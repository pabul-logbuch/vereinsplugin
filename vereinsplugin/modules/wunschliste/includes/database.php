<?php
defined('ABSPATH') || exit;

function wl_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    $table = $wpdb->prefix . 'wunschliste';

    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        titel       VARCHAR(255) NOT NULL,
        beschreibung TEXT,
        begruendung TEXT,
        betrag      DECIMAL(10,2) DEFAULT 0,
        preis_von   DECIMAL(10,2) DEFAULT NULL,
        preis_bis   DECIMAL(10,2) DEFAULT NULL,
        kategorie   VARCHAR(100) DEFAULT '',
        status      ENUM('offen','in_bearbeitung','erfuellt') DEFAULT 'offen',
        vote_status ENUM('aktiv','veto','archiviert') DEFAULT 'aktiv',
        prioritaet  TINYINT DEFAULT 2,
        bild_url    VARCHAR(500) DEFAULT '',
        erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
        geaendert_am DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        erstellt_von BIGINT UNSIGNED DEFAULT 0
    ) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Produktlinks (mehrere pro Wunsch)
    $links_table = $wpdb->prefix . 'wl_links';
    $sql_links = "CREATE TABLE IF NOT EXISTS $links_table (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        wunsch_id   BIGINT UNSIGNED NOT NULL,
        label       VARCHAR(150) DEFAULT '',
        url         VARCHAR(500) NOT NULL,
        preis       DECIMAL(10,2) DEFAULT NULL,
        sortierung  SMALLINT DEFAULT 0,
        KEY wunsch_id (wunsch_id)
    ) $charset;";
    dbDelta($sql_links);

    // Migration: falls Tabelle bereits in alter Version existierte, Spalten nachrüsten
    wl_maybe_upgrade_columns();
}

function wl_maybe_upgrade_columns() {
    global $wpdb;
    $table = $wpdb->prefix . 'wunschliste';
    $cols = $wpdb->get_col("SHOW COLUMNS FROM $table", 0);

    $needed = [
        'begruendung' => "ALTER TABLE $table ADD COLUMN begruendung TEXT AFTER beschreibung",
        'preis_von'   => "ALTER TABLE $table ADD COLUMN preis_von DECIMAL(10,2) DEFAULT NULL AFTER betrag",
        'preis_bis'   => "ALTER TABLE $table ADD COLUMN preis_bis DECIMAL(10,2) DEFAULT NULL AFTER preis_von",
        'vote_status' => "ALTER TABLE $table ADD COLUMN vote_status ENUM('aktiv','veto','archiviert') DEFAULT 'aktiv' AFTER status",
    ];

    foreach ($needed as $col => $alter_sql) {
        if (!in_array($col, $cols)) {
            $wpdb->query($alter_sql);
        }
    }
}

function wl_create_voting_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Abstimmungen (pro Wunsch, pro Voter)
    $votes = $wpdb->prefix . 'wl_votes';
    dbDelta("CREATE TABLE IF NOT EXISTS $votes (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        wunsch_id   BIGINT UNSIGNED NOT NULL,
        voter_key   VARCHAR(64) NOT NULL,
        voter_name  VARCHAR(100) DEFAULT '',
        voter_type  ENUM('mitglied','gast') DEFAULT 'mitglied',
        stufe       TINYINT NOT NULL COMMENT '1=braucht das Jufo, 2=wünsche ich mir, 3=egal, 4=braucht das Jufo nicht, 5=Veto',
        begruendung TEXT,
        abgestimmt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
        geaendert_am  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY voter_wunsch (wunsch_id, voter_key)
    ) $charset;");

    // Gastzugang-Codes (für Meetings)
    $codes = $wpdb->prefix . 'wl_gastcodes';
    dbDelta("CREATE TABLE IF NOT EXISTS $codes (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        code        VARCHAR(32) NOT NULL UNIQUE,
        beschreibung VARCHAR(255) DEFAULT '',
        gueltig_bis DATETIME DEFAULT NULL,
        erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
        erstellt_von BIGINT UNSIGNED DEFAULT 0,
        aktiv       TINYINT DEFAULT 1
    ) $charset;");
}

