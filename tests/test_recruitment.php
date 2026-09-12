<?php

declare(strict_types=1);

use App\Modules\Ats;
use App\Core\Db;
use App\Modules\Cv;
use App\Modules\Talent;

/** Un dépôt de CV, tel que le formulaire l'enverrait. */
function cvFile(string $name, string $mime, string $bytes): array
{
    return ['cv' => ['bytes' => $bytes, 'mime' => $mime, 'name' => $name]];
}

function loginAdmin(): void
{
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
}

Tests::run('le moteur ATS normalise accents, casse et ponctuation', function (): void {
    assertSame('developpeur back end c++', Ats::normalize('Développeur Back-End / C++'));
    assertSame('electricite', Ats::normalize('  ÉLECTRICITÉ  '));
});

Tests::run('le moteur ATS respecte les frontières de mots', function (): void {
    $cvText = Ats::normalize('Développeur Javascript, Node et React');
    // « java » ne doit pas être trouvé dans « javascript ».
    assertSame(false, Ats::contains($cvText, 'java'));
    assertSame(true, Ats::contains($cvText, 'javascript'));
    assertSame(true, Ats::contains(Ats::normalize('Java 17 et Spring'), 'java'));
});

Tests::run("les années d'expérience annoncées sont détectées", function (): void {
    assertSame(7, Ats::detectExperience("Technicienne — 7 ans d'expérience"));
    assertSame(12, Ats::detectExperience('Expérience : 12 ans en industrie'));
    assertSame(null, Ats::detectExperience('Profil junior sans chiffre'));
});

Tests::run('le score est pondéré par le poids des critères', function (): void {
    $opening = ['min_experience' => 0, 'ats_threshold' => 60];
    $criteria = [
        ['id' => 1, 'label' => 'Automatisme', 'keywords' => 'API, automate', 'kind' => 'Souhaité', 'weight' => 3],
        ['id' => 2, 'label' => 'Hydraulique', 'keywords' => '', 'kind' => 'Souhaité', 'weight' => 1],
    ];

    // Seul le critère de poids 3 est présent : 3/4 = 75 %.
    $result = Ats::evaluate(['cv_text' => 'Maintenance et automate Siemens'], $opening, $criteria);
    assertSame(75, $result['score']);
    foreach ($result['rows'] as $row) {
        assertSame($row['label'] === 'Automatisme', $row['matched']);
    }
    assertSame(true, $result['shortlisted']);
});

Tests::run('un critère requis manquant écarte, quel que soit le score', function (): void {
    $opening = ['min_experience' => 0, 'ats_threshold' => 50];
    $criteria = [
        ['id' => 1, 'label' => 'Soudure', 'keywords' => '', 'kind' => 'Souhaité', 'weight' => 5],
        ['id' => 2, 'label' => 'Permis cariste', 'keywords' => 'CACES', 'kind' => 'Requis', 'weight' => 1],
    ];

    $result = Ats::evaluate(['cv_text' => 'Expert en soudure TIG et MIG'], $opening, $criteria);
    assertTrue($result['score'] >= 50);
    assertSame(['Permis cariste'], $result['missingRequired']);
    assertSame(false, $result['shortlisted']);
});

Tests::run('une expérience sous le minimum écarte aussi', function (): void {
    $opening = ['min_experience' => 5, 'ats_threshold' => 0];
    $criteria = [['id' => 1, 'label' => 'Maintenance', 'keywords' => '', 'kind' => 'Souhaité', 'weight' => 1]];

    $short = Ats::evaluate(['cv_text' => 'Maintenance industrielle', 'experience_years' => 2], $opening, $criteria);
    assertSame(true, $short['experienceShort']);
    assertSame(false, $short['shortlisted']);

    $enough = Ats::evaluate(['cv_text' => 'Maintenance industrielle', 'experience_years' => 8], $opening, $criteria);
    assertSame(false, $enough['experienceShort']);
    assertSame(true, $enough['shortlisted']);
});

Tests::run("sans CV, rien n'est évalué", function (): void {
    $opening = ['min_experience' => 0, 'ats_threshold' => 0];
    $criteria = [['id' => 1, 'label' => 'X', 'keywords' => '', 'kind' => 'Souhaité', 'weight' => 1]];
    $result = Ats::evaluate(['cv_text' => ''], $opening, $criteria);

    assertSame(false, $result['hasCv']);
    assertSame(0, $result['score']);
    // Un dossier sans CV n'est jamais retenu automatiquement.
    assertSame(false, $result['shortlisted']);
});

