<?php
defined('ABSPATH') || exit;

add_action('admin_menu', 'wlv_admin_menu');
function wlv_admin_menu() {
    add_submenu_page('wunschliste', 'Abstimmung', 'Abstimmung', 'wl_manage_wishes', 'wunschliste-voting', 'wlv_ergebnis_page');
    add_submenu_page('wunschliste', 'Gast-Codes', 'Gast-Codes', 'manage_options', 'wunschliste-gastcodes', 'wlv_gastcodes_page');
}

// ─── ERGEBNIS-SEITE ──────────────────────────────────────────────────────────

function wlv_ergebnis_page() {
    $wuensche = wl_get_wuensche_mit_score(false);
    $stufen   = wl_vote_stufen();

    // Votes zurücksetzen
    if (isset($_POST['wlv_reset_all']) && check_admin_referer('wlv_reset')) {
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}wl_votes");
        $wpdb->query("UPDATE {$wpdb->prefix}wunschliste SET vote_status='aktiv'");
        echo '<div class="notice notice-success"><p>Alle Stimmen zurückgesetzt.</p></div>';
        $wuensche = wl_get_wuensche_mit_score(false);
    }
    ?>
    <div class="wrap">
        <h1>🗳️ Abstimmungs-Ergebnisse</h1>

        <div style="display:flex;gap:12px;align-items:center;margin-bottom:20px;flex-wrap:wrap;">
            <a href="<?php echo admin_url('admin.php?page=wunschliste-gastcodes'); ?>" class="button">Gast-Codes verwalten →</a>
            <form method="post" style="margin:0;" onsubmit="return confirm('Wirklich alle Stimmen löschen?');">
                <?php wp_nonce_field('wlv_reset'); ?>
                <button name="wlv_reset_all" class="button button-secondary" style="color:#dc2626;border-color:#dc2626;">Alle Stimmen zurücksetzen</button>
            </form>
        </div>

        <p>Shortcode für die Abstimmungsseite: <code>[wunschliste_voting]</code></p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="40">Rang</th>
                    <th>Wunsch</th>
                    <th width="80">Score</th>
                    <th width="80">Stimmen</th>
                    <th>Verteilung</th>
                    <th width="80">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($wuensche as $i => $w) :
                $stats = wl_get_vote_stats($w->id);
                $hat_veto = $stats['veto'];
            ?>
                <tr style="<?php echo $hat_veto ? 'background:#fff5f5;' : ''; ?>">
                    <td><?php echo $hat_veto ? '🚫' : '#' . ($i + 1); ?></td>
                    <td>
                        <strong><?php echo esc_html($w->titel); ?></strong>
                        <?php if ($hat_veto && !empty($stats['veto_begruendungen'])) : ?>
                            <br><small style="color:#dc2626;">⛔ Veto: <?php echo esc_html($stats['veto_begruendungen'][0]->begruendung); ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="font-weight:700;color:<?php echo $hat_veto ? '#dc2626' : ($w->vote_score > 0 ? '#16a34a' : '#64748b'); ?>">
                        <?php echo $hat_veto ? 'VETO' : (int)$w->vote_score; ?>
                    </td>
                    <td><?php echo $stats['total']; ?></td>
                    <td>
                        <?php foreach ($stufen as $s => $def) :
                            $n = $stats['by_stufe'][$s]['anzahl'];
                            if ($n === 0) continue;
                        ?>
                            <span title="<?php echo esc_attr($def['label']); ?>" style="
                                display:inline-block;background:<?php echo $def['bg']; ?>;color:<?php echo $def['color']; ?>;
                                border-radius:999px;padding:2px 8px;font-size:12px;margin:1px;font-weight:600;">
                                <?php echo $def['icon'] . ' ' . $n; ?>
                            </span>
                        <?php endforeach; ?>
                    </td>
                    <td>
                        <span class="wl-badge wl-badge-status wl-status-<?php echo esc_attr($w->status); ?>">
                            <?php echo wl_status_label($w->status); ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// ─── GAST-CODES SEITE ────────────────────────────────────────────────────────

