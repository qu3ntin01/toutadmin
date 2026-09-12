const { DOMAINS, frame, band, pageHero } = require('./_shared');

module.exports = function features(ctx) {
  const { t, esc, icon, media } = ctx;

  const index = DOMAINS.map((key) => `<a href="#${key}">${esc(t(`dom.${key}.title`))}</a>`).join('\n          ');

  const sections = DOMAINS.map((key, i) => {
    const items = t(`dom.${key}.items`).map((line) =>
      `<li>${icon('check')}<span>${esc(line)}</span></li>`).join('\n              ');
    return `<section class="dom${i % 2 ? ' rev' : ''}" id="${key}">
          <div class="dom-body">
            <div class="reveal">
              <div class="dom-head">
                <span class="dom-num">${String(i + 1).padStart(2, '0')}</span>
                <h2>${esc(t(`dom.${key}.title`))}</h2>
              </div>
              <p class="lede">${esc(t(`dom.${key}.lede`))}</p>
              <ul class="feat-list">
              ${items}
              </ul>
            </div>
            <div class="dom-shot reveal">
              ${frame(ctx, media.domains[key])}
            </div>
          </div>
        </section>`;
  }).join('\n        ');

  const count = DOMAINS.reduce((n, key) => n + t(`dom.${key}.items`).length, 0);

  return {
    title: `${t('nav.features')} — ${t('site.name')}`,
    description: t('features.lede'),
    body: `
    ${pageHero(ctx, t('nav.features'), t('features.title'), t('features.lede'))}

    <section class="section-tight">
      <div class="wrap">
        <div class="dom-index reveal">
          ${index}
        </div>
        <p class="stat-n mt-m reveal">${count} · ${esc(t('features.legend'))}</p>
      </div>
    </section>

    <div class="wrap">
        ${sections}
    </div>

    <section class="section">
      <div class="wrap">
        <div class="note reveal mx-auto">
          <h3>${esc(t('features.ctaTitle'))}</h3>
          <p>${esc(t('features.ctaBody'))}</p>
          <p class="mt-m"><a class="link-arrow" href="${ctx.href('contact')}">${esc(t('contact.write'))}${icon('arrow')}</a></p>
        </div>
      </div>
    </section>

    ${band(ctx)}
`,
  };
};
