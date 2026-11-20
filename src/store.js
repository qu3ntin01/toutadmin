const modules = require('./modules');
const settings = require('./settings');
const updates = require('./updates');
const logs = require('./logs');

/**
 * La boutique d'applications, vue de l'instance.
 *
 * Le catalogue n'est pas ici. Il vit chez l'éditeur, et cette instance le
 * demande à chaque visite de l'écran : une réserve mal formulée, un prix qui
 * change, une application qui devient disponible se voient sans qu'on mette à
 * jour le produit. Garder une copie en base reviendrait à figer le catalogue au
 * jour de la dernière mise à jour, ce qui est exactement ce qu'on ne veut pas.
 *
 * L'adresse et la clé sont celles des mises à jour — même éditeur, même clé
 * d'instance. En demander une seconde paire ferait deux réglages à tenir
 * d'accord pour une seule relation.
 *
 * ── Les trois genres, et pourquoi l'écran les distingue ──────────────────
 *
 *   incluse : la rubrique est déjà là, livrée avec le produit. L'écran ouvre.
 *   module  : le code est livré mais éteint. L'écran allume.
 *   bientot : la fiche existe, le code non. L'écran le dit, et n'offre rien.
 *
 * Montrer un bouton « installer » sur une « bientôt » ferait perdre une
 * demi-journée au premier qui clique, puis sa confiance. Le catalogue dit la
 * vérité ; l'écran ne la maquille pas.
 */

const GENRES = ['incluse', 'module', 'bientot'];

/** Une minute de répit : l'écran se recharge, l'éditeur n'a pas à le savoir. */
const CACHE_MS = 60_000;
let cache = null;

/** Pour les tests : remettre la boutique dans l'état où elle n'a rien demandé. */
function resetCache() {
  cache = null;
}

/**
 * Demander le catalogue à l'éditeur.
 *
 * Les pannes sont nommées plutôt que confondues : « pas configuré » se corrige
 * dans les réglages, « clé refusée » chez l'éditeur, « injoignable » se
 * réessaie. Les trois rendues comme une seule erreur laisseraient
 * l'administrateur sans rien à faire.
 */
async function fetchCatalogue({ force = false } = {}) {
  if (!force && cache && Date.now() - cache.at < CACHE_MS) return cache.catalogue;
  const config = updates.config();
  if (!config.url || !config.key) return { ok: false, reason: 'not-configured' };

  const url = new URL(`${config.url}/api/v1/applications`);
  url.searchParams.set('edition', updates.EDITION);

  try {
    const response = await fetch(url, {
      headers: { 'X-Cle-Instance': config.key },
      signal: AbortSignal.timeout(15000),
    });
    if (!response.ok) {
      /*
       * Trois refus que l'administrateur peut corriger, et un quatrième qui
       * les rassemble tous. Rendre « http-418 » à l'écran donnerait une clé de
       * traduction qui n'existe pas, donc un code affiché tel quel : le numéro
       * va au journal, et l'écran reçoit un mot qu'on a écrit dans seize
       * langues.
       */
      const reason = response.status === 401 ? 'key-refused'
        : response.status === 503 ? 'catalogue-absent' : 'refused';
      logs.record({
        level: 4, channel: 'integration', event: 'store.fetch.failed',
        message: `Catalogue refusé par l’éditeur (HTTP ${response.status}).`,
      });
      return { ok: false, reason };
    }
    const body = await response.json();
    const catalogue = {
      ok: true,
      rayons: body.rayons && typeof body.rayons === 'object' ? body.rayons : {},
      // Où le client achète. Donnée par l'éditeur plutôt que réglée ici : le
      // jour où l'adresse change, aucune instance n'a à être modifiée.
      espaceClient: typeof body.espace_client === 'string' ? body.espace_client : '',
      applications: Array.isArray(body.applications) ? body.applications : [],
    };
    return catalogue;
  } catch (err) {
    logs.record({
      level: 4, channel: 'integration', event: 'store.fetch.failed',
      message: `Éditeur injoignable : ${err.message}`,
    });
    return { ok: false, reason: 'unreachable', detail: err.message };
  }
}

/**
 * L'état local d'une fiche : ce que l'instance en sait, elle.
 *
 * L'éditeur dit ce que l'application est ; l'instance dit où elle en est. Une
 * fiche « module » déjà allumée s'ouvre comme une « incluse » — c'est le même
 * geste pour l'utilisateur, et c'est ce qui compte à l'écran.
 */
