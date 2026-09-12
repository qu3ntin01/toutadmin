<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lecture d'un message électronique brut (RFC 5322 et MIME).
 *
 * La relève n'a besoin que de trois choses : de qui vient le message, ce qu'il
 * annonce, et ce qu'il porte en pièces jointes. Tout le reste du format — les
 * en-têtes de routage, les parties alternatives, les signatures — est traversé
 * sans être interprété.
 *
 * Le contenu d'un message est une donnée hostile : rien n'est exécuté, aucun
 * nom de fichier n'est utilisé tel quel pour écrire sur le disque, et une
 * partie illisible est ignorée plutôt que devinée.
 */
final class Mime
{
    /** Sépare les en-têtes du corps, quel que soit le style de fin de ligne. */
    public static function split(string $raw): array
    {
        $normalised = str_replace("\r\n", "\n", $raw);
        $at = strpos($normalised, "\n\n");
        if ($at === false) {
            return ['headers' => $normalised, 'body' => ''];
        }
        return ['headers' => substr($normalised, 0, $at), 'body' => substr($normalised, $at + 2)];
    }

    /** Les en-têtes, repliés puis rangés par nom en minuscules. */
    public static function headers(string $block): array
    {
        $unfolded = preg_replace('/\n[ \t]+/', ' ', str_replace("\r\n", "\n", $block)) ?? '';
        $headers = [];
        foreach (explode("\n", $unfolded) as $line) {
            $at = strpos($line, ':');
            if ($at === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $at)));
            $value = trim(substr($line, $at + 1));
            $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
        }
        return $headers;
    }

    /** « =?UTF-8?B?…?= » : un sujet accentué arrive presque toujours encodé. */
    public static function decodeWords(string $value): string
    {
        return (string) preg_replace_callback(
            '/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/',
            static function (array $m): string {
                $charset = strtoupper($m[1]);
                $bytes = strtoupper($m[2]) === 'B'
                    ? (base64_decode($m[3], true) ?: '')
                    : quoted_printable_decode(str_replace('_', ' ', $m[3]));
                if ($charset === 'UTF-8' || $charset === '') {
                    return $bytes;
                }
                $converted = @iconv($charset, 'UTF-8//IGNORE', $bytes);
                return $converted === false ? $bytes : $converted;
            },
            $value
        );
    }

    /** Le type d'une partie et ses paramètres : « text/plain; charset=utf-8 ». */
    public static function contentType(array $headers): array
    {
        $raw = (string) ($headers['content-type'] ?? 'text/plain');
        $pieces = explode(';', $raw);
        $type = strtolower(trim(array_shift($pieces)));

        $params = [];
        foreach ($pieces as $piece) {
            $at = strpos($piece, '=');
            if ($at === false) {
                continue;
            }
            $name = strtolower(trim(substr($piece, 0, $at)));
            $params[$name] = trim(trim(substr($piece, $at + 1)), '"');
        }
        return ['type' => $type, 'params' => $params];
    }

    private static function decodeBody(string $body, array $headers): string
    {
        $encoding = strtolower(trim((string) ($headers['content-transfer-encoding'] ?? '')));
        return match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    /** Le nom annoncé d'une pièce jointe, jamais utilisé tel quel pour écrire. */
    private static function fileNameOf(array $headers, array $type): ?string
    {
        $disposition = (string) ($headers['content-disposition'] ?? '');
        if (preg_match('/filename\*?=([^;]+)/i', $disposition, $m)) {
            $value = trim(trim($m[1]), '"');
            // RFC 2231 : « UTF-8\'\'nom%20du%20fichier.pdf ».
            if (str_contains($value, "''")) {
                $value = urldecode(substr($value, strpos($value, "''") + 2));
            }
            return self::decodeWords($value);
        }
        if (isset($type['params']['name'])) {
            return self::decodeWords($type['params']['name']);
        }
        return null;
    }

    /**
     * Les pièces jointes d'un message : les parties nommées, ou déclarées en
     * attachement. Les parties multiples sont traversées récursivement.
     */
    public static function attachments(string $raw, int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }

        ['headers' => $headerBlock, 'body' => $body] = self::split($raw);
        $headers = self::headers($headerBlock);
        $type = self::contentType($headers);

        if (str_starts_with($type['type'], 'multipart/')) {
            $boundary = $type['params']['boundary'] ?? '';
            if ($boundary === '') {
                return [];
            }
            $found = [];
            foreach (self::parts($body, $boundary) as $part) {
                $found = array_merge($found, self::attachments($part, $depth + 1));
            }
            return $found;
        }

        $name = self::fileNameOf($headers, $type);
        $disposition = strtolower((string) ($headers['content-disposition'] ?? ''));
        if ($name === null && !str_starts_with($disposition, 'attachment')) {
            return [];
        }

        return [[
            'filename' => $name ?? 'piece-jointe',
            'contentType' => $type['type'],
            'content' => self::decodeBody($body, $headers),
        ]];
    }

    /** Les parties d'un corps multiple, découpées sur leur frontière. */
    public static function parts(string $body, string $boundary): array
    {
        $marker = '--' . $boundary;
        $segments = explode($marker, str_replace("\r\n", "\n", $body));
        array_shift($segments); // le préambule n'est pas une partie

        $parts = [];
        foreach ($segments as $segment) {
            if (str_starts_with($segment, '--')) {
                break; // la frontière de clôture
            }
            $parts[] = ltrim($segment, "\n");
        }
        return $parts;
    }

    /** Ce que la relève retient d'un message : l'expéditeur, le sujet, la date. */
    public static function envelope(string $raw): array
    {
        $headers = self::headers(self::split($raw)['headers']);
        $date = null;
        if (!empty($headers['date'])) {
            $stamp = strtotime((string) $headers['date']);
            $date = $stamp === false ? null : gmdate('c', $stamp);
        }

        return [
            'from' => self::decodeWords((string) ($headers['from'] ?? '')),
            'subject' => self::decodeWords((string) ($headers['subject'] ?? '')),
            'date' => $date,
        ];
    }
}
