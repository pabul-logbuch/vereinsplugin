<?php
defined('ABSPATH') || exit;

// ─── STUFEN-DEFINITIONEN ─────────────────────────────────────────────────────

function wl_vote_stufen() {
    return [
        1 => [
            'label'  => 'Braucht das Jufo',
            'icon'   => '🟢',
            'kurz'   => 'Notwendig',
            'score'  => 2,
            'color'  => '#16a34a',
            'bg'     => '#dcfce7',
        ],
        2 => [
            'label'  => 'Wünsche ich mir fürs Jufo',
            'icon'   => '🔵',
            'kurz'   => 'Wunsch',
            'score'  => 1,
            'color'  => '#2563eb',
            'bg'     => '#dbeafe',
        ],
        3 => [
            'label'  => 'Egal',
            'icon'   => '⚪',
            'kurz'   => 'Neutral',
            'score'  => 0,
            'color'  => '#64748b',
            'bg'     => '#f1f5f9',
        ],
        4 => [
            'label'  => 'Braucht das Jufo nicht',
            'icon'   => '🟠',
            'kurz'   => 'Unnötig',
            'score'  => -1,
            'color'  => '#ea580c',
            'bg'     => '#ffedd5',
        ],
        5 => [
            'label'  => 'Veto – ungeeignet fürs Jufo',
            'icon'   => '🔴',
            'kurz'   => 'Veto',
            'score'  => -99,
            'color'  => '#dc2626',
            'bg'     => '#fef2f2',
        ],
    ];
}

// ─── VOTER-KEY ERMITTELN ─────────────────────────────────────────────────────

function wl_get_voter_key() {
    if (is_user_logged_in()) {
        return 'user_' . get_current_user_id();
    }
    // Gast: Session-Cookie
    if (!isset($_COOKIE['wl_guest_key'])) {
        $key = 'guest_' . wp_generate_password(24, false);
        setcookie('wl_guest_key', $key, time() + (30 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);
        return $key;
    }
    return sanitize_key($_COOKIE['wl_guest_key']);
}

function wl_get_voter_type() {
    return is_user_logged_in() ? 'mitglied' : 'gast';
}

// ─── VOTES ABRUFEN ───────────────────────────────────────────────────────────

function wl_get_vote_stats($wunsch_id) {
    global $wpdb;
    $votes_table = $wpdb->prefix . 'wl_votes';

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT stufe, voter_type, COUNT(*) as anzahl FROM $votes_table WHERE wunsch_id = %d GROUP BY stufe, voter_type",
        $wunsch_id
    ));

    $stufen    = wl_vote_stufen();
    $stats     = ['total' => 0, 'score' => 0, 'veto' => false, 'veto_begruendungen' => [], 'by_stufe' => []];
    $mitglieder_count = 0;

    foreach ($stufen as $s => $def) {
        $stats['by_stufe'][$s] = ['anzahl' => 0, 'mitglieder' => 0, 'gaeste' => 0];
    }

    foreach ($rows as $row) {
        $s = intval($row->stufe);
        $n = intval($row->anzahl);
        $stats['by_stufe'][$s]['anzahl']    += $n;
        $stats['by_stufe'][$s][$row->voter_type === 'mitglied' ? 'mitglieder' : 'gaeste'] += $n;
        $stats['total'] += $n;
        // Score: nur Mitglieder zählen für Reihenfolge
        if ($row->voter_type === 'mitglied') {
            $mitglieder_count += $n;
            $stats['score'] += $stufen[$s]['score'] * $n;
        }
        if ($s === 5 && $row->voter_type === 'mitglied') {
            $stats['veto'] = true;
        }
    }

    // Veto-Begründungen laden
    if ($stats['veto']) {
        $stats['veto_begruendungen'] = $wpdb->get_results($wpdb->prepare(
            "SELECT voter_name, begruendung, abgestimmt_am FROM $votes_table
             WHERE wunsch_id = %d AND stufe = 5 AND voter_type = 'mitglied' AND begruendung != ''
             ORDER BY abgestimmt_am DESC",
            $wunsch_id
        ));
    }

    return $stats;
}

