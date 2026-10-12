<?php
/**
 * Finanzplan eines Projekts: Teilnehmenden-Szenarien, Förderungen und
 * Sponsoring in der Kalkulation (Reiter „Finanzen“).
 *
 * Posten der Kalkulation (vp_projekt_punkte, bereich=kalkulation) haben
 * zusätzlich:
 *   einheit      ''      = fester Betrag (betrag)
 *                'tn'    = Einzelbetrag × Teilnehmende (z. B. Eintritt, Verpflegung)
 *                'stueck'= Einzelbetrag × Anzahl (z. B. 4 Unternehmen × 250 €)
 *   einzelbetrag Betrag je Einheit, mit Vorzeichen (+ Einnahme, − Ausgabe)
 *   menge        Anzahl bei einheit=stueck
 *   posten_art   '' | foerderung | sponsoring
 *   status       bei Förderungen: idee | beantragt | genehmigt | abgelehnt
 *
 * `betrag` bleibt der Planwert bei der erwarteten Teilnehmendenzahl – so
 * rechnen Plan/Ist, Budget-Vorschlag und Kassen-Zuordnung unverändert.
 * Szenarien (Projektfeld tn_szenarien, z. B. „50, 100, 150“) rechnet der
 * Finanzen-Reiter zusätzlich durch.
 *
 * Ergebnis „sicher“: nur genehmigte Förderungen und zugesagtes Sponsoring
 * (laut Sponsoring-Liste); „erwartet“: dazu beantragte / geplante
 * Förderungen und das angenommene Sponsoring. Abgelehnte zählen nie.
 */

defined( 'ABSPATH' ) || exit;

function vp_projekt_posten_arten() {
	return array(
		''           => __( 'Posten', 'vereinsplugin' ),
		'foerderung' => __( 'Förderung / Zuschuss', 'vereinsplugin' ),
		'sponsoring' => __( 'Sponsoring', 'vereinsplugin' ),
	);
}

function vp_projekt_foerder_status() {
	return array(
		'idee'      => __( 'noch nicht beantragt', 'vereinsplugin' ),
		'beantragt' => __( 'Antrag gestellt', 'vereinsplugin' ),
		'genehmigt' => __( 'genehmigt', 'vereinsplugin' ),
		'abgelehnt' => __( 'abgelehnt', 'vereinsplugin' ),
	);
}

function vp_projekt_einheiten() {
	return array(
		''       => __( 'fester Betrag', 'vereinsplugin' ),
		'tn'     => __( 'je Teilnehmende:r', 'vereinsplugin' ),
		'stueck' => __( '× Anzahl', 'vereinsplugin' ),
	);
}

/** Erwartete Teilnehmende (Basis für `betrag`). */
function vp_projekt_tn_basis( $p ) {
	if ( ! empty( $p->teilnehmende_erwartet ) ) {
		return (int) $p->teilnehmende_erwartet;
	}
	$s = vp_projekt_tn_zahlen_parsen( $p->tn_szenarien ?? '' );
	return $s ? $s[0] : 0;
}

function vp_projekt_tn_zahlen_parsen( $text ) {
	$z = array_filter( array_map( 'intval', preg_split( '/[^0-9]+/', (string) $text ) ), static function ( $n ) {
		return $n > 0;
	} );
	$z = array_values( array_unique( $z ) );
	sort( $z );
	return $z;
}

/** Alle Szenarien (inkl. erwarteter Zahl), aufsteigend. */
function vp_projekt_szenarien( $p ) {
	$z = vp_projekt_tn_zahlen_parsen( $p->tn_szenarien ?? '' );
	$b = vp_projekt_tn_basis( $p );
	if ( $b && ! in_array( $b, $z, true ) ) {
		$z[] = $b;
	}
	sort( $z );
	return $z;
}

/**
 * Planwert eines Postens bei $tn Teilnehmenden (mit Vorzeichen).
 * null = noch kein Betrag.
 */
