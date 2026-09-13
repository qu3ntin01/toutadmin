<?php
$csrf = \App\Core\Csrf::field();
$hours = static fn (float $value): string => number_format($value, 1, ',', ' ');
$money = static fn (float $value): string => number_format($value, 2, ',', ' ');
?>
<p><a class="btn btn-sm" href="/rh#remuneration">← <?= e(t('nav.remuneration')) ?></a></p>

<section class="stats-grid">
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value"><?= e($hours($timeStats['monthHours'])) ?> h</span>
      <span class="stat-label"><?= e(t('tim.hoursThisMonth')) ?></span>
    </span>
  </div>
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value"><?= e($money($timeStats['monthEstimate'])) ?> €</span>
      <span class="stat-label"><?= e(t('tim.estimateThisMonth')) ?></span>
    </span>
  </div>
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value"><?= e($hours($timeStats['totalHours'])) ?> h</span>
      <span class="stat-label"><?= e(t('tim.totalHours')) ?></span>
    </span>
  </div>
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value"><?= e($money($timeStats['totalEstimate'])) ?> €</span>
      <span class="stat-label"><?= e(t('tim.totalEstimate')) ?></span>
    </span>
  </div>
</section>

<?php if ($openEntry !== null): ?>
  <div class="banner is-warning">
    <span><?= e(t('tim.runningSince', ['date' => date('d/m/Y H:i', (int) strtotime((string) $openEntry['clock_in']))])) ?></span>
    <form method="POST" action="/rh/temps/<?= (int) $employee['id'] ?>/cloturer">
      <?= $csrf ?>
      <button type="submit" class="btn btn-sm"><?= e(t('tim.closeNow')) ?></button>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-head">
    <div>
      <h3><?= e(t('timer.history')) ?> <span class="muted">(<?= count($entries) ?>)</span></h3>
      <p class="card-sub"><?= e(t('tim.rateNote')) ?></p>
    </div>
  </div>

  <?php if ($entries === []): ?>
    <div class="empty-state"><?= e(t('tim.noEntry')) ?></div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th><?= e(t('common.date')) ?></th><th><?= e(t('common.start')) ?></th>
          <th><?= e(t('common.end')) ?></th><th><?= e(t('common.duration')) ?></th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($entries as $entry): ?>
          <?php
            $start = (int) strtotime((string) $entry['clock_in']);
            $end = $entry['clock_out'] === null ? null : (int) strtotime((string) $entry['clock_out']);
          ?>
          <tr>
            <td class="cell-strong"><?= e(\App\Core\Dates::short(gmdate('Y-m-d', $start))) ?></td>
            <td class="cell-sub"><?= e(gmdate('H:i', $start)) ?></td>
            <td class="cell-sub"><?= $end === null ? '—' : e(gmdate('H:i', $end)) ?></td>
            <td>
              <?php if ($end === null): ?>
                <span class="status status-on"><?= e(t('status.running')) ?></span>
              <?php else: ?>
                <span class="num cell-strong"><?= e($money(($end - $start) / 3600)) ?> h</span>
              <?php endif; ?>
            </td>
            <td class="actions">
              <form method="POST" action="/rh/temps/<?= (int) $employee['id'] ?>/<?= (int) $entry['id'] ?>/supprimer"
                    data-confirm="<?= e(t('tim.confirmDelete')) ?>">
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
