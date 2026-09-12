<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Org;
use App\Modules\Quality;
use App\Modules\Safety;
use App\Modules\Users;

/** Une instance où la qualité a un pilote : un manager, en plus de l'administration. */
function seedQuality(): array
{
    $ids = seed();
    $ids['manager'] = Users::create([
        'role' => 'employee', 'email' => 'manager@entreprise.com', 'password' => 'Manager-Demo-2026!',
        'first_name' => 'Paul', 'last_name' => 'Rivière',
    ]);
    $ids['team'] = Org::createTeam('Production', null);
    Org::addManager('team', $ids['team'], $ids['manager']);
    Db::run('UPDATE users SET team_id = ? WHERE id = ?', [$ids['team'], $ids['member']]);
    return $ids;
}

Tests::run("la qualité s'ouvre à l'encadrement, pas à tout le monde", function (): void {
    seedQuality();

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', '/qualite')->status, 'un salarié voit la qualité');
    assertSame(403, visit('POST', '/qualite/non-conformites', ['title' => 'Test', 'source' => 'Interne',
        'severity' => 'Mineure', 'detected_on' => gmdate('Y-m-d')])->status);
    assertSame(0, count(Quality::nonconformities()));

    visit('POST', '/connexion', ['email' => 'manager@entreprise.com', 'password' => 'Manager-Demo-2026!']);
    assertSame(200, visit('GET', '/qualite')->status, "un manager n'entre pas dans la qualité");

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    assertSame(200, visit('GET', '/qualite')->status);
});

Tests::run('une non-conformité porte une référence séquentielle par année', function (): void {
    seedQuality();
    $year = (int) gmdate('Y');

    $first = Quality::createNonconformity([
        'title' => 'Lot livré hors tolérance', 'source' => 'Client', 'severity' => 'Majeure',
        'detectedOn' => gmdate('Y-m-d'),
    ]);
    $second = Quality::createNonconformity([
        'title' => 'Étiquetage absent', 'source' => 'Interne', 'severity' => 'Mineure',
        'detectedOn' => gmdate('Y-m-d'),
    ]);

    assertSame("NC-$year-001", Quality::nonconformityById($first)['reference']);
    assertSame("NC-$year-002", Quality::nonconformityById($second)['reference']);
    assertSame('Ouverte', Quality::nonconformityById($first)['status']);
});

Tests::run('une non-conformité ne se clôture pas avec des actions en cours', function (): void {
    $ids = seedQuality();
    $nc = Quality::createNonconformity([
        'title' => 'Lot hors tolérance', 'source' => 'Client', 'severity' => 'Majeure',
        'detectedOn' => gmdate('Y-m-d'),
    ]);
    $action = Quality::createAction([
        'nonconformityId' => $nc, 'kind' => 'Corrective', 'label' => 'Reprendre le réglage',
        'ownerId' => $ids['member'],
    ]);

    $refused = Quality::setNonconformityStatus($nc, 'Clôturée');
    assertTrue(!$refused['ok'], 'la clôture passe malgré une action ouverte');
    assertContains('encore en cours', $refused['message']);
    assertSame('Ouverte', Quality::nonconformityById($nc)['status']);

    // L'action faite, la clôture devient possible et date du jour.
    assertTrue(Quality::setActionStatus($action, 'Faite'));
    assertTrue(Quality::setNonconformityStatus($nc, 'Clôturée')['ok']);
    $closed = Quality::nonconformityById($nc);
    assertSame('Clôturée', $closed['status']);
    assertSame(gmdate('Y-m-d'), $closed['closed_on']);

    // Rouvrir efface la date de clôture : elle ne vaut plus rien.
    assertTrue(Quality::setNonconformityStatus($nc, 'En traitement')['ok']);
    assertSame(null, Quality::nonconformityById($nc)['closed_on']);
    assertTrue(!Quality::setNonconformityStatus($nc, 'Archivée')['ok']);
});

Tests::run("une action en cours ne peut pas être jugée efficace", function (): void {
    $ids = seedQuality();
    $action = Quality::createAction(['kind' => 'Préventive', 'label' => 'Former les opérateurs']);

    $refused = Quality::verifyAction($action, 'Efficace', $ids['admin']);
    assertTrue(!$refused['ok']);
    assertContains('encore en cours', $refused['message']);

    Quality::setActionStatus($action, 'Faite');
    assertTrue(!Quality::verifyAction($action, 'Non vérifiée', $ids['admin'])['ok'], 'un non-verdict passe');
    assertTrue(Quality::verifyAction($action, 'Inefficace', $ids['admin'])['ok']);

    $row = Db::get('SELECT * FROM quality_actions WHERE id = ?', [$action]);
    assertSame('Inefficace', $row['effectiveness']);
    assertSame(gmdate('Y-m-d'), $row['verified_on']);
    assertSame($ids['admin'], (int) $row['verified_by']);
});

