<?php
defined('ABSPATH') || exit;

// ─── FORM-HANDLER ──────────────────────────────────────────────────────────

add_action('admin_post_pp_save_protokoll', 'pp_handle_save_protokoll');
function pp_handle_save_protokoll() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_save_protokoll');

    global $wpdb;
    $table = $wpdb->prefix . 'pp_protokolle';
    $id = intval($_POST['id'] ?? 0);
    $gremium_id = intval($_POST['gremium_id'] ?? 0);
    $gremium = pp_get_gremium($gremium_id);

    $data = [
        'gremium_id'        => $gremium_id,
        'titel'             => sanitize_text_field($_POST['titel'] ?? ''),
        'datum'             => !empty($_POST['datum']) ? sanitize_text_field($_POST['datum']) : null,
        'ort'               => sanitize_text_field($_POST['ort'] ?? ''),
        'sichtbarkeit'      => $gremium ? $gremium->oeffentlichkeit : 'vereinsintern',
        'checkin'           => sanitize_textarea_field($_POST['checkin'] ?? ''),
        'organisatorisches' => sanitize_textarea_field($_POST['organisatorisches'] ?? ''),
        'checkout'          => sanitize_textarea_field($_POST['checkout'] ?? ''),
    ];

    if (empty($data['titel']) || !$gremium_id) {
        wp_safe_redirect(admin_url('admin.php?page=pp-protokolle&pp_error=Titel+und+Gremium+sind+Pflicht'));
        exit;
    }

    if ($id > 0) {
        $wpdb->update($table, $data, ['id' => $id]);
    } else {
        $data['erstellt_von'] = get_current_user_id();
        $wpdb->insert($table, $data);
        $id = $wpdb->insert_id;
    }

    wp_safe_redirect(admin_url('admin.php?page=pp-protokolle&view=edit&id=' . $id . '&pp_saved=1'));
    exit;
}

add_action('admin_post_pp_delete_protokoll', 'pp_handle_delete_protokoll');
function pp_handle_delete_protokoll() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_delete_protokoll');
    global $wpdb;
    $id = intval($_GET['id'] ?? 0);
    $wpdb->delete($wpdb->prefix . 'pp_tops', ['protokoll_id' => $id]);
    $wpdb->delete($wpdb->prefix . 'pp_protokolle', ['id' => $id]);
    wp_safe_redirect(admin_url('admin.php?page=pp-protokolle&pp_deleted=1'));
    exit;
}

add_action('admin_post_pp_add_top', 'pp_handle_add_top');
function pp_handle_add_top() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_add_top');

    global $wpdb;
    $protokoll_id = intval($_POST['protokoll_id'] ?? 0);
    $protokoll = pp_get_protokoll($protokoll_id);
    $gremium = $protokoll ? pp_get_gremium($protokoll->gremium_id) : null;

    $data = [
        'protokoll_id'   => $protokoll_id,
        'titel'          => sanitize_text_field($_POST['titel'] ?? ''),
        'typ'            => in_array($_POST['typ'] ?? '', ['standard','wahl','svo_teil_a_review']) ? $_POST['typ'] : 'standard',
        'verfahren'      => in_array($_POST['verfahren'] ?? '', ['konsent','mehrheit','geheime_wahl']) ? $_POST['verfahren'] : ($gremium->standardverfahren ?? 'konsent'),
        'beschreibung'   => sanitize_textarea_field($_POST['beschreibung'] ?? ''),
        'thema_id'       => !empty($_POST['thema_id']) ? intval($_POST['thema_id']) : null,
        'ist_aufgabe'    => isset($_POST['ist_aufgabe']) ? 1 : 0,
        'aufgabe_verantwortlich_user_id' => !empty($_POST['aufgabe_verantwortlich_user_id']) ? intval($_POST['aufgabe_verantwortlich_user_id']) : null,
        'faelligkeitsdatum' => !empty($_POST['faelligkeitsdatum']) ? sanitize_text_field($_POST['faelligkeitsdatum']) : null,
        'ist_termin'     => isset($_POST['ist_termin']) ? 1 : 0,
        'termin_datum'   => !empty($_POST['termin_datum']) ? sanitize_text_field($_POST['termin_datum']) : null,
        'erfordert_mv_bestaetigung' => isset($_POST['erfordert_mv_bestaetigung']) ? 1 : 0,
        'bestaetigung_beschluss_typ' => in_array($_POST['bestaetigung_beschluss_typ'] ?? '', ['mitgliedsaufnahme','mitgliedsausschluss','kreisgruendung','kreisaenderung','kreisaufloesung']) ? $_POST['bestaetigung_beschluss_typ'] : '',
    ];

    if (!empty($data['titel']) && $protokoll_id) {
        $wpdb->insert($wpdb->prefix . 'pp_tops', $data);
    }

    wp_safe_redirect(admin_url('admin.php?page=pp-protokolle&view=edit&id=' . $protokoll_id . '&pp_saved=1'));
    exit;
}

