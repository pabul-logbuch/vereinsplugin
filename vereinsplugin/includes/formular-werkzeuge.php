<?php
/**
 * Formulare: Vorlagen, Auswertung der Einträge und Mail an Ausgewählte.
 *
 *  - Vorlagen (Anmeldung zu einer Veranstaltung, Umfrage, Feedback,
 *    Helfer:innen, Kurs/Workshop mit Jugendlichen, Terminumfrage, Kontakt)
 *    füllen ein neues Formular mit passenden Feldern vor – danach ganz normal
 *    bearbeitbar. Eigene Vorlagen per Filter `vp_formular_vorlagen`.
 *  - Einträge: nach jeder Spalte sortieren, nach Antworten filtern (z. B.
 *    „Newsletter = ja“), Volltextsuche, Auswertung je Auswahlfeld (Anzahl
 *    und Anteil) und Summe/Durchschnitt bei Zahlenfeldern.
 *  - Mail an die gefilterten Personen: Adressen kopieren, BCC im eigenen
 *    Mailprogramm oder Rundmail von der Website (einzeln, mit {name} und
 *    {feld-schluessel} als Platzhalter).
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Vorlagen
 * ---------------------------------------------------------------------- */

/** Zustimmung zum Newsletter – in mehreren Vorlagen gleich. */
function vp_formular_newsletter_feld() {
	return array( 'label' => __( 'Newsletter: Ich möchte per Mail über weitere Veranstaltungen informiert werden', 'vereinsplugin' ), 'type' => 'checkbox' );
}

/**
 * @return array slug => [ name, kurz, titel, text, dank, felder: [ [label, type, required?, options?, show_if (Label des steuernden Felds), show_if_value] ] ]
 */
