<?php
/**
 * Kern: Buchungslogik für EÜR und Doppik auf derselben Datenbasis.
 *
 * Spiegel: desktop/src/ui/buchlogik.js – beide Seiten müssen dieselben Zahlen
 * liefern; Änderungen immer an beiden Stellen machen.
 *
 * Eine Buchung (Zeile in jb_buchungen) verbindet genau zwei Konten:
 *   geldkonto – Konto A, bei EÜR-Buchungen das Geldkonto (Bank, Kasse, PayPal)
 *   konto     – Konto B, bei EÜR-Buchungen das SKR-Konto (wofür?)
 *   betrag    – Wirkung auf Konto A: positiv = Zugang, negativ = Abgang
 *
 * Buchungssatz:  betrag ≥ 0 → Soll A an Haben B,  betrag < 0 → Soll B an Haben A.
 *
 * Je Geschäftsjahr wird festgelegt, ob es als EÜR oder Doppik geführt wird
 * (Tabelle jb_geschaeftsjahre). Das ändert Erfassung und Auswertung, nie die
 * gespeicherten Zeilen – ein Wechsel verliert deshalb nichts.
 *
 * Bis v0.32 gab es kein `geldkonto`: das Konto wurde bei jedem Lesen aus der
 * „quelle" (Geld-Topf) abgeleitet. vp_bh_umstellung_v10() schreibt es einmalig
 * fest; die Ableitung bleibt nur als Rückfall für Zeilen alter App-Versionen.
 */

defined( 'ABSPATH' ) || exit;

/** Auffangkonto für Buchungen ohne SKR-Konto. */
const VP_DOPPIK_INTERIM = '1590';
/** Gegenstück, falls das Geldkonto selbst 1590 ist. */
const VP_BH_INTERIM2 = '1599';

/* =========================================================================
 * Geld-Töpfe („quelle") und Vorgabe-Geldkonten
 * ====================================================================== */

/** Alte Standardzuordnung quelle → Konto. */
function vp_doppik_default_map() {
	return array(
		'Bank KSK'     => '1200',
		'Zettle-Bar'   => '1000',
		'Bar'          => '1000',
		'PayPal'       => '1220',
		'Zettle-Karte' => '1360',
		'Auslage'      => '1600',
		'Umbuchung'    => '1360',
		'Manuell'      => '1200',
	);
}

/**
 * Herkunftsnamen, die dasselbe Geldkonto meinen: Zettle-Kartenzahlungen landen
 * auf dem PayPal-Konto, „Bar" ist die Barkasse, „Manuell" das Bankkonto.
 */
function vp_bh_quelle_alias() {
	return array( 'Zettle-Karte' => 'PayPal', 'Bar' => 'Zettle-Bar', 'Manuell' => 'Bank KSK' );
}

/** Die vier Vorgaben, die man einstellen kann – mit Erklärung, wofür sie gelten. */
function vp_bh_vorgabe_quellen() {
	return array(
		'Bank KSK'   => __( 'Bankkonto – Bank-Import, SEPA-Einzug, bezahlte Rechnungen', 'vereinsplugin' ),
		'Zettle-Bar' => __( 'Barkasse – Z-Bon (bar bezahlt)', 'vereinsplugin' ),
		'PayPal'     => __( 'PayPal / Zettle – Z-Bon (Karte), Trinkgeld', 'vereinsplugin' ),
		'Auslage'    => __( 'Auslagen – genehmigte Auslagen von Mitgliedern', 'vereinsplugin' ),
	);
}

/** Zuordnung quelle → Konto (Option `jb_quelle_konto_map`, Zeilen „quelle = konto"). */
function vp_doppik_map() {
	$map = vp_doppik_default_map();
	$raw = (string) get_option( 'jb_quelle_konto_map', '' );
	foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || 0 === strpos( $line, '#' ) ) {
			continue;
		}
		$p = array_map( 'trim', explode( '=', $line, 2 ) );
		if ( 2 === count( $p ) && '' !== $p[0] && '' !== $p[1] ) {
			$map[ $p[0] ] = $p[1];
		}
	}
	return apply_filters( 'vp_doppik_map', $map );
}

/** Geldkonto, auf das eine automatisch erzeugte Buchung mit dieser quelle geht. */
function vp_bh_vorgabe_geldkonto( $quelle ) {
	$map   = vp_doppik_map();
	$alias = vp_bh_quelle_alias();
	$q     = $alias[ $quelle ] ?? $quelle;
	return (string) ( $map[ $q ] ?? ( $map[ $quelle ] ?? ( $map['Bank KSK'] ?? '1200' ) ) );
}

/** Kompatibilität (ältere Aufrufer). */
function vp_doppik_konto_fuer_quelle( $quelle ) {
	return vp_bh_vorgabe_geldkonto( (string) $quelle );
}

/* =========================================================================
 * Kontenplan
 * ====================================================================== */

/**
 * Kontenplan als Nachschlagetabelle.
 * @return array<string,array{typ:string,sphaere:string,bezeichnung:string,aktiv:int}>
 */
function vp_bh_konto_info( $neu_laden = false ) {
	static $info = null;
	if ( null === $info || $neu_laden ) {
		$info = array();
		if ( function_exists( 'jb_konten_all' ) ) {
			foreach ( (array) jb_konten_all( false ) as $k ) {
				$info[ (string) $k->nummer ] = array(
					'typ'         => (string) $k->typ,
					'sphaere'     => (string) $k->sphaere,
					'bezeichnung' => (string) $k->bezeichnung,
					'aktiv'       => (int) $k->aktiv,
				);
			}
		}
	}
	return $info;
}

/** Typ eines Kontos; unbekannte Nummern nach erster Ziffer (4 Einnahme, 5–7 Ausgabe, sonst Bestand). */
function vp_bh_typ( $nr ) {
	$nr   = (string) $nr;
	$info = vp_bh_konto_info();
	if ( isset( $info[ $nr ] ) && '' !== $info[ $nr ]['typ'] ) {
		return $info[ $nr ]['typ'];
	}
	if ( in_array( $nr, array( VP_DOPPIK_INTERIM, VP_BH_INTERIM2 ), true ) ) {
		return 'neutral';
	}
	$c = substr( $nr, 0, 1 );
	if ( '4' === $c ) {
		return 'einnahme';
	}
	if ( in_array( $c, array( '5', '6', '7' ), true ) ) {
		return 'ausgabe';
	}
	return 'bestand';
}

