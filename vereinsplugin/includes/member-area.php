<?php
/**
 * Kern: gemeinsamer Mitgliederbereich.
 *
 * [verein_mitgliederbereich] rendert eine Tab-Navigation und bettet darunter
 * die vorhandenen Modul-Shortcodes ein. So gibt es EINE Seite / EINEN
 * Einstiegspunkt für Mitglieder statt vier getrennter Bereiche – ohne dass
 * die Module dafür umgeschrieben werden müssen (Stage 1).
 *
 * Stage 2 ersetzt die eingebetteten Modul-Shortcodes schrittweise durch
 * echte, ineinander verzahnte Ansichten (gemeinsame Aufgaben-/Termin-/
 * Personenliste statt Silos).
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'verein_login', 'vp_shortcode_login' );
add_shortcode( 'verein_mitgliederbereich', 'vp_shortcode_member_area' );

function vp_shortcode_login( $atts ) {
	$atts = shortcode_atts( array( 'redirect' => '' ), $atts );
	if ( is_user_logged_in() ) {
		$u = wp_get_current_user();
		return '<div class="vp-note">' . sprintf(
			/* translators: %s: display name */
			esc_html__( 'Eingeloggt als %s.', 'vereinsplugin' ),
			'<strong>' . esc_html( $u->display_name ) . '</strong>'
		) . ' <a href="' . esc_url( wp_logout_url( get_permalink() ) ) . '">' . esc_html__( 'Abmelden', 'vereinsplugin' ) . '</a></div>';
	}
	$redirect = $atts['redirect'] ? home_url( $atts['redirect'] ) : get_permalink();
	ob_start();
	echo '<div class="vp-login">';
	wp_login_form( array(
		'redirect'       => $redirect,
		'label_username' => __( 'Benutzername', 'vereinsplugin' ),
		'label_password' => __( 'Passwort', 'vereinsplugin' ),
		'label_remember' => __( 'Angemeldet bleiben', 'vereinsplugin' ),
		'label_log_in'   => __( 'Einloggen', 'vereinsplugin' ),
	) );
	echo '</div>';
	return ob_get_clean();
}

/**
 * Tabs des Mitgliederbereichs. Jeder Tab bettet einen bestehenden
 * Modul-Shortcode ein. `cap` steuert Sichtbarkeit.
 */
function vp_member_area_tabs() {
	$tabs = array(
		'wuensche'      => array(
			'label'     => __( 'Wünsche', 'vereinsplugin' ),
			'shortcode' => 'wunschliste_verwaltung',
			'cap'       => 'wl_manage_wishes',
		),
		'abstimmung'    => array(
			'label'     => __( 'Abstimmung', 'vereinsplugin' ),
			'shortcode' => 'wunschliste_voting',
			'cap'       => 'wl_manage_wishes',
		),
		'schichtplaene' => array(
			'label'     => __( 'Schichtpläne', 'vereinsplugin' ),
			'shortcode' => 'schichtplan_verwaltung',
			'cap'       => 'wl_manage_wishes',
		),
		'protokolle'    => array(
			'label'     => __( 'Sitzungen & Protokolle', 'vereinsplugin' ),
			'shortcode' => 'protokollpro_mitgliederbereich',
			'cap'       => 'pp_manage',
		),
		'auslage'       => array(
			'label'     => __( 'Auslage einreichen', 'vereinsplugin' ),
			'shortcode' => 'jb_auslage_einreichen',
			'cap'       => 'jb_submit_auslagen',
		),
		'meine_auslagen' => array(
			'label'     => __( 'Meine Auslagen', 'vereinsplugin' ),
			'shortcode' => 'jb_meine_auslagen',
			'cap'       => 'read',
		),
		'kasse'         => array(
			'label'     => __( 'Kassenbericht', 'vereinsplugin' ),
			'shortcode' => 'jb_kassenbericht',
			'cap'       => 'jb_view_journal',
		),
	);

	return apply_filters( 'vp_member_area_tabs', $tabs );
}

