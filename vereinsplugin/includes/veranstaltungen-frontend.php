<?php
/**
 * Veranstaltungen vermarkten – im Mitgliederbereich statt im WP-Backend.
 *
 * Ablauf in drei Schritten auf einer Seite:
 *   1. Daten: Titel, Datum/Uhrzeit, Ort, Beschreibung (Website), Kanäle.
 *   2. Material-Checkliste: welche Texte und Bildformate die gewählten Kanäle
 *      brauchen – je Punkt Text eintragen bzw. Datei hochladen, „In Canva
 *      gestalten“ (neues Design im passenden Format) und Canva-Link merken,
 *      Ablage in einem Nextcloud-Ordner je Veranstaltung.
 *   3. Kanal-Checkliste: automatische Kanäle einzeln senden (nur Vorstand,
 *      Recht jbf_send_external), manuelle Kanäle abhaken.
 *
 *   4. Zeitplan (optional): zeitversetzte Kampagne – Schritte relativ zum
 *      (ersten) Termin, per WP-Cron einplanen und stoppen.
 * Dazu: Löschen (Papierkorb, Wiederherstellen, endgültig löschen) und für
 * Administrator:innen die Zugänge zu Social Media und Presse – das
 * WP-Backend wird für Veranstaltungen nicht mehr gebraucht.
 *
 * Datenbasis bleibt der Beitragstyp „veranstaltung“ mit den _jbf_*-Feldern
 * des Veranstaltungs-Publishers – Versand, Kampagnen und Backend-Editor
 * funktionieren unverändert weiter.
 */

defined( 'ABSPATH' ) || exit;

function vp_ev_verfuegbar() {
	return post_type_exists( 'veranstaltung' ) && class_exists( 'Jbf_Publisher' );
}

function vp_ev_url( array $args = array() ) {
	$base = get_permalink() ?: home_url( '/' );
	return add_query_arg( array_merge( array( 'vp_tab' => 'veranstaltungen' ), $args ), $base );
}

/** Kanäle: die des Publishers plus manuelle Zusatzkanäle. */
function vp_ev_kanaele() {
	$k = class_exists( 'Jbf_Metaboxes' ) ? Jbf_Metaboxes::channels() : array();
	$k = array_merge( $k, array(
		'website'    => __( 'Website (Veranstaltungsseite)', 'vereinsplugin' ),
		'newsletter' => __( 'Newsletter', 'vereinsplugin' ),
		'aushang'    => __( 'Plakate / Flyer aushängen', 'vereinsplugin' ),
	) );
	return apply_filters( 'vp_ev_kanaele', $k );
}

/** Kanäle, die der Publisher automatisch bedienen kann. */
function vp_ev_auto_kanaele() {
	return class_exists( 'Jbf_Publisher' ) ? array_keys( Jbf_Publisher::connectors() ) : array();
}

/**
 * Material, das die Kanäle brauchen. typ: text | textarea | bild | video | datei
 * w/h = empfohlenes Format in Pixeln (für Canva und die Formatprüfung).
 */
function vp_ev_material() {
	$feeds = array( 'mastodon', 'bluesky', 'twitter', 'telegram', 'facebook', 'instagram', 'whatsapp', 'signal' );
	return apply_filters( 'vp_ev_material', array(
		'text'      => array( 'titel' => __( 'Text für Social Media & Gruppen', 'vereinsplugin' ), 'typ' => 'textarea', 'meta' => '_jbf_short_text', 'kanaele' => array_merge( $feeds, array( 'facebook_event' ) ), 'pflicht' => true,
			'hinweis' => __( 'Ein gemeinsamer Text für alle Kanäle. Der Hashtag aus den Einstellungen wird automatisch angehängt.', 'vereinsplugin' ) ),
		'social'    => array( 'titel' => __( 'Bild Social-Feed (Hochformat 4:5)', 'vereinsplugin' ), 'typ' => 'bild', 'meta' => '_jbf_img_social', 'w' => 1080, 'h' => 1350, 'kanaele' => $feeds, 'pflicht' => true,
			'hinweis' => __( 'Für alle Feed-Kanäle inkl. Instagram; wird auch an die Presse angehängt.', 'vereinsplugin' ) ),
		'story'     => array( 'titel' => __( 'Bild Instagram-Story (9:16)', 'vereinsplugin' ), 'typ' => 'bild', 'meta' => '_jbf_img_story', 'w' => 1080, 'h' => 1920, 'kanaele' => array( 'instagram' ), 'pflicht' => false ),
		'fb_event'  => array( 'titel' => __( 'Bild Facebook-Veranstaltung (Querformat)', 'vereinsplugin' ), 'typ' => 'bild', 'meta' => '_jbf_img_fb_event', 'w' => 1200, 'h' => 628, 'kanaele' => array( 'facebook_event' ), 'pflicht' => true ),
		'reel'      => array( 'titel' => __( 'Instagram-Reel (Video 9:16, unter 90 s)', 'vereinsplugin' ), 'typ' => 'video', 'meta' => '_jbf_video_reel', 'w' => 1080, 'h' => 1920, 'kanaele' => array( 'instagram' ), 'pflicht' => false ),
		'beitragsbild' => array( 'titel' => __( 'Beitragsbild für die Website (Querformat 16:9)', 'vereinsplugin' ), 'typ' => 'bild', 'meta' => '_thumbnail_id', 'w' => 1200, 'h' => 675, 'kanaele' => array( 'website' ), 'pflicht' => false,
			'hinweis' => __( 'Erscheint auf der Veranstaltungsseite und in Übersichten.', 'vereinsplugin' ) ),
		'plakat'    => array( 'titel' => __( 'Plakat / Flyer (A3, PDF oder Bild)', 'vereinsplugin' ), 'typ' => 'datei', 'meta' => '_vp_ev_plakat', 'w' => 3508, 'h' => 4961, 'kanaele' => array( 'aushang', 'newsletter' ), 'pflicht' => true,
			'hinweis' => __( 'Canva-Vorlage in A3 (297 × 420 mm, 300 dpi).', 'vereinsplugin' ) ),
		'presse_ue' => array( 'titel' => __( 'Presse: Überschrift', 'vereinsplugin' ), 'typ' => 'text', 'meta' => '_jbf_press_headline', 'kanaele' => array( 'press' ), 'pflicht' => false,
			'hinweis' => __( 'Leer = Titel der Veranstaltung.', 'vereinsplugin' ) ),
		'presse'    => array( 'titel' => __( 'Presse: Text', 'vereinsplugin' ), 'typ' => 'textarea', 'meta' => '_jbf_press_lead', 'kanaele' => array( 'press' ), 'pflicht' => false,
			'hinweis' => __( 'Leer = Beschreibung der Veranstaltung.', 'vereinsplugin' ) ),
		'kontakt'   => array( 'titel' => __( 'Presse: Kontakt für Rückfragen', 'vereinsplugin' ), 'typ' => 'textarea', 'meta' => '_jbf_press_contact', 'kanaele' => array( 'press' ), 'pflicht' => false ),
		'newsletter' => array( 'titel' => __( 'Text für den Newsletter', 'vereinsplugin' ), 'typ' => 'textarea', 'meta' => '_vp_ev_newsletter_text', 'kanaele' => array( 'newsletter' ), 'pflicht' => true ),
		't_telegram' => array( 'titel' => __( 'Eigener Text Telegram (optional)', 'vereinsplugin' ), 'typ' => 'textarea', 'meta' => '_jbf_telegram_text', 'kanaele' => array( 'telegram' ), 'pflicht' => false, 'extra' => true ),
		't_facebook' => array( 'titel' => __( 'Eigener Text Facebook-Seite (optional)', 'vereinsplugin' ), 'typ' => 'textarea', 'meta' => '_jbf_facebook_text', 'kanaele' => array( 'facebook' ), 'pflicht' => false, 'extra' => true ),
		't_insta'   => array( 'titel' => __( 'Eigene Instagram-Caption (optional)', 'vereinsplugin' ), 'typ' => 'textarea', 'meta' => '_jbf_instagram_caption', 'kanaele' => array( 'instagram' ), 'pflicht' => false, 'extra' => true ),
		't_wa'      => array( 'titel' => __( 'Eigener Text WhatsApp & Signal (optional)', 'vereinsplugin' ), 'typ' => 'textarea', 'meta' => '_jbf_whatsapp_signal_text', 'kanaele' => array( 'whatsapp', 'signal' ), 'pflicht' => false, 'extra' => true ),
		't_fbev'    => array( 'titel' => __( 'Eigener Text Facebook-Veranstaltung (optional)', 'vereinsplugin' ), 'typ' => 'textarea', 'meta' => '_jbf_fb_event_text', 'kanaele' => array( 'facebook_event' ), 'pflicht' => false, 'extra' => true ),
	) );
}

/** Link für ein neues Canva-Design im passenden Format. */
function vp_ev_canva_link( $w, $h ) {
	return sprintf( 'https://www.canva.com/design?create&width=%d&height=%d&units=px', (int) $w, (int) $h );
}

/** Gewählte Kanäle einer Veranstaltung. */
function vp_ev_gewaehlt( $post_id ) {
	return array_values( array_filter( (array) get_post_meta( $post_id, '_jbf_channels', true ) ) );
}

/** Ist ein Materialpunkt erledigt? */
function vp_ev_material_fertig( $post_id, array $m ) {
	return '' !== trim( (string) get_post_meta( $post_id, $m['meta'], true ) );
}

/** Für die gewählten Kanäle nötiges Material. */
function vp_ev_material_fuer( $post_id ) {
	$k   = vp_ev_gewaehlt( $post_id );
	$out = array();
	foreach ( vp_ev_material() as $id => $m ) {
		if ( array_intersect( $m['kanaele'], $k ) ) {
			$out[ $id ] = $m;
		}
	}
	return $out;
}

/** Status je Kanal: [ kanal => [ am, von, link, text ] ] */
function vp_ev_kanal_status( $post_id ) {
	$s = get_post_meta( $post_id, '_vp_ev_kanal_status', true );
	return is_array( $s ) ? $s : array();
}

function vp_ev_kanal_setzen( $post_id, $kanal, $erledigt, $link = '', $text = '' ) {
	$s = vp_ev_kanal_status( $post_id );
	if ( $erledigt ) {
		$s[ $kanal ] = array( 'am' => current_time( 'mysql' ), 'von' => get_current_user_id(), 'link' => esc_url_raw( $link ), 'text' => sanitize_text_field( $text ) );
	} else {
		unset( $s[ $kanal ] );
	}
	update_post_meta( $post_id, '_vp_ev_kanal_status', $s );
}

/* =========================================================================
 * Veranstaltungsreihen: mehrere Termine je Veranstaltung
 * Meta _vp_ev_termine = [ [beginn 'Y-m-dTH:i', ort, notiz], … ] (sortiert).
 * _jbf_date_start bleibt der erste Termin (Kampagnen, ältere Auswertungen).
 * ====================================================================== */

/** Termine (bei Einzelterminen der eine Termin aus _jbf_date_start). */
function vp_ev_termine( $post_id ) {
	$t = get_post_meta( $post_id, '_vp_ev_termine', true );
	if ( is_array( $t ) && $t ) {
		return array_values( $t );
	}
	$d = (string) get_post_meta( $post_id, '_jbf_date_start', true );
	return $d ? array( array( 'beginn' => $d, 'ort' => '', 'notiz' => '' ) ) : array();
}

