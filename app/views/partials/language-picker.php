<form method="POST" action="/langue" class="lang-picker">
  <?= \App\Core\Csrf::field() ?>
  <input type="hidden" name="returnTo" value="<?= e($returnTo ?? '/') ?>" />
  <label class="sr-only" for="locale"><?= e(t('common.language')) ?></label>
  <select name="locale" id="locale">
    <?php foreach ($locales as $item): ?>
      <option value="<?= e($item['code']) ?>"<?= $item['code'] === $locale ? ' selected' : '' ?>>
        <?= e($item['flag'] . ' ' . $item['label']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-sm"><?= e(t('common.save')) ?></button>
</form>
