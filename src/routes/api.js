const express = require('express');
const rateLimit = require('express-rate-limit');

const db = require('../db');
const tokens = require('../api-tokens');
const org = require('../org');
const finance = require('../finance');
const billing = require('../billing');
const steering = require('../steering');
const currency = require('../currency');

const router = express.Router();

/**
 * API de lecture, version 1.
 *
 * Volontairement en lecture seule. Un jeton circule dans des fichiers de
 * configuration, des variables d'environnement, parfois un dépôt Git : il ne
 * doit pas pouvoir supprimer un salarié ni émettre une facture. Ce que les
 * outils tiers demandent, dans la pratique, c'est de lire.
 *
 * Pas de cookie, donc pas de session, donc pas de jeton CSRF : l'authentification
 * tient entièrement dans l'en-tête « Authorization: Bearer … ».
 */

const MAX_PAGE = 200;

// Un jeton volé ne doit pas permettre d'aspirer la base en quelques secondes.
const apiLimiter = rateLimit({
  windowMs: 60 * 1000,
  limit: Number(process.env.API_RATE_LIMIT) || 120,
  standardHeaders: true,
  legacyHeaders: false,
  message: { error: 'Trop de requêtes.' },
});

router.use(express.json({ limit: '16kb' }));
router.use(apiLimiter);

// Une réponse d'API ne se met pas en cache : elle porte des données nominatives.
router.use((req, res, next) => {
  res.setHeader('Cache-Control', 'no-store');
  next();
});

function unauthorized(res) {
  res.setHeader('WWW-Authenticate', 'Bearer realm="Salarié Member"');
  // On ne dit pas si le jeton est inconnu, révoqué ou périmé : cela renseignerait.
  return res.status(401).json({ error: "Jeton absent, invalide ou expiré." });
}

router.use((req, res, next) => {
  const header = String(req.headers.authorization || '');
  const bearer = header.toLowerCase().startsWith('bearer ') ? header.slice(7).trim() : '';
  const presented = bearer || String(req.headers['x-api-key'] || '').trim();
  if (!presented) return unauthorized(res);

  const token = tokens.resolve(presented);
  if (!token) return unauthorized(res);

  tokens.touch(token.id, req.ip);
  req.apiToken = token;
  return next();
});

function scope(name) {
  return (req, res, next) => {
    if (!tokens.allows(req.apiToken, name)) {
      return res.status(403).json({ error: `Ce jeton n'a pas la portée « ${name} ».`, scopes: req.apiToken.scopeList });
    }
    return next();
  };
}

function page(req) {
  const limit = Math.min(MAX_PAGE, Math.max(1, Number(req.query.limite) || 50));
  const offset = Math.max(0, Number(req.query.depuis) || 0);
  return { limit, offset };
}

const wrap = (rows, { limit, offset }) => ({ count: rows.length, limit, offset, data: rows });

// ---------- Découverte ----------

router.get('/', (req, res) => {
  res.json({
    version: 1,
    token: { label: req.apiToken.label, scopes: req.apiToken.scopeList, expiresAt: req.apiToken.expires_at },
    readOnly: true,
    endpoints: [
      { path: '/api/v1/collaborateurs', scope: 'annuaire' },
      { path: '/api/v1/services', scope: 'annuaire' },
      { path: '/api/v1/equipes', scope: 'annuaire' },
      { path: '/api/v1/absences', scope: 'rh' },
      { path: '/api/v1/tiers', scope: 'gestion' },
      { path: '/api/v1/factures', scope: 'gestion' },
      { path: '/api/v1/abonnements', scope: 'gestion' },
      { path: '/api/v1/projets', scope: 'projets' },
      { path: '/api/v1/indicateurs', scope: 'pilotage' },
    ],
  });
});

// ---------- Annuaire ----------

router.get('/collaborateurs', scope('annuaire'), (req, res) => {
  const { limit, offset } = page(req);
  // L'API respecte l'annuaire : qui en est retiré n'en sort pas par une autre porte.
  const rows = db.prepare(`
    SELECT u.id, u.first_name, u.last_name, u.email, u.grade, u.contract_type,
           d.name AS department, t.name AS team
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN teams t ON t.id = u.team_id
    WHERE u.active = 1 AND u.directory_hidden = 0
    ORDER BY u.last_name COLLATE NOCASE LIMIT ? OFFSET ?
  `).all(limit, offset);

  res.json(wrap(rows.map((r) => ({
    id: r.id, prenom: r.first_name, nom: r.last_name, email: r.email,
    grade: r.grade, contrat: r.contract_type, service: r.department, equipe: r.team,
  })), { limit, offset }));
});

router.get('/services', scope('annuaire'), (req, res) => {
  res.json(wrap(org.departments().map((d) => ({
    id: d.id, nom: d.name, effectif: d.member_count, equipes: d.team_count,
    encadrants: org.managersOf('department', d.id).map((m) => `${m.first_name} ${m.last_name}`),
  })), { limit: MAX_PAGE, offset: 0 }));
});

