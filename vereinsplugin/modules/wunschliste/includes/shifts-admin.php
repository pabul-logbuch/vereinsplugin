<?php
defined('ABSPATH') || exit;

add_action('admin_menu', 'wls_admin_menu');
function wls_admin_menu() {
    add_submenu_page('wunschliste', 'Schichtpläne', 'Schichtpläne', 'wl_manage_wishes', 'wunschliste-schichtplaene', 'wls_admin_page');
    add_submenu_page('wunschliste', 'Schichtplan Import', 'Schichtplan Import', 'wl_manage_wishes', 'wunschliste-schichtplan-import', 'wls_import_page');
}

// ─── ÜBERSICHTSSEITE ──────────────────────────────────────────────────────

function wls_admin_page() {
    $events = wl_get_events(false);
    ?>
    <div class="wrap">
        <h1>📋 Schichtpläne</h1>
        <p>Shortcodes: <code>[schichtplan event="slug"]</code> für eine bestimmte Veranstaltung, <code>[schichtplan]</code> für eine Übersicht aller aktiven Veranstaltungen. Verwaltung über <code>[schichtplan_verwaltung]</code> auf einer Mitgliederseite.</p>

        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th>Veranstaltung</th><th>Datum</th><th>Slug</th><th>Stationen</th><th>Eintragungen gesamt</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($events as $e) :
                $stationen = wl_get_stationen($e->id);
                $total_eintragungen = 0;
                foreach ($stationen as $s) {
                    foreach (wl_get_schichten($s->id) as $sch) {
                        $total_eintragungen += wl_count_eintragungen($sch->id);
                    }
                }
            ?>
                <tr>
                    <td><strong><?php echo esc_html($e->titel); ?></strong></td>
                    <td><?php echo $e->veranstaltungsdatum ? date('d.m.Y', strtotime($e->veranstaltungsdatum)) : '–'; ?></td>
                    <td><code><?php echo esc_html($e->slug); ?></code></td>
                    <td><?php echo count($stationen); ?></td>
                    <td><?php echo $total_eintragungen; ?></td>
                    <td><?php echo $e->aktiv ? '🟢 Aktiv' : '⚪ Inaktiv'; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($events)) : ?>
                <tr><td colspan="6" style="text-align:center;color:#64748b;">Noch keine Veranstaltungen. Lege sie über <code>[schichtplan_verwaltung]</code> oder den CSV-Import an.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// ─── IMPORT-SEITE ─────────────────────────────────────────────────────────

