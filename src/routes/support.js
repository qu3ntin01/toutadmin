const express = require('express');

const db = require('../db');
const org = require('../org');
const audit = require('../audit');
const support = require('../support');
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
 * L'administration et les RH voient tout ; la gestion et les managers voient
 * tout sauf les demandes RH, qui parlent de paie, de contrat, parfois de santé.
 * Tout le monde peut ouvrir un ticket et suivre les siens.
 */
function agentCategories(req) {
  const user = req.currentUser;
  if (!user) return [];
  const base = support.agentCategories(user);
  if (base.length) return base;
  // Un manager encadre : il traite les demandes de son périmètre, hors RH.
  return org.isManager(user.id) ? support.CATEGORIES.filter((c) => c !== support.RESTRICTED_CATEGORY) : [];
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
    openOnly: req.query.tous !== '1',
  };

  const allowed = agentCategories(req);
  const tickets = agent
    ? support.list({ ...filters, categories: allowed })
    : support.list({ ...filters, requesterId: req.currentUser.id });

  res.render('support', {
    isAgent: agent,
    tickets: tickets.map((tk) => ({ ...tk, overdue: support.isOverdue(tk) })),
    mine: support.list({ requesterId: req.currentUser.id }),
    assigned: agent ? support.list({ assigneeId: req.currentUser.id, openOnly: true, categories: allowed }) : [],
    filters,
    summary: support.summary(),
    categories: support.CATEGORIES,
    priorities: support.PRIORITIES,
    statuses: support.STATUSES,
    origins: support.ORIGINS,
    responseHours: support.RESPONSE_HOURS,
    people: agent ? activeUsers() : [],
    partners: agent ? finance.partners() : [],
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
  res.render('ticket', {
    ticket,
    isAgent: agent,
    overdue: support.isOverdue(ticket),
    // Une note interne ne se montre pas au demandeur.
    messageList: support.messages(ticket.id, { includeInternal: agent }),
    statuses: support.STATUSES,
    priorities: support.PRIORITIES,
    people: agent ? activeUsers() : [],
  });
});

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

router.post('/tickets/:id/supprimer', requireAgentFor, (req, res) => {
  const ticket = support.byId(req.params.id);
  if (!ticket) return fail(req, res, back('tickets'), 'Ticket introuvable.');
  support.remove(ticket.id);
  audit.log(req, 'ticket.supprime', 'tickets', ticket.id, { reference: ticket.reference });
  setFlash(req, 'success', 'Ticket supprimé.');
  res.redirect(back('tickets'));
});

module.exports = router;
