const express = require('express');

const logs = require('../logs');
const forward = require('../log-forward');
const audit = require('../audit');
const { requireAdmin } = require('../middleware/auth');
const { setFlash, introuvable } = require('../utils');

const router = express.Router();

router.use(requireAdmin);

/**
 * Centre de journaux.
 *
 * Un écran, deux usages : chercher ce qui s'est passé, et brancher les
 * collecteurs qui en garderont une copie ailleurs. Les deux tiennent ensemble
 * parce qu'on ne configure bien un export qu'en voyant ce qu'il va emporter.
 */

const back = (anchor = '') => `/journaux${anchor ? `#${anchor}` : ''}`;

function filtersOf(query) {
  return {
    page: Number(query.page) || 1,
    maxLevel: query.niveau === '' || query.niveau == null ? '' : Number(query.niveau),
    channel: (query.canal || '').trim(),
    event: (query.evenement || '').trim(),
    search: (query.recherche || '').trim().slice(0, 120),
    from: (query.du || '').trim(),
    to: (query.au || '').trim(),
  };
}

router.get('/', (req, res) => {
  const filters = filtersOf(req.query);
  const opened = req.query.ligne ? logs.byId(Number(req.query.ligne)) : null;

  res.render('journaux', {
    journal: logs.list(filters),
    filters,
    opened,
    levels: logs.LEVELS,
    channels: logs.CHANNELS,
    knownEvents: logs.knownEvents(),
    stats: logs.stats({ days: 7 }),
    retention: logs.RETENTION,
    targets: forward.list(),
    summary: forward.summary(),
    transports: forward.TRANSPORTS,
    formats: forward.FORMATS,
    kinds: forward.KINDS,
  });
});

/** L'export CSV reprend les filtres affichés : on exporte ce qu'on regarde. */
router.get('/journal.csv', (req, res) => {
  const { rows } = logs.list({ ...filtersOf(req.query), page: 1 });
  audit.log(req, 'journal.exporte', 'logs', null, { lignes: rows.length });
  res.setHeader('Content-Type', 'text/csv; charset=utf-8');
  res.setHeader('Content-Disposition', 'attachment; filename="journaux.csv"');
  res.send('﻿' + logs.toCsv(rows));
});

router.post('/purger', (req, res) => {
  const removed = logs.purge();
  audit.log(req, 'journal.purge', 'logs', null, removed);
  setFlash(req, 'success', `${removed.total} ligne(s) purgée(s).`);
  res.redirect(back());
});

/**
 * Vider le journal est un acte, pas un réglage : il laisse sa trace dans le
 * journal d'audit, que celui-ci ne peut pas effacer.
 */
router.post('/vider', (req, res) => {
  const removed = logs.clear({ req });
  audit.log(req, 'journal.vide', 'logs', null, { lignes: removed });
  setFlash(req, 'success', `Journal vidé : ${removed} ligne(s) supprimée(s).`);
  res.redirect(back());
});

// ---------- Cibles d'export ----------

function valuesOf(body) {
  return {
    kind: body.kind,
    label: body.label,
    transport: body.transport,
    host: body.host,
    port: body.port,
    format: body.format,
    facility: body.facility === '' || body.facility == null ? 16 : Number(body.facility),
    appName: body.app_name,
    minLevel: body.min_level === '' || body.min_level == null ? 6 : Number(body.min_level),
    channels: Array.isArray(body.channels) ? body.channels : (body.channels ? [body.channels] : []),
    path: body.path,
    allowSelfSigned: body.allow_self_signed === '1' || body.allow_self_signed === 'on',
    enabled: body.enabled === '1' || body.enabled === 'on',
  };
}

router.post('/cibles', (req, res) => {
  const result = forward.create(valuesOf(req.body));
  if (!result.ok) {
    setFlash(req, 'error', result.message);
    return res.redirect(back('cibles'));
  }
  audit.log(req, 'journal.cible_creee', 'log_targets', result.id, { nom: req.body.label, type: req.body.kind });
  setFlash(req, 'success', 'Cible enregistrée. Envoyez une ligne d’essai avant de compter dessus.');
  res.redirect(back('cibles'));
});

router.post('/cibles/:id', (req, res) => {
  const id = Number(req.params.id);
  const result = forward.update(id, valuesOf(req.body));
  if (!result.ok) {
    setFlash(req, 'error', result.message);
    return res.redirect(back('cibles'));
  }
  audit.log(req, 'journal.cible_modifiee', 'log_targets', id, { nom: req.body.label });
  setFlash(req, 'success', 'Cible mise à jour.');
  res.redirect(back('cibles'));
});

router.post('/cibles/:id/etat', (req, res) => {
  const id = Number(req.params.id);
  const enabled = req.body.enabled === '1';
  forward.setEnabled(id, enabled);
  audit.log(req, enabled ? 'journal.cible_allumee' : 'journal.cible_eteinte', 'log_targets', id);
  setFlash(req, 'success', enabled ? 'Export rallumé.' : 'Export éteint.');
  res.redirect(back('cibles'));
});

router.post('/cibles/:id/essai', async (req, res) => {
  const verdict = await forward.test(Number(req.params.id));
  setFlash(req, verdict.ok ? 'success' : 'error', verdict.message);
  res.redirect(back('cibles'));
});

router.post('/cibles/:id/envoyer', async (req, res) => {
  const target = forward.byId(Number(req.params.id));
  if (!target) {
    setFlash(req, 'error', 'Cible introuvable.');
    return res.redirect(back('cibles'));
  }
  const result = await forward.flushTarget(target);
  setFlash(req, result.ok ? 'success' : 'error', result.ok
    ? `${result.sent} ligne(s) envoyée(s).`
    : `Envoi impossible : ${result.error}`);
  res.redirect(back('cibles'));
});

/** Repartir du présent : on renonce à l'arriéré plutôt que de le déverser. */
router.post('/cibles/:id/rattraper', (req, res) => {
  const id = Number(req.params.id);
  const last = forward.skipBacklog(id);
  audit.log(req, 'journal.cible_rattrapee', 'log_targets', id, { jusqua: last });
  setFlash(req, 'success', 'Arriéré abandonné : la cible repart des lignes à venir.');
  res.redirect(back('cibles'));
});

router.post('/cibles/:id/supprimer', (req, res) => {
  const id = Number(req.params.id);
  if (!forward.remove(id)) return introuvable(req, res, back('cibles'), 'Cible introuvable.');

  audit.log(req, 'journal.cible_supprimee', 'log_targets', id);
  setFlash(req, 'success', 'Cible supprimée.');
  res.redirect(back('cibles'));
});

module.exports = router;
