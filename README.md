# Salarié Member

Portail interne d'entreprise : personnel, outils, temps, ressources humaines, équipes,
annuaire et messagerie. Interface inspirée de l'univers OVHcloud (navigation latérale
bleue, contenu dense, angles droits) disponible en 16 langues.

## Espaces

| Espace | URL | Qui y accède |
| --- | --- | --- |
| Administration | `/admin` | Administrateurs uniquement |
| Ressources humaines | `/rh` | Administrateurs et membres désignés RH |
| Manager | `/mon-equipe` | Tout membre encadrant au moins une équipe ou un service |
| Espace personnel | `/mon-espace` | Chaque membre, pour ses propres données |
| Profil | `/mon-profil` | Chaque membre, pour ses propres réglages |
| Annuaire | `/annuaire` | Tout membre connecté |
| Messagerie | `/messagerie` | Tout membre connecté |
| Agenda | `/agenda` | Chaque membre, pour son propre calendrier |
| CSE | `/cse` | Salariés représentés par le comité (hors freelances et administrateurs) |
| Gestion du CSE | `/cse/gestion` | Membres élus dont le mandat court encore |
| Gestion administrative et financière | `/gestion` | Administrateurs et membres désignés gestionnaires |
| Comptabilité, Paie, Facturation électronique, Stock, CRM | voir « Modules débloquables » | Modules optionnels, éteints par défaut |
| Salles | `/salles` | Tout membre connecté, pour réserver et voir le planning |

## Fonctionnalités

### Administration
- Création de membres avec grade, service, **type de contrat** (CDI, CDD, Intérim, Stage, Alternance, Freelance) et **date de fin optionnelle**
- Modification du profil à tout moment, activation/désactivation, réinitialisation de mot de passe, suppression
- **Désactivation automatique** du compte à l'échéance du contrat (vérifiée à la connexion, à l'ouverture du tableau de bord et toutes les heures)
- Catalogue d'outils (nom, catégorie, référence, description, **URL de connexion**), modifiable après création
- Affectation d'outils avec un **identifiant propre à chaque membre**
- Désignation des responsables RH

### Pointage et rémunération — freelances
- Chronomètre dans l'espace du membre : démarrage/arrêt, temps écoulé en direct, historique des sessions
- Un seul pointage ouvert à la fois, refusé côté serveur si un autre est en cours
- **TJM** réglé par l'administration ; taux horaire = TJM ÷ 8 h, estimations mensuelle et totale
- Vue consolidée côté admin, avec clôture d'un pointage oublié et suppression d'une entrée erronée

### Ressources humaines — personnel non-freelance
- Demandes de congés, RTT, absence, télétravail : jours ouvrés calculés automatiquement (week-ends exclus)
- Suivi du statut, annulation possible tant que la demande est en attente
- Panneau RH : approbation (décompte du solde), refus motivé, annulation d'une demande approuvée avec **recrédit automatique du solde**
- Gestion des soldes de congés : ajustement manuel crédit/débit avec motif et historique
- Fiches de paie : création (période, brut, net), suivi du versement, consultation par le membre
- Quota de congés annuel posé à l'installation, attribué à la création d'un membre non-freelance

### CSE — comité social et économique
- **Espace salarié** (`/cse`) : avantages et réductions négociés par le comité, avec code, lien et date de validité ; composition du comité ; réunions et comptes-rendus publiés
- **Se présenter au CSE** : dépôt d'une candidature avec profession de foi pendant la phase de candidatures, retrait possible tant que le vote n'est pas ouvert
- **Vote à bulletin secret** : le bulletin et l'émargement sont écrits ensemble mais dans deux tables sans lien entre elles — la base dit qui a voté, jamais pour qui. Un seul vote par salarié, refusé côté serveur
- **Espace de gestion des élus** (`/cse/gestion`) : publication et retrait des avantages, rédaction et publication des comptes-rendus. Un mandat échu referme automatiquement cet espace
- **Pilotage RH** (`/rh#cse`) : composition du comité et durée des mandats, organisation des élections (candidatures → vote → clôture), validation ou refus des candidatures, convocation des réunions, participation et résultats
- Le comité représente les salariés : **les freelances et les administrateurs n'y ont pas accès**, ni à l'espace, ni au corps électoral

