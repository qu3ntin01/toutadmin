#!/usr/bin/env bash
# =============================================================================
# Toutadmin — édition PHP : installation sur un serveur Linux
# =============================================================================
#
#     sudo ./install.sh
#     sudo ./install.sh --domaine intranet.exemple.fr --courriel vous@exemple.fr
#
# L'édition PHP n'a aucune dépendance : pas de Composer, rien à compiler. Ce
# script pose donc PHP et ses deux extensions, nginx, le code, les dossiers,
# et s'arrête. La base, le premier compte et la licence sont posés par
# l'assistant, dans le navigateur, en huit étapes — et il se ferme de lui-même
# ensuite.
#
# Il est fait pour être relancé : chaque étape regarde d'abord si elle a déjà
# été faite, et relancer sur une instance qui tourne ne touche pas à ses
# données.
# -----------------------------------------------------------------------------
set -euo pipefail

DOSSIER=/opt/toutadmin
DONNEES=/var/lib/toutadmin
UTILISATEUR=www-data
DOMAINE=""
COURRIEL=""
SOURCE=""
PORT=80
SIMULATION=0

# Le fichier qui décrit la distribution. Paramétrable pour que le banc
# d'essai puisse jouer AlmaLinux 10 sur une machine qui ne l'est pas.
OS_RELEASE="${OS_RELEASE:-/etc/os-release}"

rouge() { printf '\033[31m%s\033[0m\n' "$*" >&2; }
vert()  { printf '\033[32m%s\033[0m\n' "$*"; }
gris()  { printf '\033[90m%s\033[0m\n' "$*"; }
titre() { printf '\n\033[1m%s\033[0m\n' "$*"; }

aide() {
  cat <<'FIN'
Usage : sudo ./install.sh [options]

  --dossier <chemin>  Où poser le code (défaut : /opt/toutadmin)
  --donnees <chemin>  Où ranger la base et les fichiers (défaut : /var/lib/toutadmin)
  --source <chemin>   Le code : une archive .tar.gz, un dossier, ou rien —
                      auquel cas le dossier courant sert de source.
  --domaine <nom>     Le nom servi par nginx (défaut : l'adresse IP)
  --courriel <adr>    L'adresse pour les avis d'expiration de Let's Encrypt.
  --port <n>          Port d'écoute de nginx (défaut : 80)
  --simulation        Dit ce qui serait fait sur cette machine, et s'arrête
                      sans rien poser.
  --aide              Ce message.
FIN
}

while [ $# -gt 0 ]; do
  case "$1" in
    --dossier)  DOSSIER="$2"; shift 2 ;;
    --donnees)  DONNEES="$2"; shift 2 ;;
    --source)   SOURCE="$2"; shift 2 ;;
    --domaine)  DOMAINE="$2"; shift 2 ;;
    --courriel) COURRIEL="$2"; shift 2 ;;
    --port)     PORT="$2"; shift 2 ;;
    --simulation|--dry-run) SIMULATION=1; shift ;;
    --aide|-h)  aide; exit 0 ;;
    *) rouge "Option inconnue : $1"; aide; exit 2 ;;
  esac
done

# En simulation, on ne touche à rien : être root n'est pas nécessaire.
[ "$SIMULATION" = 1 ] || [ "$(id -u)" -eq 0 ] \
  || { rouge "Ce script pose un service système : lancez-le avec sudo."; exit 1; }

# Deux questions, pas une : le gestionnaire de paquets dit la commande à
# écrire, os-release dit sur quelle distribution on est. AlmaLinux, Rocky et
# RHEL ont tous « dnf » et pourtant leur version change ce qui marche.
if   command -v apt-get >/dev/null 2>&1; then FAMILLE=apt
elif command -v dnf     >/dev/null 2>&1; then FAMILLE=dnf; UTILISATEUR=nginx
elif command -v yum     >/dev/null 2>&1; then FAMILLE=dnf; DNF=yum; UTILISATEUR=nginx
elif command -v zypper  >/dev/null 2>&1; then FAMILLE=zypper; UTILISATEUR=wwwrun
elif command -v pacman  >/dev/null 2>&1; then FAMILLE=pacman; UTILISATEUR=http
elif command -v apk     >/dev/null 2>&1; then FAMILLE=apk; UTILISATEUR=nginx
else
  rouge "Aucun gestionnaire de paquets connu (apt, dnf, zypper, pacman, apk)."
  rouge "Posez PHP 8.1+ avec pdo_sqlite et mbstring, puis relancez."
  exit 1
