const test = require('node:test');
const assert = require('node:assert/strict');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

prepareEnvironment();

const createApp = require('../src/app');
const db = require('../src/db');
const people = require('../src/people');
const safety = require('../src/safety');

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

test('Arrivées et départs', async (t) => {
  const admin = await loginAsAdmin();
  const membre = await makeMember(admin, 'nouvelle@test.local', { first_name: 'Nour', last_name: 'Arrivante' });
  let templateId;

  await t.test('un modèle porte des points datés relativement au jour pivot', async () => {
    await admin.refreshToken('/parcours');
    await admin.post('/parcours/modeles', { name: 'Arrivée au siège', kind: 'Arrivée' });
    templateId = db.prepare('SELECT id FROM checklist_templates').get().id;

    for (const [label, role, offset] of [
      ['Préparer le poste de travail', 'Informatique', '-2'],
      ['Remettre le badge', 'Moyens généraux', '0'],
      ['Entretien de suivi', 'Manager', '30'],
    ]) {
      await admin.refreshToken('/parcours');
      await admin.post(`/parcours/modeles/${templateId}/points`, { label, owner_role: role, offset_days: offset });
    }
    assert.equal(people.templateItems(templateId).length, 3);
  });

  await t.test("refuse un écart au-delà de l'année", async () => {
    await admin.refreshToken('/parcours');
    await admin.post(`/parcours/modeles/${templateId}/points`, { label: 'Trop loin', owner_role: 'RH', offset_days: '900' });
    assert.match((await admin.flash('/parcours')).message, /écart au jour pivot/);
    assert.equal(people.templateItems(templateId).length, 3);
  });

  await t.test('lancer un parcours date chaque point', async () => {
    await admin.refreshToken('/parcours');
    await admin.post('/parcours/listes', {
      template_id: String(templateId), user_id: String(membre.id), reference_date: '2026-03-16',
    });

    const checklist = db.prepare('SELECT * FROM checklists').get();
    const items = people.checklistItems(checklist.id);
    assert.equal(items.length, 3);
    assert.equal(items[0].due_date, '2026-03-14');   // J−2
    assert.equal(items[1].due_date, '2026-03-16');   // J
    assert.equal(items[2].due_date, '2026-04-15');   // J+30
  });

  await t.test('cocher le dernier point clôt le parcours, décocher le rouvre', async () => {
    const checklist = db.prepare('SELECT * FROM checklists').get();
    const items = people.checklistItems(checklist.id);

    for (const item of items) {
      await admin.refreshToken(`/parcours/listes/${checklist.id}`);
      await admin.post(`/parcours/listes/points/${item.id}/basculer`);
    }
    assert.ok(db.prepare('SELECT completed_at FROM checklists WHERE id = ?').get(checklist.id).completed_at);

    await admin.refreshToken(`/parcours/listes/${checklist.id}`);
    await admin.post(`/parcours/listes/points/${items[0].id}/basculer`);
    assert.equal(db.prepare('SELECT completed_at FROM checklists WHERE id = ?').get(checklist.id).completed_at, null);
  });

  await t.test('supprimer un modèle laisse en place les parcours déjà lancés', async () => {
    await admin.refreshToken('/parcours');
    await admin.post(`/parcours/modeles/${templateId}/supprimer`);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM checklists').get().n, 1);
    assert.ok(people.checklistItems(db.prepare('SELECT id FROM checklists').get().id).length > 0);
  });

  await t.test("l'espace est fermé au personnel", async () => {
    assert.equal((await membre.client.get('/parcours')).status, 403);
    assert.equal((await membre.client.get('/sante-securite')).status, 403);
  });
});

test('Compétences et habilitations', async (t) => {
  const admin = await loginAsAdmin();
  const membre = await makeMember(admin, 'habilite@test.local', { first_name: 'Hugo', last_name: 'Habilite' });

  await t.test("l'échéance découle de la durée de validité", async () => {
    await admin.refreshToken('/parcours');
    await admin.post('/parcours/competences', {
      name: 'Habilitation électrique B1V', category: 'Sécurité', validity_months: '36', mandatory: '1',
    });
    const skill = db.prepare('SELECT * FROM skills').get();

    await admin.refreshToken('/parcours');
    await admin.post('/parcours/competences/attribuer', {
      user_id: String(membre.id), skill_id: String(skill.id), level: '3', obtained_on: '2024-04-10',
    });

    const held = db.prepare('SELECT * FROM user_skills').get();
    assert.equal(held.expires_on, '2027-04-10');
    assert.equal(held.level, 3);
  });

  await t.test('une compétence sans validité ne périme pas', async () => {
    await admin.refreshToken('/parcours');
    await admin.post('/parcours/competences', { name: 'Conduite de réunion', category: 'Générale' });
    const skill = db.prepare("SELECT * FROM skills WHERE name = 'Conduite de réunion'").get();

    await admin.refreshToken('/parcours');
    await admin.post('/parcours/competences/attribuer', {
      user_id: String(membre.id), skill_id: String(skill.id), level: '2', obtained_on: '2020-01-01',
    });
    assert.equal(db.prepare('SELECT expires_on FROM user_skills WHERE skill_id = ?').get(skill.id).expires_on, null);
  });

  await t.test('une habilitation obligatoire périmée remonte comme manquante', () => {
    const skill = db.prepare("SELECT * FROM skills WHERE mandatory = 1").get();
    db.prepare("UPDATE user_skills SET expires_on = '2020-01-01' WHERE skill_id = ?").run(skill.id);

    const missing = people.missingMandatory();
    assert.equal(missing.some((m) => m.user_id === membre.id && m.skill_id === skill.id), true);
  });

  await t.test('réattribuer met à jour au lieu de dupliquer', async () => {
    const skill = db.prepare("SELECT * FROM skills WHERE mandatory = 1").get();
    await admin.refreshToken('/parcours');
    await admin.post('/parcours/competences/attribuer', {
      user_id: String(membre.id), skill_id: String(skill.id), level: '4', obtained_on: today(),
    });

    const rows = db.prepare('SELECT * FROM user_skills WHERE user_id = ? AND skill_id = ?').all(membre.id, skill.id);
    assert.equal(rows.length, 1);
    assert.equal(rows[0].level, 4);
    assert.equal(people.missingMandatory().some((m) => m.skill_id === skill.id && m.user_id === membre.id), false);
  });

  await t.test('refuse un niveau hors barème', async () => {
    const skill = db.prepare('SELECT * FROM skills').get();
    await admin.refreshToken('/parcours');
    await admin.post('/parcours/competences/attribuer', {
      user_id: String(membre.id), skill_id: String(skill.id), level: '9', obtained_on: today(),
    });
    assert.match((await admin.flash('/parcours')).message, /Niveau invalide/);
  });
});