function vp_ev_ist_reihe( $post_id ) {
	$t = get_post_meta( $post_id, '_vp_ev_termine', true );
	return is_array( $t ) && count( $t ) > 1;
}

/** Werbung je Termin (eigene Kanal-Checkliste und eigener Versand je Termin)? */
function vp_ev_je_termin( $post_id ) {
	return vp_ev_ist_reihe( $post_id ) && '1' === (string) get_post_meta( $post_id, '_vp_ev_je_termin', true );
}

/** „Fr, 17.07.2026, 19:00 Uhr · Jugendhaus · Hinweis“ */
function vp_ev_termin_text( array $t, $post_id = 0 ) {
	$ts = strtotime( str_replace( 'T', ' ', (string) $t['beginn'] ) );
	if ( ! $ts ) {
		return '';
	}
	$zeit = gmdate( 'H:i', $ts );
	$text = date_i18n( 'D, d.m.Y', $ts ) . ( '00:00' !== $zeit ? ', ' . $zeit . ' Uhr' : '' );
	$ort  = trim( (string) ( $t['ort'] ?? '' ) );
	if ( '' === $ort && $post_id ) {
		$ort = (string) get_post_meta( $post_id, '_jbf_location', true );
	}
	return $text . ( '' !== $ort ? ' · ' . $ort : '' ) . ( ! empty( $t['notiz'] ) ? ' · ' . $t['notiz'] : '' );
}

/** Terminliste als Text (eine Zeile je Termin). */
function vp_ev_terminliste_text( $post_id, $nur_kommende = false ) {
	$z = array();
	foreach ( vp_ev_termine( $post_id ) as $t ) {
		if ( $nur_kommende && strtotime( str_replace( 'T', ' ', $t['beginn'] ) ) < current_time( 'timestamp' ) - DAY_IN_SECONDS ) {
			continue;
		}
		$z[] = '• ' . vp_ev_termin_text( $t, $post_id );
	}
	return implode( "\n", $z );
}

/** Schlüssel in der Kanal-Checkliste: „instagram“ oder je Termin „instagram@2“. */
function vp_ev_status_key( $kanal, $termin = null ) {
	return null === $termin ? $kanal : $kanal . '@' . (int) $termin;
}

/** Termine aus dem Formular lesen (Zeilen + Serien-Generator), sortiert. */
function vp_ev_termine_aus_post( array $p ) {
	$out = array();
	foreach ( (array) ( $p['termine'] ?? array() ) as $t ) {
		$b = sanitize_text_field( (string) ( $t['beginn'] ?? '' ) );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $b ) ) {
			$out[] = array( 'beginn' => substr( $b, 0, 16 ), 'ort' => sanitize_text_field( (string) ( $t['ort'] ?? '' ) ), 'notiz' => sanitize_text_field( (string) ( $t['notiz'] ?? '' ) ) );
		}
	}
	// Serie erzeugen: ab Datum, Rhythmus, Anzahl.
	$ab  = sanitize_text_field( (string) ( $p['serie_ab'] ?? '' ) );
	$anz = min( 52, max( 0, (int) ( $p['serie_anzahl'] ?? 0 ) ) );
	$rh  = sanitize_key( (string) ( $p['serie_rhythmus'] ?? 'woche' ) );
	if ( preg_match( '/^\d{4}-\d{2}-\d{2}/', $ab ) && $anz > 0 ) {
		$d    = new DateTimeImmutable( str_replace( 'T', ' ', $ab ), new DateTimeZone( 'UTC' ) );
		$step = array( 'woche' => '+1 week', '2wochen' => '+2 weeks', 'monat' => '+1 month' )[ $rh ] ?? '+1 week';
		for ( $i = 0; $i < $anz; $i++ ) {
			$out[] = array( 'beginn' => $d->format( 'Y-m-d\TH:i' ), 'ort' => '', 'notiz' => '' );
			$d     = $d->modify( $step );
		}
	}
	// Doppelte Zeitpunkte zusammenfassen, sortieren.
	$u = array();
	foreach ( $out as $t ) {
		$u[ $t['beginn'] ] = isset( $u[ $t['beginn'] ] ) && '' === $t['ort'] ? $u[ $t['beginn'] ] : $t;
	}
	ksort( $u );
	return array_values( $u );
}

/** Fortschritt: [material_fertig, material_gesamt, kanaele_fertig, kanaele_gesamt] (nur Pflichtmaterial). */
function vp_ev_fortschritt( $post_id ) {
	$mf = $mg = 0;
	foreach ( vp_ev_material_fuer( $post_id ) as $m ) {
		if ( empty( $m['pflicht'] ) ) {
			continue;
		}
		$mg++;
		$mf += vp_ev_material_fertig( $post_id, $m ) ? 1 : 0;
	}
	$k  = vp_ev_gewaehlt( $post_id );
	$st = vp_ev_kanal_status( $post_id );
	if ( vp_ev_je_termin( $post_id ) ) {
		$soll = array();
		foreach ( array_keys( vp_ev_termine( $post_id ) ) as $i ) {
			foreach ( $k as $kk ) {
				$soll[] = vp_ev_status_key( $kk, $i );
			}
		}
		return array( $mf, $mg, count( array_intersect( $soll, array_keys( $st ) ) ), count( $soll ) );
	}
	$kf = count( array_intersect( $k, array_keys( $st ) ) );
	return array( $mf, $mg, $kf, count( $k ) );
}

/* =========================================================================
 * Nextcloud: ein Ordner je Veranstaltung
 * Zugang wie die Belegablage (Buchhaltung → Einstellungen), Basisordner
 * einstellbar (Option vp_ev_nc_basis, Standard „Veranstaltungen“).
 * ====================================================================== */

function vp_ev_nc_cfg() {
	$url  = rtrim( (string) get_option( 'jb_nc_url', '' ), '/' );
	$user = trim( (string) get_option( 'jb_nc_user', '' ) );
	$pass = (string) get_option( 'jb_nc_password', '' );
	return ( $url && $user && $pass ) ? array( 'url' => $url, 'user' => $user, 'pass' => $pass ) : null;
}

function vp_ev_nc_basis() {
	return trim( (string) get_option( 'vp_ev_nc_basis', 'Veranstaltungen' ), '/' ) ?: 'Veranstaltungen';
}

function vp_ev_nc_dav( $pfad ) {
	$c = vp_ev_nc_cfg();
	$teile = array_map( 'rawurlencode', explode( '/', trim( $pfad, '/' ) ) );
	return $c['url'] . '/remote.php/dav/files/' . rawurlencode( $c['user'] ) . '/' . implode( '/', $teile );
}

function vp_ev_nc_req( $method, $pfad, $body = null ) {
	$c = vp_ev_nc_cfg();
	$r = wp_remote_request( vp_ev_nc_dav( $pfad ), array(
		'method'  => $method,
		'timeout' => 30,
		'headers' => array( 'Authorization' => 'Basic ' . base64_encode( $c['user'] . ':' . $c['pass'] ) ),
		'body'    => $body,
	) );
	return is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
}

/** Ordner (mit allen Elternordnern) anlegen. */
function vp_ev_nc_ordner_anlegen( $pfad ) {
	$weg = '';
	foreach ( explode( '/', trim( $pfad, '/' ) ) as $teil ) {
		$weg  .= '/' . $teil;
		$code  = vp_ev_nc_req( 'MKCOL', $weg );
		if ( ! in_array( $code, array( 201, 405 ), true ) ) { // 405 = gibt es schon
			return false;
		}
	}
	return true;
}

/** Ordnername einer Veranstaltung: „2026-07-17 Sommerfest“. */
function vp_ev_nc_ordner_name( $post_id ) {
	$d     = substr( (string) get_post_meta( $post_id, '_jbf_date_start', true ), 0, 10 );
	$titel = trim( preg_replace( '/[\\\\\/:*?"<>|#%]+/', ' ', get_the_title( $post_id ) ) );
	return trim( ( $d ? $d . ' ' : '' ) . $titel ) ?: 'Veranstaltung-' . (int) $post_id;
}

/** Link zum Ordner in der Nextcloud-Oberfläche. */
function vp_ev_nc_link( $pfad ) {
	$c = vp_ev_nc_cfg();
	return $c ? $c['url'] . '/apps/files/?dir=' . rawurlencode( '/' . trim( $pfad, '/' ) ) : '';
}

/** Datei in den Veranstaltungsordner kopieren (falls angelegt). */
function vp_ev_nc_ablegen( $post_id, $lokal, $name ) {
	$ordner = (string) get_post_meta( $post_id, '_vp_ev_nc_ordner', true );
	if ( '' === $ordner || ! vp_ev_nc_cfg() || ! is_readable( $lokal ) ) {
		return false;
	}
	$code = vp_ev_nc_req( 'PUT', $ordner . '/' . $name, file_get_contents( $lokal ) );
	return in_array( $code, array( 201, 204 ), true );
}

/* =========================================================================
 * Aktionen
 * ====================================================================== */

