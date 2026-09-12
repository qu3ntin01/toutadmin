<?php
$csrf = \App\Core\Csrf::field();
$fullName = static fn (array $p): string => trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
$rows = $box === 'envoyes' ? $sent : $inbox;
?>
<div class="card">
  <div class="org-head">
    <div class="row-actions">
      <a class="btn btn-sm<?= $box === 'reception' ? ' btn-primary' : '' ?>" href="/messagerie">
        <?= e(t('messages.inbox')) ?><?= $unread > 0 ? ' (' . (int) $unread . ')' : '' ?>
      </a>
      <a class="btn btn-sm<?= $box === 'envoyes' ? ' btn-primary' : '' ?>" href="/messagerie?boite=envoyes">
        <?= e(t('messages.sent')) ?>
      </a>
    </div>
  </div>

  <?php if ($rows === []): ?>
    <div class="empty-state"><?= e(t('messages.empty')) ?></div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th><?= e($box === 'envoyes' ? t('messages.to') : t('messages.from')) ?></th>
          <th><?= e(t('common.subject')) ?></th>
          <th><?= e(t('common.date')) ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): ?>
          <tr class="<?= $box === 'reception' && $row['read_at'] === null ? 'is-unread' : '' ?>">
            <td><?= e($fullName($row)) ?></td>
            <td>
              <a href="/messagerie?boite=<?= e($box) ?>&amp;message=<?= (int) $row['id'] ?>"><?= e($row['subject']) ?></a>
            </td>
            <td><span class="cell-sub"><?= e($row['created_at']) ?></span></td>
            <td>
              <form method="POST" action="/messagerie/<?= (int) $row['id'] ?>/supprimer">
                <?= $csrf ?>
                <button type="submit" class="btn btn-sm"><?= e(t('common.delete')) ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php if ($opened !== null): ?>
  <div class="card mt-l">
    <h2><?= e($opened['subject']) ?></h2>
    <p class="cell-sub">
      <?= e(trim($opened['sender_first'] . ' ' . $opened['sender_last'])) ?>
      → <?= e(trim($opened['recipient_first'] . ' ' . $opened['recipient_last'])) ?>
      · <?= e($opened['created_at']) ?>
    </p>
    <p><?= nl2br(e((string) $opened['body'])) ?></p>

    <form method="POST" action="/messagerie" class="form-grid">
      <?= $csrf ?>
      <input type="hidden" name="parent_id" value="<?= (int) $opened['id'] ?>" />
      <input type="hidden" name="recipient_id"
             value="<?= (int) ((int) $opened['sender_id'] === (int) $sessionUser['id'] ? $opened['recipient_id'] : $opened['sender_id']) ?>" />
      <input type="hidden" name="subject" value="<?= e('Re: ' . $opened['subject']) ?>" />
      <label class="span-2"><span><?= e(t('messages.body')) ?></span>
        <textarea name="body" rows="4" maxlength="5000" required></textarea>
      </label>
      <button type="submit" class="btn btn-primary"><?= e(t('messages.send')) ?></button>
    </form>
  </div>
<?php endif; ?>

<div class="card mt-l">
  <h2><?= e(t('messages.compose')) ?></h2>
  <form method="POST" action="/messagerie" class="form-grid">
    <?= $csrf ?>
    <label><span><?= e(t('messages.to')) ?></span>
      <select name="recipient_id" required>
        <option value=""></option>
        <?php foreach ($contacts as $contact): ?>
          <option value="<?= (int) $contact['id'] ?>"><?= e($fullName($contact)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><span><?= e(t('common.subject')) ?></span><input type="text" name="subject" required maxlength="200" /></label>
    <label class="span-2"><span><?= e(t('messages.body')) ?></span>
      <textarea name="body" rows="5" maxlength="5000" required></textarea>
    </label>
    <button type="submit" class="btn btn-primary"><?= e(t('messages.send')) ?></button>
  </form>
</div>

<div class="card mt-l">
  <h2><?= e(t('emp.mailbox')) ?></h2>
  <p class="muted"><?= e(t('emp.mailboxAdminNote')) ?></p>
  <dl class="detail-list">
    <div><dt><?= e(t('auth.email')) ?></dt><dd><?= e((string) ($account['mail_address'] ?: '—')) ?></dd></div>
    <div><dt><?= e(t('emp.imapServer')) ?></dt><dd><?= e((string) ($account['mail_imap_host'] ?: '—')) ?></dd></div>
    <div><dt><?= e(t('emp.smtpServer')) ?></dt><dd><?= e((string) ($account['mail_smtp_host'] ?: '—')) ?></dd></div>
  </dl>
</div>
