<?php
/**
 * Kern: REST-Sync-API für die Offline-Desktop-App („Vereinssync").
 *
 * Namespace:  vereinsplugin/v1
 * Auth:       WordPress Application Passwords (HTTPS Basic-Auth). Es wird nichts
 *             von uns gespeichert; pro Gerät widerrufbar.
 * Rechte:     jede angemeldete Person darf lesen/synchronisieren – die Daten
 *             werden pro Capability gefiltert (Vorstand sieht alles, ein
 *             Mitglied nur sein eigenes Profil + eigene Auslagen …).
 *
 * Routen
 *   GET  /me                       Identität + relevante Capabilities
 *   GET  /meta                     sync-bare Tabellen + Spalten + Sichtbarkeit
 *   GET  /snapshot?since=ISO       Voll-/Delta-Abzug (rechteabhängig gefiltert)
 *   POST /mutations                Batch upsert/delete mit `_rev`-Konflikterkennung
 *   POST /actions/antrag-decide    { id, action:annehmen|ablehnen, notiz }
 *   POST /actions/auslage-decide   { id, approve:bool, notiz }  (+ Journalbuchung)
 *   POST /actions/auslage-auszahlen{ id }
 *   POST /actions/auslage-einreichen (multipart: Felder + optional Beleg)
 *   POST /actions/journal-add      { buchung_datum, betrag, konto, … }
 *   POST /actions/bank-csv         { csv, delim } → Vorschau; { import:true, rows } → buchen
 *   GET  /report/summary?year=     Kassenbericht / EÜR-Zahlen
 *   GET  /nextcloud/users|groups   Wrappt vp_nc_get_users() / vp_nc_get_groups()
 *   POST /nextcloud/sync           Wrappt vp_nc_sync( dry )
 *   GET/POST /nextcloud/beleg      Beleg-Download-Link / -Upload
 *
 * Die App hält nur WordPress-URL + Application Password. Der Nextcloud-Admin-Zugang
 * bleibt serverseitig (Proxy).
 */

defined( 'ABSPATH' ) || exit;

const VP_SYNC_API_NS = 'vereinsplugin/v1';

/**
 * Kleine Schema-Ergänzung: Wünsche einem Kreis/Gremium zuordnen können.
 * (Die Wunschliste-Migration läuft nur bei Modul-Aktivierung – hier idempotent.)
 */
add_action( 'plugins_loaded', function () {
	if ( get_option( 'vp_wunsch_gremium_col' ) === '1' ) {
		return;
	}
	global $wpdb;
	$t = $wpdb->prefix . 'wunschliste';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) ) {
		$has = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'gremium_id'",
			$t
		) );
		if ( ! $has ) {
			$wpdb->query( "ALTER TABLE `$t` ADD COLUMN `gremium_id` BIGINT UNSIGNED DEFAULT NULL" );
		}
		update_option( 'vp_wunsch_gremium_col', '1' );
	}
}, 7 );

/* =========================================================================
 * Tabellen-Registry – die einzige Stelle, an der Sync-Tabellen definiert sind.
 * time = Spalte für Delta-Filter (oder null → Tabelle kommt immer voll).
 * ====================================================================== */

function vp_sync_tables() {
	// slug => [ table (ohne Prefix), pk, time, cap (Vollzugriff), self (Spalte für Eigen-Filter | null = verbergen) ]
	// cap '' bedeutet: nur Vorstand/Admin (vp_sync_can()).
	$defs = array(
		// Wunschliste / Schichten
		'wunschliste'            => array( 'wunschliste',            'id', 'geaendert_am',   'wl_manage_wishes', null ),
		'wl_links'               => array( 'wl_links',               'id', null,             'wl_manage_wishes', null ),
		'wl_votes'               => array( 'wl_votes',               'id', 'geaendert_am',   'wl_manage_wishes', '__voterkey' ),
		'wl_shift_events'        => array( 'wl_shift_events',         'id', null,             'read',             null ),
		'wl_shift_stationen'     => array( 'wl_shift_stationen',      'id', null,             'read',             null ),
		'wl_shift_schichten'     => array( 'wl_shift_schichten',      'id', null,             'read',             null ),
		'wl_shift_eintragungen'  => array( 'wl_shift_eintragungen',   'id', 'eingetragen_am','wl_manage_wishes', 'user_id' ),
		'wl_shift_tausch'        => array( 'wl_shift_tausch',         'id', 'erstellt_am',   'wl_manage_wishes', '__tausch' ),
		// Protokoll
		'pp_gremien'             => array( 'pp_gremien',              'id', 'erstellt_am',   'pp_manage', null ),
		'pp_rollen'              => array( 'pp_rollen',               'id', 'erstellt_am',   'pp_manage', null ),
		'pp_rollenvorlagen'      => array( 'pp_rollenvorlagen',       'id', 'erstellt_am',   'pp_manage', null ),
		'pp_rollenvorlagen_aufgaben' => array( 'pp_rollenvorlagen_aufgaben', 'id', 'erstellt_am', 'pp_manage', null ),
		'pp_protokolle'          => array( 'pp_protokolle',           'id', 'geaendert_am',  'pp_manage', null ),
		'pp_tops'                => array( 'pp_tops',                 'id', null,            'pp_manage', null ),
		'pp_einwaende'           => array( 'pp_einwaende',            'id', 'erstellt_am',   'pp_manage', null ),
		'pp_themen'              => array( 'pp_themen',               'id', 'erstellt_am',   'pp_manage', null ),
		'pp_aufgaben'            => array( 'pp_aufgaben',             'id', 'erstellt_am',   'pp_manage', null ),
		'pp_termine'             => array( 'pp_termine',              'id', 'erstellt_am',   'pp_manage', null ),
		'pp_bestaetigungen'      => array( 'pp_bestaetigungen',       'id', 'erstellt_am',   'pp_manage', null ),
		'pp_freigaben'           => array( 'pp_freigaben',            'id', 'erstellt_am',   'pp_manage', null ),
		'pp_kreis_mitglieder'    => array( 'pp_kreis_mitglieder',     'id', 'erstellt_am',   'pp_manage', null ),
		'pp_kommentare'          => array( 'pp_kommentare',           'id', 'erstellt_am',   'pp_manage', null ),
		'pp_aufgaben_sets'       => array( 'pp_aufgaben_sets',        'id', 'erstellt_am',   'pp_manage', null ),
		'pp_aufgaben_set_eintraege' => array( 'pp_aufgaben_set_eintraege', 'id', 'erstellt_am', 'pp_manage', null ),
		// Buchhaltung
		'jb_auslagen'            => array( 'jb_auslagen',             'id', 'eingereicht_am','jb_view_journal', 'user_id' ),
		'jb_buchungen'           => array( 'jb_buchungen',            'id', 'erstellt_am',   'jb_view_journal', null ),
		'jb_budgets'             => array( 'jb_budgets',              'id', 'erstellt_am',   'jb_view_journal', null ),
		'jb_ruecklagen'          => array( 'jb_ruecklagen',           'id', null,            'jb_view_journal', null ),
		'jb_getraenke'           => array( 'jb_getraenke',            'id', null,            'jb_view_journal', null ),
		'jb_getraenke_bewegungen'=> array( 'jb_getraenke_bewegungen', 'id', 'erstellt_am',  'jb_view_journal', null ),
		'jb_konten'              => array( 'jb_konten',               'id', null,            'jb_submit_auslagen', null ), // Konten dürfen auch Einreicher sehen (Kategorie-Auswahl)
		'jb_konto_regeln'        => array( 'jb_konto_regeln',         'id', null,            'jb_view_journal', null ),
		// Anträge / Newsletter
		'vp_antraege'            => array( 'vp_antraege',             'id', 'created_at',    'vp_manage_members', '__email' ),
		'vp_newsletter'          => array( 'vp_newsletter',           'id', 'gesendet_am',   'vp_manage_members', null ),
	);

	$out = array();
	foreach ( $defs as $slug => $d ) {
		$out[ $slug ] = array( 'table' => $d[0], 'pk' => $d[1], 'time' => $d[2], 'cap' => $d[3] ?? '', 'self' => $d[4] ?? null );
	}
	return apply_filters( 'vp_sync_tables', $out );
}

/**
 * Sichtbarkeit einer Tabelle für den aktuellen Benutzer.
 * @return array{mode:'all'|'self'|'none', self:?string}
 */
function vp_sync_visibility( array $def ) {
	if ( vp_sync_can() ) {
		return array( 'mode' => 'all', 'self' => null );
	}
	$cap = $def['cap'] ?? '';
	if ( $cap && current_user_can( $cap ) ) {
		return array( 'mode' => 'all', 'self' => null );
	}
	if ( ! empty( $def['self'] ) ) {
		return array( 'mode' => 'self', 'self' => $def['self'] );
	}
	return array( 'mode' => 'none', 'self' => null );
}

/** WP-Mitglieder als Pseudo-Tabelle mitgeführte usermeta-Schlüssel. */
function vp_sync_member_meta_keys() {
	return array(
		'vp_telefon', 'vp_geburtsdatum', 'vp_strasse', 'vp_plz', 'vp_ort', 'vp_land',
		'vp_beitrag', 'vp_beitrag_intervall', 'vp_sepa_iban', 'vp_sepa_kontoinhaber',
		'vp_sepa_mandat', 'vp_mandatsref', 'vp_mitglied_seit',
	);
}

/** Rollen, deren Benutzer als „Mitglied" exportiert werden. */
function vp_sync_member_roles() {
	$roles = array( VP_MEMBER_ROLE, 'editor', 'administrator' );
	return apply_filters( 'vp_sync_member_roles', array_values( array_unique( $roles ) ) );
}

/** Echte Spaltenliste einer Tabelle (gecached pro Request). */
function vp_sync_columns( $unprefixed ) {
	static $cache = array();
	if ( isset( $cache[ $unprefixed ] ) ) {
		return $cache[ $unprefixed ];
	}
	global $wpdb;
	$table = $wpdb->prefix . $unprefixed;
	$cols  = $wpdb->get_col( "SHOW COLUMNS FROM `$table`" ); // Tabellenname aus fixer Registry, kein User-Input.
	$cache[ $unprefixed ] = is_array( $cols ) ? $cols : array();
	return $cache[ $unprefixed ];
}

/* =========================================================================
 * Zeilen-Revision – identischer Hash in App (src/sync/revision.js).
 * Kanonisch: Schlüssel binär aufsteigend sortiert, je Feld "key=value"
 * (null → "\N"), verbunden mit \x1f, dann crc32b hex (8 Zeichen).
 * `_rev` selbst wird vor der Berechnung entfernt.
 * ====================================================================== */

function vp_sync_rev( array $row ) {
	unset( $row['_rev'] );
	$keys = array_keys( $row );
	sort( $keys, SORT_STRING );
	$parts = array();
	foreach ( $keys as $k ) {
		$v = $row[ $k ];
		if ( is_null( $v ) ) {
			$v = '\\N';
		} elseif ( is_bool( $v ) ) {
			$v = $v ? '1' : '0';
		} else {
			$v = (string) $v;
		}
		$parts[] = $k . '=' . $v;
	}
	return hash( 'crc32b', implode( "\x1f", $parts ) );
}

/* =========================================================================
 * Routen-Registrierung
 * ====================================================================== */

