#!/usr/bin/env bash
# =============================================================================
# Toutadmin — édition Node : installation sur un serveur Linux
# =============================================================================
#
#     curl -fsSL https://.../install.sh | sudo bash
#     sudo ./install.sh --port 3000 --domaine intranet.exemple.fr
#
# Ce que fait ce script, et surtout ce qu'il ne fait pas.
#
# Il met le moteur en place : Node, un utilisateur système, le code dans
# /opt, les données dans /var/lib, un service qui redémarre tout seul, et —
# si on lui donne un domaine — un reverse-proxy avec son certificat. Puis il
# s'arrête et donne une adresse.
#
# Il ne pose ni base, ni compte d'administration, ni licence. C'est
# l'assistant qui le fait, dans le navigateur, en huit étapes, et qui se
# ferme de lui-même une fois le compte créé. Un script qui poserait le
# compte à la place de l'assistant devrait aussi choisir un mot de passe,
# donc l'écrire quelque part — et ce quelque part serait le maillon faible de
# toute l'installation.
#
# Il est fait pour être relancé : chaque étape regarde d'abord si elle a déjà
# été faite. Relancer après un échec reprend où l'on en était, et relancer sur
# une instance qui tourne ne touche pas à ses données.
#
# ── Où il tourne ────────────────────────────────────────────────────────────
#
#   Debian, Ubuntu, et tout ce qui a apt
#   AlmaLinux, Rocky, RHEL, CentOS Stream, Fedora — 9 comme 10
#   openSUSE et SLES
#   Arch
#   Alpine (sans systemd : le service est à lancer à la main)
#
# Trois choses lui valent sa place sur Enterprise Linux, et aucune n'est
# décorative :
#
#   SELinux y est contraignant. Sans « httpd_can_network_connect », nginx ne
#   peut pas joindre le service local : le visiteur reçoit 502, le journal
#   dit « Permission denied », le service tourne parfaitement, et l'on cherche
#   une heure du mauvais côté. On ne désactive jamais SELinux pour autant.
#
#   firewalld y est allumé et ferme le web. Le site est injoignable sans le
#   moindre message d'erreur, puisque rien n'a échoué.
#
#   La modularité a disparu en 10. « dnf module enable » y est une commande
#   qui n'existe pas — un script qui l'appelle sans regarder échoue sur la
#   distribution la plus récente.
#
# « --simulation » dit ce qui serait fait sur cette machine-ci, sans rien
# poser. Neuf distributions sont éprouvées à chaque modification par
# « deploiement/essais/distributions.sh », qui les joue avec de faux outils.
# -----------------------------------------------------------------------------
set -euo pipefail

VERSION_NODE=22
UTILISATEUR=toutadmin
DOSSIER=/opt/toutadmin
DONNEES=/var/lib/toutadmin
PORT=3000
DOMAINE=""
SOURCE=""
COURRIEL=""
SIMULATION=0

# Le fichier qui décrit la distribution. Paramétrable pour que le banc
# d'essai puisse jouer AlmaLinux 10 sur une machine qui ne l'est pas : c'est
# la seule façon d'éprouver ce script sur huit distributions sans huit
# serveurs.
OS_RELEASE="${OS_RELEASE:-/etc/os-release}"

rouge() { printf '\033[31m%s\033[0m\n' "$*" >&2; }
vert()  { printf '\033[32m%s\033[0m\n' "$*"; }
gris()  { printf '\033[90m%s\033[0m\n' "$*"; }
titre() { printf '\n\033[1m%s\033[0m\n' "$*"; }

aide() {
  cat <<'FIN'
Usage : sudo ./install.sh [options]

  --port <n>          Port d'écoute du service (défaut : 3000)
  --dossier <chemin>  Où poser le code (défaut : /opt/toutadmin)
  --donnees <chemin>  Où ranger la base et les fichiers (défaut : /var/lib/toutadmin)
  --source <chemin>   Le code à installer : une archive .tar.gz, un dossier, ou
                      rien — auquel cas le dossier courant sert de source.
  --domaine <nom>     Pose un reverse-proxy nginx sur ce domaine, et un
                      certificat Let's Encrypt si certbot est disponible.
  --courriel <adr>    L'adresse que Let's Encrypt utilise pour les avis
                      d'expiration. Demandée seulement avec --domaine.
  --simulation        Dit ce qui serait fait sur cette machine, et s'arrête
                      sans rien poser. Utile avant de lancer pour de vrai sur
                      un serveur qui sert déjà, et pour savoir ce que le
                      script a reconnu de la distribution.
  --aide              Ce message.
FIN
}

