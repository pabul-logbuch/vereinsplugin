<?php
/**
 * Plugin Name:       Vereinsplugin
 * Plugin URI:        https://github.com/pabul-logbuch/vereinsplugin
 * Description:        Alles-in-einem-Vereinsverwaltung: Wunschliste & Spenden, Sitzungs-/Protokollverwaltung, Buchhaltung & Auslagen, Veranstaltungs-Publisher – mit gemeinsamem Mitgliederbereich. Im WordPress-Dashboard erscheint bewusst nur eine einzige Seite: die Shortcode-Übersicht. Alle Verwaltung läuft über Shortcodes im Frontend.
 * Version:           0.17.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Verein
 * Text Domain:       vereinsplugin
 * Domain Path:       /languages
 * GitHub Plugin URI: pabul-logbuch/vereinsplugin
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

define( 'VP_VERSION', '0.17.0' );
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
	define( 'VP_GITHUB_REPO', 'pabul-logbuch/vereinsplugin' );
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
	if ( empty( VP_GITHUB_REPO ) || '' === trim( VP_GITHUB_REPO ) || false === strpos( VP_GITHUB_REPO, '/' ) ) {
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
			// Sentinel: existiert diese Funktion schon, ist das Alt-Plugin aktiv
			// → Modul NICHT laden (sonst „Cannot redeclare function“ = Fatal).
			'guard' => 'wl_create_tables',
			'legacy_plugins' => array( 'wunschliste-plugin/wunschliste.php' ),
		),
		'protokoll' => array(
			'label' => __( 'Sitzungen & Protokolle', 'vereinsplugin' ),
			'file'  => 'protokoll/protokollpro.php',
			'menus' => array( 'protokollpro' ),
			'guard' => 'pp_create_tables',
			'legacy_plugins' => array( 'protokollpro/protokollpro.php' ),
		),
		'buchhaltung' => array(
			'label' => __( 'Buchhaltung & Auslagen', 'vereinsplugin' ),
			'file'  => 'buchhaltung/jufo-buchhaltung.php',
			'menus' => array( 'jb_kassenbericht' ),
			'guard' => 'jb_create_tables',
			'legacy_plugins' => array( 'jufo-buchhaltung-v2/jufo-buchhaltung.php', 'jufo-buchhaltung/jufo-buchhaltung.php' ),
		),
		'events' => array(
			'label' => __( 'Veranstaltungs-Publisher', 'vereinsplugin' ),
			'file'  => 'events/jufobleibt-event-publisher.php',
			'menus' => array( 'jufobleibt-event-publisher-settings', 'edit.php?post_type=veranstaltung' ),
			'guard' => 'jbf_init_plugin',
			'legacy_plugins' => array( 'jufobleibt-event-publisher/jufobleibt-event-publisher.php' ),
		),
	);
}

/**
 * Darf/kann ein Modul geladen werden? Nein, wenn es abgeschaltet ist ODER wenn
 * seine Kernfunktion schon existiert (= das gleichnamige Alt-Plugin ist noch
 * aktiv). Verhindert den „Cannot redeclare“-Fatal.
 */
function vp_module_loadable( $key, $mod ) {
	if ( ! vp_module_enabled( $key ) ) {
		return false;
	}
	if ( ! empty( $mod['guard'] ) && function_exists( $mod['guard'] ) ) {
		return false;
	}
	$path = VP_MODULES_PATH . $mod['file'];
	return is_readable( $path );
}

/**
 * Lädt eine Modul-Hauptdatei defensiv. Ein Parse-/Fatal-Fehler in einem Modul
 * (ParseError/Error sind seit PHP 7 fangbar) legt dann nicht das ganze Plugin
 * lahm, sondern nur dieses eine Modul.
 */
function vp_safe_require( $path, $key ) {
	try {
		require_once $path;
		return true;
	} catch ( \Throwable $e ) {
		vp_note_module_error( $key, $e->getMessage() );
		return false;
	}
}

function vp_note_module_error( $key, $message ) {
	$errors         = get_option( 'vp_module_errors', array() );
	$errors[ $key ] = $message;
	update_option( 'vp_module_errors', $errors );
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
		if ( vp_module_loadable( $key, $mod ) ) {
			vp_safe_require( VP_MODULES_PATH . $mod['file'], $key );
		}
	}
	do_action( 'vp_modules_loaded' );
}

/**
 * Admin-Hinweis, wenn ein Modul wegen eines noch aktiven Alt-Plugins oder wegen
 * eines Fehlers nicht geladen wurde.
 */
