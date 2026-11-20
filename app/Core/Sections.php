<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Les sections de la console, et ce qu'elles contiennent.
 *
 * Le pendant exact de « src/sections.js » de l'édition Node : mêmes groupes,
 * mêmes services, mêmes icônes, mêmes droits. Les deux éditions doivent montrer
 * le même produit — une rubrique rangée ici et là est une rubrique qu'on ne
 * retrouve plus en changeant d'édition.
 *
 * ── Pourquoi des groupes ─────────────────────────────────────────────────
 *
 * La console posait ses rubriques à la file. Sur un portable, les dernières
 * tombaient sous le bord de l'écran, et les trouver demandait de faire défiler
 * un rail — c'est-à-dire de savoir déjà qu'elles existaient. Sept groupes
 * repliés valent mieux que quarante lignes dépliées : on choisit d'abord un
 * métier, puis un écran dedans.
 *
 * ── Ce qui compose une entrée ────────────────────────────────────────────
 *
 *   cle      ce qui la nomme dans le code et dans les adresses ;
 *   titre    la clé de traduction de son nom — jamais le mot lui-même ;
 *   quoi     la clé de traduction de ce qu'elle fait, en une ligne. C'est ce
 *            qui s'écrit sous la tuile : « Émettre une facture » se comprend,
 *            « Facturation » se devine ;
 *   href     où elle mène ;
 *   icone    celle du rail et de la tuile — la même des deux côtés, pour qu'on
 *            reconnaisse au tableau de bord ce qu'on a vu dans le rail ;
 *   groupe   la section à laquelle elle appartient ;
 *   droit    qui peut l'ouvrir (voir DROITS) ;
 *   module   le module optionnel qui la débloque, s'il y en a un ;
 *   onglet   le panneau de la console qu'elle ouvre, le cas échéant ;
 *   prefixes les chemins qui lui appartiennent, pour allumer la bonne rubrique.
 *            Absent, on prend « href ».
 */
final class Sections
{
    /** Les groupes, dans l'ordre où le rail les pose. */
    public const GROUPES = [
        ['cle' => 'pilotage', 'titre' => 'group.pilotage', 'icone' => 'home'],
        ['cle' => 'personnel', 'titre' => 'group.personnel', 'icone' => 'users'],
        ['cle' => 'gestion', 'titre' => 'group.gestion', 'icone' => 'euro'],
        ['cle' => 'relation', 'titre' => 'group.relation', 'icone' => 'lifebuoy'],
        ['cle' => 'technique', 'titre' => 'group.technique', 'icone' => 'tool'],
        ['cle' => 'conformite', 'titre' => 'group.conformite', 'icone' => 'scale'],
        ['cle' => 'reglages', 'titre' => 'group.reglages', 'icone' => 'settings'],
    ];

