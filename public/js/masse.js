(function () {
  /**
   * Les actions de masse, côté écran.
   *
   * Ce fichier n'ajoute aucune capacité : cocher les lignes une par une et
   * cliquer sur une action marche sans lui. Il ajoute deux commodités, et c'est
   * tout ce qu'un script a le droit de faire ici — « tout cocher », et le compte
   * de ce qui est coché, pour qu'on ne valide pas une sélection qu'on croyait
   * plus petite.
   */
  function cases(formulaire) {
    return Array.prototype.filter.call(
      document.querySelectorAll('input[type="checkbox"][name="ids"]'),
      function (c) { return c.getAttribute('form') === formulaire.id; }
    );
  }

  function compter(formulaire) {
    var zone = formulaire.querySelector('[data-masse-count]');
    if (!zone) return;
    var cochees = cases(formulaire).filter(function (c) { return c.checked; }).length;
    if (!cochees) {
      zone.textContent = zone.getAttribute('data-masse-vide') || zone.getAttribute('data-masse-hint') || '';
      return;
    }
    var modele = zone.getAttribute('data-masse-label') || '{n}';
    zone.textContent = modele.replace('{n}', String(cochees));
  }

  Array.prototype.forEach.call(document.querySelectorAll('form.masse'), function (formulaire) {
    var zone = formulaire.querySelector('[data-masse-count]');
    // Le texte d'origine est l'invite : on le garde pour y revenir quand plus
    // rien n'est coché.
    if (zone) zone.setAttribute('data-masse-vide', zone.textContent.trim());

    var lignes = cases(formulaire);
    lignes.forEach(function (c) {
      c.addEventListener('change', function () { compter(formulaire); });
    });

    var tout = document.querySelector('[data-masse-all="' + formulaire.id + '"]');
    if (tout) {
      tout.hidden = false;
      tout.addEventListener('change', function () {
        lignes.forEach(function (c) { c.checked = tout.checked; });
        compter(formulaire);
      });
    }

    compter(formulaire);
  });
}());
