/* Les formules, et la grille par effectif.
   Un seul endroit : la page Tarifs et l'aperçu de l'accueil doivent annoncer
   le même prix, et deux listes séparées finissent toujours par diverger. */

// Le tarif par salarié, par tranche d'effectif. Tout le reste — le montant
// mensuel, la colonne « par salarié », les exemples — se calcule à partir
// d'ici : deux chiffres qui ne concordent pas sur une page de tarifs, et
// c'est la confiance qui part.
const FREE_UNDER = 5;
const BRACKETS = [
  { upTo: 249, rate: 5 },
  { upTo: 500, rate: 4 },
  { upTo: Infinity, rate: 3 },
];
const SAMPLES = [5, 10, 50, 100, 250, 500, 1000];
const LIFETIME = 10000;

const rateFor = (headcount) => BRACKETS.find((b) => headcount <= b.upTo).rate;
const monthlyFor = (headcount) => headcount * rateFor(headcount);

const euro = (locale, amount) => new Intl.NumberFormat(locale === 'ar' ? 'fr' : locale, {
  style: 'currency', currency: 'EUR', maximumFractionDigits: 0,
}).format(amount);

function plans(ctx) {
  const { t, esc } = ctx;
  return [
    { n: 1, amount: t('pricing.free'), per: '', featured: false },
    { n: 2, amount: euro(ctx.locale.code, BRACKETS[0].rate), per: t('pricing.perEmployeeShort'), featured: true },
    { n: 4, amount: euro(ctx.locale.code, LIFETIME), per: t('pricing.once'), featured: false },
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
            <a class="btn ${plan.featured ? '' : 'btn-ghost'} mt-l" href="${ctx.href('contact')}">${esc(t(plan.n === 4 ? 'pricing.ctaCustom' : 'pricing.cta'))}</a>
          </article>`;
}

function gridRows(ctx) {
  const { t, esc } = ctx;
  const code = ctx.locale.code;
  const free = `<tr>
                <th scope="row">${esc(t('pricing.under', { count: FREE_UNDER }))}</th>
                <td class="c mark-no">—</td>
                <td class="c mark-yes">${esc(t('pricing.free'))}</td>
              </tr>`;
  const rows = SAMPLES.map((headcount) => `<tr>
                <th scope="row">${esc(t('pricing.someEmployees', { count: headcount.toLocaleString(code) }))}</th>
                <td class="c mark-opt">${esc(euro(code, rateFor(headcount)))}</td>
                <td class="c"><strong>${esc(euro(code, monthlyFor(headcount)))}</strong> ${esc(t('pricing.perMonth'))}</td>
              </tr>`).join('\n              ');
  return free + '\n              ' + rows;
}

module.exports = { FREE_UNDER, BRACKETS, SAMPLES, LIFETIME, rateFor, monthlyFor, euro, plans, planCard, gridRows };
