const test = require('node:test');
const assert = require('node:assert/strict');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

prepareEnvironment();

const createApp = require('../src/app');
const db = require('../src/db');
const planning = require('../src/planning');
const quality = require('../src/quality');
const frontdesk = require('../src/frontdesk');
const deadlines = require('../src/deadlines');

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
  await client.firstAccess(email, password);
  return { client, id: userByEmail(email).id };
}

const monday = planning.weekStart(new Date().toISOString().slice(0, 10));
const dayOfWeek = (index) => planning.addDays(monday, index);

test('Planning, roulements et astreintes', async (t) => {
  const admin = await loginAsAdmin();
  const nour = await makeMember(admin, 'nour.planning@test.local', { first_name: 'Nour', last_name: 'Ferrand' });

  await t.test('le lundi de la semaine ancre la grille', () => {
    assert.equal(planning.weekStart('2026-09-05'), '2026-08-31');
    assert.equal(planning.weekStart('2026-08-31'), '2026-08-31');
    assert.equal(planning.weekDays('2026-08-31').at(-1).date, '2026-09-06');
  });

  await t.test('un créneau se pose et compte ses heures', async () => {
    await admin.refreshToken('/planning');
    const res = await admin.post('/planning/creneaux', {
      week: monday,
      user_id: String(nour.id),
      kind: 'Poste',
      day: dayOfWeek(1),
      start_time: '09:00',
      end_time: '17:30',
      label: 'Ouverture magasin',
      published: '1',
    });
    assert.equal(res.status, 302);

    const shifts = planning.between(monday, planning.addDays(monday, 6), { userId: nour.id });
    assert.equal(shifts.length, 1);
    assert.equal(shifts[0].hours, 8.5);
    assert.equal(shifts[0].published, 1);
  });

  await t.test('un créneau qui en chevauche un autre est refusé', async () => {
    await admin.refreshToken('/planning');
    await admin.post('/planning/creneaux', {
      week: monday, user_id: String(nour.id), kind: 'Poste',
      day: dayOfWeek(1), start_time: '16:00', end_time: '20:00',
    });
    const message = await admin.flash(`/planning?semaine=${monday}`);
    assert.equal(message.type, 'error');
    assert.match(message.message, /chevauche/);
    assert.equal(planning.between(monday, planning.addDays(monday, 6), { userId: nour.id }).length, 1);
  });

  await t.test('un créneau posé sur une absence accordée est refusé', async () => {
    // L'absence est accordée : le planning ne peut pas la contredire.
    db.prepare(`
      INSERT INTO hr_requests (employee_id, type, start_date, end_date, days, status)
      VALUES (?, 'Congés payés', ?, ?, 2, 'Approuvée')
    `).run(nour.id, dayOfWeek(3), dayOfWeek(4));

    await admin.refreshToken('/planning');
    await admin.post('/planning/creneaux', {
      week: monday, user_id: String(nour.id), kind: 'Poste',
      day: dayOfWeek(3), start_time: '09:00', end_time: '17:00',
    });
    const message = await admin.flash(`/planning?semaine=${monday}`);
    assert.equal(message.type, 'error');
    assert.match(message.message, /[Aa]bsence/);
  });

  await t.test('une absence en attente ne bloque pas', () => {
    db.prepare(`
      INSERT INTO hr_requests (employee_id, type, start_date, end_date, days, status)
      VALUES (?, 'Congés payés', ?, ?, 1, 'En attente')
    `).run(nour.id, dayOfWeek(5), dayOfWeek(5));

    const clash = planning.conflicts({ userId: nour.id, startsAt: `${dayOfWeek(5)}T09:00`, endsAt: `${dayOfWeek(5)}T17:00` });
    assert.equal(clash.blocked, false);
  });

  await t.test('un créneau finissant avant de commencer est refusé', async () => {
    await admin.refreshToken('/planning');
    await admin.post('/planning/creneaux', {
      week: monday, user_id: String(nour.id), kind: 'Poste',
      day: dayOfWeek(2), start_time: '17:00', end_time: '09:00',
    });
    const message = await admin.flash(`/planning?semaine=${monday}`);
    assert.match(message.message, /finit avant/);
  });

  await t.test('un roulement de nuit passe au lendemain et saute les conflits', () => {
    const templateId = planning.createTemplate({
      name: 'Nuit', kind: 'Poste', startTime: '21:00', endTime: '05:00', weekdays: [1, 2, 3, 4, 5], location: 'Atelier',
    });

    const verdict = planning.applyTemplate(templateId, {
      userId: nour.id, from: monday, to: planning.addDays(monday, 6),
    });
    assert.equal(verdict.ok, true);

    // Le poste de mardi 09:00–17:30 n'empêche pas la nuit de mardi 21:00 : les
    // deux ne se chevauchent pas. En revanche la nuit de mercredi finit jeudi à
    // 05:00, en pleine absence accordée — elle est écartée comme les suivantes.
    assert.deepEqual(verdict.created, [dayOfWeek(0), dayOfWeek(1)]);
    assert.deepEqual(verdict.skipped.map((s) => s.day), [dayOfWeek(2), dayOfWeek(3), dayOfWeek(4)]);
    assert.deepEqual([...new Set(verdict.skipped.map((s) => s.reason))], ['absence accordée']);

    const nuit = planning.between(monday, planning.addDays(monday, 7), { userId: nour.id })
      .find((s) => s.label === 'Nuit');
    assert.equal(nuit.starts_at, `${dayOfWeek(0)}T21:00`);
    assert.equal(nuit.ends_at, `${dayOfWeek(1)}T05:00`, 'un poste de nuit finit le lendemain');
    assert.equal(nuit.hours, 8);
  });

  await t.test('un roulement de jour bute sur le créneau déjà posé', () => {
    const templateId = planning.createTemplate({
      name: 'Journée', kind: 'Poste', startTime: '09:00', endTime: '17:00', weekdays: [2], location: '',
    });

    const verdict = planning.applyTemplate(templateId, {
      userId: nour.id, from: monday, to: planning.addDays(monday, 6),
    });
    assert.deepEqual(verdict.created, []);
    assert.deepEqual(verdict.skipped, [{ day: dayOfWeek(1), reason: 'créneau déjà posé' }]);
  });

  await t.test('un roulement ne s\'applique pas sur deux ans', async () => {
    const template = planning.templates().find((tp) => tp.name === 'Nuit');
    await admin.refreshToken('/planning');
    await admin.post(`/planning/roulements/${template.id}/appliquer`, {
      week: monday, user_id: String(nour.id), from: monday, to: planning.addDays(monday, 200),
    });
    const message = await admin.flash(`/planning?semaine=${monday}`);
    assert.match(message.message, /trois mois/);
  });

  await t.test('publier annonce la semaine', async () => {
    assert.equal(planning.between(monday, planning.addDays(monday, 6)).filter((s) => !s.published).length, 2);

    await admin.refreshToken('/planning');
    await admin.post('/planning/publier', { week: monday });
    assert.equal(planning.between(monday, planning.addDays(monday, 6)).filter((s) => !s.published).length, 0);
  });

  await t.test('l\'astreinte se lit à l\'instant où on la cherche', () => {
    const from = `${dayOfWeek(6)}T00:00`;
    const to = `${dayOfWeek(6)}T23:59`;
    planning.create({ userId: nour.id, startsAt: from, endsAt: to, kind: 'Astreinte', label: 'Week-end', published: true });

    const onCall = planning.whoIsOnCall(`${dayOfWeek(6)}T14:00`);
    assert.equal(onCall.length, 1);
    assert.equal(onCall[0].user_id, nour.id);
    assert.equal(planning.whoIsOnCall(`${dayOfWeek(0)}T14:00`).length, 0);
    assert.equal(planning.onCall(monday, planning.addDays(monday, 6)).length, 1);
  });

  await t.test('la charge se répartit par personne', () => {
    const load = planning.load(monday, planning.addDays(monday, 6));
    const ligne = load.find((l) => l.userId === nour.id);
    assert.equal(ligne.onCall, 1);
    assert.ok(ligne.hours > 8.5);
  });

  await t.test('un salarié consulte son planning mais n\'y touche pas', async () => {
    const { res, body } = await nour.client.html(`/planning?semaine=${monday}`);
    assert.equal(res.status, 200);
    assert.match(body, /Ouverture magasin/);

    await nour.client.refreshToken(`/planning?semaine=${monday}`);
    const refus = await nour.client.post('/planning/creneaux', {
      week: monday, user_id: String(nour.id), kind: 'Poste',
      day: dayOfWeek(2), start_time: '09:00', end_time: '10:00',
    });
    assert.equal(refus.status, 403);
  });

  await t.test('la grille ne montre au salarié que ses propres créneaux', async () => {
    const autre = await makeMember(admin, 'autre.planning@test.local', { first_name: 'Rémi', last_name: 'Bosco' });
    planning.create({
      userId: autre.id, startsAt: `${dayOfWeek(1)}T09:00`, endsAt: `${dayOfWeek(1)}T12:00`,
      kind: 'Poste', label: 'Inventaire', published: true,
    });

    const { body } = await nour.client.html(`/planning?semaine=${monday}`);
    assert.equal(body.includes('Inventaire'), false, 'un salarié ne voit pas le planning des autres');
    assert.equal(body.includes('Bosco'), false);
  });
});

