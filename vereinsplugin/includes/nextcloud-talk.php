<?php
/**
 * Kern: Nextcloud Talk – Kreis-Chats und Online-Besprechungen.
 *
 *  - Jeder Kreis (Gremium) bekommt eine Talk-Unterhaltung; deren Teilnehmende
 *    werden mit den Kreismitgliedern abgeglichen (manuell oder stündlich).
 *    Die Mitgliederversammlung (Typ „mv“) umfasst alle Vereinsmitglieder.
 *  - Sitzungen: Die Besprechung läuft im Talk-Raum des Kreises; auf Wunsch
 *    bekommt eine Sitzung einen eigenen Raum mit Gast-Link (für Externe).
 *  - Mitgliederbereich: Bereich „Chats“ mit den eigenen Kreis-Chats und den
 *    nächsten Online-Besprechungen.
 *
 * Talk selbst läuft in Nextcloud (Browser oder Talk-App). Einbetten per iframe
 * blockiert Nextcloud standardmäßig (Content-Security-Policy), deshalb öffnen
 * die Links Talk in einem neuen Tab bzw. direkt in der Talk-App.
 *
 * Zugang: der Nextcloud-Admin-/Gruppenadmin-Zugang aus nextcloud-sync.php.
 * Dieses Konto legt die Räume an und ist darin Moderator:in.
 * Zuordnung WP → Nextcloud über das User-Meta `vp_nc_id` (setzt der
 * Benutzer-Sync), sonst über die E-Mail-Adresse.
 */

defined( 'ABSPATH' ) || exit;

/* =========================================================================
 * Talk-API (OCS, apps/spreed v4)
 * ====================================================================== */

function vp_talk_ready() {
	return function_exists( 'vp_nc_ready' ) && vp_nc_ready();
}

function vp_talk_api( $method, $path, $body = array() ) {
	return vp_nc_ocs( $method, '/apps/spreed/api/v4' . $path, $body );
}

function vp_talk_url( $token ) {
	return $token ? vp_nc_cfg()['base'] . '/call/' . rawurlencode( $token ) : '';
}

/** Legt eine Unterhaltung an. $typ: 2 = Gruppe (nur Eingeladene), 3 = öffentlich (Gast-Link). @return string|WP_Error Token */
function vp_talk_raum_anlegen( $name, $typ = 2, $beschreibung = '' ) {
	$r = vp_talk_api( 'POST', '/room', array( 'roomType' => (int) $typ, 'roomName' => mb_substr( $name, 0, 255 ) ) );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	$token = (string) ( $r['data']['token'] ?? '' );
	if ( ! $token ) {
		return new WP_Error( 'vp_talk', __( 'Talk hat keinen Raum zurückgegeben. Ist die Talk-App in Nextcloud installiert?', 'vereinsplugin' ) );
	}
	if ( $beschreibung ) {
		vp_talk_api( 'PUT', '/room/' . rawurlencode( $token ) . '/description', array( 'description' => mb_substr( $beschreibung, 0, 2000 ) ) );
	}
	return $token;
}

/** Existiert der Raum noch (und ist das Admin-Konto darin)? */
function vp_talk_raum_existiert( $token ) {
	return $token && ! is_wp_error( vp_talk_api( 'GET', '/room/' . rawurlencode( $token ) ) );
}

/** Nextcloud-Benutzer-ID einer WP-Person (oder '' ohne Nextcloud-Konto). */
function vp_talk_nc_id( $user_id ) {
	$id = (string) get_user_meta( $user_id, 'vp_nc_id', true );
	if ( $id ) {
		return $id;
	}
	$u = get_userdata( $user_id );
	if ( ! $u || ! $u->user_email ) {
		return '';
	}
	$map = get_transient( 'vp_talk_nc_emails' );
	if ( ! is_array( $map ) ) {
		$map   = array();
		$users = vp_nc_get_users();
		if ( ! is_wp_error( $users ) ) {
			foreach ( $users as $nc ) {
				if ( $nc['email'] ) {
					$map[ strtolower( $nc['email'] ) ] = $nc['id'];
				}
			}
		}
		set_transient( 'vp_talk_nc_emails', $map, HOUR_IN_SECONDS );
	}
	$id = $map[ strtolower( $u->user_email ) ] ?? '';
	if ( $id ) {
		update_user_meta( $user_id, 'vp_nc_id', $id );
	}
	return $id;
}

