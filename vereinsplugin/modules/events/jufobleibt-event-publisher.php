<?php
/**
 * Plugin Name: Jufobleibt Event Publisher
 * Description: Veranstaltungen zentral anlegen (Texte & Bilder je Kanal) und automatisiert an Mastodon, Bluesky, Telegram, Facebook, Instagram, X/Twitter, Signal (Webhook) und Presseverteiler verschicken. Für Kanäle ohne API (Facebook-Veranstaltung, WhatsApp) werden fertige Copy-Paste-Vorlagen erzeugt.
 * Version: 0.6.0
 * Author: jufobleibt
 * Text Domain: jufobleibt-event-publisher
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Kein Direktzugriff.
}

define( 'JBF_PLUGIN_FILE', __FILE__ );
define( 'JBF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JBF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JBF_VERSION', '0.1.0' );

// Kern-Klassen laden.
require_once JBF_PLUGIN_DIR . 'includes/class-jbf-roles.php';
require_once JBF_PLUGIN_DIR . 'includes/class-jbf-cpt.php';
require_once JBF_PLUGIN_DIR . 'includes/class-jbf-metaboxes.php';
require_once JBF_PLUGIN_DIR . 'includes/class-jbf-settings.php';
require_once JBF_PLUGIN_DIR . 'includes/class-jbf-publisher.php';
require_once JBF_PLUGIN_DIR . 'includes/class-jbf-campaign.php';
require_once JBF_PLUGIN_DIR . 'includes/class-jbf-reels.php';
require_once JBF_PLUGIN_DIR . 'includes/class-jbf-ajax.php';

// Connector-Interfaces & Implementierungen.
require_once JBF_PLUGIN_DIR . 'includes/connectors/interface-jbf-connector.php';
require_once JBF_PLUGIN_DIR . 'includes/connectors/class-jbf-connector-mastodon.php';
require_once JBF_PLUGIN_DIR . 'includes/connectors/class-jbf-connector-bluesky.php';
require_once JBF_PLUGIN_DIR . 'includes/connectors/class-jbf-connector-telegram.php';
require_once JBF_PLUGIN_DIR . 'includes/connectors/class-jbf-connector-facebook.php';
require_once JBF_PLUGIN_DIR . 'includes/connectors/class-jbf-connector-instagram.php';
require_once JBF_PLUGIN_DIR . 'includes/connectors/class-jbf-connector-twitter.php';
require_once JBF_PLUGIN_DIR . 'includes/connectors/class-jbf-connector-signal.php';
require_once JBF_PLUGIN_DIR . 'includes/connectors/class-jbf-connector-press-email.php';

/**
 * Plugin initialisieren.
 */
function jbf_init_plugin() {
	Jbf_Roles::init();
	Jbf_Cpt::init();
	Jbf_Metaboxes::init();
	Jbf_Settings::init();
	Jbf_Ajax::init();
	Jbf_Campaign::init();
	Jbf_Reels::init();
}
add_action( 'plugins_loaded', 'jbf_init_plugin' );

/**
 * Läuft bei jedem Admin-Aufruf, aber schreibt nur, wenn wirklich etwas fehlt
 * (Jbf_Roles::setup_roles prüft intern, ob die Capability schon gesetzt ist).
 * So klappt es auch, wenn das Wunschlisten-Plugin erst NACH diesem Plugin
 * aktiviert oder die Mitglieder-Rolle erst später angelegt wurde.
 */
add_action( 'admin_init', array( 'Jbf_Roles', 'setup_roles' ) );

/**
 * Admin-Assets (CSS/JS) nur auf den relevanten Bildschirmen laden.
 */
function jbf_admin_assets( $hook ) {
	global $post_type;

	$is_event_screen   = ( 'veranstaltung' === $post_type );
	$is_settings_screen = ( false !== strpos( $hook, 'jufobleibt-event-publisher-settings' ) );

	if ( ! $is_event_screen && ! $is_settings_screen ) {
		return;
	}

	if ( $is_event_screen ) {
		wp_enqueue_media();
	}

	wp_enqueue_style( 'jbf-admin', JBF_PLUGIN_URL . 'admin/css/admin.css', array(), JBF_VERSION );
	wp_enqueue_script( 'jbf-admin', JBF_PLUGIN_URL . 'admin/js/admin.js', array( 'jquery', 'wp-util' ), JBF_VERSION, true );

	wp_localize_script(
		'jbf-admin',
		'JBF',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'jbf_publish_nonce' ),
			'i18n'    => array(
				'publishing' => __( 'Wird veröffentlicht …', 'jufobleibt-event-publisher' ),
				'copied'     => __( 'In Zwischenablage kopiert.', 'jufobleibt-event-publisher' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'jbf_admin_assets' );

/**
 * Aktivierung: Rewrite-Regeln aktualisieren.
 */
function jbf_activate_plugin() {
	Jbf_Cpt::register_post_type();
	Jbf_Roles::setup_roles();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'jbf_activate_plugin' );

function jbf_deactivate_plugin() {
	flush_rewrite_rules();
	if ( function_exists( 'wp_unschedule_hook' ) ) {
		wp_unschedule_hook( Jbf_Campaign::CRON_HOOK );
		wp_unschedule_hook( Jbf_Reels::CRON_HOOK );
	}
}
register_deactivation_hook( __FILE__, 'jbf_deactivate_plugin' );
