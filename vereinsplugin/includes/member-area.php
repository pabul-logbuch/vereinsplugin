<?php
/**
 * Kern: gemeinsamer Mitglieder- + Vorstandsbereich (WebApp).
 *
 * [verein_mitgliederbereich]  – EIN Einstiegspunkt. Zeigt Sektionen abhängig von
 * den Capabilities der eingeloggten Person: normale Mitgliederfunktionen für
 * alle Mitglieder, Vorstands-/Kassenfunktionen nur mit der jeweiligen
 * Berechtigung.
 *
 * [verein_login]  – Login-Formular, darunter Link zum Mitgliedsantrag.
 *
 * Als PWA installierbar (siehe pwa.php).
 */

defined( 'ABSPATH' ) || exit;

add_shortcode( 'verein_login', 'vp_shortcode_login' );
add_shortcode( 'verein_mitgliederbereich', 'vp_shortcode_member_area' );

/* -------------------------------------------------------------------------
 * Login
 * ---------------------------------------------------------------------- */

function vp_shortcode_login( $atts ) {
	$atts = shortcode_atts( array( 'redirect' => '' ), $atts );

	// Login-URL merken (für Willkommens-Mails / Redirects).
	if ( get_permalink() ) {
		update_option( 'vp_login_url', get_permalink() );
	}

	ob_start();
	echo '<div class="vp-card vp-login">';

	if ( is_user_logged_in() ) {
		$u   = wp_get_current_user();
		$mb  = get_option( 'vp_member_area_url' );
		echo '<p>' . sprintf(
			/* translators: %s = display name */
			esc_html__( 'Eingeloggt als %s.', 'vereinsplugin' ),
			'<strong>' . esc_html( $u->display_name ) . '</strong>'
		) . '</p><p>';
		if ( $mb ) {
			echo '<a class="vp-btn vp-btn-primary" href="' . esc_url( $mb ) . '">' . esc_html__( 'Zum Mitgliederbereich', 'vereinsplugin' ) . '</a> ';
		}
		echo '<a class="vp-btn" href="' . esc_url( wp_logout_url( get_permalink() ) ) . '">' . esc_html__( 'Abmelden', 'vereinsplugin' ) . '</a></p>';
	} else {
		$redirect = $atts['redirect'] ? home_url( $atts['redirect'] ) : ( get_option( 'vp_member_area_url' ) ?: get_permalink() );
		echo '<h2>' . esc_html__( 'Mitglieder-Login', 'vereinsplugin' ) . '</h2>';
		wp_login_form( array(
			'redirect'       => $redirect,
			'label_username' => __( 'Benutzername oder E-Mail', 'vereinsplugin' ),
			'label_password' => __( 'Passwort', 'vereinsplugin' ),
			'label_remember' => __( 'Angemeldet bleiben', 'vereinsplugin' ),
			'label_log_in'   => __( 'Einloggen', 'vereinsplugin' ),
		) );
		echo '<p class="vp-login-links">';
		echo '<a href="' . esc_url( wp_lostpassword_url() ) . '">' . esc_html__( 'Passwort vergessen?', 'vereinsplugin' ) . '</a>';
		$antrag = vp_antrag_url();
		if ( $antrag ) {
			echo ' &nbsp;·&nbsp; <a href="' . esc_url( $antrag ) . '"><strong>' . esc_html__( 'Mitglied werden', 'vereinsplugin' ) . '</strong></a>';
		}
		echo '</p>';
	}

	echo '</div>';
	return ob_get_clean();
}

/**
 * URL der Seite mit [verein_mitgliedsantrag]: aus den Einstellungen oder
 * automatisch gesucht.
 */
function vp_antrag_url() {
	$page_id = (int) get_option( 'vp_antrag_page_id' );
	if ( $page_id && get_post_status( $page_id ) === 'publish' ) {
		return get_permalink( $page_id );
	}
	$found = get_transient( 'vp_antrag_page_lookup' );
	if ( false === $found ) {
		$found = 0;
		$q = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			's'              => '[verein_mitgliedsantrag',
			'fields'         => 'ids',
		) );
		if ( $q ) {
			$found = (int) $q[0];
		}
		set_transient( 'vp_antrag_page_lookup', $found, HOUR_IN_SECONDS );
	}
	return $found ? get_permalink( $found ) : '';
}

/* -------------------------------------------------------------------------
 * Sektions-Registry
 * ---------------------------------------------------------------------- */

