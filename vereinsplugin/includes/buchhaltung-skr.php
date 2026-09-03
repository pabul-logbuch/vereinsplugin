<?php
/**
 * Kern-Erweiterung: SKR-49-Buchhaltung (EÜR mit Konten & Sphären).
 *
 *  - Kontenplan (Tabelle jb_konten), Startvorschlag nach SKR 49 (Vereine),
 *    editierbar + CSV-Import.
 *  - Stichwort→Konto-Regeln (Tabelle jb_konto_regeln) für den Bank-Import.
 *  - jb_buchungen bekommt konto / sphaere / gegenpartei.
 *  - Frontend-„Buchhaltung“ im Mitgliederbereich: Journal, Bank-Import (CSV),
 *    Auswertung nach Konto & Sphäre, Kontenplan.
 *
 * Läuft nur, wenn das Buchhaltungs-Modul geladen ist.
 */

defined( 'ABSPATH' ) || exit;

define( 'VP_SKR_DB_VERSION', '4' );

/* =========================================================================
 * Schema
 * ====================================================================== */

function jb_table_konten()  { global $wpdb; return $wpdb->prefix . 'jb_konten'; }
function jb_table_regeln()  { global $wpdb; return $wpdb->prefix . 'jb_konto_regeln'; }

add_action( 'plugins_loaded', 'vp_skr_maybe_upgrade', 7 );
function vp_skr_maybe_upgrade() {
	if ( ! function_exists( 'jb_table_journal' ) ) {
		return;
	}
	if ( get_option( 'vp_skr_db_version' ) === VP_SKR_DB_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$collate = $wpdb->get_charset_collate();

	dbDelta( "CREATE TABLE " . jb_table_konten() . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		nummer VARCHAR(10) NOT NULL DEFAULT '',
		bezeichnung VARCHAR(200) NOT NULL DEFAULT '',
		typ VARCHAR(12) NOT NULL DEFAULT 'ausgabe',
		sphaere VARCHAR(16) NOT NULL DEFAULT 'ideell',
		aktiv TINYINT NOT NULL DEFAULT 1,
		sort INT NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY nummer (nummer)
	) {$collate};" );

	dbDelta( "CREATE TABLE " . jb_table_regeln() . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		stichwort VARCHAR(160) NOT NULL DEFAULT '',
		konto VARCHAR(10) NOT NULL DEFAULT '',
		prioritaet INT NOT NULL DEFAULT 10,
		aktiv TINYINT NOT NULL DEFAULT 1,
		PRIMARY KEY  (id)
	) {$collate};" );

	// Spalten an jb_buchungen ergänzen.
	$add = function ( $table, $column, $definition ) use ( $wpdb ) {
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
			$table, $column
		) );
		if ( ! $exists ) {
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$definition}" );
		}
	};
	$j = jb_table_journal();
	$add( $j, 'konto', "`konto` VARCHAR(10) NOT NULL DEFAULT ''" );
	$add( $j, 'sphaere', "`sphaere` VARCHAR(16) NOT NULL DEFAULT ''" );
	$add( $j, 'gegenpartei', "`gegenpartei` VARCHAR(200) NOT NULL DEFAULT ''" );
	$add( $j, 'beleg_nr', "`beleg_nr` VARCHAR(20) NOT NULL DEFAULT ''" );

	// `quelle` von ENUM auf VARCHAR erweitern, damit weitere Geld-Töpfe
	// (z. B. „PayPal", „Umbuchung") ohne Schema-Änderung möglich sind.
	$q = $wpdb->get_row( "SHOW COLUMNS FROM `{$j}` LIKE 'quelle'" );
	if ( $q && 0 === stripos( (string) $q->Type, 'enum' ) ) {
		$wpdb->query( "ALTER TABLE `{$j}` MODIFY COLUMN `quelle` VARCHAR(20) NOT NULL DEFAULT 'Manuell'" );
	}
	if ( function_exists( 'jb_table_auslagen' ) ) {
		$a = jb_table_auslagen();
		$add( $a, 'konto', "`konto` VARCHAR(10) NOT NULL DEFAULT ''" );
		// 'beleg' = nur Beleg-Archivierung, keine Erstattung.
		$col = $wpdb->get_row( "SHOW COLUMNS FROM `{$a}` LIKE 'status'" );
		if ( $col && false === strpos( (string) $col->Type, "'beleg'" ) ) {
			$wpdb->query( "ALTER TABLE `{$a}` MODIFY COLUMN status ENUM('ausstehend','genehmigt','abgelehnt','ausgezahlt','beleg') DEFAULT 'ausstehend'" );
		}
	}

	vp_skr_seed();
	update_option( 'vp_skr_db_version', VP_SKR_DB_VERSION );
}

/* =========================================================================
 * Startvorschlag SKR 49 (Verein). Nummern sind ein Vorschlag – bitte mit
 * eurem Steuerbüro / DATEV abgleichen. Alles im Frontend editierbar.
 * ====================================================================== */

function vp_skr_sphaeren() {
	return array(
		'ideell'        => __( 'Ideeller Bereich', 'vereinsplugin' ),
		'vermoegen'     => __( 'Vermögensverwaltung', 'vereinsplugin' ),
		'zweckbetrieb'  => __( 'Zweckbetrieb', 'vereinsplugin' ),
		'wirtschaftlich'=> __( 'Wirtschaftl. Geschäftsbetrieb', 'vereinsplugin' ),
		'neutral'       => __( 'Neutral / Geldkonten', 'vereinsplugin' ),
	);
}

function vp_skr49_starter_konten() {
	// nummer, bezeichnung, typ, sphaere
	return array(
		array( '1000', 'Kasse (Bargeld)', 'bestand', 'neutral' ),
		array( '1200', 'Bank', 'bestand', 'neutral' ),
		array( '1360', 'Geldtransit / Wechselgeld', 'neutral', 'neutral' ),

		array( '4100', 'Echte Mitgliedsbeiträge', 'einnahme', 'ideell' ),
		array( '4110', 'Aufnahmegebühren', 'einnahme', 'ideell' ),
		array( '4200', 'Geldspenden', 'einnahme', 'ideell' ),
		array( '4210', 'Spenden lt. Zuwendungsbescheinigung', 'einnahme', 'ideell' ),
		array( '4300', 'Zuschüsse öffentliche Hand / Förderungen', 'einnahme', 'ideell' ),
		array( '4310', 'Zuschüsse Stiftungen / Dritte', 'einnahme', 'ideell' ),

		array( '4400', 'Zinserträge', 'einnahme', 'vermoegen' ),
		array( '4450', 'Sponsoring (Duldung)', 'einnahme', 'vermoegen' ),

		array( '4500', 'Einnahmen Zweckbetrieb / Veranstaltungen', 'einnahme', 'zweckbetrieb' ),
		array( '4510', 'Teilnehmer- / Eintrittsgelder', 'einnahme', 'zweckbetrieb' ),

		array( '4600', 'Getränke- / Bewirtungsumsatz', 'einnahme', 'wirtschaftlich' ),
		array( '4610', 'Werbung / aktives Sponsoring', 'einnahme', 'wirtschaftlich' ),
		array( '4620', 'Warenverkauf', 'einnahme', 'wirtschaftlich' ),

		array( '5100', 'Aufwand Vereinsarbeit / ideeller Bereich', 'ausgabe', 'ideell' ),
		array( '5110', 'Vereinssoftware / Mitgliederverwaltung', 'ausgabe', 'ideell' ),
		array( '5120', 'Versicherungen', 'ausgabe', 'ideell' ),
		array( '5130', 'Beiträge an Dachverbände', 'ausgabe', 'ideell' ),
		array( '5140', 'Material / Anschaffungen', 'ausgabe', 'ideell' ),
		array( '5150', 'Öffentlichkeitsarbeit / Druck', 'ausgabe', 'ideell' ),
		array( '5160', 'Telefon / Internet', 'ausgabe', 'ideell' ),
		array( '5170', 'GEMA', 'ausgabe', 'ideell' ),
		array( '5180', 'Müll / Gebühren / Entsorgung', 'ausgabe', 'ideell' ),
		array( '5190', 'Bankgebühren', 'ausgabe', 'ideell' ),
		array( '5200', 'Miete / Raumkosten', 'ausgabe', 'ideell' ),
		array( '5210', 'Reparatur / Instandhaltung', 'ausgabe', 'ideell' ),
		array( '5900', 'Sonstiger Aufwand (ideell)', 'ausgabe', 'ideell' ),

		array( '5400', 'Aufwand Vermögensverwaltung', 'ausgabe', 'vermoegen' ),

		array( '5500', 'Veranstaltungskosten Zweckbetrieb', 'ausgabe', 'zweckbetrieb' ),
		array( '5510', 'Honorare / Dienstleister (Zweckbetrieb)', 'ausgabe', 'zweckbetrieb' ),

		array( '5600', 'Wareneinkauf Getränke', 'ausgabe', 'wirtschaftlich' ),
		array( '5610', 'Veranstaltungstechnik (wirtschaftlich)', 'ausgabe', 'wirtschaftlich' ),
		array( '5690', 'Sonstiger Aufwand (wirtschaftl. GB)', 'ausgabe', 'wirtschaftlich' ),

		array( '7600', 'Steuern (USt / KSt / GewSt)', 'ausgabe', 'neutral' ),
	);
}

