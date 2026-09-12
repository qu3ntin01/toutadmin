<?php
$csrf = \App\Core\Csrf::field();
$money = static fn (?float $value): string => $value === null ? '—' : number_format($value, 2, ',', ' ') . ' €';
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso;
$moment = static fn (?string $value): string => ($value === null || $value === '') ? '—' : $value;
$formMoment = static fn (?string $value): string => ($value === null || $value === '') ? '' : str_replace(' ', 'T', $value);
$spell = static fn (?int $minutes): string => $minutes === null
    ? '—'
    : ($minutes < 90 ? t('inf.minutes', ['count' => $minutes]) : t('inf.hours', ['count' => round($minutes / 6) / 10]));
$who = static fn (array $row): string => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
?>
<section class="stats-grid">
  <?php foreach ([
      [(string) $summary['licences'], t('inf.softwareInPark')],
      [(string) $summary['flagged'], t('inf.accessesToReview')],
      [(string) $summary['incidents']['open'], t('inf.openIncidents')],
      [$money((float) $summary['yearlyCost']), t('inf.yearlyCost')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<section class="tab-panel is-active" id="logiciels">
  <div class="card">
    <div class="card-head">
      <h2><?= e(t('inf.softwareCount', ['count' => count($licenceList)])) ?></h2>
      <a class="btn btn-outline btn-sm" href="/informatique<?= $showRetired ? '' : '?retires=1' ?>">
        <?= e($showRetired ? t('inf.hideRetired') : t('inf.showRetired')) ?>
      </a>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('inf.software')) ?></th>
            <th><?= e(t('common.type')) ?></th>
            <th><?= e(t('inf.seats')) ?></th>
            <th><?= e(t('inf.criticality')) ?></th>
            <th><?= e(t('inf.renewal')) ?></th>
            <th><?= e(t('inf.owner')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($licenceList as $licence): ?>
            <?php $full = (int) $licence['seats'] > 0 && (int) $licence['seats_used'] >= (int) $licence['seats']; ?>
            <tr>
              <td class="cell-strong">
                <a href="/informatique/logiciels/<?= (int) $licence['id'] ?>"><?= e($licence['name']) ?></a>
                <?php if ($licence['publisher'] !== ''): ?><br /><span class="cell-sub"><?= e($licence['publisher']) ?></span><?php endif; ?>
                <?php if ((int) $licence['personal_data'] === 1): ?>
                  <span class="tag tag-warning"><?= e(t('inf.personalData')) ?></span>
                <?php endif; ?>
              </td>
              <td class="cell-sub"><?= e(st($licence['kind'])) ?></td>
              <td class="<?= $full ? 'text-danger' : 'cell-sub' ?> nowrap">
                <?= e((int) $licence['seats'] > 0
                    ? t('inf.seatsOf', ['used' => (int) $licence['seats_used'], 'total' => (int) $licence['seats']])
                    : (string) (int) $licence['seats_used']) ?>
              </td>
              <td><span class="tag <?= $licence['criticality'] === 'Vitale' ? 'tag-danger' : '' ?>"><?= e(st($licence['criticality'])) ?></span></td>
              <td class="<?= !empty($licence['renewal_date']) && $licence['renewal_date'] < $today ? 'text-danger' : 'cell-sub' ?> nowrap">
                <?= e($day($licence['renewal_date'])) ?>
              </td>
              <td class="cell-sub">
                <?= e($licence['owner_first_name'] !== null ? trim($licence['owner_first_name'] . ' ' . $licence['owner_last_name']) : '—') ?>
              </td>
              <td><span class="tag <?= $licence['status'] === 'Actif' ? 'tag-success' : '' ?>"><?= e(st($licence['status'])) ?></span></td>
              <td>
                <a class="btn btn-outline btn-sm" href="/informatique/logiciels/<?= (int) $licence['id'] ?>"><?= e(t('common.open')) ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($licenceList === []): ?>
            <tr><td colspan="8" class="cell-sub"><?= e(t('inf.noSoftware')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="tab-panel" id="acces">
  <div class="card">
    <h2><?= e(t('inf.reviewTitle', ['flagged' => count($review['flagged']), 'total' => $review['total']])) ?></h2>
    <p class="muted"><?= e(t('inf.reviewHint', ['months' => 12])) ?></p>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.member')) ?></th>
            <th><?= e(t('inf.software')) ?></th>
            <th><?= e(t('inf.level')) ?></th>
            <th><?= e(t('inf.reason')) ?></th>
            <th><?= e(t('inf.lastLook')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($review['flagged'] as $row): ?>
            <tr>
              <td class="cell-strong">
                <?= e($who($row)) ?>
                <?php if ((int) $row['active'] === 0): ?><br /><span class="cell-sub"><?= e(t('inf.accountClosed')) ?></span><?php endif; ?>
              </td>
              <td><a href="/informatique/logiciels/<?= (int) $row['licence_id'] ?>"><?= e($row['licence_name']) ?></a></td>
              <td class="cell-sub"><?= e(st($row['level'])) ?></td>
              <td>
                <span class="tag <?= $row['severity'] === 'Critique' ? 'tag-danger' : ($row['severity'] === 'Majeur' ? 'tag-warning' : '') ?>">
                  <?= e($row['reason'] === 'inactif'
                      ? t('inf.reasonInactive')
                      : ($row['reason'] === 'admin' ? t('inf.reasonAdmin') : t('inf.reasonStale', ['months' => 12]))) ?>
                </span>
              </td>
              <td class="cell-sub nowrap"><?= e($day($row['reviewed_on'] ?: $row['granted_on'])) ?></td>
              <td class="actions">
                <form method="POST" action="/informatique/acces/<?= (int) $row['id'] ?>/revu">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-outline btn-sm"><?= e(t('inf.markReviewed')) ?></button>
                </form>
                <form method="POST" action="/informatique/acces/<?= (int) $row['id'] ?>/revoquer"
                      data-confirm="<?= e(t('inf.confirmRevoke')) ?>">
                  <?= $csrf ?>
                  <input type="hidden" name="retour" value="revue" />
                  <button type="submit" class="btn btn-sm btn-danger"><?= e(t('inf.revoke')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($review['flagged'] === []): ?>
            <tr><td colspan="6" class="cell-sub"><?= e(t('inf.reviewNothing')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="tab-panel" id="incidents">
  <section class="stats-grid">
    <?php foreach ([
        [$spell($summary['incidents']['meanMinutes']), t('inf.meanRestore')],
        [(string) $summary['incidents']['total'], t('inf.incidentsWindow', ['days' => $summary['incidents']['days']])],
        [(string) $summary['incidents']['critical'], t('inf.criticalIncidents')],
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
    <h2><?= e(t('inf.declareIncident')) ?></h2>
    <form method="POST" action="/informatique/incidents" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('common.title')) ?></span><input type="text" name="title" required maxlength="150" /></label>
      <label><span><?= e(t('inf.service')) ?></span>
        <select name="service_id">
          <option value="">—</option>
          <?php foreach ($serviceList as $service): ?>
            <option value="<?= (int) $service['id'] ?>"><?= e($service['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('inf.severity')) ?></span>
        <select name="severity">
          <?php foreach ($severities as $severity): ?><option value="<?= e($severity) ?>"><?= e(st($severity)) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('inf.startedAt')) ?></span><input type="datetime-local" name="started_at" required /></label>
      <label><span><?= e(t('inf.detectedAt')) ?></span><input type="datetime-local" name="detected_at" /></label>
      <label class="span-2"><span><?= e(t('inf.impact')) ?></span><textarea name="impact" rows="2" maxlength="1000"></textarea></label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
    </form>
  </div>

  <?php foreach ($incidentList as $incident): ?>
    <?php $downtime = \App\Modules\It::downtimeMinutes($incident); ?>
    <div class="card mt-l">
      <div class="card-head">
        <div>
          <h2><?= e($incident['title']) ?></h2>
          <p class="muted">
            <span class="tag <?= $incident['severity'] === 'Critique' ? 'tag-danger' : ($incident['severity'] === 'Majeur' ? 'tag-warning' : '') ?>">
              <?= e(st($incident['severity'])) ?>
            </span>
            <span class="tag"><?= e(st($incident['status'])) ?></span>
            <?= e($incident['service_name'] ?? t('inf.noService')) ?> · <?= e($moment($incident['started_at'])) ?>
            <?php if ($downtime !== null): ?> · <?= e(t('inf.downtime')) ?> <?= e($spell($downtime)) ?><?php endif; ?>
          </p>
        </div>
        <form method="POST" action="/informatique/incidents/<?= (int) $incident['id'] ?>/supprimer"
              data-confirm="<?= e(t('inf.confirmDeleteIncident')) ?>">
          <?= $csrf ?>
          <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
        </form>
      </div>
      <form method="POST" action="/informatique/incidents/<?= (int) $incident['id'] ?>/modifier" class="form-grid">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('common.title')) ?></span>
          <input type="text" name="title" value="<?= e($incident['title']) ?>" maxlength="150" />
        </label>
        <label><span><?= e(t('inf.service')) ?></span>
          <select name="service_id">
            <option value="">—</option>
            <?php foreach ($serviceList as $service): ?>
              <option value="<?= (int) $service['id'] ?>"<?= (int) $incident['service_id'] === (int) $service['id'] ? ' selected' : '' ?>>
                <?= e($service['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('inf.severity')) ?></span>
          <select name="severity">
            <?php foreach ($severities as $severity): ?>
              <option value="<?= e($severity) ?>"<?= $incident['severity'] === $severity ? ' selected' : '' ?>><?= e(st($severity)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('inf.startedAt')) ?></span>
          <input type="datetime-local" name="started_at" value="<?= e($formMoment($incident['started_at'])) ?>" required />
        </label>
        <label><span><?= e(t('inf.detectedAt')) ?></span>
          <input type="datetime-local" name="detected_at" value="<?= e($formMoment($incident['detected_at'])) ?>" />
        </label>
        <label><span><?= e(t('inf.resolvedAt')) ?></span>
          <input type="datetime-local" name="resolved_at" value="<?= e($formMoment($incident['resolved_at'])) ?>" />
        </label>
        <label><span><?= e(t('common.status')) ?></span>
          <select name="status">
            <?php foreach ($incidentStatuses as $status): ?>
              <option value="<?= e($status) ?>"<?= $incident['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="span-2"><span><?= e(t('inf.impact')) ?></span>
          <textarea name="impact" rows="2" maxlength="1000"><?= e($incident['impact']) ?></textarea>
        </label>
        <label class="span-2"><span><?= e(t('inf.cause')) ?></span>
          <textarea name="cause" rows="2" maxlength="1000"><?= e($incident['cause']) ?></textarea>
        </label>
        <label class="span-2"><span><?= e(t('inf.remediation')) ?></span>
          <textarea name="remediation" rows="2" maxlength="1000"><?= e($incident['remediation']) ?></textarea>
        </label>
        <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
      </form>
    </div>
  <?php endforeach; ?>
  <?php if ($incidentList === []): ?>
    <div class="card mt-l"><div class="empty-state"><?= e(t('inf.noIncident')) ?></div></div>
  <?php endif; ?>
</section>

<section class="tab-panel" id="nouveau">
  <div class="card">
    <h2><?= e(t('inf.addSoftware')) ?></h2>
    <form method="POST" action="/informatique/logiciels" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('inf.software')) ?></span><input type="text" name="name" required maxlength="120" /></label>
      <label><span><?= e(t('inf.publisher')) ?></span><input type="text" name="publisher" maxlength="120" /></label>
      <label><span><?= e(t('common.type')) ?></span>
        <select name="kind">
          <?php foreach ($kinds as $kind): ?><option value="<?= e($kind) ?>"><?= e(st($kind)) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('inf.criticality')) ?></span>
        <select name="criticality">
          <?php foreach ($criticalities as $criticality): ?>
            <option value="<?= e($criticality) ?>"<?= $criticality === 'Importante' ? ' selected' : '' ?>><?= e(st($criticality)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('inf.seats')) ?></span><input type="number" name="seats" min="0" max="100000" value="0" /></label>
      <label><span><?= e(t('inf.unitCost')) ?></span><input type="text" name="unit_cost" inputmode="decimal" /></label>
      <label><span><?= e(t('inf.billingPeriod')) ?></span>
        <select name="billing_period">
          <?php foreach ($billingPeriods as $period): ?>
            <option value="<?= e($period) ?>"<?= $period === 'Annuel' ? ' selected' : '' ?>><?= e(st($period)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('inf.renewal')) ?></span><input type="date" name="renewal_date" /></label>
      <label class="span-2"><span><?= e(t('inf.owner')) ?></span>
        <select name="owner_id">
          <option value="">—</option>
          <?php foreach ($employees as $person): ?>
            <option value="<?= (int) $person['id'] ?>"><?= e($who($person)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="span-2 check-row">
        <input type="checkbox" name="personal_data" value="1" />
        <span><?= e(t('inf.personalDataLabel')) ?></span>
      </label>
      <label class="span-2"><span><?= e(t('common.notes')) ?></span><textarea name="notes" rows="2" maxlength="1000"></textarea></label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
    </form>
    <p class="hint"><?= e(t('inf.seatsHint')) ?></p>
  </div>
</section>