/**
 * @return array key => [
 *   'label'    => string,
 *   'group'    => 'mitglied'|'vorstand',
 *   'cap'      => string   (mind. eine Capability),
 *   'render'   => callable  ODER
 *   'shortcode'=> string    (bestehender Modul-Shortcode zum Einbetten),
 *   'need_sc'  => bool       (nur zeigen, wenn Shortcode registriert ist),
 * ]
 */
function vp_member_sections() {
	$sections = array(

		'start' => array(
			'label'  => __( 'Start', 'vereinsplugin' ),
			'group'  => 'mitglied',
			'cap'    => 'read',
			'render' => 'vp_render_dashboard_section',
		),
		'wuensche' => array(
			'label'     => __( 'Wünsche', 'vereinsplugin' ),
			'group'     => 'mitglied',
			'cap'       => 'wl_manage_wishes',
			'shortcode' => 'wunschliste_verwaltung',
			'need_sc'   => true,
		),
		'abstimmung' => array(
			'label'     => __( 'Abstimmung', 'vereinsplugin' ),
			'group'     => 'mitglied',
			'cap'       => 'wl_manage_wishes',
			'shortcode' => 'wunschliste_voting',
			'need_sc'   => true,
		),
		'schichtplaene' => array(
			'label'    => __( 'Schichtpläne', 'vereinsplugin' ),
			'group'    => 'mitglied',
			'cap'      => 'read',
			'render'   => 'vp_render_schichtplaene_section',
			// Nur anzeigen, wenn das Schichtplan-Modul (Wunschliste) aktiv ist.
			'need_sc'  => true,
			'shortcode' => 'schichtplan',
		),
		'protokolle' => array(
			'label'     => __( 'Sitzungen & Protokolle', 'vereinsplugin' ),
			'group'     => 'mitglied',
			'cap'       => 'pp_manage',
			'shortcode' => 'protokollpro_mitgliederbereich',
			'need_sc'   => true,
		),
		'auslage' => array(
			'label'     => __( 'Auslage einreichen', 'vereinsplugin' ),
			'group'     => 'mitglied',
			'cap'       => 'jb_submit_auslagen',
			'shortcode' => 'jb_auslage_einreichen',
			'need_sc'   => true,
		),
		'meine_auslagen' => array(
			'label'     => __( 'Meine Auslagen', 'vereinsplugin' ),
			'group'     => 'mitglied',
			'cap'       => 'read',
			'shortcode' => 'jb_meine_auslagen',
			'need_sc'   => true,
		),
		'profil' => array(
			'label'  => __( 'Mein Profil', 'vereinsplugin' ),
			'group'  => 'mitglied',
			'cap'    => 'read',
			'render' => 'vp_render_profile_section',
		),

		/* ---- Vorstand ---- */

		'antraege' => array(
			'label'  => __( 'Anträge', 'vereinsplugin' ),
			'group'  => 'vorstand',
			'cap'    => 'vp_manage_members',
			'render' => 'vp_render_antraege_section',
		),
		'mitglieder' => array(
			'label'  => __( 'Mitglieder', 'vereinsplugin' ),
			'group'  => 'vorstand',
			'cap'    => 'vp_manage_members',
			'render' => 'vp_render_members_section',
		),
		'auslagen_pruefen' => array(
			'label'  => __( 'Auslagen prüfen', 'vereinsplugin' ),
			'group'  => 'vorstand',
			'cap'    => 'jb_approve_auslagen',
			'render' => 'vp_render_auslagen_pruefen_section',
		),
		'kasse' => array(
			'label'     => __( 'Kassenbericht', 'vereinsplugin' ),
			'group'     => 'vorstand',
			'cap'       => 'jb_view_journal',
			'shortcode' => 'jb_kassenbericht',
			'need_sc'   => true,
		),
		'buchhaltung' => array(
			'label'  => __( 'Buchhaltung (Backend)', 'vereinsplugin' ),
			'group'  => 'vorstand',
			'cap'    => 'jb_view_journal',
			'render' => 'vp_render_backend_links_buchhaltung',
		),
		'veranstaltungen' => array(
			'label'  => __( 'Veranstaltungen', 'vereinsplugin' ),
			'group'  => 'vorstand',
			'cap'    => 'jbf_edit_events',
			'render' => 'vp_render_backend_links_events',
		),
	);

	return apply_filters( 'vp_member_sections', $sections );
}

