<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jbf_Connector_Press_Email implements Jbf_Connector_Interface {

	public function publish( array $event_data, array $settings ) {
		$recipients_raw = $settings['press_recipients'];
		$recipients = array_filter( array_map( 'trim', explode( "\n", $recipients_raw ) ) );

		if ( empty( $recipients ) ) {
			return array( 'success' => false, 'message' => 'Kein Presseverteiler hinterlegt (Einstellungen).' );
		}

		$headline = ! empty( $event_data['press_headline'] ) ? $event_data['press_headline'] : $event_data['title'];
		$body  = $event_data['press_lead'] . "\n\n";
		$body .= "Termin: " . $event_data['date_start'] . "\n";
		$body .= "Ort: " . $event_data['location'] . "\n\n";
		$body .= $event_data['press_contact'];

		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			'From: ' . $settings['press_from_name'] . ' <' . $settings['press_from_email'] . '>',
		);

		$attachments = array();
		if ( ! empty( $event_data['image_press_path'] ) && file_exists( $event_data['image_press_path'] ) ) {
			$attachments[] = $event_data['image_press_path'];
		}

		// Einzelversand statt BCC an alle, damit jede Redaktion eine personalisierte "An"-Adresse sieht.
		$success_count = 0;
		$fail_count    = 0;
		foreach ( $recipients as $to ) {
			$sent = wp_mail( $to, $headline, $body, $headers, $attachments );
			if ( $sent ) {
				$success_count++;
			} else {
				$fail_count++;
			}
		}

		if ( 0 === $fail_count ) {
			return array( 'success' => true, 'message' => "An {$success_count} Empfänger verschickt." );
		}

		return array( 'success' => false, 'message' => "{$success_count} erfolgreich, {$fail_count} fehlgeschlagen. Für zuverlässigen Versand ein SMTP-Plugin einrichten." );
	}
}
