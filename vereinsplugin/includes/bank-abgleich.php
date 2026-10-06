<?php
/**
 * Bank-Import: Abgleich mit dem, was schon gebucht oder eingereicht ist.
 *
 * Für jede Zeile eines Kontoauszugs wird geprüft:
 *  1. Erstattung einer Auslage – die Ausgabe ist mit der Genehmigung schon
 *     gebucht (Aufwand an 1600 Verbindlichkeiten). Die Überweisung ist nur
 *     noch Bank an 1600, keine zweite Ausgabe. Erkannt über Betrag plus
 *     Kennung „AUSLAGE-12“ im Verwendungszweck, IBAN oder Namen.
 *  2. Beleg ohne Buchung („nur Beleg“, vom Vereinskonto bezahlt) – Betrag
 *     gleich, Datum nah: der Beleg wird an die neue Buchung gehängt.
 *  3. Dublette – Datum, Betrag und Gegenpartei stehen auf demselben
 *     Geldkonto schon im Journal (Kontoauszug doppelt hochgeladen).
 *
 * Genutzt vom Bank-Import im Mitgliederbereich (vp_bh_import) und von der
 * Desktop-App (REST /actions/bank-csv).
 */

defined( 'ABSPATH' ) || exit;

/** Kennung einer Auslage im Verwendungszweck, z. B. „AUSLAGE-12“. */
function vp_auslage_kennung( $id ) {
	return 'AUSLAGE-' . (int) $id;
}

/** Auslagen-Nummer aus einem Verwendungszweck lesen (0 = keine). */
function vp_auslage_kennung_finden( $text ) {
	return preg_match( '/AUSLAGE\s*-?\s*(\d+)/i', (string) $text, $m ) ? (int) $m[1] : 0;
}

/** Name für den Vergleich: klein, Umlaute ausgeschrieben, nur Buchstaben. */
function vp_bank_name_tokens( $name ) {
	$s = mb_strtolower( (string) $name );
	$s = strtr( $s, array( 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss' ) );
	$s = function_exists( 'remove_accents' ) ? remove_accents( $s ) : $s;
	$t = preg_split( '/[^a-z]+/', $s, -1, PREG_SPLIT_NO_EMPTY );
	return array_values( array_filter( $t, static function ( $w ) {
		return strlen( $w ) >= 3;
	} ) );
}

/** Passen zwei Namen zusammen? („Anna Müller“ ~ „MUELLER, ANNA“) */
function vp_bank_name_passt( $a, $b ) {
	$ta = vp_bank_name_tokens( $a );
	$tb = vp_bank_name_tokens( $b );
	if ( ! $ta || ! $tb ) {
		return false;
	}
	$kurz = count( $ta ) <= count( $tb ) ? $ta : $tb;
	$lang = count( $ta ) <= count( $tb ) ? $tb : $ta;
	return ! array_diff( $kurz, $lang );
}

function vp_bank_iban_norm( $iban ) {
	return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $iban ) );
}

/** Spalte in jb_auslagen vorhanden? */
function vp_bank_auslagen_hat( $col ) {
	static $cols = null;
	if ( null === $cols ) {
		global $wpdb;
		$cols = function_exists( 'jb_table_auslagen' ) ? (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . jb_table_auslagen() ) : array();
	}
	return in_array( $col, $cols, true );
}

/**
 * Offene Auslagen, die noch auf ihre Erstattung warten: genehmigt, oder vor
 * kurzem von Hand auf „ausgezahlt“ gesetzt, aber noch ohne Bankbuchung.
 */
