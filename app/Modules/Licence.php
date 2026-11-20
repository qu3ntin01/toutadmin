<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Secrets;
use App\Core\Settings;

/**
 * La licence de l'instance.
 *
 * Portage littéral du module de l'édition Node : mêmes états, mêmes noms,
 * mêmes règles. Ce qui change est l'outillage — sodium là où Node emploie son
 * module crypto — et rien d'autre : un jeton signé par le panneau doit être lu
 * de la même façon des deux côtés, sans quoi un client changeant d'édition
 * verrait sa licence refusée.
 *
 * ── Les quatre états ─────────────────────────────────────────────────────
 *
 *   absente     aucun jeton : l'instance n'a jamais été activée
 *   valide      le jeton couvre aujourd'hui
 *   grace       l'échéance est passée depuis moins de « grace » jours :
 *               tout fonctionne, un bandeau prévient
 *   restreint   au-delà : plus aucune écriture, mais la lecture, l'export et
 *               la connexion restent ouverts
 *
 * Le palier de grâce n'est pas une faveur, c'est une nécessité : un réseau
 * coupé un week-end, un renouvellement oublié un vendredi soir, et une
 * entreprise entière se retrouverait à l'arrêt un lundi matin pour une raison
 * qui n'a rien à voir avec elle.
 *
 * ── Ce que « restreint » ne fait jamais ──────────────────────────────────
 *
 * Ni effacer, ni chiffrer, ni cacher quoi que ce soit. On se connecte, on lit
 * tout, on exporte tout ; seules les écritures sont refusées. Les clients sont
 * responsables de traitement au sens du RGPD : leur couper l'accès aux dossiers
 * du personnel et aux bulletins de paie exposerait l'éditeur autant que ça les
 * gênerait. Prendre des données en otage n'est pas un levier commercial, c'est
 * une faute.
 */
final class Licence
{
    public const EDITION = 'php';

    public const ABSENTE = 'absente';
    public const VALIDE = 'valide';
    public const SURSIS = 'sursis';
    public const GRACE = 'grace';
    public const RESTREINT = 'restreint';

    private const JOUR = 86400;

    /**
     * Deux comptes à rebours, et ils ne mesurent pas la même chose.
     *
     * « grace » part de l'échéance écrite dans le jeton : un abonnement qui se
     * termine, cela se prévoit. « sursis » part de la première vérification en
     * échec : une licence révoquée, une clé d'instance retirée, un panneau
     * éditeur injoignable — cela tombe sans prévenir. D'où quarante-huit
     * heures : assez pour qu'un humain soit prévenu, rappelle et règle ; trop
     * peu pour qu'une instance révoquée continue des mois.
     *
     * On revérifie toutes les heures, et tous les quarts d'heure une fois le
     * sursis engagé : c'est là qu'une vérification qui réussit rend le plus
     * service, puisqu'elle éteint le bandeau.
     */
    public const SURSIS_SECONDES = 48 * 3600;
    public const VERIF_INTERVALLE = 3600;
    public const VERIF_INTERVALLE_SURSIS = 900;

    /**
     * Le prix, pour l'afficher — jamais pour le décider. Cinq euros par salarié
     * et par mois, au-delà des cinq premiers, offerts à tous : douze salariés,
     * trente-cinq euros. Le prix se décide et se facture chez l'éditeur, sur
     * l'effectif déclaré dans l'abonnement ; l'instance ne fait que le traduire
     * depuis le plafond que la licence lui donne, qui est cet effectif.
     *
     * La licence gratuite, elle, plafonne à cinq salariés : ce nombre-là est une
     * limite technique que l'instance applique, et le seuil du prix.
     *
     * Ce sont les nombres du site vitrine : s'ils divergeaient, une page publique
     * et une facture ne diraient pas la même chose.
     *
     * L'instance ne facture rien et n'encaisse rien.
     */
    public const PRIX_SALARIE = 5;
    public const GRATUITE_SIEGES = 5;