fi
DNF="${DNF:-dnf}"

DISTRO_ID=""; DISTRO_NOM=""; DISTRO_VERSION=""; DISTRO_LIKE=""
if [ -r "$OS_RELEASE" ]; then
  # Lu dans un sous-shell : ce fichier pose une dizaine de variables, et
  # certaines — « VERSION », « NAME » — écraseraient les nôtres.
  DISTRO_ID="$(. "$OS_RELEASE" 2>/dev/null; printf '%s' "${ID:-}")"
  DISTRO_NOM="$(. "$OS_RELEASE" 2>/dev/null; printf '%s' "${PRETTY_NAME:-${NAME:-}}")"
  DISTRO_VERSION="$(. "$OS_RELEASE" 2>/dev/null; printf '%s' "${VERSION_ID:-}")"
  DISTRO_LIKE="$(. "$OS_RELEASE" 2>/dev/null; printf '%s' "${ID_LIKE:-}")"
fi

# « Enterprise Linux » : RHEL et ce qui se reconstruit dessus — AlmaLinux,
# Rocky, CentOS Stream, Oracle. SELinux contraignant, firewalld allumé, et une
# AppStream dont les versions changent d'une version majeure à l'autre.
EL=0
case " $DISTRO_ID $DISTRO_LIKE " in
  *\ rhel\ *|*\ centos\ *|*\ fedora\ *|*almalinux*|*rocky*) EL=1 ;;
esac
EL_MAJEUR="${DISTRO_VERSION%%.*}"

titre "Toutadmin — édition PHP"
gris  "  distribution  ${DISTRO_NOM:-$FAMILLE} (paquets : $FAMILLE)"
gris  "  code          $DOSSIER"
gris  "  données       $DONNEES"
[ -n "$DOMAINE" ] && gris "  domaine       $DOMAINE"

# Faut-il demander un module à dnf ?
#
# Une seule fonction, et c'est le point : le code réel et la simulation en
# dépendent tous les deux. Chacun avait son test, et le banc d'essai vérifiait
# celui de la simulation — il aurait donc laissé passer un « dnf module »
# appelé sans regarder la version, c'est-à-dire précisément la panne qu'on
# veut éviter sur Enterprise Linux 10.
#
# En 9, le module est nécessaire : la version de PHP par défaut y est la 8.0,
# trop ancienne. En 10, la modularité a disparu — « dnf module » n'y est plus
# une commande, et l'appeler fait échouer l'installation.
module_a_demander() {
  [ "$EL" = 1 ] || return 1
  [ "${EL_MAJEUR:-0}" -le 9 ] || return 1
  "$DNF" module --help >/dev/null 2>&1
}

