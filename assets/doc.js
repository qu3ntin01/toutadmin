/* Documentation de Toutadmin — comportements du site.
   Aucune dépendance : le site doit fonctionner posé tel quel sur n'importe
   quel hébergement statique, y compris ouvert depuis un disque local. */

(function () {
  'use strict';

  var root = document.documentElement;

  /* ------------------------------------------------------------ thème */

  function applyTheme(value) {
    if (value === 'dark' || value === 'light') root.setAttribute('data-theme', value);
    else root.removeAttribute('data-theme');
  }

  var themeBtn = document.querySelector('[data-theme-toggle]');
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var current = root.getAttribute('data-theme');
      // Sans choix explicite, on part de ce que le système affiche.
      if (!current) {
        current = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      }
      var next = current === 'dark' ? 'light' : 'dark';
      applyTheme(next);
      try { localStorage.setItem('doc-theme', next); } catch (e) { /* navigation privée */ }
    });
  }

  /* ------------------------------------------------- navigation mobile */

  var side = document.querySelector('.side');
  var navBtn = document.querySelector('.nav-toggle');
  if (side && navBtn) {
    navBtn.addEventListener('click', function () {
      var open = side.classList.toggle('is-open');
      navBtn.setAttribute('aria-expanded', String(open));
    });
    side.addEventListener('click', function (event) {
      if (event.target.closest('a')) {
        side.classList.remove('is-open');
        navBtn.setAttribute('aria-expanded', 'false');
      }
    });
  }

  /* --------------------------------------------- sommaire de la page */

  var tocLinks = [].slice.call(document.querySelectorAll('.toc a'));
  if (tocLinks.length && 'IntersectionObserver' in window) {
    var byId = {};
    tocLinks.forEach(function (link) { byId[link.getAttribute('href').slice(1)] = link; });
    var seen = [];
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        var id = entry.target.id;
        var at = seen.indexOf(id);
        if (entry.isIntersecting && at === -1) seen.push(id);
        if (!entry.isIntersecting && at !== -1) seen.splice(at, 1);
      });
      tocLinks.forEach(function (link) { link.classList.remove('is-active'); });
      if (seen.length && byId[seen[0]]) byId[seen[0]].classList.add('is-active');
    }, { rootMargin: '-70px 0px -72% 0px' });
    Object.keys(byId).forEach(function (id) {
      var heading = document.getElementById(id);
      if (heading) observer.observe(heading);
    });
  }

  /* ------------------------------------------------------------ recherche */

  var input = document.querySelector('.top-search input');
  var box = document.querySelector('.results');
  if (input && box) {
    var index = null;
    var loading = false;
    var base = root.getAttribute('data-base') || '';

    function load() {
      if (index || loading) return;
      loading = true;
      fetch(base + 'data/recherche.json')
        .then(function (r) { return r.json(); })
        .then(function (data) { index = data; loading = false; run(); })
        .catch(function () { loading = false; });
    }

    // Sans accents ni casse : « sécurité » se trouve en tapant « securite ».
    function fold(value) {
      return value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    }

    function run() {
      var query = fold(input.value.trim());
      if (query.length < 2) { box.hidden = true; box.innerHTML = ''; return; }
      if (!index) { load(); return; }

      var terms = query.split(/\s+/);
      var hits = [];
      for (var i = 0; i < index.length; i++) {
        var entry = index[i];
        var score = 0;
        var ok = true;
        for (var j = 0; j < terms.length; j++) {
          var inTitle = entry.f.indexOf(terms[j]) !== -1;
          var inBody = entry.b.indexOf(terms[j]) !== -1;
          if (!inTitle && !inBody) { ok = false; break; }
          score += inTitle ? 10 : 1;
        }
        if (ok) hits.push({ e: entry, s: score });
      }
      hits.sort(function (a, b) { return b.s - a.s; });

      if (!hits.length) {
        box.innerHTML = '<p class="r-empty">Aucun résultat.</p>';
        box.hidden = false;
        return;
      }
      var html = '';
      for (var k = 0; k < Math.min(hits.length, 12); k++) {
        var hit = hits[k].e;
        html += '<a href="' + base + hit.u + '"><span class="r-title">' + hit.t + '</span>'
          + '<span class="r-path">' + hit.p + '</span></a>';
      }
      box.innerHTML = html;
      box.hidden = false;
    }

    input.addEventListener('focus', load);
    input.addEventListener('input', run);
    input.addEventListener('keydown', function (event) {
      var items = [].slice.call(box.querySelectorAll('a'));
      if (!items.length) return;
      var at = items.findIndex(function (a) { return a.classList.contains('is-active'); });
      if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
        event.preventDefault();
        if (at >= 0) items[at].classList.remove('is-active');
        var next = event.key === 'ArrowDown' ? (at + 1) % items.length : (at <= 0 ? items.length - 1 : at - 1);
        items[next].classList.add('is-active');
        items[next].scrollIntoView({ block: 'nearest' });
      } else if (event.key === 'Enter' && at >= 0) {
        event.preventDefault();
        window.location.href = items[at].getAttribute('href');
      } else if (event.key === 'Escape') {
        box.hidden = true;
        input.blur();
      }
    });
    document.addEventListener('click', function (event) {
      if (!event.target.closest('.top-search')) box.hidden = true;
    });
    document.addEventListener('keydown', function (event) {
      // « / » met le curseur dans la recherche, sauf si l'on est déjà en train d'écrire.
      if (event.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) {
        event.preventDefault();
        input.focus();
      }
    });
  }
})();