while [ $# -gt 0 ]; do
  case "$1" in
    --port)     PORT="$2"; shift 2 ;;
    --dossier)  DOSSIER="$2"; shift 2 ;;
    --donnees)  DONNEES="$2"; shift 2 ;;
    --source)   SOURCE="$2"; shift 2 ;;
    --domaine)  DOMAINE="$2"; shift 2 ;;
    --courriel) COURRIEL="$2"; shift 2 ;;
    --simulation|--dry-run) SIMULATION=1; shift ;;
    --aide|-h)  aide; exit 0 ;;
    *) rouge "Option inconnue : $1"; aide; exit 2 ;;
  esac
done

# En simulation, on ne touche à rien : être root n'est pas nécessaire.
[ "$SIMULATION" = 1 ] || [ "$(id -u)" -eq 0 ] \
  || { rouge "Ce script pose un service système : lancez-le avec sudo."; exit 1; }

# ── Quelle distribution ? ────────────────────────────────────────────────────
#
# Deux questions, pas une.
#
# Le gestionnaire de paquets dit la commande à écrire. Mais il ne suffit pas :
# AlmaLinux, Rocky et RHEL ont tous « dnf » et pourtant leur version change ce
# qui marche — la modularité a disparu en 10, et il faut savoir si l'on y est.
# Le nom et la version viennent donc d'os-release, qui est le seul endroit où
# toutes les distributions se décrivent de la même façon.
if   command -v apt-get >/dev/null 2>&1; then FAMILLE=apt
elif command -v dnf     >/dev/null 2>&1; then FAMILLE=dnf
elif command -v yum     >/dev/null 2>&1; then FAMILLE=dnf; DNF=yum
elif command -v zypper  >/dev/null 2>&1; then FAMILLE=zypper
elif command -v pacman  >/dev/null 2>&1; then FAMILLE=pacman
elif command -v apk     >/dev/null 2>&1; then FAMILLE=apk
else
  rouge "Aucun gestionnaire de paquets connu (apt, dnf, zypper, pacman, apk)."
  rouge "Posez Node ${VERSION_NODE} à la main, puis relancez — le reste ne dépend pas de la distribution."
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

# « Enterprise Linux » : RHEL et tout ce qui se reconstruit dessus — AlmaLinux,
# Rocky, CentOS Stream, Oracle. Ils partagent SELinux en mode contraignant,
# firewalld allumé, et une AppStream où les versions changent d'une version
# majeure à l'autre. C'est la famille qui demande le plus de soin.
EL=0
case " $DISTRO_ID $DISTRO_LIKE " in
  *\ rhel\ *|*\ centos\ *|*\ fedora\ *|*almalinux*|*rocky*) EL=1 ;;
esac
EL_MAJEUR="${DISTRO_VERSION%%.*}"

titre "Toutadmin — installation"
gris  "  distribution  ${DISTRO_NOM:-$FAMILLE} (paquets : $FAMILLE)"
gris  "  code          $DOSSIER"
gris  "  données       $DONNEES"
gris  "  port          $PORT"
[ -n "$DOMAINE" ] && gris "  domaine       $DOMAINE"

# ── Node ─────────────────────────────────────────────────────────────────────

# Faut-il demander un module à dnf ?
#
# Une seule fonction, et c'est le point : le code réel et la simulation en
# dépendent tous les deux. Ils avaient chacun leur test, et le banc d'essai
# vérifiait celui de la simulation — il aurait donc laissé passer un « dnf
# module » appelé sans regarder la version, c'est-à-dire précisément la panne
# qu'on veut éviter sur Enterprise Linux 10.
#
# En 9, le module est nécessaire : la version par défaut y est trop ancienne.
# En 10, la modularité a disparu — « dnf module » n'y est plus une commande, et
# l'appeler fait échouer l'installation.
module_a_demander() {
  [ "$EL" = 1 ] || return 1
  [ "${EL_MAJEUR:-0}" -le 9 ] || return 1
  "$DNF" module --help >/dev/null 2>&1
}

