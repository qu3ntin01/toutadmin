<?php

declare(strict_types=1);

namespace App\Core;

/** Réglages de l'instance : posés à l'installation, modifiables ensuite. */
final class Settings
{
    /**
     * L'adresse du service de licences. Une seule écriture, que tout le reste relit.
     *
     * Le nom de machine est neutre, et c'est délibéré : ce que l'instance
     * appelle, c'est un service qui répond « voici votre bail » ou « non ». Un
     * nom qui désignerait une console d'administration apprendrait son
     * existence à qui lit ce fichier — et le code de l'instance, le client l'a
     * chez lui.
     *
     * Les instances déjà posées gardent l'adresse écrite dans leurs réglages :
     * le serveur répond aux deux noms, une adresse changée sous les pieds d'un
     * client serait une panne qu'il n'a pas demandée.
     */
    public const EDITEUR_PAR_DEFAUT = 'https://licences.toutadmin.com';

    /**
     * Où joindre l'éditeur.
     *
     * Elle n'est pas demandée à l'installation, et c'est voulu : l'éditeur d'un
     * CMS est le même pour toutes les instances. Poser la question revenait à
     * demander au client une réponse qu'il n'a pas, et à transformer une faute
     * de frappe en « éditeur injoignable » sans qu'il puisse comprendre
     * pourquoi.
     *
     * « EDITEUR_URL » la remplace pour les seuls cas où elle doit l'être : la
     * suite de tests, qui dresse un faux éditeur sur un port local, et un
     * déploiement de recette qui vise un panneau de préproduction. Posée et
     * vide, elle veut dire « aucun éditeur » — une instance fermée, qui ne
     * téléphone jamais dehors ; c'est différent de « non posée », qui veut dire
     * « celui d'origine ». C'est un réglage de machine, posé par qui tient le
     * serveur — pas un champ offert à qui installe.
     */
    public static function adresseEditeur(): string
    {
        $pose = getenv('EDITEUR_URL');
        $brut = $pose === false ? self::EDITEUR_PAR_DEFAUT : $pose;
        return rtrim(trim((string) $brut), '/');
    }

    /** L'espace client, où l'on récupère sa licence. */
    public const MANAGER_PAR_DEFAUT = 'https://manager.toutadmin.com';

    /** Le site, où l'on va lire les offres. */
    public const SITE_PAR_DEFAUT = 'https://toutadmin.com';

    /**
     * Où le client récupère sa licence, et où il lit les offres.
     *
     * Mêmes règles que pour l'éditeur, et pour les mêmes raisons : l'adresse
     * est la même pour toutes les instances, elle n'est donc pas demandée à
     * l'installation. « MANAGER_URL » et « SITE_URL » la remplacent pour la
     * suite de tests et pour une recette. Posées et vides, elles veulent dire
     * « pas d'espace client » et « pas de site » : l'assistant n'offre alors
     * plus les liens correspondants, ce qui est exactement ce qu'il faut sur
     * une instance fermée.
     */
    public static function adresseManager(): string
    {
        $pose = getenv('MANAGER_URL');
        $brut = $pose === false ? self::MANAGER_PAR_DEFAUT : $pose;
        return rtrim(trim((string) $brut), '/');
    }

    public static function adresseSite(): string
    {
        $pose = getenv('SITE_URL');
        $brut = $pose === false ? self::SITE_PAR_DEFAUT : $pose;
        return rtrim(trim((string) $brut), '/');
    }

    public const DEFAULTS = [
        'company_name' => 'Toutadmin',
        'default_locale' => 'fr',
        'annual_leave_days' => '25',
        'installed_at' => '',
        'installed_version' => '',
        'theme_palette' => 'lavande',
        'require_2fa_admin' => '0',
        'require_2fa_all' => '0',
        'audit_retention_days' => '365',
        'trash_retention_days' => '30',
        'backup_enabled' => '1',
        'backup_interval_minutes' => '60',
        'backup_keep' => '24',
        'password_max_age_days' => '0',
        // Mises à jour : d'où elles viennent, avec quelle clé, et ce qu'on fait
        // quand il y en a une. « avertir » ne touche à rien tout seul ; « auto »
        // applique au passage de la tâche planifiée. Le choix appartient à
        // l'instance, pas à l'éditeur.
        //
        // L'adresse est celle du service de licences, et elle est connue : la
        // laisser vide obligeait chaque installation à la retrouver quelque part
        // et à la recopier sans faute — une faute de frappe y ressemble à une
        // panne de réseau. Elle reste modifiable, parce qu'une maison qui
        // héberge son propre panneau, ou une instance d'essai, doit pouvoir
        // pointer ailleurs.
        //
        // Rien n'est contacté pour autant : sans clé d'instance et sans clé
        // publique, l'instance n'est pas configurée et aucune requête ne part.
        'update_editor_url' => self::EDITEUR_PAR_DEFAUT,
        'update_instance_key' => '',
        'update_public_key' => '',
        'update_channel' => 'stable',
        'update_mode' => 'avertir',
        'update_instance_name' => '',
        'update_last_check' => '',
        'update_last_error' => '',
        'update_available' => '',
        /* Licence de l'instance. Le jeton est signé par l'éditeur et rangé
           chiffré ; l'empreinte est tirée une fois à l'installation et ne bouge
           plus. La clé publique qui vérifie tout cela n'est pas ici : elle est
           dans le code, sans quoi il suffirait d'y coller la sienne. */
        'licence_jeton' => '',
        'licence_empreinte' => '',
        'licence_adresse' => '',
        'licence_derniere_verif' => '',
        'licence_dernier_refus' => '',
        // La date de la première vérification en échec depuis la dernière
        // réussie. Vide tant que tout va bien. C'est de là que part le compte à
        // rebours de quarante-huit heures, pas de l'échéance du jeton : une
        // licence révoquée ou un panneau qui ne répond plus sont des problèmes
        // d'aujourd'hui, pas de la date écrite dans le jeton.
        'licence_echec_depuis' => '',
        // La marque haute de l'horloge : le plus tard dont cette instance ait
        // la preuve. Elle ne redescend jamais — voir LicenceHorloge.
        'licence_horloge_haute' => '',
    ];

    public static function get(string $key): string
    {
        $value = Db::value('SELECT value FROM settings WHERE key = ?', [$key]);
        return $value === null ? (self::DEFAULTS[$key] ?? '') : (string) $value;
    }

    public static function set(string $key, string|int $value): void
    {
        Db::run(
            'INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime(\'now\'))
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            [$key, (string) $value]
        );
    }

    public static function setMany(array $entries): void
    {
        Db::transaction(static function () use ($entries): void {
            foreach ($entries as $key => $value) {
                self::set((string) $key, $value);
            }
        });
    }

    public static function all(): array
    {
        $rows = Db::all('SELECT key, value FROM settings');
        $out = self::DEFAULTS;
        foreach ($rows as $row) {
            $out[$row['key']] = $row['value'];
        }
        return $out;
    }

    public static function isTrue(string $key): bool
    {
        return self::get($key) === '1';
    }
}
