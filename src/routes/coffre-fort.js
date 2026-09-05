const express = require('express');

const db = require('../db');
const hr = require('../hr');
const audit = require('../audit');
const vault = require('../vault');
const security = require('../security');
const { requireAuth, requireHR } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();

router.use(requireAuth);

const back = (anchor) => `/coffre-fort/gestion#${anchor}`;

function fail(req, res, target, message) {
  setFlash(req, 'error', message);
  return res.redirect(target);
}

// ---------- Mon coffre ----------

router.get('/', (req, res) => {
  const user = req.currentUser;
  res.render('coffre-fort', {
    holder: user,
    documents: vault.documentsFor(user.id),
    payslips: hr.getPayslipsForEmployee(user.id, 200),
    retentionYears: vault.RETENTION_YEARS,
    // Vrai quand la session a été ouverte par un compte fermé ou par un code.
    restricted: Boolean(req.session.vaultOnly),
  });
});

/**
 * Téléchargement. L'empreinte est recalculée avant l'envoi : un document dont
 * le contenu a bougé n'est pas servi, et l'anomalie est consignée.
 */
router.get('/documents/:id', (req, res) => {
  const document = vault.byId(req.params.id);
  if (!document || document.removed_at) {
    return res.status(404).render('error', { message: 'Document introuvable.' });
  }

  const isOwner = document.user_id === req.currentUser.id;
  const isSteward = !req.session.vaultOnly && (req.currentUser.role === 'admin' || req.currentUser.is_hr);
  if (!isOwner && !isSteward) {
    return res.status(403).render('error', { message: "Ce document n'est pas le vôtre." });
  }

  const verdict = vault.verify(document);
  if (!verdict.ok) {
    audit.log(req, 'coffre.integrite_rompue', 'vault_documents', document.id, { motif: verdict.reason });
    return res.status(500).render('error', {
      message: "L'intégrité de ce document ne peut pas être confirmée : il n'est pas servi. Prévenez l'administration.",
    });
  }

  audit.log(req, isOwner ? 'coffre.document_telecharge' : 'coffre.document_consulte_par_rh', 'vault_documents', document.id, {
    titulaire: document.user_id, empreinte: document.sha256.slice(0, 16),
  });

  const suggested = document.original_name || `${document.title}`.replace(/[^\w.-]+/g, '-');
  res.download(vault.pathOf(document), suggested);
});

// ---------- Gestion : dépôt, codes d'accès, intégrité ----------

router.use('/gestion', requireHR);

function people() {
  return db.prepare(`
    SELECT u.id, u.first_name, u.last_name, u.email, u.active, u.contract_end_date,
           (SELECT COUNT(*) FROM vault_documents WHERE user_id = u.id AND removed_at IS NULL) AS documents
    FROM users u ORDER BY u.active DESC, u.last_name COLLATE NOCASE
  `).all();
}

router.get('/gestion', (req, res) => {
  const targetId = Number(req.query.personne) || null;
  const target = targetId ? db.prepare('SELECT * FROM users WHERE id = ?').get(targetId) : null;

  res.render('coffre-gestion', {
    people: people(),
    target,
    documents: target ? vault.documentsFor(target.id, { includeRemoved: true }) : [],
    payslips: target ? hr.getPayslipsForEmployee(target.id, 200) : [],
    grants: target ? vault.grantsFor(target.id) : [],
    categories: vault.CATEGORIES,
    summary: vault.summary(),
    integrity: vault.audit(),
    retentionYears: vault.RETENTION_YEARS,
    defaultGrantDays: vault.DEFAULT_GRANT_DAYS,
    maxGrantDays: vault.MAX_GRANT_DAYS,
    // Affiché une seule fois, juste après l'émission.
    issuedCode: req.session.vaultIssuedCode || null,
  });
  delete req.session.vaultIssuedCode;
});

function receiveDocument(req, res, next) {
  vault.upload(req, res, (err) => {
    if (!err) return next();
    const message = err.code === 'LIMIT_FILE_SIZE'
      ? 'Document trop volumineux : 10 Mo maximum.'
      : 'Format non pris en charge : PDF, DOCX, PNG ou JPEG.';
    fail(req, res, back('depot'), message);
  });
}

