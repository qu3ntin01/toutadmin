<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Audit;
use App\Core\Session;

/**
 * « Se connecter en tant que » : voir le produit par les yeux d'un salarié.
 *
 * Il existe pour une raison précise : « le bouton ne marche pas chez moi » ne se
 * résout pas en relisant du code, mais en regardant l'écran que la personne
 * regarde — avec ses droits, ses rattachements, ses données. Sans cela,
 * l'administration demande des captures d'écran, ou se fait communiquer un mot
 * de passe, ce qui est pire que tout.
 *
 * C'est aussi la fonction la plus dangereuse du produit, et elle est écrite
 * comme telle. Cinq refus en tiennent les bords :
 *
 *   1. seule l'administration peut l'ouvrir ;
 *   2. jamais sur un autre administrateur — il n'y a rien à reproduire qu'un
 *      administrateur ne puisse déjà voir, et « qui a fait quoi » cesserait
 *      d'avoir une réponse simple ;
 *   3. jamais en cascade : on n'emprunte pas depuis un emprunt ;
 *   4. une durée courte, qui ne se repousse pas. Un emprunt oublié ne doit pas
 *      devenir une porte ouverte ;
 *   5. pendant l'emprunt, les secrets de la personne restent à elle : le
 *      coffre-fort, son mot de passe, sa double authentification et sa
 *      messagerie sont fermés. Voir son écran n'est pas devenir elle.
 *
 * Et le journal ne perd jamais le fil : pendant un emprunt, l'auteur consigné
 * est l'administrateur — c'est lui qui répond de l'acte — avec mention de la
 * personne au nom de qui il agissait.
 */
final class Emprunt
{
    /** Durée d'un emprunt. Elle ne se repousse pas : c'est un plafond absolu. */
    public const DUREE_MINUTES = 30;

    /**
     * Ce qu'un emprunt n'ouvre pas.
     *
     * Liste de préfixes, fermée et relue à chaque requête par le noyau : ce qui
     * serait ajouté demain sous l'un de ces chemins est fermé sans qu'on y
     * pense. Ce sont les secrets de la personne, et non ses données de travail :
     * son coffre-fort, de quoi changer son mot de passe ou sa double
     * authentification, et sa messagerie, dont le mot de passe est en base.
     */
    public const FERME = [
        '/coffre-fort',
        '/mot-de-passe',
        '/mon-profil/mot-de-passe',
        '/mon-profil/double-authentification',
        '/mon-profil/messagerie',
        '/messages',
    ];

    /** @return array{ok:bool, raison?:string, cible?:array} */
    public static function cibleUtilisable(int $id): array
    {
        $cible = Users::byId($id);
        if ($cible === null) {
            return ['ok' => false, 'raison' => 'emprunt.err.introuvable'];
        }
        if ((int) $cible['active'] !== 1) {
            return ['ok' => false, 'raison' => 'emprunt.err.inactif'];
        }
        // Un administrateur ne s'emprunte pas : voir sa page n'apprendrait rien
        // que l'on ne puisse déjà voir, et le journal ne saurait plus dire lequel
        // des deux a agi.
        if ($cible['role'] === 'admin') {
            return ['ok' => false, 'raison' => 'emprunt.err.administrateur'];
        }
        return ['ok' => true, 'cible' => $cible];
    }

    /**
     * L'emprunt en cours, tel que les écrans doivent le voir.
     *
     * @return array{parId:int, parLabel:string, depuis:int, minutesRestantes:int, expire:bool}|null
     */
    public static function encours(): ?array
    {
        $e = Session::get('emprunt');
        if (!is_array($e)) {
            return null;
        }
        $reste = max(0, self::DUREE_MINUTES * 60 - (time() - (int) ($e['depuis'] ?? 0)));
        return [
            'parId' => (int) $e['parId'],
            'parLabel' => (string) $e['parLabel'],
            'depuis' => (int) $e['depuis'],
            'minutesRestantes' => (int) ceil($reste / 60),
            'expire' => $reste === 0,
        ];
    }

