# Ce que le CMS couvre, et ce qu'il ne couvre pas encore

Ce document sert de carte. Il est tenu à jour à chaque lot livré : une ligne cochée
correspond à du code en production dans le dépôt, testé, pas à une intention.

Le périmètre visé est l'administration complète d'une entreprise : direction,
opérations, ressources humaines, finance, moyens généraux, et le socle transverse
qui relie le tout.

---

## Socle transverse

| Fonction | État |
| --- | --- |
| Comptes, rôles, rattachements (services, équipes, managers multiples) | ✅ |
| **Service informatique désigné par l'administration**, distinct de la gestion financière | ✅ |
| Authentification durcie, double authentification, journal d'audit, console de sécurité | ✅ |
| Modules débloquables par l'administration | ✅ |
| Interface en 16 langues, trois palettes au choix de l'administration, mode clair/sombre/système par personne, responsive | ✅ |
| **Statuts traduits** dans les 16 langues, mise en page retournée en arabe | ✅ |
| Traduction de l'intégralité des écrans métier | ✅ |
| Assistant d'installation | ✅ |
| **Échéances et alertes** — un seul moteur pour tout ce qui arrive à terme | ✅ |
| **Centre de notifications** par personne, avec accusé de lecture | ✅ |
| **Recherche globale** sur tous les espaces autorisés | ✅ |
| **Import de données en masse (CSV)** : aperçu contrôlé, écriture tout ou rien | ✅ |
| **Sauvegarde automatique horaire, vérification d'intégrité et restauration** | ✅ |
| **Externalisation des sauvegardes** vers un serveur FTP/FTPS ou Google Drive, avec alerte en cas d'échec | ✅ |
| **Export intégral de l'instance** en JSON, documents compris, secrets exclus | ✅ |
| **API REST de lecture à portées, et webhooks sortants signés** | ✅ |
| **Workflows d'approbation configurables** : formulaires, seuils, validateurs par fonction | ✅ |

## Direction et pilotage

| Fonction | État |
| --- | --- |
| Actualités à portée entreprise / service / équipe | ✅ |
| **Tableau de bord de direction** — effectif, masse salariale, activité, trésorerie | ✅ |
| **Objectifs et résultats clés (OKR)**, déclinés par service et par équipe | ✅ |
| **Réunions : convocation, ordre du jour, présences, compte rendu** | ✅ |
| **Registre des décisions et actions confiées, avec échéance et porteur** | ✅ |
| **Registre des risques : cotation brute et résiduelle, matrice, traitement, revue** | ✅ |
| **Événements d'entreprise : capacité, liste d'attente automatique, émargement, budget** | ✅ |
| **Registre du capital** : associés, mouvements de titres, quotes-parts déduites des mouvements | ✅ |
| **Mandats sociaux** : fonction, durée, révocation, fin de mandat signalée à l'avance | ✅ |
| **Assemblées générales** : convocation, quorum compté en titres, résolutions, votes et procès-verbal | ✅ |

## Opérations

| Fonction | État |
| --- | --- |
| **Projets, jalons et tâches**, avec affectation et tableau par statut | ✅ |
| **Temps passé imputé au projet**, et rentabilité face au budget | ✅ |
| **Tickets et support**, interne comme client, avec priorité et délai de traitement | ✅ |
| **Base de connaissances** interne, par catégorie, avec recherche | ✅ |
| **Qualité : non-conformités, actions correctives, vérification d'efficacité, audits internes** | ✅ |
| **Planning d'équipe, roulements et astreintes, avec détection des conflits** | ✅ |
| **Parc logiciel : licences, sièges tenus comme contrainte, coût annualisé, renouvellements** | ✅ |
| **Revue des accès applicatifs** : comptes fermés, droits d'administration, accès jamais réexaminés | ✅ |
| **Incidents du système d'information**, avec délai moyen de rétablissement mesuré | ✅ |
| **Référentiel des services applicatifs** : criticité, responsable, dépôt, documentation | ✅ |
| **Registre des livraisons** par environnement, et indicateurs de livraison | ✅ |

## Ressources humaines

| Fonction | État |
| --- | --- |
| Congés, absences, soldes, fiches de paie | ✅ |
| Documents d'entreprise avec accusé de réception | ✅ |
| Formation : catalogue, sessions, inscriptions | ✅ |
| Entretiens annuels | ✅ |
| Recrutement, CV et filtrage ATS | ✅ |
| Pointage et rémunération des freelances | ✅ |
| CSE : élections, mandats, réunions, avantages | ✅ |
| **Arrivée et départ : listes de contrôle suivies** | ✅ |
| **Compétences et habilitations, avec échéances de recyclage** | ✅ |
| **Santé et sécurité au travail : risques, accidents, équipements, visites médicales** | ✅ |
| **Sondages internes et baromètre social, anonymes par construction** | ✅ |
| **Organigramme visuel**, encadrement compris, et personnes sans rattachement | ✅ |
| **Coffre-fort numérique : bulletins accessibles après le départ, scellés et conservés 50 ans** | ✅ |
| **Signature électronique simple** des contrats et avenants : circuit ordonné, empreinte, sceau, attestation | ✅ |
| **Points individuels manager-collaborateur** : résumé partagé, notes du manager séparées | ✅ |

## Finance et gestion

