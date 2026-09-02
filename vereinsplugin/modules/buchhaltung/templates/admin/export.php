<?php defined('ABSPATH') || exit; ?>
<div class="wrap jb-admin">
    <h1>Export – JuFo Buchhaltung</h1>

    <p>Wähle Jahr und Format. Die Datei wird sofort heruntergeladen.</p>

    <form method="get" action="">
        <input type="hidden" name="page" value="jb_export">
        <label><strong>Jahr:</strong>
            <select name="year" style="margin:0 .5rem">
                <?php for ($y = date('Y'); $y >= 2025; $y--): ?>
                    <option value="<?= $y ?>" <?= selected($y, $year, false) ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </label>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem;margin-top:1.5rem">

            <div class="jb-export-card">
                <h3>📊 EÜR (CSV)</h3>
                <p>Alle Einnahmen und Ausgaben als CSV. Ideal für eigene Auswertungen in Excel.</p>
                <a href="<?= wp_nonce_url(admin_url('admin.php?page=jb_export&jb_export=euer&year='.$year), 'jb_export_'.$year) ?>"
                   class="button button-primary">EÜR <?= $year ?> herunterladen</a>
            </div>

            <div class="jb-export-card">
                <h3>🏛️ DATEV (CSV)</h3>
                <p>DATEV-kompatibler Buchungsstapel im EXTF-Format. Direkt für den Steuerberater.</p>
                <a href="<?= wp_nonce_url(admin_url('admin.php?page=jb_export&jb_export=datev&year='.$year), 'jb_export_'.$year) ?>"
                   class="button button-primary">DATEV <?= $year ?> herunterladen</a>
            </div>

            <div class="jb-export-card">
                <h3>🧾 Auslagen (CSV)</h3>
                <p>Alle Auslagen-Anträge mit Status. Für den internen Überblick.</p>
                <a href="<?= wp_nonce_url(admin_url('admin.php?page=jb_export&jb_export=auslagen&year='.$year), 'jb_export_'.$year) ?>"
                   class="button">Auslagen <?= $year ?> herunterladen</a>
            </div>

        </div>
    </form>
</div>
