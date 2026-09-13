<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\ApiTokens;
use App\Modules\Users;
use App\Modules\Webhooks;

/** Une instance installée, avec un jeton d'API de la portée demandée. */
function seedApi(array $scopes = ['annuaire']): array
{
    $ids = seed();
    $token = ApiTokens::create(['label' => 'Outil de paie', 'scopes' => $scopes, 'createdBy' => $ids['admin']]);
    $ids['token'] = $token['token'];
    $ids['tokenId'] = $token['id'];
    return $ids;
}

/** Appelle l'API comme le ferait un programme tiers. */
function callApi(string $path, ?string $token = null, array $query = [], string $header = 'Authorization')
{
    $headers = [];
    if ($token !== null) {
        $headers[$header] = $header === 'Authorization' ? 'Bearer ' . $token : $token;
    }
    return visit('GET', $path, [], $query, [], $headers);
}

function apiJson(\App\Core\Response $response): array
{
    return json_decode($response->body, true) ?? [];
}

// ---------- Jetons ----------

Tests::run("le jeton en clair n'existe qu'au moment de sa création", function (): void {
    $ids = seed();
    $verdict = ApiTokens::create(['label' => 'Tableur comptable', 'scopes' => ['gestion'], 'createdBy' => $ids['admin']]);

    assertTrue($verdict['ok']);
    assertTrue(str_starts_with($verdict['token'], ApiTokens::PREFIX), 'le jeton porte son préfixe');

    // En base, l'empreinte et le préfixe ; jamais la valeur.
    $row = Db::get('SELECT * FROM api_tokens WHERE id = ?', [$verdict['id']]);
    assertSame(ApiTokens::hash($verdict['token']), $row['token_hash']);
    assertSame(substr($verdict['token'], 0, 11), $row['prefix']);
    assertTrue(!str_contains((string) json_encode($row), substr($verdict['token'], 11)), 'la valeur ne figure nulle part');

    // La liste d'administration ne la rend pas davantage.
    $listed = ApiTokens::all()[0];
    assertTrue(!isset($listed['token']), 'la liste ne rend pas de jeton en clair');
    assertSame(['gestion'], $listed['scopeList']);
});

Tests::run('un jeton sans intitulé, sans portée ou hors durée est refusé', function (): void {
    seed();
    assertSame('Un intitulé est requis.', ApiTokens::create(['label' => ' ', 'scopes' => ['rh']])['message']);
    assertSame('Choisissez au moins une portée.', ApiTokens::create(['label' => 'A', 'scopes' => []])['message']);
    // Une portée inventée n'en est pas une : elle est écartée, pas acceptée.
    assertSame('Choisissez au moins une portée.', ApiTokens::create(['label' => 'A', 'scopes' => ['tout']])['message']);
    assertSame(
        'La durée de vie tient entre 1 et 3650 jours.',
        ApiTokens::create(['label' => 'A', 'scopes' => ['rh'], 'days' => 0])['message']
    );
    assertSame(
        'La durée de vie tient entre 1 et 3650 jours.',
        ApiTokens::create(['label' => 'A', 'scopes' => ['rh'], 'days' => 4000])['message']
    );
});

Tests::run('un jeton révoqué, périmé ou inconnu se résout pareil : pas du tout', function (): void {
    $ids = seedApi(['annuaire']);

    assertTrue(ApiTokens::resolve($ids['token']) !== null, 'le jeton neuf répond');
    assertSame(null, ApiTokens::resolve('sm_inconnu'), 'un jeton inexistant ne résout pas');
    assertSame(null, ApiTokens::resolve(substr($ids['token'], 3)), 'sans le préfixe, on ne cherche même pas');
    assertSame(null, ApiTokens::resolve(null));

    // Périmé : la date passe, le jeton cesse de répondre sans qu'on y touche.
    Db::run('UPDATE api_tokens SET expires_at = ? WHERE id = ?', [gmdate('Y-m-d', time() - 86400), $ids['tokenId']]);
    assertSame(null, ApiTokens::resolve($ids['token']));

    Db::run('UPDATE api_tokens SET expires_at = ? WHERE id = ?', [gmdate('Y-m-d', time() + 86400), $ids['tokenId']]);
    assertTrue(ApiTokens::resolve($ids['token']) !== null);

    assertSame(true, ApiTokens::revoke($ids['tokenId']));
    assertSame(null, ApiTokens::resolve($ids['token']), 'révoqué, il ne répond plus');
    assertSame(false, ApiTokens::revoke($ids['tokenId']), 'révoquer deux fois ne change rien');
});