    /**
     * Ce que coûte l'instance, par mois, d'après le plafond de sa licence.
     *
     * Sans plafond (zéro), le prix ne se déduit pas : c'est une licence donnée à
     * la main, ou d'avant le prix par salarié — rend null, et l'écran ne promet
     * pas un montant qu'il ne connaît pas.
     */
    public static function prixMensuel(int $plafond): ?int
    {
        if ($plafond <= 0) {
            return null;
        }
        return self::PRIX_SALARIE * max(0, $plafond - self::GRATUITE_SIEGES);
    }

    // ------------------------------------------------------------- lecture

    private static function deB64url(string $texte): ?string
    {
        $brut = base64_decode(strtr($texte, '-_', '+/'), true);
        return $brut === false ? null : $brut;
    }

    /**
     * Relire un jeton, et ne rendre sa charge utile qu'après avoir vérifié la
     * signature. La vérification porte sur les octets reçus, pas sur un JSON
     * ré-encodé : c'est ce qui rend la canonicalisation sans objet.
     */
    public static function relire(?string $jeton, ?string $pem = null): ?array
    {
        $pem ??= LicenceCle::PEM;
        $morceaux = explode('.', trim((string) $jeton));
        if (count($morceaux) !== 2 || $morceaux[0] === '' || $morceaux[1] === '') {
            return null;
        }
        [$corps, $signature] = $morceaux;

        $brute = self::brutDepuisPem($pem);
        $octets = self::deB64url($signature);
        if ($brute === null || $octets === null || strlen($octets) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }

        try {
            if (!sodium_crypto_sign_verify_detached($octets, $corps, $brute)) {
                return null;
            }
        } catch (\Throwable) {
            // Clé publique illisible, signature mal formée : c'est non, pas une panne.
            return null;
        }

        $json = self::deB64url($corps);
        if ($json === null) {
            return null;
        }
        $charge = json_decode($json, true);
        return is_array($charge) && ($charge['v'] ?? 0) === 1 ? $charge : null;
    }

    /**
     * Déplier l'enveloppe SPKI plutôt que couper les trente-deux derniers
     * octets : une clé RSA collée là par erreur doit être refusée, pas tronquée.
     */
    private static function brutDepuisPem(string $pem): ?string
    {
        if (!preg_match('/-----BEGIN PUBLIC KEY-----(.+?)-----END PUBLIC KEY-----/s', $pem, $trouve)) {
            return null;
        }
        $der = base64_decode((string) preg_replace('/\s+/', '', $trouve[1]), true);
        if ($der === false || strlen($der) !== 44 || bin2hex(substr($der, 0, 12)) !== '302a300506032b6570032100') {
            return null;
        }
        return substr($der, 12);
    }

    // ------------------------------------------------------------- réglages

    public static function config(): array
    {
        $jeton = Settings::get('licence_jeton');
        return [
            'jeton' => $jeton !== '' ? Secrets::decrypt($jeton) : '',
            'url' => rtrim(Settings::get('update_editor_url'), '/'),
            'clef' => Settings::get('update_instance_key'),
            'adresse' => rtrim(Settings::get('licence_adresse'), '/'),
            'dernier_refus' => Settings::get('licence_dernier_refus'),
            'derniere_verif' => Settings::get('licence_derniere_verif'),
            'echec_depuis' => Settings::get('licence_echec_depuis'),
        ];
    }

    /**
     * L'empreinte de cette installation. Un nombre au hasard, posé une fois :
     * pas une empreinte matérielle, qui changerait au premier déplacement de
     * machine virtuelle et couperait un client qui n'a rien fait de mal.
     */
    public static function empreinte(): string
    {
        $valeur = Settings::get('licence_empreinte');
        if ($valeur === '') {
            $valeur = bin2hex(random_bytes(16));
            Settings::set('licence_empreinte', $valeur);
        }
        return $valeur;
    }

    // ---------------------------------------------------------------- état

