<?php defined('ABSPATH') || exit;
$budgets    = jb_budgets_get_all();
$ruecklagen = jb_ruecklagen_get_all();
?>
<div class="wrap jb-admin">
<h1>Budgets & Rücklagen</h1>

<!-- ═══════════ VERPLANTES BUDGET ═══════════ -->
<h2>Verplantes Budget
    <button class="button button-small" id="jb-new-budget" style="margin-left:.5rem">+ Neu</button>
</h2>

<div id="jb-budget-form" class="jb-form-box" style="display:none;margin-bottom:1rem">
    <h3 id="jb-budget-form-title">Neues Budget</h3>
    <input type="hidden" id="jb-budget-id" value="">
    <table class="form-table" style="margin:0">
        <tr><th>Zweck</th><td><input type="text" id="jb-b-zweck" class="regular-text" placeholder="z.B. Skatehalle"></td></tr>
        <tr><th>Beschreibung</th><td><input type="text" id="jb-b-beschr" class="regular-text"></td></tr>
        <tr><th>Betrag (€)</th><td><input type="number" id="jb-b-betrag" step="0.01" min="0" style="width:120px"></td></tr>
        <tr><th>Bereits ausgegeben (€)</th><td><input type="number" id="jb-b-ausgegeben" step="0.01" min="0" style="width:120px" value="0"></td></tr>
        <tr><th>Notiz</th><td><textarea id="jb-b-notiz" rows="2" class="large-text"></textarea></td></tr>
    </table>
    <p><button class="button button-primary" id="jb-save-budget">Speichern</button>
       <button class="button" id="jb-cancel-budget">Abbrechen</button></p>
</div>

<table class="wp-list-table widefat striped">
    <thead><tr><th>Zweck</th><th>Betrag</th><th>Ausgegeben</th><th>Rest</th><th>Notiz</th><th>Aktion</th></tr></thead>
    <tbody id="jb-budget-list">
    <?php foreach ($budgets as $b):
        $rest  = (float)$b['betrag'] - (float)$b['ausgegeben'];
        $pct   = $b['betrag'] > 0 ? min(100, round($b['ausgegeben']/$b['betrag']*100)) : 0;
    ?>
    <tr id="jb-brow-<?=$b['id']?>">
        <td><strong><?=esc_html($b['zweck'])?></strong><br><small><?=esc_html($b['beschreibung'])?></small></td>
        <td><?=number_format($b['betrag'],2,',','.')?> €</td>
        <td>
            <?=number_format($b['ausgegeben'],2,',','.')?> €
            <div style="height:4px;background:#e5e7eb;border-radius:2px;margin-top:3px">
                <div style="width:<?=$pct?>%;height:100%;background:<?=$pct>=90?'#ef4444':'#3b82f6'?>;border-radius:2px"></div>
            </div>
        </td>
        <td style="font-weight:700;color:<?=$rest<0?'#ef4444':'#065f46'?>"><?=number_format($rest,2,',','.')?> €</td>
        <td><?=esc_html($b['notiz'])?></td>
        <td>
            <button class="button button-small jb-edit-budget" data-id="<?=$b['id']?>"
                data-zweck="<?=esc_attr($b['zweck'])?>" data-beschr="<?=esc_attr($b['beschreibung'])?>"
                data-betrag="<?=$b['betrag']?>" data-ausgegeben="<?=$b['ausgegeben']?>"
                data-notiz="<?=esc_attr($b['notiz'])?>">Bearbeiten</button>
            <button class="button button-small jb-del-budget" data-id="<?=$b['id']?>">Löschen</button>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
        <td><strong>Gesamt</strong></td>
        <td><?=number_format(array_sum(array_column($budgets,'betrag')),2,',','.')?> €</td>
        <td><?=number_format(array_sum(array_column($budgets,'ausgegeben')),2,',','.')?> €</td>
        <td><strong><?=number_format(jb_budgets_rest_total(),2,',','.')?> €</strong></td>
        <td colspan="2"></td>
    </tr></tfoot>
</table>

<!-- ═══════════ RÜCKLAGEN ═══════════ -->
<h2 style="margin-top:2rem">Rücklagen (wiederkehrende Kosten)
    <button class="button button-small" id="jb-new-rl" style="margin-left:.5rem">+ Neu</button>
</h2>
<p class="description">Jeder Eintrag berechnet automatisch wie viel Geld seit der letzten Zahlung "fällig" angespart werden sollte.</p>

<div id="jb-rl-form" class="jb-form-box" style="display:none;margin-bottom:1rem">
    <h3>Rücklage</h3>
    <input type="hidden" id="jb-rl-id" value="">
    <table class="form-table" style="margin:0">
        <tr><th>Bezeichnung</th><td><input type="text" id="jb-rl-bez" class="regular-text" placeholder="z.B. Allianz Versicherung"></td></tr>
        <tr><th>Betrag pro Fälligkeit (€)</th><td><input type="number" id="jb-rl-betrag" step="0.01" min="0" style="width:120px"></td></tr>
        <tr><th>Intervall (Monate)</th><td>
            <input type="number" id="jb-rl-intervall" min="1" max="60" style="width:80px" value="12">
            <span class="description">1 = monatlich, 12 = jährlich, 24 = alle 2 Jahre</span>
        </td></tr>
        <tr><th>Letzte Zahlung</th><td><input type="date" id="jb-rl-datum"></td></tr>
        <tr><th>Notiz</th><td><input type="text" id="jb-rl-notiz" class="regular-text"></td></tr>
    </table>
    <p><button class="button button-primary" id="jb-save-rl">Speichern</button>
       <button class="button" id="jb-cancel-rl">Abbrechen</button></p>
