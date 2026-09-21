/* Fragments réutilisés par plusieurs pages. */

const DOMAINS = ['socle', 'espace', 'rh', 'talent', 'sst', 'finance', 'compta', 'achats',
  'projets', 'support', 'ops', 'direction', 'it', 'conformite'];

function frame(ctx, name, label, lazy = true) {
  const { esc, t, media } = ctx;
  const alt = esc(t(`shot.${name}`));
  const file = ctx.shotFile(name);
  // Les dimensions sont celles du fichier, pas une valeur écrite une fois :
  // c'est ce qui empêche la page de sauter quand l'image arrive.
  const dim = (f) => {
    const t = ctx.taille(f);
    return t ? ` width="${t.l}" height="${t.h}"` : '';
  };
  const attrs = (f) => `alt="${alt}"${lazy ? ' loading="lazy" decoding="async"' : ''}${dim(f)}`;
  // La capture sombre n'existe que pour la langue de référence : ailleurs, la
  // capture traduite sert dans les deux thèmes plutôt que de revenir au français.
  const darkFile = `${name}-sombre.png`;
  const paired = media.pairs.includes(name) && file === `${name}.png` && ctx.hasShot(darkFile);
  const img = paired
    ? `<img class="only-light" src="${ctx.shot(file)}" ${attrs(file)} />
            <img class="only-dark" src="${ctx.shot(darkFile)}" ${attrs(darkFile)} />`
    : `<img src="${ctx.shot(file)}" ${attrs(file)} />`;
  return `<div class="shot-frame">
            <div class="shot-bar"><i></i><i></i><i></i><span>${esc(label || t(`shot.${name}`))}</span></div>
            ${img}
          </div>`;
}

/*
 * La fenêtre du héros : une capture qu'on regarde, pas qu'on lit.
 *
 * `frame` ci-dessus rend une image et s'arrête là. Celle-ci empile les écrans
 * de `heroTour` dans un même cadre et les fait se succéder : le visiteur voit
 * en quelques secondes ce qu'est le produit pour un salarié, pour une
 * direction et pour une comptabilité, sans avoir rien à cliquer.
 *
 * Ce qui tient debout sans JavaScript et sans animation :
 *
 *  - le premier écran est visible par CSS, les autres sont derrière lui ;
 *  - les pastilles sont de vrais boutons, utilisables au clavier ;
 *  - le libellé de la barre de titre est celui du premier écran, écrit dans
 *    la page — il n'attend pas un script pour dire quelque chose.
 *
 * Le premier écran se charge sans attendre : c'est la plus grande image de la
 * page, donc celle que le navigateur mesure. Les autres sont différés, et le
 * script ne les fait tourner qu'une fois la page arrivée.
 */
function heroWindow(ctx) {
  const { esc, t, media } = ctx;
  const noms = media.heroTour && media.heroTour.length ? media.heroTour : [media.hero];

  const ecrans = noms.map((nom, i) => {
    const fichier = ctx.shotFile(nom);
    const sombre = `${nom}-sombre.png`;
    const apparie = media.pairs.includes(nom) && fichier === `${nom}.png` && ctx.hasShot(sombre);
    const alt = esc(t(`shot.${nom}`));
    const taille = (f) => {
      const d = ctx.taille(f);
      return d ? ` width="${d.l}" height="${d.h}"` : '';
    };
    // Le premier porte le poids de la page : il arrive sans différé.
    const charge = i === 0 ? '' : ' loading="lazy" decoding="async"';
    /*
     * Chaque écran porte son propre texte de remplacement : il devient
     * visible à son tour, et un « alt » vide à ce moment-là ne dirait plus
     * rien. C'est l'écran qui se cache aux lecteurs d'écran quand il n'est
     * pas montré, pas son image. La jumelle sombre, elle, n'est qu'une
     * seconde peau du même écran : la décrire une deuxième fois ferait dire
     * deux fois la même chose à qui écoute la page.
     */
    const img = apparie
      ? `<img class="only-light" src="${ctx.shot(fichier)}" alt="${alt}"${charge}${taille(fichier)} />
              <img class="only-dark" src="${ctx.shot(sombre)}" alt="" aria-hidden="true"${charge}${taille(sombre)} />`
      : `<img src="${ctx.shot(fichier)}" alt="${alt}"${charge}${taille(fichier)} />`;
    return `<div class="hero-screen${i === 0 ? ' is-on' : ''}" data-screen="${esc(nom)}" data-label="${alt}"${i === 0 ? '' : ' aria-hidden="true"'}>
              ${img}
            </div>`;
  }).join('\n            ');

  const pastilles = noms.map((nom, i) =>
    `<button class="hero-dot${i === 0 ? ' is-on' : ''}" type="button" data-go="${i}" aria-label="${esc(t(`shot.${nom}`))}"${i === 0 ? ' aria-current="true"' : ''}></button>`).join('\n          ');

  return `<div class="hero-window">
        <div class="shot-frame hero-frame">
          <div class="shot-bar">
            <i></i><i></i><i></i>
            <span data-hero-label>${esc(t(`shot.${noms[0]}`))}</span>
          </div>
          <div class="hero-screens">
            ${ecrans}
          </div>
          <span class="hero-sheen" aria-hidden="true"></span>
        </div>
      </div>
      <div class="hero-dots" role="tablist" aria-label="${esc(t('hero.screens'))}">
          ${pastilles}
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

module.exports = { DOMAINS, frame, band, head, pageHero, heroWindow };
