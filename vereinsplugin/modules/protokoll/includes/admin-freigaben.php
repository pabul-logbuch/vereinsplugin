<?php
defined('ABSPATH') || exit;

add_action('admin_post_pp_save_freigabe', 'pp_handle_save_freigabe');
function pp_handle_save_freigabe() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_save_freigabe');
    global $wpdb;
    $betrifft_kreis = !empty($_POST['betrifft_kreis_id']) ? intval($_POST['betrifft_kreis_id']) : null;
    $wpdb->insert($wpdb->prefix . 'pp_freigaben', [
        'beschreibung'      => sanitize_text_field($_POST['beschreibung'] ?? ''),
        'betrag'            => floatval($_POST['betrag'] ?? 0),
        'betrifft_kreis_id' => $betrifft_kreis,
        'kreisversammlung_konsent_status' => $betrifft_kreis ? 'ausstehend' : 'nicht_erforderlich',
        'erstellt_von'      => get_current_user_id(),
    ]);
    wp_safe_redirect(admin_url('admin.php?page=pp-freigaben&pp_saved=1'));
    exit;
}

add_action('admin_post_pp_freigabe_geben', 'pp_handle_freigabe_geben');
function pp_handle_freigabe_geben() {
    if (!pp_can_lead()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_freigabe_geben');
    global $wpdb;
    $id = intval($_POST['id'] ?? 0);
    $slot = ($_POST['slot'] ?? '') === '2' ? 2 : 1;
    $table = $wpdb->prefix . 'pp_freigaben';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id));

    if ($row) {
        $uid = get_current_user_id();
        // Vier-Augen-Prinzip: dieselbe Person darf nicht beide Freigaben geben.
        $andere_freigabe = $slot === 1 ? $row->freigabe2_user_id : $row->freigabe1_user_id;
        if ($andere_freigabe && intval($andere_freigabe) === $uid) {
            wp_safe_redirect(admin_url('admin.php?page=pp-freigaben&pp_error=Vier-Augen-Prinzip:+dieselbe+Person+kann+nicht+beide+Freigaben+geben'));
            exit;
        }

        $field_user = 'freigabe' . $slot . '_user_id';
        $field_am   = 'freigabe' . $slot . '_am';
        $wpdb->update($table, [$field_user => $uid, $field_am => current_time('mysql')], ['id' => $id]);

        pp_maybe_complete_freigabe($id);
    }

    wp_safe_redirect(admin_url('admin.php?page=pp-freigaben&pp_saved=1'));
    exit;
}

add_action('admin_post_pp_kreisversammlung_konsent', 'pp_handle_kreisversammlung_konsent');
function pp_handle_kreisversammlung_konsent() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_kreisversammlung_konsent');
    global $wpdb;
    $id = intval($_POST['id'] ?? 0);
    $wpdb->update($wpdb->prefix . 'pp_freigaben', ['kreisversammlung_konsent_status' => 'erteilt'], ['id' => $id]);
    pp_maybe_complete_freigabe($id);
    wp_safe_redirect(admin_url('admin.php?page=pp-freigaben&pp_saved=1'));
    exit;
}

function pp_maybe_complete_freigabe($id) {
    global $wpdb;
    $table = $wpdb->prefix . 'pp_freigaben';
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d", $id));
    if (!$row) return;
    $zwei_freigaben = $row->freigabe1_user_id && $row->freigabe2_user_id;
    $kv_ok = $row->kreisversammlung_konsent_status !== 'ausstehend';
    if ($zwei_freigaben && $kv_ok) {
        $wpdb->update($table, ['status' => 'freigegeben'], ['id' => $id]);
    }
}

