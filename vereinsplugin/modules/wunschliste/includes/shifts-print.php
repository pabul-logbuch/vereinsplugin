<?php
defined('ABSPATH') || exit;

/**
 * Druck-/PDF-Export für Schichtpläne.
 *
 * Funktioniert über eine eigene, schlanke HTML-Seite (kein WordPress-Theme,
 * kein Header/Footer/Sidebar), die der Browser-Druckdialog dann direkt als
 * PDF speichern oder ausdrucken kann ("Als PDF speichern" im Druckdialog).
 * Das ist deutlich robuster als serverseitige PDF-Bibliotheken und erfordert
 * keine zusätzlichen Abhängigkeiten.
 *
 * Aufruf:
 *   ?wl_print=gesamt&event=<id>           -> Gesamtübersicht aller Stationen
 *   ?wl_print=station&event=<id>&station=<id>  -> Einzelblatt für eine Station
 */

add_action('template_redirect', 'wl_handle_print_export');

function wl_handle_print_export() {
    if (empty($_GET['wl_print'])) return;

    if (!is_user_logged_in() || !wl_can_manage()) {
        wp_die('Keine Berechtigung für den Druck-Export.', 'Keine Berechtigung', ['response' => 403]);
    }

    $event_id = intval($_GET['event'] ?? 0);
    $event = wl_get_event_full($event_id);
    if (!$event) {
        wp_die('Veranstaltung nicht gefunden.', 'Fehler', ['response' => 404]);
    }

    $modus = sanitize_text_field($_GET['wl_print']);

    if ($modus === 'station') {
        $station_id = intval($_GET['station'] ?? 0);
        $station = null;
        foreach ($event->stationen as $s) {
            if ($s->id === $station_id) { $station = $s; break; }
        }
        if (!$station) {
            wp_die('Station nicht gefunden.', 'Fehler', ['response' => 404]);
        }
        wl_render_print_station($event, $station);
        exit;
    }

    // Standard: Gesamtübersicht
    wl_render_print_gesamt($event);
    exit;
}

// ─── DRUCK-LAYOUT: BASIS (gemeinsamer Kopf/Fuß für beide Varianten) ──────