# La version en place convient-elle ?
node_assez_recent() {
  command -v node >/dev/null 2>&1 || return 1
  local majeure
  majeure="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null)" || return 1
  [ -n "$majeure" ] && [ "$majeure" -ge "$VERSION_NODE" ] 2>/dev/null
}

# Curl et les autorités de certification, que tout le reste suppose.
#
# « dnf install curl » échoue sur les images minimales d'Enterprise Linux 9 et
# 10 : elles portent « curl-minimal », qui entre en conflit avec « curl », et
# dnf s'arrête plutôt que de choisir. On ne l'installe donc que s'il manque
# vraiment, et « --allowerasing » tranche le conflit quand il faut y aller.
paquets_de_base() {
  case "$FAMILLE" in
    apt)
      command -v curl >/dev/null 2>&1 || apt-get install -y -qq curl >/dev/null
      apt-get install -y -qq ca-certificates gnupg >/dev/null 2>&1 || true
      ;;
    dnf)
      "$DNF" install -y -q ca-certificates >/dev/null 2>&1 || true
      command -v curl >/dev/null 2>&1 \
        || "$DNF" install -y -q --allowerasing curl >/dev/null 2>&1 \
        || "$DNF" install -y -q curl-minimal >/dev/null 2>&1 || true
      ;;
    zypper)
      zypper --non-interactive --quiet install curl ca-certificates >/dev/null 2>&1 || true
      ;;
    pacman)
      pacman -Sy --noconfirm --needed curl ca-certificates >/dev/null 2>&1 || true
      ;;
    apk)
      apk add --no-cache curl ca-certificates >/dev/null 2>&1 || true
      ;;
  esac
  command -v curl >/dev/null 2>&1 || { rouge "curl est absent et je n'ai pas pu le poser."; exit 1; }
}

installer_node() {
  if node_assez_recent; then
    vert "✓ Node $(node -v) est déjà là."
    return
  fi
  if command -v node >/dev/null 2>&1; then
    gris "  Node $(node -v) est trop ancien : il faut au moins la ${VERSION_NODE}."
  fi

  titre "Pose de Node ${VERSION_NODE}"

  # Le dépôt de la distribution d'abord, NodeSource ensuite.
  #
  # L'inverse était fait, et c'était défendable il y a trois ans : les
  # distributions traînaient derrière. Elles ne traînent plus — Debian 13,
  # Ubuntu 24.04, AlmaLinux 10 et Rocky 10 servent tous une version qui
  # convient. Et le paquet de la distribution est celui que les mises à jour
  # de sécurité de la distribution suivront, ce qu'un dépôt tiers ne fait pas
  # à la même cadence.
  #
  # NodeSource reste le recours quand la version servie est trop vieille —
  # EL 9 sans son module, Debian 12, Ubuntu 22.04.
  case "$FAMILLE" in
    apt)
      apt-get update -qq
      paquets_de_base
      apt-get install -y -qq nodejs npm >/dev/null 2>&1 || true
      ;;
    dnf)
      paquets_de_base
      # EL 9 range Node dans un module, et il faut dire lequel. EL 10 a
      # supprimé la modularité : « dnf module » n'y existe plus, et l'appeler
      # ferait échouer l'installation sur la distribution la plus récente.
      if module_a_demander; then
        "$DNF" module reset -y nodejs >/dev/null 2>&1 || true
        "$DNF" module enable -y "nodejs:${VERSION_NODE}" >/dev/null 2>&1 || true
      fi
      "$DNF" install -y -q nodejs npm >/dev/null 2>&1 || true
      ;;
    zypper)
      paquets_de_base
      zypper --non-interactive --quiet install "nodejs${VERSION_NODE}" "npm${VERSION_NODE}" >/dev/null 2>&1 \
        || zypper --non-interactive --quiet install nodejs npm >/dev/null 2>&1 || true
      ;;
    pacman)
      paquets_de_base
      pacman -Sy --noconfirm --needed nodejs npm >/dev/null 2>&1 || true
      ;;
    apk)
      paquets_de_base
      apk add --no-cache nodejs npm >/dev/null 2>&1 || true
      ;;
  esac

  # Ce que la distribution a servi suffit-il ? Sinon, le dépôt de Node.
  if ! node_assez_recent; then
    if command -v node >/dev/null 2>&1; then
      gris "  La distribution sert Node $(node -v) : trop ancien, on prend le dépôt de Node."
    else
      gris "  La distribution ne sert pas Node : on prend son dépôt à lui."
    fi
    case "$FAMILLE" in
      apt)
        curl -fsSL "https://deb.nodesource.com/setup_${VERSION_NODE}.x" | bash - >/dev/null
        apt-get install -y -qq nodejs >/dev/null
        ;;
      dnf)
        curl -fsSL "https://rpm.nodesource.com/setup_${VERSION_NODE}.x" | bash - >/dev/null
        "$DNF" install -y -q nodejs >/dev/null
        ;;
      *)
        rouge "Node ${VERSION_NODE} n'est pas disponible ici, et le dépôt de Node ne sert que Debian et Enterprise Linux."
        rouge "Posez-le à la main — par nvm, par fnm, ou par l'archive officielle — puis relancez."
        exit 1
        ;;
    esac
  fi

  command -v node >/dev/null 2>&1 || { rouge "Node n'est pas là après installation."; exit 1; }
  node_assez_recent || { rouge "Node $(node -v) reste trop ancien : il faut au moins la ${VERSION_NODE}."; exit 1; }
  vert "✓ Node $(node -v)"
}

