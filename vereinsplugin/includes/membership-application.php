<?php
/**
 * Kern: Mitgliedsantrag (öffentlich) + Vorstands-Prüfung/Freigabe.
 *
 * - [verein_mitgliedsantrag]  – öffentliches Formular (volle Felder inkl. SEPA)
 * - Sektion "Anträge" im Mitgliederbereich (nur mit Capability vp_manage_members)
 * - Annehmen  -> WordPress-Benutzer mit Rolle wl_mitglied wird angelegt,
 *                Zugangsdaten per E-Mail, Antragsdaten als User-Meta (vp_*)
 * - Ablehnen  -> Status "abgelehnt" (+ optionale Mail)
 *
 * Tabelle {$wpdb->prefix}vp_antraege.
 */

defined( 'ABSPATH' ) || exit;

define( 'VP_ANTRAG_DB_VERSION', '1' );

/* -------------------------------------------------------------------------
 * Tabelle
 * ---------------------------------------------------------------------- */

function vp_antraege_table() {
	global $wpdb;
	return $wpdb->prefix . 'vp_antraege';
}

function vp_create_antraege_table() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$table   = vp_antraege_table();
	$collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		created_at DATETIME NOT NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'neu',
		vorname VARCHAR(120) NOT NULL DEFAULT '',
		nachname VARCHAR(120) NOT NULL DEFAULT '',
		email VARCHAR(190) NOT NULL DEFAULT '',
		telefon VARCHAR(60) NOT NULL DEFAULT '',
		geburtsdatum DATE NULL,
		strasse VARCHAR(190) NOT NULL DEFAULT '',
		plz VARCHAR(20) NOT NULL DEFAULT '',
		ort VARCHAR(120) NOT NULL DEFAULT '',
		land VARCHAR(80) NOT NULL DEFAULT '',
		beitrag DECIMAL(10,2) NULL,
		beitrag_intervall VARCHAR(20) NOT NULL DEFAULT '',
		sepa_iban VARCHAR(40) NOT NULL DEFAULT '',
		sepa_kontoinhaber VARCHAR(190) NOT NULL DEFAULT '',
		sepa_mandat TINYINT(1) NOT NULL DEFAULT 0,
		mandatsref VARCHAR(60) NOT NULL DEFAULT '',
		nachricht TEXT NULL,
		ds_akzeptiert TINYINT(1) NOT NULL DEFAULT 0,
		bearbeitet_von BIGINT UNSIGNED NULL,
		bearbeitet_am DATETIME NULL,
		notiz TEXT NULL,
		user_id BIGINT UNSIGNED NULL,
		PRIMARY KEY  (id),
		KEY status (status),
		KEY email (email)
	) {$collate};";

	dbDelta( $sql );
	update_option( 'vp_antrag_db_version', VP_ANTRAG_DB_VERSION );
}

add_action( 'plugins_loaded', function () {
	if ( get_option( 'vp_antrag_db_version' ) !== VP_ANTRAG_DB_VERSION ) {
		vp_create_antraege_table();
	}
}, 5 );

/* -------------------------------------------------------------------------
 * Öffentliches Formular
 * ---------------------------------------------------------------------- */

add_shortcode( 'verein_mitgliedsantrag', 'vp_shortcode_mitgliedsantrag' );

