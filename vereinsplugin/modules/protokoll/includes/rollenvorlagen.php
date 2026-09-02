<?php
defined('ABSPATH') || exit;

// ─── DATENZUGRIFF ──────────────────────────────────────────────────────────

function pp_get_rollenvorlagen_fuer_gremium($gremium_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pp_rollenvorlagen WHERE gremium_id = %d ORDER BY bezeichnung", $gremium_id
    ));
}

function pp_get_rollenvorlage($id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pp_rollenvorlagen WHERE id = %d", $id));
}

function pp_get_aufgaben_fuer_rollenvorlage($rollenvorlage_id) {
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pp_rollenvorlagen_aufgaben WHERE rollenvorlage_id = %d ORDER BY typ, titel", $rollenvorlage_id
    ));
}

/** Liefert die aktuellen Besetzungen (WP-User) einer Rollenvorlage,
 *  eingeschränkt auf eine laufende Amtszeit. */
function pp_get_aktuelle_besetzungen($rollenvorlage_id) {
    global $wpdb;
    $heute = current_time('Y-m-d');
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pp_rollen
         WHERE rollenvorlage_id = %d
           AND user_id IS NOT NULL
           AND (amtszeit_start IS NULL OR amtszeit_start <= %s)
           AND (amtszeit_ende IS NULL OR amtszeit_ende >= %s)",
        $rollenvorlage_id, $heute, $heute
    ));
}

function pp_textliste_zu_array($text) {
    if (!$text) return [];
    $zeilen = preg_split('/\r\n|\r|\n/', $text);
    return array_values(array_filter(array_map('trim', $zeilen)));
}

// ─── FORM-HANDLER: ROLLENVORLAGE ────────────────────────────────────────────

add_action('admin_post_pp_save_rollenvorlage', 'pp_handle_save_rollenvorlage');
function pp_handle_save_rollenvorlage() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_save_rollenvorlage');
    global $wpdb;

    $id = intval($_POST['id'] ?? 0);
    $gremium_id = intval($_POST['gremium_id'] ?? 0);
    $data = [
        'gremium_id'              => $gremium_id,
        'bezeichnung'             => sanitize_text_field($_POST['bezeichnung'] ?? ''),
        'verantwortlich_fuer'     => sanitize_textarea_field($_POST['verantwortlich_fuer'] ?? ''),
        'benoetigte_faehigkeiten' => sanitize_textarea_field($_POST['benoetigte_faehigkeiten'] ?? ''),
    ];

    if (empty($data['bezeichnung'])) {
        wp_safe_redirect(admin_url('admin.php?page=pp-gremien&edit=' . $gremium_id . '&pp_error=Bezeichnung+fehlt'));
        exit;
    }

    if ($id > 0) {
        $wpdb->update($wpdb->prefix . 'pp_rollenvorlagen', $data, ['id' => $id]);
    } else {
        $wpdb->insert($wpdb->prefix . 'pp_rollenvorlagen', $data);
        $id = $wpdb->insert_id;
    }

    wp_safe_redirect(admin_url('admin.php?page=pp-gremien&edit=' . $gremium_id . '&pp_saved=1#rollenvorlage-' . $id));
    exit;
}

add_action('admin_post_pp_delete_rollenvorlage', 'pp_handle_delete_rollenvorlage');
function pp_handle_delete_rollenvorlage() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_delete_rollenvorlage');
    global $wpdb;
    $id = intval($_GET['id'] ?? 0);
    $gremium_id = intval($_GET['gremium_id'] ?? 0);
    // Besetzungen dieser Vorlage lösen sich von der Vorlage (bleiben als reine Besetzung erhalten)
    $wpdb->update($wpdb->prefix . 'pp_rollen', ['rollenvorlage_id' => null], ['rollenvorlage_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'pp_rollenvorlagen_aufgaben', ['rollenvorlage_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'pp_rollenvorlagen', ['id' => $id]);
    wp_safe_redirect(admin_url('admin.php?page=pp-gremien&edit=' . $gremium_id . '&pp_deleted=1'));
    exit;
}