function wl_create_shift_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Veranstaltungen (z.B. "Stadtfestival")
    $events = $wpdb->prefix . 'wl_shift_events';
    dbDelta("CREATE TABLE IF NOT EXISTS $events (
        id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        titel        VARCHAR(255) NOT NULL,
        slug         VARCHAR(100) NOT NULL UNIQUE,
        beschreibung TEXT,
        veranstaltungsdatum DATE DEFAULT NULL,
        tagesgrenze_stunde TINYINT DEFAULT 0,
        aktiv        TINYINT DEFAULT 1,
        erstellt_am  DATETIME DEFAULT CURRENT_TIMESTAMP,
        erstellt_von BIGINT UNSIGNED DEFAULT 0
    ) $charset;");

    // Migration: Spalte nachrüsten falls Tabelle aus älterer Version existiert
    $cols = $wpdb->get_col("SHOW COLUMNS FROM $events", 0);
    if (!in_array('tagesgrenze_stunde', $cols)) {
        $wpdb->query("ALTER TABLE $events ADD COLUMN tagesgrenze_stunde TINYINT DEFAULT 0 AFTER veranstaltungsdatum");
    }

    // Stationen (z.B. "Hauptausschank", "Kasse", "Aufbau Freitag")
    $stationen = $wpdb->prefix . 'wl_shift_stationen';
    dbDelta("CREATE TABLE IF NOT EXISTS $stationen (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_id    BIGINT UNSIGNED NOT NULL,
        titel       VARCHAR(255) NOT NULL,
        beschreibung TEXT,
        treffpunkt  VARCHAR(255) DEFAULT '',
        ansprechperson1 VARCHAR(150) DEFAULT '',
        ansprechperson1_kontakt VARCHAR(150) DEFAULT '',
        ansprechperson2 VARCHAR(150) DEFAULT '',
        ansprechperson2_kontakt VARCHAR(150) DEFAULT '',
        sortierung  SMALLINT DEFAULT 0,
        KEY event_id (event_id)
    ) $charset;");

    // Schichten (z.B. "Freitag 18:00 - 21:30")
    $schichten = $wpdb->prefix . 'wl_shift_schichten';
    dbDelta("CREATE TABLE IF NOT EXISTS $schichten (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        station_id  BIGINT UNSIGNED NOT NULL,
        titel       VARCHAR(150) DEFAULT '',
        start_zeit  DATETIME DEFAULT NULL,
        end_zeit    DATETIME DEFAULT NULL,
        min_plaetze SMALLINT DEFAULT 0,
        max_plaetze SMALLINT DEFAULT 1,
        sortierung  SMALLINT DEFAULT 0,
        KEY station_id (station_id)
    ) $charset;");

    // Migration: Spalte nachrüsten falls Tabelle aus älterer Version existiert
    $cols2 = $wpdb->get_col("SHOW COLUMNS FROM $schichten", 0);
    if (!in_array('min_plaetze', $cols2)) {
        $wpdb->query("ALTER TABLE $schichten ADD COLUMN min_plaetze SMALLINT DEFAULT 0 AFTER end_zeit");
    }

    // Eintragungen (wer hat sich für welche Schicht eingetragen)
    $eintragungen = $wpdb->prefix . 'wl_shift_eintragungen';
    dbDelta("CREATE TABLE IF NOT EXISTS $eintragungen (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        schicht_id  BIGINT UNSIGNED NOT NULL,
        name        VARCHAR(150) NOT NULL,
        email       VARCHAR(150) DEFAULT '',
        telefon     VARCHAR(50) DEFAULT '',
        user_id     BIGINT UNSIGNED DEFAULT NULL,
        manage_key  VARCHAR(64) NOT NULL,
        erinnerung_gesendet TINYINT DEFAULT 0,
        eingetragen_am DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY schicht_id (schicht_id),
        KEY manage_key (manage_key)
    ) $charset;");

    // Migration: Spalte nachrüsten falls Tabelle aus älterer Version existiert
    $cols3 = $wpdb->get_col("SHOW COLUMNS FROM $eintragungen", 0);
    if (!in_array('erinnerung_gesendet', $cols3)) {
        $wpdb->query("ALTER TABLE $eintragungen ADD COLUMN erinnerung_gesendet TINYINT DEFAULT 0 AFTER manage_key");
    }

    // Tausch-Anfragen
    $tausch = $wpdb->prefix . 'wl_shift_tausch';
    dbDelta("CREATE TABLE IF NOT EXISTS $tausch (
        id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        von_eintrag_id  BIGINT UNSIGNED NOT NULL,
        an_email        VARCHAR(150) NOT NULL,
        tausch_key      VARCHAR(64) NOT NULL UNIQUE,
        status          ENUM('offen','angenommen','abgelehnt','abgelaufen') DEFAULT 'offen',
        erstellt_am     DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY von_eintrag_id (von_eintrag_id)
    ) $charset;");
}

