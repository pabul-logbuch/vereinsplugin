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

define( 'VP_SKR_DB_VERSION', '2' );

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
	if ( function_exists( 'jb_table_auslagen' ) ) {
		$add( jb_table_auslagen(), 'konto', "`konto` VARCHAR(10) NOT NULL DEFAULT ''" );
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
		'import'     => __( 'Bank-Import', 'vereinsplugin' ),
		'auswertung' => __( 'Auswertung', 'vereinsplugin' ),
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
	// zusätzliche Alt-Ansichten als Direktlink
	printf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=jb_getraenke' ) ), esc_html__( 'Getränkekasse ↗', 'vereinsplugin' ) );
	printf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=jb_budgets' ) ), esc_html__( 'Rücklagen ↗', 'vereinsplugin' ) );
	echo '</nav>';

	switch ( $view ) {
		case 'import':
			echo vp_bh_import(); // phpcs:ignore
			break;
		case 'auswertung':
			echo vp_bh_auswertung(); // phpcs:ignore
			break;
		case 'konten':
			echo vp_bh_konten(); // phpcs:ignore
			break;
		default:
			echo vp_bh_journal(); // phpcs:ignore
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

	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr>'
		. '<th>' . esc_html__( 'Datum', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Konto', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Gegenpartei / Zweck', 'vereinsplugin' ) . '</th>'
		. '<th style="text-align:right">' . esc_html__( 'Betrag', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Quelle', 'vereinsplugin' ) . '</th>'
		. ( $can_edit ? '<th></th>' : '' ) . '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$betrag = (float) $r['betrag'];
		printf(
			'<tr><td>%s</td><td>%s</td><td>%s<br><span class="vp-muted">%s</span></td><td style="text-align:right;%s">%s €</td><td class="vp-muted">%s</td>%s</tr>',
			esc_html( $r['buchung_datum'] ),
			esc_html( $r['konto'] ?: '–' ),
			esc_html( $r['gegenpartei'] ?? '' ),
			esc_html( wp_trim_words( (string) $r['beschreibung'], 14 ) ),
			$betrag < 0 ? 'color:#b91c1c' : 'color:#166534',
			esc_html( number_format( $betrag, 2, ',', '.' ) ),
			esc_html( $r['quelle'] ),
			$can_edit ? '<td><form method="post" onsubmit="return confirm(\'' . esc_js( __( 'Buchung löschen?', 'vereinsplugin' ) ) . '\')">' . wp_nonce_field( 'vp_bh_journal', 'vp_bh_nonce', true, false ) . '<input type="hidden" name="id" value="' . (int) $r['id'] . '"><button class="vp-btn vp-btn-danger" name="vp_bh_del" value="1">✕</button></form></td>' : ''
		);
	}
	if ( ! $rows ) {
		echo '<tr><td colspan="6" class="vp-muted">' . esc_html__( 'Keine Buchungen in diesem Jahr.', 'vereinsplugin' ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
	return ob_get_clean();
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
	$raw   = str_replace( "\r\n", "\n", trim( $raw ) );
	if ( '' === $raw ) {
		return array();
	}
	$lines = explode( "\n", $raw );
	$head  = str_getcsv( array_shift( $lines ), $delim );
	$head_l = array_map( function ( $h ) { return mb_strtolower( trim( $h ) ); }, $head );

	$find = function ( array $names ) use ( $head_l ) {
		foreach ( $head_l as $i => $h ) {
			foreach ( $names as $n ) {
				if ( false !== mb_strpos( $h, $n ) ) {
					return $i;
				}
			}
		}
		return -1;
	};
	$i_datum  = $find( array( 'buchungstag', 'buchungsdatum', 'datum', 'valuta' ) );
	$i_betrag = $find( array( 'betrag' ) );
	$i_name   = $find( array( 'beguenstigter', 'begünstigter', 'zahlungspflichtiger', 'name', 'auftraggeber', 'empfänger', 'empfaenger' ) );
	$i_zweck  = $find( array( 'verwendungszweck', 'zweck', 'buchungstext', 'vwz' ) );

	// Fallback: reine Spaltenreihenfolge Datum;Betrag;Name;Zweck
	if ( $i_datum < 0 && $i_betrag < 0 ) {
		$i_datum = 0; $i_betrag = 1; $i_name = 2; $i_zweck = 3;
		array_unshift( $lines, implode( $delim, $head ) ); // erste Zeile war doch Daten
	}

	$out = array();
	foreach ( $lines as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}
		$c = str_getcsv( $line, $delim );
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
