<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Erwartet eine laufende signal-cli-rest-api Instanz (z. B. auf einem
 * kleinen externen VPS, da Standard-WordPress-Hosting keinen dauerhaften
 * Prozess für signal-cli erlaubt). Endpunkt-Doku: bbernhard/signal-cli-rest-api
 */
class Jbf_Connector_Signal implements Jbf_Connector_Interface {

	public function publish( array $event_data, array $settings ) {
		$endpoint = untrailingslashit( $settings['signal_webhook_url'] );
		$number   = $settings['signal_number'];
		$group_id = $settings['signal_group_id'];

		if ( ! $endpoint || ! $number || ! $group_id ) {
			return array( 'success' => false, 'message' => 'Signal nicht konfiguriert (Webhook/Nummer/Gruppen-ID fehlt).' );
		}

		$text = ! empty( $event_data['whatsapp_signal_text'] ) ? $event_data['whatsapp_signal_text'] : $event_data['short_text'];

		$body = array(
			'message'    => $text,
			'number'     => $number,
			'recipients' => array( $group_id ),
		);

		if ( ! empty( $event_data['image_social_base64'] ) ) {
			$body['base64_attachments'] = array( $event_data['image_social_base64'] );
		}

		$response = wp_remote_post(
			$endpoint . '/v2/send',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => 'Signal Fehler: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return array( 'success' => true, 'message' => 'Erfolgreich an Signal-Gruppe gesendet.' );
		}

		return array( 'success' => false, 'message' => 'Signal HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
	}
}