function wl_get_my_vote($wunsch_id) {
    global $wpdb;
    $votes_table = $wpdb->prefix . 'wl_votes';
    $voter_key   = wl_get_voter_key();

    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $votes_table WHERE wunsch_id = %d AND voter_key = %s",
        $wunsch_id, $voter_key
    ));
}

// ─── WÜNSCHE NACH SCORE SORTIERT ─────────────────────────────────────────────

function wl_get_wuensche_mit_score($nur_aktiv = true) {
    global $wpdb;
    $wt = $wpdb->prefix . 'wunschliste';
    $vt = $wpdb->prefix . 'wl_votes';

    $where = $nur_aktiv ? "WHERE w.vote_status = 'aktiv' AND w.status != 'erfuellt'" : '';

    // Score = Summe(stufen_score * anzahl_mitglieder) – Veto hat -99 pro Stimme → immer ans Ende
    $sql = "
        SELECT w.*,
            COALESCE(SUM(CASE WHEN v.voter_type='mitglied' THEN
                CASE v.stufe
                    WHEN 1 THEN 2
                    WHEN 2 THEN 1
                    WHEN 3 THEN 0
                    WHEN 4 THEN -1
                    WHEN 5 THEN -99
                    ELSE 0 END
                ELSE 0 END), 0) AS vote_score,
            COUNT(DISTINCT v.id) AS vote_count,
            MAX(CASE WHEN v.stufe=5 AND v.voter_type='mitglied' THEN 1 ELSE 0 END) AS hat_veto
        FROM $wt w
        LEFT JOIN $vt v ON v.wunsch_id = w.id
        $where
        GROUP BY w.id
        ORDER BY hat_veto ASC, vote_score DESC, w.prioritaet ASC, w.erstellt_am ASC
    ";

    return $wpdb->get_results($sql);
}

// ─── GASTCODE VALIDIEREN ─────────────────────────────────────────────────────

function wl_validate_gastcode($code) {
    global $wpdb;
    $codes = $wpdb->prefix . 'wl_gastcodes';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $codes WHERE code = %s AND aktiv = 1 AND (gueltig_bis IS NULL OR gueltig_bis > NOW())",
        sanitize_text_field($code)
    ));
    return $row ? true : false;
}

// ─── SHORTCODE: VOTING-SEITE ─────────────────────────────────────────────────

add_shortcode('wunschliste_voting', 'wl_shortcode_voting');

function wl_shortcode_voting($atts) {
    $atts = shortcode_atts(['modus' => 'voting'], $atts);

    // Zugang prüfen
    $hat_zugang  = false;
    $ist_mitglied = false;
    $gast_name    = '';

    if (is_user_logged_in() && wl_can_manage()) {
        $hat_zugang   = true;
        $ist_mitglied = true;
        $gast_name    = wp_get_current_user()->display_name;
    } elseif (isset($_SESSION['wl_gast_ok']) && $_SESSION['wl_gast_ok']) {
        $hat_zugang = true;
        $gast_name  = sanitize_text_field($_SESSION['wl_gast_name'] ?? 'Gast');
    }

    // Session starten falls nötig
    if (!session_id()) session_start();

    ob_start();

    if (!$hat_zugang) {
        wl_render_voting_login();
    } else {
        wl_render_voting_board($ist_mitglied, $gast_name);
    }

    return ob_get_clean();
}

// ─── LOGIN-MASKE ─────────────────────────────────────────────────────────────

