<?php
/**
 * Kern: Formular-Baukasten (z. B. Anmeldung zu Veranstaltungen, Umfragen,
 * Feedback, Helfer:innen-Listen).
 *
 *   vp_formulare           – Kopfdaten + Feldschema (JSON)
 *   vp_formular_eintraege  – abgeschickte Einträge (Daten als JSON)
 *
 * Bauen/Auswerten: Mitgliederbereich → „Formulare" (Vorstand).
 * Veröffentlichen: [verein_formular id="3"] oder [verein_formular slug="…"]
 * auf einer beliebigen Seite.
 *
 * Felder-Baukasten folgt bewusst demselben Muster wie die Rechnungs-
 * Positionen (siehe rechnungen.php): keine JS-Dynamik, sondern bestehende
 * Felder + ein paar leere Zeilen, als parallele Arrays gepostet.
 */

defined( 'ABSPATH' ) || exit;

define( 'VP_FORMULAR_DB_VERSION', '1' );

function vp_formular_table()   { global $wpdb; return $wpdb->prefix . 'vp_formulare'; }
function vp_formular_eintraege_table() { global $wpdb; return $wpdb->prefix . 'vp_formular_eintraege'; }

add_action( 'plugins_loaded', 'vp_formulare_maybe_upgrade', 6 );
function vp_formulare_maybe_upgrade() {
	if ( get_option( 'vp_formular_db_version' ) === VP_FORMULAR_DB_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$collate = $wpdb->get_charset_collate();

	dbDelta( 'CREATE TABLE ' . vp_formular_table() . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		titel VARCHAR(190) NOT NULL DEFAULT '',
		slug VARCHAR(190) NOT NULL DEFAULT '',
		beschreibung TEXT NULL,
		felder LONGTEXT NULL,
		status VARCHAR(16) NOT NULL DEFAULT 'entwurf',
		max_teilnehmer INT UNSIGNED DEFAULT NULL,
		login_pflicht TINYINT NOT NULL DEFAULT 0,
		schliesst_am DATETIME NULL,
		dank_text TEXT NULL,
		benachrichtigung_email VARCHAR(190) NOT NULL DEFAULT '',
		erstellt_von BIGINT UNSIGNED DEFAULT NULL,
		erstellt_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		aktualisiert_am DATETIME NULL,
		PRIMARY KEY (id),
		UNIQUE KEY slug (slug),
		KEY status (status)
	) {$collate};" );

	dbDelta( 'CREATE TABLE ' . vp_formular_eintraege_table() . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		formular_id BIGINT UNSIGNED NOT NULL,
		daten LONGTEXT NULL,
		anzeige_name VARCHAR(190) NOT NULL DEFAULT '',
		anzeige_email VARCHAR(190) NOT NULL DEFAULT '',
		user_id BIGINT UNSIGNED DEFAULT NULL,
		ip_hash VARCHAR(64) NOT NULL DEFAULT '',
		eingereicht_am DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY formular_id (formular_id)
	) {$collate};" );

	update_option( 'vp_formular_db_version', VP_FORMULAR_DB_VERSION );
}

/* -------------------------------------------------------------------------
 * Feld-Typen
 * ---------------------------------------------------------------------- */

function vp_formular_feldtypen() {
	return array(
		'text'       => __( 'Text (einzeilig)', 'vereinsplugin' ),
		'textarea'   => __( 'Text (mehrzeilig)', 'vereinsplugin' ),
		'email'      => __( 'E-Mail', 'vereinsplugin' ),
		'tel'        => __( 'Telefon', 'vereinsplugin' ),
		'number'     => __( 'Zahl', 'vereinsplugin' ),
		'date'       => __( 'Datum', 'vereinsplugin' ),
		'select'     => __( 'Auswahl (Dropdown)', 'vereinsplugin' ),
		'checkboxes' => __( 'Mehrfachauswahl (Kästchen)', 'vereinsplugin' ),
		'checkbox'   => __( 'Zustimmung (ein Kästchen)', 'vereinsplugin' ),
		'heading'    => __( 'Nur Überschrift/Hinweistext (kein Eingabefeld)', 'vereinsplugin' ),
	);
}

function vp_formular_get( $id ) {
	global $wpdb;
	$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vp_formular_table() . ' WHERE id = %d', (int) $id ) );
	return $r ? vp_formular_hydrate( $r ) : null;
}

function vp_formular_get_by_slug( $slug ) {
	global $wpdb;
	$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vp_formular_table() . ' WHERE slug = %s', sanitize_title( $slug ) ) );
	return $r ? vp_formular_hydrate( $r ) : null;
}