function vp_shortcode_mitgliedsantrag( $atts ) {
	$done = false;
	$error = '';

	if ( isset( $_POST['vp_antrag_submit'] ) ) {
		$check = vp_handle_antrag_submit();
		if ( is_wp_error( $check ) ) {
			$error = $check->get_error_message();
		} else {
			$done = true;
		}
	}

	ob_start();
	echo '<div class="vp-antrag vp-card">';

	if ( $done ) {
		echo '<h2>' . esc_html__( 'Antrag eingegangen', 'vereinsplugin' ) . '</h2>';
		echo '<p>' . esc_html__( 'Vielen Dank! Dein Mitgliedsantrag wurde übermittelt und muss nun vom Vorstand geprüft werden. Du bekommst eine E-Mail, sobald er bearbeitet wurde.', 'vereinsplugin' ) . '</p>';
		echo '</div>';
		return ob_get_clean();
	}

	$fee_default      = get_option( 'vp_beitrag_default', '' );
	$fee_interval_def = get_option( 'vp_beitrag_intervall', 'jaehrlich' );
	$v = function ( $k ) { return isset( $_POST[ $k ] ) ? esc_attr( wp_unslash( $_POST[ $k ] ) ) : ''; };

	if ( $error ) {
		echo '<div class="vp-note vp-note-error">' . esc_html( $error ) . '</div>';
	}
	?>
	<h2><?php esc_html_e( 'Mitglied werden', 'vereinsplugin' ); ?></h2>
	<form method="post" class="vp-form" novalidate>
		<?php wp_nonce_field( 'vp_antrag', 'vp_antrag_nonce' ); ?>
		<input type="text" name="vp_hp" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">

		<div class="vp-form-grid">
			<label>* <?php esc_html_e( 'Vorname', 'vereinsplugin' ); ?>
				<input type="text" name="vorname" required value="<?php echo $v( 'vorname' ); ?>"></label>
			<label>* <?php esc_html_e( 'Nachname', 'vereinsplugin' ); ?>
				<input type="text" name="nachname" required value="<?php echo $v( 'nachname' ); ?>"></label>
			<label>* <?php esc_html_e( 'E-Mail', 'vereinsplugin' ); ?>
				<input type="email" name="email" required value="<?php echo $v( 'email' ); ?>"></label>
			<label><?php esc_html_e( 'Telefon', 'vereinsplugin' ); ?>
				<input type="tel" name="telefon" value="<?php echo $v( 'telefon' ); ?>"></label>
			<label><?php esc_html_e( 'Geburtsdatum', 'vereinsplugin' ); ?>
				<input type="date" name="geburtsdatum" value="<?php echo $v( 'geburtsdatum' ); ?>"></label>
		</div>

		<fieldset>
			<legend><?php esc_html_e( 'Anschrift', 'vereinsplugin' ); ?></legend>
			<div class="vp-form-grid">
				<label class="vp-col-2">* <?php esc_html_e( 'Straße & Nr.', 'vereinsplugin' ); ?>
					<input type="text" name="strasse" required value="<?php echo $v( 'strasse' ); ?>"></label>
				<label>* <?php esc_html_e( 'PLZ', 'vereinsplugin' ); ?>
					<input type="text" name="plz" required value="<?php echo $v( 'plz' ); ?>"></label>
				<label>* <?php esc_html_e( 'Ort', 'vereinsplugin' ); ?>
					<input type="text" name="ort" required value="<?php echo $v( 'ort' ); ?>"></label>
				<label><?php esc_html_e( 'Land', 'vereinsplugin' ); ?>
					<input type="text" name="land" value="<?php echo $v( 'land' ) ?: 'Deutschland'; ?>"></label>
			</div>
		</fieldset>

		<fieldset>
			<legend><?php esc_html_e( 'Mitgliedsbeitrag', 'vereinsplugin' ); ?></legend>
			<div class="vp-form-grid">
				<label><?php esc_html_e( 'Beitrag (€)', 'vereinsplugin' ); ?>
					<input type="number" step="0.01" min="0" name="beitrag" value="<?php echo $v( 'beitrag' ) ?: esc_attr( $fee_default ); ?>"></label>
				<label><?php esc_html_e( 'Zahlungsintervall', 'vereinsplugin' ); ?>
					<select name="beitrag_intervall">
						<?php
						$intervals = array(
							'monatlich'      => __( 'monatlich', 'vereinsplugin' ),
							'vierteljaehrlich' => __( 'vierteljährlich', 'vereinsplugin' ),
							'halbjaehrlich'  => __( 'halbjährlich', 'vereinsplugin' ),
							'jaehrlich'      => __( 'jährlich', 'vereinsplugin' ),
						);
						$sel = $_POST['beitrag_intervall'] ?? $fee_interval_def;
						foreach ( $intervals as $key => $label ) {
							printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $sel, $key, false ), esc_html( $label ) );
						}
						?>
					</select></label>
			</div>
		</fieldset>

		<fieldset>
			<legend><?php esc_html_e( 'SEPA-Lastschriftmandat', 'vereinsplugin' ); ?></legend>
			<div class="vp-form-grid">
				<label class="vp-col-2"><?php esc_html_e( 'Kontoinhaber:in', 'vereinsplugin' ); ?>
					<input type="text" name="sepa_kontoinhaber" value="<?php echo $v( 'sepa_kontoinhaber' ); ?>"></label>
				<label class="vp-col-2"><?php esc_html_e( 'IBAN', 'vereinsplugin' ); ?>
					<input type="text" name="sepa_iban" inputmode="text" autocomplete="off" value="<?php echo $v( 'sepa_iban' ); ?>"></label>
			</div>
			<?php
			$mandat_text = get_option( 'vp_sepa_mandatstext' );
			if ( ! $mandat_text ) {
				$mandat_text = sprintf(
					/* translators: %s = creditor / association name */
					__( 'Ich ermächtige %s, Zahlungen von meinem Konto mittels Lastschrift einzuziehen. Zugleich weise ich mein Kreditinstitut an, die von %s auf mein Konto gezogenen Lastschriften einzulösen. Ich kann innerhalb von acht Wochen, beginnend mit dem Belastungsdatum, die Erstattung des belasteten Betrags verlangen.', 'vereinsplugin' ),
					get_option( 'vp_sepa_glaeubiger', get_bloginfo( 'name' ) ),
					get_option( 'vp_sepa_glaeubiger', get_bloginfo( 'name' ) )
				);
			}
			?>
			<p class="vp-mandat-text"><?php echo esc_html( $mandat_text ); ?></p>
			<label class="vp-check"><input type="checkbox" name="sepa_mandat" value="1" <?php checked( ! empty( $_POST['sepa_mandat'] ) ); ?>>
				<?php esc_html_e( 'Ich erteile das SEPA-Lastschriftmandat.', 'vereinsplugin' ); ?></label>
		</fieldset>

		<label><?php esc_html_e( 'Nachricht / Bemerkung', 'vereinsplugin' ); ?>
			<textarea name="nachricht" rows="3"><?php echo esc_textarea( wp_unslash( $_POST['nachricht'] ?? '' ) ); ?></textarea></label>

		<label class="vp-check">* <input type="checkbox" name="ds_akzeptiert" value="1" required <?php checked( ! empty( $_POST['ds_akzeptiert'] ) ); ?>>
			<?php
			$ds_url = get_privacy_policy_url();
			if ( $ds_url ) {
				printf(
					/* translators: %s = privacy policy link */
					wp_kses_post( __( 'Ich habe die <a href="%s" target="_blank" rel="noopener">Datenschutzerklärung</a> gelesen und stimme der Verarbeitung meiner Daten zu.', 'vereinsplugin' ) ),
					esc_url( $ds_url )
				);
			} else {
				esc_html_e( 'Ich stimme der Verarbeitung meiner Daten zum Zweck der Mitgliederverwaltung zu.', 'vereinsplugin' );
			}
			?>
		</label>

		<button type="submit" name="vp_antrag_submit" value="1" class="vp-btn vp-btn-primary"><?php esc_html_e( 'Antrag absenden', 'vereinsplugin' ); ?></button>
	</form>
	<?php
	echo '</div>';
	return ob_get_clean();
}

