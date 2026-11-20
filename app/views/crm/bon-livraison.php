<?php
$qte = static fn (float $n): string => rtrim(rtrim(number_format($n, 3, ',', ' '), '0'), ',');
?>
<div class="card">
  <div class="grid-2">
    <div>
      <h2><?= e((string) ($bon['societe']['name'] ?? '')) ?></h2>
      <?php if (($bon['societe']['registration'] ?? '') !== ''): ?><div class="cell-sub"><?= e((string) $bon['societe']['registration']) ?></div><?php endif; ?>
    </div>
    <div>
      <div class="cell-sub"><?= e(t('ven.deliveredTo')) ?></div>
      <strong><?= e($bon['partner_name']) ?></strong>
      <?php if ((string) ($bon['partner_address'] ?? '') !== ''): ?><div class="cell-sub"><?= e((string) $bon['partner_address']) ?></div><?php endif; ?>
    </div>
  </div>
  <dl class="summary-list">
    <div><dt><?= e(t('ven.deliveryNote')) ?></dt><dd class="mono"><?= e($bon['reference']) ?></dd></div>
    <div><dt><?= e(t('ven.order')) ?></dt><dd class="mono"><?= e($bon['order_reference']) ?></dd></div>
    <div><dt><?= e(t('ven.deliveredOn')) ?></dt><dd><?= e(\App\Core\Dates::day((string) $bon['delivered_on'])) ?></dd></div>
  </dl>
  <table class="table">
    <thead><tr><th><?= e(t('common.reference')) ?></th><th><?= e(t('common.label')) ?></th><th><?= e(t('ven.qty')) ?></th></tr></thead>
    <tbody>
      <?php foreach ($bon['lignes'] as $l): ?>
        <tr><td class="mono"><?= e((string) ($l['product_reference'] ?? '—')) ?></td><td><?= e($l['label']) ?></td><td><?= e($qte((float) $l['quantity']) . ' ' . $l['unit']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="cell-sub"><?= e(t('ven.receivedBy')) ?></p>
</div>