function vp_skr49_starter_regeln() {
	return array(
		array( 'Mitgliedsbeitrag', '4100' ), array( 'Mitgliedsbreitrag', '4100' ),
		array( 'Spende', '4200' ), array( 'Sponsoring', '4450' ), array( 'STIFTUNG', '4200' ),
		array( 'FOERDERUNG', '4300' ), array( 'FÖRDERUNG', '4300' ), array( 'VEREINSFOERDERUNG', '4300' ),
		array( 'Vodafone', '5160' ), array( 'Telekom', '5160' ), array( 'teuto.net', '5160' ),
		array( 'GEMA', '5170' ),
		array( 'Landratsamt', '5180' ), array( 'Abfallgebühr', '5180' ), array( 'Abfall', '5180' ),
		array( 'Finanzamt', '7600' ), array( 'USt', '7600' ),
		array( 'Entgelt', '5190' ), array( 'SpkCard', '5190' ), array( 'Bankgebühr', '5190' ),
		array( 'Allianz', '5120' ), array( 'Versicherung', '5120' ),
		array( 'webling', '5110' ),
		array( 'Leibinger', '5600' ), array( 'Getränkemarkt', '5600' ), array( 'Hausmann', '5600' ),
		array( 'FLYERALARM', '5150' ), array( 'Plakate', '5150' ), array( 'Druck', '5150' ),
		array( 'DSSD', '5500' ), array( 'Sicherheitsdienst', '5500' ), array( 'MMBS', '5500' ),
		array( 'AUDIO EXPRESS', '5610' ), array( 'Licht und Ton', '5610' ), array( 'Kassensystem', '5610' ), array( 'DJ Set', '5610' ),
		array( 'HAGEBAUMARKT', '5140' ), array( 'Intersport', '5140' ), array( 'Edeka', '5140' ), array( 'Rewe', '5140' ),
		array( 'DGUV', '5210' ), array( 'E+Service+Check', '5210' ),
		array( 'WECHSELGELD', '1360' ), array( 'TASSENPFAND', '1360' ), array( 'UMBUCHUNG', '1360' ),
		array( 'GETRÄNKEUMSATZ', '4600' ), array( 'GETRAENKEUMSATZ', '4600' ), array( 'UMSATZ WEIHNACHTSMARKT', '4600' ),
	);
}

function vp_skr_seed() {
	global $wpdb;
	if ( ! (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . jb_table_konten() ) ) {
		$sort = 0;
		foreach ( vp_skr49_starter_konten() as $k ) {
			$wpdb->insert( jb_table_konten(), array(
				'nummer' => $k[0], 'bezeichnung' => $k[1], 'typ' => $k[2], 'sphaere' => $k[3],
				'aktiv' => 1, 'sort' => $sort += 10,
			) );
		}
	}
	if ( ! (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . jb_table_regeln() ) ) {
		foreach ( vp_skr49_starter_regeln() as $r ) {
			$wpdb->insert( jb_table_regeln(), array( 'stichwort' => $r[0], 'konto' => $r[1], 'prioritaet' => 10, 'aktiv' => 1 ) );
		}
	}
}

/* =========================================================================
 * Helfer
 * ====================================================================== */

function jb_konten_all( $only_active = true ) {
	global $wpdb;
	$sql = 'SELECT * FROM ' . jb_table_konten();
	if ( $only_active ) {
		$sql .= ' WHERE aktiv = 1';
	}
	$sql .= ' ORDER BY sort ASC, nummer ASC';
	return $wpdb->get_results( $sql );
}

function jb_konto_get( $nummer ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jb_table_konten() . ' WHERE nummer = %s', $nummer ) );
}

function jb_konto_sphaere( $nummer ) {
	$k = jb_konto_get( $nummer );
	return $k ? $k->sphaere : '';
}

/** Fortlaufende Beleg-Nr pro Jahr, z. B. 2026-0001. */
function jb_next_beleg_nr( $jahr ) {
	global $wpdb;
	$jahr = (int) $jahr ?: (int) gmdate( 'Y' );
	$like = $wpdb->esc_like( $jahr . '-' ) . '%';
	$max  = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT MAX(CAST(SUBSTRING_INDEX(beleg_nr, '-', -1) AS UNSIGNED))
		 FROM " . jb_table_journal() . " WHERE beleg_nr LIKE %s",
		$like
	) );
	return sprintf( '%d-%04d', $jahr, $max + 1 );
}

/** Ordnet einem Text (Gegenpartei + Verwendungszweck) per Regel ein Konto zu. */
function jb_regel_konto_fuer( $text ) {
	global $wpdb;
	static $regeln = null;
	if ( null === $regeln ) {
		$regeln = $wpdb->get_results( 'SELECT stichwort, konto FROM ' . jb_table_regeln() . ' WHERE aktiv = 1 ORDER BY prioritaet ASC, LENGTH(stichwort) DESC' );
	}
	$hay = mb_strtolower( $text );
	foreach ( $regeln as $r ) {
		if ( '' !== $r->stichwort && mb_strpos( $hay, mb_strtolower( $r->stichwort ) ) !== false ) {
			return $r->konto;
		}
	}
	return '';
}

/* =========================================================================
 * Frontend: Buchhaltungs-Hub
 * ====================================================================== */

function vp_render_buchhaltung_hub() {
	if ( ! current_user_can( 'jb_view_journal' ) ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) . '</div>';
	}
	$view = isset( $_GET['vp_bh'] ) ? sanitize_key( wp_unslash( $_GET['vp_bh'] ) ) : 'journal';
	$tabs = array(
		'journal'    => __( 'Journal', 'vereinsplugin' ),
		'belege'     => __( 'Belege', 'vereinsplugin' ),
		'import'     => __( 'Bank-Import', 'vereinsplugin' ),
		'auswertung' => __( 'Auswertung', 'vereinsplugin' ),
		'ruecklagen' => __( 'Rücklagen', 'vereinsplugin' ),
		'konten'     => __( 'Kontenplan', 'vereinsplugin' ),
	);
	if ( ! isset( $tabs[ $view ] ) ) {
		$view = 'journal';
	}
	$base = get_permalink() ?: remove_query_arg( array( 'vp_bh', 'jahr' ) );

	ob_start();
	echo '<h2>' . esc_html__( 'Buchhaltung', 'vereinsplugin' ) . '</h2>';
	echo '<nav class="vp-subnav">';
	foreach ( $tabs as $k => $label ) {
		printf(
			'<a class="%s" href="%s">%s</a>',
			$k === $view ? 'is-active' : '',
			esc_url( add_query_arg( array( 'vp_tab' => 'buchhaltung', 'vp_bh' => $k ), $base ) ),
			esc_html( $label )
		);
	}
	printf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=jb_getraenke' ) ), esc_html__( 'Getränkekasse ↗', 'vereinsplugin' ) );
	echo '</nav>';

	switch ( $view ) {
		case 'belege':
			echo vp_bh_belege(); // phpcs:ignore
			break;
		case 'import':
			echo vp_bh_import(); // phpcs:ignore
			break;
		case 'auswertung':
			echo vp_bh_auswertung(); // phpcs:ignore
			break;
		case 'ruecklagen':
			echo vp_bh_ruecklagen(); // phpcs:ignore
			break;
		case 'konten':
			echo vp_bh_konten(); // phpcs:ignore
			break;
		default:
			echo vp_bh_journal(); // phpcs:ignore
	}
	return ob_get_clean();
}

