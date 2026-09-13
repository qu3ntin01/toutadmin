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
| Socle | base et schéma (143 tables), sessions en base, jeton CSRF, en-têtes, plafonds, journal scellé, 16 langues (2 770 clés) |
| Installation | jeton d'installation exigé avant tout (posé dans la configuration ou l'environnement, comparé à temps constant) pour que l'instance ne soit pas à qui la trouve entre le dépôt des fichiers et l'assistant, vérification de l'hébergement avec prérequis bloquants, entreprise, langue, congés annuels, compte d'administration, fermeture automatique |
| Connexion | mot de passe, double authentification, codes de secours, verrouillage, changement de mot de passe forcé |
| Mon espace | accueil du salarié |
| Annuaire | recherche, masquage décidé par l'administration |
| Mon profil | nom, téléphone, présentation, langue ; messagerie en lecture seule |
| Administration | services, équipes, encadrement, rattachement, annuaire, personnel (création avec mot de passe temporaire, modification, activation, réinitialisation, suppression), messagerie du membre, actualités, outils, affectations, droits transverses, modules, palette et réglages de l'instance |
| Organigramme | services, équipes, rattachements, sans-rattachement ; effectif entier pour l'administration et les RH |
| Congés et paie | demande déposée par le salarié, décompte en jours ouvrés, approbation, refus, annulation avec recrédit du solde, ajustements de solde, fiches de paie |
| Espace du salarié | informations, managers, demandes, solde, fiches de paie, actualités, collègues, outils et accès confiés avec leur identifiant de connexion, matériel confié |
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
| Qualité | non-conformités avec référence annuelle, cause racine, clôture refusée tant qu'une action reste ouverte, actions correctives avec vérification d'efficacité, audits internes, constats et promotion d'un constat en non-conformité |
| Santé et sécurité | document unique coté gravité × probabilité avec seuil d'action, registre des accidents fermé aux saisies à venir, taux de fréquence et de gravité sur douze mois, équipements de protection dont l'échéance découle de la validité, visites médicales et échéances à soixante jours |
| Vie juridique | registre des associés où la détention est la somme des mouvements (une cession écrit les deux côtés, on ne cède pas plus qu'on ne détient), mandats sociaux, assemblées numérotées par année avec quorum lu du capital, résolutions dont la majorité se calcule sur les voix exprimées, procès-verbal qui se rédige sans toucher au reste de la fiche |
| Conformité | déclarations de conflits d'intérêts déposées par chacun pour soi et examinées par l'administration, registre des cadeaux avec seuil d'examen à 150 €, délégations de pouvoir avec plafond et période |
| Direction | réunions avec ordre du jour, participants et présence, compte rendu qui marque la réunion tenue, registre des décisions qui survit à la suppression d'une réunion, actions confiées avec échéance, registre des risques d'entreprise coté probabilité × impact dont la criticité retenue est la résiduelle, matrice 5 × 5 |
| Sondages | questionnaire figé à l'ouverture, réponses sans aucun identifiant de personne (l'anonymat tient à la structure des tables), participation nominative qui empêche de répondre deux fois, résultats retenus sous cinq réponses, baromètre social des sondages clos |
| CSE | mandats et convocations tenus par les RH, élections par phases (candidatures, vote, clôture) qui refusent un scrutin sans candidat validé, bulletin et émargement écrits ensemble mais sans lien entre eux, taux de participation sur le corps électoral, avantages qui disparaissent à leur péremption, comptes rendus rédigés par les élus |
| Informatique | parc logiciel avec sièges tenus comme une contrainte (on n'ouvre pas plus d'accès qu'il n'y en a, et on ne descend pas les sièges sous les accès ouverts), coût annualisé selon la périodicité, revue des accès qui remonte d'abord les comptes fermés gardant leurs habilitations, incidents SI dont la clôture exige l'heure de rétablissement, délai moyen calculé sur les seuls incidents rétablis |
| Développement | référentiel des services applicatifs, version en production déduite de la dernière livraison réussie, registre des livraisons dont le taux d'échec compte aussi celles qu'il a fallu retirer, adresses de dépôt refusées si elles ne sont pas http(s) |
| Flotte | parc de véhicules avec trois échéances qui ne pardonnent pas (contrôle technique, assurance, entretien), compteur qui ne recule jamais — ni à la saisie, ni par un relevé d'événement — et coût d'entretien sur douze mois |
| Accueil | registre des visiteurs qui répond à « qui est dans les murs ? », courrier qui reste « à remettre » tant que la remise n'est pas datée et signée, recommandés comptés à part |
| Recrutement | postes ouverts et candidatures, dépôt de CV dont le contenu est contrôlé avant écriture (PDF, DOCX, TXT, Markdown) et le texte extrait sans aucune dépendance, moteur ATS qui note sur les seuls critères pondérés du poste (un critère requis manquant ou une expérience sous le minimum écartent quel que soit le score), reclassement de tout le poste dès qu'un critère change, CVthèque par mots-clés, CV téléchargeable par la seule route authentifiée |
| Événements | séminaires, formations et réunions générales avec leur portée (entreprise, service, équipe) ; au-delà de la capacité on n'est pas refusé mais mis en liste d'attente, un désistement fait monter le premier qui attend et le prévient, « Complet » est décidé par la capacité et non par l'organisateur, une absence constatée garde la place, une annulation prévient les inscrits |
| Base de connaissances | articles par catégorie, portée appliquée à la lecture (entreprise, service, équipe, administration), brouillon invisible de ses lecteurs, compteur de lectures, recherche par mots-clés |
| Parcours | modèles d'arrivée et de départ dont les points portent un responsable et un écart au jour pivot, parcours daté par personne, cocher le dernier point clôt la liste et en décocher un la rouvre, points en retard remontés ; compétences et habilitations dont l'échéance découle de la durée de validité, matrice, ce qui périme et ce qui manque à l'appel |
| CRM | module optionnel : contacts commerciaux, affaires dont le pipeline pondère le montant par la probabilité, devis contrôlés avant d'exister et dont l'expiration se lit sans se réécrire, conversion d'un devis accepté en facture client — une seule fois —, relances qui visent toujours un client ou une affaire |
| Facturation électronique | module optionnel : contrôle EN 16931 qui liste les manques facture par facture plutôt que de conclure « non conforme », identité de l'émetteur validée dans sa forme (SIREN, TVA, code pays), export du XML CII (UN/CEFACT) qui ne sort que d'une facture conforme |
| Import de données | lecture d'un CSV écrite à la main (point-virgule ou virgule, guillemets, retours à la ligne dans un champ, marque d'ordre des octets d'Excel, en-têtes normalisés), aperçu qui contrôle chaque ligne et nomme son problème avec son numéro, tout ou rien à l'écriture, aucune ligne existante modifiée, mots de passe temporaires rendus une seule fois |
| Fiche tiers | interlocuteurs dont un seul est principal, pièces de conformité dont l'état découle de la date (valable, bientôt périmée à 45 jours, périmée), évaluations notées de 1 à 5 dont c'est la dernière qui fait foi — une moyenne de l'historique lisserait la dégradation qu'on cherche à voir —, contrats et factures du tiers rassemblés |
| Alertes internes | signalement anonyme qui n'enregistre pas son auteur (pas « masqué » : pas enregistré), contenu chiffré en base, référence annuelle et code de suivi montré une seule fois puis gardé haché, suivi ouvert sans compte — se connecter pour lire la réponse, ce serait signer son signalement —, référents seuls à lire (l'administration les désigne et n'y lit rien), délais légaux de 7 et 90 jours comptés, consultations tracées dans le dispositif et non au journal général |
| Pilotage | tableau de bord qui agrège sans rien recalculer (ce qui manque rend une absence, pas un zéro), chiffre d'affaires ramené en devise de référence au taux figé, santé des projets, objectifs et résultats clés dont l'avancement se mesure sur l'échelle de chacun |
| Échéances | toutes les sources de l'instance en une liste homogène — contrats, factures, habilitations, visites médicales, véhicules, actions, mandats, alertes… — et les notifications qui s'en déduisent, rejouables sans jamais alerter deux fois |
| Pièces reçues | corbeille du comptable : dépôt d'une facture dont le type réel est contrôlé avant écriture, empreinte SHA-256 qui interdit le doublon et revérifiée avant de servir le fichier, lecture par règles (numéro, dates, montants, taux, SIRET et IBAN vérifiés par leur clé, rapprochement avec un tiers connu) dont la confiance se compose et tombe si HT + TVA ne fait pas TTC, analyse assistée par modèle optionnelle et éteinte par défaut, relève IMAP d'une boîte dédiée qui ne supprime jamais un message, mise en facture qui enregistre ce qui est validé à l'écran et non ce qui a été lu |
| Photos de profil | envoi contrôlé sur le contenu et non sur le type annoncé, nom de fichier aléatoire, fichier servi par une route qui demande une session et ne sort pas de son dossier, retrait |
| Planning | grille de la semaine par personne, créneau refusé s'il chevauche un autre poste ou une absence accordée, brouillon tant que la semaine n'est pas publiée, roulements appliqués sur trois mois au plus qui sautent les jours en conflit et le disent, poste de nuit terminé le lendemain, astreintes lues à la semaine et à l'instant, charge par personne ; un manager ne planifie que les siens, tout le monde consulte |
| Intégrations | webhooks sortants signés (HMAC-SHA256 du corps, secret chiffré en base et montré une seule fois), adresse interne ou en clair refusée sauf case cochée, file d'attente vidée par la tâche planifiée avec réessais espacés puis abandon, webhook qui s'éteint tout seul après une série d'échecs plutôt que de faire croire que l'information passe ; ouvrir cette porte relève de l'administration seule |
| API v1 | jetons dont seule l'empreinte SHA-256 vit en base, portée explicite (annuaire, RH, gestion, projets, pilotage), expiration et révocation immédiate, lecture seule — un jeton volé lit, il n'écrit ni ne paie ; servie avant toute session, sans cookie ni jeton CSRF, plafonnée à la minute, respectant le retrait de l'annuaire et taisant le motif d'une absence |
| Recherche globale | une requête, tous les espaces ouverts à celui qui la pose : chaque source est interrogée avec ses droits à lui et une source fermée n'est pas interrogée du tout — rien n'est filtré après coup, ce qui fuit ne se rattrape pas à l'affichage |
| Pointage des freelances | commencer et terminer, un seul pointage ouvert à la fois — deux en compteraient les heures deux fois —, durée du pointage en cours mesurée jusqu'à maintenant, taux horaire déduit du TJM sur une base de huit heures, mois en cours compté à part du total ; supervision RH : fiche de temps d'un membre, clôture d'un pointage oublié, suppression d'une entrée fausse |
| Double authentification | mise en service depuis son profil : secret préparé mais inactif tant qu'un premier code n'est pas validé — sans quoi une application mal réglée enfermerait la personne dehors —, code QR dessiné dans la page (codeur écrit à la main, aucune image distante), saisie manuelle possible, huit codes de secours affichés une seule fois puis conservés hachés, à usage unique et regénérables ; le retrait redemande le mot de passe et reste impossible si l'entreprise l'exige pour le rôle ; fermeture de toutes ses sessions, la sienne comprise |
| Parc de salles | tenu par la gestion : création (nom unique, capacité bornée), ouverture et fermeture qui ne touchent pas aux réservations posées, suppression qui emporte les siennes, et libération d'une réservation qui n'est pas la sienne |

