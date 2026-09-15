<?php
/**
 * Kern: Kreise als Klammer über alle Module.
 *
 * Ein Kreis (pp_gremien) bündelt, was sonst in getrennten Modulen liegt:
 *
 *   Übersicht              – Zweck, Leitung, Kennzahlen aus allen Bereichen
 *   Mitglieder & Rollen    – die bisherige Kreis-Seite (ProtokollPro)
 *   Projekte               – Veranstaltungs-/Projektorganizer (projekte.php)
 *   Wunschliste            – wunschliste.gremium_id
 *   Kreiskasse / Finanzen  – jb_budgets.gremium_id; Buchungen und Auslagen
 *                            hängen über ihr Budget am Kreis
 *   Sitzungen & Aufgaben   – Protokolle, Aufgaben, Termine, Themen des Kreises
 *
 * Kasse: Ein Kreis hat eine EIGENE Kasse, sobald eine seiner Rollen als
 * Kassenrolle markiert (pp_rollenvorlagen.kasse) bzw. – ohne Markierung – als
 * „Kassier…/Finanz…" benannt UND aktuell besetzt ist. Die Person darf dann für
 * den Kreis buchen, Budgets planen und Auslagen auf Kreisbudgets entscheiden.
 * Rechtlich bleibt alles im Journal des Vereins (Unterkasse = Budget).
 *
 * Routing: ?pp_view=kreis&id=<id>&k_tab=<tab>
 */

defined( 'ABSPATH' ) || exit;

define( 'VP_KREIS_DB_VERSION', '1' );

/* -------------------------------------------------------------------------
 * Schema
 * ---------------------------------------------------------------------- */

function vp_kreis_col_exists( $table, $column ) {
	static $cache = array();
	$k = $table . '.' . $column;
	if ( ! isset( $cache[ $k ] ) ) {
		global $wpdb;
		$cache[ $k ] = (bool) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
			$table,
			$column
		) );
	}
	return $cache[ $k ];
}

/**
 * Läuft nach den Modul-Upgrades (Priorität 10), damit deren Tabellen schon
 * stehen. Der Stand merkt sich, welche Module aktiv waren – wird eins später
 * eingeschaltet, laufen die Spalten-Nachrüstungen erneut.
 */
add_action( 'plugins_loaded', 'vp_kreis_maybe_upgrade', 20 );
function vp_kreis_maybe_upgrade() {
	global $wpdb;
	$ziele = array(
		array( 'jb_create_tables', $wpdb->prefix . 'jb_budgets', 'gremium_id', '`gremium_id` BIGINT UNSIGNED DEFAULT NULL' ),
		array( 'wl_create_tables', $wpdb->prefix . 'wunschliste', 'gremium_id', '`gremium_id` BIGINT UNSIGNED DEFAULT NULL' ),
		array( 'pp_create_tables', $wpdb->prefix . 'pp_rollenvorlagen', 'kasse', '`kasse` TINYINT NOT NULL DEFAULT 0' ),
		array( 'pp_create_tables', $wpdb->prefix . 'pp_aufgaben', 'projekt_id', '`projekt_id` BIGINT UNSIGNED DEFAULT NULL' ),
	);
	$flags = '';
	foreach ( $ziele as $z ) {
		$flags .= function_exists( $z[0] ) ? '1' : '0';
	}
	$stand = VP_KREIS_DB_VERSION . ':' . $flags;
	if ( get_option( 'vp_kreis_db_version' ) === $stand ) {
		return;
	}
	foreach ( $ziele as $z ) {
		if ( ! function_exists( $z[0] ) ) {
			continue;
		}
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $z[1] ) ) ) {
			return; // Modul-Tabellen noch nicht angelegt → beim nächsten Request erneut.
		}
		if ( ! vp_kreis_col_exists( $z[1], $z[2] ) ) {
			// Tabellen-/Spaltennamen stammen aus dem Code, nicht aus Nutzereingaben.
			$wpdb->query( "ALTER TABLE `{$z[1]}` ADD COLUMN {$z[3]}" );
		}
	}
	update_option( 'vp_kreis_db_version', $stand );
}

/* -------------------------------------------------------------------------
 * Allgemeine Helfer
 * ---------------------------------------------------------------------- */

function vp_kreis_eur( $n ) {
	return number_format( (float) $n, 2, ',', '.' ) . ' €';
}

/** Link in den Kern-Mitgliederbereich (Auslage einreichen, Schichtplan, Abstimmung …). */
function vp_kreis_mitgliederbereich_url( $args = array() ) {
	$base = get_option( 'vp_member_area_url' ) ?: get_permalink();
	return $base ? add_query_arg( $args, $base ) : '';
}

function vp_kreis_url( $gremium_id, $tab = 'uebersicht', $extra = array() ) {
	return pp_front_url( array_merge( array( 'pp_view' => 'kreis', 'id' => (int) $gremium_id, 'k_tab' => $tab ), $extra ) );
}

/** Nach einem Formular zurück – alte Hinweis-Parameter vorher entfernen. */
function vp_kreis_redirect( $args = array() ) {
	$ret = isset( $_POST['pp_return'] ) ? remove_query_arg( array( 'pp_saved', 'pp_error', 'pp_set_erzeugt', 'pp_set_uebersprungen' ), wp_unslash( $_POST['pp_return'] ) ) : '';
	pp_front_redirect( $ret, $args );
}

function vp_kreis_fehler( $text ) {
	vp_kreis_redirect( array( 'pp_error' => rawurlencode( $text ) ) );
}

/** <form>-Kopf für admin-post mit Nonce und Rücksprung. */
function vp_kreis_form( $action, $class = 'pp-form', $extra_attr = '' ) {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $class ) . '" ' . $extra_attr . '>'; // phpcs:ignore
	wp_nonce_field( $action );
	echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
	pp_front_return_field();
}

function vp_kreis_check( $action ) {
	if ( ! is_user_logged_in() || ! function_exists( 'pp_can_manage' ) || ! pp_can_manage() ) {
		wp_die( esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) );
	}
	check_admin_referer( $action );
}

/** Personen-Auswahl: Kreismitglieder zuerst, dann alle anderen. */
function vp_kreis_personen_optionen( $selected = 0, $gremium_id = 0, $leer = '' ) {
	$selected = (int) $selected;
	$html     = '<option value="">' . esc_html( $leer ?: __( '– niemand –', 'vereinsplugin' ) ) . '</option>';
	$kreis    = array();
	if ( $gremium_id && function_exists( 'pp_get_kreis_mitglieder' ) ) {
		foreach ( pp_get_kreis_mitglieder( $gremium_id ) as $m ) {
			$kreis[ (int) $m->user_id ] = true;
		}
	}
	$alle   = function_exists( 'pp_get_moegliche_mitglieder' ) ? pp_get_moegliche_mitglieder() : get_users( array( 'orderby' => 'display_name' ) );
	$gruppe = array( 'kreis' => '', 'rest' => '' );
	foreach ( $alle as $u ) {
		$opt = sprintf( '<option value="%d"%s>%s</option>', (int) $u->ID, selected( $selected, (int) $u->ID, false ), esc_html( $u->display_name ) );
		$gruppe[ isset( $kreis[ (int) $u->ID ] ) ? 'kreis' : 'rest' ] .= $opt;
	}
	if ( $gruppe['kreis'] ) {
		$html .= '<optgroup label="' . esc_attr__( 'Im Kreis', 'vereinsplugin' ) . '">' . $gruppe['kreis'] . '</optgroup>'
			. '<optgroup label="' . esc_attr__( 'Alle Mitglieder', 'vereinsplugin' ) . '">' . $gruppe['rest'] . '</optgroup>';
	} else {
		$html .= $gruppe['rest'];
	}
	return $html;
}

/** Kreis-Auswahl (aktive Gremien). */
function vp_kreis_optionen( $selected = 0, $leer = '' ) {
	$html = '<option value="">' . esc_html( $leer ?: __( '– kein Kreis –', 'vereinsplugin' ) ) . '</option>';
	if ( function_exists( 'pp_get_gremien' ) ) {
		foreach ( pp_get_gremien() as $g ) {
			$html .= sprintf( '<option value="%d"%s>%s</option>', (int) $g->id, selected( (int) $selected, (int) $g->id, false ), esc_html( $g->name ) );
		}
	}
	return $html;
}

/** id => Name aller Kreise (auch aufgelöste, für Beschriftungen). */
function vp_kreis_namen() {
	static $namen = null;
	if ( null === $namen ) {
		$namen = array();
		if ( function_exists( 'pp_get_gremien' ) ) {
			foreach ( pp_get_gremien( null, false ) as $g ) {
				$namen[ (int) $g->id ] = $g->name;
			}
		}
	}
	return $namen;
}

/* -------------------------------------------------------------------------
 * Kasse: Rollen, Rechte, Zahlen
 * ---------------------------------------------------------------------- */

/** Rollen, die die Kreiskasse führen (markiert – sonst nach Namen erkannt). */
function vp_kreis_kassen_rollen( $gremium_id ) {
	if ( ! function_exists( 'pp_get_rollenvorlagen_fuer_gremium' ) ) {
		return array();
	}
	$alle     = pp_get_rollenvorlagen_fuer_gremium( $gremium_id );
	$markiert = array_filter( $alle, function ( $v ) { return ! empty( $v->kasse ); } );
	if ( $markiert ) {
		return array_values( $markiert );
	}
	return array_values( array_filter( $alle, function ( $v ) { return (bool) preg_match( '/kass|finanz/i', $v->bezeichnung ); } ) );
}

