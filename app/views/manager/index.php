<?php
$csrf = \App\Core\Csrf::field();
$fullName = static fn (array $p): string => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
?>
<section class="stats-grid">
  <?php foreach ([
      [$stats['teamSize'], t('team.members')],
      [$stats['pending'], t('hr.pendingRequests')],
      [$stats['upcoming'], t('team.onLeave')],
      [$pointStats['neverMet'], t('oto.neverMet')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= (int) $value ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<section class="tab-panel is-active" id="equipe">
  <div class="card">
    <h2><?= e(t('nav.team')) ?></h2>
    <?php if ($team === []): ?>
      <div class="empty-state"><?= e(t('team.empty')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.name')) ?></th>
            <th><?= e(t('common.grade')) ?></th>
            <th><?= e(t('org.assignment')) ?></th>
            <th><?= e(t('leave.balance')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($team as $member): ?>
            <tr>
              <td><strong><?= e($fullName($member)) ?></strong><br /><span class="cell-sub"><?= e($member['email']) ?></span></td>
              <td><?= e((string) $member['grade']) ?></td>
              <td><?= e(trim(($member['team_name'] ?? '') . ' · ' . ($member['department_name'] ?? ''), ' ·')) ?></td>
              <td><?= e((string) $member['leave_balance']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<section class="tab-panel" id="absences">
  <div class="card">
    <h2><?= e(t('nav.requests')) ?></h2>
    <p class="muted"><?= e(t('mgr.scopeNote')) ?></p>
    <?php if ($requests === []): ?>
      <div class="empty-state"><?= e(t('team.noRequests')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.member')) ?></th>
            <th><?= e(t('common.type')) ?></th>
            <th><?= e(t('common.period')) ?></th>
            <th><?= e(t('common.status')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $row): ?>
            <tr>
              <td><?= e($fullName($row)) ?></td>
              <td>
                <?= e($row['type']) ?>
                <?php if (!empty($row['reason'])): ?><br /><span class="cell-sub"><?= e($row['reason']) ?></span><?php endif; ?>
              </td>
              <td><?= e(\App\Core\Dates::short((string) $row['start_date'])) ?> → <?= e(\App\Core\Dates::short((string) $row['end_date'])) ?> (<?= (int) $row['days'] ?>)</td>
              <td>
                <span class="status <?= $row['status'] === 'Approuvée' ? 'status-on' : ($row['status'] === 'En attente' ? 'status-wait' : 'status-off') ?>">
                  <?= e(st($row['status'])) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<section class="tab-panel" id="points">
  <div class="card">
    <h2><?= e(t('oto.subtitle')) ?></h2>
    <p class="muted"><?= e(t('oto.privacyHint')) ?></p>
    <?php if ($cadence === []): ?>
      <div class="empty-state"><?= e(t('team.empty')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr><th><?= e(t('common.member')) ?></th><th><?= e(t('oto.lastHeld')) ?></th><th><?= e(t('oto.nextOn')) ?></th></tr>
        </thead>
        <tbody>
          <?php foreach ($cadence as $row): ?>
            <tr>
              <td><?= e($fullName($row)) ?></td>
              <td><?= $row['last_held'] === null ? '<span class="tag">' . e(t('oto.neverMet')) . '</span>' : e($row['last_held']) ?></td>
              <td><?= e((string) ($row['next_on'] ?? '—')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('oto.plan')) ?></h2>
    <form method="POST" action="/mon-equipe/points" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.member')) ?></span>
        <select name="employee_id" required>
          <option value=""></option>
          <?php foreach ($team as $member): ?>
            <option value="<?= (int) $member['id'] ?>"><?= e($fullName($member)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.date')) ?></span><input type="date" name="scheduled_on" required /></label>
      <label class="span-2"><span><?= e(t('oto.topics')) ?></span><input type="text" name="topics" maxlength="2000" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.create')) ?></button>
    </form>
  </div>

  <?php foreach ($points as $point): ?>
    <div class="card mt-l">
      <div class="org-head">
        <div>
          <span class="org-name"><?= e($fullName($point)) ?></span>
          <span class="cell-sub"><?= e(\App\Core\Dates::short((string) $point['scheduled_on'])) ?></span>
        </div>
        <span class="status <?= $point['status'] === 'Tenu' ? 'status-on' : ($point['status'] === 'Planifié' ? 'status-wait' : 'status-off') ?>">
          <?= e(st($point['status'])) ?>
        </span>
      </div>

      <form method="POST" action="/mon-equipe/points/<?= (int) $point['id'] ?>/modifier" class="form-grid">
        <?= $csrf ?>
        <label><span><?= e(t('common.date')) ?></span>
          <input type="date" name="scheduled_on" value="<?= e($point['scheduled_on']) ?>" required />
        </label>
        <label><span><?= e(t('oto.heldOn')) ?></span>
          <input type="date" name="held_on" value="<?= e((string) $point['held_on']) ?>" />
        </label>
        <label><span><?= e(t('common.status')) ?></span>
          <select name="status">
            <?php foreach ($pointStatuses as $status): ?>
              <option value="<?= e($status) ?>"<?= $point['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('oto.nextOn')) ?></span>
          <input type="date" name="next_on" value="<?= e((string) $point['next_on']) ?>" />
        </label>
        <label class="span-2"><span><?= e(t('oto.topics')) ?></span>
          <textarea name="topics" rows="2" maxlength="2000"><?= e((string) $point['topics']) ?></textarea>
        </label>
        <label class="span-2"><span><?= e(t('oto.sharedNote')) ?></span>
          <textarea name="shared_note" rows="3" maxlength="4000"><?= e((string) $point['shared_note']) ?></textarea>
        </label>
        <label class="span-2"><span><?= e(t('oto.privateNote')) ?></span>
          <textarea name="private_note" rows="3" maxlength="4000"><?= e((string) $point['private_note']) ?></textarea>
        </label>
        <label><span><?= e(t('oto.mood')) ?></span>
          <input type="number" name="mood" min="1" max="5" value="<?= e((string) $point['mood']) ?>" />
        </label>
        <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
      </form>

      <form method="POST" action="/mon-equipe/points/<?= (int) $point['id'] ?>/supprimer"
            data-confirm="<?= e(t('oto.confirmDelete')) ?>">
        <?= $csrf ?>
        <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
      </form>
    </div>
  <?php endforeach; ?>
</section>

<section class="tab-panel" id="actualites">
  <div class="card">
    <h2><?= e(t('team.publishNews')) ?></h2>
    <?php if ($managedScopes === []): ?>
      <div class="empty-state"><?= e(t('mgr.scopeNote')) ?></div>
    <?php else: ?>
      <form method="POST" action="/mon-equipe/actualites" class="form-grid">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('team.newsTitle')) ?></span><input type="text" name="title" required maxlength="150" /></label>
        <label class="span-2"><span><?= e(t('team.newsBody')) ?></span><textarea name="body" rows="3" maxlength="2000"></textarea></label>
        <label><span><?= e(t('common.recipients')) ?></span>
          <select name="target" required>
            <?php foreach ($managedScopes as $scope): ?>
              <option value="<?= e($scope['scope'] . ':' . $scope['id']) ?>"><?= e($scope['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit" class="btn btn-primary"><?= e(t('team.publish')) ?></button>
      </form>
    <?php endif; ?>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('team.publishedNews')) ?></h2>
    <?php if ($teamNews === []): ?>
      <div class="empty-state"><?= e(t('home.noNews')) ?></div>
    <?php endif; ?>
    <?php foreach ($teamNews as $item): ?>
      <article class="sub-card">
        <h3><?= e($item['title']) ?></h3>
        <p class="cell-sub"><?= e(\App\Core\Dates::moment((string) $item['created_at'])) ?> · <?= e((string) $item['scope_name']) ?></p>
        <p><?= nl2br(e((string) $item['body'])) ?></p>
        <form method="POST" action="/mon-equipe/actualites/<?= (int) $item['id'] ?>/supprimer">
          <?= $csrf ?>
          <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
        </form>
      </article>
    <?php endforeach; ?>
  </div>
</section>
