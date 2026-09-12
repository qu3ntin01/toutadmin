<?php

declare(strict_types=1);

use App\Core\Db;
use App\Core\Secrets;
use App\Core\Settings;
use App\Modules\Ai;
use App\Modules\Cv;
use App\Modules\Intake;
use App\Modules\InvoiceScan;
use App\Modules\Mailbox;

/**
 * Un PDF minimal mais réel, écrit à la main : la chaîne de lecture (signature
 * du fichier, extraction du texte, analyse) doit être éprouvée sur un vrai
 * PDF, pas sur un fichier texte déguisé.
 */
function makePdf(array $lines): string
{
    $content = implode("\n", array_map(
        static fn (string $line, int $index): string => 'BT /F1 12 Tf 50 ' . (760 - $index * 18)
            . ' Td (' . preg_replace('/[()\\\\]/', '', $line) . ') Tj ET',
        $lines,
        array_keys($lines)
    ));

    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }

    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT) . " 00000 n \n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";

    return $pdf;
}

/** Une facture d'essai, telle qu'un fournisseur l'enverrait. */
function invoicePdf(array $options = []): string
{
    $supplier = $options['supplier'] ?? 'ACIERS DU NORD SAS';
    $siret = $options['siret'] ?? '732 829 320 00074';
    $reference = $options['reference'] ?? 'F2026-0147';
    $ttc = $options['ttc'] ?? '1 140,00';

    return makePdf([
        $supplier,
        "SIRET $siret - TVA FR44732829320",
        "FACTURE N $reference",
        'Date de facture : 12/03/2026',
        "Date d'echeance : 11/04/2026",
        'Total HT ' . ($options['ht'] ?? '950,00') . ' EUR',
        'TVA 20 % ' . ($options['vat'] ?? '190,00') . ' EUR',
        "Net a payer $ttc EUR",
        'IBAN FR76 3000 6000 0112 3456 7890 189',
    ]);
}

/** Un courriel multipart portant une pièce jointe, au format brut. */
function mailWithAttachment(array $options = []): string
{
    $headers = [
        'From: ' . ($options['from'] ?? 'Aciers du Nord <compta@aciers.test>'),
        'To: factures@exemple.fr',
        'Subject: ' . ($options['subject'] ?? 'Facture F2026-0147'),
        'Date: ' . ($options['date'] ?? 'Thu, 12 Mar 2026 09:00:00 +0100'),
        'MIME-Version: 1.0',
    ];

    if (empty($options['attachment'])) {
        return implode("\r\n", array_merge($headers, [
            'Content-Type: text/plain; charset=utf-8', '', $options['body'] ?? 'Bonjour.', '',
        ]));
    }

    $boundary = 'frontiere-essai';
    $fileName = $options['fileName'] ?? 'facture.pdf';
    $contentType = $options['contentType'] ?? 'application/pdf';

    return implode("\r\n", array_merge($headers, [
        'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        '',
        "--$boundary",
        'Content-Type: text/plain; charset=utf-8',
        '',
        $options['body'] ?? 'Bonjour, veuillez trouver notre facture.',
        '',
        "--$boundary",
        "Content-Type: $contentType; name=\"$fileName\"",
        'Content-Transfer-Encoding: base64',
        "Content-Disposition: attachment; filename=\"$fileName\"",
        '',
        chunk_split(base64_encode($options['attachment']), 76, "\r\n"),
        "--$boundary--",
        '',
    ]));
}

function loginFinance(): array
{
    $ids = seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    return $ids;
}

// ---------- Lecture par les règles ----------

Tests::run('les nombres se lisent quelle que soit la convention', function (): void {
    assertSame(1234.56, InvoiceScan::parseNumber('1 234,56'));
    assertSame(1234.56, InvoiceScan::parseNumber('1.234,56'));
    assertSame(1234.56, InvoiceScan::parseNumber('1,234.56'));
    assertSame(1234.56, InvoiceScan::parseNumber('1 234,56 €'));
    // Trois décimales, c'est un séparateur de milliers.
    assertSame(1234.0, InvoiceScan::parseNumber('1.234'));
    assertSame(12.34, InvoiceScan::parseNumber('12.34'));
    assertSame(null, InvoiceScan::parseNumber('néant'));
});

Tests::run('les dates aussi', function (): void {
    assertSame('2026-01-05', InvoiceScan::parseDate('05/01/2026'));
    assertSame('2026-01-05', InvoiceScan::parseDate('5 janvier 2026'));
    assertSame('2026-01-05', InvoiceScan::parseDate('2026-01-05'));
    assertSame(null, InvoiceScan::parseDate('32/13/2026'));
});

