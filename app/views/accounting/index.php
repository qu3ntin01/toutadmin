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

  <div class="card">
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

  <div class="card">
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

  <div class="card">
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
                <br /><span class="cell-sub"><?= e($invoice['direction']) ?> · <?= e(\App\Core\Dates::short((string) $invoice['issue_date'])) ?></span>
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

  <div class="card">
    <h2><?= e(t('cpt.entryJournal')) ?></h2>
    <?php if ($entries === []): ?>
      <div class="empty-state"><?= e(t('cpt.noEntry')) ?></div>
    <?php else: ?>
      <?php foreach ($entries as $entry): ?>
        <div class="org-item">
          <div class="org-head">
            <div>
              <span class="org-name"><?= e(\App\Core\Dates::short((string) $entry['entry_date'])) ?> · <?= e($entry['label']) ?></span>
              <span class="cell-sub">
                <?= e($entry['journal_code']) ?>
                <?= $entry['reference'] ? ' · ' . e($entry['reference']) : '' ?>
                · <?= e($money((float) $entry['total_debit'])) ?>
              </span>
              <?php /* Le numéro n'existe qu'à la validation : avant, l'écriture est au brouillard. */ ?>
              <?php if ($entry['validated_at'] !== null): ?>
                <span class="tag mono"><?= e(t('cpt.entryNumber', ['n' => (int) $entry['number']])) ?></span>
              <?php else: ?>
                <span class="status status-warning"><?= e(t('cpt.draft')) ?></span>
              <?php endif; ?>
            </div>
            <?php if ($entry['validated_at'] === null): ?>
              <form method="POST" action="/comptabilite/ecritures/<?= (int) $entry['id'] ?>/supprimer"
                    data-confirm="<?= e(t('cpt.confirmDeleteEntry')) ?>">
                <?= $csrf ?>
                <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
              </form>
            <?php elseif ($entry['reversed_by'] === null): ?>
              <?php /* Une écriture validée ne se supprime pas : elle s'annule par son inverse. */ ?>
              <form method="POST" action="/comptabilite/ecritures/<?= (int) $entry['id'] ?>/contrepasser" class="inline-form">
                <?= $csrf ?>
                <label class="inline-form"><span class="cell-sub"><?= e(t('cpt.reverseOn')) ?></span>
                  <input type="date" name="entry_date" value="<?= $lockedUntil !== null && $today <= $lockedUntil ? '' : e($today) ?>"
                         min="<?= e((string) $lockedUntil) ?>" class="input-sm" required></label>
                <button type="submit" class="btn btn-sm"><?= e(t('cpt.reverse')) ?></button>
              </form>
            <?php else: ?>
              <span class="cell-sub"><?= e(t('cpt.reversed')) ?></span>
            <?php endif; ?>
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
      <table class="table">
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
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.date')) ?></th><th><?= e(t('cpt.journal')) ?></th><th><?= e(t('common.label')) ?></th>
            <th><?= e(t('cpt.debit')) ?></th><th><?= e(t('common.credit')) ?></th><th><?= e(t('erp.balance')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ledger as $line): ?>
            <tr>
              <td><?= e(\App\Core\Dates::short((string) $line['entry_date'])) ?></td>
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