function vp_handle_antrag_submit() {
	if ( ! isset( $_POST['vp_antrag_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['vp_antrag_nonce'] ), 'vp_antrag' ) ) {
		return new WP_Error( 'nonce', __( 'Sicherheitsprüfung fehlgeschlagen. Bitte Seite neu laden.', 'vereinsplugin' ) );
	}
	if ( ! empty( $_POST['vp_hp'] ) ) {
		return new WP_Error( 'spam', __( 'Übermittlung abgelehnt.', 'vereinsplugin' ) );
	}

	$vorname  = sanitize_text_field( wp_unslash( $_POST['vorname'] ?? '' ) );
	$nachname = sanitize_text_field( wp_unslash( $_POST['nachname'] ?? '' ) );
	$email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );

	if ( '' === $vorname || '' === $nachname ) {
		return new WP_Error( 'name', __( 'Bitte Vor- und Nachname angeben.', 'vereinsplugin' ) );
	}
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'email', __( 'Bitte eine gültige E-Mail-Adresse angeben.', 'vereinsplugin' ) );
	}
	if ( empty( $_POST['ds_akzeptiert'] ) ) {
		return new WP_Error( 'ds', __( 'Bitte der Datenverarbeitung zustimmen.', 'vereinsplugin' ) );
	}
	if ( '' === sanitize_text_field( wp_unslash( $_POST['strasse'] ?? '' ) )
		|| '' === sanitize_text_field( wp_unslash( $_POST['plz'] ?? '' ) )
		|| '' === sanitize_text_field( wp_unslash( $_POST['ort'] ?? '' ) ) ) {
		return new WP_Error( 'adr', __( 'Bitte die vollständige Anschrift angeben.', 'vereinsplugin' ) );
	}

	global $wpdb;
	$geb = sanitize_text_field( wp_unslash( $_POST['geburtsdatum'] ?? '' ) );
	$iban = strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['sepa_iban'] ?? '' ) ) ) );

	$data = array(
		'created_at'        => current_time( 'mysql' ),
		'status'            => 'neu',
		'vorname'           => $vorname,
		'nachname'          => $nachname,
		'email'             => $email,
		'telefon'           => sanitize_text_field( wp_unslash( $_POST['telefon'] ?? '' ) ),
		'geburtsdatum'      => ( $geb && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $geb ) ) ? $geb : null,
		'strasse'           => sanitize_text_field( wp_unslash( $_POST['strasse'] ?? '' ) ),
		'plz'               => sanitize_text_field( wp_unslash( $_POST['plz'] ?? '' ) ),
		'ort'               => sanitize_text_field( wp_unslash( $_POST['ort'] ?? '' ) ),
		'land'              => sanitize_text_field( wp_unslash( $_POST['land'] ?? '' ) ),
		'beitrag'           => ( '' !== ( $_POST['beitrag'] ?? '' ) ) ? (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['beitrag'] ) ) ) : null,
		'beitrag_intervall' => sanitize_key( wp_unslash( $_POST['beitrag_intervall'] ?? '' ) ),
		'sepa_iban'         => $iban,
		'sepa_kontoinhaber' => sanitize_text_field( wp_unslash( $_POST['sepa_kontoinhaber'] ?? '' ) ),
		'sepa_mandat'       => empty( $_POST['sepa_mandat'] ) ? 0 : 1,
		'mandatsref'        => '',
		'nachricht'         => sanitize_textarea_field( wp_unslash( $_POST['nachricht'] ?? '' ) ),
		'ds_akzeptiert'     => 1,
	);

	$ok = $wpdb->insert( vp_antraege_table(), $data );
	if ( ! $ok ) {
		return new WP_Error( 'db', __( 'Der Antrag konnte nicht gespeichert werden. Bitte später erneut versuchen.', 'vereinsplugin' ) );
	}
	$antrag_id = (int) $wpdb->insert_id;

	// Mandatsreferenz nachtragen.
	$mandatsref = 'VP-' . str_pad( (string) $antrag_id, 5, '0', STR_PAD_LEFT );
	$wpdb->update( vp_antraege_table(), array( 'mandatsref' => $mandatsref ), array( 'id' => $antrag_id ) );

	vp_antrag_mail_to_board( $antrag_id, $data );
	vp_antrag_mail_to_applicant( $data );

	return $antrag_id;
}

