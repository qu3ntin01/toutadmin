const db = require('./db');
const org = require('./org');
const notifications = require('./notifications');
const { parseAmount, isValidDateString } = require('./utils');

/**
 * Demandes internes et circuits d'approbation configurables.
 *
 * Les circuits déjà écrits — congés, notes de frais, demandes d'achat — sont
 * figés parce que la loi ou la comptabilité les fixe. Tout le reste varie d'une
 * entreprise à l'autre : avance sur salaire, déplacement, matériel, télétravail,
 * formation. Les figer dans le code obligerait à reprogrammer pour ajouter une
 * validation ; ils se décrivent donc ici, avec deux idées :
 *
 *   — **une étape désigne une fonction, pas une personne** (le manager, les RH,
 *     la gestion, la direction). Le circuit survit aux départs, et personne ne
 *     reste bloqué parce que le validateur nommé est parti ;
 *   — **un seuil** permet de n'appeler la direction qu'au-delà d'un montant.
 *     Une demande de 40 € et une demande de 40 000 € ne méritent pas le même
 *     nombre de signatures.
 *
 * Une demande avance étape par étape. Le premier des approbateurs d'une étape
 * qui se prononce engage l'étape : à deux managers, il n'en faut pas deux.
 */

const FIELD_TYPES = [
  { key: 'texte', label: 'Texte court' },
  { key: 'zone', label: 'Texte long' },
  { key: 'nombre', label: 'Nombre' },
  { key: 'montant', label: 'Montant' },
  { key: 'date', label: 'Date' },
  { key: 'choix', label: 'Liste de choix' },
];

const APPROVERS = [
  { key: 'manager', label: 'Le manager du demandeur' },
  { key: 'hr', label: "L'équipe RH" },
  { key: 'finance', label: 'La gestion financière' },
  { key: 'admin', label: "L'administration" },
  { key: 'user', label: 'Une personne désignée' },
];

const APPROVER_KEYS = APPROVERS.map((a) => a.key);
const MAX_FIELDS = 15;
const MAX_STEPS = 6;

// ---------- Types de demande ----------

