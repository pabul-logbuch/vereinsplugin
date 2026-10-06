<?php
/**
 * Kern: Startseite des Mitgliederbereichs – das Nötigste aus allen Bereichen.
 *
 *  1. „Zu erledigen“: Kacheln mit Zahlen (rot hinterlegt, wenn etwas ansteht),
 *     jede führt direkt in den Bereich.
 *  2. Karten je Bereich: Termine, Schichten, Sitzungen, Aufgaben, Kreise,
 *     Projekte, Wunschliste, Kasse, Chats.
 *
 * Jede Kachel/Karte erscheint nur, wenn die Person den zugehörigen Bereich
 * sieht (vp_visible_sections) und es etwas zu zeigen gibt. Weitere Kacheln
 * und Karten hängen sich über die Filter `vp_start_kacheln` / `vp_start_karten`
 * an.
 */

defined( 'ABSPATH' ) || exit;

function vp_start_url( $tab, $args = array() ) {
	return add_query_arg( array_merge( array( 'vp_tab' => $tab ), $args ), get_permalink() ?: ( get_option( 'vp_member_area_url' ) ?: home_url( '/' ) ) );
}

function vp_start_table_exists( $t ) {
	global $wpdb;
	static $c = array();
	if ( ! isset( $c[ $t ] ) ) {
		$c[ $t ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
	}
	return $c[ $t ];
}

/* =========================================================================
 * Kacheln „Zu erledigen“
 * ====================================================================== */

/** @return array[] ['zahl','text','url','alarm'(bool),'wert'(optional Text statt Zahl)] */
function vp_start_kacheln( array $sec ) {
	global $wpdb;
	$uid = get_current_user_id();
	$k   = array();

	// Meine Aufgaben (überfällige hervorheben).
	if ( isset( $sec['aufgaben'] ) && function_exists( 'pp_get_meine_aufgaben' ) ) {
		$auf    = (array) pp_get_meine_aufgaben( $uid );
		$heute  = current_time( 'Y-m-d' );
		$ueber  = count( array_filter( $auf, function ( $a ) use ( $heute ) { return $a->faelligkeitsdatum && $a->faelligkeitsdatum < $heute; } ) );
		$k[]    = array(
			'zahl'  => count( $auf ),
			'text'  => $ueber ? sprintf( /* translators: %d = count */ _n( 'offene Aufgaben, %d überfällig', 'offene Aufgaben, %d überfällig', $ueber, 'vereinsplugin' ), $ueber ) : __( 'offene Aufgaben', 'vereinsplugin' ),
			'url'   => vp_start_url( 'aufgaben' ),
			'alarm' => $ueber > 0,
		);
	}

	// Meine Auslagen in Bearbeitung.
	if ( function_exists( 'jb_get_auslagen' ) && ( isset( $sec['meine_auslagen'] ) || isset( $sec['auslage'] ) ) ) {
		$mine  = (array) jb_get_auslagen( array( 'user_id' => $uid ) );
		$offen = count( array_filter( $mine, function ( $r ) { return in_array( is_object( $r ) ? $r->status : $r['status'], array( 'ausstehend', 'genehmigt' ), true ); } ) );
		if ( $offen ) {
			$k[] = array( 'zahl' => $offen, 'text' => __( 'meine Auslagen in Bearbeitung', 'vereinsplugin' ), 'url' => vp_start_url( 'meine_auslagen' ), 'alarm' => false );
		}
	}

	// Kasse: Auslagen prüfen.
	if ( isset( $sec['auslagen_pruefen'] ) && function_exists( 'jb_get_auslagen' ) ) {
		$n   = count( (array) jb_get_auslagen( array( 'status' => 'ausstehend' ) ) );
		$k[] = array( 'zahl' => $n, 'text' => __( 'Auslagen zu prüfen', 'vereinsplugin' ), 'url' => vp_start_url( 'auslagen_pruefen' ), 'alarm' => $n > 0 );
	}

	// Vorstand: Mitgliedsanträge.
	if ( isset( $sec['antraege'] ) && function_exists( 'vp_antraege_table' ) ) {
		$n   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . vp_antraege_table() . " WHERE status = 'neu'" );
		$k[] = array( 'zahl' => $n, 'text' => __( 'offene Mitgliedsanträge', 'vereinsplugin' ), 'url' => vp_start_url( 'antraege' ), 'alarm' => $n > 0 );
	}

	// Themenspeicher: neue Vorschläge.
	if ( isset( $sec['protokolle'] ) && vp_start_table_exists( $wpdb->prefix . 'pp_themen' ) ) {
		$n = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pp_themen WHERE status = 'vorbereitet'" );
		if ( $n ) {
			$k[] = array( 'zahl' => $n, 'text' => __( 'Themen im Themenspeicher', 'vereinsplugin' ), 'url' => vp_start_url( 'protokolle', array( 'pp_view' => 'themen' ) ), 'alarm' => false );
		}
	}

	// Schichtpläne: freie Plätze in den nächsten 30 Tagen.
	if ( isset( $sec['schichtplaene'] ) && function_exists( 'vp_kal_quelle_schichten' ) ) {
		$frei = 0;
		foreach ( vp_start_schichten( 30 ) as $t ) {
			if ( ! $t['mein'] && preg_match( '/(\d+)/', $t['info'], $m ) && false === strpos( $t['info'], 'besetzt' ) ) {
				$frei += (int) $m[1];
			}
		}
		if ( $frei ) {
			$k[] = array( 'zahl' => $frei, 'text' => __( 'freie Schichtplätze (30 Tage)', 'vereinsplugin' ), 'url' => vp_start_url( 'schichtplaene' ), 'alarm' => false );
		}
	}

	// Kasse: Gesamtstand der Geldkonten.
	if ( ( isset( $sec['kassenbericht'] ) || isset( $sec['buchhaltung'] ) ) && current_user_can( 'jb_view_journal' ) && function_exists( 'vp_bh_geldkonten_stand' ) ) {
		$summe = 0.0;
		foreach ( (array) vp_bh_geldkonten_stand() as $konto ) {
			$summe += (float) $konto['ende'];
		}
		$k[] = array( 'wert' => number_format_i18n( $summe, 2 ) . ' €', 'text' => __( 'Kassenstand gesamt', 'vereinsplugin' ), 'url' => vp_start_url( isset( $sec['kassenbericht'] ) ? 'kassenbericht' : 'buchhaltung' ), 'alarm' => $summe < 0 );
	}

	return (array) apply_filters( 'vp_start_kacheln', $k, $sec );
}

