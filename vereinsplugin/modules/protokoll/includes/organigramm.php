<?php
defined('ABSPATH') || exit;

/**
 * Baut einen Baum aus allen aktiven Gremien (parent_gremium_id-Beziehungen).
 * Gremien ohne parent_gremium_id hängen an einem virtuellen Zentrum (id=0),
 * das für den Vereinsnamen steht — daraus ergibt sich die radiale/
 * mindmap-artige Anordnung statt einer klassischen Top-Down-Hierarchie.
 */
function pp_build_gremien_baum() {
    $gremien = pp_get_gremien(null, true);
    $von_id = [0 => (object) ['id' => 0, 'name' => get_bloginfo('name') ?: 'Verein', 'typ' => 'zentrum']];
    $kinder = [0 => []];

    foreach ($gremien as $g) {
        $von_id[$g->id] = $g;
        $kinder[$g->id] = [];
    }
    foreach ($gremien as $g) {
        $parent = $g->parent_gremium_id && isset($von_id[$g->parent_gremium_id]) ? $g->parent_gremium_id : 0;
        $kinder[$parent][] = $g->id;
    }

    return ['von_id' => $von_id, 'kinder' => $kinder];
}

function pp_baum_blattzahl($id, $kinder) {
    if (empty($kinder[$id])) return 1;
    $summe = 0;
    foreach ($kinder[$id] as $kid) $summe += pp_baum_blattzahl($kid, $kinder);
    return max(1, $summe);
}

/** Weist rekursiv Positionen (x,y) im Kreis zu, Radius wächst je Tiefe. */
function pp_baum_positionen($id, $kinder, $winkel_start, $winkel_ende, $tiefe, $cx, $cy, $ring_abstand, &$positionen) {
    $radius = $tiefe * $ring_abstand;
    $mitte_winkel = ($winkel_start + $winkel_ende) / 2;
    $positionen[$id] = [
        'x' => $cx + $radius * cos($mitte_winkel),
        'y' => $cy + $radius * sin($mitte_winkel),
        'tiefe' => $tiefe,
    ];

    $kids = $kinder[$id] ?? [];
    if (empty($kids)) return;

    $gesamt_blaetter = 0;
    foreach ($kids as $kid) $gesamt_blaetter += pp_baum_blattzahl($kid, $kinder);

    $winkel_cursor = $winkel_start;
    $spanne = $winkel_ende - $winkel_start;
    foreach ($kids as $kid) {
        $anteil = pp_baum_blattzahl($kid, $kinder) / max(1, $gesamt_blaetter);
        $kid_spanne = $spanne * $anteil;
        pp_baum_positionen($kid, $kinder, $winkel_cursor, $winkel_cursor + $kid_spanne, $tiefe + 1, $cx, $cy, $ring_abstand, $positionen);
        $winkel_cursor += $kid_spanne;
    }
}

function pp_gremientyp_farbe($typ) {
    $farben = [
        'zentrum'          => '#1d2327',
        'mv'               => '#7c3aed',
        'vorstand'         => '#2563eb',
        'leitungskreis'    => '#0d9488',
        'kreis'            => '#ea580c',
        'kreisversammlung' => '#ca8a04',
    ];
    return $farben[$typ] ?? '#64748b';
}

