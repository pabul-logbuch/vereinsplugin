<?php
/**
 * Kern: Spendenverwaltung + Zuwendungsbestätigungen (amtliches Muster).
 *
 *   vp_spender      – Stammdaten der zuwendenden Person/Firma (Anschrift!)
 *   vp_spenden      – einzelne Zuwendungen, i. d. R. verknüpft mit jb_buchungen
 *   vp_zuwendungen  – ausgestellte Bestätigungen (Einzel oder Sammel je Jahr)
 *
 * Die Bestätigungen folgen den amtlich vorgeschriebenen Mustern des BMF
 * (§ 50 EStDV) für Geldzuwendungen bzw. der Sammelbestätigung. Text und
 * Rechtsgrundlage (Freistellungsbescheid oder Feststellung nach § 60a AO)
 * kommen aus den Einstellungen – der Verein trägt sie einmal ein.
 *
 * Wichtig: Ausgestellte Bestätigungen werden mit einem HTML-Abzug gespeichert
 * (Spalte `html`), damit spätere Änderungen an Einstellungen den ausgegebenen
 * Beleg nicht rückwirkend verändern.
 */

defined( 'ABSPATH' ) || exit;

define( 'VP_SPENDEN_DB_VERSION', '1' );

function vp_spender_table()    { global $wpdb; return $wpdb->prefix . 'vp_spender'; }
function vp_spenden_table()    { global $wpdb; return $wpdb->prefix . 'vp_spenden'; }
function vp_zuwendung_table()  { global $wpdb; return $wpdb->prefix . 'vp_zuwendungen'; }

add_action( 'plugins_loaded', 'vp_spenden_maybe_upgrade', 6 );
function vp_spenden_maybe_upgrade() {
	if ( get_option( 'vp_spenden_db_version' ) === VP_SPENDEN_DB_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$collate = $wpdb->get_charset_collate();

	dbDelta( 'CREATE TABLE ' . vp_spender_table() . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT UNSIGNED DEFAULT NULL,
		name VARCHAR(190) NOT NULL DEFAULT '',
		strasse VARCHAR(190) NOT NULL DEFAULT '',
		plz VARCHAR(20) NOT NULL DEFAULT '',
		ort VARCHAR(120) NOT NULL DEFAULT '',
		land VARCHAR(60) NOT NULL DEFAULT '',
		email VARCHAR(190) NOT NULL DEFAULT '',
		notiz TEXT NULL,
		erstellt_am DATETIME NULL,
		PRIMARY KEY  (id),
		KEY name (name),
		KEY user_id (user_id)
	) {$collate};" );

	dbDelta( 'CREATE TABLE ' . vp_spenden_table() . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		spender_id BIGINT UNSIGNED DEFAULT NULL,
		buchung_id BIGINT UNSIGNED DEFAULT NULL,
		bescheinigung_id BIGINT UNSIGNED DEFAULT NULL,
		datum DATE DEFAULT NULL,
		betrag DECIMAL(10,2) NOT NULL DEFAULT 0,
		art VARCHAR(16) NOT NULL DEFAULT 'geld',
		verzicht TINYINT NOT NULL DEFAULT 0,
		konto VARCHAR(10) NOT NULL DEFAULT '',
		beschreibung VARCHAR(255) NOT NULL DEFAULT '',
		erstellt_am DATETIME NULL,
		PRIMARY KEY  (id),
		KEY spender_id (spender_id),
		KEY datum (datum),
		KEY buchung_id (buchung_id)
	) {$collate};" );

	dbDelta( 'CREATE TABLE ' . vp_zuwendung_table() . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		nummer VARCHAR(30) NOT NULL DEFAULT '',
		spender_id BIGINT UNSIGNED DEFAULT NULL,
		typ VARCHAR(10) NOT NULL DEFAULT 'einzel',
		jahr INT NOT NULL DEFAULT 0,
		von_datum DATE DEFAULT NULL,
		bis_datum DATE DEFAULT NULL,
		summe DECIMAL(10,2) NOT NULL DEFAULT 0,
		ausgestellt_am DATETIME NULL,
		ausgestellt_von BIGINT UNSIGNED DEFAULT NULL,
		storniert TINYINT NOT NULL DEFAULT 0,
		html LONGTEXT NULL,
		PRIMARY KEY  (id),
		KEY nummer (nummer),
		KEY spender_id (spender_id)
	) {$collate};" );

	update_option( 'vp_spenden_db_version', VP_SPENDEN_DB_VERSION );
}

function vp_spenden_can() {
	return current_user_can( 'jb_view_journal' ) || current_user_can( 'manage_options' );
}

/** Konten, die als Zuwendung gelten (Einstellung, Standard 4200/4210). */
function vp_spenden_konten() {
	$raw = (string) get_option( 'vp_spende_konten', '4200,4210' );
	$out = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	return $out ?: array( '4200', '4210' );
}

/* =========================================================================
 * Spender / Zuwendungen
 * ====================================================================== */

function vp_spender_get( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vp_spender_table() . ' WHERE id = %d', (int) $id ) );
}

function vp_spender_save( array $d ) {
	global $wpdb;
	$id  = (int) ( $d['id'] ?? 0 );
	$row = array(
		'user_id' => ! empty( $d['user_id'] ) ? (int) $d['user_id'] : null,
		'name'    => sanitize_text_field( (string) ( $d['name'] ?? '' ) ),
		'strasse' => sanitize_text_field( (string) ( $d['strasse'] ?? '' ) ),
		'plz'     => sanitize_text_field( (string) ( $d['plz'] ?? '' ) ),
		'ort'     => sanitize_text_field( (string) ( $d['ort'] ?? '' ) ),
		'land'    => sanitize_text_field( (string) ( $d['land'] ?? '' ) ),
		'email'   => sanitize_email( (string) ( $d['email'] ?? '' ) ),
		'notiz'   => sanitize_textarea_field( (string) ( $d['notiz'] ?? '' ) ),
	);
	if ( '' === $row['name'] ) {
		return new WP_Error( 'bad_req', __( 'Name ist Pflicht.', 'vereinsplugin' ) );
	}
	if ( $id ) {
		$wpdb->update( vp_spender_table(), $row, array( 'id' => $id ) );
		return $id;
	}
	$row['erstellt_am'] = current_time( 'mysql' );
	$wpdb->insert( vp_spender_table(), $row );
	return (int) $wpdb->insert_id;
}