function vp_formular_vorlagen() {
	$nl = vp_formular_newsletter_feld();
	return apply_filters( 'vp_formular_vorlagen', array(
		'event' => array(
			'name'  => __( 'Anmeldung zu einer Veranstaltung', 'vereinsplugin' ),
			'kurz'  => __( 'Name, Kontakt, Personenzahl, Essen, Fotoerlaubnis, Newsletter', 'vereinsplugin' ),
			'titel' => __( 'Anmeldung: ', 'vereinsplugin' ),
			'text'  => __( 'Melde dich hier an – wir freuen uns auf dich!', 'vereinsplugin' ),
			'dank'  => __( 'Danke für deine Anmeldung! Du bekommst gleich eine Bestätigung per Mail.', 'vereinsplugin' ),
			'felder' => array(
				array( 'label' => __( 'Name', 'vereinsplugin' ), 'type' => 'text', 'required' => 1 ),
				array( 'label' => __( 'E-Mail', 'vereinsplugin' ), 'type' => 'email', 'required' => 1 ),
				array( 'label' => __( 'Telefon', 'vereinsplugin' ), 'type' => 'tel' ),
				array( 'label' => __( 'Anzahl Personen', 'vereinsplugin' ), 'type' => 'number', 'required' => 1 ),
				array( 'label' => __( 'Essen', 'vereinsplugin' ), 'type' => 'select', 'options' => array( __( 'keine Besonderheiten', 'vereinsplugin' ), __( 'vegetarisch', 'vereinsplugin' ), __( 'vegan', 'vereinsplugin' ) ) ),
				array( 'label' => __( 'Allergien / Unverträglichkeiten', 'vereinsplugin' ), 'type' => 'textarea' ),
				array( 'label' => __( 'Fotos: Ich bin einverstanden, dass Fotos von mir veröffentlicht werden', 'vereinsplugin' ), 'type' => 'checkbox' ),
				$nl,
				array( 'label' => __( 'Bemerkung', 'vereinsplugin' ), 'type' => 'textarea' ),
			),
		),
		'umfrage' => array(
			'name'  => __( 'Umfrage', 'vereinsplugin' ),
			'kurz'  => __( 'Zufriedenheit, Interessen, Wünsche, Alter – Auswertung in den Einträgen', 'vereinsplugin' ),
			'titel' => __( 'Umfrage: ', 'vereinsplugin' ),
			'text'  => __( 'Wir möchten wissen, was dir wichtig ist. Die Umfrage dauert ca. 3 Minuten.', 'vereinsplugin' ),
			'dank'  => __( 'Danke fürs Mitmachen!', 'vereinsplugin' ),
			'felder' => array(
				array( 'label' => __( 'Wie zufrieden bist du mit unseren Angeboten?', 'vereinsplugin' ), 'type' => 'select', 'required' => 1, 'options' => array( __( 'sehr zufrieden', 'vereinsplugin' ), __( 'zufrieden', 'vereinsplugin' ), __( 'geht so', 'vereinsplugin' ), __( 'unzufrieden', 'vereinsplugin' ) ) ),
				array( 'label' => __( 'Was interessiert dich?', 'vereinsplugin' ), 'type' => 'checkboxes', 'options' => array( __( 'Konzerte', 'vereinsplugin' ), __( 'Workshops', 'vereinsplugin' ), __( 'Sport', 'vereinsplugin' ), __( 'Politik & Beteiligung', 'vereinsplugin' ), __( 'Ausflüge', 'vereinsplugin' ) ) ),
				array( 'label' => __( 'Was wünschst du dir von uns?', 'vereinsplugin' ), 'type' => 'textarea' ),
				array( 'label' => __( 'Alter', 'vereinsplugin' ), 'type' => 'select', 'options' => array( __( 'unter 14', 'vereinsplugin' ), '14–17', '18–21', '22–27', __( 'älter', 'vereinsplugin' ) ) ),
				array( 'label' => __( 'Möchtest du über die Ergebnisse informiert werden?', 'vereinsplugin' ), 'type' => 'checkbox' ),
				array( 'label' => __( 'E-Mail', 'vereinsplugin' ), 'type' => 'email', 'show_if' => __( 'Möchtest du über die Ergebnisse informiert werden?', 'vereinsplugin' ), 'show_if_value' => '1' ),
			),
		),
		'feedback' => array(
			'name'  => __( 'Feedback nach einer Veranstaltung', 'vereinsplugin' ),
			'kurz'  => __( 'Gesamteindruck, Highlights, Kritik, wiederkommen?', 'vereinsplugin' ),
			'titel' => __( 'Feedback: ', 'vereinsplugin' ),
			'text'  => __( 'Wie war’s? Dein Feedback hilft uns, beim nächsten Mal besser zu werden.', 'vereinsplugin' ),
			'dank'  => __( 'Danke für dein Feedback!', 'vereinsplugin' ),
			'felder' => array(
				array( 'label' => __( 'Gesamteindruck', 'vereinsplugin' ), 'type' => 'select', 'required' => 1, 'options' => array( __( 'super', 'vereinsplugin' ), __( 'gut', 'vereinsplugin' ), __( 'okay', 'vereinsplugin' ), __( 'nicht so gut', 'vereinsplugin' ) ) ),
				array( 'label' => __( 'Was hat dir besonders gefallen?', 'vereinsplugin' ), 'type' => 'textarea' ),
				array( 'label' => __( 'Was können wir besser machen?', 'vereinsplugin' ), 'type' => 'textarea' ),
				array( 'label' => __( 'Würdest du wiederkommen?', 'vereinsplugin' ), 'type' => 'select', 'options' => array( __( 'ja', 'vereinsplugin' ), __( 'vielleicht', 'vereinsplugin' ), __( 'nein', 'vereinsplugin' ) ) ),
				array( 'label' => __( 'Name (freiwillig)', 'vereinsplugin' ), 'type' => 'text' ),
				array( 'label' => __( 'E-Mail (freiwillig)', 'vereinsplugin' ), 'type' => 'email' ),
				$nl,
			),
		),
		'helfer' => array(
			'name'  => __( 'Helfer:innen-Anmeldung', 'vereinsplugin' ),
			'kurz'  => __( 'Kontakt, Zeiten, Aufgaben, T-Shirt-Größe', 'vereinsplugin' ),
			'titel' => __( 'Helfen bei: ', 'vereinsplugin' ),
			'text'  => __( 'Du willst mit anpacken? Super! Sag uns, wann und wobei du helfen kannst.', 'vereinsplugin' ),
			'dank'  => __( 'Danke, dass du hilfst! Wir melden uns mit dem Schichtplan.', 'vereinsplugin' ),
			'felder' => array(
				array( 'label' => __( 'Name', 'vereinsplugin' ), 'type' => 'text', 'required' => 1 ),
				array( 'label' => __( 'E-Mail', 'vereinsplugin' ), 'type' => 'email', 'required' => 1 ),
				array( 'label' => __( 'Telefon', 'vereinsplugin' ), 'type' => 'tel', 'required' => 1 ),
				array( 'label' => __( 'Wann kannst du?', 'vereinsplugin' ), 'type' => 'checkboxes', 'required' => 1, 'options' => array( __( 'Aufbau', 'vereinsplugin' ), __( 'während der Veranstaltung', 'vereinsplugin' ), __( 'Abbau', 'vereinsplugin' ) ) ),
				array( 'label' => __( 'Wobei hilfst du gern?', 'vereinsplugin' ), 'type' => 'checkboxes', 'options' => array( __( 'Bar / Ausschank', 'vereinsplugin' ), __( 'Kasse / Einlass', 'vereinsplugin' ), __( 'Technik', 'vereinsplugin' ), __( 'Deko', 'vereinsplugin' ), __( 'Küche', 'vereinsplugin' ), __( 'egal', 'vereinsplugin' ) ) ),
				array( 'label' => __( 'T-Shirt-Größe', 'vereinsplugin' ), 'type' => 'select', 'options' => array( 'XS', 'S', 'M', 'L', 'XL', 'XXL' ) ),
				array( 'label' => __( 'Bemerkung', 'vereinsplugin' ), 'type' => 'textarea' ),
				$nl,
			),
		),
		'kurs' => array(
			'name'  => __( 'Kurs / Workshop (auch für Minderjährige)', 'vereinsplugin' ),
			'kurz'  => __( 'mit Einverständnis der Erziehungsberechtigten, nur wenn unter 18', 'vereinsplugin' ),
			'titel' => __( 'Workshop: ', 'vereinsplugin' ),
			'text'  => __( 'Melde dich für den Workshop an. Die Plätze sind begrenzt.', 'vereinsplugin' ),
			'dank'  => __( 'Danke für deine Anmeldung! Wir melden uns vor dem Workshop.', 'vereinsplugin' ),
			'felder' => array(
				array( 'label' => __( 'Name', 'vereinsplugin' ), 'type' => 'text', 'required' => 1 ),
				array( 'label' => __( 'E-Mail', 'vereinsplugin' ), 'type' => 'email', 'required' => 1 ),
				array( 'label' => __( 'Bist du unter 18?', 'vereinsplugin' ), 'type' => 'select', 'required' => 1, 'options' => array( __( 'nein', 'vereinsplugin' ), __( 'ja', 'vereinsplugin' ) ) ),
				array( 'label' => __( 'Name einer erziehungsberechtigten Person', 'vereinsplugin' ), 'type' => 'text', 'show_if' => __( 'Bist du unter 18?', 'vereinsplugin' ), 'show_if_value' => __( 'ja', 'vereinsplugin' ) ),
				array( 'label' => __( 'Telefon für Notfälle', 'vereinsplugin' ), 'type' => 'tel', 'show_if' => __( 'Bist du unter 18?', 'vereinsplugin' ), 'show_if_value' => __( 'ja', 'vereinsplugin' ) ),
				array( 'label' => __( 'Einverständnis: Meine erziehungsberechtigte Person ist mit der Teilnahme einverstanden', 'vereinsplugin' ), 'type' => 'checkbox', 'show_if' => __( 'Bist du unter 18?', 'vereinsplugin' ), 'show_if_value' => __( 'ja', 'vereinsplugin' ) ),
				array( 'label' => __( 'Vorkenntnisse', 'vereinsplugin' ), 'type' => 'select', 'options' => array( __( 'keine', 'vereinsplugin' ), __( 'ein bisschen', 'vereinsplugin' ), __( 'viel', 'vereinsplugin' ) ) ),
				array( 'label' => __( 'Fotos: Ich bin einverstanden, dass Fotos von mir veröffentlicht werden', 'vereinsplugin' ), 'type' => 'checkbox' ),
				$nl,
			),
		),
		'termin' => array(
			'name'  => __( 'Terminumfrage', 'vereinsplugin' ),
			'kurz'  => __( 'Wer kann wann? Mehrfachauswahl der Termine', 'vereinsplugin' ),
			'titel' => __( 'Terminfindung: ', 'vereinsplugin' ),
			'text'  => __( 'Kreuze alle Termine an, an denen du kannst.', 'vereinsplugin' ),
			'dank'  => __( 'Danke! Wir geben den Termin bald bekannt.', 'vereinsplugin' ),
			'felder' => array(
				array( 'label' => __( 'Name', 'vereinsplugin' ), 'type' => 'text', 'required' => 1 ),
				array( 'label' => __( 'An welchen Terminen kannst du?', 'vereinsplugin' ), 'type' => 'checkboxes', 'required' => 1, 'options' => array( __( 'Termin 1 (bitte Datum eintragen)', 'vereinsplugin' ), __( 'Termin 2', 'vereinsplugin' ), __( 'Termin 3', 'vereinsplugin' ) ) ),
				array( 'label' => __( 'E-Mail (für die Info zum Termin)', 'vereinsplugin' ), 'type' => 'email' ),
				array( 'label' => __( 'Bemerkung', 'vereinsplugin' ), 'type' => 'textarea' ),
			),
		),
		'kontakt' => array(
			'name'  => __( 'Kontakt / Anfrage', 'vereinsplugin' ),
			'kurz'  => __( 'Name, E-Mail, Thema, Nachricht', 'vereinsplugin' ),
			'titel' => __( 'Kontakt', 'vereinsplugin' ),
			'text'  => __( 'Schreib uns – wir melden uns so bald wie möglich.', 'vereinsplugin' ),
			'dank'  => __( 'Danke für deine Nachricht!', 'vereinsplugin' ),
			'felder' => array(
				array( 'label' => __( 'Name', 'vereinsplugin' ), 'type' => 'text', 'required' => 1 ),
				array( 'label' => __( 'E-Mail', 'vereinsplugin' ), 'type' => 'email', 'required' => 1 ),
				array( 'label' => __( 'Thema', 'vereinsplugin' ), 'type' => 'select', 'options' => array( __( 'Allgemeine Frage', 'vereinsplugin' ), __( 'Mitmachen', 'vereinsplugin' ), __( 'Raum / Ausleihe', 'vereinsplugin' ), __( 'Presse', 'vereinsplugin' ), __( 'Sonstiges', 'vereinsplugin' ) ) ),
				array( 'label' => __( 'Nachricht', 'vereinsplugin' ), 'type' => 'textarea', 'required' => 1 ),
				$nl,
			),
		),
	) );
}

