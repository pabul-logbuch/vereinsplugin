<?php
defined('ABSPATH') || exit;

/**
 * V1: Die eigentliche Konsent-Workflow-Logik läuft über normale
 * admin-post.php-Formulare (siehe admin-protokolle.php), damit alles auch
 * ohne JavaScript zuverlässig funktioniert. Dieser AJAX-Handler ist ein
 * optionales Komfort-Feature für später (z. B. Live-Aktualisierung ohne
 * Seiten-Reload) und aktuell nur als Grundgerüst vorhanden.
 */
add_action('wp_ajax_pp_top_status', 'pp_ajax_top_status');
function pp_ajax_top_status() {
    if (!check_ajax_referer('pp_nonce', 'nonce', false)) {
        wp_send_json_error(['msg' => 'Sicherheitscheck fehlgeschlagen.']);
    }
    if (!pp_can_manage()) {
        wp_send_json_error(['msg' => 'Keine Berechtigung.']);
    }

    global $wpdb;
    $top_id = intval($_POST['top_id'] ?? 0);
    $top = $wpdb->get_row($wpdb->prepare("SELECT konsent_status FROM {$wpdb->prefix}pp_tops WHERE id=%d", $top_id));

    if (!$top) {
        wp_send_json_error(['msg' => 'TOP nicht gefunden.']);
    }

    wp_send_json_success([
        'status'       => $top->konsent_status,
        'status_label' => pp_konsent_status_label($top->konsent_status),
    ]);
}
