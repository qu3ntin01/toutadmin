/**
 * L'assistant d'installation.
 *
 * Deux choses seulement : les choix qui se peignent quand on les prend, et la
 * pose finale, qui appelle le serveur un palier à la fois.
 *
 * La barre n'avance que sur une réponse. C'est le point important : une barre
 * qui court toute seule pendant qu'on attend rassure exactement au moment où
 * elle devrait alerter, et cette page-là est celle où l'on tient à savoir que
 * quelque chose s'est mal passé.
 */
(function () {
  'use strict';

  /* ---------- Les choix ---------- */

  function peindre(nom, classeParente) {
    var boutons = document.querySelectorAll('input[name="' + nom + '"]');
    if (!boutons.length) return;
    Array.prototype.forEach.call(boutons, function (bouton) {
      bouton.addEventListener('change', function () {
        Array.prototype.forEach.call(boutons, function (autre) {
          var carte = autre.closest('.' + classeParente);
          if (carte) carte.classList.toggle('is-picked', autre.checked);
        });
      });
    });
  }

  peindre('locale', 'choice');
  peindre('db_driver', 'engine');

  /* Les champs MySQL ne se montrent que si MySQL est choisi — et ne sont
     exigés qu'alors : un champ obligatoire mais caché bloque l'envoi sans rien
     dire à personne. */
  var moteurs = document.querySelectorAll('[data-engine]');
  var champsMysql = document.querySelector('[data-mysql]');
  if (moteurs.length && champsMysql) {
    var exiges = ['db_host', 'db_name', 'db_user'];
    var ajuster = function () {
      var mysql = document.querySelector('[data-engine][value="mysql"]');
      var actif = !!(mysql && mysql.checked);
      champsMysql.hidden = !actif;
      exiges.forEach(function (nom) {
        var champ = champsMysql.querySelector('[name="' + nom + '"]');
        if (champ) champ.required = actif;
      });
    };
    Array.prototype.forEach.call(moteurs, function (moteur) {
      moteur.addEventListener('change', ajuster);
    });
    ajuster();
  }

  /* ---------- La pose ---------- */

  var scene = document.querySelector('[data-run]');
  if (!scene) return;

  var bouton = scene.querySelector('[data-launch]');
  var retour = scene.querySelector('[data-back]');
  var recap = scene.querySelector('[data-recap]');
  var actions = scene.querySelector('.install-actions');
  var erreur = scene.querySelector('[data-error]');
  var barre = document.querySelector('[data-progress]');
  var jauge = barre ? barre.parentNode : null;
  var mots = {};
  try {
    mots = JSON.parse(scene.getAttribute('data-labels') || '{}');
  } catch (e) {
    mots = {};
  }

  var paliers = Array.prototype.map.call(
    scene.querySelectorAll('[data-step]'),
    function (ligne) { return ligne.getAttribute('data-step'); }
  );

  function ligneDe(palier) {
    return scene.querySelector('[data-step="' + palier + '"]');
  }

  /* La barre ne repart pas de zéro : les cinq étapes franchies l'ont déjà
     menée aux cinq sixièmes, et les quatre paliers se partagent ce qui reste. */
  var depart = parseInt(scene.getAttribute('data-from') || '0', 10) || 0;

  function avancer(fraction) {
    if (!barre) return;
    var pour = Math.round(depart + (100 - depart) * fraction);
    barre.style.width = pour + '%';
    if (jauge) jauge.setAttribute('aria-valuenow', String(pour));
  }

  /*
     Le travail est réel et souvent très rapide : poser cent quarante-sept
     tables prend quelques dizaines de millisecondes. Chaque palier reste donc
     affiché un instant avant que le suivant parte — assez pour qu'on voie ce
     qui s'est passé. Ce délai ne porte que l'affichage : un palier marqué fait
     est fait, et un échec s'affiche sans attendre.
  */
  var DUREE_MINIMALE = 480;

  function apres(debut, suite) {
    var reste = Math.max(0, DUREE_MINIMALE - (Date.now() - debut));
    if (reste === 0) {
      suite();
    } else {
      window.setTimeout(suite, reste);
    }
  }

  function poser(palier) {
    var jeton = scene.querySelector('[name="_csrf"]');
    var corps = new URLSearchParams();
    corps.set('_csrf', jeton ? jeton.value : '');
    /* « /install/… », comme la route : l'assistant vit sous /install, et
       /installation n'est qu'une redirection de courtoisie — qu'un navigateur
       transforme en GET sur un POST, si bien que la pose échouait en 404 sans
       que rien ne le dise. */
    return fetch('/install/executer/' + palier, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: corps.toString(),
      credentials: 'same-origin'
    }).then(function (reponse) {
      return reponse.json().catch(function () {
        /* Une réponse qui n'est pas du JSON est une erreur du serveur qui n'a
           pas pu se dire proprement — une page d'erreur de l'hébergement, un
           404, un 502. On ne la fait pas passer pour un succès, et surtout on
           dit laquelle : « échec » tout court n'apprend rien à qui doit
           réparer, et c'est précisément ce qu'on voit quand l'adresse appelée
           n'existe pas. */
        return {
          ok: false,
          message: (mots.httpError || 'Le serveur a répondu {statut} sur {adresse}.')
            .replace('{statut}', String(reponse.status))
            .replace('{adresse}', '/install/executer/' + palier)
        };
      });
    }).catch(function (souci) {
      /* La requête n'est jamais partie, ou n'est jamais revenue : coupure,
         serveur arrêté, proxy qui referme. Sans cette prise, la promesse reste
         en suspens et l'écran tourne indéfiniment sans rien dire. */
      return {
        ok: false,
        message: (mots.networkError || 'Le serveur n’a pas répondu ({detail}).')
          .replace('{detail}', (souci && souci.message) || 'requête interrompue')
      };
    });
  }

  function echouer(palier, message) {
    var ligne = ligneDe(palier);
    if (ligne) {
      ligne.classList.remove('is-running');
      ligne.classList.add('is-failed');
      var dit = ligne.querySelector('[data-said]');
      if (dit) dit.textContent = mots.failed || '';
    }
    if (erreur) {
      erreur.textContent = message;
      erreur.hidden = false;
    }
    scene.classList.remove('is-running');
    if (bouton) {
      bouton.disabled = false;
      bouton.classList.remove('is-working');
    }
    if (retour) retour.hidden = false;
  }

  function enchainer(index) {
    if (index >= paliers.length) return;
    var palier = paliers[index];
    var ligne = ligneDe(palier);
    var debut = Date.now();
    if (ligne) ligne.classList.add('is-running');

    poser(palier).then(function (reponse) {
      if (!reponse || !reponse.ok) {
        echouer(palier, (reponse && reponse.message) || mots.failed || '');
        return;
      }
      apres(debut, function () {
        if (ligne) {
          ligne.classList.remove('is-running');
          ligne.classList.add('is-done');
          var dit = ligne.querySelector('[data-said]');
          if (dit) dit.textContent = reponse.message || mots.done || '';
        }
        avancer((index + 1) / paliers.length);

        if (reponse.suite) {
          scene.classList.add('is-finished');
          if (actions) actions.hidden = true;
          if (erreur) {
            erreur.textContent = mots.redirect || '';
            erreur.hidden = false;
            erreur.classList.add('is-good');
          }
          // Une seconde pour que la dernière coche se voie : elle est le seul
          // signe que l'installation s'est achevée, et non interrompue.
          window.setTimeout(function () { window.location.href = reponse.suite; }, 1100);
          return;
        }
        enchainer(index + 1);
      });
    }).catch(function (souci) {
      echouer(palier, souci && souci.message ? souci.message : (mots.failed || ''));
    });
  }

  if (bouton) {
    bouton.addEventListener('click', function () {
      bouton.disabled = true;
      bouton.classList.add('is-working');
      if (retour) retour.hidden = true;
      if (recap) recap.hidden = true;
      if (erreur) { erreur.hidden = true; erreur.classList.remove('is-good'); }
      scene.classList.add('is-running');
      Array.prototype.forEach.call(scene.querySelectorAll('[data-step]'), function (ligne) {
        ligne.classList.remove('is-done', 'is-failed', 'is-running');
        var dit = ligne.querySelector('[data-said]');
        if (dit) dit.textContent = '';
      });
      avancer(0);
      enchainer(0);
    });
  }
})();

