<?php
/* L'étape « Sections » de l'assistant d'installation.

   Une case par section, et sous chacune ce qu'elle contient : on ne choisit pas
   un mot, on choisit des services. « Réglages » est cochée et verrouillée —
   c'est elle qui permet de reposer les autres, et une instance sans elle serait
   fermée à clé de l'intérieur.

   Cochées par défaut au premier passage : une entreprise qui ne sait pas encore
   ce dont elle aura besoin doit repartir avec le produit entier, pas avec une
   coquille. Retirer se fait en une case ; découvrir qu'il manque un métier six
   mois plus tard coûte bien davantage. */
$dejaChoisies = $brouillon['sections'] ?? null;
?>
<p class="field-hint"><?= e(t('install.sections.lede')) ?></p>
<ul class="pick-list">
  <?php foreach (\App\Core\Sections::GROUPES as $groupe): ?>
    <?php
    $cle = (string) $groupe['cle'];
    $obligatoire = in_array($cle, \App\Core\SectionsReglages::OBLIGATOIRES, true);
    $retenue = $dejaChoisies === null ? true : in_array($cle, $dejaChoisies, true);
    $services = \App\Core\Sections::servicesDe($cle);
    ?>
    <li class="pick <?= $obligatoire ? 'is-locked' : '' ?>">
      <label class="pick-head" for="sec-<?= e($cle) ?>">
        <input type="checkbox" id="sec-<?= e($cle) ?>" name="sections[]" value="<?= e($cle) ?>"
               <?= $retenue || $obligatoire ? 'checked' : '' ?> <?= $obligatoire ? 'disabled' : '' ?> />
        <span class="pick-name"><?= e(t($groupe['titre'])) ?></span>
        <?php if ($obligatoire): ?>
          <span class="pick-tag"><?= e(t('install.sections.required')) ?></span>
        <?php endif; ?>
      </label>
      <p class="pick-what"><?= e(implode(' · ', array_map(static fn (array $s): string => t($s['titre']), $services))) ?></p>
    </li>
  <?php endforeach; ?>
</ul>
<p class="field-hint"><?= e(t('install.sections.later')) ?></p>
