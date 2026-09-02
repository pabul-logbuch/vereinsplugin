<?php
defined('ABSPATH') || exit;

/**
 * Erwartete Felder (Spaltennamen bei CSV / Tag-Namen bei XML):
 *
 * name*       – Pflichtfeld (Anzeigename)
 * username    – optional, wird sonst aus E-Mail/Name generiert
 * email*      – Pflichtfeld
 *
 * CSV-Beispiel:
 * name;username;email
 * Max Mustermann;max.m;max@example.de
 *
 * XML-Struktur:
 * <mitglieder>
 *   <mitglied>
 *     <name>Max Mustermann</name>
 *     <username>max.m</username>
 *     <email>max@example.de</email>
 *   </mitglied>
 * </mitglieder>
 */

// ─── CSV IMPORT ───────────────────────────────────────────────────────────

function wl_import_members_csv($file_path) {
    $result = ['created' => [], 'skipped' => 0, 'errors' => []];

    if (!file_exists($file_path)) {
        $result['errors'][] = 'Datei nicht gefunden.';
        return $result;
    }

    $handle = fopen($file_path, 'r');
    if (!$handle) {
        $result['errors'][] = 'Datei konnte nicht geöffnet werden.';
        return $result;
    }

    $first_line = fgets($handle);
    rewind($handle);
    $delimiter = (substr_count($first_line, ';') > substr_count($first_line, ',')) ? ';' : ',';

    $header = fgetcsv($handle, 0, $delimiter);
    if (!$header) {
        $result['errors'][] = 'CSV-Header konnte nicht gelesen werden.';
        fclose($handle);
        return $result;
    }

    $header = array_map(function ($h) {
        $h = trim($h);
        $h = str_replace("\xEF\xBB\xBF", '', $h);
        return strtolower($h);
    }, $header);

    $row_num = 1;
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        $row_num++;
        if (count($row) === 1 && trim($row[0]) === '') continue;

        $data = [];
        foreach ($header as $i => $key) {
            $data[$key] = isset($row[$i]) ? trim($row[$i]) : '';
        }

        $r = wl_import_single_member($data);
        if ($r['ok']) {
            $result['created'][] = $r['member'];
        } else {
            $result['skipped']++;
            $result['errors'][] = "Zeile $row_num: " . $r['error'];
        }
    }

    fclose($handle);
    return $result;
}

// ─── XML IMPORT ───────────────────────────────────────────────────────────

function wl_import_members_xml($file_path) {
    $result = ['created' => [], 'skipped' => 0, 'errors' => []];

    if (!file_exists($file_path)) {
        $result['errors'][] = 'Datei nicht gefunden.';
        return $result;
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($file_path);

    if ($xml === false) {
        $errors = libxml_get_errors();
        $msg = !empty($errors) ? $errors[0]->message : 'Unbekannter XML-Fehler.';
        $result['errors'][] = 'XML konnte nicht gelesen werden: ' . trim($msg);
        return $result;
    }

    $mitglieder = $xml->mitglied ?? $xml->member ?? $xml->children();
    $i = 0;

    foreach ($mitglieder as $node) {
        $i++;
        $data = [];
        foreach ($node->children() as $child) {
            $tag = strtolower($child->getName());
            $data[$tag] = trim((string) $child);
        }

        $r = wl_import_single_member($data);
        if ($r['ok']) {
            $result['created'][] = $r['member'];
        } else {
            $result['skipped']++;
            $result['errors'][] = "Eintrag $i: " . $r['error'];
        }
    }

    return $result;
}

// ─── EINZELNES MITGLIED ANLEGEN ───────────────────────────────────────────

function wl_import_single_member($data) {
    $name  = sanitize_text_field($data['name'] ?? $data['anzeigename'] ?? '');
    $email = sanitize_email($data['email'] ?? $data['e-mail'] ?? $data['mail'] ?? '');
    $username_raw = sanitize_text_field($data['username'] ?? $data['benutzername'] ?? '');

    if (empty($name)) {
        return ['ok' => false, 'error' => 'Name fehlt.'];
    }
    if (empty($email) || !is_email($email)) {
        return ['ok' => false, 'error' => 'Ungültige oder fehlende E-Mail-Adresse.'];
    }
    if (email_exists($email)) {
        return ['ok' => false, 'error' => "E-Mail $email ist bereits registriert."];
    }

    // Benutzername generieren falls nicht angegeben
    $username = !empty($username_raw) ? sanitize_user($username_raw) : sanitize_user(wl_generate_username_from_name($name));

    // Falls Benutzername bereits existiert, Zahl anhängen
    $base = $username;
    $n = 2;
    while (username_exists($username)) {
        $username = $base . $n;
        $n++;
    }

    $password = wp_generate_password(12, false);

    $user_id = wp_create_user($username, $password, $email);
    if (is_wp_error($user_id)) {
        return ['ok' => false, 'error' => $user_id->get_error_message()];
    }

    $user = new WP_User($user_id);
    $user->set_role('wl_mitglied');
    wp_update_user(['ID' => $user_id, 'display_name' => $name]);

    return [
        'ok' => true,
        'member' => [
            'id'       => $user_id,
            'name'     => $name,
            'username' => $username,
            'email'    => $email,
            'password' => $password,
        ],
    ];
}

function wl_generate_username_from_name($name) {
    $username = strtolower($name);
    $username = remove_accents($username);
    $username = preg_replace('/[^a-z0-9]+/', '.', $username);
    $username = trim($username, '.');
    return $username ?: 'mitglied' . wp_rand(100, 999);
}

// ─── ZUGANGSDATEN PER E-MAIL VERSCHICKEN ─────────────────────────────────

function wl_send_member_credentials($member) {
    $login_url = wp_login_url();
    $betreff = 'Deine Zugangsdaten für die Vereins-Wunschliste';

    $body  = "Hallo " . $member['name'] . ",\n\n";
    $body .= "du wurdest als Vereinsmitglied für die Wunschlisten-Verwaltung freigeschaltet.\n\n";
    $body .= "Deine Zugangsdaten:\n";
    $body .= "Benutzername: " . $member['username'] . "\n";
    $body .= "Passwort:     " . $member['password'] . "\n\n";
    $body .= "Login hier: $login_url\n\n";
    $body .= "Bitte ändere dein Passwort nach dem ersten Login.\n\n";
    $body .= "Herzliche Grüße,\n" . get_bloginfo('name');

    return wp_mail($member['email'], $betreff, $body);
}

// ─── VORLAGEN ──────────────────────────────────────────────────────────────

function wl_get_member_csv_template() {
    $rows = [
        ['name','username','email'],
        ['Anna Beispiel','anna.b','anna@example.de'],
        ['Max Mustermann','','max@example.de'],
    ];

    $output = '';
    foreach ($rows as $row) {
        $output .= implode(';', $row) . "\r\n";
    }
    return "\xEF\xBB\xBF" . $output;
}

function wl_get_member_xml_template() {
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<mitglieder>
    <mitglied>
        <name>Anna Beispiel</name>
        <username>anna.b</username>
        <email>anna@example.de</email>
    </mitglied>
    <mitglied>
        <name>Max Mustermann</name>
        <email>max@example.de</email>
    </mitglied>
</mitglieder>
XML;
}
