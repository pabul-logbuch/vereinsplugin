<?php
/**
 * Kern: Vereinskalender.
 *
 * Führt alle Termine des Vereins in einer Ansicht zusammen – ohne eigene
 * Doppelhaltung, die Daten bleiben in ihren Modulen:
 *
 *   - Veranstaltungen  (Veranstaltungs-Publisher, CPT `veranstaltung`)
 *   - Sitzungen        (ProtokollPro, pp_protokolle) + Termine aus Beschlüssen (pp_termine)
 *   - Schichtpläne     (Wunschliste, wl_shift_*) inkl. „Meine Schichten“
 *   - Öffnungszeiten   (wöchentlich wiederkehrend + Schließtage, hier gepflegt)
 *   - Weitere Termine  (frei eingetragen, hier gepflegt)
 *   - Nextcloud        (beliebig viele Nextcloud-Kalender, nur lesend eingebunden)
 *
 * Nextcloud in die andere Richtung: jede Person bekommt einen persönlichen
 * Abo-Link (ICS), den sie in Nextcloud unter „Neues Abonnement aus Link“ (oder
 * in jedem anderen Kalender) einträgt. Der Link enthält nur, was diese Person
 * auch im Mitgliederbereich sehen darf.
 *
 * Weitere Quellen hängen sich über den Filter `vp_kalender_termine` an.
 */

defined( 'ABSPATH' ) || exit;

/* =========================================================================
 * Kategorien + Hilfsfunktionen
 * ====================================================================== */

function vp_kal_kategorien() {
	return apply_filters( 'vp_kalender_kategorien', array(
		'veranstaltung' => array( 'label' => __( 'Veranstaltungen', 'vereinsplugin' ), 'farbe' => '#2563eb' ),
		'sitzung'       => array( 'label' => __( 'Sitzungen', 'vereinsplugin' ), 'farbe' => '#7c3aed' ),
		'termin'        => array( 'label' => __( 'Kreis-Termine', 'vereinsplugin' ), 'farbe' => '#0891b2' ),
		'aufgabe'       => array( 'label' => __( 'Meine Aufgaben-Fristen', 'vereinsplugin' ), 'farbe' => '#ca8a04' ),
		'schicht'       => array( 'label' => __( 'Schichtpläne', 'vereinsplugin' ), 'farbe' => '#ea580c' ),
		'oeffnung'      => array( 'label' => __( 'Öffnungszeiten', 'vereinsplugin' ), 'farbe' => '#16a34a' ),
		'sonstiges'     => array( 'label' => __( 'Weitere Termine', 'vereinsplugin' ), 'farbe' => '#db2777' ),
		'nextcloud'     => array( 'label' => __( 'Nextcloud', 'vereinsplugin' ), 'farbe' => '#0082c9' ),
	) );
}

function vp_kal_wochentage() {
	return array( 1 => 'Mo', 2 => 'Di', 3 => 'Mi', 4 => 'Do', 5 => 'Fr', 6 => 'Sa', 7 => 'So' );
}

function vp_kal_table_exists( $table ) {
	static $cache = array();
	if ( ! isset( $cache[ $table ] ) ) {
		global $wpdb;
		$cache[ $table ] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
	}
	return $cache[ $table ];
}

/** Darf die Person Kalender-Einstellungen (Öffnungszeiten, Termine, Nextcloud) pflegen? */
function vp_kal_can_manage( $user_id = 0 ) {
	$user_id = $user_id ?: get_current_user_id();
	return user_can( $user_id, 'wl_manage_wishes' ) || user_can( $user_id, 'pp_manage' ) || user_can( $user_id, 'manage_options' );
}

/** Basis-URL des Mitgliederbereichs (für Links aus dem ICS-Feed heraus). */
function vp_kal_area_url( $args = array() ) {
	$base = get_permalink() ?: get_option( 'vp_member_area_url', home_url( '/' ) );
	return add_query_arg( $args, $base );
}

/**
 * Ein Termin. `start`/`ende` sind lokale Zeit (WordPress-Zeitzone) als
 * 'Y-m-d H:i'; bei ganztägigen Terminen 'Y-m-d' (ende inklusiv).
 */
function vp_kal_eintrag( $kat, $titel, $start, $args = array() ) {
	return array_merge( array(
		'id'       => $kat . '-' . md5( $titel . $start ),
		'kat'      => $kat,
		'titel'    => $titel,
		'start'    => $start,
		'ende'     => '',
		'ganztags' => false,
		'ort'      => '',
		'info'     => '',
		'url'      => '',
		'mein'     => false,
		'farbe'    => '',
	), $args );
}

/* =========================================================================
 * Termine sammeln
 * ====================================================================== */

/**
 * Alle Termine im Zeitraum [$von, $bis] (Y-m-d, inklusiv), die $user_id sehen darf.
 *
 * @param array $args ['ohne' => [kategorien]] – z. B. Nextcloud im ICS-Export weglassen.
 */
function vp_kal_termine( $von, $bis, $user_id = 0, $args = array() ) {
	$user_id = $user_id ?: get_current_user_id();
	$ohne    = (array) ( $args['ohne'] ?? array() );

	$quellen = array(
		'veranstaltung' => 'vp_kal_quelle_veranstaltungen',
		'sitzung'       => 'vp_kal_quelle_sitzungen',
		'aufgabe'       => 'vp_kal_quelle_aufgaben',
		'schicht'       => 'vp_kal_quelle_schichten',
		'oeffnung'      => 'vp_kal_quelle_oeffnungszeiten',
		'sonstiges'     => 'vp_kal_quelle_eigene',
		'nextcloud'     => 'vp_kal_quelle_nextcloud',
	);

	$alle = array();
	foreach ( $quellen as $kat => $fn ) {
		if ( in_array( $kat, $ohne, true ) ) {
			continue;
		}
		try {
			$alle = array_merge( $alle, (array) call_user_func( $fn, $von, $bis, $user_id ) );
		} catch ( \Throwable $e ) {
			// Eine kaputte Quelle darf nicht den ganzen Kalender lahmlegen.
			continue;
		}
	}
	$alle = (array) apply_filters( 'vp_kalender_termine', $alle, $von, $bis, $user_id );

	if ( $ohne ) {
		$alle = array_filter( $alle, function ( $t ) use ( $ohne ) { return ! in_array( $t['kat'], $ohne, true ); } );
	}

	usort( $alle, function ( $a, $b ) {
		// Ganztägiges zuerst, dann nach Uhrzeit.
		$ka = substr( $a['start'], 0, 10 ) . ( $a['ganztags'] ? ' 00:00' : ' ' . substr( $a['start'], 11, 5 ) . '~' );
		$kb = substr( $b['start'], 0, 10 ) . ( $b['ganztags'] ? ' 00:00' : ' ' . substr( $b['start'], 11, 5 ) . '~' );
		return strcmp( $ka, $kb ) ?: strcmp( $a['titel'], $b['titel'] );
	} );
	return array_values( $alle );
}

/* ---- Veranstaltungen (Publisher-CPT) ---- */

function vp_kal_quelle_veranstaltungen( $von, $bis, $user_id ) {
	if ( ! post_type_exists( 'veranstaltung' ) ) {
		return array();
	}
	$editor = user_can( $user_id, 'jbf_edit_events' );
	$posts  = get_posts( array(
		'post_type'        => 'veranstaltung',
		'post_status'      => $editor ? array( 'publish', 'future', 'draft', 'pending', 'private' ) : array( 'publish' ),
		'posts_per_page'   => 300,
		'suppress_filters' => true,
		'meta_query'       => array(
			array( 'key' => '_jbf_date_start', 'value' => $von, 'compare' => '>=' ),
			// datetime-local ('2026-10-05T19:00') – exklusive Obergrenze = Folgetag.
			array( 'key' => '_jbf_date_start', 'value' => gmdate( 'Y-m-d', strtotime( $bis . ' +1 day' ) ), 'compare' => '<' ),
		),
	) );

	$out = array();
	foreach ( $posts as $p ) {
		$raw = (string) get_post_meta( $p->ID, '_jbf_date_start', true );
		$ts  = strtotime( str_replace( 'T', ' ', $raw ) );
		if ( ! $ts ) {
			continue;
		}
		$ganztags = strlen( $raw ) <= 10;
		$titel    = get_the_title( $p );
		if ( 'publish' !== $p->post_status ) {
			$titel .= ' (' . __( 'Entwurf', 'vereinsplugin' ) . ')';
		}
		$out[] = vp_kal_eintrag( 'veranstaltung', $titel, $ganztags ? gmdate( 'Y-m-d', $ts ) : gmdate( 'Y-m-d H:i', $ts ), array(
			'id'       => 'veranstaltung-' . $p->ID,
			'ganztags' => $ganztags,
			'ort'      => (string) get_post_meta( $p->ID, '_jbf_location', true ),
			'info'     => wp_trim_words( (string) get_post_meta( $p->ID, '_jbf_short_text', true ), 40 ),
			'url'      => 'publish' === $p->post_status ? get_permalink( $p ) : ( $editor ? vp_kal_area_url( array( 'vp_tab' => 'veranstaltungen' ) ) : '' ),
		) );
	}
	return $out;
}

/* ---- Sitzungen + Termine aus Beschlüssen (ProtokollPro) ---- */

function vp_kal_quelle_sitzungen( $von, $bis, $user_id ) {
	global $wpdb;
	$prot  = $wpdb->prefix . 'pp_protokolle';
	$grem  = $wpdb->prefix . 'pp_gremien';
	$kreis = $wpdb->prefix . 'pp_kreis_mitglieder';
	if ( ! vp_kal_table_exists( $prot ) || ! vp_kal_table_exists( $grem ) ) {
		return array();
	}
	$manager = user_can( $user_id, 'pp_manage' );

	// „nur_gremium“ sehen nur Kreismitglieder (und wer Protokolle verwaltet).
	$sicht = function ( $spalte ) use ( $manager, $kreis, $user_id, $wpdb ) {
		if ( $manager ) {
			return '1=1';
		}
		if ( ! vp_kal_table_exists( $kreis ) ) {
			return "$spalte <> 'nur_gremium'";
		}
		return $wpdb->prepare(
			"($spalte <> 'nur_gremium' OR EXISTS (SELECT 1 FROM $kreis k WHERE k.gremium_id = g.id AND k.user_id = %d AND k.ausgetreten_am IS NULL))",
			$user_id
		);
	};

	$out  = array();
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.id, p.titel, p.datum, p.ort, p.status, p.uhrzeit_beginn, p.uhrzeit_ende, g.name AS gremium
		 FROM $prot p LEFT JOIN $grem g ON g.id = p.gremium_id
		 WHERE p.datum BETWEEN %s AND %s AND " . $sicht( 'p.sichtbarkeit' ),
		$von, $bis
	) );
	foreach ( (array) $rows as $r ) {
		$zeit  = $r->uhrzeit_beginn ? substr( $r->uhrzeit_beginn, 0, 5 ) : '';
		$titel = $r->titel;
		if ( $r->gremium && false === stripos( $titel, $r->gremium ) ) {
			$titel = $r->gremium . ': ' . $titel;
		}
		$out[] = vp_kal_eintrag( 'sitzung', $titel, $zeit ? $r->datum . ' ' . $zeit : $r->datum, array(
			'id'       => 'sitzung-' . $r->id,
			'ganztags' => ! $zeit,
			'ende'     => ( $zeit && $r->uhrzeit_ende ) ? $r->datum . ' ' . substr( $r->uhrzeit_ende, 0, 5 ) : '',
			'ort'      => (string) $r->ort,
			'info'     => 'abgeschlossen' === $r->status ? __( 'Protokoll liegt vor', 'vereinsplugin' ) : __( 'geplant / Protokoll in Arbeit', 'vereinsplugin' ),
			'url'      => $manager ? vp_kal_area_url( array( 'vp_tab' => 'protokolle', 'pp_view' => 'protokoll', 'id' => $r->id ) ) : '',
		) );
	}

	$term = $wpdb->prefix . 'pp_termine';
	if ( vp_kal_table_exists( $term ) ) {
		// Termine, die ProtokollPro für geplante Sitzungen anlegt, stehen schon
		// als Sitzung im Kalender – nicht doppelt zeigen.
		$ohne_sitzung = $wpdb->get_var( "SHOW COLUMNS FROM $term LIKE 'quelle_protokoll_id'" ) ? ' AND t.quelle_protokoll_id IS NULL' : '';
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.id, t.titel, t.datum, t.ort, g.name AS gremium
			 FROM $term t LEFT JOIN $grem g ON g.id = t.gremium_id
			 WHERE t.datum >= %s AND t.datum < %s$ohne_sitzung AND (g.id IS NULL OR " . $sicht( 'g.oeffentlichkeit' ) . ')',
			$von . ' 00:00:00', gmdate( 'Y-m-d', strtotime( $bis . ' +1 day' ) ) . ' 00:00:00'
		) );
		foreach ( (array) $rows as $r ) {
			$zeit  = substr( $r->datum, 11, 5 );
			$ganzt = ( '' === $zeit || '00:00' === $zeit );
			$out[] = vp_kal_eintrag( 'termin', $r->titel, $ganzt ? substr( $r->datum, 0, 10 ) : substr( $r->datum, 0, 16 ), array(
				'id'       => 'termin-' . $r->id,
				'ganztags' => $ganzt,
				'ort'      => (string) $r->ort,
				'info'     => (string) $r->gremium,
			) );
		}
	}
	return $out;
}

