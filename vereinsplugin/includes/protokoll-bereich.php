<?php
/**
 * Kern: „Sitzungen & Protokolle" im gemeinsamen Mitgliederbereich.
 *
 * Statt das ganze [protokollpro_mitgliederbereich]-Widget (mit eigener
 * Seitenleiste = „App in der App") einzubetten, rendern wir hier nur die
 * jeweilige ProtokollPro-Ansicht und bauen eine flache Unter-Navigation, die
 * sich in die Mitgliederbereichs-Seitenleiste einfügt.
 *
 * Routing: ?vp_tab=protokolle&pp_view=<view>[&id=<id>]
 *   - pp_view wird von ProtokollPro selbst ausgewertet (pp_front_current_view()).
 *   - Der Filter pp_front_base_url sorgt dafür, dass die internen Links des
 *     Moduls den vp_tab behalten.
 */

defined( 'ABSPATH' ) || exit;

/** Interne ProtokollPro-Links behalten den Mitgliederbereichs-Tab. */
add_filter( 'pp_front_base_url', function ( $base ) {
	if ( isset( $_GET['vp_tab'] ) && 'protokolle' === sanitize_key( wp_unslash( $_GET['vp_tab'] ) ) ) {
		return add_query_arg( 'vp_tab', 'protokolle', get_permalink() ?: $base );
	}
	return $base;
} );

/** Ist der Protokoll-Bereich gerade aktiv? (auch wenn nur ?pp_view gesetzt ist) */
function vp_protokoll_bereich_aktiv() {
	return isset( $_GET['pp_view'] ) || ( isset( $_GET['vp_tab'] ) && 'protokolle' === sanitize_key( wp_unslash( $_GET['vp_tab'] ) ) );
}

function vp_render_protokoll_bereich() {
	if ( ! function_exists( 'pp_front_current_view' ) ) {
		return '<div class="vp-note vp-note-warn">' . esc_html__( 'Das Protokoll-Modul ist nicht aktiv.', 'vereinsplugin' ) . '</div>';
	}
	if ( ! current_user_can( 'pp_manage' ) && ! current_user_can( 'manage_options' ) ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Keine Berechtigung für den Protokollbereich.', 'vereinsplugin' ) . '</div>';
	}

	$view = pp_front_current_view();
	$id   = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;
	$perm = get_permalink() ?: '';

	$link = function ( $v, $extra = array() ) use ( $perm ) {
		return esc_url( add_query_arg( array_merge( array( 'vp_tab' => 'protokolle', 'pp_view' => $v ), $extra ), $perm ) );
	};

	// Live-Modus: eigene Ansicht (Sitzungs-Seitenleiste + Protokoll) ohne die
	// Unter-Navigation. Ohne diesen Zweig landet „Live protokollieren" im
	// default-Fall unten — also in der Übersicht.
	if ( 'live' === $view && function_exists( 'pp_render_live_modus' ) ) {
		ob_start();
		echo '<div class="vp-pp vp-pp-live">';
		pp_render_live_modus(); // gibt Hinweise selbst aus
		echo '</div>';
		return ob_get_clean();
	}

	ob_start();
	echo '<div class="vp-pp">';

	// Die Bereichs-Navigation steht in der Seitenleiste des Mitgliederbereichs
	// (vp_protokoll_nav_gruppen). Hier bleibt nur der Schnellwechsel zwischen Kreisen.
	$gremien = ( in_array( $view, array( 'kreise', 'kreis' ), true ) && function_exists( 'pp_get_gremien' ) ) ? (array) pp_get_gremien() : array();
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

	// Hinweise + Ansicht
	if ( function_exists( 'pp_render_notices' ) ) {
		pp_render_notices();
	}
	pp_render_view_switch( $view );

	echo '</div>';
	return ob_get_clean();
}

/* -------------------------------------------------------------------------
 * Unterpunkte in der Seitenleiste des Mitgliederbereichs
 * ---------------------------------------------------------------------- */

add_filter( 'vp_member_sections', function ( $sections ) {
	if ( isset( $sections['protokolle'] ) ) {
		$sections['protokolle']['children'] = 'vp_protokoll_nav_gruppen';
	}
	return $sections;
} );

/**
 * Häufig Genutztes direkt sichtbar, Seltenes in „Vorlagen & Mehr" – damit die
 * Seitenleiste nicht überläuft. Per Filter ergänzte Punkte (Kern/Plugins)
 * ohne feste Einteilung landen in der Hauptgruppe.
 */
function vp_protokoll_nav_gruppen( $base_url, $section_active ) {
	if ( ! function_exists( 'pp_front_nav_punkte' ) ) {
		return array();
	}
	$punkte = pp_front_nav_punkte();
	$aktiv  = $section_active ? pp_front_nav_aktiv( pp_front_current_view() ) : '';

	$einteilung = apply_filters( 'vp_protokoll_nav_einteilung', array(
		'haupt'    => array( '', array( 'dashboard', 'protokolle', 'entscheide', 'kreise', 'projekte', 'aufgaben', 'termine', 'themen' ) ),
		'vorlagen' => array( __( 'Vorlagen & Mehr', 'vereinsplugin' ), array( 'sets', 'ablaeufe', 'dokumente', 'kalender' ) ),
	) );

	$item = function ( $k ) use ( $punkte, $base_url, $aktiv ) {
		return array(
			'label'        => $punkte[ $k ][0],
			// „3 Entwürfe" → „3": in der schmalen Leiste reicht die Zahl.
			'badge'        => preg_replace( '/\D.*$/u', '', (string) $punkte[ $k ][1] ),
			'url'          => add_query_arg( array( 'vp_tab' => 'protokolle', 'pp_view' => $k ), $base_url ),
			'aktiv'        => $k === $aktiv,
			'badge_parent' => 'aufgaben' === $k,
		);
	};

	$gruppen  = array();
	$vergeben = array();
	foreach ( $einteilung as $gk => $def ) {
		$gruppen[ $gk ] = array( 'key' => $gk, 'label' => $def[0], 'items' => array() );
		foreach ( $def[1] as $k ) {
			if ( isset( $punkte[ $k ] ) ) {
				$gruppen[ $gk ]['items'][] = $item( $k );
				$vergeben[]                = $k;
			}
		}
	}
	foreach ( array_keys( $punkte ) as $k ) {
		if ( ! in_array( $k, $vergeben, true ) && isset( $gruppen['haupt'] ) ) {
			$gruppen['haupt']['items'][] = $item( $k );
		}
	}
	return array_values( array_filter( $gruppen, function ( $g ) { return (bool) $g['items']; } ) );
}
