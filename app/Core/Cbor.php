<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Un lecteur CBOR (RFC 8949) réduit à ce que WebAuthn transporte — le même que
 * celui de l'édition Node : entiers, chaînes, tableaux, tables, booléens, nul.
 * Pas de flottant ni de longueur indéfinie : un message qui en contient est
 * refusé plutôt que deviné. Les chaînes d'octets sont rendues dans un objet
 * pour ne pas les confondre avec du texte.
 */
final class Cbor
{
    private const PROFONDEUR = 16;

    /** @return array{valeur: mixed, fin: int} */
    public static function lire(string $octets, int $debut = 0): array
    {
        $pos = $debut;
        $valeur = self::valeur($octets, $pos, 0);
        return ['valeur' => $valeur, 'fin' => $pos];
    }

    private static function octet(string $b, int &$pos): int
    {
        if ($pos >= strlen($b)) {
            throw new \RuntimeException('cbor: fin inattendue');
        }
        return ord($b[$pos++]);
    }

    private static function tranche(string $b, int &$pos, int $n): string
    {
        if ($n < 0 || $pos + $n > strlen($b)) {
            throw new \RuntimeException('cbor: fin inattendue');
        }
        $t = substr($b, $pos, $n);
        $pos += $n;
        return $t;
    }

    private static function longueur(string $b, int &$pos, int $info): int
    {
        if ($info < 24) {
            return $info;
        }
        return match ($info) {
            24 => self::octet($b, $pos),
            25 => unpack('n', self::tranche($b, $pos, 2))[1],
            26 => unpack('N', self::tranche($b, $pos, 4))[1],
            27 => (static function () use ($b, &$pos): int {
                $v = unpack('J', self::tranche($b, $pos, 8))[1];
                if ($v < 0) {
                    throw new \RuntimeException('cbor: entier trop grand');
                }
                return $v;
            })(),
            default => throw new \RuntimeException('cbor: longueur indéfinie refusée'),
        };
    }

    private static function valeur(string $b, int &$pos, int $profondeur): mixed
    {
        if ($profondeur > self::PROFONDEUR) {
            throw new \RuntimeException('cbor: trop imbriqué');
        }
        $initial = self::octet($b, $pos);
        $majeur = $initial >> 5;
        $info = $initial & 0x1f;
        switch ($majeur) {
            case 0:
                return self::longueur($b, $pos, $info);
            case 1:
                return -1 - self::longueur($b, $pos, $info);
            case 2:
                return new CborOctets(self::tranche($b, $pos, self::longueur($b, $pos, $info)));
            case 3:
                return self::tranche($b, $pos, self::longueur($b, $pos, $info));
            case 4:
                $n = self::longueur($b, $pos, $info);
                $t = [];
                for ($i = 0; $i < $n; $i++) {
                    $t[] = self::valeur($b, $pos, $profondeur + 1);
                }
                return $t;
            case 5:
                $n = self::longueur($b, $pos, $info);
                $m = [];
                for ($i = 0; $i < $n; $i++) {
                    $cle = self::valeur($b, $pos, $profondeur + 1);
                    if (!is_int($cle) && !is_string($cle)) {
                        throw new \RuntimeException('cbor: clé de table non prise en charge');
                    }
                    $m[$cle] = self::valeur($b, $pos, $profondeur + 1);
                }
                return $m;
            case 6:
                self::longueur($b, $pos, $info);
                return self::valeur($b, $pos, $profondeur + 1);
            case 7:
                return match ($info) {
                    20 => false,
                    21 => true,
                    22 => null,
                    default => throw new \RuntimeException('cbor: valeur simple non prise en charge'),
                };
        }
        throw new \RuntimeException('cbor: type inconnu');
    }
}
