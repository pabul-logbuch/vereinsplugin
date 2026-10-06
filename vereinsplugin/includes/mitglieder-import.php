<?php
/**
 * Kern: Mitglieder-Import (CSV/XML) mit Zuordnung zu bestehenden Konten.
 *
 * Ablauf in drei Schritten auf einer Admin-Seite:
 *   1. Datei hochladen – Spalten werden über Aliasse erkannt (einfaches
 *      „name;email“ ebenso wie der Voll-Export der alten Vereinsverwaltung mit
 *      Mitglieds-ID, Anschrift, Bankverbindung, Status und Gruppen).
 *   2. Vorschau – jede Zeile bekommt einen Vorschlag: bestehendes Konto
 *      verbinden (Treffer über Mitglieds-Nr., E-Mail oder Namen), neu anlegen
 *      oder überspringen. Werte aus „Status“ und „Gruppen“ werden einmal pro
 *      Wert zugeordnet (Mitgliedsart, ausgetreten, Kreis …).
 *   3. Ausführen – Konten anlegen/ergänzen, Ehemalige ohne Zugang ablegen,
 *      SEPA-Mandate und Kreis-Mitgliedschaften anlegen.
 *
 * Die geparste Datei liegt zwischen Schritt 1 und 3 in einem Transient der
 * importierenden Person (1 Stunde) und wird nach dem Import gelöscht.
 */

defined( 'ABSPATH' ) || exit;

define( 'VP_EHEMALIG_ROLE', 'vp_ehemalig' );

function vp_mimport_transient() {
	return 'vp_mimport_' . get_current_user_id();
}

/*
 * Die Seite hängt normalerweise am Wunschliste-Modul (Slug
 * „wunschliste-mitglieder-import“). Ist das Modul abgeschaltet, registriert der
 * Kern denselben Slug versteckt, damit die Links im Mitgliederbereich bleiben.
 */
add_action( 'admin_menu', function () {
	if ( ! function_exists( 'wl_member_import_page' ) ) {
		add_submenu_page( 'options.php', __( 'Mitglieder importieren', 'vereinsplugin' ), '', 'vp_manage_members', 'wunschliste-mitglieder-import', 'vp_mitglieder_import_page' );
	}
}, 20 );

function vp_mimport_url( $args = array() ) {
	return add_query_arg( $args, admin_url( 'admin.php?page=wunschliste-mitglieder-import' ) );
}

/* -------------------------------------------------------------------------
 * Einlesen
 * ---------------------------------------------------------------------- */

/** Spaltenname → internes Feld. Schlüssel sind normalisiert (a-z0-9). */
function vp_mimport_aliases() {
	$map = array(
		'nr'             => array( 'mitgliederid', 'mitgliedsid', 'mitgliedsnr', 'mitgliedsnummer', 'mitgliedernummer', 'mitgliedernr', 'nr' ),
		'name'           => array( 'mitglied', 'name', 'anzeigename' ),
		'vorname'        => array( 'vorname' ),
		'nachname'       => array( 'nachname', 'familienname' ),
		'email'          => array( 'email', 'mail', 'emailadresse' ),
		'username'       => array( 'username', 'benutzername', 'login' ),
		'geburtsdatum'   => array( 'geburtstag', 'geburtsdatum' ),
		'strasse'        => array( 'strasse', 'strassehausnummer', 'strassenr', 'adresse', 'anschrift' ),
		'adresszusatz'   => array( 'adresszusatz', 'zusatz' ),
		'plz'            => array( 'plz', 'postleitzahl' ),
		'ort'            => array( 'ort', 'wohnort', 'stadt' ),
		'land'           => array( 'land' ),
		'telefon'        => array( 'telefon', 'festnetz', 'tel' ),
		'mobil'          => array( 'mobile', 'mobil', 'handy', 'mobiltelefon' ),
		'eintritt'       => array( 'eintrittsdatum', 'eintritt', 'mitgliedseit' ),
		'austritt'       => array( 'austrittsdatumsterbetag', 'austrittsdatum', 'austritt', 'ausgetretenam' ),
		'notiz'          => array( 'bemerkungen', 'bemerkung', 'notiz' ),
		'beitrag'        => array( 'mitgliedsbeitrag', 'beitrag' ),
		'inhaber_typ'    => array( 'kontoinhaber' ),
		'inhaber'        => array( 'kontoinhabername' ),
		'inhaber_email'  => array( 'kontoinhaberemail' ),
		'iban'           => array( 'iban' ),
		'bic'            => array( 'bic' ),
		'mandatsdatum'   => array( 'mandatdatum', 'mandatsdatum' ),
		'mandatsref'     => array( 'mandatsreferenz', 'mandatsref' ),
		'status'         => array( 'status', 'mitgliedsstatus', 'mitgliedsart' ),
		'gruppen'        => array( 'gruppen', 'gruppe' ),
	);
	$out = array();
	foreach ( $map as $feld => $aliase ) {
		foreach ( $aliase as $a ) {
			$out[ $a ] = $feld;
		}
	}
	return $out;
}

function vp_mimport_norm_key( $h ) {
	$h = str_replace( "\xEF\xBB\xBF", '', (string) $h );
	$h = strtolower( remove_accents( trim( $h ) ) );
	return preg_replace( '/[^a-z0-9]/', '', $h );
}

