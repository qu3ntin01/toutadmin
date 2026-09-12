<?php

declare(strict_types=1);

use App\Core\Csv;
use App\Core\Db;
use App\Core\Settings;
use App\Modules\Catalogue;
use App\Modules\EInvoicing;
use App\Modules\Finance;
use App\Modules\Importer;
use App\Modules\Org;
use App\Modules\Users;

function loginAdminImport(): void
{
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
}

// ---------- Lecture d'un CSV ----------

Tests::run('le CSV se lit quel que soit le tableur qui l\'a écrit', function (): void {
    // Point-virgule, guillemets, séparateur à l'intérieur d'un champ, BOM d'Excel.
    $content = "\xEF\xBB\xBFPrénom;Nom;Adresse\nAlice;Martin;\"12, rue des Lilas\"\n";
    $parsed = Csv::parse($content);

    assertSame(true, $parsed['ok']);
    assertSame(';', $parsed['delimiter']);
    // Les en-têtes sont normalisés : « Prénom » et « prenom » sont la même colonne.
    assertSame(['prenom', 'nom', 'adresse'], $parsed['headers']);
    assertSame('12, rue des Lilas', $parsed['rows'][0]['adresse']);
    // Le numéro de ligne d'origine suit, pour dire laquelle corriger.
    assertSame(2, $parsed['rows'][0]['__line']);

    // La virgule est reconnue aussi, et le guillemet doublé s'échappe.
    $comma = Csv::parse("nom,note\n\"Société \"\"Alpha\"\"\",ok\n");
    assertSame(',', $comma['delimiter']);
    assertSame('Société "Alpha"', $comma['rows'][0]['nom']);

    // Deux colonnes de même nom, une colonne sans nom, un fichier vide : refusés.
    assertSame(false, Csv::parse("nom;nom\na;b\n")['ok']);
    assertSame(false, Csv::parse("nom;;autre\na;b;c\n")['ok']);
    assertSame(false, Csv::parse('   ')['ok']);
});

// ---------- Import ----------

Tests::run("l'import contrôle tout avant d'écrire quoi que ce soit", function (): void {
    seed();
    Org::createDepartment('Production');
    loginAdminImport();

    $content = implode("\n", [
        'prenom;nom;email;grade;type_contrat;service',
        'Alice;Martin;alice@entreprise.com;Employé;CDI;Production',
        'Bruno;Sanchez;pas-une-adresse;Employé;CDI;',
        'Carole;Dupont;carole@entreprise.com;Amirale;CDI;',
        'David;Leroy;david@entreprise.com;Employé;CDI;Service inconnu',
        'Emma;Petit;alice@entreprise.com;Employé;CDI;',
    ]);

    $preview = Importer::preview('membres', $content);
    assertSame(true, $preview['ok']);
    assertSame(5, count($preview['rows']));
    assertSame(1, $preview['valid']);
    assertSame(4, count($preview['errors']));

    // Chaque refus nomme son problème, avec le numéro de ligne du tableur.
    assertSame(3, $preview['rows'][1]['line']);
    assertContains('email invalide', $preview['rows'][1]['message']);
    assertContains('Grade inconnu', $preview['rows'][2]['message']);
    assertContains('Service introuvable', $preview['rows'][3]['message']);
    // Le fichier est vérifié contre lui-même : deux lignes, une même adresse.
    assertContains('existe déjà', $preview['rows'][4]['message']);

    // Tout ou rien : une seule ligne fautive et rien n'est importé.
    $refused = Importer::commit('membres', $content);
    assertSame(false, $refused['ok']);
    assertContains("rien n'a été importé", $refused['message']);
    assertSame(2, (int) Db::value('SELECT COUNT(*) FROM users'), 'des comptes ont été créés malgré les erreurs');
});

Tests::run('un import réussi crée les comptes et rend leurs mots de passe une fois', function (): void {
    seed();
    $department = Org::createDepartment('Production');
    $team = Org::createTeam('Ligne 2', $department);
    loginAdminImport();

    $content = implode("\n", [
        'prenom;nom;email;grade;type_contrat;service;equipe;fin_contrat;tjm',
        'Alice;Martin;alice@entreprise.com;Employé;CDI;Production;Ligne 2;;',
        'Bruno;Sanchez;bruno@entreprise.com;Employé;Freelance;;;2026-12-31;450',
    ]);

    $result = Importer::commit('membres', $content);
    assertSame(true, $result['ok']);
    assertSame(2, $result['imported']);
    assertSame(2, count($result['credentials']));
    assertSame('alice@entreprise.com', $result['credentials'][0]['email']);
    assertTrue(strlen($result['credentials'][0]['password']) >= 10);

    $alice = Db::get("SELECT * FROM users WHERE email = 'alice@entreprise.com'");
    assertSame('employee', $alice['role']);
    assertSame(1, (int) $alice['must_change_password']);
    assertSame($department, (int) $alice['department_id']);
    assertSame($team, (int) $alice['team_id']);
    // Le mot de passe n'est nulle part en clair, pas même au journal.
    assertTrue(!str_contains($alice['password_hash'], $result['credentials'][0]['password']));
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM audit_log WHERE detail LIKE ?', ['%' . $result['credentials'][0]['password'] . '%']));

    // Un freelance n'ouvre pas de compteur de congés, et garde son taux.
    $bruno = Db::get("SELECT * FROM users WHERE email = 'bruno@entreprise.com'");
    assertSame(0.0, (float) $bruno['leave_balance']);
    assertSame(450.0, (float) $bruno['daily_rate']);
    assertSame('2026-12-31', $bruno['contract_end_date']);
});

