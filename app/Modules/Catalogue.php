<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Settings;

/**
 * Modules optionnels.
 *
 * Chacun est éteint par défaut : une instance n'embarque que ce dont elle a
 * besoin. Le champ « caveat » dit ce que le module ne garantit pas, et il est
 * affiché à l'activation plutôt que caché dans une documentation.
 */
final class Catalogue
{
    public const MODULES = [
        [
            'key' => 'comptabilite',
            'label' => 'Comptabilité',
            'href' => '/comptabilite',
            'description' => "Plan comptable, journaux, écritures équilibrées, grand livre et balance. Une facture peut être passée en écriture d'un clic.",
            'caveat' => "Tenue de comptes interne. Ni liasse fiscale, ni télétransmission : l'export de la balance alimente votre expert-comptable.",
        ],
        [
            'key' => 'paie',
            'label' => 'Moteur de paie',
            'href' => '/paie',
            'description' => 'Barèmes de cotisations paramétrables, calcul du brut au net, part patronale et coût employeur, bulletin détaillé.',
            'caveat' => "Les taux sont ceux que vous saisissez : ils sont pré-remplis à titre indicatif et doivent être vérifiés par votre gestionnaire de paie. Aucune DSN n'est produite.",
        ],
        [
            'key' => 'facturation-electronique',
            'label' => 'Facturation électronique',
            'href' => '/facturation-electronique',
            'description' => 'Contrôle des mentions obligatoires EN 16931 et export du XML CII (UN/CEFACT) de chaque facture client.',
            'caveat' => "Le XML produit est la charge utile réglementaire. L'encapsulation dans un PDF/A-3 (Factur-X) et le dépôt sur une plateforme agréée restent à faire par l'outil de votre choix.",
        ],
        [
            'key' => 'stock',
            'label' => 'Stock et achats',
            'href' => '/stock',
            'description' => "Articles, mouvements d'entrée et de sortie, seuil d'alerte, et demandes d'achat validées par le manager puis par la gestion.",
            'caveat' => 'Stock mono-dépôt, valorisé au dernier prix unitaire connu. Ni inventaire tournant, ni valorisation FIFO ou CUMP.',
        ],
        [
            'key' => 'tresorerie',
            'label' => 'Trésorerie',
            'href' => '/tresorerie',
            'description' => 'Comptes bancaires, mouvements, rapprochement des encaissements avec les factures, et projection de trésorerie à douze semaines.',
            'caveat' => "Saisie et import manuels : aucune connexion bancaire (DSP2) n'est établie. Le solde est celui que vous avez saisi, pas celui de la banque.",
        ],
        [
            'key' => 'immobilisations',
            'label' => 'Immobilisations',
            'href' => '/immobilisations',
            'description' => "Registre des immobilisations, tableaux d'amortissement linéaire et dégressif, valeur nette comptable et dotation de l'exercice.",
            'caveat' => "Les tableaux sont un outil de suivi : le rattachement comptable, les composants et les dérogatoires restent l'affaire de votre expert-comptable.",
        ],
        [
            'key' => 'crm',
            'label' => 'CRM commercial',
            'href' => '/crm',
            'description' => 'Contacts, pipeline des opportunités, devis convertibles en facture, et relances à échéance.',
            'caveat' => "Suivi commercial interne : pas de synchronisation avec une messagerie ni d'automatisation marketing.",
        ],
    ];

    public static function keys(): array
    {
        return array_column(self::MODULES, 'key');
    }

    public static function isEnabled(string $key): bool
    {
        return Settings::get('module.' . $key) === '1';
    }

    public static function setEnabled(string $key, bool $enabled): bool
    {
        if (!in_array($key, self::keys(), true)) {
            return false;
        }
        Settings::set('module.' . $key, $enabled ? '1' : '0');
        return true;
    }

    /** La liste complète, chacun avec son état : c'est l'écran d'activation. */
    public static function list(): array
    {
        return array_map(
            static fn (array $module): array => $module + ['enabled' => self::isEnabled($module['key'])],
            self::MODULES
        );
    }

    public static function enabled(): array
    {
        return array_values(array_filter(self::list(), static fn (array $m): bool => $m['enabled']));
    }

    public static function byKey(string $key): ?array
    {
        foreach (self::MODULES as $module) {
            if ($module['key'] === $key) {
                return $module;
            }
        }
        return null;
    }
}
