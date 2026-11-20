<?php
/* Les champs personnalisés d'une ligne de tableau, sous la ligne.

   Attend : champs (le rendu de CustomFields::valeurs() pour cet
   enregistrement), action (l'adresse où poster) et colonnes (le nombre de
   colonnes du tableau).

   Sert aux objets qui n'ont pas de fiche à eux — une facture, un équipement :
   ils vivent dans une liste, et un dépli sous leur ligne est le seul endroit où
   les reprendre sans quitter l'écran. Replié, il ne coûte rien à la lecture du
   tableau ; une entreprise qui n'a posé aucun champ ne le voit même pas. */

$champsDeLaLigne = isset($champs) && is_array($champs) ? $champs : [];
?>
<?php if ($champsDeLaLigne !== []): ?>
  <tr class="row-detail">
    <td colspan="<?= (int) $colonnes ?>">
      <details class="disclosure">
        <summary><?= e(t('champs.title')) ?></summary>
        <form method="POST" action="<?= e($action) ?>" class="form-grid">
          <?= \App\Core\Csrf::field() ?>
          <div class="span-2"><?= \App\Core\View::partial('champs-perso', ['champs' => $champsDeLaLigne]) ?></div>
          <div class="span-2"><button type="submit" class="btn btn-outline btn-sm"><?= e(t('common.save')) ?></button></div>
        </form>
      </details>
    </td>
  </tr>
<?php endif; ?>
