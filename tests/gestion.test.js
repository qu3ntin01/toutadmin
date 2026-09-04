const test = require('node:test');
const assert = require('node:assert/strict');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

prepareEnvironment();

const createApp = require('../src/app');
const db = require('../src/db');

let server;
let baseUrl;

test.before(async () => {
  server = await startServer(createApp());
  baseUrl = `http://127.0.0.1:${server.address().port}`;
});

test.after(() => server.close());

const newClient = () => new Client(baseUrl);
const userByEmail = (email) => db.prepare('SELECT * FROM users WHERE email = ?').get(email);

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
  await client.login(email, password);
  return { client, id: userByEmail(email).id };
}

test('Gestion administrative et financière', async (t) => {
  const admin = await loginAsAdmin();
  const employee = await makeMember(admin, 'sonia.gestion@test.local', { first_name: 'Sonia', last_name: 'Vidal' });

  await t.test("l'espace de gestion est fermé au personnel non désigné", async () => {
    assert.equal((await employee.client.get('/gestion')).status, 403);
    const res = await employee.client.post('/gestion/tiers', { name: 'Pirate', kind: 'Client' });
    assert.equal(res.status, 403);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM partners').get().n, 0);
  });

  await t.test("l'accès gestion prend effet sans reconnexion", async () => {
    await admin.refreshToken('/admin');
    await admin.post('/admin/gestion/nommer', { employee_id: String(employee.id) });
    assert.equal((await employee.client.get('/gestion')).status, 200);

    await admin.refreshToken('/admin');
    await admin.post(`/admin/gestion/${employee.id}/retirer`, {});
    assert.equal((await employee.client.get('/gestion')).status, 403);

    await admin.refreshToken('/admin');
    await admin.post('/admin/gestion/nommer', { employee_id: String(employee.id) });
  });

  let partnerId;

  await t.test('enregistre un tiers et refuse un email malformé', async () => {
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/tiers', { name: 'Papeterie Nord', kind: 'Fournisseur', email: 'pas-un-email' });
    assert.match((await admin.flash('/gestion')).message, /email invalide/i);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM partners').get().n, 0);

    await admin.refreshToken('/gestion');
    await admin.post('/gestion/tiers', { name: 'Papeterie Nord', kind: 'Fournisseur', email: 'contact@papeterie.test' });
    partnerId = db.prepare("SELECT id FROM partners WHERE name = 'Papeterie Nord'").get().id;
    assert.ok(partnerId);
  });

  await t.test('alerte sur un contrat dont le préavis approche', async () => {
    const soon = new Date();
    soon.setUTCDate(soon.getUTCDate() + 100);
    const endDate = soon.toISOString().slice(0, 10);

    await admin.refreshToken('/gestion');
    await admin.post('/gestion/contrats', {
      partner_id: String(partnerId), title: 'Fournitures de bureau', end_date: endDate,
      notice_days: '90', amount: '1200,50', billing_period: 'Annuel',
    });

    const finance = require('../src/finance');
    const renewals = finance.contractsToRenew();
    assert.equal(renewals.length, 1);
    assert.equal(renewals[0].title, 'Fournitures de bureau');
    // Le préavis de 90 jours place l'échéance de dénonciation dans les 10 prochains jours.
    assert.ok(renewals[0].noticeDeadline < endDate);
  });

  await t.test('refuse un préavis hors bornes', async () => {
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/contrats', { partner_id: String(partnerId), title: 'Bancal', notice_days: '900', billing_period: 'Annuel' });
    assert.match((await admin.flash('/gestion')).message, /Préavis invalide/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM partner_contracts').get().n, 1);
  });

  await t.test('calcule le TTC et repère une facture en retard', async () => {
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/factures', {
      direction: 'Client', label: 'Prestation janvier', issue_date: '2026-01-05', due_date: '2026-02-05',
      amount_ht: '1000', vat_rate: '20', partner_id: String(partnerId),
    });

    const finance = require('../src/finance');
    const invoice = finance.invoices()[0];
    assert.equal(invoice.amount_ttc, 1200);
    // L'échéance de février 2026 est passée : le retard se déduit, il n'est pas saisi.
    assert.equal(invoice.overdue, true);
  });

  await t.test("refuse une échéance antérieure à l'émission", async () => {
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/factures', {
      direction: 'Client', label: 'À rebours', issue_date: '2026-03-10', due_date: '2026-03-01',
      amount_ht: '100', vat_rate: '20',
    });
    assert.match((await admin.flash('/gestion')).message, /échéance précède/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM invoices').get().n, 1);
  });

  await t.test('un budget agrège factures fournisseurs et notes de frais du service', async () => {
    await admin.refreshToken('/admin');
    await admin.post('/admin/services', { name: 'Logistique' });
    const departmentId = db.prepare("SELECT id FROM departments WHERE name = 'Logistique'").get().id;
    await admin.refreshToken('/admin');
    await admin.post(`/admin/employes/${employee.id}/rattachement`, { department_id: String(departmentId) });

    const year = new Date().getUTCFullYear();
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/budgets', { department_id: String(departmentId), year: String(year), amount: '1000' });

    // Une facture fournisseur imputée au service : 500 HT + 20 % = 600 TTC.
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/factures', {
      direction: 'Fournisseur', label: 'Cartons', issue_date: `${year}-02-01`,
      amount_ht: '500', vat_rate: '20', department_id: String(departmentId),
    });

    // Une note de frais approuvée d'un membre du service : 100 €.
    await employee.client.refreshToken('/mon-espace');
    await employee.client.post('/mon-espace/frais', {
      spent_on: `${year}-02-10`, category: 'Transport', amount: '100', description: 'Train',
    });
    const claim = db.prepare('SELECT * FROM expense_claims').get();
    await admin.refreshToken('/gestion');
    await admin.post(`/gestion/frais/${claim.id}/statut`, { status: 'Approuvée' });

    const budget = require('../src/finance').budgets(year)[0];
    assert.equal(budget.consumed, 700);
    assert.equal(budget.remaining, 300);
    assert.equal(budget.ratio, 70);
  });

  await t.test('refuse de rembourser une note qui n\'a pas été approuvée', async () => {
    await employee.client.refreshToken('/mon-espace');
    await employee.client.post('/mon-espace/frais', { spent_on: '2026-01-15', category: 'Repas', amount: '30' });
    const pending = db.prepare("SELECT * FROM expense_claims WHERE status = 'En attente'").get();

    await admin.refreshToken('/gestion');
    await admin.post(`/gestion/frais/${pending.id}/statut`, { status: 'Remboursée' });
    assert.match((await admin.flash('/gestion')).message, /approuvée avant/);
    assert.equal(db.prepare('SELECT status FROM expense_claims WHERE id = ?').get(pending.id).status, 'En attente');
  });

  await t.test('refuse une dépense datée du futur', async () => {
    const future = new Date();
    future.setUTCDate(future.getUTCDate() + 5);
    await employee.client.refreshToken('/mon-espace');
    await employee.client.post('/mon-espace/frais', {
      spent_on: future.toISOString().slice(0, 10), category: 'Repas', amount: '20',
    });
    assert.match((await employee.client.flash('/mon-espace')).message, /datée du futur/);
  });

  await t.test('un salarié ne retire que sa propre note, et tant qu\'elle est en attente', async () => {
    const other = await makeMember(admin, 'autre.frais@test.local', { first_name: 'Otto', last_name: 'Tiers' });
    const pending = db.prepare("SELECT * FROM expense_claims WHERE status = 'En attente'").get();

    await other.client.refreshToken('/mon-espace');
    await other.client.post(`/mon-espace/frais/${pending.id}/annuler`, {});
    assert.ok(db.prepare('SELECT id FROM expense_claims WHERE id = ?').get(pending.id), "la note d'autrui doit rester");

    const approved = db.prepare("SELECT * FROM expense_claims WHERE status = 'Approuvée'").get();
    await employee.client.refreshToken('/mon-espace');
    await employee.client.post(`/mon-espace/frais/${approved.id}/annuler`, {});
    assert.ok(db.prepare('SELECT id FROM expense_claims WHERE id = ?').get(approved.id), 'une note approuvée ne se retire plus');

    await employee.client.refreshToken('/mon-espace');
    await employee.client.post(`/mon-espace/frais/${pending.id}/annuler`, {});
    assert.equal(db.prepare('SELECT id FROM expense_claims WHERE id = ?').get(pending.id), undefined);
  });
});