# ── L'utilisateur, les dossiers ──────────────────────────────────────────────
poser_utilisateur() {
  if id "$UTILISATEUR" >/dev/null 2>&1; then
    vert "✓ L'utilisateur « $UTILISATEUR » existe déjà."
  else
    titre "Création de l'utilisateur « $UTILISATEUR »"
    # Sans interpréteur et sans mot de passe : ce compte fait tourner un
    # service, il ne sert à personne pour se connecter.
    if [ "$FAMILLE" = apk ]; then
      adduser -S -D -H -s /sbin/nologin "$UTILISATEUR"
    else
      useradd --system --no-create-home --shell /usr/sbin/nologin "$UTILISATEUR"
    fi
    vert "✓ Utilisateur créé."
  fi

  mkdir -p "$DOSSIER" "$DONNEES"
  # Le code appartient à root et l'utilisateur le lit seulement : un service
  # qui ne peut pas réécrire son propre code est une porte de moins.
  chown -R root:root "$DOSSIER"
  chown -R "$UTILISATEUR:$UTILISATEUR" "$DONNEES"
  chmod 750 "$DONNEES"
}

# ── Le code ──────────────────────────────────────────────────────────────────
poser_code() {
  titre "Pose du code"
  local depuis="${SOURCE:-$PWD}"

  if [ -f "$depuis" ]; then
    gris "  depuis l'archive $depuis"
    tar -xzf "$depuis" -C "$DOSSIER" --strip-components=1
  elif [ -d "$depuis" ] && [ -f "$depuis/package.json" ]; then
    gris "  depuis le dossier $depuis"
    # Les dossiers d'exécution et de travail ne suivent jamais : ils
    # appartiennent à l'instance d'où l'on copie, pas à celle qu'on installe.
    # Ce qui ne part pas : les dépendances (réinstallées pour cette machine),
    # l'historique, les données et les secrets de l'instance d'où l'on copie,
    # la suite de tests et les fichiers de conteneur — un serveur n'en a que
    # faire, et chaque fichier inutile est un fichier de plus à auditer.
    tar -c -C "$depuis" \
        --exclude=node_modules --exclude=.git --exclude=data --exclude=.env \
        --exclude=tests --exclude=.claude --exclude=Dockerfile \
        --exclude=.dockerignore --exclude=docker-compose.yml --exclude='*.log' \
        . | tar -x -C "$DOSSIER"
  else
    rouge "Pas de code à installer : ni archive, ni dossier avec un package.json."
    rouge "Donnez --source, ou lancez le script depuis le dossier du produit."
    exit 1
  fi

  titre "Installation des dépendances"
  # « ci » et non « install » : on veut exactement ce que dit le verrou, pas
  # la dernière version compatible de chacune.
  ( cd "$DOSSIER" && npm ci --omit=dev --no-audit --no-fund )
  chown -R root:root "$DOSSIER"
  vert "✓ Code et dépendances en place."
}

