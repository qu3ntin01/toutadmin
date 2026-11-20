const express = require('express');

const audit = require('../audit');
const societes = require('../societes');
const db = require('../db');
const { requireAuth } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();
router.use(requireAuth);

/**
 * Le périmètre : en changer, et l'administrer.
 *
 * Deux écrans en un seul routeur, et deux droits différents. Changer de société
 * est ouvert à qui en a plusieurs — c'est son travail quotidien. Les créer, les
 * rattacher et les fermer appartient à l'administration de l'instance : c'est
 * elle qui décide de la forme du groupe.
 */

const refus = (req, res, message, cible = '/admin#societes') => {
  setFlash(req, 'error', message);
  return res.redirect(cible);
};

function exigerAdmin(req, res, next) {
  if (!req.session.user || req.session.user.role !== 'admin') {
    return res.status(403).render('error', { message: "Les sociétés sont administrées par l'administration de l'instance." });
  }
  return next();
}

/**
 * Changer de périmètre.
 *
 * Hors du droit d'administration : un salarié rattaché à deux entités d'un groupe
 * passe de l'une à l'autre toute la journée. Le module refuse ce qui ne lui est
 * pas ouvert, et c'est la seule vérification qui compte ici.
 */
router.post('/choisir', (req, res) => {
  const voulue = Number(req.body.societe_id) || 0;
  if (!societes.choisir(req, voulue)) {
    return refus(req, res, res.locals.t('societes.err.fermee'), req.get('referer') || '/');
  }
  const societe = societes.parId(voulue);
  audit.log(req, 'societe.choisie', 'companies', societe.id, { nom: societe.name });
  return res.redirect(req.get('referer') || '/');
});

// ------------------------------------------------- administration des sociétés

router.post('/', exigerAdmin, (req, res) => {
  try {
    const societe = societes.creer({
      nom: req.body.nom,
      forme: req.body.forme,
      immatriculation: req.body.immatriculation,
      adresse: req.body.adresse,
    });
    audit.log(req, 'societe.creee', 'companies', societe.id, { nom: societe.name });
    setFlash(req, 'success', res.locals.t('societes.created', { nom: societe.name }));
  } catch (err) {
    return refus(req, res, res.locals.t(err.message));
  }
  return res.redirect('/admin#societes');
});

router.post('/:id/modifier', exigerAdmin, (req, res) => {
  try {
    const societe = societes.modifier(req.params.id, {
      nom: req.body.nom,
      forme: req.body.forme,
      immatriculation: req.body.immatriculation,
      adresse: req.body.adresse,
    });
    if (!societe) return refus(req, res, res.locals.t('societes.err.introuvable'));
    audit.log(req, 'societe.modifiee', 'companies', societe.id, { nom: societe.name });
    setFlash(req, 'success', res.locals.t('societes.updated'));
  } catch (err) {
    return refus(req, res, res.locals.t(err.message));
  }
  return res.redirect('/admin#societes');
});

router.post('/:id/etat', exigerAdmin, (req, res) => {
  const societe = societes.parId(req.params.id);
  if (!societe) return refus(req, res, res.locals.t('societes.err.introuvable'));

  const allumee = societe.active !== 1;
  const verdict = societes.basculer(societe.id, allumee);
  if (!verdict.ok) return refus(req, res, res.locals.t(verdict.raison));

  audit.log(req, allumee ? 'societe.rouverte' : 'societe.fermee', 'companies', societe.id, { nom: societe.name });
  setFlash(req, 'success', res.locals.t(allumee ? 'societes.reopened' : 'societes.closed', { nom: societe.name }));
  return res.redirect('/admin#societes');
});

router.post('/:id/membres', exigerAdmin, (req, res) => {
  const societe = societes.parId(req.params.id);
  if (!societe) return refus(req, res, res.locals.t('societes.err.introuvable'));

  const userId = Number(req.body.user_id) || 0;
  if (!societes.rattacher(societe.id, userId)) {
    return refus(req, res, res.locals.t('societes.err.personne'));
  }
  const personne = db.prepare('SELECT first_name, last_name FROM users WHERE id = ?').get(userId);
  audit.log(req, 'societe.rattachement', 'companies', societe.id, { user: userId });
  setFlash(req, 'success', res.locals.t('societes.attached', {
    nom: `${personne.first_name} ${personne.last_name}`, societe: societe.name,
  }));
  return res.redirect('/admin#societes');
});

router.post('/:id/membres/retirer', exigerAdmin, (req, res) => {
  const societe = societes.parId(req.params.id);
  if (!societe) return refus(req, res, res.locals.t('societes.err.introuvable'));

  const verdict = societes.detacher(societe.id, req.body.user_id);
  if (!verdict.ok) return refus(req, res, res.locals.t(verdict.raison));

  audit.log(req, 'societe.detachement', 'companies', societe.id, { user: Number(req.body.user_id) || 0 });
  setFlash(req, 'success', res.locals.t('societes.detached'));
  return res.redirect('/admin#societes');
});

module.exports = router;
