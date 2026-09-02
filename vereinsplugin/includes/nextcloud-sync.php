<?php
/**
 * Kern: Nextcloud ⇄ WordPress Benutzer-/Gruppen-Sync.
 *
 *  - Bidirektional, abgeglichen über die E-Mail-Adresse.
 *  - Anlegen auf beiden Seiten; NIE automatisch löschen/deaktivieren
 *    (Abweichungen werden im Bericht gezeigt, Handeln bleibt manuell).
 *  - NC-Gruppe → WP-Rolle über eine frei konfigurierbare Mapping-Tabelle.
 *  - Auslösung: Button im Vorstandsbereich + stündlicher WP-Cron.
 *  - Optionaler „Nextcloud-Login“ (Passwort-Prüfung gegen die OCS-API,
 *    kein Server-/Admin-Zugriff nötig).
 *
 * Zugang: nutzt die Nextcloud-Basis-URL aus dem Buchhaltungs-Modul
 * (jb_nc_url) plus einen Admin-/Gruppenadmin-Zugang (vp_nc_admin_*).
 */

defined( 'ABSPATH' ) || exit;

/* =========================================================================
 * Konfiguration / OCS-Client
 * ====================================================================== */

function vp_nc_cfg() {
	$base = rtrim( get_option( 'vp_nc_url', get_option( 'jb_nc_url', '' ) ), '/' );
	$user = trim( get_option( 'vp_nc_admin_user', '' ) ) ?: trim( get_option( 'jb_nc_user', '' ) );
	$pass = get_option( 'vp_nc_admin_pass', '' ) ?: get_option( 'jb_nc_password', '' );
	return array( 'base' => $base, 'user' => $user, 'pass' => $pass );
}

function vp_nc_ready() {
	$c = vp_nc_cfg();
	return $c['base'] && $c['user'] && $c['pass'];
}

/**
 * OCS-Request. Gibt bei Erfolg das dekodierte `ocs`-Objekt zurück, sonst WP_Error.
 */
function vp_nc_ocs( $method, $path, $body = array(), $auth = null ) {
	$c    = $auth ?: vp_nc_cfg();
	$url  = $c['base'] . '/ocs/v2.php' . $path;
	$url  = add_query_arg( 'format', 'json', $url );
	$args = array(
		'method'  => $method,
		'timeout' => 25,
		'headers' => array(
			'OCS-APIRequest' => 'true',
			'Accept'         => 'application/json',
			'Authorization'  => 'Basic ' . base64_encode( $c['user'] . ':' . $c['pass'] ),
		),
	);
	if ( ! empty( $body ) ) {
		$args['body'] = $body; // application/x-www-form-urlencoded
	}
	$res = wp_remote_request( $url, $args );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$json = json_decode( wp_remote_retrieve_body( $res ), true );
	$status = $json['ocs']['meta']['statuscode'] ?? $code;
	if ( $code >= 400 || ( $status && $status >= 300 && 100 !== $status ) ) {
		$msg = $json['ocs']['meta']['message'] ?? ( 'HTTP ' . $code );
		return new WP_Error( 'nc_ocs', 'Nextcloud: ' . $msg . ' (' . $path . ')' );
	}
	return $json['ocs'] ?? array();
}

function vp_nc_get_groups() {
	$ocs = vp_nc_ocs( 'GET', '/cloud/groups' );
	if ( is_wp_error( $ocs ) ) {
		return $ocs;
	}
	return array_values( (array) ( $ocs['data']['groups'] ?? array() ) );
}

/**
 * Alle NC-Benutzer mit Details. @return array<int,array{id,email,displayname,groups,enabled}>|WP_Error
 */
function vp_nc_get_users() {
	// Erst der Sammel-Endpunkt (ein Request); Fallback: einzeln.
	$details = vp_nc_ocs( 'GET', '/cloud/users/details' );
	$out = array();
	if ( ! is_wp_error( $details ) && ! empty( $details['data']['users'] ) && is_array( $details['data']['users'] ) ) {
		foreach ( $details['data']['users'] as $id => $dd ) {
			$out[] = vp_nc_user_row( is_string( $id ) ? $id : ( $dd['id'] ?? '' ), $dd );
		}
		return $out;
	}

	$list = vp_nc_ocs( 'GET', '/cloud/users' );
	if ( is_wp_error( $list ) ) {
		return $list;
	}
	foreach ( (array) ( $list['data']['users'] ?? array() ) as $id ) {
		$d = vp_nc_ocs( 'GET', '/cloud/users/' . rawurlencode( $id ) );
		if ( ! is_wp_error( $d ) ) {
			$out[] = vp_nc_user_row( $id, $d['data'] ?? array() );
		}
	}
	return $out;
}