/* ---- Belege ohne Buchung ---- */

function vp_bh_belege() {
	if ( ! function_exists( 'jb_get_auslagen' ) ) {
		return '<div class="vp-note">' . esc_html__( 'Nicht verfügbar.', 'vereinsplugin' ) . '</div>';
	}
	global $wpdb;
	$can_edit = current_user_can( 'jb_edit_journal' ) || current_user_can( 'jb_approve_auslagen' ) || current_user_can( 'manage_options' );
	$msg = '';

	if ( $can_edit && isset( $_POST['vp_beleg_buchen'] ) && check_admin_referer( 'vp_bh_belege', 'vp_belege_nonce' ) ) {
		$aid   = (int) $_POST['auslage_id'];
		$a     = function_exists( 'jb_get_auslage' ) ? jb_get_auslage( $aid ) : null;
		if ( $a && empty( $a['buchung_id'] ) && function_exists( 'jb_journal_add' ) ) {
			$konto = sanitize_text_field( wp_unslash( $_POST['konto'] ?? ( $a['konto'] ?? '' ) ) );
			$bid = jb_journal_add( array(
				'buchung_datum' => $a['ausgabe_datum'],
				'betrag'        => -abs( (float) $a['betrag'] ),
				'kategorie'     => $konto ? ( $konto . ' ' . ( jb_konto_get( $konto )->bezeichnung ?? '' ) ) : ( $a['kategorie'] ?? 'Beleg' ),
				'beschreibung'  => 'Beleg #' . $aid . ': ' . $a['beschreibung'],
				'quelle'        => 'Manuell',
				'beleg_pfad'    => $a['beleg_pfad'],
				'konto'         => $konto,
				'sphaere'       => jb_konto_sphaere( $konto ),
				'gegenpartei'   => $a['user_name'] ?? '',
			) );
			$wpdb->update( jb_table_auslagen(), array( 'buchung_id' => $bid ), array( 'id' => $aid ) );
			$msg = __( 'Beleg als Buchung übernommen.', 'vereinsplugin' );
		}
	}

	$t  = jb_table_auslagen();
	$rows = $wpdb->get_results( "SELECT a.*, u.display_name user_name FROM {$t} a LEFT JOIN {$wpdb->users} u ON u.ID=a.user_id WHERE a.status='beleg' AND (a.buchung_id IS NULL OR a.buchung_id=0) ORDER BY a.ausgabe_datum DESC", ARRAY_A );
	$konten = jb_konten_all();

	ob_start();
	if ( $msg ) {
		echo '<div class="vp-note">' . esc_html( $msg ) . '</div>';
	}
	echo '<p class="vp-muted">' . esc_html__( 'Belege, die ohne Erstattung eingereicht wurden (Verein hat per Karte/Bar bezahlt). Einer Buchung zuordnen oder direkt als Buchung übernehmen.', 'vereinsplugin' ) . '</p>';
	if ( ! $rows ) {
		echo '<p class="vp-muted">' . esc_html__( 'Keine offenen Belege.', 'vereinsplugin' ) . '</p>';
		return ob_get_clean();
	}
	foreach ( $rows as $a ) {
		echo '<div class="vp-card">';
		printf(
			'<strong>%s €</strong> · %s · %s<br><span class="vp-muted">%s</span>',
			esc_html( number_format( (float) $a['betrag'], 2, ',', '.' ) ),
			esc_html( $a['user_name'] ),
			esc_html( $a['ausgabe_datum'] ),
			esc_html( $a['beschreibung'] )
		);
		if ( ! empty( $a['beleg_pfad'] ) && function_exists( 'jb_nc' ) ) {
			echo ' <a class="vp-btn" target="_blank" rel="noopener" href="' . esc_url( jb_nc()->get_download_url( $a['beleg_pfad'] ) ) . '">' . esc_html__( 'Beleg', 'vereinsplugin' ) . '</a>';
		}
		if ( $can_edit ) {
			echo '<form method="post" style="margin-top:8px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">';
			echo wp_nonce_field( 'vp_bh_belege', 'vp_belege_nonce', true, false );
			echo '<input type="hidden" name="auslage_id" value="' . (int) $a['id'] . '">';
			echo '<select name="konto"><option value="">' . esc_html__( 'Konto wählen', 'vereinsplugin' ) . '</option>';
			foreach ( $konten as $k ) {
				echo '<option value="' . esc_attr( $k->nummer ) . '"' . selected( $a['konto'] ?? '', $k->nummer, false ) . '>' . esc_html( $k->nummer . ' · ' . $k->bezeichnung ) . '</option>';
			}
			echo '</select><button class="vp-btn vp-btn-primary" name="vp_beleg_buchen" value="1">' . esc_html__( 'Als Buchung übernehmen', 'vereinsplugin' ) . '</button>';
			echo '</form>';
		}
		echo '</div>';
	}
	return ob_get_clean();
}

/* ---- Rücklagen ---- */

function vp_bh_ruecklagen() {
	if ( ! function_exists( 'jb_ruecklagen_get_all' ) ) {
		return '<div class="vp-note">' . esc_html__( 'Rücklagen sind in diesem Modul nicht verfügbar.', 'vereinsplugin' ) . '</div>';
	}
	$can_edit = current_user_can( 'jb_edit_journal' ) || current_user_can( 'manage_options' );
	$msg = '';
	global $wpdb;

	if ( $can_edit && isset( $_POST['vp_rl_save'] ) && check_admin_referer( 'vp_bh_rl', 'vp_rl_nonce' ) ) {
		$data = array(
			'id'               => (int) ( $_POST['id'] ?? 0 ),
			'bezeichnung'      => wp_unslash( $_POST['bezeichnung'] ?? '' ),
			'betrag'           => wp_unslash( $_POST['betrag'] ?? '0' ),
			'intervall_monate' => (int) ( $_POST['intervall_monate'] ?? 12 ),
			'letzte_zahlung'   => sanitize_text_field( wp_unslash( $_POST['letzte_zahlung'] ?? gmdate( 'Y-m-d' ) ) ),
			'notiz'            => wp_unslash( $_POST['notiz'] ?? '' ),
		);
		if ( function_exists( 'jb_ruecklage_save' ) ) {
			jb_ruecklage_save( $data );
		} else {
			$row = array(
				'bezeichnung'      => sanitize_text_field( $data['bezeichnung'] ),
				'betrag'           => (float) str_replace( ',', '.', $data['betrag'] ),
				'intervall_monate' => $data['intervall_monate'],
				'letzte_zahlung'   => $data['letzte_zahlung'],
				'notiz'            => sanitize_textarea_field( $data['notiz'] ),
			);
			if ( $data['id'] ) {
				$wpdb->update( jb_table_ruecklagen(), $row, array( 'id' => $data['id'] ) );
			} else {
				$wpdb->insert( jb_table_ruecklagen(), $row );
			}
		}
		$msg = __( 'Rücklage gespeichert.', 'vereinsplugin' );
	}

	$rl = jb_ruecklagen_get_all();

	ob_start();
	if ( $msg ) {
		echo '<div class="vp-note">' . esc_html( $msg ) . '</div>';
	}
	echo '<p class="vp-muted">' . esc_html__( 'Wiederkehrende Kosten (Versicherung, GEMA, DGUV …). Pro Monat wird anteilig zurückgelegt. „Letzte Zahlung“ nach jeder echten Zahlung aktualisieren.', 'vereinsplugin' ) . '</p>';
	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Bezeichnung', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Betrag/Fällig.', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Intervall', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Letzte Zahlung', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Bedarf/Monat', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Rücklage heute', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
	$sum = 0.0;
	foreach ( $rl as $r ) {
		$r = (object) $r;
		$iv = max( 1, (int) $r->intervall_monate );
		$pm = (float) $r->betrag / $iv;
		$monate = $r->letzte_zahlung ? max( 0, (int) floor( ( time() - strtotime( $r->letzte_zahlung ) ) / 2592000 ) ) : 0;
		$heute = min( (float) $r->betrag, $pm * $monate );
		$sum  += $heute;
		printf(
			'<tr><td>%s</td><td style="text-align:right">%s €</td><td>%d Mon.</td><td>%s</td><td style="text-align:right">%s €</td><td style="text-align:right">%s €</td></tr>',
			esc_html( $r->bezeichnung ),
			esc_html( number_format( (float) $r->betrag, 2, ',', '.' ) ),
			$iv,
			esc_html( $r->letzte_zahlung ),
			esc_html( number_format( $pm, 2, ',', '.' ) ),
			esc_html( number_format( $heute, 2, ',', '.' ) )
		);
	}
	printf( '<tr style="font-weight:700"><td colspan="5">%s</td><td style="text-align:right">%s €</td></tr>', esc_html__( 'Rücklagenbedarf gesamt', 'vereinsplugin' ), esc_html( number_format( $sum, 2, ',', '.' ) ) );
	echo '</tbody></table></div>';

	if ( $can_edit ) {
		echo '<details class="vp-card"><summary><strong>' . esc_html__( 'Rücklage hinzufügen', 'vereinsplugin' ) . '</strong></summary>';
		echo '<form method="post" class="vp-form" style="margin-top:10px">' . wp_nonce_field( 'vp_bh_rl', 'vp_rl_nonce', true, false );
		echo '<div class="vp-form-grid">';
		echo '<label class="vp-col-2">' . esc_html__( 'Bezeichnung', 'vereinsplugin' ) . '<input name="bezeichnung" required></label>';
		echo '<label>' . esc_html__( 'Betrag pro Fälligkeit (€)', 'vereinsplugin' ) . '<input name="betrag" type="text" inputmode="decimal"></label>';
		echo '<label>' . esc_html__( 'Intervall (Monate)', 'vereinsplugin' ) . '<input name="intervall_monate" type="number" value="12" min="1"></label>';
		echo '<label>' . esc_html__( 'Letzte Zahlung', 'vereinsplugin' ) . '<input name="letzte_zahlung" type="date" value="' . esc_attr( gmdate( 'Y-m-d' ) ) . '"></label>';
		echo '<label class="vp-col-2">' . esc_html__( 'Notiz', 'vereinsplugin' ) . '<input name="notiz"></label>';
		echo '</div><p><button class="vp-btn vp-btn-primary" name="vp_rl_save" value="1">' . esc_html__( 'Speichern', 'vereinsplugin' ) . '</button></p></form></details>';
	}
	return ob_get_clean();
}

