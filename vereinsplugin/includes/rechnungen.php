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
		'bic'       => (string) get_option( 'vp_sepa_bic', '' ),
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
				<?php esc_html_e( 'IBAN', 'vereinsplugin' ); ?>: <?php echo esc_html( chunk_split( vp_iban_normalize( $org['iban'] ), 4, ' ' ) ); ?><br>
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
