<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Horloge;
use App\Core\Imap;
use App\Core\Mime;
use App\Core\Secrets;

/**
 * Relève de la messagerie externe, membre par membre.
 *
 * Chaque salarié a une adresse professionnelle chez l'hébergeur de courriel de
 * l'entreprise. Ce module en rapporte les messages récents dans son espace,
 * pour qu'il n'ait pas à ouvrir un second outil pour savoir ce qui l'attend.
 *
 * Quatre règles tiennent tout le reste :
 *
 *   — **la boîte reste la source.** La relève est en lecture seule : elle ne
 *     marque rien comme lu, ne déplace rien, n'efface rien. Le membre lit la
 *     même boîte depuis son propre logiciel, et une relève qui marque les
 *     messages lui volerait ses non-lus ;
 *   — **l'administration branche, elle ne lit pas.** Les identifiants sont
 *     posés par un administrateur — un membre ne se donne pas une adresse —
 *     mais les messages relevés ne sont lisibles que par leur destinataire.
 *     C'est la même séparation que pour les signalements internes ;
 *   — **le mot de passe est chiffré en base**, comme les autres secrets de
 *     l'instance. Une copie de la base ne donne pas les boîtes du personnel ;
 *   — **la relève est bornée et rejouable.** Un nombre de messages par passage,
 *     une fenêtre de jours, et une contrainte d'unicité (membre, uid) qui fait
 *     qu'un message déjà rapporté ne l'est pas deux fois.
 */
final class MemberMail
{
    public const DEFAULTS = ['port' => 993, 'folder' => 'INBOX', 'sinceDays' => 30, 'batch' => 50];
    public const MAX_BATCH = 200;
    public const MAX_BODY = 20000;

    // Après cette série d'échecs consécutifs, la relève de ce membre s'éteint
    // d'elle-même. Continuer à présenter un mot de passe refusé, c'est faire
    // verrouiller le compte chez l'hébergeur — et le salarié n'y est pour rien.
    public const FAILURE_LIMIT = 5;

    private const COLUMNS = 'id, first_name, last_name, mail_address, mail_imap_host, mail_imap_port,
                             mail_imap_secure, mail_password, mail_smtp_host, mail_smtp_port,
                             mail_sync_enabled, mail_synced_at, mail_sync_report, mail_sync_failures';

    /** La connexion. Remplaçable : les tests fournissent leur propre client. */
    public static ?\Closure $connector = null;

    /** Les réglages d'un membre, mot de passe en clair compris. Usage interne. */
    public static function settingsFor(int $userId): ?array
    {
        $row = Db::get('SELECT ' . self::COLUMNS . ' FROM users WHERE id = ?', [$userId]);
        if ($row === null) {
            return null;
        }

        return [
            'userId' => (int) $row['id'],
            'name' => $row['first_name'] . ' ' . $row['last_name'],
            'address' => (string) ($row['mail_address'] ?? ''),
            'host' => (string) ($row['mail_imap_host'] ?? ''),
            'port' => (int) ($row['mail_imap_port'] ?? 0) ?: self::DEFAULTS['port'],
            'secure' => (int) ($row['mail_imap_secure'] ?? 1) !== 0,
            'password' => Secrets::decrypt($row['mail_password'] ?? ''),
            'smtpHost' => (string) ($row['mail_smtp_host'] ?? ''),
            'smtpPort' => $row['mail_smtp_port'] === null ? null : (int) $row['mail_smtp_port'],
            'enabled' => (int) ($row['mail_sync_enabled'] ?? 0) === 1,
            'syncedAt' => $row['mail_synced_at'],
            'report' => (string) ($row['mail_sync_report'] ?? ''),
            'failures' => (int) ($row['mail_sync_failures'] ?? 0),
        ];
    }

    /** Ce qu'on montre à l'écran : la présence du mot de passe, jamais sa valeur. */
    public static function displaySettings(int $userId): ?array
    {
        $current = self::settingsFor($userId);
        if ($current === null) {
            return null;
        }

        $stored = Db::value('SELECT mail_password FROM users WHERE id = ?', [$userId]);
        $current['password'] = Secrets::mask(is_string($stored) ? $stored : '');
        return $current;
    }

