<?php
/**
 * Kern: Projekt-Kalkulation ↔ Kasse (Soll/Ist-Abgleich über Kostenstellen).
 *
 *  - Jedes Projekt bekommt eine **Kostenstelle** (vp_projekte.kostenstelle).
 *    Ein Projektbudget übernimmt sie; in der Buchhaltung steht sie in der
 *    Kostenstellen-Auswahl.
 *  - **Ist** eines Projekts = Buchungen mit dieser Kostenstelle oder auf dem
 *    Projektbudget + Auslagen auf dem Projektbudget (abgelehnte nicht; die
 *    Journalbuchung einer schon gezählten Auslage nicht doppelt).
 *  - Jede Ist-Zeile lässt sich einem **Kalkulationsposten** zuordnen oder als
 *    neuer Posten in die Kalkulation übernehmen (vp_projekt_ist).
 *  - Beim Buchen in der Kasse kann direkt ein Kalkulationsposten gewählt
 *    werden; die Kostenstelle wird dann ergänzt.
 *  - Kasse (Budgets & Kostenstellen) und Kreiskasse zeigen je Projekt
 *    Plan, Ist und Abweichung.
 */

defined( 'ABSPATH' ) || exit;

function vp_projekt_ist_table() {
	global $wpdb;
	return $wpdb->prefix . 'vp_projekt_ist';
}

add_action( 'plugins_loaded', 'vp_projekt_kasse_upgrade', 25 );
function vp_projekt_kasse_upgrade() {
	if ( get_option( 'vp_projekt_kasse_db' ) === '1' || ! function_exists( 'vp_projekt_table' ) ) {
		return;
	}
	global $wpdb;
	$t = vp_projekt_table();
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
		return; // Projekte-Tabelle kommt erst noch
	}
	if ( ! in_array( 'kostenstelle', (array) $wpdb->get_col( "SHOW COLUMNS FROM $t" ), true ) ) {
		$wpdb->query( "ALTER TABLE $t ADD COLUMN kostenstelle VARCHAR(50) NOT NULL DEFAULT '' AFTER budget_id" );
	}
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( 'CREATE TABLE ' . vp_projekt_ist_table() . " (
		id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		projekt_id BIGINT UNSIGNED NOT NULL,
		punkt_id   BIGINT UNSIGNED NOT NULL,
		quelle     VARCHAR(10) NOT NULL,
		quelle_id  BIGINT UNSIGNED NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY quelle (quelle, quelle_id),
		KEY projekt_id (projekt_id)
	) " . $wpdb->get_charset_collate() . ';' );
	update_option( 'vp_projekt_kasse_db', '1' );
}

/* =========================================================================
 * Kostenstelle
 * ====================================================================== */

function vp_projekt_ks( $p ) {
	return trim( (string) ( $p->kostenstelle ?? '' ) );
}

/** Vorschlag aus dem Titel, z. B. „Sommerfest 2026“ → SOMMERFEST26. */
function vp_projekt_ks_vorschlag( $p ) {
	$basis = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', remove_accents( preg_replace( '/\b(19|20)\d{2}\b/', '', (string) $p->titel ) ) ) );
	$jahr  = $p->beginn ? mysql2date( 'y', $p->beginn ) : current_time( 'y' );
	return substr( $basis ?: 'PROJEKT' . (int) $p->id, 0, 14 ) . $jahr;
}

/** Betrag „1.234,56“ / „1234.56“ / „12,5“ → float. */
function vp_projekt_betrag_parsen( $roh ) {
	$roh = trim( str_replace( array( '€', ' ', "\xc2\xa0" ), '', (string) $roh ) );
	if ( false !== strpos( $roh, ',' ) ) {
		$roh = str_replace( array( '.', ',' ), array( '', '.' ), $roh );
	}
	return (float) $roh;
}

/* =========================================================================
 * Ist-Werte
 * ====================================================================== */