    /**
     * L'état d'aujourd'hui. Aucune écriture ici : cette fonction est appelée à
     * chaque requête, elle doit être prévisible et sans effet de bord.
     */
    public static function etat(?int $maintenant = null): array
    {
        $horodatage = $maintenant ?? time();

        // Une instance dont le code ne porte pas de clé publique n'est pas une
        // instance vendue : développement, tests, démonstration.
        if (!LicenceCle::distribuee()) {
            return ['phase' => self::VALIDE, 'distribuee' => false, 'plafond_membres' => 0];
        }

        $jeton = self::config()['jeton'];
        if ($jeton === '') {
            return ['phase' => self::ABSENTE, 'distribuee' => true, 'plafond_membres' => 0, 'motif' => 'aucune-licence'];
        }

        $charge = self::relire($jeton);
        if ($charge === null) {
            // Un jeton illisible est un jeton faux. On ne distingue pas les
            // deux : il n'y a rien à faire de différent.
            return ['phase' => self::ABSENTE, 'distribuee' => true, 'plafond_membres' => 0, 'motif' => 'jeton-invalide'];
        }

        /*
         * À partir d'ici le temps n'est plus celui de la machine, mais celui
         * dont l'instance a la preuve : la date d'émission du jeton, qui est
         * signée, et le dernier horodatage du journal scellé. Reculer l'horloge
         * d'un serveur prend dix secondes et prolongerait un sursis
         * indéfiniment — c'est le moyen le plus simple, le plus silencieux et
         * le plus reproductible de neutraliser une licence, et chiffrer le code
         * n'y changerait rien : le code chiffré lirait la même horloge menteuse.
         */
        $maintenant = LicenceHorloge::maintenant($charge, $horodatage);

        // Deux vérifications que la signature seule ne fait pas : le jeton doit
        // avoir été émis pour cette édition, et pour cette installation-ci.
        if (($charge['edition'] ?? '') !== self::EDITION) {
            return ['phase' => self::ABSENTE, 'distribuee' => true, 'plafond_membres' => 0, 'motif' => 'autre-edition'];
        }
        if (($charge['instance'] ?? '') !== '' && $charge['instance'] !== self::empreinte()) {
            return ['phase' => self::ABSENTE, 'distribuee' => true, 'plafond_membres' => 0, 'motif' => 'autre-instance'];
        }

        // Le jeton ne vaut jamais au-delà de l'abonnement qu'il annonce, même si
        // sa propre date d'expiration allait plus loin par erreur.
        $expire = (int) strtotime((string) ($charge['expire'] ?? ''));
        $abonnement = (int) strtotime((string) ($charge['abonnement'] ?? ''));
        $fin = min($expire, $abonnement > 0 ? $abonnement : $expire);
        $graceJours = isset($charge['grace']) ? (int) $charge['grace'] : 7;
        $finGrace = $fin + $graceJours * self::JOUR;

        $commun = [
            'distribuee' => true,
            'licence' => $charge,
            'plafond_membres' => (int) ($charge['membres'] ?? 0),
            'expire' => gmdate('c', $fin),
            'abonnement' => (string) ($charge['abonnement'] ?? ''),
            'jours_restants' => (int) ceil(($fin - $maintenant) / self::JOUR),
        ];

        /*
         * Le sursis se calcule avant la grâce et l'emporte sur « valide » : un
         * jeton qui couvre encore trois mois ne dit rien de ce qui s'est passé
         * depuis. Une licence révoquée hier porte toujours son échéance de
         * l'an prochain — c'est la vérification qui l'apprend, et elle seule.
         */
        $config = self::config();
        $sursis = self::sursis($maintenant, self::echecDepuis());
        $sursisEpuise = $sursis['epuise'];

        if ($maintenant <= $fin) {
            if ($sursisEpuise) {
                return $commun + [
                    'phase' => self::RESTREINT,
                    'motif' => 'verification-impossible',
                    'echec_depuis' => $sursis['depuis'],
                ];
            }
            if ($sursis['engage']) {
                return $commun + [
                    'phase' => self::SURSIS,
                    'motif' => (string) ($config['dernier_refus'] !== '' ? $config['dernier_refus'] : 'verification-impossible'),
                    'echec_depuis' => $sursis['depuis'],
                    'heures_de_sursis' => $sursis['heures'],
                ];
            }
            return $commun + ['phase' => self::VALIDE];
        }
        if ($maintenant <= $finGrace && !$sursisEpuise) {
            return $commun + [
                'phase' => self::GRACE,
                'jours_de_grace' => (int) ceil(($finGrace - $maintenant) / self::JOUR),
            ];
        }
        return $commun + ['phase' => self::RESTREINT];
    }