/** Datei → Liste von Rohzeilen [spaltenname_normalisiert => wert]. */
function vp_mimport_read_file( $path, $ext ) {
	$raw = file_get_contents( $path );
	if ( false === $raw || '' === trim( $raw ) ) {
		return new WP_Error( 'empty', __( 'Die Datei ist leer oder konnte nicht gelesen werden.', 'vereinsplugin' ) );
	}
	// Alte Exporte kommen gern als Windows-1252.
	if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $raw, 'UTF-8' ) ) {
		$raw = mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );
	}
	$raw = str_replace( "\xEF\xBB\xBF", '', $raw );

	$rows = array();
	if ( 'xml' === $ext ) {
		libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $raw );
		if ( false === $xml ) {
			return new WP_Error( 'xml', __( 'Die XML-Datei konnte nicht gelesen werden.', 'vereinsplugin' ) );
		}
		foreach ( $xml->children() as $node ) {
			$row = array();
			foreach ( $node->children() as $child ) {
				$row[ vp_mimport_norm_key( $child->getName() ) ] = trim( (string) $child );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	$first = strtok( $raw, "\n" );
	$delim = ';';
	foreach ( array( ',', "\t" ) as $d ) {
		if ( substr_count( $first, $d ) > substr_count( $first, $delim ) ) {
			$delim = $d;
		}
	}
	$h = fopen( 'php://temp', 'r+' );
	fwrite( $h, $raw );
	rewind( $h );
	$header = fgetcsv( $h, 0, $delim, '"', '' );
	if ( ! $header ) {
		fclose( $h );
		return new WP_Error( 'header', __( 'Die Kopfzeile der CSV-Datei fehlt.', 'vereinsplugin' ) );
	}
	$header = array_map( 'vp_mimport_norm_key', $header );
	while ( ( $r = fgetcsv( $h, 0, $delim, '"', '' ) ) !== false ) {
		if ( count( $r ) === 1 && '' === trim( (string) $r[0] ) ) {
			continue;
		}
		$row = array();
		foreach ( $header as $i => $k ) {
			// Erste Spalte gewinnt, falls ein Name doppelt vorkommt.
			if ( '' !== $k && ! isset( $row[ $k ] ) ) {
				$row[ $k ] = trim( (string) ( $r[ $i ] ?? '' ) );
			}
		}
		$rows[] = $row;
	}
	fclose( $h );
	return $rows;
}

/** 'YYYY-MM-DD' | 'TT.MM.JJJJ' → 'YYYY-MM-DD'; Platzhalter wie 1900-01-01 → ''. */
function vp_mimport_date( $s ) {
	$s = trim( (string) $s );
	if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m ) ) {
		list( , $y, $mo, $d ) = $m;
	} elseif ( preg_match( '/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$/', $s, $m ) ) {
		list( , $d, $mo, $y ) = $m;
		if ( strlen( $y ) === 2 ) {
			$y = ( (int) $y > (int) gmdate( 'y' ) ? '19' : '20' ) . $y;
		}
	} else {
		return '';
	}
	if ( (int) $y <= 1900 || ! checkdate( (int) $mo, (int) $d, (int) $y ) ) {
		return '';
	}
	return sprintf( '%04d-%02d-%02d', $y, $mo, $d );
}

function vp_mimport_clean( $s ) {
	return trim( preg_replace( '/\s+/u', ' ', (string) $s ) );
}

/** Rohzeile → normalisierter Datensatz. */
function vp_mimport_normalize( array $raw, $zeile ) {
	$alias = vp_mimport_aliases();
	$f     = array();
	foreach ( $raw as $k => $v ) {
		if ( isset( $alias[ $k ] ) && ! isset( $f[ $alias[ $k ] ] ) ) {
			$f[ $alias[ $k ] ] = vp_mimport_clean( $v );
		}
	}
	$g = function ( $k ) use ( $f ) { return $f[ $k ] ?? ''; };

	$vorname  = $g( 'vorname' );
	$nachname = $g( 'nachname' );
	$name     = $g( 'name' ) ?: trim( $vorname . ' ' . $nachname );
	if ( $name && ! $vorname && ! $nachname ) {
		$pos      = strrpos( $name, ' ' );
		$vorname  = false === $pos ? $name : substr( $name, 0, $pos );
		$nachname = false === $pos ? '' : substr( $name, $pos + 1 );
	}

	$strasse = $g( 'strasse' );
	if ( $g( 'adresszusatz' ) ) {
		$strasse = trim( $strasse . ', ' . $g( 'adresszusatz' ), ', ' );
	}
	$tel = array_values( array_unique( array_filter( array( $g( 'mobil' ), $g( 'telefon' ) ) ) ) );

	$beitrag = str_replace( array( '€', ' ' ), '', $g( 'beitrag' ) );
	if ( preg_match( '/^\d{1,3}(\.\d{3})*,\d+$/', $beitrag ) || preg_match( '/^\d+,\d+$/', $beitrag ) ) {
		$beitrag = str_replace( array( '.', ',' ), array( '', '.' ), $beitrag );
	}
	$beitrag = is_numeric( $beitrag ) ? (float) $beitrag : '';

	// „Kontoinhaber“ ist im Altexport ein Typ („Konto gehört Mitglied“), sonst ein Name.
	$inhaber = $g( 'inhaber' );
	if ( ! $inhaber && $g( 'inhaber_typ' ) && false === stripos( $g( 'inhaber_typ' ), 'konto geh' ) ) {
		$inhaber = $g( 'inhaber_typ' );
	}
	$iban = strtoupper( preg_replace( '/\s+/', '', $g( 'iban' ) ) );

	$merkmale = array();
	foreach ( array( $g( 'status' ), $g( 'gruppen' ) ) as $liste ) {
		foreach ( explode( ',', $liste ) as $w ) {
			$w = trim( $w );
			if ( '' !== $w ) {
				$merkmale[] = $w;
			}
		}
	}

	return array(
		'zeile'         => $zeile,
		'nr'            => $g( 'nr' ),
		'name'          => $name,
		'vorname'       => $vorname,
		'nachname'      => $nachname,
		'email'         => strtolower( sanitize_email( $g( 'email' ) ) ),
		'username'      => $g( 'username' ),
		'geburtsdatum'  => vp_mimport_date( $g( 'geburtsdatum' ) ),
		'strasse'       => $strasse,
		'plz'           => $g( 'plz' ),
		'ort'           => $g( 'ort' ),
		'land'          => $g( 'land' ),
		'telefon'       => implode( ' · ', $tel ),
		'eintritt'      => vp_mimport_date( $g( 'eintritt' ) ),
		'austritt'      => vp_mimport_date( $g( 'austritt' ) ),
		'notiz'         => $g( 'notiz' ),
		'beitrag'       => $beitrag,
		'inhaber'       => $inhaber ?: $name,
		'inhaber_email' => sanitize_email( $g( 'inhaber_email' ) ),
		'iban'          => $iban,
		'bic'           => strtoupper( preg_replace( '/\s+/', '', $g( 'bic' ) ) ),
		'mandatsdatum'  => vp_mimport_date( $g( 'mandatsdatum' ) ),
		'mandatsref'    => $g( 'mandatsref' ),
		'merkmale'      => array_values( array_unique( $merkmale ) ),
	);
}

/* -------------------------------------------------------------------------
 * Merkmale (Status/Gruppen) und Kreise
 * ---------------------------------------------------------------------- */

function vp_mimport_kreise() {
	return function_exists( 'pp_get_gremien' ) ? pp_get_gremien( null, true ) : array();
}

/** Mögliche Zuordnungen für einen Status-/Gruppenwert. */
function vp_mimport_merkmal_optionen() {
	$o = array(
		''             => __( 'nur am Konto vermerken', 'vereinsplugin' ),
		'aktiv'        => __( 'Mitgliedsart: aktiv', 'vereinsplugin' ),
		'passiv'       => __( 'Mitgliedsart: passiv', 'vereinsplugin' ),
		'foerdernd'    => __( 'Mitgliedsart: fördernd', 'vereinsplugin' ),
		'ausgetreten'  => __( 'Ausgetreten / ehemalig', 'vereinsplugin' ),
		'pruefen'      => __( 'Zur Prüfung vormerken (Notiz)', 'vereinsplugin' ),
	);
	if ( function_exists( 'pp_get_gremien' ) ) {
		$o['kreis_neu'] = __( 'Neuen Kreis mit diesem Namen anlegen', 'vereinsplugin' );
		foreach ( vp_mimport_kreise() as $k ) {
			$o[ 'kreis:' . (int) $k->id ] = sprintf( __( 'Kreis: %s', 'vereinsplugin' ), $k->name );
		}
	}
	return $o;
}