Tests::run('les identifiants sont vérifiés par leur clé, pas seulement par leur forme', function (): void {
    assertSame(true, InvoiceScan::luhnValid('73282932000074'));
    assertSame(false, InvoiceScan::luhnValid('73282932000075'));
    assertSame(true, InvoiceScan::ibanValid('FR7630006000011234567890189'));
    assertSame(false, InvoiceScan::ibanValid('FR7630006000011234567890188'));

    assertSame(['73282932000074'], InvoiceScan::findSirets('SIRET 732 829 320 00074 sur la facture'));
    // Un numéro faux n'est pas un SIRET.
    assertSame([], InvoiceScan::findSirets('SIRET 111 111 111 11111'));
});

Tests::run("un montant se lit à côté de son étiquette, colonne de blancs comprise", function (): void {
    $lines = implode("\n", [
        'Désignation            Qté     PU HT      Total HT',
        'Tôle acier               40     18,50       740,00',
        '',
        'Total HT                                    950,00 €',
        'TVA 20 %                                    190,00 €',
        'Net à payer                               1 140,00 €',
    ]);

    // Le récapitulatif l'emporte sur l'en-tête de colonne.
    assertSame(950.0, InvoiceScan::labelledAmount($lines, ['total ht'])['value']);
    // Le pourcentage n'est pas un montant.
    assertSame(190.0, InvoiceScan::labelledAmount($lines, ['tva'])['value']);
    assertSame(1140.0, InvoiceScan::labelledAmount($lines, ['net a payer'])['value']);
});

Tests::run('la cohérence HT + TVA = TTC fait la confiance', function (): void {
    $text = Cv::extractText(invoicePdf(), 'application/pdf');
    $result = InvoiceScan::analyse($text);

    assertSame(true, $result['coherent']);
    assertSame('F2026-0147', $result['fields']['reference']);
    assertSame([950.0, 190.0, 1140.0], [
        $result['fields']['amountHt'], $result['fields']['amountVat'], $result['fields']['amountTtc'],
    ]);
    assertSame(20.0, $result['fields']['vatRate']);
    assertSame('73282932000074', $result['fields']['siret']);
    assertSame('FR7630006000011234567890189', $result['fields']['iban']);
    assertSame('ACIERS DU NORD SAS', $result['fields']['supplierName']);
    assertTrue($result['confidence'] >= 70, 'confiance trop basse : ' . $result['confidence']);
});

Tests::run('des montants contradictoires font tomber la confiance et le disent', function (): void {
    $text = Cv::extractText(invoicePdf(['ttc' => '1 500,00']), 'application/pdf');
    $result = InvoiceScan::analyse($text);

    assertSame(false, $result['coherent']);
    assertTrue(str_contains(implode(' ', $result['notes']), 'contredisent'));
    assertTrue($result['confidence'] < 60, 'confiance trop haute : ' . $result['confidence']);
});

Tests::run('un document sans texte le signale', function (): void {
    $result = InvoiceScan::analyse('');
    assertSame(0, $result['confidence']);
    assertTrue(str_contains(implode(' ', $result['notes']), 'scanné en image'));
});

Tests::run('nos propres identifiants ne désignent pas un fournisseur', function (): void {
    Settings::set('company_siren', '552100554');
    $text = "Notre société SIREN 552 100 554\nFournisseur SIRET 732 829 320 00074\nTotal TTC 100,00 €";
    $result = InvoiceScan::analyse($text);

    assertSame('73282932000074', $result['fields']['siret']);
    assertSame('Fournisseur', $result['direction']);
});

Tests::run('un tiers connu est reconnu par son identifiant', function (): void {
    Db::run("INSERT INTO partners (kind, name, registration) VALUES ('Fournisseur', 'Aciers du Nord', '73282932000074')");
    $match = InvoiceScan::matchPartner('facture de la société', ['sirets' => ['73282932000074']]);

    assertSame('Aciers du Nord', $match['partner']['name']);
    assertSame('identifiant', $match['reason']);
});

// ---------- Réception ----------

Tests::run('un PDF déposé est lu, scellé et rangé', function (): void {
    $bytes = invoicePdf();
    $outcome = Intake::receive(['bytes' => $bytes, 'originalName' => 'facture.pdf', 'mimeType' => 'application/pdf']);

    assertSame(true, $outcome['ok']);
    $document = Intake::byId($outcome['id']);
    assertSame('Dépôt', $document['source']);
    assertSame('À traiter', $document['status']);
    assertSame(hash('sha256', $bytes), $document['sha256']);
    assertSame('F2026-0147', $document['fields']['reference']);
    // Le fichier est conservé sous un nom aléatoire, jamais celui annoncé.
    assertTrue($document['file_name'] !== 'facture.pdf' && is_file(Intake::pathOf($document)));
    assertSame(true, Intake::verify($document)['ok']);
});

Tests::run("la même pièce reçue deux fois ne fait qu'une ligne", function (): void {
    $bytes = invoicePdf();
    $first = Intake::receive(['bytes' => $bytes, 'originalName' => 'facture.pdf', 'mimeType' => 'application/pdf']);
    $second = Intake::receive(['bytes' => $bytes, 'originalName' => 'copie.pdf', 'mimeType' => 'application/pdf']);

    assertSame(false, $second['ok']);
    assertSame($first['id'], $second['duplicate']);
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM incoming_documents'));
});

