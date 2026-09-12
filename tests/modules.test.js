const test = require('node:test');
const assert = require('node:assert/strict');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

prepareEnvironment();

const createApp = require('../src/app');
const db = require('../src/db');
const modules = require('../src/modules');

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

async function enable(admin, key) {
  await admin.refreshToken('/admin');
  await admin.post(`/admin/modules/${key}`, { enabled: 'on' });
}

test('Modules débloquables', async (t) => {
  const admin = await loginAsAdmin();

  await t.test('tout module est éteint par défaut, et ses routes sont introuvables', async () => {
    for (const key of modules.KEYS) {
      assert.equal(modules.isEnabled(key), false, key);
    }
    for (const path of ['/comptabilite', '/paie', '/facturation-electronique', '/stock', '/crm']) {
      assert.equal((await admin.get(path)).status, 404, path);
    }
  });

  await t.test("la page d'un module éteint dit à l'administrateur où le débloquer", async () => {
    const { body } = await admin.html('/crm');
    assert.match(body, /est pas activé sur cette instance/);
    assert.match(body, /console d&#39;administration/);
  });

  await t.test('un module inconnu est refusé', async () => {
    await admin.refreshToken('/admin');
    await admin.post('/admin/modules/telepathie', { enabled: 'on' });
    assert.match((await admin.flash('/admin')).message, /Module inconnu/);
  });

  await t.test("seul un administrateur débloque un module", async () => {
    const employee = await makeMember(admin, 'curieux.module@test.local');
    const res = await employee.client.post('/admin/modules/crm', { enabled: 'on' });
    assert.equal(res.status, 403);
    assert.equal(modules.isEnabled('crm'), false);
  });

  await t.test('activer un module ouvre ses routes, le désactiver les referme', async () => {
    await enable(admin, 'crm');
    assert.equal(modules.isEnabled('crm'), true);
    assert.equal((await admin.get('/crm')).status, 200);

    await admin.refreshToken('/admin');
    await admin.post('/admin/modules/crm', {});
    assert.equal(modules.isEnabled('crm'), false);
    assert.equal((await admin.get('/crm')).status, 404);

    await enable(admin, 'crm');
  });

  await t.test("la première activation dote le module de quoi démarrer", async () => {
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM accounts').get().n, 0);
    await enable(admin, 'comptabilite');
    assert.ok(db.prepare('SELECT COUNT(*) AS n FROM accounts').get().n > 0, 'un plan comptable de départ est posé');
    assert.ok(db.prepare('SELECT COUNT(*) AS n FROM journals').get().n > 0);

    // Réactiver ne duplique pas.
    const before = db.prepare('SELECT COUNT(*) AS n FROM accounts').get().n;
    await enable(admin, 'comptabilite');
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM accounts').get().n, before);
  });
});

test('Comptabilité', async (t) => {
  const admin = await loginAsAdmin();
  await enable(admin, 'comptabilite');

  const accounting = require('../src/accounting');

  await t.test('refuse une écriture déséquilibrée', async () => {
    const journal = db.prepare("SELECT id FROM journals WHERE code = 'OD'").get();
    const banque = accounting.accountByCode('512');
    const achats = accounting.accountByCode('606');

    await admin.refreshToken('/comptabilite');
    await admin.post('/comptabilite/ecritures', {
      journal_id: String(journal.id), entry_date: '2026-03-01', label: 'Bancale',
      account_id: [String(achats.id), String(banque.id)],
      debit: ['100', '0'],
      credit: ['0', '80'],
      line_label: ['', ''],
    });

    const flash = await admin.flash('/comptabilite');
    assert.match(flash.message, /déséquilibrée/);
    assert.match(flash.message, /100.00 € au débit contre 80.00 € au crédit/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM entries').get().n, 0);
  });

  await t.test('accepte une écriture équilibrée et la restitue au grand livre', async () => {
    const journal = db.prepare("SELECT id FROM journals WHERE code = 'OD'").get();
    const banque = accounting.accountByCode('512');
    const achats = accounting.accountByCode('606');

    await admin.refreshToken('/comptabilite');
    await admin.post('/comptabilite/ecritures', {
      journal_id: String(journal.id), entry_date: '2026-03-01', label: 'Achat de fournitures',
      account_id: [String(achats.id), String(banque.id)],
      debit: ['100', '0'],
      credit: ['0', '100'],
      line_label: ['Fournitures', 'Règlement'],
    });

    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM entries').get().n, 1);
    const ledger = accounting.ledger(banque.id, { from: '2026-01-01', to: '2026-12-31' });
    assert.equal(ledger.length, 1);
    assert.equal(ledger[0].running, -100);
  });

  await t.test("refuse une ligne qui porte à la fois un débit et un crédit", async () => {
    const journal = db.prepare("SELECT id FROM journals WHERE code = 'OD'").get();
    const banque = accounting.accountByCode('512');

    const result = accounting.createEntry({
      journalId: journal.id, entryDate: '2026-03-02', label: 'Ambiguë',
      lines: [{ accountId: banque.id, debit: 50, credit: 50 }, { accountId: banque.id, debit: 0, credit: 50 }],
      createdBy: 1,
    });
    assert.equal(result.ok, false);
    assert.equal(result.reason, 'both-sides');
  });

  await t.test("comptabilise une facture et refuse de la passer deux fois", async () => {
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/factures', {
      direction: 'Client', label: 'Prestation mars', issue_date: '2026-03-15',
      amount_ht: '1000', vat_rate: '20', reference: 'FA-2026-001',
    });
    const invoice = db.prepare("SELECT * FROM invoices WHERE reference = 'FA-2026-001'").get();

    await admin.refreshToken('/comptabilite');
    await admin.post(`/comptabilite/factures/${invoice.id}/comptabiliser`, {});

    const entry = db.prepare('SELECT * FROM entries WHERE invoice_id = ?').get(invoice.id);
    assert.ok(entry, 'la facture doit produire une écriture');

    const lines = accounting.linesOf(entry.id);
    // Vente : client au débit du TTC, produit et TVA au crédit.
    assert.equal(lines.find((l) => l.code === '411').debit, 1200);
    assert.equal(lines.find((l) => l.code === '706').credit, 1000);
    assert.equal(lines.find((l) => l.code === '4457').credit, 200);

    await admin.refreshToken('/comptabilite');
    await admin.post(`/comptabilite/factures/${invoice.id}/comptabiliser`, {});
    assert.match((await admin.flash('/comptabilite')).message, /déjà comptabilisée/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM entries WHERE invoice_id = ?').get(invoice.id).n, 1);
  });

  await t.test('la balance est équilibrée et le résultat cohérent', async () => {
    const rows = accounting.balance({ from: '2026-01-01', to: '2026-12-31' });
    const debit = rows.reduce((sum, r) => sum + r.debit, 0);
    const credit = rows.reduce((sum, r) => sum + r.credit, 0);
    assert.equal(Math.round(debit * 100), Math.round(credit * 100), 'la balance doit être équilibrée');

    const result = accounting.income({ from: '2026-01-01', to: '2026-12-31' });
    assert.equal(result.revenue, 1000);
    assert.equal(result.expenses, 100);
    assert.equal(result.result, 900);
  });

  await t.test("un compte mouvementé est désactivé plutôt que supprimé", async () => {
    const banque = accounting.accountByCode('512');
    await admin.refreshToken('/comptabilite');
    await admin.post(`/comptabilite/comptes/${banque.id}/supprimer`, {});

    const after = db.prepare('SELECT * FROM accounts WHERE id = ?').get(banque.id);
    assert.ok(after, 'le compte doit survivre');
    assert.equal(after.active, 0);
  });

  await t.test("exporte la balance en CSV", async () => {
    const res = await admin.get('/comptabilite/balance.csv?annee=2026');
    assert.equal(res.status, 200);
    assert.match(res.headers.get('content-type'), /text\/csv/);
    const body = await res.text();
    assert.match(body, /Compte;Libellé;Type;Débit;Crédit;Solde/);
    assert.match(body, /411/);
  });
});

test('Moteur de paie', async (t) => {
  const admin = await loginAsAdmin();
  await enable(admin, 'paie');

  const payroll = require('../src/payroll');
  const employee = await makeMember(admin, 'paul.paie@test.local', { first_name: 'Paul', last_name: 'Salarie' });
  const freelance = await makeMember(admin, 'fred.paie@test.local', {
    first_name: 'Fred', last_name: 'Independant', contract_type: 'Freelance', daily_rate: '500',
  });

  await t.test('décompose un brut en net, part patronale et coût employeur', async () => {
    const result = payroll.compute(3000);
    assert.equal(result.gross, 3000);
    assert.ok(result.net < result.gross, 'le net est inférieur au brut');
    assert.ok(result.employerCost > result.gross, "le coût employeur dépasse le brut");
    assert.equal(result.net, Math.round((3000 - result.employeeTotal) * 100) / 100);
    assert.equal(result.employerCost, Math.round((3000 + result.employerTotal) * 100) / 100);
  });

  await t.test('plafonne les cotisations dont la base est le plafond', async () => {
    const under = payroll.compute(2000);
    const over = payroll.compute(10000);
    const plafonnee = (r) => r.lines.find((l) => l.label.includes('vieillesse plafonnée'));

    assert.equal(plafonnee(under).baseAmount, 2000);
    // Au-delà du plafond, la base cesse de suivre le brut.
    assert.equal(plafonnee(over).baseAmount, payroll.ceiling());
  });

  await t.test('génère un bulletin détaillé et refuse un doublon', async () => {
    await admin.refreshToken('/paie');
    await admin.post('/paie/bulletins', {
      employee_id: String(employee.id), period: '2026-04', gross_salary: '2800',
    });

    const payslip = db.prepare('SELECT * FROM payslips WHERE employee_id = ?').get(employee.id);
    assert.ok(payslip);
    assert.ok(payroll.payslipLines(payslip.id).length > 0, 'le bulletin porte le détail de ses cotisations');

    await admin.refreshToken('/paie');
    await admin.post('/paie/bulletins', {
      employee_id: String(employee.id), period: '2026-04', gross_salary: '2800',
    });
    assert.match((await admin.flash('/paie')).message, /existe déjà/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM payslips WHERE employee_id = ?').get(employee.id).n, 1);
  });

  await t.test("refuse un bulletin pour un freelance", async () => {
    await admin.refreshToken('/paie');
    await admin.post('/paie/bulletins', {
      employee_id: String(freelance.id), period: '2026-04', gross_salary: '5000',
    });
    assert.match((await admin.flash('/paie')).message, /facturé, pas salarié/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM payslips WHERE employee_id = ?').get(freelance.id).n, 0);
  });

  await t.test('refuse un taux hors bornes', async () => {
    await admin.refreshToken('/paie');
    await admin.post('/paie/baremes', { label: 'Absurde', base: 'Brut', employee_rate: '150', employer_rate: '0' });
    assert.match((await admin.flash('/paie')).message, /Taux invalide/);
  });

  await t.test('génère les bulletins en lot pour les bruts renseignés', async () => {
    payroll.setGrossSalary(employee.id, 2800);
    const second = await makeMember(admin, 'sara.paie@test.local', { first_name: 'Sara', last_name: 'Lot' });
    payroll.setGrossSalary(second.id, 2200);

    await admin.refreshToken('/paie');
    await admin.post('/paie/bulletins/lot', { period: '2026-05' });

    assert.equal(db.prepare("SELECT COUNT(*) AS n FROM payslips WHERE period = '2026-05'").get().n, 2);
    const cost = payroll.payrollCost('2026-05');
    assert.equal(cost.gross, 5000);
    assert.ok(cost.cost > cost.gross);
  });

  await t.test("le personnel n'accède pas au moteur de paie", async () => {
    assert.equal((await employee.client.get('/paie')).status, 403);
  });
});

test('Facturation électronique', async (t) => {
  const admin = await loginAsAdmin();
  await enable(admin, 'facturation-electronique');

  await t.test("liste les mentions manquantes plutôt que de dire « non conforme »", async () => {
    const { body } = await admin.html('/facturation-electronique');
    // Le libellé passe désormais par le dictionnaire, et EJS échappe
    // l'apostrophe en « &#39; » : le rendu à l'écran est le même.
    assert.match(body, /Identité de l(&#39;|')émetteur incomplète/);
    assert.match(body, /SIREN/);
  });

  await t.test('refuse un SIREN mal formé', async () => {
    await admin.refreshToken('/facturation-electronique');
    await admin.post('/facturation-electronique/emetteur', {
      company_legal_name: 'Acme', company_siren: '123', company_vat: 'FR12345678901',
      company_address: '1 rue Test', company_postal_code: '75001', company_city: 'Paris', company_country: 'FR',
    });
    assert.match((await admin.flash('/facturation-electronique')).message, /SIREN/);
  });

  await t.test("bloque l'export d'une facture incomplète", async () => {
    await admin.refreshToken('/facturation-electronique');
    await admin.post('/facturation-electronique/emetteur', {
      company_legal_name: 'Acme SAS', company_siren: '123456789', company_vat: 'FR12345678901',
      company_address: '1 rue Test', company_postal_code: '75001', company_city: 'Paris', company_country: 'FR',
    });

    // Facture sans référence, sans échéance et sans client : trois manques.
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/factures', {
      direction: 'Client', label: 'Sans mentions', issue_date: '2026-06-01', amount_ht: '500', vat_rate: '20',
    });
    const invoice = db.prepare("SELECT * FROM invoices WHERE label = 'Sans mentions'").get();

    const res = await admin.get(`/facturation-electronique/factures/${invoice.id}.xml`);
    assert.equal(res.status, 302);
    assert.match((await admin.flash('/facturation-electronique')).message, /non conforme/);
  });

  await t.test('exporte un XML CII pour une facture complète', async () => {
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/tiers', {
      name: 'Client Conforme', kind: 'Client', registration: '987654321', address: '2 avenue Test, 69000 Lyon',
    });
    const partner = db.prepare("SELECT * FROM partners WHERE name = 'Client Conforme'").get();

    await admin.refreshToken('/gestion');
    await admin.post('/gestion/factures', {
      direction: 'Client', label: 'Prestation conforme', issue_date: '2026-06-10', due_date: '2026-07-10',
      amount_ht: '2000', vat_rate: '20', reference: 'FA-2026-042', partner_id: String(partner.id),
    });
    const invoice = db.prepare("SELECT * FROM invoices WHERE reference = 'FA-2026-042'").get();

    const res = await admin.get(`/facturation-electronique/factures/${invoice.id}.xml`);
    assert.equal(res.status, 200);
    assert.match(res.headers.get('content-type'), /application\/xml/);
    assert.match(res.headers.get('content-disposition'), /facture-FA-2026-042\.xml/);

    const xml = await res.text();
    assert.match(xml, /urn:cen\.eu:en16931:2017/);
    assert.match(xml, /<ram:ID>FA-2026-042<\/ram:ID>/);
    assert.match(xml, /<ram:GrandTotalAmount>2400\.00<\/ram:GrandTotalAmount>/);
    assert.match(xml, /<ram:TaxTotalAmount currencyID="EUR">400\.00<\/ram:TaxTotalAmount>/);
    assert.match(xml, /987654321/);
  });

  await t.test('échappe les caractères XML dangereux', async () => {
    const einvoicing = require('../src/einvoicing');
    const xml = einvoicing.toXml({
      id: 99, reference: 'FA<script>', label: 'Prestation & "conseil"', issue_date: '2026-06-10',
      due_date: '2026-07-10', amount_ht: 100, vat_rate: 20, partner_id: null,
    });
    assert.doesNotMatch(xml, /<script>/);
    assert.match(xml, /FA&lt;script&gt;/);
    assert.match(xml, /&amp;/);
  });
});

test('Stock, achats et CRM', async (t) => {
  const admin = await loginAsAdmin();
  await enable(admin, 'stock');
  await enable(admin, 'crm');

  const inventory = require('../src/inventory');
  const crm = require('../src/crm');

  await admin.refreshToken('/admin');
  await admin.post('/admin/services', { name: 'Ateliers' });
  const departmentId = db.prepare("SELECT id FROM departments WHERE name = 'Ateliers'").get().id;
  await admin.refreshToken('/admin');
  await admin.post('/admin/equipes', { name: 'Fabrication', department_id: String(departmentId) });
  const teamId = db.prepare("SELECT id FROM teams WHERE name = 'Fabrication'").get().id;

  const chief = await makeMember(admin, 'chef.stock@test.local', { first_name: 'Cléo', last_name: 'Chef' });
  const worker = await makeMember(admin, 'ouvrier.stock@test.local', { first_name: 'Omar', last_name: 'Ouvrier' });

  await admin.refreshToken('/admin');
  await admin.post(`/admin/employes/${worker.id}/rattachement`, { team_id: String(teamId) });
  await admin.refreshToken('/admin');
  await admin.post('/admin/encadrement', { scope: 'team', scope_id: String(teamId), user_id: String(chief.id) });

  let itemId;

  await t.test('le stock découle des mouvements, jamais d\'une saisie', async () => {
    await admin.refreshToken('/stock');
    await admin.post('/stock/articles', { label: 'Gants de protection', unit: 'paire', stock_min: '10', unit_price: '4.50' });
    itemId = db.prepare("SELECT id FROM items WHERE label = 'Gants de protection'").get().id;
    assert.equal(inventory.itemById(itemId).stock, 0);

    await admin.refreshToken('/stock');
    await admin.post('/stock/mouvements', { item_id: String(itemId), kind: 'Entrée', quantity: '50' });
    assert.equal(inventory.itemById(itemId).stock, 50);

    await admin.refreshToken('/stock');
    await admin.post('/stock/mouvements', { item_id: String(itemId), kind: 'Sortie', quantity: '12' });
    assert.equal(inventory.itemById(itemId).stock, 38);
  });

  await t.test('refuse une sortie supérieure au stock', async () => {
    await admin.refreshToken('/stock');
    await admin.post('/stock/mouvements', { item_id: String(itemId), kind: 'Sortie', quantity: '999' });
    assert.match((await admin.flash('/stock')).message, /Stock insuffisant : il reste 38/);
    assert.equal(inventory.itemById(itemId).stock, 38);
  });

  await t.test("un inventaire repose le compteur", async () => {
    await admin.refreshToken('/stock');
    await admin.post('/stock/mouvements', { item_id: String(itemId), kind: 'Inventaire', quantity: '30', reason: 'Comptage annuel' });
    assert.equal(inventory.itemById(itemId).stock, 30);

    await admin.refreshToken('/stock');
    await admin.post('/stock/mouvements', { item_id: String(itemId), kind: 'Entrée', quantity: '5' });
    assert.equal(inventory.itemById(itemId).stock, 35);
  });

  await t.test("signale un article passé sous son seuil", async () => {
    await admin.refreshToken('/stock');
    await admin.post('/stock/mouvements', { item_id: String(itemId), kind: 'Sortie', quantity: '30' });
    const item = inventory.itemById(itemId);
    assert.equal(item.stock, 5);
    assert.equal(item.below, true);
  });

  await t.test("une petite demande s'arrête au manager, une grosse passe à la gestion", async () => {
    await worker.client.refreshToken('/stock');
    await worker.client.post('/stock/demandes', { label: 'Visseuse', quantity: '1', estimated_amount: '120' });
    await worker.client.refreshToken('/stock');
    await worker.client.post('/stock/demandes', { label: 'Établi', quantity: '1', estimated_amount: '1500' });

    const [small, big] = db.prepare('SELECT * FROM purchase_requests ORDER BY id').all();
    assert.equal(small.status, 'Manager');
    assert.equal(big.status, 'Manager');

    await chief.client.refreshToken('/stock');
    await chief.client.post(`/stock/demandes/${small.id}/manager`, { decision: 'approuver' });
    // Sous le seuil, l'accord du manager suffit.
    assert.equal(db.prepare('SELECT status FROM purchase_requests WHERE id = ?').get(small.id).status, 'Approuvée');

    await chief.client.refreshToken('/stock');
    await chief.client.post(`/stock/demandes/${big.id}/manager`, { decision: 'approuver' });
    assert.equal(db.prepare('SELECT status FROM purchase_requests WHERE id = ?').get(big.id).status, 'Gestion');

    await admin.refreshToken('/stock');
    await admin.post(`/stock/demandes/${big.id}/gestion`, { decision: 'approuver' });
    assert.equal(db.prepare('SELECT status FROM purchase_requests WHERE id = ?').get(big.id).status, 'Approuvée');
  });

  await t.test("un manager n'arbitre que les demandes de ses collaborateurs", async () => {
    const outsider = await makeMember(admin, 'ailleurs.stock@test.local', { first_name: 'Ali', last_name: 'Ailleurs' });
    await outsider.client.refreshToken('/stock');
    await outsider.client.post('/stock/demandes', { label: 'Clavier', quantity: '1', estimated_amount: '80' });
    const request = db.prepare("SELECT * FROM purchase_requests WHERE label = 'Clavier'").get();

    await chief.client.refreshToken('/stock');
    await chief.client.post(`/stock/demandes/${request.id}/manager`, { decision: 'approuver' });
    assert.match((await chief.client.flash('/stock')).message, /ne relève pas de vos collaborateurs/);
    assert.equal(db.prepare('SELECT status FROM purchase_requests WHERE id = ?').get(request.id).status, 'Manager');
  });

  await t.test("commander une demande liée à un article l'entre en stock", async () => {
    await worker.client.refreshToken('/stock');
    await worker.client.post('/stock/demandes', {
      label: 'Réassort gants', quantity: '20', estimated_amount: '90', item_id: String(itemId),
    });
    const request = db.prepare("SELECT * FROM purchase_requests WHERE label = 'Réassort gants'").get();

    await chief.client.refreshToken('/stock');
    await chief.client.post(`/stock/demandes/${request.id}/manager`, { decision: 'approuver' });

    const before = inventory.itemById(itemId).stock;
    await admin.refreshToken('/stock');
    await admin.post(`/stock/demandes/${request.id}/commander`, {});
    assert.equal(inventory.itemById(itemId).stock, before + 20);
    assert.equal(db.prepare('SELECT status FROM purchase_requests WHERE id = ?').get(request.id).status, 'Commandée');
  });

  await t.test('une facture fournisseur se rattache à son bon de commande, et le rapprochement compare', async () => {
    const purchasing = require('../src/purchasing');

    await admin.refreshToken('/gestion');
    await admin.post('/gestion/tiers', { name: 'Fournitures Nord', kind: 'Fournisseur' });
    const supplier = db.prepare("SELECT * FROM partners WHERE name = 'Fournitures Nord'").get();

    await admin.refreshToken('/stock');
    await admin.post('/stock/commandes', { partner_id: String(supplier.id), ordered_on: '2026-05-04' });
    const order = db.prepare('SELECT * FROM purchase_orders ORDER BY id DESC').get();

    await admin.refreshToken(`/stock/commandes/${order.id}`);
    await admin.post(`/stock/commandes/${order.id}/modifier`, {
      partner_id: String(supplier.id), ordered_on: '2026-05-04', status: 'Envoyée',
    });
    await admin.refreshToken(`/stock/commandes/${order.id}`);
    await admin.post(`/stock/commandes/${order.id}/lignes`, {
      label: 'Gants', quantity: '10', unit_price: '50', item_id: String(itemId),
    });
    const line = db.prepare('SELECT * FROM purchase_order_lines WHERE order_id = ? ORDER BY id DESC').get(order.id);

    // Reçu la moitié, facturé au-delà du commandé : les deux écarts se nomment.
    await admin.refreshToken(`/stock/commandes/${order.id}`);
    await admin.post(`/stock/lignes/${line.id}/reception`, { quantity: '5', received_on: '2026-05-10' });

    // La facture est créée depuis la gestion, rattachée à la commande dans le même geste.
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/factures', {
      direction: 'Fournisseur', partner_id: String(supplier.id), label: 'Fourniture de gants',
      issue_date: '2026-05-12', amount_ht: '600', vat_rate: '20', purchase_order_id: String(order.id),
    });
    const invoice = db.prepare("SELECT * FROM invoices WHERE label = 'Fourniture de gants'").get();
    assert.equal(invoice.purchase_order_id, order.id, 'la facture doit porter son bon de commande');

    const reconciliation = purchasing.match(purchasing.orderById(order.id));
    assert.equal(reconciliation.ordered, 500);
    assert.equal(reconciliation.received, 250);
    assert.equal(reconciliation.invoiced, 600);
    assert.deepEqual(reconciliation.issues.map((i) => i.kind), ['sur_commande', 'sur_reception']);
    assert.equal(purchasing.discrepancies().length, 1);

    // Détacher la facture retire l'écart.
    await admin.refreshToken(`/stock/commandes/${order.id}`);
    await admin.post(`/stock/factures/${invoice.id}/detacher`, {});
    assert.equal(db.prepare('SELECT purchase_order_id FROM invoices WHERE id = ?').get(invoice.id).purchase_order_id, null);
    assert.equal(purchasing.match(purchasing.orderById(order.id)).invoiced, 0);

    // Et la rattacher depuis la fiche de commande la remet dans le rapprochement.
    await admin.refreshToken(`/stock/commandes/${order.id}`);
    await admin.post(`/stock/commandes/${order.id}/factures`, { invoice_id: String(invoice.id) });
    assert.equal(purchasing.match(purchasing.orderById(order.id)).invoiced, 600);
  });

  await t.test("une facture client ne se rattache pas à un bon de commande", async () => {
    const purchasing = require('../src/purchasing');
    const order = db.prepare('SELECT * FROM purchase_orders ORDER BY id DESC').get();

    await admin.refreshToken('/gestion');
    await admin.post('/gestion/factures', {
      direction: 'Client', label: 'Prestation cliente', issue_date: '2026-05-12',
      amount_ht: '100', vat_rate: '20', purchase_order_id: String(order.id),
    });
    assert.match((await admin.flash('/gestion')).message, /facture fournisseur/);
    assert.equal(db.prepare("SELECT COUNT(*) AS n FROM invoices WHERE label = 'Prestation cliente'").get().n, 0);

    // Et une facture d'un autre fournisseur non plus.
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/tiers', { name: 'Autre fournisseur', kind: 'Fournisseur' });
    const other = db.prepare("SELECT * FROM partners WHERE name = 'Autre fournisseur'").get();
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/factures', {
      direction: 'Fournisseur', partner_id: String(other.id), label: 'Ailleurs',
      issue_date: '2026-05-12', amount_ht: '100', vat_rate: '20',
    });
    const stranger = db.prepare("SELECT * FROM invoices WHERE label = 'Ailleurs'").get();

    await admin.refreshToken(`/stock/commandes/${order.id}`);
    await admin.post(`/stock/commandes/${order.id}/factures`, { invoice_id: String(stranger.id) });
    assert.match((await admin.flash('/stock')).message, /autre fournisseur/);
    assert.equal(purchasing.orderById(order.id) && db.prepare('SELECT purchase_order_id FROM invoices WHERE id = ?').get(stranger.id).purchase_order_id, null);
  });

  await t.test("le personnel ne crée pas d'article ni de mouvement", async () => {
    const res = await worker.client.post('/stock/articles', { label: 'Pirate' });
    assert.equal(res.status, 403);
    assert.equal(db.prepare("SELECT COUNT(*) AS n FROM items WHERE label = 'Pirate'").get().n, 0);
  });

  await t.test("un devis accepté devient une facture, une seule fois", async () => {
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/tiers', { name: 'Client CRM', kind: 'Client' });
    const partner = db.prepare("SELECT * FROM partners WHERE name = 'Client CRM'").get();

    await admin.refreshToken('/crm');
    await admin.post('/crm/devis', {
      partner_id: String(partner.id), label: 'Refonte du site', reference: 'DV-001',
      issue_date: '2026-05-02', amount_ht: '4000', vat_rate: '20',
    });
    const quote = db.prepare("SELECT * FROM quotes WHERE reference = 'DV-001'").get();

    // Un devis en brouillon ne se facture pas.
    await admin.refreshToken('/crm');
    await admin.post(`/crm/devis/${quote.id}/facturer`, {});
    assert.match((await admin.flash('/crm')).message, /devis accepté/);

    await admin.refreshToken('/crm');
    await admin.post(`/crm/devis/${quote.id}/statut`, { status: 'Accepté' });
    await admin.refreshToken('/crm');
    await admin.post(`/crm/devis/${quote.id}/facturer`, {});

    const invoiced = db.prepare('SELECT * FROM quotes WHERE id = ?').get(quote.id);
    assert.ok(invoiced.invoice_id, 'le devis doit porter sa facture');
    const invoice = db.prepare('SELECT * FROM invoices WHERE id = ?').get(invoiced.invoice_id);
    assert.equal(invoice.amount_ht, 4000);
    assert.equal(invoice.direction, 'Client');

    await admin.refreshToken('/crm');
    await admin.post(`/crm/devis/${quote.id}/facturer`, {});
    assert.match((await admin.flash('/crm')).message, /déjà été facturé/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM invoices WHERE label = ?').get('Refonte du site').n, 1);
  });

  await t.test('le pipeline pondère les affaires par leur probabilité', async () => {
    const partner = db.prepare("SELECT * FROM partners WHERE name = 'Client CRM'").get();

    await admin.refreshToken('/crm');
    await admin.post('/crm/opportunites', {
      partner_id: String(partner.id), title: 'Maintenance annuelle', amount: '10000', probability: '40',
    });
    await admin.refreshToken('/crm');
    await admin.post('/crm/opportunites', {
      partner_id: String(partner.id), title: 'Extension', amount: '5000', probability: '80',
    });

    const pipeline = crm.pipeline();
    assert.equal(pipeline.total, 15000);
    assert.equal(pipeline.weighted, 8000);
  });

  await t.test("gagner une affaire la ferme à 100 %", async () => {
    const opportunity = db.prepare("SELECT * FROM opportunities WHERE title = 'Extension'").get();
    await admin.refreshToken('/crm');
    await admin.post(`/crm/opportunites/${opportunity.id}/etape`, { stage: 'Gagnée' });

    const won = db.prepare('SELECT * FROM opportunities WHERE id = ?').get(opportunity.id);
    assert.equal(won.probability, 100);
    assert.ok(won.closed_at, 'une affaire gagnée est datée');
    assert.equal(crm.pipeline().won, 5000);
  });

  await t.test('signale une relance en retard', async () => {
    const partner = db.prepare("SELECT * FROM partners WHERE name = 'Client CRM'").get();
    await admin.refreshToken('/crm');
    await admin.post('/crm/relances', { partner_id: String(partner.id), kind: 'Appel', due_on: '2020-01-15', note: 'Rappeler' });

    const activity = crm.activities({ pendingOnly: true }).find((a) => a.note === 'Rappeler');
    assert.equal(activity.overdue, true);

    await admin.refreshToken('/crm');
    await admin.post(`/crm/relances/${activity.id}/faite`, {});
    assert.equal(crm.activities().find((a) => a.id === activity.id).overdue, false);
  });
});
