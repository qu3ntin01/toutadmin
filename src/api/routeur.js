const express = require('express');
const rateLimit = require('express-rate-limit');

const audit = require('../audit');
const tokens = require('../api-tokens');
const manifeste = require('./manifeste');
const { erreur } = require('./aide');

/**
 * Le routeur, construit depuis le manifeste et depuis lui seul.
 *
 * Rien ne se monte ici à la main : ajouter une fonction, c'est l'ajouter au
 * manifeste, et elle apparaît du même coup dans la découverte, dans l'OpenAPI
 * et dans la documentation. Le chemin inverse — une route montée sans entrée —
 * n'existe pas.
 */

/**
 * Une écriture coûte plus cher qu'une lecture, et se répare moins bien.
 * Le plafond des écritures est donc à part, et bien plus bas : trente par
 * minute suffisent à un outil qui synchronise, et pas à un jeton volé qui vide
 * un annuaire.
 */
const limiteEcriture = rateLimit({
  windowMs: 60 * 1000,
  limit: Number(process.env.API_WRITE_RATE_LIMIT) || 30,
  standardHeaders: true,
  legacyHeaders: false,
  message: { error: "Trop d'écritures. Ce plafond est distinct de celui des lectures." },
});

/** Le garde de portée. Le refus dit la portée manquante : c'est réparable. */
function exigerPortee(portee) {
  return (req, res, next) => {
    if (!tokens.allows(req.apiToken, portee)) {
      return erreur(res, 403, `Ce jeton n'a pas la portée « ${portee} ».`, {
        // « scopes » était déjà là et des appelants le lisent : on l'ajoute,
        // on ne le renomme pas. Un champ renommé pour le style casse du code
        // qui marchait, et ne rend rien en échange.
        scopes: req.apiToken.scopeList,
        requise: portee,
        accordees: req.apiToken.scopeList,
      });
    }
    return next();
  };
}

/**
 * Consigne une écriture réussie au journal scellé.
 *
 * L'auteur n'est pas une personne mais un jeton : on écrit son intitulé, jamais
 * sa valeur. Une relecture du journal doit pouvoir dire « c'est l'outil de paie
 * qui a créé ce service », sans donner à qui la lit de quoi le rejouer.
 */
function journaliser(fonction, req, resultat) {
  if (!fonction.journal) return;

  const porteur = {
    ip: req.ip,
    session: { user: { id: null, email: `jeton « ${req.apiToken.label} »` } },
  };
  audit.log(porteur, fonction.journal, fonction.groupe, resultat && resultat.id, {
    voie: 'api',
    fonction: manifeste.cle(fonction),
    jeton: req.apiToken.id,
  });
}

/**
 * Enveloppe le code d'une fonction.
 *
 * Deux choses lui sont ajoutées : la trace au journal quand une écriture a
 * réussi — et réussi seulement, le code de statut en décide —, et le filet qui
 * transforme une exception en 500 JSON. Un programme qui reçoit une page
 * d'erreur HTML au lieu de JSON ne sait pas la lire.
 */
function executer(fonction) {
  return (req, res, next) => {
    // La trace se pose après coup : on ne consigne pas une écriture refusée
    // comme si elle avait eu lieu.
    if (fonction.ecrit && fonction.journal) {
      const json = res.json.bind(res);
      res.json = (corps) => {
        if (res.statusCode >= 200 && res.statusCode < 300) journaliser(fonction, req, corps);
        return json(corps);
      };
    }

    try {
      return fonction.faire(req, res);
    } catch (err) {
      return next(err);
    }
  };
}

function construire() {
  const routeur = express.Router();

  for (const fonction of manifeste.TOUTES) {
    const etapes = [exigerPortee(fonction.portee)];
    if (fonction.ecrit) etapes.unshift(limiteEcriture);

    routeur[fonction.methode.toLowerCase()](fonction.chemin, ...etapes, executer(fonction));
  }

  return routeur;
}

module.exports = { construire, limiteEcriture };
