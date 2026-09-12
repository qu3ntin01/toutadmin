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
  }

  /* ------------------------------------------------- plan du site (tiroir) */
  // Une page peut rester en cache chez le visiteur bien plus longtemps que ce
  // script : l'hébergement ne pose aucune directive de cache sur le HTML, le
  // navigateur applique alors sa propre heuristique, et une page d'avant le
  // tiroir garde un bouton à trois traits sans rien derrière. Plutôt que de
  // laisser ce bouton mort jusqu'au prochain rechargement forcé, on regreffe
  // un tiroir à partir de la barre de navigation elle-même. C'est un repli :
  // il reprend les liens de la barre, sans les descriptions ni les domaines.
  function graftDrawer() {
    var bar = document.querySelector('.nav-links');
    var header = document.querySelector('.nav');
    if (!bar || !header) return;

    var backdrop = document.createElement('div');
    backdrop.className = 'drawer-backdrop';
    backdrop.hidden = true;

    var aside = document.createElement('aside');
    aside.className = 'drawer';
    aside.id = 'plan-du-site';
    aside.hidden = true;

    var head = document.createElement('div');
    head.className = 'drawer-head';
    var title = document.createElement('span');
    title.className = 'brand';
    // Le nom seul : le monogramme de la marque est un décor, pas du texte.
    var brand = header.querySelector('.brand');
    if (brand) {
      var copy = brand.cloneNode(true);
      var mono = copy.querySelector('.brand-mark');
      if (mono) mono.remove();
      title.textContent = copy.textContent.trim();
    }
    var close = document.createElement('button');
    close.className = 'picker-btn drawer-close';
    close.type = 'button';
    close.textContent = '\u2715';
    var toggle = document.querySelector('.nav-toggle');
    if (toggle && toggle.getAttribute('aria-label')) close.setAttribute('aria-label', toggle.getAttribute('aria-label'));
    head.appendChild(title);
    head.appendChild(close);

    var body = document.createElement('div');
    body.className = 'drawer-body';
    var list = document.createElement('nav');
    list.className = 'drawer-list';
    var links = bar.querySelectorAll('a');
    for (var i = 0; i < links.length; i += 1) {
      var item = document.createElement('a');
      item.className = 'drawer-item' + (links[i].classList.contains('is-active') ? ' is-active' : '');
      item.href = links[i].href;
      if (links[i].rel) item.rel = links[i].rel;
      var label = document.createElement('span');
      var strong = document.createElement('strong');
      strong.textContent = (links[i].textContent || '').trim();
      label.appendChild(strong);
      item.appendChild(label);
      list.appendChild(item);
    }
    body.appendChild(list);
    aside.appendChild(head);
    aside.appendChild(body);
    header.parentNode.insertBefore(backdrop, header.nextSibling);
    header.parentNode.insertBefore(aside, backdrop.nextSibling);

    // La feuille de style peut elle aussi dater d'avant le tiroir. Sans ses
    // règles, le panneau s'empilerait en bas de page : on vérifie qu'elle le
    // connaît, et sinon on pose le strict minimum pour qu'il glisse sur le
    // côté. Les couleurs système suivent le thème clair ou sombre.
    if (window.getComputedStyle(aside).position !== 'fixed') {
      aside.style.cssText = 'position:fixed;inset-block:0;inset-inline-end:0;z-index:60;'
        + 'inline-size:min(22rem,88vw);overflow:auto;padding:1.25rem;'
        + 'background:Canvas;color:CanvasText;box-shadow:0 0 3rem rgba(0,0,0,.3)';
      backdrop.style.cssText = 'position:fixed;inset:0;z-index:59;background:rgba(0,0,0,.45)';
      list.style.cssText = 'display:grid;gap:.75rem;margin-block-start:1rem';
      head.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:1rem';
    }
  }

  if (document.querySelector('.nav-toggle') && !document.querySelector('.drawer')) graftDrawer();

  var drawer = document.querySelector('.drawer');
  var backdrop = document.querySelector('.drawer-backdrop');
  var opener = document.querySelector('.nav-toggle');

  function openDrawer() {
    if (!drawer) return;
    drawer.hidden = false;
    backdrop.hidden = false;
    // Le navigateur doit avoir peint l'élément avant qu'on l'anime, sinon il
    // apparaît d'un coup au lieu de glisser.
    requestAnimationFrame(function () {
      drawer.classList.add('is-open');
      backdrop.classList.add('is-open');
    });
    opener.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
    drawer.querySelector('.drawer-close').focus();
  }

  function closeDrawer() {
    if (!drawer || !drawer.classList.contains('is-open')) return;
    drawer.classList.remove('is-open');
    backdrop.classList.remove('is-open');
    opener.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
    opener.focus();
    // Retiré du parcours de tabulation une fois l'animation finie : un panneau
    // invisible mais focusable piège le clavier.
    window.setTimeout(function () {
      if (!drawer.classList.contains('is-open')) { drawer.hidden = true; backdrop.hidden = true; }
    }, 360);
  }

  if (drawer && opener) {
    opener.setAttribute('aria-expanded', 'false');
    opener.setAttribute('aria-controls', 'plan-du-site');
    opener.addEventListener('click', function () {
      if (drawer.classList.contains('is-open')) closeDrawer(); else openDrawer();
    });
    backdrop.addEventListener('click', closeDrawer);
    drawer.addEventListener('click', function (e) {
      if (e.target.closest('.drawer-close') || e.target.closest('a')) closeDrawer();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDrawer(); });
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
  function activate(group, name) {
    group.querySelectorAll('.tab').forEach(function (t) {
      var on = t.getAttribute('data-tab') === name;
      t.classList.toggle('is-active', on);
      t.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    group.querySelectorAll('.pane, .sheet').forEach(function (p) {
      p.classList.toggle('is-active', p.getAttribute('data-pane') === name);
    });
  }

  document.addEventListener('click', function (e) {
    var tab = e.target.closest && e.target.closest('.tab');
    if (!tab) return;
    var group = tab.closest('[data-tabs]');
    if (group) activate(group, tab.getAttribute('data-tab'));
  });

  /* Un lien peut viser une ancre cachée dans un onglet fermé — « #ecrans »
     depuis la barre, « #rh » depuis l'accueil. L'onglet qui la contient
     s'ouvre alors, sinon le lien ne mènerait nulle part. */
  function followHash() {
    var name = decodeURIComponent(window.location.hash.slice(1));
    if (!name) return;
    var target = document.getElementById(name);
    if (!target) return;
    var pane = target.closest('.sheet, .pane');
    if (!pane) return;
    var group = pane.closest('[data-tabs]');
    if (!group) return;
    activate(group, pane.getAttribute('data-pane'));
    // La position n'est connue qu'une fois le volet affiché.
    requestAnimationFrame(function () {
      target.scrollIntoView({ block: 'start' });
    });
  }
  followHash();
  window.addEventListener('hashchange', followHash);

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
