<?php
defined('ABSPATH') || exit;

// ═══════════════════════════════════════════════════════════════
// CRUD
// ═══════════════════════════════════════════════════════════════

function jb_submit_auslage(array $data, array $file): int|WP_Error {
    if (!jb_can_submit()) {
        return new WP_Error('permission', 'Keine Berechtigung.');
    }

    $user_id  = get_current_user_id();
    $betrag   = (float) str_replace(',', '.', sanitize_text_field($data['betrag'] ?? '0'));
    $datum    = sanitize_text_field($data['ausgabe_datum'] ?? '');
    $kat      = sanitize_text_field($data['kategorie'] ?? 'Sonstige Ausgaben');
    $beschr   = sanitize_textarea_field($data['beschreibung'] ?? '');

    if ($betrag <= 0)    return new WP_Error('invalid_betrag', 'Betrag muss größer als 0 sein.');
    if (empty($datum))   return new WP_Error('invalid_datum',  'Datum fehlt.');
    if (empty($beschr))  return new WP_Error('invalid_beschr', 'Beschreibung fehlt.');

    global $wpdb;
    $t = jb_table_auslagen();

    // Eintrag anlegen (ohne Beleg zunächst)
    $wpdb->insert($t, [
        'user_id'       => $user_id,
        'ausgabe_datum' => $datum,
        'betrag'        => $betrag,
        'kategorie'     => $kat,
        'beschreibung'  => $beschr,
        'status'        => 'ausstehend',
        'eingereicht_am'=> current_time('mysql'),
    ]);
    $id = (int) $wpdb->insert_id;
    if (!$id) return new WP_Error('db_error', 'Datenbankfehler beim Speichern.');

    // Beleg hochladen (falls vorhanden)
    if (!empty($file['name']) && $file['error'] === UPLOAD_ERR_OK) {
        $nc_path = jb_upload_beleg($file, $id, $datum, $user_id);
        if (is_wp_error($nc_path)) {
            // Eintrag wieder löschen, Upload war Pflicht
            $wpdb->delete($t, ['id' => $id]);
            return $nc_path;
        }
        $wpdb->update($t, [
            'beleg_pfad' => $nc_path,
            'beleg_name' => sanitize_file_name($file['name']),
        ], ['id' => $id]);
    } elseif (get_option('jb_beleg_pflicht', '1') === '1') {
        $wpdb->delete($t, ['id' => $id]);
        return new WP_Error('beleg_fehlt', 'Beleg-Upload ist Pflicht.');
    }

    // E-Mail an Kassier
    jb_notify_kassier_new($id);

    return $id;
}

function jb_get_auslagen(array $args = []): array {
    global $wpdb;
    $t = jb_table_auslagen();

    $where = ['1=1'];
    $params = [];

    if (!empty($args['status'])) {
        $where[] = 'status = %s';
        $params[] = $args['status'];
    }
    if (!empty($args['user_id'])) {
        $where[] = 'user_id = %d';
        $params[] = (int) $args['user_id'];
    }
    if (!empty($args['year'])) {
        $where[] = 'YEAR(ausgabe_datum) = %d';
        $params[] = (int) $args['year'];
    }

    $sql = "SELECT a.*, u.display_name as user_name
            FROM $t a
            LEFT JOIN {$wpdb->users} u ON a.user_id = u.ID
            WHERE " . implode(' AND ', $where) . "
            ORDER BY eingereicht_am DESC";

    if (!empty($params)) {
        $sql = $wpdb->prepare($sql, ...$params);
    }

    return $wpdb->get_results($sql, ARRAY_A) ?: [];
}

function jb_get_auslage(int $id): ?array {
    global $wpdb;
    $t = jb_table_auslagen();
    return $wpdb->get_row($wpdb->prepare(
        "SELECT a.*, u.display_name as user_name
         FROM $t a
         LEFT JOIN {$wpdb->users} u ON a.user_id = u.ID
         WHERE a.id = %d", $id
    ), ARRAY_A);
}