Tests::run('la portée est vérifiée jeton par jeton', function (): void {
    $ids = seedApi(['annuaire', 'rh']);
    $token = ApiTokens::resolve($ids['token']);

    assertSame(true, ApiTokens::allows($token, 'annuaire'));
    assertSame(true, ApiTokens::allows($token, 'rh'));
    assertSame(false, ApiTokens::allows($token, 'gestion'));
    assertSame(false, ApiTokens::allows(null, 'annuaire'));
});

// ---------- API v1 ----------

Tests::run("sans jeton, l'API ne dit rien de plus qu'un 401", function (): void {
    seedApi();

    $response = callApi('/api/v1');
    assertSame(401, $response->status);
    assertSame('Bearer realm="Toutadmin"', $response->headers['WWW-Authenticate'] ?? '');
    assertSame('no-store', $response->headers['Cache-Control'] ?? '');
    // Le motif reste muet : un jeton inconnu, révoqué ou périmé rend le même texte.
    assertSame('Jeton absent, invalide ou expiré.', apiJson($response)['error']);
    assertSame(401, callApi('/api/v1', 'sm_faux')->status);
    assertSame(401, callApi('/api/v1', 'sans-prefixe')->status);
});

Tests::run("l'API ne pose ni ne lit de cookie de session", function (): void {
    $ids = seedApi();
    // Connecté ou non, seul le jeton compte : la session ne donne aucun accès…
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    assertSame(401, callApi('/api/v1')->status, 'une session ouverte ne vaut pas jeton');

    // …et l'appel authentifié par jeton ne rend pas de cookie.
    $response = callApi('/api/v1', $ids['token']);
    assertSame(200, $response->status);
    assertTrue(!isset($response->headers['Set-Cookie']), "l'API ne dépose pas de cookie");
});

Tests::run('la racine décrit le jeton présenté et rien de plus', function (): void {
    $ids = seedApi(['annuaire', 'pilotage']);
    $body = apiJson(callApi('/api/v1', $ids['token']));

    assertSame(1, $body['version']);
    assertSame(true, $body['readOnly']);
    assertSame('Outil de paie', $body['token']['label']);
    assertSame(['annuaire', 'pilotage'], $body['token']['scopes']);
    assertSame(9, count($body['endpoints']));
    assertTrue(!isset($body['token']['token_hash']), "l'empreinte ne ressort pas");
});

Tests::run('un jeton hors portée est refusé sans servir la donnée', function (): void {
    $ids = seedApi(['annuaire']);

    $response = callApi('/api/v1/factures', $ids['token']);
    assertSame(403, $response->status);
    $body = apiJson($response);
    assertContains('gestion', $body['error']);
    // Le refus dit ce que le jeton a, pour que l'intégrateur corrige.
    assertSame(['annuaire'], $body['scopes']);
    assertTrue(!isset($body['data']), 'rien de la ressource ne fuit avec le refus');
});

Tests::run("l'API respecte le retrait de l'annuaire", function (): void {
    $ids = seedApi(['annuaire']);
    $body = apiJson(callApi('/api/v1/collaborateurs', $ids['token']));
    assertSame(2, $body['count']);

    // Retirée de l'annuaire par l'administration, Claire n'en sort pas par l'API.
    Db::run('UPDATE users SET directory_hidden = 1 WHERE id = ?', [$ids['member']]);
    $body = apiJson(callApi('/api/v1/collaborateurs', $ids['token']));
    assertSame(1, $body['count']);
    assertTrue(!str_contains((string) json_encode($body), 'Moreau'), 'la personne masquée ne figure plus');
});

Tests::run('le motif d’une absence ne sort pas par l’API', function (): void {
    $ids = seedApi(['rh']);
    Db::run(
        "INSERT INTO hr_requests (employee_id, type, start_date, end_date, days, status, reason)
         VALUES (?, 'Congés payés', '2099-07-01', '2099-07-05', 5, 'Approuvée', 'Mariage de ma sœur')",
        [$ids['member']]
    );

    $body = apiJson(callApi('/api/v1/absences', $ids['token']));
    assertSame(1, $body['count']);
    assertSame('Claire Moreau', $body['data'][0]['personne']);
    assertTrue(!str_contains((string) json_encode($body), 'Mariage'), 'le motif reste à la maison');
});

