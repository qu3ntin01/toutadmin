<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Db;
use App\Core\Secrets;
use App\Core\Settings;
use App\Core\Tar;

/**
 * Mises à jour de l'instance.
 *
 * Une mise à jour, c'est du code qui remplace du code sur un serveur en
 * production. Trois règles tiennent tout le reste :
 *
 *  1. On ne fait confiance qu'à la signature. L'empreinte dit que l'archive est
 *     entière, pas d'où elle vient ; seule la signature de l'éditeur le dit.
 *  2. On sauvegarde avant de toucher à quoi que ce soit, et on garde les
 *     fichiers remplacés à côté — un retour arrière ne doit pas dépendre du
 *     réseau.
 *  3. On vérifie que le site répond encore, tout de suite après la bascule.
 *
 * Cette troisième règle est la différence avec l'édition Node. Là-bas, un
 * service tourne et c'est son redémarrage qui met la version en route : on peut
 * compter les démarrages ratés. Ici, il n'y a pas de redémarrage — le code posé
 * est actif à la requête suivante. Une erreur de syntaxe dans un fichier posé
 * rend le site blanc immédiatement, et personne ne serait là pour le voir. Donc
 * on va voir soi-même : une requête sur sa propre adresse, et si elle ne répond
 * pas, les fichiers précédents reviennent dans la seconde.
 */
final class Updates
{
    public const EDITION = 'php';

    /**
     * Ce qu'une mise à jour remplace. Le reste — data/, config.php, .htaccess —
     * appartient à l'instance et n'est jamais touché.
     */
    public const CODE_ROOTS = ['app', 'public', 'tools', 'VERSION'];

    /** Au-delà, l'archive n'est pas une mise à jour mais une erreur de manœuvre. */
    private const MAX_ARCHIVE = 256 * 1024 * 1024;

    /**
     * Le code de cette instance s'écrit-il ?
     *
     * Une mise à jour, ici, remplace des fichiers dans le dossier du produit.
     * Cela suppose que ce dossier appartienne à l'instance et lui survive. Dans
     * une image de conteneur, il appartient à l'image : des fichiers réécrits à
     * l'intérieur disparaissent à son premier remplacement — la mise à jour
     * aurait l'air de marcher, puis se déferait toute seule, sans que rien ne
     * le dise.
     *
     * Le raisonnement vaut aussi hors conteneur, pour un code posé en lecture
     * seule ou par un gestionnaire de paquets. La variable ne parle donc pas de
     * conteneurs mais de ce qui compte : le code ne s'écrit pas.
     *
     * Ce qui reste actif : la vérification. Savoir qu'une version existe sert
     * toujours ; c'est l'application, et le retour en arrière, qui s'arrêtent.
     */
    public static function codeImmuable(): bool
    {
        $valeur = strtolower(trim((string) (getenv('CODE_IMMUABLE') ?: '')));
        if ($valeur === '') {
            // En PHP-FPM, l'environnement n'atteint le processus que si le pool
            // le laisse passer. Le fichier de configuration sert donc de
            // seconde source — c'est aussi ce qui permet de poser le réglage
            // sans toucher au service.
            return (bool) Config::get('code_immuable', false);
        }
        return $valeur === '1' || $valeur === 'true' || $valeur === 'oui';
    }

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function directory(): string
    {
        $dir = Config::get('data_dir', self::root() . '/data') . '/mises-a-jour';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        return $dir;
    }

    /** La version installée se lit dans le fichier posé, pas dans une constante :
     *  après une mise à jour, c'est le fichier qui a changé. */
    public static function currentVersion(): string
    {
        $file = self::root() . '/VERSION';
        if (is_file($file)) {
            $read = trim((string) file_get_contents($file));
            if ($read !== '') {
                return $read;
            }
        }
        return '0.0.0';
    }

    public static function config(): array
    {
        $stored = Settings::get('update_instance_key');
        return [
            'url' => rtrim(Settings::get('update_editor_url'), '/'),
            'key' => $stored === '' ? '' : Secrets::decrypt($stored),
            'public_key' => Settings::get('update_public_key'),
            'channel' => Settings::get('update_channel') === 'beta' ? 'beta' : 'stable',
            'mode' => Settings::get('update_mode') === 'auto' ? 'auto' : 'avertir',
            'name' => Settings::get('update_instance_name'),
            'version' => self::currentVersion(),
        ];
    }