# ── PHP et ses deux extensions ───────────────────────────────────────────────
#
# pdo_sqlite parce que c'est la base par défaut, mbstring parce que seize
# langues dont l'arabe, le japonais et l'hindi. pdo_mysql suit, pour ceux qui
# choisiront MySQL à l'étape « Base de données ». Le reste — openssl, curl,
# sodium — est dans PHP depuis longtemps.
poser_php() {
  titre "PHP et ses extensions"
  case "$FAMILLE" in
    apt)
      apt-get update -qq
      apt-get install -y -qq php-fpm php-sqlite3 php-mbstring php-mysql php-curl nginx >/dev/null
      command -v curl >/dev/null 2>&1 || apt-get install -y -qq curl >/dev/null
      ;;
    dnf)
      # EL 9 range PHP dans un module, et sa version par défaut est la 8.0 —
      # trop ancienne. EL 10 a supprimé la modularité : « dnf module » n'y
      # existe plus, et l'appeler ferait échouer l'installation sur la
      # distribution la plus récente. Elle y sert la 8.3 directement.
      if module_a_demander; then
        "$DNF" module reset -y php >/dev/null 2>&1 || true
        "$DNF" module enable -y php:8.3 >/dev/null 2>&1 \
          || "$DNF" module enable -y php:8.2 >/dev/null 2>&1 || true
      fi
      "$DNF" install -y -q php-fpm php-pdo php-mbstring php-mysqlnd nginx >/dev/null
      # « dnf install curl » échoue sur les images minimales d'EL 9 et 10 :
      # elles portent « curl-minimal », qui entre en conflit, et dnf s'arrête
      # plutôt que de choisir. On ne l'installe donc que s'il manque vraiment.
      command -v curl >/dev/null 2>&1 \
        || "$DNF" install -y -q --allowerasing curl >/dev/null 2>&1 || true
      ;;
    zypper)
      zypper --non-interactive --quiet install php8-fpm php8-sqlite php8-mbstring \
             php8-mysql php8-curl nginx >/dev/null 2>&1 \
        || zypper --non-interactive --quiet install php-fpm php-sqlite php-mbstring nginx >/dev/null
      command -v curl >/dev/null 2>&1 || zypper --non-interactive --quiet install curl >/dev/null 2>&1 || true
      ;;
    pacman)
      pacman -Sy --noconfirm --needed php-fpm php-sqlite nginx >/dev/null
      command -v curl >/dev/null 2>&1 || pacman -S --noconfirm --needed curl >/dev/null 2>&1 || true
      ;;
    apk)
      apk add --no-cache php83-fpm php83-pdo_sqlite php83-mbstring php83-pdo_mysql \
                          php83-curl php83-session php83-openssl nginx >/dev/null
      command -v curl >/dev/null 2>&1 || apk add --no-cache curl >/dev/null 2>&1 || true
      ;;
  esac

  local version
  version="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;' 2>/dev/null || echo 0)"
  # 8.1 est le plancher réel : types, « match », chaînage sûr.
  if [ "$(printf '%s\n8.1\n' "$version" | sort -V | head -1)" != "8.1" ]; then
    rouge "PHP $version est trop ancien : il faut 8.1 ou plus récent."
    exit 1
  fi
  for ext in pdo_sqlite mbstring; do
    php -r "exit(extension_loaded('$ext') ? 0 : 1);" \
      || { rouge "L'extension $ext manque, et le produit ne s'en passe pas."; exit 1; }
  done
  vert "✓ PHP $version, avec pdo_sqlite et mbstring."
}

# ── Le code, et la racine web ────────────────────────────────────────────────
poser_code() {
  titre "Pose du code"
  local depuis="${SOURCE:-$PWD}"
  mkdir -p "$DOSSIER" "$DONNEES"

  if [ -f "$depuis" ]; then
    tar -xzf "$depuis" -C "$DOSSIER" --strip-components=1
  elif [ -d "$depuis" ] && [ -d "$depuis/app" ] && [ -d "$depuis/public" ]; then
    tar -c -C "$depuis" --exclude=.git --exclude=data --exclude=config.php --exclude=tests \
        . | tar -x -C "$DOSSIER"
  else
    rouge "Pas de code à installer : ni archive, ni dossier avec app/ et public/."
    exit 1
  fi

  # Le code appartient à root, le serveur ne fait que le lire. Les données lui
  # appartiennent, et à lui seul : elles portent des bulletins de paie.
  chown -R root:root "$DOSSIER"
  chown -R "$UTILISATEUR:$UTILISATEUR" "$DONNEES"
  chmod 750 "$DONNEES"
  vert "✓ Code en place."
}