/* ---- Eigene offene Aufgaben mit Fälligkeit (ProtokollPro) ---- */

function vp_kal_quelle_aufgaben( $von, $bis, $user_id ) {
	global $wpdb;
	$t = $wpdb->prefix . 'pp_aufgaben';
	if ( ! vp_kal_table_exists( $t ) ) {
		return array();
	}
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, titel, faelligkeitsdatum FROM $t
		 WHERE verantwortlich_user_id = %d AND status = 'offen'
		   AND faelligkeitsdatum BETWEEN %s AND %s",
		$user_id, $von, $bis
	) );
	$out = array();
	foreach ( (array) $rows as $r ) {
		$out[] = vp_kal_eintrag( 'aufgabe', sprintf( /* translators: %s = Aufgabe */ __( 'Fällig: %s', 'vereinsplugin' ), $r->titel ), $r->faelligkeitsdatum, array(
			'id'       => 'aufgabe-' . $r->id,
			'ganztags' => true,
			'url'      => user_can( $user_id, 'pp_manage' ) ? vp_kal_area_url( array( 'vp_tab' => 'aufgaben' ) ) : '',
			'mein'     => true,
		) );
	}
	return $out;
}

/* ---- Schichtpläne (Wunschliste) ---- */

function vp_kal_quelle_schichten( $von, $bis, $user_id ) {
	global $wpdb;
	$ev = $wpdb->prefix . 'wl_shift_events';
	$st = $wpdb->prefix . 'wl_shift_stationen';
	$sc = $wpdb->prefix . 'wl_shift_schichten';
	$ei = $wpdb->prefix . 'wl_shift_eintragungen';
	if ( ! vp_kal_table_exists( $ev ) || ! vp_kal_table_exists( $sc ) || ! vp_kal_table_exists( $ei ) ) {
		return array();
	}
	$vonz = $von . ' 00:00:00';
	$bisz = gmdate( 'Y-m-d', strtotime( $bis . ' +1 day' ) ) . ' 00:00:00';

	// Je Veranstaltung und Tag ein Eintrag mit Zeitspanne und freien Plätzen.
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT e.id, e.titel, e.slug, DATE(s.start_zeit) AS tag,
		        MIN(s.start_zeit) AS beginn, MAX(s.end_zeit) AS ende,
		        SUM(s.max_plaetze) AS plaetze,
		        SUM(COALESCE(c.n, 0)) AS belegt
		 FROM $sc s
		 JOIN $st st ON st.id = s.station_id
		 JOIN $ev e ON e.id = st.event_id
		 LEFT JOIN (SELECT schicht_id, COUNT(*) AS n FROM $ei GROUP BY schicht_id) c ON c.schicht_id = s.id
		 WHERE e.aktiv = 1 AND s.start_zeit >= %s AND s.start_zeit < %s
		 GROUP BY e.id, e.titel, e.slug, DATE(s.start_zeit)",
		$vonz, $bisz
	) );

	$out = array();
	foreach ( (array) $rows as $r ) {
		$frei  = max( 0, (int) $r->plaetze - (int) $r->belegt );
		$out[] = vp_kal_eintrag( 'schicht', sprintf( /* translators: %s = Veranstaltung */ __( 'Schichtplan: %s', 'vereinsplugin' ), $r->titel ), substr( $r->beginn, 0, 16 ), array(
			'id'   => 'schicht-' . $r->id . '-' . $r->tag,
			'ende' => $r->ende ? substr( $r->ende, 0, 16 ) : '',
			'info' => $frei
				? sprintf( /* translators: %d = count */ _n( 'Noch %d Platz frei – jetzt eintragen!', 'Noch %d Plätze frei – jetzt eintragen!', $frei, 'vereinsplugin' ), $frei )
				: __( 'Alle Schichten besetzt', 'vereinsplugin' ),
			'url'  => vp_kal_area_url( array( 'vp_tab' => 'schichtplaene', 'event' => $r->slug ) ),
		) );
	}

	// Eigene Schichten einzeln (mit Station/Treffpunkt).
	$mine = $wpdb->get_results( $wpdb->prepare(
		"SELECT x.id, s.titel AS schicht, s.start_zeit, s.end_zeit, st.titel AS station, st.treffpunkt, e.titel AS event, e.slug
		 FROM $ei x
		 JOIN $sc s ON s.id = x.schicht_id
		 JOIN $st st ON st.id = s.station_id
		 JOIN $ev e ON e.id = st.event_id
		 WHERE x.user_id = %d AND s.start_zeit >= %s AND s.start_zeit < %s",
		$user_id, $vonz, $bisz
	) );
	foreach ( (array) $mine as $m ) {
		$out[] = vp_kal_eintrag( 'schicht', sprintf( /* translators: %s = Station */ __( 'Meine Schicht: %s', 'vereinsplugin' ), $m->station . ( $m->schicht ? ' – ' . $m->schicht : '' ) ), substr( $m->start_zeit, 0, 16 ), array(
			'id'   => 'meine-schicht-' . $m->id,
			'ende' => $m->end_zeit ? substr( $m->end_zeit, 0, 16 ) : '',
			'ort'  => (string) $m->treffpunkt,
			'info' => $m->event,
			'url'  => vp_kal_area_url( array( 'vp_tab' => 'schichtplaene', 'event' => $m->slug ) ),
			'mein' => true,
		) );
	}
	return $out;
}

/* ---- Öffnungszeiten (wöchentlich) + Schließtage ---- */

function vp_kal_oeffnungszeiten() {
	return array_values( array_filter( (array) get_option( 'vp_kal_oeffnungszeiten', array() ), 'is_array' ) );
}

function vp_kal_schliesstage() {
	return array_values( array_filter( (array) get_option( 'vp_kal_schliesstage', array() ), 'is_array' ) );
}

function vp_kal_quelle_oeffnungszeiten( $von, $bis, $user_id ) {
	$zeiten = vp_kal_oeffnungszeiten();
	$zu     = vp_kal_schliesstage();
	$out    = array();

	$geschlossen = array(); // Y-m-d => Grund
	foreach ( $zu as $z ) {
		if ( empty( $z['von'] ) ) {
			continue;
		}
		$ende = ! empty( $z['bis'] ) && $z['bis'] >= $z['von'] ? $z['bis'] : $z['von'];
		for ( $d = $z['von']; $d <= $ende; $d = gmdate( 'Y-m-d', strtotime( $d . ' +1 day' ) ) ) {
			$geschlossen[ $d ] = (string) ( $z['grund'] ?? '' );
		}
		if ( $z['von'] <= $bis && $ende >= $von ) {
			$out[] = vp_kal_eintrag( 'oeffnung', $z['grund'] ? sprintf( /* translators: %s = Grund */ __( 'Geschlossen: %s', 'vereinsplugin' ), $z['grund'] ) : __( 'Geschlossen', 'vereinsplugin' ), $z['von'], array(
				'id'       => 'zu-' . $z['von'],
				'ende'     => $ende,
				'ganztags' => true,
			) );
		}
	}

	if ( ! $zeiten ) {
		return $out;
	}
	for ( $d = $von; $d <= $bis; $d = gmdate( 'Y-m-d', strtotime( $d . ' +1 day' ) ) ) {
		if ( isset( $geschlossen[ $d ] ) ) {
			continue;
		}
		$wt = (int) gmdate( 'N', strtotime( $d ) );
		foreach ( $zeiten as $i => $z ) {
			if ( (int) ( $z['tag'] ?? 0 ) !== $wt || empty( $z['von'] ) ) {
				continue;
			}
			$out[] = vp_kal_eintrag( 'oeffnung', $z['titel'] ?: __( 'Geöffnet', 'vereinsplugin' ), $d . ' ' . $z['von'], array(
				'id'   => 'oeffnung-' . $i . '-' . $d,
				'ende' => ! empty( $z['bis'] ) ? $d . ' ' . $z['bis'] : '',
				'ort'  => (string) ( $z['ort'] ?? '' ),
			) );
		}
	}
	return $out;
}

/* ---- Weitere (frei eingetragene) Termine ---- */

function vp_kal_eigene() {
	return array_filter( (array) get_option( 'vp_kal_eigene', array() ), 'is_array' );
}

function vp_kal_quelle_eigene( $von, $bis, $user_id ) {
	$vorstand = vp_kal_can_manage( $user_id );
	$out      = array();
	foreach ( vp_kal_eigene() as $id => $t ) {
		if ( 'vorstand' === ( $t['sichtbar'] ?? '' ) && ! $vorstand ) {
			continue;
		}
		$start = (string) ( $t['start'] ?? '' );
		$ende  = (string) ( $t['ende'] ?? '' );
		$tag_e = $ende ? substr( $ende, 0, 10 ) : substr( $start, 0, 10 );
		if ( ! $start || substr( $start, 0, 10 ) > $bis || $tag_e < $von ) {
			continue;
		}
		$out[] = vp_kal_eintrag( 'sonstiges', (string) $t['titel'], $start, array(
			'id'       => 'eigen-' . $id,
			'ende'     => $ende,
			'ganztags' => strlen( $start ) <= 10,
			'ort'      => (string) ( $t['ort'] ?? '' ),
			'info'     => (string) ( $t['info'] ?? '' ),
			'url'      => esc_url_raw( (string) ( $t['url'] ?? '' ) ),
		) );
	}
	return $out;
}

/* =========================================================================
 * Nextcloud: Kalender einbinden (lesend)
 * ====================================================================== */

function vp_kal_nc_kalender() {
	return array_filter( (array) get_option( 'vp_kal_nc_kalender', array() ), 'is_array' );
}

/**
 * Aus dem, was Leute typischerweise kopieren, eine abrufbare ICS-URL machen:
 *  - öffentlicher Freigabe-Link  …/apps/calendar/p/<token>  → public-calendars/<token>?export
 *  - CalDAV-Link                …/remote.php/dav/calendars/<user>/<kal>/ → ?export anhängen
 *  - webcal://                  → https://
 */
function vp_kal_nc_normalize_url( $url ) {
	$url = trim( (string) $url );
	$url = preg_replace( '#^webcals?://#i', 'https://', $url );
	if ( preg_match( '#^(https?://.+?)/(?:index\.php/)?apps/calendar/(?:p|embed)/([A-Za-z0-9]+)#', $url, $m ) ) {
		return $m[1] . '/remote.php/dav/public-calendars/' . $m[2] . '/?export';
	}
	if ( preg_match( '#/remote\.php/dav/(?:public-)?calendars/#', $url ) && false === strpos( $url, 'export' ) ) {
		$url = rtrim( $url, '/' ) . '/?export';
	}
	return $url;
}

/** Zugangsdaten nur an den eigenen Nextcloud-Host schicken – nie an Fremde. */
function vp_kal_nc_auth_header( $url ) {
	if ( ! function_exists( 'vp_nc_cfg' ) || ! vp_nc_ready() ) {
		return array();
	}
	$c = vp_nc_cfg();
	if ( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( $c['base'], PHP_URL_HOST ) ) ) {
		return array();
	}
	return array( 'Authorization' => 'Basic ' . base64_encode( $c['user'] . ':' . $c['pass'] ) );
}

