<?php
$csrf = \App\Core\Csrf::field();
$moment = static fn (?string $value): string => ($value === null || $value === '')
    ? '—' : str_replace('T', ' ', substr((string) $value, 0, 16));
$money = static fn (mixed $value): string => $value === null
    ? '—' : number_format((float) $value, 2, ',', ' ') . ' €';
?>
<section class="stats-grid">
  <?php foreach (array_filter([
      [(string) $summary['upcoming'], t('evt.upcoming')],
      [(string) $summary['registered'], t('evt.registeredTotal')],
      [(string) $summary['waiting'], t('evt.waitingTotal')],
      $canManage ? [$money($summary['spend']), t('evt.spend12')] : null,
  ]) as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<?php if ($mine !== []): ?>
  <div class="card">
    <div class="card-head"><h2><?= e(t('evt.myRegistrations', ['count' => count($mine)])) ?></h2></div>
    <ul class="org-list">
      <?php foreach ($mine as $event): ?>
        <li class="org-item">
          <span>
            <a class="cell-strong" href="/evenements/<?= (int) $event['id'] ?>"><?= e($event['title']) ?></a>
            <span class="tag <?= $event['my_status'] === 'Inscrit' ? 'tag-success' : 'tag-warning' ?>">
              <?= e(st($event['my_status'])) ?>
            </span>
            <br /><span class="cell-sub">
              <?= e($moment($event['starts_at'])) ?><?= $event['location'] !== '' ? ' · ' . e($event['location']) : '' ?>
            </span>
          </span>
          <a class="btn btn-outline btn-sm" href="/evenements/<?= (int) $event['id'] ?>"><?= e(t('common.open')) ?></a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card mt-l">
  <div class="card-head"><h2><?= e(t('evt.eventCount', ['count' => count($eventList)])) ?></h2></div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th><?= e(t('evt.event')) ?></th>
          <th><?= e(t('common.type')) ?></th>
          <th><?= e(t('evt.when')) ?></th>
          <th><?= e(t('evt.scope')) ?></th>
          <th><?= e(t('evt.seats')) ?></th>
          <th><?= e(t('common.status')) ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($eventList as $event): ?>
          <tr>
            <td class="cell-strong">
              <a href="/evenements/<?= (int) $event['id'] ?>"><?= e($event['title']) ?></a>
              <?php if ($event['location'] !== ''): ?><br /><span class="cell-sub"><?= e($event['location']) ?></span><?php endif; ?>
            </td>
            <td class="cell-sub"><?= e(st($event['kind'])) ?></td>
            <td class="cell-sub nowrap"><?= e($moment($event['starts_at'])) ?></td>
            <td class="cell-sub"><?= e($event['scope_name'] ?? t('evt.wholeCompany')) ?></td>
            <td class="cell-sub nowrap">
              <?= (int) $event['capacity'] > 0
                  ? e(t('inf.seatsOf', ['used' => (int) $event['taken'], 'total' => (int) $event['capacity']]))
                  : (int) $event['taken'] ?>
              <?php if ((int) $event['waiting'] > 0): ?>
                <br /><span class="cell-sub"><?= e(t('evt.waiting', ['count' => (int) $event['waiting']])) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <span class="tag <?= $event['status'] === 'Ouvert' ? 'tag-success' : ($event['status'] === 'Annulé' ? 'tag-danger' : '') ?>">
                <?= e(st($event['status'])) ?>
              </span>
            </td>
            <td><a class="btn btn-outline btn-sm" href="/evenements/<?= (int) $event['id'] ?>"><?= e(t('common.open')) ?></a></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($eventList === []): ?>
          <tr><td colspan="7" class="cell-sub"><?= e(t('evt.noEvent')) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canManage): ?>
  <div class="card mt-l" id="nouveau">
    <div class="card-head"><h2><?= e(t('evt.newEvent')) ?></h2></div>
    <form method="POST" action="/evenements" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('common.title')) ?></span>
        <input type="text" name="title" required maxlength="150" />
      </label>
      <label><span><?= e(t('common.type')) ?></span>
        <select name="kind">
          <?php foreach ($kinds as $kind): ?><option value="<?= e($kind) ?>"><?= e(st($kind)) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('evt.location')) ?></span><input type="text" name="location" maxlength="200" /></label>
      <label><span><?= e(t('evt.startsAt')) ?></span><input type="datetime-local" name="starts_at" required /></label>
      <label><span><?= e(t('evt.endsAt')) ?></span><input type="datetime-local" name="ends_at" /></label>
      <label><span><?= e(t('evt.scope')) ?></span>
        <select name="scope">
          <option value="company"><?= e(t('evt.wholeCompany')) ?></option>
          <option value="department"><?= e(t('evt.oneDepartment')) ?></option>
          <option value="team"><?= e(t('evt.oneTeam')) ?></option>
        </select>
      </label>
      <label><span><?= e(t('evt.scopeTarget')) ?></span>
        <select name="scope_id">
          <option value="">—</option>
          <?php foreach ($departments as $department): ?>
            <option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option>
          <?php endforeach; ?>
          <?php foreach ($teams as $team): ?>
            <option value="<?= (int) $team['id'] ?>"><?= e($team['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('evt.capacity')) ?></span>
        <input type="number" name="capacity" min="0" max="100000" value="0" />
      </label>
      <label><span><?= e(t('evt.closesOn')) ?></span><input type="date" name="registration_closes_on" /></label>
      <label><span><?= e(t('evt.budget')) ?></span><input type="text" name="budget" inputmode="decimal" /></label>
      <label><span><?= e(t('evt.cost')) ?></span><input type="text" name="cost" inputmode="decimal" /></label>
      <label class="span-2"><span><?= e(t('common.description')) ?></span>
        <textarea name="description" rows="4" maxlength="4000"></textarea>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
    </form>
    <p class="hint"><?= e(t('evt.capacityHint')) ?></p>
  </div>
<?php endif; ?>