add_action( 'rest_api_init', function () {
	$perm     = 'vp_sync_can';               // Vorstand/Admin
	$loggedin = 'is_user_logged_in';         // jedes angemeldete Mitglied
	$cap      = function ( $c ) {             // eine bestimmte Capability
		return function () use ( $c ) {
			return current_user_can( $c ) || current_user_can( 'manage_options' );
		};
	};

	register_rest_route( VP_SYNC_API_NS, '/me', array(
		'methods'             => 'GET',
		'permission_callback' => $loggedin,
		'callback'            => 'vp_sync_route_me',
	) );

	register_rest_route( VP_SYNC_API_NS, '/meta', array(
		'methods'             => 'GET',
		'permission_callback' => $loggedin,
		'callback'            => 'vp_sync_route_meta',
	) );

	register_rest_route( VP_SYNC_API_NS, '/snapshot', array(
		'methods'             => 'GET',
		'permission_callback' => $loggedin,
		'callback'            => 'vp_sync_route_snapshot',
		'args'                => array(
			'since'  => array( 'type' => 'string', 'required' => false ),
			'tables' => array( 'type' => 'string', 'required' => false ), // Komma-Liste, optional
		),
	) );

	register_rest_route( VP_SYNC_API_NS, '/mutations', array(
		'methods'             => 'POST',
		'permission_callback' => $loggedin,
		'callback'            => 'vp_sync_route_mutations',
	) );

	/* ---- Aktions-Endpunkte (Workflows, nur online) ---- */

	register_rest_route( VP_SYNC_API_NS, '/actions/antrag-decide', array(
		'methods'             => 'POST',
		'permission_callback' => $cap( 'vp_manage_members' ),
		'callback'            => 'vp_sync_action_antrag_decide',
	) );

	register_rest_route( VP_SYNC_API_NS, '/actions/auslage-decide', array(
		'methods'             => 'POST',
		'permission_callback' => $cap( 'jb_approve_auslagen' ),
		'callback'            => 'vp_sync_action_auslage_decide',
	) );

	register_rest_route( VP_SYNC_API_NS, '/actions/auslage-auszahlen', array(
		'methods'             => 'POST',
		'permission_callback' => $cap( 'jb_approve_auslagen' ),
		'callback'            => 'vp_sync_action_auslage_pay',
	) );

	register_rest_route( VP_SYNC_API_NS, '/actions/auslage-einreichen', array(
		'methods'             => 'POST',
		'permission_callback' => $cap( 'jb_submit_auslagen' ),
		'callback'            => 'vp_sync_action_auslage_submit',
	) );

	register_rest_route( VP_SYNC_API_NS, '/actions/journal-add', array(
		'methods'             => 'POST',
		'permission_callback' => $cap( 'jb_view_journal' ),
		'callback'            => 'vp_sync_action_journal_add',
	) );

	register_rest_route( VP_SYNC_API_NS, '/actions/bank-csv', array(
		'methods'             => 'POST',
		'permission_callback' => $cap( 'jb_view_journal' ),
		'callback'            => 'vp_sync_action_bank_csv',
	) );

	register_rest_route( VP_SYNC_API_NS, '/report/summary', array(
		'methods'             => 'GET',
		'permission_callback' => $loggedin, // Kassenbericht sehen alle Mitglieder
		'callback'            => 'vp_sync_report_summary',
		'args'                => array( 'year' => array( 'type' => 'integer', 'required' => false ) ),
	) );

	/* ---- Editierbare Mitglieder-/Vorstands-Bereiche ---- */

	$route = function ( $path, $capname, $fn ) use ( $cap ) {
		register_rest_route( VP_SYNC_API_NS, $path, array(
			'methods'             => 'POST',
			'permission_callback' => $cap( $capname ),
			'callback'            => $fn,
		) );
	};
	$route( '/actions/newsletter-send',  'vp_manage_members', 'vp_sync_action_newsletter_send' );
	$route( '/actions/wunsch-save',      'wl_manage_wishes',  'vp_sync_action_wunsch_save' );
	$route( '/actions/wunsch-delete',    'wl_manage_wishes',  'vp_sync_action_wunsch_delete' );
	$route( '/actions/vote-cast',        'read',              'vp_sync_action_vote_cast' );
	$route( '/actions/vote-retract',     'read',              'vp_sync_action_vote_retract' );
	$route( '/actions/schicht-eintragen','read',              'vp_sync_action_schicht_eintragen' );
	$route( '/actions/schicht-austragen','read',              'vp_sync_action_schicht_austragen' );
	$route( '/actions/protokoll-save',   'pp_manage',         'vp_sync_action_protokoll_save' );
	$route( '/actions/top-save',         'pp_manage',         'vp_sync_action_top_save' );
	$route( '/actions/top-delete',       'pp_manage',         'vp_sync_action_top_delete' );
	$route( '/actions/aufgabe-save',     'pp_manage',         'vp_sync_action_aufgabe_save' );
	$route( '/actions/thema-save',       'pp_manage',         'vp_sync_action_thema_save' );
	$route( '/actions/gremium-save',     'pp_manage',         'vp_sync_action_gremium_save' );
	$route( '/actions/gremium-delete',   'pp_manage',         'vp_sync_action_gremium_delete' );
	$route( '/actions/kreis-mitglied',   'pp_manage',         'vp_sync_action_kreis_mitglied' );
	$route( '/actions/rolle-save',       'pp_manage',         'vp_sync_action_rolle_save' );
	$route( '/actions/rolle-delete',     'pp_manage',         'vp_sync_action_rolle_delete' );
	$route( '/actions/shift-event-save',   'wl_manage_wishes', 'vp_sync_action_shift_event_save' );
	$route( '/actions/shift-event-delete', 'wl_manage_wishes', 'vp_sync_action_shift_event_delete' );
	$route( '/actions/shift-station-save', 'wl_manage_wishes', 'vp_sync_action_shift_station_save' );
	$route( '/actions/shift-station-delete','wl_manage_wishes','vp_sync_action_shift_station_delete' );
	$route( '/actions/shift-schicht-save', 'wl_manage_wishes', 'vp_sync_action_shift_schicht_save' );
	$route( '/actions/shift-schicht-delete','wl_manage_wishes','vp_sync_action_shift_schicht_delete' );
	$route( '/actions/shift-tausch',       'read',            'vp_sync_action_shift_tausch' );

	register_rest_route( VP_SYNC_API_NS, '/nextcloud/users', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			$r = function_exists( 'vp_nc_get_users' ) ? vp_nc_get_users() : new WP_Error( 'no_nc', 'Nextcloud-Modul nicht geladen.' );
			return is_wp_error( $r ) ? $r : rest_ensure_response( array( 'users' => $r ) );
		},
	) );

	register_rest_route( VP_SYNC_API_NS, '/nextcloud/groups', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			$r = function_exists( 'vp_nc_get_groups' ) ? vp_nc_get_groups() : new WP_Error( 'no_nc', 'Nextcloud-Modul nicht geladen.' );
			return is_wp_error( $r ) ? $r : rest_ensure_response( array( 'groups' => $r ) );
		},
	) );

	register_rest_route( VP_SYNC_API_NS, '/nextcloud/sync', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $req ) {
			if ( ! function_exists( 'vp_nc_sync' ) ) {
				return new WP_Error( 'no_nc', 'Nextcloud-Modul nicht geladen.', array( 'status' => 400 ) );
			}
			$dry = (bool) $req->get_param( 'dry' );
			return rest_ensure_response( vp_nc_sync( $dry ) );
		},
	) );

	register_rest_route( VP_SYNC_API_NS, '/nextcloud/beleg', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => 'vp_sync_route_beleg_get',
			'args'                => array( 'path' => array( 'type' => 'string', 'required' => true ) ),
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => 'vp_sync_route_beleg_post',
		),
	) );
} );

function vp_sync_can() {
	return current_user_can( 'vp_manage_members' ) || current_user_can( 'manage_options' );
}

/* =========================================================================
 * GET /meta
 * ====================================================================== */

function vp_sync_route_meta() {
	$manage = vp_sync_can();
	$tables = array();
	foreach ( vp_sync_tables() as $slug => $def ) {
		$cols = vp_sync_columns( $def['table'] );
		if ( ! $cols ) {
			continue; // Tabelle existiert (noch) nicht → auslassen.
		}
		$vis = vp_sync_visibility( $def );
		if ( 'none' === $vis['mode'] ) {
			continue; // für diesen Benutzer nicht sichtbar
		}
		$tables[ $slug ] = array(
			'pk'         => $def['pk'],
			'time_col'   => $def['time'],
			'columns'    => array_values( $cols ),
			'visibility' => $vis['mode'], // all | self
			'writable'   => 'all' === $vis['mode'] ? array_values( array_diff( $cols, array( $def['pk'] ) ) ) : array(),
		);
	}

	// Pseudo-Tabelle Mitglieder.
	$tables['wp_members'] = array(
		'pk'         => 'id',
		'time_col'   => null,
		'visibility' => $manage ? 'all' : 'self',
		'columns'    => array_merge(
			array( 'id', 'user_login', 'user_email', 'display_name', 'first_name', 'last_name', 'roles' ),
			vp_sync_member_meta_keys()
		),
		'writable'   => array_merge(
			array( 'user_email', 'display_name', 'first_name', 'last_name' ),
			vp_sync_member_meta_keys()
		),
	);

	return rest_ensure_response( array(
		'plugin_version' => defined( 'VP_VERSION' ) ? VP_VERSION : '',
		'api_version'    => 2,
		'server_time'    => current_time( 'mysql', true ), // UTC
		'timezone'       => wp_timezone_string(),
		'can_manage'     => $manage,
		'tables'         => $tables,
	) );
}

/* =========================================================================
 * GET /me – Identität + relevante Rechte für die App-Oberfläche
 * ====================================================================== */

function vp_sync_route_me() {
	$u = wp_get_current_user();
	$caps = array();
	foreach ( array(
		'manage_options', 'vp_manage_members',
		'jb_view_journal', 'jb_view_auslagen', 'jb_approve_auslagen', 'jb_submit_auslagen', 'jb_export',
		'pp_manage', 'wl_manage_wishes', 'jbf_edit_events',
	) as $c ) {
		$caps[ $c ] = current_user_can( $c );
	}
	return rest_ensure_response( array(
		'id'           => (int) $u->ID,
		'login'        => $u->user_login,
		'email'        => $u->user_email,
		'display_name' => $u->display_name,
		'roles'        => array_values( (array) $u->roles ),
		'caps'         => $caps,
		'can_manage'   => vp_sync_can(),
	) );
}

/* =========================================================================
 * GET /snapshot
 * ====================================================================== */

