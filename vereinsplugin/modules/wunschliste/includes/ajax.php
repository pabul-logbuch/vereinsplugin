<?php
defined('ABSPATH') || exit;

// ─── WUNSCH SPEICHERN (Mitglieder) ───────────────────────────────────────

add_action('wp_ajax_wl_save_wunsch', 'wl_ajax_save_wunsch');
function wl_ajax_save_wunsch() {
    if (!check_ajax_referer('wl_nonce', 'wl_nonce', false)) {
        wp_send_json_error(['msg' => 'Sicherheitscheck fehlgeschlagen.']);
    }
    if (!wl_can_manage()) {
        wp_send_json_error(['msg' => 'Keine Berechtigung.']);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'wunschliste';

    $id          = intval($_POST['id'] ?? 0);
    $titel       = sanitize_text_field($_POST['titel'] ?? '');
    $beschreibung = sanitize_textarea_field($_POST['beschreibung'] ?? '');
    $begruendung = sanitize_textarea_field($_POST['begruendung'] ?? '');
    $betrag      = floatval($_POST['betrag'] ?? 0);
    $preis_von   = !empty($_POST['preis_von']) ? floatval($_POST['preis_von']) : null;
    $preis_bis   = !empty($_POST['preis_bis']) ? floatval($_POST['preis_bis']) : null;
    $kategorie   = sanitize_text_field($_POST['kategorie'] ?? '');
    $status      = in_array($_POST['status'], ['offen','in_bearbeitung','erfuellt']) ? $_POST['status'] : 'offen';
    $prioritaet  = intval($_POST['prioritaet'] ?? 2);
    $bild_url    = esc_url_raw($_POST['bild_url'] ?? '');

    if (empty($titel)) {
        wp_send_json_error(['msg' => 'Titel ist Pflichtfeld.']);
    }

    // Wenn Preisspanne angegeben ist, Festbetrag leeren (und umgekehrt) – je nachdem was im Formular aktiv war
    if ($preis_von !== null || $preis_bis !== null) {
        $betrag = 0;
    }

    $data = compact('titel', 'beschreibung', 'begruendung', 'betrag', 'preis_von', 'preis_bis', 'kategorie', 'status', 'prioritaet', 'bild_url');

    if ($id > 0) {
        $result = $wpdb->update($table, $data, ['id' => $id]);
        $msg = 'Wunsch aktualisiert.';
    } else {
        $data['erstellt_von'] = get_current_user_id();
        $result = $wpdb->insert($table, $data);
        $id  = $wpdb->insert_id;
        $msg = 'Neuer Wunsch hinzugefügt.';
    }

    if ($result === false) {
        wp_send_json_error(['msg' => 'Datenbankfehler: ' . $wpdb->last_error]);
    }

    // Links verarbeiten (kommen als JSON-String aus dem Formular)
    if (isset($_POST['links'])) {
        $links_raw = json_decode(stripslashes($_POST['links']), true);
        if (is_array($links_raw)) {
            wl_save_links($id, $links_raw);
        }
    }

    $wunsch = wl_get_wunsch($id);
    $wunsch->links = wl_get_links($id);
    wp_send_json_success(['msg' => $msg, 'id' => $id, 'wunsch' => $wunsch]);
}

// ─── WUNSCH LÖSCHEN (Mitglieder) ─────────────────────────────────────────

add_action('wp_ajax_wl_delete_wunsch', 'wl_ajax_delete_wunsch');
function wl_ajax_delete_wunsch() {
    if (!check_ajax_referer('wl_nonce', 'wl_nonce', false)) {
        wp_send_json_error(['msg' => 'Sicherheitscheck fehlgeschlagen.']);
    }
    if (!wl_can_manage()) {
        wp_send_json_error(['msg' => 'Keine Berechtigung.']);
    }

    global $wpdb;
    $table = $wpdb->prefix . 'wunschliste';
    $id    = intval($_POST['id'] ?? 0);

    if (!$id) {
        wp_send_json_error(['msg' => 'Ungültige ID.']);
    }

    $wpdb->delete($table, ['id' => $id]);
    wp_send_json_success(['msg' => 'Wunsch gelöscht.', 'id' => $id]);
}

// ─── SPENDEN-NACHRICHT (Öffentlich) ──────────────────────────────────────

add_action('wp_ajax_wl_sende_spende',        'wl_ajax_sende_spende');
add_action('wp_ajax_nopriv_wl_sende_spende', 'wl_ajax_sende_spende');

function wl_ajax_sende_spende() {
    if (!check_ajax_referer('wl_nonce', 'nonce', false)) {
        wp_send_json_error(['msg' => 'Sicherheitscheck fehlgeschlagen.']);
    }

    $wunsch_id = intval($_POST['wunsch_id'] ?? 0);
    $name      = sanitize_text_field($_POST['spender_name'] ?? '');
    $email     = sanitize_email($_POST['spender_email'] ?? '');
    $betrag    = floatval($_POST['spende_betrag'] ?? 0);
    $nachricht = sanitize_textarea_field($_POST['spende_nachricht'] ?? '');

    if (empty($name) || !is_email($email)) {
        wp_send_json_error(['msg' => 'Bitte Name und gültige E-Mail angeben.']);
    }

    $wunsch = wl_get_wunsch($wunsch_id);
    $wunsch_titel = $wunsch ? $wunsch->titel : 'Unbekannt';

    $admin_email = get_option('wl_kontakt_email', get_option('admin_email'));
    $betreff     = '[Wunschliste] Spendeninteresse: ' . $wunsch_titel;

    $body  = "Neue Spenden-Anfrage über die Vereins-Wunschliste:\n\n";
    $body .= "Wunsch:    $wunsch_titel\n";
    $body .= "Name:      $name\n";
    $body .= "E-Mail:    $email\n";
    $body .= "Betrag:    " . ($betrag > 0 ? number_format($betrag, 2, ',', '.') . ' €' : 'Nicht angegeben') . "\n";
    $body .= "Nachricht: " . ($nachricht ?: '–') . "\n";
    $body .= "\nBitte antworte direkt an die E-Mail des Spenders.";

    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
        'Reply-To: ' . $name . ' <' . $email . '>',
    ];

    $sent = wp_mail($admin_email, $betreff, $body, $headers);

    // Bestätigungs-Mail an Spender
    $confirm_body  = "Hallo $name,\n\n";
    $confirm_body .= "vielen Dank für dein Interesse, den Wunsch \"$wunsch_titel\" zu unterstützen!\n\n";
    $confirm_body .= "Wir melden uns in Kürze bei dir.\n\n";
    $confirm_body .= "Herzliche Grüße,\n" . get_bloginfo('name');
    wp_mail($email, 'Danke für deine Spendenbereitschaft!', $confirm_body);

    if ($sent) {
        wp_send_json_success(['msg' => 'Nachricht gesendet! Wir melden uns bald bei dir. Danke! 🙏']);
    } else {
        wp_send_json_error(['msg' => 'E-Mail konnte nicht gesendet werden. Bitte direkt per E-Mail melden.']);
    }
}