/** Erfolgskonto = Einnahme/Ausgabe oder noch fehlendes Sachkonto (1590/1599). */
function vp_bh_ist_erfolg( $nr ) {
	$nr = (string) $nr;
	if ( '' === $nr || VP_DOPPIK_INTERIM === $nr || VP_BH_INTERIM2 === $nr ) {
		return true;
	}
	return in_array( vp_bh_typ( $nr ), array( 'einnahme', 'ausgabe' ), true );
}

function vp_bh_ist_geld( $nr ) {
	return 'geld' === vp_bh_typ( $nr );
}

function vp_bh_konto_name( $nr ) {
	$info = vp_bh_konto_info();
	return $info[ (string) $nr ]['bezeichnung'] ?? '';
}

/** „1200 · Bank" */
function vp_bh_konto_label( $nr ) {
	$n = vp_bh_konto_name( $nr );
	return '' === (string) $nr ? '—' : ( $nr . ( $n ? ' · ' . $n : '' ) );
}

/** Alle Geldkonten (Typ „geld"), sortiert. @return array<string,string> nummer => bezeichnung */
function vp_bh_geldkonten() {
	$out = array();
	foreach ( vp_bh_konto_info() as $nr => $k ) {
		if ( 'geld' === $k['typ'] ) {
			$out[ (string) $nr ] = $k['bezeichnung'];
		}
	}
	uksort( $out, 'strnatcmp' );
	return $out;
}

/** Kompatibilität: Geldkonten wie früher als nummer => Beschriftung. */
function vp_doppik_geldkonten() {
	return vp_bh_geldkonten();
}

/* =========================================================================
 * Zeile ⇄ Buchungssatz
 * ====================================================================== */

/**
 * Konto A einer Zeile – explizit oder bei Altzeilen abgeleitet.
 * @param array|null $alt_map  bisherige quelle-Zuordnung (nur für die Umstellung)
 * @return array{0:string,1:bool}  [ Konto, Altzeile-mit-gegenkonto ]
 */
function vp_bh_konto_a( array $r, $alt_map = null ) {
	if ( '' !== (string) ( $r['geldkonto'] ?? '' ) ) {
		return array( (string) $r['geldkonto'], false );
	}
	if ( '' !== (string) ( $r['gegenkonto'] ?? '' ) ) {
		return array( (string) $r['gegenkonto'], true );
	}
	$q = (string) ( $r['quelle'] ?? 'Manuell' );
	if ( is_array( $alt_map ) ) {
		return array( (string) ( $alt_map[ $q ] ?? ( $alt_map['Manuell'] ?? '1200' ) ), false );
	}
	return array( vp_bh_vorgabe_geldkonto( $q ), false );
}

/**
 * Buchungssatz aus einer jb_buchungen-Zeile.
 * @return array{id:int,soll:string,haben:string,betrag:float,cent:int,datum:string,text:string,beleg:string}
 */
function vp_doppik_satz( array $r, $alt_map = null ) {
	list( $a, $legacy ) = vp_bh_konto_a( $r, $alt_map );
	$betrag = (float) ( $r['betrag'] ?? 0 );
	$cent   = (int) abs( round( $betrag * 100 ) );
	$b      = (string) ( $r['konto'] ?? '' );
	if ( '' === $b ) {
		$b = VP_DOPPIK_INTERIM === $a ? VP_BH_INTERIM2 : VP_DOPPIK_INTERIM;
	}
	$zugang = $legacy ? false : $betrag >= 0;
	return array(
		'id'     => (int) ( $r['id'] ?? 0 ),
		'soll'   => $zugang ? $a : $b,
		'haben'  => $zugang ? $b : $a,
		'cent'   => $cent,
		'betrag' => $cent / 100,
		'datum'  => (string) ( $r['buchung_datum'] ?? '' ),
		'text'   => trim( (string) ( $r['gegenpartei'] ?? '' ) . ' – ' . (string) ( $r['beschreibung'] ?? '' ), ' –' ),
		'beleg'  => (string) ( ( $r['beleg_nr'] ?? '' ) ?: ( $r['beleg_referenz'] ?? '' ) ),
	);
}

/** Soll/Haben/Betrag → Zeilenspalten (Umkehrung von vp_doppik_satz). */
function vp_bh_zeile_aus_satz( $soll, $haben, $betrag ) {
	$cent          = (int) abs( round( (float) $betrag * 100 ) );
	$soll_bestand  = ! vp_bh_ist_erfolg( $soll );
	$haben_bestand = ! vp_bh_ist_erfolg( $haben );
	if ( $soll_bestand && ! $haben_bestand ) {
		return array( 'geldkonto' => (string) $soll, 'konto' => (string) $haben, 'betrag' => $cent / 100 );
	}
	return array( 'geldkonto' => (string) $haben, 'konto' => (string) $soll, 'betrag' => -$cent / 100 );
}

/**
 * EÜR-Sicht einer Zeile. null, wenn sie nur als Buchungssatz darstellbar ist.
 * @return array|null  art einnahme|ausgabe (geldkonto, konto) oder umbuchung (von, nach); betrag positiv
 */
function vp_bh_euer_sicht( array $r ) {
	$s  = vp_doppik_satz( $r );
	$se = vp_bh_ist_erfolg( $s['soll'] );
	$he = vp_bh_ist_erfolg( $s['haben'] );
	if ( $se && $he ) {
		return null;
	}
	if ( ! $se && $he ) {
		return array( 'art' => 'einnahme', 'geldkonto' => $s['soll'], 'konto' => $s['haben'], 'betrag' => $s['betrag'] );
	}
	if ( $se && ! $he ) {
		return array( 'art' => 'ausgabe', 'geldkonto' => $s['haben'], 'konto' => $s['soll'], 'betrag' => $s['betrag'] );
	}
	return array( 'art' => 'umbuchung', 'von' => $s['haben'], 'nach' => $s['soll'], 'betrag' => $s['betrag'] );
}

