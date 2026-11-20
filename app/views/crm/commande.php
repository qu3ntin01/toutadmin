<?php
$csrf = \App\Core\Csrf::field();
$money = static fn (mixed $value): string => number_format((float) ($value ?? 0), 2, ',', ' ') . ' €';
$qte = static fn (float $n): string => rtrim(rtrim(number_format($n, 3, ',', ' '), '0'), ',');
$etatCle = ['Brouillon' => 'draft', 'Confirmée' => 'confirmed', 'Partiellement livrée' => 'partial', 'Livrée' => 'delivered', 'Facturée' => 'invoiced', 'Annulée' => 'cancelled'];
$brouillon = $commande['status'] === 'Brouillon';
$confirmee = $commande['status'] === 'Confirmée';
$entamee = array_filter($commande['lignes'], static fn (array $l): bool => $l['delivered'] > 0 || $l['invoiced'] > 0) !== [];
?>
<section class="stats-grid">
  <?php foreach ([
      [t('ven.state.' . $etatCle[$commande['etat']]), t('common.status')],
      [$money($commande['totalHt']), t('ven.totalHt')],
      [$money($commande['totalTtc']), t('ven.totalTtc')],
      [\App\Core\Dates::short((string) $commande['order_date']), t('common.date')],
  ] as [$valeur, $libelle]): ?>
    <div class="stat-card"><span class="stat-body"><span class="stat-value"><?= e($valeur) ?></span><span class="stat-label"><?= e($libelle) ?></span></span></div>
  <?php endforeach; ?>
</section>

