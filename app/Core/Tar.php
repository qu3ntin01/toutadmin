<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Écriture et lecture d'archives tar.
 *
 * Écrit à la main plutôt qu'emprunté : le format tient en un en-tête de 512
 * octets par fichier, et une sauvegarde ne doit dépendre de rien qu'on ne
 * puisse relire soi-même dans dix ans. Seul le strict nécessaire est géré —
 * fichiers ordinaires, chemins relatifs courts —, et tout le reste est refusé
 * explicitement plutôt que deviné.
 */
final class Tar
{
    public const BLOCK = 512;

    // Le champ « name » d'un en-tête ustar fait 100 octets. Au-delà, il faudrait
    // le champ « prefix » : on préfère refuser, nos chemins étant courts par
    // construction.
    public const MAX_NAME = 100;

    /** Un champ numérique tar est de l'octal ASCII, terminé par un espace et un nul. */
    private static function octal(int $value, int $length): string
    {
        return str_pad(decoct($value), $length - 2, '0', STR_PAD_LEFT) . " \0";
    }

    private static function header(string $name, int $size, int $mtime, int $mode = 0600, string $type = '0'): string
    {
        if (strlen($name) > self::MAX_NAME) {
            throw new \RuntimeException("Chemin trop long pour une archive tar : $name");
        }

        $block = str_repeat("\0", self::BLOCK);
        $put = static function (string $block, string $text, int $offset, int $length): string {
            return substr_replace($block, str_pad(substr($text, 0, $length), $length, "\0"), $offset, $length);
        };

        $block = $put($block, $name, 0, 100);
        $block = $put($block, self::octal($mode, 8), 100, 8);
        $block = $put($block, self::octal(0, 8), 108, 8);          // uid
        $block = $put($block, self::octal(0, 8), 116, 8);          // gid
        $block = $put($block, self::octal($size, 12), 124, 12);
        $block = $put($block, self::octal($mtime, 12), 136, 12);
        $block = $put($block, '        ', 148, 8); // somme de contrôle : espaces pendant le calcul
        $block = $put($block, $type, 156, 1);
        $block = $put($block, "ustar\0", 257, 6);
        $block = $put($block, '00', 263, 2);

        $sum = 0;
        for ($i = 0; $i < self::BLOCK; $i++) {
            $sum += ord($block[$i]);
        }
        return $put($block, str_pad(decoct($sum), 6, '0', STR_PAD_LEFT) . "\0 ", 148, 8);
    }

    private static function padding(int $size): string
    {
        $remainder = $size % self::BLOCK;
        return $remainder === 0 ? '' : str_repeat("\0", self::BLOCK - $remainder);
    }

    /**
     * Assemble une archive à partir d'entrées ['name' => …, 'source' => …],
     * la source étant un chemin sur disque ou déjà des octets (clé 'bytes').
     */
    public static function pack(array $entries): string
    {
        $out = '';
        foreach ($entries as $entry) {
            if (isset($entry['bytes'])) {
                $data = (string) $entry['bytes'];
                $mtime = time();
            } else {
                $data = (string) file_get_contents((string) $entry['source']);
                $mtime = (int) filemtime((string) $entry['source']);
            }
            $out .= self::header((string) $entry['name'], strlen($data), $mtime);
            $out .= $data;
            $out .= self::padding(strlen($data));
        }
        // Deux blocs nuls closent l'archive.
        return $out . str_repeat("\0", self::BLOCK * 2);
    }

    private static function readOctal(string $field): int
    {
        $text = trim(explode("\0", $field)[0]);
        return $text === '' ? 0 : (int) octdec($text);
    }

    /** Rend la liste des entrées ['name' => …, 'size' => …, 'data' => …]. */
    public static function unpack(string $buffer): array
    {
        $entries = [];
        $offset = 0;
        $length = strlen($buffer);
        // Une archive complète se termine par deux blocs nuls. Sans eux, elle a
        // été coupée : mieux vaut le dire que rendre une liste incomplète en
        // silence.
        $terminated = false;

        while ($offset + self::BLOCK <= $length) {
            $block = substr($buffer, $offset, self::BLOCK);
            if (trim($block, "\0") === '') {
                $terminated = true;
                break;
            }

            if (substr($block, 257, 5) !== 'ustar') {
                throw new \RuntimeException('Archive illisible : en-tête tar absent.');
            }

            // La somme de contrôle protège d'une archive tronquée ou corrompue.
            $declared = self::readOctal(substr($block, 148, 8));
            $check = substr_replace($block, '        ', 148, 8);
            $sum = 0;
            for ($i = 0; $i < self::BLOCK; $i++) {
                $sum += ord($check[$i]);
            }
            if ($sum !== $declared) {
                throw new \RuntimeException('Archive corrompue : somme de contrôle incorrecte.');
            }

            $name = explode("\0", substr($block, 0, 100))[0];
            $size = self::readOctal(substr($block, 124, 12));
            $type = substr($block, 156, 1);

            $offset += self::BLOCK;
            if ($offset + $size > $length) {
                throw new \RuntimeException('Archive tronquée.');
            }

            // Seuls les fichiers ordinaires nous intéressent ; le reste est ignoré.
            if ($type === '0' || $type === "\0") {
                $entries[] = ['name' => $name, 'size' => $size, 'data' => substr($buffer, $offset, $size)];
            }
            $offset += $size + strlen(self::padding($size));
        }

        if (!$terminated) {
            throw new \RuntimeException('Archive tronquée : les blocs de fin manquent.');
        }
        return $entries;
    }

    /**
     * Un nom d'entrée sûr : relatif, sans remontée, sous l'une des racines
     * attendues. Une archive est une donnée d'entrée comme une autre — même
     * produite par nous, elle peut avoir été remplacée.
     */
    public static function safeName(string $name, array $allowedRoots): ?string
    {
        if ($name === '' || str_starts_with($name, '/') || preg_match('/^[a-zA-Z]:/', $name) === 1) {
            return null;
        }
        if (str_contains($name, "\0") || str_contains($name, '\\')) {
            return null;
        }
        $segments = explode('/', $name);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }
        if (!in_array($segments[0], $allowedRoots, true)) {
            return null;
        }
        return implode('/', $segments);
    }
}
