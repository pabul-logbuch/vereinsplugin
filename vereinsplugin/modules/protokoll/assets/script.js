/**
 * ProtokollPro – V1 nutzt bewusst normale Formular-Submits (admin-post.php)
 * für den Konsent-Workflow, damit alles auch ohne JavaScript zuverlässig
 * funktioniert. Dieses Skript ist der Anknüpfungspunkt für spätere
 * Komfort-Features (z. B. Live-Status-Updates via pp_ajax, siehe includes/ajax.php).
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        // Platzhalter für zukünftige AJAX-Interaktionen.
        // pp_ajax.url und pp_ajax.nonce stehen bereits zur Verfügung (siehe protokollpro.php).

        // "+ Person eintragen"-Link auf der Gremien-Seite blendet das Formular ein/aus.
        $(document).on('click', '.pp-toggle-besetzung-form', function (e) {
            e.preventDefault();
            var target = $('#' + $(this).data('target'));
            target.toggle();
        });
    });
})(jQuery);

/**
 * Rollen-Aufgabenformular: Rhythmus bzw. Vorlauf je nach Aufgabentyp zeigen.
 * Läuft auch im Frontend-Mitgliederbereich (Delegation, da mehrere Formulare).
 */
(function ($) {
    'use strict';
    $(document).on('change', '.pp-aufgabe-typ', function () {
        var form = $(this).closest('form');
        var istEvent = $(this).val() === 'event';
        form.find('.pp-feld-rhythmus').toggle(!istEvent);
        form.find('.pp-feld-vorlauf').toggle(istEvent);
    });
})(jQuery);
