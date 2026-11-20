const support = require('./support');
const hr = require('./hr');
const org = require('./org');

/**
 * Les actions de masse : la même action, répétée, avec les mêmes refus.
 *
 * Deux dessins étaient possibles, et le choix est tout le module.
 *
 * Le premier écrit une requête par action — « UPDATE tickets SET status = ?
 * WHERE id IN (…) ». Une instruction, rapide, et fausse : elle contourne les
 * règles de l'action à l'unité. Fermer un ticket recalcule une date de
 * clôture ; approuver une absence décrémente un solde de congés et prévient les
 * outils de planification. Un « UPDATE … IN » ne fait rien de cela, et le
 * défaut ne se voit pas tout de suite — il se voit sur le solde de congés d'un
 * salarié, trois mois plus tard.
 *
 * Le second — celui-ci — appelle la fonction à l'unité, une fois par ligne.
 * C'est plus lent, et c'est la seule façon d'obtenir le même résultat que si
 * l'utilisateur avait ouvert chaque fiche : mêmes effets, mêmes refus, mêmes
 * entrées au journal. Une action de masse n'est pas une fonction de plus ;
 * c'est un raccourci sur des fonctions qui existent.
 *
 * Trois conséquences, qui sont aussi les trois invariants que les tests tiennent :
 *
 *   - le droit se vérifie ligne par ligne, et non une fois pour la requête. Un
 *     agent de support n'a la charge que de certaines catégories : cocher
 *     cinquante tickets ne lui en donne pas une de plus ;
 *   - une ligne hors périmètre ne se lit pas, donc ne se traite pas. Elle est
 *     refusée comme une ligne effacée, et sans dire laquelle des deux — un
 *     identifiant d'une autre société ne doit pas se distinguer d'un identifiant
 *     inexistant ;
 *   - une ligne qui échoue n'emporte pas les autres. Pas de transaction
 *     englobante, et c'est délibéré : sur cinquante lignes qu'on n'a pas relues
 *     une à une, tout annuler pour une seule qui n'était plus en attente serait
 *     le pire des deux mondes. Le compte rendu dit ce qui est passé et ce qui
 *     ne l'est pas.
 */

/*
 * Le plafond d'une sélection.
 *
 * Il n'est pas là pour la performance : il est là parce qu'une sélection de dix
 * mille lignes n'est plus une action de masse, c'est une migration — et une
 * migration se prépare, se sauvegarde et s'explique. Deux cents lignes est ce
 * qu'une personne peut encore relire dans une liste avant de valider.
 */
const PLAFOND = 200;

/*
 * Ce qu'on peut traiter en masse, objet par objet.
 *
 * - « lire » rend la ligne dans le périmètre courant, ou rien. C'est elle qui
 *   cloisonne : aucune requête de ce module ne touche la base directement.
 * - « autorise » reçoit la personne et la ligne, et répond pour cette ligne-là.
 * - « actions » : ce qu'on peut faire. « faire » appelle la fonction à l'unité
 *   et rend faux si elle a refusé ; « parametre » nomme la valeur attendue, et
 *   « valeurs » la restreint quand elle est énumérée.
 *
 * La liste est courte exprès. Une action de masse n'a de sens que là où la liste
 * est longue et l'action répétitive ; l'ajouter partout serait ajouter partout
 * une case à cocher que personne ne cocherait.
 */
