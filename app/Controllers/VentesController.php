<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validate;
use App\Core\View;
use App\Modules\Ventes;

/**
 * Le cycle de vente : catalogue, commandes, livraisons, facturation — dans le
 * module CRM, sous la même porte que lui.
 */
final class VentesController
{
    private static function vers(string $chemin, string $type, string $message): Response
    {
        Flash::set($type, $message);
        return Response::redirect($chemin);
    }

    private static function crm(string $ancre, string $message): Response
    {
        return self::vers('/crm#' . $ancre, 'error', $message);
    }

    private static function commande(int $id): string
    {
        return '/crm/commandes/' . $id;
    }

    private static function montant(string $brut): ?float
    {
        $v = str_replace([' ', "\u{a0}", "\u{202f}", ','], ['', '', '', '.'], trim($brut));
        return $v === '' || !is_numeric($v) ? null : (float) $v;
    }

    private static function userId(): int
    {
        return (int) Session::get('user')['id'];
    }

    // ---------- Catalogue ----------

    private const MESSAGES_PRODUIT = [
        'reference' => 'Référence invalide : 1 à 40 caractères, lettres, chiffres, point, tiret.',
        'name' => 'Le nom du produit est obligatoire.',
        'kind' => 'Type invalide.',
        'unit' => 'Unité invalide.',
        'price' => 'Prix hors taxes invalide.',
        'vat' => 'Taux de TVA invalide.',
        'item' => 'Article de stock introuvable.',
        'item-service' => 'Seul un bien se lie à un article de stock.',
        'duplicate' => 'Cette référence existe déjà au catalogue.',
    ];

    public static function createProduct(Request $request): Response
    {
        $tva = trim($request->input('vat_rate'));
        $result = Ventes::creerProduit([
            'reference' => $request->input('reference'), 'name' => $request->input('name'),
            'description' => $request->input('description'), 'kind' => $request->input('kind'), 'unit' => $request->input('unit'),
            'priceHt' => self::montant($request->input('price_ht')), 'vatRate' => is_numeric($tva) ? (float) $tva : null,
            'itemId' => (int) $request->input('item_id'),
        ]);
        if (!$result['ok']) {
            return self::crm('catalogue', self::MESSAGES_PRODUIT[$result['reason']] ?? 'Produit refusé.');
        }
        return self::vers('/crm#catalogue', 'success', 'Produit ajouté au catalogue.');
    }

    public static function toggleProduct(Request $request, array $params): Response
    {
        return Ventes::basculerProduit((int) $params['id'])
            ? self::vers('/crm#catalogue', 'success', 'Produit mis à jour.')
            : self::crm('catalogue', 'Produit introuvable.');
    }

    public static function deleteProduct(Request $request, array $params): Response
    {
        $result = Ventes::supprimerProduit((int) $params['id']);
        if (!$result['ok']) {
            return self::crm('catalogue', 'Produit introuvable.');
        }
        return self::vers('/crm#catalogue', 'success', $result['deactivated']
            ? 'Produit déjà commandé : il a été retiré du catalogue plutôt que supprimé.'
            : 'Produit supprimé.');
    }

    // ---------- Commandes ----------

    public static function createOrder(Request $request): Response
    {
        $date = trim($request->input('order_date')) ?: gmdate('Y-m-d');
        if (!Validate::date($date)) {
            return self::crm('commandes', 'Date de commande invalide.');
        }
        $result = Ventes::creerCommande((int) $request->input('partner_id'), $date, trim($request->input('notes')), null, self::userId());
        if (!$result['ok']) {
            return self::crm('commandes', 'Client introuvable.');
        }
        return self::vers(self::commande((int) $result['id']), 'success',
            'Commande ' . $result['reference'] . ' créée : ajoutez-y ses lignes, puis confirmez-la.');
    }

    public static function orderFromQuote(Request $request, array $params): Response
    {
        $result = Ventes::depuisDevis((int) $params['id'], self::userId());
        if (!$result['ok']) {
            return self::crm('devis', match ($result['reason']) {
                'not-found' => 'Devis introuvable.',
                'not-accepted' => 'Seul un devis accepté devient une commande.',
                'already-invoiced' => 'Ce devis a déjà été facturé directement.',
                'already-ordered' => 'Ce devis est déjà passé en commande.',
                default => 'Commande impossible.',
            });
        }
        return self::vers(self::commande((int) $result['id']), 'success', 'Commande ' . $result['reference'] . ' créée depuis le devis.');
    }

    public static function show(Request $request, array $params): Response
    {
        $commande = Ventes::commandeParId((int) $params['id']);
        if ($commande === null) {
            return self::crm('commandes', 'Commande introuvable.');
        }
        return Response::html(View::page('crm/commande', [
            'title' => $commande['reference'] . ' — ' . t('app.name'),
            'panelLabel' => 'CRM commercial',
            'headerTitle' => t('ven.order') . ' ' . $commande['reference'],
            'headerSubtitle' => $commande['partner_name'],
            'navItems' => [['href' => '/crm#commandes', 'label' => t('ven.orders')]],
            'footLinks' => [['href' => '/gestion', 'label' => t('nav.gestion')]],
            'scripts' => ['/js/confirm.js'],
            'commande' => $commande,
            'produits' => Ventes::produits(true),
            'today' => gmdate('Y-m-d'),
        ]));
    }

