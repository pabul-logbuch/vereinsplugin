<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jbf_Connector_Twitter implements Jbf_Connector_Interface {

	public function publish( array $event_data, array $settings ) {
		$keys = array(
			'consumer_key'    => $settings['twitter_api_key'],
			'consumer_secret' => $settings['twitter_api_secret'],
			'token'           => $settings['twitter_access_token'],
			'token_secret'    => $settings['twitter_access_secret'],
		);

		foreach ( $keys as $k ) {
			if ( ! $k ) {
				return array( 'success' => false, 'message' => 'X/Twitter nicht vollständig konfiguriert.' );
			}
		}

		$media_id = null;
		if ( ! empty( $event_data['image_social_path'] ) && file_exists( $event_data['image_social_path'] ) ) {
			$media_id = $this->upload_media( $keys, $event_data['image_social_path'] );
			if ( is_wp_error( $media_id ) ) {
				return array( 'success' => false, 'message' => 'X Bild-Upload fehlgeschlagen: ' . $media_id->get_error_message() );
			}
		}

		$payload = array( 'text' => $event_data['short_text'] );
		if ( $media_id ) {
			$payload['media'] = array( 'media_ids' => array( $media_id ) );
		}

		$url  = 'https://api.twitter.com/2/tweets';
		$auth = $this->oauth_header( $keys, 'POST', $url, array() );

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array(
					'Authorization' => $auth,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => 'X Fehler: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return array( 'success' => true, 'message' => 'Erfolgreich gepostet.' );
		}

		return array( 'success' => false, 'message' => 'X HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
	}

	protected function upload_media( $keys, $file_path ) {
		$url = 'https://upload.twitter.com/1.1/media/upload.json';

		$image_data = base64_encode( file_get_contents( $file_path ) );
		$params     = array( 'media_data' => $image_data );

		$auth = $this->oauth_header( $keys, 'POST', $url, array() );

		$response = wp_remote_post(
			$url,
			array(
				'headers' => array( 'Authorization' => $auth ),
				'body'    => $params,
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['media_id_string'] ) ) {
			return new WP_Error( 'jbf_twitter_upload_failed', wp_remote_retrieve_body( $response ) );
		}

		return $data['media_id_string'];
	}

	/**
	 * Baut den OAuth 1.0a "Authorization"-Header (HMAC-SHA1) ohne externe Bibliothek.
	 */
	protected function oauth_header( $keys, $method, $url, $extra_params ) {
		$oauth = array(
			'oauth_consumer_key'     => $keys['consumer_key'],
			'oauth_nonce'            => wp_generate_password( 32, false ),
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp'        => time(),
			'oauth_token'            => $keys['token'],
			'oauth_version'          => '1.0',
		);

		$all_params = array_merge( $oauth, $extra_params );
		ksort( $all_params );

		$pairs = array();
		foreach ( $all_params as $k => $v ) {
			$pairs[] = rawurlencode( $k ) . '=' . rawurlencode( $v );
		}
		$param_string = implode( '&', $pairs );

		$base_string = strtoupper( $method ) . '&' . rawurlencode( $url ) . '&' . rawurlencode( $param_string );
		$signing_key = rawurlencode( $keys['consumer_secret'] ) . '&' . rawurlencode( $keys['token_secret'] );
		$signature   = base64_encode( hash_hmac( 'sha1', $base_string, $signing_key, true ) );

		$oauth['oauth_signature'] = $signature;
		ksort( $oauth );

		$header_parts = array();
		foreach ( $oauth as $k => $v ) {
			$header_parts[] = rawurlencode( $k ) . '="' . rawurlencode( $v ) . '"';
		}

		return 'OAuth ' . implode( ', ', $header_parts );
	}
}
