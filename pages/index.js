const { frame, band, head, heroWindow, DOM_ICONS } = require('./_shared');

/*
 * L'animation du héros : l'entreprise, reliée à une seule base.
 *
 * C'est ce que le produit promet de plus important, et une phrase ne le fait
 * pas sentir. Huit espaces autour d'un cœur, des liens courbes, et des données
 * qui circulent dans les deux sens : ce qu'un service saisit, les autres le
 * voient. De temps en temps, un espace s'allume et dit ce qui vient de s'y
 * passer — un congé approuvé, une facture payée —, comme une instance qui vit.
 *
 * Rien n'y est inventé en chiffres : les trois nombres du bas sont ceux de
 * `chiffres.json`, comptés dans le code, et les événements sont des choses que
 * le produit fait, pas des statistiques d'usage.
 *
 * Tout est en SVG et en CSS, avec SMIL pour les trajets : aucune bibliothèque,
 * rien que la politique de contenu n'accepte pas. Pour qui demande moins
 * d'animation, le script fige le dessin et rien ne bouge.
 */
/* Le quatrième élément dit où se pose la bulle de l'espace : vers le centre
   pour les espaces des côtés (« r » à droite, « l » à gauche — « rh » et
   « lh » un peu plus haut, pour les deux du milieu dont la bulle passerait
   sinon sur le cœur), dessous pour celui du haut, dessus pour celui du bas. Posée au-dessus partout, la bulle
   d'un espace du bas recouvrait le libellé de son voisin. */
const NOEUDS = [
  ['rh', 'users', 'anim.ev.1', 'b'], ['paie', 'wallet', 'anim.ev.4', 'l'], ['compta', 'calc', 'anim.ev.8', 'lh'],
  ['crm', 'chart', 'anim.ev.7', 'l'], ['stock', 'cart', 'anim.ev.5', 'a'], ['projets', 'kanban', 'anim.ev.2', 'r'],
  ['support', 'headset', 'anim.ev.3', 'rh'], ['juridique', 'scale', 'anim.ev.6', 'r'],
];
const L = 600;
const H = 440;
const CX = 300;
const CY = 212;

