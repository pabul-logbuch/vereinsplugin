<?php
defined('ABSPATH') || exit;

function wl_shortcode_schichtplan_verwaltung() {
    if (!is_user_logged_in()) {
        return '<div class="wl-wrap"><div class="wl-notice wl-notice-warn">Bitte <a href="' . esc_url(wp_login_url(get_permalink())) . '">einloggen</a>, um Schichtpläne zu verwalten.</div></div>';
    }
    if (!wl_can_manage()) {
        return '<div class="wl-wrap"><div class="wl-notice wl-notice-error">Du hast keine Berechtigung für diesen Bereich.</div></div>';
    }

    // Welches Event wird bearbeitet?
    $event_id = isset($_GET['wls_event']) ? intval($_GET['wls_event']) : 0;

    if ($event_id > 0) {
        return wl_render_event_editor($event_id);
    }
    return wl_render_event_liste();
}

// ─── EVENT-LISTE ────────────────────────────────────────────────────────────

function wl_render_event_liste() {
    $events = wl_get_events(false);
    ob_start();
    ?>
    <div class="wl-wrap wl-admin-wrap" id="wls-verwaltung">
        <div class="wl-admin-header">
            <h2>📋 Schichtpläne verwalten</h2>
            <button type="button" class="wl-btn wl-btn-primary" id="wls-neu-event-btn">+ Neue Veranstaltung</button>
        </div>

        <!-- Neues Event Formular -->
        <div class="wl-form-panel" id="wls-event-form-panel" style="display:none;">
            <h3>Neue Veranstaltung</h3>
            <form id="wls-event-form">
                <input type="hidden" name="action" value="wl_save_event">
                <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('wl_nonce'); ?>">
                <div class="wl-form-row">
                    <label>Titel *</label>
                    <input type="text" name="titel" required placeholder="z.B. Stadtfestival 2026">
                </div>
                <div class="wl-form-row">
                    <label>Beschreibung</label>
                    <textarea name="beschreibung" rows="2" placeholder="Kurzbeschreibung der Veranstaltung"></textarea>
                </div>
                <div class="wl-form-row">
                    <label>Datum</label>
                    <input type="date" name="veranstaltungsdatum">
                </div>
                <div class="wl-form-row">
                    <label>Tagesgrenze (Stunde)</label>
                    <select name="tagesgrenze_stunde">
                        <option value="0">Keine (Kalendertag = Anzeigetag)</option>
                        <option value="2">02:00 Uhr</option>
                        <option value="3">03:00 Uhr</option>
                        <option value="4" selected>04:00 Uhr</option>
                        <option value="5">05:00 Uhr</option>
                        <option value="6">06:00 Uhr</option>
                    </select>
                    <p style="font-size:.8rem;color:#64748b;margin:4px 0 0;">
                        Schichten, die vor dieser Uhrzeit beginnen, werden in der Tagesübersicht noch dem Vortag zugeordnet (z.B. eine Nachtschicht 01:00–04:00 Uhr zählt bei Tagesgrenze 4 Uhr noch zum Vortag).
                    </p>
                </div>
                <div class="wl-form-actions">
                    <button type="submit" class="wl-btn wl-btn-primary">Anlegen</button>
                    <button type="button" class="wl-btn wl-btn-secondary" id="wls-event-form-cancel">Abbrechen</button>
                </div>
                <div id="wls-event-form-feedback"></div>
            </form>
        </div>

        <!-- CSV Import Hinweis -->
        <div class="wl-notice" style="background:#eff6ff;border-left-color:#2563eb;">
            💡 Du kannst Schichtpläne auch komplett per CSV/XML importieren — praktisch bei vielen Stationen.
            <a href="<?php echo admin_url('admin.php?page=wunschliste-schichtplan-import'); ?>" target="_blank">Zum Import →</a>
        </div>

        <div class="wl-table-wrap">
            <table class="wl-table">
                <thead>
                    <tr><th>Veranstaltung</th><th>Datum</th><th>Stationen</th><th>Status</th><th>Shortcode</th><th>Aktionen</th></tr>
                </thead>
                <tbody>
                <?php foreach ($events as $e) :
                    $stationen_count = count(wl_get_stationen($e->id));
                ?>
                    <tr id="wls-event-row-<?php echo $e->id; ?>">
                        <td><strong><?php echo esc_html($e->titel); ?></strong></td>
                        <td><?php echo $e->veranstaltungsdatum ? date('d.m.Y', strtotime($e->veranstaltungsdatum)) : '–'; ?></td>
                        <td><?php echo $stationen_count; ?></td>
                        <td>
                            <?php if ($e->aktiv) : ?>
                                <span class="wl-badge" style="background:#dcfce7;color:#166534;">Aktiv</span>
                            <?php else : ?>
                                <span class="wl-badge" style="background:#f1f5f9;color:#64748b;">Inaktiv</span>
                            <?php endif; ?>
                        </td>
                        <td><code>[schichtplan event="<?php echo esc_html($e->slug); ?>"]</code></td>
                        <td class="wl-actions">
                            <a href="<?php echo esc_url(add_query_arg('wls_event', $e->id)); ?>" class="wl-btn wl-btn-sm wl-btn-edit">✏️ Bearbeiten</a>
                            <button type="button" class="wl-btn wl-btn-sm wls-toggle-event-btn" data-id="<?php echo $e->id; ?>" data-aktiv="<?php echo $e->aktiv; ?>">
                                <?php echo $e->aktiv ? '⏸ Deaktivieren' : '▶ Aktivieren'; ?>
                            </button>
                            <button type="button" class="wl-btn wl-btn-sm wl-btn-delete wls-delete-event-btn" data-id="<?php echo $e->id; ?>">🗑️ Löschen</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($events)) : ?>
                    <tr><td colspan="6" class="wl-empty">Noch keine Veranstaltungen angelegt.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ─── EVENT-EDITOR (Stationen + Schichten) ──────────────────────────────────

