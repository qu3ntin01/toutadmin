<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Secrets;
use App\Core\Settings;

/**
 * Écrire à l'éditeur, depuis l'instance.
 *
 * Un administrateur qui a un problème avec le logiciel écrit ici, sans quitter
 * son écran et sans compte supplémentaire. L'instance porte déjà la clé qui lui
 * sert à obtenir son bail de licence et ses mises à jour : c'est elle qui
 * l'authentifie. Aucun mot de passe de l'espace client ne transite par le CMS —
 * il n'en connaît aucun, et il n'a pas à en connaître.
 *
 * Deux verbes, et une lecture. Rien n'est conservé ici : le fil vit chez
 * l'éditeur, et cette instance n'en garde pas de copie. Une copie locale
 * divergerait le jour où quelqu'un répondrait depuis l'espace client, et deux
 * versions d'une même conversation valent moins qu'une.
 */
final class AssistanceEditeur
{
    public const SUJETS = ['question', 'technique', 'facturation', 'licence', 'autre'];
    private const DELAI = 15;

    /*
     * Le seul chemin vers le dehors, et son point d'injection.
     *
     * Ce qui passe par là est exactement ce qui sort de l'instance, et un appel
     * qui le contournerait se verrait dans un test qui devrait intercepter deux
     * choses au lieu d'une.
     *
     * @var null|callable(string, string, string, string): array{statut:int, corps:string}|false
     */
    public static $transport = null;

    /** Les refus que l'API nomme, traduits en clés d'écran. */
    private const RAISONS = [
        'cle-refusee' => 'cleRefusee',
        'instance-sans-client' => 'sansClient',
        'message-trop-court' => 'tropCourt',
        'sujet-vide' => 'sujetVide',
        'demande-introuvable' => 'introuvable',
        'plafond' => 'plafond',
    ];

    /** L'éditeur est-il joignable, de principe ? Adresse et clé, rien de plus. */
    public static function configure(): bool
    {
        $config = Licence::config();
        return $config['url'] !== '' && $config['clef'] !== '';
    }

    /**
     * Les éléments de l'appel, ou null.
     *
     * La clé sort du coffre ici et nulle part ailleurs : elle est chiffrée en
     * base, et la déchiffrer dans un contrôleur l'exposerait à être enregistrée
     * par inadvertance dans un journal de requête.
     */
    private static function acces(): ?array
    {
        $config = Licence::config();
        if ($config['url'] === '' || $config['clef'] === '') {
            return null;
        }
        return ['url' => $config['url'], 'clef' => Secrets::decrypt($config['clef'])];
    }

    private static function appeler(string $methode, ?array $corps): array
    {
        $porte = self::acces();
        if ($porte === null) {
            return ['ok' => false, 'raison' => 'nonConfigure'];
        }

        $charge = $corps === null ? '' : (string) json_encode($corps, JSON_UNESCAPED_UNICODE);
        $vu = self::envoyer($porte['url'] . '/api/v1/assistance', $methode, $porte['clef'], $charge);
        if ($vu === false) {
            // Une panne de réseau n'est pas une faute de l'instance : on le dit,
            // et l'administrateur réessaiera.
            return ['ok' => false, 'raison' => 'reseau'];
        }

        $lu = json_decode($vu['corps'], true);
        $donnees = is_array($lu) ? $lu : [];

        if ($vu['statut'] >= 200 && $vu['statut'] < 300) {
            return ['ok' => true, 'donnees' => $donnees];
        }
        return [
            'ok' => false,
            'raison' => self::RAISONS[(string) ($donnees['erreur'] ?? '')] ?? 'refus',
            'statut' => $vu['statut'],
        ];
    }

    /** L'envoi lui-même, remplaçable pour les tests. */
    private static function envoyer(string $url, string $methode, string $clef, string $corps): array|false
    {
        if (is_callable(self::$transport)) {
            return (self::$transport)($url, $methode, $clef, $corps);
        }

        $contexte = stream_context_create(['http' => [
            'method' => $methode,
            'header' => "Content-Type: application/json\r\nX-Cle-Instance: $clef\r\n",
            'content' => $corps === '' ? null : $corps,
            'timeout' => self::DELAI,
            // Sans cela, un 403 lèverait un avertissement et rendrait false :
            // on veut lire le corps de la réponse, qui nomme le refus.
            'ignore_errors' => true,
        ]]);

        $reponse = @file_get_contents($url, false, $contexte);
        if ($reponse === false) {
            return false;
        }

        $statut = 0;
        foreach ($http_response_header ?? [] as $ligne) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $ligne, $trouve)) {
                $statut = (int) $trouve[1];
            }
        }
        return ['statut' => $statut, 'corps' => $reponse];
    }

    /** Ouvrir une demande. */
    public static function ouvrir(string $sujet, string $type, string $corps): array
    {
        $objet = trim($sujet);
        $texte = trim($corps);
        if ($objet === '') {
            return ['ok' => false, 'raison' => 'sujetVide'];
        }
        // Le même plancher qu'à l'autre bout : mieux vaut le dire ici que de
        // faire un aller-retour pour se faire refuser.
        if (mb_strlen($texte) < 10) {
            return ['ok' => false, 'raison' => 'tropCourt'];
        }

        return self::appeler('POST', [
            'sujet' => mb_substr($objet, 0, 200),
            'type' => in_array($type, self::SUJETS, true) ? $type : 'technique',
            'corps' => mb_substr($texte, 0, 5000),
            'edition' => 'php',
            // L'adresse aide au diagnostic, et l'éditeur la voit déjà par le
            // registre des instances : la redire ne révèle rien de plus.
            'adresse' => Licence::config()['adresse'],
        ]);
    }

    /** Relancer dans un fil déjà ouvert par cette instance. */
    public static function repondre(string $reference, string $corps): array
    {
        $texte = trim($corps);
        if (mb_strlen($texte) < 10) {
            return ['ok' => false, 'raison' => 'tropCourt'];
        }
        return self::appeler('POST', ['demande' => $reference, 'corps' => mb_substr($texte, 0, 5000)]);
    }

    /** Relever les demandes de cette instance, et les réponses. */
    public static function relever(): array
    {
        $vu = self::appeler('GET', null);
        if (empty($vu['ok'])) {
            return $vu;
        }
        $demandes = is_array($vu['donnees']['demandes'] ?? null) ? $vu['donnees']['demandes'] : [];
        return ['ok' => true, 'demandes' => $demandes];
    }

    /**
     * Le dernier relevé, et quand il a été fait.
     *
     * Gardé en réglages : l'administrateur qui se reconnecte demain doit
     * retrouver l'état sous les yeux, et non un écran vide qui laisse croire
     * qu'il n'a jamais écrit. La fraîcheur est affichée, parce qu'une liste sans
     * date laisse croire qu'elle est d'aujourd'hui.
     */
    public static function memoriser(array $demandes): void
    {
        Settings::set('assistance_editeur_releve', (string) json_encode([
            'at' => gmdate('c'),
            'demandes' => $demandes,
        ], JSON_UNESCAPED_UNICODE));
    }

    public static function memoire(): ?array
    {
        $brut = Settings::get('assistance_editeur_releve');
        if ($brut === '') {
            return null;
        }
        $lu = json_decode($brut, true);
        if (!is_array($lu)) {
            return null;
        }
        return [
            'at' => $lu['at'] ?? null,
            'demandes' => is_array($lu['demandes'] ?? null) ? $lu['demandes'] : [],
        ];
    }
}
