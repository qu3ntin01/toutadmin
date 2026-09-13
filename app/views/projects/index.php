<?php
$csrf = \App\Core\Csrf::field();
$euro = static fn (?float $v): string => $v === null ? '—' : number_format($v, 2, ',', ' ') . ' €';
?>
<section class="stats-grid">
  <?php foreach ([
      [$summary['open'], t('prj.openProjects')],
      [$summary['late'], t('prj.late')],
      [$summary['openTasks'], t('prj.openTasks')],
      [$summary['hours'], t('prj.entries30')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e((string) $value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<section class="tab-panel is-active" id="projets">
  <?php if ($canManage): ?>
    <div class="card">
      <h2><?= e(t('prj.openProject')) ?></h2>
      <form method="POST" action="/projets" class="form-grid">
        <?= $csrf ?>
        <label><span><?= e(t('common.reference')) ?></span><input type="text" name="code" maxlength="20" /></label>
        <label><span><?= e(t('common.name')) ?></span><input type="text" name="name" required maxlength="160" /></label>
        <label><span><?= e(t('prj.who')) ?></span>
          <select name="lead_id">
            <option value=""></option>
            <?php foreach ($people as $person): ?>
              <option value="<?= (int) $person['id'] ?>"><?= e(trim($person['first_name'] . ' ' . $person['last_name'])) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('common.status')) ?></span>
          <select name="status">
            <?php foreach ($statuses as $status): ?>
              <option value="<?= e($status) ?>"><?= e(st($status)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('common.department')) ?></span>
          <select name="department_id">
            <option value=""></option>
            <?php foreach ($departments as $department): ?>
              <option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('common.team')) ?></span>
          <select name="team_id">
            <option value=""></option>
            <?php foreach ($teams as $team): ?>
              <option value="<?= (int) $team['id'] ?>"><?= e($team['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('leave.from')) ?></span><input type="date" name="start_date" /></label>
        <label><span><?= e(t('leave.to')) ?></span><input type="date" name="due_date" /></label>
        <label><span><?= e(t('prj.budget')) ?></span><input type="text" name="budget_amount" inputmode="decimal" /></label>
        <label><span><?= e(t('prj.hourlyRate')) ?></span><input type="text" name="hourly_rate" inputmode="decimal" /></label>
        <label class="span-2"><span><?= e(t('common.description')) ?></span><textarea name="description" rows="2" maxlength="4000"></textarea></label>
        <button type="submit" class="btn btn-primary"><?= e(t('common.create')) ?></button>
      </form>
    </div>
  <?php endif; ?>

  <div class="card mt-l">
    <div class="org-head">
      <h2><?= e(t('nav.projects')) ?></h2>
      <a class="btn btn-sm" href="/projets?archives=<?= $showArchived ? '0' : '1' ?>">
        <?= e($showArchived ? t('prj.tabAll') : t('prj.archived')) ?>
      </a>
    </div>
    <?php if ($projectList === []): ?>
      <div class="empty-state"><?= e(t('prj.noProject')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.name')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th><?= e(t('prj.dueLower')) ?></th>
            <th><?= e(t('prj.hoursSpent')) ?></th>
            <th><?= e(t('prj.budget')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($projectList as $project): ?>
            <tr>
              <td>
                <strong><?= e($project['name']) ?></strong>
                <?php if (!empty($project['code'])): ?><br /><span class="cell-sub"><?= e($project['code']) ?></span><?php endif; ?>
              </td>
              <td><span class="status <?= in_array($project['status'], ['Livré', 'Clôturé'], true) ? 'status-on' : 'status-wait' ?>"><?= e(st($project['status'])) ?></span></td>
              <td><?= e((string) ($project['due_date'] ?? '—')) ?></td>
              <td><?= e((string) $project['profit']['hours']) ?> h</td>
              <td>
                <?= e($euro($project['profit']['budget'])) ?>
                <?php if ($project['profit']['consumed'] !== null): ?>
                  <br /><span class="cell-sub"><?= (int) $project['profit']['consumed'] ?> %</span>
                <?php endif; ?>
              </td>
              <td><a class="btn btn-sm" href="/projets/<?= (int) $project['id'] ?>"><?= e(t('common.open')) ?></a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<section class="tab-panel" id="mes-taches">
  <div class="card">
    <h2><?= e(t('prj.tabMyTasks')) ?></h2>
    <?php if ($myTasks === []): ?>
      <div class="empty-state"><?= e(t('prj.nothingWaiting')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr><th><?= e(t('nav.projects')) ?></th><th><?= e(t('common.title')) ?></th><th><?= e(t('common.status')) ?></th><th><?= e(t('prj.dueLower')) ?></th></tr>
        </thead>
        <tbody>
          <?php foreach ($myTasks as $task): ?>
            <tr>
              <td><a href="/projets/<?= (int) $task['project_id'] ?>"><?= e($task['project_name']) ?></a></td>
              <td><?= e($task['title']) ?></td>
              <td><?= e(st($task['status'])) ?></td>
              <td><?= e((string) ($task['due_date'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<section class="tab-panel" id="mon-temps">
  <div class="card">
    <h2><?= e(t('prj.tabMyTime')) ?></h2>
    <?php if ($myTime === []): ?>
      <div class="empty-state"><?= e(t('prj.noTimeLogged')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr><th><?= e(t('common.date')) ?></th><th><?= e(t('nav.projects')) ?></th><th><?= e(t('prj.timeSpent')) ?></th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($myTime as $entry): ?>
            <tr>
              <td><?= e(\App\Core\Dates::short((string) $entry['spent_on'])) ?></td>
              <td><?= e($entry['project_name']) ?><?php if (!empty($entry['task_title'])): ?><br /><span class="cell-sub"><?= e($entry['task_title']) ?></span><?php endif; ?></td>
              <td><?= e((string) $entry['hours']) ?> h</td>
              <td>
                <form method="POST" action="/projets/temps/<?= (int) $entry['id'] ?>/supprimer">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm"><?= e(t('common.delete')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>
