<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jbf_Ajax {

	public static function init() {
		add_action( 'wp_ajax_jbf_publish_event', array( __CLASS__, 'handle_publish' ) );
		add_action( 'wp_ajax_jbf_submit_for_review', array( __CLASS__, 'handle_submit_for_review' ) );
		add_action( 'wp_ajax_jbf_schedule_campaign', array( __CLASS__, 'handle_schedule_campaign' ) );
		add_action( 'wp_ajax_jbf_stop_campaign', array( __CLASS__, 'handle_stop_campaign' ) );
	}

	/**
	 * Sendet an externe Kanäle. Nur für Nutzer mit jbf_send_external
	 * (Vorstand/Admin) – Vereinsmitglieder sehen diesen Button gar nicht,
	 * die Berechtigung wird hier aber zusätzlich serverseitig erzwungen.
	 */
	public static function handle_publish() {
		check_ajax_referer( 'jbf_publish_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Keine Berechtigung.' ) );
		}

		if ( ! Jbf_Roles::can_send_external() ) {
			wp_send_json_error( array( 'message' => 'Nur der Vorstand darf an externe Kanäle senden. Bitte zur Freigabe einreichen.' ) );
		}

		$log = Jbf_Publisher::publish( $post_id );

		update_post_meta( $post_id, '_jbf_review_status', 'sent' );
		update_post_meta( $post_id, '_jbf_sent_by', get_current_user_id() );
		update_post_meta( $post_id, '_jbf_sent_at', current_time( 'mysql' ) );

		wp_send_json_success( array( 'log' => $log ) );
	}

	/**
	 * Mitglieder markieren eine Veranstaltung als bereit zur Freigabe.
	 * Löst keinen externen Versand aus, nur einen Status-/Info-Vermerk.
	 */
	public static function handle_submit_for_review() {
		check_ajax_referer( 'jbf_publish_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Keine Berechtigung.' ) );
		}

		update_post_meta( $post_id, '_jbf_review_status', 'ready' );
		update_post_meta( $post_id, '_jbf_submitted_by', get_current_user_id() );
		update_post_meta( $post_id, '_jbf_submitted_at', current_time( 'mysql' ) );

		wp_send_json_success(
			array(
				'message' => 'Zur Freigabe eingereicht. Der Vorstand wird die Veranstaltung prüfen und verschicken.',
			)
		);
	}

	public static function handle_schedule_campaign() {
		check_ajax_referer( 'jbf_publish_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Keine Berechtigung.' ) );
		}
		if ( ! Jbf_Roles::can_send_external() ) {
			wp_send_json_error( array( 'message' => 'Nur der Vorstand darf Kampagnen einplanen.' ) );
		}

		$result = Jbf_Campaign::schedule_all( $post_id );
		wp_send_json_success( array( 'result' => $result ) );
	}

	public static function handle_stop_campaign() {
		check_ajax_referer( 'jbf_publish_nonce', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Keine Berechtigung.' ) );
		}
		if ( ! Jbf_Roles::can_send_external() ) {
			wp_send_json_error( array( 'message' => 'Nur der Vorstand darf Kampagnen stoppen.' ) );
		}

		Jbf_Campaign::unschedule_all( $post_id );
		wp_send_json_success( array( 'message' => 'Kampagne gestoppt, noch offene Schritte sind zurückgesetzt.' ) );
	}
}
