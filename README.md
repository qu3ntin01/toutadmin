# Private Member

Plateforme de gestion du personnel et des outils qui leur sont affectés — design inspiré des dashboards fintech modernes (sidebar, indigo, cartes blanches).

## Fonctionnalités

- **Espace administrateur**
  - Ajout de membres du personnel avec grade, service, **type de contrat** (CDI, CDD, Intérim, Stage, Alternance, Freelance) et **date de fin de contrat optionnelle**
  - Modification du profil d'un membre (grade, service, contrat) à tout moment
  - Génération automatique d'un mot de passe temporaire pour chaque nouveau membre
  - Activation / désactivation / réinitialisation de mot de passe / suppression d'un membre
  - **Désactivation automatique** : si une date de fin de contrat est renseignée, le compte se désactive tout seul dès que la date est dépassée (vérifié à la connexion, au chargement du tableau de bord, et toutes les heures en tâche de fond)
  - Catalogue d'outils (nom, catégorie, référence, description, **URL de connexion**), modifiable après création
  - Affectation d'outils aux membres, avec **identifiant** propre à chaque membre et note
- **Espace personnel**
  - Page de connexion dédiée (email + mot de passe)
  - Cartes design listant chaque outil affecté avec son identifiant et un bouton d'accès direct vers l'URL de connexion
  - Bandeau d'alerte sur la durée de contrat restante (ou l'expiration) si une date de fin est définie
- **Thème clair / sombre / système** — bouton à trois positions (soleil / lune / écran) dans l'en-tête. Le choix est mémorisé dans le navigateur (`localStorage`) et appliqué sans flash au chargement.

## Sécurité

Seul un compte de rôle **administrateur** peut créer, modifier, désactiver ou supprimer un membre du personnel ou un outil (middleware `requireAdmin` sur toutes les routes `/admin/*`) ; un compte employé n'a accès qu'à ses propres outils affectés.

Mesures mises en place :

- **Mots de passe** : hachage `bcrypt` (coût 12), jamais stockés en clair. Les mots de passe temporaires générés sont aléatoires (12 caractères) et affichés une seule fois à l'admin. Les URL/identifiants d'outils ne remplacent pas un gestionnaire de mots de passe : aucun mot de passe tiers n'est stocké par l'application, seulement l'identifiant et l'URL d'accès.
- **Verrouillage de compte** : après 5 échecs de connexion consécutifs, le compte est verrouillé 15 minutes.
- **Anti-brute-force réseau** : limitation à 10 tentatives de connexion / 15 min par adresse IP, et une limite globale de 300 requêtes / minute.
- **Anti-énumération de comptes** : réponse générique et temps de réponse constant (comparaison bcrypt factice) que l'email existe ou non.
- **CSRF** : jeton unique par session, vérifié en comparaison à temps constant sur chaque formulaire POST.
- **Sessions** : cookie `httpOnly`, `sameSite=lax`, `secure` en production, régénération de l'identifiant de session à la connexion (anti session-fixation), secret dédié (`SESSION_SECRET`).
- **En-têtes de sécurité** : `helmet` avec Content-Security-Policy stricte (scripts avec nonce, aucun style/script inline non nonce, pas de `unsafe-inline`), HSTS, `X-Frame-Options`, etc.
- **Validation des entrées** : grade et type de contrat contrôlés côté serveur contre une liste blanche, email et URL validés, longueurs de champs bornées.
- **Base de données** : requêtes 100 % paramétrées (`better-sqlite3`), donc pas d'injection SQL possible.
- **Gestion des erreurs** : aucune trace technique renvoyée au client ; erreurs journalisées côté serveur uniquement.
- **Démarrage sécurisé** : le serveur refuse de démarrer en production (`NODE_ENV=production`) si `SESSION_SECRET` ou `ADMIN_PASSWORD` sont laissés à leur valeur par défaut.

Aucun système n'est protégé « contre toutes les failles » de façon absolue — mais ces mesures couvrent les risques standards (OWASP Top 10 applicables : injection, authentification, XSS, CSRF, contrôle d'accès, mauvaise configuration).

## Stack

- Node.js / Express
- SQLite (via `better-sqlite3`) — base de données locale, aucun service externe requis
- EJS pour le rendu des pages, sessions via `express-session`
- `helmet` (en-têtes de sécurité / CSP) + `express-rate-limit` (anti brute-force)
- CSS sur-mesure, police système, design dashboard (sidebar de navigation, accent indigo, cartes blanches à ombres douces) — clair/sombre/système

## Démarrage

```bash
npm install
cp .env.example .env   # puis personnaliser ADMIN_EMAIL / ADMIN_PASSWORD / SESSION_SECRET
npm start
```

Le serveur démarre sur `http://localhost:3000` (variable `PORT`).

Au premier démarrage, un compte administrateur est créé automatiquement à partir de `ADMIN_EMAIL` / `ADMIN_PASSWORD` défini dans `.env`.

## Variables d'environnement

| Variable | Description |
| --- | --- |
| `PORT` | Port d'écoute du serveur (défaut `3000`) |
| `SESSION_SECRET` | Secret utilisé pour signer les cookies de session — obligatoire et unique en production |
| `ADMIN_EMAIL` | Email du compte administrateur créé au démarrage |
| `ADMIN_PASSWORD` | Mot de passe du compte administrateur créé au démarrage — fort et obligatoire en production |
| `NODE_ENV` | `production` active les cookies sécurisés (HTTPS obligatoire), les vérifications de secrets au démarrage et le cache statique |
| `TRUST_PROXY` | À définir (ex. `1`) uniquement si l'app tourne derrière un reverse proxy (Nginx, Render, Railway…), pour que la limitation de débit identifie la bonne IP |

## Structure

```
src/
  server.js         routes & logique métier
  db.js             connexion SQLite + schéma + désactivation auto des contrats expirés
  security.js       CSRF, rate limiting, verrouillage de compte, nonce CSP
  grades.js         liste des grades proposés
  contract-types.js liste des types de contrat proposés
  middleware/       protections des routes (admin / employé)
views/               pages EJS (connexion, admin, espace employé, édition membre/outil)
public/              CSS, thème clair/sombre/système, JS front
```
