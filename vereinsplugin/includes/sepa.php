<?php
/**
 * Kern: SEPA-Lastschriftmandate + Einzugsläufe (pain.008.001.02).
 *
 * Drei Tabellen:
 *   vp_sepa_mandate  – ein Mandat je Kontoverbindung (Mitglied oder Dritte)
 *   vp_sepa_laeufe   – ein Einzugslauf (Beitragslauf, Rechnungslauf, frei)
 *   vp_sepa_posten   – die einzelnen Lastschriften eines Laufs
 *
 * Ablauf: Mandate pflegen → Lauf anlegen (Beiträge oder offene Rechnungen)
 *   → Posten prüfen/anpassen → XML herunterladen und bei der Bank einreichen
 *   → nach Wertstellung „gebucht" setzen (schreibt ins Journal).
 *
 * Rechtlicher Rahmen, der hier abgebildet ist:
 *   - Mandatsreferenz + Datum der Unterschrift wandern in jede Lastschrift.
 *   - Sequenz FRST beim ersten Einzug, danach RCUR (wird automatisch gedreht).
 *   - Mandate ohne Nutzung verfallen nach 36 Monaten (§ Rulebook) – wird
 *     angezeigt und beim Lauf-Aufbau übersprungen.
 */

defined( 'ABSPATH' ) || exit;

define( 'VP_SEPA_DB_VERSION', '1' );

function vp_sepa_table_mandate() { global $wpdb; return $wpdb->prefix . 'vp_sepa_mandate'; }
function vp_sepa_table_laeufe()  { global $wpdb; return $wpdb->prefix . 'vp_sepa_laeufe'; }
function vp_sepa_table_posten()  { global $wpdb; return $wpdb->prefix . 'vp_sepa_posten'; }

add_action( 'plugins_loaded', 'vp_sepa_maybe_upgrade', 6 );
function vp_sepa_maybe_upgrade() {
	if ( get_option( 'vp_sepa_db_version' ) === VP_SEPA_DB_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$collate = $wpdb->get_charset_collate();

	dbDelta( 'CREATE TABLE ' . vp_sepa_table_mandate() . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		mandatsref VARCHAR(35) NOT NULL DEFAULT '',
		user_id BIGINT UNSIGNED DEFAULT NULL,
		antrag_id BIGINT UNSIGNED DEFAULT NULL,
		kontoinhaber VARCHAR(190) NOT NULL DEFAULT '',
		email VARCHAR(190) NOT NULL DEFAULT '',
		iban VARCHAR(40) NOT NULL DEFAULT '',
		bic VARCHAR(15) NOT NULL DEFAULT '',
		typ VARCHAR(8) NOT NULL DEFAULT 'CORE',
		sequenz VARCHAR(6) NOT NULL DEFAULT 'FRST',
		unterschrift_datum DATE DEFAULT NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'aktiv',
		letzte_nutzung DATE DEFAULT NULL,
		notiz TEXT NULL,
		erstellt_am DATETIME NULL,
		geaendert_am DATETIME NULL,
		PRIMARY KEY  (id),
		KEY mandatsref (mandatsref),
		KEY user_id (user_id)
	) {$collate};" );

	dbDelta( 'CREATE TABLE ' . vp_sepa_table_laeufe() . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		bezeichnung VARCHAR(190) NOT NULL DEFAULT '',
		typ VARCHAR(20) NOT NULL DEFAULT 'beitrag',
		faellig_am DATE DEFAULT NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'entwurf',
		msg_id VARCHAR(35) NOT NULL DEFAULT '',
		anzahl INT NOT NULL DEFAULT 0,
		summe DECIMAL(12,2) NOT NULL DEFAULT 0,
		konto VARCHAR(10) NOT NULL DEFAULT '',
		quelle VARCHAR(20) NOT NULL DEFAULT '',
		exportiert_am DATETIME NULL,
		gebucht_am DATETIME NULL,
		erstellt_am DATETIME NULL,
		erstellt_von BIGINT UNSIGNED DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY faellig_am (faellig_am)
	) {$collate};" );

	dbDelta( 'CREATE TABLE ' . vp_sepa_table_posten() . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		lauf_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		mandat_id BIGINT UNSIGNED DEFAULT NULL,
		user_id BIGINT UNSIGNED DEFAULT NULL,
		rechnung_id BIGINT UNSIGNED DEFAULT NULL,
		kontoinhaber VARCHAR(190) NOT NULL DEFAULT '',
		iban VARCHAR(40) NOT NULL DEFAULT '',
		bic VARCHAR(15) NOT NULL DEFAULT '',
		mandatsref VARCHAR(35) NOT NULL DEFAULT '',
		unterschrift_datum DATE DEFAULT NULL,
		sequenz VARCHAR(6) NOT NULL DEFAULT 'RCUR',
		betrag DECIMAL(10,2) NOT NULL DEFAULT 0,
		zweck VARCHAR(140) NOT NULL DEFAULT '',
		e2e VARCHAR(35) NOT NULL DEFAULT '',
		konto VARCHAR(10) NOT NULL DEFAULT '',
		status VARCHAR(16) NOT NULL DEFAULT 'offen',
		buchung_id BIGINT UNSIGNED DEFAULT NULL,
		notiz VARCHAR(190) NOT NULL DEFAULT '',
		PRIMARY KEY  (id),
		KEY lauf_id (lauf_id)
	) {$collate};" );

	update_option( 'vp_sepa_db_version', VP_SEPA_DB_VERSION );
}

/* =========================================================================
 * IBAN / BIC / Textkonvertierung
 * ====================================================================== */

function vp_iban_normalize( $s ) {
	return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $s ) );
}

/** IBAN-Prüfziffer nach ISO 7064 mod 97-10. */
function vp_iban_valid( $iban ) {
	$iban = vp_iban_normalize( $iban );
	if ( strlen( $iban ) < 15 || strlen( $iban ) > 34 || ! preg_match( '/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $iban ) ) {
		return false;
	}
	$re = substr( $iban, 4 ) . substr( $iban, 0, 4 );
	$num = '';
	for ( $i = 0, $n = strlen( $re ); $i < $n; $i++ ) {
		$c = $re[ $i ];
		$num .= ctype_digit( $c ) ? $c : (string) ( ord( $c ) - 55 );
	}
	// Stückweiser Modulo, damit auch ohne bcmath/gmp korrekt.
	$rest = '';
	for ( $i = 0, $n = strlen( $num ); $i < $n; $i++ ) {
		$rest = (string) ( ( (int) ( $rest . $num[ $i ] ) ) % 97 );
	}
	return '1' === $rest;
}

function vp_bic_valid( $bic ) {
	$bic = strtoupper( preg_replace( '/\s+/', '', (string) $bic ) );
	return '' === $bic || (bool) preg_match( '/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic );
}

/**
 * Auf den SEPA-Zeichensatz reduzieren (a-z A-Z 0-9 / - ? : ( ) . , ' + Leerzeichen).
 * Umlaute werden ausgeschrieben, alles andere zu Leerzeichen.
 */
function vp_sepa_txt( $s, $max = 70 ) {
	$s = (string) $s;
	$map = array(
		'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue', 'ß' => 'ss',
		'á' => 'a', 'à' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'í' => 'i', 'ì' => 'i',
		'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ç' => 'c', 'ñ' => 'n',
		'&' => '+', '"' => "'", '_' => '-',
	);
	$s = strtr( $s, $map );
	$s = preg_replace( "/[^A-Za-z0-9\\/\\-\\?:\\(\\)\\.,'\\+ ]/", ' ', $s );
	$s = trim( preg_replace( '/\s+/', ' ', $s ) );
	return $max ? substr( $s, 0, $max ) : $s;
}

/* =========================================================================
 * Mandate
 * ====================================================================== */

function vp_sepa_can() {
	return current_user_can( 'jb_view_journal' ) || current_user_can( 'vp_manage_members' ) || current_user_can( 'manage_options' );
}

/** Nächste freie Mandatsreferenz (Schema VP-M-00001). */
function vp_sepa_next_mandatsref() {
	global $wpdb;
	$max = (int) $wpdb->get_var( "SELECT MAX(CAST(SUBSTRING(mandatsref, 6) AS UNSIGNED)) FROM " . vp_sepa_table_mandate() . " WHERE mandatsref LIKE 'VP-M-%'" );
	return 'VP-M-' . str_pad( (string) ( $max + 1 ), 5, '0', STR_PAD_LEFT );
}

function vp_sepa_mandat_get( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vp_sepa_table_mandate() . ' WHERE id = %d', (int) $id ) );
}