function vp_mimport_merkmal_vorschlag( $wert ) {
	$w = vp_strtolower( $wert );
	if ( preg_match( '/ehemalig|ausgetreten|verstorben/u', $w ) ) {
		return 'ausgetreten';
	}
	if ( preg_match( '/unterstütz|support|förder|foerder/u', $w ) ) {
		return 'foerdernd';
	}
	if ( false !== strpos( $w, 'passiv' ) ) {
		return 'passiv';
	}
	if ( false !== strpos( $w, 'aktiv' ) ) {
		return 'aktiv';
	}
	if ( false !== strpos( $w, 'prüf' ) || false !== strpos( $w, 'klären' ) ) {
		return 'pruefen';
	}
	if ( preg_match( '/^(alle )?mitglieder?$/u', $w ) ) {
		return '';
	}
	foreach ( vp_mimport_kreise() as $k ) {
		if ( vp_strtolower( $k->name ) === $w ) {
			return 'kreis:' . (int) $k->id;
		}
	}
	return function_exists( 'pp_get_gremien' ) ? 'kreis_neu' : '';
}

/**
 * Status eines Datensatzes nach der Merkmal-Zuordnung.
 *
 * @return array ausgetreten (bool), art, pruefen (bool), kreise (Merkmalwerte)
 */
function vp_mimport_status( array $r, array $zuordnung ) {
	$heute = current_time( 'Y-m-d' );
	$s     = array(
		'ausgetreten' => ( $r['austritt'] && $r['austritt'] <= $heute ),
		'art'         => '',
		'pruefen'     => false,
		'kreise'      => array(),
	);
	foreach ( $r['merkmale'] as $m ) {
		$z = $zuordnung[ $m ] ?? '';
		if ( 'ausgetreten' === $z ) {
			$s['ausgetreten'] = true;
		} elseif ( in_array( $z, array( 'aktiv', 'passiv', 'foerdernd' ), true ) ) {
			$s['art'] = $s['art'] ?: $z;
		} elseif ( 'pruefen' === $z ) {
			$s['pruefen'] = true;
		} elseif ( 'kreis_neu' === $z || 0 === strpos( $z, 'kreis:' ) ) {
			$s['kreise'][] = $m;
		}
	}
	return $s;
}

/* -------------------------------------------------------------------------
 * Abgleich mit bestehenden Konten
 * ---------------------------------------------------------------------- */

function vp_mimport_user_index() {
	$idx = array( 'email' => array(), 'nr' => array(), 'name' => array(), 'users' => array() );
	foreach ( get_users( array( 'orderby' => 'display_name' ) ) as $u ) {
		$idx['users'][ $u->ID ] = $u;
		if ( $u->user_email ) {
			$idx['email'][ strtolower( $u->user_email ) ] = $u->ID;
		}
		$nr = (string) get_user_meta( $u->ID, 'vp_mitglieds_nr', true );
		if ( '' !== $nr ) {
			$idx['nr'][ $nr ] = $u->ID;
		}
		foreach ( array_unique( array( $u->display_name, trim( $u->first_name . ' ' . $u->last_name ) ) ) as $n ) {
			$n = vp_strtolower( remove_accents( trim( $n ) ) );
			if ( '' !== $n ) {
				$idx['name'][ $n ][] = $u->ID;
			}
		}
	}
	return $idx;
}

function vp_mimport_name_key( $n ) {
	return vp_strtolower( remove_accents( trim( $n ) ) );
}

/**
 * Vorschlag: welches Konto gehört zu dieser Zeile?
 *
 * @return array [user_id, grund] – user_id 0 = keiner.
 */
function vp_mimport_match( array $r, array $idx, array $email_count ) {
	if ( '' !== $r['nr'] && isset( $idx['nr'][ $r['nr'] ] ) ) {
		return array( $idx['nr'][ $r['nr'] ], __( 'Mitglieds-Nr.', 'vereinsplugin' ) );
	}
	$name_key = vp_mimport_name_key( $r['name'] );
	if ( $r['email'] && isset( $idx['email'][ $r['email'] ] ) ) {
		$uid  = $idx['email'][ $r['email'] ];
		$u    = $idx['users'][ $uid ];
		$same = in_array( $name_key, array( vp_mimport_name_key( $u->display_name ), vp_mimport_name_key( $u->first_name . ' ' . $u->last_name ) ), true );
		// Teilen sich mehrere Personen der Datei eine Adresse, nur bei passendem Namen verbinden.
		if ( $same || ( $email_count[ $r['email'] ] ?? 0 ) < 2 ) {
			return array( $uid, $same ? __( 'E-Mail + Name', 'vereinsplugin' ) : __( 'E-Mail (Name weicht ab)', 'vereinsplugin' ) );
		}
	}
	if ( $name_key && isset( $idx['name'][ $name_key ] ) && count( array_unique( $idx['name'][ $name_key ] ) ) === 1 ) {
		return array( $idx['name'][ $name_key ][0], __( 'Name', 'vereinsplugin' ) );
	}
	return array( 0, '' );
}

/* -------------------------------------------------------------------------
 * Ausführen
 * ---------------------------------------------------------------------- */

