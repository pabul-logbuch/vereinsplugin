<?php
/**
 * Rechte im Vorstand: Kassenrecht und Vier-Augen-Prinzip bei Auslagen.
 *
 * Kassenrecht
 *   Standard: der ganze Vorstand (Redakteur:innen) darf buchen – wie bisher.
 *   Schaltet ein:e Administrator:in unter Verein → Einstellungen „Kassenrecht
 *   nur für Kassenwart:innen“ ein, behalten nur Administrator:innen und die
 *   dort ausgewählten Personen die schreibenden Kassenrechte. Der übrige
 *   Vorstand sieht Journal, Auswertungen und Auslagen weiter, kann aber nichts
 *   buchen, ändern, löschen oder genehmigen.
 *   Ausgewählte Kassenwart:innen bekommen die Kassenrechte auch dann, wenn sie
 *   sonst nur Mitglied sind.
 *
 * Eigene Auslagen
 *   Einstellung „Eigene Auslagen selbst genehmigen“ (Standard: erlaubt). Ist
 *   sie aus, muss eine andere Person die Auslage genehmigen oder ablehnen.
 */

defined( 'ABSPATH' ) || exit;

/** Schreibende Kassenrechte. */
function vp_kasse_schreibrechte() {
	return array( 'jb_edit_journal', 'jb_approve_auslagen', 'jb_mark_paid', 'jb_manage_settings' );
}

/** Leserechte, die Kassenwart:innen zusätzlich bekommen. */
function vp_kasse_leserechte() {
	return array( 'jb_view_journal', 'jb_view_auslagen', 'jb_export', 'jb_submit_auslagen', 'jb_view_own_auslagen' );
}

function vp_kasse_eingeschraenkt() {
	return '1' === get_option( 'vp_kasse_nur_kassenwart', '0' );
}

/** IDs der ausgewählten Kassenwart:innen. */
function vp_kassenwart_ids() {
	return array_values( array_filter( array_map( 'intval', (array) get_option( 'vp_kassenwart_ids', array() ) ) ) );
}

function vp_auslage_selbst_genehmigen_erlaubt() {
	return '0' !== get_option( 'vp_auslage_selbst_genehmigen', '1' );
}

/**
 * Rechte zur Laufzeit anpassen (ohne die WordPress-Rollen umzuschreiben –
 * Ausschalten stellt den alten Zustand sofort wieder her).
 */
add_filter( 'user_has_cap', 'vp_kasse_user_has_cap', 20, 4 );
function vp_kasse_user_has_cap( $allcaps, $caps, $args, $user ) {
	if ( ! $user || empty( $user->ID ) ) {
		return $allcaps;
	}
	if ( in_array( (int) $user->ID, vp_kassenwart_ids(), true ) ) {
		foreach ( array_merge( vp_kasse_schreibrechte(), vp_kasse_leserechte() ) as $c ) {
			$allcaps[ $c ] = true;
		}
		return $allcaps;
	}
	if ( vp_kasse_eingeschraenkt() && empty( $allcaps['manage_options'] ) ) {
		foreach ( vp_kasse_schreibrechte() as $c ) {
			$allcaps[ $c ] = false;
		}
	}
	return $allcaps;
}

/** Darf der aktuelle Benutzer in der Kasse buchen? */
function vp_kasse_darf_buchen() {
	return current_user_can( 'jb_edit_journal' ) || current_user_can( 'manage_options' );
}

/**
 * Für Bereiche, die sonst nur das Leserecht prüfen (Rechnungen, SEPA,
 * Spenden): ohne Kassenrecht werden Formulare nicht verarbeitet.
 * @return string Hinweis-HTML (leer = darf buchen)
 */
function vp_kasse_nur_lesen_hinweis() {
	if ( vp_kasse_darf_buchen() ) {
		return '';
	}
	$hinweis = '<div class="vp-note">' . esc_html__( 'Nur Lesezugriff: Buchen und Ändern dürfen hier nur Kassenwart:innen und Administrator:innen.', 'vereinsplugin' ) . '</div>';
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! empty( $_POST ) ) {
		$_POST = array(); // nichts verarbeiten
		$hinweis = '<div class="vp-note vp-note-error">' . esc_html__( 'Nicht gespeichert – dafür fehlt das Kassenrecht.', 'vereinsplugin' ) . '</div>';
	}
	return $hinweis;
}

/**
 * Sync-Tabellen der Desktop-App, die nur mit Kassenrecht beschrieben werden
 * dürfen (Lesen bleibt wie bisher).
 */
function vp_kasse_sync_tabellen() {
	return array(
		'jb_buchungen'        => 'jb_edit_journal',
		'jb_budgets'          => 'jb_edit_journal',
		'jb_ruecklagen'       => 'jb_edit_journal',
		'jb_anfangsbestaende' => 'jb_edit_journal',
		'jb_geschaeftsjahre'  => 'jb_edit_journal',
		'jb_konten'           => 'jb_edit_journal',
		'jb_konto_regeln'     => 'jb_edit_journal',
		'jb_auslagen'         => 'jb_approve_auslagen',
	);
}