function vp_sepa_mandat_fuer_user( $user_id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare(
		'SELECT * FROM ' . vp_sepa_table_mandate() . " WHERE user_id = %d AND status = 'aktiv' ORDER BY id DESC LIMIT 1",
		(int) $user_id
	) );
}

/**
 * Mandat anlegen/ändern.
 *
 * @param array $d id?, user_id, kontoinhaber, iban, bic, typ, unterschrift_datum, status, notiz, mandatsref?
 * @return int|WP_Error Mandats-ID.
 */
function vp_sepa_mandat_save( array $d ) {
	global $wpdb;
	$id   = (int) ( $d['id'] ?? 0 );
	$iban = vp_iban_normalize( $d['iban'] ?? '' );
	if ( '' === $iban || ! vp_iban_valid( $iban ) ) {
		return new WP_Error( 'bad_iban', __( 'Die IBAN ist ungültig (Prüfziffer stimmt nicht).', 'vereinsplugin' ) );
	}
	$bic = strtoupper( preg_replace( '/\s+/', '', (string) ( $d['bic'] ?? '' ) ) );
	if ( ! vp_bic_valid( $bic ) ) {
		return new WP_Error( 'bad_bic', __( 'Die BIC ist ungültig.', 'vereinsplugin' ) );
	}
	$inhaber = sanitize_text_field( (string) ( $d['kontoinhaber'] ?? '' ) );
	if ( '' === $inhaber ) {
		return new WP_Error( 'bad_req', __( 'Kontoinhaber:in ist Pflicht.', 'vereinsplugin' ) );
	}

	$row = array(
		'user_id'            => ! empty( $d['user_id'] ) ? (int) $d['user_id'] : null,
		'antrag_id'          => ! empty( $d['antrag_id'] ) ? (int) $d['antrag_id'] : null,
		'kontoinhaber'       => $inhaber,
		'email'              => sanitize_email( (string) ( $d['email'] ?? '' ) ),
		'iban'               => $iban,
		'bic'                => $bic,
		'typ'                => in_array( ( $d['typ'] ?? 'CORE' ), array( 'CORE', 'B2B' ), true ) ? $d['typ'] : 'CORE',
		'unterschrift_datum' => vp_sepa_norm_date( $d['unterschrift_datum'] ?? '' ),
		'status'             => in_array( ( $d['status'] ?? 'aktiv' ), array( 'aktiv', 'widerrufen', 'abgelaufen' ), true ) ? $d['status'] : 'aktiv',
		'notiz'              => sanitize_textarea_field( (string) ( $d['notiz'] ?? '' ) ),
		'geaendert_am'       => current_time( 'mysql' ),
	);
	if ( isset( $d['sequenz'] ) && in_array( $d['sequenz'], array( 'FRST', 'RCUR', 'OOFF', 'FNAL' ), true ) ) {
		$row['sequenz'] = $d['sequenz'];
	}

	if ( $id ) {
		$wpdb->update( vp_sepa_table_mandate(), $row, array( 'id' => $id ) );
		return $id;
	}

	$row['erstellt_am'] = current_time( 'mysql' );
	$row['sequenz']     = $row['sequenz'] ?? 'FRST';
	$ref = sanitize_text_field( (string) ( $d['mandatsref'] ?? '' ) );
	$row['mandatsref']  = $ref !== '' ? substr( vp_sepa_txt( $ref, 35 ), 0, 35 ) : vp_sepa_next_mandatsref();
	$wpdb->insert( vp_sepa_table_mandate(), $row );
	$new = (int) $wpdb->insert_id;

	// Referenz auch am Benutzerprofil führen (bestehende Felder bleiben gültig).
	if ( $new && $row['user_id'] ) {
		update_user_meta( $row['user_id'], 'vp_mandatsref', $row['mandatsref'] );
		update_user_meta( $row['user_id'], 'vp_sepa_iban', $row['iban'] );
		update_user_meta( $row['user_id'], 'vp_sepa_kontoinhaber', $row['kontoinhaber'] );
		update_user_meta( $row['user_id'], 'vp_sepa_mandat', 1 );
	}
	return $new;
}

function vp_sepa_norm_date( $s ) {
	$s = trim( (string) $s );
	if ( '' === $s ) {
		return null;
	}
	if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s ) ) {
		return $s;
	}
	if ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$/', $s, $m ) ) {
		$y = strlen( $m[3] ) === 2 ? '20' . $m[3] : $m[3];
		return sprintf( '%04d-%02d-%02d', $y, $m[2], $m[1] );
	}
	$ts = strtotime( $s );
	return $ts ? gmdate( 'Y-m-d', $ts ) : null;
}

/** Mandat gilt als verfallen, wenn 36 Monate ohne Einzug vergangen sind. */
function vp_sepa_mandat_verfallen( $m ) {
	$ref = $m->letzte_nutzung ?: $m->unterschrift_datum;
	if ( ! $ref ) {
		return false;
	}
	return strtotime( $ref . ' +36 months' ) < time();
}

/**
 * Mandate aus den Benutzerprofilen / Anträgen nachziehen (idempotent).
 * Legt für jede/n Benutzer:in mit IBAN + erteiltem Mandat einen Datensatz an,
 * sofern noch keiner existiert.
 *
 * @return array{angelegt:int, uebersprungen:int, fehler:array}
 */
function vp_sepa_mandate_import_from_users() {
	global $wpdb;
	$res = array( 'angelegt' => 0, 'uebersprungen' => 0, 'fehler' => array() );
	$users = get_users( array( 'role__in' => array( VP_MEMBER_ROLE, 'editor', 'administrator' ) ) );
	foreach ( $users as $u ) {
		$iban = vp_iban_normalize( get_user_meta( $u->ID, 'vp_sepa_iban', true ) );
		if ( '' === $iban ) {
			continue;
		}
		$exists = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . vp_sepa_table_mandate() . ' WHERE user_id = %d AND iban = %s', $u->ID, $iban
		) );
		if ( $exists ) {
			$res['uebersprungen']++;
			continue;
		}
		$r = vp_sepa_mandat_save( array(
			'user_id'            => $u->ID,
			'kontoinhaber'       => get_user_meta( $u->ID, 'vp_sepa_kontoinhaber', true ) ?: $u->display_name,
			'email'              => $u->user_email,
			'iban'               => $iban,
			'unterschrift_datum' => get_user_meta( $u->ID, 'vp_mitglied_seit', true ),
			'mandatsref'         => get_user_meta( $u->ID, 'vp_mandatsref', true ),
		) );
		if ( is_wp_error( $r ) ) {
			$res['fehler'][] = $u->display_name . ': ' . $r->get_error_message();
		} else {
			$res['angelegt']++;
		}
	}
	return $res;
}

/* =========================================================================
 * Läufe
 * ====================================================================== */