Tests::run("l'import n'écrase jamais une ligne existante", function (): void {
    seed();
    Db::insert("INSERT INTO partners (kind, name) VALUES ('Client', 'Aciers du Nord')");
    loginAdminImport();

    $content = "nom;type\nAciers du Nord;Fournisseur\n";
    $preview = Importer::preview('tiers', $content);
    assertContains('existe déjà', $preview['rows'][0]['message']);
    // Le tiers garde son type : rien n'est mis à jour.
    assertSame('Client', Db::get('SELECT * FROM partners')['kind']);
});

Tests::run("chaque type d'import suit le droit qu'il faudrait pour saisir à la main", function (): void {
    $ids = seed();

    // Les membres du personnel : l'administration seule.
    $keys = array_column(Importer::availableFor((array) Users::byId($ids['admin'])), 'key');
    assertTrue(in_array('membres', $keys, true));

    Users::setRoleFlag($ids['member'], 'is_finance', true);
    $financeKeys = array_column(Importer::availableFor((array) Users::byId($ids['member'])), 'key');
    assertTrue(!in_array('membres', $financeKeys, true), 'la gestion importe des comptes');
    assertTrue(in_array('tiers', $financeKeys, true));
    // Les modules éteints n'offrent pas leur import.
    assertTrue(!in_array('articles', $financeKeys, true));

    Catalogue::setEnabled('stock', true);
    assertTrue(in_array('articles', array_column(Importer::availableFor((array) Users::byId($ids['member'])), 'key'), true));

    // Un salarié sans droit n'entre pas du tout.
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    Users::setRoleFlag($ids['member'], 'is_finance', false);
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', '/import')->status);
});

Tests::run("l'écran d'import passe de l'aperçu à l'écriture", function (): void {
    seed();
    loginAdminImport();

    $content = "prenom;nom;email;grade;type_contrat\nAlice;Martin;alice@entreprise.com;Employé;CDI";
    $preview = visit('POST', '/import/apercu', ['entity' => 'membres', 'content' => $content]);
    assertSame(200, $preview->status);
    assertContains('alice@entreprise.com', $preview->body);
    assertSame(2, (int) Db::value('SELECT COUNT(*) FROM users'), "l'aperçu a écrit");

    $done = visit('POST', '/import/importer', ['entity' => 'membres', 'content' => $content]);
    assertSame(200, $done->status);
    assertSame(3, (int) Db::value('SELECT COUNT(*) FROM users'));
    assertSame(1, (int) Db::value("SELECT COUNT(*) FROM audit_log WHERE action = 'import.realise'"));

    // Le modèle se télécharge, en-têtes dans l'ordre et marque d'ordre des octets.
    $template = visit('GET', '/import/membres/modele.csv');
    assertSame(200, $template->status);
    assertContains('prenom;nom;email;grade;type_contrat', $template->body);
    assertSame("\xEF\xBB\xBF", substr($template->body, 0, 3));

    // Un type inconnu ne donne pas de modèle.
    assertSame(404, visit('GET', '/import/pirate/modele.csv')->status);
});

// ---------- Facturation électronique ----------

function seedEInvoicing(): array
{
    $ids = seed();
    Catalogue::setEnabled('facturation-electronique', true);
    $ids['client'] = Db::insert(
        "INSERT INTO partners (kind, name, registration, address)
         VALUES ('Client', 'Vertane Industries', '552100554', '2 rue des Lilas, 59000 Lille')"
    );
    return $ids;
}

Tests::run("une facture non conforme dit ce qui lui manque, une par une", function (): void {
    $ids = seedEInvoicing();
    $invoiceId = Finance::createInvoice([
        'direction' => 'Client', 'partnerId' => $ids['client'], 'label' => 'Prestation',
        'issueDate' => '2026-03-01', 'amountHt' => 1000.0, 'vatRate' => 20.0,
    ]);
    $invoice = (array) Finance::invoiceById((int) $invoiceId);

    $check = EInvoicing::check($invoice);
    assertSame(false, $check['ok']);
    $gaps = implode(' ', $check['gaps']);
    // L'identité de l'émetteur manque en entier, et la facture n'a ni numéro ni échéance.
    assertContains('Émetteur — Raison sociale', $gaps);
    assertContains('Numéro de facture', $gaps);
    assertContains("Date d'échéance", $gaps);
});

