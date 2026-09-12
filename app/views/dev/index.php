<?php
$csrf = \App\Core\Csrf::field();
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso;
$spell = static fn (?int $minutes): string => $minutes === null
    ? '—'
    : ($minutes < 90 ? t('inf.minutes', ['count' => $minutes]) : t('inf.hours', ['count' => round($minutes / 6) / 10]));
$who = static fn (array $row): string => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
?>
<section class="stats-grid">
  <?php foreach ([
      [(string) $summary['live'], t('dvp.servicesLive')],
      [(string) $summary['vital'], t('dvp.vitalServices')],
      [(string) $summary['orphan'], t('dvp.noLead')],
      [(string) $summary['delivery']['delivered'], t('dvp.deliveredWindow', ['days' => $summary['delivery']['days']])],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<section class="tab-panel is-active" id="services">
  <div class="card">
    <div class="card-head">
      <h2><?= e(t('dvp.serviceCount', ['count' => count($serviceList)])) ?></h2>
      <a class="btn btn-outline btn-sm" href="/developpement<?= $showRetired ? '' : '?retires=1' ?>">
        <?= e($showRetired ? t('dvp.hideRetired') : t('dvp.showRetired')) ?>
      </a>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('dvp.service')) ?></th>
            <th><?= e(t('dvp.criticality')) ?></th>
            <th><?= e(t('dvp.lead')) ?></th>
            <th><?= e(t('dvp.liveVersion')) ?></th>
            <th><?= e(t('dvp.incidents')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($serviceList as $service): ?>
            <tr>
              <td class="cell-strong">
                <a href="/developpement/services/<?= (int) $service['id'] ?>"><?= e($service['name']) ?></a>
                <?php if ($service['code'] !== ''): ?> <span class="tag"><?= e($service['code']) ?></span><?php endif; ?>
                <?php if ($service['stack'] !== ''): ?><br /><span class="cell-sub"><?= e($service['stack']) ?></span><?php endif; ?>
              </td>
              <td><span class="tag <?= $service['criticality'] === 'Vitale' ? 'tag-danger' : '' ?>"><?= e(st($service['criticality'])) ?></span></td>
              <td class="cell-sub">
                <?= e($service['lead_first_name'] !== null ? trim($service['lead_first_name'] . ' ' . $service['lead_last_name']) : '—') ?>
              </td>
              <td class="cell-sub nowrap">
                <?= e($service['live_version'] ?? '—') ?>
                <?php if (!empty($service['live_since'])): ?><br /><span class="cell-sub"><?= e($day($service['live_since'])) ?></span><?php endif; ?>
              </td>
              <td class="<?= (int) $service['open_incidents'] > 0 ? 'text-danger' : 'cell-sub' ?>"><?= (int) $service['open_incidents'] ?></td>
              <td><span class="tag <?= $service['status'] === 'En service' ? 'tag-success' : '' ?>"><?= e(st($service['status'])) ?></span></td>
              <td>
                <a class="btn btn-outline btn-sm" href="/developpement/services/<?= (int) $service['id'] ?>"><?= e(t('common.open')) ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($serviceList === []): ?>
            <tr><td colspan="7" class="cell-sub"><?= e(t('dvp.noService')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="tab-panel" id="livraisons">
  <div class="card">
    <h2><?= e(t('dvp.lastReleases', ['count' => count($releaseList)])) ?></h2>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('dvp.service')) ?></th>
            <th><?= e(t('dvp.version')) ?></th>
            <th><?= e(t('dvp.environment')) ?></th>
            <th><?= e(t('common.date')) ?></th>
            <th><?= e(t('dvp.author')) ?></th>
            <th><?= e(t('common.status')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($releaseList as $release): ?>
            <tr>
              <td><a href="/developpement/services/<?= (int) $release['service_id'] ?>"><?= e($release['service_name']) ?></a></td>
              <td class="cell-strong nowrap"><?= e($release['version']) ?></td>
              <td class="cell-sub"><?= e(st($release['environment'])) ?></td>
              <td class="cell-sub nowrap"><?= e($day($release['released_on'] ?: $release['planned_on'])) ?></td>
              <td class="cell-sub"><?= e($who($release) ?: '—') ?></td>
              <td>
                <span class="tag <?= $release['status'] === 'Livrée' ? 'tag-success' : ($release['status'] === 'Planifiée' ? '' : 'tag-danger') ?>">
                  <?= e(st($release['status'])) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($releaseList === []): ?>
            <tr><td colspan="6" class="cell-sub"><?= e(t('dvp.noRelease')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="tab-panel" id="indicateurs">
  <section class="stats-grid">
    <?php foreach ([
        [(string) $summary['delivery']['perWeek'], t('dvp.perWeek')],
        [$summary['delivery']['failureRate'] . ' %', t('dvp.failureRate')],
        [$spell($restore['meanMinutes']), t('inf.meanRestore')],
        [(string) $summary['delivery']['planned'], t('dvp.plannedReleases')],
    ] as [$value, $label]): ?>
      <div class="stat-card">
        <span class="stat-body">
          <span class="stat-value"><?= e($value) ?></span>
          <span class="stat-label"><?= e($label) ?></span>
        </span>
      </div>
    <?php endforeach; ?>
  </section>

  <div class="card mt-l">
    <h2><?= e(t('dvp.metricsTitle', ['days' => $summary['delivery']['days']])) ?></h2>
    <p class="hint"><?= e(t('dvp.metricsHint')) ?></p>
    <ul class="org-list">
      <li class="org-item"><span><?= e(t('dvp.attempts')) ?></span><span class="cell-strong"><?= (int) $summary['delivery']['attempts'] ?></span></li>
      <li class="org-item"><span><?= e(t('dvp.delivered')) ?></span><span class="cell-strong"><?= (int) $summary['delivery']['delivered'] ?></span></li>
      <li class="org-item"><span><?= e(t('dvp.failed')) ?></span><span class="cell-strong"><?= (int) $summary['delivery']['failed'] ?></span></li>
      <li class="org-item">
        <span><?= e(t('inf.incidentsWindow', ['days' => $restore['days']])) ?></span>
        <span class="cell-strong"><?= (int) $restore['total'] ?></span>
      </li>
      <li class="org-item"><span><?= e(t('dvp.restoredIncidents')) ?></span><span class="cell-strong"><?= (int) $restore['resolved'] ?></span></li>
    </ul>
  </div>
</section>

<section class="tab-panel" id="nouveau">
  <div class="card">
    <h2><?= e(t('dvp.addService')) ?></h2>
    <form method="POST" action="/developpement/services" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('dvp.service')) ?></span><input type="text" name="name" required maxlength="120" /></label>
      <label><span><?= e(t('dvp.code')) ?></span><input type="text" name="code" maxlength="30" /></label>
      <label><span><?= e(t('dvp.stack')) ?></span>
        <input type="text" name="stack" maxlength="120" placeholder="Node 22, PostgreSQL" />
      </label>
      <label><span><?= e(t('dvp.criticality')) ?></span>
        <select name="criticality">
          <?php foreach ($criticalities as $criticality): ?>
            <option value="<?= e($criticality) ?>"<?= $criticality === 'Importante' ? ' selected' : '' ?>><?= e(st($criticality)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('dvp.repository')) ?></span><input type="url" name="repository" maxlength="300" placeholder="https://" /></label>
      <label><span><?= e(t('dvp.documentation')) ?></span><input type="url" name="documentation" maxlength="300" placeholder="https://" /></label>
      <label><span><?= e(t('dvp.lead')) ?></span>
        <select name="lead_id">
          <option value="">—</option>
          <?php foreach ($employees as $person): ?>
            <option value="<?= (int) $person['id'] ?>"><?= e($who($person)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('nav.projects')) ?></span>
        <select name="project_id">
          <option value="">—</option>
          <?php foreach ($projectList as $project): ?>
            <option value="<?= (int) $project['id'] ?>"><?= e($project['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('common.description')) ?></span><textarea name="description" rows="3" maxlength="1000"></textarea></label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
    </form>
    <p class="hint"><?= e(t('dvp.leadHint')) ?></p>
  </div>
</section>
