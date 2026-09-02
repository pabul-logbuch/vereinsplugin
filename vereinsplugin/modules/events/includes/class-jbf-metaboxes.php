<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jbf_Metaboxes {

	/** Alle Text-Meta-Felder: key => [label, type, description] – wird u.a. beim Speichern gebraucht. */
	public static function text_fields() {
		return array(
			'_jbf_date_start'           => array( 'Datum & Uhrzeit', 'datetime-local', '' ),
			'_jbf_location'             => array( 'Ort', 'text', '' ),
			'_jbf_short_text'           => array( 'Text (gemeinsam für Social Media, Gruppen & Facebook-Veranstaltung)', 'textarea', '' ),
			'_jbf_telegram_text'        => array( 'Telegram-Text (optional override)', 'textarea', '' ),
			'_jbf_instagram_caption'    => array( 'Instagram-Caption (optional override)', 'textarea', '' ),
			'_jbf_facebook_text'        => array( 'Facebook-Seiten-Text (optional override)', 'textarea', '' ),
			'_jbf_whatsapp_signal_text' => array( 'Text für WhatsApp & Signal (optional override)', 'textarea', '' ),
			'_jbf_fb_event_text'        => array( 'Text für Facebook-Veranstaltung (optional override)', 'textarea', '' ),
			'_jbf_press_contact'        => array( 'Presse-Kontakt (optional, sonst Standard aus Einstellungen)', 'textarea', '' ),
		);
	}

	public static function image_fields() {
		return array(
			'_jbf_img_social'   => 'Bild Social-Feed (empfohlen 1080×1350, für alle Feed-Kanäle inkl. Instagram)',
			'_jbf_img_story'    => 'Bild Instagram-Story (1080×1920, Hochformat 9:16)',
			'_jbf_img_fb_event' => 'Bild für Facebook-Veranstaltung (Querformat, empfohlen 1200×628)',
		);
	}

	public static function video_fields() {
		return array(
			'_jbf_video_reel' => 'Instagram-Reel-Video',
		);
	}

	public static function channels() {
		return array(
			'mastodon'       => 'Mastodon',
			'bluesky'        => 'Bluesky',
			'twitter'        => 'X / Twitter',
			'telegram'       => 'Telegram',
			'facebook'       => 'Facebook-Seite (Post)',
			'instagram'      => 'Instagram (Feed + Story)',
			'signal'         => 'Signal-Gruppe (per Webhook)',
			'whatsapp'       => 'WhatsApp-Gruppe & Kanal (manuell)',
			'facebook_event' => 'Facebook-Veranstaltung (manuell)',
			'press'          => 'Presseverteiler (E-Mail)',
		);
	}

	/**
	 * Definiert die Checkliste: welche Text-/Bildfelder für welche Kanäle
	 * gebraucht werden. "required" steuert die Offen/Erledigt-Anzeige –
	 * optionale Felder (z. B. Telegram-Text als Override) bekommen nur ein
	 * "optional"-Label und blockieren nichts.
	 */
	protected static function requirement_blocks() {
		return array(
			array(
				'id'       => 'short_text',
				'title'    => 'Text (für Social Media, Gruppen & Facebook-Veranstaltung)',
				'channels' => array( 'mastodon', 'bluesky', 'twitter', 'telegram', 'facebook', 'instagram', 'whatsapp', 'signal', 'facebook_event' ),
				'required' => true,
				'fields'   => array(
					array( 'key' => '_jbf_short_text', 'type' => 'textarea', 'label' => '', 'desc' => 'Wird für alle ausgewählten Kanäle gemeinsam verwendet. #jufobleibt wird automatisch angehängt, falls nicht enthalten.' ),
				),
			),
			array(
				'id'       => 'img_social',
				'title'    => 'Social-Bild (für alle Feed-Kanäle inkl. Instagram)',
				'channels' => array( 'mastodon', 'bluesky', 'twitter', 'telegram', 'facebook', 'instagram', 'whatsapp', 'signal' ),
				'required' => false,
				'fields'   => array(
					array( 'key' => '_jbf_img_social', 'type' => 'image', 'label' => '' ),
				),
			),
			array(
				'id'       => 'img_fb_event',
				'title'    => 'Bild für Facebook-Veranstaltung (eigenes Querformat)',
				'channels' => array( 'facebook_event' ),
				'required' => true,
				'fields'   => array(
					array( 'key' => '_jbf_img_fb_event', 'type' => 'image', 'label' => '', 'desc' => 'Facebook-Veranstaltungen brauchen ein Querformat (empfohlen 1200×628) statt des Hochformat-Social-Bilds.' ),
				),
			),
			array(
				'id'       => 'img_story',
				'title'    => 'Instagram-Story (optional zusätzlich zum Feed-Post)',
				'channels' => array( 'instagram' ),
				'required' => false,
				'fields'   => array( array( 'key' => '_jbf_img_story', 'type' => 'image', 'label' => '' ) ),
			),
			array(
				'id'       => 'video_reel',
				'title'    => 'Instagram-Reel (optional, Video statt Bild)',
				'channels' => array( 'instagram' ),
				'required' => false,
				'fields'   => array( array( 'key' => '_jbf_video_reel', 'type' => 'video', 'label' => '', 'desc' => 'Empfohlen Hochformat 9:16, unter 90 Sekunden. Verarbeitung durch Instagram dauert nach dem Absenden noch etwas – Status erscheint in der "Los geht\'s"-Box.' ) ),
			),
			array(
				'id'       => 'press_contact',
				'title'    => 'Presse: Kontakt für Rückfragen (optional, sonst Standard aus Einstellungen)',
				'channels' => array( 'press' ),
				'required' => false,
				'fields'   => array( array( 'key' => '_jbf_press_contact', 'type' => 'textarea', 'label' => '' ) ),
			),
			array(
				'id'       => 'advanced_overrides',
				'title'    => 'Erweitert: abweichende Texte je Kanal (optional, sonst gemeinsamer Text oben)',
				'channels' => array( 'telegram', 'facebook', 'instagram', 'whatsapp', 'signal', 'facebook_event' ),
				'required' => false,
				'fields'   => array(
					array( 'key' => '_jbf_telegram_text', 'type' => 'textarea', 'label' => 'Telegram' ),
					array( 'key' => '_jbf_facebook_text', 'type' => 'textarea', 'label' => 'Facebook-Seite' ),
					array( 'key' => '_jbf_instagram_caption', 'type' => 'textarea', 'label' => 'Instagram-Caption' ),
					array( 'key' => '_jbf_whatsapp_signal_text', 'type' => 'textarea', 'label' => 'WhatsApp & Signal' ),
					array( 'key' => '_jbf_fb_event_text', 'type' => 'textarea', 'label' => 'Facebook-Veranstaltung' ),
				),
			),
		);
	}

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_boxes' ) );
		add_action( 'save_post_veranstaltung', array( __CLASS__, 'save' ) );
		add_filter( 'manage_veranstaltung_posts_columns', array( __CLASS__, 'add_status_column' ) );
		add_action( 'manage_veranstaltung_posts_custom_column', array( __CLASS__, 'render_status_column' ), 10, 2 );
	}

	public static function add_status_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['jbf_status'] = 'Freigabe-Status';
			}
		}
		return $new;
	}

	public static function render_status_column( $column, $post_id ) {
		if ( 'jbf_status' !== $column ) {
			return;
		}
		$status = get_post_meta( $post_id, '_jbf_review_status', true );
		$labels = array(
			''      => array( 'Entwurf', '#f0f0f1' ),
			'ready' => array( 'Bereit zur Freigabe', '#fff3cd' ),
			'sent'  => array( 'Versendet', '#d7f5d7' ),
		);
		$key = isset( $labels[ $status ] ) ? $status : '';
		list( $label, $color ) = $labels[ $key ];
		echo '<span style="background:' . esc_attr( $color ) . ';padding:2px 8px;border-radius:3px;display:inline-block;">' . esc_html( $label ) . '</span>';
	}

	public static function add_boxes() {
		add_meta_box( 'jbf_channels', 'Kanäle (steuert die Checkliste unten)', array( __CLASS__, 'render_channels' ), 'veranstaltung', 'side', 'high' );
		add_meta_box( 'jbf_basics', 'Termin & Ort', array( __CLASS__, 'render_basics' ), 'veranstaltung', 'normal', 'high' );
		add_meta_box( 'jbf_checklist', 'Checkliste: Texte & Bilder', array( __CLASS__, 'render_checklist' ), 'veranstaltung', 'normal', 'default' );
		add_meta_box( 'jbf_timing', 'Zeitplan', array( __CLASS__, 'render_timing' ), 'veranstaltung', 'normal', 'default' );
		add_meta_box( 'jbf_publish', "Los geht's", array( __CLASS__, 'render_publish' ), 'veranstaltung', 'side', 'default' );
	}

	protected static function nonce_field() {
		wp_nonce_field( 'jbf_save_meta', 'jbf_meta_nonce' );
	}

	public static function render_basics( $post ) {
		self::nonce_field();
		$fields = self::text_fields();
		foreach ( array( '_jbf_date_start', '_jbf_location' ) as $key ) {
			list( $label, $type, $desc ) = $fields[ $key ];
			$value = get_post_meta( $post->ID, $key, true );
			echo '<p><label for="' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br/>';
			echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" class="widefat" /></p>';
		}
	}

	public static function render_channels( $post ) {
		$selected = (array) get_post_meta( $post->ID, '_jbf_channels', true );
		foreach ( self::channels() as $key => $label ) {
			$checked = in_array( $key, $selected, true ) ? 'checked' : '';
			echo '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="jbf_channels[]" value="' . esc_attr( $key ) . '" ' . $checked . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '<p class="description">Wählt hier eure Kanäle – die Checkliste darunter zeigt dann automatisch nur, was dafür wirklich gebraucht wird.</p>';
	}

	/**
	 * Checkliste: für jeden ausgewählten Kanal genau die Felder, die
	 * gebraucht werden, mit live aktualisiertem Offen/Erledigt-Status.
	 */
	public static function render_checklist( $post ) {
		echo '<p id="jbf-checklist-progress" class="description"></p>';

		foreach ( self::requirement_blocks() as $block ) {
			$required = ! empty( $block['required'] );
			echo '<div class="jbf-checklist-item" data-channels="' . esc_attr( implode( ',', $block['channels'] ) ) . '" data-required="' . ( $required ? '1' : '0' ) . '">';
			echo '<div class="jbf-checklist-head"><span class="jbf-checklist-badge"></span><strong>' . esc_html( $block['title'] ) . '</strong></div>';
			echo '<div class="jbf-checklist-body">';

			foreach ( $block['fields'] as $field ) {
				$value = ( 'image' === $field['type'] )
					? get_post_meta( $post->ID, $field['key'], true )
					: get_post_meta( $post->ID, $field['key'], true );

				if ( ! empty( $field['label'] ) ) {
					echo '<label class="jbf-field-label">' . esc_html( $field['label'] ) . '</label>';
				}
				if ( ! empty( $field['desc'] ) ) {
					echo '<p class="description">' . esc_html( $field['desc'] ) . '</p>';
				}

				if ( 'image' === $field['type'] ) {
					$attachment_id = $value;
					echo '<div class="jbf-image-field" data-target="' . esc_attr( $field['key'] ) . '">';
					echo '<div class="jbf-image-preview">';
					if ( $attachment_id ) {
						echo wp_get_attachment_image( $attachment_id, 'medium' );
					}
					echo '</div>';
					echo '<input type="hidden" name="' . esc_attr( $field['key'] ) . '" value="' . esc_attr( $attachment_id ) . '" class="jbf-image-id jbf-field" />';
					echo '<button type="button" class="button jbf-select-image">Bild auswählen</button> ';
					echo '<button type="button" class="button jbf-remove-image">Entfernen</button>';
					echo '</div>';
				} elseif ( 'video' === $field['type'] ) {
					$attachment_id = $value;
					echo '<div class="jbf-video-field" data-target="' . esc_attr( $field['key'] ) . '">';
					echo '<div class="jbf-video-preview">';
					if ( $attachment_id ) {
						$video_url = wp_get_attachment_url( $attachment_id );
						echo '<video src="' . esc_url( $video_url ) . '" controls style="max-width:220px;display:block;margin-bottom:6px;"></video>';
					}
					echo '</div>';
					echo '<input type="hidden" name="' . esc_attr( $field['key'] ) . '" value="' . esc_attr( $attachment_id ) . '" class="jbf-video-id jbf-field" />';
					echo '<button type="button" class="button jbf-select-video">Video auswählen</button> ';
					echo '<button type="button" class="button jbf-remove-video">Entfernen</button>';
					echo '</div>';
				} elseif ( 'text' === $field['type'] ) {
					echo '<input type="text" name="' . esc_attr( $field['key'] ) . '" value="' . esc_attr( $value ) . '" class="widefat jbf-field" />';
				} else {
					echo '<textarea name="' . esc_attr( $field['key'] ) . '" rows="3" class="widefat jbf-field">' . esc_textarea( $value ) . '</textarea>';
				}
			}

			echo '</div></div>';
		}
	}

	/**
	 * Zeitplan: Umschalter "alles gleichzeitig" vs. "gestaffelt". Im
	 * gestaffelten Fall erscheint die Kampagnen-Schritte-Planung.
	 */
	public static function render_timing( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p class="description">Erst speichern, dann kann der Zeitplan festgelegt werden.</p>';
			return;
		}

		$mode = get_post_meta( $post->ID, '_jbf_timing_mode', true );
		$mode = $mode ? $mode : 'simultaneous';

		echo '<label style="display:block;margin-bottom:6px;"><input type="radio" name="jbf_timing_mode" value="simultaneous" ' . checked( $mode, 'simultaneous', false ) . ' /> <strong>Alles gleichzeitig</strong> – ein Klick, alle ausgewählten Kanäle auf einmal.</label>';
		echo '<label style="display:block;margin-bottom:6px;"><input type="radio" name="jbf_timing_mode" value="staggered" ' . checked( $mode, 'staggered', false ) . ' /> <strong>Gestaffelt</strong> – mehrere Posts zu unterschiedlichen Zeitpunkten (z. B. Ankündigung, Erinnerung, Rückblick).</label>';

		echo '<div id="jbf-staggered-section" style="' . ( 'staggered' === $mode ? '' : 'display:none;' ) . 'margin-top:14px;">';
		self::render_campaign_body( $post );
		echo '</div>';
	}

	protected static function render_campaign_body( $post ) {
		$steps     = Jbf_Campaign::get_steps( $post->ID );
		$pending   = array_filter( $steps, function ( $s ) {
			return in_array( $s['status'], array( 'pending', 'error' ), true );
		} );
		$scheduled = array_filter( $steps, function ( $s ) {
			return 'scheduled' === $s['status'];
		} );
		$sent      = array_filter( $steps, function ( $s ) {
			return 'sent' === $s['status'];
		} );

		echo '<p class="description">Jeder Schritt wird relativ zum Veranstaltungstermin automatisch verschickt, sobald der Vorstand die Kampagne einplant (Knopf rechts unter "Los geht\'s"). Änderungen an bereits eingeplanten Schritten setzen sie beim Speichern zurück auf "offen" – danach muss neu eingeplant werden.</p>';
		echo '<button type="button" class="button" id="jbf-campaign-add-template">Standard-Vorlage einfügen (4 Schritte)</button> ';
		echo '<button type="button" class="button" id="jbf-campaign-add-row">+ Schritt hinzufügen</button>';

		echo '<table class="widefat jbf-campaign-table" style="margin-top:10px;"><thead><tr>';
		echo '<th>Bezeichnung</th><th>Zeitpunkt (Tage vor/nach Termin)</th><th>Uhrzeit</th><th>Kanäle</th><th>Text (leer = Standard-Kurztext)</th><th></th>';
		echo '</tr></thead><tbody id="jbf-campaign-rows">';

		$index = 0;
		foreach ( $pending as $step ) {
			self::render_campaign_row( $step, $index );
			$index++;
		}
		echo '</tbody></table>';

		echo '<template id="jbf-campaign-row-template">';
		self::render_campaign_row( null, '__INDEX__' );
		echo '</template>';

		if ( $scheduled ) {
			echo '<h4>Eingeplant</h4><ul>';
			foreach ( $scheduled as $step ) {
				echo '<li>' . esc_html( $step['label'] ) . ' — ' . esc_html( date_i18n( 'd.m.Y H:i', $step['scheduled_ts'] ) ) . '</li>';
			}
			echo '</ul>';
		}

		if ( $sent ) {
			echo '<h4>Bereits versendet</h4><ul>';
			foreach ( $sent as $step ) {
				echo '<li><strong>' . esc_html( $step['label'] ) . '</strong> — ' . esc_html( $step['sent_at'] ) . '<ul>';
				foreach ( (array) $step['log'] as $entry ) {
					$cls = ! empty( $entry['success'] ) ? 'jbf-ok' : 'jbf-fail';
					echo '<li class="' . esc_attr( $cls ) . '">' . esc_html( $entry['channel'] ) . ': ' . esc_html( $entry['message'] ) . '</li>';
				}
				echo '</ul></li>';
			}
			echo '</ul>';
		}
	}

	protected static function render_campaign_row( $step, $index ) {
		$label         = $step['label'] ?? '';
		$offset        = $step['offset_days'] ?? 0;
		$time          = $step['time'] ?? '09:00';
		$id            = $step['id'] ?? '';
		$text_override = $step['text_override'] ?? '';
		$sel_channels  = $step['channels'] ?? array();
		$has_error     = isset( $step['status'] ) && 'error' === $step['status'];

		echo '<tr class="jbf-campaign-row">';
		echo '<td><input type="hidden" name="jbf_campaign[' . esc_attr( $index ) . '][id]" value="' . esc_attr( $id ) . '" />';
		echo '<input type="text" name="jbf_campaign[' . esc_attr( $index ) . '][label]" value="' . esc_attr( $label ) . '" placeholder="z.B. Ankündigung" class="widefat" /></td>';
		echo '<td><input type="number" name="jbf_campaign[' . esc_attr( $index ) . '][offset_days]" value="' . esc_attr( $offset ) . '" style="width:70px;" /> Tage</td>';
		echo '<td><input type="time" name="jbf_campaign[' . esc_attr( $index ) . '][time]" value="' . esc_attr( $time ) . '" /></td>';
		echo '<td>';
		foreach ( self::channels() as $key => $chlabel ) {
			$checked = in_array( $key, $sel_channels, true ) ? 'checked' : '';
			echo '<label style="display:block;font-size:12px;"><input type="checkbox" name="jbf_campaign[' . esc_attr( $index ) . '][channels][]" value="' . esc_attr( $key ) . '" ' . $checked . ' /> ' . esc_html( $chlabel ) . '</label>';
		}
		echo '</td>';
		echo '<td><textarea name="jbf_campaign[' . esc_attr( $index ) . '][text_override]" rows="3" class="widefat">' . esc_textarea( $text_override ) . '</textarea></td>';
		echo '<td><button type="button" class="button jbf-campaign-remove-row">Entfernen</button>';
		if ( $has_error ) {
			echo '<p class="description jbf-fail">Konnte nicht eingeplant werden – Zeitpunkt/Termin prüfen.</p>';
		}
		echo '</td>';
		echo '</tr>';
	}

	public static function render_publish( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p class="description">Erst speichern, dann kann eingereicht/versendet werden.</p>';
			return;
		}

		$review_status = get_post_meta( $post->ID, '_jbf_review_status', true );
		self::render_review_status_info( $post->ID, $review_status );

		$reel_status = get_post_meta( $post->ID, '_jbf_reel_status', true );
		if ( $reel_status && ! empty( $reel_status['message'] ) ) {
			$cls = 'jbf-log-entry ';
			$cls .= ( 'done' === $reel_status['state'] ) ? 'jbf-ok' : ( ( 'error' === $reel_status['state'] ) ? 'jbf-fail' : '' );
			echo '<div class="' . esc_attr( trim( $cls ) ) . '">Reel: ' . esc_html( $reel_status['message'] ) . ' <span class="description">(Stand: ' . esc_html( $reel_status['checked_at'] ) . ')</span></div>';
		}

		if ( Jbf_Roles::can_send_external() ) {
			echo '<div class="jbf-timing-only-simultaneous">';
			echo '<p><button type="button" class="button button-primary button-large" id="jbf-publish-btn" data-post-id="' . esc_attr( $post->ID ) . '">🚀 Los geht\'s – jetzt senden</button></p>';
			echo '<div id="jbf-publish-log"></div>';

			$log = get_post_meta( $post->ID, '_jbf_publish_log', true );
			if ( $log ) {
				echo '<div class="jbf-last-log"><strong>Letztes Ergebnis:</strong><ul>';
				foreach ( (array) $log as $entry ) {
					$status_class = ! empty( $entry['success'] ) ? 'jbf-ok' : 'jbf-fail';
					echo '<li class="' . esc_attr( $status_class ) . '">' . esc_html( $entry['channel'] ) . ': ' . esc_html( $entry['message'] ) . '</li>';
				}
				echo '</ul></div>';
			}
			echo '</div>';

			$has_pending_campaign   = (bool) array_filter( Jbf_Campaign::get_steps( $post->ID ), function ( $s ) {
				return 'pending' === $s['status'];
			} );
			$has_scheduled_campaign = (bool) array_filter( Jbf_Campaign::get_steps( $post->ID ), function ( $s ) {
				return 'scheduled' === $s['status'];
			} );

			echo '<div class="jbf-timing-only-staggered">';
			if ( $has_pending_campaign ) {
				echo '<p><button type="button" class="button button-primary button-large" id="jbf-schedule-campaign-btn" data-post-id="' . esc_attr( $post->ID ) . '">🚀 Los geht\'s – Kampagne einplanen</button></p>';
			}
			if ( $has_scheduled_campaign ) {
				echo '<p><button type="button" class="button" id="jbf-stop-campaign-btn" data-post-id="' . esc_attr( $post->ID ) . '">Kampagne stoppen</button></p>';
			}
			echo '<div id="jbf-campaign-schedule-log"></div>';
			echo '</div>';
		} else {
			$disabled = ( 'ready' === $review_status || 'sent' === $review_status ) ? 'disabled' : '';
			echo '<p><button type="button" class="button button-primary button-large" id="jbf-submit-review-btn" data-post-id="' . esc_attr( $post->ID ) . '" ' . $disabled . '>Zur Freigabe einreichen</button></p>';
			echo '<div id="jbf-review-message"></div>';
			echo '<p class="description">Der Vorstand prüft die Veranstaltung und verschickt sie an Presse, Social Media & Co. – gleichzeitig oder als Kampagne, je nach Zeitplan-Einstellung oben.</p>';
		}

		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=jbf-manual-templates&post_id=' . $post->ID ) ) . '" target="_blank" class="button">Copy-Vorlagen (Facebook-Event, WhatsApp)</a></p>';
	}

	protected static function render_review_status_info( $post_id, $review_status ) {
		$labels = array(
			''      => array( 'Entwurf', '#f0f0f1' ),
			'ready' => array( 'Bereit zur Freigabe', '#fff3cd' ),
			'sent'  => array( 'Versendet', '#d7f5d7' ),
		);
		$key = isset( $labels[ $review_status ] ) ? $review_status : '';
		list( $label, $color ) = $labels[ $key ];

		echo '<p><span class="jbf-status-badge" style="background:' . esc_attr( $color ) . ';padding:3px 8px;border-radius:3px;display:inline-block;">' . esc_html( $label ) . '</span></p>';

		if ( 'ready' === $review_status ) {
			$submitted_by = get_post_meta( $post_id, '_jbf_submitted_by', true );
			$submitted_at = get_post_meta( $post_id, '_jbf_submitted_at', true );
			if ( $submitted_by ) {
				$user = get_userdata( $submitted_by );
				echo '<p class="description">Eingereicht von ' . esc_html( $user ? $user->display_name : '?' ) . ' am ' . esc_html( $submitted_at ) . '</p>';
			}
		}
		if ( 'sent' === $review_status ) {
			$sent_by = get_post_meta( $post_id, '_jbf_sent_by', true );
			$sent_at = get_post_meta( $post_id, '_jbf_sent_at', true );
			if ( $sent_by ) {
				$user = get_userdata( $sent_by );
				echo '<p class="description">Versendet von ' . esc_html( $user ? $user->display_name : '?' ) . ' am ' . esc_html( $sent_at ) . '</p>';
			}
		}
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST['jbf_meta_nonce'] ) || ! wp_verify_nonce( $_POST['jbf_meta_nonce'], 'jbf_save_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		foreach ( array_keys( self::text_fields() ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) );
			}
		}

		foreach ( array_keys( self::image_fields() ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, absint( $_POST[ $key ] ) );
			}
		}

		foreach ( array_keys( self::video_fields() ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_post_meta( $post_id, $key, absint( $_POST[ $key ] ) );
			}
		}

		$channels = isset( $_POST['jbf_channels'] ) ? array_map( 'sanitize_key', (array) $_POST['jbf_channels'] ) : array();
		update_post_meta( $post_id, '_jbf_channels', $channels );

		$timing_mode = ( isset( $_POST['jbf_timing_mode'] ) && 'staggered' === $_POST['jbf_timing_mode'] ) ? 'staggered' : 'simultaneous';
		update_post_meta( $post_id, '_jbf_timing_mode', $timing_mode );

		Jbf_Campaign::save_from_request( $post_id );
	}
}
