<?php
$csrf = \App\Core\Csrf::field();
$money = static fn (?float $value): string => $value === null ? '—' : number_format($value, 2, ',', ' ');
$fullName = static fn (array $p): string => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
$statusClass = static fn (string $status): string => match ($status) {
    'Approuvée', 'Commandée' => 'status-on',
    'Refusée', 'Annulée' => 'status-off',
    default => 'status-wait',
};
?>
<?php if ($isFinance && $lowStock !== []): ?>
  <div class="flash flash-error">
    <?= e(t('stk.lowStockBanner', [
        'count' => count($lowStock),
        'list' => implode(', ', array_column(array_slice($lowStock, 0, 3), 'label')),
    ])) ?>
  </div>
<?php endif; ?>

<?php if ($isFinance): ?>
  <section class="stats-grid">
    <?php foreach ([
        [$money($stockValue), t('stk.stockValue')],
        [(string) $purchaseSummary['open'], t('ach.openOrders')],
        [$money($purchaseSummary['awaited']), t('ach.awaited')],
        [(string) $purchaseSummary['discrepancies'], t('ach.gaps')],
    ] as [$value, $label]): ?>
      <div class="stat-card">
        <span class="stat-body">
          <span class="stat-value"><?= e($value) ?></span>
          <span class="stat-label"><?= e($label) ?></span>
        </span>
      </div>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<!-- ----------------------------------------------------------- Demandes -->
<section class="tab-panel is-active" id="demandes">
  <div class="card">
    <h2><?= e(t('stk.newRequest')) ?></h2>
    <p class="muted"><?= e(t('stk.twoLevelNote', ['threshold' => $threshold])) ?></p>
    <form method="POST" action="/stock/demandes" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('common.title')) ?></span><input type="text" name="label" required maxlength="160" /></label>
      <label><span><?= e(t('stk.catalogItem')) ?></span>
        <select name="item_id">
          <option value=""><?= e(t('common.none')) ?></option>
          <?php foreach ($items as $item): ?>
            <?php if ((int) $item['active'] === 1): ?>
              <option value="<?= (int) $item['id'] ?>"><?= e($item['label']) ?></option>
            <?php endif; ?>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('ach.quantity')) ?></span><input type="text" name="quantity" inputmode="decimal" value="1" /></label>
      <label><span><?= e(t('stk.estimatedAmount')) ?></span><input type="text" name="estimated_amount" inputmode="decimal" value="0" /></label>
      <label class="span-2"><span><?= e(t('stk.justification')) ?></span><input type="text" name="justification" maxlength="1000" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.send')) ?></button>
    </form>
  </div>

  <?php if ($managerRequests !== []): ?>
    <div class="card mt-l">
      <h2><?= e(t('stk.toArbitrate', ['count' => count($managerRequests)])) ?></h2>
      <p class="muted"><?= e(t('stk.firstLevelNote', ['threshold' => $threshold])) ?></p>
      <?php foreach ($managerRequests as $demande): ?>
        <form method="POST" action="/stock/demandes/<?= (int) $demande['id'] ?>/manager" class="form-grid">
          <?= $csrf ?>
          <label class="span-2">
            <span><?= e($fullName($demande)) ?> · <?= e($demande['label']) ?></span>
            <input type="text" readonly
                   value="<?= e($money((float) $demande['estimated_amount'])) ?> · <?= e((string) $demande['quantity']) ?>" />
          </label>
          <label class="span-2"><span><?= e(t('common.comment')) ?></span><input type="text" name="review_note" maxlength="500" /></label>
          <div class="row-actions">
            <button type="submit" name="decision" value="approuver" class="btn btn-sm btn-primary"><?= e(t('hr.approve')) ?></button>
            <button type="submit" name="decision" value="refuser" class="btn btn-sm"><?= e(t('hr.refuse')) ?></button>
          </div>
        </form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($isFinance && $financeRequests !== []): ?>
    <div class="card mt-l">
      <h2><?= e(t('stk.toApprove')) ?></h2>
      <p class="muted"><?= e(t('stk.secondLevelNote', ['threshold' => $threshold])) ?></p>
      <?php foreach ($financeRequests as $demande): ?>
        <form method="POST" action="/stock/demandes/<?= (int) $demande['id'] ?>/gestion" class="form-grid">
          <?= $csrf ?>
          <label class="span-2">
            <span><?= e($fullName($demande)) ?> · <?= e($demande['label']) ?></span>
            <input type="text" readonly
                   value="<?= e($money((float) $demande['estimated_amount'])) ?> · <?= e((string) $demande['department_name']) ?>" />
          </label>
          <label class="span-2"><span><?= e(t('common.comment')) ?></span><input type="text" name="review_note" maxlength="500" /></label>
          <div class="row-actions">
            <button type="submit" name="decision" value="approuver" class="btn btn-sm btn-primary"><?= e(t('hr.approve')) ?></button>
            <button type="submit" name="decision" value="refuser" class="btn btn-sm"><?= e(t('hr.refuse')) ?></button>
          </div>
        </form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="card mt-l">
    <h2><?= e(t('erp.myClaims')) ?></h2>
    <?php if ($myRequests === []): ?>
      <div class="empty-state"><?= e(t('stk.noRequestSent')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.title')) ?></th><th><?= e(t('ach.quantity')) ?></th>
            <th><?= e(t('stk.estimatedAmount')) ?></th><th><?= e(t('common.status')) ?></th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($myRequests as $demande): ?>
            <tr>
              <td><?= e($demande['label']) ?><br /><span class="cell-sub"><?= e((string) $demande['item_label']) ?></span></td>
              <td><?= e((string) $demande['quantity']) ?></td>
              <td><?= e($money((float) $demande['estimated_amount'])) ?></td>
              <td>
                <span class="status <?= e($statusClass($demande['status'])) ?>"><?= e($demande['status']) ?></span>
                <?php if (!empty($demande['review_note'])): ?>
                  <br /><span class="cell-sub"><?= e($demande['review_note']) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($demande['status'] === 'Manager'): ?>
                  <form method="POST" action="/stock/demandes/<?= (int) $demande['id'] ?>/annuler"
                        data-confirm="<?= e(t('stk.confirmCancelRequest')) ?>">
                    <?= $csrf ?>
                    <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.cancel')) ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?php if ($isFinance): ?>
    <div class="card mt-l">
      <h2><?= e(t('stk.allRequests', ['count' => count($allRequests)])) ?></h2>
      <?php if ($allRequests === []): ?>
        <div class="empty-state"><?= e(t('stk.noPurchaseRequest')) ?></div>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('common.requester')) ?></th><th><?= e(t('common.title')) ?></th>
              <th><?= e(t('stk.estimatedAmount')) ?></th><th><?= e(t('common.status')) ?></th><th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($allRequests as $demande): ?>
              <tr>
                <td><?= e($fullName($demande)) ?><br /><span class="cell-sub"><?= e((string) $demande['department_name']) ?></span></td>
                <td><?= e($demande['label']) ?></td>
                <td><?= e($money((float) $demande['estimated_amount'])) ?></td>
                <td><span class="status <?= e($statusClass($demande['status'])) ?>"><?= e($demande['status']) ?></span></td>
                <td>
                  <?php if ($demande['status'] === 'Approuvée'): ?>
                    <form method="POST" action="/stock/demandes/<?= (int) $demande['id'] ?>/commander">
                      <?= $csrf ?>
                      <button type="submit" class="btn btn-sm btn-primary"><?= e(t('stk.order')) ?></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<?php if ($isFinance): ?>
