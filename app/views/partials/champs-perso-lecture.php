<?php
/* Les champs personnalisés d'un enregistrement, en lecture seule.

   Attend : champs (le rendu de CustomFields::valeurs()).

   Sert là où les valeurs se consultent sans se reprendre : une demande interne
   déposée ne se modifie pas, elle se décide ou se retire. Afficher des champs
   de saisie à qui ne peut pas enregistrer ferait croire le contraire.

   Les champs vides ne s'affichent pas : une liste de tirets n'apprend rien, et
   fait passer pour un oubli ce qui n'était pas demandé. */

$champsLus = array_values(array_filter(
    isset($champs) && is_array($champs) ? $champs : [],
    static fn (array $c): bool => (string) $c['valeur'] !== '',
));
?>
<?php if ($champsLus !== []): ?>
  <h4 class="section-label"><?= e(t('champs.title')) ?></h4>
  <ul class="org-list">
    <?php foreach ($champsLus as $c): ?>
      <li class="org-item">
        <span><?= e($c['libelle']) ?></span>
        <span class="cell-strong">
          <?php if ($c['type'] === 'booleen'): ?>
            <?= e($c['valeur'] === '1' ? t('common.yes') : t('common.no')) ?>
          <?php elseif ($c['type'] === 'lien'): ?>
            <a href="<?= e($c['valeur']) ?>" rel="noopener noreferrer" target="_blank"><?= e($c['valeur']) ?></a>
          <?php else: ?>
            <?= e($c['valeur']) ?>
          <?php endif; ?>
        </span>
      </li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