function vp_projekt_posten_wert( $x, $tn ) {
	$einheit = (string) ( $x->einheit ?? '' );
	if ( 'tn' === $einheit && isset( $x->einzelbetrag ) && null !== $x->einzelbetrag ) {
		return round( (float) $x->einzelbetrag * (int) $tn, 2 );
	}
	if ( 'stueck' === $einheit && isset( $x->einzelbetrag ) && null !== $x->einzelbetrag ) {
		return round( (float) $x->einzelbetrag * (float) ( $x->menge ?? 0 ), 2 );
	}
	return null === $x->betrag ? null : (float) $x->betrag;
}

/** Zählt der Posten im Modus sicher | erwartet? (Faktor 0 oder 1) */
function vp_projekt_posten_zaehlt( $x, $modus ) {
	if ( 'foerderung' !== ( $x->posten_art ?? '' ) ) {
		return true;
	}
	$st = (string) ( $x->status ?? '' );
	if ( 'abgelehnt' === $st ) {
		return false;
	}
	return 'sicher' === $modus ? 'genehmigt' === $st : true;
}

/**
 * Ein Szenario durchrechnen.
 * @return array ein_sicher, ein_erwartet, aus, erg_sicher, erg_erwartet, werte[id], foerder_offen, sponsoring_plan, sponsoring_sicher
 */
function vp_projekt_rechnung( $p, array $posten, $tn ) {
	$r = array(
		'tn' => (int) $tn, 'ein_sicher' => 0.0, 'ein_erwartet' => 0.0, 'aus' => 0.0,
		'werte' => array(), 'foerder_offen' => 0.0, 'sponsoring_plan' => 0.0, 'sponsoring_sicher' => 0.0,
	);
	$zugesagt = function_exists( 'vp_sponsoring_projekt_summe' ) ? vp_sponsoring_projekt_summe( (int) $p->id, array( 'zugesagt', 'erhalten' ) ) : null;
	foreach ( $posten as $x ) {
		$w                             = vp_projekt_posten_wert( $x, $tn );
		$r['werte'][ (int) $x->id ] = $w;
		if ( null === $w ) {
			continue;
		}
		if ( $w < 0 ) {
			$r['aus'] += abs( $w );
			continue;
		}
		if ( 'sponsoring' === ( $x->posten_art ?? '' ) ) {
			$r['sponsoring_plan'] += $w;
			continue; // unten mit der Sponsoring-Liste verrechnet
		}
		if ( vp_projekt_posten_zaehlt( $x, 'erwartet' ) ) {
			$r['ein_erwartet'] += $w;
		}
		if ( vp_projekt_posten_zaehlt( $x, 'sicher' ) ) {
			$r['ein_sicher'] += $w;
		} elseif ( 'foerderung' === ( $x->posten_art ?? '' ) && 'abgelehnt' !== ( $x->status ?? '' ) ) {
			$r['foerder_offen'] += $w;
		}
	}
	// Sponsoring: sicher = zugesagt laut Sponsoring-Liste; erwartet = die
	// Annahme in der Kalkulation, mindestens aber das schon Zugesagte.
	$r['sponsoring_sicher'] = (float) ( $zugesagt ?? 0 );
	$r['ein_sicher']       += $r['sponsoring_sicher'];
	$r['ein_erwartet']     += max( $r['sponsoring_plan'], $r['sponsoring_sicher'] );
	$r['erg_sicher']        = round( $r['ein_sicher'] - $r['aus'], 2 );
	$r['erg_erwartet']      = round( $r['ein_erwartet'] - $r['aus'], 2 );
	return $r;
}

/**
 * Break-even: ab wie vielen Teilnehmenden ist das erwartete Ergebnis ≥ 0?
 * null = hängt nicht von Teilnehmenden ab oder wird nie erreicht.
 */