/* ---- Journal ---- */

function vp_bh_journal() {
	global $wpdb;
	$can_edit = current_user_can( 'jb_edit_journal' ) || current_user_can( 'manage_options' );
	$msg = '';

	if ( $can_edit && isset( $_POST['vp_bh_add'] ) && check_admin_referer( 'vp_bh_journal', 'vp_bh_nonce' ) ) {
		$typ    = ( 'einnahme' === ( $_POST['typ'] ?? '' ) ) ? 1 : -1;
		$betrag = $typ * abs( (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['betrag'] ?? '0' ) ) ) );
		$konto  = sanitize_text_field( wp_unslash( $_POST['konto'] ?? '' ) );
		if ( function_exists( 'jb_journal_add' ) && $betrag ) {
			jb_journal_add( array(
				'buchung_datum' => sanitize_text_field( wp_unslash( $_POST['datum'] ?? gmdate( 'Y-m-d' ) ) ),
				'betrag'        => $betrag,
				'kategorie'     => $konto ? ( $konto . ' ' . ( jb_konto_get( $konto )->bezeichnung ?? '' ) ) : sanitize_text_field( wp_unslash( $_POST['kategorie'] ?? 'Sonstige' ) ),
				'beschreibung'  => sanitize_textarea_field( wp_unslash( $_POST['zweck'] ?? '' ) ),
				'quelle'        => 'Manuell',
				'beleg_referenz'=> sanitize_text_field( wp_unslash( $_POST['beleg'] ?? '' ) ),
				'konto'         => $konto,
				'sphaere'       => jb_konto_sphaere( $konto ),
				'gegenpartei'   => sanitize_text_field( wp_unslash( $_POST['gegenpartei'] ?? '' ) ),
			) );
			$msg = __( 'Buchung gespeichert.', 'vereinsplugin' );
		}
	}
	if ( $can_edit && isset( $_POST['vp_bh_del'] ) && check_admin_referer( 'vp_bh_journal', 'vp_bh_nonce' ) && function_exists( 'jb_journal_delete' ) ) {
		jb_journal_delete( (int) $_POST['id'] );
		$msg = __( 'Buchung gelöscht.', 'vereinsplugin' );
	}
	if ( $can_edit && isset( $_POST['vp_bh_edit'] ) && check_admin_referer( 'vp_bh_journal', 'vp_bh_nonce' ) ) {
		$eid    = (int) ( $_POST['id'] ?? 0 );
		$typ    = ( 'einnahme' === ( $_POST['typ'] ?? '' ) ) ? 1 : -1;
		$betrag = $typ * abs( (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['betrag'] ?? '0' ) ) ) );
		$konto  = sanitize_text_field( wp_unslash( $_POST['konto'] ?? '' ) );
		if ( $eid && $betrag ) {
			$upd = array(
				'buchung_datum' => sanitize_text_field( wp_unslash( $_POST['datum'] ?? gmdate( 'Y-m-d' ) ) ),
				'betrag'        => $betrag,
				'beschreibung'  => sanitize_textarea_field( wp_unslash( $_POST['zweck'] ?? '' ) ),
				'gegenpartei'   => sanitize_text_field( wp_unslash( $_POST['gegenpartei'] ?? '' ) ),
				'beleg_nr'      => sanitize_text_field( wp_unslash( $_POST['beleg'] ?? '' ) ),
				'konto'         => $konto,
				'sphaere'       => $konto ? jb_konto_sphaere( $konto ) : sanitize_text_field( wp_unslash( $_POST['sphaere'] ?? '' ) ),
				'kategorie'     => $konto ? ( $konto . ' ' . ( jb_konto_get( $konto )->bezeichnung ?? '' ) ) : sanitize_text_field( wp_unslash( $_POST['zweck'] ?? 'Sonstige' ) ),
			);
			if ( isset( $_POST['quelle'] ) ) {
				$upd['quelle'] = sanitize_text_field( wp_unslash( $_POST['quelle'] ) );
			}
			$wpdb->update( jb_table_journal(), $upd, array( 'id' => $eid ) );
			$msg = __( 'Buchung aktualisiert.', 'vereinsplugin' );
		}
	}
	if ( $can_edit && isset( $_POST['vp_bh_beleg_up'] ) && check_admin_referer( 'vp_bh_journal', 'vp_bh_nonce' ) ) {
		$msg = vp_bh_journal_beleg_upload( (int) $_POST['id'], $_FILES['beleg_file'] ?? array() );
	}

	$jahr  = isset( $_GET['jahr'] ) ? (int) $_GET['jahr'] : (int) gmdate( 'Y' );
	$rows  = function_exists( 'jb_journal_get' ) ? jb_journal_get( array( 'year' => $jahr ) ) : array();
	$konten = jb_konten_all();

	ob_start();
	if ( $msg ) {
		echo '<div class="vp-note">' . esc_html( $msg ) . '</div>';
	}
	echo vp_bh_year_switcher( $jahr );

	if ( $can_edit ) {
		?>
		<details class="vp-card"><summary><strong><?php esc_html_e( 'Neue Buchung erfassen', 'vereinsplugin' ); ?></strong></summary>
		<form method="post" class="vp-form" style="margin-top:12px">
			<?php wp_nonce_field( 'vp_bh_journal', 'vp_bh_nonce' ); ?>
			<div class="vp-form-grid">
				<label><?php esc_html_e( 'Datum', 'vereinsplugin' ); ?><input type="date" name="datum" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>"></label>
				<label><?php esc_html_e( 'Art', 'vereinsplugin' ); ?>
					<select name="typ"><option value="ausgabe"><?php esc_html_e( 'Ausgabe', 'vereinsplugin' ); ?></option><option value="einnahme"><?php esc_html_e( 'Einnahme', 'vereinsplugin' ); ?></option></select></label>
				<label><?php esc_html_e( 'Betrag (€)', 'vereinsplugin' ); ?><input type="text" name="betrag" inputmode="decimal" placeholder="0,00"></label>
				<label><?php esc_html_e( 'Konto (SKR 49)', 'vereinsplugin' ); ?>
					<select name="konto">
						<option value=""><?php esc_html_e( '– nicht zugeordnet –', 'vereinsplugin' ); ?></option>
						<?php foreach ( $konten as $k ) : ?>
							<option value="<?php echo esc_attr( $k->nummer ); ?>"><?php echo esc_html( $k->nummer . ' · ' . $k->bezeichnung ); ?></option>
						<?php endforeach; ?>
					</select></label>
				<label><?php esc_html_e( 'Gegenpartei', 'vereinsplugin' ); ?><input type="text" name="gegenpartei"></label>
				<label class="vp-col-2"><?php esc_html_e( 'Verwendungszweck', 'vereinsplugin' ); ?><input type="text" name="zweck"></label>
				<label><?php esc_html_e( 'Beleg-Nr.', 'vereinsplugin' ); ?><input type="text" name="beleg"></label>
			</div>
			<p><button class="vp-btn vp-btn-primary" name="vp_bh_add" value="1"><?php esc_html_e( 'Buchen', 'vereinsplugin' ); ?></button></p>
		</form>
		</details>
		<?php
	}

	$has_nc = function_exists( 'jb_nc' );
	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr>'
		. '<th>' . esc_html__( 'Beleg-Nr.', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Datum', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Konto', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Gegenpartei / Zweck', 'vereinsplugin' ) . '</th>'
		. '<th style="text-align:right">' . esc_html__( 'Betrag', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Beleg', 'vereinsplugin' ) . '</th>'
		. ( $can_edit ? '<th></th>' : '' ) . '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$betrag = (float) $r['betrag'];
		$rid    = (int) $r['id'];

		$beleg_cell = '<span class="vp-muted">–</span>';
		if ( ! empty( $r['beleg_pfad'] ) && $has_nc ) {
			$beleg_cell = '<a class="vp-btn" target="_blank" rel="noopener" href="' . esc_url( jb_nc()->get_download_url( $r['beleg_pfad'] ) ) . '">' . esc_html__( 'ansehen', 'vereinsplugin' ) . '</a>';
		} elseif ( $can_edit && $has_nc ) {
			$beleg_cell = '<form method="post" enctype="multipart/form-data" style="display:flex;gap:4px;align-items:center">'
				. wp_nonce_field( 'vp_bh_journal', 'vp_bh_nonce', true, false )
				. '<input type="hidden" name="id" value="' . $rid . '">'
				. '<input type="file" name="beleg_file" accept=".pdf,.jpg,.jpeg,.png,.webp" required style="max-width:120px">'
				. '<button class="vp-btn" name="vp_bh_beleg_up" value="1">↑</button></form>';
		}

		$edit_cell = '';
		if ( $can_edit ) {
			$opts = '<option value="">' . esc_html__( '– nicht zugeordnet –', 'vereinsplugin' ) . '</option>';
			foreach ( $konten as $k ) {
				$opts .= '<option value="' . esc_attr( $k->nummer ) . '"' . selected( (string) $k->nummer, (string) $r['konto'], false ) . '>'
					. esc_html( $k->nummer . ' · ' . $k->bezeichnung ) . '</option>';
			}
			$q_cur = (string) ( $r['quelle'] ?? '' );
			$q_opt = '';
			foreach ( array( 'Bank KSK', 'Zettle-Bar', 'Zettle-Karte', 'PayPal', 'Auslage', 'Umbuchung', 'Manuell' ) as $q ) {
				$q_opt .= '<option' . selected( $q, $q_cur, false ) . '>' . esc_html( $q ) . '</option>';
			}
			$edit_cell = '<td><details class="vp-inline-edit"><summary class="vp-btn">✎</summary>'
				. '<form method="post" class="vp-form" style="margin-top:8px;min-width:280px">'
				. wp_nonce_field( 'vp_bh_journal', 'vp_bh_nonce', true, false )
				. '<input type="hidden" name="id" value="' . $rid . '">'
				. '<label>' . esc_html__( 'Datum', 'vereinsplugin' ) . '<input type="date" name="datum" value="' . esc_attr( $r['buchung_datum'] ) . '"></label>'
				. '<label>' . esc_html__( 'Art', 'vereinsplugin' ) . '<select name="typ"><option value="ausgabe"' . selected( $betrag < 0, true, false ) . '>' . esc_html__( 'Ausgabe', 'vereinsplugin' ) . '</option><option value="einnahme"' . selected( $betrag >= 0, true, false ) . '>' . esc_html__( 'Einnahme', 'vereinsplugin' ) . '</option></select></label>'
				. '<label>' . esc_html__( 'Betrag (€)', 'vereinsplugin' ) . '<input type="text" name="betrag" inputmode="decimal" value="' . esc_attr( number_format( abs( $betrag ), 2, ',', '' ) ) . '"></label>'
				. '<label>' . esc_html__( 'Konto (SKR 49)', 'vereinsplugin' ) . '<select name="konto">' . $opts . '</select></label>'
				. '<label>' . esc_html__( 'Topf / Quelle', 'vereinsplugin' ) . '<select name="quelle">' . $q_opt . '</select></label>'
				. '<label>' . esc_html__( 'Gegenpartei', 'vereinsplugin' ) . '<input type="text" name="gegenpartei" value="' . esc_attr( $r['gegenpartei'] ?? '' ) . '"></label>'
				. '<label>' . esc_html__( 'Verwendungszweck', 'vereinsplugin' ) . '<input type="text" name="zweck" value="' . esc_attr( $r['beschreibung'] ?? '' ) . '"></label>'
				. '<label>' . esc_html__( 'Beleg-Nr.', 'vereinsplugin' ) . '<input type="text" name="beleg" value="' . esc_attr( $r['beleg_nr'] ?? '' ) . '"></label>'
				. '<p><button class="vp-btn vp-btn-primary" name="vp_bh_edit" value="1">' . esc_html__( 'Speichern', 'vereinsplugin' ) . '</button> '
				. '<button class="vp-btn vp-btn-danger" name="vp_bh_del" value="1" onclick="return confirm(\'' . esc_js( __( 'Buchung löschen?', 'vereinsplugin' ) ) . '\')">' . esc_html__( 'Löschen', 'vereinsplugin' ) . '</button></p>'
				. '</form></details></td>';
		}

		printf(
			'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s<br><span class="vp-muted">%s</span></td><td style="text-align:right;%s">%s €</td><td>%s</td>%s</tr>',
			esc_html( $r['beleg_nr'] ?? '' ),
			esc_html( $r['buchung_datum'] ),
			esc_html( $r['konto'] ?: '–' ),
			esc_html( $r['gegenpartei'] ?? '' ),
			esc_html( wp_trim_words( (string) $r['beschreibung'], 14 ) ),
			$betrag < 0 ? 'color:#b91c1c' : 'color:#166534',
			esc_html( number_format( $betrag, 2, ',', '.' ) ),
			$beleg_cell,
			$edit_cell
		);
	}
	if ( ! $rows ) {
		echo '<tr><td colspan="7" class="vp-muted">' . esc_html__( 'Keine Buchungen in diesem Jahr.', 'vereinsplugin' ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
	return ob_get_clean();
}

/** Beleg-Datei zu einer Journalbuchung nach Nextcloud hochladen. */
function vp_bh_journal_beleg_upload( $buchung_id, $file ) {
	if ( ! $buchung_id || empty( $file['tmp_name'] ) || ( $file['error'] ?? 1 ) !== UPLOAD_ERR_OK ) {
		return __( 'Keine Datei empfangen.', 'vereinsplugin' );
	}
	if ( ! function_exists( 'jb_nc' ) ) {
		return __( 'Nextcloud ist nicht konfiguriert.', 'vereinsplugin' );
	}
	global $wpdb;
	$b = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jb_table_journal() . ' WHERE id = %d', $buchung_id ), ARRAY_A );
	if ( ! $b ) {
		return __( 'Buchung nicht gefunden.', 'vereinsplugin' );
	}
	$ext  = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ) ?: 'pdf';
	$ext  = preg_replace( '/[^a-z0-9]/', '', $ext );
	$year = substr( (string) $b['buchung_datum'], 0, 4 ) ?: gmdate( 'Y' );
	$ref  = $b['beleg_nr'] ?: ( 'B' . $buchung_id );
	$nc_path = "Belege/{$year}/Buchungen/{$ref}.{$ext}";
	$res = jb_nc()->upload_beleg( $file['tmp_name'], $nc_path );
	if ( is_wp_error( $res ) ) {
		return $res->get_error_message();
	}
	$wpdb->update( jb_table_journal(), array( 'beleg_pfad' => $nc_path ), array( 'id' => $buchung_id ) );
	return __( 'Beleg hochgeladen.', 'vereinsplugin' );
}