add_action( 'init', 'vp_ev_aktionen', 20 );
function vp_ev_aktionen() {
	if ( empty( $_POST['vp_ev_aktion'] ) || ! is_user_logged_in() || ! vp_ev_verfuegbar() ) {
		return;
	}
	check_admin_referer( 'vp_ev', 'vp_ev_nonce' );
	$aktion = sanitize_key( wp_unslash( $_POST['vp_ev_aktion'] ) );
	$id     = (int) ( $_POST['post_id'] ?? 0 );
	$back   = remove_query_arg( array( 'vp_ev_msg', 'vp_ev_err' ), wp_get_referer() ?: home_url( '/' ) );
	$msg    = '';
	$err    = '';

	if ( 'einstellungen' === $aktion ) {
		if ( current_user_can( 'jbf_send_external' ) || current_user_can( 'manage_options' ) ) {
			update_option( 'vp_ev_nc_basis', sanitize_text_field( wp_unslash( $_POST['nc_basis'] ?? 'Veranstaltungen' ) ) );
			$msg = __( 'Gespeichert.', 'vereinsplugin' );
		}
		wp_safe_redirect( add_query_arg( 'vp_ev_msg', rawurlencode( $msg ), $back ) );
		exit;
	}

	if ( 'zugaenge' === $aktion ) {
		if ( current_user_can( 'manage_options' ) && class_exists( 'Jbf_Settings' ) ) {
			$alt   = Jbf_Settings::get();
			$neu   = array_map( 'strval', (array) wp_unslash( $_POST['jbf'] ?? array() ) );
			$daten = array();
			foreach ( $alt as $k => $v ) {
				$daten[ $k ] = array_key_exists( $k, $neu ) ? $neu[ $k ] : $v;
				// Geheime Felder: leer gelassen = bisheriger Wert bleibt.
				if ( in_array( $k, vp_ev_geheime_felder(), true ) && '' === trim( $daten[ $k ] ) && empty( $_POST['jbf_leeren'][ $k ] ) ) {
					$daten[ $k ] = $v;
				}
			}
			update_option( Jbf_Settings::OPTION_KEY, Jbf_Settings::sanitize( $daten ) );
			$msg = __( 'Zugänge gespeichert.', 'vereinsplugin' );
		}
		wp_safe_redirect( add_query_arg( 'vp_ev_msg', rawurlencode( $msg ), $back ) );
		exit;
	}

	if ( 'neu' === $aktion ) {
		if ( ! current_user_can( 'jbf_edit_events' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) );
		}
		$id = 0;
	} elseif ( ! $id || 'veranstaltung' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
		wp_die( esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) );
	}
	$p = wp_unslash( $_POST );

	switch ( $aktion ) {
		case 'neu':
		case 'daten':
			$titel = sanitize_text_field( (string) ( $p['titel'] ?? '' ) );
			if ( '' === $titel ) {
				$err = __( 'Bitte einen Titel angeben.', 'vereinsplugin' );
				break;
			}
			$post = array(
				'post_type'    => 'veranstaltung',
				'post_title'   => $titel,
				'post_content' => wp_kses_post( (string) ( $p['beschreibung'] ?? '' ) ),
				'post_excerpt' => sanitize_textarea_field( (string) ( $p['kurz'] ?? '' ) ),
				'post_status'  => ! empty( $p['sichtbar'] ) ? 'publish' : 'draft',
			);
			if ( $id ) {
				$post['ID'] = $id;
				wp_update_post( $post );
			} else {
				$id = (int) wp_insert_post( $post );
			}
			update_post_meta( $id, '_jbf_location', sanitize_text_field( (string) ( $p['ort'] ?? '' ) ) );
			if ( 'reihe' === ( $p['art'] ?? '' ) ) {
				$termine = vp_ev_termine_aus_post( $p );
				update_post_meta( $id, '_vp_ev_termine', $termine );
				update_post_meta( $id, '_jbf_date_start', $termine ? $termine[0]['beginn'] : '' );
				update_post_meta( $id, '_vp_ev_je_termin', ! empty( $p['je_termin'] ) ? '1' : '0' );
				update_post_meta( $id, '_vp_ev_termine_anhaengen', ! empty( $p['anhaengen'] ) ? '1' : '0' );
				update_post_meta( $id, '_vp_ev_art', 'reihe' );
			} else {
				delete_post_meta( $id, '_vp_ev_termine' );
				update_post_meta( $id, '_vp_ev_art', 'einzeln' );
				update_post_meta( $id, '_jbf_date_start', sanitize_text_field( (string) ( $p['beginn'] ?? '' ) ) );
			}
			$k = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $p['kanaele'] ?? array() ) ), array_keys( vp_ev_kanaele() ) ) );
			update_post_meta( $id, '_jbf_channels', $k );
			$back = add_query_arg( 'vp_ev', $id, remove_query_arg( 'vp_ev', $back ) );
			$msg  = __( 'Gespeichert.', 'vereinsplugin' );
			break;

		case 'material':
			$m = vp_ev_material();
			foreach ( (array) ( $p['m'] ?? array() ) as $mid => $wert ) {
				if ( isset( $m[ $mid ] ) && in_array( $m[ $mid ]['typ'], array( 'text', 'textarea' ), true ) ) {
					update_post_meta( $id, $m[ $mid ]['meta'], sanitize_textarea_field( (string) $wert ) );
				}
			}
			foreach ( (array) ( $p['canva'] ?? array() ) as $mid => $link ) {
				if ( isset( $m[ $mid ] ) ) {
					update_post_meta( $id, '_vp_ev_canva_' . sanitize_key( $mid ), esc_url_raw( trim( (string) $link ) ) );
				}
			}
			$msg = __( 'Material gespeichert.', 'vereinsplugin' );
			break;

		case 'datei':
			$mid = sanitize_key( (string) ( $p['material'] ?? '' ) );
			$m   = vp_ev_material()[ $mid ] ?? null;
			if ( ! $m || empty( $_FILES['datei']['name'] ) ) {
				$err = __( 'Keine Datei gewählt.', 'vereinsplugin' );
				break;
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$att = media_handle_upload( 'datei', $id );
			if ( is_wp_error( $att ) ) {
				$err = $att->get_error_message();
				break;
			}
			update_post_meta( $id, $m['meta'], (int) $att );
			$datei = get_attached_file( $att );
			$nc    = vp_ev_nc_ablegen( $id, $datei, $mid . '-' . basename( (string) $datei ) );
			$msg   = __( 'Datei hochgeladen.', 'vereinsplugin' ) . ( $nc ? ' ' . __( 'Kopie liegt im Nextcloud-Ordner.', 'vereinsplugin' ) : '' );
			break;

		case 'datei_weg':
			$mid = sanitize_key( (string) ( $p['material'] ?? '' ) );
			$m   = vp_ev_material()[ $mid ] ?? null;
			if ( $m ) {
				delete_post_meta( $id, $m['meta'] );
				$msg = __( 'Datei entfernt (bleibt in der Mediathek).', 'vereinsplugin' );
			}
			break;

		case 'nc_ordner':
			if ( ! vp_ev_nc_cfg() ) {
				$err = __( 'Nextcloud ist nicht eingerichtet (Buchhaltung → Einstellungen → Nextcloud).', 'vereinsplugin' );
				break;
			}
			$pfad = vp_ev_nc_basis() . '/' . vp_ev_nc_ordner_name( $id );
			if ( ! vp_ev_nc_ordner_anlegen( $pfad ) ) {
				$err = __( 'Nextcloud-Ordner konnte nicht angelegt werden.', 'vereinsplugin' );
				break;
			}
			update_post_meta( $id, '_vp_ev_nc_ordner', $pfad );
			// Schon hochgeladene Dateien gleich mit ablegen.
			foreach ( vp_ev_material() as $mid => $m ) {
				$att = in_array( $m['typ'], array( 'bild', 'video', 'datei' ), true ) ? (int) get_post_meta( $id, $m['meta'], true ) : 0;
				if ( $att && ( $f = get_attached_file( $att ) ) ) {
					vp_ev_nc_ablegen( $id, $f, $mid . '-' . basename( $f ) );
				}
			}
			$msg = __( 'Nextcloud-Ordner angelegt.', 'vereinsplugin' );
			break;

		case 'kanal':
			$kanal  = sanitize_key( (string) ( $p['kanal'] ?? '' ) );
			$termin = isset( $p['termin'] ) && '' !== (string) $p['termin'] ? (int) $p['termin'] : null;
			if ( isset( vp_ev_kanaele()[ $kanal ] ) ) {
				vp_ev_kanal_setzen( $id, vp_ev_status_key( $kanal, $termin ), ! empty( $p['erledigt'] ), (string) ( $p['link'] ?? '' ) );
				$msg = __( 'Kanal aktualisiert.', 'vereinsplugin' );
			}
			break;

		case 'senden':
			if ( ! class_exists( 'Jbf_Roles' ) || ! Jbf_Roles::can_send_external() ) {
				$err = __( 'Nur der Vorstand darf an externe Kanäle senden – bitte zur Freigabe einreichen.', 'vereinsplugin' );
				break;
			}
			$ziel   = sanitize_key( (string) ( $p['kanal'] ?? '' ) );
			$termin = isset( $p['termin'] ) && '' !== (string) $p['termin'] ? (int) $p['termin'] : null;
			$con    = Jbf_Publisher::connectors();
			$status = vp_ev_kanal_status( $id );
			$offen  = array_filter( vp_ev_gewaehlt( $id ), static function ( $k ) use ( $status, $termin ) {
				return ! isset( $status[ vp_ev_status_key( $k, $termin ) ] );
			} );
			$kanaele = '' !== $ziel ? array( $ziel ) : $offen;
			$daten  = vp_ev_versanddaten( $id, $termin );
			$cfg    = class_exists( 'Jbf_Settings' ) ? Jbf_Settings::get() : array();
			$log    = (array) get_post_meta( $id, '_jbf_publish_log', true );
			$ok = $fehl = array();
			foreach ( $kanaele as $k ) {
				if ( ! isset( $con[ $k ] ) ) {
					continue;
				}
				$c   = new $con[ $k ]();
				$res = $c->publish( $daten, $cfg );
				$log[] = array( 'channel' => vp_ev_kanaele()[ $k ] ?? $k, 'success' => ! empty( $res['success'] ), 'message' => (string) ( $res['message'] ?? '' ), 'time' => current_time( 'mysql' ) );
				if ( ! empty( $res['success'] ) ) {
					vp_ev_kanal_setzen( $id, vp_ev_status_key( $k, $termin ), true, '', __( 'automatisch gesendet', 'vereinsplugin' ) );
					$ok[] = vp_ev_kanaele()[ $k ] ?? $k;
				} else {
					$fehl[] = ( vp_ev_kanaele()[ $k ] ?? $k ) . ': ' . (string) ( $res['message'] ?? '' );
				}
			}
			update_post_meta( $id, '_jbf_publish_log', $log );
			update_post_meta( $id, '_jbf_review_status', 'sent' );
			update_post_meta( $id, '_jbf_sent_by', get_current_user_id() );
			update_post_meta( $id, '_jbf_sent_at', current_time( 'mysql' ) );
			if ( $ok ) {
				$msg = sprintf( __( 'Gesendet: %s.', 'vereinsplugin' ), implode( ', ', $ok ) );
			}
			if ( $fehl ) {
				$err = sprintf( __( 'Fehlgeschlagen: %s', 'vereinsplugin' ), implode( ' · ', $fehl ) );
			}
			if ( ! $ok && ! $fehl ) {
				$msg = __( 'Nichts zu senden.', 'vereinsplugin' );
			}
			break;

		case 'loeschen':
			if ( ! current_user_can( 'delete_post', $id ) ) {
				$err = __( 'Keine Berechtigung zum Löschen.', 'vereinsplugin' );
				break;
			}
			if ( class_exists( 'Jbf_Campaign' ) ) {
				Jbf_Campaign::unschedule_all( $id );
			}
			$titel = get_the_title( $id );
			wp_trash_post( $id );
			$back = remove_query_arg( 'vp_ev', $back );
			$msg  = sprintf( __( '„%s“ liegt im Papierkorb (unten in der Liste wiederherstellbar).', 'vereinsplugin' ), $titel );
			break;

		case 'wiederherstellen':
			if ( 'trash' !== get_post_status( $id ) || ! current_user_can( 'delete_post', $id ) ) {
				$err = __( 'Keine Berechtigung.', 'vereinsplugin' );
				break;
			}
			wp_untrash_post( $id );
			$back = add_query_arg( 'vp_ev', $id, remove_query_arg( 'vp_ev', $back ) );
			$msg  = __( 'Wiederhergestellt – als Entwurf. Zum Veröffentlichen „Auf der Website veröffentlichen“ anhaken und speichern.', 'vereinsplugin' );
			break;

		case 'endgueltig':
			if ( 'trash' !== get_post_status( $id ) || ! current_user_can( 'delete_post', $id ) ) {
				$err = __( 'Keine Berechtigung.', 'vereinsplugin' );
				break;
			}
			$titel = get_the_title( $id );
			wp_delete_post( $id, true );
			$back = remove_query_arg( 'vp_ev', $back );
			$msg  = sprintf( __( '„%s“ endgültig gelöscht. Hochgeladene Bilder bleiben in der Mediathek und im Nextcloud-Ordner.', 'vereinsplugin' ), $titel );
			break;

		case 'kampagne':
			if ( ! class_exists( 'Jbf_Campaign' ) ) {
				break;
			}
			$zeilen = array();
			foreach ( (array) ( $_POST['jbf_campaign'] ?? array() ) as $i => $z ) {
				if ( ! empty( $z['weg'] ) ) {
					$z['label'] = '';
				}
				$zeilen[ $i ] = $z;
			}
			if ( ! empty( $p['vorlage'] ) ) {
				$auto = array_values( array_intersect( vp_ev_gewaehlt( $id ), vp_ev_auto_kanaele() ) );
				foreach ( Jbf_Campaign::template_steps() as $t ) {
					$zeilen[] = $t + array( 'channels' => $auto, 'text_override' => '' );
				}
			}
			$_POST['jbf_campaign'] = $zeilen; // save_from_request() liest $_POST
			Jbf_Campaign::save_from_request( $id );
			update_post_meta( $id, '_jbf_timing_mode', Jbf_Campaign::get_steps( $id ) ? 'staggered' : 'simultaneous' );
			$msg = __( 'Zeitplan gespeichert. Geplante Schritte wurden angehalten – bitte neu einplanen.', 'vereinsplugin' );
			break;

		case 'kampagne_einplanen':
		case 'kampagne_stoppen':
			if ( ! class_exists( 'Jbf_Roles' ) || ! Jbf_Roles::can_send_external() || ! class_exists( 'Jbf_Campaign' ) ) {
				$err = __( 'Nur der Vorstand darf Kampagnen einplanen.', 'vereinsplugin' );
				break;
			}
			if ( 'kampagne_stoppen' === $aktion ) {
				Jbf_Campaign::unschedule_all( $id );
				$msg = __( 'Kampagne gestoppt – die Schritte sind wieder offen.', 'vereinsplugin' );
				break;
			}
			$ok = $fehl = array();
			foreach ( Jbf_Campaign::schedule_all( $id ) as $r ) {
				if ( $r['ok'] ) {
					$ok[] = $r['label'] . ': ' . $r['message'];
				} else {
					$fehl[] = $r['label'] . ': ' . $r['message'];
				}
			}
			$msg = $ok ? implode( ' · ', $ok ) : ( $fehl ? '' : __( 'Keine offenen Schritte.', 'vereinsplugin' ) );
			$err = implode( ' · ', $fehl );
			break;

		case 'freigabe':
			update_post_meta( $id, '_jbf_review_status', 'ready' );
			update_post_meta( $id, '_jbf_submitted_by', get_current_user_id() );
			update_post_meta( $id, '_jbf_submitted_at', current_time( 'mysql' ) );
			$msg = __( 'Zur Freigabe eingereicht – der Vorstand sendet die automatischen Kanäle.', 'vereinsplugin' );
			break;
	}
	$back = add_query_arg( $err ? 'vp_ev_err' : 'vp_ev_msg', rawurlencode( $err ?: $msg ), $back );
	if ( $err && $msg ) {
		$back = add_query_arg( 'vp_ev_msg', rawurlencode( $msg ), $back );
	}
	wp_safe_redirect( $back );
	exit;
}

