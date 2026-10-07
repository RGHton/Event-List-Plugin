<?php
/**
 * Standalone harness: runs the plugin's parsing, mapping and formatting logic
 * against the real WXR export, outside WordPress, so the import can be
 * verified before the plugin is installed.
 *
 * Usage: php tests/harness.php path/to/export.xml [--json]
 *
 * @package eventslist
 */

define( 'ABSPATH', __DIR__ );
define( 'EVENTSLIST_POST_TYPE', 'eventslist' );

// --- Minimal WordPress stubs -------------------------------------------------

function apply_filters( $hook, $value ) {
	return $value;
}
function __( $text, $domain = '' ) {
	return $text;
}
function _x( $text, $context = '', $domain = '' ) {
	return $text;
}
function esc_html__( $text, $domain = '' ) {
	return $text;
}
function wp_slash( $value ) {
	return $value;
}
function wp_strip_all_tags( $string, $remove_breaks = false ) {
	$string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $string );
	$string = strip_tags( $string );
	if ( $remove_breaks ) {
		$string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
	}
	return trim( $string );
}
function sanitize_text_field( $str ) {
	$str = wp_strip_all_tags( (string) $str );
	$str = preg_replace( '/[\r\n\t]+/', ' ', $str );
	return trim( preg_replace( '/ +/', ' ', $str ) );
}
function esc_url_raw( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	if ( ! preg_match( '#^(https?|mailto|tel):#i', $url ) && 0 !== strpos( $url, '/' ) ) {
		return '';
	}
	return $url;
}
function esc_html( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $t ) {
	return htmlspecialchars( esc_url_raw( $t ), ENT_QUOTES, 'UTF-8' );
}
function current_time( $format ) {
	return gmdate( $format );
}
function get_post_meta( $id, $key, $single = false ) {
	return '';
}
function update_post_meta( $id, $key, $value ) {
	return true;
}
function delete_post_meta( $id, $key ) {
	return true;
}
function get_posts( $args ) {
	return array();
}
function wp_insert_post( $arr, $wp_error = false ) {
	return 1;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function wpautop( $pee, $br = true ) {
	return '<p>' . str_replace( "\n\n", "</p>\n<p>", trim( (string) $pee ) ) . '</p>';
}
function wp_kses_post( $data ) {
	return $data;
}

class WP_Error {
	private $msg;
	public function __construct( $code = '', $message = '' ) {
		$this->msg = $message;
	}
	public function get_error_message() {
		return $this->msg;
	}
}

// --- Load the plugin classes under test -------------------------------------

require_once __DIR__ . '/../eventslist/includes/class-eventslist-event.php';
require_once __DIR__ . '/../eventslist/includes/class-eventslist-importer.php';

// --- Re-run the mapping, capturing the full result for inspection -----------

/**
 * Subclass that records the complete mapped record rather than the short
 * report row, so every field can be checked.
 */
class Harness_Importer extends Eventslist_Importer {

	/** @var array */
	public $records = array();

	protected function process_item( $item ) {
		$wp        = $item->children( 'http://wordpress.org/export/1.2/' );
		$post_type = isset( $wp->post_type ) ? (string) $wp->post_type : '';

		if ( ! in_array( $post_type, self::SOURCE_POST_TYPES, true ) ) {
			return;
		}

		$content_ns = $item->children( 'http://purl.org/rss/1.0/modules/content/' );
		$content    = isset( $content_ns->encoded ) ? (string) $content_ns->encoded : '';

		$meta      = $this->collect_meta( $wp );
		$extracted = Eventslist_Importer::extract_link( $content );

		$start = Eventslist_Event::sanitize_date( $meta['start_date'] ?? '' );
		$end   = Eventslist_Event::sanitize_date( $meta['end_date'] ?? '' );
		if ( '' === $end || $end < $start ) {
			$end = $start;
		}

		$this->records[] = array(
			'title'       => trim( (string) $item->title ),
			'start'       => $start,
			'end'         => $end,
			'date_parts'  => Eventslist_Event::format_date_parts( $start, $end ),
			'time_raw'    => $meta['start_time'] ?? '',
			'time'        => Eventslist_Event::format_time( $meta['start_time'] ?? '' ),
			'location'    => isset( $meta['location'] ) ? sanitize_text_field( $meta['location'] ) : '',
			'url'         => $extracted['url'],
			'link_text'   => $extracted['text'],
			'description' => $extracted['content'],
			'raw_content' => $content,
		);

		parent::process_item( $item );
	}
}

$file = $argv[1] ?? '';
if ( '' === $file || ! is_readable( $file ) ) {
	fwrite( STDERR, "Usage: php tests/harness.php path/to/export.xml [--json]\n" );
	exit( 1 );
}

$importer = new Harness_Importer( array( 'dry_run' => true ) );
$result   = $importer->import_file( $file );

if ( is_wp_error( $result ) ) {
	fwrite( STDERR, 'ERROR: ' . $result->get_error_message() . "\n" );
	exit( 1 );
}

$records = $importer->records;
usort(
	$records,
	static function ( $a, $b ) {
		return strcmp( $b['start'], $a['start'] );
	}
);

if ( in_array( '--json', $argv, true ) ) {
	echo json_encode( $records, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	exit( 0 );
}

// --- Human-readable report ---------------------------------------------------

$stats = $importer->get_stats();
echo "Parsed {$stats['found']} events (created {$stats['created']}, skipped {$stats['skipped']}, failed {$stats['failed']})\n";
echo str_repeat( '=', 100 ) . "\n";

$missing_date = 0;
$missing_time = 0;
$missing_loc  = 0;
$with_link    = 0;
$leftover     = 0;

foreach ( $records as $r ) {
	$date = trim( $r['date_parts']['day'] . '  ' . $r['date_parts']['year'] );
	printf( "%-26s | %s\n", $date, $r['title'] );
	printf( "%-26s | time: %-26s loc: %s\n", '', $r['time'] !== '' ? $r['time'] : '-', $r['location'] !== '' ? $r['location'] : '-' );
	if ( '' !== $r['url'] ) {
		printf( "%-26s | link: [%s] %s\n", '', $r['link_text'], $r['url'] );
	}
	if ( '' !== $r['description'] ) {
		printf( "%-26s | desc: %s\n", '', str_replace( "\n", ' / ', $r['description'] ) );
	}
	echo str_repeat( '-', 100 ) . "\n";

	if ( '' === $r['start'] ) {
		++$missing_date;
	}
	if ( '' === $r['time'] ) {
		++$missing_time;
	}
	if ( '' === $r['location'] ) {
		++$missing_loc;
	}
	if ( '' !== $r['url'] ) {
		++$with_link;
	}
	// Anything that still contains an anchor after extraction.
	if ( false !== stripos( $r['description'], '<a ' ) ) {
		++$leftover;
	}
}

echo "\nSUMMARY\n";
echo "  events ................. " . count( $records ) . "\n";
echo "  missing start date ..... {$missing_date}\n";
echo "  no start time .......... {$missing_time}\n";
echo "  no location ............ {$missing_loc}\n";
echo "  link extracted ......... {$with_link}\n";
echo "  inline link kept ....... {$leftover}\n";
