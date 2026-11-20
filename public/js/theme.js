(function () {
  var KEY = 'pm-theme';
  var DEFAULT_PREFERENCE = 'light';
  var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

  function readPreference() {
    try {
      var stored = localStorage.getItem(KEY);
      return stored === 'light' || stored === 'dark' || stored === 'system' ? stored : DEFAULT_PREFERENCE;
    } catch (e) {
      return DEFAULT_PREFERENCE;
    }
  }

  function storePreference(value) {
    try {
      localStorage.setItem(KEY, value);
    } catch (e) {
      /* navigation privée ou stockage bloqué : le thème reste valable pour la page courante */
    }
  }

  // On écrit toujours une valeur concrète sur <html> : la feuille de style
  // n'a donc qu'une palette claire et une palette sombre à maintenir.
  function resolve(preference) {
    if (preference === 'system') return media && media.matches ? 'dark' : 'light';
    return preference;
  }

  function apply(preference) {
    document.documentElement.setAttribute('data-theme', resolve(preference));
    document.querySelectorAll('[data-theme-option]').forEach(function (button) {
      button.classList.toggle('is-active', button.dataset.themeOption === preference);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    apply(readPreference());

    document.querySelectorAll('[data-theme-option]').forEach(function (button) {
      button.addEventListener('click', function () {
        var preference = button.dataset.themeOption;
        storePreference(preference);
        apply(preference);
      });
    });

    // En mode « système », suivre les changements de préférence de l'OS sans rechargement.
    if (media && media.addEventListener) {
      media.addEventListener('change', function () {
        if (readPreference() === 'system') apply('system');
      });
    }
  });
})();

(function () {
  /**
   * Repli du rail de navigation.
   *
   * Deux cent quarante-huit pixels rendus à la page quand on n'a pas besoin de
   * lire les rubriques. Le choix appartient à la personne, pas à l'instance :
   * il vit dans son navigateur et suit ses pages, sans passer par le serveur.
   */
  var CLE = 'pm-rail';

  function replie() {
    return document.documentElement.getAttribute('data-rail') === 'replie';
  }

  function dire(bouton) {
    var ferme = replie();
    var mot = ferme ? bouton.dataset.labelExpand : bouton.dataset.labelCollapse;
    bouton.setAttribute('aria-expanded', ferme ? 'false' : 'true');
    bouton.setAttribute('title', mot);
    var lu = bouton.querySelector('.sr-only');
    if (lu) lu.textContent = mot;
  }

  function basculer(bouton) {
    var ferme = !replie();
    if (ferme) document.documentElement.setAttribute('data-rail', 'replie');
    else document.documentElement.removeAttribute('data-rail');
    try {
      localStorage.setItem(CLE, ferme ? 'replie' : 'ouvert');
    } catch (e) {
      /* stockage bloqué : le repli vaut pour la page courante */
    }
    dire(bouton);
  }

  document.addEventListener('DOMContentLoaded', function () {
    var bouton = document.querySelector('[data-rail-toggle]');
    if (!bouton) return;
    dire(bouton);
    bouton.addEventListener('click', function () { basculer(bouton); });
  });
})();

(function () {
  /**
   * Le pli des groupes du rail : ce qui répond au clic.
   *
   * L'état est déjà posé sur <html> par l'en-tête, avant le premier rendu —
   * voir partials/head.php. Ici on ne fait que le changer quand on clique, et
   * le garder dans le navigateur de la personne : ce n'est pas un réglage de
   * l'instance, et il n'a rien à faire sur le serveur.
   *
   * Sept métiers cohabitent dans cette console, et l'on travaille rarement à
   * plusieurs à la fois. Replier ceux du moment laisse un rail de sept lignes
   * au lieu de quarante à relire en entier — c'est la différence entre choisir
   * et chercher.
   */
  var CLE = 'ta-groupes';

  /** L'état réellement appliqué, lu sur la page plutôt que deviné. */
  function lire() {
    return (document.documentElement.getAttribute('data-groupes-fermes') || '').split(' ').filter(Boolean);
  }

  function ecrire(liste) {
    try {
      localStorage.setItem(CLE, liste.join(' '));
    } catch (e) {
      /* stockage bloqué : le pli vaut pour la page courante */
    }
  }

  function poser(liste) {
    if (liste.length) document.documentElement.setAttribute('data-groupes-fermes', liste.join(' '));
    else document.documentElement.removeAttribute('data-groupes-fermes');
  }

  document.addEventListener('DOMContentLoaded', function () {
    var boutons = document.querySelectorAll('[data-groupe-bascule]');
    if (!boutons.length) return;

    function dire() {
      var fermes = lire();
      boutons.forEach(function (bouton) {
        bouton.setAttribute('aria-expanded', fermes.indexOf(bouton.dataset.groupeBascule) === -1 ? 'true' : 'false');
      });
    }

    dire();
    boutons.forEach(function (bouton) {
      bouton.addEventListener('click', function () {
        var cle = bouton.dataset.groupeBascule;
        var fermes = lire();
        var ou = fermes.indexOf(cle);
        if (ou === -1) fermes.push(cle);
        else fermes.splice(ou, 1);
        ecrire(fermes);
        poser(fermes);
        dire();
      });
    });
  });
})();