    /**
     * Depuis quand la vérification échoue, et qui le dit.
     *
     * Le réglage seul ne suffirait pas : il s'efface d'une requête SQL, et le
     * sursis repartirait à zéro toutes les quarante-huit heures sans que rien
     * ne paraisse. Le journal d'audit, lui, est **scellé par chaînage** —
     * retirer une ligne du milieu casse la chaîne à cet endroit précis, et la
     * vérification du sceau le dit.
     *
     * On prend donc la plus ancienne des deux dates : celle du réglage, et
     * celle du premier échec scellé depuis la dernière réussite scellée.
     * Effacer le réglage ne gagne rien ; effacer le journal se voit.
     */
    public static function echecDepuis(): string
    {
        $duReglage = (int) strtotime((string) self::config()['echec_depuis']);
        $duJournal = self::premierEchecScelle();
        $retenu = $duReglage > 0 && $duJournal > 0
            ? min($duReglage, $duJournal)
            : max($duReglage, $duJournal);
        return $retenu > 0 ? gmdate('c', $retenu) : '';
    }

    /**
     * Le premier échec scellé depuis la dernière réussite scellée. Zéro si la
     * dernière trace est une réussite — ou s'il n'y a pas de trace du tout, ce
     * qui est le cas d'une instance qui vient de s'installer.
     */
    private static function premierEchecScelle(): int
    {
        try {
            $lignes = Db::all(
                "SELECT occurred_at, action FROM audit_log
                  WHERE action IN ('licence.verifiee', 'licence.non-verifiee')
                    AND hash IS NOT NULL AND hash <> ''
                  ORDER BY id DESC LIMIT 200"
            );
        } catch (\Throwable) {
            return 0;
        }

        $premier = 0;
        foreach ($lignes as $ligne) {
            // On remonte du plus récent au plus ancien : la première réussite
            // rencontrée clôt la série d'échecs qui la suivait dans le temps.
            if ((string) $ligne['action'] === 'licence.verifiee') {
                break;
            }
            $vu = (int) strtotime((string) $ligne['occurred_at'] . ' UTC');
            if ($vu > 0) {
                $premier = $vu;
            }
        }
        return $premier;
    }

    /**
     * Où en est le sursis, à partir de la date du premier échec.
     *
     * Séparé de « etat » pour une raison qui n'est pas cosmétique : c'est la
     * seule arithmétique de ce module qu'on puisse éprouver sans clé publique
     * dans le code, et c'est elle qui décide du jour où une instance cesse
     * d'écrire. Elle mérite d'être regardée en face.
     *
     * @return array{engage:bool, epuise:bool, heures:int, depuis:string}
     */
    public static function sursis(int $maintenant, string $echecDepuis): array
    {
        $echec = (int) strtotime($echecDepuis);
        if ($echec <= 0) {
            return ['engage' => false, 'epuise' => false, 'heures' => 0, 'depuis' => ''];
        }
        $fin = $echec + self::SURSIS_SECONDES;
        return [
            'engage' => $maintenant <= $fin,
            'epuise' => $maintenant > $fin,
            // Arrondi à l'heure supérieure : « il reste 2 heures » se comprend,
            // « il reste 1,37 heure » non.
            'heures' => $maintenant <= $fin ? (int) ceil(($fin - $maintenant) / 3600) : 0,
            'depuis' => gmdate('c', $echec),
        ];
    }

    /**
     * Faut-il revérifier maintenant ? Toutes les heures en temps normal, tous
     * les quarts d'heure une fois le sursis engagé.
     */
    public static function verificationDue(?int $maintenant = null): bool
    {
        if (!LicenceCle::distribuee()) {
            return false;
        }
        $maintenant ??= time();
        $config = self::config();
        $derniere = (int) strtotime((string) $config['derniere_verif']);
        $ecart = $config['echec_depuis'] !== '' ? self::VERIF_INTERVALLE_SURSIS : self::VERIF_INTERVALLE;
        return $maintenant - $derniere >= $ecart;
    }

