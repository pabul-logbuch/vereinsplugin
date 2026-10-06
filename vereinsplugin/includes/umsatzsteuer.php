<?php
/**
 * Umsatzsteuer-Rücklage für Zweckbetrieb und wirtschaftlichen Geschäftsbetrieb.
 *
 * Ist der Verein in einem Geschäftsjahr umsatzsteuerpflichtig (keine
 * Kleinunternehmerregelung), wird aus den Buchungen dieser beiden Bereiche
 * die Umsatzsteuer (aus Einnahmen) und die Vorsteuer (aus Ausgaben)
 * herausgerechnet – je Voranmeldungszeitraum. Die Differenz (Zahllast bzw.
 * Erstattung) abzüglich der schon ans Finanzamt gezahlten Beträge ergibt die
 * „Umsatzsteuer-Rücklage“: so viel Geld gehört dem Finanzamt und sollte nicht
 * ausgegeben werden. Ein negativer Betrag ist eine erwartete Erstattung.
 *
 * Grundlagen:
 *  - Buchungen sind brutto; Steueranteil = brutto × Satz / (100 + Satz).
 *  - Steuersatz je Konto (0, 7 oder 19 %). Vorgabe: Zweckbetrieb 7 %
 *    (§ 12 Abs. 2 Nr. 8a UStG), wirtschaftlicher Geschäftsbetrieb 19 %.
 *    Steuerfreie Konten (z. B. § 4 Nr. 22 UStG) auf 0 % stellen – dann gibt es
 *    auch keine Vorsteuer.
 *  - Vorsteuer wahlweise nach Belegen oder pauschal nach § 23a UStG (7 % der
 *    steuerpflichtigen Umsätze, nur bei Vorjahresumsatz bis 45.000 €).
 *  - Zahlungen an das Finanzamt bzw. Erstattungen: Buchungen auf dem
 *    eingestellten Steuerkonto (Vorgabe 7600).
 *
 * Das ist eine Planungshilfe für die Kasse, keine Steuererklärung.
 */

defined( 'ABSPATH' ) || exit;

/** Bereiche, für die Umsatzsteuer gerechnet wird. */
function vp_ust_sphaeren() {
	return array( 'zweckbetrieb', 'wirtschaftlich' );
}

/** Einstellungen eines Geschäftsjahres. */
function vp_ust_einstellungen( $jahr ) {
	$alle = (array) get_option( 'vp_ust_jahre', array() );
	$e    = (array) ( $alle[ (int) $jahr ] ?? array() );
	return array(
		'pflichtig' => ! empty( $e['pflichtig'] ),
		'zeitraum'  => in_array( $e['zeitraum'] ?? '', array( 'monat', 'quartal', 'jahr' ), true ) ? $e['zeitraum'] : 'quartal',
		'verfahren' => ( $e['verfahren'] ?? '' ) === 'pauschal' ? 'pauschal' : 'regel',
		'zahlkonto' => trim( (string) ( $e['zahlkonto'] ?? '' ) ) ?: '7600',
	);
}

function vp_ust_einstellungen_speichern( $jahr, array $e ) {
	$alle                = (array) get_option( 'vp_ust_jahre', array() );
	$alle[ (int) $jahr ] = array(
		'pflichtig' => ! empty( $e['pflichtig'] ),
		'zeitraum'  => sanitize_key( $e['zeitraum'] ?? 'quartal' ),
		'verfahren' => sanitize_key( $e['verfahren'] ?? 'regel' ),
		'zahlkonto' => sanitize_text_field( $e['zahlkonto'] ?? '7600' ),
	);
	update_option( 'vp_ust_jahre', $alle, false );
}

/** Vorgabe-Steuersatz nach Bereich. */
function vp_ust_vorgabe_satz( $sphaere ) {
	return 'wirtschaftlich' === $sphaere ? 19 : ( 'zweckbetrieb' === $sphaere ? 7 : 0 );
}

/**
 * Steuersätze je Konto der beiden Bereiche (Einnahmen und Ausgaben).
 * @return array<string,array{satz:int,name:string,typ:string,sphaere:string}>
 */
function vp_ust_konten() {
	$gespeichert = (array) get_option( 'vp_ust_saetze', array() );
	$out         = array();
	foreach ( vp_bh_konto_info() as $nr => $k ) {
		if ( ! in_array( $k['sphaere'], vp_ust_sphaeren(), true ) || ! in_array( $k['typ'], array( 'einnahme', 'ausgabe' ), true ) ) {
			continue;
		}
		$nr         = (string) $nr;
		$out[ $nr ] = array(
			'satz'    => isset( $gespeichert[ $nr ] ) ? (int) $gespeichert[ $nr ] : vp_ust_vorgabe_satz( $k['sphaere'] ),
			'name'    => $k['bezeichnung'],
			'typ'     => $k['typ'],
			'sphaere' => $k['sphaere'],
		);
	}
	uksort( $out, 'strnatcmp' );
	return $out;
}

