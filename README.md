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

## Stack

- Node.js / Express
- SQLite (via `better-sqlite3`) — base de données locale, aucun service externe requis
- EJS pour le rendu des pages, sessions via `express-session`
- CSS sur-mesure (police `Cormorant Garamond` + `Manrope`)

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
| `SESSION_SECRET` | Secret utilisé pour signer les cookies de session |
| `ADMIN_EMAIL` | Email du compte administrateur créé au démarrage |
| `ADMIN_PASSWORD` | Mot de passe du compte administrateur créé au démarrage |

## Structure

```
src/
  server.js       routes & logique métier
  db.js           connexion SQLite + schéma + création admin
  grades.js       liste des grades proposés
  middleware/     protections des routes (admin / employé)
views/            pages EJS (connexion, admin, espace employé)
public/           CSS & JS front
```
