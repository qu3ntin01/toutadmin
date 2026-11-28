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
                  (page par page) + sitemap.xml, robots.txt, _headers,
                  .htaccess, version.txt à sa racine
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

Sans argument il refait les cent cinquante-deux ; avec des noms il ne refait
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

## SEO et sécurité

**L'adresse du site est écrite une fois**, dans `build.js` (`SITE_URL`) : c'est
elle qui fabrique l'adresse canonique, les balises Open Graph et le plan du
site. Si le site est un jour servi ailleurs qu'à `https://toutadmin.com`, c'est
cette seule ligne qu'on change.

Chaque page porte :

- une adresse canonique absolue et `hreflang` vers ses quinze autres langues,
  plus un repère `x-default` vers le français ;
- Open Graph et Twitter Card, avec une capture d'écran réelle (celle du
  pilotage, proche du format paysage qu'un réseau social recadre — jamais une
  image fabriquée pour l'occasion) ;
- des données structurées (schema.org, JSON-LD) : l'organisation et le site
  partout, le logiciel et son vrai prix sur l'accueil seulement. Rien n'y
  figure qu'on ne lise déjà ailleurs sur le site — pas d'avis ni de note, qui
  se fabriqueraient plutôt qu'elles ne se liraient.

`sitemap.xml` reprend les mêmes variantes de langue (recommandation Google,
en plus de la balise de chaque page, pas à sa place) et un `lastmod` à la date
de construction. `robots.txt` pointe dessus.

**La politique de contenu (CSP) est écrite une fois** (`CSP_BASE`, dans
`build.js`) et sert trois fois : la balise de chaque page, `_headers`
(Netlify, Cloudflare Pages) et `.htaccess` (Apache — OVH, Infomaniak, la
plupart des mutualisés). Les deux fichiers portent aussi ce qu'une balise ne
peut pas poser : HSTS, `X-Frame-Options`, `Permissions-Policy`, et `.htaccess`
en plus force HTTPS dès la première visite, interdit la liste de répertoire
et refuse de servir un fichier caché. Un hébergement qui ne lit ni l'un ni
l'autre garde la balise, qui reste la majorité de la protection.

**`/.well-known/security.txt`** suit la règle des mentions légales : construit
depuis `editeur.json`, et seulement s'il est rempli — une adresse de contact
inventée serait pire qu'absente.

## Thème, mouvement et saison

Trois réglages, posés avant le premier rendu par `assets/theme-boot.js`, dans le menu
de la barre et dans le tiroir :

- **le thème est clair par défaut** — « Système » reste un choix, mais ce n'est plus ce
  que reçoit quelqu'un qui n'a rien demandé ;
- **le mouvement est coupé par défaut** (`data-motion` sur `<html>`). Il s'allume par un
  choix, jamais de lui-même, et jamais pour qui a demandé moins d'animation à son
  système : le réglage est alors verrouillé, et le menu le dit. Changer le réglage
  recharge la page — il décide de ce qui se met en place au chargement ;
- **la saison** (`data-season`) : du 1er décembre au 6 janvier, la page prend sa tenue
  de fêtes, sauf refus du visiteur. `?saison=noel` la montre en tout temps pour la voir
  avant l'heure, `?saison=off` la retire.

La tenue de fêtes tient en deux fichiers : `assets/fete.css` — le décor immobile
(palette de pin et de rubis en clair comme en sombre, guirlande sous la barre, bonnet
sur la marque, boules au héros, congère sur le pied de page) et, sous `data-motion="on"`,
le mouvement (aurore qui dérive, poussière d'or qui scintille, ampoules qui s'allument à
tour de rôle, reflet sur les boutons, filet doré qui tourne autour de la formule mise en
avant) — et `assets/fete.js`, la neige sur trois plans de profondeur et la poussière d'or
qui suit le pointeur, sur un seul canevas que rien n'intercepte. Le dessin est tenu bas :
flocons précalculés, nombre suivant la surface de la fenêtre et qui baisse tout seul si
les images tardent, boucle arrêtée quand l'onglet est caché. Rien d'extérieur n'est
chargé : les dessins sont des SVG en `data:`, que la politique de contenu autorise.

## L'allure

