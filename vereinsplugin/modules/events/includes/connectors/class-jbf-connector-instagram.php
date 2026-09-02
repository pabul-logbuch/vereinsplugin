<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Hinweis: Instagram Content Publishing API braucht öffentlich erreichbare
 * Bild-URLs (image_social_url / image_story_url), keine lokalen Dateipfade.
 * Story-Publishing (media_type=STORIES) erfordert ggf. eine erweiterte
 * App-Prüfung durch Meta – falls das fehlschlägt, wird das im Log vermerkt
 * und die Story bleibt als Copy-Vorlage übrig.
 */
class Jbf_Connector_Instagram implements Jbf_Connector_Interface {

	const API_VERSION = 'v21.0';

	public function publish( array $event_data, array $settings ) {
		$account_id = $settings['instagram_account_id'];
		$token      = $settings['instagram_token'];

		if ( ! $account_id || ! $token ) {
			return array( 'success' => false, 'message' => 'Instagram nicht konfiguriert (Account ID/Token fehlt).' );
		}

		if ( empty( $event_data['image_social_url'] ) ) {
			return array( 'success' => false, 'message' => 'Instagram: kein Social-Bild hinterlegt.' );
		}

		$caption = ! empty( $event_data['instagram_caption'] ) ? $event_data['instagram_caption'] : $event_data['short_text'];

		$feed_result = $this->publish_media( $account_id, $token, $event_data['image_social_url'], $caption, 'IMAGE' );

		$story_message = '';
		if ( ! empty( $event_data['image_story_url'] ) ) {
			$story_result = $this->publish_media( $account_id, $token, $event_data['image_story_url'], '', 'STORIES' );
			$story_message = is_wp_error( $story_result )
				? ' | Story fehlgeschlagen (' . $story_result->get_error_message() . ') – ggf. manuell posten.'
				: ' | Story veröffentlicht.';
		}

		$reel_message = '';
		if ( ! empty( $event_data['video_reel_url'] ) ) {
			$reel_creation = $this->create_reel_container( $account_id, $token, $event_data['video_reel_url'], $caption );
			if ( is_wp_error( $reel_creation ) ) {
				$reel_message = ' | Reel fehlgeschlagen: ' . $reel_creation->get_error_message();
			} else {
				Jbf_Reels::schedule_check( $event_data['post_id'], $reel_creation );
				$reel_message = ' | Reel wird im Hintergrund verarbeitet (Status später sichtbar).';
			}
		}

		if ( is_wp_error( $feed_result ) ) {
			return array( 'success' => false, 'message' => 'Instagram Feed-Post fehlgeschlagen: ' . $feed_result->get_error_message() . $story_message . $reel_message );
		}

		return array( 'success' => true, 'message' => 'Feed-Post veröffentlicht.' . $story_message . $reel_message );
	}

	protected function create_reel_container( $account_id, $token, $video_url, $caption ) {
		$args = array(
			'media_type'    => 'REELS',
			'video_url'     => $video_url,
			'share_to_feed' => 'true',
			'access_token'  => $token,
		);
		if ( $caption ) {
			$args['caption'] = $caption;
		}

		$response = wp_remote_post(
			"https://graph.facebook.com/" . self::API_VERSION . "/{$account_id}/media",
			array( 'body' => $args, 'timeout' => 30 )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['id'] ) ) {
			return new WP_Error( 'jbf_reel_create_failed', wp_remote_retrieve_body( $response ) );
		}

		return $data['id'];
	}

	protected function publish_media( $account_id, $token, $image_url, $caption, $media_type ) {
		$create_args = array(
			'image_url'    => $image_url,
			'access_token' => $token,
		);
		if ( 'STORIES' === $media_type ) {
			$create_args['media_type'] = 'STORIES';
		} elseif ( $caption ) {
			$create_args['caption'] = $caption;
		}

		$create = wp_remote_post(
			"https://graph.facebook.com/" . self::API_VERSION . "/{$account_id}/media",
			array( 'body' => $create_args, 'timeout' => 30 )
		);

		if ( is_wp_error( $create ) ) {
			return $create;
		}

		$create_data = json_decode( wp_remote_retrieve_body( $create ), true );
		if ( empty( $create_data['id'] ) ) {
			return new WP_Error( 'jbf_ig_create_failed', wp_remote_retrieve_body( $create ) );
		}

		$publish = wp_remote_post(
			"https://graph.facebook.com/" . self::API_VERSION . "/{$account_id}/media_publish",
			array(
				'body'    => array(
					'creation_id'  => $create_data['id'],
					'access_token' => $token,
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $publish ) ) {
			return $publish;
		}

		$publish_data = json_decode( wp_remote_retrieve_body( $publish ), true );
		if ( empty( $publish_data['id'] ) ) {
			return new WP_Error( 'jbf_ig_publish_failed', wp_remote_retrieve_body( $publish ) );
		}

		return $publish_data['id'];
	}
}
