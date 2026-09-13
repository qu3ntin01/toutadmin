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
    // Base SQLite. À garder hors de la racine web : ici, data/ est au-dessus
    // de public/, donc aucune requête ne peut l'atteindre.
    'db_path' => __DIR__ . '/data/app.sqlite',
    'data_dir' => __DIR__ . '/data',

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
];
