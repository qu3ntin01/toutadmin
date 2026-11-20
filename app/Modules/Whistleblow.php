<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Cloison;
use App\Core\Db;
use App\Core\Secrets;

/**
 * Dispositif de recueil des signalements internes.
 *
 * Obligatoire en France dès cinquante salariés depuis la loi du 21 mars 2022,
 * et impossible à bâcler : un canal d'alerte que l'on n'ose pas emprunter ne
 * sert à rien, et c'est la conception qui décide si on ose.
 *
 * Trois règles en découlent, et elles commandent tout le reste :
 *
 * 1. **L'anonymat est un droit.** Un signalement anonyme n'enregistre pas son
 *    auteur — et le journal d'audit général ne doit pas le rattraper par la
 *    bande (voir la route, qui neutralise la trace automatique).
 * 2. **Le contenu est chiffré.** Une copie de la base ne livre pas les
 *    signalements, ni ce qui s'y dit ensuite.
 * 3. **Les référents seuls y accèdent**, et pas les administrateurs en tant que
 *    tels : une alerte peut viser un administrateur. L'administration désigne
 *    les référents — ce qui, lui, est tracé — mais ne lit rien.
 *
 * Ce que cela ne protège pas : l'accès direct au serveur. La clé de chiffrement
 * dérive du secret de l'instance, donc voler la base seule ne suffit pas ; qui
 * tient la machine tient tout. C'est une limite du produit, pas un oubli.
 */
final class Whistleblow
{
    public const CATEGORIES = [
        'Corruption',
        'Fraude',
        'Harcèlement',
        'Discrimination',
        'Sécurité des personnes',
        'Environnement',
        'Données personnelles',
        'Autre',
    ];

    public const REPORT_STATUSES = ['Reçue', 'Recevable', 'Irrecevable', 'En instruction', 'Clôturée'];

    /**
     * Délais légaux : accusé de réception sous 7 jours, retour sur les suites
     * données sous 3 mois. Ce ne sont pas des conventions internes, ils se comptent.
     */
    public const ACK_DAYS = 7;
    public const OUTCOME_DAYS = 90;

    /** Alphabet sans les caractères qui se confondent : le code est recopié à la main. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const CODE_LENGTH = 20;

    /** Le périmètre courant : un référent n'instruit que les alertes de sa société. */
    private static function ici(): ?int
    {
        return Societes::courant();
    }

