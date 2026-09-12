<?php
$csrf = \App\Core\Csrf::field();
$fullName = static fn (array $p): string => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
?>
<section class="stats-grid">
  <?php foreach ([
      [(string) $summary['documents'], t('cfg.documentsInVault')],
      [(string) $summary['holders'], t('cfg.holders')],
      [(string) $summary['departed'], t('cfg.ofWhichGone')],
      [(string) $summary['grants'], t('cfg.accessCodes')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<?php if ($issuedCode !== null): ?>
  <div class="flash flash-success">
    <?= e(t('cfg.codeFor', ['email' => $issuedCode['email']])) ?>
    <br /><strong class="code-display"><?= e($issuedCode['code']) ?></strong>
    <br /><?= e(t('cfg.validUntilNote', [
        'date' => substr((string) $issuedCode['expiresAt'], 0, 10),
        'url' => '/coffre-fort/acces',
    ])) ?>
  </div>
<?php endif; ?>

<!-- --------------------------------------------------------- Documents -->
<section class="tab-panel is-active" id="documents">
  <div class="card">
    <h2><?= e(t('cfg.chooseHolder')) ?></h2>
    <form method="GET" action="/coffre-fort/gestion" class="inline-form">
      <select name="personne" required>
        <option value=""><?= e(t('common.choose')) ?></option>
        <?php foreach ($people as $person): ?>
          <option value="<?= (int) $person['id'] ?>"<?= $target !== null && (int) $target['id'] === (int) $person['id'] ? ' selected' : '' ?>>
            <?= e($fullName($person)) ?>
            <?= (int) $person['active'] === 0 ? ' · ' . e(t('cfg.gone')) : '' ?>
            · <?= e(t('cfg.docCount', ['count' => (int) $person['documents']])) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm"><?= e(t('cfg.openVault')) ?></button>
    </form>
  </div>

  <div class="card mt-l">
    <?php if ($target === null): ?>
      <div class="empty-state"><?= e(t('cfg.chooseHolderToView')) ?></div>
    <?php else: ?>
      <h2><?= e(t('cfg.vaultOf', ['name' => $fullName($target)])) ?></h2>
      <?php if ($documents === []): ?>
        <div class="empty-state"><?= e(t('cfg.emptyVault')) ?></div>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('common.document')) ?></th>
              <th><?= e(t('common.category')) ?></th>
              <th><?= e(t('cfg.depositedOn')) ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($documents as $document): ?>
              <tr>
                <td>
                  <strong><?= e($document['title']) ?></strong>
                  <?php if (!empty($document['removed_at'])): ?>
                    <span class="tag tag-off"><?= e(t('cfg.withdrawnTag')) ?></span>
                    <br /><span class="cell-sub">
                      <?= e(t('cfg.withdrawnOn')) ?> <?= e(substr((string) $document['removed_at'], 0, 10)) ?>
                      — <?= e((string) $document['removal_reason']) ?>
                    </span>
                  <?php else: ?>
                    <br /><span class="cell-sub"><?= e(substr((string) $document['sha256'], 0, 16)) ?></span>
                  <?php endif; ?>
                </td>
                <td><?= e($document['category']) ?><br /><span class="cell-sub"><?= e((string) $document['period']) ?></span></td>
                <td>
                  <?= e(substr((string) $document['deposited_at'], 0, 10)) ?>
                  <br /><span class="cell-sub"><?= e(t('cfg.keptUntil')) ?> <?= e((string) $document['retention_until']) ?></span>
                </td>
                <td class="row-actions">
                  <?php if (empty($document['removed_at'])): ?>
                    <a class="btn btn-sm" href="/coffre-fort/documents/<?= (int) $document['id'] ?>"><?= e(t('common.download')) ?></a>
                    <form method="POST" action="/coffre-fort/gestion/documents/<?= (int) $document['id'] ?>/retirer"
                          class="inline-form" data-confirm="<?= e(t('cfg.confirmWithdraw')) ?>">
                      <?= $csrf ?>
                      <input type="text" name="reason" required minlength="5" maxlength="300"
                             placeholder="<?= e(t('cfg.withdrawReason')) ?>" />
                      <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.remove')) ?></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p class="muted"><?= e(t('cfg.withdrawalAdminOnly')) ?></p>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<!-- ------------------------------------------------------------- Dépôt -->
<section class="tab-panel" id="depot">
  <div class="card">
    <h2><?= e(t('cfg.depositDocument')) ?></h2>
    <p class="muted"><?= e(t('cfg.depositNote', ['years' => $retentionYears])) ?></p>
    <?php if ($target === null): ?>
      <div class="empty-state"><?= e(t('cfg.chooseHolderFirst')) ?></div>
    <?php else: ?>
      <form method="POST" action="/coffre-fort/gestion/depots" class="form-grid" enctype="multipart/form-data">
        <?= $csrf ?>
        <input type="hidden" name="user_id" value="<?= (int) $target['id'] ?>" />
        <label class="span-2"><span><?= e(t('common.title')) ?></span>
          <input type="text" name="title" required maxlength="200" placeholder="<?= e(t('cfg.titlePlaceholder')) ?>" />
        </label>
        <label><span><?= e(t('common.category')) ?></span>
          <select name="category" required>
            <?php foreach ($categories as $category): ?>
              <option value="<?= e($category) ?>"><?= e($category) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('cfg.period')) ?></span><input type="text" name="period" maxlength="7" /></label>
        <label><span><?= e(t('cfg.linkToPayslip')) ?></span>
          <select name="payslip_id">
            <option value=""><?= e(t('cfg.noneOption')) ?></option>
            <?php foreach ($payslips as $payslip): ?>
              <option value="<?= (int) $payslip['id'] ?>"><?= e($payslip['period']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="span-2"><span><?= e(t('common.file')) ?></span>
          <input type="file" name="document" required accept=".pdf,.docx,.png,.jpg,.jpeg" />
        </label>
        <button type="submit" class="btn btn-primary"><?= e(t('cfg.deposit')) ?></button>
      </form>
    <?php endif; ?>
  </div>
</section>

<!-- ------------------------------------------------------------ Accès -->
<section class="tab-panel" id="acces">
  <div class="card">
    <h2><?= e(t('cfg.accessCodes')) ?></h2>
    <p class="muted"><?= e(t('cfg.codesNote', ['years' => $retentionYears])) ?></p>
    <?php if ($target === null): ?>
      <div class="empty-state"><?= e(t('cfg.chooseHolderToIssue')) ?></div>
    <?php else: ?>
      <h3><?= e(t('cfg.issueCodeFor', ['name' => $fullName($target)])) ?></h3>
      <form method="POST" action="/coffre-fort/gestion/acces/<?= (int) $target['id'] ?>" class="inline-form">
        <?= $csrf ?>
        <label><span><?= e(t('cfg.validityDays')) ?></span>
          <input type="number" name="days" min="1" max="<?= (int) $maxGrantDays ?>" value="<?= (int) $defaultGrantDays ?>" />
        </label>
        <button type="submit" class="btn btn-primary btn-sm"><?= e(t('common.send')) ?></button>
      </form>
      <p class="muted"><?= e(t('cfg.issueRevokes')) ?></p>

      <?php if ($grants === []): ?>
        <div class="empty-state"><?= e(t('cfg.noCode')) ?></div>
      <?php else: ?>
        <table class="table mt-l">
          <thead>
            <tr><th><?= e(t('common.code')) ?></th><th><?= e(t('common.state')) ?></th><th><?= e(t('cfg.useCount', ['count' => ''])) ?></th></tr>
          </thead>
          <tbody>
            <?php foreach ($grants as $grant): ?>
              <?php
                $state = !empty($grant['revoked_at'])
                    ? t('cfg.revoked')
                    : ($grant['expires_at'] < gmdate('c') ? t('cfg.expired') : t('cfg.activeCode'));
              ?>
              <tr>
                <td><?= e(t('cfg.codeOf', ['date' => substr((string) $grant['created_at'], 0, 10)])) ?></td>
                <td><span class="tag"><?= e($state) ?></span></td>
                <td>
                  <?= e(t('cfg.useCount', ['count' => (int) $grant['uses']])) ?>
                  <?php if (!empty($grant['last_used_at'])): ?>
                    <br /><span class="cell-sub"><?= e(t('cfg.lastUse', ['date' => substr((string) $grant['last_used_at'], 0, 10)])) ?></span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <form method="POST" action="/coffre-fort/gestion/acces/<?= (int) $target['id'] ?>/revoquer"
              data-confirm="<?= e(t('cfg.confirmRevokeCodes')) ?>">
          <?= $csrf ?>
          <button type="submit" class="btn btn-sm btn-danger"><?= e(t('cfg.revokeActiveCodes')) ?></button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</section>

<!-- --------------------------------------------------------- Intégrité -->
<section class="tab-panel" id="integrite">
  <div class="card">
    <h2><?= e(t('cfg.integrityCheck')) ?></h2>
    <p class="muted"><?= e(t('cfg.integrityNote')) ?></p>
    <?php if ($integrity['broken'] === []): ?>
      <p class="status status-on"><?= e(t('cfg.checkedNoAnomaly', ['count' => $integrity['total']])) ?></p>
    <?php else: ?>
      <p class="status status-off"><?= e(t('cfg.anomalyCount', ['count' => count($integrity['broken'])])) ?></p>
      <ul class="org-list">
        <?php foreach ($integrity['broken'] as $document): ?>
          <li class="org-item">
            <?= e($document['title']) ?>
            <span class="cell-sub"><?= e(t('cfg.holderNumber', ['id' => (int) $document['user_id']])) ?> · <?= e(t('cfg.toCheck')) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <p class="cell-sub"><?= e(t('cfg.checkedFingerprints')) ?> : <?= (int) $integrity['total'] ?></p>
  </div>
</section>
