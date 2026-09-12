<?php
$csrf = \App\Core\Csrf::field();
$money = static fn (?float $value): string => $value === null ? '—' : number_format($value, 2, ',', ' ');
?>
<section class="card">
  <h2><?= e(t('ach.matchTitle')) ?>
    <span class="status <?= $reconciliation['ok'] ? 'status-on' : 'status-off' ?>">
      <?= e($reconciliation['ok'] ? t('ach.matchOk') : t('ach.matchKo')) ?>
    </span>
  </h2>
  <p class="muted"><?= e(t('ach.matchExplain')) ?></p>
  <table class="table">
    <thead>
      <tr>
        <th><?= e(t('ach.ordered')) ?></th><th><?= e(t('ach.received')) ?></th>
        <th><?= e(t('ach.invoiced')) ?></th><th><?= e(t('ach.pending')) ?></th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><?= e($money($reconciliation['ordered'])) ?></td>
        <td><?= e($money($reconciliation['received'])) ?></td>
        <td><?= e($money($reconciliation['invoiced'])) ?></td>
        <td><?= e($money($reconciliation['pending'])) ?></td>
      </tr>
    </tbody>
  </table>
  <?php foreach ($reconciliation['issues'] as $issue): ?>
    <p class="flash flash-error">
      <?= e($issue['kind'] === 'sur_commande'
          ? t('ach.issueOverOrdered', ['amount' => $money($issue['gap'])])
          : t('ach.issueOverReceived', ['amount' => $money($issue['gap'])])) ?>
    </p>
  <?php endforeach; ?>
</section>