function vp_board_email() {
	$mail = get_option( 'vp_vorstand_email' );
	return is_email( $mail ) ? $mail : get_option( 'admin_email' );
}

function vp_antrag_mail_to_board( $id, $data ) {
	$subject = sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'Neuer Mitgliedsantrag', 'vereinsplugin' ) );
	$lines   = array(
		__( 'Es liegt ein neuer Mitgliedsantrag vor:', 'vereinsplugin' ),
		'',
		$data['vorname'] . ' ' . $data['nachname'],
		$data['email'] . ( $data['telefon'] ? ' · ' . $data['telefon'] : '' ),
		trim( $data['strasse'] . ', ' . $data['plz'] . ' ' . $data['ort'] ),
		'',
		__( 'Beitrag:', 'vereinsplugin' ) . ' ' . ( null !== $data['beitrag'] ? number_format( (float) $data['beitrag'], 2, ',', '.' ) . ' € ' . $data['beitrag_intervall'] : '—' ),
		__( 'SEPA-Mandat:', 'vereinsplugin' ) . ' ' . ( $data['sepa_mandat'] ? __( 'erteilt', 'vereinsplugin' ) : __( 'nicht erteilt', 'vereinsplugin' ) ),
		'',
		__( 'Prüfen und freigeben im Mitgliederbereich unter „Anträge“.', 'vereinsplugin' ),
	);
	wp_mail( vp_board_email(), $subject, implode( "\n", $lines ) );
}

