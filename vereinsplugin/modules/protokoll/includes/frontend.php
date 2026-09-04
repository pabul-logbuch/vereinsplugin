<?php
defined('ABSPATH') || exit;

/**
 * [protokollpro_mitgliederbereich]
 * Frontend-Bereich für eingeloggte Mitglieder (Capability pp_manage).
 *
 * Zwei Layout-Modi:
 *  1. Normalmodus — linke Seitenleiste mit Navigation, rechts der Inhalt
 *     (Dashboard, Protokolle inkl. Tagesordnung, Themenspeicher, Aufgaben,
 *     Termine, Kalender).
 *  2. Live-Modus (pp_view=live) — die Navigations-Seitenleiste wird
 *     ausgeblendet und durch eine Sitzungs-Seitenleiste ersetzt: Uhr,
 *     Tagesordnung mit Zeitplan und Restzeit je TOP, Schnellerfassung von
 *     Aufgaben und Terminen. Rechts wird live protokolliert.
 */

// ─── NAVIGATION / URL ──────────────────────────────────────────────────────

function pp_front_url($args = [], $anchor = '') {
    $base = get_permalink();
    if (!$base) $base = home_url(add_query_arg([], $_SERVER['REQUEST_URI'] ?? '/'));
    // Der Kern-Mitgliederbereich hängt hier ?vp_tab=… an, damit interne
    // ProtokollPro-Links den richtigen Tab aktiv halten.
    $base = apply_filters('pp_front_base_url', $base);
    $url = add_query_arg($args, $base);
    return $anchor ? $url . '#' . $anchor : $url;
}

function pp_front_current_view() {
    $view = sanitize_key($_GET['pp_view'] ?? 'dashboard');
    $erlaubt = ['dashboard', 'protokolle', 'protokoll', 'live', 'themen', 'aufgaben', 'termine', 'kalender', 'kreise', 'kreis', 'sets'];
    return in_array($view, $erlaubt, true) ? $view : 'dashboard';
}

function pp_front_redirect($return_url, $args = [], $anchor = '') {
    $url = $return_url ? esc_url_raw($return_url) : home_url('/');
    $url = add_query_arg($args, $url);
    if ($anchor) $url .= '#' . $anchor;
    wp_safe_redirect($url);
    exit;
}

function pp_front_return_field() {
    $current = home_url(add_query_arg([], $_SERVER['REQUEST_URI'] ?? '/'));
    echo '<input type="hidden" name="pp_return" value="' . esc_url($current) . '">';
}

// ─── DATENZUGRIFF ──────────────────────────────────────────────────────────

function pp_get_naechste_termine($limit = 30) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT t.*, g.name AS gremium_name FROM {$wpdb->prefix}pp_termine t
         LEFT JOIN {$wpdb->prefix}pp_gremien g ON g.id = t.gremium_id
         WHERE t.datum >= %s ORDER BY t.datum ASC LIMIT %d",
        current_time('mysql'), $limit
    ));
}

function pp_get_meine_aufgaben($user_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pp_aufgaben WHERE verantwortlich_user_id = %d AND status = 'offen'
         ORDER BY faelligkeitsdatum IS NULL, faelligkeitsdatum",
        $user_id
    ));
}

function pp_get_alle_offenen_aufgaben() {
    global $wpdb;
    return $wpdb->get_results(
        "SELECT a.*, g.name AS gremium_name FROM {$wpdb->prefix}pp_aufgaben a
         LEFT JOIN {$wpdb->prefix}pp_gremien g ON g.id = a.verantwortliches_gremium_id
         WHERE a.status = 'offen' ORDER BY a.faelligkeitsdatum IS NULL, a.faelligkeitsdatum LIMIT 100"
    );
}

function pp_get_geplante_sitzungen() {
    global $wpdb;
    return $wpdb->get_results(
        "SELECT p.*, g.name AS gremium_name FROM {$wpdb->prefix}pp_protokolle p
         LEFT JOIN {$wpdb->prefix}pp_gremien g ON g.id = p.gremium_id
         WHERE p.status = 'entwurf' ORDER BY p.datum IS NULL, p.datum ASC"
    );
}

function pp_get_protokolle_liste($gremium_id = 0, $status = '') {
    global $wpdb;
    $where = [];
    if ($gremium_id) $where[] = $wpdb->prepare('p.gremium_id = %d', $gremium_id);
    if ($status)     $where[] = $wpdb->prepare('p.status = %s', $status);
    $sql = "SELECT p.*, g.name AS gremium_name FROM {$wpdb->prefix}pp_protokolle p
            LEFT JOIN {$wpdb->prefix}pp_gremien g ON g.id = p.gremium_id";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY p.datum IS NULL, p.datum DESC, p.id DESC';
    return $wpdb->get_results($sql);
}

function pp_get_kommentare($protokoll_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pp_kommentare WHERE protokoll_id = %d ORDER BY erstellt_am ASC", $protokoll_id
    ));
}

/** Aufgaben, die während dieser Sitzung erfasst wurden (Schnellerfassung im Live-Modus). */
function pp_get_aufgaben_aus_sitzung($protokoll_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pp_aufgaben WHERE quelle_protokoll_id = %d ORDER BY erstellt_am DESC",
        $protokoll_id
    ));
}

// ─── KREISMITGLIEDSCHAFT ───────────────────────────────────────────────────

function pp_get_kreis_mitglieder($gremium_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pp_kreis_mitglieder
         WHERE gremium_id = %d AND ausgetreten_am IS NULL
         ORDER BY beigetreten_am",
        $gremium_id
    ));
}

function pp_ist_kreis_mitglied($gremium_id, $user_id) {
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}pp_kreis_mitglieder
         WHERE gremium_id = %d AND user_id = %d AND ausgetreten_am IS NULL",
        $gremium_id, $user_id
    ));
}

/** Kreise, in denen die Person mitarbeitet. */
function pp_get_meine_kreise($user_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT g.* FROM {$wpdb->prefix}pp_kreis_mitglieder km
         JOIN {$wpdb->prefix}pp_gremien g ON g.id = km.gremium_id
         WHERE km.user_id = %d AND km.ausgetreten_am IS NULL AND g.aktiv = 1
         ORDER BY g.name",
        $user_id
    ));
}

// ─── AUFGABEN-SETS ─────────────────────────────────────────────────────────

function pp_get_aufgaben_sets($gremium_id = null) {
    global $wpdb;
    if ($gremium_id) {
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pp_aufgaben_sets
             WHERE gremium_id = %d OR gremium_id IS NULL ORDER BY name",
            $gremium_id
        ));
    }
    return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pp_aufgaben_sets ORDER BY name");
}

function pp_get_aufgaben_set($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pp_aufgaben_sets WHERE id = %d", $id));
}

function pp_get_set_eintraege($set_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pp_aufgaben_set_eintraege WHERE set_id = %d ORDER BY vorlauf_tage DESC, sortierung, id",
        $set_id
    ));
}

/** Alle Rollenvorlagen mit Gremiumsnamen — für die Zuordnung in Sets. */
function pp_get_alle_rollenvorlagen() {
    global $wpdb;
    return $wpdb->get_results(
        "SELECT rv.*, g.name AS gremium_name FROM {$wpdb->prefix}pp_rollenvorlagen rv
         LEFT JOIN {$wpdb->prefix}pp_gremien g ON g.id = rv.gremium_id
         ORDER BY g.name, rv.bezeichnung"
    );
}

function pp_get_themen($gremium_id = null) {
    global $wpdb;
    if ($gremium_id === 'ohne') {
        return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pp_themen WHERE gremium_id IS NULL AND status != 'abgeschlossen' ORDER BY titel");
    }
    if ($gremium_id) {
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pp_themen WHERE gremium_id = %d AND status != 'abgeschlossen' ORDER BY titel", $gremium_id
        ));
    }
    return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pp_themen WHERE status != 'abgeschlossen' ORDER BY titel");
}

/** Themen, die zu diesem Protokoll passen: gleicher Kreis oder ohne Kreiszuordnung. */
function pp_get_themen_fuer_protokoll($protokoll) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pp_themen
         WHERE status != 'abgeschlossen' AND (gremium_id = %d OR gremium_id IS NULL)
         ORDER BY gremium_id IS NULL, titel",
        $protokoll->gremium_id
    ));
}

/** Summe der geplanten TOP-Dauern in Minuten. */
function pp_tops_gesamtdauer($tops) {
    $summe = 0;
    foreach ($tops as $t) $summe += intval($t->dauer_minuten);
    return $summe;
}

// ─── TERMIN-AUTOMATIK FÜR GEPLANTE SITZUNGEN ───────────────────────────────

/**
 * Legt für eine geplante Sitzung automatisch einen Termin an bzw.
 * aktualisiert ihn, wenn sich Datum/Titel/Ort ändern. Dadurch taucht jede
 * geplante Sitzung ohne Zusatzaufwand in der Terminliste und im
 * Kalender-Feed auf.
 */
function pp_sync_termin_fuer_protokoll($protokoll_id) {
    global $wpdb;
    $p = pp_get_protokoll($protokoll_id);
    if (!$p) return;

    $bestehend = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pp_termine WHERE quelle_protokoll_id = %d", $protokoll_id
    ));

    // Ohne Datum kein Termin — vorhandenen ggf. entfernen.
    if (empty($p->datum)) {
        if ($bestehend) $wpdb->delete($wpdb->prefix . 'pp_termine', ['id' => $bestehend->id]);
        return;
    }

    $uhrzeit = $p->uhrzeit_beginn ?: '18:00:00';
    $datum   = $p->datum . ' ' . $uhrzeit;

    $daten = [
        'titel'               => $p->titel,
        'datum'               => $datum,
        'ort'                 => $p->ort,
        'gremium_id'          => $p->gremium_id,
        'quelle_protokoll_id' => $protokoll_id,
    ];

    if ($bestehend) {
        $wpdb->update($wpdb->prefix . 'pp_termine', $daten, ['id' => $bestehend->id]);
    } else {
        $wpdb->insert($wpdb->prefix . 'pp_termine', $daten);
    }
}

// ─── FORM-HANDLER ──────────────────────────────────────────────────────────

add_action('admin_post_pp_front_save_protokoll', 'pp_handle_front_save_protokoll');
function pp_handle_front_save_protokoll() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_save_protokoll');
    global $wpdb;

    $id         = intval($_POST['id'] ?? 0);
    $gremium_id = intval($_POST['gremium_id'] ?? 0);
    $gremium    = pp_get_gremium($gremium_id);
    $titel      = sanitize_text_field($_POST['titel'] ?? '');

    if (!$gremium_id || empty($titel)) {
        pp_front_redirect($_POST['pp_return'] ?? '', ['pp_error' => 'Gremium+und+Titel+sind+Pflicht']);
    }

    $daten = [
        'gremium_id'        => $gremium_id,
        'titel'             => $titel,
        'datum'             => !empty($_POST['datum']) ? sanitize_text_field($_POST['datum']) : null,
        'ort'               => sanitize_text_field($_POST['ort'] ?? ''),
        'uhrzeit_beginn'    => !empty($_POST['uhrzeit_beginn']) ? sanitize_text_field($_POST['uhrzeit_beginn']) : null,
        'uhrzeit_ende'      => !empty($_POST['uhrzeit_ende']) ? sanitize_text_field($_POST['uhrzeit_ende']) : null,
        'sichtbarkeit'      => $gremium ? $gremium->oeffentlichkeit : 'vereinsintern',
    ];

    if ($id > 0) {
        $wpdb->update($wpdb->prefix . 'pp_protokolle', $daten, ['id' => $id]);
    } else {
        $daten['erstellt_von'] = get_current_user_id();
        $wpdb->insert($wpdb->prefix . 'pp_protokolle', $daten);
        $id = $wpdb->insert_id;
    }

    pp_sync_termin_fuer_protokoll($id);

    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'protokoll', 'id' => $id, 'pp_saved' => '1']);
}

add_action('admin_post_pp_front_save_protokoll_inhalt', 'pp_handle_front_save_protokoll_inhalt');
function pp_handle_front_save_protokoll_inhalt() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_save_protokoll_inhalt');
    global $wpdb;

    $id = intval($_POST['id'] ?? 0);
    $wpdb->update($wpdb->prefix . 'pp_protokolle', [
        'checkin'           => sanitize_textarea_field($_POST['checkin'] ?? ''),
        'organisatorisches' => sanitize_textarea_field($_POST['organisatorisches'] ?? ''),
        'checkout'          => sanitize_textarea_field($_POST['checkout'] ?? ''),
    ], ['id' => $id]);

    $ziel = sanitize_key($_POST['ziel_view'] ?? 'protokoll');
    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => $ziel, 'id' => $id, 'pp_saved' => '1']);
}

add_action('admin_post_pp_front_add_top', 'pp_handle_front_add_top');
function pp_handle_front_add_top() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_add_top');
    global $wpdb;

    $protokoll_id = intval($_POST['protokoll_id'] ?? 0);
    $protokoll    = pp_get_protokoll($protokoll_id);
    $titel        = sanitize_text_field($_POST['titel'] ?? '');
    if (!$protokoll || empty($titel)) {
        pp_front_redirect($_POST['pp_return'] ?? '', ['pp_error' => 'Titel+fehlt']);
    }
    $gremium = pp_get_gremium($protokoll->gremium_id);

    $max = intval($wpdb->get_var($wpdb->prepare(
        "SELECT MAX(sortierung) FROM {$wpdb->prefix}pp_tops WHERE protokoll_id = %d", $protokoll_id
    )));

    $wpdb->insert($wpdb->prefix . 'pp_tops', [
        'protokoll_id'  => $protokoll_id,
        'titel'         => $titel,
        'beschreibung'  => sanitize_textarea_field($_POST['beschreibung'] ?? ''),
        'dauer_minuten' => max(0, intval($_POST['dauer_minuten'] ?? 15)),
        'typ'           => in_array($_POST['typ'] ?? '', ['standard','wahl','svo_teil_a_review']) ? $_POST['typ'] : 'standard',
        'verfahren'     => $gremium ? $gremium->standardverfahren : 'konsent',
        'thema_id'      => !empty($_POST['thema_id']) ? intval($_POST['thema_id']) : null,
        'sortierung'    => $max + 1,
    ]);

    if (!empty($_POST['thema_id'])) {
        $wpdb->update($wpdb->prefix . 'pp_themen', ['status' => 'in_bearbeitung'], ['id' => intval($_POST['thema_id'])]);
    }

    $ziel = sanitize_key($_POST['ziel_view'] ?? 'protokoll');
    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => $ziel, 'id' => $protokoll_id, 'pp_saved' => '1']);
}

add_action('admin_post_pp_front_delete_top', 'pp_handle_front_delete_top');
function pp_handle_front_delete_top() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_delete_top');
    global $wpdb;
    $id           = intval($_POST['id'] ?? 0);
    $protokoll_id = intval($_POST['protokoll_id'] ?? 0);
    $wpdb->delete($wpdb->prefix . 'pp_tops', ['id' => $id]);
    $ziel = sanitize_key($_POST['ziel_view'] ?? 'protokoll');
    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => $ziel, 'id' => $protokoll_id]);
}

add_action('admin_post_pp_front_save_top_inhalt', 'pp_handle_front_save_top_inhalt');
function pp_handle_front_save_top_inhalt() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_save_top_inhalt');
    global $wpdb;

    $id           = intval($_POST['id'] ?? 0);
    $protokoll_id = intval($_POST['protokoll_id'] ?? 0);
    $wpdb->update($wpdb->prefix . 'pp_tops', [
        'beschreibung' => sanitize_textarea_field($_POST['beschreibung'] ?? ''),
        'beschluss'    => sanitize_textarea_field($_POST['beschluss'] ?? ''),
    ], ['id' => $id]);

    $ziel = sanitize_key($_POST['ziel_view'] ?? 'live');
    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => $ziel, 'id' => $protokoll_id], 'top-' . $id);
}

/* ─── UNTERLAGEN ZU EINEM TOP ───────────────────────────────────────────────
 * Während der Sitzung kommen zu einem TOP oft noch Dinge dazu: ein längerer
 * Text, der Link auf die Nextcloud-Datei oder ein hochgeladenes PDF. Alles
 * drei landet in derselben Liste, damit die Reihenfolge stimmt.
 */

function pp_top_unterlagen_table() {
    global $wpdb;
    return $wpdb->prefix . 'pp_top_unterlagen';
}

/** Unterlagen eines TOPs, älteste zuerst. */
function pp_top_unterlagen($top_id) {
    global $wpdb;
    $t = pp_top_unterlagen_table();
    if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) return [];
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $t WHERE top_id = %d ORDER BY sortierung ASC, id ASC", intval($top_id)
    )) ?: [];
}

