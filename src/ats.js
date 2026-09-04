const db = require('./db');

const CRITERION_KINDS = ['Requis', 'Souhaité'];
const MAX_WEIGHT = 5;

/**
 * Normalisation : minuscules, accents retirés, ponctuation ramenée à des espaces.
 * « Développeur back-end » et « developpeur backend » doivent se rencontrer.
 */
function normalize(text) {
  return String(text || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9+#.]+/g, ' ')
    // Le point n'est gardé que s'il lie deux morceaux d'un même terme (node.js) ;
    // un point de fin de phrase se collerait sinon au mot et le rendrait introuvable.
    .replace(/\.(?![a-z0-9])/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

/** Les synonymes d'un critère : son intitulé, plus les mots-clés saisis. */
function termsOf(criterion) {
  const raw = [criterion.label, ...String(criterion.keywords || '').split(',')];
  return [...new Set(raw.map(normalize).filter(Boolean))];
}

/**
 * Un terme est trouvé s'il apparaît en frontière de mot : « java » ne doit pas
 * se déclencher sur « javascript », et « api » pas sur « rapide ».
 */
function contains(haystack, term) {
  if (!term) return false;
  const escaped = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return new RegExp(`(^| )${escaped}( |$)`).test(haystack);
}

const YEAR_PATTERNS = [
  /(\d{1,2})\s*(?:\+)?\s*(?:ans?|annees?)\s*(?:d\s*)?(?:experience|exp)/,
  /experience\s*(?:de|:)?\s*(\d{1,2})\s*(?:ans?|annees?)/,
];

/** Années d'expérience annoncées dans le CV, quand la phrase est explicite. */
function detectExperience(cvText) {
  const text = normalize(cvText);
  for (const pattern of YEAR_PATTERNS) {
    const match = text.match(pattern);
    if (match) {
      const years = Number(match[1]);
      if (Number.isFinite(years) && years >= 0 && years <= 60) return years;
    }
  }
  return null;
}

// ---------- Critères ----------

function criteriaOf(openingId) {
  return db.prepare('SELECT * FROM opening_criteria WHERE opening_id = ? ORDER BY kind, id').all(openingId);
}

function createCriterion({ openingId, label, keywords, kind, weight }) {
  if (!CRITERION_KINDS.includes(kind)) return { ok: false, reason: 'bad-kind' };
  if (!Number.isInteger(weight) || weight < 1 || weight > MAX_WEIGHT) return { ok: false, reason: 'bad-weight' };
  if (!db.prepare('SELECT 1 AS ok FROM job_openings WHERE id = ?').get(openingId)) return { ok: false, reason: 'no-opening' };

  db.prepare('INSERT INTO opening_criteria (opening_id, label, keywords, kind, weight) VALUES (?, ?, ?, ?, ?)')
    .run(openingId, label, keywords || '', kind, weight);
  return { ok: true };
}

function deleteCriterion(id) {
  const criterion = db.prepare('SELECT * FROM opening_criteria WHERE id = ?').get(id);
  if (!criterion) return null;
  db.prepare('DELETE FROM opening_criteria WHERE id = ?').run(id);
  return criterion.opening_id;
}

function setOpeningAts(openingId, { minExperience, threshold }) {
  if (!Number.isFinite(minExperience) || minExperience < 0 || minExperience > 60) return { ok: false, reason: 'bad-experience' };
  if (!Number.isInteger(threshold) || threshold < 0 || threshold > 100) return { ok: false, reason: 'bad-threshold' };

  db.prepare('UPDATE job_openings SET min_experience = ?, ats_threshold = ? WHERE id = ?')
    .run(minExperience, threshold, openingId);
  return { ok: true };
}

// ---------- Évaluation ----------

/**
 * Score pondéré : chaque critère satisfait apporte son poids, rapporté au total
 * des poids. Le score reste une aide au tri — il ne décide de rien tout seul,
 * et les critères requis manquants sont énoncés à part plutôt que noyés dedans.
 */
function evaluate(candidate, opening, criteria) {
  const cvText = normalize(candidate.cv_text);
  const hasCv = cvText.length > 0;

  const rows = criteria.map((criterion) => {
    const terms = termsOf(criterion);
    const matched = terms.find((term) => contains(cvText, term)) || null;
    return {
      id: criterion.id,
      label: criterion.label,
      kind: criterion.kind,
      weight: criterion.weight,
      matched: Boolean(matched),
      matchedOn: matched,
    };
  });

  const totalWeight = rows.reduce((sum, r) => sum + r.weight, 0);
  const gained = rows.filter((r) => r.matched).reduce((sum, r) => sum + r.weight, 0);
  const score = totalWeight > 0 ? Math.round((gained / totalWeight) * 100) : 0;

  const missingRequired = rows.filter((r) => r.kind === 'Requis' && !r.matched).map((r) => r.label);

  const declared = candidate.experience_years;
  const detected = detectExperience(candidate.cv_text);
  const years = declared != null ? declared : detected;
  const experienceShort = opening.min_experience > 0 && years != null && years < opening.min_experience;

  return {
    score: hasCv ? score : 0,
    hasCv,
    rows,
    totalWeight,
    gained,
    missingRequired,
    years,
    yearsSource: declared != null ? 'déclarée' : (detected != null ? 'détectée dans le CV' : null),
    experienceShort,
    // Un dossier n'est retenu que s'il a un CV, dépasse le seuil, ne manque
    // aucun critère requis et satisfait l'expérience minimale.
    shortlisted: hasCv && score >= opening.ats_threshold && missingRequired.length === 0 && !experienceShort,
  };
}

/** Recalcule et mémorise le score d'une candidature. */
function rescoreCandidate(candidateId) {
  const candidate = db.prepare('SELECT * FROM candidates WHERE id = ?').get(candidateId);
  if (!candidate) return null;

  const opening = db.prepare('SELECT * FROM job_openings WHERE id = ?').get(candidate.opening_id);
  if (!opening) return null;

  const result = evaluate(candidate, opening, criteriaOf(opening.id));
  db.prepare('UPDATE candidates SET ats_score = ?, ats_detail = ? WHERE id = ?')
    .run(result.score, JSON.stringify(result), candidateId);
  return result;
}

/** Un critère qui change périme tous les scores du poste : on les refait. */
function rescoreOpening(openingId) {
  const ids = db.prepare('SELECT id FROM candidates WHERE opening_id = ?').all(openingId);
  for (const { id } of ids) rescoreCandidate(id);
  return ids.length;
}

/** Les candidatures d'un poste, du meilleur score au moins bon. */
function rankedCandidates(openingId) {
  const opening = db.prepare('SELECT * FROM job_openings WHERE id = ?').get(openingId);
  if (!opening) return [];
  const criteria = criteriaOf(openingId);

  return db.prepare('SELECT * FROM candidates WHERE opening_id = ? ORDER BY created_at DESC').all(openingId)
    .map((candidate) => ({ ...candidate, ats: evaluate(candidate, opening, criteria) }))
    .sort((a, b) => b.ats.score - a.ats.score || a.last_name.localeCompare(b.last_name));
}

/**
 * CVthèque : recherche plein texte sur tous les CV reçus, tous postes confondus.
 * Un bon profil arrivé sur un autre poste ne doit pas être perdu.
 */
function searchCvs(query, { limit = 50 } = {}) {
  const terms = normalize(query).split(' ').filter(Boolean);
  if (terms.length === 0) return [];

  const rows = db.prepare(`
    SELECT c.*, o.title AS opening_title, o.status AS opening_status
    FROM candidates c JOIN job_openings o ON o.id = c.opening_id
    WHERE c.cv_text != ''
    ORDER BY c.created_at DESC
  `).all();

  return rows
    .map((row) => {
      const haystack = normalize(`${row.first_name} ${row.last_name} ${row.cv_text}`);
      const hits = terms.filter((term) => contains(haystack, term));
      return { ...row, hits, matchedAll: hits.length === terms.length };
    })
    .filter((row) => row.hits.length > 0)
    .sort((a, b) => b.hits.length - a.hits.length)
    .slice(0, limit);
}

module.exports = {
  CRITERION_KINDS,
  MAX_WEIGHT,
  normalize,
  termsOf,
  contains,
  detectExperience,
  criteriaOf,
  createCriterion,
  deleteCriterion,
  setOpeningAts,
  evaluate,
  rescoreCandidate,
  rescoreOpening,
  rankedCandidates,
  searchCvs,
};
