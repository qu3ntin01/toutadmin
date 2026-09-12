<?php
$csrf = \App\Core\Csrf::field();
$day = static fn (?string $iso): string => ($iso === null || $iso === '') ? '—' : $iso;
$who = static fn (array $row): string => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: '—';
?>
<section class="stats-grid">
  <?php foreach ([
      [(string) $stats['presentNow'], t('acc.visitorsInside')],
      [(string) $stats['visitorsToday'], t('acc.visitsToday')],
      [(string) $stats['pendingMail'], t('acc.mailToHand')],
      [(string) $stats['registered'], t('acc.registeredPending')],
  ] as [$value, $label]): ?>
    <div class="stat-card">
      <span class="stat-body">
        <span class="stat-value"><?= e($value) ?></span>
        <span class="stat-label"><?= e($label) ?></span>
      </span>
    </div>
  <?php endforeach; ?>
</section>

<!-- ---------- Visiteurs ---------- -->
<section class="tab-panel is-active" id="visiteurs">
  <div class="card">
    <div class="card-head">
      <h2><?= e(t('acc.inside')) ?></h2>
      <span class="muted"><?= e(t('acc.evacuationList')) ?></span>
    </div>
    <?php if ($presentList === []): ?>
      <div class="empty-state"><?= e(t('acc.noVisitor')) ?></div>
    <?php else: ?>
      <ul class="person-list">
        <?php foreach ($presentList as $visitor): ?>
          <li class="person-row">
            <span class="person-body">
              <span class="person-name">
                <?= e(trim($visitor['first_name'] . ' ' . $visitor['last_name'])) ?><?= $visitor['company'] !== '' ? ' — ' . e($visitor['company']) : '' ?>
              </span>
              <span class="person-role">
                <?= e(t('acc.arrivedAt', ['time' => $visitor['arrived_at']])) ?><?php
                if (!empty($visitor['host_last'])) {
                    echo ' · ' . e(t('acc.receivedBy', ['name' => trim($visitor['host_first'] . ' ' . $visitor['host_last'])]));
                }
                if ($visitor['badge'] !== '') {
                    echo ' · ' . e(t('acc.badgeNumber', ['number' => $visitor['badge']]));
                }
                ?>
              </span>
            </span>
            <form method="POST" action="/accueil/visiteurs/<?= (int) $visitor['id'] ?>/sortie" class="inline-field">
              <?= $csrf ?>
              <input type="time" name="departed_at" value="<?= e($now) ?>" class="input-sm" />
              <button type="submit" class="btn btn-outline btn-sm"><?= e(t('acc.exit')) ?></button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="card mt-l">
    <h2><?= e(t('acc.registerArrival')) ?></h2>
    <form method="POST" action="/accueil/visiteurs" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.firstName')) ?></span><input type="text" name="first_name" maxlength="120" /></label>
      <label><span><?= e(t('common.name')) ?></span><input type="text" name="last_name" required maxlength="120" /></label>
      <label><span><?= e(t('acc.company')) ?></span><input type="text" name="company" maxlength="160" /></label>
      <label><span><?= e(t('acc.host')) ?></span>
        <select name="host_id">
          <option value="">—</option>
          <?php foreach ($employees as $person): ?>
            <option value="<?= (int) $person['id'] ?>"><?= e($who($person)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.day')) ?></span><input type="date" name="visited_on" value="<?= e($today) ?>" /></label>
      <label><span><?= e(t('acc.arrivalTime')) ?></span><input type="time" name="arrived_at" value="<?= e($now) ?>" /></label>
      <label><span><?= e(t('acc.badgeGiven')) ?></span><input type="text" name="badge" maxlength="40" /></label>
      <label><span><?= e(t('common.reason')) ?></span>
        <input type="text" name="purpose" maxlength="300" placeholder="<?= e(t('acc.visitPlaceholder')) ?>" />
      </label>
      <label class="span-2"><span><?= e(t('common.remarks')) ?></span><textarea name="notes" rows="2"></textarea></label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('acc.register')) ?></button></div>
    </form>
  </div>

  <div class="card mt-l">
    <div class="card-head">
      <h2>
        <?= e($filterDay !== null
            ? t('acc.registerOfDay', ['register' => t('common.register'), 'date' => $day($filterDay)])
            : t('common.register')) ?>
      </h2>
      <span class="muted">
        <?= e(t('acc.visitCount', ['count' => count($visitorList)])) ?> ·
        <?= e(t('acc.overThirtyDays', ['count' => $stats['visitorsMonth']])) ?>
      </span>
    </div>
    <form method="GET" action="/accueil" class="form-grid">
      <label><span><?= e(t('acc.filterByDay')) ?></span><input type="date" name="jour" value="<?= e((string) $filterDay) ?>" /></label>
      <div><button type="submit" class="btn btn-outline btn-block"><?= e(t('common.filter')) ?></button></div>
    </form>
    <?php if ($visitorList === []): ?>
      <div class="empty-state"><?= e(t('acc.noVisitRecorded')) ?></div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th><?= e(t('common.day')) ?></th>
              <th><?= e(t('acc.visitor')) ?></th>
              <th><?= e(t('acc.company')) ?></th>
              <th><?= e(t('acc.host')) ?></th>
              <th><?= e(t('acc.arrival')) ?></th>
              <th><?= e(t('acc.departure')) ?></th>
              <th><?= e(t('common.reason')) ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($visitorList as $visitor): ?>
              <tr>
                <td class="cell-sub nowrap"><?= e($day($visitor['visited_on'])) ?></td>
                <td class="cell-strong"><?= e(trim($visitor['first_name'] . ' ' . $visitor['last_name'])) ?></td>
                <td class="cell-sub"><?= e($visitor['company'] !== '' ? $visitor['company'] : '—') ?></td>
                <td class="cell-sub">
                  <?= e(!empty($visitor['host_last']) ? trim($visitor['host_first'] . ' ' . $visitor['host_last']) : '—') ?>
                </td>
                <td class="cell-sub"><?= e($visitor['arrived_at'] !== '' ? $visitor['arrived_at'] : '—') ?></td>
                <td class="cell-sub"><?= e($visitor['departed_at'] !== '' ? $visitor['departed_at'] : '—') ?></td>
                <td class="cell-sub audit-detail"><?= e($visitor['purpose'] !== '' ? $visitor['purpose'] : '—') ?></td>
                <td>
                  <form method="POST" action="/accueil/visiteurs/<?= (int) $visitor['id'] ?>/supprimer" class="inline-form"
                        data-confirm="<?= e(t('acc.confirmRemoveVisit')) ?>">
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

