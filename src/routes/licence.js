const express = require('express');

const licence = require('../licence');
const assistanceEditeur = require('../assistance-editeur');
const settings = require('../settings');
const audit = require('../audit');
const db = require('../db');
const { requireAdmin } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();

router.use(requireAdmin);

/**
 * L'écran de licence.
 *
 * Il dit ce qui est couvert, jusqu'à quand, et ce qu'il faut faire quand ça ne
 * l'est plus. C'est aussi le seul endroit où l'empreinte de cette installation
 * se lit : le client la communique à l'éditeur pour obtenir un jeton hors
 * ligne, ou pour faire délier son ancienne machine après un déménagement.
 *
 * Il reste ouvert même quand l'instance est restreinte — c'est la sortie, on
 * ne la ferme pas.
 */

function screen(res) {
  const etat = licence.etat();
  const config = licence.config();
  return {
    title: res.locals.t('licence.title'),
    etat,
    empreinte: licence.empreinte(),
    // L'adresse publique sert au panneau à distinguer une instance qui revient
    // d'une copie posée sur un second serveur.
    adresse: config.adresse,
    dernierRefus: config.dernierRefus,
    derniereVerif: config.derniereVerif,
    editeurConfigure: Boolean(config.url && config.clef),
    effectif: db.prepare("SELECT COUNT(*) AS n FROM users WHERE active = 1 AND role <> 'admin'").get().n,
    // Ce que l'instance coûte par mois. Affiché, jamais facturé ici : elle
    // sait traduire son plafond en montant, rien de plus.
    prixMensuel: licence.prixMensuel(etat.plafondMembres || 0),
    gratuiteSieges: licence.GRATUITE_SIEGES,
    prixSalarie: licence.PRIX_SALARIE,

    /*
     * L'assistance de l'éditeur.
     *
     * Le dernier relevé, et rien de plus : aucun appel réseau sur un simple
     * affichage. Cet écran est la sortie de secours quand la licence coince, et
     * un panneau injoignable ne doit pas le faire attendre quinze secondes.
     */
    assistConfigure: assistanceEditeur.configure(),
    assistSujets: assistanceEditeur.SUJETS,
    assistReleve: assistanceEditeur.memoire(),
  };
}

/** Le refus dit en français, ou en la langue de l'écran. */
const direRefus = (res, verdict) => res.locals.t(`licence.assist.refus.${verdict.raison}`, {})
  || verdict.detail || verdict.raison;

router.get('/', (req, res) => {
  res.render('licence/index', screen(res));
});

/** Demander un bail tout de suite, sans attendre le balayage de la nuit. */
router.post('/verifier', async (req, res) => {
  const bail = await licence.renouveler();
  if (bail.ok) {
    setFlash(req, 'success', res.locals.t('licence.renewed'));
  } else {
    setFlash(req, 'error', bail.explication || res.locals.t(`licence.refus.${bail.raison}`, {}) || bail.raison);
  }
  res.redirect('/licence');
});

/** Poser un jeton hors ligne, collé à la main. */
router.post('/poser', (req, res) => {
  const pose = licence.poser(req.body.jeton || '');
  if (pose.ok) {
    setFlash(req, 'success', res.locals.t('licence.posed'));
  } else {
    setFlash(req, 'error', res.locals.t(`licence.refus.${pose.raison}`, {}) || pose.raison);
  }
  res.redirect('/licence');
});

/** L'adresse publique de cette instance, telle que le panneau la verra. */
router.post('/adresse', (req, res) => {
  settings.set('licence_adresse', String(req.body.adresse || '').trim().replace(/\/+$/, ''));
  setFlash(req, 'success', res.locals.t('licence.addressSaved'));
  res.redirect('/licence');
});


// ---------- Écrire à l'éditeur ----------

/*
 * Trois gestes, et un relevé.
 *
 * L'instance porte déjà la clé qui lui sert à obtenir son bail : c'est elle qui
 * l'authentifie auprès de l'éditeur. Aucun mot de passe de l'espace client ne
 * transite par le CMS, et l'administrateur n'a pas de second compte à tenir.
 *
 * Après chaque geste on relève : celui qui vient d'écrire veut voir son message
 * dans le fil, pas un écran inchangé qui laisse douter que l'envoi soit parti.
 */
async function releverEtRediriger(req, res, message) {
  const vu = await assistanceEditeur.relever();
  if (vu.ok) assistanceEditeur.memoriser(vu.demandes);
  if (message) setFlash(req, 'success', message);
  else if (!vu.ok) setFlash(req, 'error', direRefus(res, vu));
  return res.redirect('/licence#assistance');
}

router.post('/assistance/relever', async (req, res) => {
  const vu = await assistanceEditeur.relever();
  if (!vu.ok) {
    setFlash(req, 'error', direRefus(res, vu));
    return res.redirect('/licence#assistance');
  }
  assistanceEditeur.memoriser(vu.demandes);
  setFlash(req, 'success', res.locals.t('licence.assist.fetched', { n: vu.demandes.length }));
  return res.redirect('/licence#assistance');
});

router.post('/assistance/ouvrir', async (req, res) => {
  const verdict = await assistanceEditeur.ouvrir({
    sujet: req.body.sujet,
    type: req.body.type,
    corps: req.body.corps,
  });
  if (!verdict.ok) {
    setFlash(req, 'error', direRefus(res, verdict));
    return res.redirect('/licence#assistance');
  }
  audit.log(req, 'assistance.editeur_ouverte', 'settings', null, { reference: verdict.donnees.reference });
  return releverEtRediriger(req, res, res.locals.t('licence.assist.opened', {
    reference: verdict.donnees.reference,
  }));
});

router.post('/assistance/repondre', async (req, res) => {
  const verdict = await assistanceEditeur.repondre({
    reference: req.body.reference,
    corps: req.body.corps,
  });
  if (!verdict.ok) {
    setFlash(req, 'error', direRefus(res, verdict));
    return res.redirect('/licence#assistance');
  }
  audit.log(req, 'assistance.editeur_relancee', 'settings', null, { reference: req.body.reference });
  return releverEtRediriger(req, res, res.locals.t('licence.assist.replied'));
});

module.exports = router;
