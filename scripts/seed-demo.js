#!/usr/bin/env node
/**
 * Jeu de données de démonstration : de quoi parcourir tous les espaces immédiatement.
 * Usage : node scripts/seed-demo.js [--force]
 * Refuse de s'exécuter si des membres existent déjà (sauf --force) et jamais en production.
 */
require('dotenv').config();

const bcrypt = require('bcryptjs');
const db = require('../src/db');

if (process.env.NODE_ENV === 'production') {
  console.error('Refus : ce script de démonstration ne doit pas tourner en production.');
  process.exit(1);
}

const force = process.argv.includes('--force');
const existing = db.prepare("SELECT COUNT(*) AS n FROM users WHERE role = 'employee'").get().n;

if (existing > 0 && !force) {
  console.error(`Refus : ${existing} membre(s) déjà en base. Relancez avec --force pour ajouter quand même.`);
  process.exit(1);
}

const DEMO_PASSWORD = 'demo-1234';
const hash = bcrypt.hashSync(DEMO_PASSWORD, 12);

const insertUser = db.prepare(`
  INSERT INTO users (role, email, password_hash, first_name, last_name, grade,
                     contract_type, contract_end_date, daily_rate, leave_balance, is_hr,
                     bio, phone, mail_address, mail_imap_host, mail_imap_port, mail_smtp_host, mail_smtp_port, active)
  VALUES ('employee', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
`);

const mail = (address) => [address, 'imap.entreprise.com', 993, 'smtp.entreprise.com', 587];

const people = [
  { email: 'claire.moreau@entreprise.com', first: 'Claire', last: 'Moreau', grade: 'Manager', dept: 'Ressources humaines', contract: 'CDI', end: null, rate: null, leave: 25, hr: 1,
    bio: "Responsable RH. Je suis vos dossiers du recrutement à la paie.", phone: '+33 6 12 34 56 78' },
  { email: 'marc.leroy@entreprise.com', first: 'Marc', last: 'Leroy', grade: 'Technicien confirmé', dept: 'Maintenance', contract: 'CDI', end: null, rate: null, leave: 25, hr: 0,
    bio: "Maintenance des équipements de production, astreinte une semaine sur quatre.", phone: '+33 6 22 33 44 55' },
  { email: 'sofia.nadir@entreprise.com', first: 'Sofia', last: 'Nadir', grade: "Chef d'équipe", dept: 'Production', contract: 'CDD', end: '2027-03-31', rate: null, leave: 18.5, hr: 0,
    bio: "J'encadre l'équipe de production sur la ligne 2.", phone: '+33 6 33 44 55 66' },
  { email: 'lucas.petit@entreprise.com', first: 'Lucas', last: 'Petit', grade: 'Employé', dept: 'Design', contract: 'Freelance', end: null, rate: 480, leave: 0, hr: 0,
    bio: 'Designer produit indépendant, missions au forfait.', phone: '' },
  { email: 'ines.garnier@entreprise.com', first: 'Inès', last: 'Garnier', grade: 'Stagiaire', dept: 'Marketing', contract: 'Stage', end: '2026-12-15', rate: null, leave: 8, hr: 0,
    bio: 'Stage de fin d’études en communication interne.', phone: '' },
];

const ids = {};
for (const p of people) {
  const info = insertUser.run(
    p.email, hash, p.first, p.last, p.grade, p.contract, p.end, p.rate, p.leave, p.hr,
    p.bio, p.phone, ...mail(p.email)
  );
  ids[p.email] = info.lastInsertRowid;
}

// Le compte d'administration n'est pas de cette liste : il est posé à la
// création de la base. Il encadre, signe et publie, donc il se nomme ici.
const adminId = db.prepare("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1").get().id;

/*
 * La société dans laquelle tout le jeu se range.
 *
 * Depuis le cloisonnement, chaque ligne d'une table d'entreprise dit à quelle
 * société elle appartient, et la porte des requêtes refuse celles qui ne le
 * disent pas. Le jeu de démonstration n'avait jamais été repris : il tombait à
 * la première insertion, et la démonstration avec lui. La première société est
 * garantie ici — c'est elle qu'une instance neuve crée —, tout le monde y est
 * rattaché, et le reste du script travaille dedans, y compris les modules
 * appelés plus bas, qui lisent le périmètre courant.
 */
const societes = require('../src/societes');
const ici = societes.garantirPremiere().id;

// ---------- Organisation : services, équipes et encadrement ----------