/** EÜR-Eingabe → Zeilenspalten. */
function vp_bh_zeile_aus_euer( $art, $geldkonto, $konto, $betrag ) {
	$cent = (int) abs( round( (float) $betrag * 100 ) );
	if ( 'umbuchung' === $art ) { // geldkonto = von, konto = nach
		return array( 'geldkonto' => (string) $geldkonto, 'konto' => (string) $konto, 'betrag' => -$cent / 100 );
	}
	return array( 'geldkonto' => (string) $geldkonto, 'konto' => (string) $konto, 'betrag' => ( 'ausgabe' === $art ? -$cent : $cent ) / 100 );
}

/** Altzeile → explizite Spalten, ohne den Buchungssatz zu ändern. */
function vp_bh_migriere_zeile( array $r, $alt_map = null ) {
	list( $a, $legacy ) = vp_bh_konto_a( $r, $alt_map );
	$betrag = (float) ( $r['betrag'] ?? 0 );
	if ( $legacy ) {
		$betrag = -abs( round( $betrag, 2 ) );
	}
	return array( 'geldkonto' => $a, 'betrag' => $betrag, 'gegenkonto' => '' );
}

/**
 * Eingangsdaten einer neuen/geänderten Buchung vervollständigen: geldkonto
 * aus gegenkonto/quelle ableiten, falls es fehlt. Sphäre und Kategorie folgen
 * dem SKR-Konto, wenn sie nicht gesetzt sind.
 */
function vp_bh_buchung_ergaenzen( array $data ) {
	if ( '' === (string) ( $data['geldkonto'] ?? '' ) ) {
		$m = vp_bh_migriere_zeile( $data );
		$data['geldkonto']  = $m['geldkonto'];
		$data['betrag']     = $m['betrag'];
		$data['gegenkonto'] = '';
	}
	$konto = (string) ( $data['konto'] ?? '' );
	if ( $konto && '' === (string) ( $data['sphaere'] ?? '' ) ) {
		$data['sphaere'] = vp_bh_ist_erfolg( $konto ) ? ( vp_bh_konto_info()[ $konto ]['sphaere'] ?? '' ) : 'neutral';
	}
	if ( '' === (string) ( $data['kategorie'] ?? '' ) ) {
		$data['kategorie'] = $konto ? vp_bh_konto_label( $konto ) : 'Sonstige';
	}
	return $data;
}

/** Nach Schreibzugriffen alter App-Versionen: fehlendes geldkonto nachtragen. */
function vp_bh_normalisiere_buchung( $id ) {
	global $wpdb;
	$t   = jb_table_journal();
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE id = %d", (int) $id ), ARRAY_A );
	if ( ! $row || ! array_key_exists( 'geldkonto', $row ) || '' !== (string) $row['geldkonto'] ) {
		return;
	}
	$m = vp_bh_migriere_zeile( $row );
	$wpdb->update( $t, $m, array( 'id' => (int) $id ) );
}

/* =========================================================================
 * Geschäftsjahre: EÜR oder Doppik
 * ====================================================================== */

function jb_table_geschaeftsjahre() {
	global $wpdb;
	return $wpdb->prefix . 'jb_geschaeftsjahre';
}

function vp_bh_methoden() {
	return array(
		'euer'   => __( 'Einnahmen-Überschuss-Rechnung (EÜR)', 'vereinsplugin' ),
		'doppik' => __( 'Doppelte Buchführung (Doppik)', 'vereinsplugin' ),
	);
}

/** @return array<int,string> jahr => methode */
function vp_bh_geschaeftsjahre( $neu_laden = false ) {
	global $wpdb;
	static $gj = null;
	if ( null === $gj || $neu_laden ) {
		$gj = array();
		$t  = jb_table_geschaeftsjahre();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
			foreach ( (array) $wpdb->get_results( "SELECT jahr, methode FROM `{$t}`", ARRAY_A ) as $r ) {
				$gj[ (int) $r['jahr'] ] = 'doppik' === $r['methode'] ? 'doppik' : 'euer';
			}
		}
	}
	return $gj;
}

/** Buchführungsart eines Jahres. Ohne Eintrag gilt EÜR. */
function vp_bh_methode( $jahr ) {
	$gj = vp_bh_geschaeftsjahre();
	return $gj[ (int) $jahr ] ?? 'euer';
}

function vp_bh_methode_gesetzt( $jahr ) {
	return isset( vp_bh_geschaeftsjahre()[ (int) $jahr ] );
}

/** Alle Jahre mit Buchungen, Anfangsbeständen oder Eintrag – plus das laufende. */
function vp_bh_jahre() {
	global $wpdb;
	$jahre = array( (int) current_time( 'Y' ) );
	if ( function_exists( 'jb_table_journal' ) ) {
		$jahre = array_merge( $jahre, array_map( 'intval', (array) $wpdb->get_col( 'SELECT DISTINCT YEAR(buchung_datum) FROM ' . jb_table_journal() ) ) );
	}
	$jahre = array_merge( $jahre, vp_doppik_bestand_jahre(), array_keys( vp_bh_geschaeftsjahre() ) );
	$jahre = array_values( array_unique( array_filter( $jahre ) ) );
	rsort( $jahre );
	return $jahre;
}

/**
 * Buchführungsart festlegen. Auf EÜR geht nur, wenn sich jede Buchung des
 * Jahres als Einnahme, Ausgabe oder Umbuchung darstellen lässt.
 * @return true|WP_Error
 */
