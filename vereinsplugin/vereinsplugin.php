<?php
/**
 * Plugin Name:       Vereinsplugin
 * Plugin URI:        https://github.com/DEIN-GITHUB-NAME/vereinsplugin
 * Description:        Alles-in-einem-Vereinsverwaltung: Wunschliste & Spenden, Sitzungs-/Protokollverwaltung, Buchhaltung & Auslagen, Veranstaltungs-Publisher – mit gemeinsamem Mitgliederbereich. Im WordPress-Dashboard erscheint bewusst nur eine einzige Seite: die Shortcode-Übersicht. Alle Verwaltung läuft über Shortcodes im Frontend.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Verein
 * Text Domain:       vereinsplugin
 * Domain Path:       /languages
 * GitHub Plugin URI: DEIN-GITHUB-NAME/vereinsplugin
 * Primary Branch:    main
 * Release Asset:     true
 *
 * -----------------------------------------------------------------------------
 * GERÜST / STAGE 1
 * -----------------------------------------------------------------------------
 * Dieses Plugin bündelt vier bisher eigenständige Plugins als "Module" und
 * bootet sie über einen gemeinsamen Kern. Bestehende Datenbanktabellen
 * (wl_*, pp_*, jb_*) und die Rolle `wl_mitglied` werden unverändert
 * weitergenutzt – eine bestehende Installation kann die vier Einzel-Plugins
 * deaktivieren und stattdessen dieses aktivieren, ohne Datenverlust.
 *
 * Was der Kern in Stage 1 leistet:
 *   1. Lädt alle vier Module (modules/…) in der richtigen Reihenfolge.
 *   2. Räumt das Admin-Menü auf: die ~5 Top-Level-Menüs und ~22 Unterseiten
 *      der Module werden aus dem sichtbaren Menü entfernt. Übrig bleibt EIN
 *      Menü „Verein“ mit der Shortcode-Übersicht + einer Einstellungsseite.
 *   3. Stellt einen Kern-Shortcode `[verein_mitgliederbereich]` bereit, der
 *      die Modul-Mitgliederbereiche unter einer gemeinsamen Navigation bündelt.
 *   4. Führt die verstreuten Einstellungen (IBAN, API-Keys, Nextcloud, PWA)
 *      auf einer einzigen Seite zusammen.
 *
 * Was NOCH offen ist (Stage 2+, siehe PLAN.md):
 *   - Frontend-Portierung der reinen Admin-Funktionen (CSV-Importe,
 *     Mitglieder anlegen, Buchhaltungs-Backend, Event-Editor).
 *   - Vereinheitlichung der vier CSS-/JS-Sets zu einem Design.
 *   - Gemeinsame Kalender-/Aufgaben-/Personen-Schicht statt vier Silos.
 * -----------------------------------------------------------------------------
 */

defined( 'ABSPATH' ) || exit;

define( 'VP_VERSION', '0.1.0' );
define( 'VP_FILE', __FILE__ );
define( 'VP_PATH', plugin_dir_path( __FILE__ ) );
define( 'VP_URL', plugin_dir_url( __FILE__ ) );
define( 'VP_MODULES_PATH', VP_PATH . 'modules/' );

/**
 * GitHub-Repository für automatische Updates (Format "benutzer/repo").
 * Einmalig hier auf euren tatsächlichen Wert setzen ODER in der wp-config.php
 * per `define( 'VP_GITHUB_REPO', 'benutzer/repo' );` überschreiben.
 */
if ( ! defined( 'VP_GITHUB_REPO' ) ) {
	define( 'VP_GITHUB_REPO', 'DEIN-GITHUB-NAME/vereinsplugin' );
}

/**
 * Automatische Updates von GitHub-Releases.
 *
 * Nutzt die eingebundene Bibliothek "plugin-update-checker" (YahnisElsts, MIT).
 * WordPress zeigt neue Versionen dann ganz normal unter „Plugins → Aktualisieren“
 * an – kein Löschen/Neu-Hochladen nötig. Voraussetzung: im GitHub-Repo wird pro
 * Version ein Release mit angehängtem ZIP `vereinsplugin.zip` veröffentlicht
 * (macht der Workflow .github/workflows/release.yml automatisch beim Taggen).
 */
add_action( 'plugins_loaded', 'vp_bootstrap_update_checker', 0 );
function vp_bootstrap_update_checker() {
	$is_cli  = defined( 'WP_CLI' ) && WP_CLI;
	$is_cron = defined( 'DOING_CRON' ) && DOING_CRON;
	if ( ! is_admin() && ! $is_cli && ! $is_cron ) {
		return; // Update-Prüfung nur im Backend / per Cron / WP-CLI.
	}
	if ( empty( VP_GITHUB_REPO ) || strpos( VP_GITHUB_REPO, 'DEIN-GITHUB-NAME' ) === 0 ) {
		return; // Noch kein Repo konfiguriert – still nichts tun.
	}
	$loader = VP_PATH . 'vendor/plugin-update-checker/load-v5p6.php';
	if ( ! is_readable( $loader ) ) {
		return;
	}
	require_once $loader;

	if ( ! class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
		return;
	}

	$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/' . VP_GITHUB_REPO . '/',
		VP_FILE,
		'vereinsplugin'
	);

	// Stabilen Zweig festlegen und die an Releases angehängte ZIP verwenden
	// (sauberer als der automatische „Source code“-Tarball von GitHub).
	$checker->setBranch( 'main' );
	if ( method_exists( $checker->getVcsApi(), 'enableReleaseAssets' ) ) {
		$checker->getVcsApi()->enableReleaseAssets( '/vereinsplugin\.zip$/i' );
	}

	// Optionales privates Repo: Token in wp-config.php als VP_GITHUB_TOKEN.
	if ( defined( 'VP_GITHUB_TOKEN' ) && VP_GITHUB_TOKEN ) {
		$checker->setAuthentication( VP_GITHUB_TOKEN );
	}
}

