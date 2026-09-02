<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jbf_Publisher {

	public static function connectors() {
		return array(
			'mastodon'  => 'Jbf_Connector_Mastodon',
			'bluesky'   => 'Jbf_Connector_Bluesky',
			'telegram'  => 'Jbf_Connector_Telegram',
			'facebook'  => 'Jbf_Connector_Facebook',
			'instagram' => 'Jbf_Connector_Instagram',
			'twitter'   => 'Jbf_Connector_Twitter',
			'signal'    => 'Jbf_Connector_Signal',
			'press'     => 'Jbf_Connector_Press_Email',
		);
	}

	/**
	 * Baut das einheitliche Event-Datenpaket für alle Connectoren.
	 */
	public static function build_event_data( $post_id ) {
		$settings = Jbf_Settings::get();
		$hashtag  = trim( $settings['hashtag'] );

		$short_text = get_post_meta( $post_id, '_jbf_short_text', true );
		if ( $hashtag && false === stripos( $short_text, $hashtag ) ) {
			$short_text = trim( $short_text ) . ' ' . $hashtag;
		}

		$img_social_id   = get_post_meta( $post_id, '_jbf_img_social', true );
		$img_story_id    = get_post_meta( $post_id, '_jbf_img_story', true );
		$img_fb_event_id = get_post_meta( $post_id, '_jbf_img_fb_event', true );
		$video_reel_id   = get_post_meta( $post_id, '_jbf_video_reel', true );

		// Presse nutzt standardmäßig Titel + den normalen Website-Text (Beitragsinhalt) –
		// nur wenn im Event explizit etwas anderes hinterlegt wurde, wird das verwendet.
		$post              = get_post( $post_id );
		$press_headline    = get_post_meta( $post_id, '_jbf_press_headline', true );
		$press_headline    = $press_headline ? $press_headline : get_the_title( $post_id );
		$press_lead        = get_post_meta( $post_id, '_jbf_press_lead', true );
		$press_lead        = $press_lead ? $press_lead : wp_strip_all_tags( $post ? $post->post_content : '' );
		$press_contact     = get_post_meta( $post_id, '_jbf_press_contact', true );
		$press_contact     = $press_contact ? $press_contact : $settings['press_contact_default'];

		$data = array(
			'post_id'              => $post_id,
			'title'                => get_the_title( $post_id ),
			'date_start'           => get_post_meta( $post_id, '_jbf_date_start', true ),
			'location'             => get_post_meta( $post_id, '_jbf_location', true ),
			'short_text'           => $short_text,
			// Kanalspezifische Texte sind optionale Overrides – fallen sonst auf short_text zurück (siehe Connectoren).
			'telegram_text'        => get_post_meta( $post_id, '_jbf_telegram_text', true ),
			'instagram_caption'    => get_post_meta( $post_id, '_jbf_instagram_caption', true ),
			'facebook_text'        => get_post_meta( $post_id, '_jbf_facebook_text', true ),
			'whatsapp_signal_text' => get_post_meta( $post_id, '_jbf_whatsapp_signal_text', true ),
			'fb_event_text'        => get_post_meta( $post_id, '_jbf_fb_event_text', true ),
			'press_headline'       => $press_headline,
			'press_lead'           => $press_lead,
			'press_contact'        => $press_contact,
			'image_social_path'    => $img_social_id ? get_attached_file( $img_social_id ) : '',
			'image_social_url'     => $img_social_id ? wp_get_attachment_url( $img_social_id ) : '',
			'image_story_path'     => $img_story_id ? get_attached_file( $img_story_id ) : '',
			'image_story_url'      => $img_story_id ? wp_get_attachment_url( $img_story_id ) : '',
			'image_fb_event_path'  => $img_fb_event_id ? get_attached_file( $img_fb_event_id ) : '',
			'image_fb_event_url'   => $img_fb_event_id ? wp_get_attachment_url( $img_fb_event_id ) : '',
			'video_reel_path'      => $video_reel_id ? get_attached_file( $video_reel_id ) : '',
			'video_reel_url'       => $video_reel_id ? wp_get_attachment_url( $video_reel_id ) : '',
			// Presse-Anhang: eigenes Pressebild gibt's nicht mehr separat, das Social-Bild wird mitgeschickt.
			'image_press_path'    => $img_social_id ? get_attached_file( $img_social_id ) : '',
		);

		if ( $img_social_id && $data['image_social_path'] && file_exists( $data['image_social_path'] ) ) {
			$data['image_social_base64'] = base64_encode( file_get_contents( $data['image_social_path'] ) );
		}

		return $data;
	}

	/**
	 * Veröffentlicht an alle für dieses Event ausgewählten (und automatisierbaren) Kanäle.
	 * Gibt ein Log-Array zurück und speichert es zusätzlich als Post-Meta.
	 */
	public static function publish( $post_id ) {
		$settings        = Jbf_Settings::get();
		$selected        = (array) get_post_meta( $post_id, '_jbf_channels', true );
		$event_data       = self::build_event_data( $post_id );
		$connectors_map   = self::connectors();
		$log              = array();

		foreach ( $selected as $channel_key ) {
			if ( ! isset( $connectors_map[ $channel_key ] ) ) {
				continue; // z. B. Kanäle ohne API wie "facebook_event" oder "whatsapp" – kein Connector.
			}

			$class_name = $connectors_map[ $channel_key ];
			/** @var Jbf_Connector_Interface $connector */
			$connector = new $class_name();

			$result = $connector->publish( $event_data, $settings );

			$log[] = array(
				'channel' => self::channel_label( $channel_key ),
				'success' => ! empty( $result['success'] ),
				'message' => isset( $result['message'] ) ? $result['message'] : '',
				'time'    => current_time( 'mysql' ),
			);
		}

		update_post_meta( $post_id, '_jbf_publish_log', $log );

		return $log;
	}

	protected static function channel_label( $key ) {
		$channels = Jbf_Metaboxes::channels();
		return isset( $channels[ $key ] ) ? $channels[ $key ] : $key;
	}
}