Tests::run("l'expérience saisie prime sur celle devinée dans le CV", function (): void {
    $opening = ['min_experience' => 0, 'ats_threshold' => 0];

    $guessed = Ats::evaluate(['cv_text' => "10 ans d'expérience"], $opening, []);
    assertSame(10, $guessed['years']);
    assertSame('détectée dans le CV', $guessed['yearsSource']);

    $declared = Ats::evaluate(['cv_text' => "10 ans d'expérience", 'experience_years' => 3], $opening, []);
    assertSame(3, $declared['years']);
    assertSame('déclarée', $declared['yearsSource']);
});

Tests::run('un fichier texte est lu tel quel', function (): void {
    assertSame("Automatisme et\nhydraulique", Cv::extractText("Automatisme   et\r\nhydraulique", 'text/plain'));
});

Tests::run("le texte d'un .docx est extrait", function (): void {
    // Un .docx minimal : une archive ZIP contenant word/document.xml non compressé.
    $xml = '<w:document><w:body><w:p><w:r><w:t>Habilitation électrique B1V</w:t></w:r></w:p>'
        . '<w:p><w:r><w:t>Automatisme &amp; API</w:t></w:r></w:p></w:body></w:document>';
    $name = 'word/document.xml';
    $header = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, 0, strlen($xml), strlen($xml), strlen($name), 0);

    $text = Cv::extractDocx($header . $name . $xml);
    assertContains('Habilitation électrique B1V', $text);
    // L'entité XML est rendue, et le paragraphe devient un saut de ligne.
    assertContains('Automatisme & API', $text);
    assertContains("\n", $text);
});

Tests::run("le texte d'un PDF est extrait sans dépendance", function (): void {
    $stream = "BT /F1 12 Tf 72 720 Td (Automate Siemens et habilitation B1V) Tj T* (7 ans d'experience) Tj ET";
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream\nendobj\n%%EOF";

    $text = Cv::extractText($pdf, 'application/pdf');
    assertContains('Automate Siemens', $text);
    assertContains("7 ans d'experience", $text);
});

Tests::run('le seuil et l\'expérience minimale se règlent, et se bornent', function (): void {
    seed();
    loginAdmin();
    visit('POST', '/rh/postes', ['title' => 'Technicien de maintenance', 'contract_type' => 'CDI']);
    $opening = Db::get('SELECT * FROM job_openings');

    visit('POST', '/rh/postes/' . $opening['id'] . '/ats', ['ats_threshold' => '70', 'min_experience' => '5']);
    $updated = Db::get('SELECT * FROM job_openings WHERE id = ?', [(int) $opening['id']]);
    assertSame(70, (int) $updated['ats_threshold']);
    assertSame(5, (int) $updated['min_experience']);

    // Hors bornes : rien ne bouge.
    visit('POST', '/rh/postes/' . $opening['id'] . '/ats', ['ats_threshold' => '150', 'min_experience' => '5']);
    assertSame(70, (int) Db::get('SELECT * FROM job_openings WHERE id = ?', [(int) $opening['id']])['ats_threshold']);
});

Tests::run('un poids hors bornes est refusé', function (): void {
    seed();
    loginAdmin();
    visit('POST', '/rh/postes', ['title' => 'Technicien', 'contract_type' => 'CDI']);
    $opening = (int) Db::get('SELECT id FROM job_openings')['id'];

    visit('POST', '/rh/postes/' . $opening . '/criteres', ['label' => 'Trop lourd', 'keywords' => '', 'kind' => 'Souhaité', 'weight' => '99']);
    assertSame(0, count(Ats::criteriaOf($opening)));

    visit('POST', '/rh/postes/' . $opening . '/criteres', ['label' => 'Soudure', 'keywords' => 'TIG', 'kind' => 'Souhaité', 'weight' => '2']);
    assertSame(1, count(Ats::criteriaOf($opening)));
});

