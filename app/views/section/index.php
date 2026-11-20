<?php /* Les tuiles portent l'icône du rail : celle qu'on vient de voir en
         arrivant ici est celle qu'on retrouvera en repartant. */ ?>
<div class="card">
  <div class="card-head"><h2><?= e(t('sections.whatFor')) ?></h2></div>
  <div class="tiles">
    <?php foreach ($services as $service): ?>
      <a class="tile" href="<?= e((string) $service['href']) ?>">
        <span class="tile-icon"><?= \App\Core\View::partial('icon', ['name' => $service['icone'], 'iconSize' => 20]) ?></span>
        <span class="tile-text">
          <span class="tile-name"><?= e(t($service['titre'])) ?></span>
          <span class="tile-what"><?= e(t($service['quoi'])) ?></span>
        </span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<?php /* Les réglages de la section, là où l'on est déjà : les chercher dans un
         onglet de la console, c'est sortir du métier pour y revenir. */ ?>
<?php if (!empty($peutRegler) && $champs !== []): ?>
  <div class="card">
    <div class="card-head">
      <h2><?= e(t('sec.settings.title')) ?></h2>
      <a class="btn btn-sm" href="/section/<?= e((string) $groupe['cle']) ?>/reglages"><?= e(t('sec.settings.open')) ?></a>
    </div>
    <?= \App\Core\View::partial('recap-section', ['groupe' => $groupe, 'champs' => $champs]) ?>
  </div>
<?php endif; ?>
