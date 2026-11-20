<?php
$current = $target ?? [
    'kind' => 'syslog', 'label' => '', 'transport' => 'udp', 'host' => '', 'port' => 514,
    'format' => 'rfc5424', 'facility' => 16, 'app_name' => 'toutadmin', 'min_level' => 6,
    'channels' => '', 'path' => '', 'allow_self_signed' => 0, 'enabled' => 0,
];
$selectedChannels = array_filter(explode(',', (string) $current['channels']));
?>
<form method="POST" action="<?= e($action) ?>" class="form-grid">
  <?= \App\Core\Csrf::field() ?>

  <label class="span-2"><span><?= e(t('log.targetName')) ?></span>
    <input type="text" name="label" value="<?= e((string) $current['label']) ?>" maxlength="120" required
           placeholder="<?= e(t('log.targetNameHint')) ?>" />
  </label>

  <label><span><?= e(t('log.targetKind')) ?></span>
    <select name="kind">
      <?php foreach ($kinds as $kind): ?>
        <option value="<?= e($kind) ?>"<?= $current['kind'] === $kind ? ' selected' : '' ?>><?= e(t("log.kind.$kind")) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label><span><?= e(t('log.transport')) ?></span>
    <select name="transport">
      <?php foreach ($transports as $transport): ?>
        <option value="<?= e($transport) ?>"<?= $current['transport'] === $transport ? ' selected' : '' ?>><?= e(t("log.transport.$transport")) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label><span><?= e(t('log.host')) ?></span>
    <input type="text" name="host" value="<?= e((string) $current['host']) ?>" maxlength="200" placeholder="wazuh.interne" />
  </label>

  <label><span><?= e(t('log.port')) ?></span>
    <input type="number" name="port" value="<?= (int) $current['port'] ?>" min="1" max="65535" />
  </label>

  <label class="span-2"><span><?= e(t('log.path')) ?></span>
    <input type="text" name="path" value="<?= e((string) $current['path']) ?>" maxlength="300"
           placeholder="/var/ossec/logs/toutadmin.json" />
  </label>

  <label><span><?= e(t('log.format')) ?></span>
    <select name="format">
      <?php foreach ($formats as $format): ?>
        <option value="<?= e($format) ?>"<?= $current['format'] === $format ? ' selected' : '' ?>><?= e(t("log.format.$format")) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label><span><?= e(t('log.facility')) ?></span>
    <input type="number" name="facility" value="<?= (int) $current['facility'] ?>" min="0" max="23" />
  </label>

  <label><span><?= e(t('log.appName')) ?></span>
    <input type="text" name="app_name" value="<?= e((string) $current['app_name']) ?>" maxlength="48" />
  </label>

  <label><span><?= e(t('log.minLevel')) ?></span>
    <select name="min_level">
      <?php foreach ($levels as $band): ?>
        <option value="<?= (int) $band['value'] ?>"<?= (int) $current['min_level'] === (int) $band['value'] ? ' selected' : '' ?>><?= e(t("log.level.{$band['key']}")) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <fieldset class="span-2">
    <legend><?= e(t('log.channelsFilter')) ?></legend>
    <p class="hint"><?= e(t('log.channelsHint')) ?></p>
    <div class="checks">
      <?php foreach ($channels as $channel): ?>
        <label class="check">
          <input type="checkbox" name="channels[]" value="<?= e($channel) ?>"<?= in_array($channel, $selectedChannels, true) ? ' checked' : '' ?> />
          <span><?= e(t("log.channel.$channel")) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <label class="check">
    <input type="checkbox" name="allow_self_signed" value="1"<?= (int) $current['allow_self_signed'] === 1 ? ' checked' : '' ?> />
    <span><?= e(t('log.allowSelfSigned')) ?></span>
  </label>

  <label class="check">
    <input type="checkbox" name="enabled" value="1"<?= (int) $current['enabled'] === 1 ? ' checked' : '' ?> />
    <span><?= e(t('log.enabled')) ?></span>
  </label>

  <div class="span-2 form-actions">
    <button type="submit" class="btn btn-primary"><?= e($target === null ? t('log.addTarget') : t('common.save')) ?></button>
  </div>
</form>