<section class="tab-panel" id="lettrage">
  <div class="card">
    <h2><?= e(t('cpt.letteringTitle')) ?></h2>
    <p class="muted"><?= e(t('cpt.letteringNote')) ?></p>
    <form method="GET" action="/comptabilite" class="inline-form">
      <input type="hidden" name="annee" value="<?= (int) $year ?>" />
      <select name="lettrage" required aria-label="<?= e(t('cpt.letteringAccount')) ?>">
        <option value=""><?= e(t('common.choose')) ?></option>
        <?php foreach ($lettrables as $account): ?>
          <option value="<?= (int) $account['id'] ?>"<?= $compteLettrage !== null && (int) $compteLettrage['id'] === (int) $account['id'] ? ' selected' : '' ?>>
            <?= e($account['code']) ?> — <?= e($account['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm"><?= e(t('common.open')) ?></button>
    </form>

    <?php if ($compteLettrage === null): ?>
      <div class="empty-state"><?= e(t('cpt.letteringChoose')) ?></div>
    <?php elseif ($lettrage['ouvertes'] === []): ?>
      <div class="empty-state"><?= e(t('cpt.letteringNothing')) ?></div>
    <?php else: ?>
      <form method="POST" action="/comptabilite/lettrage">
        <?= $csrf ?>
        <input type="hidden" name="account_id" value="<?= (int) $compteLettrage['id'] ?>" />
        <table class="table">
          <thead>
            <tr>
              <th></th><th><?= e(t('common.date')) ?></th><th><?= e(t('cpt.journal')) ?></th><th><?= e(t('common.label')) ?></th>
              <th><?= e(t('cpt.debit')) ?></th><th><?= e(t('common.credit')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($lettrage['ouvertes'] as $line): ?>
              <tr>
                <td><input type="checkbox" name="lignes[]" value="<?= (int) $line['id'] ?>" aria-label="<?= e((string) $line['entry_label']) ?>" /></td>
                <td><?= e(\App\Core\Dates::short((string) $line['entry_date'])) ?></td>
                <td><?= e($line['journal_code']) ?><?php if ($line['number'] !== null): ?> <span class="cell-sub">n° <?= (int) $line['number'] ?></span><?php endif; ?></td>
                <td>
                  <?= e((string) $line['entry_label']) ?>
                  <div class="cell-sub"><?= e(implode(' · ', array_filter([(string) $line['reference'], (string) ($line['partner_name'] ?? ''), (string) $line['label']]))) ?></div>
                </td>
                <td><?= (float) $line['debit'] > 0 ? e($money((float) $line['debit'])) : '' ?></td>
                <td><?= (float) $line['credit'] > 0 ? e($money((float) $line['credit'])) : '' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot><tr><td colspan="4"><strong><?= e(t('cpt.letteringOpen')) ?></strong></td><td colspan="2"><strong><?= e($money($lettrage['solde'])) ?></strong></td></tr></tfoot>
        </table>
        <button type="submit" class="btn btn-sm btn-primary"><?= e(t('cpt.letteringSubmit')) ?></button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($compteLettrage !== null && $lettrage['lettrages'] !== []): ?>
    <div class="card">
      <h2><?= e(t('cpt.letteringDone')) ?></h2>
      <table class="table">
        <thead><tr><th><?= e(t('cpt.letteringCode')) ?></th><th><?= e(t('common.date')) ?></th><th><?= e(t('cpt.letteringLines')) ?></th><th><?= e(t('erp.amount')) ?></th><th></th></tr></thead>
        <tbody>
          <?php foreach ($lettrage['lettrages'] as $g): ?>
            <tr>
              <td><span class="tag"><?= e($g['code']) ?></span></td>
              <td><?= e($g['date'] !== null ? \App\Core\Dates::short((string) $g['date']) : '—') ?></td>
              <td><?= (int) $g['lignes'] ?></td>
              <td><?= e($money($g['debit'])) ?></td>
              <td>
                <form method="POST" action="/comptabilite/lettrage/retirer" data-confirm="<?= e(t('cpt.letteringConfirmUndo')) ?>">
                  <?= $csrf ?>
                  <input type="hidden" name="account_id" value="<?= (int) $compteLettrage['id'] ?>" />
                  <input type="hidden" name="code" value="<?= e($g['code']) ?>" />
                  <button type="submit" class="btn btn-sm"><?= e(t('cpt.letteringUndo')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="tab-panel" id="cloture">
  <div class="grid-2">
    <div class="card">
      <h2><?= e(t('cpt.closingTitle')) ?></h2>
      <p class="card-sub"><?= e(t('cpt.closingNote')) ?></p>
      <dl class="summary-list">
        <div><dt><?= e(t('cpt.lockedLabel')) ?></dt>
          <dd><?= $lockedUntil !== null ? e(\App\Core\Dates::short($lockedUntil)) : e(t('cpt.notLocked')) ?></dd></div>
        <div><dt><?= e(t('cpt.draft')) ?></dt><dd><?= e(t('cpt.draftsCount', ['n' => $drafts])) ?></dd></div>
      </dl>
      <form method="POST" action="/comptabilite/valider" class="inline-form" data-confirm="<?= e(t('cpt.confirmValidate')) ?>">
        <?= $csrf ?>
        <label class="inline-form"><span><?= e(t('cpt.validateUntil')) ?></span>
          <input type="date" name="date" value="<?= e($today) ?>" max="<?= e($today) ?>" min="<?= e((string) $lockedUntil) ?>" required></label>
        <button type="submit" class="btn btn-sm btn-primary"><?= e(t('cpt.validate')) ?></button>
      </form>
    </div>

    <div class="card">
      <h2><?= e(t('cpt.openingTitle')) ?></h2>
      <p class="card-sub"><?= e(t('cpt.openingNote')) ?></p>
      <form method="POST" action="/comptabilite/a-nouveaux" class="inline-form" data-confirm="<?= e(t('cpt.openingConfirm')) ?>">
        <?= $csrf ?>
        <label class="inline-form"><span><?= e(t('cpt.openingYear')) ?></span>
          <input type="number" name="annee" value="<?= (int) substr($today, 0, 4) ?>" min="1900" max="9999" step="1" required></label>
        <button type="submit" class="btn btn-sm"><?= e(t('cpt.openingSubmit')) ?></button>
      </form>
    </div>

    <div class="card">
      <h2><?= e(t('cpt.fecTitle')) ?></h2>
      <p class="card-sub"><?= e(t('cpt.fecNote')) ?></p>
      <?php if ($siren === null): ?>
        <div class="flash flash-info"><?= e(t('cpt.fecNoSiren')) ?></div>
      <?php endif; ?>
      <form method="GET" action="/comptabilite/fec" class="form-grid">
        <label><span><?= e(t('cpt.fecFrom')) ?></span><input type="date" name="du" value="<?= (int) $year ?>-01-01" required></label>
        <label><span><?= e(t('cpt.fecTo')) ?></span><input type="date" name="au" value="<?= (int) $year ?>-12-31" required></label>
        <div class="form-actions span-2">
          <button type="submit" class="btn btn-sm"<?= $siren === null ? ' disabled' : '' ?>><?= e(t('cpt.fecDownload')) ?></button>
        </div>
      </form>
    </div>
  </div>
</section>