Tests::run('un CV déposé est extrait, scoré, classé, puis retiré', function (): void {
    seed();
    loginAdmin();
    visit('POST', '/rh/postes', ['title' => 'Technicien de maintenance', 'contract_type' => 'CDI']);
    $opening = (int) Db::get('SELECT id FROM job_openings')['id'];

    foreach ([
        ['Automatisme', 'API, automate, Siemens', 'Souhaité', '3'],
        ['Habilitation électrique', 'B1V, habilitation', 'Requis', '2'],
        ['Soudure', 'TIG, MIG', 'Souhaité', '1'],
    ] as [$label, $keywords, $kind, $weight]) {
        visit('POST', '/rh/postes/' . $opening . '/criteres', compact('label', 'keywords', 'kind', 'weight'));
    }
    assertSame(3, count(Ats::criteriaOf($opening)));

    // Une candidature sans CV n'est pas évaluée.
    visit('POST', '/rh/candidats', [
        'opening_id' => (string) $opening, 'first_name' => 'Nadia', 'last_name' => 'Berger', 'email' => 'nadia@test.local',
    ]);
    $nadia = (int) Db::get('SELECT id FROM candidates')['id'];
    $ranked = Ats::rankedCandidates($opening);
    assertSame(false, $ranked[0]['ats']['hasCv']);
    assertSame(false, $ranked[0]['ats']['shortlisted']);

    $response = visit('POST', '/rh/candidats/' . $nadia . '/cv', [], [], cvFile(
        'nadia.txt',
        'text/plain',
        "Technicienne de maintenance — 7 ans d'expérience. Automate Siemens, habilitation B1V, hydraulique."
    ));
    assertSame(302, $response->status);

    $candidate = Db::get('SELECT * FROM candidates WHERE id = ?', [$nadia]);
    assertContains('Automate Siemens', $candidate['cv_text']);
    assertSame('nadia.txt', $candidate['cv_name']);
    // Le fichier est conservé sous un nom aléatoire.
    assertTrue($candidate['cv_file'] !== null && $candidate['cv_file'] !== 'nadia.txt');
    // Automatisme (3) + habilitation (2) = 5/6, soit 83 %.
    assertSame(83, (int) $candidate['ats_score']);

    $best = Ats::rankedCandidates($opening)[0];
    assertSame(true, $best['ats']['shortlisted']);
    assertSame(7, $best['ats']['years']);

    $download = visit('GET', '/rh/candidats/' . $nadia . '/cv');
    assertSame(200, $download->status);
    assertContains('habilitation B1V', $download->body);

    // Un requis absent écarte malgré un bon score.
    visit('POST', '/rh/candidats', ['opening_id' => (string) $opening, 'first_name' => 'Théo', 'last_name' => 'Rimbaud']);
    $theo = (int) Db::get("SELECT id FROM candidates WHERE last_name = 'Rimbaud'")['id'];
    visit('POST', '/rh/candidats/' . $theo . '/cv', [], [], cvFile(
        'theo.txt',
        'text/plain',
        "Automate Siemens, soudure TIG. 9 ans d'expérience. Aucune certification réglementaire."
    ));

    $rows = Ats::rankedCandidates($opening);
    $second = null;
    foreach ($rows as $row) {
        if ((int) $row['id'] === $theo) {
            $second = $row;
        }
    }
    // Automatisme (3) + soudure (1) = 4/6, soit 67 %.
    assertSame(67, $second['ats']['score']);
    assertSame(['Habilitation électrique'], $second['ats']['missingRequired']);
    assertSame(false, $second['ats']['shortlisted']);

    // Le classement va du meilleur score au moins bon.
    assertSame('Berger', $rows[0]['last_name']);
    assertTrue($rows[0]['ats']['score'] >= $rows[1]['ats']['score']);

    // Retirer un critère réévalue tout le poste, et la base suit.
    $criterion = null;
    foreach (Ats::criteriaOf($opening) as $row) {
        if ($row['label'] === 'Habilitation électrique') {
            $criterion = $row;
        }
    }
    visit('POST', '/rh/criteres/' . $criterion['id'] . '/supprimer');
    $theoAgain = null;
    foreach (Ats::rankedCandidates($opening) as $row) {
        if ((int) $row['id'] === $theo) {
            $theoAgain = $row;
        }
    }
    // Sans le critère requis : automatisme (3) + soudure (1) = 4/4, soit 100 %.
    assertSame(100, $theoAgain['ats']['score']);
    assertSame([], $theoAgain['ats']['missingRequired']);
    assertSame(true, $theoAgain['ats']['shortlisted']);
    assertSame(100, (int) Db::get('SELECT ats_score FROM candidates WHERE id = ?', [$theo])['ats_score']);

    // La CVthèque retrouve un profil par mots-clés.
    $found = Ats::searchCvs('hydraulique');
    assertSame(1, count($found));
    assertSame('Berger', $found[0]['last_name']);
    $both = Ats::searchCvs('automate soudure');
    assertSame('Rimbaud', $both[0]['last_name']);
    assertSame(true, $both[0]['matchedAll']);
    assertSame(0, count(Ats::searchCvs('plomberie')));

    // Supprimer un CV efface le fichier et le texte.
    $path = Cv::pathOf(Db::get('SELECT cv_file FROM candidates WHERE id = ?', [$nadia])['cv_file']);
    assertTrue(is_file($path));
    visit('POST', '/rh/candidats/' . $nadia . '/cv/supprimer');
    $after = Db::get('SELECT * FROM candidates WHERE id = ?', [$nadia]);
    assertSame(null, $after['cv_file']);
    assertSame('', $after['cv_text']);
    assertSame(false, is_file($path));
    assertSame(0, count(Ats::searchCvs('hydraulique')));
});

