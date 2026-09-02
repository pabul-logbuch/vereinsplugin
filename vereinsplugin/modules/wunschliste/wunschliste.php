<?php
/**
 * Plugin Name: Vereins-Wunschliste
 * Description: Wunschliste für Vereine – Spender sehen Wünsche, Mitglieder verwalten sie.
 * Version: 1.0.0
 * Author: Dein Verein
 * Text Domain: wunschliste
 */

defined('ABSPATH') || exit;

define('WL_VERSION', '5.3.0');
define('WL_PATH', plugin_dir_path(__FILE__));
define('WL_URL', plugin_dir_url(__FILE__));

// Includes
require_once WL_PATH . 'includes/database.php';
require_once WL_PATH . 'includes/roles.php';
require_once WL_PATH . 'includes/shortcodes.php';
require_once WL_PATH . 'includes/admin.php';
require_once WL_PATH . 'includes/ajax.php';
require_once WL_PATH . 'includes/voting.php';
require_once WL_PATH . 'includes/voting-ajax.php';
require_once WL_PATH . 'includes/voting-admin.php';
require_once WL_PATH . 'includes/import.php';
require_once WL_PATH . 'includes/member-import.php';
require_once WL_PATH . 'includes/shifts.php';
require_once WL_PATH . 'includes/shifts-admin-frontend.php';
require_once WL_PATH . 'includes/shifts-ajax.php';
require_once WL_PATH . 'includes/shifts-import.php';
require_once WL_PATH . 'includes/shifts-admin.php';
require_once WL_PATH . 'includes/shifts-ics.php';
require_once WL_PATH . 'includes/shifts-reminders.php';
require_once WL_PATH . 'includes/shifts-print.php';

// Bei jedem Plugin-Update (auch ohne Re-Aktivierung) DB-Schema prüfen/nachrüsten
add_action('plugins_loaded', 'wl_maybe_run_upgrade');
function wl_maybe_run_upgrade() {
    $installed = get_option('wl_db_version', '');
    if ($installed !== WL_VERSION) {
        wl_create_tables();
        wl_create_voting_tables();
        wl_create_shift_tables();
        wl_create_roles();
        update_option('wl_db_version', WL_VERSION);
    }
}

// Activation / Deactivation
register_activation_hook(__FILE__, 'wl_activate');
register_deactivation_hook(__FILE__, 'wl_deactivate');

function wl_activate() {
    wl_create_tables();
    wl_create_voting_tables();
    wl_create_shift_tables();
    wl_create_roles();
    wl_insert_sample_data();
}

function wl_deactivate() {
    // Roles bleiben erhalten, damit User-Daten nicht verloren gehen
    wl_unschedule_erinnerung_cron();
}

// Assets laden
add_action('wp_enqueue_scripts', 'wl_enqueue_assets');
function wl_enqueue_assets() {
    // filemtime() statt fester Versionsnummer: erzwingt automatisch eine neue
    // URL bei jeder Dateiänderung, damit Browser/CDN/Cache-Plugins nie eine
    // veraltete Version von style.css oder script.js ausliefern.
    $style_path  = WL_PATH . 'assets/style.css';
    $script_path = WL_PATH . 'assets/script.js';
    $style_ver   = file_exists($style_path) ? filemtime($style_path) : WL_VERSION;
    $script_ver  = file_exists($script_path) ? filemtime($script_path) : WL_VERSION;

    wp_enqueue_style('wl-style', WL_URL . 'assets/style.css', [], $style_ver);
    wp_enqueue_script('wl-script', WL_URL . 'assets/script.js', ['jquery'], $script_ver, true);
    wp_localize_script('wl-script', 'wl_ajax', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wl_nonce'),
    ]);
}