function vp_sepa_lauf_get( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vp_sepa_table_laeufe() . ' WHERE id = %d', (int) $id ) );
}

function vp_sepa_posten_of( $lauf_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		'SELECT * FROM ' . vp_sepa_table_posten() . ' WHERE lauf_id = %d ORDER BY kontoinhaber, id', (int) $lauf_id
	) );
}

/** Beitrag eines Mitglieds auf das Einzugsintervall des Laufs umrechnen. */
function vp_sepa_beitrag_fuer( $betrag, $intervall, $lauf_intervall ) {
	$faktor = array( 'monatlich' => 12, 'vierteljaehrlich' => 4, 'halbjaehrlich' => 2, 'jaehrlich' => 1 );
	$pro_jahr = (float) $betrag * ( $faktor[ $intervall ] ?? 1 );
	$teiler   = $faktor[ $lauf_intervall ] ?? 1;
	return round( $pro_jahr / $teiler, 2 );
}

/**
 * Lauf anlegen.
 *
 * @param array $args bezeichnung, faellig_am, typ (beitrag|rechnung|frei),
 *                    intervall (nur beitrag), konto, quelle, zweck_vorlage,
 *                    nur_user_ids (optional Array).
 * @return int|WP_Error
 */
function vp_sepa_lauf_create( array $args ) {
	global $wpdb;
	$faellig = vp_sepa_norm_date( $args['faellig_am'] ?? '' );
	if ( ! $faellig ) {
		return new WP_Error( 'bad_req', __( 'Fälligkeitsdatum fehlt.', 'vereinsplugin' ) );
	}
	$typ = in_array( ( $args['typ'] ?? 'beitrag' ), array( 'beitrag', 'rechnung', 'frei' ), true ) ? $args['typ'] : 'beitrag';

	$wpdb->insert( vp_sepa_table_laeufe(), array(
		'bezeichnung'  => sanitize_text_field( (string) ( $args['bezeichnung'] ?? '' ) ) ?: sprintf( __( 'Einzug %s', 'vereinsplugin' ), $faellig ),
		'typ'          => $typ,
		'faellig_am'   => $faellig,
		'status'       => 'entwurf',
		'konto'        => sanitize_text_field( (string) ( $args['konto'] ?? ( 'beitrag' === $typ ? '4100' : '4500' ) ) ),
		'quelle'       => sanitize_text_field( (string) ( $args['quelle'] ?? 'Bank KSK' ) ),
		'erstellt_am'  => current_time( 'mysql' ),
		'erstellt_von' => get_current_user_id(),
	) );
	$lauf_id = (int) $wpdb->insert_id;
	if ( ! $lauf_id ) {
		return new WP_Error( 'db', __( 'Lauf konnte nicht angelegt werden.', 'vereinsplugin' ) );
	}

	if ( 'beitrag' === $typ ) {
		vp_sepa_lauf_fill_beitraege( $lauf_id, $args );
	} elseif ( 'rechnung' === $typ ) {
		vp_sepa_lauf_fill_rechnungen( $lauf_id, $args );
	}
	vp_sepa_lauf_recalc( $lauf_id );
	return $lauf_id;
}

/** Posten aus den Mitgliedsbeiträgen füllen. */
function vp_sepa_lauf_fill_beitraege( $lauf_id, array $args ) {
	global $wpdb;
	$lauf       = vp_sepa_lauf_get( $lauf_id );
	$intervall  = sanitize_key( $args['intervall'] ?? 'jaehrlich' );
	$vorlage    = (string) ( $args['zweck_vorlage'] ?? get_option( 'vp_sepa_zweck_vorlage', 'Mitgliedsbeitrag {jahr} - {name}' ) );
	$nur        = isset( $args['nur_user_ids'] ) ? array_map( 'intval', (array) $args['nur_user_ids'] ) : null;
	$jahr       = substr( (string) $lauf->faellig_am, 0, 4 );

	$mandate = $wpdb->get_results( 'SELECT * FROM ' . vp_sepa_table_mandate() . " WHERE status = 'aktiv' AND user_id IS NOT NULL ORDER BY kontoinhaber" );
	foreach ( $mandate as $m ) {
		if ( null !== $nur && ! in_array( (int) $m->user_id, $nur, true ) ) {
			continue;
		}
		if ( vp_sepa_mandat_verfallen( $m ) ) {
			continue;
		}
		$betrag_meta = get_user_meta( $m->user_id, 'vp_beitrag', true );
		if ( '' === $betrag_meta || null === $betrag_meta ) {
			continue;
		}
		$betrag = vp_sepa_beitrag_fuer(
			(float) str_replace( ',', '.', (string) $betrag_meta ),
			(string) get_user_meta( $m->user_id, 'vp_beitrag_intervall', true ),
			$intervall
		);
		if ( $betrag <= 0 ) {
			continue;
		}
		$u = get_userdata( $m->user_id );
		vp_sepa_posten_add( $lauf_id, $m, $betrag, strtr( $vorlage, array(
			'{jahr}'       => $jahr,
			'{monat}'      => substr( (string) $lauf->faellig_am, 5, 2 ),
			'{name}'       => $u ? $u->display_name : $m->kontoinhaber,
			'{mandatsref}' => $m->mandatsref,
			'{betrag}'     => number_format( $betrag, 2, ',', '' ),
		) ), $lauf->konto );
	}
}

/** Posten aus offenen Rechnungen mit Zahlart „Lastschrift" füllen. */
function vp_sepa_lauf_fill_rechnungen( $lauf_id, array $args ) {
	global $wpdb;
	if ( ! function_exists( 'vp_rechnung_table' ) ) {
		return;
	}
	$lauf = vp_sepa_lauf_get( $lauf_id );
	$rows = $wpdb->get_results( 'SELECT * FROM ' . vp_rechnung_table() . " WHERE status = 'offen' AND zahlart = 'lastschrift' ORDER BY nummer" );
	foreach ( $rows as $r ) {
		$m = null;
		if ( ! empty( $r->mandat_id ) ) {
			$m = vp_sepa_mandat_get( $r->mandat_id );
		} elseif ( ! empty( $r->user_id ) ) {
			$m = vp_sepa_mandat_fuer_user( $r->user_id );
		}
		if ( ! $m || 'aktiv' !== $m->status ) {
			continue;
		}
		$betrag = round( (float) $r->summe, 2 );
		if ( $betrag <= 0 ) {
			continue;
		}
		$pid = vp_sepa_posten_add( $lauf_id, $m, $betrag, sprintf( __( 'Rechnung %s', 'vereinsplugin' ), $r->nummer ), $r->konto ?: $lauf->konto );
		if ( $pid ) {
			$wpdb->update( vp_sepa_table_posten(), array( 'rechnung_id' => (int) $r->id ), array( 'id' => $pid ) );
		}
	}
}

/** Einen Posten anlegen. */
function vp_sepa_posten_add( $lauf_id, $mandat, $betrag, $zweck, $konto = '' ) {
	global $wpdb;
	$mandat = is_object( $mandat ) ? $mandat : vp_sepa_mandat_get( $mandat );
	if ( ! $mandat ) {
		return 0;
	}
	$wpdb->insert( vp_sepa_table_posten(), array(
		'lauf_id'            => (int) $lauf_id,
		'mandat_id'          => (int) $mandat->id,
		'user_id'            => $mandat->user_id ? (int) $mandat->user_id : null,
		'kontoinhaber'       => $mandat->kontoinhaber,
		'iban'               => $mandat->iban,
		'bic'                => $mandat->bic,
		'mandatsref'         => $mandat->mandatsref,
		'unterschrift_datum' => $mandat->unterschrift_datum,
		'sequenz'            => in_array( $mandat->sequenz, array( 'FRST', 'RCUR', 'OOFF', 'FNAL' ), true ) ? $mandat->sequenz : 'RCUR',
		'betrag'             => round( (float) $betrag, 2 ),
		'zweck'              => vp_sepa_txt( $zweck, 140 ),
		'konto'              => sanitize_text_field( (string) $konto ),
		'status'             => 'offen',
	) );
	$id = (int) $wpdb->insert_id;
	if ( $id ) {
		$wpdb->update( vp_sepa_table_posten(), array( 'e2e' => 'VP-' . $lauf_id . '-' . $id ), array( 'id' => $id ) );
	}
	return $id;
}

