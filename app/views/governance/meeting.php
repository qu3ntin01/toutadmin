<?php
$csrf = \App\Core\Csrf::field();
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso;
$who = static fn (array $row): string =>
    trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: '—';
?>
<!-- ---------- Ordre du jour ---------- -->
<section class="tab-panel is-active" id="ordre-du-jour">
  <div class="card">
    <div class="card-head">
      <h2><?= e(t('reu.convocation')) ?></h2>
      <span class="tag <?= $meeting['status'] === 'Tenue' ? 'tag-success' : ($meeting['status'] === 'Annulée' ? 'tag-danger' : 'tag-warning') ?>">
        <?= e(st($meeting['status'])) ?>
      </span>
    </div>
    <form method="POST" action="/direction/reunions/<?= (int) $meeting['id'] ?>/modifier" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('common.title')) ?></span>
        <input type="text" name="title" required maxlength="200" value="<?= e($meeting['title']) ?>" />
      </label>
      <label><span><?= e(t('common.type')) ?></span>
        <select name="kind">
          <?php foreach ($meetingKinds as $kind): ?>
            <option value="<?= e($kind) ?>"<?= $kind === $meeting['kind'] ? ' selected' : '' ?>><?= e($kind) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.date')) ?></span><input type="date" name="held_on" value="<?= e($meeting['held_on']) ?>" required /></label>
      <label><span><?= e(t('common.start')) ?></span><input type="time" name="starts_at" value="<?= e($meeting['starts_at']) ?>" /></label>
      <label><span><?= e(t('common.end')) ?></span><input type="time" name="ends_at" value="<?= e($meeting['ends_at']) ?>" /></label>
      <label><span><?= e(t('common.place')) ?></span>
        <input type="text" name="location" maxlength="160" value="<?= e($meeting['location']) ?>" />
      </label>
      <label><span><?= e(t('gov.chair')) ?></span>
        <select name="chair_id">
          <option value="">—</option>
          <?php foreach ($employees as $person): ?>
            <option value="<?= (int) $person['id'] ?>"<?= (int) $person['id'] === (int) $meeting['chair_id'] ? ' selected' : '' ?>>
              <?= e($who($person)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.state')) ?></span>
        <select name="status">
          <?php foreach ($meetingStatuses as $status): ?>
            <option value="<?= e($status) ?>"<?= $status === $meeting['status'] ? ' selected' : '' ?>><?= e($status) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('cse.agenda')) ?></span><textarea name="agenda" rows="6"><?= e($meeting['agenda']) ?></textarea></label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
    </form>
  </div>
</section>

<!-- ---------- Participants ---------- -->
<section class="tab-panel" id="participants">
  <div class="card">
    <h2><?= e(t('reu.invite')) ?></h2>
    <form method="POST" action="/direction/reunions/<?= (int) $meeting['id'] ?>/participants" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('reu.people')) ?></span>
        <select name="user_ids[]" multiple size="8">
          <?php foreach ($employees as $person): ?>
            <option value="<?= (int) $person['id'] ?>">
              <?= e($who($person)) ?><?= !empty($person['grade']) ? ' — ' . e($person['grade']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('reu.invite')) ?></button></div>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= count($attendeeList) ?> participant(s)</h2>
    <?php if ($attendeeList === []): ?>
      <div class="empty-state"><?= e(t('reu.nobodyInvited')) ?></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('common.person')) ?></th>
              <th><?= e(t('common.role')) ?></th>
              <th><?= e(t('reu.attendance')) ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($attendeeList as $attendee): ?>
              <tr>
                <td class="cell-strong"><?= e($who($attendee)) ?></td>
                <td class="cell-sub"><?= e(!empty($attendee['grade']) ? $attendee['grade'] : '—') ?></td>
                <td>
                  <form method="POST"
                        action="/direction/reunions/<?= (int) $meeting['id'] ?>/participants/<?= (int) $attendee['user_id'] ?>/presence"
                        class="inline-field">
                    <?= $csrf ?>
                    <select name="attendance" class="input-sm">
                      <?php foreach ($attendances as $attendance): ?>
                        <option value="<?= e($attendance) ?>"<?= $attendance === $attendee['attendance'] ? ' selected' : '' ?>>
                          <?= e($attendance) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-outline btn-sm"><?= e(t('reu.note')) ?></button>
                  </form>
                </td>
                <td>
                  <form method="POST"
                        action="/direction/reunions/<?= (int) $meeting['id'] ?>/participants/<?= (int) $attendee['user_id'] ?>/retirer"
                        class="inline-form">
                    <?= $csrf ?>
                    <button type="submit" class="btn btn-link btn-sm"><?= e(t('common.remove')) ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>

<!-- ---------- Compte rendu ---------- -->
<section class="tab-panel" id="compte-rendu">
  <div class="card">
    <h2><?= e(t('reu.minutes')) ?></h2>
    <form method="POST" action="/direction/reunions/<?= (int) $meeting['id'] ?>/compte-rendu" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('reu.record')) ?></span>
        <textarea name="minutes" rows="14" placeholder="<?= e(t('reu.minutesPlaceholder')) ?>"><?= e($meeting['minutes']) ?></textarea>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('reu.saveAndHeld')) ?></button></div>
    </form>
  </div>