    /**
     * Noter qu'une vérification a réussi ou échoué.
     *
     * C'est le seul endroit qui arme et désarme le compte à rebours. Une
     * réussite l'efface entièrement : une instance qui a repris contact repart
     * à zéro, elle ne traîne pas la panne de la veille.
     */
    public static function noterVerification(bool $ok, string $refus = '', ?array $charge = null): void
    {
        $quand = gmdate('c');

        // L'horloge avance d'abord : la date d'émission d'un jeton qu'on vient
        // de vérifier est signée, et c'est la meilleure preuve de
        // « maintenant » qu'une instance puisse tenir.
        LicenceHorloge::avancer($charge);

        if ($ok) {
            Settings::setMany([
                'licence_derniere_verif' => $quand,
                'licence_dernier_refus' => '',
                'licence_echec_depuis' => '',
            ]);
            // Scellé : c'est cette trace-là qui clôt une série d'échecs, et
            // elle ne s'écrit pas à la main.
            Audit::logSystem('licence.verifiee', 'instance', null, ['phase' => 'ok']);
            return;
        }
        $deja = Settings::get('licence_echec_depuis');
        Settings::setMany([
            'licence_derniere_verif' => $quand,
            'licence_dernier_refus' => $refus,
            // On ne repousse jamais le début de l'échec : sinon une
            // vérification qui échoue toutes les heures remettrait le compteur
            // à zéro chaque heure, et les quarante-huit heures ne viendraient
            // jamais.
            'licence_echec_depuis' => $deja !== '' ? $deja : $quand,
        ]);
        Audit::logSystem('licence.non-verifiee', 'instance', null, ['motif' => $refus]);
    }

    /**
     * Ce jeton est-il plus ancien que celui déjà en place ?
     *
     * Chaque charge utile porte sa date d'émission. Un jeton correctement signé
     * n'est pas pour autant celui d'aujourd'hui : gardé, puis reposé plus tard,
     * il rendrait à l'instance le plafond de membres ou l'échéance qu'elle
     * avait à l'époque. La signature ne protège pas de cela — seule la date le
     * fait.
     *
     * À émission égale on accepte : c'est le même jeton, reposé, et le refuser
     * ferait échouer une réinstallation légitime depuis une sauvegarde.
     */
    public static function rejeu(array $charge, ?string $pem = null): bool
    {
        $jeton = self::config()['jeton'];
        if ($jeton === '') {
            return false;
        }
        // La clé publique se passe comme à « relire » : la constante du code en
        // production, celle des tests quand on éprouve la règle. Aucun réglage
        // ne peut la remplacer — c'est du code source, et cela ne changera pas.
        $enPlace = self::relire($jeton, $pem);
        if ($enPlace === null) {
            return false;
        }
        $ancienne = (int) strtotime((string) ($enPlace['emise'] ?? ''));
        $nouvelle = (int) strtotime((string) ($charge['emise'] ?? ''));
        if ($ancienne <= 0 || $nouvelle <= 0) {
            return false;
        }
        return $nouvelle < $ancienne;
    }

    /** Raccourci : l'instance accepte-t-elle les écritures ? */
    public static function ecritureAutorisee(): bool
    {
        $phase = self::etat()['phase'];
        return $phase === self::VALIDE || $phase === self::SURSIS || $phase === self::GRACE;
    }

    /**
     * Le plafond de membres. Rendu séparément parce que l'appliquer n'a de sens
     * qu'à la création : une instance qui repasse sous une offre plus petite
     * garde les gens qu'elle a déjà, on ne licencie personne par migration de
     * contrat.
     *
     * L'effectif se compte comme la page de tarifs l'annonce — comptes actifs,
     * freelances et stagiaires compris, administrateurs exclus. Compter
     * autrement ferait mentir une page publique.
     */
    public static function plafondAtteint(?int $effectif = null): bool
    {
        $plafond = (int) self::etat()['plafond_membres'];
        if ($plafond <= 0) {
            return false;
        }
        $effectif ??= (int) Db::value("SELECT COUNT(*) FROM users WHERE active = 1 AND role <> 'admin'");
        return $effectif >= $plafond;
    }

    // ---------------------------------------------------------------- bail

