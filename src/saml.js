const crypto = require('crypto');
const zlib = require('zlib');

const db = require('./db');
const settings = require('./settings');
const xml = require('./xml');

/**
 * La connexion unique par SAML 2.0 (Entra ID, ADFS, Okta, Google, Keycloak…),
 * à l'initiative du produit : une demande en redirection, une réponse postée.
 * Les mêmes règles que l'édition PHP, sans bibliothèque :
 *
 * - l'assertion elle-même doit être signée — RSA-SHA256 ou RSA-SHA512,
 *   condensé SHA-256 ou SHA-512 — par le certificat déclaré du fournisseur,
 *   après canonicalisation exclusive (src/xml.js) ;
 * - un seul élément du document porte l'identifiant signé, et c'est
 *   l'assertion qu'on lit : l'« emballage de signature » ne passe pas ;
 * - émetteur, audience, destinataire, fenêtre de validité, et la demande à
 *   laquelle elle répond — gardée en base, consommée une seule fois ;
 * - pas de DOCTYPE, pas d'entité, pas d'assertion chiffrée.
 *
 * Elle n'ouvre que des comptes existants.
 */

const NS_P = 'urn:oasis:names:tc:SAML:2.0:protocol';
const NS_A = 'urn:oasis:names:tc:SAML:2.0:assertion';
const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';
const EXC = 'http://www.w3.org/2001/10/xml-exc-c14n#';
const ENVELOPPE = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';
const SIGNATURES = {
  'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256': 'sha256',
  'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512': 'sha512',
};
const CONDENSES = {
  'http://www.w3.org/2001/04/xmlenc#sha256': 'sha256',
  'http://www.w3.org/2001/04/xmlenc#sha512': 'sha512',
};
const DECALAGE_S = 120;
const DUREE_DEMANDE_S = 600;
const ATTRIBUTS_COURRIEL = [
  'email', 'mail', 'emailaddress', 'urn:oid:0.9.2342.19200300.100.1.3',
  'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
];

const { enfant, enfants, texte, attr, tous, canoniser } = xml;

function config() {
  const s = settings.all();
  return {
    enabled: s.sso_saml_enabled === '1',
    idpUrl: String(s.sso_saml_idp_url || '').trim(),
    idpEntity: String(s.sso_saml_idp_entity || '').trim(),
    idpCert: String(s.sso_saml_idp_cert || ''),
    label: String(s.sso_saml_label || '') || 'SAML',
    domains: String(s.sso_saml_domains || '').split(/[\s,;]+/).map((d) => d.trim().toLowerCase()).filter(Boolean),
  };
}

function bouton() {
  const c = config();
  return c.enabled && c.idpUrl && c.idpEntity && c.idpCert ? { label: c.label } : null;
}

const prestataire = (origine) => ({ entity: `${origine}/saml/metadata`, acs: `${origine}/connexion/saml/acs` });

function clePublique(pem) {
  try {
    return pem.includes('CERTIFICATE') ? new crypto.X509Certificate(pem).publicKey : crypto.createPublicKey(pem);
  } catch {
    return null;
  }
}

function enregistrer({ enabled, idpUrl, idpEntity, idpCert, label, domains }) {
  const url = String(idpUrl || '').trim();
  if (url || enabled) {
    let u;
    try { u = new URL(url); } catch { return { ok: false, reason: 'url' }; }
    if (u.protocol !== 'https:') return { ok: false, reason: 'url' };
  }
  if (enabled && !String(idpEntity || '').trim()) return { ok: false, reason: 'entity' };
  let cert = String(idpCert || '').trim();
  if (cert && !cert.includes('-----BEGIN')) {
    // Un certificat collé sans ses lignes d'encadrement, tel que les métadonnées le donnent.
    cert = `-----BEGIN CERTIFICATE-----\n${cert.replace(/\s+/g, '').match(/.{1,64}/g).join('\n')}\n-----END CERTIFICATE-----\n`;
  }
  if (cert && !clePublique(cert)) return { ok: false, reason: 'cert' };
  if (enabled && !cert && !settings.get('sso_saml_idp_cert')) return { ok: false, reason: 'cert' };
  const valeurs = {
    sso_saml_enabled: enabled ? '1' : '0',
    sso_saml_idp_url: url.slice(0, 500),
    sso_saml_idp_entity: String(idpEntity || '').trim().slice(0, 500),
    sso_saml_label: String(label || '').trim().slice(0, 60),
    sso_saml_domains: String(domains || '').trim().slice(0, 500),
  };
  if (cert) valeurs.sso_saml_idp_cert = cert;
  settings.setMany(valeurs);
  return { ok: true };
}

const xmlEsc = (v) => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const iso = (d) => new Date(d).toISOString().replace(/\.\d{3}Z$/, 'Z');

