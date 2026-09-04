<?php
defined('ABSPATH') || exit;

// ─── BERECHTIGUNGEN ──────────────────────────────────────────────────────

function pp_can_manage() {
    return current_user_can('pp_manage');
}

function pp_can_lead() {
    // Für Aktionen, die nur Vorstand/Leitungskreis-Ebene betreffen
    // (Bestätigungen abschließen, Freigaben erteilen). V1: identisch mit
    // pp_manage, da wir noch keine feingranulare Gremienzugehörigkeits-
    // prüfung pro Aktion haben. TODO: an konkrete Vorstandsrolle koppeln.
    return current_user_can('pp_manage');
}

// ─── LABELS ──────────────────────────────────────────────────────────────

function pp_gremientyp_label($typ) {
    $labels = [
        'mv'             => 'Mitgliederversammlung',
        'vorstand'       => 'Vorstand',
        'leitungskreis'  => 'Leitungskreis',
        'kreis'          => 'Kreis',
        'kreisversammlung' => 'Kreisversammlung',
    ];
    return $labels[$typ] ?? $typ;
}

function pp_oeffentlichkeit_label($stufe) {
    $labels = [
        'oeffentlich'    => 'Öffentlich',
        'vereinsintern'  => 'Vereinsintern',
        'nur_gremium'    => 'Nur Gremium',
    ];
    return $labels[$stufe] ?? $stufe;
}

/**
 * Alle Entscheidungsverfahren.
 *
 *  art      – 'konsent'  : über Einwände, kein Auszählen
 *             'mehrheit' : Ja/Nein/Enthaltung werden ausgezählt
 *  basis    – woran die Schwelle gemessen wird:
 *             'abgegeben' (Ja+Nein, Enthaltungen zählen nicht mit) oder
 *             'stimmberechtigt' (alle Anwesenden bzw. Stimmberechtigten)
 *  schwelle – nötiger Anteil (0.5 = mehr als die Hälfte)
 */
function pp_verfahren_liste() {
    return [
        'konsent' => [
            'label' => 'Konsent (kein schwerwiegender Einwand)',
            'art' => 'konsent', 'basis' => 'abgegeben', 'schwelle' => 0.0,
            'hinweis' => 'Beschlossen, wenn niemand einen schwerwiegenden Einwand hat.',
        ],
        'systemisches_konsentieren' => [
            'label' => 'Systemisches Konsentieren (geringster Widerstand)',
            'art' => 'widerstand', 'basis' => 'stimmberechtigt', 'schwelle' => 0.0,
            'hinweis' => 'Nicht Zustimmung wird gezählt, sondern Widerstand (0–10 je Person). Der Vorschlag mit dem geringsten Gesamtwiderstand gewinnt.',
        ],
        'einfache_mehrheit' => [
            'label' => 'Einfache Mehrheit (mehr Ja als Nein)',
            'art' => 'mehrheit', 'basis' => 'abgegeben', 'schwelle' => 0.5,
            'hinweis' => 'Enthaltungen zählen nicht mit.',
        ],
        'absolute_mehrheit' => [
            'label' => 'Absolute Mehrheit (mehr als die Hälfte aller Stimmberechtigten)',
            'art' => 'mehrheit', 'basis' => 'stimmberechtigt', 'schwelle' => 0.5,
            'hinweis' => 'Enthaltungen wirken wie Nein-Stimmen.',
        ],
        'zweidrittel' => [
            'label' => 'Zwei-Drittel-Mehrheit',
            'art' => 'mehrheit', 'basis' => 'abgegeben', 'schwelle' => 2 / 3,
            'hinweis' => '',
        ],
        'dreiviertel' => [
            'label' => 'Drei-Viertel-Mehrheit',
            'art' => 'mehrheit', 'basis' => 'abgegeben', 'schwelle' => 0.75,
            'hinweis' => '',
        ],
        'vierfuenftel' => [
            'label' => 'Vier-Fünftel-Mehrheit',
            'art' => 'mehrheit', 'basis' => 'abgegeben', 'schwelle' => 0.8,
            'hinweis' => '',
        ],
        'einstimmig' => [
            'label' => 'Einstimmig (keine Gegenstimme)',
            'art' => 'mehrheit', 'basis' => 'abgegeben', 'schwelle' => 1.0,
            'hinweis' => 'Enthaltungen sind erlaubt, Nein-Stimmen nicht.',
        ],
        'offene_wahl' => [
            'label' => 'Offene Wahl',
            'art' => 'mehrheit', 'basis' => 'abgegeben', 'schwelle' => 0.5,
            'hinweis' => 'Abstimmung per Handzeichen, Ergebnis wird protokolliert.',
        ],
        'geheime_wahl' => [
            'label' => 'Geheime Wahl',
            'art' => 'mehrheit', 'basis' => 'abgegeben', 'schwelle' => 0.5,
            'hinweis' => 'Stimmzettel; hier wird nur das Ergebnis festgehalten.',
        ],
        // Altwert aus früheren Versionen, bleibt lesbar.
        'mehrheit' => [
            'label' => 'Mehrheitsentscheid',
            'art' => 'mehrheit', 'basis' => 'abgegeben', 'schwelle' => 0.5,
            'hinweis' => '',
        ],
    ];
}

