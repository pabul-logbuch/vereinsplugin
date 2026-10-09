<?php
/**
 * Schichtplan: Vorlagen, Veranstaltung kopieren, Stationen und Schichten
 * kopieren & einfügen.
 *
 * - Eine Veranstaltung lässt sich als Vorlage speichern (Option
 *   wl_shift_vorlagen). Gespeichert werden Stationen und Schichten mit
 *   Uhrzeiten RELATIV zum Veranstaltungstag (Minuten ab 0:00 Uhr) – beim
 *   Anlegen aus der Vorlage landen die Schichten am neuen Datum.
 * - „Kopieren“ einer Veranstaltung legt eine Kopie mit neuem Titel/Datum an;
 *   Schichtzeiten werden um den Datumsabstand verschoben.
 * - Stationen und Schichten kommen per „Kopieren“ in eine persönliche
 *   Zwischenablage (User-Meta wls_zwischenablage) und lassen sich in jeder
 *   Veranstaltung bzw. Station einfügen.
 * Eintragungen von Helfer:innen werden nie mitkopiert.
 */

defined( 'ABSPATH' ) || exit;

function wls_t( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'wl_shift_' . $name;
}

/** Bezugstag einer Veranstaltung (0:00 Uhr): Datum der Veranstaltung, sonst Tag der ersten Schicht. */
function wls_bezugstag( $event_id ) {
	global $wpdb;
	$e = wl_get_event( (int) $event_id );
	if ( $e && ! empty( $e->veranstaltungsdatum ) ) {
		return substr( (string) $e->veranstaltungsdatum, 0, 10 );
	}
	$erste = $wpdb->get_var( $wpdb->prepare(
		'SELECT MIN(s.start_zeit) FROM ' . wls_t( 'schichten' ) . ' s JOIN ' . wls_t( 'stationen' ) . ' st ON st.id = s.station_id WHERE st.event_id = %d',
		(int) $event_id
	) );
	return $erste ? substr( (string) $erste, 0, 10 ) : '';
}

/** Minuten zwischen Bezugstag 0:00 und einem Zeitpunkt (null = ohne Zeit). */
function wls_rel( $zeit, $tag ) {
	if ( empty( $zeit ) || '' === $tag ) {
		return null;
	}
	$a = new DateTimeImmutable( $tag . ' 00:00:00', new DateTimeZone( 'UTC' ) );
	$b = new DateTimeImmutable( (string) $zeit, new DateTimeZone( 'UTC' ) );
	return (int) round( ( $b->getTimestamp() - $a->getTimestamp() ) / 60 );
}

/** Zeitpunkt aus Bezugstag + Minuten. */
function wls_abs( $rel, $tag ) {
	if ( null === $rel || '' === $tag ) {
		return null;
	}
	$a = new DateTimeImmutable( $tag . ' 00:00:00', new DateTimeZone( 'UTC' ) );
	return $a->modify( ( $rel >= 0 ? '+' : '' ) . (int) $rel . ' minutes' )->format( 'Y-m-d H:i:s' );
}

function wls_station_felder() {
	return array( 'titel', 'beschreibung', 'treffpunkt', 'ansprechperson1', 'ansprechperson1_kontakt', 'ansprechperson2', 'ansprechperson2_kontakt' );
}

/** Eine Schicht relativ zum Bezugstag beschreiben. */
function wls_schicht_struktur( $s, $tag ) {
	return array(
		'titel'       => (string) $s->titel,
		'start'       => wls_rel( $s->start_zeit, $tag ),
		'ende'        => wls_rel( $s->end_zeit, $tag ),
		'min_plaetze' => (int) $s->min_plaetze,
		'max_plaetze' => (int) $s->max_plaetze,
		'sortierung'  => (int) $s->sortierung,
	);
}