function vp_projekt_break_even( $p, array $posten ) {
	$je_tn = 0.0;
	foreach ( $posten as $x ) {
		if ( 'tn' === ( $x->einheit ?? '' ) && null !== ( $x->einzelbetrag ?? null ) && vp_projekt_posten_zaehlt( $x, 'erwartet' ) ) {
			$je_tn += (float) $x->einzelbetrag;
		}
	}
	$fix = vp_projekt_rechnung( $p, $posten, 0 )['erg_erwartet'];
	if ( $fix >= 0 ) {
		return 0;
	}
	if ( $je_tn <= 0 ) {
		return null;
	}
	return (int) ceil( -$fix / $je_tn );
}

/** `betrag` aller berechneten Posten auf die erwartete Teilnehmendenzahl setzen. */
function vp_projekt_betraege_aktualisieren( $p ) {
	global $wpdb;
	$tn = vp_projekt_tn_basis( $p );
	foreach ( vp_projekt_punkte( $p->id, 'kalkulation' ) as $x ) {
		if ( in_array( (string) ( $x->einheit ?? '' ), array( 'tn', 'stueck' ), true ) && null !== ( $x->einzelbetrag ?? null ) ) {
			$wpdb->update( vp_projekt_punkte_table(), array( 'betrag' => vp_projekt_posten_wert( $x, $tn ) ), array( 'id' => (int) $x->id ) );
		}
	}
}

/** Kurzbeschreibung der Berechnung, z. B. „12,00 € × 100 TN“. */
function vp_projekt_posten_formel( $x, $tn ) {
	$e = abs( (float) ( $x->einzelbetrag ?? 0 ) );
	if ( 'tn' === ( $x->einheit ?? '' ) && null !== ( $x->einzelbetrag ?? null ) ) {
		return sprintf( __( '%1$s × %2$d Teilnehmende', 'vereinsplugin' ), vp_kreis_eur( $e ), (int) $tn );
	}
	if ( 'stueck' === ( $x->einheit ?? '' ) && null !== ( $x->einzelbetrag ?? null ) ) {
		$m = (float) ( $x->menge ?? 0 );
		return 'sponsoring' === ( $x->posten_art ?? '' )
			? sprintf( __( 'Annahme: %1$s Unternehmen × %2$s', 'vereinsplugin' ), rtrim( rtrim( number_format( $m, 2, ',', '' ), '0' ), ',' ), vp_kreis_eur( $e ) )
			: sprintf( __( '%1$s × %2$s', 'vereinsplugin' ), rtrim( rtrim( number_format( $m, 2, ',', '' ), '0' ), ',' ), vp_kreis_eur( $e ) );
	}
	return '';
}

/* -------------------------------------------------------------------------
 * Teilnehmenden-Szenarien speichern
 * ---------------------------------------------------------------------- */

add_action( 'admin_post_vp_projekt_tn_save', 'vp_projekt_handle_tn_save' );
function vp_projekt_handle_tn_save() {
	vp_kreis_check( 'vp_projekt_tn_save' );
	global $wpdb;
	$p   = vp_projekt_oder_fehler( (int) $_POST['projekt_id'] );
	$erw = (int) ( $_POST['teilnehmende_erwartet'] ?? 0 );
	$wpdb->update( vp_projekt_table(), array(
		'teilnehmende_erwartet' => $erw > 0 ? $erw : null,
		'tn_szenarien'          => implode( ', ', vp_projekt_tn_zahlen_parsen( sanitize_text_field( wp_unslash( $_POST['tn_szenarien'] ?? '' ) ) ) ),
	), array( 'id' => (int) $p->id ) );
	vp_projekt_betraege_aktualisieren( vp_projekt_get( (int) $p->id ) );
	vp_projekt_zurueck( $p, 'finanzen' );
}

/* -------------------------------------------------------------------------
 * Ansicht im Finanzen-Reiter
 * ---------------------------------------------------------------------- */