function vp_sync_route_snapshot( WP_REST_Request $req ) {
	global $wpdb;

	$since_raw = trim( (string) $req->get_param( 'since' ) );
	$since_sql = '';
	if ( $since_raw ) {
		$ts = strtotime( $since_raw );
		if ( $ts ) {
			$since_sql = gmdate( 'Y-m-d H:i:s', $ts ); // Vergleich in UTC gegen gespeicherte Zeiten
		}
	}

	$only = array_filter( array_map( 'trim', explode( ',', (string) $req->get_param( 'tables' ) ) ) );

	$uid       = get_current_user_id();
	$my_email  = strtolower( (string) wp_get_current_user()->user_email );
	$result    = array();

	foreach ( vp_sync_tables() as $slug => $def ) {
		if ( $only && ! in_array( $slug, $only, true ) ) {
			continue;
		}
		$cols = vp_sync_columns( $def['table'] );
		if ( ! $cols ) {
			continue;
		}
		$vis = vp_sync_visibility( $def );
		if ( 'none' === $vis['mode'] ) {
			continue;
		}
		$table  = $wpdb->prefix . $def['table'];
		$full   = true;
		$where  = array();
		$params = array();

		if ( $since_sql && $def['time'] && in_array( $def['time'], $cols, true ) ) {
			$where[]  = "`{$def['time']}` >= %s";
			$params[] = $since_sql;
			$full     = false;
		}
		if ( 'self' === $vis['mode'] ) {
			if ( '__email' === $vis['self'] && in_array( 'email', $cols, true ) ) {
				$where[]  = 'LOWER(`email`) = %s';
				$params[] = $my_email;
			} elseif ( '__voterkey' === $vis['self'] && in_array( 'voter_key', $cols, true ) ) {
				$where[]  = '`voter_key` = %s';
				$params[] = 'u' . $uid;
			} elseif ( '__tausch' === $vis['self'] ) {
				$et       = $wpdb->prefix . 'wl_shift_eintragungen';
				$where[]  = "( `an_email` = %s OR `von_eintrag_id` IN ( SELECT id FROM `$et` WHERE user_id = %d ) )";
				$params[] = $my_email;
				$params[] = $uid;
			} elseif ( '__' !== substr( (string) $vis['self'], 0, 2 ) && in_array( (string) $vis['self'], $cols, true ) ) {
				$where[]  = "`" . $vis['self'] . "` = %d";
				$params[] = $uid;
			} else {
				continue; // Eigen-Filter nicht anwendbar → lieber nichts liefern
			}
		}

		$sql = "SELECT * FROM `$table`";
		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
			$sql  = $wpdb->prepare( $sql, ...$params ); // phpcs:ignore
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
		$rows = is_array( $rows ) ? $rows : array();
		foreach ( $rows as &$r ) {
			$r['_rev'] = vp_sync_rev( $r );
		}
		unset( $r );

		$result[ $slug ] = array( 'rows' => $rows, 'full' => $full, 'visibility' => $vis['mode'] );
	}

	// Mitglieder (immer voll – klein genug, keine verlässliche Zeitspalte).
	if ( ! $only || in_array( 'wp_members', $only, true ) ) {
		$members = array();
		if ( vp_sync_can() ) {
			$users = get_users( array(
				'role__in' => vp_sync_member_roles(),
				'fields'   => array( 'ID', 'user_login', 'user_email', 'display_name' ),
			) );
		} else {
			$users = array( wp_get_current_user() ); // nur ich selbst
		}
		foreach ( $users as $u ) {
			$row = array(
				'id'           => (int) $u->ID,
				'user_login'   => $u->user_login,
				'user_email'   => $u->user_email,
				'display_name' => $u->display_name,
				'first_name'   => get_user_meta( $u->ID, 'first_name', true ),
				'last_name'    => get_user_meta( $u->ID, 'last_name', true ),
				'roles'        => implode( ',', (array) ( get_userdata( $u->ID )->roles ?? array() ) ),
			);
			foreach ( vp_sync_member_meta_keys() as $k ) {
				$row[ $k ] = (string) get_user_meta( $u->ID, $k, true );
			}
			$row['_rev'] = vp_sync_rev( $row );
			$members[]   = $row;
		}
		$result['wp_members'] = array( 'rows' => $members, 'full' => true, 'visibility' => vp_sync_can() ? 'all' : 'self' );
	}

	return rest_ensure_response( array(
		'server_time' => current_time( 'mysql', true ),
		'since'       => $since_sql,
		'tables'      => $result,
	) );
}

/* =========================================================================
 * POST /mutations
 * Body: { "mutations": [ { table, op:"upsert"|"delete", pk, base_rev, fields } ] }
 * ====================================================================== */

function vp_sync_route_mutations( WP_REST_Request $req ) {
	$body = $req->get_json_params();
	$list = isset( $body['mutations'] ) && is_array( $body['mutations'] ) ? $body['mutations'] : array();

	$applied   = array();
	$conflicts = array();
	$errors    = array();

	foreach ( $list as $i => $m ) {
		$slug    = isset( $m['table'] ) ? (string) $m['table'] : '';
		$op      = isset( $m['op'] ) ? (string) $m['op'] : 'upsert';
		$pk      = isset( $m['pk'] ) ? (int) $m['pk'] : 0;
		$baserev = isset( $m['base_rev'] ) ? (string) $m['base_rev'] : '';
		$fields  = isset( $m['fields'] ) && is_array( $m['fields'] ) ? $m['fields'] : array();
		$cid     = isset( $m['cid'] ) ? (string) $m['cid'] : (string) $i; // Client-Korrelation

		$res = vp_sync_apply_one( $slug, $op, $pk, $baserev, $fields );

		if ( 'conflict' === $res['kind'] ) {
			$conflicts[] = array( 'cid' => $cid, 'table' => $slug, 'pk' => $pk, 'server_row' => $res['server_row'] );
		} elseif ( 'error' === $res['kind'] ) {
			$errors[] = array( 'cid' => $cid, 'table' => $slug, 'pk' => $pk, 'message' => $res['message'] );
		} else {
			$applied[] = array_merge(
				array( 'cid' => $cid, 'table' => $slug, 'pk' => $pk ),
				isset( $res['new_pk'] ) ? array( 'new_pk' => $res['new_pk'] ) : array(),
				isset( $res['new_rev'] ) ? array( 'new_rev' => $res['new_rev'] ) : array()
			);
		}
	}

	return rest_ensure_response( array(
		'server_time' => current_time( 'mysql', true ),
		'applied'     => $applied,
		'conflicts'   => $conflicts,
		'errors'      => $errors,
	) );
}

/**
 * Eine Mutation anwenden.
 * @return array{kind:'ok'|'conflict'|'error', ...}
 */