function vp_antrag_mail_to_applicant( $data ) {
	$subject = sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'Dein Mitgliedsantrag ist eingegangen', 'vereinsplugin' ) );
	$body    = sprintf(
		/* translators: 1: first name, 2: site name */
		__( "Hallo %1\$s,\n\nvielen Dank für deinen Mitgliedsantrag bei %2\$s. Der Vorstand prüft ihn und meldet sich anschließend bei dir.\n\nViele Grüße", 'vereinsplugin' ),
		$data['vorname'],
		get_bloginfo( 'name' )
	);
	wp_mail( $data['email'], $subject, $body );
}

/* -------------------------------------------------------------------------
 * Vorstands-Sektion: Anträge prüfen
 * ---------------------------------------------------------------------- */

function vp_render_antraege_section() {
	if ( ! current_user_can( 'vp_manage_members' ) ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) . '</div>';
	}
	global $wpdb;

	$msg = '';
	if ( isset( $_POST['vp_antrag_action'], $_POST['antrag_id'] )
		&& check_admin_referer( 'vp_antrag_decide', 'vp_decide_nonce' ) ) {
		$msg = vp_antrag_decide( (int) $_POST['antrag_id'], sanitize_key( wp_unslash( $_POST['vp_antrag_action'] ) ), sanitize_textarea_field( wp_unslash( $_POST['notiz'] ?? '' ) ) );
	}

	$filter = isset( $_GET['vp_antrag_status'] ) ? sanitize_key( wp_unslash( $_GET['vp_antrag_status'] ) ) : 'neu';
	$allowed = array( 'neu', 'angenommen', 'abgelehnt', 'alle' );
	if ( ! in_array( $filter, $allowed, true ) ) {
		$filter = 'neu';
	}
	$where = 'alle' === $filter ? '1=1' : $wpdb->prepare( 'status = %s', $filter );
	$rows  = $wpdb->get_results( "SELECT * FROM " . vp_antraege_table() . " WHERE {$where} ORDER BY created_at DESC" );

	ob_start();
	echo '<div class="vp-antraege">';
	echo '<h2>' . esc_html__( 'Mitgliedsanträge', 'vereinsplugin' ) . '</h2>';
	if ( $msg ) {
		echo '<div class="vp-note">' . esc_html( $msg ) . '</div>';
	}

	echo '<nav class="vp-subnav">';
	foreach ( array( 'neu' => __( 'Offen', 'vereinsplugin' ), 'angenommen' => __( 'Angenommen', 'vereinsplugin' ), 'abgelehnt' => __( 'Abgelehnt', 'vereinsplugin' ), 'alle' => __( 'Alle', 'vereinsplugin' ) ) as $k => $label ) {
		$url = add_query_arg( 'vp_antrag_status', $k );
		printf( '<a class="%s" href="%s">%s</a>', $k === $filter ? 'is-active' : '', esc_url( $url ), esc_html( $label ) );
	}
	echo '</nav>';

	if ( ! $rows ) {
		echo '<p class="vp-muted">' . esc_html__( 'Keine Anträge in dieser Ansicht.', 'vereinsplugin' ) . '</p>';
		echo '</div>';
		return ob_get_clean();
	}

	foreach ( $rows as $a ) {
		$offen = 'neu' === $a->status;
		echo '<details class="vp-card vp-antrag-item"' . ( $offen ? ' open' : '' ) . '>';
		printf(
			'<summary><strong>%s %s</strong> · %s <span class="vp-badge vp-badge-%s">%s</span></summary>',
			esc_html( $a->vorname ),
			esc_html( $a->nachname ),
			esc_html( date_i18n( 'd.m.Y', strtotime( $a->created_at ) ) ),
			esc_attr( $a->status ),
			esc_html( vp_antrag_status_label( $a->status ) )
		);
		echo '<div class="vp-antrag-body">';
		echo '<dl>';
		vp_dl( __( 'E-Mail', 'vereinsplugin' ), $a->email );
		vp_dl( __( 'Telefon', 'vereinsplugin' ), $a->telefon );
		vp_dl( __( 'Geburtsdatum', 'vereinsplugin' ), $a->geburtsdatum ? date_i18n( 'd.m.Y', strtotime( $a->geburtsdatum ) ) : '' );
		vp_dl( __( 'Anschrift', 'vereinsplugin' ), trim( $a->strasse . ', ' . $a->plz . ' ' . $a->ort . ' ' . $a->land ) );
		vp_dl( __( 'Beitrag', 'vereinsplugin' ), null !== $a->beitrag ? number_format( (float) $a->beitrag, 2, ',', '.' ) . ' € / ' . $a->beitrag_intervall : '' );
		vp_dl( __( 'SEPA', 'vereinsplugin' ), $a->sepa_mandat ? ( $a->sepa_kontoinhaber . ' · ' . vp_iban_mask( $a->sepa_iban ) . ' · Mandat ' . $a->mandatsref ) : __( 'kein Mandat', 'vereinsplugin' ) );
		vp_dl( __( 'Nachricht', 'vereinsplugin' ), $a->nachricht );
		if ( $a->notiz ) {
			vp_dl( __( 'Interne Notiz', 'vereinsplugin' ), $a->notiz );
		}
		echo '</dl>';

		if ( $offen ) {
			echo '<form method="post" class="vp-antrag-decide">';
			wp_nonce_field( 'vp_antrag_decide', 'vp_decide_nonce' );
			echo '<input type="hidden" name="antrag_id" value="' . (int) $a->id . '">';
			echo '<textarea name="notiz" rows="2" placeholder="' . esc_attr__( 'Notiz (optional, bei Ablehnung auch für die Mail)', 'vereinsplugin' ) . '"></textarea>';
			echo '<div class="vp-antrag-actions">';
			echo '<button class="vp-btn vp-btn-primary" name="vp_antrag_action" value="annehmen">' . esc_html__( 'Annehmen & Zugang anlegen', 'vereinsplugin' ) . '</button> ';
			echo '<button class="vp-btn vp-btn-danger" name="vp_antrag_action" value="ablehnen">' . esc_html__( 'Ablehnen', 'vereinsplugin' ) . '</button>';
			echo '</div>';
			echo '</form>';
		} elseif ( 'angenommen' === $a->status && $a->user_id ) {
			echo '<p class="vp-muted">' . esc_html__( 'Angenommen – Benutzerkonto:', 'vereinsplugin' ) . ' ' . esc_html( get_userdata( $a->user_id ) ? get_userdata( $a->user_id )->user_login : '#' . $a->user_id ) . '</p>';
		}
		echo '</div></details>';
	}
	echo '</div>';
	return ob_get_clean();
}

