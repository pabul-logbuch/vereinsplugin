<?php
defined('ABSPATH') || exit;

// ── CRUD ────────────────────────────────────────────────────────────────────

function jb_budgets_get_all(): array {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT * FROM " . jb_table_budgets() . " WHERE aktiv = 1 ORDER BY id ASC", ARRAY_A
    ) ?: [];

    // Ausgaben, die im Journal direkt auf ein Budget gebucht wurden (Spalte
    // budget_id, v0.21). Bewusst abgeleitet statt in `ausgegeben` mitgezählt:
    // so bleiben nachträgliche Änderungen an einer Buchung konsistent.
    $gebucht = [];
    static $has_bid = null;
    if ($has_bid === null) {
        $has_bid = in_array('budget_id', (array) $wpdb->get_col('SHOW COLUMNS FROM ' . jb_table_journal()), true);
    }
    if ($has_bid) {
        $res = $wpdb->get_results(
            "SELECT budget_id, SUM(ABS(betrag)) AS s FROM " . jb_table_journal() .
            " WHERE budget_id IS NOT NULL AND betrag < 0 GROUP BY budget_id", ARRAY_A
        ) ?: [];
        foreach ($res as $r) { $gebucht[(int) $r['budget_id']] = (float) $r['s']; }
    }

    foreach ($rows as &$r) {
        $r['gebucht']    = round($gebucht[(int) $r['id']] ?? 0, 2);
        $r['verbraucht'] = round((float) $r['ausgegeben'] + $r['gebucht'], 2);
        $r['rest']       = round((float) $r['betrag'] - $r['verbraucht'], 2);
    }
    unset($r);
    return $rows;
}

function jb_budgets_rest_total(): float {
    return round(array_sum(array_column(jb_budgets_get_all(), 'rest')), 2);
}