function vp_sync_apply_one( $slug, $op, $pk, $baserev, array $fields ) {
	if ( 'wp_members' === $slug ) {
		return vp_sync_apply_member( $op, $pk, $baserev, $fields );
	}

	$defs = vp_sync_tables();
	if ( ! isset( $defs[ $slug ] ) ) {
		return array( 'kind' => 'error', 'message' => 'Unbekannte Tabelle: ' . $slug );
	}
	global $wpdb;
	$def   = $defs[ $slug ];
	$table = $wpdb->prefix . $def['table'];
	$pkcol = $def['pk'];
	$cols  = vp_sync_columns( $def['table'] );
	if ( ! $cols ) {
		return array( 'kind' => 'error', 'message' => 'Tabelle fehlt: ' . $slug );
	}

	// Schreibrechte: ohne Vorstands-/Modul-Cap darf über /mutations an diesen
	// Tabellen nichts geändert werden (Mitglieder-Workflows laufen über /actions).
	if ( 'all' !== vp_sync_visibility( $def )['mode'] ) {
		return array( 'kind' => 'error', 'message' => 'Kein Schreibrecht für „' . $slug . '" – bitte den passenden Vorgang (Aktion) nutzen.' );
	}

	// Nur echte Spalten (ohne PK) sind schreibbar.
	$allowed = array_diff( $cols, array( $pkcol ) );
	$clean   = array();
	foreach ( $fields as $k => $v ) {
		if ( in_array( $k, $allowed, true ) ) {
			$clean[ $k ] = is_scalar( $v ) || is_null( $v ) ? $v : wp_json_encode( $v );
		}
	}

	$load = function ( $id ) use ( $wpdb, $table, $pkcol ) {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$table` WHERE `$pkcol` = %d", $id ), ARRAY_A );
		return $row ?: null;
	};

	/* ---- INSERT ---- */
	if ( 'upsert' === $op && $pk <= 0 ) {
		if ( ! $clean ) {
			return array( 'kind' => 'error', 'message' => 'Keine gültigen Felder für Insert.' );
		}
		$ok = $wpdb->insert( $table, $clean );
		if ( false === $ok ) {
			return array( 'kind' => 'error', 'message' => 'DB-Insert fehlgeschlagen: ' . $wpdb->last_error );
		}
		$new = (int) $wpdb->insert_id;
		$row = $load( $new );
		return array( 'kind' => 'ok', 'new_pk' => $new, 'new_rev' => $row ? vp_sync_rev( $row ) : '' );
	}

	$current = $load( $pk );

	/* ---- DELETE ---- */
	if ( 'delete' === $op ) {
		if ( ! $current ) {
			return array( 'kind' => 'ok' ); // schon weg
		}
		if ( $baserev && vp_sync_rev( $current ) !== $baserev ) {
			return array( 'kind' => 'conflict', 'server_row' => vp_sync_with_rev( $current ) );
		}
		$wpdb->delete( $table, array( $pkcol => $pk ) );
		return array( 'kind' => 'ok' );
	}

	/* ---- UPDATE ---- */
	if ( ! $current ) {
		// Am Server gelöscht, während lokal geändert → Konflikt.
		return array( 'kind' => 'conflict', 'server_row' => null );
	}
	if ( $baserev && vp_sync_rev( $current ) !== $baserev ) {
		return array( 'kind' => 'conflict', 'server_row' => vp_sync_with_rev( $current ) );
	}
	if ( ! $clean ) {
		return array( 'kind' => 'ok', 'new_rev' => vp_sync_rev( $current ) );
	}
	$ok = $wpdb->update( $table, $clean, array( $pkcol => $pk ) );
	if ( false === $ok ) {
		return array( 'kind' => 'error', 'message' => 'DB-Update fehlgeschlagen: ' . $wpdb->last_error );
	}
	$row = $load( $pk );
	return array( 'kind' => 'ok', 'new_rev' => $row ? vp_sync_rev( $row ) : '' );
}

function vp_sync_with_rev( array $row ) {
	$row['_rev'] = vp_sync_rev( $row );
	return $row;
}

/** Mitglied (WP-User + usermeta) aktualisieren. Kein Anlegen/Löschen über diese API. */
function vp_sync_apply_member( $op, $pk, $baserev, array $fields ) {
	if ( 'delete' === $op ) {
		return array( 'kind' => 'error', 'message' => 'Mitglieder werden über diese API nicht gelöscht.' );
	}
	if ( $pk <= 0 ) {
		return array( 'kind' => 'error', 'message' => 'Mitglieder werden über diese API nicht angelegt (Mitgliedsantrag nutzen).' );
	}
	// Ohne Verwaltungsrecht darf nur das eigene Profil geändert werden.
	if ( ! vp_sync_can() && (int) $pk !== get_current_user_id() ) {
		return array( 'kind' => 'error', 'message' => 'Nur das eigene Profil ist änderbar.' );
	}
	$user = get_userdata( $pk );
	if ( ! $user ) {
		return array( 'kind' => 'conflict', 'server_row' => null );
	}

	$current = vp_sync_member_row( $user );
	if ( $baserev && vp_sync_rev( $current ) !== $baserev ) {
		return array( 'kind' => 'conflict', 'server_row' => vp_sync_with_rev( $current ) );
	}

	$meta_keys = vp_sync_member_meta_keys();
	$userdata  = array( 'ID' => $pk );
	foreach ( array( 'user_email', 'display_name', 'first_name', 'last_name' ) as $k ) {
		if ( array_key_exists( $k, $fields ) ) {
			$userdata[ $k ] = 'user_email' === $k ? sanitize_email( (string) $fields[ $k ] ) : sanitize_text_field( (string) $fields[ $k ] );
		}
	}
	if ( count( $userdata ) > 1 ) {
		$r = wp_update_user( $userdata );
		if ( is_wp_error( $r ) ) {
			return array( 'kind' => 'error', 'message' => $r->get_error_message() );
		}
	}
	foreach ( $meta_keys as $k ) {
		if ( array_key_exists( $k, $fields ) ) {
			update_user_meta( $pk, $k, sanitize_text_field( (string) $fields[ $k ] ) );
		}
	}

	$fresh = vp_sync_member_row( get_userdata( $pk ) );
	return array( 'kind' => 'ok', 'new_rev' => vp_sync_rev( $fresh ) );
}

function vp_sync_member_row( WP_User $u ) {
	$row = array(
		'id'           => (int) $u->ID,
		'user_login'   => $u->user_login,
		'user_email'   => $u->user_email,
		'display_name' => $u->display_name,
		'first_name'   => get_user_meta( $u->ID, 'first_name', true ),
		'last_name'    => get_user_meta( $u->ID, 'last_name', true ),
		'roles'        => implode( ',', (array) $u->roles ),
	);
	foreach ( vp_sync_member_meta_keys() as $k ) {
		$row[ $k ] = (string) get_user_meta( $u->ID, $k, true );
	}
	return $row;
}

/* =========================================================================
 * Nextcloud-Beleg-Proxy
 * ====================================================================== */

function vp_sync_route_beleg_get( WP_REST_Request $req ) {
	if ( ! function_exists( 'jb_nc' ) ) {
		return new WP_Error( 'no_nc', 'Buchhaltungs-Modul nicht geladen.', array( 'status' => 400 ) );
	}
	$path = ltrim( (string) $req->get_param( 'path' ), '/' );
	if ( '' === $path ) {
		return new WP_Error( 'no_path', 'Kein Pfad angegeben.', array( 'status' => 400 ) );
	}
	$url = jb_nc()->get_download_url( $path );
	return rest_ensure_response( array( 'url' => $url ) );
}

function vp_sync_route_beleg_post( WP_REST_Request $req ) {
	if ( ! function_exists( 'jb_nc' ) ) {
		return new WP_Error( 'no_nc', 'Buchhaltungs-Modul nicht geladen.', array( 'status' => 400 ) );
	}
	$files = $req->get_file_params();
	$file  = $files['file'] ?? null;
	$dest  = ltrim( (string) $req->get_param( 'path' ), '/' ); // relativ zum Basis-Ordner
	if ( ! $file || ! isset( $file['tmp_name'] ) || '' === $dest ) {
		return new WP_Error( 'bad_upload', 'Datei oder Zielpfad fehlt.', array( 'status' => 400 ) );
	}
	$res = jb_nc()->upload_beleg( $file['tmp_name'], $dest );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	return rest_ensure_response( array( 'path' => $res ) );
}

/* =========================================================================
 * Aktions-Endpunkte – rufen die vorhandene Plugin-Logik auf.
 * (Nur online sinnvoll: legen Benutzer an, verschicken Mails, buchen ins Journal.)
 * ====================================================================== */

/** POST /actions/antrag-decide  { id, action:"annehmen"|"ablehnen", notiz } */
function vp_sync_action_antrag_decide( WP_REST_Request $req ) {
	if ( ! function_exists( 'vp_antrag_decide' ) ) {
		return new WP_Error( 'no_fn', 'Antrags-Modul nicht geladen.', array( 'status' => 400 ) );
	}
	$b      = (array) $req->get_json_params();
	$id     = (int) ( $b['id'] ?? 0 );
	$action = in_array( ( $b['action'] ?? '' ), array( 'annehmen', 'ablehnen' ), true ) ? $b['action'] : '';
	$notiz  = sanitize_textarea_field( (string) ( $b['notiz'] ?? '' ) );
	if ( ! $id || ! $action ) {
		return new WP_Error( 'bad_req', 'id und action (annehmen|ablehnen) nötig.', array( 'status' => 400 ) );
	}
	$msg = vp_antrag_decide( $id, $action, $notiz );
	return rest_ensure_response( array( 'ok' => true, 'message' => (string) $msg ) );
}

/** POST /actions/auslage-decide  { id, approve:bool, notiz } */
function vp_sync_action_auslage_decide( WP_REST_Request $req ) {
	if ( ! function_exists( 'jb_approve_auslage' ) ) {
		return new WP_Error( 'no_fn', 'Buchhaltungs-Modul nicht geladen.', array( 'status' => 400 ) );
	}
	$b       = (array) $req->get_json_params();
	$id      = (int) ( $b['id'] ?? 0 );
	$approve = ! empty( $b['approve'] );
	$notiz   = sanitize_textarea_field( (string) ( $b['notiz'] ?? '' ) );
	if ( ! $id ) {
		return new WP_Error( 'bad_req', 'id nötig.', array( 'status' => 400 ) );
	}
	$ok = jb_approve_auslage( $id, $approve, $notiz );
	if ( ! $ok ) {
		return new WP_Error( 'failed', 'Entscheidung nicht möglich (Recht, Status oder ID prüfen).', array( 'status' => 400 ) );
	}
	// Genehmigte Auslage direkt ins Journal übernehmen (wie im Frontend).
	$buchung_id = 0;
	if ( $approve && function_exists( 'jb_auslage_to_journal' ) ) {
		$buchung_id = (int) jb_auslage_to_journal( $id );
	}
	return rest_ensure_response( array( 'ok' => true, 'approved' => $approve, 'buchung_id' => $buchung_id ) );
}

/** POST /actions/auslage-auszahlen  { id } */
function vp_sync_action_auslage_pay( WP_REST_Request $req ) {
	if ( ! function_exists( 'jb_mark_paid' ) ) {
		return new WP_Error( 'no_fn', 'Buchhaltungs-Modul nicht geladen.', array( 'status' => 400 ) );
	}
	$id = (int) ( ( (array) $req->get_json_params() )['id'] ?? 0 );
	if ( ! $id ) {
		return new WP_Error( 'bad_req', 'id nötig.', array( 'status' => 400 ) );
	}
	$r = jb_mark_paid( $id );
	if ( is_wp_error( $r ) ) {
		return $r;
	}
	return rest_ensure_response( array( 'ok' => (bool) $r ) );
}

/** POST /actions/auslage-einreichen  (multipart: Felder + optional file) */
function vp_sync_action_auslage_submit( WP_REST_Request $req ) {
	if ( ! function_exists( 'jb_submit_auslage' ) ) {
		return new WP_Error( 'no_fn', 'Buchhaltungs-Modul nicht geladen.', array( 'status' => 400 ) );
	}
	$p    = $req->get_params();
	$data = array(
		'ausgabe_datum' => sanitize_text_field( (string) ( $p['ausgabe_datum'] ?? '' ) ),
		'betrag'        => (string) ( $p['betrag'] ?? '0' ),
		'kategorie'     => sanitize_text_field( (string) ( $p['kategorie'] ?? 'Sonstige Ausgaben' ) ),
		'beschreibung'  => sanitize_textarea_field( (string) ( $p['beschreibung'] ?? '' ) ),
		'konto'         => sanitize_text_field( (string) ( $p['konto'] ?? '' ) ),
		'modus'         => ( ( $p['modus'] ?? 'erstattung' ) === 'beleg' || ! empty( $p['nur_beleg'] ) ) ? 'beleg' : 'erstattung',
		'budget_id'     => ! empty( $p['budget_id'] ) ? (int) $p['budget_id'] : 0,
	);
	$files = $req->get_file_params();
	$file  = $files['file'] ?? array();
	$res   = jb_submit_auslage( $data, is_array( $file ) ? $file : array() );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	return rest_ensure_response( array( 'ok' => true, 'id' => (int) $res ) );
}

/** POST /actions/journal-add  { buchung_datum, betrag, kategorie, beschreibung, konto, sphaere, quelle, gegenpartei, beleg_nr } */
function vp_sync_action_journal_add( WP_REST_Request $req ) {
	if ( ! function_exists( 'jb_journal_add' ) ) {
		return new WP_Error( 'no_fn', 'Buchhaltungs-Modul nicht geladen.', array( 'status' => 400 ) );
	}
	$b = (array) $req->get_json_params();
	if ( ! isset( $b['betrag'] ) || '' === (string) $b['betrag'] ) {
		return new WP_Error( 'bad_req', 'betrag nötig.', array( 'status' => 400 ) );
	}
	$b['betrag'] = (float) str_replace( ',', '.', (string) $b['betrag'] );
	$id = (int) jb_journal_add( $b );
	return rest_ensure_response( array( 'ok' => true, 'id' => $id ) );
}

/** POST /actions/bank-csv  { csv, delim, import:bool, rows?:[…] }
 *  Ohne import → nur Vorschau (geparste Zeilen mit Konto-Vorschlag).
 *  Mit import  → die mitgegebenen rows werden als Buchungen angelegt.
 */
function vp_sync_action_bank_csv( WP_REST_Request $req ) {
	if ( ! function_exists( 'vp_bh_parse_bank_csv' ) ) {
		return new WP_Error( 'no_fn', 'SKR-Buchhaltung nicht geladen.', array( 'status' => 400 ) );
	}
	$b     = (array) $req->get_json_params();
	$delim = ( $b['delim'] ?? ';' ) === ',' ? ',' : ';';

	if ( empty( $b['import'] ) ) {
		$rows = vp_bh_parse_bank_csv( (string) ( $b['csv'] ?? '' ), $delim );
		return rest_ensure_response( array( 'ok' => true, 'rows' => $rows ) );
	}

	$rows = is_array( $b['rows'] ?? null ) ? $b['rows'] : array();
	$made = 0;
	foreach ( $rows as $r ) {
		$betrag = (float) str_replace( ',', '.', (string) ( $r['betrag'] ?? 0 ) );
		$datum  = sanitize_text_field( (string) ( $r['datum'] ?? '' ) );
		if ( ! $datum || 0.0 === $betrag ) {
			continue;
		}
		$konto   = sanitize_text_field( (string) ( $r['konto'] ?? '' ) );
		$sphaere = ( $konto && function_exists( 'jb_konto_sphaere' ) ) ? jb_konto_sphaere( $konto ) : '';
		jb_journal_add( array(
			'buchung_datum' => $datum,
			'betrag'        => $betrag,
			'kategorie'     => sanitize_text_field( (string) ( $r['kategorie'] ?? ( $r['zweck'] ?? 'Bank' ) ) ),
			'beschreibung'  => sanitize_textarea_field( trim( (string) ( $r['name'] ?? '' ) . ' — ' . (string) ( $r['zweck'] ?? '' ), ' —' ) ),
			'quelle'        => 'Bank KSK',
			'konto'         => $konto,
			'sphaere'       => $sphaere,
			'gegenpartei'   => sanitize_text_field( (string) ( $r['name'] ?? '' ) ),
		) );
		$made++;
	}
	return rest_ensure_response( array( 'ok' => true, 'imported' => $made ) );
}

/* =========================================================================
 * GET /report/summary?year=  – Kassenbericht / EÜR-Zahlen
 * ====================================================================== */

function vp_sync_report_summary( WP_REST_Request $req ) {
	global $wpdb;
	$t = $wpdb->prefix . 'jb_buchungen';
	if ( ! vp_sync_columns( 'jb_buchungen' ) ) {
		return new WP_Error( 'no_table', 'Buchungsjournal fehlt.', array( 'status' => 400 ) );
	}
	$year = (int) $req->get_param( 'year' );
	if ( ! $year ) {
		$year = (int) current_time( 'Y' );
	}
	$has_skr = in_array( 'konto', vp_sync_columns( 'jb_buchungen' ), true );

	// Verfügbare Jahre.
	$years = array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT YEAR(buchung_datum) y FROM `$t` ORDER BY y DESC" ) );

	// Gesamt Einnahmen / Ausgaben / Überschuss im Jahr.
	$ein = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(betrag),0) FROM `$t` WHERE betrag > 0 AND YEAR(buchung_datum) = %d", $year ) );
	$aus = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(betrag),0) FROM `$t` WHERE betrag < 0 AND YEAR(buchung_datum) = %d", $year ) );

	// Nach Sphäre (nur wenn SKR-Spalten da).
	$by_sphaere = array();
	if ( $has_skr ) {
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT COALESCE(NULLIF(sphaere,''),'—') s,
			        COALESCE(SUM(CASE WHEN betrag>0 THEN betrag ELSE 0 END),0) ein,
			        COALESCE(SUM(CASE WHEN betrag<0 THEN -betrag ELSE 0 END),0) aus
			 FROM `$t` WHERE YEAR(buchung_datum) = %d GROUP BY s ORDER BY s", $year
		), ARRAY_A );
		$labels = function_exists( 'vp_skr_sphaeren' ) ? vp_skr_sphaeren() : array();
		foreach ( (array) $rows as $r ) {
			$by_sphaere[] = array(
				'sphaere' => $r['s'],
				'label'   => $labels[ $r['s'] ] ?? $r['s'],
				'einnahmen' => (float) $r['ein'],
				'ausgaben'  => (float) $r['aus'],
				'saldo'     => (float) $r['ein'] - (float) $r['aus'],
			);
		}
	}

	// Nach Konto.
	$by_konto = array();
	$konto_expr = $has_skr ? "COALESCE(NULLIF(konto,''),'—')" : "'—'";
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT $konto_expr k, kategorie,
		        COALESCE(SUM(CASE WHEN betrag>0 THEN betrag ELSE 0 END),0) ein,
		        COALESCE(SUM(CASE WHEN betrag<0 THEN -betrag ELSE 0 END),0) aus,
		        COUNT(*) n
		 FROM `$t` WHERE YEAR(buchung_datum) = %d
		 GROUP BY k, kategorie ORDER BY (ein+aus) DESC", $year
	), ARRAY_A );
	$knames = array();
	if ( $has_skr && function_exists( 'jb_konten_all' ) ) {
		foreach ( (array) jb_konten_all( false ) as $kk ) {
			$knames[ (string) $kk->nummer ] = $kk->bezeichnung;
		}
	}
	foreach ( (array) $rows as $r ) {
		$by_konto[] = array(
			'konto'     => $r['k'],
			'name'      => $knames[ $r['k'] ] ?? '',
			'kategorie' => $r['kategorie'],
			'einnahmen' => (float) $r['ein'],
			'ausgaben'  => (float) $r['aus'],
			'anzahl'    => (int) $r['n'],
		);
	}

	// Umsatz je Konto über ALLE Jahre (für den Kontenplan).
	$by_konto_all = array();
	$rows = $wpdb->get_results(
		"SELECT $konto_expr k,
		        COALESCE(SUM(CASE WHEN betrag>0 THEN betrag ELSE 0 END),0) ein,
		        COALESCE(SUM(CASE WHEN betrag<0 THEN -betrag ELSE 0 END),0) aus,
		        COUNT(*) n
		 FROM `$t` GROUP BY k", ARRAY_A
	);
	foreach ( (array) $rows as $r ) {
		$by_konto_all[ (string) $r['k'] ] = array(
			'einnahmen' => (float) $r['ein'],
			'ausgaben'  => (float) $r['aus'],
			'anzahl'    => (int) $r['n'],
		);
	}

	// Geld-Töpfe: Anfangsstand (Option) + kumulierte Journalbuchungen je quelle.
	$pot = function ( $quelle ) use ( $wpdb, $t ) {
		return (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(betrag),0) FROM `$t` WHERE quelle = %s", $quelle ) );
	};
	$bank  = (float) get_option( 'jb_kontostand_bank', 0 );
	$kasse = (float) get_option( 'jb_kontostand_kasse', 0 );
	$journal_total = (float) $wpdb->get_var( "SELECT COALESCE(SUM(betrag),0) FROM `$t`" );

	$topfe = array(
		array( 'key' => 'bank',   'label' => 'Bankkonto (KSK)',  'saldo' => round( $bank + $pot( 'Bank KSK' ), 2 ) ),
		array( 'key' => 'kasse',  'label' => 'Barkasse',          'saldo' => round( $kasse + $pot( 'Zettle-Bar' ) + $pot( 'Bar' ), 2 ) ),
		array( 'key' => 'paypal', 'label' => 'PayPal',            'saldo' => round( $pot( 'PayPal' ), 2 ) ),
		array( 'key' => 'zettle', 'label' => 'Zettle (Karte)',    'saldo' => round( $pot( 'Zettle-Karte' ), 2 ) ),
	);

	// Kassenbericht-Kennzahlen aus dem Buchhaltungs-Dashboard (falls geladen).
	$dashboard = null;
	if ( function_exists( 'jb_get_dashboard_data' ) ) {
		$d = jb_get_dashboard_data();
		$dashboard = array(
			'bank'            => round( (float) ( $d['bank'] ?? 0 ), 2 ),
			'kasse'           => round( (float) ( $d['kasse'] ?? 0 ), 2 ),
			'kontostand'      => round( (float) ( $d['kontostand'] ?? 0 ), 2 ),
			'getraenke_wert'  => round( (float) ( $d['getraenke_wert'] ?? 0 ), 2 ),
			'offene_auslagen' => round( (float) ( $d['offene_auslagen'] ?? 0 ), 2 ),
			'ruecklagen'      => round( (float) ( $d['ruecklagen'] ?? 0 ), 2 ),
			'verplantes'      => round( (float) ( $d['verplantes'] ?? 0 ), 2 ),
			'frei'            => round( (float) ( $d['frei'] ?? 0 ), 2 ),
		);
	}

	return rest_ensure_response( array(
		'year'            => $year,
		'years'           => $years ?: array( $year ),
		'has_skr'         => $has_skr,
		'total_einnahmen' => round( $ein, 2 ),
		'total_ausgaben'  => round( -$aus, 2 ),
		'ueberschuss'     => round( $ein + $aus, 2 ),
		'by_sphaere'      => $by_sphaere,
		'by_konto'        => $by_konto,
		'by_konto_all'    => $by_konto_all,
		'topfe'           => $topfe,
		'dashboard'       => $dashboard,
		'bestand'         => array(
			'bank_option'   => round( $bank, 2 ),
			'kasse_option'  => round( $kasse, 2 ),
			'journal_saldo' => round( $journal_total, 2 ),
		),
	) );
}

/* =========================================================================
 * Aktions-Endpunkte für die editierbaren Bereiche (Wünsche, Abstimmung,
 * Schichten, Protokolle, Newsletter). Schreiben direkt über $wpdb – die alten
 * wp_ajax_*-Handler sind an Nonce/Session gebunden und nicht wiederverwendbar.
 * ====================================================================== */

function vp_sync_json( WP_REST_Request $req ) {
	$b = $req->get_json_params();
	return is_array( $b ) ? $b : array();
}

/** POST /actions/newsletter-send */
function vp_sync_action_newsletter_send( WP_REST_Request $req ) {
	if ( ! function_exists( 'vp_newsletter_send' ) ) {
		return new WP_Error( 'no_fn', 'Newsletter-Modul nicht geladen.', array( 'status' => 400 ) );
	}
	$b   = vp_sync_json( $req );
	$res = vp_newsletter_send( $b['betreff'] ?? '', $b['body'] ?? '', $b['rolle'] ?? 'wl_mitglied', ! empty( $b['test'] ) );
	if ( empty( $res['ok'] ) ) {
		return new WP_Error( 'nl_failed', $res['message'] ?? 'Versand fehlgeschlagen.', array( 'status' => 400 ) );
	}
	return rest_ensure_response( $res );
}

/** POST /actions/wunsch-save */
function vp_sync_action_wunsch_save( WP_REST_Request $req ) {
	global $wpdb;
	$t = $wpdb->prefix . 'wunschliste';
	if ( ! vp_sync_columns( 'wunschliste' ) ) {
		return new WP_Error( 'no_table', 'Wunschliste fehlt.', array( 'status' => 400 ) );
	}
	$b     = vp_sync_json( $req );
	$titel = sanitize_text_field( (string) ( $b['titel'] ?? '' ) );
	if ( '' === $titel ) {
		return new WP_Error( 'bad_req', 'Titel ist Pflicht.', array( 'status' => 400 ) );
	}
	$fnum = static function ( $v ) {
		return ( null === $v || '' === $v ) ? null : (float) str_replace( ',', '.', (string) $v );
	};
	$data = array(
		'titel'        => $titel,
		'beschreibung' => sanitize_textarea_field( (string) ( $b['beschreibung'] ?? '' ) ),
		'begruendung'  => sanitize_textarea_field( (string) ( $b['begruendung'] ?? '' ) ),
		'betrag'       => (float) $fnum( $b['betrag'] ?? 0 ),
		'preis_von'    => $fnum( $b['preis_von'] ?? null ),
		'preis_bis'    => $fnum( $b['preis_bis'] ?? null ),
		'kategorie'    => sanitize_text_field( (string) ( $b['kategorie'] ?? '' ) ),
		'status'       => in_array( ( $b['status'] ?? 'offen' ), array( 'offen', 'in_bearbeitung', 'erfuellt' ), true ) ? $b['status'] : 'offen',
		'prioritaet'   => max( 1, min( 3, (int) ( $b['prioritaet'] ?? 2 ) ) ),
		'bild_url'     => esc_url_raw( (string) ( $b['bild_url'] ?? '' ) ),
	);
	if ( in_array( 'gremium_id', vp_sync_columns( 'wunschliste' ), true ) ) {
		$data['gremium_id'] = ! empty( $b['gremium_id'] ) ? (int) $b['gremium_id'] : null;
	}
	if ( null !== $data['preis_von'] || null !== $data['preis_bis'] ) {
		$data['betrag'] = 0;
	}
	$id = (int) ( $b['id'] ?? 0 );
	if ( $id > 0 ) {
		$wpdb->update( $t, $data, array( 'id' => $id ) );
	} else {
		$data['erstellt_von'] = get_current_user_id();
		$wpdb->insert( $t, $data );
		$id = (int) $wpdb->insert_id;
	}
	return rest_ensure_response( array( 'ok' => true, 'id' => $id ) );
}

/** POST /actions/wunsch-delete */
function vp_sync_action_wunsch_delete( WP_REST_Request $req ) {
	global $wpdb;
	$id = (int) ( vp_sync_json( $req )['id'] ?? 0 );
	if ( ! $id ) {
		return new WP_Error( 'bad_req', 'id nötig.', array( 'status' => 400 ) );
	}
	$wpdb->delete( $wpdb->prefix . 'wunschliste', array( 'id' => $id ) );
	$wpdb->delete( $wpdb->prefix . 'wl_votes', array( 'wunsch_id' => $id ) );
	return rest_ensure_response( array( 'ok' => true ) );
}

/** POST /actions/vote-cast  { wunsch_id, stufe 1-5, begruendung } */
function vp_sync_action_vote_cast( WP_REST_Request $req ) {
	global $wpdb;
	$t = $wpdb->prefix . 'wl_votes';
	if ( ! vp_sync_columns( 'wl_votes' ) ) {
		return new WP_Error( 'no_table', 'Abstimmungstabelle fehlt.', array( 'status' => 400 ) );
	}
	$b      = vp_sync_json( $req );
	$wunsch = (int) ( $b['wunsch_id'] ?? 0 );
	$stufe  = (int) ( $b['stufe'] ?? 0 );
	$begr   = sanitize_textarea_field( (string) ( $b['begruendung'] ?? '' ) );
	if ( ! $wunsch || $stufe < 1 || $stufe > 5 ) {
		return new WP_Error( 'bad_req', 'wunsch_id und stufe (1–5) nötig.', array( 'status' => 400 ) );
	}
	if ( 5 === $stufe && '' === trim( $begr ) ) {
		return new WP_Error( 'veto_grund', 'Ein Veto braucht eine Begründung.', array( 'status' => 400 ) );
	}
	$uid = get_current_user_id();
	$key = 'u' . $uid;
	$row = array(
		'wunsch_id'   => $wunsch,
		'voter_key'   => $key,
		'voter_name'  => wp_get_current_user()->display_name,
		'voter_type'  => 'mitglied',
		'stufe'       => $stufe,
		'begruendung' => $begr,
	);
	$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `$t` WHERE wunsch_id = %d AND voter_key = %s", $wunsch, $key ) );
	if ( $exists ) {
		$wpdb->update( $t, $row, array( 'id' => $exists ) );
	} else {
		$wpdb->insert( $t, $row );
	}
	// Wunsch-Vetostatus grob nachziehen (wie im Modul).
	if ( in_array( 'vote_status', vp_sync_columns( 'wunschliste' ), true ) ) {
		$veto = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$t` WHERE wunsch_id = %d AND stufe = 5", $wunsch ) );
		$wpdb->update( $wpdb->prefix . 'wunschliste', array( 'vote_status' => $veto ? 'veto' : 'aktiv' ), array( 'id' => $wunsch ) );
	}
	return rest_ensure_response( array( 'ok' => true ) );
}

