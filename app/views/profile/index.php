<section class="card">
  <h2><?= e(t('profile.photo')) ?></h2>
  <div class="manager-card">
    <span class="avatar avatar-lg">
      <?php if (!empty($member['avatar_file'])): ?>
        <img src="/media/avatars/<?= e($member['avatar_file']) ?>"
             alt="<?= e($member['first_name'] . ' ' . $member['last_name']) ?>" />
      <?php else: ?>
        <?= e(mb_substr($member['first_name'], 0, 1) . mb_substr($member['last_name'], 0, 1)) ?>
      <?php endif; ?>
    </span>
    <div>
      <div class="manager-name"><?= e($member['first_name'] . ' ' . $member['last_name']) ?></div>
      <div class="manager-role"><?= e((string) $member['grade']) ?></div>
    </div>
  </div>

  <form method="POST" action="/mon-profil/photo" enctype="multipart/form-data" class="stack">
    <?= \App\Core\Csrf::field() ?>
    <label>
      <span><?= e(t('profile.photo')) ?></span>
      <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required />
    </label>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><?= e(t('profile.upload')) ?></button>
    </div>
  </form>

  <?php if (!empty($member['avatar_file'])): ?>
    <form method="POST" action="/mon-profil/photo/supprimer" class="form-actions"
          data-confirm="<?= e(t('profile.removePhoto')) ?> ?">
      <?= \App\Core\Csrf::field() ?>
      <button type="submit" class="btn btn-sm btn-danger"><?= e(t('profile.removePhoto')) ?></button>
    </form>
  <?php endif; ?>
  <p class="hint"><?= e(t('profile.photoHelp')) ?></p>
</section>

<form method="POST" action="/mon-profil" class="form-grid">
  <?= \App\Core\Csrf::field() ?>

  <label>
    <span><?= e(t('install.admin.firstName')) ?></span>
    <input type="text" name="first_name" value="<?= e($member['first_name']) ?>" maxlength="80" />
  </label>
  <label>
    <span><?= e(t('install.admin.lastName')) ?></span>
    <input type="text" name="last_name" value="<?= e($member['last_name']) ?>" maxlength="80" />
  </label>
  <label>
    <span><?= e(t('common.phone')) ?></span>
    <input type="tel" name="phone" value="<?= e((string) $member['phone']) ?>" maxlength="40" />
  </label>
  <label>
    <span><?= e(t('common.language')) ?></span>
    <select name="locale">
      <?php foreach (\App\Core\I18n::LOCALES as $item): ?>
        <option value="<?= e($item['code']) ?>"<?= $item['code'] === ($member['locale'] ?: 'fr') ? ' selected' : '' ?>>
          <?= e($item['flag'] . ' ' . $item['label']) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="span-2">
    <span><?= e(t('profile.about')) ?></span>
    <textarea name="bio" rows="4" maxlength="2000" placeholder="<?= e(t('profile.aboutPlaceholder')) ?>"><?= e((string) $member['bio']) ?></textarea>
  </label>

  <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
</form>

<section class="card mt-l">
  <h2><?= e(t('emp.mailbox')) ?></h2>
  <p class="muted"><?= e(t('emp.mailboxAdminNote')) ?></p>
  <dl class="detail-list">
    <div><dt><?= e(t('auth.email')) ?></dt><dd><?= e($member['email']) ?></dd></div>
    <div><dt><?= e(t('emp.imapServer')) ?></dt><dd><?= e((string) ($member['mail_imap_host'] ?: '—')) ?></dd></div>
    <div><dt><?= e(t('emp.smtpServer')) ?></dt><dd><?= e((string) ($member['mail_smtp_host'] ?: '—')) ?></dd></div>
  </dl>
</section>

<section class="card mt-l">
  <h2><?= e(t('profile.password')) ?></h2>
  <p class="muted"><?= e(t('profile.passwordHelp')) ?></p>
  <p><a class="btn btn-sm" href="/mot-de-passe"><?= e(t('profile.password')) ?></a></p>
</section>