// ─── FORM-HANDLER: AUFGABEN-VORLAGE ─────────────────────────────────────────

add_action('admin_post_pp_save_rollenvorlage_aufgabe', 'pp_handle_save_rollenvorlage_aufgabe');
function pp_handle_save_rollenvorlage_aufgabe() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_save_rollenvorlage_aufgabe');
    global $wpdb;

    $rollenvorlage_id = intval($_POST['rollenvorlage_id'] ?? 0);
    $gremium_id = intval($_POST['gremium_id'] ?? 0);
    $typ = ($_POST['typ'] ?? '') === 'event' ? 'event' : 'wiederkehrend';

    $data = [
        'rollenvorlage_id' => $rollenvorlage_id,
        'titel'            => sanitize_text_field($_POST['titel'] ?? ''),
        'beschreibung'     => sanitize_textarea_field($_POST['beschreibung'] ?? ''),
        'typ'              => $typ,
        'wiederholung'     => $typ === 'wiederkehrend' && in_array($_POST['wiederholung'] ?? '', ['taeglich','woechentlich','monatlich','jaehrlich']) ? $_POST['wiederholung'] : null,
        'vorlauf_tage'     => $typ === 'event' ? intval($_POST['vorlauf_tage'] ?? 0) : null,
    ];

    if (!empty($data['titel']) && $rollenvorlage_id) {
        $wpdb->insert($wpdb->prefix . 'pp_rollenvorlagen_aufgaben', $data);
    }

    wp_safe_redirect(admin_url('admin.php?page=pp-gremien&edit=' . $gremium_id . '&pp_saved=1#rollenvorlage-' . $rollenvorlage_id));
    exit;
}

add_action('admin_post_pp_delete_rollenvorlage_aufgabe', 'pp_handle_delete_rollenvorlage_aufgabe');
function pp_handle_delete_rollenvorlage_aufgabe() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_delete_rollenvorlage_aufgabe');
    global $wpdb;
    $id = intval($_GET['id'] ?? 0);
    $gremium_id = intval($_GET['gremium_id'] ?? 0);
    $wpdb->delete($wpdb->prefix . 'pp_rollenvorlagen_aufgaben', ['id' => $id]);
    wp_safe_redirect(admin_url('admin.php?page=pp-gremien&edit=' . $gremium_id . '&pp_deleted=1'));
    exit;
}

// ─── AUTOMATIK: WIEDERKEHRENDE AUFGABEN (täglicher Cron) ───────────────────

/**
 * Vereinfachtes Intervall-Modell: monatlich = 30 Tage, jährlich = 365 Tage
 * (kein exaktes Kalenderdatum-Matching). Für die Praxis ("Kassier:in bucht
 * einmal im Monat") reicht das; bei Bedarf später verfeinern.
 */
function pp_wiederholung_in_tagen($wiederholung) {
    $tage = ['taeglich' => 1, 'woechentlich' => 7, 'monatlich' => 30, 'jaehrlich' => 365];
    return $tage[$wiederholung] ?? 30;
}

function pp_generate_wiederkehrende_aufgaben() {
    global $wpdb;
    $heute = current_time('Y-m-d');

    $vorlagen_aufgaben = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}pp_rollenvorlagen_aufgaben WHERE typ = 'wiederkehrend' AND aktiv = 1"
    );

    foreach ($vorlagen_aufgaben as $va) {
        $letzte = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(generiert_fuer_datum) FROM {$wpdb->prefix}pp_aufgaben_generiert_log WHERE rollenvorlage_aufgabe_id = %d",
            $va->id
        ));

        $faellig = true;
        if ($letzte) {
            $naechste_faellig = date('Y-m-d', strtotime($letzte . ' +' . pp_wiederholung_in_tagen($va->wiederholung) . ' days'));
            $faellig = ($heute >= $naechste_faellig);
        }
        if (!$faellig) continue;

        $besetzungen = pp_get_aktuelle_besetzungen($va->rollenvorlage_id);
        foreach ($besetzungen as $besetzung) {
            $wpdb->insert($wpdb->prefix . 'pp_aufgaben', [
                'titel'                          => $va->titel,
                'beschreibung'                   => $va->beschreibung,
                'verantwortlich_user_id'         => $besetzung->user_id,
                'faelligkeitsdatum'              => $heute,
                'quelle_rollenvorlage_aufgabe_id' => $va->id,
            ]);
        }

        // Auch ohne aktuelle Besetzung als "erledigt geprüft" loggen, damit
        // der Cron nicht täglich neu versucht.
        $wpdb->insert($wpdb->prefix . 'pp_aufgaben_generiert_log', [
            'rollenvorlage_aufgabe_id' => $va->id,
            'generiert_fuer_datum'     => $heute,
        ]);
    }
}

