<!doctype html>
<html lang="<?= e($locale ?? 'fr') ?>" dir="<?= e($localeDir ?? 'ltr') ?>" data-palette="<?= e($palette ?? 'lavande') ?>">
<head>
<?= \App\Core\View::partial('head', ['title' => $title, 'nonce' => $nonce]) ?>
</head>
<body class="install-scene">

  <!-- Une fenêtre posée au-dessus de la page, et non une page de plus : on
       n'est pas encore dans le produit, on est en train de l'installer. Le
       fond reste visible, assombri — la fenêtre a quelque chose derrière elle,
       ce qui suffit à la faire flotter. -->
  <div class="install-window" role="dialog" aria-labelledby="install-heading">

    <?php
      $rang = (int) $rang;
      $total = count($etapes);
      // L'avancement : les étapes franchies, pas celle qu'on regarde. Sur le
      // dernier écran, la barre repart de là et va jusqu'au bout, palier par
      // palier — elle ne recommence pas à zéro, l'installation non plus.
      $avancement = (int) round($rang / $total * 100);
      $icones = [
          'langue' => 'network', 'prerequis' => 'shield', 'mises-a-jour' => 'tool',
          'licence' => 'ticket', 'base' => 'database',
          'entreprise' => 'briefcase', 'sections' => 'grid', 'administrateur' => 'user', 'installation' => 'rocket',
      ];
      // Le rail porte des noms courts ; l'écran, le titre complet. Ce sont les
      // clés que l'autre édition emploie déjà — un seul dictionnaire pour les
      // deux assistants, et une seule traduction à tenir à jour.
      $titres = [
          'langue' => 'install.language.title', 'prerequis' => 'install.checks.title',
          'mises-a-jour' => 'install.maj.title',
          'licence' => 'licence.install.title',
          'base' => 'install.db.title', 'entreprise' => 'install.company.title',
          'sections' => 'install.sections.title',
          'administrateur' => 'install.admin.title', 'installation' => 'install.done.title',
      ];
      // Les clés sont écrites en toutes lettres plutôt que composées : le
      // vérificateur de dictionnaire lit le code, et une clé bâtie par
      // concaténation lui échappe — donc une traduction manquante aussi.
      $noms = [
          'langue' => 'install.steps.langue', 'prerequis' => 'install.steps.prerequis',
          'mises-a-jour' => 'install.maj.title',
          'licence' => 'licence.install.title',
          'base' => 'install.steps.base', 'entreprise' => 'install.steps.entreprise',
          'sections' => 'install.steps.sections',
          'administrateur' => 'install.steps.administrateur', 'installation' => 'install.steps.installation',
      ];
      $indices = [
          'langue' => 'install.hint.langue', 'prerequis' => 'install.hint.prerequis',
          'mises-a-jour' => 'install.hint.maj',
          'licence' => 'install.hint.licence',
          'base' => 'install.hint.base', 'entreprise' => 'install.hint.entreprise',
          'sections' => 'install.hint.sections',
          'administrateur' => 'install.hint.administrateur', 'installation' => 'install.hint.installation',
      ];
      $sous = [
          'langue' => 'install.language.help', 'prerequis' => 'install.checks.help',
          'mises-a-jour' => 'install.maj.lede',
          'licence' => 'licence.install.lede',
          'base' => 'install.db.help', 'entreprise' => 'install.company.help',
          'sections' => 'install.sections.help',
          'administrateur' => 'install.admin.help', 'installation' => 'install.done.help',
      ];
    ?>

    <div class="install-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100"
         aria-valuenow="<?= $avancement ?>" aria-label="<?= e(t('install.progress')) ?>">
      <!-- La largeur vient d'une classe, jamais d'un attribut « style » : la
           politique de contenu n'autorise aucun style en ligne, et une barre
           d'avancement qui ne s'affiche pas est pire que pas de barre. -->
      <span class="install-progress-fill" data-progress data-at="<?= $rang ?>"></span>
    </div>

    <aside class="install-aside">
      <div class="install-brand">
        <span class="brand-mark">TA</span>
        <span class="brand-text">
          <span class="brand-name"><?= e(t('app.name')) ?></span>
          <span class="brand-panel"><?= e(t('install.title')) ?> · v<?= e($version) ?></span>
        </span>
      </div>

      <ol class="install-steps">
        <?php foreach ($etapes as $i => $nom): ?>
          <?php $etat = $i < $rang ? 'is-done' : ($i === $rang ? 'is-current' : 'is-todo'); ?>
          <li class="install-step <?= $etat ?>">
            <span class="step-badge" aria-hidden="true">
              <?php if ($i < $rang): ?>
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7" /></svg>
              <?php else: ?>
                <?= \App\Core\View::partial('icon', ['name' => $icones[$nom], 'iconSize' => 15]) ?>
              <?php endif; ?>
            </span>
            <span class="step-text">
              <span class="step-name"><?= e(t($noms[$nom])) ?></span>
              <span class="step-hint"><?= e(t($indices[$nom])) ?></span>
            </span>
          </li>
        <?php endforeach; ?>
      </ol>

      <p class="install-aside-foot"><?= e(t('install.onceOnly')) ?></p>
    </aside>

    <section class="install-stage">
      <header class="stage-head">
        <p class="stage-count"><?= e(t('install.stepOf', ['n' => $rang + 1, 'total' => $total])) ?></p>
        <h1 id="install-heading"><?= e(t($titres[$etape])) ?></h1>
        <p class="stage-sub"><?= e(t($sous[$etape])) ?></p>
      </header>

      <?= \App\Core\View::partial('flash', ['flash' => $flash ?? null]) ?>

      <?php if ($etape === 'installation'): ?>
        <?= \App\Core\View::partial('install/etape-installation', get_defined_vars()) ?>
      <?php else: ?>
        <form method="POST" action="/install/<?= e($etape) ?>" class="install-form">
          <?= \App\Core\Csrf::field() ?>
          <?= \App\Core\View::partial('install/etape-' . $etape, get_defined_vars()) ?>

          <div class="install-actions">
            <?php if ($rang > 0): ?>
              <a class="btn btn-ghost" href="/install/<?= e($etapes[$rang - 1]) ?>"><?= e(t('install.back')) ?></a>
            <?php else: ?>
              <span></span>
            <?php endif; ?>
            <?php /* À l'étape « Licence », « Suivant » naît désactivé et n'est
                     rendu qu'une fois la licence acceptée. Un retour en arrière
                     retrouve cet état-là plutôt que de refaire le tour. */ ?>
            <?php if ($etape === 'mises-a-jour'): ?>
              <span class="install-activate">
                <progress class="licence-check-bar" data-maj-bar max="100" value="0" hidden></progress>
                <button type="button" class="btn" data-maj-poser hidden>
                  <?= e(t('install.maj.update')) ?>
                </button>
              </span>
            <?php endif; ?>
            <?php /* Plus de bouton « Activer » : la clé est éprouvée dès qu'elle
                     est posée dans le champ, collée à la main ou rapportée par
                     la fenêtre de l'espace client. Il reste la barre, qui dit
                     qu'on attend le panneau. */ ?>
            <?php if ($etape === 'licence'): ?>
              <span class="install-activate">
                <progress class="licence-check-bar" data-licence-bar max="100" value="0" hidden></progress>
              </span>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary" data-install-next
              <?= ($etape === 'licence' && empty($brouillon['licence_verifiee']))
                  || $etape === 'mises-a-jour' ? 'disabled' : '' ?>>
              <?= e(t('install.next')) ?>
            </button>
          </div>
        </form>
      <?php endif; ?>
    </section>
  </div>

  <script src="<?= e(asset('/js/theme.js')) ?>" nonce="<?= e($nonce) ?>"></script>
  <script src="<?= e(asset('/js/installation.js')) ?>" nonce="<?= e($nonce) ?>"></script>
</body>
</html>
