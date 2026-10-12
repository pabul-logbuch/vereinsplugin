<?php
/**
 * Ticket-Vorverkauf über die Formulare.
 *
 * Ein Formular mit einem Feld vom Typ „Tickets“ wird zum Vorverkauf:
 *   - Ticketarten je Zeile „Name | Preis | Kontingent“ (Kontingent optional),
 *     z. B. „Normal | 12 | 100“, „Ermäßigt | 8 | 50“, „Soli | 20“.
 *     Das Teilnehmerlimit des Formulars gilt dann für Tickets insgesamt.
 *   - Nach der Bestellung kommt sofort die Bestätigung mit Link zur
 *     Ticket-Seite (?vp_ticket=<eintrag>-<zugang>): Bestellung, Zahlungsdaten
 *     mit GiroCode und Verwendungszweck TICKET-<Nr>, je Ticket ein QR-Code.
 *     Tickets gelten sofort – der Bank-Import läuft nur gelegentlich, darum
 *     wird nichts automatisch storniert.
 *   - Bezahlt markieren: von Hand in den Einträgen (Überweisung gesehen / bar),
 *     und der Bank-Import erkennt TICKET-<Nr>, setzt „bezahlt“ und bucht die
 *     Einnahme auf das eingestellte Konto.
 *   - Einlass: QR mit der normalen Handy-Kamera scannen → ?vp_einlass=<code>.
 *     Zeigt gültig / schon eingelöst / noch offen (an der Abendkasse
 *     kassieren). Berechtigt: Formular-Verwaltung oder wer die Einlass-PIN
 *     des Formulars kennt (Helfende ohne Konto).
 *
 * Tabellen: vp_tickets (je Ticket ein Code); in vp_formular_eintraege
 * zahl_status (offen | bezahlt | storniert), betrag, bezahlt_am, zahlart,
 * buchung_id, zugang; in vp_formulare ticket_cfg (JSON: konto, pin, frist).
 */

defined( 'ABSPATH' ) || exit;

define( 'VP_TICKETS_DB_VERSION', '1' );

function vp_tickets_table() { global $wpdb; return $wpdb->prefix . 'vp_tickets'; }

add_action( 'plugins_loaded', 'vp_tickets_maybe_upgrade', 8 );
function vp_tickets_maybe_upgrade() {
	if ( get_option( 'vp_tickets_db_version' ) === VP_TICKETS_DB_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( 'CREATE TABLE ' . vp_tickets_table() . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		formular_id BIGINT UNSIGNED NOT NULL,
		eintrag_id BIGINT UNSIGNED NOT NULL,
		art VARCHAR(100) NOT NULL DEFAULT '',
		preis DECIMAL(10,2) NOT NULL DEFAULT 0,
		code VARCHAR(20) NOT NULL DEFAULT '',
		status VARCHAR(12) NOT NULL DEFAULT 'gueltig',
		eingeloest_am DATETIME NULL,
		eingeloest_von VARCHAR(100) NOT NULL DEFAULT '',
		PRIMARY KEY  (id),
		UNIQUE KEY code (code),
		KEY formular_id (formular_id),
		KEY eintrag_id (eintrag_id)
	) {$wpdb->get_charset_collate()};" );
	update_option( 'vp_tickets_db_version', VP_TICKETS_DB_VERSION );
}

/* -------------------------------------------------------------------------
 * Grundlagen
 * ---------------------------------------------------------------------- */

/** Ticketarten eines Felds: [ [name, preis, kontingent|null], … ] */
function vp_ticket_arten( $feld ) {
	$out = array();
	foreach ( (array) ( $feld['options'] ?? array() ) as $zeile ) {
		$t = array_map( 'trim', explode( '|', (string) $zeile ) );
		if ( '' === $t[0] ) {
			continue;
		}
		$preis = isset( $t[1] ) ? (float) str_replace( ',', '.', preg_replace( '/[^0-9,.]/', '', $t[1] ) ) : 0.0;
		$out[] = array(
			'name'       => $t[0],
			'preis'      => round( $preis, 2 ),
			'kontingent' => isset( $t[2] ) && '' !== $t[2] ? max( 0, (int) $t[2] ) : null,
		);
	}
	return $out;
}

/** Das Tickets-Feld eines Formulars (oder null). */
function vp_ticket_feld( $f ) {
	foreach ( (array) ( $f->felder ?? array() ) as $feld ) {
		if ( 'tickets' === $feld['type'] ) {
			return $feld;
		}
	}
	return null;
}

function vp_ticket_formular( $f ) {
	return (bool) vp_ticket_feld( $f );
}

/** Einstellungen (Konto, Einlass-PIN, Zahlungsfrist). */
function vp_ticket_cfg( $f ) {
	$c = json_decode( (string) ( $f->ticket_cfg ?? '' ), true );
	$c = is_array( $c ) ? $c : array();
	return array(
		'konto' => (string) ( $c['konto'] ?? '' ),
		'pin'   => (string) ( $c['pin'] ?? '' ),
		'frist' => max( 0, (int) ( $c['frist'] ?? 7 ) ),
	);
}

function vp_ticket_eur( $n ) {
	return number_format( (float) $n, 2, ',', '.' ) . ' €';
}

function vp_ticket_bestellnr( $eintrag_id ) {
	return 'TICKET-' . (int) $eintrag_id;
}

/** Verkaufte (nicht stornierte) Tickets: gesamt und je Art. */
function vp_ticket_verkauft( $formular_id ) {
	global $wpdb;
	if ( get_option( 'vp_tickets_db_version' ) !== VP_TICKETS_DB_VERSION ) {
		return array( 'gesamt' => 0, 'je' => array() );
	}
	$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT art, COUNT(*) AS n FROM ' . vp_tickets_table() . " WHERE formular_id = %d AND status <> 'storniert' GROUP BY art", (int) $formular_id ) );
	$je   = array();
	foreach ( $rows as $r ) {
		$je[ $r->art ] = (int) $r->n;
	}
	return array( 'gesamt' => array_sum( $je ), 'je' => $je );
}

/** Belegte Plätze: bei Ticket-Formularen Tickets, sonst Einträge. */
function vp_formular_belegt( $f ) {
	return vp_ticket_formular( $f ) ? vp_ticket_verkauft( $f->id )['gesamt'] : vp_formular_anzahl_eintraege( $f->id );
}

function vp_ticket_eintrag( $id ) {
	global $wpdb;
	$e = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vp_formular_eintraege_table() . ' WHERE id = %d', (int) $id ) );
	if ( $e ) {
		$e->daten = json_decode( (string) $e->daten, true ) ?: array();
	}
	return $e;
}

function vp_tickets_von_eintrag( $eintrag_id ) {
	global $wpdb;
	return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . vp_tickets_table() . ' WHERE eintrag_id = %d ORDER BY id', (int) $eintrag_id ) );
}

function vp_ticket_seite_url( $e ) {
	return add_query_arg( 'vp_ticket', (int) $e->id . '-' . $e->zugang, home_url( '/' ) );
}

function vp_ticket_einlass_url( $code ) {
	return add_query_arg( 'vp_einlass', rawurlencode( $code ), home_url( '/' ) );
}

/** Bestellung als Text, z. B. „2 × Normal (12,00 €)“. */
function vp_ticket_bestellung_text( array $anzahl ) {
	$t = array();
	foreach ( $anzahl as $art => $n ) {
		if ( (int) $n > 0 ) {
			$t[] = (int) $n . ' × ' . $art;
		}
	}
	return implode( ', ', $t );
}

/* -------------------------------------------------------------------------
 * Formular: Feld anzeigen, prüfen, Tickets anlegen
 * ---------------------------------------------------------------------- */

