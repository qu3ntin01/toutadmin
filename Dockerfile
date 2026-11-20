# =============================================================================
# Toutadmin — édition PHP
# =============================================================================
#
# Une seule étape, et c'est la mesure du produit : pas de Composer, pas de
# node_modules, rien à compiler. Le dossier se dépose tel quel, et tout ce que
# l'image ajoute à PHP tient en une extension — pdo_mysql, pour ceux qui
# préfèrent MySQL à SQLite.
#
#     docker build -t toutadmin-php:1.0.0 .
#     docker run -p 8080:80 -v toutadmin-donnees:/donnees toutadmin-php:1.0.0
#
# Puis ouvrir http://localhost:8080 : l'assistant s'y déroule en huit étapes et
# se ferme de lui-même dès qu'un compte existe.
# -----------------------------------------------------------------------------
FROM php:8.3-apache

# L'image officielle apporte déjà mbstring, pdo_sqlite, openssl, curl et
# sodium — ce dernier vérifie les signatures Ed25519 des licences et des mises
# à jour. Il ne manque que de quoi parler à MySQL.
RUN docker-php-ext-install -j"$(nproc)" pdo_mysql \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*

# Réglages de production : les erreurs vont au journal du conteneur et jamais à
# l'écran. Une trace d'erreur affichée à un visiteur donne des chemins, des
# versions et parfois des requêtes.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && { \
      echo 'display_errors = Off'; \
      echo 'log_errors = On'; \
      echo 'error_log = /dev/stderr'; \
      echo 'expose_php = Off'; \
      # Les pièces reçues sont des factures scannées : deux mégaoctets ne
      # suffisent pas, et le refus arriverait sans explication utile.
      echo 'upload_max_filesize = 24M'; \
      echo 'post_max_size = 26M'; \
    } > "$PHP_INI_DIR/conf.d/toutadmin.ini"

# La racine web est « public/ », et rien d'autre n'est servi. C'est la règle du
# produit : la base, la configuration et les fichiers déposés vivent ailleurs,
# et il n'y a donc rien à interdire par .htaccess.
ENV APACHE_DOCUMENT_ROOT=/app/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
      /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
 && printf '%s\n' \
      '<Directory /app/public>' \
      '  AllowOverride All' \
      '  Require all granted' \
      '</Directory>' \
      'ServerTokens Prod' \
      'ServerSignature Off' \
      > /etc/apache2/conf-available/toutadmin.conf \
 && a2enconf toutadmin

WORKDIR /app
COPY app ./app
COPY public ./public
COPY tools ./tools
COPY VERSION ./VERSION
COPY config.sample.php ./config.sample.php

# Le dossier de données, hors racine web, et qui appartient au serveur.
RUN mkdir -p /donnees && chown www-data:www-data /donnees
VOLUME ["/donnees"]

# Où l'instance range son état, et où l'assistant écrira config.php. Les deux
# sont dans le volume : une instance recréée reste la même, y compris aux yeux
# du service de licences.
ENV TOUTADMIN_CONFIG=/donnees/config.php \
    TOUTADMIN_DATA=/donnees
EXPOSE 80

# Le serveur répond sur « / » dans les deux états : redirigé vers l'assistant
# tant qu'il n'est pas installé, vers la connexion ensuite.
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD php -r "exit(@file_get_contents('http://127.0.0.1/') === false ? 1 : 0);"
