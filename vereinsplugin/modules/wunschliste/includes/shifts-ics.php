<?php
defined('ABSPATH') || exit;

/**
 * Erzeugt eine .ics-Kalenderdatei für eine Schicht-Eintragung.
 * Kompatibel mit Google Kalender, Apple Kalender, Outlook etc.
 */
function wl_build_ics($eintragung, $schicht, $station, $event) {
    $uid = 'wl-shift-' . $eintragung->id . '-' . $schicht->id . '@' . parse_url(home_url(), PHP_URL_HOST);

    $start = $schicht->start_zeit ? wl_ics_datetime($schicht->start_zeit) : null;
    $end   = $schicht->end_zeit ? wl_ics_datetime($schicht->end_zeit) : null;
    if (!$start) {
        // Ohne Zeit kein sinnvoller Kalendereintrag möglich
        return null;
    }
    if (!$end) {
        // Standarddauer 1h, falls kein Ende angegeben
        $end = wl_ics_datetime(date('Y-m-d H:i:s', strtotime($schicht->start_zeit) + 3600));
    }

    $summary = $station->titel . ($schicht->titel ? ' – ' . $schicht->titel : '') . ' (' . $event->titel . ')';

    $beschreibung_zeilen = [];
    if ($station->beschreibung) $beschreibung_zeilen[] = 'Aufgabe: ' . $station->beschreibung;
    if ($station->ansprechperson1) $beschreibung_zeilen[] = 'Ansprechperson: ' . $station->ansprechperson1 . ($station->ansprechperson1_kontakt ? ' (' . $station->ansprechperson1_kontakt . ')' : '');
    if ($station->ansprechperson2) $beschreibung_zeilen[] = 'Ansprechperson: ' . $station->ansprechperson2 . ($station->ansprechperson2_kontakt ? ' (' . $station->ansprechperson2_kontakt . ')' : '');
    $beschreibung_zeilen[] = 'Eingetragen über: ' . $event->titel;
    $beschreibung = implode('\\n', array_map('wl_ics_escape', $beschreibung_zeilen));

    $location = wl_ics_escape($station->treffpunkt ?: '');

    $now = gmdate('Ymd\THis\Z'); // DTSTAMP ist immer "jetzt in UTC", unabhängig von der Schicht-Zeitzone

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//' . wl_ics_escape(get_bloginfo('name')) . '//Schichtplan//DE',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:' . $uid,
        'DTSTAMP:' . $now,
        'DTSTART:' . $start,
        'DTEND:' . $end,
        'SUMMARY:' . wl_ics_escape($summary),
        'DESCRIPTION:' . $beschreibung,
        'LOCATION:' . $location,
        // Eingebaute Erinnerung 1 Tag vorher, falls der Kalender des Nutzers das unterstützt
        'BEGIN:VALARM',
        'TRIGGER:-P1D',
        'ACTION:DISPLAY',
        'DESCRIPTION:Erinnerung: ' . wl_ics_escape($summary),
        'END:VALARM',
        'END:VEVENT',
        'END:VCALENDAR',
    ];

    return implode("\r\n", $lines);
}

function wl_ics_datetime($mysql_datetime) {
    // $mysql_datetime ist in der lokalen WordPress-Zeitzone gespeichert (wie in der
    // Datenbank eingegeben). Für eine korrekte .ics-Datei muss daraus echtes UTC werden,
    // sonst verschiebt sich der Termin im importierenden Kalender je nach Zeitzone.
    $wp_tz = wp_timezone();
    $dt = new DateTime($mysql_datetime, $wp_tz);
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Ymd\THis\Z');
}

function wl_ics_escape($text) {
    $text = str_replace(["\\", ";", ","], ["\\\\", "\\;", "\\,"], $text);
    $text = str_replace(["\r\n", "\n"], '\\n', $text);
    return $text;
}

/**
 * Baut eine .ics-Datei mit MEHREREN Terminen (einem VEVENT pro Schicht) für
 * eine Person — alle ihre Schichten bei einer Veranstaltung in einem Rutsch.
 */
function wl_build_sammel_ics($event, $eintragungen) {
    $now = gmdate('Ymd\THis\Z');
    $host = parse_url(home_url(), PHP_URL_HOST);

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//' . wl_ics_escape(get_bloginfo('name')) . '//Schichtplan//DE',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
    ];

    $hat_mind_einen_termin = false;

    foreach ($eintragungen as $e) {
        $schicht = $e['schicht'];
        $station = $e['station'];
        if (!$station || empty($schicht->start_zeit)) continue; // ohne Zeit kein Kalendereintrag möglich

        $hat_mind_einen_termin = true;

        $start = wl_ics_datetime($schicht->start_zeit);
        $end   = !empty($schicht->end_zeit)
            ? wl_ics_datetime($schicht->end_zeit)
            : wl_ics_datetime(date('Y-m-d H:i:s', strtotime($schicht->start_zeit) + 3600));

        $summary = $station->titel . ($schicht->titel ? ' – ' . $schicht->titel : '') . ' (' . $event->titel . ')';

        $beschreibung_zeilen = [];
        if ($station->beschreibung) $beschreibung_zeilen[] = 'Aufgabe: ' . $station->beschreibung;
        if ($station->ansprechperson1) $beschreibung_zeilen[] = 'Ansprechperson: ' . $station->ansprechperson1 . ($station->ansprechperson1_kontakt ? ' (' . $station->ansprechperson1_kontakt . ')' : '');
        if ($station->ansprechperson2) $beschreibung_zeilen[] = 'Ansprechperson: ' . $station->ansprechperson2 . ($station->ansprechperson2_kontakt ? ' (' . $station->ansprechperson2_kontakt . ')' : '');
        $beschreibung = implode('\\n', array_map('wl_ics_escape', $beschreibung_zeilen));

        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'UID:wl-shift-' . $e['eintragung_id'] . '-' . $schicht->id . '@' . $host;
        $lines[] = 'DTSTAMP:' . $now;
        $lines[] = 'DTSTART:' . $start;
        $lines[] = 'DTEND:' . $end;
        $lines[] = 'SUMMARY:' . wl_ics_escape($summary);
        if ($beschreibung) $lines[] = 'DESCRIPTION:' . $beschreibung;
        if ($station->treffpunkt) $lines[] = 'LOCATION:' . wl_ics_escape($station->treffpunkt);
        $lines[] = 'BEGIN:VALARM';
        $lines[] = 'TRIGGER:-P1D';
        $lines[] = 'ACTION:DISPLAY';
        $lines[] = 'DESCRIPTION:Erinnerung: ' . wl_ics_escape($summary);
        $lines[] = 'END:VALARM';
        $lines[] = 'END:VEVENT';
    }

    if (!$hat_mind_einen_termin) return null;

    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", $lines);
}