### Agenda
- **Calendrier mensuel** par membre : grille du lundi au dimanche, navigation de mois en mois, retour au mois courant
- Événements personnels : intitulé, journée entière ou créneau horaire, plusieurs jours, catégorie et lieu
- Le calendrier **reprend ce que le site sait déjà** : congés et absences approuvés, réunions du CSE, échéance de contrat — sans ressaisie, et sans possibilité de les supprimer depuis l'agenda
- Colonne « à venir » sur 30 jours et liste détaillée du mois sous le calendrier

### Agenda partagé
- Chaque événement porte une **portée** : privé (défaut), équipe, ou service — modifiable après coup depuis la liste du mois
- Une bascule **« Mon agenda » / « Agenda de l'équipe »** superpose les événements que les collègues ont ouverts, chacun préfixé du nom de son auteur
- Un événement « Équipe » atteint les coéquipiers, un événement « Service » tout le service ; un événement privé ne sort jamais de son agenda
- Les **absences approuvées des coéquipiers** apparaissent dans la vue partagée, mais seulement comme « Absent » : ni le type d'absence, ni le motif ne franchissent le partage
- Un salarié sans rattachement n'a pas de vue partagée, et son sélecteur de portée est désactivé

### Gestion administrative et financière
- **Tiers** : clients et fournisseurs, avec contact, identifiant d'entreprise et nombre de contrats et factures rattachés
- **Contrats** fournisseurs et commerciaux : montant, périodicité, référent interne, et surtout **préavis** — le CMS calcule la date limite de dénonciation et alerte avant que la reconduction tacite ne soit acquise
- **Factures** dans les deux sens : recettes clients et dépenses fournisseurs, TTC calculé, retard déduit de l'échéance sans statut à maintenir à la main
- **Budgets par service et par exercice**, dont le consommé agrège automatiquement les factures fournisseurs du service et les notes de frais approuvées de ses membres
- **Notes de frais** : dépôt par le salarié, approbation puis remboursement par la gestion. Un remboursement suppose une approbation préalable ; une dépense datée du futur est refusée
- **Parc matériel** : équipements avec numéro de série, garantie et valeur, affectés à un salarié et repris. Un équipement déjà affecté ne peut pas l'être deux fois, et l'historique des détenteurs est conservé
- **Salles** : parc de salles et planning d'occupation. Tout salarié réserve depuis `/salles` ; un créneau qui chevauche une réservation existante est refusé en nommant qui l'occupe

### Cycle de vie du salarié
- **Documents d'entreprise** : règlement intérieur, politiques, procédures, avec **accusé de réception exigible**. Un document non lu reste signalé dans l'espace du salarié
- **Formation** : catalogue, sessions datées avec places limitées, demande par le salarié puis confirmation RH. Une session pleine refuse toute inscription de plus, et une inscription confirmée apparaît dans l'agenda
- **Entretiens annuels** : planification, compte-rendu (points forts, axes de progrès, objectifs, appréciation de 1 à 5), et **commentaire du salarié** sur son seul entretien
- **Recrutement** : postes ouverts rattachés à un service et une équipe, candidatures suivies par étapes (reçue → présélection → entretien → offre → recruté / refusé). Un poste pourvu n'accepte plus de candidature

### Organisation : services, équipes et encadrement
- **Services** et **équipes** sont des entités à part entière, créées et modifiées depuis la console d'administration ; une équipe appartient à un service
- **Plusieurs managers par périmètre** : un service comme une équipe acceptent autant de managers que nécessaire. L'encadrement est une relation, pas une colonne sur le salarié
- Un membre est rattaché à une équipe et/ou à un service. Rattacher à une équipe rattache automatiquement à son service
- Un manager de service encadre aussi les équipes que ce service contient
- Qui encadre au moins un périmètre obtient son **espace manager** : effectif consolidé, rattachements, soldes, demandes en cours, absences à venir. Retirer l'encadrement le referme aussitôt
- Supprimer un service ou une équipe **détache** ses membres, ne les supprime jamais
- **Actualités** à trois portées : toute l'entreprise (administration), un service, une équipe. Un manager ne peut publier que sur les périmètres qu'il encadre
- L'accueil du collaborateur affiche **tous ses managers** — ceux de son équipe et ceux de son service — avec son rattachement

