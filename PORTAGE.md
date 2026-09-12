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
| Administration | services, équipes, encadrement, rattachement, annuaire, personnel (création avec mot de passe temporaire, modification, activation, réinitialisation, suppression), messagerie du membre, actualités, outils, affectations, droits transverses, modules, palette et réglages de l'instance |
| Organigramme | services, équipes, rattachements, sans-rattachement ; effectif entier pour l'administration et les RH |
| Congés et paie | demande déposée par le salarié, décompte en jours ouvrés, approbation, refus, annulation avec recrédit du solde, ajustements de solde, fiches de paie |
| Espace du salarié | informations, managers, demandes, solde, fiches de paie, actualités, collègues |
| Demandes internes | types de demande et circuits configurables, seuils, validation étape par étape, refus motivé, retrait par le demandeur |
| Notifications | file personnelle, déduplication, marquage lu, purge |
| Documents, formation, entretiens | documents avec accusé de réception, catalogue et sessions de formation, inscriptions, entretiens annuels et commentaire du salarié |
| Espace manager | équipe encadrée, absences du périmètre, points individuels (note partagée et note privée), actualités de périmètre |
| Agenda | grille du mois, événements saisis, congés, salles, formations et fin de contrat posés d'eux-mêmes, agenda partagé d'équipe et de service |
| Salles | parc, réservations sans chevauchement, annulation par son auteur |

126 tests passent (`php tests/run.php`), et `php tools/check-keys.php` vérifie
qu'aucun écran n'emploie une clé de traduction absente des dictionnaires.

## À porter

Par ordre d'utilité, quatorze lots. Chacun reprend les règles de l'édition
Node telles quelles : ce sont les mêmes décisions, pas de nouvelles.

1. **Planning** — roulements, astreintes, présence
2. **Messagerie** — messagerie interne et pièces jointes
3. **Projets et support** — projets, jalons, rentabilité, tickets
4. **Gestion** — devis, factures, clients, fournisseurs, recouvrement
5. **Comptabilité et paie** — partie double, bulletins, déclarations
6. **Trésorerie, immobilisations, achats** — prévisionnel, amortissements, stock
7. **Coffre-fort et parapheur** — documents, signatures, accès après départ
8. **Sécurité et RGPD** — console, journal, données personnelles, sauvegardes
9. **Qualité, santé-sécurité, conformité** — audits, risques, déclarations
10. **Vie juridique, direction, CSE** — assemblées, mandats, gouvernance, sondages
11. **Informatique, développement, flotte, accueil** — parc, livraisons, véhicules, visiteurs

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
