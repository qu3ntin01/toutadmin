const express = require('express');

const db = require('../db');
const org = require('../org');
const audit = require('../audit');
const steering = require('../steering');
const deadlines = require('../deadlines');
const { requireAuth } = require('../middleware/auth');
const { setFlash, parseAmount } = require('../utils');

const router = express.Router();

router.use(requireAuth);

const back = (anchor) => `/pilotage#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

/** Le pilotage regarde toute l'entreprise : administration, gestion et RH. */
function requireSteering(req, res, next) {
  const user = req.currentUser;
  if (user && (user.role === 'admin' || user.is_finance || user.is_hr)) return next();
  res.status(403).render('error', { message: "Le pilotage est réservé à la direction, à la gestion et aux RH." });
}

router.use(requireSteering);

router.get('/', (req, res) => {
  const year = Number(req.query.annee) || new Date().getUTCFullYear();
  res.render('pilotage', {
    overview: steering.overview(year),
    deadlines: deadlines.summary(),
    objectiveList: steering.objectives(),
    scopes: steering.SCOPES,
    statuses: steering.OBJECTIVE_STATUSES,
    departments: org.departments(),
    teams: org.teams(),
    people: db.prepare('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE').all(),
    year,
  });
});

// ---------- Objectifs ----------

router.post('/objectifs', (req, res) => {
  const title = (req.body.title || '').trim();
  if (!title || title.length > 200) return fail(req, res, 'objectifs', 'Intitulé invalide.');
  if (!steering.SCOPES.includes(req.body.scope)) return fail(req, res, 'objectifs', 'Portée invalide.');

  const scopeId = req.body.scope === 'Entreprise' ? null : Number(req.body.scope_id) || null;
  if (req.body.scope !== 'Entreprise' && !scopeId) {
    return fail(req, res, 'objectifs', 'Une portée de service ou d\'équipe demande de choisir laquelle.');
  }

  const id = steering.createObjective({
    title,
    description: (req.body.description || '').trim().slice(0, 2000),
    scope: req.body.scope,
    scopeId,
    ownerId: Number(req.body.owner_id) || null,
    period: (req.body.period || '').trim().slice(0, 20),
  });
  audit.log(req, 'objectif.cree', 'objectives', id, { titre: title });
  setFlash(req, 'success', 'Objectif créé. Ajoutez-lui des résultats clés mesurables.');
  res.redirect(back('objectifs'));
});

router.post('/objectifs/:id/statut', (req, res) => {
  if (!steering.setObjectiveStatus(Number(req.params.id), req.body.status)) {
    return fail(req, res, 'objectifs', 'Statut invalide.');
  }
  res.redirect(back('objectifs'));
});

router.post('/objectifs/:id/supprimer', (req, res) => {
  steering.deleteObjective(Number(req.params.id));
  setFlash(req, 'success', 'Objectif supprimé, avec ses résultats clés.');
  res.redirect(back('objectifs'));
});

router.post('/objectifs/:id/resultats', (req, res) => {
  const objective = steering.objectiveById(req.params.id);
  if (!objective) return fail(req, res, 'objectifs', 'Objectif introuvable.');

  const title = (req.body.title || '').trim();
  if (!title) return fail(req, res, 'objectifs', 'Intitulé du résultat clé invalide.');

  const start = parseAmount(req.body.start_value || '0');
  const target = parseAmount(req.body.target_value || '');
  const current = parseAmount(req.body.current_value || req.body.start_value || '0');
  if (![start, target, current].every((v) => Number.isFinite(v))) {
    return fail(req, res, 'objectifs', 'Valeurs invalides.');
  }
  if (start === target) return fail(req, res, 'objectifs', 'Départ et cible identiques : il n\'y a rien à mesurer.');

  steering.addKeyResult({
    objectiveId: objective.id, title: title.slice(0, 200),
    startValue: start, targetValue: target, currentValue: current,
    unit: (req.body.unit || '').trim().slice(0, 20),
  });
  setFlash(req, 'success', 'Résultat clé ajouté.');
  res.redirect(back('objectifs'));
});

router.post('/resultats/:id', (req, res) => {
  const value = parseAmount(req.body.current_value || '');
  if (!Number.isFinite(value)) return fail(req, res, 'objectifs', 'Valeur invalide.');
  if (!steering.updateKeyResult(Number(req.params.id), value)) {
    return fail(req, res, 'objectifs', 'Résultat clé introuvable.');
  }
  res.redirect(back('objectifs'));
});

router.post('/resultats/:id/supprimer', (req, res) => {
  steering.deleteKeyResult(Number(req.params.id));
  setFlash(req, 'success', 'Résultat clé retiré.');
  res.redirect(back('objectifs'));
});

module.exports = router;