### Profil, annuaire et messagerie
- **Profil** : photo (JPEG/PNG/WebP, 2 Mo max), présentation, téléphone, langue et changement de mot de passe — grade, contrat et rattachement restent gérés par l'administration
- **Annuaire** de tous les collaborateurs avec recherche et filtres par service et par équipe, affichant le rattachement et les managers de chacun ; la **visibilité dans l'annuaire est pilotée uniquement par l'administration**, un membre ne peut pas s'y soustraire ni s'y remettre
- **Messagerie interne** : boîte de réception, envoi, réponse, compteur de non-lus, suppression. L'**adresse professionnelle et les serveurs IMAP/SMTP sont configurés par l'administration** et affichés en lecture seule au membre

### Confort
- Interface disponible en **16 langues** (français, anglais, espagnol, allemand, italien, portugais, néerlandais, polonais, russe, turc, arabe, hindi, chinois, japonais, coréen, vietnamien), sélectionnables **par drapeau sur l'écran de connexion** et depuis le profil ; l'arabe bascule l'interface en écriture de droite à gauche
- Thème **clair / sombre / système**, mémorisé dans le navigateur et appliqué sans clignotement
- Interface responsive : la navigation latérale se replie en bandeau horizontal, les tableaux denses défilent, et la grille du calendrier tient en entier sur un téléphone

## Sécurité

