const express = require('express');

const db = require('../db');
const audit = require('../audit');
const privacy = require('../privacy');
const sessionStore = require('../session-store');
const { requireAdmin } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();

// Le registre des traitements et le droit d'accès engagent le responsable de
// traitement : c'est l'administration, et elle seule.
router.use(requireAdmin);

const back = (anchor) => `/rgpd#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

router.get('/', (req, res) => {
  const targetId = Number(req.query.personne) || null;
  const target = targetId ? db.prepare('SELECT * FROM users WHERE id = ?').get(targetId) : null;

  res.render('rgpd', {
    recordList: privacy.records(),
    legalBases: privacy.LEGAL_BASES,
    people: db.prepare('SELECT id, first_name, last_name, email, active FROM users ORDER BY last_name COLLATE NOCASE').all(),
    target,
    held: target ? privacy.collectFor(target.id) : null,
    // Compte rendu du dernier effacement, affiché une fois puis oublié.
    eraseReport: req.session.eraseReport || null,
  });
  delete req.session.eraseReport;
});

// ---------- Registre ----------

router.post('/traitements', (req, res) => {
  const name = (req.body.name || '').trim();
  if (!name || name.length > 160) return fail(req, res, 'registre', 'Intitulé invalide.');
  if (!privacy.LEGAL_BASES.includes(req.body.legal_basis)) return fail(req, res, 'registre', 'Base légale invalide.');

  const id = privacy.createRecord({
    name,
    purpose: (req.body.purpose || '').trim().slice(0, 1000),
    legalBasis: req.body.legal_basis,
    dataCategories: (req.body.data_categories || '').trim().slice(0, 1000),
    recipients: (req.body.recipients || '').trim().slice(0, 1000),
    retention: (req.body.retention || '').trim().slice(0, 300),
    measures: (req.body.measures || '').trim().slice(0, 1000),
  });
  audit.log(req, 'rgpd.traitement_ajoute', 'processing_records', id, { nom: name });
  setFlash(req, 'success', 'Traitement inscrit au registre.');
  res.redirect(back('registre'));
});

router.post('/traitements/:id/supprimer', (req, res) => {
  const record = privacy.recordById(req.params.id);
  if (!record) return fail(req, res, 'registre', 'Traitement introuvable.');
  privacy.deleteRecord(record.id);
  audit.log(req, 'rgpd.traitement_supprime', 'processing_records', record.id, { nom: record.name });
  setFlash(req, 'success', 'Traitement retiré du registre.');
  res.redirect(back('registre'));
});

router.post('/traitements/amorcer', (req, res) => {
  const created = privacy.seedRecords();
  setFlash(req, created
    ? 'success' : 'error', created
    ? `${created} traitement(s) préremplis. À relire et à compléter : ils décrivent ce que le CMS fait, pas ce que fait votre entreprise.`
    : 'Le registre contient déjà des traitements : rien n\'a été ajouté.');
  res.redirect(back('registre'));
});

// ---------- Droit d'accès ----------

router.get('/personnes/:id/export.json', (req, res) => {
  const data = privacy.exportFor(Number(req.params.id));
  if (!data) return res.status(404).render('error', { message: 'Personne introuvable.' });

  audit.log(req, 'rgpd.export_donnees', 'users', Number(req.params.id), { email: data.personne.email });
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.setHeader('Content-Disposition', `attachment; filename="donnees-personnelles-${req.params.id}.json"`);
  res.send(JSON.stringify(data, null, 2));
});

router.post('/personnes/:id/effacer', (req, res) => {
  const id = Number(req.params.id);
  const target = db.prepare('SELECT * FROM users WHERE id = ?').get(id);
  if (!target) return fail(req, res, 'acces', 'Personne introuvable.');

  // Un administrateur ne s'efface pas lui-même : il perdrait la main en cours de route.
  if (id === req.currentUser.id) return fail(req, res, 'acces', "Vous ne pouvez pas effacer votre propre compte depuis cet écran.");
  if (target.role === 'admin') return fail(req, res, 'acces', "Rétrogradez d'abord ce compte : un administrateur ne s'efface pas tel quel.");
  if ((req.body.confirmation || '').trim().toLowerCase() !== target.email.toLowerCase()) {
    return fail(req, res, 'acces', "Saisissez l'adresse exacte du compte pour confirmer l'effacement.");
  }

  const result = privacy.eraseFor(id);
  sessionStore.store().revokeUser(id);
  audit.log(req, 'rgpd.effacement', 'users', id, {
    email: result.email,
    efface: result.erased.map((e) => `${e.label}:${e.count}`).join(', '),
    conserve: result.kept.map((k) => `${k.label}:${k.count}`).join(', '),
  });

  req.session.eraseReport = result;
  setFlash(req, 'success', 'Effacement effectué. Le détail de ce qui a été conservé figure ci-dessous.');
  res.redirect(back('acces'));
});

module.exports = router;
