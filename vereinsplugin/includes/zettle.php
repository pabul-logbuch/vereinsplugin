<?php
/**
 * Zettle-Anbindung: Verkäufe über die Purchase API abrufen und daraus den
 * Z-Bon vorausfüllen (Bar, Karte, Trinkgeld, Spende) sowie die verkauften
 * Mengen für den Getränkebestand liefern.
 *
 * Anmeldung: „Assertion Grant“ für eigene Integrationen. Die Kontoinhaberin
 * erzeugt unter my.zettle.com → Apps → API-Schlüssel einen Schlüssel (JWT)
 * mit dem Recht READ:PURCHASE. Daraus holt das Plugin bei Bedarf ein
 * Zugriffstoken (2 h gültig, zwischengespeichert).
 * Doku: https://github.com/iZettle/api-documentation (authorization.md, purchase.adoc)
 */

defined( 'ABSPATH' ) || exit;

define( 'VP_ZETTLE_OAUTH', 'https://oauth.zettle.com/token' );
define( 'VP_ZETTLE_PURCHASES', 'https://purchase.izettle.com/purchases/v2' );

/** Link zum Anlegen des API-Schlüssels mit vorausgefüllten Feldern. */
function vp_zettle_key_link() {
	return 'https://my.zettle.com/apps/api-keys?name=Vereinsplugin&scopes=READ:PURCHASE';
}

function vp_zettle_api_key() {
	return trim( (string) get_option( 'vp_zettle_api_key', '' ) );
}

function vp_zettle_verbunden() {
	return '' !== vp_zettle_api_key();
}

/** Einstellungen mit Vorgaben. */
function vp_zettle_einstellungen() {
	$e = (array) get_option( 'vp_zettle_einstellungen', array() );
	return array(
		// Ab dieser Uhrzeit beginnt ein neuer Kassentag (Abende über Mitternacht).
		'tagesgrenze' => preg_match( '/^\d{2}:\d{2}$/', (string) ( $e['tagesgrenze'] ?? '' ) ) ? $e['tagesgrenze'] : '05:00',
		// Produktnamen, die als Spende zählen (kommagetrennt, Teilwort genügt).
		'spende'      => trim( (string) ( $e['spende'] ?? '' ) ) ?: 'Spende',
	);
}

/** client_id aus dem API-Schlüssel (JWT) lesen. */
function vp_zettle_client_id( $key ) {
	$teile = explode( '.', (string) $key );
	if ( count( $teile ) < 2 ) {
		return '';
	}
	$json = json_decode( (string) base64_decode( strtr( $teile[1], '-_', '+/' ) ), true );
	if ( ! is_array( $json ) ) {
		return '';
	}
	foreach ( array( 'client_id', 'clientId', 'cid' ) as $k ) {
		if ( ! empty( $json[ $k ] ) && is_string( $json[ $k ] ) ) {
			return $json[ $k ];
		}
	}
	return '';
}

/** API-Schlüssel speichern (prüft ihn gleich, indem ein Token geholt wird). */
function vp_zettle_key_speichern( $key ) {
	$key = trim( (string) $key );
	if ( '' === $key ) {
		delete_option( 'vp_zettle_api_key' );
		delete_transient( 'vp_zettle_token' );
		return true;
	}
	if ( '' === vp_zettle_client_id( $key ) ) {
		return new WP_Error( 'zettle_key', __( 'Das sieht nicht wie ein Zettle-API-Schlüssel aus (erwartet wird ein langer Text mit zwei Punkten).', 'vereinsplugin' ) );
	}
	$alt = vp_zettle_api_key();
	update_option( 'vp_zettle_api_key', $key, false );
	delete_transient( 'vp_zettle_token' );
	$t = vp_zettle_token();
	if ( is_wp_error( $t ) ) {
		if ( '' === $alt ) {
			delete_option( 'vp_zettle_api_key' );
		} else {
			update_option( 'vp_zettle_api_key', $alt, false );
		}
		return $t;
	}
	return true;
}

