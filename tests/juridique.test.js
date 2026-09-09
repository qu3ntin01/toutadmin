const test = require('node:test');
const assert = require('node:assert/strict');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

prepareEnvironment();

const createApp = require('../src/app');
const db = require('../src/db');
const corporate = require('../src/corporate');
const privacy = require('../src/privacy');
const deadlines = require('../src/deadlines');

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
    first_name: 'Maya', last_name: 'Membre', grade: 'Employé', contract_type: 'CDI', email, ...extra,
  });
  const password = (await admin.flash('/admin')).message.match(/Mot de passe temporaire : ([A-Za-z0-9]+)/)[1];
  const client = newClient();
  await client.firstAccess(email, password);
  return { client, id: db.prepare('SELECT id FROM users WHERE email = ?').get(email).id };
}

// ---------------------------------------------------------------- capital

test('Registre du capital', async (t) => {
  const admin = await loginAsAdmin();

  await admin.refreshToken('/juridique');
  await admin.post('/juridique/associes', { name: 'Alice Fond', kind: 'Personne physique' });
  await admin.refreshToken('/juridique');
  await admin.post('/juridique/associes', { name: 'Bêta Holding', kind: 'Personne morale', registration: '812 345 678' });

  const [alice, beta] = db.prepare('SELECT id, name FROM shareholders ORDER BY id').all();

  await t.test('la détention se déduit des mouvements, jamais saisie directement', async () => {
    await admin.refreshToken('/juridique');
    await admin.post('/juridique/mouvements', {
      shareholder_id: String(alice.id), kind: 'Souscription', moved_on: '2024-01-15', shares: '700', unit_price: '10',
    });
    await admin.refreshToken('/juridique');
    await admin.post('/juridique/mouvements', {
      shareholder_id: String(beta.id), kind: 'Souscription', moved_on: '2024-01-15', shares: '300', unit_price: '10',
    });

    const capital = corporate.capital();
    assert.equal(capital.total, 1000);
    assert.deepEqual(capital.holders.map((h) => [h.name, h.shares, h.share]), [
      ['Alice Fond', 700, 70],
      ['Bêta Holding', 300, 30],
    ]);
    assert.deepEqual(capital.majority, ['Alice Fond']);
  });

  await t.test("on ne cède pas plus qu'on ne détient", async () => {
    await admin.refreshToken('/juridique');
    await admin.post('/juridique/mouvements', {
      shareholder_id: String(beta.id), kind: 'Cession', moved_on: '2024-06-01', shares: '400', counterparty_id: String(alice.id),
    });

    assert.match((await admin.flash('/juridique')).message, /ne détient que 300/);
    assert.equal(corporate.capital().total, 1000);
  });

  await t.test('une cession écrit ses deux jambes, ou aucune', async () => {
    await admin.refreshToken('/juridique');
    await admin.post('/juridique/mouvements', {
      shareholder_id: String(beta.id), kind: 'Cession', moved_on: '2024-06-01', shares: '100',
      unit_price: '12', counterparty_id: String(alice.id),
    });

    assert.deepEqual(corporate.capital().holders.map((h) => [h.name, h.shares]), [
      ['Alice Fond', 800],
      ['Bêta Holding', 200],
    ]);
    // Le capital total ne bouge pas : les titres ont changé de mains, pas de nombre.
    assert.equal(corporate.capital().total, 1000);

    const legs = corporate.movements().filter((m) => m.moved_on === '2024-06-01');
    assert.deepEqual(legs.map((m) => [m.shareholder_name, m.kind, m.shares]).sort(), [
      ['Alice Fond', 'Acquisition', 100],
      ['Bêta Holding', 'Cession', -100],
    ]);
  });

  await t.test('un associé qui détient encore des titres ne se supprime pas', async () => {
    await admin.refreshToken('/juridique');
    await admin.post(`/juridique/associes/${beta.id}/supprimer`, {});

    assert.match((await admin.flash('/juridique')).message, /détient encore des titres/);
    assert.ok(corporate.shareholderById(beta.id));
  });
});

// ---------------------------------------------------------------- assemblées

