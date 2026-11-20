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
| Extension **pdo_sqlite** | la base par défaut : un fichier, aucune configuration |
| Extension **mbstring** | seize langues, dont l'arabe, le japonais et l'hindi |
| Un dossier inscriptible hors racine web | la base et les fichiers déposés |
| Extension **pdo_mysql** | seulement si vous préférez MySQL ou MariaDB à SQLite |

Le moteur se choisit à l'installation et se relit dans `config.php`
(`db_driver`, puis `db_host`, `db_port`, `db_name`, `db_user`,
`db_password`) : `config.sample.php` documente chaque entrée. Les deux
moteurs portent le même schéma et passent la même suite de tests ; la page
« SQLite ou MySQL » de la documentation dit lequel prendre.

Pas de Composer, pas de `node_modules`, pas de compilation : **aucune
dépendance**. Le dossier se dépose tel quel, par FTP ou par `git pull`.

## Installer

1. Déposer le dossier sur l'hébergement.
2. Faire pointer la racine web sur **`public/`**. Si l'hébergement ne le
   permet pas, laisser la racine où elle est : le `.htaccess` à la racine
   renvoie tout dans `public/` et rend le reste inaccessible.
3. Ouvrir le site. L'assistant d'installation se déroule en huit étapes :
   la langue, les prérequis de l'hébergement, les **mises à jour** (voir
   plus bas), la **licence**, la **base de données** (SQLite dans un
   fichier, ou MySQL / MariaDB — la connexion est essayée avant qu'on s'y
   engage), l'organisation, le compte d'administration, puis la pose.
4. La pose se fait en quatre paliers annoncés à l'écran, et c'est l'assistant
   qui écrit `config.php`, en dernier : tant qu'il n'existe pas, une
   installation interrompue n'a rien laissé derrière elle.
5. L'assistant **se ferme de lui-même** dès qu'un compte existe : il n'y a
   aucun fichier à penser à supprimer ensuite.

### L'étape « Mises à jour »

Installer une version dépassée est une mauvaise façon de commencer : on pose
un CMS dont on sait déjà qu'il lui manque des correctifs, et la première chose
qu'on demandera au client sera de le mettre à jour. L'assistant le règle avant
qu'il y ait des données dedans.

Elle vient avant « Licence », ce qui pose une difficulté réelle : télécharger
une archive demande une clé, et la clé se saisit à l'étape d'après. On coupe
donc en deux ce qui n'a pas à être fait d'un bloc.

- **Savoir** s'il existe plus récent ne demande aucune clé —
  `GET /api/v1/derniere` sur le service de licences. Le numéro de la dernière
  version est écrit sur la page de téléchargement du site ; le refuser à un
  installeur serait absurde. La question part toute seule au chargement.
- **Télécharger** en demande une. Le champ n'apparaît qu'une fois qu'on sait
  qu'il va servir, et la clé est ensuite reportée à l'étape « Licence », qui
  ne la redemande pas.
- **Une version en retard bloque** : « Suivant » reste gris, et un bouton
  propose de poser la dernière version tout seul — téléchargement,
  vérification de la signature Ed25519 de l'éditeur, contrôle du contenu de
  l'archive, pose des fichiers, et remise en place de ce qui a été mis de côté
  si la pose échoue à mi-chemin.
- **Un éditeur injoignable ne bloque pas.** Un client derrière un pare-feu
  d'entreprise, sur un réseau sans sortie, ou pendant une panne de notre côté,
  doit pouvoir installer son CMS. Transformer une panne de réseau en produit
  inutilisable serait une faute bien plus grave que de laisser poser une
  version d'un mois. L'écran passe en ambre, dit ce qu'il a reçu — le code et
  le type de la réponse —, et laisse continuer.
- **Le bouton grisé est du confort, pas un contrôle** : le serveur refait la
  vérification quand on valide l'étape.
