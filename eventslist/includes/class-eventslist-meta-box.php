<?php
/**
 * The Event Details meta box.
 *
 * @package eventslist
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the event detail fields.
 */
class Eventslist_Meta_Box {

	const NONCE_ACTION = 'eventslist_save_details';
	const NONCE_NAME   = 'eventslist_details_nonce';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
		add_action( 'save_post_' . EVENTSLIST_POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_head', array( __CLASS__, 'styles' ) );
	}

	/**
	 * Registers the meta box.
	 */
	public static function add() {
		add_meta_box(
			'eventslist-details',
			__( 'Event Details', 'eventslist' ),
			array( __CLASS__, 'render' ),
			EVENTSLIST_POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Inline styles for the meta box.
	 */
	public static function styles() {
		$screen = get_current_screen();
		if ( ! $screen || EVENTSLIST_POST_TYPE !== $screen->post_type ) {
			return;
		}
		?>
		<style>
			.eventslist-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px 24px; }
			.eventslist-fields .eventslist-field-full { grid-column: 1 / -1; }
			.eventslist-fields label { display: block; font-weight: 600; margin-bottom: 4px; }
			.eventslist-fields input[type="text"],
			.eventslist-fields input[type="date"],
			.eventslist-fields input[type="url"] { width: 100%; }
			.eventslist-fields .description { margin-top: 4px; }
			@media screen and (max-width: 782px) {
				.eventslist-fields { grid-template-columns: 1fr; }
			}
		</style>
		<?php
	}

	/**
	 * Renders the meta box fields.
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public static function render( $post ) {
		$data = Eventslist_Event::get_all( $post->ID );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<div class="eventslist-fields">

			<div class="eventslist-field">
				<label for="eventslist_start_date"><?php esc_html_e( 'Start date', 'eventslist' ); ?></label>
				<input type="date" id="eventslist_start_date" name="eventslist_start_date"
					value="<?php echo esc_attr( $data['start_date'] ); ?>" />
				<p class="description">
					<?php esc_html_e( 'Required. Determines where the event sorts, and whether it counts as upcoming or past.', 'eventslist' ); ?>
				</p>
			</div>

			<div class="eventslist-field">
				<label for="eventslist_end_date"><?php esc_html_e( 'End date', 'eventslist' ); ?></label>
				<input type="date" id="eventslist_end_date" name="eventslist_end_date"
					value="<?php echo esc_attr( $data['end_date'] ); ?>" />
				<p class="description">
					<?php esc_html_e( 'Only needed for events spanning more than one day. Leave blank for a single-day event.', 'eventslist' ); ?>
				</p>
			</div>

			<div class="eventslist-field">
				<label for="eventslist_start_time"><?php esc_html_e( 'Start time', 'eventslist' ); ?></label>
				<input type="text" id="eventslist_start_time" name="eventslist_start_time"
					value="<?php echo esc_attr( $data['start_time'] ); ?>"
					placeholder="<?php esc_attr_e( '9:00 am PT', 'eventslist' ); ?>" />
				<p class="description">
					<?php esc_html_e( 'Free text. A clock time such as 17:00 is displayed as "5:00 pm"; anything else, such as "9 am PT" or "8:30 AM PT / 11:30 AM ET", is shown exactly as typed.', 'eventslist' ); ?>
				</p>
			</div>

			<div class="eventslist-field">
				<label for="eventslist_location"><?php esc_html_e( 'Location', 'eventslist' ); ?></label>
				<input type="text" id="eventslist_location" name="eventslist_location"
					value="<?php echo esc_attr( $data['location'] ); ?>"
					placeholder="<?php esc_attr_e( 'Santa Barbara, CA  /  Online', 'eventslist' ); ?>" />
				<p class="description">
					<?php esc_html_e( 'A city, a venue, or "Online" / "Virtual" for remote events. Leave blank to omit.', 'eventslist' ); ?>
				</p>
			</div>

			<div class="eventslist-field">
				<label for="eventslist_url"><?php esc_html_e( 'Link URL', 'eventslist' ); ?></label>
				<input type="url" id="eventslist_url" name="eventslist_url"
					value="<?php echo esc_attr( $data['url'] ); ?>"
					placeholder="https://example.com/register" />
				<p class="description">
					<?php esc_html_e( 'Optional registration or information page. The link only appears when this is filled in.', 'eventslist' ); ?>
				</p>
			</div>

			<div class="eventslist-field">
				<label for="eventslist_link_text"><?php esc_html_e( 'Link text', 'eventslist' ); ?></label>
				<input type="text" id="eventslist_link_text" name="eventslist_link_text"
					value="<?php echo esc_attr( $data['link_text'] ); ?>"
					placeholder="<?php esc_attr_e( 'Register Now', 'eventslist' ); ?>" />
				<p class="description">
					<?php
					printf(
						/* translators: %s: the default link text. */
						esc_html__( 'Defaults to "%s" when left blank.', 'eventslist' ),
						esc_html__( 'Learn more', 'eventslist' )
					);
					?>
				</p>
			</div>

			<div class="eventslist-field eventslist-field-full">
				<p class="description">
					<?php esc_html_e( 'Use the main editor above for the event description, for example the session or keynote title. It appears under the event title in the list.', 'eventslist' ); ?>
				</p>
			</div>

		</div>
		<?php
	}

	/**
	 * Saves the submitted fields.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save( $post_id, $post ) {
		// Autosaves and revisions do not carry our fields.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Only act when our own form was submitted. This keeps quick edit,
		// the REST API and the importer from being treated as a blank form.
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		unset( $post );

		$fields = array(
			'start_date' => array( 'Eventslist_Event', 'sanitize_date' ),
			'end_date'   => array( 'Eventslist_Event', 'sanitize_date' ),
			'start_time' => 'sanitize_text_field',
			'location'   => 'sanitize_text_field',
			'url'        => 'esc_url_raw',
			'link_text'  => 'sanitize_text_field',
		);

		foreach ( $fields as $field => $sanitizer ) {
			$key   = Eventslist_Event::meta_key( $field );
			$raw   = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
			$value = call_user_func( $sanitizer, $raw );

			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}
	}
}