La vitrine porte **la palette que le produit livre par défaut**, « Indigo et
lavande » : fond lavande dégradé, surfaces de verre translucides et arrondies,
indigo et violet pour l'action, titres gras et serrés. Un visiteur qui passe du
site à l'application ne change pas de monde — et les captures, refaites avec
cette palette, se fondent dans la page au lieu de s'y poser comme des corps
étrangers.

- **Le fond vit** : quatre nappes de couleur dérivent lentement derrière toute
  la page (`.ambiance`, posée dans `layout.html`), avec une trame de points qui
  s'estompe vers le bas. Ce sont des dégradés, pas des filtres : la carte
  graphique les déplace sans rien recalculer.
- **La barre** est une carte de verre qui flotte au-dessus de la page. Quatre
  menus s'y déroulent, dans le même esprit que le site de ToutWAF : le premier
  range les quatorze domaines en quatre colonnes (`MEGA_COLS` dans
  `pages/_shared.js`), chacun avec son icône (`DOM_ICONS`) et une ligne qui dit
  ce qu'il contient (`mega.d.*`) ; les trois autres mènent aux **sections** des
  pages — l'édition PHP, la licence, le tarif de lancement — et non aux seules
  pages. Une pastille rassemble la langue et le thème. Les menus s'ouvrent au
  survol sans script ; le script ajoute le clic, le clavier et `aria-expanded`.
  Sous 1 100 points, ils cèdent la place au tiroir, qui reste le plan complet.
- **La largeur de la barre est vérifiée**, pas supposée : en russe, les cinq
  entrées tiennent cent pixels de plus qu'en français. Les seuils (`.nav-client`
  sous 1 380 points, la pastille réduite sous 1 500) ont été posés en mesurant
  les seize langues à huit largeurs, de 1 101 à 1 920 points.

### L'animation de l'accueil

À droite du titre, huit espaces de l'entreprise reliés à un cœur : des données
circulent dans les deux sens le long des liens, et de temps en temps un espace
s'allume et dit ce qui vient de s'y passer — un congé approuvé, une facture
payée. C'est la promesse du produit, une seule base, rendue visible.

- **Rien n'y est inventé en chiffres** : les trois nombres du bas sont ceux de
  `chiffres.json`. Les événements sont des choses que le produit fait, pas des
  statistiques d'usage.
- Le dessin est en SVG, les trajets en SMIL (`animateMotion`), le reste en CSS :
  aucune bibliothèque, rien que la politique de contenu refuse.
- Chaque bulle se pose du côté de son espace qui regarde le centre (champ
  `data-side`, choisi dans `NOEUDS`, `pages/index.js`) : posée au-dessus
  partout, celle d'un espace du bas recouvrait le libellé de son voisin.
- Hors de l'écran, tout s'arrête ; pour qui a demandé moins d'animation, le
  script fige le dessin et aucune bulle n'apparaît.

## La fenêtre du produit

Sous le héros, la vitrine du produit est une fenêtre qui fait défiler
trois écrans : l'espace d'un salarié, un tableau de bord de direction, la
gestion administrative. Les trois sont déclarés dans `medias.js`, champ
`heroTour`, et doivent tous figurer dans `pairs` — sans quoi un site en thème
sombre montrerait une interface claire.

La construction refuse de finir si un écran annoncé n'a pas sa capture, si les
pastilles ne correspondent plus aux écrans, ou si le premier écran passe en
chargement différé : c'est la plus grande image de la page, donc celle que le
navigateur mesure pour noter la vitesse d'affichage.

Ces trois écrans et ceux de la visite guidée sont pris au **cadre fenêtre** :
`captures.js` les photographie à la taille d'un écran d'ordinateur,
seize-dixièmes, sans descendre le long de la page. C'est `CADRE_FENETRE` dans
ce fichier, et c'est ce qui distingue une capture qu'on regarde d'une capture
qu'on lit. Les autres suivent la page, comme avant.

### Le curseur pilote la démonstration

La fenêtre ne fait pas défiler des images : elle mime quelqu'un qui se sert du
logiciel. Un curseur se porte sur l'entrée de menu du prochain écran, appuie,
et c'est l'appui qui fait changer la page — la cause avant l'effet.

Le surlignage violet qu'on voit ensuite dans le rail **est celui du produit** :
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
