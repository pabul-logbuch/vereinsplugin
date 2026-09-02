<?php
defined('ABSPATH') || exit;

add_action('admin_menu', 'wl_admin_menu');
function wl_admin_menu() {
    add_menu_page(
        'Wunschliste',
        'Wunschliste',
        'wl_manage_wishes',
        'wunschliste',
        'wl_admin_page',
        'dashicons-heart',
        30
    );
    add_submenu_page('wunschliste', 'Import', 'CSV/XML Import', 'wl_manage_wishes', 'wunschliste-import', 'wl_import_page');
    add_submenu_page('wunschliste', 'Einstellungen', 'Einstellungen', 'manage_options', 'wunschliste-einstellungen', 'wl_settings_page');
    add_submenu_page('wunschliste', 'Neues Mitglied', 'Mitglied anlegen', 'manage_options', 'wunschliste-mitglied', 'wl_new_member_page');
    add_submenu_page('wunschliste', 'Mitglieder Import', 'Mitglieder Import', 'manage_options', 'wunschliste-mitglieder-import', 'wl_member_import_page');
}

// ─── MITGLIEDER-IMPORT-SEITE ──────────────────────────────────────────────

function wl_member_import_page() {
    require_once WL_PATH . 'includes/member-import.php';

    $result = null;
    $mail_status = null;

    if (isset($_POST['wl_do_member_import']) && check_admin_referer('wl_member_import')) {
        if (!empty($_FILES['import_file']['tmp_name'])) {
            $tmp_name  = $_FILES['import_file']['tmp_name'];
            $orig_name = $_FILES['import_file']['name'];
            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

            if ($ext === 'csv') {
                $result = wl_import_members_csv($tmp_name);
            } elseif ($ext === 'xml') {
                $result = wl_import_members_xml($tmp_name);
            } else {
                $result = ['created' => [], 'skipped' => 0, 'errors' => ['Nur .csv oder .xml Dateien werden unterstützt.']];
            }

            // E-Mails verschicken, wenn gewünscht
            if (!empty($result['created']) && isset($_POST['wl_send_mails'])) {
                $sent = 0;
                foreach ($result['created'] as $member) {
                    if (wl_send_member_credentials($member)) $sent++;
                }
                $mail_status = $sent . ' von ' . count($result['created']) . ' E-Mails erfolgreich verschickt.';
            }
        } else {
            $result = ['created' => [], 'skipped' => 0, 'errors' => ['Keine Datei ausgewählt.']];
        }
    }

    // Template-Download
    if (isset($_GET['wl_member_template']) && check_admin_referer('wl_member_template_' . $_GET['wl_member_template'])) {
        if ($_GET['wl_member_template'] === 'csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="mitglieder-vorlage.csv"');
            echo wl_get_member_csv_template();
            exit;
        } elseif ($_GET['wl_member_template'] === 'xml') {
            header('Content-Type: application/xml; charset=UTF-8');
            header('Content-Disposition: attachment; filename="mitglieder-vorlage.xml"');
            echo wl_get_member_xml_template();
            exit;
        }
    }
    ?>
    <div class="wrap">
        <h1>👥 Mitglieder importieren</h1>

        <?php if ($result) : ?>
            <div class="notice <?php echo count($result['created']) > 0 ? 'notice-success' : 'notice-warning'; ?>" style="padding:14px;">
                <p><strong><?php echo count($result['created']); ?> Mitglieder angelegt</strong>
                   <?php if ($result['skipped'] > 0) echo ', ' . $result['skipped'] . ' übersprungen.'; ?></p>
                <?php if ($mail_status) : ?>
                    <p>📧 <?php echo esc_html($mail_status); ?></p>
                <?php endif; ?>
                <?php if (!empty($result['errors'])) : ?>
                    <ul style="margin:8px 0 0 20px;color:#7f1d1d;">
                        <?php foreach (array_slice($result['errors'], 0, 15) as $err) : ?>
                            <li><?php echo esc_html($err); ?></li>
                        <?php endforeach; ?>
                        <?php if (count($result['errors']) > 15) echo '<li>… und ' . (count($result['errors']) - 15) . ' weitere</li>'; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($result && !empty($result['created'])) : ?>
            <!-- Zugangsdaten-Liste -->
            <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:20px;margin-bottom:24px;">
                <h2 style="margin-top:0;">Neue Zugangsdaten</h2>
                <?php if (!$mail_status) : ?>
                    <p style="color:#d97706;">⚠️ Diese Zugangsdaten wurden noch nicht per E-Mail verschickt. Notiere sie dir jetzt — sie werden hier nicht erneut angezeigt.</p>
                <?php else : ?>
                    <p style="color:#16a34a;">✓ Zugangsdaten wurden per E-Mail an die Mitglieder verschickt.</p>
                <?php endif; ?>
                <table class="wp-list-table widefat striped">
                    <thead><tr><th>Name</th><th>Benutzername</th><th>E-Mail</th><th>Passwort</th></tr></thead>
                    <tbody>
                        <?php foreach ($result['created'] as $m) : ?>
                            <tr>
                                <td><?php echo esc_html($m['name']); ?></td>
                                <td><code><?php echo esc_html($m['username']); ?></code></td>
                                <td><?php echo esc_html($m['email']); ?></td>
                                <td><code><?php echo esc_html($m['password']); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div style="display:flex;gap:24px;flex-wrap:wrap;">

            <!-- Upload-Formular -->
            <div style="flex:1;min-width:320px;background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:24px;">
                <h2 style="margin-top:0;">Datei hochladen</h2>
                <p>Unterstützt werden <strong>CSV</strong> (Excel-Export) und <strong>XML</strong> Dateien.</p>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('wl_member_import'); ?>
                    <input type="file" name="import_file" accept=".csv,.xml" required style="margin-bottom:14px;display:block;">
                    <label style="display:flex;align-items:center;gap:8px;margin-bottom:16px;">
                        <input type="checkbox" name="wl_send_mails" value="1" checked>
                        Zugangsdaten automatisch per E-Mail verschicken
                    </label>
                    <input type="submit" name="wl_do_member_import" class="button button-primary" value="Importieren">
                </form>
            </div>

            <!-- Vorlagen -->
            <div style="flex:1;min-width:320px;background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:24px;">
                <h2 style="margin-top:0;">Vorlagen herunterladen</h2>
                <p>Lade eine Beispieldatei herunter, fülle sie aus und importiere sie wieder.</p>
                <p>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=wunschliste-mitglieder-import&wl_member_template=csv'), 'wl_member_template_csv'); ?>" class="button">⬇ CSV-Vorlage</a>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=wunschliste-mitglieder-import&wl_member_template=xml'), 'wl_member_template_xml'); ?>" class="button">⬇ XML-Vorlage</a>
                </p>
            </div>
        </div>

        <!-- Format-Doku -->
        <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:24px;margin-top:24px;">
            <h2 style="margin-top:0;">Spalten / Felder</h2>
            <table class="wp-list-table widefat striped" style="max-width:700px;">
                <thead><tr><th>Feld</th><th>Pflicht</th><th>Beschreibung</th></tr></thead>
                <tbody>
                    <tr><td><code>name</code></td><td>✅ Ja</td><td>Anzeigename des Mitglieds</td></tr>
                    <tr><td><code>email</code></td><td>✅ Ja</td><td>E-Mail-Adresse (muss eindeutig sein)</td></tr>
                    <tr><td><code>username</code></td><td>Nein</td><td>Wird sonst automatisch aus dem Namen generiert</td></tr>
                </tbody>
            </table>
            <p style="margin-top:12px;color:#666;">
                Passwörter werden automatisch zufällig generiert. Mitglieder erhalten die Rolle <strong>Vereinsmitglied</strong>
                (Zugriff auf die Wunschlisten-Verwaltung, kein WordPress-Admin-Zugang).
                Bereits registrierte E-Mail-Adressen werden übersprungen.
            </p>
        </div>
    </div>
    <?php
}

