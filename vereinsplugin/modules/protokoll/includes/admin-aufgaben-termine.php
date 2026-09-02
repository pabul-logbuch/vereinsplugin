<?php
defined('ABSPATH') || exit;

add_action('admin_post_pp_save_aufgabe', 'pp_handle_save_aufgabe');
function pp_handle_save_aufgabe() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_save_aufgabe');
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'pp_aufgaben', [
        'titel'                  => sanitize_text_field($_POST['titel'] ?? ''),
        'beschreibung'           => sanitize_textarea_field($_POST['beschreibung'] ?? ''),
        'verantwortlich_user_id' => !empty($_POST['verantwortlich_user_id']) ? intval($_POST['verantwortlich_user_id']) : null,
        'faelligkeitsdatum'      => !empty($_POST['faelligkeitsdatum']) ? sanitize_text_field($_POST['faelligkeitsdatum']) : null,
    ]);
    wp_safe_redirect(admin_url('admin.php?page=pp-aufgaben-termine&pp_saved=1'));
    exit;
}

add_action('admin_post_pp_toggle_aufgabe', 'pp_handle_toggle_aufgabe');
function pp_handle_toggle_aufgabe() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_toggle_aufgabe');
    global $wpdb;
    $id = intval($_GET['id'] ?? 0);
    $current = $wpdb->get_var($wpdb->prepare("SELECT status FROM {$wpdb->prefix}pp_aufgaben WHERE id=%d", $id));
    $wpdb->update($wpdb->prefix . 'pp_aufgaben', ['status' => $current === 'erledigt' ? 'offen' : 'erledigt'], ['id' => $id]);
    wp_safe_redirect(admin_url('admin.php?page=pp-aufgaben-termine'));
    exit;
}

add_action('admin_post_pp_save_termin', 'pp_handle_save_termin');
function pp_handle_save_termin() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_save_termin');
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'pp_termine', [
        'titel'      => sanitize_text_field($_POST['titel'] ?? ''),
        'datum'      => !empty($_POST['datum']) ? sanitize_text_field($_POST['datum']) : null,
        'ort'        => sanitize_text_field($_POST['ort'] ?? ''),
        'gremium_id' => !empty($_POST['gremium_id']) ? intval($_POST['gremium_id']) : null,
    ]);
    wp_safe_redirect(admin_url('admin.php?page=pp-aufgaben-termine&pp_saved=1'));
    exit;
}

function pp_render_aufgaben_termine_page() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    global $wpdb;
    $aufgaben = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}pp_aufgaben ORDER BY status, faelligkeitsdatum IS NULL, faelligkeitsdatum");
    $termine  = $wpdb->get_results("SELECT t.*, g.name AS gremium_name FROM {$wpdb->prefix}pp_termine t LEFT JOIN {$wpdb->prefix}pp_gremien g ON g.id=t.gremium_id ORDER BY t.datum");
    $gremien  = pp_get_gremien();
    ?>
    <div class="wrap pp-wrap">
        <h1>Aufgaben & Termine</h1>
        <?php if (isset($_GET['pp_saved'])) echo '<div class="notice notice-success"><p>Gespeichert.</p></div>'; ?>
        <?php if (isset($_GET['pp_event_aufgaben'])) echo '<div class="notice notice-success"><p>' . intval($_GET['pp_event_aufgaben']) . ' Event-Aufgabe(n) erzeugt.</p></div>'; ?>
        <?php if (isset($_GET['pp_error'])) echo '<div class="notice notice-error"><p>' . esc_html(str_replace('+', ' ', $_GET['pp_error'])) . '</p></div>'; ?>

        <h2>Aufgaben</h2>
        <p class="description">Werden automatisch erzeugt, wenn ein TOP mit „erzeugt Aufgabe" beschlossen wird — oder hier manuell angelegt.</p>
        <table class="widefat striped">
            <thead><tr><th></th><th>Titel</th><th>Verantwortlich</th><th>Fällig</th></tr></thead>
            <tbody>
            <?php foreach ($aufgaben as $a) : ?>
                <tr style="<?php echo $a->status === 'erledigt' ? 'opacity:.55;' : ''; ?>">
                    <td>
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=pp_toggle_aufgabe&id=' . $a->id), 'pp_toggle_aufgabe')); ?>">
                            <?php echo $a->status === 'erledigt' ? '☑️' : '⬜'; ?>
                        </a>
                    </td>
                    <td><?php echo esc_html($a->titel); ?><?php if ($a->quelle_top_id) echo ' <em>(aus Protokoll)</em>'; ?></td>
                    <td><?php echo esc_html(pp_user_display_name($a->verantwortlich_user_id)); ?></td>
                    <td><?php echo esc_html($a->faelligkeitsdatum ?: '–'); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($aufgaben)) : ?><tr><td colspan="4">Keine Aufgaben.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline-form">
            <?php wp_nonce_field('pp_save_aufgabe'); ?>
            <input type="hidden" name="action" value="pp_save_aufgabe">
            <input type="text" name="titel" placeholder="Neue Aufgabe" required>
            <select name="verantwortlich_user_id">
                <option value="">Verantwortlich…</option>
                <?php foreach (pp_get_moegliche_mitglieder() as $u) : ?>
                    <option value="<?php echo esc_attr($u->ID); ?>"><?php echo esc_html($u->display_name); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="faelligkeitsdatum">
            <button type="submit" class="button">Hinzufügen</button>
        </form>

        <hr>
        <h2>Termine</h2>
        <p class="description">Für Termine mit Gremium lassen sich Event-Aufgaben der zugehörigen Rollen erzeugen (z. B. „Wechselgeld bestellen" 2 Wochen vorher bei der Kassier:in-Rolle des betroffenen Kreises).</p>
        <table class="widefat striped">
            <thead><tr><th>Titel</th><th>Datum</th><th>Ort</th><th>Gremium</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($termine as $t) : ?>
                <tr>
                    <td><?php echo esc_html($t->titel); ?><?php if ($t->quelle_top_id) echo ' <em>(aus Protokoll)</em>'; ?></td>
                    <td><?php echo esc_html($t->datum ?: '–'); ?></td>
                    <td><?php echo esc_html($t->ort); ?></td>
                    <td><?php echo esc_html($t->gremium_name ?: '–'); ?></td>
                    <td>
                        <?php if ($t->gremium_id && $t->datum) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <?php wp_nonce_field('pp_generate_event_aufgaben'); ?>
                                <input type="hidden" name="action" value="pp_generate_event_aufgaben">
                                <input type="hidden" name="termin_id" value="<?php echo esc_attr($t->id); ?>">
                                <button type="submit" class="button button-small">Event-Aufgaben erzeugen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($termine)) : ?><tr><td colspan="5">Keine Termine.</td></tr><?php endif; ?>
            </tbody>
        </table>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-inline-form">
            <?php wp_nonce_field('pp_save_termin'); ?>
            <input type="hidden" name="action" value="pp_save_termin">
            <input type="text" name="titel" placeholder="Neuer Termin" required>
            <input type="datetime-local" name="datum">
            <input type="text" name="ort" placeholder="Ort">
            <select name="gremium_id">
                <option value="">Gremium…</option>
                <?php foreach ($gremien as $g) : ?>
                    <option value="<?php echo esc_attr($g->id); ?>"><?php echo esc_html($g->name); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="button">Hinzufügen</button>
        </form>
    </div>
    <?php
}