const insertDepartment = db.prepare('INSERT OR IGNORE INTO departments (company_id, name, description) VALUES (?, ?, ?)');
const departmentId = (name) => db.prepare('SELECT id FROM departments WHERE name = ? AND company_id = ?').get(name, ici).id;

for (const [name, description] of [
  ['Ressources humaines', 'Recrutement, paie et relations sociales.'],
  ['Production', 'Lignes de fabrication et qualité.'],
  ['Support technique', 'Maintenance des équipements et des outils.'],
  ['Création', 'Design produit et communication.'],
]) {
  insertDepartment.run(ici, name, description);
}

const insertTeam = db.prepare('INSERT INTO teams (company_id, name, department_id, description) VALUES (?, ?, ?, ?)');
const teamIds = {};
for (const [name, department, description] of [
  ['Ligne 2', 'Production', 'Équipe de production de la ligne 2.'],
  ['Maintenance', 'Support technique', 'Interventions et astreinte.'],
  ['Studio', 'Création', 'Design produit et supports de communication.'],
]) {
  teamIds[name] = insertTeam.run(ici, name, departmentId(department), description).lastInsertRowid;
}

const setMembership = db.prepare('UPDATE users SET department_id = ?, team_id = ? WHERE id = ?');
setMembership.run(departmentId('Ressources humaines'), null, ids['claire.moreau@entreprise.com']);
setMembership.run(departmentId('Support technique'), teamIds['Maintenance'], ids['marc.leroy@entreprise.com']);
setMembership.run(departmentId('Production'), teamIds['Ligne 2'], ids['sofia.nadir@entreprise.com']);
setMembership.run(departmentId('Création'), teamIds['Studio'], ids['lucas.petit@entreprise.com']);
setMembership.run(departmentId('Création'), teamIds['Studio'], ids['ines.garnier@entreprise.com']);

// Un service comme une équipe peuvent avoir plusieurs managers : la Création en a deux.
const insertManager = db.prepare('INSERT OR IGNORE INTO org_managers (company_id, scope, scope_id, user_id) VALUES (?, ?, ?, ?)');
const addManager = { run: (scope, scopeId, userId) => insertManager.run(ici, scope, scopeId, userId) };
addManager.run('team', teamIds['Ligne 2'], ids['sofia.nadir@entreprise.com']);
addManager.run('team', teamIds['Maintenance'], ids['marc.leroy@entreprise.com']);
addManager.run('department', departmentId('Création'), ids['claire.moreau@entreprise.com']);
addManager.run('department', departmentId('Création'), ids['sofia.nadir@entreprise.com']);
/*
 * Les Ressources humaines ont un responsable, elles aussi.
 *
 * Claire tient ce service, et son espace personnel est l'écran le plus
 * photographié du produit — c'est lui qui ouvre le site. Sans manager
 * rattaché à son service, la première carte de cet écran affichait « Aucun
 * manager rattaché » : la démonstration montrait un trou là où elle devait
 * montrer à quoi sert la carte. La direction encadre les RH, ce qui est au
 * demeurant l'organisation la plus ordinaire qui soit.
 */
addManager.run('department', departmentId('Ressources humaines'), adminId);

const insertTool = db.prepare(`
  INSERT INTO tools (company_id, name, category, reference, description, login_url, status)
  VALUES (?, ?, ?, ?, ?, ?, 'disponible')
`);

const tools = [
  ['Slack', 'Communication', 'SAAS-001', "Messagerie interne de l'entreprise", 'https://entreprise.slack.com'],
  ['Google Workspace', 'Bureautique', 'SAAS-002', 'Mail, agenda et documents', 'https://workspace.google.com'],
  ['Perceuse Makita DHP484', 'Électroportatif', 'MAT-114', 'Perceuse-visseuse 18 V', ''],
  ['Véhicule utilitaire', 'Flotte', 'VH-03', 'Renault Kangoo — plaque AB-123-CD', ''],
];

const toolIds = tools.map((t) => insertTool.run(ici, ...t).lastInsertRowid);

const insertAssignment = db.prepare('INSERT INTO assignments (company_id, employee_id, tool_id, note, username) VALUES (?, ?, ?, ?, ?)');
const assign = { run: (...values) => insertAssignment.run(ici, ...values) };
assign.run(ids['marc.leroy@entreprise.com'], toolIds[0], '', 'marc.leroy');
assign.run(ids['marc.leroy@entreprise.com'], toolIds[2], 'Chantier Nord', '');
assign.run(ids['claire.moreau@entreprise.com'], toolIds[1], '', 'c.moreau');
assign.run(ids['sofia.nadir@entreprise.com'], toolIds[3], 'Tournées hebdomadaires', '');
assign.run(ids['lucas.petit@entreprise.com'], toolIds[0], '', 'lucas.ext');

