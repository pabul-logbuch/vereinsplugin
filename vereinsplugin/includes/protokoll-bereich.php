<?php
/**
 * Kern: ProtokollPro-Ansichten im gemeinsamen Mitgliederbereich.
 *
 * Statt das ganze [protokollpro_mitgliederbereich]-Widget (mit eigener
 * Seitenleiste = „App in der App") einzubetten, rendern wir die einzelnen
 * ProtokollPro-Ansichten direkt als Bereiche der Mitgliederbereichs-Seitenleiste:
 *
 *   Aufgaben                 aufgaben · rollenaufgaben · sets
 *   Sitzungen & Protokolle   sitzungen · protokolle · entscheide · themen (+ protokoll, live)
 *   Kreise & Rollen          kreise · kreis
 *   Projekte & Veranst.      projekte · projekt
 *   Dokumente & Vorlagen     dokumente · dokument · ablaeufe
 *
 * Die frühere Übersicht steckt jetzt in „Start“, Termine und Kalender-Sync im
 * Kern-Kalender (includes/kalender.php).
 *
 * Routing: ?vp_tab=<bereich>&pp_view=<view>[&id=<id>]
 *   - pp_view wird von ProtokollPro selbst ausgewertet (pp_front_current_view())
 *     und bestimmt, welcher Bereich in der Seitenleiste aktiv ist
 *     (vp_pp_tab_fuer_view) – interne Modul-Links brauchen daher keinen vp_tab.
 */

defined( 'ABSPATH' ) || exit;

/** pp_view → Bereich der Seitenleiste. Nicht Aufgeführtes gehört zu „Sitzungen & Protokolle“. */
function vp_pp_view_tabs() {
	return apply_filters( 'vp_pp_view_tabs', array(
		'aufgaben'       => 'aufgaben',
		'rollenaufgaben' => 'aufgaben',
		'sets'           => 'aufgaben',
		'kreise'         => 'kreise',
		'kreis'          => 'kreise',
		'projekte'       => 'projekte',
		'projekt'        => 'projekte',
		'dokumente'      => 'dokumente',
		'dokument'       => 'dokumente',
		'ablaeufe'       => 'dokumente',
		// Aufgelöste Reiter: alte Links landen am neuen Ort.
		'termine'        => 'kalender',
		'kalender'       => 'kalender',
		'dashboard'      => 'start',
	) );
}

function vp_pp_tab_fuer_view( $view ) {
	$map = vp_pp_view_tabs();
	return $map[ $view ] ?? 'protokolle';
}

/** Die Bereiche und ihre Unterpunkte (pp_view => Label). */
function vp_pp_bereiche() {
	return apply_filters( 'vp_pp_bereiche', array(
		'aufgaben'   => array(
			'label'    => __( 'Aufgaben', 'vereinsplugin' ),
			'standard' => 'aufgaben',
			'punkte'   => array(
				'aufgaben'       => __( 'Aufgaben', 'vereinsplugin' ),
				'rollenaufgaben' => __( 'Rollenaufgaben', 'vereinsplugin' ),
				'sets'           => __( 'Aufgaben-Sets', 'vereinsplugin' ),
			),
		),
		'protokolle' => array(
			'label'    => __( 'Sitzungen & Protokolle', 'vereinsplugin' ),
			'standard' => 'sitzungen',
			'punkte'   => array(
				'sitzungen'  => __( 'Geplante Sitzungen', 'vereinsplugin' ),
				'protokolle' => __( 'Protokolle', 'vereinsplugin' ),
				'entscheide' => __( 'Entscheide', 'vereinsplugin' ),
				'themen'     => __( 'Themenspeicher', 'vereinsplugin' ),
			),
		),
		'kreise'     => array(
			'label'    => __( 'Kreise & Rollen', 'vereinsplugin' ),
			'standard' => 'kreise',
			'punkte'   => array(),
		),
		'projekte'   => array(
			'label'    => __( 'Projekte & Veranstaltungen', 'vereinsplugin' ),
			'standard' => 'projekte',
			'punkte'   => array(),
		),
		'dokumente'  => array(
			'label'    => __( 'Dokumente & Vorlagen', 'vereinsplugin' ),
			'standard' => 'dokumente',
			'punkte'   => array(
				'dokumente' => __( 'Dokumente', 'vereinsplugin' ),
				'ablaeufe'  => __( 'Ablauf-Vorlagen', 'vereinsplugin' ),
			),
		),
	) );
}

