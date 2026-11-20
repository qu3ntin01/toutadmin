<?php
/*
 * Le code de cette instance ne s'écrit pas.
 *
 * Montré en tête de l'onglet « état » plutôt que dans chaque branche : une
 * instance neuve n'a pas encore de réglages de mise à jour, et c'est justement
 * là qu'on se demande pourquoi l'écran ne propose rien. Dire à la fin, sous
 * une condition qu'on n'atteint pas, revient à ne rien dire.
 */
?>
<div class="banner is-warning">
  <span>
    <strong><?= e(t('updates.immutableTitle')) ?></strong><br />
    <?= e(t('updates.immutableBody')) ?>
  </span>
</div>
<pre class="code-block"><?= e(t('updates.immutableHow')) ?></pre>
