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
| Authentification durcie, double authentification, journal d'audit, console de sécurité | ✅ |
| Modules débloquables par l'administration | ✅ |
| Interface en 16 langues, trois palettes au choix de l'administration, mode clair/sombre/système par personne, responsive | ✅ |
| **Statuts traduits** dans les 16 langues, mise en page retournée en arabe | ✅ |
| Traduction de l'intégralité des écrans métier | ⬜ |
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

## Opérations

| Fonction | État |
| --- | --- |
| **Projets, jalons et tâches**, avec affectation et tableau par statut | ✅ |
| **Temps passé imputé au projet**, et rentabilité face au budget | ✅ |
| **Tickets et support**, interne comme client, avec priorité et délai de traitement | ✅ |
| **Base de connaissances** interne, par catégorie, avec recherche | ✅ |
| **Qualité : non-conformités, actions correctives, vérification d'efficacité, audits internes** | ✅ |
| **Planning d'équipe, roulements et astreintes, avec détection des conflits** | ✅ |

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

## Finance et gestion

| Fonction | État |
| --- | --- |
| Partenaires, contrats, factures, budgets, notes de frais | ✅ |
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
| **RGPD : registre des traitements, export et effacement des données d'une personne** | ✅ |
| Archivage à valeur probante | ⬜ |

---

## L'état des traductions

Le dictionnaire compte 415 clés déclinées dans les 16 langues. Sa cohérence est
tenue par les tests : parité des clés, paramètres `{nom}` identiques d'une langue
à l'autre, aucune valeur vide, et vérification que le russe, l'arabe, le hindi,
le chinois, le japonais et le coréen sont bien écrits dans leur écriture — une
traduction oubliée se repère à ce qu'elle reste en caractères latins.

Ce qui est traduit de bout en bout : l'écran de connexion, l'assistant
d'installation, l'espace collaborateur, l'espace RH, la console d'administration,
le chrome présent sur chaque page (en-tête, compte, déconnexion, sélecteur de
thème, navigation latérale des quatre espaces) et **tous les statuts** affichés
dans le produit.

Ce qui ne l'est pas encore : le corps des écrans ajoutés lors des derniers lots
— direction, planning, qualité, accueil, parapheur, pièces reçues, trésorerie,
immobilisations, flotte, CRM, projets, sauvegardes, interfaces. Cela représente
**1 745 chaînes visibles réparties sur 64 des 76 vues**. Elles s'affichent en
français quelle que soit la langue choisie ; rien n'est cassé, mais un acheteur
allemand ou japonais verra ces pages dans une langue qui n'est pas la sienne.
C'est un travail de traduction volumineux, à mener écran par écran plutôt qu'en
une passe automatique : une traduction approximative dans un produit vendu coûte
plus cher que son absence.

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
