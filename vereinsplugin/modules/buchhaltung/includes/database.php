<?php
defined('ABSPATH') || exit;

function jb_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Auslagen-Anträge
    $t_auslagen = $wpdb->prefix . 'jb_auslagen';
    dbDelta("CREATE TABLE IF NOT EXISTS $t_auslagen (
        id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id         BIGINT UNSIGNED NOT NULL,
        ausgabe_datum   DATE NOT NULL,
        betrag          DECIMAL(10,2) NOT NULL,
        kategorie       VARCHAR(100) NOT NULL DEFAULT 'Sonstige Ausgaben',
        beschreibung    TEXT NOT NULL,
        beleg_pfad      VARCHAR(500) DEFAULT '',
        beleg_name      VARCHAR(255) DEFAULT '',
        status          ENUM('ausstehend','genehmigt','abgelehnt','ausgezahlt') DEFAULT 'ausstehend',
        kassier_id      BIGINT UNSIGNED DEFAULT NULL,
        kassier_notiz   TEXT DEFAULT NULL,
        eingereicht_am  DATETIME DEFAULT CURRENT_TIMESTAMP,
        entschieden_am  DATETIME DEFAULT NULL,
        ausgezahlt_am   DATETIME DEFAULT NULL,
        buchung_id      BIGINT UNSIGNED DEFAULT NULL,
        KEY user_id (user_id),
        KEY status (status)
    ) $charset;");

    // EÜR Buchungsjournal
    $t_journal = $wpdb->prefix . 'jb_buchungen';
    dbDelta("CREATE TABLE IF NOT EXISTS $t_journal (
        id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        buchung_datum   DATE NOT NULL,
        betrag          DECIMAL(10,2) NOT NULL COMMENT 'Positiv = Einnahme, Negativ = Ausgabe',
        kategorie       VARCHAR(100) NOT NULL,
        beschreibung    TEXT NOT NULL,
        quelle          ENUM('Bank KSK','Zettle-Bar','Zettle-Karte','Auslage','Manuell') DEFAULT 'Manuell',
        beleg_referenz  VARCHAR(255) DEFAULT '',
        beleg_pfad      VARCHAR(500) DEFAULT '',
        auslage_id      BIGINT UNSIGNED DEFAULT NULL,
        erstellt_am     DATETIME DEFAULT CURRENT_TIMESTAMP,
        erstellt_von    BIGINT UNSIGNED DEFAULT NULL,
        KEY buchung_datum (buchung_datum),
        KEY kategorie (kategorie)
    ) $charset;");

    // Verplantes Budget
    $t_budgets = $wpdb->prefix . 'jb_budgets';
    dbDelta("CREATE TABLE IF NOT EXISTS $t_budgets (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        zweck       VARCHAR(255) NOT NULL,
        beschreibung TEXT,
        betrag      DECIMAL(10,2) NOT NULL DEFAULT 0,
        ausgegeben  DECIMAL(10,2) NOT NULL DEFAULT 0,
        notiz       TEXT,
        aktiv       TINYINT DEFAULT 1,
        erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset;");

    // Rücklagen (wiederkehrende Kosten)
    $t_ruecklagen = $wpdb->prefix . 'jb_ruecklagen';
    dbDelta("CREATE TABLE IF NOT EXISTS $t_ruecklagen (
        id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        bezeichnung       VARCHAR(255) NOT NULL,
        betrag            DECIMAL(10,2) NOT NULL,
        intervall_monate  SMALLINT NOT NULL DEFAULT 12,
        letzte_zahlung    DATE NOT NULL,
        notiz             TEXT,
        aktiv             TINYINT DEFAULT 1
    ) $charset;");

    // Getränke-Produkte
    $t_getraenke = $wpdb->prefix . 'jb_getraenke';
    dbDelta("CREATE TABLE IF NOT EXISTS $t_getraenke (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(255) NOT NULL,
        einheit     VARCHAR(50) DEFAULT 'Stk',
        preis       DECIMAL(10,2) DEFAULT 0,
        pfand       DECIMAL(10,2) DEFAULT 0,
        vollbestand INT DEFAULT 0,
        aktiv       TINYINT DEFAULT 1
    ) $charset;");

    // Getränke-Lagerbewegungen
    $t_bewegungen = $wpdb->prefix . 'jb_getraenke_bewegungen';
    dbDelta("CREATE TABLE IF NOT EXISTS $t_bewegungen (
        id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        produkt_id  BIGINT UNSIGNED NOT NULL,
        datum       DATE NOT NULL,
        menge       INT NOT NULL COMMENT 'positiv=Zugang, negativ=Abgang',
        grund       ENUM('lieferung','verkauf','korrektur','verlust','sonstiges') DEFAULT 'korrektur',
        referenz    VARCHAR(255) DEFAULT '',
        notiz       TEXT,
        erstellt_am DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY produkt_id (produkt_id)
    ) $charset;");
}

// Helper: Tabellennamen
function jb_table_budgets()    { global $wpdb; return $wpdb->prefix . 'jb_budgets'; }
function jb_table_ruecklagen() { global $wpdb; return $wpdb->prefix . 'jb_ruecklagen'; }
function jb_table_getraenke()  { global $wpdb; return $wpdb->prefix . 'jb_getraenke'; }
function jb_table_bewegungen() { global $wpdb; return $wpdb->prefix . 'jb_getraenke_bewegungen'; }

// Helper: Tabellennamen
function jb_table_auslagen()  { global $wpdb; return $wpdb->prefix . 'jb_auslagen'; }
function jb_table_journal()   { global $wpdb; return $wpdb->prefix . 'jb_buchungen'; }

// Helper: Kategorien
function jb_kategorien_ausgaben() {
    return [
        'Getränke-Einkauf',
        'Versicherungen',
        'Internet/Telefon',
        'GEMA',
        'Software/Webling',
        'Müll/Entsorgung',
        'Steuerberatung',
        'Veranstaltungskosten',
        'Material/Einkäufe',
        'Bankgebühren',
        'Sonstige Ausgaben',
    ];
}

function jb_kategorien_einnahmen() {
    return [
        'Getränkeumsatz Bar (Zettle)',
        'Getränkeumsatz Karte (Zettle)',
        'Spenden',
        'Sponsoring/Einnahmen',
        'Förderung/Zuschüsse',
        'Mitgliedsbeiträge',
        'Sonstige Einnahmen',
    ];
}
