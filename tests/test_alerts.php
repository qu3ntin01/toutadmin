<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Users;
use App\Modules\Whistleblow;

/** Une instance avec un référent désigné : sans lui, le dispositif est fermé. */
function seedAlerts(): array
{
    $ids = seed();
    $ids['referent'] = Users::create([
        'role' => 'employee', 'email' => 'referent@entreprise.com', 'password' => 'Referent-Demo-2026!',
        'first_name' => 'Rachid', 'last_name' => 'Amrani',
    ]);
    Users::setRoleFlag($ids['referent'], 'is_referent', true);
    return $ids;
}

function loginMemberAlerts(): void
{
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
}

function loginReferent(): void
{
    visit('POST', '/connexion', ['email' => 'referent@entreprise.com', 'password' => 'Referent-Demo-2026!']);
}

Tests::run("un signalement anonyme n'enregistre pas son auteur", function (): void {
    $ids = seedAlerts();
    loginMemberAlerts();

    visit('POST', '/alertes/signalements', [
        'subject' => 'Facturation douteuse', 'category' => 'Fraude',
        'body' => 'Des factures sans bon de commande.', 'anonymous' => '1',
    ]);

    $row = Db::get('SELECT * FROM whistleblow_reports');
    assertSame(null, $row['author_id'], "l'auteur d'un signalement anonyme est enregistré");
    assertSame(1, (int) $row['anonymous']);

    // Le contenu est chiffré : une copie de la base ne le livre pas.
    assertTrue(!str_contains($row['subject_enc'], 'Facturation'), 'le sujet est en clair en base');
    assertTrue(!str_contains($row['body_enc'], 'bon de commande'), 'le corps est en clair en base');
    assertSame('Facturation douteuse', Whistleblow::byId((int) $row['id'])['subject']);

    // Le journal général retient qu'un signalement est arrivé, jamais lequel ni de qui.
    $entry = Db::get("SELECT * FROM audit_log WHERE action = 'alerte.deposee'");
    assertTrue($entry !== null);
    assertSame(null, $entry['actor_id']);
    assertTrue(!str_contains($entry['detail'], 'Facturation'));
    assertSame(0, (int) Db::value("SELECT COUNT(*) FROM audit_log WHERE detail LIKE '%ALT-%'"));
});

Tests::run('un signalement signé garde son auteur', function (): void {
    $ids = seedAlerts();
    loginMemberAlerts();

    visit('POST', '/alertes/signalements', [
        'subject' => 'Sécurité machine', 'category' => 'Sécurité des personnes', 'anonymous' => '0',
    ]);
    $row = Db::get('SELECT * FROM whistleblow_reports');
    assertSame($ids['member'], (int) $row['author_id']);
    assertSame(0, (int) $row['anonymous']);
});

Tests::run("sans référent désigné, le dispositif n'est pas ouvert", function (): void {
    seed();
    loginMemberAlerts();

    visit('POST', '/alertes/signalements', ['subject' => 'Un signalement', 'category' => 'Fraude']);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM whistleblow_reports'));

    // Un référent désigné ouvre le dispositif ; une catégorie inventée reste refusée.
    Users::setRoleFlag((int) Db::value("SELECT id FROM users WHERE role = 'admin'"), 'is_referent', true);
    visit('POST', '/alertes/signalements', ['subject' => 'Un signalement', 'category' => 'Potins']);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM whistleblow_reports'));

    visit('POST', '/alertes/signalements', ['subject' => 'Un signalement', 'category' => 'Fraude']);
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM whistleblow_reports'));
});

Tests::run('la référence est annuelle et le code ne sort qu\'une fois', function (): void {
    $ids = seedAlerts();

    $first = Whistleblow::create(['category' => 'Fraude', 'subject' => 'Un', 'body' => '']);
    $second = Whistleblow::create(['category' => 'Fraude', 'subject' => 'Deux', 'body' => '']);
    $year = gmdate('Y');
    assertSame("ALT-$year-0001", $first['reference']);
    assertSame("ALT-$year-0002", $second['reference']);
    assertSame(20, strlen($first['code']));

    // Le code n'est gardé que haché.
    $row = Db::get('SELECT * FROM whistleblow_reports WHERE reference = ?', [$first['reference']]);
    assertSame(Whistleblow::hashCode($first['code']), $row['follow_code_hash']);
    assertTrue($row['follow_code_hash'] !== $first['code']);

    // Référence et code doivent aller ensemble.
    assertTrue(Whistleblow::openFollow($first['reference'], $first['code']) !== null);
    assertSame(null, Whistleblow::openFollow($first['reference'], $second['code']));
    assertSame(null, Whistleblow::openFollow('ALT-2000-0001', $first['code']));
    // La casse et les espaces d'une saisie à la main ne font pas échouer le suivi.
    assertTrue(Whistleblow::openFollow(strtolower($first['reference']), ' ' . strtolower($first['code']) . ' ') !== null);
});

