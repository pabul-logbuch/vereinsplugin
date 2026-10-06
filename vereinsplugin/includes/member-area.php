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
		'kalender' => array(
			'label'  => __( 'Kalender', 'vereinsplugin' ),
			'group'  => 'mitglied',
			'cap'    => 'read',
			'render' => 'vp_render_kalender_section',
		),
		'abstimmung' => array(
			'label'     => __( 'Wunschliste & Abstimmung', 'vereinsplugin' ),
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
			'label'  => __( 'Sitzungen & Protokolle', 'vereinsplugin' ),
			'group'  => 'mitglied',
			'cap'    => 'pp_manage',
			'render' => 'vp_render_protokoll_bereich',
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
		'kassenbericht' => array(
			'label'     => __( 'Kassenbericht', 'vereinsplugin' ),
			'group'     => 'mitglied',
			'cap'       => 'read',
			'shortcode' => 'jb_kassenbericht',
			'need_sc'   => true,
		),
		'profil' => array(
			'label'  => __( 'Mein Profil', 'vereinsplugin' ),
			'group'  => 'mitglied',
			'cap'    => 'read',
			'render' => 'vp_render_profile_section',
		),

		/* ---- Vorstand · Mitgliederverwaltung ---- */

		'antraege' => array(
			'label'  => __( 'Mitgliedsanträge', 'vereinsplugin' ),
			'group'  => 'vorstand_mv',
			'cap'    => 'vp_manage_members',
			'render' => 'vp_render_antraege_section',
		),
		'mitglieder' => array(
			'label'  => __( 'Mitglieder', 'vereinsplugin' ),
			'group'  => 'vorstand_mv',
			'cap'    => 'vp_manage_members',
			'render' => 'vp_render_members_section',
		),
		'newsletter' => array(
			'label'  => __( 'Newsletter', 'vereinsplugin' ),
			'group'  => 'vorstand_mv',
			'cap'    => 'vp_manage_members',
			'render' => 'vp_render_newsletter_section',
		),

		/* ---- Vorstand · Kassier:in ---- */

		'auslagen_pruefen' => array(
			'label'  => __( 'Auslagen prüfen', 'vereinsplugin' ),
			'group'  => 'vorstand_kasse',
			'cap'    => 'jb_approve_auslagen',
			'render' => 'vp_render_auslagen_pruefen_section',
		),
		'buchhaltung' => array(
			'label'  => __( 'Buchhaltung / Journal', 'vereinsplugin' ),
			'group'  => 'vorstand_kasse',
			'cap'    => 'jb_view_journal',
			'render' => function_exists( 'vp_render_buchhaltung_hub' ) ? 'vp_render_buchhaltung_hub' : 'vp_render_backend_links_buchhaltung',
		),
		'budgets' => array(
			'label'  => __( 'Budgets & Rücklagen', 'vereinsplugin' ),
			'group'  => 'vorstand_kasse',
			'cap'    => 'jb_view_journal',
			'render' => 'vp_render_budgets_section',
		),
		'rechnungen' => array(
			'label'  => __( 'Rechnungen', 'vereinsplugin' ),
			'group'  => 'vorstand_kasse',
			'cap'    => 'jb_view_journal',
			'render' => 'vp_render_rechnungen_section',
		),
		'sepa' => array(
			'label'  => __( 'SEPA-Lastschrift', 'vereinsplugin' ),
			'group'  => 'vorstand_kasse',
			'cap'    => 'jb_view_journal',
			'render' => 'vp_render_sepa_section',
		),
		'spenden' => array(
			'label'  => __( 'Spenden & Bescheinigungen', 'vereinsplugin' ),
			'group'  => 'vorstand_kasse',
			'cap'    => 'jb_view_journal',
			'render' => 'vp_render_spenden_section',
		),

		/* ---- Vorstand · Sonstige ---- */

		'wuensche' => array(
			'label'     => __( 'Wunschlistenverwaltung', 'vereinsplugin' ),
			'group'     => 'vorstand_sonstige',
			'cap'       => 'wl_manage_wishes',
			'shortcode' => 'wunschliste_verwaltung',
			'need_sc'   => true,
		),
		'schichten_verwaltung' => array(
			'label'     => __( 'Schichtplanverwaltung', 'vereinsplugin' ),
			'group'     => 'vorstand_sonstige',
			'cap'       => 'wl_manage_wishes',
			'shortcode' => 'schichtplan_verwaltung',
			'need_sc'   => true,
		),
		'nextcloud' => array(
			'label'  => __( 'Nextcloud-Sync', 'vereinsplugin' ),
			'group'  => 'vorstand_sonstige',
			'cap'    => 'vp_manage_members',
			'render' => 'vp_render_nextcloud_section',
		),
		'veranstaltungen' => array(
			'label'  => __( 'Veranstaltungen', 'vereinsplugin' ),
			'group'  => 'vorstand_sonstige',
			'cap'    => 'jbf_edit_events',
			'render' => 'vp_render_backend_links_events',
		),
		'formulare' => array(
			'label'  => __( 'Formulare', 'vereinsplugin' ),
			'group'  => 'vorstand_sonstige',
			'cap'    => 'vp_manage_formulare',
			'render' => 'vp_render_formulare_section',
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
	// ProtokollPro-Links setzen nur ?pp_view – die Ansicht bestimmt den Bereich
	// (Aufgaben, Sitzungen & Protokolle, Kreise …).
	if ( isset( $_GET['pp_view'] ) && function_exists( 'vp_pp_tab_fuer_view' ) ) {
		$pp_tab = vp_pp_tab_fuer_view( sanitize_key( wp_unslash( $_GET['pp_view'] ) ) );
		if ( isset( $sections[ $pp_tab ] ) ) {
			$active = $pp_tab;
		}
	}
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
				$groups = array(
					'mitglied'          => '',
					'vorstand_mv'       => __( 'Vorstand · Mitgliederverwaltung', 'vereinsplugin' ),
					'vorstand_kasse'    => __( 'Vorstand · Kassier:in', 'vereinsplugin' ),
					'vorstand_sonstige' => __( 'Vorstand · Sonstige', 'vereinsplugin' ),
				);
				foreach ( $groups as $g => $g_label ) {
					$in_group = array_filter( $sections, function ( $s ) use ( $g ) { return $s['group'] === $g; } );
					if ( ! $in_group ) {
						continue;
					}
					// Benannte Gruppen sind einklappbar – offen, wenn der aktive
					// Bereich darin liegt (sonst merkt sich app.js den Zustand).
					if ( $g_label ) {
						printf(
							'<details class="vp-nav-fold vp-nav-fold-group" data-fold="%s"%s><summary class="vp-nav-group">%s</summary>',
							esc_attr( $g ),
							isset( $in_group[ $active ] ) ? ' open data-has-active="1"' : '',
							esc_html( $g_label )
						);
					}
					foreach ( $in_group as $key => $s ) {
						// Bereiche mit Unterpunkten (z. B. Sitzungen & Protokolle).
						if ( ! empty( $s['children'] ) && is_callable( $s['children'] ) ) {
							vp_render_nav_children( $key, $s, $base_url, $key === $active );
							continue;
						}
						$url   = add_query_arg( 'vp_tab', $key, $base_url );
						$badge = ( ! empty( $s['badge'] ) && is_callable( $s['badge'] ) ) ? (string) call_user_func( $s['badge'] ) : '';
						printf(
							'<a class="vp-nav-item%s" href="%s" data-vp-tab="%s"><span class="vp-nav-label">%s</span>%s</a>',
							$key === $active ? ' is-active' : '',
							esc_url( $url ),
							esc_attr( $key ),
							esc_html( $s['label'] ),
							'' !== $badge ? '<span class="vp-nav-badge">' . esc_html( $badge ) . '</span>' : ''
						);
					}
					if ( $g_label ) {
						echo '</details>';
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

				// z. B. Leiste „Live-Sitzung läuft“ (includes/live-sitzung.php).
				do_action( 'vp_member_area_vor_inhalt', $active );

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
	// Übersicht über alle Bereiche: includes/start-uebersicht.php.
	return vp_start_render();
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
		foreach ( array( 'vp_telefon', 'vp_strasse', 'vp_plz', 'vp_ort', 'vp_land', 'vp_sepa_iban', 'vp_sepa_kontoinhaber', 'vp_erstattung_iban', 'vp_erstattung_kontoinhaber' ) as $k ) {
			update_user_meta( $uid, $k, sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) ) );
		}
		$msg = __( 'Profil gespeichert.', 'vereinsplugin' ) . vp_member_ibans_normalisieren( $uid );
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
		</div>
		<fieldset>
			<legend><?php esc_html_e( 'Bankverbindungen', 'vereinsplugin' ); ?></legend>
			<div class="vp-form-grid">
				<p class="vp-muted vp-col-2"><?php esc_html_e( 'Konto für den Mitgliedsbeitrag (SEPA-Lastschrift):', 'vereinsplugin' ); ?></p>
				<label class="vp-col-2"><?php esc_html_e( 'Kontoinhaber:in', 'vereinsplugin' ); ?><input type="text" name="vp_sepa_kontoinhaber" value="<?php echo $m( 'vp_sepa_kontoinhaber' ); ?>"></label>
				<label class="vp-col-2"><?php esc_html_e( 'IBAN', 'vereinsplugin' ); ?><input type="text" name="vp_sepa_iban" value="<?php echo $m( 'vp_sepa_iban' ); ?>" autocomplete="off"></label>
				<p class="vp-muted vp-col-2"><?php esc_html_e( 'Konto für Erstattungen von Auslagen – leer lassen, wenn dafür das Beitragskonto genutzt werden soll:', 'vereinsplugin' ); ?></p>
				<label class="vp-col-2"><?php esc_html_e( 'Kontoinhaber:in', 'vereinsplugin' ); ?><input type="text" name="vp_erstattung_kontoinhaber" value="<?php echo $m( 'vp_erstattung_kontoinhaber' ); ?>"></label>
				<label class="vp-col-2"><?php esc_html_e( 'IBAN', 'vereinsplugin' ); ?><input type="text" name="vp_erstattung_iban" value="<?php echo $m( 'vp_erstattung_iban' ); ?>" autocomplete="off"></label>
			</div>
		</fieldset>
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

/* ---- Vorstand: Mitglieder ansehen und bearbeiten ---- */

/**
 * Rollen, die im Verein vorkommen: Schlüssel => Anzeigename. Nur Rollen, die es
 * auf dieser Installation wirklich gibt (vp_antrag_offen z. B. nur, wenn der
 * Antragsablauf „wartender Zugang“ eingestellt ist).
 */
function vp_member_role_labels() {
	$labels = array(
		VP_MEMBER_ROLE    => __( 'Vereinsmitglied', 'vereinsplugin' ),
		'pp_mitglied'     => __( 'Vereinsmitglied (alte Rolle)', 'vereinsplugin' ),
		'editor'          => __( 'Vorstand', 'vereinsplugin' ),
		'administrator'   => __( 'Administrator:in', 'vereinsplugin' ),
		'vp_antrag_offen' => __( 'Antrag offen (kein Zugang)', 'vereinsplugin' ),
		'vp_ehemalig'     => __( 'Ehemaliges Mitglied (kein Zugang)', 'vereinsplugin' ),
	);
	foreach ( array_keys( $labels ) as $role ) {
		if ( ! get_role( $role ) ) {
			unset( $labels[ $role ] );
		}
	}
	return $labels;
}

/** Rollen, die hier vergeben werden dürfen – die Altlast `pp_mitglied` nicht. */
function vp_member_assignable_roles() {
	$roles = vp_member_role_labels();
	unset( $roles['pp_mitglied'] );
	return $roles;
}

function vp_member_roles_text( $user ) {
	$labels = vp_member_role_labels();
	$names  = array();
	foreach ( (array) $user->roles as $r ) {
		$names[] = isset( $labels[ $r ] ) ? $labels[ $r ] : $r;
	}
	return $names ? implode( ', ', $names ) : __( 'keine Rolle', 'vereinsplugin' );
}

/**
 * Darf die aktuelle Person dieses Konto bearbeiten? Konten mit Admin-Rechten
 * darf nur eine Administrator:in anfassen – sonst könnte der Vorstand (Editor)
 * das Passwort/die Mail einer Admin-Person ändern und sich so hochstufen.
 */
function vp_member_can_edit( $user ) {
	if ( ! current_user_can( 'vp_manage_members' ) || ! $user || ! $user->exists() ) {
		return false;
	}
	return ! user_can( $user->ID, 'manage_options' ) || current_user_can( 'manage_options' );
}

/** Rollenwechsel: nur mit `promote_users` (Admin) und nie an sich selbst. */
function vp_member_can_edit_role( $user ) {
	return vp_member_can_edit( $user )
		&& current_user_can( 'promote_users' )
		&& (int) $user->ID !== get_current_user_id();
}

/** URL in den Mitglieder-Bereich (Liste oder ein einzelnes Konto). */
function vp_members_url( $args = array() ) {
	$base = get_permalink();
	if ( ! $base ) {
		$base = remove_query_arg( array( 'vp_member', 'vp_q', 'vp_role' ) );
	}
	return add_query_arg( array_merge( array( 'vp_tab' => 'mitglieder' ), $args ), $base );
}

/**
 * Query-Parameter der Ziel-URL als Hidden-Felder ausgeben. Nötig für GET-
 * Formulare: bei „einfachen“ Permalinks steckt die Seiten-ID (?page_id=…) im
 * action-Attribut und würde beim Absenden verloren gehen.
 */
function vp_hidden_query_fields( $url ) {
	$query = wp_parse_url( $url, PHP_URL_QUERY );
	if ( ! $query ) {
		return;
	}
	parse_str( $query, $args );
	foreach ( $args as $k => $v ) {
		if ( is_scalar( $v ) ) {
			printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $k ), esc_attr( $v ) );
		}
	}
}

