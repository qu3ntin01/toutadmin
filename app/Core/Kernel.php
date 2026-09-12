<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\AdminController;
use App\Controllers\AgendaController;
use App\Controllers\AuthController;
use App\Controllers\DirectoryController;
use App\Controllers\FinanceController;
use App\Controllers\HrController;
use App\Controllers\ManagerController;
use App\Controllers\InstallController;
use App\Controllers\MemberController;
use App\Controllers\MessagesController;
use App\Controllers\NotificationsController;
use App\Controllers\OrgChartController;
use App\Controllers\ProfileController;
use App\Controllers\ProjectsController;
use App\Controllers\RequestsController;
use App\Controllers\RoomsController;
use App\Controllers\SupportController;
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

        // Notes de frais côté salarié : déposer et retirer les siennes.
        $router->post('/mon-espace/frais', FinanceController::createClaim(...));
        $router->post('/mon-espace/frais/{id}/annuler', FinanceController::cancelClaim(...));

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
        if ($user === null || (int) $user['active'] !== 1) {
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
        $public = ['/connexion', '/connexion/code', '/installation', '/langue'];
        if (in_array($request->path, $public, true)) {
            return null;
        }
        if ($user === null) {
            return Response::redirect('/connexion');
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
