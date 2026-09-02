<?php
defined('ABSPATH') || exit;

// [schichtplan event="stadtfestival"] – öffentliche Anzeige + Eintragung
add_shortcode('schichtplan', 'wl_shortcode_schichtplan');

// [schichtplan_verwaltung] – Mitglieder: Events/Stationen/Schichten anlegen
add_shortcode('schichtplan_verwaltung', 'wl_shortcode_schichtplan_verwaltung');

// ─── ÖFFENTLICHE ANZEIGE ──────────────────────────────────────────────────

// Austragen-Handler früh über init abfangen (nicht im Shortcode),
// damit er auf JEDER Seite funktioniert unabhängig von der Shortcode-URL.
add_action('init', 'wl_init_handle_self_removal');
function wl_init_handle_self_removal() {
    if (empty($_GET['wl_abmelden'])) return;
    $key = sanitize_text_field($_GET['wl_abmelden']);
    if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce($_REQUEST['_wpnonce'], 'wl_abmelden_' . $key)) return;
    wl_handle_self_removal($key);
}

function wl_shortcode_schichtplan($atts) {
    $atts = shortcode_atts(['event' => ''], $atts);

    $event = !empty($atts['event']) ? wl_get_event_by_slug($atts['event']) : null;

    if (!$event) {
        // Kein Event angegeben oder nicht gefunden → Liste aller aktiven Events anzeigen
        return wl_render_event_auswahl();
    }

    if (!$event->aktiv) {
        return '<div class="wl-wrap"><div class="wl-notice wl-notice-warn">Dieser Schichtplan ist aktuell nicht aktiv.</div></div>';
    }

    return wl_render_schichtplan($event->id);
}

