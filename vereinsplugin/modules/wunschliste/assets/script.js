/* Vereins-Wunschliste – Frontend JS */

/**
 * Scrollt sicher zu einem Panel, falls es auf der aktuellen Seite existiert.
 * Diese Datei wird global auf jeder Seite geladen, aber nicht jedes Panel
 * (Wunschliste, Schichtplan, Voting) existiert auf jeder Seite. Ein direkter
 * .offset()-Aufruf auf ein nicht vorhandenes Element wirft sonst einen Fehler,
 * der die komplette restliche Skriptausführung abbricht (auch für Buttons,
 * die mit dem fehlenden Element gar nichts zu tun haben).
 */
function wlScrollToPanel(selector) {
    var $el = jQuery(selector);
    if ($el.length === 0) return;
    jQuery('html, body').animate({ scrollTop: $el.offset().top - 80 }, 300);
}

/** Setzt ein Formular zurück, falls es auf der aktuellen Seite existiert. */
function wlResetForm(selector) {
    var el = jQuery(selector).get(0);
    if (el) el.reset();
}

(function ($) {
    'use strict';

    // ─── FILTER ──────────────────────────────────────────────────────────────

    $(document).on('click', '.wl-filter-btn', function () {
        $('.wl-filter-btn').removeClass('active');
        $(this).addClass('active');

        var filter = $(this).data('filter');
        $('#wl-grid .wl-card').each(function () {
            if (!filter || $(this).data('kategorie') === filter) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });

    // ─── MODAL ÖFFNEN ────────────────────────────────────────────────────────

    $(document).on('click', '.wl-btn-spenden', function () {
        var titel  = $(this).data('titel');
        var betrag = parseFloat($(this).data('betrag'));
        var id     = $(this).data('id');

        $('#wl-modal-titel').text(titel);
        $('#wl-modal-zweck').text('Spende: ' + titel);
        $('#wl-modal-betrag').text(betrag > 0 ? betrag.toFixed(2).replace('.', ',') + ' €' : 'Freiwillig');
        $('#wl-form-id').val(id);
        $('#wl-form-betrag').val(betrag > 0 ? betrag : '');
        $('#wl-form-feedback').hide().removeClass('success error').text('');
        wlResetForm('#wl-spende-form');
        $('#wl-form-id').val(id); // nach reset wieder setzen
        $('#wl-modal').fadeIn(200);
        $('body').css('overflow', 'hidden');
    });

    // ─── MODAL SCHLIESSEN ────────────────────────────────────────────────────

    function closeModal() {
        $('#wl-modal').fadeOut(150);
        $('body').css('overflow', '');
    }

    $(document).on('click', '#wl-modal-close, .wl-modal-overlay', function (e) {
        if (e.target === this) closeModal();
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') closeModal();
    });

    $(document).on('click', '.wl-modal', function (e) {
        e.stopPropagation();
    });

    // ─── SPENDE ABSENDEN ─────────────────────────────────────────────────────

    $(document).on('submit', '#wl-spende-form', function (e) {
        e.preventDefault();

        var $btn = $(this).find('[type=submit]');
        $btn.prop('disabled', true).text('Wird gesendet…');

        var data = $(this).serializeArray();
        data.push({ name: 'action', value: 'wl_sende_spende' });
        data.push({ name: 'nonce',  value: wl_ajax.nonce });

        $.post(wl_ajax.url, data, function (res) {
            var $fb = $('#wl-form-feedback');
            $fb.show().text(res.data.msg);
            if (res.success) {
                $fb.removeClass('error').addClass('success');
                wlResetForm('#wl-spende-form');
                setTimeout(closeModal, 3000);
            } else {
                $fb.removeClass('success').addClass('error');
                $btn.prop('disabled', false).text('Nachricht absenden');
            }
        }).fail(function () {
            $('#wl-form-feedback').show().addClass('error').text('Verbindungsfehler. Bitte versuche es erneut.');
            $btn.prop('disabled', false).text('Nachricht absenden');
        });
    });

    // ─── VERWALTUNG: NEUEN WUNSCH FORMULAR ÖFFNEN ────────────────────────────

    $(document).on('click', '#wl-neu-btn', function () {
        clearForm();
        $('#wl-form-title').text('Neuer Wunsch');
        $('#wl-form-panel').slideDown(200);
        wlScrollToPanel('#wl-form-panel');
    });

    $(document).on('click', '#wl-form-cancel', function () {
        $('#wl-form-panel').slideUp(200);
        clearForm();
    });

    function clearForm() {
        $('#wl-edit-id').val('');
        $('#wl-edit-titel').val('');
        $('#wl-edit-desc').val('');
        $('#wl-edit-begruendung').val('');
        $('#wl-edit-betrag').val('');
        $('#wl-edit-preis-von').val('');
        $('#wl-edit-preis-bis').val('');
        $('#wl-edit-kat').val('');
        $('#wl-edit-status').val('offen');
        $('#wl-edit-prio').val('2');
        $('#wl-edit-bild').val('');
        $('input[name=preis_modus][value=fest]').prop('checked', true);
        $('.wl-preis-fest').show();
        $('.wl-preis-spanne').hide();
        $('#wl-links-list').empty();
        $('#wl-save-feedback').hide().text('');
    }

    // ─── PREIS-MODUS TOGGLE ───────────────────────────────────────────────────

    $(document).on('change', 'input[name=preis_modus]', function () {
        if ($(this).val() === 'spanne') {
            $('.wl-preis-fest').hide();
            $('.wl-preis-spanne').show();
            $('#wl-edit-betrag').val('');
        } else {
            $('.wl-preis-spanne').hide();
            $('.wl-preis-fest').show();
            $('#wl-edit-preis-von, #wl-edit-preis-bis').val('');
        }
    });

    // ─── PRODUKTLINKS: DYNAMISCH HINZUFÜGEN/ENTFERNEN ────────────────────────

    function wl_link_row(label, url, preis) {
        var $row = $('<div class="wl-link-row"></div>');
        $row.append('<input type="text" class="wl-link-label" placeholder="Anbieter (z.B. Amazon)" value="' + (label || '').replace(/"/g, '&quot;') + '">');
        $row.append('<input type="url" class="wl-link-url" placeholder="https://..." value="' + (url || '').replace(/"/g, '&quot;') + '">');
        $row.append('<input type="number" class="wl-link-preis" placeholder="Preis €" step="0.01" min="0" value="' + (preis || '') + '">');
        $row.append('<button type="button" class="wl-link-remove" title="Entfernen">✕</button>');
        return $row;
    }

    $(document).on('click', '#wl-add-link', function () {
        $('#wl-links-list').append(wl_link_row());
    });

    $(document).on('click', '.wl-link-remove', function () {
        $(this).closest('.wl-link-row').remove();
    });

    // ─── VERWALTUNG: BEARBEITEN ───────────────────────────────────────────────

    $(document).on('click', '.wl-btn-edit', function () {
        var $btn = $(this);
        clearForm();

        $('#wl-edit-id').val($btn.data('id'));
        $('#wl-edit-titel').val($btn.data('titel'));
        $('#wl-edit-desc').val($btn.data('desc'));
        $('#wl-edit-begruendung').val($btn.data('begruendung'));
        $('#wl-edit-kat').val($btn.data('kat'));
        $('#wl-edit-status').val($btn.data('status'));
        $('#wl-edit-prio').val($btn.data('prio'));
        $('#wl-edit-bild').val($btn.data('bild'));

        var preisVon = $btn.data('preis-von');
        var preisBis = $btn.data('preis-bis');
        if (preisVon || preisBis) {
            $('input[name=preis_modus][value=spanne]').prop('checked', true).trigger('change');
            $('#wl-edit-preis-von').val(preisVon);
            $('#wl-edit-preis-bis').val(preisBis);
        } else {
            $('input[name=preis_modus][value=fest]').prop('checked', true).trigger('change');
            $('#wl-edit-betrag').val($btn.data('betrag'));
        }

        // Links laden
        var links = $btn.data('links');
        if (links && links.length) {
            links.forEach(function (l) {
                $('#wl-links-list').append(wl_link_row(l.label, l.url, l.preis));
            });
        }

        $('#wl-form-title').text('Wunsch bearbeiten');
        $('#wl-form-panel').slideDown(200);
        wlScrollToPanel('#wl-form-panel');
    });

    // ─── VERWALTUNG: SPEICHERN ────────────────────────────────────────────────

    $(document).on('submit', '#wl-wunsch-form', function (e) {
        e.preventDefault();

        var $btn = $(this).find('[type=submit]');
        $btn.prop('disabled', true).text('Speichern…');

        // Links aus den dynamischen Zeilen sammeln
        var links = [];
        $('#wl-links-list .wl-link-row').each(function () {
            var url = $(this).find('.wl-link-url').val().trim();
            if (url) {
                links.push({
                    label: $(this).find('.wl-link-label').val().trim(),
                    url:   url,
                    preis: $(this).find('.wl-link-preis').val()
                });
            }
        });

        var data = $(this).serializeArray();
        data.push({ name: 'nonce', value: wl_ajax.nonce });
        data.push({ name: 'links', value: JSON.stringify(links) });

        $.post(wl_ajax.url, data, function (res) {
            if (res.success) {
                showFeedback('#wl-save-feedback', res.data.msg, true);
                setTimeout(function () { location.reload(); }, 1200);
            } else {
                showFeedback('#wl-save-feedback', res.data.msg, false);
                $btn.prop('disabled', false).text('Speichern');
            }
        });
    });

    // ─── VERWALTUNG: LÖSCHEN ─────────────────────────────────────────────────

    $(document).on('click', '.wl-btn-delete', function () {
        var id = $(this).data('id');
        if (!confirm('Diesen Wunsch wirklich löschen?')) return;

        $.post(wl_ajax.url, {
            action:    'wl_delete_wunsch',
            id:        id,
            wl_nonce:  wl_ajax.nonce
        }, function (res) {
            if (res.success) {
                $('#wl-row-' + id).fadeOut(300, function () { $(this).remove(); });
            } else {
                alert(res.data.msg);
            }
        });
    });

    // ─── HILFSFUNKTION ───────────────────────────────────────────────────────

    function showFeedback(selector, msg, success) {
        $(selector)
            .show()
            .removeClass('success error')
            .addClass(success ? 'success' : 'error')
            .css({ background: success ? '#dcfce7' : '#fef2f2', color: success ? '#166534' : '#dc2626', padding: '10px', borderRadius: '8px' })
            .text(msg);
    }

})(jQuery);

/* ═══════════════════════════════════════════════════
   VOTING SYSTEM
   ═══════════════════════════════════════════════════ */
(function ($) {
    'use strict';

    // ─── GAST LOGIN ──────────────────────────────────────────────────────────

    $(document).on('submit', '#wlv-gast-form', function (e) {
        e.preventDefault();
        var $btn = $(this).find('[type=submit]');
        $btn.prop('disabled', true).text('Prüfe Code…');

        var data = $(this).serializeArray();
        $.post(wl_ajax.url, data, function (res) {
            if (res.success) {
                location.reload();
            } else {
                $('#wlv-gast-error').show().text(res.data.msg);
                $btn.prop('disabled', false).text('Abstimmen');
            }
        });
    });

    // ─── TABS ────────────────────────────────────────────────────────────────

    $(document).on('click', '.wlv-tab', function () {
        var tab = $(this).data('tab');
        $('.wlv-tab').removeClass('active');
        $(this).addClass('active');
        $('.wlv-tab-content').hide();
        $('#wlv-tab-' + tab).show();
    });

    // ─── VOTE BUTTON ─────────────────────────────────────────────────────────

    $(document).on('click', '.wlv-vote-btn', function () {
        var $btn     = $(this);
        var wunschId = $btn.data('wunsch');
        var stufe    = parseInt($btn.data('stufe'));
        var needsReason = $btn.data('needs-reason') === '1' || $btn.data('needs-reason') === 1;

        // Bereits aktiv → Stimme zurückziehen
        if ($btn.hasClass('active')) {
            wlv_vote_zurueck(wunschId);
            return;
        }

        if (needsReason) {
            // Veto-Modal öffnen
            $('#wlv-veto-wunsch-id').val(wunschId);
            $('#wlv-veto-begruendung').val('');
            $('#wlv-veto-error').hide();
            $('#wlv-veto-modal').fadeIn(200);
            $('body').css('overflow', 'hidden');
            // Stufe für späteres Submit merken
            $('#wlv-veto-confirm').data('stufe', stufe);
            return;
        }

        wlv_sende_vote(wunschId, stufe, '');
    });

    // ─── VETO MODAL ──────────────────────────────────────────────────────────

    $(document).on('click', '#wlv-veto-confirm', function () {
        var wunschId     = $('#wlv-veto-wunsch-id').val();
        var begruendung  = $.trim($('#wlv-veto-begruendung').val());
        var stufe        = $(this).data('stufe');

        if (!begruendung) {
            $('#wlv-veto-error').show().text('Bitte eine Begründung eingeben.');
            return;
        }

        $('#wlv-veto-modal').fadeOut(150);
        $('body').css('overflow', '');
        wlv_sende_vote(wunschId, stufe, begruendung);
    });

    $(document).on('click', '#wlv-veto-close, #wlv-veto-cancel', function () {
        $('#wlv-veto-modal').fadeOut(150);
        $('body').css('overflow', '');
    });

    // ─── VOTE ABSENDEN ───────────────────────────────────────────────────────

    function wlv_sende_vote(wunschId, stufe, begruendung) {
        // Buttons kurz deaktivieren
        $('#wlv-buttons-' + wunschId + ' .wlv-vote-btn').prop('disabled', true);

        $.post(wl_ajax.url, {
            action:      'wl_abstimmen',
            wunsch_id:   wunschId,
            stufe:       stufe,
            begruendung: begruendung,
            nonce:       wl_ajax.nonce,
        }, function (res) {
            if (res.success) {
                var d = res.data;
                // Aktiv-Klasse setzen
                var $btns = $('#wlv-buttons-' + wunschId + ' .wlv-vote-btn');
                $btns.removeClass('active').prop('disabled', false);
                $btns.filter('[data-stufe="' + stufe + '"]').addClass('active');

                // My-vote-label updaten
                var stufen_labels = {1:'🟢 Notwendig', 2:'🔵 Wunsch', 3:'⚪ Neutral', 4:'🟠 Unnötig', 5:'🔴 Veto'};
                $('#wlv-myvote-' + wunschId)
                    .removeClass('wlv-no-vote')
                    .html('Deine Stimme: <strong>' + (stufen_labels[stufe] || stufe) + '</strong>');

                // Stats updaten
                $('#wlv-stats-' + wunschId).html(d.stats_html);

                // Veto-Status auf der Karte
                var $card = $('#wlv-card-' + wunschId);
                if (d.hat_veto) {
                    $card.addClass('wlv-card-veto');
                    $card.find('.wlv-card-rank').text('🚫');
                } else {
                    $card.removeClass('wlv-card-veto');
                }

                // Toast
                wlv_toast('✓ Stimme gespeichert');
            } else {
                $('#wlv-buttons-' + wunschId + ' .wlv-vote-btn').prop('disabled', false);
                wlv_toast('⚠️ ' + res.data.msg, true);
            }
        });
    }

    // ─── STIMME ZURÜCKZIEHEN ─────────────────────────────────────────────────

    function wlv_vote_zurueck(wunschId) {
        $('#wlv-buttons-' + wunschId + ' .wlv-vote-btn').prop('disabled', true);

        $.post(wl_ajax.url, {
            action:    'wl_vote_zurueck',
            wunsch_id: wunschId,
            nonce:     wl_ajax.nonce,
        }, function (res) {
            if (res.success) {
                var d = res.data;
                var $btns = $('#wlv-buttons-' + wunschId + ' .wlv-vote-btn');
                $btns.removeClass('active').prop('disabled', false);
                $('#wlv-myvote-' + wunschId).addClass('wlv-no-vote').text('Noch nicht abgestimmt');
                $('#wlv-stats-' + wunschId).html(d.stats_html);
                if (!d.hat_veto) $('#wlv-card-' + wunschId).removeClass('wlv-card-veto');
                wlv_toast('Stimme zurückgezogen');
            } else {
                $('#wlv-buttons-' + wunschId + ' .wlv-vote-btn').prop('disabled', false);
            }
        });
    }

    // ─── TOAST ───────────────────────────────────────────────────────────────

    function wlv_toast(msg, error) {
        var $t = $('<div class="wlv-toast' + (error ? ' wlv-toast-error' : '') + '">' + msg + '</div>');
        $('body').append($t);
        setTimeout(function () { $t.addClass('show'); }, 10);
        setTimeout(function () { $t.removeClass('show'); setTimeout(function () { $t.remove(); }, 300); }, 2500);
    }

})(jQuery);

/* ═══════════════════════════════════════════════════
   SCHICHTPLAN: ÖFFENTLICHE EINTRAGUNG
   ═══════════════════════════════════════════════════ */
(function ($) {
    'use strict';

    // ─── MODAL ÖFFNEN ────────────────────────────────────────────────────────

    $(document).on('click', '.wls-eintragen-btn', function () {
        var schichtId = $(this).data('schicht');
        var titel = $(this).data('titel');
        $('#wls-form-schicht-id').val(schichtId);
        $('#wls-modal-schicht-titel').text(titel);
        $('#wls-form-feedback').hide().removeClass('success error').text('');
        // Button-Zustand zurücksetzen, falls das Modal vorher schon für eine andere
        // Schicht offen war und der Submit-Button noch deaktiviert ist.
        $('#wls-eintragen-form').find('[type=submit]').prop('disabled', false).text('Verbindlich eintragen');
        $('#wls-eintragen-form')[0].reset();
        $('#wls-form-schicht-id').val(schichtId); // nach reset wieder setzen
        $('#wls-modal').fadeIn(200);
        $('body').css('overflow', 'hidden');
    });

    function wlsCloseModal() {
        $('#wls-modal').fadeOut(150);
        $('body').css('overflow', '');
    }

    $(document).on('click', '#wls-modal-close, #wls-modal.wl-modal-overlay', function (e) {
        if (e.target === this) wlsCloseModal();
    });
    $(document).on('click', '#wls-modal .wl-modal', function (e) { e.stopPropagation(); });
    $(document).on('keydown', function (e) { if (e.key === 'Escape') wlsCloseModal(); });

    // ─── FÜR SCHICHT EINTRAGEN ───────────────────────────────────────────────

    $(document).on('submit', '#wls-eintragen-form', function (e) {
        e.preventDefault();
        var $btn = $(this).find('[type=submit]');
        $btn.prop('disabled', true).text('Wird eingetragen…');

        var data = $(this).serializeArray();

        $.post(wl_ajax.url, data, function (res) {
            var $fb = $('#wls-form-feedback');
            if (res.success) {
                var linksHtml = '';
                if (res.data.ics_link) {
                    linksHtml += '<br><a href="' + res.data.ics_link + '" style="color:#166534;text-decoration:underline;">📅 Zum Kalender hinzufügen (.ics)</a>';
                }
                if (res.data.abmelde_link) {
                    linksHtml += '<br><a href="' + res.data.abmelde_link + '" style="color:#166534;text-decoration:underline;">Wieder austragen</a>';
                }
                if (res.data.abmelde_link) {
                    var mk = new URL(res.data.abmelde_link).searchParams.get('wl_abmelden');
                    if (mk) {
                        linksHtml += ' &nbsp;·&nbsp; <a href="#" class="wls-tausch-oeffnen" data-key="' + mk + '" style="color:#166534;text-decoration:underline;">🔄 Tausch anfragen</a>';
                    }
                }
                $fb.show().removeClass('error').addClass('success')
                   .css({background:'#dcfce7',color:'#166534',padding:'10px',borderRadius:'8px'})
                   .html(res.data.msg + linksHtml);

                // Schicht-Anzeige live updaten — sowohl Desktop-Kalender als auch Mobile-Liste
                var schichtId = $('#wls-form-schicht-id').val();
                var personHtml = '<span class="wls-person-chip">' + $('<div>').text(res.data.name).html() + '</span>';
                var platzText = res.data.belegt + '/' + (res.data.belegt + res.data.frei);

                $('#wls-schicht-' + schichtId + ', #wls-schicht-mobile-' + schichtId).each(function () {
                    var $block = $(this);
                    $block.find('.wls-platz-badge').text(platzText);

                    var $eingetragene = $block.find('.wls-eingetragene');
                    if ($eingetragene.length) {
                        $eingetragene.append(personHtml);
                    } else {
                        $block.find('.wls-platz-badge').first().closest('.wls-block-plaetze, .wls-mobile-card-bottom').after('<div class="wls-eingetragene">' + personHtml + '</div>');
                    }

                    if (res.data.voll) {
                        $block.addClass('wls-block-voll');
                        $block.find('.wls-platz-badge').addClass('voll');
                        $block.find('.wls-eintragen-btn').replaceWith('<span class="wls-voll-label">Voll</span>');
                    }
                });

                setTimeout(wlsCloseModal, 4000);

                // Button-Zustand zurücksetzen, falls noch eine weitere Eintragung
                // im selben Formular-Kontext erfolgen sollte, bevor das Modal schließt.
                $btn.prop('disabled', false).text('Verbindlich eintragen');
            } else {
                $fb.show().removeClass('success').addClass('error')
                   .css({background:'#fef2f2',color:'#dc2626',padding:'10px',borderRadius:'8px'})
                   .text(res.data.msg);
                $btn.prop('disabled', false).text('Verbindlich eintragen');
            }
        }).fail(function () {
            $('#wls-form-feedback').show().text('Verbindungsfehler. Bitte versuche es erneut.');
            $btn.prop('disabled', false).text('Verbindlich eintragen');
        });
    });

})(jQuery);

/* ═══════════════════════════════════════════════════
   SCHICHTPLAN: MITGLIEDER-VERWALTUNG
   ═══════════════════════════════════════════════════ */
(function ($) {
    'use strict';

    // ─── EVENT: NEU / ABBRECHEN ──────────────────────────────────────────────

    $(document).on('click', '#wls-neu-event-btn', function () {
        wlResetForm('#wls-event-form');
        $('#wls-event-form-panel').slideDown(200);
    });
    $(document).on('click', '#wls-event-form-cancel', function () {
        $('#wls-event-form-panel').slideUp(200);
    });

    $(document).on('submit', '#wls-event-form', function (e) {
        e.preventDefault();
        var $btn = $(this).find('[type=submit]');
        $btn.prop('disabled', true).text('Speichern…');

        $.post(wl_ajax.url, $(this).serializeArray(), function (res) {
            if (res.success) {
                location.reload();
            } else {
                $('#wls-event-form-feedback').text(res.data.msg).css({color:'#dc2626',marginTop:'8px'});
                $btn.prop('disabled', false).text('Anlegen');
            }
        });
    });

    // ─── EVENT-EINSTELLUNGEN (Tagesgrenze) BEARBEITEN ────────────────────────

    $(document).on('click', '#wls-edit-event-settings-btn', function () {
        $('#wls-event-settings-panel').slideDown(200);
    });
    $(document).on('click', '#wls-event-settings-cancel', function () {
        $('#wls-event-settings-panel').slideUp(200);
    });

    $(document).on('submit', '#wls-event-settings-form', function (e) {
        e.preventDefault();
        var $btn = $(this).find('[type=submit]');
        $btn.prop('disabled', true).text('Speichern…');

        $.post(wl_ajax.url, $(this).serializeArray(), function (res) {
            if (res.success) {
                location.reload();
            } else {
                $('#wls-event-settings-feedback').text(res.data.msg).css({color:'#dc2626',marginTop:'8px'});
                $btn.prop('disabled', false).text('Speichern');
            }
        });
    });

    // ─── EVENT: AKTIV/INAKTIV TOGGLE ──────────────────────────────────────────

    $(document).on('click', '.wls-toggle-event-btn', function () {
        var $btn = $(this);
        var id = $btn.data('id');
        $.post(wl_ajax.url, { action: 'wl_toggle_event', id: id, nonce: wl_ajax.nonce }, function (res) {
            if (res.success) location.reload();
        });
    });

    // ─── EVENT: LÖSCHEN ───────────────────────────────────────────────────────

    $(document).on('click', '.wls-delete-event-btn', function () {
        if (!confirm('Diese Veranstaltung inkl. aller Stationen, Schichten und Eintragungen wirklich löschen?')) return;
        var id = $(this).data('id');
        $.post(wl_ajax.url, { action: 'wl_delete_event', id: id, nonce: wl_ajax.nonce }, function (res) {
            if (res.success) $('#wls-event-row-' + id).fadeOut(300, function () { $(this).remove(); });
        });
    });

    // ─── STATION: NEU / BEARBEITEN / ABBRECHEN ───────────────────────────────

    function wlsClearStationForm() {
        $('#wls-station-id').val('');
        $('#wls-station-titel, #wls-station-desc, #wls-station-treffpunkt, #wls-station-ap1, #wls-station-ap1-kontakt, #wls-station-ap2, #wls-station-ap2-kontakt').val('');
        $('#wls-station-form-feedback').text('');
    }

    $(document).on('click', '#wls-neu-station-btn', function () {
        wlsClearStationForm();
        $('#wls-station-form-title').text('Neue Station');
        $('#wls-station-form-panel').slideDown(200);
        wlScrollToPanel('#wls-station-form-panel');
    });

    $(document).on('click', '#wls-station-form-cancel', function () {
        $('#wls-station-form-panel').slideUp(200);
        wlsClearStationForm();
    });

    $(document).on('click', '.wls-edit-station-btn', function () {
        var $btn = $(this);
        $('#wls-station-id').val($btn.data('id'));
        $('#wls-station-titel').val($btn.data('titel'));
        $('#wls-station-desc').val($btn.data('desc'));
        $('#wls-station-treffpunkt').val($btn.data('treffpunkt'));
        $('#wls-station-ap1').val($btn.data('ap1'));
        $('#wls-station-ap1-kontakt').val($btn.data('ap1-kontakt'));
        $('#wls-station-ap2').val($btn.data('ap2'));
        $('#wls-station-ap2-kontakt').val($btn.data('ap2-kontakt'));
        $('#wls-station-form-title').text('Station bearbeiten');
        $('#wls-station-form-panel').slideDown(200);
        wlScrollToPanel('#wls-station-form-panel');
    });

    $(document).on('submit', '#wls-station-form', function (e) {
        e.preventDefault();
        var $btn = $(this).find('[type=submit]');
        $btn.prop('disabled', true).text('Speichern…');

        $.post(wl_ajax.url, $(this).serializeArray(), function (res) {
            if (res.success) {
                location.reload();
            } else {
                $('#wls-station-form-feedback').text(res.data.msg).css({color:'#dc2626',marginTop:'8px'});
                $btn.prop('disabled', false).text('Speichern');
            }
        });
    });

    // ─── STATION: LÖSCHEN ─────────────────────────────────────────────────────

    $(document).on('click', '.wls-delete-station-btn', function () {
        if (!confirm('Diese Station inkl. aller Schichten und Eintragungen wirklich löschen?')) return;
        var id = $(this).data('id');
        $.post(wl_ajax.url, { action: 'wl_delete_station', id: id, nonce: wl_ajax.nonce }, function (res) {
            if (res.success) $('#wls-station-admin-' + id).fadeOut(300, function () { $(this).remove(); });
        });
    });

    // ─── SCHICHT: MODAL ÖFFNEN (NEU / BEARBEITEN) ────────────────────────────

    $(document).on('click', '.wls-neu-schicht-btn', function () {
        var stationId = $(this).data('station');
        $('#wls-schicht-id').val('');
        $('#wls-schicht-station-id').val(stationId);
        $('#wls-schicht-titel, #wls-schicht-start, #wls-schicht-end').val('');
        $('#wls-schicht-min').val(0);
        $('#wls-schicht-max').val(1);
        $('#wls-schicht-modal-title').text('Neue Schicht');
        $('#wls-schicht-form-feedback').text('');
        $('#wls-schicht-modal').fadeIn(200);
        $('body').css('overflow', 'hidden');
    });

    $(document).on('click', '.wls-edit-schicht-btn', function () {
        var $btn = $(this);
        $('#wls-schicht-id').val($btn.data('id'));
        $('#wls-schicht-station-id').val($btn.data('station'));
        $('#wls-schicht-titel').val($btn.data('titel'));
        $('#wls-schicht-start').val($btn.data('start'));
        $('#wls-schicht-end').val($btn.data('end'));
        $('#wls-schicht-min').val($btn.data('min') || 0);
        $('#wls-schicht-max').val($btn.data('max'));
        $('#wls-schicht-modal-title').text('Schicht bearbeiten');
        $('#wls-schicht-form-feedback').text('');
        $('#wls-schicht-modal').fadeIn(200);
        $('body').css('overflow', 'hidden');
    });

    $(document).on('click', '#wls-schicht-modal-close, #wls-schicht-modal-cancel', function () {
        $('#wls-schicht-modal').fadeOut(150);
        $('body').css('overflow', '');
    });

    $(document).on('submit', '#wls-schicht-form', function (e) {
        e.preventDefault();
        var $btn = $(this).find('[type=submit]');
        $btn.prop('disabled', true).text('Speichern…');

        $.post(wl_ajax.url, $(this).serializeArray(), function (res) {
            if (res.success) {
                location.reload();
            } else {
                $('#wls-schicht-form-feedback').text(res.data.msg).css({color:'#dc2626',marginTop:'8px'});
                $btn.prop('disabled', false).text('Speichern');
            }
        });
    });

    // ─── SCHICHT: LÖSCHEN ─────────────────────────────────────────────────────

    $(document).on('click', '.wls-delete-schicht-btn', function () {
        if (!confirm('Diese Schicht inkl. aller Eintragungen wirklich löschen?')) return;
        var id = $(this).data('id');
        $.post(wl_ajax.url, { action: 'wl_delete_schicht', id: id, nonce: wl_ajax.nonce }, function (res) {
            if (res.success) $('#wls-schicht-admin-' + id).fadeOut(300, function () { $(this).remove(); });
        });
    });

    // ─── EINTRAGUNG ENTFERNEN (durch Mitglied) ───────────────────────────────

    $(document).on('click', '.wls-remove-eintrag-btn', function () {
        if (!confirm('Diese Person aus der Schicht entfernen?')) return;
        var $line = $(this).closest('.wls-eintrag-line');
        var id = $(this).data('id');
        $.post(wl_ajax.url, { action: 'wl_remove_eintrag', id: id, nonce: wl_ajax.nonce }, function (res) {
            if (res.success) $line.fadeOut(200, function () { $(this).remove(); });
        });
    });

})(jQuery);

/* ═══════════════════════════════════════════════════
   VOTING: KATEGORIE-FILTER + WUNSCH-VERWALTUNG INLINE
   ═══════════════════════════════════════════════════ */
(function ($) {
    'use strict';

    // ─── KATEGORIE-FILTER ──────────────────────────────────────────────────────

    $(document).on('click', '#wlv-board .wl-filter-btn', function () {
        $('#wlv-board .wl-filter-btn').removeClass('active');
        $(this).addClass('active');

        var filter = $(this).data('filter');
        $('#wlv-liste .wlv-card').each(function () {
            if (!filter || $(this).data('kategorie') === filter) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    });

    // ─── NEUER WUNSCH / FORMULAR ÖFFNEN ───────────────────────────────────────

    function wlvClearWunschForm() {
        $('#wlv-edit-id').val('');
        $('#wlv-edit-titel, #wlv-edit-desc, #wlv-edit-begruendung, #wlv-edit-betrag, #wlv-edit-kat, #wlv-edit-bild').val('');
        $('#wlv-edit-status').val('offen');
        $('#wlv-edit-prio').val('2');
        $('#wlv-wunsch-save-feedback').empty();
    }

    $(document).on('click', '#wlv-neu-wunsch-btn', function () {
        wlvClearWunschForm();
        $('#wlv-wunsch-form-title').text('Neuer Wunsch');
        $('#wlv-wunsch-form-panel').slideDown(200);
        wlScrollToPanel('#wlv-wunsch-form-panel');
    });

    $(document).on('click', '#wlv-wunsch-form-cancel', function () {
        $('#wlv-wunsch-form-panel').slideUp(200);
        wlvClearWunschForm();
    });

    // ─── WUNSCH BEARBEITEN ─────────────────────────────────────────────────────

    $(document).on('click', '.wlv-edit-wunsch-btn', function () {
        var $btn = $(this);
        $('#wlv-edit-id').val($btn.data('id'));
        $('#wlv-edit-titel').val($btn.data('titel'));
        $('#wlv-edit-desc').val($btn.data('desc'));
        $('#wlv-edit-begruendung').val($btn.data('begruendung'));
        $('#wlv-edit-betrag').val($btn.data('betrag'));
        $('#wlv-edit-kat').val($btn.data('kat'));
        $('#wlv-edit-status').val($btn.data('status'));
        $('#wlv-edit-prio').val($btn.data('prio'));
        $('#wlv-edit-bild').val($btn.data('bild'));
        $('#wlv-wunsch-form-title').text('Wunsch bearbeiten');
        $('#wlv-wunsch-form-panel').slideDown(200);
        wlScrollToPanel('#wlv-wunsch-form-panel');
    });

    // ─── WUNSCH SPEICHERN ──────────────────────────────────────────────────────

    $(document).on('submit', '#wlv-wunsch-form', function (e) {
        e.preventDefault();
        var $btn = $(this).find('[type=submit]');
        $btn.prop('disabled', true).text('Speichern…');

        $.post(wl_ajax.url, $(this).serializeArray(), function (res) {
            if (res.success) {
                location.reload();
            } else {
                $('#wlv-wunsch-save-feedback').text(res.data.msg).css({color:'#dc2626',marginTop:'8px'});
                $btn.prop('disabled', false).text('Speichern');
            }
        });
    });

    // ─── WUNSCH LÖSCHEN ────────────────────────────────────────────────────────

    $(document).on('click', '.wlv-delete-wunsch-btn', function () {
        if (!confirm('Diesen Wunsch wirklich löschen? Alle Stimmen dazu gehen verloren.')) return;
        var id = $(this).data('id');
        $.post(wl_ajax.url, { action: 'wl_delete_wunsch', id: id, wl_nonce: wl_ajax.nonce }, function (res) {
            if (res.success) $('#wlv-card-' + id).fadeOut(300, function () { $(this).remove(); });
        });
    });

})(jQuery);

/* ═══════════════════════════════════════════════════
   SCHICHTPLAN: STATIONS-INFO-TOOLTIP (Desktop-Kalender)
   ═══════════════════════════════════════════════════ */
(function ($) {
    'use strict';

    var $infoPopup = null;

    function wlsCloseInfoPopup() {
        if ($infoPopup) { $infoPopup.remove(); $infoPopup = null; }
    }

    $(document).on('click', '.wls-info-btn', function (e) {
        e.stopPropagation();
        var info = $(this).data('info');
        var alreadyOpenForThis = $infoPopup && $infoPopup.data('source') === this;
        wlsCloseInfoPopup();

        if (alreadyOpenForThis) return; // Toggle: zweiter Klick auf denselben Button schließt nur

        var $btn = $(this);
        var offset = $btn.offset();

        $infoPopup = $('<div class="wls-info-popup"></div>')
            .text(info)
            .data('source', this)
            .css({
                position: 'absolute',
                top: offset.top + $btn.outerHeight() + 6,
                left: offset.left,
            });
        $('body').append($infoPopup);
    });

    $(document).on('click', function (e) {
        if ($infoPopup && !$(e.target).hasClass('wls-info-btn')) {
            wlsCloseInfoPopup();
        }
    });

})(jQuery);

/* ═══════════════════════════════════════════════════
   SCHICHTPLAN: ADMIN-EINTRAGEN + TAUSCH-ANFRAGE
   ═══════════════════════════════════════════════════ */
(function ($) {
    'use strict';

    // ─── ADMIN: + PERSON BUTTON ───────────────────────────────────────────────

    $(document).on('click', '.wls-admin-eintrag-btn', function () {
        var id = $(this).data('schicht');
        $('#wls-admin-form-' + id).slideDown(150);
        $(this).hide();
    });

    $(document).on('click', '.wls-admin-eintrag-abbrechen', function () {
        var id = $(this).data('schicht');
        $('#wls-admin-form-' + id).slideUp(150);
        $('.wls-admin-eintrag-btn[data-schicht="' + id + '"]').show();
    });

    $(document).on('click', '.wls-admin-eintrag-speichern', function () {
        var $btn = $(this);
        var id = $btn.data('schicht');
        var $form = $('#wls-admin-form-' + id);
        var name  = $form.find('.wls-admin-name').val().trim();
        var email = $form.find('.wls-admin-email').val().trim();
        var tel   = $form.find('.wls-admin-tel').val().trim();

        if (!name) { alert('Bitte Namen eingeben.'); return; }

        $btn.prop('disabled', true).text('Speichern…');
        $.post(wl_ajax.url, {
            action:     'wl_admin_eintragen',
            nonce:      wl_ajax.nonce,
            schicht_id: id,
            name:       name,
            email:      email,
            telefon:    tel,
        }, function (res) {
            if (res.success) {
                location.reload();
            } else {
                alert(res.data.msg);
                $btn.prop('disabled', false).text('✓ Eintragen');
            }
        });
    });

    // ─── TAUSCH-MODAL ÖFFNEN ──────────────────────────────────────────────────

    $(document).on('click', '.wls-tausch-oeffnen', function (e) {
        e.preventDefault();
        var key = $(this).data('key');
        $('#wls-tausch-manage-key').val(key);
        $('#wls-tausch-email, #wls-tausch-name').val('');
        $('#wls-tausch-feedback').hide().text('');
        $('#wls-tausch-modal').fadeIn(200);
        $('body').css('overflow', 'hidden');
    });

    $(document).on('click', '#wls-tausch-modal-close, #wls-tausch-abbrechen', function () {
        $('#wls-tausch-modal').fadeOut(150);
        $('body').css('overflow', '');
    });

    // ─── TAUSCH-ANFRAGE SENDEN ────────────────────────────────────────────────

    $(document).on('click', '#wls-tausch-senden', function () {
        var $btn = $(this);
        var key   = $('#wls-tausch-manage-key').val();
        var email = $('#wls-tausch-email').val().trim();
        var name  = $('#wls-tausch-name').val().trim();

        if (!email) {
            $('#wls-tausch-feedback').show().css({background:'#fef2f2',color:'#dc2626',padding:'10px',borderRadius:'8px'}).text('Bitte E-Mail eingeben.');
            return;
        }

        $btn.prop('disabled', true).text('Senden…');

        $.post(wl_ajax.url, {
            action:      'wl_tausch_anfrage',
            nonce:       wl_ajax.nonce,
            manage_key:  key,
            an_email:    email,
            an_name:     name,
        }, function (res) {
            var $fb = $('#wls-tausch-feedback');
            if (res.success) {
                $fb.show().css({background:'#dcfce7',color:'#166534',padding:'10px',borderRadius:'8px'}).text(res.data.msg);
                $btn.prop('disabled', false).text('Anfrage senden');
                setTimeout(function () {
                    $('#wls-tausch-modal').fadeOut(150);
                    $('body').css('overflow', '');
                }, 3000);
            } else {
                $fb.show().css({background:'#fef2f2',color:'#dc2626',padding:'10px',borderRadius:'8px'}).text(res.data.msg);
                $btn.prop('disabled', false).text('Anfrage senden');
            }
        });
    });

})(jQuery);
