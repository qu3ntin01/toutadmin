<?php
/* Les champs d'une section, rendus à partir de leur seule description.

   Une section déclare ses réglages dans app/Core/SectionsReglages.php ; ce
   gabarit ne connaît aucune section en particulier, et ajouter un champ ne
   demande donc pas d'y toucher. Un formulaire écrit à la main par section
   aurait fini par diverger de ce que le serveur accepte — et un champ affiché
   que le serveur refuse est le pire des deux mondes.

   Attend : groupe (la section), champs (la liste rendue par champsDe). */

/* Le nom des mois vient d'ICU quand l'hôte l'a, du numéro sinon. Les recopier
   dans les seize dictionnaires aurait fait cent quatre-vingt-douze chaînes à
   traduire pour ce que la plateforme sait déjà faire — mais « ext-intl » n'est
   pas exigé à l'installation, et un réglage ne doit pas disparaître parce qu'une
   extension manque. */
$nomDuMois = static function (int $n) use ($locale): string {
    if (!class_exists(\IntlDateFormatter::class)) {
        return (string) $n;
    }
    $f = new \IntlDateFormatter($locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, 'UTC', null, 'LLLL');
    return (string) $f->format(mktime(0, 0, 0, $n, 1, 2000));
};
$cleDu = static fn (array $champ): string => 'sec.' . $groupe['cle'] . '.' . $champ['cle'];
/* Un nombre à deux chiffres n'a pas besoin de toute la largeur, et un champ
   large au-dessus d'une valeur courte fait croire qu'on attend une phrase.
   Seuls le texte libre et les cases à cocher — dont la légende est longue —
   prennent les deux colonnes. */
$largeur = static fn (array $champ): string => in_array($champ['type'], ['texte', 'bool'], true) ? 'span-2' : '';
?>
<?php foreach ($champs as $champ): ?>
  <?php $id = 'champ-' . $groupe['cle'] . '-' . $champ['cle']; ?>
  <?php if ($champ['type'] === 'bool'): ?>
    <div class="<?= $largeur($champ) ?>">
      <label class="check" for="<?= e($id) ?>">
        <input type="checkbox" id="<?= e($id) ?>" name="<?= e((string) $champ['cle']) ?>" value="1" <?= $champ['valeur'] === '1' ? 'checked' : '' ?> />
        <span><?= e(t($cleDu($champ))) ?></span>
      </label>
      <span class="hint"><?= e(t($cleDu($champ) . '.hint')) ?></span>
    </div>
  <?php else: ?>
    <label class="<?= $largeur($champ) ?>">
      <span><?= e(t($cleDu($champ))) ?></span>
      <?php if ($champ['type'] === 'nombre'): ?>
        <input type="number" id="<?= e($id) ?>" name="<?= e((string) $champ['cle']) ?>" value="<?= e((string) $champ['valeur']) ?>"
               min="<?= e((string) ($champ['min'] ?? '')) ?>" max="<?= e((string) ($champ['max'] ?? '')) ?>" step="1" required />
      <?php elseif ($champ['type'] === 'mois'): ?>
        <select id="<?= e($id) ?>" name="<?= e((string) $champ['cle']) ?>">
          <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= (string) $m === (string) $champ['valeur'] ? 'selected' : '' ?>><?= e($nomDuMois($m)) ?></option>
          <?php endfor; ?>
        </select>
      <?php elseif ($champ['type'] === 'choix'): ?>
        <select id="<?= e($id) ?>" name="<?= e((string) $champ['cle']) ?>">
          <?php foreach (($champ['options'] ?? []) as $option): ?>
            <?php /* Un libellé porté par l'option s'affiche tel quel — un code
                     de devise ou le nom d'une palette ne se traduisent pas.
                     Sans libellé, c'est au dictionnaire de le dire. */ ?>
            <option value="<?= e((string) $option['valeur']) ?>" <?= $option['valeur'] === $champ['valeur'] ? 'selected' : '' ?>>
              <?= e($option['libelle'] ?? t($cleDu($champ) . '.' . $option['valeur'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php else: ?>
        <input type="text" id="<?= e($id) ?>" name="<?= e((string) $champ['cle']) ?>" value="<?= e((string) $champ['valeur']) ?>"
               maxlength="<?= e((string) ($champ['max'] ?? 200)) ?>" required />
      <?php endif; ?>
      <span class="hint"><?= e(t($cleDu($champ) . '.hint')) ?></span>
    </label>
  <?php endif; ?>
<?php endforeach; ?>
