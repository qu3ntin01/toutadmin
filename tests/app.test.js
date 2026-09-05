const test = require('node:test');
const assert = require('node:assert/strict');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

// L'environnement doit être préparé avant de charger la base (ouverte au require).
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

function newClient() {
  return new Client(baseUrl);
}

async function loginAsAdmin() {
  const admin = newClient();
  const res = await admin.login(ADMIN_EMAIL, ADMIN_PASSWORD);
  assert.equal(res.headers.get('location'), '/admin');
  return admin;
}

// Crée un membre et récupère le mot de passe temporaire affiché une seule fois à l'admin.
async function createEmployee(admin, fields) {
  const res = await admin.post('/admin/employes', {
    first_name: 'Test',
    last_name: 'Membre',
    grade: 'Employé',
    contract_type: 'CDI',
    ...fields,
  });
  assert.equal(res.status, 302);

  const flash = await admin.flash('/admin');
  const password = flash && flash.message.match(/Mot de passe temporaire : ([A-Za-z0-9]+)/);
  return { flash, password: password ? password[1] : null };
}

function userByEmail(email) {
  return db.prepare('SELECT * FROM users WHERE email = ?').get(email);
}

test('authentification', async (t) => {
  await t.test('redirige un visiteur anonyme vers la connexion', async () => {
    const res = await newClient().get('/');
    assert.equal(res.status, 302);
    assert.equal(res.headers.get('location'), '/connexion');
  });

  await t.test('refuse un mot de passe incorrect sans révéler la cause', async () => {
    const client = newClient();
    await client.login(ADMIN_EMAIL, 'mauvais-mot-de-passe');
    const flash = await client.flash('/connexion');
    assert.equal(flash.message, 'Identifiants incorrects.');
  });

  await t.test('renvoie le même message pour un compte inexistant', async () => {
    const client = newClient();
    await client.login('inconnu@test.local', 'peu-importe');
    const flash = await client.flash('/connexion');
    assert.equal(flash.message, 'Identifiants incorrects.');
  });

  await t.test('accepte les bons identifiants et ouvre une session', async () => {
    const admin = await loginAsAdmin();
    const res = await admin.get('/admin');
    assert.equal(res.status, 200);
  });

  await t.test('rejette un POST sans jeton CSRF (403)', async () => {
    const admin = await loginAsAdmin();
    const res = await admin.post('/admin/employes', { first_name: 'Sans', last_name: 'Jeton' }, { withToken: false });
    assert.equal(res.status, 403);
  });

  await t.test('verrouille le compte après 5 échecs, même avec le bon mot de passe ensuite', async () => {
    const admin = await loginAsAdmin();
    const email = 'verrou@test.local';
    const { password } = await createEmployee(admin, { email, first_name: 'Vic', last_name: 'Verrou' });

    const victim = newClient();
    for (let i = 0; i < 5; i++) {
      await victim.login(email, `faux-${i}`);
    }

    assert.equal(userByEmail(email).failed_attempts, 5);
    assert.ok(userByEmail(email).locked_until, 'le compte doit porter une date de déverrouillage');

    await victim.firstAccess(email, password);
    const flash = await victim.flash('/connexion');
    assert.match(flash.message, /verrouillé/);
  });
});

