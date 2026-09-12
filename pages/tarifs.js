const { band, pageHero, head } = require('./_shared');
const { plans, planCard, gridRows } = require('./_plans');

module.exports = function pricing(ctx) {
  const { t, esc, icon } = ctx;

  const perks = {
    1: ['pricing.f.allSpaces', 'pricing.f.languages', 'pricing.f.security', 'pricing.f.updates', 'pricing.f.saas'],
    2: ['pricing.f.allSpaces', 'pricing.f.local', 'pricing.f.backupOff', 'pricing.f.api', 'pricing.f.supportMail'],
    3: ['pricing.f.allSpaces', 'pricing.f.local', 'pricing.f.supportPrio', 'pricing.f.migration', 'pricing.f.multisite'],
    4: ['pricing.f.allSpaces', 'pricing.f.updates', 'pricing.f.local', 'pricing.f.multisite', 'pricing.f.api'],
  };

  const cards = plans(ctx).map((plan) => {
    const list = `<ul class="plan-list">
              ${perks[plan.n].map((key) => `<li>${icon('check')}<span>${esc(t(key))}</span></li>`).join('\n              ')}
            </ul>`;
    return planCard(ctx, plan, list);
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
    ['pricing.f.saas', yes, yes, yes, opt],
    ['pricing.f.local', no, opt, opt, yes],
    ['pricing.f.backupOff', no, yes, yes, yes],
    ['pricing.f.migration', no, no, yes, opt],
    ['pricing.f.multisite', no, no, yes, yes],
    ['pricing.f.sla', no, no, yes, opt],
    ['pricing.f.support', `<span class="mark-no">${esc(t('pricing.f.supportCommunity'))}</span>`,
      `<span class="mark-opt">${esc(t('pricing.f.supportMail'))}</span>`,
      `<span class="mark-opt">${esc(t('pricing.f.supportPrio'))}</span>`,
      `<span class="mark-opt">${esc(t('pricing.f.supportMail'))}</span>`],
  ].map(([key, a, b, c, d]) =>
    `<tr><th scope="row">${esc(t(key))}</th><td class="c">${a}</td><td class="c">${b}</td><td class="c">${c}</td><td class="c">${d}</td></tr>`).join('\n              ');

  const faq = [1, 9, 10, 6, 7, 4].map((n) => `<details class="reveal">
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
        <p class="center reveal"><span class="eyebrow">${icon('users')}${esc(t('pricing.perEmployee'))}</span></p>
        <div class="plans stagger mt-l">
          ${cards}
        </div>
        <p class="center stat-n mt-m reveal">${esc(t('pricing.vat'))}</p>
      </div>
    </section>

    <section class="section tinted">
      <div class="wrap">
        ${head(ctx, null, t('pricing.gridTitle'), t('pricing.gridLede'))}
        <div class="table-wrap reveal grid-table">
          <table>
            <thead>
              <tr>
                <th scope="col">${esc(t('pricing.col.headcount'))}</th>
                <th scope="col">${esc(t('pricing.col.monthly'))}</th>
                <th scope="col">${esc(t('pricing.col.perEmployee'))}</th>
              </tr>
            </thead>
            <tbody>
              ${gridRows(ctx)}
            </tbody>
          </table>
        </div>
        <div class="grid g2 mt-l">
          <div class="note reveal">
            <h3>${esc(t('pricing.argTitle'))}</h3>
            <p>${esc(t('pricing.argBody'))}</p>
          </div>
          <div class="note reveal">
            <h3>${esc(t('pricing.lifetimeTitle'))}</h3>
            <p>${esc(t('pricing.lifetimeBody'))}</p>
          </div>
        </div>
      </div>
    </section>

    <section class="section">
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

    <section class="section tinted">
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
