<?php
/**
 * Kern: Projekt- und Veranstaltungsorganizer.
 *
 * Ein Projekt gehört (optional) zu einem Kreis und verbindet, was es für eine
 * Veranstaltung braucht – ohne Daten doppelt zu halten:
 *
 *   Übersicht            Ziel, Eckdaten, Kennzahlen, Verknüpfungen
 *   Ablauf               vp_projekt_punkte (bereich=ablauf; Vorbereitung,
 *                        Ablauf am Tag, Nachbereitung)
 *   ToDos                pp_aufgaben.projekt_id → erscheinen auch in „Meine
 *                        Aufgaben", beim Kreis und im Kalender-Abo
 *   Helfende & Schichten vp_projekt_helfende + verknüpfter Schichtplan
 *                        (wl_shift_events)
 *   Öffentlichkeitsarbeit vp_projekt_punkte (bereich=oeffentlichkeit) +
 *                        Veranstaltungs-Beitrag (CPT „veranstaltung")
 *   Finanzen             vp_projekt_punkte (bereich=kalkulation) + Budget
 *                        des Kreises (jb_budgets.gremium_id)
 *
 * Außerdem verknüpfbar: Termin (pp_termine) und Anmeldeformular (vp_formulare).
 *
 * Routing: ?pp_view=projekte  bzw.  ?pp_view=projekt&id=<id>&p_tab=<tab>
 * Rechte:  wie der Protokollbereich (pp_manage); Budgets nur mit Kassenrecht
 *          des Kreises (vp_kreis_darf_kasse), Anmeldeformulare nur mit
 *          vp_manage_formulare.
 */

defined( 'ABSPATH' ) || exit;

define( 'VP_PROJEKT_DB_VERSION', '1' );

function vp_projekt_table()          { global $wpdb; return $wpdb->prefix . 'vp_projekte'; }
function vp_projekt_punkte_table()   { global $wpdb; return $wpdb->prefix . 'vp_projekt_punkte'; }
function vp_projekt_helfende_table() { global $wpdb; return $wpdb->prefix . 'vp_projekt_helfende'; }

add_action( 'plugins_loaded', 'vp_projekte_maybe_upgrade', 6 );
function vp_projekte_maybe_upgrade() {
	if ( get_option( 'vp_projekt_db_version' ) === VP_PROJEKT_DB_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$collate = $wpdb->get_charset_collate();

	dbDelta( 'CREATE TABLE ' . vp_projekt_table() . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		gremium_id BIGINT UNSIGNED DEFAULT NULL,
		titel VARCHAR(190) NOT NULL DEFAULT '',
		art VARCHAR(20) NOT NULL DEFAULT 'veranstaltung',
		status VARCHAR(20) NOT NULL DEFAULT 'idee',
		ziel TEXT NULL,
		beschreibung TEXT NULL,
		zielgruppe VARCHAR(190) NOT NULL DEFAULT '',
		ort VARCHAR(190) NOT NULL DEFAULT '',
		beginn DATETIME NULL,
		ende DATETIME NULL,
		helfende_bedarf SMALLINT UNSIGNED DEFAULT NULL,
		teilnehmende_erwartet INT UNSIGNED DEFAULT NULL,
		verantwortlich_user_id BIGINT UNSIGNED DEFAULT NULL,
		budget_id BIGINT UNSIGNED DEFAULT NULL,
		termin_id BIGINT UNSIGNED DEFAULT NULL,
		schicht_event_id BIGINT UNSIGNED DEFAULT NULL,
		veranstaltung_post_id BIGINT UNSIGNED DEFAULT NULL,
		formular_id BIGINT UNSIGNED DEFAULT NULL,
		notizen TEXT NULL,
		erstellt_von BIGINT UNSIGNED DEFAULT NULL,
		erstellt_am DATETIME NULL,
		geaendert_am DATETIME NULL,
		PRIMARY KEY  (id),
		KEY gremium_id (gremium_id),
		KEY status (status)
	) {$collate};" );

	// Ein Tabelle für drei Listen gleicher Form: Ablauf, Öffentlichkeitsarbeit,
	// Kostenkalkulation. `bereich` trennt sie, nicht jede Spalte gilt überall.
	dbDelta( 'CREATE TABLE ' . vp_projekt_punkte_table() . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		projekt_id BIGINT UNSIGNED NOT NULL,
		bereich VARCHAR(20) NOT NULL DEFAULT 'ablauf',
		phase VARCHAR(20) NOT NULL DEFAULT '',
		titel VARCHAR(255) NOT NULL DEFAULT '',
		beschreibung TEXT NULL,
		zeitpunkt DATETIME NULL,
		dauer_minuten SMALLINT UNSIGNED DEFAULT NULL,
		kanal VARCHAR(40) NOT NULL DEFAULT '',
		betrag DECIMAL(10,2) DEFAULT NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'offen',
		verantwortlich_user_id BIGINT UNSIGNED DEFAULT NULL,
		sortierung INT NOT NULL DEFAULT 0,
		erstellt_am DATETIME NULL,
		PRIMARY KEY  (id),
		KEY projekt_id (projekt_id),
		KEY bereich (bereich)
	) {$collate};" );

	dbDelta( 'CREATE TABLE ' . vp_projekt_helfende_table() . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		projekt_id BIGINT UNSIGNED NOT NULL,
		user_id BIGINT UNSIGNED DEFAULT NULL,
		name VARCHAR(150) NOT NULL DEFAULT '',
		kontakt VARCHAR(190) NOT NULL DEFAULT '',
		aufgabe VARCHAR(190) NOT NULL DEFAULT '',
		status VARCHAR(20) NOT NULL DEFAULT 'angefragt',
		notiz TEXT NULL,
		erstellt_am DATETIME NULL,
		PRIMARY KEY  (id),
		KEY projekt_id (projekt_id),
		KEY user_id (user_id)
	) {$collate};" );

	update_option( 'vp_projekt_db_version', VP_PROJEKT_DB_VERSION );
}

/* -------------------------------------------------------------------------
 * Wertelisten
 * ---------------------------------------------------------------------- */

function vp_projekt_arten() {
	return array(
		'veranstaltung' => __( 'Veranstaltung', 'vereinsplugin' ),
		'projekt'       => __( 'Projekt', 'vereinsplugin' ),
		'aktion'        => __( 'Aktion / Kampagne', 'vereinsplugin' ),
		'workshop'      => __( 'Workshop / Seminar', 'vereinsplugin' ),
		'fahrt'         => __( 'Fahrt / Ausflug', 'vereinsplugin' ),
	);
}

function vp_projekt_status_liste() {
	return array(
		'idee'          => __( 'Idee', 'vereinsplugin' ),
		'planung'       => __( 'In Planung', 'vereinsplugin' ),
		'vorbereitung'  => __( 'Vorbereitung läuft', 'vereinsplugin' ),
		'laeuft'        => __( 'Findet statt', 'vereinsplugin' ),
		'nachbereitung' => __( 'Nachbereitung', 'vereinsplugin' ),
		'abgeschlossen' => __( 'Abgeschlossen', 'vereinsplugin' ),
		'abgesagt'      => __( 'Abgesagt', 'vereinsplugin' ),
	);
}

function vp_projekt_status_label( $s ) {
	$l = vp_projekt_status_liste();
	return $l[ $s ] ?? $s;
}

function vp_projekt_phasen() {
	return array(
		'vorbereitung'  => __( 'Vorbereitung', 'vereinsplugin' ),
		'durchfuehrung' => __( 'Ablauf am Tag', 'vereinsplugin' ),
		'nachbereitung' => __( 'Nachbereitung', 'vereinsplugin' ),
	);
}

function vp_projekt_kanaele() {
	return array(
		'website'     => __( 'Website / Kalender', 'vereinsplugin' ),
		'social'      => __( 'Social Media', 'vereinsplugin' ),
		'presse'      => __( 'Presse', 'vereinsplugin' ),
		'plakat'      => __( 'Plakat / Flyer', 'vereinsplugin' ),
		'newsletter'  => __( 'Newsletter', 'vereinsplugin' ),
		'messenger'   => __( 'Messenger-Gruppen', 'vereinsplugin' ),
		'kooperation' => __( 'Partner & Kooperationen', 'vereinsplugin' ),
		'sonstiges'   => __( 'Sonstiges', 'vereinsplugin' ),
	);
}

function vp_projekt_punkt_status() {
	return array(
		'offen'     => __( 'offen', 'vereinsplugin' ),
		'in_arbeit' => __( 'in Arbeit', 'vereinsplugin' ),
		'erledigt'  => __( 'erledigt', 'vereinsplugin' ),
	);
}

function vp_projekt_helfende_status() {
	return array(
		'angefragt' => __( 'angefragt', 'vereinsplugin' ),
		'zugesagt'  => __( 'zugesagt', 'vereinsplugin' ),
		'abgesagt'  => __( 'abgesagt', 'vereinsplugin' ),
	);
}

/* -------------------------------------------------------------------------
 * Daten
 * ---------------------------------------------------------------------- */

function vp_projekt_get( $id ) {
	global $wpdb;
	return $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vp_projekt_table() . ' WHERE id = %d', (int) $id ) ) : null;
}

/**
 * @param array $args gremium_id, status, laufend (bool), beteiligt (user_id)
 */
function vp_projekte_liste( $args = array() ) {
	global $wpdb;
	$where = array( '1=1' );
	if ( ! empty( $args['gremium_id'] ) ) {
		$where[] = $wpdb->prepare( 'p.gremium_id = %d', (int) $args['gremium_id'] );
	}
	if ( ! empty( $args['status'] ) ) {
		$where[] = $wpdb->prepare( 'p.status = %s', $args['status'] );
	}
	if ( ! empty( $args['laufend'] ) ) {
		$where[] = "p.status NOT IN ('abgeschlossen','abgesagt')";
	}
	if ( ! empty( $args['beteiligt'] ) ) {
		$uid     = (int) $args['beteiligt'];
		$where[] = $wpdb->prepare(
			'( p.verantwortlich_user_id = %d
			   OR p.id IN (SELECT projekt_id FROM ' . vp_projekt_helfende_table() . " WHERE user_id = %d AND status != 'abgesagt')
			   OR p.gremium_id IN (SELECT gremium_id FROM {$wpdb->prefix}pp_kreis_mitglieder WHERE user_id = %d AND ausgetreten_am IS NULL) )",
			$uid,
			$uid,
			$uid
		);
	}
	return $wpdb->get_results(
		'SELECT p.*, g.name AS gremium_name FROM ' . vp_projekt_table() . " p
		 LEFT JOIN {$wpdb->prefix}pp_gremien g ON g.id = p.gremium_id
		 WHERE " . implode( ' AND ', $where ) . "
		 ORDER BY p.status IN ('abgeschlossen','abgesagt'), p.beginn IS NULL, p.beginn, p.id DESC"
	);
}

function vp_projekt_punkte( $projekt_id, $bereich ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		'SELECT * FROM ' . vp_projekt_punkte_table() . ' WHERE projekt_id = %d AND bereich = %s ORDER BY zeitpunkt IS NULL, zeitpunkt, sortierung, id',
		$projekt_id,
		$bereich
	) );
}

function vp_projekt_helfende( $projekt_id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		'SELECT * FROM ' . vp_projekt_helfende_table() . " WHERE projekt_id = %d ORDER BY FIELD(status,'zugesagt','angefragt','abgesagt'), name, id",
		$projekt_id
	) );
}

function vp_projekt_todos( $projekt_id ) {
	global $wpdb;
	if ( ! function_exists( 'vp_kreis_col_exists' ) || ! vp_kreis_col_exists( $wpdb->prefix . 'pp_aufgaben', 'projekt_id' ) ) {
		return array();
	}
	return $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}pp_aufgaben WHERE projekt_id = %d ORDER BY status = 'erledigt', faelligkeitsdatum IS NULL, faelligkeitsdatum, id",
		$projekt_id
	) );
}

function vp_projekt_url( $id, $tab = 'uebersicht', $extra = array() ) {
	return pp_front_url( array_merge( array( 'pp_view' => 'projekt', 'id' => (int) $id, 'p_tab' => $tab ), $extra ) );
}

/** datetime-local → MySQL (oder null). */
function vp_projekt_dt_in( $raw ) {
	$raw = trim( sanitize_text_field( wp_unslash( (string) $raw ) ) );
	if ( '' === $raw || false === strtotime( $raw ) ) {
		return null;
	}
	return gmdate( 'Y-m-d H:i:s', strtotime( str_replace( 'T', ' ', $raw ) . ' UTC' ) );
}

function vp_projekt_dt_out( $dt ) {
	return $dt ? mysql2date( 'Y-m-d\TH:i', $dt ) : '';
}

function vp_projekt_zeitraum( $p ) {
	if ( ! $p->beginn ) {
		return __( 'Datum offen', 'vereinsplugin' );
	}
	$txt = mysql2date( 'D d.m.Y, H:i', $p->beginn );
	if ( $p->ende ) {
		$txt .= ' – ' . ( mysql2date( 'Y-m-d', $p->ende ) === mysql2date( 'Y-m-d', $p->beginn ) ? mysql2date( 'H:i', $p->ende ) : mysql2date( 'D d.m.Y, H:i', $p->ende ) );
	}
	return $txt;
}

/** Tage relativ zum Beginn → MySQL-DATETIME (für Vorschläge). */
function vp_projekt_relativ( $p, $tage, $uhrzeit = null ) {
	$ts = strtotime( $p->beginn . ' ' . ( $tage >= 0 ? '+' : '' ) . (int) $tage . ' days' );
	return gmdate( 'Y-m-d', $ts ) . ' ' . ( $uhrzeit ?: gmdate( 'H:i', $ts ) ) . ':00';
}

/** Schichtplan-Zahlen eines verknüpften Events. */
function vp_projekt_schichtplan( $event_id ) {
	if ( ! $event_id || ! function_exists( 'wl_get_event_full' ) ) {
		return null;
	}
	$e = wl_get_event_full( $event_id );
	if ( ! $e ) {
		return null;
	}
	$s = array( 'event' => $e, 'stationen' => count( $e->stationen ), 'schichten' => 0, 'plaetze' => 0, 'belegt' => 0, 'luecken' => array(), 'personen' => array() );
	foreach ( $e->stationen as $st ) {
		foreach ( $st->schichten as $sch ) {
			$s['schichten']++;
			$s['plaetze'] += (int) $sch->max_plaetze;
			$s['belegt']  += min( (int) $sch->belegt, (int) $sch->max_plaetze );
			$soll = (int) $sch->min_plaetze ?: (int) $sch->max_plaetze;
			if ( (int) $sch->belegt < $soll ) {
				$s['luecken'][] = array( 'station' => $st->titel, 'schicht' => $sch, 'fehlt' => $soll - (int) $sch->belegt );
			}
			foreach ( $sch->eintragungen as $ein ) {
				$key = $ein->user_id ? 'u' . $ein->user_id : ( $ein->email ? strtolower( $ein->email ) : strtolower( $ein->name ) );
				if ( ! isset( $s['personen'][ $key ] ) ) {
					$s['personen'][ $key ] = array( 'name' => $ein->name, 'email' => $ein->email, 'user_id' => (int) $ein->user_id, 'n' => 0 );
				}
				$s['personen'][ $key ]['n']++;
			}
		}
	}
	return $s;
}

