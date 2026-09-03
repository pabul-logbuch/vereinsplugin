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

	global $wpdb;
	$entwuerfe = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}pp_protokolle WHERE status='entwurf'" );
	$offene    = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}pp_aufgaben WHERE status='offen' AND verantwortlich_user_id = %d",
		get_current_user_id()
	) );

	$punkte = array(
		'dashboard'  => array( __( 'Übersicht', 'vereinsplugin' ), '' ),
		'protokolle' => array( __( 'Protokolle', 'vereinsplugin' ), $entwuerfe ? $entwuerfe . ' Entwürfe' : '' ),
		'kreise'     => array( __( 'Kreise & Rollen', 'vereinsplugin' ), '' ),
		'sets'       => array( __( 'Aufgaben-Sets', 'vereinsplugin' ), '' ),
		'themen'     => array( __( 'Themenspeicher', 'vereinsplugin' ), '' ),
		'aufgaben'   => array( __( 'Aufgaben', 'vereinsplugin' ), $offene ? (string) $offene : '' ),
		'termine'    => array( __( 'Termine', 'vereinsplugin' ), '' ),
		'kalender'   => array( __( 'Kalender-Sync', 'vereinsplugin' ), '' ),
	);

	$active_key = in_array( $view, array( 'protokoll' ), true ) ? 'protokolle'
		: ( in_array( $view, array( 'kreis' ), true ) ? 'kreise' : $view );

	ob_start();
	echo '<div class="vp-pp">';

	// Unter-Navigation (Chips)
	echo '<nav class="vp-pp-subnav">';
	foreach ( $punkte as $k => $info ) {
		printf(
			'<a class="vp-pp-chip%s" href="%s">%s%s</a>',
			$k === $active_key ? ' is-active' : '',
			$link( $k ),
			esc_html( $info[0] ),
			$info[1] ? ' <span class="vp-pp-badge">' . esc_html( $info[1] ) . '</span>' : ''
		);
	}
	if ( function_exists( 'pp_get_gremien' ) ) {
		foreach ( (array) pp_get_gremien() as $g ) {
			printf(
				'<a class="vp-pp-chip vp-pp-chip-sub%s" href="%s">%s</a>',
				( 'kreis' === $view && (int) $id === (int) $g->id ) ? ' is-active' : '',
				$link( 'kreis', array( 'id' => (int) $g->id ) ),
				esc_html( $g->name )
			);
		}
	}
	echo '</nav>';

	// Hinweise + Ansicht
	if ( function_exists( 'pp_render_notices' ) ) {
		pp_render_notices();
	}
	switch ( $view ) {
		case 'protokolle': pp_render_view_protokolle(); break;
		case 'protokoll':  pp_render_view_protokoll_detail(); break;
		case 'kreise':     pp_render_view_kreise(); break;
		case 'kreis':      pp_render_view_kreis_detail(); break;
		case 'sets':       pp_render_view_sets(); break;
		case 'themen':     pp_render_view_themen(); break;
		case 'aufgaben':   pp_render_view_aufgaben(); break;
		case 'termine':    pp_render_view_termine(); break;
		case 'kalender':   pp_render_view_kalender(); break;
		default:           pp_render_view_dashboard(); break;
	}

	echo '</div>';
	return ob_get_clean();
}
