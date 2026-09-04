<?php
/**
 * Kern: die eine Einstellungsseite (Verein → Einstellungen).
 *
 * Stage 1: Hub.
 *   - Häufig gebrauchte Felder (Bankverbindung/Spendenkontakt) direkt hier.
 *   - Modul-Aktivierung + Zugriffs-Schalter direkt hier.
 *   - Für die umfangreichen technischen Zugänge (Social-Media-API-Keys,
 *     Nextcloud, PWA) Deep-Links auf die weiterhin vorhandenen – nur aus dem
 *     Menü ausgeblendeten – Modul-Seiten.
 *
 * Stage 2 zieht diese technischen Felder vollständig hier herein, sodass es
 * wirklich nur noch eine Seite gibt.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_init', 'vp_settings_save' );
function vp_settings_save() {
	if ( ! isset( $_POST['vp_save_settings'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'vp_settings' ) ) {
		return;
	}

	// Bankverbindung / Spendenkontakt (bisher: Wunschliste-Einstellungen).
	update_option( 'wl_kontoinhaber', sanitize_text_field( wp_unslash( $_POST['wl_kontoinhaber'] ?? '' ) ) );
	update_option( 'wl_iban', sanitize_text_field( wp_unslash( $_POST['wl_iban'] ?? '' ) ) );
	update_option( 'wl_bic', sanitize_text_field( wp_unslash( $_POST['wl_bic'] ?? '' ) ) );
	update_option( 'wl_kontakt_email', sanitize_email( wp_unslash( $_POST['wl_kontakt_email'] ?? '' ) ) );

	// Zugriffs-Schalter.
	update_option( 'vp_member_backend_access', isset( $_POST['vp_member_backend_access'] ) ? '1' : '0' );
	update_option( 'vp_show_module_menus', isset( $_POST['vp_show_module_menus'] ) ? '1' : '0' );

	// App / WebApp (PWA).
	update_option( 'vp_pwa_enabled', isset( $_POST['vp_pwa_enabled'] ) ? '1' : '0' );
	update_option( 'vp_app_name', sanitize_text_field( wp_unslash( $_POST['vp_app_name'] ?? '' ) ) );
	update_option( 'vp_app_short_name', sanitize_text_field( wp_unslash( $_POST['vp_app_short_name'] ?? '' ) ) );
	$color = sanitize_hex_color( wp_unslash( $_POST['vp_app_theme_color'] ?? '' ) );
	update_option( 'vp_app_theme_color', $color ? $color : '#1f2937' );

	// Verein / Vorstand.
	update_option( 'vp_vorstand_email', sanitize_email( wp_unslash( $_POST['vp_vorstand_email'] ?? '' ) ) );
	update_option( 'vp_antrag_page_id', (int) ( $_POST['vp_antrag_page_id'] ?? 0 ) );
	update_option( 'vp_member_area_page_id', (int) ( $_POST['vp_member_area_page_id'] ?? 0 ) );
	if ( (int) ( $_POST['vp_member_area_page_id'] ?? 0 ) ) {
		update_option( 'vp_member_area_url', get_permalink( (int) $_POST['vp_member_area_page_id'] ) );
	}
	delete_transient( 'vp_antrag_page_lookup' );

	// Nextcloud-Sync.
	update_option( 'vp_nc_url', esc_url_raw( trim( (string) wp_unslash( $_POST['vp_nc_url'] ?? '' ) ) ) );
	update_option( 'vp_nc_admin_user', sanitize_text_field( wp_unslash( $_POST['vp_nc_admin_user'] ?? '' ) ) );
	if ( '' !== trim( (string) ( $_POST['vp_nc_admin_pass'] ?? '' ) ) ) {
		update_option( 'vp_nc_admin_pass', trim( (string) wp_unslash( $_POST['vp_nc_admin_pass'] ) ) );
	}
	update_option( 'vp_nc_group_map', sanitize_textarea_field( wp_unslash( $_POST['vp_nc_group_map'] ?? '' ) ) );
	update_option( 'vp_nc_sync_enabled', isset( $_POST['vp_nc_sync_enabled'] ) ? '1' : '0' );
	update_option( 'vp_nc_sso', isset( $_POST['vp_nc_sso'] ) ? '1' : '0' );

	// SEPA / Beitrag.
	update_option( 'vp_sepa_glaeubiger', sanitize_text_field( wp_unslash( $_POST['vp_sepa_glaeubiger'] ?? '' ) ) );
	update_option( 'vp_sepa_glaeubiger_id', sanitize_text_field( wp_unslash( $_POST['vp_sepa_glaeubiger_id'] ?? '' ) ) );
	update_option( 'vp_sepa_mandatstext', sanitize_textarea_field( wp_unslash( $_POST['vp_sepa_mandatstext'] ?? '' ) ) );
	update_option( 'vp_beitrag_default', sanitize_text_field( wp_unslash( $_POST['vp_beitrag_default'] ?? '' ) ) );
	update_option( 'vp_beitrag_intervall', sanitize_key( wp_unslash( $_POST['vp_beitrag_intervall'] ?? 'jaehrlich' ) ) );
	update_option( 'vp_sepa_bic', sanitize_text_field( wp_unslash( $_POST['vp_sepa_bic'] ?? '' ) ) );
	update_option( 'vp_sepa_zweck_vorlage', sanitize_text_field( wp_unslash( $_POST['vp_sepa_zweck_vorlage'] ?? '' ) ) );

	// Verein als Aussteller (Rechnungen + Zuwendungsbestätigungen).
	update_option( 'vp_org_name', sanitize_text_field( wp_unslash( $_POST['vp_org_name'] ?? '' ) ) );
	update_option( 'vp_org_anschrift', sanitize_textarea_field( wp_unslash( $_POST['vp_org_anschrift'] ?? '' ) ) );
	update_option( 'vp_org_ort', sanitize_text_field( wp_unslash( $_POST['vp_org_ort'] ?? '' ) ) );
	update_option( 'vp_org_email', sanitize_email( wp_unslash( $_POST['vp_org_email'] ?? '' ) ) );
	update_option( 'vp_org_web', esc_url_raw( wp_unslash( $_POST['vp_org_web'] ?? '' ) ) );
	update_option( 'vp_org_steuernr', sanitize_text_field( wp_unslash( $_POST['vp_org_steuernr'] ?? '' ) ) );
	update_option( 'vp_org_finanzamt', sanitize_text_field( wp_unslash( $_POST['vp_org_finanzamt'] ?? '' ) ) );
	update_option( 'vp_org_vertreter', sanitize_text_field( wp_unslash( $_POST['vp_org_vertreter'] ?? '' ) ) );

	// Rechnungen.
	update_option( 'vp_rechnung_prefix', sanitize_text_field( wp_unslash( $_POST['vp_rechnung_prefix'] ?? 'RE' ) ) );
	update_option( 'vp_rechnung_zahlungsziel', max( 0, (int) ( $_POST['vp_rechnung_zahlungsziel'] ?? 14 ) ) );
	update_option( 'vp_rechnung_einleitung', sanitize_textarea_field( wp_unslash( $_POST['vp_rechnung_einleitung'] ?? '' ) ) );
	update_option( 'vp_rechnung_schluss', sanitize_textarea_field( wp_unslash( $_POST['vp_rechnung_schluss'] ?? '' ) ) );
	update_option( 'vp_rechnung_ust_hinweis', sanitize_textarea_field( wp_unslash( $_POST['vp_rechnung_ust_hinweis'] ?? '' ) ) );

	// Zuwendungsbestätigungen.
	update_option( 'vp_spende_konten', sanitize_text_field( wp_unslash( $_POST['vp_spende_konten'] ?? '4200,4210' ) ) );
	update_option( 'vp_spende_zweck', sanitize_text_field( wp_unslash( $_POST['vp_spende_zweck'] ?? '' ) ) );
	update_option( 'vp_spende_bescheid_typ', in_array( ( $_POST['vp_spende_bescheid_typ'] ?? 'freistellung' ), array( 'freistellung', '60a' ), true ) ? sanitize_text_field( wp_unslash( $_POST['vp_spende_bescheid_typ'] ) ) : 'freistellung' );
	update_option( 'vp_spende_bescheid_datum', sanitize_text_field( wp_unslash( $_POST['vp_spende_bescheid_datum'] ?? '' ) ) );
	update_option( 'vp_spende_veranlagungszeitraum', sanitize_text_field( wp_unslash( $_POST['vp_spende_veranlagungszeitraum'] ?? '' ) ) );

	// Module an/aus.
	$all      = array_keys( vp_modules() );
	$enabled  = array_map( 'sanitize_key', (array) ( $_POST['vp_modules'] ?? array() ) );
	$disabled = array_values( array_diff( $all, $enabled ) );
	update_option( 'vp_disabled_modules', $disabled );

	add_settings_error( 'vp_settings', 'saved', __( 'Einstellungen gespeichert.', 'vereinsplugin' ), 'success' );
}

function vp_render_settings_page() {
	$disabled = (array) get_option( 'vp_disabled_modules', array() );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Vereinsplugin – Einstellungen', 'vereinsplugin' ); ?></h1>
		<?php settings_errors( 'vp_settings' ); ?>

		<form method="post">
			<?php wp_nonce_field( 'vp_settings' ); ?>

			<h2><?php esc_html_e( 'Bankverbindung & Spendenkontakt', 'vereinsplugin' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wl_kontoinhaber"><?php esc_html_e( 'Kontoinhaber', 'vereinsplugin' ); ?></label></th>
					<td><input name="wl_kontoinhaber" id="wl_kontoinhaber" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'wl_kontoinhaber', '' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="wl_iban"><?php esc_html_e( 'IBAN', 'vereinsplugin' ); ?></label></th>
					<td><input name="wl_iban" id="wl_iban" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'wl_iban', '' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="wl_bic"><?php esc_html_e( 'BIC', 'vereinsplugin' ); ?></label></th>
					<td><input name="wl_bic" id="wl_bic" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'wl_bic', '' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="wl_kontakt_email"><?php esc_html_e( 'Kontakt-E-Mail (Spendenanfragen)', 'vereinsplugin' ); ?></label></th>
					<td><input name="wl_kontakt_email" id="wl_kontakt_email" type="email" class="regular-text" value="<?php echo esc_attr( get_option( 'wl_kontakt_email', get_option( 'admin_email' ) ) ); ?>"></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Mitgliederbereich & App', 'vereinsplugin' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="vp_member_area_page_id"><?php esc_html_e( 'Mitgliederbereich-Seite', 'vereinsplugin' ); ?></label></th>
					<td>
						<?php
						wp_dropdown_pages( array(
							'name'              => 'vp_member_area_page_id',
							'id'                => 'vp_member_area_page_id',
							'selected'          => (int) get_option( 'vp_member_area_page_id' ),
							'show_option_none'  => __( '– automatisch erkennen –', 'vereinsplugin' ),
							'option_none_value' => 0,
						) );
						?>
						<p class="description"><?php esc_html_e( 'Die Seite mit [verein_mitgliederbereich]. Wird für Weiterleitungen und als Start der App genutzt.', 'vereinsplugin' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_antrag_page_id"><?php esc_html_e( 'Mitgliedsantrag-Seite', 'vereinsplugin' ); ?></label></th>
					<td>
						<?php
						wp_dropdown_pages( array(
							'name'              => 'vp_antrag_page_id',
							'id'                => 'vp_antrag_page_id',
							'selected'          => (int) get_option( 'vp_antrag_page_id' ),
							'show_option_none'  => __( '– automatisch erkennen –', 'vereinsplugin' ),
							'option_none_value' => 0,
						) );
						?>
						<p class="description"><?php esc_html_e( 'Die Seite mit [verein_mitgliedsantrag]. Wird auf der Login-Seite verlinkt.', 'vereinsplugin' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Als App installierbar (PWA)', 'vereinsplugin' ); ?></th>
					<td><label><input type="checkbox" name="vp_pwa_enabled" value="1" <?php checked( get_option( 'vp_pwa_enabled', '1' ) === '1' ); ?>>
						<?php esc_html_e( 'Manifest + Service Worker ausliefern (benötigt HTTPS).', 'vereinsplugin' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_app_name"><?php esc_html_e( 'App-Name', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_app_name" id="vp_app_name" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'vp_app_name', get_bloginfo( 'name' ) ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_app_short_name"><?php esc_html_e( 'Kurzname (Homescreen)', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_app_short_name" id="vp_app_short_name" type="text" class="regular-text" maxlength="12" value="<?php echo esc_attr( get_option( 'vp_app_short_name', '' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_app_theme_color"><?php esc_html_e( 'App-Farbe', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_app_theme_color" id="vp_app_theme_color" type="text" placeholder="#1f2937" value="<?php echo esc_attr( get_option( 'vp_app_theme_color', '#1f2937' ) ); ?>"></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Nextcloud-Sync', 'vereinsplugin' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="vp_nc_url"><?php esc_html_e( 'Nextcloud-URL', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_nc_url" id="vp_nc_url" type="url" class="regular-text" placeholder="https://cloud.verein.de" value="<?php echo esc_attr( get_option( 'vp_nc_url', get_option( 'jb_nc_url', '' ) ) ); ?>">
						<p class="description"><?php esc_html_e( 'Leer lassen, um die URL aus der Buchhaltung (Belege) zu übernehmen.', 'vereinsplugin' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_nc_admin_user"><?php esc_html_e( 'Admin-/Gruppenadmin-Benutzer', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_nc_admin_user" id="vp_nc_admin_user" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'vp_nc_admin_user', '' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Braucht Rechte, um in Nextcloud Benutzer und Gruppen zu verwalten. Leer = der Buchhaltungs-Zugang.', 'vereinsplugin' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_nc_admin_pass"><?php esc_html_e( 'App-Passwort dieses Kontos', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_nc_admin_pass" id="vp_nc_admin_pass" type="password" class="regular-text" placeholder="<?php echo get_option( 'vp_nc_admin_pass' ) ? '••••••• (gespeichert)' : ''; ?>" autocomplete="new-password"></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_nc_group_map"><?php esc_html_e( 'Gruppen → Rollen', 'vereinsplugin' ); ?></label></th>
					<td>
						<textarea name="vp_nc_group_map" id="vp_nc_group_map" rows="6" class="large-text code" placeholder="Vorstand = editor&#10;Mitglieder = wl_mitglied&#10;Kassier = editor"><?php echo esc_textarea( get_option( 'vp_nc_group_map', "Vorstand = editor\nMitglieder = wl_mitglied" ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Je Zeile „NC-Gruppenname = WP-Rolle“. Reihenfolge = Priorität (obere gewinnt, wenn jemand in mehreren Gruppen ist). WP-Rollen u. a.: administrator, editor, wl_mitglied.', 'vereinsplugin' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Automatik', 'vereinsplugin' ); ?></th>
					<td><label><input type="checkbox" name="vp_nc_sync_enabled" value="1" <?php checked( get_option( 'vp_nc_sync_enabled' ) === '1' ); ?>>
						<?php esc_html_e( 'Stündlich automatisch synchronisieren (zusätzlich zum Button im Vorstandsbereich).', 'vereinsplugin' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Nextcloud-Login', 'vereinsplugin' ); ?></th>
					<td><label><input type="checkbox" name="vp_nc_sso" value="1" <?php checked( get_option( 'vp_nc_sso' ) === '1' ); ?>>
						<?php esc_html_e( 'Mitglieder dürfen sich in WordPress mit ihrem Nextcloud-Benutzernamen + Nextcloud-Passwort anmelden.', 'vereinsplugin' ); ?></label>
						<p class="description"><?php esc_html_e( 'Kein Server- oder Admin-Zugriff nötig. Das Passwort wird bei der Anmeldung direkt bei Nextcloud geprüft. Funktioniert nicht mit reiner 2-Faktor-Pflicht.', 'vereinsplugin' ); ?></p></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Mitgliedsanträge & Beitrag', 'vereinsplugin' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="vp_vorstand_email"><?php esc_html_e( 'Benachrichtigungs-E-Mail Vorstand', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_vorstand_email" id="vp_vorstand_email" type="email" class="regular-text" value="<?php echo esc_attr( get_option( 'vp_vorstand_email', get_option( 'admin_email' ) ) ); ?>">
						<p class="description"><?php esc_html_e( 'Bekommt jeden neuen Mitgliedsantrag.', 'vereinsplugin' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_beitrag_default"><?php esc_html_e( 'Standard-Beitrag (€)', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_beitrag_default" id="vp_beitrag_default" type="number" step="0.01" min="0" value="<?php echo esc_attr( get_option( 'vp_beitrag_default', '' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_beitrag_intervall"><?php esc_html_e( 'Standard-Intervall', 'vereinsplugin' ); ?></label></th>
					<td>
						<select name="vp_beitrag_intervall" id="vp_beitrag_intervall">
							<?php
							$iv  = get_option( 'vp_beitrag_intervall', 'jaehrlich' );
							foreach ( array(
								'monatlich'        => __( 'monatlich', 'vereinsplugin' ),
								'vierteljaehrlich' => __( 'vierteljährlich', 'vereinsplugin' ),
								'halbjaehrlich'    => __( 'halbjährlich', 'vereinsplugin' ),
								'jaehrlich'        => __( 'jährlich', 'vereinsplugin' ),
							) as $k => $label ) {
								printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $iv, $k, false ), esc_html( $label ) );
							}
							?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_sepa_glaeubiger"><?php esc_html_e( 'SEPA-Gläubiger (Vereinsname)', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_sepa_glaeubiger" id="vp_sepa_glaeubiger" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'vp_sepa_glaeubiger', get_bloginfo( 'name' ) ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_sepa_glaeubiger_id"><?php esc_html_e( 'SEPA-Gläubiger-ID', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_sepa_glaeubiger_id" id="vp_sepa_glaeubiger_id" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'vp_sepa_glaeubiger_id', '' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_sepa_bic"><?php esc_html_e( 'BIC des Vereinskontos', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_sepa_bic" id="vp_sepa_bic" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'vp_sepa_bic', get_option( 'wl_bic', '' ) ) ); ?>">
						<p class="description"><?php esc_html_e( 'Optional – bei fehlender BIC wird NOTPROVIDED übermittelt (SEPA-Inland).', 'vereinsplugin' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_sepa_zweck_vorlage"><?php esc_html_e( 'Verwendungszweck-Vorlage', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_sepa_zweck_vorlage" id="vp_sepa_zweck_vorlage" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'vp_sepa_zweck_vorlage', 'Mitgliedsbeitrag {jahr} - {name}' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Platzhalter: {jahr} {monat} {name} {mandatsref} {betrag}', 'vereinsplugin' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_sepa_mandatstext"><?php esc_html_e( 'Mandatstext (optional)', 'vereinsplugin' ); ?></label></th>
					<td><textarea name="vp_sepa_mandatstext" id="vp_sepa_mandatstext" rows="3" class="large-text"><?php echo esc_textarea( get_option( 'vp_sepa_mandatstext', '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Leer lassen für den Standardtext.', 'vereinsplugin' ); ?></p></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Verein als Aussteller (Rechnungen & Spendenbescheinigungen)', 'vereinsplugin' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="vp_org_name"><?php esc_html_e( 'Vereinsname', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_org_name" id="vp_org_name" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'vp_org_name', get_bloginfo( 'name' ) ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_org_anschrift"><?php esc_html_e( 'Anschrift', 'vereinsplugin' ); ?></label></th>
					<td><textarea name="vp_org_anschrift" id="vp_org_anschrift" rows="3" class="large-text"><?php echo esc_textarea( get_option( 'vp_org_anschrift', '' ) ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_org_ort"><?php esc_html_e( 'Ort (für die Unterschriftzeile)', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_org_ort" id="vp_org_ort" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'vp_org_ort', '' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_org_email"><?php esc_html_e( 'E-Mail (Fußzeile)', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_org_email" id="vp_org_email" type="email" class="regular-text" value="<?php echo esc_attr( get_option( 'vp_org_email', get_option( 'admin_email' ) ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_org_web"><?php esc_html_e( 'Website', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_org_web" id="vp_org_web" type="url" class="regular-text" value="<?php echo esc_attr( get_option( 'vp_org_web', home_url( '/' ) ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_org_vertreter"><?php esc_html_e( 'Zeichnungsberechtigte:r', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_org_vertreter" id="vp_org_vertreter" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'vp_org_vertreter', '' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_org_finanzamt"><?php esc_html_e( 'Finanzamt', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_org_finanzamt" id="vp_org_finanzamt" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'vp_org_finanzamt', '' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_org_steuernr"><?php esc_html_e( 'Steuernummer', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_org_steuernr" id="vp_org_steuernr" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'vp_org_steuernr', '' ) ); ?>"></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Rechnungen', 'vereinsplugin' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="vp_rechnung_prefix"><?php esc_html_e( 'Nummernkreis-Präfix', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_rechnung_prefix" id="vp_rechnung_prefix" type="text" class="small-text" value="<?php echo esc_attr( get_option( 'vp_rechnung_prefix', 'RE' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Ergibt Nummern wie RE-2026-001 (fortlaufend je Jahr).', 'vereinsplugin' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_rechnung_zahlungsziel"><?php esc_html_e( 'Zahlungsziel (Tage)', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_rechnung_zahlungsziel" id="vp_rechnung_zahlungsziel" type="number" min="0" class="small-text" value="<?php echo esc_attr( get_option( 'vp_rechnung_zahlungsziel', 14 ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_rechnung_einleitung"><?php esc_html_e( 'Standard-Einleitung', 'vereinsplugin' ); ?></label></th>
					<td><textarea name="vp_rechnung_einleitung" id="vp_rechnung_einleitung" rows="2" class="large-text"><?php echo esc_textarea( get_option( 'vp_rechnung_einleitung', '' ) ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_rechnung_schluss"><?php esc_html_e( 'Standard-Schlusstext', 'vereinsplugin' ); ?></label></th>
					<td><textarea name="vp_rechnung_schluss" id="vp_rechnung_schluss" rows="2" class="large-text"><?php echo esc_textarea( get_option( 'vp_rechnung_schluss', '' ) ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_rechnung_ust_hinweis"><?php esc_html_e( 'Hinweis ohne USt-Ausweis', 'vereinsplugin' ); ?></label></th>
					<td><textarea name="vp_rechnung_ust_hinweis" id="vp_rechnung_ust_hinweis" rows="2" class="large-text"><?php echo esc_textarea( get_option( 'vp_rechnung_ust_hinweis', 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.' ) ); ?></textarea></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Zuwendungsbestätigungen', 'vereinsplugin' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="vp_spende_konten"><?php esc_html_e( 'Spendenkonten', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_spende_konten" id="vp_spende_konten" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'vp_spende_konten', '4200,4210' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Kontonummern (Komma getrennt), deren Einnahmen als Zuwendung übernommen werden.', 'vereinsplugin' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_spende_zweck"><?php esc_html_e( 'Begünstigter Zweck', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_spende_zweck" id="vp_spende_zweck" type="text" class="regular-text" value="<?php echo esc_attr( get_option( 'vp_spende_zweck', 'der Jugendhilfe' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Wird im Satz „… wegen Förderung <Zweck> …" eingesetzt, z. B. „der Jugendhilfe".', 'vereinsplugin' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Rechtsgrundlage', 'vereinsplugin' ); ?></th>
					<td>
						<?php $bt = get_option( 'vp_spende_bescheid_typ', 'freistellung' ); ?>
						<label><input type="radio" name="vp_spende_bescheid_typ" value="freistellung" <?php checked( $bt, 'freistellung' ); ?>> <?php esc_html_e( 'Freistellungsbescheid / Anlage zum Körperschaftsteuerbescheid', 'vereinsplugin' ); ?></label><br>
						<label><input type="radio" name="vp_spende_bescheid_typ" value="60a" <?php checked( $bt, '60a' ); ?>> <?php esc_html_e( 'Feststellung nach § 60a AO', 'vereinsplugin' ); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_spende_bescheid_datum"><?php esc_html_e( 'Datum des Bescheids', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_spende_bescheid_datum" id="vp_spende_bescheid_datum" type="date" value="<?php echo esc_attr( get_option( 'vp_spende_bescheid_datum', '' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="vp_spende_veranlagungszeitraum"><?php esc_html_e( 'Letzter Veranlagungszeitraum', 'vereinsplugin' ); ?></label></th>
					<td><input name="vp_spende_veranlagungszeitraum" id="vp_spende_veranlagungszeitraum" type="text" class="small-text" value="<?php echo esc_attr( get_option( 'vp_spende_veranlagungszeitraum', '' ) ); ?>">
						<p class="description"><?php esc_html_e( 'Nur beim Freistellungsbescheid, z. B. „2022 – 2024".', 'vereinsplugin' ); ?></p></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Module', 'vereinsplugin' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php foreach ( vp_modules() as $key => $mod ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $mod['label'] ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="vp_modules[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( ! in_array( $key, $disabled, true ) ); ?>>
								<?php esc_html_e( 'aktiv', 'vereinsplugin' ); ?>
							</label>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>

			<h2><?php esc_html_e( 'Dashboard-Zugriff', 'vereinsplugin' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Mitglieder ins wp-admin lassen', 'vereinsplugin' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="vp_member_backend_access" value="1" <?php checked( get_option( 'vp_member_backend_access' ) === '1' ); ?>>
							<?php esc_html_e( 'Erlaubt reinen Mitgliedern das WordPress-Backend. Standard: aus (sie werden auf den Mitgliederbereich umgeleitet).', 'vereinsplugin' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Modul-Menüs wieder einblenden', 'vereinsplugin' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="vp_show_module_menus" value="1" <?php checked( get_option( 'vp_show_module_menus' ) === '1' ); ?>>
							<?php esc_html_e( 'Zeigt die alten Menüs der vier Module wieder an (nur für Debugging / Übergangszeit).', 'vereinsplugin' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<hr>
		<h2><?php esc_html_e( 'Technische Zugänge (Stage 1: separate Modul-Seiten)', 'vereinsplugin' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Diese umfangreichen Konfigurationen liegen bis Stage 2 noch auf den ursprünglichen Modul-Seiten. Sie sind aus dem Menü ausgeblendet, aber hier direkt erreichbar:', 'vereinsplugin' ); ?></p>
		<ul style="list-style:disc;margin-left:20px">
			<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=jufobleibt-event-publisher-settings' ) ); ?>"><?php esc_html_e( 'Veranstaltungs-Publisher: Social-Media- & Presse-Zugänge', 'vereinsplugin' ); ?></a></li>
			<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=jb_settings' ) ); ?>"><?php esc_html_e( 'Buchhaltung: Nextcloud-Belegablage & Kassier-Einstellungen', 'vereinsplugin' ); ?></a></li>
			<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=pp-app' ) ); ?>"><?php esc_html_e( 'Protokolle: App (PWA) & Nextcloud-Deep-Links', 'vereinsplugin' ); ?></a></li>
			<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=wunschliste-gastcodes' ) ); ?>"><?php esc_html_e( 'Abstimmung: Gast-Codes', 'vereinsplugin' ); ?></a></li>
		</ul>
	</div>
	<?php
}
