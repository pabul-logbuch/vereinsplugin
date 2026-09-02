<?php defined('ABSPATH') || exit;
$produkte = jb_getraenke_get_all();
?>
<div class="wrap jb-admin">
<h1>Getränkebestand</h1>

<div style="display:flex;gap:.7rem;margin-bottom:1.5rem;flex-wrap:wrap">
    <button class="button button-primary" id="jb-btn-lieferung">📦 Lieferung buchen</button>
    <button class="button button-primary" id="jb-btn-inventur">📋 Inventur</button>
    <button class="button" id="jb-btn-zettle">⬆ Zettle-Verkäufe importieren</button>
    <button class="button" id="jb-btn-neues-produkt">+ Neues Produkt</button>
</div>

<!-- Bestandsübersicht -->
<table class="wp-list-table widefat striped">
    <thead><tr><th>Produkt</th><th>Einheit</th><th>Bestand</th><th>Vollbestand</th><th>Fehlmenge</th><th>Warenwert</th><th>Preis</th><th>Aktion</th></tr></thead>
    <tbody>
    <?php foreach ($produkte as $p):
        $bestand   = (int)$p['bestand'];
        $fehlmenge = max(0, (int)$p['vollbestand'] - $bestand);
        $wert      = $bestand * (float)$p['preis'];
        $warn      = $bestand <= 0 ? 'style="color:#ef4444"' : ($fehlmenge > 0 ? 'style="color:#f59e0b"' : '');
    ?>
    <tr>
        <td><strong><?=esc_html($p['name'])?></strong></td>
        <td><?=esc_html($p['einheit'])?></td>
        <td <?=$warn?>><strong><?=$bestand?></strong></td>
        <td><?=esc_html($p['vollbestand'])?></td>
        <td><?=$fehlmenge > 0 ? '<span style="color:#ef4444">-'.$fehlmenge.'</span>' : '–'?></td>
        <td><?=number_format($wert,2,',','.')?> €</td>
        <td><?=number_format($p['preis'],2,',','.')?> €</td>
        <td>
            <button class="button button-small jb-korr" data-id="<?=$p['id']?>" data-name="<?=esc_attr($p['name'])?>">Korrektur</button>
            <button class="button button-small jb-edit-produkt"
                data-id="<?=$p['id']?>" data-name="<?=esc_attr($p['name'])?>"
                data-einheit="<?=esc_attr($p['einheit'])?>" data-preis="<?=$p['preis']?>"
                data-pfand="<?=$p['pfand']?>" data-voll="<?=$p['vollbestand']?>">✏</button>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
        <td colspan="5"><strong>Warenwert gesamt</strong></td>
        <td><strong><?=number_format(jb_getraenke_warenwert(),2,',','.')?> €</strong></td>
        <td colspan="2"></td>
    </tr></tfoot>
</table>

<!-- Modal: Lieferung -->
<div id="jb-modal-lieferung" class="jb-modal" style="display:none">
    <div class="jb-modal-box">
        <h3>📦 Lieferung buchen</h3>
        <p><label>Datum: <input type="date" id="jb-lief-datum" value="<?=date('Y-m-d')?>"></label>
           <label style="margin-left:1rem">Referenz: <input type="text" id="jb-lief-ref" placeholder="Rechnungs-Nr." style="width:180px"></label></p>
        <table style="width:100%;border-collapse:collapse">
            <thead><tr><th style="text-align:left">Produkt</th><th>Menge</th></tr></thead>
            <tbody id="jb-lief-rows">
            <?php foreach ($produkte as $p): ?>
            <tr>
                <td><?=esc_html($p['name'])?> <small>(<?=esc_html($p['einheit'])?>)</small></td>
                <td><input type="number" class="jb-lief-qty" data-id="<?=$p['id']?>" min="0" value="0"
                           style="width:70px;text-align:center"></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p><button class="button button-primary" id="jb-save-lieferung">Lieferung speichern</button>
           <button class="button jb-close-modal">Abbrechen</button></p>
    </div>
</div>

<!-- Modal: Inventur -->
<div id="jb-modal-inventur" class="jb-modal" style="display:none">
    <div class="jb-modal-box">
        <h3>📋 Inventur</h3>
        <p class="description">Gezählten Bestand eintragen. Die Differenz zum bisherigen Bestand wird als Korrektur gebucht.</p>
        <p><label>Datum: <input type="date" id="jb-inv-datum" value="<?=date('Y-m-d')?>"></label></p>
        <table style="width:100%;border-collapse:collapse">
            <thead><tr><th>Produkt</th><th>Aktuell</th><th>Gezählt</th></tr></thead>
            <tbody>
            <?php foreach ($produkte as $p): ?>
            <tr>
                <td><?=esc_html($p['name'])?></td>
                <td style="color:#6b7280"><?=$p['bestand']?></td>
                <td><input type="number" class="jb-inv-qty" data-id="<?=$p['id']?>"
                           value="<?=$p['bestand']?>" min="0" style="width:70px;text-align:center"></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p><button class="button button-primary" id="jb-save-inventur">Inventur speichern</button>
           <button class="button jb-close-modal">Abbrechen</button></p>
    </div>
