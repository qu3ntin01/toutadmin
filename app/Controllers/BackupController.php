<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Backup;
use App\Modules\Exporter;

/**
 * Sauvegardes, restauration et export intégral.
 *
 * Réservé à l'administration : une archive contient tout — empreintes de mots
 * de passe, secrets de double authentification, bulletins de paie.
 */
final class BackupController
{
    private static function back(string $anchor, string $type, string $message): Response
    {
        Flash::set($type, $message);
        return Response::redirect('/sauvegardes#' . $anchor);
    }

    private static function error(string $message, int $status): Response
    {
        return Response::html(View::page('error', ['title' => $message, 'message' => $message]), $status);
    }

    public static function index(Request $request): Response
    {
        // Compte rendu de la dernière restauration, affiché une fois puis
        // oublié : retiré de la session avant le rendu, jamais après.
        $restoreReport = Session::get('restore_report');
        Session::forget('restore_report');

        return Response::html(View::page('backup/index', [
            'title' => t('nav.backups') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.backups'),
            'headerTitle' => t('nav.backups'),
            'headerSubtitle' => t('bak.subtitle'),
            'navItems' => [
                ['tab' => 'archives', 'label' => t('bak.archives')],
                ['tab' => 'restauration', 'label' => t('bak.restore')],
                ['tab' => 'export', 'label' => t('bak.fullExport')],
                ['tab' => 'reglages', 'label' => t('bak.automatic')],
            ],
            'footLinks' => [
                ['href' => '/securite', 'label' => t('nav.security')],
                ['href' => '/admin', 'label' => t('admin.title')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'archives' => Backup::list(),
            'summary' => Backup::summary(),
            'directory' => Backup::directory(),
            'maxUploadBytes' => Backup::MAX_UPLOAD_BYTES,
            'exportPreview' => Exporter::preview(),
            'restoreReport' => $restoreReport,
        ]));
    }

    public static function create(Request $request): Response
    {
        try {
            $created = Backup::create('manuelle', $request->input('label'));
            $removed = Backup::prune();
            Audit::log('sauvegarde.creee', 'backups', null, [
                'fichier' => $created['fileName'], 'octets' => $created['bytes'], 'purgees' => count($removed),
            ]);
            return self::back('archives', 'success',
                'Sauvegarde ' . $created['fileName'] . ' créée (' . $created['files'] . ' fichier(s)).');
        } catch (\Throwable $error) {
            Audit::log('sauvegarde.echec', 'backups', null, ['erreur' => $error->getMessage()]);
            return self::back('archives', 'error', 'La sauvegarde a échoué : ' . $error->getMessage());
        }
    }

    /** Copie hors ligne : c'est la seule protection contre la perte du serveur. */
    public static function download(Request $request, array $params): Response
    {
        $name = (string) $params['fichier'];
        $target = Backup::pathOf($name);
        if ($target === null) {
            return self::error('Sauvegarde introuvable.', 404);
        }
        Audit::log('sauvegarde.telechargee', 'backups', null, ['fichier' => $name]);
        return Response::text((string) file_get_contents($target))->withHeaders([
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }

    public static function verify(Request $request, array $params): Response
    {
        $name = (string) $params['fichier'];
        $verdict = Backup::inspectFile($name);
        return $verdict['ok']
            ? self::back('archives', 'success',
                "$name : " . count($verdict['manifest']['files']) . ' fichier(s) vérifié(s), archive intacte.')
            : self::back('archives', 'error', "$name : " . $verdict['message']);
    }

    public static function remove(Request $request, array $params): Response
    {
        $name = (string) $params['fichier'];
        if (!Backup::remove($name)) {
            return self::back('archives', 'error', 'Sauvegarde introuvable.');
        }
        Audit::log('sauvegarde.supprimee', 'backups', null, ['fichier' => $name]);
        return self::back('archives', 'success', 'Sauvegarde supprimée.');
    }

    // ---------- Restauration ----------

    /**
     * Toute restauration commence par une sauvegarde de l'état actuel : se
     * tromper d'archive ne doit pas être définitif.
     */
    private static function applyRestore(string $buffer, string $origin): Response
    {
        try {
            $safety = Backup::create('avant restauration', $origin);
        } catch (\Throwable $error) {
            return self::back('restauration', 'error',
                "Impossible de sauvegarder l'état actuel : " . $error->getMessage() . '. Restauration annulée.');
        }

        try {
            $verdict = Backup::restore($buffer, (int) Session::get('user')['id']);
        } catch (\Throwable $error) {
            $verdict = ['ok' => false, 'message' => $error->getMessage()];
        }

        if (!$verdict['ok']) {
            Audit::log('restauration.refusee', 'backups', null, ['origine' => $origin, 'motif' => $verdict['message']]);
            return self::back('restauration', 'error', $verdict['message'] . " Rien n'a été modifié.");
        }

        Audit::log('restauration.effectuee', 'backups', null, [
            'origine' => $origin,
            'sauvegarde_prealable' => $safety['fileName'],
            'tables' => $verdict['report']['tables'],
            'lignes' => $verdict['report']['rows'],
            'fichiers' => $verdict['report']['files'],
        ]);

        Session::set('restore_report', $verdict['report'] + [
            'origin' => $origin,
            'safety' => $safety['fileName'],
            'createdAt' => $verdict['manifest']['createdAt'],
        ]);
        return self::back('restauration', 'success', "Restauration effectuée depuis $origin. "
            . "L'état précédent a été sauvegardé sous " . $safety['fileName'] . '.');
    }

    public static function restore(Request $request, array $params): Response
    {
        $name = (string) $params['fichier'];
        $target = Backup::pathOf($name);
        if ($target === null) {
            return self::back('restauration', 'error', 'Sauvegarde introuvable.');
        }
        // La confirmation demande le nom exact : on ne restaure pas d'un clic
        // distrait.
        if ($request->input('confirmation') !== $name) {
            return self::back('restauration', 'error',
                'Saisissez le nom exact de la sauvegarde pour confirmer la restauration.');
        }
        return self::applyRestore((string) file_get_contents($target), $name);
    }

    public static function restoreUpload(Request $request): Response
    {
        $file = $request->file('archive');
        if ($file === null) {
            return self::back('restauration', 'error', 'Aucune archive reçue.');
        }
        if (strlen($file['bytes']) > Backup::MAX_UPLOAD_BYTES) {
            return self::back('restauration', 'error', 'Archive trop volumineuse : '
                . (int) round(Backup::MAX_UPLOAD_BYTES / 1048576) . ' Mo maximum par téléversement. '
                . 'Déposez le fichier directement dans ' . Backup::directory() . '.');
        }
        if (mb_strtoupper($request->input('confirmation')) !== 'RESTAURER') {
            return self::back('restauration', 'error', 'Saisissez « RESTAURER » pour confirmer.');
        }
        return self::applyRestore($file['bytes'], $file['name'] ?: 'archive téléversée');
    }

    // ---------- Export intégral ----------

    public static function export(Request $request): Response
    {
        $archive = Exporter::build();
        Audit::log('export.integral', 'settings', null, [
            'tables' => $archive['tables'], 'lignes' => $archive['rows'],
            'fichiers' => $archive['files'], 'octets' => $archive['bytes'],
        ]);
        // L'archive part directement vers le navigateur : elle ne laisse pas une
        // copie de toute l'entreprise dans un dossier du serveur.
        return Response::text($archive['buffer'])->withHeaders([
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="' . $archive['fileName'] . '"',
        ]);
    }

    // ---------- Réglages ----------

    public static function setSettings(Request $request): Response
    {
        Backup::setConfig(
            $request->input('enabled') !== '',
            (int) $request->input('interval_minutes'),
            (int) $request->input('keep')
        );
        $config = Backup::config();
        Audit::log('sauvegarde.reglages', 'settings', null, $config);
        return self::back('reglages', 'success', 'Réglages de sauvegarde enregistrés.');
    }
}