/** Zeitraum-Schlüssel und -Bezeichnung für ein Datum. */
function vp_ust_zeitraum( $datum, $art ) {
	$m = (int) substr( (string) $datum, 5, 2 );
	if ( 'monat' === $art ) {
		return $m;
	}
	if ( 'quartal' === $art ) {
		return (int) ceil( $m / 3 );
	}
	return 1;
}

function vp_ust_zeitraum_name( $key, $art, $jahr ) {
	if ( 'monat' === $art ) {
		return date_i18n( 'F Y', mktime( 0, 0, 0, (int) $key, 1, (int) $jahr ) );
	}
	if ( 'quartal' === $art ) {
		return sprintf( '%d. Quartal %d', (int) $key, (int) $jahr );
	}
	return sprintf( __( 'Jahr %d', 'vereinsplugin' ), (int) $jahr );
}

/**
 * Umsatzsteuer eines Geschäftsjahres berechnen.
 *
 * @return array{jahr:int,einstellungen:array,zeitraeume:array,summe:array,sphaeren:array,offen:float}
 *   zeitraeume[key] = { name, ust, vst, zahllast, gezahlt, netto } (Euro)
 */
function vp_ust_berechnung( $jahr ) {
	$e      = vp_ust_einstellungen( $jahr );
	$konten = vp_ust_konten();
	$d      = vp_bh_jahresdaten( $jahr );
	$anz    = 'monat' === $e['zeitraum'] ? 12 : ( 'quartal' === $e['zeitraum'] ? 4 : 1 );
	$z      = array();
	for ( $i = 1; $i <= $anz; $i++ ) {
		$z[ $i ] = array( 'name' => vp_ust_zeitraum_name( $i, $e['zeitraum'], $jahr ), 'ust' => 0.0, 'vst' => 0.0, 'gezahlt' => 0.0, 'netto' => 0.0 );
	}
	$sph = array();
	foreach ( vp_ust_sphaeren() as $s ) {
		$sph[ $s ] = array( 'ust' => 0.0, 'vst' => 0.0 );
	}

	foreach ( $d['saetze'] as $s ) {
		$k = vp_ust_zeitraum( $s['datum'], $e['zeitraum'] );
		foreach ( array( 'soll' => $s['soll'], 'haben' => $s['haben'] ) as $seite => $nr ) {
			$nr = (string) $nr;
			// Zahlungen an / Erstattungen vom Finanzamt.
			if ( $nr === $e['zahlkonto'] ) {
				$z[ $k ]['gezahlt'] += ( 'soll' === $seite ? 1 : -1 ) * $s['cent'] / 100;
				continue;
			}
			if ( ! isset( $konten[ $nr ] ) || $konten[ $nr ]['satz'] <= 0 ) {
				continue;
			}
			$satz   = $konten[ $nr ]['satz'];
			$brutto = $s['cent'] / 100;
			if ( 'einnahme' === $konten[ $nr ]['typ'] ) {
				$vz    = 'haben' === $seite ? 1 : -1; // Erstattung an Kunden mindert
				$steuer = $vz * $brutto * $satz / ( 100 + $satz );
				$z[ $k ]['ust']   += $steuer;
				$z[ $k ]['netto'] += $vz * $brutto - $steuer;
				$sph[ $konten[ $nr ]['sphaere'] ]['ust'] += $steuer;
			} else {
				$vz     = 'soll' === $seite ? 1 : -1; // Gutschrift vom Lieferanten mindert
				$steuer = $vz * $brutto * $satz / ( 100 + $satz );
				$z[ $k ]['vst'] += $steuer;
				$sph[ $konten[ $nr ]['sphaere'] ]['vst'] += $steuer;
			}
		}
	}

	// Pauschaler Vorsteuerabzug (§ 23a UStG): 7 % der steuerpflichtigen Umsätze.
	if ( 'pauschal' === $e['verfahren'] ) {
		foreach ( $z as $k => $v ) {
			$z[ $k ]['vst'] = $v['netto'] * 0.07;
		}
		foreach ( $sph as $s => $v ) {
			$sph[ $s ]['vst'] = 0.0; // nicht je Bereich aufteilbar
		}
	}

	$summe = array( 'ust' => 0.0, 'vst' => 0.0, 'zahllast' => 0.0, 'gezahlt' => 0.0, 'netto' => 0.0 );
	foreach ( $z as $k => $v ) {
		foreach ( array( 'ust', 'vst', 'gezahlt', 'netto' ) as $f ) {
			$z[ $k ][ $f ] = round( $v[ $f ], 2 );
		}
		$z[ $k ]['zahllast'] = round( $z[ $k ]['ust'] - $z[ $k ]['vst'], 2 );
		foreach ( array( 'ust', 'vst', 'zahllast', 'gezahlt', 'netto' ) as $f ) {
			$summe[ $f ] += $z[ $k ][ $f ];
		}
	}
	foreach ( $sph as $s => $v ) {
		$sph[ $s ] = array( 'ust' => round( $v['ust'], 2 ), 'vst' => round( $v['vst'], 2 ) );
	}
	return array(
		'jahr'          => (int) $jahr,
		'einstellungen' => $e,
		'zeitraeume'    => $z,
		'summe'         => array_map( static function ( $x ) {
			return round( $x, 2 );
		}, $summe ),
		'sphaeren'      => $sph,
		'offen'         => round( $summe['zahllast'] - $summe['gezahlt'], 2 ),
	);
}

