<?php
/**
 * Kern: Ausgangsrechnungen.
 *
 *   vp_rechnungen            – Kopfdaten
 *   vp_rechnung_positionen   – Positionen (Menge × Einzelpreis, optional USt)
 *
 * Ablauf: Rechnung erfassen (Entwurf) → „festschreiben" vergibt die Nummer und
 * setzt den Status auf „offen" → per Mail versenden oder drucken → bei
 * Zahlungseingang „bezahlt" setzen (schreibt die Einnahme ins Journal) oder
 * über einen SEPA-Lauf einziehen (bucht dann automatisch).
 *
 * Vereine sind meist Kleinunternehmer bzw. buchen im ideellen Bereich ohne
 * Umsatzsteuer – der USt-Ausweis ist deshalb pro Rechnung abschaltbar und
 * standardmäßig aus. Ein Hinweistext (§ 19 UStG o. Ä.) kommt aus den
 * Einstellungen.
 */

defined( 'ABSPATH' ) || exit;

define( 'VP_RECHNUNG_DB_VERSION', '1' );

function vp_rechnung_table()     { global $wpdb; return $wpdb->prefix . 'vp_rechnungen'; }
function vp_rechnung_pos_table() { global $wpdb; return $wpdb->prefix . 'vp_rechnung_positionen'; }

add_action( 'plugins_loaded', 'vp_rechnungen_maybe_upgrade', 6 );
function vp_rechnungen_maybe_upgrade() {
	if ( get_option( 'vp_rechnung_db_version' ) === VP_RECHNUNG_DB_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$collate = $wpdb->get_charset_collate();

	dbDelta( 'CREATE TABLE ' . vp_rechnung_table() . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		nummer VARCHAR(30) NOT NULL DEFAULT '',
		typ VARCHAR(12) NOT NULL DEFAULT 'ausgang',
		datum DATE DEFAULT NULL,
		faellig_am DATE DEFAULT NULL,
		user_id BIGINT UNSIGNED DEFAULT NULL,
		mandat_id BIGINT UNSIGNED DEFAULT NULL,
		empfaenger_name VARCHAR(190) NOT NULL DEFAULT '',
		empfaenger_anschrift TEXT NULL,
		empfaenger_email VARCHAR(190) NOT NULL DEFAULT '',
		betreff VARCHAR(190) NOT NULL DEFAULT '',
		einleitung TEXT NULL,
		schluss TEXT NULL,
		netto DECIMAL(10,2) NOT NULL DEFAULT 0,
		ust DECIMAL(10,2) NOT NULL DEFAULT 0,
		summe DECIMAL(10,2) NOT NULL DEFAULT 0,
		ust_ausweisen TINYINT NOT NULL DEFAULT 0,
		status VARCHAR(16) NOT NULL DEFAULT 'entwurf',
		zahlart VARCHAR(16) NOT NULL DEFAULT 'ueberweisung',
		konto VARCHAR(10) NOT NULL DEFAULT '',
		kostenstelle VARCHAR(50) NOT NULL DEFAULT '',
		budget_id BIGINT UNSIGNED DEFAULT NULL,
		buchung_id BIGINT UNSIGNED DEFAULT NULL,
		bezahlt_am DATE DEFAULT NULL,
		gesendet_am DATETIME NULL,
		notiz TEXT NULL,
		erstellt_am DATETIME NULL,
		erstellt_von BIGINT UNSIGNED DEFAULT NULL,
		geaendert_am DATETIME NULL,
		PRIMARY KEY  (id),
		KEY nummer (nummer),
		KEY status (status)
	) {$collate};" );

	dbDelta( 'CREATE TABLE ' . vp_rechnung_pos_table() . " (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		rechnung_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		pos INT NOT NULL DEFAULT 0,
		bezeichnung VARCHAR(255) NOT NULL DEFAULT '',
		menge DECIMAL(10,3) NOT NULL DEFAULT 1,
		einheit VARCHAR(20) NOT NULL DEFAULT '',
		einzelpreis DECIMAL(10,2) NOT NULL DEFAULT 0,
		ust_satz DECIMAL(5,2) NOT NULL DEFAULT 0,
		konto VARCHAR(10) NOT NULL DEFAULT '',
		betrag DECIMAL(10,2) NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY rechnung_id (rechnung_id)
	) {$collate};" );

	update_option( 'vp_rechnung_db_version', VP_RECHNUNG_DB_VERSION );
}

/* =========================================================================
 * Helfer
 * ====================================================================== */

function vp_rechnung_can() {
	return current_user_can( 'jb_view_journal' ) || current_user_can( 'manage_options' );
}

function vp_rechnung_get( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . vp_rechnung_table() . ' WHERE id = %d', (int) $id ) );
}

function vp_rechnung_positionen( $id ) {
	global $wpdb;
	return $wpdb->get_results( $wpdb->prepare(
		'SELECT * FROM ' . vp_rechnung_pos_table() . ' WHERE rechnung_id = %d ORDER BY pos, id', (int) $id
	) );
}

/** Fortlaufende Rechnungsnummer (Schema PREFIX-JJJJ-NNN, lückenlos je Jahr). */
function vp_rechnung_next_nummer( $jahr = '' ) {
	global $wpdb;
	$jahr   = $jahr ?: current_time( 'Y' );
	$prefix = (string) get_option( 'vp_rechnung_prefix', 'RE' );
	$like   = $wpdb->esc_like( $prefix . '-' . $jahr . '-' ) . '%';
	$max    = (int) $wpdb->get_var( $wpdb->prepare(
		'SELECT MAX(CAST(SUBSTRING_INDEX(nummer, %s, -1) AS UNSIGNED)) FROM ' . vp_rechnung_table() . ' WHERE nummer LIKE %s',
		'-', $like
	) );
	return $prefix . '-' . $jahr . '-' . str_pad( (string) ( $max + 1 ), 3, '0', STR_PAD_LEFT );
}

/**
 * Rechnung speichern (Kopf + Positionen).
 *
 * @param array $d           Kopfdaten, `id` = 0 legt neu an.
 * @param array $positionen  Liste aus bezeichnung, menge, einheit, einzelpreis, ust_satz, konto.
 * @return int|WP_Error
 */
