<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Sondages internes et baromètre social.
 *
 * Un baromètre qui n'est pas anonyme ne mesure rien : il mesure ce que les gens
 * acceptent de dire à leur employeur. L'anonymat est donc tenu par la structure
 * des tables, pas par une promesse — les réponses ne portent aucun identifiant
 * de personne, et la participation, elle nominative, ne dit que « a répondu ».
 * Une base saisie ne peut pas rendre ce qu'elle ne contient pas.
 *
 * Second garde-fou : sous un certain nombre de réponses, les résultats ne sont
 * pas affichés. Dans une équipe de trois, une moyenne suffit à désigner
 * quelqu'un.
 */
final class Surveys
{
    public const KINDS = ['Baromètre social', 'Enquête', 'Vote consultatif', "Retour d'expérience"];
    public const STATUSES = ['Brouillon', 'Ouvert', 'Clos'];
    public const QUESTION_TYPES = [
        ['key' => 'echelle', 'label' => 'Échelle de 1 à 5'],
        ['key' => 'oui_non', 'label' => 'Oui / Non'],
        ['key' => 'choix', 'label' => 'Choix multiple'],
        ['key' => 'texte', 'label' => 'Réponse libre'],
    ];
    public const AUDIENCES = ['Tous', 'Service', 'Équipe'];

    // En deçà, un résultat désigne quelqu'un plutôt qu'il ne décrit un groupe.
    public const ANONYMITY_THRESHOLD = 5;
    public const SCALE = [1, 2, 3, 4, 5];

    // ---------- Questionnaires ----------

