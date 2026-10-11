<?php
/**
 * Sponsoring-Liste und Mailingliste.
 *
 * Projekte & Veranstaltungen → „Sponsoring & Mailingliste“:
 *   - Kontakte (Unternehmen, Ansprechperson, E-Mail, Telefon, Notiz,
 *     „auf der Mailingliste“) – vereinsweit, für alle Projekte nutzbar.
 *   - Anfragen je Projekt mit Status (ansprechen, angefragt, zugesagt,
 *     erhalten, abgesagt), Betrag und Sachleistung. Zugesagtes Sponsoring
 *     zählt in der Projekt-Kalkulation als „sicher“.
 *   - Mailingliste: Empfänger filtern (alle auf der Liste / je Projekt und
 *     Status), Adressen kopieren, BCC-Mail im eigenen Programm öffnen oder
 *     eine Rundmail (je Empfänger einzeln, mit {firma} und {name}) senden.
 *
 * Projekt-Reiter „Sponsoring“: Anfragen des Projekts.
 * Sehen und bearbeiten: wer Kreise & Projekte verwalten darf (pp_manage) –
 * es sind Kontaktdaten Dritter.
 */

defined( 'ABSPATH' ) || exit;

define( 'VP_SPONSORING_DB_VERSION', '1' );

function vp_sponsoren_table() { global $wpdb; return $wpdb->prefix . 'vp_sponsoren'; }
function vp_sponsor_anfragen_table() { global $wpdb; return $wpdb->prefix . 'vp_sponsor_anfragen'; }

add_action( 'plugins_loaded', 'vp_sponsoring_maybe_upgrade', 7 );
function vp_sponsoring_maybe_upgrade() {
	if ( get_option( 'vp_sponsoring_db_version' ) === VP_SPONSORING_DB_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$collate = $wpdb->get_charset_collate();
	dbDelta( 'CREATE TABLE ' . vp_sponsoren_table() . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		firma VARCHAR(190) NOT NULL DEFAULT '',
		ansprechperson VARCHAR(150) NOT NULL DEFAULT '',
		email VARCHAR(190) NOT NULL DEFAULT '',
		telefon VARCHAR(60) NOT NULL DEFAULT '',
		branche VARCHAR(120) NOT NULL DEFAULT '',
		adresse TEXT NULL,
		notiz TEXT NULL,
		mailing TINYINT(1) NOT NULL DEFAULT 1,
		aktiv TINYINT(1) NOT NULL DEFAULT 1,
		erstellt_von BIGINT UNSIGNED DEFAULT NULL,
		erstellt_am DATETIME NULL,
		PRIMARY KEY  (id),
		KEY firma (firma)
	) {$collate};" );
	dbDelta( 'CREATE TABLE ' . vp_sponsor_anfragen_table() . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		sponsor_id BIGINT UNSIGNED NOT NULL,
		projekt_id BIGINT UNSIGNED DEFAULT NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'idee',
		betrag DECIMAL(10,2) DEFAULT NULL,
		leistung VARCHAR(190) NOT NULL DEFAULT '',
		notiz TEXT NULL,
		datum DATE NULL,
		erstellt_am DATETIME NULL,
		PRIMARY KEY  (id),
		KEY sponsor_id (sponsor_id),
		KEY projekt_id (projekt_id)
	) {$collate};" );
	update_option( 'vp_sponsoring_db_version', VP_SPONSORING_DB_VERSION );
}

function vp_sponsoring_status() {
	return array(
		'idee'      => __( 'ansprechen', 'vereinsplugin' ),
		'angefragt' => __( 'angefragt', 'vereinsplugin' ),
		'zugesagt'  => __( 'zugesagt', 'vereinsplugin' ),
		'erhalten'  => __( 'erhalten', 'vereinsplugin' ),
		'abgesagt'  => __( 'abgesagt', 'vereinsplugin' ),
	);
}

function vp_sponsoring_status_farbe( $s ) {
	return array( 'idee' => '#f1f5f9', 'angefragt' => '#fef3c7', 'zugesagt' => '#dcfce7', 'erhalten' => '#bbf7d0', 'abgesagt' => '#fee2e2' )[ $s ] ?? '#f1f5f9';
}

function vp_sponsoring_darf() {
	return is_user_logged_in() && function_exists( 'pp_can_manage' ) && pp_can_manage();
}

/* -------------------------------------------------------------------------
 * Daten
 * ---------------------------------------------------------------------- */

function vp_sponsoren_liste( $nur_aktiv = true ) {
	global $wpdb;
	return (array) $wpdb->get_results( 'SELECT * FROM ' . vp_sponsoren_table() . ( $nur_aktiv ? ' WHERE aktiv = 1' : '' ) . ' ORDER BY firma' );
}

function vp_sponsor_get( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vp_sponsoren_table() . ' WHERE id = %d', (int) $id ) );
}

/** Anfragen, optional je Projekt oder Sponsor (mit Firma und Projekttitel). */
function vp_sponsor_anfragen( $projekt_id = 0, $sponsor_id = 0 ) {
	global $wpdb;
	$w = array( '1=1' );
	if ( $projekt_id ) {
		$w[] = $wpdb->prepare( 'a.projekt_id = %d', (int) $projekt_id );
	}
	if ( $sponsor_id ) {
		$w[] = $wpdb->prepare( 'a.sponsor_id = %d', (int) $sponsor_id );
	}
	$pt = function_exists( 'vp_projekt_table' ) ? vp_projekt_table() : $wpdb->prefix . 'vp_projekte';
	return (array) $wpdb->get_results(
		'SELECT a.*, s.firma, s.ansprechperson, s.email, p.titel AS projekt FROM ' . vp_sponsor_anfragen_table() . ' a
		 JOIN ' . vp_sponsoren_table() . ' s ON s.id = a.sponsor_id
		 LEFT JOIN ' . $pt . ' p ON p.id = a.projekt_id
		 WHERE ' . implode( ' AND ', $w ) . ' ORDER BY a.datum IS NULL, a.datum DESC, a.id DESC'
	);
}