// ─── EVENT-AUFGABEN (manuell je Termin ausgelöst) ──────────────────────────

add_action('admin_post_pp_generate_event_aufgaben', 'pp_handle_generate_event_aufgaben');
function pp_handle_generate_event_aufgaben() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_generate_event_aufgaben');
    global $wpdb;

    $termin_id = intval($_POST['termin_id'] ?? 0);
    $termin = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pp_termine WHERE id = %d", $termin_id));

    if ($termin && $termin->gremium_id && $termin->datum) {
        $rollenvorlagen = pp_get_rollenvorlagen_fuer_gremium($termin->gremium_id);
        $erzeugt = 0;

        foreach ($rollenvorlagen as $rv) {
            $event_aufgaben = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}pp_rollenvorlagen_aufgaben WHERE rollenvorlage_id = %d AND typ = 'event' AND aktiv = 1",
                $rv->id
            ));

            foreach ($event_aufgaben as $ea) {
                // Doppelte Erzeugung für denselben Termin verhindern
                $existiert = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}pp_aufgaben WHERE quelle_termin_id = %d AND quelle_rollenvorlage_aufgabe_id = %d",
                    $termin_id, $ea->id
                ));
                if ($existiert > 0) continue;

                $faelligkeitsdatum = date('Y-m-d', strtotime($termin->datum . ' -' . intval($ea->vorlauf_tage) . ' days'));
                $besetzungen = pp_get_aktuelle_besetzungen($rv->id);

                foreach ($besetzungen as $besetzung) {
                    $wpdb->insert($wpdb->prefix . 'pp_aufgaben', [
                        'titel'                          => $ea->titel . ' (' . $termin->titel . ')',
                        'beschreibung'                   => $ea->beschreibung,
                        'verantwortlich_user_id'         => $besetzung->user_id,
                        'faelligkeitsdatum'              => $faelligkeitsdatum,
                        'quelle_termin_id'               => $termin_id,
                        'quelle_rollenvorlage_aufgabe_id' => $ea->id,
                    ]);
                    $erzeugt++;
                }
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=pp-aufgaben-termine&pp_event_aufgaben=' . $erzeugt));
        exit;
    }

    wp_safe_redirect(admin_url('admin.php?page=pp-aufgaben-termine&pp_error=Termin+ohne+Gremium+oder+Datum'));
    exit;
}

// ─── RENDER-HELPER (eingebettet in die Gremien-Seite) ──────────────────────

