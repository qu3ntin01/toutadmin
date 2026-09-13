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
      <span class="status <?= in_array($ticket['status'], ['Résolu', 'Clos'], true) ? 'status-on' : 'status-wait' ?>">
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

<div class="card mt-l">
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

  <form method="POST" action="/support/tickets/<?= (int) $ticket['id'] ?>/repondre" class="form-grid">
    <?= $csrf ?>
    <label class="span-2"><span><?= e(t('messages.body')) ?></span>
      <textarea name="body" rows="4" maxlength="5000" required></textarea>
    </label>
    <?php if ($isAgent): ?>
      <label><span><?= e(t('tkt.internalNote')) ?></span><input type="checkbox" name="internal" value="1" /></label>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary"><?= e(t('messages.send')) ?></button>
  </form>
</div>

<?php if ($isAgent): ?>
  <div class="card mt-l">
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

    <form method="POST" action="/support/tickets/<?= (int) $ticket['id'] ?>/supprimer" class="mt-l"
          data-confirm="<?= e(t('tkt.confirmDelete')) ?>">
      <?= $csrf ?>
      <button type="submit" class="btn btn-sm btn-danger"><?= e(t('common.delete')) ?></button>
    </form>
  </div>
<?php endif; ?>
