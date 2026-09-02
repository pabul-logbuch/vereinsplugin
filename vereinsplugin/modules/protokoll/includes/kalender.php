<?php
defined('ABSPATH') || exit;

/**
 * Kalender-Sync ohne Google-/Outlook-API: Jede Person bekommt einen
 * persönlichen, geheimen Feed-Link (webcal/https), den sie in ihrer
 * Kalender-App als "Kalender abonnieren" hinterlegt. Die App holt sich den
 * Inhalt danach selbstständig regelmäßig ab — echtes "Sync" ohne dass wir
 * selbst in fremde Kalender schreiben müssen.
 */

function pp_get_or_create_ics_token($user_id) {
    $token = get_user_meta($user_id, 'pp_ics_token', true);
    if (!$token) {
        $token = wp_generate_password(32, false, false);
        update_user_meta($user_id, 'pp_ics_token', $token);
    }
    return $token;
}

function pp_get_ics_feed_url($user_id) {
    $token = pp_get_or_create_ics_token($user_id);
    return add_query_arg([
        'pp_ics' => '1',
        'u'      => $user_id,
        'token'  => $token,
    ], home_url('/'));
}

add_action('template_redirect', 'pp_maybe_output_ics_feed');
function pp_maybe_output_ics_feed() {
    if (empty($_GET['pp_ics']) || empty($_GET['u']) || empty($_GET['token'])) {
        return;
    }

    $user_id = intval($_GET['u']);
    $token = sanitize_text_field($_GET['token']);
    $gespeicherter_token = get_user_meta($user_id, 'pp_ics_token', true);

    if (!$gespeicherter_token || !hash_equals($gespeicherter_token, $token)) {
        status_header(403);
        exit('Ungültiger Kalender-Link.');
    }

    global $wpdb;

    $aufgaben = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pp_aufgaben WHERE verantwortlich_user_id = %d AND status = 'offen' AND faelligkeitsdatum IS NOT NULL",
        $user_id
    ));

    // Termine der Gremien, in denen die Person eine aktive Rolle hat
    $gremium_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT gremium_id FROM {$wpdb->prefix}pp_rollen
         WHERE user_id = %d AND (amtszeit_ende IS NULL OR amtszeit_ende >= CURDATE())",
        $user_id
    ));
    $termine = [];
    if ($gremium_ids) {
        $platzhalter = implode(',', array_fill(0, count($gremium_ids), '%d'));
        $termine = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}pp_termine WHERE gremium_id IN ($platzhalter) AND datum IS NOT NULL",
            ...$gremium_ids
        ));
    }

    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: inline; filename="protokollpro.ics"');

    echo "BEGIN:VCALENDAR\r\n";
    echo "VERSION:2.0\r\n";
    echo "PRODID:-//ProtokollPro//" . pp_ics_escape(get_bloginfo('name')) . "//DE\r\n";
    echo "CALSCALE:GREGORIAN\r\n";
    echo "X-WR-CALNAME:" . pp_ics_escape(get_bloginfo('name') . ' – ProtokollPro') . "\r\n";
    echo "REFRESH-INTERVAL;VALUE=DURATION:PT6H\r\n";

    foreach ($aufgaben as $a) {
        $datum = str_replace('-', '', $a->faelligkeitsdatum);
        echo "BEGIN:VTODO\r\n";
        echo "UID:pp-aufgabe-{$a->id}@" . pp_ics_domain() . "\r\n";
        echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
        echo "DUE;VALUE=DATE:{$datum}\r\n";
        echo "SUMMARY:" . pp_ics_escape($a->titel) . "\r\n";
        if ($a->beschreibung) echo "DESCRIPTION:" . pp_ics_escape($a->beschreibung) . "\r\n";
        echo "STATUS:NEEDS-ACTION\r\n";
        echo "END:VTODO\r\n";
    }

    foreach ($termine as $t) {
        $start = gmdate('Ymd\THis', strtotime($t->datum));
        $ende  = gmdate('Ymd\THis', strtotime($t->datum . ' +1 hour'));
        echo "BEGIN:VEVENT\r\n";
        echo "UID:pp-termin-{$t->id}@" . pp_ics_domain() . "\r\n";
        echo "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
        echo "DTSTART:{$start}\r\n";
        echo "DTEND:{$ende}\r\n";
        echo "SUMMARY:" . pp_ics_escape($t->titel) . "\r\n";
        if ($t->ort) echo "LOCATION:" . pp_ics_escape($t->ort) . "\r\n";
        echo "END:VEVENT\r\n";
    }

    echo "END:VCALENDAR\r\n";
    exit;
}

function pp_ics_escape($text) {
    $text = str_replace(["\\", "\n", ",", ";"], ["\\\\", "\\n", "\\,", "\\;"], (string) $text);
    return $text;
}

function pp_ics_domain() {
    return wp_parse_url(home_url(), PHP_URL_HOST) ?: 'protokollpro.local';
}