function vp_rechnung_save( array $d, array $positionen = null ) {
	global $wpdb;
	$id = (int) ( $d['id'] ?? 0 );

	$row = array(
		'datum'                => vp_sepa_norm_date( $d['datum'] ?? '' ) ?: current_time( 'Y-m-d' ),
		'user_id'              => ! empty( $d['user_id'] ) ? (int) $d['user_id'] : null,
		'mandat_id'            => ! empty( $d['mandat_id'] ) ? (int) $d['mandat_id'] : null,
		'empfaenger_name'      => sanitize_text_field( (string) ( $d['empfaenger_name'] ?? '' ) ),
		'empfaenger_anschrift' => sanitize_textarea_field( (string) ( $d['empfaenger_anschrift'] ?? '' ) ),
		'empfaenger_email'     => sanitize_email( (string) ( $d['empfaenger_email'] ?? '' ) ),
		'betreff'              => sanitize_text_field( (string) ( $d['betreff'] ?? '' ) ),
		'einleitung'           => sanitize_textarea_field( (string) ( $d['einleitung'] ?? '' ) ),
		'schluss'              => sanitize_textarea_field( (string) ( $d['schluss'] ?? '' ) ),
		'ust_ausweisen'        => empty( $d['ust_ausweisen'] ) ? 0 : 1,
		'zahlart'              => in_array( ( $d['zahlart'] ?? 'ueberweisung' ), array( 'ueberweisung', 'lastschrift', 'bar' ), true ) ? $d['zahlart'] : 'ueberweisung',
		'konto'                => sanitize_text_field( (string) ( $d['konto'] ?? '' ) ),
		'kostenstelle'         => sanitize_text_field( (string) ( $d['kostenstelle'] ?? '' ) ),
		'budget_id'            => ! empty( $d['budget_id'] ) ? (int) $d['budget_id'] : null,
		'notiz'                => sanitize_textarea_field( (string) ( $d['notiz'] ?? '' ) ),
		'geaendert_am'         => current_time( 'mysql' ),
	);
	$ziel = (int) get_option( 'vp_rechnung_zahlungsziel', 14 );
	$row['faellig_am'] = vp_sepa_norm_date( $d['faellig_am'] ?? '' ) ?: gmdate( 'Y-m-d', strtotime( $row['datum'] . ' +' . $ziel . ' days' ) );

	if ( '' === $row['empfaenger_name'] ) {
		return new WP_Error( 'bad_req', __( 'Empfänger:in ist Pflicht.', 'vereinsplugin' ) );
	}

	if ( $id ) {
		$alt = vp_rechnung_get( $id );
		if ( $alt && in_array( $alt->status, array( 'bezahlt', 'storniert' ), true ) ) {
			return new WP_Error( 'locked', __( 'Bezahlte oder stornierte Rechnungen sind gesperrt.', 'vereinsplugin' ) );
		}
		$wpdb->update( vp_rechnung_table(), $row, array( 'id' => $id ) );
	} else {
		$row['status']       = 'entwurf';
		$row['nummer']       = '';
		$row['erstellt_am']  = current_time( 'mysql' );
		$row['erstellt_von'] = get_current_user_id();
		$wpdb->insert( vp_rechnung_table(), $row );
		$id = (int) $wpdb->insert_id;
	}
	if ( ! $id ) {
		return new WP_Error( 'db', __( 'Rechnung konnte nicht gespeichert werden.', 'vereinsplugin' ) );
	}

	if ( null !== $positionen ) {
		$wpdb->delete( vp_rechnung_pos_table(), array( 'rechnung_id' => $id ) );
		$i = 0;
		foreach ( $positionen as $p ) {
			$bez = sanitize_text_field( (string) ( $p['bezeichnung'] ?? '' ) );
			$menge = (float) str_replace( ',', '.', (string) ( $p['menge'] ?? 1 ) );
			$preis = (float) str_replace( ',', '.', (string) ( $p['einzelpreis'] ?? 0 ) );
			if ( '' === $bez && 0.0 === $preis ) {
				continue;
			}
			$i++;
			$wpdb->insert( vp_rechnung_pos_table(), array(
				'rechnung_id' => $id,
				'pos'         => $i,
				'bezeichnung' => $bez,
				'menge'       => $menge,
				'einheit'     => sanitize_text_field( (string) ( $p['einheit'] ?? '' ) ),
				'einzelpreis' => round( $preis, 2 ),
				'ust_satz'    => (float) str_replace( ',', '.', (string) ( $p['ust_satz'] ?? 0 ) ),
				'konto'       => sanitize_text_field( (string) ( $p['konto'] ?? '' ) ),
				'betrag'      => round( $menge * $preis, 2 ),
			) );
		}
	}
	vp_rechnung_recalc( $id );
	return $id;
}

function vp_rechnung_recalc( $id ) {
	global $wpdb;
	$r = vp_rechnung_get( $id );
	if ( ! $r ) {
		return;
	}
	$netto = 0.0;
	$ust   = 0.0;
	foreach ( vp_rechnung_positionen( $id ) as $p ) {
		$b = round( (float) $p->menge * (float) $p->einzelpreis, 2 );
		$netto += $b;
		if ( $r->ust_ausweisen ) {
			$ust += round( $b * (float) $p->ust_satz / 100, 2 );
		}
	}
	$wpdb->update( vp_rechnung_table(), array(
		'netto' => round( $netto, 2 ),
		'ust'   => round( $ust, 2 ),
		'summe' => round( $netto + $ust, 2 ),
	), array( 'id' => (int) $id ) );
}

/** Entwurf festschreiben: Nummer vergeben, Status „offen". */
function vp_rechnung_festschreiben( $id ) {
	global $wpdb;
	$r = vp_rechnung_get( $id );
	if ( ! $r ) {
		return new WP_Error( 'not_found', __( 'Rechnung nicht gefunden.', 'vereinsplugin' ) );
	}
	if ( 'entwurf' !== $r->status ) {
		return new WP_Error( 'state', __( 'Nur Entwürfe können festgeschrieben werden.', 'vereinsplugin' ) );
	}
	if ( ! vp_rechnung_positionen( $id ) ) {
		return new WP_Error( 'empty', __( 'Die Rechnung hat keine Positionen.', 'vereinsplugin' ) );
	}
	$nummer = $r->nummer ?: vp_rechnung_next_nummer( substr( (string) $r->datum, 0, 4 ) );
	$wpdb->update( vp_rechnung_table(), array( 'nummer' => $nummer, 'status' => 'offen' ), array( 'id' => (int) $id ) );
	return $nummer;
}

