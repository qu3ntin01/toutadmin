(function () {
  /**
   * La pose d'une version, dans une fenêtre.
   *
   * Avant, appliquer une mise à jour envoyait un formulaire, la page partait,
   * et on regardait un onglet blanc pendant que l'instance téléchargeait,
   * vérifiait une signature et remplaçait son propre code. Quand elle
   * revenait, un bandeau disait « posée » ou « refusée » — sans rien de ce qui
   * s'était passé entre les deux.
   *
   * La fenêtre montre les étapes réelles : elles viennent de la base, où la
   * pose les écrit en avançant. Rafraîchir la page pendant une pose ne perd
   * donc pas le fil, et rien ici n'est une animation décorative — un point qui
   * tourne devant une étape veut dire que l'instance y est.
   *
   * Ce fichier est le même que celui de l'édition Node, à une chose près : ici
   * rien ne redémarre. La dernière étape est un contrôle, pas une attente.
   *
   * Sans JavaScript, les formulaires partent comme avant : la fenêtre est un
   * confort, pas une condition.
   */

  var racine = document.getElementById('mises-a-jour');
  if (!racine || !window.Fenetre) return;

  var mots = JSON.parse(document.getElementById('maj-libelles').textContent);
  var jeton = racine.dataset.csrf;

  /**
   * Un POST qui demande une réponse de fenêtre plutôt qu'une redirection.
   *
   * Le corps est encodé comme celui d'un formulaire : c'est ce que
   * l'application analyse, et c'est là qu'elle va chercher le jeton anti-CSRF.
   * Envoyer du JSON revenait à ne pas présenter de jeton du tout, et à se
   * faire refuser par la protection qu'on croyait satisfaire.
   */
  function envoyer(chemin) {
    var corps = new URLSearchParams();
    corps.set('_csrf', jeton);

    return fetch(chemin, {
      method: 'POST',
      headers: { 'X-Fenetre': '1', 'Content-Type': 'application/x-www-form-urlencoded' },
      body: corps.toString(),
    }).then(lireLaReponse);
  }

  /**
   * Lit une réponse sans supposer qu'elle est du JSON.
   *
   * Un refus au niveau du serveur — jeton périmé, session expirée, proxy —
   * rend une page HTML. La parser en JSON jetait une exception qui n'était
   * rattrapée nulle part, et la fenêtre restait occupée : boutons éteints,
   * échappement sans effet, plus aucune sortie.
   */
  function lireLaReponse(r) {
    return r.text().then(function (texte) {
      try {
        return { ok: r.ok, corps: JSON.parse(texte) };
      } catch (e) {
        return {
          ok: false,
          corps: { ok: false, message: mots.echec, detail: mots.reponseIllisible.replace('{code}', r.status) },
        };
      }
    });
  }

  // ---------- Les étapes ----------

  function listeEtapes(parent, etapes) {
    var liste = document.createElement('ol');
    liste.className = 'fenetre-etapes';

    etapes.forEach(function (nom) {
      var ligne = document.createElement('li');
      ligne.className = 'fenetre-etape';
      ligne.dataset.etape = nom;

      var puce = document.createElement('span');
      puce.className = 'puce';
      ligne.appendChild(puce);

      var texte = document.createElement('span');
      texte.textContent = mots['etape.' + nom] || nom;
      ligne.appendChild(texte);

      liste.appendChild(ligne);
    });

    parent.appendChild(liste);
    return liste;
  }

  /**
   * Marque l'avancement.
   *
   * Tout ce qui précède l'étape courante est fait — c'est vrai par
   * construction, la pose est séquentielle et ne revient pas en arrière.
   */
  function marquer(liste, rang, ratee) {
    Array.prototype.forEach.call(liste.children, function (ligne, index) {
      ligne.classList.remove('faite', 'courante', 'ratee');
      var puce = ligne.querySelector('.puce');
      if (index < rang) {
        ligne.classList.add('faite');
        puce.textContent = '✓';
      } else if (index === rang) {
        ligne.classList.add(ratee ? 'ratee' : 'courante');
        puce.textContent = ratee ? '✕' : '';
      } else {
        puce.textContent = '';
      }
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

  // ---------- Poser une version ----------

  function poser(version, notes) {
    window.Fenetre.ouvrir({
      titre: mots.titrePose.replace('{version}', version || ''),
      fermerLabel: mots.fermer,
      remplir: function (corps, fenetre) {
        window.Fenetre.texte(corps, mots.avantPose);

        if (notes) {
          var bloc = document.createElement('div');
          bloc.className = 'fenetre-notes';
          bloc.textContent = notes;
          corps.appendChild(bloc);
        }

        fenetre.bouton(mots.annuler, { faire: fenetre.fermer });
        fenetre.bouton(mots.poser, {
          principal: true,
          faire: function () { lancerLaPose(fenetre, version); },
        });
      },
    });
  }

  function lancerLaPose(fenetre, version) {
    fenetre.vider().titrer(mots.titreEnCours.replace('{version}', version || ''));
    fenetre.occuper(true);

    var liste = listeEtapes(fenetre.corps, JSON.parse(racine.dataset.etapes));
    marquer(liste, 0, false);

    // On interroge pendant que l'autre requête travaille : l'étape vient de la
    // base, donc de la pose elle-même, et non d'une horloge côté navigateur.
    var battement = setInterval(function () {
      fetch('/mises-a-jour/avancement', { headers: { 'X-Fenetre': '1' } })
        .then(function (r) { return r.json(); })
        .then(function (etat) {
          if (etat.enCours) marquer(liste, etat.enCours.rang, false);
        })
        .catch(function () { /* Une interrogation perdue n'arrête pas la pose. */ });
    }, 1200);

    // Le bouton de fermeture reste cliquable : fermer la fenêtre n'annule pas
    // la pose — rien ne peut l'annuler une fois commencée —, cela cesse
    // seulement de la regarder. Le libellé le dit.
    var arreterDeRegarder = fenetre.bouton(mots.masquer, {
      toujoursActif: true,
      faire: function () { clearInterval(battement); fenetre.fermer(); },
    });

    envoyer('/mises-a-jour/appliquer').then(function (reponse) {
      clearInterval(battement);
      fenetre.occuper(false);

      var corps = reponse.corps || {};
      if (corps.ok) {
        marquer(liste, liste.children.length, false);
        verdict(fenetre.corps, true, corps.message, corps.redemarrage ? mots.redemarrage : '');
      } else {
        // L'étape ratée est celle où la pose s'est arrêtée : on la lit dans la
        // liste plutôt que de la deviner.
        var courante = liste.querySelector('.courante');
        marquer(liste, courante ? Array.prototype.indexOf.call(liste.children, courante) : 0, true);
        verdict(fenetre.corps, false, corps.message || mots.echec, corps.detail || '');
      }

      arreterDeRegarder.remove();
      fenetre.bouton(mots.fermerEtRecharger, {
        principal: true, toujoursActif: true,
        faire: function () { window.location.reload(); },
      });
    }).catch(function (err) {
      clearInterval(battement);
      fenetre.occuper(false);
      verdict(fenetre.corps, false, mots.echec, err.message);
    });
  }

  // ---------- Vérifier ----------

  function verifier() {
    window.Fenetre.ouvrir({
      titre: mots.titreVerif,
      fermerLabel: mots.fermer,
      remplir: function (corps, fenetre) {
        window.Fenetre.texte(corps, mots.enVerif);
        fenetre.occuper(true);

        envoyer('/mises-a-jour/verifier').catch(function (err) {
          return { ok: false, corps: { ok: false, message: mots.echec, detail: err.message } };
        }).then(function (reponse) {
          var resultat = reponse.corps || {};
          fenetre.occuper(false);
          fenetre.vider();

          if (!resultat.ok) {
            verdict(fenetre.corps, false, resultat.message, resultat.detail || '');
            fenetre.bouton(mots.fermer, { toujoursActif: true, faire: fenetre.fermer });
            return;
          }

          verdict(fenetre.corps, true, resultat.message, '');
          if (resultat.disponible && resultat.disponible.notes) {
            var bloc = document.createElement('div');
            bloc.className = 'fenetre-notes';
            bloc.textContent = resultat.disponible.notes;
            fenetre.corps.appendChild(bloc);
          }

          fenetre.bouton(mots.fermer, { toujoursActif: true, faire: function () { window.location.reload(); } });
          if (resultat.disponible && racine.dataset.immuable !== '1') {
            fenetre.bouton(mots.poser, {
              principal: true,
              faire: function () { lancerLaPose(fenetre, resultat.disponible.version); },
            });
          }
        });
      },
    });
  }

  // ---------- Revenir en arrière ----------

  function revenir(version) {
    window.Fenetre.ouvrir({
      titre: mots.titreRetour,
      fermerLabel: mots.fermer,
      classe: 'dialog-danger',
      remplir: function (corps, fenetre) {
        window.Fenetre.texte(corps, mots.avantRetour.replace('{version}', version || ''));

        fenetre.bouton(mots.annuler, { faire: fenetre.fermer });
        fenetre.bouton(mots.revenir, {
          classe: 'btn-destroy',
          faire: function () {
            fenetre.occuper(true);
            envoyer('/mises-a-jour/retour').catch(function (err) {
              return { ok: false, corps: { ok: false, message: mots.echec, detail: err.message } };
            }).then(function (reponse) {
              var resultat = reponse.corps || {};
              fenetre.occuper(false);
              fenetre.vider();
              verdict(fenetre.corps, Boolean(resultat.ok), resultat.message || mots.echec, resultat.detail || '');
              fenetre.bouton(mots.fermerEtRecharger, {
                principal: true, toujoursActif: true,
                faire: function () { window.location.reload(); },
              });
            });
          },
        });
      },
    });
  }

  // ---------- Les boutons de l'écran ----------

  racine.addEventListener('click', function (e) {
    var bouton = e.target.closest('[data-fenetre]');
    if (!bouton) return;

    e.preventDefault();
    var quoi = bouton.dataset.fenetre;
    if (quoi === 'verifier') verifier();
    else if (quoi === 'poser') poser(bouton.dataset.version, bouton.dataset.notes || '');
    else if (quoi === 'retour') revenir(bouton.dataset.version);
  });
}());