function pp_verfahren_label($verfahren) {
    $liste = pp_verfahren_liste();
    return $liste[$verfahren]['label'] ?? $verfahren;
}

/**
 * Wertet eine Abstimmung aus.
 * @return array{basis:int,erforderlich:float,erreicht:bool,text:string}|null
 *         null, wenn das Verfahren nicht ausgezählt wird (Konsent).
 */
function pp_verfahren_ergebnis($verfahren, $ja, $nein, $enthaltung, $stimmberechtigt) {
    $v = pp_verfahren_liste()[$verfahren] ?? null;
    if (!$v || $v['art'] !== 'mehrheit') return null;

    $ja   = max(0, intval($ja));
    $nein = max(0, intval($nein));
    $enth = max(0, intval($enthaltung));
    $ber  = max(0, intval($stimmberechtigt));

    $basis = $v['basis'] === 'stimmberechtigt' ? max($ber, $ja + $nein + $enth) : ($ja + $nein);
    if ($basis <= 0) {
        return ['basis' => 0, 'erforderlich' => 0.0, 'erreicht' => false, 'text' => 'Noch keine Stimmen erfasst.'];
    }

    $erforderlich = $v['schwelle'] * $basis;
    // „Mehr als die Hälfte" heißt echt mehr; bei 2/3, 3/4, 4/5 und einstimmig
    // genügt das Erreichen des Anteils.
    $erreicht = ($v['schwelle'] < 1.0 && abs($v['schwelle'] - 0.5) < 1e-9)
        ? ($ja > $erforderlich)
        : ($ja + 1e-9 >= $erforderlich);
    if ($verfahren === 'einstimmig') $erreicht = ($nein === 0 && $ja > 0);

    $bezeichnung = $v['basis'] === 'stimmberechtigt' ? 'aller Stimmberechtigten' : 'der abgegebenen Stimmen';
    $text = sprintf(
        '%d Ja / %d Nein / %d Enthaltung — nötig: %s von %d (%s). %s',
        $ja, $nein, $enth,
        number_format_i18n($erforderlich, 2), $basis, $bezeichnung,
        $erreicht ? 'Mehrheit erreicht.' : 'Mehrheit nicht erreicht.'
    );
    return ['basis' => $basis, 'erforderlich' => $erforderlich, 'erreicht' => $erreicht, 'text' => $text];
}

function pp_konsent_status_label($status) {
    $labels = [
        'vorstellung'        => '1. Vorstellung',
        'verstaendnisfragen' => '2. Verständnisfragen',
        'meinungsrunde'      => '3. Meinungsrunde',
        'konsentrunde'       => '4. Konsentrunde',
        'einwand_offen'      => '⚠️ Einwand – wird überarbeitet',
        'beschlossen'        => '✅ Beschlossen',
    ];
    return $labels[$status] ?? $status;
}