function vp_sepa_lauf_recalc( $lauf_id ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare(
		'SELECT COUNT(*) AS n, COALESCE(SUM(betrag),0) AS s FROM ' . vp_sepa_table_posten() . ' WHERE lauf_id = %d', (int) $lauf_id
	) );
	$wpdb->update( vp_sepa_table_laeufe(), array(
		'anzahl' => (int) $row->n,
		'summe'  => round( (float) $row->s, 2 ),
	), array( 'id' => (int) $lauf_id ) );
}

/** Fehler/Warnungen zu einem Lauf (fehlende Mandatsdaten, ungültige IBAN …). */
function vp_sepa_lauf_pruefen( $lauf_id ) {
	$probleme = array();
	$lauf = vp_sepa_lauf_get( $lauf_id );
	if ( ! $lauf ) {
		return array( __( 'Lauf nicht gefunden.', 'vereinsplugin' ) );
	}
	if ( '' === trim( (string) get_option( 'vp_sepa_glaeubiger_id', '' ) ) ) {
		$probleme[] = __( 'Es ist keine Gläubiger-Identifikationsnummer hinterlegt (Einstellungen).', 'vereinsplugin' );
	}
	if ( ! vp_iban_valid( get_option( 'wl_iban', '' ) ) ) {
		$probleme[] = __( 'Die Vereins-IBAN in den Einstellungen ist leer oder ungültig.', 'vereinsplugin' );
	}
	foreach ( vp_sepa_posten_of( $lauf_id ) as $p ) {
		$wer = $p->kontoinhaber ?: ( '#' . $p->id );
		if ( ! vp_iban_valid( $p->iban ) ) {
			$probleme[] = sprintf( __( '%s: IBAN ungültig.', 'vereinsplugin' ), $wer );
		}
		if ( ! $p->mandatsref ) {
			$probleme[] = sprintf( __( '%s: keine Mandatsreferenz.', 'vereinsplugin' ), $wer );
		}
		if ( ! $p->unterschrift_datum ) {
			$probleme[] = sprintf( __( '%s: kein Datum der Mandatsunterschrift.', 'vereinsplugin' ), $wer );
		}
		if ( (float) $p->betrag <= 0 ) {
			$probleme[] = sprintf( __( '%s: Betrag ist 0.', 'vereinsplugin' ), $wer );
		}
	}
	return $probleme;
}

/* =========================================================================
 * XML (pain.008.001.02)
 * ====================================================================== */

function vp_xml_esc( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
}

/**
 * SEPA-Basislastschrift-Datei erzeugen.
 *
 * Je Sequenztyp (FRST/RCUR/OOFF/FNAL) entsteht ein eigener PmtInf-Block –
 * das verlangen die Banken, weil Vorlauffristen je Sequenz gelten.
 *
 * @return string|WP_Error XML.
 */
function vp_sepa_lauf_xml( $lauf_id ) {
	$lauf = vp_sepa_lauf_get( $lauf_id );
	if ( ! $lauf ) {
		return new WP_Error( 'not_found', __( 'Lauf nicht gefunden.', 'vereinsplugin' ) );
	}
	$posten = vp_sepa_posten_of( $lauf_id );
	if ( ! $posten ) {
		return new WP_Error( 'empty', __( 'Der Lauf enthält keine Posten.', 'vereinsplugin' ) );
	}

	$cred_name = vp_sepa_txt( get_option( 'vp_sepa_glaeubiger', get_bloginfo( 'name' ) ), 70 );
	$cred_id   = strtoupper( preg_replace( '/\s+/', '', (string) get_option( 'vp_sepa_glaeubiger_id', '' ) ) );
	$cred_iban = vp_iban_normalize( get_option( 'wl_iban', '' ) );
	$cred_bic  = strtoupper( preg_replace( '/\s+/', '', (string) get_option( 'vp_sepa_bic', get_option( 'wl_bic', '' ) ) ) );

	if ( '' === $cred_id || ! vp_iban_valid( $cred_iban ) ) {
		return new WP_Error( 'config', __( 'Gläubiger-ID und/oder Vereins-IBAN fehlen in den Einstellungen.', 'vereinsplugin' ) );
	}

	$msg_id = 'VP-' . $lauf_id . '-' . gmdate( 'YmdHis' );
	$now    = gmdate( 'Y-m-d\TH:i:s' );
	$faellig = $lauf->faellig_am;

	// Nach Sequenz gruppieren.
	$gruppen = array();
	foreach ( $posten as $p ) {
		$seq = in_array( $p->sequenz, array( 'FRST', 'RCUR', 'OOFF', 'FNAL' ), true ) ? $p->sequenz : 'RCUR';
		$gruppen[ $seq ][] = $p;
	}

	$n_ges = count( $posten );
	$s_ges = 0.0;
	foreach ( $posten as $p ) {
		$s_ges += (float) $p->betrag;
	}

	$x  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	$x .= '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.008.001.02" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n";
	$x .= "<CstmrDrctDbtInitn>\n";
	$x .= "<GrpHdr>\n";
	$x .= '<MsgId>' . vp_xml_esc( $msg_id ) . "</MsgId>\n";
	$x .= '<CreDtTm>' . $now . "</CreDtTm>\n";
	$x .= '<NbOfTxs>' . $n_ges . "</NbOfTxs>\n";
	$x .= '<CtrlSum>' . number_format( $s_ges, 2, '.', '' ) . "</CtrlSum>\n";
	$x .= '<InitgPty><Nm>' . vp_xml_esc( $cred_name ) . "</Nm></InitgPty>\n";
	$x .= "</GrpHdr>\n";

	$i = 0;
	foreach ( $gruppen as $seq => $liste ) {
		$i++;
		$n = count( $liste );
		$s = 0.0;
		foreach ( $liste as $p ) {
			$s += (float) $p->betrag;
		}
		$x .= "<PmtInf>\n";
		$x .= '<PmtInfId>' . vp_xml_esc( $msg_id . '-' . $seq ) . "</PmtInfId>\n";
		$x .= "<PmtMtd>DD</PmtMtd>\n";
		$x .= "<BtchBookg>true</BtchBookg>\n";
		$x .= '<NbOfTxs>' . $n . "</NbOfTxs>\n";
		$x .= '<CtrlSum>' . number_format( $s, 2, '.', '' ) . "</CtrlSum>\n";
		$x .= "<PmtTpInf><SvcLvl><Cd>SEPA</Cd></SvcLvl><LclInstrm><Cd>CORE</Cd></LclInstrm><SeqTp>" . $seq . "</SeqTp></PmtTpInf>\n";
		$x .= '<ReqdColltnDt>' . vp_xml_esc( $faellig ) . "</ReqdColltnDt>\n";
		$x .= '<Cdtr><Nm>' . vp_xml_esc( $cred_name ) . "</Nm></Cdtr>\n";
		$x .= '<CdtrAcct><Id><IBAN>' . vp_xml_esc( $cred_iban ) . "</IBAN></Id></CdtrAcct>\n";
		$x .= '<CdtrAgt><FinInstnId>' . ( $cred_bic ? '<BIC>' . vp_xml_esc( $cred_bic ) . '</BIC>' : '<Othr><Id>NOTPROVIDED</Id></Othr>' ) . "</FinInstnId></CdtrAgt>\n";
		$x .= "<ChrgBr>SLEV</ChrgBr>\n";
		$x .= '<CdtrSchmeId><Id><PrvtId><Othr><Id>' . vp_xml_esc( $cred_id ) . '</Id><SchmeNm><Prtry>SEPA</Prtry></SchmeNm></Othr></PrvtId></Id></CdtrSchmeId>' . "\n";

		foreach ( $liste as $p ) {
			$x .= "<DrctDbtTxInf>\n";
			$x .= '<PmtId><EndToEndId>' . vp_xml_esc( vp_sepa_txt( $p->e2e ?: ( 'VP-' . $p->id ), 35 ) ) . "</EndToEndId></PmtId>\n";
			$x .= '<InstdAmt Ccy="EUR">' . number_format( (float) $p->betrag, 2, '.', '' ) . "</InstdAmt>\n";
			$x .= '<DrctDbtTx><MndtRltdInf><MndtId>' . vp_xml_esc( vp_sepa_txt( $p->mandatsref, 35 ) ) . '</MndtId><DtOfSgntr>' . vp_xml_esc( $p->unterschrift_datum ) . '</DtOfSgntr><AmdmntInd>false</AmdmntInd></MndtRltdInf></DrctDbtTx>' . "\n";
			$pb = strtoupper( preg_replace( '/\s+/', '', (string) $p->bic ) );
			$x .= '<DbtrAgt><FinInstnId>' . ( $pb ? '<BIC>' . vp_xml_esc( $pb ) . '</BIC>' : '<Othr><Id>NOTPROVIDED</Id></Othr>' ) . "</FinInstnId></DbtrAgt>\n";
			$x .= '<Dbtr><Nm>' . vp_xml_esc( vp_sepa_txt( $p->kontoinhaber, 70 ) ) . "</Nm></Dbtr>\n";
			$x .= '<DbtrAcct><Id><IBAN>' . vp_xml_esc( vp_iban_normalize( $p->iban ) ) . "</IBAN></Id></DbtrAcct>\n";
			$x .= '<RmtInf><Ustrd>' . vp_xml_esc( vp_sepa_txt( $p->zweck, 140 ) ) . "</Ustrd></RmtInf>\n";
			$x .= "</DrctDbtTxInf>\n";
		}
		$x .= "</PmtInf>\n";
	}

	$x .= "</CstmrDrctDbtInitn>\n</Document>\n";

	global $wpdb;
	$wpdb->update( vp_sepa_table_laeufe(), array(
		'msg_id'        => $msg_id,
		'status'        => 'gebucht' === $lauf->status ? 'gebucht' : 'exportiert',
		'exportiert_am' => current_time( 'mysql' ),
	), array( 'id' => (int) $lauf_id ) );

	return $x;
}