const insertRequestRow = db.prepare(`
  INSERT INTO hr_requests (company_id, employee_id, type, start_date, end_date, days, reason, status)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?)
`);
const insertRequest = { run: (...values) => insertRequestRow.run(ici, ...values) };
insertRequest.run(ids['marc.leroy@entreprise.com'], 'Congés payés', '2026-12-21', '2026-12-31', 8, "Congés de fin d'année", 'En attente');
insertRequest.run(ids['sofia.nadir@entreprise.com'], 'Télétravail', '2026-09-14', '2026-09-18', 5, 'Déménagement', 'En attente');
insertRequest.run(ids['ines.garnier@entreprise.com'], 'Absence maladie', '2026-09-02', '2026-09-03', 2, '', 'Approuvée');
/*
 * Et Claire en dépose, elle aussi.
 *
 * Le jeu de démonstration donnait des demandes et des bulletins à tout le
 * monde sauf à elle — or c'est son espace que le site met en avant. Trois
 * cartes y disaient « (0) » et « Aucune demande envoyée », « Aucune fiche de
 * paie disponible » : la seule personne dont on montre l'écran était la seule
 * à n'avoir rien à y voir. Une approuvée et une en attente, pour que les deux
 * états se lisent d'un coup d'œil.
 */
insertRequest.run(ids['claire.moreau@entreprise.com'], 'Congés payés', '2026-10-27', '2026-10-31', 5, "Vacances de la Toussaint", 'Approuvée');
insertRequest.run(ids['claire.moreau@entreprise.com'], 'Télétravail', '2026-11-03', '2026-11-07', 5, 'Semaine de clôture de paie', 'En attente');

const insertPayslipRow = db.prepare(`
  INSERT INTO payslips (company_id, employee_id, period, gross_amount, net_amount, status, note, created_by)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?)
`);
const insertPayslip = { run: (...values) => insertPayslipRow.run(ici, ...values) };
const hrId = ids['claire.moreau@entreprise.com'];
insertPayslip.run(ids['marc.leroy@entreprise.com'], '2026-08', 3200, 2480, 'Payée', '', hrId);
insertPayslip.run(ids['sofia.nadir@entreprise.com'], '2026-08', 2750, 2135, 'Payée', '', hrId);
insertPayslip.run(ids['marc.leroy@entreprise.com'], '2026-09', 3200, 2480, 'À verser', '', hrId);
insertPayslip.run(ids['ines.garnier@entreprise.com'], '2026-09', 1200, 1015, 'À verser', 'Gratification de stage', hrId);
// Celles de Claire, émises par la direction : on ne signe pas ses propres
// bulletins, et son espace doit en montrer.
insertPayslip.run(hrId, '2026-08', 3850, 2985, 'Payée', '', adminId);
insertPayslip.run(hrId, '2026-09', 3850, 2985, 'Payée', '', adminId);

// Quelques sessions de pointage pour le freelance, dont une encore ouverte.
const insertEntryRow = db.prepare('INSERT INTO time_entries (company_id, employee_id, clock_in, clock_out) VALUES (?, ?, ?, ?)');
const insertEntry = { run: (...values) => insertEntryRow.run(ici, ...values) };
const freelanceId = ids['lucas.petit@entreprise.com'];
const day = (offset, hour) => {
  const d = new Date();
  d.setUTCDate(d.getUTCDate() - offset);
  d.setUTCHours(hour, 0, 0, 0);
  return d.toISOString();
};
insertEntry.run(freelanceId, day(3, 9), day(3, 17));
insertEntry.run(freelanceId, day(2, 10), day(2, 16));
insertEntry.run(freelanceId, day(1, 9), day(1, 18));
insertEntry.run(freelanceId, new Date(Date.now() - 47 * 60 * 1000).toISOString(), null);

// Actualités : deux pour l'entreprise, une pour une équipe, une pour un service.
const insertNewsRow = db.prepare(`
  INSERT INTO announcements (company_id, author_id, scope, scope_id, title, body)
  VALUES (?, ?, ?, ?, ?, ?)
`);
const insertNews = { run: (...values) => insertNewsRow.run(ici, ...values) };
insertNews.run(adminId, 'company', null, 'Fermeture estivale du 3 au 17 août',
  "Les demandes de congés sur cette période sont à déposer avant le 30 juin.");
