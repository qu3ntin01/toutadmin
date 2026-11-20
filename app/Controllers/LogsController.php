<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\LogForward;
use App\Modules\Logs;

/**
 * Centre de journaux.
 *
 * Un écran, deux usages : chercher ce qui s'est passé, et brancher les
 * collecteurs qui en garderont une copie ailleurs. Les deux tiennent ensemble
 * parce qu'on ne configure bien un export qu'en voyant ce qu'il va emporter.
 */
final class LogsController
{
    private static function back(string $anchor, string $type, string $message): Response
    {
        Flash::set($type, $message);
        return Response::redirect('/journaux' . ($anchor === '' ? '' : '#' . $anchor));
    }

    private static function filters(Request $request): array
    {
        $level = $request->input('niveau');

        return [
            'page' => (int) $request->input('page') ?: 1,
            'maxLevel' => $level === '' ? '' : (int) $level,
            'channel' => $request->input('canal'),
            'event' => $request->input('evenement'),
            'search' => mb_substr($request->input('recherche'), 0, 120),
            'from' => $request->input('du'),
            'to' => $request->input('au'),
        ];
    }

    public static function index(Request $request): Response
    {
        $filters = self::filters($request);
        $opened = (int) $request->input('ligne') ?: null;
        $summary = LogForward::summary();

        return Response::html(View::page('logs/index', [
            'title' => t('nav.logs') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.logs'),
            'headerTitle' => t('nav.logs'),
            'headerSubtitle' => t('log.headerSub'),
            'navItems' => [
                ['tab' => 'journal', 'label' => t('log.tabJournal')],
                ['tab' => 'cibles', 'label' => t('log.tabTargets'), 'badge' => $summary['failing']],
                ['tab' => 'conservation', 'label' => t('log.tabRetention')],
            ],
            'footLinks' => [
                ['href' => '/securite', 'label' => t('nav.security')],
                ['href' => '/admin', 'label' => t('nav.adminConsole')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'journal' => Logs::list($filters),
            'filters' => $filters,
            'opened' => $opened === null ? null : Logs::byId($opened),
            'levels' => Logs::LEVELS,
            'channels' => Logs::CHANNELS,
            'knownEvents' => Logs::knownEvents(),
            'stats' => Logs::stats(7),
            'retention' => Logs::RETENTION,
            'targets' => LogForward::all(),
            'summary' => $summary,
            'transports' => LogForward::TRANSPORTS,
            'formats' => LogForward::FORMATS,
            'kinds' => LogForward::KINDS,
        ]));
    }

    /** L'export CSV reprend les filtres affichés : on exporte ce qu'on regarde. */
    public static function journalCsv(Request $request): Response
    {
        $rows = Logs::list(['page' => 1] + self::filters($request))['rows'];
        Audit::log('journal.exporte', 'logs', null, ['lignes' => count($rows)]);

        // Le BOM évite qu'un tableur ouvre les accents de travers.
        return Response::text("\u{FEFF}" . Logs::toCsv($rows))->withHeaders([
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="journaux.csv"',
        ]);
    }

    public static function purge(Request $request): Response
    {
        $removed = Logs::purge();
        Audit::log('journal.purge', 'logs', null, $removed);
        return self::back('conservation', 'success', $removed['total'] . ' ligne(s) purgée(s).');
    }

    /**
     * Vider le journal est un acte, pas un réglage : il laisse sa trace dans le
     * journal d'audit, que celui-ci ne peut pas effacer.
     */
    public static function clear(Request $request): Response
    {
        $removed = Logs::clear();
        Audit::log('journal.vide', 'logs', null, ['lignes' => $removed]);
        return self::back('conservation', 'success', "Journal vidé : $removed ligne(s) supprimée(s).");
    }

    // ---------- Cibles d'export ----------

    private static function values(Request $request): array
    {
        return [
            'kind' => $request->input('kind'),
            'label' => $request->input('label'),
            'transport' => $request->input('transport'),
            'host' => $request->input('host'),
            'port' => $request->input('port'),
            'format' => $request->input('format'),
            'facility' => $request->input('facility') === '' ? 16 : (int) $request->input('facility'),
            'appName' => $request->input('app_name'),
            'minLevel' => $request->input('min_level') === '' ? 6 : (int) $request->input('min_level'),
            'channels' => $request->inputs('channels'),
            'path' => $request->input('path'),
            'allowSelfSigned' => $request->input('allow_self_signed') !== '',
            'enabled' => $request->input('enabled') !== '',
        ];
    }

    public static function createTarget(Request $request): Response
    {
        $result = LogForward::create(self::values($request));
        if (!$result['ok']) {
            return self::back('cibles', 'error', $result['message']);
        }
        Audit::log('journal.cible_creee', 'log_targets', $result['id'],
            ['nom' => $request->input('label'), 'type' => $request->input('kind')]);
        return self::back('cibles', 'success', 'Cible enregistrée. Envoyez une ligne d’essai avant de compter dessus.');
    }

    public static function updateTarget(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $result = LogForward::update($id, self::values($request));
        if (!$result['ok']) {
            return self::back('cibles', 'error', $result['message']);
        }
        Audit::log('journal.cible_modifiee', 'log_targets', $id, ['nom' => $request->input('label')]);
        return self::back('cibles', 'success', 'Cible mise à jour.');
    }

    public static function setTargetState(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $enabled = $request->input('enabled') === '1';
        if (LogForward::byId($id) === null) {
            return self::back('cibles', 'error', 'Cible introuvable.');
        }
        LogForward::setEnabled($id, $enabled);
        Audit::log($enabled ? 'journal.cible_allumee' : 'journal.cible_eteinte', 'log_targets', $id);
        return self::back('cibles', 'success', $enabled ? 'Export rallumé.' : 'Export éteint.');
    }

    public static function testTarget(Request $request, array $params): Response
    {
        $verdict = LogForward::test((int) $params['id']);
        return self::back('cibles', $verdict['ok'] ? 'success' : 'error', $verdict['message']);
    }

    public static function sendTarget(Request $request, array $params): Response
    {
        $target = LogForward::byId((int) $params['id']);
        if ($target === null) {
            return self::back('cibles', 'error', 'Cible introuvable.');
        }
        $result = LogForward::flushTarget($target);
        return self::back('cibles', $result['ok'] ? 'success' : 'error', $result['ok']
            ? ($result['sent'] ?? 0) . ' ligne(s) envoyée(s).'
            : 'Envoi impossible : ' . ($result['error'] ?? ''));
    }

    /** Repartir du présent : on renonce à l'arriéré plutôt que de le déverser. */
    public static function skipBacklog(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        if (LogForward::byId($id) === null) {
            return self::back('cibles', 'error', 'Cible introuvable.');
        }
        $last = LogForward::skipBacklog($id);
        Audit::log('journal.cible_rattrapee', 'log_targets', $id, ['jusqua' => $last]);
        return self::back('cibles', 'success', 'Arriéré abandonné : la cible repart des lignes à venir.');
    }

    public static function deleteTarget(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        if (!LogForward::remove($id)) {
            return self::back('cibles', 'error', 'Cible introuvable.');
        }

        Audit::log('journal.cible_supprimee', 'log_targets', $id);
        return self::back('cibles', 'success', 'Cible supprimée.');
    }
}
