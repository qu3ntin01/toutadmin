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
        <span class="brand-panel"><?= e(t('alr.panel')) ?></span>
      </span>
    </div>
    <?= \App\Core\View::partial('theme-toggle') ?>
  </header>

  <main class="auth-main">
    <div class="auth-card">
      <?php
      $moment = static fn (?string $value): string => ($value === null || $value === '')
          ? '—' : str_replace('T', ' ', substr((string) $value, 0, 16));
      ?>

      <?php if ($report === null): ?>
        <h1><?= e(t('alr.followTitle')) ?></h1>
        <p class="auth-sub"><?= e(t('alr.followSub')) ?></p>

        <?php if ($followError): ?>
          <div class="flash flash-error"><?= e(t('alr.followRefused')) ?></div>
        <?php endif; ?>

        <form method="POST" action="/alertes/suivi" class="auth-form">
          <?= \App\Core\Csrf::field() ?>
          <label>
            <span><?= e(t('alr.reference')) ?></span>
            <input type="text" name="reference" value="<?= e($reference) ?>" placeholder="ALT-2026-0001" required autofocus />
          </label>
          <label>
            <span><?= e(t('alr.followCode')) ?></span>
            <input type="text" name="code" required autocomplete="off" spellcheck="false" />
          </label>
          <button type="submit" class="btn btn-primary btn-block"><?= e(t('alr.followOpen')) ?></button>
        </form>

        <p class="auth-note"><?= e(t('alr.followNoAccount')) ?></p>
      <?php else: ?>
        <h1><?= e($report['reference']) ?></h1>
        <p class="auth-sub">
          <span class="tag"><?= e(st($report['status'])) ?></span>
          <?= e(st($report['category'])) ?> · <?= e(t('alr.filedOn', ['date' => $moment($report['submitted_at'])])) ?>
        </p>

        <ul class="org-list">
          <li class="org-item">
            <span><?= e(t('alr.acknowledgement')) ?></span>
            <span class="<?= $report['acknowledged_at'] !== null ? 'cell-strong' : 'text-danger' ?>">
              <?= e($report['acknowledged_at'] !== null
                  ? $moment($report['acknowledged_at'])
                  : t('alr.awaited', ['days' => $ackDays])) ?>
            </span>
          </li>
          <?php if ($report['outcome'] !== ''): ?>
            <li class="org-item"><span><?= e(t('alr.outcome')) ?></span>
              <span class="cell-strong"><?= e($report['outcome']) ?></span></li>
          <?php endif; ?>
        </ul>

        <h2 class="auth-section"><?= e(t('alr.exchanges')) ?></h2>
        <ul class="meeting-list">
          <li class="meeting-item">
            <div class="meeting-head">
              <span class="meeting-title"><?= e(t('alr.yourReport')) ?></span>
              <span class="news-meta"><?= e($moment($report['submitted_at'])) ?></span>
            </div>
            <p class="ticket-body"><?= e($report['subject']) ?></p>
            <?php if ($report['body'] !== ''): ?><p class="ticket-body"><?= e($report['body']) ?></p><?php endif; ?>
          </li>
          <?php foreach ($messages as $message): ?>
            <li class="meeting-item">
              <div class="meeting-head">
                <span class="meeting-title">
                  <?= e($message['author_kind'] === 'referent' ? t('alr.fromReferent') : t('alr.fromYou')) ?>
                </span>
                <span class="news-meta"><?= e($moment($message['created_at'])) ?></span>
              </div>
              <p class="ticket-body"><?= e($message['body']) ?></p>
            </li>
          <?php endforeach; ?>
        </ul>

        <?php if ($report['status'] !== 'Clôturée'): ?>
          <form method="POST" action="/alertes/suivi/message" class="auth-form">
            <?= \App\Core\Csrf::field() ?>
            <input type="hidden" name="reference" value="<?= e($report['reference']) ?>" />
            <input type="hidden" name="code" value="<?= e($code) ?>" />
            <label>
              <span><?= e(t('alr.addMessage')) ?></span>
              <textarea name="body" rows="4" maxlength="5000" required></textarea>
            </label>
            <button type="submit" class="btn btn-primary btn-block"><?= e(t('alr.send')) ?></button>
          </form>
        <?php endif; ?>

        <p class="auth-note"><?= e(t('alr.keepCode')) ?></p>
      <?php endif; ?>

      <div class="auth-foot">
        <a href="/connexion"><?= e(t('alr.backToSignIn')) ?></a>
      </div>
    </div>
  </main>

  <script src="/js/theme.js" nonce="<?= e($nonce) ?>"></script>
</body>
</html>
