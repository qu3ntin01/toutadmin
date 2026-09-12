<!doctype html>
<html lang="<?= e($locale) ?>" dir="<?= e($localeDir) ?>" data-palette="<?= e($palette) ?>">
<head>
<?= \App\Core\View::partial('head', ['title' => $title ?? t('app.name'), 'nonce' => $nonce]) ?>
</head>
<body class="app-body">
<div class="app-shell">
<?= \App\Core\View::partial('sidebar', get_defined_vars()) ?>
  <div class="app-main">
    <header class="app-header">
      <div>
        <h1><?= e($headerTitle ?? ($title ?? '')) ?></h1>
        <?php if (!empty($headerSubtitle)): ?><p class="muted"><?= e($headerSubtitle) ?></p><?php endif; ?>
      </div>
      <div class="app-header-right">
        <?php if (!empty($sessionUser)): ?>
          <span class="user-chip"><?= e($sessionUser['firstName'] . ' ' . $sessionUser['lastName']) ?></span>
        <?php endif; ?>
      </div>
    </header>

    <main class="page">
<?= \App\Core\View::partial('flash', ['flash' => $flash ?? null]) ?>
<?= $content ?>
    </main>
  </div>
</div>
<script src="/js/theme.js" nonce="<?= e($nonce) ?>"></script>
</body>
</html>
