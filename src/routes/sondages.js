const express = require('express');

const audit = require('../audit');
const surveys = require('../surveys');
const { requireAuth } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();

// Répondre est un acte personnel : chacun accède à ses sondages, personne
// d'autre. Rien ici ne permet de lire les réponses — c'est l'écran de
// direction qui montre les agrégats, et lui seul.
router.use(requireAuth);

router.get('/', (req, res) => {
  const userId = req.session.user.id;
  res.render('sondages', {
    invitations: surveys.openFor(userId),
    answered: surveys.list({ status: 'Clos' })
      .concat(surveys.list({ status: 'Ouvert' }))
      .filter((s) => surveys.hasAnswered(s.id, userId)),
    anonymityThreshold: surveys.ANONYMITY_THRESHOLD,
  });
});

router.get('/:id', (req, res) => {
  const survey = surveys.byId(req.params.id);
  const userId = req.session.user.id;
  if (!survey || survey.status !== 'Ouvert' || !surveys.isInvited(survey, userId)) {
    return res.status(404).render('error', { message: "Ce sondage ne vous est pas ouvert." });
  }
  if (surveys.hasAnswered(survey.id, userId)) {
    setFlash(req, 'error', 'Vous avez déjà répondu à ce sondage.');
    return res.redirect('/sondages');
  }

  res.render('sondage', {
    survey,
    questionList: surveys.questions(survey.id),
    scale: surveys.SCALE,
  });
});

router.post('/:id', (req, res) => {
  const verdict = surveys.submit(Number(req.params.id), req.session.user.id, req.body);
  if (!verdict.ok) {
    setFlash(req, 'error', verdict.message);
    return res.redirect(`/sondages/${Number(req.params.id)}`);
  }

  // La trace dit qu'il a répondu, jamais ce qu'il a répondu : le journal
  // d'audit ne doit pas rendre par la bande ce que les tables refusent.
  audit.log(req, 'sondage.repondu', 'surveys', Number(req.params.id), {});
  setFlash(req, 'success', 'Merci. Votre réponse est enregistrée sans lien avec votre nom.');
  res.redirect('/sondages');
});

module.exports = router;
