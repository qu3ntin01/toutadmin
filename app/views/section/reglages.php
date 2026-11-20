<div class="card">
  <div class="card-head"><h2><?= e(t('sec.settings.title')) ?></h2></div>
  <?php if ($champs === []): ?>
    <p class="hint"><?= e(t('sec.settings.none')) ?></p>
  <?php else: ?>
    <p class="hint"><?= e(t('sec.settings.intro')) ?></p>
    <form method="POST" action="/section/<?= e((string) $groupe['cle']) ?>/reglages" class="form-grid">
      <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>" />
      <?= \App\Core\View::partial('champs-section', ['groupe' => $groupe, 'champs' => $champs]) ?>
      <div class="span-2 form-actions">
        <button type="submit" class="btn btn-primary"><?= e(t('common.save')) ?></button>
        <a class="btn" href="/section/<?= e((string) $groupe['cle']) ?>"><?= e(t('common.back')) ?></a>
      </div>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-head"><h3><?= e(t('sec.wizard.again')) ?></h3></div>
  <p class="hint"><?= e(t('sec.wizard.againHelp')) ?></p>
  <form method="POST" action="/section/<?= e((string) $groupe['cle']) ?>/assistant/rouvrir">
    <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>" />
    <button type="submit" class="btn"><?= e(t('sec.wizard.againButton')) ?></button>
  </form>
</div>
