<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bindet die Veranstaltungsverwaltung an die Rolle "wl_mitglied" des
 * Vereins-Wunschliste-Plugins an. Mitglieder können Veranstaltungen komplett
 * verwalten (auch die anderer Mitglieder, wie ein Team-Kalender), aber der
 * Versand an externe Kanäle (Presse, Social Media, Signal) bleibt dem
 * Vorstand (Administrator/Redakteur) vorbehalten.
 */
class Jbf_Roles {

	const MEMBER_ROLE = 'wl_mitglied';

	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_dependency_notice' ) );
	}

	/**
	 * Capabilities, die die Veranstaltungen komplett verwalten dürfen
	 * (anlegen, bearbeiten, veröffentlichen, löschen – auch fremde Beiträge).
	 * Wird sowohl Mitgliedern als auch Vorstand zugewiesen.
	 */
	public static function full_management_caps() {
		return array(
			'jbf_edit_event',
			'jbf_read_event',
			'jbf_delete_event',
			'jbf_edit_events',
			'jbf_edit_others_events',
			'jbf_edit_published_events',
			'jbf_publish_events',
			'jbf_delete_events',
			'jbf_delete_others_events',
			'jbf_delete_published_events',
			'jbf_read_private_events',
		);
	}

	/**
	 * Zusätzliche Capability nur für den Vorstand: Versand an externe Kanäle.
	 */
	public static function external_send_cap() {
		return 'jbf_send_external';
	}

	/**
	 * Mapping für register_post_type() – WordPress übernimmt damit automatisch
	 * die Autoren-Logik (eigene vs. fremde Beiträge) über map_meta_cap.
	 */
	public static function post_type_capabilities() {
		return array(
			'edit_post'              => 'jbf_edit_event',
			'read_post'               => 'jbf_read_event',
			'delete_post'             => 'jbf_delete_event',
			'edit_posts'              => 'jbf_edit_events',
			'edit_others_posts'       => 'jbf_edit_others_events',
			'edit_published_posts'    => 'jbf_edit_published_events',
			'publish_posts'           => 'jbf_publish_events',
			'delete_posts'            => 'jbf_delete_events',
			'delete_others_posts'     => 'jbf_delete_others_events',
			'delete_published_posts'  => 'jbf_delete_published_events',
			'read_private_posts'      => 'jbf_read_private_events',
		);
	}

	public static function setup_roles() {
		$member_role = get_role( self::MEMBER_ROLE );

		if ( $member_role ) {
			foreach ( self::full_management_caps() as $cap ) {
				if ( empty( $member_role->capabilities[ $cap ] ) ) {
					$member_role->add_cap( $cap, true );
				}
			}
			// Bewusst OHNE jbf_send_external – Mitglieder reichen nur ein.
		}

		foreach ( array( 'administrator', 'editor' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			$all_caps = array_merge( self::full_management_caps(), array( self::external_send_cap() ) );
			foreach ( $all_caps as $cap ) {
				if ( empty( $role->capabilities[ $cap ] ) ) {
					$role->add_cap( $cap, true );
				}
			}
		}
	}

	public static function member_role_exists() {
		return (bool) get_role( self::MEMBER_ROLE );
	}

	public static function can_send_external() {
		return current_user_can( self::external_send_cap() );
	}

	public static function maybe_show_dependency_notice() {
		if ( self::member_role_exists() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p><strong>Jufobleibt Event Publisher:</strong> Die Rolle "wl_mitglied" (aus dem Vereins-Wunschliste-Plugin) wurde nicht gefunden. Vereinsmitglieder können Veranstaltungen erst anlegen, sobald das Wunschlisten-Plugin aktiv ist und Mitglieder importiert wurden. Administratoren und Redakteure können unabhängig davon bereits Veranstaltungen verwalten.</p></div>';
	}
}