function vp_bh_year_switcher( $jahr ) {
	$base = get_permalink() ?: remove_query_arg( 'jahr' );
	$out  = '<p class="vp-subnav">';
	for ( $y = (int) gmdate( 'Y' ); $y >= (int) gmdate( 'Y' ) - 4; $y-- ) {
		$out .= sprintf(
			'<a class="%s" href="%s">%d</a>',
			$y === (int) $jahr ? 'is-active' : '',
			esc_url( add_query_arg( array( 'vp_tab' => 'buchhaltung', 'vp_bh' => sanitize_key( $_GET['vp_bh'] ?? 'journal' ), 'jahr' => $y ), $base ) ),
			$y
		);
	}
	return $out . '</p>';
}

/* ---- Auswertung ---- */

function vp_bh_auswertung() {
	global $wpdb;
	$jahr = isset( $_GET['jahr'] ) ? (int) $_GET['jahr'] : (int) gmdate( 'Y' );
	$j    = jb_table_journal();

	$konto_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT konto,
		        SUM(CASE WHEN betrag>0 THEN betrag ELSE 0 END) ein,
		        SUM(CASE WHEN betrag<0 THEN -betrag ELSE 0 END) aus,
		        COUNT(*) n
		 FROM {$j} WHERE YEAR(buchung_datum)=%d GROUP BY konto ORDER BY konto", $jahr
	) );

	$sph_rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT sphaere,
		        SUM(CASE WHEN betrag>0 THEN betrag ELSE 0 END) ein,
		        SUM(CASE WHEN betrag<0 THEN -betrag ELSE 0 END) aus
		 FROM {$j} WHERE YEAR(buchung_datum)=%d GROUP BY sphaere", $jahr
	) );

	$sph_labels = vp_skr_sphaeren();
	$konto_name = array();
	foreach ( jb_konten_all( false ) as $k ) {
		$konto_name[ $k->nummer ] = $k->bezeichnung;
	}

	$total_ein = 0.0; $total_aus = 0.0;

	ob_start();
	echo vp_bh_year_switcher( $jahr );

	echo '<h3>' . esc_html__( 'Nach Sphäre (Gemeinnützigkeit)', 'vereinsplugin' ) . '</h3>';
	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Sphäre', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Einnahmen', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Ausgaben', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Saldo', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
	foreach ( $sph_rows as $s ) {
		$ein = (float) $s->ein; $aus = (float) $s->aus;
		$total_ein += $ein; $total_aus += $aus;
		printf(
			'<tr><td>%s</td><td style="text-align:right">%s €</td><td style="text-align:right">%s €</td><td style="text-align:right">%s €</td></tr>',
			esc_html( $sph_labels[ $s->sphaere ] ?? ( $s->sphaere ?: __( 'ohne Sphäre', 'vereinsplugin' ) ) ),
			esc_html( number_format( $ein, 2, ',', '.' ) ),
			esc_html( number_format( $aus, 2, ',', '.' ) ),
			esc_html( number_format( $ein - $aus, 2, ',', '.' ) )
		);
	}
	printf(
		'<tr style="font-weight:700"><td>%s</td><td style="text-align:right">%s €</td><td style="text-align:right">%s €</td><td style="text-align:right">%s €</td></tr>',
		esc_html__( 'Gesamt (EÜR-Überschuss)', 'vereinsplugin' ),
		esc_html( number_format( $total_ein, 2, ',', '.' ) ),
		esc_html( number_format( $total_aus, 2, ',', '.' ) ),
		esc_html( number_format( $total_ein - $total_aus, 2, ',', '.' ) )
	);
	echo '</tbody></table></div>';

	echo '<h3>' . esc_html__( 'Nach Konto', 'vereinsplugin' ) . '</h3>';
	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Konto', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Einnahmen', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Ausgaben', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Anzahl', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
	foreach ( $konto_rows as $r ) {
		printf(
			'<tr><td>%s%s</td><td style="text-align:right">%s €</td><td style="text-align:right">%s €</td><td style="text-align:right">%d</td></tr>',
			esc_html( $r->konto ?: '—' ),
			$r->konto && isset( $konto_name[ $r->konto ] ) ? ' · <span class="vp-muted">' . esc_html( $konto_name[ $r->konto ] ) . '</span>' : '',
			esc_html( number_format( (float) $r->ein, 2, ',', '.' ) ),
			esc_html( number_format( (float) $r->aus, 2, ',', '.' ) ),
			(int) $r->n
		);
	}
	echo '</tbody></table></div>';

	if ( is_callable( 'jb_page_export' ) || function_exists( 'jb_export_euer_csv' ) ) {
		echo '<p><a class="vp-btn" href="' . esc_url( admin_url( 'admin.php?page=jb_export&year=' . $jahr ) ) . '">' . esc_html__( 'EÜR / DATEV-Export öffnen', 'vereinsplugin' ) . '</a></p>';
	}
	return ob_get_clean();
}