function vp_bank_offene_auslagen() {
	global $wpdb;
	if ( ! function_exists( 'jb_table_auslagen' ) || ! vp_bank_auslagen_hat( 'erstattung_buchung_id' ) ) {
		return array();
	}
	$t    = jb_table_auslagen();
	$rows = (array) $wpdb->get_results(
		"SELECT a.*, u.display_name AS user_name FROM `{$t}` a LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
		 WHERE (a.erstattung_buchung_id IS NULL OR a.erstattung_buchung_id = 0)
		   AND ( a.status = 'genehmigt'
		      OR ( a.status = 'ausgezahlt' AND a.ausgezahlt_am >= DATE_SUB(NOW(), INTERVAL 120 DAY) ) )",
		ARRAY_A
	);
	foreach ( $rows as &$a ) {
		$z            = function_exists( 'vp_auslage_zahlungsdaten' ) ? vp_auslage_zahlungsdaten( $a ) : null;
		$a['_iban']   = vp_bank_iban_norm( $z['iban'] ?? ( $a['zahl_iban'] ?? '' ) );
		$a['_namen']  = array_filter( array( $z['inhaber'] ?? '', $a['zahl_inhaber'] ?? '', $a['user_name'] ?? '' ) );
	}
	return $rows;
}

/** Belege ohne Buchung („nur Beleg“). */
function vp_bank_offene_belege() {
	global $wpdb;
	if ( ! function_exists( 'jb_table_auslagen' ) ) {
		return array();
	}
	$t = jb_table_auslagen();
	return (array) $wpdb->get_results(
		"SELECT a.*, u.display_name AS user_name FROM `{$t}` a LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
		 WHERE a.status = 'beleg' AND (a.buchung_id IS NULL OR a.buchung_id = 0)",
		ARRAY_A
	);
}

/**
 * Kontoauszugs-Zeilen abgleichen.
 *
 * @param array  $rows      aus vp_bh_parse_bank_csv(): datum, betrag, name, zweck, konto[, iban]
 * @param string $geldkonto Geldkonto des Kontoauszugs (für die Dubletten-Suche)
 * @return array Zeilen, ergänzt um
 *   dublette: Journal-ID oder 0,
 *   vorgemerkt: true, wenn die Bank den Umsatz nur vorgemerkt hat,
 *   auslage:  null | { id, text, grund },
 *   beleg:    null | { id, text, konto }
 *   Bei einer erkannten Auslage wird konto auf das Auslagen-Konto gesetzt.
 */
