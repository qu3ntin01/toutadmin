<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Les sections : ce qu'on a choisi d'installer, et ce que chacune règle.
 *
 * Le pendant exact de « src/sections-reglages.js » de l'édition Node : mêmes
 * clés de réglage, mêmes champs, mêmes bornes, mêmes valeurs par défaut. Les
 * deux éditions doivent régler le même produit — un champ qui existe ici et
 * pas là est une promesse que l'une des deux ne tient pas.
 *
 * Trois choses vivent ici, et elles se tiennent :
 *
 * 1. UNE SECTION S'INSTALLE OU NE S'INSTALLE PAS. Une entreprise de six
 *    personnes n'a pas de conformité à tenir ni de flotte à suivre ; lui
 *    imposer sept métiers dans son rail, c'est lui demander d'ignorer les cinq
 *    qui ne la concernent pas — à chaque écran, tous les jours.
 *
 * 2. UNE SECTION SE CONFIGURE À SA PREMIÈRE OUVERTURE. Les réglages qui la
 *    gouvernent existaient déjà : ils étaient dispersés dans six onglets de la
 *    console, et on les découvrait le jour où un défaut faisait mal.
 *
 * 3. CHAQUE SECTION A SES RÉGLAGES, et ce sont les vrais. Rien ici n'est
 *    décoratif : chaque champ est lu par du code qui s'en sert. La plupart
 *    renvoient à un réglage qui existait déjà — un second réglage qui
 *    doublerait le premier finit par lui contredire, et l'on ne sait plus
 *    lequel décide.
 *
 * Ce fichier ne remplace pas « Config.php », qui lit les réglages de
 * déploiement hors de la base. Ici, ce sont des réglages métier : ils vivent
 * dans la base, avec le reste. Il ne remplace pas non plus « Catalogue.php » :
 * un module est une option de l'éditeur, qui s'achète ; une section est un pan
 * du produit, que le client décide d'installer.
 */
final class SectionsReglages
{
    /** Une section qu'on ne peut pas désinstaller : sans elle, rien ne se rallume. */
    public const OBLIGATOIRES = ['reglages'];

    /** Témoin du rattrapage : voir « rattraperInstanceExistante ». */
    private const TEMOIN_RATTRAPAGE = 'sections.rattrapees';

    /** @return list<string> */
    public static function cles(): array
    {
        return Sections::clesDesGroupes();
    }

    private static function cleActive(string $section): string
    {
        return 'section.' . $section . '.active';
    }

    private static function cleConfiguree(string $section): string
    {
        return 'section.' . $section . '.configuree';
    }

    /**
     * Le choix se lit à l'endroit, pas à l'envers.
     *
     * Écrire « 1 » pour les sections retenues et rien pour les autres aurait
     * voulu dire qu'une instance posée avant cette version — où le réglage
     * n'existe pas — n'a aucune section. On stocke donc « 0 » pour refuser, et
     * l'absence vaut « oui » : une instance déjà installée garde tout ce
     * qu'elle avait, sans migration ni intervention.
     */
    public static function estActive(string $section): bool
    {
        if (in_array($section, self::OBLIGATOIRES, true)) {
            return true;
        }
        if (!in_array($section, self::cles(), true)) {
            return false;
        }
        return Settings::get(self::cleActive($section)) !== '0';
    }

    /** @return list<string> */
    public static function actives(): array
    {
        return array_values(array_filter(self::cles(), static fn (string $c): bool => self::estActive($c)));
    }

    /**
     * Poser ou retirer une section.
     *
     * Retirer n'efface rien : les données restent en base, et la reposer les
     * retrouve intactes. C'est une décision d'affichage et d'accès, pas une
     * suppression — un client qui se trompe de case ne doit pas perdre ses
     * factures.
     */
    public static function activer(string $section, bool $voulue): bool
    {
        if (!in_array($section, self::cles(), true)) {
            return false;
        }
        if (!$voulue && in_array($section, self::OBLIGATOIRES, true)) {
            return false;
        }
        Settings::set(self::cleActive($section), $voulue ? '1' : '0');
        return true;
    }

    /**
     * Écrit le choix d'installation d'un coup. Les obligatoires s'ajoutent
     * d'office : elles ne sont pas offertes, les recevoir ne change rien.
     *
     * @param list<string> $choisies
     */
    public static function poserChoix(array $choisies): void
    {
        $voulues = array_unique(array_merge($choisies, self::OBLIGATOIRES));
        $valeurs = [];
        foreach (self::cles() as $cle) {
            $valeurs[self::cleActive($cle)] = in_array($cle, $voulues, true) ? '1' : '0';
        }
        Settings::setMany($valeurs);
    }