/**
 * Teilnehmende eines Raums an eine Liste von WP-Personen angleichen.
 * Fehlende werden hinzugefügt, Überzählige (nur Nextcloud-Benutzer, nie das
 * Admin-Konto, nie Gäste) entfernt.
 *
 * @return array{hinzu:int,entfernt:int,ohne_nc:string[],fehler:string[]}
 */
function vp_talk_teilnehmer_abgleichen( $token, array $wp_user_ids, $entfernen = true ) {
	$bericht = array( 'hinzu' => 0, 'entfernt' => 0, 'ohne_nc' => array(), 'fehler' => array() );
	$pfad    = '/room/' . rawurlencode( $token );

	$soll = array();
	foreach ( array_unique( array_map( 'intval', $wp_user_ids ) ) as $uid ) {
		$nc = vp_talk_nc_id( $uid );
		if ( $nc ) {
			$soll[ $nc ] = true;
		} else {
			$u                    = get_userdata( $uid );
			$bericht['ohne_nc'][] = $u ? $u->display_name : '#' . $uid;
		}
	}

	$ist = vp_talk_api( 'GET', $pfad . '/participants' );
	if ( is_wp_error( $ist ) ) {
		$bericht['fehler'][] = $ist->get_error_message();
		return $bericht;
	}
	$admin   = vp_nc_cfg()['user'];
	$da      = array();
	foreach ( (array) ( $ist['data'] ?? array() ) as $p ) {
		if ( 'users' !== ( $p['actorType'] ?? '' ) ) {
			continue;
		}
		$da[ $p['actorId'] ] = (int) ( $p['attendeeId'] ?? 0 );
	}

	foreach ( array_keys( $soll ) as $nc ) {
		if ( isset( $da[ $nc ] ) ) {
			continue;
		}
		$r = vp_talk_api( 'POST', $pfad . '/participants', array( 'newParticipant' => $nc, 'source' => 'users' ) );
		if ( is_wp_error( $r ) ) {
			$bericht['fehler'][] = $nc . ': ' . $r->get_error_message();
		} else {
			$bericht['hinzu']++;
		}
	}
	if ( $entfernen ) {
		foreach ( $da as $nc => $attendee ) {
			if ( isset( $soll[ $nc ] ) || 0 === strcasecmp( $nc, $admin ) || ! $attendee ) {
				continue;
			}
			$r = vp_talk_api( 'DELETE', $pfad . '/attendees?attendeeId=' . $attendee );
			if ( is_wp_error( $r ) ) {
				$bericht['fehler'][] = $nc . ': ' . $r->get_error_message();
			} else {
				$bericht['entfernt']++;
			}
		}
	}
	return $bericht;
}

/* =========================================================================
 * Kreis-Chats
 * ====================================================================== */

/** gremium_id => Token */
function vp_talk_kreis_raeume() {
	return array_filter( (array) get_option( 'vp_talk_kreise', array() ) );
}

function vp_talk_kreis_token( $gremium_id ) {
	return (string) ( vp_talk_kreis_raeume()[ (int) $gremium_id ] ?? '' );
}

/** WP-IDs der aktuellen Mitglieder eines Kreises (MV: alle Vereinsmitglieder). */
function vp_talk_kreis_mitglieder( $gremium ) {
	global $wpdb;
	if ( 'mv' === ( $gremium->typ ?? '' ) ) {
		return get_users( array(
			'role__in' => array( VP_MEMBER_ROLE, 'administrator', 'editor' ),
			'fields'   => 'ID',
		) );
	}
	$t = $wpdb->prefix . 'pp_kreis_mitglieder';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
		return array();
	}
	return $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT user_id FROM $t WHERE gremium_id = %d AND ausgetreten_am IS NULL",
		$gremium->id
	) );
}

/**
 * Kreis-Chat anlegen (falls nötig) und Mitglieder abgleichen.
 * @return array Bericht (siehe vp_talk_teilnehmer_abgleichen) + 'token', 'neu'
 */