Le contrôle d'accès repose sur des garde-fous serveur : `requireAdmin` sur `/admin/*`,
`requireHR` sur `/rh/*`, `requireEmployee` sur `/mon-espace/*`, `requireManager` sur
`/mon-equipe` (recalculé à chaque requête d'après les rattachements réels). Seul un
administrateur peut accorder ou retirer l'accès RH, rattacher un manager, masquer un membre
de l'annuaire ou configurer une messagerie.

- **Mots de passe** : hachage `bcrypt` (coût 12). Les mots de passe temporaires sont aléatoires et affichés une seule fois. Aucun mot de passe d'outil tiers n'est stocké, seulement l'identifiant et l'URL.
- **Verrouillage de compte** : 5 échecs consécutifs verrouillent le compte 15 minutes.
- **Limitation de débit** : 10 tentatives de connexion / 15 min par IP, 300 requêtes / minute au global (ajustables par variables d'environnement).
- **Anti-énumération** : message et temps de réponse identiques que le compte existe ou non.
- **CSRF** : jeton par session vérifié en comparaison à temps constant sur chaque POST.
- **Sessions** : cookie `httpOnly`, `sameSite=lax`, `secure` en production, identifiant régénéré à la connexion.
- **En-têtes** : `helmet` avec CSP stricte (scripts par nonce, aucun style ni gestionnaire d'événement en ligne).
- **Validation** : grades, types de contrat et de demande contrôlés contre des listes blanches ; emails, URL, dates et montants validés ; longueurs bornées.
- **Base** : requêtes intégralement paramétrées (`better-sqlite3`).
- **Téléversement** : photos limitées à 2 Mo, types JPEG/PNG/WebP contrôlés, nom de fichier régénéré aléatoirement, stockage hors du dépôt et servi en lecture seule.
- **Messagerie** : un message n'est lisible que par son expéditeur ou son destinataire, et n'est marqué lu que par ce dernier.
- **Erreurs** : aucune trace technique renvoyée au client.
- **Démarrage** : refus de démarrer en production si `SESSION_SECRET` ou `ADMIN_PASSWORD` sont restés à leur valeur par défaut.

Aucun système n'est protégé de façon absolue, mais ces mesures couvrent les risques
standards (injection, authentification, XSS, CSRF, contrôle d'accès, mauvaise configuration).

## Installation

```bash
npm install
npm start                 # http://localhost:3000
```

Sur une instance vierge, **toute l'application redirige vers l'assistant d'installation**
(`/installation`). Il se déroule en cinq étapes :

1. **Langue** — parmi les 16 langues disponibles ; devient la langue par défaut de l'instance.
2. **Prérequis** — version de Node, dossier de données accessible en écriture, base SQLite,
   dossier des photos de profil, plus deux recommandations de mise en production
   (`TRUST_PROXY`, `NODE_ENV=production`) signalées en ambre sans bloquer.
3. **Organisation** — nom affiché partout dans l'interface, et quota de congés annuels
   attribué à chaque nouveau salarié non-freelance.
4. **Administrateur** — le premier compte, avec un mot de passe de 12 caractères minimum.
5. **Récapitulatif** — vérification puis création de l'instance.

Le tout est écrit en une seule transaction, puis un fichier `data/install.lock` **referme
définitivement l'assistant** : toute visite ultérieure de `/installation` renvoie à la page de
connexion. Le secret de session est généré automatiquement dans `data/session.key`
(permissions `0600`) si `SESSION_SECRET` n'est pas fourni.

### Protéger l'assistant

Sur un serveur exposé, définissez `INSTALL_TOKEN` avant le premier démarrage : l'assistant
réclame alors ce jeton à l'étape des prérequis, ce qui empêche un tiers d'installer
l'instance à votre place pendant la fenêtre qui précède votre propre installation.

```bash
INSTALL_TOKEN=un-jeton-long-et-aleatoire npm start
```

### Mise à jour depuis une version antérieure

Les instances créées avant l'introduction des services et des équipes sont reprises
automatiquement au premier démarrage, une seule fois :

- chaque libellé de service saisi en texte libre devient un **service** ;
- chaque encadrant devient le manager d'une **équipe** portant ses anciens collaborateurs ;
- les actualités d'équipe suivent l'équipe de leur auteur.

Les colonnes `users.department`, `users.manager_id` et `announcements.team_manager_id`
sont ensuite retirées. Aucune action n'est requise ; sauvegardez simplement `data/`
avant la mise à jour, comme pour toute migration.

### Installation sans interface

Pour un déploiement automatisé, renseigner `ADMIN_EMAIL` et `ADMIN_PASSWORD` crée le compte
administrateur au démarrage ; l'instance est alors considérée comme installée et l'assistant
ne s'ouvre pas.

```bash
cp .env.example .env      # ADMIN_EMAIL / ADMIN_PASSWORD / SESSION_SECRET
npm start
```

Pour explorer l'application avec des données réalistes :

```bash
node scripts/seed-demo.js   # 5 membres rattachés, outils, demandes, paie, pointages, actualités, messages
```

Tous les comptes de démonstration partagent le mot de passe `demo-1234`, dont
`claire.moreau@entreprise.com` (responsable RH) et `lucas.petit@entreprise.com` (freelance).

## Tests

```bash
npm test
```

217 tests d'intégration couvrent les modules débloquables (routes en 404 tant qu'un
module est éteint, activation réservée à l'administration, amorçage non dupliqué),
la comptabilité (écriture déséquilibrée refusée, balance équilibrée, facture
comptabilisée une seule fois, export CSV), la paie (plafonnement, doublon de période,
refus pour un freelance, génération en lot), la facturation électronique (SIREN mal
formé, export bloqué sur facture incomplète, XML échappé), le stock (mouvements,
inventaire, seuil, deux niveaux d'approbation) et le CRM (devis facturé une fois,
pipeline pondéré), la gestion (rôle gestionnaire, préavis de contrat,
TTC et retard de facture, budget agrégeant factures et notes de frais, remboursement
conditionné à l'approbation, affectation et reprise d'équipement, chevauchement de
réservation), le cycle RH (accusé de réception, session pleine, appréciation bornée,
commentaire réservé à son propre entretien, poste pourvu fermé aux candidatures), l'organisation (services et équipes, encadrement
multiple, rattachement en cascade, suppression qui détache sans effacer, portée des
actualités), l'agenda partagé (privé jamais visible, portées équipe et service,
absences sans motif, portée d'autrui non modifiable), l'assistant d'installation (redirection d'une instance
vierge, jeton, validations, verrouillage définitif, réglages appliqués), le CSE
(cloisonnement des freelances, cycle complet d'une élection, anonymat du bulletin,
mandat échu, avantages périmés, comptes-rendus), l'agenda (grille, validations,
événements sur plusieurs jours, reprise des congés et des réunions, suppression
limitée à ses propres entrées), l'authentification (mauvais mot de passe, verrouillage,
rejet CSRF), le cloisonnement de tous les espaces, la création de membres et ses validations,
la désactivation automatique en fin de contrat, les outils et affectations, le pointage
freelance, le cycle RH complet (demande → approbation → décompte du solde → annulation →
recrédit), les fiches de paie, l'internationalisation (négociation de langue, bascule par
drapeau, RTL arabe), le profil (mot de passe, présentation), l'annuaire et son masquage,
la messagerie interne et ses règles de confidentialité, ainsi que les rattachements
hiérarchiques et les actualités. Ils tournent sur une base SQLite temporaire isolée.