function pp_render_freigaben_page() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    global $wpdb;
    $rows = $wpdb->get_results("
        SELECT f.*, g.name AS kreis_name
        FROM {$wpdb->prefix}pp_freigaben f
        LEFT JOIN {$wpdb->prefix}pp_gremien g ON g.id = f.betrifft_kreis_id
        ORDER BY f.status = 'offen' DESC, f.erstellt_am DESC
    ");
    $kreise = pp_get_gremien('kreis');
    ?>
    <div class="wrap pp-wrap">
        <h1>Freigaben (Vier-Augen-Prinzip)</h1>
        <p class="description">Rechtsgeschäfte oberhalb der in der Selbstverwaltungsordnung festgelegten Betragsgrenze benötigen die Freigabe durch zwei Vorstandsmitglieder (§9 Satzung); betrifft die Ausgabe einen Kreis, zusätzlich den vorherigen Konsent der Kreisversammlung.</p>
        <?php if (isset($_GET['pp_saved'])) echo '<div class="notice notice-success"><p>Gespeichert.</p></div>'; ?>
        <?php if (isset($_GET['pp_error'])) echo '<div class="notice notice-error"><p>' . esc_html(str_replace('+', ' ', $_GET['pp_error'])) . '</p></div>'; ?>

        <table class="widefat striped">
            <thead><tr><th>Beschreibung</th><th>Betrag</th><th>Kreis</th><th>Freigabe 1</th><th>Freigabe 2</th><th>KV-Konsent</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $f) : ?>
                <tr>
                    <td><?php echo esc_html($f->beschreibung); ?></td>
                    <td><?php echo number_format((float) $f->betrag, 2, ',', '.'); ?> €</td>
                    <td><?php echo esc_html($f->kreis_name ?: '–'); ?></td>
                    <td><?php echo $f->freigabe1_user_id ? esc_html(pp_user_display_name($f->freigabe1_user_id)) : '–'; ?></td>
                    <td><?php echo $f->freigabe2_user_id ? esc_html(pp_user_display_name($f->freigabe2_user_id)) : '–'; ?></td>
                    <td>
                        <?php
                        $kv = $f->kreisversammlung_konsent_status;
                        echo $kv === 'nicht_erforderlich' ? '– nicht nötig' : ($kv === 'erteilt' ? '✅ erteilt' : '🟡 ausstehend');
                        ?>
                    </td>
                    <td><?php echo $f->status === 'freigegeben' ? '✅ Freigegeben' : '🟡 Offen'; ?></td>
                    <td>
                        <?php if ($f->status === 'offen' && pp_can_lead()) : ?>
                            <?php if (!$f->freigabe1_user_id) : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                    <?php wp_nonce_field('pp_freigabe_geben'); ?>
                                    <input type="hidden" name="action" value="pp_freigabe_geben">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($f->id); ?>">
                                    <input type="hidden" name="slot" value="1">
                                    <button type="submit" class="button">Freigabe 1 geben</button>
                                </form>
                            <?php endif; ?>
                            <?php if (!$f->freigabe2_user_id) : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                    <?php wp_nonce_field('pp_freigabe_geben'); ?>
                                    <input type="hidden" name="action" value="pp_freigabe_geben">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($f->id); ?>">
                                    <input type="hidden" name="slot" value="2">
                                    <button type="submit" class="button">Freigabe 2 geben</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($f->kreisversammlung_konsent_status === 'ausstehend') : ?>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                                    <?php wp_nonce_field('pp_kreisversammlung_konsent'); ?>
                                    <input type="hidden" name="action" value="pp_kreisversammlung_konsent">
                                    <input type="hidden" name="id" value="<?php echo esc_attr($f->id); ?>">
                                    <button type="submit" class="button">KV-Konsent erteilt</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)) : ?><tr><td colspan="8">Keine Freigaben vorhanden.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <h2>Neue Freigabe anfordern</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline-form">
            <?php wp_nonce_field('pp_save_freigabe'); ?>
            <input type="hidden" name="action" value="pp_save_freigabe">
            <input type="text" name="beschreibung" placeholder="Beschreibung" required>
            <input type="number" step="0.01" name="betrag" placeholder="Betrag in €">
            <select name="betrifft_kreis_id">
                <option value="">Betrifft keinen einzelnen Kreis</option>
                <?php foreach ($kreise as $k) : ?>
                    <option value="<?php echo esc_attr($k->id); ?>"><?php echo esc_html($k->name); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button button-primary">Anlegen</button>
        </form>
    </div>
    <?php
}
