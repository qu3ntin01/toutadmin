const test = require('node:test');
const assert = require('node:assert/strict');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

prepareEnvironment();

const createApp = require('../src/app');
const db = require('../src/db');
const workflows = require('../src/workflows');
const org = require('../src/org');
const notifications = require('../src/notifications');

let server;
let baseUrl;

test.before(async () => {
  server = await startServer(createApp());
  baseUrl = `http://127.0.0.1:${server.address().port}`;
});

test.after(() => server.close());

const newClient = () => new Client(baseUrl);

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
  const flash = await admin.flash('/admin');
  const password = flash.message.match(/Mot de passe temporaire : ([A-Za-z0-9]+)/)[1];
  const client = newClient();
  await client.firstAccess(email, password);
  return { client, id: db.prepare('SELECT id FROM users WHERE email = ?').get(email).id };
}

test('Circuits d\'approbation configurables', async (t) => {
  const admin = await loginAsAdmin();
  const teamId = db.prepare("INSERT INTO teams (name, department_id, description) VALUES ('Atelier', NULL, '')").run().lastInsertRowid;
  const manager = await makeMember(admin, 'manon.manager@test.local', { first_name: 'Manon', last_name: 'Chef', team_id: String(teamId) });
  const salarie = await makeMember(admin, 'yanis.salarie@test.local', { first_name: 'Yanis', last_name: 'Dubois', team_id: String(teamId) });
  const financier = await makeMember(admin, 'fanny.finance@test.local', { first_name: 'Fanny', last_name: 'Comptes' });

  org.addManager('team', teamId, manager.id);
  db.prepare('UPDATE users SET is_finance = 1 WHERE id = ?').run(financier.id);

  let formId;

  await t.test('un type de demande décrit ses champs', async () => {
    await admin.refreshToken('/demandes');
    await admin.post('/demandes/types', {
      label: 'Demande de déplacement',
      description: 'Mission hors des locaux.',
      amount_field: 'montant',
      field_names: ['destination', 'montant', 'motif', '', ''],
      field_labels: ['Destination', 'Budget estimé', 'Motif', '', ''],
      field_types: ['texte', 'montant', 'zone', 'texte', 'texte'],
      field_required: ['1', '1', '0', '0', '0'],
      field_options: ['', '', '', '', ''],
    });

    const forms = workflows.forms();
    assert.equal(forms.length, 1);
    formId = forms[0].id;
    assert.deepEqual(forms[0].fieldList.map((f) => f.name), ['destination', 'montant', 'motif']);
    assert.equal(forms[0].amount_field, 'montant');
  });

  await t.test('un champ de montant qui n\'existe pas est refusé', () => {
    const verdict = workflows.createForm({
      label: 'Incohérent', amountField: 'prix',
      fields: [{ name: 'destination', label: 'Destination', type: 'texte' }],
    });
    assert.equal(verdict.ok, false);
    assert.match(verdict.message, /montant désigné n'existe pas/);
  });

  await t.test('deux champs de même nom sont refusés', () => {
    const verdict = workflows.createForm({
      label: 'Doublon',
      fields: [{ name: 'motif', label: 'A', type: 'texte' }, { name: 'motif', label: 'B', type: 'texte' }],
    });
    assert.match(verdict.message, /même nom/);
  });

  await t.test('le circuit se compose d\'étapes, avec seuils', async () => {
    await admin.refreshToken('/demandes');
    await admin.post(`/demandes/types/${formId}/etapes`, { approver: 'manager', label: 'Accord du manager', threshold: '0' });
    await admin.post(`/demandes/types/${formId}/etapes`, { approver: 'finance', label: 'Visa de la gestion', threshold: '500' });

    const steps = workflows.stepsOf(formId);
    assert.deepEqual(steps.map((s) => [s.position, s.approver, s.threshold]), [[1, 'manager', 0], [2, 'finance', 500]]);
  });

  await t.test('une étape désignant une personne inconnue est refusée', () => {
    const verdict = workflows.addStep(formId, { approver: 'user', approverId: 9999 });
    assert.equal(verdict.ok, false);
    assert.match(verdict.message, /inconnue/);
  });

  let petiteDemande;

  await t.test('sous le seuil, le circuit est plus court', async () => {
    await salarie.client.refreshToken('/demandes');
    const res = await salarie.client.post('/demandes', {
      form_id: String(formId),
      champ_destination: 'Lyon',
      champ_montant: '120',
      champ_motif: 'Salon professionnel',
    });
    assert.equal(res.status, 302);
    petiteDemande = workflows.list({ requesterId: salarie.id })[0];

    assert.equal(petiteDemande.steps.length, 1, "la gestion n'est appelée qu'au-delà de 500");
    assert.equal(petiteDemande.step.approver, 'manager');
    assert.deepEqual(petiteDemande.pendingApprovers.map((a) => a.id), [manager.id]);
  });

  await t.test('un champ obligatoire manquant ou un montant illisible sont refusés', async () => {
    await salarie.client.refreshToken('/demandes');
    await salarie.client.post('/demandes', { form_id: String(formId), champ_montant: '100' });
    let message = await salarie.client.flash('/demandes');
    assert.match(message.message, /Champ obligatoire/);

    await salarie.client.post('/demandes', { form_id: String(formId), champ_destination: 'Lille', champ_montant: 'beaucoup' });
    message = await salarie.client.flash('/demandes');
    assert.match(message.message, /nombre attendu/);
    assert.equal(workflows.list({ requesterId: salarie.id }).length, 1);
  });

  await t.test('le validateur de l\'étape est prévenu, les autres non', () => {
    assert.equal(notifications.forUser(manager.id, { limit: 20 }).some((n) => n.title.includes('Demande à valider')), true);
    assert.equal(notifications.forUser(financier.id, { limit: 20 }).some((n) => n.title.includes('Demande à valider')), false);
  });

  await t.test('on ne valide ni sa propre demande, ni celle des autres étapes', () => {
    assert.match(workflows.canDecide(petiteDemande, salarie.id).message, /sa propre demande/);
    assert.match(workflows.canDecide(petiteDemande, financier.id).message, /ne vous revient pas/);
  });

  await t.test('l\'approbation de la dernière étape clôt la demande', async () => {
    await manager.client.refreshToken(`/demandes/${petiteDemande.id}`);
    await manager.client.post(`/demandes/${petiteDemande.id}/decision`, { decision: 'Approuvée', note: 'Mission utile.' });

    const updated = workflows.byId(petiteDemande.id);
    assert.equal(updated.status, 'Approuvée');
    assert.ok(updated.closed_at);
    assert.equal(updated.decisions.length, 1);
    assert.equal(notifications.forUser(salarie.id, { limit: 20 }).some((n) => n.title.includes('Demande approuvée')), true);
  });

  let grosseDemande;

  await t.test('au-dessus du seuil, la gestion entre dans le circuit', async () => {
    await salarie.client.refreshToken('/demandes');
    await salarie.client.post('/demandes', {
      form_id: String(formId), champ_destination: 'Berlin', champ_montant: '1 800', champ_motif: 'Salon international',
    });
    grosseDemande = workflows.list({ requesterId: salarie.id })[0];

    assert.equal(grosseDemande.amount, 1800);
    assert.deepEqual(grosseDemande.steps.map((s) => s.approver), ['manager', 'finance']);
    assert.equal(grosseDemande.step.approver, 'manager');
  });

  await t.test('la demande avance étape par étape', async () => {
    await manager.client.refreshToken(`/demandes/${grosseDemande.id}`);
    await manager.client.post(`/demandes/${grosseDemande.id}/decision`, { decision: 'Approuvée' });

    const middle = workflows.byId(grosseDemande.id);
    assert.equal(middle.status, 'En cours');
    assert.equal(middle.step.approver, 'finance');
    assert.equal(notifications.forUser(financier.id, { limit: 20 }).some((n) => n.title.includes('Demande à valider')), true);

    // Le manager, qui s'est prononcé, ne peut pas se prononcer deux fois.
    assert.equal(workflows.canDecide(middle, manager.id).ok, false);
  });

  await t.test('un refus se motive et referme la demande', async () => {
    await financier.client.refreshToken(`/demandes/${grosseDemande.id}`);
    await financier.client.post(`/demandes/${grosseDemande.id}/decision`, { decision: 'Refusée' });
    let message = await financier.client.flash(`/demandes/${grosseDemande.id}`);
    assert.match(message.message, /se motive/);

    await financier.client.post(`/demandes/${grosseDemande.id}/decision`, { decision: 'Refusée', note: 'Budget déplacements épuisé.' });
    const closed = workflows.byId(grosseDemande.id);
    assert.equal(closed.status, 'Refusée');
    assert.equal(closed.decisions.length, 2);
    assert.equal(notifications.forUser(salarie.id, { limit: 30 }).some((n) => n.title.includes('Demande refusée')), true);
  });

  await t.test('une demande close ne se décide plus', () => {
    assert.match(workflows.decide(grosseDemande.id, financier.id, { decision: 'Approuvée' }).message, /plus en cours/);
  });

  await t.test('une étape sans validateur possible est sautée plutôt que de bloquer', async () => {
    // Un salarié sans manager : l'étape « manager » n'a personne à appeler.
    const isole = await makeMember(admin, 'iris.isolee@test.local', { first_name: 'Iris', last_name: 'Seule' });
    await isole.client.refreshToken('/demandes');
    await isole.client.post('/demandes', { form_id: String(formId), champ_destination: 'Nantes', champ_montant: '80' });

    const request = workflows.list({ requesterId: isole.id })[0];
    assert.equal(request.status, 'Approuvée', 'personne ne doit rester bloqué sur un validateur qui n\'existe pas');
    assert.equal(request.steps.length, 0);
  });

  await t.test('une étape dont le demandeur serait le seul validateur est sautée', () => {
    // L'administrateur est le seul « finance » ici : sa propre demande ne doit
    // pas rester bloquée en attente de lui-même.
    const solo = workflows.createForm({
      label: 'Achat direction',
      fields: [{ name: 'objet', label: 'Objet', type: 'texte', required: true }],
    });
    workflows.addStep(solo.id, { approver: 'user', approverId: financier.id, label: 'Visa unique' });

    const bloque = workflows.submit(solo.id, financier.id, { champ_objet: 'Écran' });
    assert.equal(bloque.ok, true);
    assert.equal(workflows.byId(bloque.id).status, 'Approuvée');
    assert.equal(bloque.steps, 0);
  });

  await t.test('le demandeur retire sa demande tant que personne ne s\'est prononcé', async () => {
    await salarie.client.refreshToken('/demandes');
    await salarie.client.post('/demandes', { form_id: String(formId), champ_destination: 'Brest', champ_montant: '90' });
    const request = workflows.list({ requesterId: salarie.id })[0];

    assert.match(workflows.cancel(request.id, manager.id).message, /Seul le demandeur/);

    await salarie.client.post(`/demandes/${request.id}/annuler`, {});
    assert.equal(workflows.byId(request.id).status, 'Annulée');
  });

  await t.test('une demande examinée ne se retire plus', async () => {
    await salarie.client.refreshToken('/demandes');
    await salarie.client.post('/demandes', { form_id: String(formId), champ_destination: 'Douai', champ_montant: '95' });
    const request = workflows.list({ requesterId: salarie.id })[0];

    await manager.client.refreshToken(`/demandes/${request.id}`);
    await manager.client.post(`/demandes/${request.id}/decision`, { decision: 'Approuvée' });

    assert.match(workflows.cancel(request.id, salarie.id).message, /n'est plus en cours/);
  });

  await t.test('une demande ne se lit que par ses parties', async () => {
    const request = workflows.list({ requesterId: salarie.id })[0];
    const tiers = await makeMember(admin, 'curieux.demandes@test.local', { first_name: 'Curieux', last_name: 'Tiers' });

    assert.equal((await tiers.client.get(`/demandes/${request.id}`)).status, 403);
    assert.equal((await salarie.client.get(`/demandes/${request.id}`)).status, 200);
    assert.equal((await manager.client.get(`/demandes/${request.id}`)).status, 200);
    assert.equal((await admin.get(`/demandes/${request.id}`)).status, 200);
  });

  await t.test('le paramétrage reste à l\'administration', async () => {
    const tiers = newClient();
    await tiers.login('yanis.salarie@test.local', 'Prairie-Bleue-2026');
    await tiers.refreshToken('/demandes');

    const avant = workflows.forms().length;
    const res = await tiers.post('/demandes/types', { label: 'Type pirate', field_names: ['x'], field_types: ['texte'], field_labels: ['X'] });
    assert.equal(res.status, 403);
    assert.equal(workflows.forms().length, avant, 'aucun type ne doit être créé');
  });

  await t.test('un type fermé n\'accepte plus de demande, les en-cours continuent', async () => {
    await admin.refreshToken('/demandes');
    await admin.post(`/demandes/types/${formId}/etat`, { active: '0' });

    const verdict = workflows.submit(formId, salarie.id, { champ_destination: 'Metz', champ_montant: '50' });
    assert.equal(verdict.ok, false);
    assert.match(verdict.message, /n'est pas ouvert/);

    // Et il ne se supprime pas tant qu'une demande y court.
    db.prepare("UPDATE workflow_requests SET status = 'En cours' WHERE id = (SELECT MAX(id) FROM workflow_requests)").run();
    const refus = workflows.deleteForm(formId);
    assert.equal(refus.ok, false);
    assert.match(refus.message, /suspendez-le/);
  });
});