function vp_formular_alle() {
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT * FROM ' . vp_formular_table() . ' ORDER BY id DESC' );
	return array_map( 'vp_formular_hydrate', $rows );
}

function vp_formular_hydrate( $r ) {
	$r->felder = json_decode( (string) $r->felder, true ) ?: array();
	return $r;
}

function vp_formular_eintraege( $formular_id ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . vp_formular_eintraege_table() . ' WHERE formular_id = %d ORDER BY id ASC', (int) $formular_id ) );
	foreach ( $rows as $r ) {
		$r->daten = json_decode( (string) $r->daten, true ) ?: array();
	}
	return $rows;
}

function vp_formular_anzahl_eintraege( $formular_id ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . vp_formular_eintraege_table() . ' WHERE formular_id = %d', (int) $formular_id ) );
}

/** Ist ein Formular gerade offen für neue Einträge? */
function vp_formular_ist_offen( $f ) {
	if ( ! $f || 'veroeffentlicht' !== $f->status ) {
		return false;
	}
	if ( $f->schliesst_am && strtotime( $f->schliesst_am ) < time() ) {
		return false;
	}
	if ( $f->max_teilnehmer && vp_formular_anzahl_eintraege( $f->id ) >= (int) $f->max_teilnehmer ) {
		return false;
	}
	return true;
}

/* -------------------------------------------------------------------------
 * Felder-Baukasten aus geposteten Parallel-Arrays lesen
 * (Muster wie die Rechnungs-Positionen: f_label[], f_type[], …)
 * ---------------------------------------------------------------------- */

function vp_formular_felder_aus_post() {
	$labels    = (array) ( $_POST['f_label'] ?? array() );
	$types     = (array) ( $_POST['f_type'] ?? array() );
	$required  = (array) ( $_POST['f_required'] ?? array() );
	$options   = (array) ( $_POST['f_options'] ?? array() );
	$types_ok  = array_keys( vp_formular_feldtypen() );

	$felder     = array();
	$used_keys  = array();
	foreach ( $labels as $i => $label ) {
		$label = trim( sanitize_text_field( wp_unslash( $label ) ) );
		$type  = sanitize_key( wp_unslash( $types[ $i ] ?? 'text' ) );
		if ( '' === $label || ! in_array( $type, $types_ok, true ) ) {
			continue; // leere Baukasten-Zeile überspringen
		}
		$key  = sanitize_title( $label ) ?: ( 'feld' . ( $i + 1 ) );
		$base = $key;
		$n    = 2;
		while ( in_array( $key, $used_keys, true ) ) {
			$key = $base . '-' . $n++;
		}
		$used_keys[] = $key;

		$opts = array();
		if ( in_array( $type, array( 'select', 'checkboxes' ), true ) ) {
			$raw = (string) wp_unslash( $options[ $i ] ?? '' );
			foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
				$line = trim( sanitize_text_field( $line ) );
				if ( '' !== $line ) {
					$opts[] = $line;
				}
			}
		}

		$felder[] = array(
			'key'      => $key,
			'label'    => $label,
			'type'     => $type,
			'required' => in_array( (string) ( $required[ $i ] ?? '' ), array( '1', 'on' ), true ) ? 1 : 0,
			'options'  => $opts,
		);
	}
	return $felder;
}

/* -------------------------------------------------------------------------
 * Öffentlicher Shortcode
 * ---------------------------------------------------------------------- */

