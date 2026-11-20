<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Jeton anti-falsification de requête.
 *
 * Un jeton par session, exigé sur chaque POST, comparé en temps constant. Sans
 * lui, une page tierce pourrait faire signer une action à une personne
 * connectée sans qu'elle le sache.
 */
final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('csrf_token');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::set('csrf_token', $token);
        }
        return $token;
    }

    public static function matches(?string $submitted): bool
    {
        $expected = Session::get('csrf_token');
        if (!is_string($expected) || !is_string($submitted) || $submitted === '') {
            return false;
        }
        return hash_equals($expected, $submitted);
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '" />';
    }
}