/** WP-User-IDs, die aktuell die Kreiskasse führen. */
function vp_kreis_kassier_ids( $gremium_id ) {
	$ids = array();
	foreach ( vp_kreis_kassen_rollen( $gremium_id ) as $rolle ) {
		foreach ( pp_get_aktuelle_besetzungen( $rolle->id ) as $b ) {
			$ids[] = (int) $b->user_id;
		}
	}
	return array_values( array_unique( $ids ) );
}

function vp_kreis_hat_eigene_kasse( $gremium_id ) {
	return (bool) vp_kreis_kassier_ids( $gremium_id );
}

/** Darf die Person für diesen Kreis buchen / Budgets planen / Auslagen entscheiden? */
function vp_kreis_darf_kasse( $gremium_id, $user_id = 0 ) {
	$user_id = $user_id ?: get_current_user_id();
	if ( ! $user_id ) {
		return false;
	}
	if ( user_can( $user_id, 'jb_approve_auslagen' ) || user_can( $user_id, 'manage_options' ) ) {
		return true;
	}
	return $gremium_id && in_array( (int) $user_id, vp_kreis_kassier_ids( $gremium_id ), true );
}

function vp_kreis_kasse_verfuegbar() {
	global $wpdb;
	return function_exists( 'jb_budgets_get_all' ) && vp_kreis_col_exists( $wpdb->prefix . 'jb_budgets', 'gremium_id' );
}

/** Aktive Budgets des Kreises (Objekte, inkl. verbraucht/rest aus jb_budgets_get_all). */
function vp_kreis_budgets( $gremium_id ) {
	if ( ! vp_kreis_kasse_verfuegbar() ) {
		return array();
	}
	$out = array();
	foreach ( jb_budgets_get_all() as $b ) {
		$b = (object) $b;
		if ( (int) ( $b->gremium_id ?? 0 ) === (int) $gremium_id ) {
			$out[] = $b;
		}
	}
	return $out;
}

function vp_kreis_budget( $budget_id ) {
	if ( ! vp_kreis_kasse_verfuegbar() || ! $budget_id ) {
		return null;
	}
	foreach ( jb_budgets_get_all() as $b ) {
		if ( (int) $b['id'] === (int) $budget_id ) {
			return (object) $b;
		}
	}
	return null;
}

/**
 * Kennzahlen der Kreiskasse.
 * verfuegbar = geplante Budgets + Einnahmen auf Kreisbudgets − verbraucht.
 */
function vp_kreis_finanzen( $gremium_id ) {
	global $wpdb;
	$budgets = vp_kreis_budgets( $gremium_id );
	$ids     = array_map( function ( $b ) { return (int) $b->id; }, $budgets );
	$f       = array(
		'budgets'    => $budgets,
		'geplant'    => 0.0,
		'verbraucht' => 0.0,
		'einnahmen'  => 0.0,
		'offen'      => 0.0,
		'offen_n'    => 0,
		'verfuegbar' => 0.0,
	);
	foreach ( $budgets as $b ) {
		$f['geplant']    += (float) $b->betrag;
		$f['verbraucht'] += (float) $b->verbraucht;
	}
	if ( $ids ) {
		$in = implode( ',', $ids ); // nur Integer
		if ( function_exists( 'jb_table_journal' ) ) {
			$f['einnahmen'] = (float) $wpdb->get_var( 'SELECT COALESCE(SUM(betrag),0) FROM ' . jb_table_journal() . " WHERE budget_id IN ($in) AND betrag > 0" );
		}
		if ( function_exists( 'jb_table_auslagen' ) ) {
			$row = $wpdb->get_row( 'SELECT COUNT(*) AS n, COALESCE(SUM(betrag),0) AS s FROM ' . jb_table_auslagen() . " WHERE budget_id IN ($in) AND status = 'ausstehend'" );
			$f['offen_n'] = (int) ( $row->n ?? 0 );
			$f['offen']   = (float) ( $row->s ?? 0 );
		}
	}
	$f['verfuegbar'] = round( $f['geplant'] + $f['einnahmen'] - $f['verbraucht'], 2 );
	return $f;
}

/**
 * Budget speichern – direkt, weil jb_budget_save() nur Vorstands-Kassier:innen
 * erlaubt und die Kreis-Zuordnung nicht kennt. Rechteprüfung beim Aufrufer.
 */
function vp_kreis_budget_speichern( array $d ) {
	global $wpdb;
	$row = array(
		'zweck'                  => sanitize_text_field( $d['zweck'] ?? '' ),
		'beschreibung'           => sanitize_textarea_field( $d['beschreibung'] ?? '' ),
		'betrag'                 => (float) str_replace( ',', '.', (string) ( $d['betrag'] ?? 0 ) ),
		'jahr'                   => (int) ( $d['jahr'] ?? 0 ) ?: null,
		'verantwortlich_user_id' => (int) ( $d['verantwortlich_user_id'] ?? 0 ) ?: null,
		'kostenstelle'           => sanitize_text_field( $d['kostenstelle'] ?? '' ),
		'gremium_id'             => (int) ( $d['gremium_id'] ?? 0 ) ?: null,
	);
	if ( '' === $row['zweck'] ) {
		return 0;
	}
	$id = (int) ( $d['id'] ?? 0 );
	if ( $id ) {
		$wpdb->update( jb_table_budgets(), $row, array( 'id' => $id ) );
		return $id;
	}
	$row['aktiv'] = 1;
	$wpdb->insert( jb_table_budgets(), $row );
	return (int) $wpdb->insert_id;
}

/* -------------------------------------------------------------------------
 * Reiter der Kreis-Seite
 * ---------------------------------------------------------------------- */

function vp_kreis_tabs( $kreis ) {
	$tabs = array(
		'uebersicht' => __( 'Übersicht', 'vereinsplugin' ),
		'struktur'   => __( 'Mitglieder & Rollen', 'vereinsplugin' ),
		'projekte'   => __( 'Projekte & Veranstaltungen', 'vereinsplugin' ),
		'wuensche'   => __( 'Wunschliste', 'vereinsplugin' ),
		'kasse'      => vp_kreis_hat_eigene_kasse( $kreis->id ) ? __( 'Kreiskasse', 'vereinsplugin' ) : __( 'Finanzen', 'vereinsplugin' ),
		'sitzungen'  => __( 'Sitzungen & Aufgaben', 'vereinsplugin' ),
	);
	if ( ! function_exists( 'vp_projekt_render_liste' ) ) {
		unset( $tabs['projekte'] );
	}
	if ( ! function_exists( 'wl_get_wuensche_mit_score' ) ) {
		unset( $tabs['wuensche'] );
	}
	if ( ! vp_kreis_kasse_verfuegbar() ) {
		unset( $tabs['kasse'] );
	}
	return apply_filters( 'vp_kreis_tabs', $tabs, $kreis );
}

/** Gibt die Reiter aus und liefert den aktiven Reiter zurück. */
function vp_kreis_tab_nav( $kreis ) {
	$tabs = vp_kreis_tabs( $kreis );
	$tab  = isset( $_GET['k_tab'] ) ? sanitize_key( wp_unslash( $_GET['k_tab'] ) ) : 'uebersicht';
	// Rollen-Bearbeiten-Links (…&rolle=) gehören zur Struktur.
	if ( isset( $_GET['rolle'] ) && ! isset( $_GET['k_tab'] ) ) {
		$tab = 'struktur';
	}
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = 'uebersicht';
	}
	echo '<nav class="pp-tabs">';
	foreach ( $tabs as $k => $label ) {
		printf(
			'<a class="pp-tab%s" href="%s">%s</a>',
			$k === $tab ? ' is-active' : '',
			esc_url( vp_kreis_url( $kreis->id, $k ) ),
			esc_html( $label )
		);
	}
	echo '</nav>';
	return $tab;
}

function vp_kreis_render_tab( $kreis, $tab ) {
	switch ( $tab ) {
		case 'projekte':
			vp_projekt_render_liste( (int) $kreis->id );
			break;
		case 'wuensche':
			vp_kreis_render_wuensche( $kreis );
			break;
		case 'kasse':
			vp_kreis_render_kasse( $kreis );
			break;
		case 'sitzungen':
			vp_kreis_render_sitzungen( $kreis );
			break;
		default:
			if ( has_action( 'vp_kreis_render_tab_' . $tab ) ) {
				do_action( 'vp_kreis_render_tab_' . $tab, $kreis );
			} else {
				vp_kreis_render_uebersicht( $kreis );
			}
	}
}

/* ---- Übersicht ---- */

