<?php
/**
 * Kern-Erweiterung des Buchhaltungs-Moduls:
 *  - Schema-Nachrüstung für Bestandsinstallationen (Budget ↔ Mitglied,
 *    Kostenstelle/Konto, Auslage ↔ Budget).
 *  - Frontend-Sektion „Budgets“ im Vorstandsbereich (planen, Mitglied
 *    zuweisen, Verbrauch sehen).
 *
 * Wird nur aktiv, wenn das Buchhaltungs-Modul geladen ist.
 */

defined( 'ABSPATH' ) || exit;

define( 'VP_BUCH_DB_VERSION', '1' );

add_action( 'plugins_loaded', 'vp_buch_maybe_upgrade', 6 );
function vp_buch_maybe_upgrade() {
	if ( ! function_exists( 'jb_table_budgets' ) ) {
		return; // Modul nicht aktiv.
	}
	if ( get_option( 'vp_buch_db_version' ) === VP_BUCH_DB_VERSION ) {
		return;
	}
	global $wpdb;

	$add = function ( $table, $column, $definition ) use ( $wpdb ) {
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
			$table,
			$column
		) );
		if ( ! $exists ) {
			// $table / $definition stammen aus dem Code, nicht aus Nutzereingaben.
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN {$definition}" );
		}
	};

	$budgets  = jb_table_budgets();
	$auslagen = function_exists( 'jb_table_auslagen' ) ? jb_table_auslagen() : $wpdb->prefix . 'jb_auslagen';

	$add( $budgets, 'verantwortlich_user_id', '`verantwortlich_user_id` BIGINT UNSIGNED DEFAULT NULL' );
	$add( $budgets, 'jahr', '`jahr` SMALLINT DEFAULT NULL' );
	$add( $budgets, 'kostenstelle', "`kostenstelle` VARCHAR(50) DEFAULT ''" );
	$add( $budgets, 'konto', "`konto` VARCHAR(20) DEFAULT ''" );
	$add( $auslagen, 'budget_id', '`budget_id` BIGINT UNSIGNED DEFAULT NULL' );

	update_option( 'vp_buch_db_version', VP_BUCH_DB_VERSION );
}

/* -------------------------------------------------------------------------
 * Sektion „Budgets“
 * ---------------------------------------------------------------------- */

