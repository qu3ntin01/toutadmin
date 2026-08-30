(function () {
  var KEY = 'pm-theme';

  function apply(theme) {
    if (theme === 'light' || theme === 'dark') {
      document.documentElement.setAttribute('data-theme', theme);
    } else {
      document.documentElement.removeAttribute('data-theme');
    }
    document.querySelectorAll('[data-theme-option]').forEach(function (btn) {
      btn.classList.toggle('is-active', btn.dataset.themeOption === theme);
    });
  }

  function getStored() {
    try {
      return localStorage.getItem(KEY);
    } catch (e) {
      return null;
    }
  }

  function setStored(value) {
    try {
      localStorage.setItem(KEY, value);
    } catch (e) {}
  }

  document.addEventListener('DOMContentLoaded', function () {
    apply(getStored() || 'dark');
    document.querySelectorAll('[data-theme-option]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var theme = btn.dataset.themeOption;
        setStored(theme);
        apply(theme);
      });
    });
  });
})();
