<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;
use App\Core\Session;

/**
 * Centre de journaux.
 *
 * Le journal d'audit répond à « qui a fait quoi » : il est scellé, il se garde
 * des années, et il ne contient que des actes. Celui-ci répond à une autre
 * question — « que s'est-il passé » — et c'est pour cela qu'il est séparé :
 *
 *   — il porte des **niveaux**, ceux de la norme syslog (RFC 5424, de 0 à 7).
 *     Un journal sans niveaux oblige à tout lire ou à ne rien lire ;
 *   — il porte des **canaux** : l'authentification, la sécurité, les données,
 *     les intégrations, le système. On ne cherche pas une erreur de webhook au
 *     milieu des connexions ;
 *   — il se **purge** tout seul, par niveau : une ligne de débogage ne vaut pas
 *     la peine d'être gardée aussi longtemps qu'une alerte de sécurité ;
 *   — il se **recopie ailleurs** (voir LogForward), parce qu'un journal qui ne
 *     vit que dans la base qu'il surveille ne prouve rien le jour où quelqu'un
 *     a la main sur cette base.
 *
 * Ce qu'il ne fait pas : remplacer le journal d'audit. Les actes continuent d'y
 * aller, scellés. Les actions d'administration sont recopiées ici en plus, pour
 * qu'une recherche unique retrouve tout — mais la preuve reste là-bas.
 */
final class Logs
{
    /** Les huit sévérités de la RFC 5424, dans l'ordre de la norme. */
    public const LEVELS = [
        ['value' => 0, 'key' => 'urgence', 'keyword' => 'emerg'],
        ['value' => 1, 'key' => 'alerte', 'keyword' => 'alert'],
        ['value' => 2, 'key' => 'critique', 'keyword' => 'crit'],
        ['value' => 3, 'key' => 'erreur', 'keyword' => 'err'],
        ['value' => 4, 'key' => 'avertissement', 'keyword' => 'warning'],
        ['value' => 5, 'key' => 'remarquable', 'keyword' => 'notice'],
        ['value' => 6, 'key' => 'information', 'keyword' => 'info'],
        ['value' => 7, 'key' => 'debogage', 'keyword' => 'debug'],
    ];

    /**
     * Les canaux. Un canal dit de quoi parle la ligne, pas d'où elle vient :
     * c'est ce qu'on cherche quand on ouvre un journal.
     */
    public const CHANNELS = [
        'authentification',   // connexions, verrouillages, double authentification
        'securite',           // plafonds, jetons refusés, accès interdits
        'administration',     // ce que fait l'administration, en écho du journal d'audit
        'donnees',            // imports, exports, effacements, sauvegardes
        'integration',        // webhooks, messagerie, API, plateformes
        'planification',      // balayage périodique et ce qu'il déclenche
        'systeme',            // démarrage, migrations, erreurs non rattrapées
    ];

    public const DEFAULT_LEVEL = 6;
    public const ENTRIES_PER_PAGE = 60;

    private const MAX_MESSAGE = 500;
    private const MAX_CONTEXT = 4000;

    /**
     * Rétention par niveau, en jours. Une ligne de débogage n'a pas à vivre
     * aussi longtemps qu'une alerte : la garder coûte de la place et noie la
     * recherche.
     */
    public const RETENTION = ['critical' => 365, 'warning' => 180, 'info' => 90, 'debug' => 7];

    /** Ce qui ne doit jamais entrer dans un journal, même par mégarde. */
    private const SECRET_KEYS = '/^(password|mot_de_passe|secret|token|jeton|authorization|cookie|totp|otp|api_key|cle)$/i';

    public static function levelOf(mixed $level): int
    {
        if (is_int($level) || is_float($level) || (is_string($level) && is_numeric($level))) {
            return max(0, min(7, (int) $level));
        }
        foreach (self::LEVELS as $band) {
            if ($band['key'] === mb_strtolower((string) $level)) {
                return $band['value'];
            }
        }
        return self::DEFAULT_LEVEL;
    }

    public static function levelKey(mixed $level): string
    {
        return self::LEVELS[self::levelOf($level)]['key'];
    }

    public static function levelKeyword(mixed $level): string
    {
        return self::LEVELS[self::levelOf($level)]['keyword'];
    }

    public static function channelOf(?string $channel): string
    {
        $name = mb_strtolower((string) $channel);
        return in_array($name, self::CHANNELS, true) ? $name : 'systeme';
    }

