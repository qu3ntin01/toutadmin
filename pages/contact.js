const { pageHero } = require('./_shared');

module.exports = function contact(ctx) {
  const { t, esc, icon } = ctx;
  const cards = [
    ['demo', 'users', t('contact.demoTitle'), t('contact.demoBody'), t('contact.write'), 'mailto:bonjour@toutadmin.example'],
    ['doc', 'layers', t('contact.docTitle'), t('contact.docBody'), t('contact.docCta'), '#'],
    ['self', 'code', t('contact.selfTitle'), t('contact.selfBody'), t('contact.selfCta'), '#'],
  ].map(([, ic, title, body, cta, href]) => `<article class="card reveal">
            <span class="ico">${icon(ic)}</span>
            <h3>${esc(title)}</h3>
            <p>${esc(body)}</p>
            <p class="mt-m"><a class="link-arrow" href="${href}">${esc(cta)}${icon('arrow')}</a></p>
          </article>`).join('\n          ');

  return {
    title: `${t('nav.contact')} — ${t('site.name')}`,
    description: t('contact.lede'),
    body: `
    ${pageHero(ctx, t('nav.contact'), t('contact.title'), t('contact.lede'))}

    <section class="section-tight">
      <div class="wrap">
        <div class="grid g3 stagger">
          ${cards}
        </div>
      </div>
    </section>

    <section class="section">
      <div class="wrap">
        <div class="band reveal">
          <h2>${esc(t('contact.demoTitle'))}</h2>
          <p>${esc(t('contact.lede'))}</p>
          <div class="band-cta">
            <a class="btn btn-light" href="mailto:bonjour@toutadmin.example">${esc(t('contact.emailLabel'))} — bonjour@toutadmin.example${icon('arrow')}</a>
          </div>
        </div>
      </div>
    </section>
`,
  };
};