/**
 * Felder einer Vorlage in das gespeicherte Format bringen – Schlüssel wie
 * vp_formular_felder_aus_post() sie bildet, Bedingungen über den Schlüssel.
 */
function vp_formular_vorlage_felder( array $v ) {
	$out  = array();
	$keys = array();
	$nach_label = array();
	foreach ( $v['felder'] as $i => $feld ) {
		$key  = sanitize_title( $feld['label'] ) ?: 'feld' . ( $i + 1 );
		$base = $key;
		$n    = 2;
		while ( in_array( $key, $keys, true ) ) {
			$key = $base . '-' . $n++;
		}
		$keys[]                        = $key;
		$nach_label[ $feld['label'] ] = $key;
		$out[]                         = array(
			'key'           => $key,
			'label'         => $feld['label'],
			'type'          => $feld['type'],
			'required'      => empty( $feld['required'] ) ? 0 : 1,
			'options'       => (array) ( $feld['options'] ?? array() ),
			'show_if'       => (string) ( $feld['show_if'] ?? '' ),
			'show_if_value' => (string) ( $feld['show_if_value'] ?? '' ),
		);
	}
	foreach ( $out as $i => $feld ) {
		if ( '' !== $feld['show_if'] ) {
			$out[ $i ]['show_if'] = $nach_label[ $feld['show_if'] ] ?? '';
		}
	}
	return $out;
}

