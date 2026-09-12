<!doctype html>
<html lang="<?= e($locale) ?>" dir="<?= e($localeDir) ?>" data-palette="<?= e($palette) ?>">
<head>
<?= \App\Core\View::partial('head', ['title' => $title, 'nonce' => $nonce]) ?>
</head>
<body class="auth-body">
  <main class="auth-main">
    <div class="auth-card">
      <h1><?= e(t('tot.title')) ?></h1>
      <p class="auth-sub"><?= e(t('tot.enterCode')) ?></p>

      <?= \App\Core\View::partial('flash', ['flash' => $flash ?? null]) ?>

      <form method="POST" action="/connexion/code" class="auth-form">
        <?= \App\Core\Csrf::field() ?>
        <label>
          <span><?= e(t('prf.sixDigitCode')) ?></span>
          <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                 pattern="[0-9A-Za-z\-]{6,11}" required autofocus />
        </label>
        <button type="submit" class="btn btn-primary btn-block"><?= e(t('tot.validate')) ?></button>
      </form>
    </div>
  </main>
  <script src="/js/theme.js" nonce="<?= e($nonce) ?>"></script>
</body>
</html>
