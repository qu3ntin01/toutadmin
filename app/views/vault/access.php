<!doctype html>
<html lang="<?= e($locale) ?>" dir="<?= e($localeDir) ?>" data-palette="<?= e($palette) ?>">
<head>
<?= \App\Core\View::partial('head', ['title' => $title, 'nonce' => $nonce]) ?>
</head>
<body class="auth-body">
  <header class="auth-top">
    <div class="auth-brand">
      <span class="brand-mark"><?= e($brandInitials) ?></span>
      <span class="brand-text">
        <span class="brand-name"><?= e($companyName) ?></span>
        <span class="brand-panel"><?= e(t('nav.vault')) ?></span>
      </span>
    </div>
    <?= \App\Core\View::partial('theme-toggle') ?>
  </header>

  <main class="auth-main">
    <div class="auth-card">
      <h1><?= e(t('va.title')) ?></h1>
      <p class="auth-sub"><?= e(t('va.intro')) ?></p>

      <?= \App\Core\View::partial('flash', ['flash' => $flash ?? null]) ?>

      <form method="POST" action="/coffre-fort/acces" class="auth-form">
        <?= \App\Core\Csrf::field() ?>
        <label>
          <span><?= e(t('va.formerAddress')) ?></span>
          <input type="email" name="email" required autofocus autocomplete="username" maxlength="254" />
        </label>
        <label>
          <span><?= e(t('va.accessCode')) ?></span>
          <input type="text" name="code" required maxlength="40" autocomplete="one-time-code"
                 placeholder="XXXXX-XXXXX-XXXXX" />
        </label>
        <button type="submit" class="btn btn-primary btn-block"><?= e(t('va.openVault')) ?></button>
      </form>

      <div class="auth-foot">
        <p><?= e(t('va.passwordNote')) ?></p>
        <p><a href="/connexion"><?= e(t('va.backToLogin')) ?></a></p>
      </div>
    </div>
  </main>

  <script src="/js/theme.js" nonce="<?= e($nonce) ?>"></script>
</body>
</html>
