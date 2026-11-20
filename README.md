# Toutadmin

CMS d'entreprise : il administre une société entière — personnel et contrats,
ressources humaines, finance et comptabilité, opérations, gouvernance, conformité
et vie juridique, moyens généraux. Interface inspirée de l'univers OVHcloud
(navigation latérale bleue, contenu dense, angles droits), disponible en 16 langues.
Node 22, Express, SQLite ou MySQL : une seule machine suffit, et aucune base externe n'est
exigée — mais un serveur MySQL est mené si vous en avez un.

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
| Service informatique | `/informatique` | Administrateurs et membres désignés au service informatique |
| Développement | `/developpement` | Administrateurs et membres désignés au service informatique |
| Événements | `/evenements` | Chacun pour ceux qui le concernent ; RH et administration pour les créer |
| Comptabilité, Paie, Facturation électronique, Stock, CRM | voir « Modules débloquables » | Modules optionnels, éteints par défaut |
| Salles | `/salles` | Tout membre connecté, pour réserver et voir le planning |

## Sections et services

Les écrans d'administration sont rangés en sept sections — pilotage, personnel,
gestion, relations, technique, conformité, réglages — dans une liste unique,
`src/sections.js`. Le rail la parcourt pour se construire, les tableaux de bord
de section pour choisir leurs tuiles. Deux copies de la même liste auraient fini
par diverger, et une rubrique qui figure dans l'une et pas dans l'autre est un
service qu'on ne retrouve plus.

Le rail n'affiche qu'une section dépliée à la fois : sur un portable,
quarante rubriques à la file tombaient sous le bord de l'écran, et les trouver
demandait de savoir déjà qu'elles existaient. Le pli tient dans le navigateur de
chaque personne — ce n'est pas un réglage de l'instance — et se pose sur `<html>`
avant le premier rendu, comme le thème. Sans JavaScript, le rail arrive entier :
plier côté serveur enfermerait derrière des groupes qu'on ne pourrait plus
ouvrir.

Chaque section ouvre sur `/section/<clé>`, un tableau de bord qui pose ses
services en tuiles, chacune avec l'icône du rail et une ligne qui dit ce que le
service permet de faire. Le rail ne montre — et ces tuiles ne proposent — que ce
que le compte connecté peut réellement ouvrir : un raccourci vers un écran
interdit est une porte peinte sur un mur. Une section dont rien n'est visible se
refuse comme une section inventée, parce qu'un « accès refusé » apprendrait
qu'elle existe.

Les onglets propres à un écran ne sont pas dans le rail mais dans une barre sous
son titre : le rail dit où l'on va, la barre dit ce qu'on regarde là où l'on est.

## Fonctionnalités

### Administration
- Création de membres avec grade, service, **type de contrat** (CDI, CDD, Intérim, Stage, Alternance, Freelance) et **date de fin optionnelle**
- Modification du profil à tout moment, activation/désactivation, réinitialisation de mot de passe, suppression
- **Désactivation automatique** du compte à l'échéance du contrat (vérifiée à la connexion, à l'ouverture du tableau de bord et toutes les heures)
- Catalogue d'outils (nom, catégorie, référence, description, **URL de connexion**), modifiable après création
- Affectation d'outils avec un **identifiant propre à chaque membre**
- Désignation des responsables RH

