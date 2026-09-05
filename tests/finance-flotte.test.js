const test = require('node:test');
const assert = require('node:assert/strict');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

prepareEnvironment();

const createApp = require('../src/app');
const db = require('../src/db');
const modules = require('../src/modules');
const treasury = require('../src/treasury');
const fleet = require('../src/fleet');

let server;
let baseUrl;

test.before(async () => {
  server = await startServer(createApp());
  baseUrl = `http://127.0.0.1:${server.address().port}`;
});

test.after(() => server.close());

const newClient = () => new Client(baseUrl);
const today = () => new Date().toISOString().slice(0, 10);

async function loginAsAdmin() {
  const admin = newClient();
  await admin.login(ADMIN_EMAIL, ADMIN_PASSWORD);
  return admin;
}

test('Trésorerie', async (t) => {
  const admin = await loginAsAdmin();

  await t.test('les routes du module restent fermées tant qu\'il est éteint', async () => {
    assert.equal((await admin.get('/tresorerie')).status, 404);
    assert.equal((await admin.get('/immobilisations')).status, 404);

    modules.setEnabled('tresorerie', true);
    modules.setEnabled('immobilisations', true);
    assert.equal((await admin.get('/tresorerie')).status, 200);
    assert.equal((await admin.get('/immobilisations')).status, 200);
  });

  await t.test("le solde se recalcule des mouvements et n'est jamais stocké", async () => {
    await admin.refreshToken('/tresorerie');
    await admin.post('/tresorerie/comptes', { label: 'Compte courant', bank: 'Banque X', iban_last4: 'FR76 1234', opening_balance: '10000' });

    const account = db.prepare('SELECT * FROM bank_accounts').get();
    // Seuls les quatre derniers chiffres sont gardés.
    assert.equal(account.iban_last4, '1234');
    assert.equal(treasury.balanceOf(account), 10000);

    for (const [label, amount] of [['Loyer', '-1200'], ['Encaissement client', '4500,50'], ['Salaires', '-3000']]) {
      await admin.refreshToken('/tresorerie');
      await admin.post('/tresorerie/mouvements', { account_id: String(account.id), value_date: today(), label, amount });
    }
    assert.equal(treasury.balanceOf(account), 10300.5);
  });

  await t.test('refuse un mouvement nul', async () => {
    const account = db.prepare('SELECT * FROM bank_accounts').get();
    await admin.refreshToken('/tresorerie');
    await admin.post('/tresorerie/mouvements', { account_id: String(account.id), value_date: today(), label: 'Rien', amount: '0' });
    assert.match((await admin.flash('/tresorerie')).message, /Montant invalide/);
  });

  await t.test('le rapprochement refuse un sens contraire à la facture', async () => {
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/factures', {
      direction: 'Client', reference: 'FC-2026-001', label: 'Prestation janvier', partner_id: '',
      amount_ht: '3750,42', issue_date: today(), due_date: today(), vat_rate: '20', status: 'Émise',
    });
    const invoice = db.prepare('SELECT * FROM invoices').get();
    assert.ok(invoice, 'la facture est créée');

    const sortie = db.prepare("SELECT * FROM bank_transactions WHERE label = 'Loyer'").get();
    await admin.refreshToken('/tresorerie');
    await admin.post(`/tresorerie/mouvements/${sortie.id}/rapprocher`, { invoice_id: String(invoice.id) });
    assert.match((await admin.flash('/tresorerie')).message, /sens du mouvement/);
    assert.notEqual(db.prepare('SELECT status FROM invoices WHERE id = ?').get(invoice.id).status, 'Payée');

    const entree = db.prepare("SELECT * FROM bank_transactions WHERE label = 'Encaissement client'").get();
    await admin.refreshToken('/tresorerie');
    await admin.post(`/tresorerie/mouvements/${entree.id}/rapprocher`, { invoice_id: String(invoice.id) });
    assert.equal(db.prepare('SELECT status FROM invoices WHERE id = ?').get(invoice.id).status, 'Payée');
  });

  await t.test('la projection cumule les échéances et repère le point bas', async () => {
    const dans = (jours) => {
      const d = new Date();
      d.setUTCDate(d.getUTCDate() + jours);
      return d.toISOString().slice(0, 10);
    };

    for (const [label, on, amount] of [['Acompte marché', dans(10), '8000'], ['Échéance emprunt', dans(20), '-15000'], ['Solde client', dans(40), '6000']]) {
      await admin.refreshToken('/tresorerie');
      await admin.post('/tresorerie/previsions', { label, expected_on: on, amount, certainty: 'Probable' });
    }

    const projection = treasury.projection();
    assert.equal(projection.start, 10300.5);
    assert.equal(projection.end, 9300.5);          // +8000 −15000 +6000
    assert.equal(projection.lowest.balance, 3300.5); // après l'échéance d'emprunt
    assert.equal(projection.lowest.label, 'Échéance emprunt');
  });
});