/**
 * Rechnung als bezahlt markieren und die Einnahme ins Journal schreiben.
 *
 * @param int    $id
 * @param string $datum      Zahlungsdatum.
 * @param int    $buchung_id Bereits vorhandene Buchung (z. B. aus dem SEPA-Lauf).
 * @param string $quelle     Geld-Topf, wenn selbst gebucht wird.
 */
function vp_rechnung_mark_bezahlt( $id, $datum = '', $buchung_id = 0, $quelle = 'Bank KSK' ) {
	global $wpdb;
	$r = vp_rechnung_get( $id );
	if ( ! $r ) {
		return new WP_Error( 'not_found', __( 'Rechnung nicht gefunden.', 'vereinsplugin' ) );
	}
	if ( 'bezahlt' === $r->status ) {
		return (int) $r->buchung_id;
	}
	$datum = vp_sepa_norm_date( $datum ) ?: current_time( 'Y-m-d' );
	$bid   = (int) $buchung_id;

	if ( ! $bid && function_exists( 'jb_journal_add' ) ) {
		$konto = $r->konto ?: '4500';
		$bid   = (int) jb_journal_add( array(
			'buchung_datum'  => $datum,
			'betrag'         => round( (float) $r->summe, 2 ),
			'konto'          => $konto,
			'sphaere'        => function_exists( 'jb_konto_sphaere' ) ? jb_konto_sphaere( $konto ) : '',
			'kategorie'      => $konto,
			'quelle'         => $quelle,
			'gegenpartei'    => $r->empfaenger_name,
			'beschreibung'   => sprintf( __( 'Rechnung %s', 'vereinsplugin' ), $r->nummer ?: ( '#' . $r->id ) ),
			'beleg_referenz' => 'RECHNUNG-' . ( $r->nummer ?: $r->id ),
			'budget_id'      => $r->budget_id,
			'kostenstelle'   => $r->kostenstelle,
		) );
	}
	$wpdb->update( vp_rechnung_table(), array(
		'status'     => 'bezahlt',
		'bezahlt_am' => $datum,
		'buchung_id' => $bid ?: null,
	), array( 'id' => (int) $id ) );
	return $bid;
}

/** Rechnung stornieren (Buchung bleibt bestehen und muss ggf. ausgeglichen werden). */
function vp_rechnung_storno( $id ) {
	global $wpdb;
	$wpdb->update( vp_rechnung_table(), array( 'status' => 'storniert' ), array( 'id' => (int) $id ) );
	return true;
}

function vp_rechnung_delete( $id ) {
	global $wpdb;
	$r = vp_rechnung_get( $id );
	if ( ! $r ) {
		return new WP_Error( 'not_found', __( 'Rechnung nicht gefunden.', 'vereinsplugin' ) );
	}
	if ( 'entwurf' !== $r->status ) {
		return new WP_Error( 'locked', __( 'Nur Entwürfe können gelöscht werden – festgeschriebene Rechnungen bitte stornieren.', 'vereinsplugin' ) );
	}
	$wpdb->delete( vp_rechnung_pos_table(), array( 'rechnung_id' => (int) $id ) );
	$wpdb->delete( vp_rechnung_table(), array( 'id' => (int) $id ) );
	return true;
}

/* =========================================================================
 * Absenderdaten (gemeinsam mit den Zuwendungsbestätigungen)
 * ====================================================================== */

function vp_org_daten() {
	return array(
		'name'      => (string) get_option( 'vp_org_name', get_option( 'vp_sepa_glaeubiger', get_bloginfo( 'name' ) ) ),
		'anschrift' => (string) get_option( 'vp_org_anschrift', '' ),
		'email'     => (string) get_option( 'vp_org_email', get_option( 'wl_kontakt_email', get_option( 'admin_email' ) ) ),
		'web'       => (string) get_option( 'vp_org_web', home_url( '/' ) ),
		'iban'      => (string) get_option( 'wl_iban', '' ),
		'bic'       => (string) get_option( 'vp_sepa_bic', get_option( 'wl_bic', '' ) ),
		'bank'      => (string) get_option( 'wl_bank', '' ),
		'steuernr'  => (string) get_option( 'vp_org_steuernr', '' ),
		'finanzamt' => (string) get_option( 'vp_org_finanzamt', '' ),
		'vertreter' => (string) get_option( 'vp_org_vertreter', '' ),
		'ort'       => (string) get_option( 'vp_org_ort', '' ),
	);
}

/* =========================================================================
 * Druckansicht
 * ====================================================================== */