### Multi-sociétés (`/admin`, onglet « Sociétés »)
- Une instance porte une société ou douze. Chaque entité d'un groupe a ses tiers, ses factures, ses projets — et ne voit pas ceux des autres
- **Une personne reste une personne** : un seul compte, un seul mot de passe, et une table d'appartenance qui dit à quelles entités il donne accès. La direction d'un groupe relève souvent de toutes
- Un **sélecteur de périmètre** dans l'en-tête, affiché seulement quand il y a un choix à faire. Il dit d'abord où l'on est : une facture saisie dans la mauvaise entité ne se répare pas en la déplaçant
- **Le cloisonnement a une seule porte**, et elle est fermée par défaut : `db.prepare` est l'unique passage de toutes les requêtes du produit, et un garde y refuse celles qui touchent une table cloisonnée sans nommer le périmètre
- **Le garde ne réécrit rien, il refuse.** Glisser un `AND company_id = ?` tout seul demanderait d'analyser du SQL — sous-requêtes, unions, agrégats —, et une analyse qui se trompe une fois sur mille produit une fuite silencieuse. Un refus bruyant vaut mieux
- Les 154 tables du schéma sont **classées une par une** : cloisonnée (elle porte le périmètre), rattachée (ses lignes appartiennent à une ligne d'une autre table, et l'identifiant du parent fait droit d'accès), ou partagée — chaque partagée portant la raison pour laquelle elle en est. **Un test échoue si une table ajoutée demain n'est pas classée**
- **L'avancement a été explicite** : une table n'entrait sous garde que lorsque ses requêtes étaient reprises et que son test d'étanchéité passait — deux sociétés, et l'une ne voit rien de l'autre. Les **82 tables cloisonnées le sont aujourd'hui toutes**
- **L'unicité est par société, pas par instance** : deux entités d'un groupe ont chacune le droit d'avoir un service « Production ». SQLite ne sait pas retirer l'index implicite qu'une contrainte `UNIQUE` de colonne a créé, donc la table est reconstruite à la mise à jour — définition reprise du schéma et jamais recopiée, bascule dans une transaction, références revérifiées juste après
- Sous garde : les **actualités**, l'**organisation** (services, équipes, encadrement), la **gestion** (tiers, factures, plan comptable, journaux, écritures), les **projets**, le **support** (demandes, files) et les **ressources humaines** (absences, soldes, paie, barèmes, pointages, visites médicales, formations, entretiens, postes, parcours d'arrivée, notes de frais), le **stock et les achats** (articles, équipements, outils, commandes, demandes d'achat) et la **trésorerie** (comptes, prévisionnel, immobilisations, abonnements, devis, affaires, budgets, TVA, taux de change), le **comité social** (mandats, élections, réunions, avantages) et la **vie sociale** (capital, mandats sociaux, assemblées générales, déclarations d'intérêts, cadeaux, délégations de pouvoir, réunions de direction et leurs décisions, dispositif d'alerte), la **qualité** (non-conformités, actions correctives, audits internes), la **sécurité au travail** (document unique, registre des accidents, protections individuelles) et le **registre des traitements**, l'**informatique** (parc logiciel et accès, services applicatifs, incidents, base de connaissances, webhooks), le **parapheur**, les **moyens généraux** (salles et réservations, flotte, accueil et courrier, documents et événements d'entreprise, pièces reçues) et le reste (objectifs, enquêtes, plannings, types de demande, corbeille, champs personnalisés, jetons d'API) — **83 tables sur 83**. 105 tests, dont l'étanchéité table par table, la reconstruction éprouvée sur une base d'avant le cloisonnement, et la reprise d'une instance restée à l'ancien modèle d'organisation
- **Le droit d'accès et l'effacement ignorent le périmètre**, et c'est voulu : une personne qui travaille pour deux entités d'un groupe a un seul dossier, une demande d'accès ne se répond pas en deux fois selon le sélecteur en haut de l'écran, et effacer dans la seule société courante laisserait derrière, dans une autre entité, exactement ce qu'on vient de dire avoir effacé
- Une société se **ferme**, elle ne se supprime pas : ses écritures et ses contrats font preuve. La dernière société ouverte ne se ferme pas — une instance sans périmètre n'affiche plus rien
- Le dernier rattachement d'un salarié ne se retire pas : sans société il ne verrait plus rien, et ne pourrait pas se rattacher lui-même

### Champs personnalisés (`/admin`, onglet « Champs »)
- Huit objets décorables : salarié, ticket, projet, partenaire, facture, équipement, demande interne, événement
- Huit types contrôlés côté serveur : texte, texte long, nombre, montant, date, booléen, liste de choix, lien
- Un champ se range (ordre d'affichage), s'éteint sans perdre ses valeurs, ou se supprime — l'écran annonce alors combien de valeurs partent avec lui
- Les valeurs vivent dans leur propre table : **aucune colonne n'est ajoutée** à l'objet décoré, donc aucune migration de schéma déclenchée par un clic
- Un formulaire refusé n'écrit rien, pas même le reste de la fiche : les champs personnalisés voyagent dans le même formulaire que le grade ou le type de contrat
- Trois façons de porter les champs, selon ce que l'écran offre : dans le formulaire d'édition quand il en a un (salarié, projet, événement), dans un formulaire à eux quand il n'en a pas (ticket, fiche tiers), sous un dépli de la ligne pour ce qui vit en liste (facture, équipement)
- Une demande interne les reçoit au dépôt et les montre ensuite en lecture : elle se décide ou se retire, elle ne se réécrit pas
- Balayage des valeurs orphelines, proposé uniquement lorsqu'il y a quelque chose à balayer

### « Se connecter en tant que » (`/admin`, onglet « Personnel »)
- Voir le produit par les yeux d'un salarié, avec ses droits, ses rattachements et ses données — ce qu'aucune capture d'écran ne remplace quand « le bouton ne marche pas chez moi »
- **Le journal nomme les deux** : l'auteur consigné est l'administrateur, c'est lui qui a cliqué ; le libellé dit au nom de qui, et le détail porte l'identifiant de la personne. Y compris sur la trace automatique de chaque requête, celle qu'on n'écrit pas à la main
- **Cinq refus** : réservé à l'administration ; jamais sur un autre administrateur ; jamais en cascade ; jamais sur un compte fermé ; et une durée courte qui ne se repousse pas — un emprunt oublié se termine de lui-même et rend l'administrateur à sa console
- **Les secrets de la personne restent à elle** : coffre-fort, mot de passe, double authentification et messagerie sont fermés pendant l'emprunt. Le verrou est posé en amont des routeurs, donc il couvre aussi les pages écrites demain
- Un bandeau collant sur chaque écran, avec la sortie à portée de clic : un administrateur qui oublie qu'il voit l'écran de quelqu'un d'autre finit par écrire en son nom sans le savoir
- Le retour relit l'administrateur en base : s'il a été rétrogradé entre-temps, le retour échoue et demande une reconnexion plutôt que de rendre un droit par habitude

### Corbeille et remise en place (`/admin`, onglet « Corbeille »)
- Sept objets : projet, ticket, fiche tiers, facture, équipement, événement, **type de demande**
- Une suppression met de côté la ligne, **ses enfants en cascade** (tâches d'un projet, messages d'un ticket, contrats et contacts d'un tiers…), **les liens que la suppression a mis à nul** et les champs personnalisés — puis supprime pour de bon : aucune lecture du produit n'est modifiée, donc rien ne réapparaît par accident dans un rapport
- La remise en place repose tout cela d'un bloc. Un lien n'est rendu que s'il est **encore libre** : si la facture a été rattachée ailleurs depuis, c'est ce choix plus récent qui gagne
- Deux refus dits plutôt que devinés : l'identifiant d'origine a été repris, ou la sauvegarde est illisible
- Une suppression **qui échoue** (un type de demande portant des demandes en cours) ne laisse rien dans la corbeille
- L'écran n'affiche jamais le contenu sauvegardé, seulement le libellé, l'auteur et la date
- Conservation réglable (`/reglages`, section Conformité) ; la purge passe au balayage périodique. Zéro garde sans limite, et c'est un choix à faire exprès
- **Pas la fiche d'un salarié** : son empreinte traverse quarante-sept tables en cascade et soixante-quatre liens, et la ressusciter à moitié ferait revenir des sessions, des jetons et des documents de coffre-fort sans que personne ait choisi lesquels. Le bon remède existe déjà : désactiver le compte, qui ne perd rien

### Actions de masse (liste des tickets, liste des demandes internes)
- Cocher des lignes, puis une action : statut, priorité ou affectation pour les tickets ; approuver ou refuser pour les demandes internes
- **La même action, répétée** : la fonction à l'unité est appelée une fois par ligne, pas un `UPDATE … WHERE id IN (…)`. Clore un ticket pose sa date de clôture, approuver une absence décrémente le solde de congés et prévient les webhooks — une requête unique n'aurait rien fait de cela, et le défaut se serait vu sur un bulletin de paie trois mois plus tard
- **Le droit se vérifie ligne par ligne** : un agent n'a la charge que de certaines catégories, et cocher cinquante tickets ne lui en donne pas une de plus
- **Une ligne d'une autre société est refusée**, comme une ligne effacée — et sans dire laquelle des deux raisons l'a emportée
- **Une ligne qui échoue n'emporte pas les autres** : pas de transaction englobante. Le compte rendu dit ce qui est passé, et compte les refus par motif
- **Marche sans script** : un contrôle par action. Le script n'ajoute que « tout cocher » et le compte de la sélection
- Sélection plafonnée à **200 lignes** : au-delà, ce n'est plus une action de masse mais une migration
- Une entrée au journal pour la demande elle-même, en nombres et non en identifiants — ce qui a changé est déjà tracé ligne par ligne

### Vue kanban (liste des tickets)
- Deux vues de la même liste, avec le même filtrage : `Liste` et `Tableau`. Basculer ne perd pas le filtre posé
- Les colonnes sont les statuts, **dans l'ordre du cycle** — pas de colonnes réglables : l'ordre d'un statut est une règle du métier, pas une préférence d'affichage
- Déplacer une carte appelle **la fonction de la fiche**, celle qui pose la date de clôture ; le droit se vérifie sur cette ligne
- **Pas de glisser-déposer** : un champ de choix et un bouton. Un glisser-déposer demanderait du script pour fonctionner du tout
- Une ligne dont le statut n'est plus une colonne déclarée est montrée **hors colonnes**, pas escamotée
- Le tableau des tâches d'un projet (`/projets/:id`) est antérieur et garde sa carte, plus riche : affectation, jalon, suppression

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

### Pièces reçues : lecture automatique des factures
- **Corbeille du comptable** (`/pieces`) : les factures fournisseurs arrivent par dépôt manuel ou par relève de la boîte aux lettres, et attendent d'être validées au même endroit
- **Lecture par règles, hors ligne** : émetteur, numéro, dates, HT, TVA, TTC, taux, devise, SIRET, numéro de TVA et IBAN. Les identifiants sont vérifiés par leur **clé de contrôle** — Luhn pour le SIRET, modulo 97 pour l'IBAN : un numéro mal recopié se voit sans interroger personne
- **La cohérence prime sur la ressemblance** : HT + TVA doit faire TTC. Quand les trois montants s'accordent, la confiance monte ; quand ils se contredisent, elle tombe et l'écran le dit. Un montant manquant est reconstitué à partir des deux autres, et la note l'explique
- **Le tiers est reconnu** par son identifiant s'il est déjà enregistré, à défaut par son nom — la facture arrive déjà rapprochée
- **Rien n'entre en comptabilité sans un clic** : l'analyse pré-remplit le formulaire de facture, le comptable corrige et valide. Une lecture automatique qui écrit directement dans les comptes est une erreur qu'on découvre au bilan
- **La pièce reste le justificatif** : fichier conservé tel quel, scellé par son empreinte SHA-256, revérifiée à chaque consultation ; une pièce qui a donné lieu à une facture ne se supprime plus. Le même fichier reçu deux fois ne fait qu'une ligne

### Analyse assistée par un modèle (optionnelle, éteinte par défaut)
*Le réglage a quitté cet écran : il vit à `/integrations`, onglet « Assistance IA », avec le reste de ce qui parle à l'extérieur. Voir « Assistance par modèle de langage » plus bas.*
- Un modèle de langage peut venir **par-dessus** les règles, pour les documents qu'elles lisent mal : mises en page inhabituelles, factures étrangères, émetteurs inconnus
- **Deux familles de services** : l'API Claude d'Anthropic (par son SDK officiel, sortie contrainte par un schéma JSON) et tout service **compatible OpenAI** — Mistral, OVHcloud, Scaleway, ou un modèle hébergé sur votre propre réseau, auquel cas rien ne sort de l'entreprise
- **C'est un choix explicite** : rien n'est envoyé tant qu'un administrateur n'a pas activé la fonction, et l'écran dit en toutes lettres ce qui part. La clé d'accès est chiffrée en base
- **Le modèle ne décide de rien.** Ce que les règles savent vérifier leur reste acquis : un SIRET ou un IBAN validés par leur clé ne sont pas remplacés, et un triplet de montants cohérent l'emporte sur un triplet qui ne l'est pas — d'où qu'il vienne. Chaque champ affiche son origine, règles ou modèle
- **Une panne du service ne casse rien** : la lecture par règles reste acquise, et l'écran signale que l'analyse assistée n'a pas répondu

### Capture de la boîte aux lettres comptable
- **Relève IMAP** d'une adresse dédiée (`factures@…`) : les pièces jointes PDF et DOCX des messages **non lus**, dans une fenêtre de jours bornée, entrent dans la corbeille du comptable
- Automatique à chaque balayage horaire, ou à la demande depuis l'écran, avec un **test de connexion** qui ouvre le dossier et compte ce qui attend
- **Aucun message n'est supprimé** : la boîte reste la source, le CMS n'en est qu'un lecteur. Le message est marqué lu — sinon un courriel sans pièce jointe reviendrait à chaque relève — et peut être rangé dans un dossier
- Le mot de passe est **chiffré en base**, la connexion chiffrée par défaut, et un certificat auto-signé n'est accepté que si on le demande

### Devises, abonnements et TVA
- **Multidevise** : chaque facture porte sa devise et **le taux figé le jour de son émission**. Le taux vit dans la pièce, pas dans la table des taux — mettre un taux à jour aujourd'hui ne réécrit pas le chiffre d'affaires de l'an dernier
- Les totaux sont exprimés dans la **devise de tenue des comptes** (réglable par l'administration seule) : additionner des euros et des dollars ne veut rien dire. Comptabilité, trésorerie et pilotage convertissent au taux de la pièce
- **Une devise sans taux connu n'est pas proposée** à la facturation : mieux vaut refuser que convertir au petit bonheur
- **Facturation récurrente** : l'abonnement est le moule, la facture est la pièce. Périodicité mensuelle à annuelle, délai de paiement, terme facultatif. Rien n'est émis d'avance ; les échéances atteintes partent au balayage horaire, et un **index unique interdit de facturer deux fois la même échéance**, même si deux balayages se croisent
- Un abonnement dont le terme est passé **s'éteint de lui-même** plutôt que de facturer au-delà de ce qui a été signé ; le supprimer laisse les factures déjà émises, qui restent dues
- **Déclarations de TVA** : collectée sur les factures client, déductible sur les factures fournisseur, ventilée par taux, sur les débits. Les brouillons et les factures annulées sont ignorés, les factures en devise converties au taux figé
- **La période se choisit dans une liste** (mensuelle ou trimestrielle) plutôt que de se saisir : trois jours de décalage feraient une déclaration fausse que personne ne verrait passer. Une TVA négative est rendue comme **crédit reportable**, pas comme dette
- **Une déclaration déposée ne se recalcule plus** : c'est une pièce, pas un tableau de bord. Le dépôt à l'administration reste l'affaire d'un tiers déclarant — aucune télétransmission n'est faite

### Achats : bons de commande, réceptions, rapprochement à trois
- **Le bon de commande engage, la réception constate, la facture réclame.** Trois chiffres doivent s'accorder ; quand ils divergent, on paie soit ce qu'on n'a pas commandé, soit ce qui n'est jamais arrivé — et on ne s'en aperçoit qu'à l'inventaire ou au bilan
- **Recevoir plus que commandé est refusé** : c'est presque toujours une erreur de saisie, et quand ce n'en est pas une, c'est la commande qu'il faut corriger pour que le rapprochement garde un sens
- **Une ligne rattachée à un article entre en stock au moment de sa réception**, dans le même geste. Ressaisir la même réception deux fois est le meilleur moyen de ne plus savoir ce qu'on a
- **Le statut suit les réceptions** et ne se tient pas à la main : *Reçue partiellement* à la première, *Reçue* quand toutes les lignes sont servies
- **Le rapprochement ne bloque pas le règlement** — il n'en a pas le pouvoir, et une livraison partielle facturée d'avance est parfois convenue. Il nomme l'écart au moment où quelqu'un regarde la facture, ce qui suffit à décider

### Recouvrement : relances clients échelonnées
- **Balance âgée** : l'encours réparti en non échu, 1–30, 31–60, 61–90 et plus de 90 jours. Ce n'est pas le total qui inquiète, c'est sa dérive vers la droite
- **Le palier se déduit de ce qui a déjà été envoyé**, pas du seul retard : une facture en retard de deux mois à qui l'on n'a jamais rien écrit appelle un rappel (7 jours), puis une relance (21 jours), puis une mise en demeure (45 jours) — jamais la mise en demeure d'emblée
- **Le délai du palier suivant se compte depuis la relance précédente**, pas depuis l'échéance : c'est le silence qui appelle l'escalade, et le client garde le temps de répondre au palier précédent
- **Chaque relance est consignée** avec son niveau et sa date ; une facture payée ou annulée sort de la liste d'elle-même

### Paiements SEPA : virements fournisseurs et prélèvements clients
- **Le lot de virements (pain.001.001.03)** règle d'un coup les factures fournisseurs émises : le fichier se dépose dans l'espace de la banque, qui exécute tout. Une facture sans IBAN valide, hors euros ou sans tiers est montrée avec sa raison et ne peut pas être cochée
- **Le lot de prélèvements (pain.008.001.02, schéma CORE)** encaisse les factures des clients qui ont signé un **mandat** : référence unique (RUM) par société, date de signature, IBAN contrôlé. Le premier prélèvement d'un mandat part en « FRST », les suivants en « RCUR » ; la date d'échéance est au plus tôt le lendemain
- **IBAN, BIC et identifiant créancier (ICS) sont contrôlés par leur clé** à l'enregistrement : une coquille de recopie est refusée avant de faire rejeter un lot entier
- **Les libellés sont ramenés au jeu de caractères SEPA** (sans accent, « & » devient « + ») : une banque rejette un fichier pour un seul caractère hors norme
- **Rien n'est marqué payé à la production du fichier** : c'est le relevé, importé puis rapproché, qui dit ce qui est passé
- À l'écran, l'IBAN d'un mandat n'apparaît que par ses quatre derniers caractères

### Projets, tâches et temps passé
- **Projets** rattachés à un client, un service, une équipe et un responsable, avec budget et taux horaire
- **Jalons** datés, marqués atteints d'un geste
- **Tâches** en colonnes par statut, avec priorité, estimation, échéance et affectation. Terminer une tâche pose sa date de fin ; la rouvrir l'efface
- **Temps passé** imputé au projet et, s'il y a lieu, à la tâche : au plus 24 heures par saisie, jamais une date à venir
- **Rentabilité** : heures, coût au taux horaire, marge restante et part du budget consommée
- Un projet n'est ouvert qu'à **son équipe**, son responsable et la gestion ; chacun n'efface que ses propres saisies
- **Trois cercles, pas deux** : la gestion conduit tous les projets, un manager peut en ouvrir mais ne conduit que ceux dont il est responsable — encadrer une équipe ne donne aucun droit sur le projet d'une autre. Sans responsable désigné, c'est celui qui ouvre le projet

### Service informatique : parc logiciel et accès applicatifs
- **Un logiciel n'est pas un équipement** : pas de numéro de série, mais des sièges qu'on paie, un renouvellement qui tombe et une liste de gens qui entrent dedans. Éditeur, criticité, coût par siège annualisé selon la périodicité, mention du traitement de données personnelles
- **Les sièges sont une contrainte, pas une indication** : attribuer au-delà de ce qui est payé est refusé, et réduire les sièges sous le nombre d'accès ouverts aussi. Un défaut de licence ne se découvre qu'à l'audit de l'éditeur, jamais avant
- **Revue des accès** : un compte fermé qui garde un accès applicatif remonte en premier — c'est la faille la plus banale, le départ ayant été traité côté RH et jamais côté informatique. Viennent ensuite les droits d'administration, puis ce qui n'a pas été réexaminé depuis un an. Un accès révoqué n'est pas effacé : la date de révocation est précisément ce qui prouve la fermeture
- **Incidents du SI** horodatés au début et au rétablissement, d'où sort un **délai moyen de rétablissement** calculé sur les incidents réellement rétablis. Clore sans heure de rétablissement est refusé : sinon l'indicateur ne mesure que les bons jours
- **Le rôle est distinct de la gestion financière** : elle paie les abonnements, elle n'ouvre pas les habilitations qu'ils accordent

### Développement : services applicatifs et livraisons
- **Référentiel applicatif** : ce qui tourne et qui en répond — criticité, responsable, technologies, dépôt de code et documentation (adresses vérifiées : ni `javascript:`, ni `data:`), rattachement au projet
- **Registre des livraisons** par environnement (développement, recette, préproduction, production), avec le contenu de version et le lien vers l'incident qu'une livraison a provoqué
- **Indicateurs** : fréquence de livraison, **taux d'échec comptant les livraisons retirées autant que les échouées** — une livraison annulée en catastrophe a coûté autant qu'une panne — et délai de rétablissement repris des incidents, seule source de vérité pour cette durée
- La version affichée « en production » est la dernière **livrée**, pas la dernière saisie

### Événements d'entreprise
- **Séminaires, formations, réunions générales, ateliers, salons**, à portée entreprise, service ou équipe — le même modèle que les actualités
- **La capacité tient toute seule** : au-delà des places on n'est pas refusé, on entre en liste d'attente ; un désistement fait monter le premier qui attend, avec une notification. Le statut « Complet » se pose et se retire de lui-même
- **Un brouillon n'existe pour personne**, y compris pour ceux qu'il concernera. Réduire la capacité sous le nombre d'inscrits est refusé, faute de quoi le logiciel déciderait en silence qui reste dehors. Annuler prévient les inscrits
- **La liste nominative reste à l'organisateur** : l'annuaire laisse chacun s'y retirer, un émargement ne doit pas contourner ce choix

### Points individuels manager–collaborateur
- **Deux comptes rendus** : le résumé partagé, que le collaborateur lit depuis son espace, et les notes du manager, qui ne quittent pas son écran
- **Le périmètre est vérifié à l'écriture**, pas seulement à l'affichage : un manager qui n'encadre plus la personne ne peut plus toucher au compte rendu, sans attendre de reconnexion
- **Cadence visible** : dernier point tenu et prochain prévu par collaborateur, ceux qu'on n'a jamais vus en tête-à-tête en premier
- Les notes du manager **figurent dans l'export RGPD** : elles parlent de la personne, et une demande d'accès les lui rend. L'écran le dit au manager au moment où il écrit

### Fiche tiers : interlocuteurs, conformité, évaluation
- **Une fiche par partenaire** rassemblant contrats, factures, interlocuteurs (un seul principal à la fois), pièces de conformité et évaluations
- **Les pièces sont des échéances, pas des pièces jointes** : une attestation de vigilance périmée engage la responsabilité du donneur d'ordre. Attestation, assurance, Kbis, coordonnées bancaires, certification — datées, signalées quarante-cinq jours avant leur terme et versées au moteur d'échéances
- **Évaluations notées** sur la qualité, les délais et le prix. La note affichée est celle de la dernière revue, pas la moyenne de l'historique : une moyenne lisserait exactement ce qu'on cherche à voir, une dégradation. Et seule la dernière évaluation porte une échéance de revue

### Support et base de connaissances
- **Tickets** internes et clients dans un même circuit, avec **délai de première réponse déduit de la priorité** (2 h à 72 h). Changer la priorité recalcule le délai depuis l'ouverture, pas depuis maintenant
- **Notes internes** invisibles du demandeur, et qui ne comptent pas comme première réponse
- **Cloisonnement par catégorie** : une demande « Ressources humaines » parle de paie, de contrat, parfois de santé. La gestion et les managers traitent tout le reste, et n'ouvrent jamais celles-là
- **Groupes de support composés par l'administration** (`/support/groupes`) : un groupe réunit des personnes et des catégories. Qui traite quoi cesse d'être écrit dans le code
- **Un groupe ajoute un accès, il n'en retire jamais.** Composer le premier groupe ne ferme la file à personne, et une catégorie sans groupe reste traitée par les rôles, comme avant. C'est ce qui rend l'écran sans danger : on ne peut pas s'enfermer dehors
- **La file est posée à l'ouverture, pas calculée à la lecture** : une demande déposée aujourd'hui garde sa file même si les catégories d'un groupe changent demain. Elle se transfère à la main d'un groupe à l'autre, et la liste se filtre par file
- **Une carte des accès** répond à la question qu'une administration pose vraiment — « si cette personne part demain, qui lit ces demandes ? » : par catégorie, ce que les rôles ouvrent d'eux-mêmes, les groupes qui la prennent en charge, et le nombre de personnes actives nommées dans ces groupes
- **Base de connaissances** par catégorie, avec recherche plein texte et portée entreprise, service, équipe ou administration

### Assistance de l'éditeur, depuis votre propre instance
- **Un onglet de l'écran « Licence »** (`/licence`, administration) ouvre une demande auprès de l'éditeur du logiciel : panne, question, anomalie
- **Aucun second compte à tenir** : l'instance signe avec la clé de licence qu'elle détient déjà. Le mot de passe de votre espace client ne transite jamais par le CMS, et rien ne crée de compte depuis l'extérieur
- **Deux systèmes qui ne se touchent pas** : le support interne ci-dessus est le vôtre, celui-ci est celui de l'éditeur. Une demande écrite ici n'apparaît jamais dans la file de vos agents, et un ticket interne ne part jamais chez l'éditeur
- **Rien ne part sur un simple affichage** : l'écran ne relève les réponses que lorsqu'on le demande, et une instance ne lit que ce qu'elle a elle-même écrit

### Moyens généraux
- **Flotte de véhicules** : affectation, kilométrage qui ne recule jamais, historique des entretiens, réparations, sinistres et carburant, échéances d'assurance, de contrôle technique et d'entretien

### Coffre-fort numérique
- **Les bulletins restent accessibles après le départ.** Un salarié parti se connecte comme avant : son compte est fermé, mais l'identifiant et le mot de passe ouvrent **son coffre-fort, et rien d'autre** — aucune autre page n'est atteignable et aucune écriture n'est possible
- **Code d'accès** émis par les RH pour qui a oublié son mot de passe, ce qui arrivera sur cinquante ans : adresse + code sur `/coffre-fort/acces`. Émettre un code révoque le précédent, la validité est réglable et le code est révocable à tout moment
- **Scellé par son empreinte** : chaque document porte le SHA-256 de son contenu, recalculé **à chaque téléchargement**. Un fichier altéré ou disparu n'est pas servi, et l'anomalie est consignée au journal d'audit
- **Conservation de 50 ans** calculée au dépôt et affichée sur chaque ligne
- **Un retrait laisse sa trace** : la ligne reste au coffre avec son auteur et son motif. Le retrait est réservé à l'administration ; les RH déposent
- Bulletins, contrats, avenants, certificats de travail, attestations, soldes de tout compte — PDF, DOCX, PNG ou JPEG, 10 Mo maximum, type vérifié à la signature
- **Contrôle d'intégrité** de tout le coffre depuis la console RH, en un écran
- L'historique des paies est affiché à côté, avec l'indication des périodes dont le bulletin n'a pas encore été déposé

### Cycle de vie du salarié
- **Documents d'entreprise** : règlement intérieur, politiques, procédures, avec **accusé de réception exigible**. Un document non lu reste signalé dans l'espace du salarié
- **Formation** : catalogue, sessions datées avec places limitées, demande par le salarié puis confirmation RH. Une session pleine refuse toute inscription de plus, et une inscription confirmée apparaît dans l'agenda
- **Entretiens annuels** : planification, compte-rendu (points forts, axes de progrès, objectifs, appréciation de 1 à 5), et **commentaire du salarié** sur son seul entretien
- **Recrutement** : postes ouverts rattachés à un service et une équipe, candidatures suivies par étapes (reçue → présélection → entretien → offre → recruté / refusé). Un poste pourvu n'accepte plus de candidature

### CV et filtrage ATS
- **Dépôt de CV** par candidature : PDF, DOCX, TXT ou Markdown, 5 Mo maximum. Le texte en est extrait à la réception (un PDF scanné, lui, ne contient aucun texte : le dépôt est accepté mais l'absence d'extraction est annoncée, ce module ne fait pas de reconnaissance de caractères)
- **Critères par poste**, chacun avec un intitulé, ses synonymes, un poids de 1 à 5 et une nature *Requis* ou *Souhaité*. Le rapprochement se fait sans tenir compte des accents, de la casse ni de la ponctuation, mais **en frontière de mot** : « java » ne se déclenche pas sur « javascript », ni « api » sur « rapide »
- **Score pondéré sur 100**, seuil de retenue et **expérience minimale** réglables poste par poste. Les années d'expérience sont lues dans le CV quand la phrase est explicite (« 7 ans d'expérience »), et peuvent être saisies à la main sinon
- Une candidature est **retenue** si elle a un CV, atteint le seuil, ne manque aucun critère requis et satisfait l'expérience minimale. Le détail est affiché critère par critère, avec le terme effectivement trouvé
- **Classement automatique** des candidatures d'un poste, réévalué à chaque dépôt de CV et à chaque modification des critères ou du seuil
- **CVthèque** : recherche plein texte dans tous les CV reçus, tous postes confondus, classée par nombre de termes trouvés
- **Le score aide à trier, il ne décide de rien** : un dossier écarté par le filtre reste consultable et son CV téléchargeable. Aucune candidature n'est refusée automatiquement
- Les CV sont des **données personnelles** : ils sont stockés hors du dépôt, en `0600`, jamais servis en statique — seule une route authentifiée réservée aux RH les délivre — et leur suppression efface le fichier *et* le texte extrait

### Arrivées, départs et compétences
- **Modèles de parcours** dont chaque point porte un écart au jour pivot (« J−2 : préparer le poste »), appliqués à une personne pour donner une liste datée
- Cocher le dernier point clôt le parcours ; en décocher un le rouvre. Supprimer un modèle laisse en place les parcours lancés
- **Matrice des compétences** : niveau, date d'obtention, échéance déduite de la durée de validité déclarée
- Une habilitation **obligatoire** absente ou périmée remonte jusqu'à régularisation

### Santé et sécurité au travail
- **Document unique** : unités de travail, dangers, cotation gravité × probabilité, mesures de prévention, dates de revue. Au-delà du seuil, le risque appelle une action
- **Registre des accidents** avec taux de fréquence et de gravité calculés sur douze mois
- **Équipements de protection** : catalogue, remises, péremptions et restitutions
- **Visites médicales** et leurs échéances — seul l'avis d'aptitude est consigné, aucune donnée de santé
- Registre interne : il ne remplace ni la déclaration à la caisse d'assurance maladie, ni l'avis du médecin du travail

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
- **Messagerie interne** : boîte de réception, envoi, réponse, compteur de non-lus, suppression. L'**adresse professionnelle et les serveurs IMAP/SMTP sont configurés par l'administration** et affichés en lecture seule au membre, dont la boîte est relevée en lecture seule dans son espace

### Direction et gouvernance
- **Réunions** (`/direction`, administration) : convocation avec ordre du jour, participants conviés et **pointage des présences** (attendu, présent, excusé, absent), compte rendu qui marque la réunion tenue
- **Registre des décisions** : intitulé, contenu, **motif** — c'est ce qu'on cherche deux ans plus tard —, portée, état et date de réexamen. Une décision peut naître **hors réunion** : le rattachement est facultatif, sinon la moitié des arbitrages n'entrerait jamais au registre. **Supprimer une réunion n'efface pas ses décisions**
- **Actions confiées** avec porteur et échéance, rattachées à leur décision d'origine ; elles rejoignent les échéances de l'entreprise et alertent leur porteur
- **Registre des risques de l'entreprise**, distinct du document unique qui ne traite que la santé des personnes : catégorie, cotation probabilité × impact, **cotation résiduelle** (ce qu'il reste une fois le traitement en place), traitement retenu, porteur, plan d'action et date de revue
- **Matrice 5 × 5** colorée par criticité. Tant que le résiduel n'est pas coté, c'est la cotation brute qui est retenue : ne pas coter ne doit pas faire passer un risque pour traité

### Vie juridique : capital, mandats, assemblées
- **Le registre du capital** (`/juridique`, administration) : associés personnes physiques ou morales, et **détention déduite des mouvements de titres**, jamais saisie. Comme un solde se recalcule de ses écritures : personne ne corrige le capital sans laisser la ligne qui l'explique
- **Une cession écrit ses deux jambes dans la même transaction** — les titres qui partent de chez l'un et ceux qui arrivent chez l'autre — ou n'en écrit aucune. Céder plus qu'on ne détient est refusé en disant combien on détient, et un associé qui porte encore des titres ne se supprime pas
- **Mandats sociaux** : président, directeur général, gérant, membre du conseil, commissaire aux comptes, avec nomination, durée et révocation. Une fin de mandat est signalée à l'avance aux administrateurs — un mandat échu que l'on continue d'exercer engage la société sur des actes que personne n'avait le pouvoir de signer
- **Assemblées générales** : convocation, **quorum compté en titres et non en associés**, résolutions, votes et procès-verbal. La majorité se calcule sur les voix exprimées, les abstentions écartées du dénominateur
- **La référence de l'assemblée est attribuée par le registre** (`AG-2025-001`), pas saisie par l'utilisateur

### Conformité : intérêts, cadeaux, délégations
- **Déclarations d'intérêts** : chacun déclare pour soi — intérêt financier, mandat externe, lien familial, activité accessoire — et l'écran ne montre à un membre que les siennes. L'identifiant de l'auteur vient de la session, jamais du formulaire
- **Un intérêt déclaré n'est pas un reproche** : c'est ce qui permet de l'écarter d'une décision au bon moment. L'administration examine et note la mesure prise
- **Cadeaux et invitations** reçus comme offerts, avec leur valeur. **Au-delà de 150 €, un examen est demandé** ; le seuil est un usage, pas une règle de droit, et mieux vaut l'écrire que le supposer
- **Délégations de pouvoir** : objet, plafond, durée. Sans écrit, c'est le dirigeant qui reste seul engagé ; l'échéance d'une délégation rejoint les alertes des administrateurs
- Intérêts et cadeaux **figurent dans l'export RGPD** de la personne, et n'en sont pas effaçables : un registre de conformité amputé ne prouve plus rien

### Sondages et baromètre social
- **Questionnaires** à échelle, oui/non, choix multiple ou réponse libre, adressés à toute l'entreprise, à un service ou à une équipe
- **L'anonymat tient à la structure des tables, pas à une promesse** : les réponses ne portent aucun identifiant de personne, et la participation — nominative — ne dit que « a répondu ». Une base saisie ne peut pas rendre ce qu'elle ne contient pas ; le journal d'audit non plus
- **Sous cinq réponses, aucun résultat n'est affiché** : dans un petit groupe, une moyenne suffit à désigner quelqu'un
- **Un sondage ouvert ne se modifie plus** — des questions changées en cours de route rendraient les réponses incomparables. Résultats agrégés, verbatims, taux de participation et **indice du baromètre** suivi d'un sondage à l'autre

### Planning, roulements et astreintes
- **Grille de la semaine** (`/planning`) par personne et par jour, avec postes, astreintes, permanences, télétravail et formations
- **Les contradictions sont refusées à la création** : un créneau qui chevauche un autre poste, ou qui tombe sur une **absence déjà accordée**, est rejeté — plus tard, ce sont déjà des absences
- **Roulements** réutilisables (horaires, jours de la semaine, lieu) applicables sur une période ; les jours en conflit sont **sautés et rendus avec leur motif**. Une heure de fin plus petite que l'heure de début décrit un **poste de nuit**, qui se termine le lendemain
- **Publication de la semaine** : un planning non publié reste un brouillon, signalé en pointillé, sur lequel personne ne doit organiser sa semaine
- **Qui est d'astreinte à cet instant**, avec son numéro — la question qu'on pose à 3 h du matin — et **charge par personne** sur la période. Un manager ne planifie que son périmètre ; un salarié consulte son planning sans voir celui des autres

### Qualité
- **Non-conformités** (`/qualite`, encadrement et administration) : origine, gravité, objet, action immédiate, **cause racine**, coût, avec une référence lisible et séquentielle (`NC-2026-001`)
- **Actions correctives, préventives et d'amélioration** avec responsable et échéance, puis **vérification d'efficacité** : une action « faite » dont personne n'a mesuré l'effet laisse le même écart revenir. Le tableau de bord compte séparément ce qui est fait et ce qui est efficace
- **Une non-conformité ne se clôture pas tant que ses actions sont en cours** : une fermeture avec du travail en cours est une fermeture de façade
- **Audits internes** : périmètre, référentiel, auditeur, constats (non-conformité, remarque, point fort) et synthèse. Un constat de non-conformité **se transforme en non-conformité** avec sa propre référence — un constat qui reste un constat ne sert à rien

### Accueil : visiteurs et courrier
- **Registre des visiteurs** avec heure d'arrivée et de départ, société, personne visitée et badge remis. La liste **« dans les murs »** répond à la seule question qui compte en cas d'évacuation, ce qu'aucune liste de salariés ne sait faire
- **Courrier entrant et sortant** : nature (dont recommandé avec AR), correspondant, destinataire interne, numéro de suivi. Un pli reste **« à remettre »** tant que personne n'a signé la remise, qui est datée et porte le nom de celui qui l'a faite

### Organigramme
- **Vue d'ensemble** (`/organigramme`, ouverte à tous) : services, équipes qu'ils contiennent, encadrants de chaque périmètre et personnes rattachées
- Trois choses qu'un organigramme dit et que des listes séparées ne disent pas : **qui encadre quoi**, **où se trouve chacun**, et **qui n'est rattaché nulle part** — c'est ce dernier point qu'on découvre en le dessinant. Les équipes sans service et les services sans équipe apparaissent aussi : un rattachement oublié se voit plutôt que de disparaître
- La visibilité suit exactement celle de l'annuaire : un membre que l'administration en a retiré n'apparaît que pour l'administration et les RH, qui doivent voir l'effectif entier

### Import de données en masse
- **Reprise d'un tableur** (`/import`) : membres du personnel, clients et fournisseurs, articles de stock, contacts commerciaux — chaque type ouvert selon les droits de la personne et les modules activés
- **Rien n'est écrit avant d'avoir tout vérifié** : l'aperçu contrôle chaque ligne, nomme le problème et son numéro de ligne, et ne touche pas la base
- **Tout ou rien** : un fichier contenant une seule ligne fautive n'est pas importé du tout — un import à moitié fait est plus long à rattraper qu'un fichier à corriger. Le contrôle est refait au moment d'écrire, car la base a pu changer entre l'aperçu et la confirmation
- **L'import crée, il ne met jamais à jour** : un doublon est signalé, jamais silencieusement fondu dans l'existant. Le fichier est aussi vérifié contre lui-même — deux lignes portant la même adresse se voient avant l'écriture
- **Lecteur CSV écrit à la main** : point-virgule, virgule ou tabulation reconnus seuls, guillemets et retours à la ligne dans un champ, marque d'ordre des octets d'Excel retirée, en-têtes normalisés (« Prénom », « prenom » et « PRENOM » désignent la même colonne). Un import de masse est une porte d'entrée dans la base : la dépendance qui lit le fichier serait aussi sensible que celle qui l'écrit
- Les **mots de passe temporaires** des comptes créés sont affichés une seule fois, à l'écran, et ne sont stockés nulle part en clair — pas même dans le journal d'audit, qui retient le nombre de lignes et rien de leur contenu

### Parapheur : signature électronique
- **Circuit de signature** (`/parapheur`) pour les contrats, avenants, accords internes et procès-verbaux : document PDF, DOCX ou texte saisi, signataires ordonnés, échéance
- **Le document est figé dès son dépôt** : son empreinte SHA-256 est calculée là, revérifiée avant chaque signature et à chaque téléchargement. Signer un document qui peut changer ensuite ne signifierait rien — un fichier modifié sur le disque n'est plus servi et bloque toute nouvelle signature
- **Chaque signature porte qui, quand, depuis quelle adresse**, et un **sceau** : un HMAC-SHA256 de l'empreinte du document, du signataire et de l'horodatage, calculé avec une clé propre à l'instance qui vit hors de la base. Une ligne écrite à la main dans la base ne passe pas la vérification
- **Le signataire prouve sa présence** en ressaisissant son mot de passe et consent explicitement : une session ouverte sur un poste laissé sans surveillance ne suffit pas à engager quelqu'un
- **Le parapheur circule** : chacun signe à son tour, et chacun est prévenu quand vient le sien. Un refus se motive et interrompt le circuit
- **Attestation de signature** imprimable : empreinte, sceaux recalculés à l'ouverture, horodatages, adresses. Un document déjà signé ne se supprime pas — il fait preuve
- Signature électronique **simple**, assumée comme telle : la valeur probante repose sur le faisceau (empreinte, sceau, horodatage, journal d'audit). Une signature *qualifiée* au sens eIDAS demande un prestataire de confiance certifié, hors périmètre — c'est écrit sur l'attestation elle-même

### API de lecture et webhooks
- **API REST versionnée** (`/api/v1`), volontairement **en lecture seule** : un jeton circule dans des fichiers de configuration, des variables d'environnement, parfois un dépôt Git — il ne doit pas pouvoir supprimer un salarié ni émettre une facture
- **Jetons à portée explicite** (annuaire, RH, gestion, projets, pilotage) et à durée de vie bornée, révocables d'un clic. Le jeton n'est **jamais conservé en clair** : seule son empreinte SHA-256 vit en base, avec le préfixe qui permet de le reconnaître. Il s'affiche une fois, à sa création
- Pas de cookie, donc pas de session, donc pas de jeton CSRF : l'authentification tient dans l'en-tête `Authorization: Bearer`. Débit limité, réponses jamais mises en cache, erreurs en JSON
- **L'API respecte les règles de l'écran** : qui est retiré de l'annuaire n'en sort pas par une autre porte, et le motif d'une absence ne quitte pas l'entreprise
- **Webhooks sortants** sur les événements qui comptent (facture créée ou payée, absence approuvée, arrivée ou départ, document signé, ticket ouvert, externalisation en échec)
- Chaque envoi est **signé** (HMAC-SHA256 du corps avec le secret du webhook, en-tête `x-toutadmin-signature`) : c'est ce qui distingue un appel venu d'ici d'un appel forgé. Le secret est chiffré en base et affiché une seule fois
- **Une adresse interne ou en clair est refusée par défaut** : faire émettre des requêtes à un serveur vers son propre réseau est une porte dérobée classique. L'autoriser se fait sciemment, case cochée
- **Un échec n'est pas silencieux** : journalisé, réessayé cinq fois avec un délai qui double, puis abandonné. Après vingt échecs consécutifs le webhook s'éteint de lui-même — mieux vaut un tuyau éteint, qui se voit, qu'un tuyau muet qui fait croire que l'information passe

### Assistance par modèle de langage (`/integrations`, onglet « Assistance IA »)
- **Un seul service pour tout le logiciel** : la comptabilité, le support, la direction et la rédaction y puisent. Le CMS fonctionne **entièrement sans lui**, et il est **éteint à la livraison**
- **Deux familles de services** : l'API Claude d'Anthropic et tout service **compatible OpenAI** — Mistral, OVHcloud, Scaleway, ou un modèle hébergé sur votre propre réseau, auquel cas rien ne sort de l'entreprise. La clé d'accès est chiffrée en base
- **Un interrupteur par fonction**, et une fonction absente des réglages veut dire **éteinte** : lecture des factures reçues, résumé d'un fil, décisions et actions depuis des notes, brouillon de réponse. Rien ne part tant que le service n'est pas actif **et** que la fonction n'est pas cochée
- **L'écran dit ce qui part**, fonction par fonction, avant qu'on ne coche. Une porte de confidentialité est le seul endroit qui retire ce qui ne doit pas sortir — coordonnées bancaires, numéro de sécurité sociale, clés et jetons — et le socle ne sait pas envoyer autrement
- **Le modèle propose, il n'écrit jamais.** Chaque ligne rendue est cochée par quelqu'un avant d'entrer en base, et le propriétaire d'une action est revérifié côté serveur : un modèle ne peut pas attribuer une tâche à quelqu'un que celui qui valide n'a pas le droit de désigner
- **Le journal garde la trace de chaque envoi sans en recopier le contenu** : on peut prouver qu'un appel a eu lieu sans constituer, dans sa propre base, une seconde copie de ce qu'on voulait justement protéger
- **Une panne du service ne casse rien** : ce que les règles savent faire leur reste acquis, et l'écran signale que l'assistance n'a pas répondu

### Demandes internes et circuits d'approbation
- **Types de demande paramétrables** (`/demandes`) : l'administration décrit les champs à saisir (texte, montant, date, liste de choix) et le circuit qui les valide — déplacement, matériel, avance, télétravail, formation, ce que l'entreprise voudra
- **Une étape désigne une fonction, pas une personne** (le manager du demandeur, les RH, la gestion, la direction, ou quelqu'un de nommé) : le circuit survit aux départs
- **Seuils** : une demande de 40 € et une demande de 40 000 € ne méritent pas le même nombre de signatures. Au-dessous du seuil, l'étape ne s'applique pas
- **Une étape sans validateur possible est sautée** plutôt que de bloquer la demande sur quelqu'un qui n'existe pas — un salarié sans manager, ou une étape dont le demandeur serait le seul validateur. Personne ne valide sa propre demande
- La demande avance étape par étape, chaque validateur est prévenu à son tour, **un refus se motive** et referme le circuit. Le demandeur retire sa demande tant que personne ne s'est prononcé
- Les circuits déjà écrits ailleurs — congés, notes de frais, demandes d'achat — restent tels quels : la loi et la comptabilité les fixent

### Export intégral
- **Emporter toutes les données** (`/sauvegardes`, onglet Export) : une table par fichier JSON, les documents joints, un manifeste avec les empreintes, et un mode d'emploi
- C'est la **réversibilité** : une sauvegarde sert à revenir dans ce logiciel, un export sert à s'en aller. Le format ne suppose ni SQLite ni ce CMS pour être relu
- **Ce qui n'y figure pas est dit dans l'archive elle-même** : empreintes de mots de passe, secrets de double authentification, jetons d'API, secrets de webhook, sessions ouvertes, réglages chiffrés. Ces éléments n'ont aucune valeur ailleurs — les recopier dans un fichier destiné à circuler serait un risque sans contrepartie
- L'archive part directement vers le navigateur : elle ne laisse pas une copie de toute l'entreprise dans un dossier du serveur. Chaque export est tracé

### Sauvegarde et restauration
- **Sauvegarde automatique**, toutes les heures par défaut (intervalle et nombre d'archives conservées réglables dans `/sauvegardes`). Le serveur sauvegarde aussi au démarrage s'il a manqué une échéance
- **Une archive contient tout** : la base, les photos de profil, les CV, le coffre-fort et les documents du parapheur. Sauvegarder la seule base serait un piège — l'instance restaurée prétendrait détenir des bulletins disparus
- Sur SQLite, la base est copiée par le **mécanisme de sauvegarde en ligne** du moteur, cohérent même pendant l'écriture ; copier le fichier à la main ne le serait pas, le journal WAL vivant à côté. Sur MySQL, l'archive porte un **export logique** écrit par le produit lui-même, table par table — ni `mysqldump` ni aucun binaire externe n'est appelé
- **Une archive ne traverse pas les moteurs** : une sauvegarde SQLite porte le fichier, une sauvegarde MySQL porte l'export. Rendre l'une à l'autre n'est pas une restauration mais une migration, et la restauration la refuse en le disant plutôt que de vider la base à mi-chemin
- **Chaque fichier porte son empreinte** dans le manifeste de l'archive, vérifiée avant toute restauration. La somme de contrôle de `tar` ne couvre que les en-têtes : c'est le manifeste qui protège le contenu
- **Restauration table par table dans une seule transaction** : l'application reste debout, et un échec en cours de route ne laisse pas une base à moitié écrite. Les colonnes ajoutées par une migration postérieure à l'archive sont ignorées plutôt que de faire échouer l'opération
- **L'état actuel est sauvegardé d'abord** : une erreur de manipulation se rattrape. Les sessions ouvertes ne sont pas restaurées, pour ne pas déconnecter celui qui mène l'opération
- Téléchargement pour copie hors ligne, vérification d'intégrité à la demande, et restauration depuis une archive téléversée (64 Mo maximum ; au-delà, le fichier se dépose dans le dossier des sauvegardes)
- Une archive contient empreintes de mots de passe, secrets de double authentification et bulletins de paie : le dossier est en `0700`, les archives en `0600`, l'espace est réservé à l'administration et chaque téléchargement est tracé

### Externalisation des sauvegardes
- **Une sauvegarde qui reste sur le serveur qu'elle protège ne protège de rien** : la panne de disque, l'incendie et le rançongiciel emportent les deux. Chaque archive est déposée sur les destinations extérieures actives dès sa création, automatique ou manuelle
- **Serveur FTP** (NAS, espace de sauvegarde d'un hébergeur) en **FTPS explicite, FTPS implicite ou FTP simple** — le mode est choisi à l'écran, l'avertissement sur le FTP en clair aussi. Le dossier distant est créé s'il manque, le certificat auto-signé accepté seulement si on le demande
- **Google Drive par compte de service** : un jeton JWT signé RS256, sans dépendance ni consentement à renouveler. Le compte de service ne possède aucun espace — le dossier de destination lui est partagé depuis un compte Drive, ce qui borne son accès à ce seul dossier
- **Les secrets sont chiffrés en base** (AES-256-GCM, clé dérivée du secret de session qui vit dans un fichier à part) : une copie de la base seule ne livre pas le mot de passe FTP ni la clé du compte de service. L'écran ne les affiche jamais, et un champ secret laissé vide conserve la valeur en place plutôt que de l'effacer
- **Un test de connexion écrit puis efface un fichier d'essai** : il vérifie l'accès en écriture, pas seulement l'authentification
- **Une destination incomplète ne peut pas être activée** — une externalisation qu'on croit active et qui ne l'est pas est pire que pas d'externalisation
- **Le distant est aligné sur le nombre d'archives conservées**, sinon l'espace grossit jusqu'à refuser les dépôts
- **Un échec alerte les administrateurs** (une fois par jour et par destination) et reste affiché à l'écran : une externalisation muette qui échoue depuis trois semaines est le pire des cas, on se croit couvert. Renvoi manuel d'une archive après une panne réseau

### Sécurité et administration de l'instance
- **Console de sécurité** (`/securite`, administration) : journal d'audit filtrable et exportable, sessions ouvertes et leur révocation, comptes à surveiller (verrouillés, mot de passe temporaire jamais remplacé, administrateurs sans double authentification, comptes dormants depuis 90 jours), gestion des administrateurs et politique de l'instance
- **Réinitialisation de la double authentification** d'un membre par l'administration, pour un téléphone perdu — la personne devra la remettre en service
- **Fermeture de toutes ses sessions** par le membre lui-même, depuis son profil
- **Journal d'audit scellé par chaînage d'empreintes** : chaque entrée porte l'empreinte de la précédente. Modifier une ligne, ou en retirer une du milieu, casse la chaîne à cet endroit — et la vérification dit lequel, en nommant l'entrée et ce qui cloche. Ce que le scellement ne fait pas : empêcher la réécriture. Qui tient le fichier de la base peut tout recalculer ; il rend l'altération visible, et c'est aussi loin qu'on aille sans autorité d'horodatage extérieure

### Centre de journaux (`/journaux`, administration)
- **Deux journaux, pas un** : le journal d'audit répond à « qui a fait quoi » et reste scellé ; le centre de journaux répond à « que s'est-il passé ». Les noyer ensemble rendrait le premier illisible et ferait grossir sa chaîne pour rien
- **Les huit niveaux de la RFC 5424** (urgence, alerte, critique, erreur, avertissement, remarquable, information, débogage) et **sept canaux** — authentification, sécurité, administration, données, intégrations, balayage, système. Reprendre les niveaux de la norme évite d'avoir à les traduire au moment de l'export
- **Le produit s'y inscrit lui-même** : connexion refusée, compte verrouillé, jeton de session invalide, plafond atteint, erreur non rattrapée, échec d'export. Rien à activer — l'instrumentation est dans le code, pas dans un réglage
- **Un secret n'y entre jamais** : toute clé qui ressemble à un mot de passe, un jeton ou un cookie devient `[secret retiré]` avant écriture, à toute profondeur. Un journal se lit, s'exporte et part chez un collecteur : ce qui y entre en sort
- **Conservation par niveau** : sept jours pour le débogage, un an pour ce qui est grave. Purge automatique à chaque balayage
- **Recopie continue vers syslog** (UDP, TCP, TLS, trame RFC 5424 ou RFC 3164) **et vers Wazuh** — son écoute syslog, le message portant alors le JSON entier que son décodeur sait lire, ou un dépôt JSON qu'un agent local suit
- **Le filigrane, pas la marque** : chaque cible garde le numéro de la dernière ligne reçue. Une panne du collecteur ne perd rien, deux collecteurs n'avancent pas du même pas, et **la purge ne prend jamais à l'export ce qu'il n'a pas encore vu**
- **Une cible qui échoue dix fois s'éteint** et le dit : mieux vaut un export éteint, qui se voit, qu'un export muet qui fait croire que les journaux partent. Une ligne d'essai part sur demande, sans toucher au filigrane

### Mises à jour (`/mises-a-jour`, administration)
- **L'instance sait se mettre à jour**, depuis le service qui publie les versions : version installée, version disponible, notes de version, historique
- **Deux modes, au choix de l'instance** : *prévenir seulement* — rien n'est appliqué sans un clic — ou *appliquer tout seul*, au balayage horaire. Ce n'est pas l'éditeur qui décide chez vous
- **On ne fait confiance qu'à la signature** : l'empreinte SHA-256 dit que l'archive est arrivée entière, pas d'où elle vient. La signature Ed25519 de l'éditeur porte sur la phrase qui nomme la version — `toutadmin/<édition>/<numéro>/<empreinte>/<taille>` —, si bien qu'une archive authentique ne peut pas être resservie sous un autre numéro ni pour l'autre édition
- **Sauvegarde complète avant de toucher au code**, et les fichiers remplacés sont gardés à côté : un retour arrière ne dépend pas du réseau
- **Une version qui ne démarre pas rend la main** : trois démarrages ratés et les fichiers précédents reviennent d'eux-mêmes, avec une ligne au journal. Personne ne surveille un serveur à trois heures du matin
- **Ce qui appartient à l'instance n'est jamais touché** : `data/`, `.env`, `node_modules/`, `.git/`. Une mise à jour remplace du code, pas des données — et un nom de fichier qui sortirait de l'arborescence est refusé, signature ou pas
- **La base n'est pas remise en arrière** par un retour arrière : elle a pu recevoir des écritures depuis. La sauvegarde prise avant est nommée dans l'historique, pour qui veut la restaurer en connaissance de cause
- **La clé d'instance est chiffrée en base** et n'est jamais réaffichée

### Pilotage et transverse
- **Tableau de bord de direction** (`/pilotage`) : effectif par contrat, absents du jour, facturé et marge, masse salariale, trésorerie, charge du support. Un tiret marque une donnée absente, jamais un zéro
- **Échéances consolidées** : contrats de travail et fournisseurs (au préavis, pas à la fin), factures, habilitations, visites médicales, protections, véhicules, revues du document unique, tâches de projet et points de parcours — dans une seule liste triée
- **Notifications personnelles** tirées de ces échéances, dédupliquées : une même échéance n'alerte qu'une fois, quel que soit le nombre de balayages
- **Objectifs et résultats clés** : chaque résultat mesuré sur sa propre échelle, y compris décroissante (de 24 h vers 4 h), l'avancement de l'objectif étant leur moyenne
- **Recherche globale** : n'interroge que les espaces ouverts à la personne — ce qu'elle n'a pas le droit de voir n'est pas cherché du tout, pas filtré après coup
- **Balayage horaire** au démarrage puis toutes les heures : contrats échus, notifications d'échéance, purge du journal et des notifications lues

### Alerte interne (dispositif de recueil des signalements)
- **Obligatoire en France dès cinquante salariés** depuis la loi du 21 mars 2022, et impossible à bâcler : un canal qu'on n'ose pas emprunter ne sert à rien, et c'est la conception qui décide si on ose
- **Le contenu est chiffré en base** : une copie de la base ne livre ni les signalements ni ce qui s'y dit ensuite
- **Les référents seuls y accèdent, pas les administrateurs** en tant que tels — une alerte peut viser un administrateur. L'administration désigne les référents, ce qui est tracé, mais ne lit rien
- **Un signalement anonyme n'enregistre pas son auteur**, et la route neutralise la trace automatique pour que le journal d'audit ne le rattrape pas par la bande
- **Le suivi se fait sans compte**, avec la référence et un code affiché une seule fois : se connecter pour lire la réponse reviendrait à signer son signalement. La consultation n'est pas journalisée
- **Les délais légaux sont comptés, pas promis** : accusé de réception sous 7 jours, retour sur les suites données sous 90 jours, l'un et l'autre affichés en retard dès qu'ils le sont
- **Chaque ouverture d'un signalement est consignée dans le signalement lui-même**, hors du journal général : y inscrire une alerte la nommerait à toute l'administration

### Données personnelles
- **Registre des traitements** (`/rgpd`, administration), préremplissable avec les traitements que ce logiciel opère lui-même — à relire et compléter, il ne décrit pas ce que fait votre entreprise par ailleurs
- **Droit d'accès** : ce que l'instance détient sur une personne, source par source, exportable en JSON
- **Effacement** : ce qui relève d'une obligation de conservation (bulletins, registre des accidents, journal d'audit) est gardé et le compte rendu le dit ; le reste est effacé et le compte anonymisé plutôt que supprimé, pour que les écritures qui le référencent restent cohérentes. L'adresse exacte est redemandée pour confirmer

### Confort
- Interface disponible en **16 langues** (français, anglais, espagnol, allemand, italien, portugais, néerlandais, polonais, russe, turc, arabe, hindi, chinois, japonais, coréen, vietnamien), sélectionnables **par drapeau sur l'écran de connexion** et depuis le profil
- **Les 78 écrans sont traduits**, comme les 11 gabarits partagés qui les habillent, et pas seulement les pages d'accueil : 2 754 clés par langue couvrent la comptabilité, la paie, la trésorerie, la qualité, le parapheur, les sauvegardes, les interfaces, les données personnelles, le parc logiciel, les livraisons, les événements, la conformité des tiers, l'alerte interne, les achats, le recouvrement et la vie juridique, jusqu'aux boîtes de dialogue de confirmation. Une phrase coupée par un chiffre devient une clé paramétrée — « {total} échéance(s) à 45 jours, dont {overdue} dépassée(s) » — parce que l'ordre des mots change d'une langue à l'autre
- **Les statuts se traduisent aussi.** Ils restent stockés en français — c'est la valeur métier, celle des contraintes de la base et des comparaisons — mais ce qui s'affiche passe par le dictionnaire : un dossier *Approuvée* se lit *Approved* en anglais, *承認済み* en japonais. Un statut ajouté au schéma sans traduction fait échouer les tests plutôt que de ressortir en français chez un utilisateur étranger
- **L'arabe retourne réellement la page.** Le sens d'écriture bascule à droite, et la feuille de style n'emploie que des propriétés logiques (`inline-start` plutôt que `left`) : la barre latérale, le liseré de l'onglet actif, la pastille du compte et les marges des tableaux suivent le sens de lecture au lieu de rester figés à gauche
- **Cinq palettes**, choisies par l'administration pour toute l'instance (`/admin`, section Apparence) : *Indigo et lavande* (**par défaut** — rail clair posé comme une carte, fond lavande dégradé, indigo et violet pour l'action, titres plus gros et plus gras, angles très ronds), *Bleu institutionnel* (sobre, angles droits), *Ardoise et indigo* (neutres contemporains, angles doux), *Magenta et violet* (couleurs franches, navigation en dégradé), *Cyan et violet* (la charte du template : rail bleu nuit, cyan pour l'action). L'écran de choix montre un aperçu qui emprunte les jetons de chaque palette — il ne peut pas mentir sur ce qu'il propose
- **Le rail n'est pas forcément sombre.** Ce qui se pose par-dessus la couleur de marque — survol, rubrique en service, filets, pastilles — était écrit en blanc translucide dans les règles, ce qui interdisait par construction toute palette à marque claire : le nom de l'instance y aurait été blanc sur blanc. Ce sont désormais des jetons, et un test refuse qu'une couleur revienne en dur dans ces règles-là. L'écriture suit la palette elle aussi : famille, poids et serrage des titres sont des jetons, pas des valeurs écrites dans les règles
- Thème **clair / sombre / système**, mémorisé dans le navigateur et appliqué sans clignotement. Deux axes distincts : la palette engage l'identité de l'entreprise et se règle une fois ; le clair ou sombre est un confort de lecture et appartient à chaque personne. Les dix combinaisons sont vérifiées au contraste par les tests — y compris ce qui se pose sur le rail, mesuré sur la couleur de marque et non sur la carte
- Interface responsive : la navigation latérale se replie en bandeau horizontal, les tableaux denses défilent, et la grille du calendrier tient en entier sur un téléphone

## Sécurité

Le contrôle d'accès repose sur des garde-fous serveur : `requireAdmin` sur `/admin/*`,
`requireHR` sur `/rh/*`, `requireEmployee` sur `/mon-espace/*`, `requireManager` sur
`/mon-equipe` (recalculé à chaque requête d'après les rattachements réels). Seul un
administrateur peut accorder ou retirer l'accès RH, rattacher un manager, masquer un membre
de l'annuaire ou configurer une messagerie.

- **La session suit les droits réels** : désactivation, fin de contrat, verrouillage, promotion ou rétrogradation sont relus **à chaque requête**. Un départ coupe l'accès tout de suite, sans attendre l'expiration du cookie.
- **Mots de passe** : hachage `bcrypt` (coût 12). Politique appliquée partout — 12 caractères minimum, trois catégories parmi minuscules/majuscules/chiffres/symboles, ni le nom ni l'identifiant, ni suite de clavier ni mot de passe courant. Les mots de passe temporaires font 16 caractères, sont affichés une seule fois, et **doivent être remplacés avant d'ouvrir quoi que ce soit**. Changer de mot de passe ferme les autres sessions. Aucun mot de passe d'outil tiers n'est stocké, seulement l'identifiant et l'URL.
- **Double authentification (TOTP, RFC 6238)** : mise en service par QR code, vérifiée par un premier code avant d'être activée, codes de secours à usage unique, et un code faux compte comme un échec de connexion. L'instance peut l'exiger des administrateurs, ou de tout le monde.
- **Sessions** persistées en base : elles survivent à un redémarrage, se voient dans la console, et se **révoquent d'un geste** — par la personne elle-même ou par l'administration. Expiration par inactivité (`SESSION_IDLE_MINUTES`, 60 min par défaut) doublée d'un plafond absolu (`SESSION_MAX_HOURS`, 12 h).
- **Journal d'audit** : toute requête qui modifie quelque chose est tracée (auteur, action, objet, IP, horodatage), avec des entrées détaillées sur les actions sensibles — connexions réussies et manquées, réinitialisations, promotions, changements de politique. Consultable, filtrable et exportable en CSV depuis `/securite`, purgeable selon la durée de conservation choisie.
- **Plusieurs administrateurs** : une entreprise ne dépend pas d'un seul compte. Le dernier administrateur ne peut être ni rétrogradé, ni se retirer ses propres droits.
- **Verrouillage de compte** : 5 échecs consécutifs verrouillent le compte 15 minutes.
- **Limitation de débit** : 10 tentatives de connexion / 15 min par IP, 300 requêtes / minute au global (ajustables par variables d'environnement).
- **Anti-énumération** : message et temps de réponse identiques que le compte existe ou non.
- **CSRF** : jeton par session vérifié en comparaison à temps constant sur chaque POST. Un envoi de fichier ne livre son jeton qu'une fois le corps multipart décodé : le contrôle y est donc différé juste après la réception, et les fichiers transitent **en mémoire** — rien n'est écrit sur le disque avant que le jeton soit validé.
- **Redirections** : toute cible venue d'un formulaire passe par un filtre qui refuse `//site` et `/\site`, des URL absolues pour le navigateur.
- **Sessions** : cookie `httpOnly`, `sameSite=lax`, `secure` en production, identifiant régénéré à la connexion.
- **En-têtes** : `helmet` avec CSP stricte (scripts par nonce, aucun style ni gestionnaire d'événement en ligne).
- **Validation** : grades, types de contrat et de demande contrôlés contre des listes blanches ; emails, URL, dates et montants validés ; longueurs bornées.
- **Base** : requêtes intégralement paramétrées, sur les deux moteurs (`better-sqlite3`, `mysql2`). La traduction du dialecte MySQL n'assemble jamais de valeur dans le SQL : elle ne touche qu'aux mots-clés, et jamais à l'intérieur d'un littéral.
- **Téléversement** : photos limitées à 2 Mo (JPEG/PNG/WebP) et CV à 5 Mo (PDF/DOCX/TXT/Markdown). Le type MIME étant déclaré par le client, c'est la **signature du fichier** qui est vérifiée : un exécutable renommé en `.pdf` est refusé. Nom de fichier régénéré aléatoirement, stockage hors du dépôt en `0600`. Les photos sont servies en lecture seule ; les CV ne le sont pas du tout, ils ne sortent que par une route authentifiée réservée aux RH.
- **Messagerie** : un message n'est lisible que par son expéditeur ou son destinataire, et n'est marqué lu que par ce dernier.
- **Erreurs** : aucune trace technique renvoyée au client.
- **Démarrage** : refus de démarrer en production si `SESSION_SECRET` ou `ADMIN_PASSWORD` sont restés à leur valeur par défaut.

Aucun système n'est protégé de façon absolue, mais ces mesures couvrent les risques
standards (injection, authentification, XSS, CSRF, contrôle d'accès, mauvaise configuration).

## Licence

L'instance sait dire si elle a le droit d'écrire, et jusqu'à quand. Elle ne
fabrique aucune licence : son code ne porte que la **clé publique** de
l'éditeur, qui vérifie sans jamais pouvoir signer. Un jeton est une charge utile
JSON signée en Ed25519 ; la vérification porte sur les octets reçus, pas sur un
JSON ré-encodé, ce qui rend la canonicalisation sans objet.

**Cinq états, et deux comptes à rebours qui ne mesurent pas la même chose.**

| État | Ce qui se passe | Écritures |
| --- | --- | --- |
| `absente` | aucun jeton, ou un jeton qu'on ne sait pas vérifier | refusées |
| `valide` | le jeton couvre aujourd'hui, la dernière vérification est passée | permises |
| `sursis` | le jeton couvre encore, mais la vérification échoue depuis moins de 48 h | **permises**, bandeau |
| `grace` | l'échéance du jeton est passée depuis moins de « grace » jours | **permises**, bandeau |
| `restreint` | au-delà de l'un ou de l'autre | refusées |

`grace` part de l'échéance écrite dans le jeton : un abonnement qui se termine,
cela se prévoit. `sursis` part de la première vérification en échec — une
licence révoquée, une clé d'instance retirée, un service de licences injoignable :
cela tombe sans prévenir. D'où quarante-huit heures, assez pour qu'un humain
soit prévenu, rappelle et règle, trop peu pour qu'une instance révoquée continue
des mois. Le bandeau compte en heures, pas en jours : « il reste 2 jours » se
lit comme « on a le temps », alors qu'il faut appeler aujourd'hui.

On vérifie **toutes les heures**, et **tous les quarts d'heure une fois le
sursis engagé** — c'est là qu'une vérification qui réussit rend le plus service,
puisqu'elle éteint le bandeau. Une réussite efface le compte à rebours ; un
échec qui se répète ne le repousse jamais, sans quoi les quarante-huit heures ne
viendraient jamais.

### Ce qui rend ces règles difficiles à neutraliser

Les durées ci-dessus ne valent que ce que vaut la date qui les mesure — et la
date d'une machine appartient à qui l'administre. Reculer l'horloge d'un serveur
prend dix secondes et prolongerait un sursis indéfiniment. C'est le moyen le
plus simple, le plus silencieux et le plus reproductible de neutraliser une
licence, et **chiffrer le code n'y changerait rien** : le code chiffré lirait la
même horloge menteuse.

L'instance ne demande donc pas « quelle heure est-il », question à laquelle
l'hôte répond ce qu'il veut, mais **« quel est le plus tard dont j'aie la
preuve »**. Trois sources, dont on retient la plus avancée :

1. **la date d'émission du jeton**, qui est signée par l'éditeur. On ne peut pas
   la déplacer vers l'avant sans la clé privée, et la déplacer vers l'arrière ne
   sert à rien ;
2. **le dernier horodatage du journal d'audit**, qui est scellé par chaînage
   d'empreintes. Le reculer casse la chaîne, et la vérification du sceau le dit
   à l'endroit exact ;
3. **la marque haute** déjà retenue, qui ne redescend jamais, et l'heure système
   si elle va plus loin.

Avancer l'horloge reste possible et n'est pas surveillé : cela fait expirer la
licence **plus tôt**, pas plus tard. Personne n'y a intérêt.

Le compteur d'échec suit la même logique. Il vit dans un réglage, qui s'efface
d'une requête SQL — et le sursis repartirait à zéro toutes les quarante-huit
heures sans que rien ne paraisse. Il est donc corroboré par le journal scellé :
on retient **la plus ancienne** des deux dates, celle du réglage et celle du
premier échec scellé depuis la dernière réussite scellée. Effacer le réglage ne
gagne rien ; effacer le journal se voit.

Enfin, **une preuve quitte la machine**. À chaque renouvellement, l'instance
annonce l'heure qu'elle croit être. Le panneau la compare à la sienne et note
l'écart quand il dépasse une journée. C'est la seule trace qui survive à un
accès administrateur complet sur le serveur du client : elle n'est pas chez lui.

### Ce que l'obfuscation ajoutera, et ce qu'elle n'ajoutera pas

L'édition PHP sera distribuée encodée. Cela **élève le coût** d'une
modification : il faut décoder avant de retoucher, ce qui écarte la retouche
opportuniste. Cela **n'ajoute rien** au reste, et il vaut mieux le savoir
d'avance :

- l'édition Node n'est pas encodable par ce moyen, et son code part tel quel.
  Les protections décrites ici valent donc pour les deux, ou elles ne valent
  rien ;
- la clé publique de l'éditeur est dans le code. Qui sait éditer le code sait la
  remplacer par la sienne et se signer des licences. Aucun encodage n'y change
  quoi que ce soit, il rend seulement l'opération plus longue ;
- un encodeur ne protège pas la base de données, ni les réglages, ni l'horloge
  du système. Les trois ancrages ci-dessus, eux, tiennent **même quand tout
  cela est entre les mains de l'hôte** — et c'est pour cela qu'ils sont dans la
  conception plutôt que dans l'emballage.

Ce qui est visé est donc modeste et utile : qu'on ne puisse pas neutraliser une
licence **par accident, ni en trois clics, ni sans laisser de trace**. Un
logiciel qu'on installe chez soi ne se défend pas d'un adversaire déterminé, et
prétendre le contraire serait se mentir autant qu'au client.

### Ce que la licence ne fait pas, et ne fera pas

- **Elle n'efface rien, ne chiffre rien, ne cache rien.** En `restreint` on se
  connecte, on lit tout, on exporte tout. Seules les écritures sont refusées.
- **Elle laisse toujours passer** : se connecter, se déconnecter, changer son
  mot de passe, lancer un export, ouvrir une sauvegarde, poser une licence. Le
  client est responsable de traitement au sens du RGPD : il doit pouvoir entrer
  chez lui et ressortir avec ses données. Une licence impayée se règle par une
  discussion commerciale, pas en séquestrant des dossiers du personnel et des
  bulletins de paie. **Ce n'est pas négociable.**
- **Elle ne licencie personne.** Le plafond de membres ne s'applique qu'à la
  création : une instance qui repasse sous une offre plus petite garde les gens
  qu'elle a déjà.
- **Elle ne lit pas le matériel.** L'empreinte d'installation est un nombre tiré
  au hasard une fois, pas une signature de machine : déplacer une machine
  virtuelle ne coupe personne.
- **Elle ne restreint rien sans clé publique.** Une instance dont le code n'en
  porte pas — développement, tests, démonstration — n'est pas une instance
  vendue, et rien n'y est limité.
- **Elle ne remplace pas un contrat.** Elle dit l'état d'un abonnement ; elle ne
  prouve rien devant un tribunal et ne prétend pas empêcher quelqu'un de
  modifier son propre code. C'est un logiciel qu'on installe chez soi : qui veut
  retirer la vérification le peut. Elle est là pour que les choses soient
  claires entre gens de bonne foi, pas pour se défendre d'un adversaire.

### Ce qu'une signature valable ne suffit pas à autoriser

Quatre contrôles que la cryptographie ne fait pas toute seule, et que le module
fait après elle :

- **l'édition** — un jeton émis pour l'autre édition est refusé ;
- **l'installation** — un jeton portant l'empreinte d'une autre instance est
  refusé ; c'est ce qui distingue une copie d'un déménagement de serveur ;
- **l'abonnement** — le jeton ne vaut jamais au-delà de l'abonnement qu'il
  annonce, même si sa propre date d'expiration allait plus loin par erreur ;
- **le rejeu** — un jeton correctement signé n'est pas pour autant celui
  d'aujourd'hui. Gardé puis reposé plus tard, un vieux jeton rendrait à
  l'instance le plafond de membres ou l'échéance qu'elle avait à l'époque, sans
  rien forger. Seule la date d'émission protège de cela : un jeton plus ancien
  que celui en place est écarté. À émission égale on accepte — c'est le même
  jeton reposé, et le refuser casserait une réinstallation depuis une
  sauvegarde.

### À l'installation

L'étape « Licence » vérifie **avant** d'aller plus loin : un bouton
« Vérifier la licence », une barre qui s'anime pendant l'attente et disparaît
dès la réponse, et « Suivant » qui reste gris tant que la réponse n'est pas
oui. C'est là qu'on veut découvrir qu'une clé est refusée — pas une fois les
données du client dedans. Retoucher un champ regrise le bouton : on ne vérifie
pas une clé pour en installer une autre.

Cette vérification **n'engage rien**. La route d'essai du panneau ne lie aucune
licence, ne l'active pas et ne la note pas comme vue : on peut presser le bouton
autant de fois qu'on veut, et une installation abandonnée en cours de route ne
consomme la licence de personne. Le jeton hors ligne, lui, se vérifie sur place
— sa signature est celle de l'éditeur et la clé publique est dans le code, rien
ne sort de la machine.

Le bouton grisé est du confort : il dit à l'utilisateur où il en est. **Ce n'est
pas un contrôle.** Un attribut `disabled` s'enlève en trois secondes dans un
navigateur, et un formulaire s'envoie sans navigateur du tout : la même
vérification est donc refaite côté serveur avant d'ouvrir l'étape suivante.

Continuer sans licence reste possible. Une instance non activée n'est pas
bloquée, elle est non activée, et son écran « Licence » la rattrapera.

## Installation

```bash
npm install
npm start                 # http://localhost:3000
```

### Sur un serveur Linux

```bash
sudo ./deploiement/install.sh --domaine intranet.exemple.fr --courriel vous@exemple.fr
```

Le script pose Node, un utilisateur système, le code dans `/opt/toutadmin`,
les données dans `/var/lib/toutadmin`, une unité systemd qui redémarre toute
seule, et — si on lui donne un domaine — un reverse-proxy nginx avec son
certificat. Puis il s'arrête et donne l'adresse à ouvrir. Il est fait pour
être relancé : chaque étape regarde d'abord si elle a déjà été faite.

`--simulation` dit ce qui serait fait sur cette machine-ci, sans rien poser.

#### Les distributions

| | |
|---|---|
| Debian, Ubuntu | `apt` |
| **AlmaLinux, Rocky, RHEL, CentOS Stream, Fedora — 9 comme 10** | `dnf` |
| openSUSE, SLES | `zypper` |
| Arch | `pacman` |
| Alpine | `apk` — sans systemd, le service est à lancer à la main |

Enterprise Linux demande trois choses que les autres n'exigent pas, et le
script les fait :

- **SELinux** y est contraignant. Sans `httpd_can_network_connect`, nginx ne
  peut pas joindre le service local : le visiteur reçoit **502**, le journal
  de nginx dit « Permission denied », le service tourne parfaitement, et l'on
  cherche une heure du mauvais côté. SELinux n'est jamais désactivé pour
  autant — le mettre en `permissive` éteindrait la protection de toute la
  machine pour un service.
- **firewalld** y est allumé et ferme le web. Le site est injoignable de
  l'extérieur sans le moindre message, puisque rien n'a échoué.
- **La modularité a disparu en 10.** `dnf module enable` y est une commande
  qui n'existe pas : un script qui l'appelle sans regarder échoue sur la
  distribution la plus récente. En 9 elle est nécessaire, en 10 elle est
  fatale.

Les neuf distributions sont éprouvées à chaque modification :

```bash
bash deploiement/essais/distributions.sh
```

Chacune est jouée par un faux `/etc/os-release` et de faux outils, et l'on
vérifie ce que le script décide. Cela ne dit pas que les paquets existent —
cela dit qu'il reconnaît la machine et choisit la bonne commande, ce qui est
précisément ce qui cassait.

Il ne pose **ni base, ni compte, ni licence**, et c'est délibéré : un script
qui créerait le compte devrait aussi choisir un mot de passe, donc l'écrire
quelque part — et ce quelque part serait le maillon faible de toute
l'installation. L'assistant le fait dans le navigateur, puis se ferme.

Le service reçoit `CODE_IMMUABLE=1` : le code est posé par le script et
remplacé par lui. L'écran « Mises à jour » signale donc les versions sans en
poser aucune, et relancer le script est le geste qui change de version.

### Sur Windows

```powershell
.\deploiement\install.ps1
```

PowerShell en administrateur. Le script télécharge l'archive officielle de
Node — pas l'installeur, pour pouvoir choisir la version —, pose le code,
installe les dépendances, et crée une tâche qui démarre avec la machine.

La version de Node est figée, et pour une raison précise : sur dix-sept
dépendances, une seule se compile, `better-sqlite3`. Si aucun binaire
précompilé ne correspond à la version de Node, npm réclame Visual Studio
Build Tools et Python — et c'est exactement là qu'on abandonne. Le script
vérifie donc que la bibliothèque **se charge vraiment** après l'installation,
et le dit franchement sinon, au lieu de laisser découvrir la panne au premier
écran.

Windows ne sait pas faire un service d'un programme ordinaire : il lui faut un
exécutable qui parle son protocole, ce que `node.exe` ne fait pas. Le script
emploie donc une tâche planifiée au démarrage, qui ne demande rien d'autre que
Windows. Sa dernière ligne dit comment passer à un vrai service avec NSSM,
pour qui le préfère.

### En conteneur

```bash
docker compose up -d      # puis http://localhost:3000
```

L'image ne pose **ni base, ni compte, ni licence** : elle met le moteur en
route, et l'assistant fait le reste dans le navigateur, exactement comme sur
une installation classique. C'est ce qui permet au `Dockerfile` de tenir en
deux étapes courtes.

Deux points méritent d'être connus avant de s'en servir :

- **Un seul volume suffit, et il suffit vraiment.** Tout ce que le produit
  range sur le disque suit le dossier de `DB_PATH` : la base, `instance.json`,
  le secret de session, les photos de profil, le coffre-fort, les CV, le
  parapheur, les pièces reçues, les sauvegardes. L'instance reste donc la même
  après n'importe quel remplacement d'image — y compris aux yeux du service de
  licences, qui la reconnaît à son empreinte.

- **L'écran « Mises à jour » ne pose plus rien lui-même.** Le code vit dans
  l'image ; des fichiers réécrits dans un conteneur disparaissent à son premier
  remplacement, c'est-à-dire que la mise à jour aurait l'air de marcher puis se
  déferait toute seule. L'écran continue de signaler les versions — le savoir
  sert — et donne le geste qui convient à la place du bouton :

  ```bash
  docker compose pull && docker compose up -d
  ```

  C'est la variable `CODE_IMMUABLE=1` qui le décide. Elle ne parle pas de
  conteneurs mais de ce qui compte : le code ne s'écrit pas. Un déploiement
  posé par un gestionnaire de paquets, ou dont le dossier est en lecture seule,
  est dans le même cas et gagne à la poser aussi.

`docker-compose.yml` porte, en commentaire, de quoi passer à MySQL ou MariaDB
plutôt qu'à SQLite, et de quoi exiger un `INSTALL_TOKEN` — recommandé dès que
le port est exposé ailleurs que sur la machine.

Sur une instance vierge, **toute l'application redirige vers l'assistant d'installation**
(`/install`). Il se déroule en huit étapes, dans une fenêtre flottante avec le rail bleu
du produit à gauche et l'avancement en barre tout en haut :

1. **Langue** — parmi les 16 langues disponibles ; devient la langue par défaut de l'instance.
2. **Prérequis** — version de Node, dossier de données accessible en écriture, base SQLite,
   dossier des photos de profil, plus deux recommandations de mise en production
   (`TRUST_PROXY`, `NODE_ENV=production`) signalées en ambre sans bloquer.
3. **Mises à jour** — voir ci-dessous.
4. **Licence** — la clé d'activation, éprouvée avant de continuer : « Activer » interroge
   l'éditeur, affiche le nombre de salariés couverts, et c'est seulement alors que
   « Suivant » se rend. Continuer sans licence reste un choix explicite ; l'instance
   s'activera depuis son écran « Licence ».
5. **Base de données** — SQLite dans un fichier, qui ne demande rien, ou MySQL / MariaDB
   quand plusieurs machines servent le même site. L'assistant essaie la connexion avant de
   s'y engager et rapporte ce que le serveur répond, sur l'écran même où l'adresse est saisie.
6. **Organisation** — nom affiché partout dans l'interface, et quota de congés annuels
   attribué à chaque nouveau salarié non-freelance.
7. **Administrateur** — le premier compte, avec un mot de passe de 12 caractères minimum.
8. **Installation** — le récapitulatif, puis la pose.

### L'étape « Mises à jour »

Installer une version dépassée est une mauvaise façon de commencer : on pose un CMS dont on
sait déjà qu'il lui manque des correctifs, et la première chose qu'on demandera au client
sera de le mettre à jour. L'assistant le règle avant qu'il y ait des données dedans.

Elle vient avant « Licence », ce qui pose une difficulté réelle : télécharger une archive
demande une clé, et la clé se saisit à l'étape d'après. On coupe donc en deux ce qui n'a pas
à être fait d'un bloc.

- **Savoir** s'il existe plus récent ne demande aucune clé — `GET /api/v1/derniere` sur le
  service de licences. Le numéro de la dernière version est écrit sur la page de téléchargement
  du site ; le refuser à un installeur serait absurde. La question part toute seule au
  chargement de l'écran.
- **Télécharger** en demande une. Le champ n'apparaît qu'une fois qu'on sait qu'il va
  servir, et la clé est ensuite reportée à l'étape « Licence », qui ne la redemande pas.
- **Une version en retard bloque** : « Suivant » reste gris, et un bouton propose de poser
  la dernière version tout seul — téléchargement, vérification de la signature Ed25519 de
  l'éditeur, contrôle du contenu de l'archive, pose des fichiers, et remise en place de ce
  qui a été mis de côté si la pose échoue à mi-chemin. Le service doit être redémarré
  ensuite : les fichiers ont changé sous ses pieds. L'écran le dit.
- **Un éditeur injoignable ne bloque pas.** Un client derrière un pare-feu d'entreprise, sur
  un réseau sans sortie, ou pendant une panne de notre côté, doit pouvoir installer son CMS.
  Transformer une panne de réseau en produit inutilisable serait une faute bien plus grave
  que de laisser poser une version d'un mois. L'écran passe en ambre, dit ce qu'il a reçu —
  le code et le type de la réponse —, et laisse continuer. Ce qui s'est produit est noté
  dans le brouillon d'installation.
- **Le bouton grisé est du confort, pas un contrôle.** Un attribut `disabled` s'enlève en
  trois secondes dans un navigateur, et un formulaire s'envoie sans navigateur du tout : le
  serveur refait la vérification quand on valide l'étape.
- **La clé publique qui vérifie l'archive est celle du code**, jamais un réglage : à
  l'installation il n'y a pas encore de réglages, et une clé de vérification qu'on pourrait
  poser soi-même ne vérifierait rien. C'est la même paire Ed25519 qui signe les licences et
  les versions.

La pose se fait en **quatre paliers**, chacun annoncé à l'écran : vérification de
l'environnement, schéma, création de l'instance, pose du verrou. La barre d'avancement ne
bouge que lorsqu'un palier revient du serveur — une barre qui court toute seule pendant qu'on
attend ment surtout au moment où elle devrait alerter — et s'arrête là où ça échoue, en
disant quoi.

Sur MySQL, le dernier palier écrit aussi `data/instance.json` — les réglages de connexion,
en `0600`, jamais versionnés. Les variables d'environnement l'emportent sur ce fichier : c'est
la convention des conteneurs, et c'est ce qui permet de corriger une instance sans toucher à
son disque. Une installation sur MySQL demande un redémarrage ; au démarrage suivant, la base
SQLite qui avait porté l'assistant est effacée, après vérification qu'elle ne porte aucun
compte.

Le dernier palier écrit `data/install.lock`, qui **referme définitivement l'assistant** :
toute visite ultérieure de `/install` renvoie à la page de connexion. Tant que ce verrou
n'est pas posé, une installation interrompue se reprend là où elle s'était arrêtée. Le secret
de session est généré automatiquement dans `data/session.key` (permissions `0600`) si
`SESSION_SECRET` n'est pas fourni.

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

1007 tests d’intégration couvrent la sauvegarde (aller-retour tar exact, en-tête
abîmé et archive tronquée refusés, chemin sortant de sa racine rejeté, archive
embarquant base et coffre-fort, contenu altéré détecté par le manifeste,
restauration qui remet base et fichiers et efface ce qui a suivi, sauvegarde de
sécurité prise avant, confirmation par nom exact, colonne ajoutée après la
sauvegarde tolérée, purge, intervalle automatique respecté, espace réservé à
l'administration), l'externalisation (client FTP éprouvé contre un vrai serveur
FTP tenu en mémoire — dépôt octet pour octet, liste, suppression, mot de passe
refusé sans exception ; jeton Google vérifié avec un vrai couple de clés et
réutilisé au lieu d'être redemandé ; secret illisible après altération, jamais
rendu à l'écran, conservé si le champ est laissé vide ; destination incomplète
non activable, administrateurs alertés en cas d'échec), les palettes (jetons complets dans chaque
combinaison, contraste minimal tenu sur les six, aperçus sans couleur en dur,
palette servie jusqu'à l'écran de connexion, valeur inconnue ou aberrante sans
effet), le coffre-fort (document scellé par son empreinte et
conservé cinquante ans, exécutable déguisé refusé, dépôt multipart sans jeton refusé,
document en double écarté, altération détectée et document non servi, retrait réservé à
l'administration et motivé, compte fermé qui n'ouvre que le coffre, code d'accès valide
puis révoqué, message identique que le compte existe ou non), le socle transverse (échéances rassemblées de tous
les espaces et triées, notification dédupliquée à la rejouée, notification d'autrui
non marquable, résultat clé mesuré sur son échelle y compris décroissante, recherche
qui ne rend ni les tiers ni les projets hors périmètre, membre masqué introuvable,
registre préremplissable une seule fois, export JSON, effacement qui garde les
bulletins et anonymise le compte), les projets (échéance antérieure au début refusée,
date de fin de tâche posée puis effacée, saisie de temps bornée, imputation croisée
entre projets refusée, cloisonnement à l'équipe, suppression en cascade), le support
(délai déduit de la priorité et recalculé depuis l'ouverture, note interne cloisonnée
et non comptée comme réponse, portée des articles), les parcours et compétences
(échéances relatives au jour pivot, clôture et réouverture, expiration déduite de la
durée de validité, obligatoire manquante), la santé-sécurité (criticité, refus d'un
accident daté dans le futur, taux de fréquence et de gravité, péremption des
protections), la gouvernance (décision qui survit à la suppression de sa réunion,
présence inventée refusée, action remontée dans les échéances et notifiée à son
porteur, cotation résiduelle retenue et cotation brute conservée quand elle manque,
placement dans la matrice), les sondages (table de réponses dépourvue de colonne
d'auteur, journal d'audit qui ne rend rien de plus, résultats retenus sous le seuil
d'anonymat, sondage ouvert non modifiable, double réponse et population non conviée
refusées, indice du baromètre), le planning (chevauchement et absence accordée
refusés, absence en attente sans effet, poste de nuit qui finit le lendemain,
roulement borné à trois mois, publication de la semaine, astreinte lue à l'instant
voulu, salarié qui ne voit que ses propres créneaux), la qualité (non-conformité non
clôturable avec des actions en cours, efficacité impossible à juger avant la fin de
l'action, constat d'audit transformé en non-conformité) et l'accueil (liste des
personnes dans les murs, sortie non réécrite, remise de courrier datée et signée),
la multidevise (taux figé qui survit à la mise à jour du taux courant, devise sans
taux refusée à la facturation, totaux convertis, devise de référence réservée à
l'administration), la facturation récurrente (échéance avancée d'une période,
émission rejouée sans doublon, terme qui éteint l'abonnement, devise sans taux
écartée sans bloquer les autres, factures conservées après suppression du moule)
et la TVA (ventilation par taux, brouillon et annulée ignorés, conversion au taux
figé, crédit reportable, période bricolée refusée, déclaration déposée non
recalculée), la lecture CSV (séparateur deviné, guillemets et retours à la ligne
dans un champ, marque d'Excel retirée, en-têtes accentués normalisés, colonnes en
double refusées), l'import de masse (aperçu qui n'écrit rien, fichier à une ligne
fautive intégralement refusé, doublon vu contre la base et contre le fichier
lui-même, mot de passe temporaire affiché une fois et absent du journal, imports
de module suivant l'activation, cloisonnement par droit) et l'organigramme
(rattachement sans équipe, équipe sans service, personne sans rattachement,
membre masqué visible de la seule administration), le parapheur (ordre du
circuit respecté, rang laissé libre sans décalage des qualités, mot de passe
et consentement exigés, sceau invalidé par une réécriture en base, texte ou
fichier modifié qui bloque la signature et le téléchargement, refus motivé qui
interrompt le circuit, document signé non supprimable, fichier en 0600), l'API
(401 sans jeton et sur jeton révoqué, expiré ou inventé, portée refusée route
par route, jeton stocké en empreinte seule et absent du journal, membre masqué
qui ne ressort pas, motif d'absence retenu, pagination bornée, 404 en JSON) et
les webhooks (adresse interne et http refusées par défaut, secret chiffré,
signature qui ne vaut que pour ce corps exact, événement non écouté ignoré,
réessais puis abandon, extinction après une série d'échecs, purge du journal),
la lecture des factures (nombres et dates dans toutes leurs conventions, clés de
contrôle SIRET et IBAN, montant lu à côté de son étiquette malgré la colonne de
blancs, pourcentage qui n'est pas un montant, cohérence qui fait la confiance,
numéro de facture distingué d'une date, nos propres identifiants écartés de
l'émetteur), la réception des pièces (doublon refusé, fichier déguisé refusé,
scan sans texte reçu mais signalé, empreinte revérifiée, correction du comptable
qui prime sur la lecture, pièce facturée ni refacturable ni supprimable),
l'analyse assistée (rien envoyé sans activation, clé chiffrée et jamais
réaffichée, schéma et texte envoyés puis réponse normalisée, refus et panne sans
casse, arbitrage règles/modèle sur les identifiants vérifiés et les montants
cohérents), la capture IMAP éprouvée contre un vrai serveur IMAP tenu en mémoire
(pièces jointes retenues, messages marqués lus, seconde relève vide, doublon
écarté, message rangé dans un dossier, rien supprimé, mot de passe faux rendu
comme un échec), les circuits d'approbation (seuil qui raccourcit le circuit, étape sans
validateur sautée, demandeur écarté de ses propres validations, avancée étape
par étape, refus motivé, retrait impossible après examen, type fermé et non
supprimable tant qu'une demande y court) et l'export intégral (tables et
colonnes sensibles absentes, réglages chiffrés retenus, jeton d'API introuvable
dans l'archive, manifeste et mode d'emploi, rien laissé sur le serveur), la trésorerie (solde recalculé, rapprochement au sens contraire refusé,
projection et point bas), les immobilisations (linéaire, bascule du dégressif, bornes)
et la flotte (doublon d'immatriculation, compteur qui ne recule pas, échéance
dépassée), la sécurité (session révoquée dès la désactivation,
la fin de contrat, le verrouillage ou la rétrogradation ; redirection hors site refusée ;
politique de mot de passe ; mot de passe temporaire à remplacer avant toute autre page ;
TOTP vérifié contre les vecteurs de la RFC 6238 ; code de secours à usage unique ;
exécutable déguisé refusé à l'envoi ; journal d'audit et son export réservés à
l'administration ; dernier administrateur non rétrogradable), le filtrage ATS (frontières de mots, années
d'expérience lues dans le CV, extraction PDF/DOCX/texte, score pondéré, critère requis
manquant qui écarte malgré un bon score, réévaluation après retrait d'un critère,
type de fichier refusé, CVthèque, suppression du fichier et du texte, CV hors de portée
du personnel, **envoi multipart sans jeton CSRF refusé sans rien écrire**), les modules débloquables (routes en 404 tant qu'un
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
hiérarchiques et les actualités. Ils tournent sur une base SQLite temporaire isolée — et
la suite entière repasse sur MySQL (`DB_DRIVER=mysql`), sur une base de test à elle.

## Modules débloquables

Sept modules sont livrés **éteints par défaut**. L'administration les débloque un à un
depuis la console (`/admin`, section Modules) : l'activation ouvre l'espace, ses routes
et son entrée de navigation ; la désactivation les referme **sans rien effacer**. Tant
qu'un module est éteint, ses URL répondent 404.

Chaque module affiche à l'activation ce qu'il **ne** garantit pas — la limite est sur
l'écran, pas enfouie dans une documentation.

| Module | URL | Ce qu'il fait | Ce qu'il ne fait pas |
| --- | --- | --- | --- |
| **Comptabilité** | `/comptabilite` | Plan comptable, journaux, écritures équilibrées, balance, grand livre, export CSV. Une facture se passe en écriture d'un clic. Les écritures entrent au brouillard ; **valider** une période les numérote dans l'ordre chronologique, sans trou, et la verrouille — une écriture validée ne se supprime plus, elle se **contrepasse**. Le **FEC** (article A47 A-1 du LPF) se tire pour un exercice validé : dix-huit colonnes, tabulation, ISO 8859-15, nommé `SIRENFECAAAAMMJJ.txt`. | Ni liasse fiscale, ni télétransmission, ni écritures d'à-nouveaux : l'export alimente l'expert-comptable. |
| **Moteur de paie** | `/paie` | Barèmes paramétrables, calcul du brut au net, part patronale, coût employeur, bulletin détaillé, génération en lot, simulateur. | Les taux sont ceux que vous saisissez ; aucune DSN. |
| **Facturation électronique** | `/facturation-electronique` | Contrôle des mentions EN 16931, export du XML CII (UN/CEFACT) et Factur-X : le PDF/A-3 lisible qui porte ce XML en pièce jointe. | Le dépôt sur une plateforme agréée reste à faire : il demande un compte chez un opérateur, pas un réglage ici. |
| **Stock et achats** | `/stock` | Articles, mouvements, seuil d'alerte, demandes d'achat validées par le manager puis par la gestion au-delà de 500 €. Bons de commande, réceptions et rapprochement à trois : commandé, reçu, facturé. | Stock mono-dépôt au dernier prix connu ; ni FIFO, ni CUMP. Le rapprochement nomme l'écart, il ne bloque pas le règlement. |
| **Trésorerie** | `/tresorerie` | Comptes bancaires, mouvements, rapprochement des encaissements avec les factures, projection à douze semaines avec son point bas. **Import des relevés** CFONB 120, CAMT.053 et OFX : un relevé réimporté ne double rien (empreinte par mouvement), et le relevé d'un compte est refusé dans un autre quand les derniers chiffres ne concordent pas. | Aucune connexion bancaire (DSP2) : le relevé se télécharge chez la banque et s'importe ici. |
| **Immobilisations** | `/immobilisations` | Registre, amortissement linéaire et dégressif avec bascule calculée, valeur nette comptable, dotation de l'exercice. | Outil de suivi : rattachement comptable, composants et dérogatoires restent à l'expert-comptable. |
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
  repose le compteur. Une sortie supérieure au stock est refusée. **Recevoir plus que
  commandé l'est aussi**, et une ligne rattachée à un article entre en stock au moment
  de sa réception, dans le même geste.
- **Une demande d'achat suit deux niveaux** : le manager du demandeur, puis la gestion
  au-delà du seuil. Un manager n'arbitre que ses propres collaborateurs.
- **Un devis accepté devient une facture**, une seule fois.

## Messagerie externe (IMAP/SMTP)

L'administration renseigne, pour chaque membre, son adresse professionnelle, les serveurs
IMAP/SMTP et le mot de passe de la boîte ; le membre les voit sans pouvoir les modifier. Le
mot de passe est **chiffré en base** et n'est jamais réaffiché.

La boîte est ensuite **relevée dans l'espace du membre**, à chaque balayage ou à la demande.
Quatre règles tiennent le reste :

- **la boîte reste la source.** La relève est en lecture seule : elle ne marque rien comme
  lu, ne déplace rien, n'efface rien. Le membre lit la même boîte depuis son propre logiciel,
  et une relève qui marque lui volerait ses non-lus ;
- **l'administration branche, elle ne lit pas.** Les messages relevés n'apparaissent que dans
  l'espace de leur destinataire ;
- **la relève s'éteint d'elle-même** après cinq refus de connexion consécutifs, et prévient
  l'administration — plutôt que d'insister jusqu'à faire verrouiller le compte chez
  l'hébergeur ;
- **la copie locale appartient au membre.** Il l'efface quand il veut sans que sa boîte soit
  touchée, elle figure dans son export de données personnelles, et l'effacement de ses données
  coupe la relève.

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
| `DB_DRIVER` | `sqlite` (défaut) ou `mysql` |
| `DB_HOST` | Hôte MySQL, ou chemin d'une socket Unix (défaut `127.0.0.1`) |
| `DB_PORT` | Port MySQL (défaut `3306`) |
| `DB_NAME` | Nom de la base MySQL |
| `DB_USER`, `DB_PASSWORD` | Identifiants MySQL |
| `LOGIN_RATE_LIMIT` / `GLOBAL_RATE_LIMIT` | Plafonds de requêtes, ajustables pour les tests ou un usage interne intensif |
| `API_RATE_LIMIT` | Requêtes par minute et par adresse sur `/api/v1` (défaut `120`) |
| `UPLOAD_DIR` | Dossier des photos de profil (défaut `data/uploads`) |
| `SESSION_IDLE_MINUTES` | Expiration d'une session inactive (défaut `60`) |
| `SESSION_MAX_HOURS` | Durée de vie absolue d'une session, quelle que soit l'activité (défaut `12`) |
| `CV_DIR` | Dossier des CV déposés (défaut : `cv/` à côté de la base) |
| `VAULT_DIR` | Dossier du coffre-fort (défaut : `coffre/` à côté de la base) |
| `SIGN_DIR` | Dossier des documents mis à la signature (défaut : `parapheur/` à côté de la base) |
| `DOCS_DIR` | Dossier des pièces comptables reçues (défaut : `pieces/` à côté de la base) |
| `BACKUP_DIR` | Dossier des sauvegardes (défaut : `sauvegardes/` à côté de la base) — à recopier hors du serveur |

## Documentation

La documentation publique vit sur **sa propre branche, `documentation`** : un site
statique autonome, destiné à un hébergement séparé. Elle ne partage aucun fichier avec
l'application et n'est servie par aucune route, d'où la séparation — elle se publie
ailleurs, et à son propre rythme.

```bash
git worktree add ../toutadmin-doc documentation
cd ../toutadmin-doc

node build.js                                  # depuis l'instantané produit.json
TOUTADMIN_SOURCE=/chemin/vers/toutadmin node build.js   # en relisant le code
```

Le second mode relit le dépôt — dictionnaires, cas de test, modules, vues — pour que la
documentation ne puisse pas afficher un chiffre que le code contredit, et dépose ce
qu'il a lu dans `produit.json`. **Après une livraison ici, reconstruire ainsi et
committer sur `documentation`.** Voir le `README.md` de cette branche.

**Toute la documentation est sur cette branche-là**, `FONCTIONNALITES.md` compris —
la carte du produit, ce qui est couvert et ce qui ne l'est pas encore. Deux endroits
où chercher, c'en est un de trop.

## Structure

```
src/
  app.js             assemblage de l'application Express (middlewares, montage des routeurs)
  server.js          démarrage du serveur et balayage périodique des contrats échus
  db.js              schéma, migrations, choix du moteur, désactivation des contrats expirés
  db-mysql.js        MySQL sous les traits de better-sqlite3 : prepare, get, all, run, transaction
  sql-mysql.js       traduction du dialecte SQLite vers MySQL, à un seul endroit
  sync-mysql.js      requêtes synchrones sur un fil d'exécution à part (Atomics.wait)
  install.js         état d'installation, secret de session, contrôles d'environnement
  settings.js        réglages de l'instance (nom, langue par défaut, quota de congés)
  security.js        CSRF (dont envois multipart), limitation de débit, verrouillage de compte, nonce CSP
  session-store.js   magasin de sessions en base, révocation par compte
  audit.js           journal d'audit : écriture, filtres, export CSV, purge
  totp.js            codes temporaires RFC 6238, codes de secours
  two-factor.js      mise en service, vérification et politique de double authentification
  file-type.js       contrôle de la signature réelle d'un fichier reçu
  utils.js           helpers partagés (flash, validation, génération de mot de passe)
  timesheet.js       pointage : entrées, heures cumulées, estimation de rémunération
  hr.js              demandes, soldes de congés, fiches de paie
  cse.js             mandats, élections, scrutin anonyme, réunions, avantages
  finance.js         tiers, contrats et préavis, factures, budgets, notes de frais
  resources.js       parc matériel, affectations, salles et réservations
  talent.js          documents, formation, entretiens, recrutement
  projects.js        projets, jalons, tâches, temps passé et rentabilité
  support.js         tickets, files, groupes de support, délais et base de connaissances
  people.js          parcours d'arrivée et de départ, compétences et habilitations
  safety.js          document unique, registre des accidents, protections, visites
  treasury.js        comptes bancaires, rapprochement, prévisionnel, amortissements
  fleet.js           véhicules, échéances et historique d'entretien
  deadlines.js       échéances de tous les espaces, et leur transformation en alertes
  notifications.js   notifications personnelles, dédupliquées par clé
  steering.js        indicateurs de direction, objectifs et résultats clés
  search.js          recherche globale, cloisonnée par droits à la source
  privacy.js         registre des traitements, export et effacement des données
  vault.js           coffre-fort : dépôt scellé, intégrité, codes d'accès après départ
  themes.js          palettes de l'instance et palette en service
  governance.js      réunions, registre des décisions, actions, risques de l'entreprise
  surveys.js         sondages anonymes, seuil d'anonymat, baromètre social
  planning.js        créneaux, roulements, astreintes, conflits avec les absences
  quality.js         non-conformités, actions correctives, efficacité, audits internes
  frontdesk.js       registre des visiteurs et suivi du courrier
  currency.js        devises, taux, conversion vers la devise de tenue des comptes
  billing.js         abonnements et facturation récurrente
  vat.js             calcul et conservation des déclarations de TVA
  csv.js             lecture de fichiers CSV, séparateurs et guillemets
  importer.js        import de masse : contrôle ligne à ligne, écriture tout ou rien
  signing.js         parapheur : empreinte du document, sceau des signatures, circuit
  api-tokens.js      jetons d'API : portées, empreinte, révocation
  webhooks.js        webhooks sortants : signature, file d'attente, réessais
  workflows.js       demandes internes : formulaires, seuils, circuits d'approbation
  export.js          export intégral en JSON, secrets exclus
  invoice-scan.js    lecture d'une facture : montants, dates, identifiants, cohérence
  intake.js          réception des pièces, arbitrage règles/modèle, empreinte
  ai.js              assistance par modèle, porte de confidentialité, un interrupteur par fonction
  assistance-editeur.js
                     demandes ouvertes auprès de l'éditeur, signées par la clé de licence
  mailbox.js         relève IMAP de la boîte aux lettres comptable
  member-mail.js     relève de la boîte professionnelle de chaque membre, en lecture seule
  tar.js             écriture et lecture d'archives tar, chemins contrôlés
  backup.js          sauvegarde complète, vérification d'intégrité, restauration
  secret-store.js    chiffrement AES-256-GCM des secrets rangés en base
  offsite/           externalisation des sauvegardes : FTP/FTPS, Google Drive
  cv.js              réception des CV en mémoire, écriture hors dépôt, extraction PDF/DOCX/texte
  ats.js             critères pondérés, score, seuil, classement des candidatures, CVthèque
  modules.js         modules optionnels : activation, barrière de route, limites
  accounting.js      plan comptable, écritures équilibrées, balance, grand livre
  payroll.js         barèmes, calcul du brut au net, bulletins détaillés
  einvoicing.js      contrôle EN 16931 et génération du XML CII
  facturx.js         Factur-X : mise en page de la facture et assemblage du PDF/A-3
  pdf-writer.js      écriture d'un PDF/A-3 : profil ICC, métadonnées XMP, pièce jointe
  pdf-font.js        police Type 3 écrite à la main, 159 glyphes de tracés
  inventory.js       articles, mouvements, demandes d'achat à deux niveaux
  crm.js             contacts, pipeline, devis, relances
  purchasing.js      bons de commande, réceptions, rapprochement à trois
  dunning.js         balance âgée, paliers de relance, relances consignées
  it.js              parc logiciel, accès applicatifs, incidents du SI
  dev.js             services applicatifs, livraisons, indicateurs de livraison
  events.js          événements d'entreprise, liste d'attente, émargement
  oneonone.js        points individuels : résumé partagé et notes du manager
  partners.js        fiche tiers : interlocuteurs, pièces de conformité, évaluations
  whistleblow.js     alerte interne : chiffrement, référents, suivi anonyme
  corporate.js       capital, mandats sociaux, assemblées, intérêts, cadeaux, délégations
  org.js             services, équipes, encadrement multiple, rattachements
  calendar.js        grille mensuelle, événements personnels et entrées dérivées
  announcements.js   actualités entreprise et équipe
  uploads.js         photos de profil (validation, stockage, suppression)
  i18n.js            négociation de langue, traduction, libellés de statut, sens d'écriture
  locales/           16 dictionnaires (fr de référence, 15 traductions)
  grades.js · contract-types.js · request-types.js   listes blanches métier
  middleware/auth.js contrôle d'accès admin / employé / RH / manager / CSE
  routes/            51 routeurs, un par espace : install · auth · admin · employee · rh
                     manager · profile · directory · messages · cse · agenda · gestion
                     salles · projets · support · connaissances · qualite · planning
                     parcours · sante-securite · sondages · direction · pilotage
                     organigramme · recherche · notifications · demandes · parapheur
                     coffre-fort · securite · rgpd · sauvegardes · integrations · api
                     import · pieces · accueil · flotte · partenaires · evenements
                     informatique · developpement · alertes · juridique
                     comptabilite · paie · facturation-electronique · stock · crm
                     tresorerie · immobilisations
views/               78 écrans et 11 gabarits partagés
  partials/          head, sidebar, navigation membre, en-têtes, avatars, icônes, langues, thème
  install · login · error · admin · rh · employee · manager · profile · directory · messages
  cse · cse-manage · agenda · gestion · rooms · alertes · alerte · alerte-suivi
  juridique · assemblee · commande · partenaire · evenement · logiciel
  comptabilite · paie · einvoicing · stock · crm
  employee-edit · employee-timesheet · tool-edit
public/              feuille de style, thème, chronomètre, agenda, jauges, confirmations
scripts/seed-demo.js jeu de données de démonstration
tests/               suite d'intégration (node --test)
```
