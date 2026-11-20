(function () {
  /**
   * Allumer une application, et récupérer ses achats, dans une fenêtre.
   *
   * L'écran « Applications » montre des fiches avec, sous chacune, une réserve
   * — ce que l'option demande, ce qu'elle ne fait pas. Cette réserve a une
   * place fixe sur la fiche parce que c'est la partie qu'on est tenté de ne
   * pas lire. La fenêtre la remontre juste avant d'allumer, pour la même
   * raison : c'est la dernière occasion de comprendre ce qu'on active.
   *
   * Sans JavaScript, les formulaires partent comme avant.
   */

  var racine = document.getElementById('applications');
  if (!racine || !window.Fenetre) return;

  var mots = JSON.parse(document.getElementById('boutique-libelles').textContent);
  var jeton = racine.dataset.csrf;

  function envoyer(chemin) {
    var corps = new URLSearchParams();
    corps.set('_csrf', jeton);

    return fetch(chemin, {
      method: 'POST',
      headers: { 'X-Fenetre': '1', 'Content-Type': 'application/x-www-form-urlencoded' },
      body: corps.toString(),
    }).then(function (r) {
      return r.text().then(function (texte) {
        try {
          return { ok: r.ok, corps: JSON.parse(texte) };
        } catch (e) {
          return { ok: false, corps: { ok: false, message: mots.echec, detail: mots.illisible.replace('{code}', r.status) } };
        }
      });
    }).catch(function (err) {
      return { ok: false, corps: { ok: false, message: mots.echec, detail: err.message } };
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
    return boite;
  }

  /** La réserve de la fiche, remontrée telle quelle avant d'allumer. */
  function reserve(parent, texte) {
    if (!texte) return;
    var bloc = document.createElement('div');
    bloc.className = 'banner is-warning';
    bloc.textContent = texte;
    parent.appendChild(bloc);
  }

  function allumer(fiche) {
    window.Fenetre.ouvrir({
      titre: mots.titreAllumer.replace('{nom}', fiche.nom),
      fermerLabel: mots.fermer,
      remplir: function (corps, fenetre) {
        if (fiche.resume) window.Fenetre.texte(corps, fiche.resume);
        reserve(corps, fiche.reserve);

        fenetre.bouton(mots.annuler, { faire: fenetre.fermer });
        fenetre.bouton(mots.allumer, {
          principal: true,
          faire: function () {
            fenetre.occuper(true);
            envoyer('/applications/' + encodeURIComponent(fiche.cle) + '/allumer').then(function (reponse) {
              var resultat = reponse.corps || {};
              fenetre.occuper(false);
              fenetre.vider();
              verdict(fenetre.corps, Boolean(resultat.ok), resultat.message || mots.echec, resultat.detail || '');

              if (resultat.ok && resultat.vers && resultat.vers !== '/applications') {
                // On propose d'aller à l'écran qu'on vient d'ouvrir : c'est ce
                // qu'on voulait, pas la boutique une seconde fois.
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

  function recuperer() {
    window.Fenetre.ouvrir({
      titre: mots.titreRecuperer,
      fermerLabel: mots.fermer,
      remplir: function (corps, fenetre) {
        window.Fenetre.texte(corps, mots.enRecuperation);
        fenetre.occuper(true);

        envoyer('/applications/recuperer').then(function (reponse) {
          var resultat = reponse.corps || {};
          fenetre.occuper(false);
          fenetre.vider();
          verdict(fenetre.corps, Boolean(resultat.ok), resultat.message || mots.echec, resultat.detail || '');
          fenetre.bouton(mots.fermer, {
            principal: true, toujoursActif: true,
            faire: function () { window.location.reload(); },
          });
        });
      },
    });
  }

  racine.addEventListener('click', function (e) {
    var bouton = e.target.closest('[data-fenetre]');
    if (!bouton) return;

    e.preventDefault();
    if (bouton.dataset.fenetre === 'recuperer') return recuperer();
    if (bouton.dataset.fenetre === 'allumer') {
      return allumer({
        cle: bouton.dataset.cle,
        nom: bouton.dataset.nom,
        resume: bouton.dataset.resume || '',
        reserve: bouton.dataset.reserve || '',
      });
    }
    return undefined;
  });
}());
