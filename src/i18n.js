const fs = require('fs');
const path = require('path');

// Les 15 langues les plus parlées, plus le français, langue de référence du produit.
const LOCALES = [
  { code: 'fr', label: 'Français', flag: '🇫🇷', dir: 'ltr' },
  { code: 'en', label: 'English', flag: '🇬🇧', dir: 'ltr' },
  { code: 'es', label: 'Español', flag: '🇪🇸', dir: 'ltr' },
  { code: 'de', label: 'Deutsch', flag: '🇩🇪', dir: 'ltr' },
  { code: 'it', label: 'Italiano', flag: '🇮🇹', dir: 'ltr' },
  { code: 'pt', label: 'Português', flag: '🇵🇹', dir: 'ltr' },
  { code: 'nl', label: 'Nederlands', flag: '🇳🇱', dir: 'ltr' },
  { code: 'pl', label: 'Polski', flag: '🇵🇱', dir: 'ltr' },
  { code: 'ru', label: 'Русский', flag: '🇷🇺', dir: 'ltr' },
  { code: 'tr', label: 'Türkçe', flag: '🇹🇷', dir: 'ltr' },
  { code: 'ar', label: 'العربية', flag: '🇸🇦', dir: 'rtl' },
  { code: 'hi', label: 'हिन्दी', flag: '🇮🇳', dir: 'ltr' },
  { code: 'zh', label: '中文', flag: '🇨🇳', dir: 'ltr' },
  { code: 'ja', label: '日本語', flag: '🇯🇵', dir: 'ltr' },
  { code: 'ko', label: '한국어', flag: '🇰🇷', dir: 'ltr' },
  { code: 'vi', label: 'Tiếng Việt', flag: '🇻🇳', dir: 'ltr' },
];

const DEFAULT_LOCALE = 'fr';
const CODES = LOCALES.map((l) => l.code);

const dictionaries = {};
for (const { code } of LOCALES) {
  const file = path.join(__dirname, 'locales', `${code}.js`);
  dictionaries[code] = fs.existsSync(file) ? require(file) : {};
}

function isSupported(code) {
  return CODES.includes(code);
}

function localeInfo(code) {
  return LOCALES.find((l) => l.code === code) || LOCALES[0];
}

/**
 * Traduit une clé. Toute clé absente retombe sur le français : une traduction
 * incomplète dégrade l'affichage, elle ne casse jamais la page.
 */
function translate(locale, key, params) {
  const dict = dictionaries[locale] || {};
  let value = dict[key];
  if (value === undefined) value = dictionaries[DEFAULT_LOCALE][key];
  if (value === undefined) return key;

  if (params) {
    for (const [name, replacement] of Object.entries(params)) {
      value = value.split(`{${name}}`).join(String(replacement));
    }
  }
  return value;
}

/*
 * Les statuts sont stockés en français dans la base : c'est la valeur métier,
 * elle sert aux contraintes CHECK et aux comparaisons, et elle ne bouge pas.
 * Cette table ne traduit que ce qui est *affiché*. Les paires de genre du
 * français (Annulé / Annulée) retombent sur une seule clé, les autres langues
 * ne faisant pas la distinction.
 */
