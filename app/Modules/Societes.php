<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;
use App\Core\Session;

/**
 * Les sociétés, et le périmètre courant.
 *
 * Le cloisonnement a deux moitiés. L'autre — app/Core/Cloison.php — dit quelles
 * tables portent un périmètre et refuse les requêtes qui l'oublient. Celle-ci dit
 * quel périmètre, pour qui, et comment on en change.
 *
 * Deux règles d'accès, et elles ne se ressemblent pas :
 *
 *   - un salarié voit les sociétés où il est rattaché, et rien d'autre. Le
 *     rattachement est une donnée, pas un droit : il se lit en base à chaque
 *     requête, comme tous les droits de ce produit ;
 *   - l'administration de l'instance voit toutes les sociétés. C'est elle qui les
 *     crée, les rattache et les ferme ; lui demander de se rattacher elle-même
 *     pour administrer reviendrait à pouvoir se verrouiller dehors.
 *
 * Ce que ce module ne fait pas : filtrer. Il ne rend que l'identifiant du
 * périmètre ; ce sont les requêtes qui s'en servent, et le garde qui vérifie
 * qu'elles le font.
 */
final class Societes
{
    /**
     * Le périmètre imposé, le temps d'un bloc. Voir dans().
     *
     * Une requête HTTP n'en a qu'un, et il vient de la session ; mais l'API, les
     * tâches planifiées et les tests travaillent hors session, et le code qui lit
     * la base n'a pas à savoir lequel des deux cas il sert.
     */
    private static ?int $impose = null;

    /**
     * Le périmètre courant, tel que les requêtes doivent le nommer.
     *
     * C'est un contexte, et non un argument passé de main en main : les modules
     * qui lisent la base sont appelés à des centaines d'endroits, et un argument
     * qu'il faudrait passer des centaines de fois serait passé de travers une
     * fois — le garde vérifie qu'une requête nomme le périmètre, pas qu'elle
     * nomme le bon. L'édition Node fait de même, avec un contexte asynchrone ;
     * ici une requête est servie d'un trait, un champ statique suffit.
     *
     * Hors session — un jeton d'API, une tâche planifiée —, c'est la société qui
     * mène : voir perimetreParDefaut(). Jamais nul sur une instance installée,
     * et c'est ce qui permet aux requêtes de nommer le périmètre sans avoir à se
     * demander s'il y en a un.
     */
    public static function courant(): ?int
    {
        return self::$impose ?? self::courantId() ?? self::perimetreParDefaut();
    }

    /**
     * Travailler dans une société nommée, puis rendre le périmètre comme il était.
     *
     * L'imbrication est permise et le périmètre précédent revient toujours, même
     * si le travail lève : un périmètre laissé de travers par une exception ferait
     * écrire la suite de la requête dans la mauvaise société.
     */
    public static function dans(int $societeId, callable $travail): mixed
    {
        $avant = self::$impose;
        self::$impose = $societeId;
        try {
            return $travail();
        } finally {
            self::$impose = $avant;
        }
    }

    /** @return list<array<string, mixed>> */
    public static function accessibles(?array $user): array
    {
        if ($user === null) {
            return [];
        }
        if (($user['role'] ?? '') === 'admin') {
            return Db::all('SELECT * FROM companies WHERE active = 1 ORDER BY name COLLATE NOCASE');
        }
        return Db::all(
            'SELECT c.* FROM companies c
             JOIN company_members m ON m.company_id = c.id
             WHERE m.user_id = ? AND c.active = 1
             ORDER BY c.name COLLATE NOCASE',
            [(int) $user['id']],
        );
    }

    public static function parId(int $id): ?array
    {
        return $id > 0 ? Db::get('SELECT * FROM companies WHERE id = ?', [$id]) : null;
    }

    /** @return list<array<string, mixed>> */
    public static function toutes(bool $fermees = true): array
    {
        return $fermees
            ? Db::all('SELECT * FROM companies ORDER BY active DESC, name COLLATE NOCASE')
            : Db::all('SELECT * FROM companies WHERE active = 1 ORDER BY name COLLATE NOCASE');
    }