Tests::run('la pagination est bornée et les deux en-têtes acceptés', function (): void {
    $ids = seedApi(['annuaire']);

    // La clé peut venir de « x-api-key » : certains outils ne savent pas faire autrement.
    assertSame(200, callApi('/api/v1', $ids['token'], [], 'X-Api-Key')->status);

    $body = apiJson(callApi('/api/v1/collaborateurs', $ids['token'], ['limite' => '1']));
    assertSame(1, $body['count']);
    assertSame(1, $body['limit']);

    // Une limite absurde est ramenée au plafond, une page négative à zéro.
    $body = apiJson(callApi('/api/v1/collaborateurs', $ids['token'], ['limite' => '9000', 'depuis' => '-5']));
    assertSame(200, $body['limit']);
    assertSame(0, $body['offset']);
});

Tests::run("l'API est en lecture seule et rend du JSON jusque dans ses erreurs", function (): void {
    $ids = seedApi(['annuaire']);

    // Une ressource inconnue : un programme reçoit du JSON, pas une page d'erreur.
    $unknown = callApi('/api/v1/salaires', $ids['token']);
    assertSame(404, $unknown->status);
    assertSame('Ressource inconnue.', apiJson($unknown)['error']);
    assertContains('application/json', $unknown->headers['Content-Type'] ?? '');

    // Écrire n'est pas prévu : aucune route POST n'existe sous /api/v1.
    $write = visit('POST', '/api/v1/collaborateurs', [], [], [], ['Authorization' => 'Bearer ' . $ids['token']]);
    assertSame(404, $write->status);
    assertSame('Ressource inconnue.', apiJson($write)['error']);
    assertContains('application/json', $write->headers['Content-Type'] ?? '');
});

Tests::run("chaque appel est daté et compté sur le jeton qui l'a fait", function (): void {
    $ids = seedApi(['annuaire']);
    callApi('/api/v1', $ids['token']);
    callApi('/api/v1/collaborateurs', $ids['token']);

    $row = Db::get('SELECT * FROM api_tokens WHERE id = ?', [$ids['tokenId']]);
    assertSame(2, (int) $row['calls']);
    assertTrue($row['last_used_at'] !== null, "l'usage est daté");
    assertSame('127.0.0.1', $row['last_ip']);
});

// ---------- Webhooks ----------

Tests::run('une adresse interne ou en clair est refusée par défaut', function (): void {
    seed();
    foreach (['localhost', '127.0.0.1', '10.0.0.4', '192.168.1.10', '172.16.0.9', '169.254.1.1',
              '::1', 'compta.internal', 'nas.local'] as $host) {
        assertTrue(Webhooks::isPrivateHost($host), "$host désigne le réseau interne");
    }
    foreach (['exemple.fr', '8.8.8.8', '172.32.0.1', '11.0.0.1'] as $host) {
        assertSame(false, Webhooks::isPrivateHost($host), "$host est public");
    }

    assertSame(false, Webhooks::checkUrl('http://exemple.fr/hook')['ok'], 'http sans chiffrement est refusé');
    assertSame(false, Webhooks::checkUrl('https://127.0.0.1/hook')['ok'], "la boucle locale n'est pas une cible");
    assertSame(false, Webhooks::checkUrl('ftp://exemple.fr')['ok']);
    assertSame(false, Webhooks::checkUrl('pas une url')['ok']);
    assertSame(true, Webhooks::checkUrl('https://exemple.fr/hook')['ok']);

    // Viser l'interne reste possible, mais en le disant.
    assertSame(true, Webhooks::checkUrl('https://192.168.1.10/hook', true)['ok']);
    assertSame(true, Webhooks::checkUrl('http://127.0.0.1:9000/hook', true)['ok']);
});

