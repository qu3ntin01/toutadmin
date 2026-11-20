const express = require('express');

const db = require('../db');
const org = require('../org');
const audit = require('../audit');
const support = require('../support');
const kanban = require('../kanban');
const customFields = require('../custom-fields');
const corbeille = require('../corbeille');
const ai = require('../ai');
const assistance = require('../assistance');
const finance = require('../finance');
const { requireAuth } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();

router.use(requireAuth);

const back = (anchor) => `/support#${anchor}`;

function fail(req, res, target, message) {
  setFlash(req, 'error', message);
  return res.redirect(target);
}

/**
 * Qui traite les tickets, et lesquels.
 *
 * Trois sources, réunies : les rôles — l'administration et les RH voient tout,
 * la gestion tout sauf les demandes RH, qui parlent de paie, de contrat, parfois
 * de santé —, les groupes de support que l'administration compose, et
 * l'encadrement, qui traite son périmètre hors RH.
 *
 * Les groupes ajoutent, ils ne retirent pas. Créer un premier groupe ne doit
 * pas fermer la file aux RH ni à l'administration : l'entreprise le découvrirait
 * le jour même, en ne voyant plus personne répondre.
 */
function agentCategories(req) {
  const user = req.currentUser;
  if (!user) return [];
  const parRoleEtGroupe = support.categoriesFor(user);
  if (parRoleEtGroupe.length) return parRoleEtGroupe;
  // Un manager encadre : il traite les demandes de son périmètre, hors RH.
  return org.isManager(user.id) ? support.CATEGORIES.filter((c) => c !== support.RESTRICTED_CATEGORY) : [];
}

/** Composer les groupes est une décision d'instance : elle appartient à l'administration. */
function exigerAdmin(req, res, next) {
  if (req.currentUser && req.currentUser.role === 'admin') return next();
  res.status(403).render('error', { message: 'La composition des groupes de support relève de l’administration.' });
}

function isAgent(req) {
  return agentCategories(req).length > 0;
}

function requireAgentFor(req, res, next) {
  const ticket = support.byId(req.params.id);
  if (ticket && agentCategories(req).includes(ticket.category)) return next();
  res.status(403).render('error', { message: 'Le traitement de ce ticket est réservé aux équipes qui en ont la charge.' });
}

function activeUsers() {
  return db.prepare('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE').all();
}

// ---------- Tickets ----------

router.get('/', (req, res) => {
  const agent = isAgent(req);
  const filters = {
    status: (req.query.statut || '').trim(),
    category: (req.query.categorie || '').trim(),
    groupId: Number(req.query.groupe) || null,
    openOnly: req.query.tous !== '1',
  };

  const allowed = agentCategories(req);
  const tickets = agent
    ? support.list({ ...filters, categories: allowed })
    : support.list({ ...filters, requesterId: req.currentUser.id });

  /*
   * La vue : la même liste, le même filtrage, rangée autrement. Le kanban n'est
   * offert qu'à qui traite les demandes — déplacer une carte est une action, et
   * un demandeur n'en a aucune à faire sur sa propre demande.
   */
  const vue = agent && req.query.vue === 'kanban' ? 'kanban' : 'liste';

  res.render('support', {
    isAgent: agent,
    vue,
    tableau: vue === 'kanban' ? kanban.tableau('ticket', { ...filters, categories: allowed }) : null,
    kanbanColonnes: kanban.colonnesDe('ticket'),
    /* Les liens de bascule gardent la requête en cours : changer de vue ne doit
       pas perdre le filtre que la personne vient de poser. */
    lienVue: (laquelle) => {
      const parametres = new URLSearchParams(req.query);
      parametres.set('vue', laquelle);
      return `/support?${parametres.toString()}`;
    },
    tickets: tickets.map((tk) => ({ ...tk, overdue: support.isOverdue(tk) })),
    mine: support.list({ requesterId: req.currentUser.id }),
    assigned: agent ? support.list({ assigneeId: req.currentUser.id, openOnly: true, categories: allowed }) : [],
    filters,
    summary: support.summary(),
    categories: support.CATEGORIES,
    priorities: support.PRIORITIES,
    statuses: support.STATUSES,
    origins: support.ORIGINS,
    // Les délais en vigueur sur cette instance, et non ceux d'origine : l'écran
    // qui les affiche doit dire ce que le serveur appliquera.
    responseHours: Object.fromEntries(support.PRIORITIES.map((p) => [p, support.responseHours(p)])),
    people: agent ? activeUsers() : [],
    partners: agent ? finance.partners() : [],
    // Les groupes, pour filtrer la file et pour transférer une demande. Un
    // salarié qui n'est pas agent n'en a pas besoin, et ne les reçoit pas.
    groups: agent ? support.groups() : [],
    myGroups: support.groupsOf(req.currentUser.id),
    canManageGroups: req.currentUser.role === 'admin',
  });
});

