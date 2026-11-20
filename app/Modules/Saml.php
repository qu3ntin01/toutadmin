<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;
use App\Core\Settings;

/**
 * La connexion unique par SAML 2.0 (Entra ID, ADFS, Okta, Google, Keycloak…),
 * à l'initiative du produit : une demande en redirection, une réponse postée.
 *
 * La vérification est faite ici, avec la canonicalisation exclusive de libxml
 * et OpenSSL, sans bibliothèque :
 * - l'assertion elle-même doit être signée (RSA-SHA256 ou RSA-SHA512, condensé
 *   SHA-256 ou SHA-512) par le certificat déclaré du fournisseur ;
 * - un seul élément du document porte l'identifiant signé, et c'est
 *   l'assertion qu'on lit : une assertion glissée à côté d'une assertion
 *   signée (l'« emballage de signature ») ne passe pas ;
 * - émetteur, audience, destinataire, fenêtre de validité, et la demande à
 *   laquelle elle répond — gardée en base, consommée une seule fois ;
 * - pas de DOCTYPE, pas d'entité, pas d'assertion chiffrée.
 *
 * Comme la connexion OpenID, elle n'ouvre que des comptes existants.
 */
final class Saml
{
    public const NS_P = 'urn:oasis:names:tc:SAML:2.0:protocol';
    public const NS_A = 'urn:oasis:names:tc:SAML:2.0:assertion';
    public const NS_DS = 'http://www.w3.org/2000/09/xmldsig#';
    private const EXC = 'http://www.w3.org/2001/10/xml-exc-c14n#';
    private const ENVELOPPE = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';
    private const SIGNATURES = [
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256' => OPENSSL_ALGO_SHA256,
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512' => OPENSSL_ALGO_SHA512,
    ];
    private const CONDENSES = [
        'http://www.w3.org/2001/04/xmlenc#sha256' => 'sha256',
        'http://www.w3.org/2001/04/xmlenc#sha512' => 'sha512',
    ];
    private const DECALAGE = 120;
    private const DUREE_DEMANDE = 600;
    private const ATTRIBUTS_COURRIEL = [
        'email', 'mail', 'emailaddress', 'urn:oid:0.9.2342.19200300.100.1.3',
        'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
    ];

    public static function config(): array
    {
        $s = Settings::all();
        return [
            'enabled' => ($s['sso_saml_enabled'] ?? '') === '1',
            'idpUrl' => trim((string) ($s['sso_saml_idp_url'] ?? '')),
            'idpEntity' => trim((string) ($s['sso_saml_idp_entity'] ?? '')),
            'idpCert' => (string) ($s['sso_saml_idp_cert'] ?? ''),
            'label' => ($s['sso_saml_label'] ?? '') !== '' ? (string) $s['sso_saml_label'] : 'SAML',
            'domains' => array_values(array_filter(array_map(
                static fn (string $d): string => strtolower(trim($d)),
                preg_split('/[\s,;]+/', (string) ($s['sso_saml_domains'] ?? '')) ?: []
            ))),
        ];
    }

    public static function bouton(): ?array
    {
        $c = self::config();
        return $c['enabled'] && $c['idpUrl'] !== '' && $c['idpEntity'] !== '' && $c['idpCert'] !== '' ? ['label' => $c['label']] : null;
    }

    /** Ce que le fournisseur doit connaître du produit. */
    public static function prestataire(string $origine): array
    {
        return ['entity' => $origine . '/saml/metadata', 'acs' => $origine . '/connexion/saml/acs'];
    }

    public static function enregistrer(bool $enabled, string $idpUrl, string $idpEntity, string $idpCert, string $label, string $domains): array
    {
        $url = trim($idpUrl);
        if ($url !== '' || $enabled) {
            $u = parse_url($url);
            if (!is_array($u) || ($u['scheme'] ?? '') !== 'https' || !isset($u['host'])) {
                return ['ok' => false, 'reason' => 'url'];
            }
        }
        if ($enabled && trim($idpEntity) === '') {
            return ['ok' => false, 'reason' => 'entity'];
        }
        $cert = trim($idpCert);
        if ($cert !== '' && !str_contains($cert, '-----BEGIN')) {
            // Un certificat collé sans ses lignes d'encadrement, tel que les
            // métadonnées le donnent.
            $cert = "-----BEGIN CERTIFICATE-----\n" . chunk_split((string) preg_replace('/\s+/', '', $cert), 64, "\n") . "-----END CERTIFICATE-----\n";
        }
        if ($cert !== '' && openssl_pkey_get_public($cert) === false) {
            return ['ok' => false, 'reason' => 'cert'];
        }
        if ($enabled && $cert === '' && Settings::get('sso_saml_idp_cert') === '') {
            return ['ok' => false, 'reason' => 'cert'];
        }
        $valeurs = [
            'sso_saml_enabled' => $enabled ? '1' : '0',
            'sso_saml_idp_url' => mb_substr($url, 0, 500),
            'sso_saml_idp_entity' => mb_substr(trim($idpEntity), 0, 500),
            'sso_saml_label' => mb_substr(trim($label), 0, 60),
            'sso_saml_domains' => mb_substr(trim($domains), 0, 500),
        ];
        if ($cert !== '') {
            $valeurs['sso_saml_idp_cert'] = $cert;
        }
        Settings::setMany($valeurs);
        return ['ok' => true];
    }