add_action('admin_post_pp_front_top_unterlage_add', 'pp_handle_front_top_unterlage_add');
function pp_handle_front_top_unterlage_add() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_top_unterlage');
    global $wpdb;

    $top_id       = intval($_POST['top_id'] ?? 0);
    $protokoll_id = intval($_POST['protokoll_id'] ?? 0);
    $ziel         = sanitize_key($_POST['ziel_view'] ?? 'live');
    $typ          = sanitize_key($_POST['typ'] ?? 'text');
    $titel        = sanitize_text_field($_POST['titel'] ?? '');
    $zurueck      = $_POST['pp_return'] ?? '';

    if (!$top_id || !$protokoll_id) {
        pp_front_redirect($zurueck, ['pp_view' => $ziel, 'id' => $protokoll_id, 'pp_error' => 'TOP+fehlt']);
    }

    $row = [
        'top_id'       => $top_id,
        'protokoll_id' => $protokoll_id,
        'typ'          => in_array($typ, ['text', 'link', 'datei'], true) ? $typ : 'text',
        'titel'        => $titel,
        'inhalt'       => '',
        'url'          => '',
        'erstellt_von' => get_current_user_id(),
        'erstellt_am'  => current_time('mysql'),
    ];

    if ($typ === 'datei') {
        // Datei landet in der WordPress-Mediathek – dort greifen Rechte,
        // Backup und Löschen bereits. Wer lieber Nextcloud nutzt, nimmt „Link".
        if (empty($_FILES['datei']['name'])) {
            pp_front_redirect($zurueck, ['pp_view' => $ziel, 'id' => $protokoll_id, 'pp_error' => 'Keine+Datei+gewählt'], 'top-' . $top_id);
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $anhang_id = media_handle_upload('datei', 0);
        if (is_wp_error($anhang_id)) {
            pp_front_redirect($zurueck, ['pp_view' => $ziel, 'id' => $protokoll_id, 'pp_error' => $anhang_id->get_error_message()], 'top-' . $top_id);
        }
        $row['anhang_id'] = (int) $anhang_id;
        $row['url']       = (string) wp_get_attachment_url($anhang_id);
        if ($row['titel'] === '') {
            $row['titel'] = get_the_title($anhang_id) ?: basename($row['url']);
        }
    } elseif ($typ === 'link') {
        $url = esc_url_raw(trim((string) ($_POST['url'] ?? '')));
        if ($url === '') {
            pp_front_redirect($zurueck, ['pp_view' => $ziel, 'id' => $protokoll_id, 'pp_error' => 'Kein+Link+angegeben'], 'top-' . $top_id);
        }
        $row['url'] = $url;
        if ($row['titel'] === '') $row['titel'] = $url;
    } else {
        $inhalt = wp_kses_post(trim((string) ($_POST['inhalt'] ?? '')));
        if ($inhalt === '') {
            pp_front_redirect($zurueck, ['pp_view' => $ziel, 'id' => $protokoll_id, 'pp_error' => 'Kein+Text+eingegeben'], 'top-' . $top_id);
        }
        $row['inhalt'] = $inhalt;
        if ($row['titel'] === '') $row['titel'] = 'Notiz';
    }

    $t = pp_top_unterlagen_table();
    $row['sortierung'] = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(sortierung),0)+1 FROM $t WHERE top_id = %d", $top_id));
    $wpdb->insert($t, $row);

    pp_front_redirect($zurueck, ['pp_view' => $ziel, 'id' => $protokoll_id], 'top-' . $top_id);
}

add_action('admin_post_pp_front_top_unterlage_delete', 'pp_handle_front_top_unterlage_delete');
function pp_handle_front_top_unterlage_delete() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_top_unterlage_delete');
    global $wpdb;

    $id           = intval($_POST['id'] ?? 0);
    $protokoll_id = intval($_POST['protokoll_id'] ?? 0);
    $top_id       = intval($_POST['top_id'] ?? 0);
    $ziel         = sanitize_key($_POST['ziel_view'] ?? 'live');

    // Die Mediathek-Datei bleibt bewusst liegen: sie kann anderswo verlinkt
    // sein, und ein versehentliches Entfernen wäre nicht rückholbar.
    $wpdb->delete(pp_top_unterlagen_table(), ['id' => $id]);

    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => $ziel, 'id' => $protokoll_id], 'top-' . $top_id);
}

add_action('admin_post_pp_front_top_konsent', 'pp_handle_front_top_konsent');
function pp_handle_front_top_konsent() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_top_konsent');
    global $wpdb;

    $id           = intval($_POST['id'] ?? 0);
    $protokoll_id = intval($_POST['protokoll_id'] ?? 0);
    $aktion       = sanitize_key($_POST['konsent_aktion'] ?? '');
    $table        = $wpdb->prefix . 'pp_tops';
    $top          = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));

    if ($top) {
        $reihenfolge = ['vorstellung', 'verstaendnisfragen', 'meinungsrunde', 'konsentrunde', 'beschlossen'];
        if ($aktion === 'weiter') {
            $pos  = array_search($top->konsent_status, $reihenfolge, true);
            $next = ($pos !== false && isset($reihenfolge[$pos + 1])) ? $reihenfolge[$pos + 1] : $top->konsent_status;
            $wpdb->update($table, ['konsent_status' => $next], ['id' => $id]);
        } elseif ($aktion === 'beschliessen') {
            $wpdb->update($table, [
                'konsent_status' => 'beschlossen',
                'beschluss'      => sanitize_textarea_field($_POST['beschluss'] ?? $top->beschluss),
            ], ['id' => $id]);

            // Beschlossene Tagesordnungsänderung jetzt wirksam werden lassen
            if ($top->typ === 'to_aenderung') {
                $aktualisiert = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
                pp_apply_to_aenderung($aktualisiert);
            }
        } elseif ($aktion === 'einwand') {
            $begruendung = sanitize_textarea_field($_POST['begruendung'] ?? '');
            if ($begruendung) {
                $wpdb->insert($wpdb->prefix . 'pp_einwaende', [
                    'top_id'      => $id,
                    'user_id'     => get_current_user_id(),
                    'begruendung' => $begruendung,
                ]);
                $wpdb->update($table, ['konsent_status' => 'einwand_offen'], ['id' => $id]);
            }
        } elseif ($aktion === 'erneut') {
            $wpdb->update($table, ['konsent_status' => 'konsentrunde'], ['id' => $id]);
            $wpdb->update($wpdb->prefix . 'pp_einwaende', ['status' => 'geklaert'], ['top_id' => $id, 'status' => 'offen']);
        }
    }

    $ziel = sanitize_key($_POST['ziel_view'] ?? 'live');
    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => $ziel, 'id' => $protokoll_id], 'top-' . $id);
}

add_action('admin_post_pp_front_start_live', 'pp_handle_front_start_live');
function pp_handle_front_start_live() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_start_live');
    global $wpdb;
    $id = intval($_POST['id'] ?? 0);
    $p  = pp_get_protokoll($id);
    if ($p && empty($p->beginn_zeit)) {
        $wpdb->update($wpdb->prefix . 'pp_protokolle', ['beginn_zeit' => current_time('mysql')], ['id' => $id]);
    }
    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'live', 'id' => $id]);
}

add_action('admin_post_pp_front_abschliessen', 'pp_handle_front_abschliessen');
function pp_handle_front_abschliessen() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_abschliessen');
    global $wpdb;

    $protokoll_id = intval($_POST['id'] ?? 0);
    $protokoll    = pp_get_protokoll($protokoll_id);
    if (!$protokoll) wp_die('Protokoll nicht gefunden.');

    foreach (pp_get_tops_fuer_protokoll($protokoll_id) as $top) {
        if ($top->konsent_status !== 'beschlossen') continue;

        if ($top->thema_id) {
            $wpdb->update($wpdb->prefix . 'pp_themen', ['status' => 'abgeschlossen'], ['id' => $top->thema_id]);
        }
        if ($top->ist_aufgabe) {
            $wpdb->insert($wpdb->prefix . 'pp_aufgaben', [
                'titel'                       => $top->titel,
                'beschreibung'                => $top->beschluss ?: $top->beschreibung,
                'verantwortlich_user_id'      => $top->aufgabe_verantwortlich_user_id,
                'verantwortliches_gremium_id' => $protokoll->gremium_id,
                'faelligkeitsdatum'           => $top->faelligkeitsdatum,
                'quelle_top_id'               => $top->id,
            ]);
        }
        if ($top->ist_termin && $top->termin_datum) {
            $wpdb->insert($wpdb->prefix . 'pp_termine', [
                'titel'         => $top->titel,
                'datum'         => $top->termin_datum,
                'gremium_id'    => $protokoll->gremium_id,
                'quelle_top_id' => $top->id,
            ]);
        }
        if ($top->erfordert_mv_bestaetigung && $top->bestaetigung_beschluss_typ) {
            $wpdb->insert($wpdb->prefix . 'pp_bestaetigungen', [
                'quelle_gremium_id'  => $protokoll->gremium_id,
                'beschluss_typ'      => $top->bestaetigung_beschluss_typ,
                'beschreibung'       => $top->titel . ' — ' . ($top->beschluss ?: $top->beschreibung),
                'quelle_top_id'      => $top->id,
                'entscheidungsdatum' => $protokoll->datum,
            ]);
        }
    }

    $wpdb->update($wpdb->prefix . 'pp_protokolle', ['status' => 'abgeschlossen'], ['id' => $protokoll_id]);
    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'protokoll', 'id' => $protokoll_id, 'pp_saved' => '1']);
}

add_action('admin_post_pp_front_add_kommentar', 'pp_handle_front_add_kommentar');
function pp_handle_front_add_kommentar() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_add_kommentar');
    global $wpdb;

    $protokoll_id = intval($_POST['protokoll_id'] ?? 0);
    $text         = sanitize_textarea_field($_POST['text'] ?? '');
    if ($protokoll_id && $text) {
        $wpdb->insert($wpdb->prefix . 'pp_kommentare', [
            'protokoll_id' => $protokoll_id,
            'user_id'      => get_current_user_id(),
            'text'         => $text,
        ]);
    }
    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'protokoll', 'id' => $protokoll_id], 'kommentare');
}

add_action('admin_post_pp_front_save_thema', 'pp_handle_front_save_thema');
function pp_handle_front_save_thema() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_save_thema');
    global $wpdb;

    $titel = sanitize_text_field($_POST['titel'] ?? '');
    if (empty($titel)) {
        pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'themen', 'pp_error' => 'Titel+fehlt']);
    }

    $wpdb->insert($wpdb->prefix . 'pp_themen', [
        'titel'        => $titel,
        'beschreibung' => sanitize_textarea_field($_POST['beschreibung'] ?? ''),
        'gremium_id'   => !empty($_POST['gremium_id']) ? intval($_POST['gremium_id']) : null,
        'svo_teil'     => in_array($_POST['svo_teil'] ?? '', ['A','B','C']) ? $_POST['svo_teil'] : '',
        'erstellt_von' => get_current_user_id(),
    ]);

    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'themen', 'pp_saved' => '1']);
}

add_action('admin_post_pp_front_toggle_aufgabe', 'pp_handle_front_toggle_aufgabe');
function pp_handle_front_toggle_aufgabe() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_toggle_aufgabe');
    global $wpdb;
    $id      = intval($_POST['id'] ?? 0);
    $current = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}pp_aufgaben WHERE id=%d", $id));
    $wpdb->update($wpdb->prefix . 'pp_aufgaben', ['status' => $current === 'erledigt' ? 'offen' : 'erledigt'], ['id' => $id]);

    $args = ['pp_view' => sanitize_key($_POST['ziel_view'] ?? 'aufgaben')];
    if (!empty($_POST['protokoll_id'])) $args['id'] = intval($_POST['protokoll_id']);
    pp_front_redirect($_POST['pp_return'] ?? '', $args);
}

add_action('admin_post_pp_front_quick_aufgabe', 'pp_handle_front_quick_aufgabe');
function pp_handle_front_quick_aufgabe() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_quick_aufgabe');
    global $wpdb;

    $protokoll_id = intval($_POST['protokoll_id'] ?? 0);
    $args = ['pp_view' => sanitize_key($_POST['ziel_view'] ?? 'aufgaben')];
    if ($protokoll_id) $args['id'] = $protokoll_id;

    $titel = sanitize_text_field($_POST['titel'] ?? '');
    if ($titel === '') {
        pp_front_redirect($_POST['pp_return'] ?? '', $args + ['pp_error' => 'Aufgabe+braucht+einen+Titel']);
    }

    $ok = $wpdb->insert($wpdb->prefix . 'pp_aufgaben', [
        'titel'                       => $titel,
        'verantwortlich_user_id'      => !empty($_POST['verantwortlich_user_id']) ? intval($_POST['verantwortlich_user_id']) : null,
        'verantwortliches_gremium_id' => !empty($_POST['gremium_id']) ? intval($_POST['gremium_id']) : null,
        'faelligkeitsdatum'           => !empty($_POST['faelligkeitsdatum']) ? sanitize_text_field($_POST['faelligkeitsdatum']) : null,
        'quelle_protokoll_id'         => $protokoll_id ?: null,
    ]);

    if ($ok === false) {
        // Nicht still scheitern lassen — sonst ist die Aufgabe scheinbar spurlos weg.
        pp_front_redirect($_POST['pp_return'] ?? '', $args + ['pp_error' => 'Aufgabe+konnte+nicht+gespeichert+werden+(Datenbankfehler)']);
    }

    pp_front_redirect($_POST['pp_return'] ?? '', $args + ['pp_saved' => 'aufgabe']);
}

add_action('admin_post_pp_front_quick_termin', 'pp_handle_front_quick_termin');
function pp_handle_front_quick_termin() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_quick_termin');
    global $wpdb;

    $protokoll_id = intval($_POST['protokoll_id'] ?? 0);
    $args = ['pp_view' => sanitize_key($_POST['ziel_view'] ?? 'termine')];
    if ($protokoll_id) $args['id'] = $protokoll_id;

    $titel = sanitize_text_field($_POST['titel'] ?? '');
    if ($titel === '') {
        pp_front_redirect($_POST['pp_return'] ?? '', $args + ['pp_error' => 'Termin+braucht+einen+Titel']);
    }

    $ok = $wpdb->insert($wpdb->prefix . 'pp_termine', [
        'titel'      => $titel,
        'datum'      => !empty($_POST['datum']) ? sanitize_text_field($_POST['datum']) : null,
        'ort'        => sanitize_text_field($_POST['ort'] ?? ''),
        'gremium_id' => !empty($_POST['gremium_id']) ? intval($_POST['gremium_id']) : null,
    ]);

    if ($ok === false) {
        pp_front_redirect($_POST['pp_return'] ?? '', $args + ['pp_error' => 'Termin+konnte+nicht+gespeichert+werden+(Datenbankfehler)']);
    }

    pp_front_redirect($_POST['pp_return'] ?? '', $args + ['pp_saved' => 'termin']);
}

add_action('admin_post_pp_front_regenerate_ics', 'pp_handle_front_regenerate_ics');
function pp_handle_front_regenerate_ics() {
    if (!is_user_logged_in()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_regenerate_ics');
    delete_user_meta(get_current_user_id(), 'pp_ics_token');
    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'kalender']);
}

add_action('admin_post_pp_front_update_top', 'pp_handle_front_update_top');
function pp_handle_front_update_top() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_update_top');
    global $wpdb;

    $id           = intval($_POST['id'] ?? 0);
    $protokoll_id = intval($_POST['protokoll_id'] ?? 0);
    $titel        = sanitize_text_field($_POST['titel'] ?? '');

    if ($id && $titel !== '') {
        $wpdb->update($wpdb->prefix . 'pp_tops', [
            'titel'         => $titel,
            'dauer_minuten' => max(0, intval($_POST['dauer_minuten'] ?? 15)),
        ], ['id' => $id]);
    }

    $ziel = sanitize_key($_POST['ziel_view'] ?? 'protokoll');
    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => $ziel, 'id' => $protokoll_id], 'top-' . $id);
}

add_action('admin_post_pp_front_move_top', 'pp_handle_front_move_top');
function pp_handle_front_move_top() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_move_top');
    global $wpdb;

    $id           = intval($_POST['id'] ?? 0);
    $protokoll_id = intval($_POST['protokoll_id'] ?? 0);
    $richtung     = ($_POST['richtung'] ?? '') === 'hoch' ? 'hoch' : 'runter';

    $tops = pp_get_tops_fuer_protokoll($protokoll_id);
    $pos  = null;
    foreach ($tops as $i => $t) {
        if (intval($t->id) === $id) { $pos = $i; break; }
    }

    if ($pos !== null) {
        $nachbar = $richtung === 'hoch' ? ($tops[$pos - 1] ?? null) : ($tops[$pos + 1] ?? null);
        if ($nachbar) {
            // Sortierwerte tauschen; falls beide gleich (Altbestand), neu durchnummerieren.
            if (intval($nachbar->sortierung) === intval($tops[$pos]->sortierung)) {
                foreach ($tops as $i => $t) {
                    $wpdb->update($wpdb->prefix . 'pp_tops', ['sortierung' => $i + 1], ['id' => $t->id]);
                }
                // Nach dem Durchnummerieren hat der eigene TOP pos+1,
                // der Nachbar pos (hoch) bzw. pos+2 (runter) — jetzt tauschen.
                $eigen = $richtung === 'hoch' ? $pos : $pos + 2;
                $fremd = $pos + 1;
            } else {
                $eigen = intval($nachbar->sortierung);
                $fremd = intval($tops[$pos]->sortierung);
            }
            $wpdb->update($wpdb->prefix . 'pp_tops', ['sortierung' => $eigen], ['id' => $id]);
            $wpdb->update($wpdb->prefix . 'pp_tops', ['sortierung' => $fremd], ['id' => $nachbar->id]);
        }
    }

    $ziel = sanitize_key($_POST['ziel_view'] ?? 'protokoll');
    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => $ziel, 'id' => $protokoll_id]);
}

// ─── TAGESORDNUNGSÄNDERUNG PER KONSENT (Live-Modus) ────────────────────────

/**
 * Vorlage für den Beschlussvorschlag. Erzeugt einen vorformulierten Text,
 * der im Konsentverfahren vorgestellt und beschlossen wird. Erst mit dem
 * Beschluss wird die Änderung tatsächlich auf die Tagesordnung angewendet.
 */
function pp_to_aenderung_vorlage($aktion, $details) {
    $zeile = '';
    switch ($aktion) {
        case 'hinzufuegen':
            $zeile = 'Die Tagesordnung wird um den Punkt „' . $details['titel'] . '" (' . intval($details['dauer']) . ' Min.) ergänzt.';
            break;
        case 'entfernen':
            $zeile = 'Der Tagesordnungspunkt „' . $details['ziel_titel'] . '" wird von der Tagesordnung genommen.';
            break;
        case 'dauer':
            $zeile = 'Die geplante Dauer für „' . $details['ziel_titel'] . '" wird auf ' . intval($details['dauer']) . ' Min. geändert.';
            break;
    }

    return "Beschlussvorschlag:\n" . $zeile
        . "\n\nBegründung:\n" . ($details['begruendung'] ?: '(bitte in der Vorstellung ergänzen)')
        . "\n\nHinweis: Die Änderung wird erst wirksam, wenn dieser Punkt im Konsent beschlossen ist.";
}