</section>

<!-- ---------- Décisions ---------- -->
<section class="tab-panel" id="decisions">
  <div class="card">
    <h2><?= e(t('reu.decisionTaken')) ?></h2>
    <form method="POST" action="/direction/decisions" class="form-grid">
      <?= $csrf ?>
      <input type="hidden" name="meeting_id" value="<?= (int) $meeting['id'] ?>" />
      <label class="span-2"><span><?= e(t('gov.decision')) ?></span><input type="text" name="title" required maxlength="200" /></label>
      <label><span><?= e(t('gov.takenOn')) ?></span><input type="date" name="decided_on" value="<?= e($meeting['held_on']) ?>" required /></label>
      <label><span><?= e(t('common.scope')) ?></span>
        <select name="scope">
          <?php foreach ($decisionScopes as $scope): ?><option value="<?= e($scope) ?>"><?= e($scope) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('common.content')) ?></span><textarea name="body" rows="3"></textarea></label>
      <label class="span-2"><span><?= e(t('common.reason')) ?></span><textarea name="rationale" rows="2"></textarea></label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('gov.registerRisk')) ?></button></div>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('reu.decisionCount', ['count' => count($decisionList)])) ?></h2>
    <?php if ($decisionList === []): ?>
      <div class="empty-state"><?= e(t('reu.noDecision')) ?></div>
    <?php else: ?>
      <ul class="meeting-list">
        <?php foreach ($decisionList as $decision): ?>
          <li class="meeting-item">
            <div class="meeting-head">
              <span class="meeting-title"><?= e($decision['title']) ?></span>
              <span class="tag <?= $decision['status'] === 'En vigueur' ? 'tag-success' : 'tag-warning' ?>">
                <?= e(st($decision['status'])) ?>
              </span>
            </div>
            <p class="cell-sub"><?= e($day($decision['decided_on'])) ?> · <?= e($decision['scope']) ?></p>
            <?php if ($decision['body'] !== ''): ?><p class="cell-sub"><?= e($decision['body']) ?></p><?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>

<!-- ---------- Actions ---------- -->
<section class="tab-panel" id="actions">
  <div class="card">
    <h2><?= e(t('gov.entrustAction')) ?></h2>
    <form method="POST" action="/direction/actions" class="form-grid">
      <?= $csrf ?>
      <input type="hidden" name="meeting_id" value="<?= (int) $meeting['id'] ?>" />
      <label class="span-2"><span><?= e(t('common.action')) ?></span><input type="text" name="label" required maxlength="300" /></label>
      <label><span><?= e(t('gov.entrustedTo')) ?></span>
        <select name="assignee_id">
          <option value="">—</option>
          <?php foreach ($employees as $person): ?>
            <option value="<?= (int) $person['id'] ?>"><?= e($who($person)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('gov.forDate')) ?></span><input type="date" name="due_date" /></label>
      <label class="span-2"><span><?= e(t('gov.sourceDecision')) ?></span>
        <select name="decision_id">
          <option value="">—</option>
          <?php foreach ($decisionList as $decision): ?>
            <option value="<?= (int) $decision['id'] ?>"><?= e($decision['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('gov.entrust')) ?></button></div>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= count($actionList) ?> action(s)</h2>
    <?php if ($actionList === []): ?>
      <div class="empty-state"><?= e(t('reu.noAction')) ?></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('erp.dueDate')) ?></th>
              <th><?= e(t('common.action')) ?></th>
              <th><?= e(t('gov.holder')) ?></th>
              <th><?= e(t('common.state')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($actionList as $action): ?>
              <tr>
                <td class="cell-sub nowrap">
                  <?php if (!empty($action['due_date']) && $action['due_date'] < $today && $action['status'] !== 'Faite'): ?>
                    <span class="tag tag-danger"><?= e($day($action['due_date'])) ?></span>
                  <?php else: ?>
                    <?= e($day($action['due_date'])) ?>
                  <?php endif; ?>
                </td>
                <td class="cell-strong"><?= e($action['label']) ?></td>
                <td class="cell-sub"><?= e($who($action)) ?></td>
                <td>
                  <form method="POST" action="/direction/actions/<?= (int) $action['id'] ?>/statut" class="inline-field">
                    <?= $csrf ?>
                    <input type="hidden" name="back" value="reunion" />
                    <input type="hidden" name="meeting_id" value="<?= (int) $meeting['id'] ?>" />
                    <select name="status" class="input-sm">
                      <?php foreach ($actionStatuses as $status): ?>
                        <option value="<?= e($status) ?>"<?= $status === $action['status'] ? ' selected' : '' ?>><?= e($status) ?></option>
                      <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-outline btn-sm"><?= e(t('common.update')) ?></button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
