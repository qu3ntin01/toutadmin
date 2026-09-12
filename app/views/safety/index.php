<?php
$csrf = \App\Core\Csrf::field();
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso;
$fullName = static fn (array $row): string =>
    trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: '—';
$critical = array_values(array_filter($riskList, static fn (array $r): bool => $r['critical']));
?>
<section class="stats-grid">
  <?php foreach ([
      [(string) count($critical), t('sst.risksNeedingAction')],
      [(string) $indicators['accidents'], t('sst.lostTimeAccidents12')],
      [(string) $indicators['frequency'], t('sst.frequencyRate')],
      [(string) $indicators['severity'], t('sst.severityRate')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<!-- ---------- Document unique ---------- -->
<section class="tab-panel is-active" id="risques">
  <div class="card">
    <h2><?= e(t('sst.recordRisk')) ?></h2>
    <form method="POST" action="/sante-securite/risques" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('sst.workUnit')) ?></span>
        <input type="text" name="unit" required maxlength="120" placeholder="<?= e(t('sst.workUnitExample')) ?>" />
      </label>
      <label><span><?= e(t('sst.hazard')) ?></span>
        <input type="text" name="hazard" required maxlength="200" placeholder="<?= e(t('sst.hazardExample')) ?>" />
      </label>
      <label class="span-2"><span><?= e(t('sst.exposure')) ?></span>
        <input type="text" name="exposure" maxlength="300" placeholder="<?= e(t('sst.exposureExample')) ?>" />
      </label>
      <label><span><?= e(t('sst.severity4')) ?></span>
        <select name="severity">
          <?php foreach ($severities as $level): ?>
            <option value="<?= (int) $level ?>"<?= $level === 2 ? ' selected' : '' ?>><?= (int) $level ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('sst.probability4')) ?></span>
        <select name="likelihood">
          <?php foreach ($likelihoods as $level): ?>
            <option value="<?= (int) $level ?>"<?= $level === 2 ? ' selected' : '' ?>><?= (int) $level ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('sst.preventiveMeasures')) ?></span>
        <textarea name="measures" rows="3" placeholder="<?= e(t('sst.measuresExample')) ?>"></textarea>
      </label>
      <label><span><?= e(t('sst.assessedOn')) ?></span><input type="date" name="reviewed_on" value="<?= e($today) ?>" /></label>
      <label><span><?= e(t('gov.nextReview')) ?></span><input type="date" name="next_review" /></label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.record')) ?></button></div>
    </form>
    <p class="hint"><?= e(t('sst.criticalityHelp', ['threshold' => $actionThreshold])) ?></p>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('sst.risksAssessed', ['count' => count($riskList)])) ?></h2>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.unit')) ?></th>
            <th><?= e(t('sst.hazard')) ?></th>
            <th>G</th>
            <th>P</th>
            <th><?= e(t('sst.criticality')) ?></th>
            <th><?= e(t('common.measures')) ?></th>
            <th><?= e(t('common.review')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($riskList as $risk): ?>
            <tr>
              <td class="cell-sub"><?= e($risk['unit']) ?></td>
              <td class="cell-strong">
                <?= e($risk['hazard']) ?>
                <?php if ($risk['exposure'] !== ''): ?><br /><span class="cell-sub"><?= e($risk['exposure']) ?></span><?php endif; ?>
              </td>
              <td><?= (int) $risk['severity'] ?></td>
              <td><?= (int) $risk['likelihood'] ?></td>
              <td><span class="tag <?= $risk['critical'] ? 'tag-danger' : 'tag-success' ?>"><?= (int) $risk['score'] ?></span></td>
              <td class="cell-sub audit-detail"><?= e($risk['measures'] !== '' ? $risk['measures'] : '—') ?></td>
              <td class="cell-sub nowrap"><?= e($day($risk['next_review'])) ?></td>
              <td>
                <form method="POST" action="/sante-securite/risques/<?= (int) $risk['id'] ?>/supprimer" class="inline-form"
                      data-confirm="<?= e(t('sst.deleteRisk')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.remove')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($riskList === []): ?>
            <tr><td colspan="8" class="cell-sub"><?= e(t('sst.emptyDocument')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- ---------- Accidents ---------- -->
<section class="tab-panel" id="accidents">
  <div class="grid-2">
    <div class="card">
      <h2><?= e(t('sst.recordEvent')) ?></h2>
      <form method="POST" action="/sante-securite/accidents" class="form-grid">
        <?= $csrf ?>
        <label><span><?= e(t('sst.occurredOn')) ?></span>
          <input type="date" name="occurred_on" max="<?= e($today) ?>" value="<?= e($today) ?>" required />
        </label>
        <label><span><?= e(t('common.nature')) ?></span>
          <select name="kind">
            <?php foreach ($incidentKinds as $kind): ?><option value="<?= e($kind) ?>"><?= e($kind) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label class="span-2"><span><?= e(t('sst.personConcerned')) ?></span>
          <select name="user_id">
            <option value="">—</option>
            <?php foreach ($employees as $person): ?>
              <option value="<?= (int) $person['id'] ?>"><?= e($fullName($person)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="span-2"><span><?= e(t('common.place')) ?></span><input type="text" name="location" maxlength="160" /></label>
        <label class="span-2"><span><?= e(t('sst.circumstances')) ?></span><textarea name="description" rows="3"></textarea></label>
        <label><span><?= e(t('sst.daysOff')) ?></span><input type="number" name="days_off" min="0" max="3650" value="0" /></label>
        <label><span><?= e(t('sst.declaredOn')) ?></span><input type="date" name="declared_on" /></label>
        <label class="span-2"><span><?= e(t('sst.followUp')) ?></span><textarea name="follow_up" rows="2"></textarea></label>
        <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.record')) ?></button></div>
      </form>
      <p class="hint"><?= e(t('sst.registerNotice')) ?></p>
    </div>

    <div class="card">
      <h2><?= e(t('sst.twelveMonthIndicators')) ?></h2>
      <table class="table">
        <tbody>
          <tr><td class="cell-sub"><?= e(t('common.headcount')) ?></td><td class="cell-strong"><?= (int) $indicators['headcount'] ?></td></tr>
          <tr><td class="cell-sub"><?= e(t('sst.hoursWorked')) ?></td><td><?= e(number_format((float) $indicators['worked'], 0, ',', ' ')) ?></td></tr>
          <tr><td class="cell-sub"><?= e(t('sst.lostTimeAccidents')) ?></td><td><?= (int) $indicators['accidents'] ?></td></tr>
          <tr><td class="cell-sub"><?= e(t('sst.daysLost')) ?></td><td><?= (int) $indicators['daysOff'] ?></td></tr>
          <tr><td class="cell-sub"><?= e(t('sst.frequencyRate')) ?></td><td class="cell-strong"><?= e((string) $indicators['frequency']) ?></td></tr>
          <tr><td class="cell-sub"><?= e(t('sst.severityRate')) ?></td><td class="cell-strong"><?= e((string) $indicators['severity']) ?></td></tr>
        </tbody>
      </table>
      <p class="hint"><?= e(t('sst.ratesHelp')) ?></p>
    </div>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('sst.registerCount', ['count' => count($incidentList)])) ?></h2>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.date')) ?></th>
            <th><?= e(t('common.nature')) ?></th>
            <th><?= e(t('common.person')) ?></th>
            <th><?= e(t('common.place')) ?></th>
            <th><?= e(t('sst.stopped')) ?></th>
            <th><?= e(t('sst.declared')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($incidentList as $incident): ?>
            <tr>
              <td class="cell-sub nowrap"><?= e($day($incident['occurred_on'])) ?></td>
              <td><span class="tag <?= $incident['kind'] === 'Presque-accident' ? '' : 'tag-danger' ?>"><?= e($incident['kind']) ?></span></td>
              <td><?= e($fullName($incident)) ?></td>
              <td class="cell-sub">
                <?= e($incident['location'] !== '' ? $incident['location'] : '—') ?>
                <?php if ($incident['description'] !== ''): ?><br /><span class="cell-sub"><?= e($incident['description']) ?></span><?php endif; ?>
              </td>
              <td class="cell-strong"><?= (int) $incident['days_off'] ?> j</td>
              <td class="cell-sub nowrap"><?= e($day($incident['declared_on'])) ?></td>
              <td>
                <form method="POST" action="/sante-securite/accidents/<?= (int) $incident['id'] ?>/supprimer" class="inline-form"
                      data-confirm="<?= e(t('sst.deleteEntry')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.remove')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($incidentList === []): ?>
            <tr><td colspan="7" class="cell-sub"><?= e(t('sst.noEvent')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- ---------- Protections ---------- -->
<section class="tab-panel" id="protections">
  <div class="grid-2">
    <div class="card">
      <h2><?= e(t('sst.declareEquipment')) ?></h2>
      <form method="POST" action="/sante-securite/protections" class="form-grid">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('common.title')) ?></span>
          <input type="text" name="name" required maxlength="120" placeholder="<?= e(t('sst.equipmentExample')) ?>" />
        </label>
        <label><span><?= e(t('common.category')) ?></span><input type="text" name="category" value="Protection" maxlength="60" /></label>
        <label><span><?= e(t('sst.validityMonths')) ?></span><input type="number" name="validity_months" min="1" max="600" placeholder="60" /></label>
        <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.declare')) ?></button></div>
      </form>
    </div>

    <div class="card">
      <h2><?= e(t('sst.handOverTo')) ?></h2>
      <form method="POST" action="/sante-securite/protections/remettre" class="form-grid">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('common.equipment')) ?></span>
          <select name="ppe_id" required>
            <option value="">—</option>
            <?php foreach ($ppeList as $item): ?>
              <option value="<?= (int) $item['id'] ?>"><?= e($item['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="span-2"><span><?= e(t('common.person')) ?></span>
          <select name="user_id" required>
            <option value="">—</option>
            <?php foreach ($employees as $person): ?>
              <option value="<?= (int) $person['id'] ?>"><?= e($fullName($person)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="span-2"><span><?= e(t('sst.handedOn')) ?></span><input type="date" name="issued_on" value="<?= e($today) ?>" required /></label>
        <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('sst.recordHandOver')) ?></button></div>
      </form>
    </div>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('sst.inCirculation', ['count' => count($ppeGiven)])) ?></h2>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.equipment')) ?></th>
            <th><?= e(t('common.holder')) ?></th>
            <th><?= e(t('sst.handedOn')) ?></th>
            <th><?= e(t('erp.dueDate')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ppeGiven as $given): ?>
            <tr>
              <td class="cell-strong"><?= e($given['name']) ?><br /><span class="cell-sub"><?= e($given['category']) ?></span></td>
              <td><?= e($fullName($given)) ?></td>
              <td class="cell-sub nowrap"><?= e($day($given['issued_on'])) ?></td>
              <td class="<?= $given['expires_on'] !== null && $given['expires_on'] < $today ? 'text-danger' : 'cell-sub' ?> nowrap">
                <?= e($day($given['expires_on'])) ?>
              </td>
              <td>
                <form method="POST" action="/sante-securite/protections/remises/<?= (int) $given['id'] ?>/rendre" class="inline-form">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-outline btn-sm"><?= e(t('sst.returned')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if ($ppeGiven === []): ?>
            <tr><td colspan="5" class="cell-sub"><?= e(t('sst.noneInCirculation')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('hr.catalogue')) ?></h2>
    <ul class="org-list">
      <?php foreach ($ppeList as $item): ?>
        <li class="org-item">
          <span>
            <span class="cell-strong"><?= e($item['name']) ?></span><br />
            <span class="cell-sub">
              <?= e($item['category']) ?>
              <?= $item['validity_months'] !== null
                  ? ' · ' . e(t('sst.monthsValidity', ['count' => (int) $item['validity_months']]))
                  : ' · ' . e(t('sst.noExpiry')) ?>
            </span>
          </span>
          <form method="POST" action="/sante-securite/protections/<?= (int) $item['id'] ?>/supprimer" class="inline-form"
                data-confirm="<?= e(t('sst.deleteEquipment')) ?>">
            <?= $csrf ?>
            <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.delete')) ?></button>
          </form>
        </li>
      <?php endforeach; ?>
      <?php if ($ppeList === []): ?><li class="cell-sub"><?= e(t('sst.noEquipment')) ?></li><?php endif; ?>
    </ul>
  </div>
</section>

<!-- ---------- Visites ---------- -->
<section class="tab-panel" id="visites">
  <div class="grid-2">
    <div class="card">
      <h2><?= e(t('sst.recordVisit')) ?></h2>
      <form method="POST" action="/sante-securite/visites" class="form-grid">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('common.person')) ?></span>
          <select name="user_id" required>
            <option value="">—</option>
            <?php foreach ($employees as $person): ?>
              <option value="<?= (int) $person['id'] ?>"><?= e($fullName($person)) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="span-2"><span><?= e(t('common.type')) ?></span>
          <select name="kind">
            <?php foreach ($visitKinds as $kind): ?><option value="<?= e($kind) ?>"><?= e($kind) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('sst.scheduledOn')) ?></span><input type="date" name="scheduled_on" /></label>
        <label><span><?= e(t('sst.performedOn')) ?></span><input type="date" name="done_on" /></label>
        <label class="span-2"><span><?= e(t('sst.opinion')) ?></span>
          <input type="text" name="verdict" maxlength="200" placeholder="<?= e(t('sst.opinionExample')) ?>" />
        </label>
        <label class="span-2"><span><?= e(t('sst.nextDue')) ?></span><input type="date" name="next_due" /></label>
        <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.record')) ?></button></div>
      </form>
      <p class="hint"><?= e(t('sst.medicalNotice')) ?></p>
    </div>

    <div class="card">
      <h2><?= e(t('sst.visitsTracked', ['count' => count($visitList)])) ?></h2>
      <ul class="org-list">
        <?php foreach ($visitList as $visit): ?>
          <li class="org-item">
            <span>
              <span class="cell-strong"><?= e($fullName($visit)) ?></span><br />
              <span class="cell-sub">
                <?= e($visit['kind']) ?><?= $visit['verdict'] !== '' ? ' · ' . e($visit['verdict']) : '' ?>
                <?= e(t('sst.performed')) ?> <?= e($day($visit['done_on'])) ?>
                · <?= e(t('ges.next')) ?> <?= e($day($visit['next_due'])) ?>
              </span>
            </span>
            <form method="POST" action="/sante-securite/visites/<?= (int) $visit['id'] ?>/supprimer" class="inline-form"
                  data-confirm="<?= e(t('sst.deleteVisit')) ?>">
              <?= $csrf ?>
              <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.remove')) ?></button>
            </form>
          </li>
        <?php endforeach; ?>
        <?php if ($visitList === []): ?><li class="cell-sub"><?= e(t('sst.noVisit')) ?></li><?php endif; ?>
      </ul>
    </div>
  </div>
</section>

<!-- ---------- Échéances ---------- -->
<section class="tab-panel" id="echeances">
  <div class="grid-2">
    <div class="card">
      <h2><?= e(t('sst.visitsDue')) ?></h2>
      <ul class="org-list">
        <?php foreach ($upcoming['visits'] as $visit): ?>
          <li class="org-item">
            <span class="cell-strong"><?= e($fullName($visit)) ?></span>
            <span class="cell-sub"><?= e($visit['kind']) ?> · <?= e($day($visit['next_due'])) ?></span>
          </li>
        <?php endforeach; ?>
        <?php if ($upcoming['visits'] === []): ?><li class="cell-sub"><?= e(t('sst.nothingIn60')) ?></li><?php endif; ?>
      </ul>
    </div>
    <div class="card">
      <h2><?= e(t('sst.protectionsToReplace')) ?></h2>
      <ul class="org-list">
        <?php foreach ($upcoming['ppe'] as $given): ?>
          <li class="org-item">
            <span class="cell-strong"><?= e($given['name']) ?></span>
            <span class="cell-sub"><?= e($fullName($given)) ?> · <?= e($day($given['expires_on'])) ?></span>
          </li>
        <?php endforeach; ?>
        <?php if ($upcoming['ppe'] === []): ?><li class="cell-sub"><?= e(t('sst.nothingIn60')) ?></li><?php endif; ?>
      </ul>
    </div>
    <div class="card">
      <h2><?= e(t('sst.risksToReassess')) ?></h2>
      <ul class="org-list">
        <?php foreach ($upcoming['risks'] as $risk): ?>
          <li class="org-item">
            <span class="cell-strong"><?= e($risk['hazard']) ?></span>
            <span class="cell-sub"><?= e($risk['unit']) ?> · <?= e($day($risk['next_review'])) ?></span>
          </li>
        <?php endforeach; ?>
        <?php if ($upcoming['risks'] === []): ?><li class="cell-sub"><?= e(t('sst.nothingIn60')) ?></li><?php endif; ?>
      </ul>
    </div>
  </div>
</section>
