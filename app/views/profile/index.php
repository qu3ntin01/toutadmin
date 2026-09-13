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
  <?php /* Le changement se fait ici, sans quitter la page, comme dans l'autre
           édition ; la route est la même que celle du premier accès, donc la
           politique de mot de passe et la fermeture des sessions s'appliquent
           de la même façon. */ ?>
  <form method="POST" action="/mot-de-passe" class="form-grid">
    <?= \App\Core\Csrf::field() ?>
    <label class="span-2">
      <span><?= e(t('profile.currentPassword')) ?></span>
      <input type="password" name="current" required autocomplete="current-password" />
    </label>
    <label>
      <span><?= e(t('profile.newPassword')) ?></span>
      <input type="password" name="password" required minlength="12" autocomplete="new-password" />
    </label>
    <label>
      <span><?= e(t('profile.confirmPassword')) ?></span>
      <input type="password" name="confirm" required minlength="12" autocomplete="new-password" />
    </label>
    <div class="span-2">
      <button type="submit" class="btn btn-primary btn-block"><?= e(t('profile.password')) ?></button>
    </div>
  </form>
</section>

<section class="card mt-l" id="securite">
  <div class="card-head">
    <h2><?= e(t('prf.twoFactor')) ?></h2>
    <?php if ($twoFactorState['enabled']): ?>
      <span class="tag tag-success"><?= e(t('common.active')) ?></span>
    <?php elseif ($twoFactorRequired): ?>
      <span class="tag tag-danger"><?= e(t('prf.required')) ?></span>
    <?php endif; ?>
  </div>

  <?php if ($recoveryCodes !== null): ?>
    <div class="flash flash-success"><?= e(t('prf.backupCodes')) ?></div>
    <ul class="code-list">
      <?php foreach ($recoveryCodes as $code): ?><li><?= e($code) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($twoFactorState['enabled']): ?>
    <p class="muted">
      <?= e(t('prf.codeAsked')) ?>
      <?= e(t('prf.remainingCodes', ['count' => (string) $twoFactorState['remainingCodes']])) ?>
    </p>
    <form method="POST" action="/mon-profil/2fa/codes" class="form-actions">
      <?= \App\Core\Csrf::field() ?>
      <button type="submit" class="btn btn-outline btn-sm"><?= e(t('prf.regenerate')) ?></button>
    </form>
    <?php if (!$twoFactorRequired): ?>
      <form method="POST" action="/mon-profil/2fa/desactiver" class="form-grid"
            data-confirm="<?= e(t('prf.confirmDisable2fa')) ?>">
        <?= \App\Core\Csrf::field() ?>
        <label class="span-2">
          <span><?= e(t('prf.passwordToConfirm')) ?></span>
          <input type="password" name="current_password" required autocomplete="current-password" />
        </label>
        <div class="span-2">
          <button type="submit" class="btn btn-danger btn-sm"><?= e(t('common.disable')) ?></button>
        </div>
      </form>
    <?php endif; ?>

  <?php elseif ($twoFactorState['pending']): ?>
    <p class="muted"><?= e(t('prf.scanQr')) ?></p>
    <?php if ($twoFactorQr !== null): ?>
      <?= $twoFactorQr ?>
    <?php endif; ?>
    <?php
      // Le libellé porte le secret : on l'échappe, puis on repose la balise
      // <code> à la place du repère — le secret ne traverse jamais le HTML brut.
      $manual = e(t('prf.manualEntry', ['secret' => '@@secret@@']));
      $manual = str_replace('@@secret@@', '<code class="totp-secret">' . e((string) $twoFactorSecret) . '</code>', $manual);
    ?>
    <p class="muted"><?= $manual ?></p>
    <form method="POST" action="/mon-profil/2fa/activer" class="form-grid">
      <?= \App\Core\Csrf::field() ?>
      <label class="span-2">
        <span><?= e(t('prf.sixDigitCode')) ?></span>
        <input type="text" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required />
      </label>
      <div class="span-2">
        <button type="submit" class="btn btn-primary btn-block"><?= e(t('common.enable')) ?></button>
      </div>
    </form>

  <?php else: ?>
    <p class="muted">
      <?= e(t('prf.stolenPassword')) ?>
      <?php if ($twoFactorRequired): ?><strong><?= e(t('prf.roleRequires')) ?></strong><?php endif; ?>
    </p>
    <form method="POST" action="/mon-profil/2fa/preparer">
      <?= \App\Core\Csrf::field() ?>
      <button type="submit" class="btn btn-primary btn-block"><?= e(t('prf.enable')) ?></button>
    </form>
  <?php endif; ?>
</section>

<section class="card mt-l">
  <h2><?= e(t('sec.tabSessions')) ?></h2>
  <p class="muted"><?= e(t('prf.sessionNote')) ?></p>
  <form method="POST" action="/sessions/fermer" data-confirm="<?= e(t('prf.confirmCloseAll')) ?>">
    <?= \App\Core\Csrf::field() ?>
    <button type="submit" class="btn btn-outline btn-block"><?= e(t('prf.closeAll')) ?></button>
  </form>
</section>