function pp_render_organigramm_svg() {
    $baum = pp_build_gremien_baum();
    $positionen = [];
    $cx = 420; $cy = 420; $ring_abstand = 230;

    pp_baum_positionen(0, $baum['kinder'], -M_PI / 2, -M_PI / 2 + 2 * M_PI, 0, $cx, $cy, $ring_abstand, $positionen);

    // Canvas-Größe an tatsächliche Tiefe anpassen
    $max_tiefe = 0;
    foreach ($positionen as $p) $max_tiefe = max($max_tiefe, $p['tiefe']);
    $groesse = ($max_tiefe + 1) * $ring_abstand * 2 + 160;
    $cx = $groesse / 2; $cy = $groesse / 2;
    $positionen = [];
    pp_baum_positionen(0, $baum['kinder'], -M_PI / 2, -M_PI / 2 + 2 * M_PI, 0, $cx, $cy, $ring_abstand, $positionen);

    ob_start();
    ?>
    <div class="pp-organigramm-wrap">
        <svg viewBox="0 0 <?php echo esc_attr($groesse); ?> <?php echo esc_attr($groesse); ?>" xmlns="http://www.w3.org/2000/svg" class="pp-organigramm-svg">
            <?php
            // Verbindungslinien zuerst (liegen unter den Knoten)
            foreach ($baum['kinder'] as $parent_id => $kids) {
                if (!isset($positionen[$parent_id])) continue;
                $p1 = $positionen[$parent_id];
                foreach ($kids as $kid) {
                    if (!isset($positionen[$kid])) continue;
                    $p2 = $positionen[$kid];
                    echo '<line x1="' . esc_attr($p1['x']) . '" y1="' . esc_attr($p1['y']) . '" x2="' . esc_attr($p2['x']) . '" y2="' . esc_attr($p2['y']) . '" class="pp-org-line" />';
                }
            }

            // Knoten
            foreach ($positionen as $id => $pos) {
                $g = $baum['von_id'][$id];
                $farbe = pp_gremientyp_farbe($g->typ);
                $radius = $pos['tiefe'] === 0 ? 74 : ($pos['tiefe'] === 1 ? 60 : ($pos['tiefe'] === 2 ? 48 : 40));
                $font = $pos['tiefe'] === 0 ? 17 : ($pos['tiefe'] === 1 ? 15 : 13);
                ?>
                <g class="pp-org-node">
                    <circle cx="<?php echo esc_attr($pos['x']); ?>" cy="<?php echo esc_attr($pos['y']); ?>" r="<?php echo esc_attr($radius); ?>" fill="<?php echo esc_attr($farbe); ?>" />
                    <title><?php echo esc_html($g->name . ($g->typ !== 'zentrum' ? ' (' . pp_gremientyp_label($g->typ) . ')' : '')); ?></title>
                    <?php
                    $zeilen = pp_svg_textzeilen($g->name, $radius);
                    $anzahl_zeilen = count($zeilen);
                    foreach ($zeilen as $i => $zeile) :
                    ?>
                        <text x="<?php echo esc_attr($pos['x']); ?>" y="<?php echo esc_attr($pos['y'] + ($i * ($font + 2)) - ($anzahl_zeilen - 1) * ($font + 2) / 2); ?>"
                              text-anchor="middle" dominant-baseline="middle" font-size="<?php echo esc_attr($font); ?>" fill="#fff" class="pp-org-label">
                            <?php echo esc_html($zeile); ?>
                        </text>
                    <?php endforeach; ?>
                </g>
                <?php
            }
            ?>
        </svg>

        <div class="pp-org-legende">
            <p class="pp-org-legende-intro">Jeder Kreis ist ein Gremium, farblich nach Typ. Die Linien zeigen, welchem übergeordneten Gremium ein Kreis zugeordnet ist (z. B. eine Kreisversammlung ihrem Kreis).</p>
            <div class="pp-org-legende-items">
                <span class="pp-org-legende-item"><span class="pp-org-legende-dot" style="background:<?php echo esc_attr(pp_gremientyp_farbe('zentrum')); ?>"></span> Verein insgesamt (Mitte)</span>
                <?php foreach (['mv' => 'Mitgliederversammlung', 'vorstand' => 'Vorstand', 'leitungskreis' => 'Leitungskreis', 'kreis' => 'Kreis', 'kreisversammlung' => 'Kreisversammlung'] as $typ => $label) : ?>
                    <span class="pp-org-legende-item"><span class="pp-org-legende-dot" style="background:<?php echo esc_attr(pp_gremientyp_farbe($typ)); ?>"></span> <?php echo esc_html($label); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/** Bricht einen Knotennamen in bis zu 3 kurze Zeilen um, damit er in den Kreis passt. */
function pp_svg_textzeilen($name, $radius) {
    $max_zeichen = max(6, intval($radius / 3.2));
    $woerter = explode(' ', $name);
    $zeilen = [];
    $aktuell = '';
    foreach ($woerter as $wort) {
        $test = trim($aktuell . ' ' . $wort);
        if (mb_strlen($test) > $max_zeichen && $aktuell !== '') {
            $zeilen[] = $aktuell;
            $aktuell = $wort;
        } else {
            $aktuell = $test;
        }
        if (count($zeilen) >= 2) break;
    }
    if ($aktuell !== '' && count($zeilen) < 3) $zeilen[] = $aktuell;
    return array_slice($zeilen, 0, 3);
}

add_shortcode('protokollpro_organigramm', 'pp_shortcode_organigramm');
function pp_shortcode_organigramm($atts) {
    return pp_render_organigramm_svg();
}
