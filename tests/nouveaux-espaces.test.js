const test = require('node:test');
const assert = require('node:assert/strict');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

prepareEnvironment();

const createApp = require('../src/app');
const db = require('../src/db');
const it = require('../src/it');
const dev = require('../src/dev');
const events = require('../src/events');
const oneonone = require('../src/oneonone');
const partners = require('../src/partners');
const deadlines = require('../src/deadlines');
const privacy = require('../src/privacy');

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

function shift(days) {
  const date = new Date();
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

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

// ---------------------------------------------------------------- informatique

test('Service informatique', async (t) => {
  const admin = await loginAsAdmin();
  const technicien = await makeMember(admin, 'techno@test.local', { first_name: 'Théo', last_name: 'Réseau' });
  const salarie = await makeMember(admin, 'simple@test.local', { first_name: 'Sam', last_name: 'Bureau' });

  await t.test("l'espace est fermé tant que l'administration n'a désigné personne", async () => {
    assert.equal((await technicien.client.get('/informatique')).status, 403);
    assert.equal((await technicien.client.get('/developpement')).status, 403);

    await admin.refreshToken('/admin');
    await admin.post('/admin/informatique/nommer', { employee_id: String(technicien.id) });
    assert.equal((await technicien.client.get('/informatique')).status, 200);

    // La désignation vaut sans reconnexion, et ne déborde pas sur les autres.
    assert.equal((await salarie.client.get('/informatique')).status, 403);
  });

  let licenceId;

  await t.test('les sièges sont une contrainte, pas une indication', async () => {
    await technicien.client.refreshToken('/informatique');
    await technicien.client.post('/informatique/logiciels', {
      name: 'Suite graphique', publisher: 'Éditeur X', kind: 'Abonnement', criticality: 'Importante',
      seats: '2', unit_cost: '15', billing_period: 'Mensuel', renewal_date: shift(20),
    });
    licenceId = db.prepare('SELECT id FROM software_licences').get().id;

    const grant = async (userId, level = 'Utilisateur') => {
      await technicien.client.refreshToken(`/informatique/logiciels/${licenceId}`);
      return technicien.client.post(`/informatique/logiciels/${licenceId}/acces`, { user_id: String(userId), level });
    };
    await grant(technicien.id, 'Administrateur');
    await grant(salarie.id);
    await grant(userByEmail(ADMIN_EMAIL).id);

    // Le troisième accès dépasse les deux sièges payés : il est refusé.
    assert.equal(it.licenceById(licenceId).seats_used, 2);
    const flash = await technicien.client.flash(`/informatique/logiciels/${licenceId}`);
    assert.match(flash.message, /2 sièges/);

    // Et l'on ne peut pas non plus réduire les sièges sous les accès ouverts.
    await technicien.client.refreshToken(`/informatique/logiciels/${licenceId}`);
    await technicien.client.post(`/informatique/logiciels/${licenceId}/modifier`, {
      name: 'Suite graphique', kind: 'Abonnement', criticality: 'Importante',
      seats: '1', billing_period: 'Mensuel', status: 'Actif',
    });
    assert.equal(it.licenceById(licenceId).seats, 2);
  });

  await t.test('un compte fermé qui garde un accès remonte en tête de la revue', async () => {
    // Le départ est traité côté RH ; l'accès applicatif, lui, reste ouvert.
    db.prepare('UPDATE users SET active = 0 WHERE id = ?').run(salarie.id);

    const review = it.accessReview();
    const flagged = review.flagged.map((row) => `${row.last_name}:${row.reason}`).sort();
    assert.deepEqual(flagged, ['Bureau:inactif', 'Réseau:admin']);
    // Le compte fermé passe avant les droits d'administration.
    assert.equal(review.flagged[0].reason, 'inactif');
    assert.equal(review.flagged[0].severity, 'Critique');

    const accessId = db.prepare('SELECT id FROM software_accesses WHERE user_id = ?').get(salarie.id).id;
    await technicien.client.refreshToken('/informatique');
    await technicien.client.post(`/informatique/acces/${accessId}/revoquer`, { retour: 'revue' });
    assert.equal(it.accessReview().flagged.filter((row) => row.reason === 'inactif').length, 0);
    // L'accès révoqué n'est pas effacé : c'est la preuve qu'il a été fermé.
    assert.equal(db.prepare('SELECT revoked_on FROM software_accesses WHERE id = ?').get(accessId).revoked_on, today());
    db.prepare('UPDATE users SET active = 1 WHERE id = ?').run(salarie.id);
  });

  await t.test('un incident ne se clôt pas sans heure de rétablissement', async () => {
    await technicien.client.refreshToken('/informatique');
    await technicien.client.post('/informatique/incidents', {
      title: 'Portail injoignable', severity: 'Critique',
      started_at: '2026-05-04T09:00', detected_at: '2026-05-04T09:05', impact: 'Aucun accès au portail.',
    });
    const incident = db.prepare('SELECT * FROM it_incidents').get();

    const close = (extra) => technicien.client.post(`/informatique/incidents/${incident.id}/modifier`, {
      title: 'Portail injoignable', severity: 'Critique', started_at: '2026-05-04T09:00', status: 'Résolu', ...extra,
    });
    await technicien.client.refreshToken('/informatique');
    await close({});
    assert.equal(it.incidentById(incident.id).status, 'Ouvert');

    // Un rétablissement antérieur au début fausserait la moyenne sans bruit.
    await technicien.client.refreshToken('/informatique');
    await close({ resolved_at: '2026-05-04T08:00' });
    assert.equal(it.incidentById(incident.id).status, 'Ouvert');

    await technicien.client.refreshToken('/informatique');
    await close({ resolved_at: '2026-05-04T11:30' });
    assert.equal(it.incidentById(incident.id).status, 'Résolu');
    assert.equal(it.downtimeMinutes(it.incidentById(incident.id)), 150);
  });

  await t.test('le renouvellement de licence rejoint le moteur d\'échéances', () => {
    const rows = deadlines.collect().filter((row) => row.source === 'Licence');
    assert.equal(rows.length, 1);
    assert.equal(rows[0].label, 'Suite graphique');
    // Le service informatique en est destinataire, pas la gestion financière.
    assert.ok(rows[0].audience.includes(technicien.id));
  });
});

// ---------------------------------------------------------------- développement

test('Espace développeurs', async (t) => {
  const admin = await loginAsAdmin();
  const technicien = newClient();
  await technicien.login('techno@test.local', require('./helpers').MEMBER_PASSWORD);

  let serviceId;

  await t.test('une livraison sortie du champ « prévue » exige sa date', async () => {
    await technicien.refreshToken('/developpement');
    await technicien.post('/developpement/services', {
      name: 'Portail RH', code: 'RH', criticality: 'Vitale', stack: 'Node 22',
      repository: 'https://exemple.test/depot',
    });
    serviceId = db.prepare('SELECT id FROM app_services').get().id;

    await technicien.refreshToken(`/developpement/services/${serviceId}`);
    await technicien.post(`/developpement/services/${serviceId}/livraisons`, {
      version: '1.0.0', environment: 'Production', status: 'Livrée',
    });
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM releases').get().n, 0);

    const flash = await technicien.flash(`/developpement/services/${serviceId}`);
    assert.match(flash.message, /date de mise en production/);
  });

  await t.test('une adresse de dépôt non http est refusée', async () => {
    await technicien.refreshToken(`/developpement/services/${serviceId}`);
    await technicien.post(`/developpement/services/${serviceId}/modifier`, {
      name: 'Portail RH', criticality: 'Vitale', status: 'En service',
      repository: 'javascript:alert(1)',
    });
    assert.equal(dev.serviceById(serviceId).repository, 'https://exemple.test/depot');
  });

  await t.test('le taux d\'échec compte les livraisons retirées', async () => {
    const record = async (version, status) => {
      await technicien.refreshToken(`/developpement/services/${serviceId}`);
      await technicien.post(`/developpement/services/${serviceId}/livraisons`, {
        version, environment: 'Production', released_on: today(), status,
      });
    };
    await record('1.0.0', 'Livrée');
    await record('1.1.0', 'Livrée');
    await record('1.2.0', 'Retirée');
    await record('1.3.0', 'Planifiée');

    const stats = dev.deliveryStats();
    // La livraison planifiée n'a pas encore eu lieu : elle ne pèse sur rien.
    assert.equal(stats.attempts, 3);
    assert.equal(stats.delivered, 2);
    assert.equal(stats.failed, 1);
    assert.equal(stats.failureRate, 33);
    assert.equal(stats.planned, 1);
  });

  await t.test('la version en production est la dernière livrée, pas la dernière saisie', () => {
    const service = dev.serviceById(serviceId);
    assert.equal(service.live_version, '1.1.0');
  });

  await t.test('la gestion financière n\'entre pas dans le référentiel applicatif', async () => {
    const financier = await makeMember(admin, 'gestion@test.local', { first_name: 'Gaël', last_name: 'Compte' });
    db.prepare('UPDATE users SET is_finance = 1 WHERE id = ?').run(financier.id);
    assert.equal((await financier.client.get('/developpement')).status, 403);
  });
});

// ---------------------------------------------------------------- événements

test('Événements d\'entreprise', async (t) => {
  const admin = await loginAsAdmin();
  const ada = await makeMember(admin, 'ada@test.local', { first_name: 'Ada', last_name: 'Première' });
  const bob = await makeMember(admin, 'bob@test.local', { first_name: 'Bob', last_name: 'Second' });
  const cleo = await makeMember(admin, 'cleo@test.local', { first_name: 'Cléo', last_name: 'Troisième' });

  let eventId;

  await t.test('un brouillon n\'existe pour personne', async () => {
    await admin.refreshToken('/evenements');
    await admin.post('/evenements', {
      title: 'Séminaire annuel', kind: 'Séminaire', scope: 'company',
      starts_at: '2027-06-12T09:00', ends_at: '2027-06-12T18:00', location: 'Lyon',
      capacity: '2', registration_closes_on: '2027-06-01', budget: '5000',
    });
    eventId = db.prepare('SELECT id FROM company_events').get().id;
    assert.equal(events.byId(eventId).status, 'Brouillon');

    assert.equal((await ada.client.get(`/evenements/${eventId}`)).status, 404);
    await ada.client.refreshToken('/evenements');
    assert.equal((await ada.client.post(`/evenements/${eventId}/inscription`, {})).status, 403);
  });

  await t.test('au-delà de la capacité on entre en liste d\'attente', async () => {
    await admin.refreshToken('/evenements');
    await admin.post(`/evenements/${eventId}/modifier`, {
      title: 'Séminaire annuel', kind: 'Séminaire', scope: 'company',
      starts_at: '2027-06-12T09:00', location: 'Lyon', capacity: '2',
      registration_closes_on: '2027-06-01', status: 'Ouvert',
    });

    for (const member of [ada, bob, cleo]) {
      await member.client.refreshToken(`/evenements/${eventId}`);
      await member.client.post(`/evenements/${eventId}/inscription`, {});
    }

    const statuses = events.registrations(eventId).map((row) => `${row.last_name}:${row.status}`);
    assert.deepEqual(statuses, ['Première:Inscrit', 'Second:Inscrit', 'Troisième:Liste d\'attente']);
    // Le statut « Complet » se pose tout seul : l'organisateur n'y pense pas.
    assert.equal(events.byId(eventId).status, 'Complet');
  });

  await t.test('la capacité ne descend pas sous le nombre d\'inscrits', async () => {
    await admin.refreshToken('/evenements');
    await admin.post(`/evenements/${eventId}/modifier`, {
      title: 'Séminaire annuel', kind: 'Séminaire', scope: 'company',
      starts_at: '2027-06-12T09:00', location: 'Lyon', capacity: '1', status: 'Ouvert',
    });
    assert.equal(events.byId(eventId).capacity, 2);
  });

  await t.test('un désistement fait monter le premier qui attend, et le lui dit', async () => {
    await ada.client.refreshToken(`/evenements/${eventId}`);
    await ada.client.post(`/evenements/${eventId}/desistement`, {});

    assert.equal(events.registrationOf(eventId, ada.id).status, 'Annulée');
    assert.equal(events.registrationOf(eventId, cleo.id).status, 'Inscrit');

    const notice = db.prepare("SELECT * FROM notifications WHERE user_id = ? AND kind = 'evenement'").get(cleo.id);
    assert.ok(notice, 'la personne promue doit être prévenue');
    assert.match(notice.title, /Place libérée/);
  });

  await t.test('la liste nominative reste à l\'organisateur', async () => {
    const { body: vueParticipant } = await cleo.client.html(`/evenements/${eventId}`);
    assert.doesNotMatch(vueParticipant, /Second/);

    const { body: vueOrganisateur } = await admin.html(`/evenements/${eventId}`);
    assert.match(vueOrganisateur, /Second/);
  });

  await t.test('une annulation prévient tous les inscrits', async () => {
    await admin.refreshToken('/evenements');
    await admin.post(`/evenements/${eventId}/modifier`, {
      title: 'Séminaire annuel', kind: 'Séminaire', scope: 'company',
      starts_at: '2027-06-12T09:00', location: 'Lyon', capacity: '2', status: 'Annulé',
    });
    const sent = db.prepare("SELECT COUNT(*) AS n FROM notifications WHERE title LIKE 'Annulé%'").get().n;
    assert.equal(sent, 2);
  });

  await t.test('la portée d\'un événement enferme sa visibilité', async () => {
    await admin.refreshToken('/admin');
    await admin.post('/admin/services', { name: 'Atelier' });
    const departement = db.prepare("SELECT * FROM departments WHERE name = 'Atelier'").get();
    db.prepare('UPDATE users SET department_id = ? WHERE id = ?').run(departement.id, ada.id);

    await admin.refreshToken('/evenements');
    await admin.post('/evenements', {
      title: 'Réunion de service', kind: 'Réunion générale', scope: 'department',
      scope_id: String(departement.id), starts_at: '2027-07-01T09:00',
    });
    const local = db.prepare("SELECT id FROM company_events WHERE title = 'Réunion de service'").get().id;
    await admin.refreshToken('/evenements');
    await admin.post(`/evenements/${local}/modifier`, {
      title: 'Réunion de service', kind: 'Réunion générale', scope: 'department',
      scope_id: String(departement.id), starts_at: '2027-07-01T09:00', status: 'Ouvert',
    });

    assert.equal((await ada.client.get(`/evenements/${local}`)).status, 200);
    assert.equal((await bob.client.get(`/evenements/${local}`)).status, 404);
  });
});

// ---------------------------------------------------------------- points individuels

test('Points individuels', async (t) => {
  const admin = await loginAsAdmin();
  const chef = await makeMember(admin, 'chef@test.local', { first_name: 'Mona', last_name: 'Encadrante' });
  const membre = await makeMember(admin, 'equipier@test.local', { first_name: 'Ilan', last_name: 'Équipier' });
  const voisin = await makeMember(admin, 'voisin@test.local', { first_name: 'Nour', last_name: 'Voisine' });

  await admin.refreshToken('/admin');
  await admin.post('/admin/equipes', { name: 'Équipe A' });
  const equipe = db.prepare("SELECT * FROM teams WHERE name = 'Équipe A'").get();
  db.prepare('UPDATE users SET team_id = ? WHERE id IN (?, ?)').run(equipe.id, chef.id, membre.id);
  db.prepare("INSERT INTO org_managers (scope, scope_id, user_id) VALUES ('team', ?, ?)").run(equipe.id, chef.id);
  db.prepare("INSERT INTO org_managers (scope, scope_id, user_id) VALUES ('team', ?, ?)").run(equipe.id, voisin.id);

  let pointId;

  await t.test('un manager ne planifie que pour ceux qu\'il encadre', async () => {
    await chef.client.refreshToken('/mon-equipe');
    await chef.client.post('/mon-equipe/points', {
      employee_id: String(membre.id), scheduled_on: shift(3), topics: 'Charge de travail',
    });
    await chef.client.refreshToken('/mon-equipe');
    await chef.client.post('/mon-equipe/points', {
      employee_id: String(voisin.id), scheduled_on: shift(3),
    });

    const points = db.prepare('SELECT * FROM one_on_ones').all();
    assert.equal(points.length, 1);
    assert.equal(points[0].employee_id, membre.id);
    pointId = points[0].id;
  });

  await t.test('un point « tenu » sans date de tenue ne compte pas comme fait', async () => {
    await chef.client.refreshToken('/mon-equipe');
    await chef.client.post(`/mon-equipe/points/${pointId}/modifier`, {
      scheduled_on: shift(3), status: 'Tenu', shared_note: 'Rien à signaler.',
    });
    assert.equal(oneonone.byId(pointId).status, 'Planifié');
  });

  await t.test('le résumé est partagé, les notes du manager ne le sont pas', async () => {
    await chef.client.refreshToken('/mon-equipe');
    await chef.client.post(`/mon-equipe/points/${pointId}/modifier`, {
      scheduled_on: shift(-2), held_on: shift(-2), status: 'Tenu',
      topics: 'Charge de travail', shared_note: 'On allège le projet Alpha.',
      private_note: 'Signes de fatigue, à surveiller.', mood: '3', next_on: shift(28),
    });

    const { body } = await membre.client.html('/mon-espace');
    assert.match(body, /On allège le projet Alpha/);
    assert.doesNotMatch(body, /Signes de fatigue/);

    // Rien ne fuit non plus par le module, qui ne sélectionne pas la colonne.
    const shared = oneonone.forEmployee(membre.id);
    assert.equal(shared.length, 1);
    assert.equal(shared[0].private_note, undefined);
  });

  await t.test('un autre manager de la même équipe ne touche pas au compte rendu', async () => {
    await voisin.client.refreshToken('/mon-equipe');
    await voisin.client.post(`/mon-equipe/points/${pointId}/modifier`, {
      scheduled_on: shift(-2), held_on: shift(-2), status: 'Tenu', shared_note: 'Détourné.',
    });
    assert.equal(oneonone.byId(pointId).shared_note, 'On allège le projet Alpha.');
  });

  await t.test('perdre l\'encadrement ferme l\'accès sans attendre une reconnexion', async () => {
    db.prepare("DELETE FROM org_managers WHERE user_id = ?").run(chef.id);
    const res = await chef.client.post(`/mon-equipe/points/${pointId}/modifier`, {
      scheduled_on: shift(-2), held_on: shift(-2), status: 'Tenu', shared_note: 'Après coup.',
    });
    assert.equal(res.status, 403);
    assert.equal(oneonone.byId(pointId).shared_note, 'On allège le projet Alpha.');
  });

  await t.test('une demande d\'accès aux données personnelles rend aussi les notes du manager', () => {
    const dump = privacy.exportFor(membre.id);
    const points = dump.donnees['Points individuels'];
    assert.equal(points.length, 1);
    // La discrétion est une règle du produit, pas une exception au droit.
    assert.equal(points[0].private_note, 'Signes de fatigue, à surveiller.');
  });
});

// ---------------------------------------------------------------- partenaires

test('Fiche partenaire', async (t) => {
  const admin = await loginAsAdmin();

  await admin.refreshToken('/gestion');
  await admin.post('/gestion/tiers', { kind: 'Fournisseur', name: 'Papeterie du Nord', email: 'contact@papeterie.test' });
  const partnerId = db.prepare("SELECT id FROM partners WHERE name = 'Papeterie du Nord'").get().id;

  await t.test('un seul interlocuteur principal à la fois', async () => {
    const add = async (name, primary) => {
      await admin.refreshToken(`/partenaires/${partnerId}`);
      await admin.post(`/partenaires/${partnerId}/contacts`, {
        name, role: 'Commerciale', email: `${name.toLowerCase()}@papeterie.test`, ...(primary ? { is_primary: '1' } : {}),
      });
    };
    await add('Claire', true);
    await add('Marc', true);

    const list = partners.contacts(partnerId);
    assert.equal(list.filter((c) => c.is_primary).length, 1);
    assert.equal(list.find((c) => c.is_primary).name, 'Marc');
  });

  await t.test('une adresse électronique invalide ne rentre pas', async () => {
    await admin.refreshToken(`/partenaires/${partnerId}`);
    await admin.post(`/partenaires/${partnerId}/contacts`, { name: 'Faux', email: 'pas-une-adresse' });
    assert.equal(partners.contacts(partnerId).length, 2);
  });

  await t.test('une pièce se lit à son état, pas à sa présence', async () => {
    const add = async (kind, issued, expires) => {
      await admin.refreshToken(`/partenaires/${partnerId}`);
      await admin.post(`/partenaires/${partnerId}/pieces`, { kind, issued_on: issued, expires_on: expires });
    };
    await add('Attestation de vigilance', shift(-400), shift(-10));
    await add('Assurance', shift(-300), shift(20));
    await add('Kbis', shift(-30), shift(300));
    // Une validité antérieure à l'émission trahit une inversion de saisie.
    await add('Certification', shift(10), shift(-10));

    const sheet = partners.sheet(partnerId);
    assert.equal(sheet.documents.length, 3);
    assert.deepEqual(sheet.documents.map((d) => d.state), ['perime', 'bientot', 'valable']);
    assert.deepEqual(sheet.compliance, { expired: 1, soon: 1, total: 3 });
  });

  await t.test('les pièces datées rejoignent le moteur d\'échéances', () => {
    const rows = deadlines.collect().filter((row) => row.source === 'Conformité tiers');
    assert.equal(rows.length, 2);
    assert.equal(rows.filter((row) => row.overdue).length, 1);
  });

  await t.test('la note affichée est celle de la dernière évaluation', async () => {
    const review = async (on, marks, next) => {
      await admin.refreshToken(`/partenaires/${partnerId}`);
      await admin.post(`/partenaires/${partnerId}/evaluations`, { reviewed_on: on, ...marks, next_review: next });
    };
    await review(shift(-200), { quality: '5', lead_time: '4', price: '3' }, shift(-5));
    await review(shift(-10), { quality: '2', lead_time: '2', price: '3' }, shift(30));
    // Une note hors bornes, et une évaluation sans aucune note, sont refusées.
    await review(today(), { quality: '9' }, '');
    await review(today(), {}, '');

    const sheet = partners.sheet(partnerId);
    assert.equal(sheet.reviews.length, 2);
    assert.equal(sheet.lastScore, 2.3);
  });

  await t.test('seule la dernière évaluation porte une échéance de revue', () => {
    const rows = deadlines.collect().filter((row) => row.source === 'Évaluation tiers');
    assert.equal(rows.length, 1);
    assert.equal(rows[0].due, shift(30));
  });

  await t.test('la fiche est fermée à qui n\'a pas la gestion', async () => {
    const curieux = await makeMember(admin, 'curieux@test.local', { first_name: 'Ken', last_name: 'Curieux' });
    assert.equal((await curieux.client.get(`/partenaires/${partnerId}`)).status, 403);
  });
});
