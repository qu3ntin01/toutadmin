<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Seize langues, le français pour référence.
 *
 * Une clé absente d'une traduction retombe sur le français plutôt que de
 * laisser un trou dans la page : une traduction incomplète dégrade l'affichage,
 * elle ne casse jamais l'écran.
 */
final class I18n
{
    public const LOCALES = [
        ['code' => 'fr', 'label' => 'Français', 'flag' => '🇫🇷', 'dir' => 'ltr'],
        ['code' => 'en', 'label' => 'English', 'flag' => '🇬🇧', 'dir' => 'ltr'],
        ['code' => 'es', 'label' => 'Español', 'flag' => '🇪🇸', 'dir' => 'ltr'],
        ['code' => 'de', 'label' => 'Deutsch', 'flag' => '🇩🇪', 'dir' => 'ltr'],
        ['code' => 'it', 'label' => 'Italiano', 'flag' => '🇮🇹', 'dir' => 'ltr'],
        ['code' => 'pt', 'label' => 'Português', 'flag' => '🇵🇹', 'dir' => 'ltr'],
        ['code' => 'nl', 'label' => 'Nederlands', 'flag' => '🇳🇱', 'dir' => 'ltr'],
        ['code' => 'pl', 'label' => 'Polski', 'flag' => '🇵🇱', 'dir' => 'ltr'],
        ['code' => 'ru', 'label' => 'Русский', 'flag' => '🇷🇺', 'dir' => 'ltr'],
        ['code' => 'tr', 'label' => 'Türkçe', 'flag' => '🇹🇷', 'dir' => 'ltr'],
        ['code' => 'ar', 'label' => 'العربية', 'flag' => '🇸🇦', 'dir' => 'rtl'],
        ['code' => 'hi', 'label' => 'हिन्दी', 'flag' => '🇮🇳', 'dir' => 'ltr'],
        ['code' => 'zh', 'label' => '中文', 'flag' => '🇨🇳', 'dir' => 'ltr'],
        ['code' => 'ja', 'label' => '日本語', 'flag' => '🇯🇵', 'dir' => 'ltr'],
        ['code' => 'ko', 'label' => '한국어', 'flag' => '🇰🇷', 'dir' => 'ltr'],
        ['code' => 'vi', 'label' => 'Tiếng Việt', 'flag' => '🇻🇳', 'dir' => 'ltr'],
    ];

    public const DEFAULT_LOCALE = 'fr';