function vp_antrag_decide( $id, $action, $notiz ) {
	global $wpdb;
	$a = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vp_antraege_table() . ' WHERE id = %d', $id ) );
	if ( ! $a || 'neu' !== $a->status ) {
		return __( 'Antrag nicht gefunden oder bereits bearbeitet.', 'vereinsplugin' );
	}

	if ( 'annehmen' === $action ) {
		$user_id = vp_antrag_create_user( $a );
		if ( is_wp_error( $user_id ) ) {
			return $user_id->get_error_message();
		}
		$wpdb->update( vp_antraege_table(), array(
			'status'         => 'angenommen',
			'bearbeitet_von' => get_current_user_id(),
			'bearbeitet_am'  => current_time( 'mysql' ),
			'notiz'          => $notiz,
			'user_id'        => $user_id,
		), array( 'id' => $id ) );
		return __( 'Antrag angenommen. Zugangsdaten wurden per E-Mail verschickt.', 'vereinsplugin' );
	}

	if ( 'ablehnen' === $action ) {
		$wpdb->update( vp_antraege_table(), array(
			'status'         => 'abgelehnt',
			'bearbeitet_von' => get_current_user_id(),
			'bearbeitet_am'  => current_time( 'mysql' ),
			'notiz'          => $notiz,
		), array( 'id' => $id ) );

		$body = sprintf(
			/* translators: 1: first name, 2: site name */
			__( "Hallo %1\$s,\n\nvielen Dank für dein Interesse an %2\$s. Leider können wir deinen Mitgliedsantrag derzeit nicht annehmen.", 'vereinsplugin' ),
			$a->vorname,
			get_bloginfo( 'name' )
		);
		if ( $notiz ) {
			$body .= "\n\n" . $notiz;
		}
		wp_mail( $a->email, sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'Zu deinem Mitgliedsantrag', 'vereinsplugin' ) ), $body );
		return __( 'Antrag abgelehnt.', 'vereinsplugin' );
	}

	return __( 'Unbekannte Aktion.', 'vereinsplugin' );
}