test('Immobilisations', async (t) => {
  const admin = await loginAsAdmin();

  await t.test("le linéaire répartit la valeur à parts égales", async () => {
    await admin.refreshToken('/immobilisations');
    await admin.post('/immobilisations', {
      label: 'Poste de travail', category: 'Matériel', acquired_on: '2026-01-10',
      amount: '3000', duration_years: '3', method: 'Linéaire',
    });

    const asset = treasury.assets().find((a) => a.label === 'Poste de travail');
    assert.deepEqual(asset.schedule.map((r) => r.charge), [1000, 1000, 1000]);
    assert.equal(asset.schedule[2].residual, 0);
  });

  await t.test('le dégressif bascule sur le linéaire quand celui-ci devient plus favorable', async () => {
    await admin.refreshToken('/immobilisations');
    await admin.post('/immobilisations', {
      label: 'Machine-outil', category: 'Matériel', acquired_on: '2026-03-01',
      amount: '10000', duration_years: '5', method: 'Dégressif',
    });

    const asset = treasury.assets().find((a) => a.label === 'Machine-outil');
    const charges = asset.schedule.map((r) => r.charge);
    // Coefficient 1,75 pour cinq ans : 10 000 × 0,35 la première année.
    assert.equal(charges[0], 3500);
    assert.equal(charges[1], 2275);
    assert.equal(charges[2], 1478.75);
    // Les deux dernières années passent au linéaire sur la valeur résiduelle.
    assert.ok(Math.round(Math.abs(charges[3] - charges[4]) * 100) <= 1, 'les deux dernières dotations sont égales à un centime près');
    assert.equal(asset.schedule[4].residual, 0);
    assert.equal(Math.round(charges.reduce((a, b) => a + b, 0)), 10000);
  });

  await t.test('refuse une durée hors bornes', async () => {
    await admin.refreshToken('/immobilisations');
    await admin.post('/immobilisations', {
      label: 'Trop long', acquired_on: today(), amount: '500', duration_years: '80', method: 'Linéaire',
    });
    assert.match((await admin.flash('/immobilisations')).message, /entre 1 et 50 ans/);
  });

  await t.test("refuse une cession antérieure à l'acquisition", async () => {
    const asset = db.prepare("SELECT * FROM fixed_assets WHERE label = 'Poste de travail'").get();
    await admin.refreshToken('/immobilisations');
    await admin.post(`/immobilisations/${asset.id}/ceder`, { disposed_on: '2020-01-01' });
    assert.match((await admin.flash('/immobilisations')).message, /ne précède pas l'acquisition/);
    assert.equal(db.prepare('SELECT disposed_on FROM fixed_assets WHERE id = ?').get(asset.id).disposed_on, null);
  });
});

test('Flotte de véhicules', async (t) => {
  const admin = await loginAsAdmin();
  let vehicleId;

  await t.test('enregistre un véhicule et refuse un doublon', async () => {
    await admin.refreshToken('/flotte');
    await admin.post('/flotte', {
      registration: 'ab-123-cd', kind: 'Utilitaire', brand: 'Renault', model: 'Kangoo',
      acquired_on: '2023-05-12', mileage: '48000', inspection_due: '2026-11-30', insurance_due: '2026-10-01',
    });

    const vehicle = db.prepare('SELECT * FROM vehicles').get();
    vehicleId = vehicle.id;
    assert.equal(vehicle.registration, 'AB-123-CD', "l'immatriculation est normalisée en majuscules");

    await admin.refreshToken('/flotte');
    await admin.post('/flotte', { registration: 'AB-123-CD', kind: 'Voiture' });
    assert.match((await admin.flash('/flotte')).message, /déjà enregistré/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM vehicles').get().n, 1);
  });

  await t.test('un relevé fait avancer le compteur, jamais reculer', async () => {
    await admin.refreshToken(`/flotte/${vehicleId}`);
    await admin.post(`/flotte/${vehicleId}/evenements`, {
      kind: 'Entretien', occurred_on: today(), mileage: '52000', cost: '340,50', note: 'Vidange et filtres',
    });
    assert.equal(fleet.byId(vehicleId).mileage, 52000);

    // Un relevé plus ancien est consigné, mais ne fait pas reculer le compteur.
    await admin.refreshToken(`/flotte/${vehicleId}`);
    await admin.post(`/flotte/${vehicleId}/evenements`, { kind: 'Carburant', occurred_on: today(), mileage: '50000', cost: '90' });
    assert.equal(fleet.byId(vehicleId).mileage, 52000);
  });

  await t.test('la fiche refuse un compteur qui recule', async () => {
    await admin.refreshToken(`/flotte/${vehicleId}`);
    await admin.post(`/flotte/${vehicleId}/modifier`, {
      kind: 'Utilitaire', status: 'En service', mileage: '10000',
    });
    assert.match((await admin.flash(`/flotte/${vehicleId}`)).message, /ne recule pas/);
    assert.equal(fleet.byId(vehicleId).mileage, 52000);
  });

  await t.test('une échéance dépassée remonte comme telle', () => {
    db.prepare("UPDATE vehicles SET inspection_due = '2020-01-01' WHERE id = ?").run(vehicleId);
    const deadlines = fleet.deadlines();
    const inspection = deadlines.find((d) => d.label === 'Contrôle technique');
    assert.ok(inspection);
    assert.equal(inspection.overdue, true);
    assert.equal(fleet.summary().overdue >= 1, true);
  });

  await t.test("le coût sur douze mois agrège les événements", () => {
    assert.equal(fleet.summary().cost, 430.5);
  });

  await t.test("l'espace est fermé au personnel", async () => {
    await admin.refreshToken('/admin');
    await admin.post('/admin/employes', {
      first_name: 'Sans', last_name: 'Droits', grade: 'Employé', contract_type: 'CDI', email: 'sans-droits@test.local',
    });
    const password = (await admin.flash('/admin')).message.match(/Mot de passe temporaire : ([A-Za-z0-9]+)/)[1];
    const client = newClient();
    await client.firstAccess('sans-droits@test.local', password);

    assert.equal((await client.get('/flotte')).status, 403);
    assert.equal((await client.get('/tresorerie')).status, 403);
    assert.equal((await client.get('/immobilisations')).status, 403);
  });
});
