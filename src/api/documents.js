const db = require('../db');
const announcements = require('../announcements');
const societes = require('../societes');
const resources = require('../resources');
const { page, wrap, erreur, absent, texte, entier, date } = require('./aide');

/**
 * Documents et bases communes : annonces, salles, matériel.
 *
 * Ce qui est déposé dans le coffre-fort, le parapheur ou la base documentaire
 * n'est pas ici, et n'y sera pas : ces pièces portent des contrats, des fiches
 * de paie et des pièces d'identité. Elles se consultent derrière une session,
 * un rôle et une trace nominative, pas derrière un jeton posé dans un fichier.
 */

const nomDe = (prenom, nom) => ([prenom, nom].filter(Boolean).join(' ') || null);

module.exports = {
  cle: 'documents',
  titre: 'Documents et bases communes',
  resume: 'Annonces, salles et réservations, matériel et affectations.',
  fonctions: [
    {
      methode: 'GET',
      chemin: '/annonces',
      portee: 'documents',
      resume: 'Les annonces publiées, de la plus récente à la plus ancienne.',
      parametres: [
        { nom: 'limite', dans: 'requete', type: 'entier', defaut: 50 },
        { nom: 'depuis', dans: 'requete', type: 'entier', defaut: 0 },
      ],
      rend: ['id', 'titre', 'corps', 'portee', 'portee_nom', 'auteur', 'publiee_le'],
      faire: (req, res) => {
        const { limit, offset } = page(req);
        // Le jeton n'a pas de session : hors contexte, « courant » rend la
        // société qui mène. Voir societes.perimetreParDefaut().
        const toutes = announcements.all(500);

        res.json(wrap(toutes.slice(offset, offset + limit).map((a) => ({
          id: a.id, titre: a.title, corps: a.body,
          portee: a.scope, portee_nom: a.scope_name,
          auteur: nomDe(a.first_name, a.last_name), publiee_le: a.created_at,
        })), { limit, offset }, toutes.length));
      },
    },
    {
      methode: 'GET',
      chemin: '/salles',
      portee: 'documents',
      resume: 'Les salles réservables.',
      rend: ['id', 'nom', 'lieu', 'capacite', 'active'],
      faire: (req, res) => res.json(wrap(resources.rooms().map((r) => ({
        id: r.id, nom: r.name, lieu: r.location, capacite: r.capacity, active: Boolean(r.active),
      })), { limit: 200, offset: 0 })),
    },
    {
      methode: 'GET',
      chemin: '/reservations',
      portee: 'documents',
      resume: 'Les réservations de salles, sur une période.',
      parametres: [
        { nom: 'du', dans: 'requete', type: 'date' },
        { nom: 'au', dans: 'requete', type: 'date' },
        { nom: 'salle', dans: 'requete', type: 'entier' },
      ],
      rend: ['id', 'salle', 'salle_id', 'lieu', 'titre', 'le', 'de', 'a', 'personne'],
      faire: (req, res) => {
        const liste = resources.bookings({
          from: date(req.query.du) || undefined,
          to: date(req.query.au) || undefined,
          roomId: entier(req.query.salle) || undefined,
        });

        res.json(wrap(liste.map((b) => ({
          id: b.id, salle: b.room_name, salle_id: b.room_id, lieu: b.location,
          titre: b.title, le: b.booking_date, de: b.start_time, a: b.end_time,
          personne: nomDe(b.first_name, b.last_name),
        })), { limit: 200, offset: 0 }, liste.length));
      },
    },
    {
      methode: 'GET',
      chemin: '/materiel',
      portee: 'documents',
      resume: 'Le matériel, son état et qui le détient.',
      rend: ['id', 'nom', 'categorie', 'reference', 'statut', 'detenteur', 'detenteur_id'],
      faire: (req, res) => res.json(wrap(resources.assets().map((a) => ({
        id: a.id, nom: a.name, categorie: a.category, reference: a.serial || a.reference || '',
        statut: a.status, detenteur: nomDe(a.holder_first_name, a.holder_last_name), detenteur_id: a.holder_id,
      })), { limit: 200, offset: 0 })),
    },

    // ---------- Écriture ----------

    {
      methode: 'POST',
      chemin: '/annonces',
      portee: 'documents:ecriture',
      resume: 'Publie une annonce.',
      details: "L'auteur enregistré est le jeton, non une personne : une annonce signée d'un nom qui n'a rien écrit serait un faux.",
      journal: 'api.annonce_publiee',
      parametres: [
        { nom: 'titre', dans: 'corps', type: 'texte', requis: true },
        { nom: 'corps', dans: 'corps', type: 'texte', requis: true },
        { nom: 'portee', dans: 'corps', type: 'texte', description: 'company, department ou team. Par défaut : company.' },
        { nom: 'portee_id', dans: 'corps', type: 'entier', description: 'Le service ou l’équipe visé.' },
      ],
      rend: ['id', 'titre', 'portee'],
      faire: (req, res) => {
        const corps = req.body || {};
        const titre = texte(corps, 'titre', 200);
        const texteCorps = texte(corps, 'corps', 8000);
        const portee = texte(corps, 'portee', 20) || 'company';

        if (!titre) return erreur(res, 422, 'Un titre est requis.');
        if (!texteCorps) return erreur(res, 422, 'Un corps est requis.');
        if (!announcements.SCOPES.includes(portee)) {
          return erreur(res, 422, 'Portée inconnue.', { attendus: announcements.SCOPES });
        }

        const porteeId = entier(corps.portee_id);
        if (portee !== 'company') {
          if (porteeId === null) return erreur(res, 422, 'Cette portée demande un identifiant.');
          const table = portee === 'team' ? 'teams' : 'departments';
          if (!db.prepare(`SELECT 1 AS ok FROM ${table} WHERE id = ?`).get(porteeId)) {
            return erreur(res, 422, 'Cette portée ne désigne rien.');
          }
        }

        const id = Number(announcements.create({
          authorId: null, scope: portee, scopeId: porteeId, title: titre, body: texteCorps,
        }));
        return res.status(201).json({ id, titre, portee });
      },
    },
    {
      methode: 'POST',
      chemin: '/reservations',
      portee: 'documents:ecriture',
      resume: 'Réserve une salle.',
      details: "Le chevauchement est refusé par la même règle qu'à l'écran, et le refus dit qui occupe déjà le créneau.",
      journal: 'api.salle_reservee',
      parametres: [
        { nom: 'salle', dans: 'corps', type: 'entier', requis: true },
        { nom: 'personne', dans: 'corps', type: 'entier', requis: true },
        { nom: 'titre', dans: 'corps', type: 'texte', requis: true },
        { nom: 'le', dans: 'corps', type: 'date', requis: true },
        { nom: 'de', dans: 'corps', type: 'texte', requis: true, description: 'HH:MM' },
        { nom: 'a', dans: 'corps', type: 'texte', requis: true, description: 'HH:MM' },
      ],
      rend: ['id', 'salle', 'le', 'de', 'a'],
      faire: (req, res) => {
        const corps = req.body || {};
        const salle = entier(corps.salle);
        const personne = entier(corps.personne);
        const titre = texte(corps, 'titre', 160);
        const le = date(corps.le);

        if (salle === null || personne === null) return erreur(res, 422, 'Une salle et une personne sont requises.');
        if (!titre) return erreur(res, 422, 'Un titre est requis.');
        if (!le) return erreur(res, 422, 'Une date au format AAAA-MM-JJ est requise.');

        const verdict = resources.bookRoom({
          roomId: salle, userId: personne, title: titre, bookingDate: le,
          startTime: texte(corps, 'de', 5), endTime: texte(corps, 'a', 5),
        });

        if (!verdict.ok) {
          const motifs = {
            'not-found': [404, 'Salle introuvable.'],
            inactive: [409, 'Cette salle n’est plus réservable.'],
            'bad-time': [422, 'Les horaires s’écrivent HH:MM.'],
            'bad-range': [422, 'La fin précède le début.'],
            clash: [409, 'Ce créneau est déjà pris.'],
          };
          const [code, message] = motifs[verdict.reason] || [409, 'Réservation refusée.'];
          return erreur(res, code, message, verdict.clash ? { occupe_par: nomDe(verdict.clash.first_name, verdict.clash.last_name), de: verdict.clash.start_time, a: verdict.clash.end_time } : {});
        }

        return res.status(201).json({
          id: verdict.id ?? null, salle, le, de: texte(corps, 'de', 5), a: texte(corps, 'a', 5),
        });
      },
    },
    {
      methode: 'POST',
      chemin: '/materiel/:id/affectation',
      portee: 'documents:ecriture',
      resume: 'Affecte un matériel à un salarié.',
      journal: 'api.materiel_affecte',
      parametres: [
        { nom: 'id', dans: 'chemin', type: 'entier', requis: true },
        { nom: 'personne', dans: 'corps', type: 'entier', requis: true },
        { nom: 'note', dans: 'corps', type: 'texte' },
      ],
      rend: ['materiel', 'personne', 'statut'],
      faire: (req, res) => {
        const id = entier(req.params.id);
        const personne = entier(req.body && req.body.personne);
        if (personne === null) return erreur(res, 422, 'Une personne est requise.');

        const verdict = resources.assignAsset({ assetId: id, employeeId: personne, note: texte(req.body, 'note', 400) });
        if (!verdict.ok) {
          const motifs = {
            'not-found': [404, 'Matériel introuvable.'],
            retired: [409, 'Ce matériel est réformé.'],
            'already-assigned': [409, 'Ce matériel est déjà affecté.'],
            'no-employee': [422, 'Ce salarié n’existe pas.'],
          };
          const [code, message] = motifs[verdict.reason] || [409, 'Affectation refusée.'];
          return erreur(res, code, message);
        }

        return res.status(201).json({ materiel: id, personne, statut: 'Affecté' });
      },
    },
  ],
};