test('Qualité : écarts, actions et audits', async (t) => {
  const admin = await loginAsAdmin();
  const membre = await makeMember(admin, 'qualite.membre@test.local', { first_name: 'Sami', last_name: 'Roux' });
  let ncId;

  await t.test('une non-conformité reçoit une référence lisible', async () => {
    await admin.refreshToken('/qualite');
    await admin.post('/qualite/non-conformites', {
      title: 'Lot hors tolérance dimensionnelle',
      source: 'Client', severity: 'Majeure',
      detected_on: new Date().toISOString().slice(0, 10),
      subject: 'Référence AX-12',
      description: 'Trois pièces sur dix hors cote.',
      immediate_action: 'Lot bloqué en zone de retenue.',
      cost: '1250,50',
    });

    const list = quality.nonconformities();
    assert.equal(list.length, 1);
    ncId = list[0].id;
    assert.match(list[0].reference, /^NC-\d{4}-001$/);
    assert.equal(list[0].cost, 1250.5);
    assert.equal(list[0].status, 'Ouverte');
  });

  await t.test('une non-conformité ne se clôture pas avec des actions en cours', async () => {
    await admin.refreshToken('/qualite');
    await admin.post('/qualite/actions', {
      label: 'Réétalonner le gabarit de contrôle',
      kind: 'Corrective',
      owner_id: String(membre.id),
      due_date: new Date().toISOString().slice(0, 10),
      nonconformity_id: String(ncId),
    });

    await admin.post(`/qualite/non-conformites/${ncId}/statut`, { status: 'Clôturée' });
    const message = await admin.flash('/qualite');
    assert.equal(message.type, 'error');
    assert.match(message.message, /encore en cours/);
    assert.equal(quality.nonconformityById(ncId).status, 'Ouverte');
  });

  await t.test('l\'action qualité rejoint les échéances de son responsable', () => {
    const echeance = deadlines.collect().find((row) => row.source === 'Qualité');
    assert.ok(echeance);
    assert.deepEqual(echeance.audience, [membre.id]);
    assert.match(echeance.detail, /Action corrective/);
  });

  await t.test('une action en cours ne peut pas être jugée efficace', async () => {
    const action = quality.actions()[0];
    await admin.refreshToken('/qualite');
    await admin.post(`/qualite/actions/${action.id}/efficacite`, { effectiveness: 'Efficace' });

    const message = await admin.flash('/qualite');
    assert.equal(message.type, 'error');
    assert.match(message.message, /en cours/);
    assert.equal(quality.actions()[0].effectiveness, 'Non vérifiée');
  });

  await t.test('une action faite se vérifie, puis la non-conformité se clôt', async () => {
    const action = quality.actions()[0];
    await admin.refreshToken('/qualite');
    await admin.post(`/qualite/actions/${action.id}/statut`, { status: 'Faite' });
    await admin.post(`/qualite/actions/${action.id}/efficacite`, { effectiveness: 'Efficace' });

    const verified = quality.actions()[0];
    assert.equal(verified.effectiveness, 'Efficace');
    assert.equal(verified.verified_on, new Date().toISOString().slice(0, 10));

    await admin.post(`/qualite/non-conformites/${ncId}/statut`, { status: 'Clôturée' });
    const nc = quality.nonconformityById(ncId);
    assert.equal(nc.status, 'Clôturée');
    assert.equal(nc.closed_on, new Date().toISOString().slice(0, 10));
  });

  await t.test('un verdict d\'efficacité inventé est refusé', () => {
    const verdict = quality.verifyAction(quality.actions()[0].id, { effectiveness: 'Peut-être' });
    assert.equal(verdict.ok, false);
  });

  await t.test('un constat d\'audit devient une non-conformité', async () => {
    await admin.refreshToken('/qualite');
    await admin.post('/qualite/audits', {
      scope: 'Processus achats', standard: 'ISO 9001:2015',
      planned_on: new Date().toISOString().slice(0, 10),
    });
    const auditRow = quality.audits()[0];
    assert.match(auditRow.reference, /^AUD-\d{4}-001$/);

    await admin.post(`/qualite/audits/${auditRow.id}/constats`, {
      kind: 'Non-conformité', clause: '8.4.1', statement: 'Aucune évaluation des fournisseurs depuis deux ans.',
    });
    const finding = quality.findings(auditRow.id)[0];

    await admin.post(`/qualite/constats/${finding.id}/en-non-conformite`, {});
    const promue = quality.nonconformities().find((n) => n.source === 'Audit');
    assert.ok(promue, 'un constat qui reste un constat ne sert à rien');
    assert.match(promue.title, /évaluation des fournisseurs/);
    assert.match(promue.description, /AUD-/);
    assert.equal(promue.severity, 'Majeure');
  });

  await t.test('seul un constat de non-conformité se transforme', async () => {
    const auditRow = quality.audits()[0];
    await admin.refreshToken('/qualite');
    await admin.post(`/qualite/audits/${auditRow.id}/constats`, {
      kind: 'Point fort', statement: 'Traçabilité exemplaire des lots.',
    });
    const fort = quality.findings(auditRow.id).find((f) => f.kind === 'Point fort');

    const verdict = quality.promoteFinding(fort.id);
    assert.equal(verdict.ok, false);
  });

  await t.test('un audit se clôt avec sa synthèse', async () => {
    const auditRow = quality.audits()[0];
    await admin.refreshToken('/qualite');
    await admin.post(`/qualite/audits/${auditRow.id}/realiser`, {
      done_on: new Date().toISOString().slice(0, 10),
      summary: 'Deux écarts, un point fort.',
    });
    const closed = quality.auditById(auditRow.id);
    assert.equal(closed.status, 'Réalisé');
    assert.match(closed.summary, /Deux écarts/);
  });

  await t.test('les indicateurs comptent le fait et l\'efficace séparément', () => {
    const summary = quality.summary();
    assert.equal(summary.open, 1, 'la non-conformité issue de l\'audit reste ouverte');
    assert.equal(summary.awaitingVerification, 0);
    assert.equal(summary.cost, 1250.5);
  });

  await t.test('l\'espace est réservé à l\'encadrement', async () => {
    assert.equal((await membre.client.get('/qualite')).status, 403);

    await membre.client.refreshToken('/mon-espace');
    const refus = await membre.client.post('/qualite/non-conformites', { title: 'Tentative' });
    assert.equal(refus.status, 403);
  });
});

