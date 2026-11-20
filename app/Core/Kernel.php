<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\ActionsMasseController;
use App\Controllers\KanbanController;
use App\Controllers\AdminController;
use App\Controllers\AccountingController;
use App\Controllers\AgendaController;
use App\Controllers\AlertsController;
use App\Controllers\CrmController;
use App\Controllers\VentesController;
use App\Controllers\EInvoicingController;
use App\Controllers\ApiController;
use App\Controllers\LicenceController;
use App\Controllers\ImportController;
use App\Controllers\IntegrationsController;
use App\Controllers\EventsController;
use App\Controllers\JourneysController;
use App\Controllers\KnowledgeController;
use App\Controllers\PartnersController;
use App\Controllers\SocietesController;
use App\Controllers\SteeringController;
use App\Controllers\PiecesController;
use App\Controllers\PlanningController;
use App\Controllers\AuthController;
use App\Controllers\BackupController;
use App\Controllers\CseController;
use App\Controllers\DevController;
use App\Controllers\DirectoryController;
use App\Controllers\FixedAssetsController;
use App\Controllers\FinanceController;
use App\Controllers\FleetController;
use App\Controllers\FrontDeskController;
use App\Controllers\GovernanceController;
use App\Controllers\HrController;
use App\Controllers\LegalController;
use App\Controllers\LogsController;
use App\Controllers\SectionController;
use App\Controllers\StoreController;
use App\Controllers\UpdatesController;
use App\Controllers\ManagerController;
use App\Controllers\InstallController;
use App\Controllers\ItController;
use App\Controllers\MemberController;
use App\Controllers\MessagesController;
use App\Controllers\NotificationsController;
use App\Controllers\OrgChartController;
use App\Controllers\PayrollController;
use App\Controllers\PrivacyController;
use App\Controllers\ProfileController;
use App\Controllers\ProjectsController;
use App\Controllers\QualityController;
use App\Controllers\RequestsController;
use App\Controllers\RoomsController;
use App\Controllers\SafetyController;
use App\Controllers\SearchController;
use App\Controllers\SecurityController;
use App\Controllers\StockController;
use App\Controllers\SigningController;
use App\Controllers\SupportController;
use App\Controllers\SurveysController;
use App\Controllers\TreasuryController;
use App\Controllers\VaultController;
use App\Modules\Themes;
use App\Modules\Users;

/**
 * Le passage obligé de chaque requête.
 *
 * Session, langue, jeton anti-falsification, en-têtes de sécurité, plafond de
 * requêtes, expiration : tout est fait ici, avant la route. Une route ne peut
 * donc pas oublier un contrôle — elle n'a pas le moyen de le sauter.
 */
final class Kernel
{
    /** Les routes postées sans jeton CSRF ; la raison est écrite là où le jeton se vérifie. */
    public const SANS_JETON = ['/connexion/saml/acs'];

    private Router $router;

    public function __construct()
    {
        $this->router = new Router();
        $this->routes();
    }

    public function router(): Router
    {
        return $this->router;
    }