test('Parc matériel et salles', async (t) => {
  const admin = await loginAsAdmin();
  const ana = await makeMember(admin, 'ana.parc@test.local', { first_name: 'Ana', last_name: 'Parc' });
  const bob = await makeMember(admin, 'bob.parc@test.local', { first_name: 'Bob', last_name: 'Salle' });

  let assetId;

  await t.test("affecter un équipement le sort du stock disponible", async () => {
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/equipements', { name: 'Portable Dell', category: 'Informatique', serial_number: 'SN-42' });
    assetId = db.prepare("SELECT id FROM assets WHERE name = 'Portable Dell'").get().id;
    assert.equal(db.prepare('SELECT status FROM assets WHERE id = ?').get(assetId).status, 'Disponible');

    await admin.refreshToken('/gestion');
    await admin.post(`/gestion/equipements/${assetId}/affecter`, { employee_id: String(ana.id) });
    assert.equal(db.prepare('SELECT status FROM assets WHERE id = ?').get(assetId).status, 'Affecté');

    const { body } = await ana.client.html('/mon-espace');
    assert.match(body, /Portable Dell/);
  });

  await t.test("un équipement déjà affecté ne peut pas l'être deux fois", async () => {
    await admin.refreshToken('/gestion');
    await admin.post(`/gestion/equipements/${assetId}/affecter`, { employee_id: String(bob.id) });
    assert.match((await admin.flash('/gestion')).message, /déjà affecté/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM asset_assignments WHERE returned_at IS NULL').get().n, 1);
  });

  await t.test("la reprise clôt l'affectation et rend l'équipement disponible", async () => {
    await admin.refreshToken('/gestion');
    await admin.post(`/gestion/equipements/${assetId}/reprendre`, {});
    assert.equal(db.prepare('SELECT status FROM assets WHERE id = ?').get(assetId).status, 'Disponible');
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM asset_assignments WHERE returned_at IS NULL').get().n, 0);
    // L'historique conserve la trace de qui l'a eu.
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM asset_assignments').get().n, 1);
  });

  let roomId;

  await t.test('refuse deux réservations qui se chevauchent', async () => {
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/salles', { name: 'Atlas', capacity: '12', location: '2e étage' });
    roomId = db.prepare("SELECT id FROM rooms WHERE name = 'Atlas'").get().id;

    await ana.client.refreshToken('/salles');
    await ana.client.post('/salles', {
      room_id: String(roomId), title: 'Comité', booking_date: '2027-06-10', start_time: '09:00', end_time: '11:00',
    });
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM room_bookings').get().n, 1);

    await bob.client.refreshToken('/salles');
    await bob.client.post('/salles', {
      room_id: String(roomId), title: 'Chevauche', booking_date: '2027-06-10', start_time: '10:00', end_time: '12:00',
    });
    const flash = await bob.client.flash('/salles');
    assert.match(flash.message, /déjà pris par Ana Parc/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM room_bookings').get().n, 1);
  });

  await t.test('accepte un créneau qui commence quand le précédent finit', async () => {
    await bob.client.refreshToken('/salles');
    await bob.client.post('/salles', {
      room_id: String(roomId), title: 'Suivant', booking_date: '2027-06-10', start_time: '11:00', end_time: '12:00',
    });
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM room_bookings').get().n, 2);
  });

  await t.test("refuse une fin antérieure au début", async () => {
    await ana.client.refreshToken('/salles');
    await ana.client.post('/salles', {
      room_id: String(roomId), title: 'À rebours', booking_date: '2027-06-11', start_time: '15:00', end_time: '09:00',
    });
    assert.match((await ana.client.flash('/salles')).message, /précède l'heure de début/);
  });

  await t.test("chacun n'annule que sa propre réservation", async () => {
    const booking = db.prepare("SELECT * FROM room_bookings WHERE title = 'Comité'").get();
    await bob.client.refreshToken('/salles');
    await bob.client.post(`/salles/${booking.id}/annuler`, {});
    assert.ok(db.prepare('SELECT id FROM room_bookings WHERE id = ?').get(booking.id));

    await ana.client.refreshToken('/salles');
    await ana.client.post(`/salles/${booking.id}/annuler`, {});
    assert.equal(db.prepare('SELECT id FROM room_bookings WHERE id = ?').get(booking.id), undefined);
  });

  await t.test("une réservation apparaît dans l'agenda de son auteur", async () => {
    await bob.client.refreshToken('/salles');
    await bob.client.post('/salles', {
      room_id: String(roomId), title: 'Revue produit', booking_date: '2027-07-08', start_time: '14:00', end_time: '15:00',
    });

    const { body } = await bob.client.html('/agenda?mois=2027-07');
    assert.match(body, /Revue produit/);
    assert.match(body, /Atlas/);
  });
});

test('Documents, formation, entretiens et recrutement', async (t) => {
  const admin = await loginAsAdmin();
  const lea = await makeMember(admin, 'lea.talent@test.local', { first_name: 'Léa', last_name: 'Talent' });

  await t.test("un document à accuser reste signalé jusqu'à lecture", async () => {
    await admin.refreshToken('/rh');
    await admin.post('/rh/documents', { title: 'Règlement intérieur 2026', category: 'Règlement intérieur', requires_ack: 'on' });
    const doc = db.prepare('SELECT * FROM company_documents').get();

    const talent = require('../src/talent');
    assert.equal(talent.pendingAckCount(lea.id), 1);

    await lea.client.refreshToken('/mon-espace');
    await lea.client.post(`/mon-espace/documents/${doc.id}/accuser`, {});
    assert.equal(talent.pendingAckCount(lea.id), 0);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM document_acks WHERE user_id = ?').get(lea.id).n, 1);
  });

  await t.test('refuse un lien de document qui n\'est pas http(s)', async () => {
    await admin.refreshToken('/rh');
    await admin.post('/rh/documents', { title: 'Piège', url: 'javascript:alert(1)' });
    assert.match((await admin.flash('/rh')).message, /Lien invalide/);
    assert.equal(db.prepare("SELECT COUNT(*) AS n FROM company_documents WHERE title = 'Piège'").get().n, 0);
  });

  let sessionId;

  await t.test("une session pleine refuse une inscription de plus", async () => {
    await admin.refreshToken('/rh');
    await admin.post('/rh/formations', { title: 'Habilitation électrique', category: 'Sécurité', duration_hours: '14' });
    const training = db.prepare('SELECT * FROM trainings').get();

    await admin.refreshToken('/rh');
    await admin.post('/rh/sessions', { training_id: String(training.id), start_date: '2027-09-14', seats: '1' });
    sessionId = db.prepare('SELECT id FROM training_sessions').get().id;

    const other = await makeMember(admin, 'max.talent@test.local', { first_name: 'Max', last_name: 'Second' });
    for (const member of [lea, other]) {
      await member.client.refreshToken('/mon-espace');
      await member.client.post(`/mon-espace/formations/${sessionId}/inscription`, {});
    }
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM training_registrations').get().n, 2);

    const [first, second] = db.prepare('SELECT * FROM training_registrations ORDER BY id').all();
    await admin.refreshToken('/rh');
    await admin.post(`/rh/inscriptions/${first.id}/statut`, { status: 'Inscrite' });
    await admin.refreshToken('/rh');
    await admin.post(`/rh/inscriptions/${second.id}/statut`, { status: 'Inscrite' });

    assert.match((await admin.flash('/rh')).message, /complète/);
    assert.equal(db.prepare('SELECT status FROM training_registrations WHERE id = ?').get(second.id).status, 'Demandée');
  });

  await t.test('une inscription confirmée apparaît dans l\'agenda du salarié', async () => {
    const { body } = await lea.client.html('/agenda?mois=2027-09');
    assert.match(body, /Habilitation électrique/);
  });

  await t.test('refuse une double inscription à la même session', async () => {
    await lea.client.refreshToken('/mon-espace');
    await lea.client.post(`/mon-espace/formations/${sessionId}/inscription`, {});
    assert.match((await lea.client.flash('/mon-espace')).message, /déjà inscrit/);
  });

  await t.test("l'entretien se conclut avec une appréciation bornée", async () => {
    await admin.refreshToken('/rh');
    await admin.post('/rh/entretiens', { employee_id: String(lea.id), period: '2026', scheduled_on: '2026-12-10' });
    const review = db.prepare('SELECT * FROM reviews').get();

    await admin.refreshToken('/rh');
    await admin.post(`/rh/entretiens/${review.id}/conclure`, { strengths: 'Autonome', rating: '9' });
    assert.match((await admin.flash('/rh')).message, /Appréciation invalide/);
    assert.equal(db.prepare('SELECT status FROM reviews WHERE id = ?').get(review.id).status, 'Planifié');

    await admin.refreshToken('/rh');
    await admin.post(`/rh/entretiens/${review.id}/conclure`, { strengths: 'Autonome', objectives: 'Piloter le projet Atlas', rating: '4' });
    const done = db.prepare('SELECT * FROM reviews WHERE id = ?').get(review.id);
    assert.equal(done.status, 'Réalisé');
    assert.equal(done.rating, 4);
  });

  await t.test("le salarié commente son entretien, et uniquement le sien", async () => {
    const review = db.prepare('SELECT * FROM reviews').get();
    const other = await makeMember(admin, 'curieux.talent@test.local', { first_name: 'Curieux', last_name: 'Voisin' });

    await other.client.refreshToken('/mon-espace');
    await other.client.post(`/mon-espace/entretiens/${review.id}/commentaire`, { employee_comment: 'Intrusion' });
    assert.equal(db.prepare('SELECT employee_comment FROM reviews WHERE id = ?').get(review.id).employee_comment, '');

    await lea.client.refreshToken('/mon-espace');
    await lea.client.post(`/mon-espace/entretiens/${review.id}/commentaire`, { employee_comment: "D'accord avec les objectifs." });
    assert.match(db.prepare('SELECT employee_comment FROM reviews WHERE id = ?').get(review.id).employee_comment, /objectifs/);
  });

  await t.test("un poste pourvu n'accepte plus de candidature", async () => {
    await admin.refreshToken('/rh');
    await admin.post('/rh/postes', { title: 'Technicien de maintenance', contract_type: 'CDI' });
    const opening = db.prepare('SELECT * FROM job_openings').get();

    await admin.refreshToken('/rh');
    await admin.post('/rh/candidats', { opening_id: String(opening.id), first_name: 'Zoé', last_name: 'Postulante', email: 'zoe@test.local' });
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM candidates').get().n, 1);

    await admin.refreshToken('/rh');
    await admin.post(`/rh/postes/${opening.id}/statut`, { status: 'Pourvu' });
    assert.ok(db.prepare('SELECT closed_on FROM job_openings WHERE id = ?').get(opening.id).closed_on);

    await admin.refreshToken('/rh');
    await admin.post('/rh/candidats', { opening_id: String(opening.id), first_name: 'Tard', last_name: 'Venu' });
    assert.match((await admin.flash('/rh')).message, /n'accepte plus/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM candidates').get().n, 1);
  });

  await t.test("le personnel n'accède pas aux routes RH de recrutement", async () => {
    const res = await lea.client.post('/rh/postes', { title: 'Poste pirate' });
    assert.equal(res.status, 403);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM job_openings').get().n, 1);
  });
});
