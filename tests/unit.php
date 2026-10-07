<?php
/**
 * Edge-case tests for the date, time and link helpers.
 *
 * Usage: php tests/unit.php
 *
 * @package eventslist
 */

define( 'ABSPATH', __DIR__ );
define( 'EVENTSLIST_POST_TYPE', 'eventslist' );

function apply_filters( $h, $v ) {
	return $v;
}
function __( $t, $d = '' ) {
	return $t;
}
function wp_strip_all_tags( $s, $rb = false ) {
	return trim( strip_tags( (string) $s ) );
}
function sanitize_text_field( $s ) {
	return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $s ) ) );
}
function esc_url_raw( $u ) {
	$u = trim( (string) $u );
	return preg_match( '#^(https?|mailto|tel):#i', $u ) ? $u : '';
}
function get_post_meta( $i, $k, $s = false ) {
	return '';
}
function update_post_meta( $i, $k, $v ) {
	return true;
}
function delete_post_meta( $i, $k ) {
	return true;
}
function get_posts( $a ) {
	return array();
}
function wp_insert_post( $a, $e = false ) {
	return 1;
}
function wp_slash( $v ) {
	return $v;
}
function is_wp_error( $t ) {
	return $t instanceof WP_Error;
}
class WP_Error {
	public function __construct( $c = '', $m = '' ) {}
	public function get_error_message() {
		return ''; }
}

require_once __DIR__ . '/../eventslist/includes/class-eventslist-event.php';
require_once __DIR__ . '/../eventslist/includes/class-eventslist-importer.php';

$pass = 0;
$fail = 0;

/**
 * Asserts two values match.
 *
 * @param string $label    Test name.
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 */
function check( $label, $expected, $actual ) {
	global $pass, $fail;
	if ( $expected === $actual ) {
		++$pass;
		printf( "  ok    %s\n", $label );
		return;
	}
	++$fail;
	printf( "  FAIL  %s\n          expected: %s\n          actual:   %s\n", $label, var_export( $expected, true ), var_export( $actual, true ) );
}

$dash = "\xe2\x80\x93";

echo "format_day / ordinals\n";
check( 'Sept 24th', 'Sept 24th', Eventslist_Event::format_day( '2024-09-24' ) );
check( '1st', 'June 1st', Eventslist_Event::format_day( '2020-06-01' ) );
check( '2nd', 'Nov 2nd', Eventslist_Event::format_day( '2020-11-02' ) );
check( '3rd', 'June 3rd', Eventslist_Event::format_day( '2026-06-03' ) );
check( '11th not 11st', 'Feb 11th', Eventslist_Event::format_day( '2025-02-11' ) );
check( '12th', 'Dec 12th', Eventslist_Event::format_day( '2025-12-12' ) );
check( '13th', 'Mar 13th', Eventslist_Event::format_day( '2025-03-13' ) );
check( '21st', 'Apr 21st', Eventslist_Event::format_day( '2025-04-21' ) );
check( '22nd', 'May 22nd', Eventslist_Event::format_day( '2025-05-22' ) );
check( '23rd', 'July 23rd', Eventslist_Event::format_day( '2025-07-23' ) );
check( '31st', 'Jan 31st', Eventslist_Event::format_day( '2025-01-31' ) );
check( 'invalid date', '', Eventslist_Event::format_day( 'not-a-date' ) );
check( 'impossible date', '', Eventslist_Event::format_day( '2025-02-30' ) );

echo "\nformat_date_parts / ranges\n";
check(
	'single day',
	array( 'day' => 'Sept 24th', 'year' => '2024' ),
	Eventslist_Event::format_date_parts( '2024-09-24', '2024-09-24' )
);
check(
	'empty end date',
	array( 'day' => 'Sept 24th', 'year' => '2024' ),
	Eventslist_Event::format_date_parts( '2024-09-24', '' )
);
check(
	'same month range',
	array( 'day' => 'June 28th' . " $dash " . '30th', 'year' => '2026' ),
	Eventslist_Event::format_date_parts( '2026-06-28', '2026-06-30' )
);
check(
	'cross month range',
	array( 'day' => 'Feb 27th' . " $dash " . 'Mar 2nd', 'year' => '2026' ),
	Eventslist_Event::format_date_parts( '2026-02-27', '2026-03-02' )
);
check(
	'cross year range',
	array( 'day' => 'Dec 30th' . " $dash " . 'Jan 2nd', 'year' => '2025' . " $dash " . '2026' ),
	Eventslist_Event::format_date_parts( '2025-12-30', '2026-01-02' )
);
check(
	'end before start is ignored',
	array( 'day' => 'Sept 24th', 'year' => '2024' ),
	Eventslist_Event::format_date_parts( '2024-09-24', '2024-01-01' )
);
check(
	'no start date',
	array( 'day' => '', 'year' => '' ),
	Eventslist_Event::format_date_parts( '', '2024-01-01' )
);