function wl_render_event_auswahl() {
    $events = wl_get_events(true);
    ob_start();
    ?>
    <div class="wl-wrap wls-wrap">
        <div class="wl-header">
            <h2 class="wl-title">📋 Schichtpläne</h2>
            <p class="wl-subtitle">Wähle eine Veranstaltung, um dich für eine Schicht einzutragen.</p>
        </div>
        <div class="wls-event-grid">
            <?php foreach ($events as $e) : ?>
                <a href="<?php echo esc_url(add_query_arg('event', $e->slug)); ?>" class="wls-event-card">
                    <h3><?php echo esc_html($e->titel); ?></h3>
                    <?php if ($e->veranstaltungsdatum) : ?>
                        <span class="wls-event-date">📅 <?php echo date('d.m.Y', strtotime($e->veranstaltungsdatum)); ?></span>
                    <?php endif; ?>
                    <?php if ($e->beschreibung) : ?>
                        <p><?php echo esc_html(wp_trim_words($e->beschreibung, 20)); ?></p>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
            <?php if (empty($events)) : ?>
                <div class="wl-empty">Aktuell gibt es keine aktiven Schichtpläne.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function wl_render_schichtplan($event_id) {
    $event = wl_get_event_full($event_id);
    if (!$event) return '<div class="wl-wrap"><div class="wl-notice wl-notice-error">Veranstaltung nicht gefunden.</div></div>';

    $abmelde_msg = isset($_GET['wl_abgemeldet']) ? 'Du wurdest erfolgreich von der Schicht ausgetragen.' : '';

    ob_start();
    ?>
    <div class="wl-wrap wls-wrap" id="wls-board">

        <div class="wl-header">
            <h2 class="wl-title">📋 <?php echo esc_html($event->titel); ?></h2>
            <?php if ($event->veranstaltungsdatum) : ?>
                <p class="wl-subtitle">📅 <?php echo date('d.m.Y', strtotime($event->veranstaltungsdatum)); ?></p>
            <?php endif; ?>
            <?php if ($event->beschreibung) : ?>
                <p class="wl-subtitle"><?php echo esc_html($event->beschreibung); ?></p>
            <?php endif; ?>
        </div>

        <?php if ($abmelde_msg) : ?>
            <div class="wl-notice" style="background:#dcfce7;color:#166534;border-left-color:#16a34a;"><?php echo esc_html($abmelde_msg); ?></div>
        <?php endif; ?>

        <div class="wls-kalender-wrap wls-only-desktop">
            <?php
            $k = wl_get_schichtplan_kalender($event);
            $tage = $k['tage'];
            $ohne_termin = $k['ohne_termin'];
            ?>

            <?php if (empty($event->stationen)) : ?>
                <div class="wl-empty">Für diese Veranstaltung wurden noch keine Stationen angelegt.</div>
            <?php elseif (empty($tage) && empty($ohne_termin)) : ?>
                <div class="wl-empty">Noch keine Schichten angelegt.</div>
            <?php else : ?>

                <div class="wls-tage-scroll">
                <div class="wls-tage-row">
                <?php foreach ($tage as $tag => $tagdaten) :
                    if (empty($tagdaten['schichten'])) continue;
                    $achse_end   = $tagdaten['achse_end']; // bereits komprimierte Gesamtlänge, achse_start ist immer 0
                    $gesamt_einheiten = max(60, $achse_end);
                    $px_pro_einheit = 1.1; // Skalierung der Zeitachse
                    $hoehe_px    = $gesamt_einheiten * $px_pro_einheit;
                    $stationen_des_tages = $tagdaten['stationen'];
                    $marker = $tagdaten['achsen_marker'];
                ?>
                <div class="wls-tag-block">
                    <h3 class="wls-tag-titel"><?php echo esc_html(date_i18n('l, d.m.Y', strtotime($tag))); ?></h3>

                    <div class="wls-kalender-grid">

                        <!-- Zeitachse links: nur Stunden-Marker innerhalb belegter Zeiträume -->
                        <div class="wls-zeitachse" style="height:<?php echo $hoehe_px; ?>px;">
                            <?php foreach ($marker as $mk) :
                                $top = $mk['comp_pos'] * $px_pro_einheit;
                                $std = floor((($mk['real_min'] % 1440) + 1440) % 1440 / 60);
                            ?>
                                <div class="wls-zeit-label" style="top:<?php echo $top; ?>px;"><?php echo sprintf('%02d:00', $std); ?></div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Lücken-Trennlinien (zeigen visuell an, wo Zeit komprimiert wurde) -->
                        <?php foreach ($tagdaten['luecken'] as $lk) :
                            $top = $lk['comp_start'] * $px_pro_einheit;
                            $hoehe_lk = ($lk['comp_ende'] - $lk['comp_start']) * $px_pro_einheit;
                            $dauer_std = round(($lk['real_ende'] - $lk['real_start']) / 60, 1);
                        ?>
                            <div class="wls-luecken-marker" style="top:<?php echo $top; ?>px; height:<?php echo $hoehe_lk; ?>px;" title="<?php echo esc_attr($dauer_std . ' Std. ohne Schicht'); ?>">
                                <span class="wls-luecke-label">⋯ <?php echo $dauer_std; ?>h Pause ⋯</span>
                            </div>
                        <?php endforeach; ?>

                        <!-- Stationen-Spalten -->
                        <div class="wls-stationen-spalten">
                            <?php foreach ($stationen_des_tages as $sid => $gruppe) :
                                $station = $gruppe['station'];
                                $lane_count = $gruppe['lane_count'];
                            ?>
                            <div class="wls-stations-spalte">
                                <div class="wls-stations-spalte-kopf">
                                    <div class="wls-spalte-kopf-zeile">
                                        <strong><?php echo esc_html($station->titel); ?></strong>
                                        <?php if ($station->beschreibung) : ?>
                                            <button type="button" class="wls-info-btn" data-info="<?php echo esc_attr($station->beschreibung); ?>" title="Was ist hier zu tun?">ℹ️</button>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($station->treffpunkt) : ?>
                                        <div class="wls-spalte-meta">📍 <?php echo esc_html($station->treffpunkt); ?></div>
                                    <?php endif; ?>
                                    <?php if ($station->ansprechperson1) : ?>
                                        <div class="wls-spalte-meta">👤 <?php echo esc_html($station->ansprechperson1); ?><?php echo $station->ansprechperson1_kontakt ? ' (' . esc_html($station->ansprechperson1_kontakt) . ')' : ''; ?></div>
                                    <?php endif; ?>
                                    <?php if ($station->ansprechperson2) : ?>
                                        <div class="wls-spalte-meta">👤 <?php echo esc_html($station->ansprechperson2); ?><?php echo $station->ansprechperson2_kontakt ? ' (' . esc_html($station->ansprechperson2_kontakt) . ')' : ''; ?></div>
                                    <?php endif; ?>
                                </div>

                                <div class="wls-spalte-zeitbereich" style="height:<?php echo $hoehe_px; ?>px;">
                                    <?php
                                    // Stundenlinien als Hintergrund-Raster (nur innerhalb belegter Zeiträume)
                                    foreach ($marker as $mk) :
                                        $top = $mk['comp_pos'] * $px_pro_einheit;
                                    ?>
                                        <div class="wls-stunden-linie" style="top:<?php echo $top; ?>px;"></div>
                                    <?php endforeach; ?>

                                    <?php foreach ($gruppe['items'] as $item) :
                                        $schicht = $item['schicht'];
                                        $voll = $schicht->frei <= 0;
                                        $top = $item['comp_start'] * $px_pro_einheit;
                                        $hoehe = max(36, ($item['comp_end'] - $item['comp_start']) * $px_pro_einheit);
                                        $lane_width = 100 / $lane_count;
                                        $left = $item['lane'] * $lane_width;
                                    ?>
                                        <div class="wls-block <?php echo $voll ? 'wls-block-voll' : ''; ?>"
                                             id="wls-schicht-<?php echo $schicht->id; ?>"
                                             style="top:<?php echo $top; ?>px; height:<?php echo $hoehe; ?>px; left:<?php echo $left; ?>%; width:calc(<?php echo $lane_width; ?>% - 4px);">
                                            <?php if ($schicht->titel) : ?>
                                                <div class="wls-block-titel"><?php echo esc_html($schicht->titel); ?></div>
                                            <?php endif; ?>
                                            <div class="wls-block-zeit">
                                                🕐 <?php echo date('H:i', strtotime($schicht->start_zeit)); ?><?php if ($schicht->end_zeit) echo '–' . date('H:i', strtotime($schicht->end_zeit)); ?>
                                            </div>
                                            <div class="wls-block-plaetze">
                                                <?php echo wl_render_plaetze_badge($schicht); ?>
                                            </div>
                                            <?php if (!empty($schicht->eintragungen)) : ?>
                                                <div class="wls-eingetragene">
                                                    <?php foreach ($schicht->eintragungen as $e) : ?>
                                                        <span class="wls-person-chip"><?php echo esc_html($e->name); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if (!$voll) : ?>
                                                <button class="wl-btn wl-btn-primary wl-btn-sm wls-eintragen-btn"
                                                    data-schicht="<?php echo $schicht->id; ?>"
                                                    data-titel="<?php echo esc_attr($station->titel . ($schicht->titel ? ' – ' . $schicht->titel : '')); ?>">
                                                    Eintragen
                                                </button>
                                            <?php else : ?>
                                                <span class="wls-voll-label">Voll</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
                </div>

                <?php if (!empty($ohne_termin)) : ?>
                <div class="wls-tag-block">
                    <h3 class="wls-tag-titel">Ohne festen Termin</h3>
                    <div class="wls-ohne-termin-liste">
                        <?php foreach ($ohne_termin as $item) :
                            $schicht = $item['schicht'];
                            $station = $item['station'];
                            $voll = $schicht->frei <= 0;
                        ?>
                            <div class="wls-block wls-block-liste <?php echo $voll ? 'wls-block-voll' : ''; ?>" id="wls-schicht-<?php echo $schicht->id; ?>">
                                <div class="wls-block-station"><?php echo esc_html($station->titel); ?></div>
                                <?php if ($schicht->titel) : ?>
                                    <div class="wls-block-titel"><?php echo esc_html($schicht->titel); ?></div>
                                <?php endif; ?>
                                <?php if ($station->beschreibung) : ?>
                                    <p class="wls-mobile-card-desc"><?php echo esc_html($station->beschreibung); ?></p>
                                <?php endif; ?>
                                <?php if ($station->treffpunkt || $station->ansprechperson1 || $station->ansprechperson2) : ?>
                                    <div class="wls-mobile-card-meta">
                                        <?php if ($station->treffpunkt) echo '<span>📍 ' . esc_html($station->treffpunkt) . '</span>'; ?>
                                        <?php if ($station->ansprechperson1) echo '<span>👤 ' . esc_html($station->ansprechperson1) . '</span>'; ?>
                                        <?php if ($station->ansprechperson2) echo '<span>👤 ' . esc_html($station->ansprechperson2) . '</span>'; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="wls-block-plaetze">
                                    <?php echo wl_render_plaetze_badge($schicht); ?>
                                </div>
                                <?php if (!empty($schicht->eintragungen)) : ?>
                                    <div class="wls-eingetragene">
                                        <?php foreach ($schicht->eintragungen as $e) : ?>
                                            <span class="wls-person-chip"><?php echo esc_html($e->name); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!$voll) : ?>
                                    <button class="wl-btn wl-btn-primary wl-btn-sm wls-eintragen-btn"
                                        data-schicht="<?php echo $schicht->id; ?>"
                                        data-titel="<?php echo esc_attr($station->titel . ($schicht->titel ? ' – ' . $schicht->titel : '')); ?>">
                                        Eintragen
                                    </button>
                                <?php else : ?>
                                    <span class="wls-voll-label">Voll</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>

        <?php echo wl_render_schichtplan_mobile($event); ?>

        <div class="wlv-footer-note">
            Du erhältst nach der Eintragung einen Link, mit dem du dich jederzeit wieder austragen kannst. Bewahre ihn dir auf (z.B. per Lesezeichen), falls keine E-Mail-Bestätigung ankommt.
        </div>
    </div>

    <!-- Eintragungs-Modal -->
    <div class="wl-modal-overlay" id="wls-modal" style="display:none;">
        <div class="wl-modal">
            <button type="button" class="wl-modal-close" id="wls-modal-close">✕</button>
            <h3>Für Schicht eintragen</h3>
            <p id="wls-modal-schicht-titel" style="font-weight:600;color:#2563eb;"></p>
            <form id="wls-eintragen-form">
                <input type="hidden" name="action" value="wl_schicht_eintragen">
                <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('wl_nonce'); ?>">
                <input type="hidden" name="schicht_id" id="wls-form-schicht-id">
                <div class="wlv-field">
                    <label>Name *</label>
                    <input type="text" name="name" required placeholder="Vor- und Nachname"
                        <?php if (is_user_logged_in()) echo 'value="' . esc_attr(wp_get_current_user()->display_name) . '"'; ?>>
                </div>
                <div class="wlv-field">
                    <label>E-Mail *</label>
                    <input type="email" name="email" required placeholder="deine@email.de"
                        <?php if (is_user_logged_in()) echo 'value="' . esc_attr(wp_get_current_user()->user_email) . '"'; ?>>
                </div>
                <div class="wlv-field">
                    <label>Telefonnummer (optional)</label>
                    <input type="tel" name="telefon" placeholder="z.B. für kurzfristige Absprachen">
                </div>
                <button type="submit" class="wl-btn wl-btn-primary" style="width:100%;">Verbindlich eintragen</button>
                <div id="wls-form-feedback" style="display:none;margin-top:10px;"></div>
            </form>
        </div>
    </div>

    <!-- Tausch-Modal -->
    <div class="wl-modal-overlay" id="wls-tausch-modal" style="display:none;">
        <div class="wl-modal">
            <button type="button" class="wl-modal-close" id="wls-tausch-modal-close">✕</button>
            <h3>🔄 Schicht tauschen</h3>
            <p>Gib die E-Mail der Person ein, die deine Schicht übernehmen soll. Sie bekommt eine Anfrage per Mail und kann annehmen oder ablehnen.</p>
            <input type="hidden" id="wls-tausch-manage-key">
            <div class="wlv-field">
                <label>E-Mail der angefragten Person *</label>
                <input type="email" id="wls-tausch-email" placeholder="z.B. anna@example.de">
            </div>
            <div class="wlv-field">
                <label>Name (optional)</label>
                <input type="text" id="wls-tausch-name" placeholder="z.B. Anna">
            </div>
            <div class="wl-form-actions" style="margin-top:12px;">
                <button type="button" class="wl-btn wl-btn-primary" id="wls-tausch-senden">Anfrage senden</button>
                <button type="button" class="wl-btn wl-btn-secondary" id="wls-tausch-abbrechen">Abbrechen</button>
            </div>
            <div id="wls-tausch-feedback" style="display:none;margin-top:10px;"></div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ─── MOBILE ANSICHT: GESTAPELTE LISTE STATT KALENDER ──────────────────────

function wl_render_schichtplan_mobile($event) {
    $k = wl_get_schichtplan_kalender($event);
    $tage = $k['tage'];
    $ohne_termin = $k['ohne_termin'];

    ob_start();
    ?>
    <div class="wls-mobile-wrap wls-only-mobile">
        <?php if (empty($event->stationen)) : ?>
            <div class="wl-empty">Für diese Veranstaltung wurden noch keine Stationen angelegt.</div>
        <?php else : ?>

            <?php foreach ($tage as $tag => $tagdaten) :
                if (empty($tagdaten['schichten'])) continue;

                // Alle Schichten dieses Tages chronologisch sortiert, stationsübergreifend
                $alle = [];
                foreach ($tagdaten['stationen'] as $gruppe) {
                    foreach ($gruppe['items'] as $item) {
                        $alle[] = $item;
                    }
                }
                usort($alle, function ($a, $b) { return $a['start_min'] <=> $b['start_min']; });
            ?>
                <div class="wls-mobile-tag">
                    <h3 class="wls-tag-titel"><?php echo esc_html(date_i18n('l, d.m.Y', strtotime($tag))); ?></h3>
                    <div class="wls-mobile-liste">
                        <?php foreach ($alle as $item) :
                            $schicht = $item['schicht'];
                            $station = $item['station'];
                            $voll = $schicht->frei <= 0;
                        ?>
                            <div class="wls-mobile-card <?php echo $voll ? 'wls-block-voll' : ''; ?>" id="wls-schicht-mobile-<?php echo $schicht->id; ?>">
                                <div class="wls-mobile-card-zeit">
                                    🕐 <?php echo date('H:i', strtotime($schicht->start_zeit)); ?><?php if ($schicht->end_zeit) echo ' – ' . date('H:i', strtotime($schicht->end_zeit)) . ' Uhr'; ?>
                                </div>
                                <div class="wls-mobile-card-station">
                                    <strong><?php echo esc_html($station->titel); ?></strong>
                                    <?php if ($schicht->titel) echo ' · ' . esc_html($schicht->titel); ?>
                                </div>
                                <?php if ($station->beschreibung) : ?>
                                    <p class="wls-mobile-card-desc"><?php echo esc_html($station->beschreibung); ?></p>
                                <?php endif; ?>
                                <?php if ($station->treffpunkt || $station->ansprechperson1 || $station->ansprechperson2) : ?>
                                    <div class="wls-mobile-card-meta">
                                        <?php if ($station->treffpunkt) echo '<span>📍 ' . esc_html($station->treffpunkt) . '</span>'; ?>
                                        <?php if ($station->ansprechperson1) echo '<span>👤 ' . esc_html($station->ansprechperson1) . ($station->ansprechperson1_kontakt ? ' (' . esc_html($station->ansprechperson1_kontakt) . ')' : '') . '</span>'; ?>
                                        <?php if ($station->ansprechperson2) echo '<span>👤 ' . esc_html($station->ansprechperson2) . ($station->ansprechperson2_kontakt ? ' (' . esc_html($station->ansprechperson2_kontakt) . ')' : '') . '</span>'; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="wls-mobile-card-bottom">
                                    <?php echo wl_render_plaetze_badge($schicht, ' Plätze'); ?>
                                    <?php if (!$voll) : ?>
                                        <button class="wl-btn wl-btn-primary wl-btn-sm wls-eintragen-btn"
                                            data-schicht="<?php echo $schicht->id; ?>"
                                            data-titel="<?php echo esc_attr($station->titel . ($schicht->titel ? ' – ' . $schicht->titel : '')); ?>">
                                            Eintragen
                                        </button>
                                    <?php else : ?>
                                        <span class="wls-voll-label">Voll</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($schicht->eintragungen)) : ?>
                                    <div class="wls-eingetragene">
                                        <?php foreach ($schicht->eintragungen as $e) : ?>
                                            <span class="wls-person-chip"><?php echo esc_html($e->name); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (!empty($ohne_termin)) : ?>
                <div class="wls-mobile-tag">
                    <h3 class="wls-tag-titel">Ohne festen Termin</h3>
                    <div class="wls-mobile-liste">
                        <?php foreach ($ohne_termin as $item) :
                            $schicht = $item['schicht'];
                            $station = $item['station'];
                            $voll = $schicht->frei <= 0;
                        ?>
                            <div class="wls-mobile-card <?php echo $voll ? 'wls-block-voll' : ''; ?>" id="wls-schicht-mobile-<?php echo $schicht->id; ?>">
                                <div class="wls-mobile-card-station">
                                    <strong><?php echo esc_html($station->titel); ?></strong>
                                    <?php if ($schicht->titel) echo ' · ' . esc_html($schicht->titel); ?>
                                </div>
                                <div class="wls-mobile-card-bottom">
                                    <?php echo wl_render_plaetze_badge($schicht, ' Plätze'); ?>
                                    <?php if (!$voll) : ?>
                                        <button class="wl-btn wl-btn-primary wl-btn-sm wls-eintragen-btn"
                                            data-schicht="<?php echo $schicht->id; ?>"
                                            data-titel="<?php echo esc_attr($station->titel . ($schicht->titel ? ' – ' . $schicht->titel : '')); ?>">
                                            Eintragen
                                        </button>
                                    <?php else : ?>
                                        <span class="wls-voll-label">Voll</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($schicht->eintragungen)) : ?>
                                    <div class="wls-eingetragene">
                                        <?php foreach ($schicht->eintragungen as $e) : ?>
                                            <span class="wls-person-chip"><?php echo esc_html($e->name); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