function vp_shortcode_member_area( $atts ) {
	$atts = shortcode_atts( array( 'start' => 'wuensche' ), $atts );

	if ( ! is_user_logged_in() ) {
		return '<div class="vp-note vp-note-warn">'
			. esc_html__( 'Bitte einloggen, um den Mitgliederbereich zu nutzen.', 'vereinsplugin' )
			. ' <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Zum Login', 'vereinsplugin' ) . '</a></div>';
	}
	if ( ! vp_can_manage() ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Dein Konto hat keine Mitgliederrechte.', 'vereinsplugin' ) . '</div>';
	}

	// Merkt sich die Mitgliederbereich-URL für Redirects aus dem Backend.
	if ( get_permalink() ) {
		update_option( 'vp_member_area_url', get_permalink() );
	}

	$tabs = array_filter( vp_member_area_tabs(), function ( $t ) {
		return current_user_can( $t['cap'] ) && shortcode_exists( $t['shortcode'] );
	} );

	if ( empty( $tabs ) ) {
		return '<div class="vp-note">' . esc_html__( 'Keine Module aktiv.', 'vereinsplugin' ) . '</div>';
	}

	$active = isset( $_GET['vp_tab'] ) ? sanitize_key( wp_unslash( $_GET['vp_tab'] ) ) : $atts['start'];
	if ( ! isset( $tabs[ $active ] ) ) {
		$active = array_key_first( $tabs );
	}

	$u = wp_get_current_user();

	ob_start();
	?>
	<div class="vp-member-area">
		<header class="vp-ma-head">
			<div>
				<strong><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong>
				<span class="vp-ma-user"><?php echo esc_html( $u->display_name ); ?></span>
			</div>
			<a class="vp-ma-logout" href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Abmelden', 'vereinsplugin' ); ?></a>
		</header>

		<nav class="vp-ma-tabs">
			<?php foreach ( $tabs as $key => $tab ) :
				$url = add_query_arg( 'vp_tab', $key, remove_query_arg( array( 'pp_view', 'id' ) ) );
				?>
				<a class="vp-ma-tab<?php echo $key === $active ? ' is-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>#vp-ma-panel">
					<?php echo esc_html( $tab['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<div class="vp-ma-panel" id="vp-ma-panel">
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput -- shortcode output.
			echo do_shortcode( '[' . $tabs[ $active ]['shortcode'] . ']' );
			?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Minimal-CSS für die Hülle. Modul-CSS lädt jedes Modul selbst; Stage 2
 * vereinheitlicht das.
 */
add_action( 'wp_enqueue_scripts', 'vp_member_area_assets', 4 );
function vp_member_area_assets() {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! has_shortcode( $post->post_content, 'verein_mitgliederbereich' ) ) {
		return;
	}

	/*
	 * Stage-1-Shim: Einige Module laden ihr Frontend-CSS/JS nur, wenn ihr
	 * eigener Shortcode direkt im Seiteninhalt steht. Beim Einbetten über
	 * [verein_mitgliederbereich] ist das nicht der Fall – deshalb hier
	 * gezielt nachladen.
	 */
	// ProtokollPro (prüft sonst has_shortcode auf pp-eigene Tags).
	$pp_dir = VP_MODULES_PATH . 'protokoll/assets/';
	$pp_url = VP_URL . 'modules/protokoll/assets/';
	if ( is_readable( $pp_dir . 'style.css' ) ) {
		wp_enqueue_style( 'pp-style', $pp_url . 'style.css', array(), filemtime( $pp_dir . 'style.css' ) );
	}
	if ( is_readable( $pp_dir . 'script.js' ) ) {
		wp_enqueue_script( 'pp-script', $pp_url . 'script.js', array( 'jquery' ), filemtime( $pp_dir . 'script.js' ), true );
		wp_localize_script( 'pp-script', 'pp_ajax', array(
			'url'   => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'pp_nonce' ),
		) );
	}

	wp_register_style( 'vp-core', false, array(), VP_VERSION );
	wp_enqueue_style( 'vp-core' );
	wp_add_inline_style( 'vp-core', '
		.vp-member-area{max-width:1100px;margin:0 auto}
		.vp-ma-head{display:flex;justify-content:space-between;align-items:center;padding:12px 4px;border-bottom:1px solid #e5e7eb;margin-bottom:12px}
		.vp-ma-user{margin-left:10px;color:#6b7280}
		.vp-ma-tabs{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:18px}
		.vp-ma-tab{padding:8px 14px;border-radius:8px 8px 0 0;background:#f3f4f6;color:#374151;text-decoration:none;font-size:14px}
		.vp-ma-tab.is-active{background:#111827;color:#fff}
		.vp-note{padding:12px 16px;border-radius:8px;background:#f3f4f6;margin:12px 0}
		.vp-note-warn{background:#fef3c7}
		.vp-note-error{background:#fee2e2}
		.vp-login{max-width:360px}
	' );
}
