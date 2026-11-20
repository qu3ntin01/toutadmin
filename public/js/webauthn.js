/*
 * Les clés de sécurité, côté navigateur.
 *
 * Ce fichier ne décide de rien : il demande à la clé de signer le défi que le
 * serveur a posé dans la page, range la réponse dans des champs cachés, et
 * envoie le formulaire comme un autre. Toute la vérification se fait au
 * serveur. Sans ce fichier, ou sans prise en charge par le navigateur, le
 * formulaire le dit au lieu de partir à vide.
 */
(function () {
  'use strict';

  function versB64u(tampon) {
    var octets = new Uint8Array(tampon);
    var texte = '';
    for (var i = 0; i < octets.length; i += 1) texte += String.fromCharCode(octets[i]);
    return btoa(texte).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function deB64u(chaine) {
    var b64 = chaine.replace(/-/g, '+').replace(/_/g, '/');
    while (b64.length % 4) b64 += '=';
    var texte = atob(b64);
    var octets = new Uint8Array(texte.length);
    for (var i = 0; i < texte.length; i += 1) octets[i] = texte.charCodeAt(i);
    return octets.buffer;
  }

  function poser(form, nom, valeur) {
    var champ = form.querySelector('input[name="' + nom + '"]');
    if (champ) champ.value = valeur;
  }

  function erreur(form) {
    var zone = form.querySelector('[data-webauthn-error]');
    if (zone) zone.hidden = false;
  }

  document.querySelectorAll('form[data-webauthn]').forEach(function (form) {
    form.addEventListener('submit', function (evenement) {
      if (form.dataset.webauthnPret === '1') return;
      evenement.preventDefault();
      if (!window.PublicKeyCredential || !navigator.credentials) {
        erreur(form);
        return;
      }
      var source = document.getElementById(form.dataset.webauthnOptions);
      var options = JSON.parse(source ? source.textContent : '{}');
      options.challenge = deB64u(options.challenge);

      var demande;
      if (form.dataset.webauthn === 'create') {
        options.user.id = deB64u(options.user.id);
        (options.excludeCredentials || []).forEach(function (c) { c.id = deB64u(c.id); });
        demande = navigator.credentials.create({ publicKey: options }).then(function (cle) {
          poser(form, 'client_data', versB64u(cle.response.clientDataJSON));
          poser(form, 'attestation', versB64u(cle.response.attestationObject));
        });
      } else {
        (options.allowCredentials || []).forEach(function (c) { c.id = deB64u(c.id); });
        demande = navigator.credentials.get({ publicKey: options }).then(function (cle) {
          poser(form, 'credential_id', versB64u(cle.rawId));
          poser(form, 'client_data', versB64u(cle.response.clientDataJSON));
          poser(form, 'authenticator_data', versB64u(cle.response.authenticatorData));
          poser(form, 'signature', versB64u(cle.response.signature));
        });
      }
      demande.then(function () {
        form.dataset.webauthnPret = '1';
        form.submit();
      }).catch(function () {
        erreur(form);
      });
    });
  });
})();