router.post('/tickets', (req, res) => {
  const subject = (req.body.subject || '').trim();
  if (!subject || subject.length > 200) return fail(req, res, back('nouveau'), 'Objet du ticket invalide.');
  if (!support.CATEGORIES.includes(req.body.category)) return fail(req, res, back('nouveau'), 'Catégorie invalide.');
  if (!support.PRIORITIES.includes(req.body.priority)) return fail(req, res, back('nouveau'), 'Priorité invalide.');

  // L'origine « Client » et le rattachement à un partenaire ne sont ouverts
  // qu'aux équipes support : un salarié ouvre un ticket interne, pour lui.
  const agent = isAgent(req);
  const origin = agent && support.ORIGINS.includes(req.body.origin) ? req.body.origin : 'Interne';

  const id = support.create({
    subject,
    body: (req.body.body || '').trim().slice(0, 5000),
    category: req.body.category,
    priority: req.body.priority,
    origin,
    requesterId: req.currentUser.id,
    partnerId: agent ? Number(req.body.partner_id) || null : null,
  });

  audit.log(req, 'ticket.ouvert', 'tickets', id, { objet: subject, priorite: req.body.priority });
  setFlash(req, 'success', `Ticket ${support.reference(id)} ouvert.`);
  res.redirect(`/support/tickets/${id}`);
});

/** Un demandeur voit ses tickets ; un agent, ceux des catégories qu'il traite. */
function visibleTo(req, ticket) {
  if (ticket.requester_id === req.currentUser.id) return true;
  return agentCategories(req).includes(ticket.category);
}

router.get('/tickets/:id', (req, res) => {
  const ticket = support.byId(req.params.id);
  if (!ticket) return res.status(404).render('error', { message: 'Ticket introuvable.' });
  if (!visibleTo(req, ticket)) {
    return res.status(403).render('error', { message: "Ce ticket n'est pas le vôtre." });
  }

  const agent = isAgent(req);

  /*
   * Ce que l'assistance vient de proposer, s'il y a lieu.
   *
   * La proposition vit dans la session, le temps d'un aller-retour, et pas en
   * base : ce n'est pas un document de l'entreprise, c'est un brouillon. Elle se
   * lit une fois, comme un message flash — rouvrir le ticket demain ne doit pas
   * ressortir un résumé d'hier.
   */
  const propose = req.session.assistance;
  const propositions = propose && propose.ticketId === ticket.id ? propose : null;
  if (propose) delete req.session.assistance;

  res.render('ticket', {
    ticket,
    isAgent: agent,
    resumeIa: propositions ? propositions.resume || null : null,
    brouillonIa: propositions ? propositions.brouillon || '' : '',
    resumeOffert: agent && ai.fonctionPrete('resume'),
    brouillonOffert: agent && ai.fonctionPrete('redaction'),
    overdue: support.isOverdue(ticket),
    // Une note interne ne se montre pas au demandeur.
    messageList: support.messages(ticket.id, { includeInternal: agent }),
    statuses: support.STATUSES,
    priorities: support.PRIORITIES,
    people: agent ? activeUsers() : [],
    // Les files, pour transférer. Un demandeur ne les reçoit pas : savoir quels
    // groupes existent n'est pas son affaire.
    groups: agent ? support.groups() : [],
    // Les champs que l'entreprise a ajoutés aux tickets. Ils ne s'affichent que
    // pour qui traite la demande : c'est un classement interne, pas une
    // question posée au demandeur.
    champsPerso: agent ? customFields.valeurs('ticket', ticket.id) : [],
  });
});