function vp_bank_abgleich( array $rows, $geldkonto = '' ) {
	global $wpdb;
	$jt      = function_exists( 'jb_table_journal' ) ? jb_table_journal() : $wpdb->prefix . 'jb_buchungen';
	$jcols   = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$jt}`" );
	$mit_gk  = '' !== (string) $geldkonto && in_array( 'geldkonto', $jcols, true );
	$auslagen = vp_bank_offene_auslagen();
	$belege   = vp_bank_offene_belege();
	$k_ausl   = function_exists( 'vp_bh_vorgabe_geldkonto' ) ? vp_bh_vorgabe_geldkonto( 'Auslage' ) : '1600';
	$vergeben = array( 'j' => array(), 'a' => array(), 'b' => array() );

	foreach ( $rows as &$r ) {
		$betrag       = round( (float) $r['betrag'], 2 );
		$r['dublette'] = 0;
		$r['auslage']  = null;
		$r['beleg']    = null;
		// Vorgemerkte Umsätze können sich noch ändern – erst nach der Buchung importieren.
		$r['vorgemerkt'] = false !== stripos( (string) ( $r['info'] ?? '' ), 'vorgemerkt' );

		// 1. Dublette: gleiches Datum, gleicher Betrag, gleiche Gegenpartei.
		$sql  = "SELECT id, gegenpartei, beschreibung FROM `{$jt}` WHERE buchung_datum = %s AND ABS(betrag - %f) < 0.005";
		$args = array( $r['datum'], $betrag );
		if ( $mit_gk ) {
			$sql   .= ' AND geldkonto = %s';
			$args[] = (string) $geldkonto;
		} else {
			$sql .= " AND quelle <> 'Auslage'";
		}
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) as $j ) {
			if ( isset( $vergeben['j'][ $j['id'] ] ) ) {
				continue;
			}
			$gleich = '' !== trim( (string) $r['name'] )
				? trim( (string) $j['gegenpartei'] ) === trim( (string) $r['name'] )
				: ( '' !== trim( (string) $r['zweck'] ) && false !== mb_strpos( (string) $j['beschreibung'], trim( (string) $r['zweck'] ) ) );
			if ( $gleich ) {
				$r['dublette']                = (int) $j['id'];
				$vergeben['j'][ (int) $j['id'] ] = true;
				break;
			}
		}
		if ( $r['dublette'] || $r['vorgemerkt'] || $betrag >= 0 ) {
			continue;
		}

		// 2. Erstattung einer Auslage.
		$ref   = vp_auslage_kennung_finden( $r['zweck'] . ' ' . $r['name'] );
		$iban  = vp_bank_iban_norm( $r['iban'] ?? '' );
		$beste = null;
		$punkte = 0;
		foreach ( $auslagen as $a ) {
			if ( isset( $vergeben['a'][ $a['id'] ] ) || abs( abs( (float) $a['betrag'] ) - abs( $betrag ) ) >= 0.005 ) {
				continue;
			}
			$p     = 0;
			$grund = '';
			if ( $ref && (int) $a['id'] === $ref ) {
				$p     = 3;
				$grund = __( 'Kennung im Verwendungszweck', 'vereinsplugin' );
			} elseif ( '' !== $iban && $iban === $a['_iban'] ) {
				$p     = 2;
				$grund = __( 'IBAN', 'vereinsplugin' );
			} else {
				foreach ( $a['_namen'] as $n ) {
					if ( vp_bank_name_passt( $n, $r['name'] ) ) {
						$p     = 1;
						$grund = __( 'Name', 'vereinsplugin' );
						break;
					}
				}
			}
			if ( $p > $punkte ) {
				$punkte = $p;
				$beste  = array(
					'id'    => (int) $a['id'],
					'text'  => sprintf(
						/* translators: 1: id, 2: person, 3: date, 4: description */
						__( 'Erstattung Auslage #%1$d · %2$s · %3$s · %4$s', 'vereinsplugin' ),
						(int) $a['id'],
						$a['user_name'] ?? '',
						mysql2date( 'd.m.Y', $a['ausgabe_datum'] ),
						wp_trim_words( (string) $a['beschreibung'], 8 )
					),
					'grund' => $grund,
				);
			}
		}
		if ( $beste ) {
			$r['auslage']                   = $beste;
			$r['konto']                     = $k_ausl;
			$vergeben['a'][ $beste['id'] ] = true;
			continue;
		}

		// 3. Beleg ohne Buchung: Betrag gleich, Datum höchstens 10 Tage entfernt.
		$bank_ts = strtotime( $r['datum'] );
		$nah     = null;
		$abstand = PHP_INT_MAX;
		foreach ( $belege as $b ) {
			if ( isset( $vergeben['b'][ $b['id'] ] ) || abs( abs( (float) $b['betrag'] ) - abs( $betrag ) ) >= 0.005 ) {
				continue;
			}
			$d = abs( $bank_ts - strtotime( $b['ausgabe_datum'] ) ) / DAY_IN_SECONDS;
			if ( $d <= 10 && $d < $abstand ) {
				$abstand = $d;
				$nah     = $b;
			}
		}
		if ( $nah ) {
			$r['beleg'] = array(
				'id'    => (int) $nah['id'],
				'text'  => sprintf(
					/* translators: 1: id, 2: description, 3: person, 4: date */
					__( 'Beleg #%1$d · %2$s · %3$s · %4$s', 'vereinsplugin' ),
					(int) $nah['id'],
					wp_trim_words( (string) $nah['beschreibung'], 8 ),
					$nah['user_name'] ?? '',
					mysql2date( 'd.m.Y', $nah['ausgabe_datum'] )
				),
				'konto' => (string) ( $nah['konto'] ?? '' ),
			);
			if ( '' === (string) $r['konto'] && '' !== $r['beleg']['konto'] ) {
				$r['konto'] = $r['beleg']['konto'];
			}
			$vergeben['b'][ (int) $nah['id'] ] = true;
		}
	}
	return $rows;
}

/**
 * Eine Zeile des Kontoauszugs buchen.
 *
 * @param array  $r         datum, betrag, name, zweck, konto
 * @param string $geldkonto Geldkonto des Kontoauszugs
 * @param int    $auslage_id  > 0: als Erstattung dieser Auslage buchen
 * @param int    $beleg_id    > 0: diesen Beleg (status „beleg“) anhängen
 * @param string $beschreibung Text für die Buchung (Vorgabe: Verwendungszweck)
 * @return array{id:int,art:string} art = auslage | beleg | normal; id 0 = nicht gebucht
 */
function vp_bank_zeile_buchen( array $r, $geldkonto, $auslage_id = 0, $beleg_id = 0, $beschreibung = null ) {
	global $wpdb;
	$betrag = round( (float) $r['betrag'], 2 );
	$datum  = sanitize_text_field( (string) ( $r['datum'] ?? '' ) );
	if ( ! $datum || 0.0 === $betrag || ! function_exists( 'jb_journal_add' ) ) {
		return array( 'id' => 0, 'art' => 'normal' );
	}
	$name  = sanitize_text_field( (string) ( $r['name'] ?? '' ) );
	$zweck = sanitize_textarea_field( (string) ( $r['zweck'] ?? '' ) );
	$beschreibung = null === $beschreibung ? $zweck : $beschreibung;
	$konto = sanitize_text_field( (string) ( $r['konto'] ?? '' ) );
	$data  = array(
		'geldkonto'     => sanitize_text_field( (string) $geldkonto ),
		'buchung_datum' => $datum,
		'betrag'        => $betrag,
		'beschreibung'  => $beschreibung,
		'quelle'        => 'Bank KSK',
		'gegenpartei'   => $name,
	);
	$at = function_exists( 'jb_table_auslagen' ) ? jb_table_auslagen() : '';

	// Erstattung einer Auslage: Bank an Auslagen-Konto, keine neue Ausgabe.
	if ( $auslage_id > 0 && $at && vp_bank_auslagen_hat( 'erstattung_buchung_id' ) ) {
		$a = function_exists( 'jb_get_auslage' ) ? jb_get_auslage( (int) $auslage_id ) : null;
		if ( $a && empty( $a['erstattung_buchung_id'] ) && in_array( $a['status'], array( 'genehmigt', 'ausgezahlt' ), true )
			&& abs( abs( (float) $a['betrag'] ) - abs( $betrag ) ) < 0.005 && $betrag < 0 ) {
			// Altfälle: Ausgabe der Auslage noch nicht im Journal → jetzt nachholen.
			$aus_id = function_exists( 'jb_auslage_to_journal' ) ? jb_auslage_to_journal( (int) $a['id'] ) : 0;
			$ref    = $aus_id ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT beleg_nr FROM `" . jb_table_journal() . "` WHERE id = %d", $aus_id ) ) : '';
			$k      = function_exists( 'vp_bh_vorgabe_geldkonto' ) ? vp_bh_vorgabe_geldkonto( 'Auslage' ) : '1600';
			$id     = (int) jb_journal_add( array_merge( $data, array(
				'konto'          => $k,
				'kategorie'      => sprintf( 'Erstattung Auslage #%d', (int) $a['id'] ),
				'beschreibung'   => sprintf( 'Erstattung Auslage #%d: %s', (int) $a['id'], (string) $a['beschreibung'] ),
				'beleg_referenz' => $ref,
			) ) );
			$upd = array( 'erstattung_buchung_id' => $id, 'status' => 'ausgezahlt' );
			if ( empty( $a['ausgezahlt_am'] ) ) {
				$upd['ausgezahlt_am'] = $datum . ' 00:00:00';
			}
			$wpdb->update( $at, $upd, array( 'id' => (int) $a['id'] ) );
			return array( 'id' => $id, 'art' => 'auslage' );
		}
	}

	// Beleg ohne Buchung anhängen.
	$beleg = null;
	if ( $beleg_id > 0 && $at ) {
		$beleg = function_exists( 'jb_get_auslage' ) ? jb_get_auslage( (int) $beleg_id ) : null;
		if ( ! $beleg || 'beleg' !== $beleg['status'] || ! empty( $beleg['buchung_id'] ) ) {
			$beleg = null;
		}
	}
	if ( $beleg ) {
		if ( '' === $konto ) {
			$konto = (string) ( $beleg['konto'] ?? '' );
		}
		$data['beleg_pfad'] = (string) $beleg['beleg_pfad'];
		if ( '' === trim( (string) $beschreibung ) ) {
			$data['beschreibung'] = 'Beleg #' . (int) $beleg['id'] . ': ' . $beleg['beschreibung'];
		}
	}
	$data['konto']     = $konto;
	$data['sphaere']   = ( $konto && function_exists( 'jb_konto_sphaere' ) ) ? jb_konto_sphaere( $konto ) : '';
	$data['kategorie'] = $konto ? trim( $konto . ' ' . ( function_exists( 'jb_konto_get' ) ? ( jb_konto_get( $konto )->bezeichnung ?? '' ) : '' ) ) : 'Import';
	$id = (int) jb_journal_add( $data );
	if ( $beleg && $id ) {
		$wpdb->update( $at, array( 'buchung_id' => $id ), array( 'id' => (int) $beleg['id'] ) );
		return array( 'id' => $id, 'art' => 'beleg' );
	}
	return array( 'id' => $id, 'art' => 'normal' );
}

/* =========================================================================
 * Protokoll: wann wurde welcher Zeitraum importiert?
 * ====================================================================== */

/**
 * Import im Protokoll vermerken (Option vp_bank_import_log, je Geldkonto die
 * letzten 20 Einträge).
 *
 * @param array $gebucht die tatsächlich gebuchten Zeilen (datum, betrag, name)
 */
function vp_bank_protokoll_add( $geldkonto, array $alle, array $gebucht, $herkunft = 'web' ) {
	if ( ! $alle ) {
		return;
	}
	$daten = array_column( $alle, 'datum' );
	$log   = (array) get_option( 'vp_bank_import_log', array() );
	$k     = (string) $geldkonto;
	$e     = array(
		'zeit'     => current_time( 'mysql' ),
		'user'     => get_current_user_id(),
		'von'      => min( $daten ),
		'bis'      => max( $daten ),
		'zeilen'   => count( $alle ),
		'gebucht'  => count( $gebucht ),
		'herkunft' => $herkunft,
	);
	// Letzte Zeile des Kontoauszugs (neuestes Datum) zur Orientierung.
	usort( $alle, static function ( $a, $b ) {
		return strcmp( $b['datum'], $a['datum'] );
	} );
	$e['letzte'] = array(
		'datum'  => $alle[0]['datum'],
		'name'   => (string) ( $alle[0]['name'] ?? '' ),
		'betrag' => (float) $alle[0]['betrag'],
	);
	$log[ $k ] = array_slice( array_merge( array( $e ), (array) ( $log[ $k ] ?? array() ) ), 0, 20 );
	update_option( 'vp_bank_import_log', $log, false );
}

/**
 * Stand eines Geldkontos: letzter protokollierter Import und – für Importe
 * vor v0.46 – die neueste Bank-Buchung im Journal.
 *
 * @return array{import:?array, journal:?array}
 */
function vp_bank_stand( $geldkonto ) {
	global $wpdb;
	$log = (array) get_option( 'vp_bank_import_log', array() );
	$imp = $log[ (string) $geldkonto ][0] ?? null;
	$jt  = function_exists( 'jb_table_journal' ) ? jb_table_journal() : $wpdb->prefix . 'jb_buchungen';
	$cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$jt}`" );
	$j    = null;
	if ( in_array( 'geldkonto', $cols, true ) ) {
		$j = $wpdb->get_row( $wpdb->prepare(
			"SELECT buchung_datum AS datum, gegenpartei AS name, betrag FROM `{$jt}`
			 WHERE geldkonto = %s AND quelle = 'Bank KSK' ORDER BY buchung_datum DESC, id DESC LIMIT 1",
			(string) $geldkonto
		), ARRAY_A );
	}
	return array( 'import' => $imp, 'journal' => $j ?: null );
}

