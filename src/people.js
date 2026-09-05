const db = require('./db');
const { addMonths } = require('./utils');

/**
 * Arrivées, départs et compétences.
 *
 * Une embauche et un départ sont des suites de gestes à faire par plusieurs
 * services, à des dates relatives à un jour pivot. Les tenir de mémoire, c'est
 * oublier de couper un accès. On part donc d'un modèle réutilisable, dont on
 * tire une liste datée par personne.
 */

const CHECKLIST_KINDS = ['Arrivée', 'Départ'];
const OWNER_ROLES = ['RH', 'Informatique', 'Manager', 'Gestion', 'Moyens généraux'];
const SKILL_LEVELS = [1, 2, 3, 4];

// ---------- Modèles ----------

function templates() {
  return db.prepare('SELECT * FROM checklist_templates ORDER BY kind, name COLLATE NOCASE').all()
    .map((tpl) => ({ ...tpl, items: templateItems(tpl.id) }));
}

function templateById(id) {
  return db.prepare('SELECT * FROM checklist_templates WHERE id = ?').get(Number(id) || 0) || null;
}

function templateItems(templateId) {
  return db.prepare('SELECT * FROM checklist_template_items WHERE template_id = ? ORDER BY sort_order, id').all(templateId);
}

function createTemplate(name, kind) {
  return db.prepare('INSERT INTO checklist_templates (name, kind) VALUES (?, ?)').run(name, kind).lastInsertRowid;
}

function deleteTemplate(id) {
  db.prepare('DELETE FROM checklist_templates WHERE id = ?').run(id);
}

function addTemplateItem(templateId, { label, ownerRole, offsetDays }) {
  const next = db.prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM checklist_template_items WHERE template_id = ?').get(templateId).n;
  return db.prepare(`
    INSERT INTO checklist_template_items (template_id, label, owner_role, offset_days, sort_order)
    VALUES (?, ?, ?, ?, ?)
  `).run(templateId, label, ownerRole, offsetDays, next).lastInsertRowid;
}

function deleteTemplateItem(id) {
  const row = db.prepare('SELECT template_id FROM checklist_template_items WHERE id = ?').get(id);
  db.prepare('DELETE FROM checklist_template_items WHERE id = ?').run(id);
  return row ? row.template_id : null;
}

// ---------- Listes appliquées ----------

function shiftDate(reference, days) {
  const date = new Date(`${reference}T00:00:00Z`);
  date.setUTCDate(date.getUTCDate() + Number(days || 0));
  return date.toISOString().slice(0, 10);
}

/**
 * Applique un modèle à une personne. Les échéances sont calculées à partir du
 * jour pivot : « J-2 : préparer le poste » vaut deux jours avant l'arrivée.
 */
function startChecklist({ templateId, userId, referenceDate }) {
  const template = templateById(templateId);
  if (!template) return null;

  const create = db.transaction(() => {
    const id = db.prepare(`
      INSERT INTO checklists (template_id, user_id, kind, reference_date) VALUES (?, ?, ?, ?)
    `).run(template.id, userId, template.kind, referenceDate).lastInsertRowid;

    const insert = db.prepare(`
      INSERT INTO checklist_items (checklist_id, label, owner_role, due_date, sort_order) VALUES (?, ?, ?, ?, ?)
    `);
    for (const item of templateItems(template.id)) {
      insert.run(id, item.label, item.owner_role, shiftDate(referenceDate, item.offset_days), item.sort_order);
    }
    return id;
  });
  return create();
}

function checklists({ userId = null, openOnly = false } = {}) {
  const clauses = [];
  const params = [];
  if (userId) { clauses.push('c.user_id = ?'); params.push(userId); }
  if (openOnly) clauses.push('c.completed_at IS NULL');
  const where = clauses.length ? `WHERE ${clauses.join(' AND ')}` : '';

  return db.prepare(`
    SELECT c.*, u.first_name, u.last_name, u.email, t.name AS template_name,
           (SELECT COUNT(*) FROM checklist_items WHERE checklist_id = c.id) AS total,
           (SELECT COUNT(*) FROM checklist_items WHERE checklist_id = c.id AND done_at IS NOT NULL) AS done
    FROM checklists c
    JOIN users u ON u.id = c.user_id
    LEFT JOIN checklist_templates t ON t.id = c.template_id
    ${where}
    ORDER BY c.completed_at IS NOT NULL, c.reference_date DESC
  `).all(...params);
}

function checklistById(id) {
  return db.prepare('SELECT * FROM checklists WHERE id = ?').get(Number(id) || 0) || null;
}

function checklistItems(checklistId) {
  return db.prepare(`
    SELECT i.*, u.first_name, u.last_name FROM checklist_items i
    LEFT JOIN users u ON u.id = i.done_by
    WHERE i.checklist_id = ? ORDER BY i.sort_order, i.id
  `).all(checklistId);
}

/** Cocher le dernier point clôt la liste ; en décocher un la rouvre. */
function toggleItem(itemId, userId) {
  const item = db.prepare('SELECT * FROM checklist_items WHERE id = ?').get(itemId);
  if (!item) return null;

  const apply = db.transaction(() => {
    if (item.done_at) {
      db.prepare('UPDATE checklist_items SET done_at = NULL, done_by = NULL WHERE id = ?').run(itemId);
    } else {
      db.prepare("UPDATE checklist_items SET done_at = datetime('now'), done_by = ? WHERE id = ?").run(userId, itemId);
    }
    const remaining = db.prepare('SELECT COUNT(*) AS n FROM checklist_items WHERE checklist_id = ? AND done_at IS NULL').get(item.checklist_id).n;
    db.prepare('UPDATE checklists SET completed_at = ? WHERE id = ?')
      .run(remaining === 0 ? new Date().toISOString() : null, item.checklist_id);
  });
  apply();
  return item.checklist_id;
}