Tests::run("les indicateurs comptent à part ce qui est fait et ce qui est efficace", function (): void {
    $ids = seedQuality();
    $nc = Quality::createNonconformity([
        'title' => 'Écart de process', 'source' => 'Audit', 'severity' => 'Critique',
        'detectedOn' => gmdate('Y-m-d'), 'cost' => 1250.5,
    ]);
    $done = Quality::createAction(['nonconformityId' => $nc, 'kind' => 'Corrective', 'label' => 'Corriger']);
    Quality::createAction(['nonconformityId' => $nc, 'kind' => 'Corrective', 'label' => 'Vérifier']);
    Quality::setActionStatus($done, 'Faite');

    $summary = Quality::summary();
    assertSame(1, $summary['open']);
    assertSame(1, $summary['critical']);
    assertSame(1, $summary['openActions'], 'les actions faites comptent encore comme ouvertes');
    assertSame(1, $summary['awaitingVerification']);
    assertSame(0, $summary['ineffective']);
    assertSame(1250.5, $summary['cost']);

    Quality::verifyAction($done, 'Inefficace', $ids['admin']);
    assertSame(0, Quality::summary()['awaitingVerification']);
    assertSame(1, Quality::summary()['ineffective']);
});

Tests::run("seul un constat de non-conformité devient une non-conformité", function (): void {
    $ids = seedQuality();
    $audit = Quality::createAudit(['scope' => 'Processus achats', 'standard' => 'ISO 9001:2015',
        'plannedOn' => gmdate('Y-m-d')]);
    $remark = Quality::addFinding($audit, 'Remarque', '7.1', 'Le tableau de bord est peu lisible.');
    $gap = Quality::addFinding($audit, 'Non-conformité', '8.4.1', "Les fournisseurs ne sont pas évalués.");

    assertTrue(!Quality::promoteFinding($remark, $ids['admin'])['ok'], 'une remarque devient une non-conformité');
    assertSame(0, count(Quality::nonconformities()));

    $promoted = Quality::promoteFinding($gap, $ids['admin']);
    assertTrue($promoted['ok']);
    $nc = Quality::nonconformityById($promoted['id']);
    assertSame('Audit', $nc['source']);
    assertSame('Majeure', $nc['severity']);
    assertSame('Processus achats', $nc['subject']);
    assertContains('exigence 8.4.1', $nc['description']);
    assertContains(Quality::auditById($audit)['reference'], $nc['description']);
});

Tests::run("un audit réalisé garde sa synthèse et ses constats", function (): void {
    seedQuality();
    $audit = Quality::createAudit(['scope' => 'Atelier', 'plannedOn' => '2026-01-15']);
    Quality::addFinding($audit, 'Point fort', '', 'Traçabilité exemplaire.');

    assertSame('Planifié', Quality::auditById($audit)['status']);
    Quality::completeAudit($audit, '2026-01-20', 'Deux écarts mineurs, un point fort.');

    $done = Quality::auditById($audit);
    assertSame('Réalisé', $done['status']);
    assertSame('2026-01-20', $done['done_on']);
    assertContains('point fort', $done['summary']);
    assertSame(1, count(Quality::findings($audit)));

    // Supprimer l'audit emporte ses constats : ils n'existent que par lui.
    Quality::deleteAudit($audit);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM audit_findings'));
});

Tests::run("l'écran de qualité passe par les routes, pas par le module", function (): void {
    seedQuality();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);

    visit('POST', '/qualite/non-conformites', [
        'title' => 'Colis abîmé à la réception', 'source' => 'Fournisseur', 'severity' => 'Majeure',
        'detected_on' => gmdate('Y-m-d'), 'cost' => '1 250,50', 'subject' => 'Réception',
    ]);
    $list = Quality::nonconformities();
    assertSame(1, count($list));
    assertSame(1250.5, (float) $list[0]['cost'], 'le montant tapé à la française est refusé');

    // Une origine inventée n'entre pas en base.
    visit('POST', '/qualite/non-conformites', ['title' => 'Autre', 'source' => 'Inventée',
        'severity' => 'Mineure', 'detected_on' => gmdate('Y-m-d')]);
    assertSame(1, count(Quality::nonconformities()));

    // Un coût illisible non plus.
    visit('POST', '/qualite/non-conformites', ['title' => 'Autre', 'source' => 'Interne',
        'severity' => 'Mineure', 'detected_on' => gmdate('Y-m-d'), 'cost' => 'beaucoup']);
    assertSame(1, count(Quality::nonconformities()));

    $page = visit('GET', '/qualite');
    assertSame(200, $page->status);
    assertContains('Colis abîmé', $page->body);
    assertContains($list[0]['reference'], $page->body);
});