/** ICS-Text eines Nextcloud-Kalenders, 15 Minuten gecacht. @return string|WP_Error */
function vp_kal_nc_fetch( $kal, $frisch = false ) {
	$url = vp_kal_nc_normalize_url( $kal['url'] ?? '' );
	if ( ! $url ) {
		return new WP_Error( 'vp_kal', __( 'Keine URL angegeben.', 'vereinsplugin' ) );
	}
	$key = 'vp_kal_nc_' . md5( $url );
	if ( ! $frisch ) {
		$c = get_transient( $key );
		if ( is_string( $c ) ) {
			return $c;
		}
	}
	$res = wp_remote_get( $url, array(
		'timeout' => 20,
		'headers' => array_merge( array( 'Accept' => 'text/calendar' ), vp_kal_nc_auth_header( $url ) ),
	) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = (string) wp_remote_retrieve_body( $res );
	if ( $code >= 400 || false === strpos( $body, 'BEGIN:VCALENDAR' ) ) {
		return new WP_Error( 'vp_kal', sprintf( /* translators: %d = HTTP-Status */ __( 'Nextcloud-Kalender nicht abrufbar (HTTP %d). Link und Freigabe prüfen.', 'vereinsplugin' ), $code ) );
	}
	set_transient( $key, $body, 15 * MINUTE_IN_SECONDS );
	return $body;
}

function vp_kal_quelle_nextcloud( $von, $bis, $user_id ) {
	$vorstand = vp_kal_can_manage( $user_id );
	$tz       = wp_timezone();
	$out      = array();
	foreach ( vp_kal_nc_kalender() as $id => $kal ) {
		if ( 'vorstand' === ( $kal['sichtbar'] ?? '' ) && ! $vorstand ) {
			continue;
		}
		$ics = vp_kal_nc_fetch( $kal );
		if ( is_wp_error( $ics ) ) {
			continue;
		}
		foreach ( vp_kal_ics_parse( $ics, $von, $bis, $tz ) as $ev ) {
			$out[] = vp_kal_eintrag( 'nextcloud', $ev['titel'], $ev['start'], array(
				'id'       => 'nc-' . $id . '-' . md5( $ev['uid'] . $ev['start'] ),
				'ende'     => $ev['ende'],
				'ganztags' => $ev['ganztags'],
				'ort'      => $ev['ort'],
				'info'     => trim( ( $kal['name'] ?? '' ) . ( $ev['info'] ? ' · ' . wp_trim_words( $ev['info'], 30 ) : '' ), ' ·' ),
				'url'      => $ev['url'],
				'farbe'    => (string) ( $kal['farbe'] ?? '' ),
			) );
		}
	}
	return $out;
}

/**
 * Kalender des Nextcloud-Admin-Kontos finden (CalDAV PROPFIND), damit man
 * sie nur noch anklicken muss. @return array[]|WP_Error [ ['name','url','farbe'] ]
 */
function vp_kal_nc_discover() {
	if ( ! function_exists( 'vp_nc_ready' ) || ! vp_nc_ready() ) {
		return new WP_Error( 'vp_kal', __( 'Nextcloud-Zugang ist nicht eingerichtet (Verein → Einstellungen → Nextcloud).', 'vereinsplugin' ) );
	}
	$cache = get_transient( 'vp_kal_nc_discover' );
	if ( is_array( $cache ) ) {
		return $cache;
	}
	$c    = vp_nc_cfg();
	$url  = $c['base'] . '/remote.php/dav/calendars/' . rawurlencode( $c['user'] ) . '/';
	$body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:a="http://apple.com/ns/ical/"><d:prop><d:displayname/><d:resourcetype/><a:calendar-color/></d:prop></d:propfind>';
	$res  = wp_remote_request( $url, array(
		'method'  => 'PROPFIND',
		'timeout' => 20,
		'headers' => array(
			'Depth'         => '1',
			'Content-Type'  => 'application/xml; charset=utf-8',
			'Authorization' => 'Basic ' . base64_encode( $c['user'] . ':' . $c['pass'] ),
		),
		'body'    => $body,
	) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( 207 !== $code ) {
		return new WP_Error( 'vp_kal', sprintf( /* translators: %d = HTTP-Status */ __( 'Nextcloud antwortet mit HTTP %d.', 'vereinsplugin' ), $code ) );
	}
	$dom = new DOMDocument();
	if ( ! @$dom->loadXML( wp_remote_retrieve_body( $res ), LIBXML_NONET ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return new WP_Error( 'vp_kal', __( 'Antwort von Nextcloud nicht lesbar.', 'vereinsplugin' ) );
	}
	$xp = new DOMXPath( $dom );
	$xp->registerNamespace( 'd', 'DAV:' );
	$xp->registerNamespace( 'c', 'urn:ietf:params:xml:ns:caldav' );
	$xp->registerNamespace( 'a', 'http://apple.com/ns/ical/' );

	$origin = preg_replace( '#^(https?://[^/]+).*$#', '$1', $c['base'] );
	$out    = array();
	foreach ( $xp->query( '//d:response' ) as $r ) {
		if ( ! $xp->query( './/d:resourcetype/c:calendar', $r )->length ) {
			continue;
		}
		$href  = trim( (string) $xp->evaluate( 'string(d:href)', $r ) );
		$name  = trim( (string) $xp->evaluate( 'string(.//d:displayname)', $r ) );
		$farbe = substr( trim( (string) $xp->evaluate( 'string(.//a:calendar-color)', $r ) ), 0, 7 );
		$out[] = array(
			'name'  => $name ?: basename( rtrim( $href, '/' ) ),
			'url'   => $origin . $href,
			'farbe' => preg_match( '/^#[0-9a-f]{6}$/i', $farbe ) ? $farbe : '',
		);
	}
	set_transient( 'vp_kal_nc_discover', $out, 10 * MINUTE_IN_SECONDS );
	return $out;
}

/* =========================================================================
 * ICS lesen (für Nextcloud & jeden anderen iCal-Kalender)
 * ====================================================================== */

function vp_kal_ics_unescape( $s ) {
	return strtr( $s, array( '\\n' => "\n", '\\N' => "\n", '\\,' => ',', '\\;' => ';', '\\\\' => '\\' ) );
}

/**
 * DTSTART/DTEND-Wert → DateTimeImmutable in $tz. @return array{0:?DateTimeImmutable,1:bool} [zeit, ganztags]
 */
function vp_kal_ics_zeit( $wert, $params, DateTimeZone $tz ) {
	$wert = trim( $wert );
	if ( preg_match( '/^\d{8}$/', $wert ) || false !== stripos( $params, 'VALUE=DATE;' ) || preg_match( '/VALUE=DATE$/i', $params ) ) {
		$d = DateTimeImmutable::createFromFormat( '!Ymd', substr( $wert, 0, 8 ), $tz );
		return array( $d ?: null, true );
	}
	$quelle = $tz;
	if ( 'Z' === substr( $wert, -1 ) ) {
		$quelle = new DateTimeZone( 'UTC' );
		$wert   = substr( $wert, 0, -1 );
	} elseif ( preg_match( '/TZID=("?)([^;:"]+)\1/i', $params, $m ) ) {
		try {
			$quelle = new DateTimeZone( $m[2] );
		} catch ( \Exception $e ) {
			$quelle = $tz; // Unbekannte (z. B. Outlook-)Zonennamen: lokal annehmen.
		}
	}
	$d = DateTimeImmutable::createFromFormat( '!Ymd\THis', substr( $wert, 0, 15 ), $quelle );
	return array( $d ? $d->setTimezone( $tz ) : null, false );
}

/**
 * VEVENTs aus einem ICS-Text im Zeitraum [$von, $bis] (Y-m-d). Wiederholungen
 * (RRULE: DAILY/WEEKLY/MONTHLY/YEARLY mit INTERVAL, COUNT, UNTIL, BYDAY,
 * BYMONTHDAY), EXDATE und geänderte Einzeltermine (RECURRENCE-ID) werden
 * aufgelöst. Ohne WordPress-Abhängigkeiten, damit es sich isoliert testen lässt.
 *
 * @return array[] ['uid','titel','start','ende','ganztags','ort','info','url']
 */
function vp_kal_ics_parse( $ics, $von, $bis, DateTimeZone $tz ) {
	$ics   = preg_replace( "/\r?\n[ \t]/", '', str_replace( "\r\n", "\n", (string) $ics ) );
	$lines = explode( "\n", $ics );

	$events = array();
	$cur    = null;
	$tiefe  = 0; // verschachtelte Blöcke (VALARM) im VEVENT überspringen
	foreach ( $lines as $line ) {
		$line = rtrim( $line, "\r" );
		if ( 'BEGIN:VEVENT' === $line ) {
			$cur   = array( 'EXDATE' => array() );
			$tiefe = 0;
			continue;
		}
		if ( null === $cur ) {
			continue;
		}
		if ( 'END:VEVENT' === $line ) {
			$events[] = $cur;
			$cur      = null;
			continue;
		}
		if ( 0 === strpos( $line, 'BEGIN:' ) ) {
			$tiefe++;
			continue;
		}
		if ( 0 === strpos( $line, 'END:' ) ) {
			$tiefe = max( 0, $tiefe - 1 );
			continue;
		}
		if ( $tiefe || ! preg_match( '/^([A-Z0-9-]+)((?:;[^:]*)?):(.*)$/', $line, $m ) ) {
			continue;
		}
		list( , $name, $params, $wert ) = $m;
		if ( 'EXDATE' === $name ) {
			foreach ( explode( ',', $wert ) as $x ) {
				$cur['EXDATE'][] = array( $x, $params );
			}
		} else {
			$cur[ $name ] = array( $wert, $params );
		}
	}

	$von_d = new DateTimeImmutable( $von . ' 00:00:00', $tz );
	$bis_d = new DateTimeImmutable( $bis . ' 23:59:59', $tz );

	// Geänderte Einzeltermine einer Serie: uid|ursprünglicher Start → ersetzt.
	$ersetzt = array();
	foreach ( $events as $e ) {
		if ( isset( $e['RECURRENCE-ID'], $e['UID'] ) ) {
			list( $rid ) = vp_kal_ics_zeit( $e['RECURRENCE-ID'][0], $e['RECURRENCE-ID'][1], $tz );
			if ( $rid ) {
				$ersetzt[ $e['UID'][0] . '|' . $rid->format( 'Y-m-d H:i' ) ] = true;
			}
		}
	}

	$out = array();
	foreach ( $events as $e ) {
		if ( empty( $e['DTSTART'] ) || ( isset( $e['STATUS'] ) && 'CANCELLED' === strtoupper( $e['STATUS'][0] ) ) ) {
			continue;
		}
		list( $start, $ganztags ) = vp_kal_ics_zeit( $e['DTSTART'][0], $e['DTSTART'][1], $tz );
		if ( ! $start ) {
			continue;
		}
		$dauer = 0;
		if ( ! empty( $e['DTEND'] ) ) {
			list( $ende ) = vp_kal_ics_zeit( $e['DTEND'][0], $e['DTEND'][1], $tz );
			$dauer = $ende ? max( 0, $ende->getTimestamp() - $start->getTimestamp() ) : 0;
		} elseif ( ! empty( $e['DURATION'] ) ) {
			try {
				$iv    = new DateInterval( ltrim( $e['DURATION'][0], '+' ) );
				$dauer = $start->add( $iv )->getTimestamp() - $start->getTimestamp();
			} catch ( \Exception $ex ) {
				$dauer = 0;
			}
		} elseif ( $ganztags ) {
			$dauer = 86400;
		}

		$uid  = isset( $e['UID'] ) ? $e['UID'][0] : md5( serialize( $e ) );
		$ex   = array();
		foreach ( $e['EXDATE'] as $x ) {
			list( $xd ) = vp_kal_ics_zeit( $x[0], $x[1], $tz );
			if ( $xd ) {
				$ex[ $xd->format( 'Y-m-d H:i' ) ] = true;
			}
		}

		$basis = array(
			'uid'   => $uid,
			'titel' => isset( $e['SUMMARY'] ) ? vp_kal_ics_unescape( $e['SUMMARY'][0] ) : '(ohne Titel)',
			'ort'   => isset( $e['LOCATION'] ) ? vp_kal_ics_unescape( $e['LOCATION'][0] ) : '',
			'info'  => isset( $e['DESCRIPTION'] ) ? trim( vp_kal_ics_unescape( $e['DESCRIPTION'][0] ) ) : '',
			'url'   => isset( $e['URL'] ) && preg_match( '#^https?://#i', $e['URL'][0] ) ? $e['URL'][0] : '',
		);

		$starts = isset( $e['RRULE'] ) && ! isset( $e['RECURRENCE-ID'] )
			? vp_kal_ics_rrule( $start, $e['RRULE'][0], $bis_d, $tz )
			: array( $start );

		foreach ( $starts as $s ) {
			$key = $s->format( 'Y-m-d H:i' );
			if ( isset( $ex[ $key ] ) || ( isset( $e['RRULE'] ) && isset( $ersetzt[ $uid . '|' . $key ] ) ) ) {
				continue;
			}
			$en = $s->modify( '+' . $dauer . ' seconds' );
			// Überschneidung mit dem Zeitraum (Ende exklusiv).
			if ( $s > $bis_d || ( $dauer ? $en <= $von_d : $s < $von_d ) ) {
				continue;
			}
			if ( $ganztags ) {
				$letzter = $dauer > 86400 ? $en->modify( '-1 day' )->format( 'Y-m-d' ) : '';
				$out[]   = array_merge( $basis, array( 'start' => $s->format( 'Y-m-d' ), 'ende' => $letzter, 'ganztags' => true ) );
			} else {
				$out[] = array_merge( $basis, array( 'start' => $s->format( 'Y-m-d H:i' ), 'ende' => $dauer ? $en->format( 'Y-m-d H:i' ) : '', 'ganztags' => false ) );
			}
		}
	}
	return $out;
}

/**
 * Startzeitpunkte einer RRULE bis $bis (höchstens 1000).
 * @return DateTimeImmutable[]
 */
function vp_kal_ics_rrule( DateTimeImmutable $start, $rrule, DateTimeImmutable $bis, DateTimeZone $tz ) {
	$r = array();
	foreach ( explode( ';', $rrule ) as $teil ) {
		$kv = explode( '=', $teil, 2 );
		if ( 2 === count( $kv ) ) {
			$r[ strtoupper( $kv[0] ) ] = $kv[1];
		}
	}
	$freq  = $r['FREQ'] ?? '';
	$iv    = max( 1, (int) ( $r['INTERVAL'] ?? 1 ) );
	$count = isset( $r['COUNT'] ) ? (int) $r['COUNT'] : 0;
	$until = null;
	if ( ! empty( $r['UNTIL'] ) ) {
		list( $until, $u_ganz ) = vp_kal_ics_zeit( $r['UNTIL'], '', $tz );
		if ( $until && $u_ganz ) {
			$until = $until->setTime( 23, 59, 59 );
		}
	}
	$ende   = ( $until && $until < $bis ) ? $until : $bis;
	$wtage  = array( 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7 );
	$byday  = array();
	foreach ( array_filter( explode( ',', $r['BYDAY'] ?? '' ) ) as $bd ) {
		if ( preg_match( '/^([+-]?\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', strtoupper( $bd ), $m ) ) {
			$byday[] = array( (int) $m[1], $wtage[ $m[2] ] );
		}
	}
	$bymd = array_map( 'intval', array_filter( explode( ',', $r['BYMONTHDAY'] ?? '' ) ) );
	$zeit = $start->format( 'H:i:s' );

	$out = array();
	$n   = 0; // gezählte Vorkommen (für COUNT – zählt auch Termine vor dem Zeitraum)
	$add = function ( DateTimeImmutable $d ) use ( &$out, &$n, $start, $ende, $count ) {
		if ( $d < $start ) {
			return true;
		}
		if ( $d > $ende || ( $count && $n >= $count ) ) {
			return false;
		}
		$n++;
		$out[] = $d;
		return true;
	};

	$sicher = 0;
	switch ( $freq ) {
		case 'DAILY':
			for ( $d = $start; $sicher++ < 20000; $d = $d->modify( "+$iv day" ) ) {
				if ( $byday && ! in_array( (int) $d->format( 'N' ), array_column( $byday, 1 ), true ) ) {
					if ( $d > $ende ) {
						break;
					}
					continue;
				}
				if ( ! $add( $d ) ) {
					break;
				}
			}
			break;

		case 'WEEKLY':
			$tage = $byday ? array_unique( array_column( $byday, 1 ) ) : array( (int) $start->format( 'N' ) );
			sort( $tage );
			$montag = $start->modify( '-' . ( (int) $start->format( 'N' ) - 1 ) . ' days' );
			for ( $w = $montag; $sicher++ < 2000; $w = $w->modify( "+$iv week" ) ) {
				foreach ( $tage as $t ) {
					if ( ! $add( $w->modify( '+' . ( $t - 1 ) . ' days' ) ) ) {
						break 3;
					}
				}
			}
			break;

		case 'MONTHLY':
		case 'YEARLY':
			$schritt = 'MONTHLY' === $freq ? "+$iv month" : "+$iv year";
			for ( $m = $start->modify( 'first day of this month' ); $sicher++ < 1200; $m = $m->modify( $schritt ) ) {
				if ( 'YEARLY' === $freq && ! $byday && ! $bymd ) {
					$m = $m->setDate( (int) $m->format( 'Y' ), (int) $start->format( 'n' ), 1 );
				}
				$kandidaten = array();
				$tage_im_m  = (int) $m->format( 't' );
				if ( $byday ) {
					foreach ( $byday as $bd ) {
						list( $nr, $wt ) = $bd;
						$treffer = array();
						for ( $i = 1; $i <= $tage_im_m; $i++ ) {
							if ( (int) $m->setDate( (int) $m->format( 'Y' ), (int) $m->format( 'n' ), $i )->format( 'N' ) === $wt ) {
								$treffer[] = $i;
							}
						}
						if ( 0 === $nr ) {
							$kandidaten = array_merge( $kandidaten, $treffer );
						} elseif ( $nr > 0 && isset( $treffer[ $nr - 1 ] ) ) {
							$kandidaten[] = $treffer[ $nr - 1 ];
						} elseif ( $nr < 0 && isset( $treffer[ count( $treffer ) + $nr ] ) ) {
							$kandidaten[] = $treffer[ count( $treffer ) + $nr ];
						}
					}
				} elseif ( $bymd ) {
					foreach ( $bymd as $md ) {
						$kandidaten[] = $md > 0 ? $md : $tage_im_m + $md + 1;
					}
				} else {
					$kandidaten[] = (int) $start->format( 'j' );
				}
				$kandidaten = array_unique( array_filter( $kandidaten, function ( $k ) use ( $tage_im_m ) { return $k >= 1 && $k <= $tage_im_m; } ) );
				sort( $kandidaten );
				foreach ( $kandidaten as $k ) {
					$d = new DateTimeImmutable( $m->format( 'Y-m-' ) . sprintf( '%02d', $k ) . ' ' . $zeit, $tz );
					if ( ! $add( $d ) ) {
						break 2;
					}
				}
				if ( $m > $ende ) {
					break;
				}
			}
			break;

		default:
			$out[] = $start;
	}
	return $out;
}

/* =========================================================================
 * ICS-Abo (Export) – z. B. in Nextcloud „Neues Abonnement aus Link“
 * ====================================================================== */

function vp_kal_user_token( $user_id, $neu = false ) {
	$t = get_user_meta( $user_id, 'vp_kal_token', true );
	if ( ! $t || $neu ) {
		$t = wp_generate_password( 32, false, false );
		update_user_meta( $user_id, 'vp_kal_token', $t );
	}
	return $t;
}

function vp_kal_feed_url( $user_id ) {
	return add_query_arg( 'vp_kalender_ics', vp_kal_user_token( $user_id ), home_url( '/' ) );
}

add_action( 'init', 'vp_kal_feed_ausliefern', 20 );
function vp_kal_feed_ausliefern() {
	if ( empty( $_GET['vp_kalender_ics'] ) ) {
		return;
	}
	$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_GET['vp_kalender_ics'] ) );
	$users = strlen( $token ) >= 20 ? get_users( array(
		'meta_key'   => 'vp_kal_token',
		'meta_value' => $token,
		'number'     => 1,
		'fields'     => 'ID',
	) ) : array();
	if ( ! $users ) {
		status_header( 404 );
		exit;
	}
	$user_id = (int) $users[0];
	$user    = get_userdata( $user_id );
	if ( ! $user || array_intersect( (array) $user->roles, array( 'vp_ehemalig', 'vp_antrag_offen' ) ) ) {
		status_header( 403 );
		exit;
	}

	$tz  = wp_timezone();
	$von = ( new DateTimeImmutable( 'now', $tz ) )->modify( '-3 months' )->format( 'Y-m-d' );
	$bis = ( new DateTimeImmutable( 'now', $tz ) )->modify( '+12 months' )->format( 'Y-m-d' );

	// Nextcloud-Kalender nicht zurückspiegeln (sonst doppelt im eigenen Nextcloud).
	$termine = vp_kal_termine( $von, $bis, $user_id, array( 'ohne' => array( 'nextcloud' ) ) );

	nocache_headers();
	header( 'Content-Type: text/calendar; charset=utf-8' );
	header( 'Content-Disposition: inline; filename="vereinskalender.ics"' );
	echo vp_kal_ics_bauen( $termine ); // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}

function vp_kal_ics_text( $s ) {
	$s = str_replace( array( '\\', ';', ',' ), array( '\\\\', '\\;', '\\,' ), (string) $s );
	return str_replace( array( "\r\n", "\n", "\r" ), '\\n', $s );
}

/** Zeile nach RFC 5545 auf 75 Oktette falten (UTF-8-sicher). */
function vp_kal_ics_falten( $zeile ) {
	if ( ! function_exists( 'mb_strcut' ) ) {
		return $zeile;
	}
	$out = '';
	while ( strlen( $zeile ) > 75 ) {
		$teil = mb_strcut( $zeile, 0, 74, 'UTF-8' );
		$out .= $teil . "\r\n ";
		$zeile = substr( $zeile, strlen( $teil ) );
	}
	return $out . $zeile;
}

function vp_kal_ics_bauen( array $termine ) {
	$tz   = wp_timezone();
	$utc  = new DateTimeZone( 'UTC' );
	$host = wp_parse_url( home_url(), PHP_URL_HOST );
	$now  = gmdate( 'Ymd\THis\Z' );
	$name = get_option( 'vp_app_name', get_bloginfo( 'name' ) );
	$kats = vp_kal_kategorien();

	$l = array(
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//' . vp_kal_ics_text( $name ) . '//Vereinskalender//DE',
		'CALSCALE:GREGORIAN',
		'METHOD:PUBLISH',
		'X-WR-CALNAME:' . vp_kal_ics_text( $name ),
		'X-WR-TIMEZONE:' . $tz->getName(),
		'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
		'X-PUBLISHED-TTL:PT1H',
	);
	foreach ( $termine as $t ) {
		$l[] = 'BEGIN:VEVENT';
		$l[] = 'UID:' . vp_kal_ics_text( $t['id'] ) . '@' . $host;
		$l[] = 'DTSTAMP:' . $now;
		if ( $t['ganztags'] ) {
			$s   = new DateTimeImmutable( substr( $t['start'], 0, 10 ), $tz );
			$e   = new DateTimeImmutable( substr( $t['ende'] ?: $t['start'], 0, 10 ), $tz );
			$l[] = 'DTSTART;VALUE=DATE:' . $s->format( 'Ymd' );
			$l[] = 'DTEND;VALUE=DATE:' . $e->modify( '+1 day' )->format( 'Ymd' );
		} else {
			$s   = new DateTimeImmutable( $t['start'], $tz );
			$e   = $t['ende'] ? new DateTimeImmutable( $t['ende'], $tz ) : $s->modify( '+1 hour' );
			$l[] = 'DTSTART:' . $s->setTimezone( $utc )->format( 'Ymd\THis\Z' );
			$l[] = 'DTEND:' . $e->setTimezone( $utc )->format( 'Ymd\THis\Z' );
		}
		$l[] = 'SUMMARY:' . vp_kal_ics_text( $t['titel'] );
		if ( $t['ort'] ) {
			$l[] = 'LOCATION:' . vp_kal_ics_text( $t['ort'] );
		}
		if ( $t['info'] ) {
			$l[] = 'DESCRIPTION:' . vp_kal_ics_text( $t['info'] );
		}
		if ( $t['url'] ) {
			$l[] = 'URL:' . $t['url'];
		}
		$l[] = 'CATEGORIES:' . vp_kal_ics_text( $kats[ $t['kat'] ]['label'] ?? $t['kat'] );
		$l[] = 'END:VEVENT';
	}
	$l[] = 'END:VCALENDAR';
	return implode( "\r\n", array_map( 'vp_kal_ics_falten', $l ) ) . "\r\n";
}

/* =========================================================================
 * Mitgliederbereich: Sektion „Kalender“
 * ====================================================================== */

function vp_kal_url( $args = array() ) {
	$base = get_permalink() ?: remove_query_arg( array( 'vp_kal', 'vp_kal_m' ) );
	return add_query_arg( array_merge( array( 'vp_tab' => 'kalender' ), $args ), $base );
}

function vp_render_kalender_section() {
	$tz     = wp_timezone();
	$heute  = new DateTimeImmutable( 'now', $tz );
	$ansicht = isset( $_GET['vp_kal'] ) ? sanitize_key( wp_unslash( $_GET['vp_kal'] ) ) : 'monat';
	// Alte Links auf ProtokollPro „Termine“ / „Kalender-Sync“ landen hier.
	if ( ! isset( $_GET['vp_kal'] ) && isset( $_GET['pp_view'] ) ) {
		$pv      = sanitize_key( wp_unslash( $_GET['pp_view'] ) );
		$ansicht = 'kalender' === $pv ? 'abo' : ( 'termine' === $pv && vp_kal_can_manage() ? 'verwalten' : $ansicht );
	}
	if ( ! in_array( $ansicht, array( 'monat', 'liste', 'abo', 'verwalten' ), true ) || ( 'verwalten' === $ansicht && ! vp_kal_can_manage() ) ) {
		$ansicht = 'monat';
	}
	$monat = isset( $_GET['vp_kal_m'] ) && preg_match( '/^\d{4}-\d{2}$/', (string) $_GET['vp_kal_m'] ) ? (string) $_GET['vp_kal_m'] : $heute->format( 'Y-m' );
	$m1    = DateTimeImmutable::createFromFormat( '!Y-m-d', $monat . '-01', $tz ) ?: $heute->modify( 'first day of this month' );

	ob_start();
	echo '<div class="vp-kal" id="vp-kal">';
	echo '<h2>' . esc_html__( 'Kalender', 'vereinsplugin' ) . '</h2>';

	// Rückmeldungen nach „Set anwenden“ u. Ä. (ProtokollPro leitet mit ?pp_… zurück).
	if ( isset( $_GET['pp_set_erzeugt'] ) ) {
		echo '<div class="vp-note">' . esc_html( sprintf( /* translators: %d = count */ __( '%d Aufgabe(n) aus dem Set erzeugt.', 'vereinsplugin' ), (int) $_GET['pp_set_erzeugt'] ) );
		if ( ! empty( $_GET['pp_set_uebersprungen'] ) ) {
			echo ' ' . esc_html( sprintf( /* translators: %d = count */ __( '%d übersprungen, weil sie für diesen Termin bereits existieren.', 'vereinsplugin' ), (int) $_GET['pp_set_uebersprungen'] ) );
		}
		echo '</div>';
	}
	if ( isset( $_GET['pp_error'] ) ) {
		echo '<div class="vp-note vp-note-error">' . esc_html( str_replace( '+', ' ', sanitize_text_field( wp_unslash( $_GET['pp_error'] ) ) ) ) . '</div>';
	}

	$tabs = array(
		'monat' => __( 'Monat', 'vereinsplugin' ),
		'liste' => __( 'Liste', 'vereinsplugin' ),
		'abo'   => __( 'Abonnieren / Nextcloud', 'vereinsplugin' ),
	);
	if ( vp_kal_can_manage() ) {
		$tabs['verwalten'] = __( 'Verwalten', 'vereinsplugin' );
	}
	echo '<nav class="vp-subnav">';
	foreach ( $tabs as $k => $label ) {
		$args = array( 'vp_kal' => $k );
		if ( in_array( $k, array( 'monat', 'liste' ), true ) ) {
			$args['vp_kal_m'] = $m1->format( 'Y-m' );
		}
		printf( '<a class="%s" href="%s">%s</a>', $k === $ansicht ? 'is-active' : '', esc_url( vp_kal_url( $args ) ), esc_html( $label ) );
	}
	echo '</nav>';

	if ( 'abo' === $ansicht ) {
		echo vp_kal_render_abo(); // phpcs:ignore WordPress.Security.EscapeOutput
	} elseif ( 'verwalten' === $ansicht ) {
		echo vp_kal_render_verwalten(); // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		echo vp_kal_render_kalender( $m1, $ansicht, $heute ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo '</div>';
	return ob_get_clean();
}

function vp_kal_monatsname( DateTimeImmutable $d ) {
	$namen = array( 1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember' );
	return $namen[ (int) $d->format( 'n' ) ] . ' ' . $d->format( 'Y' );
}

/** Termine je Tag (mehrtägige Termine erscheinen an jedem Tag). */
function vp_kal_nach_tagen( array $termine, $von, $bis ) {
	$tage = array();
	foreach ( $termine as $t ) {
		$s = substr( $t['start'], 0, 10 );
		$e = $t['ende'] ? substr( $t['ende'], 0, 10 ) : $s;
		if ( $e < $s ) {
			$e = $s;
		}
		$d = max( $s, $von );
		for ( $i = 0; $d <= $e && $d <= $bis && $i < 62; $i++, $d = gmdate( 'Y-m-d', strtotime( $d . ' +1 day' ) ) ) {
			$tage[ $d ][] = $t + array( 'folgetag' => $d !== $s );
		}
	}
	return $tage;
}

function vp_kal_zeit_text( $t, $mit_ende = true ) {
	if ( $t['ganztags'] || ! empty( $t['folgetag'] ) ) {
		return '';
	}
	$z = substr( $t['start'], 11, 5 );
	if ( $mit_ende && $t['ende'] && substr( $t['ende'], 0, 10 ) === substr( $t['start'], 0, 10 ) ) {
		$z .= '–' . substr( $t['ende'], 11, 5 );
	}
	return $z;
}

function vp_kal_render_kalender( DateTimeImmutable $m1, $ansicht, DateTimeImmutable $heute ) {
	$kats   = vp_kal_kategorien();
	$m_ende = $m1->modify( 'last day of this month' );
	// Monatsraster: von Montag der ersten bis Sonntag der letzten Woche.
	$g_von  = $m1->modify( '-' . ( (int) $m1->format( 'N' ) - 1 ) . ' days' );
	$g_bis  = $m_ende->modify( '+' . ( 7 - (int) $m_ende->format( 'N' ) ) . ' days' );
	$von    = 'monat' === $ansicht ? $g_von->format( 'Y-m-d' ) : $m1->format( 'Y-m-d' );
	$bis    = 'monat' === $ansicht ? $g_bis->format( 'Y-m-d' ) : $m_ende->format( 'Y-m-d' );

	$termine = vp_kal_termine( $von, $bis );
	$tage    = vp_kal_nach_tagen( $termine, $von, $bis );
	$vorh    = array_unique( array_column( $termine, 'kat' ) );

	ob_start();

	// Kopf: Monat blättern.
	echo '<div class="vp-kal-kopf">';
	printf( '<a class="vp-btn" href="%s" aria-label="%s">‹</a>', esc_url( vp_kal_url( array( 'vp_kal' => $ansicht, 'vp_kal_m' => $m1->modify( '-1 month' )->format( 'Y-m' ) ) ) ), esc_attr__( 'Vorheriger Monat', 'vereinsplugin' ) );
	echo '<strong class="vp-kal-monat">' . esc_html( vp_kal_monatsname( $m1 ) ) . '</strong>';
	printf( '<a class="vp-btn" href="%s" aria-label="%s">›</a>', esc_url( vp_kal_url( array( 'vp_kal' => $ansicht, 'vp_kal_m' => $m1->modify( '+1 month' )->format( 'Y-m' ) ) ) ), esc_attr__( 'Nächster Monat', 'vereinsplugin' ) );
	if ( $m1->format( 'Y-m' ) !== $heute->format( 'Y-m' ) ) {
		printf( '<a class="vp-btn" href="%s">%s</a>', esc_url( vp_kal_url( array( 'vp_kal' => $ansicht ) ) ), esc_html__( 'Heute', 'vereinsplugin' ) );
	}
	echo '</div>';

	// Filter (nur Kategorien, die es in diesem Zeitraum gibt).
	echo '<div class="vp-kal-filter">';
	foreach ( $kats as $k => $kat ) {
		if ( ! in_array( $k, $vorh, true ) ) {
			continue;
		}
		printf(
			'<label class="vp-kal-chip" style="--kal-farbe:%s"><input type="checkbox" checked data-kal-kat="%s"> %s</label>',
			esc_attr( $kat['farbe'] ),
			esc_attr( $k ),
			esc_html( $kat['label'] )
		);
	}
	echo '</div>';

	if ( ! $termine ) {
		echo '<div class="vp-note">' . esc_html__( 'In diesem Monat stehen keine Termine an.', 'vereinsplugin' );
		if ( vp_kal_can_manage() ) {
			printf( ' <a href="%s">%s</a>', esc_url( vp_kal_url( array( 'vp_kal' => 'verwalten' ) ) ), esc_html__( 'Öffnungszeiten, Termine oder Nextcloud-Kalender hinzufügen', 'vereinsplugin' ) );
		}
		echo '</div>';
	}

	$heute_s = $heute->format( 'Y-m-d' );

	if ( 'monat' === $ansicht ) {
		echo '<div class="vp-kal-grid" role="grid">';
		foreach ( vp_kal_wochentage() as $wt ) {
			echo '<div class="vp-kal-wt" role="columnheader">' . esc_html( $wt ) . '</div>';
		}
		for ( $d = $g_von; $d <= $g_bis; $d = $d->modify( '+1 day' ) ) {
			$ds   = $d->format( 'Y-m-d' );
			$kl   = array( 'vp-kal-tag' );
			if ( $d->format( 'm' ) !== $m1->format( 'm' ) ) {
				$kl[] = 'is-fremd';
			}
			if ( $ds === $heute_s ) {
				$kl[] = 'is-heute';
			}
			if ( $ds < $heute_s ) {
				$kl[] = 'is-vergangen';
			}
			echo '<div class="' . esc_attr( implode( ' ', $kl ) ) . '" role="gridcell"><div class="vp-kal-nr">' . esc_html( $d->format( 'j' ) ) . '</div>';
			foreach ( $tage[ $ds ] ?? array() as $t ) {
				echo vp_kal_render_chip( $t, $kats ); // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</div>';
		}
		echo '</div>';
	}

	// Liste: in der Listenansicht immer, in der Monatsansicht nur auf schmalen
	// Bildschirmen (dort ersetzt sie das Raster, per CSS).
	echo '<div class="vp-kal-liste' . ( 'monat' === $ansicht ? ' vp-kal-liste-mobil' : '' ) . '">';
	$wt = vp_kal_wochentage();
	foreach ( $tage as $ds => $liste ) {
		if ( $ds < $m1->format( 'Y-m-d' ) || $ds > $m_ende->format( 'Y-m-d' ) ) {
			continue;
		}
		$d = new DateTimeImmutable( $ds, wp_timezone() );
		printf(
			'<section class="vp-kal-listtag%s%s"><h3>%s, %s</h3>',
			$ds === $heute_s ? ' is-heute' : '',
			$ds < $heute_s ? ' is-vergangen' : '',
			esc_html( $wt[ (int) $d->format( 'N' ) ] ),
			esc_html( $d->format( 'd.m.Y' ) )
		);
		foreach ( $liste as $t ) {
			echo vp_kal_render_zeile( $t, $kats ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</section>';
	}
	echo '</div>';

	?>
	<script>
	(function(){
		var root = document.getElementById('vp-kal');
		if (!root) return;
		function apply(){
			root.querySelectorAll('[data-kal-kat]').forEach(function(cb){
				root.classList.toggle('vp-kal-ohne-' + cb.getAttribute('data-kal-kat'), !cb.checked);
			});
		}
		root.querySelectorAll('input[data-kal-kat]').forEach(function(cb){
			try { if (localStorage.getItem('vp_kal_aus_' + cb.getAttribute('data-kal-kat')) === '1') cb.checked = false; } catch(e) {}
			cb.addEventListener('change', function(){
				try { localStorage.setItem('vp_kal_aus_' + cb.getAttribute('data-kal-kat'), cb.checked ? '0' : '1'); } catch(e) {}
				apply();
			});
		});
		apply();
	})();
	</script>
	<?php
	return ob_get_clean();
}

function vp_kal_farbe( $t, $kats ) {
	return $t['farbe'] ?: ( $kats[ $t['kat'] ]['farbe'] ?? '#6b7280' );
}

function vp_kal_render_chip( $t, $kats ) {
	$zeit  = vp_kal_zeit_text( $t, false );
	$tip   = trim( vp_kal_zeit_text( $t ) . ' ' . $t['titel'] . ( $t['ort'] ? ' · ' . $t['ort'] : '' ) . ( $t['info'] ? "\n" . $t['info'] : '' ) );
	$inner = ( $zeit ? '<span class="vp-kal-zeit">' . esc_html( $zeit ) . '</span> ' : '' ) . esc_html( $t['titel'] );
	$attr  = sprintf(
		'class="vp-kal-ev vp-kal-kat-%s%s%s" style="--kal-farbe:%s" title="%s"',
		esc_attr( $t['kat'] ),
		$t['ganztags'] || ! empty( $t['folgetag'] ) ? ' is-ganztags' : '',
		$t['mein'] ? ' is-mein' : '',
		esc_attr( vp_kal_farbe( $t, $kats ) ),
		esc_attr( $tip )
	);
	return $t['url']
		? '<a ' . $attr . ' href="' . esc_url( $t['url'] ) . '">' . $inner . '</a>'
		: '<div ' . $attr . '>' . $inner . '</div>';
}

function vp_kal_render_zeile( $t, $kats ) {
	$zeit = vp_kal_zeit_text( $t );
	$kat  = $kats[ $t['kat'] ]['label'] ?? '';
	$html = sprintf(
		'<div class="vp-kal-zeile vp-kal-kat-%s%s" style="--kal-farbe:%s"><div class="vp-kal-zeile-zeit">%s</div><div class="vp-kal-zeile-text"><strong>%s</strong>',
		esc_attr( $t['kat'] ),
		$t['mein'] ? ' is-mein' : '',
		esc_attr( vp_kal_farbe( $t, $kats ) ),
		$zeit ? esc_html( $zeit ) : esc_html__( 'ganztägig', 'vereinsplugin' ),
		$t['url'] ? '<a href="' . esc_url( $t['url'] ) . '">' . esc_html( $t['titel'] ) . '</a>' : esc_html( $t['titel'] )
	);
	$meta = array_filter( array( $kat, $t['ort'] ? '📍 ' . $t['ort'] : '' ) );
	$html .= '<div class="vp-kal-zeile-meta">' . esc_html( implode( ' · ', $meta ) );
	// Termin als TOP vorschlagen (Themenspeicher).
	if ( function_exists( 'vp_thema_link' ) && vp_thema_verfuegbar() && vp_thema_darf() && 'oeffnung' !== $t['kat'] ) {
		$wann  = wp_date( 'd.m.Y', strtotime( substr( $t['start'], 0, 10 ) . ' 12:00' ) ) . ( vp_kal_zeit_text( $t, false ) ? ' ' . vp_kal_zeit_text( $t, false ) : '' );
		$html .= ' · <a class="vp-kal-thema" href="' . esc_url( vp_thema_link( sprintf( /* translators: 1: Termin, 2: Datum */ __( 'Termin „%1$s“ (%2$s): ', 'vereinsplugin' ), $t['titel'], $wann ), trim( $t['ort'] . "\n" . $t['info'] ), 0, vp_kal_url( array( 'vp_kal' => 'liste', 'vp_kal_m' => substr( $t['start'], 0, 7 ) ) ) ) ) . '" title="' . esc_attr__( 'Als TOP vorschlagen', 'vereinsplugin' ) . '">📌 ' . esc_html__( 'als TOP', 'vereinsplugin' ) . '</a>';
	}
	$html .= '</div>';
	if ( $t['info'] ) {
		$html .= '<div class="vp-kal-zeile-info">' . nl2br( esc_html( $t['info'] ) ) . '</div>';
	}
	return $html . '</div></div>';
}

/* ---- Abonnieren (ICS / Nextcloud) ---- */

function vp_kal_render_abo() {
	$uid = get_current_user_id();
	$msg = '';
	if ( isset( $_POST['vp_kal_token_neu'] ) && check_admin_referer( 'vp_kal_abo', 'vp_kal_nonce' ) ) {
		vp_kal_user_token( $uid, true );
		$msg = __( 'Neuer Link erzeugt. Der alte funktioniert nicht mehr – bitte im Kalender ersetzen.', 'vereinsplugin' );
	}
	$url    = vp_kal_feed_url( $uid );
	$webcal = preg_replace( '#^https?://#', 'webcal://', $url );

	ob_start();
	if ( $msg ) {
		echo '<div class="vp-note">' . esc_html( $msg ) . '</div>';
	}
	echo '<div class="vp-card">';
	echo '<h3>' . esc_html__( 'Kalender abonnieren', 'vereinsplugin' ) . '</h3>';
	echo '<p>' . esc_html__( 'Mit diesem persönlichen Link erscheinen alle Vereinstermine, die du hier siehst – inklusive deiner eigenen Schichten und der Fristen deiner offenen Aufgaben – automatisch in deinem Kalender und bleiben aktuell.', 'vereinsplugin' ) . '</p>';
	printf(
		'<p><input type="text" readonly class="vp-kal-abo-url" value="%s" onclick="this.select()" style="width:100%%"></p><p><button type="button" class="vp-btn vp-btn-primary" data-kal-copy>%s</button> <a class="vp-btn" href="%s">%s</a></p>',
		esc_attr( $url ),
		esc_html__( 'Link kopieren', 'vereinsplugin' ),
		esc_url( $webcal, array( 'webcal', 'https', 'http' ) ),
		esc_html__( 'In Kalender-App öffnen', 'vereinsplugin' )
	);
	echo '<p class="vp-muted">' . esc_html__( 'Der Link ist persönlich – bitte nicht weitergeben. Wenn er doch einmal in falsche Hände gerät, erzeuge unten einen neuen.', 'vereinsplugin' ) . '</p>';
	echo '</div>';

	echo '<div class="vp-card"><h3>' . esc_html__( 'In Nextcloud einbinden', 'vereinsplugin' ) . '</h3><ol>';
	$nc = function_exists( 'vp_nc_cfg' ) ? vp_nc_cfg()['base'] : '';
	if ( $nc ) {
		printf( '<li>%s</li>', wp_kses( sprintf( /* translators: %s = link */ __( 'Nextcloud-Kalender öffnen: %s', 'vereinsplugin' ), '<a href="' . esc_url( $nc . '/index.php/apps/calendar/' ) . '" target="_blank" rel="noopener">' . esc_html( wp_parse_url( $nc, PHP_URL_HOST ) ) . '</a>' ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ) );
	} else {
		echo '<li>' . esc_html__( 'Nextcloud öffnen und die App „Kalender“ aufrufen.', 'vereinsplugin' ) . '</li>';
	}
	echo '<li>' . esc_html__( 'Links unten „+ Neuer Kalender“ → „Neues Abonnement aus Link (schreibgeschützt)“.', 'vereinsplugin' ) . '</li>';
	echo '<li>' . esc_html__( 'Den Link von oben einfügen und bestätigen. Nextcloud aktualisiert das Abo selbstständig.', 'vereinsplugin' ) . '</li>';
	echo '</ol><p class="vp-muted">' . esc_html__( 'Genauso funktioniert es in Google Kalender („Per URL hinzufügen“), Apple Kalender, Outlook und Thunderbird. Über Nextcloud synchronisiert der Kalender dann auch aufs Handy (DAVx⁵ bzw. iOS-Kalenderkonto).', 'vereinsplugin' ) . '</p></div>';

	echo '<form method="post" class="vp-card">';
	wp_nonce_field( 'vp_kal_abo', 'vp_kal_nonce' );
	echo '<button class="vp-btn" name="vp_kal_token_neu" value="1" onclick="return confirm(\'' . esc_js( __( 'Neuen Link erzeugen? Der bisherige Link hört dann auf zu funktionieren.', 'vereinsplugin' ) ) . '\')">' . esc_html__( 'Neuen Link erzeugen', 'vereinsplugin' ) . '</button>';
	echo '</form>';
	?>
	<script>
	document.querySelectorAll('[data-kal-copy]').forEach(function(b){
		b.addEventListener('click', function(){
			var i = document.querySelector('.vp-kal-abo-url');
			if (!i) return;
			i.select();
			var ok = function(){ b.textContent = '<?php echo esc_js( __( 'Kopiert ✓', 'vereinsplugin' ) ); ?>'; };
			if (navigator.clipboard) { navigator.clipboard.writeText(i.value).then(ok, function(){ document.execCommand('copy'); ok(); }); }
			else { document.execCommand('copy'); ok(); }
		});
	});
	</script>
	<?php
	return ob_get_clean();
}

/* ---- Verwalten: Öffnungszeiten, Schließtage, Termine, Nextcloud ---- */

function vp_kal_verwalten_speichern() {
	$msg = array();
	$p   = wp_unslash( $_POST );

	if ( isset( $p['vp_kal_oeffnung_speichern'] ) ) {
		$zeiten = array();
		foreach ( (array) ( $p['oe'] ?? array() ) as $z ) {
			$tag = (int) ( $z['tag'] ?? 0 );
			$von = preg_match( '/^\d{2}:\d{2}$/', (string) ( $z['von'] ?? '' ) ) ? $z['von'] : '';
			if ( $tag < 1 || $tag > 7 || ! $von ) {
				continue;
			}
			$zeiten[] = array(
				'tag'   => $tag,
				'von'   => $von,
				'bis'   => preg_match( '/^\d{2}:\d{2}$/', (string) ( $z['bis'] ?? '' ) ) ? $z['bis'] : '',
				'titel' => sanitize_text_field( $z['titel'] ?? '' ),
				'ort'   => sanitize_text_field( $z['ort'] ?? '' ),
			);
		}
		usort( $zeiten, function ( $a, $b ) { return ( $a['tag'] <=> $b['tag'] ) ?: strcmp( $a['von'], $b['von'] ); } );
		update_option( 'vp_kal_oeffnungszeiten', $zeiten, false );

		$zu = array();
		foreach ( (array) ( $p['zu'] ?? array() ) as $z ) {
			$von = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $z['von'] ?? '' ) ) ? $z['von'] : '';
			if ( ! $von ) {
				continue;
			}
			$bis  = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $z['bis'] ?? '' ) ) ? $z['bis'] : '';
			$zu[] = array( 'von' => $von, 'bis' => $bis && $bis >= $von ? $bis : '', 'grund' => sanitize_text_field( $z['grund'] ?? '' ) );
		}
		usort( $zu, function ( $a, $b ) { return strcmp( $a['von'], $b['von'] ); } );
		update_option( 'vp_kal_schliesstage', $zu, false );
		$msg[] = __( 'Öffnungszeiten gespeichert.', 'vereinsplugin' );
	}

	if ( isset( $p['vp_kal_termin_speichern'] ) ) {
		$titel = sanitize_text_field( $p['t_titel'] ?? '' );
		$datum = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $p['t_datum'] ?? '' ) ) ? $p['t_datum'] : '';
		$gremium = (int) ( $p['t_gremium'] ?? 0 );
		if ( $titel && $datum && $gremium && vp_kal_pp_termine_moeglich() ) {
			// Kreis-Termin: in ProtokollPro speichern, damit Aufgaben-Sets und
			// Rollenaufgaben daran hängen können.
			global $wpdb;
			$von = preg_match( '/^\d{2}:\d{2}$/', (string) ( $p['t_von'] ?? '' ) ) ? $p['t_von'] : '00:00';
			$wpdb->insert( $wpdb->prefix . 'pp_termine', array(
				'titel'      => $titel,
				'datum'      => $datum . ' ' . $von . ':00',
				'ort'        => sanitize_text_field( $p['t_ort'] ?? '' ),
				'gremium_id' => $gremium,
			) );
			$msg[] = __( 'Kreis-Termin gespeichert. Unten kannst du Aufgaben-Sets und Rollenaufgaben dafür erzeugen.', 'vereinsplugin' );
		} elseif ( $titel && $datum ) {
			$von      = preg_match( '/^\d{2}:\d{2}$/', (string) ( $p['t_von'] ?? '' ) ) ? $p['t_von'] : '';
			$bis      = preg_match( '/^\d{2}:\d{2}$/', (string) ( $p['t_bis'] ?? '' ) ) ? $p['t_bis'] : '';
			$bisdatum = preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $p['t_bisdatum'] ?? '' ) ) && $p['t_bisdatum'] > $datum ? $p['t_bisdatum'] : '';
			$alle     = vp_kal_eigene();
			$id       = sanitize_key( $p['t_id'] ?? '' );
			$id       = $id && isset( $alle[ $id ] ) ? $id : 'e' . wp_generate_password( 8, false, false );
			if ( $von ) {
				$ende = $bis ? ( $bisdatum ?: $datum ) . ' ' . $bis : ( $bisdatum ? $bisdatum . ' 23:59' : '' );
			} else {
				$ende = $bisdatum; // ganztägig: letzter Tag (inklusiv)
			}
			$alle[ $id ] = array(
				'titel'    => $titel,
				'start'    => $von ? $datum . ' ' . $von : $datum,
				'ende'     => $ende,
				'ort'      => sanitize_text_field( $p['t_ort'] ?? '' ),
				'info'     => sanitize_textarea_field( $p['t_info'] ?? '' ),
				'url'      => esc_url_raw( $p['t_url'] ?? '' ),
				'sichtbar' => 'vorstand' === ( $p['t_sichtbar'] ?? '' ) ? 'vorstand' : 'mitglieder',
			);
			update_option( 'vp_kal_eigene', $alle, false );
			$msg[] = __( 'Termin gespeichert.', 'vereinsplugin' );
		} else {
			$msg[] = __( 'Bitte Titel und Datum angeben.', 'vereinsplugin' );
		}
	}

	if ( isset( $p['vp_kal_termin_loeschen'] ) ) {
		$alle = vp_kal_eigene();
		unset( $alle[ sanitize_key( $p['vp_kal_termin_loeschen'] ) ] );
		update_option( 'vp_kal_eigene', $alle, false );
		$msg[] = __( 'Termin gelöscht.', 'vereinsplugin' );
	}

	if ( isset( $p['vp_kal_pp_rollen'] ) && vp_kal_pp_termine_moeglich() && function_exists( 'pp_erzeuge_event_aufgaben' ) ) {
		$n     = pp_erzeuge_event_aufgaben( (int) $p['vp_kal_pp_rollen'] );
		$msg[] = false === $n
			? __( 'Für Rollenaufgaben braucht der Termin einen Kreis und ein Datum.', 'vereinsplugin' )
			: sprintf( /* translators: %d = count */ __( '%d Rollenaufgabe(n) erzeugt (bereits vorhandene werden übersprungen).', 'vereinsplugin' ), (int) $n );
	}

	if ( isset( $p['vp_kal_pp_loeschen'] ) && vp_kal_pp_termine_moeglich() ) {
		global $wpdb;
		// Nur manuell angelegte Termine – Sitzungstermine hängen an der Sitzung.
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}pp_termine WHERE id = %d AND quelle_protokoll_id IS NULL AND quelle_top_id IS NULL",
			(int) $p['vp_kal_pp_loeschen']
		) );
		$msg[] = __( 'Kreis-Termin gelöscht.', 'vereinsplugin' );
	}

	if ( isset( $p['vp_kal_nc_hinzu'] ) ) {
		$url = esc_url_raw( vp_kal_nc_normalize_url( $p['nc_url'] ?? '' ), array( 'http', 'https' ) );
		if ( $url ) {
			$alle = vp_kal_nc_kalender();
			$kal  = array(
				'name'     => sanitize_text_field( $p['nc_name'] ?? '' ) ?: wp_parse_url( $url, PHP_URL_HOST ),
				'url'      => $url,
				'farbe'    => sanitize_hex_color( $p['nc_farbe'] ?? '' ) ?: '',
				'sichtbar' => 'vorstand' === ( $p['nc_sichtbar'] ?? '' ) ? 'vorstand' : 'mitglieder',
			);
			$test = vp_kal_nc_fetch( $kal, true );
			if ( is_wp_error( $test ) ) {
				$msg[] = $test->get_error_message();
			} else {
				$alle[ 'n' . wp_generate_password( 8, false, false ) ] = $kal;
				update_option( 'vp_kal_nc_kalender', $alle, false );
				$msg[] = sprintf( /* translators: %s = name */ __( 'Nextcloud-Kalender „%s“ eingebunden.', 'vereinsplugin' ), $kal['name'] );
			}
		}
	}

	if ( isset( $p['vp_kal_nc_entfernen'] ) ) {
		$alle = vp_kal_nc_kalender();
		unset( $alle[ sanitize_key( $p['vp_kal_nc_entfernen'] ) ] );
		update_option( 'vp_kal_nc_kalender', $alle, false );
		$msg[] = __( 'Nextcloud-Kalender entfernt.', 'vereinsplugin' );
	}

	return $msg;
}

