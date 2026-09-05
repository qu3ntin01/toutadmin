const express = require('express');

const org = require('../org');
const { requireAuth } = require('../middleware/auth');

const router = express.Router();

// L'organigramme est un outil de repérage : il est ouvert à tous, comme
// l'annuaire dont il suit exactement les règles de visibilité.
router.use(requireAuth);

router.get('/', (req, res) => {
  const user = req.currentUser || req.session.user;
  // L'administration et les RH voient l'effectif entier ; les autres voient ce
  // que l'annuaire montre, ni plus ni moins.
  const includeHidden = user.role === 'admin' || Boolean(user.is_hr);

  res.render('organigramme', {
    chart: org.chart({ includeHidden }),
    includeHidden,
    viewerId: user.id,
  });
});

module.exports = router;
