<?php
defined('ABSPATH') || exit;

// [jb_auslage_einreichen]
add_shortcode('jb_auslage_einreichen', function() {
    if (!is_user_logged_in()) {
        return '<div class="jb-box jb-warn">Bitte erst <a href="' . wp_login_url(get_permalink()) . '">einloggen</a>.</div>';
    }
    if (!jb_can_submit()) {
        return '<div class="jb-box jb-warn">Keine Berechtigung zum Einreichen von Auslagen.</div>';
    }
    ob_start();
    include JB_PATH . 'templates/auslagen-einreichen.php';
    return ob_get_clean();
});

// [jb_meine_auslagen]
add_shortcode('jb_meine_auslagen', function() {
    if (!is_user_logged_in()) {
        return '<div class="jb-box jb-warn">Bitte erst <a href="' . wp_login_url(get_permalink()) . '">einloggen</a>.</div>';
    }
    ob_start();
    include JB_PATH . 'templates/meine-auslagen.php';
    return ob_get_clean();
});

// [jb_kassenbericht]
add_shortcode('jb_kassenbericht', function() {
    if (!jb_can_journal()) {
        return '<div class="jb-box jb-warn">Keine Berechtigung.</div>';
    }
    ob_start();
    include JB_PATH . 'templates/kassenbericht.php';
    return ob_get_clean();
});