function vp_kreis_render_uebersicht( $kreis ) {
	global $wpdb;
	$gid        = (int) $kreis->id;
	$mitglieder = pp_get_kreis_mitglieder( $gid );
	$bin_dabei  = pp_ist_kreis_mitglied( $gid, get_current_user_id() );

	$leitung = array();
	foreach ( pp_get_rollenvorlagen_fuer_gremium( $gid ) as $v ) {
		if ( ! preg_match( '/leitung|sprecher/i', $v->bezeichnung ) ) {
			continue;
		}
		foreach ( pp_get_aktuelle_besetzungen( $v->id ) as $b ) {
			$leitung[] = pp_user_display_name( $b->user_id );
		}
	}
	$kassier = array_map( 'pp_user_display_name', vp_kreis_kassier_ids( $gid ) );

	$aufgaben = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}pp_aufgaben WHERE verantwortliches_gremium_id = %d AND status = 'offen'
		 ORDER BY faelligkeitsdatum IS NULL, faelligkeitsdatum LIMIT 6",
		$gid
	) );
	$termine = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}pp_termine WHERE gremium_id = %d AND datum >= %s ORDER BY datum LIMIT 5",
		$gid,
		current_time( 'mysql' )
	) );
	?>
	<div class="pp-cards">
		<div class="pp-card">
			<h3><?php esc_html_e( 'Zweck & Leute', 'vereinsplugin' ); ?></h3>
			<p><?php echo $kreis->beschreibung ? esc_html( $kreis->beschreibung ) : '<span class="pp-empty">' . esc_html__( 'Zweck noch nicht beschrieben.', 'vereinsplugin' ) . '</span>'; ?></p>
			<ul class="pp-list">
				<li><?php esc_html_e( 'Leitung', 'vereinsplugin' ); ?><span class="pp-meta"><?php echo $leitung ? esc_html( implode( ', ', $leitung ) ) : esc_html__( 'nicht besetzt', 'vereinsplugin' ); ?></span></li>
				<li><?php esc_html_e( 'Kasse', 'vereinsplugin' ); ?><span class="pp-meta"><?php echo $kassier ? esc_html( implode( ', ', $kassier ) ) : esc_html__( 'läuft über die Vereinskasse', 'vereinsplugin' ); ?></span></li>
				<li>
					<a href="<?php echo esc_url( vp_kreis_url( $gid, 'struktur' ) ); ?>"><?php echo esc_html( sprintf( _n( '%d Person arbeitet mit', '%d Personen arbeiten mit', count( $mitglieder ), 'vereinsplugin' ), count( $mitglieder ) ) ); ?></a>
				</li>
			</ul>
			<?php if ( ! $bin_dabei ) : ?>
				<?php vp_kreis_form( 'pp_front_kreis_beitreten', 'pp-inline' ); ?>
					<input type="hidden" name="gremium_id" value="<?php echo $gid; ?>">
					<p><button type="submit" class="pp-btn pp-btn-primary pp-btn-small"><?php esc_html_e( 'Diesem Kreis beitreten', 'vereinsplugin' ); ?></button></p>
				</form>
			<?php endif; ?>
		</div>

		<?php if ( vp_kreis_kasse_verfuegbar() ) :
			$f = vp_kreis_finanzen( $gid ); ?>
			<div class="pp-card">
				<h3><?php echo vp_kreis_hat_eigene_kasse( $gid ) ? esc_html__( 'Kreiskasse', 'vereinsplugin' ) : esc_html__( 'Finanzen', 'vereinsplugin' ); ?></h3>
				<?php if ( $f['budgets'] ) : ?>
					<div class="pp-kpis">
						<div class="pp-kpi<?php echo $f['verfuegbar'] < 0 ? ' is-neg' : ''; ?>"><strong><?php echo esc_html( vp_kreis_eur( $f['verfuegbar'] ) ); ?></strong><span><?php esc_html_e( 'verfügbar', 'vereinsplugin' ); ?></span></div>
						<div class="pp-kpi"><strong><?php echo esc_html( vp_kreis_eur( $f['verbraucht'] ) ); ?></strong><span><?php esc_html_e( 'ausgegeben', 'vereinsplugin' ); ?></span></div>
					</div>
					<?php if ( $f['offen_n'] ) : ?>
						<p class="pp-meta"><?php echo esc_html( sprintf( __( '%1$d Auslage(n) über %2$s warten auf Entscheidung.', 'vereinsplugin' ), $f['offen_n'], vp_kreis_eur( $f['offen'] ) ) ); ?></p>
					<?php endif; ?>
				<?php else : ?>
					<p class="pp-empty"><?php esc_html_e( 'Noch kein Budget für diesen Kreis.', 'vereinsplugin' ); ?></p>
				<?php endif; ?>
				<p><a class="pp-btn pp-btn-small" href="<?php echo esc_url( vp_kreis_url( $gid, 'kasse' ) ); ?>"><?php esc_html_e( 'Zur Kasse', 'vereinsplugin' ); ?></a></p>
			</div>
		<?php endif; ?>

		<?php if ( function_exists( 'vp_projekte_liste' ) ) :
			$projekte = vp_projekte_liste( array( 'gremium_id' => $gid, 'laufend' => true ) ); ?>
			<div class="pp-card">
				<h3><?php esc_html_e( 'Laufende Projekte', 'vereinsplugin' ); ?></h3>
				<?php if ( $projekte ) : ?>
					<ul class="pp-list">
						<?php foreach ( array_slice( $projekte, 0, 5 ) as $p ) : ?>
							<li><a href="<?php echo esc_url( vp_projekt_url( $p->id ) ); ?>"><?php echo esc_html( $p->titel ); ?></a>
								<span class="pp-meta"><?php echo esc_html( vp_projekt_status_label( $p->status ) . ( $p->beginn ? ' · ' . mysql2date( 'd.m.Y', $p->beginn ) : '' ) ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="pp-empty"><?php esc_html_e( 'Gerade kein Projekt.', 'vereinsplugin' ); ?></p>
				<?php endif; ?>
				<p><a class="pp-btn pp-btn-small" href="<?php echo esc_url( vp_kreis_url( $gid, 'projekte' ) ); ?>"><?php esc_html_e( 'Projekt planen', 'vereinsplugin' ); ?></a></p>
			</div>
		<?php endif; ?>

		<div class="pp-card">
			<h3><?php esc_html_e( 'Offene Aufgaben', 'vereinsplugin' ); ?></h3>
			<?php if ( $aufgaben ) : ?>
				<ul class="pp-list">
					<?php foreach ( $aufgaben as $a ) : ?>
						<li><?php echo esc_html( $a->titel ); ?>
							<span class="pp-meta"><?php echo esc_html( ( $a->verantwortlich_user_id ? pp_user_display_name( $a->verantwortlich_user_id ) : __( 'noch niemand', 'vereinsplugin' ) ) . ' · ' . ( $a->faelligkeitsdatum ? mysql2date( 'd.m.Y', $a->faelligkeitsdatum ) : __( 'ohne Frist', 'vereinsplugin' ) ) ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="pp-empty"><?php esc_html_e( 'Nichts offen.', 'vereinsplugin' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="pp-card">
			<h3><?php esc_html_e( 'Nächste Termine', 'vereinsplugin' ); ?></h3>
			<?php if ( $termine ) : ?>
				<ul class="pp-list">
					<?php foreach ( $termine as $t ) : ?>
						<li><?php echo esc_html( $t->titel ); ?><span class="pp-meta"><?php echo esc_html( mysql2date( 'd.m.Y H:i', $t->datum ) . ( $t->ort ? ' · ' . $t->ort : '' ) ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="pp-empty"><?php esc_html_e( 'Keine Termine.', 'vereinsplugin' ); ?></p>
			<?php endif; ?>
		</div>

		<?php if ( function_exists( 'wl_get_wuensche_mit_score' ) ) :
			$wuensche = array_slice( vp_kreis_wuensche( $gid, true ), 0, 5 ); ?>
			<div class="pp-card">
				<h3><?php esc_html_e( 'Wunschliste', 'vereinsplugin' ); ?></h3>
				<?php if ( $wuensche ) : ?>
					<ul class="pp-list">
						<?php foreach ( $wuensche as $w ) : ?>
							<li><?php echo esc_html( $w->titel ); ?><span class="pp-meta"><?php echo esc_html( trim( ( function_exists( 'wl_format_preis' ) ? wl_format_preis( $w ) : '' ) . ' · ' . sprintf( _n( '%d Stimme', '%d Stimmen', (int) $w->vote_count, 'vereinsplugin' ), (int) $w->vote_count ), ' ·' ) ); ?></span></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="pp-empty"><?php esc_html_e( 'Noch keine Wünsche.', 'vereinsplugin' ); ?></p>
				<?php endif; ?>
				<p><a class="pp-btn pp-btn-small" href="<?php echo esc_url( vp_kreis_url( $gid, 'wuensche' ) ); ?>"><?php esc_html_e( 'Zur Wunschliste', 'vereinsplugin' ); ?></a></p>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/* ---- Wunschliste ---- */

/** Wünsche des Kreises mit Abstimmungs-Score (bestbewertete zuerst). */
function vp_kreis_wuensche( $gremium_id, $nur_offen = false ) {
	if ( ! function_exists( 'wl_get_wuensche_mit_score' ) ) {
		return array();
	}
	$out = array();
	foreach ( wl_get_wuensche_mit_score( false ) as $w ) {
		if ( (int) ( $w->gremium_id ?? 0 ) !== (int) $gremium_id ) {
			continue;
		}
		if ( $nur_offen && ( 'erfuellt' === $w->status || 'aktiv' !== $w->vote_status ) ) {
			continue;
		}
		$out[] = $w;
	}
	return $out;
}

function vp_kreis_wunsch_betrag( $w ) {
	if ( (float) $w->betrag > 0 ) {
		return (float) $w->betrag;
	}
	return (float) ( $w->preis_bis ?: $w->preis_von );
}

function vp_kreis_render_wuensche( $kreis ) {
	global $wpdb;
	$gid       = (int) $kreis->id;
	$wuensche  = vp_kreis_wuensche( $gid );
	$darf      = current_user_can( 'wl_manage_wishes' );
	$darf_geld = vp_kreis_kasse_verfuegbar() && vp_kreis_darf_kasse( $gid );
	$labels    = array( 'offen' => __( 'Offen', 'vereinsplugin' ), 'in_bearbeitung' => __( 'In Bearbeitung', 'vereinsplugin' ), 'erfuellt' => __( 'Erfüllt', 'vereinsplugin' ) );

	$summe_offen = 0.0;
	foreach ( $wuensche as $w ) {
		if ( 'erfuellt' !== $w->status ) {
			$summe_offen += vp_kreis_wunsch_betrag( $w );
		}
	}
	$abstimmung = vp_kreis_mitgliederbereich_url( array( 'vp_tab' => 'abstimmung', 'wl_kreis' => $gid ) );
	?>
	<div class="pp-page-head">
		<h3><?php esc_html_e( 'Wunschliste des Kreises', 'vereinsplugin' ); ?></h3>
		<?php if ( $abstimmung && function_exists( 'vp_member_sections' ) ) : ?>
			<a class="pp-btn pp-btn-small" href="<?php echo esc_url( $abstimmung ); ?>"><?php esc_html_e( 'Abstimmen', 'vereinsplugin' ); ?></a>
		<?php endif; ?>
	</div>
	<p class="pp-meta"><?php esc_html_e( 'Wünsche dieses Kreises erscheinen auch in der gemeinsamen Abstimmung (dort nach Kreis filterbar). Die Reihenfolge ergibt sich aus den Stimmen.', 'vereinsplugin' ); ?></p>

	<div class="pp-kpis">
		<div class="pp-kpi"><strong><?php echo count( $wuensche ); ?></strong><span><?php esc_html_e( 'Wünsche', 'vereinsplugin' ); ?></span></div>
		<div class="pp-kpi"><strong><?php echo esc_html( vp_kreis_eur( $summe_offen ) ); ?></strong><span><?php esc_html_e( 'noch nicht erfüllt', 'vereinsplugin' ); ?></span></div>
		<?php if ( vp_kreis_kasse_verfuegbar() ) :
			$f = vp_kreis_finanzen( $gid ); ?>
			<div class="pp-kpi<?php echo $f['verfuegbar'] < 0 ? ' is-neg' : ''; ?>"><strong><?php echo esc_html( vp_kreis_eur( $f['verfuegbar'] ) ); ?></strong><span><?php esc_html_e( 'in der Kasse verfügbar', 'vereinsplugin' ); ?></span></div>
		<?php endif; ?>
	</div>

	<table class="pp-table">
		<thead><tr><th>#</th><th><?php esc_html_e( 'Wunsch', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Preis', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Stimmen', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Status', 'vereinsplugin' ); ?></th><?php if ( $darf_geld ) : ?><th></th><?php endif; ?></tr></thead>
		<tbody>
		<?php foreach ( $wuensche as $i => $w ) : ?>
			<tr>
				<td><?php echo ! empty( $w->hat_veto ) ? '🚫' : (int) ( $i + 1 ); ?></td>
				<td><strong><?php echo esc_html( $w->titel ); ?></strong>
					<?php if ( $w->beschreibung ) : ?><div class="pp-meta"><?php echo esc_html( wp_trim_words( $w->beschreibung, 16 ) ); ?></div><?php endif; ?></td>
				<td><?php echo esc_html( function_exists( 'wl_format_preis' ) ? ( wl_format_preis( $w ) ?: '–' ) : '–' ); ?></td>
				<td><?php echo (int) $w->vote_count; ?> <span class="pp-meta"><?php echo esc_html( sprintf( __( 'Score %d', 'vereinsplugin' ), (int) $w->vote_score ) ); ?></span></td>
				<td>
					<?php if ( $darf ) : ?>
						<?php vp_kreis_form( 'vp_kreis_wunsch_status', 'pp-inline-form' ); ?>
							<input type="hidden" name="gremium_id" value="<?php echo $gid; ?>">
							<input type="hidden" name="wunsch_id" value="<?php echo (int) $w->id; ?>">
							<select name="status" onchange="this.form.submit()">
								<?php foreach ( $labels as $k => $l ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $w->status, $k ); ?>><?php echo esc_html( $l ); ?></option>
								<?php endforeach; ?>
							</select>
							<noscript><button class="pp-btn pp-btn-small"><?php esc_html_e( 'OK', 'vereinsplugin' ); ?></button></noscript>
						</form>
					<?php else : ?>
						<?php echo esc_html( $labels[ $w->status ] ?? $w->status ); ?>
					<?php endif; ?>
				</td>
				<?php if ( $darf_geld ) : ?>
					<td>
						<?php if ( 'erfuellt' !== $w->status && vp_kreis_wunsch_betrag( $w ) > 0 ) : ?>
							<?php vp_kreis_form( 'vp_kreis_wunsch_budget', 'pp-inline' ); ?>
								<input type="hidden" name="gremium_id" value="<?php echo $gid; ?>">
								<input type="hidden" name="wunsch_id" value="<?php echo (int) $w->id; ?>">
								<button type="submit" class="pp-btn pp-btn-small" title="<?php esc_attr_e( 'Legt ein Budget in Höhe des Preises an und setzt den Wunsch auf „In Bearbeitung".', 'vereinsplugin' ); ?>"><?php esc_html_e( 'Budget freigeben', 'vereinsplugin' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				<?php endif; ?>
			</tr>
		<?php endforeach; ?>
		<?php if ( ! $wuensche ) : ?>
			<tr><td colspan="6" class="pp-empty"><?php esc_html_e( 'Dieser Kreis hat noch keine Wünsche.', 'vereinsplugin' ); ?></td></tr>
		<?php endif; ?>
		</tbody>
	</table>

	<?php if ( ! $darf ) {
		return;
	} ?>

	<h3><?php esc_html_e( 'Neuer Wunsch für diesen Kreis', 'vereinsplugin' ); ?></h3>
	<?php vp_kreis_form( 'vp_kreis_wunsch_save', 'pp-form pp-form-grid' ); ?>
		<input type="hidden" name="gremium_id" value="<?php echo $gid; ?>">
		<label class="pp-span-2"><?php esc_html_e( 'Was wünscht ihr euch? *', 'vereinsplugin' ); ?>
			<input type="text" name="titel" required placeholder="<?php esc_attr_e( 'z. B. Musikanlage für Veranstaltungen', 'vereinsplugin' ); ?>"></label>
		<label><?php esc_html_e( 'Preis ca. (€)', 'vereinsplugin' ); ?>
			<input type="number" name="betrag" min="0" step="0.01"></label>
		<label><?php esc_html_e( 'Priorität', 'vereinsplugin' ); ?>
			<select name="prioritaet"><option value="1"><?php esc_html_e( 'Dringend', 'vereinsplugin' ); ?></option><option value="2" selected><?php esc_html_e( 'Normal', 'vereinsplugin' ); ?></option><option value="3"><?php esc_html_e( 'Irgendwann', 'vereinsplugin' ); ?></option></select></label>
		<label class="pp-span-2"><?php esc_html_e( 'Beschreibung', 'vereinsplugin' ); ?>
			<textarea name="beschreibung" rows="2"></textarea></label>
		<label class="pp-span-2"><?php esc_html_e( 'Warum braucht der Kreis das?', 'vereinsplugin' ); ?>
			<textarea name="begruendung" rows="2"></textarea></label>
		<div class="pp-form-actions"><button type="submit" class="pp-btn pp-btn-primary"><?php esc_html_e( 'Wunsch eintragen', 'vereinsplugin' ); ?></button></div>
	</form>

	<?php
	$ohne = $wpdb->get_results( "SELECT id, titel FROM {$wpdb->prefix}wunschliste WHERE gremium_id IS NULL AND status != 'erfuellt' ORDER BY titel" );
	if ( $ohne ) :
		?>
		<h3><?php esc_html_e( 'Bestehenden Wunsch übernehmen', 'vereinsplugin' ); ?></h3>
		<?php vp_kreis_form( 'vp_kreis_wunsch_zuordnen', 'pp-inline-form' ); ?>
			<input type="hidden" name="gremium_id" value="<?php echo $gid; ?>">
			<select name="wunsch_id" required>
				<option value=""><?php esc_html_e( 'Wunsch ohne Kreis wählen…', 'vereinsplugin' ); ?></option>
				<?php foreach ( $ohne as $o ) : ?>
					<option value="<?php echo (int) $o->id; ?>"><?php echo esc_html( $o->titel ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="pp-btn pp-btn-small"><?php esc_html_e( 'Diesem Kreis zuordnen', 'vereinsplugin' ); ?></button>
		</form>
		<?php
	endif;
}

add_action( 'admin_post_vp_kreis_wunsch_save', 'vp_kreis_handle_wunsch_save' );
function vp_kreis_handle_wunsch_save() {
	vp_kreis_check( 'vp_kreis_wunsch_save' );
	if ( ! current_user_can( 'wl_manage_wishes' ) ) {
		wp_die( esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) );
	}
	global $wpdb;
	$titel = sanitize_text_field( wp_unslash( $_POST['titel'] ?? '' ) );
	if ( '' === $titel ) {
		vp_kreis_fehler( __( 'Der Wunsch braucht einen Titel.', 'vereinsplugin' ) );
	}
	$wpdb->insert( $wpdb->prefix . 'wunschliste', array(
		'titel'        => $titel,
		'beschreibung' => sanitize_textarea_field( wp_unslash( $_POST['beschreibung'] ?? '' ) ),
		'begruendung'  => sanitize_textarea_field( wp_unslash( $_POST['begruendung'] ?? '' ) ),
		'betrag'       => (float) str_replace( ',', '.', (string) ( $_POST['betrag'] ?? 0 ) ),
		'prioritaet'   => max( 1, min( 3, (int) ( $_POST['prioritaet'] ?? 2 ) ) ),
		'status'       => 'offen',
		'gremium_id'   => (int) $_POST['gremium_id'] ?: null,
		'erstellt_von' => get_current_user_id(),
	) );
	vp_kreis_redirect( array( 'pp_saved' => '1' ) );
}

add_action( 'admin_post_vp_kreis_wunsch_status', 'vp_kreis_handle_wunsch_status' );
function vp_kreis_handle_wunsch_status() {
	vp_kreis_check( 'vp_kreis_wunsch_status' );
	if ( ! current_user_can( 'wl_manage_wishes' ) ) {
		wp_die( esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) );
	}
	global $wpdb;
	$status = sanitize_key( $_POST['status'] ?? '' );
	if ( in_array( $status, array( 'offen', 'in_bearbeitung', 'erfuellt' ), true ) ) {
		$wpdb->update( $wpdb->prefix . 'wunschliste', array( 'status' => $status ), array( 'id' => (int) $_POST['wunsch_id'], 'gremium_id' => (int) $_POST['gremium_id'] ) );
	}
	vp_kreis_redirect( array( 'pp_saved' => '1' ) );
}

add_action( 'admin_post_vp_kreis_wunsch_zuordnen', 'vp_kreis_handle_wunsch_zuordnen' );
function vp_kreis_handle_wunsch_zuordnen() {
	vp_kreis_check( 'vp_kreis_wunsch_zuordnen' );
	if ( ! current_user_can( 'wl_manage_wishes' ) ) {
		wp_die( esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) );
	}
	global $wpdb;
	$wpdb->update( $wpdb->prefix . 'wunschliste', array( 'gremium_id' => (int) $_POST['gremium_id'] ), array( 'id' => (int) $_POST['wunsch_id'] ) );
	vp_kreis_redirect( array( 'pp_saved' => '1' ) );
}

/** Wunsch → Budget des Kreises (Kassenrolle bzw. Vorstands-Kassier:in). */
add_action( 'admin_post_vp_kreis_wunsch_budget', 'vp_kreis_handle_wunsch_budget' );
function vp_kreis_handle_wunsch_budget() {
	vp_kreis_check( 'vp_kreis_wunsch_budget' );
	$gid = (int) $_POST['gremium_id'];
	if ( ! vp_kreis_kasse_verfuegbar() || ! vp_kreis_darf_kasse( $gid ) ) {
		wp_die( esc_html__( 'Nur wer die Kasse des Kreises führt, kann Budgets freigeben.', 'vereinsplugin' ) );
	}
	global $wpdb;
	$w = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wunschliste WHERE id = %d AND gremium_id = %d", (int) $_POST['wunsch_id'], $gid ) );
	if ( ! $w ) {
		vp_kreis_fehler( __( 'Wunsch nicht gefunden.', 'vereinsplugin' ) );
	}
	vp_kreis_budget_speichern( array(
		'zweck'                  => sprintf( __( 'Wunsch: %s', 'vereinsplugin' ), $w->titel ),
		'beschreibung'           => $w->beschreibung,
		'betrag'                 => vp_kreis_wunsch_betrag( $w ),
		'jahr'                   => (int) current_time( 'Y' ),
		'gremium_id'             => $gid,
		'verantwortlich_user_id' => get_current_user_id(),
	) );
	$wpdb->update( $wpdb->prefix . 'wunschliste', array( 'status' => 'in_bearbeitung' ), array( 'id' => (int) $w->id ) );
	vp_kreis_redirect( array( 'pp_saved' => '1' ) );
}

/* ---- Kasse ---- */

function vp_kreis_render_kasse( $kreis ) {
	global $wpdb;
	$gid      = (int) $kreis->id;
	$f        = vp_kreis_finanzen( $gid );
	$kassier  = vp_kreis_kassier_ids( $gid );
	$rollen   = vp_kreis_kassen_rollen( $gid );
	$darf     = vp_kreis_darf_kasse( $gid );
	$ids      = array_map( function ( $b ) { return (int) $b->id; }, $f['budgets'] );
	$edit_id  = isset( $_GET['k_budget'] ) ? (int) $_GET['k_budget'] : 0;
	$edit     = null;
	foreach ( $f['budgets'] as $b ) {
		if ( (int) $b->id === $edit_id ) {
			$edit = $b;
		}
	}
	?>
	<h3><?php echo $kassier ? esc_html__( 'Kreiskasse', 'vereinsplugin' ) : esc_html__( 'Finanzen des Kreises', 'vereinsplugin' ); ?></h3>

	<?php if ( $kassier ) : ?>
		<p class="pp-meta"><?php echo esc_html( sprintf( __( 'Geführt von %s. Buchungen landen im Vereinsjournal, zugeordnet zu den Budgets dieses Kreises.', 'vereinsplugin' ), implode( ', ', array_map( 'pp_user_display_name', $kassier ) ) ) ); ?></p>
	<?php elseif ( $rollen ) : ?>
		<div class="pp-hint"><?php echo esc_html( sprintf( __( 'Die Kassenrolle „%s" ist nicht besetzt. Bis dahin laufen die Finanzen über die Kassier:in des Vorstands.', 'vereinsplugin' ), $rollen[0]->bezeichnung ) ); ?></div>
	<?php else : ?>
		<div class="pp-hint"><?php esc_html_e( 'Dieser Kreis hat keine eigene Kasse – Budgets und Auslagen laufen über die Kassier:in des Vorstands. Für eine eigene Kreiskasse unter „Mitglieder & Rollen" eine Rolle anlegen, „führt die Kreiskasse" ankreuzen und besetzen.', 'vereinsplugin' ); ?></div>
	<?php endif; ?>

	<div class="pp-kpis">
		<div class="pp-kpi<?php echo $f['verfuegbar'] < 0 ? ' is-neg' : ''; ?>"><strong><?php echo esc_html( vp_kreis_eur( $f['verfuegbar'] ) ); ?></strong><span><?php esc_html_e( 'verfügbar', 'vereinsplugin' ); ?></span></div>
		<div class="pp-kpi"><strong><?php echo esc_html( vp_kreis_eur( $f['geplant'] ) ); ?></strong><span><?php esc_html_e( 'Budgets geplant', 'vereinsplugin' ); ?></span></div>
		<div class="pp-kpi"><strong><?php echo esc_html( vp_kreis_eur( $f['einnahmen'] ) ); ?></strong><span><?php esc_html_e( 'Einnahmen', 'vereinsplugin' ); ?></span></div>
		<div class="pp-kpi"><strong><?php echo esc_html( vp_kreis_eur( $f['verbraucht'] ) ); ?></strong><span><?php esc_html_e( 'ausgegeben', 'vereinsplugin' ); ?></span></div>
		<div class="pp-kpi"><strong><?php echo esc_html( vp_kreis_eur( $f['offen'] ) ); ?></strong><span><?php echo esc_html( sprintf( __( '%d Auslage(n) offen', 'vereinsplugin' ), $f['offen_n'] ) ); ?></span></div>
	</div>

	<?php
	$auslage_url = vp_kreis_mitgliederbereich_url( array( 'vp_tab' => 'auslage', 'jb_budget' => $ids ? $ids[0] : 0 ) );
	if ( $ids && current_user_can( 'jb_submit_auslagen' ) && $auslage_url && function_exists( 'vp_member_sections' ) ) {
		echo '<p><a class="pp-btn pp-btn-small" href="' . esc_url( $auslage_url ) . '">' . esc_html__( 'Auslage für diesen Kreis einreichen', 'vereinsplugin' ) . '</a></p>';
	}
	?>

	<h4><?php esc_html_e( 'Budgets', 'vereinsplugin' ); ?></h4>
	<table class="pp-table">
		<thead><tr><th><?php esc_html_e( 'Zweck', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Jahr', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Verantwortlich', 'vereinsplugin' ); ?></th><th style="text-align:right"><?php esc_html_e( 'Geplant', 'vereinsplugin' ); ?></th><th style="text-align:right"><?php esc_html_e( 'Verbraucht', 'vereinsplugin' ); ?></th><th style="text-align:right"><?php esc_html_e( 'Rest', 'vereinsplugin' ); ?></th><?php if ( $darf ) : ?><th></th><?php endif; ?></tr></thead>
		<tbody>
		<?php foreach ( $f['budgets'] as $b ) : ?>
			<tr>
				<td><strong><?php echo esc_html( $b->zweck ); ?></strong><?php if ( $b->beschreibung ) : ?><div class="pp-meta"><?php echo esc_html( wp_trim_words( $b->beschreibung, 12 ) ); ?></div><?php endif; ?></td>
				<td><?php echo $b->jahr ? (int) $b->jahr : '–'; ?></td>
				<td><?php echo $b->verantwortlich_user_id ? esc_html( pp_user_display_name( $b->verantwortlich_user_id ) ) : '–'; ?></td>
				<td style="text-align:right"><?php echo esc_html( vp_kreis_eur( $b->betrag ) ); ?></td>
				<td style="text-align:right"><?php echo esc_html( vp_kreis_eur( $b->verbraucht ) ); ?></td>
				<td style="text-align:right;<?php echo (float) $b->rest < 0 ? 'color:#b91c1c;font-weight:600' : ''; ?>"><?php echo esc_html( vp_kreis_eur( $b->rest ) ); ?></td>
				<?php if ( $darf ) : ?><td><a class="pp-meta" href="<?php echo esc_url( vp_kreis_url( $gid, 'kasse', array( 'k_budget' => (int) $b->id ) ) . '#vp-kreis-budget' ); ?>"><?php esc_html_e( 'bearbeiten', 'vereinsplugin' ); ?></a></td><?php endif; ?>
			</tr>
		<?php endforeach; ?>
		<?php if ( ! $f['budgets'] ) : ?><tr><td colspan="7" class="pp-empty"><?php esc_html_e( 'Noch keine Budgets.', 'vereinsplugin' ); ?></td></tr><?php endif; ?>
		</tbody>
	</table>

	<?php if ( $darf ) : ?>
		<details class="pp-werkzeug" id="vp-kreis-budget" <?php echo $edit ? 'open' : ''; ?>>
			<summary class="pp-details-summary"><?php echo $edit ? esc_html__( 'Budget bearbeiten', 'vereinsplugin' ) : esc_html__( '+ Budget planen', 'vereinsplugin' ); ?></summary>
			<?php vp_kreis_form( 'vp_kreis_budget_save', 'pp-form pp-form-grid' ); ?>
				<input type="hidden" name="gremium_id" value="<?php echo $gid; ?>">
				<input type="hidden" name="id" value="<?php echo $edit ? (int) $edit->id : 0; ?>">
				<label class="pp-span-2"><?php esc_html_e( 'Zweck *', 'vereinsplugin' ); ?><input type="text" name="zweck" required value="<?php echo esc_attr( $edit->zweck ?? '' ); ?>"></label>
				<label><?php esc_html_e( 'Betrag (€)', 'vereinsplugin' ); ?><input type="number" step="0.01" min="0" name="betrag" value="<?php echo esc_attr( $edit->betrag ?? '' ); ?>"></label>
				<label><?php esc_html_e( 'Jahr', 'vereinsplugin' ); ?><input type="number" name="jahr" min="2000" max="2100" value="<?php echo esc_attr( $edit->jahr ?? current_time( 'Y' ) ); ?>"></label>
				<label><?php esc_html_e( 'Verantwortlich', 'vereinsplugin' ); ?><select name="verantwortlich_user_id"><?php echo vp_kreis_personen_optionen( $edit->verantwortlich_user_id ?? 0, $gid ); // phpcs:ignore ?></select></label>
				<label><?php esc_html_e( 'Kostenstelle', 'vereinsplugin' ); ?><input type="text" name="kostenstelle" value="<?php echo esc_attr( $edit->kostenstelle ?? '' ); ?>"></label>
				<label class="pp-span-2"><?php esc_html_e( 'Beschreibung', 'vereinsplugin' ); ?><textarea name="beschreibung" rows="2"><?php echo esc_textarea( $edit->beschreibung ?? '' ); ?></textarea></label>
				<div class="pp-form-actions">
					<button type="submit" class="pp-btn pp-btn-primary"><?php esc_html_e( 'Budget speichern', 'vereinsplugin' ); ?></button>
					<?php if ( $edit ) : ?>
						<button type="submit" name="deaktivieren" value="1" class="pp-link-danger" onclick="return confirm('<?php echo esc_js( __( 'Budget deaktivieren? Buchungen bleiben erhalten.', 'vereinsplugin' ) ); ?>')"><?php esc_html_e( 'deaktivieren', 'vereinsplugin' ); ?></button>
					<?php endif; ?>
				</div>
			</form>
		</details>

		<?php if ( $f['budgets'] && function_exists( 'jb_journal_add' ) ) : ?>
			<details class="pp-werkzeug">
				<summary class="pp-details-summary"><?php esc_html_e( '+ Einnahme / Ausgabe buchen', 'vereinsplugin' ); ?></summary>
				<?php vp_kreis_form( 'vp_kreis_buchung', 'pp-form pp-form-grid' ); ?>
					<input type="hidden" name="gremium_id" value="<?php echo $gid; ?>">
					<label><?php esc_html_e( 'Art', 'vereinsplugin' ); ?><select name="typ"><option value="ausgabe"><?php esc_html_e( 'Ausgabe', 'vereinsplugin' ); ?></option><option value="einnahme"><?php esc_html_e( 'Einnahme', 'vereinsplugin' ); ?></option></select></label>
					<label><?php esc_html_e( 'Datum', 'vereinsplugin' ); ?><input type="date" name="datum" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" required></label>
					<label><?php esc_html_e( 'Betrag (€) *', 'vereinsplugin' ); ?><input type="text" inputmode="decimal" name="betrag" required placeholder="0,00"></label>
					<label><?php esc_html_e( 'Budget', 'vereinsplugin' ); ?><select name="budget_id">
						<?php foreach ( $f['budgets'] as $b ) : ?><option value="<?php echo (int) $b->id; ?>"><?php echo esc_html( $b->zweck ); ?></option><?php endforeach; ?>
					</select></label>
					<label><?php esc_html_e( 'Bezahlt über', 'vereinsplugin' ); ?><select name="quelle">
						<?php foreach ( array_keys( function_exists( 'vp_doppik_map' ) ? vp_doppik_map() : array( 'Bar' => 1, 'Bank KSK' => 1 ) ) as $q ) :
							if ( in_array( $q, array( 'Auslage', 'Umbuchung', 'Manuell' ), true ) ) {
								continue;
							} ?>
							<option value="<?php echo esc_attr( $q ); ?>" <?php selected( $q, 'Bar' ); ?>><?php echo esc_html( $q ); ?></option>
						<?php endforeach; ?>
					</select></label>
					<?php if ( function_exists( 'jb_konten_all' ) ) : ?>
						<label><?php esc_html_e( 'SKR-Konto (wofür?)', 'vereinsplugin' ); ?><select name="konto">
							<option value=""><?php esc_html_e( '– Kassier:in des Vorstands ordnet zu –', 'vereinsplugin' ); ?></option>
							<?php foreach ( jb_konten_all() as $k ) :
								if ( 'bestand' === $k->typ ) {
									continue;
								} ?>
								<option value="<?php echo esc_attr( $k->nummer ); ?>"><?php echo esc_html( $k->nummer . ' · ' . $k->bezeichnung ); ?></option>
							<?php endforeach; ?>
						</select></label>
					<?php endif; ?>
					<label><?php esc_html_e( 'Gegenpartei', 'vereinsplugin' ); ?><input type="text" name="gegenpartei" placeholder="<?php esc_attr_e( 'z. B. Getränkehandel Maier', 'vereinsplugin' ); ?>"></label>
					<label class="pp-span-2"><?php esc_html_e( 'Zweck *', 'vereinsplugin' ); ?><input type="text" name="zweck" required></label>
					<div class="pp-form-actions"><button type="submit" class="pp-btn pp-btn-primary"><?php esc_html_e( 'Buchen', 'vereinsplugin' ); ?></button>
						<span class="pp-meta"><?php esc_html_e( 'Beleg bitte trotzdem an die Vereinskasse geben (Belegnummer steht danach in der Liste).', 'vereinsplugin' ); ?></span></div>
				</form>
			</details>
		<?php endif; ?>
	<?php endif; ?>

	<?php
	// Auslagen auf Kreisbudgets.
	$auslagen = array();
	if ( $ids && function_exists( 'jb_table_auslagen' ) ) {
		$in       = implode( ',', $ids );
		$auslagen = $wpdb->get_results( 'SELECT a.*, u.display_name AS user_name FROM ' . jb_table_auslagen() . " a LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id WHERE a.budget_id IN ($in) ORDER BY FIELD(a.status,'ausstehend','genehmigt') DESC, a.eingereicht_am DESC LIMIT 40" );
	}
	$status_label = array( 'ausstehend' => __( 'wartet', 'vereinsplugin' ), 'genehmigt' => __( 'genehmigt', 'vereinsplugin' ), 'abgelehnt' => __( 'abgelehnt', 'vereinsplugin' ), 'ausgezahlt' => __( 'ausgezahlt', 'vereinsplugin' ), 'beleg' => __( 'nur Beleg', 'vereinsplugin' ) );
	?>
	<h4><?php esc_html_e( 'Auslagen & Belege', 'vereinsplugin' ); ?></h4>
	<table class="pp-table">
		<thead><tr><th><?php esc_html_e( 'Datum', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Wer', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Wofür', 'vereinsplugin' ); ?></th><th style="text-align:right"><?php esc_html_e( 'Betrag', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Status', 'vereinsplugin' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $auslagen as $a ) : ?>
			<tr>
				<td><?php echo esc_html( mysql2date( 'd.m.Y', $a->ausgabe_datum ) ); ?></td>
				<td><?php echo esc_html( $a->user_name ); ?></td>
				<td><?php echo esc_html( $a->beschreibung ); ?>
					<?php if ( ! empty( $a->beleg_pfad ) && function_exists( 'jb_nc' ) ) : ?>
						<a class="pp-meta" target="_blank" rel="noopener" href="<?php echo esc_url( jb_nc()->get_download_url( $a->beleg_pfad ) ); ?>"><?php esc_html_e( 'Beleg', 'vereinsplugin' ); ?></a>
					<?php endif; ?></td>
				<td style="text-align:right"><?php echo esc_html( vp_kreis_eur( $a->betrag ) ); ?></td>
				<td>
					<span class="pp-badge pp-status-<?php echo esc_attr( $a->status ); ?>"><?php echo esc_html( $status_label[ $a->status ] ?? $a->status ); ?></span>
					<?php if ( $darf && in_array( $a->status, array( 'ausstehend', 'genehmigt' ), true ) ) : ?>
						<?php vp_kreis_form( 'vp_kreis_auslage', 'pp-inline-form' ); ?>
							<input type="hidden" name="gremium_id" value="<?php echo $gid; ?>">
							<input type="hidden" name="auslage_id" value="<?php echo (int) $a->id; ?>">
							<?php if ( 'ausstehend' === $a->status ) : ?>
								<input type="text" name="notiz" placeholder="<?php esc_attr_e( 'Notiz (optional)', 'vereinsplugin' ); ?>">
								<button type="submit" name="entscheidung" value="approve" class="pp-btn pp-btn-small pp-btn-primary"><?php esc_html_e( 'Genehmigen', 'vereinsplugin' ); ?></button>
								<button type="submit" name="entscheidung" value="reject" class="pp-btn pp-btn-small"><?php esc_html_e( 'Ablehnen', 'vereinsplugin' ); ?></button>
							<?php else : ?>
								<button type="submit" name="entscheidung" value="paid" class="pp-btn pp-btn-small"><?php esc_html_e( 'Ausgezahlt', 'vereinsplugin' ); ?></button>
							<?php endif; ?>
						</form>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		<?php if ( ! $auslagen ) : ?><tr><td colspan="5" class="pp-empty"><?php esc_html_e( 'Keine Auslagen auf Budgets dieses Kreises.', 'vereinsplugin' ); ?></td></tr><?php endif; ?>
		</tbody>
	</table>

	<?php
	$buchungen = array();
	if ( $ids && function_exists( 'jb_table_journal' ) ) {
		$in        = implode( ',', $ids );
		$buchungen = $wpdb->get_results( 'SELECT * FROM ' . jb_table_journal() . " WHERE budget_id IN ($in) ORDER BY buchung_datum DESC, id DESC LIMIT 60" );
	}
	$budget_namen = array();
	foreach ( $f['budgets'] as $b ) {
		$budget_namen[ (int) $b->id ] = $b->zweck;
	}
	?>
	<h4><?php esc_html_e( 'Buchungen', 'vereinsplugin' ); ?></h4>
	<table class="pp-table">
		<thead><tr><th><?php esc_html_e( 'Datum', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Beleg', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Zweck', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Budget', 'vereinsplugin' ); ?></th><th style="text-align:right"><?php esc_html_e( 'Betrag', 'vereinsplugin' ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $buchungen as $r ) : ?>
			<tr>
				<td><?php echo esc_html( mysql2date( 'd.m.Y', $r->buchung_datum ) ); ?></td>
				<td class="pp-meta"><?php echo esc_html( $r->beleg_nr ?? $r->beleg_referenz ); ?></td>
				<td><?php echo esc_html( $r->beschreibung ); ?><?php if ( ! empty( $r->gegenpartei ) ) : ?><div class="pp-meta"><?php echo esc_html( $r->gegenpartei ); ?></div><?php endif; ?></td>
				<td><?php echo esc_html( $budget_namen[ (int) $r->budget_id ] ?? '' ); ?></td>
				<td style="text-align:right;<?php echo (float) $r->betrag < 0 ? 'color:#b91c1c' : 'color:#15803d'; ?>"><?php echo esc_html( vp_kreis_eur( $r->betrag ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		<?php if ( ! $buchungen ) : ?><tr><td colspan="5" class="pp-empty"><?php esc_html_e( 'Noch keine Buchungen direkt auf Kreisbudgets. (Genehmigte Auslagen stehen oben.)', 'vereinsplugin' ); ?></td></tr><?php endif; ?>
		</tbody>
	</table>
	<?php
}

add_action( 'admin_post_vp_kreis_budget_save', 'vp_kreis_handle_budget_save' );
function vp_kreis_handle_budget_save() {
	vp_kreis_check( 'vp_kreis_budget_save' );
	$gid = (int) $_POST['gremium_id'];
	if ( ! vp_kreis_kasse_verfuegbar() || ! vp_kreis_darf_kasse( $gid ) ) {
		wp_die( esc_html__( 'Nur wer die Kasse des Kreises führt, kann Budgets planen.', 'vereinsplugin' ) );
	}
	global $wpdb;
	$id = (int) ( $_POST['id'] ?? 0 );
	if ( $id && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT gremium_id FROM ' . jb_table_budgets() . ' WHERE id = %d', $id ) ) !== $gid ) {
		wp_die( esc_html__( 'Dieses Budget gehört zu einem anderen Kreis.', 'vereinsplugin' ) );
	}
	if ( $id && ! empty( $_POST['deaktivieren'] ) ) {
		$wpdb->update( jb_table_budgets(), array( 'aktiv' => 0 ), array( 'id' => $id ) );
		vp_kreis_redirect( array( 'pp_saved' => '1', 'k_budget' => 0 ) );
	}
	$ok = vp_kreis_budget_speichern( array(
		'id'                     => $id,
		'zweck'                  => wp_unslash( $_POST['zweck'] ?? '' ),
		'beschreibung'           => wp_unslash( $_POST['beschreibung'] ?? '' ),
		'betrag'                 => wp_unslash( $_POST['betrag'] ?? '0' ),
		'jahr'                   => (int) ( $_POST['jahr'] ?? 0 ),
		'verantwortlich_user_id' => (int) ( $_POST['verantwortlich_user_id'] ?? 0 ),
		'kostenstelle'           => wp_unslash( $_POST['kostenstelle'] ?? '' ),
		'gremium_id'             => $gid,
	) );
	if ( ! $ok ) {
		vp_kreis_fehler( __( 'Das Budget braucht einen Zweck.', 'vereinsplugin' ) );
	}
	vp_kreis_redirect( array( 'pp_saved' => '1', 'k_budget' => 0 ) );
}

add_action( 'admin_post_vp_kreis_buchung', 'vp_kreis_handle_buchung' );
function vp_kreis_handle_buchung() {
	vp_kreis_check( 'vp_kreis_buchung' );
	$gid = (int) $_POST['gremium_id'];
	if ( ! vp_kreis_kasse_verfuegbar() || ! vp_kreis_darf_kasse( $gid ) || ! function_exists( 'jb_journal_add' ) ) {
		wp_die( esc_html__( 'Nur wer die Kasse des Kreises führt, kann buchen.', 'vereinsplugin' ) );
	}
	$budget_id = (int) ( $_POST['budget_id'] ?? 0 );
	$budget    = null;
	foreach ( vp_kreis_budgets( $gid ) as $b ) {
		if ( (int) $b->id === $budget_id ) {
			$budget = $b;
		}
	}
	$betrag = abs( (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['betrag'] ?? '0' ) ) ) );
	$zweck  = sanitize_text_field( wp_unslash( $_POST['zweck'] ?? '' ) );
	if ( ! $budget || ! $betrag || '' === $zweck ) {
		vp_kreis_fehler( __( 'Bitte Betrag, Zweck und ein Budget des Kreises angeben.', 'vereinsplugin' ) );
	}
	$quellen = function_exists( 'vp_doppik_map' ) ? array_keys( vp_doppik_map() ) : array( 'Bar', 'Bank KSK' );
	$quelle  = sanitize_text_field( wp_unslash( $_POST['quelle'] ?? 'Bar' ) );
	if ( ! in_array( $quelle, $quellen, true ) ) {
		$quelle = 'Bar';
	}
	$konto = sanitize_text_field( wp_unslash( $_POST['konto'] ?? '' ) );
	$namen = vp_kreis_namen();

	jb_journal_add( array(
		'buchung_datum' => sanitize_text_field( wp_unslash( $_POST['datum'] ?? current_time( 'Y-m-d' ) ) ),
		'betrag'        => ( 'einnahme' === ( $_POST['typ'] ?? '' ) ? 1 : -1 ) * $betrag,
		'kategorie'     => ( $konto && function_exists( 'jb_konto_get' ) ) ? trim( $konto . ' ' . ( jb_konto_get( $konto )->bezeichnung ?? '' ) ) : sprintf( __( 'Kreis %s', 'vereinsplugin' ), $namen[ $gid ] ?? $gid ),
		'beschreibung'  => $zweck,
		'quelle'        => $quelle,
		'konto'         => $konto,
		'sphaere'       => ( $konto && function_exists( 'jb_konto_sphaere' ) ) ? jb_konto_sphaere( $konto ) : '',
		'gegenpartei'   => sanitize_text_field( wp_unslash( $_POST['gegenpartei'] ?? '' ) ),
		'budget_id'     => $budget_id,
	) );
	vp_kreis_redirect( array( 'pp_saved' => '1' ) );
}

add_action( 'admin_post_vp_kreis_auslage', 'vp_kreis_handle_auslage' );
function vp_kreis_handle_auslage() {
	vp_kreis_check( 'vp_kreis_auslage' );
	global $wpdb;
	$gid     = (int) $_POST['gremium_id'];
	$id      = (int) $_POST['auslage_id'];
	$auslage = function_exists( 'jb_get_auslage' ) ? jb_get_auslage( $id ) : null;
	if ( ! $auslage || ! vp_kreis_kasse_verfuegbar() ) {
		vp_kreis_fehler( __( 'Auslage nicht gefunden.', 'vereinsplugin' ) );
	}
	// Das Budget der Auslage muss zu diesem Kreis gehören – sonst könnte eine
	// Kreis-Kassier:in fremde Auslagen genehmigen.
	$budget_gid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT gremium_id FROM ' . jb_table_budgets() . ' WHERE id = %d', (int) $auslage['budget_id'] ) );
	if ( ! $gid || $budget_gid !== $gid || ! vp_kreis_darf_kasse( $gid ) ) {
		wp_die( esc_html__( 'Keine Berechtigung für diese Auslage.', 'vereinsplugin' ) );
	}

	$was   = sanitize_key( $_POST['entscheidung'] ?? '' );
	$notiz = sanitize_textarea_field( wp_unslash( $_POST['notiz'] ?? '' ) );

	if ( in_array( $was, array( 'approve', 'reject' ), true ) && 'ausstehend' === $auslage['status'] ) {
		$ja = 'approve' === $was;
		$wpdb->update( jb_table_auslagen(), array(
			'status'         => $ja ? 'genehmigt' : 'abgelehnt',
			'kassier_id'     => get_current_user_id(),
			'kassier_notiz'  => $notiz,
			'entschieden_am' => current_time( 'mysql' ),
		), array( 'id' => $id ) );
		// Gleicher Ablauf wie jb_approve_auslage(), nur mit Kreis- statt Vorstandsrecht.
		if ( $ja && function_exists( 'jb_auslage_to_journal' ) ) {
			jb_auslage_to_journal( $id );
		}
		if ( function_exists( 'jb_notify_member_decision' ) ) {
			jb_notify_member_decision( $id, $ja );
		}
	} elseif ( 'paid' === $was && 'genehmigt' === $auslage['status'] ) {
		$wpdb->update( jb_table_auslagen(), array( 'status' => 'ausgezahlt', 'ausgezahlt_am' => current_time( 'mysql' ) ), array( 'id' => $id ) );
		if ( function_exists( 'jb_auslage_to_journal' ) ) {
			jb_auslage_to_journal( $id );
		}
	}
	vp_kreis_redirect( array( 'pp_saved' => '1' ) );
}

/* ---- Sitzungen & Aufgaben ---- */

function vp_kreis_render_sitzungen( $kreis ) {
	global $wpdb;
	$gid        = (int) $kreis->id;
	$protokolle = pp_get_protokolle_liste( $gid );
	$aufgaben   = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}pp_aufgaben WHERE verantwortliches_gremium_id = %d AND status = 'offen' ORDER BY faelligkeitsdatum IS NULL, faelligkeitsdatum",
		$gid
	) );
	$termine = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$wpdb->prefix}pp_termine WHERE gremium_id = %d AND datum >= %s ORDER BY datum LIMIT 20",
		$gid,
		current_time( 'mysql' )
	) );
	$themen = pp_get_themen( $gid );
	$heute  = current_time( 'Y-m-d' );
	?>
	<div class="pp-cards">
		<div class="pp-card">
			<h3><?php esc_html_e( 'Sitzungen', 'vereinsplugin' ); ?></h3>
			<?php if ( $protokolle ) : ?>
				<ul class="pp-list">
					<?php foreach ( array_slice( $protokolle, 0, 10 ) as $p ) : ?>
						<li><a href="<?php echo esc_url( pp_front_url( array( 'pp_view' => 'protokoll', 'id' => $p->id ) ) ); ?>"><?php echo esc_html( $p->titel ); ?></a>
							<span class="pp-meta"><?php echo esc_html( ( $p->datum ? mysql2date( 'd.m.Y', $p->datum ) : __( 'Termin offen', 'vereinsplugin' ) ) . ' · ' . ( 'entwurf' === $p->status ? __( 'geplant', 'vereinsplugin' ) : __( 'abgeschlossen', 'vereinsplugin' ) ) ); ?></span></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="pp-empty"><?php esc_html_e( 'Noch keine Sitzungen.', 'vereinsplugin' ); ?></p>
			<?php endif; ?>
			<p><a class="pp-btn pp-btn-small" href="<?php echo esc_url( pp_front_url( array( 'pp_view' => 'protokolle' ) ) ); ?>"><?php esc_html_e( 'Sitzung planen', 'vereinsplugin' ); ?></a></p>
		</div>

		<div class="pp-card">
			<h3><?php esc_html_e( 'Themenspeicher', 'vereinsplugin' ); ?></h3>
			<?php if ( $themen ) : ?>
				<ul class="pp-list">
					<?php foreach ( $themen as $t ) : ?>
						<li><?php echo esc_html( $t->titel ); ?><?php if ( $t->beschreibung ) : ?><span class="pp-meta"><?php echo esc_html( wp_trim_words( $t->beschreibung, 12 ) ); ?></span><?php endif; ?></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="pp-empty"><?php esc_html_e( 'Keine offenen Themen.', 'vereinsplugin' ); ?></p>
			<?php endif; ?>
		</div>
	</div>

	<h3><?php esc_html_e( 'Offene Aufgaben des Kreises', 'vereinsplugin' ); ?></h3>
	<ul class="pp-list pp-aufgabenliste">
		<?php foreach ( $aufgaben as $a ) : ?>
			<li>
				<?php vp_kreis_form( 'pp_front_toggle_aufgabe', 'pp-inline' ); ?>
					<input type="hidden" name="id" value="<?php echo (int) $a->id; ?>">
					<input type="hidden" name="ziel_view" value="kreis">
					<button type="submit" class="pp-checkbox" title="<?php esc_attr_e( 'Als erledigt markieren', 'vereinsplugin' ); ?>">☐</button>
				</form>
				<?php echo esc_html( $a->titel ); ?>
				<span class="pp-meta<?php echo ( $a->faelligkeitsdatum && $a->faelligkeitsdatum < $heute ) ? ' pp-ueberfaellig' : ''; ?>">
					<?php echo esc_html( ( $a->verantwortlich_user_id ? pp_user_display_name( $a->verantwortlich_user_id ) : __( 'noch niemand', 'vereinsplugin' ) ) . ' · ' . ( $a->faelligkeitsdatum ? mysql2date( 'd.m.Y', $a->faelligkeitsdatum ) : __( 'ohne Frist', 'vereinsplugin' ) ) ); ?>
					<?php if ( ! empty( $a->projekt_id ) && function_exists( 'vp_projekt_url' ) ) : ?>
						· <a href="<?php echo esc_url( vp_projekt_url( $a->projekt_id, 'todos' ) ); ?>"><?php esc_html_e( 'Projekt', 'vereinsplugin' ); ?></a>
					<?php endif; ?>
				</span>
			</li>
		<?php endforeach; ?>
		<?php if ( ! $aufgaben ) : ?><li class="pp-empty"><?php esc_html_e( 'Nichts offen.', 'vereinsplugin' ); ?></li><?php endif; ?>
	</ul>
	<?php vp_kreis_form( 'pp_front_quick_aufgabe', 'pp-inline-form' ); ?>
		<input type="hidden" name="ziel_view" value="kreis">
		<input type="hidden" name="gremium_id" value="<?php echo $gid; ?>">
		<input type="text" name="titel" placeholder="<?php esc_attr_e( 'Neue Aufgabe', 'vereinsplugin' ); ?>" required>
		<select name="verantwortlich_user_id"><?php echo vp_kreis_personen_optionen( 0, $gid, __( 'Verantwortlich…', 'vereinsplugin' ) ); // phpcs:ignore ?></select>
		<input type="date" name="faelligkeitsdatum">
		<button type="submit" class="pp-btn pp-btn-small"><?php esc_html_e( 'Anlegen', 'vereinsplugin' ); ?></button>
	</form>

	<h3><?php esc_html_e( 'Termine', 'vereinsplugin' ); ?></h3>
	<table class="pp-table">
		<tbody>
		<?php foreach ( $termine as $t ) : ?>
			<tr><td><?php echo esc_html( mysql2date( 'd.m.Y H:i', $t->datum ) ); ?></td><td><?php echo esc_html( $t->titel ); ?></td><td class="pp-meta"><?php echo esc_html( $t->ort ); ?></td></tr>
		<?php endforeach; ?>
		<?php if ( ! $termine ) : ?><tr><td class="pp-empty"><?php esc_html_e( 'Keine anstehenden Termine.', 'vereinsplugin' ); ?></td></tr><?php endif; ?>
		</tbody>
	</table>
	<?php vp_kreis_form( 'pp_front_quick_termin', 'pp-inline-form' ); ?>
		<input type="hidden" name="ziel_view" value="kreis">
		<input type="hidden" name="gremium_id" value="<?php echo $gid; ?>">
		<input type="text" name="titel" placeholder="<?php esc_attr_e( 'Neuer Termin', 'vereinsplugin' ); ?>" required>
		<input type="datetime-local" name="datum" required>
		<input type="text" name="ort" placeholder="<?php esc_attr_e( 'Ort', 'vereinsplugin' ); ?>">
		<button type="submit" class="pp-btn pp-btn-small"><?php esc_html_e( 'Anlegen', 'vereinsplugin' ); ?></button>
	</form>
	<p class="pp-meta"><?php esc_html_e( 'Aufgaben und Termine erscheinen auch im Kalender-Abo der Mitglieder.', 'vereinsplugin' ); ?></p>
	<?php
}
