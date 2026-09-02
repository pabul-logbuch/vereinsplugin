<?php
defined('ABSPATH') || exit;

// ─── EVENT SPEICHERN ──────────────────────────────────────────────────────

add_action('wp_ajax_wl_save_event', 'wl_ajax_save_event');
function wl_ajax_save_event() {
    if (!check_ajax_referer('wl_nonce', 'nonce', false)) wp_send_json_error(['msg' => 'Sicherheitscheck fehlgeschlagen.']);
    if (!wl_can_manage()) wp_send_json_error(['msg' => 'Keine Berechtigung.']);

    global $wpdb;
    $table = $wpdb->prefix . 'wl_shift_events';

    $id    = intval($_POST['id'] ?? 0);
    $titel = sanitize_text_field($_POST['titel'] ?? '');
    if (empty($titel)) wp_send_json_error(['msg' => 'Titel ist Pflichtfeld.']);

    $data = [
        'titel'        => $titel,
        'beschreibung' => sanitize_textarea_field($_POST['beschreibung'] ?? ''),
        'veranstaltungsdatum' => !empty($_POST['veranstaltungsdatum']) ? sanitize_text_field($_POST['veranstaltungsdatum']) : null,
    ];

    if ($id > 0) {
        $wpdb->update($table, $data, ['id' => $id]);
    } else {
        $data['slug'] = wl_generate_event_slug($titel);
        $data['aktiv'] = 1;
        $data['erstellt_von'] = get_current_user_id();
        $wpdb->insert($table, $data);
        $id = $wpdb->insert_id;
    }

    wp_send_json_success(['msg' => 'Veranstaltung gespeichert.', 'event' => wl_get_event($id)]);
}

// ─── EVENT AKTIV/INAKTIV TOGGLE ───────────────────────────────────────────

add_action('wp_ajax_wl_toggle_event', 'wl_ajax_toggle_event');
function wl_ajax_toggle_event() {
    if (!check_ajax_referer('wl_nonce', 'nonce', false)) wp_send_json_error(['msg' => 'Sicherheitscheck fehlgeschlagen.']);
    if (!wl_can_manage()) wp_send_json_error(['msg' => 'Keine Berechtigung.']);

    global $wpdb;
    $table = $wpdb->prefix . 'wl_shift_events';
    $id = intval($_POST['id'] ?? 0);
    $event = wl_get_event($id);
    if (!$event) wp_send_json_error(['msg' => 'Nicht gefunden.']);

    $new_status = $event->aktiv ? 0 : 1;
    $wpdb->update($table, ['aktiv' => $new_status], ['id' => $id]);

    wp_send_json_success(['msg' => 'Status geändert.', 'aktiv' => $new_status]);
}

// ─── EVENT LÖSCHEN ────────────────────────────────────────────────────────

