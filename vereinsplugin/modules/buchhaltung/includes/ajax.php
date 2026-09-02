<?php
defined('ABSPATH') || exit;

// Auslage einreichen
add_action('wp_ajax_jb_submit_auslage', function() {
    check_ajax_referer('jb_nonce', 'nonce');
    if (!jb_can_submit()) wp_send_json_error('Keine Berechtigung.');

    $result = jb_submit_auslage($_POST, $_FILES['beleg'] ?? []);
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    wp_send_json_success(['id' => $result, 'message' => 'Auslage erfolgreich eingereicht!']);
});

// Auslage genehmigen/ablehnen
add_action('wp_ajax_jb_decide_auslage', function() {
    check_ajax_referer('jb_nonce', 'nonce');
    if (!jb_can_approve()) wp_send_json_error('Keine Berechtigung.');

    $id      = (int) ($_POST['id'] ?? 0);
    $approve = ($_POST['action_type'] ?? '') === 'approve';
    $notiz   = sanitize_textarea_field($_POST['notiz'] ?? '');

    if (!$id) wp_send_json_error('Ungültige ID.');
    $ok = jb_approve_auslage($id, $approve, $notiz);
    wp_send_json($ok ? ['success' => true] : ['success' => false, 'data' => 'Fehler.']);
});

// Als ausgezahlt markieren
add_action('wp_ajax_jb_mark_paid', function() {
    check_ajax_referer('jb_nonce', 'nonce');
    $id = (int) ($_POST['id'] ?? 0);
    $result = jb_mark_paid($id);
    if (is_wp_error($result)) {
        wp_send_json_error($result->get_error_message());
    }
    wp_send_json_success(['message' => 'Als ausgezahlt markiert und ins Buchungsjournal übertragen.']);
});

// Buchung manuell hinzufügen
add_action('wp_ajax_jb_add_buchung', function() {
    check_ajax_referer('jb_nonce', 'nonce');
    if (!jb_can_journal()) wp_send_json_error('Keine Berechtigung.');

    $betrag = (float) str_replace(',', '.', $_POST['betrag'] ?? '0');
    if ($_POST['typ'] === 'ausgabe') $betrag = -abs($betrag);

    $id = jb_journal_add([
        'buchung_datum' => sanitize_text_field($_POST['datum'] ?? ''),
        'betrag'        => $betrag,
        'kategorie'     => sanitize_text_field($_POST['kategorie'] ?? ''),
        'beschreibung'  => sanitize_textarea_field($_POST['beschreibung'] ?? ''),
        'quelle'        => 'Manuell',
        'beleg_referenz'=> sanitize_text_field($_POST['beleg_referenz'] ?? ''),
    ]);
    wp_send_json_success(['id' => $id]);
});

// Buchung löschen
add_action('wp_ajax_jb_delete_buchung', function() {
    check_ajax_referer('jb_nonce', 'nonce');
    if (!current_user_can('administrator')) wp_send_json_error('Keine Berechtigung.');
    $id = (int) ($_POST['id'] ?? 0);
    wp_send_json(jb_journal_delete($id) ? ['success' => true] : ['success' => false, 'data' => 'Fehler.']);
});

// Budget speichern
add_action('wp_ajax_jb_save_budget', function() {
    check_ajax_referer('jb_nonce', 'nonce');
    $id = jb_budget_save($_POST);
    $id ? wp_send_json_success(['id' => $id]) : wp_send_json_error('Fehler.');
});

// Budget löschen
add_action('wp_ajax_jb_delete_budget', function() {
    check_ajax_referer('jb_nonce', 'nonce');
    wp_send_json(jb_budget_delete((int)$_POST['id']) ? ['success'=>true] : ['success'=>false,'data'=>'Fehler.']);
});

// Rücklage speichern
add_action('wp_ajax_jb_save_ruecklage', function() {
    check_ajax_referer('jb_nonce', 'nonce');
    $id = jb_ruecklage_save($_POST);
    $id ? wp_send_json_success(['id' => $id]) : wp_send_json_error('Fehler.');
});

