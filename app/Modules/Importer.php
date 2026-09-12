<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Csv;
use App\Core\Db;
use App\Core\Security;
use App\Core\Settings;
use App\Core\Validate;

/**
 * Import de données en masse.
 *
 * Reprendre un tableur de deux cents lignes à la main est le moment où l'on
 * renonce à changer d'outil. Trois partis pris rendent l'opération sûre :
 *
 * 1. **Rien n'est écrit avant d'avoir tout vérifié.** L'aperçu contrôle chaque
 *    ligne et nomme le problème avec son numéro de ligne.
 * 2. **Tout ou rien.** Un fichier contenant une seule ligne fautive n'est pas
 *    importé du tout : un import à moitié fait est plus long à rattraper qu'un
 *    fichier à corriger.
 * 3. **Aucune ligne existante n'est modifiée.** L'import crée ; il ne met pas à
 *    jour et n'écrase pas. Un doublon est signalé, jamais silencieusement fondu
 *    dans l'existant.
 */
final class Importer
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    private static function text(mixed $value, int $max): string
    {
        return mb_substr(trim((string) ($value ?? '')), 0, $max);
    }

    private static function amount(string $raw): ?float
    {
        $value = str_replace([' ', ','], ['', '.'], trim($raw));
        return $value === '' || !is_numeric($value) ? null : round((float) $value, 2);
    }

    private static function annualLeaveDays(): float
    {
        $configured = Settings::get('annual_leave_days');
        return is_numeric($configured) ? (float) $configured : (float) Hr::DEFAULT_ANNUAL_LEAVE;
    }

    /** Retrouve un service ou une équipe par son nom, la casse et les espaces en moins. */
    private static function findByName(array $list, mixed $name): ?array
    {
        $wanted = mb_strtolower(trim((string) ($name ?? '')));
        foreach ($list as $row) {
            if (mb_strtolower(trim((string) $row['name'])) === $wanted) {
                return $row;
            }
        }
        return null;
    }

    /** Les types de données importables, avec leurs colonnes et leurs règles. */
    public static function entities(): array
    {
        return [
            [
                'key' => 'membres',
                'label' => 'Membres du personnel',
                'right' => 'admin',
                'module' => null,
                'hint' => "Un compte est créé pour chaque ligne, avec un mot de passe temporaire à remettre à l'intéressé. Les colonnes service et équipe désignent des rattachements existants, par leur nom.",
                'columns' => [
                    ['name' => 'prenom', 'label' => 'Prénom', 'required' => true],
                    ['name' => 'nom', 'label' => 'Nom', 'required' => true],
                    ['name' => 'email', 'label' => 'Adresse email', 'required' => true],
                    ['name' => 'grade', 'label' => 'Grade (' . implode(', ', Users::GRADES) . ')', 'required' => true],
                    ['name' => 'type_contrat', 'label' => 'Type de contrat (' . implode(', ', Users::CONTRACT_TYPES) . ')', 'required' => true],
                    ['name' => 'service', 'label' => 'Service (nom exact)', 'required' => false],
                    ['name' => 'equipe', 'label' => 'Équipe (nom exact)', 'required' => false],
                    ['name' => 'fin_contrat', 'label' => 'Fin de contrat (AAAA-MM-JJ)', 'required' => false],
                    ['name' => 'tjm', 'label' => 'Taux journalier (freelance)', 'required' => false],
                ],
            ],
            [
                'key' => 'tiers',
                'label' => 'Clients et fournisseurs',
                'right' => 'finance',
                'module' => null,
                'hint' => 'Les tiers de la gestion : clients, fournisseurs, ou les deux.',
                'columns' => [
                    ['name' => 'nom', 'label' => 'Raison sociale', 'required' => true],
                    ['name' => 'type', 'label' => 'Type (Client, Fournisseur, Client et fournisseur)', 'required' => true],
                    ['name' => 'identifiant', 'label' => 'SIRET ou numéro de TVA', 'required' => false],
                    ['name' => 'contact', 'label' => 'Personne à contacter', 'required' => false],
                    ['name' => 'email', 'label' => 'Adresse email', 'required' => false],
                    ['name' => 'telephone', 'label' => 'Téléphone', 'required' => false],
                    ['name' => 'adresse', 'label' => 'Adresse', 'required' => false],
                ],
            ],
            [
                'key' => 'articles',
                'label' => 'Articles de stock',
                'right' => 'finance',
                'module' => 'stock',
                'hint' => "Le catalogue d'articles. Les mouvements d'entrée et de sortie se saisissent ensuite depuis l'espace Stock.",
                'columns' => [
                    ['name' => 'libelle', 'label' => 'Libellé', 'required' => true],
                    ['name' => 'reference', 'label' => 'Référence', 'required' => false],
                    ['name' => 'unite', 'label' => 'Unité (unité, kg, litre…)', 'required' => false],
                    ['name' => 'categorie', 'label' => 'Catégorie', 'required' => false],
                    ['name' => 'stock_min', 'label' => "Seuil d'alerte", 'required' => false],
                    ['name' => 'prix_unitaire', 'label' => 'Prix unitaire', 'required' => false],
                ],
            ],
            [
                'key' => 'contacts',
                'label' => 'Contacts commerciaux',
                'right' => 'finance',
                'module' => 'crm',
                'hint' => 'Chaque contact est rattaché à un tiers existant, désigné par sa raison sociale.',
                'columns' => [
                    ['name' => 'tiers', 'label' => 'Tiers (raison sociale exacte)', 'required' => true],
                    ['name' => 'prenom', 'label' => 'Prénom', 'required' => true],
                    ['name' => 'nom', 'label' => 'Nom', 'required' => true],
                    ['name' => 'fonction', 'label' => 'Fonction', 'required' => false],
                    ['name' => 'email', 'label' => 'Adresse email', 'required' => false],
                    ['name' => 'telephone', 'label' => 'Téléphone', 'required' => false],
                ],
            ],
        ];
    }

    public static function byKey(string $key): ?array
    {
        foreach (self::entities() as $entity) {
            if ($entity['key'] === $key) {
                return $entity;
            }
        }
        return null;
    }

    /** Les imports ouverts à cette personne : ses droits, et les modules activés. */
    public static function availableFor(array $user): array
    {
        return array_values(array_filter(self::entities(), static function (array $entity) use ($user): bool {
            if ($entity['module'] !== null && !Catalogue::isEnabled($entity['module'])) {
                return false;
            }
            if ($user['role'] === 'admin') {
                return true;
            }
            if ($entity['right'] === 'admin') {
                return false;
            }
            return $entity['right'] === 'finance' && (int) ($user['is_finance'] ?? 0) === 1;
        }));
    }

    /** Le modèle de fichier : les en-têtes attendus, dans l'ordre. */
    public static function template(array $entity): string
    {
        return implode(';', array_column($entity['columns'], 'name'));
    }

    // ---------- Contrôle, type par type ----------

    private static function prepare(string $key): array
    {
        return match ($key) {
            'membres' => [
                'departments' => Org::departments(),
                'teams' => Org::teams(),
                'emails' => array_map(
                    static fn (array $row): string => mb_strtolower((string) $row['email']),
                    Db::all('SELECT email FROM users')
                ),
            ],
            'tiers' => [
                'names' => array_map(
                    static fn (array $row): string => mb_strtolower((string) $row['name']),
                    Db::all('SELECT name FROM partners')
                ),
            ],
            'articles' => [
                'references' => array_map(
                    static fn (array $row): string => mb_strtolower((string) $row['reference']),
                    Db::all("SELECT reference FROM items WHERE reference != ''")
                ),
            ],
            'contacts' => ['partners' => Db::all('SELECT id, name FROM partners')],
            default => [],
        };
    }

    private static function validate(string $key, array $row, array &$context): array
    {
        return match ($key) {
            'membres' => self::validateMember($row, $context),
            'tiers' => self::validatePartner($row, $context),
            'articles' => self::validateItem($row, $context),
            'contacts' => self::validateContact($row, $context),
            default => ['ok' => false, 'message' => 'Type de données inconnu.'],
        };
    }

    private static function validateMember(array $row, array &$context): array
    {
        $email = mb_strtolower(self::text($row['email'] ?? '', 254));
        $grade = self::text($row['grade'] ?? '', 60);
        $contractType = self::text($row['type_contrat'] ?? '', 40);
        $firstName = self::text($row['prenom'] ?? '', 100);
        $lastName = self::text($row['nom'] ?? '', 100);

        if ($firstName === '' || $lastName === '') {
            return ['ok' => false, 'message' => 'Prénom et nom sont requis.'];
        }
        if (!Validate::email($email)) {
            return ['ok' => false, 'message' => 'Adresse email invalide : ' . ($email !== '' ? $email : '(vide)')];
        }
        if (in_array($email, $context['emails'], true)) {
            return ['ok' => false, 'message' => "Un compte existe déjà avec $email"];
        }
        if (!in_array($grade, Users::GRADES, true)) {
            return ['ok' => false, 'message' => "Grade inconnu : $grade"];
        }
        if (!in_array($contractType, Users::CONTRACT_TYPES, true)) {
            return ['ok' => false, 'message' => "Type de contrat inconnu : $contractType"];
        }

        $department = ($row['service'] ?? '') !== '' ? self::findByName($context['departments'], $row['service']) : null;
        if (($row['service'] ?? '') !== '' && $department === null) {
            return ['ok' => false, 'message' => 'Service introuvable : ' . $row['service']];
        }
        $team = ($row['equipe'] ?? '') !== '' ? self::findByName($context['teams'], $row['equipe']) : null;
        if (($row['equipe'] ?? '') !== '' && $team === null) {
            return ['ok' => false, 'message' => 'Équipe introuvable : ' . $row['equipe']];
        }

        $endDate = self::text($row['fin_contrat'] ?? '', 10);
        if ($endDate !== '' && !Validate::date($endDate)) {
            return ['ok' => false, 'message' => "Date de fin invalide : $endDate"];
        }

        $rate = self::text($row['tjm'] ?? '', 20);
        $dailyRate = $rate === '' ? null : self::amount($rate);
        if ($rate !== '' && ($dailyRate === null || $dailyRate < 0)) {
            return ['ok' => false, 'message' => "Taux journalier invalide : $rate"];
        }

        // Le fichier est vérifié contre lui-même autant que contre la base :
        // deux lignes portant la même adresse doivent se voir avant l'écriture.
        $context['emails'][] = $email;

        return [
            'ok' => true,
            'summary' => "$firstName $lastName · $email · $grade",
            'values' => [
                'firstName' => $firstName, 'lastName' => $lastName, 'email' => $email,
                'grade' => $grade, 'contractType' => $contractType,
                'departmentId' => $department === null ? null : (int) $department['id'],
                'teamId' => $team === null ? null : (int) $team['id'],
                'endDate' => $endDate !== '' ? $endDate : null,
                'dailyRate' => $dailyRate,
            ],
        ];
    }

    private static function validatePartner(array $row, array &$context): array
    {
        $name = self::text($row['nom'] ?? '', 160);
        $kind = self::text($row['type'] ?? '', 40);
        $kinds = ['Client', 'Fournisseur', 'Client et fournisseur'];

        if ($name === '') {
            return ['ok' => false, 'message' => 'La raison sociale est requise.'];
        }
        if (in_array(mb_strtolower($name), $context['names'], true)) {
            return ['ok' => false, 'message' => "Ce tiers existe déjà : $name"];
        }
        if (!in_array($kind, $kinds, true)) {
            return ['ok' => false, 'message' => 'Type inconnu : ' . ($kind !== '' ? $kind : '(vide)')];
        }

        $email = self::text($row['email'] ?? '', 254);
        if ($email !== '' && !Validate::email($email)) {
            return ['ok' => false, 'message' => "Adresse email invalide : $email"];
        }

        $context['names'][] = mb_strtolower($name);
        return [
            'ok' => true,
            'summary' => "$name · $kind",
            'values' => [
                'name' => $name, 'kind' => $kind,
                'registration' => self::text($row['identifiant'] ?? '', 40),
                'contactName' => self::text($row['contact'] ?? '', 120),
                'email' => $email,
                'phone' => self::text($row['telephone'] ?? '', 40),
                'address' => self::text($row['adresse'] ?? '', 300),
            ],
        ];
    }

    private static function validateItem(array $row, array &$context): array
    {
        $label = self::text($row['libelle'] ?? '', 160);
        $reference = self::text($row['reference'] ?? '', 60);

        if ($label === '') {
            return ['ok' => false, 'message' => 'Le libellé est requis.'];
        }
        if ($reference !== '' && in_array(mb_strtolower($reference), $context['references'], true)) {
            return ['ok' => false, 'message' => "Référence déjà utilisée : $reference"];
        }

        $stockMinRaw = self::text($row['stock_min'] ?? '', 20);
        $stockMin = $stockMinRaw === '' ? 0.0 : self::amount($stockMinRaw);
        if ($stockMinRaw !== '' && ($stockMin === null || $stockMin < 0)) {
            return ['ok' => false, 'message' => "Seuil invalide : $stockMinRaw"];
        }

        $priceRaw = self::text($row['prix_unitaire'] ?? '', 20);
        $price = $priceRaw === '' ? null : self::amount($priceRaw);
        if ($priceRaw !== '' && ($price === null || $price < 0)) {
            return ['ok' => false, 'message' => "Prix invalide : $priceRaw"];
        }

        if ($reference !== '') {
            $context['references'][] = mb_strtolower($reference);
        }
        return [
            'ok' => true,
            'summary' => $label . ($reference !== '' ? " ($reference)" : ''),
            'values' => [
                'label' => $label, 'reference' => $reference,
                'unit' => self::text($row['unite'] ?? '', 20) ?: 'unité',
                'category' => self::text($row['categorie'] ?? '', 60),
                'stockMin' => $stockMin ?: 0.0,
                'unitPrice' => $price,
            ],
        ];
    }

    private static function validateContact(array $row, array &$context): array
    {
        $partner = self::findByName($context['partners'], $row['tiers'] ?? '');
        if ($partner === null) {
            return ['ok' => false, 'message' => 'Tiers introuvable : ' . (($row['tiers'] ?? '') !== '' ? $row['tiers'] : '(vide)')];
        }

        $firstName = self::text($row['prenom'] ?? '', 100);
        $lastName = self::text($row['nom'] ?? '', 100);
        if ($firstName === '' || $lastName === '') {
            return ['ok' => false, 'message' => 'Prénom et nom sont requis.'];
        }

        $email = self::text($row['email'] ?? '', 254);
        if ($email !== '' && !Validate::email($email)) {
            return ['ok' => false, 'message' => "Adresse email invalide : $email"];
        }

        return [
            'ok' => true,
            'summary' => "$firstName $lastName · " . $partner['name'],
            'values' => [
                'partnerId' => (int) $partner['id'],
                'firstName' => $firstName, 'lastName' => $lastName,
                'role' => self::text($row['fonction'] ?? '', 120),
                'email' => $email,
                'phone' => self::text($row['telephone'] ?? '', 40),
            ],
        ];
    }

    // ---------- Écriture, type par type ----------

    private static function insert(string $key, array $values): array
    {
        return match ($key) {
            'membres' => self::insertMember($values),
            'tiers' => ['id' => Db::insert(
                'INSERT INTO partners (kind, name, registration, contact_name, email, phone, address)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $values['kind'], $values['name'], $values['registration'], $values['contactName'],
                    $values['email'], $values['phone'], $values['address'],
                ]
            )],
            'articles' => ['id' => Db::insert(
                'INSERT INTO items (reference, label, unit, category, stock_min, unit_price) VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $values['reference'], $values['label'], $values['unit'], $values['category'],
                    $values['stockMin'], $values['unitPrice'],
                ]
            )],
            'contacts' => ['id' => Db::insert(
                'INSERT INTO crm_contacts (partner_id, first_name, last_name, role, email, phone) VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $values['partnerId'], $values['firstName'], $values['lastName'],
                    $values['role'], $values['email'], $values['phone'],
                ]
            )],
            default => ['id' => 0],
        };
    }

    private static function insertMember(array $values): array
    {
        $password = Validate::generatePassword();
        $id = Db::insert(
            "INSERT INTO users (role, email, password_hash, first_name, last_name, grade, contract_type,
                                contract_end_date, daily_rate, leave_balance, active, must_change_password)
             VALUES ('employee', ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)",
            [
                $values['email'], Security::hashPassword($password), $values['firstName'], $values['lastName'],
                $values['grade'], $values['contractType'], $values['endDate'], $values['dailyRate'],
                $values['contractType'] === 'Freelance' ? 0 : self::annualLeaveDays(),
            ]
        );

        Org::assignMembership($id, $values['departmentId'], $values['teamId']);
        // Le mot de passe temporaire est rendu à l'écran une seule fois : il n'est
        // stocké nulle part en clair, pas même dans un journal.
        return ['id' => $id, 'credential' => ['email' => $values['email'], 'password' => $password]];
    }

    // ---------- Aperçu et validation ----------

    /**
     * Contrôle le fichier sans rien écrire. Rend chaque ligne avec son verdict,
     * son numéro de ligne d'origine, et le compte des erreurs.
     */
    public static function preview(string $entityKey, string $content): array
    {
        $entity = self::byKey($entityKey);
        if ($entity === null) {
            return ['ok' => false, 'message' => 'Type de données inconnu.'];
        }

        $parsed = Csv::parse($content);
        if (!$parsed['ok']) {
            return ['ok' => false, 'message' => $parsed['message']];
        }

        $missing = [];
        foreach ($entity['columns'] as $column) {
            if ($column['required'] && !in_array($column['name'], $parsed['headers'], true)) {
                $missing[] = $column['name'];
            }
        }
        if ($missing !== []) {
            return ['ok' => false, 'message' => 'Colonne(s) obligatoire(s) absente(s) : ' . implode(', ', $missing) . '.'];
        }

        $context = self::prepare($entity['key']);
        $rows = [];
        foreach ($parsed['rows'] as $row) {
            $verdict = self::validate($entity['key'], $row, $context);

            // Une ligne refusée doit rester reconnaissable : sans son contenu brut,
            // on ne sait pas laquelle corriger dans le tableur.
            $raw = [];
            foreach ($row as $column => $value) {
                if ($column !== '__line' && $value !== '') {
                    $raw[] = $value;
                }
            }

            $rows[] = [
                'line' => $row['__line'],
                'ok' => $verdict['ok'],
                'message' => $verdict['message'] ?? '',
                'summary' => $verdict['summary'] ?? mb_substr(implode(' · ', $raw), 0, 160),
                'values' => $verdict['values'] ?? null,
            ];
        }

        $errors = array_values(array_filter($rows, static fn (array $row): bool => !$row['ok']));
        return [
            'ok' => true,
            'entity' => ['key' => $entity['key'], 'label' => $entity['label']],
            'delimiter' => $parsed['delimiter'],
            'headers' => $parsed['headers'],
            'rows' => $rows,
            'valid' => count($rows) - count($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Écrit, une fois seulement si tout est bon. Le contrôle est refait ici : entre
     * l'aperçu et la confirmation, la base a pu changer — un compte créé entre-temps
     * ne doit pas passer.
     */
    public static function commit(string $entityKey, string $content): array
    {
        $checked = self::preview($entityKey, $content);
        if (!$checked['ok']) {
            return $checked;
        }
        if ($checked['errors'] !== []) {
            return [
                'ok' => false,
                'message' => count($checked['errors']) . " ligne(s) en erreur : rien n'a été importé.",
                'preview' => $checked,
            ];
        }
        if ($checked['rows'] === []) {
            return ['ok' => false, 'message' => 'Aucune ligne à importer.'];
        }

        $entity = self::byKey($entityKey);
        $results = Db::transaction(static function () use ($checked, $entity): array {
            $written = [];
            foreach ($checked['rows'] as $row) {
                $written[] = array_merge(['line' => $row['line']], self::insert($entity['key'], $row['values']));
            }
            return $written;
        });

        $credentials = [];
        foreach ($results as $result) {
            if (isset($result['credential'])) {
                $credentials[] = $result['credential'];
            }
        }

        return [
            'ok' => true,
            'imported' => count($results),
            'entity' => ['key' => $entity['key'], 'label' => $entity['label']],
            'credentials' => $credentials,
        ];
    }
}