/** Einfache Textfelder am Mitgliedskonto (gleiche Schlüssel wie Antrag + Sync). */
function vp_member_meta_keys() {
	return array(
		'vp_telefon', 'vp_geburtsdatum', 'vp_strasse', 'vp_plz', 'vp_ort', 'vp_land',
		'vp_mitglied_seit', 'vp_ausgetreten_am', 'vp_mitglieds_nr', 'vp_mitgliedsart',
		'vp_beitrag', 'vp_beitrag_intervall',
		'vp_sepa_kontoinhaber', 'vp_sepa_iban', 'vp_mandatsref',
		'vp_erstattung_kontoinhaber', 'vp_erstattung_iban',
	);
}

/**
 * IBANs am Konto normalisieren (Leerzeichen raus, Großbuchstaben). Eine
 * kaputte IBAN blockiert das Speichern nicht, wird aber gemeldet.
 * Gibt den Warnhinweis (mit führendem Leerzeichen) oder '' zurück.
 */
function vp_member_ibans_normalisieren( $user_id ) {
	if ( ! function_exists( 'vp_iban_normalize' ) ) {
		return '';
	}
	$zusatz = '';
	foreach ( array( 'vp_sepa_iban', 'vp_erstattung_iban' ) as $k ) {
		$iban = vp_iban_normalize( (string) get_user_meta( $user_id, $k, true ) );
		update_user_meta( $user_id, $k, $iban );
		if ( '' !== $iban && function_exists( 'vp_iban_valid' ) && ! vp_iban_valid( $iban ) ) {
			$zusatz .= ' ' . sprintf( __( 'Achtung: Die IBAN %s sieht nicht gültig aus.', 'vereinsplugin' ), $iban );
		}
	}
	return $zusatz;
}