    /**
     * Ouvrir l'emprunt.
     *
     * La session est régénérée : un changement de droits change d'identifiant de
     * session, sinon un jeton volé avant l'emprunt vaudrait après.
     * L'administrateur n'est pas gardé en session autrement que par son
     * identifiant — tout le reste se relit en base au retour, et une session n'a
     * pas à porter deux identités.
     *
     * @return array{ok:bool, raison?:string, cible?:array}
     */
    public static function commencer(int $cibleId): array
    {
        $admin = Session::get('user');
        if (!is_array($admin) || ($admin['role'] ?? '') !== 'admin') {
            return ['ok' => false, 'raison' => 'emprunt.err.reserve'];
        }
        if (is_array(Session::get('emprunt'))) {
            return ['ok' => false, 'raison' => 'emprunt.err.encours'];
        }
        $verdict = self::cibleUtilisable($cibleId);
        if (!$verdict['ok']) {
            return $verdict;
        }
        $cible = $verdict['cible'];

        // La session porte les noms en camelCase (voir Users::forSession) : s'en
        // écarter ici donnerait un bandeau sans nom, et un journal qui ne dit
        // qu'une adresse.
        $parLabel = trim(($admin['firstName'] ?? '') . ' ' . ($admin['lastName'] ?? ''));
        if ($parLabel === '') {
            $parLabel = (string) ($admin['email'] ?? '#' . $admin['id']);
        }

        Audit::log('emprunt.debut', 'users', (int) $cible['id'], [
            'pour' => trim($cible['first_name'] . ' ' . $cible['last_name']),
            'minutes' => self::DUREE_MINUTES,
        ]);

        Session::regenerate();
        Session::set('opened_at', time());
        Session::set('emprunt', [
            'parId' => (int) $admin['id'],
            'parLabel' => $parLabel,
            'depuis' => time(),
        ]);
        // La même forme que celle d'une connexion ordinaire : un écran qui lit la
        // session ne doit pas avoir à savoir comment elle a été ouverte.
        Session::set('user', Users::forSession($cible));

        return ['ok' => true, 'cible' => $cible];
    }

    /**
     * Revenir à soi.
     *
     * L'administrateur est relu en base, et non restitué depuis la session :
     * entre le départ et le retour, son compte a pu être désactivé ou
     * rétrogradé, et c'est l'état d'aujourd'hui qui décide. S'il n'est plus
     * administrateur, le retour échoue et la session repart vierge — mieux vaut
     * une reconnexion qu'un droit rendu par habitude.
     *
     * @return array{ok:bool, raison?:string, deconnecte?:bool, admin?:array}
     */
    public static function revenir(): array
    {
        $e = Session::get('emprunt');
        if (!is_array($e)) {
            return ['ok' => false, 'raison' => 'emprunt.err.aucun'];
        }
        $emprunte = Session::get('user');
        $admin = Users::byId((int) $e['parId']);

        Audit::log('emprunt.fin', 'users', is_array($emprunte) ? (int) $emprunte['id'] : null, [
            'minutes' => (int) round((time() - (int) ($e['depuis'] ?? time())) / 60),
        ]);

        if ($admin === null || (int) $admin['active'] !== 1 || $admin['role'] !== 'admin') {
            Session::destroy();
            return ['ok' => false, 'raison' => 'emprunt.err.retour', 'deconnecte' => true];
        }

        /*
         * « regenerate » garde les données de session dans cette édition — elle
         * ne change que l'identifiant. L'emprunt doit donc être retiré à la
         * main : l'oublier laisserait le verrou croire que l'emprunt continue,
         * et chaque requête repartirait vers « revenir » sans fin.
         */
        Session::forget('emprunt');
        Session::regenerate();
        Session::set('opened_at', time());
        Session::set('user', Users::forSession($admin));
        return ['ok' => true, 'admin' => $admin];
    }

    /** Un chemin que l'emprunt ferme. */
    public static function ferme(string $chemin): bool
    {
        foreach (self::FERME as $prefixe) {
            if ($chemin === $prefixe || str_starts_with($chemin, $prefixe . '/')) {
                return true;
            }
        }
        return false;
    }
}
