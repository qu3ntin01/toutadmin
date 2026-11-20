const db = require('./db');
const currency = require('./currency');
const societes = require('./societes');
const { addMonths } = require('./utils');

/**
 * Le périmètre courant.
 *
 * Les abonnements sont désormais cloisonnés eux aussi : la tâche planifiée qui
 * les émet travaille dans la société qui mène, et n'émet donc que les factures
 * de celle-là. Une instance à plusieurs sociétés demandera à la tâche de passer
 * dans chacune — c'est le dernier endroit où le périmètre vient du contexte et
 * non de la ligne traitée, et il est noté dans la feuille de route.
 */
const ici = () => societes.courant();

/**
 * Facturation récurrente.
 *
 * Un abonnement est un moule ; la facture est la pièce. Le moule porte la date
 * de la prochaine émission plutôt qu'une règle à rejouer depuis l'origine :
 * une échéance sautée ou avancée à la main ne dérègle pas la suite.
 *
 * Rien n'est émis d'avance : la facture du mois prochain n'existe pas encore,
 * et l'abonnement peut être arrêté d'ici là.
 */

const PERIODS = [
  { key: 'Mensuelle', months: 1 },
  { key: 'Trimestrielle', months: 3 },
  { key: 'Semestrielle', months: 6 },
  { key: 'Annuelle', months: 12 },
];

const DIRECTIONS = ['Client', 'Fournisseur'];

const today = () => new Date().toISOString().slice(0, 10);
const monthsOf = (period) => (PERIODS.find((p) => p.key === period) || PERIODS[0]).months;

function addDays(iso, days) {
  const date = new Date(`${iso}T00:00:00Z`);
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

function list({ activeOnly = false } = {}) {
  const clause = activeOnly ? 'AND s.active = 1' : '';
  return db.prepare(`
    SELECT s.*, p.name AS partner_name, d.name AS department_name,
           (SELECT COUNT(*) FROM invoices i WHERE i.subscription_id = s.id) AS issued_count
    FROM subscriptions s
    LEFT JOIN partners p ON p.id = s.partner_id
    LEFT JOIN departments d ON d.id = s.department_id
    WHERE s.company_id = ? ${clause}
    ORDER BY s.active DESC, s.next_issue
  `).all(ici()).map((row) => ({
    ...row,
    // Le montant se lit dans sa devise ; le total, lui, se lit en référence.
    amountTtc: Math.round(row.amount_ht * (1 + row.vat_rate / 100) * 100) / 100,
    over: Boolean(row.end_date && row.end_date < today()),
  }));
}

function byId(id) {
  return db.prepare('SELECT * FROM subscriptions WHERE id = ? AND company_id = ?')
    .get(Number(id) || 0, ici()) || null;
}

function create(fields) {
  const start = fields.startDate;
  return db.prepare(`
    INSERT INTO subscriptions (company_id, direction, partner_id, department_id, label, amount_ht, vat_rate, currency,
                               period, start_date, next_issue, end_date, payment_days, notes, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(ici(), fields.direction, fields.partnerId || null, fields.departmentId || null, fields.label,
    fields.amountHt, fields.vatRate, fields.currency || currency.base(), fields.period,
    start, fields.nextIssue || start, fields.endDate || null, fields.paymentDays ?? 30,
    fields.notes || '', fields.createdBy || null).lastInsertRowid;
}

function setActive(id, active) {
  return db.prepare('UPDATE subscriptions SET active = ? WHERE id = ? AND company_id = ?')
    .run(active ? 1 : 0, id, ici()).changes > 0;
}

function remove(id) {
  // Les factures déjà émises ne disparaissent pas avec le moule : elles sont
  // dues, et l'abonnement n'en est que l'origine.
  db.prepare('UPDATE invoices SET subscription_id = NULL WHERE subscription_id = ? AND company_id = ?')
    .run(id, ici());
  db.prepare('DELETE FROM subscriptions WHERE id = ? AND company_id = ?').run(id, ici());
}

/** Les abonnements dont l'échéance est atteinte à la date donnée. */
function due(asOf = today()) {
  return db.prepare(`
    SELECT * FROM subscriptions
    WHERE company_id = ? AND active = 1 AND next_issue <= ?
      AND (end_date IS NULL OR end_date >= next_issue)
    ORDER BY next_issue
  `).all(ici(), asOf);
}

const invoiceReference = (subscription, issueDate) => `AB-${subscription.id}-${issueDate.slice(0, 7)}`;

/**
 * Émet les factures dues et avance chaque abonnement d'une période. Chaque
 * abonnement est traité dans sa propre transaction : un taux de change manquant
 * sur l'un ne doit pas empêcher les autres de partir.
 *
 * Travaille dans la société courante, et dans elle seule. Le balayage qui
 * l'appelle n'a pas de session : c'est « runToutes » qui passe dans chacune.
 */
function run({ asOf = today(), createdBy = null } = {}) {
  const issued = [];
  const skipped = [];

  for (const subscription of due(asOf)) {
    const rate = currency.rateOf(subscription.currency);
    if (rate === null) {
      skipped.push({ id: subscription.id, label: subscription.label, reason: `taux ${subscription.currency} inconnu` });
      continue;
    }

    const issueDate = subscription.next_issue;
    const step = db.transaction(() => {
      const invoiceId = db.prepare(`
        INSERT INTO invoices (company_id, direction, partner_id, department_id, reference, label, issue_date, due_date,
                              amount_ht, vat_rate, status, notes, created_by, currency, exchange_rate, subscription_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Émise', ?, ?, ?, ?, ?)
      `).run(ici(), subscription.direction, subscription.partner_id, subscription.department_id,
        invoiceReference(subscription, issueDate),
        `${subscription.label} — ${issueDate.slice(0, 7)}`,
        issueDate, addDays(issueDate, subscription.payment_days),
        subscription.amount_ht, subscription.vat_rate,
        `Émise automatiquement depuis l'abonnement « ${subscription.label} ».`,
        createdBy, subscription.currency, rate, subscription.id).lastInsertRowid;

      const next = addMonths(issueDate, monthsOf(subscription.period));
      // Un abonnement dont le terme est passé s'éteint de lui-même plutôt que
      // de facturer au-delà de ce qui a été signé.
      const stillRunning = !subscription.end_date || next <= subscription.end_date;
      db.prepare('UPDATE subscriptions SET next_issue = ?, active = ? WHERE id = ? AND company_id = ?')
        .run(next, stillRunning ? 1 : 0, subscription.id, ici());

      return invoiceId;
    });

    try {
      issued.push({ id: step(), subscriptionId: subscription.id, label: subscription.label, issueDate });
    } catch (err) {
      // L'index unique (abonnement, date) a parlé : l'échéance est déjà facturée.
      skipped.push({ id: subscription.id, label: subscription.label, reason: /UNIQUE/i.test(err.message) ? 'déjà facturée' : err.message });
    }
  }

  return { issued, skipped };
}

