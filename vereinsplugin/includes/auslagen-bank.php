<?php
/**
 * Bankverbindung für Auslagen-Erstattungen + GiroCode zum Überweisen.
 *
 *  - Im Profil hinterlegt jede:r ein Konto für Erstattungen (das SEPA-Konto
 *    für den Mitgliedsbeitrag steht ebenfalls zur Auswahl).
 *  - Beim Einreichen einer Auslage wird das Konto ausgewählt oder neu
 *    eingegeben und als Kopie an der Auslage gespeichert – spätere
 *    Profiländerungen verändern bereits eingereichte Auslagen nicht.
 *  - Nach der Genehmigung zeigt der Vorstand einen GiroCode (EPC-QR) mit
 *    Empfänger, IBAN, Betrag und Verwendungszweck
 *    „Rückzahlung Einkauf bei [Händler] am [Datum], Zweck [Kostenstelle, Budget, Konto]“.
 */

defined( 'ABSPATH' ) || exit;

define( 'VP_AUSLAGEN_BANK_DB_VERSION', '2' );

add_action( 'plugins_loaded', 'vp_auslagen_bank_maybe_upgrade', 7 );
function vp_auslagen_bank_maybe_upgrade() {
	if ( ! function_exists( 'jb_table_auslagen' ) ) {
		return; // Buchhaltungs-Modul nicht aktiv.
	}
	if ( get_option( 'vp_auslagen_bank_db_version' ) === VP_AUSLAGEN_BANK_DB_VERSION ) {
		return;
	}
	global $wpdb;
	$t    = jb_table_auslagen();
	$cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM `{$t}`" );
	if ( ! $cols ) {
		return; // Tabelle existiert (noch) nicht – beim nächsten Laden erneut.
	}
	foreach ( array(
		'haendler'     => "`haendler` VARCHAR(150) NOT NULL DEFAULT ''",
		'zahl_inhaber' => "`zahl_inhaber` VARCHAR(70) NOT NULL DEFAULT ''",
		'zahl_iban'    => "`zahl_iban` VARCHAR(40) NOT NULL DEFAULT ''",
		// Bankbuchung, mit der die Auslage erstattet wurde (Bank-Import-Abgleich).
		'erstattung_buchung_id' => '`erstattung_buchung_id` BIGINT UNSIGNED DEFAULT NULL',
	) as $col => $def ) {
		if ( ! in_array( $col, $cols, true ) ) {
			$wpdb->query( "ALTER TABLE `{$t}` ADD COLUMN {$def}" );
		}
	}
	update_option( 'vp_auslagen_bank_db_version', VP_AUSLAGEN_BANK_DB_VERSION );
}

add_action( 'init', 'vp_girocode_register_script' );
function vp_girocode_register_script() {
	$f = VP_PATH . 'assets/girocode.js';
	wp_register_script( 'vp-girocode', VP_URL . 'assets/girocode.js', array(), is_readable( $f ) ? filemtime( $f ) : VP_VERSION, true );
}

/* -------------------------------------------------------------------------
 * Konten einer Person
 * ---------------------------------------------------------------------- */

/**
 * Hinterlegte Konten einer Person, doppelte IBANs zusammengefasst.
 *
 * @return array<string,array{label:string,inhaber:string,iban:string}> Schlüssel 'erstattung' | 'sepa'
 */
function vp_user_bankkonten( $user_id ) {
	$user   = get_userdata( $user_id );
	$fallback_name = $user ? $user->display_name : '';
	$quellen = array(
		'erstattung' => array( __( 'Konto für Erstattungen', 'vereinsplugin' ), 'vp_erstattung_kontoinhaber', 'vp_erstattung_iban' ),
		'sepa'       => array( __( 'Konto für den Mitgliedsbeitrag', 'vereinsplugin' ), 'vp_sepa_kontoinhaber', 'vp_sepa_iban' ),
	);
	$out   = array();
	$ibans = array();
	foreach ( $quellen as $key => $q ) {
		$iban = vp_auslagen_iban_normalize( get_user_meta( $user_id, $q[2], true ) );
		if ( '' === $iban || in_array( $iban, $ibans, true ) ) {
			continue;
		}
		$ibans[]     = $iban;
		$inhaber     = trim( (string) get_user_meta( $user_id, $q[1], true ) );
		$out[ $key ] = array(
			'label'   => $q[0],
			'inhaber' => '' !== $inhaber ? $inhaber : $fallback_name,
			'iban'    => $iban,
		);
	}
	return $out;
}