| Fonction | État |
| --- | --- |
| Partenaires, contrats, factures, budgets, notes de frais | ✅ |
| **Fiche tiers** : interlocuteurs, pièces de conformité datées, évaluations notées | ✅ |
| Comptabilité en partie double, balance, grand livre | ✅ (module) |
| Moteur de paie | ✅ (module) |
| Facturation électronique (EN 16931, Factur-X) | ✅ (module) |
| Stock et achats, à deux niveaux d'approbation | ✅ (module) |
| CRM, pipeline pondéré, devis | ✅ (module) |
| **Trésorerie : comptes, mouvements, rapprochement, prévisionnel** | ✅ (module) |
| **Immobilisations et amortissements** | ✅ (module) |
| **Déclarations de TVA** : collectée, déductible, ventilation par taux, crédit reportable | ✅ |
| **Facturation récurrente et abonnements**, émis au balayage sans doublon possible | ✅ |
| **Multidevise**, taux figé à l'émission de chaque pièce | ✅ |
| **Lecture automatique des factures reçues** : montants, dates, identifiants vérifiés par leur clé | ✅ |
| **Capture IMAP** d'une boîte aux lettres comptable, pièces jointes analysées | ✅ |
| **Bons de commande, réceptions et rapprochement à trois** (commandé / reçu / facturé) | ✅ (module) |
| **Recouvrement : balance âgée et relances échelonnées**, du rappel à la mise en demeure | ✅ |
| **Analyse assistée par modèle de langage**, optionnelle et éteinte par défaut | ✅ |

## Moyens généraux

| Fonction | État |
| --- | --- |
| Équipements et leur affectation | ✅ |
| Salles et réservations | ✅ |
| **Flotte de véhicules : entretien, contrôle technique, assurance, sinistres** | ✅ |
| **Registre des visiteurs, avec liste des personnes présentes** | ✅ |
| **Courrier entrant et sortant, remise datée et signée** | ✅ |

## Conformité

| Fonction | État |
| --- | --- |
| Journal d'audit horodaté, exportable | ✅ |
| **Journal scellé par chaînage d'empreintes**, l'altération étant localisée à l'entrée près | ✅ |
| **RGPD : registre des traitements, export et effacement des données d'une personne** | ✅ |
| **Dispositif d'alerte interne** : contenu chiffré, référents désignés, suivi anonyme par code, délais légaux comptés | ✅ |
| **Conflits d'intérêts et cadeaux** : chacun déclare pour soi, l'administration examine et note la mesure prise | ✅ |
| **Délégations de pouvoir** : objet, plafond, durée, échéance signalée | ✅ |
| Archivage à valeur probante | ⬜ |

---

## L'état des traductions

Le dictionnaire compte 2 754 clés déclinées dans les 16 langues. Sa cohérence est
tenue par les tests : parité des clés, paramètres `{nom}` identiques d'une langue
à l'autre, aucune valeur vide, et vérification que le russe, l'arabe, le hindi,
le chinois, le japonais et le coréen sont bien écrits dans leur écriture — une
traduction oubliée se repère à ce qu'elle reste en caractères latins.

**Les 78 écrans du produit sont traduits**, comme les 11 gabarits partagés qui
les habillent, du premier écran de connexion à la dernière boîte de dialogue de
confirmation : les quatre espaces, le chrome de chaque page, tous les statuts
affichés, le vocabulaire générique et le vocabulaire propre à chaque métier —
comptabilité, paie, trésorerie, qualité, parapheur, sauvegardes, interfaces,
données personnelles, parc logiciel, livraisons, événements, points individuels,
conformité des tiers, alerte interne, achats, recouvrement et vie juridique.

Ce qui reste en français dans les gabarits n'est pas du texte affiché : les
paramètres d'URL du journal d'audit (`&du=`, `&au=`), l'exemple de commande
`curl` de la documentation d'API, et un commentaire dans le script qui applique
le thème avant le premier rendu.

### Deux règles tenues d'un bout à l'autre

**Les valeurs stockées ne bougent pas.** Un statut est écrit en français dans la
base — c'est la valeur métier, celle qui sert aux contraintes `CHECK`, aux
comparaisons et aux URL de filtre. Seul l'affichage passe par le dictionnaire.
Les attributs `value` d'un `<select>`, les chaînes comparées dans une condition
et les identifiants de filtre gardent leur libellé français.

**Une phrase coupée par une donnée devient une clé paramétrée.** « {count}
échec(s) consécutif(s) », « Pagination par {limit} (200 au plus) et {since} »,
« {total} échéance(s) à 45 jours, dont {overdue} dépassée(s) » : l'ordre des mots
n'est pas le même d'une langue à l'autre, et un nombre collé entre deux fragments
ne se traduit nulle part.

---

## Ce qui a été écarté, et pourquoi

Certaines fonctions ne relèvent pas d'un CMS d'entreprise généraliste et sont
mieux servies par un outil dédié, avec lequel s'interfacer plutôt que de le
réécrire à moitié :

- **Télétransmission fiscale et sociale** (DSN, TVA à la DGFiP) : exige un
  agrément, un format qui change chaque année, et une responsabilité légale.
  Le CMS produit les données ; leur dépôt reste l'affaire d'un tiers déclarant.
- **Édition collaborative de documents** : un traitement de texte partagé est un
  produit à lui seul.
- **Visioconférence et téléphonie**.
- **Signature électronique qualifiée** (eIDAS niveau qualifié) : demande un
  prestataire de confiance certifié.
- **Horodatage qualifié du coffre-fort** : les documents y sont scellés par leur
  empreinte et datés par le serveur, ce qui suffit à détecter une altération.
  Un horodatage opposable à un tiers demande une autorité de certification, et
  la norme NF Z42-020 pour un composant coffre-fort au sens strict.