/** Vorbelegtes (noch nicht gespeichertes) Formular aus einer Vorlage. */
function vp_formular_aus_vorlage( $slug ) {
	$v = vp_formular_vorlagen()[ $slug ] ?? null;
	if ( ! $v ) {
		return null;
	}
	return (object) array(
		'id'                     => 0,
		'titel'                  => $v['titel'],
		'slug'                   => '',
		'beschreibung'           => $v['text'],
		'dank_text'              => $v['dank'],
		'felder'                 => vp_formular_vorlage_felder( $v ),
		'status'                 => 'entwurf',
		'max_teilnehmer'         => null,
		'login_pflicht'          => 0,
		'schliesst_am'           => null,
		'benachrichtigung_email' => '',
	);
}

/** Auswahl „Neues Formular“: leer oder aus einer Vorlage. */
function vp_formular_vorlagen_auswahl( $base ) {
	$h  = '<details class="vp-card" open><summary><strong>' . esc_html__( '+ Neues Formular', 'vereinsplugin' ) . '</strong></summary>';
	$h .= '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin-top:10px">';
	$h .= '<a class="vp-card" style="margin:0;text-decoration:none;color:inherit" href="' . esc_url( add_query_arg( array( 'vp_tab' => 'formulare', 'vp_fview' => 'bearbeiten' ), $base ) ) . '"><strong>' . esc_html__( 'Leeres Formular', 'vereinsplugin' ) . '</strong><br><span class="vp-muted">' . esc_html__( 'Felder selbst zusammenstellen', 'vereinsplugin' ) . '</span></a>';
	foreach ( vp_formular_vorlagen() as $slug => $v ) {
		$h .= '<a class="vp-card" style="margin:0;text-decoration:none;color:inherit" href="' . esc_url( add_query_arg( array( 'vp_tab' => 'formulare', 'vp_fview' => 'bearbeiten', 'vp_vorlage' => $slug ), $base ) ) . '"><strong>' . esc_html( $v['name'] ) . '</strong><br><span class="vp-muted">' . esc_html( $v['kurz'] ) . '</span></a>';
	}
	return $h . '</div><p class="vp-muted">' . esc_html__( 'Eine Vorlage füllt nur vor – Titel ergänzen, Felder anpassen, speichern.', 'vereinsplugin' ) . '</p></details>';
}