// ---------------------------------------------------------------- santé et sécurité

Tests::run('le document unique relève des RH, pas de tout le monde', function (): void {
    seedQuality();

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', '/sante-securite')->status);
    visit('POST', '/connexion', ['email' => 'manager@entreprise.com', 'password' => 'Manager-Demo-2026!']);
    assertSame(403, visit('GET', '/sante-securite')->status, 'encadrer ouvre le suivi médical');

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    assertSame(200, visit('GET', '/sante-securite')->status);
});

Tests::run('un risque est coté, et les plus criticables remontent', function (): void {
    seedQuality();
    Safety::createRisk(['unit' => 'Atelier', 'hazard' => 'Chute de plain-pied', 'severity' => 2,
        'likelihood' => 2, 'measures' => 'Sol antidérapant']);
    Safety::createRisk(['unit' => 'Atelier', 'hazard' => 'Écrasement', 'severity' => 4,
        'likelihood' => 3, 'measures' => 'Consignation']);

    $risks = Safety::risks();
    assertSame('Écrasement', $risks[0]['hazard'], 'le plus critique ne remonte pas en tête');
    assertSame(12, $risks[0]['score']);
    assertTrue($risks[0]['critical']);
    assertSame(4, $risks[1]['score']);
    assertTrue(!$risks[1]['critical'], 'un risque sous le seuil est marqué critique');
});

Tests::run("un accident ne se consigne pas à l'avance", function (): void {
    seedQuality();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);

    $tomorrow = gmdate('Y-m-d', strtotime('+1 day'));
    visit('POST', '/sante-securite/accidents', ['occurred_on' => $tomorrow, 'kind' => 'Accident du travail']);
    assertSame(0, count(Safety::incidents()));

    // Une nature inconnue, un arrêt négatif : refusés tous les deux.
    visit('POST', '/sante-securite/accidents', ['occurred_on' => gmdate('Y-m-d'), 'kind' => 'Incident']);
    visit('POST', '/sante-securite/accidents', ['occurred_on' => gmdate('Y-m-d'),
        'kind' => 'Accident du travail', 'days_off' => '-3']);
    assertSame(0, count(Safety::incidents()));

    visit('POST', '/sante-securite/accidents', ['occurred_on' => gmdate('Y-m-d'),
        'kind' => 'Accident du travail', 'days_off' => '4', 'location' => 'Atelier 2']);
    $incidents = Safety::incidents();
    assertSame(1, count($incidents));
    assertSame(4, (int) $incidents[0]['days_off']);
});

Tests::run('les taux de fréquence et de gravité suivent la formule légale', function (): void {
    $ids = seedQuality();
    // Deux salariés actifs : 2 × 1607 = 3214 heures travaillées.
    Safety::createIncident(['occurredOn' => gmdate('Y-m-d'), 'userId' => $ids['member'],
        'kind' => 'Accident du travail', 'daysOff' => 10]);
    // Un presque-accident ne compte pas, un accident sans arrêt non plus.
    Safety::createIncident(['occurredOn' => gmdate('Y-m-d'), 'kind' => 'Presque-accident', 'daysOff' => 5]);
    Safety::createIncident(['occurredOn' => gmdate('Y-m-d'), 'kind' => 'Accident de trajet', 'daysOff' => 0]);
    // Ni un accident d'il y a plus d'un an.
    Safety::createIncident(['occurredOn' => gmdate('Y-m-d', strtotime('-2 years')),
        'kind' => 'Accident du travail', 'daysOff' => 30]);

    $indicators = Safety::indicators();
    assertSame(2, $indicators['headcount']);
    assertSame(3214, $indicators['worked']);
    assertSame(1, $indicators['accidents']);
    assertSame(10, $indicators['daysOff']);
    assertSame(round(1 * 1e6 / 3214, 2), $indicators['frequency']);
    assertSame(round(10 * 1e3 / 3214, 2), $indicators['severity']);
});