add_action( 'admin_notices', 'vp_admin_notices' );
function vp_admin_notices() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$conflicts = array();
	foreach ( vp_modules() as $key => $mod ) {
		if ( ! empty( $mod['guard'] ) && function_exists( $mod['guard'] ) && ! vp_self_provided( $mod['guard'] ) ) {
			$conflicts[] = $mod['label'];
		}
	}
	if ( $conflicts ) {
		echo '<div class="notice notice-error"><p><strong>Vereinsplugin:</strong> '
			. esc_html( sprintf(
				/* translators: %s = module list */
				__( 'Diese Alt-Plugins sind noch aktiv und blockieren die zugehörigen Module: %s. Bitte unter „Plugins“ deaktivieren – die Daten bleiben erhalten.', 'vereinsplugin' ),
				implode( ', ', $conflicts )
			) )
			. '</p></div>';
	}

	$errors = get_option( 'vp_module_errors', array() );
	if ( $errors ) {
		foreach ( $errors as $key => $msg ) {
			echo '<div class="notice notice-warning"><p><strong>Vereinsplugin – Modul „' . esc_html( $key ) . '“:</strong> ' . esc_html( $msg ) . '</p></div>';
		}
	}
}

/**
 * Grobe Heuristik: Stammt die Guard-Funktion aus unserem modules/-Ordner
 * (dann ist alles ok) oder aus einem fremden Pfad (Alt-Plugin aktiv)?
 */
function vp_self_provided( $function_name ) {
	try {
		$ref  = new ReflectionFunction( $function_name );
		$file = $ref->getFileName();
		return $file && strpos( $file, VP_MODULES_PATH ) === 0;
	} catch ( \Throwable $e ) {
		return false;
	}
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
require_once VP_PATH . 'includes/membership-application.php';
require_once VP_PATH . 'includes/buchhaltung-extras.php';
require_once VP_PATH . 'includes/buchhaltung-skr.php';
require_once VP_PATH . 'includes/nextcloud-sync.php';
require_once VP_PATH . 'includes/newsletter.php';
require_once VP_PATH . 'includes/protokoll-bereich.php';
require_once VP_PATH . 'includes/rest-sync-api.php';
require_once VP_PATH . 'includes/member-area.php';
require_once VP_PATH . 'includes/pwa.php';
require_once VP_PATH . 'includes/admin-consolidation.php';
require_once VP_PATH . 'includes/settings-page.php';

/**
 * Aktivierung: Aktivierungsroutinen aller Module durchreichen, damit Tabellen,
 * Rollen und Cron-Jobs sofort existieren – nicht erst beim nächsten Request.
 */
register_activation_hook( __FILE__, 'vp_activate' );
function vp_activate() {
	delete_option( 'vp_module_errors' );

	// Module einbinden (plugins_loaded ist beim Aktivieren noch nicht gelaufen).
	// Guard: ein noch aktives Alt-Plugin würde denselben Code ein zweites Mal
	// laden → Fatal. Solche Module hier überspringen.
	foreach ( vp_modules() as $key => $mod ) {
		if ( vp_module_loadable( $key, $mod ) ) {
			vp_safe_require( VP_MODULES_PATH . $mod['file'], $key );
		}
	}

	// Aktivierungsroutinen der geladenen Module – einzeln abgesichert.
	foreach ( array( 'wl_activate', 'pp_activate', 'jb_activate', 'jbf_activate_plugin' ) as $fn ) {
		if ( function_exists( $fn ) && vp_self_provided( $fn ) ) {
			try {
				call_user_func( $fn );
			} catch ( \Throwable $e ) {
				vp_note_module_error( $fn, $e->getMessage() );
			}
		}
	}

	vp_core_setup_roles();

	if ( function_exists( 'vp_create_antraege_table' ) ) {
		vp_create_antraege_table();
	}

	flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'vp_deactivate' );
function vp_deactivate() {
	foreach ( array( 'wl_deactivate', 'pp_deactivate', 'jb_deactivate', 'jbf_deactivate_plugin' ) as $fn ) {
		if ( function_exists( $fn ) && vp_self_provided( $fn ) ) {
			try {
				call_user_func( $fn );
			} catch ( \Throwable $e ) {
				// Beim Deaktivieren Fehler schlucken – Hauptsache es geht durch.
			}
		}
	}
	wp_clear_scheduled_hook( 'vp_nc_sync_cron' );
	flush_rewrite_rules();
}
