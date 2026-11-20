const settings = require('./settings');

/**
 * Modules optionnels. Chacun est éteint par défaut : une instance n'embarque
 * que ce dont elle a besoin, et l'administration débloque le reste au fur et
 * à mesure.
 *
 * Le nom, ce que fait le module et sa réserve vivent dans le dictionnaire, sous
 * « mod.<clé>.label », « .what » et « .caveat ». Ils étaient écrits ici, en
 * français : un administrateur japonais lisait donc sa liste de modules en
 * français, au milieu d'un écran traduit. La liste ne garde que ce qui ne se
 * traduit pas — la clé, l'adresse, l'icône.
 *
 * La réserve dit ce que le module ne garantit pas. Elle est affichée à
 * l'activation plutôt que cachée dans une documentation : on la lit au moment
 * où l'on décide, pas le jour où l'on découvre.
 */
const MODULES = [
  {
    key: 'comptabilite',
    href: '/comptabilite',
    icon: 'card',
  },
  {
    key: 'paie',
    href: '/paie',
    icon: 'ticket',
  },
  {
    key: 'facturation-electronique',
    href: '/facturation-electronique',
    icon: 'external',
  },
  {
    key: 'stock',
    href: '/stock',
    icon: 'network',
  },
  {
    key: 'tresorerie',
    href: '/tresorerie',
    icon: 'card',
  },
  {
    key: 'immobilisations',
    href: '/immobilisations',
    icon: 'briefcase',
  },
  {
    key: 'crm',
    href: '/crm',
    icon: 'briefcase',
  },
];

const KEYS = MODULES.map((m) => m.key);
const settingKey = (key) => `module.${key}`;

function isEnabled(key) {
  return settings.get(settingKey(key)) === '1';
}

function setEnabled(key, enabled) {
  if (!KEYS.includes(key)) return false;
  settings.set(settingKey(key), enabled ? '1' : '0');
  return true;
}

/** La liste complète, chacun avec son état : c'est ce qu'affiche l'écran d'activation. */
function list() {
  return MODULES.map((m) => ({ ...m, enabled: isEnabled(m.key) }));
}

function enabled() {
  return list().filter((m) => m.enabled);
}

function byKey(key) {
  return MODULES.find((m) => m.key === key) || null;
}

/**
 * Barrière d'un module : tant qu'il n'est pas débloqué, ses routes n'existent
 * pas. La page le dit, et propose à l'administrateur d'aller l'activer.
 */
function requireModule(key) {
  return (req, res, next) => {
    if (isEnabled(key)) return next();

    const isAdmin = req.session.user && req.session.user.role === 'admin';
    // Le nom du module vient du dictionnaire, comme le message : l'un sans
    // l'autre donnerait une phrase traduite qui cite un mot français.
    const nom = res.locals.t(`mod.${key}.label`);
    res.status(404).render('error', {
      message: isAdmin ? res.locals.t('mod.lockedAdmin', { module: nom })
        : res.locals.t('mod.locked'),
    });
  };
}

module.exports = { MODULES, KEYS, isEnabled, setEnabled, list, enabled, byKey, requireModule };