function vp_talk_kreis_einrichten( $gremium ) {
	$raeume = vp_talk_kreis_raeume();
	$token  = (string) ( $raeume[ (int) $gremium->id ] ?? '' );
	$neu    = false;
	if ( ! $token || ! vp_talk_raum_existiert( $token ) ) {
		$token = vp_talk_raum_anlegen(
			$gremium->name,
			2,
			sprintf( /* translators: %s = site */ __( 'Kreis-Chat, verwaltet vom Mitgliederbereich (%s). Mitglieder werden automatisch abgeglichen.', 'vereinsplugin' ), wp_parse_url( home_url(), PHP_URL_HOST ) )
		);
		if ( is_wp_error( $token ) ) {
			return array( 'token' => '', 'neu' => false, 'hinzu' => 0, 'entfernt' => 0, 'ohne_nc' => array(), 'fehler' => array( $token->get_error_message() ) );
		}
		$raeume[ (int) $gremium->id ] = $token;
		update_option( 'vp_talk_kreise', $raeume, false );
		$neu = true;
	}
	return array( 'token' => $token, 'neu' => $neu ) + vp_talk_teilnehmer_abgleichen( $token, vp_talk_kreis_mitglieder( $gremium ) );
}

/** Alle Kreise abgleichen (Button + Cron). Legt nur Räume für ausgewählte Kreise an. */
function vp_talk_alle_kreise_abgleichen() {
	if ( ! vp_talk_ready() || ! function_exists( 'pp_get_gremien' ) ) {
		return array();
	}
	$aktiv   = array_map( 'intval', (array) get_option( 'vp_talk_kreise_aktiv', array() ) );
	$bericht = array();
	foreach ( (array) pp_get_gremien() as $g ) {
		if ( in_array( (int) $g->id, $aktiv, true ) ) {
			$bericht[ $g->name ] = vp_talk_kreis_einrichten( $g );
		}
	}
	update_option( 'vp_talk_letzter_abgleich', array( 'zeit' => current_time( 'mysql' ), 'bericht' => $bericht ), false );
	return $bericht;
}

add_action( 'init', function () {
	$an = get_option( 'vp_talk_auto' ) === '1';
	if ( $an && ! wp_next_scheduled( 'vp_talk_sync_cron' ) ) {
		wp_schedule_event( time() + 600, 'hourly', 'vp_talk_sync_cron' );
	} elseif ( ! $an && wp_next_scheduled( 'vp_talk_sync_cron' ) ) {
		wp_clear_scheduled_hook( 'vp_talk_sync_cron' );
	}
} );
add_action( 'vp_talk_sync_cron', 'vp_talk_alle_kreise_abgleichen' );

/* =========================================================================
 * Sitzungen: Online-Besprechung
 * ====================================================================== */

/** protokoll_id => Token eines eigenen Besprechungsraums (mit Gast-Link). */
function vp_talk_sitzungs_raeume() {
	return array_filter( (array) get_option( 'vp_talk_sitzungen', array() ) );
}

/** Talk-Link einer Sitzung: eigener Raum, sonst der Kreis-Chat. */
function vp_talk_sitzung_url( $protokoll_id, $gremium_id ) {
	$eigen = vp_talk_sitzungs_raeume()[ (int) $protokoll_id ] ?? '';
	return vp_talk_url( $eigen ?: vp_talk_kreis_token( $gremium_id ) );
}