// Lieferung buchen
add_action('wp_ajax_jb_lieferung', function() {
    check_ajax_referer('jb_nonce', 'nonce');
    if (!jb_is_kassier()) wp_send_json_error('Keine Berechtigung.');
    $pos     = json_decode(stripslashes($_POST['positionen'] ?? '[]'), true);
    $datum   = sanitize_text_field($_POST['datum'] ?? date('Y-m-d'));
    $ref     = sanitize_text_field($_POST['referenz'] ?? '');
    $gebucht = jb_lieferung_buchen($pos, $datum, $ref);
    wp_send_json_success(['gebucht' => $gebucht]);
});

// Inventur speichern
add_action('wp_ajax_jb_inventur', function() {
    check_ajax_referer('jb_nonce', 'nonce');
    if (!jb_is_kassier()) wp_send_json_error('Keine Berechtigung.');
    $soll  = json_decode(stripslashes($_POST['soll'] ?? '{}'), true);
    $datum = sanitize_text_field($_POST['datum'] ?? date('Y-m-d'));
    $n     = jb_inventur($soll, $datum);
    wp_send_json_success(['gebucht' => $n]);
});

// Manuelle Korrektur
add_action('wp_ajax_jb_korrektur', function() {
    check_ajax_referer('jb_nonce', 'nonce');
    if (!jb_is_kassier()) wp_send_json_error('Keine Berechtigung.');
    $ok = jb_bewegung_add([
        'produkt_id' => (int)$_POST['produkt_id'],
        'datum'      => sanitize_text_field($_POST['datum'] ?? date('Y-m-d')),
        'menge'      => (int)$_POST['menge'],
        'grund'      => 'korrektur',
        'notiz'      => sanitize_text_field($_POST['notiz'] ?? ''),
    ]);
    wp_send_json($ok ? ['success'=>true] : ['success'=>false,'data'=>'Fehler.']);
});

// Zettle CSV Import
add_action('wp_ajax_jb_import_zettle', function() {
    check_ajax_referer('jb_nonce', 'nonce');
    if (!jb_is_kassier()) wp_send_json_error('Keine Berechtigung.');
    $csv   = wp_unslash($_POST['csv'] ?? '');
    $datum = sanitize_text_field($_POST['datum'] ?? date('Y-m-d'));
    $ref   = sanitize_text_field($_POST['referenz'] ?? '');
    $r     = jb_import_zettle_csv($csv, $datum, $ref);
    wp_send_json_success($r);
});

// Produkt speichern
add_action('wp_ajax_jb_save_produkt', function() {
    check_ajax_referer('jb_nonce', 'nonce');
    $id = jb_produkt_save($_POST);
    $id ? wp_send_json_success(['id' => $id]) : wp_send_json_error('Fehler.');
});
    check_ajax_referer('jb_nonce', 'nonce');
    if (!current_user_can('jb_manage_settings')) wp_send_json_error('Keine Berechtigung.');
    wp_send_json(jb_nc()->test_connection());
});

// Beleg herunterladen
add_action('wp_ajax_jb_download_beleg', function() {
    check_ajax_referer('jb_download', 'nonce');
    if (!jb_can_approve() && !jb_can_submit()) wp_die('Keine Berechtigung.');
    $path = sanitize_text_field($_GET['path'] ?? '');
    if (empty($path)) wp_die('Kein Pfad angegeben.');
    $filename = basename($path);
    jb_nc()->stream_to_browser($path, $filename);
});

// Export-Handler
add_action('admin_init', function() {
    if (!isset($_GET['jb_export'])) return;
    $type = sanitize_text_field($_GET['jb_export']);
    $year = (int) ($_GET['year'] ?? date('Y'));
    check_admin_referer('jb_export_' . $year);

    match($type) {
        'euer'    => jb_export_euer_csv($year),
        'datev'   => jb_export_datev($year),
        'auslagen'=> jb_export_auslagen_csv($year),
        default   => null,
    };
});