    public static function newCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }
        return $code;
    }

    /**
     * Le code n'est gardé que haché. Vingt caractères tirés au sort dans un
     * alphabet de trente et un font une centaine de bits : le deviner n'est pas
     * une attaque réaliste, et un simple SHA-256 suffit donc — ce n'est pas un
     * mot de passe choisi par un humain.
     */
    public static function hashCode(string $code): string
    {
        return hash('sha256', strtoupper(trim($code)));
    }

    /*
     * La référence est un identifiant d'instance, pas de société : c'est elle que
     * l'auteur recopie pour suivre son alerte, et il n'a aucun moyen de désigner
     * la société en plus. Deux sociétés ne doivent donc jamais pouvoir produire
     * la même — d'où le comptage hors périmètre, et l'unicité globale en base.
     */
    public static function nextReference(): string
    {
        $year = gmdate('Y');
        $count = (int) Cloison::sansPerimetre(static fn (): mixed => Db::value(
            'SELECT COUNT(*) FROM whistleblow_reports WHERE reference LIKE ?',
            ["ALT-$year-%"]
        ));
        return 'ALT-' . $year . '-' . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }

    // ---------- Dépôt ----------

    public static function create(array $fields): array
    {
        $reference = self::nextReference();
        $code = self::newCode();
        $anonymous = $fields['anonymous'] ?? true;

        Db::run(
            'INSERT INTO whistleblow_reports (company_id, reference, follow_code_hash, category, subject_enc, body_enc, author_id, anonymous)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                self::ici(), $reference, self::hashCode($code), $fields['category'],
                Secrets::encrypt($fields['subject']), Secrets::encrypt($fields['body'] ?? ''),
                // Un signalement anonyme n'enregistre pas son auteur. Pas « le
                // masque » : ne l'enregistre pas — un masque se retire.
                $anonymous ? null : ($fields['authorId'] ?? null),
                $anonymous ? 1 : 0,
            ]
        );

        // Le code n'est montré qu'une fois, à cet instant : il n'existe plus ailleurs.
        return ['reference' => $reference, 'code' => $code];
    }

    // ---------- Lecture ----------

    private static function decorate(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $row['subject'] = Secrets::decrypt($row['subject_enc']);
        $row['body'] = Secrets::decrypt($row['body_enc']);
        $row['outcome'] = Secrets::decrypt($row['outcome_enc']);
        return $row;
    }

    public static function byId(int $id): ?array
    {
        return self::decorate(Db::get(
            'SELECT * FROM whistleblow_reports WHERE id = ? AND company_id = ?',
            [$id, self::ici()]
        ));
    }

    public static function byReference(string $reference): ?array
    {
        return self::decorate(Db::get(
            'SELECT * FROM whistleblow_reports WHERE reference = ? AND company_id = ?',
            [strtoupper(trim($reference)), self::ici()]
        ));
    }

    /** Le suivi par l'auteur : référence et code doivent aller ensemble. */
    public static function openFollow(string $reference, string $code): ?array
    {
        /*
         * Hors périmètre, et c'est voulu : ce qui autorise ici, c'est le code, pas
         * la société. L'auteur d'une alerte anonyme n'est pas connecté — il n'a
         * qu'une référence et vingt caractères, et son alerte doit s'ouvrir quelle
         * que soit la société que la session aurait choisie par défaut.
         */
        $row = Cloison::sansPerimetre(static fn (): mixed => Db::get(
            'SELECT * FROM whistleblow_reports WHERE reference = ?',
            [strtoupper(trim($reference))]
        ));
        if ($row === null) {
            return null;
        }
        // Comparaison à durée constante : le temps de réponse ne doit pas indiquer
        // combien de caractères du code sont justes.
        if (!hash_equals((string) $row['follow_code_hash'], self::hashCode($code))) {
            return null;
        }
        return self::decorate($row);
    }

    public static function all(array $options = []): array
    {
        $clause = ($options['includeClosed'] ?? true) ? '' : "AND r.status != 'Clôturée'";
        return array_map([self::class, 'decorate'], Db::all(
            "SELECT r.*, u.first_name, u.last_name
             FROM whistleblow_reports r LEFT JOIN users u ON u.id = r.author_id
             WHERE r.company_id = ? $clause
             ORDER BY r.status = 'Clôturée', r.submitted_at DESC",
            [self::ici()]
        ));
    }

    public static function messages(int $reportId): array
    {
        return array_map(
            static function (array $row): array {
                $row['body'] = Secrets::decrypt($row['body_enc']);
                return $row;
            },
            Db::all('SELECT * FROM whistleblow_messages WHERE report_id = ? ORDER BY created_at, id', [$reportId])
        );
    }

    public static function addMessage(array $fields): bool
    {
        $text = trim((string) ($fields['body'] ?? ''));
        if ($text === '') {
            return false;
        }
        Db::run(
            'INSERT INTO whistleblow_messages (report_id, author_kind, referent_id, body_enc) VALUES (?, ?, ?, ?)',
            [
                (int) $fields['reportId'], $fields['kind'],
                $fields['kind'] === 'referent' ? ($fields['referentId'] ?? null) : null,
                Secrets::encrypt(mb_substr($text, 0, 5000)),
            ]
        );
        return true;
    }

    /** Qui a ouvert quel signalement : la trace vit ici, hors du journal général. */
    public static function noteAccess(int $reportId, int $userId): void
    {
        Db::run('INSERT INTO whistleblow_access_log (report_id, user_id) VALUES (?, ?)', [$reportId, $userId]);
    }

    public static function accessLog(int $reportId): array
    {
        return Db::all(
            'SELECT a.occurred_at, u.first_name, u.last_name
             FROM whistleblow_access_log a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.report_id = ? ORDER BY a.occurred_at DESC LIMIT 50',
            [$reportId]
        );
    }

    // ---------- Instruction ----------

    public static function acknowledge(int $id): bool
    {
        return Db::run(
            "UPDATE whistleblow_reports SET acknowledged_at = datetime('now')
             WHERE id = ? AND company_id = ? AND acknowledged_at IS NULL",
            [$id, self::ici()]
        ) > 0;
    }

    public static function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::REPORT_STATUSES, true)) {
            return false;
        }
        Db::run(
            "UPDATE whistleblow_reports
             SET status = ?, closed_at = CASE WHEN ? = 1 THEN COALESCE(closed_at, datetime('now')) ELSE NULL END
             WHERE id = ? AND company_id = ?",
            [$status, $status === 'Clôturée' ? 1 : 0, $id, self::ici()]
        );
        return true;
    }

    public static function setOutcome(int $id, string $outcome): void
    {
        Db::run(
            'UPDATE whistleblow_reports SET outcome_enc = ? WHERE id = ? AND company_id = ?',
            [Secrets::encrypt(mb_substr($outcome, 0, 4000)), $id, self::ici()]
        );
    }

    // ---------- Délais ----------

    public static function daysSince(?string $iso): ?int
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        $then = strtotime(str_replace(' ', 'T', $iso) . ' UTC');
        return $then === false ? null : (int) floor((time() - $then) / 86400);
    }

    /** Les deux délais légaux, comptés plutôt que promis. */
    public static function overdue(): array
    {
        $rows = self::all(['includeClosed' => false]);
        return [
            'acknowledgement' => array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['acknowledged_at'] === null
                    && (self::daysSince($row['submitted_at']) ?? 0) > self::ACK_DAYS
            )),
            'outcome' => array_values(array_filter(
                $rows,
                static fn (array $row): bool => (self::daysSince($row['submitted_at']) ?? 0) > self::OUTCOME_DAYS
            )),
        ];
    }

    public static function summary(): array
    {
        $late = self::overdue();
        return [
            'open' => (int) Db::value(
                "SELECT COUNT(*) FROM whistleblow_reports WHERE company_id = ? AND status != 'Clôturée'",
                [self::ici()]
            ),
            'waiting' => (int) Db::value(
                'SELECT COUNT(*) FROM whistleblow_reports WHERE company_id = ? AND acknowledged_at IS NULL',
                [self::ici()]
            ),
            'lateAck' => count($late['acknowledgement']),
            'lateOutcome' => count($late['outcome']),
            'total' => (int) Db::value(
                'SELECT COUNT(*) FROM whistleblow_reports WHERE company_id = ?',
                [self::ici()]
            ),
        ];
    }

    /** Les référents désignés : destinataires des échéances du dispositif. */
    public static function referents(): array
    {
        return Db::all('SELECT id, first_name, last_name FROM users WHERE active = 1 AND is_referent = 1');
    }
}
