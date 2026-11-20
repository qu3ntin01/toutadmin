#!/usr/bin/env bash
# =============================================================================
# install.sh (édition PHP) — le même script, sur neuf distributions
# =============================================================================
#
#     bash deploiement/essais/distributions.sh
#
# ── Pourquoi ce banc existe ─────────────────────────────────────────────────
#
# Un script d'installation est le seul morceau du produit qu'on ne peut pas
# éprouver en le lançant : il pose un service système, il installe des
# paquets, il touche au pare-feu. On ne le lance pas neuf fois sur sa machine
# de travail pour voir.
#
# Alors il n'était éprouvé nulle part, et c'est exactement le genre de code
# qui marche sur la distribution de celui qui l'a écrit et casse sur les
# huit autres — sans que personne ne s'en aperçoive avant un client.
#
# ── Comment il s'y prend ────────────────────────────────────────────────────
#
# Chaque distribution est jouée : un faux « /etc/os-release » qui la décrit,
# et de faux outils — dnf, zypper, pacman, getenforce, firewall-cmd, php —
# qui répondent ce qu'elle répondrait. Le script tourne en « --simulation »,
# où il dit ce qu'il ferait sans rien poser, et l'on vérifie qu'il l'a dit.
#
# Le chemin est clos : rien que les faux outils et les quelques vrais dont
# bash a besoin pour parler. Sans cela, l'apt-get de la machine hôte se
# glisse dans une simulation d'AlmaLinux, et le banc ment — c'est arrivé à
# l'écriture, et les quatre distributions sans apt étaient données pour
# bonnes.
#
# ── Ce qu'il ne dit pas ─────────────────────────────────────────────────────
#
# Que les paquets existent, ni que dnf les trouvera. Il dit que le script
# reconnaît la machine et choisit la bonne commande — ce qui est précisément
# ce qui cassait. Le reste demande un vrai serveur.
# -----------------------------------------------------------------------------
set -u
# Lancé sans argument depuis la racine du dépôt, il trouve tout seul de quoi
# travailler.
ICI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="${1:-$ICI/../install.sh}"
BANC="${2:-$(mktemp -d)}"
[ -r "$SCRIPT" ] || { printf 'Pas de script à éprouver : %s\n' "$SCRIPT" >&2; exit 2; }
OK=0; KO=0

jouer() {  # nom | os-release | outils | node | selinux | ce qu'on attend
  local nom="$1" osrel="$2" outils="$3" node_v="$4" selinux="$5" attendu="$6"  # node_v : ici la version de PHP
  local d="$BANC/$nom"; rm -rf "$d"; mkdir -p "$d/bin"
  printf '%s\n' "$osrel" > "$d/os-release"

  # Un PATH clos : rien que les faux outils, plus les quelques vrais dont le
  # script se sert pour parler. Sans cela l'apt-get de la machine hôte se
  # glisse dans une simulation d'AlmaLinux, et le banc ment.
  local u
  for u in bash env cat grep sed head tr awk; do
    command -v "$u" >/dev/null 2>&1 && ln -sf "$(command -v "$u")" "$d/bin/$u"
  done
  printf '#!/usr/bin/env bash\n[ "${1:-}" = "-u" ] && { echo 0; exit 0; }\nexit 1\n' > "$d/bin/id"
  chmod +x "$d/bin/id"

  for o in $outils; do
    printf '#!/usr/bin/env bash\nexit 0\n' > "$d/bin/$o"; chmod +x "$d/bin/$o"
  done
  if [ -n "$node_v" ]; then
    cat > "$d/bin/php" <<FIN
#!/usr/bin/env bash
case "\$*" in
  *PHP_VERSION*) printf '%s' "$node_v" ;;
  *-v) echo "PHP $node_v" ;;
esac
FIN
    chmod +x "$d/bin/php"
  fi
  if [ -n "$selinux" ]; then
    printf '#!/usr/bin/env bash\necho "%s"\n' "$selinux" > "$d/bin/getenforce"; chmod +x "$d/bin/getenforce"
  fi

  local sortie
  sortie="$(PATH="$d/bin" OS_RELEASE="$d/os-release" \
            bash "$SCRIPT" --simulation --domaine essai.test 2>&1)" || {
    printf '\033[31m✗ %-12s le script est tombé\033[0m\n' "$nom"; KO=$((KO+1)); return; }

  local manque=""
  local IFS='|'
  for phrase in $attendu; do
    case "$sortie" in *"$phrase"*) : ;; *) manque="$manque   ↳ attendu : $phrase
