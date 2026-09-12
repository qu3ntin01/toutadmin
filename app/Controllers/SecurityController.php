<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;
use App\Modules\Users;

/**
 * Console de sécurité.
 *
 * Le journal, les comptes qui méritent un regard, les sessions ouvertes, la
 * politique. Réservée à l'administration : la porte est dans le noyau, avec
 * celle du reste de /admin.
 */
final class SecurityController
{
    private static function back(string $anchor, string $type, string $message): Response
    {
        Flash::set($type, $message);
        return Response::redirect('/securite#' . $anchor);
    }

    /** Comptes qui méritent un regard : ce sont eux qu'on attaque en premier. */
    private static function riskyAccounts(): array
    {
        $now = gmdate('c');
        $stale = gmdate('Y-m-d H:i:s', time() - 90 * 86400);

        return [
            'locked' => Db::all(
                'SELECT id, email, first_name, last_name, locked_until FROM users
                 WHERE locked_until > ? ORDER BY locked_until DESC',
                [$now]
            ),
            'temporary' => Db::all(
                'SELECT id, email, first_name, last_name, created_at FROM users
                 WHERE must_change_password = 1 AND active = 1 ORDER BY created_at'
            ),
            'withoutTotp' => Db::all(
                "SELECT id, email, first_name, last_name, role FROM users
                 WHERE totp_enabled = 0 AND active = 1 AND role = 'admin' ORDER BY email"
            ),
            'dormant' => Db::all(
                'SELECT id, email, first_name, last_name, last_login_at FROM users
                 WHERE active = 1 AND (last_login_at IS NULL OR last_login_at < ?)
                 ORDER BY last_login_at IS NOT NULL, last_login_at',
                [$stale]
            ),
        ];
    }

    private static function openSessions(): array
    {
        return Db::all(
            'SELECT s.sid, s.user_id, s.expires_at, u.email, u.first_name, u.last_name, u.role
             FROM sessions s LEFT JOIN users u ON u.id = s.user_id
             WHERE s.expires_at > ? AND s.user_id IS NOT NULL
             ORDER BY s.expires_at DESC',
            [time()]
        );
    }

    private static function filters(Request $request): array
    {
        return [
            'page' => (int) $request->input('page') ?: 1,
            'action' => $request->input('action'),
            'actorId' => (int) $request->input('auteur') ?: null,
            'entity' => $request->input('objet'),
            'from' => $request->input('du'),
            'to' => $request->input('au'),
        ];
    }

