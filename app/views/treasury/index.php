<?php
$csrf = \App\Core\Csrf::field();
$money = static fn (?float $value): string => $value === null ? '—' : number_format($value, 2, ',', ' ');
?>
<section class="stats-grid">
  <?php foreach ([
      [$money($total), t('tre.consolidated')],
      [$money($projection['end']), t('tre.projected12')],
      [$money($projection['lowest']['balance']), $projection['lowest']['on_date'] === null
          ? t('tre.lowPointToday')
          : t('tre.lowPointOn', ['date' => $projection['lowest']['on_date']])],
      [(string) count($pending), t('tre.unreconciled')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<!-- ------------------------------------------------------------ Comptes -->
<section class="tab-panel is-active" id="comptes">
  <div class="card">
    <h2><?= e(t('tre.tabAccounts')) ?></h2>
    <form method="POST" action="/tresorerie/comptes" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.title')) ?></span>
        <input type="text" name="label" required maxlength="120" placeholder="<?= e(t('tre.accountPlaceholder')) ?>" />
      </label>
      <label><span><?= e(t('tre.bank')) ?></span><input type="text" name="bank" maxlength="80" /></label>
      <label><span>IBAN</span><input type="text" name="iban_last4" maxlength="34" inputmode="numeric" /></label>
      <label><span><?= e(t('tre.openingBalance')) ?></span><input type="text" name="opening_balance" inputmode="decimal" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.add')) ?></button>
    </form>
    <p class="muted"><?= e(t('tre.ibanNote')) ?></p>
  </div>

  <div class="card mt-l">
    <?php if ($accountList === []): ?>
      <div class="empty-state"><?= e(t('tre.noAccount')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.account')) ?></th>
            <th><?= e(t('tre.bank')) ?></th>
            <th><?= e(t('erp.balance')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($accountList as $account): ?>
            <tr>
              <td>
                <strong><?= e($account['label']) ?></strong>
                <?php if (!empty($account['iban_last4'])): ?>
                  <br /><span class="cell-sub">•••• <?= e($account['iban_last4']) ?></span>
                <?php endif; ?>
              </td>
              <td><?= e((string) $account['bank']) ?></td>
              <td><strong><?= e($money($account['balance'])) ?></strong></td>
              <td class="row-actions">
                <a class="btn btn-sm" href="/tresorerie?compte=<?= (int) $account['id'] ?>#mouvements"><?= e(t('common.open')) ?></a>
                <form method="POST" action="/tresorerie/comptes/<?= (int) $account['id'] ?>/cloturer" class="inline-form">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm"><?= e(t('tre.close')) ?></button>
                </form>
                <form method="POST" action="/tresorerie/comptes/<?= (int) $account['id'] ?>/supprimer"
                      data-confirm="<?= e(t('tre.confirmDeleteAccount')) ?>">
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

<!-- --------------------------------------------------------- Mouvements -->
<section class="tab-panel" id="mouvements">
  <div class="card">
    <h2><?= e(t('tre.newMovement')) ?></h2>
    <form method="POST" action="/tresorerie/mouvements" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.account')) ?></span>
        <select name="account_id" required>
          <?php foreach ($accountList as $account): ?>
            <option value="<?= (int) $account['id'] ?>"<?= $selectedAccount === (int) $account['id'] ? ' selected' : '' ?>>
              <?= e($account['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('tre.valueDate')) ?></span><input type="date" name="value_date" value="<?= e($today) ?>" required /></label>
      <label><span><?= e(t('common.label')) ?></span>
        <input type="text" name="label" required maxlength="160" placeholder="<?= e(t('tre.movementPlaceholder')) ?>" />
      </label>
      <label><span><?= e(t('tre.amountEuro')) ?></span><input type="text" name="amount" inputmode="decimal" required /></label>
      <label><span><?= e(t('common.category')) ?></span><input type="text" name="category" maxlength="60" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.record')) ?></button>
    </form>
    <p class="muted"><?= e(t('tre.signNote')) ?></p>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('common.movements')) ?></h2>
    <table class="table">
      <thead>
        <tr>
          <th><?= e(t('tre.valueDate')) ?></th>
          <th><?= e(t('common.label')) ?></th>
          <th><?= e(t('common.account')) ?></th>
          <th><?= e(t('erp.amount')) ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($movements as $movement): ?>
          <tr>
            <td><?= e($movement['value_date']) ?></td>
            <td>
              <?= e($movement['label']) ?>
              <?php if (!empty($movement['invoice_id'])): ?>
                <span class="tag"><?= e(t('tre.reconciled')) ?><?= $movement['invoice_reference'] ? ' · ' . e($movement['invoice_reference']) : '' ?></span>
              <?php endif; ?>
            </td>
            <td><?= e($movement['account_label']) ?></td>
            <td><?= e($money((float) $movement['amount'])) ?></td>
            <td>
              <form method="POST" action="/tresorerie/mouvements/<?= (int) $movement['id'] ?>/supprimer"
                    data-confirm="<?= e(t('tre.confirmDeleteMovement')) ?>">
                <?= $csrf ?>
                <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<!-- ------------------------------------------------------ Rapprochement -->
<section class="tab-panel" id="rapprochement">
  <div class="card">
    <h2><?= e(t('tre.toReconcileCount', ['count' => count($pending)])) ?></h2>
    <p class="muted"><?= e(t('tre.reconcileNote')) ?></p>
    <?php if ($pending === []): ?>
      <div class="empty-state"><?= e(t('tre.allReconciled')) ?></div>
    <?php else: ?>
      <?php foreach ($pending as $movement): ?>
        <form method="POST" action="/tresorerie/mouvements/<?= (int) $movement['id'] ?>/rapprocher" class="form-grid">
          <?= $csrf ?>
          <label class="span-2">
            <span><?= e($movement['value_date']) ?> · <?= e($movement['label']) ?></span>
            <input type="text" readonly value="<?= e($money((float) $movement['amount'])) ?> · <?= e($movement['account_label']) ?>" />
          </label>
          <label><span><?= e(t('ges.invoice')) ?></span>
            <select name="invoice_id" required>
              <option value=""><?= e(t('common.choose')) ?></option>
              <?php foreach ($openInvoices as $invoice): ?>
                <option value="<?= (int) $invoice['id'] ?>">
                  <?= e($invoice['reference'] ?: $invoice['label']) ?> — <?= e($invoice['direction']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <button type="submit" class="btn btn-primary"><?= e(t('tre.reconcile')) ?></button>
        </form>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<!-- -------------------------------------------------------- Prévisionnel -->
<section class="tab-panel" id="previsions">
  <div class="card">
    <h2><?= e(t('tre.addDue')) ?></h2>
    <form method="POST" action="/tresorerie/previsions" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.label')) ?></span>
        <input type="text" name="label" required maxlength="160" placeholder="<?= e(t('tre.duePlaceholder')) ?>" />
      </label>
      <label><span><?= e(t('tre.expectedOn')) ?></span><input type="date" name="expected_on" required /></label>
      <label><span><?= e(t('tre.amountEuro')) ?></span><input type="text" name="amount" inputmode="decimal" required /></label>
      <label><span><?= e(t('tre.certainty')) ?></span>
        <select name="certainty" required>
          <?php foreach ($certainties as $certainty): ?>
            <option value="<?= e($certainty) ?>"><?= e($certainty) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('common.note')) ?></span><input type="text" name="note" maxlength="300" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.add')) ?></button>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('tre.dueList')) ?></h2>
    <?php if ($forecastList === []): ?>
      <div class="empty-state"><?= e(t('tre.noDue')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('tre.expectedOn')) ?></th><th><?= e(t('common.label')) ?></th>
            <th><?= e(t('erp.amount')) ?></th><th><?= e(t('tre.certainty')) ?></th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($forecastList as $forecast): ?>
            <tr>
              <td><?= e($forecast['expected_on']) ?></td>
              <td><?= e($forecast['label']) ?></td>
              <td><?= e($money((float) $forecast['amount'])) ?></td>
              <td><span class="tag"><?= e($forecast['certainty']) ?></span></td>
              <td>
                <form method="POST" action="/tresorerie/previsions/<?= (int) $forecast['id'] ?>/supprimer">
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

  <div class="card mt-l">
    <h2><?= e(t('tre.projection')) ?></h2>
    <p class="muted"><?= e(t('tre.projectionNote', ['start' => $money($projection['start'])])) ?></p>
    <?php if ($projection['points'] === []): ?>
      <div class="empty-state"><?= e(t('tre.nothingPlanned')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.date')) ?></th><th><?= e(t('common.label')) ?></th>
            <th><?= e(t('common.origin')) ?></th><th><?= e(t('erp.amount')) ?></th>
            <th><?= e(t('tre.projectedBalance')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($projection['points'] as $point): ?>
            <tr>
              <td><?= e((string) $point['on_date']) ?></td>
              <td><?= e((string) $point['label']) ?></td>
              <td><span class="tag"><?= e((string) $point['origin']) ?></span></td>
              <td><?= e($money((float) $point['amount'])) ?></td>
              <td><?= e($money((float) $point['balance'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>
