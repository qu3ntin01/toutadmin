<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Gabarits PHP.
 *
 * Pas de moteur de rendu : PHP en est un. Les gabarits reçoivent leurs données
 * par extraction de tableau, échappent tout avec e(), et s'emboîtent par
 * View::partial(). Un gabarit qui affiche une variable sans e() est un bogue,
 * pas un raccourci.
 */
final class View
{
    private static array $shared = [];

    public static function share(array $values): void
    {
        self::$shared = array_merge(self::$shared, $values);
    }

    public static function shared(): array
    {
        return self::$shared;
    }

    public static function render(string $template, array $data = []): string
    {
        $file = APP_DIR . '/views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("Gabarit introuvable : $template");
        }
        $values = array_merge(self::$shared, $data);
        extract($values, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }

    /** Un fragment, rendu depuis un gabarit. */
    public static function partial(string $template, array $data = []): string
    {
        return self::render('partials/' . $template, $data);
    }

    /** Une page complète : le gabarit, posé dans la mise en page commune. */
    public static function page(string $template, array $data = []): string
    {
        $content = self::render($template, $data);
        return self::render('layout', array_merge($data, ['content' => $content]));
    }
}