/** Zuordnungen quelle:id => punkt_id eines Projekts. */
function vp_projekt_ist_map( $projekt_id ) {
	global $wpdb;
	$map = array();
	$t   = vp_projekt_ist_table();
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
		return $map;
	}
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT quelle, quelle_id, punkt_id FROM $t WHERE projekt_id = %d", $projekt_id ) ) as $r ) {
		$map[ $r->quelle . ':' . $r->quelle_id ] = (int) $r->punkt_id;
	}
	return $map;
}

/**
 * Tatsächliche Buchungen/Auslagen eines Projekts.
 * @return array[] ['quelle','id','datum','text','person','betrag'(mit Vorzeichen),'status','punkt_id']
 */
function vp_projekt_ist_zeilen( $p ) {
	global $wpdb;
	$out = array();
	$bid = (int) ( $p->budget_id ?? 0 );
	$ks  = vp_projekt_ks( $p );
	$map = vp_projekt_ist_map( $p->id );

	$auslage_ids = array();
	if ( $bid && function_exists( 'jb_table_auslagen' ) ) {
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT a.*, u.display_name AS user_name FROM ' . jb_table_auslagen() . " a LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id WHERE a.budget_id = %d AND a.status <> 'abgelehnt' ORDER BY a.ausgabe_datum",
			$bid
		) );
		foreach ( (array) $rows as $a ) {
			$auslage_ids[] = (int) $a->id;
			$out[]         = array(
				'quelle'   => 'auslage',
				'id'       => (int) $a->id,
				'datum'    => $a->ausgabe_datum,
				'text'     => trim( $a->beschreibung . ( ! empty( $a->haendler ) ? ' (' . $a->haendler . ')' : '' ) ),
				'person'   => (string) $a->user_name,
				'betrag'   => -abs( (float) $a->betrag ),
				'status'   => sprintf( /* translators: %s = Status */ __( 'Auslage · %s', 'vereinsplugin' ), $a->status ),
				'punkt_id' => $map[ 'auslage:' . $a->id ] ?? 0,
			);
		}
	}

	if ( function_exists( 'jb_table_journal' ) && ( $bid || '' !== $ks ) ) {
		$jt    = jb_table_journal();
		$cols  = (array) $wpdb->get_col( "SHOW COLUMNS FROM $jt" );
		$where = array();
		if ( $bid && in_array( 'budget_id', $cols, true ) ) {
			$where[] = $wpdb->prepare( 'budget_id = %d', $bid );
		}
		if ( '' !== $ks && in_array( 'kostenstelle', $cols, true ) ) {
			$where[] = $wpdb->prepare( 'kostenstelle = %s', $ks );
		}
		if ( $where ) {
			$sql = "SELECT * FROM $jt WHERE (" . implode( ' OR ', $where ) . ')';
			if ( $auslage_ids && in_array( 'auslage_id', $cols, true ) ) {
				$sql .= ' AND (auslage_id IS NULL OR auslage_id NOT IN (' . implode( ',', array_map( 'intval', $auslage_ids ) ) . '))';
			}
			foreach ( (array) $wpdb->get_results( $sql . ' ORDER BY buchung_datum' ) as $r ) {
				$out[] = array(
					'quelle'   => 'buchung',
					'id'       => (int) $r->id,
					'datum'    => $r->buchung_datum,
					'text'     => (string) $r->beschreibung,
					'person'   => (string) ( $r->gegenpartei ?? '' ),
					'betrag'   => (float) $r->betrag,
					'status'   => __( 'Buchung', 'vereinsplugin' ),
					'punkt_id' => $map[ 'buchung:' . $r->id ] ?? 0,
				);
			}
		}
	}
	usort( $out, function ( $a, $b ) { return strcmp( (string) $a['datum'], (string) $b['datum'] ); } );
	return $out;
}