function etatLocal(fiche) {
  const genre = GENRES.includes(fiche.genre) ? fiche.genre : 'bientot';
  if (genre === 'incluse') return { etat: 'ouverte', href: fiche.href || '' };
  if (genre === 'bientot') return { etat: 'annoncee', href: '' };

  // Un module que cette version du produit ne connaît pas encore : la fiche est
  // publiée avant la version qui l'apporte. On l'annonce plutôt que d'offrir un
  // bouton qui appellerait une clé inconnue.
  if (!modules.KEYS.includes(fiche.module)) return { etat: 'a-venir', href: '' };

  /*
   * Une option payante que ce client n'a pas prise.
   *
   * C'est l'éditeur qui le dit — « acquise » vient de sa réponse, résolue
   * depuis la clé de cette instance. Le vérifier ici plutôt que de l'afficher
   * seulement : sans cela, le bouton « allumer » donnait gratuitement une
   * application facturée, et personne ne s'en serait aperçu avant de comparer
   * les modules actifs aux factures émises.
   *
   * L'écran renvoie alors vers l'espace client, où l'achat se fait.
   *
   * « pas vrai » plutôt que « faux » : un champ absent doit verrouiller.
   * L'éditeur a servi un catalogue sans « acquise » pendant tout le temps où
   * la boutique a existé, et « === false » ne mordait pas sur « undefined » —
   * chaque option payante s'allumait gratuitement. La règle est donc prise à
   * l'envers : rien ne s'allume sans un « oui » explicite de l'éditeur.
   */
  if (fiche.tarif === 'option' && fiche.acquise !== true) {
    return { etat: 'a-acheter', href: '' };
  }

  return modules.isEnabled(fiche.module)
    ? { etat: 'allumee', href: fiche.href || '' }
    : { etat: 'eteinte', href: '' };
}

/**
 * Le catalogue, rangé par rayon, avec l'état local de chaque fiche.
 *
 * Les rayons viennent de l'éditeur, dans son ordre — du plus demandé au plus
 * spécialisé. Un rayon vide ne s'affiche pas, et un rayon que l'éditeur a
 * ajouté sans que le produit le connaisse s'affiche quand même : mieux vaut un
 * titre brut qu'une fiche disparue.
 */
function ranger(catalogue) {
  const rayons = new Map();
  for (const [cle, titre] of Object.entries(catalogue.rayons)) {
    rayons.set(cle, { cle, titre, fiches: [] });
  }
  for (const fiche of catalogue.applications) {
    if (!rayons.has(fiche.categorie)) {
      rayons.set(fiche.categorie, { cle: fiche.categorie, titre: fiche.categorie, fiches: [] });
    }
    rayons.get(fiche.categorie).fiches.push({ ...fiche, ...etatLocal(fiche) });
  }
  return [...rayons.values()].filter((r) => r.fiches.length > 0);
}

/**
 * Ce que l'écran affiche.
 *
 * « force » saute le répit : c'est le bouton « actualiser », et c'est le seul
 * moyen de voir tout de suite une fiche que l'éditeur vient de corriger.
 */
async function screen({ force = false } = {}) {
  if (!force && cache && Date.now() - cache.at < CACHE_MS) {
    return { ...cache.value, rayons: ranger(cache.catalogue), cached: true };
  }

  /*
   * « force » doit aller jusqu'au bout.
   *
   * screen() sautait bien son propre cache, mais appelait fetchCatalogue()
   * sans rien lui dire — et fetchCatalogue() rendait alors le catalogue en
   * cache, le même, pendant une minute entière. « Actualiser » ne
   * réinterrogeait donc pas l'éditeur : le client qui venait d'acheter
   * cliquait, ne voyait rien changer, et recommençait.
   */
  const catalogue = await fetchCatalogue({ force });
  if (!catalogue.ok) {
    return { ok: false, reason: catalogue.reason, rayons: [], compte: compteVide(),
      espaceClient: '', cached: false };
  }

  const value = { ok: true, compte: compter(catalogue), espaceClient: catalogue.espaceClient };
  cache = { at: Date.now(), value, catalogue };
  return { ...value, rayons: ranger(catalogue), cached: false };
}

function compteVide() {
  return { total: 0, ouvertes: 0, allumees: 0, eteintes: 0, annoncees: 0, aAcheter: 0 };
}

