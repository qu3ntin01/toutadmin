(function () {
  /**
   * Confirmations.
   *
   * window.confirm() rendait le service, mais au prix d'une boîte grise du
   * système, précédée de « exemple.com indique… », dans la langue du
   * navigateur et non dans celle de l'instance. Cent vingt-sept endroits
   * l'appelaient : c'était, de loin, l'écran le plus vu du produit.
   *
   * Ce qui suit la remplace par une fenêtre à nous. Trois obligations, sans
   * lesquelles une jolie boîte serait une régression sur la boîte grise :
   * le clavier ne doit pas pouvoir sortir de la fenêtre, l'échappement doit
   * la fermer, et le foyer doit revenir d'où il venait.
   */

  var ouverte = null;
  var mots = null;

  /** Les libellés, publiés par l'en-tête commun dans la langue de la page. */
  function textes() {
    if (mots) return mots;
    mots = { confirmer: 'OK', annuler: 'Annuler', titre: '' };
    try {
      var source = document.getElementById('dialog-labels');
      if (source) {
        var lus = JSON.parse(source.textContent);
        mots = { confirmer: lus.confirm, annuler: lus.cancel, titre: lus.title };
      }
    } catch (e) {
      /* Dictionnaire illisible : on garde les valeurs de repli plutôt que de
         priver la page de ses confirmations. */
    }
    return mots;
  }

  /** Les éléments qui peuvent recevoir le foyer, dans l'ordre du document. */
  function focusables(racine) {
    return Array.prototype.filter.call(
      racine.querySelectorAll('button, [href], input, select, textarea, [tabindex]'),
      function (el) { return !el.disabled && el.tabIndex !== -1 && el.offsetParent !== null; }
    );
  }

  function fermer(fond, rendreAu) {
    if (ouverte !== fond) return;
    ouverte = null;
    document.body.classList.remove('dialog-open');
    fond.remove();
    if (rendreAu && document.contains(rendreAu)) rendreAu.focus();
  }

  /**
   * Ouvre la fenêtre et appelle `suite` si la personne confirme. Rien n'est
   * rendu : une confirmation n'a pas de valeur à rapporter, seulement une
   * suite à déclencher.
   */
  function demander(message, destructrice, suite) {
    if (ouverte) return;

    var libelles = textes();
    var venaitDe = document.activeElement;

    var fond = document.createElement('div');
    fond.className = 'dialog-backdrop';

    var boite = document.createElement('div');
    boite.className = 'dialog' + (destructrice ? ' dialog-danger' : '');
    boite.setAttribute('role', 'dialog');
    boite.setAttribute('aria-modal', 'true');

    if (libelles.titre) {
      var titre = document.createElement('h2');
      titre.className = 'dialog-title';
      titre.id = 'dialog-titre';
      titre.textContent = libelles.titre;
      boite.appendChild(titre);
      boite.setAttribute('aria-labelledby', 'dialog-titre');
    }

    var texte = document.createElement('p');
    texte.className = 'dialog-text';
    texte.id = 'dialog-texte';
    texte.textContent = message;
    boite.appendChild(texte);
    boite.setAttribute('aria-describedby', 'dialog-texte');

    var actions = document.createElement('div');
    actions.className = 'dialog-actions';

    var annuler = document.createElement('button');
    annuler.type = 'button';
    annuler.className = 'btn';
    annuler.textContent = libelles.annuler;

    var valider = document.createElement('button');
    valider.type = 'button';
    valider.className = 'btn ' + (destructrice ? 'btn-destroy' : 'btn-primary');
    valider.textContent = libelles.confirmer;

    actions.appendChild(annuler);
    actions.appendChild(valider);
    boite.appendChild(actions);
    fond.appendChild(boite);

    annuler.addEventListener('click', function () { fermer(fond, venaitDe); });
    valider.addEventListener('click', function () {
      fermer(fond, venaitDe);
      suite();
    });

    // Cliquer à côté revient à renoncer — jamais à confirmer.
    fond.addEventListener('mousedown', function (e) {
      if (e.target === fond) fermer(fond, venaitDe);
    });

    fond.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        e.preventDefault();
        fermer(fond, venaitDe);
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
    ouverte = fond;

    // Le foyer part sur « annuler » : la touche Entrée ne doit pas valider
    // une suppression parce que quelqu'un tapait vite.
    annuler.focus();
  }

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.dataset || !form.dataset.confirm) return;
    if (form.dataset.confirmed === 'oui') return;

    e.preventDefault();

    // Le bouton qui a envoyé dit si l'acte détruit quelque chose.
    var bouton = form.querySelector('button[type="submit"], button:not([type])');
    var destructrice = /\bbtn-danger\b|\bbtn-destroy\b/.test((bouton && bouton.className) || '')
      || form.dataset.confirmDanger === 'oui';

    demander(form.dataset.confirm, destructrice, function () {
      form.dataset.confirmed = 'oui';
      if (typeof form.requestSubmit === 'function') form.requestSubmit(bouton || undefined);
      else form.submit();
    });
  });
})();
