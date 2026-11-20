<?php
/**
 * La pose.
 *
 * Quatre paliers, et quatre appels au serveur : la configuration écrite, le
 * schéma posé, l'instance ouverte, la fin. La barre avance quand un palier
 * revient, jamais avant — une barre qui court toute seule pendant qu'on
 * attend ment surtout au moment où elle devrait alerter.
 */
$paliers = [
    ['cle' => 'controle', 'libelle' => t('install.run.control')],
    ['cle' => 'schema', 'libelle' => t('install.run.schema')],
    ['cle' => 'instance', 'libelle' => t('install.run.instance')],
    ['cle' => 'configuration', 'libelle' => t('install.run.config')],
];
$recapitulatif = [
    t('install.steps.langue') => (static function () use ($brouillon): string {
        $info = \App\Core\I18n::info((string) ($brouillon['locale'] ?? 'fr'));
        return $info['flag'] . ' ' . $info['label'];
    })(),
    t('install.steps.base') => ($brouillon['db_driver'] ?? 'sqlite') === 'mysql'
        ? 'MySQL · ' . (string) $brouillon['db_name'] . ' @ ' . (string) $brouillon['db_host']
        : 'SQLite · ' . t('install.db.localFile'),
    t('install.steps.entreprise') => (string) ($brouillon['company_name'] ?? ''),
    t('install.steps.administrateur') => (string) ($brouillon['email'] ?? ''),
];
?>
<div class="install-run" data-run
     data-from="<?= (int) round($rang / count($etapes) * 100) ?>"
     data-labels="<?= e((string) json_encode([
        'done' => t('install.run.done'),
        'failed' => t('install.run.failed'),
        'redirect' => t('install.run.redirect'),
        'httpError' => t('install.run.httpError'),
        'networkError' => t('install.run.networkError'),
     ], JSON_UNESCAPED_UNICODE)) ?>">
  <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>" />

  <dl class="recap" data-recap>
    <?php foreach ($recapitulatif as $quoi => $valeur): ?>
      <div class="recap-row">
        <dt><?= e($quoi) ?></dt>
        <dd><?= e($valeur) ?></dd>
      </div>
    <?php endforeach; ?>
  </dl>

  <!-- Les paliers, visibles avant même de commencer : on sait ce qui va se
       passer, puis on le voit se passer. -->
  <ol class="run-steps" data-steps>
    <?php foreach ($paliers as $palier): ?>
      <li class="run-step" data-step="<?= e($palier['cle']) ?>">
        <span class="run-dot" aria-hidden="true"></span>
        <span class="run-label"><?= e($palier['libelle']) ?></span>
        <span class="run-said" data-said></span>
      </li>
    <?php endforeach; ?>
  </ol>

  <p class="run-error" data-error hidden></p>

  <div class="install-actions">
    <a class="btn btn-ghost" href="/install/<?= e($etapes[$rang - 1]) ?>" data-back><?= e(t('install.back')) ?></a>
    <button type="button" class="btn btn-primary" data-launch>
      <?= e(t('install.launch')) ?>
    </button>
  </div>
</div>
