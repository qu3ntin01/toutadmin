<?php
/**
 * Les prérequis.
 *
 * Vérifiés plutôt que supposés, et dits avant qu'on remplisse quoi que ce
 * soit : un formulaire rempli puis refusé pour une extension manquante est du
 * travail qu'on aurait pu épargner.
 */
?>
<ul class="check-list">
  <?php foreach ($controles as $controle): ?>
    <li class="check-item">
      <span class="check-mark <?= $controle['ok'] ? 'is-ok' : ($controle['blocking'] ? 'is-fail' : 'is-warn') ?>" aria-hidden="true"><?= $controle['ok'] ? '✓' : ($controle['blocking'] ? '✕' : '!') ?></span>
      <span class="check-body">
        <span class="check-label">
          <?= e($controle['label']) ?>
          <?php if (!$controle['blocking']): ?><span class="check-tag"><?= e(t('install.checks.optional')) ?></span><?php endif; ?>
        </span>
        <span class="check-detail"><?= e($controle['detail']) ?></span>
      </span>
    </li>
  <?php endforeach; ?>
</ul>

<?php if (!$configInscriptible): ?>
  <p class="install-warn"><?= e(t('install.checks.configWarn')) ?></p>
<?php endif; ?>

<?php if ($tokenRequired): ?>
  <label class="install-field">
    <span><?= e(t('install.checks.token')) ?></span>
    <input type="password" name="install_token" required autocomplete="off" autofocus />
    <span class="field-hint"><?= e(t('install.tokenHelp')) ?></span>
  </label>
<?php endif; ?>

<p class="install-note"><?= e($pret ? t('install.ready', ['version' => $version]) : t('install.notReady')) ?></p>