function parseFields(raw) {
  try {
    const parsed = JSON.parse(raw || '[]');
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

function forms({ activeOnly = false } = {}) {
  const clause = activeOnly ? 'WHERE active = 1' : '';
  return db.prepare(`SELECT * FROM request_forms ${clause} ORDER BY label COLLATE NOCASE`).all()
    .map((row) => ({ ...row, fieldList: parseFields(row.fields), steps: stepsOf(row.id) }));
}

function formById(id) {
  const row = db.prepare('SELECT * FROM request_forms WHERE id = ?').get(Number(id) || 0);
  return row ? { ...row, fieldList: parseFields(row.fields), steps: stepsOf(row.id) } : null;
}

function stepsOf(formId) {
  return db.prepare(`
    SELECT s.*, u.first_name, u.last_name FROM request_steps s
    LEFT JOIN users u ON u.id = s.approver_id
    WHERE s.form_id = ? ORDER BY s.position, s.id
  `).all(formId);
}

function createForm({ label, description = '', fields = [], amountField = '', createdBy = null }) {
  const name = String(label || '').trim().slice(0, 120);
  if (!name) return { ok: false, message: 'Un intitulé est requis.' };
  if (!fields.length) return { ok: false, message: 'Décrivez au moins un champ à saisir.' };
  if (fields.length > MAX_FIELDS) return { ok: false, message: `${MAX_FIELDS} champs au maximum.` };

  const clean = [];
  for (const field of fields) {
    const key = String(field.name || '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    if (!key) return { ok: false, message: 'Chaque champ a besoin d\'un nom technique.' };
    if (!FIELD_TYPES.some((t) => t.key === field.type)) return { ok: false, message: `Type de champ inconnu : ${field.type}` };
    if (clean.some((f) => f.name === key)) return { ok: false, message: `Deux champs portent le même nom : ${key}` };

    clean.push({
      name: key,
      label: String(field.label || key).trim().slice(0, 120),
      type: field.type,
      required: Boolean(field.required),
      options: (field.options || []).map((o) => String(o).trim()).filter(Boolean).slice(0, 20),
    });
  }

  // Le champ qui porte le montant doit exister : sans lui, les seuils ne
  // s'appliqueraient jamais et le circuit passerait toujours au plus court.
  const amount = String(amountField || '').trim();
  if (amount && !clean.some((f) => f.name === amount)) return { ok: false, message: 'Le champ de montant désigné n\'existe pas.' };

  const id = db.prepare(`
    INSERT INTO request_forms (label, description, fields, amount_field, created_by)
    VALUES (?, ?, ?, ?, ?)
  `).run(name, String(description || '').slice(0, 1000), JSON.stringify(clean), amount, createdBy).lastInsertRowid;

  return { ok: true, id };
}

function setFormActive(id, active) {
  return db.prepare('UPDATE request_forms SET active = ? WHERE id = ?').run(active ? 1 : 0, id).changes > 0;
}

function deleteForm(id) {
  const running = db.prepare("SELECT COUNT(*) AS n FROM workflow_requests WHERE form_id = ? AND status = 'En cours'").get(id).n;
  if (running > 0) return { ok: false, message: `${running} demande(s) en cours sur ce type : suspendez-le plutôt que de l'effacer.` };

  db.prepare('DELETE FROM request_forms WHERE id = ?').run(id);
  return { ok: true };
}

function addStep(formId, { approver, approverId = null, label = '', threshold = 0 }) {
  const form = formById(formId);
  if (!form) return { ok: false, message: 'Type de demande inconnu.' };
  if (!APPROVER_KEYS.includes(approver)) return { ok: false, message: 'Type de validateur inconnu.' };
  if (form.steps.length >= MAX_STEPS) return { ok: false, message: `${MAX_STEPS} étapes au maximum.` };
  if (approver === 'user' && !db.prepare('SELECT id FROM users WHERE id = ? AND active = 1').get(approverId)) {
    return { ok: false, message: 'Personne désignée inconnue.' };
  }

  const amount = Number(threshold) || 0;
  if (amount < 0) return { ok: false, message: 'Un seuil ne se saisit pas négatif.' };

  const position = db.prepare('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM request_steps WHERE form_id = ?').get(formId).p;
  db.prepare(`
    INSERT INTO request_steps (form_id, position, approver, approver_id, label, threshold)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(formId, position, approver, approver === 'user' ? approverId : null, String(label || '').slice(0, 80), amount);

  return { ok: true };
}

function deleteStep(formId, stepId) {
  db.prepare('DELETE FROM request_steps WHERE id = ? AND form_id = ?').run(stepId, formId);
}

// ---------- Résolution des validateurs ----------

/**
 * Qui peut se prononcer sur une étape, compte tenu du demandeur. Le demandeur
 * en est toujours retiré : personne ne valide sa propre demande, et une étape
 * dont il serait le seul validateur bloquerait la demande pour toujours.
 */
function approversFor(step, requester) {
  const resolve = () => {
    if (step.approver === 'user') {
      return step.approver_id ? db.prepare('SELECT * FROM users WHERE id = ? AND active = 1').all(step.approver_id) : [];
    }
    if (step.approver === 'manager') return org.managersFor(requester);
    if (step.approver === 'hr') return db.prepare("SELECT * FROM users WHERE active = 1 AND (is_hr = 1 OR role = 'admin')").all();
    if (step.approver === 'finance') return db.prepare("SELECT * FROM users WHERE active = 1 AND (is_finance = 1 OR role = 'admin')").all();
    return db.prepare("SELECT * FROM users WHERE active = 1 AND role = 'admin'").all();
  };
  return resolve().filter((approver) => approver.id !== requester.id);
}

/**
 * Les étapes qui s'appliquent à ce montant. Une étape sans validateur possible
 * — un demandeur sans manager, par exemple — est sautée plutôt que de bloquer
 * la demande sur quelqu'un qui n'existe pas ; la trace le dira.
 */
function applicableSteps(form, requester, amount) {
  return form.steps
    .filter((step) => amount >= (step.threshold || 0))
    .filter((step) => approversFor(step, requester).length > 0);
}

// ---------- Demandes ----------

const label = (row) => `${row.first_name} ${row.last_name}`.trim();

function decorate(row) {
  if (!row) return null;
  const form = formById(row.form_id);
  const decisions = db.prepare(`
    SELECT d.*, u.first_name, u.last_name FROM workflow_decisions d
    LEFT JOIN users u ON u.id = d.approver_id WHERE d.request_id = ? ORDER BY d.position, d.id
  `).all(row.id);

  const requester = db.prepare('SELECT * FROM users WHERE id = ?').get(row.requester_id);
  const steps = requester && form ? applicableSteps(form, requester, row.amount) : [];
  const stepIndex = steps.findIndex((s) => s.id === row.current_step);

  return {
    ...row,
    form,
    values: (() => { try { return JSON.parse(row.payload); } catch { return {}; } })(),
    requester,
    requesterName: requester ? label(requester) : '—',
    decisions,
    steps,
    stepIndex,
    step: stepIndex >= 0 ? steps[stepIndex] : null,
    pendingApprovers: stepIndex >= 0 && requester ? approversFor(steps[stepIndex], requester) : [],
  };
}

function byId(id) {
  return decorate(db.prepare('SELECT * FROM workflow_requests WHERE id = ?').get(Number(id) || 0));
}

function list({ requesterId = null, status = null, limit = 200 } = {}) {
  const clauses = [];
  const params = [];
  if (requesterId) { clauses.push('r.requester_id = ?'); params.push(requesterId); }
  if (status) { clauses.push('r.status = ?'); params.push(status); }
  const where = clauses.length ? `WHERE ${clauses.join(' AND ')}` : '';
  params.push(limit);

  // L'identifiant tranche les ex æquo : deux demandes déposées dans la même
  // seconde sortiraient sinon dans un ordre arbitraire.
  return db.prepare(`SELECT r.* FROM workflow_requests r ${where} ORDER BY r.created_at DESC, r.id DESC LIMIT ?`)
    .all(...params).map(decorate);
}

/** Les demandes qui attendent une décision de cette personne, maintenant. */
function awaiting(userId) {
  return list({ status: 'En cours' }).filter((request) => request.pendingApprovers.some((a) => a.id === Number(userId)));
}

const awaitingCount = (userId) => awaiting(userId).length;

/** Contrôle la saisie contre la description du formulaire. */
function readValues(form, input) {
  const values = {};
  for (const field of form.fieldList) {
    const raw = String(input[`champ_${field.name}`] ?? '').trim();
    if (!raw) {
      if (field.required) return { ok: false, message: `Champ obligatoire : ${field.label}` };
      values[field.name] = '';
      continue;
    }
    if (field.type === 'nombre' || field.type === 'montant') {
      const parsed = parseAmount(raw);
      if (!Number.isFinite(parsed)) return { ok: false, message: `${field.label} : nombre attendu.` };
      values[field.name] = parsed;
      continue;
    }
    if (field.type === 'date' && !isValidDateString(raw)) return { ok: false, message: `${field.label} : date invalide.` };
    if (field.type === 'choix' && !field.options.includes(raw)) return { ok: false, message: `${field.label} : choix inconnu.` };
    values[field.name] = raw.slice(0, 2000);
  }
  return { ok: true, values };
}

/**
 * Dépose une demande et l'engage dans son circuit. Une demande sans aucune
 * étape applicable est approuvée d'emblée — et le dit, plutôt que de rester
 * en attente d'un validateur qui n'existe pas.
 */
function submit(formId, requesterId, input) {
  const form = formById(formId);
  if (!form || !form.active) return { ok: false, message: "Ce type de demande n'est pas ouvert." };

  const requester = db.prepare('SELECT * FROM users WHERE id = ? AND active = 1').get(requesterId);
  if (!requester) return { ok: false, message: 'Demandeur inconnu.' };

  const read = readValues(form, input);
  if (!read.ok) return read;

  const amount = form.amount_field ? Number(read.values[form.amount_field]) || 0 : 0;
  const steps = applicableSteps(form, requester, amount);
  const summary = form.fieldList
    .filter((f) => read.values[f.name] !== '' && read.values[f.name] !== undefined)
    .slice(0, 3)
    .map((f) => `${f.label} : ${read.values[f.name]}`)
    .join(' · ');

  const id = db.prepare(`
    INSERT INTO workflow_requests (form_id, requester_id, payload, amount, summary, status, current_step, closed_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  `).run(form.id, requester.id, JSON.stringify(read.values), amount, summary.slice(0, 300),
    steps.length ? 'En cours' : 'Approuvée', steps.length ? steps[0].id : null,
    steps.length ? null : new Date().toISOString()).lastInsertRowid;

  if (steps.length) notifyStep(byId(id));
  return { ok: true, id, steps: steps.length };
}

function notifyStep(request) {
  if (!request || !request.step) return;
  for (const approver of request.pendingApprovers) {
    notifications.push({
      userId: approver.id,
      kind: 'demande',
      title: `Demande à valider — ${request.form.label}`,
      body: `${request.requesterName} · ${request.summary}`.slice(0, 300),
      link: `/demandes/${request.id}`,
      dedupeKey: `demande:${request.id}:${request.step.id}:${approver.id}`,
    });
  }
}

function canDecide(request, userId) {
  if (!request || request.status !== 'En cours') return { ok: false, message: "Cette demande n'est plus en cours." };
  if (request.requester_id === Number(userId)) return { ok: false, message: 'On ne valide pas sa propre demande.' };
  if (!request.pendingApprovers.some((a) => a.id === Number(userId))) {
    return { ok: false, message: "Cette étape ne vous revient pas." };
  }
  return { ok: true };
}

/** Approuver fait avancer d'une étape ; refuser referme la demande. */
function decide(requestId, userId, { decision, note = '' }) {
  const request = byId(requestId);
  const allowed = canDecide(request, userId);
  if (!allowed.ok) return allowed;
  if (!['Approuvée', 'Refusée'].includes(decision)) return { ok: false, message: 'Décision inconnue.' };
  if (decision === 'Refusée' && !String(note).trim()) return { ok: false, message: 'Un refus se motive.' };

  const apply = db.transaction(() => {
    db.prepare(`
      INSERT INTO workflow_decisions (request_id, step_id, position, approver_id, decision, note)
      VALUES (?, ?, ?, ?, ?, ?)
    `).run(request.id, request.step.id, request.step.position, Number(userId), decision, String(note).slice(0, 1000));

    if (decision === 'Refusée') {
      db.prepare("UPDATE workflow_requests SET status = 'Refusée', current_step = NULL, closed_at = ? WHERE id = ?")
        .run(new Date().toISOString(), request.id);
      return 'Refusée';
    }

    const next = request.steps[request.stepIndex + 1];
    if (next) {
      db.prepare('UPDATE workflow_requests SET current_step = ? WHERE id = ?').run(next.id, request.id);
      return 'En cours';
    }

    db.prepare("UPDATE workflow_requests SET status = 'Approuvée', current_step = NULL, closed_at = ? WHERE id = ?")
      .run(new Date().toISOString(), request.id);
    return 'Approuvée';
  });

  const outcome = apply();
  const updated = byId(request.id);

  if (outcome === 'En cours') notifyStep(updated);
  else {
    notifications.push({
      userId: request.requester_id,
      kind: 'demande',
      title: `Demande ${outcome.toLowerCase()} — ${request.form.label}`,
      body: String(note).slice(0, 300),
      link: `/demandes/${request.id}`,
      dedupeKey: `demande:${request.id}:issue`,
    });
  }
  return { ok: true, status: outcome };
}

/** Le demandeur retire sa demande tant que personne ne s'est prononcé. */
function cancel(requestId, userId) {
  const request = byId(requestId);
  if (!request) return { ok: false, message: 'Demande introuvable.' };
  if (request.requester_id !== Number(userId)) return { ok: false, message: 'Seul le demandeur retire sa demande.' };
  if (request.status !== 'En cours') return { ok: false, message: "Cette demande n'est plus en cours." };
  if (request.decisions.length) return { ok: false, message: 'Une demande déjà examinée ne se retire plus.' };

  db.prepare("UPDATE workflow_requests SET status = 'Annulée', current_step = NULL, closed_at = ? WHERE id = ?")
    .run(new Date().toISOString(), request.id);
  return { ok: true };
}

function summary() {
  return {
    forms: db.prepare('SELECT COUNT(*) AS n FROM request_forms WHERE active = 1').get().n,
    running: db.prepare("SELECT COUNT(*) AS n FROM workflow_requests WHERE status = 'En cours'").get().n,
    approved: db.prepare("SELECT COUNT(*) AS n FROM workflow_requests WHERE status = 'Approuvée'").get().n,
    refused: db.prepare("SELECT COUNT(*) AS n FROM workflow_requests WHERE status = 'Refusée'").get().n,
  };
}

module.exports = {
  FIELD_TYPES, APPROVERS, APPROVER_KEYS, MAX_FIELDS, MAX_STEPS,
  forms, formById, stepsOf, createForm, setFormActive, deleteForm, addStep, deleteStep,
  approversFor, applicableSteps, byId, list, awaiting, awaitingCount, canDecide,
  submit, decide, cancel, summary,
};
