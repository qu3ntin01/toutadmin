<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Le cycle de vente : le catalogue, la commande, la livraison, la facture —
 * les mêmes règles que l'édition Node.
 *
 * Une commande ne recopie jamais ce qui a été livré ou facturé : ces quantités
 * se calculent, ligne par ligne, sur les bons de livraison et sur les factures
 * qui ne sont pas annulées. Une facture annulée rend donc sa quantité d'elle-
 * même. Une commande qui mêle les taux de TVA fait une facture par taux.
 */
final class Ventes
{
    public const KINDS = ['Service', 'Bien'];
    public const UNITS = ['unité', 'heure', 'jour', 'forfait', 'mois', 'kg', 'm', 'm²', 'litre'];

    private static function ici(): ?int
    {
        return Societes::courant();
    }

    private static function today(): string
    {
        return gmdate('Y-m-d');
    }

    private static function date(string $d): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;
    }

    // ------------------------------------------------------------ catalogue

    public static function produits(bool $actifs = false): array
    {
        return Db::all(
            'SELECT p.*, i.label AS item_label FROM products p LEFT JOIN items i ON i.id = p.item_id
             WHERE p.company_id = ? ' . ($actifs ? 'AND p.active = 1 ' : '') . '
             ORDER BY p.active DESC, p.reference COLLATE NOCASE',
            [self::ici()]
        );
    }

    public static function produitParId(int $id): ?array
    {
        return Db::get('SELECT * FROM products WHERE id = ? AND company_id = ?', [$id, self::ici()]);
    }

    public static function creerProduit(array $d): array
    {
        $ref = trim((string) ($d['reference'] ?? ''));
        $nom = trim((string) ($d['name'] ?? ''));
        $kind = (string) ($d['kind'] ?? 'Service');
        $unit = (string) ($d['unit'] ?? 'unité');
        $prix = $d['priceHt'] ?? null;
        $tva = $d['vatRate'] ?? 20.0;
        $itemId = (int) ($d['itemId'] ?? 0);
        if (preg_match('/^[A-Za-z0-9._-]{1,40}$/', $ref) !== 1) {
            return ['ok' => false, 'reason' => 'reference'];
        }
        if ($nom === '') {
            return ['ok' => false, 'reason' => 'name'];
        }
        if (!in_array($kind, self::KINDS, true)) {
            return ['ok' => false, 'reason' => 'kind'];
        }
        if (!in_array($unit, self::UNITS, true)) {
            return ['ok' => false, 'reason' => 'unit'];
        }
        if (!is_float($prix) && !is_int($prix) || $prix < 0 || $prix > 1e9) {
            return ['ok' => false, 'reason' => 'price'];
        }
        if (!is_float($tva) && !is_int($tva) || $tva < 0 || $tva > 100) {
            return ['ok' => false, 'reason' => 'vat'];
        }
        $article = null;
        if ($itemId > 0) {
            if ($kind !== 'Bien') {
                return ['ok' => false, 'reason' => 'item-service'];
            }
            $article = Db::get('SELECT id FROM items WHERE id = ? AND company_id = ?', [$itemId, self::ici()]);
            if ($article === null) {
                return ['ok' => false, 'reason' => 'item'];
            }
        }
        if (Db::get('SELECT 1 AS ok FROM products WHERE reference = ? AND company_id = ?', [$ref, self::ici()]) !== null) {
            return ['ok' => false, 'reason' => 'duplicate'];
        }
        $id = Db::insert(
            'INSERT INTO products (company_id, reference, name, description, kind, unit, price_ht, vat_rate, item_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [self::ici(), $ref, mb_substr($nom, 0, 160), mb_substr((string) ($d['description'] ?? ''), 0, 1000), $kind, $unit,
             round((float) $prix, 2), (float) $tva, $article === null ? null : (int) $article['id']]
        );
        return ['ok' => true, 'id' => $id];
    }

    public static function basculerProduit(int $id): bool
    {
        return Db::run('UPDATE products SET active = 1 - active WHERE id = ? AND company_id = ?', [$id, self::ici()]) > 0;
    }

    /** Un produit déjà commandé ne s'efface pas : il se retire du catalogue. */
    public static function supprimerProduit(int $id): array
    {
        $produit = self::produitParId($id);
        if ($produit === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if (Db::get('SELECT 1 AS ok FROM sales_order_lines WHERE product_id = ?', [(int) $produit['id']]) !== null) {
            Db::run('UPDATE products SET active = 0 WHERE id = ? AND company_id = ?', [(int) $produit['id'], self::ici()]);
            return ['ok' => true, 'deactivated' => true];
        }
        Db::run('DELETE FROM products WHERE id = ? AND company_id = ?', [(int) $produit['id'], self::ici()]);
        return ['ok' => true, 'deactivated' => false];
    }

    // ------------------------------------------------------------ commandes

    private static function prochaineReference(string $prefixe, string $table, string $date): string
    {
        $annee = substr($date, 0, 4);
        $derniere = Db::value(
            "SELECT reference FROM $table WHERE company_id = ? AND reference LIKE ? ORDER BY id DESC LIMIT 1",
            [self::ici(), "$prefixe-$annee-%"]
        );
        $n = $derniere ? (int) substr((string) $derniere, strrpos((string) $derniere, '-') + 1) + 1 : 1;
        return sprintf('%s-%s-%04d', $prefixe, $annee, $n);
    }

    /** Les lignes d'une commande, avec ce qui en est livré et facturé — calculé. */
    public static function lignes(int $orderId): array
    {
        $rows = Db::all(
            "SELECT l.*, p.reference AS product_reference, p.item_id,
               COALESCE((SELECT SUM(d.quantity) FROM delivery_note_lines d WHERE d.order_line_id = l.id), 0) AS delivered,
               COALESCE((SELECT SUM(s.quantity) FROM sales_invoice_lines s JOIN invoices f ON f.id = s.invoice_id
                         WHERE s.order_line_id = l.id AND f.status <> 'Annulée'), 0) AS invoiced
             FROM sales_order_lines l
             JOIN sales_orders o ON o.id = l.order_id
             LEFT JOIN products p ON p.id = l.product_id
             WHERE l.order_id = ? AND o.company_id = ?
             ORDER BY l.position, l.id",
            [$orderId, self::ici()]
        );
        return array_map(static function (array $l): array {
            $l['quantity'] = (float) $l['quantity'];
            $l['unit_price_ht'] = (float) $l['unit_price_ht'];
            $l['vat_rate'] = (float) $l['vat_rate'];
            $l['delivered'] = round((float) $l['delivered'], 3);
            $l['invoiced'] = round((float) $l['invoiced'], 3);
            $l['remaining'] = round($l['quantity'] - $l['delivered'], 3);
            $l['toInvoice'] = round($l['delivered'] - $l['invoiced'], 3);
            $l['total'] = round($l['quantity'] * $l['unit_price_ht'], 2);
            return $l;
        }, $rows);
    }

    private static function etat(array $commande, array $ls): string
    {
        if ($commande['status'] !== 'Confirmée') {
            return (string) $commande['status'];
        }
        $cmd = array_sum(array_column($ls, 'quantity'));
        $livre = array_sum(array_column($ls, 'delivered'));
        $facture = array_sum(array_column($ls, 'invoiced'));
        if ($cmd > 0 && $facture >= $cmd) {
            return 'Facturée';
        }
        if ($cmd > 0 && $livre >= $cmd) {
            return 'Livrée';
        }
        return $livre > 0 ? 'Partiellement livrée' : 'Confirmée';
    }

    private static function enrichir(array $c): array
    {
        $ls = self::lignes((int) $c['id']);
        $ht = 0.0;
        $tva = 0.0;
        foreach ($ls as $l) {
            $ht += $l['total'];
            $tva += $l['total'] * $l['vat_rate'] / 100;
        }
        return $c + ['lignes' => $ls, 'etat' => self::etat($c, $ls), 'totalHt' => round($ht, 2), 'totalTtc' => round($ht + $tva, 2)];
    }

    public static function commandes(): array
    {
        return array_map([self::class, 'enrichir'], Db::all(
            'SELECT o.*, p.name AS partner_name FROM sales_orders o JOIN partners p ON p.id = o.partner_id
             WHERE o.company_id = ? ORDER BY o.order_date DESC, o.id DESC',
            [self::ici()]
        ));
    }

    public static function commandeParId(int $id): ?array
    {
        $c = Db::get(
            'SELECT o.*, p.name AS partner_name FROM sales_orders o JOIN partners p ON p.id = o.partner_id
             WHERE o.id = ? AND o.company_id = ?',
            [$id, self::ici()]
        );
        if ($c === null) {
            return null;
        }
        $c = self::enrichir($c);
        $c['livraisons'] = Db::all('SELECT * FROM delivery_notes WHERE order_id = ? AND company_id = ? ORDER BY id', [$id, self::ici()]);
        $c['factures'] = Db::all(
            'SELECT id, reference, label, issue_date, amount_ht, vat_rate, status FROM invoices WHERE sales_order_id = ? AND company_id = ? ORDER BY id',
            [$id, self::ici()]
        );
        return $c;
    }

    public static function creerCommande(int $partnerId, ?string $orderDate = null, string $notes = '', ?int $quoteId = null, ?int $createdBy = null): array
    {
        $orderDate ??= self::today();
        if (Db::get('SELECT 1 AS ok FROM partners WHERE id = ? AND company_id = ?', [$partnerId, self::ici()]) === null) {
            return ['ok' => false, 'reason' => 'partner'];
        }
        if (!self::date($orderDate)) {
            return ['ok' => false, 'reason' => 'date'];
        }
        $reference = self::prochaineReference('CMD', 'sales_orders', $orderDate);
        $id = Db::insert(
            'INSERT INTO sales_orders (company_id, reference, partner_id, quote_id, order_date, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [self::ici(), $reference, $partnerId, $quoteId, $orderDate, mb_substr($notes, 0, 1000), $createdBy]
        );
        return ['ok' => true, 'id' => $id, 'reference' => $reference];
    }

    private static function brouillon(int $orderId): array
    {
        $c = Db::get('SELECT * FROM sales_orders WHERE id = ? AND company_id = ?', [$orderId, self::ici()]);
        if ($c === null) {
            return [null, ['ok' => false, 'reason' => 'not-found']];
        }
        if ($c['status'] !== 'Brouillon') {
            return [null, ['ok' => false, 'reason' => 'not-draft']];
        }
        return [$c, null];
    }

    public static function ajouterLigne(int $orderId, ?int $productId, string $label, float $quantity, ?float $unitPriceHt, ?float $vatRate): array
    {
        [$c, $erreur] = self::brouillon($orderId);
        if ($erreur !== null) {
            return $erreur;
        }
        $produit = $productId ? self::produitParId($productId) : null;
        if ($productId && ($produit === null || (int) $produit['active'] !== 1)) {
            return ['ok' => false, 'reason' => 'product'];
        }
        $libelle = trim($label) !== '' ? trim($label) : (string) ($produit['name'] ?? '');
        $prix = $unitPriceHt ?? ($produit !== null ? (float) $produit['price_ht'] : null);
        $tva = $vatRate ?? ($produit !== null ? (float) $produit['vat_rate'] : 20.0);
        if ($libelle === '') {
            return ['ok' => false, 'reason' => 'label'];
        }
        if (!is_finite($quantity) || $quantity <= 0 || $quantity > 1e7) {
            return ['ok' => false, 'reason' => 'quantity'];
        }
        if ($prix === null || !is_finite($prix) || $prix < 0 || $prix > 1e9) {
            return ['ok' => false, 'reason' => 'price'];
        }
        if (!is_finite($tva) || $tva < 0 || $tva > 100) {
            return ['ok' => false, 'reason' => 'vat'];
        }
        $position = (int) Db::value('SELECT COALESCE(MAX(position), 0) + 1 FROM sales_order_lines WHERE order_id = ?', [(int) $c['id']]);
        $id = Db::insert(
            'INSERT INTO sales_order_lines (order_id, product_id, label, quantity, unit, unit_price_ht, vat_rate, position) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [(int) $c['id'], $produit === null ? null : (int) $produit['id'], mb_substr($libelle, 0, 200), round($quantity, 3),
             $produit['unit'] ?? 'unité', round($prix, 2), $tva, $position]
        );
        return ['ok' => true, 'id' => $id];
    }

    public static function retirerLigne(int $lineId): array
    {
        $ligne = Db::get(
            'SELECT l.id, l.order_id FROM sales_order_lines l JOIN sales_orders o ON o.id = l.order_id WHERE l.id = ? AND o.company_id = ?',
            [$lineId, self::ici()]
        );
        if ($ligne === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        [, $erreur] = self::brouillon((int) $ligne['order_id']);
        if ($erreur !== null) {
            return $erreur;
        }
        Db::run('DELETE FROM sales_order_lines WHERE id = ?', [(int) $ligne['id']]);
        return ['ok' => true, 'orderId' => (int) $ligne['order_id']];
    }

    public static function confirmer(int $orderId): array
    {
        [$c, $erreur] = self::brouillon($orderId);
        if ($erreur !== null) {
            return $erreur;
        }
        if (Db::get('SELECT 1 AS ok FROM sales_order_lines WHERE order_id = ?', [(int) $c['id']]) === null) {
            return ['ok' => false, 'reason' => 'empty'];
        }
        Db::run("UPDATE sales_orders SET status = 'Confirmée' WHERE id = ? AND company_id = ?", [(int) $c['id'], self::ici()]);
        return ['ok' => true];
    }

    /** Une commande s'annule tant que rien n'en est parti. */
    public static function annuler(int $orderId): array
    {
        $c = self::commandeParId($orderId);
        if ($c === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if ($c['status'] === 'Annulée') {
            return ['ok' => false, 'reason' => 'already'];
        }
        foreach ($c['lignes'] as $l) {
            if ($l['delivered'] > 0 || $l['invoiced'] > 0) {
                return ['ok' => false, 'reason' => 'started'];
            }
        }
        Db::run("UPDATE sales_orders SET status = 'Annulée' WHERE id = ? AND company_id = ?", [(int) $c['id'], self::ici()]);
        return ['ok' => true];
    }

    public static function depuisDevis(int $quoteId, ?int $createdBy = null): array
    {
        $devis = Db::get('SELECT * FROM quotes WHERE id = ? AND company_id = ?', [$quoteId, self::ici()]);
        if ($devis === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if ($devis['status'] !== 'Accepté') {
            return ['ok' => false, 'reason' => 'not-accepted'];
        }
        if ($devis['invoice_id'] !== null) {
            return ['ok' => false, 'reason' => 'already-invoiced'];
        }
        if (Db::get("SELECT 1 AS ok FROM sales_orders WHERE quote_id = ? AND company_id = ? AND status <> 'Annulée'", [$quoteId, self::ici()]) !== null) {
            return ['ok' => false, 'reason' => 'already-ordered'];
        }
        return Db::transaction(static function () use ($devis, $createdBy): array {
            $c = self::creerCommande((int) $devis['partner_id'], null, 'Issue du devis ' . ($devis['reference'] ?: '#' . $devis['id']), (int) $devis['id'], $createdBy);
            if ($c['ok']) {
                self::ajouterLigne((int) $c['id'], null, (string) $devis['label'], 1.0, (float) $devis['amount_ht'], (float) $devis['vat_rate']);
            }
            return $c;
        });
    }

    // ----------------------------------------------------------- livraisons

    /**
     * Livrer les quantités données : chacune au plus ce qui reste. Un bien lié
     * au stock en sort ; si le stock manque pour une seule ligne, rien n'est
     * livré.
     */
    public static function livrer(int $orderId, array $quantites, ?string $date = null, ?int $createdBy = null): array
    {
        $date ??= self::today();
        $c = self::commandeParId($orderId);
        if ($c === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if ($c['status'] !== 'Confirmée') {
            return ['ok' => false, 'reason' => 'not-confirmed'];
        }
        if (!self::date($date) || $date > self::today()) {
            return ['ok' => false, 'reason' => 'date'];
        }
        $retenues = [];
        foreach ($c['lignes'] as $l) {
            $brut = $quantites[(int) $l['id']] ?? null;
            if ($brut === null || trim((string) $brut) === '') {
                continue;
            }
            $texte = str_replace(',', '.', trim((string) $brut));
            if (!is_numeric($texte) || (float) $texte < 0) {
                return ['ok' => false, 'reason' => 'quantity', 'line' => $l['label']];
            }
            $q = round((float) $texte, 3);
            if ($q == 0.0) {
                continue;
            }
            if ($q > $l['remaining']) {
                return ['ok' => false, 'reason' => 'too-much', 'line' => $l['label'], 'remaining' => $l['remaining']];
            }
            $retenues[] = [$l, $q];
        }
        if ($retenues === []) {
            return ['ok' => false, 'reason' => 'nothing'];
        }
        $parArticle = [];
        foreach ($retenues as [$l, $q]) {
            if ($l['item_id'] !== null) {
                $parArticle[(int) $l['item_id']] = ($parArticle[(int) $l['item_id']] ?? 0) + $q;
            }
        }
        foreach ($parArticle as $itemId => $total) {
            $article = Inventory::itemById($itemId);
            if ($article !== null && $total > (float) $article['stock']) {
                return ['ok' => false, 'reason' => 'stock', 'item' => $article['label'], 'stock' => (float) $article['stock']];
            }
        }
        $reference = self::prochaineReference('BL', 'delivery_notes', $date);
        try {
            $noteId = self::ecrireLivraison($c, $retenues, $reference, $date, $createdBy);
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'stock:')) {
                return ['ok' => false, 'reason' => 'stock', 'item' => substr($e->getMessage(), 6)];
            }
            throw $e;
        }
        return ['ok' => true, 'id' => $noteId, 'reference' => $reference, 'lignes' => count($retenues)];
    }

    private static function ecrireLivraison(array $c, array $retenues, string $reference, string $date, ?int $createdBy): int
    {
        return Db::transaction(static function () use ($c, $retenues, $reference, $date, $createdBy): int {
            $noteId = Db::insert(
                'INSERT INTO delivery_notes (company_id, order_id, reference, delivered_on, created_by) VALUES (?, ?, ?, ?, ?)',
                [self::ici(), (int) $c['id'], $reference, $date, $createdBy]
            );
            foreach ($retenues as [$l, $q]) {
                Db::run('INSERT INTO delivery_note_lines (note_id, order_line_id, quantity) VALUES (?, ?, ?)', [$noteId, (int) $l['id'], $q]);
                if ($l['item_id'] !== null) {
                    $sortie = Inventory::move(['itemId' => (int) $l['item_id'], 'kind' => 'Sortie', 'quantity' => $q,
                        'reason' => $reference . ' — ' . $c['reference'], 'movedOn' => $date, 'createdBy' => $createdBy]);
                    if (!$sortie['ok']) {
                        throw new \RuntimeException('stock:' . $l['label']);
                    }
                }
            }
            return $noteId;
        });
    }

    public static function bonDeLivraison(int $id): ?array
    {
        $note = Db::get(
            'SELECT n.*, o.reference AS order_reference, o.partner_id, p.name AS partner_name, p.address AS partner_address
             FROM delivery_notes n JOIN sales_orders o ON o.id = n.order_id JOIN partners p ON p.id = o.partner_id
             WHERE n.id = ? AND n.company_id = ?',
            [$id, self::ici()]
        );
        if ($note === null) {
            return null;
        }
        $note['lignes'] = Db::all(
            'SELECT d.quantity, l.label, l.unit, pr.reference AS product_reference
             FROM delivery_note_lines d JOIN sales_order_lines l ON l.id = d.order_line_id
             LEFT JOIN products pr ON pr.id = l.product_id
             WHERE d.note_id = ? ORDER BY l.position, l.id',
            [(int) $note['id']]
        );
        $note['societe'] = Db::get('SELECT name, registration FROM companies WHERE id = ?', [self::ici()]) ?? [];
        return $note;
    }

    // ---------------------------------------------------------- facturation

    /** Facturer le livré non facturé : une facture par taux de TVA, à trente jours. */
    public static function facturer(int $orderId, ?string $date = null, ?int $createdBy = null): array
    {
        $date ??= self::today();
        $c = self::commandeParId($orderId);
        if ($c === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if ($c['status'] !== 'Confirmée') {
            return ['ok' => false, 'reason' => 'not-confirmed'];
        }
        $parTaux = [];
        foreach ($c['lignes'] as $l) {
            if ($l['toInvoice'] > 0) {
                $parTaux[(string) $l['vat_rate']][] = $l;
            }
        }
        if ($parTaux === []) {
            return ['ok' => false, 'reason' => 'nothing'];
        }
        $echeance = gmdate('Y-m-d', (int) strtotime("$date +30 days UTC"));
        $ids = Db::transaction(static function () use ($c, $parTaux, $date, $echeance, $createdBy): array {
            $rang = (int) Db::value('SELECT COUNT(*) FROM invoices WHERE sales_order_id = ? AND company_id = ?', [(int) $c['id'], self::ici()]);
            $ids = [];
            foreach ($parTaux as $taux => $ls) {
                $rang++;
                $montant = 0.0;
                foreach ($ls as $l) {
                    $montant += $l['toInvoice'] * $l['unit_price_ht'];
                }
                $id = (int) Finance::createInvoice([
                    'direction' => 'Client', 'partnerId' => (int) $c['partner_id'], 'reference' => $c['reference'] . '-F' . $rang,
                    'label' => mb_substr('Commande ' . $c['reference'] . ' — ' . implode(', ', array_column($ls, 'label')), 0, 200),
                    'issueDate' => $date, 'dueDate' => $echeance, 'amountHt' => round($montant, 2), 'vatRate' => (float) $taux,
                    'status' => 'Émise', 'notes' => count($parTaux) > 1 ? "TVA $taux % — une facture par taux" : '',
                    'createdBy' => $createdBy, 'currency' => Currency::base(), 'exchangeRate' => 1.0,
                ]);
                Db::run('UPDATE invoices SET sales_order_id = ? WHERE id = ? AND company_id = ?', [(int) $c['id'], $id, self::ici()]);
                foreach ($ls as $l) {
                    Db::run('INSERT INTO sales_invoice_lines (invoice_id, order_line_id, quantity) VALUES (?, ?, ?)', [$id, (int) $l['id'], $l['toInvoice']]);
                }
                $ids[] = $id;
            }
            return $ids;
        });
        return ['ok' => true, 'invoiceIds' => $ids];
    }
}