/** Eine Station samt Schichten relativ zum Bezugstag beschreiben. */
function wls_station_struktur( $st, $tag ) {
	$out = array( 'schichten' => array(), 'sortierung' => (int) $st->sortierung );
	foreach ( wls_station_felder() as $f ) {
		$out[ $f ] = (string) ( $st->$f ?? '' );
	}
	foreach ( wl_get_schichten( (int) $st->id ) as $s ) {
		$out['schichten'][] = wls_schicht_struktur( $s, $tag );
	}
	return $out;
}

/** Ganze Veranstaltung als Struktur (für Vorlagen und Kopien). */
function wls_event_struktur( $event_id ) {
	$e   = wl_get_event( (int) $event_id );
	$tag = wls_bezugstag( $event_id );
	$out = array(
		'titel'              => (string) $e->titel,
		'beschreibung'       => (string) $e->beschreibung,
		'tagesgrenze_stunde' => (int) $e->tagesgrenze_stunde,
		'stationen'          => array(),
	);
	foreach ( wl_get_stationen( (int) $event_id ) as $st ) {
		$out['stationen'][] = wls_station_struktur( $st, $tag );
	}
	return $out;
}

/** Schicht in eine Station einfügen. */
function wls_schicht_anlegen( $station_id, array $s, $tag, $sort = null ) {
	global $wpdb;
	$wpdb->insert( wls_t( 'schichten' ), array(
		'station_id'  => (int) $station_id,
		'titel'       => $s['titel'],
		'start_zeit'  => wls_abs( $s['start'], $tag ),
		'end_zeit'    => wls_abs( $s['ende'], $tag ),
		'min_plaetze' => (int) $s['min_plaetze'],
		'max_plaetze' => max( 1, (int) $s['max_plaetze'] ),
		'sortierung'  => null === $sort ? (int) $s['sortierung'] : (int) $sort,
	) );
	return (int) $wpdb->insert_id;
}

/** Station samt Schichten in eine Veranstaltung einfügen. */
function wls_station_anlegen( $event_id, array $st, $tag, $sort = null ) {
	global $wpdb;
	$row = array( 'event_id' => (int) $event_id, 'sortierung' => null === $sort ? (int) $st['sortierung'] : (int) $sort );
	foreach ( wls_station_felder() as $f ) {
		$row[ $f ] = (string) ( $st[ $f ] ?? '' );
	}
	$wpdb->insert( wls_t( 'stationen' ), $row );
	$sid = (int) $wpdb->insert_id;
	foreach ( $st['schichten'] as $s ) {
		wls_schicht_anlegen( $sid, $s, $tag );
	}
	return $sid;
}

/** Neue Veranstaltung aus einer Struktur anlegen. */
function wls_event_anlegen( array $struktur, $titel, $datum ) {
	global $wpdb;
	$titel = sanitize_text_field( $titel ) ?: $struktur['titel'];
	$wpdb->insert( wls_t( 'events' ), array(
		'titel'               => $titel,
		'slug'                => wl_generate_event_slug( $titel ),
		'beschreibung'        => $struktur['beschreibung'],
		'veranstaltungsdatum' => $datum ?: null,
		'tagesgrenze_stunde'  => (int) $struktur['tagesgrenze_stunde'],
		'aktiv'               => 1,
		'erstellt_von'        => get_current_user_id(),
	) );
	$eid = (int) $wpdb->insert_id;
	foreach ( $struktur['stationen'] as $st ) {
		wls_station_anlegen( $eid, $st, $datum );
	}
	return $eid;
}

