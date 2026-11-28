/* Trois réglages posés avant le premier rendu : sans cela, la page
   commencerait par l'état du défaut puis basculerait, ce qui se voit — un
   éclair blanc pour le thème sombre, une neige qui démarre pour qui l'a coupée.

   • le thème : clair par défaut. « Système » reste un choix, mais ce n'est
     plus ce que reçoit quelqu'un qui n'a rien demandé ;
   • le mouvement : coupé par défaut. Il s'allume par un choix, jamais de
     lui-même, et jamais du tout pour qui a demandé moins d'animation à son
     système — ce choix-là n'est pas à nous ;
   • la saison : du 1er décembre au 6 janvier, la page prend sa tenue de fêtes,
     sauf si le visiteur l'a refusée. « ?saison=noel » la montre en tout temps,
     pour la voir avant l'heure. */
(function () {
  var racine = document.documentElement;
  var lire = function (cle, defaut) {
    try { var v = localStorage.getItem(cle); return v === null ? defaut : v; } catch (e) { return defaut; }
  };

  try {
    var pref = lire('ta-theme', 'light');
    if (pref !== 'dark' && pref !== 'system') pref = 'light';
    var sombre = pref === 'dark' || (pref === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    racine.setAttribute('data-theme', sombre ? 'dark' : 'light');
    // La préférence elle-même, pas seulement son résultat : la barre replie
    // les deux thèmes non choisis, et doit savoir lequel montrer avant que le
    // script différé n'arrive.
    racine.setAttribute('data-theme-pref', pref);
  } catch (e) {
    racine.setAttribute('data-theme', 'light');
    racine.setAttribute('data-theme-pref', 'light');
  }

  try {
    var calme = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var mouvement = lire('ta-motion', 'off') === 'on' && !calme;
    racine.setAttribute('data-motion', mouvement ? 'on' : 'off');
    if (calme) racine.setAttribute('data-motion-locked', '');
  } catch (e) {
    racine.setAttribute('data-motion', 'off');
  }

  try {
    var voulue = (location.search.match(/[?&]saison=(noel|off)/) || [])[1];
    if (voulue === 'noel') { try { localStorage.setItem('ta-season', 'on'); } catch (e) { /* aperçu pour cette page */ } }
    if (voulue === 'off') { try { localStorage.setItem('ta-season', 'off'); } catch (e) { /* idem */ } }
    var choix = voulue === 'noel' ? 'on' : voulue === 'off' ? 'off' : lire('ta-season', 'auto');
    if (choix !== 'on' && choix !== 'off') choix = 'auto';
    var d = new Date();
    var enFetes = d.getMonth() === 11 || (d.getMonth() === 0 && d.getDate() <= 6);
    racine.setAttribute('data-season-pref', choix);
    if (choix === 'on' || (choix === 'auto' && enFetes)) racine.setAttribute('data-season', 'noel');
  } catch (e) { /* pas de saison */ }
}());