Tests::run('le suivi se fait sans compte, et sans rien livrer de plus', function (): void {
    $ids = seedAlerts();
    $issued = Whistleblow::create(['category' => 'Harcèlement', 'subject' => 'Propos déplacés', 'body' => 'Détails.']);

    // Aucune session : la page de suivi répond quand même.
    $form = visit('GET', '/alertes/suivi');
    assertSame(200, $form->status);

    $refused = visit('POST', '/alertes/suivi', ['reference' => $issued['reference'], 'code' => 'FAUXCODE']);
    assertSame(200, $refused->status);
    assertTrue(!str_contains($refused->body, 'Propos déplacés'), 'un mauvais code a ouvert le signalement');

    $opened = visit('POST', '/alertes/suivi', ['reference' => $issued['reference'], 'code' => $issued['code']]);
    assertContains('Propos déplacés', $opened->body);

    // L'auteur répond depuis cet écran, toujours sans compte.
    visit('POST', '/alertes/suivi/message', [
        'reference' => $issued['reference'], 'code' => $issued['code'], 'body' => 'Une précision.',
    ]);
    $messages = Whistleblow::messages((int) Db::value('SELECT id FROM whistleblow_reports'));
    assertSame(1, count($messages));
    assertSame('auteur', $messages[0]['author_kind']);
    assertSame('Une précision.', $messages[0]['body']);
    assertTrue(!str_contains(Db::get('SELECT * FROM whistleblow_messages')['body_enc'], 'précision'));
});

Tests::run('les référents seuls lisent, et leur passage est tracé', function (): void {
    $ids = seedAlerts();
    $issued = Whistleblow::create(['category' => 'Fraude', 'subject' => 'Marchés truqués', 'body' => '']);
    $id = (int) Db::value('SELECT id FROM whistleblow_reports');

    // L'administration désigne les référents, mais ne lit rien.
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    assertSame(403, visit('GET', '/alertes/signalements/' . $id)->status);
    assertSame(403, visit('POST', '/alertes/signalements/' . $id . '/accuser')->status);

    loginMemberAlerts();
    assertSame(403, visit('GET', '/alertes/signalements/' . $id)->status);

    loginReferent();
    $page = visit('GET', '/alertes/signalements/' . $id);
    assertSame(200, $page->status);
    assertContains('Marchés truqués', $page->body);

    // Qui a ouvert quoi : la trace vit dans le dispositif, pas au journal général.
    $log = Whistleblow::accessLog($id);
    assertSame(1, count($log));
    assertSame('Rachid', $log[0]['first_name']);
    assertSame(0, (int) Db::value("SELECT COUNT(*) FROM audit_log WHERE entity = 'whistleblow_reports' AND entity_id IS NOT NULL"));
});

Tests::run("l'instruction suit ses délais légaux", function (): void {
    $ids = seedAlerts();
    Whistleblow::create(['category' => 'Fraude', 'subject' => 'Un signalement', 'body' => '']);
    $id = (int) Db::value('SELECT id FROM whistleblow_reports');

    // Déposé il y a dix jours, sans accusé : le délai de sept jours est dépassé.
    Db::run("UPDATE whistleblow_reports SET submitted_at = datetime('now', '-10 days') WHERE id = ?", [$id]);
    assertSame(1, Whistleblow::summary()['lateAck']);
    assertSame(0, Whistleblow::summary()['lateOutcome']);

    loginReferent();
    visit('POST', '/alertes/signalements/' . $id . '/accuser');
    assertTrue(Whistleblow::byId($id)['acknowledged_at'] !== null);
    assertSame(0, Whistleblow::summary()['lateAck']);

    // Cent jours : le retour sur les suites est attendu.
    Db::run("UPDATE whistleblow_reports SET submitted_at = datetime('now', '-100 days') WHERE id = ?", [$id]);
    assertSame(1, Whistleblow::summary()['lateOutcome']);

    visit('POST', '/alertes/signalements/' . $id . '/suites', ['outcome' => 'Enquête interne menée, sans suite.']);
    visit('POST', '/alertes/signalements/' . $id . '/statut', ['status' => 'Clôturée']);
    $report = Whistleblow::byId($id);
    assertSame('Clôturée', $report['status']);
    assertTrue($report['closed_at'] !== null);
    assertSame('Enquête interne menée, sans suite.', $report['outcome']);
    // Une fois close, elle sort du décompte des délais.
    assertSame(0, Whistleblow::summary()['lateOutcome']);
    assertSame(0, Whistleblow::summary()['open']);

    // Un statut inventé ne passe pas.
    assertSame(false, Whistleblow::setStatus($id, 'Enterrée'));
});

Tests::run("le référent répond à l'auteur, qui lit la réponse sans compte", function (): void {
    $ids = seedAlerts();
    $issued = Whistleblow::create(['category' => 'Fraude', 'subject' => 'Un signalement', 'body' => '']);
    $id = (int) Db::value('SELECT id FROM whistleblow_reports');

    loginReferent();
    visit('POST', '/alertes/signalements/' . $id . '/message', ['body' => 'Votre signalement est recevable.']);
    visit('POST', '/alertes/signalements/' . $id . '/message', ['body' => '   ']);

    $messages = Whistleblow::messages($id);
    assertSame(1, count($messages), 'un message vide a été transmis');
    assertSame('referent', $messages[0]['author_kind']);
    assertSame($ids['referent'], (int) $messages[0]['referent_id']);

    $page = visit('POST', '/alertes/suivi', ['reference' => $issued['reference'], 'code' => $issued['code']]);
    assertContains('Votre signalement est recevable.', $page->body);
});