insertNews.run(adminId, 'company', null, 'Nouvelle mutuelle au 1er janvier',
  "Une réunion d'information est prévue le 12 décembre à 14 h en salle Atlas.");
insertNews.run(ids['sofia.nadir@entreprise.com'], 'team', teamIds['Ligne 2'],
  'Point hebdomadaire déplacé au jeudi', 'Le point de la ligne 2 passe au jeudi 9 h à compter de cette semaine.');
insertNews.run(ids['claire.moreau@entreprise.com'], 'department', departmentId('Création'),
  'Nouvelle charte graphique', 'La charte 2026 est disponible sur le partage du studio.');

// Agenda : un événement privé et un partagé avec l'équipe, pour montrer la différence.
const insertEvent = db.prepare(`
  INSERT INTO calendar_events (user_id, title, description, location, start_date, end_date, start_time, end_time, all_day, category, visibility)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
`);
const inDays = (n) => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10);
insertEvent.run(ids['sofia.nadir@entreprise.com'], 'Revue de production', 'Passage en revue des indicateurs de la ligne 2.',
  'Salle Atlas', inDays(3), inDays(3), '09:00', '10:30', 0, 'Réunion', 'Équipe');
insertEvent.run(ids['lucas.petit@entreprise.com'], 'Atelier maquettes', 'Session de travail sur les nouveaux gabarits.',
  'Studio', inDays(5), inDays(5), '14:00', '17:00', 0, 'Réunion', 'Service');
insertEvent.run(ids['ines.garnier@entreprise.com'], 'Rendez-vous personnel', '', '', inDays(2), inDays(2), '', '', 1, 'Personnel', 'Privé');

// ---------- Gestion : tiers, contrat, factures, budget, note de frais ----------

const insertPartner = db.prepare('INSERT INTO partners (company_id, kind, name, contact_name, email, phone) VALUES (?, ?, ?, ?, ?, ?)');
const partnerIds = {};
for (const [kind, name, contact, email, phone] of [
  ['Client', 'Groupe Bellefeuille', 'Marc Aubin', 'achats@bellefeuille.test', '+33 1 44 55 66 77'],
  ['Fournisseur', 'Papeterie du Nord', 'Léa Sorel', 'contact@papeterie-nord.test', '+33 3 20 11 22 33'],
  ['Fournisseur', 'Cloud Atlas Hosting', 'Support', 'facturation@cloudatlas.test', ''],
]) {
  partnerIds[name] = insertPartner.run(ici, kind, name, contact, email, phone).lastInsertRowid;
}

const inDaysISO = (n) => new Date(Date.now() + n * 86400000).toISOString().slice(0, 10);

db.prepare(`
  INSERT INTO partner_contracts (partner_id, reference, title, start_date, end_date, notice_days, amount, billing_period, status)
  VALUES (?, 'CT-2025-014', 'Hébergement et sauvegarde', ?, ?, 90, 4800, 'Annuel', 'Actif')
`).run(partnerIds['Cloud Atlas Hosting'], inDaysISO(-280), inDaysISO(85));

const insertInvoiceRow = db.prepare(`
  INSERT INTO invoices (company_id, direction, partner_id, department_id, reference, label, issue_date, due_date, amount_ht, vat_rate, status, created_by)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 20, ?, ?)
`);
const insertInvoice = { run: (...values) => insertInvoiceRow.run(ici, ...values) };
insertInvoice.run('Client', partnerIds['Groupe Bellefeuille'], null, 'FA-2026-031', 'Prestation de conception',
  inDaysISO(-40), inDaysISO(-10), 7500, 'Émise', adminId);
insertInvoice.run('Client', partnerIds['Groupe Bellefeuille'], null, 'FA-2026-028', 'Maintenance trimestrielle',
  inDaysISO(-95), inDaysISO(-65), 3200, 'Payée', adminId);
insertInvoice.run('Fournisseur', partnerIds['Papeterie du Nord'], departmentId('Support technique'), 'AC-8871',
  'Consommables atelier', inDaysISO(-25), inDaysISO(5), 640, 'Émise', adminId);

const currentYear = new Date().getUTCFullYear();
const insertBudgetRow = db.prepare('INSERT INTO budgets (company_id, department_id, year, amount) VALUES (?, ?, ?, ?)');
const insertBudget = { run: (...values) => insertBudgetRow.run(ici, ...values) };
insertBudget.run(departmentId('Support technique'), currentYear, 20000);
insertBudget.run(departmentId('Création'), currentYear, 12000);