/* =========================================================================
 * Buchen
 * ====================================================================== */

/**
 * Lauf ins Journal buchen: je Posten eine Einnahme auf dem Ertragskonto.
 * Die Summe entspricht der Sammelbuchung auf dem Kontoauszug – diese Zeile
 * beim Bank-CSV-Import also überspringen.
 *
 * @return array{gebucht:int, summe:float}|WP_Error
 */
function vp_sepa_lauf_buchen( $lauf_id ) {
	global $wpdb;
	if ( ! function_exists( 'jb_journal_add' ) ) {
		return new WP_Error( 'no_fn', __( 'Buchhaltungs-Modul nicht geladen.', 'vereinsplugin' ) );
	}
	$lauf = vp_sepa_lauf_get( $lauf_id );
	if ( ! $lauf ) {
		return new WP_Error( 'not_found', __( 'Lauf nicht gefunden.', 'vereinsplugin' ) );
	}
	$n = 0;
	$s = 0.0;
	foreach ( vp_sepa_posten_of( $lauf_id ) as $p ) {
		if ( 'gebucht' === $p->status || ! empty( $p->buchung_id ) ) {
			continue;
		}
		$konto = $p->konto ?: ( $lauf->konto ?: '4100' );
		$bid = (int) jb_journal_add( array(
			'buchung_datum'  => $lauf->faellig_am,
			'betrag'         => round( (float) $p->betrag, 2 ),
			'konto'          => $konto,
			'sphaere'        => function_exists( 'jb_konto_sphaere' ) ? jb_konto_sphaere( $konto ) : '',
			'kategorie'      => $konto,
			'quelle'         => $lauf->quelle ?: 'Bank KSK',
			'gegenpartei'    => $p->kontoinhaber,
			'beschreibung'   => $p->zweck,
			'beleg_referenz' => 'SEPA-' . $lauf_id . '-' . $p->id,
		) );
		$wpdb->update( vp_sepa_table_posten(), array( 'status' => 'gebucht', 'buchung_id' => $bid ), array( 'id' => (int) $p->id ) );

		// Mandat: Sequenz auf RCUR drehen, letzte Nutzung merken.
		if ( $p->mandat_id ) {
			$upd = array( 'letzte_nutzung' => $lauf->faellig_am, 'geaendert_am' => current_time( 'mysql' ) );
			if ( 'FRST' === $p->sequenz ) {
				$upd['sequenz'] = 'RCUR';
			}
			$wpdb->update( vp_sepa_table_mandate(), $upd, array( 'id' => (int) $p->mandat_id ) );
		}
		// Zugehörige Rechnung als bezahlt markieren.
		if ( ! empty( $p->rechnung_id ) && function_exists( 'vp_rechnung_mark_bezahlt' ) ) {
			vp_rechnung_mark_bezahlt( (int) $p->rechnung_id, $lauf->faellig_am, $bid );
		}
		$n++;
		$s += (float) $p->betrag;
	}
	$wpdb->update( vp_sepa_table_laeufe(), array( 'status' => 'gebucht', 'gebucht_am' => current_time( 'mysql' ) ), array( 'id' => (int) $lauf_id ) );
	return array( 'gebucht' => $n, 'summe' => round( $s, 2 ) );
}

/** Lauf samt Posten löschen (nur solange nicht gebucht). */
function vp_sepa_lauf_delete( $lauf_id ) {
	global $wpdb;
	$lauf = vp_sepa_lauf_get( $lauf_id );
	if ( ! $lauf ) {
		return new WP_Error( 'not_found', __( 'Lauf nicht gefunden.', 'vereinsplugin' ) );
	}
	if ( 'gebucht' === $lauf->status ) {
		return new WP_Error( 'locked', __( 'Ein gebuchter Lauf kann nicht gelöscht werden.', 'vereinsplugin' ) );
	}
	$wpdb->delete( vp_sepa_table_posten(), array( 'lauf_id' => (int) $lauf_id ) );
	$wpdb->delete( vp_sepa_table_laeufe(), array( 'id' => (int) $lauf_id ) );
	return true;
}

/* =========================================================================
 * Download-Handler (XML)
 * ====================================================================== */

add_action( 'init', 'vp_sepa_maybe_download' );
function vp_sepa_maybe_download() {
	if ( empty( $_GET['vp_sepa_xml'] ) ) {
		return;
	}
	if ( ! vp_sepa_can() ) {
		wp_die( esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) );
	}
	$id = (int) $_GET['vp_sepa_xml'];
	check_admin_referer( 'vp_sepa_xml_' . $id );
	$xml = vp_sepa_lauf_xml( $id );
	if ( is_wp_error( $xml ) ) {
		wp_die( esc_html( $xml->get_error_message() ) );
	}
	nocache_headers();
	header( 'Content-Type: application/xml; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="sepa-lastschrift-' . $id . '-' . gmdate( 'Ymd' ) . '.xml"' );
	echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}

