<?php
defined('ABSPATH') || exit;

/**
 * [protokollpro_oeffentlich gremium="MV"]
 * Zeigt veröffentlichte Protokolle (sichtbarkeit = "oeffentlich") auf der
 * Website an — z. B. für die Pflicht, MV-Protokolle öffentlich auszuhängen.
 */
add_shortcode('protokollpro_oeffentlich', 'pp_shortcode_oeffentliche_protokolle');
function pp_shortcode_oeffentliche_protokolle($atts) {
    global $wpdb;
    $rows = $wpdb->get_results("
        SELECT p.*, g.name AS gremium_name
        FROM {$wpdb->prefix}pp_protokolle p
        LEFT JOIN {$wpdb->prefix}pp_gremien g ON g.id = p.gremium_id
        WHERE p.sichtbarkeit = 'oeffentlich' AND p.status = 'abgeschlossen'
        ORDER BY p.datum DESC
    ");

    ob_start();
    echo '<div class="pp-public-list">';
    if (empty($rows)) {
        echo '<p>Noch keine veröffentlichten Protokolle.</p>';
    }
    foreach ($rows as $p) {
        echo '<div class="pp-public-item">';
        echo '<h3>' . esc_html($p->titel) . '</h3>';
        echo '<p class="pp-public-meta">' . esc_html($p->gremium_name) . ' · ' . esc_html($p->datum) . ($p->ort ? ' · ' . esc_html($p->ort) : '') . '</p>';

        $tops = pp_get_tops_fuer_protokoll($p->id);
        if ($tops) {
            echo '<ul class="pp-public-tops">';
            foreach ($tops as $top) {
                if ($top->konsent_status !== 'beschlossen') continue;
                echo '<li><strong>' . esc_html($top->titel) . ':</strong> ' . esc_html($top->beschluss ?: $top->beschreibung) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }
    echo '</div>';
    return ob_get_clean();
}

/**
 * [protokollpro_kreis id="5"]
 * Öffentlicher Steckbrief eines einzelnen Gremiums/Kreises: Beschreibung,
 * Rollen mit Zuständigkeiten, benötigten Fähigkeiten und aktueller Besetzung.
 * Ein flexibler, parametrisierter Shortcode statt eines eigenen Shortcode-
 * Namens je Kreis — die ID steht auf der Gremien-Seite im Backend.
 */
add_shortcode('protokollpro_kreis', 'pp_shortcode_kreis');
function pp_shortcode_kreis($atts) {
    $atts = shortcode_atts(['id' => 0, 'name' => ''], $atts);
    $gremium = null;

    if (!empty($atts['id'])) {
        $gremium = pp_get_gremium(intval($atts['id']));
    } elseif (!empty($atts['name'])) {
        global $wpdb;
        $gremium = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}pp_gremien WHERE name = %s", $atts['name']));
    }

    if (!$gremium) {
        return '<p><em>ProtokollPro: Gremium nicht gefunden. Bitte gültige id oder name angeben.</em></p>';
    }

    ob_start();
    ?>
    <div class="pp-kreis-steckbrief">
        <h2><?php echo esc_html($gremium->name); ?></h2>
        <p class="pp-public-meta"><?php echo esc_html(pp_gremientyp_label($gremium->typ)); ?></p>
        <?php if ($gremium->beschreibung) : ?><p><?php echo esc_html($gremium->beschreibung); ?></p><?php endif; ?>

        <?php $rollenvorlagen = pp_get_rollenvorlagen_fuer_gremium($gremium->id); ?>
        <?php foreach ($rollenvorlagen as $rv) :
            $verantwortlich = pp_textliste_zu_array($rv->verantwortlich_fuer);
            $faehigkeiten   = pp_textliste_zu_array($rv->benoetigte_faehigkeiten);
            $besetzungen    = pp_get_aktuelle_besetzungen($rv->id);
        ?>
            <div class="pp-kreis-rolle">
                <h3><?php echo esc_html($rv->bezeichnung); ?></h3>
                <?php if ($besetzungen) : ?>
                    <p><strong>Aktuell:</strong> <?php echo esc_html(implode(', ', array_map(function ($b) { return pp_user_display_name($b->user_id); }, $besetzungen))); ?></p>
                <?php else : ?>
                    <p><em>Aktuell nicht besetzt</em></p>
                <?php endif; ?>
                <?php if ($verantwortlich) : ?>
                    <p><strong>Verantwortlich für:</strong></p>
                    <ul><?php foreach ($verantwortlich as $v) echo '<li>' . esc_html($v) . '</li>'; ?></ul>
                <?php endif; ?>
                <?php if ($faehigkeiten) : ?>
                    <p><strong>Hilfreiche Fähigkeiten:</strong></p>
                    <ul><?php foreach ($faehigkeiten as $f) echo '<li>' . esc_html($f) . '</li>'; ?></ul>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if (empty($rollenvorlagen)) : ?><p><em>Noch keine Rollen definiert.</em></p><?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
