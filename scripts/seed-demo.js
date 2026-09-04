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

// ---------- Organisation : services, équipes et encadrement ----------

const insertDepartment = db.prepare('INSERT OR IGNORE INTO departments (name, description) VALUES (?, ?)');
const departmentId = (name) => db.prepare('SELECT id FROM departments WHERE name = ?').get(name).id;

for (const [name, description] of [
  ['Ressources humaines', 'Recrutement, paie et relations sociales.'],
  ['Production', 'Lignes de fabrication et qualité.'],
  ['Support technique', 'Maintenance des équipements et des outils.'],
  ['Création', 'Design produit et communication.'],
]) {
  insertDepartment.run(name, description);
}

const insertTeam = db.prepare('INSERT INTO teams (name, department_id, description) VALUES (?, ?, ?)');
const teamIds = {};
for (const [name, department, description] of [
  ['Ligne 2', 'Production', 'Équipe de production de la ligne 2.'],
  ['Maintenance', 'Support technique', 'Interventions et astreinte.'],
  ['Studio', 'Création', 'Design produit et supports de communication.'],
]) {
  teamIds[name] = insertTeam.run(name, departmentId(department), description).lastInsertRowid;
}

const setMembership = db.prepare('UPDATE users SET department_id = ?, team_id = ? WHERE id = ?');
setMembership.run(departmentId('Ressources humaines'), null, ids['claire.moreau@entreprise.com']);
setMembership.run(departmentId('Support technique'), teamIds['Maintenance'], ids['marc.leroy@entreprise.com']);
setMembership.run(departmentId('Production'), teamIds['Ligne 2'], ids['sofia.nadir@entreprise.com']);
setMembership.run(departmentId('Création'), teamIds['Studio'], ids['lucas.petit@entreprise.com']);
setMembership.run(departmentId('Création'), teamIds['Studio'], ids['ines.garnier@entreprise.com']);

// Un service comme une équipe peuvent avoir plusieurs managers : la Création en a deux.
const addManager = db.prepare("INSERT OR IGNORE INTO org_managers (scope, scope_id, user_id) VALUES (?, ?, ?)");
addManager.run('team', teamIds['Ligne 2'], ids['sofia.nadir@entreprise.com']);
addManager.run('team', teamIds['Maintenance'], ids['marc.leroy@entreprise.com']);
addManager.run('department', departmentId('Création'), ids['claire.moreau@entreprise.com']);
addManager.run('department', departmentId('Création'), ids['sofia.nadir@entreprise.com']);

const insertTool = db.prepare(`
  INSERT INTO tools (name, category, reference, description, login_url, status)
  VALUES (?, ?, ?, ?, ?, 'disponible')
`);

const tools = [
  ['Slack', 'Communication', 'SAAS-001', "Messagerie interne de l'entreprise", 'https://entreprise.slack.com'],
  ['Google Workspace', 'Bureautique', 'SAAS-002', 'Mail, agenda et documents', 'https://workspace.google.com'],
  ['Perceuse Makita DHP484', 'Électroportatif', 'MAT-114', 'Perceuse-visseuse 18 V', ''],
  ['Véhicule utilitaire', 'Flotte', 'VH-03', 'Renault Kangoo — plaque AB-123-CD', ''],
];

const toolIds = tools.map((t) => insertTool.run(...t).lastInsertRowid);

const assign = db.prepare('INSERT INTO assignments (employee_id, tool_id, note, username) VALUES (?, ?, ?, ?)');
assign.run(ids['marc.leroy@entreprise.com'], toolIds[0], '', 'marc.leroy');
assign.run(ids['marc.leroy@entreprise.com'], toolIds[2], 'Chantier Nord', '');
assign.run(ids['claire.moreau@entreprise.com'], toolIds[1], '', 'c.moreau');
assign.run(ids['sofia.nadir@entreprise.com'], toolIds[3], 'Tournées hebdomadaires', '');
assign.run(ids['lucas.petit@entreprise.com'], toolIds[0], '', 'lucas.ext');

const insertRequest = db.prepare(`
  INSERT INTO hr_requests (employee_id, type, start_date, end_date, days, reason, status)
  VALUES (?, ?, ?, ?, ?, ?, ?)
`);
insertRequest.run(ids['marc.leroy@entreprise.com'], 'Congés payés', '2026-12-21', '2026-12-31', 8, "Congés de fin d'année", 'En attente');
insertRequest.run(ids['sofia.nadir@entreprise.com'], 'Télétravail', '2026-09-14', '2026-09-18', 5, 'Déménagement', 'En attente');
insertRequest.run(ids['ines.garnier@entreprise.com'], 'Absence maladie', '2026-09-02', '2026-09-03', 2, '', 'Approuvée');

const insertPayslip = db.prepare(`
  INSERT INTO payslips (employee_id, period, gross_amount, net_amount, status, note, created_by)
  VALUES (?, ?, ?, ?, ?, ?, ?)
`);
const hrId = ids['claire.moreau@entreprise.com'];
insertPayslip.run(ids['marc.leroy@entreprise.com'], '2026-08', 3200, 2480, 'Payée', '', hrId);
insertPayslip.run(ids['sofia.nadir@entreprise.com'], '2026-08', 2750, 2135, 'Payée', '', hrId);
insertPayslip.run(ids['marc.leroy@entreprise.com'], '2026-09', 3200, 2480, 'À verser', '', hrId);
insertPayslip.run(ids['ines.garnier@entreprise.com'], '2026-09', 1200, 1015, 'À verser', 'Gratification de stage', hrId);

// Quelques sessions de pointage pour le freelance, dont une encore ouverte.
const insertEntry = db.prepare('INSERT INTO time_entries (employee_id, clock_in, clock_out) VALUES (?, ?, ?)');
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
const insertNews = db.prepare(`
  INSERT INTO announcements (author_id, scope, scope_id, title, body)
  VALUES (?, ?, ?, ?, ?)
`);
const adminId = db.prepare("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1").get().id;
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

// Quelques messages internes, dont un non lu.
const insertMessage = db.prepare('INSERT INTO messages (sender_id, recipient_id, subject, body, read_at) VALUES (?, ?, ?, ?, ?)');
insertMessage.run(ids['claire.moreau@entreprise.com'], ids['marc.leroy@entreprise.com'],
  'Votre demande de congés', "Bonjour Marc,\n\nJ'ai bien reçu votre demande pour décembre, je reviens vers vous après validation du planning.\n\nClaire", null);
insertMessage.run(ids['sofia.nadir@entreprise.com'], ids['marc.leroy@entreprise.com'],
  'Astreinte de la semaine 12', "Peux-tu confirmer ta disponibilité pour l'astreinte ?", new Date().toISOString());
insertMessage.run(ids['marc.leroy@entreprise.com'], ids['sofia.nadir@entreprise.com'],
  'Re: Astreinte de la semaine 12', 'Confirmé de mon côté.', null);

console.log('Jeu de démonstration créé.');
console.log(`  Mot de passe commun à tous les comptes de démo : ${DEMO_PASSWORD}`);
for (const p of people) {
  console.log(`  ${p.email.padEnd(34)} ${p.contract.padEnd(10)} ${p.hr ? 'RH' : ''}`);
}