add_action('wp_ajax_wl_delete_event', 'wl_ajax_delete_event');
function wl_ajax_delete_event() {
    if (!check_ajax_referer('wl_nonce', 'nonce', false)) wp_send_json_error(['msg' => 'Sicherheitscheck fehlgeschlagen.']);
    if (!wl_can_manage()) wp_send_json_error(['msg' => 'Keine Berechtigung.']);

    global $wpdb;
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(['msg' => 'Ungültige ID.']);

    // Kaskadierend löschen: Eintragungen -> Schichten -> Stationen -> Event
    $stationen = wl_get_stationen($id);
    foreach ($stationen as $station) {
        $schichten = wl_get_schichten($station->id);
        foreach ($schichten as $schicht) {
            $wpdb->delete($wpdb->prefix . 'wl_shift_eintragungen', ['schicht_id' => $schicht->id]);
        }
        $wpdb->delete($wpdb->prefix . 'wl_shift_schichten', ['station_id' => $station->id]);
    }
    $wpdb->delete($wpdb->prefix . 'wl_shift_stationen', ['event_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'wl_shift_events', ['id' => $id]);

    wp_send_json_success(['msg' => 'Veranstaltung gelöscht.', 'id' => $id]);
}

// ─── STATION SPEICHERN ────────────────────────────────────────────────────

add_action('wp_ajax_wl_save_station', 'wl_ajax_save_station');
function wl_ajax_save_station() {
    if (!check_ajax_referer('wl_nonce', 'nonce', false)) wp_send_json_error(['msg' => 'Sicherheitscheck fehlgeschlagen.']);
    if (!wl_can_manage()) wp_send_json_error(['msg' => 'Keine Berechtigung.']);

    global $wpdb;
    $table = $wpdb->prefix . 'wl_shift_stationen';

    $id    = intval($_POST['id'] ?? 0);
    $event_id = intval($_POST['event_id'] ?? 0);
    $titel = sanitize_text_field($_POST['titel'] ?? '');
    if (empty($titel) || !$event_id) wp_send_json_error(['msg' => 'Titel und Event sind Pflichtfelder.']);

    $data = [
        'event_id'    => $event_id,
        'titel'       => $titel,
        'beschreibung'=> sanitize_textarea_field($_POST['beschreibung'] ?? ''),
        'treffpunkt'  => sanitize_text_field($_POST['treffpunkt'] ?? ''),
        'ansprechperson1' => sanitize_text_field($_POST['ansprechperson1'] ?? ''),
        'ansprechperson1_kontakt' => sanitize_text_field($_POST['ansprechperson1_kontakt'] ?? ''),
        'ansprechperson2' => sanitize_text_field($_POST['ansprechperson2'] ?? ''),
        'ansprechperson2_kontakt' => sanitize_text_field($_POST['ansprechperson2_kontakt'] ?? ''),
    ];

    if ($id > 0) {
        $wpdb->update($table, $data, ['id' => $id]);
    } else {
        $wpdb->insert($table, $data);
        $id = $wpdb->insert_id;
    }

    wp_send_json_success(['msg' => 'Station gespeichert.', 'id' => $id]);
}

// ─── STATION LÖSCHEN ──────────────────────────────────────────────────────

add_action('wp_ajax_wl_delete_station', 'wl_ajax_delete_station');
function wl_ajax_delete_station() {
    if (!check_ajax_referer('wl_nonce', 'nonce', false)) wp_send_json_error(['msg' => 'Sicherheitscheck fehlgeschlagen.']);
    if (!wl_can_manage()) wp_send_json_error(['msg' => 'Keine Berechtigung.']);

    global $wpdb;
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(['msg' => 'Ungültige ID.']);

    $schichten = wl_get_schichten($id);
    foreach ($schichten as $schicht) {
        $wpdb->delete($wpdb->prefix . 'wl_shift_eintragungen', ['schicht_id' => $schicht->id]);
    }
    $wpdb->delete($wpdb->prefix . 'wl_shift_schichten', ['station_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'wl_shift_stationen', ['id' => $id]);

    wp_send_json_success(['msg' => 'Station gelöscht.', 'id' => $id]);
}

// ─── SCHICHT SPEICHERN ────────────────────────────────────────────────────

add_action('wp_ajax_wl_save_schicht', 'wl_ajax_save_schicht');
function wl_ajax_save_schicht() {
    if (!check_ajax_referer('wl_nonce', 'nonce', false)) wp_send_json_error(['msg' => 'Sicherheitscheck fehlgeschlagen.']);
    if (!wl_can_manage()) wp_send_json_error(['msg' => 'Keine Berechtigung.']);

    global $wpdb;
    $table = $wpdb->prefix . 'wl_shift_schichten';

    $id         = intval($_POST['id'] ?? 0);
    $station_id = intval($_POST['station_id'] ?? 0);
    $min_plaetze = max(0, intval($_POST['min_plaetze'] ?? 0));
    $max_plaetze = max(1, intval($_POST['max_plaetze'] ?? 1));
    if ($min_plaetze > $max_plaetze) $min_plaetze = $max_plaetze;
    if (!$station_id) wp_send_json_error(['msg' => 'Station fehlt.']);

    $data = [
        'station_id'  => $station_id,
        'titel'       => sanitize_text_field($_POST['titel'] ?? ''),
        'start_zeit'  => !empty($_POST['start_zeit']) ? sanitize_text_field(str_replace('T', ' ', $_POST['start_zeit'])) : null,
        'end_zeit'    => !empty($_POST['end_zeit']) ? sanitize_text_field(str_replace('T', ' ', $_POST['end_zeit'])) : null,
        'min_plaetze' => $min_plaetze,
        'max_plaetze' => $max_plaetze,
    ];

    if ($id > 0) {
        $wpdb->update($table, $data, ['id' => $id]);
    } else {
        $wpdb->insert($table, $data);
        $id = $wpdb->insert_id;
    }

    wp_send_json_success(['msg' => 'Schicht gespeichert.', 'id' => $id]);
}

// ─── SCHICHT LÖSCHEN ──────────────────────────────────────────────────────

add_action('wp_ajax_wl_delete_schicht', 'wl_ajax_delete_schicht');
function wl_ajax_delete_schicht() {
    if (!check_ajax_referer('wl_nonce', 'nonce', false)) wp_send_json_error(['msg' => 'Sicherheitscheck fehlgeschlagen.']);
    if (!wl_can_manage()) wp_send_json_error(['msg' => 'Keine Berechtigung.']);

    global $wpdb;
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(['msg' => 'Ungültige ID.']);

    $wpdb->delete($wpdb->prefix . 'wl_shift_eintragungen', ['schicht_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'wl_shift_schichten', ['id' => $id]);

    wp_send_json_success(['msg' => 'Schicht gelöscht.', 'id' => $id]);
}

// ─── EINTRAGUNG ENTFERNEN (durch Mitglied im Admin-Bereich) ──────────────

add_action('wp_ajax_wl_remove_eintrag', 'wl_ajax_remove_eintrag');
function wl_ajax_remove_eintrag() {
    if (!check_ajax_referer('wl_nonce', 'nonce', false)) wp_send_json_error(['msg' => 'Sicherheitscheck fehlgeschlagen.']);
    if (!wl_can_manage()) wp_send_json_error(['msg' => 'Keine Berechtigung.']);

    global $wpdb;
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error(['msg' => 'Ungültige ID.']);

    $wpdb->delete($wpdb->prefix . 'wl_shift_eintragungen', ['id' => $id]);
    wp_send_json_success(['msg' => 'Eintragung entfernt.', 'id' => $id]);
}

// ─── ÖFFENTLICH: FÜR SCHICHT EINTRAGEN ────────────────────────────────────

add_action('wp_ajax_wl_schicht_eintragen', 'wl_ajax_schicht_eintragen');
add_action('wp_ajax_nopriv_wl_schicht_eintragen', 'wl_ajax_schicht_eintragen');

function wl_ajax_schicht_eintragen() {
    if (!check_ajax_referer('wl_nonce', 'nonce', false)) {
        wp_send_json_error(['msg' => 'Sicherheitscheck fehlgeschlagen.']);
    }

    $schicht_id = intval($_POST['schicht_id'] ?? 0);
    $name       = sanitize_text_field($_POST['name'] ?? '');
    $email      = sanitize_email($_POST['email'] ?? '');
    $telefon    = sanitize_text_field($_POST['telefon'] ?? '');

    if (empty($name) || empty($email) || !is_email($email)) {
        wp_send_json_error(['msg' => 'Bitte Name und gültige E-Mail-Adresse angeben.']);
    }

    $schicht = wl_get_schicht($schicht_id);
    if (!$schicht) {
        wp_send_json_error(['msg' => 'Schicht nicht gefunden.']);
    }

    // Doppel-Eintragung verhindern: prüfen ob diese E-Mail schon in dieser Schicht steht
    global $wpdb;
    $table = $wpdb->prefix . 'wl_shift_eintragungen';
    $bereits = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE schicht_id = %d AND email = %s",
        $schicht_id, $email
    ));
    if ($bereits > 0) {
        wp_send_json_error(['msg' => 'Du bist für diese Schicht bereits eingetragen.']);
    }

    $belegt = wl_count_eintragungen($schicht_id);
    if ($belegt >= $schicht->max_plaetze) {
        wp_send_json_error(['msg' => 'Diese Schicht ist leider bereits voll.']);
    }
    $manage_key = wp_generate_password(32, false);

    $wpdb->insert($table, [
        'schicht_id' => $schicht_id,
        'name'       => $name,
        'email'      => $email,
        'telefon'    => $telefon,
        'user_id'    => is_user_logged_in() ? get_current_user_id() : null,
        'manage_key' => $manage_key,
    ]);

    // Station/Event für E-Mail-Kontext laden
    $station = wl_get_station($schicht->station_id);
    $event   = $station ? wl_get_event($station->event_id) : null;

    if ($event) {
        $abmelde_link = wl_get_abmelde_link($manage_key, $event->slug);
        $ics_link     = wl_get_ics_download_url($manage_key);

        // Prüfen, ob die Person schon weitere Schichten bei dieser Veranstaltung hat
        $gruppen = wl_get_eintragungen_gruppiert_nach_person($event->id);
        $key = strtolower(trim($email));
        $weitere_anzahl = isset($gruppen[$key]) ? count($gruppen[$key]['eintragungen']) : 1;

        $betreff = 'Bestätigung: Schicht-Eintragung für ' . $event->titel;
        $body  = "Hallo $name,\n\n";
        $body .= "du hast dich erfolgreich für folgende Schicht eingetragen:\n\n";
        $body .= "Veranstaltung: " . $event->titel . "\n";
        $body .= "Station:       " . $station->titel . "\n";
        if ($schicht->titel) $body .= "Schicht:       " . $schicht->titel . "\n";
        if ($schicht->start_zeit) {
            $body .= "Zeit:          " . date('d.m.Y H:i', strtotime($schicht->start_zeit));
            if ($schicht->end_zeit) $body .= ' – ' . date('H:i', strtotime($schicht->end_zeit)) . ' Uhr';
            $body .= "\n";
        }
        if ($station->treffpunkt) $body .= "Treffpunkt:    " . $station->treffpunkt . "\n";
        if ($station->ansprechperson1) $body .= "Ansprechperson: " . $station->ansprechperson1 . "\n";
        if ($schicht->start_zeit) {
            $body .= "\n📅 Kalendereintrag herunterladen (Google/Apple/Outlook): " . $ics_link . "\n";
        }

        if ($weitere_anzahl > 1) {
            $sammel_ics_link = wl_get_sammel_ics_download_url($event->id, $email);
            $body .= "\nDu bist jetzt für insgesamt $weitere_anzahl Schichten bei dieser Veranstaltung eingetragen.\n";
            $body .= "📅 Alle deine Schichten auf einmal in den Kalender importieren: " . $sammel_ics_link . "\n";
        }

        $body .= "\nDu bekommst außerdem 24h vor der Veranstaltung automatisch eine Erinnerungsmail mit all deinen Schichten.\n";
        $body .= "\nFalls du doch nicht kannst, trage dich bitte über diesen Link wieder aus:\n";
        $body .= $abmelde_link . "\n\n";
        $body .= "Danke für deine Unterstützung!\n" . get_bloginfo('name');

        wp_mail($email, $betreff, $body);

        $belegt_neu = $belegt + 1;

        wp_send_json_success([
            'msg' => 'Du bist eingetragen! Eine Bestätigung wurde an ' . $email . ' geschickt.',
            'belegt' => $belegt_neu,
            'frei' => max(0, $schicht->max_plaetze - $belegt_neu),
            'voll' => $belegt_neu >= $schicht->max_plaetze,
            'name' => $name,
            'abmelde_link' => $abmelde_link,
            'ics_link' => $schicht->start_zeit ? $ics_link : null,
        ]);
    } else {
        wp_send_json_success(['msg' => 'Du bist eingetragen!', 'belegt' => $belegt + 1, 'frei' => max(0, $schicht->max_plaetze - $belegt - 1), 'voll' => ($belegt + 1) >= $schicht->max_plaetze, 'name' => $name]);
    }
}

// ─── ADMIN: PERSON MANUELL EINTRAGEN ──────────────────────────────────────

add_action('wp_ajax_wl_admin_eintragen', 'wl_ajax_admin_eintragen');
function wl_ajax_admin_eintragen() {
    if (!check_ajax_referer('wl_nonce', 'nonce', false)) wp_send_json_error(['msg' => 'Sicherheitscheck fehlgeschlagen.']);
    if (!wl_can_manage()) wp_send_json_error(['msg' => 'Keine Berechtigung.']);

    $schicht_id = intval($_POST['schicht_id'] ?? 0);
    $name       = sanitize_text_field($_POST['name'] ?? '');
    $email      = sanitize_email($_POST['email'] ?? '');
    $telefon    = sanitize_text_field($_POST['telefon'] ?? '');

    if (empty($name)) wp_send_json_error(['msg' => 'Name ist Pflichtfeld.']);
    $schicht = wl_get_schicht($schicht_id);
    if (!$schicht) wp_send_json_error(['msg' => 'Schicht nicht gefunden.']);

    // Duplikat-Check auch für Admin
    if (!empty($email)) {
        global $wpdb;
        $bereits = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wl_shift_eintragungen WHERE schicht_id = %d AND email = %s",
            $schicht_id, $email
        ));
        if ($bereits > 0) wp_send_json_error(['msg' => $name . ' ist für diese Schicht bereits eingetragen.']);
    }

    $belegt = wl_count_eintragungen($schicht_id);
    if ($belegt >= $schicht->max_plaetze) wp_send_json_error(['msg' => 'Schicht ist bereits voll.']);

    global $wpdb;
    $manage_key = wp_generate_password(32, false);
    $wpdb->insert($wpdb->prefix . 'wl_shift_eintragungen', [
        'schicht_id' => $schicht_id,
        'name'       => $name,
        'email'      => $email,
        'telefon'    => $telefon,
        'user_id'    => null,
        'manage_key' => $manage_key,
    ]);
    $eintrag_id = $wpdb->insert_id;

    // Optional: Bestätigungsmail an die Person, wenn E-Mail angegeben
    if (!empty($email) && is_email($email)) {
        $station = wl_get_station($schicht->station_id);
        $event   = $station ? wl_get_event($station->event_id) : null;
        if ($event) {
            $abmelde_link = wl_get_abmelde_link($manage_key, $event->slug);
            $betreff = 'Du wurdest für eine Schicht eingetragen: ' . $event->titel;
            $body  = "Hallo $name,\n\n";
            $body .= "du wurdest von der Veranstaltungsleitung für folgende Schicht eingetragen:\n\n";
            $body .= "Veranstaltung: " . $event->titel . "\n";
            $body .= "Station:       " . $station->titel . "\n";
            if ($schicht->titel) $body .= "Schicht:       " . $schicht->titel . "\n";
            if ($schicht->start_zeit) {
                $body .= "Zeit:          " . date('d.m.Y H:i', strtotime($schicht->start_zeit));
                if ($schicht->end_zeit) $body .= ' – ' . date('H:i', strtotime($schicht->end_zeit)) . ' Uhr';
                $body .= "\n";
            }
            if ($station->treffpunkt) $body .= "Treffpunkt:    " . $station->treffpunkt . "\n";
            $body .= "\nFalls du doch nicht kannst, trage dich bitte aus:\n" . $abmelde_link . "\n\n";
            $body .= get_bloginfo('name');
            wp_mail($email, $betreff, $body);
        }
    }

    $belegt_neu = $belegt + 1;
    wp_send_json_success([
        'msg'   => $name . ' wurde eingetragen.',
        'name'  => $name,
        'email' => $email,
        'id'    => $eintrag_id,
        'belegt' => $belegt_neu,
        'voll'  => $belegt_neu >= $schicht->max_plaetze,
    ]);
}

// ─── TAUSCH-ANFRAGE SENDEN ─────────────────────────────────────────────────

add_action('wp_ajax_wl_tausch_anfrage',        'wl_ajax_tausch_anfrage');
add_action('wp_ajax_nopriv_wl_tausch_anfrage', 'wl_ajax_tausch_anfrage');

function wl_ajax_tausch_anfrage() {
    if (!check_ajax_referer('wl_nonce', 'nonce', false)) wp_send_json_error(['msg' => 'Sicherheitscheck fehlgeschlagen.']);

    $manage_key = sanitize_text_field($_POST['manage_key'] ?? '');
    $an_email   = sanitize_email($_POST['an_email'] ?? '');
    $an_name    = sanitize_text_field($_POST['an_name'] ?? '');

    if (empty($manage_key) || empty($an_email) || !is_email($an_email)) {
        wp_send_json_error(['msg' => 'Bitte eine gültige E-Mail-Adresse angeben.']);
    }

    $eintragung = wl_get_eintragung_by_key($manage_key);
    if (!$eintragung) wp_send_json_error(['msg' => 'Eintragung nicht gefunden.']);

    $schicht = wl_get_schicht($eintragung->schicht_id);
    $station = $schicht ? wl_get_station($schicht->station_id) : null;
    $event   = $station ? wl_get_event($station->event_id) : null;
    if (!$event) wp_send_json_error(['msg' => 'Veranstaltungsdaten nicht gefunden.']);

    // Tausch-Eintrag speichern
    global $wpdb;
    $tausch_key = wp_generate_password(32, false);
    $wpdb->insert($wpdb->prefix . 'wl_shift_tausch', [
        'von_eintrag_id' => $eintragung->id,
        'an_email'       => $an_email,
        'tausch_key'     => $tausch_key,
        'status'         => 'offen',
    ]);

    $annehmen_url = add_query_arg('wl_tausch_annehmen', $tausch_key, home_url('/'));
    $annehmen_url = wp_nonce_url($annehmen_url, 'wl_tausch_' . $tausch_key);
    $ablehnen_url = add_query_arg('wl_tausch_ablehnen', $tausch_key, home_url('/'));
    $ablehnen_url = wp_nonce_url($ablehnen_url, 'wl_tausch_' . $tausch_key);

    $schicht_info  = $station->titel . ($schicht->titel ? ' – ' . $schicht->titel : '');
    if ($schicht->start_zeit) {
        $schicht_info .= ' (' . date('d.m. H:i', strtotime($schicht->start_zeit));
        if ($schicht->end_zeit) $schicht_info .= '–' . date('H:i', strtotime($schicht->end_zeit));
        $schicht_info .= ' Uhr)';
    }

    // Mail an die angefragte Person
    $betreff_an = $eintragung->name . ' möchte die Schicht mit dir tauschen – ' . $event->titel;
    $body_an  = "Hallo" . ($an_name ? " $an_name" : "") . ",\n\n";
    $body_an .= $eintragung->name . " fragt, ob du seine/ihre Schicht übernehmen kannst:\n\n";
    $body_an .= "Veranstaltung: " . $event->titel . "\n";
    $body_an .= "Schicht:       " . $schicht_info . "\n";
    if ($station->treffpunkt) $body_an .= "Treffpunkt:    " . $station->treffpunkt . "\n";
    $body_an .= "\n✅ Schicht übernehmen: " . $annehmen_url . "\n";
    $body_an .= "❌ Ablehnen:           " . $ablehnen_url . "\n\n";
    $body_an .= get_bloginfo('name');
    wp_mail($an_email, $betreff_an, $body_an);

    // Bestätigung an den Anfragenden
    $betreff_von = 'Tausch-Anfrage gesendet – ' . $event->titel;
    $body_von  = "Hallo " . $eintragung->name . ",\n\n";
    $body_von .= "deine Tausch-Anfrage wurde an $an_email gesendet.\n";
    $body_von .= "Du wirst per E-Mail benachrichtigt, sobald die Person antwortet.\n\n";
    $body_von .= "Schicht: " . $schicht_info . "\n\n";
    $body_von .= get_bloginfo('name');
    if (!empty($eintragung->email)) wp_mail($eintragung->email, $betreff_von, $body_von);

    wp_send_json_success(['msg' => 'Tausch-Anfrage wurde an ' . $an_email . ' geschickt.']);
}

// ─── TAUSCH ANNEHMEN / ABLEHNEN ────────────────────────────────────────────

add_action('init', 'wl_init_handle_tausch');
function wl_init_handle_tausch() {
    $aktion = null;
    $tausch_key = null;

    if (!empty($_GET['wl_tausch_annehmen'])) {
        $aktion = 'annehmen';
        $tausch_key = sanitize_text_field($_GET['wl_tausch_annehmen']);
    } elseif (!empty($_GET['wl_tausch_ablehnen'])) {
        $aktion = 'ablehnen';
        $tausch_key = sanitize_text_field($_GET['wl_tausch_ablehnen']);
    }

    if (!$tausch_key || !$aktion) return;
    if (!wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', 'wl_tausch_' . $tausch_key)) {
        wp_die('Ungültiger oder abgelaufener Link.', 'Fehler', ['response' => 403]);
    }

    global $wpdb;
    $tt = $wpdb->prefix . 'wl_shift_tausch';
    $et = $wpdb->prefix . 'wl_shift_eintragungen';

    $tausch = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tt WHERE tausch_key = %s AND status = 'offen'", $tausch_key));
    if (!$tausch) wp_die('Diese Tausch-Anfrage ist nicht mehr gültig oder wurde bereits beantwortet.', 'Nicht mehr gültig', ['response' => 410]);

    $alte_eintragung = $wpdb->get_row($wpdb->prepare("SELECT * FROM $et WHERE id = %d", $tausch->von_eintrag_id));
    if (!$alte_eintragung) {
        $wpdb->update($tt, ['status' => 'abgelaufen'], ['tausch_key' => $tausch_key]);
        wp_die('Die ursprüngliche Eintragung existiert nicht mehr.', 'Nicht gefunden', ['response' => 404]);
    }

    if ($aktion === 'ablehnen') {
        $wpdb->update($tt, ['status' => 'abgelehnt'], ['tausch_key' => $tausch_key]);
        if (!empty($alte_eintragung->email)) {
            wp_mail($alte_eintragung->email,
                'Tausch-Anfrage abgelehnt',
                "Hallo " . $alte_eintragung->name . ",\n\ndeine Tausch-Anfrage an " . $tausch->an_email . " wurde abgelehnt.\nDu bist weiterhin für die Schicht eingetragen.\n\n" . get_bloginfo('name')
            );
        }
        wp_die('Tausch-Anfrage abgelehnt. Die ursprüngliche Person bleibt eingetragen.', 'Abgelehnt');
    }

    // Annehmen: alte Eintragung auf neue Person umschreiben
    $schicht = wl_get_schicht($alte_eintragung->schicht_id);
    $station = $schicht ? wl_get_station($schicht->station_id) : null;
    $event   = $station ? wl_get_event($station->event_id) : null;

    $neuer_manage_key = wp_generate_password(32, false);
    $wpdb->update($et, [
        'email'      => $tausch->an_email,
        'name'       => $tausch->an_email, // Name noch unbekannt — Person kann sich selbst austragen/neu eintragen
        'manage_key' => $neuer_manage_key,
        'erinnerung_gesendet' => 0,
    ], ['id' => $alte_eintragung->id]);

    $wpdb->update($tt, ['status' => 'angenommen'], ['tausch_key' => $tausch_key]);

    // Benachrichtigungen
    if (!empty($alte_eintragung->email)) {
        wp_mail($alte_eintragung->email,
            'Tausch-Anfrage angenommen – du bist ausgetragen',
            "Hallo " . $alte_eintragung->name . ",\n\n" . $tausch->an_email . " hat deine Schicht übernommen. Du bist nun ausgetragen.\n\n" . get_bloginfo('name')
        );
    }

    if ($event) {
        $neuer_abmelde_link = wl_get_abmelde_link($neuer_manage_key, $event->slug);
        $schicht_info = $station->titel . ($schicht->titel ? ' – ' . $schicht->titel : '');
        wp_mail($tausch->an_email,
            'Du hast eine Schicht übernommen – ' . $event->titel,
            "Hallo,\n\ndu hast die folgende Schicht übernommen:\n\nVeranstaltung: " . $event->titel . "\nSchicht: " . $schicht_info . "\n\nFalls du doch nicht kannst:\n" . $neuer_abmelde_link . "\n\n" . get_bloginfo('name')
        );
    }

    wp_die('✅ Du hast die Schicht übernommen! Eine Bestätigung wurde per E-Mail geschickt.', 'Tausch angenommen');
}