/* ---- Bank-Import (CSV) ---- */

function vp_bh_import() {
	if ( ! ( current_user_can( 'jb_edit_journal' ) || current_user_can( 'manage_options' ) ) ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Keine Berechtigung zum Buchen.', 'vereinsplugin' ) . '</div>';
	}

	$step   = 'form';
	$out    = '';
	$parsed = array();

	if ( isset( $_POST['vp_imp_preview'] ) && check_admin_referer( 'vp_bh_import', 'vp_imp_nonce' ) ) {
		$raw = (string) wp_unslash( $_POST['csv'] ?? '' );
		if ( '' === trim( $raw ) && ! empty( $_FILES['csvfile']['tmp_name'] ) ) {
			$raw = (string) file_get_contents( $_FILES['csvfile']['tmp_name'] );
		}
		$delim  = ( ';' === ( $_POST['delim'] ?? ';' ) ) ? ';' : ',';
		$parsed = vp_bh_parse_bank_csv( $raw, $delim );
		$step   = $parsed ? 'preview' : 'form';
		if ( ! $parsed ) {
			$out .= '<div class="vp-note vp-note-warn">' . esc_html__( 'Keine Zeilen erkannt. Trennzeichen prüfen.', 'vereinsplugin' ) . '</div>';
		}
	}

	if ( isset( $_POST['vp_imp_commit'] ) && check_admin_referer( 'vp_bh_import', 'vp_imp_nonce' ) && function_exists( 'jb_journal_add' ) ) {
		$n = 0;
		$rows = json_decode( (string) wp_unslash( $_POST['rows'] ?? '[]' ), true );
		$konto_map = (array) ( $_POST['konto'] ?? array() );
		foreach ( (array) $rows as $i => $r ) {
			$betrag = (float) $r['betrag'];
			if ( ! $betrag ) {
				continue;
			}
			$konto = sanitize_text_field( $konto_map[ $i ] ?? ( $r['konto'] ?? '' ) );
			jb_journal_add( array(
				'buchung_datum' => sanitize_text_field( $r['datum'] ),
				'betrag'        => $betrag,
				'kategorie'     => $konto ? ( $konto . ' ' . ( jb_konto_get( $konto )->bezeichnung ?? '' ) ) : 'Import',
				'beschreibung'  => sanitize_textarea_field( $r['zweck'] ),
				'quelle'        => 'Bank KSK',
				'konto'         => $konto,
				'sphaere'       => jb_konto_sphaere( $konto ),
				'gegenpartei'   => sanitize_text_field( $r['name'] ),
			) );
			$n++;
		}
		return '<div class="vp-note">' . esc_html( sprintf( __( '%d Buchungen importiert.', 'vereinsplugin' ), $n ) ) . '</div>'
			. '<p><a class="vp-btn" href="' . esc_url( add_query_arg( array( 'vp_tab' => 'buchhaltung', 'vp_bh' => 'journal' ) ) ) . '">' . esc_html__( 'Zum Journal', 'vereinsplugin' ) . '</a></p>';
	}

	$konten = jb_konten_all();

	if ( 'preview' === $step ) {
		$out .= '<form method="post"><h3>' . esc_html__( 'Vorschau – Konten prüfen, dann importieren', 'vereinsplugin' ) . '</h3>';
		$out .= wp_nonce_field( 'vp_bh_import', 'vp_imp_nonce', true, false );
		$out .= '<input type="hidden" name="rows" value="' . esc_attr( wp_json_encode( $parsed ) ) . '">';
		$out .= '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Datum', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Gegenpartei / Zweck', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Betrag', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Konto', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
		foreach ( $parsed as $i => $r ) {
			$sel = '<select name="konto[' . (int) $i . ']"><option value="">–</option>';
			foreach ( $konten as $k ) {
				$sel .= '<option value="' . esc_attr( $k->nummer ) . '"' . selected( $r['konto'], $k->nummer, false ) . '>' . esc_html( $k->nummer . ' · ' . $k->bezeichnung ) . '</option>';
			}
			$sel .= '</select>';
			$out .= sprintf(
				'<tr><td>%s</td><td>%s<br><span class="vp-muted">%s</span></td><td style="text-align:right;%s">%s €</td><td>%s</td></tr>',
				esc_html( $r['datum'] ),
				esc_html( $r['name'] ),
				esc_html( wp_trim_words( $r['zweck'], 16 ) ),
				$r['betrag'] < 0 ? 'color:#b91c1c' : 'color:#166534',
				esc_html( number_format( (float) $r['betrag'], 2, ',', '.' ) ),
				$sel
			);
		}
		$out .= '</tbody></table></div>';
		$out .= '<p><button class="vp-btn vp-btn-primary" name="vp_imp_commit" value="1">' . esc_html( sprintf( __( '%d Buchungen importieren', 'vereinsplugin' ), count( $parsed ) ) ) . '</button></p></form>';
		return $out;
	}

	// Formular.
	ob_start();
	?>
	<p class="vp-muted"><?php esc_html_e( 'CSV-Export aus dem Online-Banking (Sparkasse: „Umsätze → CSV-CAMT-Format“). Datei hochladen oder Inhalt einfügen. Konten werden automatisch per Stichwort-Regel vorgeschlagen und lassen sich vor dem Import ändern.', 'vereinsplugin' ); ?></p>
	<form method="post" enctype="multipart/form-data" class="vp-card">
		<?php echo wp_nonce_field( 'vp_bh_import', 'vp_imp_nonce', true, false ); // phpcs:ignore ?>
		<p><label><?php esc_html_e( 'CSV-Datei', 'vereinsplugin' ); ?><br><input type="file" name="csvfile" accept=".csv,text/csv"></label></p>
		<p><label><?php esc_html_e( 'oder Inhalt einfügen', 'vereinsplugin' ); ?><br>
			<textarea name="csv" rows="6" style="width:100%"></textarea></label></p>
		<p><label><?php esc_html_e( 'Trennzeichen', 'vereinsplugin' ); ?>
			<select name="delim"><option value=";">;  (Sparkasse)</option><option value=",">,</option></select></label></p>
		<p><button class="vp-btn vp-btn-primary" name="vp_imp_preview" value="1"><?php esc_html_e( 'Vorschau', 'vereinsplugin' ); ?></button></p>
	</form>
	<?php
	return $out . ob_get_clean();
}