function vp_nc_user_row( $id, $dd ) {
	return array(
		'id'          => (string) $id,
		'email'       => strtolower( trim( (string) ( $dd['email'] ?? '' ) ) ),
		'displayname' => (string) ( $dd['displayname'] ?? ( $dd['display-name'] ?? $id ) ),
		'groups'      => array_values( (array) ( $dd['groups'] ?? array() ) ),
		'enabled'     => ! isset( $dd['enabled'] ) || $dd['enabled'],
	);
}

function vp_nc_create_user( $id, $email, $displayname, $groups = array() ) {
	$r = vp_nc_ocs( 'POST', '/cloud/users', array(
		'userid'      => $id,
		'email'       => $email,      // NC schickt die Einladungsmail zum Passwort-Setzen
		'displayName' => $displayname,
	) );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	foreach ( (array) $groups as $g ) {
		if ( $g ) {
			vp_nc_add_to_group( $id, $g ); // Fehler hier sind nicht fatal
		}
	}
	return $r;
}

function vp_nc_add_to_group( $id, $group ) {
	return vp_nc_ocs( 'POST', '/cloud/users/' . rawurlencode( $id ) . '/groups', array( 'groupid' => $group ) );
}

function vp_nc_set_field( $id, $key, $value ) {
	return vp_nc_ocs( 'PUT', '/cloud/users/' . rawurlencode( $id ), array( 'key' => $key, 'value' => $value ) );
}

/* =========================================================================
 * Mapping NC-Gruppe ⇄ WP-Rolle
 * ====================================================================== */

/**
 * @return array<int,array{group:string,role:string}> in Prioritätsreihenfolge.
 */
function vp_nc_group_map() {
	$raw = get_option( 'vp_nc_group_map', '' );
	$map = array();
	foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || 0 === strpos( $line, '#' ) ) {
			continue;
		}
		$parts = array_map( 'trim', explode( '=', $line, 2 ) );
		if ( count( $parts ) === 2 && '' !== $parts[0] && '' !== $parts[1] ) {
			$map[] = array( 'group' => $parts[0], 'role' => sanitize_key( $parts[1] ) );
		}
	}
	return $map;
}

/** Höchstpriorisierte gemappte Rolle für eine Menge NC-Gruppen. */
function vp_nc_role_for_groups( array $nc_groups ) {
	$ng = array_map( 'strval', $nc_groups );
	foreach ( vp_nc_group_map() as $m ) {
		if ( in_array( $m['group'], $ng, true ) ) {
			return $m['role'];
		}
	}
	return '';
}

/** Erste NC-Gruppe, die auf diese WP-Rolle mappt (für WP→NC). */
function vp_nc_group_for_role( $role ) {
	foreach ( vp_nc_group_map() as $m ) {
		if ( $m['role'] === $role ) {
			return $m['group'];
		}
	}
	return '';
}

/** Alle im Mapping vorkommenden WP-Rollen. */
function vp_nc_mapped_roles() {
	return array_values( array_unique( wp_list_pluck( vp_nc_group_map(), 'role' ) ) );
}

/* =========================================================================
 * Sync-Engine
 * ====================================================================== */