function vp_ticket_feld_html( $feld ) {
	$fid      = (int) ( $feld['_fid'] ?? 0 );
	$verkauft = $fid ? vp_ticket_verkauft( $fid )['je'] : array();
	$posted   = (array) ( $_POST['f'][ $feld['key'] ] ?? array() );
	$h        = '<div class="vp-col-2 vp-tickets" data-vp-tickets><label>' . esc_html( $feld['label'] ) . ( ! empty( $feld['required'] ) ? ' *' : '' ) . '</label>';
	$h       .= '<table class="vp-table" style="margin:4px 0"><tbody>';
	foreach ( vp_ticket_arten( $feld ) as $i => $a ) {
		$frei = null === $a['kontingent'] ? null : max( 0, $a['kontingent'] - (int) ( $verkauft[ $a['name'] ] ?? 0 ) );
		$max  = null === $frei ? 20 : min( 20, $frei );
		$h   .= '<tr><td><strong>' . esc_html( $a['name'] ) . '</strong>' . ( null !== $frei ? '<br><span class="vp-muted">' . esc_html( $frei ? sprintf( __( 'noch %d frei', 'vereinsplugin' ), $frei ) : __( 'ausverkauft', 'vereinsplugin' ) ) . '</span>' : '' ) . '</td>'
			. '<td style="text-align:right;white-space:nowrap">' . esc_html( vp_ticket_eur( $a['preis'] ) ) . '</td>'
			. '<td style="width:90px"><input type="number" min="0" max="' . (int) $max . '" step="1" name="' . esc_attr( 'f[' . $feld['key'] . '][' . $i . ']' ) . '" value="' . esc_attr( (string) (int) ( $posted[ $i ] ?? 0 ) ) . '" data-preis="' . esc_attr( $a['preis'] ) . '"' . ( $max ? '' : ' disabled' ) . ' style="width:80px" aria-label="' . esc_attr( sprintf( __( 'Anzahl %s', 'vereinsplugin' ), $a['name'] ) ) . '"></td></tr>';
	}
	$h .= '<tr><td colspan="2" style="text-align:right"><strong>' . esc_html__( 'Summe', 'vereinsplugin' ) . '</strong></td><td><strong data-vp-summe>0,00 €</strong></td></tr></tbody></table></div>';
	$h .= '<script>(function(){document.querySelectorAll("[data-vp-tickets]").forEach(function(b){function s(){var t=0;b.querySelectorAll("input[data-preis]").forEach(function(i){t+=(parseInt(i.value,10)||0)*parseFloat(i.getAttribute("data-preis"));});b.querySelector("[data-vp-summe]").textContent=t.toFixed(2).replace(".",",")+" €";}b.addEventListener("input",s);s();});})();</script>';
	return $h;
}

/**
 * Gepostete Anzahlen prüfen.
 * @return array|WP_Error art => anzahl (nur > 0)
 */
function vp_ticket_aus_post( $f, $feld, $raw ) {
	$arten    = vp_ticket_arten( $feld );
	$verkauft = vp_ticket_verkauft( $f->id );
	$out      = array();
	$summe    = 0;
	foreach ( $arten as $i => $a ) {
		$n = max( 0, min( 20, (int) ( ( (array) $raw )[ $i ] ?? 0 ) ) );
		if ( ! $n ) {
			continue;
		}
		if ( null !== $a['kontingent'] && (int) ( $verkauft['je'][ $a['name'] ] ?? 0 ) + $n > $a['kontingent'] ) {
			return new WP_Error( 'kontingent', sprintf( __( 'Von „%s“ sind nicht mehr genug Tickets frei.', 'vereinsplugin' ), $a['name'] ) );
		}
		$out[ $a['name'] ] = $n;
		$summe            += $n;
	}
	if ( $f->max_teilnehmer && $verkauft['gesamt'] + $summe > (int) $f->max_teilnehmer ) {
		return new WP_Error( 'kontingent', sprintf( __( 'Es sind nur noch %d Tickets frei.', 'vereinsplugin' ), max( 0, (int) $f->max_teilnehmer - $verkauft['gesamt'] ) ) );
	}
	if ( ! empty( $feld['required'] ) && ! $summe ) {
		return new WP_Error( 'required', __( 'Bitte mindestens ein Ticket wählen.', 'vereinsplugin' ) );
	}
	return $out;
}

/** Tickets und Zahlstatus für einen neuen Eintrag anlegen. */
function vp_tickets_anlegen( $f, $eintrag_id, $feld, array $anzahl ) {
	global $wpdb;
	$preise = array();
	foreach ( vp_ticket_arten( $feld ) as $a ) {
		$preise[ $a['name'] ] = $a['preis'];
	}
	$betrag = 0.0;
	foreach ( $anzahl as $art => $n ) {
		for ( $i = 0; $i < (int) $n; $i++ ) {
			do {
				$code = strtoupper( wp_generate_password( 10, false, false ) );
				$code = strtr( $code, array( 'O' => 'P', '0' => '7', 'I' => 'K', '1' => '8', 'L' => 'M' ) );
			} while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . vp_tickets_table() . ' WHERE code = %s', $code ) ) );
			$wpdb->insert( vp_tickets_table(), array(
				'formular_id' => (int) $f->id,
				'eintrag_id'  => (int) $eintrag_id,
				'art'         => $art,
				'preis'       => (float) ( $preise[ $art ] ?? 0 ),
				'code'        => $code,
			) );
			$betrag += (float) ( $preise[ $art ] ?? 0 );
		}
	}
	$wpdb->update( vp_formular_eintraege_table(), array(
		'zahl_status' => $betrag > 0 ? 'offen' : 'bezahlt',
		'betrag'      => round( $betrag, 2 ),
		'zugang'      => wp_generate_password( 20, false, false ),
		'zahlart'     => $betrag > 0 ? '' : 'frei',
		'bezahlt_am'  => $betrag > 0 ? null : current_time( 'mysql' ),
	), array( 'id' => (int) $eintrag_id ) );
}

/** Zeilen für die Bestätigungsmail (Bestellung, Zahlung, Link). */
function vp_ticket_mail_zeilen( $f, $eintrag_id ) {
	$e = vp_ticket_eintrag( $eintrag_id );
	if ( ! $e || '' === (string) $e->zugang ) {
		return array();
	}
	$cfg = vp_ticket_cfg( $f );
	$z   = array( '', __( 'Deine Tickets', 'vereinsplugin' ), '' );
	$z[] = sprintf( __( 'Bestellnummer: %s', 'vereinsplugin' ), vp_ticket_bestellnr( $e->id ) );
	$z[] = sprintf( __( 'Betrag: %s', 'vereinsplugin' ), vp_ticket_eur( $e->betrag ) );
	if ( 'offen' === $e->zahl_status ) {
		$z[] = '';
		$z[] = $cfg['frist']
			? sprintf( __( 'Bitte überweise den Betrag innerhalb von %d Tagen:', 'vereinsplugin' ), $cfg['frist'] )
			: __( 'Bitte überweise den Betrag:', 'vereinsplugin' );
		$z[] = sprintf( __( 'Empfänger: %s', 'vereinsplugin' ), vp_ticket_empfaenger() );
		$z[] = sprintf( 'IBAN: %s', trim( chunk_split( strtoupper( preg_replace( '/\s+/', '', (string) get_option( 'wl_iban', '' ) ) ), 4, ' ' ) ) );
		$z[] = sprintf( __( 'Verwendungszweck: %s', 'vereinsplugin' ), vp_ticket_bestellnr( $e->id ) );
		$z[] = __( 'Auf der Ticket-Seite gibt es dafür auch einen QR-Code für deine Banking-App.', 'vereinsplugin' );
	}
	$z[] = '';
	$z[] = __( 'Deine Tickets (QR-Codes zum Vorzeigen am Einlass):', 'vereinsplugin' );
	$z[] = vp_ticket_seite_url( $e );
	return $z;
}

function vp_ticket_empfaenger() {
	return (string) get_option( 'vp_org_name', get_option( 'vp_sepa_glaeubiger', get_bloginfo( 'name' ) ) );
}

/* -------------------------------------------------------------------------
 * Zahlung
 * ---------------------------------------------------------------------- */

/**
 * Zahlstatus einer Bestellung setzen.
 * @param string $status bezahlt | offen | storniert
 * @param string $zahlart ueberweisung | bar | bank
 */