/** Interne ProtokollPro-Links: pp_view bestimmt den Bereich, vp_tab nicht mitschleppen. */
add_filter( 'pp_front_base_url', function ( $base ) {
	if ( isset( $_GET['vp_tab'] ) || isset( $_GET['pp_view'] ) ) {
		return get_permalink() ?: remove_query_arg( 'vp_tab', $base );
	}
	return $base;
} );

/** Im Mitgliederbereich zeigt „Protokolle“ nur das Archiv (Sitzungen haben einen eigenen Punkt). */
add_filter( 'pp_protokolle_ansicht', function ( $teil ) {
	return ! empty( $GLOBALS['vp_pp_im_bereich'] ) ? 'archiv' : $teil;
} );

/** Rollenaufgaben sind eine Kern-Ansicht, ProtokollPro soll sie durchlassen. */
add_filter( 'pp_front_views', function ( $views ) {
	$views[] = 'rollenaufgaben';
	return $views;
} );
add_action( 'pp_render_view_rollenaufgaben', 'vp_render_rollenaufgaben' );

/** Ist der Protokoll-Bereich gerade aktiv? (auch wenn nur ?pp_view gesetzt ist) */
function vp_protokoll_bereich_aktiv() {
	if ( isset( $_GET['pp_view'] ) ) {
		return 'protokolle' === vp_pp_tab_fuer_view( sanitize_key( wp_unslash( $_GET['pp_view'] ) ) );
	}
	return isset( $_GET['vp_tab'] ) && 'protokolle' === sanitize_key( wp_unslash( $_GET['vp_tab'] ) );
}

/**
 * Rendert eine ProtokollPro-Ansicht im Bereich $bereich. Gehört das aktuelle
 * ?pp_view nicht zu diesem Bereich (z. B. erster Klick in der Seitenleiste),
 * greift die Standard-Ansicht des Bereichs.
 */
function vp_render_pp_bereich( $bereich ) {
	if ( ! function_exists( 'pp_front_current_view' ) ) {
		return '<div class="vp-note vp-note-warn">' . esc_html__( 'Das Protokoll-Modul ist nicht aktiv.', 'vereinsplugin' ) . '</div>';
	}
	if ( ! current_user_can( 'pp_manage' ) && ! current_user_can( 'manage_options' ) ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Keine Berechtigung für diesen Bereich.', 'vereinsplugin' ) . '</div>';
	}
	$def = vp_pp_bereiche()[ $bereich ] ?? null;
	if ( ! $def ) {
		return '';
	}

	$view = isset( $_GET['pp_view'] ) ? pp_front_current_view() : '';
	if ( ! $view || vp_pp_tab_fuer_view( $view ) !== $bereich || 'dashboard' === $view ) {
		$view = $def['standard'];
	}
	// ProtokollPro liest die Ansicht aus $_GET – für den Standardfall setzen.
	$_GET['pp_view']             = $view;
	$GLOBALS['vp_pp_im_bereich'] = true;

	$id   = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
	$perm = get_permalink() ?: '';
	$link = function ( $v, $extra = array() ) use ( $perm, $bereich ) {
		return esc_url( add_query_arg( array_merge( array( 'vp_tab' => $bereich, 'pp_view' => $v ), $extra ), $perm ) );
	};

	// Live-Modus: eigene Ansicht (Sitzungs-Seitenleiste + Protokoll).
	if ( 'live' === $view && function_exists( 'pp_render_live_modus' ) ) {
		ob_start();
		echo '<div class="vp-pp vp-pp-live">';
		pp_render_live_modus(); // gibt Hinweise selbst aus
		echo '</div>';
		return ob_get_clean();
	}

	ob_start();
	echo '<div class="vp-pp">';

	// Unterpunkte als Chips (Sitzungen & Protokolle hat sie in der Seitenleiste).
	if ( count( $def['punkte'] ) > 1 && 'protokolle' !== $bereich ) {
		$aktiv = function_exists( 'pp_front_nav_aktiv' ) ? pp_front_nav_aktiv( $view ) : $view;
		echo '<nav class="vp-pp-subnav">';
		foreach ( $def['punkte'] as $v => $label ) {
			printf( '<a class="vp-pp-chip%s" href="%s">%s</a>', $v === $aktiv ? ' is-active' : '', $link( $v ), esc_html( $label ) );
		}
		echo '</nav>';
	}

	// Kreise: Schnellwechsel zwischen den Kreisen.
	$gremien = ( 'kreise' === $bereich && function_exists( 'pp_get_gremien' ) ) ? (array) pp_get_gremien() : array();
	if ( $gremien ) {
		echo '<nav class="vp-pp-subnav">';
		foreach ( $gremien as $g ) {
			printf(
				'<a class="vp-pp-chip vp-pp-chip-sub%s" href="%s">%s</a>',
				( 'kreis' === $view && (int) $id === (int) $g->id ) ? ' is-active' : '',
				$link( 'kreis', array( 'id' => (int) $g->id ) ),
				esc_html( $g->name )
			);
		}
		echo '</nav>';
	}

	if ( function_exists( 'pp_render_notices' ) ) {
		pp_render_notices();
	}
	pp_render_view_switch( $view );

	echo '</div>';
	return ob_get_clean();
}

