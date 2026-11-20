const settings = require('./settings');
const { GROUPES } = require('./sections');

/**
 * Les sections : ce qu'on a choisi d'installer, et ce que chacune règle.
 *
 * Trois choses vivent ici, et elles se tiennent :
 *
 * 1. UNE SECTION S'INSTALLE OU NE S'INSTALLE PAS. Une entreprise de six
 *    personnes n'a pas de conformité à tenir ni de flotte à suivre ; lui
 *    imposer sept métiers dans son rail, c'est lui demander d'ignorer les
 *    cinq qui ne la concernent pas — à chaque écran, tous les jours. L'étape
 *    « Sections » de l'assistant d'installation pose le choix, et il se
 *    reprend ensuite.
 *
 * 2. UNE SECTION SE CONFIGURE À SA PREMIÈRE OUVERTURE. Les réglages qui la
 *    gouvernent existaient déjà — ils étaient dispersés dans six onglets de la
 *    console, et on les découvrait le jour où un défaut faisait mal. On les
 *    demande donc au moment où l'on entre dans le métier pour la première
 *    fois, quand la question a un sens.
 *
 * 3. CHAQUE SECTION A SES RÉGLAGES, et ce sont les vrais. Rien ici n'est
 *    décoratif : chaque champ est lu par du code qui s'en sert. La plupart
 *    renvoient à un réglage qui existait déjà — « annual_leave_days »,
 *    « backup_keep », « require_2fa_admin » — et c'est voulu : un second
 *    réglage qui doublerait le premier finit par lui contredire, et l'on ne
 *    sait plus lequel décide.
 *
 * ── Ce que ce fichier n'est pas ───────────────────────────────────────────
 *
 * Il ne remplace pas « reglages.js », qui lit les réglages de déploiement
 * (base de données, secret de session) dans un fichier hors de la base. Ici,
 * ce sont des réglages métier : ils vivent dans la base, avec le reste.
 *
 * Il ne remplace pas non plus « modules.js ». Un module est une option de
 * l'éditeur, qui s'achète ; une section est un pan du produit, que le client
 * décide d'installer ou non. Un module éteint peut appartenir à une section
 * installée — c'est même le cas courant.
 */

/** Une section qu'on ne peut pas désinstaller : sans elle, rien ne se rallume. */
const OBLIGATOIRES = ['reglages'];

/** Témoin du rattrapage : voir « rattraperInstanceExistante ». */
const TEMOIN_RATTRAPAGE = 'sections.rattrapees';

const CLES = GROUPES.map((g) => g.cle);

const cleActive = (section) => `section.${section}.active`;
const cleConfiguree = (section) => `section.${section}.configuree`;
const cleChamp = (section, champ) => `section.${section}.${champ}`;

/**
 * Le choix se lit à l'endroit, pas à l'envers.
 *
 * Écrire « 1 » pour les sections retenues et rien pour les autres aurait
 * voulu dire qu'une instance posée avant cette version — où le réglage
 * n'existe pas — n'a aucune section. On stocke donc « 0 » pour refuser, et
 * l'absence vaut « oui » : une instance déjà installée garde tout ce qu'elle
 * avait, sans migration ni intervention.
 */
function estActive(section) {
  if (OBLIGATOIRES.includes(section)) return true;
  if (!CLES.includes(section)) return false;
  return settings.get(cleActive(section)) !== '0';
}

function actives() {
  return CLES.filter(estActive);
}

/**
 * Poser ou retirer une section.
 *
 * Retirer n'efface rien : les données de la section restent en base, et la
 * reposer les retrouve intactes. C'est une décision d'affichage et d'accès,
 * pas une suppression — un client qui se trompe de case ne doit pas perdre
 * ses factures.
 */
function activer(section, voulue) {
  if (!CLES.includes(section)) return false;
  if (OBLIGATOIRES.includes(section) && !voulue) return false;
  settings.set(cleActive(section), voulue ? '1' : '0');
  return true;
}

/**
 * Écrit le choix d'installation d'un coup.
 *
 * Les sections obligatoires s'ajoutent d'office : elles ne sont pas offertes
 * dans l'écran, et les recevoir ou non ne doit donc rien changer.
 */
