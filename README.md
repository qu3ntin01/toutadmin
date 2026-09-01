# Private Member

Portail interne de gestion du personnel, des outils, du temps et des ressources humaines.
Interface inspirée de l'univers OVHcloud : navigation latérale bleu profond, contenu dense,
angles droits, accent bleu.

## Espaces

| Espace | URL | Qui y accède |
| --- | --- | --- |
| Administration | `/admin` | Administrateurs uniquement |
| Ressources humaines | `/rh` | Administrateurs et membres désignés RH |
| Espace personnel | `/mon-espace` | Chaque membre, pour ses propres données |

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

### Confort
- Thème **clair / sombre / système**, mémorisé dans le navigateur et appliqué sans clignotement
- Interface responsive : la navigation latérale se replie en bandeau horizontal, les tableaux denses défilent

## Sécurité

Le contrôle d'accès repose sur trois garde-fous serveur : `requireAdmin` sur `/admin/*`,
`requireHR` sur `/rh/*`, `requireEmployee` sur `/mon-espace/*`. Seul un administrateur peut
accorder ou retirer l'accès RH.

- **Mots de passe** : hachage `bcrypt` (coût 12). Les mots de passe temporaires sont aléatoires et affichés une seule fois. Aucun mot de passe d'outil tiers n'est stocké, seulement l'identifiant et l'URL.
- **Verrouillage de compte** : 5 échecs consécutifs verrouillent le compte 15 minutes.
- **Limitation de débit** : 10 tentatives de connexion / 15 min par IP, 300 requêtes / minute au global (ajustables par variables d'environnement).
- **Anti-énumération** : message et temps de réponse identiques que le compte existe ou non.
- **CSRF** : jeton par session vérifié en comparaison à temps constant sur chaque POST.
- **Sessions** : cookie `httpOnly`, `sameSite=lax`, `secure` en production, identifiant régénéré à la connexion.
- **En-têtes** : `helmet` avec CSP stricte (scripts par nonce, aucun style ni gestionnaire d'événement en ligne).
- **Validation** : grades, types de contrat et de demande contrôlés contre des listes blanches ; emails, URL, dates et montants validés ; longueurs bornées.
- **Base** : requêtes intégralement paramétrées (`better-sqlite3`).
- **Erreurs** : aucune trace technique renvoyée au client.
- **Démarrage** : refus de démarrer en production si `SESSION_SECRET` ou `ADMIN_PASSWORD` sont restés à leur valeur par défaut.

Aucun système n'est protégé de façon absolue, mais ces mesures couvrent les risques
standards (injection, authentification, XSS, CSRF, contrôle d'accès, mauvaise configuration).

## Démarrage

```bash
npm install
cp .env.example .env      # personnaliser ADMIN_EMAIL / ADMIN_PASSWORD / SESSION_SECRET
npm start                 # http://localhost:3000
```

Au premier démarrage, le compte administrateur est créé à partir du fichier `.env`.

Pour explorer l'application avec des données réalistes :

```bash
node scripts/seed-demo.js   # 5 membres, outils, demandes, fiches de paie, pointages
```

Tous les comptes de démonstration partagent le mot de passe `demo-1234`, dont
`claire.moreau@entreprise.com` (responsable RH) et `lucas.petit@entreprise.com` (freelance).

## Tests

```bash
npm test
```

45 tests d'intégration couvrent l'authentification (mauvais mot de passe, verrouillage,
rejet CSRF), le cloisonnement des trois espaces, la création de membres et ses validations,
la désactivation automatique en fin de contrat, les outils et affectations, le pointage
freelance, le cycle RH complet (demande → approbation → décompte du solde → annulation →
recrédit) et les fiches de paie. Ils tournent sur une base SQLite temporaire isolée.

## Variables d'environnement

| Variable | Description |
| --- | --- |
| `PORT` | Port d'écoute (défaut `3000`) |
| `SESSION_SECRET` | Secret de signature des sessions — obligatoire et unique en production |
| `ADMIN_EMAIL` | Email du compte administrateur créé au démarrage |
| `ADMIN_PASSWORD` | Mot de passe de ce compte — fort et obligatoire en production |
| `NODE_ENV` | `production` active les cookies sécurisés, les vérifications de secrets et le cache statique |
| `TRUST_PROXY` | À définir (ex. `1`) derrière un reverse proxy, pour que la limitation de débit voie la bonne IP |
| `DB_PATH` | Emplacement de la base SQLite (défaut `data/app.sqlite`) |
| `LOGIN_RATE_LIMIT` / `GLOBAL_RATE_LIMIT` | Plafonds de requêtes, ajustables pour les tests ou un usage interne intensif |

## Structure

```
src/
  app.js             assemblage de l'application Express (middlewares, montage des routeurs)
  server.js          démarrage du serveur et balayage périodique des contrats échus
  db.js              base SQLite, schéma, migrations, désactivation des contrats expirés
  security.js        CSRF, limitation de débit, verrouillage de compte, nonce CSP
  utils.js           helpers partagés (flash, validation, génération de mot de passe)
  timesheet.js       pointage : entrées, heures cumulées, estimation de rémunération
  hr.js              demandes, soldes de congés, fiches de paie
  grades.js · contract-types.js · request-types.js   listes blanches métier
  middleware/auth.js contrôle d'accès admin / employé / RH
  routes/            auth.js · admin.js · employee.js · rh.js
views/
  partials/          head, sidebar, en-têtes, icônes, sélecteur de thème
  login · error · admin · rh · employee · employee-edit · employee-timesheet · tool-edit
public/              feuille de style, thème, chronomètre, confirmations
scripts/seed-demo.js jeu de données de démonstration
tests/               suite d'intégration (node --test)
```