/**
 * Datenpaket für die Connectoren. Bei Reihen:
 *  - je Termin: Datum/Ort dieses Termins, Text beginnt mit dem Termin;
 *  - sonst (ganze Reihe): auf Wunsch Terminliste an die Texte angehängt,
 *    für die Presse steht die Liste als „Termin“.
 */
function vp_ev_versanddaten( $id, $termin = null ) {
	$d = Jbf_Publisher::build_event_data( $id );
	if ( ! vp_ev_ist_reihe( $id ) ) {
		return $d;
	}
	$felder  = array( 'short_text', 'telegram_text', 'instagram_caption', 'facebook_text', 'whatsapp_signal_text', 'fb_event_text' );
	$termine = vp_ev_termine( $id );
	if ( null !== $termin && isset( $termine[ $termin ] ) ) {
		$t                = $termine[ $termin ];
		$zeile            = '📅 ' . vp_ev_termin_text( $t, $id );
		$d['date_start']  = $t['beginn'];
		$d['location']    = '' !== trim( (string) $t['ort'] ) ? $t['ort'] : $d['location'];
		foreach ( $felder as $f ) {
			if ( '' !== trim( (string) ( $d[ $f ] ?? '' ) ) ) {
				$d[ $f ] = $zeile . "\n\n" . $d[ $f ];
			}
		}
		return $d;
	}
	if ( '1' === (string) get_post_meta( $id, '_vp_ev_termine_anhaengen', true ) ) {
		$liste = vp_ev_terminliste_text( $id, true );
		foreach ( $felder as $f ) {
			if ( '' !== trim( (string) ( $d[ $f ] ?? '' ) ) ) {
				$d[ $f ] .= "\n\n" . __( 'Termine:', 'vereinsplugin' ) . "\n" . $liste;
			}
		}
	}
	$d['date_start'] = "\n" . vp_ev_terminliste_text( $id );
	return $d;
}

/* =========================================================================
 * Ansicht
 * ====================================================================== */

function vp_ev_kopf( $aktion, $post_id = 0 ) {
	return wp_nonce_field( 'vp_ev', 'vp_ev_nonce', true, false ) . '<input type="hidden" name="vp_ev_aktion" value="' . esc_attr( $aktion ) . '"><input type="hidden" name="post_id" value="' . (int) $post_id . '">';
}

function vp_render_veranstaltungen_section() {
	if ( ! vp_ev_verfuegbar() ) {
		return '<div class="vp-note">' . esc_html__( 'Das Modul „Veranstaltungs-Publisher“ ist nicht aktiv.', 'vereinsplugin' ) . '</div>';
	}
	$out = '';
	if ( ! empty( $_GET['vp_ev_msg'] ) ) {
		$out .= '<div class="vp-note">' . esc_html( sanitize_text_field( wp_unslash( $_GET['vp_ev_msg'] ) ) ) . '</div>';
	}
	if ( ! empty( $_GET['vp_ev_err'] ) ) {
		$out .= '<div class="vp-note vp-note-error">' . esc_html( sanitize_text_field( wp_unslash( $_GET['vp_ev_err'] ) ) ) . '</div>';
	}
	$ev = isset( $_GET['vp_ev'] ) ? sanitize_key( wp_unslash( $_GET['vp_ev'] ) ) : '';
	if ( 'neu' === $ev ) {
		return $out . vp_ev_editor( 0 );
	}
	if ( (int) $ev && 'veranstaltung' === get_post_type( (int) $ev ) && 'trash' !== get_post_status( (int) $ev ) ) {
		return $out . vp_ev_editor( (int) $ev );
	}
	return $out . vp_ev_liste();
}

function vp_ev_datum( $post_id ) {
	$d = (string) get_post_meta( $post_id, '_jbf_date_start', true );
	return $d ? date_i18n( 'D, d.m.Y H:i', strtotime( $d ) ) : '–';
}