function wl_insert_sample_data() {
    global $wpdb;
    $table = $wpdb->prefix . 'wunschliste';

    // Nur einfügen wenn Tabelle leer
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
    if ($count > 0) return;

    $beispiele = [
        [
            'titel'        => 'Neuer Fußball (Größe 5)',
            'beschreibung' => 'Unser alter Ball ist leider kaputt. Ein offizieller Spielball würde dem Team sehr helfen.',
            'begruendung'  => 'Der bisherige Ball hat einen Riss und ist nicht mehr bespielbar. Ohne Ersatz kann das Training nicht wie gewohnt stattfinden.',
            'betrag'       => 35.00,
            'kategorie'    => 'Sport',
            'status'       => 'offen',
            'prioritaet'   => 1,
            'links'        => [
                ['label' => 'Decathlon', 'url' => 'https://www.decathlon.de/p/fussball-groesse-5', 'preis' => 29.99],
                ['label' => 'Intersport', 'url' => 'https://www.intersport.de/fussball', 'preis' => 34.99],
            ],
        ],
        [
            'titel'        => 'Erste-Hilfe-Koffer',
            'beschreibung' => 'Für Veranstaltungen brauchen wir einen gut ausgestatteten Erste-Hilfe-Koffer.',
            'begruendung'  => 'Bei Vereinsveranstaltungen sind wir gesetzlich verpflichtet, Erste-Hilfe-Material bereitzuhalten. Aktuell haben wir keinen vollständigen Koffer.',
            'preis_von'    => 60.00,
            'preis_bis'    => 110.00,
            'kategorie'    => 'Sicherheit',
            'status'       => 'offen',
            'prioritaet'   => 1,
        ],
        [
            'titel'        => 'Trikot-Set (10 Stück)',
            'beschreibung' => 'Neue Trikots für die Jugendmannschaft in Vereinsfarben.',
            'begruendung'  => 'Die aktuellen Trikots sind seit 4 Jahren im Einsatz und stark verschlissen.',
            'betrag'       => 250.00,
            'kategorie'    => 'Sport',
            'status'       => 'in_bearbeitung',
            'prioritaet'   => 2,
        ],
        [
            'titel'        => 'Campingstühle (20 Stück)',
            'beschreibung' => 'Für unser Sommerfest und Vereinstreffen fehlen gemütliche Sitzgelegenheiten.',
            'preis_von'    => 150.00,
            'preis_bis'    => 220.00,
            'kategorie'    => 'Veranstaltungen',
            'status'       => 'offen',
            'prioritaet'   => 3,
        ],
        [
            'titel'        => 'Lautsprecher-Anlage',
            'beschreibung' => 'Eine portable PA-Anlage für Feste und Ehrungen.',
            'betrag'       => 420.00,
            'kategorie'    => 'Veranstaltungen',
            'status'       => 'erfuellt',
            'prioritaet'   => 2,
        ],
    ];

    foreach ($beispiele as $item) {
        $links = $item['links'] ?? null;
        unset($item['links']);
        $wpdb->insert($table, $item);
        if ($links) {
            wl_save_links($wpdb->insert_id, $links);
        }
    }
}