/** Plan/Ist-Summen eines Projekts (+ Ist je Posten). */
function vp_projekt_soll_ist( $p, $posten = null ) {
	$posten = null === $posten ? vp_projekt_punkte( $p->id, 'kalkulation' ) : $posten;
	$ist    = vp_projekt_ist_zeilen( $p );
	$s      = array( 'plan_aus' => 0.0, 'plan_ein' => 0.0, 'ist_aus' => 0.0, 'ist_ein' => 0.0, 'ohne_posten' => 0, 'je_posten' => array(), 'ist' => $ist );
	foreach ( $posten as $x ) {
		if ( null !== $x->betrag ) {
			if ( (float) $x->betrag < 0 ) {
				$s['plan_aus'] += abs( (float) $x->betrag );
			} else {
				$s['plan_ein'] += (float) $x->betrag;
			}
		}
		$s['je_posten'][ (int) $x->id ] = 0.0;
	}
	foreach ( $ist as $z ) {
		if ( $z['betrag'] < 0 ) {
			$s['ist_aus'] += abs( $z['betrag'] );
		} else {
			$s['ist_ein'] += $z['betrag'];
		}
		if ( $z['punkt_id'] && isset( $s['je_posten'][ $z['punkt_id'] ] ) ) {
			$s['je_posten'][ $z['punkt_id'] ] += $z['betrag'];
		} else {
			$s['ohne_posten']++;
		}
	}
	return $s;
}

/* =========================================================================
 * Finanzen-Reiter des Projekts: Kostenstelle + Plan/Ist + Zuordnung
 * ====================================================================== */

function vp_projekt_ks_formular( $p ) {
	$ks = vp_projekt_ks( $p );
	echo '<div class="vp-projekt-ks">';
	vp_kreis_form( 'vp_projekt_ks_save', 'pp-inline-form' );
	printf(
		'<input type="hidden" name="projekt_id" value="%d"><label>%s <input type="text" name="kostenstelle" maxlength="50" value="%s" placeholder="%s" style="width:160px"></label> <button class="pp-btn pp-btn-small">%s</button>',
		(int) $p->id,
		esc_html__( 'Kostenstelle', 'vereinsplugin' ),
		esc_attr( $ks ?: vp_projekt_ks_vorschlag( $p ) ),
		esc_attr( vp_projekt_ks_vorschlag( $p ) ),
		esc_html( $ks ? __( 'ändern', 'vereinsplugin' ) : __( 'festlegen', 'vereinsplugin' ) )
	);
	echo '</form>';
	echo '<p class="pp-meta">' . ( $ks
		? esc_html( sprintf( /* translators: %s = Kostenstelle */ __( 'Alles, was in der Kasse mit der Kostenstelle „%s“ (oder auf das Projektbudget) gebucht wird, zählt unten als Ist.', 'vereinsplugin' ), $ks ) )
		: esc_html__( 'Mit einer Kostenstelle werden Buchungen in der Kasse diesem Projekt zugerechnet – auch ohne eigenes Budget.', 'vereinsplugin' ) ) . '</p>';
	echo '</div>';
}

