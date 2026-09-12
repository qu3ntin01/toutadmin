<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\People;
use App\Modules\Users;

/** Une instance avec les RH, qui tiennent les parcours. */
function seedJourneys(): array
{
    $ids = seed();
    Users::setRoleFlag($ids['member'], 'is_hr', true);
    return $ids;
}

function loginHrJourneys(): void
{
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
}

Tests::run('les parcours appartiennent aux ressources humaines', function (): void {
    $ids = seed();
    $other = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);

    visit('POST', '/connexion', ['email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!']);
    assertSame(403, visit('GET', '/parcours')->status);
    assertSame(403, visit('POST', '/parcours/modeles', ['name' => 'Pirate', 'kind' => 'Arrivée'])->status);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM checklist_templates'));

    Users::setRoleFlag($other, 'is_hr', true);
    visit('POST', '/connexion', ['email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!']);
    assertSame(200, visit('GET', '/parcours')->status);
});

Tests::run('les échéances se calculent à partir du jour pivot', function (): void {
    $ids = seedJourneys();
    $template = People::createTemplate("Arrivée d'un salarié", 'Arrivée');
    People::addTemplateItem($template, ['label' => 'Préparer le poste', 'ownerRole' => 'Informatique', 'offsetDays' => -2]);
    People::addTemplateItem($template, ['label' => 'Remettre le badge', 'ownerRole' => 'Moyens généraux', 'offsetDays' => 0]);
    People::addTemplateItem($template, ['label' => 'Point de fin de période', 'ownerRole' => 'Manager', 'offsetDays' => 30]);

    $id = People::startChecklist(['templateId' => $template, 'userId' => $ids['admin'], 'referenceDate' => '2026-09-07']);
    $items = People::checklistItems((int) $id);

    assertSame(3, count($items));
    assertSame(['2026-09-05', '2026-09-07', '2026-10-07'], array_column($items, 'due_date'));
    // L'ordre du modèle est conservé : la liste se lit comme elle a été écrite.
    assertSame('Préparer le poste', $items[0]['label']);
    assertSame('Arrivée', People::checklistById((int) $id)['kind']);
});

Tests::run('cocher le dernier point clôt la liste, en décocher un la rouvre', function (): void {
    $ids = seedJourneys();
    $template = People::createTemplate('Départ', 'Départ');
    People::addTemplateItem($template, ['label' => 'Couper les accès', 'ownerRole' => 'Informatique', 'offsetDays' => 0]);
    People::addTemplateItem($template, ['label' => 'Solde de tout compte', 'ownerRole' => 'RH', 'offsetDays' => 5]);
    $id = (int) People::startChecklist(['templateId' => $template, 'userId' => $ids['admin'], 'referenceDate' => '2026-09-07']);

    loginHrJourneys();
    $items = People::checklistItems($id);
    visit('POST', '/parcours/listes/points/' . $items[0]['id'] . '/basculer');
    assertSame(null, People::checklistById($id)['completed_at']);

    visit('POST', '/parcours/listes/points/' . $items[1]['id'] . '/basculer');
    assertTrue(People::checklistById($id)['completed_at'] !== null, 'la liste ne s\'est pas close');
    assertSame($ids['member'], (int) People::checklistItems($id)[1]['done_by']);

    visit('POST', '/parcours/listes/points/' . $items[1]['id'] . '/basculer');
    assertSame(null, People::checklistById($id)['completed_at'], 'la liste est restée close');
});

Tests::run('un modèle supprimé laisse les parcours déjà lancés', function (): void {
    $ids = seedJourneys();
    $template = People::createTemplate('Arrivée', 'Arrivée');
    People::addTemplateItem($template, ['label' => 'Badge', 'ownerRole' => 'RH', 'offsetDays' => 0]);
    $id = (int) People::startChecklist(['templateId' => $template, 'userId' => $ids['admin'], 'referenceDate' => '2026-09-07']);

    loginHrJourneys();
    visit('POST', '/parcours/modeles/' . $template . '/supprimer');
    assertSame(0, count(People::templates()));
    assertTrue(People::checklistById($id) !== null, 'le parcours a disparu avec son modèle');
    assertSame(1, count(People::checklistItems($id)));
});

Tests::run('un écart au jour pivot hors de l\'année est refusé', function (): void {
    seedJourneys();
    loginHrJourneys();
    visit('POST', '/parcours/modeles', ['name' => 'Arrivée', 'kind' => 'Arrivée']);
    $template = (int) Db::value('SELECT id FROM checklist_templates');

    visit('POST', '/parcours/modeles/' . $template . '/points', [
        'label' => 'Trop loin', 'owner_role' => 'RH', 'offset_days' => '900',
    ]);
    visit('POST', '/parcours/modeles/' . $template . '/points', [
        'label' => 'Responsable inventé', 'owner_role' => 'Pirate', 'offset_days' => '0',
    ]);
    assertSame(0, count(People::templateItems($template)));

    visit('POST', '/parcours/modeles/' . $template . '/points', [
        'label' => 'Badge', 'owner_role' => 'RH', 'offset_days' => '-2',
    ]);
    assertSame(1, count(People::templateItems($template)));
    // Un type de modèle inventé non plus.
    visit('POST', '/parcours/modeles', ['name' => 'Autre', 'kind' => 'Mutation']);
    assertSame(1, count(People::templates()));
});