add_action('admin_post_pp_front_to_aenderung_beantragen', 'pp_handle_front_to_aenderung_beantragen');
function pp_handle_front_to_aenderung_beantragen() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_to_aenderung_beantragen');
    global $wpdb;

    $protokoll_id = intval($_POST['protokoll_id'] ?? 0);
    $protokoll    = pp_get_protokoll($protokoll_id);
    $aktion       = sanitize_key($_POST['aktion'] ?? '');
    if (!$protokoll || !in_array($aktion, ['hinzufuegen', 'entfernen', 'dauer'], true)) {
        pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'live', 'id' => $protokoll_id, 'pp_error' => 'Ungültiger+Antrag']);
    }

    $ziel_top_id = intval($_POST['ziel_top_id'] ?? 0);
    $ziel_titel  = '';
    if ($ziel_top_id) {
        $ziel_titel = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT titel FROM {$wpdb->prefix}pp_tops WHERE id = %d", $ziel_top_id
        ));
    }

    $details = [
        'titel'       => sanitize_text_field($_POST['titel'] ?? ''),
        'dauer'       => max(0, intval($_POST['dauer_minuten'] ?? 15)),
        'ziel_top_id' => $ziel_top_id,
        'ziel_titel'  => $ziel_titel,
        'begruendung' => sanitize_textarea_field($_POST['begruendung'] ?? ''),
    ];

    if (($aktion === 'hinzufuegen' && $details['titel'] === '') ||
        (in_array($aktion, ['entfernen', 'dauer'], true) && !$ziel_top_id)) {
        pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'live', 'id' => $protokoll_id, 'pp_error' => 'Angaben+unvollständig']);
    }

    $titel_map = [
        'hinzufuegen' => 'TO-Änderung: „' . $details['titel'] . '" aufnehmen',
        'entfernen'   => 'TO-Änderung: „' . $ziel_titel . '" streichen',
        'dauer'       => 'TO-Änderung: Dauer „' . $ziel_titel . '" auf ' . $details['dauer'] . ' Min.',
    ];

    $max = intval($wpdb->get_var($wpdb->prepare(
        "SELECT MAX(sortierung) FROM {$wpdb->prefix}pp_tops WHERE protokoll_id = %d", $protokoll_id
    )));

    $wpdb->insert($wpdb->prefix . 'pp_tops', [
        'protokoll_id'       => $protokoll_id,
        'titel'              => $titel_map[$aktion],
        'typ'                => 'to_aenderung',
        'verfahren'          => 'konsent',
        'konsent_status'     => 'vorstellung',
        'beschreibung'       => pp_to_aenderung_vorlage($aktion, $details),
        'dauer_minuten'      => 5,
        'to_aenderung_daten' => wp_json_encode(['aktion' => $aktion] + $details),
        'sortierung'         => $max + 1,
    ]);
    $neuer_top = $wpdb->insert_id;

    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'live', 'id' => $protokoll_id], 'top-' . $neuer_top);
}

/** Wendet eine beschlossene Tagesordnungsänderung an (einmalig). */
function pp_apply_to_aenderung($top) {
    global $wpdb;
    if (!$top || $top->typ !== 'to_aenderung' || $top->to_aenderung_erledigt) return;

    $daten = json_decode((string) $top->to_aenderung_daten, true);
    if (!is_array($daten)) return;

    $aktion = $daten['aktion'] ?? '';
    if ($aktion === 'hinzufuegen') {
        $max = intval($wpdb->get_var($wpdb->prepare(
            "SELECT MAX(sortierung) FROM {$wpdb->prefix}pp_tops WHERE protokoll_id = %d", $top->protokoll_id
        )));
        $wpdb->insert($wpdb->prefix . 'pp_tops', [
            'protokoll_id'  => $top->protokoll_id,
            'titel'         => $daten['titel'],
            'dauer_minuten' => intval($daten['dauer']),
            'verfahren'     => 'konsent',
            'sortierung'    => $max + 1,
        ]);
    } elseif ($aktion === 'entfernen' && !empty($daten['ziel_top_id'])) {
        $wpdb->delete($wpdb->prefix . 'pp_tops', ['id' => intval($daten['ziel_top_id'])]);
    } elseif ($aktion === 'dauer' && !empty($daten['ziel_top_id'])) {
        $wpdb->update($wpdb->prefix . 'pp_tops',
            ['dauer_minuten' => intval($daten['dauer'])],
            ['id' => intval($daten['ziel_top_id'])]
        );
    }

    $wpdb->update($wpdb->prefix . 'pp_tops', ['to_aenderung_erledigt' => 1], ['id' => $top->id]);
}

// ─── FORM-HANDLER: KREISMITGLIEDSCHAFT ─────────────────────────────────────

add_action('admin_post_pp_front_kreis_beitreten', 'pp_handle_front_kreis_beitreten');
function pp_handle_front_kreis_beitreten() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_kreis_beitreten');
    global $wpdb;

    $gremium_id = intval($_POST['gremium_id'] ?? 0);
    // Wer eingetragen wird: standardmäßig man selbst; ein anderes Mitglied
    // nur, wenn ausdrücklich ausgewählt (z. B. beim gemeinsamen Eintragen
    // in der Kreisversammlung).
    $user_id = !empty($_POST['user_id']) ? intval($_POST['user_id']) : get_current_user_id();

    if ($gremium_id && $user_id && !pp_ist_kreis_mitglied($gremium_id, $user_id)) {
        $wpdb->insert($wpdb->prefix . 'pp_kreis_mitglieder', [
            'gremium_id'     => $gremium_id,
            'user_id'        => $user_id,
            'beigetreten_am' => current_time('Y-m-d'),
        ]);
    }

    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'kreis', 'id' => $gremium_id, 'pp_saved' => '1']);
}

add_action('admin_post_pp_front_kreis_verlassen', 'pp_handle_front_kreis_verlassen');
function pp_handle_front_kreis_verlassen() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_kreis_verlassen');
    global $wpdb;

    $gremium_id = intval($_POST['gremium_id'] ?? 0);
    $user_id    = !empty($_POST['user_id']) ? intval($_POST['user_id']) : get_current_user_id();

    // Austritt datieren statt löschen — die Mitarbeitshistorie bleibt erhalten.
    $wpdb->update($wpdb->prefix . 'pp_kreis_mitglieder',
        ['ausgetreten_am' => current_time('Y-m-d')],
        ['gremium_id' => $gremium_id, 'user_id' => $user_id, 'ausgetreten_am' => null]
    );

    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'kreis', 'id' => $gremium_id, 'pp_saved' => '1']);
}

// ─── FORM-HANDLER: REGELMÄSSIGE AUFGABEN EINER ROLLE ───────────────────────

add_action('admin_post_pp_front_save_rollen_aufgabe', 'pp_handle_front_save_rollen_aufgabe');
function pp_handle_front_save_rollen_aufgabe() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_save_rollen_aufgabe');
    global $wpdb;

    $rollenvorlage_id = intval($_POST['rollenvorlage_id'] ?? 0);
    $gremium_id       = intval($_POST['gremium_id'] ?? 0);
    $titel            = sanitize_text_field($_POST['titel'] ?? '');
    $typ              = ($_POST['typ'] ?? '') === 'event' ? 'event' : 'wiederkehrend';

    if ($rollenvorlage_id && $titel !== '') {
        $wpdb->insert($wpdb->prefix . 'pp_rollenvorlagen_aufgaben', [
            'rollenvorlage_id' => $rollenvorlage_id,
            'titel'            => $titel,
            'beschreibung'     => sanitize_textarea_field($_POST['beschreibung'] ?? ''),
            'typ'              => $typ,
            'wiederholung'     => $typ === 'wiederkehrend' && in_array($_POST['wiederholung'] ?? '', ['taeglich','woechentlich','monatlich','jaehrlich'], true) ? $_POST['wiederholung'] : null,
            'vorlauf_tage'     => $typ === 'event' ? max(0, intval($_POST['vorlauf_tage'] ?? 14)) : null,
        ]);
    }

    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'kreis', 'id' => $gremium_id, 'pp_saved' => '1'], 'rolle-' . $rollenvorlage_id);
}

add_action('admin_post_pp_front_delete_rollen_aufgabe', 'pp_handle_front_delete_rollen_aufgabe');
function pp_handle_front_delete_rollen_aufgabe() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_delete_rollen_aufgabe');
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'pp_rollenvorlagen_aufgaben', ['id' => intval($_POST['id'] ?? 0)]);
    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'kreis', 'id' => intval($_POST['gremium_id'] ?? 0), 'pp_saved' => '1']);
}

// ─── FORM-HANDLER: AUFGABEN-SETS ───────────────────────────────────────────

add_action('admin_post_pp_front_save_set', 'pp_handle_front_save_set');
function pp_handle_front_save_set() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_save_set');
    global $wpdb;

    $id   = intval($_POST['id'] ?? 0);
    $name = sanitize_text_field($_POST['name'] ?? '');
    if ($name === '') {
        pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'sets', 'pp_error' => 'Name+fehlt']);
    }

    $daten = [
        'name'         => $name,
        'beschreibung' => sanitize_textarea_field($_POST['beschreibung'] ?? ''),
        'gremium_id'   => !empty($_POST['gremium_id']) ? intval($_POST['gremium_id']) : null,
    ];

    if ($id > 0) {
        $wpdb->update($wpdb->prefix . 'pp_aufgaben_sets', $daten, ['id' => $id]);
    } else {
        $daten['erstellt_von'] = get_current_user_id();
        $wpdb->insert($wpdb->prefix . 'pp_aufgaben_sets', $daten);
        $id = $wpdb->insert_id;
    }

    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'sets', 'set' => $id, 'pp_saved' => '1']);
}

add_action('admin_post_pp_front_delete_set', 'pp_handle_front_delete_set');
function pp_handle_front_delete_set() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_delete_set');
    global $wpdb;
    $id = intval($_POST['id'] ?? 0);
    $wpdb->delete($wpdb->prefix . 'pp_aufgaben_set_eintraege', ['set_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'pp_aufgaben_sets', ['id' => $id]);
    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'sets', 'pp_saved' => '1']);
}

add_action('admin_post_pp_front_save_set_eintrag', 'pp_handle_front_save_set_eintrag');
function pp_handle_front_save_set_eintrag() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_save_set_eintrag');
    global $wpdb;

    $set_id = intval($_POST['set_id'] ?? 0);
    $titel  = sanitize_text_field($_POST['titel'] ?? '');

    if ($set_id && $titel !== '') {
        $wpdb->insert($wpdb->prefix . 'pp_aufgaben_set_eintraege', [
            'set_id'           => $set_id,
            'rollenvorlage_id' => !empty($_POST['rollenvorlage_id']) ? intval($_POST['rollenvorlage_id']) : null,
            'titel'            => $titel,
            'beschreibung'     => sanitize_textarea_field($_POST['beschreibung'] ?? ''),
            'vorlauf_tage'     => max(0, intval($_POST['vorlauf_tage'] ?? 14)),
            'zuweisung'        => ($_POST['zuweisung'] ?? '') === 'alle' ? 'alle' : 'eine',
        ]);
    }

    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'sets', 'set' => $set_id, 'pp_saved' => '1']);
}

add_action('admin_post_pp_front_delete_set_eintrag', 'pp_handle_front_delete_set_eintrag');
function pp_handle_front_delete_set_eintrag() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_delete_set_eintrag');
    global $wpdb;
    $set_id = intval($_POST['set_id'] ?? 0);
    $wpdb->delete($wpdb->prefix . 'pp_aufgaben_set_eintraege', ['id' => intval($_POST['id'] ?? 0)]);
    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'sets', 'set' => $set_id, 'pp_saved' => '1']);
}

/**
 * Wendet ein Aufgaben-Set auf einen Termin an: erzeugt je Eintrag eine
 * Aufgabe, deren Fälligkeit sich aus dem Termin minus Vorlauf ergibt, und
 * weist sie den aktuellen Inhaber:innen der hinterlegten Rolle zu.
 */
add_action('admin_post_pp_front_set_anwenden', 'pp_handle_front_set_anwenden');
function pp_handle_front_set_anwenden() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_set_anwenden');
    global $wpdb;

    $termin_id = intval($_POST['termin_id'] ?? 0);
    $set_id    = intval($_POST['set_id'] ?? 0);
    $termin    = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pp_termine WHERE id = %d", $termin_id));

    if (!$termin || !$termin->datum || !$set_id) {
        pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'termine', 'pp_error' => 'Termin+braucht+ein+Datum']);
    }

    $erzeugt = 0;
    $uebersprungen = 0;

    foreach (pp_get_set_eintraege($set_id) as $e) {
        // Doppelte Anwendung desselben Sets auf denselben Termin verhindern
        $existiert = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}pp_aufgaben
             WHERE quelle_termin_id = %d AND quelle_set_eintrag_id = %d",
            $termin_id, $e->id
        ));
        if ($existiert > 0) { $uebersprungen++; continue; }

        $faellig = date('Y-m-d', strtotime($termin->datum . ' -' . intval($e->vorlauf_tage) . ' days'));

        // Aktuelle Besetzung der Rolle ermitteln
        $besetzungen = $e->rollenvorlage_id ? pp_get_aktuelle_besetzungen($e->rollenvorlage_id) : [];

        // Bei doppelt besetzten Rollen (Sprecher:in, Kassier:in) würde sonst
        // jede Aufgabe zweimal entstehen. Standard ist daher: nur eine Person
        // bekommt die Aufgabe — „alle" nur, wenn ausdrücklich so hinterlegt.
        if ($besetzungen && ($e->zuweisung ?? 'eine') === 'eine') {
            $besetzungen = [$besetzungen[0]];
        }

        if ($besetzungen) {
            foreach ($besetzungen as $b) {
                $wpdb->insert($wpdb->prefix . 'pp_aufgaben', [
                    'titel'                       => $e->titel . ' (' . $termin->titel . ')',
                    'beschreibung'                => $e->beschreibung,
                    'verantwortlich_user_id'      => $b->user_id,
                    'verantwortliches_gremium_id' => $termin->gremium_id,
                    'faelligkeitsdatum'           => $faellig,
                    'quelle_termin_id'            => $termin_id,
                    'quelle_set_eintrag_id'       => $e->id,
                ]);
                $erzeugt++;
            }
        } else {
            // Rolle unbesetzt oder keine Rolle hinterlegt: Aufgabe trotzdem
            // anlegen, damit sie nicht untergeht — nur ohne Zuständige.
            $wpdb->insert($wpdb->prefix . 'pp_aufgaben', [
                'titel'                       => $e->titel . ' (' . $termin->titel . ')',
                'beschreibung'                => $e->beschreibung,
                'verantwortliches_gremium_id' => $termin->gremium_id,
                'faelligkeitsdatum'           => $faellig,
                'quelle_termin_id'            => $termin_id,
                'quelle_set_eintrag_id'       => $e->id,
            ]);
            $erzeugt++;
        }
    }

    $args = ['pp_view' => 'termine', 'pp_set_erzeugt' => $erzeugt];
    if ($uebersprungen) $args['pp_set_uebersprungen'] = $uebersprungen;
    pp_front_redirect($_POST['pp_return'] ?? '', $args);
}

// ─── FORM-HANDLER: KREISE (Teil B — Leitungskreis) ─────────────────────────

/**
 * Kreis anlegen/ändern. Nach § 10 der Satzung ist dafür der Leitungskreis
 * zuständig; die Vollversammlung bestätigt oder revidiert nachträglich.
 * Deshalb wird bei jeder Änderung automatisch ein Eintrag in der
 * Bestätigungs-Queue erzeugt.
 */
add_action('admin_post_pp_front_save_kreis', 'pp_handle_front_save_kreis');
function pp_handle_front_save_kreis() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_save_kreis');
    global $wpdb;

    $id   = intval($_POST['id'] ?? 0);
    $name = sanitize_text_field($_POST['name'] ?? '');
    if (empty($name)) {
        pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'kreise', 'pp_error' => 'Name+fehlt']);
    }

    $daten = [
        'name'              => $name,
        'typ'               => in_array($_POST['typ'] ?? '', ['mv','vorstand','leitungskreis','kreis','kreisversammlung'], true) ? $_POST['typ'] : 'kreis',
        'beschreibung'      => sanitize_textarea_field($_POST['beschreibung'] ?? ''),
        'standardverfahren' => in_array($_POST['standardverfahren'] ?? '', ['konsent','mehrheit','geheime_wahl'], true) ? $_POST['standardverfahren'] : 'konsent',
        'oeffentlichkeit'   => in_array($_POST['oeffentlichkeit'] ?? '', ['oeffentlich','vereinsintern','nur_gremium'], true) ? $_POST['oeffentlichkeit'] : 'vereinsintern',
        'parent_gremium_id' => !empty($_POST['parent_gremium_id']) ? intval($_POST['parent_gremium_id']) : null,
    ];

    if ($id > 0) {
        $wpdb->update($wpdb->prefix . 'pp_gremien', $daten, ['id' => $id]);
        $beschluss_typ = 'kreisaenderung';
    } else {
        $daten['erstellt_von'] = get_current_user_id();
        $wpdb->insert($wpdb->prefix . 'pp_gremien', $daten);
        $id = $wpdb->insert_id;
        $beschluss_typ = 'kreisgruendung';

        // Kreisleitung als Rolle vorbereiten (Teil B der SVO: Bestätigung der Rolle)
        $wpdb->insert($wpdb->prefix . 'pp_rollenvorlagen', [
            'gremium_id'              => $id,
            'bezeichnung'             => 'Kreisleitung',
            'verantwortlich_fuer'     => "Einberufung und Moderation der Kreisversammlung\nVertretung des Kreises im Leitungskreis\nWeitergabe von Informationen in beide Richtungen",
            'benoetigte_faehigkeiten' => "Überblick über die Themen des Kreises\nZuverlässige Kommunikation",
        ]);
    }

    // Leitungskreis-Beschluss zur Bestätigung durch die Vollversammlung vormerken
    $leitungskreis = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}pp_gremien WHERE typ = 'leitungskreis' LIMIT 1");
    $wpdb->insert($wpdb->prefix . 'pp_bestaetigungen', [
        'quelle_gremium_id'  => $leitungskreis ?: null,
        'beschluss_typ'      => $beschluss_typ,
        'beschreibung'       => ($beschluss_typ === 'kreisgruendung' ? 'Kreis eingerichtet: ' : 'Kreis geändert: ') . $name,
        'entscheidungsdatum' => current_time('Y-m-d'),
    ]);

    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'kreis', 'id' => $id, 'pp_saved' => '1']);
}

