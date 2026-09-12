<?php
$csrf = \App\Core\Csrf::field();
$moment = static fn (?string $value): string => ($value === null || $value === '')
    ? '—' : str_replace('T', ' ', substr((string) $value, 0, 16));
$days = static fn (?string $iso): int => (int) (\App\Modules\Whistleblow::daysSince($iso) ?? 0);
?>
<?php if ($issued !== null): ?>
  <div class="card">
    <div class="card-head"><h2><?= e(t('alr.filed')) ?></h2></div>
    <ul class="org-list">
      <li class="org-item"><span><?= e(t('alr.reference')) ?></span>
        <span class="cell-strong mono"><?= e($issued['reference']) ?></span></li>
      <li class="org-item"><span><?= e(t('alr.followCode')) ?></span>
        <span class="cell-strong mono"><?= e($issued['code']) ?></span></li>
    </ul>
    <div class="flash flash-error"><?= e(t('alr.codeShownOnce')) ?></div>
    <p class="hint"><?= e(t('alr.codeUse')) ?> <a href="/alertes/suivi">/alertes/suivi</a></p>
  </div>
<?php endif; ?>

<?php if ($isReferent): ?>
  <section class="stats-grid">
    <?php foreach ([
        [(string) $summary['open'], t('alr.openReports')],
        [(string) $summary['lateAck'], t('alr.lateAck', ['days' => $ackDays])],
        [(string) $summary['lateOutcome'], t('alr.lateOutcome', ['days' => $outcomeDays])],
        [(string) $summary['total'], t('alr.totalReports')],
    ] as [$value, $label]): ?>
      <div class="stat-card">
        <span class="stat-body">
          <span class="stat-value"><?= e($value) ?></span>
          <span class="stat-label"><?= e($label) ?></span>
        </span>
      </div>
    <?php endforeach; ?>
  </section>

  <div class="card mt-l">
    <div class="card-head"><h2><?= e(t('alr.reportCount', ['count' => count($reportList)])) ?></h2></div>
    <p class="hint"><?= e(t('alr.referentHint')) ?></p>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('alr.reference')) ?></th>
            <th><?= e(t('alr.subject')) ?></th>
            <th><?= e(t('alr.category')) ?></th>
            <th><?= e(t('alr.filedAt')) ?></th>
            <th><?= e(t('alr.acknowledgement')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reportList as $report): ?>
            <tr>
              <td class="cell-strong mono">
                <a href="/alertes/signalements/<?= (int) $report['id'] ?>"><?= e($report['reference']) ?></a>
              </td>
              <td><?= e($report['subject']) ?><br />
                <span class="cell-sub">
                  <?= e((int) $report['anonymous'] === 1
                      ? t('alr.anonymous')
                      : trim(($report['first_name'] ?? '') . ' ' . ($report['last_name'] ?? ''))) ?>
                </span>
              </td>
              <td class="cell-sub"><?= e(st($report['category'])) ?></td>
              <td class="cell-sub nowrap"><?= e($moment($report['submitted_at'])) ?></td>
              <td class="<?= $report['acknowledged_at'] !== null ? 'cell-sub' : 'text-danger' ?> nowrap">
                <?= e($report['acknowledged_at'] !== null
                    ? $moment($report['acknowledged_at'])
                    : t('alr.pendingDays', ['days' => $days($report['submitted_at'])])) ?>
              </td>
              <td>
                <span class="tag <?= $report['status'] === 'Clôturée' ? '' : 'tag-warning' ?>"><?= e(st($report['status'])) ?></span>
              </td>
              <td>
                <a class="btn btn-outline btn-sm" href="/alertes/signalements/<?= (int) $report['id'] ?>">
                  <?= e(t('common.open')) ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($reportList === []): ?>
            <tr><td colspan="7" class="cell-sub"><?= e(t('alr.noReport')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="card mt-l">
  <div class="card-head"><h2><?= e(t('alr.newReport')) ?></h2></div>
  <p class="hint"><?= e(t('alr.legalNote', ['ack' => $ackDays, 'outcome' => $outcomeDays])) ?></p>
  <?php if ($referentCount === 0): ?>
    <div class="flash flash-error"><?= e(t('alr.noReferentYet')) ?></div>
  <?php endif; ?>
  <form method="POST" action="/alertes/signalements" class="form-grid">
    <?= $csrf ?>
    <label class="span-2"><span><?= e(t('alr.subject')) ?></span>
      <input type="text" name="subject" required maxlength="200" />
    </label>
    <label class="span-2"><span><?= e(t('alr.category')) ?></span>
      <select name="category">
        <?php foreach ($categories as $category): ?>
          <option value="<?= e($category) ?>"><?= e(st($category)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="span-2"><span><?= e(t('alr.body')) ?></span>
      <textarea name="body" rows="8" maxlength="10000"></textarea>
    </label>
    <label class="span-2 check-row">
      <input type="checkbox" name="anonymous" value="1" checked /><span><?= e(t('alr.anonymousLabel')) ?></span>
    </label>
    <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('alr.file')) ?></button></div>
  </form>
  <p class="hint"><?= e(t('alr.confidentiality')) ?></p>
</div>

<div class="card mt-l">
  <div class="card-head"><h2><?= e(t('alr.followTitle')) ?></h2></div>
  <p class="hint"><?= e(t('alr.followFromHere')) ?></p>
  <a class="btn btn-outline" href="/alertes/suivi"><?= e(t('alr.followOpen')) ?></a>
</div>