function vp_projekt_kennzahlen( $p ) {
	$todos    = vp_projekt_todos( $p->id );
	$erledigt = count( array_filter( $todos, function ( $t ) { return 'erledigt' === $t->status; } ) );
	$helfende = vp_projekt_helfende( $p->id );
	$zugesagt = count( array_filter( $helfende, function ( $h ) { return 'zugesagt' === $h->status; } ) );
	$k = array(
		'todos'       => count( $todos ),
		'todos_offen' => count( $todos ) - $erledigt,
		'erledigt'    => $erledigt,
		'zugesagt'    => $zugesagt,
		'angefragt'   => count( array_filter( $helfende, function ( $h ) { return 'angefragt' === $h->status; } ) ),
		'schicht'     => vp_projekt_schichtplan( $p->schicht_event_id ),
		'budget'      => function_exists( 'vp_kreis_budget' ) ? vp_kreis_budget( $p->budget_id ) : null,
		'anmeldungen' => ( $p->formular_id && function_exists( 'vp_formular_anzahl_eintraege' ) ) ? (int) vp_formular_anzahl_eintraege( $p->formular_id ) : null,
	);
	return $k;
}

function vp_projekt_darf_budget( $p ) {
	return function_exists( 'vp_kreis_kasse_verfuegbar' ) && vp_kreis_kasse_verfuegbar() && vp_kreis_darf_kasse( (int) $p->gremium_id );
}

/* -------------------------------------------------------------------------
 * Einbindung in den Protokollbereich
 * ---------------------------------------------------------------------- */

add_filter( 'pp_front_views', function ( $views ) {
	$views[] = 'projekte';
	$views[] = 'projekt';
	return $views;
} );

add_filter( 'pp_front_nav_punkte', function ( $punkte ) {
	$neu = array();
	foreach ( $punkte as $k => $v ) {
		$neu[ $k ] = $v;
		if ( 'kreise' === $k ) {
			$neu['projekte'] = array( __( 'Projekte & Veranstaltungen', 'vereinsplugin' ), '' );
		}
	}
	if ( ! isset( $neu['projekte'] ) ) {
		$neu['projekte'] = array( __( 'Projekte & Veranstaltungen', 'vereinsplugin' ), '' );
	}
	return $neu;
} );

add_action( 'pp_render_view_projekte', 'vp_render_view_projekte' );
add_action( 'pp_render_view_projekt', 'vp_render_view_projekt' );
add_action( 'pp_dashboard_cards', 'vp_projekt_dashboard_card' );

function vp_projekt_dashboard_card() {
	$projekte = vp_projekte_liste( array( 'laufend' => true, 'beteiligt' => get_current_user_id() ) );
	?>
	<div class="pp-card">
		<h3><?php esc_html_e( 'Meine Projekte', 'vereinsplugin' ); ?></h3>
		<?php if ( $projekte ) : ?>
			<ul class="pp-list">
				<?php foreach ( array_slice( $projekte, 0, 6 ) as $p ) : ?>
					<li><a href="<?php echo esc_url( vp_projekt_url( $p->id ) ); ?>"><?php echo esc_html( $p->titel ); ?></a>
						<span class="pp-meta"><?php echo esc_html( vp_projekt_status_label( $p->status ) . ' · ' . vp_projekt_zeitraum( $p ) . ( $p->gremium_name ? ' · ' . $p->gremium_name : '' ) ); ?></span></li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="pp-empty"><?php esc_html_e( 'Kein laufendes Projekt in deinen Kreisen.', 'vereinsplugin' ); ?></p>
		<?php endif; ?>
		<p><a class="pp-btn pp-btn-small" href="<?php echo esc_url( pp_front_url( array( 'pp_view' => 'projekte' ) ) ); ?>"><?php esc_html_e( 'Alle Projekte', 'vereinsplugin' ); ?></a></p>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Ansicht: Projektliste
 * ---------------------------------------------------------------------- */

function vp_render_view_projekte() {
	$gid    = isset( $_GET['p_kreis'] ) ? (int) $_GET['p_kreis'] : 0;
	$filter = isset( $_GET['p_status'] ) ? sanitize_key( wp_unslash( $_GET['p_status'] ) ) : 'laufend';
	$args   = array( 'gremium_id' => $gid );
	if ( 'laufend' === $filter ) {
		$args['laufend'] = true;
	} elseif ( isset( vp_projekt_status_liste()[ $filter ] ) ) {
		$args['status'] = $filter;
	}
	$projekte = vp_projekte_liste( $args );
	?>
	<div class="pp-page-head">
		<h2><?php esc_html_e( 'Projekte & Veranstaltungen', 'vereinsplugin' ); ?></h2>
		<a class="pp-btn pp-btn-primary" href="#vp-projekt-neu"><?php esc_html_e( 'Projekt anlegen', 'vereinsplugin' ); ?></a>
	</div>
	<p class="pp-meta"><?php esc_html_e( 'Alles für eine Veranstaltung an einem Ort: Ziel, Ablauf, ToDos, Helfende und Schichten, Öffentlichkeitsarbeit und Geld. Projekte gehören zu einem Kreis.', 'vereinsplugin' ); ?></p>

	<form method="get" class="pp-filterleiste">
		<?php foreach ( array( 'vp_tab', 'page_id', 'p' ) as $keep ) : ?>
			<?php if ( isset( $_GET[ $keep ] ) ) : ?><input type="hidden" name="<?php echo esc_attr( $keep ); ?>" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET[ $keep ] ) ) ); ?>"><?php endif; ?>
		<?php endforeach; ?>
		<input type="hidden" name="pp_view" value="projekte">
		<label><?php esc_html_e( 'Kreis', 'vereinsplugin' ); ?><select name="p_kreis"><?php echo vp_kreis_optionen( $gid, __( 'alle Kreise', 'vereinsplugin' ) ); // phpcs:ignore ?></select></label>
		<label><?php esc_html_e( 'Status', 'vereinsplugin' ); ?><select name="p_status">
			<option value="laufend" <?php selected( $filter, 'laufend' ); ?>><?php esc_html_e( 'laufend', 'vereinsplugin' ); ?></option>
			<option value="alle" <?php selected( $filter, 'alle' ); ?>><?php esc_html_e( 'alle', 'vereinsplugin' ); ?></option>
			<?php foreach ( vp_projekt_status_liste() as $k => $l ) : ?>
				<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filter, $k ); ?>><?php echo esc_html( $l ); ?></option>
			<?php endforeach; ?>
		</select></label>
		<button type="submit" class="pp-btn pp-btn-small"><?php esc_html_e( 'Filtern', 'vereinsplugin' ); ?></button>
	</form>

	<?php vp_projekt_render_karten( $projekte, true ); ?>

	<h3 id="vp-projekt-neu"><?php esc_html_e( 'Neues Projekt', 'vereinsplugin' ); ?></h3>
	<?php
	vp_projekt_render_neu( $gid );
}

/** Projekte eines Kreises (Reiter auf der Kreis-Seite). */
function vp_projekt_render_liste( $gremium_id ) {
	$alle     = vp_projekte_liste( array( 'gremium_id' => (int) $gremium_id ) );
	$laufend  = array_filter( $alle, function ( $p ) { return ! in_array( $p->status, array( 'abgeschlossen', 'abgesagt' ), true ); } );
	$vorbei   = array_filter( $alle, function ( $p ) { return in_array( $p->status, array( 'abgeschlossen', 'abgesagt' ), true ); } );
	?>
	<h3><?php esc_html_e( 'Projekte & Veranstaltungen des Kreises', 'vereinsplugin' ); ?></h3>
	<?php vp_projekt_render_karten( $laufend, false ); ?>
	<?php if ( $vorbei ) : ?>
		<details class="pp-werkzeug">
			<summary class="pp-details-summary"><?php echo esc_html( sprintf( __( 'Abgeschlossen & abgesagt (%d)', 'vereinsplugin' ), count( $vorbei ) ) ); ?></summary>
			<?php vp_projekt_render_karten( $vorbei, false ); ?>
		</details>
	<?php endif; ?>
	<details class="pp-werkzeug" <?php echo $alle ? '' : 'open'; ?>>
		<summary class="pp-details-summary"><?php esc_html_e( '+ Neues Projekt für diesen Kreis', 'vereinsplugin' ); ?></summary>
		<?php vp_projekt_render_neu( (int) $gremium_id ); ?>
	</details>
	<?php
}

function vp_projekt_render_karten( $projekte, $zeige_kreis ) {
	if ( ! $projekte ) {
		echo '<p class="pp-empty">' . esc_html__( 'Keine Projekte.', 'vereinsplugin' ) . '</p>';
		return;
	}
	$arten = vp_projekt_arten();
	echo '<div class="pp-cards">';
	foreach ( $projekte as $p ) {
		$k    = vp_projekt_kennzahlen( $p );
		$quot = $k['todos'] ? round( 100 * $k['erledigt'] / $k['todos'] ) : 0;
		?>
		<div class="pp-card pp-projekt-karte">
			<div class="pp-projekt-karte-kopf">
				<span class="pp-badge pp-status-<?php echo esc_attr( $p->status ); ?>"><?php echo esc_html( vp_projekt_status_label( $p->status ) ); ?></span>
				<span class="pp-meta"><?php echo esc_html( $arten[ $p->art ] ?? $p->art ); ?></span>
			</div>
			<h3><a href="<?php echo esc_url( vp_projekt_url( $p->id ) ); ?>"><?php echo esc_html( $p->titel ); ?></a></h3>
			<p class="pp-meta"><?php echo esc_html( vp_projekt_zeitraum( $p ) . ( $p->ort ? ' · ' . $p->ort : '' ) . ( $zeige_kreis && ! empty( $p->gremium_name ) ? ' · ' . $p->gremium_name : '' ) ); ?></p>
			<?php if ( $p->ziel ) : ?><p><?php echo esc_html( wp_trim_words( $p->ziel, 20 ) ); ?></p><?php endif; ?>
			<div class="pp-progress" title="<?php echo esc_attr( sprintf( __( '%1$d von %2$d ToDos erledigt', 'vereinsplugin' ), $k['erledigt'], $k['todos'] ) ); ?>"><span style="width:<?php echo (int) $quot; ?>%"></span></div>
			<p class="pp-meta">
				<?php
				echo esc_html( sprintf( __( 'ToDos %1$d/%2$d', 'vereinsplugin' ), $k['erledigt'], $k['todos'] ) );
				echo ' · ' . esc_html( sprintf( __( 'Helfende %1$d%2$s', 'vereinsplugin' ), $k['zugesagt'], $p->helfende_bedarf ? '/' . (int) $p->helfende_bedarf : '' ) );
				if ( $k['schicht'] ) {
					echo ' · ' . esc_html( sprintf( __( 'Schichten %1$d/%2$d', 'vereinsplugin' ), $k['schicht']['belegt'], $k['schicht']['plaetze'] ) );
				}
				?>
			</p>
		</div>
		<?php
	}
	echo '</div>';
}

function vp_projekt_render_neu( $gremium_id ) {
	vp_kreis_form( 'vp_projekt_save', 'pp-form pp-form-grid' );
	?>
		<input type="hidden" name="id" value="0">
		<label class="pp-span-2"><?php esc_html_e( 'Titel *', 'vereinsplugin' ); ?><input type="text" name="titel" required placeholder="<?php esc_attr_e( 'z. B. Sommerfest 2027', 'vereinsplugin' ); ?>"></label>
		<label><?php esc_html_e( 'Art', 'vereinsplugin' ); ?><select name="art">
			<?php foreach ( vp_projekt_arten() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?>
		</select></label>
		<label><?php esc_html_e( 'Kreis', 'vereinsplugin' ); ?><select name="gremium_id"><?php echo vp_kreis_optionen( $gremium_id ); // phpcs:ignore ?></select></label>
		<label><?php esc_html_e( 'Beginn', 'vereinsplugin' ); ?><input type="datetime-local" name="beginn"></label>
		<label><?php esc_html_e( 'Ende', 'vereinsplugin' ); ?><input type="datetime-local" name="ende"></label>
		<label><?php esc_html_e( 'Ort', 'vereinsplugin' ); ?><input type="text" name="ort"></label>
		<label><?php esc_html_e( 'Verantwortlich', 'vereinsplugin' ); ?><select name="verantwortlich_user_id"><?php echo vp_kreis_personen_optionen( get_current_user_id(), $gremium_id ); // phpcs:ignore ?></select></label>
		<label class="pp-span-2"><?php esc_html_e( 'Ziel – was soll am Ende erreicht sein?', 'vereinsplugin' ); ?><textarea name="ziel" rows="2"></textarea></label>
		<input type="hidden" name="status" value="idee">
		<div class="pp-form-actions"><button type="submit" class="pp-btn pp-btn-primary"><?php esc_html_e( 'Projekt anlegen', 'vereinsplugin' ); ?></button></div>
	</form>
	<?php
}

/* -------------------------------------------------------------------------
 * Ansicht: einzelnes Projekt
 * ---------------------------------------------------------------------- */

function vp_render_view_projekt() {
	$p = vp_projekt_get( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0 );
	if ( ! $p ) {
		echo '<p class="pp-empty">' . esc_html__( 'Projekt nicht gefunden.', 'vereinsplugin' ) . '</p>';
		return;
	}
	$k     = vp_projekt_kennzahlen( $p );
	$namen = vp_kreis_namen();
	$arten = vp_projekt_arten();
	$tabs  = array(
		'uebersicht'      => __( 'Übersicht', 'vereinsplugin' ),
		'ablauf'          => __( 'Ablauf', 'vereinsplugin' ),
		'todos'           => __( 'ToDos', 'vereinsplugin' ) . ( $k['todos_offen'] ? ' (' . $k['todos_offen'] . ')' : '' ),
		'helfende'        => __( 'Helfende & Schichten', 'vereinsplugin' ),
		'oeffentlichkeit' => __( 'Öffentlichkeitsarbeit', 'vereinsplugin' ),
		'finanzen'        => __( 'Finanzen', 'vereinsplugin' ),
	);
	$tab = isset( $_GET['p_tab'] ) ? sanitize_key( wp_unslash( $_GET['p_tab'] ) ) : 'uebersicht';
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = 'uebersicht';
	}
	$zurueck = $p->gremium_id ? vp_kreis_url( $p->gremium_id, 'projekte' ) : pp_front_url( array( 'pp_view' => 'projekte' ) );
	?>
	<div class="pp-page-head">
		<h2><?php echo esc_html( $p->titel ); ?></h2>
		<a class="pp-btn pp-btn-small" href="<?php echo esc_url( $zurueck ); ?>"><?php echo $p->gremium_id ? esc_html( sprintf( __( '← %s', 'vereinsplugin' ), $namen[ (int) $p->gremium_id ] ?? __( 'Kreis', 'vereinsplugin' ) ) ) : esc_html__( '← Alle Projekte', 'vereinsplugin' ); ?></a>
	</div>
	<p class="pp-meta">
		<span class="pp-badge pp-status-<?php echo esc_attr( $p->status ); ?>"><?php echo esc_html( vp_projekt_status_label( $p->status ) ); ?></span>
		<?php echo esc_html( ( $arten[ $p->art ] ?? $p->art ) . ' · ' . vp_projekt_zeitraum( $p ) . ( $p->ort ? ' · ' . $p->ort : '' ) . ( $p->verantwortlich_user_id ? ' · ' . sprintf( __( 'verantwortlich: %s', 'vereinsplugin' ), pp_user_display_name( $p->verantwortlich_user_id ) ) : '' ) ); ?>
	</p>

	<nav class="pp-tabs">
		<?php foreach ( $tabs as $key => $label ) : ?>
			<a class="pp-tab<?php echo $key === $tab ? ' is-active' : ''; ?>" href="<?php echo esc_url( vp_projekt_url( $p->id, $key ) ); ?>"><?php echo esc_html( $label ); ?></a>
		<?php endforeach; ?>
	</nav>
	<?php
	switch ( $tab ) {
		case 'ablauf':
			vp_projekt_tab_ablauf( $p );
			break;
		case 'todos':
			vp_projekt_tab_todos( $p );
			break;
		case 'helfende':
			vp_projekt_tab_helfende( $p, $k );
			break;
		case 'oeffentlichkeit':
			vp_projekt_tab_oeffentlichkeit( $p );
			break;
		case 'finanzen':
			vp_projekt_tab_finanzen( $p, $k );
			break;
		default:
			vp_projekt_tab_uebersicht( $p, $k );
	}
}