function wl_get_wuensche($args = []) {
    global $wpdb;
    $table = $wpdb->prefix . 'wunschliste';

    $defaults = [
        'status'    => '',
        'kategorie' => '',
        'orderby'   => 'prioritaet',
        'order'     => 'ASC',
    ];
    $args = wp_parse_args($args, $defaults);

    $where = [];
    $values = [];

    if (!empty($args['status'])) {
        $where[] = 'status = %s';
        $values[] = $args['status'];
    }
    if (!empty($args['kategorie'])) {
        $where[] = 'kategorie = %s';
        $values[] = $args['kategorie'];
    }

    $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $order_sql  = "ORDER BY {$args['orderby']} {$args['order']}, erstellt_am DESC";

    $sql = "SELECT * FROM $table $where_sql $order_sql";

    if ($values) {
        return $wpdb->get_results($wpdb->prepare($sql, $values));
    }
    return $wpdb->get_results($sql);
}

function wl_get_wunsch($id) {
    global $wpdb;
    $table = $wpdb->prefix . 'wunschliste';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
}

function wl_get_kategorien() {
    global $wpdb;
    $table = $wpdb->prefix . 'wunschliste';
    return $wpdb->get_col("SELECT DISTINCT kategorie FROM $table WHERE kategorie != '' ORDER BY kategorie ASC");
}

// ─── PRODUKTLINKS ─────────────────────────────────────────────────────────

function wl_get_links($wunsch_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'wl_links';
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE wunsch_id = %d ORDER BY sortierung ASC, id ASC",
        $wunsch_id
    ));
}

function wl_save_links($wunsch_id, $links) {
    global $wpdb;
    $table = $wpdb->prefix . 'wl_links';

    // Alle bestehenden Links für diesen Wunsch löschen, dann neu einfügen (einfachste Variante)
    $wpdb->delete($table, ['wunsch_id' => $wunsch_id]);

    $i = 0;
    foreach ($links as $link) {
        $url = esc_url_raw(trim($link['url'] ?? ''));
        if (empty($url)) continue;

        $wpdb->insert($table, [
            'wunsch_id'  => $wunsch_id,
            'label'      => sanitize_text_field($link['label'] ?? ''),
            'url'        => $url,
            'preis'      => !empty($link['preis']) ? floatval($link['preis']) : null,
            'sortierung' => $i,
        ]);
        $i++;
    }
}

function wl_format_preis($wunsch) {
    // Liefert einen lesbaren Preis-String zurück, je nachdem was gesetzt ist
    if (!empty($wunsch->preis_von) && !empty($wunsch->preis_bis) && $wunsch->preis_bis > $wunsch->preis_von) {
        return number_format($wunsch->preis_von, 2, ',', '.') . ' – ' . number_format($wunsch->preis_bis, 2, ',', '.') . ' €';
    }
    if (!empty($wunsch->betrag) && $wunsch->betrag > 0) {
        return number_format($wunsch->betrag, 2, ',', '.') . ' €';
    }
    if (!empty($wunsch->preis_von)) {
        return 'ab ' . number_format($wunsch->preis_von, 2, ',', '.') . ' €';
    }
    return '';
}

// ─── SCHICHTPLAN: EVENTS ───────────────────────────────────────────────────

function wl_get_events($nur_aktiv = false) {
    global $wpdb;
    $table = $wpdb->prefix . 'wl_shift_events';
    $where = $nur_aktiv ? 'WHERE aktiv = 1' : '';
    return $wpdb->get_results("SELECT * FROM $table $where ORDER BY veranstaltungsdatum DESC, id DESC");
}