Tests::run("l'échéance d'une protection découle de sa durée de validité", function (): void {
    $ids = seedQuality();
    $helmet = Safety::createPpe('Casque de chantier', 'Protection', 60);
    $gloves = Safety::createPpe('Gants', 'Protection', null);

    $given = Safety::issuePpe($helmet, $ids['member'], '2026-01-31');
    assertSame('2031-01-31', Db::get('SELECT * FROM ppe_assignments WHERE id = ?', [$given])['expires_on']);

    $forever = Safety::issuePpe($gloves, $ids['member'], '2026-01-31');
    assertSame(null, Db::get('SELECT * FROM ppe_assignments WHERE id = ?', [$forever])['expires_on']);
    assertSame(null, Safety::issuePpe(9999, $ids['member'], '2026-01-31'));

    assertSame(2, count(Safety::ppeAssignments()));
    Safety::returnPpe($given);
    assertSame(1, count(Safety::ppeAssignments()), 'une protection rendue reste en circulation');
    assertSame(2, count(Safety::ppeAssignments(false)), "l'historique des remises se perd");
});

Tests::run('les échéances à soixante jours réunissent visites, protections et risques', function (): void {
    $ids = seedQuality();
    $soon = gmdate('Y-m-d', strtotime('+30 days'));
    $later = gmdate('Y-m-d', strtotime('+200 days'));

    Safety::createVisit(['userId' => $ids['member'], 'kind' => 'Visite périodique',
        'doneOn' => gmdate('Y-m-d'), 'nextDue' => $soon]);
    Safety::createVisit(['userId' => $ids['member'], 'kind' => 'Visite périodique', 'nextDue' => $later]);

    $item = Safety::createPpe('Casque', 'Protection', 1);
    Safety::issuePpe($item, $ids['member'], gmdate('Y-m-d', strtotime('-15 days')));
    Safety::createRisk(['unit' => 'Atelier', 'hazard' => 'Bruit', 'severity' => 3,
        'likelihood' => 2, 'nextReview' => $soon]);
    Safety::createRisk(['unit' => 'Bureau', 'hazard' => 'Écran', 'severity' => 1,
        'likelihood' => 1, 'nextReview' => $later]);

    $upcoming = Safety::upcoming();
    assertSame(1, count($upcoming['visits']));
    assertSame(1, count($upcoming['ppe']));
    assertSame(1, count($upcoming['risks']));

    // Le compte d'un membre désactivé ne réclame plus de visite.
    Db::run('UPDATE users SET active = 0 WHERE id = ?', [$ids['member']]);
    assertSame(0, count(Safety::upcoming()['visits']));
});

Tests::run("l'écran santé-sécurité passe par les routes", function (): void {
    $ids = seedQuality();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);

    // Une cotation hors barème est refusée.
    visit('POST', '/sante-securite/risques', ['unit' => 'Atelier', 'hazard' => 'Chute',
        'severity' => '7', 'likelihood' => '2']);
    assertSame(0, count(Safety::risks()));

    visit('POST', '/sante-securite/risques', ['unit' => 'Atelier', 'hazard' => 'Chute de hauteur',
        'severity' => '4', 'likelihood' => '2', 'measures' => 'Garde-corps',
        'reviewed_on' => gmdate('Y-m-d'), 'next_review' => gmdate('Y-m-d', strtotime('+1 year'))]);
    assertSame(1, count(Safety::risks()));

    visit('POST', '/sante-securite/protections', ['name' => 'Harnais', 'validity_months' => '999']);
    assertSame(0, count(Safety::ppeItems()), 'une validité aberrante entre quand même');
    visit('POST', '/sante-securite/protections', ['name' => 'Harnais', 'validity_months' => '60']);
    assertSame(1, count(Safety::ppeItems()));

    // Une visite sur un membre inconnu n'est pas consignée.
    visit('POST', '/sante-securite/visites', ['user_id' => '9999', 'kind' => 'Visite périodique']);
    assertSame(0, count(Safety::visits()));
    visit('POST', '/sante-securite/visites', ['user_id' => (string) $ids['member'],
        'kind' => 'Visite de reprise', 'done_on' => gmdate('Y-m-d'), 'verdict' => 'Apte']);
    assertSame(1, count(Safety::visits()));

    $page = visit('GET', '/sante-securite');
    assertSame(200, $page->status);
    assertContains('Chute de hauteur', $page->body);
    assertContains('Harnais', $page->body);
});