/**
 * Sehr toleranter CSV-Parser: erkennt Sparkassen-CAMT-Spalten und generische
 * „Datum;Betrag;Name;Zweck“-Exporte.
 * @return array<int,array{datum:string,betrag:float,name:string,zweck:string,konto:string}>
 */
function vp_bh_parse_bank_csv( $raw, $delim = ';' ) {
	// Bank-Exporte sind oft Windows-1252 / ISO-8859-1 – nach UTF-8 wandeln.
	if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
		$raw = mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
	}
	$raw = preg_replace( "/\r\n|\r/", "\n", trim( $raw ) );
	if ( '' === $raw ) {
		return array();
	}
	$lines = explode( "\n", $raw );
	$head  = str_getcsv( array_shift( $lines ), $delim, '"' );
	$head_l = array_map( function ( $h ) { return mb_strtolower( trim( $h ) ); }, $head );

	// Erst exakte Übereinstimmung suchen, dann Teilstring. Sonst matcht z. B.
	// „Betrag“ zuerst auf „Lastschrift Ursprungsbetrag“ (nur bei Rücklastschriften
	// gefüllt) – dann werden fast alle Zeilen verworfen.
	$find = function ( array $names ) use ( $head_l ) {
		// Nach Namens-Priorität: erst exakte Treffer für den ersten Namen, dann
		// den zweiten … danach erst Teilstring-Treffer.
		foreach ( $names as $n ) {
			foreach ( $head_l as $i => $h ) {
				if ( $h === $n ) {
					return $i;
				}
			}
		}
		foreach ( $names as $n ) {
			foreach ( $head_l as $i => $h ) {
				if ( false !== mb_strpos( $h, $n ) ) {
					return $i;
				}
			}
		}
		return -1;
	};
	$i_datum  = $find( array( 'buchungstag', 'buchungsdatum', 'datum', 'valutadatum', 'valuta' ) );
	$i_betrag = $find( array( 'betrag', 'umsatz', 'betrag in eur' ) );
	$i_name   = $find( array( 'beguenstigter/zahlungspflichtiger', 'beguenstigter', 'begünstigter', 'zahlungspflichtiger', 'name', 'auftraggeber/empfänger', 'auftraggeber', 'empfänger', 'empfaenger' ) );
	$i_zweck  = $find( array( 'verwendungszweck', 'zweck', 'buchungstext', 'vwz' ) );

	// Fallback: reine Spaltenreihenfolge Datum;Betrag;Name;Zweck
	if ( $i_datum < 0 && $i_betrag < 0 ) {
		$i_datum = 0; $i_betrag = 1; $i_name = 2; $i_zweck = 3;
		array_unshift( $lines, implode( $delim, $head ) ); // erste Zeile war doch Daten
	}
	if ( $i_betrag < 0 ) {
		return array();
	}

	$out = array();
	foreach ( $lines as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}
		$c = str_getcsv( $line, $delim, '"' );
		$datum = vp_bh_norm_date( $c[ $i_datum ] ?? '' );
		$betrag = vp_bh_norm_amount( $c[ $i_betrag ] ?? '' );
		if ( ! $datum || 0.0 === $betrag ) {
			continue;
		}
		$name  = trim( $c[ $i_name ] ?? '' );
		$zweck = trim( $c[ $i_zweck ] ?? '' );
		$out[] = array(
			'datum'  => $datum,
			'betrag' => $betrag,
			'name'   => $name,
			'zweck'  => $zweck,
			'konto'  => jb_regel_konto_fuer( $name . ' ' . $zweck ),
		);
	}
	return $out;
}

function vp_bh_norm_date( $s ) {
	$s = trim( $s );
	if ( preg_match( '#^(\d{2})\.(\d{2})\.(\d{2,4})$#', $s, $m ) ) {
		$y = strlen( $m[3] ) === 2 ? '20' . $m[3] : $m[3];
		return "$y-{$m[2]}-{$m[1]}";
	}
	if ( preg_match( '#^\d{4}-\d{2}-\d{2}#', $s ) ) {
		return substr( $s, 0, 10 );
	}
	return '';
}

function vp_bh_norm_amount( $s ) {
	$s = trim( str_replace( array( "\xc2\xa0", ' ', '€', 'EUR' ), '', (string) $s ) );
	if ( '' === $s ) {
		return 0.0;
	}
	// deutsches Format 1.234,56  ->  1234.56
	if ( preg_match( '#,\d{2}$#', $s ) ) {
		$s = str_replace( '.', '', $s );
		$s = str_replace( ',', '.', $s );
	} else {
		$s = str_replace( ',', '', $s );
	}
	return (float) $s;
}

/* ---- Kontenplan + Regeln ---- */