<section class="card mt-l">
  <h2><?= e(t('ach.tabSheet')) ?></h2>
  <p class="muted"><?= e(t('ach.sheetSub')) ?></p>
  <form method="POST" action="/stock/commandes/<?= (int) $order['id'] ?>/modifier" class="form-grid">
    <?= $csrf ?>
    <label><span><?= e(t('ach.supplier')) ?></span>
      <select name="partner_id">
        <?php foreach ($partners as $partner): ?>
          <option value="<?= (int) $partner['id'] ?>"<?= (int) $partner['id'] === (int) $order['partner_id'] ? ' selected' : '' ?>>
            <?= e($partner['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><span><?= e(t('common.department')) ?></span>
      <select name="department_id">
        <option value=""></option>
        <?php foreach ($departments as $department): ?>
          <option value="<?= (int) $department['id'] ?>"<?= (int) $department['id'] === (int) $order['department_id'] ? ' selected' : '' ?>>
            <?= e($department['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><span><?= e(t('ach.orderedOn')) ?></span><input type="date" name="ordered_on" value="<?= e((string) $order['ordered_on']) ?>" required /></label>
    <label><span><?= e(t('ach.expectedOn')) ?></span><input type="date" name="expected_on" value="<?= e((string) $order['expected_on']) ?>" /></label>
    <label><span><?= e(t('common.status')) ?></span>
      <select name="status">
        <?php foreach ($statuses as $status): ?>
          <option value="<?= e($status) ?>"<?= $order['status'] === $status ? ' selected' : '' ?>><?= e($status) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="span-2"><span><?= e(t('common.notes')) ?></span><input type="text" name="notes" value="<?= e((string) $order['notes']) ?>" maxlength="1000" /></label>
    <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
  </form>
  <p class="muted"><?= e(t('ach.statusHint')) ?></p>

  <form method="POST" action="/stock/commandes/<?= (int) $order['id'] ?>/supprimer"
        data-confirm="<?= e(t('ach.confirmDelete')) ?>">
    <?= $csrf ?>
    <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
  </form>
</section>

<section class="card mt-l">
  <h2><?= e(t('ach.tabLines')) ?></h2>
  <?php if ($lineList === []): ?>
    <div class="empty-state"><?= e(t('ach.noLine')) ?></div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th><?= e(t('ach.line')) ?></th><th><?= e(t('ach.quantity')) ?></th>
          <th><?= e(t('ach.unitPrice')) ?></th><th><?= e(t('ach.receivedQty')) ?></th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lineList as $line): ?>
          <?php $remaining = round((float) $line['quantity'] - (float) $line['received_quantity'], 2); ?>
          <tr>
            <td>
              <?= e($line['label']) ?>
              <?php if (!empty($line['item_name'])): ?>
                <br /><span class="cell-sub"><?= e(t('ach.linkedItem')) ?> : <?= e($line['item_name']) ?></span>
              <?php endif; ?>
            </td>
            <td><?= e((string) $line['quantity']) ?></td>
            <td><?= e($money((float) $line['unit_price'])) ?></td>
            <td>
              <?= e((string) $line['received_quantity']) ?>
              <?php if ($remaining > 0): ?>
                <br /><span class="cell-sub"><?= e(t('ach.remaining', ['count' => $remaining])) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($remaining > 0): ?>
                <form method="POST" action="/stock/lignes/<?= (int) $line['id'] ?>/reception" class="inline-form">
                  <?= $csrf ?>
                  <input type="text" name="quantity" inputmode="decimal" value="<?= e((string) $remaining) ?>" required />
                  <input type="date" name="received_on" value="<?= e($today) ?>" required />
                  <button type="submit" class="btn btn-sm btn-primary"><?= e(t('ach.receive')) ?></button>
                </form>
              <?php endif; ?>
              <?php if ((float) $line['received_quantity'] === 0.0): ?>
                <form method="POST" action="/stock/lignes/<?= (int) $line['id'] ?>/supprimer"
                      data-confirm="<?= e(t('ach.confirmDeleteLine')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.remove')) ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <p class="muted"><?= e(t('ach.receiveHint')) ?></p>

  <h2 class="mt-l"><?= e(t('ach.addLine')) ?></h2>
  <form method="POST" action="/stock/commandes/<?= (int) $order['id'] ?>/lignes" class="form-grid">
    <?= $csrf ?>
    <label class="span-2"><span><?= e(t('common.designation')) ?></span><input type="text" name="label" required maxlength="160" /></label>
    <label><span><?= e(t('ach.linkedItem')) ?></span>
      <select name="item_id">
        <option value=""><?= e(t('common.none')) ?></option>
        <?php foreach ($items as $item): ?>
          <option value="<?= (int) $item['id'] ?>"><?= e($item['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><span><?= e(t('ach.quantity')) ?></span><input type="text" name="quantity" inputmode="decimal" required /></label>
    <label><span><?= e(t('ach.unitPrice')) ?></span><input type="text" name="unit_price" inputmode="decimal" value="0" /></label>
    <button type="submit" class="btn btn-primary"><?= e(t('common.add')) ?></button>
  </form>
  <p class="muted"><?= e(t('ach.itemHint')) ?></p>
</section>

<section class="card mt-l">
  <h2><?= e(t('ach.receiptCount', ['count' => count($receiptList)])) ?></h2>
  <?php if ($receiptList === []): ?>
    <div class="empty-state"><?= e(t('ach.noReceipt')) ?></div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th><?= e(t('ach.receivedOn')) ?></th><th><?= e(t('ach.line')) ?></th>
          <th><?= e(t('ach.quantity')) ?></th><th><?= e(t('ach.receivedBy')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($receiptList as $receipt): ?>
          <tr>
            <td><?= e($receipt['received_on']) ?></td>
            <td><?= e($receipt['label']) ?></td>
            <td><?= e((string) $receipt['quantity']) ?></td>
            <td><?= e(trim(($receipt['first_name'] ?? '') . ' ' . ($receipt['last_name'] ?? ''))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="card mt-l">
  <h2><?= e(t('ach.linkedInvoices', ['count' => count($invoiceList)])) ?></h2>
  <?php if ($invoiceList === []): ?>
    <div class="empty-state"><?= e(t('ach.noLinkedInvoice')) ?></div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th><?= e(t('common.reference')) ?></th><th><?= e(t('common.title')) ?></th>
          <th><?= e(t('ges.amountExclVat')) ?></th><th><?= e(t('common.status')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($invoiceList as $invoice): ?>
          <tr>
            <td><?= e((string) $invoice['reference']) ?></td>
            <td><?= e($invoice['label']) ?></td>
            <td><?= e($money((float) $invoice['amount_ht'])) ?></td>
            <td><span class="tag"><?= e($invoice['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <p class="muted"><?= e(t('ach.linkInvoiceHint')) ?></p>
</section>
