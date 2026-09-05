const express = require('express');

const org = require('../org');
const audit = require('../audit');
const support = require('../support');
const { requireAuth } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();

router.use(requireAuth);

const back = (anchor) => `/base-de-connaissances#${anchor}`;

function fail(req, res, target, message) {
  setFlash(req, 'error', message);
  return res.redirect(target);
}

/** Écrivent : l'administration, les RH et les managers. Tout le monde lit. */
function canWrite(req) {
  const user = req.currentUser;
  return Boolean(user && (user.role === 'admin' || user.is_hr || org.isManager(user.id)));
}

function requireWriter(req, res, next) {
  if (canWrite(req)) return next();
  res.status(403).render('error', { message: "La rédaction est réservée à l'administration, aux RH et aux managers." });
}

router.get('/', (req, res) => {
  const query = (req.query.q || '').trim();
  const category = (req.query.categorie || '').trim();

  res.render('connaissances', {
    articleList: support.articles({ query, category, visibleTo: req.currentUser }),
    categoryList: support.categories(),
    query,
    category,
    canWrite: canWrite(req),
    visibilities: support.KB_VISIBILITIES,
    departments: org.departments(),
    teams: org.teams(),
  });
});

router.get('/:id', (req, res) => {
  const article = support.articleById(req.params.id);
  if (!article) return res.status(404).render('error', { message: 'Article introuvable.' });
  if (!support.canRead(article, req.currentUser)) {
    return res.status(403).render('error', { message: "Cet article n'est pas ouvert à votre périmètre." });
  }
  if (!article.published && !canWrite(req)) {
    return res.status(404).render('error', { message: 'Article introuvable.' });
  }

  support.noteRead(article.id);
  res.render('article', {
    article,
    canWrite: canWrite(req),
    visibilities: support.KB_VISIBILITIES,
    departments: org.departments(),
    teams: org.teams(),
  });
});

router.post('/', requireWriter, (req, res) => {
  const title = (req.body.title || '').trim();
  if (!title || title.length > 200) return fail(req, res, back('rediger'), 'Titre invalide.');
  if (!support.KB_VISIBILITIES.includes(req.body.visibility)) return fail(req, res, back('rediger'), 'Portée invalide.');

  const scopeId = ['Service', 'Équipe'].includes(req.body.visibility) ? Number(req.body.scope_id) || null : null;
  if (['Service', 'Équipe'].includes(req.body.visibility) && !scopeId) {
    return fail(req, res, back('rediger'), 'Une portée de service ou d\'équipe demande de choisir laquelle.');
  }

  const id = support.createArticle({
    title,
    category: (req.body.category || 'Général').trim().slice(0, 60),
    body: (req.body.body || '').slice(0, 60000),
    visibility: req.body.visibility,
    scopeId,
    authorId: req.currentUser.id,
  });

  audit.log(req, 'article.publie', 'kb_articles', id, { titre: title, portee: req.body.visibility });
  setFlash(req, 'success', 'Article publié.');
  res.redirect(`/base-de-connaissances/${id}`);
});

router.post('/:id/modifier', requireWriter, (req, res) => {
  const article = support.articleById(req.params.id);
  if (!article) return fail(req, res, back('articles'), 'Article introuvable.');

  const title = (req.body.title || '').trim();
  if (!title) return fail(req, res, `/base-de-connaissances/${article.id}`, 'Titre invalide.');
  if (!support.KB_VISIBILITIES.includes(req.body.visibility)) {
    return fail(req, res, `/base-de-connaissances/${article.id}`, 'Portée invalide.');
  }

  const scopeId = ['Service', 'Équipe'].includes(req.body.visibility) ? Number(req.body.scope_id) || null : null;
  support.updateArticle(article.id, {
    title,
    category: (req.body.category || 'Général').trim().slice(0, 60),
    body: (req.body.body || '').slice(0, 60000),
    visibility: req.body.visibility,
    scopeId,
    published: req.body.published === '1',
  });
  setFlash(req, 'success', 'Article mis à jour.');
  res.redirect(`/base-de-connaissances/${article.id}`);
});

router.post('/:id/supprimer', requireWriter, (req, res) => {
  const article = support.articleById(req.params.id);
  if (!article) return fail(req, res, back('articles'), 'Article introuvable.');
  support.deleteArticle(article.id);
  audit.log(req, 'article.supprime', 'kb_articles', article.id, { titre: article.title });
  setFlash(req, 'success', 'Article supprimé.');
  res.redirect(back('articles'));
});

module.exports = router;
