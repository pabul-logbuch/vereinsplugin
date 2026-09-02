<?php
defined('ABSPATH') || exit;

add_action('admin_menu', function() {
    add_menu_page(
        'JuFo Buchhaltung', 'Buchhaltung', 'jb_view_auslagen',
        'jb_kassenbericht', 'jb_page_kassenbericht',
        'dashicons-chart-bar', 30
    );
    add_submenu_page('jb_kassenbericht', 'Kassenbericht',   'Kassenbericht',    'jb_view_auslagen',    'jb_kassenbericht',  'jb_page_kassenbericht');
    add_submenu_page('jb_kassenbericht', 'Auslagen',        'Auslagen',         'jb_view_auslagen',    'jb_auslagen',       'jb_page_auslagen');
    add_submenu_page('jb_kassenbericht', 'Budgets & Rücklagen','Budgets & Rücklagen','jb_view_journal', 'jb_budgets',       'jb_page_budgets');
    add_submenu_page('jb_kassenbericht', 'Getränke',        'Getränke',         'jb_view_journal',     'jb_getraenke',      'jb_page_getraenke');
    add_submenu_page('jb_kassenbericht', 'Buchungsjournal', 'Buchungsjournal',  'jb_view_journal',     'jb_journal',        'jb_page_journal');
    add_submenu_page('jb_kassenbericht', 'Export',          'Export',           'jb_export',           'jb_export',         'jb_page_export');
    add_submenu_page('jb_kassenbericht', 'Einstellungen',   'Einstellungen',    'jb_manage_settings',  'jb_settings',       'jb_page_settings');
});

function jb_page_kassenbericht() {
    if (isset($_POST['jb_update_kontostand'])) {
        check_admin_referer('jb_update_kontostand');
        if (jb_is_kassier()) {
            update_option('jb_kontostand_bank',  (float)str_replace(',','.', $_POST['bank']  ?? 0));
            update_option('jb_kontostand_kasse', (float)str_replace(',','.', $_POST['kasse'] ?? 0));
        }
    }
    if (isset($_GET['updated'])) {
        echo '<div class="notice notice-success is-dismissible"><p>Kontostand aktualisiert.</p></div>';
    }
    include JB_PATH . 'templates/admin/kassenbericht-dashboard.php';
}
function jb_page_auslagen() {
    $status   = sanitize_text_field($_GET['status'] ?? '');
    $year     = (int)($_GET['year'] ?? date('Y'));
    $auslagen = jb_get_auslagen(array_filter(['status' => $status, 'year' => $year]));
    include JB_PATH . 'templates/admin/auslagen.php';
}
function jb_page_budgets()    { include JB_PATH . 'templates/admin/budgets.php'; }
function jb_page_getraenke()  {
    $produkte = jb_getraenke_get_all();
    include JB_PATH . 'templates/admin/getraenke.php';
}
function jb_page_journal() {
    $year    = (int)($_GET['year'] ?? date('Y'));
    $entries = jb_journal_get(['year' => $year]);
    $summary = jb_journal_summary($year);
    include JB_PATH . 'templates/admin/journal.php';
}
function jb_page_export() {
    $year = (int)($_GET['year'] ?? date('Y'));
    include JB_PATH . 'templates/admin/export.php';
}
function jb_page_settings() {
    settings_errors('jb_settings');
    include JB_PATH . 'templates/admin/settings.php';
}