function deleteChecklist(id) {
  db.prepare('DELETE FROM checklists WHERE id = ?').run(id);
}

/** Les points en retard, tous parcours confondus : c'est ce qui se pilote. */
function lateItems() {
  return db.prepare(`
    SELECT i.*, c.kind, c.user_id, u.first_name, u.last_name
    FROM checklist_items i
    JOIN checklists c ON c.id = i.checklist_id
    JOIN users u ON u.id = c.user_id
    WHERE i.done_at IS NULL AND i.due_date IS NOT NULL AND i.due_date < date('now')
    ORDER BY i.due_date
  `).all();
}

// ---------- Compétences et habilitations ----------

function skills() {
  return db.prepare(`
    SELECT s.*, (SELECT COUNT(*) FROM user_skills WHERE skill_id = s.id) AS holders
    FROM skills s ORDER BY s.category COLLATE NOCASE, s.name COLLATE NOCASE
  `).all();
}

function createSkill({ name, category, validityMonths, mandatory }) {
  return db.prepare('INSERT INTO skills (name, category, validity_months, mandatory) VALUES (?, ?, ?, ?)')
    .run(name, category, validityMonths || null, mandatory ? 1 : 0).lastInsertRowid;
}

function deleteSkill(id) {
  db.prepare('DELETE FROM skills WHERE id = ?').run(id);
}

function skillById(id) {
  return db.prepare('SELECT * FROM skills WHERE id = ?').get(Number(id) || 0) || null;
}

/** L'échéance découle de la durée de validité de l'habilitation, pas d'une saisie. */
function expiryFor(skill, obtainedOn) {
  if (!skill.validity_months || !obtainedOn) return null;
  return addMonths(obtainedOn, skill.validity_months);
}

function grantSkill({ userId, skillId, level, obtainedOn, reference }) {
  const skill = skillById(skillId);
  if (!skill) return null;

  db.prepare(`
    INSERT INTO user_skills (user_id, skill_id, level, obtained_on, expires_on, reference)
    VALUES (?, ?, ?, ?, ?, ?)
    ON CONFLICT(user_id, skill_id) DO UPDATE SET
      level = excluded.level, obtained_on = excluded.obtained_on,
      expires_on = excluded.expires_on, reference = excluded.reference
  `).run(userId, skillId, level, obtainedOn || null, expiryFor(skill, obtainedOn), reference || '');
  return true;
}

function revokeSkill(userId, skillId) {
  db.prepare('DELETE FROM user_skills WHERE user_id = ? AND skill_id = ?').run(userId, skillId);
}

function skillsOf(userId) {
  return db.prepare(`
    SELECT us.*, s.name, s.category, s.validity_months, s.mandatory
    FROM user_skills us JOIN skills s ON s.id = us.skill_id
    WHERE us.user_id = ? ORDER BY s.category COLLATE NOCASE, s.name COLLATE NOCASE
  `).all(userId);
}

/** La matrice : qui détient quoi, et ce qui périme. */
function matrix() {
  const people = db.prepare("SELECT id, first_name, last_name FROM users WHERE active = 1 AND role = 'employee' ORDER BY last_name COLLATE NOCASE").all();
  const held = db.prepare('SELECT * FROM user_skills').all();
  return people.map((person) => ({
    ...person,
    held: Object.fromEntries(held.filter((h) => h.user_id === person.id).map((h) => [h.skill_id, h])),
  }));
}

function expiringSkills(withinDays = 90) {
  return db.prepare(`
    SELECT us.*, s.name, s.category, u.first_name, u.last_name
    FROM user_skills us
    JOIN skills s ON s.id = us.skill_id
    JOIN users u ON u.id = us.user_id
    WHERE us.expires_on IS NOT NULL AND us.expires_on <= date('now', ?) AND u.active = 1
    ORDER BY us.expires_on
  `).all(`+${Number(withinDays) || 90} days`);
}

/** Les habilitations obligatoires que quelqu'un n'a pas, ou plus. */
function missingMandatory() {
  return db.prepare(`
    SELECT u.id AS user_id, u.first_name, u.last_name, s.id AS skill_id, s.name,
           us.expires_on
    FROM users u
    CROSS JOIN skills s
    LEFT JOIN user_skills us ON us.user_id = u.id AND us.skill_id = s.id
    WHERE s.mandatory = 1 AND u.active = 1 AND u.role = 'employee'
      AND (us.id IS NULL OR (us.expires_on IS NOT NULL AND us.expires_on < date('now')))
    ORDER BY u.last_name COLLATE NOCASE, s.name
  `).all();
}

module.exports = {
  CHECKLIST_KINDS, OWNER_ROLES, SKILL_LEVELS,
  templates, templateById, templateItems, createTemplate, deleteTemplate, addTemplateItem, deleteTemplateItem,
  shiftDate, startChecklist, checklists, checklistById, checklistItems, toggleItem, deleteChecklist, lateItems,
  skills, createSkill, deleteSkill, skillById, expiryFor, grantSkill, revokeSkill, skillsOf, matrix,
  expiringSkills, missingMandatory,
};