Tests::run('un format non pris en charge, ou menti, est refusé', function (): void {
    seed();
    loginAdmin();
    visit('POST', '/rh/postes', ['title' => 'Technicien', 'contract_type' => 'CDI']);
    $opening = (int) Db::get('SELECT id FROM job_openings')['id'];
    visit('POST', '/rh/candidats', ['opening_id' => (string) $opening, 'first_name' => 'Nadia', 'last_name' => 'Berger']);
    $nadia = (int) Db::get('SELECT id FROM candidates')['id'];
    $before = count(glob(Cv::directory() . '/*') ?: []);

    visit('POST', '/rh/candidats/' . $nadia . '/cv', [], [], cvFile('malveillant.exe', 'application/x-msdownload', 'MZ binaire'));
    assertSame(null, Db::get('SELECT cv_file FROM candidates WHERE id = ?', [$nadia])['cv_file']);

    // Un exécutable rebaptisé PDF ne passe pas davantage : le contenu est contrôlé.
    visit('POST', '/rh/candidats/' . $nadia . '/cv', [], [], cvFile('cv.pdf', 'application/pdf', 'MZ binaire'));
    assertSame(null, Db::get('SELECT cv_file FROM candidates WHERE id = ?', [$nadia])['cv_file']);
    assertSame($before, count(glob(Cv::directory() . '/*') ?: []));
});

Tests::run("le personnel n'accède ni aux CV ni aux critères", function (): void {
    seed();
    loginAdmin();
    visit('POST', '/rh/postes', ['title' => 'Technicien', 'contract_type' => 'CDI']);
    $opening = (int) Db::get('SELECT id FROM job_openings')['id'];
    visit('POST', '/rh/candidats', ['opening_id' => (string) $opening, 'first_name' => 'Nadia', 'last_name' => 'Berger']);
    $nadia = (int) Db::get('SELECT id FROM candidates')['id'];

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', '/rh/candidats/' . $nadia . '/cv')->status);
    assertSame(403, visit('POST', '/rh/postes/' . $opening . '/criteres', [
        'label' => 'Pirate', 'kind' => 'Requis', 'weight' => '1',
    ])->status);
    assertSame(0, count(Ats::criteriaOf($opening)));
});

Tests::run("un dépôt sans jeton CSRF n'écrit rien", function (): void {
    seed();
    loginAdmin();
    visit('POST', '/rh/postes', ['title' => 'Technicien', 'contract_type' => 'CDI']);
    $opening = (int) Db::get('SELECT id FROM job_openings')['id'];
    visit('POST', '/rh/candidats', ['opening_id' => (string) $opening, 'first_name' => 'Nadia', 'last_name' => 'Berger']);
    $nadia = (int) Db::get('SELECT id FROM candidates')['id'];
    $before = count(glob(Cv::directory() . '/*') ?: []);

    $refused = visit('POST', '/rh/candidats/' . $nadia . '/cv', ['_csrf' => 'faux'], [], cvFile('forge.txt', 'text/plain', 'contenu forge'));
    assertSame(403, $refused->status);
    assertSame(null, Db::get('SELECT cv_file FROM candidates WHERE id = ?', [$nadia])['cv_file']);
    // Aucun fichier orphelin n'a été déposé.
    assertSame($before, count(glob(Cv::directory() . '/*') ?: []));
});

Tests::run('un poste fermé garde ses candidatures, sa suppression les emporte', function (): void {
    seed();
    loginAdmin();
    visit('POST', '/rh/postes', ['title' => 'Technicien', 'contract_type' => 'CDI']);
    $opening = (int) Db::get('SELECT id FROM job_openings')['id'];
    visit('POST', '/rh/candidats', ['opening_id' => (string) $opening, 'first_name' => 'Nadia', 'last_name' => 'Berger']);

    visit('POST', '/rh/postes/' . $opening . '/statut', ['status' => 'Pourvu']);
    assertSame('Pourvu', Talent::openingById($opening)['status']);
    assertSame(1, count(Talent::candidates($opening)));

    visit('POST', '/rh/postes/' . $opening . '/supprimer');
    assertSame(null, Talent::openingById($opening));
    assertSame(0, (int) Db::get('SELECT COUNT(*) AS n FROM candidates')['n']);
});
