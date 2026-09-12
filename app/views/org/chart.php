<div class="kpi-row">
  <div class="kpi"><strong><?= (int) $chart['headcount'] ?></strong><span><?= e(t('org.employees')) ?></span></div>
  <div class="kpi"><strong><?= (int) $chart['managerCount'] ?></strong><span><?= e(t('org.managers')) ?></span></div>
  <div class="kpi"><strong><?= count($chart['departments']) ?></strong><span><?= e(t('org.departments')) ?></span></div>
</div>

<?php if ($seesAll): ?>
  <p class="muted"><?= e(t('org.fullStaffNote')) ?></p>
<?php endif; ?>

<?php if ($chart['departments'] === []): ?>
  <div class="empty-state"><?= e(t('org.chartNoDepartment')) ?></div>
<?php endif; ?>

<?php foreach ($chart['departments'] as $department): ?>
  <section class="card mt-l">
    <h2><?= e($department['name']) ?></h2>
    <p class="muted">
      <?= e(t('org.peopleAndTeams', [
          'people' => (int) $department['member_count'],
          'teams' => count($department['teams']),
      ])) ?>
    </p>

    <p class="muted">
      <strong><?= e(t('org.management')) ?> :</strong>
      <?php if ($department['managers'] === []): ?>
        <?= e(t('org.chartNoManager')) ?>
      <?php else: ?>
        <?= e(implode(', ', array_map(
            static fn (array $m): string => trim($m['first_name'] . ' ' . $m['last_name']),
            $department['managers']
        ))) ?>
      <?php endif; ?>
    </p>

    <?php if ($department['teams'] === []): ?>
      <p class="muted"><?= e(t('org.noTeamInDept')) ?></p>
    <?php endif; ?>

    <?php foreach ($department['teams'] as $team): ?>
      <div class="sub-card">
        <h3><?= e($team['name']) ?></h3>
        <p class="muted">
          <?= $team['managers'] === []
              ? e(t('org.chartNoManager'))
              : e(implode(', ', array_map(
                  static fn (array $m): string => trim($m['first_name'] . ' ' . $m['last_name']),
                  $team['managers']
              ))) ?>
        </p>
        <ul class="people">
          <?php foreach ($team['members'] as $person): ?>
            <li>
              <?= e(trim($person['first_name'] . ' ' . $person['last_name'])) ?>
              <?php if (!empty($person['grade'])): ?><span class="muted"> · <?= e($person['grade']) ?></span><?php endif; ?>
              <?php if (!empty($person['directory_hidden'])): ?>
                <span class="tag"><?= e(t('org.outOfDirectory')) ?></span>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>

    <?php if ($department['loose'] !== []): ?>
      <div class="sub-card">
        <h3><?= e(t('org.attachedNoTeam')) ?></h3>
        <ul class="people">
          <?php foreach ($department['loose'] as $person): ?>
            <li><?= e(trim($person['first_name'] . ' ' . $person['last_name'])) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </section>
<?php endforeach; ?>

<?php if ($chart['orphanTeams'] !== []): ?>
  <section class="card mt-l">
    <h2><?= e(t('org.teamsNoDept')) ?></h2>
    <p class="muted"><?= e(t('org.forgottenLink')) ?></p>
    <?php foreach ($chart['orphanTeams'] as $team): ?>
      <div class="sub-card">
        <h3><?= e($team['name']) ?></h3>
        <ul class="people">
          <?php foreach ($team['members'] as $person): ?>
            <li><?= e(trim($person['first_name'] . ' ' . $person['last_name'])) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
  </section>
<?php endif; ?>

<?php if ($chart['unassigned'] !== []): ?>
  <section class="card mt-l">
    <h2><?= e(t('org.unattached')) ?></h2>
    <p class="muted"><?= e(t('org.unattachedNote')) ?></p>
    <ul class="people">
      <?php foreach ($chart['unassigned'] as $person): ?>
        <li><?= e(trim($person['first_name'] . ' ' . $person['last_name'])) ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
<?php endif; ?>