add_action('admin_post_pp_delete_top', 'pp_handle_delete_top');
function pp_handle_delete_top() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_delete_top');
    global $wpdb;
    $id = intval($_GET['id'] ?? 0);
    $protokoll_id = intval($_GET['protokoll_id'] ?? 0);
    $wpdb->delete($wpdb->prefix . 'pp_tops', ['id' => $id]);
    wp_safe_redirect(admin_url('admin.php?page=pp-protokolle&view=edit&id=' . $protokoll_id . '&pp_deleted=1'));
    exit;
}

/** Bringt einen TOP im Konsent-Ablauf einen Schritt weiter:
 *  vorstellung → verstaendnisfragen → meinungsrunde → konsentrunde → beschlossen */
add_action('admin_post_pp_top_advance', 'pp_handle_top_advance');
function pp_handle_top_advance() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_top_advance');

    global $wpdb;
    $top_id = intval($_POST['top_id'] ?? 0);
    $protokoll_id = intval($_POST['protokoll_id'] ?? 0);
    $table = $wpdb->prefix . 'pp_tops';
    $top = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $top_id));

    if ($top) {
        $reihenfolge = ['vorstellung', 'verstaendnisfragen', 'meinungsrunde', 'konsentrunde', 'beschlossen'];
        $aktuell = array_search($top->konsent_status, $reihenfolge, true);
        $naechster = ($aktuell !== false && isset($reihenfolge[$aktuell + 1])) ? $reihenfolge[$aktuell + 1] : $top->konsent_status;

        $update = ['konsent_status' => $naechster];
        if ($naechster === 'beschlossen' && !empty($_POST['beschluss'])) {
            $update['beschluss'] = sanitize_textarea_field($_POST['beschluss']);
        }
        $wpdb->update($table, $update, ['id' => $top_id]);
    }

    wp_safe_redirect(admin_url('admin.php?page=pp-protokolle&view=edit&id=' . $protokoll_id));
    exit;
}

/** Einwand in der Konsentrunde: TOP geht zurück auf "wird überarbeitet". */
add_action('admin_post_pp_top_einwand', 'pp_handle_top_einwand');
function pp_handle_top_einwand() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_top_einwand');

    global $wpdb;
    $top_id = intval($_POST['top_id'] ?? 0);
    $protokoll_id = intval($_POST['protokoll_id'] ?? 0);
    $begruendung = sanitize_textarea_field($_POST['begruendung'] ?? '');

    if ($top_id && $begruendung) {
        $wpdb->insert($wpdb->prefix . 'pp_einwaende', [
            'top_id'      => $top_id,
            'user_id'     => get_current_user_id(),
            'begruendung' => $begruendung,
        ]);
        $wpdb->update($wpdb->prefix . 'pp_tops', ['konsent_status' => 'einwand_offen'], ['id' => $top_id]);
    }

    wp_safe_redirect(admin_url('admin.php?page=pp-protokolle&view=edit&id=' . $protokoll_id));
    exit;
}

/** Nach Überarbeitung: TOP erneut zur Konsentrunde stellen. */
add_action('admin_post_pp_top_erneut_vorlegen', 'pp_handle_top_erneut_vorlegen');
function pp_handle_top_erneut_vorlegen() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_top_erneut_vorlegen');
    global $wpdb;
    $top_id = intval($_POST['top_id'] ?? 0);
    $protokoll_id = intval($_POST['protokoll_id'] ?? 0);
    $neuer_vorschlag = sanitize_textarea_field($_POST['beschreibung'] ?? '');

    $update = ['konsent_status' => 'konsentrunde'];
    if ($neuer_vorschlag) $update['beschreibung'] = $neuer_vorschlag;
    $wpdb->update($wpdb->prefix . 'pp_tops', $update, ['id' => $top_id]);
    $wpdb->update($wpdb->prefix . 'pp_einwaende', ['status' => 'geklaert'], ['top_id' => $top_id, 'status' => 'offen']);

    wp_safe_redirect(admin_url('admin.php?page=pp-protokolle&view=edit&id=' . $protokoll_id));
    exit;
}