db.prepare(`
  INSERT INTO expense_claims (company_id, employee_id, spent_on, category, description, amount, status)
  VALUES (?, ?, ?, 'Transport', 'Déplacement client Lille', 87.4, 'En attente')
`).run(ici, ids['marc.leroy@entreprise.com'], inDaysISO(-6));

/*
 * Un compte en banque, et quelques mouvements.
 *
 * Sans compte, l'écran de pilotage n'a pas de trésorerie à montrer, et la
 * démonstration — c'est elle que la vitrine photographie — laissait un tiret
 * là où une direction regarde d'abord : combien il y a en banque.
 */
const treasury = require('../src/treasury');
const compteCourant = treasury.createAccount({
  label: 'Compte courant', bank: 'Banque Populaire du Nord', ibanLast4: '0189', openingBalance: 48200,
});
for (const [jours, libelle, montant, categorie] of [
  [-28, 'Règlement FA-2026-028 — Groupe Bellefeuille', 3840, 'Encaissement client'],
  [-21, 'Loyer des locaux — octobre', -2650, 'Loyer'],
  [-14, 'Salaires de septembre', -11240, 'Salaires'],
  [-9, 'Hébergement et sauvegarde — Cloud Atlas', -400, 'Abonnement'],
  [-3, 'Acompte projet Bellefeuille', 4500, 'Encaissement client'],
]) {
  treasury.addTransaction({ accountId: compteCourant, valueDate: inDaysISO(jours), label: libelle, amount: montant, category: categorie });
}

// ---------- Parc matériel et salles ----------

const insertAsset = db.prepare(`
  INSERT INTO assets (company_id, name, category, reference, serial_number, purchase_date, warranty_end, value, status)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
`);
const assetIds = {};
for (const [name, category, reference, serial, value] of [
  ['MacBook Pro 14"', 'Informatique', 'IT-0142', 'C02XK1PQ', 2200],
  ['iPhone 14', 'Téléphonie', 'TEL-0088', 'F17GH9TT', 780],
  ['Perceuse à colonne', 'Outillage', 'OUT-0031', 'PC-77120', 1450],
]) {
  assetIds[name] = insertAsset.run(ici, name, category, reference, serial, inDaysISO(-400), inDaysISO(330), value, 'Disponible').lastInsertRowid;
}

const assignAsset = db.transaction((assetId, employeeId) => {
  db.prepare('INSERT INTO asset_assignments (asset_id, employee_id) VALUES (?, ?)').run(assetId, employeeId);
  db.prepare("UPDATE assets SET status = 'Affecté' WHERE id = ? AND company_id = ?").run(assetId, ici);
});
assignAsset(assetIds['MacBook Pro 14"'], ids['lucas.petit@entreprise.com']);
assignAsset(assetIds['Perceuse à colonne'], ids['marc.leroy@entreprise.com']);

const insertRoom = db.prepare('INSERT INTO rooms (company_id, name, location, capacity, equipment) VALUES (?, ?, ?, ?, ?)');
const roomIds = {};
for (const [name, location, capacity, equipment] of [
  ['Atlas', '2e étage', 12, 'Vidéoprojecteur, visio'],
  ['Vega', 'Rez-de-chaussée', 4, 'Écran'],
]) {
  roomIds[name] = insertRoom.run(ici, name, location, capacity, equipment).lastInsertRowid;
}

db.prepare('INSERT INTO room_bookings (room_id, user_id, title, booking_date, start_time, end_time) VALUES (?, ?, ?, ?, ?, ?)')
  .run(roomIds['Atlas'], ids['sofia.nadir@entreprise.com'], 'Revue de production', inDaysISO(3), '09:00', '10:30');

// ---------- Documents, formation, entretiens, recrutement ----------

const insertDocument = db.prepare(`
  INSERT INTO company_documents (company_id, title, category, description, requires_ack, created_by)
  VALUES (?, ?, ?, ?, ?, ?)
`);
insertDocument.run(ici, 'Règlement intérieur', 'Règlement intérieur', "Version en vigueur depuis janvier.", 1, adminId);
insertDocument.run(ici, 'Charte télétravail', 'Politique', 'Trois jours par semaine, sur accord du manager.', 1, adminId);
insertDocument.run(ici, 'Procédure de sécurité atelier', 'Sécurité', "Port des EPI obligatoire sur la ligne 2.", 0, adminId);

