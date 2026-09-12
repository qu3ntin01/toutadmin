<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Catalogue;
use App\Modules\Payroll;
use App\Modules\Users;

/** Une instance où la paie est ouverte, avec un compte RH. */
function seedPayroll(): array
{
    $ids = seed();
    Catalogue::setEnabled('paie', true);
    Payroll::seedDefaults();
    $ids['hr'] = Users::create([
        'role' => 'employee', 'email' => 'rh@entreprise.com', 'password' => 'Rh-Demo-2026!',
        'first_name' => 'Inès', 'last_name' => 'Garnier',
    ]);
    Users::setRoleFlag($ids['hr'], 'is_hr', true);
    return $ids;
}

Tests::run('la paie est fermée à qui n\'est pas RH', function (): void {
    $ids = seedPayroll();
    Users::setRoleFlag($ids['member'], 'is_finance', true);

    // Même la gestion n'y entre pas : un salaire n'est pas une dépense comme une autre.
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', '/paie')->status);
    assertSame(403, visit('POST', '/paie/salaires/' . $ids['member'], ['gross_salary' => '9000'])->status);

    visit('POST', '/connexion', ['email' => 'rh@entreprise.com', 'password' => 'Rh-Demo-2026!']);
    assertSame(200, visit('GET', '/paie')->status);
});

Tests::run('activer la paie pose les barèmes, deux fois sans doublon', function (): void {
    seed();
    assertSame(0, count(Payroll::rates()));

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', '/admin/modules/paie', ['enabled' => 'on']);
    $posed = count(Payroll::rates());
    assertTrue($posed >= 8, 'les barèmes n\'ont pas été posés');

    visit('POST', '/admin/modules/paie', ['enabled' => '']);
    visit('POST', '/admin/modules/paie', ['enabled' => 'on']);
    assertSame($posed, count(Payroll::rates()), 'les barèmes ont été dupliqués');
});

Tests::run('le net retire les seules cotisations salariales', function (): void {
    seedPayroll();
    // Un barème à soi, pour que le calcul se vérifie à la main.
    foreach (Payroll::rates() as $rate) {
        Payroll::deleteRate((int) $rate['id']);
    }
    Payroll::createRate('Cotisation sur le brut', 'Brut', 10, 20);

    $result = Payroll::compute(2000);
    assertSame(200.0, $result['employeeTotal']);
    assertSame(400.0, $result['employerTotal']);
    assertSame(1800.0, $result['net'], 'le net a été amputé de la part patronale');
    assertSame(2400.0, $result['employerCost']);
});

Tests::run('une cotisation plafonnée ne mord que sur la part sous plafond', function (): void {
    seedPayroll();
    foreach (Payroll::rates() as $rate) {
        Payroll::deleteRate((int) $rate['id']);
    }
    Payroll::createRate('Plafonnée', 'Plafond', 10, 0);
    Payroll::setCeiling(3000);

    // Sous le plafond : la base est le brut entier.
    assertSame(200.0, Payroll::compute(2000)['employeeTotal']);
    // Au-dessus : la base s'arrête au plafond.
    $high = Payroll::compute(5000);
    assertSame(3000.0, $high['lines'][0]['baseAmount']);
    assertSame(300.0, $high['employeeTotal']);
});

Tests::run('une cotisation désactivée ne pèse plus sur le bulletin', function (): void {
    seedPayroll();
    foreach (Payroll::rates() as $rate) {
        Payroll::deleteRate((int) $rate['id']);
    }
    Payroll::createRate('Active', 'Brut', 10, 0);
    Payroll::createRate('À éteindre', 'Brut', 5, 0);
    assertSame(300.0, Payroll::compute(2000)['employeeTotal']);

    $rates = Payroll::rates();
    Payroll::toggleRate((int) $rates[1]['id']);
    assertSame(200.0, Payroll::compute(2000)['employeeTotal']);
});

Tests::run('un taux hors bornes est refusé', function (): void {
    seedPayroll();
    assertSame('bad-rate', Payroll::createRate('Absurde', 'Brut', 120, 0)['reason']);
    assertSame('bad-rate', Payroll::createRate('Négative', 'Brut', -1, 0)['reason']);
    assertSame('bad-base', Payroll::createRate('Base inconnue', 'Lune', 1, 1)['reason']);

    visit('POST', '/connexion', ['email' => 'rh@entreprise.com', 'password' => 'Rh-Demo-2026!']);
    visit('POST', '/paie/plafond', ['ceiling' => '-100']);
    assertSame(Payroll::DEFAULT_CEILING, Payroll::ceiling(), 'un plafond négatif a été accepté');
});