/** Summe der Anfragen eines Projekts mit bestimmtem Status. */
function vp_sponsoring_projekt_summe( $projekt_id, array $status ) {
	global $wpdb;
	if ( ! $status || get_option( 'vp_sponsoring_db_version' ) !== VP_SPONSORING_DB_VERSION ) {
		return 0.0;
	}
	$in = implode( ',', array_map( static function ( $s ) use ( $wpdb ) {
		return $wpdb->prepare( '%s', $s );
	}, $status ) );
	return (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(betrag),0) FROM ' . vp_sponsor_anfragen_table() . " WHERE projekt_id = %d AND status IN ($in)", (int) $projekt_id ) );
}

/**
 * Empfänger der Mailingliste.
 * @param string $filter 'liste' = alle mit Haken „Mailingliste“; 'alle' = alle aktiven mit E-Mail;
 *                       'projekt' = Anfragen eines Projekts (optional Status)
 * @return array of objects (id, firma, ansprechperson, email)
 */
function vp_sponsoring_empfaenger( $filter, $projekt_id = 0, $status = '' ) {
	$out = array();
	if ( 'projekt' === $filter && $projekt_id ) {
		foreach ( vp_sponsor_anfragen( $projekt_id ) as $a ) {
			if ( ( '' === $status || $a->status === $status ) && is_email( $a->email ) ) {
				$out[ (int) $a->sponsor_id ] = (object) array( 'id' => (int) $a->sponsor_id, 'firma' => $a->firma, 'ansprechperson' => $a->ansprechperson, 'email' => $a->email );
			}
		}
		return array_values( $out );
	}
	foreach ( vp_sponsoren_liste() as $s ) {
		if ( is_email( $s->email ) && ( 'alle' === $filter || ! empty( $s->mailing ) ) ) {
			$out[] = $s;
		}
	}
	return $out;
}

/* -------------------------------------------------------------------------
 * Formular-Handler
 * ---------------------------------------------------------------------- */

function vp_sponsoring_check( $action ) {
	if ( ! vp_sponsoring_darf() ) {
		wp_die( esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) );
	}
	check_admin_referer( $action );
}

function vp_sponsoring_zurueck( $args = array() ) {
	vp_kreis_redirect( array_merge( array( 'pp_saved' => '1' ), $args ) );
}

add_action( 'admin_post_vp_sponsor_save', 'vp_sponsoring_handle_save' );
function vp_sponsoring_handle_save() {
	vp_sponsoring_check( 'vp_sponsor_save' );
	global $wpdb;
	$row = array(
		'firma'          => sanitize_text_field( wp_unslash( $_POST['firma'] ?? '' ) ),
		'ansprechperson' => sanitize_text_field( wp_unslash( $_POST['ansprechperson'] ?? '' ) ),
		'email'          => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
		'telefon'        => sanitize_text_field( wp_unslash( $_POST['telefon'] ?? '' ) ),
		'branche'        => sanitize_text_field( wp_unslash( $_POST['branche'] ?? '' ) ),
		'adresse'        => sanitize_textarea_field( wp_unslash( $_POST['adresse'] ?? '' ) ),
		'notiz'          => sanitize_textarea_field( wp_unslash( $_POST['notiz'] ?? '' ) ),
		'mailing'        => empty( $_POST['mailing'] ) ? 0 : 1,
	);
	if ( '' === $row['firma'] ) {
		vp_kreis_fehler( __( 'Bitte den Namen des Unternehmens angeben.', 'vereinsplugin' ) );
	}
	$id = (int) ( $_POST['sponsor_id'] ?? 0 );
	if ( $id ) {
		$wpdb->update( vp_sponsoren_table(), $row, array( 'id' => $id ) );
	} else {
		$row['erstellt_von'] = get_current_user_id();
		$row['erstellt_am']  = current_time( 'mysql' );
		$wpdb->insert( vp_sponsoren_table(), $row );
		$id = (int) $wpdb->insert_id;
	}
	// Direkt eine Anfrage für ein Projekt anlegen (aus dem Projekt-Reiter).
	$pid = (int) ( $_POST['projekt_id'] ?? 0 );
	if ( $pid && ! empty( $_POST['mit_anfrage'] ) ) {
		vp_sponsoring_anfrage_speichern( 0, $id, $pid );
	}
	vp_sponsoring_zurueck( array( 's_edit' => false ) );
}

add_action( 'admin_post_vp_sponsor_archiv', 'vp_sponsoring_handle_archiv' );
function vp_sponsoring_handle_archiv() {
	vp_sponsoring_check( 'vp_sponsor_archiv' );
	global $wpdb;
	$wpdb->update( vp_sponsoren_table(), array( 'aktiv' => empty( $_POST['aktiv'] ) ? 0 : 1 ), array( 'id' => (int) ( $_POST['sponsor_id'] ?? 0 ) ) );
	vp_sponsoring_zurueck();
}

