<?php defined('ABSPATH') || exit; ?>
<div class="wrap jb-admin">
    <h1>JuFo Buchhaltung – Übersicht <?= $year ?></h1>

    <?php if (!jb_nc()->is_configured()): ?>
    <div class="notice notice-warning"><p>
        Nextcloud noch nicht konfiguriert.
        <a href="<?= admin_url('admin.php?page=jb_settings') ?>">Jetzt einrichten →</a>
    </p></div>
    <?php endif; ?>

    <!-- KPI-Karten -->
    <div class="jb-kpi-grid">
        <div class="jb-kpi jb-kpi-green">
            <div class="jb-kpi-label">Einnahmen <?= $year ?></div>
            <div class="jb-kpi-val"><?= number_format($summary['total_einnahmen'], 2, ',', '.') ?> €</div>
        </div>
        <div class="jb-kpi jb-kpi-red">
            <div class="jb-kpi-label">Ausgaben <?= $year ?></div>
            <div class="jb-kpi-val"><?= number_format(abs($summary['total_ausgaben']), 2, ',', '.') ?> €</div>
        </div>
        <div class="jb-kpi <?= $summary['ueberschuss'] >= 0 ? 'jb-kpi-green' : 'jb-kpi-red' ?>">
            <div class="jb-kpi-label">Überschuss <?= $year ?></div>
            <div class="jb-kpi-val"><?= number_format($summary['ueberschuss'], 2, ',', '.') ?> €</div>
        </div>
        <div class="jb-kpi <?= count($pending) > 0 ? 'jb-kpi-orange' : 'jb-kpi-grey' ?>">
            <div class="jb-kpi-label">Auslagen ausstehend</div>
            <div class="jb-kpi-val"><?= count($pending) ?></div>
        </div>
    </div>

    <!-- Offene Auslagen -->
    <?php if (!empty($pending)): ?>
    <h2>Offene Auslagen <span class="jb-badge-count"><?= count($pending) ?></span></h2>
    <table class="wp-list-table widefat striped">
        <thead><tr>
            <th>#</th><th>Mitglied</th><th>Datum</th><th>Betrag</th><th>Beschreibung</th><th>Beleg</th><th>Aktion</th>
        </tr></thead>
        <tbody>
        <?php foreach ($pending as $a): ?>
        <tr id="jb-row-<?= $a['id'] ?>">
            <td><?= (int)$a['id'] ?></td>
            <td><?= esc_html($a['user_name']) ?></td>
            <td><?= esc_html($a['ausgabe_datum']) ?></td>
            <td><strong><?= number_format((float)$a['betrag'], 2, ',', '.') ?> €</strong></td>
            <td><?= esc_html($a['beschreibung']) ?><br>
                <small><?= esc_html($a['kategorie']) ?></small></td>
            <td>
                <?php if ($a['beleg_pfad']): ?>
                <a href="<?= esc_url(jb_nc()->get_download_url($a['beleg_pfad'])) ?>"
                   target="_blank" class="button button-small">📎 Beleg</a>
                <?php else: ?>
                <span class="jb-muted">–</span>
                <?php endif; ?>
            </td>
            <td>
                <div class="jb-action-row">
                    <input type="text" class="jb-notiz-field" placeholder="Notiz (optional)"
                           style="width:140px">
                    <button class="button button-primary jb-approve" data-id="<?= $a['id'] ?>">✓ Genehmigen</button>
                    <button class="button jb-reject" data-id="<?= $a['id'] ?>">✗ Ablehnen</button>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- Einnahmen/Ausgaben nach Kategorie -->
    <h2 style="margin-top:2rem">Nach Kategorie</h2>
    <table class="wp-list-table widefat striped">
        <thead><tr><th>Kategorie</th><th>Einnahmen</th><th>Ausgaben</th><th>Buchungen</th></tr></thead>
        <tbody>
        <?php foreach ($summary['kategorien'] as $k): ?>
        <tr>
            <td><?= esc_html($k['kategorie']) ?></td>
            <td><?= $k['einnahmen'] > 0 ? '<span style="color:#10b981">+'.number_format($k['einnahmen'],2,',','.').' €</span>' : '–' ?></td>
            <td><?= $k['ausgaben'] > 0 ? '<span style="color:#ef4444">–'.number_format($k['ausgaben'],2,',','.').' €</span>' : '–' ?></td>
            <td><?= (int)$k['anzahl'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p style="margin-top:1.5rem">
        <a href="<?= admin_url('admin.php?page=jb_export') ?>" class="button button-primary">EÜR / DATEV exportieren →</a>
        <a href="<?= admin_url('admin.php?page=jb_journal') ?>" class="button">Buchungsjournal →</a>
    </p>
</div>

<script>
jQuery(function($) {
    function decide(id, approve) {
        const row   = $('#jb-row-' + id);
        const notiz = row.find('.jb-notiz-field').val();
        $.post(ajaxurl, {
            action: 'jb_decide_auslage', nonce: JB.nonce,
            id, action_type: approve ? 'approve' : 'reject', notiz
        }, function(r) {
            if (r.success) {
                row.find('td:last').html(approve
                    ? '<span style="color:#10b981">✓ Genehmigt – <button class="button jb-mark-paid" data-id="'+id+'">Als ausgezahlt markieren</button></span>'
                    : '<span style="color:#ef4444">✗ Abgelehnt</span>');
                if (!approve) row.css('opacity', '.5');
            } else { alert('Fehler: ' + r.data); }
        });
    }

    $(document).on('click', '.jb-approve', function() { decide($(this).data('id'), true); });
    $(document).on('click', '.jb-reject',  function() { decide($(this).data('id'), false); });

    $(document).on('click', '.jb-mark-paid', function() {
        const id = $(this).data('id');
        $.post(ajaxurl, { action: 'jb_mark_paid', nonce: JB.nonce, id }, function(r) {
            if (r.success) {
                $('#jb-row-' + id).find('td:last').html('<span style="color:#6b7280">✓ Ausgezahlt & ins Journal</span>');
            } else { alert('Fehler: ' + r.data); }
        });
    });
});
</script>
