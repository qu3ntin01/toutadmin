<?php
$csrf = \App\Core\Csrf::field();
$moment = static fn (?string $value): string => ($value === null || $value === '')
    ? '—' : str_replace('T', ' ', substr((string) $value, 0, 16));
$formMoment = static fn (?string $value): string => ($value === null || $value === '')
    ? '' : str_replace(' ', 'T', substr((string) $value, 0, 16));
$money = static fn (mixed $value): string => $value === null
    ? '—' : number_format((float) $value, 2, ',', ' ') . ' €';
$registered = $myRegistration !== null && $myRegistration['status'] !== 'Annulée';
?>
<div class="card">
  <div class="card-head">
    <div>
      <h2><?= e(st($event['kind'])) ?> · <?= e($moment($event['starts_at'])) ?></h2>
      <p class="card-sub">
        <span class="tag <?= $event['status'] === 'Ouvert' ? 'tag-success' : ($event['status'] === 'Annulé' ? 'tag-danger' : '') ?>">
          <?= e(st($event['status'])) ?>
        </span>
        <?= e($event['location'] !== '' ? $event['location'] : t('evt.noLocation')) ?>
        · <?= e($event['scope_name'] ?? t('evt.wholeCompany')) ?>
        <?php if (!empty($event['organizer_first_name'])): ?>
          · <?= e(t('evt.organizedBy', ['name' => $event['organizer_first_name'] . ' ' . $event['organizer_last_name']])) ?>
        <?php endif; ?>
      </p>
    </div>
    <span class="tag">
      <?= e((int) $event['capacity'] > 0
          ? t('inf.seatsOf', ['used' => (int) $event['taken'], 'total' => (int) $event['capacity']])
          : t('evt.registeredCount', ['count' => (int) $event['taken']])) ?>
    </span>
  </div>

  <?php if ($event['description'] !== ''): ?>
    <p class="article-body"><?= e($event['description']) ?></p>
  <?php endif; ?>

  <ul class="org-list">
    <li class="org-item"><span><?= e(t('evt.startsAt')) ?></span><span class="cell-strong"><?= e($moment($event['starts_at'])) ?></span></li>
    <?php if (!empty($event['ends_at'])): ?>
      <li class="org-item"><span><?= e(t('evt.endsAt')) ?></span><span class="cell-strong"><?= e($moment($event['ends_at'])) ?></span></li>
    <?php endif; ?>
    <?php if (!empty($event['registration_closes_on'])): ?>
      <li class="org-item"><span><?= e(t('evt.closesOn')) ?></span>
        <span class="<?= $event['registration_closes_on'] < $today ? 'text-danger' : 'cell-strong' ?>">
          <?= e($event['registration_closes_on']) ?>
        </span>
      </li>
    <?php endif; ?>
    <?php if ((int) $event['capacity'] > 0): ?>
      <li class="org-item"><span><?= e(t('evt.seatsLeft')) ?></span><span class="cell-strong"><?= (int) $seatsLeft ?></span></li>
    <?php endif; ?>
    <?php if ((int) $event['waiting'] > 0): ?>
      <li class="org-item"><span><?= e(t('evt.waitingList')) ?></span><span class="cell-strong"><?= (int) $event['waiting'] ?></span></li>
    <?php endif; ?>
    <?php if ($canManage): ?>
      <li class="org-item"><span><?= e(t('evt.budget')) ?></span><span class="cell-strong"><?= e($money($event['budget'])) ?></span></li>
      <li class="org-item"><span><?= e(t('evt.cost')) ?></span><span class="cell-strong"><?= e($money($event['cost'])) ?></span></li>
    <?php endif; ?>
  </ul>
</div>

