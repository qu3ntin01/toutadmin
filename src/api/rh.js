const db = require('../db');
const societes = require('../societes');
const hr = require('../hr');
const { page, wrap, erreur, absent, texte, entier, date, compter } = require('./aide');

/**
 * Ressources humaines.
 *
 * Deux choses ne sortent pas d'ici, et c'est délibéré :
 *
 *   — le **motif** d'une absence. Il regarde la personne et son responsable,
 *     pas l'outil de planification qui lit l'API ;
 *   — les **bulletins de paie**. Un jeton qui circule dans un fichier de
 *     configuration n'a pas à donner les salaires de l'entreprise. Ils restent
 *     derrière l'écran, qui demande une session, un rôle et une trace.
 */

const APPROUVEE = 'Approuvée';

module.exports = {
  cle: 'rh',
  titre: 'Ressources humaines',
  resume: 'Absences, demandes, effectifs. Sans les motifs et sans la paie.',
  fonctions: [
    {
      methode: 'GET',
      chemin: '/absences',
      portee: 'rh',
      resume: 'Les absences approuvées, à partir d’une date.',
      details: "Le motif n'est pas rendu : il n'a pas à sortir de l'entreprise.",
      parametres: [
        { nom: 'depuis_le', dans: 'requete', type: 'date', description: 'Ne garder que ce qui finit après cette date.' },
        { nom: 'limite', dans: 'requete', type: 'entier', defaut: 50 },
        { nom: 'depuis', dans: 'requete', type: 'entier', defaut: 0 },
      ],
      rend: ['id', 'type', 'du', 'au', 'jours', 'personne', 'personne_id'],
      faire: (req, res) => {
        const { limit, offset } = page(req);
        const from = date(req.query.depuis_le) || '0000-01-01';

        /* Le jeton n'a pas de session : hors contexte, « courant » rend la
           société qui mène. Voir societes.perimetreParDefaut(). */
        const total = compter(
          'SELECT COUNT(*) AS n FROM hr_requests WHERE company_id = ? AND status = ? AND end_date >= ?',
          societes.courant(), APPROUVEE, from,
        );
        const rows = db.prepare(`
          SELECT r.id, r.type, r.start_date, r.end_date, r.days, r.employee_id,
                 u.first_name, u.last_name
          FROM hr_requests r JOIN users u ON u.id = r.employee_id
          WHERE r.company_id = ? AND r.status = ? AND r.end_date >= ?
          ORDER BY r.start_date DESC LIMIT ? OFFSET ?
        `).all(societes.courant(), APPROUVEE, from, limit, offset);

        res.json(wrap(rows.map((r) => ({
          id: r.id, type: r.type, du: r.start_date, au: r.end_date, jours: r.days,
          personne: `${r.first_name} ${r.last_name}`, personne_id: r.employee_id,
        })), { limit, offset }, total));
      },
    },
    {
      methode: 'GET',
      chemin: '/demandes',
      portee: 'rh',
      resume: 'Les demandes d’absence, quel que soit leur sort.',
      details: "Celles qui attendent, celles qui ont été refusées, celles qui ont été retirées. Le motif reste absent, comme partout ici.",
      parametres: [
        { nom: 'statut', dans: 'requete', type: 'texte', description: 'En attente, Approuvée, Refusée…' },
        { nom: 'limite', dans: 'requete', type: 'entier', defaut: 50 },
        { nom: 'depuis', dans: 'requete', type: 'entier', defaut: 0 },
      ],
      rend: ['id', 'type', 'du', 'au', 'jours', 'statut', 'personne', 'personne_id', 'deposee_le'],
      faire: (req, res) => {
        const { limit, offset } = page(req);
        const statut = texte(req.query, 'statut', 30);

        const rows = hr.getAllRequests(statut ? { status: statut } : {});
        const tranche = rows.slice(offset, offset + limit);

        res.json(wrap(tranche.map((r) => ({
          id: r.id, type: r.type, du: r.start_date, au: r.end_date, jours: r.days,
          statut: r.status, personne: `${r.first_name} ${r.last_name}`, personne_id: r.employee_id,
          deposee_le: r.created_at,
        })), { limit, offset }, rows.length));
      },
    },
    {
      methode: 'GET',
      chemin: '/effectifs',
      portee: 'rh',
      resume: 'L’effectif, par service et par type de contrat.',
      rend: ['total', 'par_service', 'par_contrat'],
      faire: (req, res) => {
        const base = "FROM users u WHERE u.active = 1 AND u.role = 'employee'";
        res.json({
          total: compter(`SELECT COUNT(*) AS n ${base}`),
          par_service: db.prepare(`
            SELECT COALESCE(d.name, 'Sans service') AS nom, COUNT(*) AS effectif
            FROM users u LEFT JOIN departments d ON d.id = u.department_id
            WHERE u.active = 1 AND u.role = 'employee'
            GROUP BY COALESCE(d.name, 'Sans service') ORDER BY effectif DESC
          `).all(),
          par_contrat: db.prepare(`
            SELECT COALESCE(NULLIF(u.contract_type, ''), 'Non renseigné') AS nom, COUNT(*) AS effectif
            ${base} GROUP BY nom ORDER BY effectif DESC
          `).all(),
        });
      },
    },

    // ---------- Écriture ----------

    {
      methode: 'POST',
      chemin: '/absences',
      portee: 'rh:ecriture',
      resume: 'Dépose une demande d’absence pour un salarié.',
      details: "Elle entre « En attente », comme si elle avait été saisie à l'écran : l'API dépose, elle n'approuve pas. Approuver reste un geste de responsable, devant une session et un nom.",
      journal: 'api.absence_deposee',
      parametres: [
        { nom: 'personne', dans: 'corps', type: 'entier', requis: true, description: 'L’identifiant du salarié.' },
        { nom: 'type', dans: 'corps', type: 'texte', requis: true, description: 'Congés payés, RTT, Maladie…' },
        { nom: 'du', dans: 'corps', type: 'date', requis: true },
        { nom: 'au', dans: 'corps', type: 'date', requis: true },
      ],
      rend: ['id', 'statut', 'jours'],
      faire: (req, res) => {
        const personne = entier(req.body && req.body.personne);
        const type = texte(req.body, 'type', 60);
        const du = date(req.body && req.body.du);
        const au = date(req.body && req.body.au);

        if (personne === null) return erreur(res, 422, 'Un salarié est requis.');
        if (!type) return erreur(res, 422, 'Un type est requis.');
        if (!du || !au) return erreur(res, 422, 'Deux dates au format AAAA-MM-JJ sont requises.');
        if (au < du) return erreur(res, 422, 'La fin précède le début.');

        const salarie = db.prepare("SELECT id FROM users WHERE id = ? AND active = 1 AND role = 'employee'").get(personne);
        if (!salarie) return absent(res, 'Salarié');

        const jours = hr.countBusinessDays(du, au);
        const id = Number(hr.createRequest({ employeeId: personne, type, startDate: du, endDate: au, days: jours, reason: '' }));

        return res.status(201).json({ id, statut: 'En attente', jours });
      },
    },
  ],
};
