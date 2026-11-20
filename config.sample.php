<?php

/**
 * Configuration de déploiement.
 *
 * Ce fichier est lu depuis la racine du dossier. Pour le tenir ailleurs, posez
 * son chemin dans la variable d'environnement TOUTADMIN_CONFIG.
 *
 * L'installateur écrit config.php à partir de ce modèle. Ce fichier-ci est
 * versionné et documente chaque entrée ; config.php, lui, ne l'est jamais — il
 * porte la clé de session de l'instance.
 */

return [
    // Le moteur : 'sqlite' (défaut) ou 'mysql'. L'assistant d'installation
    // pose ce choix à sa troisième étape et écrit ce qu'il faut ici.
    'db_driver' => 'sqlite',

    // Base SQLite. À garder hors de la racine web : ici, data/ est au-dessus
    // de public/, donc aucune requête ne peut l'atteindre.
    'db_path' => __DIR__ . '/data/app.sqlite',

    // Les fichiers déposés, les sauvegardes et la clé de l'instance. Gardé même
    // sur MySQL : seules les tables partent dans le serveur de base.
    'data_dir' => __DIR__ . '/data',

    // Sur MySQL, les cinq entrées qui suivent remplacent db_path. Un db_host
    // commençant par « / » est compris comme une socket Unix, et db_port est
    // alors ignoré. L'extension pdo_mysql doit être présente.
    //
    //   'db_driver'   => 'mysql',
    //   'db_host'     => '127.0.0.1',
    //   'db_port'     => 3306,
    //   'db_name'     => 'toutadmin',
    //   'db_user'     => 'toutadmin',
    //   'db_password' => '…',
    //
    // Changer db_driver sur une instance qui tourne ne migre rien : elle
    // pointera sur une base vide et vous accueillera avec l'assistant. Les
    // données restées de l'autre côté n'ont pas bougé, et la sauvegarde du
    // produit ne traverse pas les moteurs — c'est voulu, et dit à l'écran.

    // Clé propre à l'instance. À engendrer une fois : bin2hex(random_bytes(32)).
    'session_secret' => 'à-remplacer-à-l-installation',

    // Jeton d'installation. Entre le dépôt des fichiers sur l'hébergement et
    // le passage de l'assistant, l'instance est à qui la trouve : posez ici une
    // valeur au hasard, elle sera demandée à l'installation. Laissé vide, rien
    // n'est demandé. (La variable d'environnement INSTALL_TOKEN fait aussi
    // l'affaire.)
    'install_token' => '',

    // Adresse publique du site, utile aux liens envoyés par courriel.
    'base_url' => 'https://exemple.fr',

    // À n'activer que derrière un proxy de confiance : sinon n'importe qui
    // pourrait annoncer l'adresse IP de son choix dans un en-tête.
    'trust_proxy' => false,

    // Plafonds : tentatives de connexion par quart d'heure et par adresse,
    // requêtes par minute et par adresse.
    'login_rate_limit' => 10,
    'global_rate_limit' => 300,

    // Expiration : inactivité, puis durée absolue qu'aucune activité ne repousse.
    'session_idle_minutes' => 60,
    'session_max_hours' => 12,

    /*
     * Le code de cette instance s'écrit-il ?
     *
     * Laissez « false » sur une installation ordinaire : l'écran « Mises à
     * jour » pose alors les versions lui-même, comme il sait le faire.
     *
     * Passez-le à « true » quand le dossier du produit ne survit pas à une
     * mise en service — une image de conteneur, un paquet système, un code
     * posé en lecture seule. L'écran continue de signaler les versions, et
     * remplace le bouton par le geste qui convient : changer d'image. Sans
     * cela, la mise à jour aurait l'air de marcher, puis se déferait toute
     * seule au premier remplacement.
     *
     * La variable d'environnement CODE_IMMUABLE l'emporte sur cette ligne —
     * c'est elle que posent les images, et elle évite d'avoir à écrire dans
     * config.php.
     */
    'code_immuable' => false,

];