function vp_visible_sections() {
	$out = array();
	foreach ( vp_member_sections() as $key => $s ) {
		if ( ! current_user_can( $s['cap'] ) ) {
			continue;
		}
		if ( ! empty( $s['need_sc'] ) && ! shortcode_exists( $s['shortcode'] ) ) {
			continue;
		}
		$out[ $key ] = $s;
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * Bereich rendern
 * ---------------------------------------------------------------------- */

function vp_shortcode_member_area( $atts ) {
	$atts = shortcode_atts( array( 'start' => 'start' ), $atts );

	if ( get_permalink() ) {
		update_option( 'vp_member_area_url', get_permalink() );
	}

	if ( ! is_user_logged_in() ) {
		return '<div class="vp-card vp-note vp-note-warn">'
			. esc_html__( 'Bitte einloggen, um den Mitgliederbereich zu nutzen.', 'vereinsplugin' )
			. ' <a class="vp-btn vp-btn-primary" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Zum Login', 'vereinsplugin' ) . '</a>'
			. ( vp_antrag_url() ? ' <a class="vp-btn" href="' . esc_url( vp_antrag_url() ) . '">' . esc_html__( 'Mitglied werden', 'vereinsplugin' ) . '</a>' : '' )
			. '</div>';
	}

	// „Antrag offen“-Rolle: nur Wartehinweis.
	if ( in_array( 'vp_antrag_offen', (array) wp_get_current_user()->roles, true ) && ! vp_can_manage() ) {
		return '<div class="vp-card vp-note">' . esc_html__( 'Dein Mitgliedsantrag wird noch vom Vorstand geprüft. Du bekommst eine E-Mail, sobald er freigegeben ist.', 'vereinsplugin' ) . '</div>';
	}

	if ( ! vp_can_manage() && ! current_user_can( 'jb_submit_auslagen' ) ) {
		return '<div class="vp-card vp-note vp-note-error">' . esc_html__( 'Dein Konto hat keine Mitgliederrechte.', 'vereinsplugin' ) . '</div>';
	}

	$sections = vp_visible_sections();
	if ( empty( $sections ) ) {
		return '<div class="vp-card vp-note">' . esc_html__( 'Keine Bereiche verfügbar.', 'vereinsplugin' ) . '</div>';
	}

	$active = isset( $_GET['vp_tab'] ) ? sanitize_key( wp_unslash( $_GET['vp_tab'] ) ) : $atts['start'];
	if ( ! isset( $sections[ $active ] ) ) {
		$active = array_key_first( $sections );
	}

	$u = wp_get_current_user();

	// Basis-URL für die Navigations-Links: fester Permalink der aktuellen Seite,
	// nicht REQUEST_URI. Bewusst OHNE #-Anker – manche Themes fangen Klicks auf
	// Links mit Rautezeichen ab (Smooth-Scroll) und verhindern die Navigation.
	$base_url = get_permalink();
	if ( ! $base_url ) {
		$base_url = remove_query_arg( array( 'vp_tab', 'pp_view', 'id', 'vp_antrag_status' ) );
	}

	// Hinweis, wenn Mitglieder-Sektionen fehlen, weil ein Modul nicht aktiv ist.
	$hidden_modules = array();
	foreach ( vp_member_sections() as $skey => $sdef ) {
		if ( ! empty( $sdef['need_sc'] ) && current_user_can( $sdef['cap'] )
			&& ! shortcode_exists( $sdef['shortcode'] ) && ! isset( $sections[ $skey ] ) ) {
			$hidden_modules[] = $sdef['label'];
		}
	}

	ob_start();
	?>
	<div class="vp-app" id="vp-app">
		<header class="vp-app-head">
			<button type="button" class="vp-app-burger" aria-label="<?php esc_attr_e( 'Menü', 'vereinsplugin' ); ?>" aria-expanded="false">☰</button>
			<span class="vp-app-title"><?php echo esc_html( get_option( 'vp_app_name', get_bloginfo( 'name' ) ) ); ?></span>
			<span class="vp-app-user"><?php echo esc_html( $u->display_name ); ?></span>
			<a class="vp-app-logout" href="<?php echo esc_url( wp_logout_url( get_permalink() ) ); ?>" title="<?php esc_attr_e( 'Abmelden', 'vereinsplugin' ); ?>">⏻</a>
		</header>

		<div class="vp-app-body">
			<nav class="vp-app-nav" id="vp-app-nav">
				<?php
				$groups = array( 'mitglied' => '', 'vorstand' => __( 'Vorstand', 'vereinsplugin' ) );
				foreach ( $groups as $g => $g_label ) {
					$in_group = array_filter( $sections, function ( $s ) use ( $g ) { return $s['group'] === $g; } );
					if ( ! $in_group ) {
						continue;
					}
					if ( $g_label ) {
						echo '<div class="vp-nav-group">' . esc_html( $g_label ) . '</div>';
					}
					foreach ( $in_group as $key => $s ) {
						$url = add_query_arg( 'vp_tab', $key, $base_url );
						printf(
							'<a class="vp-nav-item%s" href="%s" data-vp-tab="%s">%s</a>',
							$key === $active ? ' is-active' : '',
							esc_url( $url ),
							esc_attr( $key ),
							esc_html( $s['label'] )
						);
					}
				}
				?>
				<?php if ( get_option( 'vp_pwa_enabled', '1' ) === '1' ) : ?>
					<button type="button" class="vp-nav-item vp-install-btn" hidden><?php esc_html_e( 'App installieren', 'vereinsplugin' ); ?></button>
				<?php endif; ?>
			</nav>

			<main class="vp-app-main" id="vp-app-main">
				<?php
				if ( $hidden_modules && 'start' === $active ) {
					echo '<div class="vp-note vp-note-warn">'
						. esc_html( sprintf(
							/* translators: %s = list of module names */
							__( 'Nicht angezeigt, weil das zugehörige Modul nicht aktiv ist: %s. Prüfe unter „Verein → Einstellungen“, ob das Modul aktiviert ist bzw. ob noch ein altes Einzel-Plugin läuft.', 'vereinsplugin' ),
							implode( ', ', $hidden_modules )
						) )
						. '</div>';
				}

				$s = $sections[ $active ];
				if ( ! empty( $s['render'] ) && is_callable( $s['render'] ) ) {
					echo call_user_func( $s['render'] ); // phpcs:ignore WordPress.Security.EscapeOutput
				} elseif ( ! empty( $s['shortcode'] ) ) {
					echo do_shortcode( '[' . $s['shortcode'] . ']' ); // phpcs:ignore WordPress.Security.EscapeOutput
				}
				?>
			</main>
		</div>
	</div>
	<script>
	(function(){
		var app = document.getElementById('vp-app');
		if (!app) return;
		// Nach dem Umschalten an den Anfang des Bereichs scrollen.
		if (location.search.indexOf('vp_tab=') !== -1 && !/[?&]pp_view=/.test(location.search)) {
			try { app.scrollIntoView({block:'start'}); } catch(e) { app.scrollIntoView(); }
		}
		// Navigation deterministisch selbst auslösen, damit kein Theme-Script
		// (Smooth-Scroll, One-Page-Nav) den Klick abfangen kann.
		app.querySelectorAll('.vp-nav-item[href]').forEach(function(a){
			a.addEventListener('click', function(ev){
				ev.preventDefault();
				window.location.href = a.href;
			});
		});
	})();
	</script>
	<?php
	return ob_get_clean();
}

/* -------------------------------------------------------------------------
 * Kern-Sektionen
 * ---------------------------------------------------------------------- */

function vp_render_dashboard_section() {
	$u = wp_get_current_user();
	ob_start();
	echo '<h2>' . sprintf(
		/* translators: %s = first name */
		esc_html__( 'Hallo %s', 'vereinsplugin' ),
		esc_html( $u->first_name ?: $u->display_name )
	) . '</h2>';

	echo '<div class="vp-tiles">';

	// Offene Aufgaben (ProtokollPro).
	if ( function_exists( 'pp_get_meine_aufgaben' ) ) {
		$auf = pp_get_meine_aufgaben( $u->ID );
		echo '<div class="vp-card vp-tile"><div class="vp-tile-num">' . (int) count( (array) $auf ) . '</div><div>' . esc_html__( 'offene Aufgaben', 'vereinsplugin' ) . '</div></div>';
	}
	// Meine Auslagen (Buchhaltung).
	if ( function_exists( 'jb_get_auslagen' ) ) {
		$mine = jb_get_auslagen( array( 'user_id' => $u->ID ) );
		$offen = array_filter( $mine, function ( $r ) { return in_array( ( is_object( $r ) ? $r->status : $r['status'] ), array( 'ausstehend', 'genehmigt' ), true ); } );
		echo '<div class="vp-card vp-tile"><div class="vp-tile-num">' . (int) count( $offen ) . '</div><div>' . esc_html__( 'Auslagen in Bearbeitung', 'vereinsplugin' ) . '</div></div>';
	}
	// Offene Anträge (nur Vorstand).
	if ( current_user_can( 'vp_manage_members' ) ) {
		global $wpdb;
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . vp_antraege_table() . " WHERE status = 'neu'" );
		echo '<div class="vp-card vp-tile' . ( $n ? ' vp-tile-alert' : '' ) . '"><div class="vp-tile-num">' . $n . '</div><div>' . esc_html__( 'offene Mitgliedsanträge', 'vereinsplugin' ) . '</div></div>';
	}
	echo '</div>';

	echo '<p class="vp-muted">' . esc_html__( 'Wähle links einen Bereich.', 'vereinsplugin' ) . '</p>';
	return ob_get_clean();
}