const STATUS_KEYS = {
  'À faire': 'status.todo',
  'À remettre': 'status.toHandOver',
  'À traiter': 'status.toProcess',
  'À verser': 'status.due',
  Abandonné: 'status.abandoned',
  Abandonnée: 'status.abandoned',
  Abonnement: 'status.subscription',
  Absent: 'status.absent',
  Accepté: 'status.accepted',
  Acquisition: 'status.acquisition',
  Actif: 'common.active',
  'Activité accessoire': 'status.sideActivity',
  Administrateur: 'status.administrator',
  Affecté: 'status.assigned',
  'Adoptée': 'status.adopted',
  Annuel: 'status.yearly',
  Annulé: 'status.cancelled',
  Annulée: 'status.cancelled',
  Approuvée: 'status.approved',
  Archivé: 'status.archived',
  'Assemblée générale extraordinaire': 'status.agmExtraordinary',
  'Assemblée générale mixte': 'status.agmCombined',
  'Assemblée générale ordinaire': 'status.agmOrdinary',
  Assurance: 'status.insurance',
  Atelier: 'status.workshop',
  Atteint: 'status.reached',
  'Attestation de vigilance': 'status.vigilanceCertificate',
  Autre: 'status.other',
  Brouillon: 'status.draft',
  Cadeau: 'status.gift',
  Cadrage: 'status.framing',
  Candidatures: 'status.applications',
  Cédé: 'status.disposed',
  Certification: 'status.certification',
  Cession: 'status.transfer',
  Client: 'status.customer',
  'Client et fournisseur': 'status.customerSupplier',
  Clos: 'status.closed',
  Clôturé: 'status.closed',
  Clôturée: 'status.closed',
  Commandée: 'status.ordered',
  'Commissaire aux comptes': 'status.statutoryAuditor',
  Complet: 'status.full',
  Confirmée: 'status.confirmed',
  Convivialité: 'status.social',
  'Convoquée': 'status.convened',
  'Coordonnées bancaires': 'status.bankDetails',
  Corruption: 'status.corruption',
  Critique: 'status.critical',
  Déclarée: 'status.declared',
  Demandée: 'status.requested',
  Développement: 'status.envDevelopment',
  'Déclaré': 'status.declared',
  'Développement interne': 'status.inHouse',
  'Directeur général': 'status.ceo',
  'Directeur général délégué': 'status.deputyCeo',
  Discrimination: 'status.discrimination',
  Disponible: 'status.available',
  'Données personnelles': 'status.personalData',
  Écartée: 'status.discarded',
  Échec: 'status.failed',
  Échouée: 'status.failed',
  Échu: 'status.matured',
  Émise: 'status.issued',
  'Échue': 'status.matured',
  'En attente': 'status.pending',
  'En construction': 'status.underConstruction',
  'En cours': 'status.running',
  'En instruction': 'status.investigating',
  'En maintenance': 'status.underMaintenance',
  'En pause': 'status.paused',
  'En réparation': 'status.underRepair',
  'En revue': 'status.inReview',
  'En service': 'status.inService',
  'En test': 'status.inTesting',
  'En traitement': 'status.processing',
  'En vigueur': 'status.inForce',
  Environnement: 'status.environment',
  Envoyé: 'status.sent',
  Envoyée: 'status.sent',
  Expiré: 'status.expired',
  Facturée: 'status.invoiced',
  'Examiné': 'status.reviewed',
  Faite: 'status.done',
  Formation: 'status.training',
  Fournisseur: 'status.supplier',
  Fraude: 'status.fraud',
  'Gérant': 'status.managingPartner',
  Gestion: 'status.stageManagement',
  Gestionnaire: 'status.steward',
  Harcèlement: 'status.harassment',
  Immobilisé: 'status.grounded',
  Importante: 'status.important',
  Inscrit: 'status.enrolled',
  Inscrite: 'status.enrolled',
  'Intérêt financier': 'status.financialInterest',
  Invitation: 'status.invitation',
  Irrecevable: 'status.inadmissible',
  Kbis: 'status.companyExtract',
  'Licence perpétuelle': 'status.perpetualLicence',
  "Liste d'attente": 'status.waitlisted',
  Livré: 'status.delivered',
  Livrée: 'status.delivered',
  'Lien familial': 'status.familyTie',
  'Logiciel libre': 'status.openSource',
  Maîtrisé: 'status.mitigated',
  Majeur: 'status.major',
  Manager: 'status.stageManager',
  'Mandat externe': 'status.externalMandate',
  'Membre du conseil': 'status.boardMember',
  Mensuel: 'status.monthly',
  'Mesure prise': 'status.measureTaken',
  Mineur: 'status.minor',
  'Mise en demeure': 'status.formalNotice',
  Offert: 'status.giftGiven',
  Ouvert: 'status.open',
  Ouverte: 'status.open',
  Payée: 'status.paid',
  Planifié: 'status.scheduled',
  Planifiée: 'status.scheduled',
  'Personne morale': 'status.legalPerson',
  'Personne physique': 'status.naturalPerson',
  Ponctuel: 'status.oneOff',
  Pourvu: 'status.filled',
  Préproduction: 'status.envPreprod',
  Présent: 'status.present',
  'Président': 'status.chairperson',
  Production: 'status.envProduction',
  Rappel: 'status.reminder',
  Réalisé: 'status.completed',
  Recette: 'status.envStaging',
  Recevable: 'status.admissible',
  Reçue: 'status.received',
  'Reçu': 'status.giftReceived',
  'Reçue partiellement': 'status.partlyReceived',
  Réformé: 'status.writtenOff',
  Refusé: 'status.refused',
  Refusée: 'status.refused',
  'Réduction': 'status.capitalReduction',
  'Rejetée': 'status.rejected',
  Relance: 'status.chaser',
  Remboursée: 'status.reimbursed',
  Remis: 'status.handedOver',
  Résilié: 'status.terminated',
  Résolu: 'status.resolved',
  Retiré: 'status.withdrawn',
  Retirée: 'status.withdrawn',
  'Restitué': 'status.giftReturned',
  'Réunion générale': 'status.allHands',
  'Révoqué': 'status.revoked',
  'Révoquée': 'status.revoked',
  Salon: 'status.tradeShow',
  Secondaire: 'status.secondary',
  'Sécurité des personnes': 'status.peopleSafety',
  Séminaire: 'status.seminar',
  Signé: 'status.signed',
  Souscription: 'status.subscription',
  Suspendue: 'status.suspended',
  Tenu: 'status.held',
  Tenue: 'status.held',
  Terminée: 'status.finished',
  Trimestriel: 'status.quarterly',
  Utilisateur: 'status.user',
  Validée: 'status.validated',
  Vitale: 'status.vital',
  Vote: 'status.voting',
  Voyage: 'status.trip',
};

/**
 * Libellé affichable d'un statut stocké. Une valeur inconnue — un statut
 * ajouté par l'exploitant, par exemple — ressort telle quelle : mieux vaut
 * un mot français qu'une case vide.
 */
function statusLabel(locale, value) {
  if (!value) return '';
  const key = STATUS_KEYS[value];
  return key ? translate(locale, key) : value;
}

// Ordre de résolution : choix du compte, puis cookie (visiteur non connecté), puis navigateur.
function resolveLocale(req) {
  if (req.session && req.session.user && isSupported(req.session.user.locale)) {
    return req.session.user.locale;
  }
  if (req.cookies && isSupported(req.cookies.locale)) return req.cookies.locale;

  const header = req.headers['accept-language'];
  if (header) {
    for (const part of header.split(',')) {
      const code = part.split(';')[0].trim().slice(0, 2).toLowerCase();
      if (isSupported(code)) return code;
    }
  }
  return DEFAULT_LOCALE;
}

function middleware(req, res, next) {
  const locale = resolveLocale(req);
  const info = localeInfo(locale);

  res.locals.locale = locale;
  res.locals.localeDir = info.dir;
  res.locals.locales = LOCALES;
  res.locals.t = (key, params) => translate(locale, key, params);
  res.locals.st = (value) => statusLabel(locale, value);
  next();
}

module.exports = { LOCALES, DEFAULT_LOCALE, STATUS_KEYS, isSupported, localeInfo, translate, statusLabel, middleware };