</div>

<table class="wp-list-table widefat striped">
    <thead><tr><th>Bezeichnung</th><th>Betrag</th><th>Intervall</th><th>Letzte Zahlung</th><th>Nächste Fälligkeit</th><th>Monatl. Bedarf</th><th>Rücklage jetzt</th><th>Aktion</th></tr></thead>
    <tbody>
    <?php foreach ($ruecklagen as $r): ?>
    <tr id="jb-rlrow-<?=$r['id']?>">
        <td><strong><?=esc_html($r['bezeichnung'])?></strong><br><small><?=esc_html($r['notiz'])?></small></td>
        <td><?=number_format($r['betrag'],2,',','.')?> €</td>
        <td><?=$r['intervall_monate']?> Mon.</td>
        <td><?=esc_html($r['letzte_zahlung'])?></td>
        <td><?=esc_html($r['naechste_faelligkeit'])?></td>
        <td><?=number_format($r['monatlicher_bedarf'],2,',','.')?> €/Mon.</td>
        <td><strong><?=number_format($r['ruecklage_jetzt'],2,',','.')?> €</strong><br>
            <small style="color:#6b7280">(<?=$r['monate_seit_zahlung']?> Mon. seit Zahlung)</small></td>
        <td>
            <button class="button button-small jb-edit-rl" data-id="<?=$r['id']?>"
                data-bez="<?=esc_attr($r['bezeichnung'])?>" data-betrag="<?=$r['betrag']?>"
                data-intervall="<?=$r['intervall_monate']?>" data-datum="<?=$r['letzte_zahlung']?>"
                data-notiz="<?=esc_attr($r['notiz'])?>">Bearbeiten</button>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
        <td colspan="6"><strong>Rücklagenbedarf gesamt</strong></td>
        <td><strong><?=number_format(jb_ruecklagen_bedarf_gesamt(),2,',','.')?> €</strong></td>
        <td></td>
    </tr></tfoot>
</table>
</div>

<style>
.jb-form-box { background: #f9fafb; border: 1.5px solid #e5e7eb; border-radius: 10px; padding: 1.2rem; }
</style>
<script>
jQuery(function($) {
    // Budget
    $('#jb-new-budget').click(function() {
        $('#jb-budget-id').val('');
        $('#jb-b-zweck,#jb-b-beschr,#jb-b-notiz').val('');
        $('#jb-b-betrag,#jb-b-ausgegeben').val('0');
        $('#jb-budget-form-title').text('Neues Budget');
        $('#jb-budget-form').slideDown();
    });
    $('#jb-cancel-budget').click(function() { $('#jb-budget-form').slideUp(); });
    $(document).on('click', '.jb-edit-budget', function() {
        const d = $(this).data();
        $('#jb-budget-id').val(d.id);
        $('#jb-b-zweck').val(d.zweck); $('#jb-b-beschr').val(d.beschr);
        $('#jb-b-betrag').val(d.betrag); $('#jb-b-ausgegeben').val(d.ausgegeben);
        $('#jb-b-notiz').val(d.notiz);
        $('#jb-budget-form-title').text('Budget bearbeiten');
        $('#jb-budget-form').slideDown();
    });
    $('#jb-save-budget').click(function() {
        $.post(ajaxurl, {
            action: 'jb_save_budget', nonce: JB.nonce,
            id: $('#jb-budget-id').val(), zweck: $('#jb-b-zweck').val(),
            beschreibung: $('#jb-b-beschr').val(), betrag: $('#jb-b-betrag').val(),
            ausgegeben: $('#jb-b-ausgegeben').val(), notiz: $('#jb-b-notiz').val()
        }, function(r) { if (r.success) location.reload(); else alert('Fehler: ' + r.data); });
    });
    $(document).on('click', '.jb-del-budget', function() {
        if (!confirm('Wirklich löschen?')) return;
        const id = $(this).data('id');
        $.post(ajaxurl, { action: 'jb_delete_budget', nonce: JB.nonce, id }, function(r) {
            if (r.success) $('#jb-brow-' + id).fadeOut(300, function() { $(this).remove(); });
        });
    });

    // Rücklagen
    $('#jb-new-rl').click(function() {
        $('#jb-rl-id').val(''); $('#jb-rl-bez,#jb-rl-notiz').val('');
        $('#jb-rl-betrag').val(''); $('#jb-rl-intervall').val('12');
        $('#jb-rl-datum').val('<?=date('Y-m-d')?>');
        $('#jb-rl-form').slideDown();
    });
    $('#jb-cancel-rl').click(function() { $('#jb-rl-form').slideUp(); });
    $(document).on('click', '.jb-edit-rl', function() {
        const d = $(this).data();
        $('#jb-rl-id').val(d.id); $('#jb-rl-bez').val(d.bez);
        $('#jb-rl-betrag').val(d.betrag); $('#jb-rl-intervall').val(d.intervall);
        $('#jb-rl-datum').val(d.datum); $('#jb-rl-notiz').val(d.notiz);
        $('#jb-rl-form').slideDown();
    });
    $('#jb-save-rl').click(function() {
        $.post(ajaxurl, {
            action: 'jb_save_ruecklage', nonce: JB.nonce,
            id: $('#jb-rl-id').val(), bezeichnung: $('#jb-rl-bez').val(),
            betrag: $('#jb-rl-betrag').val(), intervall_monate: $('#jb-rl-intervall').val(),
            letzte_zahlung: $('#jb-rl-datum').val(), notiz: $('#jb-rl-notiz').val()
        }, function(r) { if (r.success) location.reload(); else alert('Fehler: ' + r.data); });
    });
});
</script>