const insertTraining = db.prepare('INSERT INTO trainings (company_id, title, category, provider, duration_hours, cost) VALUES (?, ?, ?, ?, ?, ?)');
const trainingIds = {};
for (const [title, category, provider, hours, cost] of [
  ['Habilitation électrique B1V', 'Sécurité', 'Institut Prévention', 14, 620],
  ['Gestes et postures', 'Sécurité', 'Institut Prévention', 7, 280],
  ['Anglais professionnel', 'Langues', 'LinguaPro', 30, 1200],
]) {
  trainingIds[title] = insertTraining.run(ici, title, category, provider, hours, cost).lastInsertRowid;
}

const insertSession = db.prepare('INSERT INTO training_sessions (training_id, start_date, end_date, location, seats, status) VALUES (?, ?, ?, ?, ?, ?)');
const sessionId = insertSession.run(trainingIds['Habilitation électrique B1V'], inDaysISO(24), inDaysISO(25), 'Centre de formation', 8, 'Confirmée').lastInsertRowid;
insertSession.run(trainingIds['Gestes et postures'], inDaysISO(45), inDaysISO(45), 'Salle Atlas', 12, 'Planifiée');

db.prepare("INSERT INTO training_registrations (session_id, employee_id, status) VALUES (?, ?, 'Inscrite')")
  .run(sessionId, ids['marc.leroy@entreprise.com']);
db.prepare("INSERT INTO training_registrations (session_id, employee_id, status) VALUES (?, ?, 'Demandée')")
  .run(sessionId, ids['ines.garnier@entreprise.com']);

db.prepare(`
  INSERT INTO reviews (company_id, employee_id, reviewer_id, period, scheduled_on, status)
  VALUES (?, ?, ?, ?, ?, 'Planifié')
`).run(ici, ids['marc.leroy@entreprise.com'], ids['sofia.nadir@entreprise.com'], String(currentYear), inDaysISO(18));

const openingId = db.prepare(`
  INSERT INTO job_openings (company_id, title, department_id, contract_type, description, created_by)
  VALUES (?, ?, ?, 'CDI', ?, ?)
`).run(ici, 'Technicien de maintenance', departmentId('Support technique'),
  "Renfort sur les interventions et l'astreinte.", adminId).lastInsertRowid;

const insertCandidate = db.prepare('INSERT INTO candidates (opening_id, first_name, last_name, email, source, stage) VALUES (?, ?, ?, ?, ?, ?)');
insertCandidate.run(openingId, 'Nadia', 'Berger', 'nadia.berger@exemple.test', 'Cooptation', 'Entretien');
insertCandidate.run(openingId, 'Théo', 'Rimbaud', 'theo.rimbaud@exemple.test', 'Annonce', 'Présélection');

// Quelques messages internes, dont un non lu.
const insertMessage = db.prepare('INSERT INTO messages (sender_id, recipient_id, subject, body, read_at) VALUES (?, ?, ?, ?, ?)');
insertMessage.run(ids['claire.moreau@entreprise.com'], ids['marc.leroy@entreprise.com'],
  'Votre demande de congés', "Bonjour Marc,\n\nJ'ai bien reçu votre demande pour décembre, je reviens vers vous après validation du planning.\n\nClaire", null);
insertMessage.run(ids['sofia.nadir@entreprise.com'], ids['marc.leroy@entreprise.com'],
  'Astreinte de la semaine 12', "Peux-tu confirmer ta disponibilité pour l'astreinte ?", new Date().toISOString());
insertMessage.run(ids['marc.leroy@entreprise.com'], ids['sofia.nadir@entreprise.com'],
  'Re: Astreinte de la semaine 12', 'Confirmé de mon côté.', null);

// ---------- Projets : deux chantiers en cours, avec du temps passé ----------
/*
 * L'écran des projets fait partie de la visite guidée de la vitrine, dans les
 * seize langues. Sans projet, il y montrait « 0 projet » : la démonstration
 * parlait de rentabilité sur une page vide.
 */
