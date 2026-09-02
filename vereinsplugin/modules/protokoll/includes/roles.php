<?php
defined('ABSPATH') || exit;

/**
 * Synergie mit der Vereins-Wunschliste (wunschliste-plugin):
 * Statt einer eigenen Mitgliederverwaltung nutzt ProtokollPro dieselben
 * WordPress-User, die dort per CSV/XML-Import angelegt werden (Rolle
 * "wl_mitglied"). Ist die Wunschliste aktiv, bekommt deren Mitglieder-Rolle
 * automatisch auch die ProtokollPro-Capability. Ist sie nicht aktiv, legt
 * ProtokollPro ersatzweise eine eigene, gleichwertige Rolle an, damit das
 * Plugin auch eigenständig funktioniert.
 */
function pp_create_roles() {
    // Capability für alle relevanten Standard-Rollen
    foreach (['administrator', 'editor'] as $role_name) {
        $role = get_role($role_name);
        if ($role) {
            $role->add_cap('pp_manage');
        }
    }

    // Synergie: Wunschliste-Mitgliedsrolle, falls vorhanden, ebenfalls berechtigen
    $wl_role = get_role('wl_mitglied');
    if ($wl_role) {
        $wl_role->add_cap('pp_manage');
    } else {
        // Fallback: eigene Mitglied-Rolle, falls die Wunschliste (noch) nicht aktiv ist
        add_role('pp_mitglied', 'Vereinsmitglied (ProtokollPro)', [
            'read'      => true,
            'pp_manage' => true,
        ]);
    }
}

/**
 * Wird aufgerufen, wenn die Wunschliste erst NACH ProtokollPro aktiviert wird
 * (oder ihre Rolle sich ändert) – stellt sicher, dass die Capability
 * nachträglich ergänzt wird, ohne dass ProtokollPro neu aktiviert werden muss.
 */
add_action('admin_init', 'pp_sync_wunschliste_role');
function pp_sync_wunschliste_role() {
    static $done = false;
    if ($done) return;
    $done = true;

    $wl_role = get_role('wl_mitglied');
    if ($wl_role && !$wl_role->has_cap('pp_manage')) {
        $wl_role->add_cap('pp_manage');
    }
}
