<?php defined('ABSPATH') || exit;
$year = (int)($_GET['year'] ?? date('Y'));
$summary = jb_journal_summary($year);
?>
<div class="jb-wrap">
    <h3>Kassenbericht <?=$year?></h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin:1rem 0">
        <div class="jb-box" style="background:#ecfdf5;border-color:#6ee7b7"><strong>Einnahmen</strong><br>+<?=number_format($summary['total_einnahmen'],2,',','.')?> €</div>
        <div class="jb-box" style="background:#fef2f2;border-color:#fca5a5"><strong>Ausgaben</strong><br>–<?=number_format(abs($summary['total_ausgaben']),2,',','.')?> €</div>
        <div class="jb-box <?=$summary['ueberschuss']>=0?'':'jb-warn'?>"><strong>Überschuss</strong><br><?=number_format($summary['ueberschuss'],2,',','.')?> €</div>
    </div>
</div>