const projects = require('../src/projects');
const projetRefonte = projects.create({
  code: 'PRJ-014', name: 'Refonte du site Bellefeuille', partnerId: partnerIds['Groupe Bellefeuille'],
  departmentId: departmentId('Création'), teamId: teamIds['Studio'], leadId: ids['lucas.petit@entreprise.com'],
  status: 'En cours', startDate: inDaysISO(-40), dueDate: inDaysISO(35), budgetAmount: 18000, hourlyRate: 85,
  description: 'Maquettes, intégration et mise en ligne du nouveau site vitrine du client.',
});
projects.addMember(projetRefonte, ids['ines.garnier@entreprise.com'], 'Intégration');
const jalonMaquettes = projects.createMilestone(projetRefonte, 'Maquettes validées', inDaysISO(-5));
projects.toggleMilestone(jalonMaquettes);
projects.createMilestone(projetRefonte, 'Mise en ligne', inDaysISO(35));
for (const [titre, qui, statut, estime] of [
  ['Maquettes des pages intérieures', 'lucas.petit@entreprise.com', 'Terminée', 24],
  ['Intégration de la page d’accueil', 'ines.garnier@entreprise.com', 'En cours', 16],
  ['Formulaire de contact et suivi', 'ines.garnier@entreprise.com', 'À faire', 8],
]) {
  projects.createTask({ projectId: projetRefonte, title: titre, assigneeId: ids[qui], status: statut, estimateHours: estime, createdBy: ids['lucas.petit@entreprise.com'] });
}
for (const [jours, qui, heures, note] of [
  [-12, 'lucas.petit@entreprise.com', 7, 'Maquettes'], [-11, 'lucas.petit@entreprise.com', 6.5, 'Maquettes'],
  [-4, 'ines.garnier@entreprise.com', 5, 'Intégration'], [-2, 'ines.garnier@entreprise.com', 6, 'Intégration'],
]) {
  projects.logTime({ projectId: projetRefonte, userId: ids[qui], spentOn: inDaysISO(jours), hours: heures, note });
}

const projetLigne = projects.create({
  code: 'PRJ-009', name: 'Modernisation de la ligne 2', departmentId: departmentId('Production'), teamId: teamIds['Ligne 2'],
  leadId: ids['sofia.nadir@entreprise.com'], status: 'En cours', startDate: inDaysISO(-90), dueDate: inDaysISO(60),
  budgetAmount: 42000, description: 'Remplacement des postes de supervision et des capteurs de la ligne 2.',
});
projects.addMember(projetLigne, ids['marc.leroy@entreprise.com'], 'Maintenance');
projects.createTask({ projectId: projetLigne, title: 'Remplacer le poste de supervision', assigneeId: ids['marc.leroy@entreprise.com'], status: 'En cours', estimateHours: 12, createdBy: ids['sofia.nadir@entreprise.com'] });
projects.logTime({ projectId: projetLigne, userId: ids['marc.leroy@entreprise.com'], spentOn: inDaysISO(-6), hours: 4, note: 'Diagnostic', billable: false });

// ---------- Support : groupes, files, demandes et connaissances ----------
/*
 * Le support était le seul espace que la démonstration laissait vide.
 *
 * C'est aussi celui que le site vitrine photographie pour parler du sujet :
 * une capture d'un écran sans une seule demande ne montre pas un produit, elle
 * montre un tableau vide. Et depuis que l'administration compose ses propres
 * groupes, l'écran « qui a accès à quoi » ne veut rien dire tant qu'aucun
 * groupe n'existe.
 *
 * Deux groupes suffisent à dire l'essentiel, parce qu'ils ne se recouvrent
 * pas : celui qui répond aux pannes et aux demandes clientes, et celui qui
 * traite le personnel. Une demande RH déposée ici part dans le second, et le
 * premier ne la voit jamais — c'est la garantie que le produit promet, rendue
 * visible sur l'écran plutôt qu'écrite dans une page.
 */
const support = require('../src/support');

const groupeIT = support.createGroup({
  name: 'Assistance informatique',
  description: 'Pannes, comptes, matériel, et les demandes des clients.',
});
support.setGroupCategories(groupeIT, ['Informatique', 'Client', 'Autre']);
support.addGroupMember(groupeIT, ids['marc.leroy@entreprise.com']);
support.addGroupMember(groupeIT, ids['sofia.nadir@entreprise.com']);

const groupePersonnel = support.createGroup({
  name: 'Administration du personnel',
  description: 'Contrats, paie, attestations. Cette file ne sort pas des RH.',
});
support.setGroupCategories(groupePersonnel, ['Ressources humaines']);
support.addGroupMember(groupePersonnel, ids['claire.moreau@entreprise.com']);

const ticketPanne = support.create({
  subject: 'Écran de la ligne 2 noir au démarrage',
  body: "Le poste de supervision de la ligne 2 démarre mais l'écran reste noir. La production tourne, on pilote depuis la tablette en attendant.",
  category: 'Informatique', priority: 'Haute', origin: 'Interne',
  requesterId: ids['sofia.nadir@entreprise.com'],
});
support.assign(ticketPanne, ids['marc.leroy@entreprise.com']);
support.setStatus(ticketPanne, 'En cours');
support.reply({ ticketId: ticketPanne, authorId: ids['marc.leroy@entreprise.com'],
  body: "Je passe en début d'après-midi avec un écran de rechange. Si c'est la carte graphique, il faudra commander." });
