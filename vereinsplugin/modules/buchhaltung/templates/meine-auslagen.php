<?php defined('ABSPATH') || exit;
$auslagen = jb_get_auslagen(['user_id' => get_current_user_id()]);
$status_labels = [
    'ausstehend' => ['label' => 'Ausstehend',  'color' => '#f59e0b'],
    'genehmigt'  => ['label' => 'Genehmigt',   'color' => '#10b981'],
    'abgelehnt'  => ['label' => 'Abgelehnt',   'color' => '#ef4444'],
    'ausgezahlt' => ['label' => 'Ausgezahlt ✓','color' => '#6b7280'],
];
?>
<div class="jb-wrap">
    <h3>Meine Auslagen</h3>

    <?php if (empty($auslagen)): ?>
        <div class="jb-box jb-info">Du hast noch keine Auslagen eingereicht.
            <a href="<?= esc_url(home_url('/auslage-einreichen/')) ?>" class="jb-btn jb-btn-sm">Jetzt einreichen</a>
        </div>
    <?php else: ?>
        <div class="jb-table-wrap">
        <table class="jb-table">
            <thead>
                <tr>
                    <th>#</th><th>Datum</th><th>Betrag</th><th>Beschreibung</th><th>Status</th><th>Notiz</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($auslagen as $a):
                $s = $status_labels[$a['status']] ?? ['label' => $a['status'], 'color' => '#999'];
            ?>
                <tr>
                    <td><?= (int)$a['id'] ?></td>
                    <td><?= esc_html($a['ausgabe_datum']) ?></td>
                    <td><strong><?= number_format((float)$a['betrag'], 2, ',', '.') ?> €</strong></td>
                    <td><?= esc_html($a['beschreibung']) ?><br>
                        <small class="jb-muted"><?= esc_html($a['kategorie']) ?></small></td>
                    <td><span class="jb-badge" style="background:<?= $s['color'] ?>"><?= $s['label'] ?></span></td>
                    <td><?= $a['kassier_notiz'] ? esc_html($a['kassier_notiz']) : '–' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <p class="jb-summary">
            Gesamt eingereicht: <strong><?= count($auslagen) ?></strong> Auslagen ·
            Ausgezahlt: <strong><?= array_sum(array_column(array_filter($auslagen, fn($a) => $a['status']==='ausgezahlt'), 'betrag')) ?> €</strong>
        </p>
    <?php endif; ?>

    <a href="<?= esc_url(home_url('/auslage-einreichen/')) ?>" class="jb-btn jb-btn-primary">
        + Neue Auslage einreichen
    </a>
</div>
