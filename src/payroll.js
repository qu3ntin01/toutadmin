const db = require('./db');
const settings = require('./settings');

const RATE_BASES = ['Brut', 'Plafond'];

// Plafond mensuel de la sécurité sociale : une valeur de paramétrage, pas une
// constante du code — elle change chaque année.
const DEFAULT_CEILING = 3925;

/**
 * Barèmes pré-remplis à l'activation, à titre indicatif. Ce sont des ordres de
 * grandeur du régime général français : ils doivent être vérifiés et ajustés
 * par le gestionnaire de paie avant tout usage réel.
 */
const DEFAULT_RATES = [
  ['Sécurité sociale — maladie', 'Brut', 0, 13, 10],
  ['Sécurité sociale — vieillesse plafonnée', 'Plafond', 6.9, 8.55, 20],
  ['Sécurité sociale — vieillesse déplafonnée', 'Brut', 0.4, 2.02, 30],
  ['Allocations familiales', 'Brut', 0, 5.25, 40],
  ['Retraite complémentaire T1', 'Plafond', 3.15, 4.72, 50],
  ['Assurance chômage', 'Brut', 0, 4.05, 60],
  ['CSG déductible', 'Brut', 6.8, 0, 70],
  ['CSG/CRDS non déductible', 'Brut', 2.9, 0, 80],
];

const round = (n) => Math.round(n * 100) / 100;

function ceiling() {
  const value = Number(settings.get('payroll_ceiling'));
  return Number.isFinite(value) && value > 0 ? value : DEFAULT_CEILING;
}

function setCeiling(value) {
  settings.set('payroll_ceiling', String(value));
}

/** Idempotent : réactiver le module ne duplique pas les barèmes. */
function seedDefaults() {
  if (db.prepare('SELECT COUNT(*) AS n FROM payroll_rates').get().n > 0) return;
  const insert = db.prepare('INSERT INTO payroll_rates (label, base, employee_rate, employer_rate, sort_order) VALUES (?, ?, ?, ?, ?)');
  const seed = db.transaction(() => {
    for (const row of DEFAULT_RATES) insert.run(...row);
  });
  seed();
}

// ---------- Barèmes ----------

function rates({ activeOnly = false } = {}) {
  const where = activeOnly ? 'WHERE active = 1' : '';
  return db.prepare(`SELECT * FROM payroll_rates ${where} ORDER BY sort_order, id`).all();
}

function createRate({ label, base, employeeRate, employerRate, sortOrder }) {
  if (!RATE_BASES.includes(base)) return { ok: false, reason: 'bad-base' };
  for (const rate of [employeeRate, employerRate]) {
    if (!Number.isFinite(rate) || rate < 0 || rate > 100) return { ok: false, reason: 'bad-rate' };
  }
  db.prepare('INSERT INTO payroll_rates (label, base, employee_rate, employer_rate, sort_order) VALUES (?, ?, ?, ?, ?)')
    .run(label, base, employeeRate, employerRate, sortOrder || 0);
  return { ok: true };
}

function toggleRate(id) {
  const rate = db.prepare('SELECT * FROM payroll_rates WHERE id = ?').get(id);
  if (!rate) return false;
  db.prepare('UPDATE payroll_rates SET active = ? WHERE id = ?').run(rate.active ? 0 : 1, id);
  return true;
}

function deleteRate(id) {
  db.prepare('DELETE FROM payroll_rates WHERE id = ?').run(id);
}

// ---------- Calcul ----------

/**
 * Décompose un brut mensuel : chaque barème s'applique sur le brut ou sur la
 * part plafonnée, et produit une part salariale et une part patronale.
 * Le net à payer est le brut moins les seules cotisations salariales.
 */
function compute(grossSalary, { rateList } = {}) {
  const gross = round(grossSalary);
  const cap = ceiling();
  const applicable = rateList || rates({ activeOnly: true });

  const lines = applicable.map((rate, index) => {
    const base = rate.base === 'Plafond' ? Math.min(gross, cap) : gross;
    return {
      label: rate.label,
      baseAmount: round(base),
      employeeRate: rate.employee_rate,
      employerRate: rate.employer_rate,
      employeeAmount: round(base * (rate.employee_rate / 100)),
      employerAmount: round(base * (rate.employer_rate / 100)),
      sortOrder: rate.sort_order ?? index,
    };
  });

  const employeeTotal = round(lines.reduce((sum, l) => sum + l.employeeAmount, 0));
  const employerTotal = round(lines.reduce((sum, l) => sum + l.employerAmount, 0));

  return {
    gross,
    ceiling: cap,
    lines,
    employeeTotal,
    employerTotal,
    net: round(gross - employeeTotal),
    employerCost: round(gross + employerTotal),
  };
}

