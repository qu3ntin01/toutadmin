const { AsyncLocalStorage } = require('node:async_hooks');

const db = require('./db');
const cloison = require('./cloison');

/**
 * Les sociétés, et le périmètre courant.
 *
 * Le cloisonnement a deux moitiés. L'autre — src/cloison.js — dit quelles tables
 * portent un périmètre et refuse les requêtes qui l'oublient. Celle-ci dit quel
 * périmètre, pour qui, et comment on en change.
 *
 * Deux règles d'accès, et elles ne se ressemblent pas :
 *
 *   - un salarié voit les sociétés où il est rattaché, et rien d'autre. Le
 *     rattachement est une donnée, pas un droit : il se lit en base à chaque
 *     requête, comme tous les droits de ce produit ;
 *   - l'administration de l'instance voit toutes les sociétés. C'est elle qui
 *     les crée, les rattache et les ferme ; lui demander de se rattacher
 *     elle-même pour administrer reviendrait à pouvoir se verrouiller dehors.
 *
 * Ce que ce module ne fait pas : filtrer. Il ne rend que l'identifiant du
 * périmètre ; ce sont les requêtes qui s'en servent, et le garde qui vérifie
 * qu'elles le font.
 */

/*
 * Le périmètre de la requête en cours, et pourquoi il n'est pas un argument.
 *
 * La première table passée sous garde — les actualités — recevait le périmètre en
 * premier argument de chaque fonction. C'était juste, et cela ne passe pas
 * l'échelle : le module de l'organisation est appelé à cent sept endroits, celui
 * de la finance davantage, et un argument qu'il faut passer cent sept fois sera
 * passé de travers une fois. L'erreur serait alors silencieuse — le garde vérifie
 * qu'une requête nomme le périmètre, pas qu'elle nomme le bon.
 *
 * Le périmètre est donc tenu par la requête elle-même, dans un contexte que
 * l'intergiciel ouvre une fois et que tout ce qui s'exécute dedans retrouve,
 * fonctions asynchrones comprises. Ce que cela change :
 *
 *   - aucune signature ne porte le périmètre, donc aucune ne peut l'oublier ;
 *   - ce qui tourne hors requête — une tâche planifiée, un jeton d'API — n'a pas
 *     de contexte, et obtient la société qui mène, explicitement nommée
 *     « perimetreParDefaut » ;
 *   - les tests et les traitements de fond disent dans quelle société ils
 *     travaillent avec « dans(id, …) », ce qui se lit mieux qu'un argument de
 *     plus et ne peut pas s'oublier au milieu d'une chaîne d'appels.
 */
const contexte = new AsyncLocalStorage();

/**
 * Le périmètre dans lequel la requête en cours travaille.
 *
 * Rend l'identifiant de la société, ou celui de la société qui mène hors
 * contexte. Jamais null sur une instance installée : c'est ce qui permet aux
 * requêtes de nommer le périmètre sans avoir à se demander s'il y en a un.
 */
function courant() {
  const porte = contexte.getStore();
  if (porte && porte.societeId) return porte.societeId;
  return perimetreParDefaut();
}

/** Travailler dans une société donnée : tâches de fond, API, tests. */
function dans(societeId, travail) {
  return contexte.run({ societeId: Number(societeId) || null }, travail);
}

/** Les sociétés qu'une personne peut regarder, dans l'ordre de leur nom. */
function accessibles(user) {
  if (!user) return [];
  if (user.role === 'admin') {
    return db.prepare('SELECT * FROM companies WHERE active = 1 ORDER BY name COLLATE NOCASE').all();
  }
  return db.prepare(`
    SELECT c.* FROM companies c
    JOIN company_members m ON m.company_id = c.id
    WHERE m.user_id = ? AND c.active = 1
    ORDER BY c.name COLLATE NOCASE
  `).all(user.id);
}

function parId(id) {
  return db.prepare('SELECT * FROM companies WHERE id = ?').get(Number(id) || 0) || null;
}

function toutes({ fermees = true } = {}) {
  const sql = fermees
    ? 'SELECT * FROM companies ORDER BY active DESC, name COLLATE NOCASE'
    : 'SELECT * FROM companies WHERE active = 1 ORDER BY name COLLATE NOCASE';
  return db.prepare(sql).all();
}

function compter() {
  return db.prepare('SELECT COUNT(*) AS n FROM companies WHERE active = 1').get().n;
}

/** Une personne a-t-elle accès à ce périmètre ? */
function ouverte(user, societeId) {
  const id = Number(societeId) || 0;
  if (id <= 0 || !user) return false;
  return accessibles(user).some((c) => c.id === id);
}