/**
 * Le même travail, dans chaque société de l'instance.
 *
 * C'est ce que le balayage appelle, et la raison est simple : une tâche de fond
 * n'a pas de session, donc pas de société. Facturer la seule société qui mène
 * laisserait les abonnements des autres s'accumuler sans rien émettre, et
 * personne ne s'en apercevrait avant le premier impayé. Chaque société est
 * traitée à part, et un taux de change manquant dans l'une n'empêche pas les
 * autres de partir.
 */
function runToutes({ asOf = today(), createdBy = null } = {}) {
  const issued = [];
  const skipped = [];

  for (const societe of societes.toutes({ fermees: false })) {
    const verdict = societes.dans(societe.id, () => run({ asOf, createdBy }));
    for (const ligne of verdict.issued) issued.push({ ...ligne, societeId: societe.id });
    for (const ligne of verdict.skipped) skipped.push({ ...ligne, societeId: societe.id });
  }

  return { issued, skipped };
}

/** Ce que les abonnements actifs représentent sur douze mois, en devise de référence. */
function annualValue() {
  const perYear = { Mensuelle: 12, Trimestrielle: 4, Semestrielle: 2, Annuelle: 1 };
  let client = 0;
  let supplier = 0;

  for (const subscription of list({ activeOnly: true })) {
    const converted = currency.toBase(subscription.amount_ht, subscription.currency);
    if (converted === null) continue;
    const yearly = converted * (perYear[subscription.period] || 1);
    if (subscription.direction === 'Client') client += yearly;
    else supplier += yearly;
  }

  return {
    client: Math.round(client * 100) / 100,
    supplier: Math.round(supplier * 100) / 100,
    net: Math.round((client - supplier) * 100) / 100,
    currency: currency.base(),
  };
}

/**
 * Les métriques d'un revenu récurrent, sur les abonnements clients et en devise
 * de référence :
 *
 * - le revenu mensuel récurrent (MRR) : chaque abonnement en cours ramené au
 *   mois — un annuel de 1 200 € compte pour 100 € ; l'annuel (ARR) le
 *   multiplie par douze ;
 * - le revenu moyen par client, sur les tiers distincts ;
 * - l'attrition sur douze mois : ce qui a atteint sa date de fin dans l'année,
 *   rapporté à ce qui courait il y a un an, en nombre et en revenu.
 *
 * Un abonnement « en cours » a commencé, n'est pas arrivé à sa date de fin, et
 * n'est pas suspendu. Une suspension n'est pas datée : elle sort du revenu,
 * mais n'entre pas dans l'attrition, qui ne compte que les fins datées.
 */
function metriques(asOf = today()) {
  const ilYaUnAn = `${Number(asOf.slice(0, 4)) - 1}${asOf.slice(4)}`;
  const mensuel = (s) => {
    const converted = currency.toBase(s.amount_ht, s.currency);
    return converted === null ? null : converted / monthsOf(s.period);
  };
  const clients = db.prepare(`
    SELECT * FROM subscriptions WHERE company_id = ? AND direction = 'Client'
  `).all(ici());
  const enCours = (s, date) => s.start_date <= date && (!s.end_date || s.end_date >= date);

  let mrr = 0;
  const tiers = new Set();
  for (const s of clients.filter((x) => x.active === 1 && enCours(x, asOf))) {
    const m = mensuel(s);
    if (m === null) continue;
    mrr += m;
    tiers.add(s.partner_id === null ? `abonnement-${s.id}` : `tiers-${s.partner_id}`);
  }

  const debut = clients.filter((s) => enCours(s, ilYaUnAn));
  const perdus = debut.filter((s) => s.end_date && s.end_date < asOf);
  const somme = (liste) => liste.reduce((t, s) => t + (mensuel(s) || 0), 0);
  const mrrDebut = somme(debut);
  const mrrPerdu = somme(perdus);
  const arrondi = (n) => Math.round(n * 100) / 100;

  return {
    mrr: arrondi(mrr),
    arr: arrondi(mrr * 12),
    clients: tiers.size,
    arpa: tiers.size ? arrondi(mrr / tiers.size) : 0,
    attrition: {
      perdus: perdus.length,
      sur: debut.length,
      taux: mrrDebut > 0 ? Math.round((mrrPerdu / mrrDebut) * 1000) / 10 : null,
    },
    currency: currency.base(),
  };
}

module.exports = {
  metriques,
  PERIODS, DIRECTIONS, monthsOf, list, byId, create, setActive, remove, due, run, runToutes,
  annualValue, invoiceReference,
};