test('Assemblées générales', async (t) => {
  const admin = await loginAsAdmin();

  await admin.refreshToken('/juridique');
  await admin.post('/juridique/assemblees', {
    kind: 'Assemblée générale ordinaire', held_on: '2025-06-12', location: 'Siège', quorum_required: '500',
  });
  const meeting = db.prepare('SELECT * FROM general_meetings ORDER BY id DESC').get();

  await t.test("la référence est attribuée par le registre, pas par l'utilisateur", () => {
    assert.match(meeting.reference, /^AG-\d{4}-\d{3}$/);
  });

  await t.test('la majorité se calcule sur les voix exprimées', async () => {
    for (const [label, majority] of [['Approbation des comptes', '50'], ['Modification des statuts', '66.7']]) {
      await admin.refreshToken(`/juridique/assemblees/${meeting.id}`);
      await admin.post(`/juridique/assemblees/${meeting.id}/resolutions`, { label, majority_required: majority });
    }

    // 300 pour, 200 contre, 400 abstentions : 60 % des voix exprimées. Les
    // abstentions, écartées du dénominateur, ne font pas basculer le résultat.
    for (const resolution of corporate.resolutions(meeting.id)) {
      await admin.refreshToken(`/juridique/assemblees/${meeting.id}`);
      await admin.post(`/juridique/resolutions/${resolution.id}/vote`, {
        votes_for: '300', votes_against: '200', votes_abstain: '400',
      });
    }

    assert.deepEqual(corporate.resolutions(meeting.id).map((r) => [r.majority_required, r.outcome]), [
      [50, 'Adoptée'],
      [66.7, 'Rejetée'],
    ]);
  });

  await t.test("le quorum se compte en titres, et dit ce qui manque", async () => {
    assert.equal(corporate.quorum(meeting).reached, false);
    assert.equal(corporate.quorum(meeting).missing, 500);

    await admin.refreshToken(`/juridique/assemblees/${meeting.id}`);
    await admin.post(`/juridique/assemblees/${meeting.id}/modifier`, {
      kind: meeting.kind, held_on: meeting.held_on, location: 'Siège',
      quorum_required: '500', shares_present: '600', status: 'Tenue',
    });

    await admin.refreshToken(`/juridique/assemblees/${meeting.id}`);
    await admin.post(`/juridique/assemblees/${meeting.id}/proces-verbal`, { minutes: 'Séance ouverte à 10 h.' });

    const held = corporate.meetingById(meeting.id);
    assert.match(held.minutes, /Séance ouverte/);
    const quorum = corporate.quorum(held);
    assert.equal(quorum.reached, true);
    assert.equal(quorum.share, 60);
    assert.equal(held.status, 'Tenue');
  });
});

// ---------------------------------------------------------------- conformité

test('Déclarations de conformité', async (t) => {
  const admin = await loginAsAdmin();
  const membre = await makeMember(admin, 'conformite@test.local');

  await t.test('chacun déclare pour soi : le formulaire ne désigne pas son auteur', async () => {
    await membre.client.refreshToken('/juridique');
    // Le champ est fourni, et doit rester sans effet : l'identifiant vient de la session.
    await membre.client.post('/juridique/interets', {
      kind: 'Intérêt financier', entity: 'Fournisseur Oméga', declared_on: '2025-03-04',
      description: 'Parts détenues par mon conjoint.', user_id: '1',
    });

    const rows = db.prepare('SELECT DISTINCT user_id FROM interest_declarations').all();
    assert.deepEqual(rows.map((r) => r.user_id), [membre.id]);
  });

  await t.test('un membre ne voit que ses propres déclarations, et rien des registres', async () => {
    const { body } = await membre.client.html('/juridique');
    assert.match(body, /Fournisseur Oméga/);
    assert.doesNotMatch(body, /Bêta Holding/);

    const meeting = db.prepare('SELECT id FROM general_meetings ORDER BY id DESC').get();
    assert.equal((await membre.client.get(`/juridique/assemblees/${meeting.id}`)).status, 403);
  });

  await t.test('au-delà du seuil, un cadeau attend un examen', async () => {
    for (const [kind, value] of [['Invitation', '400'], ['Cadeau', '30']]) {
      await membre.client.refreshToken('/juridique');
      await membre.client.post('/juridique/cadeaux', {
        direction: 'Reçu', kind, third_party: 'Oméga', occurred_on: '2025-04-02', value,
      });
    }

    assert.deepEqual(corporate.giftsToReview().map((g) => [g.kind, g.value]), [['Invitation', 400]]);
  });

  await t.test("l'examen consigne la mesure prise et son auteur", async () => {
    const declaration = db.prepare('SELECT id FROM interest_declarations ORDER BY id').get();
    await admin.refreshToken('/juridique');
    await admin.post(`/juridique/interets/${declaration.id}/examen`, {
      status: 'Mesure prise', measure: 'Retrait des décisions concernant ce fournisseur.',
    });

    const reviewed = corporate.declarations().find((d) => d.id === declaration.id);
    assert.equal(reviewed.status, 'Mesure prise');
    assert.match(reviewed.measure, /Retrait des décisions/);
    assert.ok(reviewed.reviewed_by);
  });

  await t.test('les déclarations suivent la personne dans son extraction RGPD', () => {
    const sources = privacy.collectFor(membre.id);
    const interests = sources.find((s) => s.key === 'declarations_interets');
    const gifts = sources.find((s) => s.key === 'cadeaux');

    assert.equal(interests.rows.length, 1);
    assert.equal(gifts.rows.length, 2);
    // Un registre de conformité ne s'efface pas à la demande de celui qu'il recense.
    assert.equal(interests.erasable, false);
    assert.equal(gifts.erasable, false);
  });
});