Tests::run("l'échéance d'une habilitation découle de sa validité", function (): void {
    $ids = seedJourneys();
    $skill = People::createSkill(['name' => 'CACES 3', 'category' => 'Sécurité', 'validityMonths' => 60, 'mandatory' => true]);
    $free = People::createSkill(['name' => 'Anglais', 'category' => 'Langues', 'validityMonths' => null, 'mandatory' => false]);

    People::grantSkill(['userId' => $ids['admin'], 'skillId' => $skill, 'level' => 3, 'obtainedOn' => '2021-06-15']);
    People::grantSkill(['userId' => $ids['admin'], 'skillId' => $free, 'level' => 2, 'obtainedOn' => '2021-06-15']);

    $held = People::skillsOf($ids['admin']);
    $caces = $held[0]['name'] === 'CACES 3' ? $held[0] : $held[1];
    $english = $held[0]['name'] === 'CACES 3' ? $held[1] : $held[0];
    assertSame('2026-06-15', $caces['expires_on']);
    assertSame(null, $english['expires_on']);

    // Réattribuer la même compétence met à jour plutôt que de doubler la ligne.
    People::grantSkill(['userId' => $ids['admin'], 'skillId' => $skill, 'level' => 4, 'obtainedOn' => '2024-01-10']);
    assertSame(2, count(People::skillsOf($ids['admin'])));
    assertSame('2029-01-10', People::skillsOf($ids['admin'])[1]['expires_on']);
});

Tests::run('ce qui périme et ce qui manque remontent tout seuls', function (): void {
    $ids = seedJourneys();
    $mandatory = People::createSkill(['name' => 'Habilitation B1V', 'category' => 'Sécurité', 'validityMonths' => 36, 'mandatory' => true]);

    $marc = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    People::grantSkill(['userId' => $marc, 'skillId' => $mandatory, 'level' => 2, 'obtainedOn' => '2000-01-01']);

    // Marc l'a eue, mais elle est périmée : il manque à l'appel comme s'il ne l'avait pas.
    $missing = People::missingMandatory();
    assertSame(2, count($missing), 'Claire ne l\'a jamais eue, Marc ne l\'a plus');
    assertSame(1, count(People::expiringSkills()));

    // Renouvelée, elle sort des deux listes.
    People::grantSkill(['userId' => $marc, 'skillId' => $mandatory, 'level' => 2, 'obtainedOn' => gmdate('Y-m-d')]);
    assertSame(1, count(People::missingMandatory()));
    assertSame(0, count(People::expiringSkills()));
});

Tests::run('la matrice dit qui détient quoi', function (): void {
    $ids = seedJourneys();
    $skill = People::createSkill(['name' => 'Soudure TIG', 'category' => 'Atelier', 'validityMonths' => null, 'mandatory' => false]);
    People::grantSkill(['userId' => $ids['member'], 'skillId' => $skill, 'level' => 3, 'obtainedOn' => null]);

    $matrix = People::matrix();
    assertSame(1, count($matrix), 'seuls les salariés actifs figurent à la matrice');
    assertSame(3, (int) $matrix[0]['held'][$skill]['level']);

    // Retirer l'attribution vide la case, sans toucher à la compétence.
    People::revokeSkill($ids['member'], $skill);
    assertSame([], People::matrix()[0]['held']);
    assertSame(1, count(People::skills()));

    // Supprimer la compétence emporte les attributions.
    People::grantSkill(['userId' => $ids['member'], 'skillId' => $skill, 'level' => 1, 'obtainedOn' => null]);
    People::deleteSkill($skill);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM user_skills'));
});

Tests::run("l'écran des parcours s'affiche et lance un parcours", function (): void {
    $ids = seedJourneys();
    loginHrJourneys();

    visit('POST', '/parcours/modeles', ['name' => "Arrivée d'un salarié", 'kind' => 'Arrivée']);
    $template = (int) Db::value('SELECT id FROM checklist_templates');
    visit('POST', '/parcours/modeles/' . $template . '/points', [
        'label' => 'Préparer le poste', 'owner_role' => 'Informatique', 'offset_days' => '-2',
    ]);

    visit('POST', '/parcours/listes', [
        'template_id' => (string) $template, 'user_id' => (string) $ids['admin'], 'reference_date' => '2026-09-07',
    ]);
    $checklist = Db::get('SELECT * FROM checklists');
    assertTrue($checklist !== null);

    $page = visit('GET', '/parcours');
    assertSame(200, $page->status);
    // L'apostrophe est échappée à l'affichage : c'est ce que doit voir le test.
    assertContains('Arrivée d&#039;un salarié', $page->body);

    $sheet = visit('GET', '/parcours/listes/' . $checklist['id']);
    assertSame(200, $sheet->status);
    assertContains('Préparer le poste', $sheet->body);
    assertContains('2026-09-05', $sheet->body);

    // Une date pivot invalide ne lance rien.
    visit('POST', '/parcours/listes', [
        'template_id' => (string) $template, 'user_id' => (string) $ids['admin'], 'reference_date' => 'demain',
    ]);
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM checklists'));
});
