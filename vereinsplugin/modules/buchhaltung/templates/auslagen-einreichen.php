<?php defined('ABSPATH') || exit; ?>
<div class="jb-wrap" id="jb-auslage-form-wrap">
    <h3>Beleg / Auslage einreichen</h3>

    <div id="jb-msg" class="jb-msg" style="display:none"></div>

    <form id="jb-auslage-form" enctype="multipart/form-data">
        <?php wp_nonce_field('jb_nonce', 'nonce'); ?>

        <div class="jb-field">
            <label>Was möchtest du tun? *</label>
            <label style="font-weight:400;display:block;margin:4px 0">
                <input type="radio" name="modus" value="erstattung" checked>
                <strong>Erstattung beantragen</strong> – ich habe privat ausgelegt und möchte das Geld zurück
            </label>
            <label style="font-weight:400;display:block;margin:4px 0">
                <input type="radio" name="modus" value="beleg">
                <strong>Nur Beleg abgeben</strong> – bezahlt hat der Verein (Karte/Bar), ich reiche nur den Beleg ein
            </label>
        </div>

        <div class="jb-field">
            <label for="jb-datum">Datum des Einkaufs *</label>
            <input type="date" id="jb-datum" name="ausgabe_datum"
                   value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
        </div>

        <div class="jb-field">
            <label for="jb-betrag">Betrag (€) *</label>
            <input type="number" id="jb-betrag" name="betrag" step="0.01" min="0.01"
                   placeholder="0,00" required>
        </div>

        <div class="jb-field">
            <label for="jb-kat">Kategorie *</label>
            <select id="jb-kat" name="kategorie" required>
                <?php foreach (jb_kategorien_ausgaben() as $k): ?>
                    <option value="<?= esc_attr($k) ?>"><?= esc_html($k) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <?php
        $jb_konten = function_exists('jb_konten_all') ? jb_konten_all() : [];
        if ($jb_konten): ?>
        <div class="jb-field">
            <label for="jb-konto">Konto (SKR)</label>
            <select id="jb-konto" name="konto">
                <option value="">– vom Kassier zuordnen –</option>
                <?php foreach ($jb_konten as $k):
                    if ($k->typ === 'einnahme' || $k->typ === 'bestand') continue; ?>
                    <option value="<?= esc_attr($k->nummer) ?>"><?= esc_html($k->nummer . ' · ' . $k->bezeichnung) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <?php
        $jb_budgets = function_exists('jb_budgets_get_all') ? jb_budgets_get_all() : [];
        if ($jb_budgets): ?>
        <div class="jb-field">
            <label for="jb-budget">Budget / Kostenstelle</label>
            <select id="jb-budget" name="budget_id">
                <option value="0">– keinem Budget zuordnen –</option>
                <?php foreach ($jb_budgets as $b):
                    $b = (object) $b;
                    $rest = (float) $b->betrag - (float) $b->ausgegeben; ?>
                    <option value="<?= (int) $b->id ?>">
                        <?= esc_html($b->zweck) ?><?= $b->jahr ? ' (' . (int) $b->jahr . ')' : '' ?> — Rest <?= number_format($rest, 2, ',', '.') ?> €
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="jb-field">
            <label for="jb-beschr">Was wurde gekauft? *</label>
            <textarea id="jb-beschr" name="beschreibung" rows="3"
                      placeholder="z. B. Putzmittel für Küche (Rewe)" required></textarea>
        </div>

        <div class="jb-field">
            <label for="jb-beleg">Beleg (Foto/PDF) *</label>
            <div class="jb-upload-area" id="jb-upload-area">
                <input type="file" id="jb-beleg" name="beleg"
                       accept=".pdf,.jpg,.jpeg,.png,.heic,.webp" required>
                <div class="jb-upload-hint">
                    <span class="jb-upload-icon">📎</span>
                    <span>Beleg hier hochladen oder antippen</span>
                    <span class="jb-upload-sub">PDF, JPG oder PNG · max. 10 MB</span>
                </div>
                <div id="jb-upload-preview" style="display:none"></div>
            </div>
        </div>

        <button type="submit" class="jb-btn jb-btn-primary" id="jb-submit-btn">
            Auslage einreichen
        </button>
    </form>

    <script>
    (function($) {
        // Preview uploaded file
        $('#jb-beleg').on('change', function() {
            const f = this.files[0];
            if (!f) return;
            const preview = $('#jb-upload-preview');
            preview.show().html('<strong>' + f.name + '</strong> (' + (f.size/1024).toFixed(0) + ' KB)');
            $('#jb-upload-hint').hide();
        });

        // Submit
        $('#jb-auslage-form').on('submit', function(e) {
            e.preventDefault();
            const btn = $('#jb-submit-btn').prop('disabled', true).text('Wird eingereicht…');
            const fd  = new FormData(this);
            fd.append('action', 'jb_submit_auslage');

            $.ajax({
                url: JB.ajax_url, type: 'POST', data: fd,
                processData: false, contentType: false,
                success(r) {
                    if (r.success) {
                        $('#jb-msg').addClass('jb-msg-success').html(
                            '✓ ' + r.data.message + ' Die Auslage bekommt die ID #' + r.data.id + '.'
                        ).show();
                        $('#jb-auslage-form')[0].reset();
                        $('#jb-upload-preview').hide();
                        $('#jb-upload-hint').show();
                    } else {
                        $('#jb-msg').addClass('jb-msg-error').html('✗ ' + r.data).show();
                    }
                    btn.prop('disabled', false).text('Auslage einreichen');
                },
                error() {
                    $('#jb-msg').addClass('jb-msg-error').html('✗ Verbindungsfehler.').show();
                    btn.prop('disabled', false).text('Auslage einreichen');
                }
            });
        });
    })(jQuery);
    </script>
</div>