    /**
     * Les statuts sont stockés en français dans la base — c'est la langue de
     * référence du produit. Cette table donne leur clé de traduction, pour
     * qu'un écran en coréen n'affiche pas « Clôturée » au milieu d'une phrase.
     */
    public const STATUS_KEYS = [
        'À faire' => 'status.todo',
        'À remettre' => 'status.toHandOver',
        'À traiter' => 'status.toProcess',
        'À verser' => 'status.due',
        'Abandonné' => 'status.abandoned',
        'Abandonnée' => 'status.abandoned',
        'Abonnement' => 'status.subscription',
        'Absent' => 'status.absent',
        'Accepté' => 'status.accepted',
        'Acquisition' => 'status.acquisition',
        'Actif' => 'common.active',
        'Activité accessoire' => 'status.sideActivity',
        'Administrateur' => 'status.administrator',
        'Affecté' => 'status.assigned',
        'Adoptée' => 'status.adopted',
        'Annuel' => 'status.yearly',
        'Annulé' => 'status.cancelled',
        'Annulée' => 'status.cancelled',
        'Approuvée' => 'status.approved',
        'Archivé' => 'status.archived',
        'Assemblée générale extraordinaire' => 'status.agmExtraordinary',
        'Assemblée générale mixte' => 'status.agmCombined',
        'Assemblée générale ordinaire' => 'status.agmOrdinary',
        'Assurance' => 'status.insurance',
        'Atelier' => 'status.workshop',
        'Atteint' => 'status.reached',
        'Attestation de vigilance' => 'status.vigilanceCertificate',
        'Autre' => 'status.other',
        'Brouillon' => 'status.draft',
        'Cadeau' => 'status.gift',
        'Cadrage' => 'status.framing',
        'Candidatures' => 'status.applications',
        'Cédé' => 'status.disposed',
        'Certification' => 'status.certification',
        'Cession' => 'status.transfer',
        'Client' => 'status.customer',
        'Client et fournisseur' => 'status.customerSupplier',
        'Clos' => 'status.closed',
        'Clôturé' => 'status.closed',
        'Clôturée' => 'status.closed',
        'Commandée' => 'status.ordered',
        'Commissaire aux comptes' => 'status.statutoryAuditor',
        'Complet' => 'status.full',
        'Confirmée' => 'status.confirmed',
        'Convivialité' => 'status.social',
        'Convoquée' => 'status.convened',
        'Coordonnées bancaires' => 'status.bankDetails',
        'Corruption' => 'status.corruption',
        'Critique' => 'status.critical',
        'Déclarée' => 'status.declared',
        'Demandée' => 'status.requested',
        'Développement' => 'status.envDevelopment',
        'Déclaré' => 'status.declared',
        'Développement interne' => 'status.inHouse',
        'Directeur général' => 'status.ceo',
        'Directeur général délégué' => 'status.deputyCeo',
        'Discrimination' => 'status.discrimination',
        'Disponible' => 'status.available',
        'Données personnelles' => 'status.personalData',
        'Écartée' => 'status.discarded',
        'Échec' => 'status.failed',
        'Échouée' => 'status.failed',
        'Échu' => 'status.matured',
        'Émise' => 'status.issued',
        'Échue' => 'status.matured',
        'En attente' => 'status.pending',
        'En construction' => 'status.underConstruction',
        'En cours' => 'status.running',
        'En instruction' => 'status.investigating',
        'En maintenance' => 'status.underMaintenance',
        'En pause' => 'status.paused',
        'En réparation' => 'status.underRepair',
        'En revue' => 'status.inReview',
        'En service' => 'status.inService',
        'En test' => 'status.inTesting',
        'En traitement' => 'status.processing',
        'En vigueur' => 'status.inForce',
        'Environnement' => 'status.environment',
        'Envoyé' => 'status.sent',
        'Envoyée' => 'status.sent',
        'Expiré' => 'status.expired',
        'Facturée' => 'status.invoiced',
        'Examiné' => 'status.reviewed',
        'Faite' => 'status.done',
        'Formation' => 'status.training',
        'Fournisseur' => 'status.supplier',
        'Fraude' => 'status.fraud',
        'Gérant' => 'status.managingPartner',
        'Gestion' => 'status.stageManagement',
        'Gestionnaire' => 'status.steward',
        'Harcèlement' => 'status.harassment',
        'Immobilisé' => 'status.grounded',
        'Importante' => 'status.important',
        'Inscrit' => 'status.enrolled',
        'Inscrite' => 'status.enrolled',
        'Intérêt financier' => 'status.financialInterest',
        'Invitation' => 'status.invitation',
        'Irrecevable' => 'status.inadmissible',
        'Kbis' => 'status.companyExtract',
        'Licence perpétuelle' => 'status.perpetualLicence',
        'Liste d\'attente' => 'status.waitlisted',
        'Livré' => 'status.delivered',
        'Livrée' => 'status.delivered',
        'Lien familial' => 'status.familyTie',
        'Logiciel libre' => 'status.openSource',
        'Maîtrisé' => 'status.mitigated',
        'Majeur' => 'status.major',
        'Manager' => 'status.stageManager',
        'Mandat externe' => 'status.externalMandate',
        'Membre du conseil' => 'status.boardMember',
        'Mensuel' => 'status.monthly',
        'Mesure prise' => 'status.measureTaken',
        'Mineur' => 'status.minor',
        'Mise en demeure' => 'status.formalNotice',
        'Offert' => 'status.giftGiven',
        'Ouvert' => 'status.open',
        'Ouverte' => 'status.open',
        'Payée' => 'status.paid',
        'Planifié' => 'status.scheduled',
        'Planifiée' => 'status.scheduled',
        'Personne morale' => 'status.legalPerson',
        'Personne physique' => 'status.naturalPerson',
        'Ponctuel' => 'status.oneOff',
        'Pourvu' => 'status.filled',
        'Préproduction' => 'status.envPreprod',
        'Présent' => 'status.present',
        'Président' => 'status.chairperson',
        'Production' => 'status.envProduction',
        'Rappel' => 'status.reminder',
        'Réalisé' => 'status.completed',
        'Recette' => 'status.envStaging',
        'Recevable' => 'status.admissible',
        'Reçue' => 'status.received',
        'Reçu' => 'status.giftReceived',
        'Reçue partiellement' => 'status.partlyReceived',
        'Réformé' => 'status.writtenOff',
        'Refusé' => 'status.refused',
        'Refusée' => 'status.refused',
        'Réduction' => 'status.capitalReduction',
        'Rejetée' => 'status.rejected',
        'Relance' => 'status.chaser',
        'Remboursée' => 'status.reimbursed',
        'Remis' => 'status.handedOver',
        'Résilié' => 'status.terminated',
        'Résolu' => 'status.resolved',
        'Retiré' => 'status.withdrawn',
        'Retirée' => 'status.withdrawn',
        'Restitué' => 'status.giftReturned',
        'Réunion générale' => 'status.allHands',
        'Révoqué' => 'status.revoked',
        'Révoquée' => 'status.revoked',
        'Salon' => 'status.tradeShow',
        'Secondaire' => 'status.secondary',
        'Sécurité des personnes' => 'status.peopleSafety',
        'Séminaire' => 'status.seminar',
        'Signé' => 'status.signed',
        'Souscription' => 'status.subscription',
        'Suspendue' => 'status.suspended',
        'Tenu' => 'status.held',
        'Tenue' => 'status.held',
        'Terminée' => 'status.finished',
        'Trimestriel' => 'status.quarterly',
        'Utilisateur' => 'status.user',
        'Validée' => 'status.validated',
        'Vitale' => 'status.vital',
        'Vote' => 'status.voting',
        'Voyage' => 'status.trip',
    ];

