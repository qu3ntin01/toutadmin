(function () {
  // La CSP interdit l'attribut style inline : la largeur de la jauge est posée ici,
  // à partir d'un ratio déjà borné côté serveur.
  var bars = document.querySelectorAll('.meter-fill[data-ratio]');
  for (var i = 0; i < bars.length; i++) {
    var ratio = parseFloat(bars[i].getAttribute('data-ratio'));
    if (!isFinite(ratio) || ratio < 0) ratio = 0;
    if (ratio > 100) ratio = 100;
    bars[i].style.width = ratio + '%';
  }
})();
