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
`site/`, **le dossier à déposer sur l'hébergement** : cinq pages × seize langues,
le français à la racine, les quinze autres dans leur sous-dossier.

Les écrans ne forment plus une page à part : ils ferment la page
Fonctionnalités, à l'ancre `#ecrans`. L'adresse `ecrans.html` reste servie dans
chaque langue — une redirection, pour que les liens déjà partagés continuent
d'aboutir.

```
build.js        assemblage des pages (aucune dépendance)
layout.html     gabarit commun
pages/*.js      une fonction de rendu par page
content/*.js    un dictionnaire par langue ; fr est la référence
medias.js       la carte des captures : onglets, galerie, paires clair/sombre
medias/         les captures d'écran (PNG)
assets/         feuille de style, script, thème, favicon
site/           ← sortie générée, versionnée, prête à héberger
```

**`site/` est versionné.** Le serveur de production n'exécute rien : il fait un
`git pull`, et les pages sont déjà là. Cela recopie les captures une seconde
fois dans le dépôt — c'est le prix d'un déploiement qui ne peut pas échouer au
mauvais moment, et il est payé une fois pour toutes.

> **La règle qui va avec** : après toute modification de `content/`, `pages/`,
> `layout.html`, `assets/` ou `medias/`, **relancer `node build.js` et committer
> `site/` dans le même commit**. Un dépôt où la sortie ne correspond plus aux
> sources publie l'ancienne version sans prévenir personne.

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

## L'adresse du site de documentation

Le lien « Documentation » de la barre, du tiroir et du pied de page pointe vers
un site séparé. Son adresse est écrite **une seule fois**, en tête de
`build.js` :

```js
const DOC_URL = 'https://docs.toutadmin.com/';
```

Changez cette ligne, reconstruisez, et les quatre-vingt-seize pages suivent.

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

### Sur votre serveur, par git

C'est le mode prévu : le dépôt est cloné sur le serveur, la racine du site
pointe sur `site/`, et une mise à jour tient en une commande.

```bash
# Une fois, sur le serveur
git clone -b siteweb <dépôt> /var/www/toutadmin
# puis, dans la configuration du serveur web :
#   root /var/www/toutadmin/site;

# À chaque mise à jour
cd /var/www/toutadmin && git pull
```

Rien à construire, rien à installer, aucun temps d'indisponibilité : les
fichiers servis sont ceux du dépôt.

Les trois ressources — feuille de style, script de thème, script de page —
portent l'empreinte de leur contenu dans leur adresse (`app.js?v=57b4a8b7…`).
L'hébergement les sert avec douze heures de cache : sans cette empreinte, un
visiteur déjà venu garderait l'ancien script une demi-journée alors que le HTML,
lui, serait neuf — et un bouton tout neuf ne répondrait pas. Une adresse qui
change à chaque modification supprime la classe entière de ce problème.

**Pour savoir ce que le serveur sert vraiment**, sans ouvrir une page ni vider
un cache : `https://votre-domaine/version.txt`. Le fichier relève le barème
affiché et les décomptes. Il ne porte pas de date — il ne change que lorsque
les prix changent, ce qui est précisément ce qu'on cherche à vérifier quand
une page a l'air périmée.

### Autres hébergements

```bash
# Copie par rsync, sans cloner
rsync -av --delete site/ user@serveur:/var/www/toutadmin/

# Netlify, Vercel, Cloudflare Pages
#   branche           : siteweb
#   dossier à publier : site
#   commande de build : aucune (ou « node build.js » pour reconstruire)
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
