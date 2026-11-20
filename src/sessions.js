const db = require('./db');

/**
 * Les sessions ouvertes d'un compte, lisibles par qui les a ouvertes.
 *
 * ── Pourquoi cet écran existe ────────────────────────────────────────────
 *
 * Une session ne retenait que son identifiant et sa date d'expiration. La
 * console de sécurité pouvait donc en afficher la liste, mais cette liste ne
 * disait rien : quatre lignes identiques, sans moyen de reconnaître la sienne
 * — donc sans moyen de reconnaître celle d'un autre. Et le salarié, lui, ne
 * voyait rien du tout.
 *
 * Le seul recours devant un doute était « fermer toutes mes sessions », qui
 * ferme aussi la sienne. C'est un geste qu'on ne fait pas par précaution : on
 * le fait une fois certain, et on n'est jamais certain puisque rien ne se voit.
 * Voir où son compte est ouvert est la façon dont les gens découvrent qu'il
 * l'est ailleurs.
 *
 * ── Ce que l'on retient, et ce que l'on ne retient pas ───────────────────
 *
 * L'adresse, l'agent déclaré par le navigateur, l'ouverture et la dernière
 * vue. Rien de plus : pas de page visitée, pas d'empreinte de navigateur, pas
 * de géolocalisation. Le but est de reconnaître son propre appareil, pas de
 * suivre quelqu'un — un journal des pages visitées par chacun serait, lui, une
 * surveillance, et il n'a pas sa place ici.
 *
 * Tout part à la fermeture de la session : la ligne est effacée, pas archivée.
 */

/** Au-delà, on réécrit « dernière vue ». En deçà, on laisse la base tranquille. */
const SEEN_THROTTLE_MS = 60 * 1000;

const stmt = {
  stamp: db.prepare(`
    UPDATE sessions SET created_at = COALESCE(created_at, ?), last_seen_at = ?, ip = ?, user_agent = ?
    WHERE sid = ?
  `),
  seen: db.prepare('UPDATE sessions SET last_seen_at = ? WHERE sid = ?'),
  forUser: db.prepare(`
    SELECT sid, created_at, last_seen_at, ip, user_agent, expires_at
    FROM sessions WHERE user_id = ? AND expires_at > ?
    ORDER BY COALESCE(last_seen_at, created_at, expires_at) DESC
  `),
  one: db.prepare('SELECT sid FROM sessions WHERE sid = ? AND user_id = ?'),
  close: db.prepare('DELETE FROM sessions WHERE sid = ? AND user_id = ?'),
};

/*
 * Reconnaître un appareil, pas identifier un navigateur.
 *
 * Cette liste est courte et le restera. Elle n'a qu'un travail : que la
 * personne retrouve sa propre ligne — « Firefox sur Windows », c'est assez
 * pour dire « ça c'est moi » et « ça, non ». L'ordre compte, parce que les
 * agents se citent les uns les autres : Edge dit « Chrome », Chrome dit
 * « Safari », et tout le monde dit « Mozilla ».
 */
const BROWSERS = [
  [/Edg[eA]?\//, 'Edge'],
  [/OPR\/|Opera/, 'Opera'],
  [/Firefox\//, 'Firefox'],
  [/Chrome\//, 'Chrome'],
  [/Safari\//, 'Safari'],
];

const SYSTEMS = [
  [/iPhone/, 'iPhone'],
  [/iPad/, 'iPad'],
  [/Android/, 'Android'],
  [/Windows/, 'Windows'],
  [/Mac OS X|Macintosh/, 'Mac'],
  [/Linux/, 'Linux'],
];

function match(list, agent) {
  for (const [pattern, name] of list) if (pattern.test(agent)) return name;
  return null;
}

/**
 * Un nom d'appareil lisible, ou null quand on ne sait pas.
 *
 * Null plutôt qu'« Inconnu » : la vue décide comment le dire, dans sa langue.
 * Un agent absent ou fantaisiste est la normale pour un script, et ce n'est pas
 * en soi une anomalie — c'est à la personne d'en juger devant la ligne.
 */
function label(userAgent) {
  const agent = String(userAgent || '');
  if (!agent) return null;
  const browser = match(BROWSERS, agent);
  const system = match(SYSTEMS, agent);
  if (browser && system) return `${browser} sur ${system}`;
  return browser || system;
}

/**
 * Noter d'où vient cette requête, sur la session qui la porte.
 *
 * « created_at » ne s'écrit qu'une fois — COALESCE s'en charge —, sinon
 * « ouverte depuis » repartirait de zéro à chaque page. « last_seen_at » est
 * bridée : sans cela, chaque image et chaque feuille de style écrirait en base.
 */
function stamp(req) {
  const sid = req.sessionID;
  if (!sid || !req.session || !req.session.user) return;

  const now = Date.now();
  const vu = req.session.seenStampedAt || 0;
  if (now - vu < SEEN_THROTTLE_MS) return;
  req.session.seenStampedAt = now;

  const agent = String(req.get('user-agent') || '').slice(0, 300);
  stmt.stamp.run(now, now, req.ip || null, agent || null, sid);
}

/** Le middleware qui l'appelle, sans jamais faire échouer la requête. */
function middleware(req, res, next) {
  try {
    stamp(req);
  } catch {
    // Une session qu'on n'a pas su dater reste une session valable : ce n'est
    // pas cette écriture-là qui doit renvoyer quelqu'un sur la page d'erreur.
  }
  next();
}

/**
 * Les sessions ouvertes d'un compte, la courante marquée.
 *
 * On passe le sid courant plutôt que de le deviner : la console de sécurité
 * regarde le compte d'un autre, et rien n'y est « la vôtre ».
 */
function forUser(userId, currentSid = null) {
  return stmt.forUser.all(userId, Date.now()).map((row) => ({
    sid: row.sid,
    current: row.sid === currentSid,
    label: label(row.user_agent),
    userAgent: row.user_agent || null,
    ip: row.ip || null,
    createdAt: row.created_at || null,
    lastSeenAt: row.last_seen_at || null,
    expiresAt: row.expires_at,
  }));
}

/**
 * Fermer une session, et une seule.
 *
 * Le compte fait partie de la condition, pas seulement de la vérification : un
 * identifiant de session deviné ou relevé ailleurs ne ferme que ce qui
 * appartient à qui le présente. Rendre « false » quand rien n'a bougé permet à
 * l'appelant de ne pas annoncer une fermeture qui n'a pas eu lieu.
 */
function close(userId, sid) {
  return stmt.close.run(String(sid || ''), userId).changes > 0;
}

module.exports = { label, stamp, middleware, forUser, close, SEEN_THROTTLE_MS };