/** Ist-Tabelle mit Zuordnung zu Kalkulationsposten. */
function vp_projekt_ist_tabelle( $p, $posten, array $ist ) {
	$optionen = function ( $sel ) use ( $posten ) {
		$h = '<option value="">' . esc_html__( '– keinem Posten –', 'vereinsplugin' ) . '</option>';
		foreach ( $posten as $x ) {
			$h .= '<option value="' . (int) $x->id . '"' . selected( (int) $sel, (int) $x->id, false ) . '>' . esc_html( $x->titel ) . '</option>';
		}
		return $h . '<option value="neu">' . esc_html__( '+ als neuen Posten übernehmen', 'vereinsplugin' ) . '</option>';
	};
	?>
	<table class="pp-table vp-projekt-ist">
		<thead><tr><th><?php esc_html_e( 'Datum', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Was', 'vereinsplugin' ); ?></th><th style="text-align:right"><?php esc_html_e( 'Betrag', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Kalkulationsposten', 'vereinsplugin' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $ist as $z ) : ?>
			<tr class="<?php echo $z['punkt_id'] ? '' : 'vp-ist-offen'; ?>">
				<td><?php echo esc_html( $z['datum'] ? mysql2date( 'd.m.Y', $z['datum'] ) : '' ); ?></td>
				<td><?php echo esc_html( $z['text'] ); ?><div class="pp-meta"><?php echo esc_html( trim( $z['status'] . ( $z['person'] ? ' · ' . $z['person'] : '' ) ) ); ?></div></td>
				<td style="text-align:right;<?php echo $z['betrag'] < 0 ? 'color:#b91c1c' : 'color:#15803d'; ?>"><?php echo esc_html( vp_kreis_eur( $z['betrag'] ) ); ?></td>
				<td>
					<?php vp_kreis_form( 'vp_projekt_ist_zuordnen', 'pp-inline-form vp-ist-form' ); ?>
						<input type="hidden" name="projekt_id" value="<?php echo (int) $p->id; ?>">
						<input type="hidden" name="quelle" value="<?php echo esc_attr( $z['quelle'] ); ?>">
						<input type="hidden" name="quelle_id" value="<?php echo (int) $z['id']; ?>">
						<select name="punkt_id" onchange="this.form.submit()"><?php echo $optionen( $z['punkt_id'] ); // phpcs:ignore ?></select>
						<noscript><button class="pp-btn pp-btn-small"><?php esc_html_e( 'OK', 'vereinsplugin' ); ?></button></noscript>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		<?php if ( ! $ist ) : ?>
			<tr><td colspan="4" class="pp-empty"><?php esc_html_e( 'Noch nichts gebucht. Buchungen mit der Kostenstelle des Projekts oder Auslagen auf dem Projektbudget erscheinen hier automatisch.', 'vereinsplugin' ); ?></td></tr>
		<?php endif; ?>
		</tbody>
	</table>
	<?php
}

add_action( 'admin_post_vp_projekt_ks_save', function () {
	vp_kreis_check( 'vp_projekt_ks_save' );
	global $wpdb;
	$p  = vp_projekt_oder_fehler( (int) $_POST['projekt_id'] );
	$ks = strtoupper( preg_replace( '/[^A-Za-z0-9._\-]/', '', sanitize_text_field( wp_unslash( $_POST['kostenstelle'] ?? '' ) ) ) );
	$ks = substr( $ks, 0, 50 );
	$wpdb->update( vp_projekt_table(), array( 'kostenstelle' => $ks ), array( 'id' => (int) $p->id ) );
	// Projektbudget zieht mit, damit Auslagen/Buchungen darauf dieselbe Kostenstelle tragen.
	if ( $p->budget_id && function_exists( 'jb_table_budgets' ) ) {
		$wpdb->update( jb_table_budgets(), array( 'kostenstelle' => $ks ), array( 'id' => (int) $p->budget_id ) );
	}
	vp_projekt_zurueck( $p, 'finanzen' );
} );

