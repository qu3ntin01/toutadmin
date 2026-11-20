<?php
/* Le sélecteur de société, dans l'en-tête de chaque écran.

   Il ne s'affiche que s'il y a un choix à faire : une personne rattachée à une
   seule société n'a rien à choisir, et un sélecteur à une entrée ne ferait que
   poser la question de ce qu'il y a derrière.

   Il dit aussi où l'on est, et c'est son premier rôle : saisir une facture dans
   la mauvaise entité d'un groupe ne se répare pas en la déplaçant. */

$ici = \App\Modules\Societes::courante();
$ouvertes = $ici === null ? [] : \App\Modules\Societes::accessibles(\App\Core\Session::get('user'));
?>
<?php if ($ici !== null && count($ouvertes) > 1): ?>
  <form method="POST" action="/societes/choisir" class="societe-selecteur">
    <?= \App\Core\Csrf::field() ?>
    <label>
      <span class="sr-only"><?= e(t('societes.switch')) ?></span>
      <?php /* Un bouton plutôt qu'un « onchange » : la politique de sécurité
               de contenu de cette édition n'autorise aucun script en ligne, et
               l'affaiblir pour un confort de clic serait un mauvais échange. */ ?>
      <select name="societe_id" aria-label="<?= e(t('societes.switch')) ?>">
        <?php foreach ($ouvertes as $societe): ?>
          <option value="<?= (int) $societe['id'] ?>"<?= (int) $societe['id'] === (int) $ici['id'] ? ' selected' : '' ?>>
            <?= e($societe['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit" class="btn btn-sm"><?= e(t('societes.switch')) ?></button>
  </form>
<?php endif; ?>
