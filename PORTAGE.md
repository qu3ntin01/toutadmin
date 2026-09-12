# État du portage vers PHP

L'édition Node compte 51 espaces (un fichier de routes chacun), 78 modules
métier et 89 gabarits — environ 48 000 lignes. Le portage avance par lots :
un lot, c'est un espace entier, ses écrans, ses règles et ses tests. Un espace
à moitié porté ne sort pas.

Le socle est commun à tout le reste : routage, base, sessions, jeton
anti-falsification, journal d'audit, plafonds, traductions, gabarits. Il est
fait, et c'est lui qui rend les lots suivants mécaniques.

## Fait

| Espace | Ce qui marche |
| --- | --- |
| Socle | base et schéma (143 tables), sessions en base, jeton CSRF, en-têtes, plafonds, journal scellé, 16 langues (2 754 clés) |
| Installation | vérification de l'hébergement, entreprise, langue, compte d'administration, fermeture automatique |
| Connexion | mot de passe, double authentification, codes de secours, verrouillage, changement de mot de passe forcé |
| Mon espace | accueil du salarié |
| Annuaire | recherche, masquage décidé par l'administration |
| Mon profil | nom, téléphone, présentation, langue ; messagerie en lecture seule |

39 tests passent (`php tests/run.php`).

## À porter

Par ordre d'utilité, quatorze lots. Chacun reprend les règles de l'édition
Node telles quelles : ce sont les mêmes décisions, pas de nouvelles.

1. **Administration** — comptes, services, équipes, modules, réglages
2. **Ressources humaines** — dossiers, contrats, congés, absences, entretiens
3. **Demandes et manager** — demandes internes, validation, points individuels
4. **Agenda, planning, salles** — calendriers, roulements, astreintes, réservations
5. **Messagerie et annonces** — messagerie interne, actualités, notifications
6. **Projets et support** — projets, jalons, rentabilité, tickets
7. **Gestion** — devis, factures, clients, fournisseurs, recouvrement
8. **Comptabilité et paie** — partie double, bulletins, déclarations
9. **Trésorerie, immobilisations, achats** — prévisionnel, amortissements, stock
10. **Coffre-fort et parapheur** — documents, signatures, accès après départ
11. **Sécurité et RGPD** — console, journal, données personnelles, sauvegardes
12. **Qualité, santé-sécurité, conformité** — audits, risques, déclarations
13. **Vie juridique, direction, CSE** — assemblées, mandats, gouvernance, sondages
14. **Informatique, développement, flotte, accueil** — parc, livraisons, véhicules, visiteurs

## Ce qui ne sera pas porté à l'identique

Trois fonctions de l'édition Node tiennent à Node lui-même, et demandent un
équivalent plutôt qu'une traduction :

- **Relève de courrier IMAP** (`imapflow`) → l'extension `imap` de PHP, ou une
  relève lancée par tâche planifiée.
- **Sauvegardes automatiques périodiques** → une tâche planifiée (`cron`) chez
  l'hébergeur : un site PHP ne tourne qu'au moment d'une requête.
- **Lecture de PDF** (`pdf-parse`) → à décider : extension, binaire externe, ou
  fonction absente de l'édition PHP.

Ces trois-là seront documentées comme telles, pas laissées dans un état
indécis.