/** Spender:in per Name (oder WP-Benutzer) finden, sonst anlegen. */
function vp_spender_find_or_create( $name, $user_id = 0 ) {
	global $wpdb;
	$name = trim( (string) $name );
	if ( $user_id ) {
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vp_spender_table() . ' WHERE user_id = %d LIMIT 1', (int) $user_id ) );
		if ( $row ) {
			return (int) $row->id;
		}
		$u = get_userdata( $user_id );
		if ( $u ) {
			return (int) vp_spender_save( array(
				'user_id' => $user_id,
				'name'    => $name ?: $u->display_name,
				'strasse' => (string) get_user_meta( $user_id, 'vp_strasse', true ),
				'plz'     => (string) get_user_meta( $user_id, 'vp_plz', true ),
				'ort'     => (string) get_user_meta( $user_id, 'vp_ort', true ),
				'land'    => (string) get_user_meta( $user_id, 'vp_land', true ),
				'email'   => $u->user_email,
			) );
		}
	}
	if ( '' === $name ) {
		return 0;
	}
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vp_spender_table() . ' WHERE name = %s LIMIT 1', $name ) );
	if ( $row ) {
		return (int) $row->id;
	}
	$r = vp_spender_save( array( 'name' => $name ) );
	return is_wp_error( $r ) ? 0 : (int) $r;
}

function vp_spende_add( array $d ) {
	global $wpdb;
	$wpdb->insert( vp_spenden_table(), array(
		'spender_id'   => ! empty( $d['spender_id'] ) ? (int) $d['spender_id'] : null,
		'buchung_id'   => ! empty( $d['buchung_id'] ) ? (int) $d['buchung_id'] : null,
		'datum'        => vp_sepa_norm_date( $d['datum'] ?? '' ) ?: current_time( 'Y-m-d' ),
		'betrag'       => round( (float) str_replace( ',', '.', (string) ( $d['betrag'] ?? 0 ) ), 2 ),
		'art'          => in_array( ( $d['art'] ?? 'geld' ), array( 'geld', 'beitrag', 'sach', 'aufwand' ), true ) ? $d['art'] : 'geld',
		'verzicht'     => empty( $d['verzicht'] ) ? 0 : 1,
		'konto'        => sanitize_text_field( (string) ( $d['konto'] ?? '' ) ),
		'beschreibung' => sanitize_text_field( (string) ( $d['beschreibung'] ?? '' ) ),
		'erstellt_am'  => current_time( 'mysql' ),
	) );
	return (int) $wpdb->insert_id;
}

/**
 * Zuwendungen aus dem Journal übernehmen.
 * Alle Einnahmen des Jahres auf den Spendenkonten, die noch nicht als Spende
 * erfasst sind, werden angelegt; die Spender:in wird über `gegenpartei`
 * gesucht bzw. neu angelegt (Anschrift muss dann nachgetragen werden).
 *
 * @return array{neu:int, ohne_anschrift:int}
 */
function vp_spenden_import_from_journal( $jahr ) {
	global $wpdb;
	if ( ! function_exists( 'jb_table_journal' ) ) {
		return array( 'neu' => 0, 'ohne_anschrift' => 0 );
	}
	$konten = vp_spenden_konten();
	$in     = implode( ',', array_fill( 0, count( $konten ), '%s' ) );
	$sql    = $wpdb->prepare(
		'SELECT * FROM ' . jb_table_journal() . " WHERE betrag > 0 AND YEAR(buchung_datum) = %d AND konto IN ($in) ORDER BY buchung_datum",
		array_merge( array( (int) $jahr ), $konten )
	);
	$rows = $wpdb->get_results( $sql );

	$neu  = 0;
	$ohne = 0;
	foreach ( $rows as $b ) {
		$exists = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . vp_spenden_table() . ' WHERE buchung_id = %d', (int) $b->id ) );
		if ( $exists ) {
			continue;
		}
		$name = trim( (string) $b->gegenpartei );
		$sid  = $name ? vp_spender_find_or_create( $name ) : 0;
		vp_spende_add( array(
			'spender_id'   => $sid,
			'buchung_id'   => (int) $b->id,
			'datum'        => $b->buchung_datum,
			'betrag'       => $b->betrag,
			'art'          => 'geld',
			'konto'        => $b->konto,
			'beschreibung' => $b->beschreibung,
		) );
		$neu++;
		if ( $sid ) {
			$sp = vp_spender_get( $sid );
			if ( $sp && '' === trim( $sp->strasse . $sp->ort ) ) {
				$ohne++;
			}
		} else {
			$ohne++;
		}
	}
	return array( 'neu' => $neu, 'ohne_anschrift' => $ohne );
}

/* =========================================================================
 * Bestätigungen
 * ====================================================================== */

function vp_zuwendung_get( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vp_zuwendung_table() . ' WHERE id = %d', (int) $id ) );
}

function vp_zuwendung_next_nummer( $jahr ) {
	global $wpdb;
	$like = $wpdb->esc_like( 'ZB-' . $jahr . '-' ) . '%';
	$max  = (int) $wpdb->get_var( $wpdb->prepare(
		'SELECT MAX(CAST(SUBSTRING_INDEX(nummer, %s, -1) AS UNSIGNED)) FROM ' . vp_zuwendung_table() . ' WHERE nummer LIKE %s',
		'-', $like
	) );
	return 'ZB-' . $jahr . '-' . str_pad( (string) ( $max + 1 ), 3, '0', STR_PAD_LEFT );
}

/**
 * Bestätigung ausstellen.
 *
 * @param int    $spender_id
 * @param array  $spende_ids  Leer = alle noch nicht bescheinigten des Jahres.
 * @param string $typ         'einzel' | 'sammel'
 * @param int    $jahr
 * @return int|WP_Error Bescheinigungs-ID.
 */
function vp_zuwendung_erstellen( $spender_id, array $spende_ids = array(), $typ = 'sammel', $jahr = 0 ) {
	global $wpdb;
	$spender = vp_spender_get( $spender_id );
	if ( ! $spender ) {
		return new WP_Error( 'not_found', __( 'Spender:in nicht gefunden.', 'vereinsplugin' ) );
	}
	if ( '' === trim( $spender->strasse . $spender->plz . $spender->ort ) ) {
		return new WP_Error( 'no_address', __( 'Für die Bestätigung fehlt die Anschrift der Spender:in – das amtliche Muster verlangt sie.', 'vereinsplugin' ) );
	}
	$jahr = (int) ( $jahr ?: current_time( 'Y' ) );

	if ( $spende_ids ) {
		$ids  = array_map( 'intval', $spende_ids );
		$in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$args = array_merge( array( (int) $spender_id ), $ids );
		$spenden = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . vp_spenden_table() . " WHERE spender_id = %d AND bescheinigung_id IS NULL AND id IN ($in) ORDER BY datum",
			$args
		) );
	} else {
		$spenden = $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . vp_spenden_table() . ' WHERE spender_id = %d AND bescheinigung_id IS NULL AND YEAR(datum) = %d ORDER BY datum',
			(int) $spender_id, $jahr
		) );
	}
	if ( ! $spenden ) {
		return new WP_Error( 'empty', __( 'Keine offenen Zuwendungen für diese Auswahl.', 'vereinsplugin' ) );
	}
	if ( 'einzel' === $typ && count( $spenden ) > 1 ) {
		$typ = 'sammel';
	}

	$summe = 0.0;
	$von   = null;
	$bis   = null;
	foreach ( $spenden as $s ) {
		$summe += (float) $s->betrag;
		$von = ( null === $von || $s->datum < $von ) ? $s->datum : $von;
		$bis = ( null === $bis || $s->datum > $bis ) ? $s->datum : $bis;
	}

	$wpdb->insert( vp_zuwendung_table(), array(
		'nummer'          => vp_zuwendung_next_nummer( $jahr ),
		'spender_id'      => (int) $spender_id,
		'typ'             => 'einzel' === $typ ? 'einzel' : 'sammel',
		'jahr'            => $jahr,
		'von_datum'       => $von,
		'bis_datum'       => $bis,
		'summe'           => round( $summe, 2 ),
		'ausgestellt_am'  => current_time( 'mysql' ),
		'ausgestellt_von' => get_current_user_id(),
	) );
	$zid = (int) $wpdb->insert_id;
	if ( ! $zid ) {
		return new WP_Error( 'db', __( 'Bestätigung konnte nicht angelegt werden.', 'vereinsplugin' ) );
	}
	foreach ( $spenden as $s ) {
		$wpdb->update( vp_spenden_table(), array( 'bescheinigung_id' => $zid ), array( 'id' => (int) $s->id ) );
	}

	// Beleg-Abzug einfrieren.
	$html = vp_zuwendung_html( $zid, true );
	$wpdb->update( vp_zuwendung_table(), array( 'html' => $html ), array( 'id' => $zid ) );
	return $zid;
}