function wls_import_page() {
    require_once WL_PATH . 'includes/shifts-import.php';

    $result = null;
    $events = wl_get_events(false);

    if (isset($_POST['wls_do_import']) && check_admin_referer('wls_import')) {
        $event_id = intval($_POST['event_id'] ?? 0);

        // Neues Event anlegen falls gewählt
        if ($event_id === -1 && !empty($_POST['neuer_event_titel'])) {
            global $wpdb;
            $titel = sanitize_text_field($_POST['neuer_event_titel']);
            $wpdb->insert($wpdb->prefix . 'wl_shift_events', [
                'titel' => $titel,
                'slug'  => wl_generate_event_slug($titel),
                'veranstaltungsdatum' => !empty($_POST['neuer_event_datum']) ? sanitize_text_field($_POST['neuer_event_datum']) : null,
                'aktiv' => 1,
                'erstellt_von' => get_current_user_id(),
            ]);
            $event_id = $wpdb->insert_id;
        }

        if (!$event_id || $event_id < 1) {
            $result = ['stationen' => 0, 'schichten' => 0, 'errors' => ['Bitte eine Veranstaltung auswählen oder neue anlegen.']];
        } elseif (!empty($_FILES['import_file']['tmp_name'])) {
            $tmp_name  = $_FILES['import_file']['tmp_name'];
            $orig_name = $_FILES['import_file']['name'];
            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

            if ($ext === 'csv') {
                $result = wl_import_shifts_csv($tmp_name, $event_id);
            } elseif ($ext === 'xml') {
                $result = wl_import_shifts_xml($tmp_name, $event_id);
            } else {
                $result = ['stationen' => 0, 'schichten' => 0, 'errors' => ['Nur .csv oder .xml Dateien werden unterstützt.']];
            }
        } else {
            $result = ['stationen' => 0, 'schichten' => 0, 'errors' => ['Keine Datei ausgewählt.']];
        }

        $events = wl_get_events(false); // ggf. neu erstelltes Event mit aufnehmen
    }

    // Template-Download
    if (isset($_GET['wls_template']) && check_admin_referer('wls_template_' . $_GET['wls_template'])) {
        if ($_GET['wls_template'] === 'csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="schichtplan-vorlage.csv"');
            echo wl_get_shift_csv_template();
            exit;
        } elseif ($_GET['wls_template'] === 'xml') {
            header('Content-Type: application/xml; charset=UTF-8');
            header('Content-Disposition: attachment; filename="schichtplan-vorlage.xml"');
            echo wl_get_shift_xml_template();
            exit;
        }
    }
    ?>
    <div class="wrap">
        <h1>📥 Schichtplan importieren</h1>

        <?php if ($result) : ?>
            <div class="notice <?php echo $result['stationen'] > 0 ? 'notice-success' : 'notice-warning'; ?>" style="padding:14px;">
                <p><strong><?php echo $result['stationen']; ?> Stationen, <?php echo $result['schichten']; ?> Schichten importiert.</strong></p>
                <?php if (!empty($result['errors'])) : ?>
                    <ul style="margin:8px 0 0 20px;color:#7f1d1d;">
                        <?php foreach (array_slice($result['errors'], 0, 15) as $err) : ?>
                            <li><?php echo esc_html($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:20px;">

            <!-- Upload-Formular -->
            <div style="flex:1;min-width:360px;background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:24px;">
                <h2 style="margin-top:0;">Datei hochladen</h2>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('wls_import'); ?>

                    <div style="margin-bottom:16px;">
                        <label style="font-weight:600;display:block;margin-bottom:6px;">Ziel-Veranstaltung</label>
                        <select name="event_id" id="wls-event-select" style="width:100%;max-width:400px;" onchange="document.getElementById('wls-neu-event-fields').style.display = this.value === '-1' ? 'block' : 'none';">
                            <option value="">– Bitte wählen –</option>
                            <?php foreach ($events as $e) : ?>
                                <option value="<?php echo $e->id; ?>"><?php echo esc_html($e->titel); ?></option>
                            <?php endforeach; ?>
                            <option value="-1">+ Neue Veranstaltung anlegen</option>
                        </select>
                    </div>

                    <div id="wls-neu-event-fields" style="display:none;margin-bottom:16px;padding:12px;background:#f8fafc;border-radius:8px;">
                        <label style="font-weight:600;display:block;margin-bottom:6px;">Titel der neuen Veranstaltung</label>
                        <input type="text" name="neuer_event_titel" style="width:100%;max-width:400px;margin-bottom:10px;" placeholder="z.B. Stadtfestival 2026">
                        <label style="font-weight:600;display:block;margin-bottom:6px;">Datum</label>
                        <input type="date" name="neuer_event_datum">
                    </div>

                    <input type="file" name="import_file" accept=".csv,.xml" required style="margin-bottom:16px;display:block;">
                    <input type="submit" name="wls_do_import" class="button button-primary" value="Importieren">
                </form>
            </div>

            <!-- Vorlagen -->
            <div style="flex:1;min-width:320px;background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:24px;">
                <h2 style="margin-top:0;">Vorlagen herunterladen</h2>
                <p>Eine Zeile (CSV) bzw. ein <code>&lt;schicht&gt;</code> (XML) entspricht einer Schicht. Mehrere Zeilen mit demselben Stations-Namen werden automatisch zusammengefasst.</p>
                <p>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=wunschliste-schichtplan-import&wls_template=csv'), 'wls_template_csv'); ?>" class="button">⬇ CSV-Vorlage</a>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=wunschliste-schichtplan-import&wls_template=xml'), 'wls_template_xml'); ?>" class="button">⬇ XML-Vorlage</a>
                </p>
            </div>
        </div>

        <!-- Format-Doku -->
        <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:24px;margin-top:24px;">
            <h2 style="margin-top:0;">Spalten / Felder</h2>
            <table class="wp-list-table widefat striped" style="max-width:800px;">
                <thead><tr><th>Feld</th><th>Pflicht</th><th>Beschreibung</th></tr></thead>
                <tbody>
                    <tr><td><code>station</code></td><td>✅ Ja</td><td>Name der Station, z.B. "Hauptausschank"</td></tr>
                    <tr><td><code>station_beschreibung</code></td><td>Nein</td><td>Erklärung/Aufgabe an dieser Station</td></tr>
                    <tr><td><code>treffpunkt</code></td><td>Nein</td><td>Wo trifft man sich für diese Station</td></tr>
                    <tr><td><code>ansprechperson1 / 2</code></td><td>Nein</td><td>Name der Ansprechperson(en)</td></tr>
                    <tr><td><code>ansprechperson1_kontakt / 2_kontakt</code></td><td>Nein</td><td>Telefon oder E-Mail</td></tr>
                    <tr><td><code>schicht_titel</code></td><td>Nein</td><td>z.B. "1. Schicht"</td></tr>
                    <tr><td><code>start_zeit / end_zeit</code></td><td>Nein</td><td>Format: JJJJ-MM-TT HH:MM</td></tr>
                    <tr><td><code>max_plaetze</code></td><td>Nein</td><td>Anzahl Personen für diese Schicht (Standard: 1)</td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