    private static array $dictionaries = [];
    private static string $current = self::DEFAULT_LOCALE;

    public static function codes(): array
    {
        return array_column(self::LOCALES, 'code');
    }

    public static function isSupported(?string $code): bool
    {
        return $code !== null && in_array($code, self::codes(), true);
    }

    public static function info(?string $code = null): array
    {
        $code ??= self::$current;
        foreach (self::LOCALES as $locale) {
            if ($locale['code'] === $code) {
                return $locale;
            }
        }
        return self::LOCALES[0];
    }

    public static function use(?string $code): void
    {
        self::$current = self::isSupported($code) ? (string) $code : self::DEFAULT_LOCALE;
    }

    public static function current(): string
    {
        return self::$current;
    }

    public static function dictionary(string $code): array
    {
        if (!isset(self::$dictionaries[$code])) {
            $file = APP_DIR . '/locales/' . $code . '.php';
            self::$dictionaries[$code] = is_file($file) ? (array) require $file : [];
        }
        return self::$dictionaries[$code];
    }

    /**
     * Traduit une clé, avec substitution de {paramètres}. Une clé inconnue est
     * rendue telle quelle : le texte manquant se voit, sans page blanche.
     */
    public static function translate(string $key, array $params = [], ?string $code = null): string
    {
        $code ??= self::$current;
        $value = self::dictionary($code)[$key]
            ?? self::dictionary(self::DEFAULT_LOCALE)[$key]
            ?? $key;

        foreach ($params as $name => $replacement) {
            $value = str_replace('{' . $name . '}', (string) $replacement, $value);
        }
        return $value;
    }

    /**
     * Libellé affichable d'un statut stocké. Une valeur inconnue — un statut
     * ajouté par l'exploitant, par exemple — ressort telle quelle : mieux vaut
     * un mot français qu'une case vide.
     */
    public static function status(?string $value, ?string $code = null): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $key = self::STATUS_KEYS[$value] ?? null;
        return $key === null ? $value : self::translate($key, [], $code);
    }

    /**
     * La langue d'une requête : le choix de la personne d'abord, puis celui de
     * l'instance, puis l'en-tête du navigateur.
     */
    public static function negotiate(?string $userLocale, ?string $instanceLocale, ?string $header): string
    {
        if (self::isSupported($userLocale)) {
            return (string) $userLocale;
        }
        if (self::isSupported($instanceLocale)) {
            return (string) $instanceLocale;
        }
        foreach (explode(',', (string) $header) as $part) {
            $code = strtolower(trim(explode(';', $part)[0]));
            $short = substr($code, 0, 2);
            if (self::isSupported($short)) {
                return $short;
            }
        }
        return self::DEFAULT_LOCALE;
    }
}
