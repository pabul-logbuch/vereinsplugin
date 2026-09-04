<?php
defined('ABSPATH') || exit;

function pp_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // ─── GREMIEN ─────────────────────────────────────────────────────────
    // Bildet MV, Vorstand, Leitungskreis, Kreise und Kreisversammlungen einheitlich ab.
    $gremien = $wpdb->prefix . 'pp_gremien';
    dbDelta("CREATE TABLE IF NOT EXISTS $gremien (
        id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        typ                  ENUM('mv','vorstand','leitungskreis','kreis','kreisversammlung') NOT NULL,
        name                 VARCHAR(255) NOT NULL,
        parent_gremium_id    BIGINT UNSIGNED DEFAULT NULL,
        oeffentlichkeit      ENUM('oeffentlich','vereinsintern','nur_gremium') DEFAULT 'vereinsintern',
        standardverfahren    ENUM('konsent','mehrheit','geheime_wahl') DEFAULT 'konsent',
        einladungsfrist_tage SMALLINT DEFAULT 14,
        beschreibung         TEXT,
        aktiv                TINYINT DEFAULT 1,
        erstellt_am          DATETIME DEFAULT CURRENT_TIMESTAMP,
        erstellt_von         BIGINT UNSIGNED DEFAULT 0,
        KEY typ (typ),
        KEY parent_gremium_id (parent_gremium_id)
    ) $charset;");

    // ─── ROLLENVORLAGEN ──────────────────────────────────────────────────
    // Definiert einen Rollentyp je Gremium unabhängig von der aktuellen
    // Besetzung (z. B. "Kassier:in" im Vorstand) mit Zuständigkeiten,
    // benötigten Fähigkeiten und Aufgaben-Vorlagen. Bleibt bei Wahlwechsel
    // erhalten, damit die/der neue Amtsinhaber:in automatisch die gleichen
    // Aufgaben übernimmt.
    $rollenvorlagen = $wpdb->prefix . 'pp_rollenvorlagen';
    dbDelta("CREATE TABLE IF NOT EXISTS $rollenvorlagen (
        id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        gremium_id             BIGINT UNSIGNED NOT NULL,
        bezeichnung            VARCHAR(150) NOT NULL,
        verantwortlich_fuer    TEXT COMMENT 'eine Zeile pro Eintrag',
        benoetigte_faehigkeiten TEXT COMMENT 'eine Zeile pro Eintrag',
        erstellt_am            DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY gremium_id (gremium_id)
    ) $charset;");

    // ─── ROLLENVORLAGEN-AUFGABEN ─────────────────────────────────────────
    // Wiederkehrende Aufgaben (landen automatisch bei der aktuellen
    // Amtsinhaber:in) oder Event-Aufgaben (werden je Termin mit zeitlichem
    // Vorlauf erzeugt und lassen sich danach individuell anpassen).
    $rollenvorlagen_aufgaben = $wpdb->prefix . 'pp_rollenvorlagen_aufgaben';
    dbDelta("CREATE TABLE IF NOT EXISTS $rollenvorlagen_aufgaben (
        id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        rollenvorlage_id BIGINT UNSIGNED NOT NULL,
        titel            VARCHAR(255) NOT NULL,
        beschreibung     TEXT,
        typ              ENUM('wiederkehrend','event') DEFAULT 'wiederkehrend',
        wiederholung     ENUM('taeglich','woechentlich','monatlich','jaehrlich') DEFAULT NULL,
        vorlauf_tage     SMALLINT DEFAULT NULL COMMENT 'nur bei typ=event: Tage vor dem Termin',
        aktiv            TINYINT DEFAULT 1,
        erstellt_am      DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY rollenvorlage_id (rollenvorlage_id)
    ) $charset;");

    // Protokolliert, für welchen Zeitpunkt eine wiederkehrende Aufgabe
    // zuletzt automatisch erzeugt wurde (verhindert Dopplungen im Cron).
    $aufgaben_log = $wpdb->prefix . 'pp_aufgaben_generiert_log';
    dbDelta("CREATE TABLE IF NOT EXISTS $aufgaben_log (
        id                         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        rollenvorlage_aufgabe_id   BIGINT UNSIGNED NOT NULL,
        generiert_fuer_datum       DATE NOT NULL,
        erstellt_am                DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_generierung (rollenvorlage_aufgabe_id, generiert_fuer_datum)
    ) $charset;");

    // ─── ROLLEN (Besetzung) ──────────────────────────────────────────────
    // z. B. Sprecher:in, Kassier:in, Kreisleitung – gekoppelt an einen WP-User.
    $rollen = $wpdb->prefix . 'pp_rollen';
    dbDelta("CREATE TABLE IF NOT EXISTS $rollen (
        id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        gremium_id           BIGINT UNSIGNED NOT NULL,
        rollenvorlage_id     BIGINT UNSIGNED DEFAULT NULL,
        bezeichnung          VARCHAR(150) NOT NULL,
        user_id              BIGINT UNSIGNED DEFAULT NULL,
        vertretungsberechtigt TINYINT DEFAULT 0,
        amtszeit_start       DATE DEFAULT NULL,
        amtszeit_ende        DATE DEFAULT NULL,
        wahl_gruppe          VARCHAR(50) DEFAULT '',
        erstellt_am          DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY gremium_id (gremium_id),
        KEY user_id (user_id),
        KEY rollenvorlage_id (rollenvorlage_id)
    ) $charset;");

    // ─── PROTOKOLLE ──────────────────────────────────────────────────────
    $protokolle = $wpdb->prefix . 'pp_protokolle';
    dbDelta("CREATE TABLE IF NOT EXISTS $protokolle (
        id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        gremium_id      BIGINT UNSIGNED NOT NULL,
        titel           VARCHAR(255) NOT NULL,
        datum           DATE DEFAULT NULL,
        ort             VARCHAR(255) DEFAULT '',
        status          ENUM('entwurf','abgeschlossen') DEFAULT 'entwurf',
        sichtbarkeit    ENUM('oeffentlich','vereinsintern','nur_gremium') DEFAULT 'vereinsintern',
        checkin         TEXT,
        organisatorisches TEXT,
        checkout        TEXT,
        anwesenheit     TEXT COMMENT 'JSON-Liste der anwesenden user_ids',
        beginn_zeit     DATETIME DEFAULT NULL COMMENT 'Startzeitpunkt der Live-Sitzung',
        uhrzeit_beginn  TIME DEFAULT NULL,
        uhrzeit_ende    TIME DEFAULT NULL,
        erstellt_am     DATETIME DEFAULT CURRENT_TIMESTAMP,
        geaendert_am    DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        erstellt_von    BIGINT UNSIGNED DEFAULT 0,
        KEY gremium_id (gremium_id),
        KEY status (status)
    ) $charset;");

    // ─── TAGESORDNUNGSPUNKTE (TOPs) ──────────────────────────────────────
    $tops = $wpdb->prefix . 'pp_tops';
    dbDelta("CREATE TABLE IF NOT EXISTS $tops (
        id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        protokoll_id     BIGINT UNSIGNED NOT NULL,
        titel            VARCHAR(255) NOT NULL,
        typ              ENUM('standard','wahl','svo_teil_a_review','to_aenderung') DEFAULT 'standard',
        verfahren        ENUM('konsent','mehrheit','geheime_wahl') DEFAULT 'konsent',
        konsent_status   ENUM('vorstellung','verstaendnisfragen','meinungsrunde','konsentrunde','einwand_offen','beschlossen') DEFAULT 'vorstellung',
        beschreibung     TEXT,
        beschluss        TEXT,
        dauer_minuten    SMALLINT DEFAULT 15,
        to_aenderung_daten TEXT COMMENT 'JSON: geplante Tagesordnungsänderung, wird bei Beschluss angewendet',
        to_aenderung_erledigt TINYINT DEFAULT 0,
        thema_id         BIGINT UNSIGNED DEFAULT NULL,
        ist_aufgabe      TINYINT DEFAULT 0,
        aufgabe_verantwortlich_user_id BIGINT UNSIGNED DEFAULT NULL,
        faelligkeitsdatum DATE DEFAULT NULL,
        ist_termin       TINYINT DEFAULT 0,
        termin_datum     DATETIME DEFAULT NULL,
        erfordert_mv_bestaetigung TINYINT DEFAULT 0,
        bestaetigung_beschluss_typ ENUM('mitgliedsaufnahme','mitgliedsausschluss','kreisgruendung','kreisaenderung','kreisaufloesung','') DEFAULT '',
        sortierung       SMALLINT DEFAULT 0,
        erstellt_am      DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY protokoll_id (protokoll_id),
        KEY thema_id (thema_id)
    ) $charset;");

    // ─── EINWÄNDE (Konsentrunde) ─────────────────────────────────────────
    $einwaende = $wpdb->prefix . 'pp_einwaende';
    dbDelta("CREATE TABLE IF NOT EXISTS $einwaende (
        id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        top_id       BIGINT UNSIGNED NOT NULL,
        user_id      BIGINT UNSIGNED DEFAULT NULL,
        begruendung  TEXT NOT NULL,
        status       ENUM('offen','geklaert') DEFAULT 'offen',
        erstellt_am  DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY top_id (top_id)
    ) $charset;");

    // ─── THEMENSPEICHER ──────────────────────────────────────────────────
    $themen = $wpdb->prefix . 'pp_themen';
    dbDelta("CREATE TABLE IF NOT EXISTS $themen (
        id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        titel        VARCHAR(255) NOT NULL,
        beschreibung TEXT,
        svo_teil     ENUM('A','B','C','') DEFAULT '',
        status       ENUM('vorbereitet','in_bearbeitung','abgeschlossen','evaluationsreif') DEFAULT 'vorbereitet',
        gremium_id   BIGINT UNSIGNED DEFAULT NULL,
        erstellt_am  DATETIME DEFAULT CURRENT_TIMESTAMP,
        erstellt_von BIGINT UNSIGNED DEFAULT 0,
        KEY status (status),
        KEY gremium_id (gremium_id)
    ) $charset;");

    // ─── AUFGABEN ────────────────────────────────────────────────────────
    $aufgaben = $wpdb->prefix . 'pp_aufgaben';
    dbDelta("CREATE TABLE IF NOT EXISTS $aufgaben (
        id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        titel                     VARCHAR(255) NOT NULL,
        beschreibung              TEXT,
        verantwortlich_user_id    BIGINT UNSIGNED DEFAULT NULL,
        verantwortliches_gremium_id BIGINT UNSIGNED DEFAULT NULL,
        faelligkeitsdatum         DATE DEFAULT NULL,
        status                    ENUM('offen','erledigt') DEFAULT 'offen',
        quelle_top_id             BIGINT UNSIGNED DEFAULT NULL,
        quelle_termin_id          BIGINT UNSIGNED DEFAULT NULL,
        quelle_protokoll_id       BIGINT UNSIGNED DEFAULT NULL,
        quelle_set_eintrag_id     BIGINT UNSIGNED DEFAULT NULL,
        quelle_rollenvorlage_aufgabe_id BIGINT UNSIGNED DEFAULT NULL,
        erstellt_am               DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY status (status),
        KEY verantwortlich_user_id (verantwortlich_user_id)
    ) $charset;");

    // ─── TERMINE ─────────────────────────────────────────────────────────
    $termine = $wpdb->prefix . 'pp_termine';
    dbDelta("CREATE TABLE IF NOT EXISTS $termine (
        id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        titel          VARCHAR(255) NOT NULL,
        datum          DATETIME DEFAULT NULL,
        ort            VARCHAR(255) DEFAULT '',
        gremium_id     BIGINT UNSIGNED DEFAULT NULL,
        quelle_top_id  BIGINT UNSIGNED DEFAULT NULL,
        quelle_protokoll_id BIGINT UNSIGNED DEFAULT NULL,
        erstellt_am    DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY gremium_id (gremium_id)
    ) $charset;");

    // ─── BESTÄTIGUNGSPFLICHTIGE LEITUNGSKREIS-BESCHLÜSSE ────────────────
    // Mitgliedsaufnahme/-ausschluss und Kreisgründung/-änderung/-auflösung
    // (§10 Satzung) – werden der nächsten MV zur Bestätigung vorgelegt.
    $bestaetigungen = $wpdb->prefix . 'pp_bestaetigungen';
    dbDelta("CREATE TABLE IF NOT EXISTS $bestaetigungen (
        id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        quelle_gremium_id     BIGINT UNSIGNED DEFAULT NULL,
        beschluss_typ         ENUM('mitgliedsaufnahme','mitgliedsausschluss','kreisgruendung','kreisaenderung','kreisaufloesung') NOT NULL,
        beschreibung          TEXT,
        status                ENUM('offen','bestaetigt','revidiert') DEFAULT 'offen',
        ziel_mv_protokoll_id  BIGINT UNSIGNED DEFAULT NULL,
        quelle_top_id         BIGINT UNSIGNED DEFAULT NULL,
        entscheidungsdatum    DATE DEFAULT NULL,
        erstellt_am           DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY status (status)
    ) $charset;");

    // ─── FREIGABEN (Vier-Augen-Prinzip, §9 Satzung) ─────────────────────
    $freigaben = $wpdb->prefix . 'pp_freigaben';
    dbDelta("CREATE TABLE IF NOT EXISTS $freigaben (
        id                             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        beschreibung                   VARCHAR(255) NOT NULL,
        betrag                         DECIMAL(10,2) DEFAULT 0,
        betrifft_kreis_id              BIGINT UNSIGNED DEFAULT NULL,
        freigabe1_user_id              BIGINT UNSIGNED DEFAULT NULL,
        freigabe1_am                   DATETIME DEFAULT NULL,
        freigabe2_user_id              BIGINT UNSIGNED DEFAULT NULL,
        freigabe2_am                   DATETIME DEFAULT NULL,
        kreisversammlung_konsent_status ENUM('nicht_erforderlich','ausstehend','erteilt') DEFAULT 'nicht_erforderlich',
        status                         ENUM('offen','freigegeben') DEFAULT 'offen',
        erstellt_am                    DATETIME DEFAULT CURRENT_TIMESTAMP,
        erstellt_von                   BIGINT UNSIGNED DEFAULT 0,
        KEY status (status)
    ) $charset;");

    // ─── KREISMITGLIEDSCHAFT ─────────────────────────────────────────────
    // Wer arbeitet in welchem Kreis mit? Unabhängig von der Vereinsmitglied-
    // schaft und von Rollen — man kann in einem Kreis mitarbeiten, ohne dort
    // eine Rolle innezuhaben.
    $kreis_mitglieder = $wpdb->prefix . 'pp_kreis_mitglieder';
    dbDelta("CREATE TABLE IF NOT EXISTS $kreis_mitglieder (
        id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        gremium_id     BIGINT UNSIGNED NOT NULL,
        user_id        BIGINT UNSIGNED NOT NULL,
        beigetreten_am DATE DEFAULT NULL,
        ausgetreten_am DATE DEFAULT NULL,
        erstellt_am    DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY gremium_id (gremium_id),
        KEY user_id (user_id)
    ) $charset;");

    // ─── AUFGABEN-SETS ───────────────────────────────────────────────────
    // Ein benanntes Bündel von Aufgaben, das sich auf einen Termin anwenden
    // lässt — z. B. „Veranstaltung": Kostenkalkulation (Kassier:in, 21 Tage
    // vorher), Wechselgeld abheben (Kassier:in, 2 Tage vorher), Schichtplan
    // erstellen (Kreisleitung, 14 Tage vorher).
    $sets = $wpdb->prefix . 'pp_aufgaben_sets';
    dbDelta("CREATE TABLE IF NOT EXISTS $sets (
        id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name         VARCHAR(255) NOT NULL,
        beschreibung TEXT,
        gremium_id   BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL = vereinsweit verfuegbar',
        erstellt_am  DATETIME DEFAULT CURRENT_TIMESTAMP,
        erstellt_von BIGINT UNSIGNED DEFAULT 0,
        KEY gremium_id (gremium_id)
    ) $charset;");

    $set_eintraege = $wpdb->prefix . 'pp_aufgaben_set_eintraege';
    dbDelta("CREATE TABLE IF NOT EXISTS $set_eintraege (
        id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        set_id           BIGINT UNSIGNED NOT NULL,
        rollenvorlage_id BIGINT UNSIGNED DEFAULT NULL COMMENT 'NULL = niemandem fest zugeordnet',
        titel            VARCHAR(255) NOT NULL,
        beschreibung     TEXT,
        vorlauf_tage     SMALLINT DEFAULT 14,
        zuweisung        ENUM('eine','alle') DEFAULT 'eine' COMMENT 'bei doppelt besetzten Rollen: eine Person oder alle',
        sortierung       SMALLINT DEFAULT 0,
        erstellt_am      DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY set_id (set_id)
    ) $charset;");

    // ─── KOMMENTARE (zu Protokollen, für den Mitgliederbereich) ─────────
    $kommentare = $wpdb->prefix . 'pp_kommentare';
    dbDelta("CREATE TABLE IF NOT EXISTS $kommentare (
        id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        protokoll_id BIGINT UNSIGNED NOT NULL,
        user_id      BIGINT UNSIGNED DEFAULT NULL,
        text         TEXT NOT NULL,
        erstellt_am  DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY protokoll_id (protokoll_id)
    ) $charset;");

    // ─── UNTERLAGEN ZU TOPS (Text, Link, Datei) ─────────────────────────
    // Getrennte Tabelle statt weiterer TEXT-Spalten an pp_tops: es sind
    // beliebig viele je TOP, mit eigener Herkunft und eigenem Zeitstempel.
    $top_unterlagen = $wpdb->prefix . 'pp_top_unterlagen';
    dbDelta("CREATE TABLE IF NOT EXISTS $top_unterlagen (
        id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        top_id       BIGINT UNSIGNED NOT NULL,
        protokoll_id BIGINT UNSIGNED NOT NULL,
        typ          ENUM('text','link','datei') DEFAULT 'text',
        titel        VARCHAR(255) DEFAULT '',
        inhalt       LONGTEXT,
        url          VARCHAR(600) DEFAULT '',
        anhang_id    BIGINT UNSIGNED DEFAULT NULL,
        erstellt_von BIGINT UNSIGNED DEFAULT NULL,
        erstellt_am  DATETIME DEFAULT CURRENT_TIMESTAMP,
        sortierung   SMALLINT DEFAULT 0,
        KEY top_id (top_id),
        KEY protokoll_id (protokoll_id)
    ) $charset;");

    pp_maybe_upgrade_columns();
}

