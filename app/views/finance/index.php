<?php
$csrf = \App\Core\Csrf::field();
$money = static fn (?float $v, ?string $code = null): string =>
    $v === null ? '—' : number_format($v, 2, ',', ' ') . ' ' . (\App\Modules\Currency::byCode($code ?? $baseCurrency)['symbol'] ?? '');
$fullName = static fn (array $p): string => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
?>
<section class="stats-grid">
  <?php foreach ([
      [$money($summary['income']), t('erp.income')],
      [$money($summary['spending']), t('erp.spending')],
      [$money($summary['unpaidIncome']), t('erp.unpaid')],
      [(string) $summary['overdue'], t('ges.overdue')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<!-- ------------------------------------------------------------- Tiers -->
<section class="tab-panel is-active" id="tiers">
  <div class="card">
    <h2><?= e(t('erp.partners')) ?></h2>
    <form method="POST" action="/gestion/tiers" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.name')) ?></span><input type="text" name="name" required maxlength="160" /></label>
      <label><span><?= e(t('common.type')) ?></span>
        <select name="kind" required>
          <?php foreach ($partnerKinds as $kind): ?>
            <option value="<?= e($kind) ?>"><?= e($kind) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.identifier')) ?></span><input type="text" name="registration" maxlength="60" /></label>
      <label><span><?= e(t('ges.contact')) ?></span><input type="text" name="contact_name" maxlength="120" /></label>
      <label><span><?= e(t('auth.email')) ?></span><input type="email" name="email" maxlength="254" /></label>
      <label><span><?= e(t('common.phone')) ?></span><input type="tel" name="phone" maxlength="40" /></label>
      <label class="span-2"><span><?= e(t('common.address')) ?></span><input type="text" name="address" maxlength="300" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.create')) ?></button>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('erp.partners')) ?></h2>
    <?php if ($partners === []): ?>
      <div class="empty-state"><?= e(t('erp.noPartner')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.name')) ?></th>
            <th><?= e(t('common.type')) ?></th>
            <th><?= e(t('ges.contact')) ?></th>
            <th><?= e(t('erp.contracts')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($partners as $partner): ?>
            <tr>
              <td>
                <strong><a href="/partenaires/<?= (int) $partner['id'] ?>"><?= e($partner['name']) ?></a></strong>
                <?php if ((int) $partner['active'] !== 1): ?><span class="tag"><?= e(t('common.inactive')) ?></span><?php endif; ?>
                <?php if (!empty($partner['registration'])): ?><br /><span class="cell-sub"><?= e($partner['registration']) ?></span><?php endif; ?>
              </td>
              <td><?= e(st($partner['kind'])) ?></td>
              <td>
                <?= e((string) $partner['contact_name']) ?>
                <?php if (!empty($partner['email'])): ?><br /><span class="cell-sub"><?= e($partner['email']) ?></span><?php endif; ?>
              </td>
              <td><?= (int) $partner['contract_count'] ?> · <?= (int) $partner['invoice_count'] ?></td>
              <td class="row-actions">
                <a class="btn btn-outline btn-sm" href="/partenaires/<?= (int) $partner['id'] ?>"><?= e(t('ptn.openSheet')) ?></a>
                <form method="POST" action="/gestion/tiers/<?= (int) $partner['id'] ?>/statut">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm">
                    <?= e((int) $partner['active'] === 1 ? t('common.deactivate') : t('common.activate')) ?>
                  </button>
                </form>
                <form method="POST" action="/gestion/tiers/<?= (int) $partner['id'] ?>/supprimer"
                      data-confirm="<?= e(t('ges.deletePartner')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<!-- --------------------------------------------------------- Contrats -->
<section class="tab-panel" id="contrats">
  <?php if ($renewals !== []): ?>
    <div class="card">
      <h2><?= e(t('erp.toRenew')) ?></h2>
      <p class="muted"><?= e(t('ges.noticeHelp')) ?></p>
      <table class="table">
        <thead>
          <tr><th><?= e(t('common.title')) ?></th><th><?= e(t('erp.partners')) ?></th><th><?= e(t('erp.notice')) ?></th></tr>
        </thead>
        <tbody>
          <?php foreach ($renewals as $contract): ?>
            <tr>
              <td><?= e($contract['title']) ?></td>
              <td><?= e($contract['partner_name']) ?></td>
              <td>
                <?= e($contract['noticeDeadline']) ?>
                <?php if ($contract['noticeElapsed']): ?><span class="tag tag-off"><?= e(t('ges.overdue')) ?></span><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <div class="card mt-l">
    <h2><?= e(t('erp.contracts')) ?></h2>
    <form method="POST" action="/gestion/contrats" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('erp.partners')) ?></span>
        <select name="partner_id" required>
          <option value=""></option>
          <?php foreach ($partners as $partner): ?>
            <option value="<?= (int) $partner['id'] ?>"><?= e($partner['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.title')) ?></span><input type="text" name="title" required maxlength="160" /></label>
      <label><span><?= e(t('leave.from')) ?></span><input type="date" name="start_date" /></label>
      <label><span><?= e(t('leave.to')) ?></span><input type="date" name="end_date" /></label>
      <label><span><?= e(t('erp.notice') . ' (' . mb_strtolower(t('common.days')) . ')') ?></span><input type="number" name="notice_days" min="0" max="365" value="0" /></label>
      <label><span><?= e(t('erp.amount')) ?></span><input type="text" name="amount" inputmode="decimal" /></label>
      <label><span><?= e(t('ges.frequency')) ?></span>
        <select name="billing_period">
          <?php foreach ($billingPeriods as $period): ?>
            <option value="<?= e($period) ?>"<?= $period === 'Annuel' ? ' selected' : '' ?>><?= e($period) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('ges.internalContact')) ?></span>
        <select name="owner_id">
          <option value=""></option>
          <?php foreach ($employees as $employee): ?>
            <option value="<?= (int) $employee['id'] ?>"><?= e($fullName($employee)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.create')) ?></button>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('erp.contracts')) ?></h2>
    <?php if ($contracts === []): ?>
      <div class="empty-state"><?= e(t('erp.noContract')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.title')) ?></th>
            <th><?= e(t('erp.partners')) ?></th>
            <th><?= e(t('common.period')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($contracts as $contract): ?>
            <tr>
              <td><?= e($contract['title']) ?><br /><span class="cell-sub"><?= e((string) $contract['reference']) ?></span></td>
              <td><?= e($contract['partner_name']) ?></td>
              <td><?= e(\App\Core\Dates::short((string) $contract['start_date'])) ?> → <?= e(\App\Core\Dates::short((string) $contract['end_date'])) ?></td>
              <td>
                <form method="POST" action="/gestion/contrats/<?= (int) $contract['id'] ?>/statut" class="inline-form">
                  <?= $csrf ?>
                  <select name="status">
                    <?php foreach ($contractStatuses as $status): ?>
                      <option value="<?= e($status) ?>"<?= $contract['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
                </form>
              </td>
              <td>
                <form method="POST" action="/gestion/contrats/<?= (int) $contract['id'] ?>/supprimer"
                      data-confirm="<?= e(t('ges.deleteContract')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<!-- --------------------------------------------------------- Factures -->
<section class="tab-panel" id="factures">
  <div class="card">
    <h2><?= e(t('erp.invoices')) ?></h2>
    <form method="POST" action="/gestion/factures" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.direction')) ?></span>
        <select name="direction" required>
          <?php foreach ($invoiceDirections as $direction): ?>
            <option value="<?= e($direction) ?>"><?= e($direction) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('erp.partners')) ?></span>
        <select name="partner_id">
          <option value=""></option>
          <?php foreach ($partners as $partner): ?>
            <option value="<?= (int) $partner['id'] ?>"><?= e($partner['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('common.title')) ?></span><input type="text" name="label" required maxlength="160" /></label>
      <label><span><?= e(t('common.reference')) ?></span><input type="text" name="reference" maxlength="60" /></label>
      <label><span><?= e(t('ges.issue')) ?></span><input type="date" name="issue_date" value="<?= e($today) ?>" required /></label>
      <label><span><?= e(t('erp.dueDate')) ?></span><input type="date" name="due_date" /></label>
      <label><span><?= e(t('ges.amountExclVat')) ?></span><input type="text" name="amount_ht" inputmode="decimal" required /></label>
      <label><span><?= 'TVA (%)' ?></span><input type="text" name="vat_rate" inputmode="decimal" value="20" required /></label>
      <label><span><?= e(t('common.currency')) ?></span>
        <select name="currency">
          <?php foreach ($usableCurrencies as $currency): ?>
            <option value="<?= e($currency['code']) ?>"<?= $currency['code'] === $baseCurrency ? ' selected' : '' ?>>
              <?= e($currency['code']) ?> — <?= e($currency['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.department')) ?></span>
        <select name="department_id">
          <option value=""></option>
          <?php foreach ($departments as $department): ?>
            <option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.status')) ?></span>
        <select name="status">
          <?php foreach ($invoiceStatuses as $status): ?>
            <option value="<?= e($status) ?>"<?= $status === 'Émise' ? ' selected' : '' ?>><?= e(st($status)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('ges.purchaseOrder')) ?></span>
        <select name="purchase_order_id">
          <option value=""><?= e(t('common.none')) ?></option>
          <?php foreach ($openOrders as $order): ?>
            <option value="<?= (int) $order['id'] ?>"><?= e($order['reference']) ?> — <?= e($order['partner_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.create')) ?></button>
    </form>
    <p class="muted"><?= e(t('ges.purchaseOrderHelp')) ?></p>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('erp.invoices')) ?></h2>
    <?php if ($invoices === []): ?>
      <div class="empty-state"><?= e(t('erp.noInvoice')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.title')) ?></th>
            <th><?= e(t('erp.partners')) ?></th>
            <th><?= 'TTC' ?></th>
            <th><?= e(t('erp.dueDate')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($invoices as $invoice): ?>
            <tr>
              <td>
                <?= e($invoice['label']) ?>
                <br /><span class="cell-sub"><?= e($invoice['direction']) ?> · <?= e((string) $invoice['reference']) ?></span>
              </td>
              <td><?= e((string) $invoice['partner_name']) ?></td>
              <td>
                <?= e($money($invoice['amount_ttc'], $invoice['currency'])) ?>
                <?php if ($invoice['foreign']): ?>
                  <br /><span class="cell-sub"><?= e($money($invoice['amount_base_ttc'])) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?= e(\App\Core\Dates::short((string) $invoice['due_date'])) ?>
                <?php if ($invoice['overdue']): ?><br /><span class="tag tag-off"><?= e(t('ges.overdue')) ?></span><?php endif; ?>
              </td>
              <td>
                <form method="POST" action="/gestion/factures/<?= (int) $invoice['id'] ?>/statut" class="inline-form">
                  <?= $csrf ?>
                  <select name="status">
                    <?php foreach ($invoiceStatuses as $status): ?>
                      <option value="<?= e($status) ?>"<?= $invoice['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
                </form>
              </td>
              <td>
                <form method="POST" action="/gestion/factures/<?= (int) $invoice['id'] ?>/supprimer"
                      data-confirm="<?= e(t('ges.deleteInvoice')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<!-- ---------------------------------------------------------- Budgets -->
<section class="tab-panel" id="budgets">
  <div class="card">
    <div class="org-head">
      <h2><?= e(t('erp.budgets')) ?> <?= (int) $year ?></h2>
      <form method="GET" action="/gestion" class="inline-form">
        <label class="sr-only" for="annee"><?= e(t('common.fiscalYear')) ?></label>
        <select name="annee" id="annee">
          <?php foreach ($years as $choice): ?>
            <option value="<?= (int) $choice ?>"<?= (int) $choice === (int) $year ? ' selected' : '' ?>><?= (int) $choice ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm"><?= e(t('common.open')) ?></button>
      </form>
    </div>
    <form method="POST" action="/gestion/budgets" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.department')) ?></span>
        <select name="department_id" required>
          <option value=""></option>
          <?php foreach ($departments as $department): ?>
            <option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.fiscalYear')) ?></span>
        <select name="year">
          <?php foreach ($years as $option): ?>
            <option value="<?= (int) $option ?>"<?= $option === $year ? ' selected' : '' ?>><?= (int) $option ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('erp.amount')) ?></span><input type="text" name="amount" inputmode="decimal" required /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
    </form>
  </div>

  <div class="card mt-l">
    <?php if ($budgets === []): ?>
      <div class="empty-state"><?= e(t('erp.noBudget')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.department')) ?></th>
            <th><?= e(t('erp.amount')) ?></th>
            <th><?= e(t('erp.consumed')) ?></th>
            <th><?= e(t('erp.remaining')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($budgets as $budget): ?>
            <tr>
              <td><?= e($budget['department_name']) ?></td>
              <td><?= e($money((float) $budget['amount'])) ?></td>
              <td><?= e($money($budget['consumed'])) ?> <span class="cell-sub">(<?= e((string) $budget['ratio']) ?> %)</span></td>
              <td><?= e($money($budget['remaining'])) ?></td>
              <td>
                <form method="POST" action="/gestion/budgets/<?= (int) $budget['id'] ?>/supprimer">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm"><?= e(t('common.delete')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<!-- ---------------------------------------------------- Notes de frais -->
<section class="tab-panel" id="frais">
  <div class="card">
    <h2><?= e(t('erp.claims')) ?></h2>
    <?php if ($claims === []): ?>
      <div class="empty-state"><?= e(t('erp.noClaim')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.member')) ?></th>
            <th><?= e(t('common.date')) ?></th>
            <th><?= e(t('common.category')) ?></th>
            <th><?= e(t('erp.amount')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($claims as $claim): ?>
            <tr>
              <td><?= e($fullName($claim)) ?><br /><span class="cell-sub"><?= e((string) $claim['department_name']) ?></span></td>
              <td><?= e(\App\Core\Dates::short((string) $claim['spent_on'])) ?></td>
              <td><?= e($claim['category']) ?><br /><span class="cell-sub"><?= e((string) $claim['description']) ?></span></td>
              <td><?= e($money((float) $claim['amount'])) ?></td>
              <td>
                <span class="status <?= in_array($claim['status'], ['Approuvée', 'Remboursée'], true) ? 'status-on' : ($claim['status'] === 'En attente' ? 'status-wait' : 'status-off') ?>">
                  <?= e(st($claim['status'])) ?>
                </span>
              </td>
              <td class="row-actions">
                <?php
                  $next = match ($claim['status']) {
                      'En attente' => ['Approuvée', 'Refusée'],
                      'Approuvée' => ['Remboursée'],
                      default => [],
                  };
                ?>
                <?php foreach ($next as $status): ?>
                  <form method="POST" action="/gestion/frais/<?= (int) $claim['id'] ?>/statut" class="inline-form">
                    <?= $csrf ?>
                    <input type="hidden" name="status" value="<?= e($status) ?>" />
                    <button type="submit" class="btn btn-sm"><?= e($status) ?></button>
                  </form>
                <?php endforeach; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<!-- ---------------------------------------------------------- Devises -->
<section class="tab-panel" id="devises">
  <div class="card">
    <h2><?= e(t('ges.exchangeRates')) ?></h2>
    <p class="muted"><?= e(t('ges.currencyHelp')) ?></p>
    <form method="POST" action="/gestion/devises/reference" class="inline-form">
      <?= $csrf ?>
      <select name="code">
        <?php foreach ($currencies as $currency): ?>
          <option value="<?= e($currency['code']) ?>"<?= $currency['isBase'] ? ' selected' : '' ?>>
            <?= e($currency['code']) ?> — <?= e($currency['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm btn-primary"><?= e(t('ges.changeCurrency')) ?></button>
    </form>

    <table class="table mt-l">
      <thead>
        <tr><th><?= e(t('common.currency')) ?></th><th><?= e(t('ges.oneUnitEquals')) ?></th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($currencies as $currency): ?>
          <tr>
            <td>
              <strong><?= e($currency['code']) ?></strong> <?= e($currency['label']) ?>
              <?php if ($currency['isBase']): ?><span class="tag"><?= e(t('common.reference')) ?></span><?php endif; ?>
            </td>
            <td><?= $currency['rate'] === null ? '—' : e((string) $currency['rate']) ?></td>
            <td>
              <?php if (!$currency['isBase']): ?>
                <form method="POST" action="/gestion/devises/taux" class="inline-form">
                  <?= $csrf ?>
                  <input type="hidden" name="code" value="<?= e($currency['code']) ?>" />
                  <input type="text" name="rate" inputmode="decimal" placeholder="<?= e(t('ges.oneUnitEquals')) ?>" required />
                  <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- ---------------------------------------------------- Recouvrement -->
<section class="tab-panel" id="recouvrement">
  <section class="stats-grid">
    <?php foreach ([
        [$money($dunningSummary['outstanding']), t('rec.outstanding')],
        [$money($dunningSummary['overdue']), t('rec.overdue')],
        [(string) $dunningSummary['toSend'], t('rec.toSend')],
        [(string) $dunningSummary['formalNotices'], t('rec.formalNotices')],
    ] as [$value, $label]): ?>
      <div class="stat-card">
        <span class="stat-body">
          <span class="stat-value"><?= e($value) ?></span>
          <span class="stat-label"><?= e($label) ?></span>
        </span>
      </div>
    <?php endforeach; ?>
  </section>

  <div class="card">
    <h2><?= e(t('rec.agedTitle')) ?></h2>
    <table class="table">
      <thead>
        <tr>
          <th><?= e(t('rec.bucketCurrent')) ?></th><th><?= e(t('rec.bucket30')) ?></th><th><?= e(t('rec.bucket60')) ?></th>
          <th><?= e(t('rec.bucket90')) ?></th><th><?= e(t('rec.bucketMore')) ?></th><th><?= e(t('common.total')) ?></th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <?php foreach (['courant', 'j30', 'j60', 'j90', 'plus'] as $bucket): ?>
            <td><?= e($money($agedBalance['buckets'][$bucket]['amount'])) ?></td>
          <?php endforeach; ?>
          <td><strong><?= e($money($agedBalance['total'])) ?></strong></td>
        </tr>
      </tbody>
    </table>
    <p class="muted"><?= e(t('rec.agedHint')) ?></p>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('rec.dueTitle', ['count' => count($dunningDue)])) ?></h2>
    <p class="muted"><?= e(t('rec.levelHint')) ?></p>
    <?php if ($dunningDue === []): ?>
      <div class="empty-state"><?= e(t('rec.nothingDue')) ?></div>
    <?php else: ?>
      <?php foreach ($dunningDue as $row): ?>
        <form method="POST" action="/gestion/relances" class="form-grid">
          <?= $csrf ?>
          <input type="hidden" name="invoice_id" value="<?= (int) $row['invoice']['id'] ?>" />
          <input type="hidden" name="level" value="<?= (int) $row['level']['level'] ?>" />
          <label class="span-2">
            <span><?= e($row['invoice']['reference'] ?: $row['invoice']['label']) ?>
                  — <?= e((string) $row['invoice']['partner_name']) ?></span>
            <input type="text" readonly
                   value="<?= e($money((float) $row['invoice']['amount_ht'])) ?> · <?= e(t('rec.lateBy', ['days' => $row['invoice']['overdueDays']])) ?>" />
          </label>
          <label><span><?= e(t('rec.level')) ?></span><input type="text" readonly value="<?= e($row['level']['label']) ?>" /></label>
          <label><span><?= e(t('rec.sentOn')) ?></span><input type="date" name="sent_on" value="<?= e($today) ?>" required /></label>
          <label class="span-2"><span><?= e(t('common.notes')) ?></span><input type="text" name="note" maxlength="500" /></label>
          <button type="submit" class="btn btn-primary"><?= e(t('rec.record')) ?></button>
        </form>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('rec.outstandingTitle', ['count' => count($dunningOutstanding)])) ?></h2>
    <?php if ($dunningOutstanding === []): ?>
      <div class="empty-state"><?= e(t('rec.noOutstanding')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.reference')) ?></th><th><?= e(t('erp.partners')) ?></th><th><?= e(t('erp.dueDate')) ?></th>
            <th><?= e(t('erp.amount')) ?></th><th><?= e(t('rec.lateDays')) ?></th><th><?= e(t('rec.lastNotice')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($dunningOutstanding as $invoice): ?>
            <tr>
              <td><?= e($invoice['reference'] ?: $invoice['label']) ?></td>
              <td><?= e((string) $invoice['partner_name']) ?></td>
              <td><?= e(\App\Core\Dates::short((string) $invoice['due_date'])) ?></td>
              <td><?= e($money((float) $invoice['amount_ht'])) ?></td>
              <td><?= (int) $invoice['overdueDays'] > 0 ? (int) $invoice['overdueDays'] : '—' ?></td>
              <td>
                <?php if (!empty($invoice['last_level'])): ?>
                  <span class="tag"><?= e($dunningLevels[(int) $invoice['last_level'] - 1]['label']) ?></span>
                  <br /><span class="cell-sub"><?= e((string) $invoice['last_sent']) ?></span>
                <?php else: ?>—<?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<!-- ----------------------------------------------------- Abonnements -->
<section class="tab-panel" id="abonnements">
  <section class="stats-grid">
    <?php
      $activeSubscriptions = count(array_filter($subscriptions, static fn (array $s): bool => (int) $s['active'] === 1));
    ?>
    <?php foreach ([
        [(string) $activeSubscriptions, t('ges.activeSubscriptions')],
        [$money($subscriptionValue['client']), t('ges.recurringClient')],
        [$money($subscriptionValue['supplier']), t('ges.recurringSupplier')],
        [(string) count($subscriptionDue), t('ges.dueToInvoice')],
    ] as [$value, $label]): ?>
      <div class="stat-card">
        <span class="stat-body">
          <span class="stat-value"><?= e($value) ?></span>
          <span class="stat-label"><?= e($label) ?></span>
        </span>
      </div>
    <?php endforeach; ?>
  </section>

  <div class="card">
    <h2><?= e(t('ges.newSubscription')) ?></h2>
    <form method="POST" action="/gestion/abonnements" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('common.title')) ?></span>
        <input type="text" name="label" required maxlength="160" placeholder="<?= e(t('ges.subscriptionExample')) ?>" />
      </label>
      <label><span><?= e(t('common.direction')) ?></span>
        <select name="direction" required>
          <?php foreach ($subscriptionDirections as $direction): ?>
            <option value="<?= e($direction) ?>"><?= e($direction) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('ges.frequency')) ?></span>
        <select name="period" required>
          <?php foreach ($subscriptionPeriods as $period): ?>
            <option value="<?= e($period) ?>"><?= e($period) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('erp.partners')) ?></span>
        <select name="partner_id">
          <option value=""></option>
          <?php foreach ($partners as $partner): ?>
            <option value="<?= (int) $partner['id'] ?>"><?= e($partner['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('ges.chargedDepartment')) ?></span>
        <select name="department_id">
          <option value=""></option>
          <?php foreach ($departments as $department): ?>
            <option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('ges.amountExclVat')) ?></span><input type="text" name="amount_ht" inputmode="decimal" required /></label>
      <label><span>TVA (%)</span><input type="number" name="vat_rate" min="0" max="100" step="0.1" value="20" required /></label>
      <label><span><?= e(t('common.currency')) ?></span>
        <select name="currency">
          <?php foreach ($usableCurrencies as $currency): ?>
            <option value="<?= e($currency['code']) ?>"<?= $currency['code'] === $baseCurrency ? ' selected' : '' ?>>
              <?= e($currency['code']) ?> — <?= e($currency['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('ges.firstInstalment')) ?></span><input type="date" name="start_date" value="<?= e($today) ?>" required /></label>
      <label><span><?= e(t('ges.endOptional')) ?></span><input type="date" name="end_date" /></label>
      <label><span><?= e(t('ges.paymentTerms')) ?></span><input type="number" name="payment_days" min="0" max="180" value="30" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('ges.saveSubscription')) ?></button>
    </form>
    <p class="muted"><?= e(t('ges.subscriptionHelp')) ?></p>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('nav.subscriptions')) ?></h2>
    <form method="POST" action="/gestion/abonnements/emettre" class="inline-form">
      <?= $csrf ?>
      <button type="submit" class="btn btn-primary btn-sm"><?= e(t('ges.issueDue')) ?></button>
    </form>
    <?php if ($subscriptions === []): ?>
      <div class="empty-state"><?= e(t('ges.noSubscription')) ?></div>
    <?php else: ?>
      <table class="table mt-l">
        <thead>
          <tr>
            <th><?= e(t('ges.subscription')) ?></th>
            <th><?= e(t('ges.rhythm')) ?></th>
            <th><?= e(t('ges.next')) ?></th>
            <th><?= e(t('ges.issued')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($subscriptions as $subscription): ?>
            <tr>
              <td>
                <strong><?= e($subscription['label']) ?></strong>
                <br /><span class="cell-sub">
                  <?= e($subscription['direction']) ?>
                  <?= $subscription['partner_name'] ? ' · ' . e($subscription['partner_name']) : '' ?>
                  · <?= e($money($subscription['amountTtc'], $subscription['currency'])) ?>
                </span>
              </td>
              <td><?= e($subscription['period']) ?></td>
              <td>
                <?= e((string) $subscription['next_issue']) ?>
                <?php if ((int) $subscription['active'] !== 1): ?>
                  <br /><span class="tag tag-off"><?= e(t('common.inactive')) ?></span>
                <?php endif; ?>
              </td>
              <td><?= (int) $subscription['issued_count'] ?></td>
              <td class="row-actions">
                <form method="POST" action="/gestion/abonnements/<?= (int) $subscription['id'] ?>/etat" class="inline-form">
                  <?= $csrf ?>
                  <input type="hidden" name="active" value="<?= (int) $subscription['active'] === 1 ? '0' : '1' ?>" />
                  <button type="submit" class="btn btn-sm">
                    <?= e((int) $subscription['active'] === 1 ? t('common.deactivate') : t('common.activate')) ?>
                  </button>
                </form>
                <form method="POST" action="/gestion/abonnements/<?= (int) $subscription['id'] ?>/supprimer"
                      data-confirm="<?= e(t('ges.deleteSubscription')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<!-- ------------------------------------------------------------- TVA -->
<section class="tab-panel" id="tva">
  <div class="card">
    <h2><?= e(t('ges.closePeriod')) ?> <span class="muted"><?= e(t('ges.cashBasisNote')) ?></span></h2>
    <form method="POST" action="/gestion/tva" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('ges.periodOf')) ?> <?= (int) $year ?></span>
        <select name="periode" required>
          <?php foreach ($vatPeriods as $period): ?>
            <option value="<?= e($period['key']) ?>"><?= e($period['regime']) ?> — <?= e($period['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('common.notes')) ?></span><input type="text" name="notes" maxlength="1000" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('ges.computeAndSave')) ?></button>
    </form>
    <p class="muted"><?= e(t('ges.periodHelp')) ?></p>
    <p class="muted"><?= e(t('ges.vatHelp')) ?></p>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('nav.vat')) ?>
      <span class="muted"><?= e(t('ges.vatSummary', ['count' => count($vatReturns), 'pending' => $vatSummary['pending']])) ?></span>
    </h2>
    <?php if ($vatReturns === []): ?>
      <div class="empty-state"><?= e(t('ges.noVatReturn')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.period')) ?></th>
            <th><?= e(t('ges.collected')) ?></th>
            <th><?= e(t('ges.deductible')) ?></th>
            <th><?= e(t('ges.toPay')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($vatReturns as $return): ?>
            <tr>
              <td>
                <strong><?= e($return['period_label']) ?></strong>
                <br /><span class="cell-sub"><?= e($return['regime']) ?></span>
                <?php if (!empty($return['detail'])): ?>
                  <br /><span class="cell-sub"><?= e(t('ges.byRate')) ?> :
                    <?php foreach ($return['detail'] as $line): ?>
                      <?= e((string) $line['rate']) ?> % (<?= e(t('ges.vatAmounts', [
                        'collected' => $line['collected'], 'deductible' => $line['deductible'],
                      ])) ?>)
                    <?php endforeach; ?>
                  </span>
                <?php endif; ?>
              </td>
              <td><?= e($money((float) $return['collected'])) ?></td>
              <td><?= e($money((float) $return['deductible'])) ?></td>
              <td>
                <?php if ((float) $return['credit'] > 0): ?>
                  <?= e($money((float) $return['credit'])) ?> <span class="tag"><?= e(t('common.credit')) ?></span>
                <?php else: ?>
                  <?= e($money((float) $return['due'])) ?>
                <?php endif; ?>
              </td>
              <td>
                <form method="POST" action="/gestion/tva/<?= (int) $return['id'] ?>/statut" class="inline-form">
                  <?= $csrf ?>
                  <select name="status">
                    <?php foreach ($vatStatuses as $status): ?>
                      <option value="<?= e($status) ?>"<?= $return['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
                </form>
                <?php if (!empty($return['filed_on'])): ?>
                  <span class="cell-sub"><?= e(t('ges.filedOn')) ?> <?= e(\App\Core\Dates::short((string) $return['filed_on'])) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <form method="POST" action="/gestion/tva/<?= (int) $return['id'] ?>/supprimer"
                      data-confirm="<?= e(t('ges.deleteVatReturn')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<!-- ----------------------------------------------------- Équipements -->
<section class="tab-panel" id="equipements">
  <div class="card">
    <h2><?= e(t('erp.assets')) ?></h2>
    <form method="POST" action="/gestion/equipements" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.designation')) ?></span><input type="text" name="name" required maxlength="160" /></label>
      <label><span><?= e(t('common.category')) ?></span>
        <select name="category">
          <option value=""></option>
          <?php foreach ($assetCategories as $category): ?>
            <option value="<?= e($category) ?>"><?= e($category) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.reference')) ?></span><input type="text" name="reference" maxlength="60" /></label>
      <label><span><?= e(t('ges.serialNumber')) ?></span><input type="text" name="serial_number" maxlength="80" /></label>
      <label><span><?= e(t('ges.purchase')) ?></span><input type="date" name="purchase_date" /></label>
      <label><span><?= e(t('ges.warrantyEnd')) ?></span><input type="date" name="warranty_end" /></label>
      <label><span><?= e(t('ges.value')) ?></span><input type="text" name="value" inputmode="decimal" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.add')) ?></button>
    </form>
  </div>

  <div class="card mt-l">
    <?php if ($assets === []): ?>
      <div class="empty-state"><?= e(t('erp.noAsset')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.equipment')) ?></th>
            <th><?= e(t('common.holder')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($assets as $asset): ?>
            <tr>
              <td>
                <strong><?= e($asset['name']) ?></strong>
                <br /><span class="cell-sub">
                  <?= e((string) $asset['category']) ?>
                  <?= $asset['serial_number'] ? ' · ' . e($asset['serial_number']) : '' ?>
                  <?php if (!empty($asset['warranty_end'])): ?>
                    · <?= e(t('ges.warranty')) ?> <?= e($asset['warranty_end']) ?>
                  <?php endif; ?>
                </span>
              </td>
              <td>
                <?php if (!empty($asset['holder_id'])): ?>
                  <?= e(trim($asset['holder_first_name'] . ' ' . $asset['holder_last_name'])) ?>
                  <form method="POST" action="/gestion/equipements/<?= (int) $asset['id'] ?>/reprendre" class="inline-form">
                    <?= $csrf ?>
                    <button type="submit" class="btn btn-sm"><?= e(t('ges.takeBack')) ?></button>
                  </form>
                <?php else: ?>
                  <form method="POST" action="/gestion/equipements/<?= (int) $asset['id'] ?>/affecter" class="inline-form">
                    <?= $csrf ?>
                    <select name="employee_id" required>
                      <option value=""><?= e(t('ges.assignTo')) ?></option>
                      <?php foreach ($employees as $employee): ?>
                        <option value="<?= (int) $employee['id'] ?>"><?= e(trim($employee['first_name'] . ' ' . $employee['last_name'])) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-sm"><?= e(t('common.assign')) ?></button>
                  </form>
                <?php endif; ?>
              </td>
              <td>
                <form method="POST" action="/gestion/equipements/<?= (int) $asset['id'] ?>/statut" class="inline-form">
                  <?= $csrf ?>
                  <select name="status">
                    <?php foreach ($assetStatuses as $status): ?>
                      <option value="<?= e($status) ?>"<?= $asset['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
                </form>
              </td>
              <td>
                <form method="POST" action="/gestion/equipements/<?= (int) $asset['id'] ?>/supprimer"
                      data-confirm="<?= e(t('ges.deleteEquipment')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<!-- ------------------------------------------------------ Salles -->
<section class="tab-panel" id="salles">
  <div class="grid grid-2">
    <div class="card">
      <div class="card-head"><h3><?= e(t('nav.rooms')) ?></h3></div>
      <form method="POST" action="/gestion/salles" class="stack">
        <?= \App\Core\Csrf::field() ?>
        <label>
          <span><?= e(t('common.name')) ?></span>
          <input type="text" name="name" maxlength="120" required />
        </label>
        <div class="form-grid">
          <label>
            <span><?= e(t('agenda.location')) ?></span>
            <input type="text" name="location" maxlength="140" />
          </label>
          <label>
            <span><?= e(t('ges.capacity')) ?></span>
            <input type="number" name="capacity" min="0" max="10000" value="8" />
          </label>
        </div>
        <label>
          <span><?= e(t('common.equipment')) ?></span>
          <input type="text" name="equipment" maxlength="300" placeholder="<?= e(t('ges.roomEquipmentExample')) ?>" />
        </label>
        <button type="submit" class="btn btn-primary btn-block"><?= e(t('common.create')) ?></button>
      </form>
    </div>

    <div class="stack">
      <div class="card">
        <div class="card-head">
          <h3><?= e(t('nav.rooms')) ?> <span class="muted">(<?= count($rooms) ?>)</span></h3>
        </div>
        <?php if ($rooms === []): ?>
          <div class="empty-state"><?= e(t('erp.noRoom')) ?></div>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th><?= e(t('ges.room')) ?></th><th><?= e(t('ges.capacity')) ?></th>
                <th><?= e(t('common.status')) ?></th><th class="actions"></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rooms as $room): ?>
                <?php $active = (int) $room['active'] === 1; ?>
                <tr>
                  <td>
                    <div class="cell-strong"><?= e((string) $room['name']) ?></div>
                    <div class="cell-sub">
                      <?php $details = array_filter([(string) $room['location'], (string) $room['equipment']]); ?>
                      <?= e($details === [] ? '—' : implode(' · ', $details)) ?>
                    </div>
                  </td>
                  <td class="num"><?= (int) $room['capacity'] === 0 ? '—' : (int) $room['capacity'] ?></td>
                  <td>
                    <span class="status <?= $active ? 'status-on' : 'status-off' ?>">
                      <?= e($active ? t('common.active') : t('common.inactive')) ?>
                    </span>
                  </td>
                  <td class="actions">
                    <form method="POST" action="/gestion/salles/<?= (int) $room['id'] ?>/statut" class="inline-form">
                      <?= \App\Core\Csrf::field() ?>
                      <button type="submit" class="btn btn-sm">
                        <?= e($active ? t('common.disable') : t('common.enable')) ?>
                      </button>
                    </form>
                    <form method="POST" action="/gestion/salles/<?= (int) $room['id'] ?>/supprimer" class="inline-form"
                          data-confirm="<?= e(t('ges.deleteRoom')) ?>">
                      <?= \App\Core\Csrf::field() ?>
                      <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.delete')) ?></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="card-head">
          <h3><?= e(t('erp.bookings')) ?> <span class="muted">(<?= count($roomBookings) ?>)</span></h3>
        </div>
        <?php if ($roomBookings === []): ?>
          <div class="empty-state"><?= e(t('ges.noUpcomingBooking')) ?></div>
        <?php else: ?>
          <ul class="person-list">
            <?php foreach (array_slice($roomBookings, 0, 20) as $booking): ?>
              <li class="person-row">
                <span class="person-body">
                  <span class="person-name"><?= e((string) $booking['title']) ?></span>
                  <span class="cell-sub">
                    <?= e((string) $booking['room_name']) ?> · <?= e(\App\Core\Dates::short((string) $booking['booking_date'])) ?>
                    · <?= e((string) $booking['start_time']) ?> – <?= e((string) $booking['end_time']) ?>
                    · <?= e(trim($booking['first_name'] . ' ' . $booking['last_name'])) ?>
                  </span>
                </span>
                <form method="POST" action="/gestion/reservations/<?= (int) $booking['id'] ?>/annuler"
                      data-confirm="<?= e(t('ges.cancelBooking')) ?>">
                  <?= \App\Core\Csrf::field() ?>
                  <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.cancel')) ?></button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>