add_action( 'admin_post_vp_talk_sitzung', 'vp_talk_handle_sitzung' );
function vp_talk_handle_sitzung() {
	if ( ! current_user_can( 'pp_manage' ) && ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) );
	}
	check_admin_referer( 'vp_talk_sitzung' );
	global $wpdb;
	$pid  = (int) ( $_POST['protokoll_id'] ?? 0 );
	$was  = sanitize_key( $_POST['was'] ?? '' );
	$back = esc_url_raw( wp_unslash( $_POST['zurueck'] ?? '' ) ) ?: home_url( '/' );
	$s    = $wpdb->get_row( $wpdb->prepare(
		"SELECT p.*, g.name AS gremium_name, g.typ AS gremium_typ FROM {$wpdb->prefix}pp_protokolle p LEFT JOIN {$wpdb->prefix}pp_gremien g ON g.id = p.gremium_id WHERE p.id = %d",
		$pid
	) );
	$fehler = '';

	if ( $s && vp_talk_ready() ) {
		if ( 'kreis' === $was ) {
			$r      = vp_talk_kreis_einrichten( (object) array( 'id' => $s->gremium_id, 'name' => $s->gremium_name, 'typ' => $s->gremium_typ ) );
			$fehler = $r['fehler'] ? implode( '; ', $r['fehler'] ) : '';
			if ( $r['token'] ) {
				$aktiv = array_map( 'intval', (array) get_option( 'vp_talk_kreise_aktiv', array() ) );
				$aktiv[] = (int) $s->gremium_id;
				update_option( 'vp_talk_kreise_aktiv', array_values( array_unique( $aktiv ) ), false );
			}
		} elseif ( 'eigen' === $was ) {
			$name  = $s->titel . ( $s->datum ? ' (' . mysql2date( 'd.m.Y', $s->datum ) . ')' : '' );
			$token = vp_talk_raum_anlegen( $name, 3, __( 'Besprechungsraum für diese Sitzung. Externe können über den Gast-Link teilnehmen.', 'vereinsplugin' ) );
			if ( is_wp_error( $token ) ) {
				$fehler = $token->get_error_message();
			} else {
				$raeume         = vp_talk_sitzungs_raeume();
				$raeume[ $pid ] = $token;
				update_option( 'vp_talk_sitzungen', $raeume, false );
				$g = (object) array( 'id' => $s->gremium_id, 'typ' => $s->gremium_typ );
				vp_talk_teilnehmer_abgleichen( $token, vp_talk_kreis_mitglieder( $g ), false );
			}
		} elseif ( 'eigen_weg' === $was ) {
			$raeume = vp_talk_sitzungs_raeume();
			if ( isset( $raeume[ $pid ] ) ) {
				vp_talk_api( 'DELETE', '/room/' . rawurlencode( $raeume[ $pid ] ) );
				unset( $raeume[ $pid ] );
				update_option( 'vp_talk_sitzungen', $raeume, false );
			}
		}
	} elseif ( ! vp_talk_ready() ) {
		$fehler = __( 'Nextcloud ist nicht eingerichtet.', 'vereinsplugin' );
	}
	wp_safe_redirect( $fehler ? add_query_arg( 'pp_error', rawurlencode( $fehler ), $back ) : add_query_arg( 'pp_saved', '1', $back ) );
	exit;
}

/** Knöpfe in „Geplante Sitzungen“ (Hook aus ProtokollPro). */
add_action( 'pp_sitzung_aktionen', 'vp_talk_sitzung_knoepfe' );
function vp_talk_sitzung_knoepfe( $s ) {
	if ( ! vp_talk_ready() ) {
		return;
	}
	$eigen  = vp_talk_sitzungs_raeume()[ (int) $s->id ] ?? '';
	$kreis  = vp_talk_kreis_token( $s->gremium_id );
	$zurueck = home_url( add_query_arg( array() ) );
	$form   = function ( $was, $label, $klasse = '', $confirm = '' ) use ( $s, $zurueck ) {
		printf(
			'<form method="post" action="%s" style="display:inline">%s<input type="hidden" name="action" value="vp_talk_sitzung"><input type="hidden" name="protokoll_id" value="%d"><input type="hidden" name="was" value="%s"><input type="hidden" name="zurueck" value="%s"><button type="submit" class="pp-btn pp-btn-small %s"%s>%s</button></form> ',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'vp_talk_sitzung', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput
			(int) $s->id,
			esc_attr( $was ),
			esc_url( $zurueck ),
			esc_attr( $klasse ),
			$confirm ? ' onclick="return confirm(\'' . esc_js( $confirm ) . '\')"' : '',
			esc_html( $label )
		);
	};

	if ( $eigen || $kreis ) {
		printf( '<a class="pp-btn pp-btn-small" href="%s" target="_blank" rel="noopener">%s</a> ', esc_url( vp_talk_url( $eigen ?: $kreis ) ), esc_html__( '🎥 Online (Talk)', 'vereinsplugin' ) );
	} else {
		$form( 'kreis', __( '🎥 Kreis-Chat für Online-Teilnahme anlegen', 'vereinsplugin' ) );
	}
	if ( $eigen ) {
		$form( 'eigen_weg', __( 'Gast-Raum löschen', 'vereinsplugin' ), 'pp-link-danger', __( 'Eigenen Besprechungsraum in Nextcloud löschen?', 'vereinsplugin' ) );
	} else {
		$form( 'eigen', __( 'Eigener Raum mit Gast-Link', 'vereinsplugin' ) );
	}
}