/**
 * L'étape « Licence » : vérifier avant d'aller plus loin.
 *
 * Le principe de la barre de pose vaut ici aussi, et il compte : **une barre
 * ne prétend jamais avancer quand elle attend**. Pendant l'appel au panneau,
 * la barre est indéterminée — l'animation native d'un « progress » sans
 * valeur —, ce qui montre que quelque chose se passe sans mentir sur où l'on
 * en est. Elle ne se remplit qu'à la réponse, et la couleur dit laquelle.
 *
 * « Suivant » naît désactivé à cette étape et n'est rendu qu'après un oui.
 * C'est ici qu'on veut découvrir qu'une clé est refusée — pas une fois les
 * données du client dedans.
 */
(function () {
  'use strict';

  var bloc = document.querySelector('[data-licence-check]');
  if (!bloc) return;

  var form = bloc.closest('form');
  /* La barre vit dans la barre d'actions, à côté de « Suivant » : c'est là
     qu'on regarde quand on veut avancer. Le compte rendu, lui, reste près des
     champs, où il a la place de s'expliquer. */
  var barre = document.querySelector('[data-licence-bar]');
  var dire = bloc.querySelector('[data-licence-say]');
  var suivant = document.querySelector('[data-install-next]');
  var passer = document.querySelector('[data-licence-skip]');

  var mots = {};
  try {
    mots = JSON.parse(bloc.getAttribute('data-labels') || '{}');
  } catch (e) {
    mots = {};
  }

  function element(nom) {
    return form ? form.querySelector('[name="' + nom + '"]') : null;
  }

  function champ(nom) {
    var el = element(nom);
    return el ? String(el.value || '').trim() : '';
  }

  /* Ce qu'on a déjà éprouvé, pour ne pas le refaire à chaque sortie de champ.
     Un client qui entre dans le champ, n'y touche pas et en ressort ne doit
     pas relancer un aller-retour vers le panneau. */
  var derniereEprouvee = bloc.classList.contains('is-ok') ? champ('licence_cle') : null;
  var enCours = false;

  function repartirAZero() {
    if (suivant) suivant.disabled = true;
    if (barre) barre.hidden = true;
    bloc.classList.remove('is-ok', 'is-ko', 'is-warn');
    if (dire) dire.textContent = '';
  }

  /*
   * Éprouver la clé.
   *
   * Il n'y a plus de bouton : on éprouve dès qu'une clé est là. C'est le seul
   * moment où cela a un sens, et un bouton de plus n'aurait rien appris à
   * personne. L'appel n'engage rien — la route d'essai du panneau ne lie
   * aucune instance —, on peut donc le refaire autant de fois qu'il le faut.
   */
  function eprouver() {
    var cle = champ('licence_cle');
    if (cle === '' || enCours || cle === derniereEprouvee) return;

    var corps = new URLSearchParams({
      licence_cle: cle,
      licence_jeton: champ('licence_jeton'),
      // La route est une écriture comme une autre : elle veut son jeton.
      _csrf: champ('_csrf'),
    });

    enCours = true;
    bloc.classList.remove('is-ok', 'is-ko', 'is-warn');
    if (barre) {
      barre.hidden = false;
      // Sans valeur : la barre s'anime d'elle-même et n'annonce aucun
      // avancement. C'est exactement ce qu'on sait — on attend.
      barre.removeAttribute('value');
    }
    if (dire) dire.textContent = mots.checking || '';

    fetch('/install/licence/verifier', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: corps.toString(),
    }).then(function (r) {
      return r.json();
    }).then(function (reponse) {
      // La barre n'avait qu'une chose à dire — « on attend » — et la réponse
      // vient de la dire mieux qu'elle. La laisser pleine à côté de « Licence
      // valide » n'ajoute rien et alourdit l'écran.
      if (barre) barre.hidden = true;
      enCours = false;

      if (reponse && reponse.ok) {
        derniereEprouvee = cle;
        bloc.classList.add('is-ok');
        var phrase = mots.valid || '';
        if (reponse.membres && mots.members) {
          phrase += ' ' + mots.members.replace('{membres}', String(reponse.membres));
        }
        if (reponse.liee && mots.bound) phrase += ' ' + mots.bound;
        if (dire) dire.textContent = phrase;
        if (suivant) suivant.disabled = false;
        return;
      }

      if (suivant) suivant.disabled = true;

      /*
       * Deux échecs très différents, et les confondre coûterait une heure au
       * client : « le panneau a refusé votre licence » et « je n'ai pas pu
       * joindre le panneau » n'appellent pas le même coup de téléphone. Le
       * second n'est même pas une faute de licence — un pare-feu applicatif,
       * un proxy, une page de maintenance — et il se signale en jaune.
       */
      if (reponse && reponse.injoignable) {
        bloc.classList.add('is-warn');
        var quoi = mots.unreachable || '';
        if (reponse.statut) {
          quoi += ' ' + (mots.unreachableDetail || '')
            .replace('{statut}', String(reponse.statut))
            .replace('{type}', String(reponse.type || '?'));
        }
        if (dire) dire.textContent = quoi;
        return;
      }

      bloc.classList.add('is-ko');
      // L'explication vient du panneau et nomme le refus en clair. À défaut,
      // le code du refus : moins agréable, mais toujours plus utile qu'un
      // « une erreur est survenue » qui n'apprend rien.
      var pourquoi = (reponse && (reponse.explication || reponse.raison)) || '';
      if (dire) dire.textContent = (mots.invalid || '') + (pourquoi ? ' ' + pourquoi : '');
    }).catch(function () {
      if (barre) barre.hidden = true;
      enCours = false;
      bloc.classList.add('is-ko');
      if (suivant) suivant.disabled = true;
      if (dire) dire.textContent = mots.invalid || '';
    });
  }

  /* Toute retouche d'un champ de licence invalide la vérification : sans cela,
     on vérifierait une clé puis on en installerait une autre. On éprouve à la
     sortie du champ, et non à la frappe : éprouver à chaque caractère ferait
     dix appels et neuf refus pour une clé tapée à la main. */
  ['licence_cle', 'licence_jeton'].forEach(function (nom) {
    var el = element(nom);
    if (!el) return;
    el.addEventListener('input', repartirAZero);
    el.addEventListener('change', eprouver);
    el.addEventListener('blur', eprouver);
  });

  /* « Continuer sans licence » n'a rien à vérifier : le bouton part tel quel,
     et il ne doit pas être retenu par un « Suivant » désactivé. */
  if (passer) {
    passer.addEventListener('click', function () {
      if (suivant) suivant.disabled = false;
    });
  }

  /*
   * ── Aller chercher sa licence dans l'espace client ─────────────────────
   *
   * Une fenêtre s'ouvre sur l'espace client. Le mot de passe s'y tape sur
   * l'hébergement de l'espace, sous son certificat : il ne traverse jamais ce
   * CMS-ci, qui vient d'être déballé et dont on ne sait rien encore. La clé
   * revient par « postMessage ».
   *
   * Trois précautions, et aucune n'est superflue :
   *
   *   — on n'accepte que les messages venus de l'origine de l'espace, celle
   *     qu'on a nous-même ouverte. Un message venu d'ailleurs est ignoré sans
   *     bruit ;
   *   — on n'accepte que pendant qu'une fenêtre est ouverte. Hors de ce
   *     moment-là, rien n'écoute ;
   *   — on annonce l'origine de retour à l'espace, qui la montre au client
   *     avant qu'il ne clique. C'est lui qui décide à qui va sa clé.
   */
  var liens = document.querySelector('[data-licence-liens]');
  if (liens) {
    var espace = String(liens.getAttribute('data-manager') || '').replace(/\/+$/, '');
    var edition = liens.getAttribute('data-edition') || 'node';
    var ditsLiens = {};
    try {
      ditsLiens = JSON.parse(liens.getAttribute('data-labels') || '{}');
    } catch (e2) {
      ditsLiens = {};
    }

    var fenetre = null;
    var voile = null;
    var guette = null;

    /* Le voile, posé par script et non dans le gabarit : il n'a de sens que
       lorsqu'une fenêtre est ouverte, et une fenêtre ne s'ouvre pas sans
       script. Ce qui n'existe que par le script se crée par le script. */
    function poserLeVoile() {
      if (voile) return;
      voile = document.createElement('div');
      voile.className = 'install-voile';
      voile.setAttribute('data-licence-voile', '');
      var mot = document.createElement('p');
      mot.textContent = ditsLiens.opening || '';
      voile.appendChild(mot);
      // Un clic sur le voile ramène la fenêtre au premier plan plutôt que de
      // fermer : elle est peut-être passée derrière, et c'est le réflexe.
      voile.addEventListener('click', function () {
        if (fenetre && !fenetre.closed) fenetre.focus();
      });
      document.body.appendChild(voile);
      document.body.classList.add('a-voile');
    }

    function retirerLeVoile() {
      if (guette) {
        window.clearInterval(guette);
        guette = null;
      }
      if (voile && voile.parentNode) voile.parentNode.removeChild(voile);
      voile = null;
      document.body.classList.remove('a-voile');
    }

    function adresseDe(chemin) {
      return espace + chemin
        + '?retour=' + encodeURIComponent(window.location.origin)
        + '&edition=' + encodeURIComponent(edition);
    }

    /* L'adresse complète est posée sur les liens eux-mêmes, dès le chargement.
       Le gabarit ne peut pas l'écrire : l'origine de cette instance et
       l'édition ne se connaissent qu'ici. C'est ce qui fait que le repli — un
       onglet, quand la fenêtre est bloquée — mène au bon endroit plutôt qu'à
       un espace client qui ne saurait pas où renvoyer la clé. */
    Array.prototype.forEach.call(liens.querySelectorAll('[data-licence-fenetre]'), function (lien) {
      if (espace === '') return;
      lien.href = adresseDe(lien.getAttribute('data-licence-fenetre') || '/installeur');
    });

    function ouvrir(chemin) {
      if (espace === '') return false;

      fenetre = window.open(adresseDe(chemin), 'toutadmin-licence',
        'width=520,height=680,menubar=no,toolbar=no,location=yes,resizable=yes,scrollbars=yes');

      /* Bloquée par le navigateur : on ne voile pas l'écran devant une fenêtre
         qui n'existe pas, et on ne retient pas le clic — le lien porte
         « target="_blank" » et son adresse complète, le navigateur l'ouvrira
         donc dans un onglet, où tout fonctionne pareil. Le message reste ici
         pour le cas où l'onglet aussi a été retenu. */
      if (!fenetre) {
        if (dire) dire.textContent = ditsLiens.blocked || '';
        bloc.classList.remove('is-ok', 'is-ko');
        bloc.classList.add('is-warn');
        return false;
      }

      poserLeVoile();
      fenetre.focus();
      // Refermée sans rien rapporter : on reprend la main. Il n'y a pas
      // d'événement pour cela, seulement la question qu'on repose.
      guette = window.setInterval(function () {
        if (!fenetre || fenetre.closed) retirerLeVoile();
      }, 400);
      return true;
    }

    Array.prototype.forEach.call(liens.querySelectorAll('[data-licence-fenetre]'), function (lien) {
      lien.addEventListener('click', function (evenement) {
        // Ouverte : on garde le clic. Bloquée : on le rend au navigateur, qui
        // ouvrira le lien dans un onglet — mieux vaut un onglet qu'une impasse,
        // et l'assistant reste où il est puisque l'onglet est neuf.
        if (ouvrir(lien.getAttribute('data-licence-fenetre') || '/installeur')) {
          evenement.preventDefault();
        }
      });
    });

    window.addEventListener('message', function (evenement) {
      // L'origine d'abord, avant même de regarder ce qu'il y a dedans.
      if (espace === '' || evenement.origin !== espace) return;
      if (!voile) return;

      var charge = evenement.data;
      if (!charge || charge.type !== 'toutadmin.licence') return;
      var recue = String(charge.cle || '').trim();
      if (recue === '') return;

      retirerLeVoile();
      if (fenetre && !fenetre.closed) fenetre.close();

      var champCle = element('licence_cle');
      if (!champCle) return;
      champCle.value = recue;
      repartirAZero();
      if (dire) dire.textContent = ditsLiens.received || '';
      // Et on active, sans rien demander de plus : le client vient de dire
      // « celle-là », il n'a pas à le redire ici.
      eprouver();
    });
  }
})();

