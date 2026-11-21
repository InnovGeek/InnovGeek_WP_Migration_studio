<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Automatic complete-site backups. The same IGBK_Engine handles both manual
 * and scheduled jobs; WP-Cron only orchestrates short, resumable checkpoints.
 * Scheduling is disabled until an administrator explicitly enables it.
 */
final class IGBK_Scheduler {
    const HOOK = 'igbk_schedule_launch_v120';
    const STEP = 'igbk_schedule_step_v120';
    const OPTIONS = 'igbk_backup_schedule_v120';
    const STATUS = 'igbk_schedule_status_v120';
    const TIMEZONE = 'Africa/Algiers';
    const TIMEZONE_APPLIED = 'igbk_schedule_timezone_algiers_v10';

    public static function timezone() {
        return new DateTimeZone(self::TIMEZONE);
    }

    public static function init() {
        add_action(self::HOOK, array(__CLASS__, 'launch'));
        add_action(self::STEP, array(__CLASS__, 'step'), 10, 1);
        add_action('init', array(__CLASS__, 'ensure_schedule'));
    }

    public static function settings() {
        $raw = get_option(self::OPTIONS, array());
        return array_merge(array('frequency' => 'off', 'time' => '02:00', 'weekday' => 1, 'monthday' => 1, 'retention' => 5), is_array($raw) ? $raw : array());
    }

    public static function state() {
        $raw = get_option(self::STATUS, array());
        return is_array($raw) ? $raw : array();
    }

