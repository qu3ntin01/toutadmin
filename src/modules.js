const settings = require('./settings');

/**
 * Modules optionnels. Chacun est éteint par défaut : une instance n'embarque
 * que ce dont elle a besoin, et l'administration débloque le reste au fur et
 * à mesure. Le champ `caveat` dit ce que le module ne garantit pas — il est
 * affiché à l'activation plutôt que caché dans une documentation.
 */
const MODULES = [
  {
    key: 'comptabilite',
    label: 'Comptabilité',
    href: '/comptabilite',
    icon: 'card',
    description: "Plan comptable, journaux, écritures équilibrées, grand livre et balance. Une facture peut être passée en écriture d'un clic.",
    caveat: "Tenue de comptes interne. Ni liasse fiscale, ni télétransmission : l'export de la balance alimente votre expert-comptable.",
  },
  {
    key: 'paie',
    label: 'Moteur de paie',
    href: '/paie',
    icon: 'ticket',
    description: 'Barèmes de cotisations paramétrables, calcul du brut au net, part patronale et coût employeur, bulletin détaillé.',
    caveat: "Les taux sont ceux que vous saisissez : ils sont pré-remplis à titre indicatif et doivent être vérifiés par votre gestionnaire de paie. Aucune DSN n'est produite.",
  },
  {
    key: 'facturation-electronique',
    label: 'Facturation électronique',
    href: '/facturation-electronique',
    icon: 'external',
    description: "Contrôle des mentions obligatoires EN 16931 et export du XML CII (UN/CEFACT) de chaque facture client.",
    caveat: "Le XML produit est la charge utile réglementaire. L'encapsulation dans un PDF/A-3 (Factur-X) et le dépôt sur une plateforme agréée restent à faire par l'outil de votre choix.",
  },
  {
    key: 'stock',
    label: 'Stock et achats',
    href: '/stock',
    icon: 'network',
    description: "Articles, mouvements d'entrée et de sortie, seuil d'alerte, et demandes d'achat validées par le manager puis par la gestion.",
    caveat: 'Stock mono-dépôt, valorisé au dernier prix unitaire connu. Ni inventaire tournant, ni valorisation FIFO ou CUMP.',
  },
  {
    key: 'tresorerie',
    label: 'Trésorerie',
    href: '/tresorerie',
    icon: 'card',
    description: "Comptes bancaires, mouvements, rapprochement des encaissements avec les factures, et projection de trésorerie à douze semaines.",
    caveat: "Saisie et import manuels : aucune connexion bancaire (DSP2) n'est établie. Le solde est celui que vous avez saisi, pas celui de la banque.",
  },
  {
    key: 'immobilisations',
    label: 'Immobilisations',
    href: '/immobilisations',
    icon: 'briefcase',
    description: "Registre des immobilisations, tableaux d'amortissement linéaire et dégressif, valeur nette comptable et dotation de l'exercice.",
    caveat: "Les tableaux sont un outil de suivi : le rattachement comptable, les composants et les dérogatoires restent l'affaire de votre expert-comptable.",
  },
  {
    key: 'crm',
    label: 'CRM commercial',
    href: '/crm',
    icon: 'briefcase',
    description: 'Contacts, pipeline des opportunités, devis convertibles en facture, et relances à échéance.',
    caveat: "Suivi commercial interne : pas de synchronisation avec une messagerie ni d'automatisation marketing.",
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

    const module = byKey(key);
    const isAdmin = req.session.user && req.session.user.role === 'admin';
    res.status(404).render('error', {
      message: isAdmin
        ? `Le module « ${module.label} » n'est pas activé sur cette instance. Vous pouvez le débloquer depuis la console d'administration, section Modules.`
        : "Cette fonctionnalité n'est pas activée sur cette instance.",
    });
  };
}

module.exports = { MODULES, KEYS, isEnabled, setEnabled, list, enabled, byKey, requireModule };