function vp_mimport_run( array $rows, array $aktionen, array $zuordnung, array $opt ) {
	global $wpdb;
	$res = array(
		'neu' => array(), 'verbunden' => 0, 'ehemalig' => 0, 'uebersprungen' => 0,
		'mandate' => 0, 'kreise' => 0, 'eingeladen' => 0, 'hinweise' => array(),
	);

	// Neue Kreise einmalig anlegen und Merkmal → Kreis-ID auflösen.
	$kreis_id = array();
	$kt       = $wpdb->prefix . 'pp_gremien';
	foreach ( $zuordnung as $merkmal => $z ) {
		if ( 0 === strpos( $z, 'kreis:' ) ) {
			$kreis_id[ $merkmal ] = (int) substr( $z, 6 );
		} elseif ( 'kreis_neu' === $z && function_exists( 'pp_get_gremien' ) ) {
			$wpdb->insert( $kt, array( 'typ' => 'kreis', 'name' => $merkmal, 'erstellt_von' => get_current_user_id() ) );
			if ( $wpdb->insert_id ) {
				$kreis_id[ $merkmal ] = (int) $wpdb->insert_id;
				$res['hinweise'][]    = sprintf( __( 'Kreis „%s“ angelegt.', 'vereinsplugin' ), $merkmal );
			}
		}
	}

	$verbunden = array(); // user_id => Zeile, damit kein Konto doppelt belegt wird.
	$fehler    = function ( $r, $text ) use ( &$res ) {
		$res['hinweise'][] = sprintf( 'Zeile %d (%s): %s', $r['zeile'], $r['name'], $text );
	};

	foreach ( $rows as $i => $r ) {
		$aktion = $aktionen[ $i ] ?? 'skip';
		$st     = vp_mimport_status( $r, $zuordnung );

		if ( 'skip' === $aktion || '' === $r['name'] ) {
			$res['uebersprungen']++;
			continue;
		}

		$ist_neu = false;
		if ( 'new' === $aktion ) {
			if ( $st['ausgetreten'] && 'skip' === $opt['ehemalige'] ) {
				$res['uebersprungen']++;
				continue;
			}
			$uid = vp_mimport_create_user( $r, $st['ausgetreten'] );
			if ( is_wp_error( $uid ) ) {
				$fehler( $r, $uid->get_error_message() );
				$res['uebersprungen']++;
				continue;
			}
			if ( '' === get_userdata( $uid )->user_email && ! $st['ausgetreten'] ) {
				$fehler( $r, $r['email']
					? __( 'E-Mail-Adresse ist schon vergeben – Konto ohne E-Mail angelegt (kein Login möglich).', 'vereinsplugin' )
					: __( 'Keine E-Mail-Adresse – Konto ohne Login angelegt.', 'vereinsplugin' ) );
			}
			$ist_neu = true;
		} else {
			$uid = (int) $aktion;
			if ( ! $uid || ! get_userdata( $uid ) ) {
				$fehler( $r, __( 'Das gewählte Konto gibt es nicht.', 'vereinsplugin' ) );
				$res['uebersprungen']++;
				continue;
			}
			if ( isset( $verbunden[ $uid ] ) ) {
				$fehler( $r, sprintf( __( 'Das Konto ist schon mit Zeile %d verbunden – übersprungen.', 'vereinsplugin' ), $verbunden[ $uid ] ) );
				$res['uebersprungen']++;
				continue;
			}
			vp_mimport_adjust_role( $uid, $st['ausgetreten'], $opt );
			$res['verbunden']++;
		}
		$verbunden[ $uid ] = $r['zeile'];

		vp_mimport_write_data( $uid, $r, $st, $ist_neu || 'overwrite' === $opt['modus'], $opt );
		if ( $st['ausgetreten'] ) {
			$res['ehemalig']++;
		}

		// SEPA-Mandat nur für aktive Mitglieder mit gültiger IBAN.
		if ( $r['iban'] && function_exists( 'vp_iban_valid' ) && ! vp_iban_valid( $r['iban'] ) ) {
			$fehler( $r, sprintf( __( 'IBAN %s ist ungültig – kein Mandat angelegt.', 'vereinsplugin' ), $r['iban'] ) );
		} elseif ( $opt['mandate'] && $r['iban'] && ! $st['ausgetreten'] && function_exists( 'vp_sepa_mandat_save' ) ) {
			$m = vp_sepa_mandat_fuer_user( $uid );
			if ( $m && $m->iban !== $r['iban'] ) {
				$fehler( $r, __( 'Es gibt schon ein aktives Mandat mit anderer IBAN – nicht geändert.', 'vereinsplugin' ) );
			} elseif ( ! $m ) {
				$mid = vp_sepa_mandat_save( array(
					'user_id'            => $uid,
					'kontoinhaber'       => $r['inhaber'],
					'email'              => $r['inhaber_email'] ?: $r['email'],
					'iban'               => $r['iban'],
					'bic'                => vp_bic_valid( $r['bic'] ) ? $r['bic'] : '',
					'unterschrift_datum' => $r['mandatsdatum'] ?: $r['eintritt'],
					'mandatsref'         => $r['mandatsref'],
					'notiz'              => sprintf( __( 'Übernommen aus der alten Vereinsverwaltung (Mitglieds-Nr. %s).', 'vereinsplugin' ), $r['nr'] ?: '–' ),
				) );
				if ( is_wp_error( $mid ) ) {
					$fehler( $r, $mid->get_error_message() );
				} else {
					$res['mandate']++;
				}
			}
		}

		// Kreise (nur solange die Person Mitglied ist).
		if ( ! $st['ausgetreten'] ) {
			$km = $wpdb->prefix . 'pp_kreis_mitglieder';
			foreach ( $st['kreise'] as $merkmal ) {
				$gid = $kreis_id[ $merkmal ] ?? 0;
				if ( ! $gid || $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `$km` WHERE gremium_id = %d AND user_id = %d AND ausgetreten_am IS NULL", $gid, $uid ) ) ) {
					continue;
				}
				$wpdb->insert( $km, array( 'gremium_id' => $gid, 'user_id' => $uid, 'beigetreten_am' => current_time( 'Y-m-d' ) ) );
				$res['kreise']++;
			}
		}

		if ( $ist_neu ) {
			$u = get_userdata( $uid );
			$res['neu'][] = array( 'id' => $uid, 'name' => $u->display_name, 'login' => $u->user_login, 'email' => $u->user_email, 'ehemalig' => $st['ausgetreten'] );
			if ( ! $st['ausgetreten'] ) {
				/** Siehe membership-application.php. */
				do_action( 'vp_member_created', $uid );
				if ( $opt['einladen'] && $u->user_email && vp_mimport_invite( $u ) ) {
					$res['eingeladen']++;
				}
			}
		}
	}
	return $res;
}

function vp_mimport_create_user( array $r, $ehemalig ) {
	$base = sanitize_user( $r['username'] ?: remove_accents( vp_strtolower( $r['vorname'] . '.' . $r['nachname'] ) ), true );
	$base = trim( preg_replace( '/[^a-z0-9._-]+/', '.', strtolower( $base ) ), '.' ) ?: 'mitglied';
	$login = $base;
	$n     = 1;
	while ( username_exists( $login ) ) {
		$login = $base . ++$n;
	}
	$email = ( $r['email'] && is_email( $r['email'] ) && ! email_exists( $r['email'] ) ) ? $r['email'] : '';

	return wp_insert_user( array(
		'user_login'   => $login,
		'user_pass'    => wp_generate_password( 20 ),
		'user_email'   => $email,
		'first_name'   => $r['vorname'],
		'last_name'    => $r['nachname'],
		'display_name' => $r['name'],
		'role'         => $ehemalig ? VP_EHEMALIG_ROLE : VP_MEMBER_ROLE,
	) );
}