Tests::run('un fichier déguisé est refusé', function (): void {
    $outcome = Intake::receive([
        'bytes' => 'MZ executable', 'originalName' => 'facture.pdf', 'mimeType' => 'application/pdf',
    ]);
    assertSame(false, $outcome['ok']);
    assertContains("n'est pas du type annoncé", $outcome['message']);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM incoming_documents'));

    // Un type non accepté non plus.
    $refused = Intake::receive(['bytes' => 'texte', 'originalName' => 'a.exe', 'mimeType' => 'application/x-msdownload']);
    assertSame(false, $refused['ok']);
});

Tests::run('un PDF sans texte est reçu quand même, avec zéro confiance', function (): void {
    $outcome = Intake::receive([
        'bytes' => makePdf([]), 'originalName' => 'scan.pdf', 'mimeType' => 'application/pdf',
    ]);
    assertSame(true, $outcome['ok']);

    $document = Intake::byId($outcome['id']);
    assertSame(0, (int) $document['text_length']);
    assertSame(0, (int) $document['confidence']);
    assertTrue(str_contains(implode(' ', $document['notes']), 'scanné en image'));
});

Tests::run("un fichier modifié sur le disque n'est plus servi", function (): void {
    $outcome = Intake::receive([
        'bytes' => invoicePdf(), 'originalName' => 'facture.pdf', 'mimeType' => 'application/pdf',
    ]);
    $document = Intake::byId($outcome['id']);
    file_put_contents(Intake::pathOf($document), 'autre chose');

    $verdict = Intake::verify($document);
    assertSame(false, $verdict['ok']);
    assertSame('empreinte différente', $verdict['reason']);

    loginFinance();
    assertSame(409, visit('GET', '/pieces/' . $outcome['id'] . '/fichier')->status);
});

// ---------- Analyse assistée ----------

Tests::run("rien n'est envoyé tant que ce n'est pas activé", function (): void {
    $calls = 0;
    Ai::$transport = function () use (&$calls): array {
        $calls++;
        return ['ok' => true, 'status' => 200, 'body' => '{}'];
    };

    assertSame(false, Ai::isReady());
    assertSame(false, Ai::analyse('Total TTC 100,00 €')['ok']);
    assertSame(0, $calls, 'un appel est parti alors que la fonction est éteinte');
    Ai::$transport = null;
});

Tests::run('activer sans clé est refusé, et la clé est chiffrée puis masquée', function (): void {
    $refused = Ai::setConfig(['provider' => 'anthropic', 'model' => 'claude-opus-5', 'enabled' => true]);
    assertSame(false, $refused['ok']);
    // La fonction reste éteinte : le refus intervient avant de l'activer.
    assertSame(false, Ai::config()['enabled']);
    assertSame(false, Ai::isReady());

    assertSame(true, Ai::setConfig([
        'provider' => 'anthropic', 'model' => 'claude-opus-5', 'key' => 'sk-ant-secret', 'enabled' => true,
    ])['ok']);

    $stored = (string) Settings::get('ai.key');
    assertTrue(!str_contains($stored, 'sk-ant-secret'), 'la clé est en clair en base');
    assertSame('sk-ant-secret', Secrets::decrypt($stored));
    assertTrue(!str_contains(Ai::displayConfig()['key'], 'secret'), 'la clé est réaffichée');

    // Une clé laissée vide conserve la précédente.
    Ai::setConfig(['provider' => 'anthropic', 'model' => 'claude-opus-5', 'key' => '', 'enabled' => true]);
    assertSame('sk-ant-secret', Ai::config()['key']);
});

Tests::run("l'appel envoie le texte et un schéma, et rien de plus", function (): void {
    Ai::setConfig(['provider' => 'anthropic', 'model' => 'claude-opus-5', 'key' => 'sk-ant-secret', 'enabled' => true]);

    $seen = null;
    Ai::$transport = function (string $url, array $headers, string $body) use (&$seen): array {
        $seen = ['url' => $url, 'headers' => $headers, 'body' => json_decode($body, true)];
        return ['ok' => true, 'status' => 200, 'body' => (string) json_encode([
            'model' => 'claude-opus-5',
            'content' => [['type' => 'text', 'text' => json_encode([
                'fournisseur' => 'Aciers du Nord', 'reference' => 'F2026-0147',
                'date_facture' => '12/03/2026', 'montant_ht' => 950, 'montant_tva' => 190,
                'montant_ttc' => 1140, 'devise' => 'eur', 'siret' => '732 829 320 00074',
                'iban' => 'fr76 3000 6000 0112 3456 7890 189', 'objet' => 'Tôles acier',
            ])]],
        ])];
    };

    $result = Ai::analyse("FACTURE N F2026-0147\nTotal HT 950,00");
    Ai::$transport = null;

    assertSame(Ai::ANTHROPIC_URL, $seen['url']);
    assertTrue(in_array('x-api-key: sk-ant-secret', $seen['headers'], true));
    assertContains("FACTURE N F2026-0147", $seen['body']['messages'][0]['content']);
    assertContains('montant_ttc', $seen['body']['system']);

    assertSame(true, $result['ok']);
    // Ce que le modèle rend est normalisé comme une saisie : dates, devise, IBAN.
    assertSame('2026-03-12', $result['fields']['issueDate']);
    assertSame('EUR', $result['fields']['currency']);
    assertSame('FR7630006000011234567890189', $result['fields']['iban']);
    assertSame('73282932000074', $result['fields']['siret']);
    assertSame(1140.0, $result['fields']['amountTtc']);
});

