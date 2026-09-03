<?php
/**
 * Kern: Doppik-Ansicht auf der EÜR-Basis.
 *
 * Die Speicherung bleibt einfach (eine jb_buchungen-Zeile pro Vorgang). Diese
 * Datei leitet daraus Buchungssätze (Soll/Haben), Kontensalden und Kontenblätter
 * ab und bietet eine Buchungssatz-Eingabe, die zurück in eine jb_buchungen-Zeile
 * übersetzt. EÜR, DATEV-Export und Kassenbericht bleiben unberührt.
 *
 * Zuordnung: jede „quelle" entspricht einem Geld-/Bestandskonto.
 *   betrag > 0 (Einnahme):  Soll = Geldkonto(quelle)   Haben = konto (4xxx)
 *   betrag < 0 (Ausgabe):   Soll = konto (5xxx)         Haben = Geldkonto(quelle)
 *   gegenkonto gesetzt (Umbuchung): Soll = konto        Haben = gegenkonto
 */

defined( 'ABSPATH' ) || exit;

/** Standard-Zuordnung quelle → Bestandskonto. */
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

/** Aktuelle Zuordnung (Option `jb_quelle_konto_map`, Zeilen „quelle = konto"). */
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

function vp_doppik_konto_fuer_quelle( $quelle ) {
	$map = vp_doppik_map();
	return $map[ $quelle ] ?? ( $map['Manuell'] ?? '1200' );
}

/**
 * Buchungssatz aus einer jb_buchungen-Zeile.
 * @return array{soll:string,haben:string,betrag:float,datum:string,text:string,beleg:string,id:int}
 */
function vp_doppik_satz( array $r ) {
	$betrag = (float) $r['betrag'];
	$abs    = round( abs( $betrag ), 2 );
	$konto  = (string) ( $r['konto'] ?? '' );
	$gegen  = (string) ( $r['gegenkonto'] ?? '' );
	$geld   = $gegen ?: vp_doppik_konto_fuer_quelle( (string) ( $r['quelle'] ?? 'Manuell' ) );

	if ( $gegen ) {                       // Umbuchung: konto = Ziel, gegenkonto = Quelle
		$soll = $konto ?: $geld;
		$haben = $gegen;
	} elseif ( $betrag >= 0 ) {           // Einnahme
		$soll  = $geld;
		$haben = $konto ?: $geld;
	} else {                              // Ausgabe
		$soll  = $konto ?: $geld;
		$haben = $geld;
	}

	return array(
		'id'     => (int) ( $r['id'] ?? 0 ),
		'soll'   => $soll,
		'haben'  => $haben,
		'betrag' => $abs,
		'datum'  => (string) ( $r['buchung_datum'] ?? '' ),
		'text'   => trim( (string) ( $r['gegenpartei'] ?? '' ) . ' – ' . (string) ( $r['beschreibung'] ?? '' ), ' –' ),
		'beleg'  => (string) ( $r['beleg_nr'] ?? $r['beleg_referenz'] ?? '' ),
	);
}

/** Anfangsbestände je Bestandskonto (aus den jb_anfangsbestand_*-Optionen). */
function vp_doppik_anfangsbestaende() {
	$map = array(
		'bank'   => '1200',
		'kasse'  => '1000',
		'paypal' => '1220',
		'zettle' => '1360',
	);
	$out = array();
	foreach ( $map as $k => $konto ) {
		$v = (float) get_option( 'jb_anfangsbestand_' . $k, 0 );
		if ( $v ) {
			$out[ $konto ] = ( $out[ $konto ] ?? 0 ) + $v;
		}
	}
	return $out;
}

/**
 * Salden je Konto. Soll erhöht, Haben senkt den Saldo. Bestands-/Ausgabekonten
 * haben normalerweise einen positiven (Soll-)Saldo, Einnahmekonten einen
 * negativen (Haben-)Saldo.
 * @return array<int,array{konto,name,typ,soll,haben,saldo}>
 */
