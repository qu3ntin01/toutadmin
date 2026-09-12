/* Les quatre formules, et la grille par effectif.
   Un seul endroit : la page Tarifs et l'aperçu de l'accueil doivent annoncer
   le même prix, et deux listes séparées finissent toujours par diverger. */

// Effectif → prix mensuel. Le prix par salarié s'en déduit plutôt que d'être
// saisi : deux chiffres qui ne concordent pas sur une page de tarifs, et c'est
// la confiance qui part.
const GRID = [
  { headcount: 5, monthly: 25 },
  { headcount: 10, monthly: 50 },
  { headcount: 50, monthly: 250 },
  { headcount: 100, monthly: 500 },
  { headcount: 250, monthly: 1000 },
  { headcount: null, monthly: 1500 },
];

const FREE_UNDER = 5;
const LIFETIME = 10000;

const euro = (locale, amount) => new Intl.NumberFormat(locale === 'ar' ? 'fr' : locale, {
  style: 'currency', currency: 'EUR', maximumFractionDigits: 0,
}).format(amount);

function plans(ctx) {
  const { t, esc } = ctx;
  return [
    { n: 1, amount: t('pricing.free'), per: '', featured: false },
    { n: 2, amount: euro(ctx.locale.code, 5), per: t('pricing.perEmployeeShort'), featured: true },
    { n: 3, amount: euro(ctx.locale.code, 1500), per: t('pricing.perMonth'), featured: false },
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
                <td class="c mark-yes">${esc(t('pricing.free'))}</td>
                <td class="c mark-no">—</td>
              </tr>`;
  const rows = GRID.map((row) => {
    const label = row.headcount === null
      ? t('pricing.unlimited')
      : t('pricing.upTo', { count: row.headcount });
    const perHead = row.headcount === null ? '—' : euro(code, row.monthly / row.headcount);
    return `<tr>
                <th scope="row">${esc(label)}</th>
                <td class="c"><strong>${esc(euro(code, row.monthly))}</strong> ${esc(t('pricing.perMonth'))}</td>
                <td class="c ${row.headcount === null ? 'mark-no' : 'mark-opt'}">${esc(perHead)}</td>
              </tr>`;
  }).join('\n              ');
  return free + '\n              ' + rows;
}

module.exports = { GRID, FREE_UNDER, LIFETIME, euro, plans, planCard, gridRows };