/** Schichten (Kalender-Quelle) der nächsten $tage Tage. */
function vp_start_schichten( $tage ) {
	static $cache = array();
	if ( ! isset( $cache[ $tage ] ) ) {
		$von             = current_time( 'Y-m-d' );
		$bis             = gmdate( 'Y-m-d', strtotime( $von . ' +' . (int) $tage . ' days' ) );
		$cache[ $tage ] = function_exists( 'vp_kal_quelle_schichten' ) ? (array) vp_kal_quelle_schichten( $von, $bis, get_current_user_id() ) : array();
	}
	return $cache[ $tage ];
}

/* =========================================================================
 * Karten
 * ====================================================================== */

function vp_start_karte( $titel, $inhalt, $url = '', $link = '' ) {
	return '<div class="vp-card vp-start-karte"><h3>' . esc_html( $titel ) . '</h3>' . $inhalt
		. ( $url ? '<p class="vp-start-mehr"><a href="' . esc_url( $url ) . '">' . esc_html( $link ?: __( 'Alle anzeigen', 'vereinsplugin' ) ) . ' →</a></p>' : '' )
		. '</div>';
}

/** @return string[] HTML je Karte */
function vp_start_karten( array $sec ) {
	$k = array();

	// Meine nächsten Schichten.
	if ( isset( $sec['schichtplaene'] ) ) {
		$mine = array_filter( vp_start_schichten( 60 ), function ( $t ) { return $t['mein']; } );
		if ( $mine ) {
			$h = '<ul class="vp-start-liste">';
			foreach ( array_slice( array_values( $mine ), 0, 5 ) as $t ) {
				$h .= '<li><span class="vp-start-datum">' . esc_html( wp_date( 'D d.m. H:i', strtotime( $t['start'] ) ) ) . '</span> ' . esc_html( preg_replace( '/^[^:]+:\s*/', '', $t['titel'] ) ) . '<br><small class="vp-muted">' . esc_html( trim( $t['info'] . ( $t['ort'] ? ' · ' . $t['ort'] : '' ), ' ·' ) ) . '</small></li>';
			}
			$k[] = vp_start_karte( __( 'Meine Schichten', 'vereinsplugin' ), $h . '</ul>', vp_start_url( 'schichtplaene' ) );
		}
	}

	// Wunschliste: was gerade vorne liegt.
	if ( ( isset( $sec['abstimmung'] ) || isset( $sec['wuensche'] ) ) && function_exists( 'wl_get_wuensche_mit_score' ) ) {
		$w = array_slice( (array) wl_get_wuensche_mit_score( true ), 0, 5 );
		if ( $w ) {
			$h = '<ol class="vp-start-liste">';
			foreach ( $w as $x ) {
				$betrag = function_exists( 'vp_kreis_wunsch_betrag' ) ? vp_kreis_wunsch_betrag( $x ) : (float) $x->betrag;
				$h     .= '<li>' . esc_html( $x->titel ) . ( $betrag ? ' <small class="vp-muted">· ' . esc_html( number_format_i18n( $betrag, 0 ) ) . ' €</small>' : '' ) . ' <small class="vp-muted">· ' . esc_html( sprintf( /* translators: %d = score */ __( '%d Punkte', 'vereinsplugin' ), (int) $x->vote_score ) ) . '</small></li>';
			}
			$k[] = vp_start_karte( __( 'Wunschliste – vorne', 'vereinsplugin' ), $h . '</ol>', vp_start_url( isset( $sec['abstimmung'] ) ? 'abstimmung' : 'wuensche' ), __( 'Abstimmen', 'vereinsplugin' ) );
		}
	}

	// Kasse: Konten + Projekte über Plan.
	if ( ( isset( $sec['kassenbericht'] ) || isset( $sec['budgets'] ) ) && current_user_can( 'jb_view_journal' ) ) {
		$h = '';
		if ( function_exists( 'vp_bh_geldkonten_stand' ) ) {
			$h .= '<table class="vp-start-tab">';
			foreach ( (array) vp_bh_geldkonten_stand() as $konto ) {
				$h .= '<tr><td>' . esc_html( $konto['name'] ) . '</td><td>' . esc_html( number_format_i18n( (float) $konto['ende'], 2 ) ) . ' €</td></tr>';
			}
			$h .= '</table>';
		}
		if ( function_exists( 'vp_projekte_liste' ) && function_exists( 'vp_projekt_soll_ist' ) ) {
			$ueber = array();
			foreach ( (array) vp_projekte_liste( array( 'laufend' => true ) ) as $p ) {
				$s = vp_projekt_soll_ist( $p );
				if ( $s['plan_aus'] > 0 && $s['ist_aus'] > $s['plan_aus'] ) {
					$ueber[] = esc_html( $p->titel ) . ' <small class="vp-muted">(' . esc_html( sprintf( /* translators: 1: Ist, 2: Plan */ __( '%1$s statt %2$s', 'vereinsplugin' ), number_format_i18n( $s['ist_aus'], 0 ) . ' €', number_format_i18n( $s['plan_aus'], 0 ) . ' €' ) ) . ')</small>';
				}
			}
			if ( $ueber ) {
				$h .= '<p class="vp-start-warn">' . esc_html__( 'Über der Kalkulation:', 'vereinsplugin' ) . '</p><ul class="vp-start-liste"><li>' . implode( '</li><li>', $ueber ) . '</li></ul>';
			}
		}
		if ( $h ) {
			$k[] = vp_start_karte( __( 'Kasse', 'vereinsplugin' ), $h, vp_start_url( isset( $sec['budgets'] ) ? 'budgets' : 'kassenbericht' ), __( 'Budgets & Kostenstellen', 'vereinsplugin' ) );
		}
	}

	// Chats (Nextcloud Talk).
	if ( isset( $sec['chats'] ) && function_exists( 'vp_talk_kreis_raeume' ) && function_exists( 'pp_get_gremien' ) ) {
		$raeume = vp_talk_kreis_raeume();
		$uid    = (string) get_current_user_id();
		$links  = array();
		foreach ( (array) pp_get_gremien() as $g ) {
			if ( ! empty( $raeume[ (int) $g->id ] ) && in_array( $uid, array_map( 'strval', (array) vp_talk_kreis_mitglieder( $g ) ), true ) ) {
				$links[] = '<a class="vp-btn" href="' . esc_url( vp_talk_url( $raeume[ (int) $g->id ] ) ) . '" target="_blank" rel="noopener">💬 ' . esc_html( $g->name ) . '</a>';
			}
		}
		if ( $links ) {
			$k[] = vp_start_karte( __( 'Meine Kreis-Chats', 'vereinsplugin' ), '<div class="vp-start-chips">' . implode( '', $links ) . '</div>', vp_start_url( 'chats' ) );
		}
	}

	return (array) apply_filters( 'vp_start_karten', $k, $sec );
}

