<?php
$csrf = \App\Core\Csrf::field();
$fullName = static fn (array $p): string => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
?>
<section class="card">
  <h2><?= e(t('sig.flowState')) ?></h2>
  <p>
    <span class="status <?= $request['status'] === 'Signé' ? 'status-on' : ($request['status'] === 'En cours' ? 'status-wait' : 'status-off') ?>">
      <?= e($request['status']) ?>
    </span>
    <?= e(t('sig.signatureCount', ['signed' => $request['signedCount'], 'total' => $request['total']])) ?>
    · <?= e(t('sig.openedOnCap', ['date' => substr((string) $request['created_at'], 0, 10)])) ?>
    <?php if (!empty($request['deadline'])): ?>
      · <?= e(t('sig.forDate', ['date' => $request['deadline']])) ?>
      <?php if ($request['overdue']): ?><span class="tag tag-off"><?= e(t('sig.deadlinePassed')) ?></span><?php endif; ?>
    <?php endif; ?>
  </p>
  <p class="cell-sub"><?= e(t('sig.fingerprint', ['hash' => substr((string) $request['sha256'], 0, 32)])) ?></p>
  <?php if (!empty($request['closing_reason'])): ?>
    <p class="muted"><?= e(t('sig.closingReason', ['reason' => $request['closing_reason']])) ?></p>
  <?php endif; ?>

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

  <?php if (!empty($request['file_name'])): ?>
    <a class="btn btn-sm" href="/parapheur/<?= (int) $request['id'] ?>/document"><?= e(t('sig.download')) ?></a>
  <?php else: ?>
    <pre class="document-body"><?= e((string) $request['body']) ?></pre>
  <?php endif; ?>
  <a class="btn btn-sm" href="/parapheur/<?= (int) $request['id'] ?>/attestation"><?= e(t('sig.tabAttestation')) ?></a>
</section>

<section class="card mt-l">
  <h2><?= e(t('sig.signatories')) ?></h2>
  <table class="table">
    <thead>
      <tr>
        <th><?= e(t('common.rank')) ?></th><th><?= e(t('common.signatory')) ?></th>
        <th><?= e(t('common.status')) ?></th><th><?= e(t('common.seal')) ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($request['signers'] as $signer): ?>
        <tr>
          <td><?= (int) $signer['position'] ?></td>
          <td>
            <?= e($fullName($signer)) ?>
            <?php if (!empty($signer['role_label'])): ?>
              <br /><span class="cell-sub"><?= e($signer['role_label']) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <span class="status <?= $signer['status'] === 'Signé' ? 'status-on' : ($signer['status'] === 'Refusé' ? 'status-off' : 'status-wait') ?>">
              <?= e($signer['status']) ?>
            </span>
            <?php if (!empty($signer['signed_at'])): ?>
              <br /><span class="cell-sub"><?= e(substr((string) $signer['signed_at'], 0, 19)) ?></span>
            <?php endif; ?>
            <?php if (!empty($signer['reason'])): ?>
              <br /><span class="cell-sub"><?= e($signer['reason']) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($signer['seal'])): ?>
              <span class="cell-sub"><?= e(substr((string) $signer['seal'], 0, 16)) ?></span>
              <?php if (!empty($signer['ip'])): ?>
                <br /><span class="cell-sub"><?= e(t('sig.fromIp', ['ip' => $signer['ip']])) ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

<?php if ($myTurn['ok']): ?>
  <section class="card mt-l">
    <h2><?= e(t('sig.sign')) ?></h2>
    <p class="muted"><?= e(t('sig.passwordNote')) ?></p>
    <form method="POST" action="/parapheur/<?= (int) $request['id'] ?>/signer" class="form-grid">
      <?= $csrf ?>
      <label class="span-2">
        <input type="checkbox" name="consent" value="1" required />
        <span><?= e(t('sig.consent')) ?></span>
      </label>
      <label><span><?= e(t('sig.yourPassword')) ?></span>
        <input type="password" name="password" required autocomplete="current-password" />
      </label>
      <button type="submit" class="btn btn-primary"><?= e(t('sig.affix')) ?></button>
    </form>

    <form method="POST" action="/parapheur/<?= (int) $request['id'] ?>/refuser" class="form-grid mt-l"
          data-confirm="<?= e(t('sig.confirmRefuse')) ?>">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('common.refusalReason')) ?></span>
        <input type="text" name="reason" required maxlength="500" placeholder="<?= e(t('sig.refusePlaceholder')) ?>" />
      </label>
      <button type="submit" class="btn btn-danger"><?= e(t('sig.refuse')) ?></button>
    </form>
  </section>
<?php elseif ($request['status'] === 'En cours'): ?>
  <p class="muted"><?= e($myTurn['message']) ?></p>
<?php endif; ?>

<?php if ($opener): ?>
  <section class="card mt-l">
    <h2><?= e(t('sig.flowAdmin')) ?></h2>
    <?php if ($request['status'] === 'En cours'): ?>
      <form method="POST" action="/parapheur/<?= (int) $request['id'] ?>/annuler" class="inline-form"
            data-confirm="<?= e(t('sig.confirmWithdraw')) ?>">
        <?= $csrf ?>
        <input type="text" name="reason" maxlength="500" placeholder="<?= e(t('common.reason')) ?>" />
        <button type="submit" class="btn btn-sm"><?= e(t('sig.cancelFlow')) ?></button>
      </form>
    <?php else: ?>
      <form method="POST" action="/parapheur/<?= (int) $request['id'] ?>/supprimer"
            data-confirm="<?= e(t('sig.confirmDelete')) ?>">
        <?= $csrf ?>
        <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
      </form>
      <p class="muted"><?= e(t('sig.signedNotDeletable')) ?></p>
    <?php endif; ?>
  </section>
<?php endif; ?>
