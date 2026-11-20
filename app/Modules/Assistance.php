<?php

declare(strict_types=1);

namespace App\Modules;

/**
 * Ce que l'assistance sait faire, et ce qu'elle ne fait pas.
 *
 * Le pendant de « src/assistance.js » de l'édition Node. Trois verbes, pas plus :
 * résumer un fil, en proposer un brouillon de réponse, et relever dans des notes
 * ce qui ressemble à une décision ou à une action. Chacun compose son texte, passe
 * par « Ai::demander » — qui tient la porte de confidentialité et le journal — et
 * rend une proposition.
 *
 * Une proposition, jamais une écriture. Rien ici ne touche à la base : le résumé
 * s'affiche, le brouillon s'ouvre dans le champ de saisie, les décisions et les
 * actions relevées attendent qu'une personne les accepte une par une. Ce n'est pas
 * de la prudence d'ingénieur, c'est la règle : sur une personne — qui porte quoi,
 * qui répond quoi —, une machine ne décide pas.
 *
 * Aucune méthode ne lève. Le service peut être éteint, injoignable, en panne :
 * l'écran continue de marcher sans elle.
 */
final class Assistance
{
    public const MAX_ECHANGES = 60;

    // ---------- Composition du texte envoyé ----------

    /** Un échange, rendu comme on le lirait. */
    private static function ligneEchange(array $m): string
    {
        $auteur = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
        if ($auteur === '') {
            $auteur = 'Compte supprimé';
        }
        $quand = mb_substr((string) ($m['created_at'] ?? ''), 0, 16);
        $interne = !empty($m['internal']) ? ' (note interne)' : '';
        return $quand . ' — ' . $auteur . $interne . " :\n" . trim((string) ($m['body'] ?? ''));
    }

    /**
     * Un fil sans un mot n'est pas un fil : il n'y a rien à résumer, et rien à
     * envoyer.
     *
     * « demande » compte autant que les échanges, et c'est un test qui l'a
     * rappelé : la demande d'origine est portée par le ticket lui-même, pas par un
     * premier message. Un ticket qu'on vient d'ouvrir a donc un texte et zéro
     * échange — et c'est précisément celui qu'on veut résumer.
     */
    public static function filVide(array $fil): bool
    {
        if (trim((string) ($fil['demande'] ?? '')) !== '') {
            return false;
        }
        foreach ($fil['echanges'] ?? [] as $m) {
            if (trim((string) ($m['body'] ?? '')) !== '') {
                return false;
            }
        }
        return true;
    }

    public static function filEnTexte(array $fil): string
    {
        $echanges = array_slice($fil['echanges'] ?? [], -self::MAX_ECHANGES);
        $lignes = [
            'Demande : ' . (string) ($fil['titre'] ?? ''),
            ($fil['categorie'] ?? '') !== '' ? 'Catégorie : ' . $fil['categorie'] : '',
            ($fil['priorite'] ?? '') !== '' ? 'Priorité : ' . $fil['priorite'] : '',
            '',
            trim((string) ($fil['demande'] ?? '')),
            '',
        ];
        foreach ($echanges as $m) {
            $lignes[] = self::ligneEchange($m);
        }
        return implode("\n", array_filter($lignes, static fn (string $l): bool => $l !== ''));
    }

    // ---------- Résumer ----------