";; esac
  done
  unset IFS
  if [ -z "$manque" ]; then
    printf '\033[32m✓\033[0m %-12s %s\n' "$nom" "$(printf '%s' "$sortie" | grep -o 'distribution  [^(]*' | head -1)"
    OK=$((OK+1))
  else
    printf '\033[31m✗ %-12s\033[0m\n%s' "$nom" "$manque"
    printf '%s\n' "$sortie" | sed 's/^/     /' | head -20
    KO=$((KO+1))
  fi
}

jouer alma9 'ID="almalinux"
ID_LIKE="rhel centos fedora"
VERSION_ID="9.5"
PRETTY_NAME="AlmaLinux 9.5"' "dnf systemctl firewall-cmd nginx useradd curl tar certbot semanage" "8.0.30" "Enforcing" \
'AlmaLinux 9.5|paquets : dnf|Enterprise Linux 9 : le module « php:8.3 »|SELinux (contraignant)|httpd_sys_rw_content_t|firewalld'

jouer alma10 'ID="almalinux"
ID_LIKE="rhel centos fedora"
VERSION_ID="10.0"
PRETTY_NAME="AlmaLinux 10.0"' "dnf systemctl firewall-cmd nginx useradd curl tar certbot semanage" "8.3.6" "Enforcing" \
'AlmaLinux 10.0|Enterprise Linux 10 : pas de modularité|SELinux (contraignant)|httpd_can_network_connect|firewalld'

jouer rocky10 'ID="rocky"
ID_LIKE="rhel centos fedora"
VERSION_ID="10.0"
PRETTY_NAME="Rocky Linux 10.0"' "dnf systemctl firewall-cmd nginx useradd curl tar semanage" "" "Enforcing" \
'Rocky Linux 10.0|Enterprise Linux 10 : pas de modularité|httpd_sys_content_t|utilisateur   nginx'

jouer rhel9 'ID="rhel"
ID_LIKE="fedora"
VERSION_ID="9.4"
PRETTY_NAME="Red Hat Enterprise Linux 9.4"' "dnf systemctl firewall-cmd nginx useradd curl tar semanage" "8.1.2" "Enforcing" \
'Red Hat Enterprise Linux 9.4|Enterprise Linux 9 : le module|httpd_can_network_connect'

jouer debian13 'ID=debian
VERSION_ID="13"
PRETTY_NAME="Debian GNU/Linux 13 (trixie)"' "apt-get systemctl nginx useradd curl tar certbot" "8.3.11" "" \
'Debian GNU/Linux 13|paquets : apt|utilisateur   www-data'

jouer ubuntu2404 'ID=ubuntu
ID_LIKE=debian
VERSION_ID="24.04"
PRETTY_NAME="Ubuntu 24.04 LTS"' "apt-get systemctl ufw nginx useradd curl tar" "8.3.6" "" \
'Ubuntu 24.04 LTS|paquets : apt|ufw : ouverture'

jouer opensuse 'ID="opensuse-leap"
ID_LIKE="suse opensuse"
VERSION_ID="15.6"
PRETTY_NAME="openSUSE Leap 15.6"' "zypper systemctl nginx useradd curl tar" "8.2.20" "" \
'openSUSE Leap 15.6|paquets : zypper|utilisateur   wwwrun'

jouer arch 'ID=arch
PRETTY_NAME="Arch Linux"' "pacman systemctl nginx useradd curl tar" "8.4.1" "" \
'Arch Linux|paquets : pacman|utilisateur   http'

jouer alpine 'ID=alpine
VERSION_ID="3.21"
PRETTY_NAME="Alpine Linux v3.21"' "apk nginx adduser curl tar" "8.3.15" "" \
'Alpine Linux v3.21|paquets : apk|utilisateur   nginx'

printf '\n%d distribution(s) au vert, %d en échec\n' "$OK" "$KO"
[ "$KO" -eq 0 ]
