const db = require('../db');
const societes = require('../societes');

/** Le périmètre courant. Le jeton n'a pas de session : hors contexte, c'est la société qui mène. Voir societes.perimetreParDefaut(). */
const ici = () => societes.courant();
const projects = require('../projects');
const { page, wrap, erreur, absent, texte, entier, date } = require('./aide');

/** Projets, tâches, jalons, temps passé. */

const nomDe = (prenom, nom) => ([prenom, nom].filter(Boolean).join(' ') || null);

/**
 * Les compteurs ne sont pas dans `projects.list()` : ils se comptent ici, en
 * une requête pour toute la liste plutôt qu'une par projet — une page de
 * cinquante projets ferait sinon cent cinquante allers-retours.
 */
function compteurs(ids) {
  if (!ids.length) return new Map();
  const trous = ids.map(() => '?').join(',');
  const rows = db.prepare(`
    SELECT p.id,
           (SELECT COUNT(*) FROM project_tasks t WHERE t.project_id = p.id) AS taches,
           (SELECT COUNT(*) FROM project_tasks t WHERE t.project_id = p.id AND t.status = 'Terminée') AS faites,
           (SELECT COALESCE(SUM(hours), 0) FROM project_time pt WHERE pt.project_id = p.id) AS heures
    FROM projects p WHERE p.company_id = ? AND p.id IN (${trous})
  `).all(ici(), ...ids);
  return new Map(rows.map((r) => [r.id, r]));
}

const projet = (p, compte) => ({
  id: p.id, code: p.code, nom: p.name, statut: p.status,
  budget: p.budget_amount, taux_horaire: p.hourly_rate,
  debut: p.start_date, echeance: p.due_date,
  responsable: nomDe(p.lead_first_name, p.lead_last_name),
  tiers: p.partner_name || null,
  taches: compte ? compte.taches : 0,
  taches_terminees: compte ? compte.faites : 0,
  heures: compte ? compte.heures : 0,
});

