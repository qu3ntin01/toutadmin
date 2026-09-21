const { frame, band, head, heroWindow } = require('./_shared');
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
                <p><a class="link-arrow" href="${ctx.href('fonctionnalites')}#ecrans">${esc(t('tour.all'))}${icon('arrow')}</a></p>
              </div>
              ${frame(ctx, name, null, i !== 0)}
            </div>`).join('\n            ');

  const domainCards = require('./_shared').DOMAINS.map((key, i) => {
    const items = t(`dom.${key}.items`);
    return `<a class="card reveal" href="${ctx.href('fonctionnalites')}#${key}">
            <span class="ico">${icon(['layers', 'users', 'briefcase', 'spark', 'life', 'chart', 'chart', 'box', 'briefcase', 'life', 'clock', 'building', 'code', 'scale'][i])}</span>
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

  const marquee = require('./_shared').DOMAINS
    .map((k) => `<span>${esc(t(`dom.${k}.title`))}</span>`).join('');

  const title = t('hero.title').split('\n');

  return {
    title: `${t('site.name')} — ${t('site.tagline')}`,
    description: t('hero.lede', { espaces: n.espaces }),
    body: `
    <section class="hero">
      <div class="hero-bg"></div><div class="hero-grid-lines"></div>
      <div class="wrap hero-in stagger-hero">
        <div>
          <span class="eyebrow reveal">${icon('spark')}${esc(t('hero.eyebrow'))}</span>
          <h1 class="reveal">${esc(title[0])}<br /><span class="g">${esc(title[1] || '')}</span></h1>
          <p class="lede reveal">${esc(t('hero.lede', { espaces: n.espaces }))}</p>
          <div class="hero-cta reveal">
            <a class="btn" href="${ctx.href('tarifs')}">${esc(t('hero.ctaPrimary'))}${icon('arrow')}</a>
            <a class="btn btn-ghost" href="${ctx.href('fonctionnalites')}">${esc(t('hero.ctaSecondary'))}</a>
          </div>
          <p class="hero-note reveal">${esc(t('hero.note'))}</p>
          <div class="hero-badges reveal">
            <span class="chip"><span class="dot"></span>${esc(t('hero.badge1'))}</span>
            <span class="chip"><span class="dot"></span>${esc(t('hero.badge2'))}</span>
            <span class="chip"><span class="dot"></span>${esc(t('hero.badge3'))}</span>
          </div>
        </div>
        <div class="hero-shot reveal" data-parallax data-hero>
          ${heroWindow(ctx)}
          <div class="float float-1">
            <div class="float-k">${esc(t('stats.spaces'))}</div>
            <div class="float-v">${n.espaces}</div>
          </div>
          <div class="float float-2">
            <div class="float-k">${esc(t('stats.languages'))}</div>
            <div class="float-v ok">${n.langues}</div>
          </div>
        </div>
      </div>
    </section>

    <div class="marquee" aria-hidden="true"><div class="marquee-row">${marquee}${marquee}</div></div>

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
