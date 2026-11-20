<?php
/**
 * La licence.
 *
 * La clé se saisit ici, mais rien n'est activé : l'activation attend le dernier
 * palier, quand la base existe pour porter l'empreinte de l'installation.
 * Valider maintenant lierait la licence à une instance qui n'existe pas encore,
 * et une installation abandonnée en cours de route en consommerait une.
 */
$brouillon = $brouillon ?? [];

/* Où le client va chercher sa licence, et où il lit les offres. Les deux
   adresses sont connues et ne se demandent pas ; posées et vides, elles
   retirent le lien correspondant — une instance fermée n'offre pas de porte
   vers dehors. */
$espace = \App\Core\Settings::adresseManager();
$vitrine = \App\Core\Settings::adresseSite();
?>
<label class="install-field">
  <span><?= e(t('licence.install.key')) ?></span>
  <input name="licence_cle" value="<?= e((string) ($brouillon['licence_cle'] ?? '')) ?>" autocomplete="off" autofocus>
  <span class="field-hint"><?= e(t('licence.install.keyHint')) ?></span>
</label>

<?php
/*
 * Trois chemins pour remplir le champ ci-dessus, et aucun bouton « Activer » :
 * la clé est éprouvée dès qu'elle est là, qu'elle soit collée à la main ou
 * rapportée par la fenêtre. Un bouton de plus n'aurait rien appris à personne.
 *
 * Les deux premiers ouvrent une fenêtre sur l'espace client. Le mot de passe
 * du client s'y tape sur l'hébergement de l'espace, sous son certificat —
 * jamais dans ce CMS-ci, qui vient d'être déballé, souvent sur un hébergement
 * qu'on découvre et parfois encore en clair. La clé revient par
 * « postMessage », et le script vérifie l'origine du message avant de la poser
 * dans le champ.
 *
 * Le troisième part sur le site, dans un onglet : on va y lire, pas y faire —
 * et une lecture ne doit pas coûter l'assistant en cours.
 */
?>
<?php if ($espace !== '' || $vitrine !== ''): ?>
  <ul class="licence-liens" data-licence-liens
      data-manager="<?= e($espace) ?>"
      data-edition="php"
      data-labels="<?= e((string) json_encode([
        'opening' => t('licence.install.linkOpening'),
        'blocked' => t('licence.install.linkBlocked'),
        'received' => t('licence.install.linkReceived'),
      ], JSON_UNESCAPED_UNICODE)) ?>">
    <?php
    /*
     * « target="_blank" » et pas de « rel="noopener" », ce qui est délibéré et
     * mérite d'être dit : c'est justement par « window.opener » que la clé
     * revient. Le couper fermerait le chemin de retour. La page ouverte est la
     * nôtre, sur une origine que le script vérifie avant d'écouter quoi que ce
     * soit — ce n'est pas un site tiers à qui l'on prête une prise.
     *
     * L'adresse portée par le lien est nue. Le script y ajoute l'origine de
     * retour et l'édition au chargement : elles ne se connaissent qu'ici, dans
     * le navigateur. Sans script, le lien mène quand même à l'espace client,
     * où la clé se copie à la main — un chemin plus long, mais un chemin.
     */
    ?>
    <?php if ($espace !== ''): ?>
      <li>
        <a href="<?= e($espace) ?>/installeur" target="_blank" data-licence-fenetre="/installeur">
          <span class="licence-lien-titre"><?= e(t('licence.install.linkMine')) ?></span>
          <span class="licence-lien-quoi"><?= e(t('licence.install.linkMineWhat')) ?></span>
        </a>
      </li>
      <li>
        <a href="<?= e($espace) ?>/installeur/gratuite" target="_blank" data-licence-fenetre="/installeur/gratuite">
          <span class="licence-lien-titre"><?= e(t('licence.install.linkFree')) ?></span>
          <span class="licence-lien-quoi"><?= e(t('licence.install.linkFreeWhat')) ?></span>
        </a>
      </li>
    <?php endif; ?>
    <?php if ($vitrine !== ''): ?>
      <li>
        <a href="<?= e($vitrine) ?>/tarifs.html" target="_blank" rel="noopener noreferrer">
          <span class="licence-lien-titre"><?= e(t('licence.install.linkOffers')) ?></span>
          <span class="licence-lien-quoi"><?= e(t('licence.install.linkOffersWhat')) ?></span>
        </a>
      </li>
    <?php endif; ?>
  </ul>
<?php endif; ?>

<?php /* Pas de champ d'adresse : l'éditeur d'un CMS ne change pas d'une
         installation à l'autre. Le demander revenait à poser une question dont
         le client n'a pas la réponse, et dont une mauvaise réponse se solde par
         « injoignable » sans qu'il sache pourquoi. */ ?>

<label class="install-field">
  <span><?= e(t('licence.publicAddress')) ?></span>
  <input name="licence_adresse" value="<?= e((string) ($brouillon['licence_adresse'] ?? '')) ?>" placeholder="https://portail.entreprise.fr">
  <span class="field-hint"><?= e(t('licence.publicAddressHint')) ?></span>
</label>

<details class="install-details">
  <summary><?= e(t('licence.install.offlineSwitch')) ?></summary>
  <label class="install-field">
    <span><?= e(t('licence.offlineToken')) ?></span>
    <textarea name="licence_jeton" rows="5" placeholder="eyJ2IjoxLCJpZCI6…"><?= e((string) ($brouillon['licence_jeton'] ?? '')) ?></textarea>
    <span class="field-hint"><?= e(t('licence.offlineTokenHint')) ?></span>
  </label>
</details>

<?php
/* Le compte rendu de l'activation, près des champs, où il a la place de
   s'expliquer. La barre d'attente, elle, est en bas avec « Suivant » : c'est
   là qu'on regarde quand on veut avancer.

   L'activation n'engage rien : la route d'essai du panneau ne lie aucune
   licence, et l'instance n'existe pas encore. Elle peut donc repartir autant
   de fois qu'il le faut. Tant qu'elle n'a pas dit oui, « Suivant » reste
   gris — c'est ici qu'on veut découvrir qu'une clé est refusée, et non une
   fois les données du client dedans. */
$faite = !empty($brouillon['licence_verifiee']);
?>
<div class="licence-check<?= $faite ? ' is-ok' : '' ?>" data-licence-check
     data-labels="<?= e((string) json_encode([
      'checking' => t('licence.install.verifying'),
      'valid' => t('licence.install.valid'),
      'invalid' => t('licence.install.invalid'),
      'bound' => t('licence.install.bound'),
      'members' => t('licence.install.validMembers'),
      'unreachable' => t('licence.install.unreachable'),
      'unreachableDetail' => t('licence.install.unreachableDetail'),
  ], JSON_UNESCAPED_UNICODE)) ?>">
  <p class="licence-check-say" data-licence-say role="status" aria-live="polite"><?php
    if ($faite) {
        echo e(t('licence.install.valid')) . ' '
            . e(t('licence.install.validMembers',
                  ['membres' => (string) ($brouillon['licence_membres'] ?? 0)]));
    }
  ?></p>
</div>

<?php /* Continuer sans licence reste possible : une instance non activée n'est
         pas bloquée, elle est seulement non activée, et son écran « Licence »
         la rattrapera. Ce chemin-là n'a rien à vérifier. */ ?>
<button type="submit" name="passer" value="1" class="btn btn-quiet install-skip" data-licence-skip>
  <?= e(t('licence.install.skip')) ?>
</button>
<span class="field-hint"><?= e(t('licence.install.skipHint')) ?></span>
