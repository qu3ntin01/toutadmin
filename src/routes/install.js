const express = require('express');
const bcrypt = require('bcryptjs');

const db = require('../db');
const install = require('../install');
const settings = require('../settings');
const secrets = require('../secret-store');
const licence = require('../licence');
const i18n = require('../i18n');
const reglages = require('../reglages');
const sections = require('../sections');
const sectionsReglages = require('../sections-reglages');
const { setFlash, isValidEmail } = require('../utils');

const router = express.Router();

/**
 * L'assistant d'installation, en six étapes.
 *
 * Langue, prérequis, base de données, organisation, administrateur, puis la
 * pose. L'étape « base » ne propose qu'un moteur dans cette édition — et le
 * dit, plutôt que de faire disparaître le choix.
 *
 * La dernière étape ne fait pas semblant : elle appelle quatre fois le
 * serveur, une par palier réellement accompli. Une barre qui avance toute
 * seule pendant qu'on attend est un mensonge poli, et elle ment surtout au
 * moment où elle devrait alerter.
 */
const STEPS = ['langue', 'prerequis', 'mises-a-jour', 'licence', 'base', 'entreprise',
  'sections', 'administrateur', 'installation'];
const STAGES = ['controle', 'schema', 'instance', 'configuration'];
const MIN_PASSWORD_LENGTH = 12;
const { version: APP_VERSION } = require('../../package.json');

function draft(req) {
  if (!req.session.install) req.session.install = {};
  return req.session.install;
}

/** Jusqu'où l'on a le droit d'aller : on ne saute pas d'étape. */
function reached(data) {
  if (!data.locale) return 'langue';
  if (!data.checksPassed) return 'prerequis';
  /*
   * L'étape des mises à jour se franchit de deux façons : l'instance est déjà
   * à jour, ou l'éditeur n'a pas pu être joint. Le second cas passe
   * volontairement : un client derrière un pare-feu d'entreprise, sur un
   * réseau sans sortie, ou pendant une panne de notre côté, doit pouvoir
   * installer son CMS. Transformer une panne de réseau en produit
   * inutilisable serait une faute bien plus grave que de laisser poser une
   * version d'un mois.
   */
  if (!data.majVue) return 'mises-a-jour';
  // « licenceVue » et non « licenceKey » : continuer sans licence est un choix
  // qu'on peut faire, et il ne doit pas renvoyer indéfiniment sur cette étape.
  if (!data.licenceVue) return 'licence';
  if (!data.engine) return 'base';
  if (!data.companyName) return 'entreprise';
  // « sectionsVues » et non « sections » : ne retenir que « Réglages » est un
  // choix parfaitement légitime, et le tableau est alors vide. Se fier à sa
  // longueur renverrait indéfiniment sur cette étape celui qui a tout décoché.
  if (!data.sectionsVues) return 'sections';
  if (!data.adminEmail) return 'administrateur';
  return 'installation';
}

