(function () {
  const buttons = document.querySelectorAll('.tab-btn');
  const panels = document.querySelectorAll('.tab-panel');

  function activate(name) {
    buttons.forEach((b) => b.classList.toggle('is-active', b.dataset.tab === name));
    panels.forEach((p) => p.classList.toggle('is-active', p.id === name));
  }

  buttons.forEach((btn) => {
    btn.addEventListener('click', () => {
      activate(btn.dataset.tab);
      history.replaceState(null, '', '#' + btn.dataset.tab);
    });
  });

  const initial = window.location.hash.replace('#', '');
  if (initial && document.getElementById(initial)) {
    activate(initial);
  }
})();
