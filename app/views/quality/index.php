<?php
$csrf = \App\Core\Csrf::field();
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso;
$who = static fn (array $row): string =>
    trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: '—';
$number = static fn (float $value): string => number_format($value, 2, ',', ' ');
?>
<section class="stats-grid">
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value"><?= (int) $stats['open'] ?></span>
      <span class="stat-label">
        <?= e(t('qua.openNc')) ?><?= $stats['critical'] ? ' · ' . e(t('qua.criticalCount', ['count' => $stats['critical']])) : '' ?>
      </span>
    </span>
  </div>
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value"><?= (int) $stats['openActions'] ?></span>
      <span class="stat-label"><?= e(t('qua.ongoingActions')) ?></span>
    </span>
  </div>
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value"><?= (int) $stats['awaitingVerification'] ?></span>
      <span class="stat-label"><?= e(t('qua.toVerify')) ?></span>
    </span>
  </div>
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value"><?= e($number((float) $stats['cost'])) ?> €</span>
      <span class="stat-label"><?= e(t('qua.gapCost')) ?></span>
    </span>
  </div>
</section>

<!-- ---------- Non-conformités ---------- -->
<section class="tab-panel is-active" id="non-conformites">
  <div class="card">
    <h2><?= e(t('qua.recordGap')) ?></h2>
    <form method="POST" action="/qualite/non-conformites" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('common.title')) ?></span>
        <input type="text" name="title" required maxlength="200" placeholder="<?= e(t('qua.gapPlaceholder')) ?>" />
      </label>
      <label><span><?= e(t('common.origin')) ?></span>
        <select name="source">
          <?php foreach ($sources as $source): ?><option value="<?= e($source) ?>"><?= e($source) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('qua.severity')) ?></span>
        <select name="severity">
          <?php foreach ($severities as $severity): ?><option value="<?= e($severity) ?>"><?= e($severity) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('qua.detectedOn')) ?></span><input type="date" name="detected_on" value="<?= e($today) ?>" required /></label>
      <label><span><?= e(t('qua.estimatedCost')) ?></span><input type="text" name="cost" inputmode="decimal" placeholder="0" /></label>
      <label class="span-2"><span><?= e(t('qua.subject')) ?></span>
        <input type="text" name="subject" maxlength="200" placeholder="<?= e(t('qua.subjectPlaceholder')) ?>" />
      </label>
      <label class="span-2"><span><?= e(t('common.description')) ?></span><textarea name="description" rows="3"></textarea></label>
      <label class="span-2"><span><?= e(t('qua.immediateAction')) ?></span>
        <textarea name="immediate_action" rows="2" placeholder="<?= e(t('qua.immediatePlaceholder')) ?>"></textarea>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('qua.ncCount', ['count' => count($ncList)])) ?></h2>
    <?php if ($ncList === []): ?>
      <div class="empty-state"><?= e(t('qua.noGap')) ?></div>
    <?php else: ?>
      <ul class="meeting-list">
        <?php foreach ($ncList as $nc): ?>
          <li class="meeting-item">
            <div class="meeting-head">
              <span class="meeting-title">
                <span class="mono"><?= e($nc['reference']) ?></span> — <?= e($nc['title']) ?>
              </span>
              <span class="tag <?= $nc['severity'] === 'Critique' ? 'tag-danger' : ($nc['severity'] === 'Majeure' ? 'tag-warning' : '') ?>">
                <?= e($nc['severity']) ?>
              </span>
            </div>
            <p class="cell-sub">
              <?= e($day($nc['detected_on'])) ?> · <?= e($nc['source']) ?><?= $nc['subject'] !== '' ? ' · ' . e($nc['subject']) : '' ?>
              · <?= e(t('qua.detectedBy')) ?> <?= e($who($nc)) ?>
              · <span class="tag <?= $nc['status'] === 'Clôturée' ? 'tag-success' : 'tag-warning' ?>"><?= e(st($nc['status'])) ?></span>
              <?php if ((int) $nc['open_actions'] > 0): ?>
                · <?= e(t('qua.openActionCount', ['count' => (int) $nc['open_actions']])) ?>
              <?php endif; ?>
              <?php if ((float) $nc['cost'] > 0): ?> · <?= e($number((float) $nc['cost'])) ?> €<?php endif; ?>
            </p>
            <?php if ($nc['description'] !== ''): ?><p class="cell-sub"><?= e($nc['description']) ?></p><?php endif; ?>
            <?php if ($nc['immediate_action'] !== ''): ?>
              <details class="minutes">
                <summary><?= e(t('qua.immediateAction')) ?></summary>
                <p><?= e($nc['immediate_action']) ?></p>
              </details>
            <?php endif; ?>

            <form method="POST" action="/qualite/non-conformites/<?= (int) $nc['id'] ?>/cause" class="form-grid">
              <?= $csrf ?>
              <label class="span-2"><span><?= e(t('qua.rootCause')) ?></span>
                <textarea name="root_cause" rows="2" placeholder="<?= e(t('qua.rootCausePlaceholder')) ?>"><?= e($nc['root_cause']) ?></textarea>
              </label>
              <div class="span-2"><button type="submit" class="btn btn-outline btn-block"><?= e(t('qua.recordCause')) ?></button></div>
            </form>

            <div class="row-actions">
              <form method="POST" action="/qualite/non-conformites/<?= (int) $nc['id'] ?>/statut" class="inline-field">
                <?= $csrf ?>
                <select name="status" class="input-sm">
                  <?php foreach ($ncStatuses as $status): ?>
                    <option value="<?= e($status) ?>"<?= $status === $nc['status'] ? ' selected' : '' ?>><?= e(st($status)) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-outline btn-sm"><?= e(t('gov.changeState')) ?></button>
              </form>
              <form method="POST" action="/qualite/non-conformites/<?= (int) $nc['id'] ?>/supprimer" class="inline-form"
                    data-confirm="<?= e(t('qua.confirmDeleteNc')) ?>">
                <?= $csrf ?>
                <button type="submit" class="btn btn-link btn-sm"><?= e(t('common.delete')) ?></button>
              </form>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="hint"><?= e(t('qua.closeRule')) ?></p>
    <?php endif; ?>
  </div>