function constellation(ctx) {
  const { t, esc, icon } = ctx;
  const n = ctx.chiffres;
  const r1 = (v) => Math.round(v * 10) / 10;
  const pos = NOEUDS.map((_, i) => {
    const a = (-90 + i * 45) * Math.PI / 180;
    return [r1(CX + 218 * Math.cos(a)), r1(CY + 150 * Math.sin(a))];
  });
  // Un lien par espace, légèrement courbé, d'un côté puis de l'autre.
  const liens = pos.map(([x, y], i) => {
    const mx = (x + CX) / 2;
    const my = (y + CY) / 2;
    const dx = CX - x;
    const dy = CY - y;
    const d = Math.hypot(dx, dy) || 1;
    const k = (i % 2 ? 1 : -1) * 30;
    return `<path id="cx-l${i}" class="cx-link" d="M${x} ${y} Q${r1(mx - dy / d * k)} ${r1(my + dx / d * k)} ${CX} ${CY}" />`;
  }).join('\n          ');
  // Deux paquets par lien : un qui monte vers la base, un qui en redescend.
  const paquets = pos.map((_, i) => {
    const duree = (2.6 + (i * 0.37) % 1.3).toFixed(2);
    const decal = (-(i * 0.71) % 2.6).toFixed(2);
    return `<circle class="cx-pk" r="3.4"><animateMotion dur="${duree}s" begin="${decal}s" repeatCount="indefinite" keyPoints="0;1" keyTimes="0;1" calcMode="linear"><mpath href="#cx-l${i}" /></animateMotion></circle>
          <circle class="cx-pk cx-pk-out" r="2.6"><animateMotion dur="${(Number(duree) + 0.9).toFixed(2)}s" begin="${(Number(decal) - 1.2).toFixed(2)}s" repeatCount="indefinite" keyPoints="1;0" keyTimes="0;1" calcMode="linear"><mpath href="#cx-l${i}" /></animateMotion></circle>`;
  }).join('\n          ');
  const noeuds = NOEUDS.map(([cle, ic], i) => {
    const [x, y] = pos[i];
    return `<g class="cx-node" data-node="${i}" transform="translate(${x} ${y})">
            <circle class="cx-halo" r="40" />
            <circle class="cx-disc" r="29" />
            <svg class="cx-ico" x="-11" y="-11" width="22" height="22" viewBox="0 0 24 24">${icon(ic).replace(/^<svg[^>]*>|<\/svg>$/g, '')}</svg>
            <text class="cx-lbl" y="${y > CY + 60 ? 50 : y < CY - 60 ? -40 : 50}" text-anchor="middle">${esc(t(`anim.node.${cle}`))}</text>
          </g>`;
  }).join('\n          ');
  const evenements = NOEUDS.map(([, , ev, cote], i) => {
    const [x, y] = pos[i];
    return `<li data-node="${i}" data-side="${cote}" data-x="${r1(x / L * 100)}" data-y="${r1(y / H * 100)}">${esc(t(ev))}</li>`;
  }).join('\n          ');

  return `<div class="cx" role="img" aria-label="${esc(t('anim.label'))}">
      <div class="cx-head" aria-hidden="true"><span class="cx-live"></span>${esc(t('anim.label'))}</div>
      <div class="cx-stage" aria-hidden="true">
        <svg class="cx-svg" viewBox="0 0 ${L} ${H}" focusable="false">
          <defs>
            <linearGradient id="cx-core" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0" stop-color="#6366f1" /><stop offset=".55" stop-color="#8b5cf6" /><stop offset="1" stop-color="#d946ef" />
            </linearGradient>
            <radialGradient id="cx-glow"><stop offset="0" stop-color="#a78bfa" stop-opacity=".55" /><stop offset="1" stop-color="#a78bfa" stop-opacity="0" /></radialGradient>
            <filter id="cx-blur" x="-50%" y="-50%" width="200%" height="200%"><feGaussianBlur stdDeviation="2.2" /></filter>
          </defs>
          <circle class="cx-aura" cx="${CX}" cy="${CY}" r="150" fill="url(#cx-glow)" />
          <ellipse class="cx-orbit" cx="${CX}" cy="${CY}" rx="218" ry="150" />
          <circle class="cx-ring cx-ring-a" cx="${CX}" cy="${CY}" r="78" />
          <circle class="cx-ring cx-ring-b" cx="${CX}" cy="${CY}" r="112" />
          ${liens}
          <g class="cx-pks" filter="url(#cx-blur)">
          ${paquets}
          </g>
          <g class="cx-pks-net">
          ${paquets}
          </g>
          <circle class="cx-pulse" cx="${CX}" cy="${CY}" r="56" />
          <circle class="cx-pulse cx-pulse-2" cx="${CX}" cy="${CY}" r="56" />
          <g class="cx-core" transform="translate(${CX} ${CY})">
            <rect x="-48" y="-48" width="96" height="96" rx="28" fill="url(#cx-core)" />
            <rect class="cx-core-sheen" x="-48" y="-48" width="96" height="96" rx="28" />
            <text class="cx-core-mark" y="11" text-anchor="middle">TA</text>
          </g>
          ${noeuds}
        </svg>
        <div class="cx-toasts"></div>
        <ul class="cx-ev" hidden>
          ${evenements}
        </ul>
      </div>
      <div class="cx-stats" aria-hidden="true">
        <div><b data-count="${n.espaces}">${n.espaces}</b><span>${esc(t('stats.spaces'))}</span></div>
        <div><b data-count="${n.langues}">${n.langues}</b><span>${esc(t('stats.languages'))}</span></div>
        <div><b>1</b><span>${esc(t('anim.base'))}</span></div>
      </div>
    </div>`;
}
const { plans, planCard } = require('./_plans');

