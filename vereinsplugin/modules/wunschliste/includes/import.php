<?php
defined('ABSPATH') || exit;

/**
 * Erwartete Felder (Spaltennamen bei CSV / Tag-Namen bei XML):
 *
 * titel*        – Pflichtfeld
 * beschreibung
 * begruendung
 * betrag        – Festbetrag (z.B. 49.90)
 * preis_von     – Spannen-Untergrenze
 * preis_bis     – Spannen-Obergrenze
 * kategorie
 * status        – offen | in_bearbeitung | erfuellt
 * prioritaet    – 1 | 2 | 3
 * bild_url
 * link1_label / link1_url / link1_preis
 * link2_label / link2_url / link2_preis
 * link3_label / link3_url / link3_preis
 * (beliebig viele linkN_*-Spalten)
 *
 * XML-Struktur:
 * <wunschliste>
 *   <wunsch>
 *     <titel>...</titel>
 *     <beschreibung>...</beschreibung>
 *     <begruendung>...</begruendung>
 *     <betrag>49.90</betrag>
 *     <preis_von>40</preis_von>
 *     <preis_bis>60</preis_bis>
 *     <kategorie>Sport</kategorie>
 *     <status>offen</status>
 *     <prioritaet>1</prioritaet>
 *     <bild_url>https://...</bild_url>
 *     <links>
 *       <link><label>Amazon</label><url>https://...</url><preis>49.90</preis></link>
 *       <link><label>Decathlon</label><url>https://...</url><preis>44.90</preis></link>
 *     </links>
 *   </wunsch>
 * </wunschliste>
 */

// ─── CSV IMPORT ───────────────────────────────────────────────────────────

function wl_import_csv($file_path) {
    $result = ['imported' => 0, 'skipped' => 0, 'errors' => []];

    if (!file_exists($file_path)) {
        $result['errors'][] = 'Datei nicht gefunden.';
        return $result;
    }

    $handle = fopen($file_path, 'r');
    if (!$handle) {
        $result['errors'][] = 'Datei konnte nicht geöffnet werden.';
        return $result;
    }

    // Trennzeichen automatisch erkennen (Komma oder Semikolon)
    $first_line = fgets($handle);
    rewind($handle);
    $delimiter = (substr_count($first_line, ';') > substr_count($first_line, ',')) ? ';' : ',';

    $header = fgetcsv($handle, 0, $delimiter);
    if (!$header) {
        $result['errors'][] = 'CSV-Header konnte nicht gelesen werden.';
        fclose($handle);
        return $result;
    }

    // Header normalisieren (lowercase, trim, BOM entfernen)
    $header = array_map(function ($h) {
        $h = trim($h);
        $h = str_replace("\xEF\xBB\xBF", '', $h); // UTF-8 BOM
        return strtolower($h);
    }, $header);

    $row_num = 1;
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $row_num++;
        if (count($row) === 1 && trim($row[0]) === '') continue; // leere Zeile

        $data = [];
        foreach ($header as $i => $key) {
            $data[$key] = isset($row[$i]) ? trim($row[$i]) : '';
        }

        $insert_result = wl_import_single_row($data);
        if ($insert_result === true) {
            $result['imported']++;
        } else {
            $result['skipped']++;
            $result['errors'][] = "Zeile $row_num: $insert_result";
        }
    }

    fclose($handle);
    return $result;
}

// ─── XML IMPORT ───────────────────────────────────────────────────────────

function wl_import_xml($file_path) {
    $result = ['imported' => 0, 'skipped' => 0, 'errors' => []];

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

    $wuensche = $xml->wunsch ?? $xml->item ?? $xml->children();
    $i = 0;

    foreach ($wuensche as $node) {
        $i++;
        $data = [];
        foreach ($node->children() as $child) {
            $tag = strtolower($child->getName());
            if ($tag === 'links') continue; // separat behandeln
            $data[$tag] = trim((string) $child);
        }

        // Links extrahieren
        $links = [];
        if (isset($node->links) && isset($node->links->link)) {
            foreach ($node->links->link as $link_node) {
                $links[] = [
                    'label' => (string) ($link_node->label ?? ''),
                    'url'   => (string) ($link_node->url ?? ''),
                    'preis' => (string) ($link_node->preis ?? ''),
                ];
            }
        }
        $data['_links'] = $links;

        $insert_result = wl_import_single_row($data);
        if ($insert_result === true) {
            $result['imported']++;
        } else {
            $result['skipped']++;
            $result['errors'][] = "Eintrag $i: $insert_result";
        }
    }

    return $result;
}

// ─── GEMEINSAME ZEILEN-VERARBEITUNG ──────────────────────────────────────

