<?php
$csrf = \App\Core\Csrf::field();
$euro = static fn (?float $v): string => $v === null ? '—' : number_format($v, 2, ',', ' ') . ' €';
$fullName = static fn (array $p): string => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
?>
<section class="stats-grid">
  <?php foreach ([
      [$profit['hours'] . ' h', t('prj.hoursSpent')],
      [$euro($profit['cost']), t('prj.estimatedCost')],
      [$euro($profit['budget']), t('prj.budget')],
      [$profit['margin'] === null ? '—' : $euro($profit['margin']), t('prj.remainingMargin')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e((string) $value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<div class="card" id="taches">
  <h2><?= e(t('prj.tabTasks')) ?></h2>
  <div class="grid grid-4">
    <?php foreach ($board as $column): ?>
      <div class="sub-card">
        <h3><?= e(st($column['status'])) ?> <span class="muted">(<?= count($column['items']) ?>)</span></h3>
        <?php foreach ($column['items'] as $task): ?>
          <div class="task">
            <strong><?= e($task['title']) ?></strong>
            <?php if (!empty($task['first_name'])): ?>
              <br /><span class="cell-sub"><?= e($fullName($task)) ?></span>
            <?php endif; ?>
            <?php if (!empty($task['due_date'])): ?>
              <br /><span class="cell-sub"><?= e($task['due_date']) ?></span>
            <?php endif; ?>
            <form method="POST" action="/projets/taches/<?= (int) $task['id'] ?>/statut" class="inline-form">
              <?= $csrf ?>
              <select name="status" onchange="this.form.submit()">
                <?php foreach ($taskStatuses as $status): ?>
                  <option value="<?= e($status) ?>"<?= $task['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
                <?php endforeach; ?>
              </select>
              <noscript><button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button></noscript>
            </form>
            <?php if ($canManage): ?>
              <form method="POST" action="/projets/taches/<?= (int) $task['id'] ?>/supprimer" class="inline-form">
                <?= $csrf ?>
                <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <h3 class="mt-l"><?= e(t('prj.addTask')) ?></h3>
  <form method="POST" action="/projets/<?= (int) $project['id'] ?>/taches" class="form-grid">
    <?= $csrf ?>
    <label class="span-2"><span><?= e(t('common.title')) ?></span><input type="text" name="title" required maxlength="200" /></label>
    <label><span><?= e(t('prj.assignedTo')) ?></span>
      <select name="assignee_id">
        <option value=""></option>
        <?php foreach ($people as $person): ?>
          <option value="<?= (int) $person['id'] ?>"><?= e($fullName($person)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><span><?= e(t('common.priority')) ?></span>
      <select name="priority">
        <?php foreach ($priorities as $priority): ?>
          <option value="<?= e($priority) ?>"<?= $priority === 'Normale' ? ' selected' : '' ?>><?= e($priority) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><span><?= e(t('prj.dueLower')) ?></span><input type="date" name="due_date" /></label>
    <label><span><?= e(t('prj.estimateHours')) ?></span><input type="text" name="estimate_hours" inputmode="decimal" /></label>
    <button type="submit" class="btn btn-primary"><?= e(t('common.add')) ?></button>
  </form>
</div>

<div class="card mt-l" id="jalons">
  <h2><?= e(t('prj.tabMilestones')) ?></h2>
  <?php if ($milestoneList === []): ?>
    <div class="empty-state"><?= e(t('prj.noMilestone')) ?></div>
  <?php else: ?>
    <ul class="people">
      <?php foreach ($milestoneList as $milestone): ?>
        <li>
          <strong><?= e($milestone['title']) ?></strong>
          <span class="cell-sub"> · <?= e((string) ($milestone['due_date'] ?? '—')) ?></span>
          <?php if ($milestone['reached_on'] !== null): ?>
            <span class="status status-on"><?= e($milestone['reached_on']) ?></span>
          <?php endif; ?>
          <?php if ($canManage): ?>
            <form method="POST" action="/projets/jalons/<?= (int) $milestone['id'] ?>/basculer" class="inline-form">
              <?= $csrf ?>
              <button type="submit" class="btn btn-sm"><?= e($milestone['reached_on'] === null ? t('prj.reachedOn') : t('common.cancel')) ?></button>
            </form>
            <form method="POST" action="/projets/jalons/<?= (int) $milestone['id'] ?>/supprimer" class="inline-form">
              <?= $csrf ?>
              <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
            </form>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($canManage): ?>
    <form method="POST" action="/projets/<?= (int) $project['id'] ?>/jalons" class="inline-form">
      <?= $csrf ?>
      <input type="text" name="title" placeholder="<?= e(t('common.title')) ?>" required maxlength="160" />
      <input type="date" name="due_date" />
      <button type="submit" class="btn btn-sm btn-primary"><?= e(t('common.add')) ?></button>
    </form>
  <?php endif; ?>
</div>

<div class="card mt-l" id="equipe">
  <h2><?= e(t('prj.roleOnProject')) ?></h2>
  <ul class="people">
    <?php foreach ($memberList as $member): ?>
      <li>
        <strong><?= e($fullName($member)) ?></strong>
        <?php if (!empty($member['role'])): ?><span class="cell-sub"> · <?= e($member['role']) ?></span><?php endif; ?>
        <?php if ($canManage): ?>
          <form method="POST" action="/projets/<?= (int) $project['id'] ?>/membres/<?= (int) $member['user_id'] ?>/retirer" class="inline-form">
            <?= $csrf ?>
            <button type="submit" class="btn btn-sm"><?= e(t('common.remove')) ?></button>
          </form>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>

  <?php if ($canManage): ?>
    <form method="POST" action="/projets/<?= (int) $project['id'] ?>/membres" class="inline-form">
      <?= $csrf ?>
      <select name="user_id" required>
        <option value=""></option>
        <?php foreach ($people as $person): ?>
          <option value="<?= (int) $person['id'] ?>"><?= e($fullName($person)) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="role" maxlength="60" placeholder="<?= e(t('common.role')) ?>" />
      <button type="submit" class="btn btn-sm btn-primary"><?= e(t('common.add')) ?></button>
    </form>
  <?php endif; ?>
</div>

<div class="card mt-l" id="temps">
  <h2><?= e(t('prj.logTime')) ?></h2>
  <form method="POST" action="/projets/<?= (int) $project['id'] ?>/temps" class="form-grid">
    <?= $csrf ?>
    <label><span><?= e(t('common.date')) ?></span><input type="date" name="spent_on" value="<?= e($today) ?>" required /></label>
    <label><span><?= e(t('prj.timeSpent')) ?></span><input type="text" name="hours" inputmode="decimal" required /></label>
    <label><span><?= e(t('prj.noTaskOption')) ?></span>
      <select name="task_id">
        <option value=""></option>
        <?php foreach ($taskList as $task): ?>
          <option value="<?= (int) $task['id'] ?>"><?= e($task['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><span><?= e(t('common.note')) ?></span><input type="text" name="note" maxlength="300" /></label>
    <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
  </form>

  <?php if ($entries !== []): ?>
    <table class="table mt-l">
      <thead>
        <tr><th><?= e(t('common.date')) ?></th><th><?= e(t('common.member')) ?></th><th><?= e(t('prj.timeSpent')) ?></th><th><?= e(t('common.note')) ?></th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($entries as $entry): ?>
          <tr>
            <td><?= e($entry['spent_on']) ?></td>
            <td><?= e($fullName($entry)) ?></td>
            <td><?= e((string) $entry['hours']) ?> h</td>
            <td><?= e((string) $entry['note']) ?><?php if (!empty($entry['task_title'])): ?><br /><span class="cell-sub"><?= e($entry['task_title']) ?></span><?php endif; ?></td>
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

  <?php if ($byMember !== []): ?>
    <h3 class="mt-l"><?= e(t('prj.perPerson')) ?></h3>
    <ul class="people">
      <?php foreach ($byMember as $row): ?>
        <li><?= e($fullName($row)) ?> — <strong><?= e((string) round((float) $row['hours'], 2)) ?> h</strong></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>

<?php if ($canManage): ?>
  <div class="card mt-l" id="reglages">
    <h2><?= e(t('common.edit')) ?></h2>
    <form method="POST" action="/projets/<?= (int) $project['id'] ?>/modifier" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.reference')) ?></span><input type="text" name="code" value="<?= e((string) $project['code']) ?>" maxlength="20" /></label>
      <label><span><?= e(t('common.name')) ?></span><input type="text" name="name" value="<?= e($project['name']) ?>" required maxlength="160" /></label>
      <label><span><?= e(t('common.status')) ?></span>
        <select name="status">
          <?php foreach ($statuses as $status): ?>
            <option value="<?= e($status) ?>"<?= $project['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('prj.who')) ?></span>
        <select name="lead_id">
          <option value=""></option>
          <?php foreach ($people as $person): ?>
            <option value="<?= (int) $person['id'] ?>"<?= (int) ($project['lead_id'] ?? 0) === (int) $person['id'] ? ' selected' : '' ?>>
              <?= e($fullName($person)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('leave.from')) ?></span><input type="date" name="start_date" value="<?= e((string) $project['start_date']) ?>" /></label>
      <label><span><?= e(t('leave.to')) ?></span><input type="date" name="due_date" value="<?= e((string) $project['due_date']) ?>" /></label>
      <label><span><?= e(t('prj.budget')) ?></span><input type="text" name="budget_amount" value="<?= e((string) $project['budget_amount']) ?>" inputmode="decimal" /></label>
      <label><span><?= e(t('prj.hourlyRate')) ?></span><input type="text" name="hourly_rate" value="<?= e((string) $project['hourly_rate']) ?>" inputmode="decimal" /></label>
      <label class="span-2"><span><?= e(t('common.description')) ?></span><textarea name="description" rows="3" maxlength="4000"><?= e((string) $project['description']) ?></textarea></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
    </form>

    <div class="row-actions mt-l">
      <form method="POST" action="/projets/<?= (int) $project['id'] ?>/archiver">
        <?= $csrf ?>
        <button type="submit" class="btn btn-sm"><?= e((int) $project['archived'] === 1 ? t('common.activate') : t('prj.archived')) ?></button>
      </form>
      <form method="POST" action="/projets/<?= (int) $project['id'] ?>/supprimer" data-confirm="<?= e(t('prj.confirmDeleteProject')) ?>">
        <?= $csrf ?>
        <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
      </form>
    </div>
  </div>
<?php endif; ?>