## Modules débloquables

Cinq modules sont livrés **éteints par défaut**. L'administration les débloque un à un
depuis la console (`/admin`, section Modules) : l'activation ouvre l'espace, ses routes
et son entrée de navigation ; la désactivation les referme **sans rien effacer**. Tant
qu'un module est éteint, ses URL répondent 404.

Chaque module affiche à l'activation ce qu'il **ne** garantit pas — la limite est sur
l'écran, pas enfouie dans une documentation.

| Module | URL | Ce qu'il fait | Ce qu'il ne fait pas |
| --- | --- | --- | --- |
| **Comptabilité** | `/comptabilite` | Plan comptable, journaux, écritures équilibrées, balance, grand livre, export CSV. Une facture se passe en écriture d'un clic. | Ni liasse fiscale, ni télétransmission : l'export alimente l'expert-comptable. |
| **Moteur de paie** | `/paie` | Barèmes paramétrables, calcul du brut au net, part patronale, coût employeur, bulletin détaillé, génération en lot, simulateur. | Les taux sont ceux que vous saisissez ; aucune DSN. |
| **Facturation électronique** | `/facturation-electronique` | Contrôle des mentions EN 16931 et export du XML CII (UN/CEFACT) de chaque facture client. | L'encapsulation PDF/A-3 (Factur-X) et le dépôt sur plateforme agréée restent à faire. |
| **Stock et achats** | `/stock` | Articles, mouvements, seuil d'alerte, demandes d'achat validées par le manager puis par la gestion au-delà de 500 €. | Stock mono-dépôt au dernier prix connu ; ni FIFO, ni CUMP. |
| **CRM commercial** | `/crm` | Contacts, pipeline pondéré, devis convertibles en facture, relances à échéance. | Pas de synchronisation avec une messagerie ni d'automatisation marketing. |

### Ce que ces modules garantissent

- **Une écriture comptable est refusée si elle n'est pas équilibrée**, et le message dit
  de combien : « 100,00 € au débit contre 80,00 € au crédit ». Une ligne porte un débit
  ou un crédit, jamais les deux. Un compte mouvementé est désactivé, jamais supprimé.
- **Le bulletin de paie est calculé**, pas saisi : chaque cotisation s'applique sur le
  brut ou sur la part plafonnée, et le bulletin garde le détail ligne à ligne. Un
  freelance n'a pas de bulletin ; un doublon de période est refusé.
- **Le XML n'est produit que si la facture est conforme** : sinon chaque mention
  manquante est listée telle quelle, émetteur et client compris.
- **Le stock est la somme des mouvements**, jamais une valeur saisie ; un inventaire
  repose le compteur. Une sortie supérieure au stock est refusée.
- **Une demande d'achat suit deux niveaux** : le manager du demandeur, puis la gestion
  au-delà du seuil. Un manager n'arbitre que ses propres collaborateurs.
- **Un devis accepté devient une facture**, une seule fois.

## Messagerie externe (IMAP/SMTP)

