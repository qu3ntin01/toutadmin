<?php
/**
 * La langue.
 *
 * Premier écran parce que c'est le seul qui conditionne la lecture des cinq
 * autres : demander la base de données en français à quelqu'un qui installe en
 * japonais, c'est lui demander de deviner.
 */
$choisie = (string) ($brouillon['locale'] ?? $locale ?? 'fr');
?>
<div class="choice-grid">
  <?php foreach (\App\Core\I18n::LOCALES as $item): ?>
    <label class="choice<?= $item['code'] === $choisie ? ' is-picked' : '' ?>">
      <input type="radio" name="locale" value="<?= e($item['code']) ?>"<?= $item['code'] === $choisie ? ' checked' : '' ?> />
      <span class="choice-flag" aria-hidden="true"><?= e($item['flag']) ?></span>
      <span class="choice-label"><?= e($item['label']) ?></span>
    </label>
  <?php endforeach; ?>
</div>