/**
 * Rolle eines verbundenen Kontos anpassen. Vorstand/Admin-Konten bleiben
 * immer unangetastet.
 */
function vp_mimport_adjust_role( $uid, $ausgetreten, array $opt ) {
	$u     = new WP_User( $uid );
	$roles = (array) $u->roles;
	$weich = array( 'subscriber', VP_MEMBER_ROLE, 'pp_mitglied', 'vp_antrag_offen', VP_EHEMALIG_ROLE );
	if ( array_diff( $roles, $weich ) ) {
		return;
	}
	if ( $ausgetreten ) {
		if ( $opt['rolle_ehemalig'] && array( VP_EHEMALIG_ROLE ) !== array_values( $roles ) ) {
			$u->set_role( VP_EHEMALIG_ROLE );
		}
	} elseif ( ! in_array( VP_MEMBER_ROLE, $roles, true ) ) {
		$u->set_role( VP_MEMBER_ROLE );
	}
}

function vp_mimport_write_data( $uid, array $r, array $st, $overwrite, array $opt ) {
	$u   = get_userdata( $uid );
	$set = function ( $k, $v ) use ( $uid, $overwrite ) {
		if ( '' === $v || null === $v ) {
			return;
		}
		if ( $overwrite || '' === (string) get_user_meta( $uid, $k, true ) ) {
			update_user_meta( $uid, $k, $v );
		}
	};

	$core = array( 'ID' => $uid );
	foreach ( array( 'first_name' => $r['vorname'], 'last_name' => $r['nachname'] ) as $k => $v ) {
		if ( $v && ( $overwrite || '' === (string) $u->$k ) ) {
			$core[ $k ] = $v;
		}
	}
	if ( $r['name'] && ( $overwrite || '' === $u->display_name || $u->display_name === $u->user_login ) ) {
		$core['display_name'] = $r['name'];
	}
	if ( count( $core ) > 1 ) {
		wp_update_user( $core );
	}

	$set( 'vp_mitglieds_nr', $r['nr'] );
	$set( 'vp_geburtsdatum', $r['geburtsdatum'] );
	$set( 'vp_strasse', $r['strasse'] );
	$set( 'vp_plz', $r['plz'] );
	$set( 'vp_ort', $r['ort'] );
	$set( 'vp_land', $r['land'] );
	$set( 'vp_telefon', $r['telefon'] );
	$set( 'vp_mitglied_seit', $r['eintritt'] );
	$set( 'vp_ausgetreten_am', $r['austritt'] );
	$set( 'vp_mitgliedsart', $st['art'] );
	$set( 'vp_gruppen', implode( ', ', $r['merkmale'] ) );
	if ( '' !== $r['beitrag'] ) {
		$set( 'vp_beitrag', $r['beitrag'] );
		$set( 'vp_beitrag_intervall', $opt['intervall'] );
	}
	if ( $r['iban'] ) {
		$set( 'vp_sepa_iban', $r['iban'] );
		$set( 'vp_sepa_kontoinhaber', $r['inhaber'] );
		$set( 'vp_mandatsref', $r['mandatsref'] );
	}

	// Notiz wird ergänzt, nie ersetzt.
	$notiz = (string) get_user_meta( $uid, 'vp_notiz', true );
	$neu   = array();
	if ( $r['notiz'] ) {
		$neu[] = $r['notiz'];
	}
	if ( $st['pruefen'] ) {
		$neu[] = __( 'Import: In der alten Vereinsverwaltung zur Prüfung markiert.', 'vereinsplugin' );
	}
	foreach ( $neu as $zeile ) {
		if ( false === strpos( $notiz, $zeile ) ) {
			$notiz = trim( $notiz . "\n" . $zeile );
		}
	}
	update_user_meta( $uid, 'vp_notiz', $notiz );
}

function vp_mimport_invite( WP_User $u ) {
	$key = get_password_reset_key( $u );
	if ( is_wp_error( $key ) ) {
		return false;
	}
	$link = network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $u->user_login ), 'login' );
	$body = sprintf(
		/* translators: 1: name, 2: site, 3: username, 4: link */
		__( "Hallo %1\$s,\n\nwir sind mit unserer Mitgliederverwaltung umgezogen. Du hast jetzt einen Zugang zum Mitgliederbereich von %2\$s.\n\nBenutzername: %3\$s\nPasswort festlegen: %4\$s\n\nDer Link ist einige Tage gültig. Danach kannst du auf der Login-Seite jederzeit „Passwort vergessen“ wählen.\n\nHerzliche Grüße", 'vereinsplugin' ),
		$u->first_name ?: $u->display_name,
		get_bloginfo( 'name' ),
		$u->user_login,
		$link
	);
	return wp_mail( $u->user_email, sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'Dein Zugang zum Mitgliederbereich', 'vereinsplugin' ) ), $body );
}

/* -------------------------------------------------------------------------
 * Admin-Seite
 * ---------------------------------------------------------------------- */

