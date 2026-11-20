<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Les sessions ouvertes d'un compte, lisibles par qui les a ouvertes.
 *
 * ── Pourquoi cet écran existe ────────────────────────────────────────────
 *
 * Une session ne retenait que son identifiant et sa date d'expiration. La
 * console de sécurité pouvait donc en afficher la liste, mais cette liste ne
 * disait rien : des lignes identiques, sans moyen de reconnaître la sienne —
 * donc sans moyen de reconnaître celle d'un autre. Et le salarié, lui, ne
 * voyait rien du tout.
 *
 * Le seul recours devant un doute était « fermer toutes mes sessions », qui
 * ferme aussi la sienne. C'est un geste qu'on ne fait pas par précaution : on
 * le fait une fois certain, et on n'est jamais certain puisque rien ne se voit.
 * Voir où son compte est ouvert est la façon dont les gens découvrent qu'il
 * l'est ailleurs.
 *
 * ── Ce que l'on retient, et ce que l'on ne retient pas ───────────────────
 *
 * L'adresse, l'agent déclaré par le navigateur, l'ouverture et la dernière
 * vue. Rien de plus : pas de page visitée, pas d'empreinte de navigateur, pas
 * de géolocalisation. Le but est de reconnaître son propre appareil, pas de
 * suivre quelqu'un — un journal des pages visitées par chacun serait, lui, une
 * surveillance, et il n'a pas sa place ici.
 *
 * Tout part à la fermeture de la session : la ligne est effacée, pas archivée.
 */
final class Appareils
{
    /*
     * Reconnaître un appareil, pas identifier un navigateur.
     *
     * Cette liste est courte et le restera. Elle n'a qu'un travail : que la
     * personne retrouve sa propre ligne — « Firefox sur Windows », c'est assez
     * pour dire « ça c'est moi » et « ça, non ». L'ordre compte, parce que les
     * agents se citent les uns les autres : Edge dit « Chrome », Chrome dit
     * « Safari », et tout le monde dit « Mozilla ».
     */
    private const NAVIGATEURS = [
        '#Edg[eA]?/#' => 'Edge',
        '#OPR/|Opera#' => 'Opera',
        '#Firefox/#' => 'Firefox',
        '#Chrome/#' => 'Chrome',
        '#Safari/#' => 'Safari',
    ];

    private const SYSTEMES = [
        '#iPhone#' => 'iPhone',
        '#iPad#' => 'iPad',
        '#Android#' => 'Android',
        '#Windows#' => 'Windows',
        '#Mac OS X|Macintosh#' => 'Mac',
        '#Linux#' => 'Linux',
    ];

    private static function reconnaitre(array $liste, string $agent): ?string
    {
        foreach ($liste as $motif => $nom) {
            if (preg_match($motif, $agent) === 1) {
                return $nom;
            }
        }
        return null;
    }

    /**
     * Un nom d'appareil lisible, ou « null » quand on ne sait pas.
     *
     * Null plutôt qu'« Inconnu » : la vue décide comment le dire, dans sa
     * langue. Un agent absent ou fantaisiste est la normale pour un script, et
     * ce n'est pas en soi une anomalie — c'est à la personne d'en juger devant
     * la ligne.
     */
    public static function nom(?string $agent): ?string
    {
        $agent = (string) $agent;
        if ($agent === '') {
            return null;
        }
        $navigateur = self::reconnaitre(self::NAVIGATEURS, $agent);
        $systeme = self::reconnaitre(self::SYSTEMES, $agent);
        if ($navigateur !== null && $systeme !== null) {
            return $navigateur . ' sur ' . $systeme;
        }
        return $navigateur ?? $systeme;
    }

    /**
     * Noter d'où vient cette requête, sur la session qui la porte.
     *
     * « created_at » ne s'écrit qu'une fois — COALESCE s'en charge —, sinon
     * « ouverte depuis » repartirait de zéro à chaque page.
     *
     * Pas de bridage ici, contrairement à l'édition Node : celle-ci sert les
     * fichiers statiques elle-même et écrirait une fois par image, alors qu'en
     * PHP c'est le serveur web qui les sert et qu'on ne passe ici qu'une fois
     * par page.
     */
    public static function marquer(string $sid): void
    {
        $maintenant = time();
        $agent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
        Db::run(
            'UPDATE sessions SET created_at = COALESCE(created_at, ?), last_seen_at = ?, ip = ?, user_agent = ?
             WHERE sid = ?',
            [$maintenant, $maintenant, (string) ($_SERVER['REMOTE_ADDR'] ?? '') ?: null, $agent ?: null, $sid]
        );
    }

    /**
     * Les sessions ouvertes d'un compte, la courante marquée.
     *
     * On passe le sid courant plutôt que de le deviner : la console de sécurité
     * regarde le compte d'un autre, et rien n'y est « la vôtre ».
     *
     * @return list<array{sid: string, courante: bool, appareil: ?string, ip: ?string,
     *                    ouverte_le: ?int, vue_le: ?int, expire_le: int}>
     */
    public static function pour(int $userId, ?string $sidCourant = null): array
    {
        $lignes = Db::all(
            'SELECT sid, created_at, last_seen_at, ip, user_agent, expires_at
             FROM sessions WHERE user_id = ? AND expires_at > ?
             ORDER BY COALESCE(last_seen_at, created_at, expires_at) DESC',
            [$userId, time()]
        );

        return array_map(static fn (array $l): array => [
            'sid' => (string) $l['sid'],
            'courante' => (string) $l['sid'] === (string) $sidCourant,
            'appareil' => self::nom($l['user_agent'] ?? null),
            'ip' => $l['ip'] !== null && $l['ip'] !== '' ? (string) $l['ip'] : null,
            'ouverte_le' => $l['created_at'] !== null ? (int) $l['created_at'] : null,
            'vue_le' => $l['last_seen_at'] !== null ? (int) $l['last_seen_at'] : null,
            'expire_le' => (int) $l['expires_at'],
        ], $lignes);
    }

    /**
     * Fermer une session, et une seule.
     *
     * Le compte fait partie de la condition, pas seulement de la vérification :
     * un identifiant de session deviné ou relevé ailleurs ne ferme que ce qui
     * appartient à qui le présente. Rendre « false » quand rien n'a bougé
     * permet à l'appelant de ne pas annoncer une fermeture qui n'a pas eu lieu.
     */
    public static function fermer(int $userId, string $sid): bool
    {
        return Db::run('DELETE FROM sessions WHERE sid = ? AND user_id = ?', [$sid, $userId]) > 0;
    }
}