/** POST /actions/vote-retract  { wunsch_id } */
function vp_sync_action_vote_retract( WP_REST_Request $req ) {
	global $wpdb;
	$wunsch = (int) ( vp_sync_json( $req )['wunsch_id'] ?? 0 );
	if ( ! $wunsch ) {
		return new WP_Error( 'bad_req', 'wunsch_id nötig.', array( 'status' => 400 ) );
	}
	$wpdb->delete( $wpdb->prefix . 'wl_votes', array( 'wunsch_id' => $wunsch, 'voter_key' => 'u' . get_current_user_id() ) );
	return rest_ensure_response( array( 'ok' => true ) );
}

/** POST /actions/schicht-eintragen  { schicht_id, name? } */
function vp_sync_action_schicht_eintragen( WP_REST_Request $req ) {
	global $wpdb;
	$te = $wpdb->prefix . 'wl_shift_eintragungen';
	$ts = $wpdb->prefix . 'wl_shift_schichten';
	if ( ! vp_sync_columns( 'wl_shift_eintragungen' ) ) {
		return new WP_Error( 'no_table', 'Schicht-Tabellen fehlen.', array( 'status' => 400 ) );
	}
	$b   = vp_sync_json( $req );
	$sid = (int) ( $b['schicht_id'] ?? 0 );
	if ( ! $sid ) {
		return new WP_Error( 'bad_req', 'schicht_id nötig.', array( 'status' => 400 ) );
	}
	$uid = get_current_user_id();
	$me  = wp_get_current_user();
	// Schon eingetragen?
	if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `$te` WHERE schicht_id = %d AND user_id = %d", $sid, $uid ) ) ) {
		return rest_ensure_response( array( 'ok' => true, 'note' => 'bereits eingetragen' ) );
	}
	$max   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT max_plaetze FROM `$ts` WHERE id = %d", $sid ) );
	$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$te` WHERE schicht_id = %d", $sid ) );
	if ( $max > 0 && $count >= $max ) {
		return new WP_Error( 'voll', 'Diese Schicht ist voll.', array( 'status' => 409 ) );
	}
	$wpdb->insert( $te, array(
		'schicht_id'     => $sid,
		'name'           => sanitize_text_field( (string) ( $b['name'] ?? ( $me->display_name ?: $me->user_login ) ) ),
		'email'          => $me->user_email,
		'user_id'        => $uid,
		'manage_key'     => wp_generate_password( 20, false ),
		'eingetragen_am' => current_time( 'mysql' ),
	) );
	return rest_ensure_response( array( 'ok' => true, 'id' => (int) $wpdb->insert_id ) );
}

/** POST /actions/schicht-austragen  { eintrag_id } */
function vp_sync_action_schicht_austragen( WP_REST_Request $req ) {
	global $wpdb;
	$te  = $wpdb->prefix . 'wl_shift_eintragungen';
	$eid = (int) ( vp_sync_json( $req )['eintrag_id'] ?? 0 );
	if ( ! $eid ) {
		return new WP_Error( 'bad_req', 'eintrag_id nötig.', array( 'status' => 400 ) );
	}
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$te` WHERE id = %d", $eid ) );
	if ( ! $row ) {
		return rest_ensure_response( array( 'ok' => true ) );
	}
	if ( (int) $row->user_id !== get_current_user_id() && ! current_user_can( 'wl_manage_wishes' ) && ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'forbidden', 'Nur die eigene Eintragung ist entfernbar.', array( 'status' => 403 ) );
	}
	$wpdb->delete( $te, array( 'id' => $eid ) );
	return rest_ensure_response( array( 'ok' => true ) );
}