    public static function compter(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM companies WHERE active = 1');
    }

    public static function ouverte(?array $user, int $societeId): bool
    {
        if ($societeId <= 0 || $user === null) {
            return false;
        }
        foreach (self::accessibles($user) as $societe) {
            if ((int) $societe['id'] === $societeId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Le périmètre courant.
     *
     * Il se relit entièrement à chaque requête : la société gardée en session
     * n'est qu'un souvenir, pas un droit. Un rattachement retiré, une société
     * fermée, et le souvenir ne vaut plus rien — on retombe alors sur la première
     * société accessible, ou sur rien du tout.
     */
    public static function courante(): ?array
    {
        $ouvertes = self::accessibles(Session::get('user'));
        if ($ouvertes === []) {
            return null;
        }
        $gardee = (int) Session::get('societe_id', 0);
        foreach ($ouvertes as $societe) {
            if ((int) $societe['id'] === $gardee) {
                return $societe;
            }
        }
        /*
         * Le souvenir ne vaut plus : on le remplace, sans rien dire — ce n'est
         * pas un incident, c'est un rattachement qui a changé.
         *
         * Par défaut, la plus ancienne des sociétés accessibles, et non la
         * première par ordre alphabétique : créer une filiale dont le nom passe
         * avant celui de la maison mère changerait alors le périmètre de tout le
         * monde sans que personne l'ait demandé. L'ordre des noms sert à
         * l'affichage, pas au choix.
         */
        $defaut = $ouvertes[0];
        foreach ($ouvertes as $societe) {
            if ((int) $societe['id'] < (int) $defaut['id']) {
                $defaut = $societe;
            }
        }
        Session::set('societe_id', (int) $defaut['id']);
        return $defaut;
    }

    public static function courantId(): ?int
    {
        $societe = self::courante();
        return $societe === null ? null : (int) $societe['id'];
    }

    /** Changer de périmètre. Rend false si la société n'est pas ouverte à la personne. */
    public static function choisir(int $societeId): bool
    {
        if (!self::ouverte(Session::get('user'), $societeId)) {
            return false;
        }
        Session::set('societe_id', $societeId);
        return true;
    }

    /**
     * Le périmètre de ce qui n'a pas de session : l'API, les tâches planifiées.
     *
     * Un jeton d'API n'ouvre pas de session et ne choisit pas de société ; une
     * tâche planifiée non plus. Tant que le jeton ne porte pas son propre
     * périmètre — ce sera le lot qui reprendra l'API —, les deux travaillent dans
     * la première société de l'instance. Sur une instance à une société, c'est
     * exactement le comportement d'avant le cloisonnement ; sur une instance à
     * plusieurs, c'est une limite qu'il faut connaître, et c'est pour cela qu'elle
     * est écrite ici plutôt que dispersée dans les appelants.
     */
    public static function perimetreParDefaut(): ?int
    {
        $id = Db::value('SELECT id FROM companies WHERE active = 1 ORDER BY id LIMIT 1');
        return $id === null ? null : (int) $id;
    }

    /** La première société, garantie avant qu'un compte existe. */
    public static function garantirPremiere(string $nom = ''): ?int
    {
        return Db::garantirPremiereSociete($nom);
    }

    // ------------------------------------------------------------- écriture

    public static function creer(array $champs): array
    {
        $nom = mb_substr(trim((string) ($champs['nom'] ?? '')), 0, 160);
        if ($nom === '') {
            throw new \RuntimeException('societes.err.nom');
        }
        if (Db::value('SELECT id FROM companies WHERE name = ? COLLATE NOCASE', [$nom]) !== null) {
            throw new \RuntimeException('societes.err.doublon');
        }
        $id = Db::insert(
            'INSERT INTO companies (name, legal_form, registration, address) VALUES (?, ?, ?, ?)',
            [
                $nom,
                mb_substr(trim((string) ($champs['forme'] ?? '')), 0, 60),
                mb_substr(trim((string) ($champs['immatriculation'] ?? '')), 0, 40),
                mb_substr(trim((string) ($champs['adresse'] ?? '')), 0, 300),
            ],
        );
        return (array) self::parId($id);
    }

    public static function modifier(int $id, array $champs): ?array
    {
        $societe = self::parId($id);
        if ($societe === null) {
            return null;
        }
        $nom = mb_substr(trim((string) ($champs['nom'] ?? '')), 0, 160);
        if ($nom === '') {
            throw new \RuntimeException('societes.err.nom');
        }
        if (Db::value('SELECT id FROM companies WHERE name = ? COLLATE NOCASE AND id <> ?', [$nom, $id]) !== null) {
            throw new \RuntimeException('societes.err.doublon');
        }
        Db::run(
            'UPDATE companies SET name = ?, legal_form = ?, registration = ?, address = ? WHERE id = ?',
            [
                $nom,
                mb_substr(trim((string) ($champs['forme'] ?? '')), 0, 60),
                mb_substr(trim((string) ($champs['immatriculation'] ?? '')), 0, 40),
                mb_substr(trim((string) ($champs['adresse'] ?? '')), 0, 300),
                $id,
            ],
        );
        return self::parId($id);
    }

    /**
     * Fermer ou rouvrir une société.
     *
     * Fermer, et non supprimer : ses écritures, ses factures et ses contrats font
     * preuve, et les effacer parce qu'une entité a cessé son activité serait
     * perdre ce qu'une entité cessant son activité doit justement conserver.
     *
     * La dernière société ouverte ne se ferme pas : une instance sans périmètre
     * n'affiche plus rien, et personne ne saurait quoi rouvrir.
     *
     * @return array{ok:bool, raison?:string, societe?:array}
     */
    public static function basculer(int $id, bool $allumee): array
    {
        $societe = self::parId($id);
        if ($societe === null) {
            return ['ok' => false, 'raison' => 'societes.err.introuvable'];
        }
        if (!$allumee && self::compter() <= 1) {
            return ['ok' => false, 'raison' => 'societes.err.derniere'];
        }
        Db::run('UPDATE companies SET active = ? WHERE id = ?', [$allumee ? 1 : 0, $id]);
        return ['ok' => true, 'societe' => (array) self::parId($id)];
    }

    // --------------------------------------------------------- appartenance

    /** @return list<array<string, mixed>> */
    public static function membres(int $societeId): array
    {
        return Db::all(
            'SELECT u.id, u.first_name, u.last_name, u.email, u.role, u.active
             FROM company_members m JOIN users u ON u.id = m.user_id
             WHERE m.company_id = ?
             ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE',
            [$societeId],
        );
    }

    public static function rattacher(?int $societeId, int $userId): bool
    {
        if ($societeId === null || self::parId($societeId) === null) {
            return false;
        }
        if (Db::value('SELECT id FROM users WHERE id = ?', [$userId]) === null) {
            return false;
        }
        Db::run(
            'INSERT INTO company_members (company_id, user_id) VALUES (?, ?)
             ON CONFLICT(company_id, user_id) DO NOTHING',
            [$societeId, $userId],
        );
        return true;
    }

    /**
     * Détacher quelqu'un d'une société.
     *
     * Refusé si c'est son dernier rattachement et qu'elle n'est pas de
     * l'administration : un salarié sans société ne voit plus rien, et ne peut pas
     * se rattacher lui-même.
     *
     * @return array{ok:bool, raison?:string}
     */
    public static function detacher(int $societeId, int $userId): array
    {
        $personne = Db::get('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($personne === null) {
            return ['ok' => false, 'raison' => 'societes.err.personne'];
        }
        $restantes = (int) Db::value('SELECT COUNT(*) FROM company_members WHERE user_id = ?', [$userId]);
        if ($restantes <= 1 && $personne['role'] !== 'admin') {
            return ['ok' => false, 'raison' => 'societes.err.dernierRattachement'];
        }
        Db::run('DELETE FROM company_members WHERE company_id = ? AND user_id = ?', [$societeId, $userId]);
        return ['ok' => true];
    }
}
