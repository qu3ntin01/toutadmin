/* Les formules.
   Un seul endroit : la page Tarifs et l'aperçu de l'accueil doivent annoncer
   le même prix, et deux listes séparées finissent toujours par diverger. */

// Deux nombres, et rien d'autre. Tout le reste — les cartes, les exemples, la
// bande de l'accueil — se lit à partir d'ici : deux chiffres qui ne concordent
// pas sur une page de tarifs, et c'est la confiance qui part.
//
// Cinq euros par salarié et par mois, au-delà des cinq premiers, offerts à
// tous : douze salariés, trente-cinq euros. On ne paie que ce qui dépasse, et
// il n'y a ni palier ni prix de lancement — une règle qu'on refait de tête.
//
// Jusqu'à cinq salariés, c'est donc gratuit, sans limite de durée : c'est la
// licence gratuite, et ce seuil-là est aussi un plafond technique qu'elle porte.
//
// Ce sont les nombres du panneau éditeur et de l'espace client, qui facturent.
const PRICE_PER_EMPLOYEE = 5;
const FREE_SEATS = 5;

/** Ce que coûte un abonnement par mois, pour cet effectif. */
const monthly = (effectif) => PRICE_PER_EMPLOYEE * Math.max(0, effectif - FREE_SEATS);

/*
 * L'assistance, et ce qu'elle coûte.
 *
 * Elle ne s'étage pas à l'effectif, et c'est délibéré : le besoin d'aide est
 * inversement proportionnel à la taille. L'entreprise de cinquante salariés a
 * quelqu'un qui bricole l'informatique ; celle de huit n'a personne. Un seuil à
 * l'effectif ferait donc payer l'assistance exactement là où on peut le moins
 * la payer, et créerait une marche absurde à expliquer — payante à dix-neuf
 * salariés, offerte à vingt.
 *
 * Elle s'étage au périmètre. Ce qui relève du produit est compris dès le
 * premier euro ; ce qui relève de la machine du client est une prestation, au
 * forfait, et se vend à toute taille. La formule gratuite, elle, a la
 * documentation et la communauté : c'est ce qui permet de l'offrir sans limite
 * de durée plutôt que de la transformer en essai de trente jours.
 *
 * Les prestations sont bornées — un forfait, un taux horaire, un pack d'heures.
 * « Assistance illimitée » est une promesse dont on ne connaît pas la taille.
 */
const REPLY_DAYS = 3;        // jours ouvrés, délai de réponse annoncé
const SETUP_FEE = 250;       // installation assistée, forfait unique
const HOURLY_RATE = 90;      // intervention prioritaire, à l'heure
const HOURS_PACK = 5;        // le pack d'heures, et son prix
const HOURS_PACK_FEE = 400;

const euro = (locale, amount) => new Intl.NumberFormat(locale === 'ar' ? 'fr' : locale, {
  style: 'currency', currency: 'EUR', maximumFractionDigits: 0,
}).format(amount);

function plans(ctx) {
  const { t, esc } = ctx;
  return [
    { n: 1, amount: t('pricing.free'), per: '', featured: false },
    { n: 2, amount: euro(ctx.locale.code, PRICE_PER_EMPLOYEE), per: t('pricing.perSeatShort'), featured: true },
  ].map((plan) => ({
    ...plan,
    name: esc(t(`pricing.p${plan.n}.name`)),
    forWhom: esc(t(`pricing.p${plan.n}.for`)),
    body: esc(t(`pricing.p${plan.n}.body`)),
  }));
}

function planCard(ctx, plan, list = '') {
  const { t, esc } = ctx;
  return `<article class="plan${plan.featured ? ' is-featured' : ''} reveal">
            ${plan.featured ? `<span class="plan-badge">${esc(t('pricing.popular'))}</span>` : ''}
            <div class="plan-name">${plan.name}</div>
            <div class="plan-for">${plan.forWhom}</div>
            <div class="plan-price">
              <span class="plan-amount">${esc(plan.amount)}</span>
              ${plan.per ? `<span class="plan-per">${esc(plan.per)}</span>` : ''}
            </div>
            ${plan.n === 2 ? `<p class="plan-yearly">${esc(t('pricing.example', { n: 12, montant: euro(ctx.locale.code, monthly(12)) }))}</p>` : ''}
            <p>${plan.body}</p>
            ${list}
            ${plan.n === 2
              /* L'abonnement se commande seul, dans l'espace client : le bouton y
                 mène, et le devis à côté, pour qui passe par un service achats. */
              ? `<a class="btn mt-l" href="${ctx.commander()}" rel="noopener">${esc(t('pricing.ctaOrder'))}</a>
            <a class="plan-alt" href="${ctx.commander('devis=1')}" rel="noopener">${esc(t('pricing.ctaQuote'))}</a>`
              /* Le gratuit ne se commande pas : on l'installe, et l'installeur
                 obtient la licence de lui-même. */
              : `<a class="btn btn-ghost mt-l" href="${ctx.href('installation')}">${esc(t('pricing.cta'))}</a>`}
          </article>`;
}

module.exports = {
  PRICE_PER_EMPLOYEE, FREE_SEATS, monthly,
  REPLY_DAYS, SETUP_FEE, HOURLY_RATE, HOURS_PACK, HOURS_PACK_FEE,
  euro, plans, planCard };