function vp_bh_methode_setzen( $jahr, $methode ) {
	global $wpdb;
	$jahr    = (int) $jahr;
	$methode = 'doppik' === $methode ? 'doppik' : 'euer';
	if ( $jahr < 1990 || $jahr > 2200 ) {
		return new WP_Error( 'bad_year', __( 'Ungültiges Jahr.', 'vereinsplugin' ) );
	}
	if ( 'euer' === $methode ) {
		$ids = wp_list_pluck( vp_bh_nicht_euer_konform( $jahr ), 'id' );
		if ( $ids ) {
			return new WP_Error( 'nicht_euer', sprintf(
				/* translators: 1: count, 2: year, 3: ids */
				__( '%1$d Buchung(en) in %2$d sind reine Buchungssätze ohne Geldkonto (z. B. Aufwand an Ertrag) und passen nicht in eine EÜR: #%3$s. Bitte diese zuerst ändern oder löschen.', 'vereinsplugin' ),
				count( $ids ), $jahr, implode( ', #', $ids )
			) );
		}
	}
	$t  = jb_table_geschaeftsjahre();
	$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$t}` WHERE jahr = %d", $jahr ) );
	if ( $id ) {
		$wpdb->update( $t, array( 'methode' => $methode ), array( 'id' => (int) $id ) );
	} else {
		$wpdb->insert( $t, array( 'jahr' => $jahr, 'methode' => $methode, 'erstellt_am' => current_time( 'mysql' ) ) );
	}
	vp_bh_cache_leeren();
	return true;
}

/* =========================================================================
 * Anfangsbestände
 * ====================================================================== */

/** Jahre, für die Anfangsbestände hinterlegt sind (aufsteigend). */
function vp_doppik_bestand_jahre() {
	global $wpdb;
	if ( ! function_exists( 'jb_table_anfangsbestaende' ) ) {
		return array();
	}
	$t = jb_table_anfangsbestaende();
	$j = $wpdb->get_col( "SELECT DISTINCT jahr FROM `{$t}` WHERE jahr > 0 ORDER BY jahr ASC" );
	return array_map( 'intval', (array) $j );
}

/** Jüngstes Jahr mit Anfangsbeständen, das nicht nach $jahr liegt. 0 = keines. */
function vp_doppik_basisjahr( $jahr = null ) {
	$jahr  = $jahr ? (int) $jahr : (int) current_time( 'Y' );
	$basis = 0;
	foreach ( vp_doppik_bestand_jahre() as $j ) {
		if ( $j <= $jahr && $j > $basis ) {
			$basis = $j;
		}
	}
	return $basis;
}

/** Anfangsbestände je Konto zum Beginn des Basisjahres (Euro). */
function vp_doppik_anfangsbestaende( $jahr = null ) {
	global $wpdb;
	$basis = vp_doppik_basisjahr( $jahr );
	$out   = array();
	if ( ! $basis ) {
		return $out;
	}
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT konto, betrag FROM ' . jb_table_anfangsbestaende() . ' WHERE jahr = %d', $basis ), ARRAY_A );
	foreach ( (array) $rows as $r ) {
		$k = (string) $r['konto'];
		if ( '' !== $k ) {
			$out[ $k ] = ( $out[ $k ] ?? 0 ) + (float) $r['betrag'];
		}
	}
	return $out;
}

/* =========================================================================
 * Auswertungen
 * ====================================================================== */

/**
 * Alles für die Auswertung eines Jahres in einem Durchlauf (Beträge in Cent):
 *   konten[nr] = { anfang (1.1.), soll, haben, anzahl (im Jahr), ende (31.12.) }
 *   saetze     = Buchungssätze des Jahres
 *   zeilen     = Rohzeilen des Jahres nach id
 */
function vp_bh_jahresdaten( $jahr = null ) {
	global $wpdb;
	$jahr = $jahr ? (int) $jahr : (int) current_time( 'Y' );
	if ( isset( $GLOBALS['vp_bh_jahresdaten'][ $jahr ] ) ) {
		return $GLOBALS['vp_bh_jahresdaten'][ $jahr ];
	}
	$d = array( 'jahr' => $jahr, 'basis' => vp_doppik_basisjahr( $jahr ), 'konten' => array(), 'saetze' => array(), 'zeilen' => array() );
	if ( ! function_exists( 'jb_table_journal' ) ) {
		return $d;
	}
	$acc = function ( $k ) use ( &$d ) {
		if ( ! isset( $d['konten'][ $k ] ) ) {
			$d['konten'][ $k ] = array( 'anfang' => 0, 'soll' => 0, 'haben' => 0, 'anzahl' => 0 );
		}
	};
	foreach ( vp_doppik_anfangsbestaende( $jahr ) as $k => $v ) {
		$acc( (string) $k );
		$d['konten'][ (string) $k ]['anfang'] += (int) round( $v * 100 );
	}

	$t      = jb_table_journal();
	$bis    = sprintf( '%04d-12-31', $jahr );
	$von_j  = sprintf( '%04d-01-01', $jahr );
	$sql    = $d['basis']
		? $wpdb->prepare( "SELECT * FROM `{$t}` WHERE buchung_datum >= %s AND buchung_datum <= %s ORDER BY buchung_datum ASC, id ASC", sprintf( '%04d-01-01', $d['basis'] ), $bis )
		: $wpdb->prepare( "SELECT * FROM `{$t}` WHERE buchung_datum <= %s ORDER BY buchung_datum ASC, id ASC", $bis );
	foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) {
		$s = vp_doppik_satz( $r );
		$acc( $s['soll'] );
		$acc( $s['haben'] );
		if ( (string) $r['buchung_datum'] < $von_j ) {
			$d['konten'][ $s['soll'] ]['anfang']  += $s['cent'];
			$d['konten'][ $s['haben'] ]['anfang'] -= $s['cent'];
			continue;
		}
		$d['saetze'][]                       = $s;
		$d['zeilen'][ (int) $r['id'] ]       = $r;
		$d['konten'][ $s['soll'] ]['soll']   += $s['cent'];
		$d['konten'][ $s['soll'] ]['anzahl']++;
		$d['konten'][ $s['haben'] ]['haben'] += $s['cent'];
		$d['konten'][ $s['haben'] ]['anzahl']++;
	}
	foreach ( $d['konten'] as $k => $v ) {
		$d['konten'][ $k ]['ende'] = $v['anfang'] + $v['soll'] - $v['haben'];
	}
	$GLOBALS['vp_bh_jahresdaten'][ $jahr ] = $d;
	return $d;
}

/** Nach Schreibzugriffen im selben Aufruf: zwischengespeicherte Auswertungen verwerfen. */
function vp_bh_cache_leeren() {
	unset( $GLOBALS['vp_bh_jahresdaten'] );
	vp_bh_konto_info( true );
	vp_bh_geschaeftsjahre( true );
}

/**
 * Summen- und Saldenliste. Soll erhöht, Haben senkt den Saldo.
 * @return array<int,array{konto,name,typ,anfang,soll,haben,saldo,anzahl}>
 */
function vp_doppik_salden( $jahr = null ) {
	$d   = vp_bh_jahresdaten( $jahr );
	$out = array();
	foreach ( $d['konten'] as $k => $v ) {
		if ( ! $v['anfang'] && ! $v['soll'] && ! $v['haben'] ) {
			continue;
		}
		$out[] = array(
			'konto'  => (string) $k,
			'name'   => vp_bh_konto_name( $k ),
			'typ'    => vp_bh_typ( $k ),
			'anfang' => $v['anfang'] / 100,
			'soll'   => $v['soll'] / 100,
			'haben'  => $v['haben'] / 100,
			'saldo'  => $v['ende'] / 100,
			'anzahl' => (int) $v['anzahl'],
		);
	}
	usort( $out, function ( $a, $b ) {
		return strnatcmp( $a['konto'], $b['konto'] );
	} );
	return $out;
}

/** Geldkonten mit Anfang, Zu- und Abgängen und Ende des Jahres (Euro). */
function vp_bh_geldkonten_stand( $jahr = null ) {
	$d   = vp_bh_jahresdaten( $jahr );
	$out = array();
	foreach ( vp_bh_geldkonten() as $nr => $name ) {
		$v     = $d['konten'][ $nr ] ?? array( 'anfang' => 0, 'soll' => 0, 'haben' => 0, 'ende' => 0 );
		$out[] = array(
			'konto'  => (string) $nr,
			'name'   => $name,
			'anfang' => $v['anfang'] / 100,
			'zugang' => $v['soll'] / 100,
			'abgang' => $v['haben'] / 100,
			'ende'   => $v['ende'] / 100,
		);
	}
	return $out;
}

/**
 * EÜR (bzw. GuV) eines Jahres aus den Buchungssätzen – Umbuchungen zwischen
 * Geldkonten zählen nie als Einnahme oder Ausgabe.
 */
function vp_bh_euer( $jahr = null ) {
	$d     = vp_bh_jahresdaten( $jahr );
	$konto = array();
	$ohne  = array( 'einnahmen' => 0, 'ausgaben' => 0, 'anzahl' => 0 );
	foreach ( $d['saetze'] as $s ) {
		foreach ( array( 'soll' => $s['soll'], 'haben' => $s['haben'] ) as $seite => $nr ) {
			if ( VP_DOPPIK_INTERIM === $nr || VP_BH_INTERIM2 === $nr ) {
				$ohne[ 'haben' === $seite ? 'einnahmen' : 'ausgaben' ] += $s['cent'];
				$ohne['anzahl']++;
				continue;
			}
			$typ = vp_bh_typ( $nr );
			if ( ! in_array( $typ, array( 'einnahme', 'ausgabe' ), true ) ) {
				continue;
			}
			if ( ! isset( $konto[ $nr ] ) ) {
				$konto[ $nr ] = array( 'einnahmen' => 0, 'ausgaben' => 0, 'anzahl' => 0 );
			}
			if ( 'einnahme' === $typ ) {
				$konto[ $nr ]['einnahmen'] += 'haben' === $seite ? $s['cent'] : -$s['cent'];
			} else {
				$konto[ $nr ]['ausgaben'] += 'soll' === $seite ? $s['cent'] : -$s['cent'];
			}
			$konto[ $nr ]['anzahl']++;
		}
	}

	$info    = vp_bh_konto_info();
	$labels  = function_exists( 'vp_skr_sphaeren' ) ? vp_skr_sphaeren() : array();
	$ein     = $ohne['einnahmen'];
	$aus     = $ohne['ausgaben'];
	$sph     = array();
	$liste   = array();
	foreach ( $konto as $nr => $v ) {
		$ein += $v['einnahmen'];
		$aus += $v['ausgaben'];
		$sp   = ( $info[ $nr ]['sphaere'] ?? '' ) ?: '—';
		if ( ! isset( $sph[ $sp ] ) ) {
			$sph[ $sp ] = array( 'einnahmen' => 0, 'ausgaben' => 0 );
		}
		$sph[ $sp ]['einnahmen'] += $v['einnahmen'];
		$sph[ $sp ]['ausgaben']  += $v['ausgaben'];
		$liste[] = array(
			'konto'     => (string) $nr,
			'name'      => $info[ $nr ]['bezeichnung'] ?? '',
			'typ'       => vp_bh_typ( $nr ),
			'sphaere'   => $info[ $nr ]['sphaere'] ?? '',
			'einnahmen' => $v['einnahmen'] / 100,
			'ausgaben'  => $v['ausgaben'] / 100,
			'anzahl'    => $v['anzahl'],
		);
	}
	if ( $ohne['anzahl'] ) {
		if ( ! isset( $sph['—'] ) ) {
			$sph['—'] = array( 'einnahmen' => 0, 'ausgaben' => 0 );
		}
		$sph['—']['einnahmen'] += $ohne['einnahmen'];
		$sph['—']['ausgaben']  += $ohne['ausgaben'];
	}
	usort( $liste, function ( $a, $b ) {
		return strnatcmp( $a['konto'], $b['konto'] );
	} );
	$sph_liste = array();
	foreach ( $sph as $k => $v ) {
		$sph_liste[] = array(
			'sphaere'   => $k,
			'label'     => $labels[ $k ] ?? ( '—' === $k ? __( 'ohne Sphäre', 'vereinsplugin' ) : $k ),
			'einnahmen' => $v['einnahmen'] / 100,
			'ausgaben'  => $v['ausgaben'] / 100,
			'saldo'     => ( $v['einnahmen'] - $v['ausgaben'] ) / 100,
		);
	}
	return array(
		'jahr'        => $d['jahr'],
		'einnahmen'   => $ein / 100,
		'ausgaben'    => $aus / 100,
		'ueberschuss' => ( $ein - $aus ) / 100,
		'pro_konto'   => $liste,
		'pro_sphaere' => $sph_liste,
		'ohne_konto'  => array( 'einnahmen' => $ohne['einnahmen'] / 100, 'ausgaben' => $ohne['ausgaben'] / 100, 'anzahl' => $ohne['anzahl'] ),
	);
}

/** Buchungen eines Jahres, die sich nicht als EÜR darstellen lassen. */
function vp_bh_nicht_euer_konform( $jahr ) {
	$out = array();
	foreach ( vp_bh_jahresdaten( $jahr )['zeilen'] as $r ) {
		if ( null === vp_bh_euer_sicht( $r ) ) {
			$out[] = $r;
		}
	}
	return $out;
}

/** Kontenblatt eines Kontos: Anfang, alle Bewegungen des Jahres mit Gegenkonto, laufender Saldo. */
function vp_doppik_kontenblatt( $konto, $jahr = null ) {
	$konto  = sanitize_text_field( (string) $konto );
	$d      = vp_bh_jahresdaten( $jahr );
	$saldo  = (int) ( $d['konten'][ $konto ]['anfang'] ?? 0 );
	$zeilen = array();
	foreach ( $d['saetze'] as $s ) {
		if ( $s['soll'] !== $konto && $s['haben'] !== $konto ) {
			continue;
		}
		$soll   = $s['soll'] === $konto ? $s['cent'] : 0;
		$haben  = $s['haben'] === $konto ? $s['cent'] : 0;
		$saldo += $soll - $haben;
		$zeilen[] = array(
			'id'    => $s['id'],
			'datum' => $s['datum'],
			'gegen' => $s['soll'] === $konto ? $s['haben'] : $s['soll'],
			'text'  => $s['text'],
			'beleg' => $s['beleg'],
			'soll'  => $soll / 100,
			'haben' => $haben / 100,
			'saldo' => $saldo / 100,
		);
	}
	return array(
		'konto'    => $konto,
		'jahr'     => $d['jahr'],
		'anfang'   => ( $d['konten'][ $konto ]['anfang'] ?? 0 ) / 100,
		'zeilen'   => $zeilen,
		'endsaldo' => $saldo / 100,
	);
}

/* =========================================================================
 * Schreiben
 * ====================================================================== */

/**
 * Buchungssatz anlegen → eine jb_buchungen-Zeile.
 * @return int|WP_Error neue Buchungs-ID
 */
function jb_buchungssatz_add( $soll, $haben, $betrag, $datum, $text = '', $beleg_nr = '', array $extra = array() ) {
	if ( ! function_exists( 'jb_journal_add' ) ) {
		return new WP_Error( 'no_fn', 'Buchhaltungs-Modul nicht geladen.' );
	}
	$soll   = sanitize_text_field( (string) $soll );
	$haben  = sanitize_text_field( (string) $haben );
	$betrag = round( abs( (float) str_replace( ',', '.', (string) $betrag ) ), 2 );
	if ( ! $soll || ! $haben || $soll === $haben || $betrag <= 0 ) {
		return new WP_Error( 'bad_req', __( 'Soll- und Haben-Konto (verschieden) und ein Betrag > 0 nötig.', 'vereinsplugin' ) );
	}
	$z    = vp_bh_zeile_aus_satz( $soll, $haben, $betrag );
	$data = array_merge( array(
		'buchung_datum' => sanitize_text_field( (string) $datum ) ?: current_time( 'Y-m-d' ),
		'beschreibung'  => sanitize_text_field( (string) $text ),
		'beleg_nr'      => sanitize_text_field( (string) $beleg_nr ),
		'quelle'        => 'Manuell',
	), $extra, $z );
	return (int) jb_journal_add( $data );
}

/**
 * Konto $von in $nach aufgehen lassen: alle Buchungen, Anfangsbestände,
 * Budgets, Regeln und Vorgaben zeigen danach auf $nach; $von wird inaktiv.
 * @return array|WP_Error  Anzahl geänderter Einträge je Bereich
 */
function vp_bh_konten_zusammenlegen( $von, $nach ) {
	global $wpdb;
	$von  = sanitize_text_field( (string) $von );
	$nach = sanitize_text_field( (string) $nach );
	$info = vp_bh_konto_info( true );
	if ( ! $von || ! $nach || $von === $nach ) {
		return new WP_Error( 'bad_req', __( 'Zwei verschiedene Konten wählen.', 'vereinsplugin' ) );
	}
	if ( ! isset( $info[ $nach ] ) ) {
		return new WP_Error( 'bad_req', __( 'Das Zielkonto steht nicht im Kontenplan.', 'vereinsplugin' ) );
	}
	if ( vp_bh_ist_erfolg( $von ) !== vp_bh_ist_erfolg( $nach ) ) {
		return new WP_Error( 'bad_req', __( 'Ein Geld-/Bestandskonto lässt sich nur mit einem Geld-/Bestandskonto zusammenlegen, ein Einnahme-/Ausgabekonto nur mit einem Einnahme-/Ausgabekonto.', 'vereinsplugin' ) );
	}
	$j   = jb_table_journal();
	$res = array();
	$res['buchungen'] = (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$j}` SET geldkonto = %s WHERE geldkonto = %s", $nach, $von ) )
		+ (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$j}` SET konto = %s WHERE konto = %s", $nach, $von ) )
		+ (int) $wpdb->query( $wpdb->prepare( "UPDATE `{$j}` SET gegenkonto = %s WHERE gegenkonto = %s", $nach, $von ) );
	$res['ohne_wirkung'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$j}` WHERE geldkonto = %s AND konto = %s", $nach, $nach ) );

	$t = jb_table_anfangsbestaende();
	$res['anfangsbestaende'] = 0;
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE konto = %s", $von ), ARRAY_A ) as $a ) {
		$ziel = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$t}` WHERE jahr = %d AND konto = %s", (int) $a['jahr'], $nach ), ARRAY_A );
		if ( $ziel ) {
			$wpdb->update( $t, array( 'betrag' => round( (float) $ziel['betrag'] + (float) $a['betrag'], 2 ) ), array( 'id' => (int) $ziel['id'] ) );
			$wpdb->delete( $t, array( 'id' => (int) $a['id'] ) );
		} else {
			$wpdb->update( $t, array( 'konto' => $nach ), array( 'id' => (int) $a['id'] ) );
		}
		$res['anfangsbestaende']++;
	}
	foreach ( array( 'jb_budgets', 'jb_konto_regeln', 'jb_auslagen' ) as $tab ) {
		$tt = $wpdb->prefix . $tab;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tt ) ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE `{$tt}` SET konto = %s WHERE konto = %s", $nach, $von ) ); // phpcs:ignore
		}
	}
	$map  = vp_doppik_map();
	$zeil = array();
	foreach ( $map as $q => $k ) {
		$zeil[] = $q . ' = ' . ( (string) $k === $von ? $nach : $k );
	}
	update_option( 'jb_quelle_konto_map', implode( "\n", $zeil ) );
	$wpdb->update( jb_table_konten(), array( 'aktiv' => 0 ), array( 'nummer' => $von ) );
	vp_bh_cache_leeren();
	return $res;
}

/**
 * Jahresabschluss: Endbestände aller Geld- und Bestandskonten als
 * Anfangsbestände des Folgejahres setzen und dessen Buchführungsart
 * übernehmen (falls noch nicht festgelegt).
 * @return array{jahr:int,konten:int}
 */
function vp_bh_jahresabschluss( $jahr ) {
	global $wpdb;
	$jahr = (int) $jahr;
	$nach = $jahr + 1;
	$t    = jb_table_anfangsbestaende();
	$n    = 0;
	foreach ( vp_bh_jahresdaten( $jahr )['konten'] as $k => $v ) {
		$k = (string) $k;
		if ( vp_bh_ist_erfolg( $k ) ) {
			continue;
		}
		$betrag = $v['ende'] / 100;
		$id     = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$t}` WHERE jahr = %d AND konto = %s", $nach, $k ) );
		if ( 0 === (int) $v['ende'] ) {
			if ( $id ) {
				$wpdb->delete( $t, array( 'id' => (int) $id ) );
			}
			continue;
		}
		if ( $id ) {
			$wpdb->update( $t, array( 'betrag' => $betrag ), array( 'id' => (int) $id ) );
		} else {
			$wpdb->insert( $t, array(
				'jahr'        => $nach,
				'konto'       => $k,
				'betrag'      => $betrag,
				/* translators: %d = year */
				'notiz'       => sprintf( __( 'Endbestand %d übernommen', 'vereinsplugin' ), $jahr ),
				'erstellt_am' => current_time( 'mysql' ),
			) );
		}
		$n++;
	}
	if ( ! vp_bh_methode_gesetzt( $nach ) ) {
		vp_bh_methode_setzen( $nach, vp_bh_methode( $jahr ) );
	}
	vp_bh_cache_leeren();
	return array( 'jahr' => $nach, 'konten' => $n );
}