add_action('admin_post_pp_front_archive_kreis', 'pp_handle_front_archive_kreis');
function pp_handle_front_archive_kreis() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_archive_kreis');
    global $wpdb;

    $id = intval($_POST['id'] ?? 0);
    $g  = pp_get_gremium($id);
    if ($g) {
        // Nicht löschen, sondern deaktivieren — Protokolle und Rollen bleiben nachvollziehbar.
        $wpdb->update($wpdb->prefix . 'pp_gremien', ['aktiv' => 0], ['id' => $id]);
        $leitungskreis = $wpdb->get_var("SELECT id FROM {$wpdb->prefix}pp_gremien WHERE typ = 'leitungskreis' LIMIT 1");
        $wpdb->insert($wpdb->prefix . 'pp_bestaetigungen', [
            'quelle_gremium_id'  => $leitungskreis ?: null,
            'beschluss_typ'      => 'kreisaufloesung',
            'beschreibung'       => 'Kreis aufgelöst: ' . $g->name,
            'entscheidungsdatum' => current_time('Y-m-d'),
        ]);
    }
    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'kreise', 'pp_saved' => '1']);
}

// ─── FORM-HANDLER: ROLLEN (Teil C — jeder Kreis selbst) ────────────────────

add_action('admin_post_pp_front_save_rolle', 'pp_handle_front_save_rolle');
function pp_handle_front_save_rolle() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_save_rolle');
    global $wpdb;

    $gremium_id = intval($_POST['gremium_id'] ?? 0);
    $id         = intval($_POST['id'] ?? 0);
    $bezeichnung = sanitize_text_field($_POST['bezeichnung'] ?? '');

    if (!$gremium_id || empty($bezeichnung)) {
        pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'kreis', 'id' => $gremium_id, 'pp_error' => 'Bezeichnung+fehlt']);
    }

    $daten = [
        'gremium_id'              => $gremium_id,
        'bezeichnung'             => $bezeichnung,
        'verantwortlich_fuer'     => sanitize_textarea_field($_POST['verantwortlich_fuer'] ?? ''),
        'benoetigte_faehigkeiten' => sanitize_textarea_field($_POST['benoetigte_faehigkeiten'] ?? ''),
    ];

    if ($id > 0) {
        $wpdb->update($wpdb->prefix . 'pp_rollenvorlagen', $daten, ['id' => $id]);
    } else {
        $wpdb->insert($wpdb->prefix . 'pp_rollenvorlagen', $daten);
    }

    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'kreis', 'id' => $gremium_id, 'pp_saved' => '1']);
}

add_action('admin_post_pp_front_delete_rolle', 'pp_handle_front_delete_rolle');
function pp_handle_front_delete_rolle() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_delete_rolle');
    global $wpdb;

    $id         = intval($_POST['id'] ?? 0);
    $gremium_id = intval($_POST['gremium_id'] ?? 0);
    // Besetzungen lösen sich von der Vorlage, bleiben aber als Historie erhalten.
    $wpdb->update($wpdb->prefix . 'pp_rollen', ['rollenvorlage_id' => null], ['rollenvorlage_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'pp_rollenvorlagen_aufgaben', ['rollenvorlage_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'pp_rollenvorlagen', ['id' => $id]);

    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'kreis', 'id' => $gremium_id, 'pp_saved' => '1']);
}

add_action('admin_post_pp_front_besetzen', 'pp_handle_front_besetzen');
function pp_handle_front_besetzen() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_besetzen');
    global $wpdb;

    $gremium_id       = intval($_POST['gremium_id'] ?? 0);
    $rollenvorlage_id = intval($_POST['rollenvorlage_id'] ?? 0);
    $user_id          = intval($_POST['user_id'] ?? 0);
    $vorlage          = pp_get_rollenvorlage($rollenvorlage_id);

    if ($vorlage && $user_id) {
        $wpdb->insert($wpdb->prefix . 'pp_rollen', [
            'gremium_id'       => $gremium_id,
            'rollenvorlage_id' => $rollenvorlage_id,
            'bezeichnung'      => $vorlage->bezeichnung,
            'user_id'          => $user_id,
            'amtszeit_start'   => !empty($_POST['amtszeit_start']) ? sanitize_text_field($_POST['amtszeit_start']) : null,
            'amtszeit_ende'    => !empty($_POST['amtszeit_ende']) ? sanitize_text_field($_POST['amtszeit_ende']) : null,
        ]);
    }

    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'kreis', 'id' => $gremium_id, 'pp_saved' => '1']);
}

add_action('admin_post_pp_front_besetzung_beenden', 'pp_handle_front_besetzung_beenden');
function pp_handle_front_besetzung_beenden() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_besetzung_beenden');
    global $wpdb;

    $id         = intval($_POST['id'] ?? 0);
    $gremium_id = intval($_POST['gremium_id'] ?? 0);
    // Amtszeit auf gestern setzen statt löschen — Historie bleibt erhalten.
    $wpdb->update($wpdb->prefix . 'pp_rollen',
        ['amtszeit_ende' => date('Y-m-d', strtotime(current_time('Y-m-d') . ' -1 day'))],
        ['id' => $id]
    );

    pp_front_redirect($_POST['pp_return'] ?? '', ['pp_view' => 'kreis', 'id' => $gremium_id, 'pp_saved' => '1']);
}

// ─── SHORTCODE ──────────────────────────────────────────────────────────

add_shortcode('protokollpro_mitgliederbereich', 'pp_shortcode_mitgliederbereich');
function pp_shortcode_mitgliederbereich($atts) {
    if (!is_user_logged_in()) {
        return '<p>Bitte <a href="' . esc_url(wp_login_url(get_permalink())) . '">einloggen</a>, um den Mitgliederbereich zu sehen.</p>';
    }
    if (!pp_can_manage()) {
        return '<p>Dein Account hat keinen Zugriff auf den ProtokollPro-Mitgliederbereich.</p>';
    }

    $view = pp_front_current_view();
    ob_start();
    echo '<div class="pp-app pp-app-' . esc_attr($view) . '">';

    if ($view === 'live') {
        pp_render_live_modus();
    } else {
        pp_render_nav_sidebar($view);
        echo '<main class="pp-app-main">';
        if (function_exists('pp_render_app_leiste')) pp_render_app_leiste();
        pp_render_notices();
        switch ($view) {
            case 'protokolle': pp_render_view_protokolle(); break;
            case 'protokoll':  pp_render_view_protokoll_detail(); break;
            case 'kreise':     pp_render_view_kreise(); break;
            case 'kreis':      pp_render_view_kreis_detail(); break;
            case 'sets':       pp_render_view_sets(); break;
            case 'themen':     pp_render_view_themen(); break;
            case 'aufgaben':   pp_render_view_aufgaben(); break;
            case 'termine':    pp_render_view_termine(); break;
            case 'kalender':   pp_render_view_kalender(); break;
            default:           pp_render_view_dashboard(); break;
        }
        echo '</main>';
    }

    echo '</div>';
    return ob_get_clean();
}

function pp_render_notices() {
    if (isset($_GET['pp_saved'])) echo '<div class="pp-front-notice pp-front-notice-success">Gespeichert.</div>';
    if (isset($_GET['pp_error']))  echo '<div class="pp-front-notice pp-front-notice-error">' . esc_html(str_replace('+', ' ', $_GET['pp_error'])) . '</div>';
}

/** Visuelle Anzeige des Zeitbudgets einer Sitzung (geplant vs. verfügbar). */
function pp_render_budget_bar($protokoll, $tops) {
    $b = pp_sitzungsbudget($protokoll, $tops);

    if ($b['budget'] === null) {
        echo '<p class="pp-meta">Geplant: ' . intval($b['geplant']) . ' Min. — für eine Budgetanzeige bitte Beginn <em>und</em> Ende der Sitzung eintragen.</p>';
        return;
    }

    $ueberzogen = $b['rest'] < 0;
    $anteil     = $b['budget'] > 0 ? min(100, ($b['geplant'] / $b['budget']) * 100) : 100;
    $ueber_pct  = $ueberzogen && $b['geplant'] > 0 ? min(100, (abs($b['rest']) / $b['geplant']) * 100) : 0;
    ?>
    <div class="pp-budget <?php echo $ueberzogen ? 'is-over' : 'is-ok'; ?>">
        <div class="pp-budget-bar">
            <div class="pp-budget-fill" style="width: <?php echo esc_attr(round($anteil, 1)); ?>%"></div>
            <?php if ($ueberzogen) : ?>
                <div class="pp-budget-over" style="width: <?php echo esc_attr(round($ueber_pct, 1)); ?>%"></div>
            <?php endif; ?>
        </div>
        <div class="pp-budget-text">
            <strong><?php echo intval($b['geplant']); ?> Min.</strong> geplant von <?php echo intval($b['budget']); ?> Min.
            <?php if ($ueberzogen) : ?>
                — <span class="pp-budget-warn"><?php echo abs(intval($b['rest'])); ?> Min. über dem Zeitrahmen</span>
            <?php else : ?>
                — <span class="pp-budget-ok"><?php echo intval($b['rest']); ?> Min. übrig</span>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// ─── SEITENLEISTE (NAVIGATION) ─────────────────────────────────────────────

function pp_render_nav_sidebar($view) {
    global $wpdb;
    $offene_aufgaben = intval($wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}pp_aufgaben WHERE status='offen' AND verantwortlich_user_id = %d",
        get_current_user_id()
    )));
    $entwuerfe = intval($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pp_protokolle WHERE status='entwurf'"));
    $gremien   = pp_get_gremien();

    $punkte = [
        'dashboard'  => ['Übersicht', ''],
        'protokolle' => ['Protokolle', $entwuerfe ? $entwuerfe . ' Entwürfe' : ''],
        'kreise'     => ['Kreise & Rollen', ''],
        'sets'       => ['Aufgaben-Sets', ''],
        'themen'     => ['Themenspeicher', ''],
        'aufgaben'   => ['Aufgaben', $offene_aufgaben ? (string) $offene_aufgaben : ''],
        'termine'    => ['Termine', ''],
        'kalender'   => ['Kalender-Sync', ''],
    ];
    ?>
    <aside class="pp-sidebar">
        <div class="pp-sidebar-head">
            <strong>ProtokollPro</strong>
            <span>Strukturierte Sitzungen</span>
        </div>
        <nav class="pp-sidebar-nav">
            <?php foreach ($punkte as $key => $info) : ?>
                <a href="<?php echo esc_url(pp_front_url(['pp_view' => $key])); ?>"
                   class="pp-sidebar-link <?php echo ($view === $key
                        || ($view === 'protokoll' && $key === 'protokolle')
                        || ($view === 'kreis' && $key === 'kreise')) ? 'is-active' : ''; ?>">
                    <span><?php echo esc_html($info[0]); ?></span>
                    <?php if ($info[1]) : ?><span class="pp-sidebar-badge"><?php echo esc_html($info[1]); ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($gremien) : ?>
            <div class="pp-sidebar-section">Kreise &amp; Gremien</div>
            <nav class="pp-sidebar-nav">
                <?php foreach ($gremien as $g) : ?>
                    <a href="<?php echo esc_url(pp_front_url(['pp_view' => 'kreis', 'id' => $g->id])); ?>"
                       class="pp-sidebar-link pp-sidebar-link-sub <?php echo (intval($_GET['id'] ?? 0) === intval($g->id) && $view === 'kreis') ? 'is-active' : ''; ?>">
                        <span><?php echo esc_html($g->name); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <?php
        // Nextcloud-Apps (Talk, Dateien, Kalender) und Installationshinweis
        if (function_exists('pp_render_app_leiste')) {
            $nc_links = pp_pwa_app_links();
            if ($nc_links) {
                echo '<div class="pp-sidebar-section">Nextcloud</div>';
                echo '<nav class="pp-sidebar-nav">';
                foreach ($nc_links as $l) {
                    echo '<a class="pp-sidebar-link pp-sidebar-link-sub" target="_blank" rel="noopener" href="' . esc_url($l['url']) . '">'
                       . '<span>' . esc_html($l['icon'] . ' ' . $l['label']) . '</span></a>';
                }
                echo '</nav>';
            }
        }
        ?>
    </aside>
    <?php
}

// ─── ANSICHT: ÜBERSICHT ────────────────────────────────────────────────────

function pp_render_view_dashboard() {
    $user_id        = get_current_user_id();
    $meine_aufgaben = pp_get_meine_aufgaben($user_id);
    $sitzungen      = pp_get_geplante_sitzungen();
    $termine        = pp_get_naechste_termine(5);
    ?>
    <h2>Übersicht</h2>

    <div class="pp-cards">
        <div class="pp-card">
            <h3>Geplante Sitzungen</h3>
            <?php if ($sitzungen) : ?>
                <ul class="pp-list">
                    <?php foreach (array_slice($sitzungen, 0, 5) as $s) :
                        $tops = pp_get_tops_fuer_protokoll($s->id); ?>
                        <li>
                            <a href="<?php echo esc_url(pp_front_url(['pp_view' => 'protokoll', 'id' => $s->id])); ?>"><?php echo esc_html($s->titel); ?></a>
                            <span class="pp-meta"><?php echo esc_html($s->gremium_name); ?> · <?php echo esc_html($s->datum ?: 'Termin offen'); ?> · <?php echo count($tops); ?> TOPs</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?><p class="pp-empty">Keine geplanten Sitzungen.</p><?php endif; ?>
        </div>

        <div class="pp-card">
            <h3>Meine offenen Aufgaben</h3>
            <?php if ($meine_aufgaben) : ?>
                <ul class="pp-list">
                    <?php foreach (array_slice($meine_aufgaben, 0, 6) as $a) : ?>
                        <li><?php echo esc_html($a->titel); ?>
                            <span class="pp-meta"><?php echo esc_html($a->faelligkeitsdatum ?: 'ohne Frist'); ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?><p class="pp-empty">Nichts offen.</p><?php endif; ?>
        </div>

        <div class="pp-card">
            <h3>Meine Kreise</h3>
            <?php $meine_kreise = pp_get_meine_kreise($user_id); ?>
            <?php if ($meine_kreise) : ?>
                <ul class="pp-list">
                    <?php foreach ($meine_kreise as $k) : ?>
                        <li><a href="<?php echo esc_url(pp_front_url(['pp_view' => 'kreis', 'id' => $k->id])); ?>"><?php echo esc_html($k->name); ?></a>
                            <span class="pp-meta"><?php echo esc_html(pp_gremientyp_label($k->typ)); ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p class="pp-empty">Du arbeitest noch in keinem Kreis mit.</p>
                <p><a class="pp-btn pp-btn-small" href="<?php echo esc_url(pp_front_url(['pp_view' => 'kreise'])); ?>">Kreise ansehen</a></p>
            <?php endif; ?>
        </div>

        <div class="pp-card">
            <h3>Nächste Termine</h3>
            <?php if ($termine) : ?>
                <ul class="pp-list">
                    <?php foreach ($termine as $t) : ?>
                        <li><?php echo esc_html($t->titel); ?>
                            <span class="pp-meta"><?php echo esc_html(mysql2date('d.m.Y H:i', $t->datum)); ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?><p class="pp-empty">Keine Termine.</p><?php endif; ?>
        </div>
    </div>
    <?php
}

// ─── ANSICHT: PROTOKOLLE ───────────────────────────────────────────────────