/* ---- Übersicht ---- */

function vp_projekt_tab_uebersicht( $p, $k ) {
	global $wpdb;
	$quot = $k['todos'] ? round( 100 * $k['erledigt'] / $k['todos'] ) : 0;
	?>
	<div class="pp-kpis">
		<div class="pp-kpi"><strong><?php echo esc_html( $p->beginn ? mysql2date( 'd.m.Y', $p->beginn ) : '–' ); ?></strong><span>
			<?php
			if ( $p->beginn ) {
				$tage = (int) floor( ( strtotime( mysql2date( 'Y-m-d', $p->beginn ) ) - strtotime( current_time( 'Y-m-d' ) ) ) / DAY_IN_SECONDS );
				echo esc_html( $tage > 0 ? sprintf( _n( 'in %d Tag', 'in %d Tagen', $tage, 'vereinsplugin' ), $tage ) : ( 0 === $tage ? __( 'heute', 'vereinsplugin' ) : __( 'vorbei', 'vereinsplugin' ) ) );
			} else {
				esc_html_e( 'Datum offen', 'vereinsplugin' );
			}
			?>
		</span></div>
		<div class="pp-kpi"><strong><?php echo (int) $k['erledigt']; ?>/<?php echo (int) $k['todos']; ?></strong><span><?php esc_html_e( 'ToDos erledigt', 'vereinsplugin' ); ?></span><div class="pp-progress"><span style="width:<?php echo (int) $quot; ?>%"></span></div></div>
		<div class="pp-kpi<?php echo ( $p->helfende_bedarf && $k['zugesagt'] < $p->helfende_bedarf ) ? ' is-warn' : ''; ?>"><strong><?php echo (int) $k['zugesagt']; ?><?php echo $p->helfende_bedarf ? '/' . (int) $p->helfende_bedarf : ''; ?></strong><span><?php esc_html_e( 'Helfende zugesagt', 'vereinsplugin' ); ?></span></div>
		<?php if ( $k['schicht'] ) : ?>
			<div class="pp-kpi<?php echo $k['schicht']['luecken'] ? ' is-warn' : ''; ?>"><strong><?php echo (int) $k['schicht']['belegt']; ?>/<?php echo (int) $k['schicht']['plaetze']; ?></strong><span><?php esc_html_e( 'Schichtplätze belegt', 'vereinsplugin' ); ?></span></div>
		<?php endif; ?>
		<?php if ( $k['budget'] ) : ?>
			<div class="pp-kpi<?php echo (float) $k['budget']->rest < 0 ? ' is-neg' : ''; ?>"><strong><?php echo esc_html( vp_kreis_eur( $k['budget']->rest ) ); ?></strong><span><?php esc_html_e( 'Budget übrig', 'vereinsplugin' ); ?></span></div>
		<?php endif; ?>
		<?php if ( null !== $k['anmeldungen'] ) : ?>
			<div class="pp-kpi"><strong><?php echo (int) $k['anmeldungen']; ?><?php echo $p->teilnehmende_erwartet ? '/' . (int) $p->teilnehmende_erwartet : ''; ?></strong><span><?php esc_html_e( 'Anmeldungen', 'vereinsplugin' ); ?></span></div>
		<?php endif; ?>
	</div>

	<div class="pp-cards">
		<div class="pp-card">
			<h3><?php esc_html_e( 'Ziel', 'vereinsplugin' ); ?></h3>
			<?php if ( $p->ziel ) : ?>
				<p class="pp-projekt-ziel"><?php echo nl2br( esc_html( $p->ziel ) ); ?></p>
			<?php else : ?>
				<p class="pp-empty"><?php esc_html_e( 'Noch kein Ziel formuliert – unten unter „Eckdaten" eintragen.', 'vereinsplugin' ); ?></p>
			<?php endif; ?>
			<?php if ( $p->zielgruppe ) : ?><p class="pp-meta"><?php echo esc_html( sprintf( __( 'Zielgruppe: %s', 'vereinsplugin' ), $p->zielgruppe ) ); ?></p><?php endif; ?>
			<?php if ( $p->beschreibung ) : ?><p><?php echo nl2br( esc_html( $p->beschreibung ) ); ?></p><?php endif; ?>
		</div>

		<div class="pp-card">
			<h3><?php esc_html_e( 'Verknüpft mit', 'vereinsplugin' ); ?></h3>
			<ul class="pp-list pp-verknuepfungen">
				<?php
				// Termin
				$termin = $p->termin_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}pp_termine WHERE id = %d", $p->termin_id ) ) : null;
				echo '<li><strong>' . esc_html__( 'Kalender', 'vereinsplugin' ) . '</strong>';
				if ( $termin ) {
					echo '<span class="pp-meta">' . esc_html( sprintf( __( 'Termin am %s – im Kalender-Abo aller Mitglieder', 'vereinsplugin' ), mysql2date( 'd.m.Y H:i', $termin->datum ) ) ) . '</span>';
					vp_projekt_loesen_knopf( $p, 'termin_id' );
				} elseif ( $p->beginn ) {
					vp_projekt_verknuepfen_knopf( $p, 'termin', __( 'In den Kalender eintragen', 'vereinsplugin' ) );
				} else {
					echo '<span class="pp-meta">' . esc_html__( 'Erst den Beginn eintragen.', 'vereinsplugin' ) . '</span>';
				}
				echo '</li>';

				// Budget
				if ( function_exists( 'vp_kreis_kasse_verfuegbar' ) && vp_kreis_kasse_verfuegbar() ) {
					echo '<li><strong>' . esc_html__( 'Budget', 'vereinsplugin' ) . '</strong><span class="pp-meta">';
					echo $k['budget']
						? esc_html( sprintf( __( '%1$s – %2$s von %3$s übrig', 'vereinsplugin' ), $k['budget']->zweck, vp_kreis_eur( $k['budget']->rest ), vp_kreis_eur( $k['budget']->betrag ) ) )
						: esc_html__( 'noch keins', 'vereinsplugin' );
					echo ' · <a href="' . esc_url( vp_projekt_url( $p->id, 'finanzen' ) ) . '">' . esc_html__( 'Finanzen', 'vereinsplugin' ) . '</a></span></li>';
				}

				// Schichtplan
				if ( function_exists( 'wl_get_event' ) ) {
					echo '<li><strong>' . esc_html__( 'Schichtplan', 'vereinsplugin' ) . '</strong><span class="pp-meta">';
					echo $k['schicht']
						? esc_html( sprintf( __( '%1$d Schichten, %2$d von %3$d Plätzen belegt', 'vereinsplugin' ), $k['schicht']['schichten'], $k['schicht']['belegt'], $k['schicht']['plaetze'] ) )
						: esc_html__( 'noch keiner', 'vereinsplugin' );
					echo ' · <a href="' . esc_url( vp_projekt_url( $p->id, 'helfende' ) ) . '">' . esc_html__( 'Helfende & Schichten', 'vereinsplugin' ) . '</a></span></li>';
				}

				// Veranstaltungsbeitrag
				if ( post_type_exists( 'veranstaltung' ) ) {
					$post = $p->veranstaltung_post_id ? get_post( $p->veranstaltung_post_id ) : null;
					echo '<li><strong>' . esc_html__( 'Veröffentlichung', 'vereinsplugin' ) . '</strong><span class="pp-meta">';
					echo $post
						? esc_html( sprintf( __( 'Beitrag „%1$s" (%2$s)', 'vereinsplugin' ), $post->post_title, get_post_status_object( $post->post_status )->label ?? $post->post_status ) )
						: esc_html__( 'noch kein Beitrag', 'vereinsplugin' );
					echo ' · <a href="' . esc_url( vp_projekt_url( $p->id, 'oeffentlichkeit' ) ) . '">' . esc_html__( 'Öffentlichkeitsarbeit', 'vereinsplugin' ) . '</a></span></li>';
				}

				// Anmeldeformular
				if ( function_exists( 'vp_formular_get' ) ) {
					$form = $p->formular_id ? vp_formular_get( $p->formular_id ) : null;
					echo '<li><strong>' . esc_html__( 'Anmeldung', 'vereinsplugin' ) . '</strong>';
					if ( $form ) {
						echo '<span class="pp-meta">' . esc_html( sprintf( __( '„%1$s" · %2$s · %3$d Anmeldungen', 'vereinsplugin' ), $form->titel, $form->status, (int) $k['anmeldungen'] ) ) . '<br><code>[verein_formular id="' . (int) $form->id . '"]</code>';
						if ( current_user_can( 'vp_manage_formulare' ) ) {
							echo ' · <a href="' . esc_url( vp_kreis_mitgliederbereich_url( array( 'vp_tab' => 'formulare', 'vp_formular_id' => (int) $form->id ) ) ) . '">' . esc_html__( 'bearbeiten', 'vereinsplugin' ) . '</a>'
								. ' · <a href="' . esc_url( vp_kreis_mitgliederbereich_url( array( 'vp_tab' => 'formulare', 'vp_fview' => 'eintraege', 'vp_formular_id' => (int) $form->id ) ) ) . '">' . esc_html__( 'Anmeldungen', 'vereinsplugin' ) . '</a>';
						}
						echo '</span>';
						vp_projekt_loesen_knopf( $p, 'formular_id' );
					} elseif ( current_user_can( 'vp_manage_formulare' ) ) {
						vp_projekt_verknuepfen_knopf( $p, 'formular', __( 'Anmeldeformular anlegen', 'vereinsplugin' ) );
					} else {
						echo '<span class="pp-meta">' . esc_html__( 'Ein Anmeldeformular kann der Vorstand anlegen.', 'vereinsplugin' ) . '</span>';
					}
					echo '</li>';
				}
				?>
			</ul>
		</div>
	</div>

	<details class="pp-werkzeug" <?php echo $p->ziel ? '' : 'open'; ?>>
		<summary class="pp-details-summary"><?php esc_html_e( 'Eckdaten bearbeiten', 'vereinsplugin' ); ?></summary>
		<?php vp_kreis_form( 'vp_projekt_save', 'pp-form pp-form-grid' ); ?>
			<input type="hidden" name="id" value="<?php echo (int) $p->id; ?>">
			<label class="pp-span-2"><?php esc_html_e( 'Titel *', 'vereinsplugin' ); ?><input type="text" name="titel" required value="<?php echo esc_attr( $p->titel ); ?>"></label>
			<label><?php esc_html_e( 'Status', 'vereinsplugin' ); ?><select name="status">
				<?php foreach ( vp_projekt_status_liste() as $key => $l ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $p->status, $key ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?>
			</select></label>
			<label><?php esc_html_e( 'Art', 'vereinsplugin' ); ?><select name="art">
				<?php foreach ( vp_projekt_arten() as $key => $l ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $p->art, $key ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?>
			</select></label>
			<label><?php esc_html_e( 'Kreis', 'vereinsplugin' ); ?><select name="gremium_id"><?php echo vp_kreis_optionen( $p->gremium_id ); // phpcs:ignore ?></select></label>
			<label><?php esc_html_e( 'Verantwortlich', 'vereinsplugin' ); ?><select name="verantwortlich_user_id"><?php echo vp_kreis_personen_optionen( $p->verantwortlich_user_id, $p->gremium_id ); // phpcs:ignore ?></select></label>
			<label><?php esc_html_e( 'Beginn', 'vereinsplugin' ); ?><input type="datetime-local" name="beginn" value="<?php echo esc_attr( vp_projekt_dt_out( $p->beginn ) ); ?>"></label>
			<label><?php esc_html_e( 'Ende', 'vereinsplugin' ); ?><input type="datetime-local" name="ende" value="<?php echo esc_attr( vp_projekt_dt_out( $p->ende ) ); ?>"></label>
			<label><?php esc_html_e( 'Ort', 'vereinsplugin' ); ?><input type="text" name="ort" value="<?php echo esc_attr( $p->ort ); ?>"></label>
			<label><?php esc_html_e( 'Zielgruppe', 'vereinsplugin' ); ?><input type="text" name="zielgruppe" value="<?php echo esc_attr( $p->zielgruppe ); ?>" placeholder="<?php esc_attr_e( 'z. B. Jugendliche 14–21 aus der Stadt', 'vereinsplugin' ); ?>"></label>
			<label><?php esc_html_e( 'Helfende gebraucht', 'vereinsplugin' ); ?><input type="number" min="0" name="helfende_bedarf" value="<?php echo esc_attr( $p->helfende_bedarf ); ?>"></label>
			<label><?php esc_html_e( 'Erwartete Teilnehmende', 'vereinsplugin' ); ?><input type="number" min="0" name="teilnehmende_erwartet" value="<?php echo esc_attr( $p->teilnehmende_erwartet ); ?>"></label>
			<label class="pp-span-2"><?php esc_html_e( 'Ziel – was soll am Ende erreicht sein?', 'vereinsplugin' ); ?><textarea name="ziel" rows="3"><?php echo esc_textarea( $p->ziel ); ?></textarea></label>
			<label class="pp-span-2"><?php esc_html_e( 'Beschreibung', 'vereinsplugin' ); ?><textarea name="beschreibung" rows="4"><?php echo esc_textarea( $p->beschreibung ); ?></textarea></label>
			<label class="pp-span-2"><?php esc_html_e( 'Interne Notizen', 'vereinsplugin' ); ?><textarea name="notizen" rows="3"><?php echo esc_textarea( $p->notizen ); ?></textarea></label>
			<div class="pp-form-actions"><button type="submit" class="pp-btn pp-btn-primary"><?php esc_html_e( 'Speichern', 'vereinsplugin' ); ?></button>
				<?php if ( $p->termin_id ) : ?><span class="pp-meta"><?php esc_html_e( 'Der verknüpfte Kalendertermin wird mit angepasst.', 'vereinsplugin' ); ?></span><?php endif; ?></div>
		</form>
		<?php vp_kreis_form( 'vp_projekt_delete', 'pp-inline', 'onsubmit="return confirm(\'' . esc_js( __( 'Projekt mit Ablauf, Helfenden und Kalkulation löschen? ToDos, Termin, Schichtplan, Budget und Beitrag bleiben bestehen.', 'vereinsplugin' ) ) . '\')"' ); ?>
			<input type="hidden" name="id" value="<?php echo (int) $p->id; ?>">
			<button type="submit" class="pp-link-danger"><?php esc_html_e( 'Projekt löschen', 'vereinsplugin' ); ?></button>
		</form>
	</details>
	<?php
}

function vp_projekt_verknuepfen_knopf( $p, $was, $label, $felder = '' ) {
	vp_kreis_form( 'vp_projekt_verknuepfen', 'pp-inline-form' );
	echo '<input type="hidden" name="id" value="' . (int) $p->id . '"><input type="hidden" name="was" value="' . esc_attr( $was ) . '">';
	echo $felder; // phpcs:ignore -- vom Aufrufer aus festen Werten gebaut
	echo '<button type="submit" class="pp-btn pp-btn-small">' . esc_html( $label ) . '</button></form>';
}

function vp_projekt_loesen_knopf( $p, $feld ) {
	vp_kreis_form( 'vp_projekt_verknuepfen', 'pp-inline', 'onsubmit="return confirm(\'' . esc_js( __( 'Verknüpfung lösen? Das verknüpfte Objekt selbst bleibt bestehen.', 'vereinsplugin' ) ) . '\')"' );
	echo '<input type="hidden" name="id" value="' . (int) $p->id . '"><input type="hidden" name="was" value="loesen"><input type="hidden" name="feld" value="' . esc_attr( $feld ) . '">';
	echo '<button type="submit" class="pp-link-danger">' . esc_html__( 'lösen', 'vereinsplugin' ) . '</button></form>';
}

/* ---- Punkte (Ablauf, Öffentlichkeitsarbeit, Kalkulation) ---- */

function vp_projekt_punkt_formular( $p, $bereich, $punkt = null ) {
	$phase = $punkt->phase ?? ( isset( $_GET['p_phase'] ) ? sanitize_key( wp_unslash( $_GET['p_phase'] ) ) : 'vorbereitung' );
	vp_kreis_form( 'vp_projekt_punkt_save', 'pp-form pp-form-grid' );
	?>
		<input type="hidden" name="projekt_id" value="<?php echo (int) $p->id; ?>">
		<input type="hidden" name="bereich" value="<?php echo esc_attr( $bereich ); ?>">
		<input type="hidden" name="punkt_id" value="<?php echo (int) ( $punkt->id ?? 0 ); ?>">
		<?php if ( 'ablauf' === $bereich ) : ?>
			<label><?php esc_html_e( 'Phase', 'vereinsplugin' ); ?><select name="phase">
				<?php foreach ( vp_projekt_phasen() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $phase, $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?>
			</select></label>
			<label><?php esc_html_e( 'Wann', 'vereinsplugin' ); ?><input type="datetime-local" name="zeitpunkt" value="<?php echo esc_attr( vp_projekt_dt_out( $punkt->zeitpunkt ?? '' ) ); ?>"></label>
			<label><?php esc_html_e( 'Dauer (Min.)', 'vereinsplugin' ); ?><input type="number" min="0" name="dauer_minuten" value="<?php echo esc_attr( $punkt->dauer_minuten ?? '' ); ?>"></label>
		<?php elseif ( 'oeffentlichkeit' === $bereich ) : ?>
			<label><?php esc_html_e( 'Kanal', 'vereinsplugin' ); ?><select name="kanal">
				<?php foreach ( vp_projekt_kanaele() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $punkt->kanal ?? '', $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?>
			</select></label>
			<label><?php esc_html_e( 'Fällig am', 'vereinsplugin' ); ?><input type="datetime-local" name="zeitpunkt" value="<?php echo esc_attr( vp_projekt_dt_out( $punkt->zeitpunkt ?? '' ) ); ?>"></label>
		<?php else : ?>
			<label><?php esc_html_e( 'Art', 'vereinsplugin' ); ?><select name="richtung">
				<option value="ausgabe" <?php selected( isset( $punkt->betrag ) && (float) $punkt->betrag > 0, false ); ?>><?php esc_html_e( 'Ausgabe', 'vereinsplugin' ); ?></option>
				<option value="einnahme" <?php selected( isset( $punkt->betrag ) && (float) $punkt->betrag > 0, true ); ?>><?php esc_html_e( 'Einnahme', 'vereinsplugin' ); ?></option>
			</select></label>
			<label><?php esc_html_e( 'Betrag (€)', 'vereinsplugin' ); ?><input type="text" inputmode="decimal" name="betrag" required value="<?php echo isset( $punkt->betrag ) ? esc_attr( number_format( abs( (float) $punkt->betrag ), 2, ',', '' ) ) : ''; ?>"></label>
		<?php endif; ?>
		<label class="pp-span-2"><?php echo 'kalkulation' === $bereich ? esc_html__( 'Posten *', 'vereinsplugin' ) : esc_html__( 'Was *', 'vereinsplugin' ); ?><input type="text" name="titel" required value="<?php echo esc_attr( $punkt->titel ?? '' ); ?>"></label>
		<?php if ( 'kalkulation' !== $bereich ) : ?>
			<label><?php esc_html_e( 'Verantwortlich', 'vereinsplugin' ); ?><select name="verantwortlich_user_id"><?php echo vp_kreis_personen_optionen( $punkt->verantwortlich_user_id ?? 0, $p->gremium_id ); // phpcs:ignore ?></select></label>
		<?php endif; ?>
		<label class="pp-span-2"><?php esc_html_e( 'Details', 'vereinsplugin' ); ?><textarea name="beschreibung" rows="2"><?php echo esc_textarea( $punkt->beschreibung ?? '' ); ?></textarea></label>
		<div class="pp-form-actions"><button type="submit" class="pp-btn pp-btn-primary pp-btn-small"><?php echo $punkt ? esc_html__( 'Speichern', 'vereinsplugin' ) : esc_html__( 'Hinzufügen', 'vereinsplugin' ); ?></button></div>
	</form>
	<?php
}

/** Kleiner Status-Knopf + Löschen für eine Punkt-Zeile. */
function vp_projekt_punkt_aktionen( $p, $punkt, $naechster_status ) {
	$labels = vp_projekt_punkt_status();
	vp_kreis_form( 'vp_projekt_punkt_status', 'pp-inline' );
	echo '<input type="hidden" name="projekt_id" value="' . (int) $p->id . '"><input type="hidden" name="punkt_id" value="' . (int) $punkt->id . '"><input type="hidden" name="status" value="' . esc_attr( $naechster_status ) . '">';
	echo '<button type="submit" class="pp-badge pp-punkt-' . esc_attr( $punkt->status ) . '" title="' . esc_attr( sprintf( __( 'Auf „%s" setzen', 'vereinsplugin' ), $labels[ $naechster_status ] ) ) . '">' . esc_html( $labels[ $punkt->status ] ?? $punkt->status ) . '</button></form>';
	vp_kreis_form( 'vp_projekt_punkt_delete', 'pp-inline' );
	echo '<input type="hidden" name="projekt_id" value="' . (int) $p->id . '"><input type="hidden" name="punkt_id" value="' . (int) $punkt->id . '">';
	echo '<button type="submit" class="pp-link-danger" onclick="return confirm(\'' . esc_js( __( 'Eintrag löschen?', 'vereinsplugin' ) ) . '\')">' . esc_html__( 'löschen', 'vereinsplugin' ) . '</button></form>';
}

function vp_projekt_tab_ablauf( $p ) {
	$punkte = vp_projekt_punkte( $p->id, 'ablauf' );
	$edit   = isset( $_GET['p_punkt'] ) ? (int) $_GET['p_punkt'] : 0;
	?>
	<p class="pp-meta"><?php esc_html_e( 'Meilensteine der Vorbereitung, der Zeitplan am Tag selbst und was danach noch zu tun ist. Für einzelne Aufgaben mit Zuständigkeit und Frist gibt es die ToDos.', 'vereinsplugin' ); ?></p>
	<?php
	foreach ( vp_projekt_phasen() as $phase => $label ) {
		$liste = array_filter( $punkte, function ( $x ) use ( $phase ) { return ( $x->phase ?: 'vorbereitung' ) === $phase; } );
		echo '<h3>' . esc_html( $label ) . '</h3>';
		if ( ! $liste ) {
			echo '<p class="pp-empty">' . esc_html__( 'Noch nichts geplant.', 'vereinsplugin' ) . '</p>';
			continue;
		}
		echo '<table class="pp-table pp-ablauf-tabelle"><tbody>';
		foreach ( $liste as $x ) {
			$zeit = $x->zeitpunkt ? mysql2date( 'durchfuehrung' === $phase ? 'H:i' : 'd.m.Y', $x->zeitpunkt ) : '–';
			echo '<tr class="' . ( 'erledigt' === $x->status ? 'is-erledigt' : '' ) . '">';
			echo '<td class="pp-ablauf-zeit">' . esc_html( $zeit ) . ( $x->dauer_minuten ? '<div class="pp-meta">' . esc_html( sprintf( __( '%d Min.', 'vereinsplugin' ), $x->dauer_minuten ) ) . '</div>' : '' ) . '</td>';
			echo '<td><strong>' . esc_html( $x->titel ) . '</strong>' . ( $x->beschreibung ? '<div class="pp-meta">' . nl2br( esc_html( $x->beschreibung ) ) . '</div>' : '' ) . '</td>';
			echo '<td>' . ( $x->verantwortlich_user_id ? esc_html( pp_user_display_name( $x->verantwortlich_user_id ) ) : '' ) . '</td>';
			echo '<td class="pp-ablauf-aktionen">';
			vp_projekt_punkt_aktionen( $p, $x, 'erledigt' === $x->status ? 'offen' : 'erledigt' );
			echo ' <a class="pp-meta" href="' . esc_url( vp_projekt_url( $p->id, 'ablauf', array( 'p_punkt' => (int) $x->id ) ) . '#vp-punkt-form' ) . '">' . esc_html__( 'bearbeiten', 'vereinsplugin' ) . '</a>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	$edit_punkt = null;
	foreach ( $punkte as $x ) {
		if ( (int) $x->id === $edit ) {
			$edit_punkt = $x;
		}
	}
	?>
	<details class="pp-werkzeug" id="vp-punkt-form" <?php echo ( $edit_punkt || ! $punkte ) ? 'open' : ''; ?>>
		<summary class="pp-details-summary"><?php echo $edit_punkt ? esc_html__( 'Programmpunkt bearbeiten', 'vereinsplugin' ) : esc_html__( '+ Programmpunkt / Meilenstein', 'vereinsplugin' ); ?></summary>
		<?php vp_projekt_punkt_formular( $p, 'ablauf', $edit_punkt ); ?>
	</details>
	<?php if ( ! $punkte && $p->beginn ) : ?>
		<?php vp_projekt_verknuepfen_knopf( $p, 'vorschlag_ablauf', __( 'Typischen Ablauf als Vorschlag einfügen', 'vereinsplugin' ) ); ?>
	<?php endif;
}

/* ---- ToDos ---- */

function vp_projekt_tab_todos( $p ) {
	$todos  = vp_projekt_todos( $p->id );
	$offen  = array_filter( $todos, function ( $t ) { return 'erledigt' !== $t->status; } );
	$fertig = array_filter( $todos, function ( $t ) { return 'erledigt' === $t->status; } );
	$heute  = current_time( 'Y-m-d' );

	if ( isset( $_GET['pp_set_erzeugt'] ) ) {
		echo '<div class="pp-front-notice pp-front-notice-success">' . esc_html( sprintf( __( '%1$d ToDo(s) aus dem Set erzeugt, %2$d übersprungen (gab es schon).', 'vereinsplugin' ), (int) $_GET['pp_set_erzeugt'], (int) ( $_GET['pp_set_uebersprungen'] ?? 0 ) ) ) . '</div>';
	}

	$zeile = function ( $t ) use ( $p, $heute ) {
		echo '<li class="' . ( 'erledigt' === $t->status ? 'is-erledigt' : '' ) . '">';
		vp_kreis_form( 'vp_projekt_todo', 'pp-inline' );
		echo '<input type="hidden" name="projekt_id" value="' . (int) $p->id . '"><input type="hidden" name="aufgabe_id" value="' . (int) $t->id . '"><input type="hidden" name="was" value="toggle">';
		echo '<button type="submit" class="pp-checkbox" title="' . esc_attr__( 'Status umschalten', 'vereinsplugin' ) . '">' . ( 'erledigt' === $t->status ? '☑' : '☐' ) . '</button></form> ';
		echo esc_html( $t->titel );
		$ueber = 'erledigt' !== $t->status && $t->faelligkeitsdatum && $t->faelligkeitsdatum < $heute;
		echo '<span class="pp-meta' . ( $ueber ? ' pp-ueberfaellig' : '' ) . '">'
			. esc_html( ( $t->verantwortlich_user_id ? pp_user_display_name( $t->verantwortlich_user_id ) : __( 'noch niemand', 'vereinsplugin' ) ) . ' · ' . ( $t->faelligkeitsdatum ? mysql2date( 'd.m.Y', $t->faelligkeitsdatum ) : __( 'ohne Frist', 'vereinsplugin' ) ) )
			. ( $t->beschreibung ? ' · ' . esc_html( wp_trim_words( $t->beschreibung, 14 ) ) : '' ) . '</span>';
		vp_kreis_form( 'vp_projekt_todo', 'pp-inline' );
		echo '<input type="hidden" name="projekt_id" value="' . (int) $p->id . '"><input type="hidden" name="aufgabe_id" value="' . (int) $t->id . '"><input type="hidden" name="was" value="loeschen">';
		echo '<button type="submit" class="pp-link-danger" onclick="return confirm(\'' . esc_js( __( 'ToDo löschen?', 'vereinsplugin' ) ) . '\')">' . esc_html__( 'löschen', 'vereinsplugin' ) . '</button></form>';
		echo '</li>';
	};
	?>
	<p class="pp-meta"><?php esc_html_e( 'ToDos sind normale Aufgaben: Sie erscheinen auch unter „Aufgaben", beim Kreis und im Kalender-Abo der verantwortlichen Person.', 'vereinsplugin' ); ?></p>
	<ul class="pp-list pp-aufgabenliste">
		<?php foreach ( $offen as $t ) { $zeile( $t ); } ?>
		<?php if ( ! $offen ) : ?><li class="pp-empty"><?php esc_html_e( 'Keine offenen ToDos.', 'vereinsplugin' ); ?></li><?php endif; ?>
	</ul>

	<?php vp_kreis_form( 'vp_projekt_todo', 'pp-inline-form' ); ?>
		<input type="hidden" name="projekt_id" value="<?php echo (int) $p->id; ?>">
		<input type="hidden" name="was" value="neu">
		<input type="text" name="titel" required placeholder="<?php esc_attr_e( 'Neues ToDo', 'vereinsplugin' ); ?>">
		<select name="verantwortlich_user_id"><?php echo vp_kreis_personen_optionen( 0, $p->gremium_id, __( 'Wer?', 'vereinsplugin' ) ); // phpcs:ignore ?></select>
		<input type="date" name="faelligkeitsdatum" title="<?php esc_attr_e( 'Bis wann?', 'vereinsplugin' ); ?>">
		<input type="text" name="beschreibung" placeholder="<?php esc_attr_e( 'Details (optional)', 'vereinsplugin' ); ?>">
		<button type="submit" class="pp-btn pp-btn-small"><?php esc_html_e( 'Hinzufügen', 'vereinsplugin' ); ?></button>
	</form>

	<?php
	$sets = function_exists( 'pp_get_aufgaben_sets' ) ? pp_get_aufgaben_sets() : array();
	if ( $sets ) :
		?>
		<h4><?php esc_html_e( 'Aufgaben-Set anwenden', 'vereinsplugin' ); ?></h4>
		<?php if ( $p->beginn ) : ?>
			<p class="pp-meta"><?php esc_html_e( 'Erzeugt alle Aufgaben eines Sets mit Fristen relativ zum Beginn und weist sie den aktuellen Rolleninhaber:innen zu.', 'vereinsplugin' ); ?></p>
			<?php vp_kreis_form( 'vp_projekt_todo', 'pp-inline-form' ); ?>
				<input type="hidden" name="projekt_id" value="<?php echo (int) $p->id; ?>">
				<input type="hidden" name="was" value="set">
				<select name="set_id" required>
					<option value=""><?php esc_html_e( 'Set wählen…', 'vereinsplugin' ); ?></option>
					<?php foreach ( $sets as $s ) :
						if ( $s->gremium_id && (int) $s->gremium_id !== (int) $p->gremium_id ) {
							continue;
						} ?>
						<option value="<?php echo (int) $s->id; ?>"><?php echo esc_html( $s->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="pp-btn pp-btn-small"><?php esc_html_e( 'anwenden', 'vereinsplugin' ); ?></button>
			</form>
		<?php else : ?>
			<p class="pp-meta"><?php esc_html_e( 'Dafür braucht das Projekt einen Beginn (Übersicht → Eckdaten).', 'vereinsplugin' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<?php if ( $fertig ) : ?>
		<details class="pp-werkzeug">
			<summary class="pp-details-summary"><?php echo esc_html( sprintf( __( 'Erledigt (%d)', 'vereinsplugin' ), count( $fertig ) ) ); ?></summary>
			<ul class="pp-list pp-aufgabenliste"><?php foreach ( $fertig as $t ) { $zeile( $t ); } ?></ul>
		</details>
	<?php endif;
}

/* ---- Helfende & Schichten ---- */

function vp_projekt_tab_helfende( $p, $k ) {
	$helfende = vp_projekt_helfende( $p->id );
	$status   = vp_projekt_helfende_status();
	$s        = $k['schicht'];
	?>
	<div class="pp-kpis">
		<div class="pp-kpi<?php echo ( $p->helfende_bedarf && $k['zugesagt'] < $p->helfende_bedarf ) ? ' is-warn' : ''; ?>"><strong><?php echo (int) $k['zugesagt']; ?><?php echo $p->helfende_bedarf ? '/' . (int) $p->helfende_bedarf : ''; ?></strong><span><?php esc_html_e( 'zugesagt', 'vereinsplugin' ); ?></span></div>
		<div class="pp-kpi"><strong><?php echo (int) $k['angefragt']; ?></strong><span><?php esc_html_e( 'angefragt', 'vereinsplugin' ); ?></span></div>
		<?php if ( $s ) : ?>
			<div class="pp-kpi<?php echo $s['luecken'] ? ' is-warn' : ''; ?>"><strong><?php echo (int) $s['belegt']; ?>/<?php echo (int) $s['plaetze']; ?></strong><span><?php esc_html_e( 'Schichtplätze belegt', 'vereinsplugin' ); ?></span></div>
		<?php endif; ?>
	</div>

	<h3><?php esc_html_e( 'Helfende', 'vereinsplugin' ); ?></h3>
	<table class="pp-table">
		<thead><tr><th><?php esc_html_e( 'Wer', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Kontakt', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Aufgabe', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Status', 'vereinsplugin' ); ?></th><th></th></tr></thead>
		<tbody>
		<?php foreach ( $helfende as $h ) :
			$u       = $h->user_id ? get_userdata( $h->user_id ) : null;
			$kontakt = $h->kontakt ?: ( $u ? $u->user_email : '' ); ?>
			<tr>
				<td><?php echo esc_html( $u ? $u->display_name : $h->name ); ?><?php if ( $h->notiz ) : ?><div class="pp-meta"><?php echo esc_html( $h->notiz ); ?></div><?php endif; ?></td>
				<td class="pp-meta"><?php echo is_email( $kontakt ) ? '<a href="mailto:' . esc_attr( $kontakt ) . '">' . esc_html( $kontakt ) . '</a>' : esc_html( $kontakt ); ?></td>
				<td><?php echo esc_html( $h->aufgabe ); ?></td>
				<td>
					<?php vp_kreis_form( 'vp_projekt_helfer', 'pp-inline-form' ); ?>
						<input type="hidden" name="projekt_id" value="<?php echo (int) $p->id; ?>">
						<input type="hidden" name="helfer_id" value="<?php echo (int) $h->id; ?>">
						<input type="hidden" name="was" value="status">
						<select name="status" onchange="this.form.submit()">
							<?php foreach ( $status as $key => $l ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $h->status, $key ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?>
						</select>
						<noscript><button class="pp-btn pp-btn-small">OK</button></noscript>
					</form>
				</td>
				<td>
					<?php vp_kreis_form( 'vp_projekt_helfer', 'pp-inline' ); ?>
						<input type="hidden" name="projekt_id" value="<?php echo (int) $p->id; ?>">
						<input type="hidden" name="helfer_id" value="<?php echo (int) $h->id; ?>">
						<input type="hidden" name="was" value="loeschen">
						<button type="submit" class="pp-link-danger" onclick="return confirm('<?php echo esc_js( __( 'Von der Liste nehmen?', 'vereinsplugin' ) ); ?>')"><?php esc_html_e( 'entfernen', 'vereinsplugin' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		<?php if ( ! $helfende ) : ?><tr><td colspan="5" class="pp-empty"><?php esc_html_e( 'Noch niemand eingetragen.', 'vereinsplugin' ); ?></td></tr><?php endif; ?>
		</tbody>
	</table>

	<?php vp_kreis_form( 'vp_projekt_helfer', 'pp-inline-form' ); ?>
		<input type="hidden" name="projekt_id" value="<?php echo (int) $p->id; ?>">
		<input type="hidden" name="was" value="neu">
		<select name="user_id"><?php echo vp_kreis_personen_optionen( 0, $p->gremium_id, __( 'Mitglied…', 'vereinsplugin' ) ); // phpcs:ignore ?></select>
		<span class="pp-meta"><?php esc_html_e( 'oder', 'vereinsplugin' ); ?></span>
		<input type="text" name="name" placeholder="<?php esc_attr_e( 'Name (extern)', 'vereinsplugin' ); ?>">
		<input type="text" name="kontakt" placeholder="<?php esc_attr_e( 'E-Mail / Telefon', 'vereinsplugin' ); ?>">
		<input type="text" name="aufgabe" placeholder="<?php esc_attr_e( 'Aufgabe, z. B. Aufbau', 'vereinsplugin' ); ?>">
		<select name="status"><?php foreach ( $status as $key => $l ) : ?><option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
		<button type="submit" class="pp-btn pp-btn-small"><?php esc_html_e( 'Hinzufügen', 'vereinsplugin' ); ?></button>
	</form>
	<?php if ( $p->gremium_id ) : ?>
		<?php vp_projekt_verknuepfen_knopf( $p, 'helfende_kreis', __( 'Alle Kreismitglieder als „angefragt" eintragen', 'vereinsplugin' ) ); ?>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Schichtplan', 'vereinsplugin' ); ?></h3>
	<?php
	if ( ! function_exists( 'wl_get_event' ) ) {
		echo '<p class="pp-empty">' . esc_html__( 'Das Schichtplan-Modul ist nicht aktiv.', 'vereinsplugin' ) . '</p>';
		return;
	}
	if ( ! $s ) {
		echo '<p class="pp-meta">' . esc_html__( 'Mit einem Schichtplan können sich Helfende selbst in Schichten (Aufbau, Theke, Kasse …) eintragen – auch ohne Login über einen öffentlichen Link.', 'vereinsplugin' ) . '</p>';
		if ( current_user_can( 'wl_manage_wishes' ) ) {
			vp_projekt_verknuepfen_knopf( $p, 'schichtplan', __( 'Schichtplan für dieses Projekt anlegen', 'vereinsplugin' ) );
			$events = wl_get_events( true );
			if ( $events ) {
				$opts = '<select name="event_id" required><option value="">' . esc_html__( 'Bestehenden Schichtplan wählen…', 'vereinsplugin' ) . '</option>';
				foreach ( $events as $e ) {
					$opts .= '<option value="' . (int) $e->id . '">' . esc_html( $e->titel ) . '</option>';
				}
				$opts .= '</select>';
				vp_projekt_verknuepfen_knopf( $p, 'schichtplan_waehlen', __( 'verknüpfen', 'vereinsplugin' ), $opts );
			}
		}
		return;
	}
	$verwalten = vp_kreis_mitgliederbereich_url( array( 'vp_tab' => 'schichtplaene', 'vp_sp' => 'verwalten', 'wls_event' => (int) $s['event']->id ) );
	$ansehen   = vp_kreis_mitgliederbereich_url( array( 'vp_tab' => 'schichtplaene', 'event' => $s['event']->slug ) );
	?>
	<p>
		<strong><?php echo esc_html( $s['event']->titel ); ?></strong>
		<span class="pp-meta"><?php echo esc_html( sprintf( __( '%1$d Stationen · %2$d Schichten · %3$d von %4$d Plätzen belegt', 'vereinsplugin' ), $s['stationen'], $s['schichten'], $s['belegt'], $s['plaetze'] ) ); ?></span>
	</p>
	<p>
		<?php if ( function_exists( 'vp_member_sections' ) ) : ?>
			<a class="pp-btn pp-btn-small" href="<?php echo esc_url( $ansehen ); ?>"><?php esc_html_e( 'Ansehen & eintragen', 'vereinsplugin' ); ?></a>
			<?php if ( current_user_can( 'wl_manage_wishes' ) ) : ?><a class="pp-btn pp-btn-small" href="<?php echo esc_url( $verwalten ); ?>"><?php esc_html_e( 'Stationen & Schichten bearbeiten', 'vereinsplugin' ); ?></a><?php endif; ?>
		<?php endif; ?>
		<span class="pp-meta"><?php echo esc_html( sprintf( __( 'Öffentlich einbinden: [schichtplan event="%s"]', 'vereinsplugin' ), $s['event']->slug ) ); ?></span>
		<?php vp_projekt_loesen_knopf( $p, 'schicht_event_id' ); ?>
	</p>
	<?php if ( ! $s['schichten'] ) : ?>
		<p class="pp-hint"><?php esc_html_e( 'Der Schichtplan hat noch keine Stationen und Schichten – über „Stationen & Schichten bearbeiten" anlegen.', 'vereinsplugin' ); ?></p>
	<?php endif; ?>

	<?php if ( $s['luecken'] ) : ?>
		<h4><?php esc_html_e( 'Hier fehlen noch Leute', 'vereinsplugin' ); ?></h4>
		<ul class="pp-list">
			<?php foreach ( array_slice( $s['luecken'], 0, 12 ) as $l ) : ?>
				<li><?php echo esc_html( $l['station'] . ' – ' . ( $l['schicht']->titel ?: '' ) ); ?>
					<span class="pp-meta"><?php echo esc_html( ( $l['schicht']->start_zeit ? mysql2date( 'D d.m. H:i', $l['schicht']->start_zeit ) . ( $l['schicht']->end_zeit ? '–' . mysql2date( 'H:i', $l['schicht']->end_zeit ) : '' ) : '' ) . ' · ' . sprintf( _n( '%d Person fehlt', '%d Personen fehlen', $l['fehlt'], 'vereinsplugin' ), $l['fehlt'] ) ); ?></span></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<?php if ( $s['personen'] ) : ?>
		<h4><?php esc_html_e( 'Im Schichtplan eingetragen', 'vereinsplugin' ); ?></h4>
		<p><?php echo esc_html( implode( ', ', array_map( function ( $x ) { return $x['name'] . ( $x['n'] > 1 ? ' (' . $x['n'] . ')' : '' ); }, $s['personen'] ) ) ); ?></p>
		<?php vp_projekt_verknuepfen_knopf( $p, 'helfende_schicht', __( 'Eingetragene als Helfende übernehmen', 'vereinsplugin' ) ); ?>
	<?php endif;
}

/* ---- Öffentlichkeitsarbeit ---- */

function vp_projekt_tab_oeffentlichkeit( $p ) {
	$punkte  = vp_projekt_punkte( $p->id, 'oeffentlichkeit' );
	$kanaele = vp_projekt_kanaele();
	$weiter  = array( 'offen' => 'in_arbeit', 'in_arbeit' => 'erledigt', 'erledigt' => 'offen' );
	$edit    = isset( $_GET['p_punkt'] ) ? (int) $_GET['p_punkt'] : 0;
	$heute   = current_time( 'mysql' );
	?>
	<?php if ( $p->zielgruppe || $p->ziel ) : ?>
		<div class="pp-card">
			<?php if ( $p->zielgruppe ) : ?><p><strong><?php esc_html_e( 'Wen wollen wir erreichen?', 'vereinsplugin' ); ?></strong> <?php echo esc_html( $p->zielgruppe ); ?></p><?php endif; ?>
			<?php if ( $p->ziel ) : ?><p><strong><?php esc_html_e( 'Worum geht es?', 'vereinsplugin' ); ?></strong> <?php echo esc_html( wp_trim_words( $p->ziel, 40 ) ); ?></p><?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( post_type_exists( 'veranstaltung' ) ) :
		$post = $p->veranstaltung_post_id ? get_post( $p->veranstaltung_post_id ) : null; ?>
		<h3><?php esc_html_e( 'Veranstaltungs-Beitrag', 'vereinsplugin' ); ?></h3>
		<?php if ( $post ) :
			$review = get_post_meta( $post->ID, '_jbf_review_status', true ); ?>
			<p>
				<strong><?php echo esc_html( $post->post_title ); ?></strong>
				<span class="pp-meta"><?php echo esc_html( ( get_post_status_object( $post->post_status )->label ?? $post->post_status ) . ( $review ? ' · ' . sprintf( __( 'Freigabe: %s', 'vereinsplugin' ), $review ) : '' ) ); ?></span>
			</p>
			<p>
				<?php if ( 'publish' === $post->post_status ) : ?><a class="pp-btn pp-btn-small" target="_blank" rel="noopener" href="<?php echo esc_url( get_permalink( $post ) ); ?>"><?php esc_html_e( 'Ansehen', 'vereinsplugin' ); ?></a><?php endif; ?>
				<?php if ( current_user_can( 'edit_post', $post->ID ) && ( ! function_exists( 'vp_is_vorstand' ) || vp_is_vorstand() || '1' === get_option( 'vp_member_backend_access' ) ) ) : ?>
					<a class="pp-btn pp-btn-small" href="<?php echo esc_url( get_edit_post_link( $post->ID, 'raw' ) ); ?>"><?php esc_html_e( 'Texte je Kanal & Versand bearbeiten', 'vereinsplugin' ); ?></a>
				<?php else : ?>
					<span class="pp-meta"><?php esc_html_e( 'Texte je Kanal, Bilder und den Versand an Social Media & Presse bearbeitet der Vorstand im Veranstaltungs-Editor.', 'vereinsplugin' ); ?></span>
				<?php endif; ?>
				<?php vp_projekt_loesen_knopf( $p, 'veranstaltung_post_id' ); ?>
			</p>
		<?php elseif ( current_user_can( 'jbf_edit_events' ) ) : ?>
			<p class="pp-meta"><?php esc_html_e( 'Legt einen Entwurf im Veranstaltungs-Publisher an (Titel, Datum, Ort und Beschreibung werden übernommen). Von dort geht die Ankündigung an Website, Social Media, Messenger und Presse.', 'vereinsplugin' ); ?></p>
			<?php vp_projekt_verknuepfen_knopf( $p, 'veranstaltung', __( 'Veranstaltungs-Beitrag anlegen', 'vereinsplugin' ) ); ?>
		<?php endif; ?>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Maßnahmen', 'vereinsplugin' ); ?></h3>
	<table class="pp-table">
		<thead><tr><th><?php esc_html_e( 'Fällig', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Kanal', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Maßnahme', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Wer', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Status', 'vereinsplugin' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $punkte as $x ) :
			$ueber = 'erledigt' !== $x->status && $x->zeitpunkt && $x->zeitpunkt < $heute; ?>
			<tr class="<?php echo 'erledigt' === $x->status ? 'is-erledigt' : ''; ?>">
				<td class="<?php echo $ueber ? 'pp-ueberfaellig' : ''; ?>"><?php echo esc_html( $x->zeitpunkt ? mysql2date( 'd.m.Y', $x->zeitpunkt ) : '–' ); ?></td>
				<td><?php echo esc_html( $kanaele[ $x->kanal ] ?? $x->kanal ); ?></td>
				<td><strong><?php echo esc_html( $x->titel ); ?></strong><?php if ( $x->beschreibung ) : ?><div class="pp-meta"><?php echo nl2br( esc_html( $x->beschreibung ) ); ?></div><?php endif; ?></td>
				<td><?php echo $x->verantwortlich_user_id ? esc_html( pp_user_display_name( $x->verantwortlich_user_id ) ) : ''; ?></td>
				<td class="pp-ablauf-aktionen"><?php vp_projekt_punkt_aktionen( $p, $x, $weiter[ $x->status ] ?? 'offen' ); ?>
					<a class="pp-meta" href="<?php echo esc_url( vp_projekt_url( $p->id, 'oeffentlichkeit', array( 'p_punkt' => (int) $x->id ) ) . '#vp-punkt-form' ); ?>"><?php esc_html_e( 'bearbeiten', 'vereinsplugin' ); ?></a></td>
			</tr>
		<?php endforeach; ?>
		<?php if ( ! $punkte ) : ?><tr><td colspan="5" class="pp-empty"><?php esc_html_e( 'Noch keine Maßnahmen geplant.', 'vereinsplugin' ); ?></td></tr><?php endif; ?>
		</tbody>
	</table>
	<?php
	$edit_punkt = null;
	foreach ( $punkte as $x ) {
		if ( (int) $x->id === $edit ) {
			$edit_punkt = $x;
		}
	}
	?>
	<details class="pp-werkzeug" id="vp-punkt-form" <?php echo $edit_punkt ? 'open' : ''; ?>>
		<summary class="pp-details-summary"><?php echo $edit_punkt ? esc_html__( 'Maßnahme bearbeiten', 'vereinsplugin' ) : esc_html__( '+ Maßnahme', 'vereinsplugin' ); ?></summary>
		<?php vp_projekt_punkt_formular( $p, 'oeffentlichkeit', $edit_punkt ); ?>
	</details>
	<?php if ( ! $punkte && $p->beginn ) : ?>
		<?php vp_projekt_verknuepfen_knopf( $p, 'vorschlag_pr', __( 'Übliche Checkliste als Vorschlag einfügen', 'vereinsplugin' ) ); ?>
	<?php endif; ?>
	<?php if ( current_user_can( 'vp_manage_members' ) && function_exists( 'vp_member_sections' ) ) : ?>
		<p class="pp-meta"><a href="<?php echo esc_url( vp_kreis_mitgliederbereich_url( array( 'vp_tab' => 'newsletter' ) ) ); ?>"><?php esc_html_e( 'Newsletter an die Mitglieder schreiben', 'vereinsplugin' ); ?></a></p>
	<?php endif;
}

/* ---- Finanzen ---- */

function vp_projekt_tab_finanzen( $p, $k ) {
	global $wpdb;
	$posten    = vp_projekt_punkte( $p->id, 'kalkulation' );
	$ausgaben  = 0.0;
	$einnahmen = 0.0;
	foreach ( $posten as $x ) {
		if ( (float) $x->betrag < 0 ) {
			$ausgaben += abs( (float) $x->betrag );
		} else {
			$einnahmen += (float) $x->betrag;
		}
	}
	$ergebnis = $einnahmen - $ausgaben;
	?>
	<h3><?php esc_html_e( 'Kalkulation', 'vereinsplugin' ); ?></h3>
	<p class="pp-meta"><?php esc_html_e( 'Womit rechnet ihr? Die Summe der geplanten Ausgaben ist der Vorschlag fürs Budget.', 'vereinsplugin' ); ?></p>
	<table class="pp-table">
		<thead><tr><th><?php esc_html_e( 'Posten', 'vereinsplugin' ); ?></th><th style="text-align:right"><?php esc_html_e( 'Einnahme', 'vereinsplugin' ); ?></th><th style="text-align:right"><?php esc_html_e( 'Ausgabe', 'vereinsplugin' ); ?></th><th></th></tr></thead>
		<tbody>
		<?php foreach ( $posten as $x ) : ?>
			<tr>
				<td><?php echo esc_html( $x->titel ); ?><?php if ( $x->beschreibung ) : ?><div class="pp-meta"><?php echo esc_html( $x->beschreibung ); ?></div><?php endif; ?></td>
				<td style="text-align:right"><?php echo (float) $x->betrag > 0 ? esc_html( vp_kreis_eur( $x->betrag ) ) : ''; ?></td>
				<td style="text-align:right"><?php echo (float) $x->betrag < 0 ? esc_html( vp_kreis_eur( abs( (float) $x->betrag ) ) ) : ''; ?></td>
				<td>
					<?php vp_kreis_form( 'vp_projekt_punkt_delete', 'pp-inline' ); ?>
						<input type="hidden" name="projekt_id" value="<?php echo (int) $p->id; ?>"><input type="hidden" name="punkt_id" value="<?php echo (int) $x->id; ?>">
						<button type="submit" class="pp-link-danger"><?php esc_html_e( 'löschen', 'vereinsplugin' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		<?php if ( $posten ) : ?>
			<tr class="pp-summenzeile"><td><strong><?php esc_html_e( 'Summe', 'vereinsplugin' ); ?></strong></td><td style="text-align:right"><strong><?php echo esc_html( vp_kreis_eur( $einnahmen ) ); ?></strong></td><td style="text-align:right"><strong><?php echo esc_html( vp_kreis_eur( $ausgaben ) ); ?></strong></td><td style="<?php echo $ergebnis < 0 ? 'color:#b91c1c' : 'color:#15803d'; ?>"><strong><?php echo esc_html( vp_kreis_eur( $ergebnis ) ); ?></strong></td></tr>
		<?php else : ?>
			<tr><td colspan="4" class="pp-empty"><?php esc_html_e( 'Noch keine Posten.', 'vereinsplugin' ); ?></td></tr>
		<?php endif; ?>
		</tbody>
	</table>
	<details class="pp-werkzeug" <?php echo $posten ? '' : 'open'; ?>>
		<summary class="pp-details-summary"><?php esc_html_e( '+ Posten', 'vereinsplugin' ); ?></summary>
		<?php vp_projekt_punkt_formular( $p, 'kalkulation' ); ?>
	</details>

	<h3><?php esc_html_e( 'Budget & tatsächliche Ausgaben', 'vereinsplugin' ); ?></h3>
	<?php
	if ( ! function_exists( 'vp_kreis_kasse_verfuegbar' ) || ! vp_kreis_kasse_verfuegbar() ) {
		echo '<p class="pp-empty">' . esc_html__( 'Das Buchhaltungs-Modul ist nicht aktiv.', 'vereinsplugin' ) . '</p>';
		return;
	}
	$b = $k['budget'];
	if ( ! $b ) {
		if ( ! $p->gremium_id ) {
			echo '<p class="pp-hint">' . esc_html__( 'Budgets hängen an einem Kreis – bitte dem Projekt zuerst einen Kreis zuordnen.', 'vereinsplugin' ) . '</p>';
			return;
		}
		if ( ! vp_projekt_darf_budget( $p ) ) {
			echo '<p class="pp-hint">' . esc_html__( 'Noch kein Budget. Ein Budget legt an, wer die Kasse des Kreises führt (bzw. die Kassier:in des Vorstands) – die Kalkulation oben ist die Grundlage dafür.', 'vereinsplugin' ) . '</p>';
			return;
		}
		$felder = '<input type="text" inputmode="decimal" name="betrag" required value="' . esc_attr( number_format( $ausgaben, 2, ',', '' ) ) . '" title="' . esc_attr__( 'Betrag in €', 'vereinsplugin' ) . '"> €';
		vp_projekt_verknuepfen_knopf( $p, 'budget', __( 'Budget für dieses Projekt anlegen', 'vereinsplugin' ), $felder );
		$kreis_budgets = vp_kreis_budgets( $p->gremium_id );
		if ( $kreis_budgets ) {
			$opts = '<select name="budget_id" required><option value="">' . esc_html__( 'Bestehendes Kreisbudget…', 'vereinsplugin' ) . '</option>';
			foreach ( $kreis_budgets as $kb ) {
				$opts .= '<option value="' . (int) $kb->id . '">' . esc_html( $kb->zweck . ' (' . vp_kreis_eur( $kb->rest ) . ' übrig)' ) . '</option>';
			}
			$opts .= '</select>';
			vp_projekt_verknuepfen_knopf( $p, 'budget_waehlen', __( 'verknüpfen', 'vereinsplugin' ), $opts );
		}
		return;
	}
	?>
	<div class="pp-kpis">
		<div class="pp-kpi"><strong><?php echo esc_html( vp_kreis_eur( $b->betrag ) ); ?></strong><span><?php esc_html_e( 'Budget', 'vereinsplugin' ); ?></span></div>
		<div class="pp-kpi"><strong><?php echo esc_html( vp_kreis_eur( $b->verbraucht ) ); ?></strong><span><?php esc_html_e( 'ausgegeben', 'vereinsplugin' ); ?></span></div>
		<div class="pp-kpi<?php echo (float) $b->rest < 0 ? ' is-neg' : ''; ?>"><strong><?php echo esc_html( vp_kreis_eur( $b->rest ) ); ?></strong><span><?php esc_html_e( 'übrig', 'vereinsplugin' ); ?></span></div>
		<?php if ( $posten ) : ?><div class="pp-kpi"><strong><?php echo esc_html( vp_kreis_eur( $ausgaben ) ); ?></strong><span><?php esc_html_e( 'kalkuliert', 'vereinsplugin' ); ?></span></div><?php endif; ?>
	</div>
	<p>
		<strong><?php echo esc_html( $b->zweck ); ?></strong>
		<?php if ( current_user_can( 'jb_submit_auslagen' ) && function_exists( 'vp_member_sections' ) ) : ?>
			<a class="pp-btn pp-btn-small" href="<?php echo esc_url( vp_kreis_mitgliederbereich_url( array( 'vp_tab' => 'auslage', 'jb_budget' => (int) $b->id ) ) ); ?>"><?php esc_html_e( 'Auslage für dieses Projekt einreichen', 'vereinsplugin' ); ?></a>
		<?php endif; ?>
		<?php if ( $p->gremium_id ) : ?><a class="pp-btn pp-btn-small" href="<?php echo esc_url( vp_kreis_url( $p->gremium_id, 'kasse' ) ); ?>"><?php esc_html_e( 'Zur Kreiskasse', 'vereinsplugin' ); ?></a><?php endif; ?>
		<?php vp_projekt_loesen_knopf( $p, 'budget_id' ); ?>
	</p>
	<?php
	$auslagen  = function_exists( 'jb_table_auslagen' ) ? $wpdb->get_results( $wpdb->prepare( 'SELECT a.*, u.display_name AS user_name FROM ' . jb_table_auslagen() . " a LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id WHERE a.budget_id = %d ORDER BY a.ausgabe_datum DESC", $b->id ) ) : array();
	$buchungen = function_exists( 'jb_table_journal' ) ? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . jb_table_journal() . ' WHERE budget_id = %d ORDER BY buchung_datum DESC', $b->id ) ) : array();
	?>
	<table class="pp-table">
		<thead><tr><th><?php esc_html_e( 'Datum', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Was', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Art', 'vereinsplugin' ); ?></th><th style="text-align:right"><?php esc_html_e( 'Betrag', 'vereinsplugin' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $auslagen as $a ) : ?>
			<tr><td><?php echo esc_html( mysql2date( 'd.m.Y', $a->ausgabe_datum ) ); ?></td><td><?php echo esc_html( $a->beschreibung ); ?><div class="pp-meta"><?php echo esc_html( $a->user_name ); ?></div></td><td><span class="pp-badge pp-status-<?php echo esc_attr( $a->status ); ?>"><?php echo esc_html( sprintf( __( 'Auslage · %s', 'vereinsplugin' ), $a->status ) ); ?></span></td><td style="text-align:right;color:#b91c1c"><?php echo esc_html( vp_kreis_eur( -abs( (float) $a->betrag ) ) ); ?></td></tr>
		<?php endforeach; ?>
		<?php foreach ( $buchungen as $r ) : ?>
			<tr><td><?php echo esc_html( mysql2date( 'd.m.Y', $r->buchung_datum ) ); ?></td><td><?php echo esc_html( $r->beschreibung ); ?></td><td><span class="pp-badge"><?php esc_html_e( 'Buchung', 'vereinsplugin' ); ?></span></td><td style="text-align:right;<?php echo (float) $r->betrag < 0 ? 'color:#b91c1c' : 'color:#15803d'; ?>"><?php echo esc_html( vp_kreis_eur( $r->betrag ) ); ?></td></tr>
		<?php endforeach; ?>
		<?php if ( ! $auslagen && ! $buchungen ) : ?><tr><td colspan="4" class="pp-empty"><?php esc_html_e( 'Noch keine Ausgaben auf diesem Budget.', 'vereinsplugin' ); ?></td></tr><?php endif; ?>
		</tbody>
	</table>
	<?php
}

/* -------------------------------------------------------------------------
 * Formular-Handler
 * ---------------------------------------------------------------------- */

function vp_projekt_oder_fehler( $id ) {
	$p = vp_projekt_get( $id );
	if ( ! $p ) {
		vp_kreis_fehler( __( 'Projekt nicht gefunden.', 'vereinsplugin' ) );
	}
	return $p;
}

function vp_projekt_zurueck( $p, $tab, $extra = array() ) {
	vp_kreis_redirect( array_merge( array( 'pp_view' => 'projekt', 'id' => (int) $p->id, 'p_tab' => $tab, 'p_punkt' => false, 'pp_saved' => '1' ), $extra ) );
}

add_action( 'admin_post_vp_projekt_save', 'vp_projekt_handle_save' );
function vp_projekt_handle_save() {
	vp_kreis_check( 'vp_projekt_save' );
	global $wpdb;
	$id    = (int) ( $_POST['id'] ?? 0 );
	$titel = sanitize_text_field( wp_unslash( $_POST['titel'] ?? '' ) );
	if ( '' === $titel ) {
		vp_kreis_fehler( __( 'Das Projekt braucht einen Titel.', 'vereinsplugin' ) );
	}
	$status = sanitize_key( $_POST['status'] ?? 'idee' );
	$art    = sanitize_key( $_POST['art'] ?? 'veranstaltung' );
	$zahl   = function ( $k ) {
		return ( isset( $_POST[ $k ] ) && '' !== trim( (string) $_POST[ $k ] ) ) ? max( 0, (int) $_POST[ $k ] ) : null;
	};
	$row = array(
		'titel'                  => $titel,
		'art'                    => isset( vp_projekt_arten()[ $art ] ) ? $art : 'veranstaltung',
		'status'                 => isset( vp_projekt_status_liste()[ $status ] ) ? $status : 'idee',
		'gremium_id'             => (int) ( $_POST['gremium_id'] ?? 0 ) ?: null,
		'verantwortlich_user_id' => (int) ( $_POST['verantwortlich_user_id'] ?? 0 ) ?: null,
		'beginn'                 => vp_projekt_dt_in( $_POST['beginn'] ?? '' ),
		'ende'                   => vp_projekt_dt_in( $_POST['ende'] ?? '' ),
		'ort'                    => sanitize_text_field( wp_unslash( $_POST['ort'] ?? '' ) ),
		'ziel'                   => sanitize_textarea_field( wp_unslash( $_POST['ziel'] ?? '' ) ),
		'geaendert_am'           => current_time( 'mysql' ),
	);
	// Felder, die nur das ausführliche Formular hat, nicht mit Leerwerten überschreiben.
	if ( $id ) {
		$row['zielgruppe']            = sanitize_text_field( wp_unslash( $_POST['zielgruppe'] ?? '' ) );
		$row['beschreibung']          = sanitize_textarea_field( wp_unslash( $_POST['beschreibung'] ?? '' ) );
		$row['notizen']               = sanitize_textarea_field( wp_unslash( $_POST['notizen'] ?? '' ) );
		$row['helfende_bedarf']       = $zahl( 'helfende_bedarf' );
		$row['teilnehmende_erwartet'] = $zahl( 'teilnehmende_erwartet' );
	}

	if ( $id ) {
		$alt = vp_projekt_oder_fehler( $id );
		$wpdb->update( vp_projekt_table(), $row, array( 'id' => $id ) );
		// Verknüpften Kalendertermin mitziehen.
		if ( $alt->termin_id && $row['beginn'] ) {
			$wpdb->update( $wpdb->prefix . 'pp_termine', array( 'titel' => $titel, 'datum' => $row['beginn'], 'ort' => $row['ort'], 'gremium_id' => $row['gremium_id'] ), array( 'id' => (int) $alt->termin_id ) );
		}
	} else {
		$row['erstellt_von'] = get_current_user_id();
		$row['erstellt_am']  = current_time( 'mysql' );
		$wpdb->insert( vp_projekt_table(), $row );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			vp_kreis_fehler( __( 'Projekt konnte nicht gespeichert werden (Datenbankfehler).', 'vereinsplugin' ) );
		}
	}
	vp_projekt_zurueck( vp_projekt_get( $id ), 'uebersicht', array( 'k_tab' => false ) );
}

add_action( 'admin_post_vp_projekt_delete', 'vp_projekt_handle_delete' );
function vp_projekt_handle_delete() {
	vp_kreis_check( 'vp_projekt_delete' );
	global $wpdb;
	$p = vp_projekt_oder_fehler( (int) $_POST['id'] );
	$wpdb->delete( vp_projekt_punkte_table(), array( 'projekt_id' => $p->id ) );
	$wpdb->delete( vp_projekt_helfende_table(), array( 'projekt_id' => $p->id ) );
	if ( vp_kreis_col_exists( $wpdb->prefix . 'pp_aufgaben', 'projekt_id' ) ) {
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}pp_aufgaben SET projekt_id = NULL WHERE projekt_id = %d", $p->id ) );
	}
	$wpdb->delete( vp_projekt_table(), array( 'id' => $p->id ) );
	$ziel = $p->gremium_id
		? array( 'pp_view' => 'kreis', 'id' => (int) $p->gremium_id, 'k_tab' => 'projekte', 'p_tab' => false )
		: array( 'pp_view' => 'projekte', 'id' => false, 'p_tab' => false );
	vp_kreis_redirect( $ziel + array( 'pp_saved' => '1' ) );
}

add_action( 'admin_post_vp_projekt_punkt_save', 'vp_projekt_handle_punkt_save' );
function vp_projekt_handle_punkt_save() {
	vp_kreis_check( 'vp_projekt_punkt_save' );
	global $wpdb;
	$p       = vp_projekt_oder_fehler( (int) $_POST['projekt_id'] );
	$bereich = sanitize_key( $_POST['bereich'] ?? '' );
	if ( ! in_array( $bereich, array( 'ablauf', 'oeffentlichkeit', 'kalkulation' ), true ) ) {
		vp_kreis_fehler( __( 'Unbekannter Bereich.', 'vereinsplugin' ) );
	}
	$tab = 'kalkulation' === $bereich ? 'finanzen' : $bereich;
	$row = array(
		'projekt_id'             => (int) $p->id,
		'bereich'                => $bereich,
		'titel'                  => sanitize_text_field( wp_unslash( $_POST['titel'] ?? '' ) ),
		'beschreibung'           => sanitize_textarea_field( wp_unslash( $_POST['beschreibung'] ?? '' ) ),
		'verantwortlich_user_id' => (int) ( $_POST['verantwortlich_user_id'] ?? 0 ) ?: null,
	);
	if ( '' === $row['titel'] ) {
		vp_kreis_fehler( __( 'Bitte eintragen, worum es geht.', 'vereinsplugin' ) );
	}
	if ( 'ablauf' === $bereich ) {
		$phase                = sanitize_key( $_POST['phase'] ?? 'vorbereitung' );
		$row['phase']         = isset( vp_projekt_phasen()[ $phase ] ) ? $phase : 'vorbereitung';
		$row['zeitpunkt']     = vp_projekt_dt_in( $_POST['zeitpunkt'] ?? '' );
		$row['dauer_minuten'] = ( isset( $_POST['dauer_minuten'] ) && '' !== $_POST['dauer_minuten'] ) ? max( 0, (int) $_POST['dauer_minuten'] ) : null;
	} elseif ( 'oeffentlichkeit' === $bereich ) {
		$kanal            = sanitize_key( $_POST['kanal'] ?? 'sonstiges' );
		$row['kanal']     = isset( vp_projekt_kanaele()[ $kanal ] ) ? $kanal : 'sonstiges';
		$row['zeitpunkt'] = vp_projekt_dt_in( $_POST['zeitpunkt'] ?? '' );
	} else {
		$betrag        = abs( (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['betrag'] ?? '0' ) ) ) );
		$row['betrag'] = ( 'einnahme' === ( $_POST['richtung'] ?? '' ) ? 1 : -1 ) * $betrag;
	}
	$pid = (int) ( $_POST['punkt_id'] ?? 0 );
	if ( $pid ) {
		$wpdb->update( vp_projekt_punkte_table(), $row, array( 'id' => $pid, 'projekt_id' => (int) $p->id ) );
	} else {
		$row['erstellt_am'] = current_time( 'mysql' );
		$wpdb->insert( vp_projekt_punkte_table(), $row );
	}
	vp_projekt_zurueck( $p, $tab );
}

add_action( 'admin_post_vp_projekt_punkt_status', 'vp_projekt_handle_punkt_status' );
function vp_projekt_handle_punkt_status() {
	vp_kreis_check( 'vp_projekt_punkt_status' );
	global $wpdb;
	$p      = vp_projekt_oder_fehler( (int) $_POST['projekt_id'] );
	$status = sanitize_key( $_POST['status'] ?? 'offen' );
	$punkt  = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vp_projekt_punkte_table() . ' WHERE id = %d AND projekt_id = %d', (int) $_POST['punkt_id'], $p->id ) );
	if ( $punkt && isset( vp_projekt_punkt_status()[ $status ] ) ) {
		$wpdb->update( vp_projekt_punkte_table(), array( 'status' => $status ), array( 'id' => (int) $punkt->id ) );
	}
	vp_projekt_zurueck( $p, $punkt && 'oeffentlichkeit' === $punkt->bereich ? 'oeffentlichkeit' : 'ablauf' );
}

add_action( 'admin_post_vp_projekt_punkt_delete', 'vp_projekt_handle_punkt_delete' );
function vp_projekt_handle_punkt_delete() {
	vp_kreis_check( 'vp_projekt_punkt_delete' );
	global $wpdb;
	$p     = vp_projekt_oder_fehler( (int) $_POST['projekt_id'] );
	$punkt = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vp_projekt_punkte_table() . ' WHERE id = %d AND projekt_id = %d', (int) $_POST['punkt_id'], $p->id ) );
	if ( $punkt ) {
		$wpdb->delete( vp_projekt_punkte_table(), array( 'id' => (int) $punkt->id ) );
	}
	$tabs = array( 'ablauf' => 'ablauf', 'oeffentlichkeit' => 'oeffentlichkeit', 'kalkulation' => 'finanzen' );
	vp_projekt_zurueck( $p, $punkt ? $tabs[ $punkt->bereich ] ?? 'uebersicht' : 'uebersicht' );
}

add_action( 'admin_post_vp_projekt_todo', 'vp_projekt_handle_todo' );
function vp_projekt_handle_todo() {
	vp_kreis_check( 'vp_projekt_todo' );
	global $wpdb;
	$p   = vp_projekt_oder_fehler( (int) $_POST['projekt_id'] );
	$was = sanitize_key( $_POST['was'] ?? '' );
	$t   = $wpdb->prefix . 'pp_aufgaben';

	if ( ! vp_kreis_col_exists( $t, 'projekt_id' ) ) {
		vp_kreis_fehler( __( 'Die Datenbank ist noch nicht aktualisiert – bitte Seite neu laden.', 'vereinsplugin' ) );
	}

	if ( 'neu' === $was ) {
		$titel = sanitize_text_field( wp_unslash( $_POST['titel'] ?? '' ) );
		if ( '' === $titel ) {
			vp_kreis_fehler( __( 'Das ToDo braucht einen Titel.', 'vereinsplugin' ) );
		}
		$wpdb->insert( $t, array(
			'titel'                       => $titel,
			'beschreibung'                => sanitize_textarea_field( wp_unslash( $_POST['beschreibung'] ?? '' ) ),
			'verantwortlich_user_id'      => (int) ( $_POST['verantwortlich_user_id'] ?? 0 ) ?: null,
			'verantwortliches_gremium_id' => $p->gremium_id ?: null,
			'faelligkeitsdatum'           => ! empty( $_POST['faelligkeitsdatum'] ) ? sanitize_text_field( wp_unslash( $_POST['faelligkeitsdatum'] ) ) : null,
			'quelle_termin_id'            => $p->termin_id ?: null,
			'projekt_id'                  => (int) $p->id,
		) );
	} elseif ( in_array( $was, array( 'toggle', 'loeschen' ), true ) ) {
		$a = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d AND projekt_id = %d", (int) $_POST['aufgabe_id'], $p->id ) );
		if ( $a && 'toggle' === $was ) {
			$wpdb->update( $t, array( 'status' => 'erledigt' === $a->status ? 'offen' : 'erledigt' ), array( 'id' => (int) $a->id ) );
		} elseif ( $a ) {
			$wpdb->delete( $t, array( 'id' => (int) $a->id ) );
		}
	} elseif ( 'set' === $was ) {
		if ( ! $p->beginn || ! function_exists( 'pp_get_set_eintraege' ) ) {
			vp_kreis_fehler( __( 'Für ein Aufgaben-Set braucht das Projekt einen Beginn.', 'vereinsplugin' ) );
		}
		$erzeugt = 0;
		$skip    = 0;
		// Gleiche Logik wie pp_handle_front_set_anwenden(), nur mit dem
		// Projektbeginn als Bezugsdatum und Dopplungsschutz je Projekt.
		foreach ( pp_get_set_eintraege( (int) $_POST['set_id'] ) as $e ) {
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE projekt_id = %d AND quelle_set_eintrag_id = %d", $p->id, $e->id ) ) ) {
				$skip++;
				continue;
			}
			$basis = array(
				'titel'                       => $e->titel,
				'beschreibung'                => $e->beschreibung,
				'verantwortliches_gremium_id' => $p->gremium_id ?: null,
				'faelligkeitsdatum'           => gmdate( 'Y-m-d', strtotime( $p->beginn . ' -' . (int) $e->vorlauf_tage . ' days' ) ),
				'quelle_termin_id'            => $p->termin_id ?: null,
				'quelle_set_eintrag_id'       => $e->id,
				'projekt_id'                  => (int) $p->id,
			);
			$besetzt = $e->rollenvorlage_id ? pp_get_aktuelle_besetzungen( $e->rollenvorlage_id ) : array();
			if ( $besetzt && ( $e->zuweisung ?? 'eine' ) === 'eine' ) {
				$besetzt = array( $besetzt[0] );
			}
			if ( ! $besetzt ) {
				$besetzt = array( (object) array( 'user_id' => null ) );
			}
			foreach ( $besetzt as $b ) {
				$wpdb->insert( $t, $basis + array( 'verantwortlich_user_id' => $b->user_id ) );
				$erzeugt++;
			}
		}
		vp_projekt_zurueck( $p, 'todos', array( 'pp_set_erzeugt' => $erzeugt, 'pp_set_uebersprungen' => $skip, 'pp_saved' => false ) );
	}
	vp_projekt_zurueck( $p, 'todos' );
}

