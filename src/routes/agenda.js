const express = require('express');

const db = require('../db');
const calendar = require('../calendar');
const cse = require('../cse');
const org = require('../org');
const { requireEmployee } = require('../middleware/auth');
const { setFlash, isValidDateString } = require('../utils');

const router = express.Router();

router.use(requireEmployee);

function employeeRow(req) {
  return db.prepare('SELECT * FROM users WHERE id = ?').get(req.session.user.id);
}

const TIME_PATTERN = /^([01]\d|2[0-3]):[0-5]\d$/;

router.get('/', (req, res) => {
  const employee = employeeRow(req);
  const month = calendar.normalizeMonth(req.query.mois);
  const attendsCse = cse.isEligible(employee);
  // La vue partagée n'a de sens que pour un salarié rattaché à une équipe ou un service.
  const canShare = Boolean(employee.team_id || employee.department_id);
  const shared = canShare && req.query.vue === 'equipe';

  res.render('agenda', {
    employee,
    month,
    shared,
    canShare,
    team: employee.team_id ? org.teamById(employee.team_id) : null,
    teammates: org.teammates(employee),
    visibilities: calendar.VISIBILITIES,
    monthLabel: new Date(`${month}-01T00:00:00Z`).toLocaleDateString(res.locals.locale, {
      month: 'long',
      year: 'numeric',
      timeZone: 'UTC',
    }),
    previousMonth: calendar.shiftMonth(month, -1),
    nextMonth: calendar.shiftMonth(month, 1),
    currentMonth: calendar.normalizeMonth(null),
    viewQuery: shared ? '&vue=equipe' : '',
    weeks: calendar.buildGrid(month),
    agenda: calendar.monthAgenda(employee, month, { attendsCse, shared }),
    upcoming: calendar.upcoming(employee, { attendsCse }),
    categories: calendar.EVENT_CATEGORIES,
    today: calendar.toISODate(new Date()),
  });
});

router.post('/', (req, res) => {
  const month = calendar.normalizeMonth(req.body.mois);
  const back = `/agenda?mois=${month}`;

  const title = (req.body.title || '').trim().slice(0, 140);
  const startDate = (req.body.start_date || '').trim();
  const endDate = (req.body.end_date || '').trim() || startDate;
  const allDay = req.body.all_day === 'on';
  const startTime = (req.body.start_time || '').trim();
  const endTime = (req.body.end_time || '').trim();
  const category = (req.body.category || '').trim();
  const visibility = (req.body.visibility || 'Privé').trim();

  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect(back);
  };

  if (!title) return fail("L'intitulé de l'événement est obligatoire.");
  if (!isValidDateString(startDate)) return fail('Date de début invalide.');
  if (!isValidDateString(endDate)) return fail('Date de fin invalide.');
  if (endDate < startDate) return fail('La date de fin précède la date de début.');
  if (category && !calendar.EVENT_CATEGORIES.includes(category)) return fail('Catégorie invalide.');
  if (!calendar.VISIBILITIES.includes(visibility)) return fail('Portée de partage invalide.');

  // Un créneau horaire n'a de sens que sur un événement qui n'occupe pas la journée.
  if (!allDay) {
    if (startTime && !TIME_PATTERN.test(startTime)) return fail('Heure de début invalide.');
    if (endTime && !TIME_PATTERN.test(endTime)) return fail('Heure de fin invalide.');
    if (startTime && endTime && startDate === endDate && endTime < startTime) {
      return fail("L'heure de fin précède l'heure de début.");
    }
  }

  calendar.createEvent({
    userId: req.session.user.id,
    title,
    description: (req.body.description || '').trim().slice(0, 2000),
    location: (req.body.location || '').trim().slice(0, 140),
    startDate,
    endDate,
    startTime: allDay ? '' : startTime,
    endTime: allDay ? '' : endTime,
    allDay,
    category: category || 'Personnel',
    visibility,
  });

  setFlash(req, 'success', 'Événement ajouté à votre agenda.');
  res.redirect(back);
});

// Ouvrir ou refermer le partage d'un événement déjà créé.
router.post('/:id/partage', (req, res) => {
  const month = calendar.normalizeMonth(req.body.mois);
  const visibility = (req.body.visibility || '').trim();

  if (!calendar.setVisibility(Number(req.params.id), req.session.user.id, visibility)) {
    setFlash(req, 'error', 'Partage impossible : événement introuvable ou portée invalide.');
  } else {
    setFlash(req, 'success', visibility === 'Privé' ? 'Événement redevenu privé.' : `Événement partagé avec votre ${visibility.toLowerCase()}.`);
  }
  res.redirect(`/agenda?mois=${month}`);
});

router.post('/:id/supprimer', (req, res) => {
  const month = calendar.normalizeMonth(req.body.mois);
  if (!calendar.deleteEvent(Number(req.params.id), req.session.user.id)) {
    setFlash(req, 'error', 'Événement introuvable.');
  } else {
    setFlash(req, 'success', 'Événement supprimé.');
  }
  res.redirect(`/agenda?mois=${month}`);
});

module.exports = router;