function wl_render_voting_login() {
    ?>
    <div class="wl-wrap wlv-login-wrap">
        <div class="wlv-login-card">
            <div class="wlv-login-icon">🗳️</div>
            <h2>Abstimmung – Jufo Wunschliste</h2>
            <p>Melde dich an oder gib deinen Gast-Code ein, um abzustimmen.</p>

            <div class="wlv-tabs">
                <button class="wlv-tab active" data-tab="mitglied">Mitglied</button>
                <button class="wlv-tab" data-tab="gast">Gast-Code</button>
            </div>

            <div class="wlv-tab-content" id="wlv-tab-mitglied">
                <?php wp_login_form(['redirect' => get_permalink(), 'label_log_in' => 'Einloggen & abstimmen']); ?>
            </div>

            <div class="wlv-tab-content" id="wlv-tab-gast" style="display:none;">
                <form id="wlv-gast-form">
                    <input type="hidden" name="action" value="wl_gast_login">
                    <input type="hidden" name="nonce" value="<?php echo wp_create_nonce('wl_nonce'); ?>">
                    <div class="wlv-field">
                        <label>Dein Name</label>
                        <input type="text" name="gast_name" placeholder="Wie heißt du?" required>
                    </div>
                    <div class="wlv-field">
                        <label>Gast-Code</label>
                        <input type="text" name="gast_code" placeholder="z.B. MEETING2025" required autocomplete="off">
                    </div>
                    <button type="submit" class="wl-btn wl-btn-primary" style="width:100%">Abstimmen</button>
                    <div id="wlv-gast-error" class="wlv-error" style="display:none;"></div>
                </form>
            </div>
        </div>
    </div>
    <?php
}

// ─── VOTING BOARD ────────────────────────────────────────────────────────────

