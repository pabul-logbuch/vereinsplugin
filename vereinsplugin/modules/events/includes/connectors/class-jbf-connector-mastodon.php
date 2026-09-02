<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jbf_Connector_Mastodon implements Jbf_Connector_Interface {

	public function publish( array $event_data, array $settings ) {
		$instance = untrailingslashit( $settings['mastodon_instance'] );
		$token    = $settings['mastodon_token'];

		if ( ! $instance || ! $token ) {
			return array( 'success' => false, 'message' => 'Mastodon nicht konfiguriert (Instanz/Token fehlt).' );
		}

		$media_id = null;
		if ( ! empty( $event_data['image_social_path'] ) ) {
			$upload = $this->upload_media( $instance, $token, $event_data['image_social_path'] );
			if ( is_wp_error( $upload ) ) {
				return array( 'success' => false, 'message' => 'Mastodon Bild-Upload fehlgeschlagen: ' . $upload->get_error_message() );
			}
			$media_id = $upload;
		}

		$body = array( 'status' => $event_data['short_text'] );
		if ( $media_id ) {
			$body['media_ids'] = array( $media_id );
		}

		$response = wp_remote_post(
			$instance . '/api/v1/statuses',
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $token ),
				'body'    => $body,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'message' => 'Mastodon Fehler: ' . $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return array( 'success' => true, 'message' => 'Erfolgreich gepostet.' );
		}

		return array( 'success' => false, 'message' => 'Mastodon HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
	}

	protected function upload_media( $instance, $token, $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error( 'jbf_no_file', 'Bilddatei nicht gefunden.' );
		}

		$boundary = wp_generate_password( 24, false );
		$file_content = file_get_contents( $file_path );
		$filename = basename( $file_path );

		$payload  = "--{$boundary}\r\n";
		$payload .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
		$payload .= "Content-Type: image/jpeg\r\n\r\n";
		$payload .= $file_content . "\r\n";
		$payload .= "--{$boundary}--\r\n";

		$response = wp_remote_post(
			$instance . '/api/v2/media',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $payload,
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['id'] ) ) {
			return new WP_Error( 'jbf_upload_failed', wp_remote_retrieve_body( $response ) );
		}

		return $data['id'];
	}
}
