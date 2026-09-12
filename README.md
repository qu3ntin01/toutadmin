# Vitrine de Toutadmin

**Le site public de Toutadmin** : ce que le produit fait, à quoi il ressemble,
combien il coûte. Un site statique, sans dépendance, construit dans les seize
langues du logiciel.

> Le code du produit est sur la branche `toutadmin`, la documentation technique
> sur la branche `documentation`. Cette branche-ci — `siteweb` — ne contient que
> le site public.

## Construire

```bash
node build.js
```

Node seul suffit — aucune installation, aucun appel réseau. Le script écrit
`site/`, **le dossier à déposer sur l'hébergement** : six pages × seize langues,
le français à la racine, les quinze autres dans leur sous-dossier.

```
build.js        assemblage des pages (aucune dépendance)
layout.html     gabarit commun
pages/*.js      une fonction de rendu par page
content/*.js    un dictionnaire par langue ; fr est la référence
medias.js       la carte des captures : onglets, galerie, paires clair/sombre
medias/         les captures d'écran (PNG)
assets/         feuille de style, script, thème, favicon
site/           ← sortie générée, prête à héberger (non versionnée)
```

`site/` **n'est pas dans le dépôt** : il ne contient rien qui ne se reconstruise
en deux secondes, et il recopierait les neuf mégaoctets de captures une seconde
fois. La commande de construction n'a besoin de rien d'autre que Node, donc un
hébergeur qui sait lancer `node build.js` publie la branche telle quelle.

**Une langue incomplète n'est pas construite à moitié** : si une clé manque, ou
si une liste n'a pas la même longueur qu'en français, le script s'arrête et dit
laquelle. Une page à moitié traduite donne l'impression d'un produit à moitié
fini — c'est exactement ce qu'une vitrine ne doit pas faire.

## Les captures

Toutes viennent d'une instance réelle peuplée d'un jeu de démonstration : aucune
maquette, aucun montage. Les six écrans du héros et du carrousel existent **dans
chaque langue** — montrer une interface française à un visiteur japonais
annulerait la promesse des seize langues. Les autres écrans sont en français.

Six écrans existent aussi en thème sombre ; la page bascule l'image avec elle,
sans quoi un site sombre afficherait des captures claires.

Pour refaire les captures : peupler une instance, puis piloter un navigateur —
la procédure tient en un script, et les fichiers se déposent dans `medias/` sous
le nom `<écran>.png`, `<écran>-<langue>.png` ou `<écran>-sombre.png`.

## Modifier le contenu

Le texte vit dans `content/fr.js` et ses quinze traductions ; les pages n'en
contiennent aucun. Ajouter une phrase, c'est ajouter une clé au français puis
aux quinze autres fichiers — le build refuse de construire tant que ce n'est pas
fait.

Les listes de fonctionnalités (`dom.*.items`) sont des tableaux : la longueur
doit être identique d'une langue à l'autre.

## Publier

Site entièrement statique : ni base de données, ni langage serveur, ni
réécriture d'URL.

```bash
# Un serveur classique
rsync -av --delete site/ user@serveur:/var/www/toutadmin/

# Netlify, Vercel, Cloudflare Pages
#   branche          : siteweb
#   dossier à publier : site
#   commande de build : node build.js
```

Pour vérifier localement :

```bash
node build.js && (cd site && python3 -m http.server 8080)
```

## Conventions

Les mêmes que le produit, et pour les mêmes raisons :

- **aucun style ni gestionnaire d'événement en ligne** ;
- **propriétés logiques** (`inline-start` plutôt que `left`) : l'arabe retourne
  la page entière, y compris la barre de navigation et les flèches ;
- thème clair, sombre ou système, posé avant le premier rendu pour éviter
  l'éclair blanc ;
- responsive jusqu'à 390 px sans débordement horizontal ;
- animations coupées net si la personne a demandé moins de mouvement ;
- aucune dépendance extérieure, aucun appel réseau vers un tiers.

## Ce qui reste à faire

Cette branche est **une vitrine, pas une boutique** : aucun paiement, aucun
compte, aucun tunnel de commande. Les boutons mènent à la page de contact.

L'étape suivante — non commencée — est l'intégration à WHMCS : commande et
facturation côté WHMCS, puis une clé de licence vérifiée par l'instance. Rien
dans ce site ne la préempte.