function wl_get_event($id) {
    global $wpdb;
    $table = $wpdb->prefix . 'wl_shift_events';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
}

function wl_get_event_by_slug($slug) {
    global $wpdb;
    $table = $wpdb->prefix . 'wl_shift_events';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE slug = %s", $slug));
}

function wl_generate_event_slug($titel, $exclude_id = 0) {
    global $wpdb;
    $table = $wpdb->prefix . 'wl_shift_events';
    $base = sanitize_title($titel);
    $slug = $base;
    $n = 2;
    while (true) {
        $sql = "SELECT id FROM $table WHERE slug = %s";
        $params = [$slug];
        if ($exclude_id) {
            $sql .= " AND id != %d";
            $params[] = $exclude_id;
        }
        $exists = $wpdb->get_var($wpdb->prepare($sql, $params));
        if (!$exists) break;
        $slug = $base . '-' . $n;
        $n++;
    }
    return $slug;
}

// ─── SCHICHTPLAN: STATIONEN ─────────────────────────────────────────────────

function wl_get_stationen($event_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'wl_shift_stationen';
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE event_id = %d ORDER BY sortierung ASC, id ASC", $event_id
    ));
}

function wl_get_station($id) {
    global $wpdb;
    $table = $wpdb->prefix . 'wl_shift_stationen';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
}

// ─── SCHICHTPLAN: SCHICHTEN ─────────────────────────────────────────────────

function wl_get_schichten($station_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'wl_shift_schichten';
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE station_id = %d ORDER BY sortierung ASC, start_zeit ASC, id ASC", $station_id
    ));
}

function wl_get_schicht($id) {
    global $wpdb;
    $table = $wpdb->prefix . 'wl_shift_schichten';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
}

// ─── SCHICHTPLAN: EINTRAGUNGEN ──────────────────────────────────────────────

function wl_get_eintragungen($schicht_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'wl_shift_eintragungen';
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE schicht_id = %d ORDER BY eingetragen_am ASC", $schicht_id
    ));
}

function wl_count_eintragungen($schicht_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'wl_shift_eintragungen';
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE schicht_id = %d", $schicht_id
    ));
}

function wl_get_eintragung_by_key($manage_key) {
    global $wpdb;
    $table = $wpdb->prefix . 'wl_shift_eintragungen';
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table WHERE manage_key = %s", $manage_key
    ));
}

// Komplette Event-Daten verschachtelt laden (für Anzeige + Export)
function wl_get_event_full($event_id) {
    $event = wl_get_event($event_id);
    if (!$event) return null;

    $event->stationen = wl_get_stationen($event_id);
    foreach ($event->stationen as $station) {
        $station->schichten = wl_get_schichten($station->id);
        foreach ($station->schichten as $schicht) {
            $schicht->eintragungen = wl_get_eintragungen($schicht->id);
            $schicht->belegt = count($schicht->eintragungen);
            $schicht->frei = max(0, $schicht->max_plaetze - $schicht->belegt);
        }
    }
    return $event;
}

/**
 * Gruppiert alle Eintragungen einer Veranstaltung nach Person (E-Mail-Adresse).
 * Liefert ein Array: email => ['name' => ..., 'manage_keys' => [...], 'schichten' => [
 *   ['schicht' => obj, 'station' => obj], ...
 * ]]
 * Wird für die zusammengefasste Erinnerungsmail und den Mehrfach-Kalenderexport genutzt.
 */
