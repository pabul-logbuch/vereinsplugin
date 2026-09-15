<?php
defined('ABSPATH') || exit;

function jb_journal_add(array $data): int {
    global $wpdb;
    // Geldkonto festlegen (aus quelle/gegenkonto, falls nicht mitgegeben),
    // Sphäre und Kategorie aus dem SKR-Konto.
    if (function_exists('vp_bh_buchung_ergaenzen')) {
        $data = vp_bh_buchung_ergaenzen($data);
    }
    $row = [
        'buchung_datum'  => sanitize_text_field($data['buchung_datum'] ?? current_time('Y-m-d')),
        'betrag'         => (float) $data['betrag'],
        'kategorie'      => sanitize_text_field($data['kategorie'] ?? 'Sonstige'),
        'beschreibung'   => sanitize_textarea_field($data['beschreibung'] ?? ''),
        'quelle'         => sanitize_text_field($data['quelle'] ?? 'Manuell'),
        'beleg_referenz' => sanitize_text_field($data['beleg_referenz'] ?? ''),
        'beleg_pfad'     => sanitize_text_field($data['beleg_pfad'] ?? ''),
        'auslage_id'     => !empty($data['auslage_id']) ? (int) $data['auslage_id'] : null,
        'erstellt_von'   => get_current_user_id(),
    ];
    // SKR-Felder nur setzen, wenn die Spalten existieren (Migration gelaufen).
    static $has_skr = null;
    if ($has_skr === null) {
        $cols = $wpdb->get_col('SHOW COLUMNS FROM ' . jb_table_journal());
        $has_skr = in_array('konto', (array) $cols, true);
    }
    if ($has_skr) {
        $row['konto']       = sanitize_text_field($data['konto'] ?? '');
        $row['sphaere']     = sanitize_text_field($data['sphaere'] ?? '');
        $row['gegenpartei'] = sanitize_text_field($data['gegenpartei'] ?? '');
        static $has_gk = null;
        if ($has_gk === null) {
            $has_gk = in_array('gegenkonto', (array) $wpdb->get_col('SHOW COLUMNS FROM ' . jb_table_journal()), true);
        }
        if ($has_gk) {
            $row['gegenkonto'] = sanitize_text_field($data['gegenkonto'] ?? '');
        }
        static $has_geld = null;
        if ($has_geld === null) {
            $has_geld = in_array('geldkonto', (array) $wpdb->get_col('SHOW COLUMNS FROM ' . jb_table_journal()), true);
        }
        if ($has_geld) {
            $row['geldkonto'] = sanitize_text_field($data['geldkonto'] ?? '');
        }
        $beleg_nr = sanitize_text_field($data['beleg_nr'] ?? '');
        if ($beleg_nr === '' && function_exists('jb_next_beleg_nr')) {
            $beleg_nr = jb_next_beleg_nr(substr((string) $row['buchung_datum'], 0, 4));
        }
        $row['beleg_nr'] = $beleg_nr;
        if (empty($row['beleg_referenz']) && $beleg_nr !== '') {
            $row['beleg_referenz'] = $beleg_nr;
        }
    }
    // Optionale Zuordnung zu Budget / Kostenstelle (v0.21).
    static $has_budget = null;
    if ($has_budget === null) {
        $has_budget = in_array('budget_id', (array) $wpdb->get_col('SHOW COLUMNS FROM ' . jb_table_journal()), true);
    }
    if ($has_budget) {
        $row['budget_id']    = !empty($data['budget_id']) ? (int) $data['budget_id'] : null;
        $row['kostenstelle'] = sanitize_text_field($data['kostenstelle'] ?? '');
        // Kostenstelle aus dem Budget übernehmen, wenn nicht ausdrücklich gesetzt.
        if ($row['kostenstelle'] === '' && $row['budget_id'] && function_exists('jb_table_budgets')) {
            $row['kostenstelle'] = (string) $wpdb->get_var($wpdb->prepare(
                'SELECT kostenstelle FROM ' . jb_table_budgets() . ' WHERE id = %d', $row['budget_id']
            ));
        }
    }

    // Optionale Zuordnung zu einer Rücklage.
    static $has_rl = null;
    if ($has_rl === null) {
        $has_rl = in_array('ruecklage_id', (array) $wpdb->get_col('SHOW COLUMNS FROM ' . jb_table_journal()), true);
    }
    $ruecklage_id = !empty($data['ruecklage_id']) ? (int) $data['ruecklage_id'] : 0;
    if ($has_rl) {
        $row['ruecklage_id'] = $ruecklage_id ?: null;
    }

    $wpdb->insert(jb_table_journal(), $row);
    $id = (int) $wpdb->insert_id;

    // „Letzte Zahlung" der Rücklage auf das Buchungsdatum ziehen – explizit
    // (ruecklage_id) oder per Stichwort-Abgleich (Bezeichnung im Text).
    if (function_exists('jb_table_ruecklagen')) {
        if ($ruecklage_id) {
            $wpdb->update(jb_table_ruecklagen(), ['letzte_zahlung' => $row['buchung_datum']], ['id' => $ruecklage_id]);
        } elseif (function_exists('jb_ruecklage_zahlung_gebucht') && (float) $row['betrag'] < 0) {
            jb_ruecklage_zahlung_gebucht(
                strtolower(trim(($row['gegenpartei'] ?? '') . ' ' . $row['beschreibung'] . ' ' . $row['kategorie'])),
                $row['buchung_datum']
            );
        }
    }

    return $id;
}

