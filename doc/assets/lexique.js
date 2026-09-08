/* Explorateur du lexique : les 16 dictionnaires du produit, cherchables.
   Les données sont extraites de src/locales/ au moment de la construction du
   site — ce tableau ne peut donc pas diverger de ce que le logiciel affiche. */

(function () {
  'use strict';

  var mount = document.getElementById('lex');
  if (!mount) return;

  var base = document.documentElement.getAttribute('data-base') || '';
  var search = document.getElementById('lex-search');
  var langSel = document.getElementById('lex-lang');
  var groupSel = document.getElementById('lex-group');
  var count = document.getElementById('lex-count');
  var body = document.querySelector('#lex-table tbody');
  var head = document.querySelector('#lex-table thead tr');
  var more = document.getElementById('lex-more');

  var PAGE = 80;
  var data = null;
  var shown = PAGE;

  var fold = function (value) {
    return String(value).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  };

  var escape = function (value) {
    return String(value).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  };

  function matching() {
    var query = fold(search.value.trim());
    var group = groupSel.value;
    return data.keys.filter(function (key) {
      if (group && key.indexOf(group + '.') !== 0) return false;
      if (!query) return true;
      if (fold(key).indexOf(query) !== -1) return true;
      // On cherche aussi dans les valeurs, toutes langues confondues :
      // retrouver une clé à partir du mot vu à l'écran est le cas courant.
      for (var i = 0; i < data.locales.length; i++) {
        var value = data.entries[key][data.locales[i].code];
        if (value && fold(value).indexOf(query) !== -1) return true;
      }
      return false;
    });
  }

  function columns() {
    var picked = langSel.value;
    if (picked === 'all') return data.locales;
    var out = [data.locales[0]];
    if (picked !== 'fr') {
      var found = data.locales.filter(function (l) { return l.code === picked; })[0];
      if (found) out.push(found);
    }
    return out;
  }

  function render() {
    var rows = matching();
    var cols = columns();

    head.innerHTML = '<th>Clé</th>' + cols.map(function (l) {
      return '<th>' + l.flag + ' ' + escape(l.label) + '</th>';
    }).join('');

    var slice = rows.slice(0, shown);
    body.innerHTML = slice.map(function (key) {
      var cells = cols.map(function (l) {
        var value = data.entries[key][l.code] || '';
        return '<td lang="' + l.code + '"' + (l.dir === 'rtl' ? ' dir="rtl"' : '') + '>' + escape(value) + '</td>';
      }).join('');
      return '<tr><td class="mono">' + escape(key) + '</td>' + cells + '</tr>';
    }).join('');

    count.textContent = rows.length + ' clé' + (rows.length > 1 ? 's' : '')
      + (rows.length > slice.length ? ' — ' + slice.length + ' affichées' : '');
    more.hidden = rows.length <= slice.length;
  }

  function reset() { shown = PAGE; render(); }

  fetch(base + 'data/lexique.json')
    .then(function (r) { return r.json(); })
    .then(function (payload) {
      data = payload;
      langSel.innerHTML = '<option value="all">Toutes les langues</option>'
        + data.locales.map(function (l) {
          return '<option value="' + l.code + '">' + l.flag + ' ' + escape(l.label) + '</option>';
        }).join('');
      langSel.value = 'en';

      groupSel.innerHTML = '<option value="">Toutes les familles</option>'
        + data.groups.map(function (g) {
          return '<option value="' + g.name + '">' + g.name + ' (' + g.count + ')</option>';
        }).join('');

      search.addEventListener('input', reset);
      langSel.addEventListener('change', reset);
      groupSel.addEventListener('change', reset);
      more.querySelector('button').addEventListener('click', function () { shown += PAGE; render(); });
      render();
    })
    .catch(function () {
      mount.innerHTML = '<p class="warn">Le lexique n\'a pas pu être chargé. '
        + 'Ouvrez le site depuis un serveur web plutôt que directement depuis le disque.</p>';
    });
})();