// ---------- Bulletins ----------

/** Le bulletin calculé rejoint les fiches de paie déjà connues du salarié. */
function generatePayslip({ employeeId, period, grossSalary, note, createdBy }) {
  const employee = db.prepare("SELECT * FROM users WHERE id = ? AND role = 'employee'").get(employeeId);
  if (!employee) return { ok: false, reason: 'no-employee' };
  if (employee.contract_type === 'Freelance') return { ok: false, reason: 'freelance' };
  if (!Number.isFinite(grossSalary) || grossSalary <= 0 || grossSalary > 1e6) return { ok: false, reason: 'bad-gross' };
  if (db.prepare('SELECT 1 AS ok FROM payslips WHERE employee_id = ? AND period = ?').get(employeeId, period)) {
    return { ok: false, reason: 'duplicate' };
  }

  const result = compute(grossSalary);

  const commit = db.transaction(() => {
    const payslipId = db.prepare(`
      INSERT INTO payslips (employee_id, period, gross_amount, net_amount, note, created_by)
      VALUES (?, ?, ?, ?, ?, ?)
    `).run(employeeId, period, result.gross, result.net, note || '', createdBy).lastInsertRowid;

    const line = db.prepare(`
      INSERT INTO payslip_lines (payslip_id, label, base_amount, employee_rate, employer_rate, employee_amount, employer_amount, sort_order)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    `);
    for (const l of result.lines) {
      line.run(payslipId, l.label, l.baseAmount, l.employeeRate, l.employerRate, l.employeeAmount, l.employerAmount, l.sortOrder);
    }
    return payslipId;
  });

  return { ok: true, id: commit(), result };
}

function payslipLines(payslipId) {
  return db.prepare('SELECT * FROM payslip_lines WHERE payslip_id = ? ORDER BY sort_order, id').all(payslipId);
}

/** Un bulletin détaillé porte des lignes ; les fiches saisies à la main n'en ont pas. */
function detailedPayslips(limit = 200) {
  return db.prepare(`
    SELECT p.*, u.first_name, u.last_name,
      (SELECT COUNT(*) FROM payslip_lines l WHERE l.payslip_id = p.id) AS line_count,
      (SELECT COALESCE(SUM(l.employer_amount), 0) FROM payslip_lines l WHERE l.payslip_id = p.id) AS employer_total
    FROM payslips p JOIN users u ON u.id = p.employee_id
    ORDER BY p.period DESC, u.last_name COLLATE NOCASE
    LIMIT ?
  `).all(limit).map((p) => ({ ...p, employer_cost: round(p.gross_amount + p.employer_total) }));
}

function setGrossSalary(employeeId, grossSalary) {
  db.prepare('UPDATE users SET gross_salary = ? WHERE id = ?').run(grossSalary, employeeId);
}

/** Masse salariale du mois : ce que la paie coûte réellement à l'entreprise. */
function payrollCost(period) {
  const row = db.prepare(`
    SELECT COALESCE(SUM(p.gross_amount), 0) AS gross,
           COALESCE(SUM(p.net_amount), 0) AS net,
           COALESCE((SELECT SUM(l.employer_amount) FROM payslip_lines l
                     JOIN payslips q ON q.id = l.payslip_id WHERE q.period = ?), 0) AS employer
    FROM payslips p WHERE p.period = ?
  `).get(period, period);
  return { gross: round(row.gross), net: round(row.net), employer: round(row.employer), cost: round(row.gross + row.employer) };
}

module.exports = {
  RATE_BASES,
  DEFAULT_CEILING,
  ceiling,
  setCeiling,
  seedDefaults,
  rates,
  createRate,
  toggleRate,
  deleteRate,
  compute,
  generatePayslip,
  payslipLines,
  detailedPayslips,
  setGrossSalary,
  payrollCost,
};