/**
 * L'étape « Mises à jour ».
 *
 * La vérification part toute seule au chargement : l'utilisateur n'a rien à
 * demander, il veut juste savoir s'il peut continuer. Trois issues, et elles
 * n'ont pas le même poids :
 *
 *   à jour        vert, « Suivant » se rend, on passe ;
 *   en retard     rouge, « Suivant » reste gris, et un bouton propose de poser
 *                 la dernière version tout seul ;
 *   injoignable   jaune, et « Suivant » se rend quand même. Un client derrière
 *                 un pare-feu d'entreprise doit pouvoir installer son CMS, et
 *                 transformer une panne de réseau en produit inutilisable
 *                 serait une faute bien plus grave que de laisser poser une
 *                 version d'un mois.
 */
(function () {
  'use strict';

  var bloc = document.querySelector('[data-maj-check]');
  if (!bloc) return;

  var form = bloc.closest('form');
  var dire = bloc.querySelector('[data-maj-say]');
  var barre = document.querySelector('[data-maj-bar]');
  var poser = document.querySelector('[data-maj-poser]');
  var champCle = document.querySelector('[data-maj-cle]');
  var suivant = document.querySelector('[data-install-next]');

  var mots = {};
  try {
    mots = JSON.parse(bloc.getAttribute('data-labels') || '{}');
  } catch (e) {
    mots = {};
  }

  function valeur(nom) {
    var el = form ? form.querySelector('[name="' + nom + '"]') : null;
    return el ? String(el.value || '').trim() : '';
  }

  function peindre(classe, phrase) {
    bloc.classList.remove('is-ok', 'is-ko', 'is-warn');
    if (classe) bloc.classList.add(classe);
    if (dire) dire.textContent = phrase || '';
  }

  function attendre(phrase) {
    if (barre) {
      barre.hidden = false;
      // Sans valeur : la barre s'anime et n'annonce aucun avancement. C'est
      // exactement ce qu'on sait — on attend.
      barre.removeAttribute('value');
    }
    peindre('', phrase);
  }

  function verifier() {
    attendre(mots.checking);
    if (poser) poser.hidden = true;
    // La clé ne sert qu'à télécharger : tant qu'on ne sait pas s'il y a
    // quelque chose à télécharger, elle n'a rien à faire à l'écran. Et si une
    // vérification précédente l'avait fait apparaître, elle se retire —
    // laisser un champ qui ne sert plus donne à croire qu'il est attendu.
    if (champCle) champCle.hidden = true;
    if (suivant) suivant.disabled = true;

    return fetch('/install/mises-a-jour/verifier', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ _csrf: valeur('_csrf') }).toString(),
    }).then(function (r) {
      return r.json();
    }).then(function (vu) {
      if (barre) barre.hidden = true;

      if (!vu.ok) {
        var quoi = mots.unreachable || '';
        if (vu.statut) {
          quoi += ' ' + (mots.unreachableDetail || '')
            .replace('{statut}', String(vu.statut)).replace('{type}', String(vu.type || '?'));
        }
        peindre('is-warn', quoi);
        // On passe quand même : voir le commentaire en tête de ce bloc.
        if (suivant) suivant.disabled = false;
        return;
      }

      if (vu.aJour) {
        peindre('is-ok', (mots.uptodate || '').replace('{version}', String(vu.courante || '')));
        if (suivant) suivant.disabled = false;
        return;
      }

      peindre('is-ko', (mots.behind || '')
        .replace('{derniere}', String(vu.derniere || ''))
        .replace('{courante}', String(vu.courante || '')));
      if (poser) poser.hidden = false;
      if (champCle) champCle.hidden = false;
      if (suivant) suivant.disabled = true;
    }).catch(function () {
      if (barre) barre.hidden = true;
      peindre('is-warn', mots.unreachable || '');
      if (suivant) suivant.disabled = false;
    });
  }

  if (poser) {
    poser.addEventListener('click', function () {
      if (!valeur('licence_cle')) {
        peindre('is-ko', mots.needKey || '');
        return;
      }
      attendre(mots.posing);
      poser.disabled = true;

      fetch('/install/mises-a-jour/poser', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          licence_cle: valeur('licence_cle'),
          _csrf: valeur('_csrf'),
        }).toString(),
      }).then(function (r) {
        return r.json();
      }).then(function (pose) {
        if (barre) barre.hidden = true;
        poser.disabled = false;

        if (pose.ok) {
          // Les fichiers viennent de changer sous les pieds du serveur, qui
          // tourne encore sur les anciens : il faut le redémarrer, et l'écran
          // le dit plutôt que de laisser croire que c'est fini.
          peindre('is-ok', (mots.posed || '').replace('{version}', String(pose.version || '')));
          poser.hidden = true;
          return;
        }
        peindre('is-ko', (mots.failed || '')
          + ' ' + String(pose.detail || pose.raison || ''));
      }).catch(function () {
        if (barre) barre.hidden = true;
        poser.disabled = false;
        peindre('is-ko', mots.failed || '');
      });
    });
  }

  // L'adresse de l'éditeur ne se saisit plus : elle est dans le code. Il n'y a
  // donc rien à surveiller, et la question se pose une fois, à l'ouverture.
  verifier();
})();