/** Nächste Sortiernummer. */
function wls_naechste_sortierung( $tabelle, $spalte, $id ) {
	global $wpdb;
	return 1 + (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(sortierung) FROM ' . wls_t( $tabelle ) . " WHERE {$spalte} = %d", (int) $id ) );
}

/* =========================================================================
 * Vorlagen (Option wl_shift_vorlagen)
 * ====================================================================== */

function wls_vorlagen() {
	$v = get_option( 'wl_shift_vorlagen', array() );
	return is_array( $v ) ? $v : array();
}

function wls_vorlage_speichern( $name, array $struktur ) {
	$v        = wls_vorlagen();
	$id       = 'v' . substr( md5( uniqid( '', true ) ), 0, 10 );
	$v[ $id ] = array( 'name' => $name, 'erstellt' => current_time( 'mysql' ), 'struktur' => $struktur );
	update_option( 'wl_shift_vorlagen', $v, false );
	return $id;
}

/* =========================================================================
 * Zwischenablage (je Person)
 * ====================================================================== */

function wls_ablage() {
	$a = get_user_meta( get_current_user_id(), 'wls_zwischenablage', true );
	return is_array( $a ) && ! empty( $a['typ'] ) ? $a : null;
}

/** Kurztext zum Inhalt der Zwischenablage. */
function wls_ablage_text( $a ) {
	if ( ! $a ) {
		return '';
	}
	if ( 'station' === $a['typ'] ) {
		$st = wl_get_station( (int) $a['id'] );
		return $st ? sprintf( 'Station „%s“ (%d Schichten)', $st->titel, count( wl_get_schichten( (int) $st->id ) ) ) : '';
	}
	$s = wl_get_schicht( (int) $a['id'] );
	if ( ! $s ) {
		return '';
	}
	$zeit = $s->start_zeit ? date_i18n( 'd.m. H:i', strtotime( $s->start_zeit ) ) . ( $s->end_zeit ? '–' . date_i18n( 'H:i', strtotime( $s->end_zeit ) ) : '' ) : '';
	return trim( sprintf( 'Schicht %s %s', $s->titel ? '„' . $s->titel . '“' : '', $zeit ) );
}

/** Veranstaltung, zu der eine Station/Schicht gehört. */
function wls_event_von( $typ, $id ) {
	if ( 'station' === $typ ) {
		$st = wl_get_station( (int) $id );
		return $st ? (int) $st->event_id : 0;
	}
	$s = wl_get_schicht( (int) $id );
	if ( ! $s ) {
		return 0;
	}
	$st = wl_get_station( (int) $s->station_id );
	return $st ? (int) $st->event_id : 0;
}

/** Tage zwischen zwei Bezugstagen (für das Vorbelegen „verschieben um“). */
function wls_tage_abstand( $von_tag, $nach_tag ) {
	if ( '' === $von_tag || '' === $nach_tag ) {
		return 0;
	}
	$a = new DateTimeImmutable( $von_tag, new DateTimeZone( 'UTC' ) );
	$b = new DateTimeImmutable( $nach_tag, new DateTimeZone( 'UTC' ) );
	return (int) round( ( $b->getTimestamp() - $a->getTimestamp() ) / DAY_IN_SECONDS );
}

/** Tag um n Tage verschieben. */
function wls_tag_plus( $tag, $tage ) {
	if ( '' === $tag ) {
		return '';
	}
	return ( new DateTimeImmutable( $tag, new DateTimeZone( 'UTC' ) ) )->modify( ( $tage >= 0 ? '+' : '' ) . (int) $tage . ' days' )->format( 'Y-m-d' );
}

/* =========================================================================
 * Aktionen (POST wls_aktion, danach zurück zur Seite)
 * ====================================================================== */

add_action( 'init', 'wls_aktion_verarbeiten' );
function wls_aktion_verarbeiten() {
	if ( empty( $_POST['wls_aktion'] ) || ! is_user_logged_in() ) {
		return;
	}
	if ( ! wl_can_manage() || ! check_admin_referer( 'wls_vorlagen', 'wls_nonce' ) ) {
		wp_die( 'Keine Berechtigung.' );
	}
	$aktion  = sanitize_key( wp_unslash( $_POST['wls_aktion'] ) );
	$zurueck = wp_get_referer() ?: home_url( '/' );
	$zurueck = remove_query_arg( array( 'wls_msg', 'wls_err' ), $zurueck );
	$p       = wp_unslash( $_POST );
	$msg     = '';
	$err     = '';
	$datum   = static function ( $k ) use ( $p ) {
		$d = sanitize_text_field( (string) ( $p[ $k ] ?? '' ) );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : '';
	};

	switch ( $aktion ) {
		case 'event_kopieren':
			$eid = (int) ( $p['event_id'] ?? 0 );
			if ( ! wl_get_event( $eid ) ) {
				$err = 'Veranstaltung nicht gefunden.';
				break;
			}
			$struktur = wls_event_struktur( $eid );
			$alt_tag  = wls_bezugstag( $eid );
			$neu_tag  = $datum( 'datum' ) ?: $alt_tag;
			$neu      = wls_event_anlegen( $struktur, (string) ( $p['titel'] ?? '' ) ?: 'Kopie von ' . $struktur['titel'], $neu_tag );
			// Ohne eigenes Datum hatte die alte Veranstaltung nur den Tag der ersten Schicht – dann kein Datum setzen.
			if ( ! wl_get_event( $eid )->veranstaltungsdatum && ! $datum( 'datum' ) ) {
				global $wpdb;
				$wpdb->update( wls_t( 'events' ), array( 'veranstaltungsdatum' => null ), array( 'id' => $neu ) );
			}
			$zurueck = add_query_arg( 'wls_event', $neu, remove_query_arg( 'wls_event', $zurueck ) );
			$msg     = 'Kopie angelegt – Eintragungen wurden nicht übernommen.';
			break;

		case 'vorlage_speichern':
			$eid  = (int) ( $p['event_id'] ?? 0 );
			$name = sanitize_text_field( (string) ( $p['name'] ?? '' ) );
			if ( ! wl_get_event( $eid ) ) {
				$err = 'Veranstaltung nicht gefunden.';
				break;
			}
			$struktur = wls_event_struktur( $eid );
			wls_vorlage_speichern( $name ?: $struktur['titel'], $struktur );
			$msg = sprintf( 'Vorlage „%s“ gespeichert.', $name ?: $struktur['titel'] );
			break;

		case 'vorlage_anlegen':
			$v = wls_vorlagen()[ sanitize_key( (string) ( $p['vorlage'] ?? '' ) ) ] ?? null;
			if ( ! $v ) {
				$err = 'Vorlage nicht gefunden.';
				break;
			}
			$tag = $datum( 'datum' );
			if ( '' === $tag && wls_struktur_hat_zeiten( $v['struktur'] ) ) {
				$err = 'Bitte ein Datum angeben – die Schichten der Vorlage haben Uhrzeiten.';
				break;
			}
			$neu     = wls_event_anlegen( $v['struktur'], (string) ( $p['titel'] ?? '' ) ?: $v['name'], $tag );
			$zurueck = add_query_arg( 'wls_event', $neu, remove_query_arg( 'wls_event', $zurueck ) );
			$msg     = sprintf( 'Veranstaltung aus Vorlage „%s“ angelegt.', $v['name'] );
			break;

		case 'vorlage_einfuegen':
			$eid = (int) ( $p['event_id'] ?? 0 );
			$v   = wls_vorlagen()[ sanitize_key( (string) ( $p['vorlage'] ?? '' ) ) ] ?? null;
			$tag = $datum( 'datum' ) ?: wls_bezugstag( $eid );
			if ( ! $v || ! wl_get_event( $eid ) ) {
				$err = 'Vorlage oder Veranstaltung nicht gefunden.';
				break;
			}
			if ( '' === $tag && wls_struktur_hat_zeiten( $v['struktur'] ) ) {
				$err = 'Bitte einen Tag angeben – die Schichten der Vorlage haben Uhrzeiten.';
				break;
			}
			$sort = wls_naechste_sortierung( 'stationen', 'event_id', $eid );
			foreach ( $v['struktur']['stationen'] as $i => $st ) {
				wls_station_anlegen( $eid, $st, $tag, $sort + $i );
			}
			$msg = sprintf( '%d Stationen aus „%s“ eingefügt.', count( $v['struktur']['stationen'] ), $v['name'] );
			break;

		case 'vorlage_loeschen':
			$v = wls_vorlagen();
			unset( $v[ sanitize_key( (string) ( $p['vorlage'] ?? '' ) ) ] );
			update_option( 'wl_shift_vorlagen', $v, false );
			$msg = 'Vorlage gelöscht.';
			break;

		case 'kopieren':
			$typ = 'station' === ( $p['typ'] ?? '' ) ? 'station' : 'schicht';
			$id  = (int) ( $p['id'] ?? 0 );
			if ( ! wls_event_von( $typ, $id ) ) {
				$err = 'Nicht gefunden.';
				break;
			}
			update_user_meta( get_current_user_id(), 'wls_zwischenablage', array( 'typ' => $typ, 'id' => $id ) );
			$msg = wls_ablage_text( array( 'typ' => $typ, 'id' => $id ) ) . ' kopiert – jetzt dort einfügen, wo sie hin soll.';
			break;

		case 'einfuegen':
			$a = wls_ablage();
			if ( ! $a || ! wls_event_von( $a['typ'], $a['id'] ) ) {
				$err = 'Die Zwischenablage ist leer oder das Original wurde gelöscht.';
				break;
			}
			$quelle_tag = wls_bezugstag( wls_event_von( $a['typ'], $a['id'] ) );
			$tage       = (int) ( $p['tage'] ?? 0 );
			$ziel_tag   = wls_tag_plus( $quelle_tag, $tage );
			if ( 'station' === $a['typ'] ) {
				$eid = (int) ( $p['event_id'] ?? 0 );
				$st  = wl_get_station( (int) $a['id'] );
				if ( ! wl_get_event( $eid ) || ! $st ) {
					$err = 'Ziel nicht gefunden.';
					break;
				}
				$struktur = wls_station_struktur( $st, $quelle_tag );
				if ( ! empty( $p['titel'] ) ) {
					$struktur['titel'] = sanitize_text_field( (string) $p['titel'] );
				}
				wls_station_anlegen( $eid, $struktur, $ziel_tag, wls_naechste_sortierung( 'stationen', 'event_id', $eid ) );
				$msg = sprintf( 'Station „%s“ eingefügt.', $struktur['titel'] );
			} else {
				$sid = (int) ( $p['station_id'] ?? 0 );
				$s   = wl_get_schicht( (int) $a['id'] );
				if ( ! wl_get_station( $sid ) || ! $s ) {
					$err = 'Ziel nicht gefunden.';
					break;
				}
				wls_schicht_anlegen( $sid, wls_schicht_struktur( $s, $quelle_tag ), $ziel_tag, wls_naechste_sortierung( 'schichten', 'station_id', $sid ) );
				$msg = 'Schicht eingefügt.';
			}
			break;

		case 'ablage_leeren':
			delete_user_meta( get_current_user_id(), 'wls_zwischenablage' );
			$msg = 'Zwischenablage geleert.';
			break;
	}
	wp_safe_redirect( add_query_arg( $err ? 'wls_err' : 'wls_msg', rawurlencode( $err ?: $msg ), $zurueck ) );
	exit;
}

function wls_struktur_hat_zeiten( array $struktur ) {
	foreach ( $struktur['stationen'] as $st ) {
		foreach ( $st['schichten'] as $s ) {
			if ( null !== $s['start'] || null !== $s['ende'] ) {
				return true;
			}
		}
	}
	return false;
}

/* =========================================================================
 * Bausteine für die Oberfläche (shifts-admin-frontend.php)
 * ====================================================================== */

/** Verstecktes Feld + Nonce für ein Aktionsformular. */
function wls_form_kopf( $aktion ) {
	return wp_nonce_field( 'wls_vorlagen', 'wls_nonce', true, false ) . '<input type="hidden" name="wls_aktion" value="' . esc_attr( $aktion ) . '">';
}

/** Meldung nach einer Aktion. */
function wls_meldung_html() {
	$h = '';
	if ( ! empty( $_GET['wls_msg'] ) ) {
		$h .= '<div class="wl-notice" style="background:#ecfdf5;border-left-color:#16a34a;">' . esc_html( sanitize_text_field( wp_unslash( $_GET['wls_msg'] ) ) ) . '</div>';
	}
	if ( ! empty( $_GET['wls_err'] ) ) {
		$h .= '<div class="wl-notice wl-notice-error">' . esc_html( sanitize_text_field( wp_unslash( $_GET['wls_err'] ) ) ) . '</div>';
	}
	return $h;
}

/** Kleiner Knopf, der ein Element in die Zwischenablage legt. */
function wls_kopieren_knopf( $typ, $id, $text = '📋 Kopieren' ) {
	return '<form method="post" style="display:inline">' . wls_form_kopf( 'kopieren' )
		. '<input type="hidden" name="typ" value="' . esc_attr( $typ ) . '"><input type="hidden" name="id" value="' . (int) $id . '">'
		. '<button type="submit" class="wl-btn wl-btn-sm wl-btn-secondary" title="In die Zwischenablage kopieren">' . esc_html( $text ) . '</button></form>';
}

/** Leiste über der Veranstaltungsliste: aus Vorlage anlegen + Vorlagenliste. */
function wls_liste_leiste_html() {
	$v = wls_vorlagen();
	ob_start();
	echo wls_meldung_html(); // phpcs:ignore
	?>
	<details class="wl-form-panel" style="display:block;margin-bottom:12px;">
		<summary style="cursor:pointer;font-weight:600;">⭐ Vorlagen (<?php echo count( $v ); ?>)</summary>
		<?php if ( $v ) : ?>
			<form method="post" style="margin-top:10px;">
				<?php echo wls_form_kopf( 'vorlage_anlegen' ); // phpcs:ignore ?>
				<h3 style="margin:0 0 6px;">Neue Veranstaltung aus Vorlage</h3>
				<div class="wl-form-grid">
					<div class="wl-form-row"><label>Vorlage</label>
						<select name="vorlage">
							<?php foreach ( $v as $id => $vv ) : ?>
								<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $vv['name'] . ' (' . count( $vv['struktur']['stationen'] ) . ' Stationen)' ); ?></option>
							<?php endforeach; ?>
						</select></div>
					<div class="wl-form-row"><label>Titel</label><input type="text" name="titel" placeholder="leer = Name der Vorlage"></div>
					<div class="wl-form-row"><label>Datum *</label><input type="date" name="datum"></div>
				</div>
				<p style="font-size:.8rem;color:#64748b;margin:0 0 8px;">Die Schichten landen mit denselben Uhrzeiten am gewählten Tag (bzw. an den Folgetagen, wie in der Vorlage).</p>
				<button type="submit" class="wl-btn wl-btn-primary">Anlegen</button>
			</form>
			<table class="wl-table" style="margin-top:12px;">
				<thead><tr><th>Vorlage</th><th>Stationen</th><th>Schichten</th><th>gespeichert</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $v as $id => $vv ) :
					$anz = 0;
					foreach ( $vv['struktur']['stationen'] as $st ) {
						$anz += count( $st['schichten'] );
					}
					?>
					<tr><td><?php echo esc_html( $vv['name'] ); ?></td><td><?php echo count( $vv['struktur']['stationen'] ); ?></td><td><?php echo (int) $anz; ?></td>
						<td><?php echo esc_html( mysql2date( 'd.m.Y', $vv['erstellt'] ) ); ?></td>
						<td><form method="post" onsubmit="return confirm('Vorlage löschen?');"><?php echo wls_form_kopf( 'vorlage_loeschen' ); // phpcs:ignore ?><input type="hidden" name="vorlage" value="<?php echo esc_attr( $id ); ?>"><button class="wl-btn wl-btn-sm wl-btn-delete">🗑️</button></form></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p style="color:#64748b;">Noch keine Vorlagen. Eine Veranstaltung mit „⭐ Als Vorlage“ speichern – Stationen und Schichten (mit Uhrzeiten) werden übernommen, Eintragungen nicht.</p>
		<?php endif; ?>
	</details>
	<?php
	return ob_get_clean();
}