add_shortcode( 'verein_formular', 'vp_shortcode_formular' );
function vp_shortcode_formular( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0, 'slug' => '' ), $atts );
	$f = $atts['id'] ? vp_formular_get( (int) $atts['id'] ) : vp_formular_get_by_slug( $atts['slug'] );

	if ( ! $f ) {
		return current_user_can( 'vp_manage_formulare' )
			? '<div class="vp-note vp-note-error">' . esc_html__( 'Formular nicht gefunden.', 'vereinsplugin' ) . '</div>'
			: '';
	}
	if ( 'entwurf' === $f->status && ! current_user_can( 'vp_manage_formulare' ) ) {
		return ''; // Entwurf: für die Öffentlichkeit unsichtbar.
	}
	if ( $f->login_pflicht && ! is_user_logged_in() ) {
		return '<div class="vp-card vp-note vp-note-warn">' . esc_html__( 'Für dieses Formular ist ein Login nötig.', 'vereinsplugin' )
			. ' <a class="vp-btn vp-btn-primary" href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Zum Login', 'vereinsplugin' ) . '</a></div>';
	}

	$done  = false;
	$error = '';
	if ( isset( $_POST['vp_formular_submit'] ) && (int) $_POST['vp_formular_submit'] === (int) $f->id ) {
		$result = vp_formular_handle_submit( $f );
		if ( is_wp_error( $result ) ) {
			$error = $result->get_error_message();
		} else {
			$done = true;
		}
	}

	ob_start();
	echo '<div class="vp-formular vp-card">';

	if ( $done ) {
		echo '<h2>' . esc_html( $f->titel ) . '</h2>';
		echo '<div class="vp-note">' . wp_kses_post( wpautop( $f->dank_text ?: __( 'Danke, deine Anmeldung ist eingegangen.', 'vereinsplugin' ) ) ) . '</div>';
		echo '</div>';
		return ob_get_clean();
	}

	echo '<h2>' . esc_html( $f->titel ) . '</h2>';
	if ( $f->beschreibung ) {
		echo '<div class="vp-muted">' . wp_kses_post( wpautop( $f->beschreibung ) ) . '</div>';
	}

	if ( $f->max_teilnehmer ) {
		$frei = max( 0, (int) $f->max_teilnehmer - vp_formular_anzahl_eintraege( $f->id ) );
		echo '<p class="vp-muted">' . esc_html( sprintf(
			/* translators: 1: free slots, 2: total slots */
			__( 'Noch %1$d von %2$d Plätzen frei.', 'vereinsplugin' ),
			$frei,
			(int) $f->max_teilnehmer
		) ) . '</p>';
	}

	if ( ! vp_formular_ist_offen( $f ) ) {
		echo '<div class="vp-note vp-note-warn">' . esc_html__( 'Die Anmeldung ist geschlossen.', 'vereinsplugin' ) . '</div></div>';
		return ob_get_clean();
	}

	if ( $error ) {
		echo '<div class="vp-note vp-note-error">' . esc_html( $error ) . '</div>';
	}

	$u = is_user_logged_in() ? wp_get_current_user() : null;
	$v = function ( $key ) { return isset( $_POST['f'][ $key ] ) ? wp_unslash( $_POST['f'][ $key ] ) : ''; };

	echo '<form method="post" class="vp-form" novalidate>';
	wp_nonce_field( 'vp_formular_' . $f->id, 'vp_formular_nonce' );
	echo '<input type="hidden" name="vp_formular_submit" value="' . (int) $f->id . '">';
	echo '<input type="text" name="vp_hp" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">';

	echo '<div class="vp-form-grid">';
	foreach ( $f->felder as $feld ) {
		vp_formular_render_feld( $feld, $v, $u );
	}
	echo '</div>';

	echo '<button type="submit" class="vp-btn vp-btn-primary">' . esc_html__( 'Absenden', 'vereinsplugin' ) . '</button>';
	echo '</form></div>';
	return ob_get_clean();
}

function vp_formular_render_feld( $feld, $v, $u ) {
	$key   = $feld['key'];
	$name  = 'f[' . $key . ']';
	$id    = 'vp-f-' . $key;
	$req   = ! empty( $feld['required'] );
	$mark  = $req ? ' *' : '';

	if ( 'heading' === $feld['type'] ) {
		echo '<div class="vp-col-2"><h3>' . esc_html( $feld['label'] ) . '</h3></div>';
		return;
	}

	if ( 'checkbox' === $feld['type'] ) {
		printf(
			'<label class="vp-check vp-col-2">%s<input type="checkbox" id="%s" name="%s" value="1"%s> %s</label>',
			$req ? '* ' : '',
			esc_attr( $id ),
			esc_attr( $name ),
			checked( $v( $key ), '1', false ),
			esc_html( $feld['label'] )
		);
		return;
	}

	if ( 'checkboxes' === $feld['type'] ) {
		$selected = (array) ( $_POST['f'][ $key ] ?? array() );
		echo '<div class="vp-col-2"><label>' . esc_html( $feld['label'] ) . $mark . '</label>';
		foreach ( (array) $feld['options'] as $opt ) {
			printf(
				'<label class="vp-check"><input type="checkbox" name="%s[]" value="%s"%s> %s</label>',
				esc_attr( $name ),
				esc_attr( $opt ),
				checked( in_array( $opt, $selected, true ), true, false ),
				esc_html( $opt )
			);
		}
		echo '</div>';
		return;
	}

	if ( 'select' === $feld['type'] ) {
		echo '<label>' . esc_html( $feld['label'] ) . $mark;
		echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . ( $req ? ' required' : '' ) . '>';
		echo '<option value="">' . esc_html__( '– bitte wählen –', 'vereinsplugin' ) . '</option>';
		foreach ( (array) $feld['options'] as $opt ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $opt ), selected( $v( $key ), $opt, false ), esc_html( $opt ) );
		}
		echo '</select></label>';
		return;
	}

	if ( 'textarea' === $feld['type'] ) {
		printf(
			'<label class="vp-col-2">%s%s<textarea id="%s" name="%s" rows="3"%s>%s</textarea></label>',
			esc_html( $feld['label'] ),
			esc_html( $mark ),
			esc_attr( $id ),
			esc_attr( $name ),
			$req ? ' required' : '',
			esc_textarea( $v( $key ) )
		);
		return;
	}

	// Vorbelegung bequem für eingeloggte Mitglieder.
	$prefill = $v( $key );
	if ( '' === $prefill && $u ) {
		if ( 'email' === $feld['type'] ) {
			$prefill = $u->user_email;
		} elseif ( false !== stripos( $feld['label'], 'name' ) ) {
			$prefill = $u->display_name;
		}
	}

	$type = in_array( $feld['type'], array( 'email', 'tel', 'number', 'date' ), true ) ? $feld['type'] : 'text';
	printf(
		'<label>%s%s<input type="%s" id="%s" name="%s" value="%s"%s></label>',
		esc_html( $feld['label'] ),
		esc_html( $mark ),
		esc_attr( $type ),
		esc_attr( $id ),
		esc_attr( $name ),
		esc_attr( $prefill ),
		$req ? ' required' : ''
	);
}

