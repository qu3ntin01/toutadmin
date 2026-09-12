/* Vitrine Toutadmin — comportements. Aucune dépendance, aucun appel réseau.
   Tout est facultatif : sans JavaScript la page reste lisible et navigable. */
(function () {
  'use strict';

  /* ------------------------------------------------------------- thème */
  var KEY = 'ta-theme';
  var root = document.documentElement;
  var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

  function read() {
    try { var v = localStorage.getItem(KEY); return v === 'dark' || v === 'light' || v === 'system' ? v : 'system'; }
    catch (e) { return 'system'; }
  }
  function apply(pref) {
    var dark = pref === 'dark' || (pref === 'system' && media && media.matches);
    root.setAttribute('data-theme', dark ? 'dark' : 'light');
    var list = document.querySelectorAll('[data-theme-choice]');
    for (var i = 0; i < list.length; i += 1) {
      list[i].classList.toggle('is-active', list[i].getAttribute('data-theme-choice') === pref);
    }
  }
  apply(read());
  if (media && media.addEventListener) media.addEventListener('change', function () { if (read() === 'system') apply('system'); });

  document.addEventListener('click', function (e) {
    var choice = e.target.closest && e.target.closest('[data-theme-choice]');
    if (!choice) return;
    var pref = choice.getAttribute('data-theme-choice');
    try { localStorage.setItem(KEY, pref); } catch (err) { /* stockage bloqué : le choix vaut pour la page */ }
    apply(pref);
  });

  /* ------------------------------------------------ barre et menus pop */
  var nav = document.querySelector('.nav');
  if (nav) {
    var onScroll = function () { nav.classList.toggle('is-stuck', window.scrollY > 8); };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
    var toggle = nav.querySelector('.nav-toggle');
    if (toggle) toggle.addEventListener('click', function () { nav.classList.toggle('is-open'); });
  }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('.picker-btn');
    var pickers = document.querySelectorAll('.picker');
    for (var i = 0; i < pickers.length; i += 1) {
      var inside = btn && pickers[i].contains(btn);
      pickers[i].classList.toggle('is-open', inside && !pickers[i].classList.contains('is-open'));
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    var open = document.querySelectorAll('.picker.is-open');
    for (var i = 0; i < open.length; i += 1) open[i].classList.remove('is-open');
    closeBox();
  });

  /* ------------------------------------------------------- révélations */
  var reveals = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window && reveals.length) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('is-in');
        io.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    for (var r = 0; r < reveals.length; r += 1) io.observe(reveals[r]);
  } else {
    for (var r2 = 0; r2 < reveals.length; r2 += 1) reveals[r2].classList.add('is-in');
  }

  /* ---------------------------------------------------------- compteurs */
  var counters = document.querySelectorAll('[data-count]');
  var still = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (counters.length && 'IntersectionObserver' in window && !still) {
    var co = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var el = entry.target;
        co.unobserve(el);
        var target = Number(el.getAttribute('data-count'));
        var suffix = el.getAttribute('data-suffix') || '';
        var t0 = null;
        var step = function (ts) {
          if (t0 === null) t0 = ts;
          var k = Math.min((ts - t0) / 1100, 1);
          var eased = 1 - Math.pow(1 - k, 3);
          el.textContent = Math.round(target * eased).toLocaleString(root.lang || 'fr') + suffix;
          if (k < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
      });
    }, { threshold: 0.4 });
    for (var c = 0; c < counters.length; c += 1) co.observe(counters[c]);
  }

  /* -------------------------------------------------------------- onglets */
  document.addEventListener('click', function (e) {
    var tab = e.target.closest && e.target.closest('.tab');
    if (!tab) return;
    var group = tab.closest('[data-tabs]');
    if (!group) return;
    var name = tab.getAttribute('data-tab');
    group.querySelectorAll('.tab').forEach(function (t) {
      var on = t === tab;
      t.classList.toggle('is-active', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    group.querySelectorAll('.pane').forEach(function (p) {
      p.classList.toggle('is-active', p.getAttribute('data-pane') === name);
    });
  });

  /* ------------------------------------------------------------ visionneuse */
  var box = document.querySelector('.lightbox');
  var figures = Array.prototype.slice.call(document.querySelectorAll('.gal figure'));
  var at = -1;

  function show(index) {
    if (!box || !figures.length) return;
    at = (index + figures.length) % figures.length;
    var fig = figures[at];
    var img = fig.querySelector('img');
    var caption = fig.querySelector('figcaption');
    box.querySelector('img').src = img.getAttribute('data-full') || img.src;
    box.querySelector('img').alt = img.alt;
    box.querySelector('figcaption').textContent = caption ? caption.textContent : '';
    box.classList.add('is-open');
    document.body.style.overflow = 'hidden';
  }
  function closeBox() {
    if (!box || !box.classList.contains('is-open')) return;
    box.classList.remove('is-open');
    document.body.style.overflow = '';
  }
  figures.forEach(function (fig, i) {
    fig.addEventListener('click', function () { show(i); });
  });
  if (box) {
    box.addEventListener('click', function (e) {
      if (e.target.closest('.lb-next')) { show(at + 1); return; }
      if (e.target.closest('.lb-prev')) { show(at - 1); return; }
      if (e.target.closest('.lb-close') || e.target === box) closeBox();
    });
    document.addEventListener('keydown', function (e) {
      if (!box.classList.contains('is-open')) return;
      if (e.key === 'ArrowRight') show(at + 1);
      if (e.key === 'ArrowLeft') show(at - 1);
    });
  }

  /* ------------------------------------------------- parallaxe discrète */
  var floatShot = document.querySelector('[data-parallax]');
  if (floatShot && !still) {
    window.addEventListener('scroll', function () {
      var y = Math.min(window.scrollY, 520);
      floatShot.style.transform = 'translateY(' + (y * -0.035) + 'px)';
    }, { passive: true });
  }
}());