/** Szenario-Leiste, Vergleichstabelle, Förder-Übersicht. Gibt das gewählte TN zurück. */
function vp_projekt_finanzplan_kopf( $p, array $posten ) {
	$szen  = vp_projekt_szenarien( $p );
	$basis = vp_projekt_tn_basis( $p );
	$tn    = isset( $_GET['p_tn'] ) ? max( 0, (int) $_GET['p_tn'] ) : $basis;
	$hat_tn = (bool) array_filter( $posten, static function ( $x ) {
		return 'tn' === ( $x->einheit ?? '' );
	} );

	echo '<details class="pp-werkzeug"' . ( ( $hat_tn && ! $szen ) ? ' open' : '' ) . '><summary class="pp-details-summary">' . esc_html__( 'Mit wie vielen Teilnehmenden rechnet ihr?', 'vereinsplugin' ) . '</summary>';
	vp_kreis_form( 'vp_projekt_tn_save', 'pp-inline-form' );
	printf(
		'<input type="hidden" name="projekt_id" value="%d"><label>%s <input type="number" min="0" name="teilnehmende_erwartet" value="%s" style="width:90px"></label> <label>%s <input type="text" name="tn_szenarien" value="%s" placeholder="%s" style="width:160px"></label> <button class="pp-btn pp-btn-small">%s</button>',
		(int) $p->id,
		esc_html__( 'erwartet', 'vereinsplugin' ),
		esc_attr( $p->teilnehmende_erwartet ?: '' ),
		esc_html__( 'weitere Szenarien', 'vereinsplugin' ),
		esc_attr( $p->tn_szenarien ?? '' ),
		esc_attr__( 'z. B. 50, 150', 'vereinsplugin' ),
		esc_html__( 'Speichern', 'vereinsplugin' )
	);
	echo '</form><p class="pp-meta">' . esc_html__( 'Posten „je Teilnehmende:r“ (Eintritt, Verpflegung, Material …) rechnen mit der gewählten Zahl. Die erwartete Zahl ist die Grundlage für Budget und Plan/Ist.', 'vereinsplugin' ) . '</p></details>';

	if ( count( $szen ) > 1 || ( $szen && ! in_array( $tn, $szen, true ) ) ) {
		echo '<nav class="vp-pp-subnav" style="margin:8px 0">';
		foreach ( array_unique( array_merge( $szen, array( $tn ) ) ) as $z ) {
			printf(
				'<a class="vp-pp-chip%s" href="%s">%s</a>',
				$z === $tn ? ' is-active' : '',
				esc_url( vp_projekt_url( $p->id, 'finanzen', array( 'p_tn' => $z ) ) ),
				esc_html( sprintf( __( '%d TN', 'vereinsplugin' ), $z ) . ( $z === $basis ? ' · ' . __( 'erwartet', 'vereinsplugin' ) : '' ) )
			);
		}
		echo '</nav>';
	}

	if ( $posten && ( count( $szen ) > 1 || $hat_tn ) ) {
		echo '<table class="pp-table"><thead><tr><th>' . esc_html__( 'Teilnehmende', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Einnahmen erwartet', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Ausgaben', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Ergebnis sicher', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Ergebnis erwartet', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
		foreach ( $szen ?: array( $tn ) as $z ) {
			$r = vp_projekt_rechnung( $p, $posten, $z );
			printf(
				'<tr%s><td>%s</td><td style="text-align:right">%s</td><td style="text-align:right">%s</td><td style="text-align:right;color:%s">%s</td><td style="text-align:right;color:%s"><strong>%s</strong></td></tr>',
				$z === $tn ? ' style="background:rgba(250,204,21,.12)"' : '',
				esc_html( $z . ( $z === $basis ? ' (' . __( 'erwartet', 'vereinsplugin' ) . ')' : '' ) ),
				esc_html( vp_kreis_eur( $r['ein_erwartet'] ) ),
				esc_html( vp_kreis_eur( $r['aus'] ) ),
				$r['erg_sicher'] < 0 ? '#b91c1c' : '#15803d',
				esc_html( vp_kreis_eur( $r['erg_sicher'] ) ),
				$r['erg_erwartet'] < 0 ? '#b91c1c' : '#15803d',
				esc_html( vp_kreis_eur( $r['erg_erwartet'] ) )
			);
		}
		echo '</tbody></table>';
		$be = vp_projekt_break_even( $p, $posten );
		if ( null !== $be && $hat_tn ) {
			echo '<p class="pp-meta">' . esc_html( 0 === $be ? __( 'Break-even: Das Projekt trägt sich schon ohne Teilnehmende.', 'vereinsplugin' ) : sprintf( __( 'Break-even: ab %d Teilnehmenden ist das erwartete Ergebnis ausgeglichen.', 'vereinsplugin' ), $be ) ) . '</p>';
		}
	}

	// Förderungen und Sponsoring auf einen Blick.
	$r    = vp_projekt_rechnung( $p, $posten, $tn );
	$foer = array_filter( $posten, static function ( $x ) {
		return 'foerderung' === ( $x->posten_art ?? '' );
	} );
	if ( $foer || $r['sponsoring_plan'] ) {
		$st = vp_projekt_foerder_status();
		echo '<div class="pp-kpis">';
		foreach ( array( 'genehmigt', 'beantragt', 'idee', 'abgelehnt' ) as $s ) {
			$sum = 0.0;
			$n   = 0;
			foreach ( $foer as $x ) {
				if ( $s === ( $x->status ?: 'idee' ) ) {
					$sum += (float) ( $r['werte'][ (int) $x->id ] ?? 0 );
					$n++;
				}
			}
			if ( $n ) {
				printf( '<div class="pp-kpi%s"><strong>%s</strong><span>%s</span></div>', 'abgelehnt' === $s ? ' is-neg' : '', esc_html( vp_kreis_eur( $sum ) ), esc_html( sprintf( __( 'Förderung %1$s (%2$d)', 'vereinsplugin' ), $st[ $s ], $n ) ) );
			}
		}
		if ( $r['sponsoring_plan'] ) {
			printf( '<div class="pp-kpi"><strong>%s</strong><span>%s</span></div>', esc_html( vp_kreis_eur( $r['sponsoring_plan'] ) ), esc_html__( 'Sponsoring angenommen', 'vereinsplugin' ) );
			if ( function_exists( 'vp_sponsoring_projekt_summe' ) ) {
				printf( '<div class="pp-kpi"><strong>%s</strong><span>%s</span></div>', esc_html( vp_kreis_eur( vp_sponsoring_projekt_summe( (int) $p->id, array( 'zugesagt', 'erhalten' ) ) ) ), esc_html__( 'Sponsoring zugesagt', 'vereinsplugin' ) );
			}
		}
		echo '</div>';
	}
	return $tn;
}

/** Kennzeichen eines Postens in der Tabelle (Förderstatus, Formel). */
function vp_projekt_posten_badges( $x, $tn ) {
	$h = '';
	if ( 'foerderung' === ( $x->posten_art ?? '' ) ) {
		$st    = $x->status ?: 'idee';
		$farbe = array( 'genehmigt' => '#dcfce7', 'beantragt' => '#fef3c7', 'idee' => '#f1f5f9', 'abgelehnt' => '#fee2e2' )[ $st ] ?? '#f1f5f9';
		$h    .= ' <span class="pp-badge" style="background:' . esc_attr( $farbe ) . '">' . esc_html( __( 'Förderung', 'vereinsplugin' ) . ': ' . ( vp_projekt_foerder_status()[ $st ] ?? $st ) ) . '</span>';
	} elseif ( 'sponsoring' === ( $x->posten_art ?? '' ) ) {
		$h .= ' <span class="pp-badge" style="background:#e0f2fe">' . esc_html__( 'Sponsoring', 'vereinsplugin' ) . '</span>';
	}
	$f = vp_projekt_posten_formel( $x, $tn );
	if ( $f ) {
		$h .= '<div class="pp-meta">' . esc_html( $f ) . '</div>';
	}
	return $h;
}

/** Zusatzfelder im Posten-Formular der Kalkulation. */
function vp_projekt_posten_zusatzfelder( $punkt ) {
	$art     = (string) ( $punkt->posten_art ?? '' );
	$einheit = (string) ( $punkt->einheit ?? '' );
	?>
	<label><?php esc_html_e( 'Art des Postens', 'vereinsplugin' ); ?><select name="posten_art" data-vp-posten-art>
		<?php foreach ( vp_projekt_posten_arten() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $art, $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?>
	</select></label>
	<label data-vp-nur-foerderung><?php esc_html_e( 'Status der Förderung', 'vereinsplugin' ); ?><select name="foerder_status">
		<?php foreach ( vp_projekt_foerder_status() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $punkt->status ?? 'idee', $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?>
	</select></label>
	<label><?php esc_html_e( 'Berechnung', 'vereinsplugin' ); ?><select name="einheit" data-vp-einheit>
		<?php foreach ( vp_projekt_einheiten() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $einheit, $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?>
	</select></label>
	<label data-vp-nur-stueck><span data-vp-anzahl-label><?php esc_html_e( 'Anzahl', 'vereinsplugin' ); ?></span><input type="text" inputmode="decimal" name="menge" value="<?php echo esc_attr( isset( $punkt->menge ) && null !== $punkt->menge ? rtrim( rtrim( number_format( (float) $punkt->menge, 2, ',', '' ), '0' ), ',' ) : '' ); ?>" placeholder="<?php esc_attr_e( 'z. B. 4', 'vereinsplugin' ); ?>"></label>
	<?php
}

/** JS für die Felder oben (je Formular einmal). */
function vp_projekt_posten_js() {
	static $da = false;
	if ( $da ) {
		return;
	}
	$da = true;
	?>
	<script>
	(function () {
		document.querySelectorAll('[data-vp-posten-art]').forEach(function (art) {
			var f = art.closest('form'), einheit = f.querySelector('[data-vp-einheit]'), richtung = f.querySelector('select[name="richtung"]');
			function u() {
				var foe = art.value === 'foerderung', spo = art.value === 'sponsoring';
				f.querySelectorAll('[data-vp-nur-foerderung]').forEach(function (e) { e.hidden = !foe; });
				f.querySelectorAll('[data-vp-nur-stueck]').forEach(function (e) { e.hidden = einheit.value !== 'stueck'; });
				var l = f.querySelector('[data-vp-anzahl-label]');
				if (l) l.textContent = spo ? '<?php echo esc_js( __( 'Anzahl Unternehmen (Annahme)', 'vereinsplugin' ) ); ?>' : '<?php echo esc_js( __( 'Anzahl', 'vereinsplugin' ) ); ?>';
				var b = f.querySelector('[data-vp-betrag-label]');
				if (b) b.textContent = einheit.value === 'tn' ? '<?php echo esc_js( __( 'Betrag je Teilnehmende:r (€)', 'vereinsplugin' ) ); ?>' : (einheit.value === 'stueck' ? (spo ? '<?php echo esc_js( __( 'Betrag je Unternehmen (€)', 'vereinsplugin' ) ); ?>' : '<?php echo esc_js( __( 'Betrag je Stück (€)', 'vereinsplugin' ) ); ?>') : '<?php echo esc_js( __( 'Betrag (€)', 'vereinsplugin' ) ); ?>');
				if ((foe || spo) && richtung) richtung.value = 'einnahme';
			}
			art.addEventListener('change', function () { if (art.value === 'sponsoring' && einheit.value === '') einheit.value = 'stueck'; u(); });
			einheit.addEventListener('change', u);
			u();
		});
	})();
	</script>
	<?php
}