</section>

<!-- ---------- Actions ---------- -->
<section class="tab-panel" id="actions">
  <div class="card">
    <h2><?= e(t('qua.openAction')) ?></h2>
    <form method="POST" action="/qualite/actions" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('common.action')) ?></span>
        <input type="text" name="label" required maxlength="300" placeholder="<?= e(t('qua.actionPlaceholder')) ?>" />
      </label>
      <label><span><?= e(t('common.type')) ?></span>
        <select name="kind">
          <?php foreach ($actionKinds as $kind): ?><option value="<?= e($kind) ?>"><?= e($kind) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.owner')) ?></span>
        <select name="owner_id">
          <option value="">—</option>
          <?php foreach ($employees as $person): ?>
            <option value="<?= (int) $person['id'] ?>"><?= e($who($person)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('gov.forDate')) ?></span><input type="date" name="due_date" /></label>
      <label><span><?= e(t('qua.nonconformity')) ?></span>
        <select name="nonconformity_id">
          <option value="">—</option>
          <?php foreach ($ncList as $nc): ?>
            <option value="<?= (int) $nc['id'] ?>"><?= e($nc['reference'] . ' — ' . $nc['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('qua.audit')) ?></span>
        <select name="audit_id">
          <option value="">—</option>
          <?php foreach ($auditList as $audit): ?>
            <option value="<?= (int) $audit['id'] ?>"><?= e($audit['reference'] . ' — ' . $audit['scope']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('qua.openTheAction')) ?></button></div>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('qua.actionCount', ['count' => count($actionList)])) ?></h2>
    <?php if ($actionList === []): ?>
      <div class="empty-state"><?= e(t('qua.noAction')) ?></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('erp.dueDate')) ?></th>
              <th><?= e(t('common.action')) ?></th>
              <th><?= e(t('common.type')) ?></th>
              <th><?= e(t('common.owner')) ?></th>
              <th><?= e(t('common.origin')) ?></th>
              <th><?= e(t('common.state')) ?></th>
              <th><?= e(t('qua.effectiveness')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($actionList as $action): ?>
              <tr>
                <td class="cell-sub nowrap">
                  <?php if ($action['due_date'] !== null && $action['due_date'] < $today && $action['status'] !== 'Faite'): ?>
                    <span class="tag tag-danger"><?= e($day($action['due_date'])) ?></span>
                  <?php else: ?>
                    <?= e($day($action['due_date'])) ?>
                  <?php endif; ?>
                </td>
                <td class="cell-strong"><?= e($action['label']) ?></td>
                <td class="cell-sub"><?= e($action['kind']) ?></td>
                <td class="cell-sub"><?= e($who($action)) ?></td>
                <td class="cell-sub mono"><?= e($action['nc_reference'] ?? $action['audit_reference'] ?? '—') ?></td>
                <td>
                  <form method="POST" action="/qualite/actions/<?= (int) $action['id'] ?>/statut" class="inline-field">
                    <?= $csrf ?>
                    <select name="status" class="input-sm">
                      <?php foreach ($actionStatuses as $status): ?>
                        <option value="<?= e($status) ?>"<?= $status === $action['status'] ? ' selected' : '' ?>><?= e(st($status)) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-outline btn-sm"><?= e(t('common.update')) ?></button>
                  </form>
                </td>
                <td>
                  <?php if ($action['effectiveness'] !== 'Non vérifiée'): ?>
                    <span class="tag <?= $action['effectiveness'] === 'Efficace' ? 'tag-success' : 'tag-danger' ?>">
                      <?= e($action['effectiveness'] === 'Efficace' ? t('qua.effective') : t('qua.ineffective')) ?>
                    </span>
                    <span class="cell-sub"><?= e($day($action['verified_on'])) ?></span>
                  <?php elseif ($action['status'] === 'Faite'): ?>
                    <form method="POST" action="/qualite/actions/<?= (int) $action['id'] ?>/efficacite" class="inline-field">
                      <?= $csrf ?>
                      <select name="effectiveness" class="input-sm">
                        <option value="Efficace"><?= e(t('qua.effective')) ?></option>
                        <option value="Inefficace"><?= e(t('qua.ineffective')) ?></option>
                      </select>
                      <button type="submit" class="btn btn-outline btn-sm"><?= e(t('common.verify')) ?></button>
                    </form>
                  <?php else: ?>
                    <span class="cell-sub">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="hint"><?= e(t('qua.effectivenessRule')) ?></p>
    <?php endif; ?>
  </div>
</section>

<!-- ---------- Audits ---------- -->
<section class="tab-panel" id="audits">
  <div class="card">
    <h2><?= e(t('qua.planAudit')) ?></h2>
    <form method="POST" action="/qualite/audits" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('qua.scope')) ?></span>
        <input type="text" name="scope" required maxlength="200" placeholder="<?= e(t('qua.scopePlaceholder')) ?>" />
      </label>
      <label><span><?= e(t('qua.standard')) ?></span><input type="text" name="standard" maxlength="120" placeholder="ISO 9001:2015" /></label>
      <label><span><?= e(t('qua.plannedOn')) ?></span><input type="date" name="planned_on" /></label>
      <label class="span-2"><span><?= e(t('qua.auditor')) ?></span>
        <select name="auditor_id">
          <option value="">—</option>
          <?php foreach ($employees as $person): ?>
            <option value="<?= (int) $person['id'] ?>"><?= e($who($person)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('qua.plan')) ?></button></div>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('qua.auditCount', ['count' => count($auditList)])) ?></h2>
    <?php if ($auditList === []): ?>
      <div class="empty-state"><?= e(t('qua.noAudit')) ?></div>
    <?php else: ?>
      <ul class="meeting-list">
        <?php foreach ($auditList as $audit): ?>
          <li class="meeting-item">
            <div class="meeting-head">
              <span class="meeting-title">
                <span class="mono"><?= e($audit['reference']) ?></span> — <?= e($audit['scope']) ?>
              </span>
              <span class="tag <?= $audit['status'] === 'Planifié' ? 'tag-warning' : 'tag-success' ?>"><?= e(st($audit['status'])) ?></span>
            </div>
            <p class="cell-sub">
              <?= e($audit['standard'] !== '' ? $audit['standard'] : t('qua.noStandard')) ?>
              · <?= e(t('qua.plannedFor')) ?> <?= e($day($audit['planned_on'])) ?>
              <?php if ($audit['done_on'] !== null): ?> · <?= e(t('qua.doneOnLower')) ?> <?= e($day($audit['done_on'])) ?><?php endif; ?>
              · <?= e($who($audit)) ?> · <?= e(t('qua.findingCount', ['count' => (int) $audit['finding_count']])) ?>
              <?php if ((int) $audit['nc_count'] > 0): ?>, <?= e(t('qua.ofWhichNc', ['count' => (int) $audit['nc_count']])) ?><?php endif; ?>
            </p>
            <?php if ($audit['summary'] !== ''): ?>
              <details class="minutes"><summary><?= e(t('qua.summary')) ?></summary><p><?= e($audit['summary']) ?></p></details>
            <?php endif; ?>

            <?php $list = $findingsByAudit[(int) $audit['id']] ?? []; ?>
            <?php if ($list !== []): ?>
              <div class="table-wrap">
                <table class="table">
                  <thead>
                    <tr>
                      <th><?= e(t('common.type')) ?></th>
                      <th><?= e(t('qua.requirement')) ?></th>
                      <th><?= e(t('qua.finding')) ?></th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($list as $finding): ?>
                      <tr>
                        <td>
                          <span class="tag <?= $finding['kind'] === 'Non-conformité' ? 'tag-danger' : ($finding['kind'] === 'Point fort' ? 'tag-success' : '') ?>">
                            <?= e($finding['kind']) ?>
                          </span>
                        </td>
                        <td class="cell-sub mono"><?= e($finding['clause'] !== '' ? $finding['clause'] : '—') ?></td>
                        <td class="cell-sub"><?= e($finding['statement']) ?></td>
                        <td>
                          <div class="row-actions">
                            <?php if ($finding['kind'] === 'Non-conformité'): ?>
                              <form method="POST" action="/qualite/constats/<?= (int) $finding['id'] ?>/en-non-conformite" class="inline-form">
                                <?= $csrf ?>
                                <button type="submit" class="btn btn-outline btn-sm"><?= e(t('qua.openNcFromFinding')) ?></button>
                              </form>
                            <?php endif; ?>
                            <form method="POST" action="/qualite/constats/<?= (int) $finding['id'] ?>/supprimer" class="inline-form">
                              <?= $csrf ?>
                              <button type="submit" class="btn btn-link btn-sm"><?= e(t('common.remove')) ?></button>
                            </form>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>

            <form method="POST" action="/qualite/audits/<?= (int) $audit['id'] ?>/constats" class="form-grid">
              <?= $csrf ?>
              <label><span><?= e(t('qua.findingType')) ?></span>
                <select name="kind">
                  <?php foreach ($findingKinds as $kind): ?><option value="<?= e($kind) ?>"><?= e($kind) ?></option><?php endforeach; ?>
                </select>
              </label>
              <label><span><?= e(t('qua.requirement')) ?></span><input type="text" name="clause" maxlength="60" placeholder="8.4.1" /></label>
              <label class="span-2"><span><?= e(t('qua.finding')) ?></span><textarea name="statement" rows="2" required></textarea></label>
              <div class="span-2"><button type="submit" class="btn btn-outline btn-block"><?= e(t('qua.addFinding')) ?></button></div>
            </form>

            <?php if ($audit['status'] === 'Planifié'): ?>
              <form method="POST" action="/qualite/audits/<?= (int) $audit['id'] ?>/realiser" class="form-grid">
                <?= $csrf ?>
                <label><span><?= e(t('qua.doneOn')) ?></span><input type="date" name="done_on" value="<?= e($today) ?>" required /></label>
                <label class="span-2"><span><?= e(t('qua.summary')) ?></span><textarea name="summary" rows="3"></textarea></label>
                <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('qua.closeAudit')) ?></button></div>
              </form>
            <?php endif; ?>

            <div class="row-actions">
              <form method="POST" action="/qualite/audits/<?= (int) $audit['id'] ?>/supprimer" class="inline-form"
                    data-confirm="<?= e(t('qua.confirmDeleteAudit')) ?>">
                <?= $csrf ?>
                <button type="submit" class="btn btn-link btn-sm"><?= e(t('common.delete')) ?></button>
              </form>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>
