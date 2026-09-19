/* Les formules.
   Un seul endroit : la page Tarifs et l'aperçu de l'accueil doivent annoncer
   le même prix, et deux listes séparées finissent toujours par diverger. */

// Cinq nombres, et rien d'autre. Tout le reste — les cartes, la bande de
// lancement, les exemples — se lit à partir d'ici : deux chiffres qui ne
// concordent pas sur une page de tarifs, et c'est la confiance qui part.
//
// Un forfait : quinze euros par mois, quel que soit l'effectif. C'était cinq
// euros par salarié au-delà de cinq offerts — un prix qu'il fallait calculer
// pour le connaître, et qui montait le jour où l'on embauchait. Une punition à
// l'embauche, dans un logiciel qui sert justement à gérer du personnel.
//
// Les cent premiers clients paient dix euros leur première année. Ce n'est pas
// un rabais au volume déguisé : c'est une date d'entrée, la même pour tout le
// monde, et elle expire.
//
// Sous cinq salariés, c'est gratuit, sans limite de durée. Cette limite-là est
// un plafond technique que la licence porte, pas un tarif.
const PRICE = 15;
const LAUNCH_PRICE = 10;
const LAUNCH_CLIENTS = 100;
const LAUNCH_MONTHS = 12;
const FREE_SEATS = 5;

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
    { n: 2, amount: euro(ctx.locale.code, PRICE), per: t('pricing.perMonthShort'), featured: true },
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
            <p>${plan.body}</p>
            ${list}
            <a class="btn ${plan.featured ? '' : 'btn-ghost'} mt-l" href="${ctx.href('contact')}">${esc(t('pricing.cta'))}</a>
          </article>`;
}

module.exports = {
  PRICE, LAUNCH_PRICE, LAUNCH_CLIENTS, LAUNCH_MONTHS, FREE_SEATS,
  REPLY_DAYS, SETUP_FEE, HOURLY_RATE, HOURS_PACK, HOURS_PACK_FEE,
  euro, plans, planCard };