    /** Le catalogue. */
    public const CATALOGUE = [
        ['cle' => 'pilotage', 'titre' => 'nav.steering', 'quoi' => 'quoi.steering', 'href' => '/pilotage', 'icone' => 'home', 'groupe' => 'pilotage', 'droit' => 'pilotage'],
        ['cle' => 'direction', 'titre' => 'nav.governance', 'quoi' => 'quoi.governance', 'href' => '/direction', 'icone' => 'ballot', 'groupe' => 'pilotage', 'droit' => 'admin'],
        ['cle' => 'qualite', 'titre' => 'nav.quality', 'quoi' => 'quoi.quality', 'href' => '/qualite', 'icone' => 'check', 'groupe' => 'pilotage', 'droit' => 'encadrement'],
        ['cle' => 'organigramme', 'titre' => 'nav.orgChart', 'quoi' => 'quoi.orgChart', 'href' => '/organigramme', 'icone' => 'network', 'groupe' => 'pilotage', 'droit' => 'tous'],
        ['cle' => 'personnel', 'titre' => 'nav.personnel', 'quoi' => 'quoi.personnel', 'href' => '/admin#personnel', 'icone' => 'users', 'groupe' => 'personnel', 'droit' => 'admin', 'onglet' => 'personnel'],
        ['cle' => 'rh', 'titre' => 'nav.hr', 'quoi' => 'quoi.hr', 'href' => '/admin#rh', 'icone' => 'user-check', 'groupe' => 'personnel', 'droit' => 'admin', 'onglet' => 'rh'],
        ['cle' => 'parcours', 'titre' => 'nav.careerPaths', 'quoi' => 'quoi.careerPaths', 'href' => '/parcours', 'icone' => 'trending', 'groupe' => 'personnel', 'droit' => 'rh'],
        ['cle' => 'sante', 'titre' => 'nav.healthSafety', 'quoi' => 'quoi.healthSafety', 'href' => '/sante-securite', 'icone' => 'heart', 'groupe' => 'personnel', 'droit' => 'rh'],
        ['cle' => 'planning', 'titre' => 'nav.planning', 'quoi' => 'quoi.planning', 'href' => '/planning', 'icone' => 'clock', 'groupe' => 'personnel', 'droit' => 'tous'],
        ['cle' => 'organisation', 'titre' => 'nav.organisation', 'quoi' => 'quoi.organisation', 'href' => '/admin#organisation', 'icone' => 'building', 'groupe' => 'personnel', 'droit' => 'admin', 'onglet' => 'organisation'],
        ['cle' => 'affectations', 'titre' => 'nav.assignments', 'quoi' => 'quoi.assignments', 'href' => '/admin#affectations', 'icone' => 'link', 'groupe' => 'personnel', 'droit' => 'admin', 'onglet' => 'affectations'],
        ['cle' => 'paie', 'titre' => 'nav.payroll', 'quoi' => 'quoi.payroll', 'href' => '/paie', 'icone' => 'payslip', 'groupe' => 'personnel', 'droit' => 'rh', 'module' => 'paie'],
        ['cle' => 'coffre', 'titre' => 'nav.vaultManage', 'quoi' => 'quoi.vaultManage', 'href' => '/coffre-fort/gestion', 'icone' => 'safe', 'groupe' => 'personnel', 'droit' => 'rh'],
        ['cle' => 'gestion', 'titre' => 'nav.gestion', 'quoi' => 'quoi.gestion', 'href' => '/gestion', 'icone' => 'euro', 'groupe' => 'gestion', 'droit' => 'gestion'],
        ['cle' => 'pieces', 'titre' => 'nav.intake', 'quoi' => 'quoi.intake', 'href' => '/pieces', 'icone' => 'receipt', 'groupe' => 'gestion', 'droit' => 'gestion'],
        ['cle' => 'projets', 'titre' => 'nav.projects', 'quoi' => 'quoi.projects', 'href' => '/projets', 'icone' => 'briefcase', 'groupe' => 'gestion', 'droit' => 'tous'],
        ['cle' => 'flotte', 'titre' => 'nav.fleet', 'quoi' => 'quoi.fleet', 'href' => '/flotte', 'icone' => 'truck', 'groupe' => 'gestion', 'droit' => 'gestion'],
        ['cle' => 'stock', 'titre' => 'nav.stock', 'quoi' => 'quoi.stock', 'href' => '/stock', 'icone' => 'box', 'groupe' => 'gestion', 'droit' => 'gestion', 'module' => 'stock'],
        ['cle' => 'comptabilite', 'titre' => 'nav.accounting', 'quoi' => 'quoi.accounting', 'href' => '/comptabilite', 'icone' => 'ledger', 'groupe' => 'gestion', 'droit' => 'gestion', 'module' => 'comptabilite'],
        ['cle' => 'tresorerie', 'titre' => 'nav.treasury', 'quoi' => 'quoi.treasury', 'href' => '/tresorerie', 'icone' => 'bank', 'groupe' => 'gestion', 'droit' => 'gestion', 'module' => 'tresorerie'],
        ['cle' => 'immobilisations', 'titre' => 'nav.assets', 'quoi' => 'quoi.assets', 'href' => '/immobilisations', 'icone' => 'chart', 'groupe' => 'gestion', 'droit' => 'gestion', 'module' => 'immobilisations'],
        ['cle' => 'einvoicing', 'titre' => 'nav.eInvoicing', 'quoi' => 'quoi.eInvoicing', 'href' => '/facturation-electronique', 'icone' => 'send', 'groupe' => 'gestion', 'droit' => 'gestion', 'module' => 'facturation-electronique'],
        ['cle' => 'support', 'titre' => 'nav.support', 'quoi' => 'quoi.support', 'href' => '/support', 'icone' => 'lifebuoy', 'groupe' => 'relation', 'droit' => 'tous'],
        ['cle' => 'connaissances', 'titre' => 'nav.knowledge', 'quoi' => 'quoi.knowledge', 'href' => '/base-de-connaissances', 'icone' => 'book', 'groupe' => 'relation', 'droit' => 'tous'],
        ['cle' => 'demandes', 'titre' => 'nav.internalRequests', 'quoi' => 'quoi.internalRequests', 'href' => '/demandes', 'icone' => 'inbox', 'groupe' => 'relation', 'droit' => 'tous'],
        ['cle' => 'accueil', 'titre' => 'nav.frontDesk', 'quoi' => 'quoi.frontDesk', 'href' => '/accueil', 'icone' => 'bell', 'groupe' => 'relation', 'droit' => 'rh'],
        ['cle' => 'evenements', 'titre' => 'nav.events', 'quoi' => 'quoi.events', 'href' => '/evenements', 'icone' => 'calendar', 'groupe' => 'relation', 'droit' => 'tous'],
        ['cle' => 'actualites', 'titre' => 'nav.news', 'quoi' => 'quoi.news', 'href' => '/admin#actualites', 'icone' => 'megaphone', 'groupe' => 'relation', 'droit' => 'admin', 'onglet' => 'actualites'],
        ['cle' => 'crm', 'titre' => 'nav.crm', 'quoi' => 'quoi.crm', 'href' => '/crm', 'icone' => 'handshake', 'groupe' => 'relation', 'droit' => 'gestion', 'module' => 'crm'],
        ['cle' => 'informatique', 'titre' => 'nav.it', 'quoi' => 'quoi.it', 'href' => '/informatique', 'icone' => 'server', 'groupe' => 'technique', 'droit' => 'informatique'],
        ['cle' => 'developpement', 'titre' => 'nav.development', 'quoi' => 'quoi.development', 'href' => '/developpement', 'icone' => 'code', 'groupe' => 'technique', 'droit' => 'informatique'],
        ['cle' => 'integrations', 'titre' => 'nav.integrations', 'quoi' => 'quoi.integrations', 'href' => '/integrations', 'icone' => 'plug', 'groupe' => 'technique', 'droit' => 'admin'],
        ['cle' => 'mises-a-jour', 'titre' => 'nav.updates', 'quoi' => 'quoi.updates', 'href' => '/mises-a-jour', 'icone' => 'download', 'groupe' => 'technique', 'droit' => 'admin'],
        ['cle' => 'sauvegardes', 'titre' => 'nav.backups', 'quoi' => 'quoi.backups', 'href' => '/sauvegardes', 'icone' => 'archive', 'groupe' => 'technique', 'droit' => 'admin'],
        ['cle' => 'journaux', 'titre' => 'nav.logs', 'quoi' => 'quoi.logs', 'href' => '/journaux', 'icone' => 'list', 'groupe' => 'technique', 'droit' => 'admin'],
        ['cle' => 'import', 'titre' => 'nav.dataImport', 'quoi' => 'quoi.dataImport', 'href' => '/import', 'icone' => 'upload', 'groupe' => 'technique', 'droit' => 'gestion'],
        ['cle' => 'securite', 'titre' => 'nav.security', 'quoi' => 'quoi.security', 'href' => '/securite', 'icone' => 'shield', 'groupe' => 'conformite', 'droit' => 'admin'],
        ['cle' => 'rgpd', 'titre' => 'nav.privacy', 'quoi' => 'quoi.privacy', 'href' => '/rgpd', 'icone' => 'lock', 'groupe' => 'conformite', 'droit' => 'admin'],
        ['cle' => 'juridique', 'titre' => 'nav.legal', 'quoi' => 'quoi.legal', 'href' => '/juridique', 'icone' => 'scale', 'groupe' => 'conformite', 'droit' => 'tous'],
        ['cle' => 'parapheur', 'titre' => 'nav.signing', 'quoi' => 'quoi.signing', 'href' => '/parapheur', 'icone' => 'pen', 'groupe' => 'conformite', 'droit' => 'tous'],
        ['cle' => 'outils', 'titre' => 'nav.tools', 'quoi' => 'quoi.tools', 'href' => '/admin#outils', 'icone' => 'tool', 'groupe' => 'reglages', 'droit' => 'admin', 'onglet' => 'outils'],
        ['cle' => 'modules', 'titre' => 'nav.modules', 'quoi' => 'quoi.modules', 'href' => '/admin#modules', 'icone' => 'plus', 'groupe' => 'reglages', 'droit' => 'admin', 'onglet' => 'modules'],
        ['cle' => 'applications', 'titre' => 'nav.appStore', 'quoi' => 'quoi.appStore', 'href' => '/applications', 'icone' => 'grid', 'groupe' => 'reglages', 'droit' => 'admin'],
        ['cle' => 'apparence', 'titre' => 'nav.appearance', 'quoi' => 'quoi.appearance', 'href' => '/admin#apparence', 'icone' => 'settings', 'groupe' => 'reglages', 'droit' => 'admin', 'onglet' => 'apparence'],
    ];

