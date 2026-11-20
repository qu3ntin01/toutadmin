<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Audit;
use App\Core\Imap;
use App\Core\Mime;
use App\Core\Secrets;
use App\Core\Settings;

/**
 * Capture d'une boîte aux lettres par IMAP.
 *
 * Les factures fournisseurs arrivent par courriel, à une adresse dédiée du
 * genre « factures@ ». Ce module relève cette boîte, prend les pièces jointes
 * qui ressemblent à des factures, les fait passer par la même réception que
 * les dépôts manuels, puis marque le message comme lu — ou le range dans un
 * dossier, au choix.
 *
 * Ce qu'il ne fait pas, volontairement :
 *
 *   — **il ne supprime jamais un message.** La boîte reste la source ; le CMS
 *     n'en est qu'un lecteur. Une capture qui efface est une capture qu'on ne
 *     peut pas rejouer ;
 *   — **il ne lit que ce qu'on lui désigne** : un dossier, et les messages non
 *     lus depuis un nombre de jours borné. Pas d'aspiration d'archive ;
 *   — **il ne crée aucune facture.** Les pièces atterrissent dans la corbeille
 *     du comptable, qui décide.
 */
final class Mailbox
{
    public const KEYS = [
        'enabled' => 'imap.enabled',
        'host' => 'imap.host',
        'port' => 'imap.port',
        'secure' => 'imap.secure',
        'user' => 'imap.user',
        'password' => 'imap.password',
        'folder' => 'imap.folder',
        'action' => 'imap.action',
        'moveFolder' => 'imap.move_folder',
        'sinceDays' => 'imap.since_days',
        'batch' => 'imap.batch',
        'allowSelfSigned' => 'imap.allow_self_signed',
        'status' => 'imap.status',
    ];

    public const ACTIONS = [
        ['key' => 'seen', 'label' => 'Marquer le message comme lu'],
        ['key' => 'move', 'label' => 'Déplacer le message dans un dossier'],
    ];

    public const DEFAULTS = [
        'port' => 993, 'folder' => 'INBOX', 'action' => 'seen',
        'sinceDays' => 30, 'batch' => 25, 'moveFolder' => 'Traitées',
    ];
    public const MAX_BATCH = 100;