    /** Une adresse, un serveur, un mot de passe : sans les trois, rien à relever. */
    public static function isReady(?array $current): bool
    {
        return $current !== null
            && $current['address'] !== ''
            && $current['host'] !== ''
            && $current['password'] !== '';
    }

    /**
     * Enregistre les identifiants. Réservé à l'administration.
     *
     * Le mot de passe laissé vide ne l'efface pas : c'est le cas courant quand on
     * ne vient corriger qu'un nom de serveur, et redemander le mot de passe à
     * chaque retouche finirait par le faire écrire sur un papier.
     */
    public static function setCredentials(int $userId, array $values): array
    {
        $stored = Db::get('SELECT mail_password FROM users WHERE id = ?', [$userId]);
        if ($stored === null) {
            return ['ok' => false, 'message' => 'Membre introuvable.'];
        }

        $rawPort = $values['port'] ?? null;
        $port = ($rawPort === null || $rawPort === '') ? null : (int) $rawPort;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return ['ok' => false, 'message' => 'Port IMAP invalide (1 à 65535).'];
        }

        $host = mb_substr(trim((string) ($values['host'] ?? '')), 0, 200);
        $wanted = in_array($values['enabled'] ?? null, [true, '1', 'on', 1], true);

        $password = ($values['password'] ?? '') === ''
            ? (string) $stored['mail_password']
            : Secrets::encrypt(mb_substr((string) $values['password'], 0, 400));

        // Allumer la relève sans serveur ni mot de passe ne relèverait rien et
        // s'éteindrait au premier passage : autant le dire tout de suite.
        if ($wanted && !($host !== '' && Secrets::decrypt($password) !== '')) {
            return ['ok' => false, 'message' => 'Pour relever cette boîte, il faut un serveur IMAP et un mot de passe.'];
        }

        $secure = in_array($values['secure'] ?? null, [false, '0', 0], true) ? 0 : 1;

        Db::run(
            'UPDATE users
             SET mail_imap_host = ?, mail_imap_port = ?, mail_imap_secure = ?, mail_password = ?,
                 mail_sync_enabled = ?, mail_sync_failures = CASE WHEN ? = 1 THEN 0 ELSE mail_sync_failures END
             WHERE id = ?',
            [$host, $port, $secure, $password, $wanted ? 1 : 0, $wanted ? 1 : 0, $userId]
        );

