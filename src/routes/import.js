const express = require('express');
const multer = require('multer');

const audit = require('../audit');
const importer = require('../importer');
const security = require('../security');
const { requireAuth } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();

router.use(requireAuth);

// Importer, c'est écrire en masse : l'accès suit le droit qu'on aurait besoin
// d'avoir pour saisir ces lignes une par une.
router.use((req, res, next) => {
  const user = req.currentUser || req.session.user;
  if (user.role === 'admin' || user.is_finance) return next();
  return res.status(403).render('error', { message: "L'import de données est réservé à l'administration et à la gestion." });
});

function allowed(req, key) {
  const user = req.currentUser || req.session.user;
  return importer.availableFor(user).find((entity) => entity.key === key) || null;
}

function render(req, res, extra = {}) {
  const user = req.currentUser || req.session.user;
  res.render('import', {
    entities: importer.availableFor(user).map((entity) => ({
      key: entity.key,
      label: entity.label,
      hint: entity.hint,
      columns: entity.columns,
      template: importer.template(entity),
    })),
    maxBytes: importer.MAX_BYTES,
    preview: null,
    content: '',
    selected: '',
    result: null,
    ...extra,
  });
}

router.get('/', (req, res) => render(req, res));

/** Le modèle : les en-têtes attendus, dans l'ordre, prêts à remplir au tableur. */
router.get('/:key/modele.csv', (req, res) => {
  const entity = allowed(req, req.params.key);
  if (!entity) return res.status(404).render('error', { message: 'Type de données inconnu.' });

  res.setHeader('Content-Type', 'text/csv; charset=utf-8');
  res.setHeader('Content-Disposition', `attachment; filename="modele-${entity.key}.csv"`);
  // La marque d'ordre des octets évite qu'Excel n'affiche les accents en charabia.
  res.send(`﻿${importer.template(entity)}\n`);
});

const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: importer.MAX_BYTES, files: 1 },
}).single('fichier');

function receiveFile(req, res, next) {
  upload(req, res, (err) => {
    if (!err) return next();
    setFlash(req, 'error', err.code === 'LIMIT_FILE_SIZE'
      ? `Fichier trop volumineux : ${Math.round(importer.MAX_BYTES / 1048576)} Mo maximum.`
      : 'Fichier illisible.');
    res.redirect('/import');
  });
}

/** Aperçu : tout est contrôlé, rien n'est écrit. */
router.post('/apercu', ...security.upload(receiveFile), (req, res) => {
  const entity = allowed(req, (req.body.entity || '').trim());
  if (!entity) {
    setFlash(req, 'error', 'Type de données inconnu.');
    return res.redirect('/import');
  }

  const content = req.file ? req.file.buffer.toString('utf8') : String(req.body.content || '');
  if (!content.trim()) {
    setFlash(req, 'error', 'Déposez un fichier ou collez son contenu.');
    return res.redirect('/import');
  }
  if (Buffer.byteLength(content, 'utf8') > importer.MAX_BYTES) {
    setFlash(req, 'error', `Contenu trop volumineux : ${Math.round(importer.MAX_BYTES / 1048576)} Mo maximum.`);
    return res.redirect('/import');
  }

  // Le message est rendu avec la page, pas déposé en session : la page suivante
  // n'a pas à le répéter.
  const verdict = importer.preview(entity.key, content);
  return render(req, res, {
    preview: verdict.ok ? verdict : null,
    content,
    selected: entity.key,
    flash: verdict.ok ? null : { type: 'error', message: verdict.message },
  });
});

/** Écriture : tout ou rien, dans une seule transaction. */
router.post('/importer', (req, res) => {
  const entity = allowed(req, (req.body.entity || '').trim());
  if (!entity) {
    setFlash(req, 'error', 'Type de données inconnu.');
    return res.redirect('/import');
  }

  const content = String(req.body.content || '');
  const verdict = importer.commit(entity.key, content);
  if (!verdict.ok) {
    audit.log(req, 'import.refuse', entity.key, null, { motif: verdict.message });
    return render(req, res, {
      preview: verdict.preview || null,
      content,
      selected: entity.key,
      flash: { type: 'error', message: verdict.message },
    });
  }

  audit.log(req, 'import.realise', entity.key, null, { lignes: verdict.imported });
  return render(req, res, {
    selected: entity.key,
    result: verdict,
    flash: { type: 'success', message: `${verdict.imported} ligne(s) importée(s) dans « ${entity.label} ».` },
  });
});

module.exports = router;
