<?php

declare(strict_types=1);

namespace App\Core;

/** Un message d'un écran au suivant, lu une seule fois. */
final class Flash
{
    public static function set(string $type, string $message): void
    {
        Session::set('flash', ['type' => $type, 'message' => $message]);
    }

    public static function take(): ?array
    {
        $flash = Session::get('flash');
        if ($flash === null) {
            return null;
        }
        Session::forget('flash');
        return is_array($flash) ? $flash : null;
    }
}