    // ------------------------------------------------------------- réglages

    /**
     * Les champs d'une section.
     *
     *   cle      son nom dans les adresses et dans le dictionnaire ;
     *   type     « nombre », « bool », « choix », « texte », « mois » ;
     *   reglage  le réglage existant qu'il pilote. Absent, le champ vit sous
     *            « section.<section>.<cle> » ;
     *   poser    quand écrire demande de passer par le module qui tient le
     *            réglage — la devise valide son code, la palette la sienne.
     *
     * « options » est le nom d'une méthode et non une liste : les devises, les
     * langues et les palettes vivent dans des modules qui lisent la base.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public static function champsParSection(): array
    {
        return [
            'pilotage' => [
                ['cle' => 'horizon_jours', 'type' => 'nombre', 'defaut' => '45', 'min' => 7, 'max' => 365],
                ['cle' => 'exercice_mois', 'type' => 'mois', 'defaut' => '1'],
                ['cle' => 'seuil_impayes', 'type' => 'nombre', 'defaut' => '0', 'min' => 0, 'max' => 100000000],
            ],
            'personnel' => [
                ['cle' => 'conges_annuels', 'reglage' => 'annual_leave_days', 'type' => 'nombre', 'defaut' => '25', 'min' => 0, 'max' => 60],
                ['cle' => 'plafond_paie', 'reglage' => 'payroll_ceiling', 'type' => 'nombre', 'defaut' => '3925', 'min' => 0, 'max' => 1000000],
            ],
            'gestion' => [
                ['cle' => 'devise', 'reglage' => 'currency.base', 'type' => 'choix', 'defaut' => 'EUR', 'options' => 'devises', 'poser' => 'poserDevise'],
                ['cle' => 'delai_paiement', 'type' => 'nombre', 'defaut' => '30', 'min' => 0, 'max' => 365],
            ],
            'relation' => [
                ['cle' => 'delai_critique', 'type' => 'nombre', 'defaut' => '2', 'min' => 1, 'max' => 720],
                ['cle' => 'delai_haute', 'type' => 'nombre', 'defaut' => '8', 'min' => 1, 'max' => 720],
                ['cle' => 'delai_normale', 'type' => 'nombre', 'defaut' => '24', 'min' => 1, 'max' => 720],
                ['cle' => 'delai_basse', 'type' => 'nombre', 'defaut' => '72', 'min' => 1, 'max' => 720],
            ],
            'technique' => [
                ['cle' => 'sauvegarde_active', 'reglage' => 'backup_enabled', 'type' => 'bool', 'defaut' => '1'],
                ['cle' => 'sauvegarde_intervalle', 'reglage' => 'backup_interval_minutes', 'type' => 'nombre', 'defaut' => '60', 'min' => 15, 'max' => 1440],
                ['cle' => 'sauvegarde_gardees', 'reglage' => 'backup_keep', 'type' => 'nombre', 'defaut' => '24', 'min' => 2, 'max' => 500],
                ['cle' => 'maj_canal', 'reglage' => 'update_channel', 'type' => 'choix', 'defaut' => 'stable', 'options' => 'canaux'],
                ['cle' => 'maj_mode', 'reglage' => 'update_mode', 'type' => 'choix', 'defaut' => 'avertir', 'options' => 'modesMaj'],
            ],
            'conformite' => [
                ['cle' => 'facteur_admin', 'reglage' => 'require_2fa_admin', 'type' => 'bool', 'defaut' => '0'],
                ['cle' => 'facteur_tous', 'reglage' => 'require_2fa_all', 'type' => 'bool', 'defaut' => '0'],
                ['cle' => 'age_mot_de_passe', 'reglage' => 'password_max_age_days', 'type' => 'nombre', 'defaut' => '0', 'min' => 0, 'max' => 3650],
                ['cle' => 'retention_audit', 'reglage' => 'audit_retention_days', 'type' => 'nombre', 'defaut' => '365', 'min' => 30, 'max' => 3650],
                ['cle' => 'retention_corbeille', 'reglage' => 'trash_retention_days', 'type' => 'nombre', 'defaut' => '30', 'min' => 0, 'max' => 3650],
            ],
            'reglages' => [
                ['cle' => 'nom_entreprise', 'reglage' => 'company_name', 'type' => 'texte', 'defaut' => 'Toutadmin', 'max' => 120],
                ['cle' => 'langue', 'reglage' => 'default_locale', 'type' => 'choix', 'defaut' => 'fr', 'options' => 'langues'],
                ['cle' => 'palette', 'reglage' => 'theme_palette', 'type' => 'choix', 'defaut' => 'lavande', 'options' => 'palettes', 'poser' => 'poserPalette'],
            ],
        ];
    }

    /** @return list<array{valeur:string,libelle?:string}> */
    private static function options(string $nom): array
    {
        return match ($nom) {
            'devises' => array_map(
                static fn (array $c): array => ['valeur' => $c['code'], 'libelle' => $c['code'] . ' — ' . $c['label']],
                \App\Modules\Currency::CURRENCIES
            ),
            'langues' => array_map(
                static fn (array $l): array => ['valeur' => $l['code'], 'libelle' => $l['label']],
                I18n::LOCALES
            ),
            'palettes' => array_map(
                static fn (array $p): array => ['valeur' => $p['key'], 'libelle' => $p['label']],
                \App\Modules\Themes::PALETTES
            ),
            'canaux' => [['valeur' => 'stable'], ['valeur' => 'beta']],
            'modesMaj' => [['valeur' => 'avertir'], ['valeur' => 'auto']],
            default => [],
        };
    }

