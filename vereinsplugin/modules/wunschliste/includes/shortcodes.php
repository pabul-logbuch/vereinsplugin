<?php
defined('ABSPATH') || exit;

// [wunschliste] – öffentliche Ansicht für Spender
add_shortcode('wunschliste', 'wl_shortcode_public');

// [wunschliste_verwaltung] – Mitglieder-Bereich
add_shortcode('wunschliste_verwaltung', 'wl_shortcode_verwaltung');

// [wunschliste_login] – Login-Formular
add_shortcode('wunschliste_login', 'wl_shortcode_login');

// ─── ÖFFENTLICHE ANSICHT ──────────────────────────────────────────────────

function wl_shortcode_public($atts) {
    $atts = shortcode_atts(['kategorie' => '', 'status' => ''], $atts);

    $wuensche   = wl_get_wuensche(['status' => $atts['status'], 'kategorie' => $atts['kategorie']]);
    $kategorien = wl_get_kategorien();

    ob_start();
    ?>
    <div class="wl-wrap" id="wl-public">

        <!-- Header -->
        <div class="wl-header">
            <h2 class="wl-title">Unsere Wunschliste</h2>
            <p class="wl-subtitle">Helft uns, diese Wünsche zu erfüllen – mit einer einfachen Banküberweisung.</p>
        </div>

        <!-- Filter -->
        <?php if (!empty($kategorien)) : ?>
        <div class="wl-filter-bar">
            <button class="wl-filter-btn active" data-filter="">Alle</button>
            <?php foreach ($kategorien as $kat) : ?>
                <button class="wl-filter-btn" data-filter="<?php echo esc_attr($kat); ?>">
                    <?php echo esc_html($kat); ?>
                </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Wunsch-Liste -->
        <div class="wl-grid" id="wl-grid">
            <?php foreach ($wuensche as $w) :
                $links = wl_get_links($w->id);
                $preis_anzeige = wl_format_preis($w);
            ?>
                <div class="wl-card" data-kategorie="<?php echo esc_attr($w->kategorie); ?>" data-status="<?php echo esc_attr($w->status); ?>">

                    <?php if ($w->bild_url) : ?>
                        <div class="wl-card-img">
                            <img src="<?php echo esc_url($w->bild_url); ?>" alt="<?php echo esc_attr($w->titel); ?>">
                        </div>
                    <?php endif; ?>

                    <div class="wl-card-body">
                        <div class="wl-card-meta">
                            <?php if ($w->kategorie) : ?>
                                <span class="wl-badge wl-badge-kat"><?php echo esc_html($w->kategorie); ?></span>
                            <?php endif; ?>
                            <span class="wl-badge wl-badge-status wl-status-<?php echo esc_attr($w->status); ?>">
                                <?php echo wl_status_label($w->status); ?>
                            </span>
                            <?php if ($w->prioritaet == 1) : ?>
                                <span class="wl-badge wl-badge-prio">⭐ Dringend</span>
                            <?php endif; ?>
                        </div>

                        <h3 class="wl-card-titel"><?php echo esc_html($w->titel); ?></h3>

                        <?php if ($w->beschreibung) : ?>
                            <p class="wl-card-desc"><?php echo esc_html($w->beschreibung); ?></p>
                        <?php endif; ?>

                        <?php if (!empty($w->begruendung)) : ?>
                            <details class="wl-begruendung">
                                <summary>Warum brauchen wir das?</summary>
                                <p><?php echo esc_html($w->begruendung); ?></p>
                            </details>
                        <?php endif; ?>

                        <?php if (!empty($links)) : ?>
                            <div class="wl-links">
                                <span class="wl-links-label">Produktvorschläge:</span>
                                <div class="wl-links-list">
                                    <?php foreach ($links as $link) : ?>
                                        <a href="<?php echo esc_url($link->url); ?>" target="_blank" rel="noopener noreferrer nofollow" class="wl-link-chip">
                                            🔗 <?php echo esc_html($link->label ?: parse_url($link->url, PHP_URL_HOST)); ?>
                                            <?php if ($link->preis) : ?>
                                                <span class="wl-link-preis"><?php echo number_format($link->preis, 2, ',', '.'); ?> €</span>
                                            <?php endif; ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($preis_anzeige) : ?>
                            <div class="wl-betrag">
                                <span class="wl-betrag-label">Betrag:</span>
                                <span class="wl-betrag-value"><?php echo esc_html($preis_anzeige); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ($w->status !== 'erfuellt') : ?>
                            <button class="wl-btn wl-btn-spenden" data-id="<?php echo esc_attr($w->id); ?>" data-titel="<?php echo esc_attr($w->titel); ?>" data-betrag="<?php echo esc_attr($w->betrag ?: $w->preis_von); ?>">
                                Jetzt spenden
                            </button>
                        <?php else : ?>
                            <div class="wl-erfuellt-banner">✓ Bereits erfüllt – Danke!</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (empty($wuensche)) : ?>
                <div class="wl-empty">Aktuell gibt es keine Wünsche. Schau später wieder vorbei!</div>
            <?php endif; ?>
        </div>

        <!-- Spenden-Modal -->
        <div class="wl-modal-overlay" id="wl-modal" style="display:none;">
            <div class="wl-modal">
                <button type="button" class="wl-modal-close" id="wl-modal-close">✕</button>
                <h3>Spende für: <span id="wl-modal-titel"></span></h3>
                <p>Vielen Dank! Überweise deinen Betrag bitte auf folgendes Konto:</p>
                <div class="wl-konto">
                    <div class="wl-konto-row"><span>Kontoinhaber:</span><strong><?php echo esc_html(get_option('wl_kontoinhaber', 'Euer Verein e.V.')); ?></strong></div>
                    <div class="wl-konto-row"><span>IBAN:</span><strong><?php echo esc_html(get_option('wl_iban', 'DE00 0000 0000 0000 0000 00')); ?></strong></div>
                    <div class="wl-konto-row"><span>BIC:</span><strong><?php echo esc_html(get_option('wl_bic', 'BANKDEXX')); ?></strong></div>
                    <div class="wl-konto-row"><span>Verwendungszweck:</span><strong id="wl-modal-zweck"></strong></div>
                    <div class="wl-konto-row"><span>Betrag (Vorschlag):</span><strong id="wl-modal-betrag"></strong></div>
                </div>

                <p class="wl-modal-note">Oder schreib uns eine kurze Nachricht:</p>
                <form class="wl-kontakt-form" id="wl-spende-form">
                    <?php wp_nonce_field('wl_nonce', 'wl_nonce_field'); ?>
                    <input type="hidden" name="wunsch_id" id="wl-form-id">
                    <input type="text" name="spender_name" placeholder="Dein Name" required>
                    <input type="email" name="spender_email" placeholder="Deine E-Mail" required>
                    <input type="number" name="spende_betrag" id="wl-form-betrag" placeholder="Betrag in €" min="1" step="0.01">
                    <textarea name="spende_nachricht" placeholder="Kurze Nachricht (optional)" rows="3"></textarea>
                    <button type="submit" class="wl-btn wl-btn-primary">Nachricht absenden</button>
                </form>
                <div id="wl-form-feedback" style="display:none;"></div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ─── MITGLIEDER-BEREICH ───────────────────────────────────────────────────

function wl_shortcode_verwaltung() {
    if (!is_user_logged_in()) {
        return '<div class="wl-wrap"><div class="wl-notice wl-notice-warn">Bitte <a href="' . esc_url(wp_login_url(get_permalink())) . '">einloggen</a>, um die Wunschliste zu verwalten.</div></div>';
    }

    if (!wl_can_manage()) {
        return '<div class="wl-wrap"><div class="wl-notice wl-notice-error">Du hast keine Berechtigung für diesen Bereich.</div></div>';
    }

    $wuensche   = wl_get_wuensche(['orderby' => 'erstellt_am', 'order' => 'DESC']);
    $kategorien = wl_get_kategorien();

    ob_start();
    ?>
    <div class="wl-wrap wl-admin-wrap" id="wl-verwaltung">

        <div class="wl-admin-header">
            <h2>Wunschliste verwalten</h2>
            <button class="wl-btn wl-btn-primary" id="wl-neu-btn">+ Neuer Wunsch</button>
        </div>

        <!-- Formular (Neu/Bearbeiten) -->
        <div class="wl-form-panel" id="wl-form-panel" style="display:none;">
            <h3 id="wl-form-title">Neuer Wunsch</h3>
            <form id="wl-wunsch-form">
                <input type="hidden" name="action" value="wl_save_wunsch">
                <input type="hidden" name="wl_nonce" value="<?php echo wp_create_nonce('wl_nonce'); ?>">
                <input type="hidden" name="id" id="wl-edit-id" value="">

                <div class="wl-form-row">
                    <label>Titel *</label>
                    <input type="text" name="titel" id="wl-edit-titel" required placeholder="z.B. Neue Trikots">
                </div>
                <div class="wl-form-row">
                    <label>Beschreibung</label>
                    <textarea name="beschreibung" id="wl-edit-desc" rows="2" placeholder="Kurz: Was wird benötigt?"></textarea>
                </div>
                <div class="wl-form-row">
                    <label>Begründung</label>
                    <textarea name="begruendung" id="wl-edit-begruendung" rows="3" placeholder="Warum braucht ihr das? (wird auf der Spenderseite aufklappbar angezeigt)"></textarea>
                </div>

                <div class="wl-form-row">
                    <label>Preisangabe</label>
                    <div class="wl-preis-toggle">
                        <label><input type="radio" name="preis_modus" value="fest" checked> Festbetrag</label>
                        <label><input type="radio" name="preis_modus" value="spanne"> Preisspanne (von–bis)</label>
                    </div>
                </div>
                <div class="wl-form-grid">
                    <div class="wl-form-row wl-preis-fest">
                        <label>Betrag (€)</label>
                        <input type="number" name="betrag" id="wl-edit-betrag" min="0" step="0.01" placeholder="0.00">
                    </div>
                    <div class="wl-form-row wl-preis-spanne" style="display:none;">
                        <label>Preis von (€)</label>
                        <input type="number" name="preis_von" id="wl-edit-preis-von" min="0" step="0.01" placeholder="0.00">
                    </div>
                    <div class="wl-form-row wl-preis-spanne" style="display:none;">
                        <label>Preis bis (€)</label>
                        <input type="number" name="preis_bis" id="wl-edit-preis-bis" min="0" step="0.01" placeholder="0.00">
                    </div>
                    <div class="wl-form-row">
                        <label>Kategorie</label>
                        <input type="text" name="kategorie" id="wl-edit-kat" placeholder="z.B. Sport" list="wl-kat-list">
                        <datalist id="wl-kat-list">
                            <?php foreach ($kategorien as $kat) : ?>
                                <option value="<?php echo esc_attr($kat); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="wl-form-row">
                        <label>Status</label>
                        <select name="status" id="wl-edit-status">
                            <option value="offen">Offen</option>
                            <option value="in_bearbeitung">In Bearbeitung</option>
                            <option value="erfuellt">Erfüllt</option>
                        </select>
                    </div>
                    <div class="wl-form-row">
                        <label>Priorität</label>
                        <select name="prioritaet" id="wl-edit-prio">
                            <option value="1">1 – Dringend</option>
                            <option value="2" selected>2 – Normal</option>
                            <option value="3">3 – Irgendwann</option>
                        </select>
                    </div>
                </div>
                <div class="wl-form-row">
                    <label>Bild-URL (optional)</label>
                    <input type="url" name="bild_url" id="wl-edit-bild" placeholder="https://...">
                </div>

                <div class="wl-form-row">
                    <label>Produktlinks</label>
                    <div id="wl-links-list"></div>
                    <button type="button" class="wl-btn wl-btn-secondary wl-btn-sm" id="wl-add-link" style="align-self:flex-start;margin-top:6px;">+ Link hinzufügen</button>
                </div>

                <div class="wl-form-actions">
                    <button type="submit" class="wl-btn wl-btn-primary">Speichern</button>
                    <button type="button" class="wl-btn wl-btn-secondary" id="wl-form-cancel">Abbrechen</button>
                </div>
                <div id="wl-save-feedback"></div>
            </form>
        </div>

        <!-- Tabelle -->
        <div class="wl-table-wrap">
            <table class="wl-table" id="wl-table">
                <thead>
                    <tr>
                        <th>Titel</th>
                        <th>Kategorie</th>
                        <th>Betrag</th>
                        <th>Status</th>
                        <th>Prio</th>
                        <th>Erstellt</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($wuensche as $w) :
                    $links = wl_get_links($w->id);
                    $links_json = esc_attr(wp_json_encode(array_map(function($l) {
                        return ['label' => $l->label, 'url' => $l->url, 'preis' => $l->preis];
                    }, $links)));
                ?>
                    <tr id="wl-row-<?php echo $w->id; ?>">
                        <td><strong><?php echo esc_html($w->titel); ?></strong><br><small><?php echo esc_html(wp_trim_words($w->beschreibung, 8)); ?></small></td>
                        <td><?php echo esc_html($w->kategorie); ?></td>
                        <td><?php echo esc_html(wl_format_preis($w) ?: '–'); ?></td>
                        <td><span class="wl-badge wl-badge-status wl-status-<?php echo esc_attr($w->status); ?>"><?php echo wl_status_label($w->status); ?></span></td>
                        <td><?php echo wl_prio_label($w->prioritaet); ?></td>
                        <td><?php echo date('d.m.Y', strtotime($w->erstellt_am)); ?></td>
                        <td class="wl-actions">
                            <button class="wl-btn wl-btn-sm wl-btn-edit" 
                                data-id="<?php echo $w->id; ?>"
                                data-titel="<?php echo esc_attr($w->titel); ?>"
                                data-desc="<?php echo esc_attr($w->beschreibung); ?>"
                                data-begruendung="<?php echo esc_attr($w->begruendung); ?>"
                                data-betrag="<?php echo esc_attr($w->betrag); ?>"
                                data-preis-von="<?php echo esc_attr($w->preis_von); ?>"
                                data-preis-bis="<?php echo esc_attr($w->preis_bis); ?>"
                                data-kat="<?php echo esc_attr($w->kategorie); ?>"
                                data-status="<?php echo esc_attr($w->status); ?>"
                                data-prio="<?php echo esc_attr($w->prioritaet); ?>"
                                data-bild="<?php echo esc_attr($w->bild_url); ?>"
                                data-links="<?php echo $links_json; ?>">
                                ✏️ Bearbeiten
                            </button>
                            <button class="wl-btn wl-btn-sm wl-btn-delete" data-id="<?php echo $w->id; ?>">🗑️ Löschen</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (empty($wuensche)) : ?>
                <div class="wl-empty">Noch keine Wünsche. Klick auf "Neuer Wunsch" um anzufangen.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// ─── LOGIN-SHORTCODE ──────────────────────────────────────────────────────

function wl_shortcode_login() {
    if (is_user_logged_in()) {
        $user = wp_get_current_user();
        return '<div class="wl-wrap"><div class="wl-notice">Eingeloggt als <strong>' . esc_html($user->display_name) . '</strong>. <a href="' . wp_logout_url(get_permalink()) . '">Ausloggen</a></div></div>';
    }

    ob_start();
    ?>
    <div class="wl-wrap wl-login-wrap">
        <h3>Mitglieder-Login</h3>
        <?php
        wp_login_form([
            'redirect'       => get_permalink(),
            'label_username' => 'Benutzername',
            'label_password' => 'Passwort',
            'label_remember' => 'Angemeldet bleiben',
            'label_log_in'   => 'Einloggen',
        ]);
        ?>
    </div>
    <?php
    return ob_get_clean();
}

// ─── HILFSFUNKTIONEN ──────────────────────────────────────────────────────

function wl_status_label($status) {
    $labels = [
        'offen'         => 'Offen',
        'in_bearbeitung'=> 'In Bearbeitung',
        'erfuellt'      => 'Erfüllt',
    ];
    return $labels[$status] ?? $status;
}

function wl_prio_label($prio) {
    $labels = [1 => '⭐ Dringend', 2 => 'Normal', 3 => 'Niedrig'];
    return $labels[$prio] ?? $prio;
}
