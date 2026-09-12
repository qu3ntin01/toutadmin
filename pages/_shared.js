/* Fragments réutilisés par plusieurs pages. */

const DOMAINS = ['socle', 'espace', 'rh', 'talent', 'sst', 'finance', 'compta', 'achats',
  'projets', 'support', 'ops', 'direction', 'it', 'conformite'];

function frame(ctx, name, label, lazy = true) {
  const { esc, t, media } = ctx;
  const alt = esc(t(`shot.${name}`));
  const attrs = `alt="${alt}"${lazy ? ' loading="lazy" decoding="async"' : ''} width="1440" height="900"`;
  const file = ctx.shotFile(name);
  // La capture sombre n'existe que pour la langue de référence : ailleurs, la
  // capture traduite sert dans les deux thèmes plutôt que de revenir au français.
  const darkFile = `${name}-sombre.png`;
  const paired = media.pairs.includes(name) && file === `${name}.png` && ctx.hasShot(darkFile);
  const img = paired
    ? `<img class="only-light" src="${ctx.shot(file)}" ${attrs} />
            <img class="only-dark" src="${ctx.shot(darkFile)}" ${attrs} />`
    : `<img src="${ctx.shot(file)}" ${attrs} />`;
  return `<div class="shot-frame">
            <div class="shot-bar"><i></i><i></i><i></i><span>${esc(label || t(`shot.${name}`))}</span></div>
            ${img}
          </div>`;
}

function band(ctx) {
  const { t, esc, icon } = ctx;
  return `<section class="section">
      <div class="wrap">
        <div class="band reveal">
          <h2>${esc(t('cta.title'))}</h2>
          <p>${esc(t('cta.body'))}</p>
          <div class="band-cta">
            <a class="btn btn-light" href="${ctx.href('tarifs')}">${esc(t('cta.primary'))}${icon('arrow')}</a>
            <a class="btn btn-onbrand" href="${ctx.href('contact')}">${esc(t('cta.secondary'))}</a>
          </div>
        </div>
      </div>
    </section>`;
}

function head(ctx, eyebrow, title, lede, center = true) {
  const { esc } = ctx;
  return `<div class="section-head${center ? ' center' : ''} reveal">
          ${eyebrow ? `<span class="eyebrow">${esc(eyebrow)}</span>` : ''}
          <h2>${esc(title)}</h2>
          ${lede ? `<p class="lede">${esc(lede)}</p>` : ''}
        </div>`;
}

function pageHero(ctx, eyebrow, title, lede) {
  const { esc } = ctx;
  return `<section class="hero section-tight">
      <div class="hero-bg"></div><div class="hero-grid-lines"></div>
      <div class="wrap center">
        <span class="eyebrow reveal">${esc(eyebrow)}</span>
        <h1 class="reveal">${esc(title)}</h1>
        <p class="lede reveal">${esc(lede)}</p>
      </div>
    </section>`;
}

module.exports = { DOMAINS, frame, band, head, pageHero };
