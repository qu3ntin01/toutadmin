# Toutadmin — édition PHP

**La version à déposer sur un hébergement web classique.** Même produit,
même base, même interface que l'édition Node : du PHP qui tourne partout, sans
Node, sans processus à surveiller, sans ligne de commande sur le serveur.

> L'édition Node vit sur la branche `toutadmin`, le site public sur `siteweb`,
> la documentation sur `documentation`. Cette branche-ci — `version-web` — est
> l'édition PHP.

## Ce qu'il faut sur l'hébergement

| Il faut | Pourquoi |
| --- | --- |
| PHP **8.1** ou plus récent | types, `match`, chaînage sûr |
| Extension **pdo_sqlite** | la base, un fichier, aucune configuration |
| Extension **mbstring** | seize langues, dont l'arabe, le japonais et l'hindi |
| Un dossier inscriptible hors racine web | la base et les fichiers déposés |

Pas de Composer, pas de `node_modules`, pas de compilation : **aucune
dépendance**. Le dossier se dépose tel quel, par FTP ou par `git pull`.

## Installer

1. Déposer le dossier sur l'hébergement.
2. Faire pointer la racine web sur **`public/`**. Si l'hébergement ne le
   permet pas, laisser la racine où elle est : le `.htaccess` à la racine
   renvoie tout dans `public/` et rend le reste inaccessible.
3. Ouvrir le site. L'écran d'installation vérifie l'hébergement, puis demande
   le nom de l'entreprise, la langue et le compte d'administration.
4. Cet écran **se ferme de lui-même** dès qu'un compte existe : il n'y a aucun
   fichier à penser à supprimer ensuite.

### Nginx

```nginx
root /var/www/toutadmin/public;
index index.php;

location / {
  try_files $uri $uri/ /index.php?$query_string;
}

location ~ \.php$ {
  fastcgi_pass unix:/run/php/php8.3-fpm.sock;
  include fastcgi_params;
  fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}

# La base et le code ne sont pas dans la racine web : rien à interdire de plus.
```

### Essayer en local

```bash
cp config.sample.php config.php
php -S localhost:8000 -t public public/index.php
```

### Sur un serveur exposé : le jeton d'installation

Entre le dépôt des fichiers et le passage de l'assistant, l'instance est à qui
la trouve : le premier arrivé crée le compte d'administration. Posez donc une
valeur au hasard dans `config.php` avant la mise en ligne —

```php
'install_token' => 'un-jeton-long-et-aleatoire',
```

— ou dans l'environnement (`INSTALL_TOKEN`). L'assistant la demande, la
compare à temps constant, et sans elle ne crée rien. Une fois l'instance
installée, l'assistant se referme de lui-même : le jeton peut rester ou
partir, il ne sert plus.

## Tests

```bash
php tests/run.php
```

Aucune dépendance ici non plus : un harnais de quelques lignes, des requêtes
jouées à travers le vrai noyau — pas contre une version arrangée pour les
tests.

## La base

Le schéma est **celui de l'édition Node**, repris table par table : une base
passe de l'une à l'autre sans conversion, et les mots de passe comme le
journal d'audit restent valables des deux côtés. C'est la seule façon honnête
de proposer deux éditions du même produit — sinon ce sont deux produits.

Une seule table s'ajoute ici, `rate_limits` : l'édition Node tient ses
plafonds en mémoire du processus, ce qu'un hébergement mutualisé ne permet pas
(chaque requête y est un processus neuf).

## Sécurité

Les mêmes règles que l'édition Node, et pour les mêmes raisons :

- **Seul un administrateur crée un compte.** Aucune route d'inscription
  n'existe — ce n'est pas un réglage, c'est une absence.
- **L'adresse de courrier interne et les serveurs de messagerie ne sont pas
  modifiables par le membre.** Le formulaire de profil ne les propose pas du
  tout, plutôt que de les proposer et les refuser ensuite.
- **Le retrait de l'annuaire appartient à l'administration**, pas au membre.
- Jeton anti-falsification sur chaque POST, comparé en temps constant.
- Identifiant de session renouvelé à chaque changement de droits.
- Cinq échecs verrouillent le compte un quart d'heure ; l'adresse est plafonnée
  séparément, sinon mille comptes essayés quatre fois chacun ne verrouilleraient
  rien.
- Message et temps de réponse identiques que le compte existe ou non.
- Journal d'audit scellé de proche en proche : une ligne réécrite ou retirée
  casse la chaîne et se voit.
- En-têtes posés sur chaque réponse, dont une politique de contenu sans
  `unsafe-inline` : les scripts portent un nonce.

## Où en est le portage

L'édition Node compte 78 modules et 51 fichiers de routes. Le portage avance
par lots ; l'état exact est dans [PORTAGE.md](PORTAGE.md).

Ce qui fonctionne aujourd'hui : installation, connexion (avec double
authentification et codes de secours), changement de mot de passe, espace du
salarié, annuaire, profil, seize langues, journal d'audit, plafonds, sessions
en base.
