(function () {
  /**
   * La fenêtre flottante, et rien d'autre.
   *
   * Ce fichier ne sait pas ce qu'on met dedans : il sait ouvrir une fenêtre
   * par-dessus la page, la fermer, et tenir les trois obligations sans
   * lesquelles une jolie boîte est une régression sur la boîte grise du
   * système — le clavier ne doit pas pouvoir en sortir, l'échappement doit la
   * fermer, et le foyer doit revenir d'où il venait.
   *
   * Il existe parce que trois écrans en avaient besoin : les mises à jour, la
   * boutique et les modules optionnels. Trois fenêtres écrites séparément
   * auraient eu trois comportements au clavier, et deux d'entre elles auraient
   * fini par être la mauvaise.
   */

  var ouverte = null;

  /** Les éléments qui peuvent recevoir le foyer, dans l'ordre du document. */
  function focusables(racine) {
    return Array.prototype.filter.call(
      racine.querySelectorAll('button, [href], input, select, textarea, [tabindex]'),
      function (el) { return !el.disabled && el.tabIndex !== -1 && el.offsetParent !== null; }
    );
  }

  /**
   * Ouvre une fenêtre.
   *
   * `options.remplir(corps, fenetre)` reçoit le corps vide et la poignée : à
   * lui d'y mettre ce qu'il veut. `options.actions` est la rangée du bas.
   * Rend la poignée, pour que l'appelant puisse refaire le corps sans
   * refermer — c'est ce qui permet d'enchaîner des étapes dans la même
   * fenêtre au lieu d'en ouvrir une nouvelle à chaque fois.
   */
  function ouvrir(options) {
    var reglages = options || {};
    if (ouverte) ouverte.fermer();

    var venaitDe = document.activeElement;
    var identifiant = 'fenetre-titre-' + Date.now();

    var fond = document.createElement('div');
    fond.className = 'dialog-backdrop';

    var boite = document.createElement('div');
    boite.className = 'dialog fenetre' + (reglages.large ? ' fenetre-large' : '') + (reglages.classe ? ' ' + reglages.classe : '');
    boite.setAttribute('role', 'dialog');
    boite.setAttribute('aria-modal', 'true');
    boite.setAttribute('aria-labelledby', identifiant);

    var entete = document.createElement('div');
    entete.className = 'fenetre-tete';

    var titre = document.createElement('h2');
    titre.className = 'dialog-title';
    titre.id = identifiant;
    titre.textContent = reglages.titre || '';
    entete.appendChild(titre);

    // La croix ne remplace pas l'échappement : elle le double, pour la souris.
    var croix = document.createElement('button');
    croix.type = 'button';
    croix.className = 'icon-btn fenetre-croix';
    croix.setAttribute('aria-label', reglages.fermerLabel || 'Fermer');
    croix.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>';
    entete.appendChild(croix);

    var corps = document.createElement('div');
    corps.className = 'fenetre-corps';

    var actions = document.createElement('div');
    actions.className = 'dialog-actions';

    boite.appendChild(entete);
    boite.appendChild(corps);
    boite.appendChild(actions);
    fond.appendChild(boite);

    var poignee = {
      corps: corps,
      actions: actions,
      /** Change le titre en cours de route : l'étape suivante a un autre nom. */
      titrer: function (texte) { titre.textContent = texte; },
      /** Vide le corps pour y remettre autre chose, sans refermer. */
      vider: function () { corps.textContent = ''; actions.textContent = ''; return poignee; },
      /**
       * Marque la fenêtre occupée : les boutons se désactivent et un lecteur
       * d'écran est prévenu que quelque chose est en cours.
       */
      occuper: function (oui) {
        boite.classList.toggle('fenetre-occupee', Boolean(oui));
        corps.setAttribute('aria-busy', oui ? 'true' : 'false');
        Array.prototype.forEach.call(actions.querySelectorAll('button'), function (b) {
          if (!b.dataset.toujoursActif) b.disabled = Boolean(oui);
        });
        return poignee;
      },
      fermer: function () {
        if (ouverte !== poignee) return;
        ouverte = null;
        document.body.classList.remove('dialog-open');
        fond.remove();
        if (typeof reglages.surFermeture === 'function') reglages.surFermeture();
        if (venaitDe && document.contains(venaitDe)) venaitDe.focus();
      },
      /**
       * Ajoute un bouton à la rangée du bas et le rend.
       * `principal` le met en avant ; `toujoursActif` le laisse cliquable
       * pendant une opération — c'est ce qu'il faut pour « Fermer ».
       */
      bouton: function (texte, options) {
        var reglagesBouton = options || {};
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn ' + (reglagesBouton.classe || (reglagesBouton.principal ? 'btn-primary' : ''));
        b.textContent = texte;
        if (reglagesBouton.toujoursActif) b.dataset.toujoursActif = '1';
        if (typeof reglagesBouton.faire === 'function') b.addEventListener('click', reglagesBouton.faire);
        actions.appendChild(b);
        return b;
      },
    };

    croix.addEventListener('click', poignee.fermer);

    // Cliquer à côté revient à renoncer. Pendant une opération, non : on ne
    // referme pas par mégarde une fenêtre qui est en train d'écrire.
    fond.addEventListener('mousedown', function (e) {
      if (e.target === fond && !boite.classList.contains('fenetre-occupee')) poignee.fermer();
    });

    fond.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        if (!boite.classList.contains('fenetre-occupee')) poignee.fermer();
        return;
      }
      if (e.key !== 'Tab') return;

      // Le foyer tourne en boucle dans la fenêtre : sans cela, la tabulation
      // repart dans la page suspendue derrière, qu'on ne peut plus voir.
      var liste = focusables(boite);
      if (!liste.length) return;
      var premier = liste[0];
      var dernier = liste[liste.length - 1];
      if (e.shiftKey && document.activeElement === premier) {
        e.preventDefault();
        dernier.focus();
      } else if (!e.shiftKey && document.activeElement === dernier) {
        e.preventDefault();
        premier.focus();
      }
    });

    document.body.appendChild(fond);
    document.body.classList.add('dialog-open');
    ouverte = poignee;

    if (typeof reglages.remplir === 'function') reglages.remplir(corps, poignee);

    // Le foyer entre dans la fenêtre : sinon la tabulation commence en haut de
    // la page, derrière le flou.
    var entrants = focusables(boite);
    (entrants.length ? entrants[0] : boite).focus();

    return poignee;
  }

  /** Un paragraphe, parce que chaque écran en écrivait un à la main. */
  function texte(parent, contenu, classe) {
    var p = document.createElement('p');
    p.className = classe || 'dialog-text';
    p.textContent = contenu;
    parent.appendChild(p);
    return p;
  }

  window.Fenetre = { ouvrir: ouvrir, texte: texte, estOuverte: function () { return Boolean(ouverte); } };
}());