/**
 * Kanonische Mitglieder-Rolle. Bleibt aus Kompatibilitätsgründen `wl_mitglied`
 * (so wie sie die vier Module heute schon erwarten). Der Kern fügt nur
 * einheitliche Helper und Capabilities hinzu, er benennt nichts um.
 */
define( 'VP_MEMBER_ROLE', 'wl_mitglied' );

/**
 * Registry aller Module. Reihenfolge = Ladereihenfolge.
 * `wunschliste` zuerst, weil es die Rolle `wl_mitglied` anlegt, auf die die
 * anderen drei Module aufsetzen.
 */
function vp_modules() {
	return array(
		'wunschliste' => array(
			'label' => __( 'Wunschliste & Spenden', 'vereinsplugin' ),
			'file'  => 'wunschliste/wunschliste.php',
			'menus' => array( 'wunschliste' ), // Top-Level-Slugs, die versteckt werden.
		),
		'protokoll' => array(
			'label' => __( 'Sitzungen & Protokolle', 'vereinsplugin' ),
			'file'  => 'protokoll/protokollpro.php',
			'menus' => array( 'protokollpro' ),
		),
		'buchhaltung' => array(
			'label' => __( 'Buchhaltung & Auslagen', 'vereinsplugin' ),
			'file'  => 'buchhaltung/jufo-buchhaltung.php',
			'menus' => array( 'jb_kassenbericht' ),
		),
		'events' => array(
			'label' => __( 'Veranstaltungs-Publisher', 'vereinsplugin' ),
			'file'  => 'events/jufobleibt-event-publisher.php',
			'menus' => array( 'jufobleibt-event-publisher-settings', 'edit.php?post_type=veranstaltung' ),
		),
	);
}

/**
 * Module laden. Jede Modul-Hauptdatei nutzt intern `plugin_dir_path(__FILE__)`
 * und funktioniert daher aus dem Unterordner heraus unverändert. Die
 * `register_activation_hook`-Aufrufe der Module laufen zwar ins Leere (aktiviert
 * wird ja dieses Plugin), aber alle vier Module haben zusätzlich einen
 * `plugins_loaded`-Upgrade-Check, der fehlende Tabellen/Rollen selbst anlegt.
 * Zur Sicherheit ruft vp_activate() die Aktivierungsroutinen zusätzlich direkt auf.
 */
add_action( 'plugins_loaded', 'vp_load_modules', 1 );
function vp_load_modules() {
	foreach ( vp_modules() as $key => $mod ) {
		if ( vp_module_enabled( $key ) ) {
			$path = VP_MODULES_PATH . $mod['file'];
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	}
	do_action( 'vp_modules_loaded' );
}

/**
 * Module lassen sich per Option einzeln abschalten (Einstellungsseite).
 * Standard: alle an.
 */
function vp_module_enabled( $key ) {
	$disabled = (array) get_option( 'vp_disabled_modules', array() );
	return ! in_array( $key, $disabled, true );
}

// Kern-Dateien.
require_once VP_PATH . 'includes/core-roles.php';
require_once VP_PATH . 'includes/shortcode-registry.php';
require_once VP_PATH . 'includes/member-area.php';
require_once VP_PATH . 'includes/admin-consolidation.php';
require_once VP_PATH . 'includes/settings-page.php';

/**
 * Aktivierung: Aktivierungsroutinen aller Module durchreichen, damit Tabellen,
 * Rollen und Cron-Jobs sofort existieren – nicht erst beim nächsten Request.
 */
register_activation_hook( __FILE__, 'vp_activate' );
function vp_activate() {
	// Module einbinden (plugins_loaded ist beim Aktivieren noch nicht gelaufen).
	foreach ( vp_modules() as $key => $mod ) {
		$path = VP_MODULES_PATH . $mod['file'];
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}

	// Wunschliste.
	if ( function_exists( 'wl_activate' ) ) { wl_activate(); }
	// ProtokollPro.
	if ( function_exists( 'pp_activate' ) ) { pp_activate(); }
	// Buchhaltung.
	if ( function_exists( 'jb_activate' ) ) { jb_activate(); }
	// Event-Publisher.
	if ( function_exists( 'jbf_activate_plugin' ) ) { jbf_activate_plugin(); }

	vp_core_setup_roles();
	flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'vp_deactivate' );
function vp_deactivate() {
	if ( function_exists( 'wl_deactivate' ) ) { wl_deactivate(); }
	if ( function_exists( 'pp_deactivate' ) ) { pp_deactivate(); }
	if ( function_exists( 'jb_deactivate' ) ) { jb_deactivate(); }
	if ( function_exists( 'jbf_deactivate_plugin' ) ) { jbf_deactivate_plugin(); }
	flush_rewrite_rules();
}