function vp_mitgliedsarten() {
	return array(
		''          => __( '– nicht festgelegt –', 'vereinsplugin' ),
		'aktiv'     => __( 'aktiv', 'vereinsplugin' ),
		'passiv'    => __( 'passiv', 'vereinsplugin' ),
		'foerdernd' => __( 'fördernd', 'vereinsplugin' ),
	);
}

function vp_beitrag_intervalle() {
	return array(
		''                 => __( '– kein Beitrag –', 'vereinsplugin' ),
		'monatlich'        => __( 'monatlich', 'vereinsplugin' ),
		'vierteljaehrlich' => __( 'vierteljährlich', 'vereinsplugin' ),
		'halbjaehrlich'    => __( 'halbjährlich', 'vereinsplugin' ),
		'jaehrlich'        => __( 'jährlich', 'vereinsplugin' ),
	);
}

/** Ämter der Person aus ProtokollPro: „Kreis · Rolle“ (nur laufende Amtszeiten). */
function vp_member_aemter( $user_id ) {
	if ( ! function_exists( 'pp_get_gremien' ) || ! function_exists( 'pp_get_rollenvorlagen_fuer_gremium' ) ) {
		return array();
	}
	$out = array();
	foreach ( pp_get_gremien( null, false ) as $g ) {
		foreach ( pp_get_rollenvorlagen_fuer_gremium( $g->id ) as $vorlage ) {
			foreach ( pp_get_aktuelle_besetzungen( $vorlage->id ) as $b ) {
				if ( (int) $b->user_id === (int) $user_id ) {
					$out[] = array(
						'gremium_id' => (int) $g->id,
						'text'       => $g->name . ' · ' . $vorlage->bezeichnung,
					);
				}
			}
		}
	}
	return $out;
}

function vp_render_members_section() {
	if ( ! current_user_can( 'vp_manage_members' ) ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) . '</div>';
	}
	$edit_id = isset( $_GET['vp_member'] ) ? (int) $_GET['vp_member'] : 0;
	if ( isset( $_POST['vp_member_id'] ) ) {
		$edit_id = (int) $_POST['vp_member_id'];
	}
	return $edit_id ? vp_render_member_edit( $edit_id ) : vp_render_members_list();
}

/* ---- Liste ---- */

