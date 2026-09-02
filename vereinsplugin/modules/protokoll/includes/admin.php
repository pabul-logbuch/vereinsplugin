<?php
defined('ABSPATH') || exit;

add_action('admin_menu', 'pp_register_admin_menu');
function pp_register_admin_menu() {
    add_menu_page(
        'ProtokollPro', 'ProtokollPro', 'pp_manage', 'protokollpro',
        'pp_render_dashboard_page', 'dashicons-clipboard', 30
    );
    add_submenu_page('protokollpro', 'Dashboard', 'Dashboard', 'pp_manage', 'protokollpro', 'pp_render_dashboard_page');
    add_submenu_page('protokollpro', 'Gremien', 'Gremien', 'pp_manage', 'pp-gremien', 'pp_render_gremien_page');
    add_submenu_page('protokollpro', 'Protokolle', 'Protokolle', 'pp_manage', 'pp-protokolle', 'pp_render_protokolle_page');
    add_submenu_page('protokollpro', 'Themenspeicher', 'Themenspeicher', 'pp_manage', 'pp-themen', 'pp_render_themen_page');
    add_submenu_page('protokollpro', 'Aufgaben & Termine', 'Aufgaben & Termine', 'pp_manage', 'pp-aufgaben-termine', 'pp_render_aufgaben_termine_page');
    add_submenu_page('protokollpro', 'Bestätigungen (Leitungskreis → MV)', 'Bestätigungen', 'pp_manage', 'pp-bestaetigungen', 'pp_render_bestaetigungen_page');
    add_submenu_page('protokollpro', 'Freigaben (Vier-Augen)', 'Freigaben', 'pp_manage', 'pp-freigaben', 'pp_render_freigaben_page');
}

function pp_render_dashboard_page() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    global $wpdb;
    $offene_bestaetigungen = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pp_bestaetigungen WHERE status = 'offen'");
    $offene_freigaben      = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pp_freigaben WHERE status = 'offen'");
    $offene_aufgaben       = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pp_aufgaben WHERE status = 'offen'");
    $entwuerfe              = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pp_protokolle WHERE status = 'entwurf'");
    ?>
    <div class="wrap pp-wrap">
        <h1>ProtokollPro</h1>
        <p>Digitale Sitzungsverwaltung für das Jugendforum Riedlingen e.V. — Gremien, Protokolle mit Konsent-Workflow, Themenspeicher, Aufgaben und Termine.</p>

        <div class="pp-dashboard-cards">
            <a href="<?php echo esc_url(admin_url('admin.php?page=pp-protokolle&status=entwurf')); ?>" class="pp-card">
                <span class="pp-card-num"><?php echo intval($entwuerfe); ?></span>
                <span class="pp-card-label">Protokolle im Entwurf</span>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=pp-bestaetigungen')); ?>" class="pp-card">
                <span class="pp-card-num"><?php echo intval($offene_bestaetigungen); ?></span>
                <span class="pp-card-label">Offene Bestätigungen für die MV</span>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=pp-freigaben')); ?>" class="pp-card">
                <span class="pp-card-num"><?php echo intval($offene_freigaben); ?></span>
                <span class="pp-card-label">Offene Freigaben</span>
            </a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=pp-aufgaben-termine')); ?>" class="pp-card">
                <span class="pp-card-num"><?php echo intval($offene_aufgaben); ?></span>
                <span class="pp-card-label">Offene Aufgaben</span>
            </a>
        </div>

        <?php if (empty(pp_get_gremien())) : ?>
            <div class="pp-hint">
                <strong>Erster Schritt:</strong> Legt unter <a href="<?php echo esc_url(admin_url('admin.php?page=pp-gremien')); ?>">Gremien</a>
                eure Mitgliederversammlung, den Vorstand, den Leitungskreis und eure Kreise an – erst danach lassen sich Protokolle erstellen.
            </div>
        <?php endif; ?>
    </div>
    <?php
}
