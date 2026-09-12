const { band, pageHero, head } = require('./_shared');

module.exports = function pricing(ctx) {
  const { t, esc, icon } = ctx;

  const perks = {
    1: ['pricing.f.allSpaces', 'pricing.f.languages', 'pricing.f.security', 'pricing.f.updates', 'pricing.f.saas'],
    2: ['pricing.f.allSpaces', 'pricing.f.local', 'pricing.f.backupOff', 'pricing.f.api', 'pricing.f.supportMail'],
    3: ['pricing.f.allSpaces', 'pricing.f.local', 'pricing.f.supportPrio', 'pricing.f.migration', 'pricing.f.api'],
    4: ['pricing.f.multisite', 'pricing.f.sla', 'pricing.f.supportDedicated', 'pricing.f.migration', 'pricing.f.local'],
  };

  const plans = [1, 2, 3, 4].map((n) => {
    const price = ['0', '50', '100', null][n - 1];
    const featured = n === 2;
    const list = perks[n].map((key) => `<li>${icon('check')}<span>${esc(t(key))}</span></li>`).join('\n              ');
    return `<article class="plan${featured ? ' is-featured' : ''} reveal">
            ${featured ? `<span class="plan-badge">${esc(t('pricing.popular'))}</span>` : ''}
            <div class="plan-name">${esc(t(`pricing.p${n}.name`))}</div>
            <div class="plan-for">${esc(t(`pricing.p${n}.for`))}</div>
            <div class="plan-price">
              ${price === null
                ? `<span class="plan-amount">${esc(t('pricing.custom'))}</span>`
                : (price === '0'
                  ? `<span class="plan-amount">${esc(t('pricing.free'))}</span>`
                  : `<span class="plan-amount">${price} €</span><span class="plan-per">${esc(t('pricing.perMonth'))}</span>`)}
            </div>
            <p>${esc(t(`pricing.p${n}.body`))}</p>
            <ul class="plan-list">
              ${list}
            </ul>
            <a class="btn ${featured ? '' : 'btn-ghost'} mt-l" href="${ctx.href('contact')}">${esc(t(n === 4 ? 'pricing.ctaCustom' : 'pricing.cta'))}</a>
          </article>`;
  }).join('\n          ');

  const yes = `<span class="mark-yes">${esc(t('pricing.yes'))}</span>`;
  const no = `<span class="mark-no">${esc(t('pricing.no'))}</span>`;
  const opt = `<span class="mark-opt">${esc(t('pricing.option'))}</span>`;
  const rows = [
    ['pricing.f.allSpaces', yes, yes, yes, yes],
    ['pricing.f.languages', yes, yes, yes, yes],
    ['pricing.f.security', yes, yes, yes, yes],
    ['pricing.f.updates', yes, yes, yes, yes],
    ['pricing.f.api', yes, yes, yes, yes],
    ['pricing.f.saas', yes, yes, yes, yes],
    ['pricing.f.local', no, opt, opt, yes],
    ['pricing.f.backupOff', no, yes, yes, yes],
    ['pricing.f.migration', no, no, yes, yes],
    ['pricing.f.multisite', no, no, no, yes],
    ['pricing.f.sla', no, no, no, yes],
    ['pricing.f.support', `<span class="mark-no">${esc(t('pricing.f.supportCommunity'))}</span>`,
      `<span class="mark-opt">${esc(t('pricing.f.supportMail'))}</span>`,
      `<span class="mark-opt">${esc(t('pricing.f.supportPrio'))}</span>`,
      `<span class="mark-opt">${esc(t('pricing.f.supportDedicated'))}</span>`],
  ].map(([key, a, b, c, d]) =>
    `<tr><th scope="row">${esc(t(key))}</th><td class="c">${a}</td><td class="c">${b}</td><td class="c">${c}</td><td class="c">${d}</td></tr>`).join('\n              ');

  const faq = [1, 6, 7, 4, 5].map((n) => `<details class="reveal">
            <summary>${esc(t(`faq.q${n}`))}${icon('plus')}</summary>
            <div class="answer">${esc(t(`faq.a${n}`))}</div>
          </details>`).join('\n          ');

  return {
    title: `${t('nav.pricing')} — ${t('site.name')}`,
    description: t('pricing.lede'),
    body: `
    ${pageHero(ctx, t('nav.pricing'), t('pricing.title'), t('pricing.lede'))}

    <section class="section-tight">
      <div class="wrap">
        <div class="plans stagger">
          ${plans}
        </div>
        <p class="center stat-n mt-m reveal">${esc(t('pricing.vat'))}</p>
      </div>
    </section>

    <section class="section tinted">
      <div class="wrap">
        ${head(ctx, null, t('pricing.tableTitle'), null)}
        <div class="table-wrap reveal">
          <table>
            <thead>
              <tr>
                <th scope="col">${esc(t('pricing.col.feature'))}</th>
                <th scope="col">${esc(t('pricing.p1.name'))}</th>
                <th scope="col">${esc(t('pricing.p2.name'))}</th>
                <th scope="col">${esc(t('pricing.p3.name'))}</th>
                <th scope="col">${esc(t('pricing.p4.name'))}</th>
              </tr>
            </thead>
            <tbody>
              ${rows}
            </tbody>
          </table>
        </div>
        <div class="note reveal mt-l">
          <h3>${esc(t('pricing.countTitle'))}</h3>
          <p>${esc(t('pricing.countBody'))}</p>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="wrap">
        ${head(ctx, null, t('faq.title'), null)}
        <div class="faq mx-auto stagger">
          ${faq}
        </div>
      </div>
    </section>

    ${band(ctx)}
`,
  };
};
