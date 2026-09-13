<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Recherche globale.
 *
 * Une seule barre pour tout le CMS, mais jamais au prix du cloisonnement : la
 * recherche interroge chaque source avec les droits de la personne, et une
 * source qu'elle n'a pas le droit de voir n'est pas interrogée du tout. Rien
 * n'est filtré après coup — ce qui fuit ne se rattrape pas à l'affichage.
 */
final class Search
{
    private const MAX_PER_SOURCE = 8;

    private static function like(string $query): string
    {
        return '%' . trim($query) . '%';
    }

    private static function can(?array $user, string $right): bool
    {
        if ($user === null) {
            return false;
        }
        if ($user['role'] === 'admin') {
            return true;
        }
        return match ($right) {
            'hr' => (int) ($user['is_hr'] ?? 0) === 1,
            'finance' => (int) ($user['is_finance'] ?? 0) === 1,
            'it' => (int) ($user['is_it'] ?? 0) === 1,
            default => false,
        };
    }

    /**
     * @param string $query ce que la personne a tapé
     * @param array  $user  la ligne utilisateur, avec ses droits
     */
    public static function search(string $query, array $user): array
    {
        $trimmed = trim($query);
        if (mb_strlen($trimmed) < 2) {
            return [];
        }

        $pattern = self::like($trimmed);
        $limit = self::MAX_PER_SOURCE;
        $groups = [];
        $add = static function (string $source, array $rows) use (&$groups, $limit): void {
            if ($rows !== []) {
                $groups[] = ['source' => $source, 'rows' => array_slice($rows, 0, $limit)];
            }
        };

        // --- Annuaire : ouvert à tous, sauf les membres masqués par l'administration.
        $add('Annuaire', Db::all(
            "SELECT id, first_name || ' ' || last_name AS label, COALESCE(NULLIF(grade, ''), email) AS detail,
                    '/annuaire' AS link
             FROM users WHERE active = 1 AND directory_hidden = 0
               AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR grade LIKE ?)
             ORDER BY last_name COLLATE NOCASE LIMIT ?",
            [$pattern, $pattern, $pattern, $pattern, $limit]
        ));

        // --- Base de connaissances : la portée de chaque article est appliquée ici.
        $articles = Db::all(
            'SELECT id, title AS label, category AS detail, visibility, scope_id
             FROM kb_articles WHERE published = 1 AND (title LIKE ? OR body LIKE ? OR category LIKE ?)
             ORDER BY title COLLATE NOCASE LIMIT 40',
            [$pattern, $pattern, $pattern]
        );
        $readable = [];
        foreach ($articles as $article) {
            if (Support::canRead($article, $user)) {
                $article['link'] = '/base-de-connaissances/' . (int) $article['id'];
                $readable[] = $article;
            }
        }
        $add('Connaissances', $readable);

        // --- Projets : ceux dont la personne est membre, sauf droits de pilotage.
        $steersProjects = self::can($user, 'finance') || Org::isManager((int) $user['id']);
        $projects = $steersProjects
            ? Db::all(
                'SELECT id, name AS label, status AS detail FROM projects
                 WHERE archived = 0 AND (name LIKE ? OR code LIKE ? OR description LIKE ?)
                 ORDER BY name COLLATE NOCASE LIMIT ?',
                [$pattern, $pattern, $pattern, $limit]
            )
            : Db::all(
                'SELECT DISTINCT p.id, p.name AS label, p.status AS detail FROM projects p
                 LEFT JOIN project_members pm ON pm.project_id = p.id
                 WHERE p.archived = 0 AND (pm.user_id = ? OR p.lead_id = ?)
                   AND (p.name LIKE ? OR p.code LIKE ? OR p.description LIKE ?)
                 ORDER BY p.name COLLATE NOCASE LIMIT ?',
                [(int) $user['id'], (int) $user['id'], $pattern, $pattern, $pattern, $limit]
            );
        $add('Projets', array_map(static function (array $row): array {
            $row['link'] = '/projets/' . (int) $row['id'];
            return $row;
        }, $projects));

        // --- Tickets : les siens, ou tous pour les équipes support.
        $isAgent = self::can($user, 'hr') || self::can($user, 'finance') || Org::isManager((int) $user['id']);
        $tickets = $isAgent
            ? Db::all(
                "SELECT id, subject AS label, reference || ' · ' || status AS detail FROM tickets
                 WHERE subject LIKE ? OR body LIKE ? OR reference LIKE ? ORDER BY id DESC LIMIT ?",
                [$pattern, $pattern, $pattern, $limit]
            )
            : Db::all(
                "SELECT id, subject AS label, reference || ' · ' || status AS detail FROM tickets
                 WHERE requester_id = ? AND (subject LIKE ? OR body LIKE ? OR reference LIKE ?)
                 ORDER BY id DESC LIMIT ?",
                [(int) $user['id'], $pattern, $pattern, $pattern, $limit]
            );
        $add('Tickets', array_map(static function (array $row): array {
            $row['link'] = '/support/tickets/' . (int) $row['id'];
            return $row;
        }, $tickets));