function vp_auslagen_iban_normalize( $iban ) {
	return function_exists( 'vp_iban_normalize' )
		? vp_iban_normalize( (string) $iban )
		: strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $iban ) );
}

function vp_auslagen_iban_valid( $iban ) {
	return function_exists( 'vp_iban_valid' ) ? vp_iban_valid( $iban ) : (bool) preg_match( '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban );
}

/** „DE89 3704 0044 0532 0130 00“ */
function vp_iban_format( $iban ) {
	return trim( chunk_split( vp_auslagen_iban_normalize( $iban ), 4, ' ' ) );
}

/**
 * Wertet die Kontoauswahl aus dem Einreichen-Formular aus.
 *
 * Felder: zahl_wahl = 'erstattung' | 'sepa' | 'neu' | 'bar' (leer = erstes
 * hinterlegtes Konto), bei 'neu' zusätzlich zahl_inhaber, zahl_iban und
 * optional zahl_speichern (als Erstattungskonto ins Profil übernehmen).
 *
 * @return array{inhaber:string,iban:string}|WP_Error
 */
function vp_auslage_zahlungsziel_aus_formular( $user_id, array $data ) {
	$wahl   = sanitize_key( $data['zahl_wahl'] ?? '' );
	$konten = vp_user_bankkonten( $user_id );

	if ( 'bar' === $wahl ) {
		return array( 'inhaber' => '', 'iban' => '' );
	}
	if ( 'neu' === $wahl || ( '' === $wahl && ! $konten && ! empty( $data['zahl_iban'] ) ) ) {
		$iban    = vp_auslagen_iban_normalize( wp_unslash( $data['zahl_iban'] ?? '' ) );
		$inhaber = sanitize_text_field( wp_unslash( $data['zahl_inhaber'] ?? '' ) );
		if ( '' === $iban ) {
			return new WP_Error( 'iban_fehlt', __( 'Bitte eine IBAN für die Rückzahlung angeben.', 'vereinsplugin' ) );
		}
		if ( ! vp_auslagen_iban_valid( $iban ) ) {
			return new WP_Error( 'iban_ungueltig', __( 'Die IBAN ist ungültig (Prüfziffer stimmt nicht).', 'vereinsplugin' ) );
		}
		if ( '' === $inhaber ) {
			$u       = get_userdata( $user_id );
			$inhaber = $u ? $u->display_name : '';
		}
		if ( ! empty( $data['zahl_speichern'] ) ) {
			update_user_meta( $user_id, 'vp_erstattung_iban', $iban );
			update_user_meta( $user_id, 'vp_erstattung_kontoinhaber', $inhaber );
		}
		return array( 'inhaber' => $inhaber, 'iban' => $iban );
	}
	if ( isset( $konten[ $wahl ] ) ) {
		return array( 'inhaber' => $konten[ $wahl ]['inhaber'], 'iban' => $konten[ $wahl ]['iban'] );
	}
	// Keine (gültige) Auswahl, z. B. aus der Desktop-App: erstes hinterlegtes Konto.
	$erstes = reset( $konten );
	return $erstes ? array( 'inhaber' => $erstes['inhaber'], 'iban' => $erstes['iban'] ) : array( 'inhaber' => '', 'iban' => '' );
}

/* -------------------------------------------------------------------------
 * Überweisungsdaten + GiroCode
 * ---------------------------------------------------------------------- */

/**
 * „AUSLAGE-12 Rückzahlung Einkauf bei Rewe am 24.09.2026, Zweck KST 10, Sommerfest, 4900 Material“
 * (max. 140 Zeichen – Grenze des EPC-Verwendungszwecks). Die Kennung vorne
 * erkennt der Bank-Import wieder (vp_bank_abgleich).
 */
function vp_auslage_verwendungszweck( array $a ) {
	global $wpdb;
	$budget = null;
	if ( ! empty( $a['budget_id'] ) && function_exists( 'jb_table_budgets' ) ) {
		$budget = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jb_table_budgets() . ' WHERE id = %d', (int) $a['budget_id'] ) );
	}

	$zweck = array();
	if ( $budget && '' !== trim( (string) ( $budget->kostenstelle ?? '' ) ) ) {
		$zweck[] = trim( $budget->kostenstelle );
	}
	if ( $budget ) {
		$zweck[] = trim( $budget->zweck );
	}
	$konto = trim( (string) ( $a['konto'] ?? '' ) );
	if ( '' === $konto && $budget ) {
		$konto = trim( (string) ( $budget->konto ?? '' ) );
	}
	if ( '' !== $konto ) {
		$k       = function_exists( 'jb_konto_get' ) ? jb_konto_get( $konto ) : null;
		$zweck[] = $k ? $konto . ' ' . $k->bezeichnung : $konto;
	}
	if ( ! $zweck && ! empty( $a['kategorie'] ) ) {
		$zweck[] = $a['kategorie'];
	}

	$haendler = trim( (string) ( $a['haendler'] ?? '' ) );
	$datum    = date_i18n( 'd.m.Y', strtotime( $a['ausgabe_datum'] ) );
	$text     = ( ! empty( $a['id'] ) ? vp_auslage_kennung( (int) $a['id'] ) . ' ' : '' ) . ( '' !== $haendler
		? sprintf( __( 'Rückzahlung Einkauf bei %1$s am %2$s', 'vereinsplugin' ), $haendler, $datum )
		: sprintf( __( 'Rückzahlung Einkauf am %s', 'vereinsplugin' ), $datum ) );
	if ( $zweck ) {
		$text .= ', ' . sprintf( __( 'Zweck %s', 'vereinsplugin' ), implode( ', ', array_filter( $zweck ) ) );
	}
	$text = preg_replace( '/\s+/', ' ', $text );
	return mb_strlen( $text ) > 140 ? rtrim( mb_substr( $text, 0, 139 ) ) . '…' : $text;
}

