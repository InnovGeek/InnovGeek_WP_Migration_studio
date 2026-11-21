<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Original self-framed IGBK 1 archive format:
 * 7-byte magic "IGBK01\n", followed by records:
 * 4-byte network-order JSON-header length, JSON header, payload bytes.
 * Header: {kind,payload_size,sha256,...}. Each payload has a SHA-256.
 * This format is independent from proprietary .wpress archives.
 */
final class IGBK_Engine {
    const MAGIC = "IGBK01\n";
    const CHUNK = 1048576; // Memory/HTTP chunk only, never a backup size limit.
    const FILE_CHUNK = 8 * self::CHUNK; // Stream 8 MiB at a time; old 4 MiB records remain valid.
    const DB_MIN_ROWS = 32; // Start conservatively; the batch size adapts to real row sizes.
    const DB_MAX_ROWS = 1536;
    const DB_TARGET_BYTES = 3 * self::CHUNK; // Adaptive ~2 MiB SQL records, bounded for shared hosting.
    const MAX_HEADER = 32768;
    // Pack tiny files together: fewer archive records, JSON headers, seeks,
    // and checksums without changing the integrity of legacy records.
    const SMALL_FILE_MAX = 65536;
    const BUNDLE_MAX_FILES = 48;
    const BUNDLE_MAX_BYTES = 2 * self::CHUNK;
    const BUNDLE_MAX_PATH = 240;
    const TRASH_HOOK = 'igbk_trash_cleanup';
    const TRASH_PENDING_OPTION = 'igbk_trash_cleanup_pending';
    // Restore throughput: fewer browser/AJAX round trips; short, bounded PHP ticks.
    const RESTORE_BUDGET = 160 * self::CHUNK;
    const RESTORE_RECORDS = 5000;
    const RESTORE_SECONDS = 4.5;
    const VERIFY_BUDGET = 160 * self::CHUNK;
    const VERIFY_SECONDS = 4.5;
    // Batch SQL inserts within conservative MySQL max_allowed_packet limits.
    const SQL_RESTORE_BATCH_ROWS = 256;
    const SQL_RESTORE_BATCH_BYTES = 512 * 1024;

