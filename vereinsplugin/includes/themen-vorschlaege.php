<?php
/**
 * Kern: „Als TOP vorschlagen“ – aus jedem Arbeitsbereich in den Themenspeicher.
 *
 * In Kasse/Budgets, Kreiskassen, Wunschliste, Kreisen, Schichtplänen,
 * Aufgaben, Kalender, Projekten und Veranstaltungen steht oben ein Knopf
 * „📌 Als TOP vorschlagen“. Er legt ein Thema im Themenspeicher an – mit
 * vorausgefülltem Titel und Kreis aus dem, was gerade offen ist, einem Link
 * zurück zur Quelle und auf Wunsch dem aktuellen Stand als Anlage
 * (Kassenbericht, Kreiskasse & Budgets, Wunschliste).
 *
 * Wird das Thema in einer Sitzung als TOP übernommen, erscheinen Quelle und
 * Anlage direkt beim TOP (Live- und Detailansicht).
 *
 * Andere Stellen können den Knopf vorbefüllt verlinken:
 *   ?vp_thema=1&vp_thema_titel=…&vp_thema_text=…&vp_thema_gremium=<id>
 */

defined( 'ABSPATH' ) || exit;

function vp_thema_anlagen_table() {
	global $wpdb;
	return $wpdb->prefix . 'vp_themen_anlagen';
}

