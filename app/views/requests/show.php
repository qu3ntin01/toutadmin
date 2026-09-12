<?php $csrf = \App\Core\Csrf::field(); ?>
<div class="card">
  <div class="org-head">
    <div>
      <span class="org-name"><?= e($request['form']['label'] ?? '—') ?></span>
      <span class="cell-sub"><?= e(t('req.submitted')) ?> <?= e($request['created_at']) ?> · <?= e($request['requesterName']) ?></span>
    </div>
    <span class="status <?= $request['status'] === 'Approuvée' ? 'status-on' : ($request['status'] === 'En cours' ? 'status-wait' : 'status-off') ?>">
      <?= e($request['status']) ?>
    </span>
  </div>

  <h3><?= e(t('req.content')) ?></h3>
  <dl class="detail-list">
    <?php foreach (($request['form']['fieldList'] ?? []) as $field): ?>
      <div>
        <dt><?= e($field['label']) ?></dt>
        <dd><?= e((string) ($request['values'][$field['name']] ?? '—')) ?></dd>
      </div>
    <?php endforeach; ?>
  </dl>
</div>

<div class="card mt-l">
  <h2><?= e(t('req.flow')) ?></h2>
  <?php if ($request['steps'] === []): ?>
    <p class="muted"><?= e(t('req.noApprovalNeeded')) ?></p>
  <?php else: ?>
    <ol class="steps">
      <?php foreach ($request['steps'] as $index => $step): ?>
        <?php
          $decision = null;
          foreach ($request['decisions'] as $row) {
              if ((int) $row['step_id'] === (int) $step['id']) {
                  $decision = $row;
                  break;
              }
          }
        ?>
        <li>
          <strong><?= e(array_column(\App\Modules\Workflows::APPROVERS, 'label', 'key')[$step['approver']] ?? $step['approver']) ?></strong>
          <?php if ($decision !== null): ?>
            — <?= e($decision['decision']) ?>
            <span class="cell-sub">
              <?= e(t('req.byOn', [
                  'name' => trim(($decision['first_name'] ?? '') . ' ' . ($decision['last_name'] ?? '')),
                  'date' => (string) $decision['decided_at'],
              ])) ?>
            </span>
            <?php if (!empty($decision['note'])): ?><br /><span class="cell-sub"><?= e($decision['note']) ?></span><?php endif; ?>
          <?php elseif ($index === $request['stepIndex']): ?>
            — <span class="status status-wait"><?= e(t('status.pending')) ?></span>
            <span class="cell-sub">
              <?= e(implode(', ', array_map(
                  static fn (array $a): string => trim($a['first_name'] . ' ' . $a['last_name']),
                  $request['pendingApprovers']
              ))) ?>
            </span>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</div>

<?php if ($canDecide): ?>
  <div class="card mt-l">
    <h2><?= e(t('req.yourDecision')) ?></h2>
    <form method="POST" action="/demandes/<?= (int) $request['id'] ?>/decision" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('req.commentOptional')) ?></span>
        <input type="text" name="note" maxlength="1000" placeholder="<?= e(t('req.refusalPlaceholder')) ?>" />
      </label>
      <button type="submit" name="decision" value="Approuvée" class="btn btn-primary"><?= e(t('hr.approve')) ?></button>
      <button type="submit" name="decision" value="Refusée" class="btn"><?= e(t('hr.refuse')) ?></button>
    </form>
  </div>
<?php endif; ?>

<?php if ($isRequester && $request['status'] === 'En cours' && $request['decisions'] === []): ?>
  <form method="POST" action="/demandes/<?= (int) $request['id'] ?>/annuler" class="mt-l"
        data-confirm="<?= e(t('req.confirmWithdraw')) ?>">
    <?= $csrf ?>
    <button type="submit" class="btn btn-sm"><?= e(t('req.withdraw')) ?></button>
    <span class="cell-sub"><?= e(t('req.withdrawNote')) ?></span>
  </form>
<?php endif; ?>