Tests::run('le secret de signature est rendu une fois, puis chiffré en base', function (): void {
    $ids = seed();
    $verdict = Webhooks::create([
        'label' => 'Compta externe', 'url' => 'https://exemple.fr/hook',
        'events' => ['facture.creee', 'facture.creee', 'inventé'], 'createdBy' => $ids['admin'],
    ]);

    assertTrue($verdict['ok']);
    assertTrue(strlen($verdict['secret']) >= 32);

    $row = Db::get('SELECT * FROM webhooks WHERE id = ?', [$verdict['id']]);
    assertTrue(!str_contains($row['secret'], $verdict['secret']), 'le secret ne dort pas en clair');
    // Le doublon est écarté, l'événement inventé aussi.
    assertSame('facture.creee', $row['events']);

    $listed = Webhooks::all()[0];
    assertSame(true, $listed['secretSet'], "l'existence du secret se voit");
    assertSame(['facture.creee'], $listed['eventList']);

    assertSame('Un intitulé est requis.', Webhooks::create(['label' => '', 'events' => ['facture.creee']])['message']);
    assertSame(
        'Choisissez au moins un événement.',
        Webhooks::create(['label' => 'X', 'url' => 'https://exemple.fr', 'events' => []])['message']
    );
});

Tests::run("l'émission ne met en file que ce qui est écouté et actif", function (): void {
    $ids = seed();
    $facture = Webhooks::create([
        'label' => 'Factures', 'url' => 'https://exemple.fr/f', 'events' => ['facture.creee'],
    ]);
    $tickets = Webhooks::create([
        'label' => 'Tickets', 'url' => 'https://exemple.fr/t', 'events' => ['ticket.ouvert'],
    ]);

    assertSame(1, Webhooks::emit('facture.creee', ['id' => 7]));
    assertSame(0, Webhooks::emit('evenement.inventé'), "un événement inconnu n'entre pas en file");

    // Suspendu, un webhook cesse de recevoir : la file ne le rattrapera pas.
    Webhooks::setActive((int) $tickets['id'], false);
    assertSame(0, Webhooks::emit('ticket.ouvert', ['id' => 1]));

    $queued = Webhooks::deliveries();
    assertSame(1, count($queued));
    assertSame('facture.creee', $queued[0]['event']);
    assertSame((int) $facture['id'], (int) $queued[0]['webhook_id']);

    // La charge utile porte l'événement, sa date et la donnée, rien d'autre.
    $payload = json_decode((string) $queued[0]['payload'], true);
    assertSame(['event', 'at', 'data'], array_keys($payload));
    assertSame(7, $payload['data']['id']);
});

Tests::run('chaque envoi est signé du secret du webhook', function (): void {
    seed();
    $created = Webhooks::create([
        'label' => 'Compta', 'url' => 'https://exemple.fr/hook', 'events' => ['facture.creee'],
    ]);
    Webhooks::emit('facture.creee', ['reference' => 'F-1']);

    $seen = [];
    Webhooks::$transport = function (string $url, array $headers, string $body) use (&$seen): array {
        $seen = ['url' => $url, 'headers' => $headers, 'body' => $body];
        return ['ok' => true, 'status' => 200];
    };
    $result = Webhooks::flush();
    Webhooks::$transport = null;

    assertSame(1, $result['delivered']);
    assertSame('https://exemple.fr/hook', $seen['url']);
    assertTrue(in_array('x-toutadmin-event: facture.creee', $seen['headers'], true));

    // La signature se vérifie avec le secret rendu à la création, sur le corps exact.
    $expected = 'x-toutadmin-signature: ' . Webhooks::signature($created['secret'], $seen['body']);
    assertTrue(in_array($expected, $seen['headers'], true), 'signature HMAC-SHA256 du corps');
    assertSame(
        'sha256=' . hash_hmac('sha256', 'corps', 'secret'),
        Webhooks::signature('secret', 'corps')
    );

    $delivery = Webhooks::deliveries()[0];
    assertSame('Livré', $delivery['status']);
    assertSame(1, (int) $delivery['attempts']);
    assertSame('OK 200', Webhooks::byId((int) $created['id'])['last_status']);
});