Tests::run("un refus ou une panne du service ne casse rien", function (): void {
    Ai::setConfig(['provider' => 'anthropic', 'model' => 'claude-opus-5', 'key' => 'sk', 'enabled' => true]);

    Ai::$transport = static fn (): array => ['ok' => true, 'status' => 200, 'body' => (string) json_encode([
        'stop_reason' => 'refusal', 'content' => [],
    ])];
    assertSame(false, Ai::analyse('facture')['ok']);

    Ai::$transport = static fn (): array => ['ok' => false, 'status' => 0, 'body' => 'réseau coupé'];
    $down = Ai::analyse('facture');
    assertSame(false, $down['ok']);
    assertContains('indisponible', $down['message']);

    // Même service éteint, la lecture par règles reste entière.
    Ai::$transport = null;
    $outcome = Intake::receive(['bytes' => invoicePdf(), 'originalName' => 'f.pdf', 'mimeType' => 'application/pdf']);
    assertSame('F2026-0147', Intake::byId($outcome['id'])['fields']['reference']);
});

Tests::run('un service compatible OpenAI marche aussi', function (): void {
    Ai::setConfig([
        'provider' => 'openai', 'model' => 'mistral-small-latest',
        'baseUrl' => 'https://api.mistral.test/v1/', 'key' => 'clef', 'enabled' => true,
    ]);

    $seen = null;
    Ai::$transport = function (string $url, array $headers, string $body) use (&$seen): array {
        $seen = $url;
        return ['ok' => true, 'status' => 200, 'body' => (string) json_encode([
            'model' => 'mistral-small-latest',
            'choices' => [['message' => ['content' => json_encode(['montant_ttc' => 240, 'reference' => 'A-1'])]]],
        ])];
    };

    $result = Ai::analyse('facture');
    Ai::$transport = null;

    assertSame('https://api.mistral.test/v1/chat/completions', $seen);
    assertSame(240.0, $result['fields']['amountTtc']);

    // Sans adresse, ce service est refusé.
    assertSame(false, Ai::setConfig(['provider' => 'openai', 'model' => 'm', 'baseUrl' => ''])['ok']);
});

Tests::run("l'essai vérifie que le service lit vraiment une facture", function (): void {
    Ai::setConfig(['provider' => 'anthropic', 'model' => 'claude-opus-5', 'key' => 'sk', 'enabled' => true]);

    Ai::$transport = static fn (): array => ['ok' => true, 'status' => 200, 'body' => (string) json_encode([
        'model' => 'claude-opus-5',
        'content' => [['type' => 'text', 'text' => json_encode(['montant_ttc' => 240, 'reference' => 'A-2026-88'])]],
    ])];
    assertSame(true, Ai::test()['ok']);
    assertSame(true, Ai::status()['ok']);

    // Un service qui répond n'importe quoi échoue à l'essai, et c'est noté.
    Ai::$transport = static fn (): array => ['ok' => true, 'status' => 200, 'body' => (string) json_encode([
        'model' => 'claude-opus-5',
        'content' => [['type' => 'text', 'text' => json_encode(['montant_ttc' => 9, 'reference' => 'X'])]],
    ])];
    assertSame(false, Ai::test()['ok']);
    assertSame(false, Ai::status()['ok']);
    Ai::$transport = null;
});

Tests::run('les règles gardent la main sur ce qu\'elles vérifient', function (): void {
    $rules = InvoiceScan::analyse(Cv::extractText(invoicePdf(), 'application/pdf'));
    $merged = Intake::merge($rules, [
        'siret' => '11111111111111', 'iban' => 'FR0000000000000000000000000',
        'reference' => 'AUTRE', 'supplierName' => 'Quelqu\'un d\'autre',
        'amountHt' => 1.0, 'amountVat' => 1.0, 'amountTtc' => 2.0,
        'label' => 'Tôles acier',
    ]);

    // Ce que les règles ont vérifié reste : SIRET, IBAN, numéro, montants cohérents.
    assertSame('73282932000074', $merged['fields']['siret']);
    assertSame('FR7630006000011234567890189', $merged['fields']['iban']);
    assertSame('F2026-0147', $merged['fields']['reference']);
    assertSame(1140.0, $merged['fields']['amountTtc']);
    // L'objet, que les règles ne cherchent pas, vient du modèle et le dit.
    assertSame('Tôles acier', $merged['fields']['label']);
    assertSame('ia', $merged['sources']['label']);
});