add_action( 'admin_post_vp_projekt_helfer', 'vp_projekt_handle_helfer' );
function vp_projekt_handle_helfer() {
	vp_kreis_check( 'vp_projekt_helfer' );
	global $wpdb;
	$p      = vp_projekt_oder_fehler( (int) $_POST['projekt_id'] );
	$was    = sanitize_key( $_POST['was'] ?? '' );
	$status = sanitize_key( $_POST['status'] ?? 'angefragt' );
	$status = isset( vp_projekt_helfende_status()[ $status ] ) ? $status : 'angefragt';

	if ( 'neu' === $was ) {
		$uid  = (int) ( $_POST['user_id'] ?? 0 );
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		if ( ! $uid && '' === $name ) {
			vp_kreis_fehler( __( 'Bitte ein Mitglied wählen oder einen Namen eintragen.', 'vereinsplugin' ) );
		}
		$wpdb->insert( vp_projekt_helfende_table(), array(
			'projekt_id'  => (int) $p->id,
			'user_id'     => $uid ?: null,
			'name'        => $uid ? pp_user_display_name( $uid ) : $name,
			'kontakt'     => sanitize_text_field( wp_unslash( $_POST['kontakt'] ?? '' ) ),
			'aufgabe'     => sanitize_text_field( wp_unslash( $_POST['aufgabe'] ?? '' ) ),
			'status'      => $status,
			'erstellt_am' => current_time( 'mysql' ),
		) );
	} elseif ( 'status' === $was ) {
		$wpdb->update( vp_projekt_helfende_table(), array( 'status' => $status ), array( 'id' => (int) $_POST['helfer_id'], 'projekt_id' => (int) $p->id ) );
	} elseif ( 'loeschen' === $was ) {
		$wpdb->delete( vp_projekt_helfende_table(), array( 'id' => (int) $_POST['helfer_id'], 'projekt_id' => (int) $p->id ) );
	}
	vp_projekt_zurueck( $p, 'helfende' );
}

