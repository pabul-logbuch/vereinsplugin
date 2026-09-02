<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jbf_Connector_Bluesky implements Jbf_Connector_Interface {

	const API_BASE = 'https://bsky.social/xrpc/';

	public function publish( array $event_data, array $settings ) {
		$handle       = $settings['bluesky_handle'];
		$app_password = $settings['bluesky_app_password'];

		if ( ! $handle || ! $app_password ) {
			return array( 'success' => false, 'message' => 'Bluesky nicht konfiguriert (Handle/App-Passwort fehlt).' );
		}

		$session = $this->create_session( $handle, $app_password );
		if ( is_wp_error( $session ) ) {
			return array( 'success' => false, 'message' => 'Bluesky Login fehlgeschlagen: ' . $session->get_error_message() );
		}

		$embed = null;
		if ( ! empty( $event_data['image_social_path'] ) && file_exists( $event_data['image_social_path'] ) ) {
			$blob = $this->upload_blob( $session, $event_data['image_social_path'] );
			if ( is_wp_error( $blob ) ) {
				return array( 'success' => false, 'message' => 'Bluesky Bild-Upload fehlgeschlagen: ' . $blob->get_error_message() );
			}
			$embed = array(
				'$type'  => 'app.bsky.embed.images',
				'images' => array(
					array(
						'image' => $blob,
						'alt'   => isset( $event_data['title'] ) ? $event_data['title'] : '',
					),
				),
			);
		}

		$record = array(
			'$type'     => 'app.bsky.feed.post',
			'text'      => $event_data['short_text'],
			'createdAt' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
		if ( $embed ) {
			$record['embed'] = $embed;
		}

		$response = wp_remote_post(
			self::API_BASE . 'com.atproto.repo.createRecord',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $session['accessJwt'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'repo'       => $session['did'],
						'collection' => 'app.bsky.feed.post',
						'record'     => $record,
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => 'Bluesky Fehler: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return array( 'success' => true, 'message' => 'Erfolgreich gepostet.' );
		}

		return array( 'success' => false, 'message' => 'Bluesky HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
	}

	protected function create_session( $handle, $app_password ) {
		$response = wp_remote_post(
			self::API_BASE . 'com.atproto.server.createSession',
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'identifier' => $handle,
						'password'   => $app_password,
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['accessJwt'] ) || empty( $data['did'] ) ) {
			return new WP_Error( 'jbf_login_failed', wp_remote_retrieve_body( $response ) );
		}

		return $data;
	}

	protected function upload_blob( $session, $file_path ) {
		$file_content = file_get_contents( $file_path );

		$response = wp_remote_post(
			self::API_BASE . 'com.atproto.repo.uploadBlob',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $session['accessJwt'],
					'Content-Type'  => 'image/jpeg',
				),
				'body'    => $file_content,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['blob'] ) ) {
			return new WP_Error( 'jbf_blob_failed', wp_remote_retrieve_body( $response ) );
		}

		return $data['blob'];
	}
}