function vp_doppik_salden() {
	global $wpdb;
	$t = $wpdb->prefix . 'jb_buchungen';
	if ( ! function_exists( 'jb_konten_all' ) ) {
		return array();
	}
	$namen = array();
	$typ   = array();
	foreach ( (array) jb_konten_all( false ) as $k ) {
		$namen[ (string) $k->nummer ] = $k->bezeichnung;
		$typ[ (string) $k->nummer ]   = $k->typ;
	}

	$acc = array();
	$bump = function ( $konto, $soll, $haben ) use ( &$acc ) {
		if ( '' === $konto ) {
			return;
		}
		if ( ! isset( $acc[ $konto ] ) ) {
			$acc[ $konto ] = array( 'soll' => 0.0, 'haben' => 0.0 );
		}
		$acc[ $konto ]['soll']  += $soll;
		$acc[ $konto ]['haben'] += $haben;
	};

	foreach ( vp_doppik_anfangsbestaende() as $konto => $v ) {
		$bump( $konto, $v, 0 );
	}

	$rows = $wpdb->get_results( "SELECT id, buchung_datum, betrag, konto, gegenkonto, quelle, beschreibung, gegenpartei, beleg_nr, beleg_referenz FROM `$t`", ARRAY_A );
	foreach ( (array) $rows as $r ) {
		$s = vp_doppik_satz( $r );
		$bump( $s['soll'], $s['betrag'], 0 );
		$bump( $s['haben'], 0, $s['betrag'] );
	}

	$out = array();
	foreach ( $acc as $konto => $v ) {
		$saldo = round( $v['soll'] - $v['haben'], 2 );
		$out[] = array(
			'konto' => (string) $konto,
			'name'  => $namen[ (string) $konto ] ?? '',
			'typ'   => $typ[ (string) $konto ] ?? '',
			'soll'  => round( $v['soll'], 2 ),
			'haben' => round( $v['haben'], 2 ),
			'saldo' => $saldo,
		);
	}
	usort( $out, function ( $a, $b ) {
		return strnatcmp( $a['konto'], $b['konto'] );
	} );
	return $out;
}

/** Kontenblatt (Ledger) für ein Konto: alle berührenden Buchungen + laufender Saldo. */
function vp_doppik_kontenblatt( $konto ) {
	global $wpdb;
	$t = $wpdb->prefix . 'jb_buchungen';
	$konto = sanitize_text_field( (string) $konto );
	$rows  = $wpdb->get_results( "SELECT id, buchung_datum, betrag, konto, gegenkonto, quelle, beschreibung, gegenpartei, beleg_nr, beleg_referenz FROM `$t` ORDER BY buchung_datum ASC, id ASC", ARRAY_A );

	$anf = vp_doppik_anfangsbestaende();
	$saldo = (float) ( $anf[ $konto ] ?? 0 );
	$zeilen = array();
	if ( $saldo ) {
		$zeilen[] = array( 'datum' => '', 'text' => __( 'Anfangsbestand', 'vereinsplugin' ), 'soll' => $saldo > 0 ? $saldo : 0, 'haben' => $saldo < 0 ? -$saldo : 0, 'saldo' => round( $saldo, 2 ), 'id' => 0 );
	}
	foreach ( (array) $rows as $r ) {
		$s = vp_doppik_satz( $r );
		if ( $s['soll'] !== $konto && $s['haben'] !== $konto ) {
			continue;
		}
		$soll  = $s['soll'] === $konto ? $s['betrag'] : 0;
		$haben = $s['haben'] === $konto ? $s['betrag'] : 0;
		$saldo += $soll - $haben;
		$gegen = $s['soll'] === $konto ? $s['haben'] : $s['soll'];
		$zeilen[] = array(
			'id'    => $s['id'],
			'datum' => $s['datum'],
			'text'  => trim( ( $s['text'] ?: '—' ) . '  (Gegenkonto ' . $gegen . ')' ),
			'soll'  => round( $soll, 2 ),
			'haben' => round( $haben, 2 ),
			'saldo' => round( $saldo, 2 ),
		);
	}
	return array( 'konto' => $konto, 'zeilen' => $zeilen, 'endsaldo' => round( $saldo, 2 ) );
}

/**
 * Buchungssatz anlegen → in eine jb_buchungen-Zeile übersetzen.
 * @return int|WP_Error  neue Buchungs-ID
 */