    /**
     * Demander un bail au service de licences. Appelée à l'installation, puis
     * chaque nuit par la tâche planifiée. Ne jette pas : une panne de réseau
     * n'est pas une faute de l'instance, et la grâce est là pour ça.
     */
    public static function renouveler(): array
    {
        $config = self::config();
        $cible = $config['url'];
        $secret = $config['clef'] !== '' ? Secrets::decrypt($config['clef']) : '';
        if ($cible === '' || $secret === '') {
            return ['ok' => false, 'raison' => 'non-configure'];
        }

        /*
         * L'heure que cette machine croit être part avec la demande. Le panneau
         * la compare à la sienne : une instance dont l'horloge est en retard de
         * trois semaines se voit de l'extérieur, et cette observation-là,
         * l'hôte ne peut pas l'effacer — elle n'est pas chez lui. C'est la
         * seule trace qui survive à un accès administrateur complet sur le
         * serveur du client.
         */
        $enPlace = self::relire($config['jeton']);
        $retard = LicenceHorloge::recul($enPlace);
        if ($retard > 0) {
            Logs::record([
                'level' => 3, 'channel' => 'securite', 'event' => 'licence.horloge-reculee',
                'message' => 'L’horloge de cette machine est en retard de '
                    . (int) round($retard / 3600) . ' h sur ce que l’instance sait déjà. '
                    . 'Les durées de licence sont mesurées sur la date la plus tardive dont '
                    . 'elle ait la preuve.',
            ]);
            Audit::logSystem('licence.horloge-reculee', 'instance', null,
                ['retard_heures' => (int) round($retard / 3600)]);
        }

        $corps = json_encode([
            'edition' => self::EDITION,
            'instance' => self::empreinte(),
            'adresse' => $config['adresse'],
            'horloge' => gmdate('c'),
            'horloge_sure' => gmdate('c', LicenceHorloge::maintenant($enPlace)),
        ]);

        $contexte = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-Cle-Instance: $secret\r\n",
            'content' => $corps,
            'timeout' => 15,
            // Sans cela, un 403 lèverait un avertissement et rendrait false :
            // on veut lire le corps de la réponse, qui nomme le refus.
            'ignore_errors' => true,
        ]]);

        $reponse = @file_get_contents("$cible/api/v1/licence", false, $contexte);
        if ($reponse === false) {
            return ['ok' => false, 'raison' => 'reseau'];
        }

        $statut = 0;
        foreach ($http_response_header ?? [] as $ligne) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $ligne, $trouve)) {
                $statut = (int) $trouve[1];
            }
        }

        $lu = json_decode($reponse, true);
        if (!is_array($lu)) {
            return ['ok' => false, 'raison' => 'reponse-illisible'];
        }

        if ($statut < 200 || $statut >= 300) {
            // Le panneau nomme son refus : on le garde tel quel pour l'écran,
            // plutôt que de laisser l'administrateur deviner pourquoi son
            // instance s'éteint.
            self::noterVerification(false, (string) ($lu['erreur'] ?? $statut));
            Logs::record([
                'level' => 4, 'channel' => 'securite', 'event' => 'licence.refusee',
                'message' => 'Le service de licences refuse le bail : ' . ($lu['explication'] ?? $lu['erreur'] ?? $statut),
            ]);
            return ['ok' => false, 'raison' => (string) ($lu['erreur'] ?? 'refus'), 'explication' => (string) ($lu['explication'] ?? '')];
        }

        $charge = self::relire((string) ($lu['licence'] ?? ''));
        if ($charge === null) {
            // Un panneau qui répond 200 avec un jeton qu'on ne sait pas
            // vérifier : soit la clé publique du code ne correspond pas, soit
            // ce n'est pas lui.
            Logs::record([
                'level' => 3, 'channel' => 'securite', 'event' => 'licence.jeton-refuse',
                'message' => 'Un jeton de licence a été servi mais sa signature n’est pas celle de l’éditeur.',
            ]);
            self::noterVerification(false, 'signature');
            return ['ok' => false, 'raison' => 'signature'];
        }

        // Un jeton plus ancien que celui qu'on a déjà est un rejeu : le panneau
        // n'en sert jamais, mais un intermédiaire qui rejoue une vieille
        // réponse, lui, pourrait — et il gagnerait le plafond ou l'échéance
        // d'hier.
        if (self::rejeu($charge)) {
            Logs::record([
                'level' => 3, 'channel' => 'securite', 'event' => 'licence.rejeu',
                'message' => 'Un jeton de licence plus ancien que celui en place a été servi ; il est écarté.',
            ]);
            self::noterVerification(false, 'rejeu');
            return ['ok' => false, 'raison' => 'rejeu'];
        }

        Settings::set('licence_jeton', Secrets::encrypt((string) $lu['licence']));
        self::noterVerification(true, '', $charge);
        return ['ok' => true, 'licence' => $charge];
    }

    /**
     * Poser une licence hors ligne, collée à la main. Le jeton est vérifié
     * avant d'être rangé : une erreur de copier-coller se voit tout de suite,
     * plutôt qu'au prochain démarrage.
     */
    public static function poser(?string $jeton): array
    {
        $charge = self::relire(trim((string) $jeton));
        if ($charge === null) {
            return ['ok' => false, 'raison' => 'signature'];
        }
        if (($charge['edition'] ?? '') !== self::EDITION) {
            return ['ok' => false, 'raison' => 'autre-edition'];
        }
        if (($charge['instance'] ?? '') !== '' && $charge['instance'] !== self::empreinte()) {
            return ['ok' => false, 'raison' => 'autre-instance'];
        }
        if ((int) strtotime((string) ($charge['abonnement'] ?? '')) <= time()) {
            return ['ok' => false, 'raison' => 'echue'];
        }
        if (self::rejeu($charge)) {
            return ['ok' => false, 'raison' => 'rejeu'];
        }

        Settings::set('licence_jeton', Secrets::encrypt(trim((string) $jeton)));
        self::noterVerification(true, '', $charge);
        Audit::logSystem('licence.posee', 'instance', null, [
            'reference' => $charge['id'] ?? '', 'mode' => $charge['mode'] ?? '',
        ]);
        return ['ok' => true, 'licence' => $charge];
    }

    /** Le balayage : renouveler si c'est un bail en ligne, et noter ce qui bascule. */
    public static function balayer(bool $forcer = false): array
    {
        if (!LicenceCle::distribuee()) {
            return ['verifie' => false];
        }

        // Le balayage passe souvent, mais c'est « verificationDue » qui décide
        // du rythme : toutes les heures, tous les quarts d'heure en sursis.
        if (!$forcer && !self::verificationDue()) {
            return ['verifie' => false, 'phase' => self::etat()['phase'], 'raison' => 'pas-encore'];
        }

        $avant = self::etat();
        if (($avant['licence']['mode'] ?? '') === 'hors-ligne') {
            /*
             * Rien à demander : ce jeton-là ne se renouvelle que par fichier.
             * Le vérifier, c'est relire sa signature et ses dates — ce que
             * « etat » vient de faire. On note quand même le passage, sans quoi
             * l'instance se croirait jamais vérifiée et armerait un sursis pour
             * une licence parfaitement valable.
             */
            $bon = in_array($avant['phase'], [self::VALIDE, self::SURSIS, self::GRACE], true);
            self::noterVerification($bon, $bon ? '' : (string) ($avant['motif'] ?? $avant['phase']));
            return ['verifie' => true, 'phase' => self::etat()['phase'], 'mode' => 'hors-ligne',
                    'renouvele' => $bon];
        }

        $bail = self::renouveler();
        $apres = self::etat();
        if ($apres['phase'] !== $avant['phase']) {
            Logs::record([
                'level' => $apres['phase'] === self::RESTREINT ? 3 : 4,
                'channel' => 'systeme',
                'event' => 'licence.' . $apres['phase'],
                'message' => "La licence passe de « {$avant['phase']} » à « {$apres['phase']} »"
                    . (($apres['motif'] ?? '') !== '' ? " ({$apres['motif']})" : '') . '.',
            ]);
        }
        return ['verifie' => true, 'renouvele' => $bail['ok'], 'phase' => $apres['phase'], 'raison' => $bail['raison'] ?? ''];
    }
}
