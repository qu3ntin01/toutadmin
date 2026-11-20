<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Modules\Kanban;
use App\Modules\Users;

/**
 * Déplacer une carte.
 *
 * Un seul chemin, comme pour les actions de masse, et pour la même raison : le
 * droit n'est pas sur le chemin mais sur la ligne, et le module le vérifie. Une
 * route par objet aurait multiplié les endroits où l'oublier.
 */
final class KanbanController
{
    /** La personne, relue en base : la session garde un identifiant, pas des droits. */
    private static function user(): ?array
    {
        $session = Session::get('user');
        if (!is_array($session) || !isset($session['id'])) {
            return null;
        }
        $ligne = Users::byId((int) $session['id']);
        return $ligne === null ? null : (array) $ligne;
    }

    public static function move(Request $request): Response
    {
        $verdict = Kanban::deplacer(
            $request->input('objet'),
            (int) $request->input('id'),
            $request->input('colonne'),
            self::user(),
        );

        Flash::set(
            $verdict['ok'] ? 'success' : 'error',
            I18n::translate($verdict['ok'] ? 'kanban.moved' : $verdict['raison']),
        );
        return Response::redirect($request->header('referer') ?: '/');
    }
}