function vp_render_members_list() {
	$labels = vp_member_role_labels();

	$q    = isset( $_GET['vp_q'] ) ? sanitize_text_field( wp_unslash( $_GET['vp_q'] ) ) : '';
	$role = isset( $_GET['vp_role'] ) ? sanitize_key( wp_unslash( $_GET['vp_role'] ) ) : 'alle';
	if ( 'alle' !== $role && ! isset( $labels[ $role ] ) ) {
		$role = 'alle';
	}

	$users = get_users( array( 'role__in' => array_keys( $labels ), 'orderby' => 'display_name' ) );
	$total = count( $users );
	$needle = $q ? vp_strtolower( $q ) : '';

	$rows = array();
	foreach ( $users as $usr ) {
		if ( 'alle' !== $role && ! in_array( $role, (array) $usr->roles, true ) ) {
			continue;
		}
		// Ehemalige nur zeigen, wenn ausdrücklich danach gefiltert wird.
		if ( 'alle' === $role && array( 'vp_ehemalig' ) === array_values( (array) $usr->roles ) ) {
			continue;
		}
		$ort = (string) get_user_meta( $usr->ID, 'vp_ort', true );
		if ( $needle ) {
			$heu = vp_strtolower( implode( ' ', array(
				$usr->display_name, $usr->user_email, $usr->user_login, $ort,
				(string) get_user_meta( $usr->ID, 'vp_plz', true ),
			) ) );
			if ( false === strpos( $heu, $needle ) ) {
				continue;
			}
		}
		$rows[] = array( 'user' => $usr, 'ort' => $ort );
	}

	$list_url = vp_members_url();

	ob_start();
	echo '<h2>' . esc_html__( 'Mitglieder', 'vereinsplugin' ) . ' <span class="vp-muted">(' . (int) count( $rows )
		. ( count( $rows ) !== $total ? ' / ' . (int) $total : '' ) . ')</span></h2>';

	if ( current_user_can( 'manage_options' ) ) {
		echo '<p><a class="vp-btn" href="' . esc_url( admin_url( 'admin.php?page=wunschliste-mitglied' ) ) . '">' . esc_html__( 'Mitglied manuell anlegen', 'vereinsplugin' ) . '</a> ';
		echo '<a class="vp-btn" href="' . esc_url( admin_url( 'admin.php?page=wunschliste-mitglieder-import' ) ) . '">' . esc_html__( 'CSV-Import', 'vereinsplugin' ) . '</a></p>';
	}
	?>
	<form method="get" class="vp-form vp-member-filter" action="<?php echo esc_url( $list_url ); ?>">
		<?php vp_hidden_query_fields( $list_url ); ?>
		<label><?php esc_html_e( 'Suche (Name, E-Mail, Ort)', 'vereinsplugin' ); ?>
			<input type="search" name="vp_q" value="<?php echo esc_attr( $q ); ?>"></label>
		<label><?php esc_html_e( 'Rolle', 'vereinsplugin' ); ?>
			<select name="vp_role">
				<option value="alle"><?php esc_html_e( 'alle Rollen (ohne Ehemalige)', 'vereinsplugin' ); ?></option>
				<?php foreach ( $labels as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $role, $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select></label>
		<p><button class="vp-btn vp-btn-primary"><?php esc_html_e( 'Filtern', 'vereinsplugin' ); ?></button>
		<?php if ( $q || 'alle' !== $role ) : ?>
			<a class="vp-btn" href="<?php echo esc_url( $list_url ); ?>"><?php esc_html_e( 'Zurücksetzen', 'vereinsplugin' ); ?></a>
		<?php endif; ?></p>
	</form>
	<?php

	if ( ! $rows ) {
		echo '<p class="vp-muted">' . esc_html__( 'Keine Mitglieder in dieser Ansicht.', 'vereinsplugin' ) . '</p>';
		return ob_get_clean();
	}

	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr>'
		. '<th>' . esc_html__( 'Name', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Rolle', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'E-Mail', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Ort', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Mitglied seit', 'vereinsplugin' ) . '</th>'
		. '<th></th></tr></thead><tbody>';

	foreach ( $rows as $row ) {
		$usr  = $row['user'];
		$url  = vp_members_url( array( 'vp_member' => $usr->ID ) );
		$seit = (string) get_user_meta( $usr->ID, 'vp_mitglied_seit', true );
		$seit_ts = $seit ? strtotime( $seit ) : 0;
		printf(
			'<tr><td><a href="%1$s">%2$s</a></td><td><span class="vp-badge">%3$s</span></td><td>%4$s</td><td>%5$s</td><td>%6$s</td>'
			. '<td><a class="vp-btn" href="%1$s">%7$s</a></td></tr>',
			esc_url( $url ),
			esc_html( $usr->display_name ),
			esc_html( vp_member_roles_text( $usr ) ),
			esc_html( $usr->user_email ),
			esc_html( $row['ort'] ),
			esc_html( $seit_ts ? date_i18n( 'd.m.Y', $seit_ts ) : $seit ),
			esc_html__( 'Bearbeiten', 'vereinsplugin' )
		);
	}
	echo '</tbody></table></div>';
	return ob_get_clean();
}

function vp_strtolower( $s ) {
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
}

/* ---- Einzelnes Mitglied bearbeiten ---- */

function vp_render_member_edit( $user_id ) {
	$user      = get_userdata( (int) $user_id );
	$back      = '<p><a class="vp-btn" href="' . esc_url( vp_members_url() ) . '">' . esc_html__( '← Zur Mitgliederliste', 'vereinsplugin' ) . '</a></p>';
	if ( ! $user || ! vp_member_can_edit( $user ) ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Dieses Konto kann hier nicht bearbeitet werden. Konten mit Administrator-Rechten ändert nur eine Administrator:in.', 'vereinsplugin' ) . '</div>' . $back;
	}

	$msg   = '';
	$is_err = false;
	if ( isset( $_POST['vp_member_pwmail'] ) && check_admin_referer( 'vp_member_edit', 'vp_member_nonce' ) ) {
		$sent = retrieve_password( $user->user_login );
		if ( is_wp_error( $sent ) ) {
			$msg    = $sent->get_error_message();
			$is_err = true;
		} else {
			$msg = __( 'Link zum Passwort-Setzen wurde an die hinterlegte E-Mail-Adresse geschickt.', 'vereinsplugin' );
		}
	} elseif ( isset( $_POST['vp_member_save'] ) && check_admin_referer( 'vp_member_edit', 'vp_member_nonce' ) ) {
		list( $state, $msg ) = vp_member_save( $user );
		$is_err = ( 'error' === $state );
		$user   = get_userdata( (int) $user_id ); // Frisch laden: Rolle/Name können sich geändert haben.
	}

	$m = function ( $k ) use ( $user ) { return esc_attr( get_user_meta( $user->ID, $k, true ) ); };
	$can_role = vp_member_can_edit_role( $user );
	$aemter   = vp_member_aemter( $user->ID );

	ob_start();
	echo $back; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<h2>' . esc_html( $user->display_name ) . ' <span class="vp-badge">' . esc_html( vp_member_roles_text( $user ) ) . '</span></h2>';
	echo '<p class="vp-muted">' . esc_html( sprintf(
		/* translators: 1: user login, 2: registration date */
		__( 'Benutzername: %1$s · Konto angelegt am %2$s', 'vereinsplugin' ),
		$user->user_login,
		date_i18n( 'd.m.Y', strtotime( $user->user_registered ) )
	) ) . '</p>';
	if ( $msg ) {
		echo '<div class="vp-note' . ( $is_err ? ' vp-note-error' : '' ) . '">' . esc_html( $msg ) . '</div>';
	}
	?>
	<form method="post" class="vp-form vp-card vp-member-edit">
		<?php wp_nonce_field( 'vp_member_edit', 'vp_member_nonce' ); ?>
		<input type="hidden" name="vp_member_id" value="<?php echo (int) $user->ID; ?>">

		<fieldset>
			<legend><?php esc_html_e( 'Stammdaten', 'vereinsplugin' ); ?></legend>
			<div class="vp-form-grid">
				<label><?php esc_html_e( 'Vorname', 'vereinsplugin' ); ?><input type="text" name="first_name" value="<?php echo esc_attr( $user->first_name ); ?>"></label>
				<label><?php esc_html_e( 'Nachname', 'vereinsplugin' ); ?><input type="text" name="last_name" value="<?php echo esc_attr( $user->last_name ); ?>"></label>
				<label class="vp-col-2"><?php esc_html_e( 'Anzeigename (erscheint überall im Mitgliederbereich)', 'vereinsplugin' ); ?><input type="text" name="display_name" value="<?php echo esc_attr( $user->display_name ); ?>"></label>
				<label><?php esc_html_e( 'E-Mail', 'vereinsplugin' ); ?><input type="email" name="user_email" value="<?php echo esc_attr( $user->user_email ); ?>"></label>
				<label><?php esc_html_e( 'Telefon', 'vereinsplugin' ); ?><input type="tel" name="vp_telefon" value="<?php echo $m( 'vp_telefon' ); ?>"></label>
				<label><?php esc_html_e( 'Geburtsdatum', 'vereinsplugin' ); ?><input type="date" name="vp_geburtsdatum" value="<?php echo $m( 'vp_geburtsdatum' ); ?>"></label>
			</div>
		</fieldset>

		<fieldset>
			<legend><?php esc_html_e( 'Anschrift', 'vereinsplugin' ); ?></legend>
			<div class="vp-form-grid">
				<label class="vp-col-2"><?php esc_html_e( 'Straße & Nr.', 'vereinsplugin' ); ?><input type="text" name="vp_strasse" value="<?php echo $m( 'vp_strasse' ); ?>"></label>
				<label><?php esc_html_e( 'PLZ', 'vereinsplugin' ); ?><input type="text" name="vp_plz" value="<?php echo $m( 'vp_plz' ); ?>"></label>
				<label><?php esc_html_e( 'Ort', 'vereinsplugin' ); ?><input type="text" name="vp_ort" value="<?php echo $m( 'vp_ort' ); ?>"></label>
				<label><?php esc_html_e( 'Land', 'vereinsplugin' ); ?><input type="text" name="vp_land" value="<?php echo $m( 'vp_land' ); ?>"></label>
			</div>
		</fieldset>

		<fieldset>
			<legend><?php esc_html_e( 'Mitgliedschaft', 'vereinsplugin' ); ?></legend>
			<div class="vp-form-grid">
				<label class="vp-col-2"><?php esc_html_e( 'Rolle im Verein', 'vereinsplugin' ); ?>
					<?php if ( $can_role ) : ?>
						<select name="vp_member_role">
							<?php
							$rollen  = array_values( (array) $user->roles );
							$aktuell = $rollen ? $rollen[0] : '';
							foreach ( vp_member_assignable_roles() as $key => $label ) {
								printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $aktuell, $key, false ), esc_html( $label ) );
							}
							?>
						</select>
					<?php else : ?>
						<input type="text" value="<?php echo esc_attr( vp_member_roles_text( $user ) ); ?>" disabled>
					<?php endif; ?>
				</label>
				<?php if ( ! $can_role ) : ?>
					<p class="vp-muted vp-col-2"><?php
						echo (int) $user->ID === get_current_user_id()
							? esc_html__( 'Die eigene Rolle kann hier nicht geändert werden.', 'vereinsplugin' )
							: esc_html__( 'Rollen vergibt nur eine Administrator:in.', 'vereinsplugin' );
					?></p>
				<?php endif; ?>
				<label><?php esc_html_e( 'Mitglieds-Nr.', 'vereinsplugin' ); ?><input type="text" name="vp_mitglieds_nr" value="<?php echo $m( 'vp_mitglieds_nr' ); ?>"></label>
				<label><?php esc_html_e( 'Mitgliedsart', 'vereinsplugin' ); ?>
					<select name="vp_mitgliedsart">
						<?php
						$art = (string) get_user_meta( $user->ID, 'vp_mitgliedsart', true );
						foreach ( vp_mitgliedsarten() as $key => $label ) {
							printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $art, $key, false ), esc_html( $label ) );
						}
						?>
					</select></label>
				<label><?php esc_html_e( 'Mitglied seit', 'vereinsplugin' ); ?><input type="date" name="vp_mitglied_seit" value="<?php echo $m( 'vp_mitglied_seit' ); ?>"></label>
				<label><?php esc_html_e( 'Ausgetreten am', 'vereinsplugin' ); ?><input type="date" name="vp_ausgetreten_am" value="<?php echo $m( 'vp_ausgetreten_am' ); ?>"></label>
				<?php $gruppen = (string) get_user_meta( $user->ID, 'vp_gruppen', true ); if ( $gruppen ) : ?>
					<p class="vp-muted vp-col-2"><?php echo esc_html( sprintf( __( 'Aus der alten Vereinsverwaltung: %s', 'vereinsplugin' ), $gruppen ) ); ?></p>
				<?php endif; ?>
				<label><?php esc_html_e( 'Beitrag (€)', 'vereinsplugin' ); ?><input type="number" step="0.01" min="0" name="vp_beitrag" value="<?php echo $m( 'vp_beitrag' ); ?>"></label>
				<label class="vp-col-2"><?php esc_html_e( 'Beitragsintervall', 'vereinsplugin' ); ?>
					<select name="vp_beitrag_intervall">
						<?php
						$iv = (string) get_user_meta( $user->ID, 'vp_beitrag_intervall', true );
						foreach ( vp_beitrag_intervalle() as $key => $label ) {
							printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $iv, $key, false ), esc_html( $label ) );
						}
						?>
					</select></label>
				<label class="vp-col-2"><?php esc_html_e( 'Interne Notiz (nur für den Vorstand sichtbar)', 'vereinsplugin' ); ?>
					<textarea name="vp_notiz" rows="3"><?php echo esc_textarea( get_user_meta( $user->ID, 'vp_notiz', true ) ); ?></textarea></label>
			</div>
		</fieldset>

		<fieldset>
			<legend><?php esc_html_e( 'SEPA-Lastschrift', 'vereinsplugin' ); ?></legend>
			<div class="vp-form-grid">
				<label class="vp-col-2"><?php esc_html_e( 'Kontoinhaber:in', 'vereinsplugin' ); ?><input type="text" name="vp_sepa_kontoinhaber" value="<?php echo $m( 'vp_sepa_kontoinhaber' ); ?>"></label>
				<label class="vp-col-2"><?php esc_html_e( 'IBAN', 'vereinsplugin' ); ?><input type="text" name="vp_sepa_iban" value="<?php echo $m( 'vp_sepa_iban' ); ?>"></label>
				<label><?php esc_html_e( 'Mandatsreferenz', 'vereinsplugin' ); ?><input type="text" name="vp_mandatsref" value="<?php echo $m( 'vp_mandatsref' ); ?>"></label>
				<label class="vp-check vp-col-2"><input type="checkbox" name="vp_sepa_mandat" value="1" <?php checked( (string) get_user_meta( $user->ID, 'vp_sepa_mandat', true ), '1' ); ?>>
					<span><?php esc_html_e( 'SEPA-Mandat liegt vor', 'vereinsplugin' ); ?></span></label>
			</div>
		</fieldset>

		<fieldset>
			<legend><?php esc_html_e( 'Konto für Erstattungen (Auslagen)', 'vereinsplugin' ); ?></legend>
			<div class="vp-form-grid">
				<label class="vp-col-2"><?php esc_html_e( 'Kontoinhaber:in', 'vereinsplugin' ); ?><input type="text" name="vp_erstattung_kontoinhaber" value="<?php echo $m( 'vp_erstattung_kontoinhaber' ); ?>"></label>
				<label class="vp-col-2"><?php esc_html_e( 'IBAN', 'vereinsplugin' ); ?><input type="text" name="vp_erstattung_iban" value="<?php echo $m( 'vp_erstattung_iban' ); ?>"></label>
				<p class="vp-muted vp-col-2"><?php esc_html_e( 'Leer = Rückzahlungen gehen auf das SEPA-Konto.', 'vereinsplugin' ); ?></p>
			</div>
		</fieldset>

		<p>
			<button class="vp-btn vp-btn-primary" name="vp_member_save" value="1"><?php esc_html_e( 'Speichern', 'vereinsplugin' ); ?></button>
			<button class="vp-btn" name="vp_member_pwmail" value="1"><?php esc_html_e( 'Passwort-Link per E-Mail senden', 'vereinsplugin' ); ?></button>
			<a class="vp-btn" href="<?php echo esc_url( vp_members_url() ); ?>"><?php esc_html_e( 'Abbrechen', 'vereinsplugin' ); ?></a>
		</p>
	</form>

	<?php if ( $aemter ) : ?>
		<div class="vp-card">
			<h3><?php esc_html_e( 'Ämter und Kreise', 'vereinsplugin' ); ?></h3>
			<ul class="vp-member-aemter">
				<?php foreach ( $aemter as $amt ) : ?>
					<li><?php
						if ( function_exists( 'vp_kreis_url' ) ) {
							printf( '<a href="%s">%s</a>', esc_url( vp_kreis_url( $amt['gremium_id'], 'struktur' ) ), esc_html( $amt['text'] ) );
						} else {
							echo esc_html( $amt['text'] );
						}
					?></li>
				<?php endforeach; ?>
			</ul>
			<p class="vp-muted"><?php esc_html_e( 'Ämter und Amtszeiten werden im jeweiligen Kreis unter „Mitglieder & Rollen“ gepflegt.', 'vereinsplugin' ); ?></p>
		</div>
	<?php endif; ?>
	<?php
	return ob_get_clean();
}