/** Bestätigung stornieren: Zuwendungen werden wieder frei. */
function vp_zuwendung_storno( $id ) {
	global $wpdb;
	$wpdb->update( vp_zuwendung_table(), array( 'storniert' => 1 ), array( 'id' => (int) $id ) );
	$wpdb->update( vp_spenden_table(), array( 'bescheinigung_id' => null ), array( 'bescheinigung_id' => (int) $id ) );
	return true;
}

function vp_zuwendung_spenden( $id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		'SELECT * FROM ' . vp_spenden_table() . ' WHERE bescheinigung_id = %d ORDER BY datum', (int) $id
	) );
}

/* =========================================================================
 * Betrag in Worten (das amtliche Muster verlangt „in Buchstaben")
 * ====================================================================== */

function vp_zahl_in_worten( $n ) {
	$n = (int) $n;
	if ( $n < 0 ) {
		return 'minus ' . vp_zahl_in_worten( -$n );
	}
	$einer = array( 'null', 'ein', 'zwei', 'drei', 'vier', 'fünf', 'sechs', 'sieben', 'acht', 'neun',
		'zehn', 'elf', 'zwölf', 'dreizehn', 'vierzehn', 'fünfzehn', 'sechzehn', 'siebzehn', 'achtzehn', 'neunzehn' );
	$zehner = array( 3 => 'dreißig', 6 => 'sechzig', 7 => 'siebzig' );

	if ( $n < 20 ) {
		return 1 === $n ? 'eins' : $einer[ $n ];
	}
	if ( $n < 100 ) {
		$z = (int) ( $n / 10 );
		$e = $n % 10;
		$zw = isset( $zehner[ $z ] ) ? $zehner[ $z ] : $einer[ $z ] . 'zig';
		return $e ? $einer[ $e ] . 'und' . $zw : $zw;
	}
	if ( $n < 1000 ) {
		$h = (int) ( $n / 100 );
		$r = $n % 100;
		return $einer[ $h ] . 'hundert' . ( $r ? vp_zahl_in_worten( $r ) : '' );
	}
	if ( $n < 1000000 ) {
		$t = (int) ( $n / 1000 );
		$r = $n % 1000;
		return ( 1 === $t ? 'ein' : vp_zahl_in_worten( $t ) ) . 'tausend' . ( $r ? vp_zahl_in_worten( $r ) : '' );
	}
	$m = (int) ( $n / 1000000 );
	$r = $n % 1000000;
	return ( 1 === $m ? 'eine Million' : vp_zahl_in_worten( $m ) . ' Millionen' ) . ( $r ? ' ' . vp_zahl_in_worten( $r ) : '' );
}

/** „1.234,50 €" → „eintausendzweihundertvierunddreißig 50/100". */
function vp_betrag_in_worten( $betrag ) {
	$betrag = round( (float) $betrag, 2 );
	$ganz   = (int) floor( $betrag );
	$cent   = (int) round( ( $betrag - $ganz ) * 100 );
	return vp_zahl_in_worten( $ganz ) . ' ' . str_pad( (string) $cent, 2, '0', STR_PAD_LEFT ) . '/100';
}

/* =========================================================================
 * Amtliches Muster (§ 50 EStDV)
 * ====================================================================== */

/** Rechtsgrundlagen-Absatz aus den Einstellungen. */
function vp_zuwendung_rechtsgrundlage() {
	$org   = function_exists( 'vp_org_daten' ) ? vp_org_daten() : array( 'finanzamt' => '', 'steuernr' => '' );
	$zweck = (string) get_option( 'vp_spende_zweck', __( 'der Jugendhilfe', 'vereinsplugin' ) );
	$fa    = $org['finanzamt'] ?: '__________';
	$stnr  = $org['steuernr'] ?: '__________';
	$datum = (string) get_option( 'vp_spende_bescheid_datum', '' );
	$datum = $datum ? date_i18n( 'd.m.Y', strtotime( $datum ) ) : '__________';
	$vz    = (string) get_option( 'vp_spende_veranlagungszeitraum', '' );

	if ( '60a' === get_option( 'vp_spende_bescheid_typ', 'freistellung' ) ) {
		return sprintf(
			/* translators: 1: tax office, 2: tax number, 3: date, 4: purpose */
			__( 'Die Einhaltung der satzungsmäßigen Voraussetzungen nach den §§ 51, 59, 60 und 61 AO wurde vom Finanzamt %1$s, StNr. %2$s, mit Bescheid nach § 60a AO vom %3$s gesondert festgestellt. Wir fördern nach unserer Satzung die Förderung %4$s.', 'vereinsplugin' ),
			$fa, $stnr, $datum, $zweck
		);
	}
	return sprintf(
		/* translators: 1: purpose, 2: tax office, 3: tax number, 4: date, 5: assessment period */
		__( 'Wir sind wegen Förderung %1$s nach dem Freistellungsbescheid bzw. nach der Anlage zum Körperschaftsteuerbescheid des Finanzamts %2$s, StNr. %3$s, vom %4$s für den letzten Veranlagungszeitraum %5$s nach § 5 Abs. 1 Nr. 9 des Körperschaftsteuergesetzes von der Körperschaftsteuer und nach § 3 Nr. 6 des Gewerbesteuergesetzes von der Gewerbesteuer befreit.', 'vereinsplugin' ),
		$zweck, $fa, $stnr, $datum, $vz ?: '__________'
	);
}