function jb_buchungssatz_add( $soll, $haben, $betrag, $datum, $text = '', $beleg_nr = '' ) {
	if ( ! function_exists( 'jb_journal_add' ) ) {
		return new WP_Error( 'no_fn', 'Buchhaltungs-Modul nicht geladen.' );
	}
	$soll   = sanitize_text_field( (string) $soll );
	$haben  = sanitize_text_field( (string) $haben );
	$betrag = round( abs( (float) str_replace( ',', '.', (string) $betrag ) ), 2 );
	if ( ! $soll || ! $haben || $soll === $haben || $betrag <= 0 ) {
		return new WP_Error( 'bad_req', 'Soll- und Haben-Konto (verschieden) und ein Betrag > 0 nötig.' );
	}

	$typ_of = function ( $nr ) {
		$k = function_exists( 'jb_konto_get' ) ? jb_konto_get( $nr ) : null;
		return $k ? (string) $k->typ : '';
	};
	$ts = $typ_of( $soll );
	$th = $typ_of( $haben );
	$ist_bestand = function ( $typ, $nr ) {
		return in_array( $typ, array( 'bestand', 'neutral' ), true ) || 0 === strpos( (string) $nr, '1' );
	};
	$bestand_s = $ist_bestand( $ts, $soll );
	$bestand_h = $ist_bestand( $th, $haben );

	$quelle_fuer = function ( $konto ) {
		foreach ( vp_doppik_map() as $q => $k ) {
			if ( (string) $k === (string) $konto ) {
				return $q;
			}
		}
		return 'Manuell';
	};

	$data = array(
		'buchung_datum' => sanitize_text_field( (string) $datum ) ?: current_time( 'Y-m-d' ),
		'beschreibung'  => sanitize_text_field( (string) $text ),
		'gegenpartei'   => sanitize_text_field( (string) $text ),
		'beleg_nr'      => sanitize_text_field( (string) $beleg_nr ),
	);

	if ( $bestand_s && $bestand_h ) {
		// Umbuchung: Soll = Ziel (konto), Haben = Quelle (gegenkonto)
		$data['betrag']     = $betrag;
		$data['konto']      = $soll;
		$data['gegenkonto'] = $haben;
		$data['sphaere']    = 'neutral';
		$data['kategorie']  = 'Umbuchung';
		$data['quelle']     = 'Umbuchung';
	} elseif ( $bestand_s && ! $bestand_h ) {
		// Einnahme: Soll Geldkonto, Haben Ertragskonto
		$data['betrag']    = $betrag;
		$data['konto']     = $haben;
		$data['quelle']    = $quelle_fuer( $soll );
		$data['sphaere']   = function_exists( 'jb_konto_sphaere' ) ? jb_konto_sphaere( $haben ) : '';
		$data['kategorie'] = $haben . ' ' . ( $typ_of( $haben ) ? '' : '' );
	} elseif ( ! $bestand_s && $bestand_h ) {
		// Ausgabe: Soll Aufwandskonto, Haben Geldkonto
		$data['betrag']    = -$betrag;
		$data['konto']     = $soll;
		$data['quelle']    = $quelle_fuer( $haben );
		$data['sphaere']   = function_exists( 'jb_konto_sphaere' ) ? jb_konto_sphaere( $soll ) : '';
		$data['kategorie'] = $soll;
	} else {
		// Erfolg an Erfolg (selten) – als neutrale Umbuchung ablegen.
		$data['betrag']     = $betrag;
		$data['konto']      = $soll;
		$data['gegenkonto'] = $haben;
		$data['sphaere']    = 'neutral';
		$data['kategorie']  = 'Umbuchung';
		$data['quelle']     = 'Umbuchung';
	}

	return (int) jb_journal_add( $data );
}

/* =========================================================================
 * Frontend: Hub-Tab „Doppik" (Buchungssatz + Kontenblätter)
 * ====================================================================== */