/** La demande d'authentification, en redirection ; son identifiant est gardé en base. */
function demarrer(origine) {
  const c = config();
  if (!bouton()) return { ok: false, reason: 'disabled' };
  const sp = prestataire(origine);
  const id = `_${crypto.randomBytes(20).toString('hex')}`;
  const maintenant = Math.floor(Date.now() / 1000);
  db.prepare('DELETE FROM saml_requests WHERE created_at < ?').run(maintenant - DUREE_DEMANDE_S);
  db.prepare('INSERT INTO saml_requests (request_id, created_at) VALUES (?, ?)').run(id, maintenant);
  const demande = `<samlp:AuthnRequest xmlns:samlp="${NS_P}" xmlns:saml="${NS_A}" ID="${id}" Version="2.0" IssueInstant="${iso(Date.now())}"`
    + ` Destination="${xmlEsc(c.idpUrl)}" AssertionConsumerServiceURL="${xmlEsc(sp.acs)}"`
    + ' ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST">'
    + `<saml:Issuer>${xmlEsc(sp.entity)}</saml:Issuer>`
    + '<samlp:NameIDPolicy Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress" AllowCreate="true"/>'
    + '</samlp:AuthnRequest>';
  const url = new URL(c.idpUrl);
  url.searchParams.set('SAMLRequest', zlib.deflateRawSync(Buffer.from(demande)).toString('base64'));
  return { ok: true, url: url.toString(), id };
}

function prefixes(transform) {
  if (!transform) return [];
  const inc = transform.children.find((c) => c.type === 'element' && c.local === 'InclusiveNamespaces');
  return inc ? attr(inc, 'PrefixList').trim().split(/\s+/).filter(Boolean) : [];
}

/** La signature d'une assertion : le condensé de l'assertion, puis celle du SignedInfo. */
function verifierSignature(racine, assertion, certPem) {
  const sig = enfant(assertion, NS_DS, 'Signature');
  if (!sig) return 'unsigned';
  const info = enfant(sig, NS_DS, 'SignedInfo');
  const valeur = enfant(sig, NS_DS, 'SignatureValue');
  if (!info || !valeur) return 'signature';
  const canon = enfant(info, NS_DS, 'CanonicalizationMethod');
  const methode = enfant(info, NS_DS, 'SignatureMethod');
  const refs = enfants(info, NS_DS, 'Reference');
  if (!canon || attr(canon, 'Algorithm') !== EXC || !methode || refs.length !== 1) return 'signature';
  const hashSig = SIGNATURES[attr(methode, 'Algorithm')];
  if (!hashSig) return 'algorithm';
  const ref = refs[0];
  const id = attr(assertion, 'ID');
  if (!id || attr(ref, 'URI') !== `#${id}`) return 'reference';
  // Un seul élément porte cet identifiant, et c'est l'assertion lue.
  let porteurs = 0;
  for (const el of tous(racine)) {
    for (const nom of ['ID', 'Id', 'id']) if (attr(el, nom) === id) porteurs += 1;
  }
  if (porteurs !== 1) return 'wrapping';
  const transforms = enfant(ref, NS_DS, 'Transforms');
  const liste = transforms ? enfants(transforms, NS_DS, 'Transform') : [];
  const algos = liste.map((t) => attr(t, 'Algorithm'));
  const accepte = (algos.length === 2 && algos[0] === ENVELOPPE && algos[1] === EXC) || (algos.length === 1 && algos[0] === ENVELOPPE);
  if (!accepte) return 'transforms';
  const condMethode = enfant(ref, NS_DS, 'DigestMethod');
  const condValeur = enfant(ref, NS_DS, 'DigestValue');
  const hash = condMethode ? CONDENSES[attr(condMethode, 'Algorithm')] : null;
  if (!hash || !condValeur) return 'algorithm';

  // La transformation « enveloppée » : l'assertion sans sa signature.
  const forme = canoniser(assertion, { exclure: [sig], inclusifs: liste.length === 2 ? prefixes(liste[1]) : [] });
  const calcule = crypto.createHash(hash).update(forme, 'utf8').digest();
  const annonce = Buffer.from(texte(condValeur).replace(/\s+/g, ''), 'base64');
  if (calcule.length !== annonce.length || !crypto.timingSafeEqual(calcule, annonce)) return 'digest';

  const cle = clePublique(certPem);
  if (!cle) return 'signature';
  const formeInfo = canoniser(info, { inclusifs: prefixes(canon) });
  let valide = false;
  try {
    valide = crypto.verify(hashSig, Buffer.from(formeInfo, 'utf8'), cle, Buffer.from(texte(valeur).replace(/\s+/g, ''), 'base64'));
  } catch {
    valide = false;
  }
  return valide ? true : 'signature';
}

const instant = (v) => { const t = Date.parse(v); return Number.isNaN(t) ? null : Math.floor(t / 1000); };