function wl_print_html_start($titel) {
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title><?php echo esc_html($titel); ?></title>
<style>
    @page { size: A4; margin: 18mm 15mm; }
    * { box-sizing: border-box; }
    body {
        font-family: -apple-system, 'Segoe UI', Arial, sans-serif;
        color: #1e293b; margin: 0; padding: 0; font-size: 11pt;
    }
    .wlp-toolbar {
        position: sticky; top: 0; background: #1e293b; color: #fff;
        padding: 12px 20px; display: flex; justify-content: space-between;
        align-items: center; z-index: 100;
    }
    .wlp-toolbar button {
        background: #2563eb; color: #fff; border: none; border-radius: 6px;
        padding: 8px 18px; font-size: .9rem; cursor: pointer; font-weight: 600;
    }
    .wlp-toolbar a { color: #cbd5e1; text-decoration: none; font-size: .85rem; }
    .wlp-content { padding: 20px 30px; max-width: 1000px; margin: 0 auto; }

    h1 { font-size: 20pt; margin: 0 0 4px; }
    h2 { font-size: 14pt; margin: 24px 0 8px; border-bottom: 2px solid #1e293b; padding-bottom: 4px; }
    .wlp-meta { color: #64748b; font-size: 10pt; margin-bottom: 20px; }

    table { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
    th, td { border: 1px solid #cbd5e1; padding: 6px 10px; text-align: left; font-size: 10pt; vertical-align: top; }
    th { background: #f1f5f9; font-weight: 700; }

    .wlp-station-block { margin-bottom: 28px; page-break-inside: avoid; }
    .wlp-station-titel { font-size: 13pt; font-weight: 700; margin: 0 0 4px; }
    .wlp-station-meta { color: #475569; font-size: 9.5pt; margin-bottom: 8px; }
    .wlp-station-meta span { margin-right: 16px; }

    .wlp-namen-leer { color: #94a3b8; font-style: italic; }
    .wlp-unterbesetzt { color: #b45309; font-weight: 700; }

    .wlp-signaturzeile { border-bottom: 1px solid #94a3b8; display: inline-block; min-width: 140px; height: 14px; }

    @media print {
        .wlp-toolbar { display: none; }
        .wlp-content { padding: 0; max-width: none; }
        a { color: inherit; text-decoration: none; }
    }
</style>
</head>
<body>
    <div class="wlp-toolbar">
        <span>🖨️ Druckansicht — Strg/Cmd+P zum Drucken oder als PDF speichern</span>
        <div>
            <a href="javascript:history.back()" style="margin-right:16px;">← Zurück</a>
            <button onclick="window.print()">Drucken / Als PDF speichern</button>
        </div>
    </div>
    <div class="wlp-content">
    <?php
}

function wl_print_html_end() {
    ?>
    </div>
</body>
</html>
    <?php
}

// ─── GESAMTÜBERSICHT ──────────────────────────────────────────────────────

function wl_render_print_gesamt($event) {
    wl_print_html_start('Schichtplan – ' . $event->titel);
    ?>
    <h1><?php echo esc_html($event->titel); ?></h1>
    <p class="wlp-meta">
        Schichtplan – Gesamtübersicht
        <?php if ($event->veranstaltungsdatum) echo ' · ' . date('d.m.Y', strtotime($event->veranstaltungsdatum)); ?>
        · Stand: <?php echo date('d.m.Y H:i'); ?> Uhr
    </p>

    <?php foreach ($event->stationen as $station) : ?>
        <div class="wlp-station-block">
            <h2 class="wlp-station-titel"><?php echo esc_html($station->titel); ?></h2>
            <?php if ($station->beschreibung || $station->treffpunkt || $station->ansprechperson1) : ?>
                <div class="wlp-station-meta">
                    <?php if ($station->beschreibung) echo '<span>📋 ' . esc_html($station->beschreibung) . '</span>'; ?>
                    <?php if ($station->treffpunkt) echo '<span>📍 ' . esc_html($station->treffpunkt) . '</span>'; ?>
                    <?php if ($station->ansprechperson1) echo '<span>👤 ' . esc_html($station->ansprechperson1) . ($station->ansprechperson1_kontakt ? ' (' . esc_html($station->ansprechperson1_kontakt) . ')' : '') . '</span>'; ?>
                    <?php if ($station->ansprechperson2) echo '<span>👤 ' . esc_html($station->ansprechperson2) . ($station->ansprechperson2_kontakt ? ' (' . esc_html($station->ansprechperson2_kontakt) . ')' : '') . '</span>'; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($station->schichten)) : ?>
                <table>
                    <thead>
                        <tr><th style="width:18%;">Zeit</th><th style="width:12%;">Plätze</th><th>Eingetragen</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($station->schichten as $schicht) :
                            $unterbesetzt = $schicht->min_plaetze > 0 && $schicht->belegt < $schicht->min_plaetze;
                        ?>
                            <tr>
                                <td>
                                    <?php if ($schicht->titel) echo '<strong>' . esc_html($schicht->titel) . '</strong><br>'; ?>
                                    <?php
                                    if ($schicht->start_zeit) {
                                        echo date('d.m. H:i', strtotime($schicht->start_zeit));
                                        if ($schicht->end_zeit) echo ' – ' . date('H:i', strtotime($schicht->end_zeit)) . ' Uhr';
                                    } else {
                                        echo '<span class="wlp-namen-leer">ohne festen Termin</span>';
                                    }
                                    ?>
                                </td>
                                <td class="<?php echo $unterbesetzt ? 'wlp-unterbesetzt' : ''; ?>">
                                    <?php echo $schicht->belegt; ?>/<?php echo $schicht->max_plaetze; ?>
                                    <?php if ($unterbesetzt) echo ' ⚠️ min. ' . (int) $schicht->min_plaetze; ?>
                                </td>
                                <td>
                                    <?php if (!empty($schicht->eintragungen)) : ?>
                                        <?php echo esc_html(implode(', ', wp_list_pluck($schicht->eintragungen, 'name'))); ?>
                                    <?php else : ?>
                                        <span class="wlp-namen-leer">— noch niemand eingetragen —</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="wlp-namen-leer">Keine Schichten angelegt.</p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if (empty($event->stationen)) : ?>
        <p class="wlp-namen-leer">Für diese Veranstaltung wurden noch keine Stationen angelegt.</p>
    <?php endif; ?>
    <?php
    wl_print_html_end();
}

// ─── EINZELBLATT PRO STATION ──────────────────────────────────────────────

function wl_render_print_station($event, $station) {
    wl_print_html_start($station->titel . ' – ' . $event->titel);
    ?>
    <h1><?php echo esc_html($station->titel); ?></h1>
    <p class="wlp-meta">
        <?php echo esc_html($event->titel); ?>
        <?php if ($event->veranstaltungsdatum) echo ' · ' . date('d.m.Y', strtotime($event->veranstaltungsdatum)); ?>
    </p>

    <?php if ($station->beschreibung) : ?>
        <p style="font-size:12pt;margin-bottom:6px;"><strong>Aufgabe:</strong> <?php echo esc_html($station->beschreibung); ?></p>
    <?php endif; ?>
    <?php if ($station->treffpunkt) : ?>
        <p style="font-size:12pt;margin-bottom:6px;"><strong>📍 Treffpunkt:</strong> <?php echo esc_html($station->treffpunkt); ?></p>
    <?php endif; ?>
    <?php if ($station->ansprechperson1) : ?>
        <p style="font-size:12pt;margin-bottom:6px;"><strong>👤 Ansprechperson:</strong> <?php echo esc_html($station->ansprechperson1); ?><?php echo $station->ansprechperson1_kontakt ? ' (' . esc_html($station->ansprechperson1_kontakt) . ')' : ''; ?></p>
    <?php endif; ?>
    <?php if ($station->ansprechperson2) : ?>
        <p style="font-size:12pt;margin-bottom:6px;"><strong>👤 Ansprechperson:</strong> <?php echo esc_html($station->ansprechperson2); ?><?php echo $station->ansprechperson2_kontakt ? ' (' . esc_html($station->ansprechperson2_kontakt) . ')' : ''; ?></p>
    <?php endif; ?>

    <h2>Schichten</h2>

    <?php if (!empty($station->schichten)) : ?>
        <table>
            <thead>
                <tr><th style="width:25%;">Zeit</th><th style="width:15%;">Plätze</th><th>Eingetragen</th></tr>
            </thead>
            <tbody>
                <?php foreach ($station->schichten as $schicht) :
                    $unterbesetzt = $schicht->min_plaetze > 0 && $schicht->belegt < $schicht->min_plaetze;
                ?>
                    <tr>
                        <td>
                            <?php if ($schicht->titel) echo '<strong>' . esc_html($schicht->titel) . '</strong><br>'; ?>
                            <?php
                            if ($schicht->start_zeit) {
                                echo date('d.m. H:i', strtotime($schicht->start_zeit));
                                if ($schicht->end_zeit) echo ' – ' . date('H:i', strtotime($schicht->end_zeit)) . ' Uhr';
                            } else {
                                echo '<span class="wlp-namen-leer">ohne festen Termin</span>';
                            }
                            ?>
                        </td>
                        <td class="<?php echo $unterbesetzt ? 'wlp-unterbesetzt' : ''; ?>">
                            <?php echo $schicht->belegt; ?>/<?php echo $schicht->max_plaetze; ?>
                            <?php if ($unterbesetzt) echo ' ⚠️'; ?>
                        </td>
                        <td>
                            <?php if (!empty($schicht->eintragungen)) : ?>
                                <?php echo esc_html(implode(', ', wp_list_pluck($schicht->eintragungen, 'name'))); ?>
                            <?php else : ?>
                                <span class="wlp-namen-leer">— noch niemand eingetragen —</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p class="wlp-namen-leer">Keine Schichten angelegt.</p>
    <?php endif; ?>

    <p class="wlp-meta" style="margin-top:30px;">Ausgehängt am: <span class="wlp-signaturzeile"></span> &nbsp;&nbsp; Stand: <?php echo date('d.m.Y H:i'); ?> Uhr</p>
    <?php
    wl_print_html_end();
}
