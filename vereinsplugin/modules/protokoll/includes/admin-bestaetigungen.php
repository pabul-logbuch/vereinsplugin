<?php
defined('ABSPATH') || exit;

add_action('admin_post_pp_bestaetigung_entscheiden', 'pp_handle_bestaetigung_entscheiden');
function pp_handle_bestaetigung_entscheiden() {
    if (!pp_can_lead()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_bestaetigung_entscheiden');
    global $wpdb;
    $id = intval($_POST['id'] ?? 0);
    $entscheidung = ($_POST['entscheidung'] ?? '') === 'bestaetigt' ? 'bestaetigt' : 'revidiert';
    $wpdb->update($wpdb->prefix . 'pp_bestaetigungen', ['status' => $entscheidung], ['id' => $id]);
    wp_safe_redirect(admin_url('admin.php?page=pp-bestaetigungen&pp_saved=1'));
    exit;
}

function pp_render_bestaetigungen_page() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    global $wpdb;
    $typ_labels = [
        'mitgliedsaufnahme' => 'Mitgliedsaufnahme',
        'mitgliedsausschluss' => 'Mitgliedsausschluss',
        'kreisgruendung' => 'Kreisgründung',
        'kreisaenderung' => 'Kreisänderung',
        'kreisaufloesung' => 'Kreisauflösung',
    ];
    $rows = $wpdb->get_results("
        SELECT b.*, g.name AS gremium_name
        FROM {$wpdb->prefix}pp_bestaetigungen b
        LEFT JOIN {$wpdb->prefix}pp_gremien g ON g.id = b.quelle_gremium_id
        ORDER BY b.status = 'offen' DESC, b.erstellt_am DESC
    ");
    ?>
    <div class="wrap pp-wrap">
        <h1>Bestätigungen (Leitungskreis → Mitgliederversammlung)</h1>
        <p class="description">Entscheidungen des Leitungskreises zu Mitgliedschaft und Kreisen (§10 Satzung) werden hier gesammelt und der nächsten Mitgliederversammlung zur Bestätigung oder Revision vorgelegt.</p>
        <?php if (isset($_GET['pp_saved'])) echo '<div class="notice notice-success"><p>Gespeichert.</p></div>'; ?>

        <table class="widefat striped">
            <thead><tr><th>Beschluss</th><th>Typ</th><th>Von Gremium</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $b) : ?>
                <tr>
                    <td><?php echo esc_html($b->beschreibung); ?></td>
                    <td><?php echo esc_html($typ_labels[$b->beschluss_typ] ?? $b->beschluss_typ); ?></td>
                    <td><?php echo esc_html($b->gremium_name ?: '–'); ?></td>
                    <td>
                        <?php
                        echo $b->status === 'offen' ? '🟡 Offen' : ($b->status === 'bestaetigt' ? '✅ Bestätigt' : '❌ Revidiert');
                        ?>
                    </td>
                    <td>
                        <?php if ($b->status === 'offen' && pp_can_lead()) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                <?php wp_nonce_field('pp_bestaetigung_entscheiden'); ?>
                                <input type="hidden" name="action" value="pp_bestaetigung_entscheiden">
                                <input type="hidden" name="id" value="<?php echo esc_attr($b->id); ?>">
                                <input type="hidden" name="entscheidung" value="bestaetigt">
                                <button type="submit" class="button button-primary">MV bestätigt</button>
                            </form>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                <?php wp_nonce_field('pp_bestaetigung_entscheiden'); ?>
                                <input type="hidden" name="action" value="pp_bestaetigung_entscheiden">
                                <input type="hidden" name="id" value="<?php echo esc_attr($b->id); ?>">
                                <input type="hidden" name="entscheidung" value="revidiert">
                                <button type="submit" class="button">MV revidiert</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)) : ?><tr><td colspan="5">Keine Bestätigungen vorhanden.</td></tr><?php endif; ?>
            </tbody>
        </table>
        <p class="description">Tipp: Diese Liste lässt sich auf der nächsten MV als eigener TOP „Bestätigung Leitungskreis-Beschlüsse" durchgehen; die Entscheidung hier wird nicht automatisch ins MV-Protokoll übernommen (V1) — bei Bedarf manuell im Protokoll vermerken.</p>
    </div>
    <?php
}
