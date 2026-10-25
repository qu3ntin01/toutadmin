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
    root.setAttribute('data-theme-pref', pref);
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
    // Un clic dans le panneau ouvert (un thème, par exemple) le laisse ouvert :
    // on voit le changement sans avoir à rouvrir le panneau.
    if (e.target.closest && e.target.closest('.picker-pop')) return;
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

  /* ------------------------------------------------------- menus de la barre
     Ils s'ouvrent au survol par la feuille de style ; ici, le clic, le clavier
     et l'état annoncé aux lecteurs d'écran. Un seul menu ouvert à la fois. */
  var menus = document.querySelectorAll('[data-mm]');
  function fermerMenus(sauf) {
    for (var m = 0; m < menus.length; m += 1) {
      if (menus[m] === sauf) continue;
      menus[m].classList.remove('is-open');
      var b = menus[m].querySelector('.mm-btn');
      if (b) b.setAttribute('aria-expanded', 'false');
    }
  }
  if (menus.length) {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest && e.target.closest('.mm-btn');
      if (btn) {
        var menu = btn.closest('[data-mm]');
        var ouvrir = !menu.classList.contains('is-open');
        fermerMenus(menu);
        menu.classList.toggle('is-open', ouvrir);
        btn.setAttribute('aria-expanded', String(ouvrir));
        return;
      }
      if (!e.target.closest || !e.target.closest('.mm-panel')) fermerMenus(null);
      else if (e.target.closest('a')) fermerMenus(null);
    });
    for (var mi = 0; mi < menus.length; mi += 1) {
      menus[mi].addEventListener('mouseenter', function () { fermerMenus(this); });
    }
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') fermerMenus(null); });
  }

  /* ------------------------------------------------ l'animation de l'accueil
     Les trajets tournent seuls (SMIL, dans le dessin). Ici : les bulles qui
     disent ce qui vient de se passer dans un espace, une à la fois, et
     l'arrêt complet hors de l'écran ou pour qui a demandé moins d'animation. */
  var cx = document.querySelector('.cx');
  if (cx) {
    var dessin = cx.querySelector('.cx-svg');
    var calme = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var figer = function () { if (dessin && dessin.pauseAnimations) dessin.pauseAnimations(); };
    var relancer = function () { if (dessin && dessin.unpauseAnimations) dessin.unpauseAnimations(); };
    if (calme) {
      figer();
    } else {
      var faits = Array.prototype.slice.call(cx.querySelectorAll('.cx-ev li'));
      var couche = cx.querySelector('.cx-toasts');
      var noeuds = cx.querySelectorAll('.cx-node');
      var ordre = faits.map(function (_, i) { return i; });
      for (var oi = ordre.length - 1; oi > 0; oi -= 1) {
        var oj = Math.floor(Math.random() * (oi + 1));
        var ot = ordre[oi]; ordre[oi] = ordre[oj]; ordre[oj] = ot;
      }
      var tour = 0;
      var coche = '<span class="ok"><svg class="i" viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg></span>';
      var annoncer = function () {
        if (document.hidden || !faits.length) return;
        var fait = faits[ordre[tour % ordre.length]];
        tour += 1;
        var noeud = noeuds[Number(fait.getAttribute('data-node'))];
        var cote = fait.getAttribute('data-side') || 'a';
        var x = Number(fait.getAttribute('data-x'));
        var y = Number(fait.getAttribute('data-y'));
        var bulle = document.createElement('div');
        bulle.className = 'cx-toast';
        bulle.setAttribute('data-side', cote);
        bulle.innerHTML = coche;
        bulle.appendChild(document.createTextNode(fait.textContent));
        bulle.style.left = x + '%';
        bulle.style.top = y + '%';
        couche.appendChild(bulle);
        if (noeud) noeud.classList.add('is-hot');
        requestAnimationFrame(function () { requestAnimationFrame(function () { bulle.classList.add('is-on'); }); });
        setTimeout(function () { bulle.classList.add('is-off'); if (noeud) noeud.classList.remove('is-hot'); }, 2300);
        setTimeout(function () { if (bulle.parentNode) bulle.parentNode.removeChild(bulle); }, 2800);
      };
      var cadence = null;
      if ('IntersectionObserver' in window) {
        new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              relancer();
              if (!cadence) { setTimeout(annoncer, 700); cadence = setInterval(annoncer, 2600); }
            } else {
              figer();
              clearInterval(cadence); cadence = null;
            }
          });
        }, { threshold: 0.15 }).observe(cx);
      } else {
        cadence = setInterval(annoncer, 2600);
      }
    }
  }

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

  /* --------------------------------------------------- reflet des cartes
     Une lueur suit le pointeur sur la carte survolée. Deux variables CSS, et
     rien d'autre : le dessin est fait par la feuille de style, qui sait le
     couper quand on a demandé moins d'animation. Sans ce script, les cartes
     restent exactement ce qu'elles étaient. */
  var fin = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  if (fin && !still) {
    var enAttente = false;
    document.addEventListener('pointermove', function (e) {
      if (enAttente) return;
      enAttente = true;
      // Une seule écriture par image : posée telle quelle, la position du
      // pointeur ferait recalculer la page à chaque pixel parcouru.
      requestAnimationFrame(function () {
        enAttente = false;
        var carte = e.target.closest && e.target.closest('.card, .plan');
        if (!carte) return;
        var boite = carte.getBoundingClientRect();
        carte.style.setProperty('--mx', (e.clientX - boite.left) + 'px');
        carte.style.setProperty('--my', (e.clientY - boite.top) + 'px');
      });
    }, { passive: true });
  }


  /* ------------------------------------------------ la fenêtre du héros

     Quatre choses, dans cet ordre :

       1. la fenêtre se redresse à l'arrivée, une fois pour toutes ;
       2. le pointeur la fait jouer de quelques degrés ;
       3. un reflet la traverse, une fois ;
       4. elle change d'écran toute seule.

     Aucune de ces quatre n'est nécessaire à la page : sans script, le
     premier écran est déjà visible, ses pastilles sont déjà là. Ce qui suit
     n'ajoute que le mouvement.

     La rotation automatique s'arrête dès qu'elle n'a plus de raison d'être :
     hors de l'écran, sous le pointeur, dans un onglet caché, ou dès que le
     visiteur a choisi lui-même un écran — à partir de là, c'est lui qui
     décide, et reprendre la main sur son choix serait grossier. */
  var heros = document.querySelector('[data-hero]');
  if (heros) {
    var fenetre = heros.querySelector('.hero-window');
    var ecrans = heros.querySelectorAll('.hero-screen');
    var points = heros.querySelectorAll('.hero-dot');
    var etiquette = heros.querySelector('[data-hero-label]');
    var zone = heros.querySelector('.hero-screens');
    var courant = 0;
    var choisi = false;
    var minuteur = null;

    var montrer = function (i) {
      if (i === courant || i < 0 || i >= ecrans.length) return;
      // Le sortant part par la gauche, l'entrant vient de la droite : c'est le
      // geste d'une application qui change de page, pas d'une image qui fond.
      var sortant = ecrans[courant];
      sortant.classList.remove('is-on');
      sortant.classList.add('is-sortante');
      setTimeout(function () { sortant.classList.remove('is-sortante'); }, 700);
      ecrans[courant].setAttribute('aria-hidden', 'true');
      ecrans[i].classList.add('is-on');
      ecrans[i].removeAttribute('aria-hidden');
      if (points[courant]) {
        points[courant].classList.remove('is-on');
        points[courant].removeAttribute('aria-current');
      }
      if (points[i]) {
        points[i].classList.add('is-on');
        points[i].setAttribute('aria-current', 'true');
      }
      // La barre de titre suit l'écran : c'est ce qui fait croire à une
      // application qui change de page plutôt qu'à des images qui défilent.
      if (etiquette) etiquette.textContent = ecrans[i].getAttribute('data-label') || '';
      courant = i;
    };

    for (var d = 0; d < points.length; d += 1) {
      (function (i) {
        points[i].addEventListener('click', function () {
          choisi = true;
          arreter();
          montrer(i);
        });
      }(d));
    }

    var arreter = function () {
      if (minuteur) { clearTimeout(minuteur); minuteur = null; }
      heros.classList.remove('is-appuyee');
      // Dès que quelqu'un prend la main, le curseur de démonstration s'efface :
      // deux curseurs à l'écran, dont un qui n'obéit pas, est une gêne.
      heros.classList.remove('is-guidee');
    };
    /*
     * La démonstration.
     *
     * Elle ne fait pas défiler des images : elle mime quelqu'un qui se sert
     * du logiciel. Le curseur part vers l'entrée de menu du prochain écran,
     * appuie, et c'est l'appui qui fait changer la page — la cause avant
     * l'effet, comme dans la vraie application.
     *
     * Chaque capture porte la position de son entrée de menu, en pourcentage
     * de la fenêtre ; le surlignage bleu qu'on voit ensuite est celui du
     * produit, photographié sur la page en question. Rien n'est simulé.
     */
    var cible = function (i) {
      var h = (ecrans[i].getAttribute('data-hot') || '').split(',');
      return h.length === 2 ? { x: h[0], y: h[1] } : null;
    };
    /*
     * Les positions sont données en pourcentage de la fenêtre, mais posées en
     * pixels — et ce n'est pas un détail : dans `translate3d`, un pourcentage
     * se rapporte à la boîte de l'élément déplacé, pas à celle qui le
     * contient. Onze pour cent d'un curseur de vingt-six pixels font trois
     * pixels, et le curseur restait collé dans le coin.
     *
     * On garde `transform` plutôt que `left` et `top` : c'est la seule façon
     * de déplacer quelque chose sans refaire la mise en page à chaque image.
     */
    var ecranBoite = null;
    var derniere = null;
    var poserCurseur = function (c) {
      if (!c) return false;
      derniere = c;
      var b = zone.getBoundingClientRect();
      ecranBoite = b;
      heros.style.setProperty('--cx', Math.round(b.width * c.x / 100) + 'px');
      heros.style.setProperty('--cy', Math.round(b.height * c.y / 100) + 'px');
      return true;
    };
    // La fenêtre change de taille avec la page : le curseur suit, sans
    // animation, puisque ce n'est pas un geste mais un recalage.
    window.addEventListener('resize', function () {
      if (derniere) poserCurseur(derniere);
    }, { passive: true });
    var pas = function () {
      if (choisi || document.hidden) return;
      var suivant = (courant + 1) % ecrans.length;
      var c = cible(suivant);
      if (!poserCurseur(c)) { montrer(suivant); return; }
      heros.classList.add('is-guidee');
      // Le curseur met un peu plus de huit dixièmes à se porter là-bas ; on
      // appuie une fois qu'il y est, pas avant.
      minuteur = setTimeout(function () {
        heros.classList.add('is-appuyee');
        minuteur = setTimeout(function () {
          heros.classList.remove('is-appuyee');
          montrer(suivant);
          // Puis on laisse lire l'écran obtenu.
          minuteur = setTimeout(pas, 2600);
        }, 180);
      }, 880);
    };
    var tourner = function () {
      if (still || choisi || minuteur || ecrans.length < 2) return;
      minuteur = setTimeout(pas, 900);
    };

    /* Le redressement. Il part au premier rendu qui suit l'arrivée de la
       page, jamais avant : posé tout de suite, le navigateur passe de l'état
       initial à l'état final dans la même image et il n'y a pas de
       mouvement. */
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        heros.classList.add('is-posee');
      });
    });
    // Le reflet part quand la fenêtre est à plat, pas pendant qu'elle bouge.
    var installee = function () {
      heros.classList.add('is-installee');
      if (!still) heros.classList.add('is-luisante');
    };
    if (still) {
      installee();
    } else if (fenetre) {
      var pose = false;
      fenetre.addEventListener('transitionend', function (e) {
        if (pose || e.propertyName !== 'transform') return;
        pose = true;
        installee();
      });
      // Filet : si la transition ne rend pas la main — onglet passé au
      // second plan pendant l'animation, par exemple —, on n'attend pas.
      setTimeout(function () { if (!pose) { pose = true; installee(); } }, 1800);
    }

    /* Le jeu du pointeur. Deux variables sur l'élément, rien d'autre : le
       navigateur recompose, il ne recalcule pas la page. La souris seule y a
       droit — sur un écran tactile, il n'y a pas de survol, et « pointer:
       fine » évite d'écouter pour rien. */
    var fine = window.matchMedia && window.matchMedia('(pointer: fine)').matches;
    if (fenetre && !still && fine) {
      var vise = { x: 0, y: 0 };
      var pose2 = { x: 0, y: 0 };
      var anime = false;
      var suivre = function () {
        anime = false;
        // Une approche par dixièmes : la fenêtre rejoint le pointeur sans le
        // coller, ce qui donne la masse.
        pose2.x += (vise.x - pose2.x) * 0.12;
        pose2.y += (vise.y - pose2.y) * 0.12;
        heros.style.setProperty('--ry', pose2.x.toFixed(2) + 'deg');
        heros.style.setProperty('--rx', pose2.y.toFixed(2) + 'deg');
        if (Math.abs(vise.x - pose2.x) > 0.02 || Math.abs(vise.y - pose2.y) > 0.02) {
          anime = true;
          requestAnimationFrame(suivre);
        }
      };
      var relancer = function () {
        if (anime) return;
        anime = true;
        requestAnimationFrame(suivre);
      };
      // La nappe de lumière suit la main dans tout le bloc, pas seulement
      // sur la fenêtre : c'est ce qui donne à la scène l'air d'être éclairée
      // par quelque chose plutôt que posée à plat.
      var bloc = heros.closest('.hero');
      if (bloc) {
        bloc.addEventListener('pointermove', function (e) {
          if (e.pointerType !== 'mouse') return;
          var b = bloc.getBoundingClientRect();
          bloc.style.setProperty('--mx', (((e.clientX - b.left) / b.width) * 100).toFixed(1) + '%');
          bloc.style.setProperty('--my', (((e.clientY - b.top) / b.height) * 100).toFixed(1) + '%');
          bloc.classList.add('est-eclairee');
        }, { passive: true });
        bloc.addEventListener('pointerleave', function () { bloc.classList.remove('est-eclairee'); });
      }

      heros.addEventListener('pointermove', function (e) {
        if (e.pointerType !== 'mouse') return;
        var r = heros.getBoundingClientRect();
        // De -1 à 1 depuis le centre, puis au plus cinq degrés : au-delà, la
        // capture devient illisible et l'effet se voit plus que l'écran.
        vise.x = ((e.clientX - r.left) / r.width - 0.5) * 2 * 5;
        vise.y = ((e.clientY - r.top) / r.height - 0.5) * -2 * 3.2;
        heros.classList.add('is-menee');
        relancer();
      });
      heros.addEventListener('pointerleave', function () {
        vise.x = 0; vise.y = 0;
        relancer();
        // La transition longue revient pour le retour à plat : c'est le seul
        // moment où l'on veut que la fenêtre prenne son temps.
        setTimeout(function () { heros.classList.remove('is-menee'); }, 340);
      });
    }

    // Sous le pointeur, on regarde : la fenêtre ne change pas d'écran au
    // milieu d'une lecture.
    heros.addEventListener('pointerenter', arreter);
    heros.addEventListener('pointerleave', function () { if (!choisi) tourner(); });
    heros.addEventListener('focusin', arreter);

    /* Le défilement ne part qu'une fois la page arrivée et la fenêtre en
       vue : les deux écrans suivants sont des images différées, et les
       chercher pendant le chargement retarderait le premier — celui que le
       navigateur mesure. */
    var lancer = function () {
      if ('IntersectionObserver' in window) {
        var vu = new IntersectionObserver(function (entrees) {
          entrees.forEach(function (entree) {
            if (entree.isIntersecting) tourner(); else arreter();
          });
        }, { threshold: 0.35 });
        vu.observe(heros);
      } else {
        tourner();
      }
    };
    if (document.readyState === 'complete') setTimeout(lancer, 1200);
    else window.addEventListener('load', function () { setTimeout(lancer, 1200); });
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
