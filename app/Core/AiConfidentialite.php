<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Ce qui ne sort pas, même quand l'assistance est activée.
 *
 * Le pendant de « src/ai-confidentialite.js » de l'édition Node, et pour la même
 * raison. Activer l'assistance, c'est accepter qu'un texte de l'entreprise parte
 * chez un service tiers — ou vers un modèle posé sur le réseau interne, ce que
 * l'écran propose aussi. Dans les deux cas, certaines suites de caractères n'ont
 * aucune raison de faire le voyage : elles ne servent pas à résumer un fil de
 * support ni à relever les décisions d'une réunion, et leur fuite ne se répare pas.
 *
 * Ce module est le seul endroit qui les retire. Un appel d'assistance passe par
 * ici ou ne passe pas : le socle (« Ai.php ») ne sait pas envoyer autrement.
 *
 * Le filtre est une liste d'autorisation inversée : tout est retiré, et une
 * fonction déclare ce dont elle a besoin. La lecture d'une facture a besoin de
 * l'IBAN et du SIRET — c'est précisément ce qu'elle extrait, et l'administrateur
 * l'a activée en sachant qu'un document part. Le résumé d'un ticket, lui, n'a
 * besoin d'aucun des deux, et ne les verra donc jamais.
 *
 * Ce que ce module NE fait pas : décider qu'un CHAMP ne doit pas sortir. Le motif
 * d'une absence ne se reconnaît à aucun motif de caractères — c'est une colonne.
 * La règle est tenue là où le texte est composé, et un test la vérifie là.
 */
final class AiConfidentialite
{
    /** Le chiffre de contrôle de Luhn, pour ne pas prendre une référence de facture pour une carte. */
    public static function luhn(string $chiffres): bool
    {
        $somme = 0;
        $doubler = false;
        for ($i = strlen($chiffres) - 1; $i >= 0; $i--) {
            $n = (int) $chiffres[$i];
            if ($doubler) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $somme += $n;
            $doubler = !$doubler;
        }
        return $somme % 10 === 0;
    }

    /**
     * Les motifs, et ce qu'on met à la place.
     *
     * Le remplacement dit ce qui a été retiré plutôt que de laisser un blanc : un
     * modèle qui lit « [IBAN retiré] » comprend qu'il y avait là une coordonnée
     * bancaire, et ne se met pas à chercher ailleurs ce qui lui manque.
     *
     * L'ordre compte : l'en-tête d'autorisation passe avant la clé, pour que
     * l'en-tête entier disparaisse plutôt que sa seule valeur.
     */
    public static function motifs(): array
    {
        return [
            [
                'cle' => 'iban',
                // Deux lettres de pays, deux chiffres de contrôle, puis de dix à
                // trente caractères, avec ou sans les espaces de présentation.
                'motif' => '~\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]){10,30}\b~',
                'remplacement' => '[IBAN retiré]',
            ],
            [
                'cle' => 'carte',
                'motif' => '~\b(?:\d[ -]?){12,18}\d\b~',
                // Sans Luhn, ce motif mangerait les numéros de commande.
                'luhn' => true,
                'remplacement' => '[numéro de carte retiré]',
            ],
            [
                'cle' => 'nir',
                // Un NIR commence par 1 ou 2, puis treize chiffres, parfois suivis
                // de la clé à deux chiffres. C'est la donnée la plus sensible qu'un
                // document du personnel puisse porter, et aucune fonction n'en a besoin.
                'motif' => '~\b[12][ ]?\d{2}[ ]?\d{2}[ ]?\d{2}[ ]?\d{3}[ ]?\d{3}(?:[ ]?\d{2})?\b~',
                'remplacement' => '[numéro de sécurité sociale retiré]',
            ],
            [
                'cle' => 'autorisation',
                'motif' => '#\b(?:Bearer|Basic)\s+[A-Za-z0-9._~+/=-]{12,}#i',
                'remplacement' => '[autorisation retirée]',
            ],
            [
                'cle' => 'cle_api',
                // Les préfixes qu'on connaît : celui d'Anthropic, celui des services
                // compatibles OpenAI, et « sm_ », celui des jetons d'API du produit.
                'motif' => '~\b(?:sk-ant-|sk-|sm_)[A-Za-z0-9_-]{16,}~',
                'remplacement' => '[clé retirée]',
            ],
            [
                'cle' => 'jeton_licence',
                // Le jeton de licence, comme un JWT, est fait de segments base64url
                // séparés par un point. Il ne quitte jamais l'instance, nulle part.
                'motif' => '~\b[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}(?:\.[A-Za-z0-9_-]{10,})?\b~',
                // « sous-domaine-tres-long.exemple-tres-long-aussi » a la même forme
                // qu'un jeton et n'en est pas un : on laisse passer ce qui est tout en
                // minuscules et sans souligné. Une signature base64url de vingt
                // caractères sans une majuscule ni un souligné est possible en
                // théorie ; en pratique, jamais.
                'minuscules' => true,
                'remplacement' => '[jeton retiré]',
            ],
        ];
    }

    /** Ce qu'une fonction peut demander à garder, pour l'écran qui l'explique. */
    public static function cles(): array
    {
        return array_column(self::motifs(), 'cle');
    }

    /**
     * Retire de « $texte » tout ce qui ne doit pas sortir, sauf ce que la fonction
     * déclare avoir besoin de garder.
     *
     * Rend le texte nettoyé et le compte de ce qui a été retiré, par motif : le
     * journal l'écrit, et l'écran peut le dire à la personne — « trois coordonnées
     * bancaires retirées avant l'envoi » vaut mieux qu'un silence.
     */
    public static function nettoyer(?string $texte, array $garder = []): array
    {
        $sortie = (string) $texte;
        $retires = [];

        foreach (self::motifs() as $motif) {
            if (in_array($motif['cle'], $garder, true)) {
                continue;
            }

            $compte = 0;
            $sortie = (string) preg_replace_callback(
                $motif['motif'],
                static function (array $trouve) use ($motif, &$compte): string {
                    $valeur = $trouve[0];
                    if (!empty($motif['luhn'])) {
                        $chiffres = preg_replace('~\D~', '', $valeur) ?? '';
                        $taille = strlen($chiffres);
                        if ($taille < 13 || $taille > 19 || !self::luhn($chiffres)) {
                            return $valeur;
                        }
                    }
                    if (!empty($motif['minuscules']) && preg_match('~[A-Z_]~', $valeur) !== 1) {
                        return $valeur;
                    }
                    $compte++;
                    return $motif['remplacement'];
                },
                $sortie
            );

            if ($compte > 0) {
                $retires[$motif['cle']] = $compte;
            }
        }

        return ['texte' => $sortie, 'retires' => $retires];
    }

    /** Le compte total, pour une phrase courte. */
    public static function combien(array $retires): int
    {
        return array_sum($retires);
    }
}