function wl_render_event_editor($event_id) {
    $event = wl_get_event($event_id);
    if (!$event) {
        return '<div class="wl-wrap"><div class="wl-notice wl-notice-error">Veranstaltung nicht gefunden.</div></div>';
    }
    $stationen = wl_get_stationen($event_id);

    ob_start();
    ?>
    <div class="wl-wrap wl-admin-wrap" id="wls-event-editor" data-event-id="<?php echo $event_id; ?>">
        <div class="wl-admin-header">
            <div>
                <a href="<?php echo esc_url(remove_query_arg('wls_event')); ?>" style="font-size:.85rem;color:#64748b;">← Zurück zur Übersicht</a>
                <h2 style="margin-top:4px;">📋 <?php echo esc_html($event->titel); ?></h2>
                <p style="color:#64748b;margin:0;">Shortcode: <code>[schichtplan event="<?php echo esc_html($event->slug); ?>"]</code></p>
                <p style="color:#64748b;margin:4px 0 0;font-size:.85rem;">
                    Tagesgrenze: <strong><?php echo $event->tagesgrenze_stunde > 0 ? sprintf('%02d:00 Uhr', $event->tagesgrenze_stunde) : 'keine'; ?></strong>
                    <button type="button" class="wl-btn wl-btn-sm wl-btn-secondary" id="wls-edit-event-settings-btn" style="margin-left:8px;">⚙️ Einstellungen ändern</button>
                </p>
            </div>
            <div style="display:flex;gap:8px;">
                <a href="<?php echo esc_url(add_query_arg(['wl_print' => 'gesamt', 'event' => $event_id], home_url('/'))); ?>" target="_blank" class="wl-btn wl-btn-secondary">🖨️ Gesamtplan drucken</a>
                <button type="button" class="wl-btn wl-btn-primary" id="wls-neu-station-btn">+ Neue Station</button>
            </div>
        </div>

        <!-- Event-Einstellungen bearbeiten (Tagesgrenze etc.) -->
        <div class="wl-form-panel" id="wls-event-settings-panel" style="display:none;">
            <h3>Veranstaltungs-Einstellungen</h3>
            <form id="wls-event-settings-form">
                <input type="hidden" name="action" value="wl_save_event">
                <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('wl_nonce'); ?>">
                <input type="hidden" name="id" value="<?php echo $event_id; ?>">
                <div class="wl-form-row">
                    <label>Titel *</label>
                    <input type="text" name="titel" required value="<?php echo esc_attr($event->titel); ?>">
                </div>
                <div class="wl-form-row">
                    <label>Beschreibung</label>
                    <textarea name="beschreibung" rows="2"><?php echo esc_textarea($event->beschreibung); ?></textarea>
                </div>
                <div class="wl-form-row">
                    <label>Datum</label>
                    <input type="date" name="veranstaltungsdatum" value="<?php echo esc_attr($event->veranstaltungsdatum); ?>">
                </div>
                <div class="wl-form-row">
                    <label>Tagesgrenze (Stunde)</label>
                    <select name="tagesgrenze_stunde">
                        <?php foreach ([0 => 'Keine (Kalendertag = Anzeigetag)', 2 => '02:00 Uhr', 3 => '03:00 Uhr', 4 => '04:00 Uhr', 5 => '05:00 Uhr', 6 => '06:00 Uhr'] as $val => $label) : ?>
                            <option value="<?php echo $val; ?>" <?php selected((int)$event->tagesgrenze_stunde, $val); ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p style="font-size:.8rem;color:#64748b;margin:4px 0 0;">
                        Schichten, die vor dieser Uhrzeit beginnen, werden in der Tagesübersicht noch dem Vortag zugeordnet.
                    </p>
                </div>
                <div class="wl-form-actions">
                    <button type="submit" class="wl-btn wl-btn-primary">Speichern</button>
                    <button type="button" class="wl-btn wl-btn-secondary" id="wls-event-settings-cancel">Abbrechen</button>
                </div>
                <div id="wls-event-settings-feedback"></div>
            </form>
        </div>

        <!-- Stations-Formular -->
        <div class="wl-form-panel" id="wls-station-form-panel" style="display:none;">
            <h3 id="wls-station-form-title">Neue Station</h3>
            <form id="wls-station-form">
                <input type="hidden" name="action" value="wl_save_station">
                <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('wl_nonce'); ?>">
                <input type="hidden" name="event_id" value="<?php echo $event_id; ?>">
                <input type="hidden" name="id" id="wls-station-id" value="">

                <div class="wl-form-row">
                    <label>Stations-Titel *</label>
                    <input type="text" name="titel" id="wls-station-titel" required placeholder="z.B. Hauptausschank">
                </div>
                <div class="wl-form-row">
                    <label>Erklärung / Aufgabenbeschreibung</label>
                    <textarea name="beschreibung" id="wls-station-desc" rows="2" placeholder="Was ist an dieser Station zu tun?"></textarea>
                </div>
                <div class="wl-form-row">
                    <label>Treffpunkt</label>
                    <input type="text" name="treffpunkt" id="wls-station-treffpunkt" placeholder="z.B. Orga-Container">
                </div>
                <div class="wl-form-grid">
                    <div class="wl-form-row">
                        <label>Ansprechperson 1</label>
                        <input type="text" name="ansprechperson1" id="wls-station-ap1" placeholder="Name">
                    </div>
                    <div class="wl-form-row">
                        <label>Kontakt (Telefon/E-Mail)</label>
                        <input type="text" name="ansprechperson1_kontakt" id="wls-station-ap1-kontakt" placeholder="optional">
                    </div>
                    <div class="wl-form-row">
                        <label>Ansprechperson 2</label>
                        <input type="text" name="ansprechperson2" id="wls-station-ap2" placeholder="Name (optional)">
                    </div>
                    <div class="wl-form-row">
                        <label>Kontakt (Telefon/E-Mail)</label>
                        <input type="text" name="ansprechperson2_kontakt" id="wls-station-ap2-kontakt" placeholder="optional">
                    </div>
                </div>
                <div class="wl-form-actions">
                    <button type="submit" class="wl-btn wl-btn-primary">Speichern</button>
                    <button type="button" class="wl-btn wl-btn-secondary" id="wls-station-form-cancel">Abbrechen</button>
                </div>
                <div id="wls-station-form-feedback"></div>
            </form>
        </div>

        <!-- Stationen mit Schichten -->
        <div class="wls-stationen-admin">
            <?php foreach ($stationen as $station) :
                $schichten = wl_get_schichten($station->id);
            ?>
            <div class="wls-station-admin-card" id="wls-station-admin-<?php echo $station->id; ?>">
                <div class="wls-station-admin-head">
                    <div>
                        <h3><?php echo esc_html($station->titel); ?></h3>
                        <p class="wls-station-admin-meta">
                            <?php if ($station->treffpunkt) echo '📍 ' . esc_html($station->treffpunkt) . ' &nbsp;'; ?>
                            <?php if ($station->ansprechperson1) echo '👤 ' . esc_html($station->ansprechperson1) . ' &nbsp;'; ?>
                            <?php if ($station->ansprechperson2) echo '👤 ' . esc_html($station->ansprechperson2); ?>
                        </p>
                    </div>
                    <div class="wl-actions">
                        <a href="<?php echo esc_url(add_query_arg(['wl_print' => 'station', 'event' => $event_id, 'station' => $station->id], home_url('/'))); ?>" target="_blank" class="wl-btn wl-btn-sm wl-btn-secondary">🖨️ Drucken</a>
                        <button type="button" class="wl-btn wl-btn-sm wl-btn-edit wls-edit-station-btn"
                            data-id="<?php echo $station->id; ?>"
                            data-titel="<?php echo esc_attr($station->titel); ?>"
                            data-desc="<?php echo esc_attr($station->beschreibung); ?>"
                            data-treffpunkt="<?php echo esc_attr($station->treffpunkt); ?>"
                            data-ap1="<?php echo esc_attr($station->ansprechperson1); ?>"
                            data-ap1-kontakt="<?php echo esc_attr($station->ansprechperson1_kontakt); ?>"
                            data-ap2="<?php echo esc_attr($station->ansprechperson2); ?>"
                            data-ap2-kontakt="<?php echo esc_attr($station->ansprechperson2_kontakt); ?>">
                            ✏️ Bearbeiten
                        </button>
                        <button type="button" class="wl-btn wl-btn-sm wl-btn-delete wls-delete-station-btn" data-id="<?php echo $station->id; ?>">🗑️ Löschen</button>
                    </div>
                </div>

                <!-- Schichten-Tabelle -->
                <table class="wl-table wls-schicht-table">
                    <thead><tr><th>Titel</th><th>Start</th><th>Ende</th><th>Min.</th><th>Max.</th><th>Eingetragen</th><th>Aktionen</th></tr></thead>
                    <tbody>
                    <?php foreach ($schichten as $schicht) :
                        $eintragungen = wl_get_eintragungen($schicht->id);
                        $unterbesetzt = $schicht->min_plaetze > 0 && count($eintragungen) < $schicht->min_plaetze;
                    ?>
                        <tr id="wls-schicht-admin-<?php echo $schicht->id; ?>">
                            <td><?php echo esc_html($schicht->titel ?: '–'); ?></td>
                            <td><?php echo $schicht->start_zeit ? date('d.m. H:i', strtotime($schicht->start_zeit)) : '–'; ?></td>
                            <td><?php echo $schicht->end_zeit ? date('d.m. H:i', strtotime($schicht->end_zeit)) : '–'; ?></td>
                            <td>
                                <?php echo $schicht->min_plaetze > 0 ? $schicht->min_plaetze : '–'; ?>
                                <?php if ($unterbesetzt) echo ' <span style="color:#dc2626;" title="Mindestanzahl noch nicht erreicht">⚠️</span>'; ?>
                            </td>
                            <td><?php echo $schicht->max_plaetze; ?></td>
                            <td>
                                <?php if (!empty($eintragungen)) : ?>
                                    <?php foreach ($eintragungen as $e) : ?>
                                        <div class="wls-eintrag-line">
                                            <?php echo esc_html($e->name); ?>
                                            <small>(<?php echo esc_html($e->email); ?><?php echo $e->telefon ? ', ' . esc_html($e->telefon) : ''; ?>)</small>
                                            <?php if ($schicht->start_zeit) : ?>
                                                <a href="<?php echo esc_url(wl_get_ics_download_url($e->manage_key)); ?>" title="Kalendereintrag herunterladen" style="text-decoration:none;">📅</a>
                                            <?php endif; ?>
                                            <button type="button" class="wls-remove-eintrag-btn" data-id="<?php echo $e->id; ?>" title="Eintragung entfernen">✕</button>
                                        </div>
                                    <?php endforeach; ?>
                                    <!-- Admin: Person manuell eintragen -->
                                    <div class="wls-admin-eintrag-form" id="wls-admin-form-<?php echo $schicht->id; ?>" style="display:none;">
                                        <input type="text" class="wls-admin-name" placeholder="Name *" style="width:100%;margin-top:4px;">
                                        <input type="email" class="wls-admin-email" placeholder="E-Mail (optional)" style="width:100%;margin-top:4px;">
                                        <input type="tel" class="wls-admin-tel" placeholder="Telefon (optional)" style="width:100%;margin-top:4px;">
                                        <div style="display:flex;gap:6px;margin-top:4px;">
                                            <button type="button" class="wl-btn wl-btn-primary wl-btn-sm wls-admin-eintrag-speichern" data-schicht="<?php echo $schicht->id; ?>">✓ Eintragen</button>
                                            <button type="button" class="wl-btn wl-btn-secondary wl-btn-sm wls-admin-eintrag-abbrechen" data-schicht="<?php echo $schicht->id; ?>">Abbrechen</button>
                                        </div>
                                    </div>
                                <?php else : ?>
                                    <span style="color:#94a3b8;">– frei –</span>
                                <?php endif; ?>
                            </td>
                            <td class="wl-actions">
                                <button type="button" class="wl-btn wl-btn-sm wl-btn-edit wls-edit-schicht-btn"
                                    data-id="<?php echo $schicht->id; ?>"
                                    data-station="<?php echo $station->id; ?>"
                                    data-titel="<?php echo esc_attr($schicht->titel); ?>"
                                    data-start="<?php echo $schicht->start_zeit ? esc_attr(date('Y-m-d\TH:i', strtotime($schicht->start_zeit))) : ''; ?>"
                                    data-end="<?php echo $schicht->end_zeit ? esc_attr(date('Y-m-d\TH:i', strtotime($schicht->end_zeit))) : ''; ?>"
                                    data-min="<?php echo $schicht->min_plaetze; ?>"
                                    data-max="<?php echo $schicht->max_plaetze; ?>">
                                    ✏️ Bearbeiten
                                </button>
                                <?php if ($schicht->belegt < $schicht->max_plaetze) : ?>
                                    <button type="button" class="wl-btn wl-btn-sm wls-admin-eintrag-btn"
                                        data-schicht="<?php echo $schicht->id; ?>"
                                        style="background:#eff6ff;color:#2563eb;">
                                        + Person
                                    </button>
                                <?php endif; ?>
                                <button type="button" class="wl-btn wl-btn-sm wl-btn-delete wls-delete-schicht-btn" data-id="<?php echo $schicht->id; ?>">🗑️</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <button type="button" class="wl-btn wl-btn-secondary wl-btn-sm wls-neu-schicht-btn" data-station="<?php echo $station->id; ?>" style="margin-top:8px;">+ Schicht hinzufügen</button>
            </div>
            <?php endforeach; ?>

            <?php if (empty($stationen)) : ?>
                <div class="wl-empty">Noch keine Stationen angelegt. Klick auf „+ Neue Station".</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Schicht-Formular Modal -->
    <div class="wl-modal-overlay" id="wls-schicht-modal" style="display:none;">
        <div class="wl-modal">
            <button type="button" class="wl-modal-close" id="wls-schicht-modal-close">✕</button>
            <h3 id="wls-schicht-modal-title">Neue Schicht</h3>
            <form id="wls-schicht-form">
                <input type="hidden" name="action" value="wl_save_schicht">
                <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('wl_nonce'); ?>">
                <input type="hidden" name="id" id="wls-schicht-id" value="">
                <input type="hidden" name="station_id" id="wls-schicht-station-id" value="">

                <div class="wl-form-row">
                    <label>Titel (optional)</label>
                    <input type="text" name="titel" id="wls-schicht-titel" placeholder="z.B. 1. Schicht">
                </div>
                <div class="wl-form-grid">
                    <div class="wl-form-row">
                        <label>Start</label>
                        <input type="datetime-local" name="start_zeit" id="wls-schicht-start">
                    </div>
                    <div class="wl-form-row">
                        <label>Ende</label>
                        <input type="datetime-local" name="end_zeit" id="wls-schicht-end">
                    </div>
                    <div class="wl-form-row">
                        <label>Min. Plätze</label>
                        <input type="number" name="min_plaetze" id="wls-schicht-min" min="0" value="0">
                        <p style="font-size:.75rem;color:#64748b;margin:4px 0 0;">0 = keine Mindestanzahl nötig</p>
                    </div>
                    <div class="wl-form-row">
                        <label>Max. Plätze *</label>
                        <input type="number" name="max_plaetze" id="wls-schicht-max" min="1" value="1" required>
                    </div>
                </div>
                <div class="wl-form-actions">
                    <button type="submit" class="wl-btn wl-btn-primary">Speichern</button>
                    <button type="button" class="wl-btn wl-btn-secondary" id="wls-schicht-modal-cancel">Abbrechen</button>
                </div>
                <div id="wls-schicht-form-feedback"></div>
            </form>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