function vp_ticket_zahlung_setzen( $eintrag_id, $status, $zahlart = '', $datum = '', $buchung_id = 0 ) {
	global $wpdb;
	$upd = array( 'zahl_status' => $status );
	if ( 'bezahlt' === $status ) {
		$upd['zahlart']    = $zahlart;
		$upd['bezahlt_am'] = $datum ?: current_time( 'mysql' );
	} elseif ( 'offen' === $status ) {
		$upd['zahlart']    = '';
		$upd['bezahlt_am'] = null;
	}
	if ( $buchung_id ) {
		$upd['buchung_id'] = (int) $buchung_id;
	}
	$wpdb->update( vp_formular_eintraege_table(), $upd, array( 'id' => (int) $eintrag_id ) );
	$wpdb->update( vp_tickets_table(), array( 'status' => 'storniert' === $status ? 'storniert' : 'gueltig' ), array( 'eintrag_id' => (int) $eintrag_id ) );
}

/** Mail mit Ticket-Link (neu senden oder Zahlungserinnerung). */
function vp_ticket_mail_senden( $f, $e, $erinnerung = false ) {
	$mail = function_exists( 'vp_formular_eintrag_email' ) ? vp_formular_eintrag_email( $f, $e ) : $e->anzeige_email;
	if ( ! is_email( $mail ) ) {
		return false;
	}
	$z = array_merge(
		array( sprintf( __( 'Hallo%s,', 'vereinsplugin' ), $e->anzeige_name ? ' ' . $e->anzeige_name : '' ), '' ),
		$erinnerung
			? array( sprintf( __( 'für deine Tickets für „%s“ ist bei uns noch keine Zahlung eingegangen. Falls du schon überwiesen hast, ist alles gut – dann überschneidet sich das.', 'vereinsplugin' ), $f->titel ) )
			: array( sprintf( __( 'hier noch einmal deine Tickets für „%s“.', 'vereinsplugin' ), $f->titel ) ),
		vp_ticket_mail_zeilen( $f, $e->id ),
		array( '', __( 'Viele Grüße', 'vereinsplugin' ), get_bloginfo( 'name' ) )
	);
	$betreff = $erinnerung ? sprintf( __( 'Zahlungserinnerung: %s', 'vereinsplugin' ), $f->titel ) : sprintf( __( 'Deine Tickets: %s', 'vereinsplugin' ), $f->titel );
	return wp_mail( $mail, sprintf( '[%s] %s', get_bloginfo( 'name' ), $betreff ), implode( "\n", $z ) );
}

/** POST-Aktionen in der Formular-Verwaltung. @return string Meldung */
function vp_ticket_post_verarbeiten() {
	if ( empty( $_POST['vp_ticket_aktion'] ) ) {
		return '';
	}
	$aktion = sanitize_key( wp_unslash( $_POST['vp_ticket_aktion'] ) );
	$f      = vp_formular_get( (int) ( $_POST['formular_id'] ?? 0 ) );
	if ( ! $f ) {
		return '';
	}
	if ( 'erinnern' === $aktion ) {
		$n = 0;
		foreach ( vp_formular_eintraege( $f->id ) as $e ) {
			if ( 'offen' === ( $e->zahl_status ?? '' ) && vp_ticket_mail_senden( $f, $e, true ) ) {
				$n++;
			}
		}
		return sprintf( __( 'Zahlungserinnerung an %d Personen verschickt.', 'vereinsplugin' ), $n );
	}
	if ( 'pin' === $aktion ) {
		vp_ticket_cfg_speichern( $f, array( 'pin' => (string) wp_rand( 100000, 999999 ) ) );
		return __( 'Neue Einlass-PIN erzeugt.', 'vereinsplugin' );
	}
	$e = vp_ticket_eintrag( (int) ( $_POST['eintrag_id'] ?? 0 ) );
	if ( ! $e || (int) $e->formular_id !== (int) $f->id ) {
		return '';
	}
	switch ( $aktion ) {
		case 'ueberweisung':
		case 'bar':
			vp_ticket_zahlung_setzen( $e->id, 'bezahlt', $aktion );
			return sprintf( __( '%s als bezahlt markiert.', 'vereinsplugin' ), vp_ticket_bestellnr( $e->id ) );
		case 'offen':
			vp_ticket_zahlung_setzen( $e->id, 'offen' );
			return sprintf( __( '%s wieder auf offen gesetzt.', 'vereinsplugin' ), vp_ticket_bestellnr( $e->id ) );
		case 'storno':
			vp_ticket_zahlung_setzen( $e->id, 'storniert' );
			return sprintf( __( '%s storniert – die Tickets sind ungültig und die Plätze wieder frei.', 'vereinsplugin' ), vp_ticket_bestellnr( $e->id ) );
		case 'mail':
			return vp_ticket_mail_senden( $f, $e ) ? __( 'Ticket-Mail erneut verschickt.', 'vereinsplugin' ) : __( 'Keine gültige E-Mail-Adresse.', 'vereinsplugin' );
	}
	return '';
}

function vp_ticket_cfg_speichern( $f, array $neu ) {
	global $wpdb;
	$c = array_merge( vp_ticket_cfg( $f ), $neu );
	$wpdb->update( vp_formular_table(), array( 'ticket_cfg' => wp_json_encode( $c ) ), array( 'id' => (int) $f->id ) );
}

/** Einstellungen aus dem Formular-Editor. */
function vp_ticket_cfg_aus_post( $f ) {
	$alt = $f ? vp_ticket_cfg( $f ) : array( 'pin' => '' );
	return wp_json_encode( array(
		'konto' => sanitize_text_field( wp_unslash( $_POST['ticket_konto'] ?? '' ) ),
		'frist' => max( 0, (int) ( $_POST['ticket_frist'] ?? 7 ) ),
		'pin'   => $alt['pin'] ?: (string) wp_rand( 100000, 999999 ),
	) );
}

/** Einstellungsblock im Formular-Editor. */
function vp_ticket_editor_html( $f ) {
	$cfg = $f ? vp_ticket_cfg( $f ) : array( 'konto' => '', 'frist' => 7, 'pin' => '' );
	$h   = '<fieldset><legend>' . esc_html__( 'Tickets & Bezahlung (nur mit einem Feld „Tickets“)', 'vereinsplugin' ) . '</legend><div class="vp-form-grid">';
	if ( function_exists( 'vp_bh_konto_options' ) ) {
		$h .= '<label>' . esc_html__( 'Einnahmen buchen auf (Bank-Import)', 'vereinsplugin' ) . '<select name="ticket_konto">' . vp_bh_konto_options( $cfg['konto'], 'alle', __( '– beim Import wählen –', 'vereinsplugin' ) ) . '</select></label>';
	}
	$h .= '<label>' . esc_html__( 'Zahlungsfrist in Tagen (nur als Hinweis)', 'vereinsplugin' ) . '<input type="number" min="0" name="ticket_frist" value="' . (int) $cfg['frist'] . '"></label>';
	$h .= '</div><p class="vp-muted">' . esc_html__( 'Ticketarten im Feld „Tickets“ je Zeile: Name | Preis | Kontingent, z. B. „Normal | 12 | 100“. Das Teilnehmerlimit oben gilt für Tickets insgesamt. Bezahlt wird per Überweisung (IBAN aus den Vereinseinstellungen) mit Verwendungszweck TICKET-Nr.; die Einlass-PIN steht in den Einträgen.', 'vereinsplugin' ) . '</p></fieldset>';
	return $h;
}

/* -------------------------------------------------------------------------
 * Verwaltung: Übersicht, Spalten, Aktionen
 * ---------------------------------------------------------------------- */

function vp_ticket_status_badge( $s ) {
	$l = array( 'offen' => array( __( 'offen', 'vereinsplugin' ), '#fef3c7' ), 'bezahlt' => array( __( 'bezahlt', 'vereinsplugin' ), '#dcfce7' ), 'storniert' => array( __( 'storniert', 'vereinsplugin' ), '#fee2e2' ) );
	$x = $l[ $s ] ?? array( $s, '#f1f5f9' );
	return '<span class="vp-badge" style="background:' . esc_attr( $x[1] ) . '">' . esc_html( $x[0] ) . '</span>';
}

