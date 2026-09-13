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
        <?php foreach ($checks as [$label, $ok, $detail, $blocking]): ?>
          <tr>
            <td><?= e($label) ?><?= $blocking ? '' : ' <span class="muted">(facultatif)</span>' ?></td>
            <td class="<?= $ok ? 'ok' : 'ko' ?>"><?= $ok ? '✓' : '✗' ?> <span class="muted"><?= e((string) $detail) ?></span></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <form method="POST" action="/installation" class="auth-form">
        <?= \App\Core\Csrf::field() ?>
        <?php if ($tokenRequired): ?>
          <label>
            <span>Jeton d'installation</span>
            <input type="text" name="install_token" required autocomplete="off" autofocus />
            <span class="hint">Celui que porte la configuration de cette instance.</span>
          </label>
        <?php endif; ?>
        <label>
          <span>Nom de l'entreprise</span>
          <input type="text" name="company_name" required<?= $tokenRequired ? '' : ' autofocus' ?> />
        </label>
        <label>
          <span>Congés annuels (jours ouvrés)</span>
          <input type="number" name="annual_leave_days" value="25" min="0" max="365" step="1" />
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
