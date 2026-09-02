<?php
defined('ABSPATH') || exit;

// ─── GAST LOGIN ──────────────────────────────────────────────────────────────

add_action('wp_ajax_nopriv_wl_gast_login', 'wl_ajax_gast_login');
add_action('wp_ajax_wl_gast_login',        'wl_ajax_gast_login');

function wl_ajax_gast_login() {
    if (!check_ajax_referer('wl_nonce', 'nonce', false)) {
        wp_send_json_error(['msg' => 'Sicherheitscheck fehlgeschlagen.']);
    }

    $code      = sanitize_text_field($_POST['gast_code'] ?? '');
    $gast_name = sanitize_text_field($_POST['gast_name'] ?? '');

    if (empty($gast_name)) {
        wp_send_json_error(['msg' => 'Bitte Namen eingeben.']);
    }
    if (!wl_validate_gastcode($code)) {
        wp_send_json_error(['msg' => 'Ungültiger oder abgelaufener Gast-Code.']);
    }

    if (!session_id()) session_start();
    $_SESSION['wl_gast_ok']   = true;
    $_SESSION['wl_gast_name'] = $gast_name;

    wp_send_json_success(['msg' => 'Willkommen, ' . $gast_name . '!']);
}

// ─── ABSTIMMEN ────────────────────────────────────────────────────────────────

add_action('wp_ajax_wl_abstimmen',        'wl_ajax_abstimmen');
add_action('wp_ajax_nopriv_wl_abstimmen', 'wl_ajax_abstimmen');

function wl_ajax_abstimmen() {
    if (!check_ajax_referer('wl_nonce', 'nonce', false)) {
        wp_send_json_error(['msg' => 'Sicherheitscheck fehlgeschlagen.']);
    }

    // Zugang prüfen
    $ist_mitglied = is_user_logged_in() && wl_can_manage();
    $ist_gast     = false;

    if (!session_id()) session_start();
    if (!$ist_mitglied && isset($_SESSION['wl_gast_ok']) && $_SESSION['wl_gast_ok']) {
        $ist_gast = true;
    }

    if (!$ist_mitglied && !$ist_gast) {
        wp_send_json_error(['msg' => 'Kein Zugang. Bitte einloggen oder Gast-Code verwenden.']);
    }

    $wunsch_id   = intval($_POST['wunsch_id'] ?? 0);
    $stufe       = intval($_POST['stufe'] ?? 0);
    $begruendung = sanitize_textarea_field($_POST['begruendung'] ?? '');

    if (!$wunsch_id || $stufe < 1 || $stufe > 5) {
        wp_send_json_error(['msg' => 'Ungültige Eingabe.']);
    }

    // Veto nur für Mitglieder + Begründung Pflicht
    if ($stufe === 5) {
        if (!$ist_mitglied) {
            wp_send_json_error(['msg' => 'Nur Mitglieder können ein Veto einlegen.']);
        }
        if (empty(trim($begruendung))) {
            wp_send_json_error(['msg' => 'Bitte Begründung für Veto angeben.']);
        }
    }

    global $wpdb;
    $votes_table = $wpdb->prefix . 'wl_votes';
    $voter_key   = wl_get_voter_key();
    $voter_type  = $ist_mitglied ? 'mitglied' : 'gast';
    $voter_name  = $ist_mitglied
        ? wp_get_current_user()->display_name
        : sanitize_text_field($_SESSION['wl_gast_name'] ?? 'Gast');

    // Upsert (INSERT ... ON DUPLICATE KEY UPDATE)
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $votes_table WHERE wunsch_id = %d AND voter_key = %s",
        $wunsch_id, $voter_key
    ));

    if ($existing) {
        $wpdb->update($votes_table,
            ['stufe' => $stufe, 'begruendung' => $begruendung, 'voter_name' => $voter_name],
            ['wunsch_id' => $wunsch_id, 'voter_key' => $voter_key]
        );
    } else {
        $wpdb->insert($votes_table, [
            'wunsch_id'  => $wunsch_id,
            'voter_key'  => $voter_key,
            'voter_name' => $voter_name,
            'voter_type' => $voter_type,
            'stufe'      => $stufe,
            'begruendung'=> $begruendung,
        ]);
    }

    // Wenn Veto: wunsch vote_status aktualisieren
    $wl_table = $wpdb->prefix . 'wunschliste';
    $hat_veto = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $votes_table WHERE wunsch_id = %d AND stufe = 5 AND voter_type = 'mitglied'",
        $wunsch_id
    ));
    $new_vote_status = $hat_veto > 0 ? 'veto' : 'aktiv';
    $wpdb->update($wl_table, ['vote_status' => $new_vote_status], ['id' => $wunsch_id]);

    // Aktualisierte Stats zurückgeben
    $stats  = wl_get_vote_stats($wunsch_id);
    $stufen = wl_vote_stufen();
    $html   = wl_render_stats_html($stats, $stufen, $wunsch_id);

    wp_send_json_success([
        'msg'        => 'Stimme gespeichert!',
        'stats_html' => $html,
        'hat_veto'   => $stats['veto'],
        'score'      => $stats['score'],
        'wunsch_id'  => $wunsch_id,
    ]);
}

// ─── STIMME ZURÜCKZIEHEN ─────────────────────────────────────────────────────

add_action('wp_ajax_wl_vote_zurueck',        'wl_ajax_vote_zurueck');
add_action('wp_ajax_nopriv_wl_vote_zurueck', 'wl_ajax_vote_zurueck');

function wl_ajax_vote_zurueck() {
    if (!check_ajax_referer('wl_nonce', 'nonce', false)) {
        wp_send_json_error(['msg' => 'Sicherheitscheck fehlgeschlagen.']);
    }

    global $wpdb;
    $votes_table = $wpdb->prefix . 'wl_votes';
    $wl_table    = $wpdb->prefix . 'wunschliste';
    $wunsch_id   = intval($_POST['wunsch_id'] ?? 0);
    $voter_key   = wl_get_voter_key();

    $wpdb->delete($votes_table, ['wunsch_id' => $wunsch_id, 'voter_key' => $voter_key]);

    // Veto-Status neu prüfen
    $hat_veto = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $votes_table WHERE wunsch_id = %d AND stufe = 5 AND voter_type = 'mitglied'",
        $wunsch_id
    ));
    $wpdb->update($wl_table, ['vote_status' => $hat_veto > 0 ? 'veto' : 'aktiv'], ['id' => $wunsch_id]);

    $stats  = wl_get_vote_stats($wunsch_id);
    $stufen = wl_vote_stufen();
    $html   = wl_render_stats_html($stats, $stufen, $wunsch_id);

    wp_send_json_success(['stats_html' => $html, 'hat_veto' => $stats['veto'], 'wunsch_id' => $wunsch_id]);
}
