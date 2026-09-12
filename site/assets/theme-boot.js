/* Le thème est posé avant le premier rendu : sans cela, une page sombre
   commencerait par un éclair blanc, ce qui est exactement ce qu'on cherche
   à éviter en choisissant le thème sombre. */
(function () {
  try {
    var pref = localStorage.getItem('ta-theme') || 'system';
    var dark = pref === 'dark' || (pref === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
  } catch (e) {
    document.documentElement.setAttribute('data-theme', 'light');
  }
}());
