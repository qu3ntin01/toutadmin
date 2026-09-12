<!doctype html>
<html lang="<?= e($locale) ?>" dir="<?= e($localeDir) ?>" data-palette="<?= e($palette) ?>">
<head>
<?= \App\Core\View::partial('head', ['title' => $title, 'nonce' => $nonce]) ?>
</head>
<body class="auth-body">
  <main class="auth-main">
    <div class="auth-card">
      <h1><?= e(t('profile.password')) ?></h1>
      <p class="auth-sub"><?= e(t('profile.passwordHelp')) ?></p>

      <?= \App\Core\View::partial('flash', ['flash' => $flash ?? null]) ?>

      <form method="POST" action="/mot-de-passe" class="auth-form">
        <?= \App\Core\Csrf::field() ?>
        <label>
          <span><?= e(t('profile.currentPassword')) ?></span>
          <input type="password" name="current" required autocomplete="current-password" />
        </label>
        <label>
          <span><?= e(t('profile.newPassword')) ?></span>
          <input type="password" name="password" required autocomplete="new-password" minlength="12" />
        </label>
        <label>
          <span><?= e(t('profile.confirmPassword')) ?></span>
          <input type="password" name="confirm" required autocomplete="new-password" minlength="12" />
        </label>
        <button type="submit" class="btn btn-primary btn-block"><?= e(t('common.save')) ?></button>
      </form>
    </div>
  </main>
  <script src="/js/theme.js" nonce="<?= e($nonce) ?>"></script>
</body>
</html>
