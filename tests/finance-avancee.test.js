const test = require('node:test');
const assert = require('node:assert/strict');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

prepareEnvironment();

const createApp = require('../src/app');
const db = require('../src/db');
const currency = require('../src/currency');
const billing = require('../src/billing');
const vat = require('../src/vat');
const finance = require('../src/finance');
const steering = require('../src/steering');

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

const shift = (days) => {
  const date = new Date();
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
};

test('Multidevise', async (t) => {
  const admin = await loginAsAdmin();

  await t.test('la devise de référence vaut 1 par définition', () => {
    assert.equal(currency.base(), 'EUR');
    assert.equal(currency.rateOf('EUR'), 1);

    const verdict = currency.setRate('EUR', 1.5);
    assert.equal(verdict.ok, false);
    assert.match(verdict.message, /par définition/);
  });

  await t.test('un taux nul, négatif ou illisible est refusé', () => {
    for (const value of [0, -2, 'beaucoup', null]) {
      assert.equal(currency.setRate('USD', value).ok, false);
    }
    assert.equal(currency.setRate('XXX', 1).ok, false);
    assert.equal(currency.rateOf('USD'), null);
  });

  await t.test('seules les devises cotées sont proposées', () => {
    assert.deepEqual(currency.usable().map((c) => c.code), ['EUR']);

    assert.equal(currency.setRate('USD', 0.9).ok, true);
    assert.deepEqual(currency.usable().map((c) => c.code).sort(), ['EUR', 'USD']);
  });

  await t.test('facturer dans une devise sans taux est refusé', async () => {
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/factures', {
      direction: 'Client', label: 'Prestation à Londres', issue_date: shift(0),
      amount_ht: '1000', vat_rate: '20', currency: 'GBP', status: 'Émise',
    });
    const message = await admin.flash('/gestion');
    assert.equal(message.type, 'error');
    assert.match(message.message, /GBP/);
    assert.equal(finance.invoices().length, 0);
  });

  await t.test('la facture fige le taux de son émission', async () => {
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/factures', {
      direction: 'Client', label: 'Prestation à New York', issue_date: shift(0),
      amount_ht: '1000', vat_rate: '20', currency: 'USD', status: 'Émise',
    });

    const invoice = finance.invoices()[0];
    assert.equal(invoice.currency, 'USD');
    assert.equal(invoice.exchange_rate, 0.9);
    assert.equal(invoice.amount_ttc, 1200, 'le client paie 1 200 dollars');
    assert.equal(invoice.amount_base_ttc, 1080, 'les comptes retiennent 1 080 euros');
    assert.equal(invoice.foreign, true);
  });

  await t.test('changer le taux ne réécrit pas les pièces déjà émises', () => {
    const avant = finance.financialSummary(new Date().getUTCFullYear()).income;
    currency.setRate('USD', 0.5);

    const invoice = finance.invoices()[0];
    assert.equal(invoice.exchange_rate, 0.9, 'le taux vit dans la pièce, pas dans la table des taux');
    assert.equal(finance.financialSummary(new Date().getUTCFullYear()).income, avant);
    currency.setRate('USD', 0.9);
  });

  await t.test('les totaux sont exprimés en devise de référence', () => {
    const summary = finance.financialSummary(new Date().getUTCFullYear());
    assert.equal(summary.currency, 'EUR');
    assert.equal(summary.income, 1080);
    assert.equal(steering.revenue(new Date().getUTCFullYear()).sales, 900, 'le HT converti, pas le HT facial');
  });

  await t.test('la devise de référence relève de l\'administration', async () => {
    // Un gestionnaire financier n'est pas administrateur : il ne change pas
    // l'unité dans laquelle l'entreprise tient ses comptes.
    const gestionnaire = newClient();
    await admin.refreshToken('/admin');
    await admin.post('/admin/employes', {
      first_name: 'Yann', last_name: 'Devise', grade: 'Employé', contract_type: 'CDI',
      email: 'yann.devise@test.local',
    });
    const flash = await admin.flash('/admin');
    const temporaire = flash.message.match(/Mot de passe temporaire : ([A-Za-z0-9]+)/)[1];
    const userId = db.prepare('SELECT id FROM users WHERE email = ?').get('yann.devise@test.local').id;
    db.prepare('UPDATE users SET is_finance = 1 WHERE id = ?').run(userId);
    await gestionnaire.firstAccess('yann.devise@test.local', temporaire);

    await gestionnaire.refreshToken('/gestion');
    await gestionnaire.post('/gestion/devises/reference', { code: 'USD' });
    const refus = await gestionnaire.flash('/gestion');
    assert.equal(refus.type, 'error');
    assert.equal(currency.base(), 'EUR');

    // Il peut en revanche tenir les taux à jour : c'est son métier.
    await gestionnaire.post('/gestion/devises/taux', { code: 'CHF', rate: '1,05' });
    assert.equal(currency.rateOf('CHF'), 1.05);
  });
});

