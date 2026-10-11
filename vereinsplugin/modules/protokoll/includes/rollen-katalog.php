<?php
/**
 * Rollen-Katalog: fertige Vorlagen für typische Rollen in einem Kreis
 * (Kassier:in, Sprecher:in, Amt für Inneres, Hausmeister:in, Amt für
 * Öffentlichkeitsarbeit). Unter „Kreise & Rollen“ → Kreis → „Rolle aus
 * Vorlage anlegen“ lassen sich eine oder mehrere auf einmal übernehmen –
 * mit Aufgaben, Skills und den regelmäßigen bzw. veranstaltungsbezogenen
 * Rollen-Aufgaben. Danach ist alles wie eine selbst angelegte Rolle frei
 * änderbar. Eigene Vorlagen per Filter `pp_rollen_katalog`.
 */
defined('ABSPATH') || exit;

/**
 * @return array slug => [ bezeichnung, verantwortlich (Zeilen), faehigkeiten (Zeilen),
 *                        kasse (bool), aufgaben: [ [titel, beschreibung, typ, wiederholung|vorlauf_tage] ] ]
 */
function pp_rollen_katalog() {
    $k = [
        'kassier' => [
            'bezeichnung'  => 'Kassier:in',
            'kasse'        => true,
            'verantwortlich' => [
                'Kasse und Konto des Kreises führen',
                'Einnahmen und Ausgaben zeitnah buchen, Belege ablegen',
                'Budgets planen und im Blick behalten',
                'Auslagen prüfen, genehmigen und erstatten',
                'Kassenbericht für Kreisversammlung und Vorstand erstellen',
                'Kassenprüfung vorbereiten',
            ],
            'faehigkeiten' => [
                'Sorgfalt mit Zahlen',
                'Grundkenntnisse Buchhaltung (EÜR)',
                'Verschwiegenheit',
                'Zuverlässigkeit bei Fristen',
            ],
            'aufgaben' => [
                ['Kontoauszüge importieren und Buchungen prüfen', 'Bank-CSV in der Buchhaltung importieren, offene Belege und Auslagen zuordnen.', 'wiederkehrend', 'monatlich'],
                ['Offene Auslagen bearbeiten', 'Eingereichte Auslagen prüfen, genehmigen und erstatten.', 'wiederkehrend', 'woechentlich'],
                ['Budgetstand an den Kreis berichten', 'Kurzer Stand der Budgets in der Kreisversammlung.', 'wiederkehrend', 'monatlich'],
                ['Kassenbericht und Kassenprüfung', 'Jahresabschluss vorbereiten, Kassenprüfer:innen Unterlagen geben.', 'wiederkehrend', 'jaehrlich'],
                ['Kostenkalkulation der Veranstaltung', 'Einnahmen und Ausgaben planen, Budget anlegen.', 'event', 21],
                ['Wechselgeld bestellen', 'Wechselgeld bei der Bank bestellen bzw. abheben.', 'event', 7],
                ['Kasse abrechnen, Z-Bon buchen', 'Nach der Veranstaltung Kasse zählen und Z-Bon in der Buchhaltung buchen.', 'event', 0],
            ],
        ],
        'sprecher' => [
            'bezeichnung'  => 'Sprecher:in',
            'verantwortlich' => [
                'Kreis nach außen und im Leitungskreis vertreten',
                'Kreisversammlungen einberufen, Tagesordnung vorbereiten und moderieren',
                'Informationen zwischen Kreis, Vorstand und anderen Kreisen weitergeben',
                'Beschlüsse und offene Aufgaben im Blick behalten',
                'Ansprechperson für neue Mitglieder',
            ],
            'faehigkeiten' => [
                'Moderation und Gesprächsführung',
                'Überblick über die Themen des Kreises',
                'Zuverlässige Kommunikation',
                'Konflikte ansprechen können',
            ],
            'aufgaben' => [
                ['Kreisversammlung einberufen', 'Termin festlegen, Einladung mit Tagesordnung verschicken.', 'wiederkehrend', 'monatlich'],
                ['Bericht für den Leitungskreis', 'Was lief, was steht an, was braucht der Kreis?', 'wiederkehrend', 'monatlich'],
                ['Jahresrückblick und Planung', 'Rückblick für die Mitgliederversammlung, Ziele fürs nächste Jahr.', 'wiederkehrend', 'jaehrlich'],
                ['Veranstaltung im Kreis abstimmen', 'Wer macht was? Rollen und Schichtplan klären.', 'event', 28],
            ],
        ],
        'inneres' => [
            'bezeichnung'  => 'Amt für Inneres',
            'verantwortlich' => [
                'Einkäufe für Verein und Veranstaltungen planen und erledigen',
                'Lager, Vorräte und Getränke verwalten, Bestand prüfen',
                'Material und Ausstattung ausleihen und zurückgeben',
                'Lieferant:innen und Bestellungen koordinieren',
                'Belege der Einkäufe einreichen',
            ],
            'faehigkeiten' => [
                'Organisationstalent',
                'Überblick über Bestände',
                'Führerschein / Transportmöglichkeit (hilfreich)',
                'Preisbewusstsein',
            ],
            'aufgaben' => [
                ['Bestand prüfen und Einkaufsliste schreiben', 'Lager, Getränke und Verbrauchsmaterial durchgehen; Wünsche aus der Wunschliste einplanen.', 'wiederkehrend', 'monatlich'],
                ['Belege der Einkäufe einreichen', 'Kassenzettel als Auslage oder Beleg im Mitgliederbereich hochladen.', 'wiederkehrend', 'woechentlich'],
                ['Inventur', 'Bestand zählen und für die Kasse dokumentieren.', 'wiederkehrend', 'jaehrlich'],
                ['Einkauf und Bestellungen für die Veranstaltung', 'Getränke, Lebensmittel und Material bestellen bzw. einkaufen.', 'event', 10],
                ['Material und Ausstattung organisieren', 'Leihmaterial reservieren, Transport klären.', 'event', 14],
                ['Rückgabe und Bestand nach der Veranstaltung', 'Leihgut zurückgeben, Pfand abrechnen, Reste einlagern.', 'event', 0],
            ],
        ],
        'hausmeister' => [
            'bezeichnung'  => 'Hausmeister:in',
            'verantwortlich' => [
                'Räume, Gebäude und Außengelände in Schuss halten',
                'Kleine Reparaturen erledigen oder beauftragen',
                'Schlüssel verwalten und ausgeben',
                'Sicherheit im Blick: Feuerlöscher, Erste-Hilfe-Kasten, Fluchtwege',
                'Müll, Reinigung und Winterdienst organisieren',
            ],
            'faehigkeiten' => [
                'Handwerkliches Geschick',
                'Gespür für Mängel und Sicherheit',
                'Zuverlässige Erreichbarkeit',
            ],
            'aufgaben' => [
                ['Rundgang: Mängel aufnehmen', 'Räume und Gelände durchgehen, Mängel notieren und beheben oder melden.', 'wiederkehrend', 'monatlich'],
                ['Schlüsselliste prüfen', 'Wer hat welchen Schlüssel? Liste aktualisieren.', 'wiederkehrend', 'jaehrlich'],
                ['Sicherheitscheck', 'Feuerlöscher, Erste-Hilfe-Kasten, Notausgänge und Fluchtwege prüfen.', 'wiederkehrend', 'jaehrlich'],
                ['Müll und Reinigung', 'Müllabfuhr, Reinigungsplan und Verbrauchsmaterial prüfen.', 'wiederkehrend', 'woechentlich'],
                ['Räume und Technik vorbereiten', 'Strom, Licht, Toiletten, Aufbau und Schlüsselübergabe für die Veranstaltung klären.', 'event', 3],
                ['Nach der Veranstaltung: Räume abnehmen', 'Schäden aufnehmen, Schlüssel zurück, abschließen.', 'event', 0],
            ],
        ],
        'oeffentlichkeit' => [
            'bezeichnung'  => 'Amt für Öffentlichkeitsarbeit',
            'verantwortlich' => [
                'Veranstaltungen bewerben (Social Media, Presse, Website, Newsletter, Aushänge)',
                'Website und Social-Media-Kanäle pflegen',
                'Pressekontakt und Presseverteiler',
                'Bilder und Texte für Plakate und Posts erstellen bzw. koordinieren',
                'Fotos von Veranstaltungen sammeln (Einverständnisse beachten)',
            ],
            'faehigkeiten' => [
                'Gutes Schreiben',
                'Gestaltung (z. B. Canva)',
                'Social Media',
                'Gespür für Zielgruppen',
                'Datenschutz und Bildrechte kennen',
            ],
            'aufgaben' => [
                ['Redaktionsplan für den Monat', 'Was wird wann über welchen Kanal veröffentlicht?', 'wiederkehrend', 'monatlich'],
                ['Website und Termine aktuell halten', 'Veranstaltungsseiten, Öffnungszeiten und Kontaktdaten prüfen.', 'wiederkehrend', 'monatlich'],
                ['Presseverteiler pflegen', 'Kontakte prüfen und ergänzen.', 'wiederkehrend', 'jaehrlich'],
                ['Veranstaltung anlegen und Material erstellen', 'Unter „Veranstaltungen“ Daten, Texte und Bildformate (Canva) vorbereiten.', 'event', 28],
                ['Ankündigung versenden / Kampagne einplanen', 'Kanal-Checkliste abarbeiten bzw. Zeitplan einplanen lassen.', 'event', 14],
                ['Plakate und Flyer aufhängen', 'Aushänge drucken und verteilen.', 'event', 14],
                ['Rückblick mit Fotos posten', 'Nach der Veranstaltung Danke sagen, Fotos teilen.', 'event', 0],
            ],
        ],
    ];
    return apply_filters('pp_rollen_katalog', $k);
}