/**
 * Generiert einen signierten Download-Link für die Sammel-Kalenderdatei einer
 * Person bei einer Veranstaltung. Signatur verhindert, dass jemand fremde
 * E-Mail-Adressen erraten und so an fremde Kalenderdaten kommen kann.
 */
function wl_get_sammel_ics_download_url($event_id, $email) {
    $sig = wl_sammel_ics_signature($event_id, $email);
    return add_query_arg([
        'wl_ics_sammel' => $event_id,
        'email' => rawurlencode($email),
        'sig' => $sig,
    ], home_url('/'));
}

function wl_sammel_ics_signature($event_id, $email) {
    return substr(hash_hmac('sha256', $event_id . '|' . strtolower(trim($email)), wp_salt('auth')), 0, 32);
}

// ─── DOWNLOAD-ENDPUNKT ─────────────────────────────────────────────────────
// Aufruf über ?wl_ics=<manage_key>

add_action('init', 'wl_handle_ics_download');
function wl_handle_ics_download() {
    if (empty($_GET['wl_ics'])) return;

    $manage_key = sanitize_text_field($_GET['wl_ics']);
    $eintragung = wl_get_eintragung_by_key($manage_key);
    if (!$eintragung) {
        wp_die('Eintragung nicht gefunden oder bereits ausgetragen.', 'Nicht gefunden', ['response' => 404]);
    }

    $schicht = wl_get_schicht($eintragung->schicht_id);
    $station = $schicht ? wl_get_station($schicht->station_id) : null;
    $event   = $station ? wl_get_event($station->event_id) : null;

    if (!$schicht || !$station || !$event) {
        wp_die('Schicht-Daten unvollständig.', 'Fehler', ['response' => 404]);
    }

    $ics = wl_build_ics($eintragung, $schicht, $station, $event);
    if (!$ics) {
        wp_die('Für diese Schicht ist keine Uhrzeit hinterlegt, ein Kalendereintrag ist daher nicht möglich.', 'Kein Termin', ['response' => 400]);
    }

    $filename = sanitize_title($station->titel . '-' . ($schicht->titel ?: 'schicht')) . '.ics';

    nocache_headers();
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $ics;
    exit;
}

function wl_get_ics_download_url($manage_key) {
    return add_query_arg('wl_ics', $manage_key, home_url('/'));
}

// ─── DOWNLOAD-ENDPUNKT: SAMMEL-ICS (alle Schichten einer Person) ──────────
// Aufruf über ?wl_ics_sammel=<event_id>&email=<email>&sig=<signatur>

add_action('init', 'wl_handle_sammel_ics_download');
function wl_handle_sammel_ics_download() {
    if (empty($_GET['wl_ics_sammel'])) return;

    $event_id = intval($_GET['wl_ics_sammel']);
    $email    = isset($_GET['email']) ? sanitize_email(rawurldecode($_GET['email'])) : '';
    $sig      = isset($_GET['sig']) ? sanitize_text_field($_GET['sig']) : '';

    if (!$event_id || !$email || !$sig) {
        wp_die('Ungültiger Link.', 'Fehler', ['response' => 400]);
    }

    $erwartete_sig = wl_sammel_ics_signature($event_id, $email);
    if (!hash_equals($erwartete_sig, $sig)) {
        wp_die('Ungültiger oder abgelaufener Link.', 'Nicht autorisiert', ['response' => 403]);
    }

    $event = wl_get_event($event_id);
    if (!$event) {
        wp_die('Veranstaltung nicht gefunden.', 'Fehler', ['response' => 404]);
    }

    $gruppen = wl_get_eintragungen_gruppiert_nach_person($event_id);
    $key = strtolower(trim($email));
    if (!isset($gruppen[$key])) {
        wp_die('Keine Eintragungen für diese E-Mail-Adresse gefunden.', 'Nicht gefunden', ['response' => 404]);
    }

    $ics = wl_build_sammel_ics($event, $gruppen[$key]['eintragungen']);
    if (!$ics) {
        wp_die('Für deine Schichten ist keine Uhrzeit hinterlegt, ein Kalendereintrag ist daher nicht möglich.', 'Kein Termin', ['response' => 400]);
    }

    $filename = sanitize_title($event->titel . '-alle-schichten') . '.ics';

    nocache_headers();
    header('Content-Type: text/calendar; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $ics;
    exit;
}