// ─── IMPORT-SEITE ─────────────────────────────────────────────────────────

function wl_import_page() {
    require_once WL_PATH . 'includes/import.php';

    $result = null;

    if (isset($_POST['wl_do_import']) && check_admin_referer('wl_import')) {
        if (!empty($_FILES['import_file']['tmp_name'])) {
            $tmp_name = $_FILES['import_file']['tmp_name'];
            $orig_name = $_FILES['import_file']['name'];
            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));

            if ($ext === 'csv') {
                $result = wl_import_csv($tmp_name);
            } elseif ($ext === 'xml') {
                $result = wl_import_xml($tmp_name);
            } else {
                $result = ['imported' => 0, 'skipped' => 0, 'errors' => ['Nur .csv oder .xml Dateien werden unterstützt.']];
            }
        } else {
            $result = ['imported' => 0, 'skipped' => 0, 'errors' => ['Keine Datei ausgewählt.']];
        }
    }

    // Template-Download
    if (isset($_GET['wl_template']) && check_admin_referer('wl_template_' . $_GET['wl_template'])) {
        if ($_GET['wl_template'] === 'csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="wunschliste-vorlage.csv"');
            echo wl_get_csv_template();
            exit;
        } elseif ($_GET['wl_template'] === 'xml') {
            header('Content-Type: application/xml; charset=UTF-8');
            header('Content-Disposition: attachment; filename="wunschliste-vorlage.xml"');
            echo wl_get_xml_template();
            exit;
        }
    }
    ?>
    <div class="wrap">
        <h1>📥 Wunschliste importieren</h1>

        <?php if ($result) : ?>
            <div class="notice <?php echo $result['imported'] > 0 ? 'notice-success' : 'notice-warning'; ?>" style="padding:14px;">
                <p><strong><?php echo $result['imported']; ?> Wünsche importiert</strong>
                   <?php if ($result['skipped'] > 0) echo ', ' . $result['skipped'] . ' übersprungen.'; ?></p>
                <?php if (!empty($result['errors'])) : ?>
                    <ul style="margin:8px 0 0 20px;color:#7f1d1d;">
                        <?php foreach (array_slice($result['errors'], 0, 15) as $err) : ?>
                            <li><?php echo esc_html($err); ?></li>
                        <?php endforeach; ?>
                        <?php if (count($result['errors']) > 15) echo '<li>… und ' . (count($result['errors']) - 15) . ' weitere</li>'; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:20px;">

            <!-- Upload-Formular -->
            <div style="flex:1;min-width:320px;background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:24px;">
                <h2 style="margin-top:0;">Datei hochladen</h2>
                <p>Unterstützt werden <strong>CSV</strong> (Excel-Export) und <strong>XML</strong> Dateien.</p>
                <form method="post" enctype="multipart/form-data">
                    <?php wp_nonce_field('wl_import'); ?>
                    <input type="file" name="import_file" accept=".csv,.xml" required style="margin-bottom:16px;display:block;">
                    <input type="submit" name="wl_do_import" class="button button-primary" value="Importieren">
                </form>
            </div>

            <!-- Vorlagen -->
            <div style="flex:1;min-width:320px;background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:24px;">
                <h2 style="margin-top:0;">Vorlagen herunterladen</h2>
                <p>Lade eine Beispieldatei herunter, fülle sie aus und importiere sie wieder.</p>
                <p>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=wunschliste-import&wl_template=csv'), 'wl_template_csv'); ?>" class="button">⬇ CSV-Vorlage</a>
                    <a href="<?php echo wp_nonce_url(admin_url('admin.php?page=wunschliste-import&wl_template=xml'), 'wl_template_xml'); ?>" class="button">⬇ XML-Vorlage</a>
                </p>
            </div>
        </div>

        <!-- Format-Doku -->
        <div style="background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:24px;margin-top:24px;">
            <h2 style="margin-top:0;">Spalten / Felder</h2>
            <table class="wp-list-table widefat striped" style="max-width:800px;">
                <thead><tr><th>Feld</th><th>Pflicht</th><th>Beschreibung</th></tr></thead>
                <tbody>
                    <tr><td><code>titel</code></td><td>✅ Ja</td><td>Name des Wunsches</td></tr>
                    <tr><td><code>beschreibung</code></td><td>Nein</td><td>Kurzbeschreibung</td></tr>
                    <tr><td><code>begruendung</code></td><td>Nein</td><td>Warum wird das gebraucht?</td></tr>
                    <tr><td><code>betrag</code></td><td>Nein</td><td>Fester Preis, z.B. 49.90</td></tr>
                    <tr><td><code>preis_von</code> / <code>preis_bis</code></td><td>Nein</td><td>Preis-Spanne statt Festbetrag</td></tr>
                    <tr><td><code>kategorie</code></td><td>Nein</td><td>Frei wählbar, z.B. Sport</td></tr>
                    <tr><td><code>status</code></td><td>Nein</td><td>offen / in_bearbeitung / erfuellt (Standard: offen)</td></tr>
                    <tr><td><code>prioritaet</code></td><td>Nein</td><td>1 (dringend) bis 3 (Standard: 2)</td></tr>
                    <tr><td><code>bild_url</code></td><td>Nein</td><td>Bild-Link</td></tr>
                    <tr><td><code>link1_label / link1_url / link1_preis</code></td><td>Nein</td><td>Produktlink 1 (CSV). Für mehr Links: link2_*, link3_* usw.</td></tr>
                </tbody>
            </table>
            <p style="margin-top:12px;color:#666;">Bei XML werden Links über das verschachtelte <code>&lt;links&gt;</code>-Element mit beliebig vielen <code>&lt;link&gt;</code>-Einträgen angegeben (siehe Vorlage).</p>
        </div>
    </div>
    <?php
}

