const express = require('express');

const kanban = require('../kanban');
const { requireAuth } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();
router.use(requireAuth);

/**
 * Déplacer une carte.
 *
 * Un seul chemin, comme pour les actions de masse, et pour la même raison : le
 * droit n'est pas sur le chemin mais sur la ligne, et le module le vérifie. Une
 * route par objet aurait multiplié les endroits où l'oublier.
 */
router.post('/deplacer', (req, res) => {
  const verdict = kanban.deplacer({
    objet: String(req.body.objet || ''),
    id: req.body.id,
    colonne: String(req.body.colonne || ''),
    user: req.currentUser,
  });

  setFlash(
    req,
    verdict.ok ? 'success' : 'error',
    res.locals.t(verdict.ok ? 'kanban.moved' : verdict.raison),
  );
  return res.redirect(req.get('referer') || '/');
});

module.exports = router;