/**
 * Deux coups de main sur un fil : le résumer, et proposer une réponse.
 *
 * Ni l'un ni l'autre n'écrit quoi que ce soit. Le résumé s'affiche une fois ; le
 * brouillon atterrit dans le champ de saisie, où il s'édite et d'où il faut
 * encore appuyer sur « Répondre ». Un service éteint, ou en panne, laisse l'écran
 * exactement tel qu'il était avant que ces boutons existent.
 */
async function coupDeMain(req, res, quoi) {
  const ticket = support.byId(req.params.id);
  if (!ticket) return fail(req, res, back('tickets'), 'Ticket introuvable.');

  const cible = `/support/tickets/${ticket.id}`;
  if (!isAgent(req)) return fail(req, res, cible, 'Réservé à qui traite les demandes.');

  const fil = {
    titre: ticket.subject,
    categorie: ticket.category,
    priorite: ticket.priority,
    demande: ticket.body,
    echanges: support.messages(ticket.id, { includeInternal: true }),
  };

  const verdict = quoi === 'resume'
    ? await assistance.resumer(fil, { req })
    : await assistance.brouillon(fil, { req });

  if (!verdict.ok) return fail(req, res, cible, verdict.message);

  req.session.assistance = quoi === 'resume'
    ? { ticketId: ticket.id, resume: verdict.resume }
    : { ticketId: ticket.id, brouillon: verdict.brouillon };

  // Ce que la porte a retiré se dit : c'est rassurant, et c'est vrai.
  const retires = Object.values(verdict.retires || {}).reduce((total, n) => total + n, 0);
  setFlash(req, 'success', retires
    ? `Proposition de l'assistance. ${retires} donnée(s) sensible(s) retirée(s) avant l'envoi.`
    : "Proposition de l'assistance. À relire avant de s'en servir.");
  return res.redirect(cible);
}

router.post('/tickets/:id/resumer', (req, res) => coupDeMain(req, res, 'resume'));
router.post('/tickets/:id/brouillon', (req, res) => coupDeMain(req, res, 'brouillon'));

router.post('/tickets/:id/repondre', (req, res) => {
  const ticket = support.byId(req.params.id);
  if (!ticket || !visibleTo(req, ticket)) return fail(req, res, back('tickets'), 'Ticket introuvable.');

  const body = (req.body.body || '').trim();
  if (!body) return fail(req, res, `/support/tickets/${ticket.id}`, 'Message vide.');

  support.reply({
    ticketId: ticket.id,
    authorId: req.currentUser.id,
    body: body.slice(0, 5000),
    internal: isAgent(req) && req.body.internal === '1',
  });
  res.redirect(`/support/tickets/${ticket.id}`);
});

router.post('/tickets/:id/statut', requireAgentFor, (req, res) => {
  const ticket = support.byId(req.params.id);
  if (!ticket) return fail(req, res, back('tickets'), 'Ticket introuvable.');
  if (!support.setStatus(ticket.id, req.body.status)) {
    return fail(req, res, `/support/tickets/${ticket.id}`, 'Statut invalide.');
  }
  res.redirect(`/support/tickets/${ticket.id}`);
});

