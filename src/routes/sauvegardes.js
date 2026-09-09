const fs = require('fs');
const multer = require('multer');
const express = require('express');

const audit = require('../audit');
const backup = require('../backup');
const offsite = require('../offsite');
const exporter = require('../export');
const security = require('../security');
const { requireAdmin } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();

// Une sauvegarde contient tout : empreintes de mots de passe, secrets de double
// authentification, bulletins de paie. Rien ici n'est ouvert au-delà de
// l'administration.
router.use(requireAdmin);

const back = (anchor) => `/sauvegardes#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

router.get('/', (req, res) => {
  // Retiré de la session avant le rendu, jamais après : res.render() peut
  // rendre la main une fois la réponse déjà envoyée, et express-session
  // décide alors d'enregistrer la session sans voir la suppression.
  const restoreReport = req.session.restoreReport || null;
  delete req.session.restoreReport;

  res.render('sauvegardes', {
    archives: backup.list(),
    summary: backup.summary(),
    directory: backup.BACKUP_DIR,
    maxUploadBytes: backup.MAX_UPLOAD_BYTES,
    destinations: offsite.list(),
    exportPreview: exporter.preview(),
    // Compte rendu de la dernière restauration, affiché une fois puis oublié.
    restoreReport,
  });
});

router.post('/', async (req, res) => {
  try {
    const created = await backup.create({ reason: 'manuelle', label: (req.body.label || '').trim() });
    const removed = backup.prune();
    audit.log(req, 'sauvegarde.creee', 'backups', null, { fichier: created.fileName, octets: created.bytes, purgees: removed.length });

    // Externalisation dans la foulée, s'il y a des destinations actives.
    let suffix = '';
    let anyFailure = false;
    if (offsite.enabled().length) {
      const target = backup.pathOf(created.fileName);
      const sent = await offsite.afterBackup(created.fileName, fs.readFileSync(target), { keep: backup.config().keep, req });
      const failures = sent.filter((r) => !r.ok);
      anyFailure = failures.length > 0;
      suffix = anyFailure
        ? ` Externalisation en échec vers ${failures.map((r) => r.key).join(', ')}.`
        : ` Déposée sur ${sent.length} destination(s) extérieure(s).`;
    }
    // La sauvegarde locale a réussi, mais un échec d'externalisation doit se
    // voir : c'est elle qui protège du serveur lui-même.
    setFlash(req, anyFailure ? 'error' : 'success', `Sauvegarde ${created.fileName} créée (${created.files} fichier(s)).${suffix}`);
  } catch (err) {
    audit.log(req, 'sauvegarde.echec', 'backups', null, { erreur: err.message });
    setFlash(req, 'error', `La sauvegarde a échoué : ${err.message}`);
  }
  res.redirect(back('archives'));
});

/** Copie hors ligne : c'est la seule protection contre la perte du serveur. */
router.get('/:fichier/telecharger', (req, res) => {
  const target = backup.pathOf(req.params.fichier);
  if (!target) return res.status(404).render('error', { message: 'Sauvegarde introuvable.' });

  audit.log(req, 'sauvegarde.telechargee', 'backups', null, { fichier: req.params.fichier });
  res.download(target, req.params.fichier);
});

router.get('/:fichier/verifier', (req, res) => {
  const verdict = backup.inspectFile(req.params.fichier);
  if (!verdict.ok) {
    setFlash(req, 'error', `${req.params.fichier} : ${verdict.message}`);
  } else {
    setFlash(req, 'success', `${req.params.fichier} : ${verdict.manifest.files.length} fichier(s) vérifié(s), archive intacte.`);
  }
  res.redirect(back('archives'));
});

router.post('/:fichier/supprimer', (req, res) => {
  if (!backup.remove(req.params.fichier)) return fail(req, res, 'archives', 'Sauvegarde introuvable.');
  audit.log(req, 'sauvegarde.supprimee', 'backups', null, { fichier: req.params.fichier });
  setFlash(req, 'success', 'Sauvegarde supprimée.');
  res.redirect(back('archives'));
});

// ---------- Restauration ----------

/**
 * Toute restauration commence par une sauvegarde de l'état actuel : se tromper
 * d'archive ne doit pas être définitif.
 */
async function applyRestore(req, res, buffer, origin) {
  let safety = null;
  try {
    safety = await backup.create({ reason: 'avant restauration', label: origin });
  } catch (err) {
    return fail(req, res, 'restauration', `Impossible de sauvegarder l'état actuel : ${err.message}. Restauration annulée.`);
  }

  let verdict;
  try {
    verdict = backup.restore(buffer, { by: req.currentUser.id });
  } catch (err) {
    verdict = { ok: false, message: err.message };
  }

  if (!verdict.ok) {
    audit.log(req, 'restauration.refusee', 'backups', null, { origine: origin, motif: verdict.message });
    return fail(req, res, 'restauration', `${verdict.message} Rien n'a été modifié.`);
  }

  audit.log(req, 'restauration.effectuee', 'backups', null, {
    origine: origin,
    sauvegarde_prealable: safety.fileName,
    tables: verdict.report.tables,
    lignes: verdict.report.rows,
    fichiers: verdict.report.files,
  });

  req.session.restoreReport = {
    origin,
    safety: safety.fileName,
    createdAt: verdict.manifest.createdAt,
    ...verdict.report,
  };
  setFlash(req, 'success', `Restauration effectuée depuis ${origin}. L'état précédent a été sauvegardé sous ${safety.fileName}.`);
  return res.redirect(back('restauration'));
}

