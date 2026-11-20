<?php /* Les trois temps, montrés du premier au dernier : on sait où l'on en
         est et combien il en reste. Un assistant qui ne le dit pas donne
         l'impression de ne jamais finir. */ ?>
<ol class="wizard-steps">
  <?php foreach ($etapes as $index => $nom): ?>
    <li class="wizard-step <?= $index === $etapeIndex ? 'is-active' : ($index < $etapeIndex ? 'is-done' : '') ?>">
      <span class="wizard-num"><?= $index + 1 ?></span>
      <span class="wizard-name"><?= e(t('sec.wizard.step.' . $nom)) ?></span>
    </li>
  <?php endforeach; ?>
</ol>

<?php if ($etape === 'decouverte'): ?>
  <div class="card">
    <div class="card-head"><h2><?= e(t('sec.wizard.whatIs', ['section' => t($groupe['titre'])])) ?></h2></div>
    <p class="hint"><?= e(t('sec.' . $groupe['cle'] . '.intro')) ?></p>
    <div class="tiles">
      <?php foreach ($services as $service): ?>
        <span class="tile is-static">
          <span class="tile-icon"><?= \App\Core\View::partial('icon', ['name' => $service['icone'], 'iconSize' => 20]) ?></span>
          <span class="tile-text">
            <span class="tile-name"><?= e(t($service['titre'])) ?></span>
            <span class="tile-what"><?= e(t($service['quoi'])) ?></span>
          </span>
        </span>
      <?php endforeach; ?>
    </div>
    <div class="form-actions">
      <a class="btn btn-primary" href="/section/<?= e((string) $groupe['cle']) ?>/assistant?etape=reglages"><?= e(t('sec.wizard.next')) ?></a>
    </div>
  </div>

<?php elseif ($etape === 'reglages'): ?>
  <div class="card">
    <div class="card-head"><h2><?= e(t('sec.wizard.settings')) ?></h2></div>
    <p class="hint"><?= e(t('sec.wizard.settingsHelp')) ?></p>
    <form method="POST" action="/section/<?= e((string) $groupe['cle']) ?>/assistant" class="form-grid">
      <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>" />
      <?= \App\Core\View::partial('champs-section', ['groupe' => $groupe, 'champs' => $champs]) ?>
      <div class="span-2 form-actions">
        <a class="btn" href="/section/<?= e((string) $groupe['cle']) ?>/assistant?etape=decouverte"><?= e(t('common.back')) ?></a>
        <button type="submit" class="btn btn-primary"><?= e(t('sec.wizard.finish')) ?></button>
      </div>
    </form>
  </div>

  <?php /* « Garder les valeurs par défaut » est une réponse, pas un abandon :
           la section est marquée configurée et l'assistant ne revient plus. */ ?>
  <div class="card">
    <div class="card-head"><h3><?= e(t('sec.wizard.skipTitle')) ?></h3></div>
    <p class="hint"><?= e(t('sec.wizard.skipHelp')) ?></p>
    <form method="POST" action="/section/<?= e((string) $groupe['cle']) ?>/assistant">
      <input type="hidden" name="_csrf" value="<?= e($csrfToken) ?>" />
      <input type="hidden" name="passer" value="1" />
      <button type="submit" class="btn"><?= e(t('sec.wizard.skip')) ?></button>
    </form>
  </div>

<?php else: ?>
  <div class="card">
    <div class="card-head"><h2><?= e(t('sec.wizard.done', ['section' => t($groupe['titre'])])) ?></h2></div>
    <p class="hint"><?= e(t('sec.wizard.doneHelp')) ?></p>
    <?= \App\Core\View::partial('recap-section', ['groupe' => $groupe, 'champs' => $champs]) ?>
    <div class="form-actions">
      <a class="btn btn-primary" href="/section/<?= e((string) $groupe['cle']) ?>"><?= e(t('sec.wizard.open', ['section' => t($groupe['titre'])])) ?></a>
      <a class="btn" href="/section/<?= e((string) $groupe['cle']) ?>/reglages"><?= e(t('sec.settings.title')) ?></a>
    </div>
  </div>
<?php endif; ?>
