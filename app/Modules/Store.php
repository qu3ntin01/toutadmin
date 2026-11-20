<?php

declare(strict_types=1);

namespace App\Modules;

/**
 * La boutique d'applications, vue de l'instance.
 *
 * Le catalogue n'est pas ici. Il vit chez l'éditeur, et cette instance le
 * demande à chaque visite de l'écran : une réserve mal formulée, un prix qui
 * change, une application qui devient disponible se voient sans qu'on mette à
 * jour le produit. Garder une copie en base reviendrait à figer le catalogue au
 * jour de la dernière mise à jour, ce qui est exactement ce qu'on ne veut pas.
 *
 * L'adresse et la clé sont celles des mises à jour — même éditeur, même clé
 * d'instance. En demander une seconde paire ferait deux réglages à tenir
 * d'accord pour une seule relation.
 *
 * ── Les quatre états, et pourquoi l'écran les distingue ──────────────────
 *
 *   ouverte  : la rubrique est déjà là, livrée avec le produit. L'écran ouvre.
 *   allumee  : un module optionnel déjà débloqué. L'écran ouvre aussi.
 *   eteinte  : le code est livré mais éteint. L'écran allume.
 *   annoncee : la fiche existe, le code non. L'écran le dit, et n'offre rien.
 *   a-venir  : un module qu'une version plus récente apportera. Rien non plus.
 *
 * Montrer un bouton « allumer » sur les deux derniers ferait perdre une
 * demi-journée au premier qui clique, puis sa confiance. Le catalogue dit la
 * vérité ; l'écran ne la maquille pas.
 */
final class Store
{
    public const GENRES = ['incluse', 'module', 'bientot'];

    /** Une minute de répit : l'écran se recharge, l'éditeur n'a pas à le savoir. */
    private const CACHE_SECONDES = 60;

    /** @var array{at: int, catalogue: array}|null */
    private static ?array $cache = null;

    /**
     * Le transport, remplaçable par les tests.
     *
     * Sans cela, éprouver la boutique demanderait un éditeur sur le réseau : le
     * jour où il est en maintenance, la suite rougit et l'on cherche le bogue
     * chez soi.
     *
     * @var null|\Closure(string, string): array{status: int, body: string, error: string}
     */
    private static ?\Closure $transport = null;

    public static function poserTransport(?\Closure $transport): void
    {
        self::$transport = $transport;
        self::viderCache();
    }

    public static function viderCache(): void
    {
        self::$cache = null;
    }

    /**
     * Demander le catalogue à l'éditeur.
     *
     * Les pannes sont nommées plutôt que confondues : « pas configuré » se
     * corrige dans les réglages, « clé refusée » chez l'éditeur, « injoignable »
     * se réessaie. Les trois rendues comme une seule erreur laisseraient
     * l'administrateur sans rien à faire.
     */
    public static function catalogue(): array
    {
        $config = Updates::config();
        if ($config['url'] === '' || $config['key'] === '') {
            return ['ok' => false, 'reason' => 'not-configured'];
        }

        $url = $config['url'] . '/api/v1/applications?' . http_build_query(['edition' => Updates::EDITION]);
        $reponse = self::appeler($url, $config['key']);

        if ($reponse['status'] === 0) {
            Logs::record([
                'level' => 4, 'channel' => 'integration', 'event' => 'store.fetch.failed',
                'message' => 'Éditeur injoignable : ' . $reponse['error'],
            ]);
            return ['ok' => false, 'reason' => 'unreachable', 'detail' => $reponse['error']];
        }
        if ($reponse['status'] !== 200) {
            /*
             * Trois refus que l'administrateur peut corriger, et un quatrième
             * qui les rassemble tous. Rendre « http-418 » à l'écran donnerait
             * une clé de traduction qui n'existe pas, donc un code affiché tel
             * quel : le numéro va au journal, et l'écran reçoit un mot qu'on a
             * écrit dans seize langues.
             */
            $reason = match ($reponse['status']) {
                401 => 'key-refused',
                503 => 'catalogue-absent',
                default => 'refused',
            };
            Logs::record([
                'level' => 4, 'channel' => 'integration', 'event' => 'store.fetch.failed',
                'message' => "Catalogue refusé par l’éditeur (HTTP {$reponse['status']}).",
            ]);
            return ['ok' => false, 'reason' => $reason];
        }

        $lu = json_decode($reponse['body'], true);
        if (!is_array($lu)) {
            Logs::record([
                'level' => 4, 'channel' => 'integration', 'event' => 'store.fetch.failed',
                'message' => 'Catalogue illisible : la réponse n’est pas du JSON.',
            ]);
            return ['ok' => false, 'reason' => 'refused'];
        }

        return [
            'ok' => true,
            'rayons' => is_array($lu['rayons'] ?? null) ? $lu['rayons'] : [],
            // Où le client achète. Donnée par l'éditeur plutôt que réglée ici :
            // le jour où l'adresse change, aucune instance n'a à être modifiée.
            'espace_client' => rtrim((string) ($lu['espace_client'] ?? ''), '/'),
            'applications' => is_array($lu['applications'] ?? null) ? $lu['applications'] : [],
        ];
    }

