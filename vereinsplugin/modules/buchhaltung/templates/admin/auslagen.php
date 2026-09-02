<?php defined('ABSPATH') || exit;
$status_labels = ['ausstehend'=>'Ausstehend','genehmigt'=>'Genehmigt','abgelehnt'=>'Abgelehnt','ausgezahlt'=>'Ausgezahlt'];
?>
<div class="wrap jb-admin">
    <h1>Auslagen</h1>
    <ul class="subsubsub">
        <?php foreach ([''=>'Alle','ausstehend'=>'Ausstehend','genehmigt'=>'Genehmigt','ausgezahlt'=>'Ausgezahlt'] as $s=>$l): ?>
        <li><a href="<?=admin_url('admin.php?page=jb_auslagen&status='.$s.'&year='.$year)?>" <?=$status===$s?'class="current"':''?>><?=$l?></a> |</li>
        <?php endforeach; ?>
    </ul>
    <table class="wp-list-table widefat striped" style="margin-top:1rem">
        <thead><tr><th>#</th><th>Mitglied</th><th>Datum</th><th>Betrag</th><th>Beschreibung</th><th>Status</th><th>Beleg</th><th>Aktion</th></tr></thead>
        <tbody>
        <?php foreach ($auslagen as $a): ?>
        <tr id="jb-row-<?=$a['id']?>">
            <td><?=(int)$a['id']?></td>
            <td><?=esc_html($a['user_name'])?></td>
            <td><?=esc_html($a['ausgabe_datum'])?></td>
            <td><strong><?=number_format((float)$a['betrag'],2,',','.')?> €</strong></td>
            <td><?=esc_html($a['beschreibung'])?><br><small><?=esc_html($a['kategorie'])?></small></td>
            <td><span class="jb-badge" style="background:<?=['ausstehend'=>'#f59e0b','genehmigt'=>'#10b981','abgelehnt'=>'#ef4444','ausgezahlt'=>'#6b7280'][$a['status']]??'#999'?>"><?=$status_labels[$a['status']]??$a['status']?></span></td>
            <td><?=$a['beleg_pfad']?'<a href="'.esc_url(jb_nc()->get_download_url($a['beleg_pfad'])).'" target="_blank" class="button button-small">📎</a>':'–'?></td>
            <td>
            <?php if ($a['status']==='ausstehend' && jb_can_approve()): ?>
                <button class="button button-primary jb-approve" data-id="<?=$a['id']?>">✓</button>
                <button class="button jb-reject" data-id="<?=$a['id']?>">✗</button>
            <?php elseif ($a['status']==='genehmigt' && current_user_can('jb_mark_paid')): ?>
                <button class="button jb-mark-paid" data-id="<?=$a['id']?>">Ausgezahlt</button>
            <?php else: echo '–'; endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<script>
jQuery(function($){
    function decide(id,approve){
        $.post(ajaxurl,{action:'jb_decide_auslage',nonce:JB.nonce,id,action_type:approve?'approve':'reject'},function(r){
            if(r.success) location.reload(); else alert('Fehler: '+r.data);
        });
    }
    $(document).on('click','.jb-approve',function(){decide($(this).data('id'),true);});
    $(document).on('click','.jb-reject',function(){decide($(this).data('id'),false);});
    $(document).on('click','.jb-mark-paid',function(){
        var id=$(this).data('id');
        $.post(ajaxurl,{action:'jb_mark_paid',nonce:JB.nonce,id},function(r){
            if(r.success) location.reload(); else alert('Fehler: '+r.data);
        });
    });
});
</script>
