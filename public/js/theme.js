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