/**
 * Speichert das Formular. Gibt ['ok'|'error', Meldung] zurück.
 */
function vp_member_save( $user ) {
	$id   = (int) $user->ID;
	$data = array( 'ID' => $id );
	foreach ( array( 'first_name', 'last_name', 'display_name' ) as $k ) {
		$data[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) );
	}
	if ( '' === $data['display_name'] ) {
		$data['display_name'] = trim( $data['first_name'] . ' ' . $data['last_name'] ) ?: $user->user_login;
	}

	$email = sanitize_email( wp_unslash( $_POST['user_email'] ?? '' ) );
	// Leer ist erlaubt (importierte Ehemalige, Paare mit gemeinsamer Adresse) –
	// dann gibt es eben keinen Login. Eine falsch getippte Adresse nicht.
	if ( '' !== trim( (string) wp_unslash( $_POST['user_email'] ?? '' ) ) && ! is_email( $email ) ) {
		return array( 'error', __( 'Bitte eine gültige E-Mail-Adresse eintragen – ohne sie kann sich die Person nicht einloggen.', 'vereinsplugin' ) );
	}
	$owner = $email ? email_exists( $email ) : false;
	if ( $owner && (int) $owner !== $id ) {
		return array( 'error', __( 'Diese E-Mail-Adresse gehört bereits zu einem anderen Konto.', 'vereinsplugin' ) );
	}
	$data['user_email'] = $email;

	$res = wp_update_user( $data );
	if ( is_wp_error( $res ) ) {
		return array( 'error', $res->get_error_message() );
	}

	foreach ( vp_member_meta_keys() as $k ) {
		update_user_meta( $id, $k, sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) ) );
	}
	update_user_meta( $id, 'vp_sepa_mandat', empty( $_POST['vp_sepa_mandat'] ) ? 0 : 1 );
	update_user_meta( $id, 'vp_notiz', sanitize_textarea_field( wp_unslash( $_POST['vp_notiz'] ?? '' ) ) );

	$zusatz = '' === $email ? ' ' . __( 'Ohne E-Mail-Adresse kann sich die Person nicht einloggen.', 'vereinsplugin' ) : '';

	// Beitrag als Zahl mit Punkt ablegen – SEPA und Auswertungen rechnen damit.
	$beitrag = str_replace( ',', '.', (string) get_user_meta( $id, 'vp_beitrag', true ) );
	update_user_meta( $id, 'vp_beitrag', '' === trim( $beitrag ) ? '' : (float) $beitrag );

	// IBANs wie im SEPA-Modul normalisieren (das Mandat entsteht ohnehin erst dort).
	$zusatz .= vp_member_ibans_normalisieren( $id );

	if ( vp_member_can_edit_role( $user ) ) {
		$neu        = sanitize_key( wp_unslash( $_POST['vp_member_role'] ?? '' ) );
		$assignable = vp_member_assignable_roles();
		// Bewusst set_role statt add_role: pro Konto gibt es genau eine Rolle
		// (siehe core-roles.php – Vorstand/Admin erben die Mitglieds-Caps).
		if ( $neu && isset( $assignable[ $neu ] ) && array( $neu ) !== array_values( (array) $user->roles ) ) {
			$wpu = new WP_User( $id );
			$wpu->set_role( $neu );
			$zusatz .= ' ' . sprintf(
				/* translators: %s = role name */
				__( 'Neue Rolle: %s.', 'vereinsplugin' ),
				$assignable[ $neu ]
			);
		}
	}

	/**
	 * Mitgliedsdaten wurden im Mitgliederbereich geändert (z. B. für Sync-Module).
	 *
	 * @param int $id
	 */
	do_action( 'vp_member_updated', $id );

	return array( 'ok', __( 'Gespeichert.', 'vereinsplugin' ) . $zusatz );
}