function pp_render_rollenvorlagen_section($gremium) {
    $vorlagen = pp_get_rollenvorlagen_fuer_gremium($gremium->id);
    ?>
    <hr>
    <h2>Rollen in „<?php echo esc_html($gremium->name); ?>"</h2>
    <p class="description">Jede Rolle (z. B. Kassier:in) wird einmal als Vorlage mit Zuständigkeiten, Fähigkeiten und Aufgaben definiert. Personen, die die Rolle übernehmen, erben diese Vorlage automatisch — auch bei jährlichem Wechsel.</p>

    <?php foreach ($vorlagen as $rv) : pp_render_rollenvorlage_card($rv, $gremium); endforeach; ?>
    <?php if (empty($vorlagen)) : ?><p><em>Noch keine Rollen definiert.</em></p><?php endif; ?>

    <h3>Neue Rolle definieren</h3>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('pp_save_rollenvorlage'); ?>
        <input type="hidden" name="action" value="pp_save_rollenvorlage">
        <input type="hidden" name="gremium_id" value="<?php echo esc_attr($gremium->id); ?>">
        <table class="form-table">
            <tr><th>Bezeichnung</th><td><input type="text" name="bezeichnung" class="regular-text" placeholder="z. B. Kassier:in" required></td></tr>
            <tr><th>Verantwortlich für</th><td><textarea name="verantwortlich_fuer" rows="3" class="large-text" placeholder="Eine Zuständigkeit pro Zeile"></textarea></td></tr>
            <tr><th>Benötigte Fähigkeiten</th><td><textarea name="benoetigte_faehigkeiten" rows="3" class="large-text" placeholder="Eine Fähigkeit pro Zeile"></textarea></td></tr>
        </table>
        <p><button type="submit" class="button button-primary">Rolle anlegen</button></p>
    </form>
    <?php
}

