<?php defined('ABSPATH') || exit; ?>
<div class="wrap jb-admin">
    <h1>Buchungsjournal <?=$year?></h1>
    <p>Einnahmen: <strong style="color:#065f46">+<?=number_format($summary['total_einnahmen'],2,',','.')?> €</strong> &nbsp;|&nbsp;
       Ausgaben: <strong style="color:#991b1b">–<?=number_format(abs($summary['total_ausgaben']),2,',','.')?> €</strong> &nbsp;|&nbsp;
       Überschuss: <strong><?=number_format($summary['ueberschuss'],2,',','.')?> €</strong></p>
    <table class="wp-list-table widefat striped">
        <thead><tr><th>Datum</th><th>Betrag</th><th>Kategorie</th><th>Beschreibung</th><th>Quelle</th><th>Beleg</th></tr></thead>
        <tbody>
        <?php foreach ($entries as $e): $is_ein=(float)$e['betrag']>=0; ?>
        <tr>
            <td><?=esc_html($e['buchung_datum'])?></td>
            <td style="color:<?=$is_ein?'#065f46':'#991b1b'?>;font-weight:600"><?=($is_ein?'+':'').number_format((float)$e['betrag'],2,',','.')?> €</td>
            <td><?=esc_html($e['kategorie'])?></td>
            <td><?=esc_html($e['beschreibung'])?></td>
            <td><span class="jb-badge" style="background:#6b7280;font-size:.7rem"><?=esc_html($e['quelle'])?></span></td>
            <td><?=$e['beleg_pfad']?'<a href="'.esc_url(jb_nc()->get_download_url($e['beleg_pfad'])).'" target="_blank">📎</a>':($e['beleg_referenz']?esc_html($e['beleg_referenz']):'–')?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p><a href="<?=admin_url('admin.php?page=jb_export')?>" class="button button-primary">Exportieren</a></p>
</div>