/** Aktionen je Veranstaltung in der Liste: kopieren, als Vorlage. */
function wls_event_aktionen_html( $e ) {
	ob_start();
	?>
	<details style="display:inline-block;vertical-align:top;">
		<summary class="wl-btn wl-btn-sm wl-btn-secondary" style="list-style:none;cursor:pointer;">📋 Kopieren / ⭐ Vorlage</summary>
		<div class="wl-form-panel" style="display:block;min-width:260px;margin-top:6px;">
			<form method="post">
				<?php echo wls_form_kopf( 'event_kopieren' ); // phpcs:ignore ?>
				<input type="hidden" name="event_id" value="<?php echo (int) $e->id; ?>">
				<strong>Kopie anlegen</strong>
				<div class="wl-form-row"><label>Titel</label><input type="text" name="titel" value="<?php echo esc_attr( $e->titel . ' (Kopie)' ); ?>"></div>
				<div class="wl-form-row"><label>Neues Datum</label><input type="date" name="datum" value="<?php echo esc_attr( (string) $e->veranstaltungsdatum ); ?>">
					<p style="font-size:.75rem;color:#64748b;margin:4px 0 0;">Schichtzeiten wandern mit dem Datum mit.</p></div>
				<button type="submit" class="wl-btn wl-btn-sm wl-btn-primary">Kopie anlegen</button>
			</form>
			<form method="post" style="margin-top:10px;border-top:1px solid #e2e8f0;padding-top:8px;">
				<?php echo wls_form_kopf( 'vorlage_speichern' ); // phpcs:ignore ?>
				<input type="hidden" name="event_id" value="<?php echo (int) $e->id; ?>">
				<strong>Als Vorlage speichern</strong>
				<div class="wl-form-row"><label>Name der Vorlage</label><input type="text" name="name" value="<?php echo esc_attr( $e->titel ); ?>"></div>
				<button type="submit" class="wl-btn wl-btn-sm">⭐ Speichern</button>
			</form>
		</div>
	</details>
	<?php
	return ob_get_clean();
}