# ── nginx ────────────────────────────────────────────────────────────────────
poser_nginx() {
  titre "Pose de nginx"
  local socket conf
  socket="$(find /run/php /var/run/php -name 'php*-fpm.sock' 2>/dev/null | head -1)"
  [ -n "$socket" ] || socket="127.0.0.1:9000"

  conf=/etc/nginx/conf.d/toutadmin.conf
  [ -d /etc/nginx/sites-available ] && conf=/etc/nginx/sites-available/toutadmin

  cat > "$conf" <<FIN
server {
    listen $PORT;
    server_name ${DOMAINE:-_};

    # La racine web est « public/ », et rien d'autre n'est servi : la base, la
    # configuration et les fichiers déposés vivent au-dessus, dans $DONNEES.
    root $DOSSIER/public;
    index index.php;

    # Les pièces reçues sont des factures scannées : deux mégaoctets ne
    # suffisent pas, et le refus arriverait sans explication utile.
    client_max_body_size 24m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        # Là où l'instance range son état, et où l'assistant écrira config.php.
        fastcgi_param TOUTADMIN_DATA $DONNEES;
        fastcgi_param TOUTADMIN_CONFIG $DONNEES/config.php;
        fastcgi_pass $([ "\${socket#/}" != "\$socket" ] && echo "unix:$socket" || echo "$socket");
    }

    # Un fichier caché déposé par mégarde ne doit pas se servir.
    location ~ /\. { deny all; }
}
FIN
  [ -d /etc/nginx/sites-enabled ] && ln -sf "$conf" /etc/nginx/sites-enabled/toutadmin
  [ -f /etc/nginx/sites-enabled/default ] && rm -f /etc/nginx/sites-enabled/default

  nginx -t >/dev/null 2>&1 || { rouge "La configuration nginx est refusée — rien n'est rechargé."; nginx -t; exit 1; }
  systemctl enable --now php*-fpm >/dev/null 2>&1 || rc-service php-fpm83 start >/dev/null 2>&1 || true
  systemctl enable --now nginx    >/dev/null 2>&1 || rc-service nginx start     >/dev/null 2>&1 || true
  systemctl reload nginx 2>/dev/null || true
  vert "✓ nginx en place."
}

poser_certificat() {
  [ -n "$DOMAINE" ] || return 0
  command -v certbot >/dev/null 2>&1 || { gris "  certbot n'est pas installé : l'instance est servie en HTTP."; return 0; }
  [ -n "$COURRIEL" ] || { gris "  certbot est là, mais il lui faut une adresse : relancez avec --courriel."; return 0; }
  titre "Certificat pour $DOMAINE"
  certbot --nginx -d "$DOMAINE" --non-interactive --agree-tos -m "$COURRIEL" --redirect \
    && vert "✓ Certificat posé, et le trafic en clair redirigé." \
    || rouge "Certbot n'a pas abouti : l'instance reste accessible en HTTP."
}

# ── SELinux ──────────────────────────────────────────────────────────────────
#
# Le piège d'AlmaLinux, de Rocky et de RHEL. SELinux y est contraignant, et
# nginx n'a par défaut le droit ni de lire des fichiers hors de sa racine
# habituelle, ni d'écrire où que ce soit. L'édition PHP écrit dans son dossier
# de données — la base, les pièces jointes, les sauvegardes — et sans
# étiquette adéquate, tout échoue en « Permission denied » alors que les
# droits Unix sont bons. On cherche des heures du côté de chown.
#
# On ne désactive jamais SELinux : le mettre en « permissive » pour que ça
# marche, c'est éteindre la protection de toute la machine pour un service.
poser_selinux() {
  command -v getenforce >/dev/null 2>&1 || return 0
  [ "$(getenforce 2>/dev/null)" = "Enforcing" ] || return 0

  titre "SELinux"

  # Le code se lit, les données s'écrivent. Deux types différents, et c'est
  # précisément la distinction qui protège : une faille dans le produit ne
  # peut pas réécrire le produit.
  if command -v semanage >/dev/null 2>&1; then
    semanage fcontext -a -t httpd_sys_content_t "${DOSSIER}(/.*)?" >/dev/null 2>&1 || true
    semanage fcontext -a -t httpd_sys_rw_content_t "${DONNEES}(/.*)?" >/dev/null 2>&1 || true
  else
    gris "  « semanage » est absent (paquet policycoreutils-python-utils) :"
    gris "  les étiquettes ne tiendront pas un relabel complet du système."
  fi
  if command -v restorecon >/dev/null 2>&1; then
    restorecon -R "$DOSSIER" >/dev/null 2>&1 || true
    restorecon -R "$DONNEES" >/dev/null 2>&1 || true
    vert "✓ Le code est étiqueté en lecture, les données en écriture."
  fi

  # php-fpm derrière nginx passe par une socket Unix, ce qui ne demande rien
  # de plus. Mais l'instance joint le service de licences sur l'Internet, et
  # cela, SELinux l'interdit par défaut.
  if command -v setsebool >/dev/null 2>&1; then
    setsebool -P httpd_can_network_connect on 2>/dev/null \
      && vert "✓ PHP a le droit de joindre le service de licences." \
      || rouge "À poser à la main : setsebool -P httpd_can_network_connect on"
  fi
}