/** Zugriffstoken holen (aus dem Zwischenspeicher oder neu). */
function vp_zettle_token() {
	$t = get_transient( 'vp_zettle_token' );
	if ( $t ) {
		return $t;
	}
	$key = vp_zettle_api_key();
	if ( '' === $key ) {
		return new WP_Error( 'zettle_off', __( 'Zettle ist nicht verbunden.', 'vereinsplugin' ) );
	}
	$res = wp_remote_post( VP_ZETTLE_OAUTH, array(
		'timeout' => 20,
		'body'    => array(
			'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
			'client_id'  => vp_zettle_client_id( $key ),
			'assertion'  => $key,
		),
	) );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'zettle_net', sprintf( __( 'Zettle nicht erreichbar: %s', 'vereinsplugin' ), $res->get_error_message() ) );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	if ( 200 !== $code || empty( $body['access_token'] ) ) {
		return new WP_Error( 'zettle_auth', sprintf(
			/* translators: %d: HTTP status */
			__( 'Zettle hat die Anmeldung abgelehnt (HTTP %d). API-Schlüssel prüfen oder neu anlegen.', 'vereinsplugin' ),
			$code
		) );
	}
	$ttl = max( 60, (int) ( $body['expires_in'] ?? 7200 ) - 120 );
	set_transient( 'vp_zettle_token', $body['access_token'], $ttl );
	return $body['access_token'];
}

/**
 * Lokale Zeit (Europe/Berlin o. Ä. aus den WP-Einstellungen) nach UTC für Zettle.
 * @return string z. B. "2026-10-05T03:00"
 */
function vp_zettle_utc( $lokal ) {
	$dt = new DateTime( $lokal, wp_timezone() );
	$dt->setTimezone( new DateTimeZone( 'UTC' ) );
	return $dt->format( 'Y-m-d\TH:i' );
}

/**
 * Zeitraum eines Kassentags: von Datum + Tagesgrenze bis Folgetag + Tagesgrenze.
 * @return array{von:string,bis:string} lokale Zeit „Y-m-d H:i“
 */
function vp_zettle_kassentag( $datum ) {
	$g   = vp_zettle_einstellungen()['tagesgrenze'];
	$von = new DateTime( $datum . ' ' . $g, wp_timezone() );
	$bis = ( clone $von )->modify( '+1 day' );
	return array( 'von' => $von->format( 'Y-m-d H:i' ), 'bis' => $bis->format( 'Y-m-d H:i' ) );
}

/**
 * Alle Verkäufe in einem Zeitraum (lokale Zeit, bis exklusiv).
 * @return array|WP_Error Liste der Purchase-Objekte
 */
function vp_zettle_verkaeufe( $von, $bis ) {
	$token = vp_zettle_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$alle  = array();
	$hash  = '';
	for ( $seite = 0; $seite < 50; $seite++ ) {
		$args = array(
			'startDate' => vp_zettle_utc( $von ),
			'endDate'   => vp_zettle_utc( $bis ),
			'limit'     => 1000,
		);
		if ( '' !== $hash ) {
			$args['lastPurchaseHash'] = $hash;
		}
		$res = wp_remote_get( add_query_arg( $args, VP_ZETTLE_PURCHASES ), array(
			'timeout' => 30,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		) );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'zettle_net', sprintf( __( 'Zettle nicht erreichbar: %s', 'vereinsplugin' ), $res->get_error_message() ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 401 === $code ) {
			delete_transient( 'vp_zettle_token' );
			return new WP_Error( 'zettle_auth', __( 'Zettle: keine Berechtigung. Hat der API-Schlüssel das Recht „READ:PURCHASE“?', 'vereinsplugin' ) );
		}
		if ( 429 === $code ) {
			return new WP_Error( 'zettle_rate', __( 'Zettle: zu viele Anfragen – bitte in einer Minute noch einmal versuchen.', 'vereinsplugin' ) );
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'zettle_http', sprintf( __( 'Zettle-Abruf fehlgeschlagen (HTTP %d).', 'vereinsplugin' ), $code ) );
		}
		$body  = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$liste = (array) ( $body['purchases'] ?? array() );
		$alle  = array_merge( $alle, $liste );
		$neu   = (string) ( $body['lastPurchaseHash'] ?? '' );
		if ( count( $liste ) < 1000 || '' === $neu || $neu === $hash ) {
			break;
		}
		$hash = $neu;
	}
	return $alle;
}

