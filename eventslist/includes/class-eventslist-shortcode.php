<?php
/**
 * The [eventslist] shortcode.
 *
 * @package eventslist
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders events as two-column rows: date, then details.
 */
class Eventslist_Shortcode {

	const HANDLE = 'eventslist';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_shortcode( 'eventslist', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Registers (but does not enqueue) the stylesheet.
	 */
	public static function register_assets() {
		wp_register_style(
			self::HANDLE,
			EVENTSLIST_URL . 'assets/eventslist.css',
			array(),
			EVENTSLIST_VERSION
		);
	}

	/**
	 * Builds the WP_Query arguments for a set of shortcode attributes.
	 *
	 * @param array $atts Parsed attributes.
	 * @return array
	 */
	public static function query_args( $atts ) {
		$today = Eventslist_Event::today();
		$order = 'asc' === $atts['order'] ? 'ASC' : 'DESC';

		/*
		 * The start-date clause is always present so that it can be used for
		 * ordering. Dates are stored as YYYY-MM-DD, so a plain string sort is
		 * already chronological and no date casting is needed.
		 */
		$meta_query = array(
			'relation' => 'AND',
			'start'    => array(
				'key'     => Eventslist_Event::meta_key( 'start_date' ),
				'compare' => 'EXISTS',
			),
		);

		/*
		 * Upcoming and past are decided on the *end* date, so a conference that
		 * is part way through still counts as upcoming rather than dropping into
		 * the past list on its second day.
		 */
		if ( 'upcoming' === $atts['show'] ) {
			$meta_query['window'] = array(
				'key'     => Eventslist_Event::meta_key( 'end_date' ),
				'value'   => $today,
				'compare' => '>=',
				'type'    => 'DATE',
			);
		} elseif ( 'past' === $atts['show'] ) {
			$meta_query['window'] = array(
				'key'     => Eventslist_Event::meta_key( 'end_date' ),
				'value'   => $today,
				'compare' => '<',
				'type'    => 'DATE',
			);
		}

		// Cutoff: only events starting on or after this date.
		if ( ! empty( $atts['from'] ) ) {
			$meta_query['from'] = array(
				'key'     => Eventslist_Event::meta_key( 'start_date' ),
				'value'   => $atts['from'],
				'compare' => '>=',
				'type'    => 'DATE',
			);
		}

		$args = array(
			'post_type'            => EVENTSLIST_POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => $atts['limit'] > 0 ? (int) $atts['limit'] : -1,
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
			'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'orderby'                => array(
				'start' => $order,
				'title' => 'ASC',
			),
		);

		/**
		 * Filters the query used by the [eventslist] shortcode.
		 *
		 * @param array $args WP_Query arguments.
		 * @param array $atts Parsed shortcode attributes.
		 */
		return apply_filters( 'eventslist_query_args', $args, $atts );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				// upcoming | all | past.
				'show'        => 'all',
				// desc = newest first (default), asc = oldest first.
				'order'       => 'desc',
				// 0 for every matching event.
				'limit'       => 0,
				// yes | no -- show the editor content under the title.
				'description' => 'yes',
				// always (default), auto = hide the year for the current year, never.
				'year'        => 'always',
				// Cutoff date as YYYYMMDD (or YYYY-MM-DD); nothing before it is shown.
				'from'        => '',
				// Shown when nothing matches.
				'empty'       => '',
				// Extra class on the wrapper.
				'class'       => '',
			),
			$atts,
			'eventslist'
		);

		$atts['show']  = in_array( strtolower( (string) $atts['show'] ), array( 'upcoming', 'all', 'past' ), true )
			? strtolower( (string) $atts['show'] )
			: 'all';
		$atts['order'] = 'asc' === strtolower( (string) $atts['order'] ) ? 'asc' : 'desc';
		$atts['limit'] = max( 0, (int) $atts['limit'] );
		$atts['year']  = in_array( strtolower( (string) $atts['year'] ), array( 'auto', 'always', 'never' ), true )
			? strtolower( (string) $atts['year'] )
			: 'always';
		$atts['from']  = self::parse_cutoff( $atts['from'] );

		$show_description = ! in_array( strtolower( (string) $atts['description'] ), array( 'no', 'false', '0' ), true );

		$query = new WP_Query( self::query_args( $atts ) );

		wp_enqueue_style( self::HANDLE );

		if ( ! $query->have_posts() ) {
			$empty = '' !== $atts['empty'] ? $atts['empty'] : self::default_empty_message( $atts['show'] );
			return '<p class="eventslist-empty">' . esc_html( $empty ) . '</p>';
		}

		$classes = array( 'eventslist', 'eventslist--' . $atts['show'] );
		if ( '' !== trim( (string) $atts['class'] ) ) {
			foreach ( preg_split( '/\s+/', trim( (string) $atts['class'] ) ) as $class ) {
				$classes[] = sanitize_html_class( $class );
			}
		}

		$current_year = current_time( 'Y' );

		ob_start();
		?>
		<div class="<?php echo esc_attr( implode( ' ', array_filter( $classes ) ) ); ?>">
			<?php
			foreach ( $query->posts as $event ) {
				self::render_row( $event, $atts, $show_description, $current_year );
			}
			?>
		</div>
		<?php

		wp_reset_postdata();

		return (string) ob_get_clean();
	}

	/**
	 * Renders one event row.
	 *
	 * @param WP_Post $event            Event post.
	 * @param array   $atts             Parsed attributes.
	 * @param bool    $show_description Whether to print the description.
	 * @param string  $current_year     The current year, for year="auto".
	 */
	protected static function render_row( $event, $atts, $show_description, $current_year ) {
		$data  = Eventslist_Event::get_all( $event->ID );
		$parts = Eventslist_Event::format_date_parts( $data['start_date'], $data['end_date'] );
		$time  = Eventslist_Event::format_time( $data['start_time'] );

		$show_year = 'always' === $atts['year']
			|| ( 'auto' === $atts['year'] && '' !== $parts['year'] && 0 !== strcmp( $parts['year'], $current_year ) );

		$is_past = self::is_past( $event->ID );

		$row_classes = array( 'eventslist-event', $is_past ? 'is-past' : 'is-upcoming' );
		if ( Eventslist_Event::is_online( $data['location'] ) ) {
			$row_classes[] = 'is-online';
		}

		$description = '';
		if ( $show_description ) {
			$description = trim( self::clean_description( $event->post_content ) );
		}

		$link_text = '' !== trim( $data['link_text'] ) ? $data['link_text'] : __( 'Learn more', 'eventslist' );
		?>
		<div class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>">

			<div class="eventslist-date">
				<?php if ( '' !== $parts['day'] ) : ?>
					<span class="eventslist-date-day"><?php echo esc_html( $parts['day'] ); ?></span>
					<?php if ( $show_year && '' !== $parts['year'] ) : ?>
						<span class="eventslist-date-year"><?php echo esc_html( $parts['year'] ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<div class="eventslist-details">

				<h3 class="eventslist-title"><?php echo esc_html( get_the_title( $event ) ); ?></h3>

				<?php if ( '' !== $time || '' !== trim( $data['location'] ) ) : ?>
					<p class="eventslist-meta">
						<?php if ( '' !== $time ) : ?>
							<span class="eventslist-time"><?php echo esc_html( $time ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== trim( $data['location'] ) ) : ?>
							<span class="eventslist-location"><?php echo esc_html( trim( $data['location'] ) ); ?></span>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<?php if ( '' !== $description ) : ?>
					<div class="eventslist-description"><?php echo wp_kses_post( wpautop( $description ) ); ?></div>
				<?php endif; ?>

				<?php if ( '' !== trim( $data['url'] ) ) : ?>
					<p class="eventslist-link-wrap">
						<a class="eventslist-link" href="<?php echo esc_url( $data['url'] ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html( $link_text ); ?>
							<span class="eventslist-link-arrow" aria-hidden="true">&rarr;</span>
						</a>
					</p>
				<?php endif; ?>

			</div>

		</div>
		<?php
	}

	/**
	 * Normalises a cutoff date such as "20201210" or "2020-12-10" to the
	 * stored YYYY-MM-DD format.
	 *
	 * @param string $value Raw attribute value.
	 * @return string YYYY-MM-DD, or '' when missing or not a real date.
	 */
	public static function parse_cutoff( $value ) {
		if ( ! preg_match( '/^\s*(\d{4})-?(\d{2})-?(\d{2})\s*$/', (string) $value, $m ) ) {
			return '';
		}
		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return $m[1] . '-' . $m[2] . '-' . $m[3];
	}

	/**
	 * Whether an event has already finished.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	protected static function is_past( $post_id ) {
		$end = Eventslist_Event::effective_end_date( $post_id );
		return '' !== $end && $end < Eventslist_Event::today();
	}

	/**
	 * Tidies the stored description for display.
	 *
	 * Legacy content often ends with stray non-breaking spaces or empty
	 * paragraphs left behind when the call-to-action link was pulled out into
	 * its own field.
	 *
	 * @param string $content Raw post content.
	 * @return string
	 */
	public static function clean_description( $content ) {
		$content = (string) $content;

		// Normalise non-breaking spaces, both as entities and as raw UTF-8.
		$content = str_replace( array( '&nbsp;', "\xc2\xa0" ), ' ', $content );

		// Drop paragraphs and line breaks that hold nothing but whitespace.
		$content = preg_replace( '#<p[^>]*>(?:\s|<br\s*/?>)*</p>#i', '', $content );
		$content = preg_replace( '#(?:<br\s*/?>\s*)+$#i', '', $content );

		return trim( (string) $content );
	}

	/**
	 * The fallback "nothing here" message for a given mode.
	 *
	 * @param string $show upcoming | all | past.
	 * @return string
	 */
	protected static function default_empty_message( $show ) {
		if ( 'upcoming' === $show ) {
			return __( 'No upcoming events are scheduled right now. Please check back soon.', 'eventslist' );
		}
		if ( 'past' === $show ) {
			return __( 'No past events to show.', 'eventslist' );
		}
		return __( 'No events to show.', 'eventslist' );
	}
}