/**
 * Stimmen die Anfangsbestände des Folgejahres noch mit den berechneten
 * Endbeständen überein? (Weicht ab, wenn nach dem Abschluss noch gebucht wurde.)
 * @return array<int,array{konto:string,ende:float,anfang_folgejahr:float}>
 */
function vp_bh_abschluss_abweichungen( $jahr ) {
	global $wpdb;
	$nach = (int) $jahr + 1;
	if ( ! in_array( $nach, vp_doppik_bestand_jahre(), true ) ) {
		return array();
	}
	$anf = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT konto, betrag FROM ' . jb_table_anfangsbestaende() . ' WHERE jahr = %d', $nach ), ARRAY_A ) as $r ) {
		$anf[ (string) $r['konto'] ] = (int) round( (float) $r['betrag'] * 100 );
	}
	$out = array();
	$d   = vp_bh_jahresdaten( $jahr );
	foreach ( array_unique( array_merge( array_keys( $d['konten'] ), array_keys( $anf ) ) ) as $k ) {
		$k = (string) $k;
		if ( vp_bh_ist_erfolg( $k ) ) {
			continue;
		}
		$ende = (int) ( $d['konten'][ $k ]['ende'] ?? 0 );
		if ( abs( $ende - ( $anf[ $k ] ?? 0 ) ) > 0 ) {
			$out[] = array( 'konto' => $k, 'ende' => $ende / 100, 'anfang_folgejahr' => ( $anf[ $k ] ?? 0 ) / 100 );
		}
	}
	return $out;
}

