const db = require('../db');
const org = require('../org');
const { page, wrap, erreur, absent, texte, entier, compter } = require('./aide');

/**
 * Annuaire et organisation.
 *
 * L'API respecte l'annuaire : qui en est retiré n'en sort pas par une autre
 * porte. `directory_hidden` vaut ici exactement ce qu'il vaut à l'écran.
 */

const personne = (r) => ({
  id: r.id,
  prenom: r.first_name,
  nom: r.last_name,
  email: r.email,
  grade: r.grade,
  contrat: r.contract_type,
  service: r.department,
  equipe: r.team,
  fin_contrat: r.contract_end_date,
});

const SELECT_PERSONNES = `
  SELECT u.id, u.first_name, u.last_name, u.email, u.grade, u.contract_type, u.contract_end_date,
         d.name AS department, t.name AS team
  FROM users u
  LEFT JOIN departments d ON d.id = u.department_id
  LEFT JOIN teams t ON t.id = u.team_id
  WHERE u.active = 1 AND u.directory_hidden = 0`;

module.exports = {
  cle: 'annuaire',
  titre: 'Annuaire et organisation',
  resume: "Qui travaille ici, dans quel service, dans quelle équipe, et qui encadre.",
  fonctions: [
    {
      methode: 'GET',
      chemin: '/collaborateurs',
      portee: 'annuaire',
      resume: "La liste des collaborateurs visibles dans l'annuaire.",
      details: "Les comptes désactivés et ceux qui se sont retirés de l'annuaire n'y figurent pas : l'API ne contourne pas un choix fait à l'écran.",
      parametres: [
        { nom: 'limite', dans: 'requete', type: 'entier', defaut: 50, description: 'De 1 à 200.' },
        { nom: 'depuis', dans: 'requete', type: 'entier', defaut: 0, description: 'Rang du premier résultat.' },
        { nom: 'service', dans: 'requete', type: 'entier', description: "N'garder que ce service." },
        { nom: 'recherche', dans: 'requete', type: 'texte', description: 'Nom, prénom ou adresse.' },
      ],
      rend: ['id', 'prenom', 'nom', 'email', 'grade', 'contrat', 'service', 'equipe', 'fin_contrat'],
      faire: (req, res) => {
        const { limit, offset } = page(req);
        const service = entier(req.query.service);
        const q = texte(req.query, 'recherche', 60);

        const filtres = [];
        const args = [];
        if (service !== null) { filtres.push('u.department_id = ?'); args.push(service); }
        if (q) {
          filtres.push('(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)');
          args.push(`%${q}%`, `%${q}%`, `%${q}%`);
        }
        const where = filtres.length ? ` AND ${filtres.join(' AND ')}` : '';

        const total = compter(`SELECT COUNT(*) AS n FROM users u WHERE u.active = 1 AND u.directory_hidden = 0${where}`, ...args);
        const rows = db.prepare(`${SELECT_PERSONNES}${where} ORDER BY u.last_name COLLATE NOCASE LIMIT ? OFFSET ?`).all(...args, limit, offset);

        res.json(wrap(rows.map(personne), { limit, offset }, total));
      },
    },
    {
      methode: 'GET',
      chemin: '/collaborateurs/:id',
      portee: 'annuaire',
      resume: 'Une fiche, par son identifiant.',
      parametres: [{ nom: 'id', dans: 'chemin', type: 'entier', requis: true }],
      rend: ['id', 'prenom', 'nom', 'email', 'grade', 'contrat', 'service', 'equipe', 'fin_contrat', 'encadre'],
      faire: (req, res) => {
        const row = db.prepare(`${SELECT_PERSONNES} AND u.id = ?`).get(entier(req.params.id));
        if (!row) return absent(res, 'Collaborateur');

        const encadre = org.scopesManagedBy(row.id);
        return res.json({
          ...personne(row),
          encadre: { services: encadre.departments, equipes: encadre.teams },
        });
      },
    },
    {
      methode: 'GET',
      chemin: '/services',
      portee: 'annuaire',
      resume: "Les services, leur effectif et leurs encadrants.",
      rend: ['id', 'nom', 'effectif', 'equipes', 'encadrants'],
      faire: (req, res) => res.json(wrap(org.departments().map((d) => ({
        id: d.id, nom: d.name, effectif: d.member_count, equipes: d.team_count,
        encadrants: org.managersOf('department', d.id).map((m) => `${m.first_name} ${m.last_name}`),
      })), { limit: 200, offset: 0 })),
    },
    {
      methode: 'GET',
      chemin: '/equipes',
      portee: 'annuaire',
      resume: 'Les équipes, leur service de rattachement et leurs encadrants.',
      rend: ['id', 'nom', 'service', 'effectif', 'encadrants'],
      faire: (req, res) => res.json(wrap(org.teams().map((t) => ({
        id: t.id, nom: t.name, service: t.department_name, effectif: t.member_count,
        encadrants: org.managersOf('team', t.id).map((m) => `${m.first_name} ${m.last_name}`),
      })), { limit: 200, offset: 0 })),
    },
    {
      methode: 'GET',
      chemin: '/organigramme',
      portee: 'annuaire',
      resume: "L'organisation entière, services et équipes imbriqués.",
      rend: ['departments', 'orphanTeams', 'unassigned', 'headcount', 'managerCount'],
      faire: (req, res) => res.json(org.chart()),
    },

    // ---------- Écriture ----------

    {
      methode: 'POST',
      chemin: '/services',
      portee: 'annuaire:ecriture',
      resume: 'Crée un service.',
      journal: 'api.service_cree',
      parametres: [
        { nom: 'nom', dans: 'corps', type: 'texte', requis: true, description: 'Le nom du service.' },
        { nom: 'description', dans: 'corps', type: 'texte', description: 'Ce que fait ce service.' },
      ],
      rend: ['id', 'nom'],
      faire: (req, res) => {
        const nom = texte(req.body, 'nom', 120);
        if (!nom) return erreur(res, 422, 'Un nom est requis.');

        const id = Number(org.createDepartment({ name: nom, description: texte(req.body, 'description', 400) }));
        return res.status(201).json({ id, nom });
      },
    },
    {
      methode: 'POST',
      chemin: '/equipes',
      portee: 'annuaire:ecriture',
      resume: 'Crée une équipe dans un service.',
      journal: 'api.equipe_creee',
      parametres: [
        { nom: 'nom', dans: 'corps', type: 'texte', requis: true },
        { nom: 'service', dans: 'corps', type: 'entier', requis: true, description: "Le service d'accueil." },
        { nom: 'description', dans: 'corps', type: 'texte', description: "Ce que fait cette équipe." },
      ],
      rend: ['id', 'nom', 'service'],
      faire: (req, res) => {
        const nom = texte(req.body, 'nom', 120);
        const service = entier(req.body && req.body.service);
        if (!nom) return erreur(res, 422, 'Un nom est requis.');
        if (service === null) return erreur(res, 422, 'Un service est requis.');
        if (!org.departmentById(service)) return erreur(res, 422, 'Ce service n’existe pas.');

        const id = Number(org.createTeam({ name: nom, departmentId: service, description: texte(req.body, 'description', 400) }));
        return res.status(201).json({ id, nom, service });
      },
    },
  ],
};