function wl_get_eintragungen_gruppiert_nach_person($event_id) {
    global $wpdb;
    $et = $wpdb->prefix . 'wl_shift_eintragungen';
    $st = $wpdb->prefix . 'wl_shift_schichten';
    $stat = $wpdb->prefix . 'wl_shift_stationen';

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT e.*, s.titel AS schicht_titel, s.start_zeit, s.end_zeit, s.station_id
        FROM $et e
        INNER JOIN $st s ON s.id = e.schicht_id
        INNER JOIN $stat st2 ON st2.id = s.station_id
        WHERE st2.event_id = %d
          AND e.email != ''
        ORDER BY s.start_zeit ASC
    ", $event_id));

    $gruppen = [];
    foreach ($rows as $row) {
        $key = strtolower(trim($row->email));
        if (!isset($gruppen[$key])) {
            $gruppen[$key] = [
                'name'  => $row->name,
                'email' => $row->email,
                'eintragungen' => [],
            ];
        }

        $station = wl_get_station($row->station_id);
        $schicht = (object) [
            'id' => $row->schicht_id,
            'titel' => $row->schicht_titel,
            'start_zeit' => $row->start_zeit,
            'end_zeit' => $row->end_zeit,
        ];

        $gruppen[$key]['eintragungen'][] = [
            'eintragung_id' => $row->id,
            'manage_key'    => $row->manage_key,
            'erinnerung_gesendet' => (int) $row->erinnerung_gesendet,
            'schicht' => $schicht,
            'station' => $station,
        ];
    }

    return $gruppen;
}

// ─── SCHICHTPLAN: TAGES-MATRIX (für tabellarische Ansicht) ────────────────

/**
 * Ordnet eine Schicht ihrem "logischen Tag" zu: Schichten, die vor der
 * Tagesgrenze (z.B. 4 Uhr) beginnen, zählen noch zum Vortag.
 * Beispiel: Tagesgrenze 4 Uhr, Schicht beginnt 01:00 Uhr Samstag → zählt als Freitag-Nacht.
 */
function wl_get_logischer_tag($datetime_str, $tagesgrenze_stunde) {
    if (empty($datetime_str)) return null;
    $ts = strtotime($datetime_str);
    if (!$ts) return null;

    $stunde = (int) date('G', $ts);
    if ($tagesgrenze_stunde > 0 && $stunde < $tagesgrenze_stunde) {
        $ts = $ts - DAY_IN_SECONDS;
    }
    return date('Y-m-d', $ts);
}

/**
 * Baut eine Kalender-Matrix: Tage als Hauptspalten, innerhalb jedes Tages
 * laufen Stationen als Unterspalten nebeneinander, mit einer gemeinsamen
 * proportionalen Zeitachse (wie ein Tageskalender). Überlappende Schichten
 * derselben Station bekommen eigene "Spuren" (Lanes) nebeneinander.
 */
