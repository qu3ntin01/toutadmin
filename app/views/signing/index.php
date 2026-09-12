<?php
$csrf = \App\Core\Csrf::field();
$fullName = static fn (array $p): string => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
$statusClass = static fn (string $status): string => match ($status) {
    'Signé' => 'status-on',
    'Refusé', 'Annulé' => 'status-off',
    default => 'status-wait',
};
?>
<section class="stats-grid">
  <?php foreach ([
      [(string) $stats['running'], t('sig.inProgress')],
      [(string) $stats['signed'], t('sig.signedDocuments')],
      [(string) $stats['refused'], t('sig.refusals')],
      [(string) count($pending), t('sig.awaitingYou')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<section class="tab-panel is-active" id="documents">
  <?php if ($pending !== []): ?>
    <div class="card">
      <h2><?= e(t('sig.tabToSign')) ?></h2>
      <ul class="org-list">
        <?php foreach ($pending as $request): ?>
          <li class="org-item">
            <a href="/parapheur/<?= (int) $request['id'] ?>"><strong><?= e($request['title']) ?></strong></a>
            <span class="cell-sub">
              <?= e($request['kind']) ?>
              · <?= e(t('sig.signatureCount', ['signed' => $request['signedCount'], 'total' => $request['total']])) ?>
              <?php if (!empty($request['deadline'])): ?>
                · <?= e(t('sig.signBeforeDate', ['date' => $request['deadline']])) ?>
              <?php endif; ?>
              <?php if ($request['overdue']): ?>
                <span class="tag tag-off"><?= e(t('sig.overdue')) ?></span>
              <?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="card mt-l">
    <h2><?= e(t('sig.whereYouAppear')) ?></h2>
    <?php if ($mine === []): ?>
      <div class="empty-state"><?= e(t('cfg.emptyVault')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.document')) ?></th><th><?= e(t('common.nature')) ?></th>
            <th><?= e(t('sig.flowState')) ?></th><th><?= e(t('common.status')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($mine as $request): ?>
            <tr>
              <td><a href="/parapheur/<?= (int) $request['id'] ?>"><?= e($request['title']) ?></a></td>
              <td><?= e($request['kind']) ?></td>
              <td><?= e(t('sig.signatureCount', ['signed' => $request['signedCount'], 'total' => $request['total']])) ?></td>
              <td><span class="status <?= e($statusClass($request['status'])) ?>"><?= e($request['status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?php if ($opener): ?>
    <div class="card mt-l">
      <h2><?= e(t('sig.tabAllDocuments')) ?></h2>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.document')) ?></th><th><?= e(t('sig.signatories')) ?></th>
            <th><?= e(t('common.status')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($all as $request): ?>
            <tr>
              <td>
                <a href="/parapheur/<?= (int) $request['id'] ?>"><?= e($request['title']) ?></a>
                <br /><span class="cell-sub"><?= e(t('sig.openedOn', ['date' => substr((string) $request['created_at'], 0, 10)])) ?></span>
              </td>
              <td>
                <?= e(t('sig.signatureCount', ['signed' => $request['signedCount'], 'total' => $request['total']])) ?>
                <?php if ($request['next'] !== null): ?>
                  <br /><span class="cell-sub"><?= e(t('sig.turnOf', ['name' => $fullName($request['next'])])) ?></span>
                <?php endif; ?>
              </td>
              <td><span class="status <?= e($statusClass($request['status'])) ?>"><?= e($request['status']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php if ($opener): ?>
<section class="tab-panel" id="nouveau">
  <div class="card">
    <h2><?= e(t('sig.putDocument')) ?></h2>
    <p class="muted"><?= e(t('sig.frozenNote')) ?></p>
    <form method="POST" action="/parapheur" class="form-grid" enctype="multipart/form-data">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('common.title')) ?></span>
        <input type="text" name="title" required maxlength="200" placeholder="<?= e(t('sig.docPlaceholder')) ?>" />
      </label>
      <label><span><?= e(t('common.nature')) ?></span>
        <select name="kind" required>
          <?php foreach ($kinds as $kind): ?>
            <option value="<?= e($kind) ?>"><?= e($kind) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('sig.signBefore')) ?></span><input type="date" name="deadline" /></label>
      <label class="span-2"><span><?= e(t('sig.fileLabel')) ?></span>
        <input type="file" name="document" accept=".pdf,.docx" />
      </label>
      <label class="span-2"><span><?= e(t('sig.orText')) ?></span>
        <textarea name="body" rows="6" placeholder="<?= e(t('sig.textPlaceholder')) ?>"></textarea>
      </label>

      <div class="span-2">
        <h3><?= e(t('sig.signersInOrder')) ?></h3>
        <?php for ($rank = 0; $rank < 4; $rank++): ?>
          <div class="entry-line">
            <select name="signer_ids[]">
              <option value=""><?= e(t('common.none')) ?></option>
              <?php foreach ($employees as $employee): ?>
                <option value="<?= (int) $employee['id'] ?>"><?= e($fullName($employee)) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="signer_roles[]" maxlength="80" placeholder="<?= e(t('sig.capacityPlaceholder')) ?>" />
          </div>
        <?php endfor; ?>
      </div>

      <button type="submit" class="btn btn-primary"><?= e(t('sig.openFlow')) ?></button>
    </form>
  </div>
</section>
<?php endif; ?>