test('Accueil : visiteurs et courrier', async (t) => {
  const admin = await loginAsAdmin();
  const hote = await makeMember(admin, 'hote.accueil@test.local', { first_name: 'Léa', last_name: 'Marchand' });
  let visitorId;

  await t.test('une arrivée entre au registre', async () => {
    await admin.refreshToken('/accueil');
    await admin.post('/accueil/visiteurs', {
      first_name: 'Paul', last_name: 'Vidal', company: 'Transporteur Sud',
      purpose: 'Livraison', host_id: String(hote.id), badge: 'V-12',
      arrived_at: '09:15',
    });

    const list = frontdesk.visitors();
    assert.equal(list.length, 1);
    visitorId = list[0].id;
    assert.equal(list[0].arrived_at, '09:15');
    assert.equal(list[0].departed_at, '');
    assert.equal(frontdesk.present().length, 1, 'la liste d\'évacuation le compte');
  });

  await t.test('la sortie vide la liste des présents', async () => {
    await admin.refreshToken('/accueil');
    await admin.post(`/accueil/visiteurs/${visitorId}/sortie`, { departed_at: '10:40' });

    assert.equal(frontdesk.present().length, 0);
    assert.equal(frontdesk.visitors()[0].departed_at, '10:40');

    // Une seconde sortie ne réécrit pas la première.
    await admin.post(`/accueil/visiteurs/${visitorId}/sortie`, { departed_at: '18:00' });
    assert.equal(frontdesk.visitors()[0].departed_at, '10:40');
  });

  await t.test('un visiteur sans nom est refusé', async () => {
    await admin.refreshToken('/accueil');
    await admin.post('/accueil/visiteurs', { first_name: 'Anonyme', last_name: '' });
    const message = await admin.flash('/accueil');
    assert.equal(message.type, 'error');
    assert.equal(frontdesk.visitors().length, 1);
  });

  await t.test('un recommandé attend sa remise, datée et signée', async () => {
    await admin.refreshToken('/accueil');
    await admin.post('/accueil/courrier', {
      direction: 'Entrant', kind: 'Recommandé avec AR',
      correspondent: 'URSSAF', recipient_id: String(hote.id),
      subject: 'Mise en demeure', tracking: '1A 234 567 890 1',
    });

    const mail = frontdesk.mail({ direction: 'Entrant' })[0];
    assert.equal(mail.status, 'À remettre');
    assert.equal(frontdesk.summary().registered, 1);
    assert.deepEqual(frontdesk.mailFor(hote.id).map((m) => m.subject), ['Mise en demeure']);

    await admin.post(`/accueil/courrier/${mail.id}/remise`, {});
    const remis = frontdesk.mail({ direction: 'Entrant' })[0];
    assert.equal(remis.status, 'Remis');
    assert.equal(remis.handed_on, new Date().toISOString().slice(0, 10));
    assert.ok(remis.handed_by, 'la remise porte le nom de celui qui l\'a faite');

    // Remettre deux fois n'a pas de sens.
    await admin.post(`/accueil/courrier/${mail.id}/remise`, {});
    const message = await admin.flash('/accueil');
    assert.equal(message.type, 'error');
  });

  await t.test('un courrier sans objet ni correspondant est refusé', async () => {
    await admin.refreshToken('/accueil');
    await admin.post('/accueil/courrier', { direction: 'Sortant', kind: 'Lettre' });
    const message = await admin.flash('/accueil');
    assert.equal(message.type, 'error');
    assert.equal(frontdesk.mail({ direction: 'Sortant' }).length, 0);
  });

  await t.test('les registres restent entre les mains des RH', async () => {
    assert.equal((await hote.client.get('/accueil')).status, 403);
  });
});