const OBJETS = {
  ticket: {
    etiquette: 'masse.objet.ticket',
    retour: '/support',
    lire: (id) => support.byId(id),
    autorise: (user, ticket) => {
      if (support.categoriesFor(user).includes(ticket.category)) return true;
      // Un manager encadre : il traite les demandes de son périmètre, hors RH.
      return org.isManager(user.id) && ticket.category !== support.RESTRICTED_CATEGORY;
    },
    actions: {
      statut: {
        parametre: 'statut',
        valeurs: () => support.STATUSES,
        faire: (ticket, valeur) => support.setStatus(ticket.id, valeur),
      },
      priorite: {
        parametre: 'priorite',
        valeurs: () => support.PRIORITIES,
        faire: (ticket, valeur) => support.setPriority(ticket.id, valeur),
      },
      affecter: {
        parametre: 'utilisateur',
        faire: (ticket, valeur) => {
          support.assign(ticket.id, Number(valeur) || null);
          return true;
        },
      },
    },
  },

  demande: {
    etiquette: 'masse.objet.demande',
    retour: '/rh#demandes',
    lire: (id) => hr.getRequestById(id),
    // Traiter une demande interne appartient aux RH et à l'administration. Le
    // rôle suffit ici : il n'y a pas de catégorie à comparer, contrairement aux
    // tickets.
    autorise: (user) => user.role === 'admin' || Boolean(user.is_hr),
    actions: {
      approuver: {
        parametre: null,
        faire: (demande, _valeur, user) => hr.approveRequest(demande.id, user.id, '').ok,
      },
      refuser: {
        parametre: null,
        faire: (demande, _valeur, user) => hr.rejectRequest(demande.id, user.id, '').ok,
      },
    },
  },
};

/** Les actions proposées pour un objet, ou rien si l'objet n'en a pas. */
function actionsDe(objet) {
  const def = OBJETS[objet];
  return def ? Object.keys(def.actions) : [];
}

/**
 * Le paramètre qu'une action attend, ou rien.
 *
 * L'écran nomme son champ d'après lui — « valeur_statut », « valeur_utilisateur »
 * — parce que la barre porte un contrôle par action et qu'un seul nom « valeur »
 * les ferait tous partir ensemble. Sans cela, la barre ne marcherait qu'avec du
 * script, et une action de masse qui demande du script est une action de masse
 * qu'on ne peut pas éprouver sans navigateur.
 */
function parametreDe(objet, action) {
  const def = OBJETS[objet];
  const acte = def ? def.actions[action] : null;
  return acte ? acte.parametre : null;
}

/** Les valeurs acceptées par une action énumérée, ou rien. */
function valeursDe(objet, action) {
  const def = OBJETS[objet];
  const acte = def ? def.actions[action] : null;
  return acte && acte.valeurs ? acte.valeurs() : null;
}

/** Le chemin où revenir après avoir appliqué. */
function retourDe(objet) {
  const def = OBJETS[objet];
  return def ? def.retour : '/';
}

/**
 * Appliquer une action à une sélection.
 *
 * Rend soit un refus global — l'objet, l'action, la sélection ou la valeur ne
 * tiennent pas —, soit le compte rendu ligne à ligne. Jamais une exception : ce
 * qui échoue sur une ligne est une ligne refusée, pas une page d'erreur.
 */
function appliquer({ objet, action, ids, valeur = null, user }) {
  const def = OBJETS[objet];
  if (!def) return { ok: false, raison: 'masse.err.objet' };
  const acte = def.actions[action];
  if (!acte) return { ok: false, raison: 'masse.err.action' };
  if (!user) return { ok: false, raison: 'masse.err.droit' };

  const liste = [...new Set(
    (Array.isArray(ids) ? ids : [ids]).map((v) => Number(v)).filter((v) => Number.isInteger(v) && v > 0)
  )];
  if (!liste.length) return { ok: false, raison: 'masse.err.vide' };
  if (liste.length > PLAFOND) return { ok: false, raison: 'masse.err.plafond' };

  if (acte.parametre && (valeur === null || String(valeur).trim() === '')) {
    return { ok: false, raison: 'masse.err.valeur' };
  }
  if (acte.valeurs && !acte.valeurs().includes(String(valeur))) {
    return { ok: false, raison: 'masse.err.valeur' };
  }

  const faites = [];
  const refusees = [];
  for (const id of liste) {
    const ligne = def.lire(id);
    if (!ligne) {
      refusees.push({ id, raison: 'masse.err.introuvable' });
      continue;
    }
    if (!def.autorise(user, ligne)) {
      refusees.push({ id, raison: 'masse.err.droit' });
      continue;
    }
    try {
      if (acte.faire(ligne, valeur, user) === false) refusees.push({ id, raison: 'masse.err.refus' });
      else faites.push(id);
    } catch {
      refusees.push({ id, raison: 'masse.err.refus' });
    }
  }
  return { ok: true, faites, refusees };
}

module.exports = { PLAFOND, OBJETS, actionsDe, parametreDe, valeursDe, retourDe, appliquer };
