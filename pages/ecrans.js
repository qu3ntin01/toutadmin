const { frame, band, pageHero, head } = require('./_shared');

module.exports = function screens(ctx) {
  const { t, esc, icon, media } = ctx;

  const groups = media.groups.map((group) => {
    const figures = group.shots.map((name) => `<figure class="reveal">
              ${frame(ctx, name)}
              <figcaption>${esc(t(`shot.${name}`))}</figcaption>
            </figure>`).join('\n            ');
    return `<section class="section">
          <div class="wrap">
            ${head(ctx, null, t(group.key), null, false)}
            <div class="gal stagger">
            ${figures}
            </div>
          </div>
        </section>`;
  }).join('\n        ');

  return {
    title: `${t('nav.screens')} — ${t('site.name')}`,
    description: t('screens.lede'),
    body: `
    ${pageHero(ctx, t('nav.screens'), t('screens.title'), t('screens.lede'))}

    ${groups}

    ${band(ctx)}

    <div class="lightbox" role="dialog" aria-modal="true">
      <figure>
        <img src="${ctx.shot(media.hero + '.png')}" alt="" />
        <figcaption></figcaption>
      </figure>
      <button class="lb-close" type="button" aria-label="${esc(t('screens.close'))}">${icon('close')}</button>
      <button class="lb-nav lb-prev" type="button" aria-label="${esc(t('screens.prev'))}">${icon('left')}</button>
      <button class="lb-nav lb-next" type="button" aria-label="${esc(t('screens.next'))}">${icon('right')}</button>
    </div>
`,
  };
};
