<?php
/**
 * Kern: Live-Sitzung – während der Protokollierung überall weiterarbeiten.
 *
 * Wer eine Sitzung live protokolliert, bleibt im „Live-Kontext“ (User-Meta
 * `vp_live_protokoll`), bis er ihn verlässt oder das Protokoll abgeschlossen
 * wird. Solange gilt:
 *
 *  - Oben in JEDEM Bereich des Mitgliederbereichs steht eine Live-Leiste
 *    (zurück zum Protokoll, Online-Teilnahme über Nextcloud Talk, verlassen).
 *  - Änderungen in Kasse/Budgets, Wunschliste, Kreisen, Schichtplänen,
 *    Aufgaben, Kalender, Projekten und Veranstaltungen werden automatisch im
 *    Protokoll dokumentiert („Während der Sitzung erledigt“).
 *  - In der Live-Seitenleiste: „Weiterarbeiten an …“ (öffnet den Bereich in
 *    einem neuen Tab, das Protokoll bleibt offen), „Bericht einfügen“
 *    (Kassenbericht, Kreiskasse & Budgets, Wunschliste als Stand zum Zeitpunkt
 *    der Sitzung) und Online-Teilnahme.
 *
 * Mitschnitt: ein `query`-Filter merkt sich schreibende Abfragen auf bekannten
 * Tabellen (siehe vp_live_tabellen()) und schreibt am Ende des Requests je
 * Datensatz einen lesbaren Eintrag. Das Protokoll selbst (TOPs, Einwände …)
 * wird bewusst nicht mitgeschnitten – das IST ja das Protokoll.
 */

defined( 'ABSPATH' ) || exit;

define( 'VP_LIVE_DB_VERSION', '1' );

function vp_live_table() {
	global $wpdb;
	return $wpdb->prefix . 'vp_live_eintraege';
}