test('contrôle des accès', async (t) => {
  const admin = await loginAsAdmin();
  const email = 'acces@test.local';
  const { password } = await createEmployee(admin, { email, first_name: 'Alex', last_name: 'Acces' });

  const employee = newClient();
  await employee.firstAccess(email, password);

  await t.test("un employé n'atteint pas l'espace admin", async () => {
    const res = await employee.get('/admin');
    assert.equal(res.status, 403);
  });

  await t.test("un employé non-RH n'atteint pas l'espace RH", async () => {
    const res = await employee.get('/rh');
    assert.equal(res.status, 403);
  });

  await t.test("l'administrateur accède à l'espace RH pour supervision", async () => {
    const res = await admin.get('/rh');
    assert.equal(res.status, 200);
  });

  await t.test('un employé nommé RH accède à son espace RH', async () => {
    await admin.post('/admin/rh/nommer', { employee_id: String(userByEmail(email).id) });
    assert.equal(userByEmail(email).is_hr, 1);

    // Les droits sont lus à la connexion : il faut se reconnecter pour les recevoir.
    const hrUser = newClient();
    await hrUser.firstAccess(email, password);
    const res = await hrUser.get('/rh');
    assert.equal(res.status, 200);
  });

  await t.test("un visiteur anonyme est renvoyé vers la connexion, pas vers un 403", async () => {
    const res = await newClient().get('/admin');
    assert.equal(res.status, 302);
    assert.equal(res.headers.get('location'), '/connexion');
  });
});

test('création de membres', async (t) => {
  const admin = await loginAsAdmin();

  await t.test('un CDI démarre avec 25 jours de congés, un freelance avec 0', async () => {
    await createEmployee(admin, { email: 'cdi@test.local', first_name: 'Cathy', last_name: 'Cdi' });
    await createEmployee(admin, { email: 'free@test.local', first_name: 'Fred', last_name: 'Free', contract_type: 'Freelance', daily_rate: '450' });

    assert.equal(userByEmail('cdi@test.local').leave_balance, 25);
    assert.equal(userByEmail('free@test.local').leave_balance, 0);
    assert.equal(userByEmail('free@test.local').daily_rate, 450);
  });

  await t.test('refuse un grade hors liste blanche (contournement du select)', async () => {
    await admin.post('/admin/employes', {
      first_name: 'Hack', last_name: 'Grade', email: 'grade@test.local',
      grade: 'SuperAdmin', contract_type: 'CDI',
    });
    assert.equal((await admin.flash('/admin')).message, 'Grade invalide.');
    assert.equal(userByEmail('grade@test.local'), undefined);
  });

  await t.test('refuse un type de contrat hors liste blanche', async () => {
    await admin.post('/admin/employes', {
      first_name: 'Hack', last_name: 'Contrat', email: 'contrat@test.local',
      grade: 'Employé', contract_type: 'Esclavage',
    });
    assert.equal((await admin.flash('/admin')).message, 'Type de contrat invalide.');
    assert.equal(userByEmail('contrat@test.local'), undefined);
  });

  await t.test('refuse un email invalide puis un doublon', async () => {
    await admin.post('/admin/employes', {
      first_name: 'Mail', last_name: 'Invalide', email: 'pas-un-email',
      grade: 'Employé', contract_type: 'CDI',
    });
    assert.equal((await admin.flash('/admin')).message, 'Adresse email invalide.');

    await admin.post('/admin/employes', {
      first_name: 'Doublon', last_name: 'Cdi', email: 'cdi@test.local',
      grade: 'Employé', contract_type: 'CDI',
    });
    assert.equal((await admin.flash('/admin')).message, 'Un compte existe déjà avec cet email.');
  });
});

