<?php
defined('ABSPATH') || exit;

function jb_setup_roles() {
    // Neue Capabilities für Kassier-Funktion
    $kassier_caps = [
        'jb_view_auslagen'    => true,  // Auslagen sehen
        'jb_approve_auslagen' => true,  // Auslagen genehmigen/ablehnen
        'jb_mark_paid'        => true,  // Als ausgezahlt markieren
        'jb_view_journal'     => true,  // Buchungsjournal sehen
        'jb_edit_journal'     => true,  // Buchungsjournal bearbeiten
        'jb_export'           => true,  // EÜR/DATEV exportieren
        'jb_manage_settings'  => true,  // Plugin-Einstellungen
    ];

    $member_caps = [
        'jb_submit_auslagen'  => true,  // Auslage einreichen
        'jb_view_own_auslagen'=> true,  // Eigene Auslagen sehen
    ];

    // wl_mitglied bekommt Member-Caps (falls Rolle schon existiert)
    $mitglied = get_role('wl_mitglied');
    if ($mitglied) {
        foreach ($member_caps as $cap => $grant) {
            $mitglied->add_cap($cap, $grant);
        }
    }

    // Administrator bekommt alles
    $admin = get_role('administrator');
    if ($admin) {
        foreach (array_merge($kassier_caps, $member_caps) as $cap => $grant) {
            $admin->add_cap($cap, $grant);
        }
    }

    // Editor = Vorstand: bekommt Kassier-Rechte
    $editor = get_role('editor');
    if ($editor) {
        foreach (array_merge($kassier_caps, $member_caps) as $cap => $grant) {
            $editor->add_cap($cap, $grant);
        }
    }
}

// Helper functions
function jb_can_submit()  { return current_user_can('jb_submit_auslagen');  }
function jb_can_approve() { return current_user_can('jb_approve_auslagen'); }
function jb_can_export()  { return current_user_can('jb_export');           }
function jb_can_journal() { return current_user_can('jb_view_journal');     }
function jb_is_kassier()  { return current_user_can('jb_approve_auslagen'); }
