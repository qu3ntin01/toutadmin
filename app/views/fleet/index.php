<?php
$csrf = \App\Core\Csrf::field();
$money = static fn (?float $value): string => $value === null ? '—' : number_format($value, 2, ',', ' ') . ' €';
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso;
$km = static fn (int $value): string => number_format($value, 0, ',', ' ');
$who = static fn (array $row): string => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
?>
<section class="stats-grid">
  <?php foreach ([
      [(string) $summary['active'], t('flo.inFleet')],
      [(string) $summary['overdue'], t('pil.overdueDeadlines')],
      [(string) $summary['unassigned'], t('flo.noDriver')],
      [$money((float) $summary['cost']), t('flo.cost12')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<section class="tab-panel is-active" id="flotte">
  <div class="card">
    <div class="card-head">
      <h2><?= e(t('flo.vehicleCount', ['count' => count($vehicleList)])) ?></h2>
      <a class="btn btn-outline btn-sm" href="/flotte<?= $showDisposed ? '' : '?cedes=1' ?>">
        <?= e($showDisposed ? t('flo.hideDisposed') : t('flo.showDisposed')) ?>
      </a>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('flo.plate')) ?></th>
            <th><?= e(t('flo.vehicle')) ?></th>
            <th><?= e(t('flo.driver')) ?></th>
            <th>Km</th>
            <th><?= e(t('flo.inspection')) ?></th>
            <th><?= e(t('flo.insurance')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($vehicleList as $vehicle): ?>
            <tr>
              <td class="cell-strong nowrap"><a href="/flotte/<?= (int) $vehicle['id'] ?>"><?= e($vehicle['registration']) ?></a></td>
              <td>
                <?= e(trim($vehicle['brand'] . ' ' . $vehicle['model']) ?: '—') ?><br />
                <span class="cell-sub"><?= e($vehicle['kind']) ?></span>
              </td>
              <td class="cell-sub"><?= e($who($vehicle) ?: '—') ?></td>
              <td class="cell-sub nowrap"><?= e($km((int) $vehicle['mileage'])) ?></td>
              <td class="<?= !empty($vehicle['inspection_due']) && $vehicle['inspection_due'] < $today ? 'text-danger' : 'cell-sub' ?> nowrap">
                <?= e($day($vehicle['inspection_due'])) ?>
              </td>
              <td class="<?= !empty($vehicle['insurance_due']) && $vehicle['insurance_due'] < $today ? 'text-danger' : 'cell-sub' ?> nowrap">
                <?= e($day($vehicle['insurance_due'])) ?>
              </td>
              <td>
                <span class="tag <?= $vehicle['status'] === 'En service' ? 'tag-success' : ($vehicle['status'] === 'Cédé' ? '' : 'tag-warning') ?>">
                  <?= e(st($vehicle['status'])) ?>
                </span>
              </td>
              <td><a class="btn btn-outline btn-sm" href="/flotte/<?= (int) $vehicle['id'] ?>"><?= e(t('common.open')) ?></a></td>
            </tr>
          <?php endforeach; ?>
          <?php if ($vehicleList === []): ?>
            <tr><td colspan="8" class="cell-sub"><?= e(t('flo.noVehicle')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section class="tab-panel" id="echeances">
  <div class="card">
    <h2><?= e(t('flo.deadlines60', ['count' => count($deadlines)])) ?></h2>
    <ul class="org-list">
      <?php foreach ($deadlines as $deadline): ?>
        <?php $vehicle = $deadline['vehicle']; ?>
        <li class="org-item">
          <span>
            <a class="cell-strong" href="/flotte/<?= (int) $vehicle['id'] ?>"><?= e($vehicle['registration']) ?></a>
            <span class="tag <?= $deadline['overdue'] ? 'tag-danger' : 'tag-warning' ?>"><?= e($deadline['label']) ?></span>
            <br /><span class="cell-sub">
              <?= e(trim($vehicle['brand'] . ' ' . $vehicle['model'])) ?> ·
              <?= e($deadline['overdue'] ? 'dépassée depuis le ' : 'à faire avant le ') ?><?= e($day($deadline['due'])) ?>
            </span>
          </span>
          <a class="btn btn-outline btn-sm" href="/flotte/<?= (int) $vehicle['id'] ?>"><?= e(t('flo.handle')) ?></a>
        </li>
      <?php endforeach; ?>
      <?php if ($deadlines === []): ?><li class="cell-sub"><?= e(t('flo.nothing60')) ?></li><?php endif; ?>
    </ul>
  </div>
</section>

<section class="tab-panel" id="nouveau">
  <div class="card">
    <h2><?= e(t('flo.addVehicle')) ?></h2>
    <form method="POST" action="/flotte" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('flo.plate')) ?></span>
        <input type="text" name="registration" required maxlength="20" placeholder="AB-123-CD" />
      </label>
      <label><span><?= e(t('common.type')) ?></span>
        <select name="kind">
          <?php foreach ($kinds as $kind): ?><option value="<?= e($kind) ?>"><?= e($kind) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.brand')) ?></span><input type="text" name="brand" maxlength="60" /></label>
      <label><span><?= e(t('common.template')) ?></span><input type="text" name="model" maxlength="60" /></label>
      <label><span><?= e(t('flo.inService')) ?></span><input type="date" name="acquired_on" /></label>
      <label><span><?= e(t('common.mileage')) ?></span><input type="number" name="mileage" min="0" max="5000000" value="0" /></label>
      <label class="span-2"><span><?= e(t('flo.assignedDriver')) ?></span>
        <select name="assigned_to">
          <option value="">—</option>
          <?php foreach ($employees as $person): ?>
            <option value="<?= (int) $person['id'] ?>"><?= e($who($person)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('flo.inspectionDue')) ?></span><input type="date" name="inspection_due" /></label>
      <label><span><?= e(t('flo.insuranceDue')) ?></span><input type="date" name="insurance_due" /></label>
      <label class="span-2"><span><?= e(t('flo.nextService')) ?></span><input type="date" name="service_due" /></label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
    </form>
  </div>
</section>