# ── Le pare-feu ──────────────────────────────────────────────────────────────
#
# firewalld tourne par défaut sur Enterprise Linux et ne laisse pas passer le
# web. nginx écoute, tout va bien, et le site est injoignable de l'extérieur —
# sans le moindre message, puisque rien n'a échoué.
poser_parefeu() {
  if command -v firewall-cmd >/dev/null 2>&1 && firewall-cmd --state >/dev/null 2>&1; then
    titre "Pare-feu"
    firewall-cmd --permanent --add-service=http  >/dev/null 2>&1 || true
    [ -n "$DOMAINE" ] && firewall-cmd --permanent --add-service=https >/dev/null 2>&1 || true
    firewall-cmd --reload >/dev/null 2>&1 || true
    vert "✓ Le web passe le pare-feu."
  fi
  if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -qi '^Status: active'; then
    ufw allow 80/tcp >/dev/null 2>&1 || true
    [ -n "$DOMAINE" ] && ufw allow 443/tcp >/dev/null 2>&1 || true
    vert "✓ ufw : le web passe."
  fi
}

# ── La simulation ────────────────────────────────────────────────────────────
if [ "$SIMULATION" = 1 ]; then
  titre "Simulation — rien ne sera posé"
  gris  "  PHP"
  if command -v php >/dev/null 2>&1; then
    vert "    ✓ $(php -r 'echo PHP_VERSION;' 2>/dev/null) est déjà là."
  else
    echo "    absent : pose par $FAMILLE."
  fi
  if [ "$EL" = 1 ]; then
    # La même fonction que le code réel : ce que la simulation annonce est ce
    # qui sera fait, et non une seconde opinion qui pourrait diverger.
    if module_a_demander; then
      echo "    Enterprise Linux ${EL_MAJEUR} : le module « php:8.3 » sera demandé."
    else
      echo "    Enterprise Linux ${EL_MAJEUR} : pas de modularité, le paquet est pris tel quel."
    fi
  fi
  gris  "  Système"
  echo "    utilisateur   $UTILISATEUR"
  echo "    code          $DOSSIER"
  echo "    données       $DONNEES"
  echo "    nginx         port $PORT${DOMAINE:+, domaine $DOMAINE}"
  if [ "$(getenforce 2>/dev/null || true)" = "Enforcing" ]; then
    gris "  SELinux (contraignant)"
    echo "    httpd_sys_content_t    sur $DOSSIER"
    echo "    httpd_sys_rw_content_t sur $DONNEES"
    echo "    setsebool -P httpd_can_network_connect on"
  fi
  if command -v firewall-cmd >/dev/null 2>&1; then
    gris "  Pare-feu"; echo "    firewalld : ouverture du web"
  elif command -v ufw >/dev/null 2>&1; then
    gris "  Pare-feu"; echo "    ufw : ouverture du web s'il est actif"
  fi
  printf '\n'
  vert "Rien n'a été posé. Relancez sans --simulation pour installer."
  exit 0
fi

poser_php
poser_code
poser_selinux
poser_nginx
poser_parefeu
poser_certificat

titre "Installé."
if [ -n "$DOMAINE" ]; then
  echo "  Ouvrez http://$DOMAINE — l'assistant d'installation vous attend."
else
  echo "  Ouvrez http://$(hostname -I 2>/dev/null | awk '{print $1}') — l'assistant d'installation vous attend."
fi
cat <<FIN

  Il se déroule en huit étapes et pose lui-même config.php, la base, le premier
  compte et la licence. Il se ferme de lui-même ensuite : il n'y a aucun
  fichier à penser à supprimer.

  Sur un serveur exposé, posez d'abord un jeton pour que personne ne prenne
  l'instance entre maintenant et votre première visite — dans le bloc PHP de
  la configuration nginx :

      fastcgi_param INSTALL_TOKEN $(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n');

  puis : systemctl reload nginx

  La configuration : $DOSSIER
  Ses données      : $DONNEES
  Les journaux     : journalctl -u nginx -f  et  journalctl -u php*-fpm -f
FIN