/** Anfrage aus $_POST speichern (neu oder ändern). */
function vp_sponsoring_anfrage_speichern( $id, $sponsor_id, $projekt_id ) {
	global $wpdb;
	$status = sanitize_key( $_POST['status'] ?? 'idee' );
	$roh    = trim( sanitize_text_field( wp_unslash( $_POST['betrag'] ?? '' ) ) );
	$row    = array(
		'sponsor_id' => (int) $sponsor_id,
		'projekt_id' => (int) $projekt_id ?: null,
		'status'     => isset( vp_sponsoring_status()[ $status ] ) ? $status : 'idee',
		'betrag'     => '' === $roh ? null : abs( function_exists( 'vp_projekt_betrag_parsen' ) ? vp_projekt_betrag_parsen( $roh ) : (float) str_replace( ',', '.', $roh ) ),
		'leistung'   => sanitize_text_field( wp_unslash( $_POST['leistung'] ?? '' ) ),
		'notiz'      => sanitize_textarea_field( wp_unslash( $_POST['anfrage_notiz'] ?? '' ) ),
		'datum'      => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) ( $_POST['datum'] ?? '' ) ) ? $_POST['datum'] : current_time( 'Y-m-d' ),
	);
	if ( $id ) {
		$wpdb->update( vp_sponsor_anfragen_table(), $row, array( 'id' => (int) $id ) );
	} else {
		$row['erstellt_am'] = current_time( 'mysql' );
		$wpdb->insert( vp_sponsor_anfragen_table(), $row );
	}
}

add_action( 'admin_post_vp_sponsor_anfrage', 'vp_sponsoring_handle_anfrage' );
function vp_sponsoring_handle_anfrage() {
	vp_sponsoring_check( 'vp_sponsor_anfrage' );
	global $wpdb;
	$id  = (int) ( $_POST['anfrage_id'] ?? 0 );
	$sid = (int) ( $_POST['sponsor_id'] ?? 0 );
	if ( ! empty( $_POST['loeschen'] ) && $id ) {
		$wpdb->delete( vp_sponsor_anfragen_table(), array( 'id' => $id ) );
		vp_sponsoring_zurueck();
	}
	if ( ! $sid || ! vp_sponsor_get( $sid ) ) {
		vp_kreis_fehler( __( 'Bitte ein Unternehmen wählen.', 'vereinsplugin' ) );
	}
	vp_sponsoring_anfrage_speichern( $id, $sid, (int) ( $_POST['projekt_id'] ?? 0 ) );
	vp_sponsoring_zurueck( array( 's_anfrage' => false ) );
}

add_action( 'admin_post_vp_sponsor_rundmail', 'vp_sponsoring_handle_rundmail' );
function vp_sponsoring_handle_rundmail() {
	vp_sponsoring_check( 'vp_sponsor_rundmail' );
	$betreff = sanitize_text_field( wp_unslash( $_POST['betreff'] ?? '' ) );
	$text    = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
	$ids     = array_map( 'intval', (array) ( $_POST['an'] ?? array() ) );
	if ( '' === $betreff || '' === $text || ! $ids ) {
		vp_kreis_fehler( __( 'Betreff, Text und mindestens eine:n Empfänger:in angeben.', 'vereinsplugin' ) );
	}
	$antwort = sanitize_email( wp_unslash( $_POST['antwort_an'] ?? '' ) );
	$header  = array( 'Content-Type: text/plain; charset=UTF-8' );
	if ( is_email( $antwort ) ) {
		$header[] = 'Reply-To: ' . $antwort;
	}
	$ok = $fehl = 0;
	foreach ( array_slice( array_unique( $ids ), 0, 300 ) as $sid ) {
		$s = vp_sponsor_get( $sid );
		if ( ! $s || ! is_email( $s->email ) ) {
			continue;
		}
		$ersetzen = array(
			'{firma}' => $s->firma,
			'{name}'  => $s->ansprechperson ?: $s->firma,
		);
		if ( wp_mail( $s->email, strtr( $betreff, $ersetzen ), strtr( $text, $ersetzen ), $header ) ) {
			$ok++;
		} else {
			$fehl++;
		}
	}
	$log   = (array) get_option( 'vp_sponsoring_mail_log', array() );
	$log[] = array( 'zeit' => current_time( 'mysql' ), 'von' => get_current_user_id(), 'betreff' => $betreff, 'ok' => $ok, 'fehl' => $fehl );
	update_option( 'vp_sponsoring_mail_log', array_slice( $log, -30 ), false );
	$args = array( 's_gesendet' => $ok );
	if ( $fehl ) {
		$args['pp_error'] = rawurlencode( sprintf( __( '%d Mails konnten nicht verschickt werden.', 'vereinsplugin' ), $fehl ) );
	}
	vp_sponsoring_zurueck( $args );
}

/* -------------------------------------------------------------------------
 * Einbindung: Unterpunkt unter „Projekte & Veranstaltungen“
 * ---------------------------------------------------------------------- */

add_filter( 'pp_front_views', function ( $v ) {
	$v[] = 'sponsoring';
	return $v;
} );
add_filter( 'vp_pp_view_tabs', function ( $m ) {
	$m['sponsoring'] = 'projekte';
	return $m;
} );
add_filter( 'vp_pp_bereiche', function ( $b ) {
	if ( isset( $b['projekte'] ) && vp_sponsoring_darf() ) {
		$b['projekte']['punkte'] = array(
			'projekte'   => __( 'Projekte', 'vereinsplugin' ),
			'sponsoring' => __( 'Sponsoring & Mailingliste', 'vereinsplugin' ),
		);
	}
	return $b;
} );
add_action( 'pp_render_view_sponsoring', 'vp_sponsoring_render_view' );

/* -------------------------------------------------------------------------
 * Ansichten
 * ---------------------------------------------------------------------- */