function vp_mitglieder_import_page() {
	if ( ! current_user_can( 'vp_manage_members' ) && ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Keine Berechtigung.', 'vereinsplugin' ) );
	}

	echo '<div class="wrap vp-mimport"><h1>' . esc_html__( 'Mitglieder importieren', 'vereinsplugin' ) . '</h1>';
	vp_mimport_styles();

	// Schritt 1 → 2: Datei einlesen.
	if ( isset( $_POST['vp_mimport_upload'] ) && check_admin_referer( 'vp_mimport_upload' ) ) {
		$file = $_FILES['import_file'] ?? null;
		$ext  = $file ? strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) ) : '';
		if ( ! $file || empty( $file['tmp_name'] ) || ! in_array( $ext, array( 'csv', 'txt', 'xml' ), true ) ) {
			vp_mimport_notice( __( 'Bitte eine .csv- oder .xml-Datei auswählen.', 'vereinsplugin' ), 'error' );
		} else {
			$raw = vp_mimport_read_file( $file['tmp_name'], $ext );
			if ( is_wp_error( $raw ) ) {
				vp_mimport_notice( $raw->get_error_message(), 'error' );
			} else {
				$rows = array();
				foreach ( $raw as $i => $row ) {
					$rows[] = vp_mimport_normalize( $row, $i + 2 );
				}
				$rows = array_values( array_filter( $rows, function ( $r ) { return '' !== $r['name'] || '' !== $r['email']; } ) );
				if ( ! $rows ) {
					vp_mimport_notice( __( 'In der Datei wurden keine Mitglieder gefunden. Gibt es eine Spalte „Name“ oder „Vorname“/„Nachname“?', 'vereinsplugin' ), 'error' );
				} else {
					set_transient( vp_mimport_transient(), $rows, HOUR_IN_SECONDS );
				}
			}
		}
	}

	// Schritt 3: ausführen.
	if ( isset( $_POST['vp_mimport_run'] ) && check_admin_referer( 'vp_mimport_run' ) ) {
		$rows = get_transient( vp_mimport_transient() );
		if ( ! is_array( $rows ) ) {
			vp_mimport_notice( __( 'Die hochgeladene Datei ist nicht mehr vorhanden (älter als eine Stunde). Bitte erneut hochladen.', 'vereinsplugin' ), 'error' );
		} else {
			$post     = wp_unslash( $_POST );
			$aktionen = array();
			foreach ( $rows as $i => $r ) {
				$a    = sanitize_text_field( $post['aktion'][ $i ] ?? 'skip' );
				$frei = sanitize_text_field( $post['konto'][ $i ] ?? '' );
				if ( '' !== $frei && preg_match( '/#(\d+)\s*$/', $frei, $m ) ) {
					$a = $m[1];
				}
				$aktionen[ $i ] = in_array( $a, array( 'new', 'skip' ), true ) ? $a : (string) absint( $a );
			}
			$erlaubt   = vp_mimport_merkmal_optionen();
			$zuordnung = array();
			foreach ( $rows as $r ) {
				foreach ( $r['merkmale'] as $m ) {
					$v               = (string) ( $post['merkmal'][ base64_encode( $m ) ] ?? '' );
					$zuordnung[ $m ] = isset( $erlaubt[ $v ] ) ? $v : '';
				}
			}
			$intervalle = vp_beitrag_intervalle();
			$opt = array(
				'ehemalige'      => 'skip' === ( $post['opt_ehemalige'] ?? '' ) ? 'skip' : 'anlegen',
				'modus'          => 'overwrite' === ( $post['opt_modus'] ?? '' ) ? 'overwrite' : 'fill',
				'rolle_ehemalig' => ! empty( $post['opt_rolle_ehemalig'] ),
				'mandate'        => ! empty( $post['opt_mandate'] ),
				'einladen'       => ! empty( $post['opt_einladen'] ),
				'intervall'      => isset( $intervalle[ $post['opt_intervall'] ?? '' ] ) ? $post['opt_intervall'] : 'jaehrlich',
			);
			$res = vp_mimport_run( $rows, $aktionen, $zuordnung, $opt );
			delete_transient( vp_mimport_transient() );
			vp_mimport_render_result( $res );
			echo '</div>';
			return;
		}
	}

	if ( isset( $_GET['vp_mimport_reset'] ) ) {
		delete_transient( vp_mimport_transient() );
	}

	$rows = get_transient( vp_mimport_transient() );
	if ( is_array( $rows ) && ! isset( $_GET['vp_mimport_reset'] ) ) {
		vp_mimport_render_preview( $rows );
	} else {
		vp_mimport_render_upload();
	}
	echo '</div>';
}

function vp_mimport_notice( $text, $type = 'success' ) {
	printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
}

function vp_mimport_render_upload() {
	?>
	<div class="vp-mi-card">
		<h2><?php esc_html_e( '1. Datei hochladen', 'vereinsplugin' ); ?></h2>
		<p><?php esc_html_e( 'CSV (Komma, Semikolon oder Tab) oder XML. Der Export „Alle Mitglieder“ aus der alten Vereinsverwaltung wird direkt erkannt – inklusive ausgetretener Mitglieder, Bankverbindung, Status und Gruppen.', 'vereinsplugin' ); ?></p>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'vp_mimport_upload' ); ?>
			<p><input type="file" name="import_file" accept=".csv,.txt,.xml" required></p>
			<p><button class="button button-primary" name="vp_mimport_upload" value="1"><?php esc_html_e( 'Weiter zur Vorschau', 'vereinsplugin' ); ?></button></p>
		</form>
		<p class="description"><?php esc_html_e( 'Im nächsten Schritt siehst du jede Zeile und entscheidest, ob sie mit einem bestehenden WordPress-Konto verbunden, neu angelegt oder übersprungen wird. Es wird noch nichts gespeichert.', 'vereinsplugin' ); ?></p>
	</div>
	<div class="vp-mi-card">
		<h2><?php esc_html_e( 'Erkannte Spalten', 'vereinsplugin' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Pflicht ist nur ein Name (oder Vorname + Nachname). Alles andere ist optional, unbekannte Spalten werden ignoriert:', 'vereinsplugin' ); ?></p>
		<p><code>Mitglieder ID</code> <code>Mitglied / Name</code> <code>Vorname</code> <code>Nachname</code> <code>E-Mail</code> <code>Benutzername</code> <code>Geburtstag</code> <code>Strasse</code> <code>Adresszusatz</code> <code>PLZ</code> <code>Ort</code> <code>Land</code> <code>Telefon</code> <code>Mobile</code> <code>Eintrittsdatum</code> <code>Austrittsdatum</code> <code>Bemerkungen</code> <code>Mitgliedsbeitrag</code> <code>Kontoinhaber (Name)</code> <code>IBAN</code> <code>BIC</code> <code>Mandatdatum</code> <code>Mandatsreferenz</code> <code>Status</code> <code>Gruppen</code></p>
	</div>
	<?php
}