/* -------------------------------------------------------------------------
 * Einträge: Filter, Sortierung, Auswertung
 * ---------------------------------------------------------------------- */

/** Wert eines Eintrags als Text (für Anzeige, Suche, Sortierung). */
function vp_formular_wert_text( $feld, $val ) {
	if ( 'checkbox' === $feld['type'] ) {
		return '' === $val || null === $val ? '' : ( $val ? __( 'ja', 'vereinsplugin' ) : __( 'nein', 'vereinsplugin' ) );
	}
	return is_array( $val ) ? implode( ', ', $val ) : (string) $val;
}

/** Auswahlfelder, nach denen gefiltert werden kann. */
function vp_formular_filterfelder( $f ) {
	return array_filter( $f->felder, static function ( $feld ) {
		return in_array( $feld['type'], array( 'select', 'checkboxes', 'checkbox' ), true );
	} );
}

/** Mögliche Werte eines Auswahlfelds (Optionen + tatsächlich vorkommende). */
function vp_formular_feld_werte( $feld, array $eintraege ) {
	if ( 'checkbox' === $feld['type'] ) {
		return array( __( 'ja', 'vereinsplugin' ), __( 'nein', 'vereinsplugin' ) );
	}
	$w = (array) ( $feld['options'] ?? array() );
	foreach ( $eintraege as $e ) {
		foreach ( (array) ( $e->daten[ $feld['key'] ] ?? array() ) as $v ) {
			if ( '' !== (string) $v && ! in_array( (string) $v, $w, true ) ) {
				$w[] = (string) $v;
			}
		}
	}
	return $w;
}

/**
 * Einträge filtern und sortieren.
 * @param array  $filter key => gewählter Wert
 * @param string $suche  Volltext
 * @param string $sort   Feldschlüssel oder 'eingereicht'
 */
