const { DOMAINS, frame, band, head, pageHero } = require('./_shared');

/* Une seule page pour ce que le produit fait et à quoi il ressemble, en deux
   onglets : la description d'un côté, les écrans de l'autre. Les deux parlent
   des mêmes choses — les séparer en deux pages obligeait le visiteur à faire
   l'aller-retour pour vérifier que ce qui est décrit existe vraiment. */
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
  const shots = media.groups.reduce((n, group) => n + group.shots.length, 0);

  // Les captures, par famille d'écrans. Toutes viennent d'une instance réelle.
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

  const tab = (name, label, first) =>
    `<button class="tab${first ? ' is-active' : ''}" type="button" role="tab" aria-selected="${first}" data-tab="${name}">${esc(label)}</button>`;

  return {
    title: `${t('nav.features')} — ${t('site.name')}`,
    description: t('features.lede'),
    body: `
    ${pageHero(ctx, t('nav.features'), t('features.title'), t('features.lede'))}

    <div data-tabs>
      <section class="section-tight tabs-bar">
        <div class="wrap">
          <div class="tabs center reveal" role="tablist" aria-label="${esc(t('nav.features'))}">
            ${tab('fonctionnalites', t('nav.features'), true)}
            ${tab('ecrans', t('nav.screens'), false)}
          </div>
        </div>
      </section>

      <div class="sheet is-active" data-pane="fonctionnalites" id="fonctionnalites" role="tabpanel">
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
      </div>

      <div class="sheet" data-pane="ecrans" id="ecrans" role="tabpanel">
        <section class="section-tight">
          <div class="wrap">
            ${head(ctx, null, t('screens.title'), t('screens.lede'))}
            <p class="stat-n center reveal">${shots} · ${esc(t('nav.screens'))}</p>
          ${galleries}
          </div>
        </section>
      </div>
    </div>

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