/** Ab welchem Datum soll der nächste Export beginnen? (letzter Tag inklusive) */
function vp_bank_export_ab( $geldkonto ) {
	$s = vp_bank_stand( $geldkonto );
	$d = $s['import']['bis'] ?? '';
	if ( ! empty( $s['journal']['datum'] ) && $s['journal']['datum'] > $d ) {
		$d = $s['journal']['datum'];
	}
	return $d;
}

/** Hinweis-Box zum letzten Import eines Geldkontos. */
function vp_bank_stand_html( $geldkonto ) {
	$s   = vp_bank_stand( $geldkonto );
	$ab  = vp_bank_export_ab( $geldkonto );
	$fmt = static function ( $d ) {
		return $d ? mysql2date( 'd.m.Y', $d ) : '';
	};
	if ( ! $ab ) {
		return '<p class="vp-muted">' . esc_html__( 'Für dieses Konto wurde noch nichts importiert.', 'vereinsplugin' ) . '</p>';
	}
	$h = '<ul style="margin:.3em 0 .3em 1.2em">';
	if ( $s['import'] ) {
		$i = $s['import'];
		$u = $i['user'] ? get_userdata( (int) $i['user'] ) : null;
		$h .= '<li>' . esc_html( sprintf(
			/* translators: 1: date/time, 2: person, 3: from, 4: to, 5: booked, 6: rows */
			__( 'Letzter Import am %1$s von %2$s: Umsätze %3$s bis %4$s, %5$d von %6$d Zeilen gebucht.', 'vereinsplugin' ),
			mysql2date( 'd.m.Y H:i', $i['zeit'] ),
			$u ? $u->display_name : '?',
			$fmt( $i['von'] ),
			$fmt( $i['bis'] ),
			(int) $i['gebucht'],
			(int) $i['zeilen']
		) ) . '</li>';
		if ( ! empty( $i['letzte'] ) ) {
			$h .= '<li>' . esc_html( sprintf(
				__( 'Letzte Zeile im Kontoauszug: %1$s · %2$s · %3$s', 'vereinsplugin' ),
				$fmt( $i['letzte']['datum'] ),
				$i['letzte']['name'],
				vp_bh_eur( $i['letzte']['betrag'] )
			) ) . '</li>';
		}
	}
	if ( $s['journal'] ) {
		$h .= '<li>' . esc_html( sprintf(
			__( 'Neueste Bankbuchung im Journal: %1$s · %2$s · %3$s', 'vereinsplugin' ),
			$fmt( $s['journal']['datum'] ),
			$s['journal']['name'],
			vp_bh_eur( $s['journal']['betrag'] )
		) ) . '</li>';
	}
	$h .= '</ul>';
	$h .= '<p><strong>' . esc_html( sprintf( __( 'Nächsten Export ab %s (einschließlich) erstellen.', 'vereinsplugin' ), $fmt( $ab ) ) ) . '</strong> '
		. esc_html__( 'Die Überschneidung ist gewollt: Zeilen, die schon im Journal stehen, erkennt der Import und überspringt sie. So geht nichts verloren, auch nicht Umsätze vom Tag des letzten Exports.', 'vereinsplugin' ) . '</p>';
	return $h;
}

/**
 * Prüft den Zeitraum eines neuen Kontoauszugs gegen den letzten Import.
 * @return string Warnung (leer = passt)
 */
function vp_bank_luecke( array $rows, $geldkonto ) {
	$ab = vp_bank_export_ab( $geldkonto );
	if ( ! $ab || ! $rows ) {
		return '';
	}
	$min = min( array_column( $rows, 'datum' ) );
	if ( $min <= $ab ) {
		return '';
	}
	return sprintf(
		/* translators: 1: last imported date, 2: first date of new file */
		__( 'Achtung, mögliche Lücke: Bisher importiert bis %1$s, dieser Kontoauszug beginnt erst am %2$s. Umsätze dazwischen fehlen eventuell – lieber ab %1$s exportieren.', 'vereinsplugin' ),
		mysql2date( 'd.m.Y', $ab ),
		mysql2date( 'd.m.Y', $min )
	);
}