function vp_formular_handle_submit( $f ) {
	if ( ! isset( $_POST['vp_formular_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['vp_formular_nonce'] ), 'vp_formular_' . $f->id ) ) {
		return new WP_Error( 'nonce', __( 'Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden.', 'vereinsplugin' ) );
	}
	if ( ! empty( $_POST['vp_hp'] ) ) {
		return new WP_Error( 'spam', __( 'Übermittlung abgelehnt.', 'vereinsplugin' ) );
	}
	if ( ! vp_formular_ist_offen( $f ) ) {
		return new WP_Error( 'closed', __( 'Die Anmeldung ist inzwischen geschlossen.', 'vereinsplugin' ) );
	}

	$posted = (array) ( $_POST['f'] ?? array() );
	$daten  = array();
	$name   = '';
	$email  = '';

	foreach ( $f->felder as $feld ) {
		if ( 'heading' === $feld['type'] ) {
			continue;
		}
		$key = $feld['key'];
		$raw = $posted[ $key ] ?? ( 'checkboxes' === $feld['type'] ? array() : '' );

		if ( 'checkboxes' === $feld['type'] ) {
			$val = array_map( 'sanitize_text_field', array_map( 'wp_unslash', (array) $raw ) );
		} elseif ( 'checkbox' === $feld['type'] ) {
			$val = ! empty( $raw ) ? 1 : 0;
		} elseif ( 'textarea' === $feld['type'] ) {
			$val = sanitize_textarea_field( wp_unslash( (string) $raw ) );
		} elseif ( 'email' === $feld['type'] ) {
			$val = sanitize_email( wp_unslash( (string) $raw ) );
		} else {
			$val = sanitize_text_field( wp_unslash( (string) $raw ) );
		}

		if ( ! empty( $feld['required'] ) && ( '' === $val || array() === $val ) ) {
			return new WP_Error( 'required', sprintf(
				/* translators: %s: field label */
				__( 'Bitte „%s“ ausfüllen.', 'vereinsplugin' ),
				$feld['label']
			) );
		}
		if ( 'email' === $feld['type'] && $val && ! is_email( $val ) ) {
			return new WP_Error( 'email', __( 'Bitte eine gültige E-Mail-Adresse angeben.', 'vereinsplugin' ) );
		}

		$daten[ $key ] = $val;
		if ( '' === $email && 'email' === $feld['type'] ) {
			$email = $val;
		}
		if ( '' === $name && 'text' === $feld['type'] && is_string( $val ) && $val ) {
			$name = $val;
		}
	}

	global $wpdb;
	$wpdb->insert( vp_formular_eintraege_table(), array(
		'formular_id'    => $f->id,
		'daten'          => wp_json_encode( $daten ),
		'anzeige_name'   => $name,
		'anzeige_email'  => $email,
		'user_id'        => get_current_user_id() ?: null,
		'ip_hash'        => hash( 'sha256', ( $_SERVER['REMOTE_ADDR'] ?? '' ) . wp_salt() ),
		'eingereicht_am' => current_time( 'mysql' ),
	) );

	vp_formular_notify( $f, $name, $email, $daten );
	return true;
}

function vp_formular_notify( $f, $name, $email, $daten ) {
	$to = $f->benachrichtigung_email ?: ( function_exists( 'vp_board_email' ) ? vp_board_email() : get_option( 'admin_email' ) );
	$lines = array( sprintf( __( 'Neue Anmeldung für „%s“:', 'vereinsplugin' ), $f->titel ), '' );
	foreach ( $f->felder as $feld ) {
		if ( 'heading' === $feld['type'] || ! isset( $daten[ $feld['key'] ] ) ) {
			continue;
		}
		$val = $daten[ $feld['key'] ];
		$lines[] = $feld['label'] . ': ' . ( is_array( $val ) ? implode( ', ', $val ) : ( $val ?: '–' ) );
	}
	wp_mail( $to, sprintf( '[%s] %s', get_bloginfo( 'name' ), sprintf( __( 'Neue Anmeldung: %s', 'vereinsplugin' ), $f->titel ) ), implode( "\n", $lines ) );

	if ( $email ) {
		wp_mail( $email, sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'Deine Anmeldung ist eingegangen', 'vereinsplugin' ) ),
			sprintf( __( "Hallo%s,\n\ndeine Anmeldung für „%s“ ist eingegangen.\n\nViele Grüße", 'vereinsplugin' ), $name ? ' ' . $name : '', $f->titel )
		);
	}
}