/** Rüstet neue Spalten bei bestehenden Installationen manuell nach
 *  (dbDelta rüstet neue Tabellen zuverlässig nach, bei Spalten an
 *  bestehenden Tabellen gehen wir hier auf Nummer sicher, gleiches
 *  Muster wie im Wunschliste-Plugin). */
function pp_maybe_upgrade_columns() {
    global $wpdb;

    $rollen = $wpdb->prefix . 'pp_rollen';
    $cols = $wpdb->get_col("SHOW COLUMNS FROM $rollen", 0);
    if (!in_array('rollenvorlage_id', $cols)) {
        $wpdb->query("ALTER TABLE $rollen ADD COLUMN rollenvorlage_id BIGINT UNSIGNED DEFAULT NULL AFTER gremium_id");
    }

    $aufgaben = $wpdb->prefix . 'pp_aufgaben';
    $cols2 = $wpdb->get_col("SHOW COLUMNS FROM $aufgaben", 0);
    if (!in_array('quelle_termin_id', $cols2)) {
        $wpdb->query("ALTER TABLE $aufgaben ADD COLUMN quelle_termin_id BIGINT UNSIGNED DEFAULT NULL AFTER quelle_top_id");
    }
    if (!in_array('quelle_rollenvorlage_aufgabe_id', $cols2)) {
        $wpdb->query("ALTER TABLE $aufgaben ADD COLUMN quelle_rollenvorlage_aufgabe_id BIGINT UNSIGNED DEFAULT NULL AFTER quelle_termin_id");
    }
    if (!in_array('quelle_protokoll_id', $cols2)) {
        $wpdb->query("ALTER TABLE $aufgaben ADD COLUMN quelle_protokoll_id BIGINT UNSIGNED DEFAULT NULL AFTER quelle_termin_id");
    }
    if (!in_array('quelle_set_eintrag_id', $cols2)) {
        $wpdb->query("ALTER TABLE $aufgaben ADD COLUMN quelle_set_eintrag_id BIGINT UNSIGNED DEFAULT NULL AFTER quelle_protokoll_id");
    }

    $set_eintraege_tbl = $wpdb->prefix . 'pp_aufgaben_set_eintraege';
    if ($wpdb->get_var("SHOW TABLES LIKE '$set_eintraege_tbl'") === $set_eintraege_tbl) {
        $cols6 = $wpdb->get_col("SHOW COLUMNS FROM $set_eintraege_tbl", 0);
        if (!in_array('zuweisung', $cols6)) {
            $wpdb->query("ALTER TABLE $set_eintraege_tbl ADD COLUMN zuweisung ENUM('eine','alle') DEFAULT 'eine' AFTER vorlauf_tage");
        }
    }

    $tops = $wpdb->prefix . 'pp_tops';
    $cols3 = $wpdb->get_col("SHOW COLUMNS FROM $tops", 0);
    if (!in_array('dauer_minuten', $cols3)) {
        $wpdb->query("ALTER TABLE $tops ADD COLUMN dauer_minuten SMALLINT DEFAULT 15 AFTER beschluss");
    }
    if (!in_array('to_aenderung_daten', $cols3)) {
        $wpdb->query("ALTER TABLE $tops ADD COLUMN to_aenderung_daten TEXT AFTER dauer_minuten");
    }
    if (!in_array('to_aenderung_erledigt', $cols3)) {
        $wpdb->query("ALTER TABLE $tops ADD COLUMN to_aenderung_erledigt TINYINT DEFAULT 0 AFTER to_aenderung_daten");
    }
    // ENUM um 'to_aenderung' erweitern (dbDelta ändert bestehende ENUMs nicht zuverlässig)
    $typ_def = $wpdb->get_var("SHOW COLUMNS FROM $tops LIKE 'typ'", 1);
    if ($typ_def && strpos($typ_def, 'to_aenderung') === false) {
        $wpdb->query("ALTER TABLE $tops MODIFY COLUMN typ ENUM('standard','wahl','svo_teil_a_review','to_aenderung') DEFAULT 'standard'");
    }

    $protokolle = $wpdb->prefix . 'pp_protokolle';
    $cols4 = $wpdb->get_col("SHOW COLUMNS FROM $protokolle", 0);
    if (!in_array('beginn_zeit', $cols4)) {
        $wpdb->query("ALTER TABLE $protokolle ADD COLUMN beginn_zeit DATETIME DEFAULT NULL AFTER anwesenheit");
    }
    if (!in_array('uhrzeit_beginn', $cols4)) {
        $wpdb->query("ALTER TABLE $protokolle ADD COLUMN uhrzeit_beginn TIME DEFAULT NULL AFTER beginn_zeit");
    }
    if (!in_array('uhrzeit_ende', $cols4)) {
        $wpdb->query("ALTER TABLE $protokolle ADD COLUMN uhrzeit_ende TIME DEFAULT NULL AFTER uhrzeit_beginn");
    }

    $termine = $wpdb->prefix . 'pp_termine';
    $cols5 = $wpdb->get_col("SHOW COLUMNS FROM $termine", 0);
    if (!in_array('quelle_protokoll_id', $cols5)) {
        $wpdb->query("ALTER TABLE $termine ADD COLUMN quelle_protokoll_id BIGINT UNSIGNED DEFAULT NULL AFTER quelle_top_id");
    }
}