function pp_render_rollenvorlage_card($rv, $gremium) {
    $verantwortlich = pp_textliste_zu_array($rv->verantwortlich_fuer);
    $faehigkeiten   = pp_textliste_zu_array($rv->benoetigte_faehigkeiten);
    $aufgaben       = pp_get_aufgaben_fuer_rollenvorlage($rv->id);
    $besetzungen    = pp_get_aktuelle_besetzungen($rv->id);
    ?>
    <div class="pp-rollenvorlage-card" id="rollenvorlage-<?php echo esc_attr($rv->id); ?>">
        <div class="pp-rollenvorlage-head">
            <strong><?php echo esc_html($rv->bezeichnung); ?></strong>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=pp_delete_rollenvorlage&id=' . $rv->id . '&gremium_id=' . $gremium->id), 'pp_delete_rollenvorlage')); ?>"
               onclick="return confirm('Rolle inkl. aller Aufgaben-Vorlagen löschen? Bestehende Besetzungen bleiben als reine Person erhalten.');" class="pp-link-danger" style="float:right;">Löschen</a>
        </div>

        <div class="pp-rollenvorlage-grid">
            <div>
                <h4>Verantwortlich für</h4>
                <?php if ($verantwortlich) : ?>
                    <ul><?php foreach ($verantwortlich as $v) echo '<li>' . esc_html($v) . '</li>'; ?></ul>
                <?php else : ?><p><em>–</em></p><?php endif; ?>
            </div>
            <div>
                <h4>Benötigte Fähigkeiten</h4>
                <?php if ($faehigkeiten) : ?>
                    <ul><?php foreach ($faehigkeiten as $f) echo '<li>' . esc_html($f) . '</li>'; ?></ul>
                <?php else : ?><p><em>–</em></p><?php endif; ?>
            </div>
            <div>
                <h4>Aktuell besetzt durch</h4>
                <?php if ($besetzungen) : ?>
                    <ul><?php foreach ($besetzungen as $b) echo '<li>' . esc_html(pp_user_display_name($b->user_id)) . '</li>'; ?></ul>
                <?php else : ?><p><em>nicht besetzt</em></p><?php endif; ?>
                <a href="#" class="pp-toggle-besetzung-form" data-target="besetzung-form-<?php echo esc_attr($rv->id); ?>">+ Person eintragen</a>
                <div id="besetzung-form-<?php echo esc_attr($rv->id); ?>" style="display:none;margin-top:6px;">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('pp_save_rolle'); ?>
                        <input type="hidden" name="action" value="pp_save_rolle">
                        <input type="hidden" name="gremium_id" value="<?php echo esc_attr($gremium->id); ?>">
                        <input type="hidden" name="rollenvorlage_id" value="<?php echo esc_attr($rv->id); ?>">
                        <input type="hidden" name="bezeichnung" value="<?php echo esc_attr($rv->bezeichnung); ?>">
                        <select name="user_id" required>
                            <option value="">Person wählen…</option>
                            <?php foreach (pp_get_moegliche_mitglieder() as $u) : ?>
                                <option value="<?php echo esc_attr($u->ID); ?>"><?php echo esc_html($u->display_name); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label><input type="checkbox" name="vertretungsberechtigt"> vertretungsberechtigt</label>
                        Amtszeit: <input type="date" name="amtszeit_start"> bis <input type="date" name="amtszeit_ende">
                        <button type="submit" class="button">Eintragen</button>
                    </form>
                </div>
            </div>
        </div>

        <h4>Aufgaben dieser Rolle</h4>
        <table class="widefat striped pp-aufgaben-vorlagen-table">
            <thead><tr><th>Titel</th><th>Typ</th><th>Rhythmus / Vorlauf</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($aufgaben as $a) : ?>
                <tr>
                    <td><?php echo esc_html($a->titel); ?></td>
                    <td><?php echo $a->typ === 'event' ? 'Event-Aufgabe' : 'Wiederkehrend'; ?></td>
                    <td>
                        <?php
                        if ($a->typ === 'event') {
                            echo esc_html($a->vorlauf_tage) . ' Tage vorher';
                        } else {
                            $labels = ['taeglich'=>'Täglich','woechentlich'=>'Wöchentlich','monatlich'=>'Monatlich','jaehrlich'=>'Jährlich'];
                            echo esc_html($labels[$a->wiederholung] ?? $a->wiederholung);
                        }
                        ?>
                    </td>
                    <td><a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=pp_delete_rollenvorlage_aufgabe&id=' . $a->id . '&gremium_id=' . $gremium->id), 'pp_delete_rollenvorlage_aufgabe')); ?>" onclick="return confirm('Löschen?');" class="pp-link-danger">Löschen</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($aufgaben)) : ?><tr><td colspan="4"><em>Keine Aufgaben hinterlegt.</em></td></tr><?php endif; ?>
            </tbody>
        </table>

        <details class="pp-aufgabe-vorlage-form">
            <summary>+ Aufgabe hinzufügen</summary>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('pp_save_rollenvorlage_aufgabe'); ?>
                <input type="hidden" name="action" value="pp_save_rollenvorlage_aufgabe">
                <input type="hidden" name="rollenvorlage_id" value="<?php echo esc_attr($rv->id); ?>">
                <input type="hidden" name="gremium_id" value="<?php echo esc_attr($gremium->id); ?>">
                <table class="form-table">
                    <tr><th>Titel</th><td><input type="text" name="titel" class="regular-text" placeholder="z. B. Buchhaltung, Wechselgeld bestellen" required></td></tr>
                    <tr><th>Beschreibung</th><td><textarea name="beschreibung" rows="2" class="large-text"></textarea></td></tr>
                    <tr>
                        <th>Typ</th>
                        <td>
                            <label><input type="radio" name="typ" value="wiederkehrend" checked onclick="document.getElementById('pp-wiederholung-feld').style.display='block';document.getElementById('pp-vorlauf-feld').style.display='none';"> Wiederkehrend (landet automatisch bei der aktuellen Besetzung)</label><br>
                            <label><input type="radio" name="typ" value="event" onclick="document.getElementById('pp-wiederholung-feld').style.display='none';document.getElementById('pp-vorlauf-feld').style.display='block';"> Event-Aufgabe (mit Vorlauf vor einem Termin, wird je Event ausgelöst)</label>
                        </td>
                    </tr>
                    <tr id="pp-wiederholung-feld">
                        <th>Rhythmus</th>
                        <td>
                            <select name="wiederholung">
                                <option value="taeglich">Täglich</option>
                                <option value="woechentlich">Wöchentlich</option>
                                <option value="monatlich" selected>Monatlich</option>
                                <option value="jaehrlich">Jährlich</option>
                            </select>
                        </td>
                    </tr>
                    <tr id="pp-vorlauf-feld" style="display:none;">
                        <th>Vorlauf vor dem Termin (Tage)</th>
                        <td><input type="number" name="vorlauf_tage" value="14" min="0" style="width:80px;"></td>
                    </tr>
                </table>
                <p><button type="submit" class="button">Aufgabe hinzufügen</button></p>
            </form>
        </details>
    </div>
    <?php
}