function pp_render_view_protokolle() {
    $gremium_id = intval($_GET['gremium'] ?? 0);
    $gremien    = pp_get_gremien();
    $sitzungen  = pp_get_geplante_sitzungen();
    $alle       = pp_get_protokolle_liste($gremium_id);
    ?>
    <div class="pp-page-head">
        <h2>Protokolle<?php if ($gremium_id) { $g = pp_get_gremium($gremium_id); echo $g ? ' — ' . esc_html($g->name) : ''; } ?></h2>
        <a class="pp-btn" href="#pp-neues-protokoll">Neues Protokoll</a>
    </div>

    <h3>Geplante Sitzungen &amp; Tagesordnung</h3>
    <?php if (empty($sitzungen)) : ?>
        <p class="pp-empty">Keine geplanten Sitzungen (Protokolle im Entwurf).</p>
    <?php endif; ?>

    <?php foreach ($sitzungen as $s) :
        if ($gremium_id && intval($s->gremium_id) !== $gremium_id) continue;
        $tops    = pp_get_tops_fuer_protokoll($s->id);
        $fenster = pp_top_zeitfenster($tops, $s->uhrzeit_beginn);
        $themen  = pp_get_themen_fuer_protokoll($s);
    ?>
        <div class="pp-session">
            <div class="pp-session-head">
                <div>
                    <strong><?php echo esc_html($s->titel); ?></strong>
                    <span class="pp-meta"><?php echo esc_html($s->gremium_name); ?> · <?php echo esc_html($s->datum ?: 'Termin offen'); ?><?php
                        if ($s->uhrzeit_beginn) {
                            echo ' · ' . esc_html(substr($s->uhrzeit_beginn, 0, 5));
                            if ($s->uhrzeit_ende) echo '–' . esc_html(substr($s->uhrzeit_ende, 0, 5));
                            echo ' Uhr';
                        }
                    ?></span>
                </div>
                <div class="pp-session-actions">
                    <a class="pp-btn pp-btn-small" href="<?php echo esc_url(pp_front_url(['pp_view' => 'protokoll', 'id' => $s->id])); ?>">Details</a>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                        <?php wp_nonce_field('pp_front_start_live'); ?>
                        <input type="hidden" name="action" value="pp_front_start_live">
                        <input type="hidden" name="id" value="<?php echo esc_attr($s->id); ?>">
                        <?php pp_front_return_field(); ?>
                        <button type="submit" class="pp-btn pp-btn-small pp-btn-primary">Live protokollieren</button>
                    </form>
                </div>
            </div>

            <?php pp_render_budget_bar($s, $tops); ?>

            <?php if ($tops) : ?>
                <ol class="pp-agenda">
                    <?php foreach ($tops as $t) : ?>
                        <li>
                            <span class="pp-agenda-zeit"><?php
                                echo isset($fenster[$t->id])
                                    ? esc_html($fenster[$t->id]['von'] . '–' . $fenster[$t->id]['bis'])
                                    : esc_html(intval($t->dauer_minuten) . ' Min.');
                            ?></span>
                            <span class="pp-agenda-title"><?php echo esc_html($t->titel); ?></span>
                            <span class="pp-meta"><?php echo intval($t->dauer_minuten); ?> Min. · <?php echo esc_html(pp_konsent_status_label($t->konsent_status)); ?></span>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline">
                                <?php wp_nonce_field('pp_front_delete_top'); ?>
                                <input type="hidden" name="action" value="pp_front_delete_top">
                                <input type="hidden" name="id" value="<?php echo esc_attr($t->id); ?>">
                                <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($s->id); ?>">
                                <input type="hidden" name="ziel_view" value="protokolle">
                                <?php pp_front_return_field(); ?>
                                <button type="submit" class="pp-link-danger" onclick="return confirm('TOP entfernen?')">entfernen</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php else : ?>
                <p class="pp-empty">Noch keine Tagesordnungspunkte.</p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline-form">
                <?php wp_nonce_field('pp_front_add_top'); ?>
                <input type="hidden" name="action" value="pp_front_add_top">
                <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($s->id); ?>">
                <input type="hidden" name="ziel_view" value="protokolle">
                <?php pp_front_return_field(); ?>
                <input type="text" name="titel" placeholder="Neuer TOP…" required>
                <select name="thema_id">
                    <option value="">— oder Thema übernehmen —</option>
                    <?php foreach ($themen as $th) : ?>
                        <option value="<?php echo esc_attr($th->id); ?>"><?php echo esc_html($th->titel); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="dauer_minuten" value="15" min="0" step="5" style="width:80px" title="Dauer in Minuten">
                <button type="submit" class="pp-btn pp-btn-small">TOP hinzufügen</button>
            </form>
        </div>
    <?php endforeach; ?>

    <h3>Alle Protokolle</h3>
    <table class="pp-table">
        <thead><tr><th>Titel</th><th>Gremium</th><th>Datum</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($alle as $p) : ?>
            <tr>
                <td><a href="<?php echo esc_url(pp_front_url(['pp_view' => 'protokoll', 'id' => $p->id])); ?>"><?php echo esc_html($p->titel); ?></a></td>
                <td><?php echo esc_html($p->gremium_name); ?></td>
                <td><?php echo esc_html($p->datum ?: '–'); ?></td>
                <td><?php echo $p->status === 'abgeschlossen' ? 'Abgeschlossen' : 'Entwurf'; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($alle)) : ?><tr><td colspan="4" class="pp-empty">Noch keine Protokolle.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <h3 id="pp-neues-protokoll">Neues Protokoll anlegen</h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-form pp-form-grid">
        <?php wp_nonce_field('pp_front_save_protokoll'); ?>
        <input type="hidden" name="action" value="pp_front_save_protokoll">
        <?php pp_front_return_field(); ?>
        <label>Gremium
            <select name="gremium_id" required>
                <option value="">— wählen —</option>
                <?php foreach ($gremien as $g) : ?>
                    <option value="<?php echo esc_attr($g->id); ?>" <?php selected($gremium_id, $g->id); ?>><?php echo esc_html($g->name); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Titel <input type="text" name="titel" required></label>
        <label>Datum <input type="date" name="datum"></label>
        <label>Beginn <input type="time" name="uhrzeit_beginn"></label>
        <label>Ende <input type="time" name="uhrzeit_ende"></label>
        <label>Ort <input type="text" name="ort"></label>
        <div class="pp-form-actions">
            <button type="submit" class="pp-btn pp-btn-primary">Anlegen</button>
            <span class="pp-meta">Mit Datum wird automatisch ein Termin erzeugt.</span>
        </div>
    </form>
    <?php
}

// ─── ANSICHT: EINZELNES PROTOKOLL ──────────────────────────────────────────

function pp_render_view_protokoll_detail() {
    $id = intval($_GET['id'] ?? 0);
    $p  = pp_get_protokoll($id);
    if (!$p) { echo '<p class="pp-empty">Protokoll nicht gefunden.</p>'; return; }

    $gremium    = pp_get_gremium($p->gremium_id);
    $tops       = pp_get_tops_fuer_protokoll($id);
    $themen     = pp_get_themen_fuer_protokoll($p);
    $kommentare = pp_get_kommentare($id);
    $gremien    = pp_get_gremien();
    ?>
    <div class="pp-page-head">
        <h2><?php echo esc_html($p->titel); ?></h2>
        <?php if ($p->status !== 'abgeschlossen') : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('pp_front_start_live'); ?>
                <input type="hidden" name="action" value="pp_front_start_live">
                <input type="hidden" name="id" value="<?php echo esc_attr($p->id); ?>">
                <?php pp_front_return_field(); ?>
                <button type="submit" class="pp-btn pp-btn-primary">Live protokollieren</button>
            </form>
        <?php endif; ?>
    </div>
    <p class="pp-meta"><?php echo esc_html($gremium->name ?? '–'); ?> · <?php echo esc_html($p->datum ?: 'Termin offen'); ?><?php echo $p->ort ? ' · ' . esc_html($p->ort) : ''; ?> · <?php echo $p->status === 'abgeschlossen' ? 'Abgeschlossen' : 'Entwurf'; ?></p>

    <h3>Kopfdaten</h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-form pp-form-grid">
        <?php wp_nonce_field('pp_front_save_protokoll'); ?>
        <input type="hidden" name="action" value="pp_front_save_protokoll">
        <input type="hidden" name="id" value="<?php echo esc_attr($p->id); ?>">
        <?php pp_front_return_field(); ?>
        <label>Gremium
            <select name="gremium_id" required>
                <?php foreach ($gremien as $g) : ?>
                    <option value="<?php echo esc_attr($g->id); ?>" <?php selected($p->gremium_id, $g->id); ?>><?php echo esc_html($g->name); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Titel <input type="text" name="titel" value="<?php echo esc_attr($p->titel); ?>" required></label>
        <label>Datum <input type="date" name="datum" value="<?php echo esc_attr($p->datum); ?>"></label>
        <label>Beginn <input type="time" name="uhrzeit_beginn" value="<?php echo esc_attr(substr((string) $p->uhrzeit_beginn, 0, 5)); ?>"></label>
        <label>Ende <input type="time" name="uhrzeit_ende" value="<?php echo esc_attr(substr((string) $p->uhrzeit_ende, 0, 5)); ?>"></label>
        <label>Ort <input type="text" name="ort" value="<?php echo esc_attr($p->ort); ?>"></label>
        <div class="pp-form-actions"><button type="submit" class="pp-btn">Speichern</button></div>
    </form>

    <h3>Tagesordnung</h3>
    <?php
    $fenster = pp_top_zeitfenster($tops, $p->uhrzeit_beginn);
    pp_render_budget_bar($p, $tops);
    ?>
    <?php if ($tops) : ?>
        <ol class="pp-agenda pp-agenda-detail">
            <?php foreach ($tops as $index => $t) : ?>
                <li id="top-<?php echo esc_attr($t->id); ?>">
                    <div class="pp-agenda-main">
                        <span class="pp-agenda-zeit"><?php
                            echo isset($fenster[$t->id])
                                ? esc_html($fenster[$t->id]['von'] . '–' . $fenster[$t->id]['bis'])
                                : esc_html(intval($t->dauer_minuten) . ' Min.');
                        ?></span>
                        <span class="pp-agenda-title"><?php echo esc_html($t->titel); ?></span>
                        <span class="pp-meta"><?php echo esc_html(pp_top_typ_label($t->typ)); ?> · <?php echo esc_html(pp_verfahren_label($t->verfahren)); ?> · <?php echo esc_html(pp_konsent_status_label($t->konsent_status)); ?></span>
                        <?php if ($t->beschreibung) : ?><div class="pp-agenda-desc"><?php echo nl2br(esc_html($t->beschreibung)); ?></div><?php endif; ?>
                        <?php if ($t->beschluss) : ?><div class="pp-beschluss-line"><strong>Beschluss:</strong> <?php echo esc_html($t->beschluss); ?></div><?php endif; ?>

                        <?php pp_render_top_unterlagen($t, $p, $p->status !== 'abgeschlossen' && pp_can_manage(), 'protokoll'); ?>

                        <?php if ($p->status !== 'abgeschlossen') : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline-form pp-top-edit">
                                <?php wp_nonce_field('pp_front_update_top'); ?>
                                <input type="hidden" name="action" value="pp_front_update_top">
                                <input type="hidden" name="id" value="<?php echo esc_attr($t->id); ?>">
                                <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($p->id); ?>">
                                <?php pp_front_return_field(); ?>
                                <input type="text" name="titel" value="<?php echo esc_attr($t->titel); ?>" required>
                                <input type="number" name="dauer_minuten" value="<?php echo esc_attr($t->dauer_minuten); ?>" min="0" step="5" style="width:80px" title="Minuten">
                                <button type="submit" class="pp-btn pp-btn-small">Speichern</button>
                            </form>
                        <?php endif; ?>
                    </div>

                    <?php if ($p->status !== 'abgeschlossen') : ?>
                        <div class="pp-agenda-tools">
                            <?php if ($index > 0) : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline">
                                    <?php wp_nonce_field('pp_front_move_top'); ?>
                                    <input type="hidden" name="action" value="pp_front_move_top">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($t->id); ?>">
                                    <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($p->id); ?>">
                                    <input type="hidden" name="richtung" value="hoch">
                                    <?php pp_front_return_field(); ?>
                                    <button type="submit" class="pp-icon-btn" title="nach oben">▲</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($index < count($tops) - 1) : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline">
                                    <?php wp_nonce_field('pp_front_move_top'); ?>
                                    <input type="hidden" name="action" value="pp_front_move_top">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($t->id); ?>">
                                    <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($p->id); ?>">
                                    <input type="hidden" name="richtung" value="runter">
                                    <?php pp_front_return_field(); ?>
                                    <button type="submit" class="pp-icon-btn" title="nach unten">▼</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline">
                                <?php wp_nonce_field('pp_front_delete_top'); ?>
                                <input type="hidden" name="action" value="pp_front_delete_top">
                                <input type="hidden" name="id" value="<?php echo esc_attr($t->id); ?>">
                                <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($p->id); ?>">
                                <?php pp_front_return_field(); ?>
                                <button type="submit" class="pp-link-danger" onclick="return confirm('TOP entfernen?')">entfernen</button>
                            </form>
                        </div>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    <?php else : ?><p class="pp-empty">Noch keine Tagesordnungspunkte.</p><?php endif; ?>

    <?php if ($p->status !== 'abgeschlossen') : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline-form">
            <?php wp_nonce_field('pp_front_add_top'); ?>
            <input type="hidden" name="action" value="pp_front_add_top">
            <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($p->id); ?>">
            <?php pp_front_return_field(); ?>
            <input type="text" name="titel" placeholder="Neuer TOP…" required>
            <select name="thema_id">
                <option value="">— oder Thema übernehmen —</option>
                <?php foreach ($themen as $th) : ?>
                    <option value="<?php echo esc_attr($th->id); ?>"><?php echo esc_html($th->titel); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="number" name="dauer_minuten" value="15" min="0" step="5" style="width:80px" title="Dauer in Minuten">
            <button type="submit" class="pp-btn pp-btn-small">TOP hinzufügen</button>
        </form>
    <?php endif; ?>

    <?php $sitzungs_aufgaben = pp_get_aufgaben_aus_sitzung($p->id); ?>
    <h3>In dieser Sitzung erfasste Aufgaben</h3>
    <?php if ($sitzungs_aufgaben) : ?>
        <table class="pp-table">
            <thead><tr><th>Aufgabe</th><th>Verantwortlich</th><th>Fällig</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($sitzungs_aufgaben as $sa) : ?>
                <tr>
                    <td><?php echo esc_html($sa->titel); ?></td>
                    <td><?php echo esc_html(pp_user_display_name($sa->verantwortlich_user_id)); ?></td>
                    <td><?php echo esc_html($sa->faelligkeitsdatum ?: '–'); ?></td>
                    <td><?php echo $sa->status === 'erledigt' ? 'erledigt' : 'offen'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p class="pp-empty">Keine. Aufgaben aus beschlossenen TOPs entstehen erst beim Protokollabschluss.</p>
    <?php endif; ?>

    <h3 id="kommentare">Kommentare</h3>    <?php if ($kommentare) : ?>
        <ul class="pp-kommentare">
            <?php foreach ($kommentare as $k) : ?>
                <li><strong><?php echo esc_html(pp_user_display_name($k->user_id)); ?>:</strong> <?php echo esc_html($k->text); ?>
                    <span class="pp-meta"><?php echo esc_html(mysql2date('d.m.Y H:i', $k->erstellt_am)); ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php else : ?><p class="pp-empty">Noch keine Kommentare.</p><?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline-form">
        <?php wp_nonce_field('pp_front_add_kommentar'); ?>
        <input type="hidden" name="action" value="pp_front_add_kommentar">
        <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($p->id); ?>">
        <?php pp_front_return_field(); ?>
        <input type="text" name="text" placeholder="Kommentar…" required style="flex:1">
        <button type="submit" class="pp-btn pp-btn-small">Kommentieren</button>
    </form>
    <?php
}

// ─── LIVE-MODUS ────────────────────────────────────────────────────────────

