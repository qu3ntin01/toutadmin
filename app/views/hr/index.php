<?php
$csrf = \App\Core\Csrf::field();
$fullName = static fn (array $p): string => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
$statusClass = static fn (string $status): string => match ($status) {
    'Approuvée' => 'status-on',
    'Refusée' => 'status-off',
    'Annulée' => 'status-off',
    default => 'status-wait',
};
?>
<section class="tab-panel is-active" id="demandes">
  <div class="card">
    <h2><?= e(t('nav.requests')) ?> <span class="muted">(<?= (int) $pendingCount ?>)</span></h2>
    <?php if ($requests === []): ?>
      <div class="empty-state"><?= e(t('hr.noRequestForFilter')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.member')) ?></th>
            <th><?= e(t('common.type')) ?></th>
            <th><?= e(t('common.period')) ?></th>
            <th><?= e(t('common.days')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $row): ?>
            <tr>
              <td><?= e($fullName($row)) ?><br /><span class="cell-sub"><?= e($row['email']) ?></span></td>
              <td><?= e($row['type']) ?></td>
              <td><?= e($row['start_date']) ?> → <?= e($row['end_date']) ?></td>
              <td><?= (int) $row['days'] ?></td>
              <td><span class="status <?= e($statusClass($row['status'])) ?>"><?= e($row['status']) ?></span></td>
              <td class="row-actions">
                <?php if ($row['status'] === 'En attente'): ?>
                  <form method="POST" action="/rh/demandes/<?= (int) $row['id'] ?>/approuver" class="inline-form">
                    <?= $csrf ?>
                    <input type="text" name="note" maxlength="500" placeholder="<?= e(t('common.note')) ?>" />
                    <button type="submit" class="btn btn-sm btn-primary"><?= e(t('hr.approve')) ?></button>
                  </form>
                  <form method="POST" action="/rh/demandes/<?= (int) $row['id'] ?>/refuser" class="inline-form">
                    <?= $csrf ?>
                    <button type="submit" class="btn btn-sm"><?= e(t('hr.refuse')) ?></button>
                  </form>
                <?php elseif ($row['status'] === 'Approuvée'): ?>
                  <form method="POST" action="/rh/demandes/<?= (int) $row['id'] ?>/annuler" class="inline-form"
                        data-confirm="<?= e(t('hr.cancelApproved')) ?>">
                    <?= $csrf ?>
                    <button type="submit" class="btn btn-sm"><?= e(t('common.cancel')) ?></button>
                  </form>
                <?php endif; ?>
                <?php if (!empty($row['review_note'])): ?>
                  <span class="cell-sub"><?= e($row['review_note']) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<section class="tab-panel" id="personnel">
  <div class="card">
    <h2><?= e(t('hr.leaveBalances')) ?></h2>
    <p class="muted"><?= e(t('hr.adjustmentHelp')) ?></p>
    <?php if ($employees === []): ?>
      <div class="empty-state"><?= e(t('hr.noStaffToTrack')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr><th><?= e(t('common.member')) ?></th><th><?= e(t('leave.balance')) ?></th><th><?= e(t('hr.adjustment')) ?></th></tr>
        </thead>
        <tbody>
          <?php foreach ($employees as $employee): ?>
            <tr>
              <td><?= e($fullName($employee)) ?><br /><span class="cell-sub"><?= e((string) $employee['contract_type']) ?></span></td>
              <td><strong><?= e((string) $employee['leave_balance']) ?></strong> <?= e(t('common.days')) ?></td>
              <td>
                <form method="POST" action="/rh/solde/<?= (int) $employee['id'] ?>/ajuster" class="inline-form">
                  <?= $csrf ?>
                  <input type="text" name="amount" inputmode="decimal" placeholder="+2 / -1" required />
                  <input type="text" name="reason" maxlength="300" placeholder="<?= e(t('common.reason')) ?>" />
                  <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<section class="tab-panel" id="paie">
  <div class="card">
    <h2><?= e(t('hr.createPayslip')) ?></h2>
    <form method="POST" action="/rh/paie" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.member')) ?></span>
        <select name="employee_id" required>
          <option value=""></option>
          <?php foreach ($employees as $employee): ?>
            <option value="<?= (int) $employee['id'] ?>"><?= e($fullName($employee)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.period')) ?></span><input type="month" name="period" required /></label>
      <label><span><?= e(t('hr.grossEuro')) ?></span><input type="text" name="gross_amount" inputmode="decimal" required /></label>
      <label><span><?= e(t('hr.netEuro')) ?></span><input type="text" name="net_amount" inputmode="decimal" required /></label>
      <label class="span-2"><span><?= e(t('common.note')) ?></span><input type="text" name="note" maxlength="300" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.create')) ?></button>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('nav.payroll')) ?></h2>
    <?php if ($payslips === []): ?>
      <div class="empty-state"><?= e(t('hr.noPayslipRecorded')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.period')) ?></th>
            <th><?= e(t('common.member')) ?></th>
            <th><?= e(t('hr.grossEuro')) ?></th>
            <th><?= e(t('hr.netEuro')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payslips as $payslip): ?>
            <tr>
              <td><?= e($payslip['period']) ?></td>
              <td><?= e($fullName($payslip)) ?></td>
              <td><?= e(number_format((float) $payslip['gross_amount'], 2, ',', ' ')) ?> €</td>
              <td><?= e(number_format((float) $payslip['net_amount'], 2, ',', ' ')) ?> €</td>
              <td><span class="status <?= $payslip['status'] === 'Payée' ? 'status-on' : 'status-wait' ?>"><?= e($payslip['status']) ?></span></td>
              <td class="row-actions">
                <?php if ($payslip['status'] !== 'Payée'): ?>
                  <form method="POST" action="/rh/paie/<?= (int) $payslip['id'] ?>/marquer-payee">
                    <?= $csrf ?>
                    <button type="submit" class="btn btn-sm"><?= e(t('hr.markPaid')) ?></button>
                  </form>
                <?php endif; ?>
                <form method="POST" action="/rh/paie/<?= (int) $payslip['id'] ?>/supprimer"
                      data-confirm="<?= e(t('hr.deletePayslip')) ?>">
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