// ─── ÜBERSICHTSSEITE ──────────────────────────────────────────────────────

function wl_admin_page() {
    $wuensche = wl_get_wuensche(['orderby' => 'prioritaet']);
    $stats = [
        'offen'          => 0,
        'in_bearbeitung' => 0,
        'erfuellt'       => 0,
        'gesamt_betrag'  => 0,
    ];
    foreach ($wuensche as $w) {
        $stats[$w->status] = ($stats[$w->status] ?? 0) + 1;
        if ($w->status !== 'erfuellt') $stats['gesamt_betrag'] += $w->betrag;
    }
    ?>
    <div class="wrap">
        <h1>🎯 Vereins-Wunschliste</h1>

        <!-- Stats -->
        <div style="display:flex;gap:16px;margin:20px 0;flex-wrap:wrap;">
            <?php foreach ([
                ['label'=>'Offen', 'val'=>$stats['offen'], 'color'=>'#f0ad4e'],
                ['label'=>'In Bearbeitung', 'val'=>$stats['in_bearbeitung'], 'color'=>'#5bc0de'],
                ['label'=>'Erfüllt', 'val'=>$stats['erfuellt'], 'color'=>'#5cb85c'],
                ['label'=>'Offener Bedarf', 'val'=>number_format($stats['gesamt_betrag'],2,',','.') . ' €', 'color'=>'#d9534f'],
            ] as $s) : ?>
            <div style="background:#fff;border-left:4px solid <?php echo $s['color']; ?>;padding:16px 24px;border-radius:4px;box-shadow:0 1px 3px rgba(0,0,0,.1);min-width:160px;">
                <div style="font-size:28px;font-weight:700;color:<?php echo $s['color']; ?>"><?php echo $s['val']; ?></div>
                <div style="color:#666;font-size:13px;"><?php echo $s['label']; ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Shortcode-Info -->
        <div class="notice notice-info" style="padding:12px 16px;">
            <strong>Shortcodes für deine Seiten:</strong><br>
            <code>[wunschliste]</code> – Öffentliche Spender-Ansicht &nbsp;|&nbsp;
            <code>[wunschliste_verwaltung]</code> – Mitglieder-Verwaltung &nbsp;|&nbsp;
            <code>[wunschliste_login]</code> – Login-Formular für Mitglieder
        </div>

        <!-- Tabelle -->
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Titel</th><th>Kategorie</th><th>Betrag</th><th>Status</th><th>Priorität</th><th>Erstellt</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($wuensche as $w) : ?>
                <tr>
                    <td><strong><?php echo esc_html($w->titel); ?></strong></td>
                    <td><?php echo esc_html($w->kategorie); ?></td>
                    <td><?php echo $w->betrag > 0 ? number_format($w->betrag, 2, ',', '.') . ' €' : '–'; ?></td>
                    <td><?php echo wl_status_label($w->status); ?></td>
                    <td><?php echo wl_prio_label($w->prioritaet); ?></td>
                    <td><?php echo date('d.m.Y', strtotime($w->erstellt_am)); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// ─── EINSTELLUNGEN ────────────────────────────────────────────────────────

function wl_settings_page() {
    if (isset($_POST['wl_save_settings']) && check_admin_referer('wl_settings')) {
        update_option('wl_kontoinhaber',  sanitize_text_field($_POST['wl_kontoinhaber']));
        update_option('wl_iban',          sanitize_text_field($_POST['wl_iban']));
        update_option('wl_bic',           sanitize_text_field($_POST['wl_bic']));
        update_option('wl_kontakt_email', sanitize_email($_POST['wl_kontakt_email']));
        echo '<div class="notice notice-success"><p>Einstellungen gespeichert.</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Wunschliste – Einstellungen</h1>
        <form method="post">
            <?php wp_nonce_field('wl_settings'); ?>
            <table class="form-table">
                <tr><th>Kontoinhaber</th><td><input class="regular-text" type="text" name="wl_kontoinhaber" value="<?php echo esc_attr(get_option('wl_kontoinhaber', '')); ?>"></td></tr>
                <tr><th>IBAN</th><td><input class="regular-text" type="text" name="wl_iban" value="<?php echo esc_attr(get_option('wl_iban', '')); ?>"></td></tr>
                <tr><th>BIC</th><td><input class="regular-text" type="text" name="wl_bic" value="<?php echo esc_attr(get_option('wl_bic', '')); ?>"></td></tr>
                <tr><th>Kontakt-E-Mail<br><small>(für Spenden-Anfragen)</small></th>
                    <td><input class="regular-text" type="email" name="wl_kontakt_email" value="<?php echo esc_attr(get_option('wl_kontakt_email', get_option('admin_email'))); ?>"></td></tr>
            </table>
            <input type="submit" name="wl_save_settings" class="button button-primary" value="Speichern">
        </form>
    </div>
    <?php
}

// ─── NEUES MITGLIED ANLEGEN ───────────────────────────────────────────────

function wl_new_member_page() {
    $message = '';
    if (isset($_POST['wl_create_member']) && check_admin_referer('wl_create_member')) {
        $username = sanitize_user($_POST['username']);
        $email    = sanitize_email($_POST['email']);
        $password = $_POST['password'];
        $name     = sanitize_text_field($_POST['display_name']);

        if (username_exists($username)) {
            $message = '<div class="notice notice-error"><p>Benutzername bereits vergeben.</p></div>';
        } elseif (email_exists($email)) {
            $message = '<div class="notice notice-error"><p>E-Mail bereits registriert.</p></div>';
        } else {
            $user_id = wp_create_user($username, $password, $email);
            if (!is_wp_error($user_id)) {
                $user = new WP_User($user_id);
                $user->set_role('wl_mitglied');
                wp_update_user(['ID' => $user_id, 'display_name' => $name]);
                $message = '<div class="notice notice-success"><p>Mitglied <strong>' . esc_html($username) . '</strong> erfolgreich angelegt.</p></div>';
            } else {
                $message = '<div class="notice notice-error"><p>' . $user_id->get_error_message() . '</p></div>';
            }
        }
    }
    ?>
    <div class="wrap">
        <h1>Neues Vereinsmitglied anlegen</h1>
        <?php echo $message; ?>
        <p>Mitglieder erhalten Zugriff auf die Wunschlisten-Verwaltung, aber keinen WordPress-Admin-Zugang.</p>
        <form method="post">
            <?php wp_nonce_field('wl_create_member'); ?>
            <table class="form-table">
                <tr><th>Anzeigename</th><td><input class="regular-text" type="text" name="display_name" required></td></tr>
                <tr><th>Benutzername</th><td><input class="regular-text" type="text" name="username" required></td></tr>
                <tr><th>E-Mail</th><td><input class="regular-text" type="email" name="email" required></td></tr>
                <tr><th>Passwort</th><td><input class="regular-text" type="text" name="password" value="<?php echo wp_generate_password(12, false); ?>"></td></tr>
            </table>
            <input type="submit" name="wl_create_member" class="button button-primary" value="Mitglied anlegen">
        </form>
    </div>
    <?php
}