function wlv_gastcodes_page() {
    global $wpdb;
    $codes_table = $wpdb->prefix . 'wl_gastcodes';

    // Code anlegen
    $msg = '';
    if (isset($_POST['wlv_create_code']) && check_admin_referer('wlv_codes')) {
        $code    = strtoupper(sanitize_text_field($_POST['code']));
        $desc    = sanitize_text_field($_POST['beschreibung']);
        $gueltig = !empty($_POST['gueltig_bis']) ? sanitize_text_field($_POST['gueltig_bis']) : null;

        if (empty($code)) {
            $msg = '<div class="notice notice-error"><p>Code darf nicht leer sein.</p></div>';
        } elseif ($wpdb->get_var($wpdb->prepare("SELECT id FROM $codes_table WHERE code=%s", $code))) {
            $msg = '<div class="notice notice-error"><p>Dieser Code existiert bereits.</p></div>';
        } else {
            $wpdb->insert($codes_table, [
                'code'         => $code,
                'beschreibung' => $desc,
                'gueltig_bis'  => $gueltig,
                'erstellt_von' => get_current_user_id(),
                'aktiv'        => 1,
            ]);
            $msg = '<div class="notice notice-success"><p>Code <strong>' . esc_html($code) . '</strong> erstellt.</p></div>';
        }
    }

    // Code löschen/deaktivieren
    if (isset($_GET['wlv_toggle']) && check_admin_referer('wlv_toggle_' . $_GET['wlv_toggle'])) {
        $id  = intval($_GET['wlv_toggle']);
        $cur = $wpdb->get_var($wpdb->prepare("SELECT aktiv FROM $codes_table WHERE id=%d", $id));
        $wpdb->update($codes_table, ['aktiv' => $cur ? 0 : 1], ['id' => $id]);
        wp_safe_redirect(admin_url('admin.php?page=wunschliste-gastcodes'));
        exit;
    }

    $alle_codes = $wpdb->get_results("SELECT * FROM $codes_table ORDER BY erstellt_am DESC");
    ?>
    <div class="wrap">
        <h1>🔑 Gast-Codes für Abstimmungen</h1>
        <?php echo $msg; ?>
        <p>Erstelle Codes für Meetings, damit auch nicht-registrierte Mitglieder abstimmen können. Gäste können <strong>kein Veto</strong> einlegen.</p>

        <h2>Neuen Code erstellen</h2>
        <form method="post">
            <?php wp_nonce_field('wlv_codes'); ?>
            <table class="form-table">
                <tr>
                    <th>Code</th>
                    <td>
                        <input class="regular-text" type="text" name="code"
                            value="<?php echo esc_attr(strtoupper(wp_generate_password(8, false))); ?>"
                            placeholder="z.B. MEETING2025" style="text-transform:uppercase;">
                        <p class="description">Groß-/Kleinschreibung egal. Wird automatisch großgeschrieben.</p>
                    </td>
                </tr>
                <tr>
                    <th>Beschreibung</th>
                    <td><input class="regular-text" type="text" name="beschreibung" placeholder="z.B. Jahreshauptversammlung 2025"></td>
                </tr>
                <tr>
                    <th>Gültig bis (optional)</th>
                    <td><input type="datetime-local" name="gueltig_bis">
                        <p class="description">Leer lassen = unbegrenzt gültig.</p>
                    </td>
                </tr>
            </table>
            <input type="submit" name="wlv_create_code" class="button button-primary" value="Code erstellen">
        </form>

        <h2 style="margin-top:32px;">Bestehende Codes</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr><th>Code</th><th>Beschreibung</th><th>Gültig bis</th><th>Erstellt am</th><th>Status</th><th>Aktion</th></tr>
            </thead>
            <tbody>
            <?php foreach ($alle_codes as $c) : ?>
                <tr>
                    <td><code style="font-size:14px;font-weight:700;"><?php echo esc_html($c->code); ?></code></td>
                    <td><?php echo esc_html($c->beschreibung); ?></td>
                    <td><?php echo $c->gueltig_bis ? date('d.m.Y H:i', strtotime($c->gueltig_bis)) : '–'; ?></td>
                    <td><?php echo date('d.m.Y', strtotime($c->erstellt_am)); ?></td>
                    <td>
                        <?php if (!$c->aktiv) : ?>
                            <span style="color:#64748b;">● Deaktiviert</span>
                        <?php elseif ($c->gueltig_bis && strtotime($c->gueltig_bis) < time()) : ?>
                            <span style="color:#dc2626;">● Abgelaufen</span>
                        <?php else : ?>
                            <span style="color:#16a34a;">● Aktiv</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=wunschliste-gastcodes&wlv_toggle=' . $c->id), 'wlv_toggle_' . $c->id); ?>"
                           class="button button-small">
                            <?php echo $c->aktiv ? 'Deaktivieren' : 'Aktivieren'; ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($alle_codes)) : ?>
                <tr><td colspan="6" style="text-align:center;color:#64748b;">Noch keine Codes erstellt.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