/* =========================================================================
 * Mitgliederbereich: „Chats“
 * ====================================================================== */

add_filter( 'vp_member_sections', function ( $sections ) {
	if ( ! vp_talk_ready() ) {
		return $sections;
	}
	$neu = array();
	foreach ( $sections as $k => $s ) {
		$neu[ $k ] = $s;
		if ( 'kalender' === $k ) {
			$neu['chats'] = array(
				'label'  => __( 'Chats & Besprechungen', 'vereinsplugin' ),
				'group'  => 'mitglied',
				'cap'    => 'read',
				'render' => 'vp_render_talk_section',
			);
		}
	}
	return $neu;
}, 20 );

function vp_render_talk_section() {
	global $wpdb;
	$uid   = get_current_user_id();
	$nc_id = vp_talk_nc_id( $uid );
	$base  = vp_nc_cfg()['base'];
	$msg   = '';

	if ( current_user_can( 'vp_manage_members' ) || current_user_can( 'manage_options' ) ) {
		$msg = vp_talk_verwaltung_speichern();
	}

	ob_start();
	echo '<h2>' . esc_html__( 'Chats & Besprechungen', 'vereinsplugin' ) . '</h2>';
	if ( $msg ) {
		echo '<div class="vp-note">' . wp_kses_post( $msg ) . '</div>';
	}
	if ( ! $nc_id ) {
		echo '<div class="vp-note vp-note-warn">' . esc_html__( 'Für dich ist noch kein Nextcloud-Konto verknüpft. Die Chats laufen in Nextcloud Talk – bitte beim Vorstand melden (der Nextcloud-Sync legt Konten an).', 'vereinsplugin' ) . '</div>';
	}

	// Meine Kreis-Chats.
	$raeume = vp_talk_kreis_raeume();
	$meine  = array();
	if ( function_exists( 'pp_get_gremien' ) ) {
		foreach ( (array) pp_get_gremien() as $g ) {
			if ( empty( $raeume[ (int) $g->id ] ) ) {
				continue;
			}
			if ( in_array( (string) $uid, array_map( 'strval', (array) vp_talk_kreis_mitglieder( $g ) ), true ) ) {
				$meine[] = $g;
			}
		}
	}
	echo '<div class="vp-card"><h3>' . esc_html__( 'Meine Kreis-Chats', 'vereinsplugin' ) . '</h3>';
	if ( $meine ) {
		echo '<div class="vp-talk-liste">';
		foreach ( $meine as $g ) {
			printf(
				'<a class="vp-talk-raum" href="%s" target="_blank" rel="noopener"><span class="vp-talk-icon">💬</span><span><strong>%s</strong><br><small class="vp-muted">%s</small></span></a>',
				esc_url( vp_talk_url( $raeume[ (int) $g->id ] ) ),
				esc_html( $g->name ),
				esc_html__( 'Chat & Videocall in Nextcloud Talk öffnen', 'vereinsplugin' )
			);
		}
		echo '</div>';
	} else {
		echo '<p class="vp-muted">' . esc_html__( 'Du bist in keinem Kreis mit eingerichtetem Chat.', 'vereinsplugin' ) . '</p>';
	}
	printf( '<p class="vp-muted">%s <a href="%s" target="_blank" rel="noopener">%s</a></p>', esc_html__( 'Alle Unterhaltungen:', 'vereinsplugin' ), esc_url( $base . '/apps/spreed/' ), esc_html__( 'Nextcloud Talk öffnen', 'vereinsplugin' ) );
	echo '</div>';

	// Nächste Online-Besprechungen (geplante Sitzungen mit Talk-Raum).
	$prot = $wpdb->prefix . 'pp_protokolle';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prot ) ) === $prot ) {
		$sitz = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.id, p.titel, p.datum, p.uhrzeit_beginn, p.gremium_id, g.name AS gremium FROM $prot p LEFT JOIN {$wpdb->prefix}pp_gremien g ON g.id = p.gremium_id
			 WHERE p.status = 'entwurf' AND p.datum >= %s ORDER BY p.datum, p.uhrzeit_beginn LIMIT 20",
			current_time( 'Y-m-d' )
		) );
		$zeilen = '';
		foreach ( (array) $sitz as $s ) {
			$url = vp_talk_sitzung_url( $s->id, $s->gremium_id );
			if ( ! $url || ( ! in_array( $s->gremium_id, wp_list_pluck( $meine, 'id' ) ) && ! isset( vp_talk_sitzungs_raeume()[ (int) $s->id ] ) && ! current_user_can( 'pp_manage' ) ) ) {
				continue;
			}
			$zeilen .= sprintf(
				'<tr><td>%s%s</td><td><strong>%s</strong><br><small class="vp-muted">%s</small></td><td><a class="vp-btn vp-btn-primary" href="%s" target="_blank" rel="noopener">%s</a></td></tr>',
				esc_html( mysql2date( 'd.m.Y', $s->datum ) ),
				$s->uhrzeit_beginn ? ' ' . esc_html( substr( $s->uhrzeit_beginn, 0, 5 ) ) : '',
				esc_html( $s->titel ),
				esc_html( $s->gremium ),
				esc_url( $url ),
				esc_html__( 'Teilnehmen', 'vereinsplugin' )
			);
		}
		if ( $zeilen ) {
			echo '<div class="vp-card"><h3>' . esc_html__( 'Nächste Online-Besprechungen', 'vereinsplugin' ) . '</h3><div class="vp-table-wrap"><table class="vp-table"><tbody>' . $zeilen . '</tbody></table></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	echo '<p class="vp-muted">' . esc_html__( 'Tipp: Mit der App „Nextcloud Talk“ (Android/iOS/Desktop) bekommst du Benachrichtigungen und kannst direkt telefonieren; die Links oben öffnen sich dann in der App.', 'vereinsplugin' ) . '</p>';

	if ( current_user_can( 'vp_manage_members' ) || current_user_can( 'manage_options' ) ) {
		echo vp_talk_render_verwaltung(); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	return ob_get_clean();
}

/* ---- Vorstand: Kreis-Chats verwalten ---- */

function vp_talk_verwaltung_speichern() {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['vp_talk_nonce'] ) ) {
		return '';
	}
	check_admin_referer( 'vp_talk_verwalten', 'vp_talk_nonce' );
	$aktiv = array_map( 'intval', (array) wp_unslash( $_POST['kreise'] ?? array() ) );
	update_option( 'vp_talk_kreise_aktiv', $aktiv, false );
	update_option( 'vp_talk_auto', isset( $_POST['auto'] ) ? '1' : '0' );

	if ( isset( $_POST['vp_talk_trennen'] ) ) {
		$raeume = vp_talk_kreis_raeume();
		unset( $raeume[ (int) $_POST['vp_talk_trennen'] ] );
		update_option( 'vp_talk_kreise', $raeume, false );
		return esc_html__( 'Verknüpfung gelöst. Die Unterhaltung bleibt in Nextcloud bestehen.', 'vereinsplugin' );
	}

	$bericht = vp_talk_alle_kreise_abgleichen();
	$zeilen  = array();
	foreach ( $bericht as $name => $b ) {
		$z = sprintf(
			/* translators: 1: Kreis, 2: hinzu, 3: entfernt */
			__( '%1$s: %2$d hinzugefügt, %3$d entfernt', 'vereinsplugin' ),
			$name, $b['hinzu'], $b['entfernt']
		);
		if ( $b['neu'] ) {
			$z .= ' · ' . __( 'Chat neu angelegt', 'vereinsplugin' );
		}
		if ( $b['ohne_nc'] ) {
			$z .= ' · ' . __( 'ohne Nextcloud-Konto:', 'vereinsplugin' ) . ' ' . implode( ', ', $b['ohne_nc'] );
		}
		if ( $b['fehler'] ) {
			$z .= ' · ' . __( 'Fehler:', 'vereinsplugin' ) . ' ' . implode( '; ', $b['fehler'] );
		}
		$zeilen[] = esc_html( $z );
	}
	return esc_html__( 'Gespeichert und abgeglichen.', 'vereinsplugin' ) . ( $zeilen ? '<br>' . implode( '<br>', $zeilen ) : '' );
}

