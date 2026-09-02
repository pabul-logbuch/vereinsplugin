<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Jbf_Cpt {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
	}

	public static function register_post_type() {
		$labels = array(
			'name'               => __( 'Veranstaltungen', 'jufobleibt-event-publisher' ),
			'singular_name'      => __( 'Veranstaltung', 'jufobleibt-event-publisher' ),
			'add_new_item'       => __( 'Neue Veranstaltung anlegen', 'jufobleibt-event-publisher' ),
			'edit_item'          => __( 'Veranstaltung bearbeiten', 'jufobleibt-event-publisher' ),
			'all_items'          => __( 'Alle Veranstaltungen', 'jufobleibt-event-publisher' ),
			'view_item'          => __( 'Veranstaltung ansehen', 'jufobleibt-event-publisher' ),
			'search_items'       => __( 'Veranstaltungen durchsuchen', 'jufobleibt-event-publisher' ),
		);

		register_post_type(
			'veranstaltung',
			array(
				'labels'        => $labels,
				'public'        => true,
				'show_in_rest'  => true,
				'menu_icon'     => 'dashicons-megaphone',
				'supports'      => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'has_archive'   => true,
				'rewrite'       => array( 'slug' => 'veranstaltungen' ),
				'capability_type'    => array( 'veranstaltung', 'veranstaltungen' ),
				'map_meta_cap'       => true,
				'capabilities'       => Jbf_Roles::post_type_capabilities(),
			)
		);
	}
}