    /**
     * L'état local d'une fiche : ce que l'instance en sait, elle.
     *
     * L'éditeur dit ce que l'application est ; l'instance dit où elle en est.
     * Une fiche « module » déjà allumée s'ouvre comme une « incluse » — c'est le
     * même geste pour l'utilisateur, et c'est ce qui compte à l'écran.
     */
    public static function etatLocal(array $fiche): array
    {
        $genre = in_array($fiche['genre'] ?? '', self::GENRES, true) ? (string) $fiche['genre'] : 'bientot';
        $href = (string) ($fiche['href'] ?? '');

        if ($genre === 'incluse') {
            return ['etat' => 'ouverte', 'href' => $href];
        }
        if ($genre === 'bientot') {
            return ['etat' => 'annoncee', 'href' => ''];
        }

        // Un module que cette version du produit ne connaît pas encore : la
        // fiche est publiée avant la version qui l'apporte. On l'annonce plutôt
        // que d'offrir un bouton qui appellerait une clé inconnue.
        $module = (string) ($fiche['module'] ?? '');
        if (!in_array($module, Catalogue::keys(), true)) {
            return ['etat' => 'a-venir', 'href' => ''];
        }

        /*
         * Une option payante que ce client n'a pas prise.
         *
         * C'est l'éditeur qui le dit — « acquise » vient de sa réponse, résolue
         * depuis la clé de cette instance. Le vérifier ici plutôt que de
         * l'afficher seulement : sans cela, le bouton « allumer » donnait
         * gratuitement une application facturée, et personne ne s'en serait
         * aperçu avant de comparer les modules actifs aux factures émises.
         *
         * « pas vrai » plutôt que « faux », et absent vaut non : l'éditeur a
         * servi un catalogue sans « acquise » pendant tout le temps où la
         * boutique a existé, et le défaut « true » faisait passer ce silence
         * pour un achat. La règle est donc prise à l'envers — rien ne s'allume
         * sans un « oui » explicite de l'éditeur.
         */
        if (($fiche['tarif'] ?? '') === 'option' && ($fiche['acquise'] ?? false) !== true) {
            return ['etat' => 'a-acheter', 'href' => ''];
        }

        return Catalogue::isEnabled($module)
            ? ['etat' => 'allumee', 'href' => $href]
            : ['etat' => 'eteinte', 'href' => ''];
    }

    /**
     * Le catalogue, rangé par rayon, avec l'état local de chaque fiche.
     *
     * Les rayons viennent de l'éditeur, dans son ordre — du plus demandé au plus
     * spécialisé. Un rayon vide ne s'affiche pas, et un rayon que l'éditeur a
     * ajouté sans que le produit le connaisse s'affiche quand même : mieux vaut
     * un titre brut qu'une fiche disparue.
     */
    public static function ranger(array $catalogue): array
    {
        $rayons = [];
        foreach ($catalogue['rayons'] as $cle => $titre) {
            $rayons[(string) $cle] = ['cle' => (string) $cle, 'titre' => (string) $titre, 'fiches' => []];
        }
        foreach ($catalogue['applications'] as $fiche) {
            $categorie = (string) ($fiche['categorie'] ?? '');
            if (!isset($rayons[$categorie])) {
                $rayons[$categorie] = ['cle' => $categorie, 'titre' => $categorie, 'fiches' => []];
            }
            $rayons[$categorie]['fiches'][] = $fiche + self::etatLocal($fiche);
        }
        return array_values(array_filter($rayons, static fn (array $r): bool => $r['fiches'] !== []));
    }

    /**
     * Ce que l'écran affiche.
     *
     * « forcer » saute le répit : c'est le bouton « actualiser », et c'est le
     * seul moyen de voir tout de suite une fiche que l'éditeur vient de
     * corriger.
     */
    public static function ecran(bool $forcer = false): array
    {
        $catalogue = self::$cache !== null && !$forcer
            && (time() - self::$cache['at']) < self::CACHE_SECONDES
            ? self::$cache['catalogue']
            : null;

        if ($catalogue === null) {
            $catalogue = self::catalogue();
            if ($catalogue['ok']) {
                self::$cache = ['at' => time(), 'catalogue' => $catalogue];
            }
        }

        if (!$catalogue['ok']) {
            return ['ok' => false, 'reason' => $catalogue['reason'], 'rayons' => [],
                    'compte' => self::compteVide(), 'espace_client' => ''];
        }
        return ['ok' => true, 'rayons' => self::ranger($catalogue),
                'compte' => self::compter($catalogue),
                'espace_client' => (string) ($catalogue['espace_client'] ?? '')];
    }

    private static function compteVide(): array
    {
        return ['total' => 0, 'ouvertes' => 0, 'allumees' => 0, 'eteintes' => 0,
                'a_acheter' => 0, 'annoncees' => 0];
    }

