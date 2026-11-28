/* Vitrine Toutadmin — la neige et la poussière d'or.

   Un seul canevas, posé devant la page et qui n'intercepte rien : de la neige
   sur trois plans de profondeur, et, pour qui a une souris, une traînée de
   poussière d'or qui suit le pointeur.

   Ce script ne fait rien tant que deux conditions ne sont pas réunies : la page
   porte sa tenue de fêtes (data-season) ET le visiteur a allumé le mouvement
   (data-motion) — jamais s'il a demandé moins d'animation à son système, ce que
   theme-boot.js a déjà tranché. Sans lui, la page reste exactement ce qu'elle
   est : le décor immobile de fete.css.

   Ce que coûte le dessin est tenu bas, parce qu'une neige qui fait ramer la
   page est pire que pas de neige :
     • les flocons sont des images calculées une fois, collées ensuite — jamais
       un dégradé redessiné à chaque image ;
     • leur nombre suit la surface de la fenêtre, et baisse tout seul si les
       images tardent ;
     • la boucle s'arrête quand l'onglet est caché, et le canevas est retiré
       dès que le mouvement ne l'est plus.
   Aucune dépendance, aucun appel réseau. */
(function () {
  'use strict';

  var racine = document.documentElement;
  if (racine.getAttribute('data-season') !== 'noel') return;
  if (racine.getAttribute('data-motion') !== 'on') return;
  var calme = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (calme) return;

  var canevas = document.querySelector('.fete-neige');
  if (!canevas || !canevas.getContext) return;
  var ctx = canevas.getContext('2d');
  if (!ctx) return;
  canevas.classList.add('is-on');

  var dpr = Math.min(window.devicePixelRatio || 1, 2);
  var L = 0;
  var H = 0;
  var flocons = [];
  var poussiere = [];
  var sprites = null;
  var vent = 0;
  var pointeurX = 0.5;
  var densite = 1;
  var precedent = 0;
  var lent = 0;
  var rafId = 0;

  /* Les flocons : trois plans. Le lointain est petit, lent et pâle ; le proche
     est grand, rapide et net. La différence de vitesse fait la profondeur —
     c'est elle, et non le nombre, qui donne l'impression d'une vraie chute. */
  var PLANS = [
    { r: [0.9, 1.7], v: [16, 28], a: 0.5, part: 0.52 },
    { r: [1.8, 3.1], v: [34, 56], a: 0.74, part: 0.34 },
    { r: [3.4, 5.8], v: [68, 108], a: 0.92, part: 0.14 }
  ];

  /* Deux sortes de sprites : un point doux, et un cristal à six branches.
     Sur fond sombre le flocon est blanc ; sur fond clair, un blanc seul
     disparaîtrait — on lui donne un liseré bleu-gris, qui le dessine. */
  function fabriquer() {
    var sombre = racine.getAttribute('data-theme') === 'dark';
    var taille = 64;
    var doux = document.createElement('canvas');
    doux.width = doux.height = taille;
    var c = doux.getContext('2d');
    var g = c.createRadialGradient(taille / 2, taille / 2, 0, taille / 2, taille / 2, taille / 2);
    g.addColorStop(0, 'rgba(255,255,255,1)');
    g.addColorStop(0.42, 'rgba(255,255,255,0.92)');
    g.addColorStop(0.62, sombre ? 'rgba(255,255,255,0.35)' : 'rgba(196,216,232,0.75)');
    g.addColorStop(1, sombre ? 'rgba(255,255,255,0)' : 'rgba(150,185,210,0)');
    c.fillStyle = g;
    c.fillRect(0, 0, taille, taille);

    var cristal = document.createElement('canvas');
    cristal.width = cristal.height = taille;
    var k = cristal.getContext('2d');
    k.translate(taille / 2, taille / 2);
    k.lineCap = 'round';
    k.strokeStyle = sombre ? 'rgba(255,255,255,0.95)' : 'rgba(255,255,255,1)';
    if (!sombre) { k.shadowColor = 'rgba(120,160,190,0.9)'; k.shadowBlur = 3; }
    k.lineWidth = 3.2;
    for (var i = 0; i < 6; i += 1) {
      k.save();
      k.rotate(i * Math.PI / 3);
      k.beginPath();
      k.moveTo(0, 0);
      k.lineTo(0, -24);
      k.moveTo(0, -14);
      k.lineTo(-7, -21);
      k.moveTo(0, -14);
      k.lineTo(7, -21);
      k.stroke();
      k.restore();
    }
    sprites = { doux: doux, cristal: cristal };
  }

  function alea(a, b) { return a + Math.random() * (b - a); }

  function nouveau(plan, enHaut) {
    var p = PLANS[plan];
    var r = alea(p.r[0], p.r[1]);
    return {
      plan: plan,
      x: Math.random() * L,
      y: enHaut ? -r * 4 - Math.random() * 40 : Math.random() * H,
      r: r,
      v: alea(p.v[0], p.v[1]),
      a: p.a * alea(0.75, 1),
      phase: Math.random() * 6.283,
      freq: alea(0.4, 1.1),
      amp: alea(8, 26) * (0.5 + plan * 0.35),
      cristal: plan > 0 && Math.random() < 0.28,
      tourne: alea(-0.8, 0.8),
      angle: Math.random() * 6.283
    };
  }

  function peupler() {
    var cible = Math.round(Math.min(Math.max(L * H / 15000, 36), 150) * densite);
    flocons.length = 0;
    for (var i = 0; i < cible; i += 1) {
      var t = Math.random();
      var plan = t < PLANS[0].part ? 0 : (t < PLANS[0].part + PLANS[1].part ? 1 : 2);
      flocons.push(nouveau(plan, false));
    }
  }

  function dimensionner() {
    L = window.innerWidth;
    H = window.innerHeight;
    canevas.width = Math.round(L * dpr);
    canevas.height = Math.round(H * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    peupler();
  }

  /* La poussière d'or : une particule de temps en temps, quand le pointeur
     bouge, qui dérive vers le bas et s'éteint en moins d'une seconde. */
  var dernierePoussiere = 0;
  var fin = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  if (fin) {
    window.addEventListener('pointermove', function (e) {
      pointeurX = e.clientX / Math.max(L, 1);
      var t = performance.now();
      if (t - dernierePoussiere < 36 || poussiere.length > 60) return;
      dernierePoussiere = t;
      poussiere.push({
        x: e.clientX + alea(-4, 4), y: e.clientY + alea(-4, 4),
        vx: alea(-14, 14), vy: alea(6, 30),
        r: alea(1.1, 2.6), vie: 0, dure: alea(520, 980),
        or: Math.random() < 0.75
      });
    }, { passive: true });
  }

  function etoile(x, y, r, alpha, or) {
    // Quatre branches fines et un cœur : l'éclat, pas un rond.
    ctx.globalAlpha = alpha;
    ctx.fillStyle = or ? '#ffe9a8' : '#ffffff';
    ctx.beginPath();
    ctx.moveTo(x, y - r * 2.4);
    ctx.quadraticCurveTo(x + r * 0.28, y - r * 0.28, x + r * 2.4, y);
    ctx.quadraticCurveTo(x + r * 0.28, y + r * 0.28, x, y + r * 2.4);
    ctx.quadraticCurveTo(x - r * 0.28, y + r * 0.28, x - r * 2.4, y);
    ctx.quadraticCurveTo(x - r * 0.28, y - r * 0.28, x, y - r * 2.4);
    ctx.fill();
  }

  function image(t) {
    rafId = 0;
    if (document.hidden) return;
    var dt = precedent ? Math.min((t - precedent) / 1000, 0.05) : 0.016;
    // Si les images tardent plusieurs fois de suite, on allège plutôt que de
    // laisser la page ramer : vingt pour cent de flocons en moins, jusqu'à un plancher.
    if (precedent) {
      if (t - precedent > 34) lent += 1; else lent = Math.max(0, lent - 1);
      if (lent > 40 && densite > 0.35) { densite *= 0.8; lent = 0; peupler(); }
    }
    precedent = t;

    // Le vent respire, et le pointeur le penche un peu : un souffle, pas un
    // coup de vent — la neige doit rester une chute.
    vent = Math.sin(t / 7000) * 14 + (pointeurX - 0.5) * 16;

    ctx.clearRect(0, 0, L, H);
    var temps = t / 1000;
    for (var i = 0; i < flocons.length; i += 1) {
      var f = flocons[i];
      f.y += f.v * dt;
      f.x += (vent * (0.35 + f.plan * 0.4) + Math.sin(temps * f.freq + f.phase) * f.amp) * dt;
      f.angle += f.tourne * dt;
      if (f.y - f.r * 4 > H) { flocons[i] = nouveau(f.plan, true); continue; }
      if (f.x < -20) f.x = L + 20; else if (f.x > L + 20) f.x = -20;
      var d = f.r * 2;
      ctx.globalAlpha = f.a;
      if (f.cristal) {
        ctx.save();
        ctx.translate(f.x, f.y);
        ctx.rotate(f.angle);
        ctx.drawImage(sprites.cristal, -d, -d, d * 2, d * 2);
        ctx.restore();
      } else {
        ctx.drawImage(sprites.doux, f.x - d, f.y - d, d * 2, d * 2);
      }
    }

    for (var j = poussiere.length - 1; j >= 0; j -= 1) {
      var p = poussiere[j];
      p.vie += dt * 1000;
      if (p.vie >= p.dure) { poussiere.splice(j, 1); continue; }
      p.x += p.vx * dt;
      p.y += p.vy * dt;
      var reste = 1 - p.vie / p.dure;
      etoile(p.x, p.y, p.r * (0.6 + reste * 0.6), reste * 0.95, p.or);
    }
    ctx.globalAlpha = 1;
    rafId = requestAnimationFrame(image);
  }

  function demarrer() { if (!rafId && !document.hidden) { precedent = 0; rafId = requestAnimationFrame(image); } }

  // Le thème peut changer sans recharger la page : les sprites suivent.
  if (window.MutationObserver) {
    new MutationObserver(fabriquer).observe(racine, { attributes: true, attributeFilter: ['data-theme'] });
  }
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { if (rafId) { cancelAnimationFrame(rafId); rafId = 0; } } else { demarrer(); }
  });
  var attente = 0;
  window.addEventListener('resize', function () {
    clearTimeout(attente);
    attente = setTimeout(dimensionner, 160);
  }, { passive: true });

  fabriquer();
  dimensionner();
  demarrer();
}());