Tests::run("quand les règles se contredisent, un triplet cohérent du modèle l'emporte", function (): void {
    $rules = InvoiceScan::analyse(Cv::extractText(invoicePdf(['ttc' => '1 500,00']), 'application/pdf'));
    assertSame(false, $rules['coherent']);

    $merged = Intake::merge($rules, [
        'amountHt' => 950.0, 'amountVat' => 190.0, 'amountTtc' => 1140.0, 'vatRate' => 20.0,
    ]);
    assertSame(true, $merged['coherent']);
    assertSame(1140.0, $merged['fields']['amountTtc']);
    assertSame('ia', $merged['sources']['amountTtc']);
    assertTrue($merged['confidence'] > $rules['confidence']);
});

Tests::run('une pièce se relit avec le modèle une fois celui-ci activé', function (): void {
    $outcome = Intake::receive([
        'bytes' => invoicePdf(['reference' => 'sans-numero-lisible']),
        'originalName' => 'f.pdf', 'mimeType' => 'application/pdf',
    ]);
    $before = (int) Intake::byId($outcome['id'])['confidence'];

    Ai::setConfig(['provider' => 'anthropic', 'model' => 'claude-opus-5', 'key' => 'sk', 'enabled' => true]);
    Ai::$transport = static fn (): array => ['ok' => true, 'status' => 200, 'body' => (string) json_encode([
        'model' => 'claude-opus-5',
        'content' => [['type' => 'text', 'text' => json_encode(['objet' => 'Tôles acier'])]],
    ])];

    $verdict = Intake::reanalyse($outcome['id']);
    Ai::$transport = null;

    assertSame(true, $verdict['ok']);
    assertSame('règles + modèle', $verdict['analysis']['source']);
    assertSame('Tôles acier', $verdict['analysis']['fields']['label']);
    assertTrue((int) Intake::byId($outcome['id'])['confidence'] >= $before);
});

// ---------- Capture de la boîte aux lettres ----------

/** Un client IMAP d'essai : la logique de relève se vérifie sans réseau. */
final class FakeImap
{
    public array $seen = [];
    public array $moved = [];
    public bool $loggedOut = false;

    public function __construct(public array $messages) {}

    public function select(string $folder): array
    {
        return ['exists' => count($this->messages)];
    }

    public function searchUnseenSince(\DateTimeImmutable $since): array
    {
        $uids = [];
        foreach ($this->messages as $uid => $source) {
            if (!in_array($uid, $this->seen, true)) {
                $uids[] = $uid;
            }
        }
        return $uids;
    }

    public function fetchMessage(int $uid): string
    {
        return $this->messages[$uid] ?? '';
    }

    public function markSeen(int $uid): void
    {
        $this->seen[] = $uid;
    }

    public function move(int $uid, string $folder): void
    {
        $this->moved[$uid] = $folder;
    }

    public function logout(): void
    {
        $this->loggedOut = true;
    }
}

function configureMailbox(array $overrides = []): void
{
    Mailbox::setConfig(array_merge([
        'host' => 'imap.exemple.test', 'port' => 993, 'secure' => true,
        'user' => 'factures@exemple.test', 'password' => 'motdepasse',
        'folder' => 'INBOX', 'action' => 'seen', 'moveFolder' => 'Traitées',
        'sinceDays' => 30, 'batch' => 25, 'enabled' => true,
    ], $overrides));
}

Tests::run('la configuration de la boîte est contrôlée et le mot de passe chiffré', function (): void {
    assertSame("L'hôte IMAP est requis.", Mailbox::setConfig(['user' => 'a', 'action' => 'seen'])['message']);
    assertSame('Port invalide.', Mailbox::setConfig([
        'host' => 'h', 'user' => 'u', 'port' => 70000, 'action' => 'seen',
    ])['message']);
    assertContains('1 et 365 jours', Mailbox::setConfig([
        'host' => 'h', 'user' => 'u', 'sinceDays' => 400, 'action' => 'seen',
    ])['message']);
    assertSame('Action inconnue.', Mailbox::setConfig(['host' => 'h', 'user' => 'u', 'action' => 'effacer'])['message']);

    // Activer sans mot de passe est refusé.
    assertContains('mot de passe', Mailbox::setConfig([
        'host' => 'h', 'user' => 'u', 'action' => 'seen', 'enabled' => true,
    ])['message']);

    configureMailbox();
    assertSame(true, Mailbox::isReady());
    assertTrue(!str_contains((string) Settings::get('imap.password'), 'motdepasse'), 'mot de passe en clair');
    assertSame('motdepasse', Secrets::decrypt(Settings::get('imap.password')));
    assertTrue(!str_contains(Mailbox::displayConfig()['password'], 'motdepasse'));
});