    private static function compter(array $catalogue): array
    {
        $compte = self::compteVide();
        foreach ($catalogue['applications'] as $fiche) {
            $compte['total']++;
            $compte[match (self::etatLocal($fiche)['etat']) {
                'ouverte' => 'ouvertes',
                'allumee' => 'allumees',
                'eteinte' => 'eteintes',
                'a-acheter' => 'a_acheter',
                default => 'annoncees',
            }]++;
        }
        return $compte;
    }

    /**
     * Allumer une application depuis la boutique.
     *
     * Ce qui s'allume est un module du produit, pas une fiche : la boutique ne
     * téléverse rien et n'installe rien. Elle ne peut donc allumer que ce que le
     * produit porte déjà — la fiche le dit, et on le revérifie ici plutôt que de
     * faire confiance à un formulaire.
     */
    public static function allumer(string $cle): array
    {
        $catalogue = self::catalogue();
        if (!$catalogue['ok']) {
            return ['ok' => false, 'reason' => $catalogue['reason']];
        }

        $fiche = null;
        foreach ($catalogue['applications'] as $candidate) {
            if ((string) ($candidate['cle'] ?? '') === $cle) {
                $fiche = $candidate;
                break;
            }
        }
        if ($fiche === null) {
            return ['ok' => false, 'reason' => 'unknown'];
        }
        if (($fiche['genre'] ?? '') !== 'module') {
            return ['ok' => false, 'reason' => 'not-a-module'];
        }
        $module = (string) ($fiche['module'] ?? '');
        if (!in_array($module, Catalogue::keys(), true)) {
            return ['ok' => false, 'reason' => 'not-in-version'];
        }

        // Le formulaire n'est pas une autorisation : on redemande à l'éditeur,
        // et c'est sa réponse qui décide. Un bouton absent de l'écran ne
        // protège rien contre une requête écrite à la main.
        //
        // Et il faut un « oui » : une réponse qui ne dit rien sur « acquise »
        // — un éditeur plus ancien que cette instance, un catalogue tronqué —
        // ne vaut pas autorisation. Le silence n'achète pas.
        if ((string) ($fiche['tarif'] ?? '') === 'option' && ($fiche['acquise'] ?? false) !== true) {
            return ['ok' => false, 'reason' => 'not-bought'];
        }

        Catalogue::setEnabled($module, true);
        // Le réglage est écrit : le compte affiché ne doit pas rester sur l'ancien.
        self::viderCache();
        Logs::record([
            'level' => 6, 'channel' => 'admin', 'event' => 'store.module.enabled',
            'message' => "Module « $module » allumé depuis la boutique.",
        ]);
        return ['ok' => true, 'module' => $module, 'href' => (string) ($fiche['href'] ?? '')];
    }

    /**
     * Récupérer ses achats.
     *
     * Le client vient d'acheter dans son espace client. Sans ce bouton, il
     * reviendrait ici, ne verrait rien de changé — le catalogue a une minute
     * de répit — et ouvrirait une demande d'assistance. On redemande donc sans
     * attendre, et l'on allume ce qu'il a payé.
     *
     * Allumer plutôt que déverrouiller : il a payé pour s'en servir, pas pour
     * chercher un second bouton. Seules les options achetées : une application
     * comprise dans la licence reste au choix de l'administrateur, qui n'a pas
     * demandé qu'on lui allume la moitié du produit d'un clic.
     */
    public static function recuperer(): array
    {
        self::viderCache();
        $catalogue = self::catalogue();
        if (!$catalogue['ok']) {
            return ['ok' => false, 'reason' => $catalogue['reason']];
        }

        $allumes = [];
        foreach ($catalogue['applications'] as $fiche) {
            if ((string) ($fiche['genre'] ?? '') !== 'module') {
                continue;
            }
            if ((string) ($fiche['tarif'] ?? '') !== 'option') {
                continue;
            }
            // Un « oui » explicite, là encore : ce bouton allume en série, et
            // c'est le dernier endroit où l'on voudrait qu'un champ manquant
            // passe pour un achat.
            if (($fiche['acquise'] ?? false) !== true) {
                continue;
            }
            $module = (string) ($fiche['module'] ?? '');
            if (!in_array($module, Catalogue::keys(), true) || Catalogue::isEnabled($module)) {
                continue;
            }
            Catalogue::setEnabled($module, true);
            $allumes[] = (string) ($fiche['nom'] ?? $module);
        }

        self::viderCache();
        Logs::record([
            'level' => 6, 'channel' => 'admin', 'event' => 'store.purchases.fetched',
            'message' => $allumes === []
                ? 'Achats récupérés : rien de nouveau à allumer.'
                : 'Achats récupérés : ' . implode(', ', $allumes) . '.',
        ]);
        return ['ok' => true, 'allumes' => $allumes];
    }

    /** @return array{status: int, body: string, error: string} */
    private static function appeler(string $url, string $cle): array
    {
        if (self::$transport !== null) {
            return (self::$transport)($url, $cle);
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['X-Cle-Instance: ' . $cle],
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        return [
            'status' => $body === false ? 0 : $status,
            'body' => $body === false ? '' : (string) $body,
            'error' => $error,
        ];
    }
}