function vp_render_profile_section() {
	$u  = wp_get_current_user();
	$uid = $u->ID;
	$msg = '';

	if ( isset( $_POST['vp_profil_save'] ) && check_admin_referer( 'vp_profil', 'vp_profil_nonce' ) ) {
		wp_update_user( array(
			'ID'         => $uid,
			'first_name' => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ),
			'last_name'  => sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ),
			'user_email' => sanitize_email( wp_unslash( $_POST['user_email'] ?? $u->user_email ) ),
		) );
		foreach ( array( 'vp_telefon', 'vp_strasse', 'vp_plz', 'vp_ort', 'vp_land', 'vp_sepa_iban', 'vp_sepa_kontoinhaber' ) as $k ) {
			update_user_meta( $uid, $k, sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) ) );
		}
		$msg = __( 'Profil gespeichert.', 'vereinsplugin' );
		$u   = wp_get_current_user();
	}

	$m = function ( $k ) use ( $uid ) { return esc_attr( get_user_meta( $uid, $k, true ) ); };

	ob_start();
	echo '<h2>' . esc_html__( 'Mein Profil', 'vereinsplugin' ) . '</h2>';
	if ( $msg ) {
		echo '<div class="vp-note">' . esc_html( $msg ) . '</div>';
	}
	?>
	<form method="post" class="vp-form vp-card">
		<?php wp_nonce_field( 'vp_profil', 'vp_profil_nonce' ); ?>
		<div class="vp-form-grid">
			<label><?php esc_html_e( 'Vorname', 'vereinsplugin' ); ?><input type="text" name="first_name" value="<?php echo esc_attr( $u->first_name ); ?>"></label>
			<label><?php esc_html_e( 'Nachname', 'vereinsplugin' ); ?><input type="text" name="last_name" value="<?php echo esc_attr( $u->last_name ); ?>"></label>
			<label><?php esc_html_e( 'E-Mail', 'vereinsplugin' ); ?><input type="email" name="user_email" value="<?php echo esc_attr( $u->user_email ); ?>"></label>
			<label><?php esc_html_e( 'Telefon', 'vereinsplugin' ); ?><input type="tel" name="vp_telefon" value="<?php echo $m( 'vp_telefon' ); ?>"></label>
			<label class="vp-col-2"><?php esc_html_e( 'Straße & Nr.', 'vereinsplugin' ); ?><input type="text" name="vp_strasse" value="<?php echo $m( 'vp_strasse' ); ?>"></label>
			<label><?php esc_html_e( 'PLZ', 'vereinsplugin' ); ?><input type="text" name="vp_plz" value="<?php echo $m( 'vp_plz' ); ?>"></label>
			<label><?php esc_html_e( 'Ort', 'vereinsplugin' ); ?><input type="text" name="vp_ort" value="<?php echo $m( 'vp_ort' ); ?>"></label>
			<label><?php esc_html_e( 'Land', 'vereinsplugin' ); ?><input type="text" name="vp_land" value="<?php echo $m( 'vp_land' ); ?>"></label>
			<label class="vp-col-2"><?php esc_html_e( 'SEPA Kontoinhaber:in', 'vereinsplugin' ); ?><input type="text" name="vp_sepa_kontoinhaber" value="<?php echo $m( 'vp_sepa_kontoinhaber' ); ?>"></label>
			<label class="vp-col-2"><?php esc_html_e( 'SEPA IBAN', 'vereinsplugin' ); ?><input type="text" name="vp_sepa_iban" value="<?php echo $m( 'vp_sepa_iban' ); ?>"></label>
		</div>
		<p><button class="vp-btn vp-btn-primary" name="vp_profil_save" value="1"><?php esc_html_e( 'Speichern', 'vereinsplugin' ); ?></button>
		<a class="vp-btn" href="<?php echo esc_url( admin_url( 'profile.php' ) ); ?>"><?php esc_html_e( 'Passwort ändern', 'vereinsplugin' ); ?></a></p>
	</form>
	<?php
	return ob_get_clean();
}