/** Darf die Person Kreis-Termine (ProtokollPro) anlegen und vorbereiten? */
function vp_kal_pp_termine_moeglich() {
	global $wpdb;
	return ( current_user_can( 'pp_manage' ) || current_user_can( 'manage_options' ) )
		&& function_exists( 'pp_get_gremien' )
		&& vp_kal_table_exists( $wpdb->prefix . 'pp_termine' );
}

/**
 * Anstehende Kreis-Termine (inkl. geplanter Sitzungen) mit den Vorbereitungs-
 * Funktionen, die früher im Reiter „Termine“ von Sitzungen & Protokolle lagen.
 */
function vp_kal_render_pp_termine( $nonce ) {
	if ( ! vp_kal_pp_termine_moeglich() ) {
		return '';
	}
	global $wpdb;
	$termine = function_exists( 'pp_get_naechste_termine' ) ? (array) pp_get_naechste_termine( 50 ) : array();
	$sets    = function_exists( 'pp_get_aufgaben_sets' ) ? (array) pp_get_aufgaben_sets() : array();
	$zurueck = vp_kal_url( array( 'vp_kal' => 'verwalten' ) );

	ob_start();
	echo '<div class="vp-card" id="vp-kal-kreistermine"><h3>' . esc_html__( 'Kreis-Termine & Vorbereitung', 'vereinsplugin' ) . '</h3>';
	echo '<p class="vp-muted">' . esc_html__( 'Geplante Sitzungen erscheinen hier automatisch, sobald ein Datum eingetragen ist. „Set anwenden“ erzeugt alle Vorbereitungsaufgaben eines Aufgaben-Sets; „Rollenaufgaben“ erzeugt die Aufgaben, die bei den Rollen des Kreises „vor Veranstaltungen“ hinterlegt sind.', 'vereinsplugin' ) . '</p>';
	if ( ! $termine ) {
		echo '<p class="vp-muted">' . esc_html__( 'Keine anstehenden Kreis-Termine. Oben beim Termin einen Kreis auswählen, um einen anzulegen.', 'vereinsplugin' ) . '</p></div>';
		return ob_get_clean();
	}
	echo '<div class="vp-table-wrap"><table class="vp-table vp-kal-pp-termine"><thead><tr><th>' . esc_html__( 'Termin', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Datum', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Kreis', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Vorbereitung', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
	foreach ( $termine as $t ) {
		$herkunft = ! empty( $t->quelle_protokoll_id ) ? __( 'geplante Sitzung', 'vereinsplugin' ) : ( ! empty( $t->quelle_top_id ) ? __( 'aus Beschluss', 'vereinsplugin' ) : '' );
		echo '<tr><td><strong>' . esc_html( $t->titel ) . '</strong>' . ( $t->ort ? '<br><small class="vp-muted">' . esc_html( $t->ort ) . '</small>' : '' ) . ( $herkunft ? '<br><small class="vp-muted">' . esc_html( $herkunft ) . '</small>' : '' ) . '</td>';
		echo '<td style="white-space:nowrap">' . esc_html( mysql2date( 'd.m.Y H:i', $t->datum ) ) . '</td>';
		echo '<td>' . esc_html( $t->gremium_name ?: '–' ) . '</td><td>';

		// Aufgaben-Set anwenden (ProtokollPro-Handler, leitet zurück in den Kalender).
		$passende = array_filter( $sets, function ( $set ) use ( $t ) { return ! $set->gremium_id || (int) $set->gremium_id === (int) $t->gremium_id; } );
		if ( $passende && $t->datum ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'pp_front_set_anwenden' );
			echo '<input type="hidden" name="action" value="pp_front_set_anwenden"><input type="hidden" name="termin_id" value="' . (int) $t->id . '"><input type="hidden" name="pp_return" value="' . esc_url( $zurueck ) . '">';
			echo '<select name="set_id" required><option value="">' . esc_html__( 'Set…', 'vereinsplugin' ) . '</option>';
			foreach ( $passende as $set ) {
				echo '<option value="' . (int) $set->id . '">' . esc_html( $set->name ) . '</option>';
			}
			echo '</select><button class="vp-btn">' . esc_html__( 'anwenden', 'vereinsplugin' ) . '</button></form> ';
		}
		echo '<form method="post">' . $nonce; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $t->gremium_id ) {
			echo '<button class="vp-btn" name="vp_kal_pp_rollen" value="' . (int) $t->id . '">' . esc_html__( 'Rollenaufgaben erzeugen', 'vereinsplugin' ) . '</button>';
		}
		if ( function_exists( 'vp_thema_link' ) && vp_thema_verfuegbar() ) {
			echo '<a class="vp-btn" href="' . esc_url( vp_thema_link( sprintf( /* translators: 1: Termin, 2: Datum */ __( 'Termin „%1$s“ (%2$s): ', 'vereinsplugin' ), $t->titel, mysql2date( 'd.m.Y', $t->datum ) ), (string) $t->ort, (int) $t->gremium_id, $zurueck ) ) . '">📌 ' . esc_html__( 'als TOP', 'vereinsplugin' ) . '</a>';
		}
		if ( empty( $t->quelle_protokoll_id ) && empty( $t->quelle_top_id ) ) {
			echo '<button class="vp-btn vp-btn-danger" name="vp_kal_pp_loeschen" value="' . (int) $t->id . '" onclick="return confirm(\'' . esc_js( __( 'Kreis-Termin löschen?', 'vereinsplugin' ) ) . '\')">' . esc_html__( 'Löschen', 'vereinsplugin' ) . '</button>';
		}
		echo '</form></td></tr>';
	}
	echo '</tbody></table></div></div>';
	return ob_get_clean();
}