/** POST /actions/protokoll-save  { id?, gremium_id, titel, datum, ort, status } */
function vp_sync_action_protokoll_save( WP_REST_Request $req ) {
	global $wpdb;
	$t = $wpdb->prefix . 'pp_protokolle';
	if ( ! vp_sync_columns( 'pp_protokolle' ) ) {
		return new WP_Error( 'no_table', 'Protokoll-Tabelle fehlt.', array( 'status' => 400 ) );
	}
	$b    = vp_sync_json( $req );
	$data = array(
		'gremium_id' => (int) ( $b['gremium_id'] ?? 0 ),
		'titel'      => sanitize_text_field( (string) ( $b['titel'] ?? '' ) ),
		'datum'      => sanitize_text_field( (string) ( $b['datum'] ?? '' ) ) ?: null,
		'ort'        => sanitize_text_field( (string) ( $b['ort'] ?? '' ) ),
		'status'     => in_array( ( $b['status'] ?? 'entwurf' ), array( 'entwurf', 'abgeschlossen' ), true ) ? $b['status'] : 'entwurf',
	);
	if ( ! $data['gremium_id'] || '' === $data['titel'] ) {
		return new WP_Error( 'bad_req', 'gremium_id und titel nötig.', array( 'status' => 400 ) );
	}
	$id = (int) ( $b['id'] ?? 0 );
	if ( $id > 0 ) {
		$wpdb->update( $t, $data, array( 'id' => $id ) );
	} else {
		$data['erstellt_von'] = get_current_user_id();
		$wpdb->insert( $t, $data );
		$id = (int) $wpdb->insert_id;
	}
	return rest_ensure_response( array( 'ok' => true, 'id' => $id ) );
}

/** POST /actions/top-save  { id?, protokoll_id, titel, beschreibung, beschluss, konsent_status, sortierung } */
function vp_sync_action_top_save( WP_REST_Request $req ) {
	global $wpdb;
	$t = $wpdb->prefix . 'pp_tops';
	if ( ! vp_sync_columns( 'pp_tops' ) ) {
		return new WP_Error( 'no_table', 'TOP-Tabelle fehlt.', array( 'status' => 400 ) );
	}
	$b        = vp_sync_json( $req );
	$statuses = array( 'vorstellung', 'verstaendnisfragen', 'meinungsrunde', 'konsentrunde', 'einwand_offen', 'beschlossen' );
	$data     = array(
		'protokoll_id'   => (int) ( $b['protokoll_id'] ?? 0 ),
		'titel'          => sanitize_text_field( (string) ( $b['titel'] ?? '' ) ),
		'beschreibung'   => sanitize_textarea_field( (string) ( $b['beschreibung'] ?? '' ) ),
		'beschluss'      => sanitize_textarea_field( (string) ( $b['beschluss'] ?? '' ) ),
		'konsent_status' => in_array( ( $b['konsent_status'] ?? 'vorstellung' ), $statuses, true ) ? $b['konsent_status'] : 'vorstellung',
		'sortierung'     => (int) ( $b['sortierung'] ?? 0 ),
	);
	if ( ! $data['protokoll_id'] || '' === $data['titel'] ) {
		return new WP_Error( 'bad_req', 'protokoll_id und titel nötig.', array( 'status' => 400 ) );
	}
	$id = (int) ( $b['id'] ?? 0 );
	if ( $id > 0 ) {
		$wpdb->update( $t, $data, array( 'id' => $id ) );
	} else {
		$wpdb->insert( $t, $data );
		$id = (int) $wpdb->insert_id;
	}
	return rest_ensure_response( array( 'ok' => true, 'id' => $id ) );
}

/** POST /actions/top-delete  { id } */
function vp_sync_action_top_delete( WP_REST_Request $req ) {
	global $wpdb;
	$id = (int) ( vp_sync_json( $req )['id'] ?? 0 );
	if ( ! $id ) {
		return new WP_Error( 'bad_req', 'id nötig.', array( 'status' => 400 ) );
	}
	$wpdb->delete( $wpdb->prefix . 'pp_tops', array( 'id' => $id ) );
	return rest_ensure_response( array( 'ok' => true ) );
}