# ── Le service ───────────────────────────────────────────────────────────────
poser_service() {
  if ! command -v systemctl >/dev/null 2>&1; then
    gris "  systemd est absent : le service n'est pas posé."
    gris "  Lancez à la main : DB_PATH=$DONNEES/app.sqlite PORT=$PORT node $DOSSIER/src/server.js"
    return
  fi

  titre "Pose du service"
  cat > /etc/systemd/system/toutadmin.service <<FIN
[Unit]
Description=Toutadmin — CMS d'entreprise
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$UTILISATEUR
Group=$UTILISATEUR
WorkingDirectory=$DOSSIER
ExecStart=$(command -v node) $DOSSIER/src/server.js
Restart=always
RestartSec=5

Environment=NODE_ENV=production
Environment=PORT=$PORT
# Tout ce que le produit range sur le disque suit ce chemin : la base, la clé
# de session, les photos de profil, le coffre-fort, les CV, le parapheur, les
# pièces reçues, les sauvegardes.
Environment=DB_PATH=$DONNEES/app.sqlite
$([ -n "$DOMAINE" ] && echo "# Derrière nginx : sans cela, l'adresse vue est celle du proxy." && echo "Environment=TRUST_PROXY=1")
# Le code est posé par ce script et remplacé par lui : l'écran « Mises à jour »
# signale les versions mais n'écrit rien. Relancez ce script pour changer de
# version — c'est le même geste que la première fois.
Environment=CODE_IMMUABLE=1

# Le service ne touche à rien d'autre qu'à ses données.
NoNewPrivileges=yes
PrivateTmp=yes
ProtectSystem=strict
ProtectHome=yes
ReadWritePaths=$DONNEES

[Install]
WantedBy=multi-user.target
FIN

  systemctl daemon-reload
  systemctl enable --now toutadmin >/dev/null 2>&1 || systemctl restart toutadmin
  vert "✓ Service posé et démarré."
}

# ── SELinux ──────────────────────────────────────────────────────────────────
#
# Le piège numéro un d'AlmaLinux, de Rocky et de RHEL, et celui qui fait
# perdre le plus de temps parce qu'il ne ressemble pas à ce qu'il est.
#
# SELinux y est contraignant par défaut. Un nginx qui essaie de joindre
# 127.0.0.1:3000 se fait refuser la connexion — pas par le pare-feu, pas par
# le service, par la politique. Le visiteur reçoit « 502 Bad Gateway », le
# journal de nginx dit « Permission denied », le service tourne parfaitement,
# et l'on cherche pendant une heure du côté du service.
#
# Un seul interrupteur le règle, et il se pose une fois pour toutes.
#
# On ne désactive jamais SELinux. Le mettre en « permissive » pour que ça
# marche, c'est éteindre la protection de toute la machine pour un service —
# et c'est ce que raconte la moitié de l'Internet.
poser_selinux() {
  command -v getenforce >/dev/null 2>&1 || return 0
  [ "$(getenforce 2>/dev/null)" = "Enforcing" ] || return 0

  titre "SELinux"

  # Le proxy. Sans cela, nginx ne peut joindre aucun service local, et c'est
  # 502 sans rien qui l'explique.
  if command -v setsebool >/dev/null 2>&1; then
    if setsebool -P httpd_can_network_connect on 2>/dev/null; then
      vert "✓ nginx a le droit de joindre le service local."
    else
      rouge "Je n'ai pas pu poser « httpd_can_network_connect »."
      rouge "Sans lui, nginx répondra 502. À poser à la main :"
      rouge "    setsebool -P httpd_can_network_connect on"
    fi
  fi

  # Les données. Le service y écrit sous son propre compte, ce qui ne demande
  # rien de particulier à SELinux — mais un dossier créé sous /var/lib hérite
  # d'un type que la politique ne connaît pas toujours, et une restauration
  # depuis une sauvegarde arrive souvent sans étiquette du tout.
  if command -v restorecon >/dev/null 2>&1; then
    restorecon -R "$DONNEES" >/dev/null 2>&1 || true
  fi
}