function pp_render_live_modus() {
    $id = intval($_GET['id'] ?? 0);
    $p  = pp_get_protokoll($id);
    if (!$p) { echo '<p class="pp-empty">Protokoll nicht gefunden.</p>'; return; }

    $gremium = pp_get_gremium($p->gremium_id);
    $tops    = pp_get_tops_fuer_protokoll($id);
    $themen  = pp_get_themen_fuer_protokoll($p);
    $gesamt  = pp_tops_gesamtdauer($tops);
    $fenster = pp_top_zeitfenster($tops, $p->uhrzeit_beginn);
    $start   = $p->beginn_zeit ? mysql2date('c', $p->beginn_zeit) : current_time('c');

    // Startoffset je TOP für die Restzeitberechnung im Frontend
    $offsets = [];
    $kumuliert = 0;
    foreach ($tops as $t) {
        $offsets[$t->id] = ['start' => $kumuliert, 'dauer' => intval($t->dauer_minuten)];
        $kumuliert += intval($t->dauer_minuten);
    }
    ?>
    <aside class="pp-sidebar pp-sidebar-live">
        <div class="pp-live-clock" data-start="<?php echo esc_attr($start); ?>">
            <div class="pp-live-time" id="pp-live-uhr">--:--:--</div>
            <div class="pp-meta">Sitzung läuft seit <span id="pp-live-dauer">0 Min.</span></div>
        </div>

        <div class="pp-sidebar-section">Tagesordnung <span class="pp-meta">(<?php echo intval($gesamt); ?> Min. geplant)</span></div>
        <ol class="pp-live-agenda" id="pp-live-agenda">
            <?php foreach ($tops as $t) : ?>
                <li data-start-offset="<?php echo esc_attr($offsets[$t->id]['start']); ?>"
                    data-dauer="<?php echo esc_attr($offsets[$t->id]['dauer']); ?>"
                    data-erledigt="<?php echo $t->konsent_status === 'beschlossen' ? '1' : '0'; ?>">
                    <a href="#top-<?php echo esc_attr($t->id); ?>"><?php echo esc_html($t->titel); ?></a>
                    <span class="pp-live-plan"><?php
                        echo isset($fenster[$t->id])
                            ? esc_html($fenster[$t->id]['von'] . '–' . $fenster[$t->id]['bis'])
                            : esc_html(intval($t->dauer_minuten) . ' Min.');
                    ?></span>
                    <span class="pp-live-rest pp-meta"></span>
                </li>
            <?php endforeach; ?>
            <?php if (empty($tops)) : ?><li class="pp-empty">Keine TOPs.</li><?php endif; ?>
        </ol>

        <div class="pp-sidebar-section">Tagesordnung ändern</div>
        <p class="pp-live-hinweis">Änderungen an der laufenden Tagesordnung werden als eigener Punkt zur Konsentrunde gestellt und erst mit dem Beschluss wirksam.</p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-live-form">
            <?php wp_nonce_field('pp_front_to_aenderung_beantragen'); ?>
            <input type="hidden" name="action" value="pp_front_to_aenderung_beantragen">
            <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($p->id); ?>">
            <?php pp_front_return_field(); ?>

            <select name="aktion" class="pp-to-aktion">
                <option value="hinzufuegen">TOP aufnehmen</option>
                <option value="entfernen">TOP streichen</option>
                <option value="dauer">Dauer ändern</option>
            </select>

            <div class="pp-to-feld pp-to-feld-neu">
                <input type="text" name="titel" placeholder="Titel des neuen TOP">
            </div>
            <div class="pp-to-feld pp-to-feld-ziel" style="display:none">
                <select name="ziel_top_id">
                    <option value="">TOP wählen…</option>
                    <?php foreach ($tops as $t) : if ($t->typ === 'to_aenderung') continue; ?>
                        <option value="<?php echo esc_attr($t->id); ?>"><?php echo esc_html($t->titel); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="pp-to-feld pp-to-feld-dauer">
                <input type="number" name="dauer_minuten" value="15" min="0" step="5" title="Minuten">
            </div>

            <input type="text" name="begruendung" placeholder="Kurze Begründung">
            <button type="submit" class="pp-btn pp-btn-small">Änderung beantragen</button>
        </form>

        <div class="pp-sidebar-section">Aufgabe erfassen</div>
        <?php $sitzungs_aufgaben = pp_get_aufgaben_aus_sitzung($p->id); ?>
        <?php if ($sitzungs_aufgaben) : ?>
            <ul class="pp-live-erfasst">
                <?php foreach ($sitzungs_aufgaben as $sa) : ?>
                    <li>✓ <?php echo esc_html($sa->titel); ?>
                        <span class="pp-meta"><?php
                            echo esc_html(pp_user_display_name($sa->verantwortlich_user_id));
                            if ($sa->faelligkeitsdatum) echo ' · bis ' . esc_html($sa->faelligkeitsdatum);
                        ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-live-form">
            <?php wp_nonce_field('pp_front_quick_aufgabe'); ?>
            <input type="hidden" name="action" value="pp_front_quick_aufgabe">
            <input type="hidden" name="ziel_view" value="live">
            <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($p->id); ?>">
            <input type="hidden" name="gremium_id" value="<?php echo esc_attr($p->gremium_id); ?>">
            <?php pp_front_return_field(); ?>
            <input type="text" name="titel" placeholder="Aufgabe…" required>
            <select name="verantwortlich_user_id">
                <option value="">Verantwortlich…</option>
                <?php foreach (pp_get_moegliche_mitglieder() as $u) : ?>
                    <option value="<?php echo esc_attr($u->ID); ?>"><?php echo esc_html($u->display_name); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="faelligkeitsdatum">
            <button type="submit" class="pp-btn pp-btn-small">Aufgabe anlegen</button>
        </form>

        <div class="pp-sidebar-section">Termin erfassen</div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-live-form">
            <?php wp_nonce_field('pp_front_quick_termin'); ?>
            <input type="hidden" name="action" value="pp_front_quick_termin">
            <input type="hidden" name="ziel_view" value="live">
            <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($p->id); ?>">
            <input type="hidden" name="gremium_id" value="<?php echo esc_attr($p->gremium_id); ?>">
            <?php pp_front_return_field(); ?>
            <input type="text" name="titel" placeholder="Termin…" required>
            <input type="datetime-local" name="datum">
            <input type="text" name="ort" placeholder="Ort">
            <button type="submit" class="pp-btn pp-btn-small">Termin anlegen</button>
        </form>

        <a class="pp-btn pp-btn-small pp-live-exit" href="<?php echo esc_url(pp_front_url(['pp_view' => 'protokoll', 'id' => $p->id])); ?>">Live-Modus verlassen</a>
    </aside>

    <main class="pp-app-main pp-live-main">
        <?php pp_render_notices(); ?>
        <div class="pp-page-head">
            <h2><?php echo esc_html($p->titel); ?></h2>
            <span class="pp-meta"><?php echo esc_html($gremium->name ?? ''); ?> · <?php echo esc_html($p->datum ?: date_i18n('d.m.Y')); ?></span>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-form">
            <?php wp_nonce_field('pp_front_save_protokoll_inhalt'); ?>
            <input type="hidden" name="action" value="pp_front_save_protokoll_inhalt">
            <input type="hidden" name="id" value="<?php echo esc_attr($p->id); ?>">
            <input type="hidden" name="ziel_view" value="live">
            <?php pp_front_return_field(); ?>
            <label>Check-In <textarea name="checkin" rows="2"><?php echo esc_textarea($p->checkin); ?></textarea></label>
            <label>Organisatorisches <textarea name="organisatorisches" rows="2"><?php echo esc_textarea($p->organisatorisches); ?></textarea></label>
            <label>Check-Out <textarea name="checkout" rows="2"><?php echo esc_textarea($p->checkout); ?></textarea></label>
            <div class="pp-form-actions"><button type="submit" class="pp-btn">Rahmen speichern</button></div>
        </form>

        <?php foreach ($tops as $t) : pp_render_live_top($t, $p); endforeach; ?>
        <?php if (empty($tops)) : ?><p class="pp-empty">Noch keine TOPs — links in der Seitenleiste ergänzen.</p><?php endif; ?>

        <?php if ($p->status !== 'abgeschlossen') : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-abschluss"
                  onsubmit="return confirm('Protokoll abschließen? Beschlüsse, Aufgaben, Termine und Bestätigungen werden jetzt erzeugt.');">
                <?php wp_nonce_field('pp_front_abschliessen'); ?>
                <input type="hidden" name="action" value="pp_front_abschliessen">
                <input type="hidden" name="id" value="<?php echo esc_attr($p->id); ?>">
                <?php pp_front_return_field(); ?>
                <button type="submit" class="pp-btn pp-btn-primary">Protokoll abschließen</button>
            </form>
        <?php endif; ?>
    </main>

    <script>
    (function () {
        // Felder im "Tagesordnung ändern"-Formular je nach Aktion ein-/ausblenden
        var aktion = document.querySelector('.pp-to-aktion');
        if (aktion) {
            var feldNeu = document.querySelector('.pp-to-feld-neu');
            var feldZiel = document.querySelector('.pp-to-feld-ziel');
            var feldDauer = document.querySelector('.pp-to-feld-dauer');
            var umschalten = function () {
                var v = aktion.value;
                feldNeu.style.display = (v === 'hinzufuegen') ? '' : 'none';
                feldZiel.style.display = (v === 'hinzufuegen') ? 'none' : '';
                feldDauer.style.display = (v === 'entfernen') ? 'none' : '';
            };
            aktion.addEventListener('change', umschalten);
            umschalten();
        }

        var wrap = document.querySelector('.pp-live-clock');
        if (!wrap) return;
        var start = new Date(wrap.getAttribute('data-start'));
        var uhr = document.getElementById('pp-live-uhr');
        var dauerEl = document.getElementById('pp-live-dauer');
        var items = document.querySelectorAll('#pp-live-agenda li[data-dauer]');

        function tick() {
            var jetzt = new Date();
            uhr.textContent = jetzt.toLocaleTimeString('de-DE');
            var verstrichenMin = Math.floor((jetzt - start) / 60000);
            if (verstrichenMin < 0) verstrichenMin = 0;
            dauerEl.textContent = verstrichenMin + ' Min.';

            items.forEach(function (li) {
                var offset = parseInt(li.getAttribute('data-start-offset'), 10);
                var dauer = parseInt(li.getAttribute('data-dauer'), 10);
                var erledigt = li.getAttribute('data-erledigt') === '1';
                var rest = offset + dauer - verstrichenMin;
                var el = li.querySelector('.pp-live-rest');
                li.classList.remove('is-current', 'is-over', 'is-done');

                if (erledigt) {
                    el.textContent = 'beschlossen';
                    li.classList.add('is-done');
                    return;
                }
                if (verstrichenMin < offset) {
                    el.textContent = 'in ' + (offset - verstrichenMin) + ' Min.';
                } else if (rest > 0) {
                    el.textContent = 'noch ' + rest + ' Min.';
                    li.classList.add('is-current');
                } else if (rest === 0) {
                    el.textContent = 'Zeit um';
                    li.classList.add('is-current');
                } else {
                    el.textContent = Math.abs(rest) + ' Min. drüber';
                    li.classList.add('is-over');
                }
            });
        }
        tick();
        setInterval(tick, 1000);
    })();
    </script>
    <?php
}

/**
 * Unterlagen eines TOPs: Liste plus Erfassungsformular.
 * $bearbeitbar = false rendert nur die Liste (normale Protokollansicht).
 */
function pp_render_top_unterlagen($t, $p, $bearbeitbar = true, $ziel_view = 'live') {
    $unterlagen = pp_top_unterlagen($t->id);
    if (!$unterlagen && !$bearbeitbar) return;

    // Das Umschalten der Eingabefelder wird einmal pro Seite ausgegeben – die
    // Funktion läuft im Live-Modus wie in der Protokollansicht.
    static $script_raus = false;
    if ($bearbeitbar && !$script_raus) {
        $script_raus = true;
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.pp-unterlage-form').forEach(function (f) {
                var typ = f.querySelector('.pp-unterlage-typ');
                if (!typ) return;
                var felder = f.querySelectorAll('.pp-unterlage-feld');
                var zeigen = function () {
                    felder.forEach(function (l) { l.hidden = (l.getAttribute('data-fuer') !== typ.value); });
                };
                typ.addEventListener('change', zeigen);
                zeigen();
            });
        });
        </script>
        <?php
    }
    ?>
    <div class="pp-top-unterlagen">
        <?php if ($unterlagen) : ?>
            <ul class="pp-unterlagen-liste">
                <?php foreach ($unterlagen as $u) :
                    $wer = $u->erstellt_von ? get_userdata($u->erstellt_von) : null; ?>
                    <li class="pp-unterlage pp-unterlage-<?php echo esc_attr($u->typ); ?>">
                        <div class="pp-unterlage-kopf">
                            <span class="pp-unterlage-icon" aria-hidden="true"><?php
                                echo $u->typ === 'datei' ? '📄' : ($u->typ === 'link' ? '🔗' : '📝');
                            ?></span>
                            <?php if ($u->typ === 'text') : ?>
                                <strong><?php echo esc_html($u->titel); ?></strong>
                            <?php else : ?>
                                <a href="<?php echo esc_url($u->url); ?>" target="_blank" rel="noopener"><?php echo esc_html($u->titel); ?></a>
                            <?php endif; ?>
                            <span class="pp-meta"><?php
                                echo esc_html(($wer ? $wer->display_name . ' · ' : '') . mysql2date('d.m.Y H:i', $u->erstellt_am));
                            ?></span>
                            <?php if ($bearbeitbar) : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline-form"
                                      onsubmit="return confirm('Diese Unterlage entfernen?');">
                                    <?php wp_nonce_field('pp_front_top_unterlage_delete'); ?>
                                    <input type="hidden" name="action" value="pp_front_top_unterlage_delete">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($u->id); ?>">
                                    <input type="hidden" name="top_id" value="<?php echo esc_attr($t->id); ?>">
                                    <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($p->id); ?>">
                                    <input type="hidden" name="ziel_view" value="<?php echo esc_attr($ziel_view); ?>">
                                    <?php pp_front_return_field(); ?>
                                    <button type="submit" class="pp-btn pp-btn-small pp-btn-ghost" title="Entfernen">×</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php if ($u->typ === 'text' && $u->inhalt !== '') : ?>
                            <div class="pp-unterlage-text"><?php echo wpautop(wp_kses_post($u->inhalt)); ?></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($bearbeitbar) : ?>
            <details class="pp-unterlage-neu">
                <summary>Unterlage oder Text hinzufügen</summary>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      enctype="multipart/form-data" class="pp-form pp-unterlage-form">
                    <?php wp_nonce_field('pp_front_top_unterlage'); ?>
                    <input type="hidden" name="action" value="pp_front_top_unterlage_add">
                    <input type="hidden" name="top_id" value="<?php echo esc_attr($t->id); ?>">
                    <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($p->id); ?>">
                    <input type="hidden" name="ziel_view" value="<?php echo esc_attr($ziel_view); ?>">
                    <?php pp_front_return_field(); ?>

                    <label>Art
                        <select name="typ" class="pp-unterlage-typ">
                            <option value="text">Text / Notiz</option>
                            <option value="datei">Datei hochladen</option>
                            <option value="link">Link (z. B. Nextcloud)</option>
                        </select>
                    </label>
                    <label>Titel (optional)
                        <input type="text" name="titel" placeholder="z. B. Angebot Stadtfest">
                    </label>
                    <label class="pp-unterlage-feld" data-fuer="text">Text
                        <textarea name="inhalt" rows="4" placeholder="Längerer Text, Zitat, Vorlage …"></textarea>
                    </label>
                    <label class="pp-unterlage-feld" data-fuer="datei" hidden>Datei
                        <input type="file" name="datei">
                    </label>
                    <label class="pp-unterlage-feld" data-fuer="link" hidden>Link
                        <input type="url" name="url" placeholder="https://…">
                    </label>
                    <div class="pp-form-actions">
                        <button type="submit" class="pp-btn pp-btn-small">Hinzufügen</button>
                    </div>
                </form>
            </details>
        <?php endif; ?>
    </div>
    <?php
}

function pp_render_live_top($t, $p) {
    global $wpdb;
    $reihenfolge = ['vorstellung', 'verstaendnisfragen', 'meinungsrunde', 'konsentrunde', 'beschlossen'];
    $pos = array_search($t->konsent_status, $reihenfolge, true);
    ?>
    <section class="pp-live-top pp-top-status-<?php echo esc_attr($t->konsent_status); ?>" id="top-<?php echo esc_attr($t->id); ?>">
        <div class="pp-live-top-head">
            <strong><?php echo esc_html($t->titel); ?></strong>
            <span class="pp-meta"><?php echo intval($t->dauer_minuten); ?> Min. · <?php echo esc_html(pp_verfahren_label($t->verfahren)); ?> · <?php echo esc_html(pp_konsent_status_label($t->konsent_status)); ?></span>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-form">
            <?php wp_nonce_field('pp_front_save_top_inhalt'); ?>
            <input type="hidden" name="action" value="pp_front_save_top_inhalt">
            <input type="hidden" name="id" value="<?php echo esc_attr($t->id); ?>">
            <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($p->id); ?>">
            <input type="hidden" name="ziel_view" value="live">
            <?php pp_front_return_field(); ?>
            <label>Notizen / Vorschlag
                <textarea name="beschreibung" rows="4"><?php echo esc_textarea($t->beschreibung); ?></textarea>
            </label>
            <label>Beschlusstext
                <textarea name="beschluss" rows="2"><?php echo esc_textarea($t->beschluss); ?></textarea>
            </label>
            <div class="pp-form-actions"><button type="submit" class="pp-btn pp-btn-small">Notizen speichern</button></div>
        </form>

        <?php pp_render_top_unterlagen($t, $p, true, 'live'); ?>

        <?php if ($t->konsent_status === 'einwand_offen') :
            $einwaende = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}pp_einwaende WHERE top_id = %d AND status='offen' ORDER BY erstellt_am DESC", $t->id
            )); ?>
            <div class="pp-einwand-box">
                <strong>Offene Einwände</strong>
                <ul><?php foreach ($einwaende as $e) : ?>
                    <li><?php echo esc_html(pp_user_display_name($e->user_id)); ?>: <?php echo esc_html($e->begruendung); ?></li>
                <?php endforeach; ?></ul>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('pp_front_top_konsent'); ?>
                    <input type="hidden" name="action" value="pp_front_top_konsent">
                    <input type="hidden" name="konsent_aktion" value="erneut">
                    <input type="hidden" name="id" value="<?php echo esc_attr($t->id); ?>">
                    <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($p->id); ?>">
                    <input type="hidden" name="ziel_view" value="live">
                    <?php pp_front_return_field(); ?>
                    <button type="submit" class="pp-btn pp-btn-small">Überarbeitet — erneut zur Konsentrunde</button>
                </form>
            </div>
        <?php elseif ($t->konsent_status === 'konsentrunde') : ?>
            <div class="pp-konsent-actions">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('pp_front_top_konsent'); ?>
                    <input type="hidden" name="action" value="pp_front_top_konsent">
                    <input type="hidden" name="konsent_aktion" value="beschliessen">
                    <input type="hidden" name="id" value="<?php echo esc_attr($t->id); ?>">
                    <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($p->id); ?>">
                    <input type="hidden" name="ziel_view" value="live">
                    <?php pp_front_return_field(); ?>
                    <input type="text" name="beschluss" placeholder="Beschlusstext" value="<?php echo esc_attr($t->beschluss); ?>">
                    <button type="submit" class="pp-btn pp-btn-primary pp-btn-small">Kein Einwand — beschließen</button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('pp_front_top_konsent'); ?>
                    <input type="hidden" name="action" value="pp_front_top_konsent">
                    <input type="hidden" name="konsent_aktion" value="einwand">
                    <input type="hidden" name="id" value="<?php echo esc_attr($t->id); ?>">
                    <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($p->id); ?>">
                    <input type="hidden" name="ziel_view" value="live">
                    <?php pp_front_return_field(); ?>
                    <input type="text" name="begruendung" placeholder="Begründung für Einwand" required>
                    <button type="submit" class="pp-btn pp-btn-small">Einwand</button>
                </form>
            </div>
        <?php elseif ($t->konsent_status !== 'beschlossen') : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('pp_front_top_konsent'); ?>
                <input type="hidden" name="action" value="pp_front_top_konsent">
                <input type="hidden" name="konsent_aktion" value="weiter">
                <input type="hidden" name="id" value="<?php echo esc_attr($t->id); ?>">
                <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($p->id); ?>">
                <input type="hidden" name="ziel_view" value="live">
                <?php pp_front_return_field(); ?>
                <button type="submit" class="pp-btn pp-btn-small">Weiter zu: <?php echo esc_html(pp_konsent_status_label($reihenfolge[$pos + 1])); ?></button>
            </form>
        <?php else : ?>
            <p class="pp-beschluss-line"><strong>Beschluss:</strong> <?php echo esc_html($t->beschluss ?: '–'); ?></p>
        <?php endif; ?>
    </section>
    <?php
}

// ─── ANSICHT: KREISE (Übersicht) ───────────────────────────────────────────

function pp_render_view_kreise() {
    $gremien = pp_get_gremien(null, false);
    ?>
    <div class="pp-page-head">
        <h2>Kreise &amp; Rollen</h2>
        <a class="pp-btn pp-btn-primary" href="#pp-neuer-kreis">Kreis einrichten</a>
    </div>
    <p class="pp-meta">
        Zweck, Entscheidungsfindung und Leitung eines Kreises legt der <strong>Leitungskreis</strong> fest (Selbstverwaltungsordnung Teil B) —
        jede Einrichtung und Änderung wird automatisch für die nächste Vollversammlung zur Bestätigung vorgemerkt.
        Die <strong>Rollen innerhalb eines Kreises</strong> definiert jeder Kreis selbst (Teil C).
    </p>

    <table class="pp-table">
        <thead><tr><th>Kreis / Gremium</th><th>Typ</th><th>Entscheidungsfindung</th><th>Leitung</th><th>Rollen</th></tr></thead>
        <tbody>
        <?php foreach ($gremien as $g) :
            $vorlagen = pp_get_rollenvorlagen_fuer_gremium($g->id);
            $leitung  = [];
            foreach ($vorlagen as $v) {
                if (stripos($v->bezeichnung, 'leitung') === false && stripos($v->bezeichnung, 'sprecher') === false) continue;
                foreach (pp_get_aktuelle_besetzungen($v->id) as $b) $leitung[] = pp_user_display_name($b->user_id);
            }
        ?>
            <tr>
                <td>
                    <a href="<?php echo esc_url(pp_front_url(['pp_view' => 'kreis', 'id' => $g->id])); ?>"><?php echo esc_html($g->name); ?></a>
                    <?php if (!$g->aktiv) echo ' <span class="pp-meta">(aufgelöst)</span>'; ?>
                </td>
                <td><?php echo esc_html(pp_gremientyp_label($g->typ)); ?></td>
                <td><?php echo esc_html(pp_verfahren_label($g->standardverfahren)); ?></td>
                <td><?php echo $leitung ? esc_html(implode(', ', $leitung)) : '<span class="pp-meta">nicht besetzt</span>'; ?></td>
                <td><?php echo count($vorlagen); ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($gremien)) : ?><tr><td colspan="5" class="pp-empty">Noch keine Kreise angelegt.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <h3 id="pp-neuer-kreis">Neuen Kreis einrichten</h3>
    <p class="pp-meta">Beschluss des Leitungskreises — wird der nächsten Vollversammlung zur Bestätigung vorgelegt.</p>
    <?php pp_render_kreis_formular(null, $gremien); ?>
    <?php
}

