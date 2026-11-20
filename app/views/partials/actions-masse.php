<?php
/*
 * La barre des actions de masse.
 *
 * Le pendant de « views/partials/actions-masse.ejs » de l'édition Node.
 *
 * Un contrôle par action, et non un seul champ qui changerait de sens selon
 * l'action choisie : la barre doit marcher sans script. Un champ partagé aurait
 * demandé de le réécrire à chaque changement d'action, donc du script, donc une
 * action de masse qu'on ne peut pas éprouver sans navigateur.
 *
 * Attendu de l'appelant : « $masseObjet », et « $masseActions » — une liste de
 * ['cle' => …, 'champ' => …|null, 'options' => [['valeur' => …, 'libelle' => …]]].
 * Les cases à cocher, elles, vivent dans la liste et rejoignent ce formulaire par
 * leur attribut « form ».
 */
$masseId = 'masse-' . $masseObjet;
?>
<form id="<?= e($masseId) ?>" method="post" action="/actions-masse" class="masse">
  <?= \App\Core\Csrf::field() ?>
  <input type="hidden" name="objet" value="<?= e($masseObjet) ?>" />
  <div class="masse-head">
    <strong><?= e(t('masse.title')) ?></strong>
    <span class="cell-sub" data-masse-count data-masse-label="<?= e(t('masse.selection')) ?>"><?= e(t('masse.hint')) ?></span>
  </div>
  <div class="masse-actions">
    <?php foreach ($masseActions as $acte): ?>
      <div class="masse-act">
        <?php if ($acte['champ'] !== null): ?>
          <select name="valeur_<?= e($acte['champ']) ?>" aria-label="<?= e(t('masse.action.' . $acte['cle'])) ?>">
            <?php foreach ($acte['options'] as $option): ?>
              <option value="<?= e((string) $option['valeur']) ?>"><?= e((string) $option['libelle']) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
        <button type="submit" name="action" value="<?= e($acte['cle']) ?>" class="btn btn-sm btn-outline">
          <?= e(t('masse.action.' . $acte['cle'])) ?>
        </button>
      </div>
    <?php endforeach; ?>
  </div>
</form>