<?php if ($concerned && $event['status'] !== 'Brouillon'): ?>
  <div class="card mt-l">
    <div class="card-head"><h2><?= e(t('evt.myPlace')) ?></h2></div>
    <?php if ($registered): ?>
      <p class="card-sub">
        <span class="tag <?= $myRegistration['status'] === 'Inscrit' ? 'tag-success' : 'tag-warning' ?>">
          <?= e(st($myRegistration['status'])) ?>
        </span>
        <?php if ($myRegistration['status'] === "Liste d'attente"): ?><?= e(t('evt.waitingExplain')) ?><?php endif; ?>
      </p>
      <?php if ($event['status'] !== 'Annulé' && $event['status'] !== 'Clos'): ?>
        <form method="POST" action="/evenements/<?= (int) $event['id'] ?>/desistement"
              data-confirm="<?= e(t('evt.confirmCancel')) ?>">
          <?= $csrf ?>
          <button type="submit" class="btn btn-outline"><?= e(t('evt.withdraw')) ?></button>
        </form>
      <?php endif; ?>
    <?php elseif ($event['status'] === 'Ouvert' || $event['status'] === 'Complet'): ?>
      <form method="POST" action="/evenements/<?= (int) $event['id'] ?>/inscription">
        <?= $csrf ?>
        <button type="submit" class="btn btn-primary">
          <?= e($event['status'] === 'Complet' ? t('evt.joinWaitlist') : t('evt.register')) ?>
        </button>
      </form>
      <?php if ($event['status'] === 'Complet'): ?><p class="hint"><?= e(t('evt.fullExplain')) ?></p><?php endif; ?>
    <?php else: ?>
      <div class="empty-state"><?= e(t('evt.registrationClosed')) ?></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($canManage): ?>
  <div class="card mt-l">
    <div class="card-head"><h2><?= e(t('evt.participants', ['count' => count($registrationList)])) ?></h2></div>
    <p class="hint"><?= e(t('evt.participantsHint')) ?></p>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.member')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th><?= e(t('evt.registeredAt')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($registrationList as $row): ?>
            <tr>
              <td>
                <div class="cell-strong"><?= e($row['first_name'] . ' ' . $row['last_name']) ?></div>
                <div class="cell-sub"><?= e($row['grade'] !== '' ? $row['grade'] : $row['email']) ?></div>
              </td>
              <td>
                <span class="tag <?= in_array($row['status'], ['Inscrit', 'Présent'], true)
                    ? 'tag-success'
                    : (in_array($row['status'], ['Annulée', 'Absent'], true) ? '' : 'tag-warning') ?>">
                  <?= e(st($row['status'])) ?>
                </span>
              </td>
              <td class="cell-sub nowrap"><?= e($moment($row['registered_at'])) ?></td>
              <td class="actions">
                <?php if ($row['status'] !== 'Annulée'): ?>
                  <?php foreach ([['Présent', t('evt.markPresent')], ['Absent', t('evt.markAbsent')]] as [$presence, $label]): ?>
                    <form method="POST" action="/evenements/inscriptions/<?= (int) $row['id'] ?>/emargement" class="inline-form">
                      <?= $csrf ?>
                      <input type="hidden" name="presence" value="<?= e($presence) ?>" />
                      <button type="submit" class="btn btn-outline btn-sm"><?= e($label) ?></button>
                    </form>
                  <?php endforeach; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($registrationList === []): ?>
            <tr><td colspan="4" class="cell-sub"><?= e(t('evt.noRegistration')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card mt-l">
    <div class="card-head"><h2><?= e(t('evt.registerSomeone')) ?></h2></div>
    <form method="POST" action="/evenements/<?= (int) $event['id'] ?>/inscrire" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('common.member')) ?></span>
        <select name="user_id" required>
          <option value="" disabled selected><?= e(t('common.choose')) ?></option>
          <?php foreach ($employees as $person): ?>
            <option value="<?= (int) $person['id'] ?>"><?= e($person['first_name'] . ' ' . $person['last_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('evt.addRegistration')) ?></button></div>
    </form>
  </div>

  <div class="card mt-l">
    <div class="card-head">
      <h2><?= e(t('evt.editEvent')) ?></h2>
      <form method="POST" action="/evenements/<?= (int) $event['id'] ?>/supprimer"
            data-confirm="<?= e(t('evt.confirmDelete')) ?>">
        <?= $csrf ?>
        <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
      </form>
    </div>
    <form method="POST" action="/evenements/<?= (int) $event['id'] ?>/modifier" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('common.title')) ?></span>
        <input type="text" name="title" value="<?= e($event['title']) ?>" required maxlength="150" />
      </label>
      <label><span><?= e(t('common.type')) ?></span>
        <select name="kind">
          <?php foreach ($kinds as $kind): ?>
            <option value="<?= e($kind) ?>"<?= $event['kind'] === $kind ? ' selected' : '' ?>><?= e(st($kind)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('evt.location')) ?></span>
        <input type="text" name="location" value="<?= e($event['location']) ?>" maxlength="200" />
      </label>
      <label><span><?= e(t('evt.startsAt')) ?></span>
        <input type="datetime-local" name="starts_at" value="<?= e($formMoment($event['starts_at'])) ?>" required />
      </label>
      <label><span><?= e(t('evt.endsAt')) ?></span>
        <input type="datetime-local" name="ends_at" value="<?= e($formMoment($event['ends_at'])) ?>" />
      </label>
      <label><span><?= e(t('evt.scope')) ?></span>
        <select name="scope">
          <?php foreach ([
              ['company', t('evt.wholeCompany')], ['department', t('evt.oneDepartment')], ['team', t('evt.oneTeam')],
          ] as [$value, $label]): ?>
            <option value="<?= e($value) ?>"<?= $event['scope'] === $value ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('evt.scopeTarget')) ?></span>
        <select name="scope_id">
          <option value="">—</option>
          <?php foreach ($departments as $department): ?>
            <option value="<?= (int) $department['id'] ?>"
              <?= $event['scope'] === 'department' && (int) $event['scope_id'] === (int) $department['id'] ? ' selected' : '' ?>>
              <?= e($department['name']) ?>
            </option>
          <?php endforeach; ?>
          <?php foreach ($teams as $team): ?>
            <option value="<?= (int) $team['id'] ?>"
              <?= $event['scope'] === 'team' && (int) $event['scope_id'] === (int) $team['id'] ? ' selected' : '' ?>>
              <?= e($team['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('evt.capacity')) ?></span>
        <input type="number" name="capacity" min="0" max="100000" value="<?= (int) $event['capacity'] ?>" />
      </label>
      <label><span><?= e(t('evt.closesOn')) ?></span>
        <input type="date" name="registration_closes_on" value="<?= e((string) ($event['registration_closes_on'] ?? '')) ?>" />
      </label>
      <label><span><?= e(t('evt.budget')) ?></span>
        <input type="text" name="budget" inputmode="decimal" value="<?= e($event['budget'] === null ? '' : (string) $event['budget']) ?>" />
      </label>
      <label><span><?= e(t('evt.cost')) ?></span>
        <input type="text" name="cost" inputmode="decimal" value="<?= e($event['cost'] === null ? '' : (string) $event['cost']) ?>" />
      </label>
      <label><span><?= e(t('evt.organizer')) ?></span>
        <select name="organizer_id">
          <?php foreach ($employees as $person): ?>
            <option value="<?= (int) $person['id'] ?>"<?= (int) $event['organizer_id'] === (int) $person['id'] ? ' selected' : '' ?>>
              <?= e($person['first_name'] . ' ' . $person['last_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.status')) ?></span>
        <select name="status">
          <?php foreach ($statuses as $status): ?>
            <option value="<?= e($status) ?>"<?= $event['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('common.description')) ?></span>
        <textarea name="description" rows="4" maxlength="4000"><?= e($event['description']) ?></textarea>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
    </form>
    <p class="hint"><?= e(t('evt.statusHint')) ?></p>
  </div>
<?php endif; ?>