Tests::run('la relève retient les pièces jointes et marque les messages lus', function (): void {
    configureMailbox();
    $client = new FakeImap([
        7 => mailWithAttachment(['attachment' => invoicePdf()]),
        8 => mailWithAttachment(['subject' => 'Bonjour']),
    ]);
    Mailbox::$connector = static fn (): object => $client;

    $verdict = Mailbox::fetchOnce();
    assertSame(true, $verdict['ok']);
    assertSame(2, $verdict['scanned']);
    assertSame(1, $verdict['received']);
    assertSame([7, 8], $client->seen, 'un message sans pièce jointe reviendrait à chaque relève');
    assertSame(true, $client->loggedOut);

    $document = Intake::all()[0];
    assertSame('Courriel', $document['source']);
    assertSame('7', $document['mail_uid']);
    assertContains('compta@aciers.test', $document['mail_from']);
    assertSame('Facture F2026-0147', $document['mail_subject']);
    assertSame('facture.pdf', $document['original_name']);
    assertSame('F2026-0147', $document['fields']['reference']);

    // Une seconde relève ne rapporte rien : les messages sont lus.
    $again = Mailbox::fetchOnce();
    assertSame(0, $again['scanned']);
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM incoming_documents'));
    Mailbox::$connector = null;
});

Tests::run('la même facture reçue deux fois par courriel ne fait qu\'une pièce', function (): void {
    configureMailbox();
    $pdf = invoicePdf();
    $client = new FakeImap([
        1 => mailWithAttachment(['attachment' => $pdf]),
        2 => mailWithAttachment(['attachment' => $pdf, 'subject' => 'Relance']),
    ]);
    Mailbox::$connector = static fn (): object => $client;

    $verdict = Mailbox::fetchOnce();
    assertSame(1, $verdict['received']);
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM incoming_documents'));
    assertContains('déjà arrivée', $verdict['skipped'][0]['reason']);
    Mailbox::$connector = null;
});

Tests::run('le message peut être rangé dans un dossier plutôt que seulement marqué lu', function (): void {
    configureMailbox(['action' => 'move', 'moveFolder' => 'Traitées']);
    $client = new FakeImap([3 => mailWithAttachment(['attachment' => invoicePdf()])]);
    Mailbox::$connector = static fn (): object => $client;

    Mailbox::fetchOnce();
    assertSame(['3' => 'Traitées'], array_map('strval', $client->moved));
    assertSame([3], $client->seen);
    Mailbox::$connector = null;
});

Tests::run("un serveur injoignable est rendu comme un échec, pas comme une panne", function (): void {
    configureMailbox();
    Mailbox::$connector = static function (): object {
        throw new \RuntimeException('identifiants refusés');
    };

    $verdict = Mailbox::fetchOnce();
    assertSame(false, $verdict['ok']);
    assertContains('Connexion refusée', $verdict['message']);
    assertSame(false, Mailbox::status()['ok']);

    $test = Mailbox::test();
    assertSame(false, $test['ok']);
    Mailbox::$connector = null;
});

Tests::run("aucun message n'est supprimé : la boîte reste la source", function (): void {
    $client = new \ReflectionClass(\App\Core\Imap::class);
    $source = (string) file_get_contents($client->getFileName());
    assertTrue(!str_contains($source, 'EXPUNGE ALL'));
    assertTrue(!preg_match('/function\s+(delete|expunge)\s*\(/i', $source) === true);
    // Le seul \Deleted du client sert à émuler MOVE sur un serveur qui ne l'a pas.
    assertSame(1, substr_count($source, '\\\\Deleted'));
});

// ---------- Le vrai protocole ----------

Tests::run('le client IMAP écrit à la main parle à un vrai serveur', function (): void {
    $state = [
        'user' => 'factures', 'password' => 'motdepasse',
        'messages' => [
            ['uid' => 11, 'source' => base64_encode(mailWithAttachment(['attachment' => invoicePdf()])), 'seen' => false, 'folder' => 'INBOX'],
            ['uid' => 12, 'source' => base64_encode(mailWithAttachment(['subject' => 'Lu'])), 'seen' => true, 'folder' => 'INBOX'],
        ],
    ];
    $path = sys_get_temp_dir() . '/toutadmin-tests/imap-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($path, json_encode($state));

    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/tests/imap-server.php', $path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    assertTrue(is_resource($process));
    $port = (int) trim((string) fgets($pipes[1]));
    assertTrue($port > 0, 'le serveur d\'essai n\'a pas annoncé son port');

    $imap = new \App\Core\Imap('127.0.0.1', $port, false);
    $imap->connect();
    $imap->login('factures', 'motdepasse');
    $imap->select('INBOX');

    // Seul le message non lu est rendu, et son contenu arrive entier.
    assertSame([11], $imap->searchUnseenSince(new \DateTimeImmutable('-30 days')));
    $raw = $imap->fetchMessage(11);
    assertContains('Subject: Facture F2026-0147', $raw);
    assertSame(1, count(\App\Core\Mime::attachments($raw)));

    $imap->markSeen(11);
    $imap->move(11, 'Traitées');
    $imap->logout();

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $after = json_decode((string) file_get_contents($path), true)['messages'];
    assertSame(true, $after[0]['seen']);
    assertSame('Traitées', $after[0]['folder']);
    unlink($path);
});

