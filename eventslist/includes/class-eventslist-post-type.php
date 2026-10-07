<?php
/**
 * Registers the eventslist custom post type and its meta.
 *
 * @package eventslist
 */

defined( 'ABSPATH' ) || exit;

/**
 * The eventslist post type.
 */
class Eventslist_Post_Type {

	/**
	 * Hooks that are not needed before `init`.
	 */
	public static function init() {
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );

		/*
		 * Priority 99 so this runs after the meta box (priority 10) has written
		 * the submitted values and we are looking at the final state.
		 */
		add_action( 'save_post_' . EVENTSLIST_POST_TYPE, array( __CLASS__, 'backfill_dates' ), 99, 3 );
	}

	/**
	 * Registers the post type.
	 */
	public static function register() {
		$labels = array(
			'name'                  => _x( 'Events', 'post type general name', 'eventslist' ),
			'singular_name'         => _x( 'Event', 'post type singular name', 'eventslist' ),
			'menu_name'             => _x( 'Events', 'admin menu', 'eventslist' ),
			'name_admin_bar'        => _x( 'Event', 'add new on admin bar', 'eventslist' ),
			'add_new'               => __( 'Add New', 'eventslist' ),
			'add_new_item'          => __( 'Add New Event', 'eventslist' ),
			'new_item'              => __( 'New Event', 'eventslist' ),
			'edit_item'             => __( 'Edit Event', 'eventslist' ),
			'view_item'             => __( 'View Event', 'eventslist' ),
			'all_items'             => __( 'All Events', 'eventslist' ),
			'search_items'          => __( 'Search Events', 'eventslist' ),
			'not_found'             => __( 'No events found.', 'eventslist' ),
			'not_found_in_trash'    => __( 'No events found in Trash.', 'eventslist' ),
			'featured_image'        => __( 'Event Image', 'eventslist' ),
			'set_featured_image'    => __( 'Set event image', 'eventslist' ),
			'remove_featured_image' => __( 'Remove event image', 'eventslist' ),
			'items_list'            => __( 'Events list', 'eventslist' ),
		);

		$args = array(
			'labels'             => $labels,
			'description'        => __( 'Speaking engagements, webinars and other events.', 'eventslist' ),
			/*
			 * Events are managed in the admin and displayed through the
			 * [eventslist] shortcode, so no single-event pages or archive are
			 * created. Set 'public' and 'publicly_queryable' to true through
			 * the eventslist_post_type_args filter if you later want each event
			 * to have its own URL.
			 */
			'public'             => false,
			'publicly_queryable' => false,
			'has_archive'        => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'show_in_nav_menus'  => false,
			'show_in_admin_bar'  => true,
			'show_in_rest'       => true,
			'rest_base'          => 'eventslist',
			'menu_position'      => 21,
			'menu_icon'          => 'dashicons-calendar-alt',
			'capability_type'    => 'post',
			'map_meta_cap'       => true,
			'hierarchical'       => false,
			'supports'           => array( 'title', 'editor', 'thumbnail', 'revisions', 'author', 'custom-fields' ),
			'rewrite'            => array(
				'slug'       => 'events',
				'with_front' => false,
			),
		);

		/**
		 * Filters the arguments used to register the eventslist post type.
		 *
		 * @param array $args Registration arguments.
		 */
		$args = apply_filters( 'eventslist_post_type_args', $args );

		register_post_type( EVENTSLIST_POST_TYPE, $args );
	}

	/**
	 * Registers the event meta fields so they are available to the REST API
	 * and are sanitized consistently wherever they are written.
	 */
	public static function register_meta() {
		$fields = array(
			'start_date' => array(
				'description'       => __( 'Event start date (YYYY-MM-DD).', 'eventslist' ),
				'sanitize_callback' => array( 'Eventslist_Event', 'sanitize_date' ),
			),
			'end_date'   => array(
				'description'       => __( 'Event end date (YYYY-MM-DD).', 'eventslist' ),
				'sanitize_callback' => array( 'Eventslist_Event', 'sanitize_date' ),
			),
			'start_time' => array(
				'description'       => __( 'Event start time. A clock time such as 17:00 is formatted for display; anything else (for example "9 am PT") is shown as typed.', 'eventslist' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'location'   => array(
				'description'       => __( 'Where the event takes place, or Online / Virtual.', 'eventslist' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'url'        => array(
				'description'       => __( 'Link for the event, e.g. a registration page.', 'eventslist' ),
				'sanitize_callback' => 'esc_url_raw',
			),
			'link_text'  => array(
				'description'       => __( 'Text for the event link, e.g. "Register Now".', 'eventslist' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
		);

		foreach ( $fields as $field => $config ) {
			register_post_meta(
				EVENTSLIST_POST_TYPE,
				Eventslist_Event::meta_key( $field ),
				array(
					'type'              => 'string',
					'single'            => true,
					'default'           => '',
					'description'       => $config['description'],
					'sanitize_callback' => $config['sanitize_callback'],
					'show_in_rest'      => true,
					'auth_callback'     => function ( $allowed, $meta_key, $post_id ) {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			);
		}

		// Bookkeeping meta written by the importer; not exposed to REST.
		register_post_meta(
			EVENTSLIST_POST_TYPE,
			'eventslist_source_guid',
			array(
				'type'         => 'string',
				'single'       => true,
				'show_in_rest' => false,
			)
		);
	}

	/**
	 * A clearer placeholder in the title field.
	 *
	 * @param string  $text Placeholder text.
	 * @param WP_Post $post Post being edited.
	 * @return string
	 */
	public static function title_placeholder( $text, $post ) {
		if ( $post instanceof WP_Post && EVENTSLIST_POST_TYPE === $post->post_type ) {
			return __( 'Event title', 'eventslist' );
		}
		return $text;
	}

	/**
	 * Keeps the start and end dates populated.
	 *
	 * Every event needs a start date for the shortcode to place it, and an end
	 * date for the upcoming / past filter to work. If an editor leaves either
	 * blank we fill it in rather than letting the event drop out of the list:
	 * the end date falls back to the start date, and the start date falls back
	 * to the post's own publish date.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated.
	 */
	public static function backfill_dates( $post_id, $post, $update = false ) {
		unset( $update );

		if ( ! $post instanceof WP_Post || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( in_array( $post->post_status, array( 'auto-draft', 'inherit', 'trash' ), true ) ) {
			return;
		}

		$start = Eventslist_Event::get( $post_id, 'start_date' );
		$end   = Eventslist_Event::get( $post_id, 'end_date' );

		if ( '' === $start ) {
			$candidate = substr( (string) $post->post_date, 0, 10 );
			if ( Eventslist_Event::is_valid_date( $candidate ) ) {
				$start = $candidate;
				update_post_meta( $post_id, Eventslist_Event::meta_key( 'start_date' ), $start );
			}
		}

		// An end date before the start date is a typo; treat it as a single day.
		if ( '' !== $start && ( '' === $end || $end < $start ) ) {
			update_post_meta( $post_id, Eventslist_Event::meta_key( 'end_date' ), $start );
		}
	}

	/**
	 * On activation, register the post type then flush rewrite rules.
	 */
	public static function activate() {
		self::register();
		flush_rewrite_rules();
	}
}