add_action( 'plugins_loaded', 'vp_live_maybe_upgrade', 20 );
function vp_live_maybe_upgrade() {
	if ( get_option( 'vp_live_db' ) === VP_LIVE_DB_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( 'CREATE TABLE ' . vp_live_table() . " (
		id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		protokoll_id  BIGINT UNSIGNED NOT NULL,
		top_id        BIGINT UNSIGNED DEFAULT NULL,
		user_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
		zeit          DATETIME NOT NULL,
		art           VARCHAR(20) NOT NULL DEFAULT 'aenderung',
		bereich       VARCHAR(60) NOT NULL DEFAULT '',
		titel         VARCHAR(255) NOT NULL DEFAULT '',
		inhalt        LONGTEXT,
		PRIMARY KEY  (id),
		KEY protokoll_id (protokoll_id)
	) " . $wpdb->get_charset_collate() . ';' );
	update_option( 'vp_live_db', VP_LIVE_DB_VERSION );
}

/* =========================================================================
 * Live-Kontext
 * ====================================================================== */

/** Protokoll, das die Person gerade live protokolliert (oder null). */
function vp_live_kontext( $user_id = 0 ) {
	static $cache = array();
	$user_id = $user_id ?: get_current_user_id();
	if ( ! $user_id ) {
		return null;
	}
	if ( array_key_exists( $user_id, $cache ) ) {
		return $cache[ $user_id ];
	}
	$cache[ $user_id ] = null;
	$pid = (int) get_user_meta( $user_id, 'vp_live_protokoll', true );
	if ( ! $pid || ! function_exists( 'pp_get_protokoll' ) ) {
		return null;
	}
	$p = pp_get_protokoll( $pid );
	if ( ! $p || 'abgeschlossen' === $p->status ) {
		delete_user_meta( $user_id, 'vp_live_protokoll' );
		return null;
	}
	$cache[ $user_id ] = $p;
	return $p;
}

function vp_live_setzen( $protokoll_id ) {
	if ( is_user_logged_in() && $protokoll_id ) {
		update_user_meta( get_current_user_id(), 'vp_live_protokoll', (int) $protokoll_id );
	}
}

// „Live protokollieren“ gestartet → Kontext setzen.
add_action( 'admin_post_pp_front_start_live', function () {
	if ( isset( $_POST['id'] ) && current_user_can( 'pp_manage' ) ) {
		vp_live_setzen( (int) $_POST['id'] );
	}
}, 5 );

// Protokoll abgeschlossen → Kontext bei allen Beteiligten beenden.
add_action( 'admin_post_pp_front_abschliessen', function () {
	if ( isset( $_POST['id'] ) && current_user_can( 'pp_manage' ) ) {
		delete_metadata( 'user', 0, 'vp_live_protokoll', (string) (int) $_POST['id'], true );
	}
}, 5 );

add_action( 'admin_post_vp_live_verlassen', function () {
	check_admin_referer( 'vp_live_verlassen' );
	delete_user_meta( get_current_user_id(), 'vp_live_protokoll' );
	wp_safe_redirect( esc_url_raw( wp_unslash( $_POST['zurueck'] ?? '' ) ) ?: home_url( '/' ) );
	exit;
} );

/** URL eines Bereichs im Mitgliederbereich. */
function vp_live_area_url( $args ) {
	$base = get_option( 'vp_member_area_url' ) ?: ( get_permalink() ?: home_url( '/' ) );
	return add_query_arg( $args, $base );
}

function vp_live_protokoll_url( $p ) {
	return vp_live_area_url( array( 'vp_tab' => 'protokolle', 'pp_view' => 'live', 'id' => (int) $p->id ) );
}

/* =========================================================================
 * Live-Leiste über jedem Bereich
 * ====================================================================== */

add_action( 'vp_member_area_vor_inhalt', 'vp_live_leiste' );
function vp_live_leiste( $active ) {
	$p = vp_live_kontext();
	if ( ! $p ) {
		return;
	}
	// In der Live-Ansicht selbst braucht es die Leiste nicht.
	if ( isset( $_GET['pp_view'] ) && 'live' === $_GET['pp_view'] && (int) ( $_GET['id'] ?? 0 ) === (int) $p->id ) {
		return;
	}
	global $wpdb;
	$n       = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . vp_live_table() . ' WHERE protokoll_id = %d', $p->id ) );
	$gremium = function_exists( 'pp_get_gremium' ) ? pp_get_gremium( $p->gremium_id ) : null;
	$talk    = function_exists( 'vp_talk_sitzung_url' ) && vp_talk_ready() ? vp_talk_sitzung_url( $p->id, $p->gremium_id ) : '';
	$seit    = $p->beginn_zeit ? mysql2date( 'H:i', $p->beginn_zeit ) : '';

	echo '<div class="vp-live-leiste" role="status">';
	echo '<span class="vp-live-punkt" aria-hidden="true"></span>';
	echo '<div class="vp-live-text"><strong>' . esc_html__( 'Live-Sitzung', 'vereinsplugin' ) . ':</strong> ' . esc_html( $p->titel ) . ( $gremium ? ' · ' . esc_html( $gremium->name ) : '' ) . ( $seit ? ' · ' . esc_html( sprintf( /* translators: %s = Uhrzeit */ __( 'seit %s', 'vereinsplugin' ), $seit ) ) : '' );
	echo '<br><small>' . esc_html__( 'Was du hier änderst, wird im Protokoll dokumentiert.', 'vereinsplugin' ) . ' ' . esc_html( sprintf( /* translators: %d = count */ _n( '%d Eintrag bisher.', '%d Einträge bisher.', $n, 'vereinsplugin' ), $n ) ) . '</small></div>';
	echo '<div class="vp-live-knoepfe">';
	printf( '<a class="vp-btn vp-btn-primary" href="%s">%s</a>', esc_url( vp_live_protokoll_url( $p ) ), esc_html__( 'Zum Protokoll', 'vereinsplugin' ) );
	if ( $talk ) {
		printf( '<a class="vp-btn" href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $talk ), esc_html__( '🎥 Online', 'vereinsplugin' ) );
	}
	printf(
		'<form method="post" action="%s" style="display:inline">%s<input type="hidden" name="action" value="vp_live_verlassen"><input type="hidden" name="zurueck" value="%s"><button class="vp-btn" title="%s">%s</button></form>',
		esc_url( admin_url( 'admin-post.php' ) ),
		wp_nonce_field( 'vp_live_verlassen', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput
		esc_url( home_url( add_query_arg( array() ) ) ),
		esc_attr__( 'Ab jetzt nichts mehr im Protokoll dokumentieren', 'vereinsplugin' ),
		esc_html__( 'Live verlassen', 'vereinsplugin' )
	);
	echo '</div></div>';
}

/* =========================================================================
 * Mitschnitt der Änderungen
 * ====================================================================== */

/** Tabelle (ohne Präfix) => [Bereich, Bezeichnung]. */
function vp_live_tabellen() {
	return apply_filters( 'vp_live_tabellen', array(
		'jb_buchungen'                => array( __( 'Kasse', 'vereinsplugin' ), __( 'Buchung', 'vereinsplugin' ) ),
		'jb_budgets'                  => array( __( 'Kasse', 'vereinsplugin' ), __( 'Budget', 'vereinsplugin' ) ),
		'jb_ruecklagen'               => array( __( 'Kasse', 'vereinsplugin' ), __( 'Rücklage', 'vereinsplugin' ) ),
		'jb_auslagen'                 => array( __( 'Kasse', 'vereinsplugin' ), __( 'Auslage', 'vereinsplugin' ) ),
		'vp_rechnungen'               => array( __( 'Kasse', 'vereinsplugin' ), __( 'Rechnung', 'vereinsplugin' ) ),
		'vp_spenden'                  => array( __( 'Kasse', 'vereinsplugin' ), __( 'Spende', 'vereinsplugin' ) ),
		'wunschliste'                 => array( __( 'Wunschliste', 'vereinsplugin' ), __( 'Wunsch', 'vereinsplugin' ) ),
		'pp_gremien'                  => array( __( 'Kreise', 'vereinsplugin' ), __( 'Kreis', 'vereinsplugin' ) ),
		'pp_kreis_mitglieder'         => array( __( 'Kreise', 'vereinsplugin' ), __( 'Kreismitgliedschaft', 'vereinsplugin' ) ),
		'pp_rollen'                   => array( __( 'Kreise', 'vereinsplugin' ), __( 'Rollen-Besetzung', 'vereinsplugin' ) ),
		'pp_rollenvorlagen'           => array( __( 'Kreise', 'vereinsplugin' ), __( 'Rolle', 'vereinsplugin' ) ),
		'pp_rollenvorlagen_aufgaben'  => array( __( 'Aufgaben', 'vereinsplugin' ), __( 'Rollenaufgabe', 'vereinsplugin' ) ),
		'pp_aufgaben'                 => array( __( 'Aufgaben', 'vereinsplugin' ), __( 'Aufgabe', 'vereinsplugin' ) ),
		'pp_aufgaben_sets'            => array( __( 'Aufgaben', 'vereinsplugin' ), __( 'Aufgaben-Set', 'vereinsplugin' ) ),
		'pp_termine'                  => array( __( 'Kalender', 'vereinsplugin' ), __( 'Termin', 'vereinsplugin' ) ),
		'pp_themen'                   => array( __( 'Themenspeicher', 'vereinsplugin' ), __( 'Thema', 'vereinsplugin' ) ),
		'wl_shift_events'             => array( __( 'Schichtpläne', 'vereinsplugin' ), __( 'Schichtplan', 'vereinsplugin' ) ),
		'wl_shift_stationen'          => array( __( 'Schichtpläne', 'vereinsplugin' ), __( 'Station', 'vereinsplugin' ) ),
		'wl_shift_schichten'          => array( __( 'Schichtpläne', 'vereinsplugin' ), __( 'Schicht', 'vereinsplugin' ) ),
		'wl_shift_eintragungen'       => array( __( 'Schichtpläne', 'vereinsplugin' ), __( 'Schicht-Eintragung', 'vereinsplugin' ) ),
		'vp_projekte'                 => array( __( 'Projekte', 'vereinsplugin' ), __( 'Projekt', 'vereinsplugin' ) ),
		'vp_projekt_punkte'           => array( __( 'Projekte', 'vereinsplugin' ), __( 'Projekt-Punkt', 'vereinsplugin' ) ),
		'vp_projekt_helfende'         => array( __( 'Projekte', 'vereinsplugin' ), __( 'Helfende', 'vereinsplugin' ) ),
		'pp_dokumente'                => array( __( 'Dokumente', 'vereinsplugin' ), __( 'Dokument', 'vereinsplugin' ) ),
	) );
}

/** Optionen, deren Änderung dokumentiert wird: option_name => [Bereich, Text]. */
function vp_live_optionen() {
	return apply_filters( 'vp_live_optionen', array(
		'vp_kal_eigene'          => array( __( 'Kalender', 'vereinsplugin' ), __( 'Weitere Termine geändert', 'vereinsplugin' ) ),
		'vp_kal_oeffnungszeiten' => array( __( 'Kalender', 'vereinsplugin' ), __( 'Öffnungszeiten geändert', 'vereinsplugin' ) ),
		'vp_kal_schliesstage'    => array( __( 'Kalender', 'vereinsplugin' ), __( 'Schließtage geändert', 'vereinsplugin' ) ),
		'vp_kal_nc_kalender'     => array( __( 'Kalender', 'vereinsplugin' ), __( 'Nextcloud-Kalender geändert', 'vereinsplugin' ) ),
		'vp_talk_kreise_aktiv'   => array( __( 'Kreise', 'vereinsplugin' ), __( 'Kreis-Chats (Talk) geändert', 'vereinsplugin' ) ),
	) );
}

$GLOBALS['vp_live_puffer'] = array();

add_filter( 'query', 'vp_live_query_mitschnitt' );
function vp_live_query_mitschnitt( $q ) {
	static $aktiv = false;
	if ( $aktiv || ! did_action( 'init' ) || ! preg_match( '/^\s*(INSERT|REPLACE|UPDATE|DELETE)\b/i', $q, $m ) ) {
		return $q;
	}
	$aktiv = true;
	try {
		if ( vp_live_kontext() ) {
			vp_live_query_merken( $q, strtoupper( $m[1] ) );
		}
	} catch ( \Throwable $e ) {
		// Mitschnitt darf nie eine Speicherung verhindern.
	}
	$aktiv = false;
	return $q;
}

/** Wert eines SQL-Literals ('…', Zahl, NULL) zurück in PHP. */
function vp_live_sql_wert( $v ) {
	$v = trim( $v );
	if ( 'NULL' === strtoupper( $v ) ) {
		return null;
	}
	if ( strlen( $v ) >= 2 && "'" === $v[0] ) {
		return stripcslashes( substr( $v, 1, -1 ) );
	}
	return $v;
}

function vp_live_query_merken( $q, $op ) {
	global $wpdb;
	$pre = preg_quote( $wpdb->prefix, '/' );
	if ( ! preg_match( '/^\s*(?:INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+`?' . $pre . '(\w+)`?/i', $q, $m ) ) {
		return;
	}
	$tabelle = $m[1];
	$werte   = array();

	// Spalten/Werte aus den Abfragen, die $wpdb->insert/update/delete erzeugen.
	if ( in_array( $op, array( 'INSERT', 'REPLACE' ), true ) && preg_match( '/\(([^)]*)\)\s*VALUES\s*\((.*)\)\s*$/is', $q, $mm ) ) {
		$spalten = array_map( function ( $c ) { return trim( $c, " `\t\n" ); }, explode( ',', $mm[1] ) );
		preg_match_all( "/'(?:[^'\\\\]|\\\\.)*'|NULL|-?[\d.]+/i", $mm[2], $vv );
		if ( count( $vv[0] ) === count( $spalten ) ) {
			$werte = array_combine( $spalten, array_map( 'vp_live_sql_wert', $vv[0] ) );
		}
	} elseif ( 'UPDATE' === $op && preg_match( '/\bSET\b(.*?)(?:\bWHERE\b|$)/is', $q, $mm ) ) {
		preg_match_all( "/`?(\w+)`?\s*=\s*('(?:[^'\\\\]|\\\\.)*'|NULL|-?[\d.]+)/i", $mm[1], $vv, PREG_SET_ORDER );
		foreach ( $vv as $x ) {
			$werte[ $x[1] ] = vp_live_sql_wert( $x[2] );
		}
	}
	$id = null;
	if ( preg_match( "/\bWHERE\b.*?`?\bid`?\s*=\s*'?(\d+)/is", $q, $mm ) ) {
		$id = (int) $mm[1];
	}

	// Kalender-Einstellungen u. Ä. liegen in wp_options.
	if ( 'options' === $tabelle ) {
		$name = $werte['option_name'] ?? '';
		if ( ! $name && preg_match( "/option_name`?\s*=\s*'([^']+)'/i", $q, $mm ) ) {
			$name = $mm[1];
		}
		$opt = vp_live_optionen()[ $name ] ?? null;
		if ( $opt ) {
			$GLOBALS['vp_live_puffer'][ 'opt:' . $name ] = array( 'bereich' => $opt[0], 'titel' => $opt[1] );
		}
		return;
	}

	$map = vp_live_tabellen()[ $tabelle ] ?? null;
	if ( ! $map ) {
		return;
	}

	// Titel: aus den Werten, sonst (Änderung/Löschung) aus dem Datensatz.
	$zeile = $werte;
	if ( $id && ( 'DELETE' === $op || ! vp_live_titel_aus( $werte ) ) ) {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}{$tabelle}` WHERE id = %d", $id ), ARRAY_A );
		if ( $row ) {
			$zeile = array_merge( $row, $werte );
		}
	}
	$titel = vp_live_titel_aus( $zeile );
	if ( '' === $titel && ! empty( $zeile['user_id'] ) ) {
		$u     = get_userdata( (int) $zeile['user_id'] );
		$titel = $u ? $u->display_name : '';
	}
	if ( ! empty( $zeile['gremium_id'] ) && in_array( $tabelle, array( 'pp_kreis_mitglieder', 'pp_rollen' ), true ) && function_exists( 'pp_get_gremium' ) ) {
		$g      = pp_get_gremium( (int) $zeile['gremium_id'] );
		$titel .= $g ? ' (' . $g->name . ')' : '';
	}
	if ( isset( $zeile['betrag'] ) && is_numeric( $zeile['betrag'] ) && (float) $zeile['betrag'] ) {
		$titel .= ' · ' . number_format_i18n( (float) $zeile['betrag'], 2 ) . ' €';
	}

	$wort = array( 'INSERT' => __( 'angelegt', 'vereinsplugin' ), 'REPLACE' => __( 'gespeichert', 'vereinsplugin' ), 'UPDATE' => __( 'geändert', 'vereinsplugin' ), 'DELETE' => __( 'gelöscht', 'vereinsplugin' ) )[ $op ];
	if ( 'UPDATE' === $op && array_key_exists( 'ausgetreten_am', $werte ) && $werte['ausgetreten_am'] ) {
		$wort = __( 'beendet', 'vereinsplugin' );
	}
	if ( 'UPDATE' === $op && isset( $werte['status'] ) && ! isset( $werte['titel'] ) ) {
		$wort = sprintf( /* translators: %s = Status */ __( 'Status → %s', 'vereinsplugin' ), $werte['status'] );
	}

	$key = $tabelle . ':' . ( $id ?: md5( $titel ) );
	// Innerhalb eines Requests: „angelegt“ schlägt spätere „geändert“.
	if ( isset( $GLOBALS['vp_live_puffer'][ $key ] ) && 'UPDATE' === $op ) {
		return;
	}
	$GLOBALS['vp_live_puffer'][ $key ] = array(
		'bereich' => $map[0],
		'titel'   => trim( $map[1] . ' ' . ( '' !== $titel ? '„' . $titel . '“ ' : '' ) . $wort ),
	);
}

function vp_live_titel_aus( array $z ) {
	foreach ( array( 'titel', 'name', 'bezeichnung', 'zweck', 'verwendungszweck', 'beschreibung', 'text' ) as $k ) {
		if ( isset( $z[ $k ] ) && '' !== trim( (string) $z[ $k ] ) ) {
			return wp_html_excerpt( wp_strip_all_tags( (string) $z[ $k ] ), 90, '…' );
		}
	}
	return '';
}

// Veranstaltungen (Beiträge) laufen über WordPress selbst.
add_action( 'save_post_veranstaltung', function ( $post_id, $post, $update ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
		return;
	}
	if ( vp_live_kontext() ) {
		$GLOBALS['vp_live_puffer'][ 'post:' . $post_id ] = array(
			'bereich' => __( 'Veranstaltungen', 'vereinsplugin' ),
			'titel'   => sprintf( $update ? /* translators: %s = Titel */ __( 'Veranstaltung „%s“ gespeichert', 'vereinsplugin' ) : __( 'Veranstaltung „%s“ angelegt', 'vereinsplugin' ), $post->post_title ),
		);
	}
}, 10, 3 );

// Am Ende des Requests (auch nach Weiterleitung + exit) wegschreiben.
add_action( 'shutdown', 'vp_live_puffer_schreiben' );
function vp_live_puffer_schreiben() {
	$puffer = $GLOBALS['vp_live_puffer'] ?? array();
	$p      = $puffer ? vp_live_kontext() : null;
	if ( ! $p ) {
		return;
	}
	$GLOBALS['vp_live_puffer'] = array();
	foreach ( $puffer as $e ) {
		vp_live_eintrag( $p->id, 'aenderung', $e['bereich'], $e['titel'] );
	}
}

function vp_live_eintrag( $protokoll_id, $art, $bereich, $titel, $inhalt = '', $top_id = null ) {
	global $wpdb;
	$wpdb->insert( vp_live_table(), array(
		'protokoll_id' => (int) $protokoll_id,
		'top_id'       => $top_id ? (int) $top_id : null,
		'user_id'      => get_current_user_id(),
		'zeit'         => current_time( 'mysql' ),
		'art'          => $art,
		'bereich'      => mb_substr( (string) $bereich, 0, 60 ),
		'titel'        => mb_substr( (string) $titel, 0, 255 ),
		'inhalt'       => (string) $inhalt,
	) );
}

function vp_live_eintraege( $protokoll_id ) {
	global $wpdb;
	return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . vp_live_table() . ' WHERE protokoll_id = %d ORDER BY zeit, id', $protokoll_id ) );
}

/* =========================================================================
 * Berichte einfügen (Stand zum Zeitpunkt der Sitzung)
 * ====================================================================== */

function vp_live_eur( $n ) {
	return number_format_i18n( (float) $n, 2 ) . ' €';
}

function vp_live_tabelle_html( array $kopf, array $zeilen, array $fuss = array() ) {
	$h = '<table class="vp-live-tab"><thead><tr>';
	foreach ( $kopf as $k ) {
		$h .= '<th>' . esc_html( $k ) . '</th>';
	}
	$h .= '</tr></thead><tbody>';
	foreach ( $zeilen as $z ) {
		$h .= '<tr>';
		foreach ( $z as $c ) {
			$h .= '<td>' . esc_html( $c ) . '</td>';
		}
		$h .= '</tr>';
	}
	$h .= '</tbody>';
	if ( $fuss ) {
		$h .= '<tfoot><tr>';
		foreach ( $fuss as $c ) {
			$h .= '<th>' . esc_html( $c ) . '</th>';
		}
		$h .= '</tr></tfoot>';
	}
	return $h . '</table>';
}

/** @return array{0:string,1:string}|WP_Error [Titel, HTML] */
function vp_live_bericht( $typ, $gremium_id = 0 ) {
	switch ( $typ ) {
		case 'kasse':
			$html = '';
			if ( function_exists( 'vp_bh_geldkonten_stand' ) ) {
				$z   = array();
				$sum = 0;
				foreach ( vp_bh_geldkonten_stand() as $k ) {
					$z[]  = array( $k['name'], vp_live_eur( $k['anfang'] ), vp_live_eur( $k['zugang'] ), vp_live_eur( $k['abgang'] ), vp_live_eur( $k['ende'] ) );
					$sum += $k['ende'];
				}
				$html .= '<h5>' . esc_html__( 'Geldkonten (laufendes Jahr)', 'vereinsplugin' ) . '</h5>' . vp_live_tabelle_html(
					array( __( 'Konto', 'vereinsplugin' ), __( 'Anfang', 'vereinsplugin' ), __( 'Zugänge', 'vereinsplugin' ), __( 'Abgänge', 'vereinsplugin' ), __( 'Stand', 'vereinsplugin' ) ),
					$z,
					array( __( 'Gesamt', 'vereinsplugin' ), '', '', '', vp_live_eur( $sum ) )
				);
			}
			if ( function_exists( 'jb_budgets_get_all' ) ) {
				$z = array();
				foreach ( jb_budgets_get_all() as $b ) {
					if ( ! empty( $b['gremium_id'] ) ) {
						continue; // Kreis-Budgets stehen bei der Kreiskasse
					}
					$z[] = array( $b['zweck'], vp_live_eur( $b['betrag'] ), vp_live_eur( $b['verbraucht'] ), vp_live_eur( $b['rest'] ) );
				}
				if ( $z ) {
					$html .= '<h5>' . esc_html__( 'Budgets des Vereins', 'vereinsplugin' ) . '</h5>' . vp_live_tabelle_html( array( __( 'Budget', 'vereinsplugin' ), __( 'geplant', 'vereinsplugin' ), __( 'verbraucht', 'vereinsplugin' ), __( 'Rest', 'vereinsplugin' ) ), $z );
				}
			}
			if ( '' === $html ) {
				return new WP_Error( 'vp_live', __( 'Die Buchhaltung ist nicht aktiv.', 'vereinsplugin' ) );
			}
			return array( __( 'Kassenbericht', 'vereinsplugin' ), $html );

		case 'kreiskasse':
			if ( ! function_exists( 'vp_kreis_finanzen' ) || ! vp_kreis_kasse_verfuegbar() ) {
				return new WP_Error( 'vp_live', __( 'Kreiskassen sind nicht verfügbar.', 'vereinsplugin' ) );
			}
			$g = function_exists( 'pp_get_gremium' ) ? pp_get_gremium( $gremium_id ) : null;
			if ( ! $g ) {
				return new WP_Error( 'vp_live', __( 'Bitte einen Kreis wählen.', 'vereinsplugin' ) );
			}
			$f = vp_kreis_finanzen( $gremium_id );
			$z = array();
			foreach ( $f['budgets'] as $b ) {
				$z[] = array( $b->zweck, vp_live_eur( $b->betrag ), vp_live_eur( $b->verbraucht ), vp_live_eur( $b->rest ?? ( $b->betrag - $b->verbraucht ) ) );
			}
			$html = vp_live_tabelle_html(
				array( __( 'Budget', 'vereinsplugin' ), __( 'geplant', 'vereinsplugin' ), __( 'verbraucht', 'vereinsplugin' ), __( 'Rest', 'vereinsplugin' ) ),
				$z ?: array( array( __( 'Keine Budgets', 'vereinsplugin' ), '', '', '' ) ),
				array( __( 'Summe', 'vereinsplugin' ), vp_live_eur( $f['geplant'] ), vp_live_eur( $f['verbraucht'] ), '' )
			);
			$html .= '<p>' . esc_html( sprintf(
				/* translators: 1: Einnahmen, 2: verfügbar, 3: offene Auslagen (Anzahl), 4: Betrag */
				__( 'Einnahmen: %1$s · verfügbar: %2$s · offene Auslagen: %3$d (%4$s)', 'vereinsplugin' ),
				vp_live_eur( $f['einnahmen'] ), vp_live_eur( $f['verfuegbar'] ), $f['offen_n'], vp_live_eur( $f['offen'] )
			) ) . '</p>';
			return array( sprintf( /* translators: %s = Kreis */ __( 'Kreiskasse & Budgets: %s', 'vereinsplugin' ), $g->name ), $html );

		case 'wunschliste':
			if ( ! function_exists( 'wl_get_wuensche_mit_score' ) ) {
				return new WP_Error( 'vp_live', __( 'Die Wunschliste ist nicht aktiv.', 'vereinsplugin' ) );
			}
			$liste = $gremium_id && function_exists( 'vp_kreis_wuensche' ) ? vp_kreis_wuensche( $gremium_id, true ) : wl_get_wuensche_mit_score( true );
			$z     = array();
			$sum   = 0;
			foreach ( array_slice( (array) $liste, 0, 25 ) as $w ) {
				$betrag = function_exists( 'vp_kreis_wunsch_betrag' ) ? vp_kreis_wunsch_betrag( $w ) : (float) $w->betrag;
				$sum   += $betrag;
				$z[]    = array( $w->titel, $w->kategorie, $betrag ? vp_live_eur( $betrag ) : '–', (string) (int) $w->vote_score, 'offen' === $w->status ? __( 'offen', 'vereinsplugin' ) : __( 'in Bearbeitung', 'vereinsplugin' ) );
			}
			$g = $gremium_id && function_exists( 'pp_get_gremium' ) ? pp_get_gremium( $gremium_id ) : null;
			return array(
				$g ? sprintf( /* translators: %s = Kreis */ __( 'Wunschliste: %s', 'vereinsplugin' ), $g->name ) : __( 'Wunschliste', 'vereinsplugin' ),
				vp_live_tabelle_html(
					array( __( 'Wunsch', 'vereinsplugin' ), __( 'Kategorie', 'vereinsplugin' ), __( 'Betrag', 'vereinsplugin' ), __( 'Stimmen', 'vereinsplugin' ), __( 'Status', 'vereinsplugin' ) ),
					$z ?: array( array( __( 'Keine offenen Wünsche', 'vereinsplugin' ), '', '', '', '' ) ),
					array( __( 'Summe', 'vereinsplugin' ), '', vp_live_eur( $sum ), '', '' )
				),
			);
	}
	return new WP_Error( 'vp_live', __( 'Unbekannter Bericht.', 'vereinsplugin' ) );
}

add_action( 'admin_post_vp_live_einfuegen', function () {
	if ( ! current_user_can( 'pp_manage' ) && ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) );
	}
	check_admin_referer( 'vp_live_einfuegen' );
	$pid    = (int) ( $_POST['protokoll_id'] ?? 0 );
	$back   = esc_url_raw( wp_unslash( $_POST['zurueck'] ?? '' ) ) ?: home_url( '/' );
	$typ    = sanitize_key( $_POST['typ'] ?? '' );
	$gid    = 0;
	if ( preg_match( '/^(kreiskasse|wunschliste)_(\d+)$/', $typ, $m ) ) {
		$typ = $m[1];
		$gid = (int) $m[2];
	}
	$b = vp_live_bericht( $typ, $gid );
	if ( is_wp_error( $b ) ) {
		wp_safe_redirect( add_query_arg( 'pp_error', rawurlencode( $b->get_error_message() ), $back ) );
		exit;
	}
	$zeit = current_time( 'd.m.Y H:i' );
	vp_live_eintrag( $pid, 'bericht', __( 'Bericht', 'vereinsplugin' ), $b[0] . ' (' . sprintf( /* translators: %s = Zeit */ __( 'Stand %s', 'vereinsplugin' ), $zeit ) . ')', $b[1], (int) ( $_POST['top_id'] ?? 0 ) ?: null );
	wp_safe_redirect( add_query_arg( 'pp_saved', '1', $back ) . '#vp-live-anhang' );
	exit;
} );

add_action( 'admin_post_vp_live_notiz_loeschen', function () {
	if ( ! current_user_can( 'pp_manage' ) && ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) );
	}
	check_admin_referer( 'vp_live_loeschen' );
	global $wpdb;
	// Nur solange das Protokoll offen ist.
	$e = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vp_live_table() . ' WHERE id = %d', (int) ( $_POST['eintrag'] ?? 0 ) ) );
	$p = $e && function_exists( 'pp_get_protokoll' ) ? pp_get_protokoll( $e->protokoll_id ) : null;
	if ( $p && 'abgeschlossen' !== $p->status ) {
		$wpdb->delete( vp_live_table(), array( 'id' => (int) $e->id ) );
	}
	wp_safe_redirect( ( esc_url_raw( wp_unslash( $_POST['zurueck'] ?? '' ) ) ?: home_url( '/' ) ) . '#vp-live-anhang' );
	exit;
} );

/* =========================================================================
 * Live-Seitenleiste: Werkzeuge
 * ====================================================================== */

add_action( 'pp_live_werkzeuge', 'vp_live_werkzeuge' );
function vp_live_werkzeuge( $p ) {
	// Wer die Live-Ansicht öffnet, ist ab jetzt im Live-Kontext.
	if ( 'abgeschlossen' !== $p->status && (int) get_user_meta( get_current_user_id(), 'vp_live_protokoll', true ) !== (int) $p->id ) {
		vp_live_setzen( $p->id );
	}
	$zurueck  = home_url( add_query_arg( array() ) );
	$sections = function_exists( 'vp_visible_sections' ) ? vp_visible_sections() : array();
	$gid      = (int) $p->gremium_id;

	// Online-Teilnahme.
	if ( function_exists( 'vp_talk_sitzung_knoepfe' ) && vp_talk_ready() ) {
		echo '<details class="pp-live-werkzeug" open><summary class="pp-btn pp-btn-small">' . esc_html__( '🎥 Online-Teilnahme', 'vereinsplugin' ) . '</summary><div class="vp-live-talk">';
		vp_talk_sitzung_knoepfe( $p );
		echo '<p class="pp-live-hinweis">' . esc_html__( 'Der Link öffnet Nextcloud Talk (Browser oder App). Wer online dabei ist, sieht dort auch den Bildschirm, wenn du ihn teilst.', 'vereinsplugin' ) . '</p></div></details>';
	}

	// Weiterarbeiten an … (neuer Tab, Protokoll bleibt offen).
	$links = array();
	$add   = function ( $tab, $label, $args = array() ) use ( &$links, $sections ) {
		if ( isset( $sections[ $tab ] ) ) {
			$links[] = array( $label, vp_live_area_url( array_merge( array( 'vp_tab' => $tab ), $args ) ) );
		}
	};
	$add( 'kassenbericht', __( 'Kassenbericht', 'vereinsplugin' ) );
	$add( 'buchhaltung', __( 'Buchhaltung / Journal', 'vereinsplugin' ) );
	$add( 'budgets', __( 'Budgets & Rücklagen', 'vereinsplugin' ) );
	if ( $gid ) {
		$add( 'kreise', __( 'Kreiskasse dieses Kreises', 'vereinsplugin' ), array( 'pp_view' => 'kreis', 'id' => $gid, 'k_tab' => 'kasse' ) );
		$add( 'kreise', __( 'Wunschliste dieses Kreises', 'vereinsplugin' ), array( 'pp_view' => 'kreis', 'id' => $gid, 'k_tab' => 'wuensche' ) );
	}
	$add( 'wuensche', __( 'Wunschliste verwalten', 'vereinsplugin' ) );
	if ( $gid ) {
		$add( 'kreise', __( 'Diesen Kreis bearbeiten', 'vereinsplugin' ), array( 'pp_view' => 'kreis', 'id' => $gid, 'k_tab' => 'struktur' ) );
		$add( 'kreise', __( 'Unterkreis anlegen', 'vereinsplugin' ), array( 'pp_view' => 'kreise', 'parent' => $gid ) );
	}
	$add( 'schichtplaene', __( 'Schichtpläne', 'vereinsplugin' ), array( 'vp_sp' => 'verwalten' ) );
	$add( 'aufgaben', __( 'Aufgaben', 'vereinsplugin' ) );
	$add( 'kalender', __( 'Kalender', 'vereinsplugin' ), array( 'vp_kal' => 'verwalten' ) );
	$add( 'projekte', __( 'Projekte & Veranstaltungen', 'vereinsplugin' ) );
	$add( 'veranstaltungen', __( 'Veranstaltungs-Publisher', 'vereinsplugin' ) );
	if ( $links ) {
		echo '<details class="pp-live-werkzeug"><summary class="pp-btn pp-btn-small">' . esc_html__( 'Weiterarbeiten an …', 'vereinsplugin' ) . '</summary>';
		echo '<p class="pp-live-hinweis">' . esc_html__( 'Öffnet in einem neuen Tab – das Protokoll bleibt offen. Änderungen dort werden hier automatisch dokumentiert.', 'vereinsplugin' ) . '</p><ul class="vp-live-links">';
		foreach ( $links as $l ) {
			// Unterkreis: direkt zum Formular springen.
			$url = false !== strpos( $l[1], 'parent=' ) ? $l[1] . '#pp-neuer-kreis' : $l[1];
			printf( '<li><a href="%s" target="_blank" rel="noopener">%s ↗</a></li>', esc_url( $url ), esc_html( $l[0] ) );
		}
		echo '</ul></details>';
	}

	// Bericht einfügen.
	$optionen = array();
	if ( function_exists( 'vp_bh_geldkonten_stand' ) || function_exists( 'jb_budgets_get_all' ) ) {
		$optionen['kasse'] = __( 'Kassenbericht (Verein)', 'vereinsplugin' );
	}
	$gremien = function_exists( 'pp_get_gremien' ) ? (array) pp_get_gremien() : array();
	if ( function_exists( 'vp_kreis_kasse_verfuegbar' ) && vp_kreis_kasse_verfuegbar() ) {
		foreach ( $gremien as $g ) {
			$optionen[ 'kreiskasse_' . $g->id ] = sprintf( /* translators: %s = Kreis */ __( 'Kreiskasse & Budgets: %s', 'vereinsplugin' ), $g->name );
		}
	}
	if ( function_exists( 'wl_get_wuensche_mit_score' ) ) {
		$optionen['wunschliste'] = __( 'Wunschliste (alle offenen)', 'vereinsplugin' );
		foreach ( $gremien as $g ) {
			$optionen[ 'wunschliste_' . $g->id ] = sprintf( /* translators: %s = Kreis */ __( 'Wunschliste: %s', 'vereinsplugin' ), $g->name );
		}
	}
	if ( $optionen ) {
		$tops = function_exists( 'pp_get_tops_fuer_protokoll' ) ? (array) pp_get_tops_fuer_protokoll( $p->id ) : array();
		echo '<details class="pp-live-werkzeug"><summary class="pp-btn pp-btn-small">' . esc_html__( '+ Bericht einfügen', 'vereinsplugin' ) . '</summary>';
		echo '<p class="pp-live-hinweis">' . esc_html__( 'Fügt den aktuellen Stand als Anlage ins Protokoll ein.', 'vereinsplugin' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="pp-live-form">';
		wp_nonce_field( 'vp_live_einfuegen' );
		echo '<input type="hidden" name="action" value="vp_live_einfuegen"><input type="hidden" name="protokoll_id" value="' . (int) $p->id . '"><input type="hidden" name="zurueck" value="' . esc_url( $zurueck ) . '">';
		echo '<select name="typ" required>';
		foreach ( $optionen as $k => $label ) {
			$vorwahl = ( 'kreiskasse_' . $gid === $k );
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $vorwahl, true, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><select name="top_id"><option value="">' . esc_html__( 'zu TOP … (optional)', 'vereinsplugin' ) . '</option>';
		foreach ( $tops as $t ) {
			echo '<option value="' . (int) $t->id . '">' . esc_html( $t->titel ) . '</option>';
		}
		echo '</select><button type="submit" class="pp-btn pp-btn-small">' . esc_html__( 'Einfügen', 'vereinsplugin' ) . '</button></form></details>';
	}
}

/* =========================================================================
 * Anzeige im Protokoll (Live und Detail)
 * ====================================================================== */

add_action( 'pp_protokoll_anhang', 'vp_live_anhang', 10, 2 );
function vp_live_anhang( $p, $wo = 'detail' ) {
	$eintraege = vp_live_eintraege( $p->id );
	if ( ! $eintraege && 'live' !== $wo ) {
		return;
	}
	$darf    = ( current_user_can( 'pp_manage' ) || current_user_can( 'manage_options' ) ) && 'abgeschlossen' !== $p->status;
	$zurueck = home_url( add_query_arg( array() ) );
	$tops    = array();
	if ( function_exists( 'pp_get_tops_fuer_protokoll' ) ) {
		foreach ( (array) pp_get_tops_fuer_protokoll( $p->id ) as $t ) {
			$tops[ (int) $t->id ] = $t->titel;
		}
	}

	echo '<section class="vp-live-anhang" id="vp-live-anhang"><h3>' . esc_html__( 'Während der Sitzung erledigt', 'vereinsplugin' ) . '</h3>';
	if ( ! $eintraege ) {
		echo '<p class="pp-empty">' . esc_html__( 'Noch nichts. Änderungen, die Teilnehmende während der Sitzung im Mitgliederbereich machen (Kasse, Wunschliste, Kreise, Schichtpläne, Aufgaben, Kalender, Projekte …), und eingefügte Berichte erscheinen hier automatisch.', 'vereinsplugin' ) . '</p></section>';
		return;
	}

	$aenderungen = array_filter( $eintraege, function ( $e ) { return 'aenderung' === $e->art; } );
	$berichte    = array_filter( $eintraege, function ( $e ) { return 'bericht' === $e->art; } );
	$loeschen    = function ( $e ) use ( $darf, $zurueck ) {
		if ( ! $darf ) {
			return '';
		}
		return sprintf(
			'<form method="post" action="%s" class="pp-inline">%s<input type="hidden" name="action" value="vp_live_notiz_loeschen"><input type="hidden" name="eintrag" value="%d"><input type="hidden" name="zurueck" value="%s"><button class="pp-link-danger" onclick="return confirm(\'%s\')">%s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'vp_live_loeschen', '_wpnonce', true, false ),
			(int) $e->id,
			esc_url( $zurueck ),
			esc_js( __( 'Eintrag aus dem Protokoll entfernen?', 'vereinsplugin' ) ),
			esc_html__( 'entfernen', 'vereinsplugin' )
		);
	};

	if ( $aenderungen ) {
		echo '<ul class="vp-live-liste">';
		foreach ( $aenderungen as $e ) {
			$u = get_userdata( (int) $e->user_id );
			printf(
				'<li><span class="vp-live-zeit">%s</span> <span class="vp-live-bereich">%s</span> %s <span class="pp-meta">– %s</span> %s</li>',
				esc_html( mysql2date( 'H:i', $e->zeit ) ),
				esc_html( $e->bereich ),
				esc_html( $e->titel ),
				esc_html( $u ? $u->display_name : '' ),
				$loeschen( $e ) // phpcs:ignore WordPress.Security.EscapeOutput
			);
		}
		echo '</ul>';
	}
	foreach ( $berichte as $e ) {
		printf(
			'<div class="vp-live-bericht"><h4>%s%s %s</h4>%s</div>',
			esc_html( $e->titel ),
			$e->top_id && isset( $tops[ (int) $e->top_id ] ) ? ' <span class="pp-meta">· ' . esc_html( sprintf( /* translators: %s = TOP */ __( 'zu TOP „%s“', 'vereinsplugin' ), $tops[ (int) $e->top_id ] ) ) . '</span>' : '',
			$loeschen( $e ), // phpcs:ignore WordPress.Security.EscapeOutput
			wp_kses_post( $e->inhalt )
		);
	}
	echo '</section>';
}
