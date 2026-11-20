<?php
$csrf = \App\Core\Csrf::field();
$money = static fn (?float $value): string => $value === null ? '—' : number_format($value, 2, ',', ' ');
$fullName = static fn (array $p): string => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
?>
<section class="stats-grid">
  <?php foreach ([
      [$money($cost['gross']), t('payslip.gross')],
      [$money($cost['net']), t('pay.netToPay')],
      [$money($cost['employer']), t('pay.employerCharges')],
      [$money($cost['cost']), t('pay.employerCost')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?> · <?= e($period) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<!-- ------------------------------------------------------------ Barèmes -->
<section class="tab-panel is-active" id="baremes">
  <div class="card">
    <h2><?= e(t('pay.scale')) ?></h2>
    <p class="muted"><?= e(t('pay.disclaimer')) ?></p>

    <form method="POST" action="/paie/plafond" class="inline-form">
      <?= $csrf ?>
      <label><span><?= e(t('pay.socialCeiling')) ?></span>
        <input type="text" name="ceiling" inputmode="decimal" value="<?= e((string) $ceiling) ?>" required />
      </label>
      <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
    </form>
    <p class="muted"><?= e(t('pay.ceilingNote')) ?> <?= e(t('pay.ceilingChanges')) ?></p>

    <table class="table">
      <thead>
        <tr>
          <th><?= e(t('pay.contribution')) ?></th>
          <th><?= e(t('pay.base')) ?></th>
          <th><?= e(t('pay.employeeRate')) ?></th>
          <th><?= e(t('pay.employerRate')) ?></th>
          <th><?= e(t('common.status')) ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rates as $rate): ?>
          <tr>
            <td><?= e($rate['label']) ?></td>
            <td><?= e($rate['base']) ?></td>
            <td><?= e((string) $rate['employee_rate']) ?> %</td>
            <td><?= e((string) $rate['employer_rate']) ?> %</td>
            <td>
              <form method="POST" action="/paie/baremes/<?= (int) $rate['id'] ?>/statut" class="inline-form">
                <?= $csrf ?>
                <button type="submit" class="btn btn-sm">
                  <?= e((int) $rate['active'] === 1 ? t('common.active') : t('common.inactive')) ?>
                </button>
              </form>
            </td>
            <td>
              <form method="POST" action="/paie/baremes/<?= (int) $rate['id'] ?>/supprimer"
                    data-confirm="<?= e(t('pay.confirmDeleteContribution')) ?>">
                <?= $csrf ?>
                <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h2><?= e(t('pay.addContribution')) ?></h2>
    <form method="POST" action="/paie/baremes" class="form-grid">
      <?= $csrf ?>
      <label class="span-2"><span><?= e(t('common.title')) ?></span><input type="text" name="label" required maxlength="120" /></label>
      <label><span><?= e(t('pay.base')) ?></span>
        <select name="base" required>
          <?php foreach ($rateBases as $base): ?>
            <option value="<?= e($base) ?>"><?= e($base) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('pay.order')) ?></span><input type="number" name="sort_order" min="0" max="999" value="90" /></label>
      <label><span><?= e(t('pay.employeeRate')) ?></span><input type="text" name="employee_rate" inputmode="decimal" value="0" /></label>
      <label><span><?= e(t('pay.employerRate')) ?></span><input type="text" name="employer_rate" inputmode="decimal" value="0" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.create')) ?></button>
    </form>
  </div>

  <div class="card">
    <h2><?= e(t('pay.simulator')) ?></h2>
    <form method="GET" action="/paie" class="inline-form">
      <input type="hidden" name="periode" value="<?= e($period) ?>" />
      <label><span><?= e(t('pay.monthlyGross')) ?></span>
        <input type="text" name="brut" inputmode="decimal" value="<?= e($simulationGross === null ? '' : (string) $simulationGross) ?>" required />
      </label>
      <button type="submit" class="btn btn-sm btn-primary"><?= e(t('pay.simulate')) ?></button>
    </form>

    <?php if ($simulation !== null): ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('pay.contribution')) ?></th><th><?= e(t('pay.base')) ?></th>
            <th><?= e(t('pay.employeeShare')) ?></th><th><?= e(t('pay.employerShare')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($simulation['lines'] as $line): ?>
            <tr>
              <td><?= e($line['label']) ?></td>
              <td><?= e($money($line['baseAmount'])) ?></td>
              <td><?= e($money($line['employeeAmount'])) ?></td>
              <td><?= e($money($line['employerAmount'])) ?></td>
            </tr>
          <?php endforeach; ?>
          <tr>
            <td><strong><?= e(t('common.total')) ?></strong></td>
            <td><?= e($money($simulation['gross'])) ?></td>
            <td><strong><?= e($money($simulation['employeeTotal'])) ?></strong></td>
            <td><strong><?= e($money($simulation['employerTotal'])) ?></strong></td>
          </tr>
        </tbody>
      </table>
      <p>
        <strong><?= e(t('pay.estimatedNet')) ?> : <?= e($money($simulation['net'])) ?></strong>
        · <?= e(t('pay.employerCost')) ?> : <?= e($money($simulation['employerCost'])) ?>
      </p>
    <?php endif; ?>
  </div>
</section>

<!-- ----------------------------------------------------------- Salaires -->
<section class="tab-panel" id="salaires">
  <div class="card">
    <h2><?= e(t('pay.grossSalaries')) ?></h2>
    <p class="muted"><?= e(t('pay.grossSalariesNote')) ?></p>
    <?php if ($staff === []): ?>
      <div class="empty-state"><?= e(t('pay.noEmployee')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.member')) ?></th>
            <th><?= e(t('common.contract')) ?></th>
            <th><?= e(t('pay.monthlyGross')) ?></th>
            <th><?= e(t('pay.estimatedNet')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($staff as $employee): ?>
            <tr>
              <td><?= e($fullName($employee)) ?><br /><span class="cell-sub"><?= e((string) $employee['grade']) ?></span></td>
              <td><?= e((string) $employee['contract_type']) ?></td>
              <td>
                <form method="POST" action="/paie/salaires/<?= (int) $employee['id'] ?>" class="inline-form">
                  <?= $csrf ?>
                  <input type="text" name="gross_salary" inputmode="decimal"
                         value="<?= e((string) ((float) $employee['gross_salary'] ?: '')) ?>" />
                  <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
                </form>
              </td>
              <td>
                <?= (float) $employee['gross_salary'] > 0
                      ? e($money(\App\Modules\Payroll::compute((float) $employee['gross_salary'])['net']))
                      : '—' ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<!-- ---------------------------------------------------------- Bulletins -->
<section class="tab-panel" id="bulletins">
  <div class="card">
    <h2><?= e(t('pay.computePayslip')) ?></h2>
    <form method="POST" action="/paie/bulletins" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.member')) ?></span>
        <select name="employee_id" required>
          <option value=""></option>
          <?php foreach ($staff as $employee): ?>
            <option value="<?= (int) $employee['id'] ?>"><?= e($fullName($employee)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.period')) ?></span><input type="month" name="period" value="<?= e($period) ?>" required /></label>
      <label><span><?= e(t('pay.monthlyGross')) ?></span><input type="text" name="gross_salary" inputmode="decimal" required /></label>
      <label><span><?= e(t('common.note')) ?></span><input type="text" name="note" maxlength="300" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('pay.compute')) ?></button>
    </form>
  </div>

  <div class="card">
    <h2><?= e(t('pay.batch')) ?></h2>
    <p class="muted"><?= e(t('pay.batchNote')) ?></p>
    <form method="POST" action="/paie/bulletins/lot" class="inline-form">
      <?= $csrf ?>
      <input type="month" name="period" value="<?= e($period) ?>" required />
      <button type="submit" class="btn btn-primary btn-sm"><?= e(t('pay.generateForAll')) ?></button>
    </form>
  </div>

  <div class="card">
    <h2><?= e(t('pay.tabPayslips')) ?></h2>
    <p class="muted"><?= e(t('pay.payslipsNote')) ?></p>
    <?php if ($payslips === []): ?>
      <div class="empty-state"><?= e(t('pay.noPayslip')) ?></div>
    <?php else: ?>
      <?php
        /*
         * Une table plutôt qu'une carte par bulletin. Un écran de paie sert à
         * comparer : le brut de l'un au brut de l'autre, et le net à ce qu'il
         * était le mois d'avant. Empilés en cartes, les nombres ne s'alignent
         * pas et chaque ligne coûte cent pixels de haut. Le détail des
         * cotisations garde son dépliant, dans une ligne qui suit la sienne.
         *
         * La mention « saisie à la main » ne se répète plus sous chaque
         * bulletin : le sous-titre de la carte la dit déjà une fois, et la
         * colonne du coût employeur porte un tiret là où il n'y a rien à
         * calculer.
         */
      ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th><?= e(t('common.member')) ?></th>
              <th><?= e(t('common.period')) ?></th>
              <th class="num"><?= e(t('payslip.gross')) ?></th>
              <th class="num"><?= e(t('payslip.net')) ?></th>
              <th class="num"><?= e(t('pay.employerCost')) ?></th>
              <th><?= e(t('common.status')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($payslips as $payslip): ?>
              <?php $lines = \App\Modules\Payroll::payslipLines((int) $payslip['id']); ?>
              <tr>
                <td class="cell-strong"><?= e($fullName($payslip)) ?></td>
                <td><?= e($payslip['period']) ?></td>
                <td class="num"><?= e($money((float) $payslip['gross_amount'])) ?></td>
                <td class="num cell-strong"><?= e($money((float) $payslip['net_amount'])) ?></td>
                <td class="num"><?= $lines === [] ? '—' : e($money((float) $payslip['employer_cost'])) ?></td>
                <td><span class="status <?= $payslip['status'] === 'Payée' ? 'status-on' : 'status-warning' ?>"><?= e(st($payslip['status'])) ?></span></td>
              </tr>
              <?php if ($lines !== []): ?>
                <tr class="ligne-depliante">
                  <td colspan="6">
                    <details class="minutes">
                      <summary><?= e(t('pay.contributionDetail', ['count' => count($lines)])) ?></summary>
                      <div class="table-wrap">
                        <table>
                          <thead>
                            <tr>
                              <th><?= e(t('pay.contribution')) ?></th><th class="num"><?= e(t('pay.base')) ?></th>
                              <th class="num"><?= e(t('pay.employeeShare')) ?></th><th class="num"><?= e(t('pay.employerShare')) ?></th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($lines as $line): ?>
                              <tr>
                                <td class="cell-sub"><?= e($line['label']) ?></td>
                                <td class="num"><?= e($money((float) $line['base_amount'])) ?></td>
                                <td class="num"><?= e($money((float) $line['employee_amount'])) ?></td>
                                <td class="num"><?= e($money((float) $line['employer_amount'])) ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    </details>
                  </td>
                </tr>
              <?php endif; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
