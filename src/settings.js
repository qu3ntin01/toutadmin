const db = require('./db');

// Réglages d'instance, posés à l'installation puis modifiables par l'administration.
/**
 * L'adresse du service de licences. Une seule écriture, que tout le reste relit.
 *
 * Le nom de machine est neutre, et c'est délibéré : ce que l'instance appelle,
 * c'est un service qui répond « voici votre bail » ou « non ». Un nom qui
 * désignerait une console d'administration apprendrait son existence à qui lit
 * ce fichier — et le code de l'instance, le client l'a chez lui.
 *
 * Les instances déjà posées gardent l'adresse écrite dans leurs réglages : le
 * serveur répond aux deux noms, une adresse changée sous les pieds d'un client
 * serait une panne qu'il n'a pas demandée.
 */
const EDITEUR_PAR_DEFAUT = 'https://licences.toutadmin.com';

/*
 * Où joindre l'éditeur.
 *
 * Elle n'est pas demandée à l'installation, et c'est voulu : l'éditeur d'un CMS
 * est le même pour toutes les instances. Poser la question revenait à demander
 * au client une réponse qu'il n'a pas, et à transformer une faute de frappe en
 * « éditeur injoignable » sans qu'il puisse comprendre pourquoi.
 *
 * « EDITEUR_URL » la remplace pour les seuls cas où elle doit l'être : la suite
 * de tests, qui dresse un faux éditeur sur un port local, et un déploiement de
 * recette qui vise un panneau de préproduction. C'est un réglage de machine,
 * posé par qui tient le serveur — pas un champ offert à qui installe.
 *
 * Lue à chaque appel plutôt qu'au chargement : un test qui ouvre son faux
 * éditeur sur un port tiré au sort le fait après avoir chargé ce module, et une
 * constante figée à l'import serait déjà partie sur la mauvaise adresse.
 */
function adresseEditeur() {
  // Posée et vide, la variable veut dire « aucun éditeur » : une instance
  // fermée, qui ne téléphone jamais dehors. C'est différent de « non posée »,
  // qui veut dire « celui d'origine ».
  const pose = process.env.EDITEUR_URL;
  const brut = pose === undefined ? EDITEUR_PAR_DEFAUT : pose;
  return String(brut).trim().replace(/\/+$/, '');
}

/** L'espace client, où l'on récupère sa licence. */
const MANAGER_PAR_DEFAUT = 'https://manager.toutadmin.com';

/** Le site, où l'on va lire les offres. */
const SITE_PAR_DEFAUT = 'https://toutadmin.com';

/*
 * Où le client récupère sa licence, et où il lit les offres.
 *
 * Mêmes règles que pour l'éditeur, et pour les mêmes raisons : l'adresse est la
 * même pour toutes les instances, elle n'est donc pas demandée à l'installation.
 * « MANAGER_URL » et « SITE_URL » la remplacent pour la suite de tests et pour
 * une recette. Posées et vides, elles veulent dire « pas d'espace client » et
 * « pas de site » : l'assistant n'offre alors plus les liens correspondants,
 * ce qui est exactement ce qu'il faut sur une instance fermée.
 */
function adresseManager() {
  const pose = process.env.MANAGER_URL;
  const brut = pose === undefined ? MANAGER_PAR_DEFAUT : pose;
  return String(brut).trim().replace(/\/+$/, '');
}

function adresseSite() {
  const pose = process.env.SITE_URL;
  const brut = pose === undefined ? SITE_PAR_DEFAUT : pose;
  return String(brut).trim().replace(/\/+$/, '');
}

const DEFAULTS = {
  company_name: 'Toutadmin',
  default_locale: 'fr',
  annual_leave_days: '25',
  installed_at: '',
  installed_version: '',
  // Palette de l'instance : posée par l'administration, valable pour tout le monde.
  theme_palette: 'lavande',
  // Politique de sécurité de l'instance.
  require_2fa_admin: '0',
  require_2fa_all: '0',
  audit_retention_days: '365',
  trash_retention_days: '30',
  // Sauvegardes automatiques : activées, toutes les heures, 24 archives gardées.
  backup_enabled: '1',
  backup_interval_minutes: '60',
  backup_keep: '24',
  password_max_age_days: '0',
  // Mises à jour : d'où elles viennent, avec quelle clé, et ce qu'on fait quand
  // il y en a une. « avertir » ne touche à rien tout seul ; « auto » applique au
  // balayage. Le choix appartient à l'instance, pas à l'éditeur.
  //
  // L'adresse est celle du service de licences, et elle est connue : la laisser
  // vide obligeait chaque installation à la retrouver quelque part et à la
  // recopier sans faute — une faute de frappe y ressemble à une panne de réseau.
  // Elle reste modifiable, parce qu'une maison qui héberge son propre panneau,
  // ou une instance d'essai, doit pouvoir pointer ailleurs.
  //
  // Rien n'est contacté pour autant : sans clé d'instance et sans clé publique,
  // « isConfigured » est faux et aucune requête ne part.
  update_editor_url: EDITEUR_PAR_DEFAUT,
  update_instance_key: '',
  update_public_key: '',
  update_channel: 'stable',
  update_mode: 'avertir',
  update_instance_name: '',
  update_last_check: '',
  update_last_error: '',
  update_available: '',
  // Licence de l'instance. Le jeton est signé par l'éditeur et rangé chiffré ;
  // l'empreinte est tirée une fois à l'installation et ne bouge plus. La clé
  // publique qui vérifie tout cela n'est pas ici : elle est dans le code, sans
  // quoi il suffirait d'y coller la sienne.
  licence_jeton: '',
  licence_empreinte: '',
  licence_adresse: '',
  licence_derniere_verif: '',
  licence_dernier_refus: '',
  // La date de la première vérification en échec depuis la dernière réussie.
  // Vide tant que tout va bien. C'est de là que part le compte à rebours de
  // quarante-huit heures, pas de l'échéance du jeton : une licence révoquée ou
  // un panneau qui ne répond plus sont des problèmes d'aujourd'hui, pas de la
  // date d'expiration écrite dans le jeton.
  licence_echec_depuis: '',
};

function get(key) {
  const row = db.prepare('SELECT value FROM settings WHERE key = ?').get(key);
  return row ? row.value : (DEFAULTS[key] ?? '');
}

function set(key, value) {
  db.prepare(`
    INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
    ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at
  `).run(key, String(value));
}

function setMany(entries) {
  const write = db.transaction((pairs) => {
    for (const [key, value] of Object.entries(pairs)) set(key, value);
  });
  write(entries);
}

function all() {
  const stored = Object.fromEntries(db.prepare('SELECT key, value FROM settings').all().map((r) => [r.key, r.value]));
  return { ...DEFAULTS, ...stored };
}

/** Initiales affichées dans le logo, dérivées du nom de l'entreprise. */
function brandInitials(name) {
  const words = String(name || DEFAULTS.company_name).trim().split(/\s+/).filter(Boolean);
  if (words.length === 0) return 'SM';
  if (words.length === 1) return words[0].slice(0, 2).toUpperCase();
  return (words[0][0] + words[1][0]).toUpperCase();
}

module.exports = {
  DEFAULTS,
  EDITEUR_PAR_DEFAUT,
  MANAGER_PAR_DEFAUT,
  SITE_PAR_DEFAUT,
  adresseEditeur,
  adresseManager,
  adresseSite,
  get,
  set,
  setMany,
  all,
  brandInitials,
};