/** POST /actions/aufgabe-save  { id?, titel, beschreibung, verantwortlich_user_id, verantwortliches_gremium_id, faelligkeitsdatum, status } */
function vp_sync_action_aufgabe_save( WP_REST_Request $req ) {
	global $wpdb;
	$t = $wpdb->prefix . 'pp_aufgaben';
	if ( ! vp_sync_columns( 'pp_aufgaben' ) ) {
		return new WP_Error( 'no_table', 'Aufgaben-Tabelle fehlt.', array( 'status' => 400 ) );
	}
	$b    = vp_sync_json( $req );
	$id   = (int) ( $b['id'] ?? 0 );

	// Nur Status umschalten?
	if ( $id > 0 && isset( $b['status'] ) && count( $b ) <= 2 ) {
		$st = in_array( $b['status'], array( 'offen', 'erledigt' ), true ) ? $b['status'] : 'offen';
		$wpdb->update( $t, array( 'status' => $st ), array( 'id' => $id ) );
		return rest_ensure_response( array( 'ok' => true, 'id' => $id, 'status' => $st ) );
	}

	$data = array(
		'titel'                       => sanitize_text_field( (string) ( $b['titel'] ?? '' ) ),
		'beschreibung'                => sanitize_textarea_field( (string) ( $b['beschreibung'] ?? '' ) ),
		'verantwortlich_user_id'      => ! empty( $b['verantwortlich_user_id'] ) ? (int) $b['verantwortlich_user_id'] : null,
		'verantwortliches_gremium_id' => ! empty( $b['verantwortliches_gremium_id'] ) ? (int) $b['verantwortliches_gremium_id'] : null,
		'faelligkeitsdatum'           => sanitize_text_field( (string) ( $b['faelligkeitsdatum'] ?? '' ) ) ?: null,
		'status'                      => in_array( ( $b['status'] ?? 'offen' ), array( 'offen', 'erledigt' ), true ) ? $b['status'] : 'offen',
	);
	if ( '' === $data['titel'] ) {
		return new WP_Error( 'bad_req', 'Titel nötig.', array( 'status' => 400 ) );
	}
	if ( $id > 0 ) {
		$wpdb->update( $t, $data, array( 'id' => $id ) );
	} else {
		$wpdb->insert( $t, $data );
		$id = (int) $wpdb->insert_id;
	}
	return rest_ensure_response( array( 'ok' => true, 'id' => $id ) );
}

/** POST /actions/thema-save  { id?, titel, beschreibung, status, gremium_id } */
function vp_sync_action_thema_save( WP_REST_Request $req ) {
	global $wpdb;
	$t = $wpdb->prefix . 'pp_themen';
	if ( ! vp_sync_columns( 'pp_themen' ) ) {
		return new WP_Error( 'no_table', 'Themen-Tabelle fehlt.', array( 'status' => 400 ) );
	}
	$b     = vp_sync_json( $req );
	$stat  = array( 'vorbereitet', 'in_bearbeitung', 'abgeschlossen', 'evaluationsreif' );
	$data  = array(
		'titel'        => sanitize_text_field( (string) ( $b['titel'] ?? '' ) ),
		'beschreibung' => sanitize_textarea_field( (string) ( $b['beschreibung'] ?? '' ) ),
		'status'       => in_array( ( $b['status'] ?? 'vorbereitet' ), $stat, true ) ? $b['status'] : 'vorbereitet',
		'gremium_id'   => ! empty( $b['gremium_id'] ) ? (int) $b['gremium_id'] : null,
	);
	if ( '' === $data['titel'] ) {
		return new WP_Error( 'bad_req', 'Titel nötig.', array( 'status' => 400 ) );
	}
	$id = (int) ( $b['id'] ?? 0 );
	if ( $id > 0 ) {
		$wpdb->update( $t, $data, array( 'id' => $id ) );
	} else {
		$data['erstellt_von'] = get_current_user_id();
		$wpdb->insert( $t, $data );
		$id = (int) $wpdb->insert_id;
	}
	return rest_ensure_response( array( 'ok' => true, 'id' => $id ) );
}

/* =========================================================================
 * Kreise & Rollen (Protokoll-Modul)
 * ====================================================================== */

/** POST /actions/gremium-save */
function vp_sync_action_gremium_save( WP_REST_Request $req ) {
	global $wpdb;
	$t = $wpdb->prefix . 'pp_gremien';
	if ( ! vp_sync_columns( 'pp_gremien' ) ) {
		return new WP_Error( 'no_table', 'Gremien-Tabelle fehlt.', array( 'status' => 400 ) );
	}
	$b     = vp_sync_json( $req );
	$typen = array( 'mv', 'vorstand', 'leitungskreis', 'kreis', 'kreisversammlung' );
	$data  = array(
		'typ'                  => in_array( ( $b['typ'] ?? 'kreis' ), $typen, true ) ? $b['typ'] : 'kreis',
		'name'                 => sanitize_text_field( (string) ( $b['name'] ?? '' ) ),
		'parent_gremium_id'    => ! empty( $b['parent_gremium_id'] ) ? (int) $b['parent_gremium_id'] : null,
		'oeffentlichkeit'      => in_array( ( $b['oeffentlichkeit'] ?? 'vereinsintern' ), array( 'oeffentlich', 'vereinsintern', 'nur_gremium' ), true ) ? $b['oeffentlichkeit'] : 'vereinsintern',
		'standardverfahren'    => in_array( ( $b['standardverfahren'] ?? 'konsent' ), array( 'konsent', 'mehrheit', 'geheime_wahl' ), true ) ? $b['standardverfahren'] : 'konsent',
		'einladungsfrist_tage' => max( 0, (int) ( $b['einladungsfrist_tage'] ?? 14 ) ),
		'beschreibung'         => sanitize_textarea_field( (string) ( $b['beschreibung'] ?? '' ) ),
		'aktiv'                => empty( $b['aktiv'] ) ? 0 : 1,
	);
	if ( '' === $data['name'] ) {
		return new WP_Error( 'bad_req', 'Name nötig.', array( 'status' => 400 ) );
	}
	$id = (int) ( $b['id'] ?? 0 );
	if ( $id > 0 ) {
		$wpdb->update( $t, $data, array( 'id' => $id ) );
	} else {
		$data['erstellt_von'] = get_current_user_id();
		$wpdb->insert( $t, $data );
		$id = (int) $wpdb->insert_id;
	}
	return rest_ensure_response( array( 'ok' => true, 'id' => $id ) );
}

/** POST /actions/gremium-delete { id } – inkl. Mitglieder & Rollen dieses Gremiums */
function vp_sync_action_gremium_delete( WP_REST_Request $req ) {
	global $wpdb;
	$id = (int) ( vp_sync_json( $req )['id'] ?? 0 );
	if ( ! $id ) {
		return new WP_Error( 'bad_req', 'id nötig.', array( 'status' => 400 ) );
	}
	$wpdb->delete( $wpdb->prefix . 'pp_gremien', array( 'id' => $id ) );
	$wpdb->delete( $wpdb->prefix . 'pp_kreis_mitglieder', array( 'gremium_id' => $id ) );
	$wpdb->delete( $wpdb->prefix . 'pp_rollen', array( 'gremium_id' => $id ) );
	return rest_ensure_response( array( 'ok' => true ) );
}

/** POST /actions/kreis-mitglied  { op:"add"|"remove", gremium_id, user_id, id?, beigetreten_am? } */
function vp_sync_action_kreis_mitglied( WP_REST_Request $req ) {
	global $wpdb;
	$t = $wpdb->prefix . 'pp_kreis_mitglieder';
	if ( ! vp_sync_columns( 'pp_kreis_mitglieder' ) ) {
		return new WP_Error( 'no_table', 'Kreis-Mitglieder-Tabelle fehlt.', array( 'status' => 400 ) );
	}
	$b = vp_sync_json( $req );
	if ( 'remove' === ( $b['op'] ?? '' ) ) {
		$id = (int) ( $b['id'] ?? 0 );
		if ( ! $id ) {
			return new WP_Error( 'bad_req', 'id nötig.', array( 'status' => 400 ) );
		}
		$wpdb->delete( $t, array( 'id' => $id ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}
	$g = (int) ( $b['gremium_id'] ?? 0 );
	$u = (int) ( $b['user_id'] ?? 0 );
	if ( ! $g || ! $u ) {
		return new WP_Error( 'bad_req', 'gremium_id und user_id nötig.', array( 'status' => 400 ) );
	}
	if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `$t` WHERE gremium_id = %d AND user_id = %d AND ausgetreten_am IS NULL", $g, $u ) ) ) {
		return rest_ensure_response( array( 'ok' => true, 'note' => 'bereits Mitglied' ) );
	}
	$wpdb->insert( $t, array(
		'gremium_id'     => $g,
		'user_id'        => $u,
		'beigetreten_am' => sanitize_text_field( (string) ( $b['beigetreten_am'] ?? current_time( 'Y-m-d' ) ) ),
	) );
	return rest_ensure_response( array( 'ok' => true, 'id' => (int) $wpdb->insert_id ) );
}

/** POST /actions/rolle-save */
function vp_sync_action_rolle_save( WP_REST_Request $req ) {
	global $wpdb;
	$t = $wpdb->prefix . 'pp_rollen';
	if ( ! vp_sync_columns( 'pp_rollen' ) ) {
		return new WP_Error( 'no_table', 'Rollen-Tabelle fehlt.', array( 'status' => 400 ) );
	}
	$b    = vp_sync_json( $req );
	$data = array(
		'gremium_id'            => (int) ( $b['gremium_id'] ?? 0 ),
		'rollenvorlage_id'      => ! empty( $b['rollenvorlage_id'] ) ? (int) $b['rollenvorlage_id'] : null,
		'bezeichnung'           => sanitize_text_field( (string) ( $b['bezeichnung'] ?? '' ) ),
		'user_id'               => ! empty( $b['user_id'] ) ? (int) $b['user_id'] : null,
		'vertretungsberechtigt' => empty( $b['vertretungsberechtigt'] ) ? 0 : 1,
		'amtszeit_start'        => sanitize_text_field( (string) ( $b['amtszeit_start'] ?? '' ) ) ?: null,
		'amtszeit_ende'         => sanitize_text_field( (string) ( $b['amtszeit_ende'] ?? '' ) ) ?: null,
		'wahl_gruppe'           => sanitize_text_field( (string) ( $b['wahl_gruppe'] ?? '' ) ),
	);
	if ( ! $data['gremium_id'] || '' === $data['bezeichnung'] ) {
		return new WP_Error( 'bad_req', 'gremium_id und bezeichnung nötig.', array( 'status' => 400 ) );
	}
	$id = (int) ( $b['id'] ?? 0 );
	if ( $id > 0 ) {
		$wpdb->update( $t, $data, array( 'id' => $id ) );
	} else {
		$wpdb->insert( $t, $data );
		$id = (int) $wpdb->insert_id;
	}
	return rest_ensure_response( array( 'ok' => true, 'id' => $id ) );
}

/** POST /actions/rolle-delete { id } */
function vp_sync_action_rolle_delete( WP_REST_Request $req ) {
	global $wpdb;
	$id = (int) ( vp_sync_json( $req )['id'] ?? 0 );
	if ( ! $id ) {
		return new WP_Error( 'bad_req', 'id nötig.', array( 'status' => 400 ) );
	}
	$wpdb->delete( $wpdb->prefix . 'pp_rollen', array( 'id' => $id ) );
	return rest_ensure_response( array( 'ok' => true ) );
}

/* =========================================================================
 * Schichtplan-Editor (Wunschliste-Modul)
 * ====================================================================== */

function vp_sync_shift_slug( $titel, $wpdb ) {
	$t    = $wpdb->prefix . 'wl_shift_events';
	$base = sanitize_title( $titel ) ?: ( 'event-' . time() );
	$slug = $base;
	$i    = 1;
	while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `$t` WHERE slug = %s", $slug ) ) ) {
		$slug = $base . '-' . ( ++$i );
	}
	return $slug;
}

