const crypto = require('crypto');

const db = require('./db');
const secrets = require('./secret-store');
const societes = require('./societes');
const cloison = require('./cloison');

/** Le périmètre courant : un référent n'instruit que les alertes de sa société. */
const ici = () => societes.courant();

/**
 * Dispositif de recueil des signalements internes.
 *
 * Obligatoire en France dès cinquante salariés depuis la loi du 21 mars 2022,
 * et impossible à bâcler : un canal d'alerte que l'on n'ose pas emprunter ne
 * sert à rien, et c'est la conception qui décide si on ose.
 *
 * Trois règles en découlent, et elles commandent tout le reste :
 *
 * 1. **L'anonymat est un droit.** Un signalement anonyme n'enregistre pas son
 *    auteur — et le journal d'audit général ne doit pas le rattraper par la
 *    bande (voir la route, qui neutralise la trace automatique).
 * 2. **Le contenu est chiffré.** Une copie de la base ne livre pas les
 *    signalements, ni ce qui s'y dit ensuite.
 * 3. **Les référents seuls y accèdent**, et pas les administrateurs en tant que
 *    tels : une alerte peut viser un administrateur. L'administration désigne
 *    les référents — ce qui, lui, est tracé — mais ne lit rien.
 *
 * Ce que cela ne protège pas : l'accès direct au serveur. La clé de chiffrement
 * dérive du fichier de session, donc voler la base seule ne suffit pas ; qui
 * tient la machine tient tout. C'est une limite du produit, pas un oubli.
 */

const CATEGORIES = [
  'Corruption',
  'Fraude',
  'Harcèlement',
  'Discrimination',
  'Sécurité des personnes',
  'Environnement',
  'Données personnelles',
  'Autre',
];

const REPORT_STATUSES = ['Reçue', 'Recevable', 'Irrecevable', 'En instruction', 'Clôturée'];

// Délais légaux : accusé de réception sous 7 jours, retour sur les suites
// données sous 3 mois. Ce ne sont pas des conventions internes, ils se comptent.
const ACK_DAYS = 7;
const OUTCOME_DAYS = 90;

// Alphabet sans les caractères qui se confondent : le code est recopié à la main.
const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const CODE_LENGTH = 20;

function newCode() {
  let code = '';
  for (let i = 0; i < CODE_LENGTH; i += 1) code += CODE_ALPHABET[crypto.randomInt(CODE_ALPHABET.length)];
  return code;
}

/**
 * Le code n'est gardé que haché. Vingt caractères tirés au sort dans un
 * alphabet de trente et un font une centaine de bits : le deviner n'est pas
 * une attaque réaliste, et un simple SHA-256 suffit donc — ce n'est pas un
 * mot de passe choisi par un humain.
 */
function hashCode(code) {
  return crypto.createHash('sha256').update(String(code).trim().toUpperCase()).digest('hex');
}

/*
 * La référence est un identifiant d'instance, pas de société : c'est elle que
 * l'auteur recopie pour suivre son alerte, et il n'a aucun moyen de désigner la
 * société en plus. Deux sociétés ne doivent donc jamais pouvoir produire la
 * même — d'où le comptage hors périmètre, et l'unicité globale en base.
 */
function nextReference() {
  const year = new Date().getFullYear();
  const count = cloison.sansPerimetre(() => db
    .prepare('SELECT COUNT(*) AS n FROM whistleblow_reports WHERE reference LIKE ?')
    .get(`ALT-${year}-%`).n);
  return `ALT-${year}-${String(count + 1).padStart(4, '0')}`;
}

// ---------------------------------------------------------------- dépôt

function create({ authorId = null, anonymous = true, category, subject, body }) {
  const reference = nextReference();
  const code = newCode();

  db.prepare(`
    INSERT INTO whistleblow_reports (company_id, reference, follow_code_hash, category, subject_enc, body_enc, author_id, anonymous)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  `).run(
    ici(), reference, hashCode(code), category,
    secrets.encrypt(subject), secrets.encrypt(body || ''),
    // Un signalement anonyme n'enregistre pas son auteur. Pas « le masque » : ne
    // l'enregistre pas — un masque se retire.
    anonymous ? null : authorId,
    anonymous ? 1 : 0
  );

  // Le code n'est montré qu'une fois, à cet instant : il n'existe plus ailleurs.
  return { reference, code };
}

// ---------------------------------------------------------------- lecture

function decorate(row) {
  if (!row) return null;
  return {
    ...row,
    subject: secrets.decrypt(row.subject_enc),
    body: secrets.decrypt(row.body_enc),
    outcome: secrets.decrypt(row.outcome_enc),
  };
}

function byId(id) {
  return decorate(db.prepare('SELECT * FROM whistleblow_reports WHERE id = ? AND company_id = ?')
    .get(Number(id) || 0, ici()));
}

function byReference(reference) {
  return decorate(db.prepare('SELECT * FROM whistleblow_reports WHERE reference = ? AND company_id = ?')
    .get(String(reference || '').trim().toUpperCase(), ici()));
}