function pp_top_typ_label($typ) {
    $labels = [
        'standard'          => 'Standard',
        'wahl'              => 'Offene Wahl',
        'svo_teil_a_review' => 'SVO Teil A – Durchsicht',
        'to_aenderung'      => 'Änderung der Tagesordnung',
    ];
    return $labels[$typ] ?? $typ;
}

// ─── ZEITPLANUNG ───────────────────────────────────────────────────────────

/**
 * Rechnet die geplanten Uhrzeiten je TOP aus der Startzeit der Sitzung aus.
 * Liefert [top_id => ['von' => '18:00', 'bis' => '18:15']].
 * Rechnung bewusst in UTC, weil es reine Uhrzeit-Arithmetik ohne Datumsbezug ist.
 */
function pp_top_zeitfenster($tops, $startzeit) {
    $fenster = [];
    if (empty($startzeit)) return $fenster;

    $cursor = strtotime('1970-01-01 ' . $startzeit . ' UTC');
    if ($cursor === false) return $fenster;

    foreach ($tops as $t) {
        $von = $cursor;
        $cursor += max(0, intval($t->dauer_minuten)) * 60;
        $fenster[$t->id] = ['von' => gmdate('H:i', $von), 'bis' => gmdate('H:i', $cursor)];
    }
    return $fenster;
}

/**
 * Vergleicht die Summe der geplanten TOP-Dauern mit dem Zeitfenster der
 * Sitzung (Beginn bis Ende). budget/rest sind null, wenn kein Ende gesetzt ist.
 */
function pp_sitzungsbudget($protokoll, $tops) {
    $geplant = pp_tops_gesamtdauer($tops);
    $budget  = null;

    if (!empty($protokoll->uhrzeit_beginn) && !empty($protokoll->uhrzeit_ende)) {
        $start = strtotime('1970-01-01 ' . $protokoll->uhrzeit_beginn . ' UTC');
        $ende  = strtotime('1970-01-01 ' . $protokoll->uhrzeit_ende . ' UTC');
        if ($start !== false && $ende !== false && $ende > $start) {
            $budget = intval(($ende - $start) / 60);
        }
    }

    return [
        'geplant' => $geplant,
        'budget'  => $budget,
        'rest'    => $budget === null ? null : $budget - $geplant,
    ];
}

// ─── DATENZUGRIFF ──────────────────────────────────────────────────────────

function pp_get_gremien($typ = null, $nur_aktiv = true) {
    global $wpdb;
    $table = $wpdb->prefix . 'pp_gremien';
    $where = [];
    if ($nur_aktiv) $where[] = 'aktiv = 1';
    if ($typ) $where[] = $wpdb->prepare('typ = %s', $typ);
    $sql = "SELECT * FROM $table";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY typ, name';
    return $wpdb->get_results($sql);
}

function pp_get_gremium($id) {
    global $wpdb;
    $table = $wpdb->prefix . 'pp_gremien';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
}

function pp_get_rollen_fuer_gremium($gremium_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'pp_rollen';
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE gremium_id = %d ORDER BY bezeichnung", $gremium_id));
}

function pp_get_protokoll($id) {
    global $wpdb;
    $table = $wpdb->prefix . 'pp_protokolle';
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
}

function pp_get_tops_fuer_protokoll($protokoll_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'pp_tops';
    return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE protokoll_id = %d ORDER BY sortierung, id", $protokoll_id));
}

/** Liefert alle WP-User, die als potenzielle Mitglieder/Rolleninhaber infrage kommen
 *  (nutzt die gleiche Nutzerbasis wie das Wunschliste-Plugin, keine eigene Userverwaltung). */
function pp_get_moegliche_mitglieder() {
    return get_users(['orderby' => 'display_name', 'order' => 'ASC']);
}

function pp_user_display_name($user_id) {
    if (!$user_id) return '–';
    $user = get_userdata($user_id);
    return $user ? $user->display_name : '(gelöschter Nutzer #' . $user_id . ')';
}
