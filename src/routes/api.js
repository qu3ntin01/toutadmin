const express = require('express');
const rateLimit = require('express-rate-limit');

const tokens = require('../api-tokens');
const societes = require('../societes');
const manifeste = require('../api/manifeste');
const routeur = require('../api/routeur');
const openapi = require('../api/openapi');
const { erreur } = require('../api/aide');

const router = express.Router();

/**
 * API, version 1.
 *
 * Ce fichier ne contient aucune fonction métier : il pose l'authentification,
 * les plafonds et les deux routes qui décrivent l'API elle-même, puis passe la
 * main au routeur construit depuis `src/api/manifeste.js`. Les fonctions sont
 * là-bas, une par entrée, groupées par domaine.
 *
 * Ce que le jeton peut faire est décidé par sa portée, et par elle seule :
 *
 *   — la lecture est la règle, et suffit à la plupart des usages ;
 *   — l'écriture est une portée à part (« domaine:ecriture »), que
 *     l'administrateur coche exprès. Elle emporte la lecture du même domaine,
 *     jamais l'inverse ;
 *   — toute écriture réussie entre au journal d'audit sous l'intitulé du
 *     jeton. Le journal ne dit jamais sa valeur.
 *
 * Pas de cookie, donc pas de session, donc pas de jeton CSRF : l'authentification
 * tient entièrement dans l'en-tête « Authorization: Bearer … ».
 */

// Un jeton volé ne doit pas permettre d'aspirer la base en quelques secondes.
const apiLimiter = rateLimit({
  windowMs: 60 * 1000,
  limit: Number(process.env.API_RATE_LIMIT) || 120,
  standardHeaders: true,
  legacyHeaders: false,
  message: { error: 'Trop de requêtes.' },
});

router.use(express.json({ limit: '64kb' }));
router.use(apiLimiter);

// Une réponse d'API ne se met pas en cache : elle porte des données nominatives.
router.use((req, res, next) => {
  res.setHeader('Cache-Control', 'no-store');
  next();
});

function unauthorized(res) {
  res.setHeader('WWW-Authenticate', 'Bearer realm="Toutadmin"');
  // On ne dit pas si le jeton est inconnu, révoqué ou périmé : cela renseignerait.
  return res.status(401).json({ error: "Jeton absent, invalide ou expiré." });
}

router.use((req, res, next) => {
  const header = String(req.headers.authorization || '');
  const bearer = header.toLowerCase().startsWith('bearer ') ? header.slice(7).trim() : '';
  const presented = bearer || String(req.headers['x-api-key'] || '').trim();
  if (!presented) return unauthorized(res);

  const token = tokens.resolve(presented);
  if (!token) return unauthorized(res);

  tokens.touch(token.id, req.ip, token.company_id);
  req.apiToken = token;
  req.societeId = token.company_id;

  /*
   * Le jeton porte son périmètre, et tout l'appel travaille dedans.
   *
   * C'est ce qui distingue un jeton d'une tâche de fond : une tâche n'a pas de
   * société et retombe sur celle qui mène, un jeton en désigne une. Sans cela,
   * un jeton créé depuis une filiale lirait les données de la maison mère — ce
   * qui, sur une instance à plusieurs sociétés, serait une fuite servie par
   * l'API elle-même.
   */
  return societes.dans(token.company_id, next);
});

// ---------- L'API se décrit elle-même ----------

/**
 * La racine récite le catalogue, filtré par ce que le jeton peut faire.
 *
 * Un jeton qui n'a que l'annuaire n'apprend pas ici quelles routes de paie
 * existent : la découverte se tait sur ce qu'elle refuserait de servir.
 */
router.get('/', (req, res) => {
  const peutEcrire = tokens.canWrite(req.apiToken);
  const groupes = manifeste.pourJeton(req.apiToken);

  res.json({
    version: 1,
    // Ce champ existait quand l'API était en lecture seule. Il reste, et dit
    // la vérité pour ce jeton-ci : un appelant qui le lisait n'est pas trompé.
    readOnly: !peutEcrire,
    token: {
      label: req.apiToken.label,
      scopes: req.apiToken.scopeList,
      expiresAt: req.apiToken.expires_at,
      peutEcrire,
    },
    openapi: `${manifeste.BASE}/openapi.json`,
    groupes: groupes,
    /*
     * « endpoints » date de l'API à neuf fonctions, et des appelants le
     * lisent. Il reste : la liste à plat de ce que ce jeton-ci peut appeler,
     * déduite des mêmes groupes. Un champ retiré parce qu'un plus riche est
     * arrivé casse du code qui marchait, et ne rend rien en échange.
     */
    endpoints: groupes.flatMap((groupe) => groupe.fonctions.map((fonction) => ({
      path: fonction.chemin,
      method: fonction.methode,
      scope: fonction.portee,
    }))),
  });
});

/**
 * Le document OpenAPI, entier.
 *
 * Il n'est pas filtré par la portée du jeton, lui : un générateur de client a
 * besoin du contrat complet pour engendrer les appels que l'utilisateur
 * obtiendra le jour où on lui accordera la portée. Il ne contient que des noms
 * de routes, jamais de données.
 */
router.get('/openapi.json', (req, res) => {
  res.json(openapi.document({
    version: String(require('../../package.json').version || '1'),
    serveur: `${req.protocol}://${req.get('host')}`,
  }));
});

// ---------- Les fonctions, depuis le manifeste ----------

router.use(routeur.construire());

// Une route inconnue rend du JSON, pas une page d'erreur HTML : l'appelant est
// un programme.
router.use((req, res) => erreur(res, 404, 'Ressource inconnue.', { catalogue: manifeste.BASE }));

// Une exception non rattrapée aussi : sinon l'appelant reçoit du HTML.
router.use((err, req, res, next) => { // eslint-disable-line no-unused-vars
  console.error('API :', err && err.message);
  return erreur(res, 500, "L'instance n'a pas pu répondre.");
});

module.exports = router;