/** Kasten über den Einträgen: Verkauf, Geld, Einlass, PIN, Erinnerung. */
function vp_ticket_uebersicht_html( $f, array $eintraege ) {
	global $wpdb;
	$feld     = vp_ticket_feld( $f );
	$verkauft = vp_ticket_verkauft( $f->id );
	$cfg      = vp_ticket_cfg( $f );
	$geld     = array( 'bezahlt' => 0.0, 'offen' => 0.0, 'bar' => 0.0 );
	$offen_n  = 0;
	foreach ( $eintraege as $e ) {
		if ( 'bezahlt' === ( $e->zahl_status ?? '' ) ) {
			$geld['bezahlt'] += (float) $e->betrag;
			if ( 'bar' === $e->zahlart ) {
				$geld['bar'] += (float) $e->betrag;
			}
		} elseif ( 'offen' === ( $e->zahl_status ?? '' ) ) {
			$geld['offen'] += (float) $e->betrag;
			$offen_n++;
		}
	}
	$drin = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . vp_tickets_table() . " WHERE formular_id = %d AND status <> 'storniert' AND eingeloest_am IS NOT NULL", (int) $f->id ) );
	$h    = '<div class="vp-card"><h4 style="margin-top:0">' . esc_html__( 'Vorverkauf', 'vereinsplugin' ) . '</h4><div style="display:flex;gap:18px;flex-wrap:wrap">';
	foreach ( vp_ticket_arten( $feld ) as $a ) {
		$h .= '<div><strong>' . (int) ( $verkauft['je'][ $a['name'] ] ?? 0 ) . ( null !== $a['kontingent'] ? ' / ' . (int) $a['kontingent'] : '' ) . '</strong><br><span class="vp-muted">' . esc_html( $a['name'] ) . '</span></div>';
	}
	$h .= '<div><strong>' . (int) $verkauft['gesamt'] . ( $f->max_teilnehmer ? ' / ' . (int) $f->max_teilnehmer : '' ) . '</strong><br><span class="vp-muted">' . esc_html__( 'Tickets gesamt', 'vereinsplugin' ) . '</span></div>';
	$h .= '<div><strong>' . esc_html( vp_ticket_eur( $geld['bezahlt'] ) ) . '</strong><br><span class="vp-muted">' . esc_html( sprintf( __( 'bezahlt (davon bar %s)', 'vereinsplugin' ), vp_ticket_eur( $geld['bar'] ) ) ) . '</span></div>';
	$h .= '<div><strong>' . esc_html( vp_ticket_eur( $geld['offen'] ) ) . '</strong><br><span class="vp-muted">' . esc_html( sprintf( _n( 'offen (%d Bestellung)', 'offen (%d Bestellungen)', $offen_n, 'vereinsplugin' ), $offen_n ) ) . '</span></div>';
	$h .= '<div><strong>' . $drin . ' / ' . (int) $verkauft['gesamt'] . '</strong><br><span class="vp-muted">' . esc_html__( 'eingelassen', 'vereinsplugin' ) . '</span></div></div>';
	if ( $geld['bar'] ) {
		$h .= '<p class="vp-muted">' . esc_html__( 'Bar kassierte Beträge landen in der Barkasse – beim Kassenabschluss mit buchen.', 'vereinsplugin' ) . '</p>';
	}
	// Einlass
	$einlass = add_query_arg( array( 'vp_einlass' => 'liste', 'f' => (int) $f->id ), home_url( '/' ) );
	$h      .= '<details style="margin-top:8px"><summary><strong>' . esc_html__( 'Einlass', 'vereinsplugin' ) . '</strong> · PIN <code>' . esc_html( $cfg['pin'] ?: '–' ) . '</code></summary>';
	$h      .= '<p class="vp-muted">' . esc_html__( 'Am Einlass die QR-Codes der Tickets mit der normalen Handy-Kamera scannen. Wer kein Konto mit Formular-Rechten hat, gibt einmal die PIN ein (gilt 12 Stunden auf dem Gerät). Ohne QR: Einlass-Liste mit Namenssuche.', 'vereinsplugin' ) . '</p>';
	$h      .= function_exists( 'vp_teilen_box' ) ? vp_teilen_box( $einlass, 'einlass-' . (int) $f->id, __( 'Einlass-Liste (Suche nach Name / Bestellnummer)', 'vereinsplugin' ) ) : '<p><a class="vp-btn" href="' . esc_url( $einlass ) . '">' . esc_html__( 'Einlass-Liste öffnen', 'vereinsplugin' ) . '</a></p>';
	$h      .= '<form method="post" style="display:inline">' . wp_nonce_field( 'vp_formular_edit', 'vp_fe_nonce', true, false ) . '<input type="hidden" name="formular_id" value="' . (int) $f->id . '"><button class="vp-btn" name="vp_ticket_aktion" value="pin" onclick="return confirm(\'' . esc_js( __( 'Neue PIN erzeugen? Die alte gilt dann nicht mehr.', 'vereinsplugin' ) ) . '\')">' . esc_html__( 'Neue PIN', 'vereinsplugin' ) . '</button></form></details>';
	if ( $offen_n ) {
		$h .= '<form method="post" style="margin-top:8px">' . wp_nonce_field( 'vp_formular_edit', 'vp_fe_nonce', true, false ) . '<input type="hidden" name="formular_id" value="' . (int) $f->id . '"><button class="vp-btn" name="vp_ticket_aktion" value="erinnern" onclick="return confirm(\'' . esc_js( sprintf( __( 'Zahlungserinnerung an %d Personen mit offener Zahlung senden?', 'vereinsplugin' ), $offen_n ) ) . '\')">' . esc_html( sprintf( __( '✉ Zahlungserinnerung an alle %d offenen', 'vereinsplugin' ), $offen_n ) ) . '</button> <span class="vp-muted">' . esc_html__( 'Vorher am besten den Bank-Import machen oder Zahlungen im Online-Banking abhaken.', 'vereinsplugin' ) . '</span></form>';
	}
	return $h . '</div>';
}