/* =========================================================================
 * Startseite
 * ====================================================================== */

function vp_start_render() {
	$u   = wp_get_current_user();
	$sec = function_exists( 'vp_visible_sections' ) ? vp_visible_sections() : array();

	ob_start();
	echo '<div class="vp-start">';
	echo '<h2>' . esc_html( sprintf( /* translators: %s = Vorname */ __( 'Hallo %s', 'vereinsplugin' ), $u->first_name ?: $u->display_name ) ) . '</h2>';
	echo '<p class="vp-muted vp-start-datum-heute">' . esc_html( wp_date( 'l, j. F Y' ) ) . '</p>';

	// Läuft gerade eine Live-Sitzung? (Leiste steht ohnehin oben – hier nichts doppelt.)

	$kacheln = vp_start_kacheln( $sec );
	if ( $kacheln ) {
		echo '<h3 class="vp-start-abschnitt">' . esc_html__( 'Auf einen Blick', 'vereinsplugin' ) . '</h3><div class="vp-tiles vp-start-kacheln">';
		foreach ( $kacheln as $t ) {
			printf(
				'<a class="vp-card vp-tile%s" href="%s"><div class="vp-tile-num">%s</div><div>%s</div></a>',
				! empty( $t['alarm'] ) ? ' vp-tile-alert' : '',
				esc_url( $t['url'] ),
				esc_html( isset( $t['wert'] ) ? $t['wert'] : (string) (int) $t['zahl'] ),
				esc_html( $t['text'] )
			);
		}
		echo '</div>';
	}

	// Karten-Raster: Termine zuerst, dann Sitzungen/Aufgaben/Kreise/Projekte, dann der Rest.
	$html = '';
	if ( isset( $sec['kalender'] ) && function_exists( 'vp_kal_naechste_termine_html' ) ) {
		$html .= vp_kal_naechste_termine_html( 6 );
	}
	if ( function_exists( 'vp_start_pp_karten' ) ) {
		$html .= vp_start_pp_karten( false );
	}
	$html .= implode( '', vp_start_karten( $sec ) );

	if ( $html ) {
		echo '<div class="vp-start-raster">' . $html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	} else {
		echo '<p class="vp-muted">' . esc_html__( 'Gerade steht nichts an. Wähle links einen Bereich.', 'vereinsplugin' ) . '</p>';
	}
	echo '</div>';
	return ob_get_clean();
}