/** Haftungs- und Gültigkeitshinweis (Pflichttext des Musters). */
function vp_zuwendung_hinweis() {
	return __( 'Wer vorsätzlich oder grob fahrlässig eine unrichtige Zuwendungsbestätigung erstellt oder veranlasst, dass Zuwendungen nicht zu den in der Zuwendungsbestätigung angegebenen steuerbegünstigten Zwecken verwendet werden, haftet für die entgangene Steuer (§ 10b Abs. 4 EStG, § 9 Abs. 3 KStG, § 9 Nr. 5 GewStG). Diese Bestätigung wird nicht als Nachweis für die steuerliche Berücksichtigung der Zuwendung anerkannt, wenn das Datum des Freistellungsbescheides länger als 5 Jahre bzw. das Datum der Feststellung der Einhaltung der satzungsmäßigen Voraussetzungen nach § 60a Abs. 1 AO länger als 3 Jahre seit Ausstellung des Bescheides zurückliegt (§ 63 Abs. 5 AO).', 'vereinsplugin' );
}

function vp_zuwendung_art_label( $art ) {
	$map = array(
		'geld'    => __( 'Geldzuwendung', 'vereinsplugin' ),
		'beitrag' => __( 'Mitgliedsbeitrag', 'vereinsplugin' ),
		'sach'    => __( 'Sachzuwendung', 'vereinsplugin' ),
		'aufwand' => __( 'Aufwandsersatz', 'vereinsplugin' ),
	);
	return $map[ $art ] ?? $art;
}

/**
 * Bestätigung als HTML rendern.
 *
 * @param int  $id
 * @param bool $frisch true = neu aus den aktuellen Daten (beim Ausstellen),
 *                     false = gespeicherter Abzug, falls vorhanden.
 */
function vp_zuwendung_html( $id, $frisch = false ) {
	$z = vp_zuwendung_get( $id );
	if ( ! $z ) {
		return '';
	}
	if ( ! $frisch && $z->html ) {
		return $z->html;
	}
	$sp      = vp_spender_get( $z->spender_id );
	$spenden = vp_zuwendung_spenden( $id );
	$org     = function_exists( 'vp_org_daten' ) ? vp_org_daten() : array();
	$sammel  = 'sammel' === $z->typ;
	$eur     = function ( $v ) {
		return number_format( (float) $v, 2, ',', '.' );
	};
	$hat_verzicht = false;
	foreach ( $spenden as $s ) {
		if ( $s->verzicht ) {
			$hat_verzicht = true;
		}
	}

	ob_start();
	?>
	<div class="vp-doc">
		<div class="vp-doc-absender">
			<?php echo esc_html( $org['name'] ?? '' ); ?><?php echo ! empty( $org['anschrift'] ) ? ' · ' . esc_html( preg_replace( '/\s*\n\s*/', ', ', trim( $org['anschrift'] ) ) ) : ''; ?>
		</div>

		<h1>
			<?php
			echo esc_html(
				$sammel
					? __( 'Sammelbestätigung über Geldzuwendungen/Mitgliedsbeiträge', 'vereinsplugin' )
					: __( 'Bestätigung über Geldzuwendungen/Mitgliedsbeitrag', 'vereinsplugin' )
			);
			?>
		</h1>
		<p class="vp-doc-hinweis">
			<?php esc_html_e( 'im Sinne des § 10b des Einkommensteuergesetzes an eine der in § 5 Abs. 1 Nr. 9 des Körperschaftsteuergesetzes bezeichneten Körperschaften, Personenvereinigungen oder Vermögensmassen.', 'vereinsplugin' ); ?>
		</p>

		<h2><?php esc_html_e( 'Aussteller (Empfänger der Zuwendung)', 'vereinsplugin' ); ?></h2>
		<p>
			<strong><?php echo esc_html( $org['name'] ?? '' ); ?></strong><br>
			<?php echo nl2br( esc_html( (string) ( $org['anschrift'] ?? '' ) ) ); ?>
		</p>

		<h2><?php esc_html_e( 'Name und Anschrift der zuwendenden Person', 'vereinsplugin' ); ?></h2>
		<p>
			<strong><?php echo esc_html( $sp->name ?? '' ); ?></strong><br>
			<?php echo esc_html( $sp->strasse ?? '' ); ?><br>
			<?php echo esc_html( trim( ( $sp->plz ?? '' ) . ' ' . ( $sp->ort ?? '' ) ) ); ?>
			<?php echo ! empty( $sp->land ) ? '<br>' . esc_html( $sp->land ) : ''; ?>
		</p>

		<div class="vp-doc-box">
			<?php if ( $sammel ) : ?>
				<div><?php esc_html_e( 'Gesamtbetrag der Zuwendungen', 'vereinsplugin' ); ?>:
					<strong><?php echo esc_html( $eur( $z->summe ) ); ?> €</strong></div>
				<div><?php esc_html_e( 'in Buchstaben', 'vereinsplugin' ); ?>:
					<?php echo esc_html( vp_betrag_in_worten( $z->summe ) ); ?></div>
				<div><?php esc_html_e( 'Zeitraum', 'vereinsplugin' ); ?>:
					<?php
					printf(
						/* translators: 1: from date, 2: to date */
						esc_html__( 'vom %1$s bis %2$s', 'vereinsplugin' ),
						esc_html( date_i18n( 'd.m.Y', strtotime( $z->von_datum ) ) ),
						esc_html( date_i18n( 'd.m.Y', strtotime( $z->bis_datum ) ) )
					);
					?>
				</div>
			<?php else : ?>
				<?php $s = $spenden ? $spenden[0] : null; ?>
				<div><?php esc_html_e( 'Betrag der Zuwendung – in Ziffern', 'vereinsplugin' ); ?>:
					<strong><?php echo esc_html( $eur( $z->summe ) ); ?> €</strong></div>
				<div><?php esc_html_e( 'in Buchstaben', 'vereinsplugin' ); ?>:
					<?php echo esc_html( vp_betrag_in_worten( $z->summe ) ); ?></div>
				<div><?php esc_html_e( 'Tag der Zuwendung', 'vereinsplugin' ); ?>:
					<?php echo esc_html( $s ? date_i18n( 'd.m.Y', strtotime( $s->datum ) ) : '' ); ?></div>
				<div><?php esc_html_e( 'Art der Zuwendung', 'vereinsplugin' ); ?>:
					<?php echo esc_html( $s ? vp_zuwendung_art_label( $s->art ) : '' ); ?></div>
				<div><?php esc_html_e( 'Es handelt sich um den Verzicht auf Erstattung von Aufwendungen', 'vereinsplugin' ); ?>:
					<strong><?php echo $hat_verzicht ? esc_html__( 'Ja', 'vereinsplugin' ) : esc_html__( 'Nein', 'vereinsplugin' ); ?></strong></div>
			<?php endif; ?>
		</div>

		<?php if ( $sammel ) : ?>
			<p><?php esc_html_e( 'Es wird bestätigt, dass über die in der Gesamtsumme enthaltenen Zuwendungen keine weiteren Bestätigungen, weder formelle Zuwendungsbestätigungen noch Beitragsquittungen oder Ähnliches, ausgestellt wurden und werden. Ob es sich um den Verzicht auf Erstattung von Aufwendungen handelt, ist der nachstehenden Anlage zu entnehmen.', 'vereinsplugin' ); ?></p>
		<?php endif; ?>

		<p><?php echo esc_html( vp_zuwendung_rechtsgrundlage() ); ?></p>

		<p>
			<?php
			printf(
				/* translators: %s: purpose */
				esc_html__( 'Es wird bestätigt, dass die Zuwendung nur zur Förderung %s verwendet wird.', 'vereinsplugin' ),
				esc_html( (string) get_option( 'vp_spende_zweck', __( 'der Jugendhilfe', 'vereinsplugin' ) ) )
			);
			?>
			<?php if ( ! $sammel && $hat_verzicht ) : ?>
				<?php esc_html_e( 'Der Zuwendung liegt ein Verzicht auf die Erstattung von Aufwendungen zugrunde; die Aufwendungen wurden vom Verein durch Vereinbarung oder Satzung eingeräumt und der Anspruch war nicht unter der Bedingung des Verzichts eingeräumt.', 'vereinsplugin' ); ?>
			<?php endif; ?>
		</p>

		<div class="vp-doc-sig">
			<div>
				<?php
				echo esc_html( trim( ( $org['ort'] ?? '' ) . ( ! empty( $org['ort'] ) ? ', ' : '' ) . date_i18n( 'd.m.Y', strtotime( (string) $z->ausgestellt_am ) ) ) );
				?>
			</div>
			<div class="line">
				<?php echo esc_html( $org['vertreter'] ?? '' ); ?><br>
				<?php esc_html_e( 'Unterschrift der/des Zeichnungsberechtigten', 'vereinsplugin' ); ?>
			</div>
		</div>

		<p class="vp-doc-hinweis"><strong><?php esc_html_e( 'Hinweis', 'vereinsplugin' ); ?>:</strong>
			<?php echo esc_html( vp_zuwendung_hinweis() ); ?></p>

		<?php if ( $sammel && $spenden ) : ?>
			<h2><?php esc_html_e( 'Anlage zur Sammelbestätigung', 'vereinsplugin' ); ?></h2>
			<table class="vp-doc-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Datum der Zuwendung', 'vereinsplugin' ); ?></th>
						<th><?php esc_html_e( 'Art der Zuwendung', 'vereinsplugin' ); ?></th>
						<th><?php esc_html_e( 'Verzicht auf Aufwendungsersatz', 'vereinsplugin' ); ?></th>
						<th class="r"><?php esc_html_e( 'Betrag', 'vereinsplugin' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $spenden as $s ) : ?>
					<tr>
						<td><?php echo esc_html( date_i18n( 'd.m.Y', strtotime( $s->datum ) ) ); ?></td>
						<td><?php echo esc_html( vp_zuwendung_art_label( $s->art ) ); ?></td>
						<td><?php echo $s->verzicht ? esc_html__( 'Ja', 'vereinsplugin' ) : esc_html__( 'Nein', 'vereinsplugin' ); ?></td>
						<td class="r"><?php echo esc_html( $eur( $s->betrag ) ); ?> €</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr class="sum">
						<td colspan="3" class="r"><strong><?php esc_html_e( 'Gesamtbetrag', 'vereinsplugin' ); ?></strong></td>
						<td class="r"><strong><?php echo esc_html( $eur( $z->summe ) ); ?> €</strong></td>
					</tr>
				</tfoot>
			</table>
		<?php endif; ?>

		<div class="vp-doc-fuss">
			<?php echo esc_html( $z->nummer ); ?><?php echo $z->storniert ? ' · ' . esc_html__( 'STORNIERT', 'vereinsplugin' ) : ''; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

add_action( 'init', 'vp_zuwendung_maybe_print' );
function vp_zuwendung_maybe_print() {
	if ( empty( $_GET['vp_zuwendung_print'] ) ) {
		return;
	}
	if ( ! vp_spenden_can() ) {
		wp_die( esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) );
	}
	$id = (int) $_GET['vp_zuwendung_print'];
	check_admin_referer( 'vp_zuwendung_print_' . $id );
	$html = vp_zuwendung_html( $id );
	if ( '' === $html ) {
		wp_die( esc_html__( 'Bestätigung nicht gefunden.', 'vereinsplugin' ) );
	}
	vp_doc_output( __( 'Zuwendungsbestätigung', 'vereinsplugin' ), $html );
}