function vp_render_protokoll_bereich() {
	return vp_render_pp_bereich( 'protokolle' );
}
function vp_render_pp_aufgaben() {
	return vp_render_pp_bereich( 'aufgaben' );
}
function vp_render_pp_kreise() {
	return vp_render_pp_bereich( 'kreise' );
}
function vp_render_pp_projekte() {
	return vp_render_pp_bereich( 'projekte' );
}
function vp_render_pp_dokumente() {
	return vp_render_pp_bereich( 'dokumente' );
}

/* -------------------------------------------------------------------------
 * Einträge in der Seitenleiste des Mitgliederbereichs
 * ---------------------------------------------------------------------- */

add_filter( 'vp_member_sections', function ( $sections ) {
	if ( ! isset( $sections['protokolle'] ) ) {
		return $sections;
	}
	$sections['protokolle']['children'] = 'vp_protokoll_nav_gruppen';

	$basis = array(
		'group'     => $sections['protokolle']['group'],
		'cap'       => $sections['protokolle']['cap'],
		// Nur zeigen, wenn ProtokollPro aktiv ist.
		'need_sc'   => true,
		'shortcode' => 'protokollpro_mitgliederbereich',
	);
	$vorher = array(
		'aufgaben' => $basis + array(
			'label'  => __( 'Aufgaben', 'vereinsplugin' ),
			'render' => 'vp_render_pp_aufgaben',
			'badge'  => 'vp_pp_aufgaben_badge',
		),
	);
	$nachher = array(
		'kreise'    => $basis + array( 'label' => __( 'Kreise & Rollen', 'vereinsplugin' ), 'render' => 'vp_render_pp_kreise' ),
		'projekte'  => $basis + array( 'label' => __( 'Projekte & Veranstaltungen', 'vereinsplugin' ), 'render' => 'vp_render_pp_projekte' ),
		'dokumente' => $basis + array( 'label' => __( 'Dokumente & Vorlagen', 'vereinsplugin' ), 'render' => 'vp_render_pp_dokumente' ),
	);
	if ( ! has_action( 'pp_render_view_projekte' ) ) {
		unset( $nachher['projekte'] );
	}

	$neu = array();
	foreach ( $sections as $k => $s ) {
		if ( 'protokolle' === $k ) {
			$neu += $vorher;
		}
		$neu[ $k ] = $s;
		if ( 'protokolle' === $k ) {
			$neu += $nachher;
		}
	}
	return $neu;
} );

/** Zahl der eigenen offenen Aufgaben für die Seitenleiste. */
function vp_pp_aufgaben_badge() {
	if ( ! function_exists( 'pp_get_meine_aufgaben' ) ) {
		return '';
	}
	$n = count( (array) pp_get_meine_aufgaben( get_current_user_id() ) );
	return $n ? (string) $n : '';
}

