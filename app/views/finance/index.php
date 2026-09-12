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
                <strong><?= e($partner['name']) ?></strong>
                <?php if ((int) $partner['active'] !== 1): ?><span class="tag"><?= e(t('common.inactive')) ?></span><?php endif; ?>
                <?php if (!empty($partner['registration'])): ?><br /><span class="cell-sub"><?= e($partner['registration']) ?></span><?php endif; ?>
              </td>
              <td><?= e($partner['kind']) ?></td>
              <td>
                <?= e((string) $partner['contact_name']) ?>
                <?php if (!empty($partner['email'])): ?><br /><span class="cell-sub"><?= e($partner['email']) ?></span><?php endif; ?>
              </td>
              <td><?= (int) $partner['contract_count'] ?> · <?= (int) $partner['invoice_count'] ?></td>
              <td class="row-actions">
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
              <td><?= e((string) $contract['start_date']) ?> → <?= e((string) $contract['end_date']) ?></td>
              <td>
                <form method="POST" action="/gestion/contrats/<?= (int) $contract['id'] ?>/statut" class="inline-form">
                  <?= $csrf ?>
                  <select name="status" onchange="this.form.submit()">
                    <?php foreach ($contractStatuses as $status): ?>
                      <option value="<?= e($status) ?>"<?= $contract['status'] === $status ? ' selected' : '' ?>><?= e($status) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <noscript><button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button></noscript>
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
            <option value="<?= e($status) ?>"<?= $status === 'Émise' ? ' selected' : '' ?>><?= e($status) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.create')) ?></button>
    </form>
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
                <?= e((string) $invoice['due_date']) ?>
                <?php if ($invoice['overdue']): ?><br /><span class="tag tag-off"><?= e(t('ges.overdue')) ?></span><?php endif; ?>
              </td>
              <td>
                <form method="POST" action="/gestion/factures/<?= (int) $invoice['id'] ?>/statut" class="inline-form">
                  <?= $csrf ?>
                  <select name="status" onchange="this.form.submit()">
                    <?php foreach ($invoiceStatuses as $status): ?>
                      <option value="<?= e($status) ?>"<?= $invoice['status'] === $status ? ' selected' : '' ?>><?= e($status) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <noscript><button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button></noscript>
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
    <h2><?= e(t('erp.budgets')) ?> <?= (int) $year ?></h2>
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
              <td><?= e($claim['spent_on']) ?></td>
              <td><?= e($claim['category']) ?><br /><span class="cell-sub"><?= e((string) $claim['description']) ?></span></td>
              <td><?= e($money((float) $claim['amount'])) ?></td>
              <td>
                <span class="status <?= in_array($claim['status'], ['Approuvée', 'Remboursée'], true) ? 'status-on' : ($claim['status'] === 'En attente' ? 'status-wait' : 'status-off') ?>">
                  <?= e($claim['status']) ?>
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
