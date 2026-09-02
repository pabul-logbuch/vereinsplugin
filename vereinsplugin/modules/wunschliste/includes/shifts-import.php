<?php
defined('ABSPATH') || exit;

/**
 * Schichtplan-Import-Format (vereinfacht gegenüber komplexen handgepflegten Excel-Tabellen):
 *
 * CSV-Spalten (eine Zeile = eine Schicht):
 * station*        – Name der Station (z.B. "Hauptausschank")
 * station_beschreibung
 * treffpunkt
 * ansprechperson1
 * ansprechperson1_kontakt
 * ansprechperson2
 * ansprechperson2_kontakt
 * schicht_titel    – z.B. "1. Schicht"
 * start_zeit       – Format: YYYY-MM-DD HH:MM
 * end_zeit         – Format: YYYY-MM-DD HH:MM
 * max_plaetze      – Anzahl Personen für diese Schicht
 *
 * Mehrere Zeilen mit demselben "station"-Namen werden automatisch zu einer
 * Station mit mehreren Schichten zusammengefasst (Stations-Infos der ersten
 * Zeile gelten für die ganze Station).
 *
 * XML-Struktur:
 * <schichtplan>
 *   <station>
 *     <titel>Hauptausschank</titel>
 *     <beschreibung>...</beschreibung>
 *     <treffpunkt>Orga-Container</treffpunkt>
 *     <ansprechperson1>Anna</ansprechperson1>
 *     <ansprechperson1_kontakt>0160-1234567</ansprechperson1_kontakt>
 *     <schichten>
 *       <schicht>
 *         <titel>1. Schicht</titel>
 *         <start_zeit>2026-07-10 18:00</start_zeit>
 *         <end_zeit>2026-07-10 21:30</end_zeit>
 *         <max_plaetze>3</max_plaetze>
 *       </schicht>
 *     </schichten>
 *   </station>
 * </schichtplan>
 */

function wl_import_shifts_csv($file_path, $event_id) {
    $result = ['stationen' => 0, 'schichten' => 0, 'errors' => []];

    if (!file_exists($file_path)) {
        $result['errors'][] = 'Datei nicht gefunden.';
        return $result;
    }

    $handle = fopen($file_path, 'r');
    if (!$handle) {
        $result['errors'][] = 'Datei konnte nicht geöffnet werden.';
        return $result;
    }

    $first_line = fgets($handle);
    rewind($handle);
    $delimiter = (substr_count($first_line, ';') > substr_count($first_line, ',')) ? ';' : ',';

    $header = fgetcsv($handle, 0, $delimiter);
    if (!$header) {
        $result['errors'][] = 'CSV-Header konnte nicht gelesen werden.';
        fclose($handle);
        return $result;
    }

    $header = array_map(function ($h) {
        $h = trim($h);
        $h = str_replace("\xEF\xBB\xBF", '', $h);
        return strtolower($h);
    }, $header);

    global $wpdb;
    $station_ids = []; // titel => id (für diesen Import-Lauf)
    $row_num = 1;

    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $row_num++;
        if (count($row) === 1 && trim($row[0]) === '') continue;

        $data = [];
        foreach ($header as $i => $key) {
            $data[$key] = isset($row[$i]) ? trim($row[$i]) : '';
        }

        $station_titel = sanitize_text_field($data['station'] ?? '');
        if (empty($station_titel)) {
            $result['errors'][] = "Zeile $row_num: Spalte 'station' fehlt.";
            continue;
        }

        // Station anlegen, falls noch nicht in diesem Import vorhanden
        if (!isset($station_ids[$station_titel])) {
            $station_table = $wpdb->prefix . 'wl_shift_stationen';
            $wpdb->insert($station_table, [
                'event_id'    => $event_id,
                'titel'       => $station_titel,
                'beschreibung'=> sanitize_textarea_field($data['station_beschreibung'] ?? ''),
                'treffpunkt'  => sanitize_text_field($data['treffpunkt'] ?? ''),
                'ansprechperson1' => sanitize_text_field($data['ansprechperson1'] ?? ''),
                'ansprechperson1_kontakt' => sanitize_text_field($data['ansprechperson1_kontakt'] ?? ''),
                'ansprechperson2' => sanitize_text_field($data['ansprechperson2'] ?? ''),
                'ansprechperson2_kontakt' => sanitize_text_field($data['ansprechperson2_kontakt'] ?? ''),
            ]);
            $station_ids[$station_titel] = $wpdb->insert_id;
            $result['stationen']++;
        }

        $station_id = $station_ids[$station_titel];
        $max_plaetze = !empty($data['max_plaetze']) ? max(1, intval($data['max_plaetze'])) : 1;

        $schicht_table = $wpdb->prefix . 'wl_shift_schichten';
        $wpdb->insert($schicht_table, [
            'station_id'  => $station_id,
            'titel'       => sanitize_text_field($data['schicht_titel'] ?? ''),
            'start_zeit'  => !empty($data['start_zeit']) ? wl_parse_datetime($data['start_zeit']) : null,
            'end_zeit'    => !empty($data['end_zeit']) ? wl_parse_datetime($data['end_zeit']) : null,
            'max_plaetze' => $max_plaetze,
        ]);
        $result['schichten']++;
    }

    fclose($handle);
    return $result;
}