    public static function activate() {
        self::ensure_schedule();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::HOOK);
        // Current jobs can be resumed if the extension is reactivated.
        wp_clear_scheduled_hook(self::STEP);
    }

    public static function save_settings($incoming) {
        $frequency = isset($incoming['frequency']) ? (string) $incoming['frequency'] : 'off';
        if (!in_array($frequency, array('off', 'daily', 'weekly', 'monthly'), true)) {
            throw new RuntimeException('Fréquence de sauvegarde invalide.');
        }
        $time = isset($incoming['time']) ? (string) $incoming['time'] : '';
        if (!preg_match('/\A(?:[01][0-9]|2[0-3]):[0-5][0-9]\z/', $time)) {
            throw new RuntimeException('Heure invalide (HH:MM requis).');
        }
        $weekday = isset($incoming['weekday']) ? filter_var($incoming['weekday'], FILTER_VALIDATE_INT) : false;
        $monthday = isset($incoming['monthday']) ? filter_var($incoming['monthday'], FILTER_VALIDATE_INT) : (int) self::settings()['monthday'];
        $retention = isset($incoming['retention']) ? filter_var($incoming['retention'], FILTER_VALIDATE_INT) : false;
        if ($weekday === false || $weekday < 1 || $weekday > 7) { throw new RuntimeException('Jour de la semaine invalide.'); }
        if ($monthday === false || $monthday < 1 || $monthday > 31) { throw new RuntimeException('Jour du mois invalide.'); }
        if ($retention === false || $retention < 1 || $retention > 50) { throw new RuntimeException('La rétention doit être comprise entre 1 et 50 sauvegardes.'); }
        $config = array('frequency' => $frequency, 'time' => $time, 'weekday' => $weekday, 'monthday' => $monthday, 'retention' => $retention);
        update_option(self::OPTIONS, $config, false);
        wp_clear_scheduled_hook(self::HOOK);
        self::ensure_schedule();
        return array('settings' => $config, 'next' => wp_next_scheduled(self::HOOK),
            'next_formatted' => wp_next_scheduled(self::HOOK) ? wp_date('d/m/Y à H:i', wp_next_scheduled(self::HOOK), self::timezone()) : 'Non programmée');
    }

    /** Calculate next wall-clock slot in the fixed Algiers timezone (UTC+1). */
    public static function next_occurrence($cfg, $after = null) {
        $tz = self::timezone();
        $now = $after === null ? new DateTimeImmutable('now', $tz) : (new DateTimeImmutable('@' . (int) $after))->setTimezone($tz);
        $pieces = explode(':', $cfg['time']);
        $candidate = $now->setTime((int) $pieces[0], (int) $pieces[1], 0);
        if ($cfg['frequency'] === 'monthly') {
            // Clamp 29, 30 and 31 to the last available day in shorter months.
            // Always anchor to the 1st first: adding months directly to Jan 31
            // would incorrectly overflow through February.
            $first = $now->modify('first day of this month')->setTime(0, 0, 0);
            for ($i = 0; $i < 14; $i++) {
                $month = $first->modify('+' . $i . ' months');
                $day = min(max(1, (int) ($cfg['monthday'] ?? 1)), (int) $month->format('t'));
                $slot = $month->setDate((int) $month->format('Y'), (int) $month->format('n'), $day)
                    ->setTime((int) $pieces[0], (int) $pieces[1], 0);
                if ($slot->getTimestamp() > $now->getTimestamp()) { return $slot->getTimestamp(); }
            }
            throw new RuntimeException('Impossible de calculer la prochaine sauvegarde mensuelle.');
        } elseif ($cfg['frequency'] === 'weekly') {
            $delta = ((int) $cfg['weekday'] - (int) $candidate->format('N') + 7) % 7;
            if ($delta !== 0) { $candidate = $candidate->modify('+' . $delta . ' days'); }
            if ($candidate->getTimestamp() <= $now->getTimestamp()) { $candidate = $candidate->modify('+7 days'); }
        } else {
            if ($candidate->getTimestamp() <= $now->getTimestamp()) { $candidate = $candidate->modify('+1 day'); }
        }
        return $candidate->getTimestamp();
    }

    public static function ensure_schedule() {
        $cfg = self::settings();
        // On first load of this timezone correction, replace any Paris-timed
        // next event. This changes only this plugin's scheduled backup hook.
        if (get_option(self::TIMEZONE_APPLIED, '') !== self::TIMEZONE) {
            wp_clear_scheduled_hook(self::HOOK);
            update_option(self::TIMEZONE_APPLIED, self::TIMEZONE, false);
        }
        if ($cfg['frequency'] === 'off') {
            if (wp_next_scheduled(self::HOOK)) { wp_clear_scheduled_hook(self::HOOK); }
        } elseif (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(self::next_occurrence($cfg), self::HOOK);
        }
        // Recover checkpointed scheduled jobs after PHP crashes or WordPress restarts.
        $s = self::state();
        $id = isset($s['job_id']) ? (string) $s['job_id'] : '';
        if ($id && in_array(($s['phase'] ?? ''), array('scan', 'database', 'files', 'finalize'), true)
            && !wp_next_scheduled(self::STEP, array($id))) {
            wp_schedule_single_event(time() + 20, self::STEP, array($id));
        }
    }

    public static function launch() {
        // Schedule next occurrence independently of the current backup duration.
        self::ensure_schedule();
        $cfg = self::settings();
        if ($cfg['frequency'] === 'off') { return; }
        try {
            $existing = self::state();
            if (!empty($existing['job_id']) && in_array(($existing['phase'] ?? ''), array('scan','database','files','finalize'), true)) {
                // A large automatic backup is still running; do not overlap.
                self::queue_step((string) $existing['job_id']);
                return;
            }
            $pending = IGBK_Engine::latest_pending_backup();
            if ($pending && ($pending['trigger'] ?? 'manual') !== 'scheduled') {
                self::record(array('state' => 'skipped', 'error' => 'Une sauvegarde manuelle était déjà en cours.', 'finished_at' => time()));
                return;
            }
            if ($pending && ($pending['trigger'] ?? '') === 'scheduled') {
                $job = $pending;
            } else {
                $job = IGBK_Engine::start_backup('full', 'scheduled');
            }
            self::record(array('state' => 'running', 'phase' => $job['phase'], 'job_id' => $job['id'],
                'percent' => $job['percent'], 'started_at' => time(), 'error' => ''));
            self::queue_step($job['id']);
            self::step($job['id']); // Make useful progress in this initial WP-Cron request.
        } catch (Throwable $e) {
            self::record(array('state' => 'error', 'phase' => 'error', 'error' => $e->getMessage(), 'finished_at' => time(), 'job_id' => ''));
        }
    }

    private static function queue_step($id, $delay = 20) {
        if (!wp_next_scheduled(self::STEP, array($id))) {
            wp_schedule_single_event(time() + max(10, $delay), self::STEP, array($id));
        }
    }

    private static function record($update) {
        $state = array_merge(self::state(), $update);
        update_option(self::STATUS, $state, false);
    }

    public static function step($id) {
        $id = (string) $id;
        $status = self::state();
        if (!$id || ($status['job_id'] ?? '') !== $id || ($status['state'] ?? '') !== 'running') { return; }
        $lock = null;
        try {
            $lock = @fopen(IGBK_Engine::storage() . '/jobs/scheduler.lock', 'c+');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { self::queue_step($id, 30); return; }
            $started = microtime(true);
            // Multiple engine checkpoints in one cron invocation to minimize
            // repeated WordPress bootstrap overhead, respecting PHP timeouts.
            for ($iterations = 0; $iterations < 25 && microtime(true) - $started < 10.0; $iterations++) {
                $job = IGBK_Engine::tick($id);
                self::record(array('phase' => $job['phase'], 'percent' => $job['percent'] ?? 0, 'failures' => 0, 'error' => ''));
                if ($job['phase'] === 'done') {
                    self::record(array('state' => 'done', 'phase' => 'done', 'percent' => 100, 'finished_at' => time(), 'last_success' => time(), 'error' => '', 'job_id' => ''));
                    self::apply_retention();
                    return;
                }
                if ($job['phase'] === 'error') {
                    self::record(array('state' => 'error', 'phase' => 'error', 'error' => ($job['error'] ?? 'Erreur de sauvegarde'), 'finished_at' => time(), 'job_id' => ''));
                    return;
                }
            }
            self::queue_step($id);
        } catch (Throwable $e) {
            // A temporary failure remains resumable; 10 retries avoid infinite loops.
            $failures = min(11, (int) ($status['failures'] ?? 0) + 1);
            if ($failures > 10) {
                self::record(array('state' => 'error', 'phase' => 'error', 'error' => $e->getMessage(), 'failures' => $failures, 'finished_at' => time(), 'job_id' => ''));
            } else {
                self::record(array('failures' => $failures, 'error' => 'Reprise automatique : ' . $e->getMessage()));
                self::queue_step($id, 60);
            }
        } finally {
            if ($lock) { @flock($lock, LOCK_UN); @fclose($lock); }
        }
    }

    /** Delete only excess scheduled archives; NEVER remove manual backups. */
    private static function apply_retention() {
        $limit = (int) self::settings()['retention'];
        $automatic = array_values(array_filter(IGBK_Engine::list_archives(), static function ($a) {
            return ($a['type'] ?? '') === 'scheduled';
        }));
        foreach (array_slice($automatic, $limit) as $archive) {
            try { IGBK_Engine::delete_archive($archive['id']); }
            catch (Throwable $e) { /* A future run retries clean-up. */ }
        }
    }
}
