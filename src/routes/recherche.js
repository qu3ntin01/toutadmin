const express = require('express');

const search = require('../search');
const { requireAuth } = require('../middleware/auth');

const router = express.Router();

router.use(requireAuth);

router.get('/', (req, res) => {
  const query = (req.query.q || '').trim().slice(0, 100);
  res.render('recherche', {
    query,
    groups: query ? search.search(query, req.currentUser) : [],
  });
});

module.exports = router;
