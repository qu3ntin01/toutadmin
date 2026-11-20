<?php
$csrf = \App\Core\Csrf::field();
$fullName = static fn (array $p, string $prefix = ''): string =>
    trim(($p[$prefix . 'first_name'] ?? '') . ' ' . ($p[$prefix . 'last_name'] ?? ''));
?>
<div class="card">
  <div class="org-head">
    <div>
      <span class="org-name"><?= e($ticket['subject']) ?></span>
      <span class="cell-sub">
        <?= e($ticket['category']) ?> · <?= e($ticket['origin']) ?> · <?= e($fullName($ticket, 'requester_')) ?>
        <?php if (!empty($ticket['partner_name'])): ?> · <?= e($ticket['partner_name']) ?><?php endif; ?>
      </span>
    </div>
    <div>
      <span class="status <?= in_array($ticket['status'], ['Résolu', 'Clos'], true) ? 'status-on' : 'status-warning' ?>">
        <?= e(st($ticket['status'])) ?>
      </span>
      <?php if ($overdue): ?><span class="tag tag-off"><?= e(t('sup.overdue')) ?></span><?php endif; ?>
    </div>
  </div>
  <p><?= nl2br(e((string) $ticket['body'])) ?></p>
  <p class="cell-sub">
    <?= e(t('tkt.responseDeadline')) ?> : <?= e(\App\Core\Dates::moment((string) $ticket['due_at'])) ?>
    <?php if (!empty($ticket['first_reply_at'])): ?>
      · <?= e(t('tkt.firstReply')) ?> : <?= e(\App\Core\Dates::moment((string) $ticket['first_reply_at'])) ?>
    <?php endif; ?>
  </p>
</div>

<div class="card">
  <h2><?= e(t('tkt.tabExchange')) ?></h2>
  <?php if ($messageList === []): ?>
    <div class="empty-state"><?= e(t('tkt.noReply')) ?></div>
  <?php endif; ?>
  <?php foreach ($messageList as $message): ?>
    <article class="sub-card<?= (int) $message['internal'] === 1 ? ' is-internal' : '' ?>">
      <p class="cell-sub">
        <?= e($fullName($message)) ?> · <?= e(\App\Core\Dates::moment((string) $message['created_at'])) ?>
        <?php if ((int) $message['internal'] === 1): ?><span class="tag"><?= e(t('tkt.internalNote')) ?></span><?php endif; ?>
      </p>
      <p><?= nl2br(e((string) $message['body'])) ?></p>
    </article>
  <?php endforeach; ?>

  <?php if ($resumeOffert || $brouillonOffert): ?>
    <div class="row-actions">
      <?php if ($resumeOffert): ?>
        <form method="POST" action="/support/tickets/<?= (int) $ticket['id'] ?>/resumer" class="inline-form">
          <?= $csrf ?>
          <button type="submit" class="btn btn-outline btn-sm"><?= e(t('ia.summarise')) ?></button>
        </form>
      <?php endif; ?>
      <?php if ($brouillonOffert): ?>
        <form method="POST" action="/support/tickets/<?= (int) $ticket['id'] ?>/brouillon" class="inline-form">
          <?= $csrf ?>
          <button type="submit" class="btn btn-outline btn-sm"><?= e(t('ia.draft')) ?></button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($resumeIa !== null): ?>
    <div class="card assist-card">
      <div class="card-head">
        <h4><?= e(t('ia.summaryTitle')) ?></h4>
        <span class="tag"><?= e(t('ia.proposal')) ?></span>
      </div>
      <ul class="assist-points">
        <?php foreach ($resumeIa['points'] as $point): ?><li><?= e($point) ?></li><?php endforeach; ?>
      </ul>
      <?php if (($resumeIa['attente'] ?? null) !== null): ?>
        <p class="cell-sub"><strong><?= e(t('ia.waitingOn')) ?></strong> <?= e($resumeIa['attente']) ?></p>
      <?php endif; ?>
      <?php if ($resumeIa['manquant'] !== []): ?>
        <p class="cell-sub"><strong><?= e(t('ia.missing')) ?></strong> <?= e(implode(' · ', $resumeIa['manquant'])) ?></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <form method="POST" action="/support/tickets/<?= (int) $ticket['id'] ?>/repondre" class="form-grid">
    <?= $csrf ?>
    <label class="span-2"><span><?= e(t('messages.body')) ?></span>
      <textarea name="body" rows="<?= $brouillonIa !== '' ? 10 : 4 ?>" maxlength="5000" required><?= e($brouillonIa) ?></textarea>
    </label>
    <?php if ($isAgent): ?>
      <label><span><?= e(t('tkt.internalNote')) ?></span><input type="checkbox" name="internal" value="1" /></label>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary"><?= e(t('messages.send')) ?></button>
  </form>
</div>

<?php if ($isAgent): ?>
  <div class="card">
    <h2><?= e(t('sup.handledBy')) ?></h2>
    <div class="grid grid-3">
      <form method="POST" action="/support/tickets/<?= (int) $ticket['id'] ?>/statut" class="inline-form">
        <?= $csrf ?>
        <select name="status">
          <?php foreach ($statuses as $status): ?>
            <option value="<?= e($status) ?>"<?= $ticket['status'] === $status ? ' selected' : '' ?>><?= e(st($status)) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
      </form>

      <form method="POST" action="/support/tickets/<?= (int) $ticket['id'] ?>/priorite" class="inline-form">
        <?= $csrf ?>
        <select name="priority">
          <?php foreach ($priorities as $priority): ?>
            <option value="<?= e($priority) ?>"<?= $ticket['priority'] === $priority ? ' selected' : '' ?>><?= e($priority) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
      </form>

      <?php /* Transférer plutôt que réécrire : une demande mal orientée change
               de file, et ce dont elle parle reste vrai. */ ?>
      <form method="POST" action="/support/tickets/<?= (int) $ticket['id'] ?>/groupe" class="inline-form">
        <?= $csrf ?>
        <select name="group_id">
          <option value=""><?= e(t('sup.grp.noQueue')) ?></option>
          <?php foreach ($groups as $groupe): ?>
            <option value="<?= (int) $groupe['id'] ?>"<?= (int) ($ticket['group_id'] ?? 0) === (int) $groupe['id'] ? ' selected' : '' ?>>
              <?= e((string) $groupe['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm"><?= e(t('sup.grp.transfer')) ?></button>
      </form>

      <form method="POST" action="/support/tickets/<?= (int) $ticket['id'] ?>/affecter" class="inline-form">
        <?= $csrf ?>
        <select name="assignee_id">
          <option value=""><?= e(t('sup.noAssignee')) ?></option>
          <?php foreach ($people as $person): ?>
            <option value="<?= (int) $person['id'] ?>"<?= (int) ($ticket['assignee_id'] ?? 0) === (int) $person['id'] ? ' selected' : '' ?>>
              <?= e($fullName($person)) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
      </form>
    </div>

    <?= \App\Core\View::partial('champs-perso-bloc', [
          'champs' => $champsPerso,
          'action' => '/support/tickets/' . (int) $ticket['id'] . '/champs',
        ]) ?>

    <form method="POST" action="/support/tickets/<?= (int) $ticket['id'] ?>/supprimer"
          data-confirm="<?= e(t('tkt.confirmDelete')) ?>">
      <?= $csrf ?>
      <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
    </form>
  </div>
<?php endif; ?>
