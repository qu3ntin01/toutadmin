const express = require('express');

const db = require('../db');
const cse = require('../cse');
const { requireCseMember, requireCseElected } = require('../middleware/auth');
const { setFlash, isValidDateString, isValidUrl } = require('../utils');

const router = express.Router();

// Tout l'espace CSE suppose d'être représenté par le comité.
router.use(requireCseMember);

function currentUserRow(req) {
  return db.prepare('SELECT * FROM users WHERE id = ?').get(req.session.user.id);
}

// ---------- Espace salarié ----------

router.get('/', (req, res) => {
  const election = cse.openElection();
  const userId = req.session.user.id;

  res.render('cse', {
    benefits: cse.benefits({ activeOnly: true }),
    categories: cse.BENEFIT_CATEGORIES,
    meetings: cse.meetingsForStaff(),
    mandates: cse.mandates(),
    election,
    // En phase de candidature chacun voit les postulants ; pendant le vote, seuls les validés.
    candidacies: election ? cse.candidacies(election.id, { validatedOnly: election.status === 'Vote' }) : [],
    myCandidacy: election ? cse.candidacyFor(election.id, userId) : null,
    hasVoted: election ? cse.hasVoted(election.id, userId) : false,
    isElected: cse.isElected(userId),
    lastClosed: db.prepare("SELECT * FROM cse_elections WHERE status = 'Clôturée' ORDER BY closed_at DESC LIMIT 1").get() || null,
    resultsFor: (id) => cse.results(id),
    turnoutFor: (id) => cse.turnout(id),
  });
});

// Se présenter au CSE.
router.post('/candidature', (req, res) => {
  const statement = (req.body.statement || '').trim().slice(0, 2000);
  const election = cse.openElection();

  if (!election) {
    setFlash(req, 'error', 'Aucune élection ouverte pour le moment.');
    return res.redirect('/cse#elections');
  }

  const result = cse.applyForElection({ electionId: election.id, userId: req.session.user.id, statement });
  const messages = {
    closed: 'Les candidatures ne sont plus ouvertes pour cette élection.',
    'already-applied': 'Vous avez déjà déposé une candidature.',
    'not-found': 'Élection introuvable.',
  };

  if (!result.ok) setFlash(req, 'error', messages[result.reason] || 'Candidature impossible.');
  else setFlash(req, 'success', 'Candidature déposée. Elle sera validée par les ressources humaines.');

  res.redirect('/cse#elections');
});

router.post('/candidature/retirer', (req, res) => {
  const election = cse.openElection();
  if (!election) {
    setFlash(req, 'error', 'Aucune élection ouverte pour le moment.');
    return res.redirect('/cse#elections');
  }

  const result = cse.withdrawCandidacy(election.id, req.session.user.id);
  if (!result.ok) setFlash(req, 'error', 'Candidature non retirable : le scrutin a déjà avancé.');
  else setFlash(req, 'success', 'Candidature retirée.');

  res.redirect('/cse#elections');
});

router.post('/vote', (req, res) => {
  const election = cse.openElection();
  const candidacyId = Number(req.body.candidacy_id);

  if (!election) {
    setFlash(req, 'error', 'Aucune élection ouverte pour le moment.');
    return res.redirect('/cse#elections');
  }

  const result = cse.castBallot({ electionId: election.id, userId: req.session.user.id, candidacyId });
  const messages = {
    'not-open': "Le vote n'est pas ouvert.",
    'already-voted': 'Vous avez déjà voté pour cette élection.',
    'bad-candidate': 'Candidat invalide.',
    'not-found': 'Élection introuvable.',
  };

  if (!result.ok) setFlash(req, 'error', messages[result.reason] || 'Vote impossible.');
  else setFlash(req, 'success', 'Vote enregistré. Votre bulletin est anonyme.');

  res.redirect('/cse#elections');
});

// ---------- Espace de gestion des élus ----------

router.use('/gestion', requireCseElected);

router.get('/gestion', (req, res) => {
  res.render('cse-manage', {
    benefits: cse.benefits(),
    categories: cse.BENEFIT_CATEGORIES,
    meetings: cse.meetings(),
    mandates: cse.mandates(),
    myMandate: cse.mandateFor(req.session.user.id),
    stats: {
      benefitCount: cse.benefits({ activeOnly: true }).length,
      meetingCount: cse.upcomingMeetings(50).length,
      pendingMinutes: cse.meetings().filter((m) => !m.minutes_published).length,
      memberCount: cse.mandates().length,
    },
  });
});

function readBenefit(body) {
  const title = (body.title || '').trim().slice(0, 140);
  const url = (body.url || '').trim().slice(0, 500);
  const validUntil = (body.valid_until || '').trim();

  if (!title) return { ok: false, message: "L'intitulé de l'avantage est obligatoire." };
  if (url && !isValidUrl(url)) return { ok: false, message: 'Lien invalide : une adresse http(s) est attendue.' };
  if (validUntil && !isValidDateString(validUntil)) return { ok: false, message: 'Date de validité invalide.' };

  const category = (body.category || '').trim();
  if (category && !cse.BENEFIT_CATEGORIES.includes(category)) return { ok: false, message: 'Catégorie invalide.' };

  return {
    ok: true,
    data: {
      title,
      category,
      partner: (body.partner || '').trim().slice(0, 140),
      description: (body.description || '').trim().slice(0, 2000),
      discount: (body.discount || '').trim().slice(0, 60),
      code: (body.code || '').trim().slice(0, 60),
      url,
      validUntil: validUntil || null,
      active: body.active !== undefined ? Boolean(body.active) : true,
    },
  };
}

router.post('/gestion/avantages', (req, res) => {
  const parsed = readBenefit(req.body);
  if (!parsed.ok) {
    setFlash(req, 'error', parsed.message);
    return res.redirect('/cse/gestion#avantages');
  }

  cse.createBenefit({ ...parsed.data, createdBy: req.session.user.id });
  setFlash(req, 'success', 'Avantage publié.');
  res.redirect('/cse/gestion#avantages');
});

router.post('/gestion/avantages/:id/modifier', (req, res) => {
  const benefit = cse.benefitById(Number(req.params.id));
  if (!benefit) {
    setFlash(req, 'error', 'Avantage introuvable.');
    return res.redirect('/cse/gestion#avantages');
  }

  const parsed = readBenefit({ ...req.body, active: req.body.active === 'on' });
  if (!parsed.ok) {
    setFlash(req, 'error', parsed.message);
    return res.redirect('/cse/gestion#avantages');
  }

  cse.updateBenefit(benefit.id, parsed.data);
  setFlash(req, 'success', 'Avantage mis à jour.');
  res.redirect('/cse/gestion#avantages');
});

router.post('/gestion/avantages/:id/supprimer', (req, res) => {
  cse.deleteBenefit(Number(req.params.id));
  setFlash(req, 'success', 'Avantage supprimé.');
  res.redirect('/cse/gestion#avantages');
});

// Le compte-rendu est rédigé par les élus ; la convocation, elle, vient des RH.
router.post('/gestion/reunions/:id/compte-rendu', (req, res) => {
  const minutes = (req.body.minutes || '').trim().slice(0, 20000);
  const publish = req.body.publish === 'on';

  if (!cse.saveMinutes(Number(req.params.id), minutes, publish)) {
    setFlash(req, 'error', 'Réunion introuvable.');
  } else {
    setFlash(req, 'success', publish ? 'Compte-rendu publié.' : 'Compte-rendu enregistré en brouillon.');
  }
  res.redirect('/cse/gestion#reunions');
});

module.exports = router;
module.exports.currentUserRow = currentUserRow;
