<?php
$csrf = \App\Core\Csrf::field();
$fullName = static fn (array $p): string => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
$statusClass = static fn (string $status): string => match ($status) {
    'Approuvée' => 'status-on',
    'Refusée' => 'status-off',
    'Annulée' => 'status-off',
    default => 'status-wait',
};
?>
<section class="tab-panel is-active" id="demandes">
  <div class="card">
    <div class="card-head">
      <div>
        <h2><?= e(t('nav.requests')) ?> <span class="muted">(<?= (int) $pendingCount ?>)</span></h2>
        <p class="card-sub"><?= e(t('hr.requestsSub')) ?></p>
      </div>
      <div class="filter-pills">
        <?php
          $pills = [
            [null, t('hr.filterAll')],
            ['En attente', t('status.pending')],
            ['Approuvée', t('hr.filterApproved')],
            ['Refusée', t('hr.filterRefused')],
            ['Annulée', t('hr.filterCancelled')],
          ];
        ?>
        <?php foreach ($pills as [$value, $label]): ?>
          <a href="/rh<?= $value === null ? '' : '?statut=' . rawurlencode($value) ?>#demandes"
             class="tag<?= $statusFilter === $value ? ' tag-accent' : '' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if ($requests === []): ?>
      <div class="empty-state"><?= e(t('hr.noRequestForFilter')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.member')) ?></th>
            <th><?= e(t('common.type')) ?></th>
            <th><?= e(t('common.period')) ?></th>
            <th><?= e(t('common.days')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($requests as $row): ?>
            <tr>
              <td><?= e($fullName($row)) ?><br /><span class="cell-sub"><?= e($row['email']) ?></span></td>
              <td><?= e($row['type']) ?></td>
              <td><?= e(\App\Core\Dates::short((string) $row['start_date'])) ?> → <?= e(\App\Core\Dates::short((string) $row['end_date'])) ?></td>
              <td><?= (int) $row['days'] ?></td>
              <td><span class="status <?= e($statusClass($row['status'])) ?>"><?= e(st($row['status'])) ?></span></td>
              <td class="row-actions">
                <?php if ($row['status'] === 'En attente'): ?>
                  <form method="POST" action="/rh/demandes/<?= (int) $row['id'] ?>/approuver" class="inline-form">
                    <?= $csrf ?>
                    <input type="text" name="note" maxlength="500" placeholder="<?= e(t('common.note')) ?>" />
                    <button type="submit" class="btn btn-sm btn-primary"><?= e(t('hr.approve')) ?></button>
                  </form>
                  <form method="POST" action="/rh/demandes/<?= (int) $row['id'] ?>/refuser" class="inline-form">
                    <?= $csrf ?>
                    <button type="submit" class="btn btn-sm"><?= e(t('hr.refuse')) ?></button>
                  </form>
                <?php elseif ($row['status'] === 'Approuvée'): ?>
                  <form method="POST" action="/rh/demandes/<?= (int) $row['id'] ?>/annuler" class="inline-form"
                        data-confirm="<?= e(t('hr.cancelApproved')) ?>">
                    <?= $csrf ?>
                    <button type="submit" class="btn btn-sm"><?= e(t('common.cancel')) ?></button>
                  </form>
                <?php endif; ?>
                <?php if (!empty($row['review_note'])): ?>
                  <span class="cell-sub"><?= e($row['review_note']) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<section class="tab-panel" id="personnel">
  <div class="card">
    <h2><?= e(t('hr.leaveBalances')) ?></h2>
    <p class="muted"><?= e(t('hr.adjustmentHelp')) ?></p>
    <?php if ($employees === []): ?>
      <div class="empty-state"><?= e(t('hr.noStaffToTrack')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr><th><?= e(t('common.member')) ?></th><th><?= e(t('leave.balance')) ?></th><th><?= e(t('hr.adjustment')) ?></th></tr>
        </thead>
        <tbody>
          <?php foreach ($employees as $employee): ?>
            <tr>
              <td><?= e($fullName($employee)) ?><br /><span class="cell-sub"><?= e((string) $employee['contract_type']) ?></span></td>
              <td><strong><?= e((string) $employee['leave_balance']) ?></strong> <?= e(t('common.days')) ?></td>
              <td>
                <form method="POST" action="/rh/solde/<?= (int) $employee['id'] ?>/ajuster" class="inline-form">
                  <?= $csrf ?>
                  <input type="text" name="amount" inputmode="decimal" placeholder="+2 / -1" required />
                  <input type="text" name="reason" maxlength="300" placeholder="<?= e(t('common.reason')) ?>" />
                  <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<section class="tab-panel" id="paie">
  <div class="card">
    <h2><?= e(t('hr.createPayslip')) ?></h2>
    <form method="POST" action="/rh/paie" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.member')) ?></span>
        <select name="employee_id" required>
          <option value=""></option>
          <?php foreach ($employees as $employee): ?>
            <option value="<?= (int) $employee['id'] ?>"><?= e($fullName($employee)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.period')) ?></span><input type="month" name="period" required /></label>
      <label><span><?= e(t('hr.grossEuro')) ?></span><input type="text" name="gross_amount" inputmode="decimal" required /></label>
      <label><span><?= e(t('hr.netEuro')) ?></span><input type="text" name="net_amount" inputmode="decimal" required /></label>
      <label class="span-2"><span><?= e(t('common.note')) ?></span><input type="text" name="note" maxlength="300" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.create')) ?></button>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('nav.payroll')) ?></h2>
    <?php if ($payslips === []): ?>
      <div class="empty-state"><?= e(t('hr.noPayslipRecorded')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.period')) ?></th>
            <th><?= e(t('common.member')) ?></th>
            <th><?= e(t('hr.grossEuro')) ?></th>
            <th><?= e(t('hr.netEuro')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($payslips as $payslip): ?>
            <tr>
              <td><?= e($payslip['period']) ?></td>
              <td><?= e($fullName($payslip)) ?></td>
              <td><?= e(number_format((float) $payslip['gross_amount'], 2, ',', ' ')) ?> €</td>
              <td><?= e(number_format((float) $payslip['net_amount'], 2, ',', ' ')) ?> €</td>
              <td><span class="status <?= $payslip['status'] === 'Payée' ? 'status-on' : 'status-wait' ?>"><?= e(st($payslip['status'])) ?></span></td>
              <td class="row-actions">
                <?php if ($payslip['status'] !== 'Payée'): ?>
                  <form method="POST" action="/rh/paie/<?= (int) $payslip['id'] ?>/marquer-payee">
                    <?= $csrf ?>
                    <button type="submit" class="btn btn-sm"><?= e(t('hr.markPaid')) ?></button>
                  </form>
                <?php endif; ?>
                <form method="POST" action="/rh/paie/<?= (int) $payslip['id'] ?>/supprimer"
                      data-confirm="<?= e(t('hr.deletePayslip')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<!-- ------------------------------------------------- Rémunération -->
<section class="tab-panel" id="remuneration">
  <div class="card">
    <div class="card-head">
      <div>
        <h3><?= e(t('nav.remuneration')) ?> — freelances</h3>
        <p class="card-sub"><?= e(t('hr.timeNotice')) ?></p>
      </div>
    </div>
    <?php if ($freelancers === []): ?>
      <div class="empty-state"><?= e(t('hr.noFreelance')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.member')) ?></th><th>TJM</th>
            <th><?= e(t('hr.hoursMonth')) ?></th><th><?= e(t('hr.estMonth')) ?></th>
            <th><?= e(t('hr.hoursTotal')) ?></th><th><?= e(t('hr.estTotal')) ?></th><th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($freelancers as $freelance): ?>
            <?php $stats = $freelanceStats[(int) $freelance['id']]; ?>
            <tr>
              <td>
                <div class="cell-strong"><?= e(trim($freelance['first_name'] . ' ' . $freelance['last_name'])) ?></div>
                <?php if ($openEntries[(int) $freelance['id']]): ?>
                  <span class="status status-on"><?= e(t('status.running')) ?></span>
                <?php endif; ?>
              </td>
              <td class="num">
                <?= $freelance['daily_rate'] === null
                    ? '—' : e(number_format((float) $freelance['daily_rate'], 2, ',', ' ')) . ' €' ?>
              </td>
              <td class="num"><?= e(number_format($stats['monthHours'], 1, ',', ' ')) ?> h</td>
              <td class="num cell-strong"><?= e(number_format($stats['monthEstimate'], 2, ',', ' ')) ?> €</td>
              <td class="num"><?= e(number_format($stats['totalHours'], 1, ',', ' ')) ?> h</td>
              <td class="num cell-strong"><?= e(number_format($stats['totalEstimate'], 2, ',', ' ')) ?> €</td>
              <td class="actions">
                <a href="/rh/temps/<?= (int) $freelance['id'] ?>" class="btn btn-sm"><?= e(t('common.detail')) ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <p class="hint"><?= e(t('hr.estimateNotice')) ?></p>
  </div>
</section>

<!-- ---------------------------------------------------- Documents -->
<section class="tab-panel" id="documents">
  <div class="card">
    <h2><?= e(t('hr.catalogue')) ?></h2>
    <p class="muted"><?= e(t('hr.ackHelp')) ?></p>
    <form method="POST" action="/rh/documents" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.title')) ?></span><input type="text" name="title" required maxlength="160" /></label>
      <label><span><?= e(t('common.category')) ?></span>
        <select name="category">
          <option value=""></option>
          <?php foreach ($documentCategories as $category): ?>
            <option value="<?= e($category) ?>"><?= e($category) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('common.description')) ?></span><textarea name="description" rows="3" maxlength="2000"></textarea></label>
      <label><span><?= e(t('admin.loginUrl')) ?></span><input type="url" name="url" maxlength="500" placeholder="https://" /></label>
      <label><span><?= e(t('hr.requireAck')) ?></span><input type="checkbox" name="requires_ack" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.create')) ?></button>
    </form>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('hr.acknowledgements')) ?></h2>
    <?php if ($documents === []): ?>
      <div class="empty-state"><?= e(t('common.none')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.title')) ?></th>
            <th><?= e(t('common.category')) ?></th>
            <th><?= e(t('hr.acknowledgements')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($documents as $document): ?>
            <tr>
              <td>
                <strong><?= e($document['title']) ?></strong>
                <?php if (!empty($document['url'])): ?>
                  <br /><a href="<?= e($document['url']) ?>" rel="noopener"><?= e($document['url']) ?></a>
                <?php endif; ?>
              </td>
              <td><?= e((string) $document['category']) ?></td>
              <td>
                <?= (int) $document['ack_count'] ?>
                <?php if ((int) $document['requires_ack'] === 1): ?>
                  <span class="tag"><?= e(t('hr.requireAck')) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <form method="POST" action="/rh/documents/<?= (int) $document['id'] ?>/supprimer"
                      data-confirm="<?= e(t('hr.deleteDocument')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<!-- --------------------------------------------------- Formations -->
<section class="tab-panel" id="formations">
  <div class="grid grid-2">
    <div class="card">
      <h2><?= e(t('hr.catalogue')) ?></h2>
      <form method="POST" action="/rh/formations" class="form-grid">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('common.title')) ?></span><input type="text" name="title" required maxlength="160" /></label>
        <label><span><?= e(t('common.category')) ?></span><input type="text" name="category" maxlength="80" /></label>
        <label><span><?= e(t('hr.provider')) ?></span><input type="text" name="provider" maxlength="120" /></label>
        <label><span><?= e(t('hr.durationHours')) ?></span><input type="text" name="duration_hours" inputmode="decimal" /></label>
        <label><span><?= e(t('common.cost')) ?></span><input type="text" name="cost" inputmode="decimal" /></label>
        <button type="submit" class="btn btn-primary"><?= e(t('common.add')) ?></button>
      </form>
    </div>

    <div class="card">
      <h2><?= e(t('hr.scheduleSession')) ?></h2>
      <form method="POST" action="/rh/sessions" class="form-grid">
        <?= $csrf ?>
        <label class="span-2"><span><?= e(t('hr.training')) ?></span>
          <select name="training_id" required>
            <option value=""></option>
            <?php foreach ($trainings as $training): ?>
              <option value="<?= (int) $training['id'] ?>"><?= e($training['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><span><?= e(t('leave.from')) ?></span><input type="date" name="start_date" required /></label>
        <label><span><?= e(t('leave.to')) ?></span><input type="date" name="end_date" /></label>
        <label><span><?= e(t('common.place')) ?></span><input type="text" name="location" maxlength="140" /></label>
        <label><span><?= e(t('hr.seatsHelp')) ?></span><input type="number" name="seats" min="0" max="1000" value="0" /></label>
        <button type="submit" class="btn btn-primary"><?= e(t('common.create')) ?></button>
      </form>
    </div>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('hr.sessions')) ?></h2>
    <?php if ($sessions === []): ?>
      <div class="empty-state"><?= e(t('hr.noSession')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('hr.training')) ?></th>
            <th><?= e(t('common.period')) ?></th>
            <th><?= e(t('hr.enrolments')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sessions as $session): ?>
            <tr>
              <td><strong><?= e($session['title']) ?></strong><br /><span class="cell-sub"><?= e((string) $session['location']) ?></span></td>
              <td><?= e(\App\Core\Dates::short((string) $session['start_date'])) ?><?= empty($session['end_date']) ? '' : ' → ' . e(\App\Core\Dates::short((string) $session['end_date'])) ?></td>
              <td>
                <?= (int) $session['taken'] ?><?= (int) $session['seats'] > 0 ? ' / ' . (int) $session['seats'] : '' ?>
                <?php if ((int) $session['pending'] > 0): ?>
                  <span class="tag"><?= (int) $session['pending'] ?> <?= e(t('status.pending')) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <form method="POST" action="/rh/sessions/<?= (int) $session['id'] ?>/statut" class="inline-form">
                  <?= $csrf ?>
                  <select name="status">
                    <?php foreach ($sessionStatuses as $status): ?>
                      <option value="<?= e($status) ?>"<?= $session['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
                </form>
              </td>
              <td>
                <form method="POST" action="/rh/sessions/<?= (int) $session['id'] ?>/supprimer"
                      data-confirm="<?= e(t('hr.deleteSession')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('hr.enrolments')) ?></h2>
    <?php if ($registrations === []): ?>
      <div class="empty-state"><?= e(t('hr.noEnrolment')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('common.member')) ?></th>
            <th><?= e(t('hr.training')) ?></th>
            <th><?= e(t('common.status')) ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($registrations as $registration): ?>
            <tr>
              <td><?= e($fullName($registration)) ?></td>
              <td><?= e($registration['title']) ?><br /><span class="cell-sub"><?= e(\App\Core\Dates::short((string) $registration['start_date'])) ?></span></td>
              <td><span class="status <?= $registration['status'] === 'Inscrite' ? 'status-on' : ($registration['status'] === 'Demandée' ? 'status-wait' : 'status-off') ?>"><?= e(st($registration['status'])) ?></span></td>
              <td class="row-actions">
                <?php if ($registration['status'] === 'Demandée'): ?>
                  <?php foreach (['Inscrite' => t('hr.approve'), 'Refusée' => t('hr.refuse')] as $status => $label): ?>
                    <form method="POST" action="/rh/inscriptions/<?= (int) $registration['id'] ?>/statut" class="inline-form">
                      <?= $csrf ?>
                      <input type="hidden" name="status" value="<?= e($status) ?>" />
                      <button type="submit" class="btn btn-sm"><?= e($label) ?></button>
                    </form>
                  <?php endforeach; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</section>

<!-- --------------------------------------------------- Entretiens -->
<section class="tab-panel" id="entretiens">
  <div class="card">
    <h2><?= e(t('hr.planReview')) ?></h2>
    <form method="POST" action="/rh/entretiens" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.member')) ?></span>
        <select name="employee_id" required>
          <option value=""></option>
          <?php foreach ($employees as $employee): ?>
            <option value="<?= (int) $employee['id'] ?>"><?= e($fullName($employee)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('hr.reviewer')) ?></span>
        <select name="reviewer_id">
          <option value=""></option>
          <?php foreach ($employees as $employee): ?>
            <option value="<?= (int) $employee['id'] ?>"><?= e($fullName($employee)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.period')) ?></span><input type="text" name="period" required maxlength="40" placeholder="2026" /></label>
      <label><span><?= e(t('common.date')) ?></span><input type="date" name="scheduled_on" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('common.create')) ?></button>
    </form>
  </div>

  <?php foreach ($reviews as $review): ?>
    <div class="card mt-l">
      <div class="org-head">
        <div>
          <span class="org-name"><?= e($fullName($review)) ?> — <?= e($review['period']) ?></span>
          <span class="cell-sub">
            <?= e(\App\Core\Dates::short((string) $review['scheduled_on'])) ?>
            <?php if (!empty($review['reviewer_first_name'])): ?>
              · <?= e(trim($review['reviewer_first_name'] . ' ' . $review['reviewer_last_name'])) ?>
            <?php endif; ?>
          </span>
        </div>
        <span class="status <?= $review['status'] === 'Réalisé' ? 'status-on' : ($review['status'] === 'Planifié' ? 'status-wait' : 'status-off') ?>">
          <?= e(st($review['status'])) ?>
        </span>
      </div>

      <?php if ($review['status'] === 'Réalisé'): ?>
        <dl class="detail-list">
          <div><dt><?= e(t('hr.strengths')) ?></dt><dd><?= nl2br(e((string) $review['strengths'])) ?></dd></div>
          <div><dt><?= e(t('hr.improvements')) ?></dt><dd><?= nl2br(e((string) $review['improvements'])) ?></dd></div>
          <div><dt><?= e(t('hr.objectivesLabel')) ?></dt><dd><?= nl2br(e((string) $review['objectives'])) ?></dd></div>
          <?php if ($review['rating'] !== null): ?>
            <div><dt><?= e(t('hr.rating')) ?></dt><dd><?= (int) $review['rating'] ?>/5</dd></div>
          <?php endif; ?>
          <?php if (!empty($review['employee_comment'])): ?>
            <div><dt><?= e(t('hr.memberComment')) ?></dt><dd><?= nl2br(e($review['employee_comment'])) ?></dd></div>
          <?php endif; ?>
        </dl>
      <?php elseif ($review['status'] === 'Planifié'): ?>
        <form method="POST" action="/rh/entretiens/<?= (int) $review['id'] ?>/conclure" class="form-grid">
          <?= $csrf ?>
          <label class="span-2"><span><?= e(t('hr.strengthsLabel')) ?></span><textarea name="strengths" rows="2" maxlength="2000"></textarea></label>
          <label class="span-2"><span><?= e(t('hr.improvementsLabel')) ?></span><textarea name="improvements" rows="2" maxlength="2000"></textarea></label>
          <label class="span-2"><span><?= e(t('hr.objectivesLabel')) ?></span><textarea name="objectives" rows="2" maxlength="2000"></textarea></label>
          <label><span><?= e(t('hr.rating')) ?></span><input type="number" name="rating" min="1" max="5" /></label>
          <button type="submit" class="btn btn-primary"><?= e(t('hr.concludeReview')) ?></button>
        </form>
      <?php endif; ?>

      <div class="row-actions">
        <?php if ($review['status'] === 'Planifié'): ?>
          <form method="POST" action="/rh/entretiens/<?= (int) $review['id'] ?>/annuler">
            <?= $csrf ?>
            <button type="submit" class="btn btn-sm"><?= e(t('common.cancel')) ?></button>
          </form>
        <?php endif; ?>
        <form method="POST" action="/rh/entretiens/<?= (int) $review['id'] ?>/supprimer"
              data-confirm="<?= e(t('hr.deleteReview')) ?>">
          <?= $csrf ?>
          <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
</section>

<!-- ---------- Comité social et économique ---------- -->
<section class="tab-panel" id="cse">
  <div class="grid-2">
    <div class="card">
      <h2><?= e(t('cse.addElected')) ?></h2>
      <form method="POST" action="/rh/cse/mandats" class="stack">
        <?= $csrf ?>
        <label>
          <span><?= e(t('common.member')) ?></span>
          <select name="user_id" required>
            <option value=""><?= e(t('common.none')) ?></option>
            <?php foreach ($cseEligible as $employee): ?>
              <option value="<?= (int) $employee['id'] ?>">
                <?= e(trim($employee['first_name'] . ' ' . $employee['last_name'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          <span><?= e(t('cse.mandate')) ?></span>
          <select name="mandate_role" required>
            <?php foreach ($cseMandateRoles as $role): ?><option value="<?= e($role) ?>"><?= e($role) ?></option><?php endforeach; ?>
          </select>
        </label>
        <div class="form-grid">
          <label><span><?= e(t('agenda.from')) ?></span><input type="date" name="started_on" value="<?= e($today) ?>" required /></label>
          <label>
            <span><?= e(t('agenda.to')) ?> <span class="cell-sub">(<?= e(t('common.optional')) ?>)</span></span>
            <input type="date" name="ends_on" />
          </label>
        </div>
        <button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button>
        <p class="hint"><?= e(t('hr.mandateNotice')) ?></p>
      </form>
    </div>

    <div class="card">
      <h2><?= e(t('cse.elected')) ?> <span class="muted">(<?= count($cseMandates) ?>)</span></h2>
      <?php if ($cseMandates === []): ?>
        <div class="empty-state"><?= e(t('cse.noElected')) ?></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th><?= e(t('common.member')) ?></th>
                <th><?= e(t('cse.mandate')) ?></th>
                <th><?= e(t('common.period')) ?></th>
                <th class="actions"><?= e(t('common.actions')) ?></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($cseMandates as $member): ?>
                <tr>
                  <td>
                    <strong><?= e(trim($member['first_name'] . ' ' . $member['last_name'])) ?></strong>
                    <div class="cell-sub"><?= e($member['email']) ?></div>
                  </td>
                  <td><span class="tag"><?= e(\App\Core\Dates::short((string) $member['mandate_role'])) ?></span></td>
                  <td>
                    <?= e(\App\Core\Dates::short((string) $member['started_on'])) ?><?= !empty($member['ends_on']) ? ' → ' . e(\App\Core\Dates::short((string) $member['ends_on'])) : '' ?>
                  </td>
                  <td class="actions">
                    <form method="POST" action="/rh/cse/mandats/<?= (int) $member['user_id'] ?>/retirer"
                          data-confirm="<?= e(t('hr.removeMandate')) ?>">
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

  <div class="grid-2 mt-l">
    <div class="card">
      <h2><?= e(t('cse.newElection')) ?></h2>
      <form method="POST" action="/rh/cse/elections" class="stack">
        <?= $csrf ?>
        <label>
          <span><?= e(t('agenda.eventTitle')) ?></span>
          <input type="text" name="title" maxlength="160" placeholder="<?= e(t('hr.electionExample')) ?>" required />
        </label>
        <div class="form-grid">
          <label><span><?= e(t('cse.seats')) ?></span><input type="number" name="seats" min="1" max="100" value="4" required /></label>
          <label><span><?= e(t('hr.applicationsClose')) ?></span><input type="date" name="candidacy_deadline" /></label>
        </div>
        <div class="form-grid">
          <label><span><?= e(t('hr.voteOpens')) ?></span><input type="date" name="vote_start" /></label>
          <label><span><?= e(t('hr.voteCloses')) ?></span><input type="date" name="vote_end" /></label>
        </div>
        <label><span><?= e(t('common.description')) ?></span><textarea name="description" rows="3" maxlength="2000"></textarea></label>
        <button type="submit" class="btn btn-primary btn-block"><?= e(t('common.create')) ?></button>
        <p class="hint"><?= e(t('hr.electionHelp')) ?></p>
      </form>
    </div>

    <div class="card">
      <h2><?= e(t('cse.newMeeting')) ?></h2>
      <form method="POST" action="/rh/cse/reunions" class="stack">
        <?= $csrf ?>
        <label>
          <span><?= e(t('agenda.eventTitle')) ?></span>
          <input type="text" name="title" maxlength="160" placeholder="<?= e(t('hr.meetingExample')) ?>" required />
        </label>
        <div class="form-grid">
          <label><span><?= e(t('common.date')) ?></span><input type="date" name="meeting_date" required /></label>
          <label><span><?= e(t('timer.start_label')) ?></span><input type="time" name="meeting_time" /></label>
        </div>
        <label><span><?= e(t('agenda.location')) ?></span><input type="text" name="location" maxlength="140" /></label>
        <label><span><?= e(t('cse.agenda')) ?></span><textarea name="agenda" rows="3" maxlength="4000"></textarea></label>
        <button type="submit" class="btn btn-primary btn-block"><?= e(t('common.create')) ?></button>
        <p class="hint"><?= e(t('hr.meetingHelp')) ?></p>
      </form>
    </div>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('cse.elections')) ?> <span class="muted">(<?= count($cseElections) ?>)</span></h2>
    <?php if ($cseElections === []): ?>
      <div class="empty-state"><?= e(t('cse.noElection')) ?></div>
    <?php else: ?>
      <ul class="meeting-list">
        <?php foreach ($cseElections as $election): ?>
          <?php
          $electionId = (int) $election['id'];
          $candidacies = $cseCandidacies[$electionId] ?? [];
          $turnout = $cseTurnout[$electionId];
          $results = $cseResults[$electionId] ?? [];
          ?>
          <li class="meeting-item">
            <div class="meeting-head">
              <span class="meeting-title"><?= e($election['title']) ?></span>
              <span class="status <?= $election['status'] === 'Vote' ? 'status-on' : ($election['status'] === 'Clôturée' ? 'status-off' : 'status-warning') ?>">
                <?= e(st($election['status'])) ?>
              </span>
            </div>
            <p class="cell-sub">
              <?= e(t('cse.seats')) ?> : <?= (int) $election['seats'] ?> ·
              <?= e(t('cse.turnout')) ?> : <?= e((string) $turnout['rate']) ?> %
              (<?= (int) $turnout['voters'] ?>/<?= (int) $turnout['electorate'] ?>)
            </p>

            <?php if ($election['status'] !== 'Clôturée'): ?>
              <div class="row-actions">
                <?php if ($election['status'] === 'Candidatures'): ?>
                  <form method="POST" action="/rh/cse/elections/<?= $electionId ?>/statut" class="inline-form">
                    <?= $csrf ?>
                    <input type="hidden" name="status" value="Vote" />
                    <button type="submit" class="btn btn-primary btn-sm"><?= e(t('cse.openVote')) ?></button>
                  </form>
                <?php endif; ?>
                <form method="POST" action="/rh/cse/elections/<?= $electionId ?>/statut" class="inline-form"
                      data-confirm="<?= e(t('hr.closeBallot')) ?>">
                  <?= $csrf ?>
                  <input type="hidden" name="status" value="Clôturée" />
                  <button type="submit" class="btn btn-outline btn-sm"><?= e(t('cse.closeElection')) ?></button>
                </form>
                <form method="POST" action="/rh/cse/elections/<?= $electionId ?>/supprimer" class="inline-form"
                      data-confirm="<?= e(t('hr.deleteElection')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.delete')) ?></button>
                </form>
              </div>
            <?php endif; ?>

            <h3 class="section-label"><?= e(t('cse.candidates')) ?> <span class="muted">(<?= count($candidacies) ?>)</span></h3>
            <?php if ($candidacies === []): ?>
              <div class="empty-state"><?= e(t('cse.noCandidates')) ?></div>
            <?php else: ?>
              <div class="table-wrap">
                <table class="table">
                  <thead>
                    <tr>
                      <th><?= e(t('common.member')) ?></th>
                      <th><?= e(t('cse.statement')) ?></th>
                      <th><?= e(t('common.status')) ?></th>
                      <?php if ($election['status'] === 'Clôturée'): ?><th><?= e(t('cse.votes')) ?></th><?php endif; ?>
                      <th class="actions"><?= e(t('common.actions')) ?></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($candidacies as $candidate): ?>
                      <tr>
                        <td>
                          <strong><?= e(trim($candidate['first_name'] . ' ' . $candidate['last_name'])) ?></strong>
                          <div class="cell-sub"><?= e((string) $candidate['grade']) ?></div>
                        </td>
                        <td class="cell-sub"><?= e($candidate['statement'] !== '' ? $candidate['statement'] : '—') ?></td>
                        <td>
                          <span class="status <?= $candidate['status'] === 'Validée' ? 'status-on' : ($candidate['status'] === 'Refusée' ? 'status-danger' : 'status-warning') ?>">
                            <?= e(st($candidate['status'])) ?>
                          </span>
                        </td>
                        <?php if ($election['status'] === 'Clôturée'): ?>
                          <?php
                          $votes = 0;
                          foreach ($results as $row) {
                              if ((int) $row['id'] === (int) $candidate['id']) {
                                  $votes = (int) $row['votes'];
                              }
                          }
                          ?>
                          <td><strong><?= $votes ?></strong></td>
                        <?php endif; ?>
                        <td class="actions">
                          <?php if ($election['status'] === 'Candidatures'): ?>
                            <form method="POST" action="/rh/cse/candidatures/<?= (int) $candidate['id'] ?>/statut" class="inline-form">
                              <?= $csrf ?>
                              <input type="hidden" name="status" value="Validée" />
                              <button type="submit" class="btn btn-primary btn-sm"><?= e(t('hr.approve')) ?></button>
                            </form>
                            <form method="POST" action="/rh/cse/candidatures/<?= (int) $candidate['id'] ?>/statut" class="inline-form">
                              <?= $csrf ?>
                              <input type="hidden" name="status" value="Refusée" />
                              <button type="submit" class="btn btn-outline btn-sm"><?= e(t('hr.refuse')) ?></button>
                            </form>
                          <?php else: ?>
                            <span class="cell-sub">—</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('cse.meetings')) ?> <span class="muted">(<?= count($cseMeetings) ?>)</span></h2>
    <?php if ($cseMeetings === []): ?>
      <div class="empty-state"><?= e(t('cse.noMeetings')) ?></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('common.date')) ?></th>
              <th><?= e(t('agenda.eventTitle')) ?></th>
              <th><?= e(t('agenda.location')) ?></th>
              <th><?= e(t('cse.minutes')) ?></th>
              <th class="actions"><?= e(t('common.actions')) ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($cseMeetings as $meeting): ?>
              <tr>
                <td>
                  <strong><?= e(\App\Core\Dates::short((string) $meeting['meeting_date'])) ?></strong>
                  <?php if ($meeting['meeting_time'] !== ''): ?><div class="cell-sub"><?= e($meeting['meeting_time']) ?></div><?php endif; ?>
                </td>
                <td><?= e($meeting['title']) ?></td>
                <td><?= e($meeting['location'] !== '' ? $meeting['location'] : '—') ?></td>
                <td>
                  <span class="status <?= (int) $meeting['minutes_published'] === 1 ? 'status-on' : 'status-warning' ?>">
                    <?= e((int) $meeting['minutes_published'] === 1 ? t('cse.published') : t('cse.draft')) ?>
                  </span>
                </td>
                <td class="actions">
                  <form method="POST" action="/rh/cse/reunions/<?= (int) $meeting['id'] ?>/supprimer"
                        data-confirm="<?= e(t('hr.deleteMeeting')) ?>">
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
</section>

<!-- ---------- Recrutement ---------- -->
<section class="tab-panel" id="recrutement">
  <div class="grid-2">
    <div class="stack">
      <div class="card">
        <h2><?= e(t('hr.openPosition')) ?></h2>
        <form method="POST" action="/rh/postes" class="stack">
          <?= $csrf ?>
          <label><span><?= e(t('common.title')) ?></span><input type="text" name="title" maxlength="160" required /></label>
          <div class="form-grid">
            <label><span><?= e(t('common.department')) ?></span>
              <select name="department_id">
                <option value="">—</option>
                <?php foreach ($departments as $department): ?>
                  <option value="<?= (int) $department['id'] ?>"><?= e($department['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label><span><?= e(t('common.team')) ?></span>
              <select name="team_id">
                <option value="">—</option>
                <?php foreach ($teams as $team): ?>
                  <option value="<?= (int) $team['id'] ?>"><?= e($team['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <label><span><?= e(t('common.contract')) ?></span>
            <select name="contract_type">
              <option value="">—</option>
              <?php foreach ($contractTypes as $contract): ?>
                <option value="<?= e($contract) ?>"><?= e($contract) ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label><span><?= e(t('common.description')) ?></span><textarea name="description" rows="3" maxlength="4000"></textarea></label>
          <button type="submit" class="btn btn-primary btn-block"><?= e(t('common.create')) ?></button>
        </form>
      </div>

      <?php if ($openings !== []): ?>
        <div class="card mt-l">
          <h2><?= e(t('hr.addApplication')) ?></h2>
          <form method="POST" action="/rh/candidats" class="stack">
            <?= $csrf ?>
            <label><span><?= e(t('hr.position')) ?></span>
              <select name="opening_id" required>
                <?php foreach ($openings as $opening): ?>
                  <option value="<?= (int) $opening['id'] ?>"><?= e($opening['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <div class="form-grid">
              <label><span><?= e(t('common.firstName')) ?></span><input type="text" name="first_name" maxlength="100" required /></label>
              <label><span><?= e(t('common.name')) ?></span><input type="text" name="last_name" maxlength="100" required /></label>
              <label><span><?= e(t('common.email')) ?></span><input type="email" name="email" maxlength="254" /></label>
              <label><span><?= e(t('common.source')) ?></span>
                <input type="text" name="source" maxlength="80" placeholder="Ex : cooptation" />
              </label>
            </div>
            <button type="submit" class="btn btn-primary btn-block"><?= e(t('common.create')) ?></button>
            <p class="hint"><?= e(t('hr.cvLater')) ?></p>
          </form>
        </div>
      <?php endif; ?>

      <!-- ---------- CVthèque ---------- -->
      <div class="card mt-l" id="cvtheque">
        <h2><?= e(t('hr.cvLibrary')) ?></h2>
        <p class="muted"><?= e(t('hr.cvSearchHelp')) ?></p>
        <form method="GET" action="/rh" class="search-bar">
          <input type="search" name="cv" value="<?= e($cvQuery) ?>" placeholder="Ex : automatisme habilitation" />
          <button type="submit" class="btn"><?= e(t('common.search')) ?></button>
        </form>

        <?php if ($cvQuery !== ''): ?>
          <?php if ($cvResults === []): ?>
            <div class="empty-state"><?= e(t('hr.noCvMatches')) ?></div>
          <?php else: ?>
            <ul class="person-list">
              <?php foreach ($cvResults as $result): ?>
                <li class="person-row">
                  <span class="person-body">
                    <span class="person-name"><?= e(trim($result['first_name'] . ' ' . $result['last_name'])) ?></span>
                    <span class="cell-sub">
                      <?= e($result['opening_title']) ?> · <?= e(st($result['stage'])) ?>
                      <?= $result['ats_score'] !== null ? ' · score ' . (int) $result['ats_score'] : '' ?>
                    </span>
                    <span class="cell-sub">
                      <?php foreach ($result['hits'] as $hit): ?>
                        <span class="tag <?= $result['matchedAll'] ? 'tag-accent' : '' ?>"><?= e($hit) ?></span>
                      <?php endforeach; ?>
                    </span>
                  </span>
                  <a href="/rh/candidats/<?= (int) $result['id'] ?>/cv" class="btn btn-outline btn-sm">CV</a>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <h2><?= e(t('erp.recruitment')) ?> <span class="muted">(<?= count($openings) ?>)</span></h2>
      <?php if ($openings === []): ?>
        <div class="empty-state"><?= e(t('erp.noOpening')) ?></div>
      <?php else: ?>
        <ul class="meeting-list">
          <?php foreach ($openings as $opening): ?>
            <?php
            $openingId = (int) $opening['id'];
            $applicants = $candidatesByOpening[$openingId] ?? [];
            $criteria = $criteriaByOpening[$openingId] ?? [];
            $shortlisted = count(array_filter($applicants, static fn (array $c): bool => $c['ats']['shortlisted']));
            ?>
            <li class="meeting-item">
              <div class="meeting-head">
                <span class="meeting-title"><?= e($opening['title']) ?></span>
                <span class="status <?= $opening['status'] === 'Pourvu' ? 'status-on' : ($opening['status'] === 'Annulé' ? 'status-danger' : 'status-warning') ?>">
                  <?= e(st($opening['status'])) ?>
                </span>
              </div>
              <p class="cell-sub">
                <?= e(implode(' · ', array_filter([
                    $opening['department_name'], $opening['team_name'], $opening['contract_type'],
                ])) ?: '—') ?>
                · <?= e(t('hr.applicationsRetained', ['total' => count($applicants), 'kept' => $shortlisted])) ?>
              </p>

              <div class="row-actions">
                <form method="POST" action="/rh/postes/<?= $openingId ?>/statut" class="inline-form">
                  <?= $csrf ?>
                  <select name="status" class="input-sm">
                    <?php foreach ($openingStatuses as $status): ?>
                      <option value="<?= e($status) ?>"<?= $opening['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
                </form>
                <form method="POST" action="/rh/postes/<?= $openingId ?>/supprimer" class="inline-form"
                      data-confirm="<?= e(t('hr.deletePosition')) ?>">
                  <?= $csrf ?>
                  <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.delete')) ?></button>
                </form>
              </div>

              <!-- Réglages et critères ATS -->
              <details class="minutes">
                <summary>
                  <?= e(t('hr.atsSummary', ['count' => count($criteria), 'threshold' => (int) $opening['ats_threshold']])) ?>
                </summary>

                <form method="POST" action="/rh/postes/<?= $openingId ?>/ats" class="inline-form">
                  <?= $csrf ?>
                  <label class="inline-field">
                    <span class="cell-sub"><?= e(t('hr.threshold')) ?></span>
                    <input type="number" name="ats_threshold" class="input-sm" min="0" max="100"
                           value="<?= (int) $opening['ats_threshold'] ?>" />
                  </label>
                  <label class="inline-field">
                    <span class="cell-sub"><?= e(t('hr.minExperience')) ?></span>
                    <input type="text" name="min_experience" class="input-sm" inputmode="decimal"
                           value="<?= e((string) $opening['min_experience']) ?>" />
                  </label>
                  <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
                </form>

                <?php if ($criteria !== []): ?>
                  <div class="table-wrap">
                    <table class="table">
                      <thead>
                        <tr>
                          <th><?= e(t('hr.criterion')) ?></th>
                          <th><?= e(t('hr.synonyms')) ?></th>
                          <th><?= e(t('common.type')) ?></th>
                          <th><?= e(t('hr.weight')) ?></th>
                          <th class="actions"></th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($criteria as $criterion): ?>
                          <tr>
                            <td class="cell-strong"><?= e($criterion['label']) ?></td>
                            <td class="cell-sub"><?= e($criterion['keywords'] !== '' ? $criterion['keywords'] : '—') ?></td>
                            <td><span class="tag <?= $criterion['kind'] === 'Requis' ? 'tag-accent' : '' ?>"><?= e($criterion['kind']) ?></span></td>
                            <td class="num"><?= (int) $criterion['weight'] ?></td>
                            <td class="actions">
                              <form method="POST" action="/rh/criteres/<?= (int) $criterion['id'] ?>/supprimer">
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

                <form method="POST" action="/rh/postes/<?= $openingId ?>/criteres" class="stack">
                  <?= $csrf ?>
                  <div class="form-grid">
                    <label><span><?= e(t('hr.criterion')) ?></span>
                      <input type="text" name="label" maxlength="120" placeholder="Ex : Automatisme" required />
                    </label>
                    <label>
                      <span><?= e(t('hr.synonyms')) ?> <span class="cell-sub"><?= e(t('hr.commaSeparated')) ?></span></span>
                      <input type="text" name="keywords" maxlength="500" placeholder="API, automate, Siemens" />
                    </label>
                    <label><span><?= e(t('common.type')) ?></span>
                      <select name="kind">
                        <?php foreach ($criterionKinds as $kind): ?><option value="<?= e($kind) ?>"><?= e($kind) ?></option><?php endforeach; ?>
                      </select>
                    </label>
                    <label><span><?= e(t('hr.weightRange', ['max' => $maxWeight])) ?></span>
                      <input type="number" name="weight" min="1" max="<?= (int) $maxWeight ?>" value="1" />
                    </label>
                  </div>
                  <div class="form-actions">
                    <button type="submit" class="btn btn-sm"><?= e(t('hr.addCriterion')) ?></button>
                  </div>
                  <p class="hint"><?= e(t('hr.atsHelp')) ?></p>
                </form>
              </details>

              <!-- Candidatures classées -->
              <?php if ($applicants !== []): ?>
                <h3 class="section-label"><?= e(t('hr.rankedApplications')) ?></h3>
                <ul class="candidate-list">
                  <?php foreach ($applicants as $candidate): ?>
                    <?php $ats = $candidate['ats']; ?>
                    <li class="candidate-row">
                      <div class="meeting-head">
                        <span class="person-name">
                          <?= e(trim($candidate['first_name'] . ' ' . $candidate['last_name'])) ?>
                          <?php if ($ats['shortlisted']): ?><span class="tag tag-accent"><?= e(t('hr.retained')) ?></span><?php endif; ?>
                        </span>
                        <span class="score-badge <?= $ats['shortlisted'] ? 'is-ok' : ($ats['hasCv'] ? 'is-low' : 'is-none') ?>">
                          <?= e($ats['hasCv'] ? $ats['score'] . ' %' : 'sans CV') ?>
                        </span>
                      </div>
                      <p class="cell-sub">
                        <?= e(implode(' · ', array_filter([$candidate['email'], $candidate['source']])) ?: '—') ?>
                        <?= $ats['years'] !== null ? ' · ' . (int) $ats['years'] . ' ans (' . e($ats['yearsSource']) . ')' : '' ?>
                      </p>

                      <?php if ($ats['hasCv']): ?>
                        <div class="meter">
                          <span class="meter-fill <?= $ats['shortlisted'] ? '' : 'is-warn' ?>" data-ratio="<?= (int) $ats['score'] ?>"></span>
                        </div>
                        <p class="cell-sub">
                          <?php foreach ($ats['rows'] as $row): ?>
                            <span class="tag <?= $row['matched'] ? 'tag-accent' : '' ?>"
                                  title="<?= e($row['kind']) ?> · poids <?= (int) $row['weight'] ?><?= $row['matchedOn'] !== null ? ' · trouvé : ' . e($row['matchedOn']) : '' ?>">
                              <?= $row['matched'] ? '✓' : '✕' ?> <?= e($row['label']) ?>
                            </span>
                          <?php endforeach; ?>
                        </p>
                        <?php if ($ats['missingRequired'] !== []): ?>
                          <p class="cell-sub text-danger">
                            <?= e(t('hr.missingRequired', ['list' => implode(', ', $ats['missingRequired'])])) ?>
                          </p>
                        <?php endif; ?>
                        <?php if ($ats['experienceShort']): ?>
                          <p class="cell-sub text-warning">
                            <?= e(t('hr.experienceBelow', ['years' => $opening['min_experience']])) ?>
                          </p>
                        <?php endif; ?>
                      <?php else: ?>
                        <p class="cell-sub"><?= e(t('hr.noCvNotEvaluated')) ?></p>
                      <?php endif; ?>

                      <div class="row-actions">
                        <form method="POST" action="/rh/candidats/<?= (int) $candidate['id'] ?>/cv"
                              enctype="multipart/form-data" class="inline-form">
                          <?= $csrf ?>
                          <input type="file" name="cv" class="input-sm" accept=".pdf,.docx,.txt,.md" required />
                          <button type="submit" class="btn btn-sm">
                            <?= e(!empty($candidate['cv_file']) ? t('hr.replaceCv') : t('hr.uploadCv')) ?>
                          </button>
                        </form>
                        <?php if (!empty($candidate['cv_file'])): ?>
                          <a href="/rh/candidats/<?= (int) $candidate['id'] ?>/cv" class="btn btn-outline btn-sm">
                            <?= e($candidate['cv_name'] !== '' ? $candidate['cv_name'] : 'CV') ?>
                          </a>
                          <form method="POST" action="/rh/candidats/<?= (int) $candidate['id'] ?>/cv/supprimer"
                                class="inline-form" data-confirm="<?= e(t('hr.deleteCv')) ?>">
                            <?= $csrf ?>
                            <button type="submit" class="btn btn-danger btn-sm"><?= e(t('hr.deleteCvButton')) ?></button>
                          </form>
                        <?php endif; ?>
                      </div>

                      <div class="row-actions">
                        <form method="POST" action="/rh/candidats/<?= (int) $candidate['id'] ?>/experience" class="inline-form">
                          <?= $csrf ?>
                          <label class="inline-field">
                            <span class="cell-sub"><?= e(t('hr.experienceYears')) ?></span>
                            <input type="text" name="experience_years" class="input-sm" inputmode="decimal"
                                   value="<?= $candidate['experience_years'] !== null ? e((string) $candidate['experience_years']) : '' ?>" />
                          </label>
                          <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
                        </form>
                        <form method="POST" action="/rh/candidats/<?= (int) $candidate['id'] ?>/etape" class="inline-form">
                          <?= $csrf ?>
                          <select name="stage" class="input-sm">
                            <?php foreach ($candidateStages as $stage): ?>
                              <option value="<?= e($stage) ?>"<?= $candidate['stage'] === $stage ? ' selected' : '' ?>><?= e(st($stage)) ?></option>
                            <?php endforeach; ?>
                          </select>
                          <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
                        </form>
                        <form method="POST" action="/rh/candidats/<?= (int) $candidate['id'] ?>/supprimer"
                              class="inline-form" data-confirm="<?= e(t('hr.deleteApplication')) ?>">
                          <?= $csrf ?>
                          <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.delete')) ?></button>
                        </form>
                      </div>
                    </li>
                  <?php endforeach; ?>
                </ul>
                <p class="hint"><?= e(t('hr.scoreNotice')) ?></p>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</section>