    /** La demande d'authentification, en redirection ; son identifiant est gardé en base. */
    public static function demarrer(string $origine): array
    {
        $c = self::config();
        if (self::bouton() === null) {
            return ['ok' => false, 'reason' => 'disabled'];
        }
        $sp = self::prestataire($origine);
        $id = '_' . bin2hex(random_bytes(20));
        Db::run('DELETE FROM saml_requests WHERE created_at < ?', [time() - self::DUREE_DEMANDE]);
        Db::run('INSERT INTO saml_requests (request_id, created_at) VALUES (?, ?)', [$id, time()]);
        $x = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $xml = '<samlp:AuthnRequest xmlns:samlp="' . self::NS_P . '" xmlns:saml="' . self::NS_A . '"'
            . ' ID="' . $id . '" Version="2.0" IssueInstant="' . gmdate('Y-m-d\TH:i:s\Z') . '"'
            . ' Destination="' . $x($c['idpUrl']) . '" AssertionConsumerServiceURL="' . $x($sp['acs']) . '"'
            . ' ProtocolBinding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST">'
            . '<saml:Issuer>' . $x($sp['entity']) . '</saml:Issuer>'
            . '<samlp:NameIDPolicy Format="urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress" AllowCreate="true"/>'
            . '</samlp:AuthnRequest>';
        $url = $c['idpUrl'] . (str_contains($c['idpUrl'], '?') ? '&' : '?')
            . http_build_query(['SAMLRequest' => base64_encode((string) gzdeflate($xml))], '', '&', PHP_QUERY_RFC3986);
        return ['ok' => true, 'url' => $url, 'id' => $id];
    }

    private static function enfants(\DOMElement $parent, string $ns, string $nom): array
    {
        $t = [];
        foreach ($parent->childNodes as $n) {
            if ($n instanceof \DOMElement && $n->namespaceURI === $ns && $n->localName === $nom) {
                $t[] = $n;
            }
        }
        return $t;
    }

    private static function enfant(\DOMElement $parent, string $ns, string $nom): ?\DOMElement
    {
        $t = self::enfants($parent, $ns, $nom);
        return count($t) === 1 ? $t[0] : null;
    }

    private static function instant(string $v): ?int
    {
        $t = strtotime($v);
        return $t === false ? null : $t;
    }

    private static function prefixes(?\DOMElement $transform): ?array
    {
        if ($transform === null) {
            return null;
        }
        foreach ($transform->childNodes as $n) {
            if ($n instanceof \DOMElement && $n->localName === 'InclusiveNamespaces' && $n->hasAttribute('PrefixList')) {
                return array_values(array_filter(preg_split('/\s+/', trim($n->getAttribute('PrefixList'))) ?: []));
            }
        }
        return null;
    }