function compter(catalogue) {
  const compte = compteVide();
  for (const fiche of catalogue.applications) {
    compte.total += 1;
    const { etat } = etatLocal(fiche);
    if (etat === 'ouverte') compte.ouvertes += 1;
    else if (etat === 'allumee') compte.allumees += 1;
    else if (etat === 'eteinte') compte.eteintes += 1;
    else if (etat === 'a-acheter') compte.aAcheter += 1;
    else compte.annoncees += 1;
  }
  return compte;
}

/**
 * Allumer une application depuis la boutique.
 *
 * Ce qui s'allume est un module du produit, pas une fiche : la boutique ne
 * téléverse rien et n'installe rien. Elle ne peut donc allumer que ce que le
 * produit porte déjà — la fiche le dit, et on le revérifie ici plutôt que de
 * faire confiance à un formulaire.
 */
async function enable(cle) {
  // Sans répit : c'est une décision d'autorisation, et une réponse d'il y a
  // cinquante secondes refuserait une option achetée entre-temps.
  const catalogue = await fetchCatalogue({ force: true });
  if (!catalogue.ok) return { ok: false, reason: catalogue.reason };

  const fiche = catalogue.applications.find((a) => a.cle === cle);
  if (!fiche) return { ok: false, reason: 'unknown' };
  if (fiche.genre !== 'module') return { ok: false, reason: 'not-a-module' };
  if (!modules.KEYS.includes(fiche.module)) return { ok: false, reason: 'not-in-version' };

  // Le formulaire n'est pas une autorisation : on redemande à l'éditeur, et
  // c'est sa réponse qui décide. Un bouton absent de l'écran ne protège rien
  // contre une requête écrite à la main.
  //
  // Et il faut un « oui » : une réponse qui ne dit rien sur « acquise » — un
  // éditeur plus ancien que cette instance, un catalogue tronqué — ne vaut pas
  // autorisation. Le silence n'achète pas.
  if (fiche.tarif === 'option' && fiche.acquise !== true) {
    return { ok: false, reason: 'not-bought' };
  }

  modules.setEnabled(fiche.module, true);
  // Le réglage est écrit : le compte affiché ne doit pas rester sur l'ancien.
  resetCache();
  logs.record({
    level: 6, channel: 'admin', event: 'store.module.enabled',
    message: `Module « ${fiche.module} » allumé depuis la boutique.`,
  });
  return { ok: true, module: fiche.module, href: fiche.href || '' };
}

/**
 * Récupérer ses achats.
 *
 * Le client vient d'acheter dans son espace client. Sans ce bouton, il
 * reviendrait ici, ne verrait rien de changé — le catalogue a une minute de
 * répit — et ouvrirait une demande d'assistance. On redemande donc sans
 * attendre, et l'on allume ce qu'il a payé.
 *
 * Allumer plutôt que déverrouiller : il a payé pour s'en servir, pas pour
 * chercher un second bouton. Ce qui l'était déjà n'est pas touché, et rien ne
 * s'éteint — résilier une option se fait dans l'espace client, et l'écran le
 * dira à la visite suivante.
 */
async function fetchPurchases() {
  const catalogue = await fetchCatalogue({ force: true });
  if (!catalogue.ok) return { ok: false, reason: catalogue.reason };

  const allumes = [];
  for (const fiche of catalogue.applications) {
    // Un « oui » explicite, là encore : ce bouton allume en série, et c'est
    // le dernier endroit où l'on voudrait qu'un champ manquant passe pour un
    // achat.
    if (fiche.genre !== 'module' || fiche.acquise !== true) continue;
    if (!modules.KEYS.includes(fiche.module)) continue;
    if (modules.isEnabled(fiche.module)) continue;

    // Seules les options achetées : une application comprise dans la licence
    // reste au choix de l'administrateur, qui n'a pas demandé qu'on lui allume
    // la moitié du produit d'un clic.
    if (fiche.tarif !== 'option') continue;

    modules.setEnabled(fiche.module, true);
    allumes.push(fiche.nom);
  }

  resetCache();
  logs.record({
    level: 6, channel: 'admin', event: 'store.purchases.fetched',
    message: allumes.length === 0
      ? 'Achats récupérés : rien de nouveau à allumer.'
      : `Achats récupérés : ${allumes.join(', ')}.`,
  });
  return { ok: true, allumes };
}

module.exports = {
  GENRES, screen, enable, fetchPurchases, resetCache, etatLocal, ranger, fetchCatalogue,
};
