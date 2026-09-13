<?php
$csrf = \App\Core\Csrf::field();
$statusClass = static fn (string $status): string => match ($status) {
    'Approuvée' => 'status-on',
    'Refusée', 'Annulée' => 'status-off',
    default => 'status-wait',
};
?>
<section class="tab-panel is-active" id="nouvelle">
  <?php if ($openForms === []): ?>
    <div class="card"><div class="empty-state"><?= e(t('dmd.noOpenType')) ?></div></div>
  <?php endif; ?>

  <?php foreach ($openForms as $form): ?>
    <div class="card mt-l">
      <h2><?= e($form['label']) ?></h2>
      <?php if (!empty($form['description'])): ?><p class="muted"><?= e($form['description']) ?></p><?php endif; ?>

      <?php if ($form['steps'] !== []): ?>
        <p class="cell-sub">
          <?= e(t('req.flow')) ?> :
          <?= e(implode(' → ', array_map(static function (array $step): string {
              $names = array_column(\App\Modules\Workflows::APPROVERS, 'label', 'key');
              $label = $names[$step['approver']] ?? $step['approver'];
              return (float) $step['threshold'] > 0
                  ? $label . ' (> ' . (float) $step['threshold'] . ' €)'
                  : $label;
          }, $form['steps']))) ?>
        </p>
      <?php endif; ?>

      <form method="POST" action="/demandes" class="form-grid">
        <?= $csrf ?>
        <input type="hidden" name="form_id" value="<?= (int) $form['id'] ?>" />
        <?php foreach ($form['fieldList'] as $field): ?>
          <label class="<?= $field['type'] === 'zone' ? 'span-2' : '' ?>">
            <span><?= e($field['label']) ?><?= !empty($field['required']) ? ' *' : '' ?></span>
            <?php if ($field['type'] === 'zone'): ?>
              <textarea name="champ_<?= e($field['name']) ?>" rows="3" maxlength="2000"<?= !empty($field['required']) ? ' required' : '' ?>></textarea>
            <?php elseif ($field['type'] === 'choix'): ?>
              <select name="champ_<?= e($field['name']) ?>"<?= !empty($field['required']) ? ' required' : '' ?>>
                <option value=""></option>
                <?php foreach ($field['options'] as $option): ?>
                  <option value="<?= e($option) ?>"><?= e($option) ?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($field['type'] === 'date'): ?>
              <input type="date" name="champ_<?= e($field['name']) ?>"<?= !empty($field['required']) ? ' required' : '' ?> />
            <?php elseif ($field['type'] === 'nombre' || $field['type'] === 'montant'): ?>
              <input type="text" inputmode="decimal" name="champ_<?= e($field['name']) ?>"<?= !empty($field['required']) ? ' required' : '' ?> />
            <?php else: ?>
              <input type="text" name="champ_<?= e($field['name']) ?>" maxlength="2000"<?= !empty($field['required']) ? ' required' : '' ?> />
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-primary"><?= e(t('leave.send')) ?></button>
      </form>
    </div>
  <?php endforeach; ?>
</section>

