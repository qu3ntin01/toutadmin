<?php
$csrf = \App\Core\Csrf::field();
$money = static fn (?float $value): string => $value === null ? '—' : number_format($value, 2, ',', ' ') . ' €';
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso;
$who = static fn (array $row): string => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
?>
<section class="stats-grid">
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value">
        <?= e((int) $licence['seats'] > 0
            ? t('inf.seatsOf', ['used' => (int) $licence['seats_used'], 'total' => (int) $licence['seats']])
            : (string) (int) $licence['seats_used']) ?>
      </span>
      <span class="stat-label"><?= e(t('inf.openAccesses')) ?></span>
    </span>
  </div>
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value"><?= e($money($yearlyCost)) ?></span>
      <span class="stat-label"><?= e(t('inf.yearlyCost')) ?></span>
    </span>
  </div>
  <div class="stat-card">
    <span class="stat-body">
      <span class="stat-value"><?= e($day($licence['renewal_date'])) ?></span>
      <span class="stat-label"><?= e(t('inf.renewal')) ?></span>
    </span>
  </div>
</section>

<section class="tab-panel is-active" id="fiche">
  <div class="card">
    <div class="card-head">
      <h2><?= e(t('inf.sheet')) ?></h2>
      <form method="POST" action="/informatique/logiciels/<?= (int) $licence['id'] ?>/supprimer"
            data-confirm="<?= e(t('inf.confirmDeleteSoftware')) ?>">
        <?= $csrf ?>
        <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
      </form>
    </div>
    <form method="POST" action="/informatique/logiciels/<?= (int) $licence['id'] ?>/modifier" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('inf.software')) ?></span>
        <input type="text" name="name" value="<?= e($licence['name']) ?>" required maxlength="120" />
      </label>
      <label><span><?= e(t('inf.publisher')) ?></span>
        <input type="text" name="publisher" value="<?= e($licence['publisher']) ?>" maxlength="120" />
      </label>
      <label><span><?= e(t('common.type')) ?></span>
        <select name="kind">
          <?php foreach ($kinds as $kind): ?>
            <option value="<?= e($kind) ?>"<?= $licence['kind'] === $kind ? ' selected' : '' ?>><?= e(st($kind)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('inf.criticality')) ?></span>
        <select name="criticality">
          <?php foreach ($criticalities as $criticality): ?>
            <option value="<?= e($criticality) ?>"<?= $licence['criticality'] === $criticality ? ' selected' : '' ?>>
              <?= e(st($criticality)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('inf.seats')) ?></span>
        <input type="number" name="seats" min="0" max="100000" value="<?= (int) $licence['seats'] ?>" />
      </label>
      <label><span><?= e(t('inf.unitCost')) ?></span>
        <input type="text" name="unit_cost" inputmode="decimal"
               value="<?= $licence['unit_cost'] === null ? '' : e((string) $licence['unit_cost']) ?>" />
      </label>
      <label><span><?= e(t('inf.billingPeriod')) ?></span>
        <select name="billing_period">
          <?php foreach ($billingPeriods as $period): ?>
            <option value="<?= e($period) ?>"<?= $licence['billing_period'] === $period ? ' selected' : '' ?>><?= e(st($period)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('inf.renewal')) ?></span>
        <input type="date" name="renewal_date" value="<?= e((string) ($licence['renewal_date'] ?? '')) ?>" />
      </label>
      <label><span><?= e(t('inf.owner')) ?></span>
        <select name="owner_id">
          <option value="">—</option>
          <?php foreach ($employees as $person): ?>
            <option value="<?= (int) $person['id'] ?>"<?= (int) $licence['owner_id'] === (int) $person['id'] ? ' selected' : '' ?>>
              <?= e($who($person)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.status')) ?></span>
        <select name="status">
          <?php foreach ($statuses as $status): ?>
            <option value="<?= e($status) ?>"<?= $licence['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="span-2 check-row">
        <input type="checkbox" name="personal_data" value="1"<?= (int) $licence['personal_data'] === 1 ? ' checked' : '' ?> />
        <span><?= e(t('inf.personalDataLabel')) ?></span>
      </label>
      <label class="span-2"><span><?= e(t('common.notes')) ?></span>
        <textarea name="notes" rows="3" maxlength="1000"><?= e($licence['notes']) ?></textarea>
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
    </form>
  </div>
</section>

<section class="tab-panel" id="acces">
  <div class="card">
    <h2><?= e(t('inf.grantAccess')) ?></h2>
    <?php if ($grantable === []): ?>
      <div class="empty-state"><?= e(t('inf.everyoneHasAccess')) ?></div>
    <?php else: ?>
      <form method="POST" action="/informatique/logiciels/<?= (int) $licence['id'] ?>/acces" class="form-grid">
        <?= $csrf ?>
        <label><span><?= e(t('common.member')) ?></span>
          <select name="user_id" required>
            <option value="" disabled selected><?= e(t('common.choose')) ?></option>
            <?php foreach ($grantable as $person): ?>
              <option value="<?= (int) $person['id'] ?>"><?= e($who($person)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('inf.level')) ?></span>
          <select name="level">
            <?php foreach ($levels as $level): ?><option value="<?= e($level) ?>"><?= e(st($level)) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label class="span-2"><span><?= e(t('inf.accessReason')) ?></span><input type="text" name="note" maxlength="300" /></label>
        <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('inf.openAccess')) ?></button></div>
      </form>
    <?php endif; ?>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('inf.accessCount', ['count' => count($accessList)])) ?></h2>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.member')) ?></th>
            <th><?= e(t('inf.level')) ?></th>
            <th><?= e(t('inf.grantedOn')) ?></th>
            <th><?= e(t('inf.reviewedOn')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($accessList as $access): ?>
            <tr>
              <td>
                <div class="cell-strong"><?= e($who($access)) ?></div>
                <div class="cell-sub"><?= e($access['email']) ?></div>
              </td>
              <td class="cell-sub"><?= e(st($access['level'])) ?></td>
              <td class="cell-sub nowrap">
                <?= e($day($access['granted_on'])) ?>
                <?php if ($access['by_first_name'] !== null): ?>
                  <br /><span class="cell-sub">
                    <?= e(t('inf.grantedBy', ['name' => trim($access['by_first_name'] . ' ' . $access['by_last_name'])])) ?>
                  </span>
                <?php endif; ?>
              </td>
              <td class="cell-sub nowrap"><?= e($day($access['reviewed_on'])) ?></td>
              <td>
                <?php if (!empty($access['revoked_on'])): ?>
                  <span class="tag"><?= e(t('inf.revokedOn', ['date' => $day($access['revoked_on'])])) ?></span>
                <?php elseif ((int) $access['active'] === 0): ?>
                  <span class="tag tag-danger"><?= e(t('inf.accountClosed')) ?></span>
                <?php else: ?>
                  <span class="tag tag-success"><?= e(t('inf.accessOpen')) ?></span>
                <?php endif; ?>
              </td>
              <td class="actions">
                <?php if (empty($access['revoked_on'])): ?>
                  <form method="POST" action="/informatique/acces/<?= (int) $access['id'] ?>/revoquer"
                        data-confirm="<?= e(t('inf.confirmRevoke')) ?>">
                    <?= $csrf ?>
                    <button type="submit" class="btn btn-sm btn-danger"><?= e(t('inf.revoke')) ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($accessList === []): ?>
            <tr><td colspan="6" class="cell-sub"><?= e(t('inf.noAccess')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
