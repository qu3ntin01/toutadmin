const express = require('express');

const db = require('../db');
const audit = require('../audit');
const quality = require('../quality');
const org = require('../org');
const { requireAuth } = require('../middleware/auth');
const { setFlash, isValidDateString, parseAmount } = require('../utils');

const router = express.Router();

router.use(requireAuth);

// La qualité se pilote au plus près du terrain : l'encadrement et
// l'administration y ont accès, sans créer un rôle de plus à administrer.
router.use((req, res, next) => {
  const user = req.currentUser || req.session.user;
  if (user.role === 'admin' || org.isManager(user.id)) return next();
  return res.status(403).render('error', { message: "Espace réservé à l'encadrement et à l'administration." });
});

const back = (anchor) => `/qualite#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

const text = (raw, max) => (raw || '').trim().slice(0, max);

function readDate(raw, { required = false } = {}) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: !required, value: null };
  if (!isValidDateString(trimmed)) return { ok: false };
  return { ok: true, value: trimmed };
}

function people() {
  return db.prepare('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE').all();
}

router.get('/', (req, res) => {
  res.render('qualite', {
    stats: quality.summary(),
    ncList: quality.nonconformities(),
    actionList: quality.actions(),
    auditList: quality.audits(),
    findingsByAudit: Object.fromEntries(quality.audits().map((a) => [a.id, quality.findings(a.id)])),
    sources: quality.SOURCES,
    severities: quality.SEVERITIES,
    ncStatuses: quality.NC_STATUSES,
    actionKinds: quality.ACTION_KINDS,
    actionStatuses: quality.ACTION_STATUSES,
    effectiveness: quality.EFFECTIVENESS,
    findingKinds: quality.FINDING_KINDS,
    employees: people(),
    today: new Date().toISOString().slice(0, 10),
  });
});

// ---------- Non-conformités ----------

router.post('/non-conformites', (req, res) => {
  const title = text(req.body.title, 200);
  if (!title) return fail(req, res, 'non-conformites', 'Un intitulé est requis.');
  if (!quality.SOURCES.includes(req.body.source)) return fail(req, res, 'non-conformites', 'Origine inconnue.');
  if (!quality.SEVERITIES.includes(req.body.severity)) return fail(req, res, 'non-conformites', 'Gravité inconnue.');

  const detected = readDate(req.body.detected_on, { required: true });
  if (!detected.ok) return fail(req, res, 'non-conformites', 'Date de constat invalide.');

  const cost = parseAmount(req.body.cost);
  if (req.body.cost && cost === null) return fail(req, res, 'non-conformites', 'Coût invalide.');

  const id = quality.createNonconformity({
    title,
    description: text(req.body.description, 4000),
    source: req.body.source,
    severity: req.body.severity,
    detectedOn: detected.value,
    detectedBy: req.session.user.id,
    subject: text(req.body.subject, 200),
    immediateAction: text(req.body.immediate_action, 2000),
    cost: cost || 0,
  });
  audit.log(req, 'qualite.nc_ouverte', 'nonconformities', id, { titre: title, gravite: req.body.severity });
  setFlash(req, 'success', 'Non-conformité enregistrée.');
  res.redirect(back('non-conformites'));
});

router.post('/non-conformites/:id/cause', (req, res) => {
  const nc = quality.nonconformityById(req.params.id);
  if (!nc) return fail(req, res, 'non-conformites', 'Non-conformité introuvable.');

  quality.setRootCause(nc.id, text(req.body.root_cause, 3000));
  setFlash(req, 'success', 'Cause racine consignée.');
  res.redirect(back('non-conformites'));
});

router.post('/non-conformites/:id/statut', (req, res) => {
  const nc = quality.nonconformityById(req.params.id);
  if (!nc) return fail(req, res, 'non-conformites', 'Non-conformité introuvable.');

  const verdict = quality.setNonconformityStatus(nc.id, req.body.status);
  if (!verdict.ok) return fail(req, res, 'non-conformites', verdict.message);

  audit.log(req, 'qualite.nc_statut', 'nonconformities', nc.id, { statut: req.body.status });
  setFlash(req, 'success', `Non-conformité ${req.body.status.toLowerCase()}.`);
  res.redirect(back('non-conformites'));
});

router.post('/non-conformites/:id/supprimer', (req, res) => {
  const nc = quality.nonconformityById(req.params.id);
  if (!nc) return fail(req, res, 'non-conformites', 'Non-conformité introuvable.');

  quality.deleteNonconformity(nc.id);
  audit.log(req, 'qualite.nc_supprimee', 'nonconformities', nc.id, { reference: nc.reference });
  setFlash(req, 'success', 'Non-conformité supprimée.');
  res.redirect(back('non-conformites'));
});

// ---------- Actions ----------

router.post('/actions', (req, res) => {
  const label = text(req.body.label, 300);
  if (!label) return fail(req, res, 'actions', 'Un libellé est requis.');
  if (!quality.ACTION_KINDS.includes(req.body.kind)) return fail(req, res, 'actions', 'Type d\'action inconnu.');

  const due = readDate(req.body.due_date);
  if (!due.ok) return fail(req, res, 'actions', 'Échéance invalide.');

  const ncId = Number(req.body.nonconformity_id) || null;
  const auditId = Number(req.body.audit_id) || null;
  if (ncId && !quality.nonconformityById(ncId)) return fail(req, res, 'actions', 'Non-conformité inconnue.');
  if (auditId && !quality.auditById(auditId)) return fail(req, res, 'actions', 'Audit inconnu.');

  const id = quality.createAction({
    nonconformityId: ncId,
    auditId,
    kind: req.body.kind,
    label,
    ownerId: Number(req.body.owner_id) || null,
    dueDate: due.value,
  });
  audit.log(req, 'qualite.action_creee', 'quality_actions', id, { libelle: label, type: req.body.kind });
  setFlash(req, 'success', 'Action enregistrée.');
  res.redirect(back('actions'));
});

router.post('/actions/:id/statut', (req, res) => {
  if (!quality.setActionStatus(Number(req.params.id), req.body.status)) {
    return fail(req, res, 'actions', 'Statut inconnu.');
  }
  res.redirect(back('actions'));
});

router.post('/actions/:id/efficacite', (req, res) => {
  const verdict = quality.verifyAction(Number(req.params.id), {
    effectiveness: req.body.effectiveness,
    verifiedBy: req.session.user.id,
  });
  if (!verdict.ok) return fail(req, res, 'actions', verdict.message);

  audit.log(req, 'qualite.action_verifiee', 'quality_actions', Number(req.params.id), { verdict: req.body.effectiveness });
  setFlash(req, 'success', req.body.effectiveness === 'Efficace'
    ? 'Action jugée efficace.'
    : 'Action jugée inefficace : elle appelle une nouvelle réponse.');
  res.redirect(back('actions'));
});

router.post('/actions/:id/supprimer', (req, res) => {
  quality.deleteAction(Number(req.params.id));
  res.redirect(back('actions'));
});

// ---------- Audits internes ----------

router.post('/audits', (req, res) => {
  const scope = text(req.body.scope, 200);
  if (!scope) return fail(req, res, 'audits', 'Un périmètre est requis.');

  const planned = readDate(req.body.planned_on);
  if (!planned.ok) return fail(req, res, 'audits', 'Date invalide.');

  const id = quality.createAudit({
    scope,
    standard: text(req.body.standard, 120),
    plannedOn: planned.value,
    auditorId: Number(req.body.auditor_id) || null,
    summary: '',
  });
  audit.log(req, 'qualite.audit_planifie', 'internal_audits', id, { perimetre: scope });
  setFlash(req, 'success', 'Audit interne planifié.');
  res.redirect(back('audits'));
});

router.post('/audits/:id/realiser', (req, res) => {
  const internal = quality.auditById(req.params.id);
  if (!internal) return fail(req, res, 'audits', 'Audit introuvable.');

  const done = readDate(req.body.done_on, { required: true });
  if (!done.ok) return fail(req, res, 'audits', 'Date de réalisation invalide.');

  quality.completeAudit(internal.id, { doneOn: done.value, summary: text(req.body.summary, 5000) });
  audit.log(req, 'qualite.audit_realise', 'internal_audits', internal.id, { date: done.value });
  setFlash(req, 'success', 'Audit clos et synthèse enregistrée.');
  res.redirect(back('audits'));
});

router.post('/audits/:id/constats', (req, res) => {
  const internal = quality.auditById(req.params.id);
  if (!internal) return fail(req, res, 'audits', 'Audit introuvable.');
  if (!quality.FINDING_KINDS.includes(req.body.kind)) return fail(req, res, 'audits', 'Type de constat inconnu.');

  const statement = text(req.body.statement, 1000);
  if (!statement) return fail(req, res, 'audits', 'Un constat est requis.');

  quality.addFinding(internal.id, { kind: req.body.kind, clause: text(req.body.clause, 60), statement });
  setFlash(req, 'success', 'Constat ajouté.');
  res.redirect(back('audits'));
});

router.post('/constats/:id/en-non-conformite', (req, res) => {
  const verdict = quality.promoteFinding(Number(req.params.id), { detectedBy: req.session.user.id });
  if (!verdict.ok) return fail(req, res, 'audits', verdict.message);

  audit.log(req, 'qualite.constat_promu', 'nonconformities', verdict.id, { constat: Number(req.params.id) });
  setFlash(req, 'success', 'Constat transformé en non-conformité : il a maintenant un porteur et une échéance à recevoir.');
  res.redirect(back('non-conformites'));
});

router.post('/constats/:id/supprimer', (req, res) => {
  quality.deleteFinding(Number(req.params.id));
  res.redirect(back('audits'));
});

router.post('/audits/:id/supprimer', (req, res) => {
  const internal = quality.auditById(req.params.id);
  if (!internal) return fail(req, res, 'audits', 'Audit introuvable.');

  quality.deleteAudit(internal.id);
  audit.log(req, 'qualite.audit_supprime', 'internal_audits', internal.id, { reference: internal.reference });
  setFlash(req, 'success', 'Audit supprimé.');
  res.redirect(back('audits'));
});

module.exports = router;
