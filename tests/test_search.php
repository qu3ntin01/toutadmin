<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Org;
use App\Modules\Search;
use App\Modules\Users;

/**
 * Une instance où chaque espace contient une pièce portant le même mot :
 * « Vertane ». Ce que la recherche rend de ce mot dit exactement ce que la
 * personne avait le droit de voir.
 */
function seedSearch(): array
{
    $ids = seed();
    // L'annuaire porte le mot lui aussi : c'est la source ouverte à tous.
    Db::run('UPDATE users SET grade = ? WHERE id = ?', ['Référente Vertane', $ids['member']]);
    $ids['service'] = Db::insert("INSERT INTO departments (name) VALUES ('Production')");
    $ids['autre'] = Db::insert("INSERT INTO departments (name) VALUES ('Commerce')");

    Db::insert(
        "INSERT INTO kb_articles (title, category, body, visibility, scope_id, published)
         VALUES ('Procédure Vertane', 'Général', 'Le déroulé.', 'Entreprise', NULL, 1)"
    );
    Db::insert(
        "INSERT INTO kb_articles (title, category, body, visibility, scope_id, published)
         VALUES ('Consignes Vertane du service', 'Général', 'Interne.', 'Service', ?, 1)",
        [$ids['autre']]
    );
    Db::insert(
        "INSERT INTO kb_articles (title, category, body, visibility, scope_id, published)
         VALUES ('Note Vertane de direction', 'Général', 'Confidentiel.', 'Administration', NULL, 1)"
    );
    Db::insert(
        "INSERT INTO kb_articles (title, category, body, visibility, published)
         VALUES ('Brouillon Vertane', 'Général', 'Pas prêt.', 'Entreprise', 0)"
    );

    $ids['projetSien'] = Db::insert("INSERT INTO projects (code, name, status) VALUES ('P1', 'Refonte Vertane', 'En cours')");
    $ids['projetAutre'] = Db::insert("INSERT INTO projects (code, name, status) VALUES ('P2', 'Chantier Vertane', 'En cours')");
    $ids['projetArchive'] = Db::insert(
        "INSERT INTO projects (code, name, status, archived) VALUES ('P3', 'Ancien Vertane', 'Clos', 1)"
    );
    Db::run('INSERT INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)',
        [$ids['projetSien'], $ids['member'], 'Membre']);

    $ids['ticketSien'] = Db::insert(
        "INSERT INTO tickets (reference, subject, body, status, requester_id) VALUES ('T-1', 'Écran Vertane', '', 'Ouvert', ?)",
        [$ids['member']]
    );
    $ids['ticketAutre'] = Db::insert(
        "INSERT INTO tickets (reference, subject, body, status, requester_id) VALUES ('T-2', 'Portable Vertane', '', 'Ouvert', ?)",
        [$ids['admin']]
    );

    Db::insert("INSERT INTO partners (kind, name, email) VALUES ('Client', 'Vertane Industries', 'contact@vertane.fr')");
    Db::insert(
        "INSERT INTO invoices (reference, label, direction, status, issue_date, due_date, amount_ht, vat_rate)
         VALUES ('FA-VERTANE-1', 'Prestation', 'Client', 'Émise', '2026-01-10', '2026-02-10', 100, 20)"
    );
    Db::insert("INSERT INTO vehicles (registration, brand, model, kind) VALUES ('VERTANE-77', 'Renault', 'Kangoo', 'Utilitaire')");

    Db::insert(
        "INSERT INTO company_events (title, kind, description, location, starts_at, ends_at, status)
         VALUES ('Fête Vertane', 'Convivialité', '', 'Siège', '2099-06-01 18:00', '2099-06-01 23:00', 'Ouvert')"
    );
    Db::insert(
        "INSERT INTO company_events (title, kind, description, location, starts_at, ends_at, status)
         VALUES ('Séminaire Vertane', 'Séminaire', '', 'Siège', '2099-09-01 09:00', '2099-09-01 18:00', 'Brouillon')"
    );

    Db::insert("INSERT INTO software_licences (name, publisher, kind) VALUES ('Vertane CAO', 'Dassault', 'Abonnement')");
    Db::insert("INSERT INTO app_services (name, code, description, stack) VALUES ('Portail Vertane', 'PV', '', 'PHP')");

    Db::insert(
        "INSERT INTO decisions (title, body, rationale, decided_on, status) VALUES ('Vertane : plan 2027', '', '', '2026-03-01', 'En vigueur')"
    );
    Db::insert("INSERT INTO meetings (title, kind, held_on, agenda) VALUES ('Comité Vertane', 'Comité de direction', '2026-03-01', '')");
    Db::insert(
        "INSERT INTO enterprise_risks (reference, category, title, description, status) VALUES ('R-1', 'Opérationnel', 'Dépendance Vertane', '', 'Ouvert')"
    );

    Db::insert(
        "INSERT INTO nonconformities (reference, title, description, source, severity, detected_on, subject, status)
         VALUES ('NC-1', 'Écart Vertane', '', 'Audit', 'Majeure', '2026-02-01', 'Production', 'Ouverte')"
    );

    $opening = Db::insert("INSERT INTO job_openings (title, contract_type, status) VALUES ('Soudeur', 'CDI', 'Ouvert')");
    Db::insert(
        "INSERT INTO candidates (opening_id, first_name, last_name, email, stage) VALUES (?, 'Léa', 'Vertane', 'lea@exemple.fr', 'Reçue')",
        [$opening]
    );

    return $ids;
}

