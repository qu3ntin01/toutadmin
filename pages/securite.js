const { band, pageHero, head } = require('./_shared');

module.exports = function security(ctx) {
  const { t, esc, icon } = ctx;
  const icons = ['lock', 'shield', 'users', 'life', 'box', 'code'];
  const cards = [1, 2, 3, 4, 5, 6].map((n, i) => `<article class="card reveal">
            <span class="ico">${icon(icons[i])}</span>
            <h3>${esc(t(`security.${n}.title`))}</h3>
            <p>${esc(t(`security.${n}.body`))}</p>
          </article>`).join('\n          ');

  return {
    title: `${t('nav.security')} — ${t('site.name')}`,
    description: t('security.lede'),
    body: `
    ${pageHero(ctx, t('nav.security'), t('security.title'), t('security.lede'))}

    <section class="section-tight">
      <div class="wrap">
        <div class="grid g3 stagger">
          ${cards}
        </div>
      </div>
    </section>

    <section class="section tinted">
      <div class="wrap">
        <div class="grid g2">
          <div class="reveal">
            <span class="eyebrow">${esc(t('hero.badge2'))}</span>
            <h2 class="mt-m">${esc(t('security.hostTitle'))}</h2>
            <p class="lede mt-m">${esc(t('security.hostBody'))}</p>
          </div>
          <div class="note reveal">
            <h3>${esc(t('security.limitsTitle'))}</h3>
            <p>${esc(t('security.limitsBody'))}</p>
          </div>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="wrap">
        ${head(ctx, null, t('faq.title'), null)}
        <div class="faq mx-auto stagger">
          <details class="reveal"><summary>${esc(t('faq.q2'))}${icon('plus')}</summary><div class="answer">${esc(t('faq.a2'))}</div></details>
          <details class="reveal"><summary>${esc(t('faq.q3'))}${icon('plus')}</summary><div class="answer">${esc(t('faq.a3'))}</div></details>
          <details class="reveal"><summary>${esc(t('faq.q8'))}${icon('plus')}</summary><div class="answer">${esc(t('faq.a8'))}</div></details>
        </div>
      </div>
    </section>

    ${band(ctx)}
`,
  };
};