test('Santé et sécurité au travail', async (t) => {
  const admin = await loginAsAdmin();
  const membre = await makeMember(admin, 'accidente@test.local', { first_name: 'Sacha', last_name: 'Ouvrier' });

  await t.test('la criticité est le produit gravité × probabilité', async () => {
    await admin.refreshToken('/sante-securite');
    await admin.post('/sante-securite/risques', {
      unit: 'Atelier', hazard: 'Chute de hauteur', severity: '4', likelihood: '2',
      measures: 'Garde-corps posés, harnais fournis.', reviewed_on: today(), next_review: '2027-01-15',
    });

    const risk = safety.risks()[0];
    assert.equal(risk.score, 8);
    assert.equal(risk.critical, true);
  });

  await t.test('un risque sous le seuil ne réclame pas d\'action', async () => {
    await admin.refreshToken('/sante-securite');
    await admin.post('/sante-securite/risques', {
      unit: 'Bureaux', hazard: 'Fatigue visuelle', severity: '1', likelihood: '3', measures: 'Écrans réglables.',
    });
    const risk = safety.risks().find((r) => r.hazard === 'Fatigue visuelle');
    assert.equal(risk.score, 3);
    assert.equal(risk.critical, false);
  });

  await t.test('refuse un accident daté dans le futur', async () => {
    await admin.refreshToken('/sante-securite');
    await admin.post('/sante-securite/accidents', {
      occurred_on: '2099-01-01', kind: 'Accident du travail', days_off: '3',
    });
    assert.match((await admin.flash('/sante-securite')).message, /à l'avance/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM workplace_incidents').get().n, 0);
  });

  await t.test('les taux de fréquence et de gravité se calculent sur douze mois', async () => {
    await admin.refreshToken('/sante-securite');
    await admin.post('/sante-securite/accidents', {
      occurred_on: today(), kind: 'Accident du travail', user_id: String(membre.id),
      location: 'Atelier', description: 'Chute depuis un escabeau.', days_off: '12', declared_on: today(),
    });

    const indicators = safety.indicators();
    const worked = indicators.headcount * 1607;
    assert.equal(indicators.accidents, 1);
    assert.equal(indicators.daysOff, 12);
    assert.equal(indicators.frequency, Math.round((1e6 / worked) * 100) / 100);
    assert.equal(indicators.severity, Math.round((12 * 1e3 / worked) * 100) / 100);
  });

  await t.test('un presque-accident ne compte pas dans les taux', async () => {
    const avant = safety.indicators().accidents;
    await admin.refreshToken('/sante-securite');
    await admin.post('/sante-securite/accidents', {
      occurred_on: today(), kind: 'Presque-accident', days_off: '0', description: 'Palette instable.',
    });
    assert.equal(safety.indicators().accidents, avant);
  });

  await t.test("l'échéance d'un équipement découle de sa durée de validité", async () => {
    await admin.refreshToken('/sante-securite');
    await admin.post('/sante-securite/protections', { name: 'Casque de chantier', category: 'Tête', validity_months: '60' });
    const ppe = db.prepare('SELECT * FROM ppe_items').get();

    await admin.refreshToken('/sante-securite');
    await admin.post('/sante-securite/protections/remettre', {
      ppe_id: String(ppe.id), user_id: String(membre.id), issued_on: '2024-02-29',
    });

    const given = db.prepare('SELECT * FROM ppe_assignments').get();
    assert.equal(given.expires_on, '2029-02-28');
    assert.equal(given.returned_on, null);

    await admin.refreshToken('/sante-securite');
    await admin.post(`/sante-securite/protections/remises/${given.id}/rendre`);
    assert.ok(db.prepare('SELECT returned_on FROM ppe_assignments WHERE id = ?').get(given.id).returned_on);
    assert.equal(safety.ppeAssignments().length, 0, 'un équipement rendu sort de la circulation');
  });

  await t.test('les échéances proches remontent ensemble', async () => {
    await admin.refreshToken('/sante-securite');
    await admin.post('/sante-securite/visites', {
      user_id: String(membre.id), kind: 'Visite périodique', done_on: '2024-06-01',
      verdict: 'Apte', next_due: new Date(Date.now() + 20 * 86400000).toISOString().slice(0, 10),
    });

    const upcoming = safety.upcoming();
    assert.equal(upcoming.visits.length, 1);
    assert.equal(upcoming.visits[0].verdict, 'Apte');
  });
});
