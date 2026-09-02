<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jbf_Connector_Telegram implements Jbf_Connector_Interface {

	public function publish( array $event_data, array $settings ) {
		$token   = $settings['telegram_bot_token'];
		$chat_id = $settings['telegram_chat_id'];

		if ( ! $token || ! $chat_id ) {
			return array( 'success' => false, 'message' => 'Telegram nicht konfiguriert (Bot-Token/Chat-ID fehlt).' );
		}

		$text = ! empty( $event_data['telegram_text'] ) ? $event_data['telegram_text'] : $event_data['short_text'];
		$image_url = ! empty( $event_data['image_social_url'] ) ? $event_data['image_social_url'] : '';

		$endpoint = $image_url ? "https://api.telegram.org/bot{$token}/sendPhoto" : "https://api.telegram.org/bot{$token}/sendMessage";

		$body = array( 'chat_id' => $chat_id );
		if ( $image_url ) {
			$body['photo']   = $image_url;
			$body['caption'] = $text;
		} else {
			$body['text'] = $text;
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'body'    => $body,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => 'Telegram Fehler: ' . $response->get_error_message() );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $data['ok'] ) ) {
			return array( 'success' => true, 'message' => 'Erfolgreich gesendet.' );
		}

		return array( 'success' => false, 'message' => 'Telegram Fehler: ' . wp_remote_retrieve_body( $response ) );
	}
}
