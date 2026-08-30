# Private Member

Plateforme de gestion du personnel et des outils qui leur sont affectés — design luxe, bleu foncé & or.

## Fonctionnalités

- **Espace administrateur**
  - Ajout de membres du personnel avec choix d'un grade (Stagiaire, Employé, Technicien, Chef d'équipe, Responsable, Manager, Directeur…)
  - Génération automatique d'un mot de passe temporaire pour chaque nouveau membre
  - Activation / désactivation / réinitialisation de mot de passe / suppression d'un membre
  - Catalogue d'outils (nom, catégorie, référence, description)
  - Affectation d'outils aux membres du personnel, avec note et historique
- **Espace personnel**
  - Page de connexion dédiée (email + mot de passe)
  - Liste des outils actuellement affectés au salarié connecté
- **Thème clair / sombre / système** — bouton à trois positions (soleil / lune / écran) dans l'en-tête. Clair par défaut. Le choix est mémorisé dans le navigateur (`localStorage`) et appliqué sans flash au chargement.

## Sécurité

Seul un compte de rôle **administrateur** peut créer, modifier, désactiver ou supprimer un membre du personnel ou un outil (middleware `requireAdmin` sur toutes les routes `/admin/*`) ; un compte employé n'a accès qu'à ses propres outils affectés.

Mesures mises en place :

- **Mots de passe** : hachage `bcrypt` (coût 12), jamais stockés en clair. Les mots de passe temporaires générés sont aléatoires (12 caractères) et affichés une seule fois à l'admin.
- **Verrouillage de compte** : après 5 échecs de connexion consécutifs, le compte est verrouillé 15 minutes.
- **Anti-brute-force réseau** : limitation à 10 tentatives de connexion / 15 min par adresse IP, et une limite globale de 300 requêtes / minute.
- **Anti-enumération de comptes** : réponse générique et temps de réponse constant (comparaison bcrypt factice) que l'email existe ou non.
- **CSRF** : jeton unique par session, vérifié en comparaison à temps constant sur chaque formulaire POST.
- **Sessions** : cookie `httpOnly`, `sameSite=lax`, `secure` en production, régénération de l'identifiant de session à la connexion (anti session-fixation), secret dédié (`SESSION_SECRET`).
- **En-têtes de sécurité** : `helmet` avec Content-Security-Policy stricte (scripts avec nonce, pas de `unsafe-inline`), HSTS, `X-Frame-Options`, etc.
- **Validation des entrées** : grade contrôlé côté serveur contre une liste blanche (impossible de contourner le `<select>`), email validé, longueurs de champs bornées.
- **Base de données** : requêtes 100 % paramétrées (`better-sqlite3`), donc pas d'injection SQL possible.
- **Gestion des erreurs** : aucune trace technique renvoyée au client ; erreurs journalisées côté serveur uniquement.
- **Démarrage sécurisé** : le serveur refuse de démarrer en production (`NODE_ENV=production`) si `SESSION_SECRET` ou `ADMIN_PASSWORD` sont laissés à leur valeur par défaut.

Aucun système n'est protégé « contre toutes les failles » de façon absolue — mais ces mesures couvrent les risques standards (OWASP Top 10 applicables : injection, authentification, XSS, CSRF, contrôle d'accès, mauvaise configuration).

## Stack

- Node.js / Express
- SQLite (via `better-sqlite3`) — base de données locale, aucun service externe requis
- EJS pour le rendu des pages, sessions via `express-session`
- `helmet` (en-têtes de sécurité / CSP) + `express-rate-limit` (anti brute-force)
- CSS sur-mesure, police système (San Francisco / Segoe UI selon l'OS), design inspiré d'Apple : carte centrée, gris neutre, noir quasi pur, accent bronze/or unique — clair/sombre/système

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
  server.js       routes & logique métier
  db.js           connexion SQLite + schéma + création admin
  security.js     CSRF, rate limiting, verrouillage de compte, nonce CSP
  grades.js       liste des grades proposés
  middleware/     protections des routes (admin / employé)
views/            pages EJS (connexion, admin, espace employé)
public/           CSS, thème clair/sombre/système, JS front
```