function vp_talk_render_verwaltung() {
	if ( ! function_exists( 'pp_get_gremien' ) ) {
		return '';
	}
	$aktiv  = array_map( 'intval', (array) get_option( 'vp_talk_kreise_aktiv', array() ) );
	$raeume = vp_talk_kreis_raeume();
	$letzt  = get_option( 'vp_talk_letzter_abgleich', array() );

	ob_start();
	echo '<form method="post" class="vp-card vp-form"><h3>' . esc_html__( 'Vorstand: Kreis-Chats einrichten', 'vereinsplugin' ) . '</h3>';
	wp_nonce_field( 'vp_talk_verwalten', 'vp_talk_nonce' );
	echo '<p class="vp-muted">' . esc_html__( 'Für jeden angehakten Kreis wird in Nextcloud Talk eine Unterhaltung angelegt; die Teilnehmenden werden mit den Kreismitgliedern abgeglichen (Neue rein, Ausgetretene raus). Die Mitgliederversammlung umfasst alle Mitglieder. Voraussetzung: Die Personen haben ein Nextcloud-Konto (siehe „Nextcloud-Sync“).', 'vereinsplugin' ) . '</p>';
	echo '<div class="vp-table-wrap"><table class="vp-table"><tbody>';
	foreach ( (array) pp_get_gremien() as $g ) {
		$tok = $raeume[ (int) $g->id ] ?? '';
		printf(
			'<tr><td><label class="vp-check" style="margin:0"><input type="checkbox" name="kreise[]" value="%d"%s> %s</label></td><td>%s</td><td style="white-space:nowrap">%s</td></tr>',
			(int) $g->id,
			checked( in_array( (int) $g->id, $aktiv, true ), true, false ),
			esc_html( $g->name ),
			$tok ? '<a href="' . esc_url( vp_talk_url( $tok ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Chat öffnen', 'vereinsplugin' ) . '</a>' : '<span class="vp-muted">' . esc_html__( 'noch kein Chat', 'vereinsplugin' ) . '</span>',
			$tok ? '<button class="vp-btn" name="vp_talk_trennen" value="' . (int) $g->id . '" onclick="return confirm(\'' . esc_js( __( 'Verknüpfung lösen? Die Unterhaltung bleibt in Nextcloud, wird aber nicht mehr abgeglichen.', 'vereinsplugin' ) ) . '\')">' . esc_html__( 'Trennen', 'vereinsplugin' ) . '</button>' : ''
		);
	}
	echo '</tbody></table></div>';
	printf( '<label class="vp-check"><input type="checkbox" name="auto" value="1"%s> %s</label>', checked( get_option( 'vp_talk_auto' ), '1', false ), esc_html__( 'Stündlich automatisch abgleichen', 'vereinsplugin' ) );
	echo '<p><button class="vp-btn vp-btn-primary">' . esc_html__( 'Speichern & jetzt abgleichen', 'vereinsplugin' ) . '</button></p>';
	if ( ! empty( $letzt['zeit'] ) ) {
		echo '<p class="vp-muted">' . esc_html( sprintf( /* translators: %s = Zeit */ __( 'Letzter Abgleich: %s', 'vereinsplugin' ), mysql2date( 'd.m.Y H:i', $letzt['zeit'] ) ) ) . '</p>';
	}
	echo '</form>';
	return ob_get_clean();
}

/* =========================================================================
 * Kalender: Online-Link an Sitzungen
 * ====================================================================== */

add_filter( 'vp_kalender_termine', function ( $termine ) {
	if ( ! vp_talk_ready() ) {
		return $termine;
	}
	global $wpdb;
	$ids = array();
	foreach ( $termine as $t ) {
		if ( 'sitzung' === $t['kat'] && preg_match( '/^sitzung-(\d+)$/', $t['id'], $m ) ) {
			$ids[] = (int) $m[1];
		}
	}
	if ( ! $ids ) {
		return $termine;
	}
	$gremium = $wpdb->get_results( "SELECT id, gremium_id FROM {$wpdb->prefix}pp_protokolle WHERE id IN (" . implode( ',', $ids ) . ')', OBJECT_K );
	foreach ( $termine as &$t ) {
		if ( 'sitzung' === $t['kat'] && preg_match( '/^sitzung-(\d+)$/', $t['id'], $m ) && isset( $gremium[ (int) $m[1] ] ) ) {
			$url = vp_talk_sitzung_url( (int) $m[1], $gremium[ (int) $m[1] ]->gremium_id );
			if ( $url ) {
				$t['info'] = trim( $t['info'] . "\n" . __( 'Online (Nextcloud Talk):', 'vereinsplugin' ) . ' ' . $url );
			}
		}
	}
	return $termine;
} );
