<?php
$csrf = \App\Core\Csrf::field();
$day = static fn (string $iso): string => gmdate('d/m', (int) strtotime($iso . ' UTC'));
$hour = static fn (string $moment): string => substr($moment, 11, 5);
$who = static fn (array $row): string => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
$teamSuffix = $teamId !== null ? '&equipe=' . (int) $teamId : '';
?>
<!-- ---------- Semaine ---------- -->
<section class="tab-panel is-active" id="semaine">
  <div class="card">
    <div class="card-head">
      <h2><?= e(t('pln.weekOf', ['start' => $day($start), 'end' => $day($end)])) ?></h2>
      <span class="muted"><?= e(t('pln.plannedPeople', ['count' => count($week['rows'])])) ?></span>
    </div>
    <div class="pager">
      <a href="/planning?semaine=<?= e($previousWeek) . $teamSuffix ?>" class="btn btn-outline btn-sm"><?= e(t('pln.previousWeek')) ?></a>
      <a href="/planning?semaine=<?= e($today) . $teamSuffix ?>" class="btn btn-outline btn-sm"><?= e(t('pln.thisWeek')) ?></a>
      <a href="/planning?semaine=<?= e($nextWeek) . $teamSuffix ?>" class="btn btn-outline btn-sm"><?= e(t('pln.nextWeek')) ?></a>
    </div>

    <?php if ($planner && $teams !== []): ?>
      <form method="GET" action="/planning" class="form-grid">
        <input type="hidden" name="semaine" value="<?= e($start) ?>" />
        <label><span><?= e(t('common.team')) ?></span>
          <select name="equipe">
            <option value=""><?= e(t('hr.filterAll')) ?></option>
            <?php foreach ($teams as $team): ?>
              <option value="<?= (int) $team['id'] ?>"<?= (int) $team['id'] === $teamId ? ' selected' : '' ?>><?= e($team['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <div><button type="submit" class="btn btn-outline btn-block"><?= e(t('common.filter')) ?></button></div>
      </form>
    <?php endif; ?>

    <?php if ($week['rows'] === []): ?>
      <div class="empty-state"><?= e(t('pln.noSlotThisWeek')) ?></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="planning-grid">
          <thead>
            <tr>
              <th><?= e(t('common.person')) ?></th>
              <?php foreach ($week['days'] as $column): ?>
                <th class="matrix-head"><?= e($column['short']) ?><br /><span class="cell-sub"><?= e($day($column['date'])) ?></span></th>
              <?php endforeach; ?>
              <th class="matrix-head"><?= e(t('common.hours')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($week['rows'] as $row): ?>
              <tr>
                <td class="cell-strong"><?= e($row['name']) ?></td>
                <?php foreach ($week['days'] as $column): ?>
                  <td class="planning-cell">
                    <?php foreach ($row['days'][$column['date']] ?? [] as $shift): ?>
                      <span class="shift shift-<?= $shift['kind'] === 'Astreinte' ? 'oncall' : ($shift['kind'] === 'Télétravail' ? 'remote' : 'shift') ?><?= (int) $shift['published'] === 1 ? '' : ' is-draft' ?>">
                        <span class="shift-time"><?= e($hour($shift['starts_at'])) ?>–<?= e($hour($shift['ends_at'])) ?></span>
                        <span class="shift-label"><?= e($shift['label'] !== '' ? $shift['label'] : $shift['kind']) ?></span>
                        <?php if ($planner): ?>
                          <form method="POST" action="/planning/creneaux/<?= (int) $shift['id'] ?>/supprimer" class="inline-form"
                                data-confirm="<?= e(t('pln.confirmRemoveSlot')) ?>">
                            <?= $csrf ?>
                            <button type="submit" class="btn btn-link btn-sm">×</button>
                          </form>
                        <?php endif; ?>
                      </span>
                    <?php endforeach; ?>
                  </td>
                <?php endforeach; ?>
                <td class="num"><?= e((string) $row['hours']) ?> h</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="hint"><?= e(t('pln.draftNote')) ?></p>
    <?php endif; ?>
  </div>

  <?php if ($planner): ?>
    <div class="card mt-l">
      <h2><?= e(t('pln.addSlot')) ?></h2>
      <form method="POST" action="/planning/creneaux" class="form-grid">
        <?= $csrf ?>
        <input type="hidden" name="week" value="<?= e($start) ?>" />
        <label><span><?= e(t('common.person')) ?></span>
          <select name="user_id" required>
            <?php foreach ($people as $person): ?>
              <option value="<?= (int) $person['id'] ?>"><?= e($who($person)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('common.nature')) ?></span>
          <select name="kind">
            <?php foreach ($kinds as $kind): ?><option value="<?= e($kind) ?>"><?= e($kind) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('common.day')) ?></span><input type="date" name="day" value="<?= e($start) ?>" required /></label>
        <label><span><?= e(t('messages.from')) ?></span><input type="time" name="start_time" value="09:00" required /></label>
        <label><span><?= e(t('pln.endDay')) ?></span><input type="date" name="end_day" /></label>
        <label><span>À</span><input type="time" name="end_time" value="17:00" required /></label>
        <label><span><?= e(t('common.label')) ?></span>
          <input type="text" name="label" maxlength="160" placeholder="<?= e(t('pln.slotPlaceholder')) ?>" />
        </label>
        <label><span><?= e(t('common.place')) ?></span><input type="text" name="location" maxlength="160" /></label>
        <label><span><?= e(t('common.team')) ?></span>
          <select name="team_id">
            <option value="">—</option>
            <?php foreach ($teams as $team): ?>
              <option value="<?= (int) $team['id'] ?>"><?= e($team['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="check-row">
          <input type="checkbox" name="published" value="1" /><span><?= e(t('pln.publishNow')) ?></span>
        </label>
        <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('pln.addTheSlot')) ?></button></div>
      </form>
      <p class="hint"><?= e(t('pln.overlapNote')) ?></p>
    </div>

    <div class="card mt-l">
      <h2><?= e(t('pln.publishWeek')) ?></h2>
      <form method="POST" action="/planning/publier" class="form-grid">
        <?= $csrf ?>
        <input type="hidden" name="week" value="<?= e($start) ?>" />
        <?php if ($teamId !== null): ?><input type="hidden" name="team_id" value="<?= (int) $teamId ?>" /><?php endif; ?>
        <div class="span-2">
          <button type="submit" class="btn btn-primary btn-block"><?= e(t('pln.publishDraftsOf', ['date' => $day($start)])) ?></button>
        </div>
      </form>
    </div>
  <?php endif; ?>
</section>

<!-- ---------- Astreintes ---------- -->
<section class="tab-panel" id="astreintes">
  <div class="card">
    <h2><?= e(t('pln.onCallNow')) ?></h2>
    <?php if ($onCallNow === []): ?>
      <div class="empty-state"><?= e(t('pln.nobodyOnCall')) ?></div>
    <?php else: ?>
      <ul class="person-list">
        <?php foreach ($onCallNow as $shift): ?>
          <li class="person-row">
            <span class="person-body">
              <span class="person-name"><?= e($who($shift)) ?></span>
              <span class="person-role">
                <?= e(t('pln.untilAt', ['date' => $day(substr($shift['ends_at'], 0, 10)), 'hour' => $hour($shift['ends_at'])])) ?><?= !empty($shift['phone']) ? ' · ' . e($shift['phone']) : '' ?>
              </span>
            </span>
            <span class="tag tag-success"><?= e(t('status.running')) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('pln.weekOnCall')) ?></h2>
    <?php if ($onCallList === []): ?>
      <div class="empty-state"><?= e(t('pln.noOnCall')) ?></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('common.person')) ?></th>
              <th><?= e(t('common.start')) ?></th>
              <th><?= e(t('common.end')) ?></th>
              <th><?= e(t('common.duration')) ?></th>
              <th><?= e(t('common.state')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($onCallList as $shift): ?>
              <tr>
                <td class="cell-strong"><?= e($who($shift)) ?></td>
                <td class="cell-sub nowrap"><?= e($day(substr($shift['starts_at'], 0, 10))) ?> <?= e($hour($shift['starts_at'])) ?></td>
                <td class="cell-sub nowrap"><?= e($day(substr($shift['ends_at'], 0, 10))) ?> <?= e($hour($shift['ends_at'])) ?></td>
                <td class="num"><?= e((string) $shift['hours']) ?> h</td>
                <td>
                  <span class="tag <?= (int) $shift['published'] === 1 ? 'tag-success' : 'tag-warning' ?>">
                    <?= e((int) $shift['published'] === 1 ? 'Publiée' : 'Brouillon') ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- ---------- Roulements ---------- -->
<section class="tab-panel" id="roulements">
  <?php if ($planner): ?>
    <div class="card">
      <h2><?= e(t('pln.createRotation')) ?></h2>
      <form method="POST" action="/planning/roulements" class="form-grid">
        <?= $csrf ?>
        <input type="hidden" name="week" value="<?= e($start) ?>" />
        <label><span><?= e(t('common.name')) ?></span>
          <input type="text" name="name" required maxlength="120" placeholder="<?= e(t('pln.rotationPlaceholder')) ?>" />
        </label>
        <label><span><?= e(t('common.nature')) ?></span>
          <select name="kind">
            <?php foreach ($kinds as $kind): ?><option value="<?= e($kind) ?>"><?= e($kind) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('messages.from')) ?></span><input type="time" name="start_time" value="06:00" required /></label>
        <label><span>À</span><input type="time" name="end_time" value="14:00" required /></label>
        <label class="span-2"><span><?= e(t('common.days')) ?></span>
          <span class="filter-pills">
            <?php foreach ($weekdays as $weekday): ?>
              <label class="check-row">
                <input type="checkbox" name="weekdays[]" value="<?= (int) $weekday['value'] ?>"<?= $weekday['value'] <= 5 ? ' checked' : '' ?> />
                <span><?= e($weekday['short']) ?></span>
              </label>
            <?php endforeach; ?>
          </span>
        </label>
        <label class="span-2"><span><?= e(t('common.place')) ?></span><input type="text" name="location" maxlength="160" /></label>
        <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('pln.saveRotation')) ?></button></div>
      </form>
      <p class="hint"><?= e(t('pln.nightShiftNote')) ?></p>
    </div>
  <?php endif; ?>

  <div class="card mt-l">
    <h2><?= count($templateList) ?> roulement(s)</h2>
    <?php if ($templateList === []): ?>
      <div class="empty-state"><?= e(t('pln.noRotation')) ?></div>
    <?php else: ?>
      <ul class="meeting-list">
        <?php foreach ($templateList as $template): ?>
          <li class="meeting-item">
            <div class="meeting-head">
              <span class="meeting-title"><?= e($template['name']) ?></span>
              <span class="tag"><?= e($template['kind']) ?></span>
            </div>
            <p class="cell-sub">
              <?= e($template['start_time']) ?> – <?= e($template['end_time']) ?><?= $template['end_time'] <= $template['start_time'] ? ' (nuit)' : '' ?>
              · <?= e(implode(', ', array_map(
                  static fn (array $d): string => $d['short'],
                  array_filter($weekdays, static fn (array $d): bool => in_array($d['value'], $template['days'], true))
              ))) ?>
              <?= $template['location'] !== '' ? ' · ' . e($template['location']) : '' ?>
            </p>
            <?php if ($planner): ?>
              <form method="POST" action="/planning/roulements/<?= (int) $template['id'] ?>/appliquer" class="form-grid">
                <?= $csrf ?>
                <input type="hidden" name="week" value="<?= e($start) ?>" />
                <label><span><?= e(t('common.person')) ?></span>
                  <select name="user_id" required>
                    <?php foreach ($people as $person): ?>
                      <option value="<?= (int) $person['id'] ?>"><?= e($who($person)) ?></option>
                    <?php endforeach; ?>
                  </select>
                </label>
                <label><span><?= e(t('leave.from')) ?></span><input type="date" name="from" value="<?= e($start) ?>" required /></label>
                <label><span><?= e(t('leave.to')) ?></span><input type="date" name="to" value="<?= e($end) ?>" required /></label>
                <label class="check-row">
                  <input type="checkbox" name="published" value="1" /><span><?= e(t('common.publish')) ?></span>
                </label>
                <div class="span-2"><button type="submit" class="btn btn-outline btn-block"><?= e(t('common.apply')) ?></button></div>
              </form>
              <div class="row-actions">
                <form method="POST" action="/planning/roulements/<?= (int) $template['id'] ?>/supprimer" class="inline-form"
                      data-confirm="<?= e(t('pln.confirmDeleteRotation')) ?>">
                  <?= $csrf ?>
                  <input type="hidden" name="week" value="<?= e($start) ?>" />
                  <button type="submit" class="btn btn-link btn-sm"><?= e(t('common.delete')) ?></button>
                </form>
              </div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>