    public static function addLine(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $q = str_replace(',', '.', trim($request->input('quantity')));
        $prix = trim($request->input('unit_price_ht'));
        $tva = trim($request->input('vat_rate'));
        $result = Ventes::ajouterLigne(
            $id,
            (int) $request->input('product_id') ?: null,
            trim($request->input('label')),
            is_numeric($q) ? (float) $q : NAN,
            $prix === '' ? null : (self::montant($prix) ?? NAN),
            $tva === '' ? null : (is_numeric($tva) ? (float) $tva : NAN)
        );
        if (!$result['ok']) {
            $message = match ($result['reason']) {
                'not-found' => 'Commande introuvable.',
                'not-draft' => 'Une commande confirmée ne change plus de lignes.',
                'product' => 'Produit introuvable ou retiré du catalogue.',
                'label' => 'Choisissez un produit ou écrivez un libellé.',
                'quantity' => 'Quantité invalide.',
                'price' => 'Prix unitaire invalide : choisissez un produit ou donnez un prix.',
                'vat' => 'Taux de TVA invalide.',
                default => 'Ligne refusée.',
            };
            return $result['reason'] === 'not-found' ? self::crm('commandes', $message) : self::vers(self::commande($id), 'error', $message);
        }
        return self::vers(self::commande($id), 'success', 'Ligne ajoutée.');
    }

    public static function removeLine(Request $request, array $params): Response
    {
        $result = Ventes::retirerLigne((int) $params['id']);
        if (!$result['ok']) {
            return self::crm('commandes', $result['reason'] === 'not-draft'
                ? 'Une commande confirmée ne change plus de lignes.' : 'Ligne introuvable.');
        }
        return self::vers(self::commande($result['orderId']), 'success', 'Ligne retirée.');
    }

    public static function confirm(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $result = Ventes::confirmer($id);
        if (!$result['ok']) {
            $message = match ($result['reason']) {
                'not-found' => 'Commande introuvable.',
                'not-draft' => 'Cette commande est déjà confirmée ou annulée.',
                default => 'Une commande sans ligne ne se confirme pas.',
            };
            return $result['reason'] === 'not-found' ? self::crm('commandes', $message) : self::vers(self::commande($id), 'error', $message);
        }
        return self::vers(self::commande($id), 'success', 'Commande confirmée : elle peut être livrée.');
    }

    public static function cancel(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $result = Ventes::annuler($id);
        if (!$result['ok']) {
            $message = match ($result['reason']) {
                'not-found' => 'Commande introuvable.',
                'already' => 'Cette commande est déjà annulée.',
                default => "Une commande déjà livrée ou facturée ne s'annule plus.",
            };
            return $result['reason'] === 'not-found' ? self::crm('commandes', $message) : self::vers(self::commande($id), 'error', $message);
        }
        return self::vers(self::commande($id), 'success', 'Commande annulée.');
    }

    public static function deliver(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $quantites = [];
        foreach ($request->body as $cle => $valeur) {
            if (is_string($cle) && preg_match('/^q_(\d+)$/', $cle, $m) === 1 && is_scalar($valeur)) {
                $quantites[(int) $m[1]] = (string) $valeur;
            }
        }
        $date = trim($request->input('delivered_on')) ?: gmdate('Y-m-d');
        $result = Ventes::livrer($id, $quantites, $date, self::userId());
        if (!$result['ok']) {
            $message = match ($result['reason']) {
                'not-found' => 'Commande introuvable.',
                'not-confirmed' => 'Seule une commande confirmée se livre.',
                'date' => "Date de livraison invalide : au plus tard aujourd'hui.",
                'quantity' => 'Quantité invalide sur « ' . $result['line'] . ' ».',
                'too-much' => '« ' . $result['line'] . ' » : il ne reste que ' . $result['remaining'] . ' à livrer.',
                'nothing' => 'Indiquez au moins une quantité à livrer.',
                'stock' => 'Stock insuffisant pour « ' . $result['item'] . ' »'
                    . (isset($result['stock']) ? ' (' . $result['stock'] . ' en stock)' : '') . " : rien n'a été livré.",
                default => 'Livraison refusée.',
            };
            return $result['reason'] === 'not-found' ? self::crm('commandes', $message) : self::vers(self::commande($id), 'error', $message);
        }
        return self::vers(self::commande($id), 'success', 'Bon de livraison ' . $result['reference'] . ' établi.');
    }

    public static function invoice(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $result = Ventes::facturer($id, null, self::userId());
        if (!$result['ok']) {
            $message = match ($result['reason']) {
                'not-found' => 'Commande introuvable.',
                'not-confirmed' => 'Seule une commande confirmée se facture.',
                default => 'Rien de livré qui ne soit déjà facturé.',
            };
            return $result['reason'] === 'not-found' ? self::crm('commandes', $message) : self::vers(self::commande($id), 'error', $message);
        }
        return self::vers(self::commande($id), 'success', count($result['invoiceIds']) . ' facture(s) émise(s) : elles sont dans la gestion.');
    }

    public static function deliveryNote(Request $request, array $params): Response
    {
        $bon = Ventes::bonDeLivraison((int) $params['id']);
        if ($bon === null) {
            return self::crm('commandes', 'Bon de livraison introuvable.');
        }
        return Response::html(View::page('crm/bon-livraison', [
            'title' => $bon['reference'] . ' — ' . t('app.name'),
            'panelLabel' => 'CRM commercial',
            'headerTitle' => t('ven.deliveryNote') . ' ' . $bon['reference'],
            'headerSubtitle' => $bon['partner_name'],
            'navItems' => [['href' => '/crm/commandes/' . (int) $bon['order_id'], 'label' => $bon['order_reference']]],
            'footLinks' => [['href' => '/crm#commandes', 'label' => t('ven.orders')]],
            'bon' => $bon,
        ]));
    }
}