/* ---- Schichtpläne: ansehen + eintragen + verwalten ---- */

function vp_render_schichtplaene_section() {
	if ( ! shortcode_exists( 'schichtplan' ) ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Das Schichtplan-Modul ist nicht aktiv.', 'vereinsplugin' ) . '</div>';
	}

	$can_manage = current_user_can( 'wl_manage_wishes' ) && shortcode_exists( 'schichtplan_verwaltung' );
	$view       = isset( $_GET['vp_sp'] ) ? sanitize_key( wp_unslash( $_GET['vp_sp'] ) ) : 'ansehen';
	if ( 'verwalten' === $view && ! $can_manage ) {
		$view = 'ansehen';
	}

	$base = get_permalink();
	if ( ! $base ) {
		$base = remove_query_arg( array( 'vp_sp', 'event', 'wl_abmelden', 'wl_abgemeldet' ) );
	}
	$url_ansehen   = add_query_arg( array( 'vp_tab' => 'schichtplaene', 'vp_sp' => 'ansehen' ), $base );
	$url_verwalten = add_query_arg( array( 'vp_tab' => 'schichtplaene', 'vp_sp' => 'verwalten' ), $base );

	ob_start();
	echo '<h2>' . esc_html__( 'Schichtpläne', 'vereinsplugin' ) . '</h2>';

	if ( $can_manage ) {
		echo '<nav class="vp-subnav">';
		printf( '<a class="%s" href="%s">%s</a>', 'ansehen' === $view ? 'is-active' : '', esc_url( $url_ansehen ), esc_html__( 'Ansehen & Eintragen', 'vereinsplugin' ) );
		printf( '<a class="%s" href="%s">%s</a>', 'verwalten' === $view ? 'is-active' : '', esc_url( $url_verwalten ), esc_html__( 'Verwalten', 'vereinsplugin' ) );
		echo '</nav>';
	}

	if ( 'verwalten' === $view ) {
		echo do_shortcode( '[schichtplan_verwaltung]' ); // phpcs:ignore WordPress.Security.EscapeOutput
		return ob_get_clean();
	}

	// Ansehen & Eintragen. Das Modul liest den Event nur aus dem Shortcode-
	// Attribut – hier den ?event=<slug> aus der URL nachreichen.
	$slug  = isset( $_GET['event'] ) ? sanitize_title( wp_unslash( $_GET['event'] ) ) : '';
	$event = ( $slug && function_exists( 'wl_get_event_by_slug' ) ) ? wl_get_event_by_slug( $slug ) : null;

	if ( $event && function_exists( 'wl_render_schichtplan' ) ) {
		printf(
			'<p><a class="vp-btn" href="%s">%s</a></p>',
			esc_url( $url_ansehen ),
			esc_html__( '← Alle Schichtpläne', 'vereinsplugin' )
		);
		echo wl_render_schichtplan( $event->id ); // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		// Übersicht aller aktiven Veranstaltungen (verlinkt jeweils auf ?event=slug).
		echo do_shortcode( '[schichtplan]' ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	return ob_get_clean();
}

/* ---- Vorstand: Mitgliederliste ---- */

function vp_render_members_section() {
	if ( ! current_user_can( 'vp_manage_members' ) ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) . '</div>';
	}
	$users = get_users( array( 'role__in' => array( VP_MEMBER_ROLE, 'pp_mitglied' ), 'orderby' => 'display_name' ) );
	ob_start();
	echo '<h2>' . esc_html__( 'Mitglieder', 'vereinsplugin' ) . ' <span class="vp-muted">(' . count( $users ) . ')</span></h2>';
	echo '<p><a class="vp-btn" href="' . esc_url( admin_url( 'admin.php?page=wunschliste-mitglied' ) ) . '">' . esc_html__( 'Mitglied manuell anlegen', 'vereinsplugin' ) . '</a> ';
	echo '<a class="vp-btn" href="' . esc_url( admin_url( 'admin.php?page=wunschliste-mitglieder-import' ) ) . '">' . esc_html__( 'CSV-Import', 'vereinsplugin' ) . '</a></p>';
	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Name', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'E-Mail', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Ort', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Mitglied seit', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
	foreach ( $users as $usr ) {
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( $usr->display_name ),
			esc_html( $usr->user_email ),
			esc_html( get_user_meta( $usr->ID, 'vp_ort', true ) ),
			esc_html( get_user_meta( $usr->ID, 'vp_mitglied_seit', true ) )
		);
	}
	echo '</tbody></table></div>';
	return ob_get_clean();
}