/**
 * Le périmètre courant d'une requête.
 *
 * Il se relit entièrement à chaque requête : la société gardée en session n'est
 * qu'un souvenir, pas un droit. Un rattachement retiré, une société fermée, et
 * le souvenir ne vaut plus rien — on retombe alors sur la première société
 * accessible, ou sur rien du tout.
 */
function courante(req) {
  const user = req && req.session ? req.session.user : null;
  const ouvertes = accessibles(user);
  if (ouvertes.length === 0) return null;

  const gardee = req.session ? Number(req.session.societeId) || 0 : 0;
  const trouvee = ouvertes.find((c) => c.id === gardee);
  if (trouvee) return trouvee;

  /*
   * Le souvenir ne vaut plus : on le remplace, sans rien dire — ce n'est pas un
   * incident, c'est un rattachement qui a changé.
   *
   * Par défaut, la plus ancienne des sociétés accessibles, et non la première
   * par ordre alphabétique : créer une filiale dont le nom passe avant celui de
   * la maison mère changerait alors le périmètre de tout le monde sans que
   * personne l'ait demandé. L'ordre des noms sert à l'affichage, pas au choix.
   */
  const defaut = ouvertes.reduce((plusAncienne, c) => (c.id < plusAncienne.id ? c : plusAncienne), ouvertes[0]);
  if (req.session) req.session.societeId = defaut.id;
  return defaut;
}

/** Changer de périmètre. Rend false si la société n'est pas ouverte à la personne. */
function choisir(req, societeId) {
  const user = req && req.session ? req.session.user : null;
  if (!ouverte(user, societeId)) return false;
  req.session.societeId = Number(societeId);
  return true;
}

/**
 * La première société, garantie avant qu'un compte existe.
 *
 * Appelée partout où un premier compte se crée : l'assistant d'installation,
 * l'installation sans interface, le harnais de tests. Sans elle, le tout premier
 * administrateur ouvrirait une instance sans périmètre, où chaque écran métier
 * refuserait de répondre — et le message parlerait de cloisonnement à quelqu'un
 * qui vient seulement d'installer le produit.
 *
 * Idempotente : s'il existe déjà une société, elle ne fait rien et rend celle
 * qui mène. Rattache aussi tout compte qui n'aurait aucun rattachement, ce qui
 * couvre les comptes créés avant que le cloisonnement existe.
 */
function garantirPremiere(nom = '') {
  return db.garantirPremiereSociete(nom);
}

// ---------------------------------------------------------------- écriture

function creer({ nom, forme = '', immatriculation = '', adresse = '' }) {
  const propre = String(nom || '').trim().slice(0, 160);
  if (!propre) throw new Error('societes.err.nom');
  const existe = db.prepare('SELECT id FROM companies WHERE name = ? COLLATE NOCASE').get(propre);
  if (existe) throw new Error('societes.err.doublon');

  const id = Number(db.prepare(`
    INSERT INTO companies (name, legal_form, registration, address) VALUES (?, ?, ?, ?)
  `).run(propre, String(forme).trim().slice(0, 60), String(immatriculation).trim().slice(0, 40),
    String(adresse).trim().slice(0, 300)).lastInsertRowid);
  return parId(id);
}

function modifier(id, { nom, forme = '', immatriculation = '', adresse = '' }) {
  const societe = parId(id);
  if (!societe) return null;
  const propre = String(nom || '').trim().slice(0, 160);
  if (!propre) throw new Error('societes.err.nom');
  const autre = db.prepare('SELECT id FROM companies WHERE name = ? COLLATE NOCASE AND id <> ?').get(propre, societe.id);
  if (autre) throw new Error('societes.err.doublon');

  db.prepare(`
    UPDATE companies SET name = ?, legal_form = ?, registration = ?, address = ? WHERE id = ?
  `).run(propre, String(forme).trim().slice(0, 60), String(immatriculation).trim().slice(0, 40),
    String(adresse).trim().slice(0, 300), societe.id);
  return parId(societe.id);
}

/**
 * Fermer ou rouvrir une société.
 *
 * Fermer, et non supprimer : ses écritures, ses factures et ses contrats font
 * preuve, et les effacer parce qu'une entité a cessé son activité serait perdre
 * ce qu'une entité cessant son activité doit justement conserver. Une société
 * fermée disparaît des périmètres, et ses données avec — elles reviennent si on
 * la rouvre.
 *
 * La dernière société ouverte ne se ferme pas : une instance sans périmètre
 * n'affiche plus rien, et personne ne saurait quoi rouvrir.
 */