function vp_render_budgets_section() {
	if ( ! current_user_can( 'jb_view_journal' ) || ! function_exists( 'jb_budgets_get_all' ) ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Nicht verfügbar.', 'vereinsplugin' ) . '</div>';
	}
	$can_edit = current_user_can( 'jb_edit_journal' ) || current_user_can( 'jb_approve_auslagen' ) || current_user_can( 'manage_options' );

	global $wpdb;
	$msg = '';

	if ( $can_edit && isset( $_POST['vp_budget_save'] ) && check_admin_referer( 'vp_budget', 'vp_budget_nonce' ) ) {
		if ( function_exists( 'jb_budget_save' ) ) {
			jb_budget_save( array(
				'id'                     => (int) ( $_POST['id'] ?? 0 ),
				'zweck'                  => wp_unslash( $_POST['zweck'] ?? '' ),
				'beschreibung'           => wp_unslash( $_POST['beschreibung'] ?? '' ),
				'betrag'                 => wp_unslash( $_POST['betrag'] ?? '0' ),
				'jahr'                   => (int) ( $_POST['jahr'] ?? 0 ),
				'kostenstelle'           => wp_unslash( $_POST['kostenstelle'] ?? '' ),
				'konto'                  => wp_unslash( $_POST['konto'] ?? '' ),
				'verantwortlich_user_id' => (int) ( $_POST['verantwortlich_user_id'] ?? 0 ),
				'notiz'                  => wp_unslash( $_POST['notiz'] ?? '' ),
			) );
			$msg = __( 'Budget gespeichert.', 'vereinsplugin' );
		}
	}
	if ( $can_edit && isset( $_POST['vp_budget_delete'] ) && check_admin_referer( 'vp_budget', 'vp_budget_nonce' ) && function_exists( 'jb_budget_delete' ) ) {
		jb_budget_delete( (int) $_POST['id'] );
		$msg = __( 'Budget deaktiviert.', 'vereinsplugin' );
	}

	$budgets = jb_budgets_get_all();
	$members = get_users( array( 'role__in' => array( VP_MEMBER_ROLE, 'pp_mitglied', 'administrator', 'editor' ), 'orderby' => 'display_name' ) );

	$edit_id  = isset( $_GET['vp_budget_edit'] ) ? (int) $_GET['vp_budget_edit'] : 0;
	$edit_row = null;
	foreach ( $budgets as $b ) {
		if ( (int) ( (object) $b )->id === $edit_id ) {
			$edit_row = (object) $b;
		}
	}

	$base = get_permalink() ?: remove_query_arg( array( 'vp_budget_edit' ) );

	ob_start();
	echo '<h2>' . esc_html__( 'Budgets & Kostenstellen', 'vereinsplugin' ) . '</h2>';
	if ( $msg ) {
		echo '<div class="vp-note">' . esc_html( $msg ) . '</div>';
	}

	// Tabelle.
	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr>'
		. '<th>' . esc_html__( 'Zweck', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Jahr', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Verantwortlich', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Konto/KSt', 'vereinsplugin' ) . '</th>'
		. '<th style="text-align:right">' . esc_html__( 'Geplant', 'vereinsplugin' ) . '</th>'
		. '<th style="text-align:right">' . esc_html__( 'Verbraucht', 'vereinsplugin' ) . '</th>'
		. '<th style="text-align:right">' . esc_html__( 'Rest', 'vereinsplugin' ) . '</th>'
		. ( $can_edit ? '<th></th>' : '' )
		. '</tr></thead><tbody>';

	foreach ( $budgets as $b ) {
		$b    = (object) $b;
		$rest = (float) $b->betrag - (float) $b->ausgegeben;
		$who  = $b->verantwortlich_user_id ? get_userdata( $b->verantwortlich_user_id ) : null;
		printf(
			'<tr><td><strong>%s</strong><br><span class="vp-muted">%s</span></td><td>%s</td><td>%s</td><td>%s</td><td style="text-align:right">%s €</td><td style="text-align:right">%s €</td><td style="text-align:right;%s">%s €</td>%s</tr>',
			esc_html( $b->zweck ),
			esc_html( wp_trim_words( (string) $b->beschreibung, 12 ) ),
			$b->jahr ? (int) $b->jahr : '–',
			$who ? esc_html( $who->display_name ) : '<span class="vp-muted">–</span>',
			esc_html( trim( ( $b->konto ? $b->konto : '' ) . ' ' . ( $b->kostenstelle ? $b->kostenstelle : '' ) ) ?: '–' ),
			esc_html( number_format( (float) $b->betrag, 2, ',', '.' ) ),
			esc_html( number_format( (float) $b->ausgegeben, 2, ',', '.' ) ),
			$rest < 0 ? 'color:#b91c1c;font-weight:700' : '',
			esc_html( number_format( $rest, 2, ',', '.' ) ),
			$can_edit ? '<td><a class="vp-btn" href="' . esc_url( add_query_arg( 'vp_budget_edit', (int) $b->id, $base ) ) . '#vp-budget-form">' . esc_html__( 'Bearbeiten', 'vereinsplugin' ) . '</a></td>' : ''
		);
	}
	if ( ! $budgets ) {
		echo '<tr><td colspan="8" class="vp-muted">' . esc_html__( 'Noch keine Budgets.', 'vereinsplugin' ) . '</td></tr>';
	}
	echo '</tbody></table></div>';

	if ( ! $can_edit ) {
		return ob_get_clean();
	}

	// Formular.
	$val = function ( $k, $d = '' ) use ( $edit_row ) {
		return esc_attr( $edit_row->$k ?? $d );
	};
	?>
	<h3 id="vp-budget-form"><?php echo $edit_row ? esc_html__( 'Budget bearbeiten', 'vereinsplugin' ) : esc_html__( 'Neues Budget', 'vereinsplugin' ); ?></h3>
	<form method="post" class="vp-form vp-card">
		<?php wp_nonce_field( 'vp_budget', 'vp_budget_nonce' ); ?>
		<input type="hidden" name="id" value="<?php echo $edit_row ? (int) $edit_row->id : 0; ?>">
		<div class="vp-form-grid">
			<label class="vp-col-2"><?php esc_html_e( 'Zweck *', 'vereinsplugin' ); ?>
				<input type="text" name="zweck" required value="<?php echo $val( 'zweck' ); ?>"></label>
			<label><?php esc_html_e( 'Jahr', 'vereinsplugin' ); ?>
				<input type="number" name="jahr" min="2000" max="2100" value="<?php echo $val( 'jahr', (int) gmdate( 'Y' ) ); ?>"></label>
			<label><?php esc_html_e( 'Verplanter Betrag (€)', 'vereinsplugin' ); ?>
				<input type="number" step="0.01" min="0" name="betrag" value="<?php echo $val( 'betrag' ); ?>"></label>
			<label><?php esc_html_e( 'Verantwortliches Mitglied', 'vereinsplugin' ); ?>
				<select name="verantwortlich_user_id">
					<option value="0">– <?php esc_html_e( 'niemand', 'vereinsplugin' ); ?> –</option>
					<?php foreach ( $members as $mem ) : ?>
						<option value="<?php echo (int) $mem->ID; ?>" <?php selected( (int) ( $edit_row->verantwortlich_user_id ?? 0 ), $mem->ID ); ?>>
							<?php echo esc_html( $mem->display_name ); ?>
						</option>
					<?php endforeach; ?>
				</select></label>
			<label><?php esc_html_e( 'SKR-Konto (optional)', 'vereinsplugin' ); ?>
				<input type="text" name="konto" placeholder="z. B. 4980" value="<?php echo $val( 'konto' ); ?>"></label>
			<label><?php esc_html_e( 'Kostenstelle (optional)', 'vereinsplugin' ); ?>
				<input type="text" name="kostenstelle" placeholder="z. B. FOA26" value="<?php echo $val( 'kostenstelle' ); ?>"></label>
			<label class="vp-col-2"><?php esc_html_e( 'Beschreibung', 'vereinsplugin' ); ?>
				<textarea name="beschreibung" rows="2"><?php echo esc_textarea( $edit_row->beschreibung ?? '' ); ?></textarea></label>
			<label class="vp-col-2"><?php esc_html_e( 'Interne Notiz', 'vereinsplugin' ); ?>
				<textarea name="notiz" rows="2"><?php echo esc_textarea( $edit_row->notiz ?? '' ); ?></textarea></label>
		</div>
		<p>
			<button class="vp-btn vp-btn-primary" name="vp_budget_save" value="1"><?php esc_html_e( 'Speichern', 'vereinsplugin' ); ?></button>
			<?php if ( $edit_row ) : ?>
				<a class="vp-btn" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Abbrechen', 'vereinsplugin' ); ?></a>
				<button class="vp-btn vp-btn-danger" name="vp_budget_delete" value="1" onclick="return confirm('<?php echo esc_js( __( 'Budget wirklich deaktivieren?', 'vereinsplugin' ) ); ?>')"><?php esc_html_e( 'Deaktivieren', 'vereinsplugin' ); ?></button>
			<?php endif; ?>
		</p>
		<p class="vp-muted"><?php esc_html_e( '„Verbraucht“ zählt automatisch hoch, sobald eine zugeordnete Auslage als ausgezahlt markiert wird.', 'vereinsplugin' ); ?></p>
	</form>
	<?php

	// Rücklagen (wiederkehrende Kosten) direkt darunter.
	if ( function_exists( 'vp_bh_ruecklagen' ) ) {
		echo '<h2 style="margin-top:32px">' . esc_html__( 'Rücklagen für wiederkehrende Kosten', 'vereinsplugin' ) . '</h2>';
		echo vp_bh_ruecklagen(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	return ob_get_clean();
}