function wl_import_shifts_xml($file_path, $event_id) {
    $result = ['stationen' => 0, 'schichten' => 0, 'errors' => []];

    if (!file_exists($file_path)) {
        $result['errors'][] = 'Datei nicht gefunden.';
        return $result;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($file_path);
    if ($xml === false) {
        $errors = libxml_get_errors();
        $msg = !empty($errors) ? $errors[0]->message : 'Unbekannter XML-Fehler.';
        $result['errors'][] = 'XML konnte nicht gelesen werden: ' . trim($msg);
        return $result;
    }

    global $wpdb;
    $stationen_nodes = $xml->station ?? $xml->children();

    foreach ($stationen_nodes as $node) {
        $titel = sanitize_text_field((string) ($node->titel ?? ''));
        if (empty($titel)) {
            $result['errors'][] = 'Eine Station ohne Titel wurde übersprungen.';
            continue;
        }

        $station_table = $wpdb->prefix . 'wl_shift_stationen';
        $wpdb->insert($station_table, [
            'event_id'    => $event_id,
            'titel'       => $titel,
            'beschreibung'=> sanitize_textarea_field((string) ($node->beschreibung ?? '')),
            'treffpunkt'  => sanitize_text_field((string) ($node->treffpunkt ?? '')),
            'ansprechperson1' => sanitize_text_field((string) ($node->ansprechperson1 ?? '')),
            'ansprechperson1_kontakt' => sanitize_text_field((string) ($node->ansprechperson1_kontakt ?? '')),
            'ansprechperson2' => sanitize_text_field((string) ($node->ansprechperson2 ?? '')),
            'ansprechperson2_kontakt' => sanitize_text_field((string) ($node->ansprechperson2_kontakt ?? '')),
        ]);
        $station_id = $wpdb->insert_id;
        $result['stationen']++;

        if (isset($node->schichten->schicht)) {
            foreach ($node->schichten->schicht as $s) {
                $max_plaetze = !empty((string) $s->max_plaetze) ? max(1, intval((string) $s->max_plaetze)) : 1;
                $schicht_table = $wpdb->prefix . 'wl_shift_schichten';
                $wpdb->insert($schicht_table, [
                    'station_id'  => $station_id,
                    'titel'       => sanitize_text_field((string) ($s->titel ?? '')),
                    'start_zeit'  => !empty((string) $s->start_zeit) ? wl_parse_datetime((string) $s->start_zeit) : null,
                    'end_zeit'    => !empty((string) $s->end_zeit) ? wl_parse_datetime((string) $s->end_zeit) : null,
                    'max_plaetze' => $max_plaetze,
                ]);
                $result['schichten']++;
            }
        }
    }

    return $result;
}

function wl_parse_datetime($value) {
    $ts = strtotime($value);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

// ─── VORLAGEN ──────────────────────────────────────────────────────────────

function wl_get_shift_csv_template() {
    $rows = [
        ['station','station_beschreibung','treffpunkt','ansprechperson1','ansprechperson1_kontakt','ansprechperson2','ansprechperson2_kontakt','schicht_titel','start_zeit','end_zeit','max_plaetze'],
        ['Hauptausschank','Getränke ausschenken und kassieren','Orga-Container','Anna Beispiel','0160-1234567','','','1. Schicht','2026-07-10 18:00','2026-07-10 21:30','3'],
        ['Hauptausschank','Getränke ausschenken und kassieren','Orga-Container','Anna Beispiel','0160-1234567','','','2. Schicht','2026-07-10 21:15','2026-07-11 00:30','3'],
        ['Kasse','Eintritt kassieren','Haupteingang','Max Mustermann','max@example.de','','','1. Schicht','2026-07-10 18:00','2026-07-10 21:30','2'],
    ];
    $output = '';
    foreach ($rows as $row) {
        $output .= implode(';', $row) . "\r\n";
    }
    return "\xEF\xBB\xBF" . $output;
}

function wl_get_shift_xml_template() {
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<schichtplan>
    <station>
        <titel>Hauptausschank</titel>
        <beschreibung>Getränke ausschenken und kassieren</beschreibung>
        <treffpunkt>Orga-Container</treffpunkt>
        <ansprechperson1>Anna Beispiel</ansprechperson1>
        <ansprechperson1_kontakt>0160-1234567</ansprechperson1_kontakt>
        <schichten>
            <schicht>
                <titel>1. Schicht</titel>
                <start_zeit>2026-07-10 18:00</start_zeit>
                <end_zeit>2026-07-10 21:30</end_zeit>
                <max_plaetze>3</max_plaetze>
            </schicht>
            <schicht>
                <titel>2. Schicht</titel>
                <start_zeit>2026-07-10 21:15</start_zeit>
                <end_zeit>2026-07-11 00:30</end_zeit>
                <max_plaetze>3</max_plaetze>
            </schicht>
        </schichten>
    </station>
    <station>
        <titel>Kasse</titel>
        <beschreibung>Eintritt kassieren</beschreibung>
        <treffpunkt>Haupteingang</treffpunkt>
        <ansprechperson1>Max Mustermann</ansprechperson1>
        <schichten>
            <schicht>
                <titel>1. Schicht</titel>
                <start_zeit>2026-07-10 18:00</start_zeit>
                <end_zeit>2026-07-10 21:30</end_zeit>
                <max_plaetze>2</max_plaetze>
            </schicht>
        </schichten>
    </station>
</schichtplan>
XML;
}