Tests::run('un mot de passe faux est rendu comme un échec', function (): void {
    $path = sys_get_temp_dir() . '/toutadmin-tests/imap-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($path, json_encode(['user' => 'factures', 'password' => 'motdepasse', 'messages' => []]));

    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__) . '/tests/imap-server.php', $path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $port = (int) trim((string) fgets($pipes[1]));

    $imap = new \App\Core\Imap('127.0.0.1', $port, false);
    $imap->connect();
    $refused = false;
    try {
        $imap->login('factures', 'faux');
    } catch (\RuntimeException $error) {
        $refused = str_contains($error->getMessage(), 'refusés');
    }
    $imap->logout();
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    unlink($path);

    assertSame(true, $refused);
});

// ---------- Lecture d'un courriel ----------

Tests::run("un courriel est lu : expéditeur, sujet accentué, pièce jointe", function (): void {
    $raw = mailWithAttachment([
        'subject' => '=?UTF-8?B?' . base64_encode('Facture réglée — mars') . '?=',
        'attachment' => invoicePdf(),
        'fileName' => 'facture mars.pdf',
    ]);

    $envelope = \App\Core\Mime::envelope($raw);
    assertSame('Facture réglée — mars', $envelope['subject']);
    assertContains('compta@aciers.test', $envelope['from']);
    assertSame('2026-03-12', substr((string) $envelope['date'], 0, 10));

    $attachments = \App\Core\Mime::attachments($raw);
    assertSame(1, count($attachments));
    assertSame('facture mars.pdf', $attachments[0]['filename']);
    assertSame('application/pdf', $attachments[0]['contentType']);
    assertSame(invoicePdf(), $attachments[0]['content']);

    // Une signature d'image ou un calendrier ne sont pas des factures.
    assertSame([], Mailbox::attachmentsOf([
        ['filename' => 'logo.png', 'contentType' => 'image/png', 'content' => 'x'],
        ['filename' => 'rdv.ics', 'contentType' => 'text/calendar', 'content' => 'x'],
    ]));
});

// ---------- Écran du comptable ----------

Tests::run("le dépôt passe par l'écran, puis la facture reprend ce qui est validé", function (): void {
    loginFinance();
    $response = visit('POST', '/pieces/deposer', [], [], [
        'document' => ['bytes' => invoicePdf(), 'mime' => 'application/pdf', 'name' => 'facture.pdf'],
    ]);
    assertSame(302, $response->status);

    $document = Intake::all()[0];
    assertSame('F2026-0147', $document['fields']['reference']);

    $page = visit('GET', '/pieces/' . $document['id']);
    assertSame(200, $page->status);
    assertContains('F2026-0147', $page->body);
    assertContains('73282932000074', $page->body);

    // C'est ce qui est validé à l'écran qui est enregistré, pas ce qui a été lu.
    visit('POST', '/pieces/' . $document['id'] . '/facturer', [
        'direction' => 'Fournisseur', 'label' => 'Tôles acier mars', 'issue_date' => '2026-03-12',
        'due_date' => '2026-04-11', 'amount_ht' => '900', 'vat_rate' => '20', 'currency' => 'EUR',
        'reference' => 'F2026-0147',
    ]);

    $invoice = Db::get('SELECT * FROM invoices');
    assertSame('Tôles acier mars', $invoice['label']);
    assertSame(900.0, (float) $invoice['amount_ht']);
    assertSame('Facturée', Intake::byId((int) $document['id'])['status']);
    assertContains('pièce reçue n° ' . $document['id'], $invoice['notes']);

    // Une pièce déjà facturée ne se refacture ni ne se supprime.
    visit('POST', '/pieces/' . $document['id'] . '/facturer', [
        'direction' => 'Fournisseur', 'label' => 'Doublon', 'issue_date' => '2026-03-12',
        'amount_ht' => '900', 'vat_rate' => '20', 'currency' => 'EUR',
    ]);
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM invoices'));
    visit('POST', '/pieces/' . $document['id'] . '/supprimer');
    assertTrue(Intake::byId((int) $document['id']) !== null, 'une pièce justificative a été supprimée');
});

Tests::run('une pièce écartée se motive, puis se supprime', function (): void {
    loginFinance();
    $outcome = Intake::receive(['bytes' => invoicePdf(), 'originalName' => 'f.pdf', 'mimeType' => 'application/pdf']);
    $path = Intake::pathOf(Intake::byId($outcome['id']));

    visit('POST', '/pieces/' . $outcome['id'] . '/ecarter', ['note' => 'Facture en double du fournisseur']);
    $document = Intake::byId($outcome['id']);
    assertSame('Écartée', $document['status']);
    assertSame('Facture en double du fournisseur', $document['note']);

    visit('POST', '/pieces/' . $outcome['id'] . '/supprimer');
    assertSame(null, Intake::byId($outcome['id']));
    assertSame(false, is_file($path), 'le fichier est resté sur le disque');
});

