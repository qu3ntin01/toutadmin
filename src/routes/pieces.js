const express = require('express');
const fs = require('fs');

const db = require('../db');
const audit = require('../audit');
const intake = require('../intake');
const mailbox = require('../mailbox');
const ai = require('../ai');
const finance = require('../finance');
const currency = require('../currency');
const org = require('../org');
const security = require('../security');
const { requireFinance } = require('../middleware/auth');
const { setFlash, isValidDateString, parseAmount } = require('../utils');

const router = express.Router();

// La corbeille des pièces comptables appartient à la gestion : elle contient
// les factures des fournisseurs avant qu'elles n'entrent dans les comptes.
router.use(requireFinance);

const back = (anchor) => `/pieces#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

const isAdmin = (req) => (req.currentUser || req.session.user).role === 'admin';

function receiveDocument(req, res, next) {
  intake.upload(req, res, (err) => {
    if (!err) return next();
    setFlash(req, 'error', err.message === 'unsupported-type'
      ? 'Seuls les PDF, DOCX et fichiers texte sont acceptés.'
      : `Fichier refusé : ${Math.round(intake.MAX_BYTES / 1048576)} Mo maximum.`);
    res.redirect(back('a-traiter'));
  });
}

router.get('/', (req, res) => {
  res.render('pieces', {
    waiting: intake.list({ status: 'À traiter' }),
    handled: intake.list({ status: 'Facturée' }).concat(intake.list({ status: 'Écartée' }))
      .sort((a, b) => String(b.received_at).localeCompare(String(a.received_at))),
    stats: intake.summary(),
    mail: mailbox.displayConfig(),
    mailActions: mailbox.ACTIONS,
    mailStatus: mailbox.status(),
    mailReady: mailbox.isReady(),
    aiConfig: ai.displayConfig(),
    aiProviders: ai.PROVIDERS,
    aiEfforts: ai.EFFORTS,
    aiStatus: ai.status(),
    aiReady: ai.isReady(),
    admin: isAdmin(req),
    maxBytes: intake.MAX_BYTES,
  });
});

// ---------- Dépôt manuel ----------

router.post('/deposer', ...security.upload(receiveDocument), async (req, res) => {
  if (!req.file) return fail(req, res, 'a-traiter', 'Aucun fichier reçu.');

  const outcome = await intake.receive({
    buffer: req.file.buffer,
    originalName: req.file.originalname,
    mimeType: req.file.mimetype,
    source: 'Dépôt',
    createdBy: req.session.user.id,
  });
  if (!outcome.ok) {
    return outcome.duplicate
      ? fail(req, res, 'a-traiter', `${outcome.message} Voir la pièce n° ${outcome.duplicate}.`)
      : fail(req, res, 'a-traiter', outcome.message);
  }

  audit.log(req, 'pieces.deposee', 'incoming_documents', outcome.id, {
    fichier: req.file.originalname, confiance: outcome.analysis.confidence,
  });
  setFlash(req, 'success', `Pièce reçue et analysée (confiance ${outcome.analysis.confidence} %).`);
  return res.redirect(`/pieces/${outcome.id}`);
});

// ---------- Pièce ----------

function loadDocument(req, res) {
  const document = intake.byId(req.params.id);
  if (!document) {
    res.status(404).render('error', { message: 'Pièce introuvable.' });
    return null;
  }
  return document;
}

router.get('/:id', (req, res) => {
  const document = loadDocument(req, res);
  if (!document) return undefined;

  return res.render('piece', {
    document,
    integrity: intake.verify(document),
    partners: finance.partners(),
    departments: org.departments(),
    currencies: currency.usable(),
    baseCurrency: currency.base(),
    invoiceDirections: finance.INVOICE_DIRECTIONS,
    invoiceStatuses: finance.INVOICE_STATUSES,
    aiReady: ai.isReady(),
    today: new Date().toISOString().slice(0, 10),
  });
});

/** Le fichier d'origine, servi seulement si son empreinte correspond toujours. */
router.get('/:id/fichier', (req, res) => {
  const document = loadDocument(req, res);
  if (!document) return undefined;

  const integrity = intake.verify(document);
  if (!integrity.ok) {
    return res.status(409).render('error', { message: `Pièce non servie : ${integrity.reason}.` });
  }

  audit.log(req, 'pieces.telechargee', 'incoming_documents', document.id, {});
  res.setHeader('Content-Type', document.mime_type);
  res.setHeader('Content-Disposition', `inline; filename="${encodeURIComponent(document.original_name || 'piece')}"`);
  return res.send(fs.readFileSync(intake.pathOf(document)));
});

router.post('/:id/analyser', async (req, res) => {
  const document = loadDocument(req, res);
  if (!document) return undefined;

  const verdict = await intake.reanalyse(document.id);
  if (!verdict.ok) {
    setFlash(req, 'error', verdict.message);
    return res.redirect(`/pieces/${document.id}`);
  }

  audit.log(req, 'pieces.reanalysee', 'incoming_documents', document.id, { confiance: verdict.analysis.confidence });
  setFlash(req, 'success', `Nouvelle lecture : confiance ${verdict.analysis.confidence} %.`);
  return res.redirect(`/pieces/${document.id}`);
});

/**
 * Création de la facture depuis la pièce. Les champs proposés par l'analyse
 * ont été relus et, au besoin, corrigés à l'écran : c'est ce qui est validé
 * qui est enregistré, jamais ce qui a été lu.
 */
router.post('/:id/facturer', (req, res) => {
  const document = loadDocument(req, res);
  if (!document) return undefined;
  if (document.status === 'Facturée') {
    setFlash(req, 'error', 'Cette pièce a déjà donné lieu à une facture.');
    return res.redirect(`/pieces/${document.id}`);
  }

  const direction = (req.body.direction || '').trim();
  const label = (req.body.label || '').trim().slice(0, 160);
  const issueDate = (req.body.issue_date || '').trim();
  const dueDate = (req.body.due_date || '').trim();
  const amountHt = parseAmount(req.body.amount_ht);
  const vatRate = Number(req.body.vat_rate);
  const code = (req.body.currency || currency.base()).trim().toUpperCase();
  const partnerId = Number(req.body.partner_id) || null;
  const departmentId = Number(req.body.department_id) || null;

  const refuse = (message) => {
    setFlash(req, 'error', message);
    return res.redirect(`/pieces/${document.id}`);
  };

  if (!finance.INVOICE_DIRECTIONS.includes(direction)) return refuse('Sens de facture invalide.');
  if (!label) return refuse("L'intitulé est obligatoire.");
  if (!isValidDateString(issueDate)) return refuse("Date d'émission invalide.");
  if (dueDate && (!isValidDateString(dueDate) || dueDate < issueDate)) return refuse("Date d'échéance invalide.");
  if (!Number.isFinite(amountHt) || amountHt < 0) return refuse('Montant HT invalide.');
  if (!Number.isFinite(vatRate) || vatRate < 0 || vatRate > 100) return refuse('Taux de TVA invalide.');
  if (!currency.isKnown(code) || currency.rateOf(code) === null) return refuse(`Aucun taux connu pour ${code}.`);
  if (partnerId && !finance.partnerById(partnerId)) return refuse('Tiers introuvable.');
  if (departmentId && !org.departmentById(departmentId)) return refuse('Service introuvable.');

  const invoiceId = finance.createInvoice({
    direction,
    partnerId,
    departmentId,
    label,
    issueDate,
    dueDate: dueDate || null,
    amountHt: Math.round(amountHt * 100) / 100,
    vatRate,
    status: 'Émise',
    currency: code,
    reference: (req.body.reference || '').trim().slice(0, 60),
    notes: `Créée depuis la pièce reçue n° ${document.id}${document.mail_from ? ` (courriel de ${document.mail_from})` : ''}.`,
    createdBy: req.session.user.id,
  });
  if (!invoiceId) return refuse('Facture non créée : taux de change manquant.');

  intake.setStatus(document.id, 'Facturée', { userId: req.session.user.id, invoiceId });
  if (partnerId) intake.attachPartner(document.id, partnerId);

  audit.log(req, 'pieces.facturee', 'invoices', invoiceId, { piece: document.id, montant: amountHt, devise: code });
  setFlash(req, 'success', 'Facture créée depuis la pièce. La pièce reste attachée comme justificatif.');
  return res.redirect(`/pieces/${document.id}`);
});

router.post('/:id/ecarter', (req, res) => {
  const document = loadDocument(req, res);
  if (!document) return undefined;

  intake.setStatus(document.id, 'Écartée', {
    userId: req.session.user.id,
    note: (req.body.note || '').trim(),
  });
  audit.log(req, 'pieces.ecartee', 'incoming_documents', document.id, { motif: (req.body.note || '').slice(0, 120) });
  setFlash(req, 'success', 'Pièce écartée.');
  return res.redirect(back('traitees'));
});

router.post('/:id/supprimer', (req, res) => {
  const verdict = intake.remove(Number(req.params.id));
  if (!verdict.ok) return fail(req, res, 'traitees', verdict.message);

  audit.log(req, 'pieces.supprimee', 'incoming_documents', Number(req.params.id), {});
  setFlash(req, 'success', 'Pièce supprimée.');
  return res.redirect(back('traitees'));
});

// ---------- Capture de la boîte aux lettres ----------

router.post('/capture/reglages', (req, res) => {
  if (!isAdmin(req)) return fail(req, res, 'capture', "Les accès à la boîte aux lettres relèvent de l'administration.");

  const verdict = mailbox.setConfig({
    host: req.body.host,
    port: Number(req.body.port),
    secure: req.body.secure === '1',
    user: req.body.user,
    password: req.body.password,
    folder: req.body.folder,
    action: req.body.action,
    moveFolder: req.body.move_folder,
    sinceDays: Number(req.body.since_days),
    batch: Number(req.body.batch),
    allowSelfSigned: req.body.allow_self_signed === '1',
    enabled: req.body.enabled === '1',
  });
  if (!verdict.ok) return fail(req, res, 'capture', verdict.message);

  audit.log(req, 'pieces.capture_configuree', 'settings', null, { hote: req.body.host, actif: req.body.enabled === '1' });
  setFlash(req, 'success', 'Capture enregistrée.');
  return res.redirect(back('capture'));
});

router.post('/capture/tester', async (req, res) => {
  const verdict = await mailbox.test();
  audit.log(req, 'pieces.capture_testee', 'settings', null, { ok: verdict.ok });
  setFlash(req, verdict.ok ? 'success' : 'error', verdict.message);
  return res.redirect(back('capture'));
});

router.post('/capture/relever', async (req, res) => {
  const verdict = await mailbox.fetchOnce({ req });
  setFlash(req, verdict.ok ? 'success' : 'error', verdict.message);
  return res.redirect(back(verdict.ok && verdict.received ? 'a-traiter' : 'capture'));
});

// ---------- Analyse par modèle ----------

router.post('/analyse/reglages', (req, res) => {
  if (!isAdmin(req)) return fail(req, res, 'analyse', "L'envoi de documents à un service extérieur relève de l'administration.");

  const verdict = ai.setConfig({
    provider: req.body.provider,
    model: req.body.model,
    baseUrl: req.body.base_url,
    effort: req.body.effort,
    key: req.body.key,
    enabled: req.body.enabled === '1',
  });
  if (!verdict.ok) return fail(req, res, 'analyse', verdict.message);

  audit.log(req, 'pieces.analyse_configuree', 'settings', null, {
    service: req.body.provider, modele: req.body.model, actif: req.body.enabled === '1',
  });
  setFlash(req, 'success', req.body.enabled === '1'
    ? "Analyse activée : le texte des pièces sera envoyé au service choisi."
    : 'Réglages enregistrés. L\'analyse reste éteinte.');
  return res.redirect(back('analyse'));
});

router.post('/analyse/tester', async (req, res) => {
  const verdict = await ai.test();
  audit.log(req, 'pieces.analyse_testee', 'settings', null, { ok: verdict.ok });
  setFlash(req, verdict.ok ? 'success' : 'error', verdict.message);
  return res.redirect(back('analyse'));
});

module.exports = router;
