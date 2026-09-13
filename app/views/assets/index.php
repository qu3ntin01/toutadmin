<?php
$csrf = \App\Core\Csrf::field();
$money = static fn (?float $value): string => $value === null ? '—' : number_format($value, 2, ',', ' ');
?>
<section class="stats-grid">
  <?php foreach ([
      [(string) $summary['count'], t('imm.inService')],
      [$money($summary['gross']), t('imm.grossValue')],
      [$money($summary['net']), t('imm.netBookValue')],
      [$money($summary['charge']), t('imm.charge') . ' ' . $currentYear],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<section class="tab-panel is-active" id="registre">
  <div class="card">
    <h2><?= e(t('imm.record')) ?></h2>
    <form method="POST" action="/immobilisations" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('common.title')) ?></span>
        <input type="text" name="label" required maxlength="160" placeholder="<?= e(t('imm.assetPlaceholder')) ?>" />
      </label>
      <label><span><?= e(t('common.category')) ?></span><input type="text" name="category" maxlength="60" /></label>
      <label><span><?= e(t('imm.acquiredOn')) ?></span><input type="date" name="acquired_on" value="<?= e($today) ?>" required /></label>
      <label><span><?= e(t('imm.amountExclVat')) ?></span><input type="text" name="amount" inputmode="decimal" required /></label>
      <label><span><?= e(t('imm.durationYears')) ?></span><input type="number" name="duration_years" min="1" max="50" value="5" required /></label>
      <label><span><?= e(t('imm.method')) ?></span>
        <select name="method" required>
          <?php foreach ($methods as $method): ?>
            <option value="<?= e($method) ?>"><?= e($method) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('common.note')) ?></span><input type="text" name="note" maxlength="500" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
    </form>
    <p class="muted"><?= e(t('imm.decliningNote')) ?></p>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('imm.tabSchedules')) ?></h2>
    <?php if ($assetList === []): ?>
      <div class="empty-state"><?= e(t('imm.noAsset')) ?></div>
    <?php else: ?>
      <?php foreach ($assetList as $asset): ?>
        <div class="org-item">
          <div class="org-head">
            <div>
              <span class="org-name">
                <?= e(t('imm.scheduleTitle', [
                    'label' => $asset['label'],
                    'method' => $asset['method'],
                    'years' => (int) $asset['duration_years'],
                ])) ?>
              </span>
              <span class="cell-sub">
                <?= e(t('imm.acquired')) ?> <?= e(\App\Core\Dates::short((string) $asset['acquired_on'])) ?>
                · <?= e($money((float) $asset['amount'])) ?>
                · <?= e(t('imm.netBookValue')) ?> <?= e($money($asset['bookValue'])) ?>
                <?php if (!empty($asset['disposed_on'])): ?>
                  · <span class="tag tag-off"><?= e(t('imm.disposedOn', ['date' => $asset['disposed_on']])) ?></span>
                <?php endif; ?>
              </span>
            </div>
            <div class="row-actions">
              <?php if (empty($asset['disposed_on'])): ?>
                <form method="POST" action="/immobilisations/<?= (int) $asset['id'] ?>/ceder" class="inline-form">
                  <?= $csrf ?>
                  <input type="date" name="disposed_on" value="<?= e($today) ?>" required />
                  <button type="submit" class="btn btn-sm"><?= e(t('imm.dispose')) ?></button>
                </form>
              <?php endif; ?>
              <form method="POST" action="/immobilisations/<?= (int) $asset['id'] ?>/supprimer"
                    data-confirm="<?= e(t('imm.confirmDelete')) ?>">
                <?= $csrf ?>
                <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
              </form>
            </div>
          </div>
          <table class="table">
            <thead>
              <tr>
                <th><?= e(t('common.fiscalYear')) ?></th>
                <th><?= e(t('imm.charge')) ?></th>
                <th><?= e(t('imm.residualValue')) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($asset['schedule'] as $row): ?>
                <tr<?= $row['year'] === $currentYear ? ' class="is-current"' : '' ?>>
                  <td><?= (int) $row['year'] ?></td>
                  <td><?= e($money($row['charge'])) ?></td>
                  <td><?= e($money($row['residual'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</section>
