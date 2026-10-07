<?php
/**
 * Event data accessor and formatting helpers.
 *
 * @package eventslist
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and formats the meta attached to an eventslist post.
 */
class Eventslist_Event {

	/**
	 * Logical field name => meta key actually stored in the database.
	 *
	 * @var array<string,string>
	 */
	const META_KEYS = array(
		'start_date' => 'eventslist_start_date',
		'end_date'   => 'eventslist_end_date',
		'start_time' => 'eventslist_start_time',
		'location'   => 'eventslist_location',
		'url'        => 'eventslist_url',
		'link_text'  => 'eventslist_link_text',
	);

	/**
	 * Meta keys used by the old Events List / el_events plugin. Read as a
	 * fallback so events brought across by the stock WordPress importer
	 * (which keeps the original keys) still display correctly.
	 *
	 * @var array<string,string>
	 */
	const LEGACY_META_KEYS = array(
		'start_date' => 'startdate',
		'end_date'   => 'enddate',
		'start_time' => 'starttime',
		'location'   => 'location',
	);

	/**
	 * Month abbreviations, AP style ("Sept" rather than "Sep").
	 *
	 * @return array<int,string>
	 */
	public static function month_abbreviations() {
		$months = array(
			1  => 'Jan',
			2  => 'Feb',
			3  => 'Mar',
			4  => 'Apr',
			5  => 'May',
			6  => 'June',
			7  => 'July',
			8  => 'Aug',
			9  => 'Sept',
			10 => 'Oct',
			11 => 'Nov',
			12 => 'Dec',
		);

		/**
		 * Filters the month abbreviations used in the date column.
		 *
		 * @param array<int,string> $months Month number => abbreviation.
		 */
		return apply_filters( 'eventslist_month_abbreviations', $months );
	}

	/**
	 * Returns the meta key for a logical field name.
	 *
	 * @param string $field Logical field name.
	 * @return string
	 */
	public static function meta_key( $field ) {
		return isset( self::META_KEYS[ $field ] ) ? self::META_KEYS[ $field ] : '';
	}

