<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jbf_Connector_Facebook implements Jbf_Connector_Interface {

	const API_VERSION = 'v21.0';

	public function publish( array $event_data, array $settings ) {
		$page_id = $settings['facebook_page_id'];
		$token   = $settings['facebook_page_token'];

		if ( ! $page_id || ! $token ) {
			return array( 'success' => false, 'message' => 'Facebook nicht konfiguriert (Page ID/Token fehlt).' );
		}

		$text      = ! empty( $event_data['facebook_text'] ) ? $event_data['facebook_text'] : $event_data['short_text'];
		$image_url = ! empty( $event_data['image_social_url'] ) ? $event_data['image_social_url'] : '';

		if ( $image_url ) {
			$endpoint = "https://graph.facebook.com/" . self::API_VERSION . "/{$page_id}/photos";
			$body     = array(
				'url'          => $image_url,
				'caption'      => $text,
				'access_token' => $token,
			);
		} else {
			$endpoint = "https://graph.facebook.com/" . self::API_VERSION . "/{$page_id}/feed";
			$body     = array(
				'message'      => $text,
				'access_token' => $token,
			);
		}

		$response = wp_remote_post( $endpoint, array( 'body' => $body, 'timeout' => 30 ) );

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => 'Facebook Fehler: ' . $response->get_error_message() );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $data['id'] ) ) {
			return array( 'success' => true, 'message' => 'Erfolgreich gepostet (Post ID ' . $data['id'] . ').' );
		}

		return array( 'success' => false, 'message' => 'Facebook Fehler: ' . wp_remote_retrieve_body( $response ) );
	}
}
