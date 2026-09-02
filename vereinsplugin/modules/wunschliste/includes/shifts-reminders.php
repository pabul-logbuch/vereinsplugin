<?php
defined('ABSPATH') || exit;

// ─── CRON EINRICHTEN ───────────────────────────────────────────────────────

add_action('wl_check_schicht_erinnerungen', 'wl_send_schicht_erinnerungen');

function wl_schedule_erinnerung_cron() {
    if (!wp_next_scheduled('wl_check_schicht_erinnerungen')) {
        wp_schedule_event(time(), 'hourly', 'wl_check_schicht_erinnerungen');
    }
}
add_action('wp', 'wl_schedule_erinnerung_cron');

function wl_unschedule_erinnerung_cron() {
    $timestamp = wp_next_scheduled('wl_check_schicht_erinnerungen');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'wl_check_schicht_erinnerungen');
    }
}
// Wird beim Deaktivieren des Plugins aus wunschliste.php aufgerufen.

// ─── ERINNERUNGEN PRÜFEN UND VERSENDEN ─────────────────────────────────────

/**
 * Läuft stündlich (WP-Cron). Sucht alle Veranstaltungen, deren Datum in den
 * nächsten 23-25 Stunden liegt (Fenster wegen stündlicher Prüfung), und
 * verschickt für jede Person mit mindestens einer Eintragung dort EINE
 * zusammengefasste Erinnerungsmail mit allen ihren Schichten bei dieser
 * Veranstaltung — unabhängig davon, an welchem Tag/zu welcher Uhrzeit diese
 * einzelnen Schichten liegen.
 */
function wl_send_schicht_erinnerungen() {
    global $wpdb;

    // start_zeit/veranstaltungsdatum in der Datenbank ist lokale WordPress-Zeit,
    // daher hier ebenfalls mit lokaler Zeit vergleichen (current_time ohne $gmt-Flag).
    $heute_plus_23h = date('Y-m-d', current_time('timestamp') + 23 * HOUR_IN_SECONDS);
    $heute_plus_25h = date('Y-m-d', current_time('timestamp') + 25 * HOUR_IN_SECONDS);

    $events_table = $wpdb->prefix . 'wl_shift_events';
    $faellige_events = $wpdb->get_results($wpdb->prepare("
        SELECT * FROM $events_table
        WHERE aktiv = 1
          AND veranstaltungsdatum IS NOT NULL
          AND veranstaltungsdatum BETWEEN %s AND %s
    ", $heute_plus_23h, $heute_plus_25h));

    foreach ($faellige_events as $event) {
        $gruppen = wl_get_eintragungen_gruppiert_nach_person($event->id);

        foreach ($gruppen as $person) {
            // Nur Personen, bei denen noch mindestens eine Eintragung unbestätigt ist,
            // bekommen die Mail. Sind alle ihre Eintragungen schon "erinnert", überspringen.
            $offene = array_filter($person['eintragungen'], function ($e) {
                return !$e['erinnerung_gesendet'];
            });
            if (empty($offene)) continue;

            $gesendet = wl_send_erinnerung_mail_gesammelt($person, $event);

            if ($gesendet) {
                $eintragung_ids = wp_list_pluck($person['eintragungen'], 'eintragung_id');
                wl_markiere_erinnerungen_gesendet($eintragung_ids);
            }
        }
    }
}

function wl_markiere_erinnerungen_gesendet($eintragung_ids) {
    if (empty($eintragung_ids)) return;
    global $wpdb;
    $et = $wpdb->prefix . 'wl_shift_eintragungen';
    $ids_sql = implode(',', array_map('intval', $eintragung_ids));
    $wpdb->query("UPDATE $et SET erinnerung_gesendet = 1 WHERE id IN ($ids_sql)");
}

/**
 * Verschickt eine einzige Erinnerungsmail an eine Person mit ALLEN ihren
 * Schichten bei dieser Veranstaltung (chronologisch sortiert), inklusive
 * Sammel-Kalenderdatei (.ics mit mehreren Terminen) und individuellem
 * Austragungslink je Schicht.
 */
function wl_send_erinnerung_mail_gesammelt($person, $event) {
    $eintragungen = $person['eintragungen'];
    usort($eintragungen, function ($a, $b) {
        $ta = $a['schicht']->start_zeit ? strtotime($a['schicht']->start_zeit) : PHP_INT_MAX;
        $tb = $b['schicht']->start_zeit ? strtotime($b['schicht']->start_zeit) : PHP_INT_MAX;
        return $ta <=> $tb;
    });

    $anzahl = count($eintragungen);
    $betreff = $anzahl > 1
        ? '⏰ Erinnerung: Deine ' . $anzahl . ' Schichten bei ' . $event->titel
        : '⏰ Erinnerung: Deine Schicht bei ' . $event->titel;

    $body  = "Hallo " . $person['name'] . ",\n\n";
    $body .= "kurze Erinnerung: Morgen beginnt \"" . $event->titel . "\"";
    if ($event->veranstaltungsdatum) {
        $body .= " (" . date('d.m.Y', strtotime($event->veranstaltungsdatum)) . ")";
    }
    $body .= "!\n\n";

    if ($anzahl > 1) {
        $body .= "Du bist für insgesamt $anzahl Schichten eingetragen:\n\n";
    } else {
        $body .= "Du bist für folgende Schicht eingetragen:\n\n";
    }

    foreach ($eintragungen as $i => $e) {
        $schicht = $e['schicht'];
        $station = $e['station'];
        if (!$station) continue;

        $body .= "—————————————————\n";
        $body .= ($i + 1) . ". " . $station->titel;
        if ($schicht->titel) $body .= " – " . $schicht->titel;
        $body .= "\n";
        if ($schicht->start_zeit) {
            $body .= "   Zeit:    " . date('d.m.Y H:i', strtotime($schicht->start_zeit));
            if ($schicht->end_zeit) $body .= ' – ' . date('H:i', strtotime($schicht->end_zeit)) . ' Uhr';
            $body .= "\n";
        }
        if ($station->treffpunkt) $body .= "   Treffpunkt: " . $station->treffpunkt . "\n";
        if ($station->ansprechperson1) $body .= "   Ansprechperson: " . $station->ansprechperson1 . ($station->ansprechperson1_kontakt ? ' (' . $station->ansprechperson1_kontakt . ')' : '') . "\n";
        if ($station->beschreibung) $body .= "   Aufgabe: " . $station->beschreibung . "\n";
        $body .= "   Falls du diese Schicht doch nicht machen kannst, hier austragen:\n";
        $body .= "   " . wl_get_abmelde_link($e['manage_key'], $event->slug) . "\n";
    }
    $body .= "—————————————————\n\n";

    // Sammel-Kalenderdatei mit allen Terminen dieser Person als Link
    $sammel_ics_link = wl_get_sammel_ics_download_url($event->id, $person['email']);
    $body .= "📅 Alle deine Schichten auf einmal in den Kalender importieren:\n";
    $body .= $sammel_ics_link . "\n\n";

    $body .= "Danke für deine Unterstützung!\n" . get_bloginfo('name');

    return wp_mail($person['email'], $betreff, $body);
}
