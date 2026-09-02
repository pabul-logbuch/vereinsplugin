<?php
/**
 * Plugin Name: JuFo Buchhaltung
 * Description: EÜR-Buchhaltung, Belegverwaltung und Auslagen-Erstattung für das Jugendforum Riedlingen. Nutzt Nextcloud zur Belegablage.
 * Version: 1.0.0
 * Author: Jugendforum Riedlingen e.V.
 * Text Domain: jufo-buch
 */

defined('ABSPATH') || exit;

define('JB_VERSION', '1.0.0');
define('JB_PATH',    plugin_dir_path(__FILE__));
define('JB_URL',     plugin_dir_url(__FILE__));

require_once JB_PATH . 'includes/database.php';
require_once JB_PATH . 'includes/roles.php';
require_once JB_PATH . 'includes/nextcloud.php';
require_once JB_PATH . 'includes/auslagen.php';
require_once JB_PATH . 'includes/buchungsjournal.php';
require_once JB_PATH . 'includes/budgets.php';
require_once JB_PATH . 'includes/getraenke.php';
require_once JB_PATH . 'includes/export.php';
require_once JB_PATH . 'includes/ajax.php';
require_once JB_PATH . 'includes/settings.php';
require_once JB_PATH . 'includes/shortcodes.php';
require_once JB_PATH . 'includes/admin.php';

// Activation / Deactivation
register_activation_hook(__FILE__, 'jb_activate');
register_deactivation_hook(__FILE__, 'jb_deactivate');

function jb_activate() {
    jb_create_tables();
    jb_setup_roles();
    flush_rewrite_rules();
}

function jb_deactivate() {
    flush_rewrite_rules();
}

// Auto-upgrade on version change
add_action('plugins_loaded', 'jb_maybe_upgrade');
function jb_maybe_upgrade() {
    if (get_option('jb_db_version', '') !== JB_VERSION) {
        jb_create_tables();
        jb_setup_roles();
        update_option('jb_db_version', JB_VERSION);
    }
}

// Assets
add_action('wp_enqueue_scripts', 'jb_frontend_assets');
function jb_frontend_assets() {
    wp_enqueue_style('jb-style', JB_URL . 'assets/style.css', [], JB_VERSION);
    wp_enqueue_script('jb-script', JB_URL . 'assets/script.js', ['jquery'], JB_VERSION, true);
    wp_localize_script('jb-script', 'JB', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('jb_nonce'),
        'logged_in' => is_user_logged_in(),
    ]);
}

add_action('admin_enqueue_scripts', 'jb_admin_assets');
function jb_admin_assets($hook) {
    if (strpos($hook, 'jb_') === false && strpos($hook, 'jufo-buch') === false) return;
    wp_enqueue_style('jb-admin-style', JB_URL . 'assets/admin.css', [], JB_VERSION);
    wp_enqueue_script('jb-admin-script', JB_URL . 'assets/script.js', ['jquery'], JB_VERSION, true);
    wp_localize_script('jb-admin-script', 'JB', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('jb_nonce'),
    ]);
}
