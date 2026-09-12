<?php
$csrf = \App\Core\Csrf::field();
$money = static fn (?float $value): string => $value === null ? '—' : number_format($value, 2, ',', ' ');
?>
<section class="stats-grid">
  <?php foreach ([
      [$money($income['revenue']), t('cpt.revenue')],
      [$money($income['expenses']), t('cpt.expenses')],
      [$money($income['result']), t('cpt.result')],
      [(string) count($unposted), t('cpt.toPost')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<!-- ------------------------------------------------------ Plan comptable -->
<section class="tab-panel is-active" id="plan">
  <div class="card">
    <h2><?= e(t('cpt.addAccount')) ?></h2>
    <form method="POST" action="/comptabilite/comptes" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.number')) ?></span><input type="text" name="code" required maxlength="20" inputmode="numeric" /></label>
      <label><span><?= e(t('common.label')) ?></span><input type="text" name="label" required maxlength="160" /></label>
      <label><span><?= e(t('common.type')) ?></span>
        <select name="kind" required>
          <?php foreach ($accountKinds as $kind): ?>
            <option value="<?= e($kind) ?>"><?= e($kind) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.create')) ?></button>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('cpt.tabChart')) ?></h2>
    <table class="table">
      <thead>
        <tr>
          <th><?= e(t('common.number')) ?></th>
          <th><?= e(t('common.label')) ?></th>
          <th><?= e(t('common.type')) ?></th>
          <th><?= e(t('common.status')) ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($accounts as $account): ?>
          <tr>
            <td><strong><?= e($account['code']) ?></strong></td>
            <td><?= e($account['label']) ?></td>
            <td><?= e($account['kind']) ?></td>
            <td>
              <span class="status <?= (int) $account['active'] === 1 ? 'status-on' : 'status-off' ?>">
                <?= e((int) $account['active'] === 1 ? t('common.active') : t('common.inactive')) ?>
              </span>
            </td>
            <td>
              <form method="POST" action="/comptabilite/comptes/<?= (int) $account['id'] ?>/supprimer"
                    data-confirm="<?= e(t('cpt.confirmDeleteAccount')) ?>">
                <?= $csrf ?>
                <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('cpt.addJournal')) ?></h2>
    <form method="POST" action="/comptabilite/journaux" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.code')) ?></span><input type="text" name="code" required maxlength="6" /></label>
      <label><span><?= e(t('common.label')) ?></span><input type="text" name="label" required maxlength="120" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.create')) ?></button>
    </form>
    <ul class="tag-list">
      <?php foreach ($journals as $journal): ?>
        <li class="tag"><?= e($journal['code']) ?> — <?= e($journal['label']) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>

<!-- ---------------------------------------------------------- Écritures -->
<section class="tab-panel" id="ecritures">
  <div class="card">
    <h2><?= e(t('cpt.newEntry')) ?></h2>
    <p class="muted"><?= e(t('cpt.balanceRule')) ?></p>
    <form method="POST" action="/comptabilite/ecritures" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('cpt.journal')) ?></span>
        <select name="journal_id" required>
          <?php foreach ($journals as $journal): ?>
            <option value="<?= (int) $journal['id'] ?>"><?= e($journal['code']) ?> — <?= e($journal['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.date')) ?></span><input type="date" name="entry_date" value="<?= e($today) ?>" required /></label>
      <label><span><?= e(t('common.title')) ?></span><input type="text" name="label" required maxlength="160" /></label>
      <label><span><?= e(t('common.reference')) ?></span><input type="text" name="reference" maxlength="60" /></label>

      <?php for ($line = 0; $line < 4; $line++): ?>
        <div class="span-2 entry-line">
          <select name="account_id[]">
            <option value=""><?= e(t('common.account')) ?></option>
            <?php foreach ($accounts as $account): ?>
              <?php if ((int) $account['active'] === 1): ?>
                <option value="<?= (int) $account['id'] ?>"><?= e($account['code']) ?> — <?= e($account['label']) ?></option>
              <?php endif; ?>
            <?php endforeach; ?>
          </select>
          <input type="text" name="line_label[]" maxlength="160" placeholder="<?= e(t('cpt.labelPlaceholder')) ?>" />
          <input type="text" name="debit[]" inputmode="decimal" placeholder="<?= e(t('cpt.debitPlaceholder')) ?>" />
          <input type="text" name="credit[]" inputmode="decimal" placeholder="<?= e(t('cpt.creditPlaceholder')) ?>" />
        </div>
      <?php endfor; ?>

      <button type="submit" class="btn btn-primary"><?= e(t('cpt.saveEntry')) ?></button>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('cpt.toPost')) ?></h2>
    <p class="muted"><?= e(t('cpt.postingRule')) ?></p>
    <?php if ($unposted === []): ?>
      <div class="empty-state"><?= e(t('cpt.allPosted')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr><th><?= e(t('ges.invoice')) ?></th><th><?= e(t('erp.amount')) ?></th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($unposted as $invoice): ?>
            <tr>
              <td>
                <?= e($invoice['label']) ?>
                <br /><span class="cell-sub"><?= e($invoice['direction']) ?> · <?= e($invoice['issue_date']) ?></span>
              </td>
              <td><?= e($money($invoice['amount_base_ttc'])) ?></td>
              <td>
                <form method="POST" action="/comptabilite/factures/<?= (int) $invoice['id'] ?>/comptabiliser">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm btn-primary"><?= e(t('cpt.post')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('cpt.entryJournal')) ?></h2>
    <?php if ($entries === []): ?>
      <div class="empty-state"><?= e(t('cpt.noEntry')) ?></div>
    <?php else: ?>
      <?php foreach ($entries as $entry): ?>
        <div class="org-item">
          <div class="org-head">
            <div>
              <span class="org-name"><?= e($entry['entry_date']) ?> · <?= e($entry['label']) ?></span>
              <span class="cell-sub">
                <?= e($entry['journal_code']) ?>
                <?= $entry['reference'] ? ' · ' . e($entry['reference']) : '' ?>
                · <?= e($money((float) $entry['total_debit'])) ?>
              </span>
            </div>
            <form method="POST" action="/comptabilite/ecritures/<?= (int) $entry['id'] ?>/supprimer"
                  data-confirm="<?= e(t('cpt.confirmDeleteEntry')) ?>">
              <?= $csrf ?>
              <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
            </form>
          </div>
          <table class="table">
            <thead>
              <tr>
                <th><?= e(t('common.account')) ?></th><th><?= e(t('common.label')) ?></th>
                <th><?= e(t('cpt.debit')) ?></th><th><?= e(t('common.credit')) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (\App\Modules\Accounting::linesOf((int) $entry['id']) as $line): ?>
                <tr>
                  <td><?= e($line['code']) ?> — <?= e($line['account_label']) ?></td>
                  <td><?= e((string) $line['label']) ?></td>
                  <td><?= (float) $line['debit'] > 0 ? e($money((float) $line['debit'])) : '' ?></td>
                  <td><?= (float) $line['credit'] > 0 ? e($money((float) $line['credit'])) : '' ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>

<!-- ------------------------------------------------------------ Balance -->
<section class="tab-panel" id="balance">
  <div class="card">
    <h2><?= e(t('cpt.trialBalance')) ?> <?= (int) $year ?></h2>
    <p class="muted"><?= e(t('cpt.balanceNote')) ?></p>
    <form method="GET" action="/comptabilite" class="inline-form">
      <select name="annee">
        <?php foreach ($years as $option): ?>
          <option value="<?= (int) $option ?>"<?= $option === $year ? ' selected' : '' ?>><?= (int) $option ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm"><?= e(t('common.open')) ?></button>
      <a class="btn btn-sm" href="/comptabilite/balance.csv?annee=<?= (int) $year ?>"><?= e(t('cpt.exportCsv')) ?></a>
    </form>

    <?php if ($balance === []): ?>
      <div class="empty-state"><?= e(t('cpt.noMovementYear')) ?></div>
    <?php else: ?>
      <table class="table mt-l">
        <thead>
          <tr>
            <th><?= e(t('common.account')) ?></th><th><?= e(t('common.type')) ?></th>
            <th><?= e(t('cpt.debit')) ?></th><th><?= e(t('common.credit')) ?></th><th><?= e(t('erp.balance')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($balance as $row): ?>
            <tr>
              <td><strong><?= e($row['code']) ?></strong> <?= e($row['label']) ?></td>
              <td><?= e($row['kind']) ?></td>
              <td><?= e($money($row['debit'])) ?></td>
              <td><?= e($money($row['credit'])) ?></td>
              <td><?= e($money($row['balance'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <p class="muted"><?= e(t('cpt.exportNote')) ?></p>
  </div>
</section>

<!-- -------------------------------------------------------- Grand livre -->
<section class="tab-panel" id="grandlivre">
  <div class="card">
    <h2><?= e(t('cpt.tabLedger')) ?></h2>
    <p class="muted"><?= e(t('cpt.ledgerNote')) ?></p>
    <form method="GET" action="/comptabilite" class="inline-form">
      <input type="hidden" name="annee" value="<?= (int) $year ?>" />
      <select name="compte" required>
        <option value=""><?= e(t('common.choose')) ?></option>
        <?php foreach ($accounts as $account): ?>
          <option value="<?= (int) $account['id'] ?>"<?= $selectedAccount !== null && (int) $selectedAccount['id'] === (int) $account['id'] ? ' selected' : '' ?>>
            <?= e($account['code']) ?> — <?= e($account['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm"><?= e(t('common.open')) ?></button>
    </form>

    <?php if ($selectedAccount === null): ?>
      <div class="empty-state"><?= e(t('cpt.chooseAccount')) ?></div>
    <?php elseif ($ledger === []): ?>
      <div class="empty-state"><?= e(t('cpt.noMovementAccount', ['year' => $year])) ?></div>
    <?php else: ?>
      <table class="table mt-l">
        <thead>
          <tr>
            <th><?= e(t('common.date')) ?></th><th><?= e(t('cpt.journal')) ?></th><th><?= e(t('common.label')) ?></th>
            <th><?= e(t('cpt.debit')) ?></th><th><?= e(t('common.credit')) ?></th><th><?= e(t('erp.balance')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ledger as $line): ?>
            <tr>
              <td><?= e($line['entry_date']) ?></td>
              <td><?= e($line['journal_code']) ?></td>
              <td><?= e($line['label'] ?: $line['entry_label']) ?></td>
              <td><?= (float) $line['debit'] > 0 ? e($money((float) $line['debit'])) : '' ?></td>
              <td><?= (float) $line['credit'] > 0 ? e($money((float) $line['credit'])) : '' ?></td>
              <td><?= e($money($line['running'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>