function wl_render_voting_board($ist_mitglied, $voter_name) {
    $wuensche = wl_get_wuensche_mit_score(true);
    $stufen   = wl_vote_stufen();
    $voter_key = wl_get_voter_key();
    $kategorien = wl_get_kategorien();
    ?>
    <div class="wl-wrap wlv-wrap" id="wlv-board"
         data-mitglied="<?php echo $ist_mitglied ? '1' : '0'; ?>"
         data-voter="<?php echo esc_attr($voter_key); ?>">

        <div class="wlv-header">
            <div>
                <h2 class="wlv-title">🗳️ Abstimmung Wunschliste</h2>
                <p class="wlv-subtitle">Eingeloggt als <strong><?php echo esc_html($voter_name); ?></strong>
                    <?php if (!$ist_mitglied) echo '<span class="wlv-badge-gast">Gast</span>'; ?></p>
            </div>
            <div style="display:flex;gap:8px;">
                <?php if ($ist_mitglied) : ?>
                    <button type="button" class="wl-btn wl-btn-primary wl-btn-sm" id="wlv-neu-wunsch-btn">+ Neuer Wunsch</button>
                <?php endif; ?>
                <a href="<?php echo esc_url(add_query_arg('wl_gast_logout', '1')); ?>" class="wl-btn wl-btn-secondary wl-btn-sm">Abmelden</a>
            </div>
        </div>

        <!-- Legende -->
        <div class="wlv-legende">
            <?php foreach ($stufen as $s => $def) : ?>
                <div class="wlv-legende-item">
                    <span class="wlv-dot" style="background:<?php echo $def['color']; ?>"></span>
                    <span><?php echo $def['icon']; ?> <?php echo esc_html($def['label']); ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Kategorie-Filter -->
        <?php if (!empty($kategorien)) : ?>
        <div class="wl-filter-bar">
            <button type="button" class="wl-filter-btn active" data-filter="">Alle</button>
            <?php foreach ($kategorien as $kat) : ?>
                <button type="button" class="wl-filter-btn" data-filter="<?php echo esc_attr($kat); ?>"><?php echo esc_html($kat); ?></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($ist_mitglied) : ?>
        <!-- Wunsch-Formular (Mitglieder, inline) -->
        <div class="wl-form-panel" id="wlv-wunsch-form-panel" style="display:none;">
            <h3 id="wlv-wunsch-form-title">Neuer Wunsch</h3>
            <form id="wlv-wunsch-form">
                <input type="hidden" name="action" value="wl_save_wunsch">
                <input type="hidden" name="wl_nonce" value="<?php echo wp_create_nonce('wl_nonce'); ?>">
                <input type="hidden" name="id" id="wlv-edit-id" value="">

                <div class="wl-form-row">
                    <label>Titel *</label>
                    <input type="text" name="titel" id="wlv-edit-titel" required placeholder="z.B. Neue Trikots">
                </div>
                <div class="wl-form-row">
                    <label>Beschreibung</label>
                    <textarea name="beschreibung" id="wlv-edit-desc" rows="2" placeholder="Kurz: Was wird benötigt?"></textarea>
                </div>
                <div class="wl-form-row">
                    <label>Begründung</label>
                    <textarea name="begruendung" id="wlv-edit-begruendung" rows="2" placeholder="Warum braucht ihr das?"></textarea>
                </div>
                <div class="wl-form-grid">
                    <div class="wl-form-row">
                        <label>Betrag (€)</label>
                        <input type="number" name="betrag" id="wlv-edit-betrag" min="0" step="0.01" placeholder="0.00">
                    </div>
                    <div class="wl-form-row">
                        <label>Kategorie</label>
                        <input type="text" name="kategorie" id="wlv-edit-kat" placeholder="z.B. Sport" list="wlv-kat-list">
                        <datalist id="wlv-kat-list">
                            <?php foreach ($kategorien as $kat) : ?><option value="<?php echo esc_attr($kat); ?>"><?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="wl-form-row">
                        <label>Status</label>
                        <select name="status" id="wlv-edit-status">
                            <option value="offen">Offen</option>
                            <option value="in_bearbeitung">In Bearbeitung</option>
                            <option value="erfuellt">Erfüllt</option>
                        </select>
                    </div>
                    <div class="wl-form-row">
                        <label>Priorität</label>
                        <select name="prioritaet" id="wlv-edit-prio">
                            <option value="1">1 – Dringend</option>
                            <option value="2" selected>2 – Normal</option>
                            <option value="3">3 – Irgendwann</option>
                        </select>
                    </div>
                </div>
                <div class="wl-form-row">
                    <label>Bild-URL</label>
                    <input type="url" name="bild_url" id="wlv-edit-bild" placeholder="https://...">
                </div>
                <div class="wl-form-actions">
                    <button type="submit" class="wl-btn wl-btn-primary">Speichern</button>
                    <button type="button" class="wl-btn wl-btn-secondary" id="wlv-wunsch-form-cancel">Abbrechen</button>
                </div>
                <div id="wlv-wunsch-save-feedback"></div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Wunsch-Karten -->
        <div class="wlv-liste" id="wlv-liste">
            <?php foreach ($wuensche as $i => $w) :
                $my_vote = wl_get_my_vote($w->id);
                $stats   = wl_get_vote_stats($w->id);
                $hat_veto = $stats['veto'];
                $links = wl_get_links($w->id);
                $preis_anzeige = wl_format_preis($w);
            ?>
            <div class="wlv-card <?php echo $hat_veto ? 'wlv-card-veto' : ''; ?>"
                 id="wlv-card-<?php echo $w->id; ?>" data-id="<?php echo $w->id; ?>"
                 data-kategorie="<?php echo esc_attr($w->kategorie); ?>">

                <div class="wlv-card-rank"><?php echo $hat_veto ? '🚫' : '#' . ($i + 1); ?></div>

                <?php if ($w->bild_url) : ?>
                    <div class="wlv-card-img">
                        <img src="<?php echo esc_url($w->bild_url); ?>" alt="<?php echo esc_attr($w->titel); ?>">
                    </div>
                <?php endif; ?>

                <div class="wlv-card-info">
                    <div class="wlv-card-meta">
                        <?php if ($w->kategorie) echo '<span class="wl-badge wl-badge-kat">' . esc_html($w->kategorie) . '</span>'; ?>
                        <?php if ($hat_veto) echo '<span class="wl-badge" style="background:#fef2f2;color:#dc2626">⛔ Veto aktiv</span>'; ?>
                        <span class="wl-badge wl-badge-status wl-status-<?php echo esc_attr($w->status); ?>"><?php echo wl_status_label($w->status); ?></span>
                        <?php if ($w->prioritaet == 1) echo '<span class="wl-badge wl-badge-prio">⭐ Dringend</span>'; ?>
                    </div>
                    <h3 class="wlv-card-titel"><?php echo esc_html($w->titel); ?></h3>
                    <?php if ($w->beschreibung) : ?>
                        <p class="wlv-card-desc"><?php echo esc_html($w->beschreibung); ?></p>
                    <?php endif; ?>

                    <?php if (!empty($w->begruendung)) : ?>
                        <details class="wl-begruendung">
                            <summary>Warum brauchen wir das?</summary>
                            <p><?php echo esc_html($w->begruendung); ?></p>
                        </details>
                    <?php endif; ?>

                    <?php if (!empty($links)) : ?>
                        <div class="wl-links">
                            <div class="wl-links-list">
                                <?php foreach ($links as $link) : ?>
                                    <a href="<?php echo esc_url($link->url); ?>" target="_blank" rel="noopener noreferrer nofollow" class="wl-link-chip">
                                        🔗 <?php echo esc_html($link->label ?: parse_url($link->url, PHP_URL_HOST)); ?>
                                        <?php if ($link->preis) : ?><span class="wl-link-preis"><?php echo number_format($link->preis, 2, ',', '.'); ?> €</span><?php endif; ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($preis_anzeige) : ?>
                        <div class="wlv-betrag-row"><span class="wlv-betrag">💶 <?php echo esc_html($preis_anzeige); ?></span></div>
                    <?php endif; ?>

                    <!-- Veto-Begründungen -->
                    <?php if ($hat_veto && !empty($stats['veto_begruendungen'])) : ?>
                    <div class="wlv-veto-box">
                        <strong>⛔ Veto-Begründungen:</strong>
                        <?php foreach ($stats['veto_begruendungen'] as $vb) : ?>
                            <div class="wlv-veto-item">
                                <span class="wlv-veto-name"><?php echo esc_html($vb->voter_name ?: 'Anonym'); ?></span>
                                <span class="wlv-veto-text"><?php echo esc_html($vb->begruendung); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Ergebnis-Balken -->
                    <div class="wlv-stats" id="wlv-stats-<?php echo $w->id; ?>">
                        <?php echo wl_render_stats_html($stats, $stufen, $w->id); ?>
                    </div>

                    <?php if ($ist_mitglied) : ?>
                    <div class="wlv-card-admin-actions">
                        <button type="button" class="wl-btn wl-btn-sm wl-btn-edit wlv-edit-wunsch-btn"
                            data-id="<?php echo $w->id; ?>"
                            data-titel="<?php echo esc_attr($w->titel); ?>"
                            data-desc="<?php echo esc_attr($w->beschreibung); ?>"
                            data-begruendung="<?php echo esc_attr($w->begruendung); ?>"
                            data-betrag="<?php echo esc_attr($w->betrag); ?>"
                            data-kat="<?php echo esc_attr($w->kategorie); ?>"
                            data-status="<?php echo esc_attr($w->status); ?>"
                            data-prio="<?php echo esc_attr($w->prioritaet); ?>"
                            data-bild="<?php echo esc_attr($w->bild_url); ?>">
                            ✏️ Bearbeiten
                        </button>
                        <button type="button" class="wl-btn wl-btn-sm wl-btn-delete wlv-delete-wunsch-btn" data-id="<?php echo $w->id; ?>">🗑️ Löschen</button>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Abstimm-Buttons -->
                <div class="wlv-vote-col">
                    <div class="wlv-vote-buttons" id="wlv-buttons-<?php echo $w->id; ?>">
                        <?php foreach ($stufen as $s => $def) : ?>
                            <button type="button" class="wlv-vote-btn <?php echo ($my_vote && intval($my_vote->stufe) === $s) ? 'active' : ''; ?>"
                                data-wunsch="<?php echo $w->id; ?>"
                                data-stufe="<?php echo $s; ?>"
                                data-needs-reason="<?php echo $s === 5 ? '1' : '0'; ?>"
                                style="--vote-color:<?php echo $def['color']; ?>;--vote-bg:<?php echo $def['bg']; ?>"
                                title="<?php echo esc_attr($def['label']); ?>">
                                <?php echo $def['icon']; ?>
                                <span><?php echo esc_html($def['kurz']); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($my_vote) : ?>
                        <div class="wlv-my-vote" id="wlv-myvote-<?php echo $w->id; ?>">
                            Deine Stimme: <strong><?php echo $stufen[intval($my_vote->stufe)]['icon'] . ' ' . $stufen[intval($my_vote->stufe)]['kurz']; ?></strong>
                        </div>
                    <?php else : ?>
                        <div class="wlv-my-vote wlv-no-vote" id="wlv-myvote-<?php echo $w->id; ?>">Noch nicht abgestimmt</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (empty($wuensche)) : ?>
                <div class="wl-empty">Keine aktiven Wünsche zur Abstimmung.</div>
            <?php endif; ?>
        </div>


        <!-- Score-Info -->
        <div class="wlv-footer-note">
            Die Reihenfolge ergibt sich aus den Mitglieder-Stimmen. Gäste-Stimmen sind sichtbar, aber nicht ranglistenrelevant. Ein Veto eines Mitglieds setzt den Wunsch ans Ende.
        </div>
    </div>

    <!-- Veto-Modal (Begründung) -->
    <div class="wl-modal-overlay" id="wlv-veto-modal" style="display:none;">
        <div class="wl-modal">
            <button type="button" class="wl-modal-close" id="wlv-veto-close">✕</button>
            <h3>🔴 Veto einlegen</h3>
            <p>Du legst ein schwerwiegendes Bedenken gegen diesen Wunsch ein. Bitte begründe kurz warum.</p>
            <input type="hidden" id="wlv-veto-wunsch-id">
            <div class="wlv-field">
                <label>Begründung *</label>
                <textarea id="wlv-veto-begruendung" rows="4" placeholder="Warum ist dieser Wunsch ungeeignet fürs Jufo?" style="width:100%;box-sizing:border-box;"></textarea>
            </div>
            <div class="wl-form-actions" style="margin-top:12px;">
                <button class="wl-btn wl-btn-primary" id="wlv-veto-confirm" style="background:#dc2626;">Veto abgeben</button>
                <button class="wl-btn wl-btn-secondary" id="wlv-veto-cancel">Abbrechen</button>
            </div>
            <div id="wlv-veto-error" class="wlv-error" style="display:none;margin-top:8px;"></div>
        </div>
    </div>
    <?php

    // Gast-Logout
    if (isset($_GET['wl_gast_logout'])) {
        if (!session_id()) session_start();
        unset($_SESSION['wl_gast_ok'], $_SESSION['wl_gast_name']);
        wp_safe_redirect(get_permalink());
        exit;
    }
}

