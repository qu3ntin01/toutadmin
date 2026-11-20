<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Recopie du journal vers un collecteur : syslog, Wazuh.
 *
 * Un journal qui ne vit que dans la base qu'il surveille ne prouve rien le jour
 * où quelqu'un a la main sur cette base. Le recopier ailleurs, en continu, est
 * la seule façon d'avoir une trace que l'instance elle-même ne peut pas
 * réécrire. Quatre règles :
 *
 *   — **le filigrane, pas la marque.** Chaque cible garde le numéro de la
 *     dernière ligne reçue, plutôt qu'une case cochée sur chaque ligne. Une
 *     panne du collecteur ne perd rien : au rétablissement, l'export reprend
 *     où il s'était arrêté. Deux collecteurs n'avancent pas du même pas ;
 *   — **la trame est celle de la norme**, pas une invention maison : RFC 5424
 *     (celle qu'on veut) ou RFC 3164 (celle que demandent les vieux
 *     collecteurs). Les niveaux du journal sont déjà ceux de syslog, il n'y a
 *     donc rien à traduire ;
 *   — **Wazuh se branche de deux façons**, et les deux sont ici : le
 *     gestionnaire écoute le syslog (c'est alors une cible syslog ordinaire
 *     pointée sur lui), ou un agent local lit un fichier — auquel cas on écrit
 *     une ligne JSON par événement, ce que l'agent sait décoder tel quel ;
 *   — **une cible qui échoue s'éteint**, après une série d'échecs, plutôt que
 *     de faire croire que les journaux partent.
 *
 * Ce que ce module ne fait pas : garantir la remise. UDP ne le permet pas, et
 * TCP ne le permet qu'à moitié. Ce qu'il garantit, c'est qu'une ligne non
 * envoyée reste en base et repart au passage suivant.
 *
 * Comme pour les webhooks, l'envoi est fait par la tâche planifiée : un site
 * PHP ne tourne qu'au moment d'une requête, et une requête utilisateur n'a pas
 * à attendre un collecteur distant.
 */
final class LogForward
{
    public const TRANSPORTS = ['udp', 'tcp', 'tls', 'fichier'];
    public const FORMATS = ['rfc5424', 'rfc3164', 'json'];
    public const KINDS = ['syslog', 'wazuh'];

    /**
     * Numéro d'entreprise privé réservé aux exemples par l'IANA (RFC 5612) : il
     * nomme nos données structurées sans usurper celui d'un autre.
     */
    public const ENTERPRISE_ID = 32473;

    public const BATCH = 200;
    public const TIMEOUT = 5;
    /** Après cette série d'échecs consécutifs, la cible s'éteint d'elle-même. */
    public const FAILURE_LIMIT = 10;

    /** L'envoi lui-même. Remplaçable pour que les tests se passent du réseau. */
    public static ?\Closure $transport = null;

    /* --------------------------------------------------------------- cibles */

    public static function all(): array
    {
        return Db::all('SELECT * FROM log_targets ORDER BY id');
    }

    public static function byId(int $id): ?array
    {
        return Db::get('SELECT * FROM log_targets WHERE id = ?', [$id]);
    }

    private static function port(mixed $value): ?int
    {
        $port = is_numeric($value) ? (int) $value : null;
        return $port !== null && $port >= 1 && $port <= 65535 ? $port : null;
    }