function vp_mimport_render_preview( array $rows ) {
	$idx         = vp_mimport_user_index();
	$email_count = array_count_values( array_filter( wp_list_pluck( $rows, 'email' ) ) );

	// Alle Status-/Gruppenwerte mit Häufigkeit.
	$merkmale = array();
	foreach ( $rows as $r ) {
		foreach ( $r['merkmale'] as $m ) {
			$merkmale[ $m ] = ( $merkmale[ $m ] ?? 0 ) + 1;
		}
	}
	$zuordnung = array();
	foreach ( array_keys( $merkmale ) as $m ) {
		$zuordnung[ $m ] = vp_mimport_merkmal_vorschlag( $m );
	}
	$optionen = vp_mimport_merkmal_optionen();

	$zaehler = array( 'match' => 0, 'ehemalig' => 0 );
	$prep    = array();
	foreach ( $rows as $i => $r ) {
		list( $uid, $grund ) = vp_mimport_match( $r, $idx, $email_count );
		$st = vp_mimport_status( $r, $zuordnung );
		$zaehler['match']    += $uid ? 1 : 0;
		$zaehler['ehemalig'] += $st['ausgetreten'] ? 1 : 0;
		$prep[ $i ] = array( $uid, $grund, $st );
	}
	$hat_iban = (bool) array_filter( wp_list_pluck( $rows, 'iban' ) );
	?>
	<form method="post">
		<?php wp_nonce_field( 'vp_mimport_run' ); ?>
		<div class="vp-mi-card">
			<h2><?php esc_html_e( '2. Vorschau und Zuordnung', 'vereinsplugin' ); ?></h2>
			<p><?php echo esc_html( sprintf(
				/* translators: 1: rows, 2: matches, 3: former members */
				__( '%1$d Datensätze gelesen · %2$d passen zu einem bestehenden Konto · %3$d davon sind ausgetreten/ehemalig.', 'vereinsplugin' ),
				count( $rows ), $zaehler['match'], $zaehler['ehemalig']
			) ); ?>
			<a href="<?php echo esc_url( vp_mimport_url( array( 'vp_mimport_reset' => 1 ) ) ); ?>"><?php esc_html_e( 'Andere Datei hochladen', 'vereinsplugin' ); ?></a></p>

			<?php if ( $merkmale ) : ?>
				<h3><?php esc_html_e( 'Status und Gruppen zuordnen', 'vereinsplugin' ); ?></h3>
				<table class="widefat striped vp-mi-merkmale">
					<thead><tr><th><?php esc_html_e( 'Wert in der Datei', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Anzahl', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Bedeutung hier', 'vereinsplugin' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $merkmale as $m => $anzahl ) : ?>
						<tr><td><?php echo esc_html( $m ); ?></td><td><?php echo (int) $anzahl; ?></td><td>
							<select name="merkmal[<?php echo esc_attr( base64_encode( $m ) ); ?>]">
								<?php foreach ( $optionen as $k => $label ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $zuordnung[ $m ], $k ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Als ausgetreten gilt außerdem jede Person mit einem Austrittsdatum, das heute oder früher liegt. Ein Austritt in der Zukunft wird nur vermerkt. Der Originalwert wird immer am Konto gespeichert.', 'vereinsplugin' ); ?></p>
			<?php endif; ?>

			<h3><?php esc_html_e( 'Optionen', 'vereinsplugin' ); ?></h3>
			<table class="form-table vp-mi-opts"><tbody>
				<tr><th><?php esc_html_e( 'Ausgetretene ohne Konto', 'vereinsplugin' ); ?></th><td>
					<label><input type="radio" name="opt_ehemalige" value="anlegen" checked> <?php esc_html_e( 'als „Ehemaliges Mitglied“ ohne Zugang übernehmen (Daten bleiben für Nachweise erhalten)', 'vereinsplugin' ); ?></label><br>
					<label><input type="radio" name="opt_ehemalige" value="skip"> <?php esc_html_e( 'nicht übernehmen', 'vereinsplugin' ); ?></label></td></tr>
				<tr><th><?php esc_html_e( 'Verbundene Konten', 'vereinsplugin' ); ?></th><td>
					<label><input type="radio" name="opt_modus" value="fill" checked> <?php esc_html_e( 'nur leere Felder aus der Datei füllen', 'vereinsplugin' ); ?></label><br>
					<label><input type="radio" name="opt_modus" value="overwrite"> <?php esc_html_e( 'Werte aus der Datei übernehmen (überschreibt Name, Anschrift, Beitrag …)', 'vereinsplugin' ); ?></label><br>
					<label><input type="checkbox" name="opt_rolle_ehemalig" value="1" checked> <?php esc_html_e( 'Ausgetretene auf „Ehemaliges Mitglied“ setzen (Zugang zum Mitgliederbereich endet; Vorstand/Admin bleiben unverändert)', 'vereinsplugin' ); ?></label>
					<p class="description"><?php esc_html_e( 'E-Mail-Adresse und Benutzername eines bestehenden Kontos werden nie geändert.', 'vereinsplugin' ); ?></p></td></tr>
				<tr><th><?php esc_html_e( 'Beitragsintervall', 'vereinsplugin' ); ?></th><td>
					<select name="opt_intervall">
						<?php foreach ( vp_beitrag_intervalle() as $k => $label ) : if ( '' === $k ) { continue; } ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $k, 'jaehrlich' ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="description"><?php esc_html_e( 'gilt für die Beträge aus der Spalte „Mitgliedsbeitrag“', 'vereinsplugin' ); ?></span></td></tr>
				<?php if ( $hat_iban && function_exists( 'vp_sepa_mandat_save' ) ) : ?>
				<tr><th><?php esc_html_e( 'SEPA', 'vereinsplugin' ); ?></th><td>
					<label><input type="checkbox" name="opt_mandate" value="1" checked> <?php esc_html_e( 'Für aktive Mitglieder mit gültiger IBAN ein SEPA-Mandat anlegen (Datum = Mandatdatum, sonst Eintritt; ohne Referenz in der Datei wird eine neue vergeben, erster Einzug dann als FRST)', 'vereinsplugin' ); ?></label></td></tr>
				<?php endif; ?>
				<tr><th><?php esc_html_e( 'Einladung', 'vereinsplugin' ); ?></th><td>
					<label><input type="checkbox" name="opt_einladen" value="1"> <?php esc_html_e( 'Neu angelegte aktive Mitglieder per E-Mail einladen (Link zum Passwort-Setzen)', 'vereinsplugin' ); ?></label>
					<p class="description"><?php esc_html_e( 'Ohne Häkchen wird niemand angeschrieben – einzeln geht es später über „Passwort-Link per E-Mail senden“ im Mitgliederbereich.', 'vereinsplugin' ); ?></p></td></tr>
			</tbody></table>
		</div>

		<datalist id="vp-mi-konten">
			<?php foreach ( $idx['users'] as $u ) : ?>
				<option value="<?php echo esc_attr( $u->display_name . ' <' . $u->user_email . '> #' . $u->ID ); ?>"></option>
			<?php endforeach; ?>
		</datalist>

		<div class="vp-mi-card">
			<h3><?php esc_html_e( 'Datensätze', 'vereinsplugin' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Vorschläge für bestehende Konten kommen aus Mitglieds-Nr. (bei erneutem Import), E-Mail-Adresse oder eindeutigem Namen. Im Suchfeld kannst du jedes andere Konto wählen – das hat Vorrang vor der Auswahl links.', 'vereinsplugin' ); ?></p>
			<div class="vp-mi-scroll">
			<table class="widefat striped vp-mi-rows">
				<thead><tr>
					<th>#</th><th><?php esc_html_e( 'Name', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'E-Mail', 'vereinsplugin' ); ?></th>
					<th><?php esc_html_e( 'Status', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Gruppen', 'vereinsplugin' ); ?></th>
					<th><?php esc_html_e( 'Bank', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'WordPress-Konto', 'vereinsplugin' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $i => $r ) :
					list( $uid, $grund, $st ) = $prep[ $i ];
					$status = $st['ausgetreten']
						? '<span class="vp-mi-tag vp-mi-ex">' . esc_html__( 'ehemalig', 'vereinsplugin' ) . ( $r['austritt'] ? ' ' . esc_html( date_i18n( 'd.m.Y', strtotime( $r['austritt'] ) ) ) : '' ) . '</span>'
						: '<span class="vp-mi-tag">' . esc_html( $st['art'] ? $optionen[ $st['art'] ] : __( 'Mitglied', 'vereinsplugin' ) ) . '</span>'
							. ( $r['austritt'] ? '<br><small>' . esc_html( sprintf( __( 'Austritt zum %s', 'vereinsplugin' ), date_i18n( 'd.m.Y', strtotime( $r['austritt'] ) ) ) ) . '</small>' : '' );
					$bank = '';
					if ( $r['iban'] ) {
						$ok   = ! function_exists( 'vp_iban_valid' ) || vp_iban_valid( $r['iban'] );
						$bank = esc_html( vp_iban_mask( $r['iban'] ) ) . ( $ok ? '' : ' <span class="vp-mi-warn">' . esc_html__( 'ungültig', 'vereinsplugin' ) . '</span>' );
					}
					$warn = ( $r['email'] && ( $email_count[ $r['email'] ] ?? 0 ) > 1 ) ? __( 'E-Mail kommt mehrfach vor', 'vereinsplugin' ) : '';
					?>
					<tr class="<?php echo $st['ausgetreten'] ? 'vp-mi-row-ex' : ''; ?>">
						<td><?php echo esc_html( $r['nr'] ?: $r['zeile'] ); ?></td>
						<td><strong><?php echo esc_html( $r['name'] ); ?></strong><?php echo $r['ort'] ? '<br><small>' . esc_html( trim( $r['plz'] . ' ' . $r['ort'] ) ) . '</small>' : ''; ?></td>
						<td><?php echo esc_html( $r['email'] ?: '–' ); ?><?php echo $warn ? '<br><span class="vp-mi-warn">' . esc_html( $warn ) . '</span>' : ''; ?></td>
						<td><?php echo $status; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><small><?php echo esc_html( implode( ', ', $r['merkmale'] ) ); ?></small></td>
						<td><small><?php echo $bank; // phpcs:ignore WordPress.Security.EscapeOutput ?></small></td>
						<td class="vp-mi-konto">
							<select name="aktion[<?php echo (int) $i; ?>]">
								<?php if ( $uid ) : $u = $idx['users'][ $uid ]; ?>
									<option value="<?php echo (int) $uid; ?>" selected><?php echo esc_html( sprintf( __( 'Verbinden: %1$s (%2$s)', 'vereinsplugin' ), $u->display_name, $grund ) ); ?></option>
								<?php endif; ?>
								<option value="new" <?php selected( ! $uid ); ?>><?php echo esc_html( $st['ausgetreten'] ? __( 'Als Ehemalige:n anlegen', 'vereinsplugin' ) : __( 'Neues Konto anlegen', 'vereinsplugin' ) ); ?></option>
								<option value="skip"><?php esc_html_e( 'Überspringen', 'vereinsplugin' ); ?></option>
							</select>
							<input type="text" name="konto[<?php echo (int) $i; ?>]" list="vp-mi-konten" placeholder="<?php esc_attr_e( 'oder anderes Konto suchen …', 'vereinsplugin' ); ?>">
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<p><button class="button button-primary button-hero" name="vp_mimport_run" value="1"><?php esc_html_e( 'Import ausführen', 'vereinsplugin' ); ?></button></p>
		</div>
	</form>
	<?php
}

function vp_mimport_render_result( array $res ) {
	$aktiv_neu = array_filter( $res['neu'], function ( $n ) { return ! $n['ehemalig']; } );
	vp_mimport_notice( sprintf(
		/* translators: counts */
		__( 'Fertig: %1$d neu angelegt, %2$d mit bestehenden Konten verbunden, %3$d als ehemalig geführt, %4$d übersprungen. %5$d SEPA-Mandate, %6$d Kreis-Zuordnungen, %7$d Einladungen verschickt.', 'vereinsplugin' ),
		count( $res['neu'] ), $res['verbunden'], $res['ehemalig'], $res['uebersprungen'], $res['mandate'], $res['kreise'], $res['eingeladen']
	) );
	?>
	<div class="vp-mi-card">
		<?php if ( $res['hinweise'] ) : ?>
			<h2><?php esc_html_e( 'Hinweise', 'vereinsplugin' ); ?></h2>
			<ul class="vp-mi-hinweise">
				<?php foreach ( $res['hinweise'] as $h ) : ?><li><?php echo esc_html( $h ); ?></li><?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( $aktiv_neu ) : ?>
			<h2><?php esc_html_e( 'Neue Konten', 'vereinsplugin' ); ?></h2>
			<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Name', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'Benutzername', 'vereinsplugin' ); ?></th><th><?php esc_html_e( 'E-Mail', 'vereinsplugin' ); ?></th></tr></thead><tbody>
				<?php foreach ( $aktiv_neu as $n ) : ?>
					<tr><td><?php echo esc_html( $n['name'] ); ?></td><td><code><?php echo esc_html( $n['login'] ); ?></code></td><td><?php echo esc_html( $n['email'] ?: '–' ); ?></td></tr>
				<?php endforeach; ?>
			</tbody></table>
			<p class="description"><?php esc_html_e( 'Passwörter werden nicht angezeigt. Wer noch keine Einladung bekommen hat, kann sich über „Passwort vergessen“ oder den Passwort-Link im Mitgliederbereich einen Zugang setzen.', 'vereinsplugin' ); ?></p>
		<?php endif; ?>
		<p><a class="button" href="<?php echo esc_url( vp_mimport_url() ); ?>"><?php esc_html_e( 'Weitere Datei importieren', 'vereinsplugin' ); ?></a></p>
	</div>
	<?php
}

function vp_mimport_styles() {
	?>
	<style>
		.vp-mimport .vp-mi-card{background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px 24px;margin:16px 0;max-width:1400px}
		.vp-mimport .vp-mi-card h2{margin-top:0}
		.vp-mimport .vp-mi-merkmale{max-width:760px}
		.vp-mimport .vp-mi-scroll{overflow-x:auto}
		.vp-mimport .vp-mi-rows td{vertical-align:top}
		.vp-mimport .vp-mi-konto select,.vp-mimport .vp-mi-konto input{display:block;width:100%;min-width:240px;margin-bottom:4px}
		.vp-mimport .vp-mi-tag{display:inline-block;padding:1px 8px;border-radius:10px;background:#e7f5ec;color:#14532d;font-size:12px;white-space:nowrap}
		.vp-mimport .vp-mi-tag.vp-mi-ex{background:#f1f1f1;color:#555}
		.vp-mimport .vp-mi-row-ex td{color:#666}
		.vp-mimport .vp-mi-warn{color:#b45309;font-size:12px}
		.vp-mimport .vp-mi-hinweise{list-style:disc;margin-left:20px}
	</style>
	<?php
}