/** Unterpunkte von „Sitzungen & Protokolle“ in der Seitenleiste. */
function vp_protokoll_nav_gruppen( $base_url, $section_active ) {
	$def   = vp_pp_bereiche()['protokolle'];
	$view  = ( $section_active && function_exists( 'pp_front_current_view' ) ) ? pp_front_current_view() : '';
	$aktiv = $view && function_exists( 'pp_front_nav_aktiv' ) ? pp_front_nav_aktiv( $view ) : '';
	if ( $section_active && ( ! $aktiv || ! isset( $def['punkte'][ $aktiv ] ) ) && ! in_array( $view, array( 'protokoll', 'live' ), true ) ) {
		$aktiv = $def['standard'];
	}

	$badges = function_exists( 'pp_front_nav_punkte' ) ? pp_front_nav_punkte() : array();
	$items  = array();
	foreach ( $def['punkte'] as $k => $label ) {
		$items[] = array(
			'label'        => $label,
			// „3 Entwürfe" → „3": in der schmalen Leiste reicht die Zahl.
			'badge'        => isset( $badges[ $k ] ) ? preg_replace( '/\D.*$/u', '', (string) $badges[ $k ][1] ) : '',
			'url'          => add_query_arg( array( 'vp_tab' => 'protokolle', 'pp_view' => $k ), $base_url ),
			'aktiv'        => $k === $aktiv,
			'badge_parent' => false,
		);
	}
	return array( array( 'key' => 'haupt', 'label' => '', 'items' => $items ) );
}

/* -------------------------------------------------------------------------
 * Rollenaufgaben: was jede Rolle regelmäßig bzw. vor Terminen zu tun hat
 * ---------------------------------------------------------------------- */