    /** Vérifier la signature d'une assertion : condensé de l'assertion, puis signature du SignedInfo. */
    private static function signatureValide(\DOMDocument $doc, \DOMElement $assertion, string $cert): string|true
    {
        $sig = self::enfant($assertion, self::NS_DS, 'Signature');
        if ($sig === null) {
            return 'unsigned';
        }
        $info = self::enfant($sig, self::NS_DS, 'SignedInfo');
        $valeur = self::enfant($sig, self::NS_DS, 'SignatureValue');
        if ($info === null || $valeur === null) {
            return 'signature';
        }
        $canon = self::enfant($info, self::NS_DS, 'CanonicalizationMethod');
        $methode = self::enfant($info, self::NS_DS, 'SignatureMethod');
        $refs = self::enfants($info, self::NS_DS, 'Reference');
        if ($canon === null || $canon->getAttribute('Algorithm') !== self::EXC || $methode === null || count($refs) !== 1) {
            return 'signature';
        }
        $algo = self::SIGNATURES[$methode->getAttribute('Algorithm')] ?? null;
        if ($algo === null) {
            return 'algorithm';
        }
        $ref = $refs[0];
        $id = $assertion->getAttribute('ID');
        if ($id === '' || $ref->getAttribute('URI') !== '#' . $id) {
            return 'reference';
        }
        // Un seul élément porte cet identifiant, et c'est l'assertion lue.
        $porteurs = 0;
        foreach ($doc->getElementsByTagName('*') as $el) {
            foreach (['ID', 'Id', 'id'] as $attr) {
                if ($el->hasAttribute($attr) && $el->getAttribute($attr) === $id) {
                    $porteurs++;
                }
            }
        }
        if ($porteurs !== 1) {
            return 'wrapping';
        }
        $transforms = self::enfant($ref, self::NS_DS, 'Transforms');
        $liste = $transforms === null ? [] : self::enfants($transforms, self::NS_DS, 'Transform');
        $algos = array_map(static fn (\DOMElement $t): string => $t->getAttribute('Algorithm'), $liste);
        if ($algos !== [self::ENVELOPPE, self::EXC] && $algos !== [self::ENVELOPPE]) {
            return 'transforms';
        }
        $prefixesRef = count($liste) === 2 ? self::prefixes($liste[1]) : null;
        $condMethode = self::enfant($ref, self::NS_DS, 'DigestMethod');
        $condValeur = self::enfant($ref, self::NS_DS, 'DigestValue');
        $hash = $condMethode === null ? null : (self::CONDENSES[$condMethode->getAttribute('Algorithm')] ?? null);
        if ($hash === null || $condValeur === null) {
            return 'algorithm';
        }

        // La transformation « enveloppée » : l'assertion sans sa signature,
        // canonicalisée dans un document à part pour ne pas toucher l'original.
        $copie = new \DOMDocument();
        $copie->appendChild($copie->importNode($assertion, true));
        $racine = $copie->documentElement;
        foreach (self::enfants($racine, self::NS_DS, 'Signature') as $s) {
            $racine->removeChild($s);
        }
        $forme = $racine->C14N(true, false, null, $prefixesRef);
        if (!is_string($forme) || !hash_equals(base64_encode(hash($hash, $forme, true)), preg_replace('/\s+/', '', $condValeur->textContent))) {
            return 'digest';
        }
        $formeInfo = $info->C14N(true, false, null, self::prefixes($canon));
        $brute = base64_decode((string) preg_replace('/\s+/', '', $valeur->textContent), true);
        if (!is_string($formeInfo) || $brute === false || openssl_verify($formeInfo, $brute, $cert, $algo) !== 1) {
            return 'signature';
        }
        return true;
    }

    /**
     * Lire et vérifier une réponse postée par le fournisseur. Rend l'adresse
     * vérifiée ; c'est à l'appelant de trouver le compte.
     */
    public static function valider(string $reponseB64, string $origine, ?int $maintenant = null): array
    {
        $maintenant ??= time();
        $c = self::config();
        if (self::bouton() === null) {
            return ['ok' => false, 'reason' => 'disabled'];
        }
        $xml = base64_decode(preg_replace('/\s+/', '', $reponseB64) ?? '', true);
        if (!is_string($xml) || $xml === '' || strlen($xml) > 524288) {
            return ['ok' => false, 'reason' => 'format'];
        }
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            return ['ok' => false, 'reason' => 'doctype'];
        }
        $doc = new \DOMDocument();
        $precedent = libxml_use_internal_errors(true);
        $lu = $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($precedent);
        if (!$lu || $doc->documentElement === null) {
            return ['ok' => false, 'reason' => 'format'];
        }
        $rep = $doc->documentElement;
        if ($rep->namespaceURI !== self::NS_P || $rep->localName !== 'Response') {
            return ['ok' => false, 'reason' => 'format'];
        }
        if (self::enfants($rep, self::NS_A, 'EncryptedAssertion') !== []) {
            return ['ok' => false, 'reason' => 'encrypted'];
        }
        $statut = self::enfant($rep, self::NS_P, 'Status');
        $code = $statut === null ? null : self::enfant($statut, self::NS_P, 'StatusCode');
        if ($code === null || $code->getAttribute('Value') !== 'urn:oasis:names:tc:SAML:2.0:status:Success') {
            return ['ok' => false, 'reason' => 'status'];
        }
        $assertion = self::enfant($rep, self::NS_A, 'Assertion');
        if ($assertion === null) {
            return ['ok' => false, 'reason' => 'assertion'];
        }
        $signee = self::signatureValide($doc, $assertion, $c['idpCert']);
        if ($signee !== true) {
            return ['ok' => false, 'reason' => $signee];
        }