function render(req, res, step, extra = {}) {
  const data = draft(req);

  /*
   * L'étape « Licence » ouvre une fenêtre sur l'espace client, et la clé
   * revient par « postMessage ».
   *
   * Or l'application entière est servie avec « Cross-Origin-Opener-Policy:
   * same-origin », ce qui coupe toute fenêtre d'une autre origine de celle qui
   * l'a ouverte : la fenêtre s'ouvre bien, le client choisit bien sa licence,
   * « window.opener » est nul et rien ne rentre. Le symptôme est déroutant,
   * parce que tout a l'air de marcher jusqu'au dernier instant.
   *
   * « same-origin-allow-popups » lève exactement ce qu'il faut et rien de plus :
   * cette page reste isolée de ce qui l'ouvrirait, elle, et garde seulement le
   * lien vers ce qu'elle ouvre. L'assouplissement ne vaut que pour cette étape.
   */
  if (step === 'licence') {
    res.set('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');
  }
  res.render('install', {
    step,
    steps: STEPS,
    stepIndex: STEPS.indexOf(step),
    stages: STAGES,
    draft: data,
    // Les contrôles remontent des clés : c'est ici, où la langue est connue,
    // qu'ils deviennent des phrases.
    checks: step === 'prerequis'
      ? install.runChecks().map((check) => install.translateCheck(check, res.locals.t))
      : [],
    /*
     * Les sections proposées à l'installation, chacune avec ce qu'elle
     * contient : choisir « Conformité » sans savoir qu'on y trouve le RGPD et
     * le parapheur, c'est cocher au hasard. Les obligatoires sont montrées
     * aussi, cochées et verrouillées — les cacher ferait croire qu'on installe
     * six sections quand on en installe sept.
     */
    sectionsOffertes: sections.GROUPES.map((groupe) => ({
      cle: groupe.cle,
      titre: groupe.titre,
      icone: groupe.icone,
      obligatoire: sectionsReglages.OBLIGATOIRES.includes(groupe.cle),
      services: sections.servicesDe(groupe.cle),
      // Cochée par défaut au premier passage : une entreprise qui ne sait pas
      // encore ce dont elle aura besoin doit repartir avec le produit entier,
      // pas avec une coquille. Retirer se fait en une case ; découvrir qu'il
      // manque un métier six mois plus tard coûte bien davantage.
      retenue: data.sections ? data.sections.includes(groupe.cle) : true,
    })),
    engines: install.engines(),
    databaseFile: install.databaseFile(),
    tokenRequired: install.tokenRequired(),
    licenceDistribuee: licence.distribuee(),
    // L'adresse du service de licences est connue : le champ arrive rempli plutôt
    // que vide. La retrouver et la recopier sans faute n'apprend rien à
    // personne, et une faute de frappe y ressemble à une panne de réseau.
    editeurParDefaut: settings.adresseEditeur(),
    /*
     * Où le client va chercher sa licence, et où il lit les offres. Les deux
     * adresses sont connues et ne se demandent pas — elles sont les mêmes pour
     * toutes les instances. Posées et vides, elles retirent le lien
     * correspondant : une instance fermée n'offre pas de porte vers dehors.
     */
    managerUrl: settings.adresseManager(),
    siteUrl: settings.adresseSite(),
    version: APP_VERSION,
    ...extra,
  });
}

// L'assistant se referme définitivement dès que l'instance est installée — à
// une exception près : le dernier palier, qui pose le verrou et vient donc
// forcément après la création de l'administrateur.
router.use((req, res, next) => {
  const finishing = req.method === 'POST'
    && req.path.startsWith('/executer/')
    && Boolean(req.session.install && req.session.install.adminEmail);
  if (install.isInstalled() && !finishing) return res.redirect('/connexion');
  next();
});

router.get('/', (req, res) => res.redirect(`/install/${reached(draft(req))}`));

router.get('/:step', (req, res, next) => {
  const { step } = req.params;
  if (!STEPS.includes(step)) return next();

  const permitted = reached(draft(req));
  if (STEPS.indexOf(step) > STEPS.indexOf(permitted)) {
    return res.redirect(`/install/${permitted}`);
  }
  render(req, res, step);
});

router.post('/langue', (req, res) => {
  const locale = (req.body.locale || '').trim();
  if (i18n.isSupported(locale)) {
    draft(req).locale = locale;
    res.cookie('locale', locale, { httpOnly: true, sameSite: 'lax', maxAge: 1000 * 60 * 60 * 24 * 365 });
  }
  res.redirect('/install/prerequis');
});

/**
 * L'étape « Licence ».
 *
 * Rien n'est activé ici : la clé est mise de côté dans le brouillon, et
 * l'activation attend le dernier palier, quand la base existe enfin pour porter
 * l'empreinte de l'installation. Valider une clé maintenant obligerait à lier
 * l'instance avant même qu'elle existe, et une installation abandonnée en cours
 * de route consommerait la licence d'un client.
 */
/**
 * Éprouver la licence saisie, sans rien engager.
 *
 * Répond en JSON : le navigateur affiche le résultat sans quitter la page, et
 * « Suivant » ne se déverrouille qu'après un « oui ». Rien n'est lié ici — ni
 * côté panneau, où la route d'essai n'active aucune licence, ni côté instance,
 * qui n'existe pas encore. On peut donc presser le bouton autant de fois qu'on
 * veut ; une installation abandonnée ne consomme la licence de personne.
 */
