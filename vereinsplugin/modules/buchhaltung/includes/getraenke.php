<?php
defined('ABSPATH') || exit;

// ── PRODUKTE ──────────────────────────────────────────────────────────────────

function jb_getraenke_get_all(): array {
    global $wpdb;
    $t_g = jb_table_getraenke();
    $t_b = jb_table_bewegungen();
    return $wpdb->get_results(
        "SELECT g.*,
                COALESCE(SUM(b.menge), 0) AS bestand,
                COALESCE(SUM(CASE WHEN b.menge > 0 THEN b.menge ELSE 0 END), 0) AS zugang_gesamt,
                COALESCE(SUM(CASE WHEN b.menge < 0 THEN ABS(b.menge) ELSE 0 END), 0) AS abgang_gesamt
         FROM $t_g g
         LEFT JOIN $t_b b ON b.produkt_id = g.id
         WHERE g.aktiv = 1
         GROUP BY g.id
         ORDER BY g.name ASC", ARRAY_A
    ) ?: [];
}

function jb_getraenke_warenwert(): float {
    global $wpdb;
    $t_g = jb_table_getraenke();
    $t_b = jb_table_bewegungen();
    $val = $wpdb->get_var(
        "SELECT SUM(g.preis * COALESCE(sub.bestand, 0))
         FROM $t_g g
         LEFT JOIN (
             SELECT produkt_id, SUM(menge) AS bestand FROM $t_b GROUP BY produkt_id
         ) sub ON sub.produkt_id = g.id
         WHERE g.aktiv = 1 AND g.preis > 0"
    );
    return round((float)($val ?? 0), 2);
}

function jb_produkt_save(array $data): int|false {
    if (!jb_is_kassier()) return false;
    global $wpdb;
    $id  = (int)($data['id'] ?? 0);
    $row = [
        'name'        => sanitize_text_field($data['name'] ?? ''),
        'einheit'     => sanitize_text_field($data['einheit'] ?? 'Stk'),
        'preis'       => (float)str_replace(',', '.', $data['preis'] ?? 0),
        'pfand'       => (float)str_replace(',', '.', $data['pfand'] ?? 0),
        'vollbestand' => (int)($data['vollbestand'] ?? 0),
    ];
    if ($id) { $wpdb->update(jb_table_getraenke(), $row, ['id' => $id]); return $id; }
    $wpdb->insert(jb_table_getraenke(), $row);
    return (int)$wpdb->insert_id;
}

// ── LAGERBEWEGUNGEN ───────────────────────────────────────────────────────────

function jb_bewegung_add(array $data): int|false {
    global $wpdb;
    $wpdb->insert(jb_table_bewegungen(), [
        'produkt_id' => (int)$data['produkt_id'],
        'datum'      => sanitize_text_field($data['datum'] ?? date('Y-m-d')),
        'menge'      => (int)$data['menge'],
        'grund'      => sanitize_text_field($data['grund'] ?? 'korrektur'),
        'referenz'   => sanitize_text_field($data['referenz'] ?? ''),
        'notiz'      => sanitize_textarea_field($data['notiz'] ?? ''),
    ]);
    return (int)$wpdb->insert_id ?: false;
}

/**
 * Lieferung buchen: Array von ['produkt_id' => X, 'menge' => Y]
 */
function jb_lieferung_buchen(array $positionen, string $datum, string $referenz = ''): int {
    $gebucht = 0;
    foreach ($positionen as $pos) {
        if (empty($pos['produkt_id']) || empty($pos['menge'])) continue;
        jb_bewegung_add([
            'produkt_id' => (int)$pos['produkt_id'],
            'datum'      => $datum,
            'menge'      => abs((int)$pos['menge']),
            'grund'      => 'lieferung',
            'referenz'   => $referenz,
            'notiz'      => $pos['notiz'] ?? '',
        ]);
        $gebucht++;
    }
    return $gebucht;
}

/**
 * Inventur-Korrektur: Soll-Bestand wird gesetzt, Differenz gebucht.
 */
