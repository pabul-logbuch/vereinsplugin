<?php
/**
 * Buchhaltung im Mitgliederbereich: Journal, Auswertung und Geschäftsjahr.
 *
 * Alle drei richten sich nach der Buchführungsart des gewählten Jahres –
 * EÜR oder Doppik (Rechenlogik: includes/buchungslogik.php).
 */

defined( 'ABSPATH' ) || exit;

/* =========================================================================
 * Helfer
 * ====================================================================== */

function vp_bh_eur( $v ) {
	return number_format( (float) $v, 2, ',', '.' ) . ' €';
}

function vp_bh_gewaehltes_jahr() {
	$j = isset( $_GET['jahr'] ) ? (int) $_GET['jahr'] : 0;
	return ( $j > 1990 && $j < 2200 ) ? $j : (int) current_time( 'Y' );
}

function vp_bh_url( array $args ) {
	$base = get_permalink() ?: remove_query_arg( array( 'vp_bh', 'jahr', 'kb' ) );
	return add_query_arg( array_merge( array( 'vp_tab' => 'buchhaltung' ), $args ), $base );
}

/** Aufklappbarer Erklärkasten. */
function vp_bh_hilfe( $titel, array $absaetze, $offen = false ) {
	$h = '<details class="vp-card"' . ( $offen ? ' open' : '' ) . '><summary><strong>' . esc_html( $titel ) . '</strong></summary>';
	foreach ( $absaetze as $a ) {
		$h .= '<p style="margin:8px 0 0">' . wp_kses( $a, array( 'strong' => array(), 'em' => array(), 'br' => array() ) ) . '</p>';
	}
	return $h . '</details>';
}

/** Jahresauswahl und Hinweis, wie das Jahr geführt wird. */
function vp_bh_jahresleiste( $jahr, $tab, $mit_folgejahr = false ) {
	$jahre = vp_bh_jahre();
	if ( $mit_folgejahr ) {
		$jahre[] = (int) current_time( 'Y' ) + 1;
		$jahre   = array_values( array_unique( $jahre ) );
		rsort( $jahre );
	}
	$out = '<p class="vp-subnav">';
	foreach ( $jahre as $y ) {
		$out .= sprintf(
			'<a class="%s" href="%s">%d</a>',
			(int) $y === (int) $jahr ? 'is-active' : '',
			esc_url( vp_bh_url( array( 'vp_bh' => $tab, 'jahr' => (int) $y ) ) ),
			(int) $y
		);
	}
	$out .= '</p>';
	$m    = vp_bh_methode( $jahr );
	$out .= '<div class="vp-note">' . sprintf(
		/* translators: 1: year, 2: method */
		esc_html__( 'Geschäftsjahr %1$d wird geführt als %2$s.', 'vereinsplugin' ),
		(int) $jahr,
		'<strong>' . esc_html( vp_bh_methoden()[ $m ] ) . '</strong>'
	);
	if ( 'jahr' !== $tab ) {
		$out .= ' <a href="' . esc_url( vp_bh_url( array( 'vp_bh' => 'jahr', 'jahr' => (int) $jahr ) ) ) . '">' . esc_html__( 'Was heißt das? / ändern', 'vereinsplugin' ) . '</a>';
	}
	if ( ! vp_bh_methode_gesetzt( $jahr ) ) {
		$out .= '<br>' . esc_html__( 'Für dieses Jahr ist noch nichts festgelegt – bis dahin gilt die EÜR. Legt die Buchführungsart am besten gleich zu Beginn des Jahres unter „Geschäftsjahr" fest.', 'vereinsplugin' );
	}
	return $out . '</div>';
}

/**
 * <option>-Liste der Konten, gruppiert.
 * @param string $filter alle | geld (Geld- und Bestandskonten) | erfolg (Einnahmen/Ausgaben)
 */
function vp_bh_konto_options( $selected, $filter = 'alle', $leer = '' ) {
	$gruppen = array(
		'geld'     => __( 'Geldkonten', 'vereinsplugin' ),
		'bestand'  => __( 'Weitere Bestandskonten', 'vereinsplugin' ),
		'einnahme' => __( 'Einnahmen', 'vereinsplugin' ),
		'ausgabe'  => __( 'Ausgaben', 'vereinsplugin' ),
	);
	$erlaubt = array(
		'alle'   => array( 'geld', 'bestand', 'einnahme', 'ausgabe' ),
		'geld'   => array( 'geld', 'bestand' ),
		'erfolg' => array( 'einnahme', 'ausgabe' ),
	);
	$liste    = array();
	$selected = (string) $selected;
	foreach ( vp_bh_konto_info() as $nr => $k ) {
		$nr = (string) $nr;
		if ( ! $k['aktiv'] && $nr !== $selected ) {
			continue;
		}
		$g                  = in_array( $k['typ'], array( 'geld', 'einnahme', 'ausgabe' ), true ) ? $k['typ'] : 'bestand';
		$liste[ $g ][ $nr ] = $k['bezeichnung'];
	}
	$html     = '' !== $leer ? '<option value="">' . esc_html( $leer ) . '</option>' : '';
	$gefunden = '' === $selected;
	foreach ( $erlaubt[ $filter ] ?? $erlaubt['alle'] as $g ) {
		if ( empty( $liste[ $g ] ) ) {
			continue;
		}
		uksort( $liste[ $g ], 'strnatcmp' );
		$html .= '<optgroup label="' . esc_attr( $gruppen[ $g ] ) . '">';
		foreach ( $liste[ $g ] as $nr => $name ) {
			$gefunden = $gefunden || (string) $nr === $selected;
			$html    .= '<option value="' . esc_attr( $nr ) . '"' . selected( $selected, (string) $nr, false ) . '>' . esc_html( $nr . ' · ' . $name ) . '</option>';
		}
		$html .= '</optgroup>';
	}
	if ( ! $gefunden ) {
		$html = '<option value="' . esc_attr( $selected ) . '" selected>' . esc_html( $selected . ' ' . __( '(aktuell)', 'vereinsplugin' ) ) . '</option>' . $html;
	}
	return $html;
}

/**
 * Eingabefelder einer Buchung – als EÜR (Art, Geldkonto, SKR-Konto) oder
 * als Buchungssatz (Soll an Haben).
 */
