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
| Messagerie | boîtes de réception et d'envoi, réponse, marque « lu » posée par le seul destinataire, suppression par ceux que le message concerne |
| Projets | projets, équipe projet, jalons, tâches en tableau, temps passé, rentabilité et écart au budget |
| Support | tickets internes et clients, délai de première réponse selon la priorité, notes internes, files par catégorie |
| Gestion | tiers (clients, fournisseurs), contrats et alerte de préavis, factures client et fournisseur avec TVA, retard déduit de l'échéance, budgets par service avec consommé, notes de frais du dépôt au remboursement, multidevise à taux figé à l'émission |
| Trésorerie | module optionnel : comptes bancaires dont le solde se recalcule des mouvements, rapprochement qui distingue émis d'encaissé et refuse un mouvement de sens contraire, prévisionnel et projection à douze semaines avec son point bas |
| Immobilisations | module optionnel : registre, amortissement linéaire et dégressif avec bascule calculée, valeur nette comptable, dotation de l'exercice, cession |
| Stock et achats | module optionnel : stock déduit des mouvements depuis le dernier inventaire, seuil d'alerte, demandes d'achat validées par le manager puis par la gestion au-delà du seuil, bons de commande, réceptions qui entrent en stock, rapprochement à trois (commandé, reçu, facturé) |
| Coffre-fort | dépôt par les RH avec contrôle du type réel du fichier, empreinte SHA-256 vérifiée à chaque téléchargement, conservation cinquante ans, retrait réservé à l'administration et motivé, codes d'accès pour les anciens salariés ouvrant une session qui ne voit que le coffre |
| Parapheur | document figé dès la mise à la signature, circuit ordonné, mot de passe et consentement redemandés, sceau HMAC par signature, refus motivé qui interrompt le circuit, attestation imprimable |
| Sécurité | console d'administration : journal paginé et filtrable avec export CSV, vérification du scellement qui dit où la chaîne casse, purge bornée, comptes à surveiller (verrouillés, mots de passe temporaires, administrateurs sans double authentification, comptes dormants), sessions ouvertes, nomination et rétrogradation des administrateurs, politique |
| Données personnelles | registre des traitements (six préremplis), export JSON de ce que vingt-cinq sources détiennent sur une personne, effacement qui distingue l'effaçable de ce que la loi impose de garder, compte anonymisé plutôt que supprimé |
| Externalisation | dépôt FTP/FTPS et Google Drive après chaque sauvegarde, secrets chiffrés en base (AES-256-GCM, clé dérivée du secret de l'instance), alerte des administrateurs quand une destination refuse, purge distante alignée sur le nombre d'archives conservées |
| Sauvegardes | archive tar.gz écrite à la main (base copiée par VACUUM INTO, coffre-fort, parapheur), empreinte par fichier vérifiée à la restauration, restauration table par table en une transaction précédée d'une sauvegarde de sécurité, purge par nombre d'archives, export intégral en JSON pour partir |
| Comptabilité | module optionnel : plan comptable et journaux posés à l'activation, écritures refusées si elles ne s'équilibrent pas, facture passée en écriture d'un clic au taux figé, balance, grand livre, résultat, export CSV |
| Paie | module optionnel : barèmes paramétrables (base brut ou plafond), calcul du brut au net, part patronale et coût employeur, bulletin détaillé, génération en lot, masse salariale du mois |
| Gestion (suite) | abonnements qui émettent leurs factures à échéance sans jamais facturer deux fois la même, déclarations de TVA par période avec ventilation par taux et crédit reportable, recouvrement par paliers (rappel, relance, mise en demeure) et balance âgée, parc matériel avec affectations et historique |

288 tests passent (`php tests/run.php`), et `php tools/check-keys.php` vérifie
qu'aucun écran n'emploie une clé de traduction absente des dictionnaires.

## À porter

Par ordre d'utilité, les lots restants. Chacun reprend les règles de l'édition
Node telles quelles : ce sont les mêmes décisions, pas de nouvelles.

1. **Qualité, santé-sécurité, conformité** — audits, risques, déclarations
2. **Vie juridique, direction, CSE** — assemblées, mandats, gouvernance, sondages
3. **Informatique, développement, flotte, accueil** — parc, livraisons, véhicules, visiteurs
4. **Recrutement** — postes, candidatures, entretiens
5. **Planning** — roulements, astreintes, présence

## Un manque de l'édition Node, comblé des deux côtés

Le rapprochement à trois compare le commandé, le reçu et le facturé. Le
rattachement d'une facture fournisseur à son bon de commande existait en
base (`invoices.purchase_order_id`) et l'écran l'annonçait, mais aucune
route ne le renseignait : le rapprochement ne pouvait rien comparer.

Les deux éditions le portent désormais, avec les mêmes règles : le champ
« Bon de commande » au formulaire de facture, le rattachement et le
détachement depuis la fiche de commande, et trois refus — une facture
client n'a pas de bon de commande, une facture d'un autre fournisseur ne
se rattache pas, une facture déjà rattachée ailleurs non plus.

## Ce qui ne sera pas porté à l'identique

Trois fonctions de l'édition Node tiennent à Node lui-même, et demandent un
équivalent plutôt qu'une traduction :

- **Relève de courrier IMAP** (`imapflow`) → l'extension `imap` de PHP, ou une
  relève lancée par tâche planifiée.
- **Sauvegardes automatiques périodiques** → fait : `tools/cron.php` est appelé
  par la tâche planifiée de l'hébergeur et déclenche la sauvegarde dès
  l'intervalle écoulé, un site PHP ne tournant qu'au moment d'une requête. La
  copie de la base passe par `VACUUM INTO`, cohérente sur une base en écriture,
  là où l'édition Node utilise l'API de sauvegarde en ligne de SQLite.
- **Lecture de PDF** (`pdf-parse`) → à décider : extension, binaire externe, ou
  fonction absente de l'édition PHP.

Ces trois-là seront documentées comme telles, pas laissées dans un état
indécis.