/** Zusätzliche Spalten je Eintrag (Bestellung, Zahlung + Knöpfe). */
function vp_ticket_spalten_html( $f, $e ) {
	if ( '' === (string) ( $e->zahl_status ?? '' ) ) {
		return '<td></td><td></td>';
	}
	$knopf = function ( $wert, $label, $bestaetigen = '' ) use ( $f, $e ) {
		return '<form method="post" style="display:inline">' . wp_nonce_field( 'vp_formular_edit', 'vp_fe_nonce', true, false )
			. '<input type="hidden" name="formular_id" value="' . (int) $f->id . '"><input type="hidden" name="eintrag_id" value="' . (int) $e->id . '">'
			. '<button class="vp-btn" style="padding:2px 8px;font-size:.85em" name="vp_ticket_aktion" value="' . esc_attr( $wert ) . '"' . ( $bestaetigen ? ' onclick="return confirm(\'' . esc_js( $bestaetigen ) . '\')"' : '' ) . '>' . esc_html( $label ) . '</button></form> ';
	};
	$h  = '<td style="white-space:nowrap"><strong>' . esc_html( vp_ticket_bestellnr( $e->id ) ) . '</strong><br><span class="vp-muted">' . esc_html( vp_ticket_eur( $e->betrag ) ) . '</span><br><a href="' . esc_url( vp_ticket_seite_url( $e ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Tickets', 'vereinsplugin' ) . '</a></td>';
	$h .= '<td>' . vp_ticket_status_badge( $e->zahl_status );
	if ( 'bezahlt' === $e->zahl_status ) {
		$art = array( 'ueberweisung' => __( 'Überweisung', 'vereinsplugin' ), 'bar' => __( 'bar', 'vereinsplugin' ), 'bank' => __( 'Bank-Import', 'vereinsplugin' ), 'frei' => __( 'kostenlos', 'vereinsplugin' ) )[ $e->zahlart ] ?? $e->zahlart;
		$h  .= '<br><span class="vp-muted">' . esc_html( $art . ( $e->bezahlt_am ? ' · ' . mysql2date( 'd.m.Y', $e->bezahlt_am ) : '' ) . ( $e->buchung_id ? ' · ' . __( 'gebucht', 'vereinsplugin' ) : '' ) ) . '</span>';
	}
	$h .= '<div style="margin-top:4px">';
	if ( 'offen' === $e->zahl_status ) {
		$h .= $knopf( 'ueberweisung', __( '✓ überwiesen', 'vereinsplugin' ) ) . $knopf( 'bar', __( '✓ bar', 'vereinsplugin' ) );
	} elseif ( 'bezahlt' === $e->zahl_status && empty( $e->buchung_id ) && 'frei' !== $e->zahlart ) {
		$h .= $knopf( 'offen', __( '↺ offen', 'vereinsplugin' ) );
	}
	if ( 'storniert' !== $e->zahl_status ) {
		$h .= $knopf( 'mail', __( '✉ Ticket-Mail', 'vereinsplugin' ) ) . $knopf( 'storno', __( 'stornieren', 'vereinsplugin' ), __( 'Bestellung stornieren? Die Tickets werden ungültig.', 'vereinsplugin' ) );
	} else {
		$h .= $knopf( 'offen', __( '↺ wiederherstellen', 'vereinsplugin' ) );
	}
	return $h . '</div></td>';
}

/* -------------------------------------------------------------------------
 * Öffentliche Seiten: Ticket-Seite und Einlass
 * ---------------------------------------------------------------------- */

add_action( 'template_redirect', 'vp_ticket_seiten', 2 );
function vp_ticket_seiten() {
	if ( isset( $_GET['vp_ticket'] ) && function_exists( 'vp_teilen_seite' ) ) {
		$teile = explode( '-', sanitize_text_field( wp_unslash( $_GET['vp_ticket'] ) ), 2 );
		$e     = vp_ticket_eintrag( (int) $teile[0] );
		if ( ! $e || '' === (string) $e->zugang || ! hash_equals( (string) $e->zugang, (string) ( $teile[1] ?? '' ) ) ) {
			vp_teilen_seite( __( 'Ticket nicht gefunden', 'vereinsplugin' ), '<div class="vp-card vp-note vp-note-warn">' . esc_html__( 'Diesen Ticket-Link gibt es nicht. Bitte den Link aus der Bestätigungsmail verwenden.', 'vereinsplugin' ) . '</div>', 404 );
		}
		vp_teilen_seite( __( 'Deine Tickets', 'vereinsplugin' ), vp_ticket_seite_html( $e ) );
	}
	if ( isset( $_GET['vp_einlass'] ) && function_exists( 'vp_teilen_seite' ) ) {
		vp_teilen_seite( __( 'Einlass', 'vereinsplugin' ), vp_einlass_html() );
	}
}

function vp_ticket_seite_html( $e ) {
	$f = vp_formular_get( (int) $e->formular_id );
	wp_enqueue_script( 'vp-girocode' );
	$h  = '<div class="vp-card"><h2 style="margin-top:0">' . esc_html( $f ? $f->titel : '' ) . '</h2>';
	$h .= '<p>' . esc_html( sprintf( __( 'Bestellung %1$s · %2$s · %3$s', 'vereinsplugin' ), vp_ticket_bestellnr( $e->id ), $e->anzeige_name, vp_ticket_eur( $e->betrag ) ) ) . ' ' . vp_ticket_status_badge( $e->zahl_status ) . '</p>';
	if ( 'storniert' === $e->zahl_status ) {
		return $h . '<div class="vp-note vp-note-warn">' . esc_html__( 'Diese Bestellung wurde storniert. Bei Fragen antworte auf die Bestätigungsmail.', 'vereinsplugin' ) . '</div></div>';
	}
	if ( 'offen' === $e->zahl_status ) {
		$iban = (string) get_option( 'wl_iban', '' );
		$h   .= '<div class="vp-note"><strong>' . esc_html__( 'Bitte überweisen', 'vereinsplugin' ) . '</strong><br>'
			. esc_html( sprintf( __( 'Empfänger: %s', 'vereinsplugin' ), vp_ticket_empfaenger() ) ) . '<br>IBAN: ' . esc_html( trim( chunk_split( strtoupper( preg_replace( '/\s+/', '', $iban ) ), 4, ' ' ) ) ) . '<br>'
			. esc_html( sprintf( __( 'Betrag: %s', 'vereinsplugin' ), vp_ticket_eur( $e->betrag ) ) ) . '<br>'
			. esc_html( sprintf( __( 'Verwendungszweck: %s', 'vereinsplugin' ), vp_ticket_bestellnr( $e->id ) ) ) . '</div>';
		if ( $iban && function_exists( 'vp_epc_payload' ) ) {
			$h .= '<p class="vp-muted">' . esc_html__( 'GiroCode mit der Banking-App scannen:', 'vereinsplugin' ) . '</p><div class="vp-girocode-qr" data-epc="' . esc_attr( vp_epc_payload( vp_ticket_empfaenger(), $iban, $e->betrag, vp_ticket_bestellnr( $e->id ) ) ) . '" data-label="GiroCode" style="width:200px;height:200px;background:#fff;padding:6px;border-radius:8px"></div>';
		}
		$h .= '<p class="vp-muted">' . esc_html__( 'Deine Tickets gelten schon jetzt. Falls die Zahlung bis zur Veranstaltung noch nicht bei uns verbucht ist, kannst du am Einlass auch bar zahlen.', 'vereinsplugin' ) . '</p>';
	}
	$h .= '</div><style>.vp-ticket-qr svg{display:block;width:100%;height:100%}</style><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px">';
	foreach ( vp_tickets_von_eintrag( $e->id ) as $i => $t ) {
		$h .= '<div class="vp-card" style="margin:0;text-align:center"><strong>' . esc_html( $t->art ) . '</strong><br><span class="vp-muted">' . esc_html( sprintf( __( 'Ticket %1$d · %2$s', 'vereinsplugin' ), $i + 1, $t->code ) ) . '</span>'
			. '<div class="vp-girocode-qr vp-ticket-qr" data-epc="' . esc_attr( vp_ticket_einlass_url( $t->code ) ) . '" data-label="Ticket" style="width:180px;height:180px;margin:8px auto;background:#fff;padding:6px;border-radius:8px"></div>'
			. ( $t->eingeloest_am ? '<span class="vp-badge">' . esc_html( sprintf( __( 'eingelöst %s', 'vereinsplugin' ), mysql2date( 'd.m. H:i', $t->eingeloest_am ) ) ) . '</span>' : '' ) . '</div>';
	}
	return $h . '</div><p class="vp-muted" style="margin-top:12px">' . esc_html__( 'Tipp: Diese Seite als Lesezeichen speichern oder einen Screenshot machen.', 'vereinsplugin' ) . '</p>';
}

/** Darf das Gerät für dieses Formular einlassen? (Rechte oder PIN-Cookie) */
function vp_einlass_darf( $f ) {
	if ( current_user_can( 'vp_manage_formulare' ) ) {
		return true;
	}
	$cfg = vp_ticket_cfg( $f );
	if ( '' === $cfg['pin'] ) {
		return false;
	}
	$soll = hash_hmac( 'sha256', $f->id . '|' . $cfg['pin'], wp_salt( 'auth' ) );
	return hash_equals( $soll, (string) ( $_COOKIE[ 'vp_einlass_' . (int) $f->id ] ?? '' ) );
}

function vp_einlass_pin_formular( $f, $fehler = '' ) {
	return '<div class="vp-card"><h2 style="margin-top:0">' . esc_html( sprintf( __( 'Einlass: %s', 'vereinsplugin' ), $f->titel ) ) . '</h2>'
		. ( $fehler ? '<div class="vp-note vp-note-error">' . esc_html( $fehler ) . '</div>' : '' )
		. '<form method="post" class="vp-form"><label>' . esc_html__( 'Einlass-PIN', 'vereinsplugin' ) . '<input type="text" inputmode="numeric" name="vp_einlass_pin" autocomplete="off" required></label>'
		. '<input type="hidden" name="f" value="' . (int) $f->id . '"><button class="vp-btn vp-btn-primary">' . esc_html__( 'Freischalten', 'vereinsplugin' ) . '</button></form>'
		. '<p class="vp-muted">' . esc_html__( 'Die PIN steht bei den Einträgen des Formulars. Vorstand/Formular-Verwaltung: einfach einloggen.', 'vereinsplugin' ) . '</p></div>';
}

/** Einlass-Seite: einzelnes Ticket (QR) oder Liste mit Suche. */
function vp_einlass_html() {
	global $wpdb;
	$code = strtoupper( sanitize_text_field( wp_unslash( $_GET['vp_einlass'] ) ) );
	$t    = null;
	if ( 'LISTE' !== $code ) {
		$t = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vp_tickets_table() . ' WHERE code = %s', $code ) );
		if ( ! $t ) {
			return '<div class="vp-card vp-note vp-note-error" style="font-size:1.3em">✗ ' . esc_html__( 'Unbekanntes Ticket.', 'vereinsplugin' ) . '</div>';
		}
	}
	$f = vp_formular_get( $t ? (int) $t->formular_id : (int) ( $_REQUEST['f'] ?? 0 ) );
	if ( ! $f ) {
		return '<div class="vp-card vp-note vp-note-error">' . esc_html__( 'Veranstaltung nicht gefunden.', 'vereinsplugin' ) . '</div>';
	}

	// PIN-Eingabe
	if ( isset( $_POST['vp_einlass_pin'] ) ) {
		$cfg = vp_ticket_cfg( $f );
		if ( '' !== $cfg['pin'] && hash_equals( $cfg['pin'], trim( sanitize_text_field( wp_unslash( $_POST['vp_einlass_pin'] ) ) ) ) ) {
			$wert = hash_hmac( 'sha256', $f->id . '|' . $cfg['pin'], wp_salt( 'auth' ) );
			setcookie( 'vp_einlass_' . (int) $f->id, $wert, time() + 12 * HOUR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
			$_COOKIE[ 'vp_einlass_' . (int) $f->id ] = $wert;
		} else {
			return vp_einlass_pin_formular( $f, __( 'PIN stimmt nicht.', 'vereinsplugin' ) );
		}
	}
	if ( ! vp_einlass_darf( $f ) ) {
		return vp_einlass_pin_formular( $f );
	}

	// Aktionen
	$meldung = '';
	if ( isset( $_POST['vp_einlass_aktion'] ) && wp_verify_nonce( wp_unslash( $_POST['_vp_einlass'] ?? '' ), 'vp_einlass_' . $f->id ) ) {
		$tid = (int) ( $_POST['ticket_id'] ?? 0 );
		$tk  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vp_tickets_table() . ' WHERE id = %d AND formular_id = %d', $tid, (int) $f->id ) );
		$wer = is_user_logged_in() ? wp_get_current_user()->display_name : __( 'Einlass (PIN)', 'vereinsplugin' );
		if ( $tk ) {
			switch ( sanitize_key( wp_unslash( $_POST['vp_einlass_aktion'] ) ) ) {
				case 'bar':
					vp_ticket_zahlung_setzen( $tk->eintrag_id, 'bezahlt', 'bar' );
					// fall through: kassiert und eingelassen
				case 'rein':
					if ( ! $tk->eingeloest_am ) {
						$wpdb->update( vp_tickets_table(), array( 'eingeloest_am' => current_time( 'mysql' ), 'eingeloest_von' => $wer ), array( 'id' => $tk->id ) );
					}
					$meldung = __( 'Eingelassen ✓', 'vereinsplugin' );
					break;
				case 'zurueck':
					$wpdb->update( vp_tickets_table(), array( 'eingeloest_am' => null, 'eingeloest_von' => '' ), array( 'id' => $tk->id ) );
					$meldung = __( 'Einlass zurückgenommen.', 'vereinsplugin' );
					break;
			}
			if ( $t ) {
				$t = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vp_tickets_table() . ' WHERE id = %d', $t->id ) );
			}
		}
	}

	$drin = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . vp_tickets_table() . " WHERE formular_id = %d AND status <> 'storniert' AND eingeloest_am IS NOT NULL", (int) $f->id ) );
	$alle = vp_ticket_verkauft( $f->id )['gesamt'];
	$kopf = '<p class="vp-muted">' . esc_html( $f->titel . ' · ' . sprintf( __( 'eingelassen %1$d von %2$d', 'vereinsplugin' ), $drin, $alle ) ) . ' · <a href="' . esc_url( add_query_arg( array( 'vp_einlass' => 'liste', 'f' => (int) $f->id ), home_url( '/' ) ) ) . '">' . esc_html__( 'Liste / Suche', 'vereinsplugin' ) . '</a></p>';
	$h    = $meldung ? '<div class="vp-card vp-note" style="font-size:1.2em">' . esc_html( $meldung ) . '</div>' : '';

	if ( $t ) {
		return $h . $kopf . vp_einlass_ticket_karte( $f, $t, true );
	}

	// Liste mit Suche
	$suche = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
	$h    .= $kopf . '<form method="get" class="vp-card" style="display:flex;gap:8px"><input type="hidden" name="vp_einlass" value="liste"><input type="hidden" name="f" value="' . (int) $f->id . '"><input type="search" name="s" value="' . esc_attr( $suche ) . '" placeholder="' . esc_attr__( 'Name, Bestellnummer oder Ticket-Code', 'vereinsplugin' ) . '" style="flex:1;padding:8px"><button class="vp-btn vp-btn-primary">' . esc_html__( 'Suchen', 'vereinsplugin' ) . '</button></form>';
	if ( '' === $suche ) {
		return $h . '<p class="vp-muted">' . esc_html__( 'Suchbegriff eingeben – oder einfach den QR-Code des Tickets mit der Kamera scannen.', 'vereinsplugin' ) . '</p>';
	}
	$like = '%' . $wpdb->esc_like( $suche ) . '%';
	$nr   = (int) preg_replace( '/\D/', '', $suche );
	$rows = (array) $wpdb->get_results( $wpdb->prepare(
		'SELECT t.* FROM ' . vp_tickets_table() . ' t JOIN ' . vp_formular_eintraege_table() . ' e ON e.id = t.eintrag_id
		 WHERE t.formular_id = %d AND ( e.anzeige_name LIKE %s OR e.anzeige_email LIKE %s OR t.code LIKE %s OR e.id = %d ) ORDER BY e.anzeige_name, t.id LIMIT 60',
		(int) $f->id, $like, $like, $like, $nr
	) );
	if ( ! $rows ) {
		return $h . '<p class="vp-muted">' . esc_html__( 'Nichts gefunden.', 'vereinsplugin' ) . '</p>';
	}
	foreach ( $rows as $tk ) {
		$h .= vp_einlass_ticket_karte( $f, $tk, false );
	}
	return $h;
}

