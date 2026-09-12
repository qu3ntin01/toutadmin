<?php
$fullName = static fn (array $p): string => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
?>
<?php if ($restricted): ?>
  <div class="flash flash-info">
    <?= e(t('cf.consulting', ['name' => $fullName($holder)])) ?>
    <form method="POST" action="/deconnexion" class="inline-form">
      <?= \App\Core\Csrf::field() ?>
      <button type="submit" class="btn btn-sm"><?= e(t('cf.closeAccess')) ?></button>
    </form>
  </div>
<?php endif; ?>

<section class="card">
  <h2><?= e(t('cf.documentCount', ['count' => count($documents)])) ?></h2>
  <p class="muted"><?= e(t('cf.recomputeNote')) ?></p>
  <?php if ($documents === []): ?>
    <div class="empty-state"><?= e(t('cf.noDocument')) ?></div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th><?= e(t('common.document')) ?></th>
          <th><?= e(t('common.category')) ?></th>
          <th><?= e(t('cf.depositedOn')) ?></th>
          <th><?= e(t('cfg.keptUntil')) ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($documents as $document): ?>
          <tr>
            <td>
              <strong><?= e($document['title']) ?></strong>
              <?php if (!empty($document['period'])): ?>
                <br /><span class="cell-sub"><?= e($document['period']) ?></span>
              <?php endif; ?>
            </td>
            <td><?= e($document['category']) ?></td>
            <td><?= e(substr((string) $document['deposited_at'], 0, 10)) ?></td>
            <td><?= e((string) $document['retention_until']) ?></td>
            <td>
              <a class="btn btn-sm btn-primary" href="/coffre-fort/documents/<?= (int) $document['id'] ?>"
                 title="<?= e(t('cf.fingerprintTitle')) ?> : <?= e(substr((string) $document['sha256'], 0, 16)) ?>">
                <?= e(t('common.download')) ?>
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section class="card mt-l">
  <h2><?= e(t('cf.payHistory', ['count' => count($payslips)])) ?></h2>
  <p class="muted"><?= e(t('cf.payHistoryNote')) ?></p>
  <?php if ($payslips !== []): ?>
    <table class="table">
      <thead>
        <tr>
          <th><?= e(t('common.period')) ?></th>
          <th><?= e(t('payslip.gross')) ?></th>
          <th><?= e(t('payslip.net')) ?></th>
          <th><?= e(t('common.document')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($payslips as $payslip): ?>
          <?php
            $deposited = null;
            foreach ($documents as $document) {
                if ((int) ($document['payslip_id'] ?? 0) === (int) $payslip['id']) {
                    $deposited = $document;
                }
            }
          ?>
          <tr>
            <td><?= e($payslip['period']) ?></td>
            <td><?= e(number_format((float) $payslip['gross_amount'], 2, ',', ' ')) ?></td>
            <td><?= e(number_format((float) $payslip['net_amount'], 2, ',', ' ')) ?></td>
            <td>
              <?php if ($deposited !== null): ?>
                <a href="/coffre-fort/documents/<?= (int) $deposited['id'] ?>"><?= e(t('common.download')) ?></a>
              <?php else: ?>
                <span class="cell-sub"><?= e(t('cf.notDeposited')) ?></span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>