/** POST /actions/shift-event-save */
function vp_sync_action_shift_event_save( WP_REST_Request $req ) {
	global $wpdb;
	$t = $wpdb->prefix . 'wl_shift_events';
	if ( ! vp_sync_columns( 'wl_shift_events' ) ) {
		return new WP_Error( 'no_table', 'Schicht-Tabellen fehlen.', array( 'status' => 400 ) );
	}
	$b    = vp_sync_json( $req );
	$data = array(
		'titel'               => sanitize_text_field( (string) ( $b['titel'] ?? '' ) ),
		'beschreibung'        => sanitize_textarea_field( (string) ( $b['beschreibung'] ?? '' ) ),
		'veranstaltungsdatum' => sanitize_text_field( (string) ( $b['veranstaltungsdatum'] ?? '' ) ) ?: null,
		'tagesgrenze_stunde'  => max( 0, min( 23, (int) ( $b['tagesgrenze_stunde'] ?? 0 ) ) ),
		'aktiv'               => isset( $b['aktiv'] ) && ! $b['aktiv'] ? 0 : 1,
	);
	if ( '' === $data['titel'] ) {
		return new WP_Error( 'bad_req', 'Titel nötig.', array( 'status' => 400 ) );
	}
	$id = (int) ( $b['id'] ?? 0 );
	if ( $id > 0 ) {
		$wpdb->update( $t, $data, array( 'id' => $id ) );
	} else {
		$data['slug']         = ! empty( $b['slug'] ) ? sanitize_title( $b['slug'] ) : vp_sync_shift_slug( $data['titel'], $wpdb );
		$data['erstellt_von'] = get_current_user_id();
		$wpdb->insert( $t, $data );
		$id = (int) $wpdb->insert_id;
	}
	return rest_ensure_response( array( 'ok' => true, 'id' => $id ) );
}

/** POST /actions/shift-event-delete { id } – Kaskade */
function vp_sync_action_shift_event_delete( WP_REST_Request $req ) {
	global $wpdb;
	$id = (int) ( vp_sync_json( $req )['id'] ?? 0 );
	if ( ! $id ) {
		return new WP_Error( 'bad_req', 'id nötig.', array( 'status' => 400 ) );
	}
	$st = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wl_shift_stationen WHERE event_id = %d", $id ) );
	foreach ( (array) $st as $sid ) {
		vp_sync_shift_delete_station( (int) $sid, $wpdb );
	}
	$wpdb->delete( $wpdb->prefix . 'wl_shift_events', array( 'id' => $id ) );
	return rest_ensure_response( array( 'ok' => true ) );
}

function vp_sync_shift_delete_station( $sid, $wpdb ) {
	$sch = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}wl_shift_schichten WHERE station_id = %d", $sid ) );
	foreach ( (array) $sch as $scid ) {
		$wpdb->delete( $wpdb->prefix . 'wl_shift_eintragungen', array( 'schicht_id' => (int) $scid ) );
	}
	$wpdb->delete( $wpdb->prefix . 'wl_shift_schichten', array( 'station_id' => $sid ) );
	$wpdb->delete( $wpdb->prefix . 'wl_shift_stationen', array( 'id' => $sid ) );
}

/** POST /actions/shift-station-save */
function vp_sync_action_shift_station_save( WP_REST_Request $req ) {
	global $wpdb;
	$t = $wpdb->prefix . 'wl_shift_stationen';
	if ( ! vp_sync_columns( 'wl_shift_stationen' ) ) {
		return new WP_Error( 'no_table', 'Stationen-Tabelle fehlt.', array( 'status' => 400 ) );
	}
	$b    = vp_sync_json( $req );
	$data = array(
		'event_id'                => (int) ( $b['event_id'] ?? 0 ),
		'titel'                   => sanitize_text_field( (string) ( $b['titel'] ?? '' ) ),
		'beschreibung'            => sanitize_textarea_field( (string) ( $b['beschreibung'] ?? '' ) ),
		'treffpunkt'              => sanitize_text_field( (string) ( $b['treffpunkt'] ?? '' ) ),
		'ansprechperson1'         => sanitize_text_field( (string) ( $b['ansprechperson1'] ?? '' ) ),
		'ansprechperson1_kontakt' => sanitize_text_field( (string) ( $b['ansprechperson1_kontakt'] ?? '' ) ),
		'ansprechperson2'         => sanitize_text_field( (string) ( $b['ansprechperson2'] ?? '' ) ),
		'ansprechperson2_kontakt' => sanitize_text_field( (string) ( $b['ansprechperson2_kontakt'] ?? '' ) ),
		'sortierung'              => (int) ( $b['sortierung'] ?? 0 ),
	);
	if ( ! $data['event_id'] || '' === $data['titel'] ) {
		return new WP_Error( 'bad_req', 'event_id und titel nötig.', array( 'status' => 400 ) );
	}
	$id = (int) ( $b['id'] ?? 0 );
	if ( $id > 0 ) {
		$wpdb->update( $t, $data, array( 'id' => $id ) );
	} else {
		$wpdb->insert( $t, $data );
		$id = (int) $wpdb->insert_id;
	}
	return rest_ensure_response( array( 'ok' => true, 'id' => $id ) );
}

/** POST /actions/shift-station-delete { id } */
function vp_sync_action_shift_station_delete( WP_REST_Request $req ) {
	global $wpdb;
	$id = (int) ( vp_sync_json( $req )['id'] ?? 0 );
	if ( ! $id ) {
		return new WP_Error( 'bad_req', 'id nötig.', array( 'status' => 400 ) );
	}
	vp_sync_shift_delete_station( $id, $wpdb );
	return rest_ensure_response( array( 'ok' => true ) );
}

/** POST /actions/shift-schicht-save */
function vp_sync_action_shift_schicht_save( WP_REST_Request $req ) {
	global $wpdb;
	$t = $wpdb->prefix . 'wl_shift_schichten';
	if ( ! vp_sync_columns( 'wl_shift_schichten' ) ) {
		return new WP_Error( 'no_table', 'Schichten-Tabelle fehlt.', array( 'status' => 400 ) );
	}
	$b    = vp_sync_json( $req );
	$data = array(
		'station_id'  => (int) ( $b['station_id'] ?? 0 ),
		'titel'       => sanitize_text_field( (string) ( $b['titel'] ?? '' ) ),
		'start_zeit'  => sanitize_text_field( (string) ( $b['start_zeit'] ?? '' ) ) ?: null,
		'end_zeit'    => sanitize_text_field( (string) ( $b['end_zeit'] ?? '' ) ) ?: null,
		'min_plaetze' => max( 0, (int) ( $b['min_plaetze'] ?? 0 ) ),
		'max_plaetze' => max( 1, (int) ( $b['max_plaetze'] ?? 1 ) ),
		'sortierung'  => (int) ( $b['sortierung'] ?? 0 ),
	);
	if ( ! $data['station_id'] ) {
		return new WP_Error( 'bad_req', 'station_id nötig.', array( 'status' => 400 ) );
	}
	$id = (int) ( $b['id'] ?? 0 );
	if ( $id > 0 ) {
		$wpdb->update( $t, $data, array( 'id' => $id ) );
	} else {
		$wpdb->insert( $t, $data );
		$id = (int) $wpdb->insert_id;
	}
	return rest_ensure_response( array( 'ok' => true, 'id' => $id ) );
}

/** POST /actions/shift-schicht-delete { id } */
function vp_sync_action_shift_schicht_delete( WP_REST_Request $req ) {
	global $wpdb;
	$id = (int) ( vp_sync_json( $req )['id'] ?? 0 );
	if ( ! $id ) {
		return new WP_Error( 'bad_req', 'id nötig.', array( 'status' => 400 ) );
	}
	$wpdb->delete( $wpdb->prefix . 'wl_shift_eintragungen', array( 'schicht_id' => $id ) );
	$wpdb->delete( $wpdb->prefix . 'wl_shift_schichten', array( 'id' => $id ) );
	return rest_ensure_response( array( 'ok' => true ) );
}

/* =========================================================================
 * Schichttausch
 * ====================================================================== */

/**
 * POST /actions/shift-tausch
 *   { op:"anfrage", von_eintrag_id, an_email }
 *   { op:"entscheiden", id, annehmen:bool }
 *   { op:"zuruecknehmen", id }
 */
function vp_sync_action_shift_tausch( WP_REST_Request $req ) {
	global $wpdb;
	$tt = $wpdb->prefix . 'wl_shift_tausch';
	$te = $wpdb->prefix . 'wl_shift_eintragungen';
	if ( ! vp_sync_columns( 'wl_shift_tausch' ) ) {
		return new WP_Error( 'no_table', 'Tausch-Tabelle fehlt.', array( 'status' => 400 ) );
	}
	$b   = vp_sync_json( $req );
	$op  = $b['op'] ?? 'anfrage';
	$uid = get_current_user_id();
	$me  = wp_get_current_user();

	if ( 'anfrage' === $op ) {
		$ve    = (int) ( $b['von_eintrag_id'] ?? 0 );
		$email = sanitize_email( (string) ( $b['an_email'] ?? '' ) );
		$row   = $ve ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$te` WHERE id = %d", $ve ) ) : null;
		if ( ! $row || ! $email ) {
			return new WP_Error( 'bad_req', 'von_eintrag_id und gültige an_email nötig.', array( 'status' => 400 ) );
		}
		if ( (int) $row->user_id !== $uid && ! current_user_can( 'wl_manage_wishes' ) && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'Nur die eigene Eintragung kann getauscht werden.', array( 'status' => 403 ) );
		}
		$key = wp_generate_password( 24, false );
		$wpdb->insert( $tt, array(
			'von_eintrag_id' => $ve,
			'an_email'       => strtolower( $email ),
			'tausch_key'     => $key,
			'status'         => 'offen',
			'erstellt_am'    => current_time( 'mysql' ),
		) );
		wp_mail(
			$email,
			sprintf( '[%s] Schichttausch-Anfrage', get_bloginfo( 'name' ) ),
			sprintf( '%s möchte eine Schicht mit dir tauschen. Bitte im Mitgliederbereich unter „Schichtpläne“ annehmen oder ablehnen.', $me->display_name ?: $me->user_login )
		);
		return rest_ensure_response( array( 'ok' => true, 'id' => (int) $wpdb->insert_id ) );
	}

	$id = (int) ( $b['id'] ?? 0 );
	$tr = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `$tt` WHERE id = %d", $id ) ) : null;
	if ( ! $tr ) {
		return new WP_Error( 'bad_req', 'Tausch-Anfrage nicht gefunden.', array( 'status' => 400 ) );
	}

	if ( 'zuruecknehmen' === $op ) {
		$wpdb->delete( $tt, array( 'id' => $id ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	if ( 'offen' !== $tr->status ) {
		return new WP_Error( 'done', 'Diese Anfrage ist schon entschieden.', array( 'status' => 409 ) );
	}
	if ( strtolower( (string) $me->user_email ) !== strtolower( (string) $tr->an_email ) && ! current_user_can( 'wl_manage_wishes' ) ) {
		return new WP_Error( 'forbidden', 'Diese Anfrage ist nicht an dich gerichtet.', array( 'status' => 403 ) );
	}
	$annehmen = ! empty( $b['annehmen'] );
	if ( $annehmen ) {
		$wpdb->update( $te, array(
			'name'    => $me->display_name ?: $me->user_login,
			'email'   => $me->user_email,
			'user_id' => $uid,
		), array( 'id' => (int) $tr->von_eintrag_id ) );
	}
	$wpdb->update( $tt, array( 'status' => $annehmen ? 'angenommen' : 'abgelehnt' ), array( 'id' => $id ) );
	return rest_ensure_response( array( 'ok' => true, 'status' => $annehmen ? 'angenommen' : 'abgelehnt' ) );
}
