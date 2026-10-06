<?php
/**
 * Z-Bon (Zettle/POS) aufteilen und ins Journal buchen – gemeinsam genutzt von
 * der Desktop-App (REST: /actions/zbon-import) und dem Mitgliederbereich
 * (Buchhaltung → Z-Bon).
 *
 * Aus einem Z-Bon entstehen bis zu vier Buchungen:
 *   Getränke Bar Z-Bon #N       = Bar   − (Produkt-Spende, falls bar bezahlt)       → Barkasse
 *   Getränke Karte Z-Bon #N     = Karte − Trinkgeld − (Produkt-Spende, falls Karte) → PayPal
 *   Trinkgeld (Spende) Z-Bon #N = Trinkgeld                                          → PayPal
 *   Spende Z-Bon #N             = Produkt-Spende                                     → Barkasse/PayPal
 *
 * Die Töpfe stimmen per Konstruktion:  Barkasse-Summe = Bar,  PayPal-Summe = Karte.
 */

defined( 'ABSPATH' ) || exit;

/** Geldbetrag aus Zahl oder deutscher Eingabe („1.234,50“). */
function vp_zbon_betrag( $v ) {
	if ( is_int( $v ) || is_float( $v ) ) {
		return round( (float) $v, 2 );
	}
	$s = trim( str_replace( array( '€', ' ' ), '', (string) $v ) );
	if ( false !== strpos( $s, ',' ) ) {
		$s = str_replace( array( '.', ',' ), array( '', '.' ), $s );
	}
	return round( (float) $s, 2 );
}

/**
 * Z-Bon in Buchungszeilen aufteilen (ohne zu buchen).
 *
 * @param array $b nr, datum, bar, karte, trinkgeld, spende_produkt,
 *                 spende_bezahlung (bar|karte), konto_getraenke, konto_spende, konto_trinkgeld
 * @return array|WP_Error { nr, datum, ref, lines: [ { label, betrag, konto, sphaere, quelle } ] }
 */
function vp_zbon_zeilen( array $b ) {
	$num  = preg_replace( '/[^0-9A-Za-z\-]/', '', (string) ( $b['nr'] ?? '' ) );
	$dat  = sanitize_text_field( (string) ( $b['datum'] ?? '' ) ) ?: current_time( 'Y-m-d' );
	$bar  = vp_zbon_betrag( $b['bar'] ?? 0 );
	$kar  = vp_zbon_betrag( $b['karte'] ?? 0 );
	$tip  = vp_zbon_betrag( $b['trinkgeld'] ?? 0 );
	$sp   = vp_zbon_betrag( $b['spende_produkt'] ?? 0 );
	$spez = ( ( $b['spende_bezahlung'] ?? 'bar' ) === 'karte' ) ? 'karte' : 'bar';

	if ( '' === $num ) {
		return new WP_Error( 'bad_req', 'Z-Bon-Nummer fehlt.', array( 'status' => 400 ) );
	}
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $dat ) ) {
		return new WP_Error( 'bad_req', 'Datum ungültig.', array( 'status' => 400 ) );
	}
	if ( min( $bar, $kar, $tip, $sp ) < 0 ) {
		return new WP_Error( 'bad_req', 'Beträge dürfen nicht negativ sein.', array( 'status' => 400 ) );
	}

	$k_getr = sanitize_text_field( (string) ( $b['konto_getraenke'] ?? '' ) ) ?: '4600';
	$k_sp   = sanitize_text_field( (string) ( $b['konto_spende'] ?? '' ) ) ?: '4200';
	$k_tip  = sanitize_text_field( (string) ( $b['konto_trinkgeld'] ?? '' ) ) ?: '4200';

	$sp_bar     = ( 'bar' === $spez ) ? $sp : 0.0;
	$sp_karte   = ( 'karte' === $spez ) ? $sp : 0.0;
	$getr_bar   = round( $bar - $sp_bar, 2 );
	$getr_karte = round( $kar - $tip - $sp_karte, 2 );

	if ( $getr_bar < 0 || $getr_karte < 0 ) {
		return new WP_Error( 'unplausibel', 'Trinkgeld/Spende übersteigen den Bar- bzw. Kartenumsatz.', array( 'status' => 400 ) );
	}

	$sph = static function ( $konto ) {
		return function_exists( 'jb_konto_sphaere' ) ? jb_konto_sphaere( $konto ) : '';
	};
	$lines = array();
	$mk    = static function ( $label, $betrag, $konto, $quelle ) use ( $num, $sph, &$lines ) {
		if ( round( $betrag, 2 ) <= 0 ) {
			return;
		}
		$lines[] = array(
			'label'   => $label . ' Z-Bon #' . $num,
			'betrag'  => round( $betrag, 2 ),
			'konto'   => $konto,
			'sphaere' => $sph( $konto ),
			'quelle'  => $quelle,
		);
	};
	$mk( 'Getränke Bar',       $getr_bar,   $k_getr, 'Zettle-Bar' );
	$mk( 'Getränke Karte',     $getr_karte, $k_getr, 'PayPal' );
	$mk( 'Trinkgeld (Spende)', $tip,        $k_tip,  'PayPal' );
	$mk( 'Spende',             $sp,         $k_sp,   ( 'bar' === $spez ) ? 'Zettle-Bar' : 'PayPal' );

	if ( ! $lines ) {
		return new WP_Error( 'bad_req', 'Keine Beträge eingetragen.', array( 'status' => 400 ) );
	}
	return array(
		'nr'    => $num,
		'datum' => $dat,
		'ref'   => 'ZBON-' . $num,
		'lines' => $lines,
	);
}