	/**
	 * Reads one field for a post, falling back to the legacy meta key.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Logical field name.
	 * @return string
	 */
	public static function get( $post_id, $field ) {
		$key = self::meta_key( $field );
		if ( '' === $key ) {
			return '';
		}

		$value = get_post_meta( $post_id, $key, true );

		if ( ( '' === $value || null === $value ) && isset( self::LEGACY_META_KEYS[ $field ] ) ) {
			$value = get_post_meta( $post_id, self::LEGACY_META_KEYS[ $field ], true );
		}

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Reads every field for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,string>
	 */
	public static function get_all( $post_id ) {
		$data = array();
		foreach ( array_keys( self::META_KEYS ) as $field ) {
			$data[ $field ] = self::get( $post_id, $field );
		}
		return $data;
	}

	/**
	 * The date an event finishes: the end date when set, otherwise the start date.
	 *
	 * @param int $post_id Post ID.
	 * @return string Y-m-d, or an empty string.
	 */
	public static function effective_end_date( $post_id ) {
		$end = self::get( $post_id, 'end_date' );
		return '' !== $end ? $end : self::get( $post_id, 'start_date' );
	}

	/**
	 * Today's date in the site's timezone.
	 *
	 * @return string Y-m-d
	 */
	public static function today() {
		return current_time( 'Y-m-d' );
	}

	/**
	 * Validates a Y-m-d string.
	 *
	 * @param string $date Candidate date.
	 * @return bool
	 */
	public static function is_valid_date( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
			return false;
		}
		return (bool) checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * Normalises whatever came out of a date input into Y-m-d, or an empty string.
	 *
	 * @param string $date Candidate date.
	 * @return string
	 */
	public static function sanitize_date( $date ) {
		$date = trim( (string) $date );
		if ( '' === $date ) {
			return '';
		}
		if ( self::is_valid_date( $date ) ) {
			return $date;
		}

		// Be forgiving about other common inputs, e.g. "9/24/2024".
		$timestamp = strtotime( $date );
		return false === $timestamp ? '' : gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * The ordinal suffix for a day of the month.
	 *
	 * @param int $day Day of month.
	 * @return string
	 */
	public static function ordinal_suffix( $day ) {
		$day = (int) $day;

		if ( in_array( $day % 100, array( 11, 12, 13 ), true ) ) {
			return 'th';
		}

		switch ( $day % 10 ) {
			case 1:
				return 'st';
			case 2:
				return 'nd';
			case 3:
				return 'rd';
			default:
				return 'th';
		}
	}

	/**
	 * Formats a single date as "Sept 24th".
	 *
	 * @param string $date Y-m-d.
	 * @return string
	 */
	public static function format_day( $date ) {
		if ( ! self::is_valid_date( $date ) ) {
			return '';
		}

		$months = self::month_abbreviations();
		$month  = (int) substr( $date, 5, 2 );
		$day    = (int) substr( $date, 8, 2 );

		$abbrev = isset( $months[ $month ] ) ? $months[ $month ] : '';

		return trim( $abbrev . ' ' . $day . self::ordinal_suffix( $day ) );
	}

	/**
	 * Formats the date column for an event, collapsing multi-day ranges.
	 *
	 * Single day ............. "Sept 24th"          year "2024"
	 * Range in one month ..... "June 28th - 30th"   year "2026"
	 * Range across months .... "Feb 27th - Mar 2nd" year "2026"
	 * Range across years ..... "Dec 30th - Jan 2nd" year "2025 - 2026"
	 *
	 * @param string $start Y-m-d start date.
	 * @param string $end   Y-m-d end date (may be empty or equal to the start).
	 * @return array Formatted parts, keyed 'day' and 'year'; empty when unknown.
	 */
	public static function format_date_parts( $start, $end = '' ) {
		$parts = array(
			'day'  => '',
			'year' => '',
		);

		if ( ! self::is_valid_date( $start ) ) {
			return $parts;
		}

		$parts['day']  = self::format_day( $start );
		$parts['year'] = substr( $start, 0, 4 );

		// Nothing more to do for a single-day event.
		if ( ! self::is_valid_date( $end ) || $end <= $start ) {
			return $parts;
		}

		$dash     = ' ' . self::ndash() . ' ';
		$end_day  = (int) substr( $end, 8, 2 );
		$end_year = substr( $end, 0, 4 );

		if ( substr( $start, 0, 7 ) === substr( $end, 0, 7 ) ) {
			// Same month and year: "June 28th - 30th".
			$parts['day'] .= $dash . $end_day . self::ordinal_suffix( $end_day );
		} else {
			// Different month: "Feb 27th - Mar 2nd".
			$parts['day'] .= $dash . self::format_day( $end );
		}

		if ( $end_year !== $parts['year'] ) {
			$parts['year'] .= $dash . $end_year;
		}

		return $parts;
	}

	/**
	 * An en dash character.
	 *
	 * @return string
	 */
	private static function ndash() {
		return "\xe2\x80\x93";
	}

	/**
	 * Formats a start time for display.
	 *
	 * Values stored as a clock time ("17:00:00") are rendered as "5:00 pm".
	 * Anything else is free text the editor typed on purpose -- values like
	 * "9 am PT" or "8:30 AM PT / 11:30 AM ET" -- and is returned untouched.
	 *
	 * @param string $time Stored start time.
	 * @return string
	 */
	public static function format_time( $time ) {
		$time = trim( (string) $time );
		if ( '' === $time ) {
			return '';
		}

		if ( ! preg_match( '/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $time, $m ) ) {
			return $time;
		}

		$hours   = (int) $m[1];
		$minutes = (int) $m[2];

		if ( $hours > 23 || $minutes > 59 ) {
			return $time;
		}

		$meridiem = $hours < 12 ? 'am' : 'pm';
		$hour12   = $hours % 12;
		if ( 0 === $hour12 ) {
			$hour12 = 12;
		}

		return sprintf( '%d:%02d %s', $hour12, $minutes, $meridiem );
	}

	/**
	 * Whether an event's location reads as a virtual one.
	 *
	 * @param string $location Location text.
	 * @return bool
	 */
	public static function is_online( $location ) {
		return (bool) preg_match( '/\b(online|virtual|webinar|zoom|remote)\b/i', (string) $location );
	}
}