/**
 * Rolle aus dem Katalog in einem Kreis anlegen.
 * @return int ID der Rollenvorlage (0 = schon vorhanden / unbekannt)
 */
function pp_rolle_aus_katalog_anlegen($gremium_id, $slug) {
    global $wpdb;
    $k = pp_rollen_katalog()[$slug] ?? null;
    if (!$k || !$gremium_id) return 0;
    $t = $wpdb->prefix . 'pp_rollenvorlagen';
    // Gleichnamige Rolle im Kreis nicht doppelt anlegen.
    if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE gremium_id = %d AND bezeichnung = %s", $gremium_id, $k['bezeichnung']))) {
        return 0;
    }
    $daten = [
        'gremium_id'              => (int) $gremium_id,
        'bezeichnung'             => $k['bezeichnung'],
        'verantwortlich_fuer'     => implode("\n", $k['verantwortlich']),
        'benoetigte_faehigkeiten' => implode("\n", $k['faehigkeiten']),
    ];
    if (!empty($k['kasse']) && function_exists('vp_kreis_col_exists') && vp_kreis_col_exists($t, 'kasse')) {
        $daten['kasse'] = 1;
    }
    $wpdb->insert($t, $daten);
    $id = (int) $wpdb->insert_id;
    foreach ((array) ($k['aufgaben'] ?? []) as $a) {
        $event = 'event' === $a[2];
        $wpdb->insert($wpdb->prefix . 'pp_rollenvorlagen_aufgaben', [
            'rollenvorlage_id' => $id,
            'titel'            => $a[0],
            'beschreibung'     => $a[1],
            'typ'              => $event ? 'event' : 'wiederkehrend',
            'wiederholung'     => $event ? null : $a[3],
            'vorlauf_tage'     => $event ? (int) $a[3] : null,
        ]);
    }
    return $id;
}