/* =========================================================================
 * Einmalige Umstellung auf feste Geldkonten (v0.33)
 * ====================================================================== */

/**
 * Schreibt das bisher abgeleitete Geldkonto in jede Buchung, legt EÜR-
 * Geschäftsjahre an und kennzeichnet Geldkonten. Jede Zeile wird vorher und
 * nachher als Buchungssatz verglichen; weicht etwas ab, bleibt die Zeile
 * unverändert und steht im Bericht. Vorher wird jb_buchungen gesichert.
 */
function vp_bh_umstellung_v10() {
	global $wpdb;
	if ( '1' === get_option( 'jb_umstellung_v10' ) || ! function_exists( 'jb_table_journal' ) ) {
		return;
	}
	$j = jb_table_journal();
	if ( ! in_array( 'geldkonto', (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$j}`" ), true ) ) {
		return; // Spalte fehlt noch – beim nächsten Laden erneut versuchen.
	}
	$bericht = array(
		'datum'        => current_time( 'mysql' ),
		'buchungen'    => 0,
		'umgestellt'   => 0,
		'abweichungen' => array(),
		'hinweise'     => array(),
		'sicherung'    => '',
	);

	// 0. Sicherungskopie
	$backup = $wpdb->prefix . 'jb_buchungen_vor_v033';
	if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $backup ) ) ) {
		$wpdb->query( "CREATE TABLE `{$backup}` AS SELECT * FROM `{$j}`" ); // phpcs:ignore
	}
	$bericht['sicherung'] = $backup;

	// 1. Bisherige Zuordnung; Zettle-Karte und PayPal sind dasselbe Konto.
	$map                 = vp_doppik_map();
	$map['Zettle-Karte'] = $map['PayPal'] ?? '1220';
	$info                = vp_bh_konto_info( true );
	$muster              = array(
		'Bank KSK'     => array( '/bank/i', __( 'Bank', 'vereinsplugin' ) ),
		'Manuell'      => array( '/bank/i', __( 'Bank', 'vereinsplugin' ) ),
		'Zettle-Bar'   => array( '/kasse|bar/i', __( 'Barkasse', 'vereinsplugin' ) ),
		'Bar'          => array( '/kasse|bar/i', __( 'Barkasse', 'vereinsplugin' ) ),
		'PayPal'       => array( '/paypal|zettle/i', 'PayPal' ),
		'Zettle-Karte' => array( '/paypal|zettle/i', 'PayPal' ),
	);
	foreach ( $muster as $q => $m ) {
		$ziel = (string) ( $map[ $q ] ?? '' );
		if ( '' === $ziel || isset( $info[ $ziel ] ) ) {
			continue;
		}
		// Das zugeordnete Konto fehlt im Kontenplan (z. B. umnummeriert).
		$treffer = array();
		foreach ( $info as $nr => $k ) {
			if ( ! in_array( $k['typ'], array( 'einnahme', 'ausgabe' ), true ) && preg_match( $m[0], $k['bezeichnung'] ) ) {
				$treffer[] = (string) $nr;
			}
		}
		if ( 1 === count( $treffer ) ) {
			$map[ $q ] = $treffer[0];
			/* translators: 1: source, 2: old account, 3: new account */
			$bericht['hinweise'][] = sprintf( __( '„%1$s" zeigte auf Konto %2$s, das es im Kontenplan nicht gibt – übernommen auf %3$s.', 'vereinsplugin' ), $q, $ziel, $treffer[0] );
		} else {
			$wpdb->insert( jb_table_konten(), array( 'nummer' => $ziel, 'bezeichnung' => $m[1], 'typ' => 'geld', 'sphaere' => 'neutral', 'aktiv' => 1, 'sort' => 5 ) );
			/* translators: 1: account, 2: source */
			$bericht['hinweise'][] = sprintf( __( 'Konto %1$s („%2$s") fehlte im Kontenplan und wurde angelegt.', 'vereinsplugin' ), $ziel, $q );
			$info = vp_bh_konto_info( true );
		}
	}

	// 2. Buchungen: geldkonto festschreiben, Satz vorher/nachher vergleichen.
	foreach ( (array) $wpdb->get_results( "SELECT * FROM `{$j}`", ARRAY_A ) as $r ) {
		$bericht['buchungen']++;
		if ( '' !== (string) $r['geldkonto'] ) {
			continue;
		}
		$vorher  = vp_doppik_satz( $r, $map );
		$neu     = vp_bh_migriere_zeile( $r, $map );
		$nachher = vp_doppik_satz( array_merge( $r, $neu ) );
		if ( $vorher['soll'] !== $nachher['soll'] || $vorher['haben'] !== $nachher['haben'] || $vorher['cent'] !== $nachher['cent'] ) {
			$bericht['abweichungen'][] = (int) $r['id'];
			continue;
		}
		$wpdb->update( $j, $neu, array( 'id' => (int) $r['id'] ) );
		$bericht['umgestellt']++;
	}

	// 3. Anfangsbestände auf Konten, die es nicht mehr gibt, zum neuen Konto.
	$std = vp_doppik_default_map();
	$t   = jb_table_anfangsbestaende();
	foreach ( (array) $wpdb->get_results( "SELECT * FROM `{$t}`", ARRAY_A ) as $a ) {
		$k = (string) $a['konto'];
		if ( isset( $info[ $k ] ) ) {
			continue;
		}
		$ziel = '';
		foreach ( $std as $q => $d ) {
			if ( (string) $d === $k && isset( $map[ $q ], $info[ (string) $map[ $q ] ] ) && (string) $map[ $q ] !== $k ) {
				$ziel = (string) $map[ $q ];
				break;
			}
		}
		if ( ! $ziel ) {
			continue;
		}
		$betrag = number_format( (float) $a['betrag'], 2, ',', '.' );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$t}` WHERE jahr = %d AND konto = %s", (int) $a['jahr'], $ziel ) ) ) {
			/* translators: 1: year, 2: account, 3: amount, 4: target */
			$bericht['hinweise'][] = sprintf( __( 'Anfangsbestand %1$d auf Konto %2$s (%3$s €) bitte prüfen – das Konto gibt es nicht mehr, auf %4$s steht aber schon ein Wert.', 'vereinsplugin' ), (int) $a['jahr'], $k, $betrag, $ziel );
			continue;
		}
		$wpdb->update( $t, array( 'konto' => $ziel, 'notiz' => trim( $a['notiz'] . ' (vorher Konto ' . $k . ')' ) ), array( 'id' => (int) $a['id'] ) );
		/* translators: 1: year, 2: amount, 3: old account, 4: new account */
		$bericht['hinweise'][] = sprintf( __( 'Anfangsbestand %1$d (%2$s €) von Konto %3$s, das es nicht mehr gibt, auf %4$s übertragen.', 'vereinsplugin' ), (int) $a['jahr'], $betrag, $k, $ziel );
	}

	// 4. Geldkonten kennzeichnen.
	foreach ( array( 'Bank KSK', 'Zettle-Bar', 'PayPal' ) as $q ) {
		$nr = (string) ( $map[ $q ] ?? '' );
		if ( isset( $info[ $nr ] ) && ! in_array( $info[ $nr ]['typ'], array( 'einnahme', 'ausgabe', 'geld' ), true ) ) {
			$wpdb->update( jb_table_konten(), array( 'typ' => 'geld' ), array( 'nummer' => $nr ) );
		}
	}
	vp_bh_konto_info( true );

	// 5. Vorgaben für automatische Buchungen festhalten.
	$zeilen = array();
	foreach ( array_keys( vp_bh_vorgabe_quellen() ) as $q ) {
		if ( isset( $map[ $q ] ) ) {
			$zeilen[] = $q . ' = ' . $map[ $q ];
		}
	}
	update_option( 'jb_quelle_konto_map', implode( "\n", $zeilen ) );

	// 6. Bisher geführte Jahre als EÜR anlegen.
	foreach ( vp_bh_jahre() as $jahr ) {
		if ( ! vp_bh_methode_gesetzt( $jahr ) ) {
			$wpdb->insert( jb_table_geschaeftsjahre(), array( 'jahr' => (int) $jahr, 'methode' => 'euer', 'erstellt_am' => current_time( 'mysql' ) ) );
		}
	}
	vp_bh_geschaeftsjahre( true );

	// 7. Änderungszeit setzen, damit die Desktop-App alles neu lädt.
	foreach ( array( $j, $t ) as $tab ) {
		if ( in_array( 'geaendert_am', (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$tab}`" ), true ) ) {
			$wpdb->query( "UPDATE `{$tab}` SET geaendert_am = CURRENT_TIMESTAMP" ); // phpcs:ignore
		}
	}

	update_option( 'jb_umstellung_v10_bericht', $bericht, false );
	update_option( 'jb_umstellung_v10', '1' );
	vp_bh_cache_leeren();
}
