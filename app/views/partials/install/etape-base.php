<?php
/**
 * La base de données.
 *
 * Deux chemins, et le choix se fait ici une fois pour toutes : SQLite, qui ne
 * demande rien parce que le fichier est déjà là, ou MySQL, qui demande une
 * adresse — et qu'on essaie avant de s'y engager.
 */
$pilote = (string) ($brouillon['db_driver'] ?? 'sqlite');
$mysqlDispo = extension_loaded('pdo_mysql');
?>
<div class="engine-grid">
  <label class="engine<?= $pilote === 'sqlite' ? ' is-picked' : '' ?>">
    <input type="radio" name="db_driver" value="sqlite"<?= $pilote === 'sqlite' ? ' checked' : '' ?> data-engine />
    <span class="engine-head">
      <span class="engine-name">SQLite</span>
      <span class="engine-tag"><?= e(t('install.db.recommended')) ?></span>
    </span>
    <span class="engine-sub"><?= e(t('install.db.sqliteHelp')) ?></span>
  </label>

  <label class="engine<?= $pilote === 'mysql' ? ' is-picked' : '' ?><?= $mysqlDispo ? '' : ' is-off' ?>">
    <input type="radio" name="db_driver" value="mysql"<?= $pilote === 'mysql' ? ' checked' : '' ?><?= $mysqlDispo ? '' : ' disabled' ?> data-engine />
    <span class="engine-head">
      <span class="engine-name">MySQL / MariaDB</span>
      <?php if (!$mysqlDispo): ?><span class="engine-tag is-off"><?= e(t('install.db.unavailable')) ?></span><?php endif; ?>
    </span>
    <span class="engine-sub"><?= e($mysqlDispo ? t('install.db.mysqlHelp') : t('install.db.noDriver')) ?></span>
  </label>
</div>

<fieldset class="mysql-fields"<?= $pilote === 'mysql' ? '' : ' hidden' ?> data-mysql>
  <legend class="sr-only"><?= e(t('install.db.connection')) ?></legend>
  <div class="form-grid">
    <label>
      <span><?= e(t('install.db.host')) ?></span>
      <input type="text" name="db_host" value="<?= e((string) ($brouillon['db_host'] ?? '127.0.0.1')) ?>" autocomplete="off" />
      <span class="field-hint"><?= e(t('install.db.hostHelp')) ?></span>
    </label>
    <label>
      <span><?= e(t('install.db.port')) ?></span>
      <input type="number" name="db_port" value="<?= e((string) ($brouillon['db_port'] ?? 3306)) ?>" min="1" max="65535" />
    </label>
    <label>
      <span><?= e(t('install.db.name')) ?></span>
      <input type="text" name="db_name" value="<?= e((string) ($brouillon['db_name'] ?? '')) ?>" autocomplete="off" />
      <span class="field-hint"><?= e(t('install.db.nameHelp')) ?></span>
    </label>
    <label>
      <span><?= e(t('install.db.user')) ?></span>
      <input type="text" name="db_user" value="<?= e((string) ($brouillon['db_user'] ?? '')) ?>" autocomplete="off" />
    </label>
    <label class="span-2">
      <span><?= e(t('install.db.password')) ?></span>
      <input type="password" name="db_password" autocomplete="off" />
      <span class="field-hint"><?= e(t('install.db.passwordHelp')) ?></span>
    </label>
  </div>
</fieldset>