function vp_ev_liste() {
	$posts = get_posts( array( 'post_type' => 'veranstaltung', 'post_status' => array( 'publish', 'draft', 'pending', 'future' ), 'numberposts' => 200 ) );
	// Nach Datum, neueste zuerst; ohne Datum ans Ende (nicht über meta_key, sonst fehlen sie ganz).
	usort( $posts, static function ( $a, $b ) {
		return strcmp( (string) get_post_meta( $b->ID, '_jbf_date_start', true ), (string) get_post_meta( $a->ID, '_jbf_date_start', true ) );
	} );
	ob_start();
	echo '<h2>' . esc_html__( 'Veranstaltungen vermarkten', 'vereinsplugin' ) . '</h2>';
	echo '<p><a class="vp-btn vp-btn-primary" href="' . esc_url( vp_ev_url( array( 'vp_ev' => 'neu' ) ) ) . '">+ ' . esc_html__( 'Neue Veranstaltung', 'vereinsplugin' ) . '</a></p>';
	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Veranstaltung', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Datum', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Material', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Kanäle', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
	foreach ( $posts as $p ) {
		list( $mf, $mg, $kf, $kg ) = vp_ev_fortschritt( $p->ID );
		$balken = static function ( $a, $b ) {
			return $b ? sprintf( '<span class="vp-badge" style="background:%s">%d / %d</span>', $a >= $b ? '#dcfce7' : '#fef3c7', $a, $b ) : '<span class="vp-muted">–</span>';
		};
		printf(
			'<tr><td><a href="%s"><strong>%s</strong></a>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_url( vp_ev_url( array( 'vp_ev' => $p->ID ) ) ),
			esc_html( get_the_title( $p ) ),
			vp_ev_status_badges( $p ),
			esc_html( vp_ev_ist_reihe( $p->ID ) ? sprintf( __( 'Reihe · %1$d Termine ab %2$s', 'vereinsplugin' ), count( vp_ev_termine( $p->ID ) ), vp_ev_datum( $p->ID ) ) : vp_ev_datum( $p->ID ) ),
			$balken( $mf, $mg ), // phpcs:ignore
			$balken( $kf, $kg ) // phpcs:ignore
		);
	}
	if ( ! $posts ) {
		echo '<tr><td colspan="4" class="vp-muted">' . esc_html__( 'Noch keine Veranstaltungen.', 'vereinsplugin' ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
	if ( current_user_can( 'jbf_send_external' ) || current_user_can( 'manage_options' ) ) {
		echo '<details class="vp-card"><summary><strong>' . esc_html__( 'Einstellungen', 'vereinsplugin' ) . '</strong></summary><form method="post" class="vp-form">' . vp_ev_kopf( 'einstellungen' ); // phpcs:ignore
		echo '<label>' . esc_html__( 'Nextcloud-Basisordner für Veranstaltungsmaterial', 'vereinsplugin' ) . '<input type="text" name="nc_basis" value="' . esc_attr( vp_ev_nc_basis() ) . '"></label>';
		echo '<p class="vp-muted">' . esc_html__( 'Liegt im Nextcloud-Konto der Belegablage. Diesen Ordner einmal in Nextcloud mit der Gruppe teilen, die das Material bearbeitet – dann sehen alle die Unterordner je Veranstaltung.', 'vereinsplugin' ) . '</p>';
		echo '<p><button class="vp-btn">' . esc_html__( 'Speichern', 'vereinsplugin' ) . '</button></p></form></details>';
	}
	if ( current_user_can( 'manage_options' ) && class_exists( 'Jbf_Settings' ) ) {
		echo vp_ev_zugaenge_form(); // phpcs:ignore
	}
	echo vp_ev_papierkorb(); // phpcs:ignore
	return ob_get_clean();
}

/** Badges in der Liste: Entwurf, Freigabe, Kampagne. */
function vp_ev_status_badges( $p ) {
	$h = '';
	if ( 'publish' !== $p->post_status ) {
		$h .= ' <span class="vp-badge">' . esc_html__( 'Entwurf', 'vereinsplugin' ) . '</span>';
	}
	if ( 'ready' === get_post_meta( $p->ID, '_jbf_review_status', true ) ) {
		$h .= ' <span class="vp-badge" style="background:#fef3c7">' . esc_html__( 'Freigabe angefragt', 'vereinsplugin' ) . '</span>';
	}
	if ( class_exists( 'Jbf_Campaign' ) ) {
		$geplant = count( array_filter( Jbf_Campaign::get_steps( $p->ID ), static function ( $st ) {
			return 'scheduled' === ( $st['status'] ?? '' );
		} ) );
		if ( $geplant ) {
			$h .= ' <span class="vp-badge" style="background:#e0f2fe">' . esc_html( sprintf( _n( '%d Schritt eingeplant', '%d Schritte eingeplant', $geplant, 'vereinsplugin' ), $geplant ) ) . '</span>';
		}
	}
	return $h;
}

/** Felder der Zugänge, die nicht im Klartext angezeigt werden. */
function vp_ev_geheime_felder() {
	return array( 'mastodon_token', 'bluesky_app_password', 'telegram_bot_token', 'facebook_page_token', 'instagram_token', 'twitter_api_secret', 'twitter_access_token', 'twitter_access_secret' );
}

/** Zugänge für Social Media und Presse (nur Administrator:innen). */
function vp_ev_zugaenge_form() {
	$cfg     = Jbf_Settings::get();
	$gruppen = array(
		__( 'Allgemein', 'vereinsplugin' ) => array(
			'hashtag' => array( __( 'Fester Hashtag', 'vereinsplugin' ), __( 'Wird an Social-Kurztexte angehängt, falls nicht enthalten.', 'vereinsplugin' ) ),
		),
		'Mastodon' => array(
			'mastodon_instance' => array( __( 'Instanz-URL', 'vereinsplugin' ), 'z. B. https://mastodon.social' ),
			'mastodon_token'    => array( __( 'Access Token', 'vereinsplugin' ), __( 'Einstellungen → Entwicklung → Neue Anwendung, Scope „write“.', 'vereinsplugin' ) ),
		),
		'Bluesky' => array(
			'bluesky_handle'       => array( __( 'Handle', 'vereinsplugin' ), 'z. B. verein.bsky.social' ),
			'bluesky_app_password' => array( __( 'App-Passwort', 'vereinsplugin' ), __( 'Einstellungen → App-Passwörter, nicht das Hauptpasswort.', 'vereinsplugin' ) ),
		),
		'Telegram' => array(
			'telegram_bot_token' => array( __( 'Bot-Token', 'vereinsplugin' ), __( 'Von @BotFather.', 'vereinsplugin' ) ),
			'telegram_chat_id'   => array( __( 'Kanal-/Chat-ID', 'vereinsplugin' ), __( 'Bot muss Admin im Kanal sein, z. B. @euerkanal oder -100…', 'vereinsplugin' ) ),
		),
		__( 'Facebook-Seite', 'vereinsplugin' ) => array(
			'facebook_page_id'    => array( 'Page ID', '' ),
			'facebook_page_token' => array( __( 'Page Access Token (langlebig)', 'vereinsplugin' ), __( 'Über den Graph API Explorer von Meta.', 'vereinsplugin' ) ),
		),
		'Instagram' => array(
			'instagram_account_id' => array( __( 'Business Account ID', 'vereinsplugin' ), __( 'Muss mit der Facebook-Seite verknüpft sein.', 'vereinsplugin' ) ),
			'instagram_token'      => array( __( 'Access Token', 'vereinsplugin' ), __( 'Meist derselbe wie Facebook, mit Instagram-Rechten.', 'vereinsplugin' ) ),
		),
		'X / Twitter' => array(
			'twitter_api_key'       => array( 'API Key', '' ),
			'twitter_api_secret'    => array( 'API Secret', '' ),
			'twitter_access_token'  => array( 'Access Token', __( 'User-Context-Token mit Schreibrechten.', 'vereinsplugin' ) ),
			'twitter_access_secret' => array( 'Access Token Secret', '' ),
		),
		__( 'Signal (über signal-cli-rest-api)', 'vereinsplugin' ) => array(
			'signal_webhook_url' => array( __( 'REST-API-Endpunkt', 'vereinsplugin' ), 'z. B. https://euer-server:8080' ),
			'signal_number'      => array( __( 'Registrierte Nummer', 'vereinsplugin' ), '' ),
			'signal_group_id'    => array( __( 'Gruppen-ID', 'vereinsplugin' ), __( 'Aus „listGroups“.', 'vereinsplugin' ) ),
		),
		__( 'Presseverteiler', 'vereinsplugin' ) => array(
			'press_from_name'       => array( __( 'Absendername', 'vereinsplugin' ), '' ),
			'press_from_email'      => array( __( 'Absender-E-Mail', 'vereinsplugin' ), __( 'Für zuverlässigen Versand ein SMTP-Plugin nutzen.', 'vereinsplugin' ) ),
			'press_recipients'      => array( __( 'Empfänger (eine Adresse pro Zeile)', 'vereinsplugin' ), '', 'textarea' ),
			'press_contact_default' => array( __( 'Standard-Kontakt für Presseanfragen', 'vereinsplugin' ), __( 'Gilt für jede Veranstaltung, wenn dort kein eigener Kontakt steht.', 'vereinsplugin' ), 'textarea' ),
		),
	);
	$geheim = vp_ev_geheime_felder();
	$h      = '<details class="vp-card"><summary><strong>' . esc_html__( 'Zugänge für Social Media und Presse', 'vereinsplugin' ) . '</strong> <span class="vp-muted">(' . esc_html__( 'nur Administrator:innen', 'vereinsplugin' ) . ')</span></summary>';
	$h     .= '<form method="post" class="vp-form" autocomplete="off">' . vp_ev_kopf( 'zugaenge' );
	foreach ( $gruppen as $titel => $felder ) {
		$h .= '<h4 style="margin:14px 0 4px">' . esc_html( $titel ) . '</h4><div class="vp-form-grid">';
		foreach ( $felder as $k => $f ) {
			$wert = (string) ( $cfg[ $k ] ?? '' );
			$h   .= '<label' . ( isset( $f[2] ) ? ' class="vp-col-2"' : '' ) . '>' . esc_html( $f[0] );
			if ( isset( $f[2] ) ) {
				$h .= '<textarea name="jbf[' . esc_attr( $k ) . ']" rows="4">' . esc_textarea( $wert ) . '</textarea>';
			} elseif ( in_array( $k, $geheim, true ) ) {
				$h .= '<input type="password" name="jbf[' . esc_attr( $k ) . ']" value="" placeholder="' . esc_attr( '' !== $wert ? __( '•••••• gespeichert – leer lassen = unverändert', 'vereinsplugin' ) : '' ) . '" autocomplete="new-password">';
				if ( '' !== $wert ) {
					$h .= '<span style="font-weight:normal;font-size:.85em"><input type="checkbox" name="jbf_leeren[' . esc_attr( $k ) . ']" value="1"> ' . esc_html__( 'löschen', 'vereinsplugin' ) . '</span>';
				}
			} else {
				$h .= '<input type="text" name="jbf[' . esc_attr( $k ) . ']" value="' . esc_attr( $wert ) . '">';
			}
			if ( '' !== $f[1] ) {
				$h .= '<span class="vp-muted" style="font-weight:normal">' . esc_html( $f[1] ) . '</span>';
			}
			$h .= '</label>';
		}
		$h .= '</div>';
	}
	return $h . '<p><button class="vp-btn vp-btn-primary">' . esc_html__( 'Zugänge speichern', 'vereinsplugin' ) . '</button></p></form></details>';
}

/** Papierkorb: gelöschte Veranstaltungen wiederherstellen oder endgültig löschen. */
function vp_ev_papierkorb() {
	$weg = get_posts( array( 'post_type' => 'veranstaltung', 'post_status' => 'trash', 'numberposts' => 100 ) );
	$weg = array_filter( $weg, static function ( $p ) {
		return current_user_can( 'delete_post', $p->ID );
	} );
	if ( ! $weg ) {
		return '';
	}
	$h = '<details class="vp-card"><summary><strong>🗑 ' . esc_html( sprintf( __( 'Papierkorb (%d)', 'vereinsplugin' ), count( $weg ) ) ) . '</strong></summary><div class="vp-table-wrap"><table class="vp-table"><tbody>';
	foreach ( $weg as $p ) {
		$h .= '<tr><td><strong>' . esc_html( get_the_title( $p ) ) . '</strong><br><span class="vp-muted">' . esc_html( vp_ev_datum( $p->ID ) ) . '</span></td><td style="text-align:right;white-space:nowrap">'
			. '<form method="post" style="display:inline">' . vp_ev_kopf( 'wiederherstellen', $p->ID ) . '<button class="vp-btn">↺ ' . esc_html__( 'Wiederherstellen', 'vereinsplugin' ) . '</button></form> '
			. '<form method="post" style="display:inline">' . vp_ev_kopf( 'endgueltig', $p->ID ) . '<button class="vp-btn" onclick="return confirm(\'' . esc_js( __( 'Endgültig löschen? Das lässt sich nicht rückgängig machen.', 'vereinsplugin' ) ) . '\')">' . esc_html__( 'Endgültig löschen', 'vereinsplugin' ) . '</button></form>'
			. '</td></tr>';
	}
	return $h . '</tbody></table></div><p class="vp-muted">' . esc_html__( 'WordPress leert den Papierkorb nach 30 Tagen automatisch.', 'vereinsplugin' ) . '</p></details>';
}

function vp_ev_editor( $id ) {
	$kanaele = vp_ev_kanaele();
	$auto    = vp_ev_auto_kanaele();
	$gew     = $id ? vp_ev_gewaehlt( $id ) : array();
	$post    = $id ? get_post( $id ) : null;
	$beginn  = $id ? (string) get_post_meta( $id, '_jbf_date_start', true ) : '';
	ob_start();
	echo '<p><a href="' . esc_url( vp_ev_url() ) . '">‹ ' . esc_html__( 'Alle Veranstaltungen', 'vereinsplugin' ) . '</a></p>';
	echo '<h2>' . esc_html( $id ? get_the_title( $id ) : __( 'Neue Veranstaltung', 'vereinsplugin' ) ) . '</h2>';
	if ( $id ) {
		list( $mf, $mg, $kf, $kg ) = vp_ev_fortschritt( $id );
		echo '<p class="vp-muted">' . esc_html( sprintf( __( 'Material %1$d / %2$d · Kanäle %3$d / %4$d erledigt', 'vereinsplugin' ), $mf, $mg, $kf, $kg ) );
		if ( 'publish' === $post->post_status ) {
			echo ' · <a href="' . esc_url( get_permalink( $id ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Seite ansehen', 'vereinsplugin' ) . '</a>';
		}
		echo '</p>';
	}

	/* ---- 1. Daten ---- */
	echo '<div class="vp-card"><h3 style="margin-top:0">1 · ' . esc_html__( 'Veranstaltungsdaten', 'vereinsplugin' ) . '</h3>';
	echo '<form method="post" class="vp-form">' . vp_ev_kopf( $id ? 'daten' : 'neu', $id ); // phpcs:ignore
	echo '<div class="vp-form-grid">';
	echo '<label class="vp-col-2">' . esc_html__( 'Titel', 'vereinsplugin' ) . '<input type="text" name="titel" required value="' . esc_attr( $post ? $post->post_title : '' ) . '"></label>';
	$reihe = $id && 'reihe' === get_post_meta( $id, '_vp_ev_art', true );
	echo '<div class="vp-col-2" style="display:flex;gap:16px;flex-wrap:wrap">'
		. '<label style="font-weight:normal"><input type="radio" name="art" value="einzeln" data-vp-ev-art ' . checked( ! $reihe, true, false ) . '> ' . esc_html__( 'Einzeltermin', 'vereinsplugin' ) . '</label>'
		. '<label style="font-weight:normal"><input type="radio" name="art" value="reihe" data-vp-ev-art ' . checked( $reihe, true, false ) . '> ' . esc_html__( 'Veranstaltungsreihe (mehrere Termine)', 'vereinsplugin' ) . '</label></div>';
	echo '<label data-vp-ev-nur="einzeln">' . esc_html__( 'Beginn (Datum & Uhrzeit)', 'vereinsplugin' ) . '<input type="datetime-local" name="beginn" value="' . esc_attr( $beginn ? substr( str_replace( ' ', 'T', $beginn ), 0, 16 ) : '' ) . '"></label>';
	echo '<label>' . esc_html__( 'Ort (Standard für alle Termine)', 'vereinsplugin' ) . '<input type="text" name="ort" value="' . esc_attr( $id ? (string) get_post_meta( $id, '_jbf_location', true ) : '' ) . '"></label>';
	// Termine der Reihe
	$termine = $reihe ? vp_ev_termine( $id ) : array();
	echo '<div class="vp-col-2" data-vp-ev-nur="reihe"><strong>' . esc_html__( 'Termine', 'vereinsplugin' ) . '</strong>'
		. '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Datum & Uhrzeit', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Ort (leer = Standardort)', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Hinweis (z. B. „mit Gast“)', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
	$zeilen = array_merge( $termine, array_fill( 0, 3, array( 'beginn' => '', 'ort' => '', 'notiz' => '' ) ) );
	foreach ( $zeilen as $i => $t ) {
		printf(
			'<tr><td><input type="datetime-local" name="termine[%1$d][beginn]" value="%2$s"></td><td><input type="text" name="termine[%1$d][ort]" value="%3$s"></td><td><input type="text" name="termine[%1$d][notiz]" value="%4$s"></td></tr>',
			(int) $i,
			esc_attr( substr( str_replace( ' ', 'T', (string) $t['beginn'] ), 0, 16 ) ),
			esc_attr( (string) $t['ort'] ),
			esc_attr( (string) $t['notiz'] )
		);
	}
	echo '</tbody></table></div><p class="vp-muted">' . esc_html__( 'Termin löschen: Datum leeren und speichern. Drei leere Zeilen für neue Termine – nach dem Speichern kommen neue dazu.', 'vereinsplugin' ) . '</p>';
	echo '<details><summary>' . esc_html__( 'Serie erzeugen (z. B. jeden Freitag)', 'vereinsplugin' ) . '</summary><div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-top:6px">'
		. '<label>' . esc_html__( 'Erster Termin', 'vereinsplugin' ) . '<input type="datetime-local" name="serie_ab"></label>'
		. '<label>' . esc_html__( 'Rhythmus', 'vereinsplugin' ) . '<select name="serie_rhythmus"><option value="woche">' . esc_html__( 'wöchentlich', 'vereinsplugin' ) . '</option><option value="2wochen">' . esc_html__( 'alle 14 Tage', 'vereinsplugin' ) . '</option><option value="monat">' . esc_html__( 'monatlich', 'vereinsplugin' ) . '</option></select></label>'
		. '<label>' . esc_html__( 'Anzahl', 'vereinsplugin' ) . '<input type="number" name="serie_anzahl" min="0" max="52" value="0" style="width:80px"></label>'
		. '</div><p class="vp-muted">' . esc_html__( 'Wird beim Speichern zu den Terminen oben hinzugefügt.', 'vereinsplugin' ) . '</p></details>';
	echo '<p><label style="font-weight:normal"><input type="checkbox" name="anhaengen" value="1" ' . checked( ! $id || '1' === (string) get_post_meta( $id, '_vp_ev_termine_anhaengen', true ), true, false ) . '> ' . esc_html__( 'Beim Versand die Liste der kommenden Termine an die Texte anhängen', 'vereinsplugin' ) . '</label><br>'
		. '<label style="font-weight:normal"><input type="checkbox" name="je_termin" value="1" ' . checked( $id && '1' === (string) get_post_meta( $id, '_vp_ev_je_termin', true ), true, false ) . '> ' . esc_html__( 'Jeden Termin einzeln bewerben (eigene Kanal-Checkliste je Termin, Text beginnt mit dem Termin)', 'vereinsplugin' ) . '</label></p></div>';
	echo '<label class="vp-col-2">' . esc_html__( 'Beschreibung (Website, Presse-Standardtext)', 'vereinsplugin' ) . '<textarea name="beschreibung" rows="5">' . esc_textarea( $post ? $post->post_content : '' ) . '</textarea></label>';
	echo '<label class="vp-col-2">' . esc_html__( 'Kurzbeschreibung (optional, für Übersichten auf der Website)', 'vereinsplugin' ) . '<textarea name="kurz" rows="2">' . esc_textarea( $post ? $post->post_excerpt : '' ) . '</textarea></label>';
	echo '</div>';
	echo '<p><label><input type="checkbox" name="sichtbar" value="1" ' . checked( ! $post || 'publish' === $post->post_status, true, false ) . '> ' . esc_html__( 'Auf der Website veröffentlichen (Veranstaltungsseite)', 'vereinsplugin' ) . '</label></p>';
	echo '<fieldset style="border:0;padding:0"><legend><strong>' . esc_html__( 'Über welche Kanäle wird verbreitet?', 'vereinsplugin' ) . '</strong></legend><div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:4px 16px;margin-top:6px">';
	foreach ( $kanaele as $k => $label ) {
		echo '<label style="font-weight:normal"><input type="checkbox" name="kanaele[]" value="' . esc_attr( $k ) . '" ' . checked( in_array( $k, $gew, true ), true, false ) . '> ' . esc_html( $label )
			. ' <span class="vp-muted">' . esc_html( in_array( $k, $auto, true ) ? __( '(automatisch)', 'vereinsplugin' ) : __( '(von Hand)', 'vereinsplugin' ) ) . '</span></label>';
	}
	echo '</div></fieldset>';
	echo '<p><button class="vp-btn vp-btn-primary">' . esc_html( $id ? __( 'Speichern', 'vereinsplugin' ) : __( 'Anlegen und weiter', 'vereinsplugin' ) ) . '</button></p></form></div>';
	echo '<script>(function(){var r=document.querySelectorAll("[data-vp-ev-art]");function u(){var v="einzeln";r.forEach(function(x){if(x.checked)v=x.value;});document.querySelectorAll("[data-vp-ev-nur]").forEach(function(e){e.hidden=e.getAttribute("data-vp-ev-nur")!==v;});}r.forEach(function(x){x.addEventListener("change",u);});u();})();</script>';

	if ( ! $id ) {
		return ob_get_clean();
	}

	/* ---- 2. Material ---- */
	$material = vp_ev_material_fuer( $id );
	$nc_pfad  = (string) get_post_meta( $id, '_vp_ev_nc_ordner', true );
	echo '<div class="vp-card"><h3 style="margin-top:0">2 · ' . esc_html__( 'Material – Texte und Bildformate', 'vereinsplugin' ) . '</h3>';
	if ( ! $material ) {
		echo '<p class="vp-muted">' . esc_html__( 'Erst oben Kanäle wählen – dann steht hier, was gebraucht wird.', 'vereinsplugin' ) . '</p></div>';
	} else {
		// Nextcloud-Ordner
		echo '<p>';
		if ( $nc_pfad ) {
			echo '📁 <a href="' . esc_url( vp_ev_nc_link( $nc_pfad ) ) . '" target="_blank" rel="noopener">' . esc_html( $nc_pfad ) . '</a> <span class="vp-muted">' . esc_html__( '– Uploads werden dort automatisch abgelegt.', 'vereinsplugin' ) . '</span>';
		} elseif ( vp_ev_nc_cfg() ) {
			echo '<form method="post" style="display:inline">' . vp_ev_kopf( 'nc_ordner', $id ) . '<button class="vp-btn">📁 ' . esc_html__( 'Nextcloud-Ordner anlegen', 'vereinsplugin' ) . '</button></form> <span class="vp-muted">' . esc_html( vp_ev_nc_basis() . '/' . vp_ev_nc_ordner_name( $id ) ) . '</span>'; // phpcs:ignore
		} else {
			echo '<span class="vp-muted">' . esc_html__( 'Nextcloud-Ablage: in der Buchhaltung unter Einstellungen → Nextcloud einrichten.', 'vereinsplugin' ) . '</span>';
		}
		echo '</p>';

		if ( vp_ev_ist_reihe( $id ) ) {
			echo '<details open style="margin:6px 0 10px"><summary><strong>' . esc_html( sprintf( __( 'Terminliste (%d Termine) – zum Kopieren', 'vereinsplugin' ), count( vp_ev_termine( $id ) ) ) ) . '</strong></summary>'
				. '<textarea readonly rows="' . min( 12, count( vp_ev_termine( $id ) ) + 1 ) . '" style="width:100%;margin-top:6px" onclick="this.select()">' . esc_textarea( vp_ev_terminliste_text( $id ) ) . '</textarea>'
				. '<p class="vp-muted">' . esc_html( '1' === (string) get_post_meta( $id, '_vp_ev_termine_anhaengen', true ) ? __( 'Wird beim automatischen Versand an die Texte angehängt (nur kommende Termine).', 'vereinsplugin' ) : __( 'Für Plakat, Newsletter und manuelle Kanäle.', 'vereinsplugin' ) ) . '</p></details>';
		}
		// Texte + Canva-Links in einem Formular
		$haupt = array_filter( $material, static function ( $m ) {
			return empty( $m['extra'] );
		} );
		$extra = array_diff_key( $material, $haupt );
		echo '<form method="post" class="vp-form" id="vp-ev-material">' . vp_ev_kopf( 'material', $id ) . '</form>'; // phpcs:ignore
		foreach ( $haupt as $mid => $m ) {
			echo vp_ev_material_zeile( $id, $mid, $m ); // phpcs:ignore
		}
		if ( $extra ) {
			echo '<details style="margin:8px 0"><summary>' . esc_html__( 'Eigene Texte je Kanal (optional, sonst gilt der gemeinsame Text)', 'vereinsplugin' ) . '</summary>';
			foreach ( $extra as $mid => $m ) {
				echo vp_ev_material_zeile( $id, $mid, $m ); // phpcs:ignore
			}
			echo '</details>';
		}
		echo '<p><button class="vp-btn vp-btn-primary" form="vp-ev-material">' . esc_html__( 'Texte & Links speichern', 'vereinsplugin' ) . '</button></p></div>';
	}

	/* ---- 3. Kanäle ---- */
	$status = vp_ev_kanal_status( $id );
	$senden = class_exists( 'Jbf_Roles' ) && Jbf_Roles::can_send_external();
	echo '<div class="vp-card"><h3 style="margin-top:0">3 · ' . esc_html__( 'Verbreitung – Kanäle', 'vereinsplugin' ) . '</h3>';
	if ( ! $gew ) {
		echo '<p class="vp-muted">' . esc_html__( 'Noch keine Kanäle gewählt.', 'vereinsplugin' ) . '</p>';
	} else {
		if ( vp_ev_je_termin( $id ) ) {
			foreach ( vp_ev_termine( $id ) as $i => $t ) {
				$vorbei = strtotime( str_replace( 'T', ' ', $t['beginn'] ) ) < current_time( 'timestamp' ) - DAY_IN_SECONDS;
				echo '<details' . ( $vorbei ? '' : ' open' ) . ' style="margin:6px 0"><summary><strong>' . esc_html( vp_ev_termin_text( $t, $id ) ) . '</strong>' . ( $vorbei ? ' <span class="vp-muted">(' . esc_html__( 'vorbei', 'vereinsplugin' ) . ')</span>' : '' ) . '</summary>';
				echo vp_ev_kanal_tabelle( $id, $gew, $status, $senden, $i ); // phpcs:ignore
				echo '</details>';
			}
		} else {
			if ( vp_ev_ist_reihe( $id ) ) {
				echo '<p class="vp-muted">' . esc_html( sprintf( __( 'Die ganze Reihe (%d Termine) wird einmal beworben. Für Werbung je Termin oben „Jeden Termin einzeln bewerben“ anhaken.', 'vereinsplugin' ), count( vp_ev_termine( $id ) ) ) ) . '</p>';
			}
			echo vp_ev_kanal_tabelle( $id, $gew, $status, $senden, null ); // phpcs:ignore
		}
		$log = (array) get_post_meta( $id, '_jbf_publish_log', true );
		if ( array_filter( $log ) ) {
			echo '<details style="margin-top:8px"><summary>' . esc_html__( 'Versandprotokoll', 'vereinsplugin' ) . '</summary><ul>';
			foreach ( array_reverse( array_filter( $log ) ) as $l ) {
				echo '<li>' . esc_html( ( $l['success'] ? '✓ ' : '✗ ' ) . $l['channel'] . ' · ' . mysql2date( 'd.m.Y H:i', $l['time'] ) . ( $l['message'] ? ' · ' . $l['message'] : '' ) ) . '</li>';
			}
			echo '</ul></details>';
		}
	}
	$reel = get_post_meta( $id, '_jbf_reel_status', true );
	if ( is_array( $reel ) && ! empty( $reel['message'] ) ) {
		echo '<p class="vp-muted">' . esc_html( sprintf( __( 'Instagram-Reel: %1$s (Stand %2$s)', 'vereinsplugin' ), $reel['message'], $reel['checked_at'] ?? '' ) ) . '</p>';
	}
	echo '</div>';

	/* ---- 4. Zeitplan ---- */
	if ( class_exists( 'Jbf_Campaign' ) ) {
		echo vp_ev_kampagne_karte( $id, $gew ); // phpcs:ignore
	}

	/* ---- Löschen ---- */
	if ( current_user_can( 'delete_post', $id ) ) {
		echo '<form method="post" style="margin:18px 0;text-align:right">' . vp_ev_kopf( 'loeschen', $id ) // phpcs:ignore
			. '<button class="vp-btn" style="color:#b91c1c" onclick="return confirm(\'' . esc_js( __( 'Veranstaltung löschen? Sie kommt in den Papierkorb und lässt sich dort wiederherstellen. Eingeplante Kampagnen-Schritte werden gestoppt.', 'vereinsplugin' ) ) . '\')">🗑 ' . esc_html__( 'Veranstaltung löschen', 'vereinsplugin' ) . '</button></form>';
	}
	return ob_get_clean();
}

/** Zeitversetzte Kampagne: Schritte relativ zum (ersten) Termin. */
function vp_ev_kampagne_karte( $id, array $gew ) {
	$steps  = Jbf_Campaign::get_steps( $id );
	$auto   = vp_ev_auto_kanaele();
	$kan    = vp_ev_kanaele();
	$senden = class_exists( 'Jbf_Roles' ) && Jbf_Roles::can_send_external();
	$wahl   = array_values( array_intersect( $gew, $auto ) ) ?: $auto;
	$gesendet = array_filter( $steps, static function ( $st ) {
		return 'sent' === ( $st['status'] ?? '' );
	} );
	$offen = array_filter( $steps, static function ( $st ) {
		return 'sent' !== ( $st['status'] ?? '' );
	} );
	$geplant = array_filter( $offen, static function ( $st ) {
		return 'scheduled' === ( $st['status'] ?? '' );
	} );
	$pending = array_filter( $offen, static function ( $st ) {
		return 'pending' === ( $st['status'] ?? '' );
	} );
	$beginn = (string) get_post_meta( $id, '_jbf_date_start', true );

	$h  = '<details class="vp-card"' . ( $steps ? ' open' : '' ) . '><summary><strong>4 · ' . esc_html__( 'Zeitplan: zeitversetzte Kampagne (optional)', 'vereinsplugin' ) . '</strong>';
	if ( $geplant ) {
		$h .= ' <span class="vp-badge" style="background:#e0f2fe">' . esc_html( sprintf( __( '%d eingeplant', 'vereinsplugin' ), count( $geplant ) ) ) . '</span>';
	}
	$h .= '</summary>';
	$h .= '<p class="vp-muted">' . esc_html( sprintf(
		__( 'Statt alles sofort zu senden, gehen die automatischen Kanäle in Schritten raus – relativ zum Termin %s (bei Reihen: der erste Termin). Tage negativ = vorher, positiv = danach.', 'vereinsplugin' ),
		$beginn ? vp_ev_datum( $id ) : __( '(noch kein Termin)', 'vereinsplugin' )
	) ) . '</p>';

	if ( $gesendet ) {
		$h .= '<h4 style="margin:8px 0 4px">' . esc_html__( 'Bereits versendet', 'vereinsplugin' ) . '</h4><ul>';
		foreach ( $gesendet as $st ) {
			$h .= '<li><strong>' . esc_html( $st['label'] ) . '</strong> · ' . esc_html( mysql2date( 'd.m.Y H:i', $st['sent_at'] ) ) . '<ul>';
			foreach ( (array) ( $st['log'] ?? array() ) as $l ) {
				$h .= '<li>' . esc_html( ( ! empty( $l['success'] ) ? '✓ ' : '✗ ' ) . ( $kan[ $l['channel'] ] ?? $l['channel'] ) . ( ! empty( $l['message'] ) ? ' · ' . $l['message'] : '' ) ) . '</li>';
			}
			$h .= '</ul></li>';
		}
		$h .= '</ul>';
	}

	$h .= '<form method="post" class="vp-form">' . vp_ev_kopf( 'kampagne', $id );
	$h .= '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Schritt', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Tage', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Uhrzeit', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Kanäle', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Eigener Text (leer = gemeinsamer Text)', 'vereinsplugin' ) . '</th><th></th></tr></thead><tbody>';
	$zeilen = array_merge( array_values( $offen ), array( array(), array() ) );
	foreach ( $zeilen as $i => $st ) {
		$n      = 'jbf_campaign[' . (int) $i . ']';
		$status = $st['status'] ?? '';
		$ch     = $st ? (array) ( $st['channels'] ?? array() ) : $wahl;
		$h     .= '<tr><td><input type="hidden" name="' . $n . '[id]" value="' . esc_attr( $st['id'] ?? '' ) . '"><input type="text" name="' . $n . '[label]" value="' . esc_attr( $st['label'] ?? '' ) . '" placeholder="' . esc_attr__( 'z. B. Ankündigung', 'vereinsplugin' ) . '" style="width:140px">';
		if ( 'scheduled' === $status && ! empty( $st['scheduled_ts'] ) ) {
			$h .= '<br><span class="vp-badge" style="background:#e0f2fe">' . esc_html( sprintf( __( 'eingeplant %s', 'vereinsplugin' ), date_i18n( 'd.m.Y H:i', (int) $st['scheduled_ts'] ) ) ) . '</span>';
		} elseif ( 'error' === $status ) {
			$h .= '<br><span class="vp-badge" style="background:#fee2e2">' . esc_html__( 'nicht eingeplant – Termin/Zeitpunkt prüfen', 'vereinsplugin' ) . '</span>';
		}
		$h .= '</td><td><input type="number" name="' . $n . '[offset_days]" value="' . esc_attr( (string) ( $st['offset_days'] ?? ( $st ? 0 : -7 ) ) ) . '" style="width:70px"></td>';
		$h .= '<td><input type="time" name="' . $n . '[time]" value="' . esc_attr( $st['time'] ?? '09:00' ) . '"></td><td style="font-size:.9em">';
		foreach ( $auto as $k ) {
			$h .= '<label style="display:block;font-weight:normal"><input type="checkbox" name="' . $n . '[channels][]" value="' . esc_attr( $k ) . '" ' . checked( in_array( $k, $ch, true ), true, false ) . '> ' . esc_html( $kan[ $k ] ?? $k ) . '</label>';
		}
		$h .= '</td><td><textarea name="' . $n . '[text_override]" rows="3" style="width:100%;min-width:160px">' . esc_textarea( $st['text_override'] ?? '' ) . '</textarea></td>';
		$h .= '<td>' . ( $st ? '<label style="font-weight:normal;white-space:nowrap"><input type="checkbox" name="' . $n . '[weg]" value="1"> ' . esc_html__( 'entfernen', 'vereinsplugin' ) . '</label>' : '' ) . '</td></tr>';
	}
	$h .= '</tbody></table></div>';
	$h .= '<p class="vp-muted">' . esc_html__( 'Leere Zeilen werden ignoriert. Nach dem Speichern sind alle Schritte wieder offen und müssen neu eingeplant werden.', 'vereinsplugin' ) . '</p>';
	$h .= '<p style="display:flex;gap:8px;flex-wrap:wrap"><button class="vp-btn vp-btn-primary">' . esc_html__( 'Zeitplan speichern', 'vereinsplugin' ) . '</button>'
		. '<button class="vp-btn" name="vorlage" value="1">' . esc_html__( '+ Vorlage einfügen (−14, −3, −1, +2 Tage)', 'vereinsplugin' ) . '</button></p></form>';

	if ( $senden && ( $pending || $geplant ) ) {
		$h .= '<p style="display:flex;gap:8px;flex-wrap:wrap">';
		if ( $pending ) {
			$h .= '<form method="post" style="display:inline">' . vp_ev_kopf( 'kampagne_einplanen', $id ) . '<button class="vp-btn vp-btn-primary">🚀 ' . esc_html__( 'Kampagne einplanen', 'vereinsplugin' ) . '</button></form>';
		}
		if ( $geplant ) {
			$h .= '<form method="post" style="display:inline">' . vp_ev_kopf( 'kampagne_stoppen', $id ) . '<button class="vp-btn">' . esc_html__( 'Kampagne stoppen', 'vereinsplugin' ) . '</button></form>';
		}
		$h .= '</p>';
	} elseif ( $pending && ! $senden ) {
		$h .= '<p class="vp-muted">' . esc_html__( 'Einplanen darf der Vorstand – oben in Schritt 3 „Zur Freigabe einreichen“.', 'vereinsplugin' ) . '</p>';
	}
	return $h . '</details>';
}

/** Kanal-Checkliste (ganze Veranstaltung oder ein Termin der Reihe). */
function vp_ev_kanal_tabelle( $id, array $gew, array $status, $senden, $termin ) {
	$kanaele = vp_ev_kanaele();
	$auto    = vp_ev_auto_kanaele();
	$tfeld   = null === $termin ? '' : '<input type="hidden" name="termin" value="' . (int) $termin . '">';
	$h       = '';
	$offen_auto = array_filter( array_intersect( $gew, $auto ), static function ( $k ) use ( $status, $termin ) {
		return ! isset( $status[ vp_ev_status_key( $k, $termin ) ] );
	} );
	if ( $offen_auto ) {
		if ( $senden ) {
			$h .= '<form method="post" style="margin:6px 0 10px">' . vp_ev_kopf( 'senden', $id ) . $tfeld . '<button class="vp-btn vp-btn-primary" onclick="return confirm(\'' . esc_js( __( 'Jetzt an alle offenen automatischen Kanäle senden?', 'vereinsplugin' ) ) . '\')">🚀 ' . esc_html( sprintf( __( 'Alle %d automatischen Kanäle senden', 'vereinsplugin' ), count( $offen_auto ) ) ) . '</button></form>';
		} elseif ( null === $termin || 0 === (int) $termin ) {
			$rs = (string) get_post_meta( $id, '_jbf_review_status', true );
			$h .= '<form method="post" style="margin:6px 0 10px">' . vp_ev_kopf( 'freigabe', $id ) . '<button class="vp-btn vp-btn-primary"' . ( 'ready' === $rs ? ' disabled' : '' ) . '>' . esc_html( 'ready' === $rs ? __( 'Zur Freigabe eingereicht', 'vereinsplugin' ) : __( 'Zur Freigabe einreichen', 'vereinsplugin' ) ) . '</button> <span class="vp-muted">' . esc_html__( 'Automatische Kanäle sendet der Vorstand.', 'vereinsplugin' ) . '</span></form>';
		}
	}
	$h .= '<div class="vp-table-wrap"><table class="vp-table"><tbody>';
	foreach ( $gew as $k ) {
		if ( ! isset( $kanaele[ $k ] ) ) {
			continue;
		}
		$s        = $status[ vp_ev_status_key( $k, $termin ) ] ?? null;
		$ist_auto = in_array( $k, $auto, true );
		$h       .= '<tr><td style="width:32px;font-size:1.2em">' . ( $s ? '✅' : '⬜' ) . '</td><td><strong>' . esc_html( $kanaele[ $k ] ) . '</strong><br><span class="vp-muted">';
		if ( $s ) {
			$u  = get_userdata( (int) $s['von'] );
			$h .= esc_html( trim( ( $s['text'] ?: __( 'erledigt', 'vereinsplugin' ) ) . ' · ' . mysql2date( 'd.m.Y H:i', $s['am'] ) . ( $u ? ' · ' . $u->display_name : '' ) ) );
			if ( $s['link'] ) {
				$h .= ' · <a href="' . esc_url( $s['link'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Link', 'vereinsplugin' ) . '</a>';
			}
		} else {
			$h .= esc_html( $ist_auto ? __( 'automatisch – wird beim Senden veröffentlicht', 'vereinsplugin' ) : vp_ev_kanal_hinweis( $k, $id ) );
		}
		$h .= '</span></td><td style="text-align:right;white-space:nowrap">';
		if ( ! $s && $ist_auto && $senden ) {
			$h .= '<form method="post" style="display:inline">' . vp_ev_kopf( 'senden', $id ) . $tfeld . '<input type="hidden" name="kanal" value="' . esc_attr( $k ) . '"><button class="vp-btn">' . esc_html__( 'Senden', 'vereinsplugin' ) . '</button></form> ';
		}
		$h .= '<form method="post" style="display:inline-flex;gap:4px;align-items:center">' . vp_ev_kopf( 'kanal', $id ) . $tfeld . '<input type="hidden" name="kanal" value="' . esc_attr( $k ) . '">';
		if ( $s ) {
			$h .= '<button class="vp-btn" title="' . esc_attr__( 'Als offen markieren', 'vereinsplugin' ) . '">↺</button>';
		} else {
			$h .= '<input type="url" name="link" placeholder="' . esc_attr__( 'Link (optional)', 'vereinsplugin' ) . '" style="width:150px"><input type="hidden" name="erledigt" value="1"><button class="vp-btn">' . esc_html__( '✓ erledigt', 'vereinsplugin' ) . '</button>';
		}
		$h .= '</form></td></tr>';
	}
	return $h . '</tbody></table></div>';
}

/** Hinweis für manuelle Kanäle. */
function vp_ev_kanal_hinweis( $k, $id ) {
	switch ( $k ) {
		case 'website':
			return 'publish' === get_post_status( $id ) ? __( 'Seite ist online – abhaken, wenn geprüft.', 'vereinsplugin' ) : __( 'Oben „Auf der Website veröffentlichen“ anhaken.', 'vereinsplugin' );
		case 'whatsapp':
			return __( 'Text und Bild aus Schritt 2 in Gruppe/Kanal posten, dann abhaken.', 'vereinsplugin' );
		case 'facebook_event':
			return __( 'Veranstaltung auf Facebook anlegen (Querformat-Bild), Link eintragen.', 'vereinsplugin' );
		case 'newsletter':
			return __( 'Newsletter-Text aus Schritt 2 verwenden.', 'vereinsplugin' );
		case 'aushang':
			return __( 'Plakat drucken und aufhängen.', 'vereinsplugin' );
	}
	return __( 'von Hand – abhaken, wenn erledigt.', 'vereinsplugin' );
}

/** Eine Zeile der Material-Checkliste. */
function vp_ev_material_zeile( $id, $mid, array $m ) {
	$fertig = vp_ev_material_fertig( $id, $m );
	$wert   = (string) get_post_meta( $id, $m['meta'], true );
	$kan    = vp_ev_kanaele();
	$fuer   = array_map( static function ( $k ) use ( $kan ) {
		return $kan[ $k ] ?? $k;
	}, array_intersect( $m['kanaele'], vp_ev_gewaehlt( $id ) ) );

	$h  = '<div style="display:flex;gap:10px;padding:10px 0;border-top:1px solid rgba(127,127,127,.2)">';
	$h .= '<div style="font-size:1.2em;width:24px">' . ( $fertig ? '✅' : ( empty( $m['pflicht'] ) ? '◻️' : '⬜' ) ) . '</div><div style="flex:1;min-width:0">';
	$h .= '<strong>' . esc_html( $m['titel'] ) . '</strong>' . ( empty( $m['pflicht'] ) ? ' <span class="vp-muted">(' . esc_html__( 'optional', 'vereinsplugin' ) . ')</span>' : '' );
	if ( ! empty( $m['w'] ) ) {
		$h .= ' <span class="vp-badge">' . (int) $m['w'] . ' × ' . (int) $m['h'] . ' px</span>';
	}
	$h .= '<br><span class="vp-muted">' . esc_html( sprintf( __( 'für: %s', 'vereinsplugin' ), implode( ', ', $fuer ) ) ) . ( ! empty( $m['hinweis'] ) ? ' · ' . esc_html( $m['hinweis'] ) : '' ) . '</span>';

	if ( in_array( $m['typ'], array( 'text', 'textarea' ), true ) ) {
		$h .= 'textarea' === $m['typ']
			? '<textarea form="vp-ev-material" name="m[' . esc_attr( $mid ) . ']" rows="4" style="width:100%;margin-top:6px">' . esc_textarea( $wert ) . '</textarea>'
			: '<input form="vp-ev-material" type="text" name="m[' . esc_attr( $mid ) . ']" value="' . esc_attr( $wert ) . '" style="width:100%;margin-top:6px">';
	} else {
		$att = (int) $wert;
		if ( $att ) {
			$url  = wp_get_attachment_url( $att );
			$meta = wp_get_attachment_metadata( $att );
			$h   .= '<div style="margin-top:6px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">';
			$h   .= 'bild' === $m['typ'] ? wp_get_attachment_image( $att, array( 120, 120 ) ) : '<a class="vp-btn" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( basename( (string) $url ) ) . '</a>';
			if ( ! empty( $meta['width'] ) && ! empty( $m['w'] ) && abs( $meta['width'] / max( 1, $meta['height'] ) - $m['w'] / $m['h'] ) > 0.03 ) {
				$h .= '<span class="vp-note vp-note-warn" style="margin:0">' . esc_html( sprintf( __( 'Format %1$d × %2$d passt nicht zu %3$d × %4$d – wird evtl. beschnitten.', 'vereinsplugin' ), $meta['width'], $meta['height'], $m['w'], $m['h'] ) ) . '</span>';
			}
			$h .= '<form method="post" style="display:inline">' . vp_ev_kopf( 'datei_weg', $id ) . '<input type="hidden" name="material" value="' . esc_attr( $mid ) . '"><button class="vp-btn" title="' . esc_attr__( 'Entfernen', 'vereinsplugin' ) . '">✕</button></form></div>';
		}
		$accept = 'video' === $m['typ'] ? 'video/*' : ( 'datei' === $m['typ'] ? 'application/pdf,image/*' : 'image/*' );
		$h     .= '<form method="post" enctype="multipart/form-data" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-top:6px">' . vp_ev_kopf( 'datei', $id )
			. '<input type="hidden" name="material" value="' . esc_attr( $mid ) . '"><input type="file" name="datei" accept="' . esc_attr( $accept ) . '" required style="max-width:220px">'
			. '<button class="vp-btn">' . esc_html( $att ? __( 'Ersetzen', 'vereinsplugin' ) : __( 'Hochladen', 'vereinsplugin' ) ) . '</button>';
		if ( ! empty( $m['w'] ) && 'video' !== $m['typ'] ) {
			$h .= ' <a class="vp-btn" href="' . esc_url( vp_ev_canva_link( $m['w'], $m['h'] ) ) . '" target="_blank" rel="noopener">🎨 ' . esc_html__( 'In Canva gestalten', 'vereinsplugin' ) . '</a>';
		}
		$h .= '</form>';
		if ( 'video' !== $m['typ'] ) {
			$canva = (string) get_post_meta( $id, '_vp_ev_canva_' . $mid, true );
			$h    .= '<label style="display:block;margin-top:6px;font-size:.9em">' . esc_html__( 'Canva-Link zum Design (zum Wiederfinden)', 'vereinsplugin' )
				. ' <input form="vp-ev-material" type="url" name="canva[' . esc_attr( $mid ) . ']" value="' . esc_attr( $canva ) . '" placeholder="https://www.canva.com/design/…" style="width:100%"></label>';
			if ( $canva ) {
				$h .= '<a href="' . esc_url( $canva ) . '" target="_blank" rel="noopener">' . esc_html__( 'Design in Canva öffnen', 'vereinsplugin' ) . '</a>';
			}
		}
	}
	return $h . '</div></div>';
}


/** Veranstaltungsseite: bei Reihen die Termine unter dem Text zeigen. */
add_filter( 'the_content', 'vp_ev_termine_auf_seite', 20 );
function vp_ev_termine_auf_seite( $content ) {
	if ( ! is_singular( 'veranstaltung' ) || ! in_the_loop() || ! vp_ev_ist_reihe( get_the_ID() ) ) {
		return $content;
	}
	$li = '';
	foreach ( vp_ev_termine( get_the_ID() ) as $t ) {
		$vorbei = strtotime( str_replace( 'T', ' ', $t['beginn'] ) ) < current_time( 'timestamp' ) - DAY_IN_SECONDS;
		$li    .= '<li' . ( $vorbei ? ' style="opacity:.55"' : '' ) . '>' . esc_html( vp_ev_termin_text( $t, get_the_ID() ) ) . '</li>';
	}
	return $content . '<h3>' . esc_html__( 'Termine', 'vereinsplugin' ) . '</h3><ul class="vp-ev-termine">' . $li . '</ul>';
}