    public static function index(Request $request): Response
    {
        $filters = self::filters($request);

        return Response::html(View::page('security/index', [
            'title' => t('nav.security') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.security'),
            'headerTitle' => t('nav.security'),
            'headerSubtitle' => t('sec.headerSub'),
            'navItems' => [
                ['tab' => 'journal', 'label' => t('sec.tabAudit')],
                ['tab' => 'scellement', 'label' => t('sec.tabSeal')],
                ['tab' => 'comptes', 'label' => t('sec.tabWatch')],
                ['tab' => 'administrateurs', 'label' => t('sec.tabAdmins')],
                ['tab' => 'sessions', 'label' => t('sec.tabSessions')],
                ['tab' => 'politique', 'label' => t('sec.tabPolicy')],
            ],
            'footLinks' => [
                ['href' => '/admin', 'label' => t('admin.title')],
                ['href' => '/rgpd', 'label' => t('nav.privacy')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'journal' => Audit::list($filters),
            'filters' => $filters,
            'knownActions' => Audit::knownActions(),
            'admins' => Db::all(
                "SELECT id, email, first_name, last_name, totp_enabled, last_login_at FROM users
                 WHERE role = 'admin' ORDER BY email"
            ),
            'promotable' => Db::all(
                "SELECT id, email, first_name, last_name FROM users
                 WHERE role = 'employee' AND active = 1 ORDER BY last_name COLLATE NOCASE"
            ),
            'sessions' => self::openSessions(),
            'risky' => self::riskyAccounts(),
            'policy' => Settings::all(),
            // Le sceau du journal : vérifié à l'ouverture, pas sur demande. Un
            // contrôle qu'il faut penser à lancer est un contrôle qu'on ne
            // lance pas.
            'seal' => Audit::verifySeal(),
            'counters' => [
                'entries' => (int) Db::value('SELECT COUNT(*) FROM audit_log'),
                'totpEnabled' => (int) Db::value('SELECT COUNT(*) FROM users WHERE totp_enabled = 1'),
                'accounts' => (int) Db::value('SELECT COUNT(*) FROM users WHERE active = 1'),
            ],
        ]));
    }

    /** Export du journal, filtres compris : une demande d'audit se répond par un fichier. */
    public static function journalCsv(Request $request): Response
    {
        $rows = Audit::list(['page' => 1] + self::filters($request))['rows'];
        Audit::log('journal.exporte', 'audit_log', null, ['lignes' => count($rows)]);
        // Le BOM évite qu'un tableur ouvre les accents de travers.
        return Response::text("\u{FEFF}" . Audit::toCsv($rows))->withHeaders([
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="journal-audit.csv"',
        ]);
    }

    // ---------- Administrateurs ----------

    private static function adminCount(): int
    {
        return (int) Db::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1");
    }

    public static function promote(Request $request): Response
    {
        $id = (int) $request->input('user_id');
        $user = Db::get("SELECT * FROM users WHERE id = ? AND role = 'employee'", [$id]);
        if ($user === null) {
            return self::back('administrateurs', 'error', 'Membre introuvable.');
        }
        Db::run("UPDATE users SET role = 'admin' WHERE id = ?", [$id]);
        Audit::log('administrateur.promu', 'users', $id, ['email' => $user['email']]);
        return self::back('administrateurs', 'success', $user['email'] . ' est désormais administrateur.');
    }

    public static function demote(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $user = Db::get("SELECT * FROM users WHERE id = ? AND role = 'admin'", [$id]);
        if ($user === null) {
            return self::back('administrateurs', 'error', 'Administrateur introuvable.');
        }
        // Une instance sans administrateur ne se rattrape plus depuis l'interface.
        if (self::adminCount() <= 1) {
            return self::back('administrateurs', 'error',
                "Le dernier administrateur ne peut pas être rétrogradé : l'instance deviendrait ingérable.");
        }
        if ($id === (int) Session::get('user')['id']) {
            return self::back('administrateurs', 'error', 'Un administrateur ne se retire pas lui-même ses droits.');
        }

        Db::run("UPDATE users SET role = 'employee' WHERE id = ?", [$id]);
        // La rétrogradation vaut tout de suite, mais fermer ses sessions évite
        // qu'une page déjà ouverte laisse croire le contraire.
        Session::destroyAllFor($id);
        Audit::log('administrateur.retrograde', 'users', $id, ['email' => $user['email']]);
        return self::back('administrateurs', 'success', $user['email'] . ' redevient membre.');
    }

    // ---------- Sessions ----------

    public static function closeSessions(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $closed = Session::destroyAllFor($id);
        Audit::log('sessions.revoquees', 'users', $id, ['fermees' => $closed]);
        return self::back('sessions', 'success', "$closed session(s) fermée(s).");
    }

    // ---------- Double authentification d'un membre ----------

    public static function resetTotp(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $user = Users::byId($id);
        if ($user === null) {
            return self::back('comptes', 'error', 'Membre introuvable.');
        }
        Db::run('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?', [$id]);
        Db::run('DELETE FROM totp_recovery_codes WHERE user_id = ?', [$id]);
        Session::destroyAllFor($id);
        Audit::log('2fa.reinitialisee_par_admin', 'users', $id, ['email' => $user['email']]);
        return self::back('comptes', 'success', 'Double authentification remise à zéro pour '
            . $user['email'] . ' : la personne devra la remettre en service.');
    }

    public static function unlock(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        Db::run('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?', [$id]);
        Audit::log('compte.deverrouille', 'users', $id);
        return self::back('comptes', 'success', 'Compte déverrouillé.');
    }

    // ---------- Politique ----------

    public static function setPolicy(Request $request): Response
    {
        $retention = (int) $request->input('audit_retention_days');
        if ($retention < 30 || $retention > 3650) {
            return self::back('politique', 'error',
                'La conservation du journal doit tenir entre 30 et 3650 jours.');
        }

        $adminTotp = $request->input('require_2fa_admin') !== '';
        $allTotp = $request->input('require_2fa_all') !== '';
        Settings::setMany([
            'require_2fa_admin' => $adminTotp ? '1' : '0',
            'require_2fa_all' => $allTotp ? '1' : '0',
            'audit_retention_days' => (string) $retention,
        ]);
        Audit::log('politique.modifiee', 'settings', null, [
            'require_2fa_admin' => $adminTotp,
            'require_2fa_all' => $allTotp,
            'audit_retention_days' => $retention,
        ]);
        return self::back('politique', 'success', 'Politique de sécurité enregistrée.');
    }

    public static function purgeJournal(Request $request): Response
    {
        $days = (int) Settings::get('audit_retention_days') ?: 365;
        $removed = Audit::purgeOlderThan($days);
        Audit::log('journal.purge', 'audit_log', null, ['supprimees' => $removed, 'au_dela_de_jours' => $days]);
        return self::back('journal', 'success', "$removed entrée(s) de plus de $days jours supprimée(s).");
    }
}
