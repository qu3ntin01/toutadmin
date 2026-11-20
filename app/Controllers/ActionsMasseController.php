<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Flash;
use App\Core\I18n;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Modules\ActionsMasse;
use App\Modules\Users;

/**
 * Le seul point d'entrée des actions de masse.
 *
 * Une route par objet aurait semblé plus naturelle, et aurait dispersé la
 * vérification : trois routes, trois occasions d'oublier le plafond ou le compte
 * rendu. Ici le même chemin sert les tickets et les demandes, et le module décide
 * — c'est lui qui sait quels droits et quelles valeurs.
 *
 * Le contrôleur ne porte aucun droit propre, et ce n'est pas un oubli : un droit
 * posé ici serait le droit d'« utiliser les actions de masse », qui ne veut rien
 * dire. Ce qui compte est le droit sur chaque ligne, vérifié ligne par ligne,
 * exactement comme à l'unité.
 */
final class ActionsMasseController
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

    public static function apply(Request $request): Response
    {
        $objet = $request->input('objet');
        $action = $request->input('action');
        $retour = ActionsMasse::retourDe($objet);
        $origine = $request->header('referer') ?: $retour;

        $parametre = ActionsMasse::parametreDe($objet, $action);
        $valeur = $parametre === null ? null : $request->input('valeur_' . $parametre);

        $verdict = ActionsMasse::appliquer(
            $objet,
            $action,
            $request->inputs('ids'),
            $valeur,
            self::user(),
        );

        if ($verdict['ok'] === false) {
            Flash::set('error', I18n::translate($verdict['raison'], ['plafond' => ActionsMasse::PLAFOND]));
            return Response::redirect($origine);
        }

        /*
         * Une entrée au journal pour la demande elle-même, en plus de celles que
         * les actions à l'unité ont déjà écrites. Elle porte des nombres et non
         * des identifiants : ce qui a changé est déjà tracé ligne par ligne, et
         * recopier la liste ici ferait du journal un second exemplaire des
         * données.
         */
        Audit::log('masse.appliquee', $objet, null, [
            'action' => $action,
            'faites' => count($verdict['faites']),
            'refusees' => count($verdict['refusees']),
        ]);

        /* Les refus sont comptés par motif, et non listés un par un : une liste de
           cinquante identifiants ne dit rien, « hors de votre charge : 2 » dit tout. */
        $parMotif = [];
        foreach ($verdict['refusees'] as $refusee) {
            $parMotif[$refusee['raison']] = ($parMotif[$refusee['raison']] ?? 0) + 1;
        }
        $motifs = [];
        foreach ($parMotif as $cle => $combien) {
            $motifs[] = I18n::translate($cle) . ' : ' . $combien;
        }

        $message = $verdict['refusees'] === []
            ? I18n::translate('masse.fait', ['faites' => count($verdict['faites'])])
            : I18n::translate('masse.partiel', [
                'faites' => count($verdict['faites']),
                'refusees' => count($verdict['refusees']),
                'motifs' => implode(', ', $motifs),
            ]);

        Flash::set($verdict['faites'] === [] ? 'error' : 'success', $message);
        return Response::redirect($origine);
    }
}