router.post('/gestion/depots', ...security.upload(receiveDocument), (req, res) => {
  const userId = Number(req.body.user_id);
  const holder = db.prepare('SELECT * FROM users WHERE id = ?').get(userId);
  if (!holder) return fail(req, res, back('depot'), 'Personne introuvable.');

  const title = (req.body.title || '').trim();
  if (!title || title.length > 200) return fail(req, res, back('depot'), 'Intitulé invalide.');

  const period = (req.body.period || '').trim();
  if (period && !/^\d{4}(-\d{2})?$/.test(period)) {
    return fail(req, res, back('depot'), 'Période invalide : attendu AAAA ou AAAA-MM.');
  }

  // Rattachement facultatif à une fiche de paie déjà enregistrée.
  const payslipId = Number(req.body.payslip_id) || null;
  if (payslipId) {
    const payslip = db.prepare('SELECT * FROM payslips WHERE id = ? AND employee_id = ?').get(payslipId, userId);
    if (!payslip) return fail(req, res, back('depot'), 'Fiche de paie introuvable pour cette personne.');
  }

  const verdict = vault.deposit({
    userId,
    category: req.body.category,
    title: title.slice(0, 200),
    period,
    file: req.file,
    depositedBy: req.currentUser.id,
    payslipId,
  });
  if (!verdict.ok) return fail(req, res, `/coffre-fort/gestion?personne=${userId}#depot`, verdict.message);

  audit.log(req, 'coffre.document_depose', 'vault_documents', verdict.id, {
    titulaire: userId, categorie: req.body.category, empreinte: verdict.sha256.slice(0, 16),
  });
  setFlash(req, 'success', `Document déposé au coffre de ${holder.first_name} ${holder.last_name}, conservé jusqu'en ${new Date().getUTCFullYear() + vault.RETENTION_YEARS}.`);
  res.redirect(`/coffre-fort/gestion?personne=${userId}#documents`);
});

router.post('/gestion/documents/:id/retirer', (req, res) => {
  const document = vault.byId(req.params.id);
  if (!document) return fail(req, res, back('documents'), 'Document introuvable.');

  // Un retrait engage : il est réservé à l'administration et laisse un motif.
  if (req.currentUser.role !== 'admin') {
    return res.status(403).render('error', { message: "Le retrait d'un document du coffre est réservé à l'administration." });
  }

  const verdict = vault.remove(document.id, { removedBy: req.currentUser.id, reason: req.body.reason || '' });
  if (!verdict.ok) return fail(req, res, `/coffre-fort/gestion?personne=${document.user_id}#documents`, verdict.message);

  audit.log(req, 'coffre.document_retire', 'vault_documents', document.id, {
    titulaire: document.user_id, motif: (req.body.reason || '').trim().slice(0, 300),
  });
  setFlash(req, 'success', 'Document retiré. La trace du retrait, son auteur et son motif restent au coffre.');
  res.redirect(`/coffre-fort/gestion?personne=${document.user_id}#documents`);
});

router.post('/gestion/acces/:id', (req, res) => {
  const holder = db.prepare('SELECT * FROM users WHERE id = ?').get(Number(req.params.id));
  if (!holder) return fail(req, res, back('acces'), 'Personne introuvable.');
  if (!vault.hasDocuments(holder.id)) {
    return fail(req, res, `/coffre-fort/gestion?personne=${holder.id}#acces`, "Le coffre de cette personne est vide : un code n'ouvrirait rien.");
  }

  const days = Number(req.body.days) || vault.DEFAULT_GRANT_DAYS;
  if (days < 1 || days > vault.MAX_GRANT_DAYS) {
    return fail(req, res, `/coffre-fort/gestion?personne=${holder.id}#acces`, `La validité tient entre 1 et ${vault.MAX_GRANT_DAYS} jours.`);
  }

  const grant = vault.issueGrant(holder.id, { days, createdBy: req.currentUser.id });
  req.session.vaultIssuedCode = { code: grant.code, expiresAt: grant.expiresAt, email: holder.email };
  audit.log(req, 'coffre.code_emis', 'users', holder.id, { validite_jours: days });
  setFlash(req, 'success', "Code émis. Il n'est affiché qu'une fois : transmettez-le à la personne.");
  res.redirect(`/coffre-fort/gestion?personne=${holder.id}#acces`);
});

router.post('/gestion/acces/:id/revoquer', (req, res) => {
  const holder = db.prepare('SELECT * FROM users WHERE id = ?').get(Number(req.params.id));
  if (!holder) return fail(req, res, back('acces'), 'Personne introuvable.');

  const revoked = vault.revokeGrants(holder.id);
  audit.log(req, 'coffre.codes_revoques', 'users', holder.id, { revoques: revoked });
  setFlash(req, 'success', `${revoked} code(s) révoqué(s).`);
  res.redirect(`/coffre-fort/gestion?personne=${holder.id}#acces`);
});

module.exports = router;