/** Lire et vérifier une réponse postée ; rend l'adresse vérifiée. */
function valider(reponseB64, origine, maintenant = Math.floor(Date.now() / 1000)) {
  const c = config();
  if (!bouton()) return { ok: false, reason: 'disabled' };
  const brut = Buffer.from(String(reponseB64 || '').replace(/\s+/g, ''), 'base64');
  if (brut.length === 0 || brut.length > 524288) return { ok: false, reason: 'format' };
  const source = brut.toString('utf8');
  if (/<!DOCTYPE/i.test(source) || /<!ENTITY/i.test(source)) return { ok: false, reason: 'doctype' };
  let rep;
  try {
    rep = xml.lire(source);
  } catch {
    return { ok: false, reason: 'format' };
  }
  if (rep.ns !== NS_P || rep.local !== 'Response') return { ok: false, reason: 'format' };
  if (enfants(rep, NS_A, 'EncryptedAssertion').length) return { ok: false, reason: 'encrypted' };
  const statut = enfant(rep, NS_P, 'Status');
  const code = statut ? enfant(statut, NS_P, 'StatusCode') : null;
  if (!code || attr(code, 'Value') !== 'urn:oasis:names:tc:SAML:2.0:status:Success') return { ok: false, reason: 'status' };
  const assertion = enfant(rep, NS_A, 'Assertion');
  if (!assertion) return { ok: false, reason: 'assertion' };
  const signee = verifierSignature(rep, assertion, c.idpCert);
  if (signee !== true) return { ok: false, reason: signee };

  // Tout ce qui suit se lit dans l'assertion vérifiée, et en elle seule.
  const sp = prestataire(origine);
  const emetteur = enfant(assertion, NS_A, 'Issuer');
  if (!emetteur || texte(emetteur).trim() !== c.idpEntity) return { ok: false, reason: 'issuer' };
  const conditions = enfant(assertion, NS_A, 'Conditions');
  if (!conditions) return { ok: false, reason: 'conditions' };
  const avant = instant(attr(conditions, 'NotBefore'));
  const apres = instant(attr(conditions, 'NotOnOrAfter'));
  if (apres === null || apres + DECALAGE_S <= maintenant || (avant !== null && avant - DECALAGE_S > maintenant)) return { ok: false, reason: 'expired' };
  const audiences = enfants(conditions, NS_A, 'AudienceRestriction').flatMap((r) => enfants(r, NS_A, 'Audience').map((a) => texte(a).trim()));
  if (!audiences.includes(sp.entity)) return { ok: false, reason: 'audience' };
  const sujet = enfant(assertion, NS_A, 'Subject');
  let confirmee = null;
  for (const sc of sujet ? enfants(sujet, NS_A, 'SubjectConfirmation') : []) {
    const d = enfant(sc, NS_A, 'SubjectConfirmationData');
    if (attr(sc, 'Method') === 'urn:oasis:names:tc:SAML:2.0:cm:bearer' && d) confirmee = d;
  }
  if (!confirmee || attr(confirmee, 'Recipient') !== sp.acs) return { ok: false, reason: 'recipient' };
  const fin = instant(attr(confirmee, 'NotOnOrAfter'));
  if (fin === null || fin + DECALAGE_S <= maintenant) return { ok: false, reason: 'expired' };
  // La demande à laquelle elle répond : émise ici, il y a peu, et une seule fois.
  const demande = attr(confirmee, 'InResponseTo');
  const consommee = demande && db.prepare('DELETE FROM saml_requests WHERE request_id = ? AND created_at >= ?')
    .run(demande, maintenant - DUREE_DEMANDE_S).changes === 1;
  if (!consommee) return { ok: false, reason: 'in-response-to' };

  let email = '';
  const nameId = enfant(sujet, NS_A, 'NameID');
  if (nameId && texte(nameId).includes('@')) email = texte(nameId).trim();
  if (!email) {
    for (const st of enfants(assertion, NS_A, 'AttributeStatement')) {
      for (const at of enfants(st, NS_A, 'Attribute')) {
        if (!ATTRIBUTS_COURRIEL.includes(attr(at, 'Name').toLowerCase())) continue;
        const v = enfants(at, NS_A, 'AttributeValue');
        if (v.length && texte(v[0]).includes('@')) { email = texte(v[0]).trim(); break; }
      }
      if (email) break;
    }
  }
  email = email.toLowerCase();
  if (!email) return { ok: false, reason: 'no-email' };
  if (c.domains.length && !c.domains.includes(email.split('@').pop())) return { ok: false, reason: 'domain' };
  return { ok: true, email };
}

function metadonnees(origine) {
  const sp = prestataire(origine);
  return '<?xml version="1.0" encoding="UTF-8"?>\n'
    + `<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="${xmlEsc(sp.entity)}">`
    + `<md:SPSSODescriptor AuthnRequestsSigned="false" WantAssertionsSigned="true" protocolSupportEnumeration="${NS_P}">`
    + '<md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat>'
    + `<md:AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="${xmlEsc(sp.acs)}" index="0" isDefault="true"/>`
    + '</md:SPSSODescriptor></md:EntityDescriptor>\n';
}

module.exports = { config, bouton, prestataire, enregistrer, demarrer, valider, metadonnees };