async function eprouverLicence({ cle, jeton, url }) {
  // Le jeton hors ligne se vérifie ici même : sa signature est celle de
  // l'éditeur, et la clé publique est dans le code. Aucun réseau nécessaire —
  // c'est tout l'intérêt du mode hors ligne.
  if (jeton) {
    const charge = licence.relire(jeton);
    if (charge === null) return { ok: false, raison: 'signature' };
    if (charge.edition !== licence.EDITION) return { ok: false, raison: 'autre-edition' };
    if ((Date.parse(charge.abonnement) || 0) <= Date.now()) {
      return { ok: false, raison: 'echue' };
    }
    return {
      ok: true, mode: 'hors-ligne', offre: charge.offre || '',
      membres: Number(charge.membres) || 0, echeance: charge.abonnement || '',
    };
  }

  if (!cle) return { ok: false, raison: 'vide' };
  if (!url) return { ok: false, raison: 'sans-panneau' };

  let reponse;
  let brut = '';
  try {
    reponse = await fetch(`${url}/api/v1/licence/verifier`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Cle-Instance': cle },
      body: JSON.stringify({ edition: licence.EDITION }),
      signal: AbortSignal.timeout(15000),
    });
    brut = await reponse.text();
  } catch (erreur) {
    return { ok: false, injoignable: true, raison: 'reseau', detail: String(erreur.message || erreur) };
  }

  /*
   * Une réponse qui n'est pas du JSON n'est pas un refus de licence : c'est
   * quelque chose qui s'est mis en travers. Un pare-feu applicatif qui sert un
   * défi JavaScript, un proxy d'entreprise, une page de maintenance, un
   * hébergeur qui redirige — tous rendent du HTML avec un code quelconque.
   *
   * Les confondre est le pire service qu'on puisse rendre : le client lit
   * « licence refusée », appelle son éditeur, et cherche du mauvais côté
   * pendant une heure. On dit donc ce qu'on a reçu — le code, le type — pour
   * que la cause se voie du premier coup d'œil.
   */
  let corps = null;
  try {
    corps = JSON.parse(brut);
  } catch {
    corps = null;
  }
  if (corps === null || typeof corps !== 'object') {
    return {
      ok: false,
      injoignable: true,
      raison: 'reponse-illisible',
      statut: reponse.status,
      type: (reponse.headers.get('content-type') || '').split(';')[0],
      detail: brut.slice(0, 200),
    };
  }

  if (!reponse.ok) {
    return {
      ok: false,
      raison: String(corps.erreur || reponse.status),
      // Le panneau nomme son refus en clair : on le montre tel quel plutôt que
      // de laisser deviner pourquoi une clé qu'on vient de payer est refusée.
      explication: String(corps.explication || ''),
    };
  }
  const dit = corps.licence || {};
  return {
    ok: true, mode: dit.mode || 'en-ligne', offre: dit.offre || '',
    membres: Number(dit.membres) || 0, echeance: dit.echeance || '', liee: Boolean(dit.liee),
  };
}

/**
 * Vérifier sans engager. Répond en JSON : le navigateur montre le résultat sans
 * quitter la page.
 *
 * Elle est appelée dès qu'une clé est posée dans le champ, collée à la main ou
 * rapportée par la fenêtre de l'espace client — il n'y a plus de bouton à
 * presser. Cela n'engage rien : la route d'essai du panneau ne lie aucune
 * instance, et elle peut donc repartir autant de fois qu'il le faut.
 */
router.post('/licence/verifier', async (req, res) => {
  res.json(await eprouverLicence({
    cle: String(req.body.licence_cle || '').trim(),
    jeton: String(req.body.licence_jeton || '').trim(),
    url: settings.adresseEditeur(),
  }));
});

