<?php
// templates/admin/kassenbericht-dashboard.php
defined('ABSPATH') || exit;
$d = jb_get_dashboard_data();
?>
<div class="wrap jb-admin">
<h1>Kassenbericht</h1>
<p class="description">Kontostand manuell aktualisieren, alle anderen Werte werden automatisch berechnet.</p>

<!-- Kontostand eintragen -->
<form method="post" style="background:#f9fafb;padding:1rem;border-radius:10px;display:inline-flex;gap:1rem;align-items:flex-end;margin-bottom:1.5rem;border:1.5px solid #e5e7eb">
    <?php wp_nonce_field('jb_update_kontostand'); ?>
    <div><label style="font-weight:600;display:block;font-size:.85rem">Bankkonto (€)</label>
        <input type="number" name="bank" step="0.01" value="<?=esc_attr(get_option('jb_kontostand_bank',0))?>"
               style="width:140px;padding:.4rem;border-radius:6px;border:1.5px solid #d1d5db"></div>
    <div><label style="font-weight:600;display:block;font-size:.85rem">Bargeldkasse (€)</label>
        <input type="number" name="kasse" step="0.01" value="<?=esc_attr(get_option('jb_kontostand_kasse',0))?>"
               style="width:120px;padding:.4rem;border-radius:6px;border:1.5px solid #d1d5db"></div>
    <input type="hidden" name="jb_update_kontostand" value="1">
    <?php submit_button('Speichern', 'secondary', 'submit', false, ['style'=>'margin:0']); ?>
</form>

<!-- KPI Grid -->
<div class="jb-kpi-grid">
    <div class="jb-kpi"><div class="jb-kpi-label">Bankkonto</div><div class="jb-kpi-val"><?=number_format($d['bank'],2,',','.')?> €</div></div>
    <div class="jb-kpi"><div class="jb-kpi-label">Bargeldkasse</div><div class="jb-kpi-val"><?=number_format($d['kasse'],2,',','.')?> €</div></div>
    <div class="jb-kpi"><div class="jb-kpi-label">Kontostand gesamt</div><div class="jb-kpi-val"><?=number_format($d['kontostand'],2,',','.')?> €</div></div>
</div>
<div class="jb-kpi-grid" style="margin-top:.5rem">
    <div class="jb-kpi jb-kpi-orange"><div class="jb-kpi-label">− Rücklagenbedarf</div><div class="jb-kpi-val"><?=number_format($d['ruecklagen'],2,',','.')?> €</div></div>
    <div class="jb-kpi jb-kpi-orange"><div class="jb-kpi-label">− Verplantes Budget</div><div class="jb-kpi-val"><?=number_format($d['verplantes'],2,',','.')?> €</div></div>
    <div class="jb-kpi jb-kpi-orange"><div class="jb-kpi-label">− Offene Auslagen</div><div class="jb-kpi-val"><?=number_format($d['offene_auslagen'],2,',','.')?> €</div></div>
    <div class="jb-kpi <?=$d['frei']>=0?'jb-kpi-green':'jb-kpi-red'?>" style="border-width:2px">
        <div class="jb-kpi-label">= Freies Budget</div>
        <div class="jb-kpi-val" style="font-size:1.8rem"><?=number_format($d['frei'],2,',','.')?> €</div>
    </div>
</div>
<p style="color:#6b7280;font-size:.85rem;margin-top:.3rem">Getränkewert aktuell: <?=number_format($d['getraenke_wert'],2,',','.')?> € (nicht im Freien Budget enthalten)</p>
</div>
<?php

// Handle kontostand update
add_action('admin_init', function() {
    if (!isset($_POST['jb_update_kontostand'])) return;
    check_admin_referer('jb_update_kontostand');
    if (!jb_is_kassier()) return;
    update_option('jb_kontostand_bank',  (float)str_replace(',','.', $_POST['bank']  ?? 0));
    update_option('jb_kontostand_kasse', (float)str_replace(',','.', $_POST['kasse'] ?? 0));
    wp_redirect(admin_url('admin.php?page=jb_kassenbericht&updated=1'));
    exit;
});