router.post('/tickets/:id/priorite', requireAgentFor, (req, res) => {
  const ticket = support.byId(req.params.id);
  if (!ticket) return fail(req, res, back('tickets'), 'Ticket introuvable.');
  if (!support.setPriority(ticket.id, req.body.priority)) {
    return fail(req, res, `/support/tickets/${ticket.id}`, 'Priorité invalide.');
  }
  res.redirect(`/support/tickets/${ticket.id}`);
});

router.post('/tickets/:id/affecter', requireAgentFor, (req, res) => {
  const ticket = support.byId(req.params.id);
  if (!ticket) return fail(req, res, back('tickets'), 'Ticket introuvable.');
  support.assign(ticket.id, Number(req.body.assignee_id) || null);
  res.redirect(`/support/tickets/${ticket.id}`);
});

/*
 * Les champs personnalisés du ticket, dans leur propre formulaire.
 *
 * Le fil n'a pas d'écran d'édition où les glisser — un ticket ne se réécrit
 * pas — donc ils ont le leur, qui n'enregistre qu'eux.
 */
router.post('/tickets/:id/champs', requireAgentFor, (req, res) => {
  const ticket = support.byId(req.params.id);
  if (!ticket) return fail(req, res, back('tickets'), 'Ticket introuvable.');

  const cible = `/support/tickets/${ticket.id}`;
  const refus = customFields.refus('ticket', ticket.id, req.body, res.locals.t);
  if (refus) return fail(req, res, cible, refus);

  setFlash(req, 'success', 'Champs enregistrés.');
  res.redirect(cible);
});

router.post('/tickets/:id/supprimer', requireAgentFor, (req, res) => {
  const ticket = support.byId(req.params.id);
  if (!ticket) return fail(req, res, back('tickets'), 'Ticket introuvable.');
  corbeille.jeterPuis('ticket', ticket.id, { parId: req.session.user.id },
    () => support.remove(ticket.id));
  customFields.oublier('ticket', ticket.id);
  audit.log(req, 'ticket.supprime', 'tickets', ticket.id, { reference: ticket.reference });
  setFlash(req, 'success', 'Ticket supprimé.');
  res.redirect(back('tickets'));
});

/*
 * Transférer une demande d'un groupe à l'autre.
 *
 * C'est l'acte qui rend les files utiles : une demande mal orientée se déplace
 * au lieu d'être réécrite. Le groupe vide la retire de toute file et la rend aux
 * rôles — mieux que de la laisser dans une file où personne ne la lit.
 */
router.post('/tickets/:id/groupe', requireAgentFor, (req, res) => {
  const ticket = support.byId(req.params.id);
  if (!ticket) return fail(req, res, back('tickets'), 'Ticket introuvable.');
  const voulu = Number(req.body.group_id) || 0;
  if (!support.setTicketGroup(ticket.id, voulu)) {
    return fail(req, res, `/support/tickets/${ticket.id}`, 'Groupe de support introuvable.');
  }
  const groupe = voulu ? support.groupById(voulu) : null;
  audit.log(req, 'ticket.transfere', 'tickets', ticket.id, { groupe: groupe ? groupe.name : null });
  setFlash(req, 'success', groupe ? `Demande transférée au groupe « ${groupe.name} ».` : 'Demande retirée de sa file.');
  res.redirect(`/support/tickets/${ticket.id}`);
});

// ---------- Les groupes de support ----------

/*
 * L'écran d'administration du support.
 *
 * Il répond à une question que trois écrans ne répondaient pas : si Claire part
 * demain, qui lit les demandes informatiques ? La carte des accès la pose une
 * fois, catégorie par catégorie, au lieu de la faire reconstituer de tête.
 */
router.get('/groupes', exigerAdmin, (req, res) => {
  res.render('support-groupes', {
    groups: support.groups().map((g) => ({ ...g, members: support.groupMembers(g.id) })),
    access: support.accessMap(),
    categories: support.CATEGORIES,
    restricted: support.RESTRICTED_CATEGORY,
    people: activeUsers(),
  });
});

const versGroupes = (anchor) => `/support/groupes#${anchor}`;