// ---------------------------------------------------------------- délégations

test('Délégations de pouvoir', async (t) => {
  const admin = await loginAsAdmin();
  const membre = await makeMember(admin, 'delegataire@test.local', { first_name: 'Théo' });

  await t.test("une délégation qui s'achève avant de commencer est refusée", async () => {
    await admin.refreshToken('/juridique');
    await admin.post('/juridique/delegations', {
      holder_id: String(membre.id), scope: 'Essai', starts_on: '2025-05-01', ends_on: '2025-04-01',
    });

    assert.match((await admin.flash('/juridique')).message, /La fin précède le début/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM power_delegations').get().n, 0);
  });

  await t.test('la fin de mandat et la fin de délégation sont des échéances', async () => {
    const soon = new Date();
    soon.setUTCDate(soon.getUTCDate() + 10);
    const endsOn = soon.toISOString().slice(0, 10);

    await admin.refreshToken('/juridique');
    await admin.post('/juridique/delegations', {
      holder_id: String(membre.id), scope: 'Engagement des achats courants',
      amount_limit: '5000', starts_on: '2025-01-01', ends_on: endsOn,
    });
    await admin.refreshToken('/juridique');
    await admin.post('/juridique/mandats', {
      holder_name: 'Alice Fond', role: 'Président', started_on: '2024-02-01', ends_on: endsOn,
    });

    const rows = deadlines.collect().filter((row) => ['Mandat social', 'Délégation de pouvoir'].includes(row.source));
    assert.deepEqual(rows.map((row) => row.source).sort(), ['Délégation de pouvoir', 'Mandat social']);
    // Elles ne concernent que l'administration : personne d'autre ne peut y répondre.
    const admins = db.prepare("SELECT id FROM users WHERE role = 'admin' AND active = 1").all().map((r) => r.id);
    rows.forEach((row) => assert.deepEqual(row.audience, admins));
  });

  await t.test('un membre ordinaire ne peut ni accorder ni révoquer', async () => {
    const delegation = db.prepare('SELECT id FROM power_delegations ORDER BY id DESC').get();

    await membre.client.refreshToken('/juridique');
    const refused = await membre.client.post('/juridique/delegations', {
      holder_id: String(membre.id), scope: 'Tout engager', starts_on: '2025-01-01',
    });
    assert.equal(refused.status, 403);

    await membre.client.refreshToken('/juridique');
    const revoke = await membre.client.post(`/juridique/delegations/${delegation.id}/statut`, { status: 'Révoquée' });
    assert.equal(revoke.status, 403);
    assert.equal(db.prepare('SELECT status FROM power_delegations WHERE id = ?').get(delegation.id).status, 'En vigueur');
  });
});
