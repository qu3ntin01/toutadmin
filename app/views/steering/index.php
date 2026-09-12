<?php
$csrf = \App\Core\Csrf::field();
$money = static fn (mixed $value): string => $value === null
    ? '—' : number_format((float) $value, 0, ',', ' ') . ' €';
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso;
?>
<section class="stats-grid">
  <?php foreach ([
      [(string) $overview['headcount']['total'], t('pil.activeStaff'), ''],
      [$money($overview['revenue']['sales']), t('crm.invoiced') . ' ' . $year, ''],
      [$money($overview['unpaid']['total']), t('pil.unpaidInvoices', ['count' => $overview['unpaid']['count']]), ''],
      [(string) $deadlines['overdue'], t('pil.overdueDeadlines'), $deadlines['overdue'] > 0 ? 'text-danger' : ''],
  ] as [$value, $label, $class]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value <?= e($class) ?>"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<!-- ---------- Tableau de bord ---------- -->
<section class="tab-panel is-active" id="tableau">
  <div class="grid-2">
    <div class="card">
      <div class="card-head"><h2><?= e(t('common.headcount')) ?></h2></div>
      <table class="table">
        <tbody>
          <?php foreach ($overview['headcount']['byContract'] as $row): ?>
            <tr>
              <td class="cell-sub"><?= e($row['contract_type'] ?: 'Non renseigné') ?></td>
              <td class="cell-strong"><?= (int) $row['n'] ?></td>
            </tr>
          <?php endforeach; ?>
          <tr><td class="cell-sub"><?= e(t('pil.absentToday')) ?></td>
            <td class="cell-strong"><?= (int) $overview['absentToday'] ?></td></tr>
          <?php if ($overview['headcount']['byContract'] === []): ?>
            <tr><td class="cell-sub" colspan="2"><?= e(t('pil.noActiveMember')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card">
      <div class="card-head"><h2><?= e(t('pil.activity')) ?> <?= (int) $year ?></h2></div>
      <table class="table">
        <tbody>
          <tr><td class="cell-sub"><?= e(t('pil.billedExclVat')) ?></td>
            <td class="cell-strong"><?= e($money($overview['revenue']['sales'])) ?></td></tr>
          <tr><td class="cell-sub"><?= e(t('pil.supplierPurchases')) ?></td>
            <td><?= e($money($overview['revenue']['purchases'])) ?></td></tr>
          <tr><td class="cell-sub"><?= e(t('pil.grossMargin')) ?></td>
            <td class="cell-strong <?= $overview['revenue']['margin'] < 0 ? 'text-danger' : '' ?>">
              <?= e($money($overview['revenue']['margin'])) ?>
            </td></tr>
          <tr>
            <td class="cell-sub"><?= e(t('pil.payrollCost')) ?></td>
            <td>
              <?= $overview['payroll'] === null
                  ? '— non renseignée'
                  : e($money($overview['payroll']['monthly']) . ' (' . $overview['payroll']['known'] . ' salaires connus)') ?>
            </td>
          </tr>
          <tr>
            <td class="cell-sub"><?= e(t('tre.panel')) ?></td>
            <td class="<?= $overview['treasury'] !== null && $overview['treasury'] < 0 ? 'text-danger' : '' ?>">
              <?= e($overview['treasury'] === null ? t('pil.moduleOff') : $money($overview['treasury'])) ?>
            </td>
          </tr>
          <tr><td class="cell-sub"><?= e(t('pil.openTickets')) ?></td>
            <td><?= (int) $overview['tickets']['open'] ?>
              (<?= e(t('pil.thisMonth', ['count' => (int) $overview['tickets']['month']])) ?>)</td></tr>
        </tbody>
      </table>
      <p class="hint"><?= e(t('pil.dashNote')) ?></p>
    </div>
  </div>
</section>

<!-- ---------- Échéances ---------- -->
<section class="tab-panel" id="echeances">
  <div class="card">
    <div class="card-head">
      <h2><?= e(t('pil.deadlineSummary', ['total' => $deadlines['total'], 'overdue' => $deadlines['overdue']])) ?></h2>
    </div>
    <div class="row-actions">
      <?php foreach ($deadlines['bySource'] as $source => $counts): ?>
        <span class="tag <?= $counts['overdue'] > 0 ? 'tag-danger' : '' ?>">
          <?= e($source) ?> : <?= (int) $counts['total'] ?><?= $counts['overdue'] > 0
              ? ' (' . e(t('pil.lateCount', ['count' => (int) $counts['overdue']])) . ')' : '' ?>
        </span>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card mt-l">
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('erp.dueDate')) ?></th>
            <th><?= e(t('common.source')) ?></th>
            <th><?= e(t('common.subject')) ?></th>
            <th><?= e(t('common.detail')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($deadlines['rows'] as $row): ?>
            <tr>
              <td class="<?= $row['overdue'] ? 'text-danger cell-strong' : 'cell-sub' ?> nowrap"><?= e($day($row['due'])) ?></td>
              <td><span class="tag <?= $row['overdue'] ? 'tag-danger' : '' ?>"><?= e($row['source']) ?></span></td>
              <td class="cell-strong"><?= e($row['label']) ?></td>
              <td class="cell-sub"><?= e($row['detail'] !== '' ? $row['detail'] : '—') ?></td>
              <td>
                <?php if ($row['link'] !== ''): ?>
                  <a class="btn btn-outline btn-sm" href="<?= e($row['link']) ?>"><?= e(t('common.open')) ?></a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($deadlines['rows'] === []): ?>
            <tr><td colspan="5" class="cell-sub"><?= e(t('pil.nothing45')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- ---------- Projets ---------- -->
<section class="tab-panel" id="projets">
  <div class="card">
    <div class="card-head">
      <h2><?= e(t('pil.projectsInProgress', ['count' => count($overview['projects'])])) ?></h2>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.project')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th><?= e(t('erp.dueDate')) ?></th>
            <th><?= e(t('common.hours')) ?></th>
            <th><?= e(t('common.cost')) ?></th>
            <th><?= e(t('common.budget')) ?></th>
            <th><?= e(t('erp.consumed')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($overview['projects'] as $project): ?>
            <tr>
              <td><a class="cell-strong" href="/projets/<?= (int) $project['id'] ?>"><?= e($project['name']) ?></a></td>
              <td><span class="tag"><?= e(st($project['status'])) ?></span></td>
              <td class="<?= $project['late'] ? 'text-danger' : 'cell-sub' ?> nowrap"><?= e($day($project['due_date'])) ?></td>
              <td class="cell-sub"><?= e((string) round((float) $project['hours'], 1)) ?> h</td>
              <td><?= e($money($project['cost'])) ?></td>
              <td class="cell-sub"><?= e($money($project['budget_amount'])) ?></td>
              <td>
                <?php if ($project['consumed'] === null): ?>
                  <span class="cell-sub">—</span>
                <?php else: ?>
                  <span class="meter">
                    <span class="meter-fill <?= $project['consumed'] > 100 ? 'is-danger' : ($project['consumed'] > 80 ? 'is-warn' : '') ?>"
                          data-ratio="<?= min(100, (int) $project['consumed']) ?>"></span>
                  </span>
                  <span class="cell-sub"><?= (int) $project['consumed'] ?> %</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($overview['projects'] === []): ?>
            <tr><td colspan="7" class="cell-sub"><?= e(t('pil.noProjectInProgress')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- ---------- Objectifs ---------- -->
<section class="tab-panel" id="objectifs">
  <div class="card">
    <div class="card-head"><h2><?= e(t('pil.setObjective')) ?></h2></div>
    <form method="POST" action="/pilotage/objectifs" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('common.title')) ?></span>
        <input type="text" name="title" required maxlength="200" placeholder="<?= e(t('pil.objectivePlaceholder')) ?>" />
      </label>
      <label><span><?= e(t('common.scope')) ?></span>
        <select name="scope">
          <?php foreach ($scopes as $scope): ?><option value="<?= e($scope) ?>"><?= e($scope) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.period')) ?></span>
        <input type="text" name="period" maxlength="20" placeholder="2026-T1" />
      </label>
      <label class="span-2"><span><?= e(t('pil.scopeIfLimited')) ?></span>
        <select name="scope_id">
          <option value="">—</option>
          <optgroup label="<?= e(t('org.departments')) ?>">
            <?php foreach ($departments as $department): ?>
              <option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option>
            <?php endforeach; ?>
          </optgroup>
          <optgroup label="<?= e(t('org.teams')) ?>">
            <?php foreach ($teams as $team): ?>
              <option value="<?= (int) $team['id'] ?>"><?= e($team['name']) ?></option>
            <?php endforeach; ?>
          </optgroup>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('gov.holder')) ?></span>
        <select name="owner_id">
          <option value="">—</option>
          <?php foreach ($people as $person): ?>
            <option value="<?= (int) $person['id'] ?>"><?= e($person['first_name'] . ' ' . $person['last_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('common.description')) ?></span>
        <textarea name="description" rows="2"></textarea>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.create')) ?></button></div>
    </form>
  </div>

  <?php foreach ($objectiveList as $objective): ?>
    <div class="card mt-l">
      <div class="card-head">
        <h2><?= e($objective['title']) ?></h2>
        <span class="tag <?= $objective['status'] === 'Atteint'
            ? 'tag-success' : ($objective['status'] === 'Abandonné' ? 'tag-danger' : 'tag-accent') ?>">
          <?= e(st($objective['status'])) ?>
        </span>
      </div>
      <p class="cell-sub">
        <?= e($objective['scope']) ?><?= $objective['period'] !== '' ? ' · ' . e($objective['period']) : '' ?>
        <?= $objective['first_name'] !== null ? ' · ' . e($objective['first_name'] . ' ' . $objective['last_name']) : '' ?>
        <?= $objective['description'] !== '' ? ' · ' . e($objective['description']) : '' ?>
      </p>
      <span class="meter">
        <span class="meter-fill <?= $objective['progress'] >= 100 ? '' : ($objective['progress'] < 34 ? 'is-warn' : '') ?>"
              data-ratio="<?= (int) $objective['progress'] ?>"></span>
      </span>
      <p class="cell-sub"><?= e(t('pil.progressNote', ['percent' => (int) $objective['progress']])) ?></p>

      <ul class="org-list">
        <?php foreach ($objective['results'] as $result): ?>
          <li class="org-item">
            <span>
              <span class="cell-strong"><?= e($result['title']) ?></span>
              <br /><span class="cell-sub">
                <?= e(t('pil.fromToCurrent', [
                    'start' => $result['start_value'], 'target' => $result['target_value'],
                    'unit' => $result['unit'], 'current' => $result['current_value'],
                ])) ?>
              </span>
            </span>
            <span class="row-actions">
              <form method="POST" action="/pilotage/resultats/<?= (int) $result['id'] ?>" class="inline-form">
                <?= $csrf ?>
                <input type="text" name="current_value" value="<?= e((string) $result['current_value']) ?>"
                       class="input-sm" inputmode="decimal" />
                <button type="submit" class="btn btn-sm"><?= e(t('common.update')) ?></button>
              </form>
              <form method="POST" action="/pilotage/resultats/<?= (int) $result['id'] ?>/supprimer" class="inline-form">
                <?= $csrf ?>
                <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.remove')) ?></button>
              </form>
            </span>
          </li>
        <?php endforeach; ?>
        <?php if ($objective['results'] === []): ?><li class="cell-sub"><?= e(t('pil.noKeyResult')) ?></li><?php endif; ?>
      </ul>

      <form method="POST" action="/pilotage/objectifs/<?= (int) $objective['id'] ?>/resultats" class="form-grid">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('pil.keyResult')) ?></span>
          <input type="text" name="title" required maxlength="200" placeholder="<?= e(t('pil.keyResultPlaceholder')) ?>" />
        </label>
        <label><span><?= e(t('acc.departure')) ?></span>
          <input type="text" name="start_value" inputmode="decimal" value="0" required />
        </label>
        <label><span><?= e(t('pil.target')) ?></span>
          <input type="text" name="target_value" inputmode="decimal" required />
        </label>
        <label><span><?= e(t('pil.current')) ?></span><input type="text" name="current_value" inputmode="decimal" /></label>
        <label><span><?= e(t('common.unit')) ?></span>
          <input type="text" name="unit" maxlength="20" placeholder="h, %, €" />
        </label>
        <div class="span-2"><button type="submit" class="btn btn-outline btn-block"><?= e(t('pil.addKeyResult')) ?></button></div>
      </form>

      <div class="row-actions">
        <form method="POST" action="/pilotage/objectifs/<?= (int) $objective['id'] ?>/statut" class="inline-form">
          <?= $csrf ?>
          <select name="status" class="input-sm">
            <?php foreach ($statuses as $status): ?>
              <option value="<?= e($status) ?>"<?= $objective['status'] === $status ? ' selected' : '' ?>><?= e($status) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm"><?= e(t('pil.changeStatus')) ?></button>
        </form>
        <form method="POST" action="/pilotage/objectifs/<?= (int) $objective['id'] ?>/supprimer" class="inline-form"
              data-confirm="<?= e(t('pil.confirmDeleteObjective')) ?>">
          <?= $csrf ?>
          <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.delete')) ?></button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if ($objectiveList === []): ?>
    <div class="card mt-l"><p class="cell-sub"><?= e(t('pil.noObjective')) ?></p></div>
  <?php endif; ?>
</section>