function jb_approve_auslage(int $id, bool $approve, string $notiz = ''): bool {
    if (!jb_can_approve()) return false;
    global $wpdb;

    $status = $approve ? 'genehmigt' : 'abgelehnt';
    $rows = $wpdb->update(jb_table_auslagen(), [
        'status'         => $status,
        'kassier_id'     => get_current_user_id(),
        'kassier_notiz'  => sanitize_textarea_field($notiz),
        'entschieden_am' => current_time('mysql'),
    ], ['id' => $id]);

    if ($rows && $approve) {
        jb_notify_member_decision($id, true);
    } elseif ($rows && !$approve) {
        jb_notify_member_decision($id, false);
    }

    return (bool) $rows;
}

function jb_mark_paid(int $id): bool|WP_Error {
    if (!current_user_can('jb_mark_paid')) return false;

    $auslage = jb_get_auslage($id);
    if (!$auslage || $auslage['status'] !== 'genehmigt') {
        return new WP_Error('invalid_state', 'Nur genehmigte Auslagen können ausgezahlt werden.');
    }

    global $wpdb;
    $wpdb->update(jb_table_auslagen(), [
        'status'        => 'ausgezahlt',
        'ausgezahlt_am' => current_time('mysql'),
    ], ['id' => $id]);

    // Ins Buchungsjournal übernehmen (als Ausgabe, negativ)
    $buchung_id = jb_journal_add([
        'buchung_datum' => $auslage['ausgabe_datum'],
        'betrag'        => -abs((float) $auslage['betrag']),
        'kategorie'     => $auslage['kategorie'],
        'beschreibung'  => 'Auslage #' . $id . ': ' . $auslage['beschreibung'],
        'quelle'        => 'Auslage',
        'beleg_pfad'    => $auslage['beleg_pfad'],
        'auslage_id'    => $id,
    ]);

    // Buchungs-ID in Auslage speichern
    $wpdb->update(jb_table_auslagen(), ['buchung_id' => $buchung_id], ['id' => $id]);

    return true;
}

// ═══════════════════════════════════════════════════════════════
// E-MAIL NOTIFICATIONS
// ═══════════════════════════════════════════════════════════════

function jb_notify_kassier_new(int $auslage_id): void {
    $auslage = jb_get_auslage($auslage_id);
    if (!$auslage) return;

    $kassier_email = get_option('jb_kassier_email', get_option('admin_email'));
    $subject = '[JuFo] Neue Auslage von ' . $auslage['user_name'];
    $body = sprintf(
        "Hallo,\n\n%s hat eine neue Auslage eingereicht:\n\nBetrag: %.2f €\nDatum: %s\nKategorie: %s\nBeschreibung: %s\n\nZur Genehmigung: %s\n",
        $auslage['user_name'],
        $auslage['betrag'],
        $auslage['ausgabe_datum'],
        $auslage['kategorie'],
        $auslage['beschreibung'],
        admin_url('admin.php?page=jb_auslagen&id=' . $auslage_id)
    );
    wp_mail($kassier_email, $subject, $body);
}

function jb_notify_member_decision(int $auslage_id, bool $approved): void {
    $auslage = jb_get_auslage($auslage_id);
    if (!$auslage) return;
    $user = get_userdata($auslage['user_id']);
    if (!$user) return;

    $status = $approved ? 'genehmigt ✓' : 'abgelehnt ✗';
    $subject = '[JuFo] Deine Auslage wurde ' . $status;
    $body = sprintf(
        "Hallo %s,\n\ndeine Auslage über %.2f € (%s) wurde %s.\n\n%s\n\nDeine Auslagen: %s\n",
        $user->display_name,
        $auslage['betrag'],
        $auslage['beschreibung'],
        $status,
        $auslage['kassier_notiz'] ? 'Notiz: ' . $auslage['kassier_notiz'] : '',
        home_url('/meine-auslagen/')
    );
    wp_mail($user->user_email, $subject, $body);
}
