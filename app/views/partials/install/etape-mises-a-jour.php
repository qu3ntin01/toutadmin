<?php
/**
 * Les mises à jour.
 *
 * Installer une version dépassée est une mauvaise façon de commencer : on pose
 * un CMS dont on sait déjà qu'il lui manque des correctifs, et la première
 * chose qu'on demandera au client sera de le mettre à jour. Autant le faire
 * avant qu'il n'y ait des données dedans.
 *
 * Une version en retard bloque. Un éditeur injoignable, non : un client derrière
 * un pare-feu d'entreprise doit pouvoir installer son CMS, et transformer une
 * panne de réseau en produit inutilisable serait une faute bien plus grave que
 * de laisser poser une version d'un mois.
 */
$brouillon = $brouillon ?? [];
?>
<?php /* Pas de champ d'adresse : l'éditeur d'un CMS ne change pas d'une
         installation à l'autre. Le demander revenait à poser une question dont
         le client n'a pas la réponse, et dont une mauvaise réponse se solde par
         « injoignable » sans qu'il sache pourquoi. */ ?>

<div class="licence-check" data-maj-check
     data-labels="<?= e((string) json_encode([
      'checking' => t('install.maj.checking'),
      'uptodate' => t('install.maj.uptodate'),
      'behind' => t('install.maj.behind'),
      'unreachable' => t('install.maj.unreachable'),
      'unreachableDetail' => t('install.maj.unreachableDetail'),
      'posing' => t('install.maj.posing'),
      'posed' => t('install.maj.posed'),
      'failed' => t('install.maj.failed'),
      'needKey' => t('install.maj.needKey'),
  ], JSON_UNESCAPED_UNICODE)) ?>">
  <p class="licence-check-say" data-maj-say role="status" aria-live="polite"></p>
</div>

<?php /* Le champ ne sert qu'à télécharger, et n'apparaît donc qu'au moment où
         l'on sait qu'il va servir. La clé est ensuite reportée à l'étape
         « Licence », qui n'a pas à la redemander. */ ?>
<label class="install-field" data-maj-cle hidden>
  <span><?= e(t('licence.install.key')) ?></span>
  <input name="licence_cle" value="<?= e((string) ($brouillon['licence_cle'] ?? '')) ?>" autocomplete="off">
  <span class="field-hint"><?= e(t('install.maj.needKeyHint')) ?></span>
</label>
