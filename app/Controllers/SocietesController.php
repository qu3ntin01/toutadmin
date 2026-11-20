<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Modules\Societes;

/**
 * Le périmètre : en changer, et l'administrer.
 *
 * Deux écrans en un seul contrôleur, et deux droits différents. Changer de
 * société est ouvert à qui en a plusieurs — c'est son travail quotidien. Les
 * créer, les rattacher et les fermer appartient à l'administration de
 * l'instance : c'est elle qui décide de la forme du groupe.
 */
final class SocietesController
{
    private static function refus(string $message, string $cible = '/admin#societes'): Response
    {
        Flash::set('error', $message);
        return Response::redirect($cible);
    }

    private static function admin(): bool
    {
        $user = Session::get('user');
        return is_array($user) && ($user['role'] ?? '') === 'admin';
    }

    private static function refuseNonAdmin(): ?Response
    {
        if (self::admin()) {
            return null;
        }
        return Response::html(\App\Core\View::page('error', [
            'title' => t('err.notAccessible'),
            'headerTitle' => t('err.notAccessible'),
            'message' => "Les sociétés sont administrées par l'administration de l'instance.",
        ]), 403);
    }

    /**
     * Changer de périmètre.
     *
     * Hors du droit d'administration : un salarié rattaché à deux entités d'un
     * groupe passe de l'une à l'autre toute la journée. Le module refuse ce qui ne
     * lui est pas ouvert, et c'est la seule vérification qui compte ici.
     */
    public static function choose(Request $request): Response
    {
        $voulue = (int) $request->input('societe_id');
        if (!Societes::choisir($voulue)) {
            return self::refus(t('societes.err.fermee'), $request->header('referer') ?: '/');
        }
        $societe = Societes::parId($voulue);
        Audit::log('societe.choisie', 'companies', $voulue, ['nom' => $societe['name'] ?? '']);
        return Response::redirect($request->header('referer') ?: '/');
    }

    // --------------------------------------- administration des sociétés

    public static function create(Request $request): Response
    {
        $refus = self::refuseNonAdmin();
        if ($refus !== null) {
            return $refus;
        }
        try {
            $societe = Societes::creer([
                'nom' => $request->input('nom'),
                'forme' => $request->input('forme'),
                'immatriculation' => $request->input('immatriculation'),
                'adresse' => $request->input('adresse'),
            ]);
        } catch (\RuntimeException $erreur) {
            return self::refus(t($erreur->getMessage()));
        }
        Audit::log('societe.creee', 'companies', (int) $societe['id'], ['nom' => $societe['name']]);
        Flash::set('success', t('societes.created', ['nom' => $societe['name']]));
        return Response::redirect('/admin#societes');
    }

    public static function update(Request $request, array $params): Response
    {
        $refus = self::refuseNonAdmin();
        if ($refus !== null) {
            return $refus;
        }
        try {
            $societe = Societes::modifier((int) $params['id'], [
                'nom' => $request->input('nom'),
                'forme' => $request->input('forme'),
                'immatriculation' => $request->input('immatriculation'),
                'adresse' => $request->input('adresse'),
            ]);
        } catch (\RuntimeException $erreur) {
            return self::refus(t($erreur->getMessage()));
        }
        if ($societe === null) {
            return self::refus(t('societes.err.introuvable'));
        }
        Audit::log('societe.modifiee', 'companies', (int) $societe['id'], ['nom' => $societe['name']]);
        Flash::set('success', t('societes.updated'));
        return Response::redirect('/admin#societes');
    }

    public static function toggle(Request $request, array $params): Response
    {
        $refus = self::refuseNonAdmin();
        if ($refus !== null) {
            return $refus;
        }
        $societe = Societes::parId((int) $params['id']);
        if ($societe === null) {
            return self::refus(t('societes.err.introuvable'));
        }
        $allumee = (int) $societe['active'] !== 1;
        $verdict = Societes::basculer((int) $societe['id'], $allumee);
        if (!$verdict['ok']) {
            return self::refus(t((string) $verdict['raison']));
        }
        Audit::log($allumee ? 'societe.rouverte' : 'societe.fermee', 'companies',
            (int) $societe['id'], ['nom' => $societe['name']]);
        Flash::set('success', t($allumee ? 'societes.reopened' : 'societes.closed', ['nom' => $societe['name']]));
        return Response::redirect('/admin#societes');
    }

    public static function attach(Request $request, array $params): Response
    {
        $refus = self::refuseNonAdmin();
        if ($refus !== null) {
            return $refus;
        }
        $societe = Societes::parId((int) $params['id']);
        if ($societe === null) {
            return self::refus(t('societes.err.introuvable'));
        }
        $userId = (int) $request->input('user_id');
        if (!Societes::rattacher((int) $societe['id'], $userId)) {
            return self::refus(t('societes.err.personne'));
        }
        $personne = Db::get('SELECT first_name, last_name FROM users WHERE id = ?', [$userId]);
        Audit::log('societe.rattachement', 'companies', (int) $societe['id'], ['user' => $userId]);
        Flash::set('success', t('societes.attached', [
            'nom' => trim(($personne['first_name'] ?? '') . ' ' . ($personne['last_name'] ?? '')),
            'societe' => $societe['name'],
        ]));
        return Response::redirect('/admin#societes');
    }

    public static function detach(Request $request, array $params): Response
    {
        $refus = self::refuseNonAdmin();
        if ($refus !== null) {
            return $refus;
        }
        $societe = Societes::parId((int) $params['id']);
        if ($societe === null) {
            return self::refus(t('societes.err.introuvable'));
        }
        $verdict = Societes::detacher((int) $societe['id'], (int) $request->input('user_id'));
        if (!$verdict['ok']) {
            return self::refus(t((string) $verdict['raison']));
        }
        Audit::log('societe.detachement', 'companies', (int) $societe['id'],
            ['user' => (int) $request->input('user_id')]);
        Flash::set('success', t('societes.detached'));
        return Response::redirect('/admin#societes');
    }
}
