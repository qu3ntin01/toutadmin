const db = require('../db');
const societes = require('../societes');
const steering = require('../steering');
const projects = require('../projects');
const currency = require('../currency');
const { compter } = require('./aide');

/**
 * Indicateurs de direction.
 *
 * Les mêmes chiffres que le tableau de bord, calculés par les mêmes fonctions.
 * Les recopier ici en SQL aurait donné deux vérités : celle de l'écran et celle
 * de l'API, qui finissent toujours par diverger le jour où l'une des deux est
 * corrigée.
 */

module.exports = {
  cle: 'pilotage',
  titre: 'Indicateurs de direction',
  resume: 'Les chiffres consolidés du tableau de bord : activité, retards, projets.',
  fonctions: [
    {
      methode: 'GET',
      chemin: '/indicateurs',
      portee: 'pilotage',
      resume: 'Chiffre d’affaires, achats, marge et impayés, pour une année.',
      parametres: [{ nom: 'annee', dans: 'requete', type: 'entier', description: 'Par défaut, l’année en cours.' }],
      rend: ['annee', 'devise', 'effectif', 'chiffre_affaires', 'achats', 'marge', 'factures_impayees'],
      faire: (req, res) => {
        const annee = Number(req.query.annee) || new Date().getUTCFullYear();
        const revenus = steering.revenue(annee);
        const impayes = steering.unpaid();

        res.json({
          annee,
          devise: currency.base(),
          effectif: compter("SELECT COUNT(*) AS n FROM users WHERE active = 1 AND role = 'employee'"),
          chiffre_affaires: revenus.sales,
          achats: revenus.purchases,
          marge: revenus.margin,
          factures_impayees: { nombre: impayes.count, total: impayes.total },
        });
      },
    },
    {
      methode: 'GET',
      chemin: '/indicateurs/projets',
      portee: 'pilotage',
      resume: 'L’état du portefeuille de projets.',
      rend: ['ouverts', 'en_retard', 'taches_ouvertes', 'heures_30_jours'],
      faire: (req, res) => {
        // summary() parle anglais comme le reste du module ; l'API parle
        // français comme le reste de l'API. La traduction se fait ici, une fois.
        const chiffres = projects.summary();
        res.json({
          ouverts: chiffres.open,
          en_retard: chiffres.late,
          taches_ouvertes: chiffres.openTasks,
          heures_30_jours: chiffres.hours,
        });
      },
    },
    {
      methode: 'GET',
      chemin: '/indicateurs/rh',
      portee: 'pilotage',
      resume: 'Effectif, absences du jour, demandes en attente.',
      details: "Aucun nom ici : ce sont des compteurs, et un compteur ne désigne personne.",
      rend: ['effectif', 'absents_aujourdhui', 'demandes_en_attente', 'contrats_a_echeance'],
      faire: (req, res) => res.json({
        effectif: compter("SELECT COUNT(*) AS n FROM users WHERE active = 1 AND role = 'employee'"),
        absents_aujourdhui: compter(`
          SELECT COUNT(*) AS n FROM hr_requests
          WHERE company_id = ? AND status = 'Approuvée'
            AND date('now') BETWEEN start_date AND end_date
        `, societes.courant()),
        demandes_en_attente: compter(
          "SELECT COUNT(*) AS n FROM hr_requests WHERE company_id = ? AND status = 'En attente'",
          societes.courant(),
        ),
        contrats_a_echeance: compter(`
          SELECT COUNT(*) AS n FROM users
          WHERE active = 1 AND contract_end_date IS NOT NULL AND contract_end_date != ''
            AND contract_end_date BETWEEN date('now') AND date('now', '+90 days')
        `),
      }),
    },
  ],
};