function vp_formular_eintraege_auswahl( $f, array $eintraege, array $filter, $suche, $sort, $dir ) {
	$felder = array();
	foreach ( $f->felder as $feld ) {
		$felder[ $feld['key'] ] = $feld;
	}
	$suche = mb_strtolower( trim( (string) $suche ) );
	$out   = array_filter( $eintraege, static function ( $e ) use ( $filter, $felder, $suche ) {
		foreach ( $filter as $key => $wert ) {
			if ( '' === (string) $wert || ! isset( $felder[ $key ] ) ) {
				continue;
			}
			$val = $e->daten[ $key ] ?? '';
			if ( 'checkbox' === $felder[ $key ]['type'] ) {
				if ( ( ! empty( $val ) ? __( 'ja', 'vereinsplugin' ) : __( 'nein', 'vereinsplugin' ) ) !== $wert ) {
					return false;
				}
			} elseif ( ! in_array( (string) $wert, array_map( 'strval', (array) $val ), true ) ) {
				return false;
			}
		}
		if ( '' !== $suche ) {
			$alles = '';
			foreach ( $felder as $key => $feld ) {
				$alles .= ' ' . vp_formular_wert_text( $feld, $e->daten[ $key ] ?? '' );
			}
			if ( false === mb_strpos( mb_strtolower( $alles ), $suche ) ) {
				return false;
			}
		}
		return true;
	} );
	$out = array_values( $out );
	usort( $out, static function ( $a, $b ) use ( $sort, $dir, $felder ) {
		if ( isset( $felder[ $sort ] ) ) {
			$fa = $a->daten[ $sort ] ?? '';
			$fb = $b->daten[ $sort ] ?? '';
			if ( 'number' === $felder[ $sort ]['type'] ) {
				$c = (float) $fa <=> (float) $fb;
			} else {
				$c = strnatcasecmp( vp_formular_wert_text( $felder[ $sort ], $fa ), vp_formular_wert_text( $felder[ $sort ], $fb ) );
			}
		} else {
			$c = strcmp( (string) $a->eingereicht_am, (string) $b->eingereicht_am );
		}
		return 'asc' === $dir ? $c : -$c;
	} );
	return $out;
}

/** E-Mail-Adresse eines Eintrags (Anzeige-Mail oder erstes E-Mail-Feld). */
function vp_formular_eintrag_email( $f, $e ) {
	if ( is_email( $e->anzeige_email ?? '' ) ) {
		return $e->anzeige_email;
	}
	foreach ( $f->felder as $feld ) {
		if ( 'email' === $feld['type'] && is_email( $e->daten[ $feld['key'] ] ?? '' ) ) {
			return $e->daten[ $feld['key'] ];
		}
	}
	return '';
}

/** Auswertung: Anzahl je Antwort (Auswahlfelder), Summe/Ø (Zahlenfelder). */
function vp_formular_auswertung_html( $f, array $eintraege ) {
	$n = count( $eintraege );
	if ( ! $n ) {
		return '';
	}
	$h = '';
	foreach ( $f->felder as $feld ) {
		if ( in_array( $feld['type'], array( 'select', 'checkboxes', 'checkbox' ), true ) ) {
			$zahl = array();
			foreach ( vp_formular_feld_werte( $feld, $eintraege ) as $w ) {
				$zahl[ $w ] = 0;
			}
			foreach ( $eintraege as $e ) {
				$val = $e->daten[ $feld['key'] ] ?? null;
				if ( 'checkbox' === $feld['type'] ) {
					$zahl[ ! empty( $val ) ? __( 'ja', 'vereinsplugin' ) : __( 'nein', 'vereinsplugin' ) ]++;
					continue;
				}
				foreach ( (array) $val as $v ) {
					if ( '' !== (string) $v ) {
						$zahl[ (string) $v ] = ( $zahl[ (string) $v ] ?? 0 ) + 1;
					}
				}
			}
			$h .= '<div style="margin:0 0 12px"><strong>' . esc_html( $feld['label'] ) . '</strong>';
			foreach ( $zahl as $w => $z ) {
				$pz = round( 100 * $z / $n );
				$h .= '<div style="display:grid;grid-template-columns:minmax(120px,40%) 1fr 70px;gap:8px;align-items:center;font-size:.92em">'
					. '<span>' . esc_html( $w ) . '</span>'
					. '<span style="background:rgba(127,127,127,.15);border-radius:4px;height:10px"><span style="display:block;height:10px;border-radius:4px;background:var(--vp-accent,#2563eb);width:' . (int) $pz . '%"></span></span>'
					. '<span class="vp-muted" style="text-align:right">' . (int) $z . ' · ' . (int) $pz . ' %</span></div>';
			}
			$h .= '</div>';
		} elseif ( 'number' === $feld['type'] ) {
			$werte = array();
			foreach ( $eintraege as $e ) {
				if ( isset( $e->daten[ $feld['key'] ] ) && '' !== (string) $e->daten[ $feld['key'] ] ) {
					$werte[] = (float) str_replace( ',', '.', (string) $e->daten[ $feld['key'] ] );
				}
			}
			if ( $werte ) {
				$h .= '<p><strong>' . esc_html( $feld['label'] ) . ':</strong> ' . esc_html( sprintf( __( 'Summe %1$s · Durchschnitt %2$s', 'vereinsplugin' ), rtrim( rtrim( number_format( array_sum( $werte ), 2, ',', '.' ), '0' ), ',' ), number_format( array_sum( $werte ) / count( $werte ), 1, ',', '.' ) ) ) . '</p>';
			}
		}
	}
	return $h ? '<details class="vp-card"><summary><strong>' . esc_html( sprintf( __( 'Auswertung (%d Einträge)', 'vereinsplugin' ), $n ) ) . '</strong></summary><div style="margin-top:10px">' . $h . '</div></details>' : '';
}