module.exports = {
  cle: 'projets',
  titre: 'Projets',
  resume: 'Projets, tâches, jalons, temps passé et rentabilité.',
  fonctions: [
    {
      methode: 'GET',
      chemin: '/projets',
      portee: 'projets',
      resume: 'Les projets en cours.',
      parametres: [
        { nom: 'statut', dans: 'requete', type: 'texte', description: 'Cadrage, En cours, Livré…' },
        { nom: 'archives', dans: 'requete', type: 'booleen', description: 'À 1, inclut les projets archivés.' },
        { nom: 'limite', dans: 'requete', type: 'entier', defaut: 50 },
        { nom: 'depuis', dans: 'requete', type: 'entier', defaut: 0 },
      ],
      rend: ['id', 'code', 'nom', 'statut', 'budget', 'debut', 'echeance', 'taches', 'taches_terminees', 'heures'],
      faire: (req, res) => {
        const { limit, offset } = page(req);
        const statut = texte(req.query, 'statut', 30);

        let tous = projects.list({ includeArchived: String(req.query.archives || '') === '1' });
        if (statut) tous = tous.filter((p) => p.status === statut);

        const tranche = tous.slice(offset, offset + limit);
        const compte = compteurs(tranche.map((p) => p.id));
        res.json(wrap(tranche.map((p) => projet(p, compte.get(p.id))), { limit, offset }, tous.length));
      },
    },
    {
      methode: 'GET',
      chemin: '/projets/:id',
      portee: 'projets',
      resume: 'Un projet, avec ses membres, ses jalons et sa rentabilité.',
      parametres: [{ nom: 'id', dans: 'chemin', type: 'entier', requis: true }],
      rend: ['id', 'nom', 'statut', 'membres', 'jalons', 'rentabilite'],
      faire: (req, res) => {
        const p = projects.byId(entier(req.params.id));
        if (!p) return absent(res, 'Projet');

        return res.json({
          ...projet(p, compteurs([p.id]).get(p.id)),
          membres: projects.members(p.id).map((m) => ({
            id: m.user_id, nom: nomDe(m.first_name, m.last_name), role: m.role,
          })),
          jalons: projects.milestones(p.id),
          rentabilite: projects.profitability(p),
        });
      },
    },
    {
      methode: 'GET',
      chemin: '/projets/:id/taches',
      portee: 'projets',
      resume: 'Les tâches d’un projet.',
      parametres: [
        { nom: 'id', dans: 'chemin', type: 'entier', requis: true },
        { nom: 'statut', dans: 'requete', type: 'texte', description: 'À faire, En cours, Terminée…' },
      ],
      rend: ['id', 'titre', 'statut', 'priorite', 'assignee', 'echeance', 'estimation'],
      faire: (req, res) => {
        const p = projects.byId(entier(req.params.id));
        if (!p) return absent(res, 'Projet');

        const statut = texte(req.query, 'statut', 30);
        let taches = projects.tasks(p.id);
        if (statut) taches = taches.filter((t) => t.status === statut);

        return res.json(wrap(taches.map((t) => ({
          id: t.id, titre: t.title, statut: t.status, priorite: t.priority,
          assignee: nomDe(t.first_name, t.last_name), echeance: t.due_date, estimation: t.estimate_hours,
        })), { limit: 200, offset: 0 }, taches.length));
      },
    },
    {
      methode: 'GET',
      chemin: '/projets/:id/temps',
      portee: 'projets',
      resume: 'Le temps passé sur un projet, par membre.',
      parametres: [{ nom: 'id', dans: 'chemin', type: 'entier', requis: true }],
      rend: ['total', 'par_membre', 'saisies'],
      faire: (req, res) => {
        const p = projects.byId(entier(req.params.id));
        if (!p) return absent(res, 'Projet');

        const par_membre = projects.timeByMember(p.id).map((m) => ({
          id: m.id, nom: nomDe(m.first_name, m.last_name), heures: m.hours,
        }));
        return res.json({
          total: par_membre.reduce((somme, m) => somme + m.heures, 0),
          par_membre,
          saisies: projects.timeEntries(p.id, { limit: 200 }).map((e) => ({
            le: e.spent_on, heures: e.hours, personne: nomDe(e.first_name, e.last_name),
            tache: e.task_title || null, facturable: Boolean(e.billable),
          })),
        });
      },
    },

    // ---------- Écriture ----------

    {
      methode: 'POST',
      chemin: '/projets',
      portee: 'projets:ecriture',
      resume: 'Crée un projet.',
      journal: 'api.projet_cree',
      parametres: [
        { nom: 'nom', dans: 'corps', type: 'texte', requis: true },
        { nom: 'code', dans: 'corps', type: 'texte' },
        { nom: 'statut', dans: 'corps', type: 'texte', description: 'Par défaut : Cadrage.' },
        { nom: 'debut', dans: 'corps', type: 'date' },
        { nom: 'echeance', dans: 'corps', type: 'date' },
        { nom: 'budget', dans: 'corps', type: 'nombre' },
        { nom: 'tiers', dans: 'corps', type: 'entier' },
      ],
      rend: ['id', 'nom', 'statut'],
      faire: (req, res) => {
        const corps = req.body || {};
        const nom = texte(corps, 'nom', 160);
        if (!nom) return erreur(res, 422, 'Un nom est requis.');

        const statut = texte(corps, 'statut', 30);
        if (statut && !projects.STATUSES.includes(statut)) {
          return erreur(res, 422, 'Statut inconnu.', { attendus: projects.STATUSES });
        }

        const budget = corps.budget === undefined ? null : Number(corps.budget);
        if (budget !== null && !Number.isFinite(budget)) return erreur(res, 422, 'Budget illisible.');

        const id = Number(projects.create({
          name: nom, code: texte(corps, 'code', 40), status: statut || undefined,
          startDate: date(corps.debut) || null, dueDate: date(corps.echeance) || null,
          budgetAmount: budget, partnerId: entier(corps.tiers),
          description: texte(corps, 'description', 2000),
        }));

        return res.status(201).json({ id, nom, statut: statut || 'Cadrage' });
      },
    },
    {
      methode: 'POST',
      chemin: '/projets/:id/taches',
      portee: 'projets:ecriture',
      resume: 'Ajoute une tâche à un projet.',
      journal: 'api.tache_creee',
      parametres: [
        { nom: 'id', dans: 'chemin', type: 'entier', requis: true },
        { nom: 'titre', dans: 'corps', type: 'texte', requis: true },
        { nom: 'assignee', dans: 'corps', type: 'entier', description: 'L’identifiant du responsable.' },
        { nom: 'priorite', dans: 'corps', type: 'texte' },
        { nom: 'echeance', dans: 'corps', type: 'date' },
        { nom: 'estimation', dans: 'corps', type: 'nombre', description: 'En heures.' },
      ],
      rend: ['id', 'titre', 'statut'],
      faire: (req, res) => {
        const p = projects.byId(entier(req.params.id));
        if (!p) return absent(res, 'Projet');

        const corps = req.body || {};
        const titre = texte(corps, 'titre', 200);
        if (!titre) return erreur(res, 422, 'Un titre est requis.');

        const priorite = texte(corps, 'priorite', 30);
        if (priorite && !projects.PRIORITIES.includes(priorite)) {
          return erreur(res, 422, 'Priorité inconnue.', { attendus: projects.PRIORITIES });
        }

        const id = Number(projects.createTask({
          projectId: p.id, title: titre,
          description: texte(corps, 'description', 2000),
          assigneeId: entier(corps.assignee), priority: priorite || undefined,
          dueDate: date(corps.echeance) || null,
          estimateHours: corps.estimation === undefined ? null : Number(corps.estimation),
        }));

        return res.status(201).json({ id, titre, statut: 'À faire' });
      },
    },
    {
      methode: 'POST',
      chemin: '/projets/:id/temps',
      portee: 'projets:ecriture',
      resume: 'Pointe du temps sur un projet.',
      details: "Le plafond par saisie est celui de l'écran : au-delà, c'est une erreur de frappe plus souvent qu'une journée de travail.",
      journal: 'api.temps_pointe',
      parametres: [
        { nom: 'id', dans: 'chemin', type: 'entier', requis: true },
        { nom: 'personne', dans: 'corps', type: 'entier', requis: true },
        { nom: 'le', dans: 'corps', type: 'date', requis: true },
        { nom: 'heures', dans: 'corps', type: 'nombre', requis: true },
        { nom: 'tache', dans: 'corps', type: 'entier' },
        { nom: 'note', dans: 'corps', type: 'texte' },
        { nom: 'facturable', dans: 'corps', type: 'booleen', description: 'Vrai par défaut.' },
      ],
      rend: ['projet', 'heures', 'total'],
      faire: (req, res) => {
        const p = projects.byId(entier(req.params.id));
        if (!p) return absent(res, 'Projet');

        const corps = req.body || {};
        const personne = entier(corps.personne);
        const le = date(corps.le);
        const heures = Number(corps.heures);

        if (personne === null) return erreur(res, 422, 'Une personne est requise.');
        if (!le) return erreur(res, 422, 'Une date au format AAAA-MM-JJ est requise.');
        if (!db.prepare('SELECT 1 AS ok FROM users WHERE id = ? AND active = 1').get(personne)) {
          return absent(res, 'Personne');
        }

        const verdict = projects.logTime({
          projectId: p.id, taskId: entier(corps.tache), userId: personne,
          spentOn: le, hours: heures, note: texte(corps, 'note', 400),
          billable: corps.facturable === undefined ? true : Boolean(corps.facturable),
        });
        if (!verdict.ok) return erreur(res, 422, verdict.message);

        const total = projects.timeByMember(p.id).reduce((somme, m) => somme + m.hours, 0);
        return res.status(201).json({ projet: p.id, heures, total });
      },
    },
  ],
};
