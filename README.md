# Salarié Member

Portail interne d'entreprise : personnel, outils, temps, ressources humaines, équipes,
annuaire et messagerie. Interface inspirée de l'univers OVHcloud (navigation latérale
bleue, contenu dense, angles droits) disponible en 16 langues.

## Espaces

| Espace | URL | Qui y accède |
| --- | --- | --- |
| Administration | `/admin` | Administrateurs uniquement |
| Ressources humaines | `/rh` | Administrateurs et membres désignés RH |
| Manager | `/mon-equipe` | Tout membre ayant au moins un collaborateur rattaché |
| Espace personnel | `/mon-espace` | Chaque membre, pour ses propres données |
| Profil | `/mon-profil` | Chaque membre, pour ses propres réglages |
| Annuaire | `/annuaire` | Tout membre connecté |
| Messagerie | `/messagerie` | Tout membre connecté |

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
- 25 jours de congés attribués à la création d'un membre non-freelance

### Organisation, managers et actualités
- Chaque membre peut être **rattaché à un manager** depuis la console d'administration (auto-rattachement et boucles hiérarchiques refusés)
- Un membre ayant au moins un collaborateur obtient automatiquement son **espace manager** : effectif, soldes, demandes en cours, absences à venir
- **Actualités** : l'administration publie pour toute l'entreprise, le manager pour sa seule équipe ; les deux fils apparaissent sur la page d'accueil du collaborateur, à côté du nom de son manager

### Profil, annuaire et messagerie
- **Profil** : photo (JPEG/PNG/WebP, 2 Mo max), présentation, téléphone, langue et changement de mot de passe — grade, contrat et rattachement restent gérés par l'administration
- **Annuaire** de tous les collaborateurs avec recherche ; la **visibilité dans l'annuaire est pilotée uniquement par l'administration**, un membre ne peut pas s'y soustraire ni s'y remettre
- **Messagerie interne** : boîte de réception, envoi, réponse, compteur de non-lus, suppression. L'**adresse professionnelle et les serveurs IMAP/SMTP sont configurés par l'administration** et affichés en lecture seule au membre

### Confort
- Interface disponible en **16 langues** (français, anglais, espagnol, allemand, italien, portugais, néerlandais, polonais, russe, turc, arabe, hindi, chinois, japonais, coréen, vietnamien), sélectionnables **par drapeau sur l'écran de connexion** et depuis le profil ; l'arabe bascule l'interface en écriture de droite à gauche
- Thème **clair / sombre / système**, mémorisé dans le navigateur et appliqué sans clignotement
- Interface responsive : la navigation latérale se replie en bandeau horizontal, les tableaux denses défilent

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

99 tests d'intégration couvrent l'assistant d'installation (redirection d'une instance
vierge, jeton, validations, verrouillage définitif, réglages appliqués), l'authentification (mauvais mot de passe, verrouillage,
rejet CSRF), le cloisonnement de tous les espaces, la création de membres et ses validations,
la désactivation automatique en fin de contrat, les outils et affectations, le pointage
freelance, le cycle RH complet (demande → approbation → décompte du solde → annulation →
recrédit), les fiches de paie, l'internationalisation (négociation de langue, bascule par
drapeau, RTL arabe), le profil (mot de passe, présentation), l'annuaire et son masquage,
la messagerie interne et ses règles de confidentialité, ainsi que les rattachements
hiérarchiques et les actualités. Ils tournent sur une base SQLite temporaire isolée.

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
  announcements.js   actualités entreprise et équipe
  uploads.js         photos de profil (validation, stockage, suppression)
  i18n.js            négociation de langue, traduction, sens d'écriture
  locales/           16 dictionnaires (fr de référence, 15 traductions)
  grades.js · contract-types.js · request-types.js   listes blanches métier
  middleware/auth.js contrôle d'accès admin / employé / RH / manager
  routes/            install · auth · admin · employee · rh · manager · profile · directory · messages
views/
  partials/          head, sidebar, navigation membre, en-têtes, avatars, icônes, langues, thème
  install · login · error · admin · rh · employee · manager · profile · directory · messages
  employee-edit · employee-timesheet · tool-edit
public/              feuille de style, thème, chronomètre, confirmations
scripts/seed-demo.js jeu de données de démonstration
tests/               suite d'intégration (node --test)
```