Tests::run("un échec est réessayé, espacé, puis abandonné — jamais silencieux", function (): void {
    seed();
    $created = Webhooks::create([
        'label' => 'Compta', 'url' => 'https://exemple.fr/hook', 'events' => ['facture.creee'],
    ]);
    Webhooks::emit('facture.creee', []);
    Webhooks::$transport = static fn (): array => ['ok' => false, 'status' => 502, 'error' => 'réponse 502'];

    for ($attempt = 1; $attempt < Webhooks::MAX_ATTEMPTS; $attempt++) {
        assertSame(1, Webhooks::flush()['failed'], "tentative $attempt");
        $delivery = Webhooks::deliveries()[0];
        assertSame('En attente', $delivery['status'], "la livraison reste en file après $attempt échec(s)");
        assertSame($attempt, (int) $delivery['attempts']);
        assertSame('réponse 502', $delivery['last_error']);

        // Le prochain essai est repoussé : la file n'est pas rejouée dans la seconde.
        assertSame(0, count(Webhooks::pending()), 'le réessai attend son tour');
        Db::run("UPDATE webhook_deliveries SET next_try_at = datetime('now', '-1 minute')");
    }

    assertSame(1, Webhooks::flush()['failed']);
    $delivery = Webhooks::deliveries()[0];
    assertSame('Abandonné', $delivery['status']);
    assertSame(Webhooks::MAX_ATTEMPTS, (int) $delivery['attempts']);
    Webhooks::$transport = null;

    // Abandonnée, la livraison ne revient plus en file, même la date passée.
    Db::run("UPDATE webhook_deliveries SET next_try_at = datetime('now', '-1 day')");
    assertSame(0, count(Webhooks::pending()));
    assertSame(1, Webhooks::summary()['abandoned']);
    assertSame('réponse 502', Webhooks::byId((int) $created['id'])['last_status']);
});

Tests::run("après une série d'échecs, le webhook s'éteint de lui-même", function (): void {
    seed();
    $created = Webhooks::create([
        'label' => 'Compta', 'url' => 'https://exemple.fr/hook', 'events' => ['facture.creee'],
    ]);
    Webhooks::$transport = static fn (): array => ['ok' => false, 'status' => 0, 'error' => 'service injoignable'];

    for ($i = 0; $i < Webhooks::FAILURE_LIMIT - 1; $i++) {
        Webhooks::emit('facture.creee', []);
        Webhooks::flush();
    }
    assertSame(1, (int) Webhooks::byId((int) $created['id'])['active'], 'il tient jusqu’au seuil');
    assertSame(Webhooks::FAILURE_LIMIT - 1, (int) Webhooks::byId((int) $created['id'])['failures']);

    Webhooks::emit('facture.creee', []);
    Webhooks::flush();
    Webhooks::$transport = null;

    $hook = Webhooks::byId((int) $created['id']);
    assertSame(0, (int) $hook['active'], "au seuil, il s'éteint plutôt que de faire croire que ça passe");
    // Éteint, il ne reçoit plus rien.
    assertSame(0, Webhooks::emit('facture.creee', []));

    // La réactivation remet le compteur d'échecs à zéro.
    Webhooks::setActive((int) $created['id'], true);
    $hook = Webhooks::byId((int) $created['id']);
    assertSame(1, (int) $hook['active']);
    assertSame(0, (int) $hook['failures']);
});

Tests::run('un secret illisible ne fait pas partir la charge utile', function (): void {
    seed();
    $created = Webhooks::create([
        'label' => 'Compta', 'url' => 'https://exemple.fr/hook', 'events' => ['facture.creee'],
    ]);
    Webhooks::emit('facture.creee', []);

    // Secret d'instance changé : le chiffré porte toujours sa marque, mais ne s'ouvre plus.
    Db::run(
        'UPDATE webhooks SET secret = ? WHERE id = ?',
        [\App\Core\Secrets::PREFIX . base64_encode(random_bytes(48)), (int) $created['id']]
    );
    $touched = false;
    Webhooks::$transport = function () use (&$touched): array {
        $touched = true;
        return ['ok' => true, 'status' => 200];
    };
    $result = Webhooks::flush();
    Webhooks::$transport = null;

    assertSame(1, $result['failed']);
    assertSame(false, $touched, "rien n'est envoyé sans signature possible");
    assertContains('secret illisible', Webhooks::deliveries()[0]['last_error']);
});