/** Le suivi par l'auteur : référence et code doivent aller ensemble. */
function openFollow(reference, code) {
  /*
   * Hors périmètre, et c'est voulu : ce qui autorise ici, c'est le code, pas la
   * société. L'auteur d'une alerte anonyme n'est pas connecté — il n'a qu'une
   * référence et vingt caractères, et son alerte doit s'ouvrir quelle que soit
   * la société que la session aurait choisie par défaut.
   */
  const row = cloison.sansPerimetre(() => db
    .prepare('SELECT * FROM whistleblow_reports WHERE reference = ?')
    .get(String(reference || '').trim().toUpperCase()));
  if (!row) return null;

  const given = Buffer.from(hashCode(code));
  const stored = Buffer.from(row.follow_code_hash);
  // Comparaison à durée constante : le temps de réponse ne doit pas indiquer
  // combien de caractères du code sont justes.
  if (given.length !== stored.length || !crypto.timingSafeEqual(given, stored)) return null;
  return decorate(row);
}

function list({ includeClosed = true } = {}) {
  const clause = includeClosed ? '' : "AND r.status != 'Clôturée'";
  return db.prepare(`
    SELECT r.*, u.first_name, u.last_name
    FROM whistleblow_reports r LEFT JOIN users u ON u.id = r.author_id
    WHERE r.company_id = ? ${clause}
    ORDER BY r.status = 'Clôturée', r.submitted_at DESC
  `).all(ici()).map(decorate);
}

function messages(reportId) {
  return db.prepare('SELECT * FROM whistleblow_messages WHERE report_id = ? ORDER BY created_at, id').all(reportId)
    .map((row) => ({ ...row, body: secrets.decrypt(row.body_enc) }));
}

function addMessage({ reportId, kind, referentId = null, body }) {
  const text = String(body || '').trim();
  if (!text) return false;
  db.prepare(`
    INSERT INTO whistleblow_messages (report_id, author_kind, referent_id, body_enc)
    VALUES (?, ?, ?, ?)
  `).run(reportId, kind, kind === 'referent' ? referentId : null, secrets.encrypt(text.slice(0, 5000)));
  return true;
}

/** Qui a ouvert quel signalement : la trace vit ici, hors du journal général. */
function noteAccess(reportId, userId) {
  db.prepare('INSERT INTO whistleblow_access_log (report_id, user_id) VALUES (?, ?)').run(reportId, userId);
}

function accessLog(reportId) {
  return db.prepare(`
    SELECT a.occurred_at, u.first_name, u.last_name
    FROM whistleblow_access_log a LEFT JOIN users u ON u.id = a.user_id
    WHERE a.report_id = ? ORDER BY a.occurred_at DESC LIMIT 50
  `).all(reportId);
}

// ---------------------------------------------------------------- instruction

function acknowledge(id) {
  return db.prepare(`
    UPDATE whistleblow_reports SET acknowledged_at = datetime('now')
    WHERE id = ? AND company_id = ? AND acknowledged_at IS NULL
  `).run(id, ici()).changes > 0;
}

function setStatus(id, status) {
  if (!REPORT_STATUSES.includes(status)) return false;
  const closing = status === 'Clôturée';
  db.prepare(`
    UPDATE whistleblow_reports
    SET status = ?, closed_at = CASE WHEN ? = 1 THEN COALESCE(closed_at, datetime('now')) ELSE NULL END
    WHERE id = ? AND company_id = ?
  `).run(status, closing ? 1 : 0, id, ici());
  return true;
}

function setOutcome(id, outcome) {
  db.prepare('UPDATE whistleblow_reports SET outcome_enc = ? WHERE id = ? AND company_id = ?')
    .run(secrets.encrypt(String(outcome || '').slice(0, 4000)), id, ici());
}

// ---------------------------------------------------------------- délais

function daysSince(iso) {
  if (!iso) return null;
  const then = Date.parse(`${String(iso).replace(' ', 'T')}Z`);
  if (Number.isNaN(then)) return null;
  return Math.floor((Date.now() - then) / 86400000);
}

/** Les deux délais légaux, comptés plutôt que promis. */
function overdue() {
  const rows = list({ includeClosed: false });
  return {
    acknowledgement: rows.filter((r) => !r.acknowledged_at && daysSince(r.submitted_at) > ACK_DAYS),
    outcome: rows.filter((r) => daysSince(r.submitted_at) > OUTCOME_DAYS),
  };
}

function summary() {
  const open = db.prepare(`
    SELECT COUNT(*) AS n FROM whistleblow_reports WHERE company_id = ? AND status != 'Clôturée'
  `).get(ici()).n;
  const waiting = db.prepare(`
    SELECT COUNT(*) AS n FROM whistleblow_reports WHERE company_id = ? AND acknowledged_at IS NULL
  `).get(ici()).n;
  const late = overdue();
  return {
    open,
    waiting,
    lateAck: late.acknowledgement.length,
    lateOutcome: late.outcome.length,
    total: db.prepare('SELECT COUNT(*) AS n FROM whistleblow_reports WHERE company_id = ?').get(ici()).n,
  };
}

/** Les référents désignés : destinataires des échéances du dispositif. */
function referents() {
  return db.prepare('SELECT id, first_name, last_name FROM users WHERE active = 1 AND is_referent = 1').all();
}

module.exports = {
  CATEGORIES,
  REPORT_STATUSES,
  ACK_DAYS,
  OUTCOME_DAYS,
  create,
  byId,
  byReference,
  openFollow,
  list,
  messages,
  addMessage,
  noteAccess,
  accessLog,
  acknowledge,
  setStatus,
  setOutcome,
  daysSince,
  overdue,
  summary,
  referents,
};