function wl_import_single_row($data) {
    global $wpdb;
    $table = $wpdb->prefix . 'wunschliste';

    $titel = sanitize_text_field($data['titel'] ?? '');
    if (empty($titel)) {
        return 'Titel fehlt – Zeile übersprungen.';
    }

    $status = strtolower(trim($data['status'] ?? 'offen'));
    if (!in_array($status, ['offen', 'in_bearbeitung', 'erfuellt'])) {
        $status = 'offen';
    }

    $prioritaet = intval($data['prioritaet'] ?? 2);
    if ($prioritaet < 1 || $prioritaet > 3) $prioritaet = 2;

    $insert = [
        'titel'        => $titel,
        'beschreibung' => sanitize_textarea_field($data['beschreibung'] ?? ''),
        'begruendung'  => sanitize_textarea_field($data['begruendung'] ?? ''),
        'betrag'       => !empty($data['betrag']) ? wl_parse_preis($data['betrag']) : 0,
        'preis_von'    => !empty($data['preis_von']) ? wl_parse_preis($data['preis_von']) : null,
        'preis_bis'    => !empty($data['preis_bis']) ? wl_parse_preis($data['preis_bis']) : null,
        'kategorie'    => sanitize_text_field($data['kategorie'] ?? ''),
        'status'       => $status,
        'prioritaet'   => $prioritaet,
        'bild_url'     => !empty($data['bild_url']) ? esc_url_raw($data['bild_url']) : '',
        'erstellt_von' => get_current_user_id(),
    ];

    $ok = $wpdb->insert($table, $insert);
    if ($ok === false) {
        return 'Datenbankfehler: ' . $wpdb->last_error;
    }
    $wunsch_id = $wpdb->insert_id;

    // Links sammeln – entweder aus XML (_links) oder aus CSV (linkN_*)
    $links = [];
    if (!empty($data['_links'])) {
        foreach ($data['_links'] as $l) {
            if (!empty($l['url'])) $links[] = $l;
        }
    } else {
        // CSV: linkN_label, linkN_url, linkN_preis durchsuchen
        $n = 1;
        while (isset($data["link{$n}_url"])) {
            if (!empty($data["link{$n}_url"])) {
                $links[] = [
                    'label' => $data["link{$n}_label"] ?? '',
                    'url'   => $data["link{$n}_url"],
                    'preis' => $data["link{$n}_preis"] ?? '',
                ];
            }
            $n++;
        }
    }

    if (!empty($links)) {
        wl_save_links($wunsch_id, $links);
    }

    return true;
}

function wl_parse_preis($value) {
    // "1.234,56" → 1234.56  /  "49.90" → 49.90  /  "49,90 €" → 49.90
    $value = preg_replace('/[^\d,.\-]/', '', $value);
    if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
        // beide vorhanden → Punkt ist Tausendertrennzeichen
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    } elseif (strpos($value, ',') !== false) {
        $value = str_replace(',', '.', $value);
    }
    return floatval($value);
}

// ─── BEISPIEL-DATEIEN GENERIEREN (für Download im Admin) ────────────────

function wl_get_csv_template() {
    $rows = [
        ['titel','beschreibung','begruendung','betrag','preis_von','preis_bis','kategorie','status','prioritaet','bild_url','link1_label','link1_url','link1_preis','link2_label','link2_url','link2_preis'],
        ['Neue Eckfahnen','4 Eckfahnen für den Sportplatz','Die alten sind verblasst und kaum noch sichtbar','25.00','','','Sport','offen','2','','Decathlon','https://www.decathlon.de/p/eckfahnen','22.99','Amazon','https://www.amazon.de/eckfahnen','25.50'],
        ['Beamer für Vereinsraum','Für Präsentationen und Filmabende','','','350','550','Technik','offen','3','','MediaMarkt','https://www.mediamarkt.de/beamer','449.00','',''],
    ];

    $output = '';
    foreach ($rows as $row) {
        $escaped = array_map(function ($v) {
            if (strpos($v, ';') !== false || strpos($v, '"') !== false) {
                return '"' . str_replace('"', '""', $v) . '"';
            }
            return $v;
        }, $row);
        $output .= implode(';', $escaped) . "\r\n";
    }
    return "\xEF\xBB\xBF" . $output; // mit BOM für Excel-Kompatibilität
}

function wl_get_xml_template() {
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<wunschliste>
    <wunsch>
        <titel>Neue Eckfahnen</titel>
        <beschreibung>4 Eckfahnen für den Sportplatz</beschreibung>
        <begruendung>Die alten sind verblasst und kaum noch sichtbar</begruendung>
        <betrag>25.00</betrag>
        <kategorie>Sport</kategorie>
        <status>offen</status>
        <prioritaet>2</prioritaet>
        <links>
            <link><label>Decathlon</label><url>https://www.decathlon.de/p/eckfahnen</url><preis>22.99</preis></link>
            <link><label>Amazon</label><url>https://www.amazon.de/eckfahnen</url><preis>25.50</preis></link>
        </links>
    </wunsch>
    <wunsch>
        <titel>Beamer für Vereinsraum</titel>
        <beschreibung>Für Präsentationen und Filmabende</beschreibung>
        <preis_von>350</preis_von>
        <preis_bis>550</preis_bis>
        <kategorie>Technik</kategorie>
        <status>offen</status>
        <prioritaet>3</prioritaet>
        <links>
            <link><label>MediaMarkt</label><url>https://www.mediamarkt.de/beamer</url><preis>449.00</preis></link>
        </links>
    </wunsch>
</wunschliste>
XML;
}