/* ---- Vorstand: Auslagen prüfen ---- */

function vp_render_auslagen_pruefen_section() {
	if ( ! current_user_can( 'jb_approve_auslagen' ) || ! function_exists( 'jb_get_auslagen' ) ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Nicht verfügbar.', 'vereinsplugin' ) . '</div>';
	}

	$pending  = jb_get_auslagen( array( 'status' => 'ausstehend' ) );
	$approved = jb_get_auslagen( array( 'status' => 'genehmigt' ) );
	$budgets  = function_exists( 'jb_budgets_get_all' ) ? jb_budgets_get_all() : array();
	$budget_name = array();
	$kreis_namen = function_exists( 'vp_kreis_namen' ) ? vp_kreis_namen() : array();
	foreach ( $budgets as $b ) {
		$b = (object) $b;
		// Kreisbudgets kennzeichnen – die Kassenrolle des Kreises kann sie auch selbst entscheiden.
		$kreis = ( ! empty( $b->gremium_id ) && isset( $kreis_namen[ (int) $b->gremium_id ] ) ) ? $kreis_namen[ (int) $b->gremium_id ] . ' · ' : '';
		$budget_name[ (int) $b->id ] = $kreis . $b->zweck;
	}

	// GiroCode-Renderer auch laden, wenn erst per AJAX genehmigt wird.
	wp_enqueue_script( 'vp-girocode' );

	ob_start();
	echo '<h2>' . esc_html__( 'Auslagen prüfen', 'vereinsplugin' ) . '</h2>';

	$render_row = function ( $r ) use ( $budget_name ) {
		$r = (object) $r;
		echo '<div class="vp-card vp-auslage" data-id="' . (int) $r->id . '">';
		printf(
			'<div class="vp-auslage-head"><strong>%s €</strong> · %s · %s</div>',
			esc_html( number_format( (float) $r->betrag, 2, ',', '.' ) ),
			esc_html( $r->user_name ),
			esc_html( date_i18n( 'd.m.Y', strtotime( $r->ausgabe_datum ) ) )
		);
		echo '<div class="vp-muted">' . ( ! empty( $r->haendler ) ? esc_html( $r->haendler ) . ' · ' : '' ) . esc_html( $r->kategorie ) . ' — ' . esc_html( $r->beschreibung ) . '</div>';
		$bid = isset( $r->budget_id ) ? (int) $r->budget_id : 0;
		if ( $bid && isset( $budget_name[ $bid ] ) ) {
			echo '<div class="vp-muted">' . esc_html__( 'Budget:', 'vereinsplugin' ) . ' ' . esc_html( $budget_name[ $bid ] ) . '</div>';
		}
		if ( ! empty( $r->beleg_pfad ) && function_exists( 'jb_nc' ) ) {
			echo '<div><a class="vp-btn" target="_blank" rel="noopener" href="' . esc_url( jb_nc()->get_download_url( $r->beleg_pfad ) ) . '">' . esc_html__( 'Beleg ansehen', 'vereinsplugin' ) . '</a></div>';
		}
		if ( 'genehmigt' === $r->status && function_exists( 'vp_auslage_girocode_html' ) ) {
			echo vp_auslage_girocode_html( $r ); // phpcs:ignore WordPress.Security.EscapeOutput -- baut escaped HTML
		}
		echo '<div class="vp-auslage-actions">';
		if ( 'ausstehend' === $r->status ) {
			echo '<input type="text" class="vp-jb-notiz" placeholder="' . esc_attr__( 'Notiz / Ablehnungsgrund (optional)', 'vereinsplugin' ) . '">';
			echo '<button type="button" class="vp-btn vp-btn-primary vp-jb-decide" data-do="approve">' . esc_html__( 'Genehmigen', 'vereinsplugin' ) . '</button> ';
			echo '<button type="button" class="vp-btn vp-btn-danger vp-jb-decide" data-do="reject">' . esc_html__( 'Ablehnen', 'vereinsplugin' ) . '</button>';
		} elseif ( 'genehmigt' === $r->status ) {
			echo '<button type="button" class="vp-btn vp-jb-paid">' . esc_html__( 'Als ausgezahlt markieren', 'vereinsplugin' ) . '</button>';
		}
		echo '</div><div class="vp-auslage-msg" role="status"></div></div>';
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

	// Nutzt die bestehenden AJAX-Endpunkte des Buchhaltungs-Moduls – bewusst
	// in reinem JS (kein jQuery, kein window.prompt): das inline-Script läuft,
	// bevor jQuery im Footer geladen ist, und prompt() ist in installierten
	// PWAs oft gesperrt. Genau daran scheiterte bisher das Ablehnen.
	$ajax  = admin_url( 'admin-ajax.php' );
	$nonce = wp_create_nonce( 'jb_nonce' );
	$t_ok   = esc_js( __( 'Erledigt.', 'vereinsplugin' ) );
	$t_err  = esc_js( __( 'Fehler.', 'vereinsplugin' ) );
	$t_wait = esc_js( __( 'Bitte warten …', 'vereinsplugin' ) );
	?>
	<script>
	(function(){
		var AJAX = <?php echo wp_json_encode( $ajax ); ?>;
		var NONCE = <?php echo wp_json_encode( $nonce ); ?>;
		function decide(card, act){
			var id = card.getAttribute('data-id');
			var notizEl = card.querySelector('.vp-jb-notiz');
			var msg = card.querySelector('.vp-auslage-msg');
			var btns = card.querySelectorAll('button');
			btns.forEach(function(b){ b.disabled = true; });
			msg.textContent = '<?php echo $t_wait; ?>';
			var body = new URLSearchParams();
			body.set('nonce', NONCE);
			if (act === 'paid') {
				body.set('action', 'jb_mark_paid');
			} else {
				body.set('action', 'jb_decide_auslage');
				body.set('action_type', act); // 'approve' | 'reject'
				body.set('notiz', notizEl ? notizEl.value : '');
			}
			body.set('id', id);
			fetch(AJAX, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}, body: body.toString()})
				.then(function(r){ return r.json(); })
				.then(function(res){
					var ok = res && (res.success === true);
					if (ok) {
						msg.textContent = '<?php echo $t_ok; ?>';
						var giro = res.data && res.data.girocode;
						if (act === 'approve' && giro) {
							// Genehmigt: GiroCode zum Überweisen direkt anzeigen, Karte bleibt aktiv.
							var box = document.createElement('div');
							box.innerHTML = giro;
							card.querySelector('.vp-auslage-actions').replaceWith(box);
							if (window.vpGirocode) { window.vpGirocode.renderAll(box); }
							msg.textContent = '<?php echo esc_js( __( 'Genehmigt – jetzt überweisen und danach unter „Genehmigt“ als ausgezahlt markieren.', 'vereinsplugin' ) ); ?>';
						} else {
							card.style.opacity = .45;
						}
					} else {
						msg.textContent = (res && res.data) ? res.data : '<?php echo $t_err; ?>';
						btns.forEach(function(b){ b.disabled = false; });
					}
				})
				.catch(function(){
					msg.textContent = '<?php echo $t_err; ?>';
					btns.forEach(function(b){ b.disabled = false; });
				});
		}
		function init(){
			document.querySelectorAll('.vp-app .vp-auslage').forEach(function(card){
				card.querySelectorAll('.vp-jb-decide').forEach(function(b){
					b.addEventListener('click', function(){ decide(card, b.getAttribute('data-do')); });
				});
				var paid = card.querySelector('.vp-jb-paid');
				if (paid) { paid.addEventListener('click', function(){ decide(card, 'paid'); }); }
			});
		}
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', init);
		} else {
			init();
		}
	})();
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
	// Alle öffentlichen Kern-Shortcodes, die das vp-app-CSS/JS brauchen. Bei
	// einem neuen öffentlichen Shortcode hier ergänzen – sonst rendert er
	// ungestylt (genau das ist [verein_formular] anfangs passiert).
	$public_tags = apply_filters( 'vp_public_shortcodes_needing_assets', array(
		'verein_mitgliederbereich',
		'verein_login',
		'verein_mitgliedsantrag',
		'verein_formular',
	) );
	$is_area = has_shortcode( $post->post_content, 'verein_mitgliederbereich' );
	$found   = false;
	foreach ( $public_tags as $tag ) {
		if ( has_shortcode( $post->post_content, $tag ) ) {
			$found = true;
			break;
		}
	}
	if ( ! $found ) {
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

/**
 * Seitenleisten-Eintrag mit Unterpunkten. `children` liefert
 * [ [ key, label ('' = ohne Überschrift), items => [ [label, badge, url, aktiv, badge_parent] ] ] ].
 * Gruppen mit Überschrift werden als eingeklappte Untergruppe gezeigt.
 */
function vp_render_nav_children( $key, $s, $base_url, $section_active ) {
	$gruppen = (array) call_user_func( $s['children'], $base_url, $section_active );

	$badge = '';
	foreach ( $gruppen as $gr ) {
		foreach ( $gr['items'] as $it ) {
			if ( ! empty( $it['badge_parent'] ) && '' !== (string) $it['badge'] ) {
				$badge = $it['badge'];
			}
		}
	}

	printf(
		'<details class="vp-nav-fold vp-nav-parent%1$s" data-fold="%2$s"%3$s><summary class="vp-nav-item"><span class="vp-nav-label">%4$s</span>%5$s</summary><div class="vp-nav-children">',
		$section_active ? ' has-active' : '',
		esc_attr( 'bereich-' . $key ),
		$section_active ? ' open data-has-active="1"' : '',
		esc_html( $s['label'] ),
		$badge ? '<span class="vp-nav-badge">' . esc_html( $badge ) . '</span>' : ''
	);
	foreach ( $gruppen as $gr ) {
		$sub_active = (bool) array_filter( $gr['items'], function ( $it ) { return ! empty( $it['aktiv'] ); } );
		if ( '' !== $gr['label'] ) {
			printf(
				'<details class="vp-nav-fold vp-nav-subfold" data-fold="%s"%s><summary class="vp-nav-sublabel">%s</summary><div class="vp-nav-children">',
				esc_attr( 'bereich-' . $key . '-' . $gr['key'] ),
				$sub_active ? ' open data-has-active="1"' : '',
				esc_html( $gr['label'] )
			);
		}
		foreach ( $gr['items'] as $it ) {
			printf(
				'<a class="vp-nav-item vp-nav-child%s" href="%s" data-vp-tab="%s">%s%s</a>',
				! empty( $it['aktiv'] ) ? ' is-active' : '',
				esc_url( $it['url'] ),
				esc_attr( $key ),
				esc_html( $it['label'] ),
				'' !== (string) $it['badge'] ? '<span class="vp-nav-badge">' . esc_html( $it['badge'] ) . '</span>' : ''
			);
		}
		if ( '' !== $gr['label'] ) {
			echo '</div></details>';
		}
	}
	echo '</div></details>';
}
