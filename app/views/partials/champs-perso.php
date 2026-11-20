<?php
/* Les champs personnalisés d'un enregistrement, dans un formulaire.

   Attend : champs (le rendu de CustomFields::valeurs()), et rien d'autre. Le
   partiel ne va pas chercher les valeurs lui-même : c'est l'écran qui a eu le
   droit de charger l'enregistrement, et lui seul doit décider de ce qu'il
   affiche. Un partiel qui irait lire en base contournerait ce droit sans que
   personne ne s'en aperçoive.

   Les champs portent le nom « perso_<id> », que CustomFields::enregistrer()
   sait relire — et non « champ_<id> », que les demandes internes emploient
   déjà pour les leurs. Un écran qui appelle ce partiel n'a donc qu'une ligne à ajouter
   à son enregistrement. */

$champsARendre = isset($champs) && is_array($champs) ? $champs : [];
?>
<?php if ($champsARendre !== []): ?>
  <div class="champs-perso">
    <?php foreach ($champsARendre as $c): ?>
      <?php $nom = 'perso_' . (int) $c['id']; ?>
      <?php if ($c['type'] === 'booleen'): ?>
        <label class="check">
          <input type="checkbox" name="<?= e($nom) ?>" value="1"<?= $c['valeur'] === '1' ? ' checked' : '' ?>>
          <span>
            <?= e($c['libelle']) ?>
            <?php if ($c['aide'] !== ''): ?><span class="cell-sub"><?= e($c['aide']) ?></span><?php endif; ?>
          </span>
        </label>
      <?php else: ?>
        <label>
          <span><?= e($c['libelle']) ?><?= !empty($c['obligatoire']) ? ' *' : '' ?></span>
          <?php if ($c['type'] === 'texte_long'): ?>
            <textarea name="<?= e($nom) ?>" rows="4"<?= !empty($c['obligatoire']) ? ' required' : '' ?>><?= e($c['valeur']) ?></textarea>
          <?php elseif ($c['type'] === 'liste'): ?>
            <select name="<?= e($nom) ?>"<?= !empty($c['obligatoire']) ? ' required' : '' ?>>
              <option value=""><?= e(t('champs.pick')) ?></option>
              <?php foreach ($c['choixListe'] as $choix): ?>
                <option value="<?= e($choix) ?>"<?= $c['valeur'] === $choix ? ' selected' : '' ?>><?= e($choix) ?></option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <?php /* Le type de l'élément suit celui du champ : un clavier de
                     téléphone propose des chiffres pour un nombre, et un
                     sélecteur de date pour une date. C'est la seule part du
                     rendu qui change, le contrôle restant côté serveur. */ ?>
            <input
              type="<?= $c['type'] === 'date' ? 'date' : ($c['type'] === 'lien' ? 'url' : 'text') ?>"
              name="<?= e($nom) ?>"
              value="<?= e($c['valeur']) ?>"
              <?= $c['type'] === 'montant' || $c['type'] === 'nombre' ? ' inputmode="decimal"' : '' ?>
              <?= !empty($c['obligatoire']) ? ' required' : '' ?>>
          <?php endif; ?>
          <?php if ($c['aide'] !== ''): ?><span class="hint"><?= e($c['aide']) ?></span><?php endif; ?>
        </label>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