router.post('/licence', async (req, res) => {
  const data = draft(req);

  if (req.body.passer === '1') {
    data.licenceVue = true;
    // Continuer sans licence : l'instance s'installe et s'activera depuis
    // l'écran « Licence ». Rien n'est restreint tant qu'elle ne l'est pas.
    delete data.licenceKey;
    delete data.licenceJeton;
    delete data.licenceVerifiee;
    delete data.licenceMembres;
    return res.redirect('/install/base');
  }

  data.licenceKey = String(req.body.licence_cle || '').trim();
  data.licenceJeton = String(req.body.licence_jeton || '').trim();
  data.licenceAdresse = String(req.body.licence_adresse || '').trim().replace(/\/+$/, '');

  if (!data.licenceKey && !data.licenceJeton) {
    setFlash(req, 'error', res.locals.t('install.error.licenceEmpty'));
    return res.redirect('/install/licence');
  }

  /*
   * La même vérification qu'au bouton, refaite ici. Le bouton grisé est du
   * confort : il dit à l'utilisateur où il en est. Ce n'est pas un contrôle —
   * un attribut « disabled » s'enlève en trois secondes dans un navigateur, et
   * un formulaire s'envoie sans navigateur du tout. Le contrôle est celui-ci,
   * et il est du côté qui décide.
   */
  const essai = await eprouverLicence({
    cle: data.licenceKey,
    jeton: data.licenceJeton,
    url: settings.adresseEditeur(),
  });
  if (!essai.ok) {
    data.licenceVerifiee = false;
    setFlash(req, 'error', res.locals.t('install.error.licenceInvalid', {
      raison: essai.explication || essai.detail || essai.raison || '',
    }));
    return res.redirect('/install/licence');
  }

  // Marquées vues et vérifiées seulement ici : une saisie refusée ne doit pas
  // déverrouiller l'étape suivante, sinon le contrôle ne sert à rien. Le
  // nombre de salariés couverts est retenu pour qu'un retour en arrière
  // retrouve l'écran tel qu'on l'avait laissé.
  data.licenceVue = true;
  data.licenceVerifiee = true;
  data.licenceMembres = Number(essai.membres) || 0;
  res.redirect('/install/base');
});

router.post('/prerequis', (req, res) => {
  if (install.tokenRequired() && !install.tokenMatches(req.body.install_token)) {
    setFlash(req, 'error', res.locals.t('install.error.token'));
    return res.redirect('/install/prerequis');
  }

  const blocking = install.runChecks()
    .filter((check) => check.blocking && !check.ok)
    .map((check) => install.translateCheck(check, res.locals.t));
  if (blocking.length > 0) {
    setFlash(req, 'error', res.locals.t('install.error.requirements', {
      list: blocking.map((c) => c.label).join(', '),
    }));
    return res.redirect('/install/prerequis');
  }

  draft(req).checksPassed = true;
  res.redirect('/install/mises-a-jour');
});

/**
 * L'étape « Mises à jour ».
 *
 * Elle regarde s'il existe plus récent, et ne laisse pas passer tant que ce
 * n'est pas posé. Savoir ne demande aucune clé — le numéro de la dernière
 * version est écrit sur la page de téléchargement du site ; télécharger en
 * demande une, et l'écran ne la réclame donc qu'une fois qu'on sait qu'elle
 * va servir.
 */
router.post('/mises-a-jour/verifier', async (req, res) => {
  res.json(await install.versionDisponible({ url: settings.adresseEditeur() }));
});

/**
 * Poser la dernière version, tout seul. Le serveur doit redémarrer ensuite :
 * les fichiers viennent de changer sous ses pieds, et il tourne encore sur les
 * anciens.
 */
router.post('/mises-a-jour/poser', async (req, res) => {
  const cle = String(req.body.licence_cle || '').trim();
  const pose = await install.poserLaMiseAJour({ url: settings.adresseEditeur(), cle });

  if (pose.ok) {
    const data = draft(req);
    // La clé a servi ici : elle est reportée à l'étape « Licence », qui n'a
    // donc pas à la redemander. Et l'étape des mises à jour repart à zéro —
    // la version posée n'est pas encore celle qui tourne.
    data.licenceKey = cle;
    delete data.majVue;
  }
  res.json(pose);
});