function vp_bh_doppik() {
	if ( ! function_exists( 'vp_doppik_salden' ) ) {
		return '';
	}
	$can_edit = current_user_can( 'jb_edit_journal' ) || current_user_can( 'manage_options' );
	$msg = '';

	if ( $can_edit && isset( $_POST['vp_satz_add'] ) && check_admin_referer( 'vp_bh_doppik', 'vp_doppik_nonce' ) ) {
		$r = jb_buchungssatz_add(
			wp_unslash( $_POST['soll'] ?? '' ),
			wp_unslash( $_POST['haben'] ?? '' ),
			wp_unslash( $_POST['betrag'] ?? '0' ),
			wp_unslash( $_POST['datum'] ?? '' ),
			wp_unslash( $_POST['text'] ?? '' ),
			wp_unslash( $_POST['beleg'] ?? '' )
		);
		$msg = is_wp_error( $r ) ? $r->get_error_message() : __( 'Buchungssatz gespeichert.', 'vereinsplugin' );
	}

	$konten = function_exists( 'jb_konten_all' ) ? jb_konten_all( false ) : array();
	$sel    = isset( $_GET['kb'] ) ? sanitize_text_field( wp_unslash( $_GET['kb'] ) ) : '';
	$base   = get_permalink() ?: remove_query_arg( array( 'kb' ) );

	ob_start();
	echo '<h2>' . esc_html__( 'Doppik – Buchungssätze & Kontenblätter', 'vereinsplugin' ) . '</h2>';
	echo '<p class="vp-muted">' . esc_html__( 'Sicht auf dieselben Daten in Soll/Haben. EÜR und Exporte bleiben unverändert.', 'vereinsplugin' ) . '</p>';
	if ( $msg ) {
		echo '<div class="vp-note">' . esc_html( $msg ) . '</div>';
	}

	if ( $can_edit ) {
		$opts = '';
		foreach ( $konten as $k ) {
			$opts .= '<option value="' . esc_attr( $k->nummer ) . '">' . esc_html( $k->nummer . ' · ' . $k->bezeichnung ) . '</option>';
		}
		echo '<details class="vp-card"><summary><strong>' . esc_html__( 'Buchungssatz erfassen', 'vereinsplugin' ) . '</strong></summary>';
		echo '<form method="post" class="vp-form" style="margin-top:10px">' . wp_nonce_field( 'vp_bh_doppik', 'vp_doppik_nonce', true, false );
		echo '<div class="vp-form-grid">';
		echo '<label>' . esc_html__( 'Datum', 'vereinsplugin' ) . '<input type="date" name="datum" value="' . esc_attr( gmdate( 'Y-m-d' ) ) . '"></label>';
		echo '<label>' . esc_html__( 'Betrag (€)', 'vereinsplugin' ) . '<input type="text" name="betrag" inputmode="decimal" placeholder="0,00"></label>';
		echo '<label>' . esc_html__( 'Soll (an dieses Konto)', 'vereinsplugin' ) . '<select name="soll">' . $opts . '</select></label>';
		echo '<label>' . esc_html__( 'Haben (von diesem Konto)', 'vereinsplugin' ) . '<select name="haben">' . $opts . '</select></label>';
		echo '<label class="vp-col-2">' . esc_html__( 'Buchungstext', 'vereinsplugin' ) . '<input type="text" name="text"></label>';
		echo '<label>' . esc_html__( 'Beleg-Nr.', 'vereinsplugin' ) . '<input type="text" name="beleg"></label>';
		echo '</div><p><button class="vp-btn vp-btn-primary" name="vp_satz_add" value="1">' . esc_html__( 'Buchen', 'vereinsplugin' ) . '</button> ';
		echo '<span class="vp-muted">' . esc_html__( 'Wird als eine Journalzeile gespeichert (E-Konto ↔ Geldkonto), Geldkonto↔Geldkonto als Umbuchung.', 'vereinsplugin' ) . '</span></p></form></details>';
	}

	// Kontenübersicht
	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Konto', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Bezeichnung', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Typ', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Soll', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Haben', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Saldo', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
	foreach ( vp_doppik_salden() as $s ) {
		printf(
			'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td style="text-align:right">%s</td><td style="text-align:right">%s</td><td style="text-align:right"><strong>%s</strong></td></tr>',
			esc_url( add_query_arg( array( 'vp_tab' => 'buchhaltung', 'vp_bh' => 'doppik', 'kb' => $s['konto'] ), $base ) ),
			esc_html( $s['konto'] ),
			esc_html( $s['name'] ),
			esc_html( $s['typ'] ),
			esc_html( number_format( $s['soll'], 2, ',', '.' ) ),
			esc_html( number_format( $s['haben'], 2, ',', '.' ) ),
			esc_html( number_format( $s['saldo'], 2, ',', '.' ) )
		);
	}
	echo '</tbody></table></div>';

	// Kontenblatt
	if ( $sel ) {
		$kb = vp_doppik_kontenblatt( $sel );
		echo '<h3>' . esc_html( sprintf( __( 'Kontenblatt %s', 'vereinsplugin' ), $sel ) ) . '</h3>';
		echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Datum', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Text', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Soll', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Haben', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Saldo', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
		foreach ( $kb['zeilen'] as $z ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td style="text-align:right">%s</td><td style="text-align:right">%s</td><td style="text-align:right">%s</td></tr>',
				esc_html( $z['datum'] ),
				esc_html( $z['text'] ),
				esc_html( $z['soll'] ? number_format( $z['soll'], 2, ',', '.' ) : '' ),
				esc_html( $z['haben'] ? number_format( $z['haben'], 2, ',', '.' ) : '' ),
				esc_html( number_format( $z['saldo'], 2, ',', '.' ) )
			);
		}
		printf( '<tr style="font-weight:700"><td colspan="4">%s</td><td style="text-align:right">%s</td></tr>', esc_html__( 'Endsaldo', 'vereinsplugin' ), esc_html( number_format( $kb['endsaldo'], 2, ',', '.' ) ) );
		echo '</tbody></table></div>';
	}
	return ob_get_clean();
}