/** Ist das ein Spenden-Produkt? */
function vp_zettle_ist_spende( $name ) {
	foreach ( array_filter( array_map( 'trim', explode( ',', vp_zettle_einstellungen()['spende'] ) ) ) as $w ) {
		if ( false !== mb_stripos( (string) $name, $w ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Verkäufe zu Z-Bon-Werten zusammenfassen. Beträge in Euro.
 *
 * - Bar = Zahlungen IZETTLE_CASH, Karte = Kartenzahlungen (Kartenleser,
 *   online, PayPal). Trinkgeld (gratuityAmount) steckt im Zahlungsbetrag.
 * - Spende = Produkte, deren Name auf die Spenden-Liste passt; bei gemischt
 *   bezahlten Verkäufen anteilig auf bar/Karte verteilt.
 * - Rechnung, Gutschein, Guthaben u. Ä. landen nicht auf Barkasse/PayPal und
 *   werden nur gemeldet.
 * - Erstattungen (refund) haben negative Beträge und mindern die Summen.
 *
 * @return array{anzahl:int,erstattungen:int,bar:float,karte:float,trinkgeld:float,trinkgeld_bar:float,
 *               spende_bar:float,spende_karte:float,sonstige:array,produkte:array,mengen:array,erster:string,letzter:string}
 */
function vp_zettle_zusammenfassen( array $verkaeufe ) {
	$karte_typen = array( 'IZETTLE_CARD', 'IZETTLE_CARD_ONLINE', 'PAYPAL' );
	$s = array(
		'anzahl' => 0, 'erstattungen' => 0, 'bar' => 0, 'karte' => 0, 'trinkgeld' => 0, 'trinkgeld_bar' => 0,
		'spende_bar' => 0, 'spende_karte' => 0, 'sonstige' => array(), 'produkte' => array(), 'mengen' => array(),
		'erster' => '', 'letzter' => '',
	);
	foreach ( $verkaeufe as $p ) {
		$s['anzahl']++;
		if ( ! empty( $p['refund'] ) ) {
			$s['erstattungen']++;
		}
		$ts = (string) ( $p['timestamp'] ?? ( $p['created'] ?? '' ) );
		if ( $ts ) {
			$s['erster']  = ( '' === $s['erster'] || $ts < $s['erster'] ) ? $ts : $s['erster'];
			$s['letzter'] = $ts > $s['letzter'] ? $ts : $s['letzter'];
		}

		$p_bar = 0;
		$p_kar = 0;
		foreach ( (array) ( $p['payments'] ?? array() ) as $z ) {
			$betrag = (int) ( $z['amount'] ?? 0 );
			$tip    = (int) ( $z['gratuityAmount'] ?? 0 );
			$typ    = (string) ( $z['type'] ?? '' );
			if ( 'IZETTLE_CASH' === $typ ) {
				$p_bar += $betrag;
				$s['trinkgeld_bar'] += $tip;
			} elseif ( in_array( $typ, $karte_typen, true ) ) {
				$p_kar += $betrag;
				$s['trinkgeld'] += $tip;
			} else {
				$s['sonstige'][ $typ ] = ( $s['sonstige'][ $typ ] ?? 0 ) + $betrag;
			}
		}
		$s['bar']   += $p_bar;
		$s['karte'] += $p_kar;

		$anteil_bar = ( $p_bar + $p_kar ) ? $p_bar / ( $p_bar + $p_kar ) : 1.0;
		foreach ( (array) ( $p['products'] ?? array() ) as $pr ) {
			$name  = trim( (string) ( $pr['name'] ?? '' ) . ( ! empty( $pr['variantName'] ) ? ' ' . $pr['variantName'] : '' ) );
			$menge = (float) str_replace( ',', '.', (string) ( $pr['quantity'] ?? 0 ) );
			$summe = (int) round( (int) ( $pr['unitPrice'] ?? 0 ) * $menge ) - (int) ( $pr['discountValue'] ?? 0 );
			if ( '' === $name ) {
				$name = __( '(freier Betrag)', 'vereinsplugin' );
			}
			if ( ! isset( $s['produkte'][ $name ] ) ) {
				$s['produkte'][ $name ] = array( 'menge' => 0, 'summe' => 0, 'spende' => vp_zettle_ist_spende( $name ) );
			}
			$s['produkte'][ $name ]['menge'] += $menge;
			$s['produkte'][ $name ]['summe'] += $summe;
			if ( $s['produkte'][ $name ]['spende'] ) {
				$s['spende_bar']   += $summe * $anteil_bar;
				$s['spende_karte'] += $summe * ( 1 - $anteil_bar );
			} elseif ( 'PRODUCT' === ( $pr['type'] ?? 'PRODUCT' ) ) {
				// Für den Getränkebestand nur Produkte aus der Bibliothek.
				$base = trim( (string) ( $pr['name'] ?? '' ) );
				if ( '' !== $base ) {
					$s['mengen'][ $base ] = ( $s['mengen'][ $base ] ?? 0 ) + $menge;
				}
			}
		}
	}
	// Cent → Euro.
	foreach ( array( 'bar', 'karte', 'trinkgeld', 'trinkgeld_bar', 'spende_bar', 'spende_karte' ) as $k ) {
		$s[ $k ] = round( $s[ $k ] / 100, 2 );
	}
	foreach ( $s['sonstige'] as $k => $v ) {
		$s['sonstige'][ $k ] = round( $v / 100, 2 );
	}
	foreach ( $s['produkte'] as $k => $v ) {
		$s['produkte'][ $k ]['summe'] = round( $v['summe'] / 100, 2 );
	}
	uasort( $s['produkte'], static function ( $a, $b ) {
		return $b['summe'] <=> $a['summe'];
	} );
	return $s;
}

/* =========================================================================
 * Welche Zeiträume sind schon als Z-Bon gebucht?
 * ====================================================================== */

/** Zeitraum eines gebuchten Z-Bons merken (Option vp_zettle_gebucht). */
function vp_zettle_zeitraum_merken( $nr, $von, $bis ) {
	$log = (array) get_option( 'vp_zettle_gebucht', array() );
	array_unshift( $log, array( 'nr' => (string) $nr, 'von' => $von, 'bis' => $bis ) );
	update_option( 'vp_zettle_gebucht', array_slice( $log, 0, 400 ), false );
}

/** Schon gebuchte Z-Bons, die sich mit dem Zeitraum überschneiden. */
function vp_zettle_ueberschneidung( $von, $bis ) {
	$treffer = array();
	foreach ( (array) get_option( 'vp_zettle_gebucht', array() ) as $z ) {
		if ( $z['von'] < $bis && $z['bis'] > $von ) {
			$treffer[] = $z;
		}
	}
	return $treffer;
}

/** Nächster noch nicht gebuchter Kassentag (Datum) – nach dem jüngsten gebuchten. */
function vp_zettle_naechster_tag() {
	$log = (array) get_option( 'vp_zettle_gebucht', array() );
	if ( ! $log ) {
		return current_time( 'Y-m-d' );
	}
	$max = max( array_column( $log, 'bis' ) );
	return substr( $max, 0, 10 );
}

/* =========================================================================
 * Getränkebestand: verkaufte Mengen abbuchen
 * ====================================================================== */

/**
 * @param array  $mengen Produktname → Menge
 * @return array{gebucht:int,nicht_gefunden:array,schon:bool}
 */
function vp_zettle_bestand_abbuchen( array $mengen, $datum, $ref ) {
	global $wpdb;
	$out = array( 'gebucht' => 0, 'nicht_gefunden' => array(), 'schon' => false );
	if ( ! function_exists( 'jb_bewegung_add' ) || ! function_exists( 'jb_table_getraenke' ) || ! $mengen ) {
		return $out;
	}
	if ( function_exists( 'jb_table_bewegungen' ) && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . jb_table_bewegungen() . ' WHERE referenz = %s', $ref ) ) ) {
		$out['schon'] = true;
		return $out;
	}
	$produkte = (array) $wpdb->get_results( 'SELECT id, name FROM ' . jb_table_getraenke() . ' WHERE aktiv = 1', ARRAY_A );
	$map      = array_column( $produkte, 'id', 'name' );
	foreach ( $mengen as $name => $menge ) {
		$menge = (int) round( (float) $menge );
		if ( 0 === $menge ) {
			continue;
		}
		$pid = function_exists( 'jb_find_produkt_by_name' ) ? jb_find_produkt_by_name( (string) $name, $map ) : ( $map[ $name ] ?? null );
		if ( ! $pid ) {
			$out['nicht_gefunden'][] = $name . ' (' . $menge . ')';
			continue;
		}
		jb_bewegung_add( array(
			'produkt_id' => (int) $pid,
			'datum'      => $datum,
			'menge'      => -$menge,
			'grund'      => 'verkauf',
			'referenz'   => $ref,
			'notiz'      => 'Zettle: ' . $name,
		) );
		$out['gebucht']++;
	}
	return $out;
}