/** Protokoll-Transformation beim Abschluss: Themen, Aufgaben, Termine,
 *  Bestätigungspflichtige Beschlüsse werden aus den TOPs generiert. */
add_action('admin_post_pp_abschliessen_protokoll', 'pp_handle_abschliessen_protokoll');
function pp_handle_abschliessen_protokoll() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_abschliessen_protokoll');

    global $wpdb;
    $protokoll_id = intval($_POST['protokoll_id'] ?? 0);
    $protokoll = pp_get_protokoll($protokoll_id);
    if (!$protokoll) wp_die('Protokoll nicht gefunden.');

    $tops = pp_get_tops_fuer_protokoll($protokoll_id);

    foreach ($tops as $top) {
        if ($top->konsent_status !== 'beschlossen') continue;

        // Thema aktualisieren
        if ($top->thema_id) {
            $wpdb->update($wpdb->prefix . 'pp_themen', ['status' => 'abgeschlossen'], ['id' => $top->thema_id]);
        }

        // Aufgabe erzeugen
        if ($top->ist_aufgabe) {
            $wpdb->insert($wpdb->prefix . 'pp_aufgaben', [
                'titel'                      => $top->titel,
                'beschreibung'               => $top->beschluss ?: $top->beschreibung,
                'verantwortlich_user_id'     => $top->aufgabe_verantwortlich_user_id,
                'verantwortliches_gremium_id' => $protokoll->gremium_id,
                'faelligkeitsdatum'          => $top->faelligkeitsdatum,
                'quelle_top_id'              => $top->id,
            ]);
        }

        // Termin erzeugen
        if ($top->ist_termin && $top->termin_datum) {
            $wpdb->insert($wpdb->prefix . 'pp_termine', [
                'titel'         => $top->titel,
                'datum'         => $top->termin_datum,
                'gremium_id'    => $protokoll->gremium_id,
                'quelle_top_id' => $top->id,
            ]);
        }

        // Bestätigungspflichtiger Beschluss (Leitungskreis → nächste MV)
        if ($top->erfordert_mv_bestaetigung && $top->bestaetigung_beschluss_typ) {
            $wpdb->insert($wpdb->prefix . 'pp_bestaetigungen', [
                'quelle_gremium_id'  => $protokoll->gremium_id,
                'beschluss_typ'      => $top->bestaetigung_beschluss_typ,
                'beschreibung'       => $top->titel . ' — ' . ($top->beschluss ?: $top->beschreibung),
                'quelle_top_id'      => $top->id,
                'entscheidungsdatum' => $protokoll->datum,
            ]);
        }
    }

    $wpdb->update($wpdb->prefix . 'pp_protokolle', ['status' => 'abgeschlossen'], ['id' => $protokoll_id]);

    wp_safe_redirect(admin_url('admin.php?page=pp-protokolle&view=edit&id=' . $protokoll_id . '&pp_abgeschlossen=1'));
    exit;
}

// ─── SEITEN ──────────────────────────────────────────────────────────────

function pp_render_protokolle_page() {
    if (!pp_can_manage()) wp_die('Keine Berechtigung.');
    $view = sanitize_text_field($_GET['view'] ?? 'list');
    if ($view === 'edit') {
        pp_render_protokoll_edit();
    } else {
        pp_render_protokoll_list();
    }
}

