<?php
defined('ABSPATH') || exit;

add_action('admin_post_pp_save_thema', 'pp_handle_save_thema');
function pp_handle_save_thema() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_save_thema');
    global $wpdb;

    $id = intval($_POST['id'] ?? 0);
    $data = [
        'titel'        => sanitize_text_field($_POST['titel'] ?? ''),
        'beschreibung' => sanitize_textarea_field($_POST['beschreibung'] ?? ''),
        'svo_teil'     => in_array($_POST['svo_teil'] ?? '', ['A','B','C']) ? $_POST['svo_teil'] : '',
        'status'       => in_array($_POST['status'] ?? '', ['vorbereitet','in_bearbeitung','abgeschlossen','evaluationsreif']) ? $_POST['status'] : 'vorbereitet',
        'gremium_id'   => !empty($_POST['gremium_id']) ? intval($_POST['gremium_id']) : null,
    ];

    if (empty($data['titel'])) {
        wp_safe_redirect(admin_url('admin.php?page=pp-themen&pp_error=Titel+fehlt'));
        exit;
    }

    if ($id > 0) {
        $wpdb->update($wpdb->prefix . 'pp_themen', $data, ['id' => $id]);
    } else {
        $data['erstellt_von'] = get_current_user_id();
        $wpdb->insert($wpdb->prefix . 'pp_themen', $data);
    }

    wp_safe_redirect(admin_url('admin.php?page=pp-themen&pp_saved=1'));
    exit;
}

add_action('admin_post_pp_delete_thema', 'pp_handle_delete_thema');
function pp_handle_delete_thema() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_delete_thema');
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'pp_themen', ['id' => intval($_GET['id'] ?? 0)]);
    wp_safe_redirect(admin_url('admin.php?page=pp-themen&pp_deleted=1'));
    exit;
}

function pp_render_themen_page() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    global $wpdb;
    $edit_id = intval($_GET['edit'] ?? 0);
    $editing = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pp_themen WHERE id=%d", $edit_id)) : null;
    $themen = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pp_themen ORDER BY status, titel");
    $gremien = pp_get_gremien();
    $status_labels = ['vorbereitet' => 'Vorbereitet', 'in_bearbeitung' => 'In Bearbeitung', 'abgeschlossen' => 'Abgeschlossen', 'evaluationsreif' => 'Evaluationsreif'];
    ?>
    <div class="wrap pp-wrap">
        <h1>Themenspeicher</h1>
        <p class="description">Zentrales Repository für zu behandelnde Themen. Aus einem Protokoll heraus lässt sich ein TOP mit einem Thema verknüpfen; bei Beschluss wird das Thema automatisch auf „abgeschlossen" gesetzt.</p>
        <?php if (isset($_GET['pp_saved'])) echo '<div class="notice notice-success"><p>Gespeichert.</p></div>'; ?>

        <div class="pp-columns">
            <div class="pp-col-list">
                <table class="widefat striped">
                    <thead><tr><th>Titel</th><th>Status</th><th>SVO-Teil</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($themen as $t) : ?>
                        <tr>
                            <td><a href="<?php echo esc_url(admin_url('admin.php?page=pp-themen&edit=' . $t->id)); ?>"><?php echo esc_html($t->titel); ?></a></td>
                            <td><?php echo esc_html($status_labels[$t->status] ?? $t->status); ?></td>
                            <td><?php echo esc_html($t->svo_teil ?: '–'); ?></td>
                            <td><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=pp_delete_thema&id=' . $t->id), 'pp_delete_thema')); ?>" onclick="return confirm('Löschen?');" class="pp-link-danger">Löschen</a></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($themen)) : ?><tr><td colspan="4">Noch keine Themen.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="pp-col-form">
                <h2><?php echo $editing ? 'Thema bearbeiten' : 'Neues Thema'; ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('pp_save_thema'); ?>
                    <input type="hidden" name="action" value="pp_save_thema">
                    <input type="hidden" name="id" value="<?php echo esc_attr($editing->id ?? 0); ?>">
                    <table class="form-table">
                        <tr><th>Titel</th><td><input type="text" name="titel" required class="regular-text" value="<?php echo esc_attr($editing->titel ?? ''); ?>"></td></tr>
                        <tr><th>Beschreibung</th><td><textarea name="beschreibung" rows="3" class="large-text"><?php echo esc_textarea($editing->beschreibung ?? ''); ?></textarea></td></tr>
                        <tr>
                            <th>Betrifft SVO-Teil</th>
                            <td>
                                <select name="svo_teil">
                                    <option value="">— kein Bezug —</option>
                                    <option value="A" <?php selected($editing->svo_teil ?? '', 'A'); ?>>Teil A (nur MV)</option>
                                    <option value="B" <?php selected($editing->svo_teil ?? '', 'B'); ?>>Teil B (Kreisordnungen)</option>
                                    <option value="C" <?php selected($editing->svo_teil ?? '', 'C'); ?>>Teil C (kreisintern)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Status</th>
                            <td>
                                <select name="status">
                                    <?php foreach ($status_labels as $k => $l) : ?>
                                        <option value="<?php echo esc_attr($k); ?>" <?php selected($editing->status ?? 'vorbereitet', $k); ?>><?php echo esc_html($l); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Zuständiges Gremium</th>
                            <td>
                                <select name="gremium_id">
                                    <option value="">— keins —</option>
                                    <?php foreach ($gremien as $g) : ?>
                                        <option value="<?php echo esc_attr($g->id); ?>" <?php selected($editing->gremium_id ?? '', $g->id); ?>><?php echo esc_html($g->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <p><button type="submit" class="button button-primary">Speichern</button></p>
                </form>
            </div>
        </div>
    </div>
    <?php
}
