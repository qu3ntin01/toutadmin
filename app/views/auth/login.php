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
        <span class="brand-panel"><?= e(t('app.portal')) ?></span>
      </span>
    </div>
    <?= \App\Core\View::partial('theme-toggle') ?>
  </header>

  <main class="auth-main">
    <div class="auth-card">
      <h1><?= e(t('auth.title')) ?></h1>
      <p class="auth-sub"><?= e(t('auth.subtitle')) ?></p>

      <?= \App\Core\View::partial('flash', ['flash' => $flash ?? null]) ?>

      <form method="POST" action="/connexion" class="auth-form">
        <?= \App\Core\Csrf::field() ?>
        <label>
          <span><?= e(t('auth.email')) ?></span>
          <input type="email" name="email" placeholder="nom@entreprise.com" required autofocus autocomplete="username" />
        </label>
        <label>
          <span><?= e(t('auth.password')) ?></span>
          <input type="password" name="password" placeholder="••••••••" required autocomplete="current-password" />
        </label>
        <button type="submit" class="btn btn-primary btn-block"><?= e(t('auth.signIn')) ?></button>
      </form>

      <p class="auth-note">
        <?= e(t('lgn.formerEmployee')) ?>
        <a href="/coffre-fort/acces"><?= e(t('lgn.accessVault')) ?></a>.
      </p>

      <div class="auth-foot">
        <p><?= e(t('auth.note')) ?></p>
        <p class="cell-sub"><?= e(t('common.language')) ?></p>
        <?= \App\Core\View::partial('language-picker', ['returnTo' => '/connexion', 'locales' => $locales, 'locale' => $locale]) ?>
      </div>
    </div>
  </main>

  <script src="/js/theme.js" nonce="<?= e($nonce) ?>"></script>
</body>
</html>
