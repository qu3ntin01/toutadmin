const db = require('./db');
const rattachees = require('./rattachees');
const societes = require('./societes');

/** Le périmètre courant : les chiffres d'une société, pas ceux du groupe. */
const ici = () => societes.courant();

/**
 * Pilotage : les chiffres qu'une direction regarde, et les objectifs qu'elle
 * s'est fixés.
 *
 * Rien n'est recalculé ici de ce que les espaces savent déjà faire : ce module
 * agrège, il ne réinvente pas. Ce qui manque dans une instance (un module
 * éteint, une donnée non saisie) rend null plutôt que zéro — un zéro se lit
 * comme une information, un null comme une absence.
 */

const SCOPES = ['Entreprise', 'Service', 'Équipe'];
const OBJECTIVE_STATUSES = ['En cours', 'Atteint', 'Abandonné'];

function headcount() {
  const rows = db.prepare(`
    SELECT contract_type, COUNT(*) AS n FROM users
    WHERE active = 1 AND role = 'employee' GROUP BY contract_type
  `).all();
  const total = rows.reduce((sum, r) => sum + r.n, 0);
  return { total, byContract: rows };
}

function payrollMass() {
  const row = db.prepare("SELECT COALESCE(SUM(gross_salary), 0) AS total, COUNT(gross_salary) AS known FROM users WHERE active = 1 AND role = 'employee'").get();
  return row.known === 0 ? null : { monthly: Math.round(row.total * 100) / 100, known: row.known };
}

/**
 * Les bornes d'un exercice.
 *
 * Il commence au mois réglé dans la section « Pilotage ». À janvier — le cas
 * de la plupart des sociétés françaises — c'est l'année civile, et rien ne
 * change. Ailleurs, l'exercice 2026 d'une société qui clôture en septembre va
 * d'octobre 2025 à septembre 2026 : compter son chiffre d'affaires sur l'année
 * civile lui donnerait un total qu'aucun de ses documents comptables ne
 * confirme.
 *
 * L'exercice porte le millésime de l'année où il se termine, comme il se dit.
 */
function exerciceBornes(year) {
  // eslint-disable-next-line global-require
  const mois = require('./sections-reglages').nombre('pilotage', 'exercice_mois');
  if (mois === 1) return { debut: `${year}-01-01`, fin: `${year}-12-31` };
  const debut = new Date(Date.UTC(year - 1, mois - 1, 1));
  const fin = new Date(Date.UTC(year, mois - 1, 0));
  return { debut: debut.toISOString().slice(0, 10), fin: fin.toISOString().slice(0, 10) };
}

function revenue(year) {
  const { debut, fin } = exerciceBornes(year);
  const row = db.prepare(`
    SELECT
      -- Chaque facture est ramenée en devise de référence par le taux figé à
      -- son émission : un total qui mêle des devises ne veut rien dire.
      COALESCE(SUM(CASE WHEN direction = 'Client' THEN amount_ht * exchange_rate ELSE 0 END), 0) AS sales,
      COALESCE(SUM(CASE WHEN direction = 'Fournisseur' THEN amount_ht * exchange_rate ELSE 0 END), 0) AS purchases
    FROM invoices WHERE company_id = ? AND issue_date BETWEEN ? AND ?
  `).get(ici(), debut, fin);
  return {
    sales: Math.round(row.sales * 100) / 100,
    purchases: Math.round(row.purchases * 100) / 100,
    margin: Math.round((row.sales - row.purchases) * 100) / 100,
  };
}

function unpaid() {
  const row = db.prepare(`
    SELECT COUNT(*) AS n, COALESCE(SUM(amount_ht * (1 + vat_rate / 100.0) * exchange_rate), 0) AS total
    FROM invoices WHERE company_id = ? AND direction = 'Client' AND status != 'Payée'
  `).get(ici());
  return { count: row.n, total: Math.round(row.total * 100) / 100 };
}

function absences() {
  return db.prepare(`
    SELECT COUNT(*) AS n FROM hr_requests
    WHERE company_id = ? AND status = 'Approuvée'
      AND date(start_date) <= date('now') AND date(end_date) >= date('now')
  `).get(ici()).n;
}

function projectHealth() {
  const rows = db.prepare(`
    SELECT p.id, p.name, p.budget_amount, p.hourly_rate, p.due_date, p.status,
           (SELECT COALESCE(SUM(hours), 0) FROM project_time WHERE project_id = p.id) AS hours
    FROM projects p
    WHERE p.company_id = ? AND p.archived = 0 AND p.status NOT IN ('Livré', 'Clôturé')
  `).all(ici());

  return rows.map((p) => {
    const cost = p.hourly_rate == null ? null : Math.round(p.hours * p.hourly_rate * 100) / 100;
    const consumed = cost == null || !p.budget_amount ? null : Math.round((cost / p.budget_amount) * 100);
    return { ...p, cost, consumed, late: Boolean(p.due_date && p.due_date < new Date().toISOString().slice(0, 10)) };
  }).sort((a, b) => (b.consumed || 0) - (a.consumed || 0));
}