# ── Le pare-feu ──────────────────────────────────────────────────────────────
#
# firewalld tourne par défaut sur Enterprise Linux, et il ne laisse pas passer
# le web. Le service démarre, nginx écoute, et le site est injoignable depuis
# l'extérieur — sans le moindre message, puisque rien n'a échoué.
#
# On n'ouvre que ce qu'on a posé : 80 toujours, 443 seulement si l'on a donné
# un domaine, c'est-à-dire s'il y aura un certificat.
poser_parefeu() {
  [ -n "$DOMAINE" ] || return 0
  command -v firewall-cmd >/dev/null 2>&1 || return 0
  firewall-cmd --state >/dev/null 2>&1 || return 0

  titre "Pare-feu"
  local ouvert=0
  for service in http https; do
    if firewall-cmd --permanent --add-service="$service" >/dev/null 2>&1; then
      ouvert=1
    fi
  done
  if [ "$ouvert" = 1 ]; then
    firewall-cmd --reload >/dev/null 2>&1 || true
    vert "✓ Les ports 80 et 443 sont ouverts."
  else
    rouge "Je n'ai pas pu ouvrir le pare-feu. À poser à la main :"
    rouge "    firewall-cmd --permanent --add-service=http --add-service=https && firewall-cmd --reload"
  fi

  # Ubuntu et Debian ont ufw, éteint par défaut — mais allumé sur les images
  # de certains hébergeurs. S'il tourne, il ferme la porte comme firewalld.
  if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -qi '^Status: active'; then
    ufw allow 80/tcp >/dev/null 2>&1 || true
    ufw allow 443/tcp >/dev/null 2>&1 || true
    vert "✓ ufw : 80 et 443 ouverts."
  fi
}

# ── Le reverse-proxy, si on a donné un domaine ───────────────────────────────
poser_nginx() {
  [ -n "$DOMAINE" ] || return 0

  titre "Pose de nginx pour $DOMAINE"
  command -v nginx >/dev/null 2>&1 || case "$FAMILLE" in
    apt)    apt-get install -y -qq nginx >/dev/null ;;
    dnf)    "$DNF" install -y -q nginx >/dev/null ;;
    zypper) zypper --non-interactive --quiet install nginx >/dev/null ;;
    pacman) pacman -Sy --noconfirm --needed nginx >/dev/null ;;
    apk)    apk add --no-cache nginx >/dev/null ;;
  esac
  command -v nginx >/dev/null 2>&1 || { rouge "nginx n'est pas là après installation."; return 1; }

  # Sur Enterprise Linux, nginx n'est ni activé ni démarré par le paquet.
  systemctl enable nginx >/dev/null 2>&1 || true

  local conf=/etc/nginx/conf.d/toutadmin.conf
  [ -d /etc/nginx/sites-available ] && conf=/etc/nginx/sites-available/toutadmin
  cat > "$conf" <<FIN
