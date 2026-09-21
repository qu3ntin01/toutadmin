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

Les écrans ne forment plus une page à part : la page Fonctionnalités a deux
onglets, « Fonctionnalités » et « Les écrans », le second à l'ancre `#ecrans`.
Un lien vers une ancre cachée dans l'onglet fermé l'ouvre — c'est le script qui
s'en charge. L'adresse `ecrans.html` reste servie dans chaque langue, en
redirection, pour que les liens déjà partagés continuent d'aboutir.

```
build.js        assemblage des pages (aucune dépendance)
layout.html     gabarit commun
pages/*.js      une fonction de rendu par page
content/*.js    un dictionnaire par langue ; fr est la référence
medias.js       la carte des captures : onglets, galerie, paires clair/sombre
medias/         les captures d'écran (PNG à palette)
captures.js     refait les captures sur une instance réelle (Playwright)
images.js       réduit une capture à 256 couleurs et réécrit le PNG
chiffres.js     compte les chiffres de la page d'accueil dans les deux éditions
chiffres.json   ← leur relevé, versionné ; c'est lui que la construction lit
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
Sept écrans existent aussi en thème sombre ; la page bascule l'image avec elle,
sans quoi un site sombre afficherait des captures claires.

**Elles se refont en une commande**, et la marche à suivre est en tête de
`captures.js` : deux instances du CMS — celle de démonstration, et une seconde
qu'on laisse devant son assistant d'installation, parce que cette porte se
referme dès qu'on a fini de l'installer.

```bash
npm install --no-save playwright        # jamais dans le dépôt du site

CMS=http://127.0.0.1:8500 CMS_NEUF=http://127.0.0.1:8501 \
ADMIN_MDP=… MEMBRE_MDP=… node captures.js [nom-de-capture …]
```

Sans argument il refait les cent cinquante et une ; avec des noms il ne refait
que ceux-là. Chaque image sort du navigateur en couleurs vraies et passe par
`images.js`, qui la réduit à deux cent cinquante-six couleurs : le poids tombe
d'un facteur quatre pour un écart qu'on ne voit pas. Les pages plus hautes que
mille quatre cents points sont coupées là — le site n'en montre jamais autant,
et la visionneuse écraserait le reste en un ruban illisible.

Le script change la langue des deux comptes de démonstration comme le ferait un
utilisateur, par l'écran « Mon profil », et les remet en français en partant :
un cookie ne suffit pas, la préférence enregistrée du compte l'emporte une fois
la session ouverte.

## Les chiffres de la page d'accueil

Six nombres : des espaces, des langues, des tests, des modules, des libellés.
Ils étaient écrits à la main. Ils ont vieilli — la page annonçait mille sept
cent quarante-six tests quand il y en avait mille sept cent quatre-vingt-onze,
et trente-cinq espaces quand il y en avait quarante-quatre, dans seize langues
à la fois. Un chiffre invérifiable sur une page de vente est pire qu'un chiffre
absent : il se vérifie, justement.

```bash
TOUTADMIN_SOURCE=/chemin/vers/toutadmin \
TOUTADMIN_PHP_SOURCE=/chemin/vers/version-web node chiffres.js
```

Le script écrit `chiffres.json`, versionné. **La construction ne lit que ce
fichier** : le site se bâtit sur une machine qui n'a pas les deux éditions sous
la main, et le jour où les chiffres bougent, cela se voit dans l'historique au
lieu de dériver en silence.

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

Les cent cinquante captures portent la même empreinte, et pour la même raison
en pire : l'hébergement les sert avec **un mois** de cache. Sans empreinte,
refaire une capture ne suffit pas à la montrer — le visiteur déjà venu et tout
cache posé devant le site gardent l'ancienne image jusqu'à trente jours, le
héros de la page d'accueil compris. Ce n'est pas une hypothèse : au lendemain
d'une refonte complète des captures, cent quarante-six des cent cinquante
étaient à jour en ligne et quatre ne l'étaient pas — le héros, l'écran de
pilotage, et leurs deux jumelles sombres.

## La fenêtre du héros

La page d'accueil ne montre plus une capture, mais une fenêtre qui fait défiler
trois écrans : l'espace d'un salarié, un tableau de bord de direction, la
gestion administrative. Les trois sont déclarés dans `medias.js`, champ
`heroTour`, et doivent tous figurer dans `pairs` — sans quoi un site en thème
sombre montrerait une interface claire.

La construction refuse de finir si un écran annoncé n'a pas sa capture, si les
pastilles ne correspondent plus aux écrans, ou si le premier écran passe en
chargement différé : c'est la plus grande image de la page, donc celle que le
navigateur mesure pour noter la vitesse d'affichage.

Le héros est pris au **cadre fenêtre** : `captures.js` le photographie à la
taille d'un écran d'ordinateur, seize-dixièmes, sans descendre le long de la
page. C'est `CADRE_FENETRE` dans ce fichier, et c'est ce qui distingue une
capture qu'on regarde d'une capture qu'on lit. Les autres suivent la page,
comme avant.

### Le curseur pilote la démonstration

La fenêtre ne fait pas défiler des images : elle mime quelqu'un qui se sert du
logiciel. Un curseur se porte sur l'entrée de menu du prochain écran, appuie,
et c'est l'appui qui fait changer la page — la cause avant l'effet.

Le surlignage bleu qu'on voit ensuite dans le rail **est celui du produit** :
chaque capture a été prise sur la page en question, donc son entrée de menu y
est déjà active. Rien n'est simulé ; le curseur se pose au bon endroit et la
capture fait le reste. Les positions sont dans `medias.js`, champ `heroCible`,
en pourcentage de ce que montre la fenêtre — barre de titre exclue, puisque le
curseur vit dans la zone d'écran.

Elles sont posées **en pixels** par le script, pas en pourcentage : dans
`transform: translate3d()`, un pourcentage se rapporte à la boîte de l'élément
déplacé, pas à celle qui le contient. Onze pour cent d'un curseur de vingt-six
pixels font trois pixels.

Le mouvement — redressement à l'arrivée, jeu au pointeur, profondeur des
plans, reflet, nappe de lumière qui suit la main, curseur, poussée d'un écran
à l'autre — est entièrement en `transform` et `opacity`, et s'efface pour qui
a demandé moins d'animations : plus de curseur, plus de nappe, plus de
démonstration. Les pastilles, elles, restent : un réglage d'accessibilité ne
doit pas retirer une fonction.

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
