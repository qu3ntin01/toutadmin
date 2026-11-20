<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Secrets;
use App\Core\Settings;
use App\Modules\Offsite\Drive;
use App\Modules\Offsite\Ftp;

/**
 * Externalisation des sauvegardes.
 *
 * Une sauvegarde qui reste sur le serveur qu'elle protège ne protège de rien :
 * le disque, l'incendie et le rançongiciel emportent les deux. Ce module envoie
 * chaque archive vers des destinations extérieures, et surveille qu'elles y
 * arrivent — une externalisation qui échoue en silence est pire que pas
 * d'externalisation, puisqu'elle rassure.
 */
final class Offsite
{
    public static function destinations(): array
    {
        return [
            [
                'key' => 'ftp',
                'label' => 'Serveur FTP',
                'hint' => "Un NAS ou l'espace de sauvegarde d'un hébergeur. Préférez FTPS : en FTP simple, "
                    . "l'identifiant, le mot de passe et l'archive traversent le réseau en clair.",
                'fields' => [
                    ['name' => 'host', 'label' => 'Hôte', 'required' => true],
                    ['name' => 'port', 'label' => 'Port', 'type' => 'number', 'placeholder' => '21'],
                    ['name' => 'mode', 'label' => 'Sécurité', 'type' => 'select',
                     'options' => array_map(
                         static fn (array $m): array => ['value' => $m['key'], 'label' => $m['label']],
                         Ftp::MODES
                     )],
                    ['name' => 'user', 'label' => 'Identifiant', 'required' => true],
                    ['name' => 'password', 'label' => 'Mot de passe', 'type' => 'password', 'secret' => true, 'required' => true],
                    ['name' => 'directory', 'label' => 'Dossier distant', 'placeholder' => '/sauvegardes'],
                    ['name' => 'allowSelfSigned', 'label' => 'Accepter un certificat auto-signé', 'type' => 'checkbox'],
                ],
            ],
            [
                'key' => 'drive',
                'label' => 'Google Drive',
                'hint' => 'Par compte de service. Créez-en un dans la console Google Cloud, activez '
                    . "l'API Drive, puis partagez le dossier de destination avec l'adresse du compte de "
                    . 'service : son accès se limite alors à ce dossier.',
                'fields' => [
                    ['name' => 'clientEmail', 'label' => 'Adresse du compte de service', 'required' => true,
                     'placeholder' => 'sauvegarde@projet.iam.gserviceaccount.com'],
                    ['name' => 'privateKey', 'label' => 'Clé privée (champ private_key du fichier JSON)',
                     'type' => 'textarea', 'secret' => true, 'required' => true],
                    ['name' => 'folderId', 'label' => 'Identifiant du dossier Drive', 'required' => true,
                     'placeholder' => '1AbC…'],
                ],
            ],
        ];
    }

    public static function keys(): array
    {
        return array_column(self::destinations(), 'key');
    }

    public static function byKey(string $key): ?array
    {
        foreach (self::destinations() as $destination) {
            if ($destination['key'] === $key) {
                return $destination;
            }
        }
        return null;
    }

    private static function settingKey(string $key, string $suffix): string
    {
        return "offsite.$key.$suffix";
    }