server {
    listen 80;
    server_name $DOMAINE;

    # Les pièces reçues sont des factures scannées : deux mégaoctets ne
    # suffisent pas, et le refus arriverait sans explication utile.
    client_max_body_size 24m;

    location / {
        proxy_pass http://127.0.0.1:$PORT;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        # Celui-ci décide si le cookie de session est marqué Secure : sans
        # lui, une instance en HTTPS poserait des cookies en clair.
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
FIN
  [ -d /etc/nginx/sites-enabled ] && ln -sf "$conf" /etc/nginx/sites-enabled/toutadmin
  nginx -t >/dev/null 2>&1 || { rouge "La configuration nginx est refusée — rien n'est rechargé."; nginx -t; return 1; }
  systemctl reload nginx 2>/dev/null || systemctl restart nginx 2>/dev/null || true
  vert "✓ nginx en place."

  if command -v certbot >/dev/null 2>&1; then
    titre "Certificat pour $DOMAINE"
    if [ -n "$COURRIEL" ]; then
      certbot --nginx -d "$DOMAINE" --non-interactive --agree-tos -m "$COURRIEL" --redirect \
        && vert "✓ Certificat posé, et le trafic en clair redirigé." \
        || rouge "Certbot n'a pas abouti : l'instance reste accessible en HTTP."
    else
      gris "  certbot est là, mais il lui faut une adresse : relancez avec --courriel."
    fi
  else
    gris "  certbot n'est pas installé : l'instance est servie en HTTP."
    gris "  Posez-le, puis : certbot --nginx -d $DOMAINE"
  fi
}

# ── La simulation ────────────────────────────────────────────────────────────
#
# Ce que le script a reconnu, et ce qu'il ferait. Rien n'est posé.
if [ "$SIMULATION" = 1 ]; then
  titre "Simulation — rien ne sera posé"

  gris  "  Node"
  if node_assez_recent; then
    vert "    ✓ $(node -v) est déjà là, rien à faire."
  elif command -v node >/dev/null 2>&1; then
    echo "    $(node -v) est trop ancien : pose de la ${VERSION_NODE}."
  else
    echo "    absent : pose de la ${VERSION_NODE} par $FAMILLE."
  fi
  if [ "$EL" = 1 ]; then
    # La même fonction que le code réel : ce que la simulation annonce est ce
    # qui sera fait, et non une seconde opinion qui pourrait diverger.
    if module_a_demander; then
      echo "    Enterprise Linux ${EL_MAJEUR} : le module « nodejs:${VERSION_NODE} » sera demandé."
    else
      echo "    Enterprise Linux ${EL_MAJEUR} : pas de modularité, le paquet est pris tel quel."
    fi
  fi

  gris  "  Système"
  echo "    utilisateur   $UTILISATEUR"
  echo "    code          $DOSSIER"
  echo "    données       $DONNEES"
  if command -v systemctl >/dev/null 2>&1; then
    echo "    service       systemd, « toutadmin »"
  else
    echo "    service       systemd est absent : à lancer à la main."
  fi

  if [ "$(getenforce 2>/dev/null || true)" = "Enforcing" ]; then
    gris "  SELinux (contraignant)"
    echo "    setsebool -P httpd_can_network_connect on"
    echo "    restorecon -R $DONNEES"
  fi

  if [ -n "$DOMAINE" ]; then
    gris "  Reverse-proxy"
    echo "    nginx pour $DOMAINE, vers 127.0.0.1:$PORT"
    if command -v firewall-cmd >/dev/null 2>&1; then
      echo "    firewalld : ouverture de http et https"
    elif command -v ufw >/dev/null 2>&1; then
      echo "    ufw : ouverture de 80 et 443, s'il est actif"
    fi
    if command -v certbot >/dev/null 2>&1; then
      echo "    certbot : certificat pour $DOMAINE"
    else
      echo "    certbot est absent : pas de certificat, l'instance restera en HTTP."
    fi
  else
    gris "  Pas de --domaine : ni nginx, ni certificat, ni ouverture de pare-feu."
  fi

  printf '\n'
  vert "Rien n'a été posé. Relancez sans --simulation pour installer."
  exit 0
fi

installer_node
poser_utilisateur
poser_code
poser_service
# Avant nginx : le droit de joindre le service local doit exister au moment
# où nginx démarre, sinon la première requête tombe en 502 et l'on croit que
# l'installation a échoué.
poser_selinux
poser_nginx
poser_parefeu

titre "Installé."
if [ -n "$DOMAINE" ]; then
  echo "  Ouvrez https://$DOMAINE — l'assistant d'installation vous attend."
else
  echo "  Ouvrez http://$(hostname -I 2>/dev/null | awk '{print $1}'):$PORT — l'assistant d'installation vous attend."
fi
cat <<FIN

  Il se déroule en huit étapes et pose lui-même la base, le premier compte et
  la licence. Il se ferme de lui-même ensuite : il n'y a aucun fichier à
  penser à supprimer.

  Sur un serveur exposé, posez d'abord un jeton pour que personne ne prenne
  l'instance entre maintenant et votre première visite :

      systemctl edit toutadmin
      # puis, dans le fichier qui s'ouvre :
      [Service]
      Environment=INSTALL_TOKEN=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
      # puis :
      systemctl restart toutadmin

  Le service      : systemctl status toutadmin
  Ses journaux    : journalctl -u toutadmin -f
  Ses données     : $DONNEES
FIN

if [ "$(getenforce 2>/dev/null || true)" = "Enforcing" ]; then
  cat <<'FIN'

  SELinux est contraignant sur cette machine, et c'est bien ainsi. Deux
  choses ont été posées pour lui : le droit pour nginx de joindre le service
  local, et l'étiquetage du dossier de données. Si un jour une page répond
  502 alors que le service tourne, c'est par là qu'il faut regarder :

      ausearch -m avc -ts recent
FIN
fi