function vp_nc_sync( $dry_run = false ) {
	$report = array(
		'time'       => current_time( 'mysql' ),
		'dry_run'    => $dry_run,
		'created_wp' => array(),
		'created_nc' => array(),
		'role_wp'    => array(),
		'group_nc'   => array(),
		'only_nc'    => array(),
		'only_wp'    => array(),
		'errors'     => array(),
	);

	if ( ! vp_nc_ready() ) {
		$report['errors'][] = 'Nextcloud-Zugang unvollständig (Basis-URL / Admin-Benutzer / Passwort).';
		update_option( 'vp_nc_sync_last', $report );
		return $report;
	}
	if ( ! vp_nc_group_map() ) {
		$report['errors'][] = 'Keine Gruppen→Rollen-Zuordnung konfiguriert.';
		update_option( 'vp_nc_sync_last', $report );
		return $report;
	}

	$nc_users = vp_nc_get_users();
	if ( is_wp_error( $nc_users ) ) {
		$report['errors'][] = $nc_users->get_error_message();
		update_option( 'vp_nc_sync_last', $report );
		return $report;
	}

	$nc_by_email = array();
	foreach ( $nc_users as $u ) {
		if ( $u['email'] ) {
			$nc_by_email[ $u['email'] ] = $u;
		}
	}

	$wp_users = get_users( array( 'fields' => array( 'ID', 'user_email', 'user_login', 'display_name' ) ) );
	$wp_by_email = array();
	foreach ( $wp_users as $u ) {
		if ( $u->user_email ) {
			$wp_by_email[ strtolower( $u->user_email ) ] = $u;
		}
	}

	/* --- NC → WP --- */
	foreach ( $nc_users as $nc ) {
		if ( ! $nc['email'] ) {
			continue;
		}
		$role = vp_nc_role_for_groups( $nc['groups'] );
		if ( ! $role ) {
			continue; // NC-User in keiner gemappten Gruppe → ignorieren
		}
		if ( isset( $wp_by_email[ $nc['email'] ] ) ) {
			$wpu   = $wp_by_email[ $nc['email'] ];
			$wpobj = get_userdata( $wpu->ID );
			if ( $wpobj && ! in_array( $role, (array) $wpobj->roles, true ) ) {
				if ( ! $dry_run ) {
					$wpobj->set_role( $role );
				}
				$report['role_wp'][] = $wpu->user_email . ' → ' . $role;
			}
			continue;
		}
		// Neuer WP-User.
		$report['created_wp'][] = $nc['email'] . ' (' . $role . ')';
		if ( ! $dry_run ) {
			$login = sanitize_user( $nc['id'], true ) ?: sanitize_user( current( explode( '@', $nc['email'] ) ), true );
			$base = $login;
			$i = 1;
			while ( username_exists( $login ) ) {
				$login = $base . ++$i;
			}
			$uid = wp_insert_user( array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 20 ),
				'user_email'   => $nc['email'],
				'display_name' => $nc['displayname'],
				'role'         => $role,
			) );
			if ( is_wp_error( $uid ) ) {
				$report['errors'][] = 'WP anlegen (' . $nc['email'] . '): ' . $uid->get_error_message();
			} else {
				update_user_meta( $uid, 'vp_nc_id', $nc['id'] );
			}
		}
	}

	/* --- WP → NC --- */
	$mapped_roles = vp_nc_mapped_roles();
	foreach ( $wp_users as $wpu ) {
		if ( ! $wpu->user_email ) {
			continue;
		}
		$email  = strtolower( $wpu->user_email );
		$wpobj  = get_userdata( $wpu->ID );
		$roles  = $wpobj ? (array) $wpobj->roles : array();
		$role   = '';
		foreach ( vp_nc_group_map() as $m ) { // Priorität wie im Mapping
			if ( in_array( $m['role'], $roles, true ) ) {
				$role = $m['role'];
				break;
			}
		}
		if ( ! $role && array_intersect( $roles, $mapped_roles ) ) {
			$role = (string) current( array_intersect( $roles, $mapped_roles ) );
		}
		if ( ! $role ) {
			continue; // WP-User ohne gemappte Rolle
		}
		$group = vp_nc_group_for_role( $role );

		if ( isset( $nc_by_email[ $email ] ) ) {
			$nc = $nc_by_email[ $email ];
			if ( $group && ! in_array( $group, $nc['groups'], true ) ) {
				$report['group_nc'][] = $email . ' → ' . $group;
				if ( ! $dry_run ) {
					$r = vp_nc_add_to_group( $nc['id'], $group );
					if ( is_wp_error( $r ) ) {
						$report['errors'][] = $r->get_error_message();
					}
				}
			}
			continue;
		}
		// Neuer NC-User.
		$report['created_nc'][] = $email . ' (' . $group . ')';
		if ( ! $dry_run ) {
			$nc_id = get_user_meta( $wpu->ID, 'vp_nc_id', true )
				?: preg_replace( '/[^A-Za-z0-9._@-]/', '', current( explode( '@', $email ) ) );
			$r = vp_nc_create_user( $nc_id, $wpu->user_email, $wpu->display_name ?: $wpu->user_login, $group ? array( $group ) : array() );
			if ( is_wp_error( $r ) ) {
				$report['errors'][] = 'NC anlegen (' . $email . '): ' . $r->get_error_message();
			} else {
				update_user_meta( $wpu->ID, 'vp_nc_id', $nc_id );
			}
		}
	}

	/* --- Abweichungs-Berichte (nur informativ) --- */
	foreach ( $nc_users as $nc ) {
		if ( $nc['email'] && ! isset( $wp_by_email[ $nc['email'] ] ) && ! vp_nc_role_for_groups( $nc['groups'] ) ) {
			$report['only_nc'][] = $nc['email'] . ' [' . implode( ',', $nc['groups'] ) . ']';
		}
	}
	foreach ( $wp_users as $wpu ) {
		if ( $wpu->user_email && ! isset( $nc_by_email[ strtolower( $wpu->user_email ) ] ) ) {
			$report['only_wp'][] = strtolower( $wpu->user_email );
		}
	}

	update_option( 'vp_nc_sync_last', $report );
	return $report;
}

