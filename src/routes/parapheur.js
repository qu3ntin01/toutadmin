const express = require('express');
const fs = require('fs');

const db = require('../db');
const audit = require('../audit');
const signing = require('../signing');
const security = require('../security');
const notifications = require('../notifications');
const { requireAuth } = require('../middleware/auth');
const { setFlash, isValidDateString } = require('../utils');

const router = express.Router();

router.use(requireAuth);

// Mettre un document à la signature engage l'entreprise : c'est l'affaire de
// l'administration et des RH. Signer, en revanche, concerne chacun.
function canOpen(req) {
  const user = req.currentUser || req.session.user;
  return user.role === 'admin' || Boolean(user.is_hr);
}

function requireOpener(req, res, next) {
  if (!canOpen(req)) {
    return res.status(403).render('error', { message: "Seules l'administration et les RH mettent un document à la signature." });
  }
  next();
}

const back = (anchor) => `/parapheur#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

function receiveDocument(req, res, next) {
  signing.upload(req, res, (err) => {
    if (!err) return next();
    setFlash(req, 'error', err.message === 'unsupported-type'
      ? 'Seuls les fichiers PDF et DOCX sont acceptés.'
      : `Fichier refusé : ${Math.round(signing.MAX_BYTES / 1048576)} Mo maximum.`);
    res.redirect(back('nouveau'));
  });
}

router.get('/', (req, res) => {
  const user = req.currentUser || req.session.user;
  res.render('parapheur', {
    opener: canOpen(req),
    mine: signing.forUser(user.id),
    pending: signing.pendingFor(user.id),
    all: canOpen(req) ? signing.list() : [],
    stats: signing.summary(),
    kinds: signing.KINDS,
    employees: db.prepare("SELECT id, first_name, last_name, grade FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE").all(),
    maxSigners: signing.MAX_SIGNERS,
    today: new Date().toISOString().slice(0, 10),
  });
});

router.post('/', requireOpener, ...security.upload(receiveDocument), (req, res) => {
  const deadline = (req.body.deadline || '').trim();
  if (deadline && !isValidDateString(deadline)) return fail(req, res, 'nouveau', 'Échéance invalide.');

  // L'ordre des signataires est celui de la saisie : le parapheur circule.
  // Les rôles sont appariés avant d'écarter les cases vides, sinon un rang
  // laissé libre décalerait tous les suivants.
  const ids = [].concat(req.body.signer_ids || []);
  const roles = [].concat(req.body.signer_roles || []);
  const signers = ids
    .map((id, index) => ({ userId: Number(id), roleLabel: roles[index] || '' }))
    .filter((signer) => signer.userId);

  const verdict = signing.create({
    title: req.body.title,
    kind: req.body.kind,
    body: req.body.body || '',
    file: req.file || null,
    deadline: deadline || null,
    createdBy: req.session.user.id,
    signers,
  });
  if (!verdict.ok) return fail(req, res, 'nouveau', verdict.message);

  audit.log(req, 'parapheur.ouvert', 'signature_requests', verdict.id, {
    titre: req.body.title, empreinte: verdict.sha256.slice(0, 16), signataires: signers.length,
  });

  // Le premier signataire est prévenu ; les suivants le seront à leur tour.
  const first = signing.signersOf(verdict.id)[0];
  if (first) {
    notifications.push({
      userId: first.user_id,
      kind: 'document',
      title: `Document à signer — ${String(req.body.title).slice(0, 120)}`,
      body: 'Le parapheur attend votre signature.',
      link: `/parapheur/${verdict.id}`,
      dedupeKey: `parapheur:${verdict.id}:${first.user_id}`,
    });
  }

  setFlash(req, 'success', 'Document mis à la signature.');
  res.redirect(`/parapheur/${verdict.id}`);
});

function loadRequest(req, res) {
  const request = signing.decorate(signing.byId(req.params.id));
  if (!request) {
    res.status(404).render('error', { message: 'Document introuvable.' });
    return null;
  }
  // Un document au parapheur ne se lit que par ses parties, l'administration
  // et les RH : c'est souvent un contrat de travail.
  if (!canOpen(req) && !signing.isParty(request, req.session.user.id)) {
    res.status(403).render('error', { message: 'Ce document ne vous concerne pas.' });
    return null;
  }
  return request;
}

router.get('/:id', (req, res) => {
  const request = loadRequest(req, res);
  if (!request) return undefined;

  const user = req.currentUser || req.session.user;
  return res.render('signature', {
    request,
    verification: signing.verify(request),
    myTurn: signing.canSign(request, user.id),
    opener: canOpen(req),
  });
});

/** Le document tel qu'il a été mis à la signature — jamais une version d'après. */
router.get('/:id/document', (req, res) => {
  const request = loadRequest(req, res);
  if (!request) return undefined;
  if (!request.file_name) return res.status(404).render('error', { message: 'Ce document est un texte, affiché sur sa page.' });

  const integrity = signing.verifyDocument(request);
  if (!integrity.ok) {
    return res.status(409).render('error', {
      message: `Document non servi : ${integrity.reason}. Prévenez l'administration.`,
    });
  }

  audit.log(req, 'parapheur.telecharge', 'signature_requests', request.id, {});
  res.setHeader('Content-Type', request.mime_type);
  res.setHeader('Content-Disposition', `attachment; filename="${encodeURIComponent(request.original_name || 'document')}"`);
  return res.send(fs.readFileSync(signing.pathOf(request)));
});

