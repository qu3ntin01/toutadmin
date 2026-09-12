const { frame, band, head } = require('./_shared');

module.exports = function index(ctx) {
  const { t, esc, icon, media } = ctx;

  const stats = [
    ['35', 'stats.spaces', 'stats.spacesNote'],
    ['16', 'stats.languages', 'stats.languagesNote'],
    ['797', 'stats.tests', 'stats.testsNote'],
    ['7', 'stats.modules', 'stats.modulesNote'],
    ['2754', 'stats.keys', 'stats.keysNote'],
    ['0', 'stats.deps', 'stats.depsNote'],
  ].map(([value, key, note], i) => `<div class="stat reveal">
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
                <p><a class="link-arrow" href="${ctx.href('ecrans')}">${esc(t('tour.all'))}${icon('arrow')}</a></p>
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

  const plans = [1, 2, 3, 4].map((n) => {
    const price = ['0', '50', '100', null][n - 1];
    const featured = n === 2;
    return `<article class="plan${featured ? ' is-featured' : ''} reveal">
            ${featured ? `<span class="plan-badge">${esc(t('pricing.popular'))}</span>` : ''}
            <div class="plan-name">${esc(t(`pricing.p${n}.name`))}</div>
            <div class="plan-for">${esc(t(`pricing.p${n}.for`))}</div>
            <div class="plan-price">
              ${price === null
                ? `<span class="plan-amount">${esc(t('pricing.custom'))}</span>`
                : (price === '0'
                  ? `<span class="plan-amount">${esc(t('pricing.free'))}</span>`
                  : `<span class="plan-amount">${price} €</span><span class="plan-per">${esc(t('pricing.perMonth'))}</span>`)}
            </div>
            <p>${esc(t(`pricing.p${n}.body`))}</p>
            <a class="btn ${featured ? '' : 'btn-ghost'}" href="${ctx.href(n === 4 ? 'contact' : 'tarifs')}">${esc(t(n === 4 ? 'pricing.ctaCustom' : 'pricing.cta'))}</a>
          </article>`;
  }).join('\n          ');

  const faq = [1, 2, 3, 4, 5, 6].map((n) => `<details class="reveal">
            <summary>${esc(t(`faq.q${n}`))}${icon('plus')}</summary>
            <div class="answer">${esc(t(`faq.a${n}`))}</div>
          </details>`).join('\n          ');

  const marquee = require('./_shared').DOMAINS
    .map((k) => `<span>${esc(t(`dom.${k}.title`))}</span>`).join('');

  const title = t('hero.title').split('\n');

  return {
    title: `${t('site.name')} — ${t('site.tagline')}`,
    description: t('hero.lede'),
    body: `
    <section class="hero">
      <div class="hero-bg"></div><div class="hero-grid-lines"></div>
      <div class="wrap hero-in stagger-hero">
        <div>
          <span class="eyebrow reveal">${icon('spark')}${esc(t('hero.eyebrow'))}</span>
          <h1 class="reveal">${esc(title[0])}<br /><span class="g">${esc(title[1] || '')}</span></h1>
          <p class="lede reveal">${esc(t('hero.lede'))}</p>
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
        <div class="hero-shot reveal" data-parallax>
          ${frame(ctx, media.hero, null, false)}
          <div class="float float-1">
            <div class="float-k">${esc(t('stats.spaces'))}</div>
            <div class="float-v">35</div>
          </div>
          <div class="float float-2">
            <div class="float-k">${esc(t('stats.languages'))}</div>
            <div class="float-v ok">16</div>
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
        <div class="plans stagger">
          ${plans}
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