/* =========================================================================
 * Frontend-Sektion „SEPA-Lastschrift"
 * ====================================================================== */

function vp_render_sepa_section() {
	if ( ! vp_sepa_can() ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) . '</div>';
	}
	vp_sepa_maybe_upgrade();

	$msg  = '';
	$err  = '';
	$view = isset( $_GET['vp_sepa'] ) ? sanitize_key( wp_unslash( $_GET['vp_sepa'] ) ) : 'mandate';

	/* ---- POST-Verarbeitung ---- */
	if ( isset( $_POST['vp_sepa_mandat'] ) && check_admin_referer( 'vp_sepa', 'vp_sepa_nonce' ) ) {
		$r = vp_sepa_mandat_save( array(
			'id'                 => (int) ( $_POST['id'] ?? 0 ),
			'user_id'            => (int) ( $_POST['user_id'] ?? 0 ),
			'kontoinhaber'       => wp_unslash( $_POST['kontoinhaber'] ?? '' ),
			'email'              => wp_unslash( $_POST['email'] ?? '' ),
			'iban'               => wp_unslash( $_POST['iban'] ?? '' ),
			'bic'                => wp_unslash( $_POST['bic'] ?? '' ),
			'typ'                => wp_unslash( $_POST['typ'] ?? 'CORE' ),
			'sequenz'            => wp_unslash( $_POST['sequenz'] ?? '' ),
			'unterschrift_datum' => wp_unslash( $_POST['unterschrift_datum'] ?? '' ),
			'status'             => wp_unslash( $_POST['status'] ?? 'aktiv' ),
			'notiz'              => wp_unslash( $_POST['notiz'] ?? '' ),
		) );
		if ( is_wp_error( $r ) ) {
			$err = $r->get_error_message();
		} else {
			$msg = __( 'Mandat gespeichert.', 'vereinsplugin' );
		}
	}
	if ( isset( $_POST['vp_sepa_import'] ) && check_admin_referer( 'vp_sepa', 'vp_sepa_nonce' ) ) {
		$r   = vp_sepa_mandate_import_from_users();
		$msg = sprintf( __( '%1$d Mandate angelegt, %2$d bereits vorhanden.', 'vereinsplugin' ), $r['angelegt'], $r['uebersprungen'] );
		if ( $r['fehler'] ) {
			$err = implode( ' · ', array_slice( $r['fehler'], 0, 5 ) );
		}
	}
	if ( isset( $_POST['vp_sepa_lauf_neu'] ) && check_admin_referer( 'vp_sepa', 'vp_sepa_nonce' ) ) {
		$r = vp_sepa_lauf_create( array(
			'bezeichnung'   => wp_unslash( $_POST['bezeichnung'] ?? '' ),
			'typ'           => wp_unslash( $_POST['lauf_typ'] ?? 'beitrag' ),
			'faellig_am'    => wp_unslash( $_POST['faellig_am'] ?? '' ),
			'intervall'     => wp_unslash( $_POST['intervall'] ?? 'jaehrlich' ),
			'konto'         => wp_unslash( $_POST['konto'] ?? '' ),
			'quelle'        => wp_unslash( $_POST['quelle'] ?? 'Bank KSK' ),
			'zweck_vorlage' => wp_unslash( $_POST['zweck_vorlage'] ?? '' ),
		) );
		if ( is_wp_error( $r ) ) {
			$err = $r->get_error_message();
		} else {
			$msg  = __( 'Lauf angelegt.', 'vereinsplugin' );
			$view = 'lauf';
			$_GET['lauf'] = $r;
		}
	}
	if ( isset( $_POST['vp_sepa_posten'] ) && check_admin_referer( 'vp_sepa', 'vp_sepa_nonce' ) ) {
		global $wpdb;
		$lauf_id = (int) ( $_POST['lauf_id'] ?? 0 );
		foreach ( (array) ( $_POST['betrag'] ?? array() ) as $pid => $betrag ) {
			$pid    = (int) $pid;
			$betrag = round( (float) str_replace( ',', '.', (string) $betrag ), 2 );
			if ( $betrag <= 0 ) {
				$wpdb->delete( vp_sepa_table_posten(), array( 'id' => $pid, 'lauf_id' => $lauf_id ) );
				continue;
			}
			$wpdb->update( vp_sepa_table_posten(), array(
				'betrag' => $betrag,
				'zweck'  => vp_sepa_txt( wp_unslash( $_POST['zweck'][ $pid ] ?? '' ), 140 ),
			), array( 'id' => $pid, 'lauf_id' => $lauf_id ) );
		}
		vp_sepa_lauf_recalc( $lauf_id );
		$msg  = __( 'Posten gespeichert.', 'vereinsplugin' );
		$view = 'lauf';
		$_GET['lauf'] = $lauf_id;
	}
	if ( isset( $_POST['vp_sepa_buchen'] ) && check_admin_referer( 'vp_sepa', 'vp_sepa_nonce' ) ) {
		$r = vp_sepa_lauf_buchen( (int) $_POST['lauf_id'] );
		if ( is_wp_error( $r ) ) {
			$err = $r->get_error_message();
		} else {
			$msg = sprintf( __( '%1$d Posten gebucht (%2$s €).', 'vereinsplugin' ), $r['gebucht'], number_format( $r['summe'], 2, ',', '.' ) );
		}
		$view = 'lauf';
		$_GET['lauf'] = (int) $_POST['lauf_id'];
	}
	if ( isset( $_POST['vp_sepa_lauf_del'] ) && check_admin_referer( 'vp_sepa', 'vp_sepa_nonce' ) ) {
		$r = vp_sepa_lauf_delete( (int) $_POST['lauf_id'] );
		if ( is_wp_error( $r ) ) {
			$err = $r->get_error_message();
		} else {
			$msg  = __( 'Lauf gelöscht.', 'vereinsplugin' );
			$view = 'laeufe';
		}
	}

	$base = get_permalink() ?: remove_query_arg( array( 'vp_sepa', 'lauf', 'id' ) );
	$url  = function ( $args ) use ( $base ) {
		return esc_url( add_query_arg( array_merge( array( 'vp_tab' => 'sepa' ), $args ), $base ) );
	};

	ob_start();
	echo '<h2>' . esc_html__( 'SEPA-Lastschrift', 'vereinsplugin' ) . '</h2>';
	echo '<nav class="vp-subnav">';
	$tabs = array(
		'mandate' => __( 'Mandate', 'vereinsplugin' ),
		'laeufe'  => __( 'Einzugsläufe', 'vereinsplugin' ),
		'neu'     => __( 'Neuer Lauf', 'vereinsplugin' ),
	);
	foreach ( $tabs as $k => $label ) {
		printf( '<a class="%s" href="%s">%s</a>', ( $k === $view || ( 'lauf' === $view && 'laeufe' === $k ) ) ? 'is-active' : '', $url( array( 'vp_sepa' => $k ) ), esc_html( $label ) );
	}
	echo '</nav>';

	if ( $msg ) {
		echo '<div class="vp-note">' . esc_html( $msg ) . '</div>';
	}
	if ( $err ) {
		echo '<div class="vp-note vp-note-error">' . esc_html( $err ) . '</div>';
	}

	switch ( $view ) {
		case 'laeufe':
			echo vp_sepa_view_laeufe( $url ); // phpcs:ignore
			break;
		case 'lauf':
			echo vp_sepa_view_lauf( (int) ( $_GET['lauf'] ?? 0 ), $url ); // phpcs:ignore
			break;
		case 'neu':
			echo vp_sepa_view_neu(); // phpcs:ignore
			break;
		default:
			echo vp_sepa_view_mandate( $url ); // phpcs:ignore
	}
	return ob_get_clean();
}