/**
 * Empfänger, IBAN, Betrag, Verwendungszweck einer Auslage. Ältere Auslagen
 * ohne gespeichertes Konto fallen auf das Profil der Person zurück.
 *
 * @return array{inhaber:string,iban:string,betrag:float,zweck:string}|null null = kein Konto bekannt
 */
function vp_auslage_zahlungsdaten( array $a ) {
	$iban    = vp_auslagen_iban_normalize( $a['zahl_iban'] ?? '' );
	$inhaber = trim( (string) ( $a['zahl_inhaber'] ?? '' ) );
	if ( '' === $iban ) {
		$konten = vp_user_bankkonten( (int) $a['user_id'] );
		$erstes = reset( $konten );
		if ( ! $erstes ) {
			return null;
		}
		$iban    = $erstes['iban'];
		$inhaber = $erstes['inhaber'];
	}
	if ( '' === $inhaber ) {
		$inhaber = (string) ( $a['user_name'] ?? '' );
	}
	return array(
		'inhaber' => $inhaber,
		'iban'    => $iban,
		'betrag'  => round( abs( (float) $a['betrag'] ), 2 ),
		'zweck'   => vp_auslage_verwendungszweck( $a ),
	);
}

/** EPC069-12-Datensatz (Version 002, UTF-8, BIC optional). */
function vp_epc_payload( $inhaber, $iban, $betrag, $zweck ) {
	$clean = function ( $s, $max ) {
		$s = trim( preg_replace( '/[\r\n]+/', ' ', (string) $s ) );
		return mb_substr( $s, 0, $max );
	};
	return implode( "\n", array(
		'BCD',
		'002',
		'1',
		'SCT',
		'',
		$clean( $inhaber, 70 ),
		vp_auslagen_iban_normalize( $iban ),
		'EUR' . number_format( (float) $betrag, 2, '.', '' ),
		'',
		'',
		$clean( $zweck, 140 ),
	) );
}