router.post('/:fichier/restaurer', async (req, res) => {
  const target = backup.pathOf(req.params.fichier);
  if (!target) return fail(req, res, 'restauration', 'Sauvegarde introuvable.');

  // La confirmation demande le nom exact : on ne restaure pas d'un clic distrait.
  if ((req.body.confirmation || '').trim() !== req.params.fichier) {
    return fail(req, res, 'restauration', "Saisissez le nom exact de la sauvegarde pour confirmer la restauration.");
  }

  return applyRestore(req, res, fs.readFileSync(target), req.params.fichier);
});

// ---------- Restauration depuis une archive téléversée ----------

const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: backup.MAX_UPLOAD_BYTES, files: 1 },
}).single('archive');

function receiveArchive(req, res, next) {
  upload(req, res, (err) => {
    if (!err) return next();
    const message = err.code === 'LIMIT_FILE_SIZE'
      ? `Archive trop volumineuse : ${Math.round(backup.MAX_UPLOAD_BYTES / 1048576)} Mo maximum par téléversement. Déposez le fichier directement dans ${backup.BACKUP_DIR}.`
      : "Archive illisible.";
    fail(req, res, 'restauration', message);
  });
}

router.post('/televerser', ...security.upload(receiveArchive), async (req, res) => {
  if (!req.file) return fail(req, res, 'restauration', 'Aucune archive reçue.');
  if ((req.body.confirmation || '').trim().toUpperCase() !== 'RESTAURER') {
    return fail(req, res, 'restauration', "Saisissez « RESTAURER » pour confirmer.");
  }
  return applyRestore(req, res, req.file.buffer, req.file.originalname || 'archive téléversée');
});

// ---------- Externalisation ----------

router.post('/destinations/:key', (req, res) => {
  const destination = offsite.byKey(req.params.key);
  if (!destination) return fail(req, res, 'externalisation', 'Destination inconnue.');

  const verdict = offsite.setConfig(destination.key, req.body);
  if (!verdict.ok) return fail(req, res, 'externalisation', verdict.message);

  // Activer une destination mal renseignée donnerait une fausse assurance.
  const wanted = req.body.enabled === '1';
  if (wanted && !offsite.isConfigured(destination.key)) {
    offsite.setEnabled(destination.key, false);
    return fail(req, res, 'externalisation', `${destination.label} : renseignez les champs obligatoires avant de l'activer.`);
  }
  offsite.setEnabled(destination.key, wanted);

  audit.log(req, 'externalisation.configuree', 'settings', null, { destination: destination.key, active: wanted });
  setFlash(req, 'success', `${destination.label} enregistrée${wanted ? ' et activée' : ''}.`);
  res.redirect(back('externalisation'));
});

router.post('/destinations/:key/tester', async (req, res) => {
  const destination = offsite.byKey(req.params.key);
  if (!destination) return fail(req, res, 'externalisation', 'Destination inconnue.');

  const verdict = await offsite.test(destination.key);
  audit.log(req, 'externalisation.testee', 'settings', null, { destination: destination.key, ok: verdict.ok });
  setFlash(req, verdict.ok ? 'success' : 'error', `${destination.label} : ${verdict.message}`);
  res.redirect(back('externalisation'));
});

/** Renvoi manuel d'une archive : après une panne réseau, ou pour une reprise. */
router.post('/:fichier/externaliser', async (req, res) => {
  const target = backup.pathOf(req.params.fichier);
  if (!target) return fail(req, res, 'externalisation', 'Sauvegarde introuvable.');
  if (offsite.enabled().length === 0) return fail(req, res, 'externalisation', "Aucune destination extérieure n'est active.");

  const sent = await offsite.afterBackup(req.params.fichier, fs.readFileSync(target), { keep: backup.config().keep, req });
  const failures = sent.filter((r) => !r.ok);
  setFlash(req, failures.length ? 'error' : 'success', failures.length
    ? `Échec vers ${failures.map((r) => `${r.key} (${r.message})`).join(', ')}.`
    : `${req.params.fichier} déposé sur ${sent.length} destination(s).`);
  res.redirect(back('externalisation'));
});

// ---------- Export intégral ----------

/**
 * L'export part directement au navigateur, sans passer par le disque : il n'a
 * pas à laisser une copie de toute l'entreprise dans un dossier du serveur.
 */
router.post('/export', (req, res) => {
  const archive = exporter.build();
  audit.log(req, 'export.integral', 'instance', null, {
    fichier: archive.fileName, tables: archive.tables, lignes: archive.rows, fichiers: archive.files, octets: archive.bytes,
  });

  res.setHeader('Content-Type', 'application/gzip');
  res.setHeader('Content-Disposition', `attachment; filename="${archive.fileName}"`);
  res.send(archive.buffer);
});

// ---------- Réglages ----------

router.post('/reglages', (req, res) => {
  const interval = Number(req.body.interval_minutes);
  const keep = Number(req.body.keep);
  if (!Number.isInteger(interval) || interval < 15 || interval > 1440) {
    return fail(req, res, 'reglages', "L'intervalle doit tenir entre 15 minutes et 24 heures.");
  }
  if (!Number.isInteger(keep) || keep < 2 || keep > 500) {
    return fail(req, res, 'reglages', 'Le nombre de sauvegardes conservées doit tenir entre 2 et 500.');
  }

  backup.setConfig({ enabled: req.body.enabled === '1', intervalMinutes: interval, keep });
  const removed = backup.prune();
  audit.log(req, 'sauvegarde.reglages', 'settings', null, { actif: req.body.enabled === '1', intervalle: interval, conservees: keep });
  setFlash(req, 'success', `Réglages enregistrés.${removed.length ? ` ${removed.length} archive(s) au-delà du seuil supprimée(s).` : ''}`);
  res.redirect(back('reglages'));
});

module.exports = router;
