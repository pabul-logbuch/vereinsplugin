<?php
defined( 'ABSPATH' ) || exit;

$year    = (int) ( $_GET['year'] ?? gmdate( 'Y' ) );
$summary = function_exists( 'jb_journal_summary' ) ? jb_journal_summary( $year ) : array( 'total_einnahmen' => 0, 'total_ausgaben' => 0, 'ueberschuss' => 0, 'kategorien' => array() );
$d       = function_exists( 'jb_get_dashboard_data' ) ? jb_get_dashboard_data() : null;
$euro    = static function ( $v ) { return number_format( (float) $v, 2, ',', '.' ) . ' €'; };

// verfügbare Jahre für den Umschalter
global $wpdb;
$jt    = method_exists( $wpdb, 'get_col' ) && function_exists( 'jb_table_journal' ) ? jb_table_journal() : '';
$years = $jt ? array_map( 'intval', (array) $wpdb->get_col( "SELECT DISTINCT YEAR(buchung_datum) y FROM `$jt` ORDER BY y DESC" ) ) : array();
if ( ! $years ) {
	$years = array( $year );
}
$base = get_permalink() ?: remove_query_arg( 'year' );
?>
<div class="jb-wrap vp-kassenbericht">
	<h3><?php echo esc_html__( 'Kassenbericht', 'vereinsplugin' ) . ' ' . (int) $year; ?></h3>

	<p class="vp-muted">
		<?php esc_html_e( 'Jahr:', 'vereinsplugin' ); ?>
		<?php foreach ( $years as $y ) : ?>
			<a class="vp-btn vp-btn-small<?php echo $y === $year ? ' vp-btn-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'year', $y, $base ) ); ?>"><?php echo (int) $y; ?></a>
		<?php endforeach; ?>
	</p>

	<h4><?php esc_html_e( 'Jahresergebnis (EÜR)', 'vereinsplugin' ); ?></h4>
	<div class="jb-kpis" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin:1rem 0">
		<div class="jb-box" style="background:#ecfdf5;border:1px solid #6ee7b7;border-radius:8px;padding:12px"><strong><?php esc_html_e( 'Einnahmen', 'vereinsplugin' ); ?></strong><br>+<?php echo esc_html( $euro( $summary['total_einnahmen'] ) ); ?></div>
		<div class="jb-box" style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:12px"><strong><?php esc_html_e( 'Ausgaben', 'vereinsplugin' ); ?></strong><br>&ndash;<?php echo esc_html( $euro( abs( (float) $summary['total_ausgaben'] ) ) ); ?></div>
		<div class="jb-box" style="border:1px solid #cbd5e1;border-radius:8px;padding:12px"><strong><?php esc_html_e( 'Überschuss', 'vereinsplugin' ); ?></strong><br><?php echo esc_html( $euro( $summary['ueberschuss'] ) ); ?></div>
	</div>

	<?php if ( $d ) : ?>
		<h4><?php esc_html_e( 'Aktueller Stand', 'vereinsplugin' ); ?></h4>
		<div class="vp-table-wrap"><table class="vp-table">
			<tbody>
				<tr><th colspan="2">1. <?php esc_html_e( 'Kontostand', 'vereinsplugin' ); ?></th></tr>
				<tr><td><?php esc_html_e( 'Bankkonto', 'vereinsplugin' ); ?></td><td style="text-align:right"><?php echo esc_html( $euro( $d['bank'] ) ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Barkasse (gezählt)', 'vereinsplugin' ); ?></td><td style="text-align:right"><?php echo esc_html( $euro( $d['kasse'] ) ); ?></td></tr>
				<tr style="font-weight:700"><td><?php esc_html_e( 'Kontostand gesamt', 'vereinsplugin' ); ?></td><td style="text-align:right"><?php echo esc_html( $euro( $d['kontostand'] ) ); ?></td></tr>

				<tr><th colspan="2">2. <?php esc_html_e( 'Getränke', 'vereinsplugin' ); ?></th></tr>
				<tr><td><?php esc_html_e( 'Warenwert aktuell', 'vereinsplugin' ); ?></td><td style="text-align:right"><?php echo esc_html( $euro( $d['getraenke_wert'] ) ); ?></td></tr>

				<tr><th colspan="2">3. <?php esc_html_e( 'Offene Auslagen', 'vereinsplugin' ); ?></th></tr>
				<tr><td><?php esc_html_e( 'Genehmigt, noch nicht ausgezahlt', 'vereinsplugin' ); ?></td><td style="text-align:right">&minus;<?php echo esc_html( $euro( $d['offene_auslagen'] ) ); ?></td></tr>

				<tr><th colspan="2">4. <?php esc_html_e( 'Rücklagen für wiederkehrende Kosten', 'vereinsplugin' ); ?></th></tr>
				<tr><td><?php esc_html_e( 'Rücklagenbedarf bis heute', 'vereinsplugin' ); ?></td><td style="text-align:right">&minus;<?php echo esc_html( $euro( $d['ruecklagen'] ) ); ?></td></tr>

				<tr><th colspan="2">5. <?php esc_html_e( 'Verplantes Budget', 'vereinsplugin' ); ?></th></tr>
				<tr><td><?php esc_html_e( 'Noch reserviert (Rest)', 'vereinsplugin' ); ?></td><td style="text-align:right">&minus;<?php echo esc_html( $euro( $d['verplantes'] ) ); ?></td></tr>

				<tr><th colspan="2">6. <?php esc_html_e( 'Ergebnis', 'vereinsplugin' ); ?></th></tr>
				<tr style="font-weight:700;font-size:1.05em"><td><?php esc_html_e( 'Freies / verfügbares Budget', 'vereinsplugin' ); ?></td><td style="text-align:right"><?php echo esc_html( $euro( $d['frei'] ) ); ?></td></tr>
			</tbody>
		</table></div>
		<p class="vp-muted"><?php esc_html_e( 'Freies Budget = Kontostand − offene Auslagen − Rücklagenbedarf − verplantes Budget. Bargeld & Bankkonto trägst du unter „Buchhaltung → Kassenbericht-Bestände“ ein; alles andere wird berechnet.', 'vereinsplugin' ); ?></p>
	<?php else : ?>
		<p class="vp-muted"><?php esc_html_e( 'Detaillierter Stand nicht verfügbar (Buchhaltungs-Modul).', 'vereinsplugin' ); ?></p>
	<?php endif; ?>

	<?php if ( ! empty( $summary['kategorien'] ) ) : ?>
		<h4><?php esc_html_e( 'Nach Kategorie / Konto', 'vereinsplugin' ); ?> (<?php echo (int) $year; ?>)</h4>
		<div class="vp-table-wrap"><table class="vp-table">
			<thead><tr><th><?php esc_html_e( 'Kategorie', 'vereinsplugin' ); ?></th><th style="text-align:right"><?php esc_html_e( 'Einnahmen', 'vereinsplugin' ); ?></th><th style="text-align:right"><?php esc_html_e( 'Ausgaben', 'vereinsplugin' ); ?></th><th style="text-align:right"><?php esc_html_e( 'Anz.', 'vereinsplugin' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $summary['kategorien'] as $k ) : ?>
				<tr>
					<td><?php echo esc_html( $k['kategorie'] ?: '—' ); ?></td>
					<td style="text-align:right"><?php echo esc_html( (float) $k['einnahmen'] ? $euro( $k['einnahmen'] ) : '—' ); ?></td>
					<td style="text-align:right"><?php echo esc_html( (float) $k['ausgaben'] ? $euro( $k['ausgaben'] ) : '—' ); ?></td>
					<td style="text-align:right"><?php echo (int) $k['anzahl']; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table></div>
	<?php endif; ?>
</div>