function vp_kal_render_verwalten() {
	if ( ! vp_kal_can_manage() ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) . '</div>';
	}
	$msg = array();
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['vp_kal_nonce'] ) && check_admin_referer( 'vp_kal_verwalten', 'vp_kal_nonce' ) ) {
		$msg = vp_kal_verwalten_speichern();
	}
	$nonce = wp_nonce_field( 'vp_kal_verwalten', 'vp_kal_nonce', true, false );
	$wt    = vp_kal_wochentage();

	ob_start();
	foreach ( $msg as $m ) {
		echo '<div class="vp-note">' . esc_html( $m ) . '</div>';
	}

	/* -- Öffnungszeiten -- */
	echo '<form method="post" class="vp-card vp-form"><h3>' . esc_html__( 'Öffnungszeiten', 'vereinsplugin' ) . '</h3>' . $nonce; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<p class="vp-muted">' . esc_html__( 'Wiederholen sich jede Woche. Mehrere Zeiten pro Tag sind möglich (z. B. vormittags und abends). Leere Zeilen werden ignoriert.', 'vereinsplugin' ) . '</p>';
	echo '<div class="vp-table-wrap"><table class="vp-table vp-kal-edit"><thead><tr><th>' . esc_html__( 'Tag', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'von', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'bis', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Bezeichnung', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Ort', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
	$zeiten = array_merge( vp_kal_oeffnungszeiten(), array_fill( 0, 3, array() ) );
	foreach ( $zeiten as $i => $z ) {
		echo '<tr><td><select name="oe[' . (int) $i . '][tag]"><option value="">–</option>';
		foreach ( $wt as $n => $label ) {
			printf( '<option value="%d"%s>%s</option>', (int) $n, selected( (int) ( $z['tag'] ?? 0 ), $n, false ), esc_html( $label ) );
		}
		printf(
			'</select></td><td><input type="time" name="oe[%1$d][von]" value="%2$s"></td><td><input type="time" name="oe[%1$d][bis]" value="%3$s"></td><td><input type="text" name="oe[%1$d][titel]" value="%4$s" placeholder="%6$s"></td><td><input type="text" name="oe[%1$d][ort]" value="%5$s"></td></tr>',
			(int) $i,
			esc_attr( $z['von'] ?? '' ),
			esc_attr( $z['bis'] ?? '' ),
			esc_attr( $z['titel'] ?? '' ),
			esc_attr( $z['ort'] ?? '' ),
			esc_attr__( 'z. B. Offener Treff', 'vereinsplugin' )
		);
	}
	echo '</tbody></table></div>';

	echo '<h4>' . esc_html__( 'Schließtage / Ferien', 'vereinsplugin' ) . '</h4>';
	echo '<p class="vp-muted">' . esc_html__( 'An diesen Tagen entfallen die Öffnungszeiten; im Kalender steht stattdessen „Geschlossen“.', 'vereinsplugin' ) . '</p>';
	echo '<div class="vp-table-wrap"><table class="vp-table vp-kal-edit"><thead><tr><th>' . esc_html__( 'von', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'bis (optional)', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Grund', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
	$heute = wp_date( 'Y-m-d' );
	$zu    = array_filter( vp_kal_schliesstage(), function ( $z ) use ( $heute ) { return ( $z['bis'] ?: $z['von'] ) >= $heute; } ); // Vergangenes fällt beim Speichern weg.
	foreach ( array_merge( array_values( $zu ), array_fill( 0, 3, array() ) ) as $i => $z ) {
		printf(
			'<tr><td><input type="date" name="zu[%1$d][von]" value="%2$s"></td><td><input type="date" name="zu[%1$d][bis]" value="%3$s"></td><td><input type="text" name="zu[%1$d][grund]" value="%4$s" placeholder="%5$s"></td></tr>',
			(int) $i,
			esc_attr( $z['von'] ?? '' ),
			esc_attr( $z['bis'] ?? '' ),
			esc_attr( $z['grund'] ?? '' ),
			esc_attr__( 'z. B. Sommerferien', 'vereinsplugin' )
		);
	}
	echo '</tbody></table></div>';
	echo '<p><button class="vp-btn vp-btn-primary" name="vp_kal_oeffnung_speichern" value="1">' . esc_html__( 'Speichern', 'vereinsplugin' ) . '</button></p></form>';

	/* -- Weitere Termine -- */
	$alle = vp_kal_eigene();
	uasort( $alle, function ( $a, $b ) { return strcmp( $a['start'], $b['start'] ); } );
	$edit_id = isset( $_GET['vp_kal_edit'] ) ? sanitize_key( wp_unslash( $_GET['vp_kal_edit'] ) ) : '';
	$e       = $edit_id && isset( $alle[ $edit_id ] ) ? $alle[ $edit_id ] : array();

	echo '<form method="post" class="vp-card vp-form" id="vp-kal-termin"><h3>' . esc_html( $e ? __( 'Termin bearbeiten', 'vereinsplugin' ) : __( 'Weiteren Termin eintragen', 'vereinsplugin' ) ) . '</h3>' . $nonce; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<p class="vp-muted">' . esc_html__( 'Für alles, was nicht aus Veranstaltungen, Sitzungen oder Schichtplänen kommt – z. B. Ausflüge, Fristen, Aufräumtage.', 'vereinsplugin' ) . '</p>';
	printf( '<input type="hidden" name="t_id" value="%s">', esc_attr( $e ? $edit_id : '' ) );
	echo '<div class="vp-form-grid">';
	printf( '<label class="vp-col-2">%s<input type="text" name="t_titel" required value="%s"></label>', esc_html__( 'Titel', 'vereinsplugin' ), esc_attr( $e['titel'] ?? '' ) );
	printf( '<label>%s<input type="date" name="t_datum" required value="%s"></label>', esc_html__( 'Datum', 'vereinsplugin' ), esc_attr( substr( $e['start'] ?? '', 0, 10 ) ) );
	printf( '<label>%s<input type="date" name="t_bisdatum" value="%s"></label>', esc_html__( 'bis Datum (mehrtägig, optional)', 'vereinsplugin' ), esc_attr( ( $e && substr( $e['ende'], 0, 10 ) !== substr( $e['start'], 0, 10 ) ) ? substr( $e['ende'], 0, 10 ) : '' ) );
	printf( '<label>%s<input type="time" name="t_von" value="%s"></label>', esc_html__( 'Beginn (leer = ganztägig)', 'vereinsplugin' ), esc_attr( substr( $e['start'] ?? '', 11, 5 ) ) );
	printf( '<label>%s<input type="time" name="t_bis" value="%s"></label>', esc_html__( 'Ende', 'vereinsplugin' ), esc_attr( substr( $e['ende'] ?? '', 11, 5 ) ) );
	printf( '<label>%s<input type="text" name="t_ort" value="%s"></label>', esc_html__( 'Ort', 'vereinsplugin' ), esc_attr( $e['ort'] ?? '' ) );
	printf( '<label>%s<input type="url" name="t_url" value="%s"></label>', esc_html__( 'Link (optional)', 'vereinsplugin' ), esc_attr( $e['url'] ?? '' ) );
	printf( '<label class="vp-col-2">%s<textarea name="t_info" rows="2">%s</textarea></label>', esc_html__( 'Beschreibung', 'vereinsplugin' ), esc_textarea( $e['info'] ?? '' ) );
	printf(
		'<label>%s<select name="t_sichtbar"><option value="mitglieder">%s</option><option value="vorstand"%s>%s</option></select></label>',
		esc_html__( 'Sichtbar für', 'vereinsplugin' ),
		esc_html__( 'alle Mitglieder', 'vereinsplugin' ),
		selected( $e['sichtbar'] ?? '', 'vorstand', false ),
		esc_html__( 'nur Vorstand', 'vereinsplugin' )
	);
	if ( ! $e && vp_kal_pp_termine_moeglich() ) {
		echo '<label>' . esc_html__( 'Kreis (optional)', 'vereinsplugin' ) . '<select name="t_gremium"><option value="">' . esc_html__( '— kein Kreis —', 'vereinsplugin' ) . '</option>';
		foreach ( (array) pp_get_gremien() as $g ) {
			echo '<option value="' . (int) $g->id . '">' . esc_html( $g->name ) . '</option>';
		}
		echo '</select></label>';
		echo '<p class="vp-col-2 vp-muted" style="margin:0 0 10px">' . esc_html__( 'Mit Kreis wird der Termin ein Kreis-Termin: Er gilt für den Kreis (Sichtbarkeit wie der Kreis), und du kannst unten Aufgaben-Sets und Rollenaufgaben dafür erzeugen. Mehrtägig, Ende, Link und Beschreibung entfallen dann.', 'vereinsplugin' ) . '</p>';
	}
	echo '</div><p><button class="vp-btn vp-btn-primary" name="vp_kal_termin_speichern" value="1">' . esc_html__( 'Speichern', 'vereinsplugin' ) . '</button>';
	if ( $e ) {
		printf( ' <a class="vp-btn" href="%s">%s</a>', esc_url( vp_kal_url( array( 'vp_kal' => 'verwalten' ) ) ), esc_html__( 'Abbrechen', 'vereinsplugin' ) );
	}
	echo '</p></form>';

	if ( $alle ) {
		echo '<form method="post" class="vp-card">' . $nonce . '<h3>' . esc_html__( 'Eingetragene Termine', 'vereinsplugin' ) . '</h3><div class="vp-table-wrap"><table class="vp-table"><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( $alle as $id => $t ) {
			printf(
				'<tr%s><td>%s</td><td>%s%s</td><td style="white-space:nowrap"><a class="vp-btn" href="%s">%s</a><button class="vp-btn vp-btn-danger" name="vp_kal_termin_loeschen" value="%s" onclick="return confirm(\'%s\')">%s</button></td></tr>',
				( $t['ende'] ?: $t['start'] ) < $heute ? ' class="vp-muted"' : '',
				esc_html( wp_date( 'd.m.Y', strtotime( substr( $t['start'], 0, 10 ) . ' 12:00' ) ) . ( strlen( $t['start'] ) > 10 ? ' ' . substr( $t['start'], 11, 5 ) : '' ) ),
				esc_html( $t['titel'] ),
				'vorstand' === ( $t['sichtbar'] ?? '' ) ? ' <span class="vp-badge">' . esc_html__( 'Vorstand', 'vereinsplugin' ) . '</span>' : '',
				esc_url( vp_kal_url( array( 'vp_kal' => 'verwalten', 'vp_kal_edit' => $id ) ) . '#vp-kal-termin' ),
				esc_html__( 'Bearbeiten', 'vereinsplugin' ),
				esc_attr( $id ),
				esc_js( __( 'Termin löschen?', 'vereinsplugin' ) ),
				esc_html__( 'Löschen', 'vereinsplugin' )
			);
		}
		echo '</tbody></table></div></form>';
	}

	echo vp_kal_render_pp_termine( $nonce ); // phpcs:ignore WordPress.Security.EscapeOutput

	/* -- Nextcloud -- */
	echo '<div class="vp-card vp-form"><h3>' . esc_html__( 'Nextcloud-Kalender einbinden', 'vereinsplugin' ) . '</h3>';
	echo '<p class="vp-muted">' . esc_html__( 'Termine aus Nextcloud-Kalendern erscheinen hier mit (nur lesend, alle 15 Minuten aktualisiert). Umgekehrt kann jede Person den Vereinskalender unter „Abonnieren / Nextcloud“ in ihre Nextcloud holen.', 'vereinsplugin' ) . '</p>';

	$nc_alle = vp_kal_nc_kalender();
	if ( $nc_alle ) {
		echo '<form method="post">' . $nonce . '<div class="vp-table-wrap"><table class="vp-table"><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( $nc_alle as $id => $kal ) {
			$status = vp_kal_nc_fetch( $kal );
			printf(
				'<tr><td><span class="vp-kal-punkt" style="--kal-farbe:%s"></span> <strong>%s</strong>%s<br><small class="vp-muted">%s</small>%s</td><td style="white-space:nowrap"><button class="vp-btn vp-btn-danger" name="vp_kal_nc_entfernen" value="%s">%s</button></td></tr>',
				esc_attr( $kal['farbe'] ?: vp_kal_kategorien()['nextcloud']['farbe'] ),
				esc_html( $kal['name'] ),
				'vorstand' === ( $kal['sichtbar'] ?? '' ) ? ' <span class="vp-badge">' . esc_html__( 'Vorstand', 'vereinsplugin' ) . '</span>' : '',
				esc_html( preg_replace( '#[?&]export.*$#', '', $kal['url'] ) ),
				is_wp_error( $status ) ? '<div class="vp-note vp-note-error">' . esc_html( $status->get_error_message() ) . '</div>' : '',
				esc_attr( $id ),
				esc_html__( 'Entfernen', 'vereinsplugin' )
			);
		}
		echo '</tbody></table></div></form>';
	}

	// Kalender des Nextcloud-Kontos zum Anklicken.
	if ( function_exists( 'vp_nc_ready' ) && vp_nc_ready() ) {
		$gefunden = vp_kal_nc_discover();
		if ( is_wp_error( $gefunden ) ) {
			echo '<div class="vp-note vp-note-warn">' . esc_html( $gefunden->get_error_message() ) . '</div>';
		} else {
			$schon = array_map( function ( $k ) { return preg_replace( '#/?\?export.*$#', '', rtrim( $k['url'], '/' ) ); }, $nc_alle );
			$neu   = array_filter( $gefunden, function ( $g ) use ( $schon ) { return ! in_array( rtrim( $g['url'], '/' ), $schon, true ); } );
			if ( $neu ) {
				echo '<h4>' . esc_html__( 'Kalender aus eurer Nextcloud', 'vereinsplugin' ) . '</h4><div class="vp-kal-nc-vorschlaege">';
				foreach ( $neu as $g ) {
					echo '<form method="post" class="vp-kal-nc-vorschlag">' . $nonce; // phpcs:ignore WordPress.Security.EscapeOutput
					printf(
						'<input type="hidden" name="nc_url" value="%s"><input type="hidden" name="nc_name" value="%s"><input type="hidden" name="nc_farbe" value="%s"><button class="vp-btn" name="vp_kal_nc_hinzu" value="1"><span class="vp-kal-punkt" style="--kal-farbe:%s"></span> %s</button>',
						esc_attr( $g['url'] ),
						esc_attr( $g['name'] ),
						esc_attr( $g['farbe'] ),
						esc_attr( $g['farbe'] ?: '#0082c9' ),
						esc_html( '+ ' . $g['name'] )
					);
					echo '</form>';
				}
				echo '</div>';
			}
		}
	} else {
		echo '<div class="vp-note">' . esc_html__( 'Tipp: Ist unter „Verein → Einstellungen“ der Nextcloud-Zugang eingetragen, werden die Kalender dieses Kontos hier automatisch zur Auswahl angeboten.', 'vereinsplugin' ) . '</div>';
	}

	echo '<form method="post"><h4>' . esc_html__( 'Per Link hinzufügen', 'vereinsplugin' ) . '</h4>' . $nonce; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '<p class="vp-muted">' . esc_html__( 'In Nextcloud beim Kalender „…“ → „Teilen“ → „Link teilen“ bzw. „Abonnement-Link kopieren“ – oder jede andere iCal-/ICS-Adresse.', 'vereinsplugin' ) . '</p>';
	echo '<div class="vp-form-grid">';
	printf( '<label class="vp-col-2">%s<input type="url" name="nc_url" required placeholder="https://cloud.verein.de/index.php/apps/calendar/p/…"></label>', esc_html__( 'Link', 'vereinsplugin' ) );
	printf( '<label>%s<input type="text" name="nc_name" placeholder="%s"></label>', esc_html__( 'Name', 'vereinsplugin' ), esc_attr__( 'z. B. Raumbelegung', 'vereinsplugin' ) );
	printf( '<label>%s<input type="color" name="nc_farbe" value="#0082c9"></label>', esc_html__( 'Farbe', 'vereinsplugin' ) );
	printf(
		'<label>%s<select name="nc_sichtbar"><option value="mitglieder">%s</option><option value="vorstand">%s</option></select></label>',
		esc_html__( 'Sichtbar für', 'vereinsplugin' ),
		esc_html__( 'alle Mitglieder', 'vereinsplugin' ),
		esc_html__( 'nur Vorstand', 'vereinsplugin' )
	);
	echo '</div><p><button class="vp-btn vp-btn-primary" name="vp_kal_nc_hinzu" value="1">' . esc_html__( 'Einbinden', 'vereinsplugin' ) . '</button></p></form>';
	echo '</div>';

	return ob_get_clean();
}