function basculer(id, allumee) {
  const societe = parId(id);
  if (!societe) return { ok: false, raison: 'societes.err.introuvable' };
  if (!allumee && compter() <= 1) return { ok: false, raison: 'societes.err.derniere' };
  db.prepare('UPDATE companies SET active = ? WHERE id = ?').run(allumee ? 1 : 0, societe.id);
  return { ok: true, societe: parId(societe.id) };
}

// ------------------------------------------------------------ appartenance

function membres(societeId) {
  return db.prepare(`
    SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.active
    FROM company_members m JOIN users u ON u.id = m.user_id
    WHERE m.company_id = ?
    ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE
  `).all(Number(societeId) || 0);
}

function rattacher(societeId, userId) {
  if (!parId(societeId)) return false;
  const personne = db.prepare('SELECT id FROM users WHERE id = ?').get(Number(userId) || 0);
  if (!personne) return false;
  db.prepare(`
    INSERT INTO company_members (company_id, user_id) VALUES (?, ?)
    ON CONFLICT(company_id, user_id) DO NOTHING
  `).run(Number(societeId), personne.id);
  return true;
}

/**
 * Détacher quelqu'un d'une société.
 *
 * Refusé si c'est son dernier rattachement et qu'elle n'est pas de
 * l'administration : un salarié sans société ne voit plus rien, et ne peut pas
 * se rattacher lui-même. Mieux vaut refuser et le dire que de le laisser devant
 * un produit vide.
 */
function detacher(societeId, userId) {
  const personne = db.prepare('SELECT * FROM users WHERE id = ?').get(Number(userId) || 0);
  if (!personne) return { ok: false, raison: 'societes.err.personne' };
  const restantes = db.prepare('SELECT COUNT(*) AS n FROM company_members WHERE user_id = ?').get(personne.id).n;
  if (restantes <= 1 && personne.role !== 'admin') {
    return { ok: false, raison: 'societes.err.dernierRattachement' };
  }
  db.prepare('DELETE FROM company_members WHERE company_id = ? AND user_id = ?')
    .run(Number(societeId) || 0, personne.id);
  return { ok: true };
}

/**
 * Le périmètre de ce qui n'a pas de session : les tâches planifiées.
 *
 * Un jeton d'API, lui, porte désormais sa société : l'intergiciel de l'API entre
 * dans celle du jeton, et ne passe donc jamais par ici. Restent les tâches de
 * fond, qui n'ont ni session ni société — celles qui doivent travailler partout
 * parcourent les sociétés elles-mêmes, comme la facturation récurrente et la
 * file des webhooks. Pour les autres, la première société de l'instance est le
 * repli, et c'est exactement le comportement d'avant le cloisonnement sur une
 * instance à une société.
 */
function perimetreParDefaut() {
  const premiere = db.prepare('SELECT id FROM companies WHERE active = 1 ORDER BY id LIMIT 1').get();
  return premiere ? premiere.id : null;
}

/** Les sociétés d'une personne, pour l'écran qui les montre. */
function desQuelles(userId) {
  return db.prepare(`
    SELECT c.* FROM companies c JOIN company_members m ON m.company_id = c.id
    WHERE m.user_id = ? ORDER BY c.name COLLATE NOCASE
  `).all(Number(userId) || 0);
}

/**
 * L'intergiciel : il pose le périmètre sur la requête, et rien de plus.
 *
 * Posé une fois en amont des routeurs, il évite que chaque écran ait à se
 * demander dans quelle société il se trouve. Il ne refuse rien : une personne
 * sans aucun rattachement garde un périmètre nul, et ce sont les requêtes qui ne
 * rendront rien — refuser ici fermerait aussi les écrans qui n'ont pas de
 * périmètre du tout, comme son propre profil.
 */
function middleware(req, res, next) {
  const societe = courante(req);
  req.societeId = societe ? societe.id : null;
  res.locals.societe = societe;
  res.locals.societesOuvertes = societe ? accessibles(req.session.user) : [];

  /*
   * Tout ce qui suit s'exécute dans le périmètre : les routeurs, les modules
   * qu'ils appellent, et ce qu'ils attendent. « req.societeId » reste posé pour
   * les écrans qui préfèrent le lire explicitement, mais plus aucune signature
   * n'a besoin de le porter.
   */
  dans(req.societeId, next);
}

module.exports = {
  courant,
  dans,
  accessibles,
  perimetreParDefaut,
  garantirPremiere,
  parId,
  toutes,
  compter,
  ouverte,
  courante,
  choisir,
  creer,
  modifier,
  basculer,
  membres,
  rattacher,
  detacher,
  desQuelles,
  middleware,
  TABLES_SOUS_GARDE: cloison.SOUS_GARDE,
};