function vp_bh_konten() {
	if ( ! ( current_user_can( 'jb_edit_journal' ) || current_user_can( 'manage_options' ) ) ) {
		// Nur-Lese-Ansicht
	}
	global $wpdb;
	$can_edit = current_user_can( 'jb_edit_journal' ) || current_user_can( 'manage_options' );
	$msg = '';

	if ( $can_edit && isset( $_POST['vp_konto_save'] ) && check_admin_referer( 'vp_konten', 'vp_konten_nonce' ) ) {
		$id = (int) ( $_POST['id'] ?? 0 );
		$row = array(
			'nummer'      => sanitize_text_field( wp_unslash( $_POST['nummer'] ?? '' ) ),
			'bezeichnung' => sanitize_text_field( wp_unslash( $_POST['bezeichnung'] ?? '' ) ),
			'typ'         => sanitize_key( wp_unslash( $_POST['typ'] ?? 'ausgabe' ) ),
			'sphaere'     => sanitize_key( wp_unslash( $_POST['sphaere'] ?? 'ideell' ) ),
			'aktiv'       => empty( $_POST['aktiv'] ) ? 0 : 1,
		);
		if ( $id ) {
			$wpdb->update( jb_table_konten(), $row, array( 'id' => $id ) );
		} elseif ( $row['nummer'] || $row['bezeichnung'] ) {
			$row['sort'] = 999;
			$wpdb->insert( jb_table_konten(), $row );
		}
		$msg = __( 'Kontenplan gespeichert.', 'vereinsplugin' );
	}
	if ( $can_edit && isset( $_POST['vp_regel_save'] ) && check_admin_referer( 'vp_konten', 'vp_konten_nonce' ) ) {
		$sw = sanitize_text_field( wp_unslash( $_POST['stichwort'] ?? '' ) );
		$ko = sanitize_text_field( wp_unslash( $_POST['regel_konto'] ?? '' ) );
		if ( $sw && $ko ) {
			$wpdb->insert( jb_table_regeln(), array( 'stichwort' => $sw, 'konto' => $ko, 'prioritaet' => 10, 'aktiv' => 1 ) );
			$msg = __( 'Regel hinzugefügt.', 'vereinsplugin' );
		}
	}
	if ( $can_edit && isset( $_POST['vp_regel_del'] ) && check_admin_referer( 'vp_konten', 'vp_konten_nonce' ) ) {
		$wpdb->delete( jb_table_regeln(), array( 'id' => (int) $_POST['id'] ) );
		$msg = __( 'Regel gelöscht.', 'vereinsplugin' );
	}

	$konten = jb_konten_all( false );
	$regeln = $wpdb->get_results( 'SELECT * FROM ' . jb_table_regeln() . ' ORDER BY prioritaet, stichwort' );
	$sph    = vp_skr_sphaeren();

	ob_start();
	if ( $msg ) {
		echo '<div class="vp-note">' . esc_html( $msg ) . '</div>';
	}
	echo '<div class="vp-note vp-note-warn">' . esc_html__( 'Die Kontonummern sind ein SKR-49-Startvorschlag. Bitte mit eurem Steuerbüro / eurer DATEV-Vorlage abgleichen und anpassen.', 'vereinsplugin' ) . '</div>';

	echo '<h3>' . esc_html__( 'Konten', 'vereinsplugin' ) . '</h3>';
	if ( ! $can_edit ) {
		echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Nr.', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Bezeichnung', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Typ', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Sphäre', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Aktiv', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
		foreach ( $konten as $k ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $k->nummer ), esc_html( $k->bezeichnung ), esc_html( $k->typ ),
				esc_html( $sph[ $k->sphaere ] ?? $k->sphaere ), $k->aktiv ? '✓' : '–'
			);
		}
		echo '</tbody></table></div>';
	} else {
		// Editierbare Liste als einzelne Inline-Formulare (keine <form> in <tr>).
		echo '<div class="vp-konten-liste">';
		foreach ( $konten as $k ) {
			echo '<form method="post" class="vp-konto-row">' . wp_nonce_field( 'vp_konten', 'vp_konten_nonce', true, false );
			echo '<input type="hidden" name="id" value="' . (int) $k->id . '">';
			echo '<input name="nummer" value="' . esc_attr( $k->nummer ) . '" size="6" aria-label="Nr.">';
			echo '<input name="bezeichnung" value="' . esc_attr( $k->bezeichnung ) . '" aria-label="Bezeichnung" style="flex:1;min-width:160px">';
			echo '<select name="typ" aria-label="Typ">';
			foreach ( array( 'einnahme', 'ausgabe', 'bestand', 'neutral' ) as $t ) {
				echo '<option value="' . esc_attr( $t ) . '"' . selected( $k->typ, $t, false ) . '>' . esc_html( $t ) . '</option>';
			}
			echo '</select><select name="sphaere" aria-label="Sphäre">';
			foreach ( $sph as $sk => $sl ) {
				echo '<option value="' . esc_attr( $sk ) . '"' . selected( $k->sphaere, $sk, false ) . '>' . esc_html( $sl ) . '</option>';
			}
			echo '</select>';
			echo '<label style="white-space:nowrap"><input type="checkbox" name="aktiv" value="1" ' . checked( $k->aktiv, 1, false ) . '> ' . esc_html__( 'aktiv', 'vereinsplugin' ) . '</label>';
			echo '<button class="vp-btn vp-btn-primary" name="vp_konto_save" value="1">' . esc_html__( 'Speichern', 'vereinsplugin' ) . '</button>';
			echo '</form>';
		}
		echo '</div>';
	}

	if ( $can_edit ) {
		echo '<details class="vp-card"><summary><strong>' . esc_html__( 'Konto hinzufügen', 'vereinsplugin' ) . '</strong></summary>';
		echo '<form method="post" class="vp-form" style="margin-top:10px">' . wp_nonce_field( 'vp_konten', 'vp_konten_nonce', true, false );
		echo '<div class="vp-form-grid"><label>' . esc_html__( 'Nummer', 'vereinsplugin' ) . '<input name="nummer"></label>';
		echo '<label>' . esc_html__( 'Bezeichnung', 'vereinsplugin' ) . '<input name="bezeichnung"></label>';
		echo '<label>' . esc_html__( 'Typ', 'vereinsplugin' ) . '<select name="typ"><option>einnahme</option><option selected>ausgabe</option><option>bestand</option><option>neutral</option></select></label>';
		echo '<label>' . esc_html__( 'Sphäre', 'vereinsplugin' ) . '<select name="sphaere">';
		foreach ( $sph as $sk => $sl ) {
			echo '<option value="' . esc_attr( $sk ) . '">' . esc_html( $sl ) . '</option>';
		}
		echo '</select></label></div><input type="hidden" name="aktiv" value="1">';
		echo '<p><button class="vp-btn vp-btn-primary" name="vp_konto_save" value="1">' . esc_html__( 'Anlegen', 'vereinsplugin' ) . '</button></p></form></details>';
	}

	echo '<h3>' . esc_html__( 'Stichwort-Regeln (Bank-Import)', 'vereinsplugin' ) . '</h3>';
	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Enthält', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Konto', 'vereinsplugin' ) . '</th>' . ( $can_edit ? '<th></th>' : '' ) . '</tr></thead><tbody>';
	foreach ( $regeln as $r ) {
		printf(
			'<tr><td>%s</td><td>%s</td>%s</tr>',
			esc_html( $r->stichwort ),
			esc_html( $r->konto ),
			$can_edit ? '<td><form method="post">' . wp_nonce_field( 'vp_konten', 'vp_konten_nonce', true, false ) . '<input type="hidden" name="id" value="' . (int) $r->id . '"><button class="vp-btn vp-btn-danger" name="vp_regel_del" value="1">✕</button></form></td>' : ''
		);
	}
	echo '</tbody></table></div>';
	if ( $can_edit ) {
		echo '<form method="post" class="vp-form">' . wp_nonce_field( 'vp_konten', 'vp_konten_nonce', true, false );
		echo '<div class="vp-form-grid"><label>' . esc_html__( 'Stichwort', 'vereinsplugin' ) . '<input name="stichwort"></label>';
		echo '<label>' . esc_html__( 'Konto-Nr.', 'vereinsplugin' ) . '<input name="regel_konto"></label></div>';
		echo '<p><button class="vp-btn vp-btn-primary" name="vp_regel_save" value="1">' . esc_html__( 'Regel hinzufügen', 'vereinsplugin' ) . '</button></p></form>';
	}
	return ob_get_clean();
}
