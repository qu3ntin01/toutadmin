<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\AdminController;
use App\Controllers\AccountingController;
use App\Controllers\AgendaController;
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
use App\Controllers\SecurityController;
use App\Controllers\StockController;
use App\Controllers\SigningController;
use App\Controllers\SupportController;
use App\Controllers\SurveysController;
use App\Controllers\TreasuryController;
use App\Controllers\VaultController;
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
        $router->get('/installation', InstallController::form(...));
        $router->post('/installation', InstallController::submit(...));

        $router->get('/', AuthController::home(...));
        $router->get('/connexion', AuthController::loginForm(...));
        $router->post('/connexion', AuthController::login(...));
        $router->get('/connexion/code', AuthController::totpForm(...));
        $router->post('/connexion/code', AuthController::totpSubmit(...));
        $router->post('/deconnexion', AuthController::logout(...));
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
        $router->post('/admin/employes/{id}/statut', AdminController::toggleEmployee(...));
        $router->post('/admin/employes/{id}/reinitialiser', AdminController::resetEmployeePassword(...));
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

        // Espace RH : congés, soldes, fiches de paie.
        $router->get('/rh', HrController::home(...));
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
        // Photo de profil : l'envoi, le retrait, et le fichier lui-même.
        $router->post('/mon-profil/photo', ProfileController::uploadPhoto(...));
        $router->post('/mon-profil/photo/supprimer', ProfileController::deletePhoto(...));
        $router->get('/media/avatars/{name}', ProfileController::photo(...));

        // Pièces reçues : dépôt, relève de la boîte aux lettres, mise en facture.
        $router->get('/pieces', PiecesController::index(...));
        $router->post('/pieces/deposer', PiecesController::deposit(...));
        $router->post('/pieces/capture/reglages', PiecesController::saveCapture(...));
        $router->post('/pieces/capture/tester', PiecesController::testCapture(...));
        $router->post('/pieces/capture/relever', PiecesController::runCapture(...));
        $router->post('/pieces/analyse/reglages', PiecesController::saveAnalysis(...));
        $router->post('/pieces/analyse/tester', PiecesController::testAnalysis(...));
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

        // Support : tickets internes et clients.
        $router->get('/support', SupportController::index(...));
        $router->post('/support/tickets', SupportController::create(...));
        $router->get('/support/tickets/{id}', SupportController::show(...));
        $router->post('/support/tickets/{id}/repondre', SupportController::reply(...));
        $router->post('/support/tickets/{id}/statut', SupportController::setStatus(...));
        $router->post('/support/tickets/{id}/priorite', SupportController::setPriority(...));
        $router->post('/support/tickets/{id}/affecter', SupportController::assign(...));
        $router->post('/support/tickets/{id}/supprimer', SupportController::remove(...));

        // Gestion : tiers, contrats, factures, budgets, notes de frais, devises.
        $router->get('/gestion', FinanceController::index(...));
        $router->post('/gestion/tiers', FinanceController::createPartner(...));
        $router->post('/gestion/tiers/{id}/statut', FinanceController::togglePartner(...));
        $router->post('/gestion/tiers/{id}/supprimer', FinanceController::deletePartner(...));
        $router->post('/gestion/contrats', FinanceController::createContract(...));
        $router->post('/gestion/contrats/{id}/statut', FinanceController::setContractStatus(...));
        $router->post('/gestion/contrats/{id}/supprimer', FinanceController::deleteContract(...));
        $router->post('/gestion/factures', FinanceController::createInvoice(...));
        $router->post('/gestion/factures/{id}/statut', FinanceController::setInvoiceStatus(...));
        $router->post('/gestion/factures/{id}/supprimer', FinanceController::deleteInvoice(...));
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
        $router->post('/gestion/equipements', FinanceController::createAsset(...));
        $router->post('/gestion/equipements/{id}/affecter', FinanceController::assignAsset(...));
        $router->post('/gestion/equipements/{id}/reprendre', FinanceController::takeBackAsset(...));
        $router->post('/gestion/equipements/{id}/statut', FinanceController::setAssetStatus(...));
        $router->post('/gestion/equipements/{id}/supprimer', FinanceController::deleteAsset(...));

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
        $router->post('/securite/journal/purger', SecurityController::purgeJournal(...));

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
        $router->get('/annuaire', DirectoryController::index(...));
        $router->get('/mon-profil', ProfileController::show(...));
        $router->post('/mon-profil', ProfileController::update(...));
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
        if (!$installed && !str_starts_with($request->path, '/installation')) {
            return Response::redirect('/installation');
        }
        if ($installed && str_starts_with($request->path, '/installation')) {
            return Response::redirect('/connexion');
        }

        $user = $this->currentUser();
        $this->prepareLocale($request, $user);

        if ($request->isPost() && !Csrf::matches($request->input('_csrf'))) {
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
        return $response->withHeaders(Security::headers($nonce));
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

    private function prepareLocale(Request $request, ?array $user): void
    {
        $chosen = Session::get('locale');
        I18n::use(I18n::negotiate(
            is_string($chosen) ? $chosen : ($user['locale'] ?? null),
            Settings::get('default_locale'),
            $request->header('accept-language')
        ));
    }

    /** Les portes : qui peut atteindre quoi. */
    private function guard(Request $request, ?array $user): ?Response
    {
        $public = ['/connexion', '/connexion/code', '/installation', '/langue', '/coffre-fort/acces'];
        if (in_array($request->path, $public, true)) {
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
        // Mot de passe à changer : aucune autre page tant que ce n'est pas fait.
        if ((int) $user['must_change_password'] === 1 && $request->path !== '/mot-de-passe' && $request->path !== '/deconnexion') {
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
        // La console de sécurité et le registre des données personnelles
        // donnent à voir tout le monde : l'administration, et elle seule.
        if ((str_starts_with($request->path, '/securite') || str_starts_with($request->path, '/rgpd')
             || str_starts_with($request->path, '/sauvegardes'))
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
                return $this->error(t('err.notAccessible'), 404, base64_encode(random_bytes(16)));
            }
            if (!AccountingController::canAccess($user)) {
                return $refuse();
            }
        }
        if (str_starts_with($request->path, '/tresorerie')) {
            if (!\App\Modules\Catalogue::isEnabled('tresorerie')) {
                return $this->error(t('err.notAccessible'), 404, base64_encode(random_bytes(16)));
            }
            if (!TreasuryController::canAccess($user)) {
                return $refuse();
            }
        }
        if (str_starts_with($request->path, '/immobilisations')) {
            if (!\App\Modules\Catalogue::isEnabled('immobilisations')) {
                return $this->error(t('err.notAccessible'), 404, base64_encode(random_bytes(16)));
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
                return $this->error(t('err.notAccessible'), 404, base64_encode(random_bytes(16)));
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
        if (str_starts_with($request->path, '/paie')) {
            if (!\App\Modules\Catalogue::isEnabled('paie')) {
                return $this->error(t('err.notAccessible'), 404, base64_encode(random_bytes(16)));
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
            'palette' => Settings::get('theme_palette'),
            'flash' => Flash::take(),
            'path' => $request->path,
        ]);
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
