<?php $csrf = \App\Core\Csrf::field(); ?>
<div class="card">
  <div class="org-head">
    <div class="row-actions">
      <a class="btn btn-sm" href="/agenda?mois=<?= e($previousMonth) ?><?= $shared ? '&vue=equipe' : '' ?>">←</a>
      <strong><?= e($month) ?></strong>
      <a class="btn btn-sm" href="/agenda?mois=<?= e($nextMonth) ?><?= $shared ? '&vue=equipe' : '' ?>">→</a>
    </div>
    <?php if ($canShare): ?>
      <div class="row-actions">
        <a class="btn btn-sm<?= $shared ? '' : ' btn-primary' ?>" href="/agenda?mois=<?= e($month) ?>"><?= e(t('agenda.mine')) ?></a>
        <a class="btn btn-sm<?= $shared ? ' btn-primary' : '' ?>" href="/agenda?mois=<?= e($month) ?>&vue=equipe"><?= e(t('agenda.teamView')) ?></a>
      </div>
    <?php endif; ?>
  </div>

  <!-- La grille reprend les classes de la feuille de style du produit :
       une case par jour, trois entrées visibles, le reste compté. -->
  <div class="calendar-wrap">
    <table class="calendar">
      <thead>
        <tr>
          <?php for ($d = 1; $d <= 7; $d++): ?>
            <th scope="col"><?= e(t("cal.d$d")) ?></th>
          <?php endfor; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($weeks as $week): ?>
          <tr>
            <?php foreach ($week as $day): ?>
              <?php $entries = $agenda[$day['iso']] ?? []; ?>
              <td class="cal-cell<?= $day['inMonth'] ? '' : ' is-outside' ?><?= $day['isToday'] ? ' is-today' : '' ?><?= $day['isWeekend'] ? ' is-weekend' : '' ?>">
                <span class="cal-day"><?= (int) $day['day'] ?></span>
                <?php foreach (array_slice($entries, 0, 3) as $entry): ?>
                  <span class="cal-event is-<?= e($entry['source']) ?>"
                        title="<?= e((empty($entry['owner']) ? '' : $entry['owner'] . ' — ') . $entry['title'] . ($entry['time'] === '' ? '' : ' · ' . $entry['time'])) ?>">
                    <?php if ($entry['startTime'] !== ''): ?><span class="cal-event-time"><?= e($entry['startTime']) ?></span><?php endif; ?>
                    <?= e($entry['title']) ?>
                  </span>
                <?php endforeach; ?>
                <?php if (count($entries) > 3): ?>
                  <span class="cal-more">+<?= count($entries) - 3 ?></span>
                <?php endif; ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid grid-2 mt-l">
  <div class="card">
    <h2><?= e(t('agenda.newEvent')) ?></h2>
    <form method="POST" action="/agenda" class="form-grid">
      <?= $csrf ?>
      <input type="hidden" name="mois" value="<?= e($month) ?>" />
      <label class="span-2"><span><?= e(t('common.title')) ?></span><input type="text" name="title" required maxlength="140" /></label>
      <label><span><?= e(t('leave.from')) ?></span><input type="date" name="start_date" required value="<?= e($today) ?>" /></label>
      <label><span><?= e(t('leave.to')) ?></span><input type="date" name="end_date" /></label>
      <label><span><?= e(t('timer.start_label')) ?></span><input type="time" name="start_time" /></label>
      <label><span><?= e(t('timer.end_label')) ?></span><input type="time" name="end_time" /></label>
      <label><span><?= e(t('agenda.allDay')) ?></span><input type="checkbox" name="all_day" /></label>
      <label><span><?= e(t('common.category')) ?></span>
        <select name="category">
          <?php foreach ($categories as $category): ?>
            <option value="<?= e($category) ?>"><?= e($category) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.place')) ?></span><input type="text" name="location" maxlength="140" /></label>
      <label><span><?= e(t('agenda.share')) ?></span>
        <select name="visibility">
          <?php foreach ($visibilities as $visibility): ?>
            <option value="<?= e($visibility) ?>"><?= e($visibility) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('common.description')) ?></span><textarea name="description" rows="2" maxlength="2000"></textarea></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.add')) ?></button>
    </form>
  </div>

  <div class="card">
    <h2><?= e(t('agenda.upcoming')) ?></h2>
    <?php if ($upcoming === []): ?>
      <div class="empty-state"><?= e(t('agenda.nothingUpcoming')) ?></div>
    <?php else: ?>
      <ul class="people">
        <?php foreach ($upcoming as $entry): ?>
          <li>
            <strong><?= e(\App\Core\Dates::short((string) $entry['date'])) ?></strong>
            <?= e($entry['title']) ?>
            <?php if ($entry['time'] !== ''): ?><span class="cell-sub"> · <?= e($entry['time']) ?></span><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<div class="card mt-l">
  <h2><?= e(t('agenda.mine')) ?></h2>
  <?php
    $mine = [];
    foreach ($agenda as $iso => $entries) {
        foreach ($entries as $entry) {
            if ($entry['source'] === 'personnel' && !isset($mine[$entry['id']])) {
                $mine[$entry['id']] = $entry + ['date' => $iso];
            }
        }
    }
  ?>
  <?php if ($mine === []): ?>
    <div class="empty-state"><?= e(t('agenda.noEvent')) ?></div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th><?= e(t('common.date')) ?></th>
          <th><?= e(t('common.title')) ?></th>
          <th><?= e(t('agenda.share')) ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($mine as $entry): ?>
          <tr>
            <td><?= e(\App\Core\Dates::short((string) $entry['date'])) ?><?= $entry['time'] === '' ? '' : '<br />' ?><span class="cell-sub"><?= e($entry['time']) ?></span></td>
            <td><?= e($entry['title']) ?><br /><span class="cell-sub"><?= e((string) $entry['category']) ?></span></td>
            <td>
              <form method="POST" action="/agenda/<?= (int) $entry['id'] ?>/partage" class="inline-form">
                <?= $csrf ?>
                <input type="hidden" name="mois" value="<?= e($month) ?>" />
                <select name="visibility">
                  <?php foreach ($visibilities as $visibility): ?>
                    <option value="<?= e($visibility) ?>"<?= $entry['visibility'] === $visibility ? ' selected' : '' ?>><?= e($visibility) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
              </form>
            </td>
            <td>
              <form method="POST" action="/agenda/<?= (int) $entry['id'] ?>/supprimer">
                <?= $csrf ?>
                <input type="hidden" name="mois" value="<?= e($month) ?>" />
                <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