    public static function schemaResume(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'points' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Trois à six phrases courtes : ce qui a été demandé, ce qui a été fait, où cela en est.',
                ],
                'attente' => [
                    'type' => ['string', 'null'],
                    'description' => 'Ce que le fil attend maintenant, en une phrase. null si rien n’est en attente.',
                ],
                'manquant' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Les informations qui manquent pour avancer. Tableau vide s’il ne manque rien.',
                ],
            ],
            'required' => ['points', 'attente', 'manquant'],
            'additionalProperties' => false,
        ];
    }

    private static function consigneResume(): string
    {
        return implode("\n", [
            'Tu résumes un fil de discussion interne à une entreprise, pour la personne qui va le reprendre.',
            'Tu t’en tiens à ce que le fil dit. Tu ne supposes rien, tu ne conseilles rien, tu ne juges personne.',
            'Chaque point est une phrase courte, en français, sans formule d’introduction.',
            'Si le fil ne dit pas où il en est, « attente » vaut null plutôt qu’une hypothèse.',
            'Réponds uniquement par le JSON demandé.',
        ]);
    }

    public static function resumer(array $fil): array
    {
        if (self::filVide($fil)) {
            return ['ok' => false, 'message' => 'Ce fil ne contient aucun message : rien à résumer.'];
        }

        $resultat = Ai::demander([
            'fonction' => 'resume',
            'consigne' => self::consigneResume(),
            'contenu' => self::filEnTexte($fil),
            'schema' => self::schemaResume(),
            'maxTokens' => 1500,
        ]);

        if (empty($resultat['ok'])) {
            return $resultat;
        }

        $d = is_array($resultat['donnees'] ?? null) ? $resultat['donnees'] : [];
        $resultat['resume'] = [
            'points' => self::texteListe($d['points'] ?? null, 6, 300),
            'attente' => self::texte($d['attente'] ?? null, 300),
            'manquant' => self::texteListe($d['manquant'] ?? null, 6, 200),
        ];
        return $resultat;
    }

    // ---------- Proposer un brouillon ----------

    private static function consigneBrouillon(): string
    {
        return implode("\n", [
            'Tu proposes un brouillon de réponse à une demande d’assistance interne, en français.',
            'Tu écris ce que la personne qui traite la demande pourrait envoyer : direct, courtois, sans emphase.',
            'Tu ne promets aucun délai, aucun budget, aucune décision : ce n’est pas à toi de les prendre.',
            'Ce que le fil ne dit pas, tu le demandes plutôt que de l’inventer.',
            'Réponds par le texte du message seul, sans objet, sans signature, sans commentaire sur ton propre travail.',
        ]);
    }

    public static function brouillon(array $fil): array
    {
        if (self::filVide($fil)) {
            return ['ok' => false, 'message' => 'Ce fil ne contient aucun message : rien à quoi répondre.'];
        }

        $resultat = Ai::demander([
            'fonction' => 'redaction',
            'consigne' => self::consigneBrouillon(),
            'contenu' => self::filEnTexte($fil),
            'maxTokens' => 1200,
        ]);

        if (empty($resultat['ok'])) {
            return $resultat;
        }
        $resultat['brouillon'] = self::texte($resultat['texte'] ?? null, 4000);
        return $resultat;
    }

    // ---------- Relever des décisions et des actions ----------

    public static function schemaElements(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'decisions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'libelle' => ['type' => 'string', 'description' => 'La décision, telle qu’elle a été prise, en une phrase'],
                        ],
                        'required' => ['libelle'],
                        'additionalProperties' => false,
                    ],
                ],
                'actions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'libelle' => ['type' => 'string', 'description' => 'Ce qui est à faire, en une phrase'],
                            'porteur' => [
                                'type' => ['string', 'null'],
                                'description' => 'Le prénom et le nom de la personne désignée, repris EXACTEMENT de la liste fournie. null si les notes ne désignent personne.',
                            ],
                            'echeance' => ['type' => ['string', 'null'], 'description' => 'Date au format AAAA-MM-JJ, si les notes en donnent une. Sinon null.'],
                        ],
                        'required' => ['libelle', 'porteur', 'echeance'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['decisions', 'actions'],
            'additionalProperties' => false,
        ];
    }

    private static function consigneElements(): string
    {
        return implode("\n", [
            'Tu relèves, dans des notes de réunion, ce qui a été DÉCIDÉ et ce qui est À FAIRE.',
            'Une décision est une chose tranchée. Une action est une chose à faire, avec si possible qui la porte et pour quand.',
            'Tu ne relèves que ce que les notes contiennent. Une piste évoquée n’est pas une décision ; une intention n’est pas une action.',
            'Le porteur est repris exactement de la liste des personnes fournie. Si les notes désignent quelqu’un qui n’y figure pas, le porteur vaut null.',
            'Si les notes ne contiennent ni décision ni action, rends deux tableaux vides. C’est une réponse, pas un échec.',
            'Réponds uniquement par le JSON demandé.',
        ]);
    }

    /**
     * Relève les décisions et les actions de notes de réunion.
     *
     * « personnes » sert à deux choses : le modèle y prend les porteurs possibles,
     * et le retour est recoupé avec elle. Un nom qui n'y figure pas est écarté — une
     * action ne s'attribue pas à quelqu'un qui n'existe pas dans l'entreprise.
     */
    public static function relever(string $notes, array $personnes = []): array
    {
        $annuaire = [];
        foreach ($personnes as $p) {
            $nom = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
            if ($nom !== '') {
                $annuaire[] = ['id' => (int) $p['id'], 'nom' => $nom, 'grade' => (string) ($p['grade'] ?? '')];
            }
        }

        $lignes = ['Personnes à qui une action peut être attribuée :'];
        foreach ($annuaire as $p) {
            $lignes[] = '- ' . $p['nom'] . ($p['grade'] !== '' ? ' (' . $p['grade'] . ')' : '');
        }
        $lignes[] = '';
        $lignes[] = 'Notes de la réunion :';
        $lignes[] = $notes;

        $resultat = Ai::demander([
            'fonction' => 'elements',
            'consigne' => self::consigneElements(),
            'contenu' => implode("\n", $lignes),
            'schema' => self::schemaElements(),
            'maxTokens' => 2500,
        ]);

        if (empty($resultat['ok'])) {
            return $resultat;
        }

        $d = is_array($resultat['donnees'] ?? null) ? $resultat['donnees'] : [];
        $parNom = [];
        foreach ($annuaire as $p) {
            $parNom[mb_strtolower($p['nom'])] = $p['id'];
        }

        $decisions = [];
        foreach (array_slice(is_array($d['decisions'] ?? null) ? $d['decisions'] : [], 0, 20) as $x) {
            $libelle = self::texte(is_array($x) ? ($x['libelle'] ?? null) : null, 300);
            if ($libelle !== null) {
                $decisions[] = ['libelle' => $libelle];
            }
        }

        $actions = [];
        foreach (array_slice(is_array($d['actions'] ?? null) ? $d['actions'] : [], 0, 30) as $x) {
            $libelle = self::texte(is_array($x) ? ($x['libelle'] ?? null) : null, 300);
            if ($libelle === null) {
                continue;
            }
            $nom = self::texte(is_array($x) ? ($x['porteur'] ?? null) : null, 120);
            $cle = $nom === null ? '' : mb_strtolower($nom);
            // Un porteur qui n'est pas dans l'annuaire n'est pas un porteur.
            $connu = $cle !== '' && isset($parNom[$cle]);
            $actions[] = [
                'libelle' => $libelle,
                'porteurId' => $connu ? $parNom[$cle] : null,
                'porteurNom' => $connu ? $nom : null,
                'echeance' => self::dateSeule(is_array($x) ? ($x['echeance'] ?? null) : null),
            ];
        }

        $resultat['decisions'] = $decisions;
        $resultat['actions'] = $actions;
        return $resultat;
    }

    // ---------- Nettoyage du retour ----------

    /** Ce que le modèle rend est traité comme une saisie : borné, jamais repris tel quel. */
    private static function texte(mixed $valeur, int $max): ?string
    {
        if ($valeur === null) {
            return null;
        }
        $propre = trim((string) preg_replace('~\s+~u', ' ', (string) $valeur));
        return $propre === '' ? null : mb_substr($propre, 0, $max);
    }

    private static function texteListe(mixed $valeur, int $combien, int $max): array
    {
        $sortie = [];
        foreach (array_slice(is_array($valeur) ? $valeur : [], 0, $combien) as $x) {
            $propre = self::texte($x, $max);
            if ($propre !== null) {
                $sortie[] = $propre;
            }
        }
        return $sortie;
    }

    private static function dateSeule(mixed $valeur): ?string
    {
        $propre = self::texte($valeur, 10);
        return $propre !== null && preg_match('~^\d{4}-\d{2}-\d{2}$~', $propre) === 1 ? $propre : null;
    }
}
