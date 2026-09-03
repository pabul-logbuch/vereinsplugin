<?php
/**
 * Kern: einfacher Vereins-Newsletter.
 *
 *  - Betreff + Text, Versand als BCC an alle Benutzer einer Rolle über wp_mail().
 *  - Jede Sendung wird in {$wpdb->prefix}vp_newsletter protokolliert.
 *  - Frontend-Sektion „Newsletter" (Gruppe Mitgliederverwaltung).
 *  - REST-Aktion liegt in rest-sync-api.php (vp_sync_action_newsletter_send()).
 */

defined( 'ABSPATH' ) || exit;

function vp_newsletter_table() {
	global $wpdb;
	return $wpdb->prefix . 'vp_newsletter';
}

add_action( 'plugins_loaded', 'vp_newsletter_maybe_create_table', 6 );
function vp_newsletter_maybe_create_table() {
	if ( get_option( 'vp_newsletter_db' ) === '1' ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$collate = $wpdb->get_charset_collate();
	dbDelta( 'CREATE TABLE ' . vp_newsletter_table() . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		betreff VARCHAR(255) NOT NULL DEFAULT '',
		body LONGTEXT NULL,
		empfaenger_rolle VARCHAR(60) NOT NULL DEFAULT '',
		anzahl INT NOT NULL DEFAULT 0,
		gesendet_am DATETIME NULL,
		gesendet_von BIGINT UNSIGNED NULL,
		PRIMARY KEY  (id),
		KEY gesendet_am (gesendet_am)
	) {$collate};" );
	update_option( 'vp_newsletter_db', '1' );
}

/**
 * Newsletter verschicken.
 *
 * @param string $betreff
 * @param string $body       Klartext.
 * @param string $rolle      WordPress-Rolle (z. B. wl_mitglied).
 * @param bool   $test       true → nur an die aktuelle Person, kein Protokoll.
 * @return array{ok:bool, anzahl:int, message:string}
 */
function vp_newsletter_send( $betreff, $body, $rolle, $test = false ) {
	$betreff = trim( wp_strip_all_tags( (string) $betreff ) );
	$body    = trim( (string) $body );
	$rolle   = sanitize_key( $rolle );
	if ( '' === $betreff || '' === $body ) {
		return array( 'ok' => false, 'anzahl' => 0, 'message' => 'Betreff und Text sind Pflicht.' );
	}

	$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
	$from    = get_option( 'admin_email' );
	if ( $from ) {
		$headers[] = 'From: ' . get_bloginfo( 'name' ) . ' <' . $from . '>';
	}

	if ( $test ) {
		$me = wp_get_current_user();
		$ok = $me->user_email ? wp_mail( $me->user_email, '[Test] ' . $betreff, $body, $headers ) : false;
		return array( 'ok' => (bool) $ok, 'anzahl' => $ok ? 1 : 0, 'message' => $ok ? 'Testmail verschickt.' : 'Testmail fehlgeschlagen.' );
	}

	$users = get_users( array( 'role' => $rolle, 'fields' => array( 'user_email' ) ) );
	$mails = array_values( array_unique( array_filter( array_map(
		static function ( $u ) {
			return is_email( $u->user_email ) ? $u->user_email : '';
		},
		$users
	) ) ) );

	if ( ! $mails ) {
		return array( 'ok' => false, 'anzahl' => 0, 'message' => 'Keine Empfänger mit E-Mail in der Rolle „' . $rolle . '".' );
	}

	$sent = 0;
	foreach ( array_chunk( $mails, 40 ) as $chunk ) {
		$h = array_merge( $headers, array( 'Bcc: ' . implode( ',', $chunk ) ) );
		// „To" auf die Absenderadresse, echte Empfänger im BCC.
		if ( wp_mail( $from ?: $chunk[0], $betreff, $body, $h ) ) {
			$sent += count( $chunk );
		}
	}

	global $wpdb;
	$wpdb->insert( vp_newsletter_table(), array(
		'betreff'          => $betreff,
		'body'             => $body,
		'empfaenger_rolle' => $rolle,
		'anzahl'           => $sent,
		'gesendet_am'      => current_time( 'mysql' ),
		'gesendet_von'     => get_current_user_id(),
	) );

	return array( 'ok' => $sent > 0, 'anzahl' => $sent, 'message' => $sent . ' Empfänger angeschrieben.' );
}

/* =========================================================================
 * Frontend-Sektion „Newsletter"
 * ====================================================================== */

function vp_render_newsletter_section() {
	if ( ! current_user_can( 'vp_manage_members' ) && ! current_user_can( 'manage_options' ) ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) . '</div>';
	}
	vp_newsletter_maybe_create_table();

	$msg = '';
	if ( isset( $_POST['vp_nl_send'] ) && check_admin_referer( 'vp_nl', 'vp_nl_nonce' ) ) {
		$res = vp_newsletter_send(
			wp_unslash( $_POST['betreff'] ?? '' ),
			wp_unslash( $_POST['body'] ?? '' ),
			$_POST['rolle'] ?? 'wl_mitglied',
			! empty( $_POST['test'] )
		);
		$msg = $res['message'];
	}

	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT * FROM ' . vp_newsletter_table() . ' ORDER BY gesendet_am DESC LIMIT 30' );

	$roles = array();
	foreach ( wp_roles()->roles as $slug => $r ) {
		$roles[ $slug ] = $r['name'];
	}

	ob_start();
	echo '<h2>' . esc_html__( 'Newsletter', 'vereinsplugin' ) . '</h2>';
	if ( $msg ) {
		echo '<div class="vp-note">' . esc_html( $msg ) . '</div>';
	}
	echo '<form method="post" class="vp-card">';
	wp_nonce_field( 'vp_nl', 'vp_nl_nonce' );
	echo '<p><label>' . esc_html__( 'Empfänger-Rolle', 'vereinsplugin' ) . '<br><select name="rolle">';
	foreach ( $roles as $slug => $name ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $slug ), selected( $slug, 'wl_mitglied', false ), esc_html( $name ) );
	}
	echo '</select></label></p>';
	echo '<p><label>' . esc_html__( 'Betreff', 'vereinsplugin' ) . '<br><input type="text" name="betreff" class="regular-text" style="width:100%"></label></p>';
	echo '<p><label>' . esc_html__( 'Text', 'vereinsplugin' ) . '<br><textarea name="body" rows="10" style="width:100%"></textarea></label></p>';
	echo '<p><label><input type="checkbox" name="test" value="1"> ' . esc_html__( 'Nur Testmail an mich', 'vereinsplugin' ) . '</label></p>';
	echo '<p><button class="vp-btn vp-btn-primary" name="vp_nl_send" value="1">' . esc_html__( 'Senden', 'vereinsplugin' ) . '</button></p>';
	echo '</form>';

	if ( $rows ) {
		echo '<h3>' . esc_html__( 'Versendet', 'vereinsplugin' ) . '</h3><table class="widefat striped"><thead><tr><th>Datum</th><th>Betreff</th><th>Rolle</th><th>Anzahl</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $r->gesendet_am ),
				esc_html( $r->betreff ),
				esc_html( $r->empfaenger_rolle ),
				esc_html( $r->anzahl )
			);
		}
		echo '</tbody></table>';
	}
	return ob_get_clean();
}
