<?php
defined('ABSPATH') || exit;

function jb_get_setting(string $key, string $default = ''): string {
    return get_option('jb_' . $key, $default);
}

function jb_save_settings(array $data): void {
    $fields = ['nc_url','nc_user','nc_password','nc_folder','kassier_email','beleg_pflicht'];
    foreach ($fields as $f) {
        if (isset($data[$f])) {
            update_option('jb_' . $f, sanitize_text_field($data[$f]));
        }
    }
}

add_action('admin_init', function() {
    if (isset($_POST['jb_save_settings']) && current_user_can('jb_manage_settings')) {
        check_admin_referer('jb_settings');
        jb_save_settings($_POST);
        add_settings_error('jb_settings', 'saved', 'Einstellungen gespeichert.', 'success');
    }
});