function wl_get_schichtplan_kalender($event) {
    $tagesgrenze = (int) $event->tagesgrenze_stunde;

    // 1. Alle Schichten mit Zeit den Tagen zuordnen, ohne Zeit separat sammeln
    $tage = []; // 'Y-m-d' => ['schichten' => [ ['schicht'=>obj,'station'=>obj,'start_min'=>int,'end_min'=>int] ] ]
    $ohne_termin = []; // [ ['schicht'=>obj,'station'=>obj] ]

    foreach ($event->stationen as $station) {
        foreach ($station->schichten as $schicht) {
            if (empty($schicht->start_zeit)) {
                $ohne_termin[] = ['schicht' => $schicht, 'station' => $station];
                continue;
            }

            $tag = wl_get_logischer_tag($schicht->start_zeit, $tagesgrenze);
            if (!isset($tage[$tag])) {
                $tage[$tag] = ['schichten' => []];
            }

            $start_ts = strtotime($schicht->start_zeit);
            // Tagesanfang dieses logischen Tages (für Minuten-Offset-Berechnung)
            $tag_start_ts = strtotime($tag . ' ' . sprintf('%02d:00:00', $tagesgrenze));
            $start_min = ($start_ts - $tag_start_ts) / 60;

            if (!empty($schicht->end_zeit)) {
                $end_ts = strtotime($schicht->end_zeit);
                $end_min = ($end_ts - $tag_start_ts) / 60;
            } else {
                $end_min = $start_min + 60; // Standarddauer 1h falls kein Ende angegeben
            }

            $tage[$tag]['schichten'][] = [
                'schicht' => $schicht,
                'station' => $station,
                'start_min' => $start_min,
                'end_min'   => max($end_min, $start_min + 30), // Mindesthöhe für Lesbarkeit
            ];
        }
    }

    ksort($tage);

    // 2. Pro Tag: komprimierte Zeitachse bauen (Lücken zwischen Schichten stauchen),
    //    dann Lane-Zuteilung pro Station innerhalb der komprimierten Achse.
    foreach ($tage as $tag => &$daten) {
        if (empty($daten['schichten'])) continue;

        $kompression = wl_build_komprimierte_achse($daten['schichten']);
        $daten['achse_start'] = 0;
        $daten['achse_end']   = $kompression['gesamt_laenge'];
        $daten['achsen_marker'] = $kompression['marker']; // für Stunden-Beschriftung
        $daten['luecken'] = array_values(array_filter($kompression['segmente'], function ($s) { return $s['typ'] === 'luecke'; }));

        // Start/Ende jeder Schicht auf komprimierte Achse umrechnen
        foreach ($daten['schichten'] as &$item) {
            $item['comp_start'] = wl_komprimiere_minute($item['start_min'], $kompression['segmente']);
            $item['comp_end']   = wl_komprimiere_minute($item['end_min'], $kompression['segmente']);
        }
        unset($item);

        // Schichten nach Station gruppieren
        $nach_station = [];
        foreach ($daten['schichten'] as $item) {
            $sid = $item['station']->id;
            if (!isset($nach_station[$sid])) {
                $nach_station[$sid] = ['station' => $item['station'], 'items' => []];
            }
            $nach_station[$sid]['items'][] = $item;
        }

        // Innerhalb jeder Station: Lanes zuteilen (Greedy-Algorithmus für Überlappungen),
        // basierend auf den komprimierten Positionen.
        foreach ($nach_station as $sid => &$gruppe) {
            usort($gruppe['items'], function ($a, $b) { return $a['comp_start'] <=> $b['comp_start']; });

            $lane_ends = []; // lane_index => letztes comp_end in dieser Spur
            foreach ($gruppe['items'] as &$item) {
                $platziert = false;
                foreach ($lane_ends as $lane => $ende) {
                    if ($item['comp_start'] >= $ende) {
                        $item['lane'] = $lane;
                        $lane_ends[$lane] = $item['comp_end'];
                        $platziert = true;
                        break;
                    }
                }
                if (!$platziert) {
                    $item['lane'] = count($lane_ends);
                    $lane_ends[] = $item['comp_end'];
                }
            }
            unset($item);
            $gruppe['lane_count'] = max(1, count($lane_ends));
        }
        unset($gruppe);

        $daten['stationen'] = $nach_station;
    }
    unset($daten);

    return ['tage' => $tage, 'ohne_termin' => $ohne_termin];
}

/**
 * Baut eine komprimierte Zeitachse für einen Tag: durchgehend belegte
 * Zeiträume (in denen mind. eine Schicht läuft) behalten ihre echte
 * proportionale Länge, Lücken zwischen Schichten (in denen NICHTS läuft)
 * werden auf eine kleine Fixbreite zusammengestaucht.
 *
 * Beispiel: Nachtschicht 4-8, Mittagsschicht 12-15, Abendschicht 19-23
 * → Lücke 8-12 und 15-19 werden auf z.B. 25min "visuelle Breite" reduziert,
 *   statt 4h echte Breite einzunehmen.
 */
