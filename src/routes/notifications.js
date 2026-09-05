const express = require('express');

const notifications = require('../notifications');
const { requireAuth } = require('../middleware/auth');
const { setFlash, safeRedirect } = require('../utils');

const router = express.Router();

router.use(requireAuth);

router.get('/', (req, res) => {
  res.render('notifications', {
    items: notifications.forUser(req.currentUser.id, { limit: 100 }),
  });
});

router.post('/:id/lue', (req, res) => {
  notifications.markRead(Number(req.params.id), req.currentUser.id);
  // Depuis une notification, on file droit à la page concernée.
  res.redirect(safeRedirect(req.body.retour, '/notifications'));
});

router.post('/tout-lu', (req, res) => {
  const count = notifications.markAllRead(req.currentUser.id);
  setFlash(req, 'success', `${count} notification(s) marquée(s) comme lue(s).`);
  res.redirect('/notifications');
});

router.post('/:id/supprimer', (req, res) => {
  notifications.remove(Number(req.params.id), req.currentUser.id);
  res.redirect('/notifications');
});

module.exports = router;
