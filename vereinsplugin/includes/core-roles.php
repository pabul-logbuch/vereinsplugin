<?php
/**
 * Kern: Rollen- und Berechtigungs-Brücke.
 *
 * Die vier Module prüfen jeweils eigene Capabilities:
 *   - Wunschliste:   wl_manage_wishes
 *   - ProtokollPro:  pp_manage
 *   - Buchhaltung:   jb_submit_auslagen / jb_view_auslagen / jb_view_journal / …
 *   - Events:        jbf_edit_events / jbf_send_external / …
 *
 * Alle hängen bereits an der Rolle `wl_mitglied` bzw. an administrator/editor.
 * Dieser Kern stellt nur einheitliche Helfer bereit und stellt sicher, dass
 * eine frisch angelegte `wl_mitglied`-Rolle wirklich alle Modul-Caps bekommt,
 * egal in welcher Reihenfolge die Module geladen/aktiviert wurden.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ist der aktuelle Benutzer ein Vereinsmitglied (oder mehr)?
 */
function vp_is_member() {
	return is_user_logged_in() && (
		current_user_can( 'wl_manage_wishes' )
		|| current_user_can( 'pp_manage' )
		|| current_user_can( 'manage_options' )
		|| in_array( VP_MEMBER_ROLE, (array) wp_get_current_user()->roles, true )
	);
}

/**
 * Darf der Benutzer im Mitgliederbereich verwalten (Vorstand/Mitglied)?
 * Bewusst großzügig – Feinberechtigungen liegen in den Modulen.
 */
function vp_can_manage() {
	return current_user_can( 'wl_manage_wishes' )
		|| current_user_can( 'pp_manage' )
		|| current_user_can( 'manage_options' );
}

/**
 * Nur „Vorstand“ (kann nach außen senden, Einstellungen ändern).
 */
function vp_is_vorstand() {
	return current_user_can( 'manage_options' ) || current_user_can( 'editor' );
}

/**
 * Sammelt alle bekannten Modul-Capabilities an einem Ort – damit der Kern eine
 * neu erzeugte Mitglieds-Rolle vollständig ausstatten kann.
 */
function vp_member_capabilities() {
	return array(
		'read'                => true,
		// Wunschliste.
		'wl_manage_wishes'    => true,
		// ProtokollPro.
		'pp_manage'           => true,
		// Buchhaltung (Mitglied reicht nur ein + sieht Eigenes).
		'jb_submit_auslagen'  => true,
		'jb_view_own_auslagen' => true,
		// Events (verwalten ja, extern senden NICHT).
		'jbf_edit_event'            => true,
		'jbf_read_event'            => true,
		'jbf_delete_event'          => true,
		'jbf_edit_events'           => true,
		'jbf_edit_others_events'    => true,
		'jbf_edit_published_events' => true,
		'jbf_publish_events'        => true,
		'jbf_delete_events'         => true,
		'jbf_delete_others_events'  => true,
		'jbf_delete_published_events' => true,
		'jbf_read_private_events'   => true,
	);
}

/**
 * Idempotent: legt die Mitglieds-Rolle an, falls sie fehlt, und ergänzt fehlende
 * Caps auf Rolle + administrator/editor. Läuft bei Aktivierung und einmal pro
 * Admin-Request (falls ein Modul die Rolle nachträglich verändert hat).
 */
function vp_core_setup_roles() {
	$role = get_role( VP_MEMBER_ROLE );
	if ( ! $role ) {
		add_role( VP_MEMBER_ROLE, __( 'Vereinsmitglied', 'vereinsplugin' ), vp_member_capabilities() );
		$role = get_role( VP_MEMBER_ROLE );
	}
	if ( $role ) {
		foreach ( vp_member_capabilities() as $cap => $grant ) {
			if ( empty( $role->capabilities[ $cap ] ) ) {
				$role->add_cap( $cap, true );
			}
		}
	}

	// „Antrag offen“: rechtelose Warterolle (nur lesen). Wird nur benutzt, wenn
	// in den Einstellungen „Sofort wartender Zugang“ gewählt ist – standardmäßig
	// legt der Vorstand den Zugang erst bei Annahme an.
	if ( ! get_role( 'vp_antrag_offen' ) ) {
		add_role( 'vp_antrag_offen', __( 'Antrag offen', 'vereinsplugin' ), array( 'read' => true ) );
	}

	// administrator + editor: alles inkl. der „Vorstand“-Caps.
	$vorstand_extra = array(
		'jb_view_auslagen', 'jb_approve_auslagen', 'jb_mark_paid',
		'jb_view_journal', 'jb_edit_journal', 'jb_export', 'jb_manage_settings',
		'jbf_send_external',
		'vp_manage_members',
	);
	foreach ( array( 'administrator', 'editor' ) as $role_name ) {
		$r = get_role( $role_name );
		if ( ! $r ) {
			continue;
		}
		foreach ( array_merge( array_keys( vp_member_capabilities() ), $vorstand_extra ) as $cap ) {
			if ( 'read' === $cap ) {
				continue;
			}
			if ( empty( $r->capabilities[ $cap ] ) ) {
				$r->add_cap( $cap, true );
			}
		}
	}
}
add_action( 'admin_init', 'vp_core_setup_roles', 5 );

/**
 * Mitglieder haben keinen Grund, das wp-admin zu sehen. Der komplette
 * Funktionsumfang liegt im Frontend-Mitgliederbereich. Vorstand/Admins
 * behalten das Dashboard.
 *
 * Deaktivierbar über Option `vp_member_backend_access` = '1'.
 */
add_action( 'admin_init', 'vp_block_member_backend' );
function vp_block_member_backend() {
	if ( wp_doing_ajax() ) {
		return;
	}
	if ( get_option( 'vp_member_backend_access' ) === '1' ) {
		return;
	}
	if ( vp_can_manage() && ! vp_only_member() ) {
		return; // Vorstand/Admin.
	}
	if ( vp_only_member() ) {
		$target = get_option( 'vp_member_area_url' );
		wp_safe_redirect( $target ? $target : home_url( '/' ) );
		exit;
	}
}

/**
 * Reines Mitglied (ohne Vorstand-/Admin-Rechte)?
 */
function vp_only_member() {
	$u = wp_get_current_user();
	if ( ! $u || ! $u->exists() ) {
		return false;
	}
	if ( current_user_can( 'manage_options' ) || current_user_can( 'editor' ) ) {
		return false;
	}
	foreach ( array( VP_MEMBER_ROLE, 'pp_mitglied', 'vp_antrag_offen' ) as $r ) {
		if ( in_array( $r, (array) $u->roles, true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Admin-Bar für reine Mitglieder im Frontend ausblenden.
 */
add_action( 'after_setup_theme', function () {
	if ( vp_only_member() && get_option( 'vp_member_backend_access' ) !== '1' ) {
		show_admin_bar( false );
	}
} );
