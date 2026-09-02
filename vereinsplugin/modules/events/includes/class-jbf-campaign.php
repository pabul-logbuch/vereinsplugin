<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Eine Kampagne besteht aus mehreren "Schritten" (Posts), die relativ zum
 * Veranstaltungstermin zeitversetzt automatisch verschickt werden.
 * Gespeichert als JSON in Post-Meta "_jbf_campaign_steps":
 *
 * [
 *   [
 *     'id'            => 'abc123',
 *     'label'         => 'Ankündigung',
 *     'offset_days'   => -14,      // relativ zu _jbf_date_start
 *     'time'          => '09:00',
 *     'channels'      => ['mastodon','bluesky'],
 *     'text_override' => '',       // leer = Standard-Kurztext verwenden
 *     'status'        => 'pending' | 'scheduled' | 'sent' | 'error',
 *     'scheduled_ts'  => 0,
 *     'sent_at'       => '',
 *     'log'           => [ [ 'channel' => .., 'success' => bool, 'message' => .. ], ... ],
 *   ],
 *   ...
 * ]
 */
class Jbf_Campaign {

	const META_KEY = '_jbf_campaign_steps';
	const CRON_HOOK = 'jbf_run_campaign_step';

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_step' ), 10, 2 );
	}

	public static function get_steps( $post_id ) {
		$raw = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! $raw ) {
			return array();
		}
		$steps = json_decode( $raw, true );
		return is_array( $steps ) ? $steps : array();
	}

	protected static function save_steps( $post_id, array $steps ) {
		update_post_meta( $post_id, self::META_KEY, wp_json_encode( $steps ) );
	}

	/**
	 * Beispiel-Vorlage mit zeitlich sinnvollen Abständen – wird im Browser
	 * per JS eingefügt (siehe admin.js), rein informativ hier dokumentiert.
	 */
	public static function template_steps() {
		return array(
			array( 'label' => 'Ankündigung', 'offset_days' => -14, 'time' => '09:00' ),
			array( 'label' => 'Erinnerung', 'offset_days' => -3, 'time' => '09:00' ),
			array( 'label' => 'Letzter Aufruf', 'offset_days' => -1, 'time' => '17:00' ),
			array( 'label' => 'Rückblick / Dank', 'offset_days' => 2, 'time' => '11:00' ),
		);
	}

	/**
	 * Liest die editierbaren Zeilen aus dem POST-Request, behält bereits
	 * versendete Schritte unverändert bei und speichert die Kombination.
	 * Aufgerufen aus Jbf_Metaboxes::save().
	 */
	public static function save_from_request( $post_id ) {
		if ( ! isset( $_POST['jbf_campaign'] ) || ! is_array( $_POST['jbf_campaign'] ) ) {
			return;
		}

		$existing      = self::get_steps( $post_id );
		$sent_steps    = array_values( array_filter( $existing, function ( $s ) {
			return isset( $s['status'] ) && 'sent' === $s['status'];
		} ) );
		$existing_by_id = array();
		foreach ( $existing as $s ) {
			if ( ! empty( $s['id'] ) ) {
				$existing_by_id[ $s['id'] ] = $s;
			}
		}

		// Alle noch nicht versendeten, bisher evtl. schon geplanten Schritte
		// aus dem Cron werfen – nach dem Speichern muss der Vorstand neu
		// einplanen, damit Bearbeitungen nicht unbemerkt am Cron vorbeilaufen.
		self::unschedule_all( $post_id );

		$new_steps = array();
		foreach ( wp_unslash( $_POST['jbf_campaign'] ) as $row ) {
			$label = sanitize_text_field( $row['label'] ?? '' );
			if ( '' === $label ) {
				continue; // Leere Zeilen ignorieren.
			}

			$id = ! empty( $row['id'] ) ? sanitize_key( $row['id'] ) : wp_generate_password( 8, false, false );

			$offset_days = isset( $row['offset_days'] ) ? intval( $row['offset_days'] ) : 0;
			$time        = preg_match( '/^\d{2}:\d{2}$/', $row['time'] ?? '' ) ? $row['time'] : '09:00';
			$channels    = isset( $row['channels'] ) ? array_map( 'sanitize_key', (array) $row['channels'] ) : array();
			$text_override = isset( $row['text_override'] ) ? sanitize_textarea_field( $row['text_override'] ) : '';

			$new_steps[] = array(
				'id'            => $id,
				'label'         => $label,
				'offset_days'   => $offset_days,
				'time'          => $time,
				'channels'      => $channels,
				'text_override' => $text_override,
				'status'        => 'pending',
				'scheduled_ts'  => 0,
				'sent_at'       => '',
				'log'           => isset( $existing_by_id[ $id ]['log'] ) ? $existing_by_id[ $id ]['log'] : array(),
			);
		}

		self::save_steps( $post_id, array_merge( $sent_steps, $new_steps ) );
	}

	/**
	 * Berechnet den Unix-Timestamp eines Schritts anhand des Event-Termins.
	 */
	protected static function compute_timestamp( $post_id, array $step ) {
		$date_start = get_post_meta( $post_id, '_jbf_date_start', true );
		if ( ! $date_start ) {
			return null;
		}
		$base = strtotime( $date_start );
		if ( ! $base ) {
			return null;
		}
		$day_ts = strtotime( ( $step['offset_days'] >= 0 ? '+' : '' ) . $step['offset_days'] . ' days', $base );
		list( $h, $m ) = array_map( 'intval', explode( ':', $step['time'] ) );
		$final = mktime( $h, $m, 0, (int) gmdate( 'n', $day_ts ), (int) gmdate( 'j', $day_ts ), (int) gmdate( 'Y', $day_ts ) );
		return $final;
	}

	/**
	 * Plant alle "pending"-Schritte per WP-Cron ein. Nur für den Vorstand
	 * (Capability-Check erfolgt im AJAX-Handler).
	 */
	public static function schedule_all( $post_id ) {
		$steps  = self::get_steps( $post_id );
		$result = array();

		foreach ( $steps as &$step ) {
			if ( 'pending' !== $step['status'] ) {
				continue;
			}

			$ts = self::compute_timestamp( $post_id, $step );

			if ( ! $ts ) {
				$step['status'] = 'error';
				$result[] = array( 'label' => $step['label'], 'ok' => false, 'message' => 'Kein Veranstaltungstermin hinterlegt.' );
				continue;
			}

			if ( $ts <= time() ) {
				$step['status'] = 'error';
				$result[] = array( 'label' => $step['label'], 'ok' => false, 'message' => 'Zeitpunkt liegt in der Vergangenheit: ' . date_i18n( 'd.m.Y H:i', $ts ) );
				continue;
			}

			wp_schedule_single_event( $ts, self::CRON_HOOK, array( $post_id, $step['id'] ) );
			$step['status']       = 'scheduled';
			$step['scheduled_ts'] = $ts;
			$result[] = array( 'label' => $step['label'], 'ok' => true, 'message' => 'Eingeplant für ' . date_i18n( 'd.m.Y H:i', $ts ) );
		}
		unset( $step );

		self::save_steps( $post_id, $steps );
		return $result;
	}

	/**
	 * Nimmt alle "scheduled"-Schritte wieder aus dem WP-Cron und setzt sie
	 * zurück auf "pending" (z. B. weil das Event bearbeitet wurde).
	 */
	public static function unschedule_all( $post_id ) {
		$steps = self::get_steps( $post_id );
		$changed = false;

		foreach ( $steps as &$step ) {
			if ( 'scheduled' === $step['status'] ) {
				wp_unschedule_event( $step['scheduled_ts'], self::CRON_HOOK, array( $post_id, $step['id'] ) );
				$step['status']       = 'pending';
				$step['scheduled_ts'] = 0;
				$changed = true;
			}
		}
		unset( $step );

		if ( $changed ) {
			self::save_steps( $post_id, $steps );
		}
	}

	/**
	 * Wird vom WP-Cron zum geplanten Zeitpunkt aufgerufen.
	 */
	public static function run_step( $post_id, $step_id ) {
		$steps = self::get_steps( $post_id );
		$found = false;

		foreach ( $steps as &$step ) {
			if ( $step['id'] !== $step_id ) {
				continue;
			}
			$found = true;

			if ( 'scheduled' !== $step['status'] ) {
				break; // Wurde inzwischen bearbeitet/gestoppt.
			}

			$settings   = Jbf_Settings::get();
			$event_data = Jbf_Publisher::build_event_data( $post_id );

			if ( ! empty( $step['text_override'] ) ) {
				$hashtag = trim( $settings['hashtag'] );
				$text    = $step['text_override'];
				if ( $hashtag && false === stripos( $text, $hashtag ) ) {
					$text = trim( $text ) . ' ' . $hashtag;
				}
				$event_data['short_text'] = $text;
			}

			$connectors_map = Jbf_Publisher::connectors();
			$log = array();

			foreach ( $step['channels'] as $channel_key ) {
				if ( ! isset( $connectors_map[ $channel_key ] ) ) {
					continue;
				}
				$class_name = $connectors_map[ $channel_key ];
				$connector  = new $class_name();
				$res = $connector->publish( $event_data, $settings );

				$log[] = array(
					'channel' => $channel_key,
					'success' => ! empty( $res['success'] ),
					'message' => isset( $res['message'] ) ? $res['message'] : '',
				);
			}

			$step['status']  = 'sent';
			$step['sent_at'] = current_time( 'mysql' );
			$step['log']     = $log;
			break;
		}
		unset( $step );

		if ( $found ) {
			self::save_steps( $post_id, $steps );
		}
	}
}