    /**
     * Nettoie le contexte avant écriture.
     *
     * Un journal se lit, s'exporte et part chez un collecteur : ce qui y entre
     * en sort. Une clé qui ressemble à un secret est donc remplacée par une
     * mention, et non écrite — même si l'appelant s'est trompé.
     */
    public static function scrub(mixed $context, int $depth = 0): mixed
    {
        if ($context === null) {
            return null;
        }
        if ($depth > 4) {
            return '…';
        }
        if (!is_array($context)) {
            return $context;
        }

        $out = [];
        $seen = 0;
        foreach ($context as $key => $value) {
            if ($seen++ >= 50) {
                break;
            }
            $out[$key] = is_string($key) && preg_match(self::SECRET_KEYS, $key)
                ? '[secret retiré]'
                : self::scrub($value, $depth + 1);
        }
        return $out;
    }

    /**
     * Écrit une ligne. Ne lève jamais : un journal qui fait tomber ce qu'il
     * observe est pire que pas de journal du tout.
     *
     * « actorId » et « actorLabel » existent pour un seul cas : l'emprunt
     * d'identité, où l'auteur responsable n'est pas celui qui est en session. Le
     * journal d'audit sait déjà nommer les deux ; sans ces deux clés, le centre
     * de journaux attribuerait l'acte à la personne empruntée, et les deux
     * journaux ne diraient pas la même chose.
     */
    public static function record(array $entry): ?int
    {
        try {
            $user = $entry['actor'] ?? Session::get('user');
            $cleaned = self::scrub($entry['context'] ?? null);

            return Db::insert(
                'INSERT INTO logs (occurred_at, level, channel, event, message, context, actor_id, actor_label, ip, request_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    gmdate('Y-m-d H:i:s'),
                    self::levelOf($entry['level'] ?? self::DEFAULT_LEVEL),
                    self::channelOf($entry['channel'] ?? 'systeme'),
                    mb_substr((string) ($entry['event'] ?? ''), 0, 120),
                    mb_substr((string) ($entry['message'] ?? ''), 0, self::MAX_MESSAGE),
                    $cleaned === null ? '' : mb_substr((string) json_encode($cleaned, JSON_UNESCAPED_UNICODE), 0, self::MAX_CONTEXT),
                    $entry['actorId'] ?? (is_array($user) ? (int) $user['id'] : null),
                    ($entry['actorLabel'] ?? '') !== '' ? (string) $entry['actorLabel'] : self::label($user),
                    mb_substr((string) ($entry['ip'] ?? self::ip()), 0, 64),
                    mb_substr((string) ($entry['requestId'] ?? ''), 0, 40),
                ]
            );
        } catch (\Throwable $error) {
            error_log('journal: ' . $error->getMessage());
            return null;
        }
    }

    // Les raccourcis qu'on écrit vraiment dans le code appelant.

    public static function emergency(string $channel, string $event, string $message, array $extra = []): ?int
    {
        return self::at(0, $channel, $event, $message, $extra);
    }

    public static function alert(string $channel, string $event, string $message, array $extra = []): ?int
    {
        return self::at(1, $channel, $event, $message, $extra);
    }

    public static function critical(string $channel, string $event, string $message, array $extra = []): ?int
    {
        return self::at(2, $channel, $event, $message, $extra);
    }

    public static function error(string $channel, string $event, string $message, array $extra = []): ?int
    {
        return self::at(3, $channel, $event, $message, $extra);
    }

    public static function warning(string $channel, string $event, string $message, array $extra = []): ?int
    {
        return self::at(4, $channel, $event, $message, $extra);
    }

    public static function notice(string $channel, string $event, string $message, array $extra = []): ?int
    {
        return self::at(5, $channel, $event, $message, $extra);
    }

    public static function info(string $channel, string $event, string $message, array $extra = []): ?int
    {
        return self::at(6, $channel, $event, $message, $extra);
    }

    public static function debug(string $channel, string $event, string $message, array $extra = []): ?int
    {
        return self::at(7, $channel, $event, $message, $extra);
    }

    private static function at(int $level, string $channel, string $event, string $message, array $extra): ?int
    {
        return self::record($extra + [
            'level' => $level, 'channel' => $channel, 'event' => $event, 'message' => $message,
        ]);
    }

    /* --------------------------------------------------------- consultation */

    private static function filters(array $options): array
    {
        $clauses = [];
        $params = [];

        if (($options['level'] ?? '') !== '' && ($options['level'] ?? null) !== null) {
            $clauses[] = 'level = ?';
            $params[] = self::levelOf($options['level']);
        }
        // « à partir de » : au sens de la norme, plus grave veut dire plus petit.
        if (($options['maxLevel'] ?? '') !== '' && ($options['maxLevel'] ?? null) !== null) {
            $clauses[] = 'level <= ?';
            $params[] = self::levelOf($options['maxLevel']);
        }
        if (!empty($options['channel'])) {
            $clauses[] = 'channel = ?';
            $params[] = self::channelOf($options['channel']);
        }
        if (!empty($options['event'])) {
            $clauses[] = 'event LIKE ?';
            $params[] = $options['event'] . '%';
        }
        if (!empty($options['actorId'])) {
            $clauses[] = 'actor_id = ?';
            $params[] = (int) $options['actorId'];
        }
        if (!empty($options['from'])) {
            $clauses[] = 'occurred_at >= ?';
            $params[] = $options['from'];
        }
        if (!empty($options['to'])) {
            $clauses[] = 'occurred_at <= ?';
            $params[] = $options['to'] . ' 23:59:59';
        }
        if (!empty($options['search'])) {
            $clauses[] = '(message LIKE ? OR context LIKE ? OR event LIKE ? OR actor_label LIKE ? OR ip LIKE ?)';
            $like = '%' . $options['search'] . '%';
            array_push($params, $like, $like, $like, $like, $like);
        }

        return ['where' => $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), 'params' => $params];
    }

    public static function list(array $options = []): array
    {
        ['where' => $where, 'params' => $params] = self::filters($options);

        $total = (int) Db::value("SELECT COUNT(*) FROM logs $where", $params);
        $pages = max(1, (int) ceil($total / self::ENTRIES_PER_PAGE));
        $page = min(max(1, (int) ($options['page'] ?? 1)), $pages);

        $rows = Db::all(
            "SELECT * FROM logs $where ORDER BY id DESC LIMIT ? OFFSET ?",
            [...$params, self::ENTRIES_PER_PAGE, ($page - 1) * self::ENTRIES_PER_PAGE]
        );

        return ['rows' => array_map(self::decorate(...), $rows), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    public static function decorate(array $row): array
    {
        $context = null;
        if (($row['context'] ?? '') !== '') {
            $decoded = json_decode((string) $row['context'], true);
            $context = $decoded === null ? $row['context'] : $decoded;
        }
        $row['levelKey'] = self::levelKey((int) $row['level']);
        $row['levelKeyword'] = self::levelKeyword((int) $row['level']);
        $row['parsedContext'] = $context;
        return $row;
    }

    public static function byId(int $id): ?array
    {
        $row = Db::get('SELECT * FROM logs WHERE id = ?', [$id]);
        return $row === null ? null : self::decorate($row);
    }

    /** De quoi dessiner l'état du journal sans parcourir des milliers de lignes. */
    public static function stats(int $days = 7): array
    {
        $days = max(1, $days);
        $since = gmdate('Y-m-d H:i:s', time() - $days * 86400);

        $byLevel = array_map(static function (array $row): array {
            $row['key'] = self::levelKey((int) $row['level']);
            return $row;
        }, Db::all('SELECT level, COUNT(*) AS n FROM logs WHERE occurred_at >= ? GROUP BY level ORDER BY level', [$since]));

        return [
            'total' => (int) Db::value('SELECT COUNT(*) FROM logs'),
            'window' => (int) Db::value('SELECT COUNT(*) FROM logs WHERE occurred_at >= ?', [$since]),
            'serious' => (int) Db::value('SELECT COUNT(*) FROM logs WHERE level <= 3 AND occurred_at >= ?', [$since]),
            'pending' => (int) Db::value(
                'SELECT COUNT(*) FROM logs WHERE id > (SELECT COALESCE(MIN(cursor), 0) FROM log_targets WHERE enabled = 1)'
            ),
            'oldest' => Db::value('SELECT occurred_at FROM logs ORDER BY id LIMIT 1'),
            'byLevel' => $byLevel,
            'byChannel' => Db::all(
                'SELECT channel, COUNT(*) AS n FROM logs WHERE occurred_at >= ? GROUP BY channel ORDER BY n DESC',
                [$since]
            ),
            'byDay' => Db::all(
                'SELECT substr(occurred_at, 1, 10) AS day, COUNT(*) AS n,
                        SUM(CASE WHEN level <= 3 THEN 1 ELSE 0 END) AS graves
                 FROM logs WHERE occurred_at >= ? GROUP BY day ORDER BY day',
                [$since]
            ),
            'days' => $days,
        ];
    }

    /** Les événements déjà rencontrés, pour alimenter le filtre sans les coder en dur. */
    public static function knownEvents(int $limit = 200): array
    {
        return array_column(
            Db::all("SELECT DISTINCT event FROM logs WHERE event != '' ORDER BY event LIMIT ?", [$limit]),
            'event'
        );
    }

    public static function toCsv(array $rows): string
    {
        $escape = static fn (mixed $value): string => '"' . str_replace('"', '""', (string) ($value ?? '')) . '"';
        $lines = [implode(';', array_map($escape, ['Date', 'Niveau', 'Canal', 'Événement', 'Message', 'Contexte', 'Auteur', 'IP']))];
        foreach ($rows as $row) {
            $lines[] = implode(';', array_map($escape, [
                $row['occurred_at'], self::levelKey((int) $row['level']), $row['channel'], $row['event'],
                $row['message'], $row['context'], $row['actor_label'], $row['ip'],
            ]));
        }
        return implode("\n", $lines);
    }

    /**
     * La plus haute ligne qu'on ait le droit d'effacer.
     *
     * Une cible active qui n'a pas encore reçu une ligne la recevra au prochain
     * passage : la purge ne doit pas la lui prendre entre-temps. S'il n'y a
     * aucune cible active, rien ne retient la purge.
     */
    public static function purgeCeiling(): int
    {
        $floor = Db::value('SELECT MIN(cursor) FROM log_targets WHERE enabled = 1');
        return $floor === null ? PHP_INT_MAX : (int) $floor;
    }

    /**
     * Purge par niveau. Rend le détail plutôt qu'un total : « 4 000 lignes
     * supprimées » ne dit pas si ce sont des traces de débogage ou des alertes.
     *
     * Une ligne qu'une cible active n'a pas encore reçue n'est jamais purgée :
     * la purge ne doit pas faire disparaître ce que l'export n'a pas encore vu.
     */
    public static function purge(array $retention = self::RETENTION): array
    {
        $ceiling = self::purgeCeiling();
        $bands = [
            ['debug', 'level = 7', $retention['debug'] ?? self::RETENTION['debug']],
            ['info', 'level IN (5, 6)', $retention['info'] ?? self::RETENTION['info']],
            ['warning', 'level = 4', $retention['warning'] ?? self::RETENTION['warning']],
            ['critical', 'level <= 3', $retention['critical'] ?? self::RETENTION['critical']],
        ];

        $removed = [];
        foreach ($bands as [$name, $clause, $days]) {
            $keep = max(1, (int) $days);
            $cutoff = gmdate('Y-m-d H:i:s', time() - $keep * 86400);
            $removed[$name] = Db::run(
                "DELETE FROM logs WHERE $clause AND occurred_at < ? AND id <= ?",
                [$cutoff, $ceiling]
            );
        }

        $total = array_sum($removed);
        if ($total > 0) {
            self::record([
                'level' => 5, 'channel' => 'systeme', 'event' => 'journal.purge',
                'message' => "$total ligne(s) de journal purgée(s).", 'context' => $removed,
            ]);
        }
        return $removed + ['total' => $total];
    }

    /** Vide le journal. Réservé à l'administration, et consigné comme tel. */
    public static function clear(): int
    {
        $removed = Db::run('DELETE FROM logs');
        self::record([
            'level' => 4, 'channel' => 'administration', 'event' => 'journal.vide',
            'message' => "Journal vidé : $removed ligne(s) supprimée(s).",
        ]);
        return $removed;
    }

    private static function label(mixed $user): string
    {
        if (!is_array($user)) {
            return 'système';
        }
        $name = trim(($user['firstName'] ?? $user['first_name'] ?? '') . ' ' . ($user['lastName'] ?? $user['last_name'] ?? ''));
        $email = (string) ($user['email'] ?? '');
        $label = $name !== '' ? "$name <$email>" : ($email !== '' ? $email : '#' . ($user['id'] ?? '?'));
        return mb_substr($label, 0, 200);
    }

    private static function ip(): string
    {
        if (\App\Core\Config::get('trust_proxy') && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return substr(trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]), 0, 64);
        }
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    }
}