/* ---- Vorstand: Auslagen prüfen ---- */

function vp_render_auslagen_pruefen_section() {
	if ( ! current_user_can( 'jb_approve_auslagen' ) || ! function_exists( 'jb_get_auslagen' ) ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Nicht verfügbar.', 'vereinsplugin' ) . '</div>';
	}

	$pending  = jb_get_auslagen( array( 'status' => 'ausstehend' ) );
	$approved = jb_get_auslagen( array( 'status' => 'genehmigt' ) );

	ob_start();
	echo '<h2>' . esc_html__( 'Auslagen prüfen', 'vereinsplugin' ) . '</h2>';

	$render_row = function ( $r ) {
		$r = (object) $r;
		echo '<div class="vp-card vp-auslage" data-id="' . (int) $r->id . '">';
		printf(
			'<div class="vp-auslage-head"><strong>%s €</strong> · %s · %s</div>',
			esc_html( number_format( (float) $r->betrag, 2, ',', '.' ) ),
			esc_html( $r->user_name ),
			esc_html( date_i18n( 'd.m.Y', strtotime( $r->ausgabe_datum ) ) )
		);
		echo '<div class="vp-muted">' . esc_html( $r->kategorie ) . ' — ' . esc_html( $r->beschreibung ) . '</div>';
		echo '<div class="vp-auslage-actions">';
		if ( 'ausstehend' === $r->status ) {
			echo '<button class="vp-btn vp-btn-primary vp-jb-approve" data-do="approve">' . esc_html__( 'Genehmigen', 'vereinsplugin' ) . '</button> ';
			echo '<button class="vp-btn vp-btn-danger vp-jb-approve" data-do="reject">' . esc_html__( 'Ablehnen', 'vereinsplugin' ) . '</button>';
		} elseif ( 'genehmigt' === $r->status ) {
			echo '<button class="vp-btn vp-jb-paid">' . esc_html__( 'Als ausgezahlt markieren', 'vereinsplugin' ) . '</button>';
		}
		echo '</div><div class="vp-auslage-msg"></div></div>';
	};

	echo '<h3>' . esc_html__( 'Wartet auf Prüfung', 'vereinsplugin' ) . '</h3>';
	if ( $pending ) {
		foreach ( $pending as $r ) {
			$render_row( $r );
		}
	} else {
		echo '<p class="vp-muted">' . esc_html__( 'Nichts offen.', 'vereinsplugin' ) . '</p>';
	}

	echo '<h3>' . esc_html__( 'Genehmigt – noch nicht ausgezahlt', 'vereinsplugin' ) . '</h3>';
	if ( $approved ) {
		foreach ( $approved as $r ) {
			$render_row( $r );
		}
	} else {
		echo '<p class="vp-muted">' . esc_html__( 'Nichts offen.', 'vereinsplugin' ) . '</p>';
	}

	// Nutzt die bestehenden AJAX-Endpunkte des Buchhaltungs-Moduls (JB global).
	?>
	<script>
	(function($){
		if (typeof JB === 'undefined') return;
		function post(data, el){
			el.disabled = true;
			$.post(JB.ajax_url, $.extend({nonce: JB.nonce}, data), function(res){
				var card = $(el).closest('.vp-auslage');
				var ok = res && res.success === true;
				if (ok) {
					card.find('.vp-auslage-msg').text('<?php echo esc_js( __( 'Gespeichert. Bitte Seite neu laden.', 'vereinsplugin' ) ); ?>');
					card.css('opacity', .5);
					card.find('.vp-btn').prop('disabled', true);
				} else {
					card.find('.vp-auslage-msg').text((res && res.data) ? res.data : '<?php echo esc_js( __( 'Fehler.', 'vereinsplugin' ) ); ?>');
					el.disabled = false;
				}
			});
		}
		$(document).on('click', '.vp-jb-approve', function(){
			var id = $(this).closest('.vp-auslage').data('id');
			var dov = $(this).data('do');
			var notiz = dov === 'reject' ? (window.prompt('<?php echo esc_js( __( 'Grund für die Ablehnung (optional):', 'vereinsplugin' ) ); ?>') || '') : '';
			post({action:'jb_decide_auslage', id:id, action_type:dov, notiz:notiz}, this);
		});
		$(document).on('click', '.vp-jb-paid', function(){
			var id = $(this).closest('.vp-auslage').data('id');
			post({action:'jb_mark_paid', id:id}, this);
		});
	})(jQuery);
	</script>
	<?php
	return ob_get_clean();
}