function poserChoix(choisies) {
  const voulues = new Set([...(Array.isArray(choisies) ? choisies : []), ...OBLIGATOIRES]);
  const valeurs = {};
  for (const cle of CLES) valeurs[cleActive(cle)] = voulues.has(cle) ? '1' : '0';
  settings.setMany(valeurs);
}

// ---------------------------------------------------------------- réglages

/*
 * Les champs d'une section.
 *
 *   cle      son nom dans les adresses et dans le dictionnaire ;
 *   type     « nombre », « bool », « choix », « texte », « mois » ;
 *   reglage  le réglage existant qu'il pilote. Absent, le champ vit sous
 *            « section.<section>.<cle> » ;
 *   poser    quand écrire le réglage demande de passer par le module qui le
 *            tient — les sauvegardes replanifient, la devise valide son code.
 *            Absent, on écrit directement.
 *
 * « options » est une fonction et non une liste : les devises, les langues et
 * les palettes vivent dans des modules qui lisent la base. Les évaluer au
 * chargement de celui-ci les figerait avant que la base existe, et ferait
 * dépendre l'ordre des « require » du bon fonctionnement du produit.
 */
const CHAMPS = {
  pilotage: [
    { cle: 'horizon_jours', type: 'nombre', defaut: '45', min: 7, max: 365 },
    { cle: 'exercice_mois', type: 'mois', defaut: '1' },
    { cle: 'seuil_impayes', type: 'nombre', defaut: '0', min: 0, max: 100000000 },
  ],
  personnel: [
    { cle: 'conges_annuels', reglage: 'annual_leave_days', type: 'nombre', defaut: '25', min: 0, max: 60 },
    { cle: 'plafond_paie', reglage: 'payroll_ceiling', type: 'nombre', defaut: '3925', min: 0, max: 1000000 },
  ],
  gestion: [
    {
      cle: 'devise',
      reglage: 'currency.base',
      type: 'choix',
      defaut: 'EUR',
      options: () => require('./currency').CURRENCIES.map((c) => ({ valeur: c.code, libelle: `${c.code} — ${c.label}` })), // eslint-disable-line global-require
      poser: (valeur) => require('./currency').setBase(valeur), // eslint-disable-line global-require
    },
    { cle: 'delai_paiement', type: 'nombre', defaut: '30', min: 0, max: 365 },
  ],
  relation: [
    { cle: 'delai_critique', type: 'nombre', defaut: '2', min: 1, max: 720 },
    { cle: 'delai_haute', type: 'nombre', defaut: '8', min: 1, max: 720 },
    { cle: 'delai_normale', type: 'nombre', defaut: '24', min: 1, max: 720 },
    { cle: 'delai_basse', type: 'nombre', defaut: '72', min: 1, max: 720 },
  ],
  technique: [
    { cle: 'sauvegarde_active', reglage: 'backup_enabled', type: 'bool', defaut: '1' },
    { cle: 'sauvegarde_intervalle', reglage: 'backup_interval_minutes', type: 'nombre', defaut: '60', min: 15, max: 1440 },
    { cle: 'sauvegarde_gardees', reglage: 'backup_keep', type: 'nombre', defaut: '24', min: 2, max: 500 },
    {
      cle: 'maj_canal',
      reglage: 'update_channel',
      type: 'choix',
      defaut: 'stable',
      options: () => [{ valeur: 'stable' }, { valeur: 'beta' }],
    },
    {
      cle: 'maj_mode',
      reglage: 'update_mode',
      type: 'choix',
      defaut: 'avertir',
      options: () => [{ valeur: 'avertir' }, { valeur: 'auto' }],
    },
  ],
  conformite: [
    { cle: 'facteur_admin', reglage: 'require_2fa_admin', type: 'bool', defaut: '0' },
    { cle: 'facteur_tous', reglage: 'require_2fa_all', type: 'bool', defaut: '0' },
    { cle: 'age_mot_de_passe', reglage: 'password_max_age_days', type: 'nombre', defaut: '0', min: 0, max: 3650 },
    { cle: 'retention_audit', reglage: 'audit_retention_days', type: 'nombre', defaut: '365', min: 30, max: 3650 },
    { cle: 'retention_corbeille', reglage: 'trash_retention_days', type: 'nombre', defaut: '30', min: 0, max: 3650 },
  ],
  reglages: [
    { cle: 'nom_entreprise', reglage: 'company_name', type: 'texte', defaut: 'Toutadmin', max: 120 },
    {
      cle: 'langue',
      reglage: 'default_locale',
      type: 'choix',
      defaut: 'fr',
      options: () => require('./i18n').LOCALES.map((l) => ({ valeur: l.code, libelle: l.label })), // eslint-disable-line global-require
    },
    {
      cle: 'palette',
      reglage: 'theme_palette',
      type: 'choix',
      defaut: 'lavande',
      options: () => require('./themes').PALETTES.map((p) => ({ valeur: p.key, libelle: p.label })), // eslint-disable-line global-require
      poser: (valeur) => require('./themes').set(valeur), // eslint-disable-line global-require
    },
  ],
};