    /**
     * Qui peut ouvrir quoi.
     *
     * Un raccourci vers un écran qu'on ne peut pas ouvrir est une porte peinte
     * sur un mur : elle donne sur un refus. Chaque nom répond au garde de la
     * route correspondante, et un test le vérifie pour de bon.
     *
     * L'administration passe partout sauf là où c'est voulu — voir le référent
     * du dispositif d'alerte, absent de ce catalogue pour cette raison même.
     *
     * @param array<string,mixed> $qui
     */
    public static function autorise(array $qui, array $service): bool
    {
        $role = $qui['role'] ?? null;
        /*
         * Une section que le client n'a pas installée n'existe pas pour lui :
         * ni dans le rail, ni dans les tuiles, ni dans la recherche, et ses
         * routes répondent comme une adresse inventée. Absente de « qui », la
         * liste veut dire « on ne sait pas » et non « aucune » — les tests et
         * les écrans qui rendent le catalogue entier ne la fournissent pas.
         */
        $sections = $qui['sections'] ?? null;
        if (is_array($sections) && !in_array($service['groupe'], $sections, true)) {
            return false;
        }
        $modules = $qui['modules'] ?? null;
        if (isset($service['module']) && is_array($modules)
            && !in_array($service['module'], $modules, true)) {
            return false;
        }
        if ($role === 'admin') {
            return true;
        }
        return match ($service['droit']) {
            'tous' => true,
            'admin' => false,
            'gestion' => (bool) ($qui['finance'] ?? false),
            'rh' => (bool) ($qui['rh'] ?? false),
            'informatique' => (bool) ($qui['informatique'] ?? false),
            'encadrement' => (bool) ($qui['encadrement'] ?? false),
            // Le pilotage regarde toute l'entreprise : direction, gestion et RH.
            'pilotage' => (bool) (($qui['finance'] ?? false) || ($qui['rh'] ?? false)),
            default => throw new \RuntimeException('Droit inconnu : ' . $service['droit']),
        };
    }

