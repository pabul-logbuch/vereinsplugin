<?php defined('ABSPATH') || exit; ?>
<div class="wrap jb-admin">
    <h1>Einstellungen – JuFo Buchhaltung</h1>

    <form method="post">
        <?php wp_nonce_field('jb_settings'); ?>

        <h2>Nextcloud-Verbindung</h2>
        <p class="description">Lege in Nextcloud ein App-Passwort an (Einstellungen → Sicherheit → App-Passwörter). Niemals dein normales Passwort verwenden!</p>

        <table class="form-table">
            <tr>
                <th><label for="jb_nc_url">Nextcloud-URL</label></th>
                <td><input type="url" id="jb_nc_url" name="nc_url" class="regular-text"
                           value="<?= esc_attr(get_option('jb_nc_url','')) ?>"
                           placeholder="https://cloud.juforiedlingen.de"></td>
            </tr>
            <tr>
                <th><label for="jb_nc_user">Benutzername</label></th>
                <td><input type="text" id="jb_nc_user" name="nc_user" class="regular-text"
                           value="<?= esc_attr(get_option('jb_nc_user','')) ?>"></td>
            </tr>
            <tr>
                <th><label for="jb_nc_password">App-Passwort</label></th>
                <td><input type="password" id="jb_nc_password" name="nc_password" class="regular-text"
                           value="<?= esc_attr(get_option('jb_nc_password','')) ?>"
                           autocomplete="new-password">
                    <button type="button" id="jb-test-nc" class="button" style="margin-left:8px">
                        Verbindung testen
                    </button>
                    <span id="jb-nc-result" style="margin-left:8px"></span>
                </td>
            </tr>
            <tr>
                <th><label for="jb_nc_folder">Ordner in Nextcloud</label></th>
                <td><input type="text" id="jb_nc_folder" name="nc_folder" class="regular-text"
                           value="<?= esc_attr(get_option('jb_nc_folder','JuFo-Buchhaltung')) ?>">
                    <p class="description">Hauptordner für alle Belege. Wird automatisch angelegt.</p>
                </td>
            </tr>
        </table>

        <h2>Allgemein</h2>
        <table class="form-table">
            <tr>
                <th><label for="jb_kassier_email">Kassier E-Mail</label></th>
                <td><input type="email" id="jb_kassier_email" name="kassier_email" class="regular-text"
                           value="<?= esc_attr(get_option('jb_kassier_email', get_option('admin_email'))) ?>">
                    <p class="description">Bekommt E-Mail bei neuen Auslagen-Anträgen.</p>
                </td>
            </tr>
            <tr>
                <th>Beleg-Pflicht</th>
                <td>
                    <label>
                        <input type="checkbox" name="beleg_pflicht" value="1"
                               <?= checked('1', get_option('jb_beleg_pflicht','1')) ?>>
                        Beleg-Upload ist Pflicht beim Einreichen
                    </label>
                </td>
            </tr>
        </table>

        <input type="hidden" name="jb_save_settings" value="1">
        <?php submit_button('Einstellungen speichern'); ?>
    </form>
</div>

<script>
jQuery('#jb-test-nc').on('click', function() {
    const btn = jQuery(this).prop('disabled', true).text('Teste…');
    jQuery.post(ajaxurl, { action: 'jb_test_nc', nonce: JB.nonce }, function(r) {
        const el = jQuery('#jb-nc-result');
        el.html(r.success
            ? '<span style="color:green">✓ ' + r.data.message + '</span>'
            : '<span style="color:red">✗ ' + r.data.message + '</span>');
        btn.prop('disabled', false).text('Verbindung testen');
    });
});
</script>