/** Les intitulés de sources rendus pour une personne donnée. */
function sourcesFor(int $userId): array
{
    $groups = Search::search('Vertane', (array) Users::byId($userId));
    return array_column($groups, 'source');
}

/** Les libellés d'une source, ou une liste vide si la source n'est pas rendue. */
function rowsOf(array $groups, string $source): array
{
    foreach ($groups as $group) {
        if ($group['source'] === $source) {
            return array_column($group['rows'], 'label');
        }
    }
    return [];
}

Tests::run('une recherche trop courte ne cherche rien', function (): void {
    $ids = seedSearch();
    $user = (array) Users::byId($ids['admin']);

    assertSame([], Search::search('', $user));
    assertSame([], Search::search('V', $user));
    assertSame([], Search::search('  V  ', $user), 'un espace ne fait pas une lettre de plus');
    assertTrue(Search::search('Ve', $user) !== [], 'deux caractères suffisent');
});

Tests::run('un salarié ne voit que les espaces qui lui sont ouverts', function (): void {
    $ids = seedSearch();
    $sources = sourcesFor($ids['member']);

    // Ouvert à tous : l'annuaire, les connaissances, ses projets, ses tickets,
    // les événements de l'entreprise.
    assertSame(['Annuaire', 'Connaissances', 'Projets', 'Tickets', 'Événements'], $sources);

    // Fermé : la gestion, l'informatique, la gouvernance, la qualité, les RH.
    foreach (['Tiers', 'Factures', 'Véhicules', 'Logiciels', 'Services applicatifs',
              'Décisions', 'Réunions', 'Risques', 'Qualité', 'Candidatures', 'Personnel'] as $closed) {
        assertTrue(!in_array($closed, $sources, true), "« $closed » ne s'ouvre pas à un salarié");
    }
});

Tests::run("la portée d'un article est appliquée à la recherche, pas à l'affichage", function (): void {
    $ids = seedSearch();
    $groups = Search::search('Vertane', (array) Users::byId($ids['member']));
    $titles = rowsOf($groups, 'Connaissances');

    assertSame(['Procédure Vertane'], $titles);
    // Un brouillon, un article d'un autre service et une note de direction
    // restent hors de portée.
    assertTrue(!in_array('Brouillon Vertane', $titles, true));
    assertTrue(!in_array('Consignes Vertane du service', $titles, true));
    assertTrue(!in_array('Note Vertane de direction', $titles, true));

    // Rattachée au service concerné, elle voit l'article de ce service.
    Db::run('UPDATE users SET department_id = ? WHERE id = ?', [$ids['autre'], $ids['member']]);
    $titles = rowsOf(Search::search('Vertane', (array) Users::byId($ids['member'])), 'Connaissances');
    assertSame(2, count($titles));
    assertTrue(in_array('Consignes Vertane du service', $titles, true));

    // L'administration voit tout ce qui est publié, la note comprise.
    $titles = rowsOf(Search::search('Vertane', (array) Users::byId($ids['admin'])), 'Connaissances');
    assertSame(3, count($titles));
    assertTrue(in_array('Note Vertane de direction', $titles, true));
});