router.post('/mises-a-jour', async (req, res) => {
  const data = draft(req);

  /*
   * Le contrôle est refait ici, côté serveur : le bouton grisé est du confort,
   * et un formulaire s'envoie sans navigateur. Une version en retard ne passe
   * pas ; un éditeur injoignable passe, avec la trace de ce qui s'est produit.
   */
  const vu = await install.versionDisponible({ url: settings.adresseEditeur() });
  if (vu.ok && !vu.aJour) {
    setFlash(req, 'error', res.locals.t('install.error.majRequise', {
      derniere: vu.derniere, courante: vu.courante,
    }));
    return res.redirect('/install/mises-a-jour');
  }

  data.majVue = true;
  data.majEtat = vu.ok ? 'a-jour' : String(vu.raison || 'injoignable');
  res.redirect('/install/licence');
});

router.post('/base', (req, res) => {
  const engine = install.engines().find((e) => e.key === req.body.db_driver && e.available);
  if (!engine) {
    setFlash(req, 'error', res.locals.t('install.db.noDriver'));
    return res.redirect('/install/base');
  }

  if (engine.key !== 'mysql') {
    Object.assign(draft(req), { engine: 'sqlite' });
    return res.redirect('/install/entreprise');
  }

  const champs = {
    host: (req.body.db_host || '').trim(),
    port: Number(req.body.db_port) || 3306,
    name: (req.body.db_name || '').trim(),
    user: (req.body.db_user || '').trim(),
    password: req.body.db_password || '',
  };

  // On essaie avant de s'engager : une adresse qui ne répond pas doit se dire
  // ici, et non trois étapes plus loin.
  const essai = install.tryMysql(champs, res.locals.t);
  if (!essai.ok) {
    Object.assign(draft(req), {
      engine: null, dbHost: champs.host, dbPort: champs.port, dbName: champs.name, dbUser: champs.user,
    });
    setFlash(req, 'error', res.locals.t('install.db.failed', { message: essai.message }));
    return res.redirect('/install/base');
  }

  Object.assign(draft(req), {
    engine: 'mysql',
    dbHost: champs.host, dbPort: champs.port, dbName: champs.name,
    dbUser: champs.user, dbPassword: champs.password,
  });
  res.redirect('/install/entreprise');
});

/**
 * L'étape « Sections ».
 *
 * Ce que le client coche ici décide de ce que son rail portera. Rien n'est
 * définitif : une section se repose ensuite depuis la console, et ses données
 * l'attendent — retirer une section ne supprime rien.
 *
 * Le choix n'est pas écrit maintenant : il dort dans le brouillon jusqu'au
 * dernier palier, comme la licence et le compte administrateur. Sur MySQL, la
 * base qui le recevra n'est même pas encore celle de ce processus.
 */
router.post('/sections', (req, res) => {
  // Une seule case cochée arrive comme une chaîne, plusieurs comme un tableau :
  // c'est ainsi que les formulaires s'envoient, et le lire d'une seule façon
  // ferait perdre le choix de qui n'en retient qu'une.
  const brut = req.body.sections === undefined ? [] : [].concat(req.body.sections);
  const retenues = brut.map(String).filter((cle) => sections.GROUPES.some((g) => g.cle === cle));

  Object.assign(draft(req), {
    sections: [...new Set([...retenues, ...sectionsReglages.OBLIGATOIRES])],
    sectionsVues: true,
  });
  res.redirect('/install/administrateur');
});

router.post('/entreprise', (req, res) => {
  const companyName = (req.body.company_name || '').trim().slice(0, 120);
  const leaveDays = Number(String(req.body.annual_leave_days || '').replace(',', '.'));

  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect('/install/entreprise');
  };

  if (!companyName) return fail(res.locals.t('install.error.company'));
  if (!Number.isFinite(leaveDays) || leaveDays < 0 || leaveDays > 365) {
    return fail(res.locals.t('install.error.leave'));
  }

  Object.assign(draft(req), { companyName, leaveDays });
  res.redirect('/install/sections');
});

router.post('/administrateur', (req, res) => {
  const firstName = (req.body.first_name || '').trim().slice(0, 100);
  const lastName = (req.body.last_name || '').trim().slice(0, 100);
  const email = (req.body.email || '').toLowerCase().trim().slice(0, 254);
  const password = req.body.password || '';
  const confirm = req.body.confirm_password || '';

  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect('/install/administrateur');
  };

  if (!firstName || !lastName) return fail(res.locals.t('install.error.names'));
  if (!isValidEmail(email)) return fail(res.locals.t('install.error.email'));
  if (password.length < MIN_PASSWORD_LENGTH) {
    return fail(res.locals.t('install.error.password', { min: MIN_PASSWORD_LENGTH }));
  }
  if (password !== confirm) return fail(res.locals.t('install.error.confirm'));

  Object.assign(draft(req), {
    adminFirstName: firstName, adminLastName: lastName, adminEmail: email, adminPassword: password,
  });
  res.redirect('/install/installation');
});