    public static function storage() {
        static $cached = null;
        if ($cached !== null) { return $cached; }
        if (defined('INNOVGEEK_BACKUP_DIR') && INNOVGEEK_BACKUP_DIR) {
            $base = rtrim((string) INNOVGEEK_BACKUP_DIR, '/\\');
        } else {
            $root = realpath(ABSPATH);
            $doc = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
            $parent = dirname($root ?: ABSPATH);
            // If WordPress is in a subfolder below DOCUMENT_ROOT, prefer a sibling of the webroot.
            if ($doc && self::path_inside($parent, $doc)) {
                $parent = dirname($doc);
            }
            $salt = defined('AUTH_SALT') ? AUTH_SALT : (defined('NONCE_SALT') ? NONCE_SALT : DB_NAME);
            $base = $parent . '/.innovgeek-backups-' . substr(hash('sha256', $salt . ABSPATH), 0, 16);
        }
        $absolute = self::absolute_path($base);
        if (!$absolute) { throw new RuntimeException('Le chemin de stockage doit être absolu.'); }
        // Never save archives in a served directory; .htaccess is insufficient for Nginx.
        $doc = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
        if (!$doc) {
            $doc = realpath(ABSPATH);
        }
        $existing = realpath($base);
        $effective = $existing ?: $base;
        if (self::path_inside($effective, $doc)) {
            throw new RuntimeException('Stockage non sécurisé : le dossier est sous la racine Web. Configurez INNOVGEEK_BACKUP_DIR hors de DOCUMENT_ROOT.');
        }
        if (!is_dir($base) && !wp_mkdir_p($base)) {
            throw new RuntimeException('Impossible de créer le stockage privé. Définissez INNOVGEEK_BACKUP_DIR dans wp-config.php vers un dossier accessible en écriture hors de la racine Web.');
        }
        $canonical = realpath($base);
        if (!$canonical || self::path_inside($canonical, $doc)) {
            throw new RuntimeException('Le dossier de sauvegarde doit être physiquement en dehors de la racine Web.');
        }
        @chmod($base, 0700);
        foreach (array('archives', 'jobs', 'trash') as $dir) {
            if (!is_dir($base . '/' . $dir) && !wp_mkdir_p($base . '/' . $dir)) {
                throw new RuntimeException('Impossible de créer le dossier de travail ' . $dir);
            }
            @chmod($base . '/' . $dir, 0700);
        }
        // Defense in depth, even for custom locations.
        foreach (array($base, $base . '/archives', $base . '/jobs', $base . '/trash') as $dir) {
            if (!file_exists($dir . '/.htaccess')) { @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n"); }
            if (!file_exists($dir . '/web.config')) { @file_put_contents($dir . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><security><authorization><remove users="*" roles="" verbs=""/><add accessType="Deny" users="*" /></authorization></security></system.webServer></configuration>'); }
            if (!file_exists($dir . '/index.php')) { @file_put_contents($dir . '/index.php', "<?php http_response_code(403); exit;\n"); }
        }
        $cached = $base;
        return $cached;
    }

    private static function absolute_path($path) {
        return (bool) preg_match('~^(?:/|[A-Za-z]:[/\\\\])~', $path);
    }

    private static function path_inside($candidate, $base) {
        $candidate = str_replace('\\', '/', rtrim($candidate, '/\\'));
        $base = str_replace('\\', '/', rtrim($base, '/\\'));
        if (DIRECTORY_SEPARATOR === '\\') { $candidate = strtolower($candidate); $base = strtolower($base); }
        return $candidate === $base || strpos($candidate, $base . '/') === 0;
    }

    public static function list_archives() {
        $folder = self::storage() . '/archives/';
        $rows = array();
        foreach (glob($folder . '*.json') ?: array() as $file) {
            $meta = json_decode((string) @file_get_contents($file), true);
            if (!is_array($meta) || empty($meta['id']) || !self::valid_id($meta['id'])) { continue; }
            $archive = $folder . $meta['id'] . '.igbk';
            if (!is_file($archive)) { continue; }
            $meta['bytes'] = filesize($archive);
            $rows[] = $meta;
        }
        usort($rows, static function ($a, $b) { return strcmp($b['created'] ?? '', $a['created'] ?? ''); });
        return $rows;
    }

    private static function valid_id($id) { return is_string($id) && (bool) preg_match('/\A[a-f0-9]{24}\z/', $id); }
    private static function new_id() { return bin2hex(random_bytes(12)); }
    private static function job_dir($id) {
        if (!self::valid_id($id)) { throw new RuntimeException('Identifiant de tâche invalide.'); }
        return self::storage() . '/jobs/' . $id;
    }
    private static function job_file($id) { return self::job_dir($id) . '/state.json'; }

    private static function save($state) {
        $path = self::job_file($state['id']);
        $json = wp_json_encode($state, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $tmp = $path . '.new';
        if (file_put_contents($tmp, $json, LOCK_EX) === false || !@rename($tmp, $path)) {
            throw new RuntimeException('Impossible de sauvegarder le suivi de tâche.');
        }
        return $state;
    }
    public static function load($id) {
        $file = self::job_file($id);
        if (!is_file($file)) { throw new RuntimeException('Tâche introuvable.'); }
        $state = json_decode((string) file_get_contents($file), true);
        if (!is_array($state) || ($state['id'] ?? '') !== $id) { throw new RuntimeException('État de tâche illisible.'); }
        return $state;
    }
    private static function create_job($initial) {
        $id = self::new_id();
        $dir = self::job_dir($id);
        if (!wp_mkdir_p($dir)) { throw new RuntimeException('Impossible de créer le dossier de tâche.'); }
        @chmod($dir, 0700);
        $initial['id'] = $id;
        $initial['created'] = gmdate('c');
        $initial['error'] = '';
        $initial['percent'] = 0;
        self::save($initial);
        return $initial;
    }
    public static function start_backup($scope, $trigger = 'manual') {
        if (is_multisite()) { throw new RuntimeException('Les installations multisites ne sont pas prises en charge dans cette version.'); }
        if (realpath(WP_CONTENT_DIR) !== realpath(rtrim(ABSPATH, '/\\') . '/wp-content')) {
            throw new RuntimeException('Un dossier wp-content personnalisé n’est pas encore pris en charge.');
        }
        // Exactly one mode: complete site (all files under ABSPATH) and WordPress database.
        $scope = 'full';
        $state = self::create_job(array(
            'type' => 'backup', 'phase' => 'scan', 'scope' => $scope,
            'trigger' => $trigger === 'scheduled' ? 'scheduled' : 'manual',
            'dirs' => array(''), 'scan_active' => null,
            'files_count' => 0, 'files_total' => 0, 'file_bytes_done' => 0, 'files_finished' => 0,
            'queue_offset' => 0, 'queue_size' => 0, 'scan_list_size' => 0, 'current_file' => null, 'archive_offset' => strlen(self::MAGIC),
            'db_tables' => array(), 'db_index' => 0, 'db_offset' => 0, 'db_schema_written' => false,
            'db_keycol' => null, 'db_keyvalue' => null, 'db_keytype' => null, 'db_limit' => self::DB_MIN_ROWS, 'scan_fast' => true,
            'records' => 0, 'message' => 'Analyse des fichiers…',
        ));
        if (file_put_contents(self::job_dir($state['id']) . '/data.igbk.tmp', self::MAGIC) !== strlen(self::MAGIC)) {
            throw new RuntimeException('Impossible de créer le fichier de sauvegarde.');
        }
        touch(self::job_dir($state['id']) . '/queue.ndjson');
        return self::public_state($state);
    }
    public static function start_upload($filename, $size) {
        if (!is_string($filename) || strtolower(substr($filename, -5)) !== '.igbk') {
            throw new RuntimeException('Format non pris en charge. Importez une archive .igbk créée par Backup Studio (pas .wpress).');
        }
        if (is_multisite()) { throw new RuntimeException('Restauration multisite non prise en charge.'); }
        if ($size <= 0) { throw new RuntimeException('Taille de fichier invalide.'); }
        $state = self::create_job(array('type' => 'import', 'phase' => 'receiving', 'name' => sanitize_file_name($filename), 'expected' => (int) $size, 'received' => 0, 'message' => 'Réception du fichier…'));
        if (file_put_contents(self::job_dir($state['id']) . '/upload.igbk.part', '') === false) { throw new RuntimeException('Impossible de créer le fichier importé.'); }
        return self::public_state($state);
    }
    /** Resume an incomplete upload only when the name, length and first 64 KiB match.
     *  Also supports partially uploaded jobs from V1.2.0 (which had no browser persistence).
     */
    public static function start_or_resume_upload($filename, $size, $fingerprint = '') {
        $safe_name = sanitize_file_name((string) $filename);
        $size = (int) $size;
        if (strtolower(substr($safe_name, -5)) !== '.igbk' || $size <= 0) {
            throw new RuntimeException('Fichier .igbk ou taille d’archive invalide.');
        }
        if (is_multisite()) { throw new RuntimeException('Restauration multisite non prise en charge.'); }
        if (is_string($fingerprint) && preg_match('/\A[a-f0-9]{64}\z/', $fingerprint)) {
            $matches = array();
            foreach (glob(self::storage() . '/jobs/*/state.json') ?: array() as $statefile) {
                $state = json_decode((string) @file_get_contents($statefile), true);
                if (!is_array($state) || !self::valid_id($state['id'] ?? null) ||
                    ($state['type'] ?? '') !== 'import' || ($state['phase'] ?? '') !== 'receiving' ||
                    ($state['name'] ?? '') !== $safe_name || (int) ($state['expected'] ?? 0) !== $size ||
                    (int) ($state['received'] ?? 0) < min(65536, $size)) { continue; }
                $path = self::job_dir($state['id']) . '/upload.igbk.part';
                if (!is_file($path) || filesize($path) < $state['received']) { continue; }
                $fp = @fopen($path, 'rb');
                if (!$fp) { continue; }
                $bytes = fread($fp, min(65536, $size));
                fclose($fp);
                if ($bytes === false || !hash_equals($fingerprint, hash('sha256', $bytes))) { continue; }
                $matches[] = $state;
            }
            if ($matches) {
                usort($matches, static function ($a, $b) {
                    return strcmp($b['created'] ?? '', $a['created'] ?? '');
                });
                return self::public_state($matches[0]);
            }
        }
        return self::start_upload($filename, $size);
    }

    public static function upload_chunk($id, $offset, $file) {
        $lockfile = self::job_dir($id) . '/upload.lock';
        $lock = @fopen($lockfile, 'c+');
        if (!$lock || !flock($lock, LOCK_EX)) {
            if ($lock) { fclose($lock); }
            throw new RuntimeException('Verrou de transfert indisponible.');
        }
        try {
            // Read the checkpoint only after acquiring the lock; a timed-out
            // request may still be completing the previous block.
            $state = self::load($id);
            if ($state['type'] !== 'import' || $state['phase'] !== 'receiving') { throw new RuntimeException('Cette tâche n’accepte pas de données.'); }
            if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                throw new RuntimeException('Bloc de fichier non reçu. Vérifiez post_max_size et upload_max_filesize.');
            }
            $length = (int) ($file['size'] ?? 0);
            if ($length < 1 || $length > 8 * self::CHUNK || $offset < 0 || $offset + $length > $state['expected']) {
                throw new RuntimeException('Taille du bloc invalide.');
            }
            $outpath = self::job_dir($id) . '/upload.igbk.part';
            // Idempotent retry, without accepting mismatching content.
            if ($offset < (int) $state['received'] && $offset + $length <= (int) $state['received']) {
                $previous = @fopen($outpath, 'rb');
                if (!$previous || fseek($previous, $offset) !== 0) {
                    if ($previous) { fclose($previous); }
                    throw new RuntimeException('Impossible de contrôler le bloc déjà reçu.');
                }
                $expected = hash_init('sha256');
                $remaining = $length;
                while ($remaining > 0) {
                    $part = fread($previous, min(self::CHUNK, $remaining));
                    if ($part === false || $part === '') { fclose($previous); throw new RuntimeException('Bloc ancien incomplet.'); }
                    hash_update($expected, $part);
                    $remaining -= strlen($part);
                }
                fclose($previous);
                if (!hash_equals(hash_final($expected), hash_file('sha256', $file['tmp_name']))) {
                    throw new RuntimeException('Le bloc renvoyé ne correspond pas aux données enregistrées.');
                }
                return self::public_state($state);
            }
            if ($offset !== (int) $state['received']) { throw new RuntimeException('Offset de transfert incorrect.'); }
            $fp = @fopen($outpath, 'c+b');
            if (!$fp) { throw new RuntimeException('Impossible d’écrire le fichier importé.'); }
            try {
                if (fseek($fp, $offset) !== 0) { throw new RuntimeException('Échec du positionnement d’écriture.'); }
                $source = @fopen($file['tmp_name'], 'rb');
                if (!$source) { throw new RuntimeException('Bloc temporaire inaccessible.'); }
                $copied = stream_copy_to_stream($source, $fp);
                fclose($source);
                if ($copied !== $length) { throw new RuntimeException('Écriture incomplète du bloc importé.'); }
                fflush($fp);
                $state['received'] += $length;
                $state['percent'] = (int) floor($state['received'] / $state['expected'] * 100);
                self::save($state);
            } finally { fclose($fp); }
            return self::public_state($state);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    /** Raw HTTP stream receiver. Avoids the PHP temporary upload directory entirely.
     *  Uses the existing upload.lock and state.json so old partial imports are resumable.
     *  Incomplete writes are rolled back to the last durable checkpoint.
     */
    public static function upload_chunk_raw($id, $offset, $length, $source) {
        if (!is_resource($source) || !self::valid_id($id) || !is_int($offset) || !is_int($length) ||
            $offset < 0 || $length < 1 || $length > 8 * self::CHUNK) {
            throw new RuntimeException('Bloc HTTP invalide.');
        }
        $lock = @fopen(self::job_dir($id) . '/upload.lock', 'c+');
        if (!$lock || !@flock($lock, LOCK_EX)) {
            if ($lock) { fclose($lock); }
            throw new RuntimeException('Verrou de transfert indisponible.');
        }
        try {
            $state = self::load($id);
            if (($state['type'] ?? '') !== 'import' || ($state['phase'] ?? '') !== 'receiving') {
                throw new RuntimeException('Cette tâche n’accepte pas de données.');
            }
            if ($offset + $length > (int) $state['expected']) {
                throw new RuntimeException('Bloc au-delà de la taille de l’archive.');
            }
            $confirmed = (int) $state['received'];
            $path = self::job_dir($id) . '/upload.igbk.part';
            if (!is_file($path)) { throw new RuntimeException('Archive partielle introuvable.'); }
            if ($offset < $confirmed && $offset + $length <= $confirmed) {
                // Lost HTTP response: compare bytes before confirming an already-written block.
                $stored = @fopen($path, 'rb');
                if (!$stored) { throw new RuntimeException('Impossible de vérifier le bloc existant.'); }
                try {
                    if (fseek($stored, $offset) !== 0) { throw new RuntimeException('Position de relecture incorrecte.'); }
                    $remaining = $length;
                    while ($remaining > 0) {
                        $take = min(65536, $remaining);
                        $input = '';
                        $prior = '';
                        while (strlen($input) < $take) {
                            $part = fread($source, $take - strlen($input));
                            if ($part === false || $part === '') { throw new RuntimeException('Bloc HTTP incomplet.'); }
                            $input .= $part;
                        }
                        while (strlen($prior) < $take) {
                            $part = fread($stored, $take - strlen($prior));
                            if ($part === false || $part === '') { throw new RuntimeException('Bloc de référence incomplet.'); }
                            $prior .= $part;
                        }
                        if (!hash_equals($prior, $input)) {
                            throw new RuntimeException('Le bloc renvoyé ne correspond pas aux données déjà reçues.');
                        }
                        $remaining -= $take;
                    }
                    if (fread($source, 1) !== '') { throw new RuntimeException('Données supplémentaires après le bloc.'); }
                } finally { fclose($stored); }
                return self::public_state($state);
            }
            if ($offset !== $confirmed) { throw new RuntimeException('Position de transfert incorrecte, demandez le dernier point reçu.'); }
            $out = @fopen($path, 'c+b');
            if (!$out) { throw new RuntimeException('Fichier d’archive importée non accessible.'); }
            try {
                // A previous request may have written half a block before timing out.
                if (!@ftruncate($out, $confirmed) || fseek($out, $confirmed) !== 0) {
                    throw new RuntimeException('Impossible de repositionner le fichier importé.');
                }
                $remaining = $length;
                while ($remaining > 0) {
                    $take = min(65536, $remaining);
                    $bytes = fread($source, $take);
                    if ($bytes === false || $bytes === '') { throw new RuntimeException('Bloc HTTP incomplet, réessai possible.'); }
                    $size = strlen($bytes);
                    $written = 0;
                    while ($written < $size) {
                        $n = fwrite($out, substr($bytes, $written));
                        if ($n === false || $n === 0) { throw new RuntimeException('Écriture du bloc impossible (disque plein ou accès refusé).'); }
                        $written += $n;
                    }
                    $remaining -= $size;
                }
                if (fread($source, 1) !== '') { throw new RuntimeException('Données supplémentaires après le bloc.'); }
                if (!fflush($out)) { throw new RuntimeException('Synchronisation du bloc impossible.'); }
                $state['received'] = $confirmed + $length;
                $state['percent'] = (int) floor($state['received'] / $state['expected'] * 100);
                self::save($state);
            } catch (Throwable $e) {
                // Never advance the checkpoint after a partially written request.
                @ftruncate($out, $confirmed);
                throw $e;
            } finally { fclose($out); }
            return self::public_state($state);
        } finally {
            @flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    public static function finish_upload($id) {
        $state = self::load($id);
        if (($state['type'] ?? '') !== 'import' || (int) ($state['received'] ?? 0) !== (int) ($state['expected'] ?? 0) || (int) ($state['expected'] ?? 0) < 1) {
            throw new RuntimeException('Le transfert est incomplet.');
        }
        if (($state['phase'] ?? '') === 'validating') {
            return self::public_state($state);
        }
        if (!in_array($state['phase'] ?? '', array('receiving', 'error'), true)) {
            throw new RuntimeException('Cette importation ne peut pas être relancée.');
        }
        $file = self::job_dir($id) . '/upload.igbk.part';
        if (!is_file($file) || (int) filesize($file) !== (int) $state['expected']) { throw new RuntimeException('La taille reçue ne correspond pas.'); }
        $state['phase'] = 'validating';
        $state['cursor'] = strlen(self::MAGIC);
        $state['validated_files'] = 0;
        $state['validated_tables'] = 0;
        $state['validation_file'] = null;
        $state['percent'] = 0;
        $state['error'] = '';
        $state['message'] = 'Vérification cryptographique de l’archive…';
        self::save($state);
        return self::public_state($state);
    }
    /** Locate an already-uploaded archive which failed during validation. */
    public static function latest_failed_import() {
        $latest = null;
        foreach (glob(self::storage() . '/jobs/*/state.json') ?: array() as $path) {
            $state = json_decode((string) @file_get_contents($path), true);
            if (!is_array($state) || !self::valid_id($state['id'] ?? null) ||
                ($state['type'] ?? '') !== 'import' || ($state['phase'] ?? '') !== 'error' ||
                (int) ($state['expected'] ?? 0) < 1 || (int) ($state['received'] ?? 0) !== (int) $state['expected']) { continue; }
            $part = self::job_dir($state['id']) . '/upload.igbk.part';
            if (!is_file($part) || (int) filesize($part) !== (int) $state['expected']) { continue; }
            if (!$latest || strcmp((string) ($state['created'] ?? ''), (string) ($latest['created'] ?? '')) > 0) { $latest = $state; }
        }
        return $latest ? self::public_state($latest) : null;
    }

    /** Retry SHA-256 validation without re-uploading the already-received archive. */
    public static function retry_import($id) {
        $state = self::load($id);
        if (($state['type'] ?? '') !== 'import' || ($state['phase'] ?? '') !== 'error' ||
            (int) ($state['expected'] ?? 0) < 1 || (int) ($state['expected'] ?? 0) !== (int) ($state['received'] ?? 0)) {
            throw new RuntimeException('Aucune importation complète récupérable pour cette tâche.');
        }
        $file = self::job_dir($id) . '/upload.igbk.part';
        if (!is_file($file) || (int) filesize($file) !== (int) $state['expected']) {
            throw new RuntimeException('L’archive temporaire est introuvable ou incomplète.');
        }
        $state['phase'] = 'validating';
        $state['cursor'] = strlen(self::MAGIC);
        $state['validated_files'] = 0;
        $state['validated_tables'] = 0;
        $state['validation_file'] = null;
        $state['percent'] = 0;
        $state['error'] = '';
        $state['message'] = 'Reprise de la validation SHA-256 (archive déjà transférée)…';
        return self::public_state(self::save($state));
    }

    public static function verify_existing($archive_id) {
        if (!self::valid_id($archive_id)) { throw new RuntimeException('Archive invalide.'); }
        $path = self::storage() . '/archives/' . $archive_id . '.igbk';
        if (!is_file($path)) { throw new RuntimeException('Archive introuvable.'); }
        $state = self::create_job(array(
            'type' => 'restore', 'phase' => 'validating', 'archive_id' => $archive_id,
            'cursor' => strlen(self::MAGIC), 'validated_files' => 0, 'validated_tables' => 0,
            'validation_file' => null, 'message' => 'Vérification cryptographique de l’archive…',
        ));
        return self::public_state($state);
    }

    /** Read-only progress endpoint: allows the browser to recover from a lost AJAX response. */
    public static function status($id) {
        return self::public_state(self::load($id));
    }

    /** Discover older V1.0.0 jobs, whose browsers did not persist the job ID. */
    public static function latest_pending_backup() {
        $jobs = glob(self::storage() . '/jobs/*/state.json') ?: array();
        $result = null;
        foreach ($jobs as $path) {
            $state = json_decode((string) @file_get_contents($path), true);
            if (!is_array($state) || !self::valid_id($state['id'] ?? null)
                || ($state['type'] ?? '') !== 'backup'
                || ($state['scope'] ?? '') !== 'full'
                || !in_array($state['phase'] ?? '', array('scan', 'database', 'files', 'finalize'), true)
                || !is_file(self::job_dir($state['id']) . '/data.igbk.tmp')) { continue; }
            if (!$result || strcmp($state['created'] ?? '', $result['created'] ?? '') > 0) { $result = $state; }
        }
        return $result ? self::public_state($result) : null;
    }

    public static function tick($id) {
        $lockpath = self::job_dir($id) . '/lock';
        $lock = fopen($lockpath, 'c+');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Cette tâche est déjà en cours. Réessayez.'); }
        try {
            $state = self::load($id);
            if (in_array($state['phase'], array('done', 'ready', 'error'), true)) { return self::public_state($state); }
            try {
                if ($state['type'] === 'backup') {
                    self::backup_tick($state);
                } elseif ($state['phase'] === 'validating') {
                    self::validate_tick($state);
                } elseif ($state['phase'] === 'restoring') {
                    self::restore_tick($state);
                } else { throw new RuntimeException('État de tâche inconnu.'); }
            } catch (Throwable $e) {
                $state['phase'] = 'error';
                $state['error'] = $e->getMessage();
                $state['message'] = 'Échec : ' . $e->getMessage();
            }
            unset($state['timings']); // Older job checkpoints may include removed diagnostic fields.
            self::save($state);
            return self::public_state($state);
        } finally {
            flock($lock, LOCK_UN); fclose($lock);
        }
    }

    private static function backup_tick(&$s) {
        $fp = fopen(self::job_dir($s['id']) . '/data.igbk.tmp', 'c+b');
        if (!$fp) { throw new RuntimeException('Archive temporaire inaccessible.'); }
        try {
            if (!ftruncate($fp, (int) $s['archive_offset']) || fseek($fp, (int) $s['archive_offset']) !== 0) {
                throw new RuntimeException('Impossible de reprendre l’écriture de l’archive.');
            }
            switch ($s['phase']) {
                case 'scan':
                    if (!empty($s['scan_fast'])) { self::scan_tick_fast($s, $fp); }
                    else { self::scan_tick($s, $fp); }
                    break;
                case 'database':
                    // Many small SQL operations per AJAX request avoid spending most of
                    // the runtime in WordPress bootstrapping and browser round trips.
                    $deadline = microtime(true) + 4.5;
                    for ($i = 0; $i < 128 && $s['phase'] === 'database'; $i++) {
                        self::database_tick($s, $fp);
                        if (microtime(true) >= $deadline) { break; }
                    }
                    break;
                case 'files': self::files_tick($s, $fp); break;
                case 'finalize':
                    self::write_record($fp, array('kind' => 'end'), '');
                    $s['records']++;
                    $s['phase'] = 'done';
                    $s['percent'] = 100;
                    $s['message'] = 'Sauvegarde terminée.';
                    break;
            }
            $s['archive_offset'] = ftell($fp);
            fflush($fp);
        } finally { fclose($fp); }
        if ($s['phase'] === 'done') {
            $id = $s['id'];
            $source = self::job_dir($id) . '/data.igbk.tmp';
            $destination = self::storage() . '/archives/' . $id . '.igbk';
            if (!@rename($source, $destination)) { throw new RuntimeException('Impossible de finaliser l’archive.'); }
            self::write_manifest($id, array(
                'created' => gmdate('c'), 'origin' => home_url('/'), 'scope' => $s['scope'],
                'files' => $s['files_count'], 'type' => (($s['trigger'] ?? 'manual') === 'scheduled' ? 'scheduled' : 'manual'),
                'label' => (($s['trigger'] ?? 'manual') === 'scheduled' ? 'Sauvegarde programmée du site complet' : 'Sauvegarde du site complet'),
            ));
            @unlink(self::job_dir($id) . '/queue.ndjson');
        }
    }
    private static function scan_tick(&$s, $fp) {
        $base = rtrim(ABSPATH, '/\\');
        $processed = 0;
        $queue = fopen(self::job_dir($s['id']) . '/queue.ndjson', 'c+b');
        if (!$queue) { throw new RuntimeException('File d’inventaire inaccessible.'); }
        if (!ftruncate($queue, (int) $s['queue_size']) || fseek($queue, (int) $s['queue_size']) !== 0) {
            fclose($queue); throw new RuntimeException('Impossible de reprendre l’inventaire.');
        }
        try {
            while ($processed < 250) {
                if ($s['scan_active'] === null) {
                    if (empty($s['dirs'])) { break; }
                    $s['scan_active'] = array('dir' => array_pop($s['dirs']), 'offset' => 0);
                }
                $relative = $s['scan_active']['dir'];
                if (!self::valid_rel($relative, true)) { throw new RuntimeException('Chemin de scan invalide.'); }
                $path = $base . ($relative === '' ? '' : '/' . $relative);
                $real = realpath($path);
                if (is_link($path) || !$real || !self::path_inside($real, $base)) {
                    throw new RuntimeException('Dossier non sécurisé ou lien symbolique : ' . $relative);
                }
                $items = @scandir($path);
                if ($items === false) { throw new RuntimeException('Répertoire illisible : ' . $relative); }
                $items = array_values(array_diff($items, array('.', '..')));
                sort($items, SORT_STRING);
                $count = count($items);
                $batch = array_slice($items, (int) $s['scan_active']['offset'], 250 - $processed);
                foreach ($batch as $item) {
                    $processed++;
                    $child = ($relative === '' ? '' : $relative . '/') . $item;
                    $full = $base . '/' . $child;
                    if (!self::valid_rel($child) || is_link($full) || self::skip_file($child, $full)) { continue; }
                    if (is_dir($full)) { $s['dirs'][] = $child; continue; }
                    if (!is_file($full)) { continue; }
                    if (!is_readable($full)) { throw new RuntimeException('Fichier inaccessible : ' . $child); }
                    $size = filesize($full);
                    if ($size === false) { throw new RuntimeException('Impossible de lire la taille : ' . $child); }
                    $line = wp_json_encode(array('path' => $child, 'size' => $size, 'mtime' => (int) @filemtime($full)), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
                    if (fwrite($queue, $line) !== strlen($line)) { throw new RuntimeException('Écriture de l’inventaire incomplète.'); }
                    $s['files_total'] += $size;
                    $s['files_count']++;
                }
                $s['scan_active']['offset'] += count($batch);
                if ($s['scan_active']['offset'] >= $count) { $s['scan_active'] = null; }
                if (!$batch && $s['scan_active'] !== null) { break; }
            }
        } finally { $s['queue_size'] = ftell($queue); fclose($queue); }
        $s['percent'] = 4;
        $s['message'] = sprintf('Inventaire : %s fichiers repérés', number_format_i18n($s['files_count']));
        if ($s['scan_active'] === null && empty($s['dirs'])) {
            self::complete_scan($s, $fp);
        }
    }

    /** Fast, crash-safe inventory: persistent append-only per-directory names.
     * On retry, truncate both append-only files to the last committed checkpoint.
     */
    private static function scan_tick_fast(&$s, $fp) {
        $base = rtrim(ABSPATH, '/\\');
        $queue = fopen(self::job_dir($s['id']) . '/queue.ndjson', 'c+b');
        if (!$queue) { throw new RuntimeException('Inventaire de fichiers inaccessible.'); }
        $listfile = self::job_dir($s['id']) . '/dir-scan.ndjson';
        $listing = @fopen($listfile, 'c+b');
        if (!$listing) { fclose($queue); throw new RuntimeException('Curseur de scan inaccessible.'); }
        $processed = 0;
        $deadline = microtime(true) + 3.5;
        if (!ftruncate($queue, (int) $s['queue_size']) || fseek($queue, (int) $s['queue_size']) !== 0
            || !ftruncate($listing, (int) ($s['scan_list_size'] ?? 0))) {
            fclose($queue); fclose($listing);
            throw new RuntimeException('Impossible de restaurer les curseurs de l’inventaire.');
        }
        try {
            while ($processed < 8000 && microtime(true) < $deadline) {
                if ($s['scan_active'] === null) {
                    if (empty($s['dirs'])) { break; }
                    $relative = array_pop($s['dirs']);
                    if (!self::valid_rel($relative, true)) { throw new RuntimeException('Chemin de scan invalide.'); }
                    $path = $base . ($relative === '' ? '' : '/' . $relative);
                    $real = realpath($path);
                    if (is_link($path) || !$real || !self::path_inside($real, $base)) {
                        throw new RuntimeException('Dossier non sécurisé ou lié symboliquement : ' . $relative);
                    }
                    $items = @scandir($path);
                    if ($items === false) { throw new RuntimeException('Répertoire illisible : ' . $relative); }
                    $items = array_values(array_diff($items, array('.', '..')));
                    sort($items, SORT_STRING);
                    if (fseek($listing, 0, SEEK_END) !== 0) { throw new RuntimeException('Inventaire de scan inaccessible.'); }
                    $begin = ftell($listing);
                    foreach ($items as $name) {
                        $line = wp_json_encode($name, JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
                        if (fwrite($listing, $line) !== strlen($line)) { throw new RuntimeException('Erreur d’écriture du scan.'); }
                    }
                    $end = ftell($listing);
                    $s['scan_active'] = array('dir' => $relative, 'cursor' => $begin, 'end' => $end);
                    $s['scan_list_size'] = $end;
                }
                $relative = $s['scan_active']['dir'];
                if ((int) $s['scan_active']['cursor'] >= (int) $s['scan_active']['end']) {
                    $s['scan_active'] = null;
                    continue;
                }
                if (fseek($listing, (int) $s['scan_active']['cursor']) !== 0) {
                    throw new RuntimeException('Position du curseur de scan incorrecte.');
                }
                while ($processed < 8000 && microtime(true) < $deadline
                       && $s['scan_active']['cursor'] < $s['scan_active']['end']) {
                    $line = fgets($listing);
                    if ($line === false) { throw new RuntimeException('Inventaire de répertoire tronqué.'); }
                    $s['scan_active']['cursor'] = ftell($listing);
                    if ($s['scan_active']['cursor'] > $s['scan_active']['end']) { throw new RuntimeException('Position de scan incohérente.'); }
                    $item = json_decode($line, true);
                    if (!is_string($item) || $item === '') { throw new RuntimeException('Inventaire de répertoire corrompu.'); }
                    $processed++;
                    $child = ($relative === '' ? '' : $relative . '/') . $item;
                    $full = $base . '/' . $child;
                    if (!self::valid_rel($child) || is_link($full) || self::skip_file($child, $full)) { continue; }
                    if (is_dir($full)) { $s['dirs'][] = $child; continue; }
                    if (!is_file($full)) { continue; }
                    if (!is_readable($full)) { throw new RuntimeException('Fichier inaccessible : ' . $child); }
                    $size = filesize($full);
                    if ($size === false) { throw new RuntimeException('Taille illisible : ' . $child); }
                    $record = wp_json_encode(array('path' => $child, 'size' => $size, 'mtime' => (int) @filemtime($full)), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
                    if (fwrite($queue, $record) !== strlen($record)) { throw new RuntimeException('Inventaire de fichiers incomplet.'); }
                    $s['files_total'] += $size;
                    $s['files_count']++;
                }
                if ($s['scan_active']['cursor'] >= $s['scan_active']['end']) { $s['scan_active'] = null; }
            }
        } finally {
            $s['queue_size'] = ftell($queue);
            fflush($listing);
            fclose($listing);
            fclose($queue);
        }
        $s['percent'] = 4;
        $s['message'] = sprintf('Inventaire rapide : %s fichiers repérés', number_format_i18n($s['files_count']));
        if ($s['scan_active'] === null && empty($s['dirs'])) { self::complete_scan($s, $fp); }
    }

    private static function complete_scan(&$s, $fp) {
            $s['phase'] = 'database';
            global $wpdb;
            $all = $wpdb->get_results('SHOW FULL TABLES', ARRAY_N);
            if ($wpdb->last_error) { throw new RuntimeException('Lecture des tables MySQL impossible : ' . $wpdb->last_error); }
            foreach ($all as $item) {
                $name = (string) $item[0];
                if (($item[1] ?? '') === 'BASE TABLE' && strpos($name, $wpdb->prefix) === 0 && self::valid_table($name)) {
                    $s['db_tables'][] = $name;
                }
            }
            if (empty($s['db_tables'])) { throw new RuntimeException('Aucune table WordPress trouvée dans la base de données.'); }
            self::write_record($fp, array(
                'kind' => 'meta', 'format' => 1, 'site' => untrailingslashit(home_url('/')),
                'db_prefix' => $wpdb->prefix, 'scope' => $s['scope'], 'created' => gmdate('c'),
                'plugin_version' => IGBK_VERSION,
            ), '');
            $s['records']++;
    }

    private static function valid_table($name) { return (bool) preg_match('/\A[A-Za-z0-9_]{1,64}\z/', $name); }
    private static function skip_file($relative, $full) {
        // scan_tick_fast already rejects symlinked files and validates each directory;
        // comparing their paths is enough here, avoiding realpath() per file.
        if (strpos('/' . $relative . '/', '/.git/') !== false || strpos('/' . $relative . '/', '/node_modules/') !== false) { return true; }
        if (preg_match('~^wp-content/(cache|upgrade|debug\.log)(?:/|$)~i', $relative)) { return true; }
        static $storage = null;
        if ($storage === null) { $storage = realpath(self::storage()); }
        if ($storage && self::path_inside($full, $storage)) { return true; }
        return false;
    }
    private static function database_tick(&$s, $fp) {
        global $wpdb;
        $max = count($s['db_tables']);
        if ($s['db_index'] >= $max) { $s['phase'] = 'files'; $s['message'] = 'Archivage des fichiers…'; return; }
        $table = $s['db_tables'][$s['db_index']];
        $quoted = '`' . $table . '`';
        if (!$s['db_schema_written']) {
            $ddl_result = $wpdb->get_row('SHOW CREATE TABLE ' . $quoted, ARRAY_N);
            if (!$ddl_result || empty($ddl_result[1])) { throw new RuntimeException('Schéma SQL inaccessible : ' . $table); }
            $ddl = (string) $ddl_result[1];
            self::write_record($fp, array('kind' => 'db_schema', 'table' => $table), $ddl);
            $s['records']++;
            $s['db_schema_written'] = true;
            // Keyset pagination uses a single integer primary key, where one exists.
            // It avoids scanning an ever-increasing OFFSET for large WordPress tables.
            $primary = self::keyset_primary_key($ddl);
            $s['db_keycol'] = $primary['column'] ?? null;
            $s['db_keytype'] = $primary['type'] ?? null;
            $s['db_keyvalue'] = null;
        }
        $limit = max(1, min(self::DB_MAX_ROWS, (int) ($s['db_limit'] ?? self::DB_MIN_ROWS)));
        // Existing V1.0.0 interrupted jobs have no cursor: safely retain their old
        // OFFSET strategy for the current table, switching at the next table.
        $key = $s['db_keycol'] ?? null;
        if ($key && !self::valid_table($key)) { throw new RuntimeException('Clé primaire SQL invalide.'); }
        if ($key) {
            $where = '';
            if (isset($s['db_keyvalue'])) {
                $value = (string) $s['db_keyvalue'];
                if (($s['db_keytype'] ?? 'number') === 'text') {
                    // Text PKs (e.g. wp_options.option_name) have an index too.
                    // Bind the cursor safely; this avoids O(n²) OFFSET rescans.
                    if (strlen($value) > 2048) { throw new RuntimeException('Curseur SQL texte trop long.'); }
                    $where = $wpdb->prepare(' WHERE `' . $key . '` > %s', $value);
                } else {
                    if (!preg_match('/\A-?[0-9]+\z/', $value)) { throw new RuntimeException('Curseur de pagination SQL invalide.'); }
                    $where = ' WHERE `' . $key . '` > ' . $value;
                }
            }
            $sql = 'SELECT * FROM ' . $quoted . $where . ' ORDER BY `' . $key . '` ASC LIMIT ' . $limit;
        } else {
            $sql = 'SELECT * FROM ' . $quoted . ' LIMIT ' . $limit . ' OFFSET ' . (int) $s['db_offset'];
        }
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($wpdb->last_error) { throw new RuntimeException('Échec export SQL ' . $table . ' : ' . $wpdb->last_error); }
        if ($rows) {
            $packed = array();
            foreach ($rows as $row) {
                $values = array();
                foreach ($row as $column => $value) { $values[$column] = $value === null ? null : base64_encode((string) $value); }
                $packed[] = $values;
            }
            $payload = wp_json_encode($packed, JSON_UNESCAPED_SLASHES);
            if ($payload === false) { throw new RuntimeException('Impossible d’encoder les données SQL.'); }
            self::write_record($fp, array('kind' => 'db_rows', 'table' => $table, 'rows' => count($rows)), $payload);
            $s['records']++;
            $s['db_offset'] += count($rows);
            // Adaptive row limit: small for BLOB-heavy tables, large for normal
            // WordPress tables. Prevents many tiny requests without large peaks.
            $average = strlen($payload) / max(1, count($rows));
            $s['db_limit'] = max(1, min(self::DB_MAX_ROWS, (int) floor(self::DB_TARGET_BYTES / max(1, $average))));
            if ($key) {
                $last = end($rows);
                $new_cursor = $last[$key] ?? null;
                if ($new_cursor === null || !is_scalar($new_cursor) ||
                    (($s['db_keytype'] ?? 'number') !== 'text' && !preg_match('/\A-?[0-9]+\z/', (string) $new_cursor))) {
                    throw new RuntimeException('Valeur de clé primaire SQL invalide : ' . $table);
                }
                $s['db_keyvalue'] = (string) $new_cursor;
            }
        }
        if (count($rows) < $limit) {
            $s['db_index']++;
            $s['db_offset'] = 0;
            $s['db_schema_written'] = false;
            $s['db_keycol'] = null;
            $s['db_keyvalue'] = null;
            $s['db_keytype'] = null;
            $s['db_limit'] = self::DB_MIN_ROWS;
        }
        $s['percent'] = 6 + (int) floor(22 * $s['db_index'] / max(1, $max));
        $s['message'] = 'Base de données : ' . min($s['db_index'] + 1, $max) . '/' . $max
            . ' tables — ' . ($s['db_index'] < $max ? $s['db_tables'][$s['db_index']] : 'terminé')
            . ' (' . number_format_i18n($s['db_offset']) . ' lignes)';
    }
    private static function keyset_primary_key($ddl) {
        // Single-column indexed primary keys only. Composite keys and collated
        // prefix indexes continue using OFFSET rather than risking skipped rows.
        if (!preg_match('/\bPRIMARY\s+KEY\s*\(\s*`([A-Za-z0-9_]+)`\s*\)/i', $ddl, $match)) { return null; }
        $column = $match[1];
        if (!preg_match('/(?:^|\n)\s*`' . preg_quote($column, '/') . '`\s+([A-Za-z]+)(?:\s*\(\s*\d+\s*\))?/i', $ddl, $def)) { return null; }
        $type = strtolower($def[1]);
        if (in_array($type, array('tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'), true)) {
            return array('column' => $column, 'type' => 'number');
        }
        if (in_array($type, array('varchar', 'char'), true)) {
            return array('column' => $column, 'type' => 'text');
        }
        return null;
    }
    private static function files_tick(&$s, $fp) {
        $queue = fopen(self::job_dir($s['id']) . '/queue.ndjson', 'rb');
        if (!$queue) { throw new RuntimeException('Inventaire de fichiers introuvable.'); }
        $root = rtrim(ABSPATH, '/\\');
        $budget = 160 * self::CHUNK;
        $nfiles = 0;
        $opened = null;
        $in = null;
        // Batching does not change the archive format or the verification model.
        // The time budget stays bounded for hosts with short PHP execution limits.
        $pending = '';
        $deadline = microtime(true) + 4.5;
        try {
            while ($budget > 0 && $nfiles < 5000 && microtime(true) < $deadline) {
                if ($s['current_file'] === null) {
                    if (ftell($queue) !== (int) $s['queue_offset'] &&
                        fseek($queue, (int) $s['queue_offset']) !== 0) { throw new RuntimeException('Inventaire corrompu.'); }
                    $line = fgets($queue);
                    if ($line === false) { $s['phase'] = 'finalize'; break; }
                    $record = json_decode($line, true);
                    if (!is_array($record) || !self::valid_rel($record['path'] ?? '')) { throw new RuntimeException('Fichier d’inventaire corrompu.'); }
                    // Keep the legacy two-record-per-file writer on all new archives.
                    // Bundles introduced in V1.2.9 could be slower on some hosts.
                    // The bundle reader stays available for V1.2.9 (and mixed) archives.
                    $s['queue_offset'] = ftell($queue);
                    $s['current_file'] = array('path' => $record['path'], 'size' => (int) $record['size'], 'mtime' => (int) $record['mtime'], 'offset' => 0);
                    self::write_file_record($fp, $pending, array('kind' => 'file_start', 'path' => $record['path'], 'size' => (int) $record['size'], 'mtime' => (int) $record['mtime']), '');
                    $s['records']++;
                    if ((int) $record['size'] === 0) { $s['current_file'] = null; $nfiles++; if (isset($s['files_finished'])) { $s['files_finished']++; } continue; }
                }
                $file = $s['current_file'];
                $full = $root . '/' . $file['path'];
                if ($opened !== $full) {
                    if ($in) { fclose($in); }
                    if (is_link($full)) { throw new RuntimeException('Fichier devenu lien symbolique : ' . $file['path']); }
                    $in = @fopen($full, 'rb');
                    if (!$in) { throw new RuntimeException('Lecture impossible : ' . $file['path']); }
                    $opened = $full;
                }
                if (ftell($in) !== (int) $file['offset'] &&
                    fseek($in, (int) $file['offset']) !== 0) { throw new RuntimeException('Position du fichier invalide.'); }
                $length = min(self::FILE_CHUNK, $budget, $file['size'] - $file['offset']);
                $payload = self::read_exact($in, $length);
                if (strlen($payload) !== $length) { throw new RuntimeException('Le fichier a changé durant la sauvegarde : ' . $file['path']); }
                self::write_file_record($fp, $pending, array('kind' => 'file_chunk', 'path' => $file['path'], 'offset' => (int) $file['offset']), $payload);
                $s['records']++;
                $s['current_file']['offset'] += $length;
                $s['file_bytes_done'] += $length;
                $budget -= $length;
                if ($s['current_file']['offset'] === $s['current_file']['size']) {
                    $s['current_file'] = null;
                    $nfiles++;
                    if (isset($s['files_finished'])) { $s['files_finished']++; }
                }
            }
        } finally { if ($in) { fclose($in); } fclose($queue); }
        self::flush_file_records($fp, $pending);
        $byte_fraction = min(1.0, $s['file_bytes_done'] / max(1, $s['files_total']));
        if (isset($s['files_finished']) && (int) $s['files_count'] > 0) {
            $file_fraction = min(1.0, $s['files_finished'] / $s['files_count']);
            $s['percent'] = 30 + (int) floor(67 * (0.8 * $byte_fraction + 0.2 * $file_fraction));
            $s['message'] = 'Fichiers : ' . number_format_i18n($s['files_finished']) . ' / ' . number_format_i18n($s['files_count'])
                . ' · ' . size_format($s['file_bytes_done'], 1) . ' / ' . size_format($s['files_total'], 1);
        } else {
            // Jobs started on older versions did not track the finished-file count.
            $s['percent'] = 30 + (int) floor(67 * $byte_fraction);
            $s['message'] = 'Fichiers : ' . size_format($s['file_bytes_done'], 1) . ' / ' . size_format($s['files_total'], 1);
        }
    }
    /** The bounded header stores names/offsets; the payload is the exact bytes. */
    private static function bundlable($record) {
        return is_array($record) && self::valid_rel($record['path'] ?? '')
            && strlen($record['path']) <= self::BUNDLE_MAX_PATH
            && isset($record['size']) && is_numeric($record['size'])
            && (int) $record['size'] >= 0 && (int) $record['size'] <= self::SMALL_FILE_MAX;
    }

    /** Bundle consecutive tiny files, using the same durable archive+queue
     * checkpoint as older file records. A large file stops the bundle without
     * consuming its inventory entry. */
    private static function bundle_files_tick(&$s, $fp, $queue, $root, &$budget, &$nfiles, $deadline) {
        if (fseek($queue, (int) $s['queue_offset']) !== 0) { throw new RuntimeException('Inventaire corrompu.'); }
        $files = array();
        $payload = '';
        $size = 0;
        $cursor = (int) $s['queue_offset'];
        while (count($files) < self::BUNDLE_MAX_FILES && microtime(true) < $deadline) {
            $line = fgets($queue);
            if ($line === false) { break; }
            $record = json_decode($line, true);
            if (!is_array($record) || !self::valid_rel($record['path'] ?? '')) {
                throw new RuntimeException('Inventaire de fichiers corrompu.');
            }
            if (!self::bundlable($record)) { break; }
            $length = (int) $record['size'];
            if ($size + $length > min(self::BUNDLE_MAX_BYTES, $budget)) { break; }
            $full = $root . '/' . $record['path'];
            if (is_link($full)) { throw new RuntimeException('Fichier devenu lien symbolique : ' . $record['path']); }
            $bytes = @file_get_contents($full);
            if ($bytes === false || strlen($bytes) !== $length) {
                throw new RuntimeException('Le fichier a changé pendant la sauvegarde : ' . $record['path']);
            }
            $files[] = array('path' => $record['path'], 'size' => $length,
                'mtime' => (int) $record['mtime'], 'offset' => $size);
            $payload .= $bytes;
            $size += $length;
            $cursor = ftell($queue);
        }
        if (!$files) { return false; }
        self::write_record($fp, array('kind' => 'file_bundle', 'files' => $files), $payload);
        $s['queue_offset'] = $cursor;
        $s['file_bytes_done'] += $size;
        $s['records']++;
        $budget -= $size;
        $nfiles += count($files);
        return true;
    }

    /** Strict structural validation shared by the verifier and restorer.
     * Never trust unvalidated paths/offsets inside a bundle. */
    private static function bundle_entries($h) {
        $files = $h['files'] ?? null;
        if (!is_array($files) || !$files || count($files) > self::BUNDLE_MAX_FILES ||
            $h['payload_size'] > self::BUNDLE_MAX_BYTES) {
            throw new RuntimeException('Bloc de petits fichiers invalide.');
        }
        $offset = 0;
        $seen = array();
        foreach ($files as $file) {
            if (!is_array($file) || !self::valid_rel($file['path'] ?? '') ||
                !isset($file['size'], $file['offset'], $file['mtime']) ||
                !is_int($file['size']) || $file['size'] < 0 || $file['size'] > self::SMALL_FILE_MAX ||
                !is_int($file['offset']) || $file['offset'] !== $offset ||
                !is_int($file['mtime']) || isset($seen[$file['path']])) {
                throw new RuntimeException('Chemin ou taille invalide dans un bloc de fichiers.');
            }
            $seen[$file['path']] = true;
            $offset += $file['size'];
        }
        if ($offset !== $h['payload_size']) { throw new RuntimeException('Données manquantes dans le bloc de fichiers.'); }
        return $files;
    }

    private static function restore_file_bundle(&$s, $h, $payload) {
        $entries = self::bundle_entries($h);
        if (!empty($s['restore_file']) && $s['restore_file']['remaining'] > 0) {
            throw new RuntimeException('Bloc de fichiers rencontré avant la fin du fichier précédent.');
        }
        foreach ($entries as $entry) {
            $relative = $entry['path'];
            $allowed = strpos($relative, 'wp-content/') === 0
                && strpos($relative, 'wp-content/plugins/innovgeek-backup-studio/') !== 0
                && strpos($relative, 'wp-content/cache/') !== 0;
            if (!$allowed) { continue; }
            $target = rtrim(ABSPATH, '/\\') . '/' . $relative;
            self::safe_parent_directory(dirname($target));
            $tmp = $target . '.igbk-' . $s['id'] . '.tmp';
            if (is_link($target) || is_link($tmp)) { throw new RuntimeException('Lien symbolique interdit : ' . $relative); }
            $out = @fopen($tmp, 'wb');
            if (!$out) { throw new RuntimeException('Écriture impossible : ' . $relative); }
            try {
                self::write_all($out, substr($payload, $entry['offset'], $entry['size']));
            } finally { fclose($out); }
            if (!@rename($tmp, $target)) { throw new RuntimeException('Remplacement impossible : ' . $relative); }
            if ($entry['mtime'] > 0) { @touch($target, $entry['mtime']); }
            $s['restored_files']++;
        }
    }

    /** Coalesce ordinary IGBK records for small files into one larger disk write.
     *  Every record remains byte-for-byte compatible with previous versions;
     *  the reader/restorer and SHA-256 checks are unchanged. Larger files keep
     *  the tested direct writer to avoid extra copying of megabyte buffers.
     */
    private static function write_file_record($fp, &$pending, $header, $payload) {
        $payload_size = strlen($payload);
        if ($payload_size > 65536) {
            self::flush_file_records($fp, $pending);
            self::write_record($fp, $header, $payload);
            return;
        }
        $header['payload_size'] = $payload_size;
        $header['sha256'] = hash('sha256', $payload);
        $json = wp_json_encode($header, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!$json || strlen($json) > self::MAX_HEADER) {
            throw new RuntimeException('En-tête d’archive trop volumineux.');
        }
        $record = pack('N', strlen($json)) . $json . $payload;
        if (strlen($pending) + strlen($record) > self::CHUNK) {
            self::flush_file_records($fp, $pending);
        }
        $pending .= $record;
    }
    private static function flush_file_records($fp, &$pending) {
        if ($pending !== '') {
            self::write_all($fp, $pending);
            $pending = '';
        }
    }

    private static function write_record($fp, $header, $payload) {
        $header['payload_size'] = strlen($payload);
        $header['sha256'] = hash('sha256', $payload);
        $json = wp_json_encode($header, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!$json || strlen($json) > self::MAX_HEADER) { throw new RuntimeException('En-tête d’archive trop volumineux.'); }
        self::write_all($fp, pack('N', strlen($json)) . $json);
        if ($payload !== '') { self::write_all($fp, $payload); }
    }
    private static function write_all($fp, $bytes) {
        $offset = 0; $length = strlen($bytes);
        while ($offset < $length) {
            // Most local files accept the full string in a single native write.
            // Only allocate a suffix if the underlying filesystem wrote partially.
            $written = fwrite($fp, $offset === 0 ? $bytes : substr($bytes, $offset));
            if ($written === false || $written === 0) { throw new RuntimeException('Disque plein ou erreur d’écriture.'); }
            $offset += $written;
        }
    }
    private static function read_exact($fp, $bytes) {
        // stream_get_contents assembles its buffer in native code rather than
        // repeatedly concatenating 1 MiB strings in PHP for large file chunks.
        if ($bytes === 0) { return ''; }
        $data = stream_get_contents($fp, $bytes);
        return $data === false ? '' : $data;
    }
    private static function archive_for_job($s) {
        if ($s['type'] === 'import' && ($s['phase'] === 'validating')) {
            return self::job_dir($s['id']) . '/upload.igbk.part';
        }
        $archive_id = $s['archive_id'] ?? $s['id'];
        return self::storage() . '/archives/' . $archive_id . '.igbk';
    }
    private static function header_at($fp, $cursor, $length) {
        if ($cursor + 4 > $length || fseek($fp, $cursor) !== 0) { throw new RuntimeException('Archive tronquée (en-tête).'); }
        $bytes = self::read_exact($fp, 4);
        if (strlen($bytes) !== 4) { throw new RuntimeException('Archive tronquée (taille).'); }
        $header_size = unpack('N', $bytes)[1];
        if (!$header_size || $header_size > self::MAX_HEADER || $cursor + 4 + $header_size > $length) { throw new RuntimeException('Longueur d’en-tête invalide.'); }
        $header = json_decode(self::read_exact($fp, $header_size), true);
        if (!is_array($header) || !isset($header['kind'], $header['payload_size'], $header['sha256'])) { throw new RuntimeException('En-tête d’archive illisible.'); }
        $payload_size = $header['payload_size'];
        if (!is_int($payload_size) || $payload_size < 0 || $cursor + 4 + $header_size + $payload_size > $length) {
            throw new RuntimeException('Longueur de données invalide dans l’archive.');
        }
        $header['__start'] = $cursor + 4 + $header_size;
        $header['__next'] = $header['__start'] + $payload_size;
        return $header;
    }
    private static function check_payload($fp, $header) {
        if (fseek($fp, $header['__start']) !== 0) { throw new RuntimeException('Position archive invalide.'); }
        $h = hash_init('sha256');
        $remain = $header['payload_size'];
        while ($remain > 0) {
            $chunk = fread($fp, min(4 * self::CHUNK, $remain));
            if ($chunk === '' || $chunk === false) { throw new RuntimeException('Archive incomplète.'); }
            hash_update($h, $chunk);
            $remain -= strlen($chunk);
        }
        if (!hash_equals((string) $header['sha256'], hash_final($h))) { throw new RuntimeException('Échec de vérification SHA-256, archive endommagée.'); }
    }
    private static function read_payload($fp, $header) {
        if (fseek($fp, $header['__start']) !== 0) { throw new RuntimeException('Position archive invalide.'); }
        $data = self::read_exact($fp, $header['payload_size']);
        if (strlen($data) !== $header['payload_size']) { throw new RuntimeException('Données d’archive tronquées.'); }
        return $data;
    }
    private static function read_verified_payload($fp, $header) {
        $data = self::read_payload($fp, $header);
        if (!hash_equals((string) $header['sha256'], hash('sha256', $data))) {
            throw new RuntimeException('Échec de vérification SHA-256, archive endommagée.');
        }
        return $data;
    }
    private static function valid_rel($path, $empty_ok = false) {
        if ($path === '' && $empty_ok) { return true; }
        if (!is_string($path) || $path === '' || strlen($path) > 4096 || strpos($path, '\\') !== false || strpos($path, "\0") !== false || substr($path, 0, 1) === '/') { return false; }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || strpos($part, ':') !== false) { return false; }
        }
        return true;
    }
    private static function validate_tick(&$s) {
        $path = self::archive_for_job($s);
        $fp = @fopen($path, 'rb');
        if (!$fp) { throw new RuntimeException('Archive importée inaccessible.'); }
        try {
            $length = filesize($path);
            if ($length < strlen(self::MAGIC) + 4 || self::read_exact($fp, strlen(self::MAGIC)) !== self::MAGIC) { throw new RuntimeException('Format de sauvegarde incorrect. Seuls les fichiers .igbk sont acceptés.'); }
            $cursor = (int) $s['cursor'];
            $processed = 0;
            $deadline = microtime(true) + self::VERIFY_SECONDS;
            $records = 0;
            while ($processed < self::VERIFY_BUDGET && $records < 4000
                && ($records === 0 || microtime(true) < $deadline) && $s['phase'] === 'validating') {
                $h = self::header_at($fp, $cursor, $length);
                self::check_payload($fp, $h);
                $kind = $h['kind'];
                if ($cursor === strlen(self::MAGIC) && $kind !== 'meta') { throw new RuntimeException('Métadonnées initiales manquantes.'); }
                if ($kind === 'meta') {
                    if ($cursor !== strlen(self::MAGIC) || (int) ($h['format'] ?? 0) !== 1) { throw new RuntimeException('Version de format non prise en charge.'); }
                    $origin = untrailingslashit((string) ($h['site'] ?? ''));
                    $parts = wp_parse_url($origin);
                    if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), array('http', 'https'), true) || empty($parts['host']) || !empty($parts['user']) || !empty($parts['pass'])) {
                        throw new RuntimeException('URL d’origine invalide dans l’archive.');
                    }
                    if (empty($h['db_prefix']) || !self::valid_table($h['db_prefix'])) { throw new RuntimeException('Préfixe de base invalide.'); }
                    $target = untrailingslashit(home_url('/'));
                    $s['meta'] = array('site' => $origin, 'target_site' => $target,
                        'migration' => $origin !== $target, 'db_prefix' => $h['db_prefix'], 'scope' => $h['scope'] ?? 'content');
                } elseif ($kind === 'db_schema' || $kind === 'db_rows') {
                    if (empty($s['meta']) || !self::valid_table($h['table'] ?? '') || strpos($h['table'], $s['meta']['db_prefix']) !== 0) { throw new RuntimeException('Nom de table d’archive invalide.'); }
                    if ($kind === 'db_schema') { $s['validated_tables']++; }
                    if ($kind === 'db_rows' && $h['payload_size'] > 128 * self::CHUNK) { throw new RuntimeException('Bloc SQL exceptionnellement volumineux (mémoire PHP insuffisante possible).'); }
                } elseif ($kind === 'file_bundle') {
                    if (!empty($s['validation_file']) && $s['validation_file']['remaining'] !== 0) {
                        throw new RuntimeException('Bloc de petits fichiers avant la fin du fichier précédent.');
                    }
                    $s['validated_files'] += count(self::bundle_entries($h));
                } elseif ($kind === 'file_start') {
                    if (!self::valid_rel($h['path'] ?? '') || !isset($h['size']) || !is_int($h['size']) || $h['size'] < 0) { throw new RuntimeException('Chemin ou taille de fichier invalide.'); }
                    if ($s['validation_file'] && $s['validation_file']['remaining'] !== 0) { throw new RuntimeException('Données d’un fichier précédent manquantes.'); }
                    $s['validation_file'] = array('path' => $h['path'], 'remaining' => $h['size'], 'offset' => 0);
                    $s['validated_files']++;
                } elseif ($kind === 'file_chunk') {
                    $active = $s['validation_file'];
                    if (!$active || ($h['path'] ?? '') !== $active['path'] || ($h['offset'] ?? -1) !== $active['offset'] || $h['payload_size'] < 1 || $h['payload_size'] > self::FILE_CHUNK || $h['payload_size'] > $active['remaining']) {
                        throw new RuntimeException('Un fichier de l’archive possède des blocs non contigus.');
                    }
                    $s['validation_file']['offset'] += $h['payload_size'];
                    $s['validation_file']['remaining'] -= $h['payload_size'];
                } elseif ($kind === 'end') {
                    if ($h['payload_size'] !== 0 || $h['__next'] !== $length || !$s['validated_tables'] || ($s['validation_file'] && $s['validation_file']['remaining'] !== 0)) {
                        throw new RuntimeException('Archive non terminée ou structure incomplète.');
                    }
                    $s['phase'] = 'ready';
                    $s['percent'] = 100;
                    $s['message'] = 'Archive validée. Restauration prête.';
                    break;
                } else { throw new RuntimeException('Type d’enregistrement inconnu : ' . $kind); }
                $processed += $h['__next'] - $cursor;
                $cursor = $h['__next'];
                $records++;
                if ($cursor >= $length) { throw new RuntimeException('Fin d’archive absente.'); }
            }
            $s['cursor'] = $cursor;
            if ($s['phase'] !== 'ready') {
                $s['percent'] = (int) floor(100 * $cursor / max(1, $length));
                $s['message'] = 'Validation : ' . size_format($cursor, 1) . ' / ' . size_format($length, 1);
            }
        } finally { fclose($fp); }
        if ($s['phase'] === 'ready' && $s['type'] === 'import') {
            $id = $s['id'];
            if (!@rename(self::job_dir($id) . '/upload.igbk.part', self::storage() . '/archives/' . $id . '.igbk')) {
                throw new RuntimeException('Archive vérifiée mais impossible à enregistrer.');
            }
            $s['archive_id'] = $id;
            self::write_manifest($id, array('created' => gmdate('c'), 'origin' => $s['meta']['site'], 'scope' => $s['meta']['scope'], 'files' => $s['validated_files'], 'type' => 'import', 'label' => 'Archive importée'));
        }
    }
    public static function begin_restore($id, $confirmation) {
        $s = self::load($id);
        if ($s['phase'] !== 'ready') { throw new RuntimeException('Cette archive doit d’abord être validée.'); }
        if ($confirmation !== 'RESTAURER') { throw new RuntimeException('Confirmation RESTAURER manquante.'); }
        if (is_multisite()) { throw new RuntimeException('Restauration multisite non prise en charge.'); }
        if (realpath(WP_CONTENT_DIR) !== realpath(rtrim(ABSPATH, '/\\') . '/wp-content')) {
            throw new RuntimeException('wp-content personnalisé non pris en charge pour la restauration.');
        }
        $s['phase'] = 'restoring';
        $s['cursor'] = strlen(self::MAGIC);
        $s['restore_tables'] = array();
        $s['restore_file'] = null;
        $s['restored_files'] = 0;
        $s['percent'] = 0;
        $s['message'] = 'Restauration des données en préparation…';
        self::save($s);
        return self::public_state($s);
    }
    private static function stage_name($id, $table, $previous = false) {
        return 'igb_' . ($previous ? 'p' : 's') . '_' . substr($id, 0, 8) . '_' . substr(hash('sha256', $table), 0, 18);
    }
    private static function restore_tick(&$s) {
        global $wpdb;
        $path = self::archive_for_job($s);
        $fp = @fopen($path, 'rb');
        if (!$fp) { throw new RuntimeException('Archive de restauration inaccessible.'); }
        try {
            $length = filesize($path);
            if (self::read_exact($fp, strlen(self::MAGIC)) !== self::MAGIC) { throw new RuntimeException('Signature de sauvegarde invalide.'); }
            $cursor = (int) $s['cursor'];
            $budget = self::RESTORE_BUDGET;
            $records = 0;
            $deadline = microtime(true) + self::RESTORE_SECONDS;
            while ($budget > 0 && $records < self::RESTORE_RECORDS
                && ($records === 0 || microtime(true) < $deadline) && $s['phase'] === 'restoring') {
                $h = self::header_at($fp, $cursor, $length);
                $kind = $h['kind'];
                // One read per payload: checksum is still verified BEFORE database or filesystem writes.
                $payload = in_array($kind, array('db_schema', 'db_rows', 'file_chunk', 'file_bundle'), true)
                    ? self::read_verified_payload($fp, $h) : null;
                if ($payload === null) { self::check_payload($fp, $h); }
                if ($kind === 'meta') {
                    if (untrailingslashit((string) ($h['site'] ?? '')) !== ($s['meta']['site'] ?? '') ||
                        untrailingslashit(home_url('/')) !== ($s['meta']['target_site'] ?? '')) {
                        throw new RuntimeException('L’URL source ou l’URL destination a changé depuis la validation.');
                    }
                } elseif ($kind === 'db_schema') {
                    self::restore_schema($s, $h, $payload);
                } elseif ($kind === 'db_rows') {
                    self::restore_db_rows($s, $h, $payload);
                } elseif ($kind === 'file_bundle') {
                    self::restore_file_bundle($s, $h, $payload);
                } elseif ($kind === 'file_start') {
                    self::restore_file_start($s, $h);
                } elseif ($kind === 'file_chunk') {
                    self::restore_file_chunk($s, $h, $payload);
                } elseif ($kind === 'end') {
                    if ($h['__next'] !== $length || ($s['restore_file'] && $s['restore_file']['remaining'] !== 0)) { throw new RuntimeException('Fin d’archive incohérente.'); }
                    self::swap_tables($s);
                    $s['phase'] = 'done';
                    $s['percent'] = 100;
                    $s['message'] = 'Restauration achevée. Reconnectez-vous si nécessaire.';
                    break;
                } else { throw new RuntimeException('Enregistrement inconnu.'); }
                $budget -= ($h['__next'] - $cursor);
                $cursor = $h['__next'];
                $records++;
            }
            $s['cursor'] = $cursor;
            if ($s['phase'] !== 'done') {
                $s['percent'] = (int) floor(95 * $cursor / max(1, $length));
                $s['message'] = 'Restauration : ' . size_format($cursor, 1) . ' / ' . size_format($length, 1);
            }
        } finally { fclose($fp); }
        if ($s['phase'] === 'done') {
            wp_cache_flush();
        }
    }
    private static function restore_schema(&$s, $h, $payload) {
        global $wpdb;
        $source = (string) ($h['table'] ?? '');
        $prefix = (string) $s['meta']['db_prefix'];
        if (!self::valid_table($source) || strpos($source, $prefix) !== 0) { throw new RuntimeException('Schéma SQL d’origine invalide.'); }
        $target = $wpdb->prefix . substr($source, strlen($prefix));
        if (!self::valid_table($target)) { throw new RuntimeException('Table SQL de destination invalide.'); }
        if (isset($s['restore_tables'][$target])) { throw new RuntimeException('Schéma SQL dupliqué.'); }
        $stage = self::stage_name($s['id'], $target);
        $ddl = $payload;
        $count = 0;
        $ddl = preg_replace('/\ACREATE TABLE\s+`[^`]+`/i', 'CREATE TABLE `' . $stage . '`', $ddl, 1, $count);
        if ($count !== 1) { throw new RuntimeException('Schéma CREATE TABLE non reconnu : ' . $source); }
        // This only ever creates a staging table; existing production tables are not touched.
        static $known_tables = null;
        if ($known_tables === null) { $known_tables = $wpdb->get_col('SHOW TABLES'); }
        if (in_array($stage, $known_tables, true)) {
            // Retry after a crash when state was not committed; resume by re-creating staging table.
            $wpdb->query('DROP TABLE `' . $stage . '`');
        }
        $result = $wpdb->query($ddl);
        if ($result === false) { throw new RuntimeException('Création table temporaire impossible : ' . $wpdb->last_error); }
        $known_tables[] = $stage;
        $s['restore_tables'][$target] = $stage;
    }
    private static function restore_db_rows($s, $h, $payload) {
        global $wpdb;
        $source = (string) ($h['table'] ?? '');
        $prefix = (string) $s['meta']['db_prefix'];
        if (strpos($source, $prefix) !== 0) { throw new RuntimeException('Préfixe SQL incompatible.'); }
        $target = $wpdb->prefix . substr($source, strlen($prefix));
        if (empty($s['restore_tables'][$target])) { throw new RuntimeException('Données SQL sans table temporaire.'); }
        $rows = json_decode($payload, true);
        if (!is_array($rows)) { throw new RuntimeException('Bloc SQL JSON corrompu.'); }
        $batchColumns = null;
        $batchTuples = array();
        $batchValues = array();
        $batchBytes = 0;
        foreach ($rows as $packed) {
            if (!is_array($packed) || !$packed) { throw new RuntimeException('Ligne SQL corrompue.'); }
            $row = array();
            foreach ($packed as $col => $encoded) {
                if (!is_string($col) || !self::valid_table($col)) { throw new RuntimeException('Nom de colonne non valide.'); }
                $decoded = $encoded === null ? null : base64_decode($encoded, true);
                if ($encoded !== null && $decoded === false) { throw new RuntimeException('Donnée SQL base64 invalide.'); }
                // Rewrite domain URLs before the final atomic SQL table swap.
                // Safe for nested PHP-serialized values; unsupported encodings fail closed.
                $row[$col] = $decoded !== null && !empty($s['meta']['migration'])
                    ? IGBK_Migration::rewrite($decoded, $s['meta']['site'], $s['meta']['target_site']) : $decoded;
            }
            if (!empty($s['meta']['migration'])) {
                // Keep the installed WordPress location (which can differ from home_url).
                if ($source === $prefix . 'options') {
                    if (($row['option_name'] ?? '') === 'home') { $row['option_value'] = untrailingslashit(home_url('/')); }
                    if (($row['option_name'] ?? '') === 'siteurl') { $row['option_value'] = untrailingslashit(site_url('/')); }
                    if (isset($row['option_name']) && strpos($row['option_name'], $prefix) === 0 && $prefix !== $wpdb->prefix) {
                        $row['option_name'] = $wpdb->prefix . substr($row['option_name'], strlen($prefix));
                    }
                }
                if ($source === $prefix . 'usermeta' && isset($row['meta_key']) && $prefix !== $wpdb->prefix && strpos($row['meta_key'], $prefix) === 0) {
                    $row['meta_key'] = $wpdb->prefix . substr($row['meta_key'], strlen($prefix));
                }
            }
            // Group similarly shaped rows into one prepared INSERT statement.
            // Very large BLOB rows use the original single-row path instead.
            $columns = array_keys($row);
            $estimated = 0;
            foreach ($row as $value) { $estimated += $value === null ? 4 : strlen($value) + 8; }
            if ($estimated > self::SQL_RESTORE_BATCH_BYTES) {
                self::flush_sql_batch($s['restore_tables'][$target], $batchColumns, $batchTuples, $batchValues);
                if ($wpdb->insert($s['restore_tables'][$target], $row) === false) {
                    throw new RuntimeException('Insertion SQL volumineuse échouée : ' . $wpdb->last_error);
                }
                continue;
            }
            if ($batchColumns !== $columns || $batchBytes + $estimated > self::SQL_RESTORE_BATCH_BYTES
                || count($batchTuples) >= self::SQL_RESTORE_BATCH_ROWS) {
                self::flush_sql_batch($s['restore_tables'][$target], $batchColumns, $batchTuples, $batchValues);
                $batchBytes = 0;
            }
            $batchColumns = $columns;
            $tokens = array();
            foreach ($row as $value) {
                if ($value === null) { $tokens[] = 'NULL'; }
                else { $tokens[] = '%s'; $batchValues[] = $value; }
            }
            $batchTuples[] = '(' . implode(',', $tokens) . ')';
            $batchBytes += $estimated;
        }
        self::flush_sql_batch($s['restore_tables'][$target], $batchColumns, $batchTuples, $batchValues);
    }
    private static function flush_sql_batch($table, &$columns, &$tuples, &$values) {
        global $wpdb;
        if (!$tuples) { return; }
        $quoted = array();
        foreach ($columns as $col) {
            if (!self::valid_table($col)) { throw new RuntimeException('Nom de colonne SQL invalide.'); }
            $quoted[] = '`' . $col . '`';
        }
        $sql = 'INSERT INTO `' . $table . '` (' . implode(',', $quoted) . ') VALUES ' . implode(',', $tuples);
        if ($values) {
            $sql = $wpdb->prepare($sql, $values);
        }
        if (!is_string($sql) || $wpdb->query($sql) === false) {
            throw new RuntimeException('Insertion SQL groupée échouée : ' . $wpdb->last_error);
        }
        $columns = null;
        $tuples = array();
        $values = array();
    }
    private static function restore_file_start(&$s, $h) {
        if (!self::valid_rel($h['path'] ?? '') || !is_int($h['size'] ?? null) || $h['size'] < 0) { throw new RuntimeException('Chemin de restauration invalide.'); }
        if ($s['restore_file'] && $s['restore_file']['remaining'] > 0) { throw new RuntimeException('Fichier incomplet avant le suivant.'); }
        $relative = $h['path'];
        $allowed = strpos($relative, 'wp-content/') === 0
            && strpos($relative, 'wp-content/plugins/innovgeek-backup-studio/') !== 0
            && strpos($relative, 'wp-content/cache/') !== 0;
        $s['restore_file'] = array('path' => $relative, 'size' => $h['size'], 'remaining' => $h['size'], 'offset' => 0,
            'allowed' => $allowed, 'mtime' => (int) ($h['mtime'] ?? time()),
            'check_existing' => false, 'unchanged' => false);
        if ($allowed) {
            $target = rtrim(ABSPATH, '/\\') . '/' . $relative;
            self::safe_parent_directory(dirname($target));
            $temp = $target . '.igbk-' . $s['id'] . '.tmp';
            if (is_link($target) || is_link($temp)) { throw new RuntimeException('Liens symboliques interdits : ' . $relative); }
            // Single-chunk files: delay creating a temporary file until the
            // archive bytes have been compared cryptographically with the target.
            // Identical files can be kept in place, avoiding a write + rename.
            clearstatcache(true, $target);
            $same_size = $h['size'] <= self::FILE_CHUNK && is_file($target)
                && (int) @filesize($target) === $h['size']
                && (int) @filemtime($target) === (int) ($h['mtime'] ?? 0);
            $s['restore_file']['check_existing'] = $same_size;
            if (!$same_size) {
                $file = @fopen($temp, 'wb');
                if (!$file) { throw new RuntimeException('Destination de restauration non accessible : ' . $relative); }
                fclose($file);
            }
            if ($same_size && $h['size'] === 0) { $s['restore_file']['unchanged'] = true; }
        }
        if ($h['size'] === 0) { self::finish_restored_file($s); }
    }
    private static function safe_parent_directory($directory) {
        $base = rtrim(ABSPATH, '/\\') . '/wp-content';
        // Cache only within this one HTTP request. Previously seen paths are
        // still local to this restore tick; checks are repeated next request.
        static $verified = array();
        if (isset($verified[$directory])) { return; }
        if (!self::path_inside($directory, $base)) { throw new RuntimeException('Destination hors wp-content.'); }
        $parts = explode('/', substr(str_replace('\\', '/', $directory), strlen(str_replace('\\', '/', $base)) + 1));
        $path = $base;
        if (is_link($path)) { throw new RuntimeException('wp-content est un lien symbolique non pris en charge.'); }
        foreach ($parts as $part) {
            if ($part === '') { continue; }
            $path .= '/' . $part;
            if (is_link($path)) { throw new RuntimeException('Dossier lié symboliquement non autorisé : ' . $path); }
            if (!is_dir($path) && !@mkdir($path, 0755)) { throw new RuntimeException('Impossible de créer : ' . $path); }
        }
        $verified[$directory] = true;
    }
    private static function restore_file_chunk(&$s, $h, $payload) {
        $f = $s['restore_file'];
        if (!$f || ($h['path'] ?? '') !== $f['path'] || ($h['offset'] ?? -1) !== $f['offset'] || $h['payload_size'] < 1 || $h['payload_size'] > self::FILE_CHUNK || $h['payload_size'] > $f['remaining']) {
            throw new RuntimeException('Bloc de restauration dans un ordre invalide.');
        }
        if ($f['allowed']) {
            $target = rtrim(ABSPATH, '/\\') . '/' . $f['path'];
            $temp = $target . '.igbk-' . $s['id'] . '.tmp';
            if (!empty($f['check_existing']) && $f['offset'] === 0 && $h['payload_size'] === $f['remaining']) {
                // read_verified_payload() already checked the archive bytes.
                // Hash the existing target, rather than trusting size or mtime.
                // If it differs, use the original atomic replace path.
                $current = @hash_file('sha256', $target);
                if ($current !== false && hash_equals((string) $h['sha256'], $current)) {
                    $s['restore_file']['unchanged'] = true;
                } else {
                    $out = @fopen($temp, 'wb');
                    if (!$out) { throw new RuntimeException('Écriture impossible : ' . $f['path']); }
                    try { self::write_all($out, $payload); }
                    finally { fclose($out); }
                }
            } else {
                $out = @fopen($temp, 'c+b');
                if (!$out) { throw new RuntimeException('Écriture impossible : ' . $f['path']); }
                try {
                    if (fseek($out, $f['offset']) !== 0) { throw new RuntimeException('Décalage de fichier invalide.'); }
                    self::write_all($out, $payload);
                } finally { fclose($out); }
            }
        }
        $s['restore_file']['offset'] += $h['payload_size'];
        $s['restore_file']['remaining'] -= $h['payload_size'];
        if ($s['restore_file']['remaining'] === 0) { self::finish_restored_file($s); }
    }
    private static function finish_restored_file(&$s) {
        $f = $s['restore_file'];
        if ($f && $f['allowed']) {
            $target = rtrim(ABSPATH, '/\\') . '/' . $f['path'];
            if (empty($f['unchanged'])) {
                $temp = $target . '.igbk-' . $s['id'] . '.tmp';
                if (!@rename($temp, $target)) { throw new RuntimeException('Impossible de remplacer : ' . $f['path']); }
            }
            // Preserve archive timestamps only when necessary, to avoid
            // 20,000 unnecessary filesystem metadata writes on re-restores.
            if ($f['mtime'] > 0 && @filemtime($target) !== $f['mtime']) { @touch($target, $f['mtime']); }
            $s['restored_files']++;
        }
        $s['restore_file'] = null;
    }
    private static function swap_tables($s) {
        global $wpdb;
        if (empty($s['restore_tables'])) { throw new RuntimeException('Aucune table SQL dans l’archive.'); }
        $existing = $wpdb->get_col('SHOW TABLES');
        $parts = array();
        $count_stages = 0;
        foreach ($s['restore_tables'] as $target => $stage) {
            if (in_array($stage, $existing, true)) {
                $count_stages++;
                if (in_array($target, $existing, true)) {
                    $previous = self::stage_name($s['id'], $target, true);
                    if (in_array($previous, $existing, true)) { throw new RuntimeException('Table de sécurité déjà présente : ' . $previous); }
                    $parts[] = '`' . $target . '` TO `' . $previous . '`';
                }
                $parts[] = '`' . $stage . '` TO `' . $target . '`';
            }
        }
        if ($count_stages === 0) { return; } // Retry after successful atomic rename.
        if ($count_stages !== count($s['restore_tables'])) { throw new RuntimeException('Tables temporaires manquantes, bascule SQL annulée.'); }
        $result = $wpdb->query('RENAME TABLE ' . implode(', ', $parts));
        if ($result === false) { throw new RuntimeException('Bascule SQL atomique impossible : ' . $wpdb->last_error); }
        // Previous tables are deliberately retained as a safety net until manual SQL cleanup.
    }
    private static function delete_tree($path) {
        if (!file_exists($path)) { return; }
        if (is_file($path) || is_link($path)) {
            if (!@unlink($path)) { throw new RuntimeException('Suppression impossible : ' . basename((string) $path)); }
            return;
        }
        $items = scandir($path);
        if ($items === false) { throw new RuntimeException('Lecture de dossier impossible : ' . basename((string) $path)); }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') { continue; }
            self::delete_tree($path . '/' . $item);
        }
        if (!@rmdir($path)) { throw new RuntimeException('Suppression de dossier impossible : ' . basename((string) $path)); }
    }

    private static function write_manifest($id, $data) {
        $data['id'] = $id;
        if (file_put_contents(self::storage() . '/archives/' . $id . '.json', wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            throw new RuntimeException('Impossible d’enregistrer le manifeste de sauvegarde.');
        }
    }
    /** Fast logical deletion. The potentially slow unlink of gigabyte files is delegated
     *  to a separate WP-Cron request. All paths stay on the same filesystem.
     */
    public static function delete_archive($id) {
        if (!self::valid_id($id)) { throw new RuntimeException('Identifiant de sauvegarde invalide.'); }
        $dir = self::storage() . '/archives/';
        $source = $dir . $id . '.igbk';
        $trash = self::storage() . '/trash/archive-' . $id . '.igbk';
        if (!is_file($source)) { throw new RuntimeException('Archive introuvable.'); }
        if (file_exists($trash) || !@rename($source, $trash)) {
            throw new RuntimeException('Impossible de déplacer la sauvegarde vers la corbeille privée. Vérifiez les droits ou un éventuel verrou disque.');
        }
        // An orphan manifest is harmless: list_archives() only lists physical archives.
        @unlink($dir . $id . '.json');
        self::schedule_trash_cleanup();
        return true;
    }
    public static function delete_job($id) {
        if (!self::valid_id($id)) { throw new RuntimeException('Identifiant de tâche invalide.'); }
        $state = self::load($id);
        if (($state['type'] ?? '') === 'import') {
            return self::delete_import_job($id);
        }
        if (($state['type'] ?? '') === 'backup') {
            return self::delete_pending_backup($id);
        }
        throw new RuntimeException('Ce type de tâche ne peut pas être supprimé ici.');
    }
    public static function delete_import_job($id) {
        if (!self::valid_id($id)) { throw new RuntimeException('Identifiant d’importation invalide.'); }
        $state = self::load($id);
        if (($state['type'] ?? '') !== 'import' || ($state['phase'] ?? '') !== 'error') {
            throw new RuntimeException('Seules les importations interrompues peuvent être supprimées ici.');
        }
        $source = self::job_dir($id);
        $trash = self::storage() . '/trash/import-' . $id;
        if (file_exists($trash) || !@rename($source, $trash)) {
            throw new RuntimeException('Impossible de retirer cette importation. Vérifiez les droits ou un éventuel verrou disque.');
        }
        self::schedule_trash_cleanup();
        return true;
    }
    public static function delete_pending_backup($id) {
        if (!self::valid_id($id)) { throw new RuntimeException('Identifiant de sauvegarde invalide.'); }
        $state = self::load($id);
        if (($state['type'] ?? '') !== 'backup' || ($state['trigger'] ?? 'manual') !== 'manual' ||
            !in_array(($state['phase'] ?? ''), array('scan', 'database', 'files', 'finalize', 'error'), true)) {
            throw new RuntimeException('Seules les sauvegardes manuelles interrompues peuvent être supprimées ici.');
        }
        $source = self::job_dir($id);
        $trash = self::storage() . '/trash/backup-' . $id;
        if (file_exists($trash) || !@rename($source, $trash)) {
            throw new RuntimeException('Impossible de retirer cette sauvegarde en attente. Vérifiez les droits ou un éventuel verrou disque.');
        }
        self::schedule_trash_cleanup();
        return true;
    }

    /** Registered on every WordPress request, including cron requests. */
    public static function register_trash_cleanup() {
        add_action(self::TRASH_HOOK, array(__CLASS__, 'cleanup_trash'));
        add_action('init', array(__CLASS__, 'ensure_trash_cleanup'));
    }
    public static function ensure_trash_cleanup() {
        // Recover the scheduled task if a server restart or cron failure removed it.
        if (get_option(self::TRASH_PENDING_OPTION, false)) { self::schedule_trash_cleanup(); }
    }
    private static function schedule_trash_cleanup($delay = 15) {
        update_option(self::TRASH_PENDING_OPTION, 1, false);
        if (!wp_next_scheduled(self::TRASH_HOOK)) {
            wp_schedule_single_event(time() + max(10, (int) $delay), self::TRASH_HOOK);
        }
    }
    public static function cleanup_trash() {
        $dir = self::storage() . '/trash';
        $lock = @fopen($dir . '/cleanup.lock', 'c+');
        if (!$lock || !@flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock) { @fclose($lock); }
            self::schedule_trash_cleanup(60);
            return;
        }
        try {
            $items = @scandir($dir);
            if ($items === false) { throw new RuntimeException('Impossible de lire la corbeille de sauvegardes.'); }
            $started = microtime(true);
            foreach ($items as $name) {
                if (in_array($name, array('.', '..', 'index.php', '.htaccess', 'web.config', 'cleanup.lock'), true)) { continue; }
                // Every item comes from our own strict prefix/id naming convention.
                if (!preg_match('/\A(?:archive-[a-f0-9]{24}\.igbk|import-[a-f0-9]{24}|backup-[a-f0-9]{24})\z/', $name)) { continue; }
                self::delete_tree($dir . '/' . $name);
                if (microtime(true) - $started >= 8.0) { break; }
            }
        } catch (Throwable $error) {
            // Retry later; preserve the real filesystem error in server logs for diagnosis.
            error_log('InnovGeek Backup Studio: deferred deletion failed: ' . $error->getMessage());
        } finally {
            @flock($lock, LOCK_UN);
            @fclose($lock);
        }
        $remaining = glob($dir . '/archive-*.igbk') ?: array();
        $dirs = array_merge(glob($dir . '/import-*', GLOB_ONLYDIR) ?: array(), glob($dir . '/backup-*', GLOB_ONLYDIR) ?: array());
        if ($remaining || $dirs) {
            self::schedule_trash_cleanup(60);
        } else {
            delete_option(self::TRASH_PENDING_OPTION);
        }
    }
    public static function archive_path($id) {
        if (!self::valid_id($id)) { throw new RuntimeException('Archive invalide.'); }
        $file = self::storage() . '/archives/' . $id . '.igbk';
        if (!is_file($file)) { throw new RuntimeException('Archive introuvable.'); }
        return $file;
    }
    private static function public_state($s) {
        $keys = array('id', 'type', 'phase', 'percent', 'message', 'error', 'files_count', 'files_total', 'file_bytes_done', 'files_finished', 'received', 'expected', 'validated_files', 'validated_tables', 'restored_files', 'archive_id', 'trigger');
        $result = array();
        foreach ($keys as $key) { if (array_key_exists($key, $s)) { $result[$key] = $s[$key]; } }
        if (!empty($s['meta'])) {
            $result['source_site'] = $s['meta']['site'] ?? '';
            $result['target_site'] = $s['meta']['target_site'] ?? '';
            $result['migration'] = !empty($s['meta']['migration']);
        }
        return $result;
    }
}