router.post('/groupes', exigerAdmin, (req, res) => {
  const nom = (req.body.name || '').trim();
  if (!nom || nom.length > 120) return fail(req, res, versGroupes('nouveau'), 'Nom de groupe invalide.');

  const id = support.createGroup({ name: nom, description: (req.body.description || '').trim().slice(0, 500) });
  if (!id) return fail(req, res, versGroupes('nouveau'), 'Nom de groupe invalide.');

  support.setGroupCategories(id, [].concat(req.body.categories || []));
  audit.log(req, 'support.groupe_cree', 'support_groups', id, { nom });
  setFlash(req, 'success', `Groupe « ${nom} » créé.`);
  res.redirect(versGroupes('groupes'));
});

router.post('/groupes/:id/modifier', exigerAdmin, (req, res) => {
  const nom = (req.body.name || '').trim();
  if (!nom || nom.length > 120) return fail(req, res, versGroupes('groupes'), 'Nom de groupe invalide.');
  if (!support.renameGroup(req.params.id, { name: nom, description: (req.body.description || '').trim().slice(0, 500) })) {
    return fail(req, res, versGroupes('groupes'), 'Groupe introuvable.');
  }
  audit.log(req, 'support.groupe_modifie', 'support_groups', Number(req.params.id), { nom });
  setFlash(req, 'success', 'Groupe enregistré.');
  res.redirect(versGroupes('groupes'));
});

/*
 * Les catégories d'un groupe, remplacées d'un bloc.
 *
 * Une case décochée n'est pas envoyée par le navigateur : l'absence doit donc
 * valoir « non ». Le contraire rendrait une catégorie impossible à retirer
 * depuis l'écran.
 */
router.post('/groupes/:id/categories', exigerAdmin, (req, res) => {
  if (!support.setGroupCategories(req.params.id, [].concat(req.body.categories || []))) {
    return fail(req, res, versGroupes('groupes'), 'Groupe introuvable.');
  }
  const groupe = support.groupById(req.params.id);
  audit.log(req, 'support.groupe_categories', 'support_groups', groupe.id, { categories: groupe.categories });
  setFlash(req, 'success', `Prise en charge du groupe « ${groupe.name} » enregistrée.`);
  res.redirect(versGroupes('groupes'));
});

router.post('/groupes/:id/membres', exigerAdmin, (req, res) => {
  if (!support.addGroupMember(req.params.id, req.body.user_id)) {
    return fail(req, res, versGroupes('groupes'), 'Groupe ou personne introuvable.');
  }
  audit.log(req, 'support.groupe_membre_ajoute', 'support_groups', Number(req.params.id), {
    personne: Number(req.body.user_id),
  });
  setFlash(req, 'success', 'Personne ajoutée au groupe.');
  res.redirect(versGroupes('groupes'));
});

router.post('/groupes/:id/membres/retirer', exigerAdmin, (req, res) => {
  if (!support.removeGroupMember(req.params.id, req.body.user_id)) {
    return fail(req, res, versGroupes('groupes'), 'Cette personne n’est pas dans ce groupe.');
  }
  audit.log(req, 'support.groupe_membre_retire', 'support_groups', Number(req.params.id), {
    personne: Number(req.body.user_id),
  });
  setFlash(req, 'success', 'Personne retirée du groupe.');
  res.redirect(versGroupes('groupes'));
});

router.post('/groupes/:id/supprimer', exigerAdmin, (req, res) => {
  const groupe = support.groupById(req.params.id);
  if (!groupe) return fail(req, res, versGroupes('groupes'), 'Groupe introuvable.');
  support.deleteGroup(groupe.id);
  audit.log(req, 'support.groupe_supprime', 'support_groups', groupe.id, { nom: groupe.name });
  setFlash(req, 'success', `Groupe « ${groupe.name} » supprimé. Ses demandes restent visibles par les rôles.`);
  res.redirect(versGroupes('groupes'));
});

module.exports = router;