/** Gemeinsames Formular für Kreis anlegen und bearbeiten (Teil B). */
function pp_render_kreis_formular($kreis, $alle_gremien) {
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-form pp-form-grid">
        <?php wp_nonce_field('pp_front_save_kreis'); ?>
        <input type="hidden" name="action" value="pp_front_save_kreis">
        <input type="hidden" name="id" value="<?php echo esc_attr($kreis->id ?? 0); ?>">
        <?php pp_front_return_field(); ?>

        <label>Name <input type="text" name="name" value="<?php echo esc_attr($kreis->name ?? ''); ?>" required></label>

        <label>Typ
            <select name="typ">
                <?php foreach (['kreis','kreisversammlung','leitungskreis','vorstand','mv'] as $t) : ?>
                    <option value="<?php echo esc_attr($t); ?>" <?php selected($kreis->typ ?? 'kreis', $t); ?>><?php echo esc_html(pp_gremientyp_label($t)); ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Entscheidungsfindung
            <select name="standardverfahren">
                <?php foreach (['konsent','mehrheit','geheime_wahl'] as $v) : ?>
                    <option value="<?php echo esc_attr($v); ?>" <?php selected($kreis->standardverfahren ?? 'konsent', $v); ?>><?php echo esc_html(pp_verfahren_label($v)); ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Sichtbarkeit der Protokolle
            <select name="oeffentlichkeit">
                <?php foreach (['vereinsintern','oeffentlich','nur_gremium'] as $o) : ?>
                    <option value="<?php echo esc_attr($o); ?>" <?php selected($kreis->oeffentlichkeit ?? 'vereinsintern', $o); ?>><?php echo esc_html(pp_oeffentlichkeit_label($o)); ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Übergeordnetes Gremium
            <select name="parent_gremium_id">
                <option value="">— keins —</option>
                <?php foreach ($alle_gremien as $g) :
                    if ($kreis && intval($g->id) === intval($kreis->id)) continue; ?>
                    <option value="<?php echo esc_attr($g->id); ?>" <?php selected($kreis->parent_gremium_id ?? '', $g->id); ?>><?php echo esc_html($g->name); ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="pp-span-2">Zweck / Aufgabenbereich des Kreises
            <textarea name="beschreibung" rows="3" placeholder="Wofür ist dieser Kreis da? Was fällt in seinen Aufgabenbereich?"><?php echo esc_textarea($kreis->beschreibung ?? ''); ?></textarea>
        </label>

        <div class="pp-form-actions">
            <button type="submit" class="pp-btn pp-btn-primary"><?php echo $kreis ? 'Änderungen speichern' : 'Kreis einrichten'; ?></button>
            <span class="pp-meta">Wird als Leitungskreis-Beschluss protokolliert.</span>
        </div>
    </form>
    <?php
}

// ─── ANSICHT: EINZELNER KREIS ──────────────────────────────────────────────

