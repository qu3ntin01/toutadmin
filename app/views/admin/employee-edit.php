<?php $csrf = \App\Core\Csrf::field(); ?>
<div class="card">
  <h2><?= e(t('emp.profile')) ?></h2>
  <form method="POST" action="/admin/employes/<?= (int) $employee['id'] ?>/modifier" class="form-grid">
    <?= $csrf ?>
    <label><span><?= e(t('common.grade')) ?></span>
      <select name="grade" required>
        <?php foreach ($grades as $grade): ?>
          <option value="<?= e($grade) ?>"<?= $employee['grade'] === $grade ? ' selected' : '' ?>><?= e($grade) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><span><?= e(t('common.contract')) ?></span>
      <select name="contract_type" required>
        <?php foreach ($contractTypes as $type): ?>
          <option value="<?= e($type) ?>"<?= $employee['contract_type'] === $type ? ' selected' : '' ?>><?= e($type) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><span><?= e(t('admin.contractEnd')) ?></span>
      <input type="date" name="contract_end_date" value="<?= e((string) $employee['contract_end_date']) ?>" />
    </label>
    <label><span><?= e(t('emp.dailyRate')) ?></span>
      <input type="text" name="daily_rate" inputmode="decimal" value="<?= e((string) $employee['daily_rate']) ?>" />
    </label>
    <label><span><?= e(t('common.department')) ?></span>
      <select name="department_id">
        <option value=""></option>
        <?php foreach ($departments as $department): ?>
          <option value="<?= (int) $department['id'] ?>"
            <?= (int) ($employee['department_id'] ?? 0) === (int) $department['id'] ? ' selected' : '' ?>>
            <?= e($department['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><span><?= e(t('common.team')) ?></span>
      <select name="team_id">
        <option value=""></option>
        <?php foreach ($teams as $team): ?>
          <option value="<?= (int) $team['id'] ?>"
            <?= (int) ($employee['team_id'] ?? 0) === (int) $team['id'] ? ' selected' : '' ?>>
            <?= e($team['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php /* Les champs que l'entreprise a ajoutés à cette fiche. Ils vivent
             dans le même formulaire que le reste : un second formulaire
             obligerait à enregistrer deux fois, et l'on en oublierait un. */ ?>
    <div class="span-2">
      <?= \App\Core\View::partial('champs-perso', ['champs' => $champsPerso]) ?>
    </div>
    <p class="muted span-2"><?= e(t('emp.contractNote')) ?></p>
    <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
  </form>
</div>

<div class="card">
  <h2><?= e(t('emp.mailbox')) ?></h2>
  <p class="muted"><?= e(t('emp.mailboxAdminNote')) ?></p>
  <form method="POST" action="/admin/employes/<?= (int) $employee['id'] ?>/messagerie" class="form-grid">
    <?= $csrf ?>
    <label class="span-2"><span><?= e(t('auth.email')) ?></span>
      <input type="email" name="mail_address" value="<?= e((string) $employee['mail_address']) ?>" maxlength="254" />
    </label>
    <label><span><?= e(t('emp.imapServer')) ?></span>
      <input type="text" name="mail_imap_host" value="<?= e((string) $employee['mail_imap_host']) ?>" maxlength="200" />
    </label>
    <label><span><?= e(t('emp.imapPort')) ?></span>
      <input type="number" name="mail_imap_port" value="<?= e((string) $employee['mail_imap_port']) ?>" min="1" max="65535" />
    </label>
    <label><span><?= e(t('emp.smtpServer')) ?></span>
      <input type="text" name="mail_smtp_host" value="<?= e((string) $employee['mail_smtp_host']) ?>" maxlength="200" />
    </label>
    <label><span><?= e(t('emp.smtpPort')) ?></span>
      <input type="number" name="mail_smtp_port" value="<?= e((string) $employee['mail_smtp_port']) ?>" min="1" max="65535" />
    </label>
    <label class="span-2"><span><?= e(t('emp.mailPassword')) ?></span>
      <input type="password" name="mail_password" value="" autocomplete="new-password"
             placeholder="<?= e($mailbox['password'] !== '' ? $mailbox['password'] : t('emp.mailPasswordEmpty')) ?>" />
    </label>
    <label class="check"><input type="checkbox" name="mail_imap_secure" value="1"<?= $mailbox['secure'] ? ' checked' : '' ?> />
      <span><?= e(t('emp.mailSecure')) ?></span>
    </label>
    <label class="check"><input type="checkbox" name="mail_sync_enabled" value="1"<?= $mailbox['enabled'] ? ' checked' : '' ?> />
      <span><?= e(t('emp.mailSync')) ?></span>
    </label>
    <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
  </form>

  <p class="muted">
    <strong><?= e(t('emp.mailLastSync')) ?> :</strong>
    <?= e($mailbox['syncedAt'] !== null ? \App\Core\Dates::moment((string) $mailbox['syncedAt']) : t('emp.mailNeverSynced')) ?>
    <?php if (!$mailbox['enabled']): ?> · <span class="tag"><?= e(t('emp.mailSyncOff')) ?></span><?php endif; ?>
  </p>
  <?php if ($mailbox['report'] !== ''): ?>
    <p class="muted"><?= e(st($mailbox['report'])) ?></p>
  <?php endif; ?>

  <div class="row-actions">
    <form method="POST" action="/admin/employes/<?= (int) $employee['id'] ?>/messagerie/essai">
      <?= $csrf ?>
      <button type="submit" class="btn btn-sm"><?= e(t('emp.mailTest')) ?></button>
    </form>
    <form method="POST" action="/admin/employes/<?= (int) $employee['id'] ?>/messagerie/relever">
      <?= $csrf ?>
      <button type="submit" class="btn btn-sm"><?= e(t('emp.mailFetchNow')) ?></button>
    </form>
  </div>

  <p class="muted"><?= e(t('emp.mailboxReadOnly')) ?></p>
  <p class="muted"><?= e(t('emp.mailPrivacyNote')) ?></p>
</div>

<p><a class="btn btn-sm" href="/admin#personnel"><?= e(t('err.backHome')) ?></a></p>