// ─── STATS HTML ──────────────────────────────────────────────────────────────

function wl_render_stats_html($stats, $stufen, $wunsch_id) {
    if ($stats['total'] === 0) {
        return '<div class="wlv-no-votes">Noch keine Stimmen</div>';
    }

    $html  = '<div class="wlv-bar-wrap">';
    foreach ($stufen as $s => $def) {
        $n     = $stats['by_stufe'][$s]['anzahl'];
        $m     = $stats['by_stufe'][$s]['mitglieder'];
        $g     = $stats['by_stufe'][$s]['gaeste'];
        if ($n === 0) continue;
        $pct   = round($n / $stats['total'] * 100);
        $label = $def['icon'] . ' ' . $def['kurz'] . ': ' . $n;
        if ($g > 0) $label .= ' (' . $m . ' Mitgl. + ' . $g . ' Gast)';
        $html .= '<div class="wlv-bar-row" title="' . esc_attr($def['label'] . ': ' . $n . ' Stimmen') . '">';
        $html .= '<span class="wlv-bar-label">' . esc_html($def['icon'] . ' ' . $def['kurz']) . '</span>';
        $html .= '<div class="wlv-bar-track"><div class="wlv-bar-fill" style="width:' . $pct . '%;background:' . $def['color'] . '"></div></div>';
        $html .= '<span class="wlv-bar-count">' . $n . '</span>';
        $html .= '</div>';
    }
    $score_label = $stats['veto'] ? '<span style="color:#dc2626">⛔ Veto</span>' : '<strong>Score: ' . $stats['score'] . '</strong>';
    $html .= '</div><div class="wlv-score-line">' . $score_label . ' · ' . $stats['total'] . ' Stimmen</div>';
    return $html;
}