Tests::run("on ne cherche que dans les projets et les tickets qui sont les siens", function (): void {
    $ids = seedSearch();
    $groups = Search::search('Vertane', (array) Users::byId($ids['member']));

    assertSame(['Refonte Vertane'], rowsOf($groups, 'Projets'), "le projet d'un autre ne remonte pas");
    assertSame(['Écran Vertane'], rowsOf($groups, 'Tickets'), "le ticket d'un autre ne remonte pas");

    // Diriger un projet vaut d'y être : c'est la même appartenance.
    Db::run('UPDATE projects SET lead_id = ? WHERE id = ?', [$ids['member'], $ids['projetAutre']]);
    $groups = Search::search('Vertane', (array) Users::byId($ids['member']));
    assertSame(2, count(rowsOf($groups, 'Projets')));

    // Un projet archivé n'est plus une pièce vivante : il sort de la recherche
    // même pour qui pilote.
    $titles = rowsOf(Search::search('Vertane', (array) Users::byId($ids['admin'])), 'Projets');
    assertSame(2, count($titles));
    assertTrue(!in_array('Ancien Vertane', $titles, true));
});

Tests::run('un droit délégué ouvre sa part, et rien de plus', function (): void {
    $ids = seedSearch();

    // La gestion ouvre les tiers, les factures et la flotte — pas la gouvernance.
    Users::setRoleFlag($ids['member'], 'is_finance', true);
    $sources = sourcesFor($ids['member']);
    foreach (['Tiers', 'Factures', 'Véhicules'] as $open) {
        assertTrue(in_array($open, $sources, true), "la gestion ouvre « $open »");
    }
    assertTrue(!in_array('Décisions', $sources, true), 'la gestion n’ouvre pas la gouvernance');
    assertTrue(!in_array('Candidatures', $sources, true), 'la gestion n’ouvre pas les RH');
    // Piloter la gestion vaut de voir tous les projets vivants, pas seulement les siens.
    assertSame(2, count(rowsOf(Search::search('Vertane', (array) Users::byId($ids['member'])), 'Projets')));
    Users::setRoleFlag($ids['member'], 'is_finance', false);

    // L'informatique ouvre le parc et les services applicatifs.
    Users::setRoleFlag($ids['member'], 'is_it', true);
    $sources = sourcesFor($ids['member']);
    assertTrue(in_array('Logiciels', $sources, true), "l'informatique voit le parc logiciel");
    assertTrue(in_array('Services applicatifs', $sources, true), "l'informatique voit ses services");
    assertTrue(!in_array('Factures', $sources, true), "l'informatique n'ouvre pas la gestion");
    Users::setRoleFlag($ids['member'], 'is_it', false);

    // Les RH ouvrent les candidatures et le personnel.
    Users::setRoleFlag($ids['member'], 'is_hr', true);
    Db::run('UPDATE users SET last_name = ? WHERE id = ?', ['Vertane', $ids['member']]);
    $sources = sourcesFor($ids['member']);
    assertTrue(in_array('Candidatures', $sources, true), 'les RH voient les candidatures');
    assertTrue(in_array('Personnel', $sources, true), 'les RH voient le personnel');
    assertTrue(!in_array('Réunions', $sources, true), "les RH n'ouvrent pas la gouvernance");
    assertTrue(!in_array('Logiciels', $sources, true), "un droit retiré referme sa source");
});

Tests::run("la gouvernance et la qualité ne s'ouvrent qu'à qui les tient", function (): void {
    $ids = seedSearch();

    // L'administration voit tout ; c'est le seul cas.
    $sources = sourcesFor($ids['admin']);
    foreach (['Décisions', 'Réunions', 'Risques', 'Qualité'] as $source) {
        assertTrue(in_array($source, $sources, true), "l'administration voit « $source »");
    }

    // Un manager voit la qualité, parce que l'écran qui la porte lui est ouvert,
    // mais pas les relevés de décisions.
    $team = Org::createTeam('Atelier', $ids['service']);
    Org::addManager('team', $team, $ids['member']);
    $sources = sourcesFor($ids['member']);
    assertTrue(in_array('Qualité', $sources, true));
    assertTrue(!in_array('Décisions', $sources, true), 'encadrer n’est pas diriger');
    // Encadrer vaut d'être en charge du support : tous les tickets remontent.
    assertSame(2, count(rowsOf(Search::search('Vertane', (array) Users::byId($ids['member'])), 'Tickets')));
});