function vp_render_rollenaufgaben() {
	global $wpdb;
	$rv  = $wpdb->prefix . 'pp_rollenvorlagen';
	$gr  = $wpdb->prefix . 'pp_gremien';
	$ro  = $wpdb->prefix . 'pp_rollen';
	$uid = get_current_user_id();

	$vorlagen = $wpdb->get_results(
		"SELECT v.id, v.bezeichnung, v.gremium_id, g.name AS gremium
		 FROM $rv v JOIN $gr g ON g.id = v.gremium_id
		 WHERE g.aktiv = 1 ORDER BY g.name, v.bezeichnung"
	);
	$heute = current_time( 'Y-m-d' );
	$meine = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT rollenvorlage_id FROM $ro
		 WHERE user_id = %d AND rollenvorlage_id IS NOT NULL
		   AND (amtszeit_start IS NULL OR amtszeit_start <= %s)
		   AND (amtszeit_ende IS NULL OR amtszeit_ende >= %s)",
		$uid, $heute, $heute
	) ) );

	$rhythmus = array( 'taeglich' => 'täglich', 'woechentlich' => 'wöchentlich', 'monatlich' => 'monatlich', 'jaehrlich' => 'jährlich' );

	echo '<div class="pp-page-head"><h2>' . esc_html__( 'Rollenaufgaben', 'vereinsplugin' ) . '</h2></div>';
	echo '<p class="pp-meta">' . esc_html__( 'Was zu jeder Rolle gehört. Regelmäßige Aufgaben landen automatisch bei den aktuellen Amtsinhaber:innen unter „Aufgaben“. Aufgaben „vor Veranstaltungen“ erzeugst du im Kalender unter „Verwalten → Kreis-Termine“ für einen konkreten Termin. Bearbeitet werden die Rollen beim jeweiligen Kreis.', 'vereinsplugin' ) . '</p>';

	if ( ! $vorlagen ) {
		echo '<p class="pp-empty">' . esc_html__( 'Noch keine Rollen angelegt. Rollen und ihre Aufgaben legst du bei einem Kreis unter „Mitglieder & Rollen“ an.', 'vereinsplugin' ) . '</p>';
		return;
	}

	// Eigene Rollen zuerst.
	usort( $vorlagen, function ( $a, $b ) use ( $meine ) {
		return ( in_array( (int) $b->id, $meine, true ) <=> in_array( (int) $a->id, $meine, true ) );
	} );

	$abschnitt = null;
	foreach ( $vorlagen as $v ) {
		$mein = in_array( (int) $v->id, $meine, true );
		$neu  = $mein ? 'mein' : 'alle';
		if ( $neu !== $abschnitt ) {
			echo '<h3>' . esc_html( $mein ? __( 'Meine Rollen', 'vereinsplugin' ) : __( 'Alle Rollen', 'vereinsplugin' ) ) . '</h3>';
			$abschnitt = $neu;
		}
		$aufgaben = function_exists( 'pp_get_aufgaben_fuer_rollenvorlage' ) ? (array) pp_get_aufgaben_fuer_rollenvorlage( $v->id ) : array();
		$inhaber  = function_exists( 'pp_get_aktuelle_besetzungen' ) ? (array) pp_get_aktuelle_besetzungen( $v->id ) : array();
		$namen    = array_map( function ( $b ) { return function_exists( 'pp_user_display_name' ) ? pp_user_display_name( $b->user_id ) : ''; }, $inhaber );
		$kreis    = function_exists( 'vp_kreis_url' ) ? vp_kreis_url( $v->gremium_id, 'struktur' ) : pp_front_url( array( 'pp_view' => 'kreis', 'id' => (int) $v->gremium_id ) );

		echo '<div class="pp-card vp-rollenaufgaben' . ( $mein ? ' is-mein' : '' ) . '">';
		printf(
			'<div class="pp-session-head"><div><strong>%s</strong> <span class="pp-meta">%s · %s</span></div><a class="pp-btn pp-btn-small" href="%s">%s</a></div>',
			esc_html( $v->bezeichnung ),
			esc_html( $v->gremium ),
			esc_html( $namen ? implode( ', ', array_filter( $namen ) ) : __( 'unbesetzt', 'vereinsplugin' ) ),
			esc_url( $kreis ),
			esc_html__( 'Bearbeiten', 'vereinsplugin' )
		);
		if ( $aufgaben ) {
			echo '<ul class="pp-list">';
			foreach ( $aufgaben as $a ) {
				$wann = 'event' === $a->typ
					? sprintf( /* translators: %d = Tage */ __( 'vor Veranstaltungen · %d Tage vorher', 'vereinsplugin' ), (int) $a->vorlauf_tage )
					: sprintf( /* translators: %s = Rhythmus */ __( 'regelmäßig · %s', 'vereinsplugin' ), $rhythmus[ $a->wiederholung ] ?? $a->wiederholung );
				printf(
					'<li%s>%s <span class="pp-meta">%s%s</span>%s</li>',
					empty( $a->aktiv ) ? ' class="pp-meta"' : '',
					esc_html( $a->titel ),
					esc_html( $wann ),
					empty( $a->aktiv ) ? ' · ' . esc_html__( 'pausiert', 'vereinsplugin' ) : '',
					$a->beschreibung ? '<div class="pp-agenda-desc">' . esc_html( $a->beschreibung ) . '</div>' : ''
				);
			}
			echo '</ul>';
		} else {
			echo '<p class="pp-empty">' . esc_html__( 'Keine Aufgaben hinterlegt.', 'vereinsplugin' ) . '</p>';
		}
		echo '</div>';
	}
}

/* -------------------------------------------------------------------------
 * Start: die frühere ProtokollPro-Übersicht
 * ---------------------------------------------------------------------- */