/* ---- Vorstand: Backend-Verweise für (noch) nicht portierte Tools ---- */

function vp_render_backend_links_buchhaltung() {
	ob_start();
	echo '<h2>' . esc_html__( 'Buchhaltung', 'vereinsplugin' ) . '</h2>';
	echo '<p class="vp-muted">' . esc_html__( 'Diese Auswertungen liegen noch im WordPress-Backend. Der Kassenbericht und die Auslagen-Prüfung sind bereits hier im Mitgliederbereich.', 'vereinsplugin' ) . '</p>';
	echo '<p>';
	foreach ( array(
		'jb_budgets'   => __( 'Budgets & Rücklagen', 'vereinsplugin' ),
		'jb_getraenke' => __( 'Getränkekasse', 'vereinsplugin' ),
		'jb_journal'   => __( 'Buchungsjournal', 'vereinsplugin' ),
		'jb_export'    => __( 'EÜR / DATEV-Export', 'vereinsplugin' ),
		'jb_settings'  => __( 'Nextcloud-Einstellungen', 'vereinsplugin' ),
	) as $slug => $label ) {
		echo '<a class="vp-btn" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $label ) . '</a> ';
	}
	echo '</p>';
	return ob_get_clean();
}

function vp_render_backend_links_events() {
	ob_start();
	echo '<h2>' . esc_html__( 'Veranstaltungen', 'vereinsplugin' ) . '</h2>';
	echo '<p class="vp-muted">' . esc_html__( 'Der Veranstaltungs-Editor (Texte je Kanal, Kampagnen, Versand an Social Media / Presse) liegt noch im WordPress-Backend.', 'vereinsplugin' ) . '</p>';
	echo '<p><a class="vp-btn" href="' . esc_url( admin_url( 'edit.php?post_type=veranstaltung' ) ) . '">' . esc_html__( 'Veranstaltungen öffnen', 'vereinsplugin' ) . '</a> ';
	echo '<a class="vp-btn" href="' . esc_url( admin_url( 'post-new.php?post_type=veranstaltung' ) ) . '">' . esc_html__( 'Neue Veranstaltung', 'vereinsplugin' ) . '</a></p>';
	return ob_get_clean();
}

/* -------------------------------------------------------------------------
 * Assets
 * ---------------------------------------------------------------------- */

add_action( 'wp_enqueue_scripts', 'vp_member_area_assets', 4 );
function vp_member_area_assets() {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post ) {
		return;
	}
	$is_area   = has_shortcode( $post->post_content, 'verein_mitgliederbereich' );
	$is_login  = has_shortcode( $post->post_content, 'verein_login' );
	$is_antrag = has_shortcode( $post->post_content, 'verein_mitgliedsantrag' );
	if ( ! $is_area && ! $is_login && ! $is_antrag ) {
		return;
	}

	if ( $is_area ) {
		// ProtokollPro lädt sein CSS/JS sonst nur bei pp-eigenen Shortcodes.
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
	}

	$css_file = VP_PATH . 'assets/app.css';
	wp_enqueue_style( 'vp-app', VP_URL . 'assets/app.css', array(), is_readable( $css_file ) ? filemtime( $css_file ) : VP_VERSION );

	$js_file = VP_PATH . 'assets/app.js';
	if ( is_readable( $js_file ) ) {
		wp_enqueue_script( 'vp-app', VP_URL . 'assets/app.js', array( 'jquery' ), filemtime( $js_file ), true );
	}
}