function vp_antrag_create_user( $a ) {
	if ( email_exists( $a->email ) ) {
		return new WP_Error( 'exists', __( 'Für diese E-Mail existiert bereits ein Benutzerkonto.', 'vereinsplugin' ) );
	}
	$base = sanitize_user( strtolower( $a->vorname . '.' . $a->nachname ), true );
	$base = $base ?: 'mitglied';
	$login = $base;
	$i = 1;
	while ( username_exists( $login ) ) {
		$login = $base . ++$i;
	}
	$pass    = wp_generate_password( 14 );
	$user_id = wp_insert_user( array(
		'user_login'   => $login,
		'user_pass'    => $pass,
		'user_email'   => $a->email,
		'first_name'   => $a->vorname,
		'last_name'    => $a->nachname,
		'display_name' => trim( $a->vorname . ' ' . $a->nachname ),
		'role'         => VP_MEMBER_ROLE,
	) );
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	foreach ( array(
		'vp_telefon'       => $a->telefon,
		'vp_geburtsdatum'  => $a->geburtsdatum,
		'vp_strasse'       => $a->strasse,
		'vp_plz'           => $a->plz,
		'vp_ort'           => $a->ort,
		'vp_land'          => $a->land,
		'vp_beitrag'       => $a->beitrag,
		'vp_beitrag_intervall' => $a->beitrag_intervall,
		'vp_sepa_iban'     => $a->sepa_iban,
		'vp_sepa_kontoinhaber' => $a->sepa_kontoinhaber,
		'vp_sepa_mandat'   => $a->sepa_mandat,
		'vp_mandatsref'    => $a->mandatsref,
		'vp_mitglied_seit' => current_time( 'Y-m-d' ),
	) as $k => $val ) {
		update_user_meta( $user_id, $k, $val );
	}

	$login_url = get_option( 'vp_login_url' ) ?: wp_login_url();
	$body = sprintf(
		/* translators: 1: name, 2: site, 3: username, 4: password, 5: login url */
		__( "Hallo %1\$s,\n\ndein Mitgliedsantrag bei %2\$s wurde angenommen. Willkommen!\n\nDein Zugang zum Mitgliederbereich:\nBenutzername: %3\$s\nPasswort: %4\$s\nLogin: %5\$s\n\nBitte ändere dein Passwort nach dem ersten Login.", 'vereinsplugin' ),
		$a->vorname,
		get_bloginfo( 'name' ),
		$login,
		$pass,
		$login_url
	);
	wp_mail( $a->email, sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'Willkommen – dein Zugang', 'vereinsplugin' ) ), $body );

	/**
	 * Neues Vereinsmitglied wurde angelegt (z. B. für den Nextcloud-Sync).
	 *
	 * @param int $user_id
	 */
	do_action( 'vp_member_created', $user_id );

	return $user_id;
}

/* helpers */
function vp_antrag_status_label( $s ) {
	$m = array(
		'neu'        => __( 'Offen', 'vereinsplugin' ),
		'angenommen' => __( 'Angenommen', 'vereinsplugin' ),
		'abgelehnt'  => __( 'Abgelehnt', 'vereinsplugin' ),
	);
	return $m[ $s ] ?? $s;
}
function vp_dl( $label, $value ) {
	if ( '' === (string) $value || null === $value ) {
		return;
	}
	echo '<dt>' . esc_html( $label ) . '</dt><dd>' . nl2br( esc_html( $value ) ) . '</dd>';
}
function vp_iban_mask( $iban ) {
	$iban = (string) $iban;
	if ( strlen( $iban ) < 8 ) {
		return $iban;
	}
	return substr( $iban, 0, 4 ) . ' … ' . substr( $iban, -4 );
}