/** Anzahl der Journalzeilen, die schon zu dieser Z-Bon-Referenz gebucht sind. */
function vp_zbon_schon_gebucht( $ref ) {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->prefix}jb_buchungen WHERE beleg_referenz = %s", $ref
	) );
}

/**
 * Z-Bon aufteilen und buchen.
 * @param array $b wie vp_zbon_zeilen(), zusätzlich force (erneut buchen trotz vorhandener Referenz)
 * @return array|WP_Error { nr, datum, ref, lines, booked_ids }
 */
function vp_zbon_buchen( array $b ) {
	if ( ! function_exists( 'jb_journal_add' ) ) {
		return new WP_Error( 'no_fn', 'Buchhaltungs-Modul nicht geladen.', array( 'status' => 400 ) );
	}
	$z = vp_zbon_zeilen( $b );
	if ( is_wp_error( $z ) ) {
		return $z;
	}
	// Doppel-Import verhindern.
	$exists = vp_zbon_schon_gebucht( $z['ref'] );
	if ( $exists && empty( $b['force'] ) ) {
		return new WP_Error( 'schon_gebucht', 'Z-Bon #' . $z['nr'] . ' ist bereits gebucht (' . $exists . ' Zeilen). „force" zum erneuten Buchen.', array( 'status' => 409 ) );
	}

	$ids = array();
	foreach ( $z['lines'] as $ln ) {
		$ids[] = (int) jb_journal_add( array(
			'buchung_datum'  => $z['datum'],
			'betrag'         => $ln['betrag'],
			'kategorie'      => $ln['label'],
			'beschreibung'   => $ln['label'],
			'quelle'         => $ln['quelle'],
			'konto'          => $ln['konto'],
			'sphaere'        => $ln['sphaere'],
			'gegenpartei'    => 'Z-Bon #' . $z['nr'],
			'beleg_referenz' => $z['ref'],
		) );
	}
	if ( function_exists( 'vp_bh_cache_leeren' ) ) {
		vp_bh_cache_leeren();
	}
	$z['booked_ids'] = $ids;
	return $z;
}

/** Bereits gebuchte Z-Bons: Nummer → Datum, neueste zuerst. */
function vp_zbon_liste( $limit = 30 ) {
	global $wpdb;
	$rows = (array) $wpdb->get_results( $wpdb->prepare(
		"SELECT beleg_referenz AS ref, MIN(buchung_datum) AS datum, SUM(betrag) AS summe, COUNT(*) AS n
		 FROM {$wpdb->prefix}jb_buchungen WHERE beleg_referenz LIKE %s
		 GROUP BY beleg_referenz ORDER BY MIN(buchung_datum) DESC, beleg_referenz DESC LIMIT %d",
		$wpdb->esc_like( 'ZBON-' ) . '%',
		(int) $limit
	), ARRAY_A );
	return $rows;
}

