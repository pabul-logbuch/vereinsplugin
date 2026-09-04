<?php
defined('ABSPATH') || exit;

// ─── FORM-HANDLER (admin-post.php) ─────────────────────────────────────────

add_action('admin_post_pp_save_gremium', 'pp_handle_save_gremium');
function pp_handle_save_gremium() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_save_gremium');

    global $wpdb;
    $table = $wpdb->prefix . 'pp_gremien';

    $id   = intval($_POST['id'] ?? 0);
    $data = [
        'typ'                  => in_array($_POST['typ'] ?? '', ['mv','vorstand','leitungskreis','kreis','kreisversammlung']) ? $_POST['typ'] : 'kreis',
        'name'                 => sanitize_text_field($_POST['name'] ?? ''),
        'parent_gremium_id'    => !empty($_POST['parent_gremium_id']) ? intval($_POST['parent_gremium_id']) : null,
        'oeffentlichkeit'      => in_array($_POST['oeffentlichkeit'] ?? '', ['oeffentlich','vereinsintern','nur_gremium']) ? $_POST['oeffentlichkeit'] : 'vereinsintern',
        'standardverfahren'    => array_key_exists($_POST['standardverfahren'] ?? '', pp_verfahren_liste()) ? $_POST['standardverfahren'] : 'konsent',
        'einladungsfrist_tage' => intval($_POST['einladungsfrist_tage'] ?? 14),
        'beschreibung'         => sanitize_textarea_field($_POST['beschreibung'] ?? ''),
        'aktiv'                => isset($_POST['aktiv']) ? 1 : 0,
    ];

    if (empty($data['name'])) {
        wp_safe_redirect(admin_url('admin.php?page=pp-gremien&pp_error=Name+fehlt'));
        exit;
    }

    if ($id > 0) {
        $wpdb->update($table, $data, ['id' => $id]);
    } else {
        $data['erstellt_von'] = get_current_user_id();
        $wpdb->insert($table, $data);
        $id = $wpdb->insert_id;
    }

    wp_safe_redirect(admin_url('admin.php?page=pp-gremien&pp_saved=1&edit=' . $id));
    exit;
}

add_action('admin_post_pp_delete_gremium', 'pp_handle_delete_gremium');
function pp_handle_delete_gremium() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_delete_gremium');
    global $wpdb;
    $id = intval($_GET['id'] ?? 0);
    $wpdb->delete($wpdb->prefix . 'pp_gremien', ['id' => $id]);
    wp_safe_redirect(admin_url('admin.php?page=pp-gremien&pp_deleted=1'));
    exit;
}

add_action('admin_post_pp_save_rolle', 'pp_handle_save_rolle');
function pp_handle_save_rolle() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_save_rolle');

    global $wpdb;
    $gremium_id = intval($_POST['gremium_id'] ?? 0);

    $data = [
        'gremium_id'            => $gremium_id,
        'rollenvorlage_id'      => !empty($_POST['rollenvorlage_id']) ? intval($_POST['rollenvorlage_id']) : null,
        'bezeichnung'           => sanitize_text_field($_POST['bezeichnung'] ?? ''),
        'user_id'               => !empty($_POST['user_id']) ? intval($_POST['user_id']) : null,
        'vertretungsberechtigt' => isset($_POST['vertretungsberechtigt']) ? 1 : 0,
        'amtszeit_start'        => !empty($_POST['amtszeit_start']) ? sanitize_text_field($_POST['amtszeit_start']) : null,
        'amtszeit_ende'         => !empty($_POST['amtszeit_ende']) ? sanitize_text_field($_POST['amtszeit_ende']) : null,
        'wahl_gruppe'           => sanitize_text_field($_POST['wahl_gruppe'] ?? ''),
    ];

    if (!empty($data['bezeichnung'])) {
        $wpdb->insert($wpdb->prefix . 'pp_rollen', $data);
    }

    wp_safe_redirect(admin_url('admin.php?page=pp-gremien&edit=' . $gremium_id . '&pp_saved=1'));
    exit;
}

add_action('admin_post_pp_delete_rolle', 'pp_handle_delete_rolle');
function pp_handle_delete_rolle() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_delete_rolle');
    global $wpdb;
    $id = intval($_GET['id'] ?? 0);
    $gremium_id = intval($_GET['gremium_id'] ?? 0);
    $wpdb->delete($wpdb->prefix . 'pp_rollen', ['id' => $id]);
    wp_safe_redirect(admin_url('admin.php?page=pp-gremien&edit=' . $gremium_id . '&pp_deleted=1'));
    exit;
}

// ─── SEITE ───────────────────────────────────────────────────────────────