/* -------------------------------------------------------------------------
 * Vorstands-Sektion: bauen, veröffentlichen, auswerten
 * ---------------------------------------------------------------------- */

add_action( 'init', 'vp_formular_maybe_csv_export' );
function vp_formular_maybe_csv_export() {
	if ( empty( $_GET['vp_formular_csv'] ) || ! current_user_can( 'vp_manage_formulare' ) ) {
		return;
	}
	$id = (int) $_GET['vp_formular_csv'];
	if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'vp_formular_csv_' . $id ) ) {
		return;
	}
	$f = vp_formular_get( $id );
	if ( ! $f ) {
		return;
	}
	$eintraege = vp_formular_eintraege( $id );

	nocache_headers();
	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $f->titel ) . '.csv"' );
	echo "\xEF\xBB\xBF"; // BOM, damit Excel Umlaute richtig zeigt.
	$out = fopen( 'php://output', 'w' );

	$labels = array( __( 'Eingereicht am', 'vereinsplugin' ) );
	foreach ( $f->felder as $feld ) {
		if ( 'heading' !== $feld['type'] ) {
			$labels[] = $feld['label'];
		}
	}
	fputcsv( $out, $labels, ';' );

	foreach ( $eintraege as $e ) {
		$row = array( $e->eingereicht_am );
		foreach ( $f->felder as $feld ) {
			if ( 'heading' === $feld['type'] ) {
				continue;
			}
			$val = $e->daten[ $feld['key'] ] ?? '';
			$row[] = is_array( $val ) ? implode( ', ', $val ) : $val;
		}
		fputcsv( $out, $row, ';' );
	}
	fclose( $out );
	exit;
}

function vp_render_formulare_section() {
	if ( ! current_user_can( 'vp_manage_formulare' ) ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) . '</div>';
	}
	$msg = '';

	if ( isset( $_POST['vp_formular_save'] ) && check_admin_referer( 'vp_formular_edit', 'vp_fe_nonce' ) ) {
		$id = vp_formular_save_from_post();
		$msg = __( 'Formular gespeichert.', 'vereinsplugin' );
		$_GET['vp_formular_id'] = $id; // gleich wieder zum Bearbeiten anzeigen
	}
	if ( isset( $_POST['vp_formular_delete'] ) && check_admin_referer( 'vp_formular_edit', 'vp_fe_nonce' ) ) {
		global $wpdb;
		$id = (int) $_POST['id'];
		$wpdb->delete( vp_formular_eintraege_table(), array( 'formular_id' => $id ) );
		$wpdb->delete( vp_formular_table(), array( 'id' => $id ) );
		$msg = __( 'Formular gelöscht.', 'vereinsplugin' );
	}
	if ( isset( $_POST['vp_eintrag_delete'] ) && check_admin_referer( 'vp_formular_edit', 'vp_fe_nonce' ) ) {
		global $wpdb;
		$wpdb->delete( vp_formular_eintraege_table(), array( 'id' => (int) $_POST['eintrag_id'] ) );
		$msg = __( 'Eintrag gelöscht.', 'vereinsplugin' );
	}

	$view = isset( $_GET['vp_fview'] ) ? sanitize_key( wp_unslash( $_GET['vp_fview'] ) ) : 'liste';
	$fid  = isset( $_GET['vp_formular_id'] ) ? (int) $_GET['vp_formular_id'] : 0;

	ob_start();
	echo '<h2>' . esc_html__( 'Formulare', 'vereinsplugin' ) . '</h2>';
	if ( $msg ) {
		echo '<div class="vp-note">' . esc_html( $msg ) . '</div>';
	}

	if ( 'eintraege' === $view && $fid ) {
		echo vp_formular_render_eintraege( $fid ); // phpcs:ignore
	} elseif ( 'bearbeiten' === $view || $fid ) {
		echo vp_formular_render_editor( $fid ); // phpcs:ignore
	} else {
		echo vp_formular_render_liste(); // phpcs:ignore
	}
	return ob_get_clean();
}

