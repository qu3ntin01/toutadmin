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
  INSERT INTO users (role, email, password_hash, first_name, last_name, grade, department,
                     contract_type, contract_end_date, daily_rate, leave_balance, is_hr, active)
  VALUES ('employee', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
`);

const people = [
  { email: 'claire.moreau@entreprise.com', first: 'Claire', last: 'Moreau', grade: 'Manager', dept: 'Ressources humaines', contract: 'CDI', end: null, rate: null, leave: 25, hr: 1 },
  { email: 'marc.leroy@entreprise.com', first: 'Marc', last: 'Leroy', grade: 'Technicien confirmé', dept: 'Maintenance', contract: 'CDI', end: null, rate: null, leave: 25, hr: 0 },
  { email: 'sofia.nadir@entreprise.com', first: 'Sofia', last: 'Nadir', grade: "Chef d'équipe", dept: 'Production', contract: 'CDD', end: '2027-03-31', rate: null, leave: 18.5, hr: 0 },
  { email: 'lucas.petit@entreprise.com', first: 'Lucas', last: 'Petit', grade: 'Employé', dept: 'Design', contract: 'Freelance', end: null, rate: 480, leave: 0, hr: 0 },
  { email: 'ines.garnier@entreprise.com', first: 'Inès', last: 'Garnier', grade: 'Stagiaire', dept: 'Marketing', contract: 'Stage', end: '2026-12-15', rate: null, leave: 8, hr: 0 },
];

const ids = {};
for (const p of people) {
  const info = insertUser.run(p.email, hash, p.first, p.last, p.grade, p.dept, p.contract, p.end, p.rate, p.leave, p.hr);
  ids[p.email] = info.lastInsertRowid;
}

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

console.log('Jeu de démonstration créé.');
console.log(`  Mot de passe commun à tous les comptes de démo : ${DEMO_PASSWORD}`);
for (const p of people) {
  console.log(`  ${p.email.padEnd(34)} ${p.contract.padEnd(10)} ${p.hr ? 'RH' : ''}`);
}
