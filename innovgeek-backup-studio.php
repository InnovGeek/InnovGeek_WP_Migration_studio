<?php
/**
 * Plugin Name: InnovGeek Backup Studio
 * Plugin URI: https://innovgeek.com/
 * Description: Backup and restore WordPress sites / Sauvegarde et restauration de sites WordPress.
 * Version: 1.0
 * Author: InnovGeek
 * License: GPL-2.0-or-later
 * Text Domain: innovgeek-backup-studio
 * Requires PHP: 7.4
 * Requires at least: 5.9
 */

if (!defined('ABSPATH')) { exit; }

define('IGBK_VERSION', '1.0');
define('IGBK_PLUGIN_FILE', __FILE__);
define('IGBK_PLUGIN_DIR', plugin_dir_path(__FILE__));
require_once IGBK_PLUGIN_DIR . 'includes/class-igbk-migration.php';
require_once IGBK_PLUGIN_DIR . 'includes/class-igbk-engine.php';
require_once IGBK_PLUGIN_DIR . 'includes/class-igbk-scheduler.php';
require_once IGBK_PLUGIN_DIR . 'includes/class-igbk-admin.php';

register_activation_hook(__FILE__, array('IGBK_Scheduler', 'activate'));
register_deactivation_hook(__FILE__, array('IGBK_Scheduler', 'deactivate'));

add_action('plugins_loaded', static function () {
    IGBK_Scheduler::init();
    IGBK_Engine::register_trash_cleanup();
    IGBK_Admin::init();
});
