# Documentation de Toutadmin

**La documentation publique de Toutadmin, sous forme de site statique autonome.**
Elle vit dans sa propre branche — `documentation` — sans le code du CMS à côté :
aucun fichier n'est partagé avec l'application, rien n'est servi par le CMS, et le
site est destiné à un hébergement séparé.

Toute la documentation est ici, et nulle part ailleurs : le site public, et
**`FONCTIONNALITES.md`**, la carte du produit — ce qui est couvert, ce qui ne l'est
pas encore, et ce qui a été écarté avec la raison. Le code, lui, est sur la branche
`toutadmin`, qui ne porte plus que son `README.md`.

## Construire

```bash
node build.js
```

Aucune dépendance, aucune installation : Node seul suffit. Le script écrit `site/`,
qui est **le dossier à déposer sur l'hébergement**.

```
build.js            assemblage (aucune dépendance)
layout.html         gabarit commun à toutes les pages
pages/*.html        contenu du site, un fichier par page
assets/             feuille de style et scripts
produit.json        instantané des chiffres relevés dans le code
site/               ← sortie générée, prête à héberger
FONCTIONNALITES.md  la carte du produit, hors site : couvert, pas couvert, écarté
```

`FONCTIONNALITES.md` n'entre pas dans le site construit : c'est un document de
travail, tenu à jour à chaque lot livré, où une ligne cochée correspond à du code
en production et testé — pas à une intention.

`site/` est versionné pour pouvoir être publié sans rien exécuter. Après toute
modification de `pages/`, `layout.html` ou `assets/`, **relancer la construction et
committer la sortie**.

## Les deux modes de construction

La documentation doit pouvoir se reconstruire seule — sinon « indépendante » ne
voudrait rien dire — tout en restant capable de relire le logiciel quand il est là,
parce que c'est ce qui empêche ses chiffres de mentir. Le script dit toujours lequel
des deux modes il a pris.

```bash
# Seule : les chiffres viennent de produit.json, écrit par la dernière
# construction faite avec le code. Rien n'est inventé.
node build.js

# Avec le code : tout est relu — dictionnaires, tests, modules, vues — et
# produit.json comme site/data/lexique.json sont rafraîchis.
TOUTADMIN_SOURCE=/chemin/vers/une/copie/de/la/branche/toutadmin node build.js
```

Une construction faite sans le code **n'écrit pas** l'instantané : elle ne doit pas
pouvoir figer des chiffres qu'elle n'a pas vérifiés.

**Après une livraison sur `toutadmin`**, reconstruire avec `TOUTADMIN_SOURCE` et
committer ici : c'est ce qui remet à jour le nombre de clés, de tests, de vues et le
lexique complet.

## Publier

Le site est entièrement statique : ni base de données, ni langage serveur, ni
réécriture d'URL. N'importe quel hébergement convient.

```bash
# Un serveur classique (OVH, Infomaniak, un VPS…)
rsync -av --delete site/ user@serveur:/var/www/documentation/

# Netlify, Vercel, Cloudflare Pages
#   branche          : documentation
#   dossier à publier : site
#   commande de build : node build.js

# GitHub Pages — la source doit être la racine ou /docs d'une branche ;
# publier alors le contenu de site/ par une action, ou servir la branche
# telle quelle depuis un autre hébergeur.
```

Pour vérifier localement avant de publier :

```bash
node build.js && (cd site && python3 -m http.server 8080)
```

Le site fonctionne aussi ouvert directement depuis le disque, à deux exceptions près :
la **recherche** et le **lexique** chargent leurs données par `fetch`, que les
navigateurs bloquent sur `file://`. Passez par un serveur pour les essayer.

## Modifier le contenu

Chaque page est un fragment HTML dans `pages/`, précédé d'un petit en-tête séparé du
corps par une ligne vide :

```
title: Sécurité
nav: Sécurité
description: Phrase affichée dans les résultats de recherche et la balise meta.
scripts: lexique.js        (facultatif)

<h1>Sécurité</h1>
…
```

Le gabarit, la barre latérale, le sommaire de droite, les liens « précédent / suivant »
et l'index de recherche sont produits automatiquement. L'ordre des pages et leur
regroupement se règlent dans la constante `NAV`, en tête de `build.js`.

### Chiffres injectés

Pour qu'aucun chiffre de la documentation ne puisse contredire le dépôt, certains
sont extraits du code au moment de la construction — ou repris de `produit.json`,
qui les tient de la dernière construction faite avec le code — et écrits dans les
pages sous forme de jetons :

| Jeton | Valeur |
| --- | --- |
| `{{i18n.locales}}` | Nombre de langues |
| `{{i18n.keys}}` | Nombre de clés de traduction |
| `{{i18n.strings}}` | Clés × langues |
| `{{i18n.statuses}}` | Valeurs de statut couvertes |
| `{{i18n.statusKeys}}` | Clés de statut distinctes |
| `{{tests.cases}}` | Cas de test |
| `{{tests.suites}}` | Suites de test |
| `{{tests.files}}` | Fichiers de test |
| `{{src.modules}}` | Modules dans `src/` |
| `{{views.count}}` | Vues EJS |

Deux fichiers de données sont également produits :

- `site/data/lexique.json` — les 16 dictionnaires complets, qui alimentent
  la page **Lexique** et peuvent être relus par un outil de traduction ;
- `site/data/recherche.json` — l'index de la recherche du site.

## Conventions

Le site suit les mêmes règles que le produit :

- **aucun style ni gestionnaire d'événement en ligne** ;
- **propriétés logiques** (`inline-start` plutôt que `left`), pour que la mise en page
  ne soit pas figée dans un sens de lecture ;
- thème clair, sombre ou système, mémorisé dans le navigateur ;
- responsive jusqu'à 390 px sans débordement horizontal ;
- aucune dépendance extérieure, aucun appel réseau vers un tiers.