Tests::run('la file est un journal, pas une archive', function (): void {
    seed();
    Webhooks::create(['label' => 'Compta', 'url' => 'https://exemple.fr/hook', 'events' => ['facture.creee']]);
    Webhooks::emit('facture.creee', []);
    Webhooks::emit('facture.creee', []);

    // Une livraison encore en attente n'est jamais purgée, si vieille soit-elle.
    Db::run("UPDATE webhook_deliveries SET created_at = datetime('now', '-90 days')");
    assertSame(0, Webhooks::purge(30));

    Db::run("UPDATE webhook_deliveries SET status = 'Livré' WHERE id = (SELECT MIN(id) FROM webhook_deliveries)");
    assertSame(1, Webhooks::purge(30));
    assertSame(1, count(Webhooks::deliveries()));

    // Supprimer un webhook emporte son journal avec lui.
    Webhooks::remove((int) Webhooks::all()[0]['id']);
    assertSame(0, count(Webhooks::deliveries()));
});

// ---------- L'écran ----------

Tests::run("l'écran des intégrations est réservé à l'administration", function (): void {
    seed();
    assertSame(302, visit('GET', '/integrations')->status, 'sans session, on ne voit rien');

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', '/integrations')->status);
    // Même un droit délégué ne suffit pas : ouvrir une porte d'entrée relève de l'administration.
    Users::setRoleFlag(2, 'is_it', true);
    assertSame(403, visit('GET', '/integrations')->status);
    assertSame(403, visit('POST', '/integrations/jetons', ['label' => 'X', 'scopes' => ['rh']])->status);

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    assertSame(200, visit('GET', '/integrations')->status);
});

Tests::run("le jeton créé s'affiche une fois, avec l'écran et pas ailleurs", function (): void {
    seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);

    $response = visit('POST', '/integrations/jetons', [
        'label' => 'Connecteur paie', 'scopes' => ['rh'], 'days' => '30',
    ]);
    assertSame(200, $response->status, 'la valeur en clair impose de rendre la page, pas de rediriger');

    $row = Db::get('SELECT * FROM api_tokens ORDER BY id DESC LIMIT 1');
    assertSame('Connecteur paie', $row['label']);
    assertSame('rh', $row['scopes']);
    assertSame(gmdate('Y-m-d', time() + 30 * 86400), $row['expires_at']);

    // La valeur en clair est dans la page, et son empreinte correspond bien au
    // jeton enregistré : c'est le seul instant où elle existe hors mémoire.
    assertSame(1, preg_match('/sm_[A-Za-z0-9_-]{20,}/', $response->body, $shown),
        'le jeton est montré maintenant ou jamais');
    assertSame($row['token_hash'], ApiTokens::hash($shown[0]));

    // Rouvrir l'écran ne montre plus que le préfixe, jamais la valeur.
    $again = visit('GET', '/integrations')->body;
    assertTrue(!str_contains($again, $shown[0]), 'la valeur ne réapparaît pas');
    assertContains($row['prefix'], $again, 'le préfixe sert à reconnaître le jeton dans la liste');

    // Le journal garde la trace de la création, sans la valeur.
    $entry = Db::get("SELECT * FROM audit_log WHERE action = 'api.jeton_cree'");
    assertTrue($entry !== null);
    assertTrue(!str_contains((string) $entry['detail'], 'sm_'), "le journal ne conserve pas le jeton");

    // Révocation par l'écran : immédiate.
    assertSame(302, visit('POST', '/integrations/jetons/' . $row['id'] . '/revoquer')->status);
    assertTrue(Db::get('SELECT * FROM api_tokens WHERE id = ?', [$row['id']])['revoked_at'] !== null);
});

Tests::run("l'écran refuse un webhook interne et garde le motif lisible", function (): void {
    seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);

    $refused = visit('POST', '/integrations/webhooks', [
        'label' => 'Local', 'url' => 'http://127.0.0.1:9000/hook', 'events' => ['facture.creee'],
    ]);
    assertSame(302, $refused->status);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM webhooks'));

    // Coché sciemment, le même envoi passe.
    $accepted = visit('POST', '/integrations/webhooks', [
        'label' => 'Local', 'url' => 'http://127.0.0.1:9000/hook',
        'events' => ['facture.creee'], 'allow_private' => '1',
    ]);
    assertSame(200, $accepted->status);
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM webhooks WHERE allow_private = 1'));
});
