<?php
$csrf = \App\Core\Csrf::field();
$when = static function (?string $value): string {
    return $value === null || $value === '' ? '—' : \App\Core\Dates::moment($value);
};
$branche = $config['url'] !== '' && $keySet && $config['public_key'] !== '';
?>
<section class="tab-panel is-active" id="etat">
  <div class="stats-grid">
    <?php foreach ([
        [$config['version'], t('updates.installed')],
        [t("updates.channel.{$config['channel']}"), t('updates.channel')],
        [t("updates.mode.{$config['mode']}"), t('updates.mode')],
        [$when($lastCheck), t('updates.lastCheck')],
    ] as [$value, $label]): ?>
      <div class="stat-card">
        <span class="stat-body">
          <span class="stat-value"><?= e($value) ?></span>
          <span class="stat-label"><?= e($label) ?></span>
        </span>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($immuable): ?>
    <?= \App\Core\View::partial('immuable') ?>
  <?php endif; ?>

  <?php if (!$branche): ?>
    <div class="card">
      <h2><?= e(t('updates.notConfigured')) ?></h2>
      <p class="hint"><?= e(t('updates.notConfiguredHelp')) ?></p>
    </div>
  <?php elseif ($available !== null): ?>
    <div class="card">
      <h2><?= e(t('updates.availableTitle', ['version' => $available['numero']])) ?></h2>
      <p class="hint">
        <?= e(t('updates.publishedOn', ['date' => $when($available['publiee_le'] ?? '')])) ?> ·
        <?= e(number_format(((int) ($available['taille'] ?? 0)) / (1024 * 1024), 1, ',', ' ')) ?> Mo ·
        <?= e((static function (string $canal): string { return t("updates.channel.$canal"); })((string) ($available['canal'] ?? $config['channel']))) ?>
      </p>
      <?php if (!empty($available['notes'])): ?>
        <pre class="code-block"><?= e($available['notes']) ?></pre>
      <?php endif; ?>
      <?php if (!$immuable): ?>
        <p class="hint"><?= e(t('updates.beforeApplyPhp')) ?></p>
        <form method="post" action="/mises-a-jour/appliquer"
              data-confirm="<?= e(t('updates.confirmApply', ['version' => $available['numero']])) ?>">
          <?= $csrf ?>
          <button class="btn btn-primary"
                  data-fenetre="poser"
                  data-version="<?= e((string) $available['numero']) ?>"
                  data-notes="<?= e((string) ($available['notes'] ?? '')) ?>"><?= e(t('updates.apply', ['version' => $available['numero']])) ?></button>
        </form>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="card">
      <h2><?= e(t('updates.upToDate')) ?></h2>
      <?php if ($lastError !== ''): ?>
        <p class="hint"><?= e(t("updates.error.$lastError")) ?></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <form method="post" action="/mises-a-jour/verifier">
    <?= $csrf ?>
    <button class="btn" data-fenetre="verifier"><?= e(t('updates.check')) ?></button>
  </form>
</section>

<section class="tab-panel" id="reglages">
  <form method="post" action="/mises-a-jour/reglages" class="card form-grid">
    <?= $csrf ?>
    <label>
      <span><?= e(t('updates.editorUrl')) ?></span>
      <input type="url" name="url" value="<?= e($config['url']) ?>"
             placeholder="<?= e(\App\Core\Settings::EDITEUR_PAR_DEFAUT) ?>">
      <small class="hint"><?= e(t('updates.editorUrlHelp')) ?></small>
    </label>

    <label>
      <span><?= e(t('updates.instanceKey')) ?></span>
      <input type="password" name="cle" autocomplete="off"
             placeholder="<?= e($keySet ? t('updates.keySet') : t('updates.keyUnset')) ?>">
      <small class="hint"><?= e(t('updates.instanceKeyHelp')) ?></small>
    </label>

    <label class="span-2">
      <span><?= e(t('updates.publicKey')) ?></span>
      <textarea name="clePublique" rows="5" spellcheck="false"><?= e($config['public_key']) ?></textarea>
      <small class="hint"><?= e(t('updates.publicKeyHelp')) ?></small>
    </label>

    <label>
      <span><?= e(t('updates.instanceName')) ?></span>
      <input name="nom" value="<?= e($config['name']) ?>" maxlength="120">
      <small class="hint"><?= e(t('updates.instanceNameHelp')) ?></small>
    </label>

    <label>
      <span><?= e(t('updates.channel')) ?></span>
      <select name="canal">
        <?php foreach (['stable', 'beta'] as $canal): ?>
          <option value="<?= e($canal) ?>" <?= $config['channel'] === $canal ? 'selected' : '' ?>>
            <?= e(t("updates.channel.$canal")) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>
      <span><?= e(t('updates.mode')) ?></span>
      <select name="mode">
        <?php foreach (['avertir', 'auto'] as $mode): ?>
          <option value="<?= e($mode) ?>" <?= $config['mode'] === $mode ? 'selected' : '' ?>>
            <?= e(t("updates.mode.$mode")) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <small class="hint"><?= e(t('updates.modeHelp')) ?></small>
    </label>

    <div class="span-2 row-actions">
      <button class="btn btn-primary"><?= e(t('common.save')) ?></button>
    </div>
  </form>
