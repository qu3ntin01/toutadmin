# Site de documentation

Ce dossier contient **la documentation publique de Toutadmin, sous forme de site
statique autonome**. Il est indépendant du logiciel : aucun fichier n'est partagé avec
l'application, rien n'est servi par le CMS, et le site est destiné à un hébergement
séparé.

> À ne pas confondre avec `docs/`, qui reste la carte interne du produit
> (`docs/FONCTIONNALITES.md` : ce qui est couvert, ce qui ne l'est pas encore).

## Construire

```bash
node doc/build.js
```

Aucune dépendance, aucune installation : Node seul suffit. Le script écrit `doc/site/`,
qui est **le dossier à déposer sur l'hébergement**.

```
doc/
  build.js        assemblage (aucune dépendance)
  layout.html     gabarit commun à toutes les pages
  pages/*.html    contenu, un fichier par page
  assets/         feuille de style et scripts
  site/           ← sortie générée, prête à héberger
```

`doc/site/` est versionné pour pouvoir être publié sans rien exécuter. Après toute
modification de `pages/`, `layout.html` ou `assets/`, **relancer la construction et
committer la sortie**.

## Publier

Le site est entièrement statique : ni base de données, ni langage serveur, ni
réécriture d'URL. N'importe quel hébergement convient.

```bash
# Un serveur classique (OVH, Infomaniak, un VPS…)
rsync -av --delete doc/site/ user@serveur:/var/www/documentation/

# Netlify, Vercel, Cloudflare Pages
#   dossier à publier : doc/site
#   commande de build : node doc/build.js

# GitHub Pages
#   publier le contenu de doc/site/ sur la branche gh-pages
```

Pour vérifier localement avant de publier :

```bash
node doc/build.js && (cd doc/site && python3 -m http.server 8080)
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
sont extraits du code au moment de la construction et écrits dans les pages sous forme
de jetons :

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