echo "\nformat_time\n";
check( 'midnight', '12:00 am', Eventslist_Event::format_time( '00:00:00' ) );
check( 'morning', '8:15 am', Eventslist_Event::format_time( '08:15:00' ) );
check( 'noon', '12:00 pm', Eventslist_Event::format_time( '12:00:00' ) );
check( 'afternoon', '5:00 pm', Eventslist_Event::format_time( '17:00:00' ) );
check( 'evening', '5:30 pm', Eventslist_Event::format_time( '17:30:00' ) );
check( 'no seconds', '11:30 am', Eventslist_Event::format_time( '11:30' ) );
check( 'free text kept', '9 am PT', Eventslist_Event::format_time( '9 am PT' ) );
check( 'dual zone kept', '8:30 AM PT / 11:30 AM ET', Eventslist_Event::format_time( '8:30 AM PT / 11:30 AM ET' ) );
check( 'mixed kept', '09:00 AM PT', Eventslist_Event::format_time( '09:00 AM PT' ) );
check( 'empty', '', Eventslist_Event::format_time( '' ) );
check( 'out of range kept as typed', '99:99', Eventslist_Event::format_time( '99:99' ) );

echo "\nsanitize_date\n";
check( 'passthrough', '2024-09-24', Eventslist_Event::sanitize_date( '2024-09-24' ) );
check( 'us format', '2024-09-24', Eventslist_Event::sanitize_date( '9/24/2024' ) );
check( 'empty', '', Eventslist_Event::sanitize_date( '' ) );
check( 'garbage', '', Eventslist_Event::sanitize_date( 'nonsense' ) );

echo "\nextract_link\n";
$r = Eventslist_Importer::extract_link( 'Session: Wired to Grow' );
check( 'no link: url', '', $r['url'] );
check( 'no link: content kept', 'Session: Wired to Grow', $r['content'] );

$r = Eventslist_Importer::extract_link( 'Session: X <a href="https://e.com/r" target="_blank" rel="noopener">Register Now &gt;</a>' );
check( 'cta url', 'https://e.com/r', $r['url'] );
check( 'cta text trimmed', 'Register Now', $r['text'] );
check( 'cta removed from content', 'Session: X', $r['content'] );

$r = Eventslist_Importer::extract_link( '<a href="https://a.com/s">Session Title</a>' . "\n\n" . '<a href="https://b.com/reg">Register Now &gt;&nbsp;</a>' );
check( 'last anchor is the cta', 'https://b.com/reg', $r['url'] );
check( 'cta text nbsp stripped', 'Register Now', $r['text'] );
check( 'inline anchor retained', '<a href="https://a.com/s">Session Title</a>', $r['content'] );

$r = Eventslist_Importer::extract_link( '<a href="https://only.com/x">Learn More &gt;&gt;</a>' );
check( 'only a link: url', 'https://only.com/x', $r['url'] );
check( 'only a link: text', 'Learn More', $r['text'] );
check( 'only a link: content empty', '', $r['content'] );

$r = Eventslist_Importer::extract_link( 'Listen here: <a href="https://pod.com/ep">Learning How to Learn</a>' );
check( 'dangling label dropped', '', $r['content'] );
check( 'episode name as link text', 'Learning How to Learn', $r['text'] );

$r = Eventslist_Importer::extract_link( '<strong>Webinar:</strong> <a href="https://z.com/w">Register</a>' );
check( 'html label dropped', '', $r['content'] );

$r = Eventslist_Importer::extract_link( "Keynote: The Science of Teams\n\n<a href=\"https://x.com\">Register &rsaquo;</a>" );
check( 'description preserved', 'Keynote: The Science of Teams', $r['content'] );
check( 'single angle quote stripped', 'Register', $r['text'] );

$r = Eventslist_Importer::extract_link( 'See <a href="javascript:alert(1)">bad</a>' );
check( 'unsafe scheme rejected', '', $r['url'] );

$r = Eventslist_Importer::extract_link( '' );
check( 'empty content', '', $r['content'] );

$r = Eventslist_Importer::extract_link( 'Email <a href="mailto:a@b.com">us</a>' );
check( 'mailto allowed', 'mailto:a@b.com', $r['url'] );

$r = Eventslist_Importer::extract_link( 'Query <a href="https://x.com/a?b=1&amp;c=2">Go</a>' );
check( 'entity-encoded ampersand decoded', 'https://x.com/a?b=1&c=2', $r['url'] );

echo "\nis_online\n";
check( 'Online', true, Eventslist_Event::is_online( 'Online' ) );
check( 'Virtual Webinar', true, Eventslist_Event::is_online( 'Virtual Webinar' ) );
check( 'Virtual via Zoom', true, Eventslist_Event::is_online( 'Virtual via Zoom' ) );
check( 'a city', false, Eventslist_Event::is_online( 'Santa Barbara, California' ) );
check( 'empty', false, Eventslist_Event::is_online( '' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
