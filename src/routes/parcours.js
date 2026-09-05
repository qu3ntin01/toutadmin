const express = require('express');

const db = require('../db');
const audit = require('../audit');
const people = require('../people');
const { requireHR } = require('../middleware/auth');
const { setFlash, isValidDateString } = require('../utils');

const router = express.Router();

// Arrivées, départs et compétences : le suivi du cycle de vie relève des RH.
router.use(requireHR);

const back = (anchor) => `/parcours#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

function employees() {
  return db.prepare("SELECT id, first_name, last_name, email FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE").all();
}

router.get('/', (req, res) => {
  res.render('parcours', {
    templateList: people.templates(),
    checklistList: people.checklists(),
    late: people.lateItems(),
    skillList: people.skills(),
    matrix: people.matrix(),
    expiring: people.expiringSkills(),
    missing: people.missingMandatory(),
    kinds: people.CHECKLIST_KINDS,
    ownerRoles: people.OWNER_ROLES,
    levels: people.SKILL_LEVELS,
    employees: employees(),
    today: new Date().toISOString().slice(0, 10),
  });
});

router.get('/listes/:id', (req, res) => {
  const checklist = people.checklistById(req.params.id);
  if (!checklist) return res.status(404).render('error', { message: 'Parcours introuvable.' });

  res.render('parcours-liste', {
    checklist,
    person: db.prepare('SELECT * FROM users WHERE id = ?').get(checklist.user_id),
    items: people.checklistItems(checklist.id),
  });
});

// ---------- Modèles ----------

router.post('/modeles', (req, res) => {
  const name = (req.body.name || '').trim();
  if (!name || name.length > 120) return fail(req, res, 'modeles', 'Nom de modèle invalide.');
  if (!people.CHECKLIST_KINDS.includes(req.body.kind)) return fail(req, res, 'modeles', 'Type invalide.');

  people.createTemplate(name, req.body.kind);
  setFlash(req, 'success', 'Modèle créé.');
  res.redirect(back('modeles'));
});

router.post('/modeles/:id/points', (req, res) => {
  const template = people.templateById(req.params.id);
  if (!template) return fail(req, res, 'modeles', 'Modèle introuvable.');

  const label = (req.body.label || '').trim();
  if (!label || label.length > 200) return fail(req, res, 'modeles', 'Intitulé invalide.');
  if (!people.OWNER_ROLES.includes(req.body.owner_role)) return fail(req, res, 'modeles', 'Responsable invalide.');

  const offset = Number(req.body.offset_days);
  if (!Number.isInteger(offset) || offset < -365 || offset > 365) {
    return fail(req, res, 'modeles', "L'écart au jour pivot doit tenir dans l'année.");
  }

  people.addTemplateItem(template.id, { label, ownerRole: req.body.owner_role, offsetDays: offset });
  setFlash(req, 'success', 'Point ajouté au modèle.');
  res.redirect(back('modeles'));
});

router.post('/modeles/points/:id/supprimer', (req, res) => {
  people.deleteTemplateItem(Number(req.params.id));
  setFlash(req, 'success', 'Point retiré.');
  res.redirect(back('modeles'));
});

router.post('/modeles/:id/supprimer', (req, res) => {
  people.deleteTemplate(Number(req.params.id));
  setFlash(req, 'success', 'Modèle supprimé. Les parcours déjà lancés restent en place.');
  res.redirect(back('modeles'));
});

// ---------- Parcours ----------

router.post('/listes', (req, res) => {
  const templateId = Number(req.body.template_id);
  const userId = Number(req.body.user_id);
  const reference = (req.body.reference_date || '').trim();

  if (!people.templateById(templateId)) return fail(req, res, 'parcours', 'Modèle introuvable.');
  if (!db.prepare('SELECT 1 FROM users WHERE id = ?').get(userId)) return fail(req, res, 'parcours', 'Membre introuvable.');
  if (!isValidDateString(reference)) return fail(req, res, 'parcours', 'Date pivot invalide.');

  const id = people.startChecklist({ templateId, userId, referenceDate: reference });
  audit.log(req, 'parcours.lance', 'checklists', id, { membre: userId });
  setFlash(req, 'success', 'Parcours lancé : les échéances sont calculées à partir de la date pivot.');
  res.redirect(`/parcours/listes/${id}`);
});

router.post('/listes/points/:id/basculer', (req, res) => {
  const checklistId = people.toggleItem(Number(req.params.id), req.currentUser.id);
  if (!checklistId) return fail(req, res, 'parcours', 'Point introuvable.');
  res.redirect(`/parcours/listes/${checklistId}`);
});

router.post('/listes/:id/supprimer', (req, res) => {
  people.deleteChecklist(Number(req.params.id));
  setFlash(req, 'success', 'Parcours supprimé.');
  res.redirect(back('parcours'));
});

// ---------- Compétences ----------

router.post('/competences', (req, res) => {
  const name = (req.body.name || '').trim();
  if (!name || name.length > 120) return fail(req, res, 'competences', 'Intitulé invalide.');
  if (db.prepare('SELECT 1 FROM skills WHERE name = ?').get(name)) {
    return fail(req, res, 'competences', 'Cette compétence existe déjà.');
  }

  const validity = (req.body.validity_months || '').trim();
  const months = validity ? Number(validity) : null;
  if (validity && (!Number.isInteger(months) || months < 1 || months > 600)) {
    return fail(req, res, 'competences', 'Durée de validité invalide.');
  }

  people.createSkill({
    name,
    category: (req.body.category || 'Générale').trim().slice(0, 60),
    validityMonths: months,
    mandatory: req.body.mandatory === '1',
  });
  setFlash(req, 'success', 'Compétence ajoutée.');
  res.redirect(back('competences'));
});

router.post('/competences/:id/supprimer', (req, res) => {
  people.deleteSkill(Number(req.params.id));
  setFlash(req, 'success', 'Compétence supprimée, avec les attributions correspondantes.');
  res.redirect(back('competences'));
});

router.post('/competences/attribuer', (req, res) => {
  const userId = Number(req.body.user_id);
  const skillId = Number(req.body.skill_id);
  const obtained = (req.body.obtained_on || '').trim();
  const level = Number(req.body.level);

  if (!db.prepare('SELECT 1 FROM users WHERE id = ?').get(userId)) return fail(req, res, 'competences', 'Membre introuvable.');
  if (!people.skillById(skillId)) return fail(req, res, 'competences', 'Compétence introuvable.');
  if (!people.SKILL_LEVELS.includes(level)) return fail(req, res, 'competences', 'Niveau invalide.');
  if (obtained && !isValidDateString(obtained)) return fail(req, res, 'competences', "Date d'obtention invalide.");

  people.grantSkill({ userId, skillId, level, obtainedOn: obtained || null, reference: (req.body.reference || '').trim().slice(0, 120) });
  setFlash(req, 'success', "Compétence attribuée. L'échéance découle de sa durée de validité.");
  res.redirect(back('competences'));
});

router.post('/competences/:skillId/retirer/:userId', (req, res) => {
  people.revokeSkill(Number(req.params.userId), Number(req.params.skillId));
  setFlash(req, 'success', 'Attribution retirée.');
  res.redirect(back('competences'));
});

module.exports = router;
