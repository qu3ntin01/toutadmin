<?php
$csrf = \App\Core\Csrf::field();
$fullName = static fn (array $p): string => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
?>
<section class="grid grid-2">
  <article class="card">
    <h2><?= e(t('profile.details')) ?></h2>
    <dl class="detail-list">
      <div><dt><?= e(t('auth.email')) ?></dt><dd><?= e($member['email']) ?></dd></div>
      <?php if (!empty($member['grade'])): ?>
        <div><dt><?= e(t('common.role')) ?></dt><dd><?= e($member['grade']) ?></dd></div>
      <?php endif; ?>
      <?php if ($department !== null): ?>
        <div><dt><?= e(t('common.department')) ?></dt><dd><?= e($department['name']) ?></dd></div>
      <?php endif; ?>
      <?php if ($team !== null): ?>
        <div><dt><?= e(t('common.team')) ?></dt><dd><?= e($team['name']) ?></dd></div>
      <?php endif; ?>
      <?php if ($eligible): ?>
        <div><dt><?= e(t('leave.balance')) ?></dt><dd><strong><?= e((string) $member['leave_balance']) ?></strong> <?= e(t('common.days')) ?></dd></div>
      <?php endif; ?>
    </dl>
    <p><a class="btn btn-sm" href="/mon-profil"><?= e(t('nav.profile')) ?></a></p>
  </article>

  <article class="card">
    <h2><?= e($managers === [] || count($managers) === 1 ? t('home.yourManager') : t('common.managers')) ?></h2>
    <?php if ($managers === []): ?>
      <div class="empty-state"><?= e(t('home.noManager')) ?></div>
    <?php else: ?>
      <ul class="people">
        <?php foreach ($managers as $manager): ?>
          <li>
            <strong><?= e($fullName($manager)) ?></strong>
            <span class="muted"> · <?= e((string) $manager['grade']) ?>
              · <?= e($manager['scope'] === 'team' ? t('common.team') : t('common.department')) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </article>
</section>

<?php if ($eligible): ?>
  <section class="card mt-l">
    <h2><?= e(t('leave.newRequest')) ?></h2>
    <p class="muted"><?= e(t('hr.leaveRule')) ?></p>
    <form method="POST" action="/mon-espace/demandes" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.type')) ?></span>
        <select name="type" required>
          <?php foreach ($requestTypes as $type): ?>
            <option value="<?= e($type) ?>"><?= e($type) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('leave.from')) ?></span><input type="date" name="start_date" required /></label>
      <label><span><?= e(t('leave.to')) ?></span><input type="date" name="end_date" required /></label>
      <label class="span-2"><span><?= e(t('common.reason')) ?></span><input type="text" name="reason" maxlength="500" /></label>
      <button type="submit" class="btn btn-primary"><?= e(t('leave.send')) ?></button>
    </form>
  </section>

  <section class="card mt-l">
    <h2><?= e(t('leave.myRequests')) ?></h2>
    <?php if ($requests === []): ?>
      <div class="empty-state"><?= e(t('leave.noRequests')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
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
              <td>
                <?= e($row['type']) ?>
                <?php if (!empty($row['reason'])): ?><br /><span class="cell-sub"><?= e($row['reason']) ?></span><?php endif; ?>
              </td>
              <td><?= e($row['start_date']) ?> → <?= e($row['end_date']) ?></td>
              <td><?= (int) $row['days'] ?></td>
              <td>
                <span class="status <?= $row['status'] === 'Approuvée' ? 'status-on' : ($row['status'] === 'En attente' ? 'status-wait' : 'status-off') ?>">
                  <?= e($row['status']) ?>
                </span>
                <?php if (!empty($row['review_note'])): ?><br /><span class="cell-sub"><?= e($row['review_note']) ?></span><?php endif; ?>
              </td>
              <td>
                <?php if ($row['status'] === 'En attente'): ?>
                  <form method="POST" action="/mon-espace/demandes/<?= (int) $row['id'] ?>/annuler">
                    <?= $csrf ?>
                    <button type="submit" class="btn btn-sm"><?= e(t('common.cancel')) ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>

  <section class="card mt-l">
    <h2><?= e(t('hr.payslips')) ?></h2>
    <?php if ($payslips === []): ?>
      <div class="empty-state"><?= e(t('hr.noPayslipRecorded')) ?></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr><th><?= e(t('common.period')) ?></th><th><?= e(t('hr.netEuro')) ?></th><th><?= e(t('common.status')) ?></th></tr>
        </thead>
        <tbody>
          <?php foreach ($payslips as $payslip): ?>
            <tr>
              <td><?= e($payslip['period']) ?></td>
              <td><?= e(number_format((float) $payslip['net_amount'], 2, ',', ' ')) ?> €</td>
              <td><?= e($payslip['status']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </section>
<?php endif; ?>

<section class="card mt-l">
  <h2><?= e(t('home.news')) ?></h2>
  <?php if ($news === []): ?>
    <div class="empty-state"><?= e(t('home.noNews')) ?></div>
  <?php else: ?>
    <?php foreach ($news as $item): ?>
      <article class="sub-card">
        <h3><?= e($item['title']) ?></h3>
        <p class="cell-sub">
          <?= e($item['created_at']) ?> ·
          <?= e($item['scope'] === 'company' ? t('home.companyNews') : t('home.teamNews')) ?>
        </p>
        <p><?= nl2br(e((string) $item['body'])) ?></p>
      </article>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<section class="card mt-l">
  <h2><?= e(t('nav.directory')) ?></h2>
  <?php if ($colleagues === []): ?>
    <div class="empty-state"><?= e(t('directory.empty')) ?></div>
  <?php else: ?>
    <ul class="people">
      <?php foreach ($colleagues as $person): ?>
        <li>
          <strong><?= e($fullName($person)) ?></strong>
          <?php if (!empty($person['grade'])): ?><span class="muted"> · <?= e($person['grade']) ?></span><?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <p><a class="btn btn-sm" href="/annuaire"><?= e(t('directory.title')) ?></a></p>
</section>
