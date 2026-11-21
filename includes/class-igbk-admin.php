<?php
if (!defined('ABSPATH')) { exit; }

final class IGBK_Admin {
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'register_menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        foreach (array('start_backup', 'save_schedule', 'tick', 'status', 'start_upload', 'upload_chunk', 'finish_upload', 'retry_import', 'verify', 'begin_restore', 'delete') as $method) {
            add_action('wp_ajax_igbk_' . $method, array(__CLASS__, 'ajax_' . $method));
        }
        add_action('admin_post_igbk_download', array(__CLASS__, 'download'));
        add_action('wp_ajax_igbk_upload_chunk_raw', array(__CLASS__, 'ajax_upload_chunk_raw'));
    }
    public static function register_menu() {
        $title = self::is_english_locale() ? 'InnovGeek Backup Studio' : 'InnovGeek Backup Studio';
        $menu  = self::is_english_locale() ? 'Backup Studio' : 'Backup Studio';
        add_menu_page($title, $menu, 'manage_options', 'igbk-backup-studio', array(__CLASS__, 'render'), 'dashicons-backup', 65);
    }
    public static function enqueue($hook) {
        if ($hook !== 'toplevel_page_igbk-backup-studio') { return; }
        wp_enqueue_style('igbk-studio', plugins_url('assets/admin.css', IGBK_PLUGIN_FILE), array(), (string) filemtime(IGBK_PLUGIN_DIR . 'assets/admin.css'));
        wp_enqueue_script('igbk-studio', plugins_url('assets/admin.js', IGBK_PLUGIN_FILE), array(), IGBK_VERSION, true);
        wp_localize_script('igbk-studio', 'IGBK_CONFIG', array(
            'url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('igbk_admin'),
            'chunk' => self::upload_chunk_size(),
        ));
        if (self::is_english_locale()) {
            wp_enqueue_script('igbk-i18n', plugins_url('assets/i18n.js', IGBK_PLUGIN_FILE), array('igbk-studio'), IGBK_VERSION, true);
            wp_localize_script('igbk-i18n', 'IGBK_L10N', array('locale' => 'en', 'strings' => self::translations()));
        }
    }
    private static function is_english_locale() {
        $candidates = array();
        if (function_exists('determine_locale')) { $candidates[] = (string) determine_locale(); }
        if (function_exists('get_user_locale')) { $candidates[] = (string) get_user_locale(); }
        $candidates[] = (string) get_locale();
        foreach ($candidates as $locale) {
            $locale = strtolower((string) $locale);
            if ($locale === '') { continue; }
            if (strpos($locale, 'en') === 0) { return true; }
            if (strpos($locale, 'fr') === 0) { return false; }
        }
        return false;
    }
    private static function translations() {
        static $translations = null;
        if ($translations !== null) { return $translations; }
        $file = IGBK_PLUGIN_DIR . 'languages/en_US.json';
        if (!is_file($file)) { $translations = array(); return $translations; }
        $loaded = json_decode((string) file_get_contents($file), true);
        $translations = is_array($loaded) ? $loaded : array();
        return $translations;
    }
    private static function translate_text($text) {
        if (!self::is_english_locale() || !is_string($text) || $text === '') { return $text; }
        $translations = self::translations();
        if (!$translations) { return $text; }
        uksort($translations, static function ($a, $b) { return strlen((string) $b) <=> strlen((string) $a); });
        $result = strtr($text, $translations);
        $result = preg_replace_callback('/(\d(?:[.,]\d+)?\s*)(Ko|Mo|Go|To|o)(?![[:alnum:]_])/u', static function ($m) {
            $units = array('Ko' => 'KB', 'Mo' => 'MB', 'Go' => 'GB', 'To' => 'TB', 'o' => 'B');
            return $m[1] . ($units[$m[2]] ?? $m[2]);
        }, $result);
        return $result;
    }
    private static function upload_chunk_size() {
        $limit = (int) wp_max_upload_size();
        if ($limit <= 0) { return IGBK_Engine::CHUNK; }
        // Leave comfortable room for multipart/form-data overhead.
        return min(8 * IGBK_Engine::CHUNK, max(131072, (int) floor($limit * 0.65)));
    }
    private static function guard() {
        if (!current_user_can('manage_options')) { wp_send_json_error(array('message' => 'Accès administrateur requis.'), 403); }
        check_ajax_referer('igbk_admin', 'nonce');
    }
    private static function post($name) { return isset($_POST[$name]) && is_scalar($_POST[$name]) ? sanitize_text_field(wp_unslash($_POST[$name])) : ''; }
    private static function run($callback) {
        self::guard();
        try { wp_send_json_success($callback()); }
        catch (Throwable $e) { wp_send_json_error(array('message' => $e->getMessage()), 400); }
    }
    public static function ajax_save_schedule() {
        self::run(static function () {
            return IGBK_Scheduler::save_settings(array(
                'frequency' => self::post('frequency'), 'time' => self::post('time'),
                'weekday' => self::post('weekday'), 'monthday' => self::post('monthday'), 'retention' => self::post('retention'),
            ));
        });
    }
    public static function ajax_start_backup() {
        self::run(static function () {
            $schedule = IGBK_Scheduler::state();
            if (($schedule['state'] ?? '') === 'running') {
                throw new RuntimeException('Une sauvegarde programmée est en cours. Elle doit se terminer avant de lancer une nouvelle sauvegarde manuelle.');
            }
            return IGBK_Engine::start_backup('full');
        });
    }
    public static function ajax_tick() {
        self::run(static function () { return IGBK_Engine::tick(self::post('job')); });
    }
    public static function ajax_status() {
        self::run(static function () { return IGBK_Engine::status(self::post('job')); });
    }
    public static function ajax_start_upload() {
        self::run(static function () {
            $size = filter_var(self::post('size'), FILTER_VALIDATE_INT);
            if ($size === false) { throw new RuntimeException('Taille de fichier incorrecte.'); }
            return IGBK_Engine::start_or_resume_upload(self::post('filename'), $size, self::post('fingerprint'));
        });
    }
    public static function ajax_upload_chunk() {
        self::guard();
        if (isset($_FILES['chunk']['error']) && in_array((int) $_FILES['chunk']['error'], array(UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE), true)) {
            // A PHP per-request upload limit was hit; the browser may retry with a smaller block.
            wp_send_json_error(array('message' => 'Bloc trop volumineux pour la configuration PHP.'), 413);
        }
        try {
            $offset = filter_var(self::post('offset'), FILTER_VALIDATE_INT);
            if ($offset === false) { throw new RuntimeException('Position de transfert incorrecte.'); }
            wp_send_json_success(IGBK_Engine::upload_chunk(self::post('job'), $offset, isset($_FILES['chunk']) ? $_FILES['chunk'] : array()));
        } catch (Throwable $e) { wp_send_json_error(array('message' => $e->getMessage()), 400); }
    }
    /** Stream one chunk directly to private storage, without a PHP upload-temp file.
     *  The nonce is transmitted in a header instead of the query string.
     */
    public static function ajax_upload_chunk_raw() {
        if (!current_user_can('manage_options') ||
            !isset($_SERVER['HTTP_X_IGBK_NONCE']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_SERVER['HTTP_X_IGBK_NONCE'])), 'igbk_admin')) {
            wp_send_json_error(array('message' => 'Session WordPress expirée. Actualisez la page.'), 403);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||
            stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/octet-stream') !== 0) {
            wp_send_json_error(array('message' => 'Type de requête non pris en charge.'), 415);
        }
        $id = isset($_GET['job']) && is_scalar($_GET['job']) ? sanitize_text_field(wp_unslash($_GET['job'])) : '';
        $offset = isset($_GET['offset']) && is_scalar($_GET['offset']) ? filter_var(wp_unslash($_GET['offset']), FILTER_VALIDATE_INT) : false;
        $length = isset($_GET['length']) && is_scalar($_GET['length']) ? filter_var(wp_unslash($_GET['length']), FILTER_VALIDATE_INT) : false;
        if ($offset === false || $length === false || $length < 1 || $length > 8 * IGBK_Engine::CHUNK) {
            wp_send_json_error(array('message' => 'Position ou taille du bloc invalide.'), 400);
        }
        // Catch truncated/proxy-modified bodies before writing anything.
        $content_length = isset($_SERVER['CONTENT_LENGTH']) ? filter_var($_SERVER['CONTENT_LENGTH'], FILTER_VALIDATE_INT) : false;
        if ($content_length !== false && $content_length !== $length) {
            wp_send_json_error(array('message' => 'Taille HTTP du bloc incohérente.'), 400);
        }
        try {
            $source = @fopen('php://input', 'rb');
            if (!$source) { throw new RuntimeException('Flux HTTP du bloc inaccessible.'); }
            try { $result = IGBK_Engine::upload_chunk_raw($id, $offset, $length, $source); }
            finally { fclose($source); }
            wp_send_json_success($result);
        } catch (Throwable $e) {
            wp_send_json_error(array('message' => $e->getMessage()), 400);
        }
    }
    public static function ajax_finish_upload() {
        self::run(static function () { return IGBK_Engine::finish_upload(self::post('job')); });
    }
    public static function ajax_retry_import() {
        self::run(static function () { return IGBK_Engine::retry_import(self::post('job')); });
    }
    public static function ajax_verify() {
        self::run(static function () { return IGBK_Engine::verify_existing(self::post('archive')); });
    }
    public static function ajax_begin_restore() {
        self::run(static function () { return IGBK_Engine::begin_restore(self::post('job'), self::post('confirmation')); });
    }
    public static function ajax_delete() {
        self::run(static function () {
            $job = self::post('job');
            if ($job !== '') { return array('deleted' => IGBK_Engine::delete_job($job), 'queued' => true); }
            return array('deleted' => IGBK_Engine::delete_archive(self::post('archive')), 'queued' => true);
        });
    }
    public static function download() {
        if (!current_user_can('manage_options')) { wp_die('Accès refusé', '', array('response' => 403)); }
        $id = isset($_GET['archive']) ? sanitize_text_field(wp_unslash($_GET['archive'])) : '';
        check_admin_referer('igbk_download_' . $id, 'nonce');
        try { $path = IGBK_Engine::archive_path($id); }
        catch (Throwable $e) { wp_die(esc_html($e->getMessage())); }
        while (ob_get_level()) { @ob_end_clean(); }
        @set_time_limit(0);
        header('Content-Type: application/octet-stream');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="backup-' . $id . '.igbk"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-store');
        $fp = fopen($path, 'rb');
        if (!$fp) { exit; }
        while (!feof($fp) && !connection_aborted()) {
            echo fread($fp, 4 * 1024 * 1024);
            flush();
        }
        fclose($fp);
        exit;
    }
    public static function render() {
        if (!current_user_can('manage_options')) { return; }
        ob_start();
        $archives = array(); $pending = null; $failedImport = null; $error = ''; $folder = '';
        try {
            $folder = IGBK_Engine::storage();
            $archives = IGBK_Engine::list_archives();
            $pending = IGBK_Engine::latest_pending_backup();
            $failedImport = IGBK_Engine::latest_failed_import();
            if ($pending && ($pending['trigger'] ?? 'manual') === 'scheduled') { $pending = null; }
        } catch (Throwable $e) { $error = $e->getMessage(); }
        $cfg = IGBK_Scheduler::settings();
        $schedule = IGBK_Scheduler::state();
        $next = wp_next_scheduled(IGBK_Scheduler::HOOK);
        $zone = 'Alger, Algérie';
        $bytes = array_sum(array_column($archives, 'bytes'));
        $scheduled_count = count(array_filter($archives, static function ($a) { return ($a['type'] ?? '') === 'scheduled'; }));
        $logo = plugins_url('assets/innovgeek-logo-officiel.png', IGBK_PLUGIN_FILE);
        $last = $archives[0] ?? null;
        ?>
        <div class="igbk-root">
          <div class="igbk-wrap">
            <header class="igbk-top">
              <div class="igbk-brand-full">
                <img class="igbk-official-logo" src="<?php echo esc_url($logo); ?>" alt="Logo officiel InnovGeek" />
                <span class="igbk-top-title"><small>BACKUP STUDIO <span>V<?php echo esc_html(IGBK_VERSION); ?></span></small></span>
              </div>
              <span class="igbk-top-protected"><span class="dashicons dashicons-lock"></span> Archives privées</span>
            </header>
            <nav class="igbk-tabs" aria-label="Navigation InnovGeek Backup Studio">
              <button type="button" class="igbk-nav-link is-active" data-tab="dashboard"><span class="dashicons dashicons-cloud-saved"></span> Sauvegarde</button>
              <button type="button" class="igbk-nav-link" data-tab="import"><span class="dashicons dashicons-migrate"></span> Importer / restaurer</button>
              <button type="button" class="igbk-nav-link" data-tab="schedule"><span class="dashicons dashicons-calendar-alt"></span> Programmation</button>
              <button type="button" class="igbk-nav-link" data-tab="backups"><span class="dashicons dashicons-backup"></span> Sauvegardes <span class="igbk-count"><?php echo esc_html(count($archives)); ?></span></button>
            </nav>
            <?php if ($error): ?><div class="igbk-alert igbk-alert-error"><b>Stockage privé indisponible :</b> <?php echo esc_html($error); ?></div><?php endif; ?>
            <section class="igbk-panel is-active" data-panel="dashboard">
              <div class="igbk-hero">
                <div class="igbk-hero-art" aria-hidden="true"><img class="igbk-hero-illustration" src="<?php echo esc_url(plugins_url('assets/backup-cloud-server.png', IGBK_PLUGIN_FILE)); ?>" alt="" /></div>
                <div class="igbk-hero-copy">
                  <span class="igbk-pill"><span class="dashicons dashicons-performance"></span> Mode optimisé</span>
                  <h1>Sauvegarde du site complet</h1>
                  <p>Fichiers WordPress + base de données</p>
                  <button type="button" class="igbk-btn igbk-btn-primary igbk-btn-big" id="igbk-create" <?php disabled((bool) $error); ?>><span class="dashicons dashicons-cloud-upload"></span> Sauvegarder le site complet</button>
                </div>
                <div class="igbk-feature-row">
                  <div class="igbk-feature"><span class="igbk-tile-icon"><span class="dashicons dashicons-yes-alt"></span></span><div><b>Un seul mode de backup</b><small>Site entier et base SQL, en une opération</small></div></div>
                  <div class="igbk-feature"><span class="igbk-tile-icon violet"><span class="dashicons dashicons-performance"></span></span><div><b>Copie optimisée</b><small>Lots adaptatifs et reprise après coupure</small></div></div>
                </div>
              </div>
              <div class="igbk-resume-box" id="igbk-resume-box" <?php if (!$pending) { echo 'hidden'; } ?>>
                <div><b>Une sauvegarde manuelle peut être reprise</b><p id="igbk-resume-description"><?php echo $pending ? esc_html(($pending['message'] ?? '') . ' · ' . ($pending['percent'] ?? 0) . '%') : ''; ?></p></div>
                <div class="igbk-inline-actions">
                  <button type="button" id="igbk-resume" class="igbk-btn igbk-btn-outline" data-job="<?php echo $pending ? esc_attr($pending['id']) : ''; ?>"><span class="dashicons dashicons-controls-play"></span> Reprendre</button>
                  <button type="button" id="igbk-delete-resume" class="igbk-btn igbk-btn-outline igbk-btn-delete-pending" data-job="<?php echo $pending ? esc_attr($pending['id']) : ''; ?>"><span class="dashicons dashicons-trash"></span> Supprimer</button>
                </div>
              </div>
              <div class="igbk-overview-grid">
                <div class="igbk-stat"><span>Archives disponibles</span><strong><?php echo esc_html(count($archives)); ?></strong><small><?php echo esc_html($scheduled_count); ?> programmée(s)</small></div>
                <div class="igbk-stat"><span>Espace utilisé</span><strong><?php echo esc_html(size_format($bytes, 1)); ?></strong><small>Sur le stockage privé du serveur</small></div>
                <div class="igbk-stat"><span>Dernière sauvegarde</span><strong><?php echo $last ? esc_html(wp_date('d/m/Y', strtotime($last['created']))) : 'Aucune'; ?></strong><small><?php echo $last ? esc_html(wp_date('H:i', strtotime($last['created']))) : 'Pas encore de sauvegarde'; ?></small></div>
              </div>
              <div class="igbk-main-grid">
                <div class="igbk-card igbk-recent-card">
                  <div class="igbk-card-heading"><h2><span class="dashicons dashicons-backup"></span> Sauvegardes récentes</h2><button type="button" class="igbk-link" data-goto="backups">Voir toutes <span class="dashicons dashicons-arrow-right-alt2"></span></button></div>
                  <?php if (!$archives): ?><div class="igbk-empty"><span class="dashicons dashicons-cloud"></span><p>Aucune sauvegarde pour le moment.</p></div>
                  <?php else: ?>
                  <?php foreach (array_slice($archives, 0, 4) as $a): ?>
                    <div class="igbk-recent-row"><span class="dashicons dashicons-yes-alt"></span><div><b><?php echo esc_html(wp_date('d/m/Y · H:i', strtotime($a['created'] ?? 'now'))); ?></b><small><?php echo ($a['type'] ?? 'manual') === 'scheduled' ? 'Programmée · Site complet' : 'Manuelle · Site complet'; ?></small></div><span class="igbk-recent-size"><?php echo esc_html(size_format($a['bytes'], 1)); ?></span></div>
                  <?php endforeach; ?>
                  <?php endif; ?>
                </div>
                <div class="igbk-card igbk-schedule-summary">
                  <div class="igbk-card-heading"><h2><span class="dashicons dashicons-calendar-alt"></span> Backups programmés</h2></div>
                  <span class="igbk-chip <?php echo $cfg['frequency'] !== 'off' ? 'is-enabled' : ''; ?>"><?php echo $cfg['frequency'] === 'off' ? 'Désactivés' : ($cfg['frequency'] === 'daily' ? 'Quotidiens' : ($cfg['frequency'] === 'weekly' ? 'Hebdomadaires' : 'Mensuels')); ?></span>
                  <p id="igbk-next-summary"><?php echo $next ? 'Prochaine exécution : ' . esc_html(wp_date('d/m/Y à H:i', $next, IGBK_Scheduler::timezone())) : 'Activez les sauvegardes automatiques pour protéger votre site régulièrement.'; ?></p>
                  <button type="button" class="igbk-btn igbk-btn-outline" data-goto="schedule">Configurer la programmation</button>
                </div>
              </div>
            </section>
            <section class="igbk-panel" data-panel="schedule">
              <div class="igbk-page-title"><span class="igbk-overline">AUTOMATISATION</span><h1>Sauvegardes programmées</h1><p>Le même backup complet, lancé automatiquement par WordPress.</p></div>
              <div class="igbk-schedule-layout">
              <div class="igbk-card igbk-form-card">
                <form id="igbk-schedule-form" autocomplete="off">
                  <div class="igbk-field"><label for="igbk-frequency">Fréquence</label><select id="igbk-frequency" name="frequency"><option value="off" <?php selected($cfg['frequency'], 'off'); ?>>Désactivée</option><option value="daily" <?php selected($cfg['frequency'], 'daily'); ?>>Tous les jours</option><option value="weekly" <?php selected($cfg['frequency'], 'weekly'); ?>>Toutes les semaines</option><option value="monthly" <?php selected($cfg['frequency'], 'monthly'); ?>>Tous les mois</option></select></div>
                  <div class="igbk-form-row"><div class="igbk-field"><label for="igbk-time">Heure (Alger, Algérie)</label><input id="igbk-time" name="time" type="time" required value="<?php echo esc_attr($cfg['time']); ?>" /></div><div class="igbk-field" id="igbk-weekday-row"><label for="igbk-weekday">Jour</label><select name="weekday" id="igbk-weekday"><?php foreach (array(1=>'Lundi',2=>'Mardi',3=>'Mercredi',4=>'Jeudi',5=>'Vendredi',6=>'Samedi',7=>'Dimanche') as $day=>$label): ?><option value="<?php echo esc_attr($day); ?>" <?php selected((int) $cfg['weekday'], $day); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></div></div>
                  <div class="igbk-field" id="igbk-monthday-row" hidden><label for="igbk-monthday">Jour du mois</label><select name="monthday" id="igbk-monthday"><?php for ($day = 1; $day <= 31; $day++): ?><option value="<?php echo esc_attr($day); ?>" <?php selected((int) $cfg['monthday'], $day); ?>><?php echo esc_html($day); ?></option><?php endfor; ?></select><small>Pour les mois plus courts, la sauvegarde est effectuée le dernier jour du mois.</small></div>
                  <div class="igbk-field"><label for="igbk-retention">Nombre de backups programmés à conserver</label><input id="igbk-retention" name="retention" type="number" min="1" max="50" required value="<?php echo esc_attr($cfg['retention']); ?>" /><small>Les plus anciennes sauvegardes programmées seront supprimées uniquement après la réussite d'une nouvelle sauvegarde. Les sauvegardes manuelles ne sont jamais concernées.</small></div>
                  <div class="igbk-note"><span class="dashicons dashicons-clock"></span><div><b>Fuseau : <?php echo esc_html($zone); ?></b><p>WP-Cron dépend des visites sur ton site. Pour une exécution fiable à l’heure prévue, configure une tâche cron du serveur qui lance <code>wp-cron.php</code> toutes les minutes.</p></div></div>
                  <button type="submit" class="igbk-btn igbk-btn-primary" id="igbk-save-schedule"><span class="dashicons dashicons-saved"></span> Enregistrer la programmation</button>
                  <p class="igbk-form-feedback" id="igbk-schedule-feedback" role="status" aria-live="polite"></p>
                </form>
              </div>
              <div class="igbk-card igbk-job-card"><h2>État de l’automatisation</h2><div class="igbk-job-row"><span>Prochaine sauvegarde</span><strong id="igbk-next-run"><?php echo $next ? esc_html(wp_date('d/m/Y à H:i', $next, IGBK_Scheduler::timezone())) : 'Non programmée'; ?></strong></div><div class="igbk-job-row"><span>Dernière exécution</span><strong><?php echo !empty($schedule['finished_at']) ? esc_html(wp_date('d/m/Y à H:i', (int) $schedule['finished_at'], IGBK_Scheduler::timezone())) : 'Aucune'; ?></strong></div><div class="igbk-job-row"><span>Statut</span><strong><?php echo esc_html($schedule['state'] ?? 'En attente'); ?><?php if (($schedule['state'] ?? '') === 'running'): ?> · <?php echo esc_html((int) ($schedule['percent'] ?? 0)); ?>%<?php endif; ?></strong></div><?php if (!empty($schedule['error'])): ?><p class="igbk-job-error"><?php echo esc_html($schedule['error']); ?></p><?php endif; ?></div>
              </div>
            </section>
            <section class="igbk-panel" data-panel="backups">
              <div class="igbk-page-title"><span class="igbk-overline">HISTORIQUE</span><h1>Toutes les sauvegardes</h1><p>Archives du site complet : manuelles et programmées.</p></div>
              <div class="igbk-card igbk-history">
                <?php if (!$archives): ?><div class="igbk-empty"><span class="dashicons dashicons-archive"></span><p>Aucune archive.</p></div>
                <?php else: foreach ($archives as $a):
                    $id = $a['id'];
                    $download = wp_nonce_url(admin_url('admin-post.php?action=igbk_download&archive=' . rawurlencode($id)), 'igbk_download_' . $id, 'nonce');
                ?><div class="igbk-history-row"><span class="igbk-history-icon dashicons dashicons-yes-alt"></span><div class="igbk-history-name"><b><?php echo esc_html($a['label'] ?? 'Sauvegarde du site complet'); ?></b><small><?php echo esc_html(wp_date('d/m/Y · H:i', strtotime($a['created'] ?? 'now'))); ?> · <?php echo esc_html(size_format($a['bytes'], 1)); ?> · <?php echo esc_html((string) ($a['files'] ?? '?')); ?> fichiers</small></div><div class="igbk-history-actions"><a class="igbk-icon-btn" title="Télécharger" aria-label="Télécharger" href="<?php echo esc_url($download); ?>"><span class="dashicons dashicons-download"></span></a><button type="button" class="igbk-icon-btn igbk-restore-archive" title="Vérifier et restaurer" aria-label="Vérifier et restaurer" data-id="<?php echo esc_attr($id); ?>"><span class="dashicons dashicons-update"></span></button><button type="button" class="igbk-icon-btn igbk-delete-archive" title="Supprimer" aria-label="Supprimer" data-id="<?php echo esc_attr($id); ?>"><span class="dashicons dashicons-trash"></span></button></div></div>
                <?php endforeach; endif; ?>
              </div>
              <p class="igbk-storage-path"><span class="dashicons dashicons-lock"></span> Dossier privé : <code><?php echo esc_html($folder ?: 'Indisponible'); ?></code></p>
            </section>
            <section class="igbk-panel" data-panel="import">
              <div class="igbk-page-title"><span class="igbk-overline">IMPORTATION ET RESTAURATION</span><h1>Importer une sauvegarde</h1><p>Transfert par petits blocs sans plafond de taille totale fixé par le plugin.</p></div>
              <?php if ($failedImport): ?>
                <div class="igbk-resume-box igbk-import-resume" role="status">
                  <div><b>Importation complète déjà transférée</b><p>Archive de <?php echo esc_html(size_format((int) $failedImport['expected'], 1)); ?> présente sur le serveur. Revalidez-la sans renvoyer le fichier.</p></div>
                  <div class="igbk-inline-actions">
                    <button type="button" class="igbk-btn igbk-btn-primary" id="igbk-retry-import" data-job="<?php echo esc_attr($failedImport['id']); ?>"><span class="dashicons dashicons-update"></span> Reprendre la validation</button>
                    <button type="button" class="igbk-btn igbk-btn-outline igbk-btn-delete-pending" id="igbk-delete-pending-import" data-job="<?php echo esc_attr($failedImport['id']); ?>"><span class="dashicons dashicons-trash"></span> Supprimer</button>
                  </div>
                </div>
              <?php endif; ?>
              <div class="igbk-card igbk-import-card"><div id="igbk-drop" class="igbk-dropzone" role="button" tabindex="0"><span class="igbk-drop-icon dashicons dashicons-cloud-upload"></span><h2>Glissez votre archive ici</h2><p>ou choisissez un fichier .igbk sur votre ordinateur</p><button type="button" class="igbk-btn igbk-btn-outline" id="igbk-choose">Choisir une archive</button><input type="file" id="igbk-file" accept=".igbk" hidden /><small>Transfert par blocs adaptatifs · format .igbk uniquement</small></div>
              <div class="igbk-note"><span class="dashicons dashicons-warning"></span><div><b>Restauration à vérifier sur un site de test</b><p>La restauration remplace <code>wp-content</code> et les tables SQL. Pour une migration inter-domaines, les URL reconnues sont adaptées, mais le cœur WordPress et <code>wp-config.php</code> ne sont pas réinstallés. Sauvegardez le site de destination et testez d’abord sur une préproduction. Les fichiers .wpress ne sont pas compatibles.</p></div></div></div>
            </section>
            <footer class="igbk-footer"><span>© <?php echo esc_html(wp_date('Y')); ?> InnovGeek Backup Studio</span><span>V<?php echo esc_html(IGBK_VERSION); ?> · Stockage privé · Format .igbk</span></footer>
          </div>
          <div class="igbk-progress-layer" id="igbk-progress" hidden role="dialog" aria-modal="true" aria-labelledby="igbk-progress-title"><div class="igbk-progress-dialog"><div class="igbk-progress-icon"><span class="dashicons dashicons-cloud-upload"></span></div><div class="igbk-progress-category" id="igbk-progress-category">SAUVEGARDE</div><h2 id="igbk-progress-title">Copie du site en cours</h2><p id="igbk-progress-message">Site complet : fichiers + base de données</p><div class="igbk-progress-track"><div id="igbk-progress-bar"></div></div><div class="igbk-progress-bottom"><strong id="igbk-progress-pct">0%</strong><span>Optimisation active · traitement par lots</span></div><div class="igbk-progress-pills"><span><span class="dashicons dashicons-yes-alt"></span> Un seul mode de backup</span><span><span class="dashicons dashicons-performance"></span> Copie optimisée</span></div><div class="igbk-stopwatch" id="igbk-stopwatch" hidden><span class="dashicons dashicons-clock" aria-hidden="true"></span><span id="igbk-stopwatch-label">Temps écoulé</span><strong id="igbk-stopwatch-value" role="timer">00:00</strong></div><p class="igbk-progress-error" id="igbk-progress-error" hidden></p><div class="igbk-progress-actions"><button class="igbk-btn igbk-btn-primary" type="button" id="igbk-progress-resume" hidden>Reprendre</button><button class="igbk-btn igbk-btn-outline" type="button" id="igbk-progress-close" hidden>Fermer</button></div></div></div>
          <div class="igbk-modal-layer" id="igbk-modal" hidden><div class="igbk-modal" role="dialog" aria-modal="true"><div class="igbk-modal-symbol"><span class="dashicons dashicons-warning"></span></div><h2 id="igbk-modal-title"></h2><p id="igbk-modal-description"></p><div id="igbk-modal-confirm-row" hidden><label for="igbk-modal-confirm-input">Saisissez <b>RESTAURER</b> pour confirmer</label><input id="igbk-modal-confirm-input" type="text" autocomplete="off" spellcheck="false" /></div><div class="igbk-modal-actions"><button id="igbk-modal-cancel" class="igbk-btn igbk-btn-outline" type="button">Annuler</button><button id="igbk-modal-accept" class="igbk-btn igbk-btn-danger" type="button">Confirmer</button></div></div></div>
        </div>
        <?php
        $html = ob_get_clean();
        echo self::translate_text($html);
    }
}
