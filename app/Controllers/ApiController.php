<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;
use App\Modules\ApiTokens;
use App\Modules\Billing;
use App\Modules\Currency;
use App\Modules\Finance;
use App\Modules\Org;
use App\Modules\Steering;

/**
 * API de lecture, version 1.
 *
 * Volontairement en lecture seule. Un jeton circule dans des fichiers de
 * configuration, des variables d'environnement, parfois un dépôt Git : il ne
 * doit pas pouvoir supprimer un salarié ni émettre une facture. Ce que les
 * outils tiers demandent, dans la pratique, c'est de lire.
 *
 * Pas de cookie, donc pas de session, donc pas de jeton CSRF : l'authentification
 * tient entièrement dans l'en-tête « Authorization: Bearer … ».
 */
final class ApiController
{
    private const MAX_PAGE = 200;

    private static function json(mixed $payload, int $status = 200, array $headers = []): Response
    {
        return Response::text((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status)
            ->withHeaders(array_merge([
                'Content-Type' => 'application/json; charset=utf-8',
                // Une réponse d'API ne se met pas en cache : elle porte des
                // données nominatives.
                'Cache-Control' => 'no-store',
            ], $headers));
    }

    private static function unauthorized(): Response
    {
        // On ne dit pas si le jeton est inconnu, révoqué ou périmé : cela renseignerait.
        return self::json(['error' => 'Jeton absent, invalide ou expiré.'], 401, [
            'WWW-Authenticate' => 'Bearer realm="Toutadmin"',
        ]);
    }

    /** Le jeton présenté, ou null. Chaque appel compte, et le dernier usage est daté. */
    private static function token(Request $request): ?array
    {
        $header = (string) $request->header('authorization');
        $bearer = str_starts_with(strtolower($header), 'bearer ') ? trim(substr($header, 7)) : '';
        $presented = $bearer !== '' ? $bearer : trim((string) $request->header('x-api-key'));
        if ($presented === '') {
            return null;
        }

        $token = ApiTokens::resolve($presented);
        if ($token === null) {
            return null;
        }
        ApiTokens::touch((int) $token['id'], (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        return $token;
    }

    private static function page(Request $request): array
    {
        $limit = min(self::MAX_PAGE, max(1, (int) $request->input('limite') ?: 50));
        $offset = max(0, (int) $request->input('depuis'));
        return ['limit' => $limit, 'offset' => $offset];
    }

    private static function wrap(array $rows, array $page): array
    {
        return [
            'count' => count($rows), 'limit' => $page['limit'], 'offset' => $page['offset'],
            'data' => array_values($rows),
        ];
    }

    /**
     * Point d'entrée unique : l'authentification, le plafond et la portée sont
     * vérifiés ici, une fois, avant de servir quoi que ce soit.
     */
    public static function dispatch(Request $request, array $params): Response
    {
        // Un jeton volé ne doit pas permettre d'aspirer la base en quelques secondes.
        if (Security::tooManyAttempts('api', (int) Config::get('api_rate_limit', 120), 60)) {
            return self::json(['error' => 'Trop de requêtes.'], 429);
        }

        $token = self::token($request);
        if ($token === null) {
            return self::unauthorized();
        }

        $resource = trim((string) ($params['resource'] ?? ''), '/');
        [$scope, $handler] = match ($resource) {
            '' => ['', 'root'],
            'collaborateurs' => ['annuaire', 'people'],
            'services' => ['annuaire', 'departments'],
            'equipes' => ['annuaire', 'teams'],
            'absences' => ['rh', 'leave'],
            'tiers' => ['gestion', 'partners'],
            'factures' => ['gestion', 'invoices'],
            'abonnements' => ['gestion', 'subscriptions'],
            'projets' => ['projets', 'projects'],
            'indicateurs' => ['pilotage', 'indicators'],
            // Une route inconnue rend du JSON, pas une page d'erreur HTML :
            // l'appelant est un programme.
            default => [null, null],
        };

        if ($handler === null) {
            return self::json(['error' => 'Ressource inconnue.'], 404);
        }
        if ($scope !== '' && !ApiTokens::allows($token, $scope)) {
            return self::json([
                'error' => "Ce jeton n'a pas la portée « $scope ».",
                'scopes' => $token['scopeList'],
            ], 403);
        }

        return match ($handler) {
            'root' => self::root($token),
            'people' => self::people($request),
            'departments' => self::departments(),
            'teams' => self::teams(),
            'leave' => self::leave($request),
            'partners' => self::partners($request),
            'invoices' => self::invoices($request),
            'subscriptions' => self::subscriptions(),
            'projects' => self::projects($request),
            'indicators' => self::indicators($request),
        };
    }

    // ---------- Découverte ----------

    private static function root(array $token): Response
    {
        return self::json([
            'version' => 1,
            'token' => [
                'label' => $token['label'], 'scopes' => $token['scopeList'], 'expiresAt' => $token['expires_at'],
            ],
            'readOnly' => true,
            'endpoints' => [
                ['path' => '/api/v1/collaborateurs', 'scope' => 'annuaire'],
                ['path' => '/api/v1/services', 'scope' => 'annuaire'],
                ['path' => '/api/v1/equipes', 'scope' => 'annuaire'],
                ['path' => '/api/v1/absences', 'scope' => 'rh'],
                ['path' => '/api/v1/tiers', 'scope' => 'gestion'],
                ['path' => '/api/v1/factures', 'scope' => 'gestion'],
                ['path' => '/api/v1/abonnements', 'scope' => 'gestion'],
                ['path' => '/api/v1/projets', 'scope' => 'projets'],
                ['path' => '/api/v1/indicateurs', 'scope' => 'pilotage'],
            ],
        ]);
    }

    // ---------- Annuaire ----------

    private static function people(Request $request): Response
    {
        $page = self::page($request);
        // L'API respecte l'annuaire : qui en est retiré n'en sort pas par une autre porte.
        $rows = Db::all(
            'SELECT u.id, u.first_name, u.last_name, u.email, u.grade, u.contract_type,
                    d.name AS department, t.name AS team
             FROM users u
             LEFT JOIN departments d ON d.id = u.department_id
             LEFT JOIN teams t ON t.id = u.team_id
             WHERE u.active = 1 AND u.directory_hidden = 0
             ORDER BY u.last_name COLLATE NOCASE LIMIT ? OFFSET ?',
            [$page['limit'], $page['offset']]
        );

        return self::json(self::wrap(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'prenom' => $row['first_name'], 'nom' => $row['last_name'],
            'email' => $row['email'], 'grade' => $row['grade'], 'contrat' => $row['contract_type'],
            'service' => $row['department'], 'equipe' => $row['team'],
        ], $rows), $page));
    }

    private static function names(array $people): array
    {
        return array_map(static fn (array $row): string => $row['first_name'] . ' ' . $row['last_name'], $people);
    }

    private static function departments(): Response
    {
        return self::json(self::wrap(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'nom' => $row['name'],
            'effectif' => (int) $row['member_count'], 'equipes' => (int) $row['team_count'],
            'encadrants' => self::names(Org::managersOf('department', (int) $row['id'])),
        ], Org::departments()), ['limit' => self::MAX_PAGE, 'offset' => 0]));
    }

    private static function teams(): Response
    {
        return self::json(self::wrap(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'nom' => $row['name'], 'service' => $row['department_name'],
            'effectif' => (int) $row['member_count'],
            'encadrants' => self::names(Org::managersOf('team', (int) $row['id'])),
        ], Org::teams()), ['limit' => self::MAX_PAGE, 'offset' => 0]));
    }

    // ---------- Ressources humaines ----------

    private static function leave(Request $request): Response
    {
        $page = self::page($request);
        $since = (string) $request->input('depuis_le');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $since) ? $since : '0000-01-01';

        $rows = Db::all(
            "SELECT r.id, r.type, r.start_date, r.end_date, r.days, r.status, u.first_name, u.last_name
             FROM hr_requests r JOIN users u ON u.id = r.employee_id
             WHERE r.status = 'Approuvée' AND r.end_date >= ?
             ORDER BY r.start_date DESC LIMIT ? OFFSET ?",
            [$from, $page['limit'], $page['offset']]
        );

        // Le motif de l'absence n'est pas rendu : il n'a pas à sortir de l'entreprise.
        return self::json(self::wrap(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'type' => $row['type'], 'du' => $row['start_date'],
            'au' => $row['end_date'], 'jours' => (float) $row['days'],
            'personne' => $row['first_name'] . ' ' . $row['last_name'],
        ], $rows), $page));
    }

    // ---------- Gestion ----------

    private static function partners(Request $request): Response
    {
        $page = self::page($request);
        $rows = Db::all(
            'SELECT id, kind, name, registration, email, phone, active FROM partners
             ORDER BY name COLLATE NOCASE LIMIT ? OFFSET ?',
            [$page['limit'], $page['offset']]
        );

        return self::json(self::wrap(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'type' => $row['kind'], 'nom' => $row['name'],
            'identifiant' => $row['registration'], 'email' => $row['email'],
            'telephone' => $row['phone'], 'actif' => (int) $row['active'] === 1,
        ], $rows), $page));
    }

    private static function invoices(Request $request): Response
    {
        $page = self::page($request);
        $wanted = in_array($request->input('sens'), ['Client', 'Fournisseur'], true) ? $request->input('sens') : null;
        $rows = array_slice(Finance::invoices($wanted), $page['offset'], $page['limit']);

        return self::json(self::wrap(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'reference' => $row['reference'], 'libelle' => $row['label'],
            'sens' => $row['direction'], 'statut' => $row['status'],
            'emise_le' => $row['issue_date'], 'echeance' => $row['due_date'],
            'en_retard' => (bool) ($row['overdue'] ?? false),
            'montant_ht' => (float) $row['amount_ht'], 'tva' => (float) $row['vat_rate'],
            'montant_ttc' => (float) $row['amount_ttc'],
            'devise' => $row['currency'], 'taux' => (float) $row['exchange_rate'],
            'montant_ttc_reference' => $row['amount_base_ttc'] ?? null,
            'devise_reference' => Currency::base(),
            'tiers' => $row['partner_name'],
        ], $rows), $page));
    }

    private static function subscriptions(): Response
    {
        return self::json(self::wrap(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'libelle' => $row['label'], 'sens' => $row['direction'],
            'tiers' => $row['partner_name'], 'montant_ht' => (float) $row['amount_ht'],
            'devise' => $row['currency'], 'periodicite' => $row['period'],
            'prochaine_emission' => $row['next_issue'], 'fin' => $row['end_date'],
            'actif' => (int) $row['active'] === 1,
        ], Billing::list()), ['limit' => self::MAX_PAGE, 'offset' => 0]));
    }

    // ---------- Projets ----------

    private static function projects(Request $request): Response
    {
        $page = self::page($request);
        $rows = Db::all(
            "SELECT p.id, p.code, p.name, p.status, p.budget_amount, p.start_date, p.due_date,
                    (SELECT COUNT(*) FROM project_tasks t WHERE t.project_id = p.id) AS tasks,
                    (SELECT COUNT(*) FROM project_tasks t WHERE t.project_id = p.id AND t.status = 'Terminée') AS done,
                    (SELECT COALESCE(SUM(hours), 0) FROM project_time pt WHERE pt.project_id = p.id) AS hours
             FROM projects p WHERE p.archived = 0
             ORDER BY p.name COLLATE NOCASE LIMIT ? OFFSET ?",
            [$page['limit'], $page['offset']]
        );

        return self::json(self::wrap(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'code' => $row['code'], 'nom' => $row['name'],
            'statut' => $row['status'], 'budget' => $row['budget_amount'],
            'debut' => $row['start_date'], 'echeance' => $row['due_date'],
            'taches' => (int) $row['tasks'], 'taches_terminees' => (int) $row['done'],
            'heures' => (float) $row['hours'],
        ], $rows), $page));
    }

    // ---------- Pilotage ----------

    private static function indicators(Request $request): Response
    {
        $year = (int) $request->input('annee') ?: (int) gmdate('Y');
        $revenue = Steering::revenue($year);
        $unpaid = Steering::unpaid();

        return self::json([
            'annee' => $year,
            'devise' => Currency::base(),
            'effectif' => (int) Db::value("SELECT COUNT(*) FROM users WHERE active = 1 AND role = 'employee'"),
            'chiffre_affaires' => $revenue['sales'],
            'achats' => $revenue['purchases'],
            'marge' => $revenue['margin'],
            'factures_impayees' => ['nombre' => $unpaid['count'], 'total' => $unpaid['total']],
        ]);
    }
}