        return ['ok' => true];
    }

    /** Coupe la relève sans toucher aux identifiants. */
    public static function disable(int $userId, string $reason = ''): void
    {
        Db::run(
            'UPDATE users SET mail_sync_enabled = 0, mail_sync_report = ? WHERE id = ?',
            [mb_substr($reason, 0, 300), $userId]
        );
    }

    public static function recordOutcome(int $userId, array $result): void
    {
        $ok = !empty($result['ok']);
        Db::run(
            "UPDATE users
             SET mail_sync_report = ?, mail_synced_at = datetime('now'),
                 mail_sync_failures = CASE WHEN ? = 1 THEN 0 ELSE mail_sync_failures + 1 END
             WHERE id = ?",
            [mb_substr((string) ($result['message'] ?? ''), 0, 300), $ok ? 1 : 0, $userId]
        );

        if ($ok) {
            return;
        }

        // Le compte a refusé cinq fois de suite : on arrête d'insister. L'hébergeur
        // verrouillerait la boîte, et le membre ne saurait pas pourquoi.
        $failures = (int) Db::value('SELECT mail_sync_failures FROM users WHERE id = ?', [$userId]);
        if ($failures >= self::FAILURE_LIMIT) {
            self::disable($userId, mb_substr(
                'Relève éteinte après ' . self::FAILURE_LIMIT . ' échecs : ' . (string) ($result['message'] ?? ''),
                0,
                300
            ));
            self::notifyAdmins($userId);
        }
    }

    /** Prévient l'administration, pas le membre : c'est un réglage, pas son travail. */
    private static function notifyAdmins(int $userId): void
    {
        $member = Db::get('SELECT first_name, last_name FROM users WHERE id = ?', [$userId]);
        if ($member === null) {
            return;
        }

        foreach (Db::all("SELECT id FROM users WHERE role = 'admin' AND active = 1") as $admin) {
            Notifications::push((int) $admin['id'], 'Relève de messagerie éteinte', [
                'kind' => 'messagerie',
                'body' => 'La boîte de ' . $member['first_name'] . ' ' . $member['last_name']
                    . ' a refusé la connexion ' . self::FAILURE_LIMIT . ' fois de suite.',
                'link' => '/admin/employes/' . $userId . '/modifier',
                'dedupeKey' => 'mail-sync-off-' . $userId,
            ]);
        }
    }

    /** La connexion. Injectable : les tests fournissent leur propre client. */
    private static function connect(array $current): object
    {
        if (self::$connector !== null) {
            return (self::$connector)($current);
        }

        $client = new Imap($current['host'], $current['port'], $current['secure'], false);
        $client->connect();
        $client->login($current['address'], $current['password']);
        return $client;
    }

    private static function since(int $days): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-$days days");
    }

    /** « Nom <adresse> » tel qu'il arrive dans l'en-tête, séparé en deux. */
    public static function addressOf(string $from): array
    {
        $from = trim($from);
        if (preg_match('/^(.*)<([^>]*)>\s*$/', $from, $matches) === 1) {
            $name = trim(trim($matches[1]), '"\' ');
            return [
                'name' => mb_substr($name, 0, 200),
                'address' => mb_substr(trim($matches[2]), 0, 254),
            ];
        }

        return ['name' => '', 'address' => mb_substr($from, 0, 254)];
    }

    /**
     * Relève la boîte d'un membre. Ne lève jamais : un serveur indisponible ne doit
     * pas emporter le balayage des autres membres avec lui.
     */
    public static function fetchFor(int $userId, array $options = []): array
    {
        $current = self::settingsFor($userId);
        if ($current === null) {
            return ['ok' => false, 'message' => 'Membre introuvable.'];
        }
        if (!$current['enabled']) {
            return ['ok' => false, 'message' => 'Relève éteinte pour ce membre.'];
        }
        if (!self::isReady($current)) {
            return ['ok' => false, 'message' => 'Boîte non configurée.'];
        }

        $limit = min(self::MAX_BATCH, max(1, (int) ($options['batch'] ?? 0) ?: self::DEFAULTS['batch']));
        $sinceDays = max(1, (int) ($options['sinceDays'] ?? 0) ?: self::DEFAULTS['sinceDays']);
        $audit = !empty($options['audit']);

        try {
            $client = self::connect($current);
        } catch (\Throwable $error) {
            $result = ['ok' => false, 'message' => 'Connexion refusée : ' . $error->getMessage()];
            self::recordOutcome($userId, $result);
            return $result;
        }

        $scanned = 0;
        $added = 0;

        try {
            $client->select(self::DEFAULTS['folder']);
            $uids = $client->searchSince(self::since($sinceDays));

            // Les plus récents d'abord : si la fenêtre déborde le lot, mieux vaut
            // rapporter ce qui vient d'arriver que ce qui date de trois semaines.
            $wanted = array_reverse(array_slice($uids, -$limit));

            foreach ($wanted as $uid) {
                $scanned++;
                $raw = $client->fetchMessage((int) $uid);
                $envelope = Mime::envelope($raw);
                $from = self::addressOf((string) $envelope['from']);

                $added += Db::run(
                    'INSERT OR IGNORE INTO external_messages
                       (user_id, uid, from_name, from_address, subject, body, attachments, sent_at, seen_on_server)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $userId,
                        (string) $uid,
                        $from['name'],
                        $from['address'],
                        mb_substr((string) $envelope['subject'], 0, 300),
                        mb_substr(Mime::textBody($raw), 0, self::MAX_BODY),
                        count(Mime::attachments($raw)),
                        $envelope['date'],
                        0,
                    ]
                );
            }
        } catch (\Throwable $error) {
            $result = [
                'ok' => false,
                'message' => 'Relève interrompue : ' . $error->getMessage(),
                'scanned' => $scanned,
                'added' => $added,
            ];
            self::recordOutcome($userId, $result);
            try {
                $client->logout();
            } catch (\Throwable) {
                // la connexion est déjà perdue
            }
            return $result;
        }

        try {
            $client->logout();
        } catch (\Throwable) {
            // rien à sauver
        }

        $result = [
            'ok' => true,
            'scanned' => $scanned,
            'added' => $added,
            'message' => $added . ' message(s) nouveau(x) sur ' . $scanned . ' relevé(s).',
        ];
        self::recordOutcome($userId, $result);
        if ($added > 0 && $audit) {
            // Ce qu'on journalise, c'est le passage — jamais un sujet ni un expéditeur.
            Audit::log('messagerie.releve', 'users', $userId, ['messages' => $added]);
        }
        return $result;
    }

    /** Essai de connexion : le seul moyen de savoir que les identifiants passent. */
    public static function test(int $userId): array
    {
        $current = self::settingsFor($userId);
        if (!self::isReady($current)) {
            return ['ok' => false, 'message' => 'Boîte non configurée.'];
        }

        try {
            $client = self::connect($current);
        } catch (\Throwable $error) {
            return ['ok' => false, 'message' => 'Connexion refusée : ' . $error->getMessage()];
        }

        try {
            $client->select(self::DEFAULTS['folder']);
            $waiting = count($client->searchSince(self::since(self::DEFAULTS['sinceDays'])));
            $client->logout();
            return ['ok' => true, 'waiting' => $waiting, 'message' => 'Boîte ouverte : ' . $waiting . ' message(s) dans la fenêtre.'];
        } catch (\Throwable $error) {
            try {
                $client->logout();
            } catch (\Throwable) {
                // connexion perdue
            }
            return ['ok' => false, 'message' => 'Boîte illisible : ' . $error->getMessage()];
        }
    }

    /** Les membres dont la boîte est à relever, dans l'ordre du plus ancien passage. */
    public static function due(): array
    {
        $rows = Db::all(
            "SELECT id FROM users
             WHERE active = 1 AND mail_sync_enabled = 1
               AND mail_address != '' AND mail_imap_host != '' AND mail_password != ''
             ORDER BY COALESCE(mail_synced_at, '') ASC"
        );
        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Relève tout le monde. Appelée par le balayage périodique.
     *
     * Bornée par passage : sur une instance de deux cents personnes, tout relever
     * d'un coup tiendrait le balayage pendant des minutes. Les boîtes non servies
     * ce tour-ci passent en tête du suivant, puisque l'ordre est celui du dernier
     * passage.
     */
    public static function fetchAll(int $members = 25): array
    {
        $ids = array_slice(self::due(), 0, max(1, $members));
        $added = 0;
        $failed = 0;

        foreach ($ids as $id) {
            $result = self::fetchFor($id);
            if (!empty($result['ok'])) {
                $added += (int) ($result['added'] ?? 0);
            } else {
                $failed++;
            }
        }

        return ['boxes' => count($ids), 'added' => $added, 'failed' => $failed];
    }

    /** Ce que le membre voit dans son espace. Lui seul. */
    public static function inbox(int $userId, int $limit = 50): array
    {
        return Db::all(
            'SELECT id, uid, from_name, from_address, subject, body, attachments, sent_at, fetched_at
             FROM external_messages
             WHERE user_id = ?
             ORDER BY COALESCE(sent_at, fetched_at) DESC
             LIMIT ?',
            [$userId, min(200, max(1, $limit))]
        );
    }

    /** Un message relevé, et seulement si c'est bien le destinataire qui l'ouvre. */
    public static function message(int $id, int $userId): ?array
    {
        return Db::get('SELECT * FROM external_messages WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    /**
     * Efface la copie locale. La boîte, elle, n'est pas touchée : le message est
     * toujours chez l'hébergeur, et une relève ultérieure pourrait le rapporter.
     */
    public static function forget(int $userId): int
    {
        return Db::run('DELETE FROM external_messages WHERE user_id = ?', [$userId]);
    }

    /** La copie locale ne sert qu'à lire : passé ce délai, elle n'a plus d'objet. */
    public static function purge(int $days = 90): int
    {
        return Db::run(
            "DELETE FROM external_messages WHERE fetched_at < ?",
            [Horloge::instant('-' . max(1, $days) . ' days')]
        );
    }
}