        // Tout ce qui suit se lit dans l'assertion vérifiée, et en elle seule.
        $sp = self::prestataire($origine);
        $emetteur = self::enfant($assertion, self::NS_A, 'Issuer');
        if ($emetteur === null || trim($emetteur->textContent) !== $c['idpEntity']) {
            return ['ok' => false, 'reason' => 'issuer'];
        }
        $conditions = self::enfant($assertion, self::NS_A, 'Conditions');
        if ($conditions === null) {
            return ['ok' => false, 'reason' => 'conditions'];
        }
        $avant = self::instant($conditions->getAttribute('NotBefore'));
        $apres = self::instant($conditions->getAttribute('NotOnOrAfter'));
        if ($apres === null || $apres + self::DECALAGE <= $maintenant || ($avant !== null && $avant - self::DECALAGE > $maintenant)) {
            return ['ok' => false, 'reason' => 'expired'];
        }
        $audiences = [];
        foreach (self::enfants($conditions, self::NS_A, 'AudienceRestriction') as $r) {
            foreach (self::enfants($r, self::NS_A, 'Audience') as $a) {
                $audiences[] = trim($a->textContent);
            }
        }
        if (!in_array($sp['entity'], $audiences, true)) {
            return ['ok' => false, 'reason' => 'audience'];
        }
        $sujet = self::enfant($assertion, self::NS_A, 'Subject');
        $confirmee = null;
        foreach ($sujet === null ? [] : self::enfants($sujet, self::NS_A, 'SubjectConfirmation') as $sc) {
            $d = self::enfant($sc, self::NS_A, 'SubjectConfirmationData');
            if ($sc->getAttribute('Method') === 'urn:oasis:names:tc:SAML:2.0:cm:bearer' && $d !== null) {
                $confirmee = $d;
            }
        }
        if ($confirmee === null || $confirmee->getAttribute('Recipient') !== $sp['acs']) {
            return ['ok' => false, 'reason' => 'recipient'];
        }
        $fin = self::instant($confirmee->getAttribute('NotOnOrAfter'));
        if ($fin === null || $fin + self::DECALAGE <= $maintenant) {
            return ['ok' => false, 'reason' => 'expired'];
        }
        // La demande à laquelle elle répond : émise ici, il y a peu, et une seule fois.
        $demande = $confirmee->getAttribute('InResponseTo');
        $consommee = $demande !== '' && Db::run(
            'DELETE FROM saml_requests WHERE request_id = ? AND created_at >= ?',
            [$demande, $maintenant - self::DUREE_DEMANDE]
        ) === 1;
        if (!$consommee) {
            return ['ok' => false, 'reason' => 'in-response-to'];
        }

        $email = '';
        $nameId = self::enfant($sujet, self::NS_A, 'NameID');
        if ($nameId !== null && str_contains($nameId->textContent, '@')) {
            $email = trim($nameId->textContent);
        }
        if ($email === '') {
            foreach (self::enfants($assertion, self::NS_A, 'AttributeStatement') as $st) {
                foreach (self::enfants($st, self::NS_A, 'Attribute') as $at) {
                    if (in_array(strtolower($at->getAttribute('Name')), self::ATTRIBUTS_COURRIEL, true)) {
                        $v = self::enfants($at, self::NS_A, 'AttributeValue');
                        if ($v !== [] && str_contains($v[0]->textContent, '@')) {
                            $email = trim($v[0]->textContent);
                            break 2;
                        }
                    }
                }
            }
        }
        $email = strtolower($email);
        if ($email === '') {
            return ['ok' => false, 'reason' => 'no-email'];
        }
        if ($c['domains'] !== [] && !in_array(substr((string) strrchr($email, '@'), 1), $c['domains'], true)) {
            return ['ok' => false, 'reason' => 'domain'];
        }
        return ['ok' => true, 'email' => $email];
    }

    /** Les métadonnées du prestataire, pour déclarer le produit chez le fournisseur. */
    public static function metadonnees(string $origine): string
    {
        $sp = self::prestataire($origine);
        $x = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<md:EntityDescriptor xmlns:md="urn:oasis:names:tc:SAML:2.0:metadata" entityID="' . $x($sp['entity']) . '">'
            . '<md:SPSSODescriptor AuthnRequestsSigned="false" WantAssertionsSigned="true" protocolSupportEnumeration="' . self::NS_P . '">'
            . '<md:NameIDFormat>urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress</md:NameIDFormat>'
            . '<md:AssertionConsumerService Binding="urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST" Location="' . $x($sp['acs']) . '" index="0" isDefault="true"/>'
            . '</md:SPSSODescriptor></md:EntityDescriptor>' . "\n";
    }
}
