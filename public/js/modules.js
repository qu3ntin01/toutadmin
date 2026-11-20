(function () {
  /**
   * Activer ou retirer un module optionnel, dans une fenêtre.
   *
   * Un module qu'on active change le rail, les écrans, parfois les droits.
   * Un module qu'on retire ne perd rien — ses données restent —, mais la
   * phrase qui le dit passait avant dans un bandeau qu'on lisait après coup,
   * une fois l'écran disparu du rail. Elle est maintenant devant le bouton.
   *
   * Sans JavaScript, les formulaires partent comme avant.
   */

  var racine = document.getElementById('console-admin');
  if (!racine || !window.Fenetre) return;

  var source = document.getElementById('modules-libelles');
  if (!source) return;
  var mots = JSON.parse(source.textContent);
  var jeton = racine.dataset.csrf;

  function envoyer(cle, allumer) {
    var corps = new URLSearchParams();
    corps.set('_csrf', jeton);
    if (allumer) corps.set('enabled', 'on');

    return fetch('/admin/modules/' + encodeURIComponent(cle), {
      method: 'POST',
      headers: { 'X-Fenetre': '1', 'Content-Type': 'application/x-www-form-urlencoded' },
      body: corps.toString(),
    }).then(function (r) {
      return r.text().then(function (texte) {
        try {
          return JSON.parse(texte);
        } catch (e) {
          return { ok: false, message: mots.echec, detail: mots.illisible.replace('{code}', r.status) };
        }
      });
    }).catch(function (err) {
      return { ok: false, message: mots.echec, detail: err.message };
    });
  }

  function verdict(parent, reussi, message, detail) {
    var boite = document.createElement('div');
    boite.className = 'fenetre-verdict ' + (reussi ? 'reussi' : 'rate');
    boite.setAttribute('role', 'status');
    var texte = document.createElement('div');
    texte.textContent = message;
    if (detail) {
      var petit = document.createElement('span');
      petit.className = 'detail';
      petit.textContent = detail;
      texte.appendChild(petit);
    }
    boite.appendChild(texte);
    parent.appendChild(boite);
  }

  function basculer(module) {
    var allumer = !module.actif;

    window.Fenetre.ouvrir({
      titre: (allumer ? mots.titreActiver : mots.titreRetirer).replace('{nom}', module.nom),
      fermerLabel: mots.fermer,
      classe: allumer ? '' : 'dialog-danger',
      remplir: function (corps, fenetre) {
        window.Fenetre.texte(corps, allumer ? module.quoi : mots.donneesGardees);

        if (allumer && module.reserve) {
          var bandeau = document.createElement('div');
          bandeau.className = 'banner is-warning';
          bandeau.textContent = module.reserve;
          corps.appendChild(bandeau);
        }

        fenetre.bouton(mots.annuler, { faire: fenetre.fermer });
        fenetre.bouton(allumer ? mots.activer : mots.retirer, {
          principal: allumer,
          classe: allumer ? 'btn-primary' : 'btn-destroy',
          faire: function () {
            fenetre.occuper(true);
            envoyer(module.cle, allumer).then(function (resultat) {
              fenetre.occuper(false);
              fenetre.vider();
              verdict(fenetre.corps, Boolean(resultat.ok), resultat.message || mots.echec, resultat.detail || '');

              if (resultat.ok && resultat.vers) {
                fenetre.bouton(mots.rester, { toujoursActif: true, faire: function () { window.location.reload(); } });
                fenetre.bouton(mots.ouvrir, {
                  principal: true, toujoursActif: true,
                  faire: function () { window.location.href = resultat.vers; },
                });
              } else {
                fenetre.bouton(mots.fermer, { principal: true, toujoursActif: true, faire: function () { window.location.reload(); } });
              }
            });
          },
        });
      },
    });
  }

  racine.addEventListener('click', function (e) {
    var bouton = e.target.closest('[data-fenetre="module"]');
    if (!bouton) return;

    e.preventDefault();
    basculer({
      cle: bouton.dataset.cle,
      nom: bouton.dataset.nom,
      quoi: bouton.dataset.quoi || '',
      reserve: bouton.dataset.reserve || '',
      actif: bouton.dataset.actif === '1',
    });
  });
}());