/**
 * Offene Umsatzsteuer für die Rücklagen-Übersicht: laufendes Jahr und
 * Vorjahr (dessen Jahreserklärung meist erst später abgerechnet wird).
 * @return array<int,float> Jahr => offener Betrag (nur USt-pflichtige Jahre)
 */
function vp_ust_offen_fuer_ruecklagen() {
	$j   = (int) current_time( 'Y' );
	$out = array();
	foreach ( array( $j - 1, $j ) as $jahr ) {
		if ( vp_ust_einstellungen( $jahr )['pflichtig'] ) {
			$out[ $jahr ] = vp_ust_berechnung( $jahr )['offen'];
		}
	}
	return $out;
}

/* =========================================================================
 * Ansicht: Buchhaltung → Rücklagen → Umsatzsteuer
 * ====================================================================== */

/**
 * Einstellungen aus dem Formular speichern. Wird vor dem Aufbau der
 * Rücklagen-Tabelle aufgerufen, damit diese schon die neuen Werte zeigt.
 * @return string Meldung (leer = nichts gespeichert)
 */
function vp_ust_post_verarbeiten( $can_edit ) {
	static $msg = null;
	if ( null !== $msg ) {
		return $msg;
	}
	$msg  = '';
	$jahr = vp_bh_gewaehltes_jahr();
	if ( $can_edit && isset( $_POST['vp_ust_save'] ) && check_admin_referer( 'vp_bh_ust', 'vp_ust_nonce' ) ) {
		vp_ust_einstellungen_speichern( $jahr, array(
			'pflichtig' => ! empty( $_POST['ust_pflichtig'] ),
			'zeitraum'  => wp_unslash( $_POST['ust_zeitraum'] ?? 'quartal' ),
			'verfahren' => wp_unslash( $_POST['ust_verfahren'] ?? 'regel' ),
			'zahlkonto' => wp_unslash( $_POST['ust_zahlkonto'] ?? '7600' ),
		) );
		$saetze = (array) get_option( 'vp_ust_saetze', array() );
		foreach ( (array) ( $_POST['ust_satz'] ?? array() ) as $nr => $satz ) {
			$satz = (int) $satz;
			if ( in_array( $satz, array( 0, 7, 19 ), true ) ) {
				$saetze[ sanitize_text_field( (string) $nr ) ] = $satz;
			}
		}
		update_option( 'vp_ust_saetze', $saetze, false );
		vp_bh_cache_leeren();
		$msg = __( 'Umsatzsteuer-Einstellungen gespeichert.', 'vereinsplugin' );
	}
	return $msg;
}