function vp_sepa_view_mandate( $url ) {
	global $wpdb;
	$edit = isset( $_GET['id'] ) ? vp_sepa_mandat_get( (int) $_GET['id'] ) : null;
	$rows = $wpdb->get_results( 'SELECT * FROM ' . vp_sepa_table_mandate() . ' ORDER BY status, kontoinhaber' );

	ob_start();

	echo '<form method="post" class="vp-card">';
	wp_nonce_field( 'vp_sepa', 'vp_sepa_nonce' );
	echo '<h3>' . ( $edit ? esc_html__( 'Mandat bearbeiten', 'vereinsplugin' ) : esc_html__( 'Mandat anlegen', 'vereinsplugin' ) ) . '</h3>';
	echo '<input type="hidden" name="id" value="' . ( $edit ? (int) $edit->id : 0 ) . '">';
	echo '<div class="vp-form-grid">';

	echo '<label>' . esc_html__( 'Mitglied', 'vereinsplugin' ) . '<select name="user_id"><option value="0">' . esc_html__( '— externe Person —', 'vereinsplugin' ) . '</option>';
	foreach ( get_users( array( 'role__in' => array( VP_MEMBER_ROLE, 'editor', 'administrator' ), 'orderby' => 'display_name' ) ) as $u ) {
		printf( '<option value="%d"%s>%s</option>', $u->ID, selected( $edit ? (int) $edit->user_id : 0, $u->ID, false ), esc_html( $u->display_name ) );
	}
	echo '</select></label>';

	printf( '<label>%s<input type="text" name="kontoinhaber" value="%s" required></label>', esc_html__( 'Kontoinhaber:in', 'vereinsplugin' ), esc_attr( $edit->kontoinhaber ?? '' ) );
	printf( '<label>%s<input type="text" name="iban" value="%s" required></label>', esc_html__( 'IBAN', 'vereinsplugin' ), esc_attr( $edit->iban ?? '' ) );
	printf( '<label>%s<input type="text" name="bic" value="%s" placeholder="optional"></label>', esc_html__( 'BIC', 'vereinsplugin' ), esc_attr( $edit->bic ?? '' ) );
	printf( '<label>%s<input type="email" name="email" value="%s"></label>', esc_html__( 'E-Mail', 'vereinsplugin' ), esc_attr( $edit->email ?? '' ) );
	printf( '<label>%s<input type="date" name="unterschrift_datum" value="%s" required></label>', esc_html__( 'Unterschrift am', 'vereinsplugin' ), esc_attr( $edit->unterschrift_datum ?? '' ) );

	echo '<label>' . esc_html__( 'Art', 'vereinsplugin' ) . '<select name="typ">';
	foreach ( array( 'CORE' => __( 'Basislastschrift (CORE)', 'vereinsplugin' ), 'B2B' => __( 'Firmenlastschrift (B2B)', 'vereinsplugin' ) ) as $k => $v ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $edit->typ ?? 'CORE', $k, false ), esc_html( $v ) );
	}
	echo '</select></label>';

	echo '<label>' . esc_html__( 'Nächste Sequenz', 'vereinsplugin' ) . '<select name="sequenz">';
	foreach ( array( 'FRST' => __( 'Erstlastschrift (FRST)', 'vereinsplugin' ), 'RCUR' => __( 'Folgelastschrift (RCUR)', 'vereinsplugin' ), 'OOFF' => __( 'Einmallastschrift (OOFF)', 'vereinsplugin' ), 'FNAL' => __( 'Letzte Lastschrift (FNAL)', 'vereinsplugin' ) ) as $k => $v ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $edit->sequenz ?? 'FRST', $k, false ), esc_html( $v ) );
	}
	echo '</select></label>';

	echo '<label>' . esc_html__( 'Status', 'vereinsplugin' ) . '<select name="status">';
	foreach ( array( 'aktiv' => __( 'aktiv', 'vereinsplugin' ), 'widerrufen' => __( 'widerrufen', 'vereinsplugin' ), 'abgelaufen' => __( 'abgelaufen', 'vereinsplugin' ) ) as $k => $v ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $edit->status ?? 'aktiv', $k, false ), esc_html( $v ) );
	}
	echo '</select></label>';
	echo '</div>';
	printf( '<p><label>%s<br><textarea name="notiz" rows="2" style="width:100%%">%s</textarea></label></p>', esc_html__( 'Notiz', 'vereinsplugin' ), esc_textarea( $edit->notiz ?? '' ) );
	echo '<p><button class="vp-btn vp-btn-primary" name="vp_sepa_mandat" value="1">' . esc_html__( 'Speichern', 'vereinsplugin' ) . '</button> ';
	echo '<button class="vp-btn" name="vp_sepa_import" value="1">' . esc_html__( 'Mandate aus Mitgliederprofilen übernehmen', 'vereinsplugin' ) . '</button></p>';
	echo '</form>';

	echo '<h3>' . esc_html__( 'Mandate', 'vereinsplugin' ) . ' <span class="vp-muted">(' . count( $rows ) . ')</span></h3>';
	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr>'
		. '<th>' . esc_html__( 'Referenz', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Kontoinhaber:in', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'IBAN', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Unterschrift', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Sequenz', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Letzter Einzug', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Status', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $m ) {
		$warn = vp_sepa_mandat_verfallen( $m ) ? ' ⚠' : '';
		printf(
			'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s%s</td></tr>',
			$url( array( 'vp_sepa' => 'mandate', 'id' => (int) $m->id ) ),
			esc_html( $m->mandatsref ),
			esc_html( $m->kontoinhaber ),
			esc_html( function_exists( 'vp_iban_mask' ) ? vp_iban_mask( $m->iban ) : $m->iban ),
			esc_html( $m->unterschrift_datum ),
			esc_html( $m->sequenz ),
			esc_html( $m->letzte_nutzung ?: '—' ),
			esc_html( $m->status ),
			esc_html( $warn )
		);
	}
	echo '</tbody></table></div>';
	echo '<p class="vp-muted">' . esc_html__( '⚠ = seit 36 Monaten kein Einzug – das Mandat ist damit erloschen und muss neu erteilt werden.', 'vereinsplugin' ) . '</p>';
	return ob_get_clean();
}