// ─── HELPER: PLÄTZE-BADGE MIT MIN-WARNUNG ─────────────────────────────────

function wl_render_plaetze_badge($schicht, $suffix = '') {
    $voll = $schicht->frei <= 0;
    $unterbesetzt = $schicht->min_plaetze > 0 && $schicht->belegt < $schicht->min_plaetze;
    $html = '<span class="wls-platz-badge' . ($voll ? ' voll' : '') . ($unterbesetzt ? ' unterbesetzt' : '') . '">';
    $html .= $schicht->belegt . '/' . $schicht->max_plaetze . $suffix;
    $html .= '</span>';
    if ($unterbesetzt) {
        $html .= '<span class="wls-min-hinweis" title="Mindestens ' . (int) $schicht->min_plaetze . ' Person(en) nötig">⚠️ min. ' . (int) $schicht->min_plaetze . '</span>';
    }
    return $html;
}

// ─── SELBST-AUSTRAGUNG ÜBER LINK ──────────────────────────────────────────

function wl_handle_self_removal($manage_key) {
    global $wpdb;
    $table = $wpdb->prefix . 'wl_shift_eintragungen';
    $eintragung = wl_get_eintragung_by_key($manage_key);

    if ($eintragung) {
        $schicht = wl_get_schicht($eintragung->schicht_id);
        $wpdb->delete($table, ['manage_key' => $manage_key]);

        if ($schicht) {
            $station = wl_get_station($schicht->station_id);
            $event = $station ? wl_get_event($station->event_id) : null;
            if ($event) {
                wp_safe_redirect(add_query_arg(['event' => $event->slug, 'wl_abgemeldet' => '1'], remove_query_arg(['wl_abmelden', '_wpnonce'])));
                exit;
            }
        }
    }
    wp_safe_redirect(remove_query_arg(['wl_abmelden', '_wpnonce']));
    exit;
}

function wl_get_abmelde_link($manage_key, $event_slug) {
    $url = add_query_arg(['event' => $event_slug, 'wl_abmelden' => $manage_key], wl_get_schichtplan_page_url());
    return wp_nonce_url($url, 'wl_abmelden_' . $manage_key);
}

function wl_get_schichtplan_page_url() {
    // Versucht, die Seite mit dem [schichtplan]-Shortcode zu finden; Fallback: aktuelle Seite
    global $wpdb;
    $page_id = $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE '%[schichtplan%' AND post_status = 'publish' LIMIT 1");
    return $page_id ? get_permalink($page_id) : home_url('/');
}