/* =========================================================================
 * Cron
 * ====================================================================== */

add_action( 'init', function () {
	if ( get_option( 'vp_nc_sync_enabled' ) === '1' && ! wp_next_scheduled( 'vp_nc_sync_cron' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'vp_nc_sync_cron' );
	}
	if ( get_option( 'vp_nc_sync_enabled' ) !== '1' && wp_next_scheduled( 'vp_nc_sync_cron' ) ) {
		wp_clear_scheduled_hook( 'vp_nc_sync_cron' );
	}
} );
add_action( 'vp_nc_sync_cron', function () {
	vp_nc_sync( false );
} );

// Beim Annehmen eines Mitgliedsantrags gleich anstoßen (leichtgewichtig genug).
add_action( 'vp_member_created', function () {
	if ( get_option( 'vp_nc_sync_enabled' ) === '1' && vp_nc_ready() ) {
		vp_nc_sync( false );
	}
} );

/* =========================================================================
 * Optionaler „Nextcloud-Login“ für WordPress
 * ====================================================================== */

add_filter( 'authenticate', 'vp_nc_sso_authenticate', 30, 3 );
function vp_nc_sso_authenticate( $user, $username, $password ) {
	if ( $user instanceof WP_User ) {
		return $user; // WP-Login hat schon geklappt.
	}
	if ( get_option( 'vp_nc_sso' ) !== '1' || ! $username || ! $password || ! vp_nc_ready() ) {
		return $user;
	}

	// Passwort direkt gegen Nextcloud prüfen (OCS „eigener Benutzer“).
	$check = vp_nc_ocs( 'GET', '/cloud/user', array(), array(
		'base' => vp_nc_cfg()['base'],
		'user' => $username,
		'pass' => $password,
	) );
	if ( is_wp_error( $check ) ) {
		return $user; // NC-Login ungültig → normale Fehlermeldung
	}
	$nc_email = strtolower( trim( (string) ( $check['data']['email'] ?? '' ) ) );
	$nc_groups = array_values( (array) ( $check['data']['groups'] ?? array() ) );

	// Passenden WP-User finden.
	$wpu = get_user_by( 'login', $username );
	if ( ! $wpu && $nc_email ) {
		$wpu = get_user_by( 'email', $nc_email );
	}
	if ( $wpu ) {
		return $wpu;
	}

	// Keiner da: nur anlegen, wenn NC-User in einer gemappten Gruppe ist.
	$role = vp_nc_role_for_groups( $nc_groups );
	if ( ! $role || ! $nc_email ) {
		return new WP_Error( 'vp_nc_no_member', __( 'Nextcloud-Login ok, aber kein Vereinskonto zugeordnet. Bitte an den Vorstand wenden.', 'vereinsplugin' ) );
	}
	$login = sanitize_user( $username, true );
	$base  = $login;
	$i = 1;
	while ( username_exists( $login ) ) {
		$login = $base . ++$i;
	}
	$uid = wp_insert_user( array(
		'user_login'   => $login,
		'user_pass'    => wp_generate_password( 20 ),
		'user_email'   => $nc_email,
		'display_name' => (string) ( $check['data']['displayname'] ?? $username ),
		'role'         => $role,
	) );
	if ( is_wp_error( $uid ) ) {
		return $uid;
	}
	update_user_meta( $uid, 'vp_nc_id', $username );
	return get_user_by( 'id', $uid );
}

/* =========================================================================
 * Frontend: Vorstands-Sektion „Nextcloud“
 * ====================================================================== */