/** Les champs d'une section, options résolues : c'est ce que les écrans rendent. */
function champsDe(section) {
  return (CHAMPS[section] || []).map((champ) => ({
    ...champ,
    options: typeof champ.options === 'function' ? champ.options() : undefined,
    valeur: valeur(section, champ.cle),
  }));
}

function definition(section, cle) {
  return (CHAMPS[section] || []).find((c) => c.cle === cle) || null;
}

function cleDe(section, champ) {
  return champ.reglage || cleChamp(section, champ.cle);
}

/** La valeur en place, ou le défaut du champ si rien n'a été posé. */
function valeur(section, cle) {
  const champ = definition(section, cle);
  if (!champ) return '';
  const pose = settings.get(cleDe(section, champ));
  return pose === '' || pose === undefined ? champ.defaut : String(pose);
}

/** Un nombre, toujours dans ses bornes : le reste du code n'a pas à s'en méfier. */
function nombre(section, cle) {
  const champ = definition(section, cle);
  const brut = Number(valeur(section, cle));
  const defaut = Number(champ ? champ.defaut : 0);
  if (!Number.isFinite(brut)) return defaut;
  const bas = champ && champ.min !== undefined ? champ.min : -Infinity;
  const haut = champ && champ.max !== undefined ? champ.max : Infinity;
  return Math.min(haut, Math.max(bas, brut));
}

function bool(section, cle) {
  return valeur(section, cle) === '1';
}

/**
 * Nettoie une valeur soumise.
 *
 * Rend null quand elle est irrécupérable — l'écran redemande plutôt que
 * d'écrire n'importe quoi. Un nombre hors bornes se ramène dans ses bornes
 * plutôt que d'être refusé : l'intention est claire, et refuser « 10000 jours
 * de congés » d'un message d'erreur n'apprend rien de plus que de le ramener
 * au maximum affiché.
 */
function nettoyer(champ, brut) {
  const texte = brut === undefined || brut === null ? '' : String(brut).trim();
  switch (champ.type) {
    case 'bool':
      return texte === '1' || texte === 'on' || texte === 'true' ? '1' : '0';
    case 'nombre': {
      const n = Number(texte);
      if (!Number.isFinite(n)) return null;
      const bas = champ.min !== undefined ? champ.min : -Infinity;
      const haut = champ.max !== undefined ? champ.max : Infinity;
      return String(Math.round(Math.min(haut, Math.max(bas, n))));
    }
    case 'mois': {
      const n = Number(texte);
      if (!Number.isInteger(n) || n < 1 || n > 12) return null;
      return String(n);
    }
    case 'choix': {
      const permis = (typeof champ.options === 'function' ? champ.options() : []).map((o) => o.valeur);
      return permis.includes(texte) ? texte : null;
    }
    case 'texte': {
      if (!texte) return null;
      return texte.slice(0, champ.max || 200);
    }
    default:
      return null;
  }
}

