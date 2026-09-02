<?php
defined('ABSPATH') || exit;

function wl_create_roles() {
    // Mitglied-Rolle: kann Wünsche verwalten, aber nicht WordPress-Admin
    add_role('wl_mitglied', 'Vereinsmitglied', [
        'read'              => true,
        'wl_manage_wishes'  => true,
    ]);

    // Capability auch für Admins/Editoren hinzufügen
    $admin = get_role('administrator');
    if ($admin) {
        $admin->add_cap('wl_manage_wishes');
    }
    $editor = get_role('editor');
    if ($editor) {
        $editor->add_cap('wl_manage_wishes');
    }
}

function wl_can_manage() {
    return current_user_can('wl_manage_wishes');
}