add_action('admin_post_pp_front_rollen_katalog', 'pp_handle_front_rollen_katalog');
function pp_handle_front_rollen_katalog() {
    if (!is_user_logged_in() || !pp_can_manage()) wp_die('Keine Berechtigung.');
    check_admin_referer('pp_front_rollen_katalog');
    $gremium_id = intval($_POST['gremium_id'] ?? 0);
    $neu = $schon = 0;
    foreach ((array) ($_POST['rollen'] ?? []) as $slug) {
        if (pp_rolle_aus_katalog_anlegen($gremium_id, sanitize_key(wp_unslash($slug)))) {
            $neu++;
        } else {
            $schon++;
        }
    }
    $args = ['pp_view' => 'kreis', 'id' => $gremium_id, 'pp_saved' => '1'];
    if ($schon) {
        $args['pp_error'] = rawurlencode(sprintf('%d Rolle(n) gab es in diesem Kreis schon – nicht doppelt angelegt.', $schon));
    }
    pp_front_redirect($_POST['pp_return'] ?? '', $args);
}

/** Auswahl „Rolle aus Vorlage anlegen“ für einen Kreis. */
function pp_render_rollen_katalog($kreis) {
    global $wpdb;
    $vorhanden = array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
        "SELECT bezeichnung FROM {$wpdb->prefix}pp_rollenvorlagen WHERE gremium_id = %d", $kreis->id
    )));
    $katalog = pp_rollen_katalog();
    if (!$katalog) return;
    ?>
    <details class="pp-rollen-katalog" open>
        <summary class="pp-details-summary"><strong>Rolle aus Vorlage anlegen</strong> <span class="pp-meta">mit Aufgaben, Skills und Rollen-Aufgaben – danach frei anpassbar</span></summary>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="pp-form">
            <?php wp_nonce_field('pp_front_rollen_katalog'); ?>
            <input type="hidden" name="action" value="pp_front_rollen_katalog">
            <input type="hidden" name="gremium_id" value="<?php echo esc_attr($kreis->id); ?>">
            <?php pp_front_return_field(); ?>
            <?php foreach ($katalog as $slug => $k) :
                $da = in_array($k['bezeichnung'], $vorhanden, true);
                $n_regel = count(array_filter($k['aufgaben'] ?? [], fn($a) => 'event' !== $a[2]));
                $n_event = count($k['aufgaben'] ?? []) - $n_regel;
                ?>
                <label class="pp-checkbox-label" style="<?php echo $da ? 'opacity:.55' : ''; ?>">
                    <span><input type="checkbox" name="rollen[]" value="<?php echo esc_attr($slug); ?>" <?php disabled($da); ?>>
                        <strong><?php echo esc_html($k['bezeichnung']); ?></strong>
                        <?php if (!empty($k['kasse'])) : ?><span class="pp-meta">· führt die Kreiskasse</span><?php endif; ?>
                        <?php if ($da) : ?><span class="pp-meta">· schon vorhanden</span><?php endif; ?>
                    </span>
                    <span class="pp-meta"><?php echo esc_html(implode(' · ', array_slice($k['verantwortlich'], 0, 3)) . (count($k['verantwortlich']) > 3 ? ' …' : '')); ?></span>
                    <span class="pp-meta"><?php echo esc_html(sprintf('%d regelmäßige Aufgaben, %d vor Veranstaltungen', $n_regel, $n_event)); ?></span>
                </label>
            <?php endforeach; ?>
            <div class="pp-form-actions">
                <button type="submit" class="pp-btn pp-btn-primary">Ausgewählte Rollen anlegen</button>
            </div>
        </form>
    </details>
    <?php
}