/* =========================================================================
 * Start-Seite: „Nächste Termine“
 * ====================================================================== */

function vp_kal_naechste_termine_html( $anzahl = 5 ) {
	$tz    = wp_timezone();
	$jetzt = new DateTimeImmutable( 'now', $tz );
	$alle  = vp_kal_termine( $jetzt->format( 'Y-m-d' ), $jetzt->modify( '+60 days' )->format( 'Y-m-d' ) );
	$now_s = $jetzt->format( 'Y-m-d H:i' );
	// Öffnungszeiten wiederholen sich ohnehin – auf der Startseite nur echte Termine.
	$alle = array_filter( $alle, function ( $t ) use ( $now_s ) {
		$ende = $t['ende'] ?: $t['start'];
		return 'oeffnung' !== $t['kat'] && ( $t['ganztags'] ? substr( $ende, 0, 10 ) >= substr( $now_s, 0, 10 ) : $ende >= $now_s );
	} );
	$alle = array_slice( array_values( $alle ), 0, $anzahl );
	if ( ! $alle ) {
		return '';
	}
	$kats = vp_kal_kategorien();
	$wt   = vp_kal_wochentage();
	$html = '<div class="vp-card"><h3 style="margin-top:0">' . esc_html__( 'Nächste Termine', 'vereinsplugin' ) . '</h3><div class="vp-kal-liste">';
	foreach ( $alle as $t ) {
		$d  = new DateTimeImmutable( substr( $t['start'], 0, 10 ), $tz );
		$t2 = $t;
		$t2['info'] = '';
		$html .= '<div class="vp-kal-start-datum">' . esc_html( $wt[ (int) $d->format( 'N' ) ] . ', ' . $d->format( 'd.m.' ) ) . '</div>' . vp_kal_render_zeile( $t2, $kats );
	}
	return $html . '</div><p><a class="vp-btn" href="' . esc_url( vp_kal_url() ) . '">' . esc_html__( 'Zum Kalender', 'vereinsplugin' ) . '</a></p></div>';
}
