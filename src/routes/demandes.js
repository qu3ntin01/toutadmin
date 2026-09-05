const express = require('express');

const audit = require('../audit');
const workflows = require('../workflows');
const db = require('../db');
const { requireAuth } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();

router.use(requireAuth);

const isAdmin = (req) => (req.currentUser || req.session.user).role === 'admin';

function requireAdminHere(req, res, next) {
  if (!isAdmin(req)) {
    return res.status(403).render('error', { message: "Les circuits d'approbation se paramètrent depuis l'administration." });
  }
  return next();
}

const back = (anchor) => `/demandes#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

router.get('/', (req, res) => {
  const user = req.currentUser || req.session.user;
  res.render('demandes', {
    admin: isAdmin(req),
    openForms: workflows.forms({ activeOnly: true }),
    allForms: isAdmin(req) ? workflows.forms() : [],
    mine: workflows.list({ requesterId: user.id }),
    toDecide: workflows.awaiting(user.id),
    stats: workflows.summary(),
    fieldTypes: workflows.FIELD_TYPES,
    approvers: workflows.APPROVERS,
    maxFields: workflows.MAX_FIELDS,
    maxSteps: workflows.MAX_STEPS,
    employees: db.prepare('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE').all(),
  });
});

// ---------- Paramétrage ----------

router.post('/types', requireAdminHere, (req, res) => {
  // Les champs arrivent en colonnes parallèles : on les rassemble avant de les
  // valider, pour qu'une ligne laissée vide n'en décale pas une autre.
  const names = [].concat(req.body.field_names || []);
  const labels = [].concat(req.body.field_labels || []);
  const types = [].concat(req.body.field_types || []);
  const required = [].concat(req.body.field_required || []);
  const options = [].concat(req.body.field_options || []);

  const fields = names
    .map((name, index) => ({
      name,
      label: labels[index] || name,
      type: types[index] || 'texte',
      required: String(required[index] || '') === '1',
      options: String(options[index] || '').split('|').map((o) => o.trim()).filter(Boolean),
    }))
    .filter((field) => String(field.name || '').trim());

  const verdict = workflows.createForm({
    label: req.body.label,
    description: req.body.description,
    fields,
    amountField: req.body.amount_field,
    createdBy: req.session.user.id,
  });
  if (!verdict.ok) return fail(req, res, 'types', verdict.message);

  audit.log(req, 'demande.type_cree', 'request_forms', verdict.id, { intitule: req.body.label, champs: fields.length });
  setFlash(req, 'success', "Type de demande créé. Ajoutez maintenant les étapes de son circuit.");
  return res.redirect(back('types'));
});

router.post('/types/:id/etapes', requireAdminHere, (req, res) => {
  const verdict = workflows.addStep(Number(req.params.id), {
    approver: req.body.approver,
    approverId: Number(req.body.approver_id) || null,
    label: req.body.label,
    threshold: Number(req.body.threshold) || 0,
  });
  if (!verdict.ok) return fail(req, res, 'types', verdict.message);

  audit.log(req, 'demande.etape_ajoutee', 'request_steps', Number(req.params.id), { validateur: req.body.approver });
  setFlash(req, 'success', 'Étape ajoutée au circuit.');
  return res.redirect(back('types'));
});

router.post('/types/:id/etapes/:stepId/supprimer', requireAdminHere, (req, res) => {
  workflows.deleteStep(Number(req.params.id), Number(req.params.stepId));
  setFlash(req, 'success', 'Étape retirée.');
  return res.redirect(back('types'));
});

router.post('/types/:id/etat', requireAdminHere, (req, res) => {
  const active = req.body.active === '1';
  workflows.setFormActive(Number(req.params.id), active);
  audit.log(req, 'demande.type_etat', 'request_forms', Number(req.params.id), { actif: active });
  setFlash(req, 'success', active ? 'Type ouvert aux demandes.' : 'Type fermé : les demandes en cours suivent leur circuit.');
  return res.redirect(back('types'));
});

router.post('/types/:id/supprimer', requireAdminHere, (req, res) => {
  const verdict = workflows.deleteForm(Number(req.params.id));
  if (!verdict.ok) return fail(req, res, 'types', verdict.message);

  audit.log(req, 'demande.type_supprime', 'request_forms', Number(req.params.id), {});
  setFlash(req, 'success', 'Type de demande supprimé.');
  return res.redirect(back('types'));
});

// ---------- Demandes ----------

router.post('/', (req, res) => {
  const verdict = workflows.submit(Number(req.body.form_id), req.session.user.id, req.body);
  if (!verdict.ok) return fail(req, res, 'nouvelle', verdict.message);

  audit.log(req, 'demande.deposee', 'workflow_requests', verdict.id, { type: Number(req.body.form_id), etapes: verdict.steps });
  setFlash(req, 'success', verdict.steps
    ? 'Demande déposée : elle suit son circuit de validation.'
    : "Demande déposée et approuvée d'emblée : aucune validation n'est requise pour ce cas.");
  return res.redirect(`/demandes/${verdict.id}`);
});

function loadRequest(req, res) {
  const request = workflows.byId(req.params.id);
  if (!request) {
    res.status(404).render('error', { message: 'Demande introuvable.' });
    return null;
  }

  const user = req.currentUser || req.session.user;
  const involved = request.requester_id === user.id
    || request.pendingApprovers.some((a) => a.id === user.id)
    || request.decisions.some((d) => d.approver_id === user.id);

  if (!involved && !isAdmin(req)) {
    res.status(403).render('error', { message: 'Cette demande ne vous concerne pas.' });
    return null;
  }
  return request;
}

router.get('/:id', (req, res) => {
  const request = loadRequest(req, res);
  if (!request) return undefined;

  const user = req.currentUser || req.session.user;
  return res.render('demande', {
    request,
    canDecide: workflows.canDecide(request, user.id),
    isRequester: request.requester_id === user.id,
  });
});

router.post('/:id/decision', (req, res) => {
  const request = loadRequest(req, res);
  if (!request) return undefined;

  const verdict = workflows.decide(request.id, req.session.user.id, {
    decision: req.body.decision,
    note: req.body.note,
  });
  if (!verdict.ok) {
    setFlash(req, 'error', verdict.message);
    return res.redirect(`/demandes/${request.id}`);
  }

  audit.log(req, 'demande.decidee', 'workflow_requests', request.id, { decision: req.body.decision, issue: verdict.status });
  setFlash(req, 'success', verdict.status === 'En cours'
    ? "Étape validée : la demande passe au validateur suivant."
    : `Demande ${verdict.status.toLowerCase()}.`);
  return res.redirect(`/demandes/${request.id}`);
});

router.post('/:id/annuler', (req, res) => {
  const verdict = workflows.cancel(Number(req.params.id), req.session.user.id);
  if (!verdict.ok) {
    setFlash(req, 'error', verdict.message);
    return res.redirect(`/demandes/${Number(req.params.id)}`);
  }

  audit.log(req, 'demande.annulee', 'workflow_requests', Number(req.params.id), {});
  setFlash(req, 'success', 'Demande retirée.');
  return res.redirect(back('mes-demandes'));
});

module.exports = router;