/**
 * Kasten mit GiroCode + Überweisungsdaten zum Abtippen/Kopieren.
 *
 * @param array|object $a Auslage (Zeile aus jb_auslagen, gern mit user_name)
 */
function vp_auslage_girocode_html( $a ) {
	$a = (array) $a;
	$z = vp_auslage_zahlungsdaten( $a );
	if ( ! $z ) {
		return '<div class="vp-girocode vp-girocode-leer">' . esc_html__( 'Keine Bankverbindung hinterlegt – bitte bar auszahlen oder die IBAN bei der Person erfragen.', 'vereinsplugin' ) . '</div>';
	}
	wp_enqueue_script( 'vp-girocode' );

	$betrag = number_format( $z['betrag'], 2, ',', '.' ) . ' €';
	$zeilen = array(
		__( 'Empfänger', 'vereinsplugin' )        => array( $z['inhaber'], $z['inhaber'] ),
		__( 'IBAN', 'vereinsplugin' )             => array( vp_iban_format( $z['iban'] ), $z['iban'] ),
		__( 'Betrag', 'vereinsplugin' )           => array( $betrag, number_format( $z['betrag'], 2, ',', '' ) ),
		__( 'Verwendungszweck', 'vereinsplugin' ) => array( $z['zweck'], $z['zweck'] ),
	);

	$html  = vp_girocode_css() . '<div class="vp-girocode">';
	$html .= sprintf(
		'<div class="vp-girocode-qr" data-epc="%s" data-label="%s"></div>',
		esc_attr( vp_epc_payload( $z['inhaber'], $z['iban'], $z['betrag'], $z['zweck'] ) ),
		esc_attr__( 'GiroCode für die Rückzahlung', 'vereinsplugin' )
	);
	$html .= '<div class="vp-girocode-daten"><strong>' . esc_html__( 'Rückzahlung überweisen', 'vereinsplugin' ) . '</strong>';
	$html .= '<span class="vp-girocode-hint">' . esc_html__( 'GiroCode mit der Banking-App scannen oder die Daten kopieren.', 'vereinsplugin' ) . '</span><dl>';
	foreach ( $zeilen as $label => $v ) {
		$html .= sprintf(
			'<dt>%s</dt><dd><span>%s</span> <button type="button" class="vp-girocode-copy" data-copy="%s" title="%s">⧉</button></dd>',
			esc_html( $label ),
			esc_html( $v[0] ),
			esc_attr( $v[1] ),
			esc_attr__( 'Kopieren', 'vereinsplugin' )
		);
	}
	$html .= '</dl></div></div>';
	return $html;
}

/**
 * Minimal-CSS, damit der Kasten auch im WP-Backend und in den Kreis-Seiten
 * (ohne app.css) ordentlich aussieht. Einmal pro Seite.
 */
function vp_girocode_css() {
	static $done = false;
	if ( $done ) {
		return '';
	}
	$done = true;
	return '<style>
.vp-girocode{display:flex;flex-wrap:wrap;gap:12px 16px;align-items:flex-start;margin:10px 0;padding:12px;border:1px solid rgba(0,0,0,.12);border-radius:8px;background:rgba(0,0,0,.02)}
.vp-girocode-qr{width:180px;max-width:100%;flex:0 0 auto;background:#fff}
.vp-girocode-qr svg{display:block;width:100%;height:auto}
.vp-girocode-daten{flex:1 1 220px;min-width:0}
.vp-girocode-hint{display:block;font-size:.85em;opacity:.75;margin:2px 0 6px}
.vp-girocode dl{margin:0;display:grid;grid-template-columns:auto 1fr;gap:4px 10px}
.vp-girocode dt{font-size:.85em;opacity:.75}
.vp-girocode dd{margin:0;overflow-wrap:anywhere}
.vp-girocode-copy{border:0;background:none;cursor:pointer;padding:0 2px;font-size:1em;opacity:.6}
.vp-girocode-copy:hover{opacity:1}
.vp-girocode-leer{display:block;font-size:.9em}
</style>';
}