<!-- ----------------------------------------------------------- Articles -->
<section class="tab-panel" id="articles">
  <div class="card">
    <h2><?= e(t('stk.newItem')) ?></h2>
    <form method="POST" action="/stock/articles" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('common.designation')) ?></span><input type="text" name="label" required maxlength="160" /></label>
      <label><span><?= e(t('common.reference')) ?></span><input type="text" name="reference" maxlength="60" /></label>
      <label><span><?= e(t('common.unit')) ?></span><input type="text" name="unit" maxlength="20" value="unité" /></label>
      <label><span><?= e(t('common.category')) ?></span><input type="text" name="category" maxlength="80" /></label>
      <label><span><?= e(t('stk.alertThreshold')) ?></span><input type="text" name="stock_min" inputmode="decimal" value="0" /></label>
      <label><span><?= e(t('stk.unitPrice')) ?></span><input type="text" name="unit_price" inputmode="decimal" /></label>
      <label><span><?= e(t('stk.supplier')) ?></span>
        <select name="partner_id">
          <option value=""></option>
          <?php foreach ($partners as $partner): ?>
            <option value="<?= (int) $partner['id'] ?>"><?= e($partner['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.create')) ?></button>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('stk.stockCount', ['count' => count($items)])) ?></h2>
    <p class="muted"><?= e(t('stk.stockIsSum')) ?></p>
    <?php if ($items === []): ?>
      <div class="empty-state"><?= e(t('stk.noItem')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('stk.item')) ?></th><th><?= e(t('stk.stock')) ?></th>
            <th><?= e(t('stk.alertThreshold')) ?></th><th><?= e(t('stk.unitPrice')) ?></th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <tr>
              <td>
                <strong><?= e($item['label']) ?></strong>
                <?php if ((int) $item['active'] !== 1): ?><span class="tag"><?= e(t('common.inactive')) ?></span><?php endif; ?>
                <br /><span class="cell-sub">
                  <?= e((string) $item['category']) ?>
                  <?= $item['partner_name'] ? ' · ' . e($item['partner_name']) : '' ?>
                </span>
              </td>
              <td>
                <?= e(t('stk.unitCount', ['count' => $item['stock']])) ?>
                <?php if ($item['below']): ?><br /><span class="tag tag-off"><?= e(t('stk.belowThreshold')) ?></span><?php endif; ?>
              </td>
              <td><?= e((string) $item['stock_min']) ?></td>
              <td><?= $item['unit_price'] === null ? '—' : e($money((float) $item['unit_price'])) ?></td>
              <td class="row-actions">
                <form method="POST" action="/stock/articles/<?= (int) $item['id'] ?>/statut" class="inline-form">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm">
                    <?= e((int) $item['active'] === 1 ? t('common.deactivate') : t('common.activate')) ?>
                  </button>
                </form>
                <form method="POST" action="/stock/articles/<?= (int) $item['id'] ?>/supprimer"
                      data-confirm="<?= e(t('stk.confirmDeleteItem')) ?>">
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
    <h2><?= e(t('stk.recordMovement')) ?></h2>
    <p class="muted"><?= e(t('stk.movementRule')) ?></p>
    <?php if ($items === []): ?>
      <div class="empty-state"><?= e(t('stk.createItemFirst')) ?></div>
    <?php else: ?>
      <form method="POST" action="/stock/mouvements" class="form-grid">
        <?= $csrf ?>
        <label><span><?= e(t('stk.item')) ?></span>
          <select name="item_id" required>
            <?php foreach ($items as $item): ?>
              <option value="<?= (int) $item['id'] ?>"><?= e($item['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('common.type')) ?></span>
          <select name="kind" required>
            <?php foreach ($movementKinds as $kind): ?>
              <option value="<?= e($kind) ?>"><?= e($kind) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('ach.quantity')) ?></span><input type="text" name="quantity" inputmode="decimal" required /></label>
        <label><span><?= e(t('common.date')) ?></span><input type="date" name="moved_on" value="<?= e($today) ?>" /></label>
        <label class="span-2"><span><?= e(t('common.reason')) ?></span><input type="text" name="reason" maxlength="200" /></label>
        <button type="submit" class="btn btn-primary"><?= e(t('common.record')) ?></button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('stk.lastMovements')) ?></h2>
    <?php if ($movements === []): ?>
      <div class="empty-state"><?= e(t('stk.noMovement')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.date')) ?></th><th><?= e(t('stk.item')) ?></th>
            <th><?= e(t('common.type')) ?></th><th><?= e(t('ach.quantity')) ?></th><th><?= e(t('common.reason')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($movements as $movement): ?>
            <tr>
              <td><?= e($movement['moved_on']) ?></td>
              <td><?= e($movement['item_label']) ?></td>
              <td><span class="tag"><?= e($movement['kind']) ?></span></td>
              <td><?= e((string) $movement['quantity']) ?> <?= e((string) $movement['unit']) ?></td>
              <td><?= e((string) $movement['reason']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<!-- ---------------------------------------------------------- Commandes -->
<section class="tab-panel" id="commandes">
  <?php if ($discrepancies !== []): ?>
    <div class="card">
      <h2><?= e(t('ach.gapsTitle', ['count' => count($discrepancies)])) ?></h2>
      <p class="muted"><?= e(t('ach.gapsHint')) ?></p>
      <ul class="org-list">
        <?php foreach ($discrepancies as $row): ?>
          <li class="org-item">
            <a href="/stock/commandes/<?= (int) $row['order']['id'] ?>"><?= e($row['order']['reference']) ?></a>
            — <?= e($row['order']['partner_name']) ?>
            <?php foreach ($row['match']['issues'] as $issue): ?>
              <br /><span class="cell-sub">
                <?= e($issue['kind'] === 'sur_commande'
                    ? t('ach.gapOverOrdered', ['amount' => $money($issue['gap'])])
                    : t('ach.gapOverReceived', ['amount' => $money($issue['gap'])])) ?>
              </span>
            <?php endforeach; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="card mt-l">
    <h2><?= e(t('ach.newOrder')) ?></h2>
    <form method="POST" action="/stock/commandes" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('ach.supplier')) ?></span>
        <select name="partner_id" required>
          <option value=""></option>
          <?php foreach ($partners as $partner): ?>
            <option value="<?= (int) $partner['id'] ?>"><?= e($partner['name']) ?></option>
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
      <label><span><?= e(t('ach.orderedOn')) ?></span><input type="date" name="ordered_on" value="<?= e($today) ?>" required /></label>
      <label><span><?= e(t('ach.expectedOn')) ?></span><input type="date" name="expected_on" /></label>
      <label class="span-2"><span><?= e(t('common.notes')) ?></span><input type="text" name="notes" maxlength="1000" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.create')) ?></button>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('ach.orderCount', ['count' => count($orderList)])) ?></h2>
    <?php if ($orderList === []): ?>
      <div class="empty-state"><?= e(t('ach.noOrder')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('ach.reference')) ?></th><th><?= e(t('ach.supplier')) ?></th>
            <th><?= e(t('ach.ordered')) ?></th><th><?= e(t('ach.received')) ?></th>
            <th><?= e(t('ach.invoiced')) ?></th><th><?= e(t('common.status')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orderList as $order): ?>
            <tr>
              <td><a href="/stock/commandes/<?= (int) $order['id'] ?>"><?= e($order['reference']) ?></a></td>
              <td><?= e($order['partner_name']) ?></td>
              <td><?= e($money((float) $order['ordered_amount'])) ?></td>
              <td><?= e($money((float) $order['received_amount'])) ?></td>
              <td><?= e($money((float) $order['invoiced_amount'])) ?></td>
              <td><span class="tag"><?= e($order['status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>
<?php endif; ?>