    public static function list(?string $status = null): array
    {
        $clause = $status !== null ? 'WHERE s.status = ?' : '';
        $params = $status !== null ? [$status] : [];

        return Db::all(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM survey_questions q WHERE q.survey_id = s.id) AS question_count,
                    (SELECT COUNT(*) FROM survey_participations p WHERE p.survey_id = s.id) AS answer_count
             FROM surveys s $clause ORDER BY s.created_at DESC",
            $params
        );
    }

    public static function byId(int $id): ?array
    {
        return Db::get('SELECT * FROM surveys WHERE id = ?', [$id]);
    }

    public static function create(array $fields): int
    {
        return Db::insert(
            'INSERT INTO surveys (title, intro, kind, audience, audience_id, opens_on, closes_on, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['title'], $fields['intro'] ?? '', $fields['kind'], $fields['audience'],
                $fields['audienceId'] ?? null, $fields['opensOn'] ?? null, $fields['closesOn'] ?? null,
                $fields['createdBy'] ?? null,
            ]
        );
    }

    public static function questions(int $surveyId): array
    {
        return array_map(static function (array $question): array {
            $question['choiceList'] = $question['choices'] === ''
                ? []
                : array_values(array_filter(explode('|', (string) $question['choices'])));
            return $question;
        }, Db::all('SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY position, id', [$surveyId]));
    }

    public static function addQuestion(int $surveyId, array $fields): int
    {
        $next = (int) Db::value(
            'SELECT COALESCE(MAX(position), 0) + 1 FROM survey_questions WHERE survey_id = ?',
            [$surveyId]
        );
        return Db::insert(
            'INSERT INTO survey_questions (survey_id, position, label, type, choices, required)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $surveyId, $next, $fields['label'], $fields['type'],
                implode('|', $fields['choices'] ?? []), !empty($fields['required']) ? 1 : 0,
            ]
        );
    }

    public static function removeQuestion(int $surveyId, int $questionId): void
    {
        Db::run('DELETE FROM survey_questions WHERE id = ? AND survey_id = ?', [$questionId, $surveyId]);
    }

    /**
     * Ouvrir fige le questionnaire : modifier les questions après les premières
     * réponses rendrait les résultats incomparables entre eux.
     */
    public static function open(int $id): array
    {
        $survey = self::byId($id);
        if ($survey === null || $survey['status'] !== 'Brouillon') {
            return ['ok' => false, 'message' => 'Ce sondage ne peut plus être ouvert.'];
        }
        if (self::questions($id) === []) {
            return ['ok' => false, 'message' => "Ajoutez au moins une question avant d'ouvrir le sondage."];
        }

        Db::run("UPDATE surveys SET status = 'Ouvert' WHERE id = ?", [$id]);
        return ['ok' => true];
    }

    public static function close(int $id): bool
    {
        return Db::run("UPDATE surveys SET status = 'Clos' WHERE id = ? AND status = 'Ouvert'", [$id]) > 0;
    }

    public static function remove(int $id): void
    {
        Db::run('DELETE FROM surveys WHERE id = ?', [$id]);
    }

    // ---------- Participation ----------

    /** Qui est convié : tout le monde, un service, ou une équipe. */
    public static function audienceUsers(array $survey): array
    {
        if ($survey['audience'] === 'Service' && !empty($survey['audience_id'])) {
            return array_values(array_filter(
                Org::membersOfDepartment((int) $survey['audience_id']),
                static fn (array $u): bool => (int) $u['active'] === 1
            ));
        }
        if ($survey['audience'] === 'Équipe' && !empty($survey['audience_id'])) {
            return Db::all(
                "SELECT * FROM users WHERE active = 1 AND team_id = ? AND role = 'employee'",
                [(int) $survey['audience_id']]
            );
        }
        return Db::all("SELECT * FROM users WHERE active = 1 AND role = 'employee'");
    }

    public static function isInvited(array $survey, int $userId): bool
    {
        foreach (self::audienceUsers($survey) as $user) {
            if ((int) $user['id'] === $userId) {
                return true;
            }
        }
        return false;
    }

    public static function hasAnswered(int $surveyId, int $userId): bool
    {
        return Db::get(
            'SELECT id FROM survey_participations WHERE survey_id = ? AND user_id = ?',
            [$surveyId, $userId]
        ) !== null;
    }

    /**
     * Le compte des sondages en attente pour une personne, en une requête : la
     * navigation l'affiche à chaque page, elle ne peut pas se permettre de
     * recalculer une population entière à chaque fois.
     */
    public static function pendingCountFor(int $userId): int
    {
        return (int) Db::value(
            "SELECT COUNT(*) FROM surveys s
             WHERE s.status = 'Ouvert'
               AND (s.closes_on IS NULL OR s.closes_on >= date('now'))
               AND (
                 s.audience = 'Tous'
                 OR (s.audience = 'Service' AND s.audience_id = (SELECT department_id FROM users WHERE id = ?))
                 OR (s.audience = 'Équipe' AND s.audience_id = (SELECT team_id FROM users WHERE id = ?))
               )
               AND NOT EXISTS (SELECT 1 FROM survey_participations p WHERE p.survey_id = s.id AND p.user_id = ?)",
            [$userId, $userId, $userId]
        );
    }

    /** Les sondages ouverts qu'une personne peut encore remplir. */
    public static function openFor(int $userId): array
    {
        $today = gmdate('Y-m-d');
        return array_values(array_filter(
            self::list('Ouvert'),
            static fn (array $s): bool => self::isInvited($s, $userId)
                && !self::hasAnswered((int) $s['id'], $userId)
                && (empty($s['closes_on']) || $s['closes_on'] >= $today)
        ));
    }

    /**
     * Enregistre une participation. Les deux écritures tiennent dans une seule
     * transaction : sans elle, un plantage entre les deux laisserait soit un vote
     * fantôme, soit la possibilité de voter deux fois.
     */
    public static function submit(int $surveyId, int $userId, array $values): array
    {
        $survey = self::byId($surveyId);
        if ($survey === null || $survey['status'] !== 'Ouvert') {
            return ['ok' => false, 'message' => "Ce sondage n'est pas ouvert."];
        }
        if (!self::isInvited($survey, $userId)) {
            return ['ok' => false, 'message' => 'Ce sondage ne vous est pas destiné.'];
        }
        if (self::hasAnswered($surveyId, $userId)) {
            return ['ok' => false, 'message' => 'Vous avez déjà répondu à ce sondage.'];
        }

        $entries = [];
        foreach (self::questions($surveyId) as $question) {
            $raw = trim((string) ($values['q_' . (int) $question['id']] ?? ''));
            if ($raw === '') {
                if ((int) $question['required'] === 1) {
                    return ['ok' => false, 'message' => 'Question sans réponse : ' . $question['label']];
                }
                continue;
            }
            if ($question['type'] === 'echelle' && !in_array((int) $raw, self::SCALE, true)) {
                return ['ok' => false, 'message' => "Valeur hors de l'échelle."];
            }
            if ($question['type'] === 'oui_non' && !in_array($raw, ['Oui', 'Non'], true)) {
                return ['ok' => false, 'message' => 'Réponse attendue : Oui ou Non.'];
            }
            if ($question['type'] === 'choix' && !in_array($raw, $question['choiceList'], true)) {
                return ['ok' => false, 'message' => 'Choix inconnu.'];
            }
            $entries[] = ['questionId' => (int) $question['id'], 'value' => mb_substr($raw, 0, 2000)];
        }

        try {
            Db::transaction(static function () use ($surveyId, $userId, $entries): void {
                Db::insert('INSERT INTO survey_participations (survey_id, user_id) VALUES (?, ?)', [$surveyId, $userId]);
                foreach ($entries as $entry) {
                    Db::insert('INSERT INTO survey_answers (question_id, value) VALUES (?, ?)', [$entry['questionId'], $entry['value']]);
                }
            });
        } catch (\Throwable) {
            // Course entre deux envois simultanés : l'index unique tranche.
            return ['ok' => false, 'message' => 'Vous avez déjà répondu à ce sondage.'];
        }
        return ['ok' => true];
    }

    // ---------- Résultats ----------

    public static function participationCount(int $surveyId): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM survey_participations WHERE survey_id = ?', [$surveyId]);
    }

    /**
     * Les résultats agrégés. Sous le seuil d'anonymat, rien n'est rendu : ni
     * moyenne, ni verbatim. Le refus est explicite plutôt que silencieux, sinon on
     * croirait le sondage vide.
     */
    public static function results(int $surveyId): ?array
    {
        $survey = self::byId($surveyId);
        if ($survey === null) {
            return null;
        }

        $invited = count(self::audienceUsers($survey));
        $answered = self::participationCount($surveyId);
        $withheld = $answered < self::ANONYMITY_THRESHOLD;

        $rows = array_map(static function (array $question) use ($withheld): array {
            $values = $withheld ? [] : array_map(
                static fn (array $row): string => (string) $row['value'],
                Db::all('SELECT value FROM survey_answers WHERE question_id = ?', [(int) $question['id']])
            );
            $question['count'] = count($values);

            if ($question['type'] === 'echelle') {
                $numbers = array_values(array_filter(
                    array_map('intval', $values),
                    static fn (int $n): bool => in_array($n, self::SCALE, true)
                ));
                $question['distribution'] = array_map(
                    static fn (int $n): array => [
                        'value' => $n,
                        'count' => count(array_filter($numbers, static fn (int $x): bool => $x === $n)),
                    ],
                    self::SCALE
                );
                $question['average'] = $numbers === [] ? null : round(array_sum($numbers) / count($numbers), 2);
                return $question;
            }
            if ($question['type'] === 'oui_non') {
                $question['distribution'] = array_map(
                    static fn (string $v): array => [
                        'value' => $v,
                        'count' => count(array_filter($values, static fn (string $x): bool => $x === $v)),
                    ],
                    ['Oui', 'Non']
                );
                return $question;
            }
            if ($question['type'] === 'choix') {
                $question['distribution'] = array_map(
                    static fn (string $v): array => [
                        'value' => $v,
                        'count' => count(array_filter($values, static fn (string $x): bool => $x === $v)),
                    ],
                    $question['choiceList']
                );
                return $question;
            }
            $question['verbatims'] = $values;
            return $question;
        }, self::questions($surveyId));

        return [
            'survey' => $survey,
            'invited' => $invited,
            'answered' => $answered,
            'rate' => $invited ? (int) round($answered / $invited * 100) : 0,
            'withheld' => $withheld,
            'threshold' => self::ANONYMITY_THRESHOLD,
            'questions' => $rows,
        ];
    }

    /** L'indice du baromètre : la moyenne des échelles de tous les sondages clos. */
    public static function barometer(): array
    {
        $points = [];
        foreach (self::list('Clos') as $survey) {
            if ($survey['kind'] !== 'Baromètre social') {
                continue;
            }
            $result = self::results((int) $survey['id']);
            if ($result === null || $result['withheld']) {
                continue;
            }
            $scales = array_values(array_filter(
                $result['questions'],
                static fn (array $q): bool => $q['type'] === 'echelle' && ($q['average'] ?? null) !== null
            ));
            if ($scales === []) {
                continue;
            }
            $points[] = [
                'id' => (int) $survey['id'],
                'title' => $survey['title'],
                'closedOn' => $survey['closes_on'] ?: substr((string) $survey['created_at'], 0, 10),
                'score' => round(array_sum(array_column($scales, 'average')) / count($scales), 2),
                'answered' => $result['answered'],
                'rate' => $result['rate'],
            ];
        }
        usort($points, static fn (array $a, array $b): int => strcmp($a['closedOn'], $b['closedOn']));
        return $points;
    }
}