function vp_formular_save_from_post() {
	$id     = (int) ( $_POST['id'] ?? 0 );
	$titel  = sanitize_text_field( wp_unslash( $_POST['titel'] ?? '' ) );
	$status = in_array( $_POST['status'] ?? '', array( 'entwurf', 'veroeffentlicht', 'geschlossen' ), true ) ? $_POST['status'] : 'entwurf';

	global $wpdb;
	$slug = sanitize_title( $titel ) ?: 'formular';
	$base = $slug;
	$n    = 2;
	while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . vp_formular_table() . ' WHERE slug = %s AND id != %d', $slug, $id ) ) ) {
		$slug = $base . '-' . $n++;
	}

	$row = array(
		'titel'                  => $titel,
		'slug'                   => $slug,
		'beschreibung'           => sanitize_textarea_field( wp_unslash( $_POST['beschreibung'] ?? '' ) ),
		'felder'                 => wp_json_encode( vp_formular_felder_aus_post() ),
		'status'                 => $status,
		'max_teilnehmer'         => ( '' !== trim( (string) ( $_POST['max_teilnehmer'] ?? '' ) ) ) ? (int) $_POST['max_teilnehmer'] : null,
		'login_pflicht'          => empty( $_POST['login_pflicht'] ) ? 0 : 1,
		'schliesst_am'           => ! empty( $_POST['schliesst_am'] ) ? sanitize_text_field( wp_unslash( $_POST['schliesst_am'] ) ) . ' 23:59:59' : null,
		'dank_text'              => sanitize_textarea_field( wp_unslash( $_POST['dank_text'] ?? '' ) ),
		'benachrichtigung_email' => sanitize_email( wp_unslash( $_POST['benachrichtigung_email'] ?? '' ) ),
		'aktualisiert_am'        => current_time( 'mysql' ),
	);

	if ( $id ) {
		$wpdb->update( vp_formular_table(), $row, array( 'id' => $id ) );
	} else {
		$row['erstellt_von'] = get_current_user_id();
		$row['erstellt_am']  = current_time( 'mysql' );
		$wpdb->insert( vp_formular_table(), $row );
		$id = (int) $wpdb->insert_id;
	}
	return $id;
}