router.post('/:id/signer', (req, res) => {
  const request = loadRequest(req, res);
  if (!request) return undefined;

  const verdict = signing.sign(request.id, req.session.user.id, {
    password: req.body.password,
    consent: req.body.consent === '1',
    ip: req.ip,
  });
  if (!verdict.ok) {
    setFlash(req, 'error', verdict.message);
    return res.redirect(`/parapheur/${request.id}`);
  }

  audit.log(req, 'parapheur.signe', 'signature_requests', request.id, {
    sceau: verdict.seal.slice(0, 16), reste: verdict.remaining,
  });

  // Au suivant : la notification suit le circuit plutôt que d'attendre qu'on y pense.
  const next = signing.signersOf(request.id).find((s) => s.status === 'En attente');
  if (next) {
    notifications.push({
      userId: next.user_id,
      kind: 'document',
      title: `Document à signer — ${request.title.slice(0, 120)}`,
      body: 'Le parapheur attend votre signature.',
      link: `/parapheur/${request.id}`,
      dedupeKey: `parapheur:${request.id}:${next.user_id}`,
    });
  } else if (request.created_by) {
    notifications.push({
      userId: request.created_by,
      kind: 'document',
      title: `Document signé — ${request.title.slice(0, 120)}`,
      body: 'Tous les signataires se sont prononcés.',
      link: `/parapheur/${request.id}/attestation`,
      dedupeKey: `parapheur:${request.id}:complet`,
    });
  }

  setFlash(req, 'success', verdict.completed
    ? 'Signature apposée. Le document est intégralement signé.'
    : 'Signature apposée. Le parapheur passe au signataire suivant.');
  return res.redirect(`/parapheur/${request.id}`);
});

router.post('/:id/refuser', (req, res) => {
  const request = loadRequest(req, res);
  if (!request) return undefined;

  const verdict = signing.refuse(request.id, req.session.user.id, { reason: req.body.reason, ip: req.ip });
  if (!verdict.ok) {
    setFlash(req, 'error', verdict.message);
    return res.redirect(`/parapheur/${request.id}`);
  }

  audit.log(req, 'parapheur.refuse', 'signature_requests', request.id, { motif: String(req.body.reason || '').slice(0, 120) });
  if (request.created_by) {
    notifications.push({
      userId: request.created_by,
      kind: 'document',
      title: `Signature refusée — ${request.title.slice(0, 120)}`,
      body: String(req.body.reason || '').slice(0, 300),
      link: `/parapheur/${request.id}`,
      dedupeKey: `parapheur:${request.id}:refus`,
    });
  }
  setFlash(req, 'success', 'Refus enregistré et motivé. Le circuit est interrompu.');
  return res.redirect(`/parapheur/${request.id}`);
});

router.post('/:id/annuler', requireOpener, (req, res) => {
  const verdict = signing.cancel(Number(req.params.id), req.body.reason || '');
  if (!verdict.ok) return fail(req, res, 'documents', verdict.message);

  audit.log(req, 'parapheur.annule', 'signature_requests', Number(req.params.id), { motif: String(req.body.reason || '').slice(0, 120) });
  setFlash(req, 'success', 'Document retiré de la signature.');
  return res.redirect(`/parapheur/${Number(req.params.id)}`);
});

router.post('/:id/supprimer', requireOpener, (req, res) => {
  const verdict = signing.remove(Number(req.params.id));
  if (!verdict.ok) return fail(req, res, 'documents', verdict.message);

  audit.log(req, 'parapheur.supprime', 'signature_requests', Number(req.params.id), {});
  setFlash(req, 'success', 'Document supprimé.');
  return res.redirect(back('documents'));
});

/** L'attestation : ce qu'on imprime et qu'on joint au dossier. */
router.get('/:id/attestation', (req, res) => {
  const request = loadRequest(req, res);
  if (!request) return undefined;

  const certificate = signing.certificate(request.id);
  return res.render('attestation-signature', { ...certificate, generatedAt: new Date().toISOString() });
});

module.exports = router;