Tests::run("l'espace est réservé à la gestion, et ses réglages à l'administration", function (): void {
    $ids = seed();
    \App\Modules\Users::setRoleFlag($ids['member'], 'is_finance', true);

    // Un salarié sans la gestion n'entre pas.
    $other = \App\Modules\Users::create([
        'role' => 'employee', 'email' => 'curieux@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Curieux', 'last_name' => 'Voisin',
    ]);
    visit('POST', '/connexion', ['email' => 'curieux@entreprise.com', 'password' => 'Salarie-Demo-2026!']);
    assertSame(403, visit('GET', '/pieces')->status);

    // La gestion entre, mais ne règle ni la boîte aux lettres ni le service extérieur.
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(200, visit('GET', '/pieces')->status);

    visit('POST', '/pieces/capture/reglages', [
        'host' => 'imap.pirate.test', 'user' => 'moi', 'password' => 'x', 'action' => 'seen', 'enabled' => '1',
    ]);
    assertSame('', (string) Settings::get('imap.host'));

    visit('POST', '/pieces/analyse/reglages', [
        'provider' => 'anthropic', 'model' => 'claude-opus-5', 'key' => 'sk-pirate', 'enabled' => '1',
    ]);
    assertSame('', (string) Settings::get('ai.key'));

    // L'administration, elle, règle les deux.
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', '/pieces/capture/reglages', [
        'host' => 'imap.exemple.test', 'port' => '993', 'secure' => '1', 'user' => 'factures@exemple.test',
        'password' => 'motdepasse', 'folder' => 'INBOX', 'action' => 'seen', 'since_days' => '30', 'batch' => '25',
        'enabled' => '1',
    ]);
    assertSame('imap.exemple.test', (string) Settings::get('imap.host'));
    assertSame('1', (string) Settings::get('imap.enabled'));
});

// ---------- Photos de profil ----------

/** Un PNG minimal valide : l'en-tête suffit, c'est le contenu qui est contrôlé. */
function pngBytes(): string
{
    return (string) hex2bin('89504e470d0a1a0a0000000d49484452');
}

Tests::run('une photo de profil se dépose, se sert, puis se retire', function (): void {
    $ids = seed();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);

    $response = visit('POST', '/mon-profil/photo', [], [], [
        'avatar' => ['bytes' => pngBytes(), 'mime' => 'image/png', 'name' => 'moi.png'],
    ]);
    assertSame(302, $response->status);

    $stored = (string) Db::value('SELECT avatar_file FROM users WHERE id = ?', [$ids['member']]);
    // Le nom d'origine n'est jamais réutilisé.
    assertTrue((bool) preg_match('/^[a-f0-9]{32}\.png$/', $stored), 'nom de fichier inattendu : ' . $stored);
    assertSame(true, is_file(\App\Modules\Avatars::pathOf($stored)));

    $file = visit('GET', '/media/avatars/' . $stored);
    assertSame(200, $file->status);
    assertSame(pngBytes(), $file->body);

    // L'annuaire montre la photo plutôt que les initiales.
    assertContains('/media/avatars/' . $stored, visit('GET', '/annuaire')->body);

    visit('POST', '/mon-profil/photo/supprimer');
    assertSame(null, Db::value('SELECT avatar_file FROM users WHERE id = ?', [$ids['member']]));
    assertSame(false, is_file(\App\Modules\Avatars::directory() . '/' . $stored));
});

Tests::run("un fichier qui n'est pas une image est refusé, et rien n'est écrit", function (): void {
    $ids = seed();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);

    visit('POST', '/mon-profil/photo', [], [], [
        'avatar' => ['bytes' => 'MZ executable', 'mime' => 'image/png', 'name' => 'piege.png'],
    ]);
    assertSame(null, Db::value('SELECT avatar_file FROM users WHERE id = ?', [$ids['member']]));
    assertSame(0, count(glob(\App\Modules\Avatars::directory() . '/*') ?: []));

    // Un envoi sans jeton n'écrit rien non plus.
    $forged = visit('POST', '/mon-profil/photo', ['_csrf' => 'faux'], [], [
        'avatar' => ['bytes' => pngBytes(), 'mime' => 'image/png', 'name' => 'moi.png'],
    ]);
    assertSame(403, $forged->status);
    assertSame(0, count(glob(\App\Modules\Avatars::directory() . '/*') ?: []));
});

Tests::run("le service des photos ne sort pas de son dossier", function (): void {
    seed();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);

    assertSame(404, visit('GET', '/media/avatars/..%2F..%2Fapp.sqlite')->status);
    assertSame(404, visit('GET', '/media/avatars/app.sqlite')->status);
    assertSame(null, \App\Modules\Avatars::pathOf('../app.sqlite'));

    // Et il demande une session.
    visit('POST', '/deconnexion');
    assertSame(302, visit('GET', '/media/avatars/' . str_repeat('a', 32) . '.png')->status);
});
