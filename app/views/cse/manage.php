<?php
$csrf = \App\Core\Csrf::field();
$fullName = static fn (array $row): string => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
?>
<section class="stats-grid">
  <?php foreach ([
      [(string) $stats['benefitCount'], t('cse.benefits')],
      [(string) $stats['meetingCount'], t('cse.meetings')],
      [(string) $stats['pendingMinutes'], t('cse.minutes') . ' · ' . t('cse.draft')],
      [(string) $stats['memberCount'], t('cse.elected')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<!-- ---------- Avantages ---------- -->
<section class="tab-panel is-active" id="avantages">
  <div class="grid-2">
    <div class="card">
      <h2><?= e(t('cse.newBenefit')) ?></h2>
      <form method="POST" action="/cse/gestion/avantages" class="stack">
        <?= $csrf ?>
        <label><span><?= e(t('agenda.eventTitle')) ?></span><input type="text" name="title" maxlength="140" required /></label>
        <div class="form-grid">
          <label><span><?= e(t('common.type')) ?></span>
            <select name="category">
              <option value="">—</option>
              <?php foreach ($categories as $category): ?>
                <option value="<?= e($category) ?>"><?= e($category) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label><span><?= e(t('cse.partner')) ?></span><input type="text" name="partner" maxlength="140" /></label>
        </div>
        <div class="form-grid">
          <label><span><?= e(t('cse.discount')) ?></span><input type="text" name="discount" maxlength="60" placeholder="Ex : -30 %" /></label>
          <label><span><?= e(t('cse.code')) ?></span><input type="text" name="code" maxlength="60" /></label>
        </div>
        <label><span><?= e(t('common.link')) ?></span><input type="url" name="url" maxlength="500" placeholder="https://" /></label>
        <label><span><?= e(t('cse.validUntil', ['date' => ''])) ?></span><input type="date" name="valid_until" /></label>
        <label><span><?= e(t('common.description')) ?></span><textarea name="description" rows="3" maxlength="2000"></textarea></label>
        <button type="submit" class="btn btn-primary btn-block"><?= e(t('common.create')) ?></button>
        <p class="hint"><?= e(t('cse.expiredNote')) ?></p>
      </form>
    </div>

    <div class="card">
      <h2><?= e(t('cse.benefits')) ?> <span class="muted">(<?= count($benefits) ?>)</span></h2>
      <?php if ($benefits === []): ?>
        <div class="empty-state"><?= e(t('cse.noBenefits')) ?></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th><?= e(t('agenda.eventTitle')) ?></th>
                <th><?= e(t('common.type')) ?></th>
                <th><?= e(t('common.status')) ?></th>
                <th class="actions"><?= e(t('common.actions')) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($benefits as $benefit): ?>
                <?php $expired = !empty($benefit['valid_until']) && $benefit['valid_until'] < $today; ?>
                <tr>
                  <td>
                    <strong><?= e($benefit['title']) ?></strong>
                    <?php if ($benefit['partner'] !== ''): ?><div class="cell-sub"><?= e($benefit['partner']) ?></div><?php endif; ?>
                  </td>
                  <td><?= e($benefit['category'] !== '' ? $benefit['category'] : '—') ?></td>
                  <td>
                    <span class="status <?= (int) $benefit['active'] === 0 ? 'status-off' : ($expired ? 'status-warning' : 'status-on') ?>">
                      <?= e((int) $benefit['active'] === 0 ? t('common.inactive') : ($expired ? t('status.expired') : t('common.active'))) ?>
                    </span>
                  </td>
                  <td class="actions">
                    <form method="POST" action="/cse/gestion/avantages/<?= (int) $benefit['id'] ?>/modifier" class="inline-form">
                      <?= $csrf ?>
                      <input type="hidden" name="title" value="<?= e($benefit['title']) ?>" />
                      <input type="hidden" name="category" value="<?= e($benefit['category']) ?>" />
                      <input type="hidden" name="partner" value="<?= e($benefit['partner']) ?>" />
                      <input type="hidden" name="description" value="<?= e($benefit['description']) ?>" />
                      <input type="hidden" name="discount" value="<?= e($benefit['discount']) ?>" />
                      <input type="hidden" name="code" value="<?= e($benefit['code']) ?>" />
                      <input type="hidden" name="url" value="<?= e($benefit['url']) ?>" />
                      <input type="hidden" name="valid_until" value="<?= e((string) ($benefit['valid_until'] ?? '')) ?>" />
                      <?php if ((int) $benefit['active'] === 1): ?>
                        <button type="submit" class="btn btn-sm"><?= e(t('common.disable')) ?></button>
                      <?php else: ?>
                        <input type="hidden" name="active" value="on" />
                        <button type="submit" class="btn btn-sm"><?= e(t('common.enable')) ?></button>
                      <?php endif; ?>
                    </form>
                    <form method="POST" action="/cse/gestion/avantages/<?= (int) $benefit['id'] ?>/supprimer" class="inline-form"
                          data-confirm="<?= e(t('cse.confirmDeleteBenefit')) ?>">
                      <?= $csrf ?>
                      <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.delete')) ?></button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- ---------- Réunions et comptes-rendus ---------- -->
<section class="tab-panel" id="reunions">
  <div class="card">
    <h2><?= e(t('cse.meetings')) ?></h2>
    <p class="muted"><?= e(t('cse.meetingsNote')) ?></p>
    <?php if ($meetings === []): ?>
      <div class="empty-state"><?= e(t('cse.noMeetings')) ?></div>
    <?php else: ?>
      <ul class="meeting-list">
        <?php foreach ($meetings as $meeting): ?>
          <li class="meeting-item">
            <div class="meeting-head">
              <span class="meeting-title"><?= e($meeting['title']) ?></span>
              <span class="status <?= (int) $meeting['minutes_published'] === 1 ? 'status-on' : 'status-warning' ?>">
                <?= e((int) $meeting['minutes_published'] === 1 ? t('cse.published') : t('cse.draft')) ?>
              </span>
            </div>
            <p class="cell-sub">
              <?= e(\App\Core\Dates::short((string) $meeting['meeting_date'])) ?><?= $meeting['meeting_time'] !== '' ? ' · ' . e($meeting['meeting_time']) : '' ?><?= $meeting['location'] !== '' ? ' · ' . e($meeting['location']) : '' ?>
            </p>
            <?php if ($meeting['agenda'] !== ''): ?>
              <p class="cell-sub"><strong><?= e(t('cse.agenda')) ?> :</strong> <?= e($meeting['agenda']) ?></p>
            <?php endif; ?>

            <form method="POST" action="/cse/gestion/reunions/<?= (int) $meeting['id'] ?>/compte-rendu" class="stack">
              <?= $csrf ?>
              <label>
                <span><?= e(t('cse.minutes')) ?></span>
                <textarea name="minutes" rows="4" maxlength="20000"><?= e($meeting['minutes']) ?></textarea>
              </label>
              <label class="check-row">
                <input type="checkbox" name="publish"<?= (int) $meeting['minutes_published'] === 1 ? ' checked' : '' ?> />
                <span><?= e(t('cse.publishMinutes')) ?></span>
              </label>
              <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-sm"><?= e(t('common.save')) ?></button>
              </div>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>

<!-- ---------- Composition du comité ---------- -->
<section class="tab-panel" id="elus">
  <div class="card">
    <h2><?= e(t('cse.elected')) ?> <span class="muted">(<?= count($mandates) ?>)</span></h2>
    <p class="muted"><?= e(t('cse.membershipNote')) ?></p>
    <?php if ($mandates === []): ?>
      <div class="empty-state"><?= e(t('cse.noElected')) ?></div>
    <?php else: ?>
      <ul class="person-list">
        <?php foreach ($mandates as $member): ?>
          <li class="person-row">
            <span class="person-body">
              <span class="person-name"><?= e($fullName($member)) ?></span>
              <span class="cell-sub">
                <?= e(\App\Core\Dates::short((string) $member['mandate_role'])) ?> · <?= e(t('agenda.from')) ?> <?= e(\App\Core\Dates::short((string) $member['started_on'])) ?><?= !empty($member['ends_on']) ? ' → ' . e(\App\Core\Dates::short((string) $member['ends_on'])) : '' ?>
              </span>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>