function pp_render_gremien_page() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    global $wpdb;

    $edit_id = intval($_GET['edit'] ?? 0);
    $editing = $edit_id ? pp_get_gremium($edit_id) : null;
    $alle_gremien = pp_get_gremien(null, false);
    ?>
    <div class="wrap pp-wrap">
        <h1>Gremien</h1>
        <p class="description">MV, Vorstand, Leitungskreis und Kreise (inkl. Beirat) als eigene Gremien anlegen. Rollen (Sprecher:in, Kassier:in, Kreisleitung …) werden je Gremium unten verwaltet.</p>

        <?php if (isset($_GET['pp_saved'])) echo '<div class="notice notice-success"><p>Gespeichert.</p></div>'; ?>
        <?php if (isset($_GET['pp_error'])) echo '<div class="notice notice-error"><p>' . esc_html(str_replace('+', ' ', $_GET['pp_error'])) . '</p></div>'; ?>

        <div class="pp-columns">
            <div class="pp-col-list">
                <table class="widefat striped">
                    <thead><tr><th>Name</th><th>Typ</th><th>Öffentlichkeit</th><th>Verfahren</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($alle_gremien as $g) : ?>
                        <tr class="<?php echo $g->id == $edit_id ? 'pp-row-active' : ''; ?>">
                            <td><a href="<?php echo esc_url(admin_url('admin.php?page=pp-gremien&edit=' . $g->id)); ?>"><?php echo esc_html($g->name); ?></a><?php echo $g->aktiv ? '' : ' <em>(inaktiv)</em>'; ?></td>
                            <td><?php echo esc_html(pp_gremientyp_label($g->typ)); ?></td>
                            <td><?php echo esc_html(pp_oeffentlichkeit_label($g->oeffentlichkeit)); ?></td>
                            <td><?php echo esc_html(pp_verfahren_label($g->standardverfahren)); ?></td>
                            <td>
                                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=pp_delete_gremium&id=' . $g->id), 'pp_delete_gremium')); ?>"
                                   onclick="return confirm('Gremium wirklich löschen?');" class="pp-link-danger">Löschen</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($alle_gremien)) : ?>
                        <tr><td colspan="5">Noch keine Gremien angelegt.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="pp-col-form">
                <h2><?php echo $editing ? 'Gremium bearbeiten' : 'Neues Gremium'; ?></h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('pp_save_gremium'); ?>
                    <input type="hidden" name="action" value="pp_save_gremium">
                    <input type="hidden" name="id" value="<?php echo esc_attr($editing->id ?? 0); ?>">

                    <table class="form-table">
                        <tr>
                            <th><label>Name</label></th>
                            <td><input type="text" name="name" required class="regular-text" value="<?php echo esc_attr($editing->name ?? ''); ?>"></td>
                        </tr>
                        <tr>
                            <th><label>Typ</label></th>
                            <td>
                                <select name="typ">
                                    <?php foreach (['mv','vorstand','leitungskreis','kreis','kreisversammlung'] as $t) : ?>
                                        <option value="<?php echo esc_attr($t); ?>" <?php selected($editing->typ ?? '', $t); ?>><?php echo esc_html(pp_gremientyp_label($t)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Übergeordnetes Gremium</label></th>
                            <td>
                                <select name="parent_gremium_id">
                                    <option value="">— keins —</option>
                                    <?php foreach ($alle_gremien as $g) : if ($editing && $g->id == $editing->id) continue; ?>
                                        <option value="<?php echo esc_attr($g->id); ?>" <?php selected($editing->parent_gremium_id ?? '', $g->id); ?>><?php echo esc_html($g->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">z. B. Kreisversammlung → gehört zu einem Kreis.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Öffentlichkeit der Protokolle</label></th>
                            <td>
                                <select name="oeffentlichkeit">
                                    <?php foreach (['oeffentlich','vereinsintern','nur_gremium'] as $o) : ?>
                                        <option value="<?php echo esc_attr($o); ?>" <?php selected($editing->oeffentlichkeit ?? 'vereinsintern', $o); ?>><?php echo esc_html(pp_oeffentlichkeit_label($o)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Standard-Entscheidungsverfahren</label></th>
                            <td>
                                <select name="standardverfahren">
                                    <?php foreach (array_keys(pp_verfahren_liste()) as $v) : if ($v === 'mehrheit') continue; ?>
                                        <option value="<?php echo esc_attr($v); ?>" <?php selected($editing->standardverfahren ?? 'konsent', $v); ?>><?php echo esc_html(pp_verfahren_label($v)); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Kann pro TOP im Protokoll überschrieben werden.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Einladungsfrist (Tage)</label></th>
                            <td><input type="number" name="einladungsfrist_tage" value="<?php echo esc_attr($editing->einladungsfrist_tage ?? 14); ?>" min="0" style="width:80px;"></td>
                        </tr>
                        <tr>
                            <th><label>Beschreibung</label></th>
                            <td><textarea name="beschreibung" rows="3" class="large-text"><?php echo esc_textarea($editing->beschreibung ?? ''); ?></textarea></td>
                        </tr>
                        <tr>
                            <th><label>Aktiv</label></th>
                            <td><label><input type="checkbox" name="aktiv" <?php checked(($editing->aktiv ?? 1), 1); ?>> Gremium ist aktiv</label></td>
                        </tr>
                    </table>
                    <p><button type="submit" class="button button-primary">Speichern</button></p>
                </form>

                <?php if ($editing) : ?>
                    <div class="pp-hint" style="margin-top:16px;">
                        <strong>Shortcode für den öffentlichen Steckbrief dieses Gremiums:</strong>
                        <code>[protokollpro_kreis id="<?php echo esc_html($editing->id); ?>"]</code>
                    </div>
                    <?php pp_render_rollenvorlagen_section($editing); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