test('fin de contrat : désactivation automatique', async (t) => {
  const admin = await loginAsAdmin();
  const email = 'expire@test.local';
  const { password } = await createEmployee(admin, {
    email, first_name: 'Elsa', last_name: 'Expire',
    contract_type: 'CDD', contract_end_date: '2020-01-01',
  });

  await t.test('le balayage désactive le compte échu', async () => {
    await admin.get('/admin'); // le tableau de bord déclenche le balayage
    assert.equal(userByEmail(email).active, 0);
  });

  await t.test('la connexion est refusée, et oriente vers le coffre-fort', async () => {
    const expired = newClient();
    await expired.login(email, password);
    const flash = await expired.flash('/connexion');
    // Le compte est fermé et son coffre est vide : rien à ouvrir, mais on dit où aller.
    assert.match(flash.message, /compte est fermé/);
    assert.match(flash.message, /code d'accès/);
    assert.equal((await expired.get('/mon-espace')).headers.get('location'), '/connexion');
  });

  await t.test('un mot de passe faux sur un compte fermé ne dit pas qu\'il est fermé', async () => {
    const intrus = newClient();
    await intrus.login(email, 'ce-n-est-pas-le-bon');
    assert.match((await intrus.flash('/connexion')).message, /Identifiants incorrects/);
  });
});

test('outils et affectations', async (t) => {
  const admin = await loginAsAdmin();
  const email = 'outils@test.local';
  const { password } = await createEmployee(admin, { email, first_name: 'Théo', last_name: 'Outils' });

  await t.test('refuse une URL de connexion malformée', async () => {
    await admin.post('/admin/outils', { name: 'Outil douteux', login_url: 'pas-une-url' });
    assert.match((await admin.flash('/admin')).message, /URL de connexion invalide/);
  });

  await t.test("l'employé voit l'identifiant et l'URL de l'outil qui lui est affecté", async () => {
    await admin.post('/admin/outils', { name: 'Slack', category: 'Communication', login_url: 'https://exemple.slack.com' });
    const tool = db.prepare('SELECT * FROM tools WHERE name = ?').get('Slack');
    const employeeId = userByEmail(email).id;

    await admin.post('/admin/affectations', {
      employee_id: String(employeeId), tool_id: String(tool.id), username: 'theo.outils',
    });

    const employee = newClient();
    await employee.firstAccess(email, password);
    const { body } = await employee.html('/mon-espace');
    assert.match(body, /theo\.outils/);
    assert.match(body, /https:\/\/exemple\.slack\.com/);
  });

  await t.test('refuse une double affectation du même outil au même membre', async () => {
    const tool = db.prepare('SELECT * FROM tools WHERE name = ?').get('Slack');
    const employeeId = userByEmail(email).id;
    await admin.post('/admin/affectations', { employee_id: String(employeeId), tool_id: String(tool.id) });
    assert.match((await admin.flash('/admin')).message, /déjà affecté/);
  });
});

test('pointage des freelances', async (t) => {
  const admin = await loginAsAdmin();
  const email = 'pointage@test.local';
  const { password } = await createEmployee(admin, {
    email, first_name: 'Paul', last_name: 'Pointage', contract_type: 'Freelance', daily_rate: '480',
  });

  const freelance = newClient();
  await freelance.firstAccess(email, password);
  const employeeId = userByEmail(email).id;

  const openEntries = () =>
    db.prepare('SELECT * FROM time_entries WHERE employee_id = ? AND clock_out IS NULL').all(employeeId);

  await t.test('démarre un pointage', async () => {
    await freelance.post('/mon-espace/pointage/commencer');
    assert.equal(openEntries().length, 1);
  });

  await t.test('refuse un second pointage simultané', async () => {
    await freelance.post('/mon-espace/pointage/commencer');
    assert.equal((await freelance.flash('/mon-espace')).message, 'Un pointage est déjà en cours.');
    assert.equal(openEntries().length, 1, 'aucune entrée en double ne doit être créée');
  });

  await t.test('clôture le pointage et calcule le taux horaire sur base 8 h', async () => {
    await freelance.post('/mon-espace/pointage/terminer');
    assert.equal(openEntries().length, 0);

    const timesheet = require('../src/timesheet');
    assert.equal(timesheet.getStats(employeeId, 480).hourlyRate, 60);
  });

  await t.test("un salarié non-freelance ne peut pas pointer", async () => {
    const cdiEmail = 'nonfree@test.local';
    const { password: cdiPassword } = await createEmployee(admin, { email: cdiEmail, first_name: 'Nina', last_name: 'Cdi' });
    const cdi = newClient();
    await cdi.firstAccess(cdiEmail, cdiPassword);

    await cdi.post('/mon-espace/pointage/commencer');
    const entries = db.prepare('SELECT * FROM time_entries WHERE employee_id = ?').all(userByEmail(cdiEmail).id);
    assert.equal(entries.length, 0);
  });
});

test('cycle RH complet : demande, approbation, solde', async (t) => {
  const admin = await loginAsAdmin();

  const staffEmail = 'salarie@test.local';
  const { password: staffPassword } = await createEmployee(admin, {
    email: staffEmail, first_name: 'Sam', last_name: 'Salarie',
  });
  const staffId = userByEmail(staffEmail).id;

  const hrEmail = 'drh@test.local';
  const { password: hrPassword } = await createEmployee(admin, { email: hrEmail, first_name: 'Rita', last_name: 'Drh' });
  await admin.post('/admin/rh/nommer', { employee_id: String(userByEmail(hrEmail).id) });

  const staff = newClient();
  await staff.firstAccess(staffEmail, staffPassword);
  const rh = newClient();
  await rh.firstAccess(hrEmail, hrPassword);

  const lastRequest = () =>
    db.prepare('SELECT * FROM hr_requests WHERE employee_id = ? ORDER BY id DESC LIMIT 1').get(staffId);
  const balance = () => userByEmail(staffEmail).leave_balance;

  await t.test('compte les jours ouvrés du lundi au vendredi', async () => {
    await staff.post('/mon-espace/demandes', {
      type: 'Congés payés', start_date: '2026-09-07', end_date: '2026-09-11', reason: 'Vacances',
    });
    assert.equal(lastRequest().days, 5);
    assert.equal(lastRequest().status, 'En attente');
  });

  await t.test('exclut samedi et dimanche sur une période à cheval', async () => {
    await staff.post('/mon-espace/demandes', {
      type: 'RTT', start_date: '2026-09-07', end_date: '2026-09-14',
    });
    assert.equal(lastRequest().days, 6, 'lundi→lundi suivant = 6 jours ouvrés');
    await staff.post(`/mon-espace/demandes/${lastRequest().id}/annuler`);
  });

  await t.test('refuse une date de fin antérieure à la date de début', async () => {
    const before = db.prepare('SELECT COUNT(*) AS n FROM hr_requests').get().n;
    await staff.post('/mon-espace/demandes', {
      type: 'Congés payés', start_date: '2026-09-20', end_date: '2026-09-18',
    });
    assert.match((await staff.flash('/mon-espace')).message, /période sélectionnée est invalide/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM hr_requests').get().n, before);
  });

  await t.test("l'approbation décompte le solde de congés", async () => {
    const request = db.prepare("SELECT * FROM hr_requests WHERE employee_id = ? AND status = 'En attente'").get(staffId);
    assert.equal(balance(), 25);

    await rh.post(`/rh/demandes/${request.id}/approuver`);
    assert.equal(db.prepare('SELECT status FROM hr_requests WHERE id = ?').get(request.id).status, 'Approuvée');
    assert.equal(balance(), 20);
  });

  await t.test("l'annulation d'une demande approuvée recrédite le solde", async () => {
    const request = db.prepare("SELECT * FROM hr_requests WHERE employee_id = ? AND status = 'Approuvée'").get(staffId);
    await rh.post(`/rh/demandes/${request.id}/annuler`, { note: 'Erreur de saisie' });

    assert.equal(db.prepare('SELECT status FROM hr_requests WHERE id = ?').get(request.id).status, 'Annulée');
    assert.equal(balance(), 25);
  });

  await t.test('un refus conserve le motif et ne touche pas au solde', async () => {
    await staff.post('/mon-espace/demandes', {
      type: 'Congés payés', start_date: '2026-10-05', end_date: '2026-10-06',
    });
    const request = lastRequest();

    await rh.post(`/rh/demandes/${request.id}/refuser`, { note: 'Effectif insuffisant' });
    const refreshed = db.prepare('SELECT * FROM hr_requests WHERE id = ?').get(request.id);

    assert.equal(refreshed.status, 'Refusée');
    assert.equal(refreshed.review_note, 'Effectif insuffisant');
    assert.equal(balance(), 25);
  });

  await t.test('une demande déjà traitée ne peut plus être approuvée', async () => {
    const request = db.prepare("SELECT * FROM hr_requests WHERE status = 'Refusée' ORDER BY id DESC LIMIT 1").get();
    await rh.post(`/rh/demandes/${request.id}/approuver`);
    assert.match((await rh.flash('/rh')).message, /n'est plus en attente/);
    assert.equal(balance(), 25);
  });

  await t.test('un ajustement manuel crédite le solde et laisse une trace', async () => {
    await rh.post(`/rh/solde/${staffId}/ajuster`, { amount: '2.5', reason: 'Report N-1' });
    assert.equal(balance(), 27.5);

    const adjustment = db.prepare('SELECT * FROM leave_adjustments WHERE employee_id = ? ORDER BY id DESC LIMIT 1').get(staffId);
    assert.equal(adjustment.amount, 2.5);
    assert.equal(adjustment.reason, 'Report N-1');
  });

  await t.test('un ajustement nul ou démesuré est refusé', async () => {
    await rh.post(`/rh/solde/${staffId}/ajuster`, { amount: '0' });
    assert.equal((await rh.flash('/rh')).message, 'Ajustement invalide.');

    await rh.post(`/rh/solde/${staffId}/ajuster`, { amount: '999' });
    assert.equal((await rh.flash('/rh')).message, 'Ajustement invalide.');
    assert.equal(balance(), 27.5);
  });

  await t.test('un freelance ne peut pas déposer de demande RH', async () => {
    const freelanceEmail = 'freerh@test.local';
    const { password } = await createEmployee(admin, {
      email: freelanceEmail, first_name: 'Flo', last_name: 'Free', contract_type: 'Freelance', daily_rate: '400',
    });
    const freelance = newClient();
    await freelance.firstAccess(freelanceEmail, password);

    await freelance.post('/mon-espace/demandes', {
      type: 'Congés payés', start_date: '2026-09-07', end_date: '2026-09-11',
    });

    const requests = db.prepare('SELECT * FROM hr_requests WHERE employee_id = ?').all(userByEmail(freelanceEmail).id);
    assert.equal(requests.length, 0);
  });
});

test('fiches de paie', async (t) => {
  const admin = await loginAsAdmin();
  const email = 'paie@test.local';
  const { password } = await createEmployee(admin, { email, first_name: 'Paola', last_name: 'Paie' });
  const employeeId = userByEmail(email).id;

  await t.test('refuse un net supérieur au brut', async () => {
    await admin.post('/rh/paie', {
      employee_id: String(employeeId), period: '2026-08', gross_amount: '2000', net_amount: '2500',
    });
    assert.match((await admin.flash('/rh')).message, /Montants invalides/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM payslips WHERE employee_id = ?').get(employeeId).n, 0);
  });

  await t.test('refuse une période au mauvais format', async () => {
    await admin.post('/rh/paie', {
      employee_id: String(employeeId), period: 'août 2026', gross_amount: '3200', net_amount: '2480',
    });
    assert.match((await admin.flash('/rh')).message, /Période invalide/);
  });

  await t.test("crée une fiche visible par le salarié, puis la marque payée", async () => {
    await admin.post('/rh/paie', {
      employee_id: String(employeeId), period: '2026-08', gross_amount: '3200', net_amount: '2480',
    });

    const payslip = db.prepare('SELECT * FROM payslips WHERE employee_id = ?').get(employeeId);
    assert.equal(payslip.status, 'À verser');

    const employee = newClient();
    await employee.firstAccess(email, password);
    const { body } = await employee.html('/mon-espace');
    assert.match(body, /2026-08/);
    assert.match(body, /2480\.00/);

    await admin.post(`/rh/paie/${payslip.id}/marquer-payee`);
    const paid = db.prepare('SELECT * FROM payslips WHERE id = ?').get(payslip.id);
    assert.equal(paid.status, 'Payée');
    assert.ok(paid.paid_at);
  });
});