/** Bestätigung per E-Mail verschicken. */
function vp_zuwendung_mail( $id ) {
	$z  = vp_zuwendung_get( $id );
	$sp = $z ? vp_spender_get( $z->spender_id ) : null;
	if ( ! $z || ! $sp ) {
		return new WP_Error( 'not_found', __( 'Bestätigung nicht gefunden.', 'vereinsplugin' ) );
	}
	if ( ! is_email( $sp->email ) ) {
		return new WP_Error( 'no_mail', __( 'Für diese Spender:in ist keine E-Mail-Adresse hinterlegt.', 'vereinsplugin' ) );
	}
	$org  = function_exists( 'vp_org_daten' ) ? vp_org_daten() : array( 'name' => get_bloginfo( 'name' ) );
	$html = '<html><head><meta charset="utf-8"><style>' . vp_doc_css() . '</style></head><body>' . vp_zuwendung_html( $id ) . '</body></html>';
	$ok   = wp_mail(
		$sp->email,
		sprintf( __( 'Zuwendungsbestätigung %s', 'vereinsplugin' ), $z->nummer ),
		$html,
		array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . $org['name'] . ' <' . get_option( 'admin_email' ) . '>' )
	);
	return $ok ? true : new WP_Error( 'mail', __( 'Der Versand ist fehlgeschlagen.', 'vereinsplugin' ) );
}

/* =========================================================================
 * Frontend-Sektion „Spenden & Bescheinigungen"
 * ====================================================================== */

