<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Messages;
use App\Modules\Notifications;
use App\Modules\Users;

Tests::run('un message part, arrive, et prévient son destinataire', function (): void {
    $ids = seed();
    $result = Messages::send($ids['member'], $ids['admin'], 'Congés d\'été', 'Puis-je poser la semaine 32 ?');
    assertTrue($result['ok']);
    assertSame(1, count(Messages::inbox($ids['admin'])));
    assertSame(1, count(Messages::sent($ids['member'])));
    assertSame(1, Messages::unreadCount($ids['admin']));
    assertSame(1, Notifications::unreadCount($ids['admin']));
});

Tests::run('un message refuse un destinataire absent, soi-même, ou un vide', function (): void {
    $ids = seed();
    assertTrue(!Messages::send($ids['member'], 9999, 'Objet', 'Corps')['ok']);
    assertTrue(!Messages::send($ids['member'], $ids['member'], 'Objet', 'Corps')['ok'], 's\'écrire à soi-même est passé');
    assertTrue(!Messages::send($ids['member'], $ids['admin'], '', 'Corps')['ok']);
    assertTrue(!Messages::send($ids['member'], $ids['admin'], 'Objet', '   ')['ok']);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM messages'));
});

Tests::run('un compte fermé ne reçoit plus de message', function (): void {
    $ids = seed();
    Db::run('UPDATE users SET active = 0 WHERE id = ?', [$ids['member']]);
    assertTrue(!Messages::send($ids['admin'], $ids['member'], 'Objet', 'Corps')['ok']);
});

Tests::run('la marque « lu » n\'est posée que par le destinataire', function (): void {
    $ids = seed();
    $id = (int) Messages::send($ids['member'], $ids['admin'], 'Objet', 'Corps')['id'];

    // L'auteur relit son envoi : le message reste non lu.
    Messages::open($id, $ids['member']);
    assertSame(1, Messages::unreadCount($ids['admin']));

    Messages::open($id, $ids['admin']);
    assertSame(0, Messages::unreadCount($ids['admin']));
});

Tests::run('un tiers n\'ouvre pas un message qui ne le concerne pas', function (): void {
    $ids = seed();
    $tiers = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    $id = (int) Messages::send($ids['member'], $ids['admin'], 'Confidentiel', 'Corps')['id'];
    assertSame(null, Messages::open($id, $tiers));
    assertTrue(!Messages::remove($id, $tiers), 'un tiers a supprimé le message');
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM messages'));
});

Tests::run('chacun supprime les messages qui le concernent', function (): void {
    $ids = seed();
    $id = (int) Messages::send($ids['member'], $ids['admin'], 'Objet', 'Corps')['id'];
    assertTrue(Messages::remove($id, $ids['admin']));
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM messages'));
});

Tests::run('la messagerie s\'affiche et envoie depuis l\'écran', function (): void {
    $ids = seed();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', '/messagerie', [
        'recipient_id' => (string) $ids['admin'], 'subject' => 'Question matériel', 'body' => 'Mon écran clignote.',
    ]);
    assertSame(1, count(Messages::inbox($ids['admin'])));

    $page = visit('GET', '/messagerie', [], ['boite' => 'envoyes']);
    assertSame(200, $page->status);
    assertContains('Question matériel', $page->body);
    // Les réglages de serveur sont affichés, jamais proposés à la saisie.
    assertTrue(!str_contains($page->body, 'name="mail_imap_host"'));
});