function ticketLoad() {
  const open = db.prepare(`
    SELECT COUNT(*) AS n FROM tickets
    WHERE company_id = ? AND status IN ('Ouvert','En cours','En attente')
  `).get(ici()).n;
  const month = db.prepare(`
    SELECT COUNT(*) AS n FROM tickets
    WHERE company_id = ? AND created_at >= date('now', 'start of month')
  `).get(ici()).n;
  return { open, month };
}

function overview(year = new Date().getUTCFullYear()) {
  return {
    year,
    headcount: headcount(),
    payroll: payrollMass(),
    revenue: revenue(year),
    exercice: exerciceBornes(year),
    unpaid: unpaid(),
    /*
     * Le seuil au-delà duquel les impayés se signalent, réglé dans la section.
     * À zéro il ne signale rien — un compteur d'impayés qui rougit dès le
     * premier euro ne rougit bientôt plus pour personne.
     */
    unpaidAlert: (() => {
      // eslint-disable-next-line global-require
      const seuil = require('./sections-reglages').nombre('pilotage', 'seuil_impayes');
      return seuil > 0 && unpaid().total > seuil;
    })(),
    absentToday: absences(),
    projects: projectHealth(),
    tickets: ticketLoad(),
    treasury: db.prepare('SELECT COUNT(*) AS n FROM bank_accounts WHERE company_id = ? AND active = 1')
      .get(ici()).n > 0
      ? require('./treasury').totalBalance()
      : null,
  };
}

// ---------- Objectifs et résultats clés ----------

function objectives({ scope = '', period = '' } = {}) {
  const clauses = ['o.company_id = ?'];
  const params = [ici()];
  if (scope) { clauses.push('o.scope = ?'); params.push(scope); }
  if (period) { clauses.push('o.period = ?'); params.push(period); }
  const where = `WHERE ${clauses.join(' AND ')}`;

  return db.prepare(`
    SELECT o.*, u.first_name, u.last_name FROM objectives o
    LEFT JOIN users u ON u.id = o.owner_id
    ${where}
    ORDER BY o.status != 'En cours', o.period DESC, o.id DESC
  `).all(...params).map((objective) => {
    const results = keyResults(objective.id);
    return { ...objective, results, progress: progressOf(results) };
  });
}

/**
 * L'avancement d'un objectif est la moyenne de ses résultats clés, chacun
 * ramené sur sa propre échelle : un compteur qui part de 40 et vise 60 est à
 * moitié quand il atteint 50, pas à 83 %.
 */
function progressOf(results) {
  if (results.length === 0) return 0;
  const ratios = results.map((r) => {
    const span = r.target_value - r.start_value;
    if (span === 0) return r.current_value >= r.target_value ? 1 : 0;
    return Math.max(0, Math.min(1, (r.current_value - r.start_value) / span));
  });
  return Math.round((ratios.reduce((a, b) => a + b, 0) / ratios.length) * 100);
}

function keyResults(objectiveId) {
  return db.prepare('SELECT * FROM key_results WHERE objective_id = ? ORDER BY id').all(objectiveId);
}

function objectiveById(id) {
  return db.prepare('SELECT * FROM objectives WHERE id = ? AND company_id = ?')
    .get(Number(id) || 0, ici()) || null;
}

function createObjective({ title, description, scope, scopeId, ownerId, period }) {
  return db.prepare(`
    INSERT INTO objectives (company_id, title, description, scope, scope_id, owner_id, period)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(ici(), title, description || '', scope, scopeId || null, ownerId || null, period || '').lastInsertRowid;
}

function setObjectiveStatus(id, status) {
  if (!OBJECTIVE_STATUSES.includes(status)) return false;
  db.prepare('UPDATE objectives SET status = ? WHERE id = ? AND company_id = ?').run(status, id, ici());
  return true;
}

function deleteObjective(id) {
  return db.prepare('DELETE FROM objectives WHERE id = ? AND company_id = ?').run(id, ici()).changes > 0;
}

function addKeyResult({ objectiveId, title, startValue, targetValue, currentValue, unit }) {
  return db.prepare(`
    INSERT INTO key_results (objective_id, title, start_value, target_value, current_value, unit)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(objectiveId, title, startValue, targetValue, currentValue, unit || '').lastInsertRowid;
}

function updateKeyResult(id, currentValue) {
  if (!rattachees.visible('key_results', id)) return null;
  const row = db.prepare('SELECT objective_id FROM key_results WHERE id = ?').get(id);
  if (!row) return null;
  db.prepare('UPDATE key_results SET current_value = ? WHERE id = ?').run(currentValue, id);
  return row.objective_id;
}

function deleteKeyResult(id) {
  if (!rattachees.visible('key_results', id)) return null;
  const row = db.prepare('SELECT objective_id FROM key_results WHERE id = ?').get(id);
  db.prepare('DELETE FROM key_results WHERE id = ?').run(id);
  return row ? row.objective_id : null;
}

module.exports = {
  exerciceBornes,
  SCOPES, OBJECTIVE_STATUSES,
  headcount, payrollMass, revenue, unpaid, projectHealth, ticketLoad, overview,
  objectives, keyResults, objectiveById, progressOf,
  createObjective, setObjectiveStatus, deleteObjective,
  addKeyResult, updateKeyResult, deleteKeyResult,
};
