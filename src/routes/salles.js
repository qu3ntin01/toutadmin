const express = require('express');

const resources = require('../resources');
const { requireAuth } = require('../middleware/auth');
const { setFlash, isValidDateString } = require('../utils');

const router = express.Router();

// Réserver une salle est ouvert à tout le personnel ; la gestion tient le parc.
router.use(requireAuth);

router.get('/', (req, res) => {
  const date = isValidDateString(req.query.jour || '') ? req.query.jour : new Date().toISOString().slice(0, 10);
  const rooms = resources.rooms({ activeOnly: true });
  const dayBookings = resources.bookings({ from: date, to: date });

  const shift = (days) => {
    const d = new Date(`${date}T00:00:00Z`);
    d.setUTCDate(d.getUTCDate() + days);
    return d.toISOString().slice(0, 10);
  };

  res.render('rooms', {
    date,
    previousDay: shift(-1),
    nextDay: shift(1),
    today: new Date().toISOString().slice(0, 10),
    rooms,
    // Une salle par colonne : c'est ainsi qu'on lit un planning d'occupation.
    bookingsByRoom: Object.fromEntries(rooms.map((r) => [r.id, dayBookings.filter((b) => b.room_id === r.id)])),
    myBookings: resources.bookings({ from: new Date().toISOString().slice(0, 10) })
      .filter((b) => b.user_id === req.session.user.id),
  });
});

router.post('/', (req, res) => {
  const roomId = Number(req.body.room_id);
  const title = (req.body.title || '').trim().slice(0, 140);
  const bookingDate = (req.body.booking_date || '').trim();
  const startTime = (req.body.start_time || '').trim();
  const endTime = (req.body.end_time || '').trim();
  const backTo = `/salles?jour=${isValidDateString(bookingDate) ? bookingDate : ''}`;

  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect(backTo);
  };

  if (!title) return fail("L'objet de la réservation est obligatoire.");
  if (!isValidDateString(bookingDate)) return fail('Date invalide.');

  const result = resources.bookRoom({ roomId, userId: req.session.user.id, title, bookingDate, startTime, endTime });
  const messages = {
    'not-found': 'Salle introuvable.',
    inactive: "Cette salle n'est pas réservable.",
    'bad-time': 'Horaires invalides.',
    'bad-range': "L'heure de fin précède l'heure de début.",
  };

  if (!result.ok) {
    if (result.reason === 'clash') {
      const { first_name: first, last_name: last, start_time: from, end_time: to } = result.clash;
      return fail(`Créneau déjà pris par ${first} ${last} (${from} – ${to}).`);
    }
    return fail(messages[result.reason] || 'Réservation impossible.');
  }

  setFlash(req, 'success', 'Salle réservée.');
  res.redirect(backTo);
});

router.post('/:id/annuler', (req, res) => {
  const day = isValidDateString(req.body.jour || '') ? req.body.jour : '';
  if (!resources.cancelBooking(Number(req.params.id), req.session.user.id)) {
    setFlash(req, 'error', "Vous ne pouvez annuler que vos propres réservations.");
  } else {
    setFlash(req, 'success', 'Réservation annulée.');
  }
  res.redirect(`/salles?jour=${day}`);
});

module.exports = router;