add_action( 'admin_post_vp_projekt_ist_zuordnen', function () {
	vp_kreis_check( 'vp_projekt_ist_zuordnen' );
	global $wpdb;
	$p      = vp_projekt_oder_fehler( (int) $_POST['projekt_id'] );
	$quelle = 'auslage' === ( $_POST['quelle'] ?? '' ) ? 'auslage' : 'buchung';
	$qid    = (int) ( $_POST['quelle_id'] ?? 0 );
	$ziel   = sanitize_key( $_POST['punkt_id'] ?? '' );

	// Nur Zeilen, die wirklich zu diesem Projekt gehören.
	$zeile = null;
	foreach ( vp_projekt_ist_zeilen( $p ) as $z ) {
		if ( $z['quelle'] === $quelle && $z['id'] === $qid ) {
			$zeile = $z;
		}
	}
	if ( ! $zeile ) {
		vp_kreis_fehler( __( 'Diese Buchung gehört nicht zu diesem Projekt.', 'vereinsplugin' ) );
	}

	$wpdb->delete( vp_projekt_ist_table(), array( 'quelle' => $quelle, 'quelle_id' => $qid ) );
	$punkt_id = 0;
	if ( 'neu' === $ziel ) {
		// Ausgabe/Einnahme als neuen Kalkulationsposten übernehmen (Plan = Ist).
		$wpdb->insert( vp_projekt_punkte_table(), array(
			'projekt_id'  => (int) $p->id,
			'bereich'     => 'kalkulation',
			'titel'       => wp_html_excerpt( $zeile['text'] ?: __( 'Posten', 'vereinsplugin' ), 250, '…' ),
			'kanal'       => $zeile['betrag'] < 0 ? 'ausgabe' : 'einnahme',
			'betrag'      => round( $zeile['betrag'], 2 ),
			'quelle'      => 'kasse',
			'erstellt_am' => current_time( 'mysql' ),
		) );
		$punkt_id = (int) $wpdb->insert_id;
	} elseif ( (int) $ziel ) {
		$punkt_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . vp_projekt_punkte_table() . " WHERE id = %d AND projekt_id = %d AND bereich = 'kalkulation'", (int) $ziel, $p->id ) );
	}
	if ( $punkt_id ) {
		$wpdb->insert( vp_projekt_ist_table(), array( 'projekt_id' => (int) $p->id, 'punkt_id' => $punkt_id, 'quelle' => $quelle, 'quelle_id' => $qid ) );
	}
	vp_projekt_zurueck( $p, 'finanzen' );
} );

// Posten gelöscht → Zuordnungen lösen.
add_action( 'admin_post_vp_projekt_punkt_delete', function () {
	global $wpdb;
	if ( isset( $_POST['punkt_id'] ) && current_user_can( 'pp_manage' ) ) {
		$wpdb->delete( vp_projekt_ist_table(), array( 'punkt_id' => (int) $_POST['punkt_id'] ) );
	}
}, 5 );

/* =========================================================================
 * Kasse: Soll/Ist je Projekt
 * ====================================================================== */