    /**
     * Les entrées d'un groupe, dans l'ordre du catalogue.
     *
     * Sans « qui », on les rend toutes. Avec, on ne garde que celles qui
     * s'ouvriront.
     *
     * @param array<string,mixed>|null $qui
     * @return list<array<string,mixed>>
     */
    public static function servicesDe(string $groupe, ?array $qui = null): array
    {
        $dedans = array_values(array_filter(
            self::CATALOGUE,
            static fn (array $s): bool => $s['groupe'] === $groupe
        ));
        if ($qui === null) {
            return $dedans;
        }
        return array_values(array_filter(
            $dedans,
            static fn (array $s): bool => self::autorise($qui, $s)
        ));
    }

    /**
     * Le groupe d'un chemin, ou null — c'est ce qui décide du groupe ouvert.
     *
     * Le plus long préfixe gagne : /flotte/12 appartient à « flotte », pas à
     * « / ». À égalité, c'est le premier du catalogue — ce qui règle le cas de
     * /admin, que plusieurs onglets revendiquent à six caractères chacun : on
     * ouvre « personnel », l'onglet que la console ouvre elle-même quand aucune
     * ancre ne dit le contraire.
     */
    public static function groupeDuChemin(?string $chemin): ?string
    {
        $propre = explode('?', (string) ($chemin ?: '/'))[0];
        $propre = rtrim($propre, '/');
        if ($propre === '') {
            $propre = '/';
        }
        // Le tableau de bord d'une section appartient évidemment à sa section :
        // il n'est pas dans le catalogue, il le présente.
        if (preg_match('~^/section/([a-z-]+)$~', $propre, $m) === 1 && self::groupe($m[1]) !== null) {
            return $m[1];
        }
        $trouve = null;
        $longueur = -1;
        foreach (self::CATALOGUE as $service) {
            $prefixes = $service['prefixes'] ?? [explode('#', $service['href'])[0]];
            foreach ($prefixes as $prefixe) {
                if ($prefixe === '/') {
                    continue;
                }
                $dedans = $propre === $prefixe || str_starts_with($propre, $prefixe . '/');
                if ($dedans && strlen($prefixe) > $longueur) {
                    $trouve = $service['groupe'];
                    $longueur = strlen($prefixe);
                }
            }
        }
        return $trouve;
    }

    /** Le groupe existe-t-il ? Les écrans de section s'en servent avant de rendre. */
    public static function groupe(string $cle): ?array
    {
        foreach (self::GROUPES as $groupe) {
            if ($groupe['cle'] === $cle) {
                return $groupe;
            }
        }
        return null;
    }

    /** Les clés des groupes, dans l'ordre : ce que <html> porte pour le pli. */
    public static function clesDesGroupes(): array
    {
        return array_map(static fn (array $g): string => $g['cle'], self::GROUPES);
    }
}