function wl_build_komprimierte_achse($schichten) {
    // Alle Start/Ende-Zeitpunkte sammeln und auf volle Stunden runden/aufrunden
    $intervalle = [];
    foreach ($schichten as $s) {
        $intervalle[] = [floor($s['start_min'] / 60) * 60, ceil($s['end_min'] / 60) * 60];
    }
    usort($intervalle, function ($a, $b) { return $a[0] <=> $b[0]; });

    // Überlappende/angrenzende Intervalle zu "belegten Blöcken" verschmelzen
    $belegte_bloecke = [];
    foreach ($intervalle as $iv) {
        if (!empty($belegte_bloecke) && $iv[0] <= end($belegte_bloecke)[1]) {
            $belegte_bloecke[count($belegte_bloecke) - 1][1] = max(end($belegte_bloecke)[1], $iv[1]);
        } else {
            $belegte_bloecke[] = $iv;
        }
    }

    // Segmente bauen: abwechselnd "belegt" (echte Länge) und "luecke" (Fixbreite)
    $LUECKE_BREITE = 35; // visuelle "Minuten-Breite" für jede Lücke, unabhängig von ihrer echten Dauer
    $segmente = [];
    $cursor_real = $belegte_bloecke[0][0]; // vor dem ersten Block gibt es keine Lücke
    $cursor_comp = 0;

    foreach ($belegte_bloecke as $i => $block) {
        if ($i > 0) {
            // Lücke vor diesem Block (zwischen vorherigem Blockende und diesem Blockstart)
            $luecke_real_start = $cursor_real;
            $luecke_real_ende  = $block[0];
            if ($luecke_real_ende > $luecke_real_start) {
                $segmente[] = [
                    'typ' => 'luecke',
                    'real_start' => $luecke_real_start, 'real_ende' => $luecke_real_ende,
                    'comp_start' => $cursor_comp, 'comp_ende' => $cursor_comp + $LUECKE_BREITE,
                ];
                $cursor_comp += $LUECKE_BREITE;
            }
        }
        $block_laenge = $block[1] - $block[0];
        $segmente[] = [
            'typ' => 'belegt',
            'real_start' => $block[0], 'real_ende' => $block[1],
            'comp_start' => $cursor_comp, 'comp_ende' => $cursor_comp + $block_laenge,
        ];
        $cursor_comp += $block_laenge;
        $cursor_real = $block[1];
    }

    // Stunden-Marker für die Beschriftung sammeln (nur innerhalb belegter Segmente)
    $marker = [];
    foreach ($segmente as $seg) {
        if ($seg['typ'] !== 'belegt') continue;
        for ($m = $seg['real_start']; $m <= $seg['real_ende']; $m += 60) {
            $comp_pos = $seg['comp_start'] + ($m - $seg['real_start']);
            $marker[] = ['real_min' => $m, 'comp_pos' => $comp_pos];
        }
    }

    return [
        'segmente' => $segmente,
        'gesamt_laenge' => $cursor_comp,
        'marker' => $marker,
    ];
}

/** Rechnet eine echte Minute (Tagesoffset) in ihre Position auf der komprimierten Achse um. */
function wl_komprimiere_minute($real_minute, $segmente) {
    foreach ($segmente as $seg) {
        if ($real_minute >= $seg['real_start'] && $real_minute <= $seg['real_ende']) {
            if ($seg['typ'] === 'belegt') {
                return $seg['comp_start'] + ($real_minute - $seg['real_start']);
            }
            // Mitten in einer Lücke (sollte bei Schicht-Start/Ende nicht vorkommen,
            // aber zur Sicherheit linear innerhalb der Lücke interpolieren)
            $anteil = ($seg['real_ende'] > $seg['real_start'])
                ? ($real_minute - $seg['real_start']) / ($seg['real_ende'] - $seg['real_start'])
                : 0;
            return $seg['comp_start'] + $anteil * ($seg['comp_ende'] - $seg['comp_start']);
        }
    }
    // Außerhalb aller Segmente (sollte nicht vorkommen) - letztes Segment nehmen
    $last = end($segmente);
    return $last ? $last['comp_ende'] : 0;
}
