<?php
/**
 * Plugin Name: ProtokollPro
 * Description: Digitale Sitzungsverwaltung für Vereine mit Konsent-Verfahren (Gremien, Protokolle, TOPs, Themenspeicher, Aufgaben, Termine). Nutzt dieselbe Mitgliederverwaltung wie die Vereins-Wunschliste.
 * Version: 1.0.0
 * Author: Jugendforum Riedlingen e.V.
 * Text Domain: protokollpro
 */

defined('ABSPATH') || exit;

define('PP_VERSION', '1.9.0');
define('PP_PATH', plugin_dir_path(__FILE__));
define('PP_URL', plugin_dir_url(__FILE__));

// ─── INCLUDES ──────────────────────────────────────────────────────────────
require_once PP_PATH . 'includes/helpers.php';
require_once PP_PATH . 'includes/database.php';
require_once PP_PATH . 'includes/roles.php';
require_once PP_PATH . 'includes/admin.php';
require_once PP_PATH . 'includes/admin-gremien.php';
require_once PP_PATH . 'includes/admin-protokolle.php';
require_once PP_PATH . 'includes/admin-themen.php';
require_once PP_PATH . 'includes/admin-aufgaben-termine.php';
require_once PP_PATH . 'includes/admin-bestaetigungen.php';
require_once PP_PATH . 'includes/admin-freigaben.php';
require_once PP_PATH . 'includes/rollenvorlagen.php';
require_once PP_PATH . 'includes/organigramm.php';
require_once PP_PATH . 'includes/kalender.php';
require_once PP_PATH . 'includes/pwa.php';
require_once PP_PATH . 'includes/frontend.php';
require_once PP_PATH . 'includes/ajax.php';
require_once PP_PATH . 'includes/shortcodes.php';

// ─── UPGRADE / ACTIVATION ────────────────────────────────────────────────

// Bei jedem Plugin-Update (auch ohne Re-Aktivierung) DB-Schema prüfen/nachrüsten,
// gleiches Muster wie im Wunschliste-Plugin (wl_maybe_run_upgrade).
add_action('plugins_loaded', 'pp_maybe_run_upgrade');
function pp_maybe_run_upgrade() {
    $installed = get_option('pp_db_version', '');
    if ($installed !== PP_VERSION) {
        pp_create_tables();
        pp_create_roles();
        update_option('pp_db_version', PP_VERSION);
    }
}

register_activation_hook(__FILE__, 'pp_activate');
register_deactivation_hook(__FILE__, 'pp_deactivate');

function pp_activate() {
    pp_create_tables();
    pp_create_roles();
    if (!wp_next_scheduled('pp_daily_cron')) {
        wp_schedule_event(time(), 'daily', 'pp_daily_cron');
    }
}

function pp_deactivate() {
    // Rollen/Daten bleiben erhalten.
    wp_clear_scheduled_hook('pp_daily_cron');
}

// Wiederkehrende Rollen-Aufgaben täglich prüfen und ggf. erzeugen (siehe includes/rollenvorlagen.php)
add_action('pp_daily_cron', 'pp_generate_wiederkehrende_aufgaben');

// ─── ASSETS ────────────────────────────────────────────────────────────────

add_action('admin_enqueue_scripts', 'pp_enqueue_admin_assets');
function pp_enqueue_admin_assets($hook) {
    // Nur auf ProtokollPro-eigenen Admin-Seiten laden
    if (strpos($hook, 'protokollpro') === false && strpos($hook, 'pp-') === false) {
        return;
    }
    $style_path  = PP_PATH . 'assets/style.css';
    $script_path = PP_PATH . 'assets/script.js';
    $style_ver   = file_exists($style_path) ? filemtime($style_path) : PP_VERSION;
    $script_ver  = file_exists($script_path) ? filemtime($script_path) : PP_VERSION;

    wp_enqueue_style('pp-style', PP_URL . 'assets/style.css', [], $style_ver);
    wp_enqueue_script('pp-script', PP_URL . 'assets/script.js', ['jquery'], $script_ver, true);
    wp_localize_script('pp-script', 'pp_ajax', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('pp_nonce'),
    ]);
}

add_action('wp_enqueue_scripts', 'pp_enqueue_frontend_assets');
function pp_enqueue_frontend_assets() {
    global $post;
    $shortcodes = ['protokollpro_oeffentlich', 'protokollpro_organigramm', 'protokollpro_kreis', 'protokollpro_mitgliederbereich'];
    $hat_shortcode = is_a($post, 'WP_Post') && array_reduce($shortcodes, function ($carry, $sc) use ($post) {
        return $carry || has_shortcode($post->post_content, $sc);
    }, false);
    if (!$hat_shortcode) {
        return;
    }
    $style_path = PP_PATH . 'assets/style.css';
    $style_ver  = file_exists($style_path) ? filemtime($style_path) : PP_VERSION;
    wp_enqueue_style('pp-style', PP_URL . 'assets/style.css', [], $style_ver);

    $script_path = PP_PATH . 'assets/script.js';
    $script_ver  = file_exists($script_path) ? filemtime($script_path) : PP_VERSION;
    wp_enqueue_script('pp-script', PP_URL . 'assets/script.js', ['jquery'], $script_ver, true);
    wp_localize_script('pp-script', 'pp_ajax', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('pp_nonce'),
    ]);
}
