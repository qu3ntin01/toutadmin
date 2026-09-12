const { DOMAINS, frame, band, head, pageHero } = require('./_shared');

module.exports = function features(ctx) {
  const { t, esc, icon, media } = ctx;

  // Les écrans ferment la page : la liste des domaines y mène comme aux autres.
  const index = DOMAINS.map((key) => `<a href="#${key}">${esc(t(`dom.${key}.title`))}</a>`).join('\n          ')
    + `\n          <a href="#ecrans">${esc(t('nav.screens'))}</a>`;

  // Les captures, par famille d'écrans. Elles viennent d'une instance réelle :
  // les montrer ici, à la suite des fonctionnalités, évite au visiteur d'aller
  // les chercher ailleurs pour vérifier que ce qui est décrit existe vraiment.
  const galleries = media.groups.map((group) => {
    const figures = group.shots.map((name) => `<figure class="reveal">
              ${frame(ctx, name)}
              <figcaption>${esc(t(`shot.${name}`))}</figcaption>
            </figure>`).join('\n            ');
    return `<div class="mt-l">
            ${head(ctx, null, t(group.key), null, false)}
            <div class="gal stagger">
            ${figures}
            </div>
          </div>`;
  }).join('\n          ');

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

    <section class="section tinted" id="ecrans">
      <div class="wrap">
        ${head(ctx, t('nav.screens'), t('screens.title'), t('screens.lede'))}
          ${galleries}
      </div>
    </section>

    <div class="lightbox" role="dialog" aria-modal="true">
      <figure>
        <img src="${ctx.shot(media.hero + '.png')}" alt="" />
        <figcaption></figcaption>
      </figure>
      <button class="lb-close" type="button" aria-label="${esc(t('screens.close'))}">${icon('close')}</button>
      <button class="lb-nav lb-prev" type="button" aria-label="${esc(t('screens.prev'))}">${icon('left')}</button>
      <button class="lb-nav lb-next" type="button" aria-label="${esc(t('screens.next'))}">${icon('right')}</button>
    </div>

    ${band(ctx)}
`,
  };
};
