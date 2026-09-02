<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jbf_Settings {

	const OPTION_KEY = 'jbf_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function menu() {
		add_menu_page(
			'Jufobleibt Publisher',
			'Jufobleibt Publisher',
			'manage_options',
			'jufobleibt-event-publisher-settings',
			array( __CLASS__, 'render_settings_page' ),
			'dashicons-share',
			58
		);

		add_submenu_page(
			'jufobleibt-event-publisher-settings',
			'Einstellungen',
			'Einstellungen',
			'manage_options',
			'jufobleibt-event-publisher-settings',
			array( __CLASS__, 'render_settings_page' )
		);

		// Wird als Unterseite registriert (damit die Callback-Route existiert),
		// aber direkt danach wieder aus dem sichtbaren Menü entfernt – nur über
		// den Direktlink im Veranstaltungs-Editor erreichbar.
		add_submenu_page(
			'jufobleibt-event-publisher-settings',
			'Copy-Vorlagen',
			'Copy-Vorlagen',
			'edit_posts',
			'jbf-manual-templates',
			array( __CLASS__, 'render_manual_templates_page' )
		);
		remove_submenu_page( 'jufobleibt-event-publisher-settings', 'jbf-manual-templates' );
	}

	public static function defaults() {
		return array(
			'mastodon_instance'   => '',
			'mastodon_token'      => '',
			'bluesky_handle'      => '',
			'bluesky_app_password' => '',
			'telegram_bot_token'  => '',
			'telegram_chat_id'    => '',
			'facebook_page_id'    => '',
			'facebook_page_token' => '',
			'instagram_account_id' => '',
			'instagram_token'     => '',
			'twitter_api_key'     => '',
			'twitter_api_secret'  => '',
			'twitter_access_token' => '',
			'twitter_access_secret' => '',
			'signal_webhook_url'  => '',
			'signal_number'       => '',
			'signal_group_id'     => '',
			'press_from_name'     => get_bloginfo( 'name' ),
			'press_from_email'    => get_bloginfo( 'admin_email' ),
			'press_recipients'    => '',
			'press_contact_default' => '',
			'hashtag'             => '#jufobleibt',
		);
	}

	public static function get() {
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, self::defaults() );
	}

	public static function register_settings() {
		register_setting( 'jbf_settings_group', self::OPTION_KEY, array( __CLASS__, 'sanitize' ) );
	}

	public static function sanitize( $input ) {
		$clean = array();
		foreach ( self::defaults() as $key => $default ) {
			if ( ! isset( $input[ $key ] ) ) {
				$clean[ $key ] = $default;
				continue;
			}
			if ( 'press_recipients' === $key || 'press_contact_default' === $key ) {
				$clean[ $key ] = sanitize_textarea_field( $input[ $key ] );
			} else {
				$clean[ $key ] = sanitize_text_field( $input[ $key ] );
			}
		}
		return $clean;
	}

	protected static function field( $settings, $key, $label, $type = 'text', $desc = '' ) {
		echo '<tr><th scope="row"><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		if ( 'textarea' === $type ) {
			echo '<textarea id="' . esc_attr( $key ) . '" name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $key ) . ']" rows="4" class="large-text">' . esc_textarea( $settings[ $key ] ) . '</textarea>';
		} else {
			echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $key ) . '" name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( $settings[ $key ] ) . '" class="regular-text" />';
		}
		if ( $desc ) {
			echo '<p class="description">' . wp_kses_post( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = self::get();
		?>
		<div class="wrap">
			<h1>Jufobleibt Event Publisher – Einstellungen</h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'jbf_settings_group' ); ?>

				<h2>Allgemein</h2>
				<table class="form-table">
					<?php self::field( $settings, 'hashtag', 'Fester Hashtag', 'text', 'Wird automatisch an Social-Kurztexte angehängt, falls nicht bereits enthalten.' ); ?>
				</table>

				<h2>Mastodon</h2>
				<table class="form-table">
					<?php
					self::field( $settings, 'mastodon_instance', 'Instanz-URL', 'text', 'z. B. https://mastodon.social' );
					self::field( $settings, 'mastodon_token', 'Access Token', 'text', 'Einstellungen → Entwicklung → Neue Anwendung, Scope „write“.' );
					?>
				</table>

				<h2>Bluesky</h2>
				<table class="form-table">
					<?php
					self::field( $settings, 'bluesky_handle', 'Handle', 'text', 'z. B. verein.bsky.social' );
					self::field( $settings, 'bluesky_app_password', 'App-Passwort', 'text', 'Einstellungen → App-Passwörter, NICHT das Hauptpasswort verwenden.' );
					?>
				</table>

				<h2>Telegram</h2>
				<table class="form-table">
					<?php
					self::field( $settings, 'telegram_bot_token', 'Bot-Token', 'text', 'Von @BotFather erhalten.' );
					self::field( $settings, 'telegram_chat_id', 'Kanal-/Chat-ID', 'text', 'Bot muss im Kanal als Admin sein, z. B. @euerkanal oder -100…' );
					?>
				</table>

				<h2>Facebook-Seite</h2>
				<table class="form-table">
					<?php
					self::field( $settings, 'facebook_page_id', 'Page ID', 'text', '' );
					self::field( $settings, 'facebook_page_token', 'Page Access Token (langlebig)', 'text', 'Über Meta Graph API Explorer erzeugen, siehe Meta for Developers.' );
					?>
				</table>

				<h2>Instagram</h2>
				<table class="form-table">
					<?php
					self::field( $settings, 'instagram_account_id', 'Instagram Business Account ID', 'text', 'Muss mit der Facebook-Seite verknüpft sein.' );
					self::field( $settings, 'instagram_token', 'Access Token', 'text', 'In der Regel derselbe Token wie Facebook, mit Instagram-Berechtigungen.' );
					?>
				</table>

				<h2>X / Twitter</h2>
				<table class="form-table">
					<?php
					self::field( $settings, 'twitter_api_key', 'API Key', 'text', '' );
					self::field( $settings, 'twitter_api_secret', 'API Secret', 'text', '' );
					self::field( $settings, 'twitter_access_token', 'Access Token', 'text', 'User-Context Token mit Schreibrechten, aus dem Developer Portal.' );
					self::field( $settings, 'twitter_access_secret', 'Access Token Secret', 'text', '' );
					?>
				</table>

				<h2>Signal (über externen Webhook)</h2>
				<table class="form-table">
					<?php
					self::field( $settings, 'signal_webhook_url', 'REST-API-Endpunkt', 'text', 'z. B. https://euer-mini-server:8080 (signal-cli-rest-api)' );
					self::field( $settings, 'signal_number', 'Registrierte Signal-Nummer', 'text', '' );
					self::field( $settings, 'signal_group_id', 'Gruppen-ID', 'text', 'Aus signal-cli-rest-api "listGroups" auslesen.' );
					?>
				</table>

				<h2>Presseverteiler</h2>
				<table class="form-table">
					<?php
					self::field( $settings, 'press_from_name', 'Absendername', 'text', '' );
					self::field( $settings, 'press_from_email', 'Absender-E-Mail', 'email', 'Für zuverlässigen Versand empfiehlt sich ein SMTP-Plugin (z. B. WP Mail SMTP).' );
					self::field( $settings, 'press_recipients', 'Empfänger (eine Adresse pro Zeile)', 'textarea', '' );
					self::field( $settings, 'press_contact_default', 'Standard-Kontaktblock für Presseanfragen', 'textarea', 'Wird bei jeder Veranstaltung automatisch verwendet (Name, Telefon, E-Mail für Presseanfragen). Kann pro Veranstaltung überschrieben werden, muss aber normalerweise nicht.' );
					?>
				</table>

				<?php submit_button( 'Einstellungen speichern' ); ?>
			</form>
		</div>
		<?php
	}

	public static function render_manual_templates_page() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;

		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( 'Keine Berechtigung für diese Veranstaltung.' );
		}

		$post = get_post( $post_id );

		echo '<div class="wrap"><h1>Copy-Vorlagen</h1>';

		if ( ! $post ) {
			echo '<p>Keine Veranstaltung gefunden.</p></div>';
			return;
		}

		$short_text = get_post_meta( $post_id, '_jbf_short_text', true );
		$fb_text = get_post_meta( $post_id, '_jbf_fb_event_text', true );
		$fb_text = $fb_text ? $fb_text : $short_text;
		$wa_text = get_post_meta( $post_id, '_jbf_whatsapp_signal_text', true );
		$wa_text = $wa_text ? $wa_text : $short_text;
		$img_fb_event_id = get_post_meta( $post_id, '_jbf_img_fb_event', true );
		$img_fb_event_url = $img_fb_event_id ? wp_get_attachment_url( $img_fb_event_id ) : '';
		$img_id  = get_post_meta( $post_id, '_jbf_img_social', true );
		$img_url = $img_id ? wp_get_attachment_url( $img_id ) : '';

		echo '<h2>' . esc_html( $post->post_title ) . '</h2>';

		echo '<h3>Facebook-Veranstaltung (manuell anlegen)</h3>';
		echo '<textarea readonly rows="6" class="large-text jbf-copy-source">' . esc_textarea( $fb_text ) . '</textarea>';
		echo '<p><button type="button" class="button jbf-copy-btn" data-copy-target="prev">Text kopieren</button></p>';
		if ( $img_fb_event_url ) {
			echo '<p><img src="' . esc_url( $img_fb_event_url ) . '" style="max-width:300px;" /></p>';
			echo '<p><a class="button" href="' . esc_url( $img_fb_event_url ) . '" download>Facebook-Veranstaltungsbild herunterladen (Querformat)</a></p>';
		} else {
			echo '<p class="description">Kein eigenes Bild für die Facebook-Veranstaltung hinterlegt (Checkliste im Editor, empfohlen 1200×628 Querformat).</p>';
		}

		echo '<h3>WhatsApp-Gruppe / WhatsApp-Kanal (manuell versenden)</h3>';
		echo '<textarea readonly rows="6" class="large-text jbf-copy-source">' . esc_textarea( $wa_text ) . '</textarea>';
		echo '<p><button type="button" class="button jbf-copy-btn" data-copy-target="prev">Text kopieren</button></p>';

		if ( $img_url ) {
			echo '<h3>Social-Bild zum Herunterladen</h3>';
			echo '<p><img src="' . esc_url( $img_url ) . '" style="max-width:300px;" /></p>';
			echo '<p><a class="button" href="' . esc_url( $img_url ) . '" download>Bild herunterladen</a></p>';
		}

		echo '</div>';
	}
}