router.get('/equipes', scope('annuaire'), (req, res) => {
  res.json(wrap(org.teams().map((t) => ({
    id: t.id, nom: t.name, service: t.department_name, effectif: t.member_count,
    encadrants: org.managersOf('team', t.id).map((m) => `${m.first_name} ${m.last_name}`),
  })), { limit: MAX_PAGE, offset: 0 }));
});

// ---------- Ressources humaines ----------

router.get('/absences', scope('rh'), (req, res) => {
  const { limit, offset } = page(req);
  const from = /^\d{4}-\d{2}-\d{2}$/.test(req.query.depuis_le || '') ? req.query.depuis_le : '0000-01-01';

  const rows = db.prepare(`
    SELECT r.id, r.type, r.start_date, r.end_date, r.days, r.status,
           u.first_name, u.last_name
    FROM hr_requests r JOIN users u ON u.id = r.employee_id
    WHERE r.status = 'Approuvée' AND r.end_date >= ?
    ORDER BY r.start_date DESC LIMIT ? OFFSET ?
  `).all(from, limit, offset);

  // Le motif de l'absence n'est pas rendu : il n'a pas à sortir de l'entreprise.
  res.json(wrap(rows.map((r) => ({
    id: r.id, type: r.type, du: r.start_date, au: r.end_date, jours: r.days,
    personne: `${r.first_name} ${r.last_name}`,
  })), { limit, offset }));
});

// ---------- Gestion ----------

router.get('/tiers', scope('gestion'), (req, res) => {
  const { limit, offset } = page(req);
  const rows = db.prepare(`
    SELECT id, kind, name, registration, email, phone, active FROM partners
    ORDER BY name COLLATE NOCASE LIMIT ? OFFSET ?
  `).all(limit, offset);

  res.json(wrap(rows.map((r) => ({
    id: r.id, type: r.kind, nom: r.name, identifiant: r.registration,
    email: r.email, telephone: r.phone, actif: Boolean(r.active),
  })), { limit, offset }));
});

router.get('/factures', scope('gestion'), (req, res) => {
  const { limit, offset } = page(req);
  const wanted = ['Client', 'Fournisseur'].includes(req.query.sens) ? req.query.sens : null;

  const rows = finance.invoices(wanted ? { direction: wanted } : {}).slice(offset, offset + limit);
  res.json(wrap(rows.map((i) => ({
    id: i.id, reference: i.reference, libelle: i.label, sens: i.direction, statut: i.status,
    emise_le: i.issue_date, echeance: i.due_date, en_retard: i.overdue,
    montant_ht: i.amount_ht, tva: i.vat_rate, montant_ttc: i.amount_ttc,
    devise: i.currency, taux: i.exchange_rate,
    montant_ttc_reference: i.amount_base_ttc, devise_reference: currency.base(),
    tiers: i.partner_name,
  })), { limit, offset }));
});

router.get('/abonnements', scope('gestion'), (req, res) => {
  res.json(wrap(billing.list().map((a) => ({
    id: a.id, libelle: a.label, sens: a.direction, tiers: a.partner_name,
    montant_ht: a.amount_ht, devise: a.currency, periodicite: a.period,
    prochaine_emission: a.next_issue, fin: a.end_date, actif: Boolean(a.active),
  })), { limit: MAX_PAGE, offset: 0 }));
});

// ---------- Projets ----------

router.get('/projets', scope('projets'), (req, res) => {
  const { limit, offset } = page(req);
  const rows = db.prepare(`
    SELECT p.id, p.code, p.name, p.status, p.budget, p.started_on, p.due_on,
           (SELECT COUNT(*) FROM project_tasks t WHERE t.project_id = p.id) AS tasks,
           (SELECT COUNT(*) FROM project_tasks t WHERE t.project_id = p.id AND t.status = 'Terminée') AS done,
           (SELECT COALESCE(SUM(hours), 0) FROM project_time pt WHERE pt.project_id = p.id) AS hours
    FROM projects p WHERE p.archived = 0
    ORDER BY p.name COLLATE NOCASE LIMIT ? OFFSET ?
  `).all(limit, offset);

  res.json(wrap(rows.map((p) => ({
    id: p.id, code: p.code, nom: p.name, statut: p.status, budget: p.budget,
    debut: p.started_on, echeance: p.due_on,
    taches: p.tasks, taches_terminees: p.done, heures: p.hours,
  })), { limit, offset }));
});

// ---------- Pilotage ----------

router.get('/indicateurs', scope('pilotage'), (req, res) => {
  const year = Number(req.query.annee) || new Date().getUTCFullYear();
  const revenue = steering.revenue(year);
  const unpaid = steering.unpaid();

  res.json({
    annee: year,
    devise: currency.base(),
    effectif: db.prepare("SELECT COUNT(*) AS n FROM users WHERE active = 1 AND role = 'employee'").get().n,
    chiffre_affaires: revenue.sales,
    achats: revenue.purchases,
    marge: revenue.margin,
    factures_impayees: { nombre: unpaid.count, total: unpaid.total },
  });
});

// Une route inconnue rend du JSON, pas une page d'erreur HTML : l'appelant est
// un programme.
router.use((req, res) => res.status(404).json({ error: 'Ressource inconnue.' }));

module.exports = router;