function vp_bh_buchung_felder( $methode, array $r = array() ) {
	$sicht = $r ? vp_bh_euer_sicht( $r ) : array( 'art' => 'ausgabe', 'geldkonto' => vp_bh_vorgabe_geldkonto( 'Bank KSK' ), 'konto' => '', 'betrag' => 0 );
	$h     = '';
	if ( 'euer' === $methode && null === $sicht ) {
		$methode = 'doppik';
		$h      .= '<p class="vp-muted">' . esc_html__( 'Diese Buchung ist ein reiner Buchungssatz ohne Geldkonto und lässt sich nur als Soll/Haben bearbeiten.', 'vereinsplugin' ) . '</p>';
	}
	$satz   = $r ? vp_doppik_satz( $r ) : array( 'soll' => '', 'haben' => '', 'betrag' => 0 );
	$wert   = 'euer' === $methode ? (float) $sicht['betrag'] : (float) $satz['betrag'];
	$betrag = $r ? number_format( $wert, 2, ',', '' ) : '';

	$h .= '<input type="hidden" name="modus" value="' . esc_attr( $methode ) . '">';
	$h .= '<div class="vp-form-grid">';
	$h .= '<label>' . esc_html__( 'Datum', 'vereinsplugin' ) . '<input type="date" name="datum" value="' . esc_attr( $r['buchung_datum'] ?? current_time( 'Y-m-d' ) ) . '"></label>';
	$h .= '<label>' . esc_html__( 'Betrag (€, immer positiv)', 'vereinsplugin' ) . '<input type="text" name="betrag" inputmode="decimal" placeholder="0,00" value="' . esc_attr( $betrag ) . '"></label>';

	if ( 'euer' === $methode ) {
		$art = $sicht['art'];
		$h  .= '<label>' . esc_html__( 'Art', 'vereinsplugin' ) . '<select name="art" data-vp-art>';
		foreach ( array(
			'einnahme'  => __( 'Einnahme – Geld kommt rein', 'vereinsplugin' ),
			'ausgabe'   => __( 'Ausgabe – Geld geht raus', 'vereinsplugin' ),
			'umbuchung' => __( 'Umbuchung – Geld zwischen eigenen Konten', 'vereinsplugin' ),
		) as $k => $l ) {
			$h .= '<option value="' . esc_attr( $k ) . '"' . selected( $art, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		$h .= '</select></label>';
		$geld = 'umbuchung' === $art ? $sicht['von'] : $sicht['geldkonto'];
		$h   .= '<label>' . esc_html__( 'Geldkonto – wo? (bei Umbuchung: von)', 'vereinsplugin' )
			. '<select name="geldkonto">' . vp_bh_konto_options( $geld, 'geld' ) . '</select></label>';
		// Verrechnungskonto 1590 steht für „noch kein SKR-Konto" – leer anbieten.
		$sach = 'umbuchung' === $art || in_array( (string) $sicht['konto'], array( VP_DOPPIK_INTERIM, VP_BH_INTERIM2 ), true ) ? '' : $sicht['konto'];
		$h   .= '<label data-vp-nur="einnahme ausgabe">' . esc_html__( 'SKR-Konto – wofür?', 'vereinsplugin' )
			. '<select name="konto">' . vp_bh_konto_options( $sach, 'erfolg', __( '– noch nicht zugeordnet –', 'vereinsplugin' ) ) . '</select></label>';
		$h   .= '<label data-vp-nur="umbuchung">' . esc_html__( 'Nach Konto', 'vereinsplugin' )
			. '<select name="nach">' . vp_bh_konto_options( 'umbuchung' === $art ? $sicht['nach'] : '', 'geld', '–' ) . '</select></label>';
	} else {
		$h .= '<label>' . esc_html__( 'Soll – wohin geht der Betrag?', 'vereinsplugin' ) . '<select name="soll">' . vp_bh_konto_options( $satz['soll'], 'alle', '–' ) . '</select></label>';
		$h .= '<label>' . esc_html__( 'Haben – woher kommt er?', 'vereinsplugin' ) . '<select name="haben">' . vp_bh_konto_options( $satz['haben'], 'alle', '–' ) . '</select></label>';
	}
	$h .= '<label>' . esc_html__( 'Gegenpartei – mit wem?', 'vereinsplugin' ) . '<input type="text" name="gegenpartei" value="' . esc_attr( $r['gegenpartei'] ?? '' ) . '"></label>';
	$h .= '<label>' . esc_html__( 'Beleg-Nr. (leer = automatisch)', 'vereinsplugin' ) . '<input type="text" name="beleg" value="' . esc_attr( $r['beleg_nr'] ?? '' ) . '"></label>';
	$h .= '<label class="vp-col-2">' . esc_html__( 'Verwendungszweck', 'vereinsplugin' ) . '<input type="text" name="zweck" value="' . esc_attr( $r['beschreibung'] ?? '' ) . '"></label>';
	return $h . '</div>';
}

/** Blendet je nach Art nur die passenden Felder ein (ohne JS bleiben alle sichtbar). */
function vp_bh_art_js() {
	return '<script>document.querySelectorAll("[data-vp-art]").forEach(function(s){var f=s.closest("form");function u(){f.querySelectorAll("[data-vp-nur]").forEach(function(l){l.hidden=l.getAttribute("data-vp-nur").split(" ").indexOf(s.value)<0;});}s.addEventListener("change",u);u();});</script>';
}

/**
 * Formularwerte → jb_buchungen-Spalten.
 * @return array|WP_Error
 */
function vp_bh_buchung_aus_post() {
	$p      = wp_unslash( $_POST );
	$betrag = abs( (float) str_replace( ',', '.', sanitize_text_field( (string) ( $p['betrag'] ?? '0' ) ) ) );
	if ( $betrag <= 0 ) {
		return new WP_Error( 'betrag', __( 'Bitte einen Betrag größer 0 eingeben.', 'vereinsplugin' ) );
	}
	if ( 'doppik' === ( $p['modus'] ?? '' ) ) {
		$soll  = sanitize_text_field( (string) ( $p['soll'] ?? '' ) );
		$haben = sanitize_text_field( (string) ( $p['haben'] ?? '' ) );
		if ( ! $soll || ! $haben || $soll === $haben ) {
			return new WP_Error( 'konten', __( 'Soll und Haben brauchen zwei verschiedene Konten.', 'vereinsplugin' ) );
		}
		$z = vp_bh_zeile_aus_satz( $soll, $haben, $betrag );
	} else {
		$art  = in_array( $p['art'] ?? '', array( 'einnahme', 'ausgabe', 'umbuchung' ), true ) ? $p['art'] : 'ausgabe';
		$geld = sanitize_text_field( (string) ( $p['geldkonto'] ?? '' ) );
		$ziel = sanitize_text_field( (string) ( 'umbuchung' === $art ? ( $p['nach'] ?? '' ) : ( $p['konto'] ?? '' ) ) );
		if ( ! $geld ) {
			return new WP_Error( 'geld', __( 'Bitte das Geldkonto wählen.', 'vereinsplugin' ) );
		}
		if ( 'umbuchung' === $art && ( ! $ziel || $ziel === $geld ) ) {
			return new WP_Error( 'umbuchung', __( 'Bei einer Umbuchung „von" und „nach" zwei verschiedene Konten wählen.', 'vereinsplugin' ) );
		}
		$z = vp_bh_zeile_aus_euer( $art, $geld, $ziel, $betrag );
	}
	$umbuchung = ! vp_bh_ist_erfolg( $z['konto'] ) && ! vp_bh_ist_erfolg( $z['geldkonto'] );
	$info      = vp_bh_konto_info();
	return array_merge( $z, array(
		'buchung_datum' => sanitize_text_field( (string) ( $p['datum'] ?? '' ) ) ?: current_time( 'Y-m-d' ),
		'beschreibung'  => sanitize_textarea_field( (string) ( $p['zweck'] ?? '' ) ),
		'gegenpartei'   => sanitize_text_field( (string) ( $p['gegenpartei'] ?? '' ) ),
		'beleg_nr'      => sanitize_text_field( (string) ( $p['beleg'] ?? '' ) ),
		'gegenkonto'    => '',
		'sphaere'       => $umbuchung ? 'neutral' : ( $info[ $z['konto'] ]['sphaere'] ?? '' ),
		'kategorie'     => $umbuchung ? 'Umbuchung' : ( $z['konto'] ? vp_bh_konto_label( $z['konto'] ) : 'Sonstige' ),
	) );
}

/* =========================================================================
 * Journal
 * ====================================================================== */

function vp_bh_journal() {
	global $wpdb;
	$can_edit = current_user_can( 'jb_edit_journal' ) || current_user_can( 'manage_options' );
	$jahr     = vp_bh_gewaehltes_jahr();
	$methode  = vp_bh_methode( $jahr );
	$msg      = '';
	$fehler   = false;
	$jcols    = (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . jb_table_journal() );
	$nonce_ok = function () {
		return check_admin_referer( 'vp_bh_journal', 'vp_bh_nonce' );
	};
	$zuordnung = function ( array $d ) use ( $jcols ) {
		foreach ( array( 'ruecklage_id', 'budget_id' ) as $k ) {
			if ( isset( $_POST[ $k ] ) && in_array( $k, $jcols, true ) ) {
				$d[ $k ] = (int) $_POST[ $k ] ?: null;
			}
		}
		if ( isset( $_POST['kostenstelle'] ) && in_array( 'kostenstelle', $jcols, true ) ) {
			$d['kostenstelle'] = sanitize_text_field( wp_unslash( $_POST['kostenstelle'] ) );
		}
		return $d;
	};

	if ( $can_edit && isset( $_POST['vp_bh_add'] ) && $nonce_ok() ) {
		$d = vp_bh_buchung_aus_post();
		if ( is_wp_error( $d ) ) {
			$msg    = $d->get_error_message();
			$fehler = true;
		} else {
			$d['quelle'] = 'Manuell';
			jb_journal_add( $zuordnung( $d ) );
			vp_bh_cache_leeren();
			$msg = __( 'Buchung gespeichert.', 'vereinsplugin' );
		}
	}
	if ( $can_edit && isset( $_POST['vp_bh_edit'] ) && $nonce_ok() ) {
		$eid = (int) ( $_POST['id'] ?? 0 );
		$d   = vp_bh_buchung_aus_post();
		if ( is_wp_error( $d ) ) {
			$msg    = $d->get_error_message();
			$fehler = true;
		} elseif ( $eid ) {
			$d = array_intersect_key( $zuordnung( $d ), array_flip( $jcols ) );
			if ( '' === $d['beleg_nr'] ) {
				unset( $d['beleg_nr'] );
			}
			$wpdb->update( jb_table_journal(), $d, array( 'id' => $eid ) );
			if ( ! empty( $d['ruecklage_id'] ) && function_exists( 'jb_table_ruecklagen' ) ) {
				$wpdb->update( jb_table_ruecklagen(), array( 'letzte_zahlung' => $d['buchung_datum'] ), array( 'id' => (int) $d['ruecklage_id'] ) );
			}
			vp_bh_cache_leeren();
			$msg = __( 'Buchung aktualisiert.', 'vereinsplugin' );
		}
	}
	if ( $can_edit && isset( $_POST['vp_bh_del'] ) && $nonce_ok() && function_exists( 'jb_journal_delete' ) ) {
		jb_journal_delete( (int) $_POST['id'] );
		vp_bh_cache_leeren();
		$msg = __( 'Buchung gelöscht.', 'vereinsplugin' );
	}
	if ( $can_edit && isset( $_POST['vp_bh_beleg_up'] ) && $nonce_ok() ) {
		$msg = vp_bh_journal_beleg_upload( (int) $_POST['id'], $_FILES['beleg_file'] ?? array() );
	}
	if ( $can_edit && isset( $_POST['vp_bh_split'] ) && $nonce_ok() ) {
		$sid  = (int) ( $_POST['id'] ?? 0 );
		$sbet = abs( (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['split_betrag'] ?? '0' ) ) ) );
		$skon = sanitize_text_field( wp_unslash( $_POST['split_konto'] ?? '' ) );
		$src  = $sid ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . jb_table_journal() . ' WHERE id = %d', $sid ), ARRAY_A ) : null;
		if ( $src && $sbet && $sbet < abs( (float) $src['betrag'] ) ) {
			// Der abgespaltene Teil wirkt in dieselbe Richtung auf dasselbe Geldkonto.
			$vz = (float) $src['betrag'] < 0 ? -1 : 1;
			jb_journal_add( array(
				'buchung_datum'  => $src['buchung_datum'],
				'betrag'         => $vz * $sbet,
				'geldkonto'      => $src['geldkonto'] ?? '',
				'gegenkonto'     => $src['gegenkonto'] ?? '',
				'konto'          => $skon,
				'kategorie'      => $skon ? vp_bh_konto_label( $skon ) : 'Teilbuchung',
				'beschreibung'   => sanitize_text_field( wp_unslash( $_POST['split_zweck'] ?? '' ) ) ?: ( $src['beschreibung'] ?? '' ),
				'quelle'         => $src['quelle'] ?? 'Manuell',
				'gegenpartei'    => $src['gegenpartei'] ?? '',
				'beleg_referenz' => $src['beleg_referenz'] ?? '',
				'beleg_pfad'     => $src['beleg_pfad'] ?? '',
			) );
			$wpdb->update( jb_table_journal(), array( 'betrag' => round( (float) $src['betrag'] - $vz * $sbet, 2 ) ), array( 'id' => $sid ) );
			vp_bh_cache_leeren();
			$msg = __( 'Buchung aufgeteilt.', 'vereinsplugin' );
		} else {
			$msg    = __( 'Aufteilen nicht möglich: der Teilbetrag muss kleiner als die Buchung sein.', 'vereinsplugin' );
			$fehler = true;
		}
	}

	$rows       = function_exists( 'jb_journal_get' ) ? jb_journal_get( array( 'year' => $jahr ) ) : array();
	$ruecklagen = function_exists( 'jb_ruecklagen_get_all' ) ? jb_ruecklagen_get_all() : array();
	$budgets    = function_exists( 'jb_budgets_get_all' ) ? jb_budgets_get_all() : array();
	$ks_liste   = function_exists( 'jb_kostenstellen' ) ? jb_kostenstellen() : array();
	$extras     = function ( array $r = array() ) use ( $ruecklagen, $budgets ) {
		$h = '<div class="vp-form-grid">';
		if ( $ruecklagen ) {
			$h .= '<label>' . esc_html__( 'Für Rücklage (optional)', 'vereinsplugin' ) . '<select name="ruecklage_id"><option value="0">' . esc_html__( '– keine –', 'vereinsplugin' ) . '</option>';
			foreach ( $ruecklagen as $rr ) {
				$rr = (object) $rr;
				$h .= '<option value="' . (int) $rr->id . '"' . selected( (int) ( $r['ruecklage_id'] ?? 0 ), (int) $rr->id, false ) . '>' . esc_html( $rr->bezeichnung ) . '</option>';
			}
			$h .= '</select></label>';
		}
		if ( $budgets ) {
			$h .= '<label>' . esc_html__( 'Budget belasten (optional)', 'vereinsplugin' ) . '<select name="budget_id"><option value="0">' . esc_html__( '– kein Budget –', 'vereinsplugin' ) . '</option>';
			foreach ( $budgets as $bb ) {
				$bb = (object) $bb;
				$h .= '<option value="' . (int) $bb->id . '"' . selected( (int) ( $r['budget_id'] ?? 0 ), (int) $bb->id, false ) . '>'
					. esc_html( $bb->zweck . ( $bb->kostenstelle ? ' · ' . $bb->kostenstelle : '' ) . ' (' . number_format( (float) ( $bb->rest ?? 0 ), 2, ',', '.' ) . ' € frei)' ) . '</option>';
			}
			$h .= '</select></label>';
		}
		$h .= '<label>' . esc_html__( 'Kostenstelle', 'vereinsplugin' ) . '<input type="text" name="kostenstelle" list="vp_ks_liste" value="' . esc_attr( $r['kostenstelle'] ?? '' ) . '"></label>';
		return $h . '</div>';
	};

	ob_start();
	if ( $ks_liste ) {
		echo '<datalist id="vp_ks_liste">';
		foreach ( $ks_liste as $ks ) {
			echo '<option value="' . esc_attr( $ks ) . '">';
		}
		echo '</datalist>';
	}
	if ( $msg ) {
		echo '<div class="vp-note' . ( $fehler ? ' vp-note-error' : '' ) . '">' . esc_html( $msg ) . '</div>';
	}
	echo vp_bh_jahresleiste( $jahr, 'journal' ); // phpcs:ignore

	if ( 'euer' === $methode ) {
		echo vp_bh_hilfe( __( 'So bucht ihr in der EÜR', 'vereinsplugin' ), array( // phpcs:ignore
			__( 'Jede Buchung beantwortet drei Fragen: <strong>Art</strong> (kommt Geld rein, geht es raus oder wandert es nur zwischen euren Konten?), <strong>Geldkonto – wo?</strong> (Bank, Barkasse, PayPal) und <strong>SKR-Konto – wofür?</strong> (z. B. 4100 Mitgliedsbeiträge, 5600 Wareneinkauf). Der Betrag ist immer positiv.', 'vereinsplugin' ),
			__( '<em>Beispiel Einnahme:</em> Beitrag 30 € per Überweisung → Einnahme, Geldkonto Bank, SKR-Konto 4100, Gegenpartei „Anna Müller".', 'vereinsplugin' ),
			__( '<em>Beispiel Ausgabe:</em> Getränke bar gekauft für 84,20 € → Ausgabe, Geldkonto Barkasse, SKR-Konto 5600.', 'vereinsplugin' ),
			__( '<em>Beispiel Umbuchung:</em> Kasseninhalt zur Bank gebracht → Umbuchung von Barkasse nach Bank. Umbuchungen sind weder Einnahme noch Ausgabe und tauchen deshalb nicht im Überschuss auf.', 'vereinsplugin' ),
			__( 'Zettle-Kartenzahlungen und PayPal landen auf demselben Konto (PayPal).', 'vereinsplugin' ),
		) );
	} else {
		echo vp_bh_hilfe( __( 'So bucht ihr in der Doppik', 'vereinsplugin' ), array( // phpcs:ignore
			__( 'Jede Buchung ist ein Buchungssatz <strong>Soll an Haben</strong>: Soll ist das Konto, auf das der Betrag geht, Haben das Konto, von dem er kommt. Der Betrag ist immer positiv.', 'vereinsplugin' ),
			__( '<em>Beitrag per Überweisung:</em> 1200 Bank an 4100 Mitgliedsbeiträge. <em>Getränke bar gekauft:</em> 5600 Wareneinkauf an 1000 Kasse. <em>Bargeld zur Bank:</em> 1200 Bank an 1000 Kasse.', 'vereinsplugin' ),
			__( 'Anders als in der EÜR lassen sich auch Vorgänge ohne Geldfluss buchen, z. B. eine noch offene Auslage: 5140 Material an 1600 Verbindlichkeiten – und später bei Auszahlung 1600 an 1200 Bank.', 'vereinsplugin' ),
		) );
	}

	if ( $can_edit ) {
		echo '<details class="vp-card"' . ( $fehler && isset( $_POST['vp_bh_add'] ) ? ' open' : '' ) . '><summary><strong>' . esc_html__( 'Neue Buchung erfassen', 'vereinsplugin' ) . '</strong></summary>';
		echo '<form method="post" class="vp-form" style="margin-top:12px">' . wp_nonce_field( 'vp_bh_journal', 'vp_bh_nonce', true, false );
		echo vp_bh_buchung_felder( $methode ) . $extras(); // phpcs:ignore
		echo '<p><button class="vp-btn vp-btn-primary" name="vp_bh_add" value="1">' . esc_html__( 'Buchen', 'vereinsplugin' ) . '</button></p></form></details>';
	}

	$has_nc = function_exists( 'jb_nc' );
	$num    = function ( $v ) {
		return esc_html( number_format( (float) $v, 2, ',', '.' ) );
	};
	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Beleg-Nr.', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Datum', 'vereinsplugin' ) . '</th>';
	if ( 'euer' === $methode ) {
		echo '<th>' . esc_html__( 'Wofür / Konto', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Gegenpartei / Zweck', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Einnahme', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Ausgabe', 'vereinsplugin' ) . '</th>';
	} else {
		echo '<th>' . esc_html__( 'Soll', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Haben', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Betrag', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Text', 'vereinsplugin' ) . '</th>';
	}
	echo '<th>' . esc_html__( 'Beleg', 'vereinsplugin' ) . '</th>' . ( $can_edit ? '<th></th>' : '' ) . '</tr></thead><tbody>';

	foreach ( $rows as $r ) {
		$rid = (int) $r['id'];
		$s   = vp_doppik_satz( $r );
		$v   = vp_bh_euer_sicht( $r );

		$beleg_cell = '<span class="vp-muted">–</span>';
		if ( ! empty( $r['beleg_pfad'] ) && $has_nc ) {
			$beleg_cell = '<a class="vp-btn" target="_blank" rel="noopener" href="' . esc_url( jb_nc()->get_download_url( $r['beleg_pfad'] ) ) . '">' . esc_html__( 'ansehen', 'vereinsplugin' ) . '</a>';
		} elseif ( $can_edit && $has_nc ) {
			$beleg_cell = '<form method="post" enctype="multipart/form-data" style="display:flex;gap:4px;align-items:center">'
				. wp_nonce_field( 'vp_bh_journal', 'vp_bh_nonce', true, false )
				. '<input type="hidden" name="id" value="' . $rid . '">'
				. '<input type="file" name="beleg_file" accept=".pdf,.jpg,.jpeg,.png,.webp" required style="max-width:120px">'
				. '<button class="vp-btn" name="vp_bh_beleg_up" value="1">↑</button></form>';
		}

		$edit_cell = '';
		if ( $can_edit ) {
			$edit_cell = '<td><details class="vp-inline-edit"><summary class="vp-btn">✎</summary>'
				. '<form method="post" class="vp-form" style="margin-top:8px;min-width:300px">'
				. wp_nonce_field( 'vp_bh_journal', 'vp_bh_nonce', true, false )
				. '<input type="hidden" name="id" value="' . $rid . '">'
				. vp_bh_buchung_felder( $methode, $r ) . $extras( $r )
				. '<p><button class="vp-btn vp-btn-primary" name="vp_bh_edit" value="1">' . esc_html__( 'Speichern', 'vereinsplugin' ) . '</button> '
				. '<button class="vp-btn vp-btn-danger" name="vp_bh_del" value="1" onclick="return confirm(\'' . esc_js( __( 'Buchung löschen?', 'vereinsplugin' ) ) . '\')">' . esc_html__( 'Löschen', 'vereinsplugin' ) . '</button></p>'
				. '</form>'
				. '<form method="post" class="vp-form" style="margin-top:8px;border-top:1px solid #e2e5ea;padding-top:8px">'
				. wp_nonce_field( 'vp_bh_journal', 'vp_bh_nonce', true, false )
				. '<input type="hidden" name="id" value="' . $rid . '">'
				. '<strong>' . esc_html__( 'Teil abspalten', 'vereinsplugin' ) . '</strong>'
				. '<label>' . esc_html__( 'Teilbetrag (€)', 'vereinsplugin' ) . '<input type="text" name="split_betrag" inputmode="decimal" placeholder="3,00"></label>'
				. '<label>' . esc_html__( 'SKR-Konto des Teils', 'vereinsplugin' ) . '<select name="split_konto">' . vp_bh_konto_options( '5190', 'alle', '–' ) . '</select></label>'
				. '<label>' . esc_html__( 'Zweck', 'vereinsplugin' ) . '<input type="text" name="split_zweck" value="' . esc_attr__( 'Bankgebühr', 'vereinsplugin' ) . '"></label>'
				. '<p><button class="vp-btn" name="vp_bh_split" value="1">' . esc_html__( 'Abspalten', 'vereinsplugin' ) . '</button> '
				. '<span class="vp-muted">' . esc_html__( 'Wird von dieser Buchung abgezogen und als eigene Buchung auf demselben Geldkonto angelegt.', 'vereinsplugin' ) . '</span></p>'
				. '</form></details></td>';
		}

		$text = '<br><span class="vp-muted">' . esc_html( wp_trim_words( (string) $r['beschreibung'], 14 ) ) . '</span>';
		echo '<tr><td>' . esc_html( $r['beleg_nr'] ?? '' ) . '</td><td>' . esc_html( $r['buchung_datum'] ) . '</td>';
		if ( 'euer' === $methode ) {
			if ( $v && 'umbuchung' === $v['art'] ) {
				echo '<td>' . esc_html__( 'Umbuchung', 'vereinsplugin' ) . '<br><span class="vp-muted">' . esc_html( vp_bh_konto_label( $v['von'] ) . ' → ' . vp_bh_konto_label( $v['nach'] ) ) . '</span></td>';
				echo '<td>' . esc_html( $r['gegenpartei'] ?? '' ) . $text . '</td>'; // phpcs:ignore
				echo '<td colspan="2" style="text-align:center" class="vp-muted">⇄ ' . $num( $v['betrag'] ) . ' €</td>'; // phpcs:ignore
			} elseif ( $v ) {
				$wo = 'einnahme' === $v['art'] ? __( 'auf %s', 'vereinsplugin' ) : __( 'von %s', 'vereinsplugin' );
				echo '<td>' . esc_html( $v['konto'] && ! in_array( $v['konto'], array( VP_DOPPIK_INTERIM, VP_BH_INTERIM2 ), true ) ? vp_bh_konto_label( $v['konto'] ) : __( '– noch nicht zugeordnet –', 'vereinsplugin' ) )
					. '<br><span class="vp-muted">' . esc_html( sprintf( $wo, vp_bh_konto_label( $v['geldkonto'] ) ) ) . '</span></td>';
				echo '<td>' . esc_html( $r['gegenpartei'] ?? '' ) . $text . '</td>'; // phpcs:ignore
				echo '<td style="text-align:right;color:#166534">' . ( 'einnahme' === $v['art'] ? $num( $v['betrag'] ) . ' €' : '' ) . '</td>'; // phpcs:ignore
				echo '<td style="text-align:right;color:#b91c1c">' . ( 'ausgabe' === $v['art'] ? $num( $v['betrag'] ) . ' €' : '' ) . '</td>'; // phpcs:ignore
			} else {
				echo '<td>' . esc_html__( 'Buchungssatz', 'vereinsplugin' ) . '<br><span class="vp-muted">' . esc_html( $s['soll'] . ' an ' . $s['haben'] ) . '</span></td>';
				echo '<td>' . esc_html( $r['gegenpartei'] ?? '' ) . $text . '</td>'; // phpcs:ignore
				echo '<td colspan="2" style="text-align:center" class="vp-muted">' . $num( $s['betrag'] ) . ' €</td>'; // phpcs:ignore
			}
		} else {
			echo '<td>' . esc_html( vp_bh_konto_label( $s['soll'] ) ) . '</td><td>' . esc_html( vp_bh_konto_label( $s['haben'] ) ) . '</td>';
			echo '<td style="text-align:right">' . $num( $s['betrag'] ) . ' €</td>'; // phpcs:ignore
			echo '<td>' . esc_html( $r['gegenpartei'] ?? '' ) . $text . '</td>'; // phpcs:ignore
		}
		echo '<td>' . $beleg_cell . '</td>' . $edit_cell . '</tr>'; // phpcs:ignore
	}
	if ( ! $rows ) {
		echo '<tr><td colspan="8" class="vp-muted">' . esc_html__( 'Keine Buchungen in diesem Jahr.', 'vereinsplugin' ) . '</td></tr>';
	} elseif ( 'euer' === $methode ) {
		$e = vp_bh_euer( $jahr );
		echo '<tr style="font-weight:700"><td colspan="4">' . esc_html__( 'Summe', 'vereinsplugin' ) . '</td><td style="text-align:right">' . $num( $e['einnahmen'] ) . ' €</td><td style="text-align:right">' . $num( $e['ausgaben'] ) . ' €</td><td colspan="2"></td></tr>'; // phpcs:ignore
	}
	echo '</tbody></table></div>';
	echo vp_bh_art_js(); // phpcs:ignore
	return ob_get_clean();
}

/* =========================================================================
 * Auswertung
 * ====================================================================== */

function vp_bh_auswertung() {
	$jahr    = vp_bh_gewaehltes_jahr();
	$methode = vp_bh_methode( $jahr );
	$kb      = isset( $_GET['kb'] ) ? sanitize_text_field( wp_unslash( $_GET['kb'] ) ) : '';
	$num     = function ( $v ) {
		return esc_html( number_format( (float) $v, 2, ',', '.' ) );
	};
	$kb_link = function ( $konto, $label = '' ) use ( $jahr ) {
		return '<a href="' . esc_url( vp_bh_url( array( 'vp_bh' => 'auswertung', 'jahr' => $jahr, 'kb' => $konto ) ) ) . '">' . esc_html( $label ?: vp_bh_konto_label( $konto ) ) . '</a>';
	};

	ob_start();
	echo vp_bh_jahresleiste( $jahr, 'auswertung' ); // phpcs:ignore

	if ( $kb ) {
		$blatt = vp_doppik_kontenblatt( $kb, $jahr );
		$euer  = 'euer' === $methode;
		echo '<p><a class="vp-btn" href="' . esc_url( vp_bh_url( array( 'vp_bh' => 'auswertung', 'jahr' => $jahr ) ) ) . '">‹ ' . esc_html__( 'Zur Übersicht', 'vereinsplugin' ) . '</a></p>';
		/* translators: 1: account, 2: year */
		echo '<h3>' . esc_html( sprintf( $euer ? __( 'Kontoauszug %1$s – %2$d', 'vereinsplugin' ) : __( 'Kontenblatt %1$s – %2$d', 'vereinsplugin' ), vp_bh_konto_label( $kb ), $jahr ) ) . '</h3>';
		echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Datum', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Text', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Gegenkonto', 'vereinsplugin' ) . '</th>'
			. '<th style="text-align:right">' . esc_html( $euer ? __( 'Zugang', 'vereinsplugin' ) : __( 'Soll', 'vereinsplugin' ) ) . '</th><th style="text-align:right">' . esc_html( $euer ? __( 'Abgang', 'vereinsplugin' ) : __( 'Haben', 'vereinsplugin' ) ) . '</th><th style="text-align:right">' . esc_html__( 'Stand', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
		echo '<tr class="vp-muted"><td></td><td>' . esc_html__( 'Stand am 1.1.', 'vereinsplugin' ) . '</td><td></td><td></td><td></td><td style="text-align:right">' . $num( $blatt['anfang'] ) . '</td></tr>'; // phpcs:ignore
		foreach ( $blatt['zeilen'] as $z ) {
			echo '<tr><td>' . esc_html( $z['datum'] ) . '</td><td>' . esc_html( $z['text'] ?: '—' ) . '</td><td>' . $kb_link( $z['gegen'] ) . '</td>' // phpcs:ignore
				. '<td style="text-align:right">' . ( $z['soll'] ? $num( $z['soll'] ) : '' ) . '</td><td style="text-align:right">' . ( $z['haben'] ? $num( $z['haben'] ) : '' ) . '</td>' // phpcs:ignore
				. '<td style="text-align:right">' . $num( $z['saldo'] ) . '</td></tr>'; // phpcs:ignore
		}
		echo '<tr style="font-weight:700"><td colspan="5">' . esc_html__( 'Stand am Jahresende', 'vereinsplugin' ) . '</td><td style="text-align:right">' . $num( $blatt['endsaldo'] ) . '</td></tr></tbody></table></div>'; // phpcs:ignore
		if ( vp_bh_ist_erfolg( $kb ) ) {
			echo '<p class="vp-muted">' . esc_html__( 'Bei Einnahmekonten steht der Stand im Minus (Haben-Saldo) – das ist in der Buchhaltung so üblich und kein Fehler.', 'vereinsplugin' ) . '</p>';
		}
		return ob_get_clean();
	}

	$e  = vp_bh_euer( $jahr );
	$gk = vp_bh_geldkonten_stand( $jahr );

	$kpi = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:12px 0">';
	foreach ( array(
		array( 'euer' === $methode ? __( 'Einnahmen', 'vereinsplugin' ) : __( 'Erträge', 'vereinsplugin' ), $e['einnahmen'], '#ecfdf5' ),
		array( 'euer' === $methode ? __( 'Ausgaben', 'vereinsplugin' ) : __( 'Aufwendungen', 'vereinsplugin' ), $e['ausgaben'], '#fef2f2' ),
		array( 'euer' === $methode ? __( 'Überschuss', 'vereinsplugin' ) : __( 'Jahresergebnis', 'vereinsplugin' ), $e['ueberschuss'], '#f8fafc' ),
	) as $t ) {
		$kpi .= '<div class="vp-card" style="background:' . $t[2] . ';margin:0"><div class="vp-muted">' . esc_html( $t[0] ) . '</div><strong style="font-size:1.3em">' . vp_bh_eur( $t[1] ) . '</strong></div>';
	}
	$kpi .= '</div>';

	// Geldkonten (beide Methoden)
	$geld_tabelle = '<h3>' . esc_html__( 'Geldkonten', 'vereinsplugin' ) . '</h3><div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Konto', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Stand 1.1.', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( '+ Zugänge', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( '− Abgänge', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( '= Stand 31.12.', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
	$summe = array( 0, 0, 0, 0 );
	foreach ( $gk as $g ) {
		$summe = array( $summe[0] + $g['anfang'], $summe[1] + $g['zugang'], $summe[2] + $g['abgang'], $summe[3] + $g['ende'] );
		$geld_tabelle .= '<tr><td>' . $kb_link( $g['konto'] ) . '</td><td style="text-align:right">' . $num( $g['anfang'] ) . '</td><td style="text-align:right">' . $num( $g['zugang'] ) . '</td><td style="text-align:right">' . $num( $g['abgang'] ) . '</td><td style="text-align:right' . ( $g['ende'] < 0 ? ';color:#b91c1c' : '' ) . '"><strong>' . $num( $g['ende'] ) . '</strong></td></tr>';
	}
	$geld_tabelle .= '<tr style="font-weight:700"><td>' . esc_html__( 'Gesamt', 'vereinsplugin' ) . '</td><td style="text-align:right">' . $num( $summe[0] ) . '</td><td style="text-align:right">' . $num( $summe[1] ) . '</td><td style="text-align:right">' . $num( $summe[2] ) . '</td><td style="text-align:right">' . $num( $summe[3] ) . '</td></tr></tbody></table></div>';
	if ( ! $gk ) {
		$geld_tabelle .= '<div class="vp-note vp-note-warn">' . esc_html__( 'Im Kontenplan ist noch kein Konto als „Geldkonto" gekennzeichnet.', 'vereinsplugin' ) . '</div>';
	}

	$sph_tabelle = '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Sphäre', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html( 'euer' === $methode ? __( 'Einnahmen', 'vereinsplugin' ) : __( 'Erträge', 'vereinsplugin' ) ) . '</th><th style="text-align:right">' . esc_html( 'euer' === $methode ? __( 'Ausgaben', 'vereinsplugin' ) : __( 'Aufwendungen', 'vereinsplugin' ) ) . '</th><th style="text-align:right">' . esc_html__( 'Ergebnis', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
	foreach ( $e['pro_sphaere'] as $sp ) {
		$sph_tabelle .= '<tr><td>' . esc_html( $sp['label'] ) . '</td><td style="text-align:right">' . $num( $sp['einnahmen'] ) . '</td><td style="text-align:right">' . $num( $sp['ausgaben'] ) . '</td><td style="text-align:right">' . $num( $sp['saldo'] ) . '</td></tr>';
	}
	$sph_tabelle .= '</tbody></table></div>';

	$konto_tabelle = function ( $typ, $titel ) use ( $e, $num, $kb_link ) {
		$h   = '<h4>' . esc_html( $titel ) . '</h4><div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Konto', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Betrag', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Buchungen', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
		$sum = 0;
		foreach ( $e['pro_konto'] as $k ) {
			if ( $k['typ'] !== $typ ) {
				continue;
			}
			$v    = 'einnahme' === $typ ? $k['einnahmen'] : $k['ausgaben'];
			$sum += $v;
			$h   .= '<tr><td>' . $kb_link( $k['konto'] ) . '</td><td style="text-align:right">' . $num( $v ) . '</td><td style="text-align:right">' . (int) $k['anzahl'] . '</td></tr>';
		}
		return $h . '<tr style="font-weight:700"><td>' . esc_html__( 'Summe', 'vereinsplugin' ) . '</td><td style="text-align:right">' . $num( $sum ) . '</td><td></td></tr></tbody></table></div>';
	};

	if ( $e['ohne_konto']['anzahl'] ) {
		echo '<div class="vp-note vp-note-warn">' . esc_html( sprintf(
			/* translators: 1: count, 2: in, 3: out */
			__( '%1$d Buchung(en) haben noch kein SKR-Konto (Einnahmen %2$s, Ausgaben %3$s). Sie zählen im Ergebnis mit, aber ohne Zuordnung. Im Journal über ✎ ein Konto wählen.', 'vereinsplugin' ),
			(int) $e['ohne_konto']['anzahl'],
			vp_bh_eur( $e['ohne_konto']['einnahmen'] ),
			vp_bh_eur( $e['ohne_konto']['ausgaben'] )
		) ) . ' <a href="' . esc_url( vp_bh_url( array( 'vp_bh' => 'auswertung', 'jahr' => $jahr, 'kb' => VP_DOPPIK_INTERIM ) ) ) . '">' . esc_html__( 'anzeigen', 'vereinsplugin' ) . '</a></div>';
	}

	if ( 'euer' === $methode ) {
		echo vp_bh_hilfe( __( 'Wie liest man die EÜR?', 'vereinsplugin' ), array( // phpcs:ignore
			__( '<strong>Einnahmen − Ausgaben = Überschuss.</strong> Gezählt wird, was im Jahr tatsächlich eingenommen und ausgegeben wurde. Geld, das nur zwischen euren Konten wandert (Bareinzahlung, Wechselgeld, PayPal-Auszahlung), ist keine Einnahme und keine Ausgabe.', 'vereinsplugin' ),
			__( '<strong>Geldkonten</strong> zeigen, wie viel Geld am Jahresanfang und -ende da war. Stand 1.1. + Zugänge − Abgänge = Stand 31.12. Ein Konto anklicken zeigt jede Bewegung.', 'vereinsplugin' ),
			__( 'Die <strong>Sphären</strong> trennen die Bereiche, die das Finanzamt bei gemeinnützigen Vereinen unterscheidet (ideeller Bereich, Vermögensverwaltung, Zweckbetrieb, wirtschaftlicher Geschäftsbetrieb).', 'vereinsplugin' ),
		) );
		echo $kpi . $geld_tabelle; // phpcs:ignore
		echo '<h3>' . esc_html__( 'Nach Sphäre', 'vereinsplugin' ) . '</h3>' . $sph_tabelle; // phpcs:ignore
		echo '<h3>' . esc_html__( 'Nach SKR-Konto', 'vereinsplugin' ) . '</h3>';
		echo $konto_tabelle( 'einnahme', __( 'Einnahmen', 'vereinsplugin' ) ) . $konto_tabelle( 'ausgabe', __( 'Ausgaben', 'vereinsplugin' ) ); // phpcs:ignore
	} else {
		echo vp_bh_hilfe( __( 'Wie liest man die Doppik-Auswertung?', 'vereinsplugin' ), array( // phpcs:ignore
			__( 'Die <strong>Summen- und Saldenliste</strong> zeigt jedes Konto mit Stand am 1.1., allen Soll- und Haben-Buchungen des Jahres und dem Saldo (Soll − Haben). Bestandskonten haben meist einen positiven Saldo, Ertragskonten einen negativen – das ist normal.', 'vereinsplugin' ),
			__( 'Die <strong>Gewinn- und Verlustrechnung (GuV)</strong> fasst die Erfolgskonten zusammen: Erträge − Aufwendungen = Jahresergebnis. Die <strong>Vermögensübersicht</strong> listet, was am Jahresende da ist (Geld, Forderungen) und was ihr schuldet (Verbindlichkeiten).', 'vereinsplugin' ),
		) );
		echo $kpi; // phpcs:ignore
		echo '<h3>' . esc_html__( 'Summen- und Saldenliste', 'vereinsplugin' ) . '</h3>';
		echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Konto', 'vereinsplugin' ) . '</th><th>' . esc_html__( 'Typ', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Stand 1.1.', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Soll', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Haben', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Saldo', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
		$typen = function_exists( 'vp_skr_typen' ) ? vp_skr_typen() : array();
		foreach ( vp_doppik_salden( $jahr ) as $sl ) {
			echo '<tr><td>' . $kb_link( $sl['konto'] ) . '</td><td class="vp-muted">' . esc_html( $typen[ $sl['typ'] ] ?? $sl['typ'] ) . '</td><td style="text-align:right">' . $num( $sl['anfang'] ) . '</td><td style="text-align:right">' . $num( $sl['soll'] ) . '</td><td style="text-align:right">' . $num( $sl['haben'] ) . '</td><td style="text-align:right"><strong>' . $num( $sl['saldo'] ) . '</strong></td></tr>'; // phpcs:ignore
		}
		echo '</tbody></table></div>';
		echo '<h3>' . esc_html__( 'Gewinn- und Verlustrechnung', 'vereinsplugin' ) . '</h3>' . $sph_tabelle; // phpcs:ignore
		echo $konto_tabelle( 'einnahme', __( 'Erträge', 'vereinsplugin' ) ) . $konto_tabelle( 'ausgabe', __( 'Aufwendungen', 'vereinsplugin' ) ); // phpcs:ignore
		echo '<h3>' . esc_html__( 'Vermögensübersicht am 31.12.', 'vereinsplugin' ) . '</h3>' . $geld_tabelle; // phpcs:ignore
		$sonst = array_filter( vp_doppik_salden( $jahr ), function ( $sl ) {
			return ! vp_bh_ist_erfolg( $sl['konto'] ) && 'geld' !== $sl['typ'] && abs( $sl['saldo'] ) > 0.001;
		} );
		if ( $sonst ) {
			echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Weitere Bestandskonten', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Saldo 31.12.', 'vereinsplugin' ) . '</th></tr></thead><tbody>';
			foreach ( $sonst as $sl ) {
				echo '<tr><td>' . $kb_link( $sl['konto'] ) . '</td><td style="text-align:right">' . $num( $sl['saldo'] ) . '</td></tr>'; // phpcs:ignore
			}
			echo '</tbody></table></div>';
		}
	}

	if ( is_callable( 'jb_page_export' ) || function_exists( 'jb_export_euer_csv' ) ) {
		echo '<p><a class="vp-btn" href="' . esc_url( admin_url( 'admin.php?page=jb_export&year=' . $jahr ) ) . '">' . esc_html__( 'EÜR- / DATEV-Export öffnen', 'vereinsplugin' ) . '</a></p>';
	}
	return ob_get_clean();
}

/* =========================================================================
 * Geschäftsjahr
 * ====================================================================== */

function vp_bh_geschaeftsjahr() {
	global $wpdb;
	$can_edit = current_user_can( 'jb_edit_journal' ) || current_user_can( 'manage_options' );
	$jahr     = vp_bh_gewaehltes_jahr();
	$t        = jb_table_anfangsbestaende();
	$msg      = '';
	$fehler   = false;
	$ok       = function () {
		return check_admin_referer( 'vp_bh_gj', 'vp_bh_gj_nonce' );
	};

	if ( $can_edit && isset( $_POST['vp_gj_methode'] ) && $ok() ) {
		$r = vp_bh_methode_setzen( $jahr, sanitize_key( wp_unslash( $_POST['vp_gj_methode'] ) ) );
		if ( is_wp_error( $r ) ) {
			$msg    = $r->get_error_message();
			$fehler = true;
		} else {
			/* translators: 1: year, 2: method */
			$msg = sprintf( __( '%1$d wird jetzt als %2$s geführt.', 'vereinsplugin' ), $jahr, vp_bh_methoden()[ vp_bh_methode( $jahr ) ] );
		}
	}
	if ( $can_edit && isset( $_POST['vp_anf_save'] ) && $ok() ) {
		$werte = (array) wp_unslash( $_POST['anf'] ?? array() );
		$neu_k = sanitize_text_field( wp_unslash( $_POST['anf_neu_konto'] ?? '' ) );
		if ( $neu_k ) {
			$werte[ $neu_k ] = wp_unslash( $_POST['anf_neu_betrag'] ?? '' );
		}
		foreach ( $werte as $konto => $roh ) {
			$konto = sanitize_text_field( (string) $konto );
			$roh   = trim( sanitize_text_field( (string) $roh ) );
			if ( '' === $konto ) {
				continue;
			}
			$da = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$t}` WHERE jahr = %d AND konto = %s", $jahr, $konto ) );
			if ( '' === $roh ) {
				if ( $da ) {
					$wpdb->delete( $t, array( 'id' => (int) $da ) );
				}
				continue;
			}
			// „1.234,56" und „1234.56" beide verstehen.
			$zahl   = false !== strpos( $roh, ',' ) ? str_replace( ',', '.', str_replace( '.', '', $roh ) ) : $roh;
			$betrag = round( (float) $zahl, 2 );
			if ( $da ) {
				$wpdb->update( $t, array( 'betrag' => $betrag ), array( 'id' => (int) $da ) );
			} else {
				$wpdb->insert( $t, array( 'jahr' => $jahr, 'konto' => $konto, 'betrag' => $betrag, 'erstellt_am' => current_time( 'mysql' ) ) );
			}
		}
		vp_bh_cache_leeren();
		/* translators: %d = year */
		$msg = sprintf( __( 'Anfangsbestände %d gespeichert.', 'vereinsplugin' ), $jahr );
	}
	if ( $can_edit && isset( $_POST['vp_gj_abschluss'] ) && $ok() ) {
		$r = vp_bh_jahresabschluss( $jahr );
		/* translators: 1: year, 2: count, 3: next year */
		$msg = sprintf( __( 'Jahresabschluss %1$d: %2$d Endbestände als Anfangsbestände %3$d übernommen.', 'vereinsplugin' ), $jahr, $r['konten'], $r['jahr'] );
	}
	if ( $can_edit && isset( $_POST['vp_quellen_save'] ) && $ok() ) {
		$zeilen = array();
		foreach ( (array) wp_unslash( $_POST['quelle_konto'] ?? array() ) as $q => $k ) {
			$q = sanitize_text_field( (string) $q );
			$k = sanitize_text_field( (string) $k );
			if ( '' !== $q && '' !== $k ) {
				$zeilen[] = $q . ' = ' . $k;
			}
		}
		update_option( 'jb_quelle_konto_map', implode( "\n", $zeilen ) );
		$msg = __( 'Vorgaben gespeichert. Sie gelten für neue automatische Buchungen; bestehende Buchungen bleiben, wo sie sind.', 'vereinsplugin' );
	}
	if ( $can_edit && isset( $_POST['vp_konten_merge'] ) && $ok() ) {
		$r = vp_bh_konten_zusammenlegen( wp_unslash( $_POST['von'] ?? '' ), wp_unslash( $_POST['nach'] ?? '' ) );
		if ( is_wp_error( $r ) ) {
			$msg    = $r->get_error_message();
			$fehler = true;
		} else {
			/* translators: 1: bookings, 2: opening balances */
			$msg = sprintf( __( 'Konten zusammengelegt: %1$d Buchungsfelder und %2$d Anfangsbestände umgehängt.', 'vereinsplugin' ), $r['buchungen'], $r['anfangsbestaende'] );
		}
	}

	$methode = vp_bh_methode( $jahr );
	$nonce   = wp_nonce_field( 'vp_bh_gj', 'vp_bh_gj_nonce', true, false );
	ob_start();
	echo '<h2>' . esc_html( sprintf( __( 'Geschäftsjahr %d', 'vereinsplugin' ), $jahr ) ) . '</h2>';
	if ( $msg ) {
		echo '<div class="vp-note' . ( $fehler ? ' vp-note-error' : '' ) . '">' . esc_html( $msg ) . '</div>';
	}
	echo vp_bh_jahresleiste( $jahr, 'jahr', true ); // phpcs:ignore

	/* ---- 1. Buchführungsart ---- */
	echo '<h3>' . esc_html__( '1. Buchführungsart festlegen', 'vereinsplugin' ) . '</h3>';
	echo '<p class="vp-muted">' . esc_html__( 'Legt zu Beginn jedes Geschäftsjahres fest, wie ihr bucht, und bleibt dann dabei – Steuerbüro und Kassenprüfung brauchen für ein Jahr eine einheitliche Methode. Die Wahl ändert nur, wie Buchungen eingegeben und ausgewertet werden. Die gespeicherten Buchungen bleiben dieselben, deshalb geht beim Wechsel nichts verloren.', 'vereinsplugin' ) . '</p>';
	$karten = array(
		'euer'   => array(
			__( 'Einnahmen-Überschuss-Rechnung (EÜR)', 'vereinsplugin' ),
			__( 'Ihr zählt, was an Geld tatsächlich rein- und rausgeht. Am Jahresende: Einnahmen − Ausgaben = Überschuss.', 'vereinsplugin' ),
			__( 'Ihr erfasst: Einnahme / Ausgabe / Umbuchung, Betrag, Geldkonto (Bank, Kasse, PayPal) und wofür (SKR-Konto).', 'vereinsplugin' ),
			__( 'Ihr bekommt: EÜR nach Sphären und Konten, Kontostände, Kontoauszüge.', 'vereinsplugin' ),
			__( 'Passt für: die meisten Vereine. Buchführungspflicht (und damit Doppik) entsteht erst bei großen wirtschaftlichen Geschäftsbetrieben (derzeit über 800.000 € Umsatz oder 80.000 € Gewinn, § 141 AO) oder wenn Satzung/Steuerbüro es verlangen.', 'vereinsplugin' ),
		),
		'doppik' => array(
			__( 'Doppelte Buchführung (Doppik)', 'vereinsplugin' ),
			__( 'Jede Buchung ist ein Buchungssatz „Soll an Haben": ein Konto bekommt den Betrag, ein anderes gibt ihn ab. Auch Vorgänge ohne Geldfluss (offene Rechnungen, Verbindlichkeiten) lassen sich buchen.', 'vereinsplugin' ),
			__( 'Ihr erfasst: Soll-Konto, Haben-Konto, Betrag. Beispiel Beitrag per Überweisung: 1200 Bank an 4100 Mitgliedsbeiträge.', 'vereinsplugin' ),
			__( 'Ihr bekommt: Summen- und Saldenliste, Kontenblätter, Gewinn- und Verlustrechnung, Vermögensübersicht.', 'vereinsplugin' ),
			__( 'Passt für: Vereine mit Buchführungspflicht oder wenn ihr Forderungen und Verbindlichkeiten sauber abbilden wollt.', 'vereinsplugin' ),
		),
	);
	echo '<form method="post">' . $nonce . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">'; // phpcs:ignore
	foreach ( $karten as $k => $c ) {
		$aktiv = $k === $methode;
		echo '<div class="vp-card" style="margin:0;' . ( $aktiv ? 'border:2px solid #166534' : '' ) . '">';
		echo '<strong>' . esc_html( $c[0] ) . '</strong>' . ( $aktiv ? ' <span style="color:#166534">✓ ' . esc_html__( 'aktiv', 'vereinsplugin' ) . '</span>' : '' );
		for ( $i = 1; $i <= 4; $i++ ) {
			echo '<p style="margin:8px 0 0"' . ( 4 === $i ? ' class="vp-muted"' : '' ) . '>' . esc_html( $c[ $i ] ) . '</p>';
		}
		if ( $can_edit && ! $aktiv ) {
			/* translators: 1: method, 2: year */
			$frage = sprintf( __( '%1$s für %2$d verwenden? Die Buchungen bleiben unverändert.', 'vereinsplugin' ), $c[0], $jahr );
			echo '<p><button class="vp-btn vp-btn-primary" name="vp_gj_methode" value="' . esc_attr( $k ) . '" onclick="return confirm(\'' . esc_js( $frage ) . '\')">' . esc_html( sprintf( __( 'Für %d verwenden', 'vereinsplugin' ), $jahr ) ) . '</button></p>';
		} elseif ( $can_edit && ! vp_bh_methode_gesetzt( $jahr ) ) {
			echo '<p><button class="vp-btn vp-btn-primary" name="vp_gj_methode" value="' . esc_attr( $k ) . '">' . esc_html( sprintf( __( 'Für %d bestätigen', 'vereinsplugin' ), $jahr ) ) . '</button></p>';
		}
		echo '</div>';
	}
	echo '</div></form>';
	$nicht = vp_bh_nicht_euer_konform( $jahr );
	if ( 'doppik' === $methode && $nicht ) {
		/* translators: %d = count */
		echo '<p class="vp-muted">' . esc_html( sprintf( __( 'Hinweis: %d Buchung(en) dieses Jahres sind reine Buchungssätze ohne Geldkonto. Ein Wechsel zur EÜR geht erst, wenn sie geändert sind.', 'vereinsplugin' ), count( $nicht ) ) ) . '</p>';
	}

	/* ---- 2. Anfangsbestände ---- */
	$vorjahr = vp_bh_jahresdaten( $jahr - 1 );
	$werte   = array();
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT konto, betrag FROM `{$t}` WHERE jahr = %d", $jahr ), ARRAY_A ) as $r ) {
		$werte[ (string) $r['konto'] ] = (float) $r['betrag'];
	}
	$konten = vp_bh_geldkonten();
	foreach ( array_keys( $werte ) as $k ) {
		if ( ! isset( $konten[ $k ] ) ) {
			$konten[ $k ] = vp_bh_konto_name( $k ) ?: __( '(nicht im Kontenplan)', 'vereinsplugin' );
		}
	}
	echo '<h3>' . esc_html( sprintf( __( '2. Anfangsbestände am 1.1.%d', 'vereinsplugin' ), $jahr ) ) . '</h3>';
	echo '<p class="vp-muted">' . esc_html__( 'Der tatsächliche Stand jedes Geldkontos am 1. Januar (Kontoauszug, gezählte Kasse). Alle Auswertungen dieses und der folgenden Jahre rechnen davon aus. Ohne Eintrag wird der Stand aus den Vorjahren weitergerechnet.', 'vereinsplugin' ) . '</p>';
	echo '<form method="post">' . $nonce; // phpcs:ignore
	echo '<div class="vp-table-wrap"><table class="vp-table"><thead><tr><th>' . esc_html__( 'Konto', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html__( 'Anfangsbestand', 'vereinsplugin' ) . '</th><th style="text-align:right">' . esc_html( sprintf( __( 'Berechneter Endbestand %d', 'vereinsplugin' ), $jahr - 1 ) ) . '</th></tr></thead><tbody>';
	foreach ( $konten as $k => $name ) {
		$wert = isset( $werte[ $k ] ) ? number_format( $werte[ $k ], 2, ',', '' ) : '';
		$ende = isset( $vorjahr['konten'][ $k ] ) ? $vorjahr['konten'][ $k ]['ende'] / 100 : null;
		$abw  = null !== $ende && isset( $werte[ $k ] ) && abs( $ende - $werte[ $k ] ) > 0.004;
		echo '<tr><td>' . esc_html( $k . ' · ' . $name ) . '</td><td style="text-align:right">'
			. ( $can_edit ? '<input type="text" name="anf[' . esc_attr( $k ) . ']" inputmode="decimal" value="' . esc_attr( $wert ) . '" placeholder="—" style="width:120px;text-align:right">' : esc_html( $wert ?: '–' ) )
			. '</td><td style="text-align:right' . ( $abw ? ';color:#b91c1c' : '' ) . '">' . ( null === $ende ? '<span class="vp-muted">–</span>' : esc_html( vp_bh_eur( $ende ) ) . ( $abw ? ' ⚠' : '' ) ) . '</td></tr>';
	}
	if ( $can_edit ) {
		echo '<tr><td><select name="anf_neu_konto">' . vp_bh_konto_options( '', 'geld', __( '+ weiteres Bestandskonto …', 'vereinsplugin' ) ) . '</select></td><td style="text-align:right"><input type="text" name="anf_neu_betrag" inputmode="decimal" placeholder="0,00" style="width:120px;text-align:right"></td><td></td></tr>'; // phpcs:ignore
	}
	echo '</tbody></table></div>';
	if ( $can_edit ) {
		echo '<p><button class="vp-btn vp-btn-primary" name="vp_anf_save" value="1">' . esc_html__( 'Anfangsbestände speichern', 'vereinsplugin' ) . '</button> <span class="vp-muted">' . esc_html__( 'Leeres Feld = kein Anfangsbestand. ⚠ = weicht vom berechneten Endbestand des Vorjahres ab.', 'vereinsplugin' ) . '</span></p>';
	}
	echo '</form>';

	/* ---- 3. Jahresabschluss ---- */
	echo '<h3>' . esc_html( sprintf( __( '3. Jahresabschluss %d', 'vereinsplugin' ), $jahr ) ) . '</h3>';
	/* translators: 1: year, 2: next year */
	echo '<p class="vp-muted">' . esc_html( sprintf( __( 'Wenn %1$d fertig gebucht ist: Die berechneten Endbestände aller Geld- und Bestandskonten werden als Anfangsbestände %2$d eingetragen, und %2$d übernimmt die Buchführungsart, falls dort noch keine gewählt ist. Das lässt sich jederzeit wiederholen, z. B. wenn nachträglich noch gebucht wurde.', 'vereinsplugin' ), $jahr, $jahr + 1 ) ) . '</p>';
	$abw = vp_bh_abschluss_abweichungen( $jahr );
	if ( $abw ) {
		echo '<div class="vp-note vp-note-warn">' . esc_html( sprintf( __( 'Die Anfangsbestände %d passen nicht mehr zu den Endbeständen dieses Jahres:', 'vereinsplugin' ), $jahr + 1 ) );
		foreach ( $abw as $a ) {
			echo '<br>' . esc_html( vp_bh_konto_label( $a['konto'] ) . ': ' . vp_bh_eur( $a['ende'] ) . ' ≠ ' . vp_bh_eur( $a['anfang_folgejahr'] ) );
		}
		echo '</div>';
	}
	if ( $can_edit ) {
		/* translators: 1: year, 2: next year */
		$frage = sprintf( __( 'Endbestände %1$d als Anfangsbestände %2$d übernehmen?', 'vereinsplugin' ), $jahr, $jahr + 1 );
		echo '<form method="post">' . $nonce . '<p><button class="vp-btn" name="vp_gj_abschluss" value="1" onclick="return confirm(\'' . esc_js( $frage ) . '\')">' . esc_html( sprintf( __( 'Endbestände %1$d → Anfangsbestände %2$d', 'vereinsplugin' ), $jahr, $jahr + 1 ) ) . '</button></p></form>'; // phpcs:ignore
	}

	/* ---- 4. Geldkonten & Vorgaben ---- */
	echo '<h3>' . esc_html__( '4. Geldkonten und Vorgaben für automatische Buchungen', 'vereinsplugin' ) . '</h3>';
	echo '<p class="vp-muted">' . esc_html__( 'Geldkonten sind die Konten, auf denen echtes Geld liegt. Welche das sind, legt ihr im Kontenplan über den Typ „Geldkonto" fest. Zettle-Kartenzahlungen landen auf dem PayPal-Konto – beides ist dasselbe Geld.', 'vereinsplugin' ) . '</p>';
	echo '<p class="vp-muted">' . esc_html__( 'Z-Bon, Bank-Import, SEPA-Einzug, Rechnungen und Auslagen buchen selbstständig. Hier stellt ihr ein, auf welches Konto sie gehen. Eine Änderung gilt für neue Buchungen – bestehende bleiben, wo sie sind (dafür gibt es unten „Konten zusammenlegen").', 'vereinsplugin' ) . '</p>';
	$map = vp_doppik_map();
	echo '<form method="post">' . $nonce . '<div class="vp-table-wrap"><table class="vp-table"><tbody>'; // phpcs:ignore
	foreach ( vp_bh_vorgabe_quellen() as $q => $label ) {
		echo '<tr><td>' . esc_html( $label ) . '</td><td>'
			. ( $can_edit ? '<select name="quelle_konto[' . esc_attr( $q ) . ']">' . vp_bh_konto_options( $map[ $q ] ?? '', 'geld' ) . '</select>' : esc_html( vp_bh_konto_label( $map[ $q ] ?? '' ) ) )
			. '</td></tr>';
	}
	echo '</tbody></table></div>';
	if ( $can_edit ) {
		echo '<p><button class="vp-btn vp-btn-primary" name="vp_quellen_save" value="1">' . esc_html__( 'Vorgaben speichern', 'vereinsplugin' ) . '</button></p>';
	}
	echo '</form>';

	/* ---- 5. Konten zusammenlegen ---- */
	if ( $can_edit ) {
		echo '<h3>' . esc_html__( '5. Konten zusammenlegen', 'vereinsplugin' ) . '</h3>';
		echo '<p class="vp-muted">' . esc_html__( 'Wenn zwei Konten eigentlich dasselbe sind (z. B. ein altes und ein neues PayPal-Konto): Alle Buchungen, Anfangsbestände, Budgets und Regeln des ersten Kontos wandern auf das zweite, das erste wird deaktiviert. Das gilt für alle Jahre und lässt sich nicht automatisch rückgängig machen.', 'vereinsplugin' ) . '</p>';
		echo '<form method="post" class="vp-form">' . $nonce . '<div class="vp-form-grid">'; // phpcs:ignore
		echo '<label>' . esc_html__( 'Dieses Konto …', 'vereinsplugin' ) . '<select name="von">' . vp_bh_konto_options( '', 'alle', '–' ) . '</select></label>'; // phpcs:ignore
		echo '<label>' . esc_html__( '… geht auf in', 'vereinsplugin' ) . '<select name="nach">' . vp_bh_konto_options( '', 'alle', '–' ) . '</select></label>'; // phpcs:ignore
		echo '</div><p><button class="vp-btn vp-btn-danger" name="vp_konten_merge" value="1" onclick="return confirm(\'' . esc_js( __( 'Konten wirklich zusammenlegen? Das betrifft alle Jahre.', 'vereinsplugin' ) ) . '\')">' . esc_html__( 'Zusammenlegen', 'vereinsplugin' ) . '</button></p></form>';
	}

	/* ---- Umstellungsbericht ---- */
	$bericht = get_option( 'jb_umstellung_v10_bericht' );
	if ( is_array( $bericht ) ) {
		$zeilen = array(
			/* translators: 1: date, 2: converted, 3: total */
			sprintf( __( 'Am %1$s wurden %2$d von %3$d Buchungen auf feste Geldkonten umgestellt. Jede Buchung wurde vorher und nachher als Buchungssatz verglichen.', 'vereinsplugin' ), $bericht['datum'], $bericht['umgestellt'], $bericht['buchungen'] ),
			$bericht['abweichungen']
				? sprintf( __( 'Nicht umgestellt, weil sich der Buchungssatz geändert hätte: #%s', 'vereinsplugin' ), implode( ', #', $bericht['abweichungen'] ) )
				: __( 'Keine Abweichungen – alle Kontostände sind unverändert.', 'vereinsplugin' ),
			/* translators: %s = table */
			sprintf( __( 'Sicherungskopie der Buchungen vor der Umstellung: Tabelle %s.', 'vereinsplugin' ), $bericht['sicherung'] ),
		);
		foreach ( (array) $bericht['hinweise'] as $h ) {
			$zeilen[] = $h;
		}
		echo vp_bh_hilfe( __( 'Umstellung auf feste Geldkonten (v0.33)', 'vereinsplugin' ), array_map( 'esc_html', $zeilen ) ); // phpcs:ignore
	}
	return ob_get_clean();
}