function vp_rechnung_html( $id ) {
	$r = vp_rechnung_get( $id );
	if ( ! $r ) {
		return '';
	}
	$pos = vp_rechnung_positionen( $id );
	$org = vp_org_daten();
	$eur = function ( $v ) {
		return number_format( (float) $v, 2, ',', '.' ) . ' €';
	};

	ob_start();
	?>
	<div class="vp-doc">
		<div class="vp-doc-absender"><?php echo esc_html( $org['name'] ); ?><?php echo $org['anschrift'] ? ' · ' . esc_html( preg_replace( '/\s*\n\s*/', ', ', trim( $org['anschrift'] ) ) ) : ''; ?></div>
		<div class="vp-doc-empfaenger">
			<strong><?php echo esc_html( $r->empfaenger_name ); ?></strong><br>
			<?php echo nl2br( esc_html( (string) $r->empfaenger_anschrift ) ); ?>
		</div>
		<div class="vp-doc-meta">
			<div><?php esc_html_e( 'Rechnungsnummer', 'vereinsplugin' ); ?>: <strong><?php echo esc_html( $r->nummer ?: __( '(Entwurf)', 'vereinsplugin' ) ); ?></strong></div>
			<div><?php esc_html_e( 'Datum', 'vereinsplugin' ); ?>: <?php echo esc_html( date_i18n( 'd.m.Y', strtotime( $r->datum ) ) ); ?></div>
			<?php if ( $r->faellig_am ) : ?>
				<div><?php esc_html_e( 'Fällig am', 'vereinsplugin' ); ?>: <?php echo esc_html( date_i18n( 'd.m.Y', strtotime( $r->faellig_am ) ) ); ?></div>
			<?php endif; ?>
		</div>

		<h1><?php echo esc_html( $r->betreff ?: __( 'Rechnung', 'vereinsplugin' ) ); ?></h1>
		<?php if ( $r->einleitung ) : ?>
			<p><?php echo nl2br( esc_html( $r->einleitung ) ); ?></p>
		<?php endif; ?>

		<table class="vp-doc-table">
			<thead>
				<tr>
					<th>#</th>
					<th><?php esc_html_e( 'Bezeichnung', 'vereinsplugin' ); ?></th>
					<th class="r"><?php esc_html_e( 'Menge', 'vereinsplugin' ); ?></th>
					<th class="r"><?php esc_html_e( 'Einzelpreis', 'vereinsplugin' ); ?></th>
					<?php if ( $r->ust_ausweisen ) : ?><th class="r"><?php esc_html_e( 'USt', 'vereinsplugin' ); ?></th><?php endif; ?>
					<th class="r"><?php esc_html_e( 'Betrag', 'vereinsplugin' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $pos as $p ) : ?>
				<tr>
					<td><?php echo (int) $p->pos; ?></td>
					<td><?php echo esc_html( $p->bezeichnung ); ?></td>
					<td class="r"><?php echo esc_html( rtrim( rtrim( number_format( (float) $p->menge, 3, ',', '.' ), '0' ), ',' ) . ( $p->einheit ? ' ' . $p->einheit : '' ) ); ?></td>
					<td class="r"><?php echo esc_html( $eur( $p->einzelpreis ) ); ?></td>
					<?php if ( $r->ust_ausweisen ) : ?><td class="r"><?php echo esc_html( number_format( (float) $p->ust_satz, 0, ',', '.' ) . ' %' ); ?></td><?php endif; ?>
					<td class="r"><?php echo esc_html( $eur( $p->betrag ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
			<tfoot>
				<?php if ( $r->ust_ausweisen ) : ?>
					<tr><td colspan="<?php echo $r->ust_ausweisen ? 5 : 4; ?>" class="r"><?php esc_html_e( 'Netto', 'vereinsplugin' ); ?></td><td class="r"><?php echo esc_html( $eur( $r->netto ) ); ?></td></tr>
					<tr><td colspan="5" class="r"><?php esc_html_e( 'Umsatzsteuer', 'vereinsplugin' ); ?></td><td class="r"><?php echo esc_html( $eur( $r->ust ) ); ?></td></tr>
				<?php endif; ?>
				<tr class="sum"><td colspan="<?php echo $r->ust_ausweisen ? 5 : 4; ?>" class="r"><strong><?php esc_html_e( 'Gesamtbetrag', 'vereinsplugin' ); ?></strong></td><td class="r"><strong><?php echo esc_html( $eur( $r->summe ) ); ?></strong></td></tr>
			</tfoot>
		</table>

		<?php if ( ! $r->ust_ausweisen && get_option( 'vp_rechnung_ust_hinweis', '' ) ) : ?>
			<p class="vp-doc-hinweis"><?php echo esc_html( get_option( 'vp_rechnung_ust_hinweis' ) ); ?></p>
		<?php endif; ?>

		<p>
			<?php if ( 'lastschrift' === $r->zahlart ) : ?>
				<?php
				$mand = $r->mandat_id && function_exists( 'vp_sepa_mandat_get' ) ? vp_sepa_mandat_get( $r->mandat_id ) : null;
				printf(
					/* translators: 1: date, 2: mandate reference */
					esc_html__( 'Der Betrag wird am %1$s per SEPA-Lastschrift von Ihrem Konto eingezogen (Mandat %2$s). Sie brauchen nichts weiter zu tun.', 'vereinsplugin' ),
					esc_html( date_i18n( 'd.m.Y', strtotime( $r->faellig_am ) ) ),
					esc_html( $mand ? $mand->mandatsref : '—' )
				);
				?>
			<?php elseif ( 'bar' === $r->zahlart ) : ?>
				<?php esc_html_e( 'Betrag bar erhalten.', 'vereinsplugin' ); ?>
			<?php else : ?>
				<?php
				printf(
					/* translators: 1: due date */
					esc_html__( 'Bitte überweisen Sie den Betrag bis zum %s auf folgendes Konto:', 'vereinsplugin' ),
					esc_html( date_i18n( 'd.m.Y', strtotime( $r->faellig_am ) ) )
				);
				?>
				<br>
				<?php echo esc_html( $org['name'] ); ?><br>
				<?php esc_html_e( 'IBAN', 'vereinsplugin' ); ?>: <?php echo esc_html( trim( chunk_split( vp_iban_normalize( $org['iban'] ), 4, ' ' ) ) ); ?><br>
				<?php if ( $org['bic'] ) : ?><?php esc_html_e( 'BIC', 'vereinsplugin' ); ?>: <?php echo esc_html( $org['bic'] ); ?><br><?php endif; ?>
				<?php esc_html_e( 'Verwendungszweck', 'vereinsplugin' ); ?>: <?php echo esc_html( $r->nummer ?: __( 'Rechnung', 'vereinsplugin' ) ); ?>
			<?php endif; ?>
		</p>

		<?php if ( $r->schluss ) : ?>
			<p><?php echo nl2br( esc_html( $r->schluss ) ); ?></p>
		<?php endif; ?>

		<div class="vp-doc-fuss">
			<?php echo esc_html( $org['name'] ); ?><?php echo $org['email'] ? ' · ' . esc_html( $org['email'] ) : ''; ?><?php echo $org['steuernr'] ? ' · ' . esc_html__( 'Steuernummer', 'vereinsplugin' ) . ' ' . esc_html( $org['steuernr'] ) : ''; ?>
		</div>
	</div>
	<?php
	return ob_get_clean();
}

/** Minimales Stylesheet für Rechnung + Zuwendungsbestätigung (Druck). */
function vp_doc_css() {
	return '
	.vp-doc{max-width:19cm;margin:0 auto;background:#fff;color:#111;padding:1.5cm;font:13px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;box-sizing:border-box}
	.vp-doc h1{font-size:19px;margin:1.2em 0 .6em}
	.vp-doc h2{font-size:15px;margin:1.2em 0 .4em}
	.vp-doc-absender{font-size:10px;color:#555;border-bottom:1px solid #ccc;padding-bottom:2px;margin-bottom:1.2em}
	.vp-doc-empfaenger{min-height:3.2cm}
	.vp-doc-meta{margin:1em 0;font-size:12px}
	.vp-doc-table{width:100%;border-collapse:collapse;margin:1em 0}
	.vp-doc-table th,.vp-doc-table td{border-bottom:1px solid #ddd;padding:6px 4px;text-align:left;vertical-align:top}
	.vp-doc-table .r{text-align:right}
	.vp-doc-table tfoot .sum td{border-top:2px solid #333;border-bottom:none}
	.vp-doc-hinweis{font-size:12px;color:#444}
	.vp-doc-fuss{margin-top:2.5em;padding-top:6px;border-top:1px solid #ccc;font-size:10px;color:#555}
	.vp-doc-box{border:1px solid #333;padding:10px;margin:1em 0;font-size:12px}
	.vp-doc-sig{margin-top:2.5em}
	.vp-doc-sig .line{border-top:1px solid #333;width:7cm;margin-top:2.2em;padding-top:3px;font-size:11px}
	.vp-doc-print{max-width:19cm;margin:10px auto;text-align:right}
	@media print{.vp-doc-print{display:none}.vp-doc{padding:0;max-width:none}body{background:#fff}}
	';
}

/* =========================================================================
 * Druck-/Ansichts-Handler
 * ====================================================================== */

add_action( 'init', 'vp_rechnung_maybe_print' );
function vp_rechnung_maybe_print() {
	if ( empty( $_GET['vp_rechnung_print'] ) ) {
		return;
	}
	if ( ! vp_rechnung_can() ) {
		wp_die( esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) );
	}
	$id = (int) $_GET['vp_rechnung_print'];
	check_admin_referer( 'vp_rechnung_print_' . $id );
	$html = vp_rechnung_html( $id );
	if ( '' === $html ) {
		wp_die( esc_html__( 'Rechnung nicht gefunden.', 'vereinsplugin' ) );
	}
	vp_doc_output( __( 'Rechnung', 'vereinsplugin' ), $html );
}

/** Ein eigenständiges Druckdokument ausgeben (ohne Theme). */
function vp_doc_output( $titel, $html ) {
	nocache_headers();
	header( 'Content-Type: text/html; charset=UTF-8' );
	echo '<!doctype html><html lang="de"><head><meta charset="utf-8">';
	echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
	echo '<title>' . esc_html( $titel ) . '</title>';
	echo '<style>body{background:#f4f4f4;margin:0}' . vp_doc_css() . '</style>'; // phpcs:ignore
	echo '</head><body>';
	echo '<div class="vp-doc-print"><button onclick="window.print()">' . esc_html__( 'Drucken / als PDF sichern', 'vereinsplugin' ) . '</button></div>';
	echo $html; // phpcs:ignore WordPress.Security.EscapeOutput
	echo '</body></html>';
	exit;
}

/** Rechnung per E-Mail versenden (Link zur Druckansicht als Text). */
function vp_rechnung_mail( $id ) {
	$r = vp_rechnung_get( $id );
	if ( ! $r ) {
		return new WP_Error( 'not_found', __( 'Rechnung nicht gefunden.', 'vereinsplugin' ) );
	}
	if ( ! is_email( $r->empfaenger_email ) ) {
		return new WP_Error( 'no_mail', __( 'Für diese Rechnung ist keine E-Mail-Adresse hinterlegt.', 'vereinsplugin' ) );
	}
	$org  = vp_org_daten();
	$html = '<html><head><meta charset="utf-8"><style>' . vp_doc_css() . '</style></head><body>' . vp_rechnung_html( $id ) . '</body></html>';
	$ok   = wp_mail(
		$r->empfaenger_email,
		sprintf( __( '%1$s %2$s', 'vereinsplugin' ), $r->betreff ?: __( 'Rechnung', 'vereinsplugin' ), $r->nummer ),
		$html,
		array( 'Content-Type: text/html; charset=UTF-8', 'From: ' . $org['name'] . ' <' . get_option( 'admin_email' ) . '>' )
	);
	if ( $ok ) {
		global $wpdb;
		$wpdb->update( vp_rechnung_table(), array( 'gesendet_am' => current_time( 'mysql' ) ), array( 'id' => (int) $id ) );
	}
	return $ok ? true : new WP_Error( 'mail', __( 'Der Versand ist fehlgeschlagen.', 'vereinsplugin' ) );
}

/* =========================================================================
 * Frontend-Sektion „Rechnungen"
 * ====================================================================== */

function vp_render_rechnungen_section() {
	if ( ! vp_rechnung_can() ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) . '</div>';
	}
	vp_rechnungen_maybe_upgrade();

	$msg = '';
	$err = '';
	$id  = isset( $_GET['re'] ) ? (int) $_GET['re'] : 0;

	if ( isset( $_POST['vp_re_save'] ) && check_admin_referer( 'vp_re', 'vp_re_nonce' ) ) {
		$positionen = array();
		foreach ( (array) ( $_POST['p_bezeichnung'] ?? array() ) as $k => $bez ) {
			$positionen[] = array(
				'bezeichnung' => wp_unslash( $bez ),
				'menge'       => wp_unslash( $_POST['p_menge'][ $k ] ?? '1' ),
				'einheit'     => wp_unslash( $_POST['p_einheit'][ $k ] ?? '' ),
				'einzelpreis' => wp_unslash( $_POST['p_preis'][ $k ] ?? '0' ),
				'ust_satz'    => wp_unslash( $_POST['p_ust'][ $k ] ?? '0' ),
				'konto'       => wp_unslash( $_POST['p_konto'][ $k ] ?? '' ),
			);
		}
		$r = vp_rechnung_save( array(
			'id'                   => (int) ( $_POST['id'] ?? 0 ),
			'datum'                => wp_unslash( $_POST['datum'] ?? '' ),
			'faellig_am'           => wp_unslash( $_POST['faellig_am'] ?? '' ),
			'user_id'              => (int) ( $_POST['user_id'] ?? 0 ),
			'mandat_id'            => (int) ( $_POST['mandat_id'] ?? 0 ),
			'empfaenger_name'      => wp_unslash( $_POST['empfaenger_name'] ?? '' ),
			'empfaenger_anschrift' => wp_unslash( $_POST['empfaenger_anschrift'] ?? '' ),
			'empfaenger_email'     => wp_unslash( $_POST['empfaenger_email'] ?? '' ),
			'betreff'              => wp_unslash( $_POST['betreff'] ?? '' ),
			'einleitung'           => wp_unslash( $_POST['einleitung'] ?? '' ),
			'schluss'              => wp_unslash( $_POST['schluss'] ?? '' ),
			'ust_ausweisen'        => ! empty( $_POST['ust_ausweisen'] ),
			'zahlart'              => wp_unslash( $_POST['zahlart'] ?? 'ueberweisung' ),
			'konto'                => wp_unslash( $_POST['konto'] ?? '' ),
			'kostenstelle'         => wp_unslash( $_POST['kostenstelle'] ?? '' ),
			'budget_id'            => (int) ( $_POST['budget_id'] ?? 0 ),
			'notiz'                => wp_unslash( $_POST['notiz'] ?? '' ),
		), $positionen );
		if ( is_wp_error( $r ) ) {
			$err = $r->get_error_message();
		} else {
			$msg = __( 'Rechnung gespeichert.', 'vereinsplugin' );
			$id  = (int) $r;
			unset( $_GET['re'] );
		}
	}
	if ( isset( $_POST['vp_re_fest'] ) && check_admin_referer( 'vp_re', 'vp_re_nonce' ) ) {
		$r = vp_rechnung_festschreiben( (int) $_POST['id'] );
		$id = (int) $_POST['id'];
		if ( is_wp_error( $r ) ) {
			$err = $r->get_error_message();
		} else {
			$msg = sprintf( __( 'Festgeschrieben als %s.', 'vereinsplugin' ), $r );
		}
	}
	if ( isset( $_POST['vp_re_bezahlt'] ) && check_admin_referer( 'vp_re', 'vp_re_nonce' ) ) {
		$id = (int) $_POST['id'];
		$r  = vp_rechnung_mark_bezahlt( $id, wp_unslash( $_POST['bezahlt_am'] ?? '' ), 0, wp_unslash( $_POST['quelle'] ?? 'Bank KSK' ) );
		$msg = is_wp_error( $r ) ? '' : __( 'Als bezahlt gebucht.', 'vereinsplugin' );
		$err = is_wp_error( $r ) ? $r->get_error_message() : '';
	}
	if ( isset( $_POST['vp_re_storno'] ) && check_admin_referer( 'vp_re', 'vp_re_nonce' ) ) {
		vp_rechnung_storno( (int) $_POST['id'] );
		$id  = (int) $_POST['id'];
		$msg = __( 'Rechnung storniert.', 'vereinsplugin' );
	}
	if ( isset( $_POST['vp_re_del'] ) && check_admin_referer( 'vp_re', 'vp_re_nonce' ) ) {
		$r = vp_rechnung_delete( (int) $_POST['id'] );
		if ( is_wp_error( $r ) ) {
			$err = $r->get_error_message();
			$id  = (int) $_POST['id'];
		} else {
			$msg = __( 'Entwurf gelöscht.', 'vereinsplugin' );
			$id  = 0;
		}
	}
	if ( isset( $_POST['vp_re_mail'] ) && check_admin_referer( 'vp_re', 'vp_re_nonce' ) ) {
		$id = (int) $_POST['id'];
		$r  = vp_rechnung_mail( $id );
		if ( is_wp_error( $r ) ) {
			$err = $r->get_error_message();
		} else {
			$msg = __( 'Rechnung per E-Mail verschickt.', 'vereinsplugin' );
		}
	}

	$base = get_permalink() ?: remove_query_arg( array( 're' ) );

	ob_start();
	echo '<h2>' . esc_html__( 'Rechnungen', 'vereinsplugin' ) . '</h2>';
	if ( $msg ) {
		echo '<div class="vp-note">' . esc_html( $msg ) . '</div>';
	}
	if ( $err ) {
		echo '<div class="vp-note vp-note-error">' . esc_html( $err ) . '</div>';
	}
	echo '<p><a class="vp-btn vp-btn-primary" href="' . esc_url( add_query_arg( array( 'vp_tab' => 'rechnungen', 're' => 'neu' ), $base ) ) . '">'
		. esc_html__( 'Neue Rechnung', 'vereinsplugin' ) . '</a></p>';

	if ( isset( $_GET['re'] ) && 'neu' === $_GET['re'] ) {
		echo vp_rechnung_form( null ); // phpcs:ignore
	} elseif ( $id ) {
		echo vp_rechnung_form( vp_rechnung_get( $id ) ); // phpcs:ignore
	}

	echo vp_rechnung_liste( $base ); // phpcs:ignore
	return ob_get_clean();
}

function vp_rechnung_liste( $base ) {
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT * FROM ' . vp_rechnung_table() . ' ORDER BY datum DESC, id DESC LIMIT 200' );
	$offen = 0.0;
	foreach ( $rows as $r ) {
		if ( 'offen' === $r->status ) {
			$offen += (float) $r->summe;
		}
	}
	ob_start();
	echo '<h3>' . esc_html__( 'Übersicht', 'vereinsplugin' ) . ' <span class="vp-muted">('
		. sprintf( esc_html__( 'offen: %s €', 'vereinsplugin' ), esc_html( number_format( $offen, 2, ',', '.' ) ) ) . ')</span></h3>';
	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr>'
		. '<th>' . esc_html__( 'Nummer', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Datum', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Empfänger:in', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Betreff', 'vereinsplugin' ) . '</th>'
		. '<th class="r">' . esc_html__( 'Summe', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Zahlart', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Status', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		printf(
			'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td class="r">%s €</td><td>%s</td><td>%s</td></tr>',
			esc_url( add_query_arg( array( 'vp_tab' => 'rechnungen', 're' => (int) $r->id ), $base ) ),
			esc_html( $r->nummer ?: __( 'Entwurf', 'vereinsplugin' ) ),
			esc_html( date_i18n( 'd.m.Y', strtotime( $r->datum ) ) ),
			esc_html( $r->empfaenger_name ),
			esc_html( $r->betreff ),
			esc_html( number_format( (float) $r->summe, 2, ',', '.' ) ),
			esc_html( $r->zahlart ),
			esc_html( $r->status )
		);
	}
	if ( ! $rows ) {
		echo '<tr><td colspan="7">' . esc_html__( 'Noch keine Rechnungen.', 'vereinsplugin' ) . '</td></tr>';
	}
	echo '</tbody></table></div>';
	return ob_get_clean();
}

function vp_rechnung_form( $r ) {
	global $wpdb;
	$pos     = $r ? vp_rechnung_positionen( $r->id ) : array();
	$konten  = function_exists( 'jb_konten_all' ) ? jb_konten_all() : array();
	$budgets = function_exists( 'jb_budgets_get_all' ) ? jb_budgets_get_all() : array();
	$mandate = $wpdb->get_results( 'SELECT * FROM ' . vp_sepa_table_mandate() . " WHERE status = 'aktiv' ORDER BY kontoinhaber" );
	$locked  = $r && in_array( $r->status, array( 'bezahlt', 'storniert' ), true );
	$v       = function ( $feld, $default = '' ) use ( $r ) {
		return esc_attr( $r && isset( $r->$feld ) && null !== $r->$feld ? $r->$feld : $default );
	};

	ob_start();
	echo '<form method="post" class="vp-card">';
	wp_nonce_field( 'vp_re', 'vp_re_nonce' );
	echo '<input type="hidden" name="id" value="' . ( $r ? (int) $r->id : 0 ) . '">';
	printf(
		'<h3>%s</h3>',
		$r ? esc_html( sprintf( __( 'Rechnung %s (%s)', 'vereinsplugin' ), $r->nummer ?: __( 'Entwurf', 'vereinsplugin' ), $r->status ) ) : esc_html__( 'Neue Rechnung', 'vereinsplugin' )
	);

	echo '<div class="vp-form-grid">';
	echo '<label>' . esc_html__( 'Mitglied (optional)', 'vereinsplugin' ) . '<select name="user_id" id="vp-re-user"><option value="0">' . esc_html__( '— frei —', 'vereinsplugin' ) . '</option>';
	foreach ( get_users( array( 'role__in' => array( VP_MEMBER_ROLE, 'editor', 'administrator' ), 'orderby' => 'display_name' ) ) as $u ) {
		printf(
			'<option value="%d"%s data-name="%s" data-email="%s" data-anschrift="%s">%s</option>',
			$u->ID,
			selected( $r ? (int) $r->user_id : 0, $u->ID, false ),
			esc_attr( $u->display_name ),
			esc_attr( $u->user_email ),
			esc_attr( trim( get_user_meta( $u->ID, 'vp_strasse', true ) . "\n" . trim( get_user_meta( $u->ID, 'vp_plz', true ) . ' ' . get_user_meta( $u->ID, 'vp_ort', true ) ) ) ),
			esc_html( $u->display_name )
		);
	}
	echo '</select></label>';

	printf( '<label>%s<input type="text" name="empfaenger_name" id="vp-re-name" value="%s" required></label>', esc_html__( 'Empfänger:in', 'vereinsplugin' ), $v( 'empfaenger_name' ) );
	printf( '<label>%s<input type="email" name="empfaenger_email" id="vp-re-email" value="%s"></label>', esc_html__( 'E-Mail', 'vereinsplugin' ), $v( 'empfaenger_email' ) );
	printf( '<label>%s<input type="date" name="datum" value="%s"></label>', esc_html__( 'Datum', 'vereinsplugin' ), $v( 'datum', current_time( 'Y-m-d' ) ) );
	printf( '<label>%s<input type="date" name="faellig_am" value="%s"></label>', esc_html__( 'Fällig am', 'vereinsplugin' ), $v( 'faellig_am' ) );

	echo '<label>' . esc_html__( 'Zahlart', 'vereinsplugin' ) . '<select name="zahlart">';
	foreach ( array( 'ueberweisung' => __( 'Überweisung', 'vereinsplugin' ), 'lastschrift' => __( 'SEPA-Lastschrift', 'vereinsplugin' ), 'bar' => __( 'bar', 'vereinsplugin' ) ) as $k => $lbl ) {
		printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $r ? $r->zahlart : 'ueberweisung', $k, false ), esc_html( $lbl ) );
	}
	echo '</select></label>';

	echo '<label>' . esc_html__( 'SEPA-Mandat (bei Lastschrift)', 'vereinsplugin' ) . '<select name="mandat_id"><option value="0">' . esc_html__( '— automatisch —', 'vereinsplugin' ) . '</option>';
	foreach ( $mandate as $m ) {
		printf( '<option value="%d"%s>%s (%s)</option>', (int) $m->id, selected( $r ? (int) $r->mandat_id : 0, (int) $m->id, false ), esc_html( $m->kontoinhaber ), esc_html( $m->mandatsref ) );
	}
	echo '</select></label>';

	echo '<label>' . esc_html__( 'Ertragskonto', 'vereinsplugin' ) . '<select name="konto">';
	echo '<option value="">' . esc_html__( '— später —', 'vereinsplugin' ) . '</option>';
	foreach ( $konten as $k ) {
		if ( 'einnahme' !== $k->typ ) {
			continue;
		}
		printf( '<option value="%s"%s>%s %s</option>', esc_attr( $k->nummer ), selected( $r ? $r->konto : '', $k->nummer, false ), esc_html( $k->nummer ), esc_html( $k->bezeichnung ) );
	}
	echo '</select></label>';

	echo '<label>' . esc_html__( 'Budget', 'vereinsplugin' ) . '<select name="budget_id"><option value="0">—</option>';
	foreach ( $budgets as $b ) {
		$b = (object) $b;
		printf( '<option value="%d"%s>%s</option>', (int) $b->id, selected( $r ? (int) $r->budget_id : 0, (int) $b->id, false ), esc_html( $b->zweck ) );
	}
	echo '</select></label>';
	printf( '<label>%s<input type="text" name="kostenstelle" value="%s"></label>', esc_html__( 'Kostenstelle', 'vereinsplugin' ), $v( 'kostenstelle' ) );
	echo '</div>';

	printf(
		'<p><label>%s<br><textarea name="empfaenger_anschrift" id="vp-re-anschrift" rows="3" style="width:100%%">%s</textarea></label></p>',
		esc_html__( 'Anschrift', 'vereinsplugin' ),
		esc_textarea( $r ? (string) $r->empfaenger_anschrift : '' )
	);
	printf( '<p><label>%s<br><input type="text" name="betreff" value="%s" style="width:100%%"></label></p>', esc_html__( 'Betreff', 'vereinsplugin' ), $v( 'betreff', __( 'Rechnung', 'vereinsplugin' ) ) );
	printf(
		'<p><label>%s<br><textarea name="einleitung" rows="2" style="width:100%%">%s</textarea></label></p>',
		esc_html__( 'Einleitungstext', 'vereinsplugin' ),
		esc_textarea( $r ? (string) $r->einleitung : (string) get_option( 'vp_rechnung_einleitung', '' ) )
	);

	/* ---- Positionen ---- */
	echo '<h4>' . esc_html__( 'Positionen', 'vereinsplugin' ) . '</h4>';
	echo '<div class="vp-table-wrap"><table class="vp-table" id="vp-re-pos"><thead><tr>'
		. '<th>' . esc_html__( 'Bezeichnung', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Menge', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Einheit', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Einzelpreis', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'USt %', 'vereinsplugin' ) . '</th>'
		. '<th>' . esc_html__( 'Konto', 'vereinsplugin' ) . '</th></tr></thead><tbody>';

	$zeile = function ( $p = null ) use ( $konten ) {
		echo '<tr>';
		echo '<td><input type="text" name="p_bezeichnung[]" value="' . esc_attr( $p->bezeichnung ?? '' ) . '" style="width:100%"></td>';
		echo '<td><input type="text" inputmode="decimal" size="4" name="p_menge[]" value="' . esc_attr( $p ? rtrim( rtrim( number_format( (float) $p->menge, 3, ',', '' ), '0' ), ',' ) : '1' ) . '"></td>';
		echo '<td><input type="text" size="6" name="p_einheit[]" value="' . esc_attr( $p->einheit ?? '' ) . '"></td>';
		echo '<td><input type="text" inputmode="decimal" size="8" name="p_preis[]" value="' . esc_attr( $p ? number_format( (float) $p->einzelpreis, 2, ',', '' ) : '' ) . '"></td>';
		echo '<td><input type="text" inputmode="decimal" size="3" name="p_ust[]" value="' . esc_attr( $p ? number_format( (float) $p->ust_satz, 0, ',', '' ) : '0' ) . '"></td>';
		echo '<td><select name="p_konto[]"><option value="">—</option>';
		foreach ( $konten as $k ) {
			if ( 'einnahme' !== $k->typ ) {
				continue;
			}
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k->nummer ), selected( $p->konto ?? '', $k->nummer, false ), esc_html( $k->nummer . ' ' . $k->bezeichnung ) );
		}
		echo '</select></td></tr>';
	};
	foreach ( $pos as $p ) {
		$zeile( $p );
	}
	for ( $i = 0; $i < 3; $i++ ) {
		$zeile( null );
	}
	echo '</tbody></table></div>';

	printf(
		'<p><label><input type="checkbox" name="ust_ausweisen" value="1"%s> %s</label></p>',
		checked( $r ? (int) $r->ust_ausweisen : 0, 1, false ),
		esc_html__( 'Umsatzsteuer ausweisen (sonst Kleinunternehmer-/gemeinnütziger Hinweis)', 'vereinsplugin' )
	);
	printf(
		'<p><label>%s<br><textarea name="schluss" rows="2" style="width:100%%">%s</textarea></label></p>',
		esc_html__( 'Schlusstext', 'vereinsplugin' ),
		esc_textarea( $r ? (string) $r->schluss : (string) get_option( 'vp_rechnung_schluss', '' ) )
	);
	printf(
		'<p><label>%s<br><textarea name="notiz" rows="2" style="width:100%%">%s</textarea></label></p>',
		esc_html__( 'Interne Notiz', 'vereinsplugin' ),
		esc_textarea( $r ? (string) $r->notiz : '' )
	);

	echo '<p>';
	if ( ! $locked ) {
		echo '<button class="vp-btn vp-btn-primary" name="vp_re_save" value="1">' . esc_html__( 'Speichern', 'vereinsplugin' ) . '</button> ';
	}
	if ( $r && 'entwurf' === $r->status ) {
		echo '<button class="vp-btn" name="vp_re_fest" value="1">' . esc_html__( 'Festschreiben (Nummer vergeben)', 'vereinsplugin' ) . '</button> ';
		echo '<button class="vp-btn" name="vp_re_del" value="1" onclick="return confirm(\'' . esc_js( __( 'Entwurf löschen?', 'vereinsplugin' ) ) . '\')">' . esc_html__( 'Löschen', 'vereinsplugin' ) . '</button> ';
	}
	if ( $r && $r->id ) {
		printf(
			'<a class="vp-btn" target="_blank" rel="noopener" href="%s">%s</a> ',
			esc_url( wp_nonce_url( add_query_arg( 'vp_rechnung_print', (int) $r->id, home_url( '/' ) ), 'vp_rechnung_print_' . (int) $r->id ) ),
			esc_html__( 'Drucken / PDF', 'vereinsplugin' )
		);
	}
	if ( $r && 'offen' === $r->status ) {
		echo '<button class="vp-btn" name="vp_re_mail" value="1">' . esc_html__( 'Per E-Mail senden', 'vereinsplugin' ) . '</button> ';
		echo '<button class="vp-btn" name="vp_re_storno" value="1" onclick="return confirm(\'' . esc_js( __( 'Rechnung stornieren?', 'vereinsplugin' ) ) . '\')">' . esc_html__( 'Stornieren', 'vereinsplugin' ) . '</button> ';
	}
	echo '</p>';

	if ( $r && in_array( $r->status, array( 'offen', 'entwurf' ), true ) ) {
		echo '<div class="vp-card"><strong>' . esc_html__( 'Zahlungseingang buchen', 'vereinsplugin' ) . '</strong>';
		echo '<div class="vp-form-grid">';
		printf( '<label>%s<input type="date" name="bezahlt_am" value="%s"></label>', esc_html__( 'Bezahlt am', 'vereinsplugin' ), esc_attr( current_time( 'Y-m-d' ) ) );
		printf( '<label>%s<input type="text" name="quelle" value="Bank KSK"></label>', esc_html__( 'Geld-Topf', 'vereinsplugin' ) );
		echo '</div>';
		echo '<p><button class="vp-btn" name="vp_re_bezahlt" value="1">' . esc_html__( 'Als bezahlt buchen', 'vereinsplugin' ) . '</button></p></div>';
	}
	if ( $r && $r->bezahlt_am ) {
		echo '<p class="vp-muted">' . esc_html( sprintf( __( 'Bezahlt am %1$s · Buchung #%2$d', 'vereinsplugin' ), $r->bezahlt_am, (int) $r->buchung_id ) ) . '</p>';
	}
	echo '</form>';

	// Empfängerdaten aus dem Mitgliederprofil übernehmen.
	echo '<script>(function(){var s=document.getElementById("vp-re-user");if(!s)return;s.addEventListener("change",function(){'
		. 'var o=s.options[s.selectedIndex];if(!o||!o.dataset.name)return;'
		. 'var n=document.getElementById("vp-re-name"),e=document.getElementById("vp-re-email"),a=document.getElementById("vp-re-anschrift");'
		. 'if(n&&!n.value)n.value=o.dataset.name;if(e&&!e.value)e.value=o.dataset.email;if(a&&!a.value.trim())a.value=o.dataset.anschrift||"";});})();</script>';

	return ob_get_clean();
}