test('Facturation récurrente', async (t) => {
  const admin = await loginAsAdmin();
  let subscriptionId;

  await t.test('un abonnement se crée avec sa première échéance', async () => {
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/abonnements', {
      label: 'Maintenance annuelle — site vitrine',
      direction: 'Client', period: 'Mensuelle',
      amount_ht: '250', vat_rate: '20', currency: 'EUR',
      start_date: shift(-1), payment_days: '30',
    });

    const list = billing.list();
    assert.equal(list.length, 1);
    subscriptionId = list[0].id;
    assert.equal(list[0].next_issue, shift(-1));
    assert.equal(list[0].active, 1);
  });

  await t.test('l\'émission crée la facture et avance l\'échéance', () => {
    const result = billing.run();
    assert.equal(result.issued.length, 1);
    assert.equal(result.skipped.length, 0);

    const invoice = finance.invoiceById(result.issued[0].id);
    assert.equal(invoice.amount_ht, 250);
    assert.equal(invoice.status, 'Émise');
    assert.equal(invoice.subscription_id, subscriptionId);
    assert.equal(invoice.due_date, shift(29), 'échéance à trente jours de la facture');
    assert.match(invoice.reference, /^AB-\d+-\d{4}-\d{2}$/);

    const subscription = billing.byId(subscriptionId);
    assert.notEqual(subscription.next_issue, shift(-1), "l'échéance a avancé d'un mois");
  });

  await t.test('rejouer l\'émission ne facture pas deux fois', () => {
    const result = billing.run();
    assert.equal(result.issued.length, 0);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM invoices WHERE subscription_id = ?').get(subscriptionId).n, 1);
  });

  await t.test('un abonnement suspendu n\'émet plus rien', () => {
    db.prepare('UPDATE subscriptions SET next_issue = ? WHERE id = ?').run(shift(0), subscriptionId);
    billing.setActive(subscriptionId, false);

    assert.equal(billing.due().length, 0);
    assert.equal(billing.run().issued.length, 0);
    billing.setActive(subscriptionId, true);
  });

  await t.test('un abonnement dont le terme est passé s\'éteint de lui-même', () => {
    // Terme fixé avant la prochaine échéance mensuelle : la facture du jour
    // part, la suivante n'existera pas.
    db.prepare('UPDATE subscriptions SET next_issue = ?, end_date = ? WHERE id = ?')
      .run(shift(1), shift(10), subscriptionId);

    const result = billing.run({ asOf: shift(1) });
    assert.equal(result.issued.length, 1);
    assert.equal(billing.byId(subscriptionId).active, 0, "le moule s'arrête au terme signé");
  });

  await t.test('une échéance en devise sans taux est écartée, sans bloquer les autres', () => {
    currency.forgetRate('CHF');
    db.prepare(`
      INSERT INTO subscriptions (direction, label, amount_ht, vat_rate, currency, period, start_date, next_issue)
      VALUES ('Client', 'Hébergement suisse', 100, 20, 'CHF', 'Mensuelle', ?, ?)
    `).run(shift(-2), shift(-2));
    db.prepare(`
      INSERT INTO subscriptions (direction, label, amount_ht, vat_rate, currency, period, start_date, next_issue)
      VALUES ('Fournisseur', 'Licences', 80, 20, 'EUR', 'Mensuelle', ?, ?)
    `).run(shift(-2), shift(-2));

    const result = billing.run();
    assert.deepEqual(result.issued.map((i) => i.label), ['Licences']);
    assert.equal(result.skipped.length, 1);
    assert.match(result.skipped[0].reason, /taux CHF inconnu/);
  });

  await t.test('la valeur annuelle ignore ce qui n\'est pas convertible', () => {
    const value = billing.annualValue();
    // L'abonnement suisse reste sans taux : il ne peut pas entrer dans un total.
    assert.equal(value.supplier, 960);
    assert.equal(value.currency, 'EUR');
  });

  await t.test('supprimer l\'abonnement laisse les factures dues', async () => {
    const before = db.prepare('SELECT COUNT(*) AS n FROM invoices WHERE subscription_id = ?').get(subscriptionId).n;
    assert.ok(before > 0);

    await admin.refreshToken('/gestion');
    await admin.post(`/gestion/abonnements/${subscriptionId}/supprimer`, {});

    assert.equal(billing.byId(subscriptionId), null);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM invoices WHERE subscription_id = ?').get(subscriptionId).n, 0);
    assert.equal(db.prepare("SELECT COUNT(*) AS n FROM invoices WHERE label LIKE 'Maintenance annuelle%'").get().n, before,
      'les factures restent, seul le lien vers le moule disparaît');
  });
});