    /**
     * Les pièces jointes qui méritent d'être lues : une signature d'image ou un
     * calendrier .ics ne sont pas des factures.
     */
    public const ATTACHMENT_TYPES = [
        'application/pdf' => 'application/pdf',
        'application/x-pdf' => 'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /** La connexion. Remplaçable : les tests fournissent leur propre client. */
    public static ?\Closure $connector = null;

    // ---------- Configuration ----------

    public static function config(): array
    {
        $action = (string) Settings::get(self::KEYS['action']);
        $known = array_column(self::ACTIONS, 'key');

        return [
            'enabled' => Settings::get(self::KEYS['enabled']) === '1',
            'host' => (string) Settings::get(self::KEYS['host']),
            'port' => (int) Settings::get(self::KEYS['port']) ?: self::DEFAULTS['port'],
            'secure' => Settings::get(self::KEYS['secure']) !== '0',
            'user' => (string) Settings::get(self::KEYS['user']),
            'password' => Secrets::decrypt(Settings::get(self::KEYS['password'])),
            'folder' => (string) (Settings::get(self::KEYS['folder']) ?: self::DEFAULTS['folder']),
            'action' => in_array($action, $known, true) ? $action : self::DEFAULTS['action'],
            'moveFolder' => (string) (Settings::get(self::KEYS['moveFolder']) ?: self::DEFAULTS['moveFolder']),
            'sinceDays' => (int) Settings::get(self::KEYS['sinceDays']) ?: self::DEFAULTS['sinceDays'],
            'batch' => min(self::MAX_BATCH, (int) Settings::get(self::KEYS['batch']) ?: self::DEFAULTS['batch']),
            'allowSelfSigned' => Settings::get(self::KEYS['allowSelfSigned']) === '1',
        ];
    }

    public static function displayConfig(): array
    {
        $current = self::config();
        $current['password'] = Secrets::mask(Settings::get(self::KEYS['password']));
        return $current;
    }

    public static function setConfig(array $values): array
    {
        $host = mb_substr(trim((string) ($values['host'] ?? '')), 0, 200);
        $user = mb_substr(trim((string) ($values['user'] ?? '')), 0, 200);
        $port = (int) ($values['port'] ?? 0) ?: self::DEFAULTS['port'];
        $sinceDays = (int) ($values['sinceDays'] ?? 0) ?: self::DEFAULTS['sinceDays'];
        $batch = (int) ($values['batch'] ?? 0) ?: self::DEFAULTS['batch'];
        $action = (string) ($values['action'] ?? '');

        if ($host === '') {
            return ['ok' => false, 'message' => "L'hôte IMAP est requis."];
        }
        if ($user === '') {
            return ['ok' => false, 'message' => "L'identifiant est requis."];
        }
        if ($port < 1 || $port > 65535) {
            return ['ok' => false, 'message' => 'Port invalide.'];
        }
        if ($sinceDays < 1 || $sinceDays > 365) {
            return ['ok' => false, 'message' => 'La fenêtre de relève tient entre 1 et 365 jours.'];
        }
        if ($batch < 1 || $batch > self::MAX_BATCH) {
            return ['ok' => false, 'message' => 'Le lot tient entre 1 et ' . self::MAX_BATCH . ' messages.'];
        }
        if (!in_array($action, array_column(self::ACTIONS, 'key'), true)) {
            return ['ok' => false, 'message' => 'Action inconnue.'];
        }

        Settings::set(self::KEYS['host'], $host);
        Settings::set(self::KEYS['port'], (string) $port);
        Settings::set(self::KEYS['secure'], !empty($values['secure']) ? '1' : '0');
        Settings::set(self::KEYS['user'], $user);
        Settings::set(self::KEYS['folder'], mb_substr(trim((string) ($values['folder'] ?? '')), 0, 120) ?: self::DEFAULTS['folder']);
        Settings::set(self::KEYS['action'], $action);
        Settings::set(self::KEYS['moveFolder'], mb_substr(trim((string) ($values['moveFolder'] ?? '')), 0, 120) ?: self::DEFAULTS['moveFolder']);
        Settings::set(self::KEYS['sinceDays'], (string) $sinceDays);
        Settings::set(self::KEYS['batch'], (string) $batch);
        Settings::set(self::KEYS['allowSelfSigned'], !empty($values['allowSelfSigned']) ? '1' : '0');

        // Un mot de passe laissé vide conserve le précédent.
        $typed = trim((string) ($values['password'] ?? ''));
        if ($typed !== '') {
            Settings::set(self::KEYS['password'], Secrets::encrypt($typed));
        }

        $enabled = !empty($values['enabled']);
        if ($enabled && self::config()['password'] === '') {
            return ['ok' => false, 'message' => "Renseignez le mot de passe avant d'activer la relève."];
        }
        Settings::set(self::KEYS['enabled'], $enabled ? '1' : '0');

        return ['ok' => true];
    }

    public static function isReady(): bool
    {
        $current = self::config();
        return $current['host'] !== '' && $current['user'] !== '' && $current['password'] !== '';
    }

    public static function status(): ?array
    {
        $stored = (string) Settings::get(self::KEYS['status']);
        if ($stored === '') {
            return null;
        }
        $decoded = json_decode($stored, true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function recordStatus(array $result): void
    {
        Settings::set(self::KEYS['status'], (string) json_encode([
            'ok' => !empty($result['ok']),
            'message' => mb_substr((string) ($result['message'] ?? ''), 0, 300),
            'at' => gmdate('c'),
            'received' => (int) ($result['received'] ?? 0),
            'scanned' => (int) ($result['scanned'] ?? 0),
        ], JSON_UNESCAPED_UNICODE));
    }

    // ---------- Relève ----------

    private static function connect(array $current): object
    {
        if (self::$connector !== null) {
            return (self::$connector)($current);
        }

        $client = new Imap(
            $current['host'],
            $current['port'],
            $current['secure'],
            $current['allowSelfSigned']
        );
        $client->connect();
        $client->login($current['user'], $current['password']);
        return $client;
    }

    private static function since(int $days): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify("-$days days");
    }

    /** Les pièces jointes qui ressemblent à une facture, et rien d'autre. */
    public static function attachmentsOf(array $attachments): array
    {
        return array_values(array_filter($attachments, static function (array $attachment): bool {
            $type = strtolower(trim(explode(';', (string) ($attachment['contentType'] ?? ''))[0]));
            $name = mb_strtolower((string) ($attachment['filename'] ?? ''));
            return isset(self::ATTACHMENT_TYPES[$type]) || str_ends_with($name, '.pdf') || str_ends_with($name, '.docx');
        }));
    }

    public static function mimeOf(array $attachment): string
    {
        $type = strtolower(trim(explode(';', (string) ($attachment['contentType'] ?? ''))[0]));
        if (isset(self::ATTACHMENT_TYPES[$type])) {
            return self::ATTACHMENT_TYPES[$type];
        }
        return str_ends_with(mb_strtolower((string) ($attachment['filename'] ?? '')), '.docx')
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/pdf';
    }

    /**
     * Relève la boîte une fois. Rend le compte de ce qui a été vu et de ce qui a
     * été retenu ; ne lève jamais, pour qu'un serveur mail indisponible n'emporte
     * pas le balayage périodique avec lui.
     */
    public static function fetchOnce(): array
    {
        if (!self::isReady()) {
            return ['ok' => false, 'message' => 'Capture non configurée.'];
        }

        $current = self::config();
        try {
            $client = self::connect($current);
        } catch (\Throwable $error) {
            $result = ['ok' => false, 'message' => 'Connexion refusée : ' . $error->getMessage()];
            self::recordStatus($result);
            return $result;
        }

        $received = [];
        $skipped = [];
        $scanned = 0;

        try {
            $client->select($current['folder']);
            $uids = $client->searchUnseenSince(self::since($current['sinceDays']));

            foreach (array_slice($uids, 0, $current['batch']) as $uid) {
                $scanned++;
                $raw = $client->fetchMessage((int) $uid);
                $envelope = Mime::envelope($raw);
                $attachments = self::attachmentsOf(Mime::attachments($raw));

                if ($attachments === []) {
                    $skipped[] = ['uid' => $uid, 'reason' => 'aucune pièce jointe exploitable'];
                }

                foreach ($attachments as $attachment) {
                    $outcome = Intake::receive([
                        'bytes' => (string) $attachment['content'],
                        'originalName' => (string) ($attachment['filename'] ?: 'facture.pdf'),
                        'mimeType' => self::mimeOf($attachment),
                        'source' => 'Courriel',
                        'mail' => [
                            'uid' => (string) $uid,
                            'from' => $envelope['from'],
                            'subject' => $envelope['subject'],
                            'date' => $envelope['date'],
                        ],
                    ]);
                    if (!empty($outcome['ok'])) {
                        $received[] = ['uid' => $uid, 'id' => $outcome['id'], 'name' => $attachment['filename']];
                    } else {
                        $skipped[] = ['uid' => $uid, 'reason' => $outcome['message']];
                    }
                }

                // Le message est marqué lu dans tous les cas : sans cela, un courriel
                // sans pièce jointe reviendrait à chaque relève.
                $client->markSeen((int) $uid);
                if ($current['action'] === 'move' && $current['moveFolder'] !== '') {
                    try {
                        $client->move((int) $uid, $current['moveFolder']);
                    } catch (\Throwable $error) {
                        $skipped[] = ['uid' => $uid, 'reason' => 'déplacement impossible : ' . $error->getMessage()];
                    }
                }
            }
        } catch (\Throwable $error) {
            $result = [
                'ok' => false,
                'message' => 'Relève interrompue : ' . $error->getMessage(),
                'received' => count($received),
                'scanned' => $scanned,
            ];
            self::recordStatus($result);
            $client->logout();
            return $result;
        }

        $client->logout();

        $result = [
            'ok' => true,
            'scanned' => $scanned,
            'received' => count($received),
            'documents' => $received,
            'skipped' => $skipped,
            'message' => count($received) . ' pièce(s) retenue(s) sur ' . $scanned . ' message(s) relevé(s).',
        ];
        self::recordStatus($result);
        if ($received !== []) {
            Audit::log('pieces.capture', 'incoming_documents', null, [
                'recues' => count($received), 'messages' => $scanned,
            ]);
        }
        return $result;
    }

    /** Essai de connexion : le seul moyen de savoir que les identifiants passent. */
    public static function test(): array
    {
        if (!self::isReady()) {
            return ['ok' => false, 'message' => 'Capture non configurée.'];
        }

        $current = self::config();
        try {
            $client = self::connect($current);
        } catch (\Throwable $error) {
            $result = ['ok' => false, 'message' => 'Connexion refusée : ' . $error->getMessage()];
            self::recordStatus($result);
            return $result;
        }

        try {
            $client->select($current['folder']);
            $waiting = count($client->searchUnseenSince(self::since($current['sinceDays'])));
            $client->logout();

            $result = [
                'ok' => true,
                'message' => "Boîte « {$current['folder']} » ouverte : $waiting message(s) non lu(s) dans la fenêtre.",
                'waiting' => $waiting,
            ];
            self::recordStatus($result);
            return $result;
        } catch (\Throwable $error) {
            $client->logout();
            $result = ['ok' => false, 'message' => 'Dossier illisible : ' . $error->getMessage()];
            self::recordStatus($result);
            return $result;
        }
    }
}