function vp_render_spenden_section() {
	if ( ! vp_spenden_can() ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) . '</div>';
	}
	vp_spenden_maybe_upgrade();

	global $wpdb;
	$msg  = '';
	$err  = '';
	$view = isset( $_GET['vp_zb'] ) ? sanitize_key( wp_unslash( $_GET['vp_zb'] ) ) : 'zuwendungen';
	$jahr = isset( $_GET['jahr'] ) ? (int) $_GET['jahr'] : (int) current_time( 'Y' );

	if ( isset( $_POST['vp_sp_import'] ) && check_admin_referer( 'vp_sp', 'vp_sp_nonce' ) ) {
		$jahr = (int) $_POST['jahr'];
		$r    = vp_spenden_import_from_journal( $jahr );
		$msg  = sprintf(
			/* translators: 1: count, 2: count */
			__( '%1$d Zuwendungen übernommen. Bei %2$d fehlt noch die Anschrift.', 'vereinsplugin' ),
			$r['neu'],
			$r['ohne_anschrift']
		);
	}
	if ( isset( $_POST['vp_sp_spende'] ) && check_admin_referer( 'vp_sp', 'vp_sp_nonce' ) ) {
		$sid = (int) ( $_POST['spender_id'] ?? 0 );
		if ( ! $sid && ! empty( $_POST['neuer_name'] ) ) {
			$sid = vp_spender_find_or_create( wp_unslash( $_POST['neuer_name'] ) );
		}
		if ( ! $sid ) {
			$err = __( 'Bitte eine Spender:in wählen oder anlegen.', 'vereinsplugin' );
		} else {
			vp_spende_add( array(
				'spender_id'   => $sid,
				'datum'        => wp_unslash( $_POST['datum'] ?? '' ),
				'betrag'       => wp_unslash( $_POST['betrag'] ?? '0' ),
				'art'          => wp_unslash( $_POST['art'] ?? 'geld' ),
				'verzicht'     => ! empty( $_POST['verzicht'] ),
				'beschreibung' => wp_unslash( $_POST['beschreibung'] ?? '' ),
			) );
			$msg = __( 'Zuwendung erfasst.', 'vereinsplugin' );
		}
	}
	if ( isset( $_POST['vp_sp_spender'] ) && check_admin_referer( 'vp_sp', 'vp_sp_nonce' ) ) {
		$r = vp_spender_save( array(
			'id'      => (int) ( $_POST['id'] ?? 0 ),
			'user_id' => (int) ( $_POST['user_id'] ?? 0 ),
			'name'    => wp_unslash( $_POST['name'] ?? '' ),
			'strasse' => wp_unslash( $_POST['strasse'] ?? '' ),
			'plz'     => wp_unslash( $_POST['plz'] ?? '' ),
			'ort'     => wp_unslash( $_POST['ort'] ?? '' ),
			'land'    => wp_unslash( $_POST['land'] ?? '' ),
			'email'   => wp_unslash( $_POST['email'] ?? '' ),
			'notiz'   => wp_unslash( $_POST['notiz'] ?? '' ),
		) );
		if ( is_wp_error( $r ) ) {
			$err = $r->get_error_message();
		} else {
			$msg = __( 'Spender:in gespeichert.', 'vereinsplugin' );
		}
		$view = 'spender';
	}
	if ( isset( $_POST['vp_sp_bestaetigen'] ) && check_admin_referer( 'vp_sp', 'vp_sp_nonce' ) ) {
		$jahr = (int) $_POST['jahr'];
		$ids  = array_map( 'intval', (array) ( $_POST['sel'] ?? array() ) );
		$sid  = (int) ( $_POST['spender_id'] ?? 0 );
		$r    = vp_zuwendung_erstellen( $sid, $ids, ( $_POST['vp_sp_bestaetigen'] === 'einzel' ? 'einzel' : 'sammel' ), $jahr );
		if ( is_wp_error( $r ) ) {
			$err = $r->get_error_message();
		} else {
			$z   = vp_zuwendung_get( $r );
			$msg = sprintf( __( 'Bestätigung %s ausgestellt.', 'vereinsplugin' ), $z->nummer );
			$view = 'bestaetigungen';
		}
	}
	if ( isset( $_POST['vp_sp_alle'] ) && check_admin_referer( 'vp_sp', 'vp_sp_nonce' ) ) {
		$jahr = (int) $_POST['jahr'];
		$sids = $wpdb->get_col( $wpdb->prepare(
			'SELECT DISTINCT spender_id FROM ' . vp_spenden_table() . ' WHERE bescheinigung_id IS NULL AND spender_id IS NOT NULL AND YEAR(datum) = %d',
			$jahr
		) );
		$n = 0;
		$fehler = array();
		foreach ( $sids as $sid ) {
			$r = vp_zuwendung_erstellen( (int) $sid, array(), 'sammel', $jahr );
			if ( is_wp_error( $r ) ) {
				$sp = vp_spender_get( $sid );
				$fehler[] = ( $sp ? $sp->name : '#' . $sid ) . ': ' . $r->get_error_message();
			} else {
				$n++;
			}
		}
		$msg  = sprintf( __( '%d Sammelbestätigungen ausgestellt.', 'vereinsplugin' ), $n );
		$err  = $fehler ? implode( ' · ', array_slice( $fehler, 0, 5 ) ) : '';
		$view = 'bestaetigungen';
	}
	if ( isset( $_POST['vp_sp_storno'] ) && check_admin_referer( 'vp_sp', 'vp_sp_nonce' ) ) {
		vp_zuwendung_storno( (int) $_POST['id'] );
		$msg  = __( 'Bestätigung storniert – die Zuwendungen sind wieder frei.', 'vereinsplugin' );
		$view = 'bestaetigungen';
	}
	if ( isset( $_POST['vp_sp_mail'] ) && check_admin_referer( 'vp_sp', 'vp_sp_nonce' ) ) {
		$r = vp_zuwendung_mail( (int) $_POST['id'] );
		if ( is_wp_error( $r ) ) {
			$err = $r->get_error_message();
		} else {
			$msg = __( 'Bestätigung verschickt.', 'vereinsplugin' );
		}
		$view = 'bestaetigungen';
	}

	$base = get_permalink() ?: remove_query_arg( array( 'vp_zb', 'jahr', 'id' ) );
	$url  = function ( $args ) use ( $base ) {
		return esc_url( add_query_arg( array_merge( array( 'vp_tab' => 'spenden' ), $args ), $base ) );
	};

	ob_start();
	echo '<h2>' . esc_html__( 'Spenden & Zuwendungsbestätigungen', 'vereinsplugin' ) . '</h2>';
	echo '<nav class="vp-subnav">';
	foreach ( array(
		'zuwendungen'     => __( 'Zuwendungen', 'vereinsplugin' ),
		'spender'         => __( 'Spender:innen', 'vereinsplugin' ),
		'bestaetigungen'  => __( 'Bestätigungen', 'vereinsplugin' ),
	) as $k => $label ) {
		printf( '<a class="%s" href="%s">%s</a>', $k === $view ? 'is-active' : '', $url( array( 'vp_zb' => $k, 'jahr' => $jahr ) ), esc_html( $label ) );
	}
	echo '</nav>';
	if ( $msg ) {
		echo '<div class="vp-note">' . esc_html( $msg ) . '</div>';
	}
	if ( $err ) {
		echo '<div class="vp-note vp-note-error">' . esc_html( $err ) . '</div>';
	}

	switch ( $view ) {
		case 'spender':
			echo vp_sp_view_spender( $url ); // phpcs:ignore
			break;
		case 'bestaetigungen':
			echo vp_sp_view_bestaetigungen( $url, $jahr ); // phpcs:ignore
			break;
		default:
			echo vp_sp_view_zuwendungen( $url, $jahr ); // phpcs:ignore
	}
	return ob_get_clean();
}

function vp_sp_jahr_switcher( $url, $jahr, $view ) {
	$out = '<p class="vp-muted">' . esc_html__( 'Jahr', 'vereinsplugin' ) . ': ';
	$now = (int) current_time( 'Y' );
	for ( $y = $now; $y >= $now - 5; $y-- ) {
		$out .= sprintf(
			'<a class="vp-btn%s" href="%s">%d</a> ',
			$y === (int) $jahr ? ' vp-btn-primary' : '',
			$url( array( 'vp_zb' => $view, 'jahr' => $y ) ),
			$y
		);
	}
	return $out . '</p>';
}

