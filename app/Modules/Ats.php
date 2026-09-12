<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Filtrage des candidatures : critères pondérés, score, CVthèque.
 *
 * Le score est une aide au tri, jamais une décision : les critères requis
 * manquants sont énoncés à part plutôt que noyés dans une note, et un dossier
 * sans CV vaut zéro sans rien laisser croire d'autre.
 */
final class Ats
{
    public const CRITERION_KINDS = ['Requis', 'Souhaité'];
    public const MAX_WEIGHT = 5;

    private const YEAR_PATTERNS = [
        '/(\d{1,2})\s*(?:\+)?\s*(?:ans?|annees?)\s*(?:d\s*)?(?:experience|exp)/',
        '/experience\s*(?:de|:)?\s*(\d{1,2})\s*(?:ans?|annees?)/',
    ];

    /**
     * Normalisation : minuscules, accents retirés, ponctuation ramenée à des espaces.
     * « Développeur back-end » et « developpeur backend » doivent se rencontrer.
     */
    public static function normalize(?string $text): string
    {
        $value = mb_strtolower((string) $text, 'UTF-8');
        $value = self::stripAccents($value);
        $value = (string) preg_replace('/[^a-z0-9+#.]+/u', ' ', $value);
        // Le point n'est gardé que s'il lie deux morceaux d'un même terme (node.js) ;
        // un point de fin de phrase se collerait sinon au mot et le rendrait introuvable.
        $value = (string) preg_replace('/\.(?![a-z0-9])/', ' ', $value);
        $value = (string) preg_replace('/\s+/', ' ', $value);
        return trim($value);
    }