Tests::run("l'identité de l'émetteur est contrôlée dans sa forme", function (): void {
    seedEInvoicing();
    loginAdminImport();

    $refused = EInvoicing::saveIssuer([
        'company_legal_name' => 'Vertane', 'company_siren' => '12', 'company_vat' => 'pas-un-numero',
        'company_country' => 'France',
    ]);
    assertSame(false, $refused['ok']);
    assertSame(3, count($refused['errors']));
    assertSame('', (string) Settings::get('company_legal_name'), 'rien n\'est enregistré quand une valeur est refusée');

    assertSame(true, EInvoicing::saveIssuer([
        'company_legal_name' => 'Vertane Industries SAS', 'company_siren' => '552100554',
        'company_vat' => 'FR44552100554', 'company_address' => '2 rue des Lilas',
        'company_postal_code' => '59000', 'company_city' => 'Lille', 'company_country' => 'FR',
    ])['ok']);
    assertSame([], EInvoicing::issuerGaps());
});

Tests::run('le XML CII ne sort que d\'une facture conforme', function (): void {
    $ids = seedEInvoicing();
    EInvoicing::saveIssuer([
        'company_legal_name' => 'Vertane Industries SAS', 'company_siren' => '552100554',
        'company_vat' => 'FR44552100554', 'company_address' => '2 rue des Lilas',
        'company_postal_code' => '59000', 'company_city' => 'Lille', 'company_country' => 'FR',
    ]);

    $incomplete = (int) Finance::createInvoice([
        'direction' => 'Client', 'partnerId' => $ids['client'], 'label' => 'Sans numéro',
        'issueDate' => '2026-03-01', 'dueDate' => '2026-03-31', 'amountHt' => 100.0, 'vatRate' => 20.0,
    ]);
    $complete = (int) Finance::createInvoice([
        'direction' => 'Client', 'partnerId' => $ids['client'], 'reference' => 'F2026-0001',
        'label' => 'Prestation de conseil', 'issueDate' => '2026-03-01', 'dueDate' => '2026-03-31',
        'amountHt' => 1000.0, 'vatRate' => 20.0,
    ]);

    loginAdminImport();
    // Sans numéro, la facture n'est pas émise : l'écran renvoie à la liste.
    assertSame(302, visit('GET', '/facturation-electronique/factures/' . $incomplete . '.xml')->status);

    $response = visit('GET', '/facturation-electronique/factures/' . $complete . '.xml');
    assertSame(200, $response->status);
    assertContains('urn:cen.eu:en16931:2017', $response->body);
    assertContains('<ram:ID>F2026-0001</ram:ID>', $response->body);
    assertContains('<udt:DateTimeString format="102">20260301</udt:DateTimeString>', $response->body);
    assertContains('<ram:GrandTotalAmount>1200.00</ram:GrandTotalAmount>', $response->body);
    assertContains('<ram:ID schemeID="VA">FR44552100554</ram:ID>', $response->body);
    assertSame('facture-F2026-0001.xml', EInvoicing::fileName((array) Finance::invoiceById($complete)));

    // Une facture fournisseur n'est pas émise : elle est reçue.
    $supplier = (int) Finance::createInvoice([
        'direction' => 'Fournisseur', 'partnerId' => $ids['client'], 'reference' => 'A-1',
        'label' => 'Achat', 'issueDate' => '2026-03-01', 'dueDate' => '2026-03-31',
        'amountHt' => 50.0, 'vatRate' => 20.0,
    ]);
    assertSame(404, visit('GET', '/facturation-electronique/factures/' . $supplier . '.xml')->status);
});

Tests::run("le XML échappe ce qui casserait le document", function (): void {
    $ids = seedEInvoicing();
    EInvoicing::saveIssuer([
        'company_legal_name' => 'Vertane & Fils <SAS>', 'company_siren' => '552100554',
        'company_vat' => 'FR44552100554', 'company_address' => '2 rue des Lilas',
        'company_postal_code' => '59000', 'company_city' => 'Lille', 'company_country' => 'FR',
    ]);
    $id = (int) Finance::createInvoice([
        'direction' => 'Client', 'partnerId' => $ids['client'], 'reference' => 'F<2026>',
        'label' => 'Conseil & audit', 'issueDate' => '2026-03-01', 'dueDate' => '2026-03-31',
        'amountHt' => 100.0, 'vatRate' => 20.0,
    ]);

    $xml = EInvoicing::toXml((array) Finance::invoiceById($id));
    assertContains('Vertane &amp; Fils &lt;SAS&gt;', $xml);
    assertContains('<ram:ID>F&lt;2026&gt;</ram:ID>', $xml);
    // Et le document reste lisible par un analyseur XML.
    assertTrue(simplexml_load_string($xml) !== false, 'le XML produit est mal formé');
});