function vp_sepa_view_neu() {
	$konten = function_exists( 'jb_konten_all' ) ? jb_konten_all() : array();
	ob_start();
	echo '<form method="post" class="vp-card">';
	wp_nonce_field( 'vp_sepa', 'vp_sepa_nonce' );
	echo '<h3>' . esc_html__( 'Neuen Einzugslauf anlegen', 'vereinsplugin' ) . '</h3>';
	echo '<div class="vp-form-grid">';
	printf( '<label>%s<input type="text" name="bezeichnung" placeholder="%s"></label>', esc_html__( 'Bezeichnung', 'vereinsplugin' ), esc_attr__( 'Beitragseinzug 2026', 'vereinsplugin' ) );
	printf( '<label>%s<input type="date" name="faellig_am" value="%s" required></label>', esc_html__( 'Fällig am', 'vereinsplugin' ), esc_attr( gmdate( 'Y-m-d', strtotime( '+7 days' ) ) ) );

	echo '<label>' . esc_html__( 'Quelle der Posten', 'vereinsplugin' ) . '<select name="lauf_typ">';
	echo '<option value="beitrag">' . esc_html__( 'Mitgliedsbeiträge', 'vereinsplugin' ) . '</option>';
	echo '<option value="rechnung">' . esc_html__( 'Offene Rechnungen (Zahlart Lastschrift)', 'vereinsplugin' ) . '</option>';
	echo '<option value="frei">' . esc_html__( 'Leer (Posten von Hand)', 'vereinsplugin' ) . '</option>';
	echo '</select></label>';

	echo '<label>' . esc_html__( 'Einzugsintervall (nur Beiträge)', 'vereinsplugin' ) . '<select name="intervall">';
	foreach ( array( 'jaehrlich' => __( 'jährlich', 'vereinsplugin' ), 'halbjaehrlich' => __( 'halbjährlich', 'vereinsplugin' ), 'vierteljaehrlich' => __( 'vierteljährlich', 'vereinsplugin' ), 'monatlich' => __( 'monatlich', 'vereinsplugin' ) ) as $k => $v ) {
		printf( '<option value="%s">%s</option>', esc_attr( $k ), esc_html( $v ) );
	}
	echo '</select></label>';

	echo '<label>' . esc_html__( 'Ertragskonto', 'vereinsplugin' ) . '<select name="konto">';
	foreach ( $konten as $k ) {
		if ( 'einnahme' !== $k->typ ) {
			continue;
		}
		printf( '<option value="%s"%s>%s %s</option>', esc_attr( $k->nummer ), selected( $k->nummer, '4100', false ), esc_html( $k->nummer ), esc_html( $k->bezeichnung ) );
	}
	echo '</select></label>';

	printf( '<label>%s<input type="text" name="quelle" value="Bank KSK"></label>', esc_html__( 'Geld-Topf (quelle)', 'vereinsplugin' ) );
	printf(
		'<label>%s<input type="text" name="zweck_vorlage" value="%s"></label>',
		esc_html__( 'Verwendungszweck-Vorlage', 'vereinsplugin' ),
		esc_attr( get_option( 'vp_sepa_zweck_vorlage', 'Mitgliedsbeitrag {jahr} - {name}' ) )
	);
	echo '</div>';
	echo '<p class="vp-muted">' . esc_html__( 'Platzhalter: {jahr} {monat} {name} {mandatsref} {betrag}. Der Beitrag aus dem Profil wird auf das gewählte Intervall umgerechnet.', 'vereinsplugin' ) . '</p>';
	echo '<p><button class="vp-btn vp-btn-primary" name="vp_sepa_lauf_neu" value="1">' . esc_html__( 'Lauf anlegen', 'vereinsplugin' ) . '</button></p>';
	echo '</form>';
	return ob_get_clean();
}

function vp_sepa_view_laeufe( $url ) {
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT * FROM ' . vp_sepa_table_laeufe() . ' ORDER BY faellig_am DESC, id DESC LIMIT 100' );
	ob_start();
	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr>'
		. '<th>' . esc_html__( 'Fällig', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Bezeichnung', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Posten', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Summe', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Status', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $l ) {
		printf(
			'<tr><td>%s</td><td><a href="%s">%s</a></td><td>%d</td><td>%s €</td><td>%s</td></tr>',
			esc_html( $l->faellig_am ),
			$url( array( 'vp_sepa' => 'lauf', 'lauf' => (int) $l->id ) ),
			esc_html( $l->bezeichnung ),
			(int) $l->anzahl,
			esc_html( number_format( (float) $l->summe, 2, ',', '.' ) ),
			esc_html( $l->status )
		);
	}
	if ( ! $rows ) {
		echo '<tr><td colspan="5">' . esc_html__( 'Noch keine Läufe.', 'vereinsplugin' ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
	return ob_get_clean();
}

function vp_sepa_view_lauf( $lauf_id, $url ) {
	$lauf = vp_sepa_lauf_get( $lauf_id );
	if ( ! $lauf ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Lauf nicht gefunden.', 'vereinsplugin' ) . '</div>';
	}
	$posten   = vp_sepa_posten_of( $lauf_id );
	$probleme = vp_sepa_lauf_pruefen( $lauf_id );

	ob_start();
	printf(
		'<h3>%s <span class="vp-muted">%s · %s · %s €</span></h3>',
		esc_html( $lauf->bezeichnung ),
		esc_html( $lauf->faellig_am ),
		esc_html( $lauf->status ),
		esc_html( number_format( (float) $lauf->summe, 2, ',', '.' ) )
	);

	if ( $probleme ) {
		echo '<div class="vp-note vp-note-warn"><strong>' . esc_html__( 'Vor dem Export klären:', 'vereinsplugin' ) . '</strong><ul>';
		foreach ( array_slice( $probleme, 0, 20 ) as $p ) {
			echo '<li>' . esc_html( $p ) . '</li>';
		}
		echo '</ul></div>';
	}

	echo '<p>';
	if ( ! $probleme ) {
		printf(
			'<a class="vp-btn vp-btn-primary" href="%s">%s</a> ',
			esc_url( wp_nonce_url( add_query_arg( 'vp_sepa_xml', (int) $lauf_id, home_url( '/' ) ), 'vp_sepa_xml_' . (int) $lauf_id ) ),
			esc_html__( 'SEPA-XML herunterladen', 'vereinsplugin' )
		);
	}
	echo '</p>';

	echo '<form method="post">';
	wp_nonce_field( 'vp_sepa', 'vp_sepa_nonce' );
	echo '<input type="hidden" name="lauf_id" value="' . (int) $lauf_id . '">';
	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr>'
		. '<th>' . esc_html__( 'Kontoinhaber:in', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'IBAN', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Mandat', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Seq.', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Betrag', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Verwendungszweck', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Status', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
	foreach ( $posten as $p ) {
		$pid = (int) $p->id;
		echo '<tr>';
		echo '<td>' . esc_html( $p->kontoinhaber ) . '</td>';
		echo '<td>' . esc_html( function_exists( 'vp_iban_mask' ) ? vp_iban_mask( $p->iban ) : $p->iban ) . '</td>';
		echo '<td>' . esc_html( $p->mandatsref ) . '<br><span class="vp-muted">' . esc_html( $p->unterschrift_datum ) . '</span></td>';
		echo '<td>' . esc_html( $p->sequenz ) . '</td>';
		echo '<td><input type="text" inputmode="decimal" size="8" name="betrag[' . $pid . ']" value="' . esc_attr( number_format( (float) $p->betrag, 2, ',', '' ) ) . '"></td>';
		echo '<td><input type="text" name="zweck[' . $pid . ']" value="' . esc_attr( $p->zweck ) . '" style="width:100%"></td>';
		echo '<td>' . esc_html( $p->status ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table></div>';
	echo '<p><button class="vp-btn" name="vp_sepa_posten" value="1">' . esc_html__( 'Posten speichern', 'vereinsplugin' ) . '</button> ';
	if ( 'gebucht' !== $lauf->status ) {
		echo '<button class="vp-btn vp-btn-primary" name="vp_sepa_buchen" value="1" onclick="return confirm(\'' . esc_js( __( 'Alle Posten als eingezogen ins Journal buchen?', 'vereinsplugin' ) ) . '\')">' . esc_html__( 'Als eingezogen buchen', 'vereinsplugin' ) . '</button> ';
		echo '<button class="vp-btn" name="vp_sepa_lauf_del" value="1" onclick="return confirm(\'' . esc_js( __( 'Lauf wirklich löschen?', 'vereinsplugin' ) ) . '\')">' . esc_html__( 'Lauf löschen', 'vereinsplugin' ) . '</button>';
	}
	echo '</p>';
	echo '<p class="vp-muted">' . esc_html__( 'Betrag 0 löscht den Posten. Beim Buchen entsteht je Posten eine Einnahme im Journal – die Sammelbuchung auf dem Kontoauszug beim Bank-Import deshalb überspringen.', 'vereinsplugin' ) . '</p>';
	echo '</form>';
	return ob_get_clean();
}
