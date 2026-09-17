const { band, pageHero, head } = require('./_shared');
const { plans, planCard, gridRows, FREE_SEATS, RATE, euro, monthlyFor, billableFor,
  REPLY_DAYS, SETUP_FEE, HOURLY_RATE, HOURS_PACK, HOURS_PACK_FEE } = require('./_plans');

module.exports = function pricing(ctx) {
  const { t, esc, icon } = ctx;

  const perks = {
    1: ['pricing.f.allSpaces', 'pricing.f.languages', 'pricing.f.security', 'pricing.f.updates', 'pricing.f.saas'],
    2: ['pricing.f.allSpaces', 'pricing.f.local', 'pricing.f.backupOff', 'pricing.f.api', 'pricing.f.supportMail'],
  };

  const cards = plans(ctx).map((plan) => {
    const list = `<ul class="plan-list">
              ${perks[plan.n].map((key) => `<li>${icon('check')}<span>${esc(t(key))}</span></li>`).join('\n              ')}
            </ul>`;
    return planCard(ctx, plan, list);
  }).join('\n          ');

  /*
   * Ce qui est compris — une colonne, pas deux.
   *
   * La page comparait deux formules fonction par fonction, avec des « Non » en
   * face de la colonne gratuite : sauvegardes externalisées, multisite, SLA. Ce
   * tableau datait des paliers. Depuis qu'il n'y en a plus — cinq salariés
   * offerts, puis cinq euros par salarié —, il n'y a plus qu'un produit, et il
   * est entier pour tout le monde. Le laisser aurait contredit, quinze lignes
   * plus haut, la promesse « tout le produit est dans toutes les formules ».
   *
   * Restent deux prestations qui ne sont pas du logiciel et se chiffrent au cas
   * par cas : reprendre des données existantes, et s'engager sur un niveau de
   * service. Elles sont dites « en option », ce qu'elles sont.
   */
  const yes = `<span class="mark-yes">${esc(t('pricing.yes'))}</span>`;
  const opt = `<span class="mark-opt">${esc(t('pricing.option'))}</span>`;
  const rows = [
    ['pricing.f.allSpaces', yes],
    ['pricing.f.languages', yes],
    ['pricing.f.security', yes],
    ['pricing.f.updates', yes],
    ['pricing.f.api', yes],
    ['pricing.f.saas', yes],
    ['pricing.f.local', yes],
    ['pricing.f.backupOff', yes],
    ['pricing.f.multisite', yes],
    ['pricing.f.support', `<span class="mark-yes">${esc(t('pricing.f.supportMail'))}</span>`],
    ['pricing.f.setup', opt],
    ['pricing.f.migration', opt],
    ['pricing.f.sla', opt],
  ].map(([key, valeur]) =>
    `<tr><th scope="row">${esc(t(key))}</th><td class="c">${valeur}</td></tr>`).join('\n              ');

  // Les trois paliers d'une licence qui s'arrête. On les met sur la page qui
  // vend : un client a le droit de savoir ce qui se passe s'il cesse de payer,
  // et le savoir d'avance vaut mieux que le découvrir.
  const steps = [
    ['licence.step1.when', 'licence.step1.what'],
    ['licence.step2.when', 'licence.step2.what'],
    ['licence.step3.when', 'licence.step3.what'],
  ].map(([when, what]) =>
    `<tr><th scope="row">${esc(t(when))}</th><td>${esc(t(what))}</td></tr>`).join('\n              ');

  const faq = [1, 9, 6, 7, 4].map((n) => `<details class="reveal">
            <summary>${esc(t(`faq.q${n}`))}${icon('plus')}</summary>
            <div class="answer">${esc(t(`faq.a${n}`))}</div>
          </details>`).join('\n          ');

  return {
    title: `${t('nav.pricing')} — ${t('site.name')}`,
    description: t('pricing.lede'),
    body: `
    ${pageHero(ctx, t('nav.pricing'), t('pricing.title'), t('pricing.lede'))}

    <section class="section-tight">
      <div class="wrap">
        <p class="center reveal"><span class="eyebrow">${icon('users')}${esc(t('pricing.perEmployee'))}</span></p>
        <div class="plans stagger mt-l">
          ${cards}
        </div>
        <p class="center stat-n mt-m reveal">${esc(t('pricing.vat'))}</p>
      </div>
    </section>

    <section class="section tinted">
      <div class="wrap">
        ${head(ctx, null, t('sim.title'), t('sim.lede'))}

        <div class="sim reveal mx-auto" data-sim
             data-sim-free="${FREE_SEATS}" data-sim-rate="${RATE}"
             data-sim-currency="EUR" data-sim-free-label="${esc(t('pricing.free'))}">
          <label class="sim-field">
            <span>${esc(t('sim.count'))}</span>
            <span class="sim-input">
              <button type="button" class="sim-step" data-sim-step="-1"
                      aria-label="${esc(t('sim.less'))}">−</button>
              <input type="number" min="0" max="100000" step="1" value="12"
                     data-sim-count inputmode="numeric" />
              <button type="button" class="sim-step" data-sim-step="1"
                      aria-label="${esc(t('sim.more'))}">+</button>
            </span>
          </label>

          <p class="sim-total" aria-live="polite">
            <span data-sim-total>${esc(euro(ctx.locale.code, monthlyFor(12)))}</span>
            <span class="sim-per">${esc(t('pricing.perMonth'))}</span>
          </p>

          <p class="sim-detail" data-sim-detail data-model="${esc(t('sim.detail'))}">${
            esc(t('sim.detail')
              .replace('{total}', '12').replace('{free}', String(FREE_SEATS))
              .replace('{billable}', String(billableFor(12))).replace('{rate}', euro(ctx.locale.code, RATE)))
          }</p>

          <p class="sim-note">${esc(t('sim.note'))}</p>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="wrap">
        ${head(ctx, null, t('pricing.gridTitle'), t('pricing.gridLede'))}
        <div class="table-wrap reveal grid-table">
          <table>
            <thead>
              <tr>
                <th scope="col">${esc(t('pricing.col.headcount'))}</th>
                <th scope="col">${esc(t('pricing.col.perEmployee'))}</th>
                <th scope="col">${esc(t('pricing.col.monthly'))}</th>
              </tr>
            </thead>
            <tbody>
              ${gridRows(ctx)}
            </tbody>
          </table>
        </div>
        <div class="note reveal mx-auto mt-l">
          <h3>${esc(t('pricing.argTitle'))}</h3>
          <p>${esc(t('pricing.argBody'))}</p>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="wrap">
        ${head(ctx, null, t('pricing.tableTitle'), null)}
        <div class="table-wrap reveal">
          <table>
            <thead>
              <tr>
                <th scope="col">${esc(t('pricing.col.feature'))}</th>
                <th scope="col">${esc(t('pricing.col.included'))}</th>
              </tr>
            </thead>
            <tbody>
              ${rows}
            </tbody>
          </table>
        </div>
        <div class="note reveal mt-l">
          <h3>${esc(t('pricing.countTitle'))}</h3>
          <p>${esc(t('pricing.countBody'))}</p>
        </div>
      </div>
    </section>

    <section class="section tinted">
      <div class="wrap">
        ${head(ctx, null, t('support.title'), t('support.lede'))}

        <div class="plans plans-3 stagger mt-l">
          ${[
            ['support.t1', t('pricing.free'), ''],
            ['support.t2', t('support.t2.amount', { jours: String(REPLY_DAYS) }), t('support.included')],
            ['support.t3', t('support.t3.amount'), ''],
          ].map(([cle, montant, per], i) => `<article class="plan${i === 1 ? ' is-featured' : ''} reveal">
            <div class="plan-name">${esc(t(`${cle}.name`))}</div>
            <div class="plan-for">${esc(t(`${cle}.who`))}</div>
            <p class="plan-value">${esc(montant)}${per ? ` <span class="plan-per">${esc(per)}</span>` : ''}</p>
            <p>${esc(t(`${cle}.what`))}</p>
          </article>`).join('\n          ')}
        </div>

        <div class="note reveal mx-auto mt-l">
          <h3>${esc(t('support.scopeTitle'))}</h3>
          <p>${esc(t('support.scopeBody'))}</p>
        </div>

        <div class="table-wrap reveal mt-l">
          <table>
            <thead>
              <tr>
                <th scope="col">${esc(t('support.optTitle'))}</th>
                <th scope="col">${esc(t('support.col.price'))}</th>
              </tr>
            </thead>
            <tbody>
              ${[
                ['support.opt.setup', euro(ctx.locale.code, SETUP_FEE)],
                ['support.opt.migration', t('support.quote')],
                ['support.opt.priority', t('support.opt.priorityPrice', {
                  rate: euro(ctx.locale.code, HOURLY_RATE),
                  hours: String(HOURS_PACK),
                  pack: euro(ctx.locale.code, HOURS_PACK_FEE),
                })],
                ['support.opt.sla', t('support.quote')],
              ].map(([cle, prix]) => `<tr>
                <th scope="row">${esc(t(cle))}<span class="opt-what">${esc(t(`${cle}Body`))}</span></th>
                <td class="c"><strong>${esc(prix)}</strong></td>
              </tr>`).join('\n              ')}
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <section class="section">
      <div class="wrap">
        ${head(ctx, null, t('licence.title'), t('licence.lede'))}

        <div class="table-wrap reveal">
          <table>
            <thead>
              <tr>
                <th scope="col">${esc(t('licence.col.when'))}</th>
                <th scope="col">${esc(t('licence.col.what'))}</th>
              </tr>
            </thead>
            <tbody>
              ${steps}
            </tbody>
          </table>
        </div>

        <div class="note reveal mx-auto mt-l">
          <h3>${esc(t('licence.neverTitle'))}</h3>
          <p>${esc(t('licence.neverBody'))}</p>
        </div>

        <div class="note reveal mx-auto mt-l">
          <h3>${esc(t('licence.offlineTitle'))}</h3>
          <p>${esc(t('licence.offlineBody'))}</p>
        </div>
      </div>
    </section>

    <section class="section tinted">
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