/* -------------------------------------------------------------------------
 * Mail an Ausgewählte
 * ---------------------------------------------------------------------- */

/** Rundmail aus dem Formular-Bereich senden. @return string Meldung */
function vp_formular_rundmail_senden() {
	$f = vp_formular_get( (int) ( $_POST['formular_id'] ?? 0 ) );
	if ( ! $f ) {
		return __( 'Formular nicht gefunden.', 'vereinsplugin' );
	}
	$betreff = sanitize_text_field( wp_unslash( $_POST['betreff'] ?? '' ) );
	$text    = sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) );
	$ids     = array_map( 'intval', (array) ( $_POST['an'] ?? array() ) );
	if ( '' === $betreff || '' === $text || ! $ids ) {
		return __( 'Betreff, Text und mindestens eine:n Empfänger:in angeben.', 'vereinsplugin' );
	}
	$ich    = wp_get_current_user();
	$header = array( 'Content-Type: text/plain; charset=UTF-8' );
	if ( is_email( $ich->user_email ) ) {
		$header[] = 'Reply-To: ' . $ich->user_email;
	}
	$ok      = 0;
	$fehl    = 0;
	$gesehen = array();
	foreach ( vp_formular_eintraege( $f->id ) as $e ) {
		if ( ! in_array( (int) $e->id, $ids, true ) ) {
			continue;
		}
		$mail = vp_formular_eintrag_email( $f, $e );
		if ( ! $mail || isset( $gesehen[ strtolower( $mail ) ] ) ) {
			continue;
		}
		$gesehen[ strtolower( $mail ) ] = true;
		$ersetzen = array( '{name}' => $e->anzeige_name ?: __( 'du', 'vereinsplugin' ) );
		foreach ( $f->felder as $feld ) {
			if ( 'heading' !== $feld['type'] ) {
				$ersetzen[ '{' . $feld['key'] . '}' ] = vp_formular_wert_text( $feld, $e->daten[ $feld['key'] ] ?? '' );
			}
		}
		if ( wp_mail( $mail, strtr( $betreff, $ersetzen ), strtr( $text, $ersetzen ), $header ) ) {
			$ok++;
		} else {
			$fehl++;
		}
		if ( $ok + $fehl >= 300 ) {
			break;
		}
	}
	$log = (array) get_option( 'vp_formular_mail_log', array() );
	$log[ (int) $f->id ][] = array( 'zeit' => current_time( 'mysql' ), 'von' => get_current_user_id(), 'betreff' => $betreff, 'ok' => $ok );
	$log[ (int) $f->id ]   = array_slice( $log[ (int) $f->id ], -10 );
	update_option( 'vp_formular_mail_log', $log, false );
	return sprintf( __( 'Mail an %d Personen verschickt.', 'vereinsplugin' ), $ok ) . ( $fehl ? ' ' . sprintf( __( '%d konnten nicht verschickt werden.', 'vereinsplugin' ), $fehl ) : '' );
}

