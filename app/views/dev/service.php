<?php
$csrf = \App\Core\Csrf::field();
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso;
$moment = static fn (?string $value): string => ($value === null || $value === '') ? '—' : $value;
$spell = static fn (?int $minutes): string => $minutes === null
    ? '—'
    : ($minutes < 90 ? t('inf.minutes', ['count' => $minutes]) : t('inf.hours', ['count' => round($minutes / 6) / 10]));
$who = static fn (array $row): string => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
?>
<section class="stats-grid">
  <?php foreach ([
      [$service['live_version'] ?? '—', t('dvp.liveVersion')],
      [$day($service['live_since']), t('dvp.liveSince')],
      [(string) (int) $service['open_incidents'], t('inf.openIncidents')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e((string) $value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<section class="tab-panel is-active" id="fiche">
  <div class="card">
    <div class="card-head">
      <h2><?= e(t('dvp.sheet')) ?></h2>
      <form method="POST" action="/developpement/services/<?= (int) $service['id'] ?>/supprimer"
            data-confirm="<?= e(t('dvp.confirmDeleteService')) ?>">
        <?= $csrf ?>
        <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
      </form>
    </div>
    <?php if ($service['repository'] !== '' || $service['documentation'] !== ''): ?>
      <p class="muted">
        <?php if ($service['repository'] !== ''): ?>
          <a href="<?= e($service['repository']) ?>" rel="noreferrer noopener"><?= e(t('dvp.repository')) ?></a>
        <?php endif; ?>
        <?php if ($service['repository'] !== '' && $service['documentation'] !== ''): ?> · <?php endif; ?>
        <?php if ($service['documentation'] !== ''): ?>
          <a href="<?= e($service['documentation']) ?>" rel="noreferrer noopener"><?= e(t('dvp.documentation')) ?></a>
        <?php endif; ?>
      </p>
    <?php endif; ?>
    <form method="POST" action="/developpement/services/<?= (int) $service['id'] ?>/modifier" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('dvp.service')) ?></span>
        <input type="text" name="name" value="<?= e($service['name']) ?>" required maxlength="120" />
      </label>
      <label><span><?= e(t('dvp.code')) ?></span><input type="text" name="code" value="<?= e($service['code']) ?>" maxlength="30" /></label>
      <label><span><?= e(t('dvp.stack')) ?></span><input type="text" name="stack" value="<?= e($service['stack']) ?>" maxlength="120" /></label>
      <label><span><?= e(t('dvp.criticality')) ?></span>
        <select name="criticality">
          <?php foreach ($criticalities as $criticality): ?>
            <option value="<?= e($criticality) ?>"<?= $service['criticality'] === $criticality ? ' selected' : '' ?>><?= e(st($criticality)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('dvp.repository')) ?></span>
        <input type="url" name="repository" value="<?= e($service['repository']) ?>" maxlength="300" />
      </label>
      <label><span><?= e(t('dvp.documentation')) ?></span>
        <input type="url" name="documentation" value="<?= e($service['documentation']) ?>" maxlength="300" />
      </label>
      <label><span><?= e(t('dvp.lead')) ?></span>
        <select name="lead_id">
          <option value="">—</option>
          <?php foreach ($employees as $person): ?>
            <option value="<?= (int) $person['id'] ?>"<?= (int) $service['lead_id'] === (int) $person['id'] ? ' selected' : '' ?>>
              <?= e($who($person)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('nav.projects')) ?></span>
        <select name="project_id">
          <option value="">—</option>
          <?php foreach ($projectList as $project): ?>
            <option value="<?= (int) $project['id'] ?>"<?= (int) $service['project_id'] === (int) $project['id'] ? ' selected' : '' ?>>
              <?= e($project['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.status')) ?></span>
        <select name="status">
          <?php foreach ($statuses as $status): ?>
            <option value="<?= e($status) ?>"<?= $service['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('common.description')) ?></span>
        <textarea name="description" rows="3" maxlength="1000"><?= e($service['description']) ?></textarea>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
    </form>
  </div>
</section>

<section class="tab-panel" id="livraisons">
  <div class="card">
    <h2><?= e(t('dvp.addRelease')) ?></h2>
    <form method="POST" action="/developpement/services/<?= (int) $service['id'] ?>/livraisons" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('dvp.version')) ?></span><input type="text" name="version" required maxlength="40" placeholder="1.4.0" /></label>
      <label><span><?= e(t('dvp.environment')) ?></span>
        <select name="environment">
          <?php foreach ($environments as $environment): ?>
            <option value="<?= e($environment) ?>"<?= $environment === 'Production' ? ' selected' : '' ?>><?= e(st($environment)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('dvp.plannedOn')) ?></span><input type="date" name="planned_on" /></label>
      <label><span><?= e(t('dvp.releasedOn')) ?></span><input type="date" name="released_on" /></label>
      <label class="span-2"><span><?= e(t('common.status')) ?></span>
        <select name="status">
          <?php foreach ($releaseStatuses as $status): ?>
            <option value="<?= e($status) ?>"<?= $status === 'Livrée' ? ' selected' : '' ?>><?= e(st($status)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('dvp.changelog')) ?></span><textarea name="changelog" rows="3" maxlength="2000"></textarea></label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
    </form>
  </div>

  <?php foreach ($releaseList as $release): ?>
    <div class="card mt-l">
      <div class="card-head">
        <div>
          <h2>
            <?= e($release['version']) ?>
            <span class="tag"><?= e(st($release['environment'])) ?></span>
            <span class="tag <?= $release['status'] === 'Livrée' ? 'tag-success' : ($release['status'] === 'Planifiée' ? '' : 'tag-danger') ?>">
              <?= e(st($release['status'])) ?>
            </span>
          </h2>
          <p class="muted">
            <?= e($day($release['released_on'] ?: $release['planned_on'])) ?>
            <?php if ($release['first_name'] !== null): ?> · <?= e($who($release)) ?><?php endif; ?>
            <?php if ($release['incident_title'] !== null): ?>
              · <?= e(t('dvp.linkedIncident', ['title' => $release['incident_title']])) ?>
            <?php endif; ?>
          </p>
        </div>
        <form method="POST" action="/developpement/livraisons/<?= (int) $release['id'] ?>/supprimer"
              data-confirm="<?= e(t('dvp.confirmDeleteRelease')) ?>">
          <?= $csrf ?>
          <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
        </form>
      </div>
      <form method="POST" action="/developpement/livraisons/<?= (int) $release['id'] ?>/modifier" class="form-grid">
        <?= $csrf ?>
        <label><span><?= e(t('dvp.version')) ?></span>
          <input type="text" name="version" value="<?= e($release['version']) ?>" maxlength="40" />
        </label>
        <label><span><?= e(t('dvp.environment')) ?></span>
          <select name="environment">
            <?php foreach ($environments as $environment): ?>
              <option value="<?= e($environment) ?>"<?= $release['environment'] === $environment ? ' selected' : '' ?>>
                <?= e(st($environment)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('dvp.plannedOn')) ?></span>
          <input type="date" name="planned_on" value="<?= e((string) ($release['planned_on'] ?? '')) ?>" />
        </label>
        <label><span><?= e(t('dvp.releasedOn')) ?></span>
          <input type="date" name="released_on" value="<?= e((string) ($release['released_on'] ?? '')) ?>" />
        </label>
        <label><span><?= e(t('common.status')) ?></span>
          <select name="status">
            <?php foreach ($releaseStatuses as $status): ?>
              <option value="<?= e($status) ?>"<?= $release['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('dvp.linkIncident')) ?></span>
          <select name="incident_id">
            <option value="">—</option>
            <?php foreach ($incidentList as $incident): ?>
              <option value="<?= (int) $incident['id'] ?>"<?= (int) $release['incident_id'] === (int) $incident['id'] ? ' selected' : '' ?>>
                <?= e($incident['title']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="span-2"><span><?= e(t('dvp.changelog')) ?></span>
          <textarea name="changelog" rows="3" maxlength="2000"><?= e($release['changelog']) ?></textarea>
        </label>
        <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if ($releaseList === []): ?>
    <div class="card mt-l"><div class="empty-state"><?= e(t('dvp.noRelease')) ?></div></div>
  <?php endif; ?>
</section>

<section class="tab-panel" id="incidents">
  <div class="card">
    <div class="card-head">
      <h2><?= e(t('dvp.serviceIncidents', ['count' => count($incidentList)])) ?></h2>
      <a class="btn btn-outline btn-sm" href="/informatique#incidents"><?= e(t('dvp.manageIncidents')) ?></a>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.title')) ?></th>
            <th><?= e(t('inf.severity')) ?></th>
            <th><?= e(t('inf.startedAt')) ?></th>
            <th><?= e(t('inf.downtime')) ?></th>
            <th><?= e(t('common.status')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($incidentList as $incident): ?>
            <tr>
              <td class="cell-strong"><?= e($incident['title']) ?></td>
              <td><span class="tag <?= $incident['severity'] === 'Critique' ? 'tag-danger' : '' ?>"><?= e(st($incident['severity'])) ?></span></td>
              <td class="cell-sub nowrap"><?= e($moment($incident['started_at'])) ?></td>
              <td class="cell-sub nowrap"><?= e($spell(\App\Modules\It::downtimeMinutes($incident))) ?></td>
              <td><span class="tag"><?= e(st($incident['status'])) ?></span></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($incidentList === []): ?>
            <tr><td colspan="5" class="cell-sub"><?= e(t('dvp.noServiceIncident')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