555 tests passent (`php tests/run.php`), et `php tools/check-keys.php` vérifie
qu'aucun écran n'emploie une clé de traduction absente des dictionnaires.

## À porter

Plus rien de connu. La comparaison ne porte plus sur les espaces mais sur
chaque point d'entrée : les 531 routes de l'édition Node ont été confrontées
une à une aux 513 de celle-ci. Les 28 qui n'ont pas d'équivalent littéral
sont des différences de forme, vérifiées une par une :

| Édition Node | Ici |
| --- | --- |
| les neuf ressources de `/api/v1/…` | une seule route `/api/v1/{ressource}` |
| `/installation/{étape}` et ses cinq POST | un seul assistant, `/installation` |
| `/admin/rh/nommer`, `/admin/gestion/nommer`… | `/admin/droits/{droit}` |
| `/mon-profil/informations` | `POST /mon-profil` |
| `/mon-profil/mot-de-passe`, `/mon-profil/premier-acces` | `/mot-de-passe`, où le noyau conduit d'office tant qu'un mot de passe temporaire est en place |
| `/notifications/tout-lu` | `/notifications/tout-lire` |

La comparaison a ensuite porté sur les fonctions elles-mêmes : les 1 161 noms
exportés par les 72 modules de l'édition Node, confrontés aux classes d'ici.
Ce qui restait sans correspondance était, à une exception près, une différence
de nom (`list` devient `all`, `getEntries` devient `entries`, `assetById`
devient `Assets::byId`) ou une notion sans objet en PHP (les intergiciels
d'Express, le magasin de sessions). L'exception était le jeton d'installation,
maintenant porté.

Enfin la comparaison a porté sur les écrans : les clés de traduction employées
par chacun des 79 gabarits de l'édition Node, cherchées dans toutes les sources
d'ici. Là encore, l'essentiel de l'écart était du vocabulaire — mais quatre
blocs manquaient vraiment, et ils sont posés : les outils et accès confiés au
salarié (avec l'identifiant de connexion qu'on lui a donné), le matériel qui
lui est remis, le filtre par statut de la liste des demandes RH, et le lien qui
mène les anciens salariés au coffre-fort depuis l'écran de connexion — une
fonction qu'on n'atteint pas est une fonction qui n'existe pas.

Ce que l'une sait faire, l'autre le sait. Ce qui sera ajouté à l'une le sera
à l'autre.

## Un défaut du socle PHP, corrigé

PDO envoie tous les paramètres en texte par défaut, et SQLite range le texte
après les nombres. `max(compteur, ?)` rendait donc la chaîne plutôt que le plus
grand des deux — le kilométrage d'un véhicule reculait sur un relevé inférieur,
ce que le module refuse précisément de faire. Les paramètres sont maintenant
liés un par un avec leur type (`PDO::PARAM_INT` pour un entier), ce qui remet
d'aplomb toutes les comparaisons numériques, pas seulement celle-là.

## Les statuts s'affichent maintenant dans la langue de la page

Les statuts sont stockés en français — c'est la langue de référence du produit,
et une base ne se traduit pas. L'édition Node les traduisait à l'affichage par
une table de correspondance ; l'édition PHP ne l'avait pas, et montrait
« Clôturée » au milieu d'une page en coréen.

`I18n::status()` et l'aide `st()` portent cette table (167 statuts), et les
écrans déjà portés s'en servent : libellés d'options, étiquettes d'état,
partout où un statut est lu par quelqu'un. La valeur envoyée par le formulaire,
elle, reste française — c'est elle qui va en base.

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

Trois fonctions de l'édition Node tenaient à Node lui-même. Deux sont réglées,
la troisième reste à faire :

- **Sauvegardes automatiques périodiques** → fait : `tools/cron.php` est appelé
  par la tâche planifiée de l'hébergeur et déclenche la sauvegarde dès
  l'intervalle écoulé, un site PHP ne tournant qu'au moment d'une requête. La
  copie de la base passe par `VACUUM INTO`, cohérente sur une base en écriture,
  là où l'édition Node utilise l'API de sauvegarde en ligne de SQLite.
- **Lecture de PDF** (`pdf-parse`) → fait, sans extension ni binaire externe :
  `App\Core\Pdf` parcourt les flux du document, décompresse ceux qui le sont
  (`/FlateDecode`) et lit les opérateurs de texte. Un PDF scanné ne contient
  pas de texte : l'extraction revient vide, et l'écran le dit plutôt que de
  noter un dossier sur du vide — c'est aussi ce que fait l'édition Node.
- **Relève de courrier IMAP** (`imapflow`) → fait, et sans l'extension `imap` de
  PHP — absente de beaucoup d'hébergements mutualisés, et dépréciée depuis
  PHP 8.3. `App\Core\Imap` parle le protocole directement (ouvrir un dossier,
  chercher les non-lus, télécharger, poser un drapeau, déplacer) et
  `App\Core\Mime` lit le message reçu. Le client n'expose ni EXPUNGE global ni
  suppression : la boîte reste la source. La relève automatique passe par
  `tools/cron.php`, un site PHP ne tournant qu'au moment d'une requête.

Les trois sont donc réglées.
