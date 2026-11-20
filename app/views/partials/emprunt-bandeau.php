<?php
/* Le bandeau d'emprunt d'identité, posé sous l'en-tête de chaque écran.

   Il ne doit jamais pouvoir passer inaperçu : un administrateur qui oublie qu'il
   voit l'écran de quelqu'un d'autre finit par écrire en son nom sans le savoir.
   Il dit donc trois choses à la fois — au nom de qui l'on agit, combien de temps
   il reste, et comment revenir — et la sortie est à portée de clic. */

$enCours = \App\Modules\Emprunt::encours();
?>
<?php if ($enCours !== null): ?>
  <div class="flash flash-warning emprunt-banner" role="alert">
    <span>
      <strong><?= e(t('emprunt.bannerTitle')) ?></strong>
      <?= e(t('emprunt.bannerBody', [
        'par' => $enCours['parLabel'],
        'minutes' => $enCours['minutesRestantes'],
      ])) ?>
    </span>
    <form method="POST" action="/emprunt/retour" class="inline-form">
      <?= \App\Core\Csrf::field() ?>
      <button type="submit" class="btn btn-sm"><?= e(t('emprunt.back')) ?></button>
    </form>
  </div>
<?php endif; ?>