/** Mail-Bereich unter der Einträge-Tabelle. */
function vp_formular_mail_bereich_html( $f, array $auswahl, $gefiltert ) {
	$empf = array();
	foreach ( $auswahl as $e ) {
		$m = vp_formular_eintrag_email( $f, $e );
		if ( $m && ! isset( $empf[ strtolower( $m ) ] ) ) {
			$empf[ strtolower( $m ) ] = array( $e, $m );
		}
	}
	$h = '<details class="vp-card" id="vp-formular-mail"><summary><strong>' . esc_html( sprintf( __( '✉ Mail an diese %d Personen', 'vereinsplugin' ), count( $empf ) ) ) . '</strong>'
		. ( $gefiltert ? ' <span class="vp-muted">' . esc_html__( '(nur die gefilterten Einträge)', 'vereinsplugin' ) . '</span>' : '' ) . '</summary>';
	if ( ! $empf ) {
		return $h . '<p class="vp-muted">' . esc_html__( 'Keine E-Mail-Adressen in dieser Auswahl.', 'vereinsplugin' ) . '</p></details>';
	}
	$ich   = wp_get_current_user();
	$mails = array_column( array_values( $empf ), 1 );
	$h    .= '<p class="vp-muted">' . esc_html__( 'Für Newsletter und Werbung nur Personen anschreiben, die zugestimmt haben – dazu oben nach dem Zustimmungsfeld (z. B. „Newsletter“ = ja) filtern.', 'vereinsplugin' ) . '</p>';
	$h    .= '<label class="vp-muted">' . esc_html__( 'Adressen zum Kopieren (ins BCC-Feld)', 'vereinsplugin' ) . '<textarea readonly rows="2" style="width:100%" onclick="this.select()">' . esc_textarea( implode( ', ', $mails ) ) . '</textarea></label>';
	$h    .= '<p><a class="vp-btn" href="' . esc_attr( 'mailto:' . rawurlencode( $ich->user_email ) . '?bcc=' . rawurlencode( implode( ',', $mails ) ) ) . '">' . esc_html__( 'Im eigenen Mailprogramm öffnen (BCC)', 'vereinsplugin' ) . '</a></p>';
	$h    .= '<form method="post" class="vp-form">' . wp_nonce_field( 'vp_formular_edit', 'vp_fe_nonce', true, false ) . '<input type="hidden" name="formular_id" value="' . (int) $f->id . '">';
	$h    .= '<div style="max-height:180px;overflow:auto;border:1px solid rgba(127,127,127,.25);border-radius:8px;padding:6px 10px;margin-bottom:8px">';
	foreach ( $empf as $paar ) {
		$h .= '<label class="vp-check" style="margin:0"><input type="checkbox" name="an[]" value="' . (int) $paar[0]->id . '" checked> <span>' . esc_html( ( $paar[0]->anzeige_name ? $paar[0]->anzeige_name . ' – ' : '' ) . $paar[1] ) . '</span></label>';
	}
	$platz = array( '{name}' );
	foreach ( $f->felder as $feld ) {
		if ( 'heading' !== $feld['type'] ) {
			$platz[] = '{' . $feld['key'] . '}';
		}
	}
	$h .= '</div><label>' . esc_html__( 'Betreff', 'vereinsplugin' ) . '<input type="text" name="betreff" required value="' . esc_attr( $f->titel ) . '"></label>';
	$h .= '<label>' . esc_html__( 'Text', 'vereinsplugin' ) . ' <span class="vp-muted" style="font-weight:normal">' . esc_html( __( 'Platzhalter:', 'vereinsplugin' ) . ' ' . implode( ' ', $platz ) ) . '</span>'
		. '<textarea name="text" rows="8" required>' . esc_textarea( __( "Hallo {name},\n\n\n\nViele Grüße\n", 'vereinsplugin' ) . get_bloginfo( 'name' ) ) . '</textarea></label>';
	$h .= '<p class="vp-muted">' . esc_html( sprintf( __( 'Antworten gehen an %s.', 'vereinsplugin' ), $ich->user_email ) ) . '</p>';
	$h .= '<p><button class="vp-btn vp-btn-primary" name="vp_formular_rundmail" value="1" onclick="return confirm(\'' . esc_js( __( 'Mail jetzt an alle angehakten Personen senden?', 'vereinsplugin' ) ) . '\')">' . esc_html__( 'Mail senden', 'vereinsplugin' ) . '</button></p></form>';
	$log = (array) ( get_option( 'vp_formular_mail_log', array() )[ (int) $f->id ] ?? array() );
	if ( $log ) {
		$h .= '<p class="vp-muted">' . esc_html__( 'Zuletzt gesendet:', 'vereinsplugin' ) . '</p><ul>';
		foreach ( array_reverse( $log ) as $l ) {
			$u  = get_userdata( (int) $l['von'] );
			$h .= '<li class="vp-muted">' . esc_html( mysql2date( 'd.m.Y H:i', $l['zeit'] ) . ' · ' . ( $u ? $u->display_name : '' ) . ' · ' . $l['betreff'] . ' · ' . sprintf( __( '%d gesendet', 'vereinsplugin' ), $l['ok'] ) ) . '</li>';
		}
		$h .= '</ul>';
	}
	return $h . '</details>';
}