function vp_formular_render_liste() {
	$base = get_permalink() ?: remove_query_arg( array( 'vp_fview', 'vp_formular_id' ) );
	$formulare = vp_formular_alle();

	ob_start();
	echo '<p><a class="vp-btn vp-btn-primary" href="' . esc_url( add_query_arg( array( 'vp_tab' => 'formulare', 'vp_fview' => 'bearbeiten' ), $base ) ) . '">' . esc_html__( '+ Neues Formular', 'vereinsplugin' ) . '</a></p>';

	if ( ! $formulare ) {
		echo '<p class="vp-muted">' . esc_html__( 'Noch keine Formulare.', 'vereinsplugin' ) . '</p>';
		return ob_get_clean();
	}

	$status_label = array(
		'entwurf'         => __( 'Entwurf', 'vereinsplugin' ),
		'veroeffentlicht' => __( 'Veröffentlicht', 'vereinsplugin' ),
		'geschlossen'     => __( 'Geschlossen', 'vereinsplugin' ),
	);

	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr>'
		. '<th>' . esc_html__( 'Titel', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Status', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Einträge', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Shortcode', 'vereinsplugin' ) . '</th><th></th></tr></thead><tbody>';
	foreach ( $formulare as $f ) {
		$n = vp_formular_anzahl_eintraege( $f->id );
		printf(
			'<tr><td><strong>%s</strong></td><td><span class="vp-badge">%s</span></td><td>%s</td><td><code>[verein_formular id="%d"]</code></td>'
			. '<td><a class="vp-btn" href="%s">%s</a> <a class="vp-btn" href="%s">%s</a></td></tr>',
			esc_html( $f->titel ),
			esc_html( $status_label[ $f->status ] ?? $f->status ),
			$n . ( $f->max_teilnehmer ? ' / ' . (int) $f->max_teilnehmer : '' ),
			(int) $f->id,
			esc_url( add_query_arg( array( 'vp_tab' => 'formulare', 'vp_fview' => 'bearbeiten', 'vp_formular_id' => $f->id ), $base ) ),
			esc_html__( 'Bearbeiten', 'vereinsplugin' ),
			esc_url( add_query_arg( array( 'vp_tab' => 'formulare', 'vp_fview' => 'eintraege', 'vp_formular_id' => $f->id ), $base ) ),
			esc_html__( 'Einträge', 'vereinsplugin' )
		);
	}
	echo '</tbody></table></div>';
	return ob_get_clean();
}

function vp_formular_render_editor( $id ) {
	$f    = $id ? vp_formular_get( $id ) : null;
	$base = get_permalink() ?: remove_query_arg( array( 'vp_fview', 'vp_formular_id' ) );
	$felder = $f ? $f->felder : array();

	ob_start();
	echo '<p><a class="vp-btn" href="' . esc_url( add_query_arg( 'vp_tab', 'formulare', $base ) ) . '">← ' . esc_html__( 'Zur Übersicht', 'vereinsplugin' ) . '</a></p>';
	?>
	<form method="post" class="vp-form vp-card">
		<?php wp_nonce_field( 'vp_formular_edit', 'vp_fe_nonce' ); ?>
		<input type="hidden" name="id" value="<?php echo (int) ( $f->id ?? 0 ); ?>">
		<div class="vp-form-grid">
			<label class="vp-col-2"><?php esc_html_e( 'Titel *', 'vereinsplugin' ); ?>
				<input type="text" name="titel" required value="<?php echo esc_attr( $f->titel ?? '' ); ?>"></label>
			<label class="vp-col-2"><?php esc_html_e( 'Beschreibung', 'vereinsplugin' ); ?>
				<textarea name="beschreibung" rows="2"><?php echo esc_textarea( $f->beschreibung ?? '' ); ?></textarea></label>
			<label><?php esc_html_e( 'Status', 'vereinsplugin' ); ?>
				<select name="status">
					<?php foreach ( array( 'entwurf' => __( 'Entwurf', 'vereinsplugin' ), 'veroeffentlicht' => __( 'Veröffentlicht', 'vereinsplugin' ), 'geschlossen' => __( 'Geschlossen', 'vereinsplugin' ) ) as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f->status ?? 'entwurf', $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select></label>
			<label><?php esc_html_e( 'Teilnehmerlimit', 'vereinsplugin' ); ?>
				<input type="number" min="1" name="max_teilnehmer" placeholder="<?php esc_attr_e( 'unbegrenzt', 'vereinsplugin' ); ?>" value="<?php echo esc_attr( $f->max_teilnehmer ?? '' ); ?>"></label>
			<label><?php esc_html_e( 'Anmeldeschluss', 'vereinsplugin' ); ?>
				<input type="date" name="schliesst_am" value="<?php echo esc_attr( $f && $f->schliesst_am ? substr( $f->schliesst_am, 0, 10 ) : '' ); ?>"></label>
			<label><?php esc_html_e( 'Login erforderlich', 'vereinsplugin' ); ?>
				<span class="vp-check"><input type="checkbox" name="login_pflicht" value="1" <?php checked( $f->login_pflicht ?? 0, 1 ); ?>> <?php esc_html_e( 'nur für eingeloggte Mitglieder', 'vereinsplugin' ); ?></span></label>
			<label><?php esc_html_e( 'Benachrichtigungs-E-Mail', 'vereinsplugin' ); ?>
				<input type="email" name="benachrichtigung_email" placeholder="<?php echo esc_attr( function_exists( 'vp_board_email' ) ? vp_board_email() : get_option( 'admin_email' ) ); ?>" value="<?php echo esc_attr( $f->benachrichtigung_email ?? '' ); ?>"></label>
			<label class="vp-col-2"><?php esc_html_e( 'Dank-Text nach dem Absenden', 'vereinsplugin' ); ?>
				<textarea name="dank_text" rows="2"><?php echo esc_textarea( $f->dank_text ?? '' ); ?></textarea></label>
		</div>

		<h3><?php esc_html_e( 'Felder', 'vereinsplugin' ); ?></h3>
		<p class="vp-muted"><?php esc_html_e( 'Leere Zeilen werden beim Speichern ignoriert. Optionen: eine pro Zeile (nur bei Auswahl/Mehrfachauswahl).', 'vereinsplugin' ); ?></p>
		<div class="vp-table-wrap"><table class="vp-table"><thead><tr>
			<th><?php esc_html_e( 'Beschriftung', 'vereinsplugin' ); ?></th>
			<th><?php esc_html_e( 'Typ', 'vereinsplugin' ); ?></th>
			<th><?php esc_html_e( 'Pflicht', 'vereinsplugin' ); ?></th>
			<th><?php esc_html_e( 'Optionen', 'vereinsplugin' ); ?></th>
		</tr></thead><tbody>
		<?php
		$zeile = function ( $feld = null ) {
			echo '<tr>';
			echo '<td><input type="text" name="f_label[]" style="width:100%" value="' . esc_attr( $feld['label'] ?? '' ) . '"></td>';
			echo '<td><select name="f_type[]">';
			foreach ( vp_formular_feldtypen() as $k => $l ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $feld['type'] ?? 'text', $k, false ), esc_html( $l ) );
			}
			echo '</select></td>';
			echo '<td style="text-align:center"><input type="checkbox" name="f_required[]" value="1"' . checked( ! empty( $feld['required'] ), true, false ) . '></td>';
			echo '<td><textarea name="f_options[]" rows="2" style="width:100%">' . esc_textarea( implode( "\n", (array) ( $feld['options'] ?? array() ) ) ) . '</textarea></td>';
			echo '</tr>';
		};
		foreach ( $felder as $feld ) {
			$zeile( $feld );
		}
		for ( $i = 0; $i < 6; $i++ ) {
			$zeile( null );
		}
		?>
		</tbody></table></div>

		<p><button class="vp-btn vp-btn-primary" name="vp_formular_save" value="1"><?php esc_html_e( 'Speichern', 'vereinsplugin' ); ?></button>
		<?php if ( $f ) : ?>
			<button class="vp-btn vp-btn-danger" name="vp_formular_delete" value="1" onclick="return confirm('<?php echo esc_js( __( 'Formular samt aller Einträge löschen?', 'vereinsplugin' ) ); ?>')"><?php esc_html_e( 'Löschen', 'vereinsplugin' ); ?></button>
		<?php endif; ?></p>
	</form>
	<?php
	return ob_get_clean();
}

