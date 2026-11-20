<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Réglages de déploiement : chemin de la base, clé de session, URL publique.
 *
 * Ils vivent dans un fichier que l'installateur écrit, jamais dans le dépôt :
 * une clé de session versionnée est une clé publique. Le fichier d'exemple,
 * lui, est versionné et documente chaque entrée.
 */
final class Config
{
    private static array $values = [];

    public static function load(?string $file = null): void
    {
        // La variable d'environnement sert aux hébergements qui préfèrent tenir
        // leur configuration ailleurs, et à la tâche planifiée quand on veut la
        // faire tourner sur une autre instance que celle du dossier.
        $file ??= ((string) (getenv('TOUTADMIN_CONFIG') ?: '')) ?: APP_ROOT . '/config.php';

        /*
         * Où l'instance range son état, quand ce n'est pas sous le code.
         *
         * Le défaut — « data/ » à côté de l'application — convient à un
         * hébergement mutualisé où le dossier entier appartient au client. Il
         * ne convient pas dès que le code est ailleurs que les données : une
         * image de conteneur, dont seul le volume survit ; un paquet système,
         * dont le code est en lecture seule. Sans ce réglage, l'assistant y
         * écrirait la base et les fichiers déposés, et le premier
         * remplacement les emporterait.
         *
         * C'est l'équivalent de DB_PATH dans l'édition Node, et il joue le
         * même rôle : tout le reste — la base en SQLite, les photos de
         * profil, le coffre-fort, les CV, le parapheur, les pièces reçues,
         * les sauvegardes — en découle. L'assistant recopie ensuite ces deux
         * valeurs dans config.php, qui reste la source une fois posé.
         */
        $donnees = rtrim((string) (getenv('TOUTADMIN_DATA') ?: ''), '/') ?: APP_ROOT . '/data';

        $defaults = [
            'db_path' => $donnees . '/app.sqlite',
            'data_dir' => $donnees,
            'session_secret' => '',
            'base_url' => '',
            'trust_proxy' => false,
            'login_rate_limit' => 10,
            'global_rate_limit' => 300,
            'session_idle_minutes' => 60,
            'session_max_hours' => 12,
        ];
        $values = is_file($file) ? (array) require $file : [];
        self::$values = array_merge($defaults, $values);

        // La base et les téléversements ne doivent jamais être servis par le
        // serveur web : ils vivent au-dessus de la racine publique.
        if (!is_dir(self::$values['data_dir'])) {
            @mkdir(self::$values['data_dir'], 0770, true);
        }
    }

    public static function get(string $key, mixed $fallback = null): mixed
    {
        return self::$values[$key] ?? $fallback;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$values[$key] = $value;
    }

    /**
     * Le secret de l'instance : celui que config.php porte, ou à défaut une clé
     * engendrée une fois et gardée dans un fichier à part, hors de la base et
     * hors de la racine web. Elle scelle les sceaux du parapheur : la perdre
     * invaliderait des signatures, la mettre en base la rendrait copiable avec
     * une sauvegarde SQL.
     */
    public static function secret(): string
    {
        $configured = (string) self::get('session_secret', '');
        if ($configured !== '' && $configured !== 'à-remplacer-à-l-installation') {
            return $configured;
        }

        $file = (string) self::get('data_dir') . '/.instance-key';
        if (is_file($file)) {
            $stored = trim((string) file_get_contents($file));
            if ($stored !== '') {
                return $stored;
            }
        }

        $secret = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        @mkdir(dirname($file), 0770, true);
        file_put_contents($file, $secret);
        @chmod($file, 0600);
        return $secret;
    }

    public static function isInstalled(): bool
    {
        return is_file(APP_ROOT . '/config.php') && is_file((string) self::get('db_path'));
    }
}
