<?php
defined('ABSPATH') || exit;

function jb_journal_add(array $data): int {
    global $wpdb;
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
    }
    $wpdb->insert(jb_table_journal(), $row);
    return (int) $wpdb->insert_id;
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