<div class="card">
  <h2><?= e(t('ven.lines')) ?></h2>
  <div class="inline-form">
    <?php if ($brouillon): ?>
      <form method="POST" action="/crm/commandes/<?= (int) $commande['id'] ?>/confirmer" class="inline-form">
        <?= $csrf ?>
        <button type="submit" class="btn btn-primary btn-sm"<?= $commande['lignes'] === [] ? ' disabled' : '' ?>><?= e(t('ven.confirm')) ?></button>
      </form>
    <?php endif; ?>
    <?php if ($commande['status'] !== 'Annulée' && !$entamee): ?>
      <form method="POST" action="/crm/commandes/<?= (int) $commande['id'] ?>/annuler" class="inline-form" data-confirm="<?= e(t('ven.confirmCancel')) ?>">
        <?= $csrf ?>
        <button type="submit" class="btn btn-danger btn-sm"><?= e(t('ven.cancel')) ?></button>
      </form>
    <?php endif; ?>
  </div>

  <?php if ($commande['lignes'] === []): ?>
    <div class="empty-state"><?= e(t('ven.noLine')) ?></div>
  <?php else: ?>
    <form method="POST" action="/crm/commandes/<?= (int) $commande['id'] ?>/livrer" id="livrer"></form>
    <table class="table">
      <thead><tr>
        <th><?= e(t('common.label')) ?></th><th><?= e(t('ven.qty')) ?></th><th><?= e(t('ven.unitPrice')) ?></th>
        <th><?= e(t('ven.totalHt')) ?></th><th><?= e(t('ven.delivered')) ?></th><th><?= e(t('ven.invoiced')) ?></th>
        <?php if ($confirmee): ?><th><?= e(t('ven.toDeliver')) ?></th><?php endif; ?><?php if ($brouillon): ?><th></th><?php endif; ?>
      </tr></thead>
      <tbody>
        <?php foreach ($commande['lignes'] as $l): ?>
          <tr>
            <td><strong><?= e($l['label']) ?></strong><?php if ($l['product_reference'] !== null): ?><div class="cell-sub mono"><?= e($l['product_reference']) ?></div><?php endif; ?></td>
            <td><?= e($qte($l['quantity'])) ?> <span class="cell-sub"><?= e($l['unit']) ?></span></td>
            <td><?= e($money($l['unit_price_ht'])) ?><div class="cell-sub">TVA <?= e((string) $l['vat_rate']) ?> %</div></td>
            <td><strong><?= e($money($l['total'])) ?></strong></td>
            <td><?= e($qte($l['delivered'])) ?></td>
            <td><?= e($qte($l['invoiced'])) ?></td>
            <?php if ($confirmee): ?>
              <td><?php if ($l['remaining'] > 0): ?><input type="text" inputmode="decimal" name="q_<?= (int) $l['id'] ?>" form="livrer" size="6" placeholder="<?= e($qte($l['remaining'])) ?>" aria-label="<?= e(t('ven.toDeliver') . ' — ' . $l['label']) ?>" /><?php else: ?>—<?php endif; ?></td>
            <?php endif; ?>
            <?php if ($brouillon): ?>
              <td>
                <form method="POST" action="/crm/commandes/lignes/<?= (int) $l['id'] ?>/retirer" class="inline-form">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm"><?= e(t('ven.removeLine')) ?></button>
                </form>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php if ($confirmee && array_filter($commande['lignes'], static fn (array $l): bool => $l['remaining'] > 0) !== []): ?>
      <div class="inline-form">
        <input type="hidden" name="_csrf" value="<?= e(\App\Core\Csrf::token()) ?>" form="livrer" />
        <label class="inline-form"><span><?= e(t('ven.deliveredOn')) ?></span><input type="date" name="delivered_on" value="<?= e($today) ?>" max="<?= e($today) ?>" form="livrer" required /></label>
        <button type="submit" class="btn btn-primary btn-sm" form="livrer"><?= e(t('ven.deliver')) ?></button>
      </div>
    <?php endif; ?>
    <?php if ($confirmee && array_filter($commande['lignes'], static fn (array $l): bool => $l['toInvoice'] > 0) !== []): ?>
      <form method="POST" action="/crm/commandes/<?= (int) $commande['id'] ?>/facturer" class="inline-form">
        <?= $csrf ?>
        <button type="submit" class="btn btn-sm"><?= e(t('ven.invoiceDelivered')) ?></button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($brouillon): ?>
  <div class="card">
    <h2><?= e(t('ven.addLine')) ?></h2>
    <p class="card-sub"><?= e(t('ven.addLineNote')) ?></p>
    <form method="POST" action="/crm/commandes/<?= (int) $commande['id'] ?>/lignes" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('ven.product')) ?></span>
        <select name="product_id">
          <option value="">— <?= e(t('ven.freeLine')) ?> —</option>
          <?php foreach ($produits as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['reference'] . ' — ' . $p['name'] . ' (' . $money((float) $p['price_ht']) . ')') ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('common.label')) ?></span><input type="text" name="label" maxlength="200" /></label>
      <label><span><?= e(t('ven.qty')) ?></span><input type="text" name="quantity" inputmode="decimal" value="1" required /></label>
      <label><span><?= e(t('ven.unitPrice')) ?></span><input type="text" name="unit_price_ht" inputmode="decimal" /></label>
      <label><span>TVA (%)</span><input type="number" name="vat_rate" min="0" max="100" step="0.1" /></label>
      <button type="submit" class="btn btn-primary btn-sm"><?= e(t('ven.addLine')) ?></button>
    </form>
  </div>
<?php endif; ?>

<div class="grid-2">
  <div class="card">
    <h2><?= e(t('ven.deliveries')) ?></h2>
    <?php if ($commande['livraisons'] === []): ?>
      <div class="empty-state"><?= e(t('ven.noDelivery')) ?></div>
    <?php else: ?>
      <ul class="tag-list">
        <?php foreach ($commande['livraisons'] as $n): ?><li><a class="tag" href="/crm/livraisons/<?= (int) $n['id'] ?>"><?= e($n['reference'] . ' · ' . \App\Core\Dates::short((string) $n['delivered_on'])) ?></a></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2><?= e(t('ven.invoices')) ?></h2>
    <?php if ($commande['factures'] === []): ?>
      <div class="empty-state"><?= e(t('ven.noInvoice')) ?></div>
    <?php else: ?>
      <ul class="tag-list">
        <?php foreach ($commande['factures'] as $f): ?><li><span class="tag"><?= e($f['reference'] . ' · ' . $money((float) $f['amount_ht']) . ' HT · ' . st((string) $f['status'])) ?></span></li><?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>