test('Déclarations de TVA', async (t) => {
  const admin = await loginAsAdmin();
  const year = new Date().getUTCFullYear();
  const from = `${year}-01-01`;
  const to = `${year}-12-31`;

  await t.test('le calcul ventile par taux et ignore ce qui n\'est pas émis', () => {
    db.prepare('DELETE FROM invoices').run();
    const insert = db.prepare(`
      INSERT INTO invoices (direction, label, issue_date, amount_ht, vat_rate, status, currency, exchange_rate)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    `);
    insert.run('Client', 'Prestation', `${year}-03-10`, 1000, 20, 'Émise', 'EUR', 1);
    insert.run('Client', 'Livraison', `${year}-03-15`, 500, 5.5, 'Payée', 'EUR', 1);
    insert.run('Fournisseur', 'Matériel', `${year}-03-20`, 400, 20, 'Émise', 'EUR', 1);
    insert.run('Client', 'Devis non émis', `${year}-03-25`, 9000, 20, 'Brouillon', 'EUR', 1);
    insert.run('Client', 'Commande annulée', `${year}-03-28`, 7000, 20, 'Annulée', 'EUR', 1);

    const totals = vat.compute(from, to);
    assert.equal(totals.invoices, 3, 'brouillon et annulée ne génèrent pas de TVA');
    assert.equal(totals.collected, 227.5);
    assert.equal(totals.deductible, 80);
    assert.equal(totals.due, 147.5);
    assert.equal(totals.credit, 0);
    assert.deepEqual(totals.byRate.map((r) => r.rate), [20, 5.5]);
    assert.equal(totals.byRate[0].collected, 200);
    assert.equal(totals.byRate[0].deductible, 80);
  });

  await t.test('une facture en devise est convertie au taux figé', () => {
    db.prepare(`
      INSERT INTO invoices (direction, label, issue_date, amount_ht, vat_rate, status, currency, exchange_rate)
      VALUES ('Client', 'Prestation en dollars', ?, 1000, 20, 'Émise', 'USD', 0.9)
    `).run(`${year}-04-02`);

    const totals = vat.compute(from, to);
    // 1 000 USD × 0,9 = 900 € de base, soit 180 € de TVA de plus.
    assert.equal(totals.collected, 407.5);
    assert.equal(totals.baseCollected, 2400);
  });

  await t.test('un crédit de TVA n\'est pas une dette', () => {
    const trimestre = { from: `${year}-07-01`, to: `${year}-09-30` };
    db.prepare(`
      INSERT INTO invoices (direction, label, issue_date, amount_ht, vat_rate, status, currency, exchange_rate)
      VALUES ('Fournisseur', 'Gros investissement', ?, 10000, 20, 'Émise', 'EUR', 1)
    `).run(`${year}-08-01`);

    const totals = vat.compute(trimestre.from, trimestre.to);
    assert.equal(totals.due, 0);
    assert.equal(totals.credit, 2000);
  });

  await t.test('la déclaration s\'enregistre et se recalcule tant qu\'elle est brouillon', async () => {
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/tva', { periode: `Trimestriel:${year}-01-01:${year}-03-31` });

    const declaration = vat.list()[0];
    assert.equal(declaration.period_label, `T1 ${year}`);
    assert.equal(declaration.due, 147.5);
    assert.equal(declaration.status, 'Brouillon');
    assert.equal(declaration.detail.length, 2);

    // Une facture de plus dans la période, puis recalcul : le brouillon suit.
    db.prepare(`
      INSERT INTO invoices (direction, label, issue_date, amount_ht, vat_rate, status, currency, exchange_rate)
      VALUES ('Client', 'Complément', ?, 100, 20, 'Émise', 'EUR', 1)
    `).run(`${year}-03-30`);

    await admin.post('/gestion/tva', { periode: `Trimestriel:${year}-01-01:${year}-03-31` });
    assert.equal(vat.list().length, 1, 'une période, une déclaration');
    assert.equal(vat.list()[0].due, 167.5);
  });

  await t.test('une déclaration déposée ne se recalcule plus', async () => {
    const declaration = vat.list()[0];
    await admin.refreshToken('/gestion');
    await admin.post(`/gestion/tva/${declaration.id}/statut`, { status: 'Déclarée' });

    const filed = vat.byId(declaration.id);
    assert.equal(filed.status, 'Déclarée');
    assert.equal(filed.filed_on, new Date().toISOString().slice(0, 10));

    await admin.post('/gestion/tva', { periode: `Trimestriel:${year}-01-01:${year}-03-31` });
    const message = await admin.flash('/gestion');
    assert.equal(message.type, 'error');
    assert.match(message.message, /déjà déclarée/);
    assert.equal(vat.byId(declaration.id).due, 167.5, 'le montant déposé reste celui qui a été déposé');
  });

  await t.test('le paiement date la déclaration et sort du restant dû', async () => {
    const declaration = vat.list()[0];
    assert.equal(vat.summary().pending, 1);

    await admin.refreshToken('/gestion');
    await admin.post(`/gestion/tva/${declaration.id}/statut`, { status: 'Payée' });

    assert.equal(vat.byId(declaration.id).paid_on, new Date().toISOString().slice(0, 10));
    assert.equal(vat.summary().pending, 0);
    assert.equal(vat.summary().pendingAmount, 0);
  });

  await t.test('une période bricolée à la main est refusée', async () => {
    await admin.refreshToken('/gestion');
    // Trois jours de décalage feraient une déclaration fausse que personne ne
    // verrait passer : seule une période de la liste est acceptée.
    await admin.post('/gestion/tva', { periode: `Trimestriel:${year}-01-04:${year}-03-31` });
    const message = await admin.flash('/gestion');
    assert.equal(message.type, 'error');
    assert.match(message.message, /Période inconnue/);
  });

  await t.test('les périodes suivent le régime', () => {
    assert.equal(vat.periods(year, 'Mensuel').length, 12);
    assert.equal(vat.periods(year, 'Trimestriel').length, 4);
    assert.equal(vat.periods(year, 'Trimestriel')[0].end, `${year}-03-31`);
    assert.equal(vat.periods(2024, 'Mensuel')[1].end, '2024-02-29', 'une année bissextile a un 29 février');
    assert.equal(vat.periodByKey('Mensuel:2024-02-01:2024-02-29').label, 'février 2024');
  });

  await t.test('l\'espace reste réservé à la gestion', async () => {
    const anonyme = newClient();
    assert.equal((await anonyme.get('/gestion')).status, 302);
  });
});
