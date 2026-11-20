<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="color-scheme" content="light dark" />
<title><?= e($title ?? t('app.name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('/css/style.css')) ?>" />
<link rel="manifest" href="/manifest.webmanifest" />
<link rel="icon" href="/icone.svg" type="image/svg+xml" />

<?php /* Améliorations d'écran : le statut fondu dans sa pastille, le panneau
         de création replié. Chargé ici plutôt que dans chaque gabarit, et
         différé : rien n'en dépend pour que la page fonctionne. */ ?>
<script src="<?= e(asset('/js/ecrans.js')) ?>" defer nonce="<?= e($nonce) ?>"></script>

<?php /* Les actions de masse : « tout cocher » et le compte de la sélection.
         Deux commodités seulement — cocher les lignes une par une et cliquer
         sur une action marche sans ce fichier, et c'est la condition pour
         qu'une action de masse s'éprouve sans navigateur. */ ?>
<script src="<?= e(asset('/js/masse.js')) ?>" defer nonce="<?= e($nonce) ?>"></script>

<?php /* Les libellés de la fenêtre de confirmation, publiés ici plutôt que
         répétés dans chaque gabarit. Ce n'est pas du script : le navigateur ne
         l'exécute pas, et la politique de sécurité n'a rien à y redire. */ ?>
<script type="application/json" id="dialog-labels"><?= json_encode([
  'confirm' => t('common.confirm'),
  'cancel' => t('common.cancel'),
  'title' => t('common.confirmTitle'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script nonce="<?= e($nonce) ?>">
/* Applique le thème avant le premier rendu pour éviter tout clignotement. */
(function () {
  try {
    var stored = localStorage.getItem('pm-theme') || 'light';
    var resolved = stored === 'system'
      ? (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
      : stored;
    document.documentElement.setAttribute('data-theme', resolved === 'dark' ? 'dark' : 'light');
  } catch (e) {
    document.documentElement.setAttribute('data-theme', 'light');
  }
  // Le rail replié se pose ici aussi : appliqué après coup, on verrait le rail
  // large s'afficher puis se rétracter à chaque page.
  try {
    if (localStorage.getItem('pm-rail') === 'replie') {
      document.documentElement.setAttribute('data-rail', 'replie');
    }
  } catch (e) {
    /* stockage bloqué : le rail reste déplié, ce qui est le cas sûr */
  }

  /*
   * Le pli des groupes du rail, posé ici pour la même raison que les deux
   * au-dessus : appliqué après coup, on verrait les quarante rubriques
   * s'afficher puis se replier sous les yeux de qui les avait pliées.
   *
   * « Rien de gardé » n'est pas « tout est ouvert » : tant que personne n'a
   * plié quoi que ce soit, c'est le défaut qui vaut — tout replié sauf le
   * groupe de la page courante. Replier celui-là aussi cacherait la rubrique
   * allumée, et l'on ne saurait plus où l'on est.
   *
   * Le défaut se calcule ici plutôt que sur le serveur : rendu plié dans le
   * HTML, le rail aurait enfermé qui n'a pas de JavaScript — le bouton d'un
   * groupe ne fait rien sans script. Sans script, le rail reste donc entier.
   */
  try {
    var racine = document.documentElement;
    var mots = function (nom) {
      return (racine.getAttribute(nom) || '').split(' ').filter(Boolean);
    };
    var garde = localStorage.getItem('ta-groupes');
    var actif = racine.getAttribute('data-groupe-actif') || '';
    var fermes;
    if (garde === null) {
      fermes = mots('data-groupes').filter(function (cle) { return cle !== actif; });
    } else {
      fermes = garde.split(' ').filter(Boolean);
    }
    /*
     * Le groupe de la page courante est toujours ouvert.
     *
     * Le pli gardé dans le navigateur vaut pour les autres groupes, et pour
     * eux seuls. Appliqué tel quel, il repliait aussi celui de la page où
     * l'on arrive : qui avait une fois replié un groupe le retrouvait fermé
     * en revenant sur sa propre section, sans plus voir où il se trouvait.
     * Le pli est une préférence de rangement, pas une raison de cacher la
     * rubrique allumée.
     */
    if (actif !== '') {
      fermes = fermes.filter(function (cle) { return cle !== actif; });
    }
    if (fermes.length) racine.setAttribute('data-groupes-fermes', fermes.join(' '));
  } catch (e) {
    /* stockage bloqué : les groupes restent ouverts, ce qui est le cas sûr */
  }
})();
</script>