/**
 * Écrit ce qu'on lui donne, et rend les champs refusés.
 *
 * Ce qui n'est pas dans le corps n'est pas touché : l'assistant avance par
 * étapes, et chaque étape ne porte que ses champs à elle. Une écriture par
 * champ plutôt qu'un « setMany » global, parce que certains passent par le
 * module qui les tient — les sauvegardes replanifient, la palette se valide.
 */
function enregistrer(section, corps) {
  const refuses = [];
  for (const champ of CHAMPS[section] || []) {
    if (!Object.prototype.hasOwnProperty.call(corps, champ.cle)) {
      // Une case à cocher absente du corps vaut « décochée » : c'est ainsi que
      // les navigateurs envoient un formulaire, et la lire autrement rendrait
      // toute case impossible à décocher.
      if (champ.type !== 'bool') continue;
    }
    const propre = nettoyer(champ, corps[champ.cle]);
    if (propre === null) {
      refuses.push(champ.cle);
      continue;
    }
    if (champ.poser) champ.poser(propre);
    else settings.set(cleDe(section, champ), propre);
  }
  return refuses;
}

// ------------------------------------------------------- première ouverture

function estConfiguree(section) {
  return settings.get(cleConfiguree(section)) !== '';
}

function marquerConfiguree(section) {
  settings.set(cleConfiguree(section), new Date().toISOString());
}

/**
 * Rouvrir l'assistant d'une section.
 *
 * Il ne défait rien : les réglages en place restent, et l'assistant les
 * représente tels quels. C'est une relecture guidée, pas une remise à zéro.
 */
function rouvrir(section) {
  if (!CLES.includes(section)) return false;
  settings.set(cleConfiguree(section), '');
  return true;
}

/**
 * Une instance installée avant cette version n'a jamais vu l'assistant, et
 * elle tourne depuis des mois avec des réglages qu'elle a posés elle-même.
 * Lui ouvrir sept assistants à la première mise à jour serait lui redemander
 * ce qu'elle a déjà répondu. On marque donc tout comme configuré au moment où
 * l'on constate que l'instance existait déjà.
 *
 * Ce qui distingue « déjà là » de « fraîchement posée » n'est pas la date
 * d'installation : l'assistant l'écrit lui aussi, et une instance neuve
 * serait rattrapée dès son premier démarrage — donc jamais configurée. C'est
 * le témoin ci-dessous, que l'assistant pose lui-même en terminant : une
 * instance née avec les sections n'a rien à rattraper, et le dit.
 *
 * Restent les déploiements pilotés par variables d'environnement, qui n'ont
 * jamais vu d'assistant du tout. Un administrateur en base et pas de témoin :
 * l'instance tournait déjà, on la rattrape.
 */
function instanceDejaLa() {
  if (settings.get('installed_at') !== '') return true;
  try {
    // eslint-disable-next-line global-require
    return require('./db').prepare("SELECT COUNT(*) AS n FROM users WHERE role = 'admin'").get().n > 0;
  } catch {
    // Base absente ou schéma pas encore posé : il n'y a rien à rattraper.
    return false;
  }
}

function rattraperInstanceExistante() {
  if (settings.get(TEMOIN_RATTRAPAGE) === '1') return false;
  if (!instanceDejaLa()) return false;
  const valeurs = { [TEMOIN_RATTRAPAGE]: '1' };
  const quand = new Date().toISOString();
  for (const cle of CLES) {
    if (settings.get(cleConfiguree(cle)) === '') valeurs[cleConfiguree(cle)] = quand;
  }
  settings.setMany(valeurs);
  return true;
}

/**
 * Posé par l'assistant d'installation en terminant : cette instance est née
 * avec les sections, et ses assistants de section doivent s'ouvrir.
 */
function neeAvecLesSections() {
  settings.set(TEMOIN_RATTRAPAGE, '1');
}

module.exports = {
  OBLIGATOIRES,
  CLES,
  CHAMPS,
  estActive,
  actives,
  activer,
  poserChoix,
  champsDe,
  definition,
  valeur,
  nombre,
  bool,
  nettoyer,
  enregistrer,
  estConfiguree,
  marquerConfiguree,
  rouvrir,
  rattraperInstanceExistante,
  neeAvecLesSections,
};