function vp_projekt_kassen_uebersicht( $gremium_id = null ) {
	if ( ! function_exists( 'vp_projekte_liste' ) ) {
		return '';
	}
	$args = array();
	if ( $gremium_id ) {
		$args['gremium_id'] = (int) $gremium_id;
	}
	$zeilen = '';
	$summe  = array( 0.0, 0.0 );
	foreach ( (array) vp_projekte_liste( $args ) as $p ) {
		if ( 'abgesagt' === $p->status ) {
			continue;
		}
		if ( $gremium_id && (int) $p->gremium_id !== (int) $gremium_id ) {
			continue;
		}
		$posten = vp_projekt_punkte( $p->id, 'kalkulation' );
		if ( ! $posten && ! vp_projekt_ks( $p ) && ! $p->budget_id ) {
			continue;
		}
		$s    = vp_projekt_soll_ist( $p, $posten );
		$plan = $s['plan_ein'] - $s['plan_aus'];
		$ist  = $s['ist_ein'] - $s['ist_aus'];
		$abw  = $ist - $plan;
		$summe[0] += $plan;
		$summe[1] += $ist;
		$url  = function_exists( 'vp_kreis_mitgliederbereich_url' )
			? vp_kreis_mitgliederbereich_url( array( 'vp_tab' => 'projekte', 'pp_view' => 'projekt', 'id' => (int) $p->id, 'p_tab' => 'finanzen' ) )
			: vp_projekt_url( $p->id, 'finanzen' );
		$zeilen .= sprintf(
			'<tr><td><a href="%s">%s</a>%s</td><td>%s</td><td style="text-align:right">%s<div class="pp-meta vp-muted">%s</div></td><td style="text-align:right">%s<div class="pp-meta vp-muted">%s</div></td><td style="text-align:right;color:%s"><strong>%s</strong></td></tr>',
			esc_url( $url ),
			esc_html( $p->titel ),
			$s['ohne_posten'] ? '<div class="pp-meta vp-muted">' . esc_html( sprintf( /* translators: %d = count */ _n( '%d Buchung ohne Posten', '%d Buchungen ohne Posten', $s['ohne_posten'], 'vereinsplugin' ), $s['ohne_posten'] ) ) . '</div>' : '',
			esc_html( vp_projekt_ks( $p ) ?: '–' ),
			esc_html( vp_kreis_eur( $plan ) ),
			esc_html( sprintf( '−%s / +%s', vp_kreis_eur( $s['plan_aus'] ), vp_kreis_eur( $s['plan_ein'] ) ) ),
			esc_html( vp_kreis_eur( $ist ) ),
			esc_html( sprintf( '−%s / +%s', vp_kreis_eur( $s['ist_aus'] ), vp_kreis_eur( $s['ist_ein'] ) ) ),
			$abw < 0 ? '#b91c1c' : '#15803d',
			esc_html( vp_kreis_eur( $abw ) )
		);
	}
	if ( '' === $zeilen ) {
		return '';
	}
	return '<h3 style="margin-top:28px">' . esc_html__( 'Projekte: Kalkulation ↔ Ist (Kostenstellen)', 'vereinsplugin' ) . '</h3>'
		. '<p class="pp-meta vp-muted">' . esc_html__( 'Ergebnis = Einnahmen − Ausgaben. Ist zählt Buchungen mit der Kostenstelle des Projekts bzw. auf dem Projektbudget und Auslagen darauf. Abweichung negativ = teurer als geplant.', 'vereinsplugin' ) . '</p>'
		. '<div class="vp-table-wrap"><table class="pp-table vp-table"><thead><tr><th>' . esc_html__( 'Projekt', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Kostenstelle', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Plan', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Ist', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Abweichung', 'vereinsplugin' ) . '</th></tr></thead><tbody>'
		. $zeilen
		. '</tbody><tfoot><tr><th colspan="2">' . esc_html__( 'Summe', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html( vp_kreis_eur( $summe[0] ) ) . '</th><th style="text-align:right">' . esc_html( vp_kreis_eur( $summe[1] ) ) . '</th><th style="text-align:right">' . esc_html( vp_kreis_eur( $summe[1] - $summe[0] ) ) . '</th></tr></tfoot></table></div>';
}

/* =========================================================================
 * Buchhaltung: Kostenstellen-Liste + Kalkulationsposten beim Buchen
 * ====================================================================== */

add_filter( 'jb_kostenstellen', function ( $ks ) {
	global $wpdb;
	if ( function_exists( 'vp_projekt_table' ) && get_option( 'vp_projekt_kasse_db' ) === '1' ) {
		$ks = array_merge( $ks, (array) $wpdb->get_col( 'SELECT DISTINCT kostenstelle FROM ' . vp_projekt_table() . " WHERE kostenstelle <> ''" ) );
	}
	$ks = array_values( array_unique( array_filter( array_map( 'strval', $ks ) ) ) );
	sort( $ks );
	return $ks;
} );

/** Kalkulationsposten laufender Projekte für die Auswahl beim Buchen. */
function vp_projekt_posten_auswahl() {
	global $wpdb;
	if ( ! function_exists( 'vp_projekt_table' ) || get_option( 'vp_projekt_kasse_db' ) !== '1' ) {
		return array();
	}
	return (array) $wpdb->get_results(
		'SELECT pu.id, pu.titel, pu.betrag, p.id AS projekt_id, p.titel AS projekt, p.kostenstelle FROM ' . vp_projekt_punkte_table() . ' pu JOIN ' . vp_projekt_table() . " p ON p.id = pu.projekt_id
		 WHERE pu.bereich = 'kalkulation' AND p.status NOT IN ('abgeschlossen','abgesagt')
		 ORDER BY p.beginn IS NULL, p.beginn, p.titel, pu.sortierung, pu.id"
	);
}

add_filter( 'vp_bh_buchung_extra_felder', function ( $html, $r ) {
	$posten = vp_projekt_posten_auswahl();
	if ( ! $posten ) {
		return $html;
	}
	global $wpdb;
	$sel = 0;
	if ( ! empty( $r['id'] ) ) {
		$sel = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT punkt_id FROM ' . vp_projekt_ist_table() . " WHERE quelle = 'buchung' AND quelle_id = %d", (int) $r['id'] ) );
	}
	$h       = '<label>' . esc_html__( 'Projekt-Kalkulationsposten (optional)', 'vereinsplugin' ) . '<select name="vp_projekt_punkt"><option value="0">' . esc_html__( '– keiner –', 'vereinsplugin' ) . '</option>';
	$projekt = null;
	foreach ( $posten as $x ) {
		if ( $x->projekt !== $projekt ) {
			$h      .= ( null !== $projekt ? '</optgroup>' : '' ) . '<optgroup label="' . esc_attr( $x->projekt . ( $x->kostenstelle ? ' · ' . $x->kostenstelle : '' ) ) . '">';
			$projekt = $x->projekt;
		}
		$plan = null !== $x->betrag ? ' (' . __( 'Plan', 'vereinsplugin' ) . ' ' . vp_kreis_eur( $x->betrag ) . ')' : '';
		$h   .= '<option value="' . (int) $x->id . '"' . selected( $sel, (int) $x->id, false ) . '>' . esc_html( $x->titel . $plan ) . '</option>';
	}
	return $html . $h . '</optgroup></select></label>';
}, 10, 2 );

add_action( 'vp_bh_buchung_gespeichert', function ( $buchung_id ) {
	if ( ! $buchung_id || ! isset( $_POST['vp_projekt_punkt'] ) ) {
		return;
	}
	global $wpdb;
	$punkt = (int) $_POST['vp_projekt_punkt'];
	$wpdb->delete( vp_projekt_ist_table(), array( 'quelle' => 'buchung', 'quelle_id' => (int) $buchung_id ) );
	if ( ! $punkt ) {
		return;
	}
	$x = $wpdb->get_row( $wpdb->prepare( 'SELECT pu.id, pu.projekt_id, p.kostenstelle FROM ' . vp_projekt_punkte_table() . ' pu JOIN ' . vp_projekt_table() . " p ON p.id = pu.projekt_id WHERE pu.id = %d AND pu.bereich = 'kalkulation'", $punkt ) );
	if ( ! $x ) {
		return;
	}
	// Damit die Buchung als Ist des Projekts zählt: Kostenstelle ergänzen.
	$ks = trim( (string) $x->kostenstelle );
	if ( '' === $ks ) {
		$p  = vp_projekt_get( $x->projekt_id );
		$ks = vp_projekt_ks_vorschlag( $p );
		$wpdb->update( vp_projekt_table(), array( 'kostenstelle' => $ks ), array( 'id' => (int) $x->projekt_id ) );
	}
	$jt = jb_table_journal();
	if ( in_array( 'kostenstelle', (array) $wpdb->get_col( "SHOW COLUMNS FROM $jt" ), true ) ) {
		$wpdb->query( $wpdb->prepare( "UPDATE $jt SET kostenstelle = %s WHERE id = %d AND (kostenstelle = '' OR kostenstelle IS NULL)", $ks, (int) $buchung_id ) );
	}
	$wpdb->insert( vp_projekt_ist_table(), array( 'projekt_id' => (int) $x->projekt_id, 'punkt_id' => (int) $x->id, 'quelle' => 'buchung', 'quelle_id' => (int) $buchung_id ) );
} );