/** Nächste Z-Bon-Nummer aus den bereits gebuchten ZBON-Referenzen. */
function vp_zbon_naechste_nr() {
	global $wpdb;
	$refs = (array) $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT beleg_referenz FROM {$wpdb->prefix}jb_buchungen WHERE beleg_referenz LIKE %s",
		$wpdb->esc_like( 'ZBON-' ) . '%'
	) );
	$max = 0;
	foreach ( $refs as $r ) {
		if ( preg_match( '/^ZBON-(\d+)$/', (string) $r, $m ) ) {
			$max = max( $max, (int) $m[1] );
		}
	}
	return $max + 1;
}

/* =========================================================================
 * Frontend: Buchhaltung → Z-Bon
 * ====================================================================== */

function vp_bh_zbon() {
	if ( ! ( current_user_can( 'jb_edit_journal' ) || current_user_can( 'manage_options' ) ) ) {
		return '<div class="vp-note vp-note-error">' . esc_html__( 'Keine Berechtigung zum Buchen.', 'vereinsplugin' ) . '</div>';
	}

	$felder = array( 'nr', 'datum', 'bar', 'karte', 'trinkgeld', 'spende_produkt', 'spende_bezahlung', 'konto_getraenke', 'konto_spende', 'konto_trinkgeld' );
	$v      = array(
		'nr'               => (string) vp_zbon_naechste_nr(),
		'datum'            => current_time( 'Y-m-d' ),
		'bar'              => '',
		'karte'            => '',
		'trinkgeld'        => '',
		'spende_produkt'   => '',
		'spende_bezahlung' => 'bar',
		'konto_getraenke'  => '4600',
		'konto_spende'     => '4200',
		'konto_trinkgeld'  => '4200',
	);
	$out         = '';
	$frage_force = false;

	if ( isset( $_POST['vp_zbon_buchen'] ) && check_admin_referer( 'vp_bh_zbon', 'vp_zbon_nonce' ) ) {
		$b = array();
		foreach ( $felder as $k ) {
			$b[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $k ] ?? '' ) );
		}
		$b['force'] = ! empty( $_POST['force'] );
		$r          = vp_zbon_buchen( $b );
		if ( is_wp_error( $r ) ) {
			$v           = array_merge( $v, $b );
			$frage_force = 'schon_gebucht' === $r->get_error_code();
			$out        .= '<div class="vp-note vp-note-error">' . esc_html(
				$frage_force
					? sprintf( __( 'Z-Bon #%s ist bereits gebucht. Haken bei „trotzdem erneut buchen“ setzen, falls das Absicht ist.', 'vereinsplugin' ), $b['nr'] )
					: $r->get_error_message()
			) . '</div>';
		} else {
			$out .= '<div class="vp-note">' . esc_html( sprintf(
				/* translators: 1: number of bookings, 2: Z-Bon number */
				__( '%1$d Buchung(en) für Z-Bon #%2$s angelegt.', 'vereinsplugin' ),
				count( $r['booked_ids'] ),
				$r['nr']
			) ) . ' <a href="' . esc_url( vp_bh_url( array( 'vp_bh' => 'journal', 'jahr' => (int) substr( $r['datum'], 0, 4 ) ) ) ) . '">' . esc_html__( 'Im Journal ansehen', 'vereinsplugin' ) . '</a></div>';
			// Formular für den nächsten Bon vorbereiten, Konten beibehalten.
			$v['nr']    = (string) vp_zbon_naechste_nr();
			$v['datum'] = $r['datum'];
			foreach ( array( 'konto_getraenke', 'konto_spende', 'konto_trinkgeld', 'spende_bezahlung' ) as $k ) {
				$v[ $k ] = $b[ $k ];
			}
		}
	}

	$konto_namen = array();
	foreach ( vp_bh_konto_info() as $nr => $k ) {
		$konto_namen[ (string) $nr ] = $nr . ' · ' . $k['bezeichnung'];
	}
	$feld = static function ( $name, $label, $wert, $extra = '' ) {
		return '<label>' . esc_html( $label ) . '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $wert ) . '" ' . $extra . '></label>';
	};
	$geld = 'inputmode="decimal" placeholder="0,00" data-vp-zbon';

	ob_start();
	echo vp_bh_hilfe( __( 'So funktioniert der Z-Bon', 'vereinsplugin' ), array(
		__( 'Den Tagesabschluss (Z-Bon) aus Zettle abtippen. Daraus entstehen bis zu vier Buchungen: <strong>Getränke Bar</strong>, <strong>Getränke Karte</strong>, <strong>Trinkgeld</strong> und <strong>Spende</strong>.', 'vereinsplugin' ),
		__( 'Bar geht auf die Barkasse, Karte und Trinkgeld auf PayPal – Zettle und PayPal sind dasselbe Konto. Trinkgeld und das Produkt „Spende“ werden vom jeweiligen Umsatz abgezogen, damit die Summen je Geldkonto genau dem Bon entsprechen.', 'vereinsplugin' ),
		__( 'Welche Geldkonten das sind, stellt ihr unter „Geschäftsjahr“ bei den Vorgabe-Konten ein. Jeder Z-Bon lässt sich nur einmal buchen.', 'vereinsplugin' ),
	) ); // phpcs:ignore
	echo $out; // phpcs:ignore
	?>
	<form method="post" class="vp-card vp-form" id="vp-zbon-form">
		<?php echo wp_nonce_field( 'vp_bh_zbon', 'vp_zbon_nonce', true, false ); // phpcs:ignore ?>
		<div class="vp-form-grid">
			<?php
			echo $feld( 'nr', __( 'Z-Bon-Nr.', 'vereinsplugin' ), $v['nr'], 'required data-vp-zbon' ); // phpcs:ignore
			echo '<label>' . esc_html__( 'Datum', 'vereinsplugin' ) . '<input type="date" name="datum" required value="' . esc_attr( $v['datum'] ) . '"></label>';
			echo $feld( 'bar', __( 'Bar (Zahlungsart)', 'vereinsplugin' ), $v['bar'], $geld ); // phpcs:ignore
			echo $feld( 'karte', __( 'Karte (Zahlungsart)', 'vereinsplugin' ), $v['karte'], $geld ); // phpcs:ignore
			echo $feld( 'trinkgeld', __( 'Trinkgeld', 'vereinsplugin' ), $v['trinkgeld'], $geld ); // phpcs:ignore
			echo $feld( 'spende_produkt', __( 'Produkt „Spende“', 'vereinsplugin' ), $v['spende_produkt'], $geld ); // phpcs:ignore
			echo '<label>' . esc_html__( 'Spende bezahlt', 'vereinsplugin' ) . '<select name="spende_bezahlung" data-vp-zbon>'
				. '<option value="bar"' . selected( $v['spende_bezahlung'], 'bar', false ) . '>' . esc_html__( 'bar bezahlt', 'vereinsplugin' ) . '</option>'
				. '<option value="karte"' . selected( $v['spende_bezahlung'], 'karte', false ) . '>' . esc_html__( 'per Karte bezahlt', 'vereinsplugin' ) . '</option>'
				. '</select></label>';
			echo '<label>' . esc_html__( 'Konto Getränke', 'vereinsplugin' ) . '<select name="konto_getraenke" data-vp-zbon>' . vp_bh_konto_options( $v['konto_getraenke'], 'einnahme' ) . '</select></label>'; // phpcs:ignore
			echo '<label>' . esc_html__( 'Konto Spende', 'vereinsplugin' ) . '<select name="konto_spende" data-vp-zbon>' . vp_bh_konto_options( $v['konto_spende'], 'einnahme' ) . '</select></label>'; // phpcs:ignore
			echo '<label>' . esc_html__( 'Konto Trinkgeld', 'vereinsplugin' ) . '<select name="konto_trinkgeld" data-vp-zbon>' . vp_bh_konto_options( $v['konto_trinkgeld'], 'einnahme' ) . '</select></label>'; // phpcs:ignore
			?>
		</div>
		<h3><?php esc_html_e( 'Vorschau', 'vereinsplugin' ); ?></h3>
		<div id="vp-zbon-vorschau"><p class="vp-muted"><?php esc_html_e( 'Beträge eintragen – die Aufteilung erscheint hier.', 'vereinsplugin' ); ?></p></div>
		<?php if ( $frage_force ) : ?>
			<p><label><input type="checkbox" name="force" value="1"> <?php esc_html_e( 'trotzdem erneut buchen', 'vereinsplugin' ); ?></label></p>
		<?php endif; ?>
		<p><button class="vp-btn vp-btn-primary" name="vp_zbon_buchen" value="1"><?php esc_html_e( 'Buchen', 'vereinsplugin' ); ?></button></p>
	</form>
	<script>
	(function () {
		var f = document.getElementById('vp-zbon-form');
		var box = document.getElementById('vp-zbon-vorschau');
		if (!f || !box) return;
		var namen = <?php echo wp_json_encode( $konto_namen ); ?>;
		var gBar = <?php echo wp_json_encode( vp_bh_konto_label( vp_bh_vorgabe_geldkonto( 'Zettle-Bar' ) ) ); ?>;
		var gPP = <?php echo wp_json_encode( vp_bh_konto_label( vp_bh_vorgabe_geldkonto( 'PayPal' ) ) ); ?>;
		function num(n) {
			var s = String(f.elements[n].value || '').replace(/[€\s]/g, '');
			if (s.indexOf(',') !== -1) s = s.replace(/\./g, '').replace(',', '.');
			var x = parseFloat(s);
			return isNaN(x) ? 0 : Math.round(x * 100) / 100;
		}
		function eur(x) { return x.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €'; }
		function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
		function kn(v) { return namen[v] || v; }
		function draw() {
			var bar = num('bar'), karte = num('karte'), tip = num('trinkgeld'), sp = num('spende_produkt');
			var spBar = f.elements.spende_bezahlung.value === 'bar';
			var getrBar = Math.round((bar - (spBar ? sp : 0)) * 100) / 100;
			var getrKarte = Math.round((karte - tip - (spBar ? 0 : sp)) * 100) / 100;
			var nr = f.elements.nr.value || '?';
			if (getrBar < 0 || getrKarte < 0) {
				box.innerHTML = '<div class="vp-note vp-note-error">' + esc(<?php echo wp_json_encode( __( 'Trinkgeld/Spende übersteigen den Bar- bzw. Kartenumsatz.', 'vereinsplugin' ) ); ?>) + '</div>';
				return;
			}
			var kg = f.elements.konto_getraenke.value, ks = f.elements.konto_spende.value, kt = f.elements.konto_trinkgeld.value;
			var lines = [];
			if (getrBar > 0) lines.push(['Getränke Bar', getrBar, kg, true]);
			if (getrKarte > 0) lines.push(['Getränke Karte', getrKarte, kg, false]);
			if (tip > 0) lines.push(['Trinkgeld (Spende)', tip, kt, false]);
			if (sp > 0) lines.push(['Spende', sp, ks, spBar]);
			if (!lines.length) {
				box.innerHTML = '<p class="vp-muted">' + esc(<?php echo wp_json_encode( __( 'Beträge eintragen – die Aufteilung erscheint hier.', 'vereinsplugin' ) ); ?>) + '</p>';
				return;
			}
			var sBar = 0, sPP = 0;
			var h = '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>Buchung</th><th>Konto</th><th>Geldkonto</th><th style="text-align:right">Betrag</th></tr></thead><tbody>';
			lines.forEach(function (l) {
				h += '<tr><td>' + esc(l[0] + ' Z-Bon #' + nr) + '</td><td>' + esc(kn(l[2])) + '</td><td>' + esc(l[3] ? gBar : gPP) + '</td><td style="text-align:right">' + eur(l[1]) + '</td></tr>';
				if (l[3]) sBar += l[1]; else sPP += l[1];
			});
			h += '</tbody></table></div>';
			h += '<p class="vp-muted">→ ' + esc(gBar) + ' ' + eur(Math.round(sBar * 100) / 100) + ' (soll: ' + eur(bar) + ') · ' + esc(gPP) + ' ' + eur(Math.round(sPP * 100) / 100) + ' (soll: ' + eur(karte) + ')</p>';
			box.innerHTML = h;
		}
		f.addEventListener('input', draw);
		f.addEventListener('change', draw);
		draw();
	})();
	</script>
	<?php
	$liste = vp_zbon_liste();
	if ( $liste ) {
		echo '<h3>' . esc_html__( 'Zuletzt gebucht', 'vereinsplugin' ) . '</h3>';
		echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Z-Bon', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Datum', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Buchungen', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Summe', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
		foreach ( $liste as $z ) {
			printf(
				'<tr><td>#%s</td><td>%s</td><td>%d</td><td style="text-align:right">%s</td></tr>',
				esc_html( substr( (string) $z['ref'], 5 ) ),
				esc_html( mysql2date( 'd.m.Y', $z['datum'] ) ),
				(int) $z['n'],
				esc_html( vp_bh_eur( abs( (float) $z['summe'] ) ) )
			);
		}
		echo '</tbody></table></div>';
	}
	return ob_get_clean();
}