/**
 * Un palier de la pose.
 *
 * Quatre appels, quatre choses faites, dans l'ordre du risque : on vérifie, on
 * compte le schéma, on ouvre l'instance, et on pose le verrou en dernier. Tant
 * que ce dernier palier n'a pas eu lieu, une installation interrompue n'a rien
 * fermé : l'assistant se réaffiche.
 */
/**
 * Exécute un travail sur la base visée, puis la referme si elle n'est pas celle
 * de l'instance en cours.
 *
 * Sur MySQL, l'assistant pose le schéma et crée le compte dans une base que ce
 * processus n'utilise pas encore : il tourne encore sur celle qui l'a porté
 * jusqu'ici. Elle ne deviendra la sienne qu'au redémarrage, une fois les
 * réglages écrits.
 */
function surLaCible(data, travail) {
  const cible = install.targetDatabase(data);
  try {
    return travail(cible);
  } finally {
    if (cible !== db && cible.close) cible.close();
  }
}

/**
 * Poser la licence de cette instance, au sortir de l'installation.
 *
 * Rend une phrase courte pour l'écran final — « activée », « posée », ou le
 * motif du refus — et ne jette jamais : une installation ne doit pas échouer
 * parce qu'un service de licences était injoignable. L'instance s'installe, et son
 * écran « Licence » dira quoi faire.
 */
async function poserLaLicence(data) {
  if (!data.licenceKey && !data.licenceJeton) return { fait: false, raison: 'aucune' };

  const valeurs = {};
  if (data.licenceAdresse) valeurs.licence_adresse = data.licenceAdresse;
  // La clé ouvre la licence et les mises à jour : une seule chez le client.
  if (data.licenceKey) valeurs.update_instance_key = secrets.encrypt(data.licenceKey);
  if (Object.keys(valeurs).length > 0) settings.setMany(valeurs);

  try {
    if (data.licenceJeton) {
      const pose = licence.poser(data.licenceJeton);
      return { fait: pose.ok, raison: pose.ok ? 'posee' : pose.raison };
    }
    const bail = await licence.renouveler();
    return { fait: bail.ok, raison: bail.ok ? 'activee' : bail.raison };
  } catch (erreur) {
    return { fait: false, raison: 'reseau', detail: String(erreur.message || erreur) };
  }
}