function vp_formular_render_eintraege( $id ) {
	$f = vp_formular_get( $id );
	if ( ! $f ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Formular nicht gefunden.', 'vereinsplugin' ) . '</div>';
	}
	$base = get_permalink() ?: remove_query_arg( array( 'vp_fview', 'vp_formular_id' ) );
	$eintraege = vp_formular_eintraege( $id );

	ob_start();
	echo '<p><a class="vp-btn" href="' . esc_url( add_query_arg( 'vp_tab', 'formulare', $base ) ) . '">← ' . esc_html__( 'Zur Übersicht', 'vereinsplugin' ) . '</a> ';
	printf(
		'<a class="vp-btn" href="%s">%s</a></p>',
		esc_url( wp_nonce_url( add_query_arg( 'vp_formular_csv', (int) $f->id, home_url( '/' ) ), 'vp_formular_csv_' . $f->id ) ),
		esc_html__( 'CSV exportieren', 'vereinsplugin' )
	);
	echo '<h3>' . esc_html( $f->titel ) . ' – ' . esc_html( sprintf( _n( '%d Eintrag', '%d Einträge', count( $eintraege ), 'vereinsplugin' ), count( $eintraege ) ) ) . '</h3>';

	if ( ! $eintraege ) {
		echo '<p class="vp-muted">' . esc_html__( 'Noch keine Einträge.', 'vereinsplugin' ) . '</p>';
		return ob_get_clean();
	}

	$labels = array();
	foreach ( $f->felder as $feld ) {
		if ( 'heading' !== $feld['type'] ) {
			$labels[ $feld['key'] ] = $feld['label'];
		}
	}

	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Eingereicht', 'vereinsplugin' ) . '</th>';
	foreach ( $labels as $l ) {
		echo '<th>' . esc_html( $l ) . '</th>';
	}
	echo '<th></th></tr></thead><tbody>';
	foreach ( $eintraege as $e ) {
		echo '<tr><td>' . esc_html( mysql2date( 'd.m.Y H:i', $e->eingereicht_am ) ) . '</td>';
		foreach ( $labels as $key => $l ) {
			$val = $e->daten[ $key ] ?? '';
			echo '<td>' . esc_html( is_array( $val ) ? implode( ', ', $val ) : ( $val ?: '–' ) ) . '</td>';
		}
		echo '<td><form method="post" onsubmit="return confirm(\'' . esc_js( __( 'Eintrag löschen?', 'vereinsplugin' ) ) . '\')">'
			. wp_nonce_field( 'vp_formular_edit', 'vp_fe_nonce', true, false )
			. '<input type="hidden" name="eintrag_id" value="' . (int) $e->id . '">'
			. '<button class="vp-btn vp-btn-danger" name="vp_eintrag_delete" value="1">✕</button></form></td></tr>';
	}
	echo '</tbody></table></div>';
	return ob_get_clean();
}
