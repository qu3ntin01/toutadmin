<?php $csrf = \App\Core\Csrf::field(); ?>
<div class="card">
  <?php if ($survey['intro'] !== ''): ?><p class="page-intro"><?= e($survey['intro']) ?></p><?php endif; ?>
  <form method="POST" action="/sondages/<?= (int) $survey['id'] ?>" class="form-grid">
    <?= $csrf ?>
    <?php foreach ($questionList as $question): ?>
      <?php $required = (int) $question['required'] === 1; ?>
      <?php if ($question['type'] === 'echelle'): ?>
        <label class="span-2"><span><?= e($question['label']) ?><?= $required ? ' *' : '' ?></span>
          <select name="q_<?= (int) $question['id'] ?>"<?= $required ? ' required' : '' ?>>
            <option value="">—</option>
            <?php foreach ($scale as $level): ?><option value="<?= (int) $level ?>"><?= (int) $level ?></option><?php endforeach; ?>
          </select>
        </label>
      <?php elseif ($question['type'] === 'oui_non'): ?>
        <label class="span-2"><span><?= e($question['label']) ?><?= $required ? ' *' : '' ?></span>
          <select name="q_<?= (int) $question['id'] ?>"<?= $required ? ' required' : '' ?>>
            <option value="">—</option>
            <option value="Oui"><?= e(t('srp.yes')) ?></option>
            <option value="Non"><?= e(t('srp.no')) ?></option>
          </select>
        </label>
      <?php elseif ($question['type'] === 'choix'): ?>
        <label class="span-2"><span><?= e($question['label']) ?><?= $required ? ' *' : '' ?></span>
          <select name="q_<?= (int) $question['id'] ?>"<?= $required ? ' required' : '' ?>>
            <option value="">—</option>
            <?php foreach ($question['choiceList'] as $choice): ?>
              <option value="<?= e($choice) ?>"><?= e($choice) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php else: ?>
        <label class="span-2"><span><?= e($question['label']) ?><?= $required ? ' *' : '' ?></span>
          <textarea name="q_<?= (int) $question['id'] ?>" rows="3"<?= $required ? ' required' : '' ?>></textarea>
        </label>
      <?php endif; ?>
    <?php endforeach; ?>
    <div class="span-2"><button type="submit" class="btn btn-primary btn-block"><?= e(t('srp.send')) ?></button></div>
  </form>
  <p class="hint"><?= e(t('srp.finalNote')) ?></p>
</div>
