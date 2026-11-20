<?php
/* Les champs personnalisés dans leur propre formulaire.

   Attend : champs (le rendu de CustomFields::valeurs()) et action (l'adresse où
   poster). Sert aux écrans qui n'ont pas de formulaire d'édition où glisser les
   champs — un ticket, une fiche tiers : là, un formulaire à part est la seule
   manière de les rendre modifiables, et il n'enregistre que les champs, donc le
   « tout ou rien » ne concerne qu'eux.

   Un écran qui a déjà son formulaire d'édition emploie « champs-perso » et non
   ce bloc : un second formulaire l'obligerait à enregistrer deux fois, et l'on
   en oublierait un.

   Rien ne s'affiche quand aucun champ n'est posé : un bloc vide ferait croire
   qu'il manque quelque chose. */

$champsDuBloc = isset($champs) && is_array($champs) ? $champs : [];
?>
<?php if ($champsDuBloc !== []): ?>
  <form method="POST" action="<?= e($action) ?>" class="form-grid">
    <?= \App\Core\Csrf::field() ?>
    <div class="span-2">
      <h4 class="section-label"><?= e(t('champs.title')) ?></h4>
      <?= \App\Core\View::partial('champs-perso', ['champs' => $champsDuBloc]) ?>
    </div>
    <div class="span-2"><button type="submit" class="btn btn-outline btn-block"><?= e(t('common.save')) ?></button></div>
  </form>
<?php endif; ?>