L'administration renseigne, pour chaque membre, son adresse professionnelle et les serveurs
IMAP/SMTP ; le membre les voit sans pouvoir les modifier. La **messagerie interne est
pleinement fonctionnelle**, mais la synchronisation avec la boîte externe n'est pas encore
branchée : la relever demande un client IMAP (connexion, parsing MIME, pièces jointes,
threads) qui ne peut pas être validé sans boîte de test. Le modèle de données et les écrans
d'administration sont en place pour l'accueillir.

## Variables d'environnement

| Variable | Description |
| --- | --- |
| `PORT` | Port d'écoute (défaut `3000`) |
| `SESSION_SECRET` | Secret de signature des sessions — généré dans `data/session.key` s'il n'est pas fourni |
| `INSTALL_TOKEN` | Jeton exigé par l'assistant d'installation — recommandé sur un serveur exposé |
| `ADMIN_EMAIL` | Installation sans interface : email du compte administrateur créé au démarrage |
| `ADMIN_PASSWORD` | Mot de passe de ce compte — fort et obligatoire en production |
| `NODE_ENV` | `production` active les cookies sécurisés, les vérifications de secrets et le cache statique |
| `TRUST_PROXY` | À définir (ex. `1`) derrière un reverse proxy, pour que la limitation de débit voie la bonne IP |
| `DB_PATH` | Emplacement de la base SQLite (défaut `data/app.sqlite`) |
| `LOGIN_RATE_LIMIT` / `GLOBAL_RATE_LIMIT` | Plafonds de requêtes, ajustables pour les tests ou un usage interne intensif |
| `UPLOAD_DIR` | Dossier des photos de profil (défaut `data/uploads`) |

## Structure

```
src/
  app.js             assemblage de l'application Express (middlewares, montage des routeurs)
  server.js          démarrage du serveur et balayage périodique des contrats échus
  db.js              base SQLite, schéma, migrations, désactivation des contrats expirés
  install.js         état d'installation, secret de session, contrôles d'environnement
  settings.js        réglages de l'instance (nom, langue par défaut, quota de congés)
  security.js        CSRF, limitation de débit, verrouillage de compte, nonce CSP
  utils.js           helpers partagés (flash, validation, génération de mot de passe)
  timesheet.js       pointage : entrées, heures cumulées, estimation de rémunération
  hr.js              demandes, soldes de congés, fiches de paie
  cse.js             mandats, élections, scrutin anonyme, réunions, avantages
  finance.js         tiers, contrats et préavis, factures, budgets, notes de frais
  resources.js       parc matériel, affectations, salles et réservations
  talent.js          documents, formation, entretiens, recrutement
  modules.js         modules optionnels : activation, barrière de route, limites
  accounting.js      plan comptable, écritures équilibrées, balance, grand livre
  payroll.js         barèmes, calcul du brut au net, bulletins détaillés
  einvoicing.js      contrôle EN 16931 et génération du XML CII
  inventory.js       articles, mouvements, demandes d'achat à deux niveaux
  crm.js             contacts, pipeline, devis, relances
  org.js             services, équipes, encadrement multiple, rattachements
  calendar.js        grille mensuelle, événements personnels et entrées dérivées
  announcements.js   actualités entreprise et équipe
  uploads.js         photos de profil (validation, stockage, suppression)
  i18n.js            négociation de langue, traduction, sens d'écriture
  locales/           16 dictionnaires (fr de référence, 15 traductions)
  grades.js · contract-types.js · request-types.js   listes blanches métier
  middleware/auth.js contrôle d'accès admin / employé / RH / manager / CSE
  routes/            install · auth · admin · employee · rh · manager · profile · directory
                     messages · cse · agenda · gestion · salles
                     comptabilite · paie · facturation-electronique · stock · crm
views/
  partials/          head, sidebar, navigation membre, en-têtes, avatars, icônes, langues, thème
  install · login · error · admin · rh · employee · manager · profile · directory · messages
  cse · cse-manage · agenda · gestion · rooms
  comptabilite · paie · einvoicing · stock · crm
  employee-edit · employee-timesheet · tool-edit
public/              feuille de style, thème, chronomètre, agenda, jauges, confirmations
scripts/seed-demo.js jeu de données de démonstration
tests/               suite d'intégration (node --test)
```
