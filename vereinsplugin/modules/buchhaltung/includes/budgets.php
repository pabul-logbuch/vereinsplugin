<?php
defined('ABSPATH') || exit;

// ── CRUD ────────────────────────────────────────────────────────────────────

function jb_budgets_get_all(): array {
    global $wpdb;
    return $wpdb->get_results(
        "SELECT *, (betrag - ausgegeben) as rest FROM " . jb_table_budgets() .
        " WHERE aktiv = 1 ORDER BY id ASC", ARRAY_A
    ) ?: [];
}

function jb_budgets_rest_total(): float {
    global $wpdb;
    $val = $wpdb->get_var("SELECT SUM(betrag - ausgegeben) FROM " . jb_table_budgets() . " WHERE aktiv = 1");
    return (float)($val ?? 0);
}

function jb_budget_save(array $data): int|false {
    if (!jb_is_kassier()) return false;
    global $wpdb;

    $id  = (int)($data['id'] ?? 0);
    $row = [
        'zweck'       => sanitize_text_field($data['zweck'] ?? ''),
        'beschreibung'=> sanitize_textarea_field($data['beschreibung'] ?? ''),
        'betrag'      => (float)str_replace(',', '.', $data['betrag'] ?? 0),
        'notiz'       => sanitize_textarea_field($data['notiz'] ?? ''),
        'verantwortlich_user_id' => (int)($data['verantwortlich_user_id'] ?? 0) ?: null,
        'jahr'        => (int)($data['jahr'] ?? 0) ?: null,
        'kostenstelle'=> sanitize_text_field($data['kostenstelle'] ?? ''),
        'konto'       => sanitize_text_field($data['konto'] ?? ''),
    ];
    // 'ausgegeben' nur überschreiben, wenn ausdrücklich mitgegeben (sonst
    // wird der per Auslagen-Auszahlung hochgezählte Wert nicht zerstört).
    if (array_key_exists('ausgegeben', $data) && $data['ausgegeben'] !== '') {
        $row['ausgegeben'] = (float)str_replace(',', '.', $data['ausgegeben']);
    }

    if ($id) {
        $wpdb->update(jb_table_budgets(), $row, ['id' => $id]);
        return $id;
    } else {
        $wpdb->insert(jb_table_budgets(), $row);
        return (int)$wpdb->insert_id;
    }
}

function jb_budget_delete(int $id): bool {
    if (!jb_is_kassier()) return false;
    global $wpdb;
    return (bool)$wpdb->update(jb_table_budgets(), ['aktiv' => 0], ['id' => $id]);
}

// ── RÜCKLAGEN ──────────────────────────────────────────────────────────────

function jb_ruecklagen_get_all(): array {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT * FROM " . jb_table_ruecklagen() . " WHERE aktiv = 1 ORDER BY id ASC", ARRAY_A
    ) ?: [];

    foreach ($rows as &$r) {
        $betrag      = (float)$r['betrag'];
        $intervall   = max(1, (int)$r['intervall_monate']);
        $pro_monat   = $betrag / $intervall;

        $letzte = strtotime($r['letzte_zahlung']);
        $monate  = max(0, (time() - $letzte) / (30.44 * 86400));
        $monate  = floor($monate);

        $r['monatlicher_bedarf'] = round($pro_monat, 2);
        $r['monate_seit_zahlung']= (int)$monate;
        $r['ruecklage_jetzt']    = round(min($betrag, $pro_monat * $monate), 2);
        $r['naechste_faelligkeit']= date('Y-m-d', strtotime('+' . $intervall . ' months', $letzte));
    }
    return $rows;
}

function jb_ruecklagen_bedarf_gesamt(): float {
    $rows = jb_ruecklagen_get_all();
    return array_sum(array_column($rows, 'ruecklage_jetzt'));
}

function jb_ruecklage_save(array $data): int|false {
    if (!jb_is_kassier()) return false;
    global $wpdb;
    $id  = (int)($data['id'] ?? 0);
    $row = [
        'bezeichnung'     => sanitize_text_field($data['bezeichnung'] ?? ''),
        'betrag'          => (float)str_replace(',', '.', $data['betrag'] ?? 0),
        'intervall_monate'=> max(1, (int)($data['intervall_monate'] ?? 12)),
        'letzte_zahlung'  => sanitize_text_field($data['letzte_zahlung'] ?? date('Y-m-d')),
        'notiz'           => sanitize_textarea_field($data['notiz'] ?? ''),
    ];
    if ($id) { $wpdb->update(jb_table_ruecklagen(), $row, ['id' => $id]); return $id; }
    $wpdb->insert(jb_table_ruecklagen(), $row);
    return (int)$wpdb->insert_id;
}

function jb_ruecklage_delete(int $id): bool {
    if (!jb_is_kassier()) return false;
    global $wpdb;
    return (bool) $wpdb->delete(jb_table_ruecklagen(), ['id' => $id]);
}

function jb_ruecklage_zahlung_gebucht(string $bezeichnung_suche, string $datum): void {
    // Wenn eine passende Rücklage im Buchungsjournal auftaucht, letzte_zahlung updaten
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT id, bezeichnung FROM " . jb_table_ruecklagen() . " WHERE aktiv = 1", ARRAY_A
    );
    foreach ($rows as $r) {
        if (stripos($bezeichnung_suche, strtolower($r['bezeichnung'])) !== false) {
            $wpdb->update(jb_table_ruecklagen(), ['letzte_zahlung' => $datum], ['id' => $r['id']]);
            break;
        }
    }
}

// ── DASHBOARD ────────────────────────────────────────────────────────────────

function jb_get_dashboard_data(): array {
    global $wpdb;

    $bank  = (float)get_option('jb_kontostand_bank', 0);
    $kasse = (float)get_option('jb_kontostand_kasse', 0);
    $kontostand = $bank + $kasse;

    $ruecklagen        = jb_ruecklagen_bedarf_gesamt();
    $verplantes        = jb_budgets_rest_total();

    // Offene genehmigte Auslagen (noch nicht ausgezahlt)
    $offene_auslagen = (float)($wpdb->get_var(
        "SELECT SUM(betrag) FROM " . jb_table_auslagen() . " WHERE status = 'genehmigt'"
    ) ?? 0);

    // Getränkewert
    $getraenke_wert  = jb_getraenke_warenwert();

    $frei = $kontostand - $ruecklagen - $verplantes - $offene_auslagen;

    return compact(
        'bank', 'kasse', 'kontostand',
        'ruecklagen', 'verplantes', 'offene_auslagen',
        'getraenke_wert', 'frei'
    );
}