    private function routes(): void
    {
        $router = $this->router;

        // Installation : ouverte tant qu'aucun compte n'existe, fermée après.
        // L'adresse a raccourci : « /install ». C'est la seule du produit qu'on
        // tape vraiment — celle qu'on donne au client au téléphone —, et
        // « installation » n'y ajoutait rien. Les anciens liens ne tombent pas
        // pour autant : ils mènent ici, en permanence.
        $router->get('/installation', static fn (): Response => Response::redirect('/install', 301));
        $router->get('/installation/{etape}', static fn ($r, array $p): Response
            => Response::redirect('/install/' . $p['etape'], 301));

        $router->get('/install', InstallController::home(...));
        $router->get('/install/{etape}', InstallController::form(...));
        $router->post('/install/{etape}', InstallController::submit(...));
        // Les paliers de la pose, appelés un par un : la barre d'avancement
        // suit ce qui a réellement eu lieu.
        // Éprouver la licence sans rien engager : répond en JSON, le navigateur
        // montre le résultat sans quitter la page. Deux segments, comme
        // « /install/executer/{palier} » : « /install/{etape} » n'en prend
        // qu'un et ne l'attrape donc pas.
        $router->post('/install/licence/verifier', InstallController::verifierLicence(...));
        $router->post('/install/mises-a-jour/verifier', InstallController::verifierMaj(...));
        $router->post('/install/mises-a-jour/poser', InstallController::poserLaMaj(...));
        $router->post('/install/executer/{palier}', InstallController::executer(...));

        $router->get('/', AuthController::home(...));
        // Le manifeste et l'icône : publics, à la marque de l'instance.
        $router->get('/manifest.webmanifest', static fn (): Response => Response::text(
            (string) json_encode(\App\Modules\Installable::manifeste(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        )->withHeaders(['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=3600']));
        $router->get('/icone.svg', static fn (): Response => Response::text(\App\Modules\Installable::icone())
            ->withHeaders(['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'public, max-age=3600']));
        $router->get('/connexion', AuthController::loginForm(...));
        $router->post('/connexion', AuthController::login(...));
        $router->get('/connexion/code', AuthController::totpForm(...));
        $router->post('/connexion/code', AuthController::totpSubmit(...));
        $router->post('/connexion/cle', AuthController::keySubmit(...));
        $router->get('/connexion/sso', AuthController::ssoStart(...));
        $router->get('/connexion/sso/retour', AuthController::ssoReturn(...));
        $router->get('/saml/metadata', AuthController::samlMetadata(...));
        $router->get('/connexion/saml', AuthController::samlStart(...));
        $router->post('/connexion/saml/acs', AuthController::samlAcs(...));
        $router->post('/deconnexion', AuthController::logout(...));
        /* Hors de « /admin », et c'est volontaire : pendant l'emprunt, la session
           est celle d'un salarié, et « /admin » lui est fermé. Enfermer la sortie
           derrière un droit que l'on vient de quitter serait enfermer
           l'administrateur dans l'identité qu'il a emprunté. */
        $router->post('/emprunt/retour', AuthController::endImpersonation(...));

        /* Le périmètre : en changer est ouvert à qui a plusieurs sociétés ; les
           administrer est réservé à l'administration, et le contrôleur le
           vérifie lui-même — la route, elle, doit rester hors de « /admin »
           pour que le changement de société reste à portée d'un salarié. */
        $router->post('/societes/choisir', SocietesController::choose(...));
        $router->post('/societes', SocietesController::create(...));
        $router->post('/societes/{id}/modifier', SocietesController::update(...));
        $router->post('/societes/{id}/etat', SocietesController::toggle(...));
        $router->post('/societes/{id}/membres', SocietesController::attach(...));
        $router->post('/societes/{id}/membres/retirer', SocietesController::detach(...));
        $router->get('/mot-de-passe', AuthController::passwordForm(...));
        $router->post('/mot-de-passe', AuthController::passwordSubmit(...));
        $router->post('/langue', AuthController::switchLocale(...));

        // Administration : le noyau exige le rôle « admin » sur tout /admin,
        // plutôt que de laisser chaque route s'en souvenir.
        $router->get('/admin', AdminController::home(...));
        $router->post('/admin/services', AdminController::createDepartment(...));
        $router->post('/admin/services/{id}/modifier', AdminController::updateDepartment(...));
        $router->post('/admin/services/{id}/supprimer', AdminController::deleteDepartment(...));
        $router->post('/admin/equipes', AdminController::createTeam(...));
        $router->post('/admin/equipes/{id}/modifier', AdminController::updateTeam(...));
        $router->post('/admin/equipes/{id}/supprimer', AdminController::deleteTeam(...));
        $router->post('/admin/encadrement', AdminController::addManager(...));
        $router->post('/admin/encadrement/retirer', AdminController::removeManager(...));
        $router->post('/admin/employes', AdminController::createEmployee(...));
        $router->get('/admin/employes/{id}/modifier', AdminController::editEmployee(...));
        $router->post('/admin/employes/{id}/modifier', AdminController::updateEmployee(...));
        $router->post('/admin/employes/{id}/rattachement', AdminController::assignMembership(...));
        $router->post('/admin/employes/{id}/annuaire', AdminController::toggleDirectory(...));
        $router->post('/admin/employes/{id}/messagerie', AdminController::updateMailbox(...));
        $router->post('/admin/employes/{id}/messagerie/essai', AdminController::testMailbox(...));
        $router->post('/admin/employes/{id}/messagerie/relever', AdminController::fetchMailbox(...));
        $router->post('/admin/employes/{id}/statut', AdminController::toggleEmployee(...));
        $router->post('/admin/employes/{id}/reinitialiser', AdminController::resetEmployeePassword(...));
        $router->post('/admin/employes/{id}/emprunter', AdminController::impersonate(...));
        $router->post('/admin/employes/{id}/supprimer', AdminController::deleteEmployee(...));
        $router->post('/admin/actualites', AdminController::createNews(...));
        $router->post('/admin/actualites/{id}/supprimer', AdminController::deleteNews(...));
        $router->post('/admin/outils', AdminController::createTool(...));
        $router->get('/admin/outils/{id}/modifier', AdminController::editTool(...));
        $router->post('/admin/outils/{id}/modifier', AdminController::updateTool(...));
        $router->post('/admin/outils/{id}/supprimer', AdminController::deleteTool(...));
        $router->post('/admin/affectations', AdminController::assign(...));
        $router->post('/admin/affectations/{id}/supprimer', AdminController::unassign(...));
        $router->post('/admin/droits/{flag}', AdminController::grantFlag(...));
        $router->post('/admin/droits/{flag}/{id}/retirer', AdminController::revokeFlag(...));
        $router->post('/admin/modules/{key}', AdminController::setModule(...));
        $router->post('/admin/apparence', AdminController::setPalette(...));
        $router->post('/admin/entreprise', AdminController::setCompany(...));
        // Les champs que l'entreprise ajoute elle-même aux fiches du produit.
        $router->post('/admin/champs', AdminController::createField(...));
        $router->post('/admin/champs/balayer', AdminController::sweepFields(...));
        $router->post('/admin/corbeille/{id}/restaurer', AdminController::restoreTrash(...));
        $router->post('/admin/corbeille/{id}/supprimer', AdminController::dropTrash(...));
        $router->post('/admin/champs/{id}/modifier', AdminController::updateField(...));
        $router->post('/admin/champs/{id}/basculer', AdminController::toggleField(...));
        $router->post('/admin/champs/{id}/supprimer', AdminController::deleteField(...));

        // Espace RH : congés, soldes, fiches de paie.
        $router->get('/rh', HrController::home(...));
        // Pointage des freelances : consulté et corrigé par les RH.
        $router->get('/rh/temps/{id}', HrController::timesheet(...));
        $router->post('/rh/temps/{id}/cloturer', HrController::closeTimeEntry(...));
        $router->post('/rh/temps/{id}/{entree}/supprimer', HrController::deleteTimeEntry(...));
        $router->post('/rh/demandes/{id}/approuver', HrController::approve(...));
        $router->post('/rh/demandes/{id}/refuser', HrController::reject(...));
        $router->post('/rh/demandes/{id}/annuler', HrController::revoke(...));
        $router->post('/rh/solde/{id}/ajuster', HrController::adjustBalance(...));
        $router->post('/rh/paie', HrController::createPayslip(...));
        $router->post('/rh/paie/{id}/marquer-payee', HrController::markPayslipPaid(...));
        $router->post('/rh/paie/{id}/supprimer', HrController::deletePayslip(...));

        $router->post('/rh/documents', HrController::createDocument(...));
        $router->post('/rh/documents/{id}/supprimer', HrController::deleteDocument(...));
        $router->post('/rh/formations', HrController::createTraining(...));
        $router->post('/rh/formations/{id}/supprimer', HrController::deleteTraining(...));
        $router->post('/rh/sessions', HrController::createSession(...));
        $router->post('/rh/sessions/{id}/statut', HrController::setSessionStatus(...));
        $router->post('/rh/sessions/{id}/supprimer', HrController::deleteSession(...));
        $router->post('/rh/inscriptions/{id}/statut', HrController::reviewRegistration(...));
        $router->post('/rh/entretiens', HrController::createReview(...));
        $router->post('/rh/entretiens/{id}/conclure', HrController::completeReview(...));
        $router->post('/rh/entretiens/{id}/annuler', HrController::cancelReview(...));
        $router->post('/rh/entretiens/{id}/supprimer', HrController::deleteReview(...));

        // Recrutement : postes, candidatures, critères de filtrage et CV.
        $router->post('/rh/postes', HrController::createOpening(...));
        $router->post('/rh/postes/{id}/statut', HrController::setOpeningStatus(...));
        $router->post('/rh/postes/{id}/supprimer', HrController::deleteOpening(...));
        $router->post('/rh/postes/{id}/ats', HrController::setOpeningAts(...));
        $router->post('/rh/postes/{id}/criteres', HrController::createCriterion(...));
        $router->post('/rh/criteres/{id}/supprimer', HrController::deleteCriterion(...));
        $router->post('/rh/candidats', HrController::createCandidate(...));
        $router->post('/rh/candidats/{id}/etape', HrController::setCandidateStage(...));
        $router->post('/rh/candidats/{id}/experience', HrController::setCandidateExperience(...));
        $router->post('/rh/candidats/{id}/supprimer', HrController::deleteCandidate(...));
        $router->post('/rh/candidats/{id}/cv', HrController::uploadCv(...));
        $router->get('/rh/candidats/{id}/cv', HrController::downloadCv(...));
        $router->post('/rh/candidats/{id}/cv/supprimer', HrController::deleteCv(...));

        // Le CSE, côté employeur : les mandats, les scrutins et les convocations.
        $router->post('/rh/cse/mandats', HrController::addCseMandate(...));
        $router->post('/rh/cse/mandats/{id}/retirer', HrController::removeCseMandate(...));
        $router->post('/rh/cse/elections', HrController::createCseElection(...));
        $router->post('/rh/cse/elections/{id}/statut', HrController::setCseElectionStatus(...));
        $router->post('/rh/cse/elections/{id}/supprimer', HrController::deleteCseElection(...));
        $router->post('/rh/cse/candidatures/{id}/statut', HrController::reviewCseCandidacy(...));
        $router->post('/rh/cse/reunions', HrController::createCseMeeting(...));
        $router->post('/rh/cse/reunions/{id}/supprimer', HrController::deleteCseMeeting(...));

        $router->post('/mon-espace/demandes', MemberController::createRequest(...));
        // Pointage : deux gestes, réservés au freelance pour lui-même.
        $router->post('/mon-espace/pointage/commencer', MemberController::clockIn(...));
        $router->post('/mon-espace/pointage/terminer', MemberController::clockOut(...));
        $router->post('/mon-espace/documents/{id}/accuser', MemberController::acknowledgeDocument(...));
        $router->post('/mon-espace/formations/{id}/inscription', MemberController::requestSeat(...));
        $router->post('/mon-espace/formations/{id}/annuler', MemberController::cancelSeat(...));
        $router->post('/mon-espace/entretiens/{id}/commentaire', MemberController::commentReview(...));
        $router->post('/mon-espace/demandes/{id}/annuler', MemberController::cancelRequest(...));

        // Demandes internes : circuits d'approbation configurables.
        $router->get('/demandes', RequestsController::index(...));
        $router->post('/demandes', RequestsController::submit(...));
        $router->post('/demandes/types', RequestsController::createForm(...));
        $router->post('/demandes/types/{id}/etapes', RequestsController::addStep(...));
        $router->post('/demandes/types/{id}/etapes/{stepId}/supprimer', RequestsController::deleteStep(...));
        $router->post('/demandes/types/{id}/etat', RequestsController::setFormActive(...));
        $router->post('/demandes/types/{id}/supprimer', RequestsController::deleteForm(...));
        $router->get('/demandes/{id}', RequestsController::show(...));
        $router->post('/demandes/{id}/decision', RequestsController::decide(...));
        $router->post('/demandes/{id}/annuler', RequestsController::cancel(...));

        // Espace manager : équipe, absences, points individuels, actualités.
        $router->get('/mon-equipe', ManagerController::home(...));
        $router->post('/mon-equipe/actualites', ManagerController::publish(...));
        $router->post('/mon-equipe/actualites/{id}/supprimer', ManagerController::deleteNews(...));
        $router->post('/mon-equipe/points', ManagerController::createPoint(...));
        $router->post('/mon-equipe/points/{id}/modifier', ManagerController::updatePoint(...));
        $router->post('/mon-equipe/points/{id}/supprimer', ManagerController::deletePoint(...));

        // Agenda et salles.
        $router->get('/agenda', AgendaController::index(...));
        $router->post('/agenda', AgendaController::create(...));
        $router->post('/agenda/{id}/partage', AgendaController::share(...));
        $router->post('/agenda/{id}/supprimer', AgendaController::delete(...));
        // L'API : pas de session, pas de cookie, un jeton en en-tête. Les routes
        // se construisent depuis le manifeste, pas à la main — une fonction
        // ajoutée là-bas apparaît ici sans que personne y pense.
        ApiController::enregistrer($router);

        // Intégrations : jetons d'API et webhooks sortants.
        $router->get('/integrations', IntegrationsController::index(...));
        $router->post('/integrations/jetons', IntegrationsController::createToken(...));
        $router->post('/integrations/jetons/{id}/revoquer', IntegrationsController::revokeToken(...));
        $router->post('/integrations/jetons/{id}/supprimer', IntegrationsController::deleteToken(...));
        $router->post('/integrations/webhooks', IntegrationsController::createWebhook(...));
        $router->post('/integrations/webhooks/{id}/etat', IntegrationsController::setWebhookState(...));
        $router->post('/integrations/webhooks/{id}/supprimer', IntegrationsController::deleteWebhook(...));
        $router->post('/integrations/webhooks/{id}/tester', IntegrationsController::testWebhook(...));
        $router->post('/integrations/paiement', IntegrationsController::savePaiement(...));
        $router->post('/integrations/assistance/reglages', IntegrationsController::saveAssistance(...));
        $router->post('/integrations/assistance/tester', IntegrationsController::testAssistance(...));

        // Import de données en masse : aperçu d'abord, écriture ensuite.
        $router->get('/import', ImportController::index(...));
        $router->post('/import/apercu', ImportController::preview(...));
        $router->post('/import/importer', ImportController::commit(...));
        $router->get('/import/{key}/modele.csv', ImportController::template(...));

        // Facturation électronique : contrôle EN 16931 et export du XML CII.
        $router->get('/facturation-electronique', EInvoicingController::index(...));
        $router->post('/facturation-electronique/emetteur', EInvoicingController::saveIssuer(...));
        $router->get('/facturation-electronique/factures/{id}.xml', EInvoicingController::xml(...));
        $router->get('/facturation-electronique/factures/{id}.pdf', EInvoicingController::facturx(...));

        // CRM : module optionnel, du ressort de la gestion.
        $router->get('/crm', CrmController::index(...));
        $router->post('/crm/contacts', CrmController::createContact(...));
        $router->post('/crm/contacts/{id}/supprimer', CrmController::deleteContact(...));
        $router->post('/crm/opportunites', CrmController::createOpportunity(...));
        $router->post('/crm/opportunites/{id}/etape', CrmController::setStage(...));
        $router->post('/crm/opportunites/{id}/supprimer', CrmController::deleteOpportunity(...));
        $router->post('/crm/devis', CrmController::createQuote(...));
        $router->post('/crm/devis/{id}/statut', CrmController::setQuoteStatus(...));
        $router->post('/crm/devis/{id}/facturer', CrmController::invoiceQuote(...));
        $router->post('/crm/devis/{id}/commander', VentesController::orderFromQuote(...));
        // Le cycle de vente : catalogue, commandes, livraisons, facturation.
        $router->post('/crm/produits', VentesController::createProduct(...));
        $router->post('/crm/produits/{id}/basculer', VentesController::toggleProduct(...));
        $router->post('/crm/produits/{id}/supprimer', VentesController::deleteProduct(...));
        $router->post('/crm/commandes', VentesController::createOrder(...));
        $router->get('/crm/commandes/{id}', VentesController::show(...));
        $router->post('/crm/commandes/{id}/lignes', VentesController::addLine(...));
        $router->post('/crm/commandes/lignes/{id}/retirer', VentesController::removeLine(...));
        $router->post('/crm/commandes/{id}/confirmer', VentesController::confirm(...));
        $router->post('/crm/commandes/{id}/annuler', VentesController::cancel(...));
        $router->post('/crm/commandes/{id}/livrer', VentesController::deliver(...));
        $router->post('/crm/commandes/{id}/facturer', VentesController::invoice(...));
        $router->get('/crm/livraisons/{id}', VentesController::deliveryNote(...));
        $router->post('/crm/devis/{id}/supprimer', CrmController::deleteQuote(...));
        $router->post('/crm/relances', CrmController::createActivity(...));
        $router->post('/crm/relances/{id}/faite', CrmController::completeActivity(...));
        $router->post('/crm/relances/{id}/supprimer', CrmController::deleteActivity(...));

        // Pilotage : tableau de bord, échéances, objectifs.
        $router->get('/pilotage', SteeringController::index(...));
        $router->post('/pilotage/objectifs', SteeringController::createObjective(...));
        $router->post('/pilotage/objectifs/{id}/statut', SteeringController::setObjectiveStatus(...));
        $router->post('/pilotage/objectifs/{id}/supprimer', SteeringController::deleteObjective(...));
        $router->post('/pilotage/objectifs/{id}/resultats', SteeringController::addKeyResult(...));
        $router->post('/pilotage/resultats/{id}', SteeringController::updateKeyResult(...));
        $router->post('/pilotage/resultats/{id}/supprimer', SteeringController::deleteKeyResult(...));

        // Dispositif d'alerte interne. Le suivi se fait sans compte : se
        // connecter pour lire la réponse, ce serait signer son signalement.
        $router->get('/alertes/suivi', AlertsController::followForm(...));
        $router->post('/alertes/suivi', AlertsController::follow(...));
        $router->post('/alertes/suivi/message', AlertsController::followMessage(...));
        $router->get('/alertes', AlertsController::index(...));
        $router->post('/alertes/signalements', AlertsController::file(...));
        $router->get('/alertes/signalements/{id}', AlertsController::show(...));
        $router->post('/alertes/signalements/{id}/accuser', AlertsController::acknowledge(...));
        $router->post('/alertes/signalements/{id}/statut', AlertsController::setStatus(...));
        $router->post('/alertes/signalements/{id}/suites', AlertsController::setOutcome(...));
        $router->post('/alertes/signalements/{id}/message', AlertsController::reply(...));

        // Fiche d'un tiers : interlocuteurs, conformité, évaluations.
        $router->post('/partenaires/contacts/{id}/principal', PartnersController::setPrimaryContact(...));
        $router->post('/partenaires/contacts/{id}/supprimer', PartnersController::removeContact(...));
        $router->post('/partenaires/pieces/{id}/supprimer', PartnersController::removeDocument(...));
        $router->post('/partenaires/evaluations/{id}/supprimer', PartnersController::removeReview(...));
        $router->get('/partenaires/{id}', PartnersController::show(...));
        $router->post('/partenaires/{id}/banque', PartnersController::bank(...));
        $router->post('/partenaires/{id}/mandats', PartnersController::createMandate(...));
        $router->post('/partenaires/mandats/{id}/revoquer', PartnersController::revokeMandate(...));
        $router->post('/partenaires/{id}/contacts', PartnersController::addContact(...));
        $router->post('/partenaires/{id}/pieces', PartnersController::addDocument(...));
        $router->post('/partenaires/{id}/evaluations', PartnersController::addReview(...));
        $router->post('/partenaires/{id}/champs', PartnersController::saveFields(...));

        // Parcours d'arrivée et de départ, compétences et habilitations.
        $router->get('/parcours', JourneysController::index(...));
        $router->post('/parcours/modeles', JourneysController::createTemplate(...));
        $router->post('/parcours/modeles/points/{id}/supprimer', JourneysController::deleteTemplateItem(...));
        $router->post('/parcours/modeles/{id}/points', JourneysController::addTemplateItem(...));
        $router->post('/parcours/modeles/{id}/supprimer', JourneysController::deleteTemplate(...));
        $router->post('/parcours/listes', JourneysController::startChecklist(...));
        $router->post('/parcours/listes/points/{id}/basculer', JourneysController::toggleItem(...));
        $router->get('/parcours/listes/{id}', JourneysController::showChecklist(...));
        $router->post('/parcours/listes/{id}/supprimer', JourneysController::deleteChecklist(...));
        $router->post('/parcours/competences', JourneysController::createSkill(...));
        $router->post('/parcours/competences/attribuer', JourneysController::grantSkill(...));
        $router->post('/parcours/competences/{id}/supprimer', JourneysController::deleteSkill(...));
        $router->post('/parcours/competences/{skillId}/retirer/{userId}', JourneysController::revokeSkill(...));

        // Base de connaissances : tout le monde lit, l'encadrement rédige.
        $router->get('/base-de-connaissances', KnowledgeController::index(...));
        $router->post('/base-de-connaissances', KnowledgeController::create(...));
        $router->get('/base-de-connaissances/{id}', KnowledgeController::show(...));
        $router->post('/base-de-connaissances/{id}/modifier', KnowledgeController::update(...));
        $router->post('/base-de-connaissances/{id}/supprimer', KnowledgeController::delete(...));

        // Événements d'entreprise : inscriptions, liste d'attente, émargement.
        $router->get('/evenements', EventsController::index(...));
        $router->post('/evenements', EventsController::create(...));
        $router->post('/evenements/inscriptions/{id}/emargement', EventsController::markAttendance(...));
        $router->get('/evenements/{id}', EventsController::show(...));
        $router->post('/evenements/{id}/modifier', EventsController::update(...));
        $router->post('/evenements/{id}/supprimer', EventsController::delete(...));
        $router->post('/evenements/{id}/inscrire', EventsController::registerSomeone(...));
        $router->post('/evenements/{id}/inscription', EventsController::register(...));
        $router->post('/evenements/{id}/desistement', EventsController::withdraw(...));

        // Photo de profil : l'envoi, le retrait, et le fichier lui-même.
        $router->post('/mon-profil/photo', ProfileController::uploadPhoto(...));
        $router->post('/mon-profil/photo/supprimer', ProfileController::deletePhoto(...));
        // Double authentification, pilotée par la personne elle-même.
        $router->post('/mon-profil/2fa/preparer', ProfileController::prepareTwoFactor(...));
        $router->post('/mon-profil/2fa/activer', ProfileController::enableTwoFactor(...));
        $router->post('/mon-profil/2fa/desactiver', ProfileController::disableTwoFactor(...));
        $router->post('/mon-profil/2fa/codes', ProfileController::newRecoveryCodes(...));
        $router->post('/mon-profil/cles', ProfileController::addKey(...));
        $router->post('/mon-profil/cles/{id}/supprimer', ProfileController::removeKey(...));
        // Fermer toutes ses sessions : après un doute, un voyage, un poste prêté.
        $router->post('/mon-profil/appareils/{sid}/fermer', ProfileController::closeDevice(...));
        $router->post('/sessions/fermer', AuthController::closeSessions(...));
        $router->get('/media/avatars/{name}', ProfileController::photo(...));

        // Pièces reçues : dépôt, relève de la boîte aux lettres, mise en facture.
        $router->get('/pieces', PiecesController::index(...));
        $router->post('/pieces/deposer', PiecesController::deposit(...));
        $router->post('/pieces/capture/reglages', PiecesController::saveCapture(...));
        $router->post('/pieces/capture/tester', PiecesController::testCapture(...));
        $router->post('/pieces/capture/relever', PiecesController::runCapture(...));
        $router->get('/pieces/{id}', PiecesController::show(...));
        $router->get('/pieces/{id}/fichier', PiecesController::file(...));
        $router->post('/pieces/{id}/analyser', PiecesController::reanalyse(...));
        $router->post('/pieces/{id}/facturer', PiecesController::invoice(...));
        $router->post('/pieces/{id}/ecarter', PiecesController::discard(...));
        $router->post('/pieces/{id}/supprimer', PiecesController::delete(...));

        // Planning d'équipe : créneaux, roulements, astreintes.
        $router->get('/planning', PlanningController::index(...));
        $router->post('/planning/creneaux', PlanningController::createShift(...));
        $router->post('/planning/creneaux/{id}/supprimer', PlanningController::deleteShift(...));
        $router->post('/planning/publier', PlanningController::publish(...));
        $router->post('/planning/roulements', PlanningController::createTemplate(...));
        $router->post('/planning/roulements/{id}/appliquer', PlanningController::applyTemplate(...));
        $router->post('/planning/roulements/{id}/supprimer', PlanningController::deleteTemplate(...));

        $router->get('/salles', RoomsController::index(...));
        $router->post('/salles', RoomsController::book(...));
        $router->post('/salles/{id}/annuler', RoomsController::cancel(...));

        $router->get('/messagerie', MessagesController::index(...));
        $router->post('/messagerie/externe/oublier', MessagesController::forgetExternal(...));
        $router->post('/messagerie', MessagesController::send(...));
        $router->post('/messagerie/{id}/supprimer', MessagesController::remove(...));

        // Projets : jalons, tâches, temps passé, rentabilité.
        $router->get('/projets', ProjectsController::index(...));
        $router->post('/projets', ProjectsController::create(...));
        $router->post('/projets/taches/{id}/statut', ProjectsController::setTaskStatus(...));
        $router->post('/projets/taches/{id}/affecter', ProjectsController::assignTask(...));
        $router->post('/projets/taches/{id}/supprimer', ProjectsController::deleteTask(...));
        $router->post('/projets/jalons/{id}/basculer', ProjectsController::toggleMilestone(...));
        $router->post('/projets/jalons/{id}/supprimer', ProjectsController::deleteMilestone(...));
        $router->post('/projets/temps/{id}/supprimer', ProjectsController::deleteTime(...));
        $router->get('/projets/{id}', ProjectsController::show(...));
        $router->post('/projets/{id}/modifier', ProjectsController::update(...));
        $router->post('/projets/{id}/archiver', ProjectsController::archive(...));
        $router->post('/projets/{id}/supprimer', ProjectsController::remove(...));
        $router->post('/projets/{id}/membres', ProjectsController::addMember(...));
        $router->post('/projets/{id}/membres/{userId}/retirer', ProjectsController::removeMember(...));
        $router->post('/projets/{id}/jalons', ProjectsController::createMilestone(...));
        $router->post('/projets/{id}/taches', ProjectsController::createTask(...));
        $router->post('/projets/{id}/temps', ProjectsController::logTime(...));

        /*
         * Les actions de masse : un seul chemin pour toutes les listes. Le droit
         * n'est pas ici mais sur chaque ligne, vérifié par le module.
         */
        $router->post('/actions-masse', ActionsMasseController::apply(...));

        /*
         * La vue en tableau : un seul chemin pour déplacer une carte, quelle que
         * soit la liste. Le droit est sur la ligne, pas sur le chemin.
         */
        $router->post('/kanban/deplacer', KanbanController::move(...));

        // Support : tickets internes et clients.
        $router->get('/support', SupportController::index(...));
        $router->post('/support/tickets', SupportController::create(...));
        $router->get('/support/tickets/{id}', SupportController::show(...));
        $router->post('/support/tickets/{id}/resumer', SupportController::summarise(...));
        $router->post('/support/tickets/{id}/brouillon', SupportController::draftReply(...));
        $router->post('/support/tickets/{id}/repondre', SupportController::reply(...));
        $router->post('/support/tickets/{id}/statut', SupportController::setStatus(...));
        $router->post('/support/tickets/{id}/priorite', SupportController::setPriority(...));
        $router->post('/support/tickets/{id}/affecter', SupportController::assign(...));
        $router->post('/support/tickets/{id}/supprimer', SupportController::remove(...));
        $router->post('/support/tickets/{id}/champs', SupportController::saveFields(...));
        $router->post('/support/tickets/{id}/groupe', SupportController::setGroup(...));
        /*
         * Les groupes de support : qui traite quoi, décidé par l'entreprise.
         * L'écran est réservé à l'administration, contrôlé dans le contrôleur.
         */
        $router->get('/support/groupes', SupportController::groups(...));
        $router->post('/support/groupes', SupportController::createGroup(...));
        $router->post('/support/groupes/{id}/modifier', SupportController::renameGroup(...));
        $router->post('/support/groupes/{id}/categories', SupportController::setGroupCategories(...));
        $router->post('/support/groupes/{id}/membres', SupportController::addGroupMember(...));
        $router->post('/support/groupes/{id}/membres/retirer', SupportController::removeGroupMember(...));
        $router->post('/support/groupes/{id}/supprimer', SupportController::deleteGroup(...));

        // Gestion : tiers, contrats, factures, budgets, notes de frais, devises.
        $router->get('/gestion', FinanceController::index(...));
        $router->post('/gestion/sepa/coordonnees', FinanceController::sepaCoordinates(...));
        $router->post('/gestion/sepa/virements', FinanceController::sepaTransfers(...));
        $router->post('/gestion/sepa/prelevements', FinanceController::sepaDebits(...));
        $router->post('/gestion/tiers', FinanceController::createPartner(...));
        $router->post('/gestion/tiers/{id}/statut', FinanceController::togglePartner(...));
        $router->post('/gestion/tiers/{id}/supprimer', FinanceController::deletePartner(...));
        $router->post('/gestion/contrats', FinanceController::createContract(...));
        $router->post('/gestion/contrats/{id}/statut', FinanceController::setContractStatus(...));
        $router->post('/gestion/contrats/{id}/supprimer', FinanceController::deleteContract(...));
        $router->post('/gestion/factures', FinanceController::createInvoice(...));
        $router->post('/gestion/factures/{id}/statut', FinanceController::setInvoiceStatus(...));
        $router->post('/gestion/factures/{id}/paiement', FinanceController::payOnline(...));
        $router->post('/gestion/factures/{id}/supprimer', FinanceController::deleteInvoice(...));
        $router->post('/gestion/factures/{id}/champs', FinanceController::saveInvoiceFields(...));
        $router->post('/gestion/budgets', FinanceController::setBudget(...));
        $router->post('/gestion/budgets/{id}/supprimer', FinanceController::deleteBudget(...));
        $router->post('/gestion/frais/{id}/statut', FinanceController::reviewClaim(...));
        $router->post('/gestion/devises/reference', FinanceController::setBaseCurrency(...));
        $router->post('/gestion/devises/taux', FinanceController::setRate(...));
        $router->post('/gestion/abonnements', FinanceController::createSubscription(...));
        $router->post('/gestion/abonnements/emettre', FinanceController::issueSubscriptions(...));
        $router->post('/gestion/abonnements/{id}/etat', FinanceController::setSubscriptionState(...));
        $router->post('/gestion/abonnements/{id}/supprimer', FinanceController::deleteSubscription(...));
        $router->post('/gestion/tva', FinanceController::saveVatReturn(...));
        $router->post('/gestion/tva/{id}/statut', FinanceController::setVatStatus(...));
        $router->post('/gestion/tva/{id}/supprimer', FinanceController::deleteVatReturn(...));
        $router->post('/gestion/relances', FinanceController::recordNotice(...));
        $router->post('/gestion/relances/{id}/supprimer', FinanceController::deleteNotice(...));
        // Parc de salles : réserver est ouvert à tous, le tenir relève de la gestion.
        $router->post('/gestion/salles', FinanceController::createRoom(...));
        $router->post('/gestion/salles/{id}/statut', FinanceController::toggleRoom(...));
        $router->post('/gestion/salles/{id}/supprimer', FinanceController::deleteRoom(...));
        $router->post('/gestion/reservations/{id}/annuler', FinanceController::cancelBooking(...));
        $router->post('/gestion/equipements', FinanceController::createAsset(...));
        $router->post('/gestion/equipements/{id}/affecter', FinanceController::assignAsset(...));
        $router->post('/gestion/equipements/{id}/reprendre', FinanceController::takeBackAsset(...));
        $router->post('/gestion/equipements/{id}/statut', FinanceController::setAssetStatus(...));
        $router->post('/gestion/equipements/{id}/supprimer', FinanceController::deleteAsset(...));
        $router->post('/gestion/equipements/{id}/champs', FinanceController::saveAssetFields(...));

        // Notes de frais côté salarié : déposer et retirer les siennes.
        $router->post('/mon-espace/frais', FinanceController::createClaim(...));
        $router->post('/mon-espace/frais/{id}/annuler', FinanceController::cancelClaim(...));

        // Comptabilité (module optionnel).
        $router->get('/comptabilite', AccountingController::index(...));
        $router->get('/comptabilite/balance.csv', AccountingController::balanceCsv(...));
        $router->post('/comptabilite/comptes', AccountingController::createAccount(...));
        $router->post('/comptabilite/comptes/{id}/supprimer', AccountingController::deleteAccount(...));
        $router->post('/comptabilite/journaux', AccountingController::createJournal(...));
        $router->post('/comptabilite/ecritures', AccountingController::createEntry(...));
        $router->post('/comptabilite/ecritures/{id}/supprimer', AccountingController::deleteEntry(...));
        $router->post('/comptabilite/ecritures/{id}/contrepasser', AccountingController::reverseEntry(...));
        $router->post('/comptabilite/lettrage', AccountingController::letter(...));
        $router->post('/comptabilite/lettrage/retirer', AccountingController::unletter(...));
        $router->post('/comptabilite/a-nouveaux', AccountingController::openingBalances(...));
        $router->post('/comptabilite/valider', AccountingController::validate(...));
        $router->get('/comptabilite/fec', AccountingController::fec(...));
        $router->post('/comptabilite/factures/{id}/comptabiliser', AccountingController::postInvoice(...));

        // Paie (module optionnel).
        $router->get('/paie', PayrollController::index(...));
        $router->post('/paie/baremes', PayrollController::createRate(...));
        $router->post('/paie/baremes/{id}/statut', PayrollController::toggleRate(...));
        $router->post('/paie/baremes/{id}/supprimer', PayrollController::deleteRate(...));
        $router->post('/paie/plafond', PayrollController::setCeiling(...));
        $router->post('/paie/salaires/{id}', PayrollController::setGrossSalary(...));
        $router->post('/paie/bulletins', PayrollController::createPayslip(...));
        $router->post('/paie/bulletins/lot', PayrollController::createPayslipBatch(...));

        // Trésorerie (module optionnel).
        $router->get('/tresorerie', TreasuryController::index(...));
        $router->post('/tresorerie/comptes', TreasuryController::createAccount(...));
        $router->post('/tresorerie/comptes/{id}/cloturer', TreasuryController::closeAccount(...));
        $router->post('/tresorerie/comptes/{id}/supprimer', TreasuryController::deleteAccount(...));
        $router->post('/tresorerie/mouvements', TreasuryController::addTransaction(...));
        $router->post('/tresorerie/releves', TreasuryController::importStatement(...));
        $router->post('/tresorerie/mouvements/{id}/supprimer', TreasuryController::deleteTransaction(...));
        $router->post('/tresorerie/mouvements/{id}/rapprocher', TreasuryController::reconcile(...));
        $router->post('/tresorerie/previsions', TreasuryController::addForecast(...));
        $router->post('/tresorerie/previsions/{id}/supprimer', TreasuryController::deleteForecast(...));

        // Immobilisations (module optionnel).
        $router->get('/immobilisations', FixedAssetsController::index(...));
        $router->post('/immobilisations', FixedAssetsController::create(...));
        $router->post('/immobilisations/{id}/ceder', FixedAssetsController::dispose(...));
        $router->post('/immobilisations/{id}/supprimer', FixedAssetsController::remove(...));

        // Stock et achats (module optionnel).
        $router->get('/stock', StockController::index(...));
        $router->get('/stock/commandes/{id}', StockController::showOrder(...));
        $router->post('/stock/commandes', StockController::createOrder(...));
        $router->post('/stock/commandes/{id}/modifier', StockController::updateOrder(...));
        $router->post('/stock/commandes/{id}/supprimer', StockController::deleteOrder(...));
        $router->post('/stock/commandes/{id}/lignes', StockController::addLine(...));
        $router->post('/stock/lignes/{id}/supprimer', StockController::deleteLine(...));
        $router->post('/stock/lignes/{id}/reception', StockController::receiveLine(...));
        $router->post('/stock/commandes/{id}/factures', StockController::attachInvoice(...));
        $router->post('/stock/factures/{id}/detacher', StockController::detachInvoice(...));
        $router->post('/stock/articles', StockController::createItem(...));
        $router->post('/stock/articles/{id}/statut', StockController::toggleItem(...));
        $router->post('/stock/articles/{id}/supprimer', StockController::deleteItem(...));
        $router->post('/stock/mouvements', StockController::move(...));
        $router->post('/stock/demandes', StockController::createRequest(...));
        $router->post('/stock/demandes/{id}/annuler', StockController::cancelRequest(...));
        $router->post('/stock/demandes/{id}/manager', StockController::managerDecision(...));
        $router->post('/stock/demandes/{id}/gestion', StockController::financeDecision(...));
        $router->post('/stock/demandes/{id}/commander', StockController::markOrdered(...));

        // Service informatique : parc logiciel, accès applicatifs, incidents.
        $router->get('/informatique', ItController::index(...));
        $router->get('/informatique/logiciels/{id}', ItController::showLicence(...));
        $router->post('/informatique/logiciels', ItController::createLicence(...));
        $router->post('/informatique/logiciels/{id}/modifier', ItController::updateLicence(...));
        $router->post('/informatique/logiciels/{id}/supprimer', ItController::deleteLicence(...));
        $router->post('/informatique/logiciels/{id}/acces', ItController::grantAccess(...));
        $router->post('/informatique/acces/{id}/revoquer', ItController::revokeAccess(...));
        $router->post('/informatique/acces/{id}/revu', ItController::markReviewed(...));
        $router->post('/informatique/incidents', ItController::createIncident(...));
        $router->post('/informatique/incidents/{id}/modifier', ItController::updateIncident(...));
        $router->post('/informatique/incidents/{id}/supprimer', ItController::deleteIncident(...));

        // Développement : services applicatifs et livraisons.
        $router->get('/developpement', DevController::index(...));
        $router->get('/developpement/services/{id}', DevController::showService(...));
        $router->post('/developpement/services', DevController::createService(...));
        $router->post('/developpement/services/{id}/modifier', DevController::updateService(...));
        $router->post('/developpement/services/{id}/supprimer', DevController::deleteService(...));
        $router->post('/developpement/services/{id}/livraisons', DevController::createRelease(...));
        $router->post('/developpement/livraisons/{id}/modifier', DevController::updateRelease(...));
        $router->post('/developpement/livraisons/{id}/supprimer', DevController::deleteRelease(...));

        // Flotte de véhicules.
        $router->get('/flotte', FleetController::index(...));
        $router->post('/flotte', FleetController::create(...));
        $router->post('/flotte/evenements/{id}/supprimer', FleetController::deleteEvent(...));
        $router->get('/flotte/{id}', FleetController::show(...));
        $router->post('/flotte/{id}/modifier', FleetController::update(...));
        $router->post('/flotte/{id}/supprimer', FleetController::remove(...));
        $router->post('/flotte/{id}/evenements', FleetController::addEvent(...));

        // Accueil : visiteurs et courrier.
        $router->get('/accueil', FrontDeskController::index(...));
        $router->post('/accueil/visiteurs', FrontDeskController::checkIn(...));
        $router->post('/accueil/visiteurs/{id}/sortie', FrontDeskController::checkOut(...));
        $router->post('/accueil/visiteurs/{id}/supprimer', FrontDeskController::deleteVisitor(...));
        $router->post('/accueil/courrier', FrontDeskController::logMail(...));
        $router->post('/accueil/courrier/{id}/remise', FrontDeskController::handOver(...));
        $router->post('/accueil/courrier/{id}/archiver', FrontDeskController::archiveMail(...));
        $router->post('/accueil/courrier/{id}/supprimer', FrontDeskController::deleteMail(...));

        // Direction : réunions, décisions, actions, risques et sondages.
        $router->get('/direction', GovernanceController::index(...));
        $router->post('/direction/reunions', GovernanceController::createMeeting(...));
        $router->get('/direction/reunions/{id}', GovernanceController::showMeeting(...));
        $router->post('/direction/reunions/{id}/modifier', GovernanceController::updateMeeting(...));
        $router->post('/direction/reunions/{id}/compte-rendu', GovernanceController::setMinutes(...));
        $router->post('/direction/reunions/{id}/relever', GovernanceController::pickOut(...));
        $router->post('/direction/reunions/{id}/relever/inscrire', GovernanceController::recordPicked(...));
        $router->post('/direction/reunions/{id}/participants', GovernanceController::invite(...));
        $router->post('/direction/reunions/{id}/participants/{userId}/presence', GovernanceController::setAttendance(...));
        $router->post('/direction/reunions/{id}/participants/{userId}/retirer', GovernanceController::removeAttendee(...));
        $router->post('/direction/reunions/{id}/supprimer', GovernanceController::deleteMeeting(...));
        $router->post('/direction/decisions', GovernanceController::createDecision(...));
        $router->post('/direction/decisions/{id}/statut', GovernanceController::setDecisionStatus(...));
        $router->post('/direction/decisions/{id}/supprimer', GovernanceController::deleteDecision(...));
        $router->post('/direction/actions', GovernanceController::createAction(...));
        $router->post('/direction/actions/{id}/statut', GovernanceController::setActionStatus(...));
        $router->post('/direction/actions/{id}/supprimer', GovernanceController::deleteAction(...));
        $router->post('/direction/risques', GovernanceController::createRisk(...));
        $router->post('/direction/risques/{id}/modifier', GovernanceController::updateRisk(...));
        $router->post('/direction/risques/{id}/supprimer', GovernanceController::deleteRisk(...));
        $router->post('/direction/sondages', GovernanceController::createSurvey(...));
        $router->post('/direction/sondages/{id}/questions', GovernanceController::addQuestion(...));
        $router->post('/direction/sondages/{id}/questions/{questionId}/supprimer', GovernanceController::deleteQuestion(...));
        $router->post('/direction/sondages/{id}/ouvrir', GovernanceController::openSurvey(...));
        $router->post('/direction/sondages/{id}/clore', GovernanceController::closeSurvey(...));
        $router->post('/direction/sondages/{id}/supprimer', GovernanceController::deleteSurvey(...));
        $router->get('/direction/sondages/{id}/resultats', GovernanceController::surveyResults(...));

        // Sondages, côté salarié : répondre, et rien d'autre.
        $router->get('/sondages', SurveysController::index(...));
        $router->get('/sondages/{id}', SurveysController::show(...));
        $router->post('/sondages/{id}', SurveysController::submit(...));

        // Comité social et économique.
        $router->get('/cse', CseController::index(...));
        $router->post('/cse/candidature', CseController::apply(...));
        $router->post('/cse/candidature/retirer', CseController::withdraw(...));
        $router->post('/cse/vote', CseController::vote(...));
        $router->get('/cse/gestion', CseController::manage(...));
        $router->post('/cse/gestion/avantages', CseController::createBenefit(...));
        $router->post('/cse/gestion/avantages/{id}/modifier', CseController::updateBenefit(...));
        $router->post('/cse/gestion/avantages/{id}/supprimer', CseController::deleteBenefit(...));
        $router->post('/cse/gestion/reunions/{id}/compte-rendu', CseController::saveMinutes(...));

        // Vie juridique et conformité : l'administration tient les registres,
        // chacun dépose ses propres déclarations.
        $router->get('/juridique', LegalController::index(...));
        $router->get('/juridique/assemblees/{id}', LegalController::showMeeting(...));
        $router->post('/juridique/associes', LegalController::createShareholder(...));
        $router->post('/juridique/associes/{id}/supprimer', LegalController::deleteShareholder(...));
        $router->post('/juridique/mouvements', LegalController::recordMovement(...));
        $router->post('/juridique/mouvements/{id}/supprimer', LegalController::deleteMovement(...));
        $router->post('/juridique/mandats', LegalController::createMandate(...));
        $router->post('/juridique/mandats/{id}/statut', LegalController::setMandateStatus(...));
        $router->post('/juridique/mandats/{id}/supprimer', LegalController::deleteMandate(...));
        $router->post('/juridique/assemblees', LegalController::createMeeting(...));
        $router->post('/juridique/assemblees/{id}/modifier', LegalController::updateMeeting(...));
        $router->post('/juridique/assemblees/{id}/proces-verbal', LegalController::updateMinutes(...));
        $router->post('/juridique/assemblees/{id}/resolutions', LegalController::addResolution(...));
        $router->post('/juridique/assemblees/{id}/supprimer', LegalController::deleteMeeting(...));
        $router->post('/juridique/resolutions/{id}/vote', LegalController::recordVote(...));
        $router->post('/juridique/resolutions/{id}/supprimer', LegalController::deleteResolution(...));
        $router->post('/juridique/interets', LegalController::declareInterest(...));
        $router->post('/juridique/interets/{id}/examen', LegalController::reviewDeclaration(...));
        $router->post('/juridique/cadeaux', LegalController::declareGift(...));
        $router->post('/juridique/cadeaux/{id}/examen', LegalController::reviewGift(...));
        $router->post('/juridique/delegations', LegalController::createDelegation(...));
        $router->post('/juridique/delegations/{id}/statut', LegalController::setDelegationStatus(...));
        $router->post('/juridique/delegations/{id}/supprimer', LegalController::deleteDelegation(...));

        // Qualité : non-conformités, actions, audits internes.
        $router->get('/qualite', QualityController::index(...));
        $router->post('/qualite/non-conformites', QualityController::createNonconformity(...));
        $router->post('/qualite/non-conformites/{id}/cause', QualityController::setRootCause(...));
        $router->post('/qualite/non-conformites/{id}/statut', QualityController::setNonconformityStatus(...));
        $router->post('/qualite/non-conformites/{id}/supprimer', QualityController::deleteNonconformity(...));
        $router->post('/qualite/actions', QualityController::createAction(...));
        $router->post('/qualite/actions/{id}/statut', QualityController::setActionStatus(...));
        $router->post('/qualite/actions/{id}/efficacite', QualityController::verifyAction(...));
        $router->post('/qualite/actions/{id}/supprimer', QualityController::deleteAction(...));
        $router->post('/qualite/audits', QualityController::createAudit(...));
        $router->post('/qualite/audits/{id}/realiser', QualityController::completeAudit(...));
        $router->post('/qualite/audits/{id}/constats', QualityController::addFinding(...));
        $router->post('/qualite/audits/{id}/supprimer', QualityController::deleteAudit(...));
        $router->post('/qualite/constats/{id}/en-non-conformite', QualityController::promoteFinding(...));
        $router->post('/qualite/constats/{id}/supprimer', QualityController::deleteFinding(...));

        // Santé et sécurité au travail.
        $router->get('/sante-securite', SafetyController::index(...));
        $router->post('/sante-securite/risques', SafetyController::createRisk(...));
        $router->post('/sante-securite/risques/{id}/supprimer', SafetyController::deleteRisk(...));
        $router->post('/sante-securite/accidents', SafetyController::createIncident(...));
        $router->post('/sante-securite/accidents/{id}/supprimer', SafetyController::deleteIncident(...));
        $router->post('/sante-securite/protections', SafetyController::createPpe(...));
        $router->post('/sante-securite/protections/remettre', SafetyController::issuePpe(...));
        $router->post('/sante-securite/protections/remises/{id}/rendre', SafetyController::returnPpe(...));
        $router->post('/sante-securite/protections/{id}/supprimer', SafetyController::deletePpe(...));
        $router->post('/sante-securite/visites', SafetyController::createVisit(...));
        $router->post('/sante-securite/visites/{id}/supprimer', SafetyController::deleteVisit(...));

        // Coffre-fort : le sien, celui de la gestion, et l'accès par code.
        $router->get('/coffre-fort/acces', VaultController::accessForm(...));
        $router->post('/coffre-fort/acces', VaultController::redeem(...));
        $router->get('/coffre-fort', VaultController::mine(...));
        $router->get('/coffre-fort/documents/{id}', VaultController::download(...));
        $router->get('/coffre-fort/gestion', VaultController::manage(...));
        $router->post('/coffre-fort/gestion/depots', VaultController::deposit(...));
        $router->post('/coffre-fort/gestion/documents/{id}/retirer', VaultController::removeDocument(...));
        $router->post('/coffre-fort/gestion/acces/{id}', VaultController::issueGrant(...));
        $router->post('/coffre-fort/gestion/acces/{id}/revoquer', VaultController::revokeGrants(...));

        // Parapheur.
        $router->get('/parapheur', SigningController::index(...));
        $router->post('/parapheur', SigningController::create(...));
        $router->get('/parapheur/{id}', SigningController::show(...));
        $router->get('/parapheur/{id}/document', SigningController::download(...));
        $router->get('/parapheur/{id}/attestation', SigningController::certificate(...));
        $router->post('/parapheur/{id}/signer', SigningController::sign(...));
        $router->post('/parapheur/{id}/refuser', SigningController::refuse(...));
        $router->post('/parapheur/{id}/annuler', SigningController::cancel(...));
        $router->post('/parapheur/{id}/supprimer', SigningController::remove(...));

        // Sauvegardes, restauration et export intégral : l'administration.
        $router->get('/sauvegardes', BackupController::index(...));
        $router->post('/sauvegardes', BackupController::create(...));
        $router->post('/sauvegardes/export', BackupController::export(...));
        $router->post('/sauvegardes/reglages', BackupController::setSettings(...));
        $router->post('/sauvegardes/televerser', BackupController::restoreUpload(...));
        $router->post('/sauvegardes/destinations/{key}', BackupController::setDestination(...));
        $router->post('/sauvegardes/destinations/{key}/tester', BackupController::testDestination(...));
        $router->post('/sauvegardes/{fichier}/externaliser', BackupController::sendOffsite(...));
        $router->get('/sauvegardes/{fichier}/telecharger', BackupController::download(...));
        $router->get('/sauvegardes/{fichier}/verifier', BackupController::verify(...));
        $router->post('/sauvegardes/{fichier}/supprimer', BackupController::remove(...));
        $router->post('/sauvegardes/{fichier}/restaurer', BackupController::restore(...));

        // Console de sécurité et données personnelles : l'administration.
        $router->get('/securite', SecurityController::index(...));
        $router->get('/securite/journal.csv', SecurityController::journalCsv(...));
        $router->post('/securite/administrateurs', SecurityController::promote(...));
        $router->post('/securite/administrateurs/{id}/retirer', SecurityController::demote(...));
        $router->post('/securite/sessions/{id}/fermer', SecurityController::closeSessions(...));
        $router->post('/securite/2fa/{id}/reinitialiser', SecurityController::resetTotp(...));
        $router->post('/securite/comptes/{id}/deverrouiller', SecurityController::unlock(...));
        $router->post('/securite/politique', SecurityController::setPolicy(...));
        $router->post('/securite/sso', SecurityController::setSso(...));
        $router->post('/securite/saml', SecurityController::setSaml(...));
        $router->post('/securite/journal/purger', SecurityController::purgeJournal(...));

        // Centre de journaux : ce qui s'est passé, et les collecteurs qui en
        // gardent une copie ailleurs.
        /* Le tableau de bord d'une section : les tuiles de ses services. Il
           n'a pas de garde ici — le contrôleur ne montre que ce que ce compte
           peut ouvrir, et refuse une section dont il ne verrait rien. */
        $router->get('/section/{cle}', SectionController::index(...));
        /* L'assistant de première ouverture et les réglages d'une section :
           l'administration seule, et toujours après la garde ci-dessous. */
        $router->get('/section/{cle}/assistant', SectionController::assistant(...));
        $router->post('/section/{cle}/assistant', SectionController::repondre(...));
        $router->post('/section/{cle}/assistant/rouvrir', SectionController::rouvrir(...));
        $router->get('/section/{cle}/reglages', SectionController::reglages(...));
        $router->post('/section/{cle}/reglages', SectionController::enregistrer(...));

        $router->get('/journaux', LogsController::index(...));
        $router->get('/journaux/journal.csv', LogsController::journalCsv(...));
        $router->post('/journaux/purger', LogsController::purge(...));
        $router->post('/journaux/vider', LogsController::clear(...));
        $router->post('/journaux/cibles', LogsController::createTarget(...));
        $router->post('/journaux/cibles/{id}', LogsController::updateTarget(...));
        $router->post('/journaux/cibles/{id}/etat', LogsController::setTargetState(...));
        $router->post('/journaux/cibles/{id}/essai', LogsController::testTarget(...));
        $router->post('/journaux/cibles/{id}/envoyer', LogsController::sendTarget(...));
        $router->post('/journaux/cibles/{id}/rattraper', LogsController::skipBacklog(...));
        $router->post('/journaux/cibles/{id}/supprimer', LogsController::deleteTarget(...));

        // Mises à jour : ce que l'éditeur propose, et ce que l'instance en fait.
        $router->get('/licence', LicenceController::index(...));
        $router->post('/licence/verifier', LicenceController::renew(...));
        $router->post('/licence/poser', LicenceController::apply(...));
        $router->post('/licence/adresse', LicenceController::address(...));
        /*
         * Écrire à l'éditeur depuis l'instance, avec la clé qu'elle porte déjà.
         * Rien ne part sur un simple affichage : le relevé se demande.
         */
        $router->post('/licence/assistance/relever', LicenceController::fetchAssistance(...));
        $router->post('/licence/assistance/ouvrir', LicenceController::openAssistance(...));
        $router->post('/licence/assistance/repondre', LicenceController::replyAssistance(...));

        $router->get('/applications', StoreController::index(...));
        $router->post('/applications/recuperer', StoreController::fetch(...));
        $router->post('/applications/{cle}/allumer', StoreController::enable(...));

        $router->get('/mises-a-jour', UpdatesController::index(...));
        // Où en est la pose : la fenêtre l'interroge pendant qu'elle attend.
        $router->get('/mises-a-jour/avancement', UpdatesController::progress(...));
        $router->post('/mises-a-jour/reglages', UpdatesController::save(...));
        $router->post('/mises-a-jour/verifier', UpdatesController::check(...));
        $router->post('/mises-a-jour/appliquer', UpdatesController::apply(...));
        $router->post('/mises-a-jour/retour', UpdatesController::rollback(...));

        $router->get('/rgpd', PrivacyController::index(...));
        $router->post('/rgpd/traitements', PrivacyController::createRecord(...));
        $router->post('/rgpd/traitements/amorcer', PrivacyController::seedRecords(...));
        $router->post('/rgpd/traitements/{id}/supprimer', PrivacyController::deleteRecord(...));
        $router->get('/rgpd/personnes/{id}/export.json', PrivacyController::exportJson(...));
        $router->post('/rgpd/personnes/{id}/effacer', PrivacyController::erase(...));

        $router->get('/notifications', NotificationsController::index(...));
        $router->post('/notifications/tout-lire', NotificationsController::markAllRead(...));
        $router->post('/notifications/{id}/lue', NotificationsController::markRead(...));
        $router->post('/notifications/{id}/supprimer', NotificationsController::remove(...));

        $router->get('/organigramme', OrgChartController::index(...));
        $router->get('/mon-espace', MemberController::home(...));
        // Recherche globale : ouverte à toute personne connectée, mais chaque
        // source est interrogée avec ses droits à elle.
        $router->get('/recherche', SearchController::index(...));
        $router->get('/annuaire', DirectoryController::index(...));
        $router->get('/mon-profil', ProfileController::show(...));
        $router->post('/mon-profil', ProfileController::update(...));
    }

    /**
     * Les chemins dont les écritures passent même sans licence valable.
     * Comparés en préfixe : une route plus précise en dessous reste couverte.
     *
     * Sortir ses données est la contrepartie de tout le reste. On ne la retire
     * jamais : c'est ce qui sépare une restriction commerciale d'une prise
     * d'otage.
     */
    private const SANS_LICENCE = [
        '/connexion', '/deconnexion', '/langue', '/mot-de-passe',
        '/licence', '/coffre-fort', '/sauvegardes', '/admin/export',
    ];

    private static function permisSansLicence(string $chemin): bool
    {
        foreach (self::SANS_LICENCE as $prefixe) {
            if ($chemin === $prefixe || str_starts_with($chemin, $prefixe . '/')) {
                return true;
            }
        }
        return false;
    }

    public function handle(Request $request): Response
    {
        Session::start();

        // Plafond global : une adresse qui martèle le site est ralentie avant
        // même qu'on regarde ce qu'elle demande.
        if (Security::tooManyAttempts('global', (int) Config::get('global_rate_limit', 300), 60)) {
            return Response::text("Trop de requêtes. Merci de réessayer dans une minute.", 429);
        }

        $installed = Users::count() > 0;
        /*
         * Une instance qui tournait avant les sections a déjà ses réglages,
         * posés à la main dans les onglets de la console. On la marque
         * configurée une fois pour toutes, plutôt que de lui ouvrir sept
         * assistants au premier démarrage après la mise à jour.
         */
        if ($installed) {
            SectionsReglages::rattraperInstanceExistante();
        }
        // Changer de langue reste possible avant l'installation : sans cela,
        // l'assistant n'est lisible que dans la langue devinée du navigateur.
        if (!$installed && !self::cheminDInstallation($request->path) && $request->path !== '/langue') {
            return Response::redirect('/install');
        }
        // L'assistant se referme dès qu'un compte existe — à une exception
        // près : le dernier palier, qui écrit la configuration. Il vient par
        // définition après la création de l'administrateur, et le lui interdire
        // laisserait l'instance debout mais non configurée. Le brouillon dit
        // qu'une installation est en cours ; il est effacé par ce palier-là.
        $acheve = str_starts_with($request->path, '/install/executer/')
            && \App\Modules\Installation::brouillon() !== [];
        if ($installed && self::cheminDInstallation($request->path) && !$acheve) {
            return Response::redirect('/connexion');
        }

        // La licence : elle ne refuse que des écritures, et seulement au dernier
        // palier. Posée après l'assistant, parce qu'une instance en cours
        // d'installation n'a pas encore de licence à vérifier ; et avant les
        // routes, pour qu'aucune n'ait à y penser.
        //
        // Ce qui reste permis quoi qu'il arrive : toutes les lectures, et parmi
        // les écritures se connecter, se déconnecter, changer son mot de passe,
        // poser une licence, exporter. Le client est responsable de traitement
        // au sens du RGPD : il doit pouvoir entrer chez lui et ressortir avec
        // ses données. Une licence impayée se règle par une discussion
        // commerciale, pas en séquestrant des dossiers du personnel.
        if ($installed && $request->method !== 'GET' && $request->method !== 'HEAD'
            && !\App\Modules\Licence::ecritureAutorisee()
            && !self::permisSansLicence($request->path)) {
            /*
             * Le même refus, dit dans la langue de l'appelant.
             *
             * Un navigateur suit une redirection et lit un bandeau ; un
             * programme reçoit une page HTML là où il attendait du JSON, et
             * n'en fait rien — au mieux il signale une erreur d'analyse, au
             * pire il croit avoir réussi. L'API dit donc non en JSON, avec le
             * code qui convient.
             */
            if (str_starts_with($request->path, '/api/v1')) {
                return Response::text(
                    (string) json_encode([
                        'error' => "L'écriture est suspendue : la licence de cette instance n'est plus valide.",
                        'motif' => 'licence',
                    ], JSON_UNESCAPED_UNICODE),
                    403
                )->withHeaders(['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']);
            }
            Flash::set('error', t('licence.readOnlyBlocked'));
            return Response::redirect('/licence');
        }

        // L'avis de paiement de Stripe : servi avant la session, parce qu'il n'en
        // a pas — ni cookie ni jeton CSRF. Il s'authentifie par sa signature
        // HMAC sur le corps brut, son heure, et une session Checkout créée ici
        // (app/Modules/Stripe.php).
        if ($request->path === '/stripe/webhook') {
            if ($request->method !== 'POST') {
                return Response::text('{"error":"POST attendu."}', 405)
                    ->withHeaders(['Content-Type' => 'application/json; charset=utf-8', 'Allow' => 'POST']);
            }
            $verdict = \App\Modules\Stripe::recevoir($request->raw, $request->header('stripe-signature'));
            if ($verdict['reason'] === 'paid') {
                Audit::log('facture.payee_en_ligne', 'invoices', (int) $verdict['session']['invoice_id'], ['session' => $verdict['session']['session_id']]);
            } elseif ($verdict['status'] !== 200 || in_array($verdict['reason'], ['amount', 'invoice'], true)) {
                Audit::log('stripe.avis_refuse', 'invoices', isset($verdict['session']) ? (int) $verdict['session']['invoice_id'] : null, ['motif' => $verdict['reason']]);
            }
            return Response::text((string) json_encode(['received' => $verdict['status'] === 200]), $verdict['status'])
                ->withHeaders(['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']);
        }

        // L'API est servie avant toute session : elle ne pose ni ne lit de
        // cookie, et son authentification tient entièrement dans l'en-tête.
        // Un jeton n'a donc pas de jeton CSRF à présenter — il n'y a pas de
        // session à détourner, et c'est la portée du jeton, vérifiée route par
        // route, qui décide s'il peut écrire.
        if ($request->path === '/api/v1' || str_starts_with($request->path, '/api/v1/')) {
            $match = $this->router->match($request);
            if ($match === null || $match['handler'] === null) {
                return Response::text('{"error":"Ressource inconnue."}', 404)
                    ->withHeaders(['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']);
            }
            return ($match['handler'])($request, $match['params']);
        }

        $user = $this->currentUser();
        $this->prepareLocale($request, $user, $installed);

        /*
         * Un formulaire trop gros, avant de conclure au jeton manquant.
         *
         * Quand le corps dépasse « post_max_size », PHP ne jette pas : il rend
         * un « $_POST » VIDE et poursuit. Le jeton disparaît avec le reste, et
         * le contrôle ci-dessous concluait « session expirée ou requête
         * invalide » — un message faux, qui envoie la personne se reconnecter
         * et ressaisir, pour reperdre sa saisie au même endroit.
         *
         * On reconnaît le cas à ce qu'il a d'impossible autrement : le
         * navigateur a annoncé un corps, et il n'en est rien arrivé. Ni champ,
         * ni fichier.
         */
        $annonce = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($request->isPost() && $annonce > 0 && $request->body === [] && $request->files === []) {
            /*
             * Deux causes possibles, et deux phrases différentes : le corps
             * dépasse la limite de PHP, ou il est arrivé dans une forme que
             * PHP ne sait pas découper en champs. Dire « trop volumineux » à
             * qui a envoyé trois cents octets mal formés ne l'aiderait pas
             * davantage que le message d'origine.
             */
            $trop = $annonce > self::limiteDuCorps();
            \App\Modules\Logs::warning('systeme', 'corps.refuse',
                $trop ? 'Formulaire refusé : le corps dépasse ce que PHP accepte.'
                      : 'Formulaire refusé : le corps est arrivé illisible.',
                ['context' => [
                    'chemin' => mb_substr($request->path, 0, 200),
                    'annonce' => $annonce,
                    'limite' => (string) ini_get('post_max_size'),
                ]]);
            return $this->error(
                $trop ? t('err.bodyTooLarge') : t('err.bodyUnreadable'),
                413,
                base64_encode(random_bytes(16))
            );
        }

        // La seule route postée sans jeton, et pourquoi : la réponse SAML arrive
        // du fournisseur d'identité, par un formulaire qu'il poste lui-même. Elle
        // ne peut pas porter le jeton d'une session qui, à cet instant, n'existe
        // pas encore — le cookie « Lax » ne voyage pas sur un POST venu d'un
        // autre site. Ce qui tient lieu de jeton est plus fort : une assertion
        // signée par le fournisseur, adressée à ce site, qui répond à une demande
        // émise ici il y a moins de dix minutes, consommée une seule fois
        // (app/Modules/Saml.php).
        $sansJeton = $request->isPost() && in_array($request->path, self::SANS_JETON, true);
        if ($request->isPost() && !$sansJeton && !Csrf::matches($request->input('_csrf'))) {
            // Un jeton refusé n'est pas toujours une attaque — une session
            // expirée en produit aussi. C'est justement pour pouvoir faire la
            // différence, en regardant s'il y en a un ou trois cents, que la
            // ligne est journalisée.
            \App\Modules\Logs::warning('securite', 'csrf.refus',
                'Requête refusée : jeton de session absent ou invalide.',
                ['context' => ['chemin' => mb_substr($request->path, 0, 200), 'methode' => $request->method]]);
            return $this->refuse();
        }

        $guard = $this->guard($request, $user);
        if ($guard !== null) {
            return $guard;
        }

        $nonce = base64_encode(random_bytes(16));
        $this->share($request, $user, $nonce);

        $match = $this->router->match($request);
        if ($match === null) {
            return $this->error(t('err.notAccessible'), 404, $nonce);
        }
        if ($match['handler'] === null) {
            return $this->error(t('err.notAccessible'), 405, $nonce);
        }

        $response = ($match['handler'])($request, $match['params']);
        if (!$response instanceof Response) {
            throw new \RuntimeException('Une route doit renvoyer une réponse.');
        }
        return $response->withHeaders(Security::headers($nonce, $request->path));
    }

    /**
     * La session est revalidée à chaque requête : un compte fermé, un mot de
     * passe changé ailleurs ou une session trop vieille ne doivent pas survivre
     * parce que le cookie, lui, est encore là.
     */
    private function currentUser(): ?array
    {
        $session = Session::get('user');
        if (!is_array($session)) {
            return null;
        }
        $user = Users::byId((int) $session['id']);
        // Une session de coffre-fort est ouverte précisément parce que le compte
        // est fermé : le compte désactivé n'est donc pas un motif de révocation
        // ici — seule compte la disparition du compte ou de son coffre.
        $vaultOnly = (bool) Session::get('vault_only', false);
        if ($user === null || (!$vaultOnly && (int) $user['active'] !== 1)) {
            Session::destroy();
            return null;
        }
        if ($vaultOnly && !\App\Modules\Vault::hasDocuments((int) $user['id'])) {
            Session::destroy();
            return null;
        }
        $openedAt = (int) Session::get('opened_at', 0);
        $maxHours = (int) Config::get('session_max_hours', 12);
        if ($openedAt > 0 && time() - $openedAt > $maxHours * 3600) {
            Session::destroy();
            return null;
        }
        // Une session ouverte avant le dernier changement de mot de passe n'a
        // plus lieu d'être : c'est ce qui rend le changement utile en cas de vol.
        $changedAt = $user['password_changed_at'] ?? null;
        if ($changedAt && strtotime((string) $changedAt) > (int) Session::get('opened_at', 0)) {
            Session::destroy();
            return null;
        }
        Session::save();
        return $user;
    }

    private function prepareLocale(Request $request, ?array $user, bool $installed): void
    {
        $chosen = Session::get('locale');

        // Avant l'installation, la langue choisie à la première étape vit
        // aussi dans le brouillon de l'assistant. Elle y est reprise si la
        // session s'est perdue en route : l'assistant est précisément le
        // moment où l'on n'a pas encore de session solide.
        if (!$installed && !is_string($chosen)) {
            $brouillon = \App\Modules\Installation::brouillon();
            $chosen = $brouillon['locale'] ?? null;
        }

        // Tant que personne n'a installé, la langue de l'instance n'a été
        // choisie par personne : « fr » n'est qu'une valeur par défaut, et la
        // laisser gagner ferait lire l'assistant en français à quelqu'un dont
        // le navigateur demande l'allemand. Après l'installation, au
        // contraire, c'est un choix, et il prime.
        $instance = $installed ? Settings::get('default_locale') : null;

        I18n::use(I18n::negotiate(
            is_string($chosen) ? $chosen : ($user['locale'] ?? null),
            $instance,
            $request->header('accept-language')
        ));
    }

    /**
     * Un chemin de l'assistant d'installation — la nouvelle adresse, ou
     * l'ancienne qui y redirige.
     *
     * Trois gardes posaient la question, chacun à sa façon, et « /install »
     * comparé par préfixe attrapait aussi « /installation » : le jour où la
     * seconde est devenue une redirection, elle est passée à travers le
     * troisième garde, qui l'a renvoyée vers la page de connexion. Une seule
     * fonction, et la question se pose partout de la même manière.
     */
    private static function cheminDInstallation(string $chemin): bool
    {
        return $chemin === '/install' || str_starts_with($chemin, '/install/')
            || $chemin === '/installation' || str_starts_with($chemin, '/installation/');
    }

    /** Les portes : qui peut atteindre quoi. */
    private function guard(Request $request, ?array $user): ?Response
    {
        // Le suivi d'un signalement est la seule porte ouverte sans session :
        // il est gardé par la référence, le code et un plafond de tentatives.
        $public = ['/connexion', '/connexion/code', '/connexion/cle', '/connexion/sso', '/connexion/sso/retour',
                   '/connexion/saml', '/connexion/saml/acs', '/saml/metadata', '/langue', '/coffre-fort/acces',
                   '/alertes/suivi', '/alertes/suivi/message', '/manifest.webmanifest', '/icone.svg'];
        if (in_array($request->path, $public, true) || self::cheminDInstallation($request->path)) {
            return null;
        }
        if ($user === null) {
            return Response::redirect('/connexion');
        }
        // Session ouverte par code d'accès : hors du coffre, rien n'est
        // atteignable. Le verrou est ici, donc il ferme aussi ce qui sera
        // ajouté demain sans qu'on y pense.
        if ((bool) Session::get('vault_only', false)) {
            $allowed = ['/coffre-fort', '/deconnexion', '/langue'];
            $inside = false;
            foreach ($allowed as $prefix) {
                if ($request->path === $prefix || str_starts_with($request->path, $prefix . '/')) {
                    $inside = true;
                }
            }
            // La gestion du coffre reste fermée : l'ancien salarié vient
            // chercher ses documents, pas en déposer.
            if ($inside && str_starts_with($request->path, '/coffre-fort/gestion')) {
                $inside = false;
            }
            if (!$inside) {
                return $request->isPost()
                    ? $this->error('Cet accès ne permet que la consultation de votre coffre-fort.', 403, base64_encode(random_bytes(16)))
                    : Response::redirect('/coffre-fort');
            }
            return null;
        }
        // L'espace du salarié et son agenda appartiennent au personnel :
        // l'administration a sa console, et un compte d'administration n'a ni
        // solde de congés, ni manager, ni fiche de paie à y lire. Même règle
        // que l'édition Node, où ces deux espaces exigent le rôle « employee ».
        foreach (['/mon-espace', '/agenda'] as $staffOnly) {
            if (($request->path === $staffOnly || str_starts_with($request->path, $staffOnly . '/'))
                && $user['role'] !== 'employee') {
                return Response::redirect('/admin');
            }
        }

        /*
         * L'emprunt d'identité : il ferme les secrets de la personne empruntée,
         * et se termine de lui-même quand la durée est écoulée. Le verrou est ici,
         * donc il ferme aussi ce qui sera ajouté demain sans qu'on y pense.
         */
        $emprunt = \App\Modules\Emprunt::encours();
        if ($emprunt !== null) {
            if ($emprunt['expire']) {
                $verdict = \App\Modules\Emprunt::revenir();
                Flash::set('success', 'Emprunt terminé : la durée était écoulée.');
                return Response::redirect($verdict['ok'] ? '/admin' : '/connexion');
            }
            // Le retour et la déconnexion restent ouverts : fermer la sortie
            // serait enfermer l'administrateur dans l'identité qu'il a emprunté.
            $sortie = in_array($request->path, ['/emprunt/retour', '/deconnexion', '/langue'], true);
            if (!$sortie && \App\Modules\Emprunt::ferme($request->path)) {
                return $this->error(
                    "Pendant un emprunt d'identité, les secrets de la personne restent à elle.",
                    403,
                    base64_encode(random_bytes(16))
                );
            }
        }

        /*
         * Mot de passe à changer : aucune autre page tant que ce n'est pas fait.
         *
         * Sauf pendant un emprunt : ce mot de passe temporaire n'est pas celui de
         * qui regarde, l'administrateur n'a rien à y faire, et l'écran lui est de
         * toute façon fermé par le verrou ci-dessus. Sans cette exception,
         * emprunter l'identité de quelqu'un qui ne s'est jamais connecté
         * n'aboutirait qu'à cet écran — et donc à rien.
         */
        if ($emprunt === null
            && (int) $user['must_change_password'] === 1
            && $request->path !== '/mot-de-passe' && $request->path !== '/deconnexion') {
            return Response::redirect('/mot-de-passe');
        }
        // Les espaces fermés le sont d'un bloc : la porte est ici, elle ne
        // dépend pas de ce que chaque route pense à vérifier.
        $refuse = function () use ($user, $request): Response {
            Audit::log('acces.refuse', 'users', (int) $user['id'], ['chemin' => $request->path]);
            return $this->error(t('err.notAccessible'), 403, base64_encode(random_bytes(16)));
        };
        if (str_starts_with($request->path, '/admin') && $user['role'] !== 'admin') {
            return $refuse();
        }
        // Un module éteint n'a pas d'écrans : la page le dit, et propose à
        // l'administrateur d'aller l'activer plutôt que de le laisser deviner.
        // Le nom du module vient du dictionnaire, comme le message : l'un sans
        // l'autre donnerait une phrase traduite qui cite un mot français.
        $moduleEteint = function (string $cle) use ($user): Response {
            $message = $user['role'] === 'admin'
                ? t('mod.lockedAdmin', ['module' => t('mod.' . $cle . '.label')])
                : t('mod.locked');
            return $this->error($message, 404, base64_encode(random_bytes(16)));
        };

        /*
         * Les sections que le client n'a pas installées, et celles qu'il n'a
         * pas encore configurées.
         *
         * Une seule garde plutôt que cinquante : le chemin dit à quelle section
         * il appartient, et il n'y a donc rien à accrocher route par route — ce
         * qu'on oublie sur une route est un trou qu'on ne découvre qu'en
         * production.
         *
         * L'espace membre n'est pas concerné : il n'est dans aucune section, et
         * une entreprise qui n'installe pas la comptabilité ne retire pas pour
         * autant à ses salariés leurs congés et leur fiche de paie.
         */
        $sectionDuChemin = Sections::groupeDuChemin($request->path);
        if ($sectionDuChemin !== null) {
            if (!SectionsReglages::estActive($sectionDuChemin)) {
                // Comme un module éteint : la page dit ce qu'il en est, et à
                // l'administration seule elle dit où reposer la section.
                $message = $user['role'] === 'admin' ? t('sec.offAdmin') : t('sec.off');
                return $this->error($message, 404, base64_encode(random_bytes(16)));
            }
            /*
             * La première ouverture passe par l'assistant — pour
             * l'administration seule. Un salarié n'a pas les droits d'y
             * répondre : l'y envoyer l'enfermerait dans un écran qu'il ne peut
             * pas franchir, sur une section qui marche très bien avec ses
             * valeurs par défaut.
             *
             * Les écritures passent aussi : renvoyer un POST vers une page
             * perdrait ce que la personne vient de saisir, et l'assistant ne
             * s'ouvre de toute façon que sur un écran qu'on regarde.
             */
            if ($request->method === 'GET'
                && $user['role'] === 'admin'
                && !SectionsReglages::estConfiguree($sectionDuChemin)) {
                return Response::redirect('/section/' . $sectionDuChemin . '/assistant');
            }
        }

        // La console de sécurité et le registre des données personnelles
        // donnent à voir tout le monde : l'administration, et elle seule.
        if ((str_starts_with($request->path, '/securite') || str_starts_with($request->path, '/rgpd')
             || str_starts_with($request->path, '/sauvegardes') || str_starts_with($request->path, '/journaux')
             || str_starts_with($request->path, '/mises-a-jour')
             || str_starts_with($request->path, '/applications'))
            && $user['role'] !== 'admin') {
            return $refuse();
        }
        // Le paramétrage des circuits appartient à l'administration ; déposer
        // une demande et la décider restent ouverts à tout le monde.
        if (str_starts_with($request->path, '/demandes/types') && $user['role'] !== 'admin') {
            return $refuse();
        }
        // L'accès RH est donné par l'administration ; encadrer une équipe ne
        // l'ouvre pas : congés et fiches de paie ne sont pas des informations
        // d'équipe.
        if (str_starts_with($request->path, '/rh') && !\App\Controllers\HrController::canAccess($user)) {
            return $refuse();
        }
        // Déposer au coffre et émettre un code d'accès relèvent des RH ; le
        // retrait d'un document, lui, est réservé à l'administration (dans le
        // contrôleur, là où le motif se lit).
        if (str_starts_with($request->path, '/coffre-fort/gestion') && !VaultController::canManage($user)) {
            return $refuse();
        }
        // Mettre un document à la signature engage l'entreprise : réservé aussi.
        if (str_starts_with($request->path, '/parapheur')
            && ($request->path === '/parapheur' && $request->isPost()
                || str_ends_with($request->path, '/annuler')
                || str_ends_with($request->path, '/supprimer') && str_starts_with($request->path, '/parapheur/'))
            && !SigningController::canOpen($user)) {
            return $refuse();
        }
        // L'espace manager s'ouvre à qui encadre au moins un périmètre — la
        // relation, pas un droit posé à la main.
        if (str_starts_with($request->path, '/mon-equipe') && !ManagerController::canAccess($user)) {
            return $refuse();
        }
        // La gestion engage l'argent de l'entreprise : l'administration, et qui
        // elle a désigné. Les notes de frais d'un salarié passent, elles, par
        // /mon-espace, qui n'est pas derrière cette porte.
        if (str_starts_with($request->path, '/gestion') && !FinanceController::canAccess($user)) {
            return $refuse();
        }
        // Le parc logiciel et les accès applicatifs relèvent du service
        // informatique, désigné par l'administration ; ceux qui livrent et ceux
        // qui exploitent regardent le même référentiel.
        if ((str_starts_with($request->path, '/informatique') || str_starts_with($request->path, '/developpement'))
            && !ItController::canAccess($user)) {
            return $refuse();
        }
        // La flotte relève des moyens généraux, tenus par la gestion.
        if (str_starts_with($request->path, '/flotte') && !FleetController::canAccess($user)) {
            return $refuse();
        }
        // Les registres de l'accueil tiennent des données de tiers : RH et
        // administration, comme les autres registres de l'entreprise.
        if (str_starts_with($request->path, '/accueil') && !FrontDeskController::canAccess($user)) {
            return $refuse();
        }
        // La direction, c'est l'administration de l'instance : la gouvernance
        // n'est pas un droit qu'on délègue.
        if (str_starts_with($request->path, '/direction') && ($user === null || $user['role'] !== 'admin')) {
            return $refuse();
        }
        // Le CSE ne représente ni les administrateurs ni les freelances ; sa
        // gestion, elle, revient aux élus dont le mandat court encore.
        if (str_starts_with($request->path, '/cse')) {
            if (!CseController::canAccess($user)) {
                return $refuse();
            }
            if (str_starts_with($request->path, '/cse/gestion') && !CseController::isElected($user)) {
                return $refuse();
            }
        }
        // Le juridique est réservé à l'administration — sauf les déclarations
        // de conflits d'intérêts et de cadeaux, que chacun dépose pour soi. Un
        // registre que seuls les dirigeants alimentent ne recense que les leurs.
        if (str_starts_with($request->path, '/juridique') && ($user === null || $user['role'] !== 'admin')) {
            $ownDeclaration = $request->path === '/juridique'
                || $request->path === '/juridique/interets'
                || $request->path === '/juridique/cadeaux';
            if (!$ownDeclaration) {
                return $refuse();
            }
        }
        // La qualité se pilote au plus près du terrain : l'encadrement et
        // l'administration, sans créer un rôle de plus à administrer.
        if (str_starts_with($request->path, '/qualite') && !QualityController::canAccess($user)) {
            return $refuse();
        }
        // Le document unique, le registre des accidents et le suivi médical
        // sont des obligations de l'employeur : RH et administration.
        if (str_starts_with($request->path, '/sante-securite') && !SafetyController::canAccess($user)) {
            return $refuse();
        }
        // Les modules optionnels : éteints, leurs écrans n'existent pas, et ce
        // n'est pas à chaque route de s'en souvenir. La comptabilité suit les
        // droits de la gestion ; la paie, ceux des RH.
        if (str_starts_with($request->path, '/comptabilite')) {
            if (!\App\Modules\Catalogue::isEnabled('comptabilite')) {
                return $moduleEteint('comptabilite');
            }
            if (!AccountingController::canAccess($user)) {
                return $refuse();
            }
        }
        if (str_starts_with($request->path, '/tresorerie')) {
            if (!\App\Modules\Catalogue::isEnabled('tresorerie')) {
                return $moduleEteint('tresorerie');
            }
            if (!TreasuryController::canAccess($user)) {
                return $refuse();
            }
        }
        if (str_starts_with($request->path, '/immobilisations')) {
            if (!\App\Modules\Catalogue::isEnabled('immobilisations')) {
                return $moduleEteint('immobilisations');
            }
            if (!FixedAssetsController::canAccess($user)) {
                return $refuse();
            }
        }
        // Le stock est ouvert à tous : chacun demande ce dont il a besoin. Mais
        // les articles, les mouvements et les bons de commande engagent l'argent
        // de l'entreprise : ceux-là restent à la gestion.
        if (str_starts_with($request->path, '/stock')) {
            if (!\App\Modules\Catalogue::isEnabled('stock')) {
                return $moduleEteint('stock');
            }
            $reserved = false;
            foreach (['/stock/articles', '/stock/mouvements', '/stock/commandes', '/stock/lignes', '/stock/factures'] as $prefix) {
                if (str_starts_with($request->path, $prefix)) {
                    $reserved = true;
                }
            }
            // La décision de second niveau et le passage en commande aussi.
            if (str_ends_with($request->path, '/gestion') || str_ends_with($request->path, '/commander')) {
                $reserved = true;
            }
            if ($reserved && !FinanceController::canAccess($user)) {
                return $refuse();
            }
        }
        // Le pilotage regarde toute l'entreprise : direction, gestion et RH.
        if (str_starts_with($request->path, '/pilotage') && !SteeringController::canAccess($user)) {
            return $refuse();
        }
        // Une alerte peut viser un administrateur : les signalements ne sont
        // ouverts qu'aux référents désignés, et l'administration, qui les
        // désigne, n'y lit rien.
        if (str_starts_with($request->path, '/alertes/signalements/')
            && !AlertsController::isReferent($user)) {
            return $refuse();
        }
        // La fiche d'un tiers prolonge l'espace de gestion : même périmètre.
        if (str_starts_with($request->path, '/partenaires') && !FinanceController::canAccess($user)) {
            return $refuse();
        }
        // Les parcours d'arrivée et de départ tiennent des dossiers du personnel :
        // ils appartiennent aux ressources humaines, comme l'espace RH lui-même.
        if (str_starts_with($request->path, '/parcours')
            && !\App\Controllers\HrController::canAccess($user)) {
            return $refuse();
        }
        // La rédaction est réservée à l'administration, aux RH et aux managers ;
        // la lecture est ouverte, et c'est la portée de l'article qui la borne.
        if (str_starts_with($request->path, '/base-de-connaissances') && $request->isPost()
            && !KnowledgeController::canWrite($user)) {
            return $refuse();
        }
        // Un événement engage un budget et le temps de travail de l'entreprise :
        // l'organiser relève de l'administration et des RH. S'y inscrire, non :
        // c'est ouvert à tous, et ces routes-là sont donc laissées passer.
        if (str_starts_with($request->path, '/evenements') && $request->isPost()
            && !EventsController::canManage($user)) {
            $ownSeat = (bool) preg_match('#^/evenements/\d+/(inscription|desistement)$#', $request->path);
            if (!$ownSeat) {
                return $refuse();
            }
        }
        // La corbeille des pièces comptables contient les factures des
        // fournisseurs avant qu'elles n'entrent dans les comptes : la gestion,
        // et elle seule.
        if (str_starts_with($request->path, '/pieces') && !FinanceController::canAccess($user)) {
            return $refuse();
        }
        // Consulter le planning est ouvert à tous — un planning illisible ne sert
        // à personne. Le modifier engage les journées d'autrui : encadrement,
        // RH et administration seulement.
        if (str_starts_with($request->path, '/planning') && $request->isPost()
            && !PlanningController::canPlan($user)) {
            return $refuse();
        }
        // Ouvrir une porte d'entrée sur les données de l'entreprise relève de
        // l'administration seule, jamais d'un droit délégué.
        if (str_starts_with($request->path, '/integrations') && $user['role'] !== 'admin') {
            return $refuse();
        }
        /*
         * L'écran de licence relève de l'administration.
         *
         * Il montre l'empreinte de cette installation, l'état du bail, et il
         * porte la porte par laquelle l'instance écrit à l'éditeur avec sa clé.
         * Rien de cela n'est l'affaire d'un salarié — et l'édition Node le
         * réservait déjà depuis toujours : c'est l'alignement d'une divergence,
         * pas un durcissement nouveau.
         *
         * Cela ne touche pas « SANS_LICENCE » : une instance restreinte garde
         * cet écran ouvert, pour son administration. C'est la sortie, on ne la
         * ferme pas.
         */
        if (str_starts_with($request->path, '/licence') && $user['role'] !== 'admin') {
            return $refuse();
        }
        // Importer, c'est écrire en masse : l'accès suit le droit qu'il faudrait
        // pour saisir ces lignes une par une.
        if (str_starts_with($request->path, '/import') && !ImportController::canAccess($user)) {
            return $refuse();
        }
        // La facturation électronique est un module optionnel de la gestion.
        if (str_starts_with($request->path, '/facturation-electronique')) {
            if (!\App\Modules\Catalogue::isEnabled('facturation-electronique')) {
                return $moduleEteint('facturation-electronique');
            }
            if (!FinanceController::canAccess($user)) {
                return $refuse();
            }
        }
        // Un pipeline commercial dit ce que l'entreprise attend d'argent, et un
        // devis l'engage : module optionnel, et réservé à la gestion.
        if (str_starts_with($request->path, '/crm')) {
            if (!\App\Modules\Catalogue::isEnabled('crm')) {
                return $moduleEteint('crm');
            }
            if (!FinanceController::canAccess($user)) {
                return $refuse();
            }
        }
        if (str_starts_with($request->path, '/paie')) {
            if (!\App\Modules\Catalogue::isEnabled('paie')) {
                return $moduleEteint('paie');
            }
            if (!PayrollController::canAccess($user)) {
                return $refuse();
            }
        }
        return null;
    }

    private function share(Request $request, ?array $user, string $nonce): void
    {
        $locale = I18n::info();
        View::share([
            'nonce' => $nonce,
            'locale' => $locale['code'],
            'localeDir' => $locale['dir'],
            'locales' => I18n::LOCALES,
            'csrfToken' => Csrf::token(),
            'user' => $user,
            'sessionUser' => $user === null ? null : Users::forSession($user),
            'companyName' => Settings::get('company_name'),
            'brandInitials' => mb_strtoupper(mb_substr(Settings::get('company_name'), 0, 2)),
            /* La palette passe par le module, qui ne rend jamais qu'une valeur
               connue : lue en direct dans les réglages, une valeur aberrante
               ressortait telle quelle dans l'attribut « data-palette ». Échappée,
               donc sans danger, mais la page n'avait plus de palette du tout. */
            'palette' => Themes::current(),
            'flash' => Flash::take(),
            'path' => $request->path,
            // De quoi filtrer le rail des services : les mêmes drapeaux que les
            // gardes de route lisent, sous les noms qu'emploie le catalogue.
            // Un raccourci vers un écran qu'on ne peut pas ouvrir est une porte
            // peinte sur un mur — elle donne sur un refus.
            'droits' => $user === null ? ['role' => null] : [
                'role' => $user['role'],
                'finance' => (int) ($user['is_finance'] ?? 0) === 1,
                'rh' => (int) ($user['is_hr'] ?? 0) === 1,
                'informatique' => (int) ($user['is_it'] ?? 0) === 1,
                'encadrement' => \App\Modules\Org::isManager((int) $user['id']),
                // Un service qu'aucun module n'a débloqué n'a pas de route :
                // le rail ne doit pas le proposer.
                'modules' => array_map(
                    static fn (array $m): string => $m['key'],
                    \App\Modules\Catalogue::enabled()
                ),
                // Ni celui d'une section que le client n'a pas installée.
                'sections' => SectionsReglages::actives(),
            ],
        ]);
    }

    /**
     * Ce que PHP accepte comme corps, en octets.
     *
     * « post_max_size » s'écrit « 8M », « 512K », parfois « 0 » pour « pas de
     * limite ». On le lit tel qu'il est plutôt que de supposer une valeur :
     * un hébergeur mutualisé le descend souvent à deux mégaoctets, et c'est
     * justement là que le cas se produit.
     */
    private static function limiteDuCorps(): int
    {
        $brut = trim((string) ini_get('post_max_size'));
        if ($brut === '' || $brut === '0') {
            return PHP_INT_MAX;
        }
        $nombre = (int) $brut;
        return match (strtoupper(substr($brut, -1))) {
            'G' => $nombre * 1024 * 1024 * 1024,
            'M' => $nombre * 1024 * 1024,
            'K' => $nombre * 1024,
            default => $nombre,
        };
    }

    private function refuse(): Response
    {
        return $this->error('Session expirée ou requête invalide. Merci de recharger la page et de réessayer.', 403, base64_encode(random_bytes(16)));
    }

    public function error(string $message, int $status, string $nonce): Response
    {
        View::share(['nonce' => $nonce, 'csrfToken' => Csrf::token()]);
        $html = View::page('error', ['message' => $message, 'title' => $message]);
        return Response::html($html, $status)->withHeaders(Security::headers($nonce));
    }
}