    private static function rawConfig(string $key): array
    {
        $stored = Settings::get(self::settingKey($key, 'config'));
        if ($stored === '') {
            return [];
        }
        $decoded = json_decode($stored, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** La configuration utilisable : secrets déchiffrés, prête pour le pilote. */
    public static function config(string $key): array
    {
        $destination = self::byKey($key);
        if ($destination === null) {
            return [];
        }
        $raw = self::rawConfig($key);
        $out = [];
        foreach ($destination['fields'] as $field) {
            $out[$field['name']] = !empty($field['secret'])
                ? Secrets::decrypt($raw[$field['name']] ?? '')
                : ($raw[$field['name']] ?? '');
        }
        return $out;
    }

    /**
     * La configuration telle qu'on la montre : les secrets n'en sortent jamais,
     * seulement l'information qu'ils sont posés.
     */
    public static function displayConfig(string $key): array
    {
        $destination = self::byKey($key);
        if ($destination === null) {
            return [];
        }
        $raw = self::rawConfig($key);
        $out = [];
        foreach ($destination['fields'] as $field) {
            $out[$field['name']] = !empty($field['secret'])
                ? Secrets::mask($raw[$field['name']] ?? '')
                : ($raw[$field['name']] ?? '');
        }
        return $out;
    }

    public static function isEnabled(string $key): bool
    {
        return Settings::get(self::settingKey($key, 'enabled')) === '1';
    }

    public static function isConfigured(string $key): bool
    {
        $destination = self::byKey($key);
        if ($destination === null) {
            return false;
        }
        $values = self::config($key);
        foreach ($destination['fields'] as $field) {
            if (!empty($field['required']) && trim((string) ($values[$field['name']] ?? '')) === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Enregistre la configuration. Un champ secret laissé vide conserve la
     * valeur précédente : l'écran n'affiche jamais le secret, il ne peut donc
     * pas le renvoyer, et l'effacer par inadvertance couperait la sauvegarde.
     */
    public static function setConfig(string $key, array $values): array
    {
        $destination = self::byKey($key);
        if ($destination === null) {
            return ['ok' => false, 'message' => 'Destination inconnue.'];
        }

        $previous = self::rawConfig($key);
        $next = [];
        foreach ($destination['fields'] as $field) {
            $name = $field['name'];
            $submitted = $values[$name] ?? null;
            if (!empty($field['secret'])) {
                $typed = trim((string) $submitted);
                $next[$name] = $typed !== '' ? Secrets::encrypt($typed) : ($previous[$name] ?? '');
            } elseif (($field['type'] ?? '') === 'checkbox') {
                $next[$name] = (bool) $submitted;
            } else {
                $next[$name] = mb_substr(trim((string) $submitted), 0, 4000);
            }
        }

        Settings::set(self::settingKey($key, 'config'), (string) json_encode($next, JSON_UNESCAPED_UNICODE));
        Drive::forgetTokens();
        return ['ok' => true];
    }

    public static function setEnabled(string $key, bool $enabled): bool
    {
        if (self::byKey($key) === null) {
            return false;
        }
        // Activer une destination incomplète promettrait une protection qui
        // n'existe pas.
        if ($enabled && !self::isConfigured($key)) {
            return false;
        }
        Settings::set(self::settingKey($key, 'enabled'), $enabled ? '1' : '0');
        return true;
    }

    public static function status(string $key): ?array
    {
        $stored = Settings::get(self::settingKey($key, 'status'));
        if ($stored === '') {
            return null;
        }
        $decoded = json_decode($stored, true);
        return is_array($decoded) ? $decoded : null;
    }

    public static function recordStatus(string $key, array $result): void
    {
        Settings::set(self::settingKey($key, 'status'), (string) json_encode([
            'ok' => (bool) ($result['ok'] ?? false),
            'message' => mb_substr((string) ($result['message'] ?? ''), 0, 400),
            'at' => gmdate('c'),
            'fileName' => (string) ($result['fileName'] ?? ''),
        ], JSON_UNESCAPED_UNICODE));
    }

    /** Le pilote d'une destination : c'est lui qui parle le protocole. */
    private static function driver(string $key): ?string
    {
        return match ($key) {
            'ftp' => Ftp::class,
            'drive' => Drive::class,
            default => null,
        };
    }

    public static function test(string $key): array
    {
        $driver = self::driver($key);
        if ($driver === null) {
            return ['ok' => false, 'message' => 'Destination inconnue.'];
        }
        if (!self::isConfigured($key)) {
            return ['ok' => false, 'message' => 'Configuration incomplète.'];
        }
        $result = $driver::test(self::config($key));
        self::recordStatus($key, $result);
        return $result;
    }

    public static function send(string $key, string $fileName, string $bytes): array
    {
        $driver = self::driver($key);
        if ($driver === null) {
            return ['ok' => false, 'message' => 'Destination inconnue.'];
        }
        if (!self::isConfigured($key)) {
            return ['ok' => false, 'message' => 'Configuration incomplète.'];
        }
        try {
            $result = $driver::upload(self::config($key), $fileName, $bytes);
        } catch (\Throwable $error) {
            $result = ['ok' => false, 'message' => $error->getMessage()];
        }
        self::recordStatus($key, $result + ['fileName' => $fileName]);
        return $result;
    }

    public static function remoteList(string $key): array
    {
        $driver = self::driver($key);
        if ($driver === null || !self::isConfigured($key)) {
            return ['ok' => false, 'message' => 'Configuration incomplète.'];
        }
        return $driver::list(self::config($key));
    }

    /**
     * Aligne la destination sur le nombre d'archives conservées : sans cela,
     * l'espace distant grossit indéfiniment et finit par refuser les dépôts.
     */
    public static function prune(string $key, int $keep): array
    {
        $driver = self::driver($key);
        if ($driver === null || !self::isConfigured($key)) {
            return ['ok' => false, 'message' => 'Configuration incomplète.'];
        }
        $listing = $driver::list(self::config($key));
        if (!$listing['ok']) {
            return $listing;
        }

        $removed = [];
        foreach (array_slice($listing['files'], $keep) as $file) {
            $handle = $key === 'drive' ? (string) $file['id'] : (string) $file['name'];
            if ($driver::remove(self::config($key), $handle)['ok']) {
                $removed[] = $file['name'];
            }
        }
        return ['ok' => true, 'removed' => $removed];
    }

    public static function enabled(): array
    {
        return array_values(array_filter(self::keys(), static fn (string $key): bool => self::isEnabled($key)));
    }

    /**
     * Envoie une archive à toutes les destinations actives. N'échoue jamais en
     * bloc : chaque destination est indépendante, et le résultat de chacune est
     * rendu pour être tracé et signalé.
     */
    public static function dispatch(string $fileName, string $bytes, int $keep = 24): array
    {
        $results = [];
        foreach (self::enabled() as $key) {
            $result = self::send($key, $fileName, $bytes);
            $results[] = ['key' => $key] + $result;

            if ($result['ok']) {
                self::prune($key, $keep);
                Audit::log('sauvegarde.externalisee', 'backups', null, ['destination' => $key, 'fichier' => $fileName]);
            } else {
                Audit::log('sauvegarde.externalisation_echec', 'backups', null,
                    ['destination' => $key, 'fichier' => $fileName, 'motif' => $result['message']]);
            }
        }
        return $results;
    }

    /**
     * Après chaque sauvegarde : envoi vers les destinations actives, et alerte
     * des administrateurs si l'une d'elles refuse. Une externalisation muette
     * qui échoue depuis trois semaines est le pire des cas — on croit être
     * couvert.
     */
    public static function afterBackup(string $fileName, string $bytes, int $keep = 24): array
    {
        $results = self::dispatch($fileName, $bytes, $keep);

        foreach ($results as $result) {
            if ($result['ok']) {
                continue;
            }
            $destination = self::byKey($result['key']);
            Webhooks::emit('sauvegarde.echec', [
                'destination' => $result['key'], 'fichier' => $fileName, 'motif' => $result['message'],
            ]);
            foreach (Db::all("SELECT id FROM users WHERE role = 'admin' AND active = 1") as $admin) {
                Notifications::push(
                    (int) $admin['id'],
                    'Externalisation en échec — ' . ($destination['label'] ?? $result['key']),
                    [
                        'kind' => 'sauvegarde',
                        'body' => "$fileName n'a pas pu être déposé : " . $result['message'],
                        'link' => '/sauvegardes#externalisation',
                        // Une même destination en échec n'alerte qu'une fois par jour.
                        'dedupeKey' => 'offsite:' . $result['key'] . ':' . gmdate('Y-m-d'),
                    ]
                );
            }
        }
        return $results;
    }

    /** Les destinations actives dont le dernier envoi a échoué. */
    public static function failing(): array
    {
        $out = [];
        foreach (self::enabled() as $key) {
            $status = self::status($key);
            if ($status !== null && $status['ok'] === false) {
                $out[] = ['key' => $key, 'status' => $status];
            }
        }
        return $out;
    }

    public static function list(): array
    {
        return array_map(static fn (array $destination): array => [
            'key' => $destination['key'],
            'label' => $destination['label'],
            'hint' => $destination['hint'],
            'fields' => $destination['fields'],
            'enabled' => self::isEnabled($destination['key']),
            'configured' => self::isConfigured($destination['key']),
            'values' => self::displayConfig($destination['key']),
            'status' => self::status($destination['key']),
        ], self::destinations());
    }
}