function vp_sp_view_zuwendungen( $url, $jahr ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT s.*, p.name AS spender_name, p.strasse, p.ort, z.nummer AS zb_nummer
		 FROM ' . vp_spenden_table() . ' s
		 LEFT JOIN ' . vp_spender_table() . ' p ON p.id = s.spender_id
		 LEFT JOIN ' . vp_zuwendung_table() . ' z ON z.id = s.bescheinigung_id
		 WHERE YEAR(s.datum) = %d ORDER BY s.datum DESC, s.id DESC',
		(int) $jahr
	) );
	$spender = $wpdb->get_results( 'SELECT * FROM ' . vp_spender_table() . ' ORDER BY name' );
	$summe   = 0.0;
	$offen   = 0.0;
	foreach ( $rows as $r ) {
		$summe += (float) $r->betrag;
		if ( ! $r->bescheinigung_id ) {
			$offen += (float) $r->betrag;
		}
	}

	ob_start();
	echo vp_sp_jahr_switcher( $url, $jahr, 'zuwendungen' ); // phpcs:ignore

	echo '<form method="post" class="vp-card">';
	wp_nonce_field( 'vp_sp', 'vp_sp_nonce' );
	echo '<input type="hidden" name="jahr" value="' . (int) $jahr . '">';
	echo '<p>' . esc_html( sprintf(
		/* translators: 1: year, 2: total, 3: open total */
		__( '%1$d: %2$s € Zuwendungen, davon %3$s € noch ohne Bestätigung.', 'vereinsplugin' ),
		$jahr,
		number_format( $summe, 2, ',', '.' ),
		number_format( $offen, 2, ',', '.' )
	) ) . '</p>';
	echo '<p><button class="vp-btn" name="vp_sp_import" value="1">' . esc_html__( 'Zuwendungen aus dem Journal übernehmen', 'vereinsplugin' ) . '</button> ';
	echo '<button class="vp-btn vp-btn-primary" name="vp_sp_alle" value="1" onclick="return confirm(\'' . esc_js( __( 'Für alle Spender:innen mit offenen Zuwendungen eine Sammelbestätigung ausstellen?', 'vereinsplugin' ) ) . '\')">'
		. esc_html__( 'Sammelbestätigungen für alle ausstellen', 'vereinsplugin' ) . '</button></p>';
	echo '<p class="vp-muted">' . esc_html( sprintf(
		/* translators: %s: account numbers */
		__( 'Als Zuwendung gelten Einnahmen auf den Konten %s (Einstellungen).', 'vereinsplugin' ),
		implode( ', ', vp_spenden_konten() )
	) ) . '</p>';
	echo '</form>';

	/* Einzelne Zuwendung von Hand erfassen. */
	echo '<details class="vp-card"><summary>' . esc_html__( 'Zuwendung von Hand erfassen', 'vereinsplugin' ) . '</summary>';
	echo '<form method="post">';
	wp_nonce_field( 'vp_sp', 'vp_sp_nonce' );
	echo '<div class="vp-form-grid">';
	echo '<label>' . esc_html__( 'Spender:in', 'vereinsplugin' ) . '<select name="spender_id"><option value="0">' . esc_html__( '— neu —', 'vereinsplugin' ) . '</option>';
	foreach ( $spender as $s ) {
		printf( '<option value="%d">%s</option>', (int) $s->id, esc_html( $s->name ) );
	}
	echo '</select></label>';
	printf( '<label>%s<input type="text" name="neuer_name"></label>', esc_html__( '… oder neuer Name', 'vereinsplugin' ) );
	printf( '<label>%s<input type="date" name="datum" value="%s"></label>', esc_html__( 'Datum', 'vereinsplugin' ), esc_attr( current_time( 'Y-m-d' ) ) );
	printf( '<label>%s<input type="text" inputmode="decimal" name="betrag"></label>', esc_html__( 'Betrag (€)', 'vereinsplugin' ) );
	echo '<label>' . esc_html__( 'Art', 'vereinsplugin' ) . '<select name="art">';
	foreach ( array( 'geld', 'beitrag', 'sach', 'aufwand' ) as $a ) {
		printf( '<option value="%s">%s</option>', esc_attr( $a ), esc_html( vp_zuwendung_art_label( $a ) ) );
	}
	echo '</select></label>';
	printf( '<label>%s<input type="text" name="beschreibung"></label>', esc_html__( 'Beschreibung', 'vereinsplugin' ) );
	echo '</div>';
	printf( '<p><label><input type="checkbox" name="verzicht" value="1"> %s</label></p>', esc_html__( 'Verzicht auf Erstattung von Aufwendungen', 'vereinsplugin' ) );
	echo '<p><button class="vp-btn" name="vp_sp_spende" value="1">' . esc_html__( 'Erfassen', 'vereinsplugin' ) . '</button></p>';
	echo '</form></details>';

	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr>'
		. '<th>' . esc_html__( 'Datum', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Spender:in', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Anschrift', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Art', 'vereinsplugin' ) . '</th>'
		. '<th class="r">' . esc_html__( 'Betrag', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Bestätigung', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$adr = trim( (string) $r->strasse . ' ' . (string) $r->ort );
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class="r">%s €</td><td>%s</td></tr>',
			esc_html( date_i18n( 'd.m.Y', strtotime( $r->datum ) ) ),
			esc_html( $r->spender_name ?: '—' ),
			$adr ? esc_html( $adr ) : '<span class="vp-note-error">' . esc_html__( 'fehlt', 'vereinsplugin' ) . '</span>',
			esc_html( vp_zuwendung_art_label( $r->art ) ),
			esc_html( number_format( (float) $r->betrag, 2, ',', '.' ) ),
			esc_html( $r->zb_nummer ?: '—' )
		);
	}
	if ( ! $rows ) {
		echo '<tr><td colspan="6">' . esc_html__( 'Keine Zuwendungen in diesem Jahr.', 'vereinsplugin' ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
	return ob_get_clean();
}

function vp_sp_view_spender( $url ) {
	global $wpdb;
	$edit = isset( $_GET['id'] ) ? vp_spender_get( (int) $_GET['id'] ) : null;
	$jahr = (int) current_time( 'Y' );
	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT p.*, COALESCE(SUM(s.betrag),0) AS summe, COUNT(s.id) AS anzahl,
			COALESCE(SUM(CASE WHEN s.bescheinigung_id IS NULL AND YEAR(s.datum) = %d THEN s.betrag ELSE 0 END),0) AS offen
		 FROM ' . vp_spender_table() . ' p LEFT JOIN ' . vp_spenden_table() . ' s ON s.spender_id = p.id
		 GROUP BY p.id ORDER BY p.name',
		$jahr
	) );

	ob_start();
	echo '<form method="post" class="vp-card">';
	wp_nonce_field( 'vp_sp', 'vp_sp_nonce' );
	echo '<h3>' . ( $edit ? esc_html__( 'Spender:in bearbeiten', 'vereinsplugin' ) : esc_html__( 'Spender:in anlegen', 'vereinsplugin' ) ) . '</h3>';
	echo '<input type="hidden" name="id" value="' . ( $edit ? (int) $edit->id : 0 ) . '">';
	echo '<div class="vp-form-grid">';
	printf( '<label>%s<input type="text" name="name" value="%s" required></label>', esc_html__( 'Name', 'vereinsplugin' ), esc_attr( $edit->name ?? '' ) );
	printf( '<label>%s<input type="text" name="strasse" value="%s"></label>', esc_html__( 'Straße, Nr.', 'vereinsplugin' ), esc_attr( $edit->strasse ?? '' ) );
	printf( '<label>%s<input type="text" name="plz" value="%s"></label>', esc_html__( 'PLZ', 'vereinsplugin' ), esc_attr( $edit->plz ?? '' ) );
	printf( '<label>%s<input type="text" name="ort" value="%s"></label>', esc_html__( 'Ort', 'vereinsplugin' ), esc_attr( $edit->ort ?? '' ) );
	printf( '<label>%s<input type="text" name="land" value="%s"></label>', esc_html__( 'Land', 'vereinsplugin' ), esc_attr( $edit->land ?? '' ) );
	printf( '<label>%s<input type="email" name="email" value="%s"></label>', esc_html__( 'E-Mail', 'vereinsplugin' ), esc_attr( $edit->email ?? '' ) );
	echo '<label>' . esc_html__( 'Mitglied', 'vereinsplugin' ) . '<select name="user_id"><option value="0">—</option>';
	foreach ( get_users( array( 'role__in' => array( VP_MEMBER_ROLE, 'editor', 'administrator' ), 'orderby' => 'display_name' ) ) as $u ) {
		printf( '<option value="%d"%s>%s</option>', $u->ID, selected( $edit ? (int) $edit->user_id : 0, $u->ID, false ), esc_html( $u->display_name ) );
	}
	echo '</select></label>';
	echo '</div>';
	echo '<p><button class="vp-btn vp-btn-primary" name="vp_sp_spender" value="1">' . esc_html__( 'Speichern', 'vereinsplugin' ) . '</button></p>';
	echo '</form>';

	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr>'
		. '<th>' . esc_html__( 'Name', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Anschrift', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'E-Mail', 'vereinsplugin' ) . '</th>'
		. '<th class="r">' . esc_html__( 'Zuwendungen', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Ohne Bestätigung', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $s ) {
		$adr = trim( $s->strasse . ', ' . trim( $s->plz . ' ' . $s->ort ), ', ' );
		echo '<tr>';
		printf(
			'<td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td class="r">%s € (%d)</td>',
			$url( array( 'vp_zb' => 'spender', 'id' => (int) $s->id ) ),
			esc_html( $s->name ),
			$adr ? esc_html( $adr ) : '<span class="vp-note-error">' . esc_html__( 'fehlt', 'vereinsplugin' ) . '</span>',
			esc_html( $s->email ),
			esc_html( number_format( (float) $s->summe, 2, ',', '.' ) ),
			(int) $s->anzahl
		);
		echo '<td>';
		if ( (float) $s->offen > 0 ) {
			echo '<form method="post" style="display:inline">';
			wp_nonce_field( 'vp_sp', 'vp_sp_nonce' );
			echo '<input type="hidden" name="spender_id" value="' . (int) $s->id . '">';
			echo '<input type="hidden" name="jahr" value="' . (int) $jahr . '">';
			printf(
				'<button class="vp-btn" name="vp_sp_bestaetigen" value="sammel">%s</button>',
				esc_html( sprintf(
					/* translators: 1: amount, 2: year */
					__( '%1$s € (%2$d) bestätigen', 'vereinsplugin' ),
					number_format( (float) $s->offen, 2, ',', '.' ),
					$jahr
				) )
			);
			echo '</form>';
		} else {
			echo '<span class="vp-muted">—</span>';
		}
		echo '</td></tr>';
	}
	echo '</tbody></table></div>';
	return ob_get_clean();
}