function vp_sponsoring_status_badge( $s ) {
	return '<span class="pp-badge" style="background:' . esc_attr( vp_sponsoring_status_farbe( $s ) ) . '">' . esc_html( vp_sponsoring_status()[ $s ] ?? $s ) . '</span>';
}

function vp_sponsoring_sponsor_formular( $s = null, $projekt_id = 0 ) {
	vp_kreis_form( 'vp_sponsor_save', 'pp-form pp-form-grid' );
	?>
		<input type="hidden" name="sponsor_id" value="<?php echo (int) ( $s->id ?? 0 ); ?>">
		<?php if ( $projekt_id ) : ?><input type="hidden" name="projekt_id" value="<?php echo (int) $projekt_id; ?>"><input type="hidden" name="mit_anfrage" value="1"><?php endif; ?>
		<label><?php esc_html_e( 'Unternehmen *', 'vereinsplugin' ); ?><input type="text" name="firma" required value="<?php echo esc_attr( $s->firma ?? '' ); ?>"></label>
		<label><?php esc_html_e( 'Ansprechperson', 'vereinsplugin' ); ?><input type="text" name="ansprechperson" value="<?php echo esc_attr( $s->ansprechperson ?? '' ); ?>"></label>
		<label><?php esc_html_e( 'E-Mail', 'vereinsplugin' ); ?><input type="email" name="email" value="<?php echo esc_attr( $s->email ?? '' ); ?>"></label>
		<label><?php esc_html_e( 'Telefon', 'vereinsplugin' ); ?><input type="text" name="telefon" value="<?php echo esc_attr( $s->telefon ?? '' ); ?>"></label>
		<label><?php esc_html_e( 'Branche', 'vereinsplugin' ); ?><input type="text" name="branche" value="<?php echo esc_attr( $s->branche ?? '' ); ?>"></label>
		<label class="pp-checkbox-label"><span><input type="checkbox" name="mailing" value="1" <?php checked( ! $s || ! empty( $s->mailing ) ); ?>> <?php esc_html_e( 'auf der Mailingliste', 'vereinsplugin' ); ?></span></label>
		<label class="pp-span-2"><?php esc_html_e( 'Adresse', 'vereinsplugin' ); ?><textarea name="adresse" rows="2"><?php echo esc_textarea( $s->adresse ?? '' ); ?></textarea></label>
		<label class="pp-span-2"><?php esc_html_e( 'Notiz (z. B. wer kennt wen, frühere Unterstützung)', 'vereinsplugin' ); ?><textarea name="notiz" rows="2"><?php echo esc_textarea( $s->notiz ?? '' ); ?></textarea></label>
		<?php if ( $projekt_id ) : ?>
			<label><?php esc_html_e( 'Status der Anfrage', 'vereinsplugin' ); ?><select name="status"><?php foreach ( vp_sponsoring_status() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></label>
			<label><?php esc_html_e( 'Betrag (€)', 'vereinsplugin' ); ?><input type="text" inputmode="decimal" name="betrag"></label>
		<?php endif; ?>
		<div class="pp-form-actions"><button type="submit" class="pp-btn pp-btn-primary pp-btn-small"><?php echo $s ? esc_html__( 'Speichern', 'vereinsplugin' ) : esc_html__( 'Unternehmen anlegen', 'vereinsplugin' ); ?></button></div>
	</form>
	<?php
}

/** Formular für eine Anfrage (neu: Unternehmen und ggf. Projekt wählen). */
function vp_sponsoring_anfrage_formular( $a = null, $projekt_id = 0 ) {
	$projekte = function_exists( 'vp_projekte_liste' ) ? vp_projekte_liste( array() ) : array();
	vp_kreis_form( 'vp_sponsor_anfrage', 'pp-form pp-form-grid' );
	?>
		<input type="hidden" name="anfrage_id" value="<?php echo (int) ( $a->id ?? 0 ); ?>">
		<label><?php esc_html_e( 'Unternehmen', 'vereinsplugin' ); ?><select name="sponsor_id" required><option value=""><?php esc_html_e( '– wählen –', 'vereinsplugin' ); ?></option>
			<?php foreach ( vp_sponsoren_liste() as $s ) : ?><option value="<?php echo (int) $s->id; ?>" <?php selected( (int) ( $a->sponsor_id ?? 0 ), (int) $s->id ); ?>><?php echo esc_html( $s->firma ); ?></option><?php endforeach; ?>
		</select></label>
		<?php if ( $projekt_id ) : ?>
			<input type="hidden" name="projekt_id" value="<?php echo (int) $projekt_id; ?>">
		<?php else : ?>
			<label><?php esc_html_e( 'Projekt', 'vereinsplugin' ); ?><select name="projekt_id"><option value=""><?php esc_html_e( '– allgemein (ohne Projekt) –', 'vereinsplugin' ); ?></option>
				<?php foreach ( $projekte as $p ) : ?><option value="<?php echo (int) $p->id; ?>" <?php selected( (int) ( $a->projekt_id ?? 0 ), (int) $p->id ); ?>><?php echo esc_html( $p->titel ); ?></option><?php endforeach; ?>
			</select></label>
		<?php endif; ?>
		<label><?php esc_html_e( 'Status', 'vereinsplugin' ); ?><select name="status"><?php foreach ( vp_sponsoring_status() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $a->status ?? 'idee', $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></label>
		<label><?php esc_html_e( 'Betrag (€)', 'vereinsplugin' ); ?><input type="text" inputmode="decimal" name="betrag" value="<?php echo isset( $a->betrag ) && null !== $a->betrag ? esc_attr( number_format( (float) $a->betrag, 2, ',', '' ) ) : ''; ?>"></label>
		<label><?php esc_html_e( 'Sachleistung', 'vereinsplugin' ); ?><input type="text" name="leistung" value="<?php echo esc_attr( $a->leistung ?? '' ); ?>" placeholder="<?php esc_attr_e( 'z. B. Getränke, Druck', 'vereinsplugin' ); ?>"></label>
		<label><?php esc_html_e( 'Datum', 'vereinsplugin' ); ?><input type="date" name="datum" value="<?php echo esc_attr( $a->datum ?? current_time( 'Y-m-d' ) ); ?>"></label>
		<label class="pp-span-2"><?php esc_html_e( 'Notiz', 'vereinsplugin' ); ?><textarea name="anfrage_notiz" rows="2"><?php echo esc_textarea( $a->notiz ?? '' ); ?></textarea></label>
		<div class="pp-form-actions"><button type="submit" class="pp-btn pp-btn-primary pp-btn-small"><?php echo $a ? esc_html__( 'Speichern', 'vereinsplugin' ) : esc_html__( 'Anfrage anlegen', 'vereinsplugin' ); ?></button></div>
	</form>
	<?php
}

/** Tabelle der Anfragen (je Projekt oder alle). */
function vp_sponsoring_anfragen_tabelle( array $anfragen, $mit_projekt ) {
	$edit = isset( $_GET['s_anfrage'] ) ? (int) $_GET['s_anfrage'] : 0;
	if ( ! $anfragen ) {
		echo '<p class="pp-empty">' . esc_html__( 'Noch keine Anfragen.', 'vereinsplugin' ) . '</p>';
		return;
	}
	echo '<table class="pp-table"><thead><tr><th>' . esc_html__( 'Unternehmen', 'vereinsplugin' ) . '</th>' . ( $mit_projekt ? '<th>' . esc_html__( 'Projekt', 'vereinsplugin' ) . '</th>' : '' ) . '<th>' . esc_html__( 'Status', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Betrag', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Datum / Notiz', 'vereinsplugin' ) . '</th><th></th></tr></thead><tbody>';
	foreach ( $anfragen as $a ) {
		echo '<tr><td><strong>' . esc_html( $a->firma ) . '</strong>' . ( $a->ansprechperson ? '<div class="pp-meta">' . esc_html( $a->ansprechperson ) . '</div>' : '' ) . '</td>';
		if ( $mit_projekt ) {
			echo '<td>' . ( $a->projekt_id ? '<a href="' . esc_url( vp_projekt_url( $a->projekt_id, 'sponsoring' ) ) . '">' . esc_html( $a->projekt ?: '#' . $a->projekt_id ) . '</a>' : '<span class="pp-meta">' . esc_html__( 'allgemein', 'vereinsplugin' ) . '</span>' ) . '</td>';
		}
		echo '<td>' . vp_sponsoring_status_badge( $a->status ) . '</td>'; // phpcs:ignore
		echo '<td style="text-align:right">' . ( null !== $a->betrag ? esc_html( vp_kreis_eur( $a->betrag ) ) : '' ) . ( $a->leistung ? '<div class="pp-meta">' . esc_html( $a->leistung ) . '</div>' : '' ) . '</td>';
		echo '<td>' . esc_html( $a->datum ? mysql2date( 'd.m.Y', $a->datum ) : '' ) . ( $a->notiz ? '<div class="pp-meta">' . esc_html( $a->notiz ) . '</div>' : '' ) . '</td>';
		echo '<td class="pp-ablauf-aktionen"><a class="pp-meta" href="' . esc_url( add_query_arg( 's_anfrage', (int) $a->id ) ) . '#vp-anfrage-' . (int) $a->id . '">' . esc_html__( 'bearbeiten', 'vereinsplugin' ) . '</a> ';
		vp_kreis_form( 'vp_sponsor_anfrage', 'pp-inline' );
		echo '<input type="hidden" name="anfrage_id" value="' . (int) $a->id . '"><input type="hidden" name="loeschen" value="1"><button type="submit" class="pp-link-danger" onclick="return confirm(\'' . esc_js( __( 'Anfrage löschen?', 'vereinsplugin' ) ) . '\')">' . esc_html__( 'löschen', 'vereinsplugin' ) . '</button></form></td></tr>';
		if ( $edit === (int) $a->id ) {
			echo '<tr id="vp-anfrage-' . (int) $a->id . '"><td colspan="' . ( $mit_projekt ? 6 : 5 ) . '">';
			vp_sponsoring_anfrage_formular( $a, $mit_projekt ? 0 : (int) $a->projekt_id );
			echo '</td></tr>';
		}
	}
	echo '</tbody></table>';
}

/** Projekt-Reiter „Sponsoring“. */
function vp_sponsoring_projekt_tab( $p ) {
	if ( ! vp_sponsoring_darf() ) {
		echo '<p class="pp-empty">' . esc_html__( 'Die Sponsoring-Liste sehen nur Personen, die Kreise und Projekte verwalten.', 'vereinsplugin' ) . '</p>';
		return;
	}
	$anfragen = vp_sponsor_anfragen( (int) $p->id );
	$summen   = array();
	foreach ( vp_sponsoring_status() as $k => $l ) {
		$summen[ $k ] = 0.0;
	}
	foreach ( $anfragen as $a ) {
		$summen[ $a->status ] = ( $summen[ $a->status ] ?? 0 ) + (float) $a->betrag;
	}
	$plan = 0.0;
	if ( function_exists( 'vp_projekt_rechnung' ) ) {
		$plan = vp_projekt_rechnung( $p, vp_projekt_punkte( $p->id, 'kalkulation' ), vp_projekt_tn_basis( $p ) )['sponsoring_plan'];
	}
	?>
	<div class="pp-kpis">
		<div class="pp-kpi"><strong><?php echo esc_html( vp_kreis_eur( $plan ) ); ?></strong><span><?php esc_html_e( 'angenommen (Kalkulation)', 'vereinsplugin' ); ?></span></div>
		<div class="pp-kpi"><strong><?php echo esc_html( vp_kreis_eur( $summen['angefragt'] ) ); ?></strong><span><?php esc_html_e( 'angefragt', 'vereinsplugin' ); ?></span></div>
		<div class="pp-kpi"><strong><?php echo esc_html( vp_kreis_eur( $summen['zugesagt'] + $summen['erhalten'] ) ); ?></strong><span><?php esc_html_e( 'zugesagt / erhalten', 'vereinsplugin' ); ?></span></div>
		<div class="pp-kpi<?php echo ( $summen['zugesagt'] + $summen['erhalten'] ) < $plan ? ' is-neg' : ''; ?>"><strong><?php echo esc_html( vp_kreis_eur( max( 0, $plan - $summen['zugesagt'] - $summen['erhalten'] ) ) ); ?></strong><span><?php esc_html_e( 'fehlt noch', 'vereinsplugin' ); ?></span></div>
	</div>
	<p class="pp-meta"><?php esc_html_e( 'Die Annahme (z. B. „4 Unternehmen × 250 €“) legt ihr unter Finanzen als Posten der Art „Sponsoring“ an. Zugesagtes zählt dort als sicher.', 'vereinsplugin' ); ?></p>
	<h3><?php esc_html_e( 'Anfragen', 'vereinsplugin' ); ?></h3>
	<?php vp_sponsoring_anfragen_tabelle( $anfragen, false ); ?>
	<details class="pp-werkzeug"><summary class="pp-details-summary"><?php esc_html_e( '+ Unternehmen aus der Liste anfragen', 'vereinsplugin' ); ?></summary>
		<?php vp_sponsoring_anfrage_formular( null, (int) $p->id ); ?>
	</details>
	<details class="pp-werkzeug"><summary class="pp-details-summary"><?php esc_html_e( '+ Neues Unternehmen anlegen und anfragen', 'vereinsplugin' ); ?></summary>
		<?php vp_sponsoring_sponsor_formular( null, (int) $p->id ); ?>
	</details>
	<p><a class="pp-btn pp-btn-small" href="<?php echo esc_url( pp_front_url( array( 'pp_view' => 'sponsoring', 's_filter' => 'projekt', 's_projekt' => (int) $p->id ) ) . '#vp-mailing' ); ?>"><?php esc_html_e( 'Mail an die Unternehmen dieses Projekts', 'vereinsplugin' ); ?></a></p>
	<?php
}

/** Hauptansicht: Kontakte, Anfragen, Mailingliste. */
function vp_sponsoring_render_view() {
	if ( ! vp_sponsoring_darf() ) {
		echo '<p class="pp-empty">' . esc_html__( 'Die Sponsoring-Liste sehen nur Personen, die Kreise und Projekte verwalten.', 'vereinsplugin' ) . '</p>';
		return;
	}
	$edit     = isset( $_GET['s_edit'] ) ? (int) $_GET['s_edit'] : 0;
	$archiv   = ! empty( $_GET['s_archiv'] );
	$alle     = vp_sponsoren_liste( false );
	$anfragen = vp_sponsor_anfragen();
	$je       = array();
	foreach ( $anfragen as $a ) {
		$je[ (int) $a->sponsor_id ][] = $a;
	}
	if ( isset( $_GET['s_gesendet'] ) ) {
		echo '<div class="pp-front-notice pp-front-notice-success">' . esc_html( sprintf( __( 'Rundmail an %d Empfänger:innen verschickt.', 'vereinsplugin' ), (int) $_GET['s_gesendet'] ) ) . '</div>';
	}
	?>
	<div class="pp-page-head">
		<h2><?php esc_html_e( 'Sponsoring & Mailingliste', 'vereinsplugin' ); ?></h2>
		<a class="pp-btn pp-btn-primary" href="#vp-sponsor-neu"><?php esc_html_e( 'Unternehmen anlegen', 'vereinsplugin' ); ?></a>
	</div>
	<p class="pp-meta"><?php esc_html_e( 'Unternehmen und Unterstützer:innen für alle Projekte. Anfragen je Projekt mit Status – Zugesagtes zählt in der Kalkulation des Projekts als sicher.', 'vereinsplugin' ); ?></p>

	<h3><?php esc_html_e( 'Unternehmen', 'vereinsplugin' ); ?></h3>
	<table class="pp-table"><thead><tr><th><?php esc_html_e( 'Unternehmen', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Kontakt', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Anfragen', 'vereinsplugin' ); ?></th><th></th></tr></thead><tbody>
	<?php foreach ( $alle as $s ) :
		if ( ! $s->aktiv && ! $archiv ) {
			continue;
		}
		$liste = $je[ (int) $s->id ] ?? array();
		$summe = 0.0;
		foreach ( $liste as $a ) {
			$summe += in_array( $a->status, array( 'zugesagt', 'erhalten' ), true ) ? (float) $a->betrag : 0;
		}
		?>
		<tr<?php echo $s->aktiv ? '' : ' style="opacity:.55"'; ?>>
			<td><strong><?php echo esc_html( $s->firma ); ?></strong><?php echo $s->branche ? '<div class="pp-meta">' . esc_html( $s->branche ) . '</div>' : ''; ?><?php echo $s->mailing ? ' <span class="pp-badge">✉ ' . esc_html__( 'Mailingliste', 'vereinsplugin' ) . '</span>' : ''; ?></td>
			<td><?php echo esc_html( $s->ansprechperson ); ?><?php if ( $s->email ) : ?><div class="pp-meta"><a href="mailto:<?php echo esc_attr( $s->email ); ?>"><?php echo esc_html( $s->email ); ?></a></div><?php endif; ?><?php echo $s->telefon ? '<div class="pp-meta">' . esc_html( $s->telefon ) . '</div>' : ''; ?></td>
			<td><?php foreach ( array_slice( $liste, 0, 4 ) as $a ) : ?><div><?php echo vp_sponsoring_status_badge( $a->status ); // phpcs:ignore ?> <span class="pp-meta"><?php echo esc_html( ( $a->projekt ?: __( 'allgemein', 'vereinsplugin' ) ) . ( null !== $a->betrag ? ' · ' . vp_kreis_eur( $a->betrag ) : '' ) ); ?></span></div><?php endforeach; ?>
				<?php echo $summe ? '<div class="pp-meta">' . esc_html( sprintf( __( 'zugesagt gesamt: %s', 'vereinsplugin' ), vp_kreis_eur( $summe ) ) ) . '</div>' : ''; ?></td>
			<td class="pp-ablauf-aktionen">
				<a class="pp-meta" href="<?php echo esc_url( add_query_arg( 's_edit', (int) $s->id ) . '#vp-sponsor-' . (int) $s->id ); ?>"><?php esc_html_e( 'bearbeiten', 'vereinsplugin' ); ?></a>
				<?php vp_kreis_form( 'vp_sponsor_archiv', 'pp-inline' ); ?><input type="hidden" name="sponsor_id" value="<?php echo (int) $s->id; ?>"><input type="hidden" name="aktiv" value="<?php echo $s->aktiv ? 0 : 1; ?>"><button type="submit" class="pp-link-danger"><?php echo $s->aktiv ? esc_html__( 'archivieren', 'vereinsplugin' ) : esc_html__( 'reaktivieren', 'vereinsplugin' ); ?></button></form>
			</td>
		</tr>
		<?php if ( $edit === (int) $s->id ) : ?>
			<tr id="vp-sponsor-<?php echo (int) $s->id; ?>"><td colspan="4"><?php vp_sponsoring_sponsor_formular( $s ); ?></td></tr>
		<?php endif; ?>
	<?php endforeach; ?>
	<?php if ( ! $alle ) : ?><tr><td colspan="4" class="pp-empty"><?php esc_html_e( 'Noch keine Unternehmen.', 'vereinsplugin' ); ?></td></tr><?php endif; ?>
	</tbody></table>
	<p class="pp-meta"><a href="<?php echo esc_url( add_query_arg( 's_archiv', $archiv ? false : 1 ) ); ?>"><?php echo $archiv ? esc_html__( 'Archivierte ausblenden', 'vereinsplugin' ) : esc_html__( 'Archivierte zeigen', 'vereinsplugin' ); ?></a></p>

	<details class="pp-werkzeug" id="vp-sponsor-neu"><summary class="pp-details-summary"><?php esc_html_e( '+ Unternehmen anlegen', 'vereinsplugin' ); ?></summary>
		<?php vp_sponsoring_sponsor_formular(); ?>
	</details>

	<h3><?php esc_html_e( 'Anfragen', 'vereinsplugin' ); ?></h3>
	<?php vp_sponsoring_anfragen_tabelle( $anfragen, true ); ?>
	<details class="pp-werkzeug"><summary class="pp-details-summary"><?php esc_html_e( '+ Anfrage', 'vereinsplugin' ); ?></summary>
		<?php vp_sponsoring_anfrage_formular(); ?>
	</details>

	<?php vp_sponsoring_mailing_bereich(); ?>
	<?php
}

/** Mailingliste: Empfänger filtern, Adressen kopieren, BCC, Rundmail. */
function vp_sponsoring_mailing_bereich() {
	$filter  = isset( $_GET['s_filter'] ) ? sanitize_key( wp_unslash( $_GET['s_filter'] ) ) : 'liste';
	$pid     = isset( $_GET['s_projekt'] ) ? (int) $_GET['s_projekt'] : 0;
	$status  = isset( $_GET['s_status'] ) ? sanitize_key( wp_unslash( $_GET['s_status'] ) ) : '';
	$empf    = vp_sponsoring_empfaenger( $filter, $pid, isset( vp_sponsoring_status()[ $status ] ) ? $status : '' );
	$mails   = array_map( static function ( $e ) {
		return $e->email;
	}, $empf );
	$projekte = function_exists( 'vp_projekte_liste' ) ? vp_projekte_liste( array() ) : array();
	$ich      = wp_get_current_user();
	?>
	<h3 id="vp-mailing"><?php esc_html_e( 'Mailingliste', 'vereinsplugin' ); ?></h3>
	<form method="get" class="pp-filterleiste" action="#vp-mailing">
		<?php foreach ( array( 'vp_tab', 'page_id', 'p' ) as $keep ) : ?>
			<?php if ( isset( $_GET[ $keep ] ) ) : ?><input type="hidden" name="<?php echo esc_attr( $keep ); ?>" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET[ $keep ] ) ) ); ?>"><?php endif; ?>
		<?php endforeach; ?>
		<input type="hidden" name="pp_view" value="sponsoring">
		<label><?php esc_html_e( 'Empfänger', 'vereinsplugin' ); ?><select name="s_filter">
			<option value="liste" <?php selected( $filter, 'liste' ); ?>><?php esc_html_e( 'alle auf der Mailingliste', 'vereinsplugin' ); ?></option>
			<option value="alle" <?php selected( $filter, 'alle' ); ?>><?php esc_html_e( 'alle Unternehmen mit E-Mail', 'vereinsplugin' ); ?></option>
			<option value="projekt" <?php selected( $filter, 'projekt' ); ?>><?php esc_html_e( 'Anfragen eines Projekts', 'vereinsplugin' ); ?></option>
		</select></label>
		<label><?php esc_html_e( 'Projekt', 'vereinsplugin' ); ?><select name="s_projekt"><option value="0">–</option>
			<?php foreach ( $projekte as $p ) : ?><option value="<?php echo (int) $p->id; ?>" <?php selected( $pid, (int) $p->id ); ?>><?php echo esc_html( $p->titel ); ?></option><?php endforeach; ?>
		</select></label>
		<label><?php esc_html_e( 'Status', 'vereinsplugin' ); ?><select name="s_status"><option value=""><?php esc_html_e( 'alle', 'vereinsplugin' ); ?></option>
			<?php foreach ( vp_sponsoring_status() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $status, $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?>
		</select></label>
		<button type="submit" class="pp-btn pp-btn-small"><?php esc_html_e( 'Anzeigen', 'vereinsplugin' ); ?></button>
	</form>
	<?php if ( ! $empf ) : ?>
		<p class="pp-empty"><?php esc_html_e( 'Keine Empfänger:innen mit E-Mail-Adresse für diese Auswahl.', 'vereinsplugin' ); ?></p>
		<?php return; ?>
	<?php endif; ?>
	<p class="pp-meta"><?php echo esc_html( sprintf( _n( '%d Empfänger:in', '%d Empfänger:innen', count( $empf ), 'vereinsplugin' ), count( $empf ) ) ); ?></p>
	<label class="pp-meta"><?php esc_html_e( 'Adressen zum Kopieren (ins BCC-Feld)', 'vereinsplugin' ); ?>
		<textarea readonly rows="3" style="width:100%" onclick="this.select()"><?php echo esc_textarea( implode( ', ', $mails ) ); ?></textarea></label>
	<p><a class="pp-btn pp-btn-small" href="<?php echo esc_attr( 'mailto:' . rawurlencode( $ich->user_email ) . '?bcc=' . rawurlencode( implode( ',', $mails ) ) ); ?>"><?php esc_html_e( 'Im eigenen Mailprogramm öffnen (BCC)', 'vereinsplugin' ); ?></a></p>

	<details class="pp-werkzeug"><summary class="pp-details-summary"><?php esc_html_e( 'Rundmail von der Website senden (einzeln an jede:n)', 'vereinsplugin' ); ?></summary>
		<?php vp_kreis_form( 'vp_sponsor_rundmail', 'pp-form' ); ?>
			<div style="max-height:180px;overflow:auto;border:1px solid rgba(127,127,127,.25);border-radius:8px;padding:6px 10px;margin-bottom:8px">
				<?php foreach ( $empf as $e ) : ?>
					<label class="pp-checkbox-label" style="margin:0"><span><input type="checkbox" name="an[]" value="<?php echo (int) $e->id; ?>" checked> <?php echo esc_html( $e->firma . ( $e->ansprechperson ? ' – ' . $e->ansprechperson : '' ) . ' <' . $e->email . '>' ); ?></span></label>
				<?php endforeach; ?>
			</div>
			<label><?php esc_html_e( 'Betreff', 'vereinsplugin' ); ?><input type="text" name="betreff" required></label>
			<label><?php esc_html_e( 'Text', 'vereinsplugin' ); ?> <span class="pp-meta"><?php esc_html_e( 'Platzhalter: {name} (Ansprechperson), {firma}', 'vereinsplugin' ); ?></span>
				<textarea name="text" rows="8" required><?php echo esc_textarea( __( "Hallo {name},\n\n\n\nViele Grüße\n", 'vereinsplugin' ) . $ich->display_name ); ?></textarea></label>
			<label><?php esc_html_e( 'Antworten an', 'vereinsplugin' ); ?><input type="email" name="antwort_an" value="<?php echo esc_attr( $ich->user_email ); ?>"></label>
			<div class="pp-form-actions"><button type="submit" class="pp-btn pp-btn-primary pp-btn-small" onclick="return confirm('<?php echo esc_js( __( 'Rundmail jetzt an alle angehakten Empfänger:innen senden?', 'vereinsplugin' ) ); ?>')"><?php esc_html_e( 'Rundmail senden', 'vereinsplugin' ); ?></button></div>
		</form>
		<?php
		$log = array_reverse( (array) get_option( 'vp_sponsoring_mail_log', array() ) );
		if ( $log ) {
			echo '<p class="pp-meta">' . esc_html__( 'Zuletzt gesendet:', 'vereinsplugin' ) . '</p><ul class="pp-list">';
			foreach ( array_slice( $log, 0, 5 ) as $l ) {
				echo '<li><span class="pp-meta">' . esc_html( mysql2date( 'd.m.Y H:i', $l['zeit'] ) . ' · ' . pp_user_display_name( $l['von'] ) . ' · ' . $l['betreff'] . ' · ' . sprintf( __( '%d gesendet', 'vereinsplugin' ), $l['ok'] ) ) . '</span></li>';
			}
			echo '</ul>';
		}
		?>
	</details>
	<?php
}
