<?php
$fullName = static fn (array $p): string => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
?>
<section class="card">
  <h2><?= e(t('sig.attestation')) ?></h2>
  <dl class="detail-list">
    <div><dt><?= e(t('common.title')) ?></dt><dd><?= e($request['title']) ?></dd></div>
    <div><dt><?= e(t('common.nature')) ?></dt><dd><?= e($request['kind']) ?></dd></div>
    <div><dt><?= e(t('common.status')) ?></dt><dd><?= e(st($request['status'])) ?></dd></div>
    <div><dt><?= e(t('cf.fingerprintTitle')) ?></dt><dd><code><?= e((string) $request['sha256']) ?></code></dd></div>
    <div><dt><?= e(t('common.timestamp')) ?></dt><dd><?= e(substr($generatedAt, 0, 19)) ?></dd></div>
  </dl>

  <h3><?= e(t('sig.signatories')) ?></h3>
  <table class="table">
    <thead>
      <tr>
        <th><?= e(t('common.rank')) ?></th><th><?= e(t('common.signatory')) ?></th>
        <th><?= e(t('common.timestamp')) ?></th><th><?= e(t('common.seal')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($request['signers'] as $signer): ?>
        <tr>
          <td><?= (int) $signer['position'] ?></td>
          <td><?= e($fullName($signer)) ?><br /><span class="cell-sub"><?= e((string) $signer['role_label']) ?></span></td>
          <td><?= e(substr((string) $signer['signed_at'], 0, 19)) ?></td>
          <td><code><?= e((string) $signer['seal']) ?></code></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <p>
    <?php if ($verification['ok']): ?>
      <span class="status status-on"><?= e(t('sig.integrityOk')) ?></span>
    <?php else: ?>
      <span class="status status-off">
        <?= e(t('sig.integrityFailed', [
            'detail' => $verification['document']['ok']
                ? t('sig.invalidSeal', ['names' => implode(', ', $verification['broken'])])
                : $verification['document']['reason'],
        ])) ?>
      </span>
    <?php endif; ?>
  </p>
  <p class="muted">
    <?= e(t('sig.simpleNote', ['simple' => t('sig.simple'), 'qualified' => t('sig.qualified')])) ?>
  </p>
</section>