function jb_journal_get(array $args = []): array {
    global $wpdb;
    $t = jb_table_journal();
    $where = ['1=1']; $params = [];

    if (!empty($args['year'])) {
        $where[] = 'YEAR(buchung_datum) = %d';
        $params[] = (int) $args['year'];
    }
    if (!empty($args['kategorie'])) {
        $where[] = 'kategorie = %s';
        $params[] = $args['kategorie'];
    }
    if (isset($args['typ'])) {
        $where[] = $args['typ'] === 'einnahme' ? 'betrag > 0' : 'betrag < 0';
    }

    $sql = "SELECT * FROM $t WHERE " . implode(' AND ', $where) . " ORDER BY buchung_datum DESC, id DESC";
    if ($params) $sql = $wpdb->prepare($sql, ...$params);
    return $wpdb->get_results($sql, ARRAY_A) ?: [];
}

function jb_journal_summary(int $year): array {
    global $wpdb;
    // Aus den Buchungssätzen: Umbuchungen zwischen Geldkonten sind weder
    // Einnahme noch Ausgabe (früher zählte allein das Vorzeichen).
    if (function_exists('vp_bh_euer')) {
        $e = vp_bh_euer($year);
        $kat = [];
        foreach ($e['pro_konto'] as $k) {
            $kat[] = [
                'kategorie' => $k['konto'] . ($k['name'] ? ' ' . $k['name'] : ''),
                'einnahmen' => $k['einnahmen'],
                'ausgaben'  => $k['ausgaben'],
                'anzahl'    => $k['anzahl'],
            ];
        }
        if ($e['ohne_konto']['anzahl']) {
            $kat[] = ['kategorie' => __('ohne SKR-Konto', 'vereinsplugin'), 'einnahmen' => $e['ohne_konto']['einnahmen'], 'ausgaben' => $e['ohne_konto']['ausgaben'], 'anzahl' => $e['ohne_konto']['anzahl']];
        }
        return [
            'kategorien'      => $kat,
            'total_einnahmen' => $e['einnahmen'],
            'total_ausgaben'  => -$e['ausgaben'],
            'ueberschuss'     => $e['ueberschuss'],
        ];
    }
    $t = jb_table_journal();
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT kategorie,
                SUM(CASE WHEN betrag > 0 THEN betrag ELSE 0 END) as einnahmen,
                SUM(CASE WHEN betrag < 0 THEN ABS(betrag) ELSE 0 END) as ausgaben,
                COUNT(*) as anzahl
         FROM $t WHERE YEAR(buchung_datum) = %d
         GROUP BY kategorie ORDER BY einnahmen DESC, ausgaben DESC", $year
    ), ARRAY_A);

    $total_ein = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(betrag) FROM $t WHERE YEAR(buchung_datum) = %d AND betrag > 0", $year));
    $total_aus = $wpdb->get_var($wpdb->prepare(
        "SELECT SUM(betrag) FROM $t WHERE YEAR(buchung_datum) = %d AND betrag < 0", $year));

    return [
        'kategorien'     => $rows ?: [],
        'total_einnahmen'=> (float) ($total_ein ?? 0),
        'total_ausgaben' => (float) ($total_aus ?? 0),
        'ueberschuss'    => (float) ($total_ein ?? 0) + (float) ($total_aus ?? 0),
    ];
}

function jb_journal_delete(int $id): bool {
    if (!jb_can_journal()) return false;
    global $wpdb;
    return (bool) $wpdb->delete(jb_table_journal(), ['id' => $id]);
}