    /**
     * Les accents sont retirés lettre à lettre plutôt que par iconv : une
     * translittération dépend de la locale du serveur, et selon la machine
     * « d'expérience » devenait « dexperience » ou « d'experience ». Une table
     * explicite rend le même texte partout.
     */
    private static function stripAccents(string $value): string
    {
        return strtr($value, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae',
            'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'œ' => 'oe',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
        ]);
    }

    /** Les synonymes d'un critère : son intitulé, plus les mots-clés saisis. */
    public static function termsOf(array $criterion): array
    {
        $raw = array_merge([$criterion['label']], explode(',', (string) ($criterion['keywords'] ?? '')));
        $terms = [];
        foreach ($raw as $item) {
            $normalized = self::normalize($item);
            if ($normalized !== '' && !in_array($normalized, $terms, true)) {
                $terms[] = $normalized;
            }
        }
        return $terms;
    }

    /**
     * Un terme est trouvé s'il apparaît en frontière de mot : « java » ne doit pas
     * se déclencher sur « javascript », et « api » pas sur « rapide ».
     */
    public static function contains(string $haystack, string $term): bool
    {
        if ($term === '') {
            return false;
        }
        return (bool) preg_match('/(^| )' . preg_quote($term, '/') . '( |$)/', $haystack);
    }

    /** Années d'expérience annoncées dans le CV, quand la phrase est explicite. */
    public static function detectExperience(?string $cvText): ?int
    {
        $text = self::normalize($cvText);
        foreach (self::YEAR_PATTERNS as $pattern) {
            if (preg_match($pattern, $text, $match)) {
                $years = (int) $match[1];
                if ($years >= 0 && $years <= 60) {
                    return $years;
                }
            }
        }
        return null;
    }

    // ---------- Critères ----------

    public static function criteriaOf(int $openingId): array
    {
        return Db::all('SELECT * FROM opening_criteria WHERE opening_id = ? ORDER BY kind, id', [$openingId]);
    }

    public static function createCriterion(array $fields): array
    {
        $kind = $fields['kind'] ?? '';
        $weight = $fields['weight'] ?? 0;
        if (!in_array($kind, self::CRITERION_KINDS, true)) {
            return ['ok' => false, 'reason' => 'bad-kind'];
        }
        if (!is_int($weight) || $weight < 1 || $weight > self::MAX_WEIGHT) {
            return ['ok' => false, 'reason' => 'bad-weight'];
        }
        if (Db::get('SELECT id FROM job_openings WHERE id = ?', [(int) $fields['openingId']]) === null) {
            return ['ok' => false, 'reason' => 'no-opening'];
        }

        Db::insert(
            'INSERT INTO opening_criteria (opening_id, label, keywords, kind, weight) VALUES (?, ?, ?, ?, ?)',
            [(int) $fields['openingId'], $fields['label'], $fields['keywords'] ?? '', $kind, $weight]
        );
        return ['ok' => true];
    }

    public static function deleteCriterion(int $id): ?int
    {
        $criterion = Db::get('SELECT * FROM opening_criteria WHERE id = ?', [$id]);
        if ($criterion === null) {
            return null;
        }
        Db::run('DELETE FROM opening_criteria WHERE id = ?', [$id]);
        return (int) $criterion['opening_id'];
    }

    public static function setOpeningAts(int $openingId, float $minExperience, int $threshold): array
    {
        if ($minExperience < 0 || $minExperience > 60) {
            return ['ok' => false, 'reason' => 'bad-experience'];
        }
        if ($threshold < 0 || $threshold > 100) {
            return ['ok' => false, 'reason' => 'bad-threshold'];
        }

        Db::run(
            'UPDATE job_openings SET min_experience = ?, ats_threshold = ? WHERE id = ?',
            [$minExperience, $threshold, $openingId]
        );
        return ['ok' => true];
    }

    // ---------- Évaluation ----------

    /**
     * Score pondéré : chaque critère satisfait apporte son poids, rapporté au total
     * des poids. Le score reste une aide au tri — il ne décide de rien tout seul,
     * et les critères requis manquants sont énoncés à part plutôt que noyés dedans.
     */
    public static function evaluate(array $candidate, array $opening, array $criteria): array
    {
        $cvText = self::normalize($candidate['cv_text'] ?? '');
        $hasCv = $cvText !== '';

        $rows = [];
        foreach ($criteria as $criterion) {
            $matched = null;
            foreach (self::termsOf($criterion) as $term) {
                if (self::contains($cvText, $term)) {
                    $matched = $term;
                    break;
                }
            }
            $rows[] = [
                'id' => (int) $criterion['id'],
                'label' => $criterion['label'],
                'kind' => $criterion['kind'],
                'weight' => (int) $criterion['weight'],
                'matched' => $matched !== null,
                'matchedOn' => $matched,
            ];
        }

        $totalWeight = array_sum(array_column($rows, 'weight'));
        $gained = 0;
        foreach ($rows as $row) {
            if ($row['matched']) {
                $gained += $row['weight'];
            }
        }
        $score = $totalWeight > 0 ? (int) round($gained / $totalWeight * 100) : 0;

        $missingRequired = array_values(array_map(
            static fn (array $row): string => $row['label'],
            array_filter($rows, static fn (array $row): bool => $row['kind'] === 'Requis' && !$row['matched'])
        ));

        $declared = $candidate['experience_years'] ?? null;
        $detected = self::detectExperience($candidate['cv_text'] ?? '');
        $years = $declared !== null ? (int) $declared : $detected;
        $minExperience = (float) ($opening['min_experience'] ?? 0);
        $experienceShort = $minExperience > 0 && $years !== null && $years < $minExperience;

        return [
            'score' => $hasCv ? $score : 0,
            'hasCv' => $hasCv,
            'rows' => $rows,
            'totalWeight' => $totalWeight,
            'gained' => $gained,
            'missingRequired' => $missingRequired,
            'years' => $years,
            'yearsSource' => $declared !== null ? 'déclarée' : ($detected !== null ? 'détectée dans le CV' : null),
            'experienceShort' => $experienceShort,
            // Un dossier n'est retenu que s'il a un CV, dépasse le seuil, ne manque
            // aucun critère requis et satisfait l'expérience minimale.
            'shortlisted' => $hasCv
                && $score >= (int) ($opening['ats_threshold'] ?? 0)
                && $missingRequired === []
                && !$experienceShort,
        ];
    }

    /** Recalcule et mémorise le score d'une candidature. */
    public static function rescoreCandidate(int $candidateId): ?array
    {
        $candidate = Db::get('SELECT * FROM candidates WHERE id = ?', [$candidateId]);
        if ($candidate === null) {
            return null;
        }
        $opening = Db::get('SELECT * FROM job_openings WHERE id = ?', [(int) $candidate['opening_id']]);
        if ($opening === null) {
            return null;
        }

        $result = self::evaluate($candidate, $opening, self::criteriaOf((int) $opening['id']));
        Db::run(
            'UPDATE candidates SET ats_score = ?, ats_detail = ? WHERE id = ?',
            [$result['score'], json_encode($result, JSON_UNESCAPED_UNICODE), $candidateId]
        );
        return $result;
    }

    /** Un critère qui change périme tous les scores du poste : on les refait. */
    public static function rescoreOpening(int $openingId): int
    {
        $ids = Db::all('SELECT id FROM candidates WHERE opening_id = ?', [$openingId]);
        foreach ($ids as $row) {
            self::rescoreCandidate((int) $row['id']);
        }
        return count($ids);
    }

    /** Les candidatures d'un poste, du meilleur score au moins bon. */
    public static function rankedCandidates(int $openingId): array
    {
        $opening = Db::get('SELECT * FROM job_openings WHERE id = ?', [$openingId]);
        if ($opening === null) {
            return [];
        }
        $criteria = self::criteriaOf($openingId);

        $rows = array_map(
            static function (array $candidate) use ($opening, $criteria): array {
                $candidate['ats'] = self::evaluate($candidate, $opening, $criteria);
                return $candidate;
            },
            Db::all('SELECT * FROM candidates WHERE opening_id = ? ORDER BY created_at DESC', [$openingId])
        );

        usort($rows, static fn (array $a, array $b): int =>
            $b['ats']['score'] <=> $a['ats']['score'] ?: strcmp($a['last_name'], $b['last_name']));
        return $rows;
    }

    /**
     * CVthèque : recherche plein texte sur tous les CV reçus, tous postes confondus.
     * Un bon profil arrivé sur un autre poste ne doit pas être perdu.
     */
    public static function searchCvs(string $query, int $limit = 50): array
    {
        $terms = array_values(array_filter(explode(' ', self::normalize($query))));
        if ($terms === []) {
            return [];
        }

        $rows = Db::all(
            "SELECT c.*, o.title AS opening_title, o.status AS opening_status
             FROM candidates c JOIN job_openings o ON o.id = c.opening_id
             WHERE c.cv_text != ''
             ORDER BY c.created_at DESC"
        );

        $matched = [];
        foreach ($rows as $row) {
            $haystack = self::normalize($row['first_name'] . ' ' . $row['last_name'] . ' ' . $row['cv_text']);
            $hits = array_values(array_filter($terms, static fn (string $term): bool => self::contains($haystack, $term)));
            if ($hits === []) {
                continue;
            }
            $row['hits'] = $hits;
            $row['matchedAll'] = count($hits) === count($terms);
            $matched[] = $row;
        }

        usort($matched, static fn (array $a, array $b): int => count($b['hits']) <=> count($a['hits']));
        return array_slice($matched, 0, $limit);
    }
}