        // --- Gestion : tiers, contrats et factures, réservés à la gestion.
        if (self::can($user, 'finance')) {
            $add('Tiers', Db::all(
                "SELECT id, name AS label, kind AS detail, '/gestion#tiers' AS link FROM partners
                 WHERE name LIKE ? OR email LIKE ? ORDER BY name COLLATE NOCASE LIMIT ?",
                [$pattern, $pattern, $limit]
            ));

            $add('Factures', Db::all(
                "SELECT id, COALESCE(NULLIF(reference, ''), label) AS label,
                        direction || ' · ' || status AS detail, '/gestion#factures' AS link
                 FROM invoices WHERE reference LIKE ? OR label LIKE ? ORDER BY issue_date DESC LIMIT ?",
                [$pattern, $pattern, $limit]
            ));

            $add('Véhicules', array_map(static function (array $row): array {
                $row['link'] = '/flotte/' . (int) $row['id'];
                return $row;
            }, Db::all(
                "SELECT id, registration AS label, brand || ' ' || model AS detail FROM vehicles
                 WHERE registration LIKE ? OR brand LIKE ? OR model LIKE ? ORDER BY registration LIMIT ?",
                [$pattern, $pattern, $pattern, $limit]
            )));
        }

        // --- Événements ouverts : chacun cherche ceux auxquels il peut s'inscrire.
        //     Un brouillon n'existe pas encore pour l'entreprise, il reste hors recherche.
        $add('Événements', array_map(static function (array $row): array {
            $row['link'] = '/evenements/' . (int) $row['id'];
            return $row;
        }, Db::all(
            "SELECT id, title AS label, kind || ' · ' || substr(starts_at, 1, 10) AS detail FROM company_events
             WHERE status IN ('Ouvert','Complet','Clos') AND (title LIKE ? OR description LIKE ? OR location LIKE ?)
             ORDER BY starts_at DESC LIMIT ?",
            [$pattern, $pattern, $pattern, $limit]
        )));

        // --- Informatique : parc logiciel et référentiel applicatif.
        if (self::can($user, 'it')) {
            $add('Logiciels', array_map(static function (array $row): array {
                $row['link'] = '/informatique/logiciels/' . (int) $row['id'];
                return $row;
            }, Db::all(
                "SELECT id, name AS label, publisher || ' · ' || kind AS detail FROM software_licences
                 WHERE name LIKE ? OR publisher LIKE ? OR notes LIKE ? ORDER BY name COLLATE NOCASE LIMIT ?",
                [$pattern, $pattern, $pattern, $limit]
            )));

            $add('Services applicatifs', array_map(static function (array $row): array {
                $row['link'] = '/developpement/services/' . (int) $row['id'];
                return $row;
            }, Db::all(
                "SELECT id, name AS label, COALESCE(NULLIF(stack, ''), criticality) AS detail FROM app_services
                 WHERE name LIKE ? OR code LIKE ? OR description LIKE ? OR stack LIKE ?
                 ORDER BY name COLLATE NOCASE LIMIT ?",
                [$pattern, $pattern, $pattern, $pattern, $limit]
            )));
        }

        // --- Gouvernance : décisions et réunions, réservées à l'administration.
        //     Un relevé de décisions est plus confidentiel que la plupart des tables.
        if ($user['role'] === 'admin') {
            $add('Décisions', Db::all(
                "SELECT id, title AS label, decided_on || ' · ' || status AS detail, '/direction#decisions' AS link
                 FROM decisions WHERE title LIKE ? OR body LIKE ? OR rationale LIKE ?
                 ORDER BY decided_on DESC LIMIT ?",
                [$pattern, $pattern, $pattern, $limit]
            ));

            $add('Réunions', array_map(static function (array $row): array {
                $row['link'] = '/direction/reunions/' . (int) $row['id'];
                return $row;
            }, Db::all(
                "SELECT id, title AS label, kind || ' · ' || held_on AS detail FROM meetings
                 WHERE title LIKE ? OR agenda LIKE ? OR minutes LIKE ? ORDER BY held_on DESC LIMIT ?",
                [$pattern, $pattern, $pattern, $limit]
            )));

            $add('Risques', Db::all(
                "SELECT id, title AS label, category || ' · ' || status AS detail, '/direction#risques' AS link
                 FROM enterprise_risks WHERE title LIKE ? OR description LIKE ? OR reference LIKE ?
                 ORDER BY id DESC LIMIT ?",
                [$pattern, $pattern, $pattern, $limit]
            ));
        }

        // --- Qualité : ouverte à l'encadrement, comme l'écran qui la porte.
        if ($user['role'] === 'admin' || Org::isManager((int) $user['id'])) {
            $add('Qualité', Db::all(
                "SELECT id, reference || ' — ' || title AS label, severity || ' · ' || status AS detail,
                        '/qualite#non-conformites' AS link
                 FROM nonconformities WHERE title LIKE ? OR description LIKE ? OR reference LIKE ? OR subject LIKE ?
                 ORDER BY detected_on DESC LIMIT ?",
                [$pattern, $pattern, $pattern, $pattern, $limit]
            ));
        }

        // --- RH : personnel et candidatures, réservés aux RH.
        if (self::can($user, 'hr')) {
            $add('Candidatures', Db::all(
                "SELECT c.id, c.first_name || ' ' || c.last_name AS label,
                        o.title || ' · ' || c.stage AS detail, '/rh#recrutement' AS link
                 FROM candidates c JOIN job_openings o ON o.id = c.opening_id
                 WHERE c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.cv_text LIKE ?
                 ORDER BY c.id DESC LIMIT ?",
                [$pattern, $pattern, $pattern, $pattern, $limit]
            ));

            $add('Personnel', Db::all(
                "SELECT id, first_name || ' ' || last_name AS label,
                        contract_type || CASE WHEN active = 1 THEN '' ELSE ' · désactivé' END AS detail,
                        '/admin#personnel' AS link
                 FROM users WHERE role = 'employee' AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)
                 ORDER BY last_name COLLATE NOCASE LIMIT ?",
                [$pattern, $pattern, $pattern, $limit]
            ));
        }

        return $groups;
    }
}