function vp_ust_abschnitt( $can_edit ) {
	$jahr = vp_bh_gewaehltes_jahr();
	$msg  = vp_ust_post_verarbeiten( $can_edit );
	$e    = vp_ust_einstellungen( $jahr );
	$eur = static function ( $x ) {
		return vp_bh_eur( $x );
	};

	ob_start();
	echo '<h3 id="ust">' . esc_html__( 'Umsatzsteuer-Rücklage', 'vereinsplugin' ) . '</h3>';
	if ( $msg ) {
		echo '<div class="vp-note">' . esc_html( $msg ) . '</div>';
	}
	echo vp_bh_jahresleiste( $jahr, 'ruecklagen' ); // phpcs:ignore
	echo vp_bh_hilfe( __( 'Wie wird gerechnet?', 'vereinsplugin' ), array(
		__( 'Nur für Jahre, in denen der Verein <strong>umsatzsteuerpflichtig</strong> ist (also nicht Kleinunternehmer). Gerechnet werden die Buchungen im <strong>Zweckbetrieb</strong> und im <strong>wirtschaftlichen Geschäftsbetrieb</strong>. Ideeller Bereich und Vermögensverwaltung bleiben außen vor.', 'vereinsplugin' ),
		__( 'Aus jeder Einnahme wird die enthaltene Umsatzsteuer herausgerechnet, aus jeder Ausgabe die Vorsteuer (brutto × Satz ÷ (100 + Satz)). Zahllast = Umsatzsteuer − Vorsteuer. Zahlungen an das Finanzamt und Erstattungen (Buchungen auf dem Steuerkonto) werden abgezogen. Was übrig bleibt, gehört dem Finanzamt und sollte zurückgelegt werden – ein negativer Betrag ist eine erwartete Erstattung.', 'vereinsplugin' ),
		__( 'Steuersatz je Konto: Zweckbetrieb meist 7 %, wirtschaftlicher Geschäftsbetrieb 19 %. Steuerfreie Umsätze (z. B. Kurse, Vorträge nach § 4 Nr. 22 UStG) auf 0 % stellen – dann gibt es dort auch keine Vorsteuer. Pauschaler Vorsteuerabzug nach § 23a UStG (7 % der steuerpflichtigen Umsätze) nur, wenn der Vorjahresumsatz höchstens 45.000 € betrug.', 'vereinsplugin' ),
		__( 'Eine Planungshilfe für die Kasse, keine Steuererklärung. Im Zweifel die Sätze mit Steuerberatung abstimmen.', 'vereinsplugin' ),
	) ); // phpcs:ignore

	if ( $e['pflichtig'] ) {
		$r = vp_ust_berechnung( $jahr );
		echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Zeitraum', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Umsatzsteuer', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html( 'pauschal' === $e['verfahren'] ? __( 'Vorsteuer (pauschal 7 %)', 'vereinsplugin' ) : __( 'Vorsteuer', 'vereinsplugin' ) ) . '</th><th style="text-align:right">' . esc_html__( 'Zahllast', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'gezahlt / erstattet', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
		foreach ( $r['zeitraeume'] as $z ) {
			printf(
				'<tr><td>%s</td><td style="text-align:right">%s</td><td style="text-align:right">%s</td><td style="text-align:right">%s</td><td style="text-align:right">%s</td></tr>',
				esc_html( $z['name'] ),
				esc_html( $eur( $z['ust'] ) ),
				esc_html( $eur( $z['vst'] ) ),
				esc_html( $eur( $z['zahllast'] ) ),
				esc_html( $eur( $z['gezahlt'] ) )
			);
		}
		printf(
			'<tr style="font-weight:700"><td>%s</td><td style="text-align:right">%s</td><td style="text-align:right">%s</td><td style="text-align:right">%s</td><td style="text-align:right">%s</td></tr>',
			esc_html__( 'Summe', 'vereinsplugin' ),
			esc_html( $eur( $r['summe']['ust'] ) ),
			esc_html( $eur( $r['summe']['vst'] ) ),
			esc_html( $eur( $r['summe']['zahllast'] ) ),
			esc_html( $eur( $r['summe']['gezahlt'] ) )
		);
		echo '</tbody></table></div>';

		$offen = $r['offen'];
		if ( $offen > 0 ) {
			echo '<div class="vp-note vp-note-warn"><strong>' . esc_html( sprintf( __( 'Zurücklegen: %s', 'vereinsplugin' ), $eur( $offen ) ) ) . '</strong> – ' . esc_html__( 'so viel Umsatzsteuer ist noch nicht an das Finanzamt gezahlt.', 'vereinsplugin' ) . '</div>';
		} elseif ( $offen < 0 ) {
			echo '<div class="vp-note"><strong>' . esc_html( sprintf( __( 'Erwartete Erstattung: %s', 'vereinsplugin' ), $eur( -$offen ) ) ) . '</strong> – ' . esc_html__( 'die Vorsteuer übersteigt die Umsatzsteuer.', 'vereinsplugin' ) . '</div>';
		} else {
			echo '<div class="vp-note">' . esc_html__( 'Nichts offen.', 'vereinsplugin' ) . '</div>';
		}
		$labels = function_exists( 'vp_skr_sphaeren' ) ? vp_skr_sphaeren() : array();
		echo '<p class="vp-muted">';
		foreach ( $r['sphaeren'] as $s => $v ) {
			echo esc_html( sprintf(
				/* translators: 1: area, 2: VAT, 3: input tax */
				__( '%1$s: Umsatzsteuer %2$s, Vorsteuer %3$s', 'vereinsplugin' ),
				$labels[ $s ] ?? $s,
				$eur( $v['ust'] ),
				'pauschal' === $e['verfahren'] ? '–' : $eur( $v['vst'] )
			) ) . '<br>';
		}
		echo '</p>';
	} else {
		echo '<div class="vp-note">' . esc_html( sprintf( __( 'Für %d ist keine Umsatzsteuerpflicht eingestellt (Kleinunternehmer) – es wird nichts gerechnet.', 'vereinsplugin' ), $jahr ) ) . '</div>';
	}

	if ( $can_edit ) {
		echo '<details class="vp-card"' . ( $e['pflichtig'] ? '' : ' open' ) . '><summary><strong>' . esc_html( sprintf( __( 'Einstellungen für %d', 'vereinsplugin' ), $jahr ) ) . '</strong></summary>';
		echo '<form method="post" class="vp-form" style="margin-top:10px">' . wp_nonce_field( 'vp_bh_ust', 'vp_ust_nonce', true, false );
		echo '<p><label><input type="checkbox" name="ust_pflichtig" value="1" ' . checked( $e['pflichtig'], true, false ) . '> ' . esc_html( sprintf( __( 'Der Verein ist %d umsatzsteuerpflichtig', 'vereinsplugin' ), $jahr ) ) . '</label><br><span class="vp-muted">' . esc_html__( 'Nicht anhaken bei Kleinunternehmerregelung (§ 19 UStG: Vorjahresumsatz bis 25.000 € und laufendes Jahr bis 100.000 €).', 'vereinsplugin' ) . '</span></p>';
		echo '<div class="vp-form-grid">';
		echo '<label>' . esc_html__( 'Voranmeldungszeitraum', 'vereinsplugin' ) . '<select name="ust_zeitraum">';
		foreach ( array( 'monat' => __( 'monatlich', 'vereinsplugin' ), 'quartal' => __( 'vierteljährlich', 'vereinsplugin' ), 'jahr' => __( 'jährlich (nur Jahreserklärung)', 'vereinsplugin' ) ) as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $e['zeitraum'], $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></label>';
		echo '<label>' . esc_html__( 'Vorsteuer', 'vereinsplugin' ) . '<select name="ust_verfahren">'
			. '<option value="regel"' . selected( $e['verfahren'], 'regel', false ) . '>' . esc_html__( 'nach Belegen (Regelfall)', 'vereinsplugin' ) . '</option>'
			. '<option value="pauschal"' . selected( $e['verfahren'], 'pauschal', false ) . '>' . esc_html__( 'pauschal 7 % nach § 23a UStG', 'vereinsplugin' ) . '</option>'
			. '</select></label>';
		echo '<label>' . esc_html__( 'Steuerkonto (Zahlungen ans Finanzamt / Erstattungen)', 'vereinsplugin' ) . '<select name="ust_zahlkonto">' . vp_bh_konto_options( $e['zahlkonto'], 'alle' ) . '</select></label>';
		echo '</div>';
		echo '<h4>' . esc_html__( 'Steuersatz je Konto (gilt für alle Jahre)', 'vereinsplugin' ) . '</h4><div class="vp-table-wrap"><table class="vp-table"><tbody>';
		$labels = function_exists( 'vp_skr_sphaeren' ) ? vp_skr_sphaeren() : array();
		foreach ( vp_ust_konten() as $nr => $k ) {
			echo '<tr><td>' . esc_html( $nr . ' · ' . $k['name'] ) . '<br><span class="vp-muted">' . esc_html( ( $labels[ $k['sphaere'] ] ?? $k['sphaere'] ) . ' · ' . ( 'einnahme' === $k['typ'] ? __( 'Einnahme → Umsatzsteuer', 'vereinsplugin' ) : __( 'Ausgabe → Vorsteuer', 'vereinsplugin' ) ) ) . '</span></td><td><select name="ust_satz[' . esc_attr( $nr ) . ']">';
			foreach ( array( 19, 7, 0 ) as $s ) {
				echo '<option value="' . (int) $s . '"' . selected( $k['satz'], $s, false ) . '>' . (int) $s . ' %</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<p><button class="vp-btn vp-btn-primary" name="vp_ust_save" value="1">' . esc_html__( 'Speichern', 'vereinsplugin' ) . '</button></p></form></details>';
	}
	return ob_get_clean();
}