<!-- ---------- Courrier ---------- -->
<section class="tab-panel" id="courrier">
  <div class="card">
    <h2><?= e(t('acc.recordMail')) ?></h2>
    <form method="POST" action="/accueil/courrier" class="form-grid">
      <?= $csrf ?>
      <label><span><?= e(t('common.direction')) ?></span>
        <select name="direction">
          <?php foreach ($mailDirections as $direction): ?><option value="<?= e($direction) ?>"><?= e($direction) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('common.nature')) ?></span>
        <select name="kind">
          <?php foreach ($mailKinds as $kind): ?><option value="<?= e($kind) ?>"><?= e($kind) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label><span><?= e(t('acc.receivedOrSentOn')) ?></span><input type="date" name="logged_on" value="<?= e($today) ?>" /></label>
      <label><span><?= e(t('acc.trackingNumber')) ?></span><input type="text" name="tracking" maxlength="80" /></label>
      <label><span><?= e(t('acc.correspondent')) ?></span>
        <input type="text" name="correspondent" maxlength="200" placeholder="<?= e(t('acc.correspondentPlaceholder')) ?>" />
      </label>
      <label><span><?= e(t('acc.internalRecipient')) ?></span>
        <select name="recipient_id">
          <option value="">—</option>
          <?php foreach ($employees as $person): ?>
            <option value="<?= (int) $person['id'] ?>"><?= e($who($person)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label class="span-2"><span><?= e(t('common.subject')) ?></span><input type="text" name="subject" maxlength="300" /></label>
      <label class="span-2"><span><?= e(t('common.remarks')) ?></span><textarea name="notes" rows="2"></textarea></label>
      <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button></div>
    </form>
  </div>

  <?php foreach ([[t('acc.incomingMail'), $incoming], [t('acc.outgoingMail'), $outgoing]] as [$label, $rows]): ?>
    <div class="card mt-l">
      <div class="card-head">
        <h2><?= e($label) ?></h2>
        <span class="muted"><?= e(t('acc.itemCount', ['count' => count($rows)])) ?></span>
      </div>
      <?php if ($rows === []): ?>
        <div class="empty-state"><?= e(t('acc.nothingRecorded')) ?></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th><?= e(t('common.date')) ?></th>
                <th><?= e(t('common.nature')) ?></th>
                <th><?= e(t('acc.correspondent')) ?></th>
                <th><?= e(t('messages.to')) ?></th>
                <th><?= e(t('common.subject')) ?></th>
                <th><?= e(t('acc.tracking')) ?></th>
                <th><?= e(t('common.state')) ?></th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $item): ?>
                <tr>
                  <td class="cell-sub nowrap"><?= e($day($item['logged_on'])) ?></td>
                  <td class="cell-sub"><?= e($item['kind']) ?></td>
                  <td class="cell-sub"><?= e($item['correspondent'] !== '' ? $item['correspondent'] : '—') ?></td>
                  <td class="cell-sub">
                    <?= e($item['last_name'] !== null ? $who($item) : ($item['recipient_label'] !== '' ? $item['recipient_label'] : '—')) ?>
                  </td>
                  <td class="cell-strong"><?= e($item['subject'] !== '' ? $item['subject'] : '—') ?></td>
                  <td class="cell-sub mono"><?= e($item['tracking'] !== '' ? $item['tracking'] : '—') ?></td>
                  <td>
                    <span class="tag <?= $item['status'] === 'À remettre' ? 'tag-warning' : 'tag-success' ?>"><?= e(st($item['status'])) ?></span>
                    <?php if (!empty($item['handed_on'])): ?><br /><span class="cell-sub"><?= e($day($item['handed_on'])) ?></span><?php endif; ?>
                  </td>
                  <td>
                    <div class="row-actions">
                      <?php if ($item['status'] === 'À remettre'): ?>
                        <form method="POST" action="/accueil/courrier/<?= (int) $item['id'] ?>/remise" class="inline-form">
                          <?= $csrf ?>
                          <button type="submit" class="btn btn-outline btn-sm"><?= e(t('status.handedOver')) ?></button>
                        </form>
                      <?php elseif ($item['status'] === 'Remis'): ?>
                        <form method="POST" action="/accueil/courrier/<?= (int) $item['id'] ?>/archiver" class="inline-form">
                          <?= $csrf ?>
                          <button type="submit" class="btn btn-outline btn-sm"><?= e(t('acc.archive')) ?></button>
                        </form>
                      <?php endif; ?>
                      <form method="POST" action="/accueil/courrier/<?= (int) $item['id'] ?>/supprimer" class="inline-form"
                            data-confirm="<?= e(t('acc.confirmRemoveMail')) ?>">
                        <?= $csrf ?>
                        <button type="submit" class="btn btn-link btn-sm"><?= e(t('common.remove')) ?></button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>