module.exports = function index(ctx) {
  const { t, esc, icon, media } = ctx;

  /*
   * Ces chiffres se relèvent dans le code, ils ne s'inventent pas — et
   * depuis qu'ils vieillissaient malgré le commentaire qui le demandait, ils
   * ne se recopient plus non plus. `chiffres.js` les compte dans les deux
   * éditions et les écrit dans `chiffres.json`, versionné à côté ; la
   * construction ne lit que ce fichier.
   */
  const n = ctx.chiffres;
  const stats = [
    [n.espaces, 'stats.spaces', 'stats.spacesNote'],
    [n.langues, 'stats.languages', 'stats.languagesNote'],
    [n.tests, 'stats.tests', 'stats.testsNote'],
    [n.modules, 'stats.modules', 'stats.modulesNote'],
    [n.libelles, 'stats.keys', 'stats.keysNote'],
    [n.services, 'stats.deps', 'stats.depsNote'],
  ].map(([value, key, note]) => `<div class="stat reveal">
            <div class="stat-v" data-count="${value}">0</div>
            <div class="stat-k">${esc(t(key))}</div>
            <div class="stat-n">${esc(t(note))}</div>
          </div>`).join('\n          ');

  const icons = ['layers', 'shield', 'clock', 'scale', 'globe', 'lock'];
  const pillars = [1, 2, 3, 4, 5, 6].map((n, i) => `<article class="card reveal">
            <span class="ico">${icon(icons[i])}</span>
            <h3>${esc(t(`pillars.${n}.title`))}</h3>
            <p>${esc(t(`pillars.${n}.body`))}</p>
          </article>`).join('\n          ');

  const tabs = media.tour.map((name, i) =>
    `<button class="tab${i === 0 ? ' is-active' : ''}" type="button" role="tab" aria-selected="${i === 0}" data-tab="${name}">${esc(t(`shot.${name}`))}</button>`).join('\n            ');
  const panes = media.tour.map((name, i) => `<div class="pane${i === 0 ? ' is-active' : ''}" data-pane="${name}" role="tabpanel">
              <div class="pane-text">
                <h3>${esc(t(`shot.${name}`))}</h3>
                <p>${esc(t(`tour.${name}`))}</p>
                <p><a class="link-arrow" href="${ctx.href('fonctionnalites')}#ecrans">${esc(t('tour.all', { count: media.groups.reduce((n, g) => n + g.shots.length, 0) }))}${icon('arrow')}</a></p>
              </div>
              ${frame(ctx, name, null, i !== 0)}
            </div>`).join('\n            ');

  const domainCards = require('./_shared').DOMAINS.map((key) => {
    const items = t(`dom.${key}.items`);
    return `<a class="card reveal" href="${ctx.href('fonctionnalites')}#${key}">
            <span class="ico">${icon(DOM_ICONS[key])}</span>
            <h3>${esc(t(`dom.${key}.title`))}</h3>
            <p>${esc(t(`dom.${key}.lede`))}</p>
            <p class="stat-n">${items.length}</p>
          </a>`;
  }).join('\n          ');

  const secCards = [1, 2, 4].map((n, i) => `<article class="card reveal">
            <span class="ico">${icon(['lock', 'shield', 'life'][i])}</span>
            <h3>${esc(t(`security.${n}.title`))}</h3>
            <p>${esc(t(`security.${n}.body`))}</p>
          </article>`).join('\n          ');

  const planCards = plans(ctx).map((plan) => planCard(ctx, plan)).join('\n          ');

  const faq = [1, 2, 3, 4, 5, 6].map((n) => `<details class="reveal">
            <summary>${esc(t(`faq.q${n}`))}${icon('plus')}</summary>
            <div class="answer">${esc(t(`faq.a${n}`))}</div>
          </details>`).join('\n          ');

  const title = t('hero.title').split('\n');

  return {
    title: `${t('site.name')} — ${t('site.tagline')}`,
    description: t('hero.lede', { espaces: n.espaces }),
    body: `
    <section class="hero hero-home">
      <div class="hero-glow" aria-hidden="true"></div>
      <div class="wrap hero-in stagger-hero">
        <div class="hero-copy">
          <span class="eyebrow reveal"><span class="pulse-dot" aria-hidden="true"></span>${esc(t('hero.eyebrow'))}</span>
          <h1 class="reveal">${esc(title[0])}<br /><span class="g">${esc(title[1] || '')}</span></h1>
          <p class="lede reveal">${esc(t('hero.lede', { espaces: n.espaces }))}</p>
          <div class="hero-cta reveal">
            <a class="btn btn-lg" href="${ctx.href('tarifs')}">${esc(t('hero.ctaPrimary'))}${icon('arrow')}</a>
            <a class="btn btn-lg btn-glass" href="${ctx.href('fonctionnalites')}">${esc(t('hero.ctaSecondary'))}</a>
          </div>
          <p class="hero-note reveal">${esc(t('hero.note'))}</p>
          <div class="hero-badges reveal">
            <span class="chip"><span class="dot"></span>${esc(t('hero.badge1'))}</span>
            <span class="chip"><span class="dot"></span>${esc(t('hero.badge2'))}</span>
            <span class="chip"><span class="dot"></span>${esc(t('hero.badge3'))}</span>
          </div>
        </div>
        <div class="hero-visual reveal">
          ${constellation(ctx)}
        </div>
      </div>
    </section>

    <section class="section showcase">
      <div class="wrap">
        ${head(ctx, t('hero.screens'), t('showcase.title'), t('showcase.lede'))}
        <div class="hero-shot reveal" data-parallax data-hero>
          ${heroWindow(ctx)}
        </div>
      </div>
    </section>

    <section class="section">
      <div class="wrap">
        ${head(ctx, null, t('stats.title'), null)}
        <div class="stats stagger">
          ${stats}
        </div>
      </div>
    </section>

    <section class="section tinted">
      <div class="wrap">
        ${head(ctx, t('nav.product'), t('pillars.title'), t('pillars.lede'))}
        <div class="grid g3 stagger">
          ${pillars}
        </div>
      </div>
    </section>

    <section class="section">
      <div class="wrap">
        ${head(ctx, t('nav.screens'), t('tour.title'), t('tour.lede'))}
        <div data-tabs>
          <div class="tabs reveal" role="tablist">
            ${tabs}
          </div>
          <div class="panes">
            ${panes}
          </div>
        </div>
      </div>
    </section>

    <section class="section tinted">
      <div class="wrap">
        ${head(ctx, t('nav.features'), t('features.title'), t('features.intro'))}
        <div class="grid g4 stagger">
          ${domainCards}
        </div>
        <p class="center reveal mt-l"><a class="btn btn-ghost" href="${ctx.href('fonctionnalites')}">${esc(t('features.seeAll'))}${icon('arrow')}</a></p>
      </div>
    </section>

    <section class="section">
      <div class="wrap">
        ${head(ctx, t('nav.security'), t('security.title'), t('security.lede'))}
        <div class="grid g3 stagger">
          ${secCards}
        </div>
        <p class="center reveal mt-l"><a class="link-arrow" href="${ctx.href('securite')}">${esc(t('nav.security'))}${icon('arrow')}</a></p>
      </div>
    </section>

    <section class="section tinted">
      <div class="wrap">
        ${head(ctx, t('nav.pricing'), t('pricing.title'), t('pricing.lede'))}
        <p class="center reveal"><span class="eyebrow">${icon('users')}${esc(t('pricing.flat'))}</span></p>
        <div class="plans stagger mt-l">
          ${planCards}
        </div>
        <p class="center stat-n reveal mt-m">${esc(t('pricing.vat'))}</p>
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