</div>

<!-- Modal: Zettle Import -->
<div id="jb-modal-zettle" class="jb-modal" style="display:none">
    <div class="jb-modal-box">
        <h3>⬆ Zettle-Verkäufe importieren</h3>
        <p class="description">Exportiere aus Zettle den "Umsatz nach Produkt" als CSV (semikolon-getrennt) und lade ihn hier hoch. Spalten: <code>Produkt;Anzahl;...</code></p>
        <p><label>Datum der Verkäufe: <input type="date" id="jb-zettle-datum" value="<?=date('Y-m-d')?>"></label></p>
        <p><label>Referenz (z.B. Z-Bon #103): <input type="text" id="jb-zettle-ref" style="width:200px"></label></p>
        <p><input type="file" id="jb-zettle-file" accept=".csv,.txt"></p>
        <p><button class="button button-primary" id="jb-save-zettle">Importieren</button>
           <button class="button jb-close-modal">Abbrechen</button></p>
        <div id="jb-zettle-result" style="display:none;margin-top:.5rem"></div>
    </div>
</div>

<style>
.jb-modal { position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:9999;display:flex;align-items:center;justify-content:center }
.jb-modal-box { background:#fff;border-radius:12px;padding:1.5rem;max-width:620px;width:95%;max-height:80vh;overflow-y:auto }
.jb-modal-box h3 { margin:0 0 .8rem }
</style>
<script>
jQuery(function($) {
    function openModal(id) { $(id).show(); }
    function closeModals() { $('.jb-modal').hide(); }
    $('#jb-btn-lieferung').click(function() { openModal('#jb-modal-lieferung'); });
    $('#jb-btn-inventur').click(function()  { openModal('#jb-modal-inventur'); });
    $('#jb-btn-zettle').click(function()    { openModal('#jb-modal-zettle'); });
    $(document).on('click', '.jb-close-modal', closeModals);
    $(document).on('click', '.jb-modal', function(e) { if ($(e.target).hasClass('jb-modal')) closeModals(); });

    // Lieferung speichern
    $('#jb-save-lieferung').click(function() {
        const pos = [];
        $('.jb-lief-qty').each(function() {
            const q = parseInt($(this).val());
            if (q > 0) pos.push({ produkt_id: $(this).data('id'), menge: q });
        });
        if (!pos.length) { alert('Keine Mengen eingetragen.'); return; }
        $.post(ajaxurl, {
            action: 'jb_lieferung', nonce: JB.nonce,
            positionen: JSON.stringify(pos),
            datum: $('#jb-lief-datum').val(), referenz: $('#jb-lief-ref').val()
        }, function(r) { if (r.success) location.reload(); else alert('Fehler: ' + r.data); });
    });

    // Inventur speichern
    $('#jb-save-inventur').click(function() {
        const soll = {};
        $('.jb-inv-qty').each(function() { soll[$(this).data('id')] = $(this).val(); });
        $.post(ajaxurl, {
            action: 'jb_inventur', nonce: JB.nonce,
            soll: JSON.stringify(soll), datum: $('#jb-inv-datum').val()
        }, function(r) { if (r.success) location.reload(); else alert('Fehler: ' + r.data); });
    });

    // Korrektur
    $(document).on('click', '.jb-korr', function() {
        const id = $(this).data('id'), name = $(this).data('name');
        const menge = prompt('Korrektur für "' + name + '":\n+5 = 5 hinzufügen, -3 = 3 abziehen');
        if (!menge) return;
        $.post(ajaxurl, { action: 'jb_korrektur', nonce: JB.nonce, produkt_id: id, menge,
            datum: '<?=date("Y-m-d")?>' }, function(r) {
            if (r.success) location.reload(); else alert('Fehler: ' + r.data);
        });
    });

    // Zettle CSV Import
    $('#jb-save-zettle').click(function() {
        const file = $('#jb-zettle-file')[0].files[0];
        if (!file) { alert('Bitte eine CSV-Datei auswählen.'); return; }
        const reader = new FileReader();
        reader.onload = function(e) {
            $.post(ajaxurl, {
                action: 'jb_import_zettle', nonce: JB.nonce,
                csv: e.target.result,
                datum: $('#jb-zettle-datum').val(), referenz: $('#jb-zettle-ref').val()
            }, function(r) {
                if (r.success) {
                    const d = r.data;
                    let msg = '✓ ' + d.gebucht + ' Produkte importiert.';
                    if (d.nicht_gefunden.length) msg += '\n⚠ Nicht gefunden: ' + d.nicht_gefunden.join(', ');
                    $('#jb-zettle-result').show().html(msg.replace('\n','<br>')).css('color', d.nicht_gefunden.length ? '#92400e' : '#065f46');
                    if (!d.nicht_gefunden.length) setTimeout(() => location.reload(), 1500);
                } else alert('Fehler: ' + r.data);
            });
        };
        reader.readAsText(file, 'UTF-8');
    });
});
</script>