/** Karte eines Tickets mit Status und Knöpfen. */
function vp_einlass_ticket_karte( $f, $t, $gross ) {
	$e     = vp_ticket_eintrag( (int) $t->eintrag_id );
	$nonce = wp_nonce_field( 'vp_einlass_' . $f->id, '_vp_einlass', false, false ) . '<input type="hidden" name="ticket_id" value="' . (int) $t->id . '"><input type="hidden" name="f" value="' . (int) $f->id . '">';
	if ( 'storniert' === $t->status || ( $e && 'storniert' === $e->zahl_status ) ) {
		$farbe = '#fee2e2';
		$titel = '✗ ' . __( 'Storniert – kein Einlass', 'vereinsplugin' );
	} elseif ( $t->eingeloest_am ) {
		$farbe = '#fee2e2';
		$titel = '✗ ' . sprintf( __( 'Schon eingelöst %1$s (%2$s)', 'vereinsplugin' ), mysql2date( 'd.m. H:i', $t->eingeloest_am ), $t->eingeloest_von );
	} elseif ( $e && 'offen' === $e->zahl_status ) {
		$farbe = '#fef3c7';
		$titel = '! ' . sprintf( __( 'Noch nicht bezahlt: %s für die ganze Bestellung', 'vereinsplugin' ), vp_ticket_eur( $e->betrag ) );
	} else {
		$farbe = '#dcfce7';
		$titel = '✓ ' . __( 'Gültig', 'vereinsplugin' );
	}
	$h  = '<div class="vp-card" style="background:' . esc_attr( $farbe ) . ';color:#111;' . ( $gross ? 'font-size:1.15em' : '' ) . '">';
	$h .= '<strong style="font-size:' . ( $gross ? '1.4em' : '1.1em' ) . '">' . esc_html( $titel ) . '</strong>';
	$h .= '<p style="margin:6px 0">' . esc_html( $t->art . ' · ' . ( $e ? $e->anzeige_name : '' ) . ' · ' . ( $e ? vp_ticket_bestellnr( $e->id ) : '' ) . ' · ' . $t->code ) . '</p>';
	$h .= '<form method="post" style="display:flex;gap:8px;flex-wrap:wrap">' . $nonce;
	if ( 'storniert' !== $t->status && ! $t->eingeloest_am ) {
		if ( $e && 'offen' === $e->zahl_status ) {
			$h .= '<button class="vp-btn vp-btn-primary" name="vp_einlass_aktion" value="bar">' . esc_html( sprintf( __( '%s bar kassiert & einlassen', 'vereinsplugin' ), vp_ticket_eur( $e->betrag ) ) ) . '</button>';
			$h .= '<button class="vp-btn" name="vp_einlass_aktion" value="rein">' . esc_html__( 'trotzdem einlassen (Überweisung kommt)', 'vereinsplugin' ) . '</button>';
		} else {
			$h .= '<button class="vp-btn vp-btn-primary" name="vp_einlass_aktion" value="rein">' . esc_html__( 'Einlassen', 'vereinsplugin' ) . '</button>';
		}
	} elseif ( $t->eingeloest_am ) {
		$h .= '<button class="vp-btn" name="vp_einlass_aktion" value="zurueck" onclick="return confirm(\'' . esc_js( __( 'Einlass zurücknehmen?', 'vereinsplugin' ) ) . '\')">' . esc_html__( 'Einlass zurücknehmen', 'vereinsplugin' ) . '</button>';
	}
	return $h . '</form></div>';
}