    private static function poser(string $nom, string $valeur): void
    {
        match ($nom) {
            'poserDevise' => \App\Modules\Currency::setBase($valeur),
            'poserPalette' => \App\Modules\Themes::set($valeur),
            default => null,
        };
    }

    /** @return list<array<string,mixed>> */
    public static function champsDe(string $section): array
    {
        $rendus = [];
        foreach (self::champsParSection()[$section] ?? [] as $champ) {
            $champ['options'] = isset($champ['options']) ? self::options((string) $champ['options']) : null;
            $champ['valeur'] = self::valeur($section, (string) $champ['cle']);
            $rendus[] = $champ;
        }
        return $rendus;
    }

    /** @return array<string,mixed>|null */
    public static function definition(string $section, string $cle): ?array
    {
        foreach (self::champsParSection()[$section] ?? [] as $champ) {
            if ($champ['cle'] === $cle) {
                return $champ;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $champ */
    private static function cleDe(string $section, array $champ): string
    {
        return isset($champ['reglage']) ? (string) $champ['reglage'] : 'section.' . $section . '.' . $champ['cle'];
    }

    /** La valeur en place, ou le défaut du champ si rien n'a été posé. */
    public static function valeur(string $section, string $cle): string
    {
        $champ = self::definition($section, $cle);
        if ($champ === null) {
            return '';
        }
        $pose = Settings::get(self::cleDe($section, $champ));
        return $pose === '' ? (string) $champ['defaut'] : $pose;
    }

    /** Un nombre, toujours dans ses bornes : le reste du code n'a pas à s'en méfier. */
    public static function nombre(string $section, string $cle): int
    {
        $champ = self::definition($section, $cle);
        if ($champ === null) {
            return 0;
        }
        $brut = self::valeur($section, $cle);
        $valeur = is_numeric($brut) ? (int) round((float) $brut) : (int) $champ['defaut'];
        if (isset($champ['min'])) {
            $valeur = max((int) $champ['min'], $valeur);
        }
        if (isset($champ['max'])) {
            $valeur = min((int) $champ['max'], $valeur);
        }
        return $valeur;
    }

    public static function bool(string $section, string $cle): bool
    {
        return self::valeur($section, $cle) === '1';
    }

    /**
     * Nettoie une valeur soumise. Rend null quand elle est irrécupérable —
     * l'écran redemande plutôt que d'écrire n'importe quoi. Un nombre hors
     * bornes se ramène dans ses bornes : l'intention est claire, et refuser
     * « 10 000 jours de congés » n'apprend rien de plus que de le ramener au
     * maximum affiché.
     *
     * @param array<string,mixed> $champ
     */
    public static function nettoyer(array $champ, mixed $brut): ?string
    {
        $texte = trim((string) ($brut ?? ''));
        switch ($champ['type']) {
            case 'bool':
                return in_array($texte, ['1', 'on', 'true'], true) ? '1' : '0';
            case 'nombre':
                if (!is_numeric($texte)) {
                    return null;
                }
                $n = (int) round((float) $texte);
                if (isset($champ['min'])) {
                    $n = max((int) $champ['min'], $n);
                }
                if (isset($champ['max'])) {
                    $n = min((int) $champ['max'], $n);
                }
                return (string) $n;
            case 'mois':
                if (!ctype_digit($texte)) {
                    return null;
                }
                $n = (int) $texte;
                return ($n >= 1 && $n <= 12) ? (string) $n : null;
            case 'choix':
                $permis = array_column(self::options((string) ($champ['options'] ?? '')), 'valeur');
                return in_array($texte, $permis, true) ? $texte : null;
            case 'texte':
                return $texte === '' ? null : mb_substr($texte, 0, (int) ($champ['max'] ?? 200));
            default:
                return null;
        }
    }

    /**
     * Écrit ce qu'on lui donne, et rend les champs refusés.
     *
     * Ce qui n'est pas dans le corps n'est pas touché — l'assistant avance par
     * étapes, et chaque étape ne porte que ses champs. Une case à cocher
     * absente vaut « décochée » : c'est ainsi que les navigateurs envoient un
     * formulaire, et la lire autrement rendrait toute case impossible à
     * décocher.
     *
     * @param array<string,mixed> $corps
     * @return list<string>
     */
    public static function enregistrer(string $section, array $corps): array
    {
        $refuses = [];
        foreach (self::champsParSection()[$section] ?? [] as $champ) {
            $cle = (string) $champ['cle'];
            if (!array_key_exists($cle, $corps) && $champ['type'] !== 'bool') {
                continue;
            }
            $propre = self::nettoyer($champ, $corps[$cle] ?? null);
            if ($propre === null) {
                $refuses[] = $cle;
                continue;
            }
            if (isset($champ['poser'])) {
                self::poser((string) $champ['poser'], $propre);
            } else {
                Settings::set(self::cleDe($section, $champ), $propre);
            }
        }
        return $refuses;
    }

    // -------------------------------------------------- première ouverture

    public static function estConfiguree(string $section): bool
    {
        return Settings::get(self::cleConfiguree($section)) !== '';
    }

    public static function marquerConfiguree(string $section): void
    {
        Settings::set(self::cleConfiguree($section), gmdate('c'));
    }

    /**
     * Rouvrir l'assistant d'une section : il ne défait rien, les réglages en
     * place restent et l'assistant les représente tels quels.
     */
    public static function rouvrir(string $section): bool
    {
        if (!in_array($section, self::cles(), true)) {
            return false;
        }
        Settings::set(self::cleConfiguree($section), '');
        return true;
    }

    /**
     * Ce qui distingue « déjà là » de « fraîchement posée » n'est pas la date
     * d'installation : l'assistant l'écrit lui aussi, et une instance neuve
     * serait rattrapée dès son premier démarrage — donc jamais configurée.
     * C'est le témoin ci-dessous, que l'assistant pose en terminant.
     *
     * Restent les déploiements pilotés par variables d'environnement, qui n'ont
     * jamais vu d'assistant : un administrateur en base et pas de témoin, et
     * l'instance tournait déjà.
     */
    private static function instanceDejaLa(): bool
    {
        if (Settings::get('installed_at') !== '') {
            return true;
        }
        try {
            return (int) Db::value("SELECT COUNT(*) FROM users WHERE role = 'admin'") > 0;
        } catch (\Throwable) {
            // Base absente ou schéma pas encore posé : rien à rattraper.
            return false;
        }
    }

    /**
     * Une instance installée avant cette version a déjà ses réglages, posés à
     * la main dans les onglets de la console. Lui ouvrir sept assistants à la
     * première mise à jour serait lui redemander ce qu'elle a déjà répondu.
     */
    public static function rattraperInstanceExistante(): bool
    {
        if (Settings::get(self::TEMOIN_RATTRAPAGE) === '1') {
            return false;
        }
        if (!self::instanceDejaLa()) {
            return false;
        }
        $valeurs = [self::TEMOIN_RATTRAPAGE => '1'];
        $quand = gmdate('c');
        foreach (self::cles() as $cle) {
            if (Settings::get(self::cleConfiguree($cle)) === '') {
                $valeurs[self::cleConfiguree($cle)] = $quand;
            }
        }
        Settings::setMany($valeurs);
        return true;
    }

    /**
     * Posé par l'assistant d'installation en terminant : cette instance est née
     * avec les sections, et ses assistants de section doivent s'ouvrir.
     */
    public static function neeAvecLesSections(): void
    {
        Settings::set(self::TEMOIN_RATTRAPAGE, '1');
    }
}
