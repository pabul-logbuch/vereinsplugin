<?php
/**
 * Kern: Admin-Menü-Konsolidierung.
 *
 * Ziel (Vorgabe): Im WordPress-Dashboard erscheint durch dieses Plugin so gut
 * wie nichts – nur EINE Seite mit der Übersicht aller Shortcodes samt
 * Beschreibung. Reine Konfiguration (IBAN, API-Zugänge, Nextcloud, PWA) liegt
 * als einzige Unterseite darunter.
 *
 * Umsetzung:
 *   - Die Module registrieren ihre Admin-Seiten weiterhin (priority 10), damit
 *     noch nicht ins Frontend portierte Funktionen (Importe etc.) erreichbar
 *     bleiben.
 *   - Wir hängen uns mit priority 999 in `admin_menu` und entfernen die
 *     Modul-Menüs aus der sichtbaren Navigation. Die Seiten-Callbacks bleiben
 *     via `admin.php?page=…` aufrufbar (Direktlinks auf der Shortcode-Seite).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Das eine Menü.
 */
add_action( 'admin_menu', 'vp_register_admin_menu', 9 );
function vp_register_admin_menu() {
	add_menu_page(
		__( 'Verein', 'vereinsplugin' ),
		__( 'Verein', 'vereinsplugin' ),
		'edit_posts',
		'vereinsplugin',
		'vp_render_shortcodes_page',
		'dashicons-groups',
		3
	);
	add_submenu_page(
		'vereinsplugin',
		__( 'Shortcodes', 'vereinsplugin' ),
		__( 'Shortcodes', 'vereinsplugin' ),
		'edit_posts',
		'vereinsplugin',
		'vp_render_shortcodes_page'
	);
	add_submenu_page(
		'vereinsplugin',
		__( 'Einstellungen', 'vereinsplugin' ),
		__( 'Einstellungen', 'vereinsplugin' ),
		'manage_options',
		'vereinsplugin-einstellungen',
		'vp_render_settings_page'
	);
}

/**
 * Modul-Menüs verstecken (nicht entfernen – Seiten bleiben per URL nutzbar).
 */
add_action( 'admin_menu', 'vp_hide_module_menus', 999 );
function vp_hide_module_menus() {
	if ( get_option( 'vp_show_module_menus' ) === '1' ) {
		return; // Notausgang für Debugging.
	}

	$slugs = array();
	foreach ( vp_modules() as $mod ) {
		foreach ( (array) ( $mod['menus'] ?? array() ) as $slug ) {
			$slugs[] = $slug;
		}
	}
	// PWA-/Nextcloud-Einstellungsseite von ProtokollPro (separat registriert).
	$slugs[] = 'pp-app';

	foreach ( $slugs as $slug ) {
		remove_menu_page( $slug );
	}

	// Der „Veranstaltung“-CPT hängt sich als eigenes Top-Level-Menü ein.
	remove_menu_page( 'edit.php?post_type=veranstaltung' );
}

/**
 * Die einzige inhaltliche Admin-Seite: Shortcode-Übersicht.
 */
function vp_render_shortcodes_page() {
	$catalog = vp_shortcode_catalog();
	$scope_label = array(
		'public'   => array( __( 'Öffentlich', 'vereinsplugin' ), '#2563eb' ),
		'member'   => array( __( 'Mitglieder', 'vereinsplugin' ), '#16a34a' ),
		'vorstand' => array( __( 'Vorstand/Kassier', 'vereinsplugin' ), '#b45309' ),
	);
	?>
	<div class="wrap vp-shortcodes">
		<h1><?php esc_html_e( 'Vereinsplugin – Shortcodes', 'vereinsplugin' ); ?></h1>
		<p class="vp-intro">
			<?php esc_html_e( 'Dieses Plugin fügt dem Dashboard bewusst nur diese Übersichtsseite hinzu. Die gesamte Verwaltung läuft über die folgenden Shortcodes auf normalen WordPress-Seiten. Empfehlung: eine Seite „Mitgliederbereich“ mit [verein_mitgliederbereich], eine öffentliche Seite mit [wunschliste], und je nach Bedarf weitere.', 'vereinsplugin' ); ?>
		</p>

		<?php foreach ( $catalog as $gruppe => $items ) : ?>
			<h2 class="vp-group"><?php echo esc_html( $gruppe ); ?></h2>
			<table class="widefat striped vp-table">
				<thead>
					<tr>
						<th style="width:24%"><?php esc_html_e( 'Shortcode', 'vereinsplugin' ); ?></th>
						<th style="width:16%"><?php esc_html_e( 'Beispiel-Attribute', 'vereinsplugin' ); ?></th>
						<th style="width:12%"><?php esc_html_e( 'Sichtbar für', 'vereinsplugin' ); ?></th>
						<th><?php esc_html_e( 'Beschreibung', 'vereinsplugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $items as $sc ) :
					$sl = $scope_label[ $sc['scope'] ] ?? array( $sc['scope'], '#555' );
					?>
					<tr>
						<td>
							<code class="vp-code">[<?php echo esc_html( $sc['tag'] ); ?>]</code>
							<button type="button" class="button-link vp-copy" data-clip="[<?php echo esc_attr( $sc['tag'] ); ?>]" title="<?php esc_attr_e( 'Kopieren', 'vereinsplugin' ); ?>">⧉</button>
						</td>
						<td><?php echo $sc['attrs'] ? '<code>' . esc_html( $sc['attrs'] ) . '</code>' : '<span class="vp-muted">–</span>'; ?></td>
						<td><span class="vp-badge" style="background:<?php echo esc_attr( $sl[1] ); ?>"><?php echo esc_html( $sl[0] ); ?></span></td>
						<td><?php echo esc_html( $sc['desc'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>

		<h2 class="vp-group"><?php esc_html_e( 'Admin-Direktlinks (noch ohne Frontend-Seite)', 'vereinsplugin' ); ?></h2>
		<p class="vp-muted"><?php esc_html_e( 'Diese Funktionen sind aus dem Menü ausgeblendet, aber weiterhin erreichbar. In Stage 2 wandern sie ins Frontend.', 'vereinsplugin' ); ?></p>
		<ul class="vp-links">
			<?php foreach ( vp_admin_direct_links() as $l ) : ?>
				<li><a href="<?php echo esc_url( $l['url'] ); ?>"><?php echo esc_html( $l['label'] ); ?></a></li>
			<?php endforeach; ?>
		</ul>
	</div>

	<style>
		.vp-shortcodes .vp-intro{max-width:820px;font-size:14px}
		.vp-shortcodes .vp-group{margin-top:28px}
		.vp-shortcodes .vp-table{margin-top:8px}
		.vp-shortcodes .vp-code{font-size:13px}
		.vp-shortcodes .vp-badge{color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;white-space:nowrap}
		.vp-shortcodes .vp-muted{color:#777}
		.vp-shortcodes .vp-copy{cursor:pointer;text-decoration:none;font-size:14px;vertical-align:middle}
		.vp-shortcodes .vp-links{list-style:disc;margin-left:20px}
	</style>
	<script>
		document.querySelectorAll('.vp-copy').forEach(function(b){
			b.addEventListener('click',function(){
				navigator.clipboard && navigator.clipboard.writeText(b.dataset.clip);
				var o=b.textContent;b.textContent='✓';setTimeout(function(){b.textContent=o;},1200);
			});
		});
	</script>
	<?php
}