/* -------------------------------------------------------------------------
 * Bank-Import: TICKET-<Nr> erkennen
 * ---------------------------------------------------------------------- */

function vp_ticket_kennung_finden( $text ) {
	return preg_match( '/TICKET\s*-?\s*(\d+)/i', (string) $text, $m ) ? (int) $m[1] : 0;
}

/** Kandidat für eine Bankzeile (Einnahme): Bestellung, die noch nicht gebucht ist. */
function vp_ticket_bank_kandidat( array $r ) {
	if ( (float) $r['betrag'] <= 0 || get_option( 'vp_tickets_db_version' ) !== VP_TICKETS_DB_VERSION ) {
		return null;
	}
	$nr = vp_ticket_kennung_finden( ( $r['zweck'] ?? '' ) . ' ' . ( $r['name'] ?? '' ) );
	if ( ! $nr ) {
		return null;
	}
	$e = vp_ticket_eintrag( $nr );
	if ( ! $e || '' === (string) $e->zahl_status || ! empty( $e->buchung_id ) || 'storniert' === $e->zahl_status ) {
		return null;
	}
	$f = vp_formular_get( (int) $e->formular_id );
	return array(
		'id'    => (int) $e->id,
		'text'  => sprintf( __( '%1$s · %2$s · %3$s · %4$s (%5$s)', 'vereinsplugin' ), vp_ticket_bestellnr( $e->id ), $f ? $f->titel : '', $e->anzeige_name, vp_ticket_eur( $e->betrag ), 'bezahlt' === $e->zahl_status ? __( 'schon als bezahlt markiert', 'vereinsplugin' ) : __( 'offen', 'vereinsplugin' ) ),
		'konto' => $f ? vp_ticket_cfg( $f )['konto'] : '',
		'passt' => abs( (float) $e->betrag - (float) $r['betrag'] ) < 0.005,
		'buchungstext' => trim( vp_ticket_bestellnr( $e->id ) . ' · ' . ( $f ? $f->titel : '' ) . ' · ' . $e->anzeige_name, ' ·' ),
	);
}

/** Nach dem Buchen einer Bankzeile: Bestellung als bezahlt + gebucht markieren. */
function vp_ticket_bank_gebucht( $eintrag_id, $buchung_id, $datum ) {
	$e = vp_ticket_eintrag( $eintrag_id );
	if ( ! $e ) {
		return;
	}
	if ( 'bezahlt' === $e->zahl_status ) {
		global $wpdb;
		$wpdb->update( vp_formular_eintraege_table(), array( 'buchung_id' => (int) $buchung_id ), array( 'id' => (int) $e->id ) );
	} else {
		vp_ticket_zahlung_setzen( $e->id, 'bezahlt', 'bank', $datum . ' 00:00:00', $buchung_id );
	}
}

/* -------------------------------------------------------------------------
 * Wöchentliche Prüfung: Zahlungseingänge (Kassier:in)
 * Mitgliederbereich → Vorstand · Kassier:in → „Zahlungseingänge prüfen“.
 * Gedacht für den Wochentermin im Online-Banking (Rechnungen bezahlen,
 * Auslagen erstatten): offene Ticket-Bestellungen abhaken, erinnern und –
 * wenn nach der Erinnerung die Frist verstrichen ist – stornieren.
 * Offene Ausgangsrechnungen stehen zur Kontrolle mit dabei.
 * ---------------------------------------------------------------------- */

/** Offene Ticket-Bestellungen aller Formulare (älteste zuerst). */
function vp_ticket_offene_bestellungen() {
	global $wpdb;
	if ( get_option( 'vp_tickets_db_version' ) !== VP_TICKETS_DB_VERSION ) {
		return array();
	}
	$rows = (array) $wpdb->get_results( "SELECT * FROM " . vp_formular_eintraege_table() . " WHERE zahl_status = 'offen' ORDER BY eingereicht_am ASC" );
	foreach ( $rows as $e ) {
		$e->daten = json_decode( (string) $e->daten, true ) ?: array();
	}
	return $rows;
}

function vp_ticket_offen_anzahl() {
	return count( vp_ticket_offene_bestellungen() );
}

/** Zahl für die Seitenleiste (leer, wenn nichts offen ist). */
function vp_ticket_offen_badge() {
	$n = vp_ticket_offen_anzahl();
	return $n ? (string) $n : '';
}

/** Ist die Frist nach der Erinnerung abgelaufen? */
function vp_ticket_storno_faellig( $f, $e ) {
	if ( empty( $e->erinnert_am ) ) {
		return false;
	}
	$frist = max( 3, vp_ticket_cfg( $f )['frist'] );
	return strtotime( $e->erinnert_am ) + $frist * DAY_IN_SECONDS < current_time( 'timestamp' );
}

function vp_ticket_storno_mail( $f, $e ) {
	$mail = function_exists( 'vp_formular_eintrag_email' ) ? vp_formular_eintrag_email( $f, $e ) : $e->anzeige_email;
	if ( ! is_email( $mail ) ) {
		return false;
	}
	$text = array(
		sprintf( __( 'Hallo%s,', 'vereinsplugin' ), $e->anzeige_name ? ' ' . $e->anzeige_name : '' ),
		'',
		sprintf( __( 'für deine Bestellung %1$s („%2$s“) ist trotz Erinnerung keine Zahlung bei uns eingegangen. Wir haben sie deshalb storniert, die Tickets sind nicht mehr gültig.', 'vereinsplugin' ), vp_ticket_bestellnr( $e->id ), $f->titel ),
		'',
		__( 'Falls du doch schon bezahlt hast oder das ein Versehen ist, antworte einfach auf diese Mail – wir klären das.', 'vereinsplugin' ),
		'',
		__( 'Viele Grüße', 'vereinsplugin' ),
		get_bloginfo( 'name' ),
	);
	return wp_mail( $mail, sprintf( '[%s] %s', get_bloginfo( 'name' ), sprintf( __( 'Bestellung storniert: %s', 'vereinsplugin' ), $f->titel ) ), implode( "\n", $text ) );
}