function vp_start_pp_karten() {
	if ( ! function_exists( 'pp_get_meine_aufgaben' ) || ( ! current_user_can( 'pp_manage' ) && ! current_user_can( 'manage_options' ) ) ) {
		return '';
	}
	$uid       = get_current_user_id();
	$aufgaben  = (array) pp_get_meine_aufgaben( $uid );
	$sitzungen = function_exists( 'pp_get_geplante_sitzungen' ) ? (array) pp_get_geplante_sitzungen() : array();
	$kreise    = function_exists( 'pp_get_meine_kreise' ) ? (array) pp_get_meine_kreise( $uid ) : array();
	$perm      = get_permalink() ?: '';
	$url       = function ( $tab, $args = array() ) use ( $perm ) {
		return add_query_arg( array_merge( array( 'vp_tab' => $tab ), $args ), $perm );
	};

	ob_start();
	echo '<div class="pp-cards vp-start-pp">';

	echo '<div class="pp-card"><h3>' . esc_html__( 'Geplante Sitzungen', 'vereinsplugin' ) . '</h3>';
	if ( $sitzungen ) {
		echo '<ul class="pp-list">';
		foreach ( array_slice( $sitzungen, 0, 5 ) as $s ) {
			$tops = function_exists( 'pp_get_tops_fuer_protokoll' ) ? count( (array) pp_get_tops_fuer_protokoll( $s->id ) ) : 0;
			printf(
				'<li><a href="%s">%s</a> <span class="pp-meta">%s · %s · %s</span></li>',
				esc_url( $url( 'protokolle', array( 'pp_view' => 'protokoll', 'id' => (int) $s->id ) ) ),
				esc_html( $s->titel ),
				esc_html( $s->gremium_name ),
				esc_html( $s->datum ? mysql2date( 'd.m.Y', $s->datum ) : __( 'Termin offen', 'vereinsplugin' ) ),
				/* translators: %d = count */
				esc_html( sprintf( _n( '%d TOP', '%d TOPs', $tops, 'vereinsplugin' ), $tops ) )
			);
		}
		echo '</ul>';
	} else {
		echo '<p class="pp-empty">' . esc_html__( 'Keine geplanten Sitzungen.', 'vereinsplugin' ) . '</p>';
	}
	printf( '<p><a class="pp-btn pp-btn-small" href="%s">%s</a></p></div>', esc_url( $url( 'protokolle', array( 'pp_view' => 'sitzungen' ) ) ), esc_html__( 'Alle Sitzungen', 'vereinsplugin' ) );

	echo '<div class="pp-card"><h3>' . esc_html__( 'Meine offenen Aufgaben', 'vereinsplugin' ) . '</h3>';
	if ( $aufgaben ) {
		echo '<ul class="pp-list">';
		foreach ( array_slice( $aufgaben, 0, 6 ) as $a ) {
			printf(
				'<li>%s <span class="pp-meta">%s</span></li>',
				esc_html( $a->titel ),
				esc_html( $a->faelligkeitsdatum ? mysql2date( 'd.m.Y', $a->faelligkeitsdatum ) : __( 'ohne Frist', 'vereinsplugin' ) )
			);
		}
		echo '</ul>';
	} else {
		echo '<p class="pp-empty">' . esc_html__( 'Nichts offen.', 'vereinsplugin' ) . '</p>';
	}
	printf( '<p><a class="pp-btn pp-btn-small" href="%s">%s</a></p></div>', esc_url( $url( 'aufgaben' ) ), esc_html__( 'Zu den Aufgaben', 'vereinsplugin' ) );

	echo '<div class="pp-card"><h3>' . esc_html__( 'Meine Kreise', 'vereinsplugin' ) . '</h3>';
	if ( $kreise ) {
		echo '<ul class="pp-list">';
		foreach ( $kreise as $k ) {
			printf(
				'<li><a href="%s">%s</a> <span class="pp-meta">%s</span></li>',
				esc_url( $url( 'kreise', array( 'pp_view' => 'kreis', 'id' => (int) $k->id ) ) ),
				esc_html( $k->name ),
				esc_html( function_exists( 'pp_gremientyp_label' ) ? pp_gremientyp_label( $k->typ ) : '' )
			);
		}
		echo '</ul>';
	} else {
		echo '<p class="pp-empty">' . esc_html__( 'Du arbeitest noch in keinem Kreis mit.', 'vereinsplugin' ) . '</p>';
		printf( '<p><a class="pp-btn pp-btn-small" href="%s">%s</a></p>', esc_url( $url( 'kreise' ) ), esc_html__( 'Kreise ansehen', 'vereinsplugin' ) );
	}
	echo '</div>';

	// Weitere Karten (z. B. „Meine Projekte“). Deren Links nutzen pp_front_url().
	do_action( 'pp_dashboard_cards' );

	echo '</div>';
	return ob_get_clean();
}
