const test = require('node:test');
const assert = require('node:assert/strict');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

prepareEnvironment();

const createApp = require('../src/app');
const db = require('../src/db');
const projects = require('../src/projects');

let server;
let baseUrl;

test.before(async () => {
  server = await startServer(createApp());
  baseUrl = `http://127.0.0.1:${server.address().port}`;
});

test.after(() => server.close());

const newClient = () => new Client(baseUrl);
const userByEmail = (email) => db.prepare('SELECT * FROM users WHERE email = ?').get(email);
const today = () => new Date().toISOString().slice(0, 10);

async function loginAsAdmin() {
  const admin = newClient();
  await admin.login(ADMIN_EMAIL, ADMIN_PASSWORD);
  return admin;
}

async function makeMember(admin, email, extra = {}) {
  await admin.refreshToken('/admin');
  await admin.post('/admin/employes', {
    first_name: 'Test', last_name: 'Membre', grade: 'Employé', contract_type: 'CDI', email, ...extra,
  });
  const password = (await admin.flash('/admin')).message.match(/Mot de passe temporaire : ([A-Za-z0-9]+)/)[1];
  const client = newClient();
  await client.firstAccess(email, password);
  return { client, id: userByEmail(email).id };
}

test('Projets, tâches et temps passé', async (t) => {
  const admin = await loginAsAdmin();
  let projectId;

  await t.test('ouvre un projet avec budget et taux horaire', async () => {
    await admin.refreshToken('/projets');
    await admin.post('/projets', {
      code: 'PRJ-1', name: 'Refonte du portail', status: 'En cours',
      start_date: '2026-01-05', due_date: '2026-06-30',
      budget_amount: '30000', hourly_rate: '75', description: 'Refonte complète.',
    });

    const project = db.prepare('SELECT * FROM projects').get();
    assert.equal(project.name, 'Refonte du portail');
    assert.equal(project.budget_amount, 30000);
    assert.equal(project.hourly_rate, 75);
    projectId = project.id;
  });

  await t.test('refuse une échéance antérieure au début', async () => {
    await admin.refreshToken('/projets');
    await admin.post('/projets', { name: 'À rebours', start_date: '2026-05-01', due_date: '2026-04-01' });
    assert.match((await admin.flash('/projets')).message, /précède la date de début/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM projects').get().n, 1);
  });

  await t.test('un jalon se pose, se marque atteint, puis se rouvre', async () => {
    await admin.refreshToken(`/projets/${projectId}`);
    await admin.post(`/projets/${projectId}/jalons`, { title: 'Maquettes validées', due_date: '2026-02-15' });

    const milestone = db.prepare('SELECT * FROM project_milestones').get();
    assert.equal(milestone.reached_on, null);

    await admin.refreshToken(`/projets/${projectId}`);
    await admin.post(`/projets/jalons/${milestone.id}/basculer`);
    assert.ok(db.prepare('SELECT reached_on FROM project_milestones WHERE id = ?').get(milestone.id).reached_on);

    await admin.refreshToken(`/projets/${projectId}`);
    await admin.post(`/projets/jalons/${milestone.id}/basculer`);
    assert.equal(db.prepare('SELECT reached_on FROM project_milestones WHERE id = ?').get(milestone.id).reached_on, null);
  });

  await t.test('une tâche terminée porte sa date de fin, sans la saisir', async () => {
    await admin.refreshToken(`/projets/${projectId}`);
    await admin.post(`/projets/${projectId}/taches`, {
      title: 'Écrire le cahier des charges', priority: 'Haute', estimate_hours: '12', due_date: '2026-01-20',
    });

    const task = db.prepare('SELECT * FROM project_tasks').get();
    assert.equal(task.status, 'À faire');
    assert.equal(task.done_at, null);

    await admin.refreshToken(`/projets/${projectId}`);
    await admin.post(`/projets/taches/${task.id}/statut`, { status: 'Terminée' });
    const done = db.prepare('SELECT * FROM project_tasks WHERE id = ?').get(task.id);
    assert.equal(done.status, 'Terminée');
    assert.equal(done.done_at, today());

    // Rouvrir efface la date : elle ne doit pas rester d'une vie antérieure.
    await admin.refreshToken(`/projets/${projectId}`);
    await admin.post(`/projets/taches/${task.id}/statut`, { status: 'En cours' });
    assert.equal(db.prepare('SELECT done_at FROM project_tasks WHERE id = ?').get(task.id).done_at, null);
  });

  await t.test('refuse un statut de tâche inventé', async () => {
    const task = db.prepare('SELECT * FROM project_tasks').get();
    await admin.refreshToken(`/projets/${projectId}`);
    await admin.post(`/projets/taches/${task.id}/statut`, { status: 'Abandonnée' });
    assert.equal(db.prepare('SELECT status FROM project_tasks WHERE id = ?').get(task.id).status, 'En cours');
  });

  await t.test('le temps saisi alimente le coût et la marge', async () => {
    await admin.refreshToken(`/projets/${projectId}`);
    await admin.post(`/projets/${projectId}/temps`, { spent_on: today(), hours: '10', note: 'Cadrage' });

    const project = projects.byId(projectId);
    const profit = projects.profitability(project);
    assert.equal(profit.hours, 10);
    assert.equal(profit.cost, 750);          // 10 h × 75 €
    assert.equal(profit.margin, 29250);      // 30 000 − 750
    assert.equal(profit.consumed, 3);        // 750 / 30 000
  });

  await t.test('refuse une saisie démesurée ou dans le futur', async () => {
    await admin.refreshToken(`/projets/${projectId}`);
    await admin.post(`/projets/${projectId}/temps`, { spent_on: today(), hours: '30' });
    assert.match((await admin.flash(`/projets/${projectId}`)).message, /entre 0 et 24 heures/);

    await admin.refreshToken(`/projets/${projectId}`);
    await admin.post(`/projets/${projectId}/temps`, { spent_on: '2099-01-01', hours: '2' });
    assert.match((await admin.flash(`/projets/${projectId}`)).message, /à l'avance/);

    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM project_time').get().n, 1);
  });

  await t.test("refuse d'imputer du temps sur la tâche d'un autre projet", async () => {
    await admin.refreshToken('/projets');
    await admin.post('/projets', { name: 'Second projet' });
    const second = db.prepare("SELECT id FROM projects WHERE name = 'Second projet'").get().id;
    const foreignTask = db.prepare('SELECT id FROM project_tasks').get().id;

    await admin.refreshToken(`/projets/${second}`);
    await admin.post(`/projets/${second}/temps`, { spent_on: today(), hours: '1', task_id: String(foreignTask) });
    assert.match((await admin.flash(`/projets/${second}`)).message, /Tâche introuvable sur ce projet/);
  });

  await t.test("un projet n'est ouvert qu'à son équipe", async () => {
    const { client: etranger } = await makeMember(admin, 'hors-projet@test.local');
    assert.equal((await etranger.get(`/projets/${projectId}`)).status, 403);

    // La liste ne montre à un membre que ses propres projets.
    const { body } = await etranger.html('/projets');
    assert.equal(/Refonte du portail/.test(body), false);
  });

  await t.test('un membre du projet y accède et y saisit son temps', async () => {
    const { client, id } = await makeMember(admin, 'sur-projet@test.local');
    await admin.refreshToken(`/projets/${projectId}`);
    await admin.post(`/projets/${projectId}/membres`, { user_id: String(id), role: 'Développeuse' });

    assert.equal((await client.get(`/projets/${projectId}`)).status, 200);

    await client.refreshToken(`/projets/${projectId}`);
    await client.post(`/projets/${projectId}/temps`, { spent_on: today(), hours: '4', note: 'Intégration' });
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM project_time WHERE user_id = ?').get(id).n, 1);
  });

  await t.test('un membre ne crée ni ne supprime de projet', async () => {
    const client = newClient();
    const member = db.prepare("SELECT id FROM users WHERE email = 'sur-projet@test.local'").get();
    await client.login('sur-projet@test.local', 'Prairie-Bleue-2026');
    await client.refreshToken('/projets');

    assert.equal((await client.post('/projets', { name: 'Projet clandestin' })).status, 403);
    assert.equal((await client.post(`/projets/${projectId}/supprimer`)).status, 403);
    assert.ok(projects.byId(projectId), 'le projet est toujours là');
    assert.ok(member);
  });

  await t.test("chacun n'efface que ses propres saisies", async () => {
    const mine = db.prepare("SELECT pt.id FROM project_time pt JOIN users u ON u.id = pt.user_id WHERE u.email = 'sur-projet@test.local'").get().id;
    const adminEntry = db.prepare("SELECT pt.id FROM project_time pt JOIN users u ON u.id = pt.user_id WHERE u.email = ?").get(ADMIN_EMAIL).id;

    const client = newClient();
    await client.login('sur-projet@test.local', 'Prairie-Bleue-2026');
    await client.refreshToken(`/projets/${projectId}`);

    await client.post(`/projets/temps/${adminEntry}/supprimer`);
    assert.ok(db.prepare('SELECT id FROM project_time WHERE id = ?').get(adminEntry), "la saisie d'un autre reste");

    await client.refreshToken(`/projets/${projectId}`);
    await client.post(`/projets/temps/${mine}/supprimer`);
    assert.equal(db.prepare('SELECT id FROM project_time WHERE id = ?').get(mine), undefined);
  });

  await t.test("un manager conduit ses projets, pas ceux des autres", async () => {
    // Un manager : quelqu'un qui encadre une équipe.
    const { client: manager, id: managerId } = await makeMember(admin, 'manager-projets@test.local');
    await admin.refreshToken('/admin');
    await admin.post('/admin/equipes', { name: 'Équipe Projets' });
    const team = db.prepare("SELECT id FROM teams WHERE name = 'Équipe Projets'").get();
    await admin.refreshToken('/admin');
    await admin.post('/admin/encadrement', { scope: 'team', scope_id: String(team.id), user_id: String(managerId) });

    // Il peut ouvrir un projet, et en devient responsable sans l'avoir désigné.
    await manager.refreshToken('/projets');
    await manager.post('/projets', { name: 'Projet du manager' });
    const sien = db.prepare("SELECT * FROM projects WHERE name = 'Projet du manager'").get();
    assert.equal(sien.lead_id, managerId, "l'ouvreur devient responsable par défaut");

    await manager.refreshToken(`/projets/${sien.id}`);
    await manager.post(`/projets/${sien.id}/jalons`, { title: 'Jalon du manager' });
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM project_milestones WHERE project_id = ?').get(sien.id).n, 1);

    // Mais pas toucher au projet d'un autre : encadrer une équipe ne donne
    // aucun droit sur le projet d'une autre.
    assert.equal((await manager.get(`/projets/${projectId}`)).status, 403);
    await manager.refreshToken('/projets');
    assert.equal((await manager.post(`/projets/${projectId}/supprimer`)).status, 403);
    assert.ok(projects.byId(projectId), "le projet d'autrui est intact");

    const foreignTask = db.prepare('SELECT id FROM project_tasks WHERE project_id = ?').get(projectId);
    if (foreignTask) {
      await manager.refreshToken('/projets');
      assert.equal((await manager.post(`/projets/taches/${foreignTask.id}/supprimer`)).status, 403);
      assert.ok(db.prepare('SELECT id FROM project_tasks WHERE id = ?').get(foreignTask.id));
    }
  });

  await t.test('archiver retire le projet de la liste courante sans rien perdre', async () => {
    await admin.refreshToken('/projets');
    await admin.post(`/projets/${projectId}/archiver`);
    assert.equal(db.prepare('SELECT archived FROM projects WHERE id = ?').get(projectId).archived, 1);

    const visible = projects.list();
    assert.equal(visible.some((p) => p.id === projectId), false);
    assert.equal(projects.list({ includeArchived: true }).some((p) => p.id === projectId), true);
    assert.ok(db.prepare('SELECT COUNT(*) AS n FROM project_time WHERE project_id = ?').get(projectId).n > 0);
  });

  await t.test('supprimer un projet emporte ses tâches et son temps', async () => {
    await admin.refreshToken('/projets');
    await admin.post(`/projets/${projectId}/supprimer`);
    assert.equal(projects.byId(projectId), null);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM project_tasks WHERE project_id = ?').get(projectId).n, 0);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM project_time WHERE project_id = ?').get(projectId).n, 0);
  });
});
