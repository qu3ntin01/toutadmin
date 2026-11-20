(function () {
  /**
   * Deux gains de place, pris sur le DOM existant plutôt que dans les
   * gabarits : le même motif est repris par cinquante-sept panneaux de
   * création et vingt écrans à statut, et les réécrire un par un aurait été
   * cinquante-sept occasions de casser quelque chose.
   *
   * Ce fichier n'est qu'une amélioration : sans lui — script bloqué, erreur
   * de chargement — les écrans restent exactement ce qu'ils étaient, avec
   * leur formulaire déplié et leur bouton « enregistrer ». Rien ne dépend de
   * lui pour fonctionner.
   */

  // ------------------------------------------------------------------ statut
  /**
   * Une ligne montrait son statut deux fois : une pastille dans sa colonne, et
   * une liste déroulante portant la même valeur dans les actions. La liste
   * prend la place de la pastille, en garde la couleur, et s'applique au
   * changement — le bouton qui l'accompagnait n'a plus lieu d'être.
   */
  function fondreLesStatuts(racine) {
    racine.querySelectorAll('tbody tr').forEach(function (ligne) {
      var pastille = ligne.querySelector('td > .status');
      var liste = ligne.querySelector('td.actions select[name="status"]');
      if (!pastille || !liste) return;

      var form = liste.form;
      if (!form) return;

      // La couleur de la pastille dit l'état : la liste la reprend, sinon on
      // perdrait le seul repère qui se lit sans rien lire.
      var teinte = Array.prototype.filter.call(pastille.classList, function (c) {
        return c.indexOf('status-') === 0;
      });

      // Relevée avant le déplacement : après, la liste n'est plus dedans.
      var celluleActions = liste.parentNode;
      while (celluleActions && celluleActions.tagName !== 'TD') celluleActions = celluleActions.parentNode;

      var cellule = pastille.parentNode;
      liste.classList.add('status-select');
      teinte.forEach(function (c) { liste.classList.add(c); });
      liste.dataset.avant = liste.value;

      cellule.replaceChild(form, pastille);
      form.classList.add('status-form');

      // Le bouton devient inutile : le changement vaut validation.
      var bouton = form.querySelector('button[type="submit"], button:not([type])');
      if (bouton) bouton.hidden = true;

      // La colonne d'actions n'a plus à loger une liste et son bouton.
      if (celluleActions) celluleActions.classList.add('est-allegee');

      var envoiEnCours = false;
      liste.addEventListener('change', function () {
        if (envoiEnCours || liste.value === liste.dataset.avant) return;

        // Pendant l'aller-retour, la liste ne doit pas repartir : deux
        // changements coup sur coup enverraient deux requêtes dont on ne
        // saurait pas l'ordre d'arrivée.
        //
        // Mais on ne la désactive pas pour autant : un champ désactivé n'est
        // pas transmis, et le statut ne partirait jamais. C'est l'attente qui
        // se voit, pas le champ qui se ferme.
        envoiEnCours = true;
        liste.classList.add('is-sending');
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
      });
    });
  }

  // ------------------------------------------------- panneau de création
  /**
   * Le formulaire de création occupait en permanence un tiers de l'écran, y
   * compris les neuf fois sur dix où l'on vient consulter. Il passe derrière
   * un bouton qui porte son propre titre.
   */
  function replierLesCreations(racine) {
    racine.querySelectorAll('.grid-2').forEach(function (grille, rang) {
      var aside = grille.firstElementChild;
      if (!aside || !aside.classList.contains('card')) return;
      if (!aside.querySelector('form[method="POST" i]')) return;

      // Les deux éditions ne titrent pas leurs cartes au même niveau : « h3 »
      // dans l'une, « h2 » dans l'autre. On prend le premier titre venu plutôt
      // que d'imposer un niveau, faute de quoi la moitié des panneaux d'une
      // édition resterait dépliée sans que rien ne le signale.
      var titre = aside.querySelector('h2, h3');
      if (!titre) return;

      var bouton = document.createElement('button');
      bouton.type = 'button';
      bouton.className = 'btn btn-primary create-toggle';
      bouton.textContent = titre.textContent.trim();
      bouton.setAttribute('aria-expanded', 'false');

      var id = 'creation-' + rang;
      aside.id = aside.id || id;
      bouton.setAttribute('aria-controls', aside.id);

      grille.classList.add('grid-2-replie');
      grille.parentNode.insertBefore(bouton, grille);

      function poser(ouvert) {
        grille.classList.toggle('grid-2-replie', !ouvert);
        bouton.setAttribute('aria-expanded', ouvert ? 'true' : 'false');
        bouton.classList.toggle('is-open', ouvert);
      }

      bouton.addEventListener('click', function () {
        var ouvert = bouton.getAttribute('aria-expanded') === 'true';
        poser(!ouvert);
        if (!ouvert) {
          // Le premier champ visible : le jeton anti-falsification est un
          // champ caché, et le foyer resterait sur le bouton.
          var premier = aside.querySelector('input:not([type="hidden"]), select, textarea');
          if (premier) premier.focus();
        }
      });

      // Un refus renvoie la page avec le formulaire vidé : le rouvrir d'office
      // évite de chercher où est passé ce qu'on était en train de faire.
      if (document.querySelector('.flash-error')) poser(true);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    fondreLesStatuts(document);
    replierLesCreations(document);
  });
})();
