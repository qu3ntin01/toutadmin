<?php
/* Le bandeau de licence, posé sous l'en-tête de chaque écran.

   Il ne s'affiche que quand il a quelque chose à dire : une instance dont la
   licence est en cours ne voit rien du tout. Le ton monte d'un cran à chaque
   palier, et chaque palier dit ce qu'il faut faire — un bandeau qui alarme sans
   indiquer la sortie ne sert qu'à inquiéter. */

$etat = \App\Modules\Licence::etat();
$admin = ($utilisateur['role'] ?? '') === 'admin';
?>
<?php if ($etat['phase'] !== \App\Modules\Licence::VALIDE): ?>
  <?php if ($etat['phase'] === \App\Modules\Licence::SURSIS): ?>
    <?php /* Le sursis : la licence couvre encore, mais la vérification échoue.
             Quarante-huit heures, et on le dit en heures — « il reste 2 jours »
             sur un compte à rebours de deux jours se lit comme « on a le
             temps », alors qu'il faut appeler aujourd'hui. */ ?>
    <div class="flash flash-warning licence-banner" role="alert">
      <span>
        <strong><?= e(t('licence.sursisTitle')) ?></strong>
        <?= e(t('licence.sursisBody', ['heures' => (string) ($etat['heures_de_sursis'] ?? 0)])) ?>
      </span>
      <?php if ($admin): ?><a href="/licence" class="btn btn-sm"><?= e(t('licence.open')) ?></a><?php endif; ?>
    </div>
  <?php elseif ($etat['phase'] === \App\Modules\Licence::GRACE): ?>
    <div class="flash flash-warning licence-banner" role="status">
      <span>
        <strong><?= e(t('licence.graceTitle')) ?></strong>
        <?= e(t('licence.graceBody', ['jours' => (string) ($etat['jours_de_grace'] ?? 0)])) ?>
      </span>
      <?php if ($admin): ?><a href="/licence" class="btn btn-sm"><?= e(t('licence.open')) ?></a><?php endif; ?>
    </div>
  <?php else: ?>
    <?php /* « absente » et « restreint » disent la même chose à l'utilisateur —
             l'instance n'écrit plus — mais pas pour la même raison, et l'écran
             de licence saura la nommer précisément. */ ?>
    <div class="flash flash-error licence-banner" role="alert">
      <span>
        <strong><?= e(t('licence.readOnlyTitle')) ?></strong>
        <?= e(t('licence.readOnlyBody')) ?>
      </span>
      <?php if ($admin): ?><a href="/licence" class="btn btn-sm"><?= e(t('licence.open')) ?></a><?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