Tests::run("un événement non ouvert n'existe pas encore pour l'entreprise", function (): void {
    $ids = seedSearch();
    $titles = rowsOf(Search::search('Vertane', (array) Users::byId($ids['member'])), 'Événements');

    assertSame(['Fête Vertane'], $titles);
    // Même l'administration ne trouve pas un brouillon par la recherche : il se
    // gère sur son écran, pas ici.
    assertSame(['Fête Vertane'], rowsOf(Search::search('Vertane', (array) Users::byId($ids['admin'])), 'Événements'));
});

Tests::run("qui est retiré de l'annuaire n'en sort pas par la recherche", function (): void {
    $ids = seedSearch();
    Db::run('UPDATE users SET first_name = ?, last_name = ? WHERE id = ?', ['Paul', 'Vertane', $ids['member']]);

    assertSame(1, count(rowsOf(Search::search('Vertane', (array) Users::byId($ids['admin'])), 'Annuaire')));

    Db::run('UPDATE users SET directory_hidden = 1 WHERE id = ?', [$ids['member']]);
    assertSame([], rowsOf(Search::search('Vertane', (array) Users::byId($ids['admin'])), 'Annuaire'));

    // Les RH le retrouvent quand même, mais par le personnel — leur espace à eux.
    Users::setRoleFlag($ids['admin'], 'is_hr', true);
    assertSame(['Paul Vertane'], rowsOf(Search::search('Vertane', (array) Users::byId($ids['admin'])), 'Personnel'));
});

Tests::run('chaque source est bornée, et un résultat mène quelque part', function (): void {
    $ids = seedSearch();
    for ($i = 0; $i < 12; $i++) {
        Db::insert(
            "INSERT INTO kb_articles (title, category, body, visibility, published)
             VALUES (?, 'Général', '', 'Entreprise', 1)",
            ['Vertane ' . $i]
        );
    }

    $groups = Search::search('Vertane', (array) Users::byId($ids['admin']));
    foreach ($groups as $group) {
        assertTrue(count($group['rows']) <= 8, "« {$group['source']} » ne rend pas plus de huit lignes");
        foreach ($group['rows'] as $row) {
            assertTrue(str_starts_with((string) $row['link'], '/'), 'chaque ligne mène à une page');
            assertTrue(trim((string) $row['label']) !== '', 'chaque ligne porte un libellé');
        }
    }
});

Tests::run('un caractère de requête ne devient jamais du SQL', function (): void {
    $ids = seedSearch();
    $user = (array) Users::byId($ids['admin']);

    // Le pire cas classique : la recherche le traite comme du texte, et ne
    // trouve simplement rien.
    assertSame([], Search::search("' OR 1=1 --", $user));
    assertSame([], Search::search('Vertane"; DROP TABLE users; --', $user));
    assertSame(2, (int) Db::value('SELECT COUNT(*) FROM users'), 'la table est intacte');
});

// ---------- L'écran ----------

Tests::run("l'écran de recherche demande une session, et rend ce qu'il a trouvé", function (): void {
    $ids = seedSearch();
    assertSame(302, visit('GET', '/recherche')->status);

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);

    // Sans question, l'écran ne montre que la barre.
    $blank = visit('GET', '/recherche')->body;
    assertTrue(!str_contains($blank, 'Refonte Vertane'));

    $found = visit('GET', '/recherche', [], ['q' => 'Vertane'])->body;
    assertContains('Refonte Vertane', $found);
    assertContains('/projets/' . $ids['projetSien'], $found);
    // Ce qui ne lui est pas ouvert n'apparaît pas davantage sur l'écran.
    assertTrue(!str_contains($found, 'Chantier Vertane'));
    assertTrue(!str_contains($found, 'Note Vertane de direction'));
    assertTrue(!str_contains($found, 'FA-VERTANE-1'));

    // Rien trouvé : la question est rendue échappée, jamais réinjectée telle quelle.
    $none = visit('GET', '/recherche', [], ['q' => '<script>alert(1)</script>'])->body;
    assertTrue(!str_contains($none, '<script>alert(1)</script>'), 'la question ne revient pas en balise');
    assertContains('&lt;script&gt;', $none);
});