support.reply({ ticketId: ticketPanne, authorId: ids['marc.leroy@entreprise.com'], internal: true,
  body: 'Troisième panne sur ce poste en six mois. À remplacer plutôt qu’à réparer, je monte une demande d’investissement.' });

const ticketAttestation = support.create({
  subject: "Attestation d'emploi pour un dossier de location",
  body: "Mon agence demande une attestation d'employeur avec l'ancienneté et le salaire. Est-ce que je peux l'avoir cette semaine ?",
  category: 'Ressources humaines', priority: 'Normale', origin: 'Interne',
  requesterId: ids['marc.leroy@entreprise.com'],
});
support.assign(ticketAttestation, ids['claire.moreau@entreprise.com']);
support.reply({ ticketId: ticketAttestation, authorId: ids['claire.moreau@entreprise.com'],
  body: 'Je la prépare et vous la dépose signée dans votre coffre-fort avant vendredi.' });
support.setStatus(ticketAttestation, 'En attente');

support.create({
  subject: "Badge d'accès à refaire",
  body: 'Mon badge ne passe plus à la porte du bâtiment B depuis lundi.',
  category: 'Moyens généraux', priority: 'Basse', origin: 'Interne',
  requesterId: ids['ines.garnier@entreprise.com'],
});

const ticketClient = support.create({
  subject: 'Export de commandes incomplet sur le mois de septembre',
  body: "Notre export mensuel s'arrête au 18 septembre. Les commandes suivantes sont bien dans votre interface mais absentes du fichier.",
  category: 'Client', priority: 'Critique', origin: 'Client',
  partnerId: partnerIds['Groupe Bellefeuille'],
});
support.assign(ticketClient, ids['marc.leroy@entreprise.com']);
support.setStatus(ticketClient, 'En cours');
support.reply({ ticketId: ticketClient, authorId: ids['marc.leroy@entreprise.com'],
  body: "Reproduit de notre côté : l'export coupe au-delà de mille lignes. Correctif en cours, je vous renvoie le fichier complet dès aujourd'hui." });

const ticketClos = support.create({
  subject: 'Accès à la boîte partagée du studio',
  body: 'Je ne reçois pas les messages adressés au studio.',
  category: 'Informatique', priority: 'Normale', origin: 'Interne',
  requesterId: ids['lucas.petit@entreprise.com'],
});
support.reply({ ticketId: ticketClos, authorId: ids['marc.leroy@entreprise.com'],
  body: 'Droit ajouté sur la boîte du studio, à vérifier de votre côté.' });
support.setStatus(ticketClos, 'Résolu');

support.createArticle({
  title: 'Réinitialiser son mot de passe', category: 'Informatique',
  body: "Depuis l'écran de connexion, demandez un lien de réinitialisation ; il expire au bout d'une heure.\n\nSi l'adresse n'est plus la bonne, cette voie est fermée : passez par une demande de support, l'administration change l'adresse elle-même.",
  visibility: 'Entreprise', authorId: ids['marc.leroy@entreprise.com'],
});
support.createArticle({
  title: 'Demander une attestation ou un justificatif', category: 'Ressources humaines',
  body: "Ouvrez une demande de support en catégorie « Ressources humaines » en précisant le document et la date à laquelle il vous est nécessaire.\n\nLa demande ne sort pas des RH : ni votre manager ni la gestion ne la lisent.",
  visibility: 'Entreprise', authorId: ids['claire.moreau@entreprise.com'],
});
support.createArticle({
  title: "Procédure d'astreinte", category: 'Moyens généraux',
  body: "La semaine d'astreinte court du lundi 8 h au lundi suivant 8 h.\n\nEn cas d'appel : consigner l'heure, l'appelant et la nature de l'incident dans une demande de support avant l'intervention.",
  visibility: 'Service', scopeId: departmentId('Support technique'), authorId: ids['marc.leroy@entreprise.com'],
});

console.log('Jeu de démonstration créé.');
console.log(`  Mot de passe commun à tous les comptes de démo : ${DEMO_PASSWORD}`);
for (const p of people) {
  console.log(`  ${p.email.padEnd(34)} ${p.contract.padEnd(10)} ${p.hr ? 'RH' : ''}`);
}
