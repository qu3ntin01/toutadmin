<!doctype html>
<html lang="fr" dir="ltr">
<head>
<?= \App\Core\View::partial('head', ['title' => $title, 'nonce' => $nonce]) ?>
</head>
<body class="auth-body">
  <main class="auth-main">
    <div class="auth-card">
      <h1>Installation de Toutadmin</h1>
      <p class="auth-sub">Cet écran ne s'affiche qu'une fois : il se ferme dès qu'un compte existe.</p>

      <?= \App\Core\View::partial('flash', ['flash' => $flash ?? null]) ?>

      <table class="table">
        <caption class="sr-only">Vérifications de l'hébergement</caption>
        <tbody>
        <?php foreach ($checks as [$label, $ok, $detail]): ?>
          <tr>
            <td><?= e($label) ?></td>
            <td class="<?= $ok ? 'ok' : 'ko' ?>"><?= $ok ? '✓' : '✗' ?> <span class="muted"><?= e((string) $detail) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <form method="POST" action="/installation" class="auth-form">
        <?= \App\Core\Csrf::field() ?>
        <label>
          <span>Nom de l'entreprise</span>
          <input type="text" name="company_name" required autofocus />
        </label>
        <label>
          <span>Langue par défaut</span>
          <select name="locale">
            <?php foreach (\App\Core\I18n::LOCALES as $item): ?>
              <option value="<?= e($item['code']) ?>"><?= e($item['flag'] . ' ' . $item['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          <span>Adresse du compte d'administration</span>
          <input type="email" name="email" required autocomplete="username" />
        </label>
        <label>
          <span>Mot de passe</span>
          <input type="password" name="password" required minlength="12" autocomplete="new-password" />
        </label>
        <label>
          <span>Confirmation</span>
          <input type="password" name="confirm" required minlength="12" autocomplete="new-password" />
        </label>
        <button type="submit" class="btn btn-primary btn-block">Installer</button>
      </form>
    </div>
  </main>
  <script src="/js/theme.js" nonce="<?= e($nonce) ?>"></script>
</body>
</html>