- **La clé publique qui vérifie l'archive est celle du code**, jamais un
  réglage : à l'installation il n'y a pas encore de réglages, et une clé de
  vérification qu'on pourrait poser soi-même ne vérifierait rien. C'est la
  même paire Ed25519 qui signe les licences et les versions.

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
php -S localhost:8000 -t public public/index.php
```

`config.php` n'a pas à être copié à la main : l'assistant l'écrit. Le copier
depuis `config.sample.php` reste utile pour poser un jeton d'installation
avant la mise en ligne, mais ce n'est plus un prérequis.

### Sur un serveur Linux

```bash
sudo ./deploiement/install.sh --domaine intranet.exemple.fr --courriel vous@exemple.fr
```

Le script pose PHP et ses deux extensions, nginx, le code dans
`/opt/toutadmin`, les données dans `/var/lib/toutadmin`, et — si on lui donne
un domaine — un certificat Let's Encrypt. Il est fait pour être relancé.

`--simulation` dit ce qui serait fait sur cette machine-ci, sans rien poser.

#### Les distributions

| | | l'utilisateur du service web |
|---|---|---|
| Debian, Ubuntu | `apt` | `www-data` |
| **AlmaLinux, Rocky, RHEL, CentOS Stream, Fedora — 9 comme 10** | `dnf` | `nginx` |
| openSUSE, SLES | `zypper` | `wwwrun` |
| Arch | `pacman` | `http` |
| Alpine | `apk` | `nginx` |

Enterprise Linux demande trois choses que les autres n'exigent pas :

- **SELinux** y est contraignant. L'édition PHP écrit dans son dossier de
  données — la base, les pièces jointes, les sauvegardes — et sans étiquette
  adéquate tout échoue en « Permission denied » alors que les droits Unix
  sont bons ; on cherche des heures du côté de `chown`. Le script pose
  `httpd_sys_content_t` sur le code et `httpd_sys_rw_content_t` sur les
  données : le code se lit, les données s'écrivent, et une faille dans le
  produit ne peut pas réécrire le produit. SELinux n'est jamais désactivé.
- **firewalld** y est allumé et ferme le web.
- **La modularité a disparu en 10.** En 9, `dnf module enable php:8.3` est
  nécessaire — la version par défaut y est la 8.0, trop ancienne. En 10, la
  commande n'existe plus et l'appeler fait échouer l'installation ; la 8.3 y
  est servie directement.

Les neuf distributions sont éprouvées à chaque modification :

```bash
bash deploiement/essais/distributions.sh
```

Il ne pose **ni base, ni config.php, ni compte, ni licence** : l'assistant le
fait dans le navigateur, puis se ferme. Un script qui créerait le compte
devrait aussi choisir un mot de passe, donc l'écrire quelque part — et ce
quelque part serait le maillon faible de toute l'installation.

### En conteneur

```bash
docker compose up -d      # puis http://localhost:8080
```

L'image n'ajoute qu'une chose à PHP — `pdo_mysql`, pour ceux qui préfèrent
MySQL à SQLite. Tout le reste est déjà là dans l'image officielle, `sodium`
compris, qui vérifie les signatures Ed25519 des licences et des versions.
Comme partout ailleurs, l'image ne pose ni base, ni compte, ni licence :
l'assistant fait les huit étapes dans le navigateur, puis se ferme.

Deux réglages portent tout le reste, et l'image les pose :

| Variable | Ce qu'elle fait |
| --- | --- |
| `TOUTADMIN_DATA` | Le dossier où l'instance range son état. Tout en découle : la base SQLite, les photos de profil, le coffre-fort, les CV, le parapheur, les pièces reçues, les sauvegardes. Un seul volume suffit donc, et l'instance reste la même après n'importe quel remplacement d'image — y compris aux yeux du service de licences. |
| `CODE_IMMUABLE` | Dit que le code ne s'écrit pas. L'écran « Mises à jour » continue de signaler les versions, mais n'en pose plus : des fichiers réécrits dans un conteneur disparaissent à son premier remplacement, c'est-à-dire que la mise à jour aurait l'air de marcher puis se déferait toute seule. À la place, l'écran donne le geste : `docker compose pull && docker compose up -d`. La clé `code_immuable` de `config.php` fait la même chose pour un déploiement qui n'a pas d'environnement à poser. |

`TOUTADMIN_CONFIG` pointe `config.php` dans le volume : c'est l'assistant qui
l'écrit, en dernier, comme sur n'importe quelle autre installation.

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

## Licence

L'instance sait dire si elle a le droit d'écrire, et jusqu'à quand. Elle ne
fabrique aucune licence : son code ne porte que la **clé publique** de
l'éditeur, qui vérifie sans jamais pouvoir signer. Un jeton est une charge utile
JSON signée en Ed25519 ; la vérification porte sur les octets reçus, pas sur un
JSON ré-encodé, ce qui rend la canonicalisation sans objet.

**Cinq états, et deux comptes à rebours qui ne mesurent pas la même chose.**

| État | Ce qui se passe | Écritures |
| --- | --- | --- |
| `absente` | aucun jeton, ou un jeton qu'on ne sait pas vérifier | refusées |
| `valide` | le jeton couvre aujourd'hui, la dernière vérification est passée | permises |
| `sursis` | le jeton couvre encore, mais la vérification échoue depuis moins de 48 h | **permises**, bandeau |
| `grace` | l'échéance du jeton est passée depuis moins de « grace » jours | **permises**, bandeau |
| `restreint` | au-delà de l'un ou de l'autre | refusées |

`grace` part de l'échéance écrite dans le jeton : un abonnement qui se termine,
cela se prévoit. `sursis` part de la première vérification en échec — une
licence révoquée, une clé d'instance retirée, un service de licences injoignable :
cela tombe sans prévenir. D'où quarante-huit heures, assez pour qu'un humain
soit prévenu, rappelle et règle, trop peu pour qu'une instance révoquée continue
des mois. Le bandeau compte en heures, pas en jours : « il reste 2 jours » se
lit comme « on a le temps », alors qu'il faut appeler aujourd'hui.

On vérifie **toutes les heures**, et **tous les quarts d'heure une fois le
sursis engagé** — c'est là qu'une vérification qui réussit rend le plus service,
puisqu'elle éteint le bandeau. Une réussite efface le compte à rebours ; un
échec qui se répète ne le repousse jamais, sans quoi les quarante-huit heures ne
viendraient jamais.

### Ce qui rend ces règles difficiles à neutraliser

Les durées ci-dessus ne valent que ce que vaut la date qui les mesure — et la
date d'une machine appartient à qui l'administre. Reculer l'horloge d'un serveur
prend dix secondes et prolongerait un sursis indéfiniment. C'est le moyen le
plus simple, le plus silencieux et le plus reproductible de neutraliser une
licence, et **chiffrer le code n'y changerait rien** : le code chiffré lirait la
même horloge menteuse.

L'instance ne demande donc pas « quelle heure est-il », question à laquelle
l'hôte répond ce qu'il veut, mais **« quel est le plus tard dont j'aie la
preuve »**. Trois sources, dont on retient la plus avancée :

1. **la date d'émission du jeton**, qui est signée par l'éditeur. On ne peut pas
   la déplacer vers l'avant sans la clé privée, et la déplacer vers l'arrière ne
   sert à rien ;
2. **le dernier horodatage du journal d'audit**, qui est scellé par chaînage
   d'empreintes. Le reculer casse la chaîne, et la vérification du sceau le dit
   à l'endroit exact ;
3. **la marque haute** déjà retenue, qui ne redescend jamais, et l'heure système
   si elle va plus loin.

Avancer l'horloge reste possible et n'est pas surveillé : cela fait expirer la
licence **plus tôt**, pas plus tard. Personne n'y a intérêt.

Le compteur d'échec suit la même logique. Il vit dans un réglage, qui s'efface
d'une requête SQL — et le sursis repartirait à zéro toutes les quarante-huit
heures sans que rien ne paraisse. Il est donc corroboré par le journal scellé :
on retient **la plus ancienne** des deux dates, celle du réglage et celle du
premier échec scellé depuis la dernière réussite scellée. Effacer le réglage ne
gagne rien ; effacer le journal se voit.

Enfin, **une preuve quitte la machine**. À chaque renouvellement, l'instance
annonce l'heure qu'elle croit être. Le panneau la compare à la sienne et note
l'écart quand il dépasse une journée. C'est la seule trace qui survive à un
accès administrateur complet sur le serveur du client : elle n'est pas chez lui.

### Ce que l'obfuscation ajoutera, et ce qu'elle n'ajoutera pas

L'édition PHP sera distribuée encodée. Cela **élève le coût** d'une
modification : il faut décoder avant de retoucher, ce qui écarte la retouche
opportuniste. Cela **n'ajoute rien** au reste, et il vaut mieux le savoir
d'avance :

- l'édition Node n'est pas encodable par ce moyen, et son code part tel quel.
  Les protections décrites ici valent donc pour les deux, ou elles ne valent
  rien ;
- la clé publique de l'éditeur est dans le code. Qui sait éditer le code sait la
  remplacer par la sienne et se signer des licences. Aucun encodage n'y change
  quoi que ce soit, il rend seulement l'opération plus longue ;
- un encodeur ne protège pas la base de données, ni les réglages, ni l'horloge
  du système. Les trois ancrages ci-dessus, eux, tiennent **même quand tout
  cela est entre les mains de l'hôte** — et c'est pour cela qu'ils sont dans la
  conception plutôt que dans l'emballage.

Ce qui est visé est donc modeste et utile : qu'on ne puisse pas neutraliser une
licence **par accident, ni en trois clics, ni sans laisser de trace**. Un
logiciel qu'on installe chez soi ne se défend pas d'un adversaire déterminé, et
prétendre le contraire serait se mentir autant qu'au client.

### Ce que la licence ne fait pas, et ne fera pas

- **Elle n'efface rien, ne chiffre rien, ne cache rien.** En `restreint` on se
  connecte, on lit tout, on exporte tout. Seules les écritures sont refusées.
- **Elle laisse toujours passer** : se connecter, se déconnecter, changer son
  mot de passe, lancer un export, ouvrir une sauvegarde, poser une licence. Le
  client est responsable de traitement au sens du RGPD : il doit pouvoir entrer
  chez lui et ressortir avec ses données. Une licence impayée se règle par une
  discussion commerciale, pas en séquestrant des dossiers du personnel et des
  bulletins de paie. **Ce n'est pas négociable.**
- **Elle ne licencie personne.** Le plafond de membres ne s'applique qu'à la
  création : une instance qui repasse sous une offre plus petite garde les gens
  qu'elle a déjà.
- **Elle ne lit pas le matériel.** L'empreinte d'installation est un nombre tiré
  au hasard une fois, pas une signature de machine : déplacer une machine
  virtuelle ne coupe personne.
- **Elle ne restreint rien sans clé publique.** Une instance dont le code n'en
  porte pas — développement, tests, démonstration — n'est pas une instance
  vendue, et rien n'y est limité.
- **Elle ne remplace pas un contrat.** Elle dit l'état d'un abonnement ; elle ne
  prouve rien devant un tribunal et ne prétend pas empêcher quelqu'un de
  modifier son propre code. C'est un logiciel qu'on installe chez soi : qui veut
  retirer la vérification le peut. Elle est là pour que les choses soient
  claires entre gens de bonne foi, pas pour se défendre d'un adversaire.

### Ce qu'une signature valable ne suffit pas à autoriser

Quatre contrôles que la cryptographie ne fait pas toute seule, et que le module
fait après elle :

- **l'édition** — un jeton émis pour l'autre édition est refusé ;
- **l'installation** — un jeton portant l'empreinte d'une autre instance est
  refusé ; c'est ce qui distingue une copie d'un déménagement de serveur ;
- **l'abonnement** — le jeton ne vaut jamais au-delà de l'abonnement qu'il
  annonce, même si sa propre date d'expiration allait plus loin par erreur ;
- **le rejeu** — un jeton correctement signé n'est pas pour autant celui
  d'aujourd'hui. Gardé puis reposé plus tard, un vieux jeton rendrait à
  l'instance le plafond de membres ou l'échéance qu'elle avait à l'époque, sans
  rien forger. Seule la date d'émission protège de cela : un jeton plus ancien
  que celui en place est écarté. À émission égale on accepte — c'est le même
  jeton reposé, et le refuser casserait une réinstallation depuis une
  sauvegarde.

### À l'installation

L'étape « Licence » vérifie **avant** d'aller plus loin : un bouton
« Vérifier la licence », une barre qui s'anime pendant l'attente et disparaît
dès la réponse, et « Suivant » qui reste gris tant que la réponse n'est pas
oui. C'est là qu'on veut découvrir qu'une clé est refusée — pas une fois les
données du client dedans. Retoucher un champ regrise le bouton : on ne vérifie
pas une clé pour en installer une autre.

Cette vérification **n'engage rien**. La route d'essai du panneau ne lie aucune
licence, ne l'active pas et ne la note pas comme vue : on peut presser le bouton
autant de fois qu'on veut, et une installation abandonnée en cours de route ne
consomme la licence de personne. Le jeton hors ligne, lui, se vérifie sur place
— sa signature est celle de l'éditeur et la clé publique est dans le code, rien
ne sort de la machine.

Le bouton grisé est du confort : il dit à l'utilisateur où il en est. **Ce n'est
pas un contrôle.** Un attribut `disabled` s'enlève en trois secondes dans un
navigateur, et un formulaire s'envoie sans navigateur du tout : la même
vérification est donc refaite côté serveur avant d'ouvrir l'étape suivante.

Continuer sans licence reste possible. Une instance non activée n'est pas
bloquée, elle est non activée, et son écran « Licence » la rattrapera.

## Sections et services

Les écrans d'administration sont rangés en sept sections — pilotage, personnel,
gestion, relations, technique, conformité, réglages — dans une liste unique,
`app/Core/Sections.php`. C'est le pendant exact de `src/sections.js` de l'édition
Node : mêmes groupes, mêmes services, mêmes icônes, mêmes droits. Les deux
éditions doivent montrer le même produit, et une rubrique rangée ici et là est
une rubrique qu'on ne retrouve plus en changeant d'édition.

Le rail n'affiche qu'une section dépliée à la fois, et le pli se pose sur
`<html>` avant le premier rendu, comme le thème. Sans JavaScript, il arrive
entier : plier côté serveur enfermerait derrière des groupes qu'on ne pourrait
plus ouvrir.

Chaque section ouvre sur `/section/<clé>`, un tableau de bord qui pose ses
services en tuiles. Le rail ne montre — et ces tuiles ne proposent — que ce que
le compte connecté peut réellement ouvrir, droits et modules débloqués compris :
un raccourci vers un écran interdit est une porte peinte sur un mur. Ce n'est pas
une déclaration crue sur parole : `tests/test_sections.php` ouvre chaque service
avec chaque rôle et compare ce que le rail montre à ce que le noyau laisse
passer.

Les onglets propres à un écran ne sont pas dans le rail mais dans une barre sous
son titre. La mise en page tranche en un seul endroit, `app/views/layout.php` :
le chemin courant dit si l'on est dans l'espace d'administration, et rien
d'autre.

## Tests

```bash
php tests/run.php
```

Aucune dépendance ici non plus : un harnais de quelques lignes, des requêtes
jouées à travers le vrai noyau — pas contre une version arrangée pour les
tests.

Chaque exécution a son propre dossier de données, nommé d'après le numéro du
processus, et l'efface en partant. On peut donc lancer la suite complète d'un
côté et relancer un fichier de l'autre sans que les deux se marchent dessus —
ce qui n'était pas le cas : le dossier était partagé, et la seconde exécution
effaçait le brouillon d'installation que la première venait d'écrire. Pour
venir voir ce qu'une exécution a laissé, `TOUTADMIN_GARDER=1 php tests/run.php`
garde le dossier.

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
- **Mises à jour signées** : l'instance interroge le service de licences, vérifie
  l'empreinte *et* la signature Ed25519 de l'archive (sodium), se sauvegarde,
  met de côté les fichiers remplacés, puis les pose. Comme un site PHP ne
  redémarre pas — le code posé est actif à la requête suivante —, la vérification
  est une requête sur sa propre adresse juste après la bascule : si le site ne
  répond plus, les fichiers précédents reviennent dans la seconde. `data/`,
  `config.php` et le reste de l'instance ne sont jamais touchés.
- **Centre de journaux** séparé du journal d'audit : huit niveaux (ceux de la
  RFC 5424), sept canaux, conservation par niveau, et recopie continue vers un
  serveur syslog ou vers Wazuh. Le produit s'y inscrit lui-même — connexion
  refusée, compte verrouillé, jeton invalide, plafond atteint, erreur non
  rattrapée — et aucun secret n'y entre : une clé qui ressemble à un mot de
  passe ou à un jeton est remplacée avant l'écriture. Chaque cible d'export
  garde le numéro de la dernière ligne reçue, donc une panne du collecteur ne
  perd rien et la purge ne prend jamais à l'export ce qu'il n'a pas encore vu.
- En-têtes posés sur chaque réponse, dont une politique de contenu sans
  `unsafe-inline` : les scripts portent un nonce.

## Où en est le portage

L'édition Node compte 78 modules et 51 fichiers de routes. Le portage avance
par lots ; l'état exact est dans [PORTAGE.md](PORTAGE.md).

Ce qui fonctionne aujourd'hui : installation, connexion (avec double
authentification et codes de secours), changement de mot de passe, espace du
salarié, annuaire, profil, seize langues, journal d'audit, plafonds, sessions
en base.