<!-- ---------- Charge ---------- -->
<section class="tab-panel" id="charge">
  <div class="card">
    <h2><?= e(t('pln.plannedHours', ['start' => $day($start), 'end' => $day($end)])) ?></h2>
    <?php if (!$planner): ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('common.day')) ?></th>
              <th><?= e(t('pln.slot')) ?></th>
              <th><?= e(t('common.nature')) ?></th>
              <th><?= e(t('common.duration')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($mine as $shift): ?>
              <tr>
                <td class="cell-sub nowrap"><?= e($day($shift['day'])) ?></td>
                <td class="cell-strong">
                  <?= e($hour($shift['starts_at'])) ?>–<?= e($hour($shift['ends_at'])) ?><?= $shift['label'] !== '' ? ' · ' . e($shift['label']) : '' ?>
                </td>
                <td class="cell-sub"><?= e($shift['kind']) ?></td>
                <td class="num"><?= e((string) $shift['hours']) ?> h</td>
              </tr>
            <?php endforeach; ?>
            <?php if ($mine === []): ?>
              <tr><td colspan="4" class="cell-sub"><?= e(t('pln.noSlotThisWeekShort')) ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    <?php elseif ($loadList === []): ?>
      <div class="empty-state"><?= e(t('pln.nothingPlanned')) ?></div>
    <?php else: ?>
      <?php $maxHours = max(array_merge([1.0], array_column($loadList, 'hours'))); ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('common.person')) ?></th>
              <th><?= e(t('pln.slots')) ?></th>
              <th><?= e(t('pln.tabOnCall')) ?></th>
              <th><?= e(t('common.hours')) ?></th>
              <th><?= e(t('pln.distribution')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($loadList as $line): ?>
              <tr>
                <td class="cell-strong"><?= e($line['name']) ?></td>
                <td><?= (int) $line['shifts'] ?></td>
                <td><?= (int) $line['onCall'] ?></td>
                <td class="num"><?= e((string) $line['hours']) ?> h</td>
                <td>
                  <span class="meter">
                    <span class="meter-fill <?= $line['hours'] > 48 ? 'is-over' : ($line['hours'] > 40 ? 'is-warn' : '') ?>"
                          data-ratio="<?= (int) round($line['hours'] * 100 / $maxHours) ?>"></span>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <p class="hint"><?= e(t('pln.imbalanceNote')) ?></p>
    <?php endif; ?>
  </div>
</section>
