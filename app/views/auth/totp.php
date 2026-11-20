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

      <?php if (($webauthnOptions ?? null) !== null): ?>
        <script type="application/json" id="webauthn-connexion"><?= str_replace('<', '\\u003c', (string) json_encode($webauthnOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></script>
        <form method="POST" action="/connexion/cle" class="auth-form" data-webauthn="get" data-webauthn-options="webauthn-connexion">
          <?= \App\Core\Csrf::field() ?>
          <input type="hidden" name="credential_id" />
          <input type="hidden" name="client_data" />
          <input type="hidden" name="authenticator_data" />
          <input type="hidden" name="signature" />
          <p class="flash flash-error" data-webauthn-error hidden><?= e(t('wan.unsupported')) ?></p>
          <button type="submit" class="btn btn-primary btn-block"><?= e(t('wan.useKey')) ?></button>
        </form>
      <?php endif; ?>

      <?php if ($totpActif ?? true): ?>
        <form method="POST" action="/connexion/code" class="auth-form">
          <?= \App\Core\Csrf::field() ?>
          <label>
            <span><?= e(t('prf.sixDigitCode')) ?></span>
            <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code"
                   pattern="[0-9A-Za-z\-]{6,11}" required autofocus />
          </label>
          <button type="submit" class="btn btn-primary btn-block"><?= e(t('tot.validate')) ?></button>
        </form>
      <?php endif; ?>
    </div>
  </main>
  <script src="<?= e(asset('/js/theme.js')) ?>" nonce="<?= e($nonce) ?>"></script>
  <script src="<?= e(asset('/js/webauthn.js')) ?>" nonce="<?= e($nonce) ?>"></script>
</body>
</html>