/** Alle bisher verwendeten Kostenstellen (Budgets + Journal), für Dropdowns. */
function jb_kostenstellen(): array {
    global $wpdb;
    $ks = $wpdb->get_col("SELECT DISTINCT kostenstelle FROM " . jb_table_budgets() . " WHERE kostenstelle <> ''") ?: [];
    if (in_array('kostenstelle', (array) $wpdb->get_col('SHOW COLUMNS FROM ' . jb_table_journal()), true)) {
        $ks = array_merge($ks, $wpdb->get_col("SELECT DISTINCT kostenstelle FROM " . jb_table_journal() . " WHERE kostenstelle <> ''") ?: []);
    }
    $ks = array_values(array_unique(array_filter(array_map('strval', $ks))));
    sort($ks);
    return $ks;
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

    // Vorausplanung: der Bedarf wird für die nächsten N Monate schon
    // zurückgelegt (Einnahmen kommen nur ein paar Mal im Jahr).
    $horizont = max(0, (int) get_option('jb_ruecklagen_horizont_monate', 9));

    foreach ($rows as &$r) {
        $betrag      = (float)$r['betrag'];
        $intervall   = max(1, (int)$r['intervall_monate']);
        $pro_monat   = $betrag / $intervall;

        $letzte = strtotime($r['letzte_zahlung']);
        $monate  = max(0, (time() - $letzte) / (30.44 * 86400));
        $monate  = floor($monate);

        $r['monatlicher_bedarf']  = round($pro_monat, 2);
        $r['monate_seit_zahlung'] = (int)$monate;
        $r['horizont_monate']     = $horizont;
        // Reiner aufgelaufener Bedarf bis heute (auf eine Fälligkeit begrenzt).
        $r['ruecklage_bis_heute'] = round(min($betrag, $pro_monat * $monate), 2);
        // „Bedarf": pro-Monat-Anteil über das Fenster (Monate seit letzter
        // Zahlung + Vorausplanung), gedeckelt auf die Fälligkeiten, die im
        // Fenster tatsächlich anfallen. Monatliche Kosten ⇒ horizont × Betrag,
        // jährliche wachsen anteilig Richtung Jahresbetrag.
        $fenster = max(0, $monate + $horizont);
        $zyklen  = max(1, (int) ceil($fenster / $intervall));
        $r['ruecklage_jetzt']     = round(min($betrag * $zyklen, $pro_monat * $fenster), 2);
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

/**
 * Aktueller Kontostand je Geld-Topf = Anfangsbestand + Summe der Journalbuchungen
 * mit passender „quelle" (ab optionalem Stichtag).
 */
function jb_topf_saldo(string $key): float {
    global $wpdb;
    $map = [
        'bank'   => ["Bank KSK"],
        'kasse'  => ["Zettle-Bar", "Bar"],
        'paypal' => ["PayPal"],
        'zettle' => ["Zettle-Karte"],
    ];
    $quellen = $map[$key] ?? [];
    if (!$quellen) return (float) get_option('jb_anfangsbestand_' . $key, 0);

    // Bevorzugt der buchhalterisch saubere Weg: Saldo des zugehörigen
    // Geldkontos aus der Doppik (berücksichtigt Umbuchungen über gegenkonto
    // und Vorzeichen korrekt, nicht nur die „quelle").
    static $doppik_konto = ['bank' => '1200', 'kasse' => '1000', 'paypal' => '1220', 'zettle' => '1360'];
    if (function_exists('vp_doppik_salden') && isset($doppik_konto[$key])) {
        $konto = $doppik_konto[$key];
        foreach (vp_doppik_salden() as $s) {
            if ($s['konto'] === $konto) {
                return (float) $s['saldo'];
            }
        }
        // Konto (noch) unbewegt: reiner Anfangsbestand.
        $anf = function_exists('vp_doppik_anfangsbestaende') ? vp_doppik_anfangsbestaende() : [];
        return (float) ($anf[$konto] ?? get_option('jb_anfangsbestand_' . $key, 0));
    }

    // Einmalige Migration: alte „aktueller Stand"-Option in einen Anfangsbestand
    // umrechnen, damit der berechnete Saldo weiter stimmt.
    if (get_option('jb_anfangsbestand_migr') !== '1') {
        foreach (['bank' => 'jb_kontostand_bank', 'kasse' => 'jb_kontostand_kasse'] as $k => $alt) {
            $old = get_option($alt, null);
            if ($old !== null && get_option('jb_anfangsbestand_' . $k, null) === null) {
                $qs  = "'" . implode("','", array_map('esc_sql', $map[$k])) . "'";
                $sum = (float) $wpdb->get_var("SELECT COALESCE(SUM(betrag),0) FROM " . jb_table_journal() . " WHERE quelle IN ($qs)");
                update_option('jb_anfangsbestand_' . $k, round((float) $old - $sum, 2));
            }
        }
        update_option('jb_anfangsbestand_migr', '1');
    }

    $anfang  = (float) get_option('jb_anfangsbestand_' . $key, 0);
    $stichtag = sanitize_text_field((string) get_option('jb_anfangsbestand_datum', ''));
    $qs = "'" . implode("','", array_map('esc_sql', $quellen)) . "'";
    $sql = "SELECT COALESCE(SUM(betrag),0) FROM " . jb_table_journal() . " WHERE quelle IN ($qs)";
    if ($stichtag) {
        $sql = $wpdb->prepare($sql . " AND buchung_datum >= %s", $stichtag);
    }
    return round($anfang + (float) $wpdb->get_var($sql), 2);
}

function jb_get_dashboard_data(): array {
    global $wpdb;

    $bank   = jb_topf_saldo('bank');
    $kasse  = jb_topf_saldo('kasse');
    $paypal = jb_topf_saldo('paypal') + jb_topf_saldo('zettle');
    $kontostand = $bank + $kasse + $paypal;

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
        'bank', 'kasse', 'paypal', 'kontostand',
        'ruecklagen', 'verplantes', 'offene_auslagen',
        'getraenke_wert', 'frei'
    );
}