<section class="tab-panel" id="mes-demandes">
  <div class="card">
    <h2><?= e(t('leave.myRequests')) ?></h2>
    <?php if ($mine === []): ?>
      <div class="empty-state"><?= e(t('leave.noRequests')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.type')) ?></th>
            <th><?= e(t('req.content')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($mine as $row): ?>
            <tr>
              <td><?= e($row['form']['label'] ?? '—') ?><br /><span class="cell-sub"><?= e(\App\Core\Dates::moment((string) $row['created_at'])) ?></span></td>
              <td><?= e((string) $row['summary']) ?></td>
              <td><span class="status <?= e($statusClass($row['status'])) ?>"><?= e(st($row['status'])) ?></span></td>
              <td><a class="btn btn-sm" href="/demandes/<?= (int) $row['id'] ?>"><?= e(t('common.open')) ?></a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<section class="tab-panel" id="a-decider">
  <div class="card">
    <h2><?= e(t('dmd.awaitingYou')) ?></h2>
    <?php if ($toDecide === []): ?>
      <div class="empty-state"><?= e(t('leave.noRequests')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.member')) ?></th>
            <th><?= e(t('common.type')) ?></th>
            <th><?= e(t('req.content')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($toDecide as $row): ?>
            <tr>
              <td><?= e($row['requesterName']) ?></td>
              <td><?= e($row['form']['label'] ?? '—') ?></td>
              <td><?= e((string) $row['summary']) ?></td>
              <td><a class="btn btn-sm btn-primary" href="/demandes/<?= (int) $row['id'] ?>"><?= e(t('req.yourDecision')) ?></a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<?php if ($admin): ?>
  <section class="tab-panel" id="types">
    <div class="card">
      <h2><?= e(t('dmd.createType')) ?></h2>
      <p class="muted"><?= e(t('dmd.thresholdNote')) ?></p>
      <form method="POST" action="/demandes/types" class="form-grid">
        <?= $csrf ?>
        <label><span><?= e(t('common.name')) ?></span><input type="text" name="label" required maxlength="120" /></label>
        <label><span><?= e(t('dmd.amountField')) ?></span><input type="text" name="amount_field" maxlength="60" placeholder="montant" /></label>
        <label class="span-2"><span><?= e(t('common.description')) ?></span><input type="text" name="description" maxlength="1000" /></label>

        <div class="span-2">
          <h3><?= e(t('dmd.fieldsToFill')) ?></h3>
          <?php for ($i = 0; $i < 5; $i++): ?>
            <div class="inline-form">
              <input type="text" name="field_names[]" placeholder="<?= e(t('dmd.typePlaceholder')) ?>" maxlength="60" />
              <input type="text" name="field_labels[]" placeholder="<?= e(t('dmd.displayedLabel')) ?>" maxlength="120" />
              <select name="field_types[]">
                <?php foreach ($fieldTypes as $type): ?>
                  <option value="<?= e($type['key']) ?>"><?= e($type['label']) ?></option>
                <?php endforeach; ?>
              </select>
              <select name="field_required[]">
                <option value="0"><?= e(t('common.optional')) ?></option>
                <option value="1"><?= e(t('common.mandatory')) ?></option>
              </select>
              <input type="text" name="field_options[]" placeholder="<?= e(t('hr.commaSeparated')) ?>" maxlength="300" />
            </div>
          <?php endfor; ?>
        </div>
        <button type="submit" class="btn btn-primary"><?= e(t('dmd.createTheType')) ?></button>
      </form>
    </div>

    <div class="card mt-l">
      <h2><?= e(t('dmd.tabFlows')) ?></h2>
      <?php if ($allForms === []): ?>
        <div class="empty-state"><?= e(t('dmd.noOpenType')) ?></div>
      <?php endif; ?>
      <?php foreach ($allForms as $form): ?>
        <div class="sub-card">
          <div class="org-head">
            <div>
              <span class="org-name"><?= e($form['label']) ?></span>
              <span class="cell-sub"><?= count($form['fieldList']) ?> <?= e(t('dmd.fieldsToFill')) ?></span>
            </div>
            <span class="status <?= (int) $form['active'] === 1 ? 'status-on' : 'status-off' ?>">
              <?= e((int) $form['active'] === 1 ? t('common.active') : t('common.inactive')) ?>
            </span>
          </div>

          <ol class="steps">
            <?php if ($form['steps'] === []): ?>
              <li class="cell-sub"><?= e(t('dmd.noStep')) ?></li>
            <?php endif; ?>
            <?php foreach ($form['steps'] as $step): ?>
              <li>
                <?php $byKey = array_column(\App\Modules\Workflows::APPROVERS, 'label', 'key'); ?>
                <?= e(trim((string) ($step['label'] ?? '')) !== ''
                      ? (string) $step['label']
                      : ($byKey[$step['approver']] ?? $step['approver'])) ?>
                <?php if (trim((string) ($step['label'] ?? '')) !== ''): ?>
                  <span class="cell-sub"><?= e($byKey[$step['approver']] ?? $step['approver']) ?></span>
                <?php endif; ?>
                <?php if ($step['approver'] === 'user'): ?>
                  — <?= e(trim(($step['first_name'] ?? '') . ' ' . ($step['last_name'] ?? ''))) ?>
                <?php endif; ?>
                <?php if ((float) $step['threshold'] > 0): ?>
                  <span class="cell-sub">&gt; <?= e((string) (float) $step['threshold']) ?> €</span>
                <?php endif; ?>
                <form method="POST" action="/demandes/types/<?= (int) $form['id'] ?>/etapes/<?= (int) $step['id'] ?>/supprimer" class="inline-form">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm"><?= e(t('common.remove')) ?></button>
                </form>
              </li>
            <?php endforeach; ?>
          </ol>

          <form method="POST" action="/demandes/types/<?= (int) $form['id'] ?>/etapes" class="inline-form">
            <?= $csrf ?>
            <select name="approver" required>
              <?php foreach ($approvers as $approver): ?>
                <option value="<?= e($approver['key']) ?>"><?= e($approver['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <select name="approver_id">
              <option value=""></option>
              <?php foreach ($employees as $employee): ?>
                <option value="<?= (int) $employee['id'] ?>"><?= e(trim($employee['first_name'] . ' ' . $employee['last_name'])) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="text" name="label" maxlength="80" placeholder="<?= e(t('dmd.stepPlaceholder')) ?>" />
            <input type="text" name="threshold" inputmode="decimal" placeholder="<?= e(t('common.threshold')) ?>" />
            <button type="submit" class="btn btn-sm btn-primary"><?= e(t('dmd.addStep')) ?></button>
          </form>

          <div class="row-actions">
            <form method="POST" action="/demandes/types/<?= (int) $form['id'] ?>/etat">
              <?= $csrf ?>
              <input type="hidden" name="active" value="<?= (int) $form['active'] === 1 ? '0' : '1' ?>" />
              <button type="submit" class="btn btn-sm">
                <?= e((int) $form['active'] === 1 ? t('common.deactivate') : t('common.unlock')) ?>
              </button>
            </form>
            <form method="POST" action="/demandes/types/<?= (int) $form['id'] ?>/supprimer"
                  data-confirm="<?= e(t('admin.deleteMember')) ?>">
              <?= $csrf ?>
              <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>
