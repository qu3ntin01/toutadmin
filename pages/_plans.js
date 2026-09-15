/* Les formules, et la grille par effectif.
   Un seul endroit : la page Tarifs et l'aperçu de l'accueil doivent annoncer
   le même prix, et deux listes séparées finissent toujours par diverger. */

// Deux nombres, et rien d'autre. Tout le reste — le montant mensuel, la colonne
// « par salarié », les exemples, le simulateur — se calcule à partir d'ici :
// deux chiffres qui ne concordent pas sur une page de tarifs, et c'est la
// confiance qui part.
//
// Les cinq premiers salariés ne sont jamais facturés. Pas « gratuit en dessous
// de cinq puis payant à partir du sixième pour tout le monde » — les cinq
// restent offerts quel que soit l'effectif. Une entreprise de six salariés paie
// donc cinq euros, pas trente : la courbe n'a pas de marche.
const FREE_SEATS = 5;
const RATE = 5;
const SAMPLES = [5, 6, 10, 25, 50, 100, 250, 1000];

/** Les salariés effectivement facturés. */
const billableFor = (headcount) => Math.max(0, headcount - FREE_SEATS);
const monthlyFor = (headcount) => billableFor(headcount) * RATE;

/**
 * Le prix moyen par salarié, celui qu'on affiche à côté du montant. Il baisse
 * tout seul quand l'effectif monte, puisque les cinq offerts pèsent de moins en
 * moins lourd — c'est la dégressivité, sans grille à maintenir.
 */
const rateFor = (headcount) => (headcount > 0 ? monthlyFor(headcount) / headcount : 0);

const euro = (locale, amount) => new Intl.NumberFormat(locale === 'ar' ? 'fr' : locale, {
  style: 'currency', currency: 'EUR', maximumFractionDigits: 0,
}).format(amount);

function plans(ctx) {
  const { t, esc } = ctx;
  return [
    { n: 1, amount: t('pricing.free'), per: '', featured: false },
    { n: 2, amount: euro(ctx.locale.code, RATE), per: t('pricing.perEmployeeShort'), featured: true },
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

function gridRows(ctx) {
  const { t, esc } = ctx;
  const code = ctx.locale.code;
  // Le prix moyen par salarié se donne au centime : à six salariés il vaut
  // 0,83 €, et l'arrondir à l'euro afficherait « 1 € » à côté de « 5 € »,
  // ce qui se lit comme une erreur.
  const moyen = (headcount) => new Intl.NumberFormat(code === 'ar' ? 'fr' : code, {
    style: 'currency', currency: 'EUR', maximumFractionDigits: 2,
  }).format(rateFor(headcount));

  return SAMPLES.map((headcount) => {
    const gratuit = monthlyFor(headcount) === 0;
    return `<tr>
                <th scope="row">${esc(t('pricing.someEmployees', { count: headcount.toLocaleString(code) }))}</th>
                <td class="c ${gratuit ? 'mark-no' : 'mark-opt'}">${gratuit ? '—' : esc(moyen(headcount))}</td>
                <td class="c">${gratuit
                  ? `<span class="mark-yes">${esc(t('pricing.free'))}</span>`
                  : `<strong>${esc(euro(code, monthlyFor(headcount)))}</strong> ${esc(t('pricing.perMonth'))}`}</td>
              </tr>`;
  }).join('\n              ');
}

module.exports = { FREE_SEATS, RATE, SAMPLES, billableFor, rateFor, monthlyFor, euro, plans, planCard, gridRows };