add_action( 'plugins_loaded', function () {
	if ( get_option( 'vp_themen_db' ) === '1' ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( 'CREATE TABLE ' . vp_thema_anlagen_table() . " (
		id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		thema_id    BIGINT UNSIGNED NOT NULL,
		bereich     VARCHAR(60) NOT NULL DEFAULT '',
		titel       VARCHAR(255) NOT NULL DEFAULT '',
		inhalt      LONGTEXT,
		quelle_url  TEXT,
		erstellt_am DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY thema_id (thema_id)
	) " . $wpdb->get_charset_collate() . ';' );
	update_option( 'vp_themen_db', '1' );
}, 20 );

function vp_thema_verfuegbar() {
	global $wpdb;
	static $ok = null;
	if ( null === $ok ) {
		$t  = $wpdb->prefix . 'pp_themen';
		$ok = function_exists( 'pp_get_gremien' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
	}
	return $ok;
}

function vp_thema_darf() {
	return is_user_logged_in() && current_user_can( 'read' )
		&& ! in_array( 'vp_antrag_offen', (array) wp_get_current_user()->roles, true );
}

/** Link, der den Vorschlags-Knopf im aktuellen Bereich vorbefüllt öffnet. */
function vp_thema_link( $titel, $text = '', $gremium_id = 0, $basis = '' ) {
	$basis = $basis ?: home_url( add_query_arg( array() ) );
	$url   = add_query_arg( array_filter( array(
		'vp_thema'         => 1,
		'vp_thema_titel'   => $titel,
		'vp_thema_text'    => $text,
		'vp_thema_gremium' => $gremium_id ?: null,
		'vp_thema_ok'      => false,
	) ), remove_query_arg( 'vp_thema_ok', $basis ) );
	return $url . '#vp-thema';
}

/* =========================================================================
 * Kontext: was ist gerade offen?
 * ====================================================================== */

/** @return array|null ['bereich','titel','gremium_id','bericht','text'] */
function vp_thema_kontext( $active ) {
	global $wpdb;
	$get  = function ( $k ) { return isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : ''; };
	$view = sanitize_key( $get( 'pp_view' ) );
	$id   = (int) $get( 'id' );
	$k    = null;

	switch ( $active ) {
		case 'kassenbericht':
		case 'buchhaltung':
		case 'budgets':
			$k = array( 'bereich' => __( 'Kasse', 'vereinsplugin' ), 'titel' => __( 'Kassenbericht', 'vereinsplugin' ), 'bericht' => 'kasse' );
			break;

		case 'wuensche':
		case 'abstimmung':
			$k = array( 'bereich' => __( 'Wunschliste', 'vereinsplugin' ), 'titel' => __( 'Wunschliste: ', 'vereinsplugin' ), 'bericht' => 'wunschliste' );
			break;

		case 'kreise':
			$g = ( 'kreis' === $view && $id && function_exists( 'pp_get_gremium' ) ) ? pp_get_gremium( $id ) : null;
			if ( $g ) {
				$tab = sanitize_key( $get( 'k_tab' ) );
				if ( 'kasse' === $tab ) {
					$k = array( 'bereich' => __( 'Kasse', 'vereinsplugin' ), 'titel' => sprintf( /* translators: %s = Kreis */ __( 'Kreiskasse & Budgets: %s', 'vereinsplugin' ), $g->name ), 'bericht' => 'kreiskasse_' . $g->id );
				} elseif ( 'wuensche' === $tab ) {
					$k = array( 'bereich' => __( 'Wunschliste', 'vereinsplugin' ), 'titel' => sprintf( /* translators: %s = Kreis */ __( 'Wunschliste %s: ', 'vereinsplugin' ), $g->name ), 'bericht' => 'wunschliste_' . $g->id );
				} else {
					$k = array( 'bereich' => __( 'Kreise', 'vereinsplugin' ), 'titel' => sprintf( /* translators: %s = Kreis */ __( 'Kreis %s: ', 'vereinsplugin' ), $g->name ) );
				}
				$k['gremium_id'] = (int) $g->id;
			} else {
				$k = array( 'bereich' => __( 'Kreise', 'vereinsplugin' ), 'titel' => __( 'Neuer Kreis / Unterkreis: ', 'vereinsplugin' ), 'gremium_id' => (int) $get( 'parent' ) );
			}
			break;

		case 'schichtplaene':
			$slug = sanitize_title( $get( 'event' ) );
			$ev   = ( $slug && function_exists( 'wl_get_event_by_slug' ) ) ? wl_get_event_by_slug( $slug ) : null;
			$k    = array( 'bereich' => __( 'Schichtpläne', 'vereinsplugin' ), 'titel' => $ev ? sprintf( /* translators: %s = Veranstaltung */ __( 'Schichtplan %s: ', 'vereinsplugin' ), $ev->titel ) : __( 'Schichtpläne: ', 'vereinsplugin' ) );
			break;

		case 'aufgaben':
			$k = array( 'bereich' => __( 'Aufgaben', 'vereinsplugin' ), 'titel' => __( 'Aufgaben: ', 'vereinsplugin' ) );
			break;

		case 'kalender':
			$k = array( 'bereich' => __( 'Kalender', 'vereinsplugin' ), 'titel' => __( 'Termin: ', 'vereinsplugin' ) );
			break;

		case 'projekte':
			$k = array( 'bereich' => __( 'Projekte', 'vereinsplugin' ), 'titel' => __( 'Projekt: ', 'vereinsplugin' ) );
			if ( 'projekt' === $view && $id ) {
				$pr = $wpdb->get_row( $wpdb->prepare( "SELECT titel, gremium_id FROM {$wpdb->prefix}vp_projekte WHERE id = %d", $id ) );
				if ( $pr ) {
					$k['titel']      = sprintf( /* translators: %s = Projekt */ __( 'Projekt %s: ', 'vereinsplugin' ), $pr->titel );
					$k['gremium_id'] = (int) $pr->gremium_id;
				}
			}
			break;

		case 'veranstaltungen':
			$k = array( 'bereich' => __( 'Veranstaltungen', 'vereinsplugin' ), 'titel' => __( 'Veranstaltung: ', 'vereinsplugin' ) );
			break;
	}
	$k = apply_filters( 'vp_thema_kontext', $k, $active );
	if ( ! $k ) {
		return null;
	}
	return array_merge( array( 'gremium_id' => 0, 'bericht' => '', 'text' => '' ), $k );
}

/* =========================================================================
 * Knopf + Formular über dem Bereich
 * ====================================================================== */

add_action( 'vp_member_area_vor_inhalt', 'vp_thema_knopf', 20 );
function vp_thema_knopf( $active ) {
	if ( ! vp_thema_verfuegbar() || ! vp_thema_darf() ) {
		return;
	}
	$k = vp_thema_kontext( $active );
	if ( ! $k ) {
		return;
	}
	$get     = function ( $x ) { return isset( $_GET[ $x ] ) ? sanitize_text_field( wp_unslash( $_GET[ $x ] ) ) : ''; };
	$offen   = ! empty( $_GET['vp_thema'] );
	$titel   = $get( 'vp_thema_titel' ) ?: $k['titel'];
	$text    = isset( $_GET['vp_thema_text'] ) ? sanitize_textarea_field( wp_unslash( $_GET['vp_thema_text'] ) ) : $k['text'];
	$gremium = (int) ( $get( 'vp_thema_gremium' ) ?: $k['gremium_id'] );
	$quelle  = remove_query_arg( array( 'vp_thema', 'vp_thema_titel', 'vp_thema_text', 'vp_thema_gremium', 'vp_thema_ok', 'pp_saved', 'pp_error' ), home_url( add_query_arg( array() ) ) );
	$bericht = $k['bericht'] && function_exists( 'vp_live_bericht' ) ? $k['bericht'] : '';

	if ( ! empty( $_GET['vp_thema_ok'] ) ) {
		$ts = function_exists( 'vp_visible_sections' ) && isset( vp_visible_sections()['protokolle'] )
			? ' <a href="' . esc_url( add_query_arg( array( 'vp_tab' => 'protokolle', 'pp_view' => 'themen' ), get_permalink() ?: home_url( '/' ) ) ) . '">' . esc_html__( 'Zum Themenspeicher', 'vereinsplugin' ) . '</a>'
			: '';
		echo '<div class="vp-note">' . esc_html__( 'Thema im Themenspeicher gespeichert – es kann bei der nächsten Sitzung als TOP übernommen werden.', 'vereinsplugin' ) . $ts . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	echo '<details class="vp-thema-vorschlag" id="vp-thema"' . ( $offen ? ' open' : '' ) . '><summary class="vp-btn">' . esc_html__( '📌 Als TOP vorschlagen', 'vereinsplugin' ) . '</summary>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="vp-card vp-form">';
	wp_nonce_field( 'vp_thema_vorschlagen' );
	echo '<input type="hidden" name="action" value="vp_thema_vorschlagen"><input type="hidden" name="quelle" value="' . esc_url( $quelle ) . '"><input type="hidden" name="bereich" value="' . esc_attr( $k['bereich'] ) . '"><input type="hidden" name="bericht" value="' . esc_attr( $bericht ) . '">';
	echo '<p class="vp-muted" style="margin-top:0">' . esc_html__( 'Landet im Themenspeicher des gewählten Kreises und kann bei der Sitzungsplanung als TOP übernommen werden. Ein Link hierher wird mitgespeichert.', 'vereinsplugin' ) . '</p>';
	echo '<div class="vp-form-grid">';
	printf( '<label class="vp-col-2">%s<input type="text" name="titel" required value="%s"></label>', esc_html__( 'Thema / TOP', 'vereinsplugin' ), esc_attr( $titel ) );
	printf( '<label class="vp-col-2">%s<textarea name="beschreibung" rows="2" placeholder="%s">%s</textarea></label>', esc_html__( 'Worum geht es?', 'vereinsplugin' ), esc_attr__( 'Was soll entschieden oder besprochen werden?', 'vereinsplugin' ), esc_textarea( $text ) );
	echo '<label>' . esc_html__( 'Kreis', 'vereinsplugin' ) . '<select name="gremium_id"><option value="">' . esc_html__( '— kreisübergreifend —', 'vereinsplugin' ) . '</option>';
	foreach ( (array) pp_get_gremien() as $g ) {
		printf( '<option value="%d"%s>%s</option>', (int) $g->id, selected( $gremium, (int) $g->id, false ), esc_html( $g->name ) );
	}
	echo '</select></label>';
	if ( $bericht ) {
		printf( '<label class="vp-check" style="align-self:end"><input type="checkbox" name="mit_bericht" value="1" checked> %s</label>', esc_html__( 'Aktuellen Stand als Anlage anhängen', 'vereinsplugin' ) );
	}
	echo '</div><p><button class="vp-btn vp-btn-primary">' . esc_html__( 'In den Themenspeicher', 'vereinsplugin' ) . '</button></p></form></details>';
}

add_action( 'admin_post_vp_thema_vorschlagen', function () {
	if ( ! vp_thema_darf() || ! vp_thema_verfuegbar() ) {
		wp_die( esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) );
	}
	check_admin_referer( 'vp_thema_vorschlagen' );
	global $wpdb;
	$quelle = esc_url_raw( wp_unslash( $_POST['quelle'] ?? '' ) );
	$back   = $quelle ?: home_url( '/' );
	$titel  = sanitize_text_field( wp_unslash( $_POST['titel'] ?? '' ) );
	if ( '' === trim( $titel, " :" ) ) {
		wp_safe_redirect( add_query_arg( array( 'vp_thema' => 1, 'pp_error' => rawurlencode( __( 'Bitte einen Titel angeben.', 'vereinsplugin' ) ) ), $back ) . '#vp-thema' );
		exit;
	}
	$gid = (int) ( $_POST['gremium_id'] ?? 0 );
	$wpdb->insert( $wpdb->prefix . 'pp_themen', array(
		'titel'        => rtrim( $titel, ' :' ),
		'beschreibung' => sanitize_textarea_field( wp_unslash( $_POST['beschreibung'] ?? '' ) ),
		'gremium_id'   => $gid ?: null,
		'erstellt_von' => get_current_user_id(),
	) );
	$thema_id = (int) $wpdb->insert_id;

	if ( $thema_id ) {
		$inhalt = '';
		$btitel = '';
		$typ    = sanitize_key( $_POST['bericht'] ?? '' );
		if ( $typ && ! empty( $_POST['mit_bericht'] ) && function_exists( 'vp_live_bericht' ) ) {
			$g = 0;
			if ( preg_match( '/^(kreiskasse|wunschliste)_(\d+)$/', $typ, $m ) ) {
				$typ = $m[1];
				$g   = (int) $m[2];
			}
			$b = vp_live_bericht( $typ, $g );
			if ( ! is_wp_error( $b ) ) {
				$btitel = $b[0] . ' (' . sprintf( /* translators: %s = Zeit */ __( 'Stand %s', 'vereinsplugin' ), current_time( 'd.m.Y H:i' ) ) . ')';
				$inhalt = $b[1];
			}
		}
		$wpdb->insert( vp_thema_anlagen_table(), array(
			'thema_id'    => $thema_id,
			'bereich'     => sanitize_text_field( wp_unslash( $_POST['bereich'] ?? '' ) ),
			'titel'       => $btitel,
			'inhalt'      => $inhalt,
			'quelle_url'  => $quelle,
			'erstellt_am' => current_time( 'mysql' ),
		) );
	}
	wp_safe_redirect( add_query_arg( 'vp_thema_ok', 1, $back ) );
	exit;
} );

/* =========================================================================
 * Anzeige im Themenspeicher und beim TOP
 * ====================================================================== */

function vp_thema_anlagen( $thema_id ) {
	global $wpdb;
	static $cache = array();
	if ( ! isset( $cache[ $thema_id ] ) ) {
		$t                    = vp_thema_anlagen_table();
		$cache[ $thema_id ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t
			? (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE thema_id = %d ORDER BY id", $thema_id ) )
			: array();
	}
	return $cache[ $thema_id ];
}

function vp_thema_anlagen_html( $thema_id, $offen = false ) {
	$h = '';
	foreach ( vp_thema_anlagen( $thema_id ) as $a ) {
		$h .= '<div class="vp-thema-anlage">';
		if ( $a->bereich ) {
			$h .= '<span class="vp-live-bereich">' . esc_html( $a->bereich ) . '</span> ';
		}
		if ( $a->quelle_url ) {
			$h .= '<a href="' . esc_url( $a->quelle_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Quelle öffnen ↗', 'vereinsplugin' ) . '</a>';
		}
		if ( $a->inhalt ) {
			$h .= '<details class="vp-live-bericht"' . ( $offen ? ' open' : '' ) . '><summary><strong>📎 ' . esc_html( $a->titel ) . '</strong></summary>' . wp_kses_post( $a->inhalt ) . '</details>';
		}
		$h .= '</div>';
	}
	return $h;
}

add_action( 'pp_thema_extra', function ( $th ) {
	echo vp_thema_anlagen_html( (int) $th->id ); // phpcs:ignore WordPress.Security.EscapeOutput
} );

add_action( 'pp_top_extra', function ( $t, $p ) {
	if ( ! empty( $t->thema_id ) ) {
		echo vp_thema_anlagen_html( (int) $t->thema_id, true ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}, 10, 2 );
