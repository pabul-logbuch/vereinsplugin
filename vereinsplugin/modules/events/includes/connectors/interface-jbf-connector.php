<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jeder Kanal-Connector bekommt ein einheitliches "Paket" mit allen
 * Daten der Veranstaltung und liefert ein Ergebnis-Array zurück:
 * [ 'success' => bool, 'message' => string ]
 */
interface Jbf_Connector_Interface {
	public function publish( array $event_data, array $settings );
}