function pp_render_view_kreis_detail() {
    $id    = intval($_GET['id'] ?? 0);
    $kreis = pp_get_gremium($id);
    if (!$kreis) { echo '<p class="pp-empty">Kreis nicht gefunden.</p>'; return; }

    $alle_gremien = pp_get_gremien(null, false);
    $vorlagen     = pp_get_rollenvorlagen_fuer_gremium($id);
    $edit_rolle   = intval($_GET['rolle'] ?? 0);
    ?>
    <div class="pp-page-head">
        <h2><?php echo esc_html($kreis->name); ?></h2>
        <a class="pp-btn pp-btn-small" href="<?php echo esc_url(pp_front_url(['pp_view' => 'kreise'])); ?>">Alle Kreise</a>
    </div>
    <p class="pp-meta">
        <?php echo esc_html(pp_gremientyp_label($kreis->typ)); ?> ·
        Entscheidungen: <?php echo esc_html(pp_verfahren_label($kreis->standardverfahren)); ?> ·
        Protokolle: <?php echo esc_html(pp_oeffentlichkeit_label($kreis->oeffentlichkeit)); ?>
    </p>
    <?php if ($kreis->beschreibung) : ?>
        <p><strong>Zweck:</strong> <?php echo esc_html($kreis->beschreibung); ?></p>
    <?php endif; ?>

    <h3>Grunddaten <span class="pp-meta">— SVO Teil B, Beschluss des Leitungskreises</span></h3>
    <?php pp_render_kreis_formular($kreis, $alle_gremien); ?>

    <?php
    $mitglieder   = pp_get_kreis_mitglieder($kreis->id);
    $bin_dabei    = pp_ist_kreis_mitglied($kreis->id, get_current_user_id());
    $mitglied_ids = array_map(function ($m) { return intval($m->user_id); }, $mitglieder);
    ?>
    <h3>Wer arbeitet in diesem Kreis mit? <span class="pp-meta">(<?php echo count($mitglieder); ?>)</span></h3>
    <p class="pp-meta">Kreismitarbeit ist unabhängig von einer Rolle — man kann mitarbeiten, ohne ein Amt zu haben.</p>

    <?php if ($mitglieder) : ?>
        <ul class="pp-list pp-kreis-mitglieder">
            <?php foreach ($mitglieder as $m) : ?>
                <li>
                    <?php echo esc_html(pp_user_display_name($m->user_id)); ?>
                    <span class="pp-meta">dabei seit <?php echo esc_html($m->beigetreten_am ?: '–'); ?></span>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline">
                        <?php wp_nonce_field('pp_front_kreis_verlassen'); ?>
                        <input type="hidden" name="action" value="pp_front_kreis_verlassen">
                        <input type="hidden" name="gremium_id" value="<?php echo esc_attr($kreis->id); ?>">
                        <input type="hidden" name="user_id" value="<?php echo esc_attr($m->user_id); ?>">
                        <?php pp_front_return_field(); ?>
                        <button type="submit" class="pp-link-danger" onclick="return confirm('Mitarbeit in diesem Kreis beenden?')">austragen</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else : ?><p class="pp-empty">Noch niemand eingetragen.</p><?php endif; ?>

    <div class="pp-kreis-beitritt">
        <?php if (!$bin_dabei) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline">
                <?php wp_nonce_field('pp_front_kreis_beitreten'); ?>
                <input type="hidden" name="action" value="pp_front_kreis_beitreten">
                <input type="hidden" name="gremium_id" value="<?php echo esc_attr($kreis->id); ?>">
                <?php pp_front_return_field(); ?>
                <button type="submit" class="pp-btn pp-btn-primary">Diesem Kreis beitreten</button>
            </form>
        <?php else : ?>
            <span class="pp-meta">Du arbeitest in diesem Kreis mit.</span>
        <?php endif; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline-form">
            <?php wp_nonce_field('pp_front_kreis_beitreten'); ?>
            <input type="hidden" name="action" value="pp_front_kreis_beitreten">
            <input type="hidden" name="gremium_id" value="<?php echo esc_attr($kreis->id); ?>">
            <?php pp_front_return_field(); ?>
            <select name="user_id" required>
                <option value="">Anderes Mitglied eintragen…</option>
                <?php foreach (pp_get_moegliche_mitglieder() as $u) :
                    if (in_array(intval($u->ID), $mitglied_ids, true)) continue; ?>
                    <option value="<?php echo esc_attr($u->ID); ?>"><?php echo esc_html($u->display_name); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="pp-btn pp-btn-small">Eintragen</button>
        </form>
    </div>

    <?php if ($kreis->aktiv) : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px"
              onsubmit="return confirm('Kreis auflösen? Er wird deaktiviert, Protokolle und Rollen bleiben erhalten. Die Auflösung wird der Vollversammlung zur Bestätigung vorgelegt.');">
            <?php wp_nonce_field('pp_front_archive_kreis'); ?>
            <input type="hidden" name="action" value="pp_front_archive_kreis">
            <input type="hidden" name="id" value="<?php echo esc_attr($kreis->id); ?>">
            <?php pp_front_return_field(); ?>
            <button type="submit" class="pp-btn pp-btn-small">Kreis auflösen</button>
        </form>
    <?php endif; ?>

    <h3>Rollen im Kreis <span class="pp-meta">— SVO Teil C, vom Kreis selbst festgelegt</span></h3>

    <?php foreach ($vorlagen as $v) :
        $aufgaben     = pp_textliste_zu_array($v->verantwortlich_fuer);
        $skills       = pp_textliste_zu_array($v->benoetigte_faehigkeiten);
        $besetzungen  = pp_get_aktuelle_besetzungen($v->id);
        $wird_editiert = ($edit_rolle === intval($v->id));
    ?>
        <div class="pp-rolle-card" id="rolle-<?php echo esc_attr($v->id); ?>">
            <div class="pp-rolle-head">
                <strong><?php echo esc_html($v->bezeichnung); ?></strong>
                <span>
                    <a class="pp-meta" href="<?php echo esc_url(pp_front_url(['pp_view' => 'kreis', 'id' => $kreis->id, 'rolle' => $v->id], 'rolle-' . $v->id)); ?>">bearbeiten</a>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline">
                        <?php wp_nonce_field('pp_front_delete_rolle'); ?>
                        <input type="hidden" name="action" value="pp_front_delete_rolle">
                        <input type="hidden" name="id" value="<?php echo esc_attr($v->id); ?>">
                        <input type="hidden" name="gremium_id" value="<?php echo esc_attr($kreis->id); ?>">
                        <?php pp_front_return_field(); ?>
                        <button type="submit" class="pp-link-danger" onclick="return confirm('Rolle löschen? Bisherige Besetzungen bleiben als Historie erhalten.')">löschen</button>
                    </form>
                </span>
            </div>

            <div class="pp-rolle-grid">
                <div>
                    <h4>Aufgaben der Rolle</h4>
                    <?php if ($aufgaben) : ?>
                        <ul><?php foreach ($aufgaben as $a) echo '<li>' . esc_html($a) . '</li>'; ?></ul>
                    <?php else : ?><p class="pp-empty">Noch nicht beschrieben.</p><?php endif; ?>
                </div>
                <div>
                    <h4>Nötige Skills</h4>
                    <?php if ($skills) : ?>
                        <ul><?php foreach ($skills as $s) echo '<li>' . esc_html($s) . '</li>'; ?></ul>
                    <?php else : ?><p class="pp-empty">Noch nicht beschrieben.</p><?php endif; ?>
                </div>
                <div>
                    <h4>Aktuell besetzt durch</h4>
                    <?php if ($besetzungen) : ?>
                        <ul>
                            <?php foreach ($besetzungen as $b) : ?>
                                <li><?php echo esc_html(pp_user_display_name($b->user_id)); ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline">
                                        <?php wp_nonce_field('pp_front_besetzung_beenden'); ?>
                                        <input type="hidden" name="action" value="pp_front_besetzung_beenden">
                                        <input type="hidden" name="id" value="<?php echo esc_attr($b->id); ?>">
                                        <input type="hidden" name="gremium_id" value="<?php echo esc_attr($kreis->id); ?>">
                                        <?php pp_front_return_field(); ?>
                                        <button type="submit" class="pp-link-danger" onclick="return confirm('Besetzung beenden?')">beenden</button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else : ?><p class="pp-empty">Nicht besetzt.</p><?php endif; ?>

                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline-form">
                        <?php wp_nonce_field('pp_front_besetzen'); ?>
                        <input type="hidden" name="action" value="pp_front_besetzen">
                        <input type="hidden" name="gremium_id" value="<?php echo esc_attr($kreis->id); ?>">
                        <input type="hidden" name="rollenvorlage_id" value="<?php echo esc_attr($v->id); ?>">
                        <?php pp_front_return_field(); ?>
                        <select name="user_id" required>
                            <option value="">Person…</option>
                            <?php foreach (pp_get_moegliche_mitglieder() as $u) : ?>
                                <option value="<?php echo esc_attr($u->ID); ?>"><?php echo esc_html($u->display_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="date" name="amtszeit_start" title="Amtszeit von">
                        <input type="date" name="amtszeit_ende" title="Amtszeit bis">
                        <button type="submit" class="pp-btn pp-btn-small">Besetzen</button>
                    </form>
                </div>
            </div>

            <?php
            // Regelmäßige und Event-Aufgaben dieser Rolle
            $rollen_aufgaben = pp_get_aufgaben_fuer_rollenvorlage($v->id);
            $rhythmus = ['taeglich' => 'täglich', 'woechentlich' => 'wöchentlich', 'monatlich' => 'monatlich', 'jaehrlich' => 'jährlich'];
            ?>
            <div class="pp-rolle-aufgaben">
                <h4>Aufgaben dieser Rolle</h4>
                <?php if ($rollen_aufgaben) : ?>
                    <ul class="pp-list">
                        <?php foreach ($rollen_aufgaben as $ra) : ?>
                            <li>
                                <strong><?php echo esc_html($ra->titel); ?></strong>
                                <span class="pp-meta">
                                    <?php
                                    if ($ra->typ === 'event') {
                                        echo 'vor Veranstaltungen · ' . intval($ra->vorlauf_tage) . ' Tage vorher';
                                    } else {
                                        echo 'regelmäßig · ' . esc_html($rhythmus[$ra->wiederholung] ?? $ra->wiederholung);
                                    }
                                    ?>
                                </span>
                                <?php if ($ra->beschreibung) : ?><div class="pp-agenda-desc"><?php echo esc_html($ra->beschreibung); ?></div><?php endif; ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline">
                                    <?php wp_nonce_field('pp_front_delete_rollen_aufgabe'); ?>
                                    <input type="hidden" name="action" value="pp_front_delete_rollen_aufgabe">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($ra->id); ?>">
                                    <input type="hidden" name="gremium_id" value="<?php echo esc_attr($kreis->id); ?>">
                                    <?php pp_front_return_field(); ?>
                                    <button type="submit" class="pp-link-danger" onclick="return confirm('Aufgabe entfernen?')">entfernen</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else : ?>
                    <p class="pp-empty">Noch keine Aufgaben hinterlegt.</p>
                <?php endif; ?>

                <details>
                    <summary class="pp-details-summary">+ Aufgabe für diese Rolle anlegen</summary>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-form pp-rollen-aufgabe-form">
                        <?php wp_nonce_field('pp_front_save_rollen_aufgabe'); ?>
                        <input type="hidden" name="action" value="pp_front_save_rollen_aufgabe">
                        <input type="hidden" name="rollenvorlage_id" value="<?php echo esc_attr($v->id); ?>">
                        <input type="hidden" name="gremium_id" value="<?php echo esc_attr($kreis->id); ?>">
                        <?php pp_front_return_field(); ?>

                        <label>Titel
                            <input type="text" name="titel" placeholder="z. B. Buchhaltung machen" required>
                        </label>
                        <label>Beschreibung (optional)
                            <textarea name="beschreibung" rows="2"></textarea>
                        </label>
                        <label>Art der Aufgabe
                            <select name="typ" class="pp-aufgabe-typ">
                                <option value="wiederkehrend">Regelmäßig — kommt von selbst immer wieder</option>
                                <option value="event">Vor Veranstaltungen — wird je Termin ausgelöst</option>
                            </select>
                        </label>
                        <label class="pp-feld-rhythmus">Rhythmus
                            <select name="wiederholung">
                                <option value="woechentlich">wöchentlich</option>
                                <option value="monatlich" selected>monatlich</option>
                                <option value="jaehrlich">jährlich</option>
                                <option value="taeglich">täglich</option>
                            </select>
                        </label>
                        <label class="pp-feld-vorlauf" style="display:none">Vorlauf in Tagen
                            <input type="number" name="vorlauf_tage" value="14" min="0">
                        </label>
                        <div class="pp-form-actions">
                            <button type="submit" class="pp-btn pp-btn-small">Aufgabe anlegen</button>
                        </div>
                    </form>
                </details>
            </div>

            <?php if ($wird_editiert) : ?>
                <div class="pp-rolle-edit">
                    <h4>Rolle bearbeiten</h4>
                    <?php pp_render_rolle_formular($kreis, $v); ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if (empty($vorlagen)) : ?><p class="pp-empty">Noch keine Rollen definiert.</p><?php endif; ?>

    <h3>Neue Rolle definieren</h3>
    <?php pp_render_rolle_formular($kreis, null); ?>
    <?php
}

/** Formular für eine Rolle (Teil C): Aufgaben + nötige Skills. */
function pp_render_rolle_formular($kreis, $rolle) {
    ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-form">
        <?php wp_nonce_field('pp_front_save_rolle'); ?>
        <input type="hidden" name="action" value="pp_front_save_rolle">
        <input type="hidden" name="gremium_id" value="<?php echo esc_attr($kreis->id); ?>">
        <input type="hidden" name="id" value="<?php echo esc_attr($rolle->id ?? 0); ?>">
        <?php pp_front_return_field(); ?>

        <label>Bezeichnung
            <input type="text" name="bezeichnung" value="<?php echo esc_attr($rolle->bezeichnung ?? ''); ?>"
                   placeholder="z. B. Kreisleitung, Kassier:in, Schriftführung" required>
        </label>
        <label>Aufgaben der Rolle <span class="pp-meta">eine Aufgabe pro Zeile</span>
            <textarea name="verantwortlich_fuer" rows="4" placeholder="Buchhaltung führen&#10;Kassenbericht erstellen"><?php echo esc_textarea($rolle->verantwortlich_fuer ?? ''); ?></textarea>
        </label>
        <label>Nötige Skills <span class="pp-meta">eine Fähigkeit pro Zeile</span>
            <textarea name="benoetigte_faehigkeiten" rows="4" placeholder="Sorgfalt mit Zahlen&#10;Grundkenntnisse Buchhaltung"><?php echo esc_textarea($rolle->benoetigte_faehigkeiten ?? ''); ?></textarea>
        </label>
        <div class="pp-form-actions">
            <button type="submit" class="pp-btn pp-btn-primary"><?php echo $rolle ? 'Rolle speichern' : 'Rolle anlegen'; ?></button>
        </div>
    </form>
    <?php
}

// ─── ANSICHT: AUFGABEN-SETS ────────────────────────────────────────────────

function pp_render_view_sets() {
    $sets      = pp_get_aufgaben_sets();
    $gremien   = pp_get_gremien();
    $vorlagen  = pp_get_alle_rollenvorlagen();
    $offen_set = intval($_GET['set'] ?? 0);
    ?>
    <div class="pp-page-head">
        <h2>Aufgaben-Sets</h2>
        <a class="pp-btn" href="#pp-neues-set">Neues Set</a>
    </div>
    <p class="pp-meta">
        Ein Set bündelt alle Aufgaben, die rund um einen bestimmten Anlass anfallen — etwa eine Veranstaltung:
        Kostenkalkulation und Wechselgeld für die Kassier:in, Schichtplan für die Kreisleitung.
        Beim Anwenden auf einen Termin entstehen daraus automatisch Aufgaben mit passender Frist,
        zugewiesen an die Personen, die die jeweilige Rolle gerade innehaben.
    </p>

    <?php foreach ($sets as $set) :
        $eintraege = pp_get_set_eintraege($set->id);
        $gremium   = $set->gremium_id ? pp_get_gremium($set->gremium_id) : null;
        $offen     = ($offen_set === intval($set->id));
    ?>
        <div class="pp-set-card" id="set-<?php echo esc_attr($set->id); ?>">
            <div class="pp-set-head">
                <div>
                    <strong><?php echo esc_html($set->name); ?></strong>
                    <span class="pp-meta"><?php echo $gremium ? esc_html($gremium->name) : 'für alle Kreise'; ?> · <?php echo count($eintraege); ?> Aufgaben</span>
                    <?php if ($set->beschreibung) : ?><div class="pp-agenda-desc"><?php echo esc_html($set->beschreibung); ?></div><?php endif; ?>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('pp_front_delete_set'); ?>
                    <input type="hidden" name="action" value="pp_front_delete_set">
                    <input type="hidden" name="id" value="<?php echo esc_attr($set->id); ?>">
                    <?php pp_front_return_field(); ?>
                    <button type="submit" class="pp-link-danger" onclick="return confirm('Set mit allen Aufgaben löschen? Bereits erzeugte Aufgaben bleiben bestehen.')">löschen</button>
                </form>
            </div>

            <?php if ($eintraege) : ?>
                <table class="pp-table">
                    <thead><tr><th>Aufgabe</th><th>Rolle</th><th>Fällig</th><th>Bei Doppelbesetzung</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($eintraege as $e) :
                        $rv = $e->rollenvorlage_id ? pp_get_rollenvorlage($e->rollenvorlage_id) : null; ?>
                        <tr>
                            <td><?php echo esc_html($e->titel); ?>
                                <?php if ($e->beschreibung) : ?><div class="pp-meta"><?php echo esc_html($e->beschreibung); ?></div><?php endif; ?>
                            </td>
                            <td><?php echo $rv ? esc_html($rv->bezeichnung) : '<span class="pp-meta">ohne feste Rolle</span>'; ?></td>
                            <td><?php echo intval($e->vorlauf_tage); ?> Tage vorher</td>
                            <td class="pp-meta"><?php echo ($e->zuweisung ?? 'eine') === 'alle' ? 'alle Personen' : 'eine Person'; ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <?php wp_nonce_field('pp_front_delete_set_eintrag'); ?>
                                    <input type="hidden" name="action" value="pp_front_delete_set_eintrag">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($e->id); ?>">
                                    <input type="hidden" name="set_id" value="<?php echo esc_attr($set->id); ?>">
                                    <?php pp_front_return_field(); ?>
                                    <button type="submit" class="pp-link-danger">entfernen</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="pp-empty">Noch keine Aufgaben in diesem Set.</p>
            <?php endif; ?>

            <details <?php echo $offen ? 'open' : ''; ?>>
                <summary class="pp-details-summary">+ Aufgabe zum Set hinzufügen</summary>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-form pp-form-grid">
                    <?php wp_nonce_field('pp_front_save_set_eintrag'); ?>
                    <input type="hidden" name="action" value="pp_front_save_set_eintrag">
                    <input type="hidden" name="set_id" value="<?php echo esc_attr($set->id); ?>">
                    <?php pp_front_return_field(); ?>
                    <label>Aufgabe <input type="text" name="titel" placeholder="z. B. Wechselgeld abheben" required></label>
                    <label>Zuständige Rolle
                        <select name="rollenvorlage_id">
                            <option value="">— ohne feste Rolle —</option>
                            <?php foreach ($vorlagen as $rv) : ?>
                                <option value="<?php echo esc_attr($rv->id); ?>"><?php echo esc_html($rv->bezeichnung . ' (' . ($rv->gremium_name ?: '–') . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Vorlauf in Tagen <input type="number" name="vorlauf_tage" value="14" min="0"></label>
                    <label>Bei doppelt besetzter Rolle
                        <select name="zuweisung">
                            <option value="eine">nur eine Person bekommt die Aufgabe</option>
                            <option value="alle">alle Personen der Rolle</option>
                        </select>
                    </label>
                    <label class="pp-span-2">Beschreibung (optional) <textarea name="beschreibung" rows="2"></textarea></label>
                    <div class="pp-form-actions"><button type="submit" class="pp-btn pp-btn-small">Hinzufügen</button></div>
                </form>
            </details>
        </div>
    <?php endforeach; ?>

    <?php if (empty($sets)) : ?><p class="pp-empty">Noch keine Aufgaben-Sets angelegt.</p><?php endif; ?>

    <h3 id="pp-neues-set">Neues Set anlegen</h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-form pp-form-grid">
        <?php wp_nonce_field('pp_front_save_set'); ?>
        <input type="hidden" name="action" value="pp_front_save_set">
        <?php pp_front_return_field(); ?>
        <label>Name <input type="text" name="name" placeholder="z. B. Veranstaltung" required></label>
        <label>Gilt für
            <select name="gremium_id">
                <option value="">alle Kreise</option>
                <?php foreach ($gremien as $g) : ?>
                    <option value="<?php echo esc_attr($g->id); ?>"><?php echo esc_html($g->name); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="pp-span-2">Beschreibung <textarea name="beschreibung" rows="2" placeholder="Wofür wird dieses Set verwendet?"></textarea></label>
        <div class="pp-form-actions"><button type="submit" class="pp-btn pp-btn-primary">Set anlegen</button></div>
    </form>
    <?php
}

// ─── ANSICHT: THEMENSPEICHER ───────────────────────────────────────────────

function pp_render_view_themen() {
    $gremien = pp_get_gremien();
    ?>
    <h2>Themenspeicher</h2>
    <p class="pp-meta">Themen werden je Kreis/Gremium gespeichert. Beim Anlegen eines TOPs stehen die Themen des jeweiligen Kreises sowie kreisübergreifende Themen zur Auswahl.</p>

    <?php foreach ($gremien as $g) :
        $themen = pp_get_themen($g->id); ?>
        <div class="pp-themen-block">
            <h3><?php echo esc_html($g->name); ?></h3>
            <?php if ($themen) : ?>
                <ul class="pp-list">
                    <?php foreach ($themen as $th) : ?>
                        <li><strong><?php echo esc_html($th->titel); ?></strong>
                            <?php if ($th->beschreibung) echo ' – ' . esc_html($th->beschreibung); ?>
                            <span class="pp-meta"><?php echo esc_html($th->status); ?><?php echo $th->svo_teil ? ' · SVO Teil ' . esc_html($th->svo_teil) : ''; ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?><p class="pp-empty">Keine offenen Themen.</p><?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php $ohne = pp_get_themen('ohne'); ?>
    <div class="pp-themen-block">
        <h3>Ohne Kreiszuordnung</h3>
        <?php if ($ohne) : ?>
            <ul class="pp-list">
                <?php foreach ($ohne as $th) : ?>
                    <li><strong><?php echo esc_html($th->titel); ?></strong>
                        <?php if ($th->beschreibung) echo ' – ' . esc_html($th->beschreibung); ?>
                        <span class="pp-meta"><?php echo esc_html($th->status); ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php else : ?><p class="pp-empty">Keine.</p><?php endif; ?>
    </div>

    <h3>Thema vorschlagen</h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-form pp-form-grid">
        <?php wp_nonce_field('pp_front_save_thema'); ?>
        <input type="hidden" name="action" value="pp_front_save_thema">
        <?php pp_front_return_field(); ?>
        <label>Titel <input type="text" name="titel" required></label>
        <label>Kreis / Gremium
            <select name="gremium_id">
                <option value="">— ohne Zuordnung —</option>
                <?php foreach ($gremien as $g) : ?>
                    <option value="<?php echo esc_attr($g->id); ?>"><?php echo esc_html($g->name); ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Betrifft SVO-Teil
            <select name="svo_teil">
                <option value="">— kein Bezug —</option>
                <option value="A">Teil A (nur Vollversammlung)</option>
                <option value="B">Teil B (Kreisordnungen)</option>
                <option value="C">Teil C (kreisintern)</option>
            </select>
        </label>
        <label class="pp-span-2">Beschreibung <textarea name="beschreibung" rows="2"></textarea></label>
        <div class="pp-form-actions"><button type="submit" class="pp-btn pp-btn-primary">Thema anlegen</button></div>
    </form>
    <?php
}

// ─── ANSICHT: AUFGABEN ─────────────────────────────────────────────────────

function pp_render_view_aufgaben() {
    $user_id = get_current_user_id();
    $meine   = pp_get_meine_aufgaben($user_id);
    $alle    = pp_get_alle_offenen_aufgaben();
    $gremien = pp_get_gremien();
    ?>
    <h2>Aufgaben</h2>

    <h3>Meine offenen Aufgaben</h3>
    <ul class="pp-list pp-aufgabenliste">
        <?php foreach ($meine as $a) : ?>
            <li>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline">
                    <?php wp_nonce_field('pp_front_toggle_aufgabe'); ?>
                    <input type="hidden" name="action" value="pp_front_toggle_aufgabe">
                    <input type="hidden" name="id" value="<?php echo esc_attr($a->id); ?>">
                    <input type="hidden" name="ziel_view" value="aufgaben">
                    <?php pp_front_return_field(); ?>
                    <button type="submit" class="pp-checkbox" title="Als erledigt markieren">☐</button>
                </form>
                <?php echo esc_html($a->titel); ?>
                <span class="pp-meta"><?php echo esc_html($a->faelligkeitsdatum ?: 'ohne Frist'); ?></span>
            </li>
        <?php endforeach; ?>
        <?php if (empty($meine)) : ?><li class="pp-empty">Nichts offen.</li><?php endif; ?>
    </ul>

    <h3>Alle offenen Aufgaben</h3>
    <table class="pp-table">
        <thead><tr><th>Aufgabe</th><th>Verantwortlich</th><th>Gremium</th><th>Fällig</th><th>Herkunft</th></tr></thead>
        <tbody>
        <?php foreach ($alle as $a) : ?>
            <tr>
                <td><?php echo esc_html($a->titel); ?></td>
                <td><?php echo esc_html(pp_user_display_name($a->verantwortlich_user_id)); ?></td>
                <td><?php echo esc_html($a->gremium_name ?: '–'); ?></td>
                <td><?php echo esc_html($a->faelligkeitsdatum ?: '–'); ?></td>
                <td class="pp-meta">
                    <?php
                    if (!empty($a->quelle_protokoll_id)) {
                        echo '<a href="' . esc_url(pp_front_url(['pp_view' => 'protokoll', 'id' => $a->quelle_protokoll_id])) . '">in Sitzung erfasst</a>';
                    } elseif (!empty($a->quelle_top_id)) {
                        echo 'aus Beschluss';
                    } elseif (!empty($a->quelle_rollenvorlage_aufgabe_id)) {
                        echo 'wiederkehrend/Rolle';
                    } else {
                        echo 'manuell';
                    }
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($alle)) : ?><tr><td colspan="5" class="pp-empty">Keine offenen Aufgaben.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <h3>Aufgabe anlegen</h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline-form">
        <?php wp_nonce_field('pp_front_quick_aufgabe'); ?>
        <input type="hidden" name="action" value="pp_front_quick_aufgabe">
        <input type="hidden" name="ziel_view" value="aufgaben">
        <?php pp_front_return_field(); ?>
        <input type="text" name="titel" placeholder="Aufgabe" required>
        <select name="verantwortlich_user_id">
            <option value="">Verantwortlich…</option>
            <?php foreach (pp_get_moegliche_mitglieder() as $u) : ?>
                <option value="<?php echo esc_attr($u->ID); ?>"><?php echo esc_html($u->display_name); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="gremium_id">
            <option value="">Gremium…</option>
            <?php foreach ($gremien as $g) : ?>
                <option value="<?php echo esc_attr($g->id); ?>"><?php echo esc_html($g->name); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="faelligkeitsdatum">
        <button type="submit" class="pp-btn pp-btn-small">Anlegen</button>
    </form>
    <?php
}

// ─── ANSICHT: TERMINE ──────────────────────────────────────────────────────

function pp_render_view_termine() {
    $termine = pp_get_naechste_termine(50);
    $gremien = pp_get_gremien();
    $sets    = pp_get_aufgaben_sets();
    ?>
    <h2>Termine</h2>
    <p class="pp-meta">Geplante Sitzungen erscheinen hier automatisch, sobald ein Datum eingetragen ist. Über „Set anwenden“ entstehen aus einem Aufgaben-Set alle Vorbereitungsaufgaben für einen Termin auf einmal.</p>

    <?php if (isset($_GET['pp_set_erzeugt'])) : ?>
        <div class="pp-front-notice pp-front-notice-success">
            <?php echo intval($_GET['pp_set_erzeugt']); ?> Aufgabe(n) aus dem Set erzeugt.
            <?php if (!empty($_GET['pp_set_uebersprungen'])) : ?>
                <?php echo intval($_GET['pp_set_uebersprungen']); ?> übersprungen, weil sie für diesen Termin bereits existieren.
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <table class="pp-table">
        <thead><tr><th>Termin</th><th>Datum</th><th>Ort</th><th>Gremium</th><th>Herkunft</th><th>Vorbereitung</th></tr></thead>
        <tbody>
        <?php foreach ($termine as $t) : ?>
            <tr>
                <td><?php echo esc_html($t->titel); ?></td>
                <td><?php echo esc_html(mysql2date('d.m.Y H:i', $t->datum)); ?></td>
                <td><?php echo esc_html($t->ort); ?></td>
                <td><?php echo esc_html($t->gremium_name ?: '–'); ?></td>
                <td class="pp-meta">
                    <?php
                    if ($t->quelle_protokoll_id) {
                        echo '<a href="' . esc_url(pp_front_url(['pp_view' => 'protokoll', 'id' => $t->quelle_protokoll_id])) . '">geplante Sitzung</a>';
                    } elseif ($t->quelle_top_id) {
                        echo 'aus Protokoll';
                    } else {
                        echo 'manuell';
                    }
                    ?>
                </td>
                <td>
                    <?php if ($sets && $t->datum) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline-form">
                            <?php wp_nonce_field('pp_front_set_anwenden'); ?>
                            <input type="hidden" name="action" value="pp_front_set_anwenden">
                            <input type="hidden" name="termin_id" value="<?php echo esc_attr($t->id); ?>">
                            <?php pp_front_return_field(); ?>
                            <select name="set_id" required>
                                <option value="">Set…</option>
                                <?php foreach ($sets as $s) :
                                    // Kreisspezifische Sets nur beim passenden Gremium anbieten
                                    if ($s->gremium_id && intval($s->gremium_id) !== intval($t->gremium_id)) continue; ?>
                                    <option value="<?php echo esc_attr($s->id); ?>"><?php echo esc_html($s->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="pp-btn pp-btn-small">anwenden</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($termine)) : ?><tr><td colspan="6" class="pp-empty">Keine Termine.</td></tr><?php endif; ?>
        </tbody>
    </table>

    <h3>Termin anlegen</h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline-form">
        <?php wp_nonce_field('pp_front_quick_termin'); ?>
        <input type="hidden" name="action" value="pp_front_quick_termin">
        <input type="hidden" name="ziel_view" value="termine">
        <?php pp_front_return_field(); ?>
        <input type="text" name="titel" placeholder="Termin" required>
        <input type="datetime-local" name="datum">
        <input type="text" name="ort" placeholder="Ort">
        <select name="gremium_id">
            <option value="">Gremium…</option>
            <?php foreach ($gremien as $g) : ?>
                <option value="<?php echo esc_attr($g->id); ?>"><?php echo esc_html($g->name); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="pp-btn pp-btn-small">Anlegen</button>
    </form>
    <?php
}

// ─── ANSICHT: KALENDER ─────────────────────────────────────────────────────

function pp_render_view_kalender() {
    $ics_url    = pp_get_ics_feed_url(get_current_user_id());
    $ics_webcal = preg_replace('#^https?://#', 'webcal://', $ics_url);
    ?>
    <h2>Kalender-Sync</h2>
    <p>Diesen Link in eurer Kalender-App (Google, Apple, Outlook …) als „Kalender abonnieren" hinterlegen. Eure offenen Aufgaben mit Fälligkeitsdatum und die Termine eurer Gremien — inklusive der geplanten Sitzungen — erscheinen dann automatisch und aktualisieren sich von selbst.</p>
    <p><a href="<?php echo esc_url($ics_webcal); ?>" class="pp-btn pp-btn-primary">Kalender abonnieren</a></p>
    <p class="pp-meta">Falls der Klick nicht funktioniert, diesen Link manuell eintragen:<br><code><?php echo esc_html($ics_url); ?></code></p>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('pp_front_regenerate_ics'); ?>
        <input type="hidden" name="action" value="pp_front_regenerate_ics">
        <?php pp_front_return_field(); ?>
        <button type="submit" class="pp-btn pp-btn-small"
                onclick="return confirm('Neuen Link erzeugen? Der alte funktioniert danach nicht mehr.');">Link zurücksetzen</button>
    </form>
    <?php
}