function jb_inventur(array $soll_bestaende, string $datum): int {
    $gebucht = 0;
    $produkte = jb_getraenke_get_all();
    $ist = array_column($produkte, 'bestand', 'id');

    foreach ($soll_bestaende as $pid => $soll) {
        $pid  = (int)$pid;
        $soll = (int)$soll;
        $ist_val = (int)($ist[$pid] ?? 0);
        $diff    = $soll - $ist_val;
        if ($diff === 0) continue;

        jb_bewegung_add([
            'produkt_id' => $pid,
            'datum'      => $datum,
            'menge'      => $diff,
            'grund'      => 'korrektur',
            'referenz'   => 'Inventur ' . $datum,
            'notiz'      => 'Inventur: Ist ' . $ist_val . ' → Soll ' . $soll,
        ]);
        $gebucht++;
    }
    return $gebucht;
}

/**
 * Zettle "Umsatz nach Produkt" CSV importieren.
 * CSV-Format: Produkt;Anzahl;... (semikolon-getrennt, erste Zeile Header)
 * Trägt Verkäufe als negative Lagerbewegungen ein.
 */
function jb_import_zettle_csv(string $csv_content, string $datum, string $referenz = ''): array {
    $lines  = array_filter(explode("\n", str_replace("\r", '', $csv_content)));
    $header = str_getcsv(array_shift($lines), ';');

    // Spalten-Index ermitteln (Zettle-Format kann variieren)
    $col_name = array_search('Produkt', $header)
              ?? array_search('Product', $header)
              ?? 0;
    $col_qty  = array_search('Anzahl', $header)
              ?? array_search('Quantity', $header)
              ?? array_search('Sold', $header)
              ?? 1;

    // Alle Produkte laden für Namens-Matching
    global $wpdb;
    $produkte = $wpdb->get_results(
        "SELECT id, name FROM " . jb_table_getraenke() . " WHERE aktiv = 1", ARRAY_A
    );
    $produkt_map = array_column($produkte, 'id', 'name'); // name → id

    $gebucht = 0;
    $nicht_gefunden = [];

    foreach ($lines as $line) {
        $cols = str_getcsv($line, ';');
        if (empty($cols[$col_name])) continue;

        $csv_name = trim($cols[$col_name]);
        $menge    = abs((int)preg_replace('/[^0-9]/', '', $cols[$col_qty] ?? '0'));
        if ($menge === 0) continue;

        // Fuzzy-Match: exakt oder ähnlich
        $pid = jb_find_produkt_by_name($csv_name, $produkt_map);
        if (!$pid) {
            $nicht_gefunden[] = $csv_name . ' (' . $menge . ')';
            continue;
        }

        jb_bewegung_add([
            'produkt_id' => $pid,
            'datum'      => $datum,
            'menge'      => -$menge,  // Abgang!
            'grund'      => 'verkauf',
            'referenz'   => $referenz ?: 'Zettle Import ' . $datum,
            'notiz'      => 'Import aus Zettle CSV: ' . $csv_name,
        ]);
        $gebucht++;
    }

    return ['gebucht' => $gebucht, 'nicht_gefunden' => $nicht_gefunden];
}

function jb_find_produkt_by_name(string $name, array $map): ?int {
    // 1. Exakter Match
    if (isset($map[$name])) return (int)$map[$name];

    // 2. Case-insensitive
    foreach ($map as $pname => $pid) {
        if (strtolower($name) === strtolower($pname)) return (int)$pid;
    }

    // 3. Enthält (z.B. "Halbe (0,5l)" matched "Halbe")
    foreach ($map as $pname => $pid) {
        if (stripos($name, $pname) !== false || stripos($pname, $name) !== false) {
            return (int)$pid;
        }
    }

    return null;
}

/**
 * Automatisch letzte Zahlung für Rücklage updaten wenn entsprechende Buchung eingetragen wird.
 * Wird aus ajax.php nach jb_journal_add() aufgerufen.
 */
function jb_maybe_update_ruecklage(string $beschreibung, string $datum, float $betrag): void {
    if ($betrag >= 0) return; // Nur Ausgaben
    jb_ruecklage_zahlung_gebucht($beschreibung, $datum);
}