function vp_sp_view_bestaetigungen( $url, $jahr ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		'SELECT z.*, p.name AS spender_name FROM ' . vp_zuwendung_table() . ' z
		 LEFT JOIN ' . vp_spender_table() . ' p ON p.id = z.spender_id
		 WHERE z.jahr = %d ORDER BY z.nummer DESC',
		(int) $jahr
	) );

	ob_start();
	echo vp_sp_jahr_switcher( $url, $jahr, 'bestaetigungen' ); // phpcs:ignore
	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr>'
		. '<th>' . esc_html__( 'Nummer', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Spender:in', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Art', 'vereinsplugin' ) . '</th>'
		. '<th class="r">' . esc_html__( 'Summe', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Ausgestellt', 'vereinsplugin' ) . '</th>'
		. '<th></th></tr></thead><tbody>';
	foreach ( $rows as $z ) {
		echo '<tr>';
		echo '<td>' . esc_html( $z->nummer ) . ( $z->storniert ? ' <span class="vp-muted">' . esc_html__( '(storniert)', 'vereinsplugin' ) . '</span>' : '' ) . '</td>';
		echo '<td>' . esc_html( $z->spender_name ) . '</td>';
		echo '<td>' . esc_html( 'sammel' === $z->typ ? __( 'Sammelbestätigung', 'vereinsplugin' ) : __( 'Einzelbestätigung', 'vereinsplugin' ) ) . '</td>';
		echo '<td class="r">' . esc_html( number_format( (float) $z->summe, 2, ',', '.' ) ) . ' €</td>';
		echo '<td>' . esc_html( $z->ausgestellt_am ) . '</td>';
		echo '<td>';
		printf(
			'<a class="vp-btn" target="_blank" rel="noopener" href="%s">%s</a> ',
			esc_url( wp_nonce_url( add_query_arg( 'vp_zuwendung_print', (int) $z->id, home_url( '/' ) ), 'vp_zuwendung_print_' . (int) $z->id ) ),
			esc_html__( 'Drucken / PDF', 'vereinsplugin' )
		);
		if ( ! $z->storniert ) {
			echo '<form method="post" style="display:inline">';
			wp_nonce_field( 'vp_sp', 'vp_sp_nonce' );
			echo '<input type="hidden" name="id" value="' . (int) $z->id . '">';
			echo '<button class="vp-btn" name="vp_sp_mail" value="1">' . esc_html__( 'Mailen', 'vereinsplugin' ) . '</button> ';
			echo '<button class="vp-btn" name="vp_sp_storno" value="1" onclick="return confirm(\'' . esc_js( __( 'Bestätigung stornieren?', 'vereinsplugin' ) ) . '\')">' . esc_html__( 'Stornieren', 'vereinsplugin' ) . '</button>';
			echo '</form>';
		}
		echo '</td></tr>';
	}
	if ( ! $rows ) {
		echo '<tr><td colspan="6">' . esc_html__( 'Für dieses Jahr wurden noch keine Bestätigungen ausgestellt.', 'vereinsplugin' ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
	return ob_get_clean();
}