function vp_render_zahlungen_pruefen_section() {
	if ( ! ( current_user_can( 'jb_view_journal' ) || current_user_can( 'vp_manage_formulare' ) ) ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) . '</div>';
	}
	global $wpdb;
	$darf = current_user_can( 'jb_edit_journal' ) || current_user_can( 'vp_manage_formulare' ) || current_user_can( 'manage_options' );
	$msg  = '';
	if ( $darf && isset( $_POST['vp_zp_aktion'] ) && check_admin_referer( 'vp_zahlungen_pruefen', 'vp_zp_nonce' ) ) {
		$e = vp_ticket_eintrag( (int) ( $_POST['eintrag_id'] ?? 0 ) );
		$f = $e ? vp_formular_get( (int) $e->formular_id ) : null;
		if ( $e && $f && 'offen' === $e->zahl_status ) {
			switch ( sanitize_key( wp_unslash( $_POST['vp_zp_aktion'] ) ) ) {
				case 'eingegangen':
					vp_ticket_zahlung_setzen( $e->id, 'bezahlt', 'ueberweisung' );
					$msg = sprintf( __( '%s: Zahlung eingegangen.', 'vereinsplugin' ), vp_ticket_bestellnr( $e->id ) );
					break;
				case 'erinnern':
					if ( vp_ticket_mail_senden( $f, $e, true ) ) {
						$wpdb->update( vp_formular_eintraege_table(), array( 'erinnert_am' => current_time( 'mysql' ) ), array( 'id' => (int) $e->id ) );
						$msg = sprintf( __( '%s: Erinnerung verschickt.', 'vereinsplugin' ), vp_ticket_bestellnr( $e->id ) );
					} else {
						$msg = __( 'Keine gültige E-Mail-Adresse – bitte anders nachfragen.', 'vereinsplugin' );
					}
					break;
				case 'storno':
					vp_ticket_zahlung_setzen( $e->id, 'storniert' );
					$mail = ! empty( $_POST['mit_mail'] ) && vp_ticket_storno_mail( $f, $e );
					$msg  = sprintf( __( '%s storniert – die Plätze sind wieder frei.', 'vereinsplugin' ), vp_ticket_bestellnr( $e->id ) ) . ( $mail ? ' ' . __( 'Die Person wurde per Mail informiert.', 'vereinsplugin' ) : '' );
					break;
			}
		}
	}

	$offen = vp_ticket_offene_bestellungen();
	$forms = array();
	ob_start();
	echo '<h2>' . esc_html__( 'Zahlungseingänge prüfen', 'vereinsplugin' ) . '</h2>';
	echo '<p class="vp-muted">' . esc_html__( 'Für den Wochentermin im Online-Banking: Zahlungseingänge mit dem Verwendungszweck abgleichen und abhaken. Was nicht da ist: erinnern. Ist nach der Erinnerung die Frist verstrichen: stornieren. Der Bank-Import bucht die Einnahmen später trotzdem richtig.', 'vereinsplugin' ) . '</p>';
	if ( $msg ) {
		echo '<div class="vp-note">' . esc_html( $msg ) . '</div>';
	}

	echo '<h3>' . esc_html( sprintf( __( 'Ticket-Bestellungen (%d offen)', 'vereinsplugin' ), count( $offen ) ) ) . '</h3>';
	if ( ! $offen ) {
		echo '<p class="vp-muted">' . esc_html__( 'Keine offenen Ticket-Zahlungen. 🎉', 'vereinsplugin' ) . '</p>';
	} else {
		$summe = array_sum( array_map( static function ( $e ) {
			return (float) $e->betrag;
		}, $offen ) );
		echo '<p class="vp-muted">' . esc_html( sprintf( __( 'Zusammen %s offen.', 'vereinsplugin' ), vp_ticket_eur( $summe ) ) ) . '</p>';
		echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Verwendungszweck', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Wer / Veranstaltung', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Betrag', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Stand', 'vereinsplugin' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $offen as $e ) {
			$fid = (int) $e->formular_id;
			if ( ! isset( $forms[ $fid ] ) ) {
				$forms[ $fid ] = vp_formular_get( $fid );
			}
			$f = $forms[ $fid ];
			if ( ! $f ) {
				continue;
			}
			$tage   = (int) floor( ( current_time( 'timestamp' ) - strtotime( $e->eingereicht_am ) ) / DAY_IN_SECONDS );
			$faellig = vp_ticket_storno_faellig( $f, $e );
			$stand  = sprintf( _n( 'bestellt vor %d Tag', 'bestellt vor %d Tagen', $tage, 'vereinsplugin' ), $tage );
			if ( ! empty( $e->erinnert_am ) ) {
				$stand .= ' · ' . sprintf( __( 'erinnert am %s', 'vereinsplugin' ), mysql2date( 'd.m.', $e->erinnert_am ) );
			}
			$knopf = function ( $wert, $label, $primary = false, $extra = '', $confirm = '' ) use ( $e ) {
				return '<form method="post" style="display:inline">' . wp_nonce_field( 'vp_zahlungen_pruefen', 'vp_zp_nonce', true, false )
					. '<input type="hidden" name="eintrag_id" value="' . (int) $e->id . '">' . $extra
					. '<button class="vp-btn' . ( $primary ? ' vp-btn-primary' : '' ) . '" style="padding:4px 10px" name="vp_zp_aktion" value="' . esc_attr( $wert ) . '"' . ( $confirm ? ' onclick="return confirm(\'' . esc_js( $confirm ) . '\')"' : '' ) . '>' . esc_html( $label ) . '</button></form> ';
			};
			echo '<tr' . ( $faellig ? ' style="background:rgba(239,68,68,.08)"' : '' ) . '><td><code>' . esc_html( vp_ticket_bestellnr( $e->id ) ) . '</code></td>'
				. '<td>' . esc_html( $e->anzeige_name ) . '<br><span class="vp-muted">' . esc_html( $f->titel ) . '</span></td>'
				. '<td style="text-align:right;white-space:nowrap"><strong>' . esc_html( vp_ticket_eur( $e->betrag ) ) . '</strong></td>'
				. '<td><span class="vp-muted">' . esc_html( $stand ) . '</span>' . ( $faellig ? '<br><span class="vp-badge" style="background:#fee2e2">' . esc_html__( 'Frist nach Erinnerung abgelaufen', 'vereinsplugin' ) . '</span>' : '' ) . '</td><td style="white-space:nowrap">';
			if ( $darf ) {
				echo $knopf( 'eingegangen', __( '✓ eingegangen', 'vereinsplugin' ), true ); // phpcs:ignore
				echo $knopf( 'erinnern', empty( $e->erinnert_am ) ? __( '✉ erinnern', 'vereinsplugin' ) : __( '✉ nochmal erinnern', 'vereinsplugin' ) ); // phpcs:ignore
				if ( ! empty( $e->erinnert_am ) ) {
					echo $knopf( 'storno', __( 'stornieren', 'vereinsplugin' ), false, '<input type="hidden" name="mit_mail" value="1">', __( 'Bestellung stornieren und der Person Bescheid geben? Die Tickets werden ungültig.', 'vereinsplugin' ) ); // phpcs:ignore
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="vp-muted">' . esc_html__( 'Stornieren geht erst nach einer Erinnerung – rot markiert, wenn danach die Zahlungsfrist des Formulars (mindestens 3 Tage) verstrichen ist.', 'vereinsplugin' ) . '</p>';
	}

	// Offene Ausgangsrechnungen zur Kontrolle.
	if ( function_exists( 'vp_rechnung_table' ) && get_option( 'vp_rechnung_db_version' ) ) {
		$re = (array) $wpdb->get_results( 'SELECT * FROM ' . vp_rechnung_table() . " WHERE status = 'offen' ORDER BY faellig_am ASC LIMIT 100" );
		echo '<h3>' . esc_html( sprintf( __( 'Offene Rechnungen (%d)', 'vereinsplugin' ), count( $re ) ) ) . '</h3>';
		if ( ! $re ) {
			echo '<p class="vp-muted">' . esc_html__( 'Keine offenen Rechnungen.', 'vereinsplugin' ) . '</p>';
		} else {
			$base = get_permalink() ?: home_url( '/' );
			echo '<div class="vp-table-wrap"><table class="vp-table"><tbody>';
			foreach ( $re as $r ) {
				$ueber = $r->faellig_am && strtotime( $r->faellig_am ) < current_time( 'timestamp' );
				printf(
					'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td><a class="vp-btn" href="%s">%s</a></td></tr>',
					esc_html( $r->nummer ),
					esc_html( (string) ( $r->empfaenger_name ?? ( $r->name ?? '' ) ) ),
					$r->faellig_am ? ( $ueber ? '<span class="vp-badge" style="background:#fee2e2">' : '<span class="vp-muted">' ) . esc_html( sprintf( __( 'fällig %s', 'vereinsplugin' ), mysql2date( 'd.m.Y', $r->faellig_am ) ) ) . '</span>' : '',
					esc_url( add_query_arg( array( 'vp_tab' => 'rechnungen', 're' => (int) $r->id ), $base ) ),
					esc_html__( 'öffnen (bezahlt setzen)', 'vereinsplugin' )
				);
			}
			echo '</tbody></table></div>';
		}
	}
	return ob_get_clean();
}
