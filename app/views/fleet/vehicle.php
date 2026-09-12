<?php
$csrf = \App\Core\Csrf::field();
$money = static fn (?float $value): string => $value === null ? '—' : number_format($value, 2, ',', ' ') . ' €';
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso;
$km = static fn (int $value): string => number_format($value, 0, ',', ' ');
$who = static fn (array $row): string => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
?>
<section class="tab-panel is-active" id="historique">
  <div class="grid-2">
    <div class="card">
      <h2><?= e(t('sst.recordEvent')) ?></h2>
      <form method="POST" action="/flotte/<?= (int) $vehicle['id'] ?>/evenements" class="form-grid">
        <?= $csrf ?>
        <label><span><?= e(t('common.nature')) ?></span>
          <select name="kind">
            <?php foreach ($eventKinds as $kind): ?><option value="<?= e($kind) ?>"><?= e($kind) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('common.date')) ?></span><input type="date" name="occurred_on" value="<?= e($today) ?>" required /></label>
        <label><span><?= e(t('flo.mileage')) ?></span>
          <input type="number" name="mileage" min="0" max="5000000" placeholder="<?= (int) $vehicle['mileage'] ?>" />
        </label>
        <label><span><?= e(t('flo.costEuro')) ?></span><input type="text" name="cost" inputmode="decimal" /></label>
        <label class="span-2"><span><?= e(t('common.note')) ?></span><input type="text" name="note" maxlength="500" /></label>
        <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.record')) ?></button></div>
      </form>
      <p class="hint"><?= e(t('flo.odometerNote')) ?></p>
    </div>

    <div class="card">
      <h2><?= e(t('sst.deadlines')) ?></h2>
      <table class="table">
        <tbody>
          <tr>
            <td class="cell-sub"><?= e(t('flo.inspectionLabel')) ?></td>
            <td class="<?= !empty($vehicle['inspection_due']) && $vehicle['inspection_due'] < $today ? 'text-danger cell-strong' : '' ?>">
              <?= e($day($vehicle['inspection_due'])) ?>
            </td>
          </tr>
          <tr>
            <td class="cell-sub"><?= e(t('flo.insurance')) ?></td>
            <td class="<?= !empty($vehicle['insurance_due']) && $vehicle['insurance_due'] < $today ? 'text-danger cell-strong' : '' ?>">
              <?= e($day($vehicle['insurance_due'])) ?>
            </td>
          </tr>
          <tr>
            <td class="cell-sub"><?= e(t('flo.nextService')) ?></td>
            <td class="<?= !empty($vehicle['service_due']) && $vehicle['service_due'] < $today ? 'text-danger cell-strong' : '' ?>">
              <?= e($day($vehicle['service_due'])) ?>
            </td>
          </tr>
          <tr>
            <td class="cell-sub"><?= e(t('common.mileage')) ?></td>
            <td class="cell-strong"><?= e($km((int) $vehicle['mileage'])) ?> km</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('flo.eventCount', ['count' => count($eventList)])) ?></h2>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.date')) ?></th>
            <th><?= e(t('common.nature')) ?></th>
            <th>Km</th>
            <th><?= e(t('common.cost')) ?></th>
            <th><?= e(t('common.note')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($eventList as $event): ?>
            <tr>
              <td class="cell-sub nowrap"><?= e($day($event['occurred_on'])) ?></td>
              <td><span class="tag <?= $event['kind'] === 'Sinistre' ? 'tag-danger' : '' ?>"><?= e($event['kind']) ?></span></td>
              <td class="cell-sub"><?= e($event['mileage'] !== null ? $km((int) $event['mileage']) : '—') ?></td>
              <td><?= e($money($event['cost'] === null ? null : (float) $event['cost'])) ?></td>
              <td class="cell-sub"><?= e($event['note'] !== '' ? $event['note'] : '—') ?></td>
              <td>
                <form method="POST" action="/flotte/evenements/<?= (int) $event['id'] ?>/supprimer" class="inline-form"
                      data-confirm="<?= e(t('flo.confirmRemoveEvent')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.remove')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($eventList === []): ?>
            <tr><td colspan="6" class="cell-sub"><?= e(t('flo.noEvent')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="tab-panel" id="fiche">
  <div class="card">
    <h2><?= e(t('flo.vehicleCard')) ?></h2>
    <form method="POST" action="/flotte/<?= (int) $vehicle['id'] ?>/modifier" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.brand')) ?></span><input type="text" name="brand" maxlength="60" value="<?= e($vehicle['brand']) ?>" /></label>
      <label><span><?= e(t('common.template')) ?></span><input type="text" name="model" maxlength="60" value="<?= e($vehicle['model']) ?>" /></label>
      <label><span><?= e(t('common.type')) ?></span>
        <select name="kind">
          <?php foreach ($kinds as $kind): ?>
            <option value="<?= e($kind) ?>"<?= $vehicle['kind'] === $kind ? ' selected' : '' ?>><?= e($kind) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.status')) ?></span>
        <select name="status">
          <?php foreach ($statuses as $status): ?>
            <option value="<?= e($status) ?>"<?= $vehicle['status'] === $status ? ' selected' : '' ?>><?= e($status) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('flo.inService')) ?></span>
        <input type="date" name="acquired_on" value="<?= e((string) ($vehicle['acquired_on'] ?? '')) ?>" />
      </label>
      <label><span><?= e(t('common.mileage')) ?></span>
        <input type="number" name="mileage" min="0" max="5000000" value="<?= (int) $vehicle['mileage'] ?>" />
      </label>
      <label class="span-2"><span><?= e(t('flo.assignedDriver')) ?></span>
        <select name="assigned_to">
          <option value="">—</option>
          <?php foreach ($employees as $person): ?>
            <option value="<?= (int) $person['id'] ?>"<?= (int) $vehicle['assigned_to'] === (int) $person['id'] ? ' selected' : '' ?>>
              <?= e($who($person)) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('flo.inspectionLabel')) ?></span>
        <input type="date" name="inspection_due" value="<?= e((string) ($vehicle['inspection_due'] ?? '')) ?>" />
      </label>
      <label><span><?= e(t('flo.insurance')) ?></span>
        <input type="date" name="insurance_due" value="<?= e((string) ($vehicle['insurance_due'] ?? '')) ?>" />
      </label>
      <label class="span-2"><span><?= e(t('flo.nextService')) ?></span>
        <input type="date" name="service_due" value="<?= e((string) ($vehicle['service_due'] ?? '')) ?>" />
      </label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
    </form>

    <form method="POST" action="/flotte/<?= (int) $vehicle['id'] ?>/supprimer"
          data-confirm="<?= e(t('flo.confirmDeleteVehicle')) ?>">
      <?= $csrf ?>
      <button type="submit" class="btn btn-danger btn-block"><?= e(t('flo.deleteVehicle')) ?></button>
    </form>
  </div>
</section>