</section>

<section class="tab-panel" id="historique">
  <?php if ($history === []): ?>
    <p class="empty-state"><?= e(t('updates.noHistory')) ?></p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('updates.version')) ?></th>
            <th><?= e(t('updates.from')) ?></th>
            <th><?= e(t('updates.when')) ?></th>
            <th><?= e(t('updates.how')) ?></th>
            <th><?= e(t('updates.result')) ?></th>
            <th><?= e(t('updates.backup')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $row): ?>
            <tr>
              <td><strong><?= e($row['version']) ?></strong></td>
              <td><?= e($row['from_version'] !== '' ? $row['from_version'] : '—') ?></td>
              <td class="nowrap"><?= e($when($row['started_at'])) ?></td>
              <td><?= e(t("updates.how.{$row['mode']}")) ?></td>
              <td>
                <span class="tag <?= $row['status'] === 'success' ? 'tag-on' : ($row['status'] === 'failed' ? 'tag-off' : '') ?>">
                  <?= e(t("updates.status.{$row['status']}")) ?>
                </span>
                <?php if ($row['detail'] !== ''): ?>
                  <div class="cell-sub"><?= e($row['detail']) ?></div>
                <?php endif; ?>
              </td>
              <td class="cell-sub"><?= e($row['backup_file'] !== '' ? $row['backup_file'] : '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($immuable): ?>
      <?= \App\Core\View::partial('immuable') ?>
    <?php else: ?>
      <form method="post" action="/mises-a-jour/retour" data-confirm="<?= e(t('updates.confirmRollback')) ?>">
        <?= $csrf ?>
        <button class="btn btn-sm btn-danger"
                data-fenetre="retour"
                data-version="<?= e((string) $config['version']) ?>"><?= e(t('updates.rollback')) ?></button>
      </form>
      <p class="hint"><?= e(t('updates.rollbackHelp')) ?></p>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php /* Le script ne connaît aucun mot : ils viennent d'ici, dans la langue de
         la page. Une fenêtre qui parlerait anglais sur une instance en polonais
         serait pire que pas de fenêtre du tout. */ ?>
<script type="application/json" id="maj-libelles"><?= json_encode([
    'titrePose' => t('updates.window.applyTitle'),
    'titreEnCours' => t('updates.window.runningTitle'),
    'titreVerif' => t('updates.window.checkTitle'),
    'titreRetour' => t('updates.window.rollbackTitle'),
    'avantPose' => t('updates.beforeApplyPhp'),
    'avantRetour' => t('updates.confirmRollback'),
    'enVerif' => t('updates.window.checking'),
    'redemarrage' => '',
    'echec' => t('updates.window.failed'),
    'reponseIllisible' => t('updates.window.unreadable'),
    'poser' => t('updates.window.apply'),
    'revenir' => t('updates.rollback'),
    'annuler' => t('common.cancel'),
    'fermer' => t('common.close'),
    'masquer' => t('updates.window.hide'),
    'fermerEtRecharger' => t('updates.window.reload'),
    'etape.telechargement' => t('updates.step.download'),
    'etape.verification' => t('updates.step.verify'),
    'etape.lecture' => t('updates.step.read'),
    'etape.sauvegarde' => t('updates.step.backup'),
    'etape.pose' => t('updates.step.write'),
    'etape.controle' => t('updates.step.check'),
], JSON_UNESCAPED_UNICODE) ?></script>