Tests::run('un bulletin ne se calcule pas deux fois sur la même période', function (): void {
    $ids = seedPayroll();
    visit('POST', '/connexion', ['email' => 'rh@entreprise.com', 'password' => 'Rh-Demo-2026!']);
    visit('POST', '/paie/bulletins', [
        'employee_id' => (string) $ids['member'], 'period' => '2026-03', 'gross_salary' => '2500',
    ]);
    assertSame(1, count(Payroll::detailedPayslips()));

    visit('POST', '/paie/bulletins', [
        'employee_id' => (string) $ids['member'], 'period' => '2026-03', 'gross_salary' => '9000',
    ]);
    assertSame(1, count(Payroll::detailedPayslips()), 'un second bulletin est passé sur la même période');
    assertSame(2500.0, (float) Payroll::detailedPayslips()[0]['gross_amount']);
});

Tests::run('un freelance est facturé, pas salarié', function (): void {
    $ids = seedPayroll();
    Db::run("UPDATE users SET contract_type = 'Freelance' WHERE id = ?", [$ids['member']]);
    $result = Payroll::generatePayslip([
        'employeeId' => $ids['member'], 'period' => '2026-03', 'grossSalary' => 3000,
    ]);
    assertTrue(!$result['ok']);
    assertSame('freelance', $result['reason']);
    // Et il ne figure plus dans le personnel de la paie.
    assertTrue(
        !in_array($ids['member'], array_map('intval', array_column(Payroll::staff(), 'id')), true),
        'un freelance figure encore dans le personnel de la paie'
    );
});

Tests::run('la génération en lot ne prend que les bruts renseignés', function (): void {
    $ids = seedPayroll();
    $sans = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    Payroll::setGrossSalary($ids['member'], 2400);

    visit('POST', '/connexion', ['email' => 'rh@entreprise.com', 'password' => 'Rh-Demo-2026!']);
    visit('POST', '/paie/bulletins/lot', ['period' => '2026-04']);

    $payslips = Payroll::detailedPayslips();
    assertSame(1, count($payslips), 'un salarié sans brut a reçu un bulletin');
    assertSame((int) $ids['member'], (int) $payslips[0]['employee_id']);

    // Relancer le lot ne recalcule pas ce qui existe.
    visit('POST', '/paie/bulletins/lot', ['period' => '2026-04']);
    assertSame(1, count(Payroll::detailedPayslips()));
});

Tests::run('le bulletin porte le détail de ses cotisations et alimente la masse salariale', function (): void {
    $ids = seedPayroll();
    foreach (Payroll::rates() as $rate) {
        Payroll::deleteRate((int) $rate['id']);
    }
    Payroll::createRate('Cotisation', 'Brut', 10, 20);

    $result = Payroll::generatePayslip([
        'employeeId' => $ids['member'], 'period' => '2026-05', 'grossSalary' => 2000, 'createdBy' => $ids['admin'],
    ]);
    assertTrue($result['ok']);
    assertSame(1, count(Payroll::payslipLines((int) $result['id'])));

    $cost = Payroll::payrollCost('2026-05');
    assertSame(2000.0, $cost['gross']);
    assertSame(1800.0, $cost['net']);
    assertSame(400.0, $cost['employer']);
    assertSame(2400.0, $cost['cost']);
    // Le salarié retrouve sa fiche dans son espace.
    assertSame(1, count(\App\Modules\Hr::payslipsFor($ids['member'], 12)));
});

Tests::run('l\'écran de paie affiche barèmes, salaires et bulletins', function (): void {
    $ids = seedPayroll();
    Payroll::setGrossSalary($ids['member'], 2600);
    Payroll::generatePayslip([
        'employeeId' => $ids['member'], 'period' => '2026-06', 'grossSalary' => 2600, 'createdBy' => $ids['admin'],
    ]);

    visit('POST', '/connexion', ['email' => 'rh@entreprise.com', 'password' => 'Rh-Demo-2026!']);
    $page = visit('GET', '/paie', [], ['periode' => '2026-06', 'brut' => '2600']);
    assertSame(200, $page->status);
    assertContains('Moreau', $page->body);
    assertContains('Assurance chômage', $page->body);
    assertContains('2026-06', $page->body);
});