    /**
     * Contrôle la définition d'une cible. Refuse plutôt que de corriger : une
     * adresse devinée est une adresse fausse qu'on croira bonne.
     */
    public static function check(array $values): array
    {
        $kind = in_array($values['kind'] ?? '', self::KINDS, true) ? $values['kind'] : 'syslog';
        $transport = in_array($values['transport'] ?? '', self::TRANSPORTS, true) ? $values['transport'] : 'udp';
        $format = in_array($values['format'] ?? '', self::FORMATS, true) ? $values['format'] : 'rfc5424';
        $label = mb_substr(trim((string) ($values['label'] ?? '')), 0, 120);

        if ($label === '') {
            return ['ok' => false, 'message' => 'Un nom est nécessaire pour reconnaître la cible.'];
        }

        if ($transport === 'fichier') {
            $file = trim((string) ($values['path'] ?? ''));
            if ($file === '') {
                return ['ok' => false, 'message' => 'Le dépôt lu par l’agent demande un chemin de fichier.'];
            }
            if (!str_starts_with($file, '/')) {
                return ['ok' => false, 'message' => 'Le chemin du dépôt doit être absolu.'];
            }
            if ($format !== 'json') {
                return ['ok' => false, 'message' => 'Un dépôt lu par un agent s’écrit en JSON, une ligne par événement.'];
            }
        } else {
            if (trim((string) ($values['host'] ?? '')) === '') {
                return ['ok' => false, 'message' => 'L’adresse du collecteur est nécessaire.'];
            }
            if (self::port($values['port'] ?? null) === null) {
                return ['ok' => false, 'message' => 'Port invalide (1 à 65535).'];
            }
        }

        $facility = $values['facility'] ?? null;
        if (!is_numeric($facility) || (int) $facility < 0 || (int) $facility > 23) {
            return ['ok' => false, 'message' => 'Facilité syslog invalide (0 à 23).'];
        }

        // Aucun canal coché veut dire « tous » : une liste vide ne doit pas se
        // replier sur le canal par défaut, sinon la cible ne reçoit plus rien.
        $channels = $values['channels'] ?? [];
        $channels = is_array($channels) ? $channels : explode(',', (string) $channels);
        $channels = array_values(array_unique(array_filter(
            array_map(static fn ($c): string => mb_strtolower(trim((string) $c)), $channels),
            static fn (string $c): bool => in_array($c, Logs::CHANNELS, true)
        )));

        $appName = trim((string) ($values['appName'] ?? 'toutadmin'));

        return ['ok' => true, 'values' => [
            'kind' => $kind,
            'label' => $label,
            'transport' => $transport,
            'format' => $format,
            'facility' => (int) $facility,
            'host' => mb_substr(trim((string) ($values['host'] ?? '')), 0, 200),
            'port' => self::port($values['port'] ?? null) ?? 514,
            'app_name' => mb_substr(preg_replace('/\s+/', '-', $appName === '' ? 'toutadmin' : $appName) ?? 'toutadmin', 0, 48),
            'min_level' => Logs::levelOf($values['minLevel'] ?? 6),
            'channels' => implode(',', $channels),
            'path' => mb_substr(trim((string) ($values['path'] ?? '')), 0, 300),
            'allow_self_signed' => !empty($values['allowSelfSigned']) ? 1 : 0,
            'enabled' => !empty($values['enabled']) ? 1 : 0,
        ]];
    }