function vp_render_nextcloud_section() {
	if ( ! current_user_can( 'vp_manage_members' ) && ! current_user_can( 'manage_options' ) ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) . '</div>';
	}

	$msg = '';
	if ( isset( $_POST['vp_nc_sync_now'] ) && check_admin_referer( 'vp_nc_sync', 'vp_nc_nonce' ) ) {
		$dry = ! empty( $_POST['dry'] );
		vp_nc_sync( $dry );
		$msg = $dry ? __( 'Testlauf abgeschlossen (nichts geändert).', 'vereinsplugin' ) : __( 'Synchronisierung abgeschlossen.', 'vereinsplugin' );
	}

	$last = get_option( 'vp_nc_sync_last', array() );

	ob_start();
	echo '<h2>' . esc_html__( 'Nextcloud-Sync', 'vereinsplugin' ) . '</h2>';

	if ( ! vp_nc_ready() ) {
		echo '<div class="vp-note vp-note-warn">' . esc_html__( 'Zugang unvollständig. Unter „Verein → Einstellungen“ Nextcloud-URL sowie einen Admin-/Gruppenadmin-Zugang und die Gruppen-Zuordnung eintragen.', 'vereinsplugin' ) . '</div>';
	}
	if ( ! vp_nc_group_map() ) {
		echo '<div class="vp-note vp-note-warn">' . esc_html__( 'Es ist noch keine Gruppen→Rollen-Zuordnung gepflegt (Einstellungen).', 'vereinsplugin' ) . '</div>';
	} else {
		echo '<p class="vp-muted">' . esc_html__( 'Zuordnung:', 'vereinsplugin' ) . ' ';
		foreach ( vp_nc_group_map() as $m ) {
			echo esc_html( $m['group'] . ' → ' . $m['role'] ) . ' &nbsp; ';
		}
		echo '</p>';
	}

	if ( $msg ) {
		echo '<div class="vp-note">' . esc_html( $msg ) . '</div>';
	}

	echo '<form method="post" class="vp-card">';
	wp_nonce_field( 'vp_nc_sync', 'vp_nc_nonce' );
	echo '<label class="vp-check"><input type="checkbox" name="dry" value="1"> ' . esc_html__( 'Nur Testlauf (zeigt an, ändert nichts)', 'vereinsplugin' ) . '</label>';
	echo '<p><button class="vp-btn vp-btn-primary" name="vp_nc_sync_now" value="1">' . esc_html__( 'Jetzt synchronisieren', 'vereinsplugin' ) . '</button> ';
	echo '<span class="vp-muted">' . esc_html( get_option( 'vp_nc_sync_enabled' ) === '1' ? __( 'Läuft zusätzlich stündlich automatisch.', 'vereinsplugin' ) : __( 'Automatik ist aus (Einstellungen).', 'vereinsplugin' ) ) . '</span></p>';
	echo '</form>';

	if ( $last ) {
		echo '<h3>' . esc_html__( 'Letzter Lauf', 'vereinsplugin' ) . ' – ' . esc_html( $last['time'] ) . ( ! empty( $last['dry_run'] ) ? ' (Test)' : '' ) . '</h3>';
		$block = function ( $title, $items ) {
			$items = (array) $items;
			echo '<details class="vp-card"><summary><strong>' . esc_html( $title ) . '</strong> (' . count( $items ) . ')</summary>';
			if ( $items ) {
				echo '<ul style="margin:8px 0 0 18px">';
				foreach ( array_slice( $items, 0, 200 ) as $it ) {
					echo '<li>' . esc_html( $it ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</details>';
		};
		$block( __( 'In WordPress angelegt', 'vereinsplugin' ), $last['created_wp'] ?? array() );
		$block( __( 'In Nextcloud angelegt', 'vereinsplugin' ), $last['created_nc'] ?? array() );
		$block( __( 'WP-Rolle angepasst', 'vereinsplugin' ), $last['role_wp'] ?? array() );
		$block( __( 'NC-Gruppe ergänzt', 'vereinsplugin' ), $last['group_nc'] ?? array() );
		$block( __( 'Nur in Nextcloud (nicht übernommen)', 'vereinsplugin' ), $last['only_nc'] ?? array() );
		$block( __( 'Nur in WordPress', 'vereinsplugin' ), $last['only_wp'] ?? array() );
		if ( ! empty( $last['errors'] ) ) {
			$block( __( 'Fehler', 'vereinsplugin' ), $last['errors'] );
		}
	}
	return ob_get_clean();
}