/** Legt verknüpfte Objekte an bzw. verknüpft/löst sie. */
add_action( 'admin_post_vp_projekt_verknuepfen', 'vp_projekt_handle_verknuepfen' );
function vp_projekt_handle_verknuepfen() {
	vp_kreis_check( 'vp_projekt_verknuepfen' );
	global $wpdb;
	$p   = vp_projekt_oder_fehler( (int) $_POST['id'] );
	$was = sanitize_key( $_POST['was'] ?? '' );
	$tab = 'uebersicht';
	$set = function ( $feld, $wert ) use ( $wpdb, $p ) {
		$wpdb->update( vp_projekt_table(), array( $feld => $wert, 'geaendert_am' => current_time( 'mysql' ) ), array( 'id' => (int) $p->id ) );
	};

	switch ( $was ) {
		case 'loesen':
			$feld = sanitize_key( $_POST['feld'] ?? '' );
			$tabs = array( 'termin_id' => 'uebersicht', 'budget_id' => 'finanzen', 'schicht_event_id' => 'helfende', 'veranstaltung_post_id' => 'oeffentlichkeit', 'formular_id' => 'uebersicht' );
			if ( isset( $tabs[ $feld ] ) ) {
				$set( $feld, null );
				$tab = $tabs[ $feld ];
			}
			break;

		case 'termin':
			if ( ! $p->beginn ) {
				vp_kreis_fehler( __( 'Erst den Beginn eintragen.', 'vereinsplugin' ) );
			}
			$wpdb->insert( $wpdb->prefix . 'pp_termine', array( 'titel' => $p->titel, 'datum' => $p->beginn, 'ort' => $p->ort, 'gremium_id' => $p->gremium_id ?: null ) );
			$set( 'termin_id', (int) $wpdb->insert_id );
			break;

		case 'schichtplan':
		case 'schichtplan_waehlen':
			$tab = 'helfende';
			if ( ! current_user_can( 'wl_manage_wishes' ) || ! function_exists( 'wl_generate_event_slug' ) ) {
				wp_die( esc_html__( 'Keine Berechtigung für Schichtpläne.', 'vereinsplugin' ) );
			}
			if ( 'schichtplan_waehlen' === $was ) {
				$eid = (int) ( $_POST['event_id'] ?? 0 );
				if ( $eid && wl_get_event( $eid ) ) {
					$set( 'schicht_event_id', $eid );
				}
				break;
			}
			$wpdb->insert( $wpdb->prefix . 'wl_shift_events', array(
				'titel'               => $p->titel,
				'slug'                => wl_generate_event_slug( $p->titel ),
				'beschreibung'        => (string) $p->ziel,
				'veranstaltungsdatum' => $p->beginn ? mysql2date( 'Y-m-d', $p->beginn ) : null,
				'aktiv'               => 1,
				'erstellt_von'        => get_current_user_id(),
			) );
			if ( $wpdb->insert_id ) {
				$set( 'schicht_event_id', (int) $wpdb->insert_id );
			}
			break;

		case 'veranstaltung':
			$tab = 'oeffentlichkeit';
			if ( ! post_type_exists( 'veranstaltung' ) || ! current_user_can( 'jbf_edit_events' ) ) {
				wp_die( esc_html__( 'Keine Berechtigung für Veranstaltungs-Beiträge.', 'vereinsplugin' ) );
			}
			$post_id = wp_insert_post( array(
				'post_type'    => 'veranstaltung',
				'post_status'  => 'draft',
				'post_title'   => $p->titel,
				'post_content' => (string) $p->beschreibung,
				'post_excerpt' => wp_trim_words( (string) $p->ziel, 40 ),
				'post_author'  => get_current_user_id(),
			), true );
			if ( is_wp_error( $post_id ) ) {
				vp_kreis_fehler( $post_id->get_error_message() );
			}
			update_post_meta( $post_id, '_jbf_date_start', $p->beginn ? mysql2date( 'Y-m-d\TH:i', $p->beginn ) : '' );
			update_post_meta( $post_id, '_jbf_location', $p->ort );
			update_post_meta( $post_id, '_jbf_short_text', wp_strip_all_tags( $p->beschreibung ?: $p->ziel ) );
			$set( 'veranstaltung_post_id', (int) $post_id );
			break;

		case 'formular':
			if ( ! current_user_can( 'vp_manage_formulare' ) || ! function_exists( 'vp_formular_table' ) ) {
				wp_die( esc_html__( 'Keine Berechtigung für Formulare.', 'vereinsplugin' ) );
			}
			$feld = function ( $label, $type, $req ) {
				return array( 'key' => sanitize_title( $label ), 'label' => $label, 'type' => $type, 'required' => $req, 'options' => array(), 'show_if' => '', 'show_if_value' => '' );
			};
			$titel = sprintf( __( 'Anmeldung: %s', 'vereinsplugin' ), $p->titel );
			$slug  = sanitize_title( $titel ) ?: 'anmeldung';
			$base  = $slug;
			$n     = 2;
			while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . vp_formular_table() . ' WHERE slug = %s', $slug ) ) ) {
				$slug = $base . '-' . $n++;
			}
			$verantw = $p->verantwortlich_user_id ? get_userdata( $p->verantwortlich_user_id ) : null;
			$wpdb->insert( vp_formular_table(), array(
				'titel'                  => $titel,
				'slug'                   => $slug,
				'beschreibung'           => trim( vp_projekt_zeitraum( $p ) . ( $p->ort ? ', ' . $p->ort : '' ) ),
				'felder'                 => wp_json_encode( array(
					$feld( __( 'Name', 'vereinsplugin' ), 'text', 1 ),
					$feld( __( 'E-Mail', 'vereinsplugin' ), 'email', 1 ),
					$feld( __( 'Telefon', 'vereinsplugin' ), 'tel', 0 ),
					$feld( __( 'Anmerkungen', 'vereinsplugin' ), 'textarea', 0 ),
				) ),
				'status'                 => 'entwurf',
				'login_pflicht'          => 0,
				'schliesst_am'           => $p->beginn ? gmdate( 'Y-m-d', strtotime( $p->beginn . ' -1 day' ) ) . ' 23:59:59' : null,
				'dank_text'              => __( 'Danke – du bist angemeldet!', 'vereinsplugin' ),
				'benachrichtigung_email' => $verantw ? $verantw->user_email : '',
				'erstellt_von'           => get_current_user_id(),
				'erstellt_am'            => current_time( 'mysql' ),
				'aktualisiert_am'        => current_time( 'mysql' ),
			) );
			if ( $wpdb->insert_id ) {
				$set( 'formular_id', (int) $wpdb->insert_id );
			}
			break;

		case 'budget':
		case 'budget_waehlen':
			$tab = 'finanzen';
			if ( ! vp_projekt_darf_budget( $p ) ) {
				wp_die( esc_html__( 'Nur wer die Kasse des Kreises führt, kann Budgets anlegen.', 'vereinsplugin' ) );
			}
			if ( 'budget_waehlen' === $was ) {
				$bid = (int) ( $_POST['budget_id'] ?? 0 );
				foreach ( vp_kreis_budgets( $p->gremium_id ) as $kb ) {
					if ( (int) $kb->id === $bid ) {
						$set( 'budget_id', $bid );
					}
				}
				break;
			}
			$bid = vp_kreis_budget_speichern( array(
				'zweck'                  => $p->titel,
				'beschreibung'           => sprintf( __( 'Projekt: %s', 'vereinsplugin' ), $p->titel ),
				'betrag'                 => wp_unslash( $_POST['betrag'] ?? '0' ),
				'jahr'                   => $p->beginn ? (int) mysql2date( 'Y', $p->beginn ) : (int) current_time( 'Y' ),
				'gremium_id'             => (int) $p->gremium_id,
				'verantwortlich_user_id' => (int) $p->verantwortlich_user_id,
			) );
			if ( $bid ) {
				$set( 'budget_id', $bid );
			}
			break;

		case 'helfende_kreis':
			$tab = 'helfende';
			if ( $p->gremium_id ) {
				$schon = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . vp_projekt_helfende_table() . ' WHERE projekt_id = %d AND user_id IS NOT NULL', $p->id ) ) );
				foreach ( pp_get_kreis_mitglieder( $p->gremium_id ) as $m ) {
					if ( in_array( (int) $m->user_id, $schon, true ) ) {
						continue;
					}
					$wpdb->insert( vp_projekt_helfende_table(), array( 'projekt_id' => (int) $p->id, 'user_id' => (int) $m->user_id, 'name' => pp_user_display_name( $m->user_id ), 'status' => 'angefragt', 'erstellt_am' => current_time( 'mysql' ) ) );
				}
			}
			break;

		case 'helfende_schicht':
			$tab = 'helfende';
			$s   = vp_projekt_schichtplan( $p->schicht_event_id );
			if ( $s ) {
				$vorhandene = vp_projekt_helfende( $p->id );
				foreach ( $s['personen'] as $person ) {
					$gibt_es = false;
					foreach ( $vorhandene as $h ) {
						if ( ( $person['user_id'] && (int) $h->user_id === $person['user_id'] )
							|| ( ! $person['user_id'] && 0 === strcasecmp( trim( $h->name ), trim( $person['name'] ) ) ) ) {
							$gibt_es = true;
							if ( 'angefragt' === $h->status ) {
								$wpdb->update( vp_projekt_helfende_table(), array( 'status' => 'zugesagt' ), array( 'id' => (int) $h->id ) );
							}
						}
					}
					if ( ! $gibt_es ) {
						$wpdb->insert( vp_projekt_helfende_table(), array(
							'projekt_id'  => (int) $p->id,
							'user_id'     => $person['user_id'] ?: null,
							'name'        => $person['name'],
							'kontakt'     => (string) $person['email'],
							'aufgabe'     => sprintf( _n( '%d Schicht', '%d Schichten', $person['n'], 'vereinsplugin' ), $person['n'] ),
							'status'      => 'zugesagt',
							'erstellt_am' => current_time( 'mysql' ),
						) );
					}
				}
			}
			break;

		case 'vorschlag_ablauf':
			$tab = 'ablauf';
			if ( $p->beginn && ! vp_projekt_punkte( $p->id, 'ablauf' ) ) {
				$ende = $p->ende ?: gmdate( 'Y-m-d H:i:s', strtotime( $p->beginn . ' +4 hours' ) );
				$vorschlag = array(
					array( 'vorbereitung', __( 'Ziel, Rahmen und Verantwortliche klären', 'vereinsplugin' ), vp_projekt_relativ( $p, -56, '18:00' ) ),
					array( 'vorbereitung', __( 'Kalkulation & Budget, Genehmigungen/Raum anfragen', 'vereinsplugin' ), vp_projekt_relativ( $p, -42, '18:00' ) ),
					array( 'vorbereitung', __( 'Helfende anfragen, Schichtplan veröffentlichen', 'vereinsplugin' ), vp_projekt_relativ( $p, -28, '18:00' ) ),
					array( 'vorbereitung', __( 'Öffentlichkeitsarbeit startet', 'vereinsplugin' ), vp_projekt_relativ( $p, -21, '18:00' ) ),
					array( 'vorbereitung', __( 'Einkauf, Material & Technik bereit', 'vereinsplugin' ), vp_projekt_relativ( $p, -3, '18:00' ) ),
					array( 'durchfuehrung', __( 'Aufbau', 'vereinsplugin' ), gmdate( 'Y-m-d H:i:s', strtotime( $p->beginn . ' -2 hours' ) ) ),
					array( 'durchfuehrung', __( 'Briefing der Helfenden', 'vereinsplugin' ), gmdate( 'Y-m-d H:i:s', strtotime( $p->beginn . ' -30 minutes' ) ) ),
					array( 'durchfuehrung', __( 'Beginn / Einlass', 'vereinsplugin' ), $p->beginn ),
					array( 'durchfuehrung', __( 'Ende & Abbau', 'vereinsplugin' ), $ende ),
					array( 'nachbereitung', __( 'Danke an Helfende, Nachbericht & Fotos', 'vereinsplugin' ), vp_projekt_relativ( $p, 3, '18:00' ) ),
					array( 'nachbereitung', __( 'Belege einreichen & abrechnen', 'vereinsplugin' ), vp_projekt_relativ( $p, 7, '18:00' ) ),
					array( 'nachbereitung', __( 'Auswertung im Kreis: Was lief gut, was nehmen wir mit?', 'vereinsplugin' ), vp_projekt_relativ( $p, 14, '18:00' ) ),
				);
				foreach ( $vorschlag as $i => $v ) {
					$wpdb->insert( vp_projekt_punkte_table(), array( 'projekt_id' => (int) $p->id, 'bereich' => 'ablauf', 'phase' => $v[0], 'titel' => $v[1], 'zeitpunkt' => $v[2], 'sortierung' => $i, 'erstellt_am' => current_time( 'mysql' ) ) );
				}
			}
			break;

		case 'vorschlag_pr':
			$tab = 'oeffentlichkeit';
			if ( $p->beginn && ! vp_projekt_punkte( $p->id, 'oeffentlichkeit' ) ) {
				$vorschlag = array(
					array( 'website', __( 'Termin auf Website & in Veranstaltungskalender eintragen', 'vereinsplugin' ), -28 ),
					array( 'plakat', __( 'Plakate & Flyer gestalten, drucken und aushängen', 'vereinsplugin' ), -21 ),
					array( 'kooperation', __( 'Partner, Schulen und andere Vereine informieren', 'vereinsplugin' ), -21 ),
					array( 'social', __( 'Ankündigung auf Social Media', 'vereinsplugin' ), -14 ),
					array( 'presse', __( 'Pressemitteilung an die Lokalpresse', 'vereinsplugin' ), -10 ),
					array( 'newsletter', __( 'Newsletter an Mitglieder', 'vereinsplugin' ), -7 ),
					array( 'messenger', __( 'Erinnerung in Messenger-Gruppen und Story', 'vereinsplugin' ), -2 ),
					array( 'sonstiges', __( 'Fotos machen (Einverständnis beachten)', 'vereinsplugin' ), 0 ),
					array( 'social', __( 'Nachbericht & Danke-Post', 'vereinsplugin' ), 2 ),
				);
				foreach ( $vorschlag as $i => $v ) {
					$wpdb->insert( vp_projekt_punkte_table(), array( 'projekt_id' => (int) $p->id, 'bereich' => 'oeffentlichkeit', 'kanal' => $v[0], 'titel' => $v[1], 'zeitpunkt' => vp_projekt_relativ( $p, $v[2], '12:00' ), 'sortierung' => $i, 'erstellt_am' => current_time( 'mysql' ) ) );
				}
			}
			break;
	}
	vp_projekt_zurueck( $p, $tab );
}