    public static function create(array $values): array
    {
        $verdict = self::check($values);
        if (!$verdict['ok']) {
            return $verdict;
        }
        $v = $verdict['values'];

        // Une cible nouvelle part du présent : elle ne déverse pas six mois
        // d'historique chez le collecteur au premier passage.
        $from = (int) Db::value('SELECT COALESCE(MAX(id), 0) FROM logs');

        $id = Db::insert(
            'INSERT INTO log_targets (kind, label, enabled, transport, host, port, format, facility,
                                      app_name, min_level, channels, path, allow_self_signed, cursor)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $v['kind'], $v['label'], $v['enabled'], $v['transport'], $v['host'], $v['port'], $v['format'],
                $v['facility'], $v['app_name'], $v['min_level'], $v['channels'], $v['path'],
                $v['allow_self_signed'], $from,
            ]
        );

        return ['ok' => true, 'id' => $id];
    }

    public static function update(int $id, array $values): array
    {
        if (self::byId($id) === null) {
            return ['ok' => false, 'message' => 'Cible introuvable.'];
        }
        $verdict = self::check($values);
        if (!$verdict['ok']) {
            return $verdict;
        }
        $v = $verdict['values'];

        Db::run(
            'UPDATE log_targets SET kind = ?, label = ?, enabled = ?, transport = ?, host = ?, port = ?,
                    format = ?, facility = ?, app_name = ?, min_level = ?, channels = ?, path = ?,
                    allow_self_signed = ?, failures = CASE WHEN ? = 1 THEN 0 ELSE failures END
             WHERE id = ?',
            [
                $v['kind'], $v['label'], $v['enabled'], $v['transport'], $v['host'], $v['port'], $v['format'],
                $v['facility'], $v['app_name'], $v['min_level'], $v['channels'], $v['path'],
                $v['allow_self_signed'], $v['enabled'], $id,
            ]
        );

        return ['ok' => true];
    }

    public static function remove(int $id): int
    {
        return Db::run('DELETE FROM log_targets WHERE id = ?', [$id]);
    }

    public static function setEnabled(int $id, bool $enabled): void
    {
        Db::run(
            'UPDATE log_targets SET enabled = ?, failures = CASE WHEN ? = 1 THEN 0 ELSE failures END WHERE id = ?',
            [$enabled ? 1 : 0, $enabled ? 1 : 0, $id]
        );
    }

    /**
     * Repart du présent : la cible oublie ce qu'elle n'a pas envoyé.
     *
     * Utile quand un collecteur est resté coupé une semaine et qu'on ne veut
     * pas lui déverser la semaine entière d'un coup.
     */
    public static function skipBacklog(int $id): int
    {
        $last = (int) Db::value('SELECT COALESCE(MAX(id), 0) FROM logs');
        Db::run('UPDATE log_targets SET cursor = ? WHERE id = ?', [$last, $id]);
        return $last;
    }

    /* --------------------------------------------------------------- trames */

    public static function pri(int $facility, mixed $severity): int
    {
        return $facility * 8 + Logs::levelOf($severity);
    }

    /** L'horodatage de la RFC 5424 : date complète, millisecondes, décalage. */
    public static function rfc5424Time(?string $value): string
    {
        $time = strtotime((string) $value . ' UTC');
        return gmdate('Y-m-d\TH:i:s', $time === false ? time() : $time) . '.000Z';
    }

    /** Celui de la RFC 3164 : trois lettres, un jour cadré, une heure. Rien de plus. */
    public static function rfc3164Time(?string $value): string
    {
        $time = strtotime((string) $value . ' UTC');
        $when = $time === false ? time() : $time;
        return gmdate('M', $when) . ' ' . str_pad(gmdate('j', $when), 2, ' ', STR_PAD_LEFT) . ' ' . gmdate('H:i:s', $when);
    }

    /** Échappement des données structurées : la norme n'en demande que trois. */
    private static function sdEscape(mixed $value): string
    {
        return preg_replace('/([\]"\\\\])/', '\\\\$1', (string) ($value ?? '')) ?? '';
    }

    /** Ce que le collecteur reçoit, quel que soit le format : les mêmes faits. */
    public static function payload(array $row): array
    {
        $context = null;
        if (($row['context'] ?? '') !== '') {
            $decoded = json_decode((string) $row['context'], true);
            $context = $decoded === null ? ['texte' => $row['context']] : $decoded;
        }

        return [
            'timestamp' => self::rfc5424Time($row['occurred_at'] ?? null),
            'severity' => Logs::levelOf((int) $row['level']),
            'level' => Logs::levelKeyword((int) $row['level']),
            'channel' => $row['channel'],
            'event' => $row['event'],
            'message' => $row['message'],
            'actor' => (string) ($row['actor_label'] ?? ''),
            'actor_id' => isset($row['actor_id']) && $row['actor_id'] !== null ? (int) $row['actor_id'] : null,
            'ip' => (string) ($row['ip'] ?? ''),
            'request_id' => (string) ($row['request_id'] ?? ''),
            'id' => (int) $row['id'],
            'context' => $context,
        ];
    }

    /**
     * Une ligne, dans le format de la cible.
     *
     * Le JSON n'est pas un format syslog : c'est celui qu'un agent Wazuh lit
     * dans un fichier, et celui qu'on met dans le message d'une trame 5424
     * quand le gestionnaire Wazuh écoute en syslog — son décodeur sait le lire,
     * et on garde l'en-tête normalisé par-dessus.
     */
    public static function frame(array $target, array $row, ?string $host = null): string
    {
        $data = self::payload($row);
        $app = (string) ($target['app_name'] ?? 'toutadmin');
        $machine = mb_substr(explode(' ', (string) ($host ?? gethostname()))[0], 0, 255) ?: '-';
        $json = static fn (array $extra): string => (string) json_encode($data + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($target['format'] === 'json') {
            return $json(['app' => $app, 'host' => $machine]);
        }

        if ($target['format'] === 'rfc3164') {
            $text = trim((string) preg_replace('/[\r\n]+/', ' ',
                $data['channel'] . '/' . ($data['event'] !== '' ? $data['event'] : '-') . ' ' . $data['message']));
            return '<' . self::pri((int) $target['facility'], (int) $row['level']) . '>'
                . self::rfc3164Time($row['occurred_at'] ?? null) . " $machine $app: $text";
        }

        $sd = implode(' ', [
            'channel="' . self::sdEscape($data['channel']) . '"',
            'event="' . self::sdEscape($data['event']) . '"',
            'actor="' . self::sdEscape($data['actor']) . '"',
            'ip="' . self::sdEscape($data['ip']) . '"',
            'entry="' . $data['id'] . '"',
        ]);

        $message = $target['kind'] === 'wazuh'
            // Le décodeur de Wazuh lit le JSON qui suit l'en-tête : on lui donne
            // les champs entiers plutôt qu'une phrase dans laquelle il faudrait
            // piocher.
            ? $json(['app' => $app, 'host' => $machine])
            : (string) preg_replace('/[\r\n]+/', ' ', (string) $data['message']);

        return '<' . self::pri((int) $target['facility'], (int) $row['level']) . '>1 '
            . $data['timestamp'] . " $machine $app - "
            . ($data['event'] !== '' ? $data['event'] : '-')
            . ' [toutadmin@' . self::ENTERPRISE_ID . " $sd] $message";
    }

    /* ----------------------------------------------------------- transports */

    /** Envoie un lot. Ne lève pas : rend le verdict, comme le reste du produit. */
    public static function send(array $target, array $lines): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($target, $lines);
        }
        if ($lines === []) {
            return ['ok' => true, 'sent' => 0];
        }

        try {
            if ($target['transport'] === 'fichier') {
                return self::sendFile($target, $lines);
            }
            if ($target['transport'] === 'udp') {
                return self::sendDatagrams($target, $lines);
            }
            return self::sendStream($target, $lines);
        } catch (\Throwable $error) {
            return ['ok' => false, 'sent' => 0, 'error' => $error->getMessage()];
        }
    }

    private static function sendFile(array $target, array $lines): array
    {
        $directory = dirname((string) $target['path']);
        if (!is_dir($directory) && !@mkdir($directory, 0o750, true) && !is_dir($directory)) {
            return ['ok' => false, 'sent' => 0, 'error' => 'dossier du dépôt inaccessible'];
        }
        $written = @file_put_contents((string) $target['path'], implode("\n", $lines) . "\n", FILE_APPEND | LOCK_EX);
        if ($written === false) {
            return ['ok' => false, 'sent' => 0, 'error' => 'écriture impossible dans le dépôt'];
        }
        // Le dépôt n'est lisible que par l'agent et le serveur : un journal
        // contient assez pour qu'on ne le laisse pas ouvert à tous.
        @chmod((string) $target['path'], 0o640);
        return ['ok' => true, 'sent' => count($lines)];
    }

    private static function sendDatagrams(array $target, array $lines): array
    {
        $socket = @stream_socket_client(
            'udp://' . $target['host'] . ':' . $target['port'],
            $code,
            $error,
            self::TIMEOUT,
            STREAM_CLIENT_CONNECT
        );
        if ($socket === false) {
            return ['ok' => false, 'sent' => 0, 'error' => $error !== '' ? $error : 'collecteur injoignable'];
        }

        $sent = 0;
        foreach ($lines as $line) {
            // Un datagramme trop gros est jeté en route sans prévenir : on coupe
            // à une taille qu'aucun collecteur ne refuse.
            if (@fwrite($socket, substr((string) $line, 0, 8000)) === false) {
                fclose($socket);
                return ['ok' => false, 'sent' => $sent, 'error' => 'datagramme refusé'];
            }
            $sent++;
        }
        fclose($socket);
        return ['ok' => true, 'sent' => $sent];
    }

    private static function sendStream(array $target, array $lines): array
    {
        $context = stream_context_create($target['transport'] === 'tls' ? ['ssl' => [
            'verify_peer' => empty($target['allow_self_signed']),
            'verify_peer_name' => empty($target['allow_self_signed']),
            'allow_self_signed' => !empty($target['allow_self_signed']),
        ]] : []);

        $scheme = $target['transport'] === 'tls' ? 'tls' : 'tcp';
        $socket = @stream_socket_client(
            $scheme . '://' . $target['host'] . ':' . $target['port'],
            $code,
            $error,
            self::TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            return ['ok' => false, 'sent' => 0, 'error' => $error !== '' && $error !== null ? $error : 'collecteur injoignable'];
        }

        stream_set_timeout($socket, self::TIMEOUT);
        // Trame par saut de ligne : c'est ce qu'attendent rsyslog, syslog-ng et
        // le gestionnaire Wazuh sur un flux TCP.
        $written = @fwrite($socket, implode("\n", $lines) . "\n");
        fclose($socket);

        return $written === false
            ? ['ok' => false, 'sent' => 0, 'error' => 'flux interrompu']
            : ['ok' => true, 'sent' => count($lines)];
    }

    /* -------------------------------------------------------------- passage */

    /** Les lignes qu'une cible n'a pas encore reçues, dans l'ordre où elles sont nées. */
    public static function backlog(array $target, int $limit = self::BATCH): array
    {
        $clauses = ['id > ?', 'level <= ?'];
        $params = [(int) $target['cursor'], (int) $target['min_level']];

        $channels = array_filter(explode(',', (string) ($target['channels'] ?? '')));
        if ($channels !== []) {
            $clauses[] = 'channel IN (' . implode(', ', array_fill(0, count($channels), '?')) . ')';
            array_push($params, ...$channels);
        }

        return Db::all(
            'SELECT * FROM logs WHERE ' . implode(' AND ', $clauses) . ' ORDER BY id LIMIT ?',
            [...$params, $limit]
        );
    }

    private static function recordOutcome(array $target, array $result, ?int $moved = null): void
    {
        if ($result['ok']) {
            Db::run(
                "UPDATE log_targets SET cursor = ?, sent = sent + ?, failures = 0, status = ?, last_at = ? WHERE id = ?",
                [
                    $moved ?? (int) $target['cursor'],
                    (int) ($result['sent'] ?? 0),
                    mb_substr((string) ($result['message'] ?? ((int) ($result['sent'] ?? 0)) . ' ligne(s) envoyée(s).'), 0, 300),
                    gmdate('Y-m-d H:i:s'),
                    (int) $target['id'],
                ]
            );
            return;
        }

        $failures = (int) (self::byId((int) $target['id'])['failures'] ?? $target['failures']) + 1;
        Db::run(
            'UPDATE log_targets SET failures = ?, status = ?, last_at = ? WHERE id = ?',
            [$failures, mb_substr((string) ($result['error'] ?? 'échec'), 0, 300), gmdate('Y-m-d H:i:s'), (int) $target['id']]
        );

        if ($failures >= self::FAILURE_LIMIT) {
            Db::run('UPDATE log_targets SET enabled = 0 WHERE id = ?', [(int) $target['id']]);
            Logs::warning('integration', 'journal.export.eteint',
                'Export du journal éteint après ' . self::FAILURE_LIMIT . ' échecs : ' . $target['label'] . '.',
                ['context' => ['cible' => $target['label'], 'erreur' => mb_substr((string) ($result['error'] ?? ''), 0, 200)]]);
        }
    }

    /** Sert une cible, une fois. Rend le compte de ce qui est parti. */
    public static function flushTarget(array $target, int $limit = self::BATCH): array
    {
        $rows = self::backlog($target, $limit);
        if ($rows === []) {
            Db::run('UPDATE log_targets SET status = ?, last_at = ? WHERE id = ?',
                ['À jour.', gmdate('Y-m-d H:i:s'), (int) $target['id']]);
            return ['ok' => true, 'sent' => 0, 'target' => (int) $target['id']];
        }

        $lines = array_map(static fn (array $row): string => self::frame($target, $row), $rows);
        $result = self::send($target, $lines);
        self::recordOutcome($target, $result, $result['ok'] ? (int) $rows[count($rows) - 1]['id'] : null);

        return $result + ['target' => (int) $target['id'], 'read' => count($rows)];
    }

    /**
     * Sert toutes les cibles allumées. Appelée par la tâche planifiée.
     *
     * Une cible en panne n'emporte pas les autres, et son propre filigrane
     * n'avance pas : ce qu'elle n'a pas reçu repart au passage suivant.
     */
    public static function flush(int $limit = self::BATCH): array
    {
        $sent = 0;
        $failed = 0;
        $served = array_values(array_filter(self::all(), static fn (array $t): bool => (int) $t['enabled'] === 1));

        foreach ($served as $target) {
            $result = self::flushTarget($target, $limit);
            if ($result['ok']) {
                $sent += (int) ($result['sent'] ?? 0);
            } else {
                $failed++;
            }
        }

        return ['targets' => count($served), 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * Essai : envoie une ligne qui dit ce qu'elle est, sans toucher au
     * filigrane.
     *
     * C'est le seul moyen de savoir qu'un collecteur répond avant d'attendre le
     * premier incident pour s'en apercevoir.
     */
    public static function test(int $id): array
    {
        $target = self::byId($id);
        if ($target === null) {
            return ['ok' => false, 'message' => 'Cible introuvable.'];
        }

        $line = self::frame($target, [
            'id' => 0,
            'occurred_at' => gmdate('Y-m-d H:i:s'),
            'level' => 5,
            'channel' => 'systeme',
            'event' => 'journal.export.essai',
            'message' => 'Essai d’export depuis Toutadmin vers « ' . $target['label'] . ' ».',
            'context' => (string) json_encode(['essai' => true]),
            'actor_label' => 'système',
            'actor_id' => null,
            'ip' => '',
            'request_id' => '',
        ]);

        $result = self::send($target, [$line]);
        Db::run('UPDATE log_targets SET status = ?, last_at = ? WHERE id = ?', [
            $result['ok'] ? 'Essai reçu par le collecteur.' : mb_substr('Essai refusé : ' . ($result['error'] ?? ''), 0, 300),
            gmdate('Y-m-d H:i:s'),
            $id,
        ]);

        return $result['ok']
            ? ['ok' => true, 'message' => 'Ligne d’essai envoyée.', 'line' => $line]
            : ['ok' => false, 'message' => 'Envoi impossible : ' . ($result['error'] ?? ''), 'line' => $line];
    }

    /** Ce que l'écran affiche en tête : l'état de l'export, en une ligne par cible. */
    public static function summary(): array
    {
        $rows = self::all();
        $last = (int) Db::value('SELECT COALESCE(MAX(id), 0) FROM logs');
        $pending = 0;
        foreach ($rows as $target) {
            if ((int) $target['enabled'] === 1) {
                $pending = max($pending, $last - (int) $target['cursor']);
            }
        }

        return [
            'targets' => count($rows),
            'enabled' => count(array_filter($rows, static fn (array $t): bool => (int) $t['enabled'] === 1)),
            'pending' => $pending,
            'failing' => count(array_filter($rows, static fn (array $t): bool => (int) $t['failures'] > 0)),
        ];
    }
}
