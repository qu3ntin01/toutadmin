const tokens = require('../api-tokens');
const manifeste = require('./manifeste');

/**
 * Le document OpenAPI 3.1, déduit du manifeste.
 *
 * Il n'est pas écrit à la main et ne peut donc pas vieillir : il se recalcule à
 * chaque appel depuis les mêmes déclarations que les routes elles-mêmes. Ce
 * qu'un générateur de client en tire est, par construction, ce que l'API sert.
 */

const TYPES = {
  entier: { type: 'integer' },
  texte: { type: 'string' },
  nombre: { type: 'number' },
  date: { type: 'string', format: 'date' },
  booleen: { type: 'boolean' },
};

const schemaDe = (type) => TYPES[type] || TYPES.texte;

/** `/collaborateurs/:id` s'écrit `/collaborateurs/{id}` en OpenAPI. */
const enAccolades = (chemin) => chemin.replace(/:([A-Za-z_]+)/g, '{$1}');

/** Les paramètres de chemin et de requête. Le corps se décrit à part. */
function parametres(fonction) {
  return fonction.parametres
    .filter((p) => p.dans !== 'corps')
    .map((p) => ({
      name: p.nom,
      in: p.dans === 'chemin' ? 'path' : 'query',
      required: p.dans === 'chemin' ? true : Boolean(p.requis),
      description: p.description || '',
      schema: p.defaut === undefined ? schemaDe(p.type) : { ...schemaDe(p.type), default: p.defaut },
    }));
}

function corps(fonction) {
  const champs = fonction.parametres.filter((p) => p.dans === 'corps');
  if (!champs.length) return undefined;

  const properties = {};
  const required = [];
  for (const champ of champs) {
    properties[champ.nom] = { ...schemaDe(champ.type), description: champ.description || '' };
    if (champ.requis) required.push(champ.nom);
  }

  return {
    required: required.length > 0,
    content: {
      'application/json': {
        schema: { type: 'object', properties, ...(required.length ? { required } : {}) },
      },
    },
  };
}

/**
 * Les réponses.
 *
 * Les codes d'erreur ne sont pas énumérés fonction par fonction : ils sont les
 * mêmes partout, et les répéter soixante fois dans le document aurait surtout
 * garanti qu'ils finissent par différer.
 */
function reponses(fonction) {
  const succes = fonction.ecrit && fonction.methode === 'POST' ? '201' : '200';
  return {
    [succes]: {
      description: fonction.rend.length ? `Champs rendus : ${fonction.rend.join(', ')}.` : 'Succès.',
      content: { 'application/json': { schema: { type: 'object' } } },
    },
    401: { $ref: '#/components/responses/SansJeton' },
    403: { $ref: '#/components/responses/PorteeManquante' },
    404: { $ref: '#/components/responses/Absent' },
    429: { $ref: '#/components/responses/TropDeRequetes' },
  };
}

function document({ version = '1', serveur = '/' } = {}) {
  const paths = {};

  for (const fonction of manifeste.TOUTES) {
    const chemin = enAccolades(manifeste.cheminComplet(fonction));
    paths[chemin] = paths[chemin] || {};
    paths[chemin][fonction.methode.toLowerCase()] = {
      tags: [fonction.groupe],
      summary: fonction.resume,
      description: [fonction.details, fonction.ecrit ? "Écriture : consignée au journal d'audit sous l'intitulé du jeton." : '']
        .filter(Boolean).join('\n\n'),
      operationId: `${fonction.methode.toLowerCase()}${enAccolades(fonction.chemin).replace(/[^A-Za-z0-9]+(.)/g, (m, c) => c.toUpperCase())}`,
      security: [{ jeton: [fonction.portee] }],
      parameters: parametres(fonction),
      requestBody: corps(fonction),
      responses: reponses(fonction),
    };
  }

  const erreurSchema = {
    type: 'object',
    properties: { error: { type: 'string' } },
    required: ['error'],
  };

  return {
    openapi: '3.1.0',
    info: {
      title: 'Toutadmin — API v1',
      version,
      description: [
        'Une API de lecture, plus des écritures que l’administrateur accorde une à une.',
        '',
        "L'authentification tient dans l'en-tête `Authorization: Bearer …`. Il n'y a ni cookie, ni session, ni jeton CSRF.",
        '',
        "Une portée d'écriture s'écrit `domaine:ecriture` et emporte la lecture du même domaine. L'inverse est faux.",
      ].join('\n'),
    },
    servers: [{ url: serveur }],
    tags: manifeste.GROUPES.map((g) => ({ name: g.cle, description: g.resume })),
    paths,
    components: {
      securitySchemes: {
        jeton: { type: 'http', scheme: 'bearer', description: 'Un jeton créé dans Intégrations → Jetons d’API.' },
      },
      schemas: { Erreur: erreurSchema },
      responses: {
        SansJeton: { description: 'Jeton absent, invalide ou expiré.', content: { 'application/json': { schema: erreurSchema } } },
        PorteeManquante: { description: 'Le jeton n’a pas la portée exigée.', content: { 'application/json': { schema: erreurSchema } } },
        Absent: { description: 'Ressource introuvable.', content: { 'application/json': { schema: erreurSchema } } },
        TropDeRequetes: { description: 'Plafond atteint. Les écritures ont le leur, distinct des lectures.', content: { 'application/json': { schema: erreurSchema } } },
      },
    },
    'x-portees': [
      ...tokens.SCOPES.map((s) => ({ cle: s.key, intitule: s.label, ecrit: false, note: s.hint })),
      ...tokens.WRITE_SCOPES.map((s) => ({ cle: s.key, intitule: s.label, ecrit: true, lit: s.reads, note: s.hint })),
    ],
  };
}

module.exports = { document, enAccolades };