router.post('/executer/:stage', async (req, res) => {
  const data = draft(req);
  const { t } = res.locals;

  if (!data.companyName || !data.adminEmail || !data.adminPassword) {
    return res.status(409).json({ ok: false, message: t('install.error.incomplete') });
  }

  try {
    if (req.params.stage === 'controle') {
      const blocking = install.runChecks()
        .filter((check) => check.blocking && !check.ok)
        .map((check) => install.translateCheck(check, t));
      if (blocking.length > 0) {
        return res.status(500).json({
          ok: false,
          message: t('install.error.requirements', { list: blocking.map((c) => c.label).join(', ') }),
        });
      }
      if (install.hasAdmin()) {
        return res.status(409).json({ ok: false, message: t('install.error.adminExists') });
      }
      if (data.engine === 'mysql') {
        const essai = install.tryMysql(
          { host: data.dbHost, port: data.dbPort, name: data.dbName, user: data.dbUser, password: data.dbPassword },
          t
        );
        if (!essai.ok) {
          return res.status(500).json({ ok: false, message: t('install.db.failed', { message: essai.message }) });
        }
      }
      return res.json({ ok: true, message: t('install.run.controlDone') });
    }

    if (req.params.stage === 'schema') {
      // Sur SQLite, le schéma de cette instance est déjà posé : ce palier le
      // constate. Sur MySQL, la base vient d'être désignée et n'a rien : on y
      // rejoue les mêmes scripts, ceux que db.js a posés ici.
      return surLaCible(data, (cible) => {
        if (data.engine === 'mysql') for (const script of db.scripts) cible.poserLeSchema(script);
        return res.json({
          ok: true,
          message: t('install.run.schemaDone', { count: cible === db ? install.tableCount() : cible.tables().length }),
        });
      });
    }

    if (req.params.stage === 'instance') {
      if (install.hasAdmin()) {
        return res.status(409).json({ ok: false, message: t('install.error.adminExists') });
      }
      const locale = i18n.isSupported(data.locale) ? data.locale : i18n.DEFAULT_LOCALE;
      const passwordHash = bcrypt.hashSync(data.adminPassword, 12);

      return surLaCible(data, (cible) => {
        cible.transaction(() => {
          cible.prepare(`
            INSERT INTO users (role, email, password_hash, first_name, last_name, grade, locale, active)
            VALUES ('admin', ?, ?, ?, ?, 'Direction', ?, 1)
          `).run(data.adminEmail, passwordHash, data.adminFirstName, data.adminLastName, locale);

          // Écrit ici plutôt que par settings.setMany : ce module parle à la
          // base de l'instance en cours, et la cible peut être une autre.
          const poserReglage = cible.prepare(`
            INSERT INTO settings (key, value) VALUES (?, ?)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value
          `);
          /*
           * La première société, posée en même temps que le compte
           * d'administration : c'est le périmètre de tout ce qui sera saisi
           * ensuite, et une instance sans société n'afficherait rien.
           */
          cible.prepare('INSERT INTO companies (name) VALUES (?)').run(data.companyName);
          cible.prepare(`
            INSERT INTO company_members (company_id, user_id)
            SELECT (SELECT id FROM companies ORDER BY id LIMIT 1), id FROM users
          `).run();

          poserReglage.run('company_name', data.companyName);
          poserReglage.run('default_locale', locale);
          poserReglage.run('annual_leave_days', String(data.leaveDays ?? 25));

          /*
           * Les sections retenues. On écrit « 0 » pour les refusées plutôt que
           * « 1 » pour les gardées : l'absence de réglage vaut « installée »,
           * ce qui laisse les instances posées avant cette version avec tout
           * ce qu'elles avaient.
           *
           * Et le témoin de rattrapage, posé ici : cette instance est née avec
           * les sections, ses assistants doivent s'ouvrir à la première
           * ouverture de chaque métier. Sans lui, le démarrage suivant la
           * prendrait pour une instance déjà configurée.
           */
          const voulues = new Set(data.sections || sectionsReglages.CLES);
          for (const cle of sectionsReglages.CLES) {
            poserReglage.run(`section.${cle}.active`,
              voulues.has(cle) || sectionsReglages.OBLIGATOIRES.includes(cle) ? '1' : '0');
          }
          poserReglage.run('sections.rattrapees', '1');
        })();
        return res.json({ ok: true, message: t('install.run.instanceDone') });
      });
    }

    if (req.params.stage === 'configuration') {
      // Les réglages d'abord, le verrou ensuite : tant que le verrou n'est pas
      // posé, une installation interrompue se reprend.
      reglages.ecrire(install.instanceSettings(data));

      // La licence, maintenant que la base porte des réglages. Un échec n'arrête
      // pas l'installation : l'instance s'installe et le dira sur son écran
      // « Licence ». Laisser un client à moitié installé parce que le panneau
      // éditeur ne répondait pas serait la pire des façons de le perdre.
      const licenceDite = await poserLaLicence(data);

      install.markInstalled(APP_VERSION);
      // Le mot de passe ne transite plus : l'administrateur se connecte
      // normalement, et le brouillon qui le portait disparaît.
      delete req.session.install;
      return res.json({
        ok: true,
        message: t('install.run.configDone'),
        licence: licenceDite,
        suite: '/connexion',
        redemarrage: data.engine === 'mysql',
      });
    }
  } catch (error) {
    return res.status(500).json({ ok: false, message: error.message });
  }

  return res.status(404).json({ ok: false, message: t('install.error.unknownStage') });
});

module.exports = router;
module.exports.STEPS = STEPS;
module.exports.STAGES = STAGES;
