<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instagram verarbeitet Reel-Videos asynchron: Nach dem Erstellen eines
 * "Media Containers" muss man wiederholt abfragen, ob die Verarbeitung
 * fertig ist (status_code=FINISHED), bevor man media_publish aufrufen kann.
 * Das übernimmt hier ein WP-Cron-Job im Hintergrund.
 */
class Jbf_Reels {

	const CRON_HOOK     = 'jbf_finish_reel_publish';
	const MAX_ATTEMPTS  = 12; // 12 x 30s ≈ 6 Minuten, reicht für die meisten kurzen Reels.
	const API_VERSION   = 'v21.0';

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'attempt_publish' ), 10, 3 );
	}

	/**
	 * Wird direkt nach dem Erstellen des Reel-Containers aufgerufen und plant
	 * den ersten Statuscheck in 30 Sekunden ein.
	 */
	public static function schedule_check( $post_id, $creation_id, $attempt = 1 ) {
		wp_schedule_single_event( time() + 30, self::CRON_HOOK, array( $post_id, $creation_id, $attempt ) );
		self::save_status( $post_id, 'processing', 'Wird von Instagram verarbeitet …' );
	}

	public static function attempt_publish( $post_id, $creation_id, $attempt ) {
		$settings   = Jbf_Settings::get();
		$account_id = $settings['instagram_account_id'];
		$token      = $settings['instagram_token'];

		$status = self::get_status( $creation_id, $token );

		if ( is_wp_error( $status ) ) {
			self::save_status( $post_id, 'error', 'Statusabfrage fehlgeschlagen: ' . $status->get_error_message() );
			return;
		}

		if ( 'FINISHED' === $status ) {
			$publish = self::publish_container( $account_id, $token, $creation_id );
			if ( is_wp_error( $publish ) ) {
				self::save_status( $post_id, 'error', 'Veröffentlichen fehlgeschlagen: ' . $publish->get_error_message() );
			} else {
				self::save_status( $post_id, 'done', 'Reel veröffentlicht.' );
			}
			return;
		}

		if ( in_array( $status, array( 'ERROR', 'EXPIRED' ), true ) ) {
			self::save_status( $post_id, 'error', 'Instagram konnte das Video nicht verarbeiten (Status: ' . $status . '). Bitte Format/Länge prüfen.' );
			return;
		}

		if ( $attempt >= self::MAX_ATTEMPTS ) {
			self::save_status( $post_id, 'error', 'Zeitüberschreitung bei der Verarbeitung – bitte Reel notfalls manuell in der Instagram-App hochladen.' );
			return;
		}

		wp_schedule_single_event( time() + 30, self::CRON_HOOK, array( $post_id, $creation_id, $attempt + 1 ) );
	}

	protected static function get_status( $creation_id, $token ) {
		$url = 'https://graph.facebook.com/' . self::API_VERSION . '/' . $creation_id . '?fields=status_code&access_token=' . rawurlencode( $token );
		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return isset( $data['status_code'] ) ? $data['status_code'] : new WP_Error( 'jbf_reel_status_unknown', wp_remote_retrieve_body( $response ) );
	}

	protected static function publish_container( $account_id, $token, $creation_id ) {
		$response = wp_remote_post(
			"https://graph.facebook.com/" . self::API_VERSION . "/{$account_id}/media_publish",
			array(
				'body'    => array(
					'creation_id'  => $creation_id,
					'access_token' => $token,
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['id'] ) ) {
			return new WP_Error( 'jbf_reel_publish_failed', wp_remote_retrieve_body( $response ) );
		}

		return $data['id'];
	}

	protected static function save_status( $post_id, $state, $message ) {
		update_post_meta(
			$post_id,
			'_jbf_reel_status',
			array(
				'state'      => $state,
				'message'    => $message,
				'checked_at' => current_time( 'mysql' ),
			)
		);
	}
}
