const support = require('./support');
const org = require('./org');

/**
 * La vue kanban : la même liste, rangée en colonnes.
 *
 * Ce n'est pas un écran de plus, et c'est tout le propos. Une vue kanban qui
 * aurait ses propres requêtes, ses propres droits et sa propre façon de changer
 * un statut serait un second produit à maintenir, qui divergerait du premier au
 * premier correctif. Celle-ci reprend la liste que l'écran affiche déjà, la
 * répartit en colonnes selon un champ, et déplace une carte en appelant la
 * fonction à l'unité — la même que le formulaire de la fiche.
 *
 * Trois conséquences :
 *
 *   - les colonnes sont les valeurs du statut, dans leur ordre déclaré. Pas de
 *     colonnes réglables : l'ordre d'un statut est une règle du métier, pas une
 *     préférence d'affichage, et une colonne « en attente » placée après « clos »
 *     mentirait sur le cycle ;
 *   - une carte ne se déplace que là où le statut l'autorise, et le droit est
 *     vérifié sur cette ligne-là, comme à l'unité ;
 *   - le déplacement est un POST avec un champ de choix et un bouton. Pas de
 *     glisser-déposer : il demanderait du script pour fonctionner du tout, et une
 *     vue qui ne marche pas sans script n'est pas une vue, c'est une option.
 *
 * Le tableau des tâches d'un projet est antérieur à ce module et garde sa carte,
 * plus riche (affectation, suppression, jalon). Il n'est pas réécrit ici : un
 * écran qui marche ne se refait pas pour l'uniformité.
 */

/*
 * Ce qui se range en colonnes, objet par objet.
 *
 * - « colonnes » : les valeurs du champ, dans l'ordre du cycle.
 * - « lister » rend les lignes du périmètre courant — c'est la fonction de liste
 *   de l'écran, pas une requête d'ici.
 * - « colonneDe » dit dans quelle colonne tombe une ligne.
 * - « carte » dit ce que la carte montre. Jamais plus que la liste ne montre.
 * - « lire », « autorise » et « deplacer » sont ceux de l'action à l'unité.
 */
const OBJETS = {
  ticket: {
    etiquette: 'kanban.objet.ticket',
    colonnes: () => support.STATUSES,
    lister: (filtres = {}) => support.list(filtres),
    colonneDe: (ticket) => ticket.status,
    carte: (ticket) => ({
      id: ticket.id,
      lien: `/support/tickets/${ticket.id}`,
      titre: ticket.subject,
      reference: ticket.reference,
      lignes: [
        ticket.requester_first_name ? `${ticket.requester_first_name} ${ticket.requester_last_name}` : null,
        ticket.assignee_first_name ? `${ticket.assignee_first_name} ${ticket.assignee_last_name}` : null,
        ticket.category,
      ].filter(Boolean),
      alerte: support.isOverdue(ticket) ? 'kanban.overdue' : null,
      etiquette: ['Haute', 'Critique'].includes(ticket.priority) ? ticket.priority : null,
    }),
    lire: (id) => support.byId(id),
    autorise: (user, ticket) => {
      if (support.categoriesFor(user).includes(ticket.category)) return true;
      return org.isManager(user.id) && ticket.category !== support.RESTRICTED_CATEGORY;
    },
    deplacer: (ticket, colonne) => support.setStatus(ticket.id, colonne),
  },
};

/** Les objets qui offrent une vue kanban. */
function objets() {
  return Object.keys(OBJETS);
}

/** Les colonnes déclarées pour un objet, ou rien. */
function colonnesDe(objet) {
  const def = OBJETS[objet];
  return def ? def.colonnes() : null;
}

/**
 * Le tableau : les colonnes dans leur ordre, chacune avec ses cartes.
 *
 * Une ligne dont le statut n'est pas une colonne déclarée n'est pas perdue : elle
 * est rendue à part, sous « hors colonnes ». Un statut ajouté à la base sans être
 * déclaré ferait sinon disparaître des lignes de l'écran sans un mot, et c'est le
 * genre de défaut qu'on ne remarque qu'en comptant.
 */
function tableau(objet, filtres = {}) {
  const def = OBJETS[objet];
  if (!def) return null;

  const lignes = def.lister(filtres);
  const colonnes = def.colonnes().map((valeur) => ({ valeur, cartes: [] }));
  const index = new Map(colonnes.map((colonne) => [colonne.valeur, colonne]));
  const horsColonnes = [];

  for (const ligne of lignes) {
    const colonne = index.get(def.colonneDe(ligne));
    if (colonne) colonne.cartes.push(def.carte(ligne));
    else horsColonnes.push(def.carte(ligne));
  }
  return { objet, colonnes, horsColonnes };
}

/**
 * Déplacer une ligne dans une autre colonne.
 *
 * Mêmes refus que l'action à l'unité, et dans le même ordre : la colonne doit
 * exister, la ligne doit se lire dans le périmètre, et le droit se vérifie sur
 * cette ligne.
 */
function deplacer({ objet, id, colonne, user }) {
  const def = OBJETS[objet];
  if (!def) return { ok: false, raison: 'kanban.err.objet' };
  if (!def.colonnes().includes(String(colonne))) return { ok: false, raison: 'kanban.err.colonne' };
  if (!user) return { ok: false, raison: 'kanban.err.droit' };

  const ligne = def.lire(id);
  // Hors périmètre, hors du tableau : le même refus qu'une ligne effacée.
  if (!ligne) return { ok: false, raison: 'kanban.err.introuvable' };
  if (!def.autorise(user, ligne)) return { ok: false, raison: 'kanban.err.droit' };

  if (def.deplacer(ligne, String(colonne)) === false) return { ok: false, raison: 'kanban.err.refus' };
  return { ok: true, id: ligne.id, colonne: String(colonne) };
}

module.exports = { OBJETS, objets, colonnesDe, tableau, deplacer };