/** Darf der aktuelle Benutzer diese Sync-Tabelle beschreiben? */
function vp_kasse_sync_schreiben_ok( $slug ) {
	$t = vp_kasse_sync_tabellen();
	if ( ! isset( $t[ $slug ] ) ) {
		return true;
	}
	return current_user_can( $t[ $slug ] ) || current_user_can( 'manage_options' );
}

/* =========================================================================
 * Eigene Auslagen
 * ====================================================================== */

/** Darf der aktuelle Benutzer über diese Auslage entscheiden? */
function vp_auslage_entscheiden_ok( $auslage ) {
	if ( vp_auslage_selbst_genehmigen_erlaubt() ) {
		return true;
	}
	$uid = is_array( $auslage ) ? (int) ( $auslage['user_id'] ?? 0 ) : (int) ( $auslage->user_id ?? 0 );
	return $uid !== get_current_user_id();
}

function vp_auslage_selbst_text() {
	return __( 'Eigene Auslage – genehmigen oder ablehnen muss eine andere Person aus dem Vorstand.', 'vereinsplugin' );
}

/* =========================================================================
 * Einstellungen (Verein → Einstellungen, nur Administrator:innen)
 * ====================================================================== */

add_action( 'admin_init', 'vp_vorstand_rechte_speichern', 9 );
function vp_vorstand_rechte_speichern() {
	if ( ! isset( $_POST['vp_save_settings'] ) || ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'vp_settings' ) ) {
		return;
	}
	update_option( 'vp_kasse_nur_kassenwart', isset( $_POST['vp_kasse_nur_kassenwart'] ) ? '1' : '0' );
	update_option( 'vp_kassenwart_ids', array_values( array_filter( array_map( 'intval', (array) ( $_POST['vp_kassenwart_ids'] ?? array() ) ) ) ), false );
	update_option( 'vp_auslage_selbst_genehmigen', isset( $_POST['vp_auslage_selbst_genehmigen'] ) ? '1' : '0' );
}

/** Abschnitt für die Einstellungsseite. */
function vp_vorstand_rechte_einstellungen_html() {
	$kw       = vp_kassenwart_ids();
	$personen = get_users( array(
		'role__in' => array( 'editor', VP_MEMBER_ROLE ),
		'orderby'  => 'display_name',
		'fields'   => array( 'ID', 'display_name' ),
	) );
	$vorstand = array();
	foreach ( get_users( array( 'role' => 'editor', 'fields' => 'ID' ) ) as $id ) {
		$vorstand[ (int) $id ] = true;
	}
	ob_start();
	?>
	<h2><?php esc_html_e( 'Rechte im Vorstand', 'vereinsplugin' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Vorstand = WordPress-Rolle „Redakteur“. Administrator:innen haben immer alle Rechte.', 'vereinsplugin' ); ?></p>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Kassenrecht', 'vereinsplugin' ); ?></th>
			<td>
				<label><input type="checkbox" name="vp_kasse_nur_kassenwart" value="1" <?php checked( vp_kasse_eingeschraenkt() ); ?>>
					<?php esc_html_e( 'Nur Kassenwart:innen dürfen buchen', 'vereinsplugin' ); ?></label>
				<p class="description"><?php esc_html_e( 'Aus: der ganze Vorstand darf buchen (wie bisher). An: Buchen, Ändern, Löschen, Bank-Import, Z-Bon, Auslagen genehmigen/auszahlen, Rechnungen, SEPA-Läufe und Spendenquittungen nur noch für die unten gewählten Personen und Administrator:innen. Der übrige Vorstand sieht alles weiter.', 'vereinsplugin' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="vp_kassenwart_ids"><?php esc_html_e( 'Kassenwart:innen', 'vereinsplugin' ); ?></label></th>
			<td>
				<select name="vp_kassenwart_ids[]" id="vp_kassenwart_ids" multiple size="8" style="min-width:320px">
					<?php foreach ( $personen as $p ) : ?>
						<option value="<?php echo (int) $p->ID; ?>" <?php selected( in_array( (int) $p->ID, $kw, true ) ); ?>>
							<?php echo esc_html( $p->display_name . ( isset( $vorstand[ (int) $p->ID ] ) ? ' (Vorstand)' : '' ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Mehrfachauswahl mit Strg/⌘. Ausgewählte Personen haben die Kassenrechte immer – auch wenn sie nur Mitglied sind. Erst Personen wählen, dann den Schalter oben einschalten.', 'vereinsplugin' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Eigene Auslagen', 'vereinsplugin' ); ?></th>
			<td>
				<label><input type="checkbox" name="vp_auslage_selbst_genehmigen" value="1" <?php checked( vp_auslage_selbst_genehmigen_erlaubt() ); ?>>
					<?php esc_html_e( 'Eigene Auslagen selbst genehmigen erlauben', 'vereinsplugin' ); ?></label>
				<p class="description"><?php esc_html_e( 'Aus = Vier-Augen-Prinzip: Eine Auslage, die jemand selbst eingereicht hat, muss eine andere Person genehmigen oder ablehnen (auch in Kreis-Kassen und der Desktop-App).', 'vereinsplugin' ); ?></p>
			</td>
		</tr>
	</table>
	<?php
	return ob_get_clean();
}