function pp_render_protokoll_list() {
    global $wpdb;
    $status_filter = sanitize_text_field($_GET['status'] ?? '');
    $where = $status_filter ? $wpdb->prepare('WHERE p.status = %s', $status_filter) : '';
    $rows = $wpdb->get_results("
        SELECT p.*, g.name AS gremium_name, g.typ AS gremium_typ
        FROM {$wpdb->prefix}pp_protokolle p
        LEFT JOIN {$wpdb->prefix}pp_gremien g ON g.id = p.gremium_id
        $where
        ORDER BY p.datum DESC, p.id DESC
    ");
    ?>
    <div class="wrap pp-wrap">
        <h1>Protokolle
            <a href="<?php echo esc_url(admin_url('admin.php?page=pp-protokolle&view=edit&id=0')); ?>" class="page-title-action">Neues Protokoll</a>
        </h1>
        <?php if (isset($_GET['pp_deleted'])) echo '<div class="notice notice-success"><p>Gelöscht.</p></div>'; ?>

        <table class="widefat striped">
            <thead><tr><th>Titel</th><th>Gremium</th><th>Datum</th><th>Status</th><th>Sichtbarkeit</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $p) : ?>
                <tr>
                    <td><a href="<?php echo esc_url(admin_url('admin.php?page=pp-protokolle&view=edit&id=' . $p->id)); ?>"><?php echo esc_html($p->titel); ?></a></td>
                    <td><?php echo esc_html($p->gremium_name . ' (' . pp_gremientyp_label($p->gremium_typ) . ')'); ?></td>
                    <td><?php echo esc_html($p->datum ?: '–'); ?></td>
                    <td><?php echo $p->status === 'abgeschlossen' ? '✅ Abgeschlossen' : '📝 Entwurf'; ?></td>
                    <td><?php echo esc_html(pp_oeffentlichkeit_label($p->sichtbarkeit)); ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($rows)) : ?>
                <tr><td colspan="5">Noch keine Protokolle.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function pp_render_protokoll_edit() {
    $id = intval($_GET['id'] ?? 0);
    $protokoll = $id ? pp_get_protokoll($id) : null;
    $gremien = pp_get_gremien();
    $tops = $id ? pp_get_tops_fuer_protokoll($id) : [];
    $themen = pp_get_offene_themen_liste();
    ?>
    <div class="wrap pp-wrap">
        <h1><?php echo $protokoll ? 'Protokoll bearbeiten' : 'Neues Protokoll'; ?></h1>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=pp-protokolle')); ?>">&larr; Zur Liste</a></p>

        <?php if (isset($_GET['pp_saved'])) echo '<div class="notice notice-success"><p>Gespeichert.</p></div>'; ?>
        <?php if (isset($_GET['pp_abgeschlossen'])) echo '<div class="notice notice-success"><p>Protokoll abgeschlossen. Beschlüsse, Aufgaben, Termine und ggf. Bestätigungen für die MV wurden erzeugt.</p></div>'; ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('pp_save_protokoll'); ?>
            <input type="hidden" name="action" value="pp_save_protokoll">
            <input type="hidden" name="id" value="<?php echo esc_attr($protokoll->id ?? 0); ?>">
            <table class="form-table">
                <tr>
                    <th><label>Gremium</label></th>
                    <td>
                        <select name="gremium_id" required <?php echo $protokoll ? 'disabled' : ''; ?>>
                            <option value="">— wählen —</option>
                            <?php foreach ($gremien as $g) : ?>
                                <option value="<?php echo esc_attr($g->id); ?>" <?php selected($protokoll->gremium_id ?? 0, $g->id); ?>><?php echo esc_html($g->name . ' (' . pp_gremientyp_label($g->typ) . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($protokoll) : ?><input type="hidden" name="gremium_id" value="<?php echo esc_attr($protokoll->gremium_id); ?>"><?php endif; ?>
                    </td>
                </tr>
                <tr><th><label>Titel</label></th><td><input type="text" name="titel" required class="regular-text" value="<?php echo esc_attr($protokoll->titel ?? ''); ?>"></td></tr>
                <tr><th><label>Datum</label></th><td><input type="date" name="datum" value="<?php echo esc_attr($protokoll->datum ?? ''); ?>"></td></tr>
                <tr><th><label>Ort</label></th><td><input type="text" name="ort" class="regular-text" value="<?php echo esc_attr($protokoll->ort ?? ''); ?>"></td></tr>
                <tr><th><label>Check-In</label></th><td><textarea name="checkin" rows="2" class="large-text"><?php echo esc_textarea($protokoll->checkin ?? ''); ?></textarea></td></tr>
                <tr><th><label>Organisatorisches</label></th><td><textarea name="organisatorisches" rows="2" class="large-text"><?php echo esc_textarea($protokoll->organisatorisches ?? ''); ?></textarea></td></tr>
                <tr><th><label>Check-Out</label></th><td><textarea name="checkout" rows="2" class="large-text"><?php echo esc_textarea($protokoll->checkout ?? ''); ?></textarea></td></tr>
            </table>
            <p><button type="submit" class="button button-primary">Kopfdaten speichern</button></p>
        </form>

        <?php if ($protokoll) : ?>
            <hr>
            <h2>Tagesordnung</h2>

            <?php foreach ($tops as $top) : pp_render_top_row($top, $protokoll); endforeach; ?>
            <?php if (empty($tops)) : ?><p><em>Noch keine TOPs.</em></p><?php endif; ?>

            <h3>Neuen TOP hinzufügen</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-top-form">
                <?php wp_nonce_field('pp_add_top'); ?>
                <input type="hidden" name="action" value="pp_add_top">
                <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($protokoll->id); ?>">
                <table class="form-table">
                    <tr><th>Titel</th><td><input type="text" name="titel" required class="regular-text"></td></tr>
                    <tr>
                        <th>Typ</th>
                        <td>
                            <select name="typ">
                                <option value="standard">Standard</option>
                                <option value="wahl">Offene Wahl</option>
                                <option value="svo_teil_a_review">SVO Teil A – Durchsicht</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Verfahren</th>
                        <td>
                            <select name="verfahren">
                                <option value="konsent">Konsent (Standard)</option>
                                <option value="mehrheit">Mehrheitsentscheid</option>
                                <option value="geheime_wahl">Geheime Wahl</option>
                            </select>
                        </td>
                    </tr>
                    <tr><th>Beschreibung / Vorschlag</th><td><textarea name="beschreibung" rows="2" class="large-text"></textarea></td></tr>
                    <tr>
                        <th>Bezug zum Themenspeicher</th>
                        <td>
                            <select name="thema_id">
                                <option value="">— kein Bezug —</option>
                                <?php foreach ($themen as $th) : ?>
                                    <option value="<?php echo esc_attr($th->id); ?>"><?php echo esc_html($th->titel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>Ergebnis-Verknüpfung</th>
                        <td>
                            <label><input type="checkbox" name="ist_aufgabe" value="1" onclick="document.getElementById('pp-aufgabe-felder').style.display=this.checked?'block':'none';"> erzeugt bei Beschluss eine Aufgabe</label>
                            <div id="pp-aufgabe-felder" style="display:none;margin:6px 0;">
                                Verantwortlich:
                                <select name="aufgabe_verantwortlich_user_id">
                                    <option value="">—</option>
                                    <?php foreach (pp_get_moegliche_mitglieder() as $u) : ?>
                                        <option value="<?php echo esc_attr($u->ID); ?>"><?php echo esc_html($u->display_name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                Fällig am: <input type="date" name="faelligkeitsdatum">
                            </div>
                            <br>
                            <label><input type="checkbox" name="ist_termin" value="1" onclick="document.getElementById('pp-termin-feld').style.display=this.checked?'block':'none';"> erzeugt bei Beschluss einen Termin</label>
                            <div id="pp-termin-feld" style="display:none;margin:6px 0;">
                                Termin: <input type="datetime-local" name="termin_datum">
                            </div>
                            <br>
                            <label><input type="checkbox" name="erfordert_mv_bestaetigung" value="1" onclick="document.getElementById('pp-bestaetigung-feld').style.display=this.checked?'block':'none';"> Beschluss braucht Bestätigung der nächsten MV (nur bei Leitungskreis-TOPs)</label>
                            <div id="pp-bestaetigung-feld" style="display:none;margin:6px 0;">
                                <select name="bestaetigung_beschluss_typ">
                                    <option value="mitgliedsaufnahme">Mitgliedsaufnahme</option>
                                    <option value="mitgliedsausschluss">Mitgliedsausschluss</option>
                                    <option value="kreisgruendung">Kreisgründung</option>
                                    <option value="kreisaenderung">Kreisänderung</option>
                                    <option value="kreisaufloesung">Kreisauflösung</option>
                                </select>
                            </div>
                        </td>
                    </tr>
                </table>
                <p><button type="submit" class="button">TOP hinzufügen</button></p>
            </form>

            <?php if ($protokoll->status !== 'abgeschlossen') : ?>
                <hr>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Protokoll abschließen? Beschlüsse, Aufgaben, Termine und Bestätigungen werden jetzt erzeugt und können danach nicht mehr automatisch geändert werden.');">
                    <?php wp_nonce_field('pp_abschliessen_protokoll'); ?>
                    <input type="hidden" name="action" value="pp_abschliessen_protokoll">
                    <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($protokoll->id); ?>">
                    <p><button type="submit" class="button button-primary button-hero">✅ Protokoll abschließen</button></p>
                </form>
            <?php else : ?>
                <p><strong>Dieses Protokoll ist abgeschlossen.</strong></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}

/** Rendert eine einzelne TOP-Zeile mit Konsent-Fortschritt und Aktions-Buttons. */
function pp_render_top_row($top, $protokoll) {
    global $wpdb;
    $reihenfolge = ['vorstellung', 'verstaendnisfragen', 'meinungsrunde', 'konsentrunde', 'beschlossen'];
    ?>
    <div class="pp-top-card pp-top-status-<?php echo esc_attr($top->konsent_status); ?>">
        <div class="pp-top-head">
            <strong><?php echo esc_html($top->titel); ?></strong>
            <span class="pp-badge"><?php echo esc_html(pp_top_typ_label($top->typ)); ?></span>
            <span class="pp-badge"><?php echo esc_html(pp_verfahren_label($top->verfahren)); ?></span>
            <span class="pp-badge pp-badge-status"><?php echo esc_html(pp_konsent_status_label($top->konsent_status)); ?></span>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=pp_delete_top&id=' . $top->id . '&protokoll_id=' . $protokoll->id), 'pp_delete_top')); ?>"
               onclick="return confirm('TOP löschen?');" class="pp-link-danger" style="float:right;">Löschen</a>
        </div>

        <?php if ($top->beschreibung) : ?><p><?php echo esc_html($top->beschreibung); ?></p><?php endif; ?>

        <?php if ($top->konsent_status === 'einwand_offen') :
            $einwaende = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pp_einwaende WHERE top_id = %d AND status='offen' ORDER BY erstellt_am DESC", $top->id));
        ?>
            <div class="pp-einwand-box">
                <strong>⚠️ Offene Einwände:</strong>
                <ul>
                    <?php foreach ($einwaende as $e) : ?>
                        <li><?php echo esc_html(pp_user_display_name($e->user_id)); ?>: <?php echo esc_html($e->begruendung); ?></li>
                    <?php endforeach; ?>
                </ul>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('pp_top_erneut_vorlegen'); ?>
                    <input type="hidden" name="action" value="pp_top_erneut_vorlegen">
                    <input type="hidden" name="top_id" value="<?php echo esc_attr($top->id); ?>">
                    <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($protokoll->id); ?>">
                    <textarea name="beschreibung" rows="2" class="large-text" placeholder="Überarbeiteter Vorschlag (optional)"><?php echo esc_textarea($top->beschreibung); ?></textarea>
                    <button type="submit" class="button">Überarbeiteten Vorschlag erneut zur Konsentrunde stellen</button>
                </form>
            </div>
        <?php elseif ($top->konsent_status !== 'beschlossen') : ?>
            <div class="pp-top-actions">
                <?php if ($top->konsent_status === 'konsentrunde') : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
                        <?php wp_nonce_field('pp_top_advance'); ?>
                        <input type="hidden" name="action" value="pp_top_advance">
                        <input type="hidden" name="top_id" value="<?php echo esc_attr($top->id); ?>">
                        <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($protokoll->id); ?>">
                        <input type="text" name="beschluss" placeholder="Beschlusstext" class="regular-text">
                        <button type="submit" class="button button-primary">✅ Kein Einwand – beschließen</button>
                    </form>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                        <?php wp_nonce_field('pp_top_einwand'); ?>
                        <input type="hidden" name="action" value="pp_top_einwand">
                        <input type="hidden" name="top_id" value="<?php echo esc_attr($top->id); ?>">
                        <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($protokoll->id); ?>">
                        <input type="text" name="begruendung" placeholder="Begründung für Einwand" required>
                        <button type="submit" class="button">⚠️ Einwand einlegen</button>
                    </form>
                <?php else : ?>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('pp_top_advance'); ?>
                        <input type="hidden" name="action" value="pp_top_advance">
                        <input type="hidden" name="top_id" value="<?php echo esc_attr($top->id); ?>">
                        <input type="hidden" name="protokoll_id" value="<?php echo esc_attr($protokoll->id); ?>">
                        <button type="submit" class="button">Weiter zu: <?php
                            $aktuell = array_search($top->konsent_status, $reihenfolge, true);
                            echo esc_html(pp_konsent_status_label($reihenfolge[$aktuell + 1]));
                        ?></button>
                    </form>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <p class="pp-beschluss"><strong>Beschluss:</strong> <?php echo esc_html($top->beschluss ?: '–'); ?></p>
        <?php endif; ?>
    </div>
    <?php
}

function pp_get_offene_themen_liste() {
    global $wpdb;
    return $wpdb->get_results("SELECT id, titel FROM {$wpdb->prefix}pp_themen WHERE status != 'abgeschlossen' ORDER BY titel");
}