    public static function setConfig(array $values): array
    {
        $write = [];
        if (array_key_exists('url', $values)) {
            $write['update_editor_url'] = trim((string) $values['url']);
        }
        // Une clé laissée vide ne l'efface pas : l'écran ne la réaffiche jamais,
        // donc un enregistrement du formulaire la perdrait à chaque passage.
        if (!empty($values['key'])) {
            $write['update_instance_key'] = Secrets::encrypt(trim((string) $values['key']));
        }
        if (array_key_exists('public_key', $values)) {
            $write['update_public_key'] = trim((string) $values['public_key']);
        }
        if (array_key_exists('channel', $values)) {
            $write['update_channel'] = $values['channel'] === 'beta' ? 'beta' : 'stable';
        }
        if (array_key_exists('mode', $values)) {
            $write['update_mode'] = $values['mode'] === 'auto' ? 'auto' : 'avertir';
        }
        if (array_key_exists('name', $values)) {
            $write['update_instance_name'] = mb_substr(trim((string) $values['name']), 0, 120);
        }
        Settings::setMany($write);
        return self::config();
    }

    public static function isConfigured(): bool
    {
        $config = self::config();
        return $config['url'] !== '' && $config['key'] !== '' && $config['public_key'] !== '';
    }

    /** Ce que l'éditeur a répondu la dernière fois, tel qu'on l'a rangé. */
    public static function available(): ?array
    {
        $raw = Settings::get('update_available');
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Demander s'il y a du nouveau. La réponse est rangée telle quelle : c'est
     * au moment d'appliquer qu'on vérifie, pas au moment de lire.
     */
    public static function check(): array
    {
        if (!self::isConfigured()) {
            return ['ok' => false, 'reason' => 'not-configured'];
        }
        $config = self::config();

        $url = $config['url'] . '/api/v1/version?' . http_build_query([
            'edition' => self::EDITION,
            'canal' => $config['channel'],
            'courante' => $config['version'],
            'nom' => $config['name'],
        ]);

        $response = self::fetch($url, $config['key']);
        if ($response['status'] === 0) {
            Settings::setMany([
                'update_last_check' => gmdate('c'),
                'update_last_error' => 'unreachable',
            ]);
            Logs::record([
                'level' => 4, 'channel' => 'integration', 'event' => 'update.check.failed',
                'message' => 'Éditeur injoignable : ' . $response['error'],
            ]);
            return ['ok' => false, 'reason' => 'unreachable', 'detail' => $response['error']];
        }
        if ($response['status'] !== 200) {
            $reason = $response['status'] === 401 ? 'key-refused' : 'http-' . $response['status'];
            Settings::setMany(['update_last_check' => gmdate('c'), 'update_last_error' => $reason]);
            Logs::record([
                'level' => 4, 'channel' => 'integration', 'event' => 'update.check.failed',
                'message' => "Vérification des mises à jour refusée ($reason).",
            ]);
            return ['ok' => false, 'reason' => $reason];
        }

        $body = json_decode($response['body'], true);
        $announced = is_array($body) && !empty($body['disponible']) ? ($body['version'] ?? null) : null;
        Settings::setMany([
            'update_last_check' => gmdate('c'),
            'update_last_error' => '',
            'update_available' => is_array($announced) ? json_encode($announced, JSON_UNESCAPED_UNICODE) : '',
        ]);
        return ['ok' => true, 'available' => is_array($announced), 'version' => $announced];
    }

    /**
     * Vérifier une archive avant de la croire : la taille, l'empreinte, puis la
     * signature de l'éditeur sur la phrase qui nomme cette version précise. Une
     * signature portée par l'empreinte seule laisserait resservir une archive
     * authentique sous un autre numéro.
     */
    public static function verifyArchive(string $content, array $announced, string $publicKeyPem): array
    {
        if (strlen($content) !== (int) ($announced['taille'] ?? 0)) {
            return ['ok' => false, 'reason' => 'taille'];
        }
        $digest = hash('sha256', $content);
        if (!hash_equals((string) ($announced['empreinte'] ?? ''), $digest)) {
            return ['ok' => false, 'reason' => 'empreinte'];
        }

        $raw = self::rawPublicKey($publicKeyPem);
        if ($raw === null) {
            return ['ok' => false, 'reason' => 'cle-publique'];
        }

        $phrase = sprintf(
            'toutadmin/%s/%s/%s/%d',
            $announced['edition'] ?? '',
            $announced['numero'] ?? '',
            $announced['empreinte'] ?? '',
            (int) ($announced['taille'] ?? 0),
        );
        $signature = base64_decode((string) ($announced['signature'] ?? ''), true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return ['ok' => false, 'reason' => 'signature'];
        }

        try {
            $good = sodium_crypto_sign_verify_detached($signature, $phrase, $raw);
        } catch (\Throwable) {
            $good = false;
        }
        return $good ? ['ok' => true, 'digest' => $digest] : ['ok' => false, 'reason' => 'signature'];
    }

    /**
     * La clé publique voyage en PEM ; sodium veut ses trente-deux octets bruts.
     * L'enveloppe SPKI d'une clé Ed25519 fait quarante-quatre octets : douze
     * d'en-tête, puis la clé. On vérifie l'en-tête plutôt que de couper à
     * l'aveugle — une clé RSA collée là par erreur doit être refusée, pas
     * tronquée.
     */
    public static function rawPublicKey(string $pem): ?string
    {
        if (!preg_match('/-----BEGIN PUBLIC KEY-----(.+?)-----END PUBLIC KEY-----/s', $pem, $matches)) {
            return null;
        }
        $der = base64_decode(preg_replace('/\s+/', '', $matches[1]) ?? '', true);
        if ($der === false || strlen($der) !== 44) {
            return null;
        }
        // 302a300506032b6570032100 : SEQUENCE, algorithme 1.3.101.112 (Ed25519).
        if (bin2hex(substr($der, 0, 12)) !== '302a300506032b6570032100') {
            return null;
        }
        return substr($der, 12);
    }

    /** L'archive doit contenir un produit, et celui qu'elle annonce. */
    public static function inspectContents(array $files, string $number): array
    {
        $index = [];
        foreach ($files as $file) {
            $index[$file['name']] = $file['content'];
        }
        if (!isset($index['public/index.php'])) {
            return ['ok' => false, 'reason' => 'public/index.php absent de l’archive'];
        }
        if (!isset($index['VERSION'])) {
            return ['ok' => false, 'reason' => 'VERSION absent de l’archive'];
        }
        $declared = trim($index['VERSION']);
        if ($declared !== $number) {
            return ['ok' => false, 'reason' => "l’archive annonce $declared, la version dit $number"];
        }
        return ['ok' => true];
    }

    /**
     * Lire l'archive. Les noms sont contrôlés même si la signature est bonne :
     * elle prouve l'origine, pas l'innocence, et un nom qui remonte d'un cran
     * écrirait hors de l'arborescence.
     */
    public static function readArchive(string $content): array
    {
        $raw = @gzdecode($content);
        if ($raw === false) {
            throw new \RuntimeException('Archive illisible : ce n’est pas du gzip.');
        }
        $files = [];
        foreach (Tar::unpack($raw) as $entry) {
            $clean = preg_replace('#^\./#', '', $entry['name']) ?? $entry['name'];
            $root = explode('/', $clean)[0];
            if (!in_array($root, self::CODE_ROOTS, true)) {
                continue;
            }
            $safe = Tar::safeName($clean, self::CODE_ROOTS);
            if ($safe === null) {
                throw new \RuntimeException('Nom de fichier refusé dans l’archive : ' . $entry['name']);
            }
            $files[] = ['name' => $safe, 'content' => $entry['data']];
        }
        if ($files === []) {
            throw new \RuntimeException('Archive vide : aucun fichier de code.');
        }
        return $files;
    }

    /** Mettre de côté les fichiers qu'on s'apprête à remplacer. */
    public static function setAside(array $files, string $dir, ?string $root = null): array
    {
        $root ??= self::root();
        $kept = [];
        foreach ($files as $file) {
            $source = $root . '/' . $file['name'];
            if (!is_file($source)) {
                continue;
            }
            $target = $dir . '/' . $file['name'];
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0770, true);
            }
            copy($source, $target);
            $kept[] = $file['name'];
        }
        return $kept;
    }

    public static function writeFiles(array $files, ?string $root = null): void
    {
        $root ??= self::root();
        foreach ($files as $file) {
            $target = $root . '/' . $file['name'];
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0770, true);
            }
            file_put_contents($target, $file['content']);
        }
    }

    /** Remettre ce qui avait été mis de côté. */
    public static function restoreFiles(string $dir, array $names, ?string $root = null): void
    {
        $root ??= self::root();
        foreach ($names as $name) {
            $source = $dir . '/' . $name;
            if (!is_file($source)) {
                continue;
            }
            $target = $root . '/' . $name;
            if (!is_dir(dirname($target))) {
                mkdir(dirname($target), 0770, true);
            }
            copy($source, $target);
        }
    }

    public static function history(int $limit = 20): array
    {
        return Db::all(
            'SELECT u.*, users.first_name, users.last_name
             FROM updates u LEFT JOIN users ON users.id = u.by_user_id
             ORDER BY u.started_at DESC, u.id DESC LIMIT ?',
            [$limit],
        );
    }

    /**
     * Appliquer une mise à jour.
     *
     * L'ordre compte, et chaque étape peut refuser : télécharger, vérifier,
     * lire, regarder ce qu'il y a dedans, sauvegarder, mettre de côté, poser,
     * puis aller voir si le site répond encore. Rien n'est écrit dans
     * l'arborescence avant que tout le reste soit passé.
     */
    /**
     * Les étapes d'une pose, dans l'ordre où elles arrivent.
     *
     * Elles sont nommées ici parce que trois endroits en parlent : le code qui
     * les traverse, la fenêtre qui les affiche, et le journal. Une liste
     * recopiée aurait fini par décrire une quatrième chose.
     *
     * L'édition Node en porte une sixième, « redemarrage » : ici, rien ne
     * redémarre — le serveur relit les fichiers à la requête suivante.
     */
    public const ETAPES = ['telechargement', 'verification', 'lecture', 'sauvegarde', 'pose', 'controle'];

    /**
     * Où en est la pose en cours, s'il y en a une.
     *
     * L'étape vient de la base, où la pose l'écrit en avançant : rafraîchir la
     * page pendant une pose ne perd donc pas le fil, et la fenêtre montre où
     * l'instance en est plutôt qu'une animation.
     */
    public static function enCours(): ?array
    {
        $ligne = Db::get("SELECT * FROM updates WHERE status = 'running' ORDER BY id DESC LIMIT 1");
        if ($ligne === null) {
            return null;
        }
        $detail = (string) ($ligne['detail'] ?? '');
        $nom = str_starts_with($detail, 'etape:') ? substr($detail, 6) : '';
        $nom = $nom !== '' ? $nom : self::ETAPES[0];
        $rang = array_search($nom, self::ETAPES, true);

        return [
            'id' => (int) $ligne['id'],
            'version' => $ligne['version'],
            'depuis' => $ligne['from_version'],
            'etape' => $nom,
            'rang' => $rang === false ? 0 : $rang,
            'total' => count(self::ETAPES),
            'commencee_le' => $ligne['started_at'],
        ];
    }

    public static function apply(array $options = []): array
    {
        $by = $options['by'] ?? null;
        $mode = $options['mode'] ?? 'manual';
        $root = $options['root'] ?? self::root();
        $selfCheck = $options['self_check'] ?? null;

        // Avant tout le reste : rien n'est tenté si rien ne peut être écrit.
        // Refuser ici plutôt que d'échouer au milieu évite de laisser une
        // instance à moitié remplacée.
        if (self::codeImmuable()) {
            return ['ok' => false, 'reason' => 'code-immuable'];
        }
        if (!self::isConfigured()) {
            return ['ok' => false, 'reason' => 'not-configured'];
        }
        $announced = self::available();
        if ($announced === null) {
            return ['ok' => false, 'reason' => 'nothing-to-apply'];
        }

        $config = self::config();
        $from = self::currentVersion();
        $id = Db::insert(
            'INSERT INTO updates (version, edition, channel, mode, status, from_version, by_user_id)
             VALUES (?, ?, ?, ?, \'running\', ?, ?)',
            [$announced['numero'], $announced['edition'] ?? self::EDITION,
             $announced['canal'] ?? $config['channel'], $mode, $from, $by],
        );

        /*
         * Note l'étape en cours sur la ligne de la mise à jour.
         *
         * Elle y est écrite plutôt que gardée en mémoire pour une raison
         * précise : qui rafraîchit la page pendant une pose doit retrouver où
         * elle en est, et non un écran muet devant un processus qui travaille
         * encore.
         */
        $etape = static function (string $nom) use ($id): void {
            Db::run('UPDATE updates SET detail = ? WHERE id = ?', ["etape:$nom", $id]);
        };

        $fail = static function (string $reason, string $detail = '') use ($id, $announced): array {
            Db::run("UPDATE updates SET status = 'failed', detail = ?, finished_at = datetime('now') WHERE id = ?",
                [$detail !== '' ? $detail : $reason, $id]);
            Logs::record([
                'level' => 3, 'channel' => 'systeme', 'event' => 'update.failed',
                'message' => "Mise à jour {$announced['numero']} refusée : " . ($detail !== '' ? $detail : $reason),
            ]);
            return ['ok' => false, 'reason' => $reason, 'detail' => $detail];
        };

        // 1. Télécharger.
        $etape('telechargement');
        $response = self::fetch((string) $announced['archive'], $config['key'], 180);
        if ($response['status'] === 0) {
            return $fail('download', $response['error']);
        }
        if ($response['status'] !== 200) {
            return $fail('download', 'l’éditeur a répondu ' . $response['status']);
        }
        $content = $response['body'];
        if (strlen($content) > self::MAX_ARCHIVE) {
            return $fail('download', 'archive démesurée');
        }

        // 2. Vérifier avant de croire.
        $etape('verification');
        $verdict = self::verifyArchive($content, $announced, $config['public_key']);
        if (!$verdict['ok']) {
            Logs::record([
                'level' => 1, 'channel' => 'securite', 'event' => 'update.rejected',
                'message' => "Archive de mise à jour refusée ({$verdict['reason']}) pour la version {$announced['numero']}.",
            ]);
            return $fail('verification', $verdict['reason']);
        }

        // 3. Lire, et regarder ce qu'il y a dedans.
        $etape('lecture');
        try {
            $files = self::readArchive($content);
        } catch (\Throwable $error) {
            return $fail('archive', $error->getMessage());
        }
        $contents = self::inspectContents($files, (string) $announced['numero']);
        if (!$contents['ok']) {
            return $fail('contents', $contents['reason']);
        }

        // 4. Sauvegarder avant de toucher au code.
        $etape('sauvegarde');
        try {
            $backup = Backup::create('avant mise à jour', 'vers ' . $announced['numero']);
            $backupFile = (string) ($backup['fileName'] ?? '');
        } catch (\Throwable $error) {
            return $fail('backup', $error->getMessage());
        }

        // 5. Mettre de côté, puis poser.
        $etape('pose');
        $aside = self::directory() . '/precedent-' . $from;
        self::removeDirectory($aside);
        if (!is_dir($aside)) {
            mkdir($aside, 0770, true);
        }
        $kept = self::setAside($files, $aside, $root);
        try {
            self::writeFiles($files, $root);
        } catch (\Throwable $error) {
            self::restoreFiles($aside, $kept, $root);
            return $fail('write', $error->getMessage());
        }

        $etape('controle');
        // 6. Aller voir si le site répond encore. Ici, rien ne redémarre : le
        //    code posé est actif tout de suite, et une erreur de syntaxe rend le
        //    site blanc dans la seconde.
        $verdictSite = self::siteAnswers($selfCheck);
        if ($verdictSite === false) {
            self::restoreFiles($aside, $kept, $root);
            Logs::record([
                'level' => 2, 'channel' => 'systeme', 'event' => 'update.rolledback',
                'message' => "Retour à la version $from : {$announced['numero']} ne répondait plus.",
            ]);
            Db::run("UPDATE updates SET status = 'failed', detail = ?, backup_file = ?, finished_at = datetime('now') WHERE id = ?",
                ['le site ne répondait plus après la pose ; version précédente remise', $backupFile, $id]);
            return ['ok' => false, 'reason' => 'site-muet', 'rolledBack' => true];
        }

        Db::run("UPDATE updates SET status = 'success', detail = 'appliquée', backup_file = ?, finished_at = datetime('now') WHERE id = ?",
            [$backupFile, $id]);
        Settings::set('update_available', '');

        Logs::record([
            'level' => 5, 'channel' => 'systeme', 'event' => 'update.applied',
            'message' => "Version {$announced['numero']} appliquée (depuis $from).",
        ]);
        Audit::logSystem('update.apply', 'instance', null, [
            'de' => $from, 'vers' => $announced['numero'], 'mode' => $mode,
        ]);

        return ['ok' => true, 'version' => $announced['numero'], 'from' => $from, 'backup' => $backupFile];
    }

    /**
     * Le site répond-il encore ? Une requête sur sa propre adresse, sans suivre
     * de redirection : ce qu'on veut savoir, c'est que PHP sert quelque chose,
     * pas qu'une page précise s'affiche. Sans adresse connue, on ne peut pas
     * vérifier — et on ne prétend pas l'avoir fait : la mise à jour passe, avec
     * une ligne au journal pour le dire.
     */
    private static function siteAnswers(?callable $selfCheck): ?bool
    {
        if ($selfCheck !== null) {
            return (bool) $selfCheck();
        }
        $base = rtrim((string) Config::get('base_url', ''), '/');
        if ($base === '') {
            Logs::record([
                'level' => 5, 'channel' => 'systeme', 'event' => 'update.selfcheck.skipped',
                'message' => 'Adresse de l’instance inconnue : la pose n’a pas été vérifiée par une requête.',
            ]);
            return null;
        }
        $handle = curl_init($base . '/connexion');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return $status > 0 && $status < 500;
    }

    /**
     * Revenir à la version précédente à la demande, tant que les fichiers mis de
     * côté sont là.
     */
    public static function rollback(array $options = []): array
    {
        $by = $options['by'] ?? null;
        $root = $options['root'] ?? self::root();

        if (self::codeImmuable()) {
            return ['ok' => false, 'reason' => 'code-immuable'];
        }

        $last = Db::get("SELECT * FROM updates WHERE status = 'success' ORDER BY id DESC LIMIT 1");
        if ($last === null) {
            return ['ok' => false, 'reason' => 'nothing-to-undo'];
        }
        $aside = self::directory() . '/precedent-' . $last['from_version'];
        if (!is_dir($aside)) {
            return ['ok' => false, 'reason' => 'files-gone'];
        }

        self::restoreFiles($aside, self::filesIn($aside), $root);
        Db::run(
            "INSERT INTO updates (version, edition, channel, mode, status, detail, from_version, by_user_id, finished_at)
             VALUES (?, 'php', ?, 'rollback', 'rolledback', 'retour arrière demandé', ?, ?, datetime('now'))",
            [$last['from_version'], $last['channel'], $last['version'], $by],
        );
        Logs::record([
            'level' => 5, 'channel' => 'systeme', 'event' => 'update.rollback',
            'message' => "Retour à la version {$last['from_version']} demandé.",
        ]);
        Audit::logSystem('update.rollback', 'instance', null, [
            'de' => $last['version'], 'vers' => $last['from_version'],
        ]);
        return ['ok' => true, 'version' => $last['from_version']];
    }

    /** La tâche planifiée : vérifier, et appliquer si l'instance l'a demandé. */
    public static function sweep(): array
    {
        if (!self::isConfigured()) {
            return ['checked' => false];
        }
        $seen = self::check();
        if (!$seen['ok'] || empty($seen['available'])) {
            return ['checked' => true, 'available' => false];
        }
        // En code immuable, « auto » ne veut plus rien dire : on prévient, et
        // c'est tout. Le réglage reste tel que l'administrateur l'a laissé.
        if (self::codeImmuable() || self::config()['mode'] !== 'auto') {
            Logs::record([
                'level' => 5, 'channel' => 'systeme', 'event' => 'update.available',
                'message' => self::codeImmuable()
                    ? "Version {$seen['version']['numero']} disponible. Le code de cette instance ne s’écrit pas : changez l’image."
                    : "Version {$seen['version']['numero']} disponible. Mode « prévenir seulement » : rien n’est appliqué.",
            ]);
            return ['checked' => true, 'available' => true, 'applied' => false];
        }
        $result = self::apply(['mode' => 'auto']);
        return ['checked' => true, 'available' => true, 'applied' => $result['ok'], 'result' => $result];
    }

    /** Une requête HTTP, avec la clé d'instance. */
    private static function fetch(string $url, string $key, int $timeout = 20): array
    {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['X-Cle-Instance: ' . $key],
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        return [
            'status' => $body === false ? 0 : $status,
            'body' => $body === false ? '' : (string) $body,
            'error' => $error,
        ];
    }

    private static function filesIn(string $dir, string $prefix = ''): array
    {
        $names = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;
            if (is_dir($path)) {
                $names = array_merge($names, self::filesIn($path, $relative));
            } else {
                $names[] = $relative;
            }
        }
        return $names;
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (self::filesIn($dir) as $name) {
            @unlink($dir . '/' . $name);
        }
        // Les dossiers vides partent du plus profond au plus court.
        $dirs = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                $dirs[] = $item->getPathname();
            }
        }
        foreach ($dirs as $path) {
            @rmdir($path);
        }
        @rmdir($dir);
    }
}