/** Leiste im Veranstaltungs-Editor: Meldung, Zwischenablage, Vorlage einfügen/speichern. */
function wls_editor_leiste_html( $event ) {
	$a   = wls_ablage();
	$tag = wls_bezugstag( (int) $event->id );
	ob_start();
	echo wls_meldung_html(); // phpcs:ignore
	if ( $a && 'station' === $a['typ'] && wls_event_von( 'station', $a['id'] ) ) {
		$qt = wls_bezugstag( wls_event_von( 'station', $a['id'] ) );
		?>
		<div class="wl-notice" style="background:#fefce8;border-left-color:#ca8a04;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
			<span>📋 In der Zwischenablage: <strong><?php echo esc_html( wls_ablage_text( $a ) ); ?></strong></span>
			<form method="post" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
				<?php echo wls_form_kopf( 'einfuegen' ); // phpcs:ignore ?>
				<input type="hidden" name="event_id" value="<?php echo (int) $event->id; ?>">
				<input type="text" name="titel" placeholder="neuer Name (optional)" style="width:180px;">
				<label style="font-size:.85rem;">Zeiten verschieben um <input type="number" name="tage" value="<?php echo (int) wls_tage_abstand( $qt, $tag ); ?>" style="width:64px;"> Tage</label>
				<button type="submit" class="wl-btn wl-btn-sm wl-btn-primary">📥 Station hier einfügen</button>
			</form>
			<form method="post"><?php echo wls_form_kopf( 'ablage_leeren' ); // phpcs:ignore ?><button class="wl-btn wl-btn-sm wl-btn-secondary">✕</button></form>
		</div>
		<?php
	} elseif ( $a && 'schicht' === $a['typ'] && wls_event_von( 'schicht', $a['id'] ) ) {
		?>
		<div class="wl-notice" style="background:#fefce8;border-left-color:#ca8a04;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
			<span>📋 In der Zwischenablage: <strong><?php echo esc_html( wls_ablage_text( $a ) ); ?></strong> – bei der gewünschten Station auf „📥 Schicht einfügen“ klicken.</span>
			<form method="post"><?php echo wls_form_kopf( 'ablage_leeren' ); // phpcs:ignore ?><button class="wl-btn wl-btn-sm wl-btn-secondary">✕</button></form>
		</div>
		<?php
	}
	$v = wls_vorlagen();
	?>
	<details class="wl-form-panel" style="display:block;margin-bottom:12px;">
		<summary style="cursor:pointer;font-weight:600;">⭐ Vorlagen</summary>
		<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-top:10px;">
			<form method="post">
				<?php echo wls_form_kopf( 'vorlage_speichern' ); // phpcs:ignore ?>
				<input type="hidden" name="event_id" value="<?php echo (int) $event->id; ?>">
				<strong>Diese Veranstaltung als Vorlage speichern</strong>
				<div class="wl-form-row"><label>Name der Vorlage</label><input type="text" name="name" value="<?php echo esc_attr( $event->titel ); ?>"></div>
				<button type="submit" class="wl-btn wl-btn-sm">⭐ Speichern</button>
			</form>
			<?php if ( $v ) : ?>
			<form method="post">
				<?php echo wls_form_kopf( 'vorlage_einfuegen' ); // phpcs:ignore ?>
				<input type="hidden" name="event_id" value="<?php echo (int) $event->id; ?>">
				<strong>Stationen aus einer Vorlage hinzufügen</strong>
				<div class="wl-form-row"><label>Vorlage</label><select name="vorlage">
					<?php foreach ( $v as $id => $vv ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $vv['name'] . ' (' . count( $vv['struktur']['stationen'] ) . ' Stationen)' ); ?></option>
					<?php endforeach; ?>
				</select></div>
				<div class="wl-form-row"><label>Bezugstag</label><input type="date" name="datum" value="<?php echo esc_attr( $tag ); ?>">
					<p style="font-size:.75rem;color:#64748b;margin:4px 0 0;">Tag, auf den sich die Uhrzeiten der Vorlage beziehen.</p></div>
				<button type="submit" class="wl-btn wl-btn-sm wl-btn-primary">📥 Einfügen</button>
			</form>
			<?php endif; ?>
		</div>
	</details>
	<?php
	return ob_get_clean();
}

/** „Schicht einfügen“ je Station, wenn eine Schicht in der Zwischenablage liegt. */
function wls_schicht_einfuegen_html( $station, $event ) {
	$a = wls_ablage();
	if ( ! $a || 'schicht' !== $a['typ'] || ! wls_event_von( 'schicht', $a['id'] ) ) {
		return '';
	}
	$tage = wls_tage_abstand( wls_bezugstag( wls_event_von( 'schicht', $a['id'] ) ), wls_bezugstag( (int) $event->id ) );
	return '<form method="post" style="display:inline-flex;gap:6px;align-items:center;margin:8px 0 0 8px;">' . wls_form_kopf( 'einfuegen' )
		. '<input type="hidden" name="station_id" value="' . (int) $station->id . '">'
		. '<label style="font-size:.8rem;">± Tage <input type="number" name="tage" value="' . (int) $tage . '" style="width:56px;"></label>'
		. '<button type="submit" class="wl-btn wl-btn-sm wl-btn-primary">📥 Schicht einfügen</button></form>';
}
