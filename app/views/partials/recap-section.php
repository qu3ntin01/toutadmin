<?php
/* Ce qui est posé dans une section, en clair.

   Le même rendu sert le tableau de bord de la section et le dernier temps de
   l'assistant : deux listes écrites séparément auraient fini par montrer deux
   choses différentes du même réglage.

   Attend : groupe, champs (rendus par champsDe). */
$nomDuMois = static function (int $n) use ($locale): string {
    if (!class_exists(\IntlDateFormatter::class)) {
        return (string) $n;
    }
    $f = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, 'LLLL');
    return (string) $f->format(mktime(0, 0, 0, $n, 1, 2000));
};
?>
<dl class="recap">
  <?php foreach ($champs as $champ): ?>
    <?php $cle = 'sec.' . $groupe['cle'] . '.' . $champ['cle']; ?>
    <div class="recap-row">
      <dt><?= e(t($cle)) ?></dt>
      <dd>
        <?php if ($champ['type'] === 'bool'): ?>
          <?= e(t($champ['valeur'] === '1' ? 'common.yes' : 'common.no')) ?>
        <?php elseif ($champ['type'] === 'mois'): ?>
          <?= e($nomDuMois((int) $champ['valeur'])) ?>
        <?php elseif ($champ['type'] === 'choix'): ?>
          <?php
          $choisi = null;
          foreach (($champ['options'] ?? []) as $option) {
              if ($option['valeur'] === $champ['valeur']) {
                  $choisi = $option;
              }
          }
          ?>
          <?= e($choisi === null ? (string) $champ['valeur'] : ($choisi['libelle'] ?? t($cle . '.' . $choisi['valeur']))) ?>
        <?php else: ?>
          <?= e((string) $champ['valeur']) ?>
        <?php endif; ?>
      </dd>
    </div>
  <?php endforeach; ?>
</dl>
