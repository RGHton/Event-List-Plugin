<?php
/**
 * Renders the real [eventslist] shortcode output, using the real event data
 * from the export, into a standalone HTML file so the two-column layout can be
 * checked in a browser before the plugin is installed.
 *
 * Usage: php tests/preview.php path/to/export.xml > preview.html
 *
 * @package eventslist
 */

define( 'ABSPATH', __DIR__ );
define( 'EVENTSLIST_POST_TYPE', 'eventslist' );
define( 'EVENTSLIST_URL', '' );
define( 'EVENTSLIST_VERSION', '1.0.5' );

// --- Stubs -------------------------------------------------------------------

$GLOBALS['el_meta']  = array();
$GLOBALS['el_posts'] = array();

function apply_filters( $hook, $value ) {
	return $value;
}
function add_shortcode( $tag, $cb ) {}
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}
	return $out;
}
function add_action( $hook, $cb, $p = 10, $a = 1 ) {}
function add_filter( $hook, $cb, $p = 10, $a = 1 ) {}
function wp_register_style() {}
function wp_enqueue_style() {}
function wp_reset_postdata() {}
function __( $t, $d = '' ) {
	return $t;
}
function _x( $t, $c = '', $d = '' ) {
	return $t;
}
function esc_html__( $t, $d = '' ) {
	return $t;
}
function wp_slash( $v ) {
	return $v;
}
function is_wp_error( $t ) {
	return $t instanceof WP_Error;
}
function wp_strip_all_tags( $s, $rb = false ) {
	return trim( strip_tags( (string) $s ) );
}
function sanitize_text_field( $s ) {
	return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $s ) ) );
}
function sanitize_html_class( $c ) {
	return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $c );
}
function esc_url_raw( $u ) {
	$u = trim( (string) $u );
	return preg_match( '#^(https?|mailto|tel):#i', $u ) ? $u : '';
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
function current_time( $f ) {
	return gmdate( $f );
}
function wpautop( $p, $br = true ) {
	$p = trim( (string) $p );
	return '' === $p ? '' : '<p>' . str_replace( "\n\n", "</p>\n<p>", $p ) . '</p>';
}
function wp_kses_post( $d ) {
	return $d;
}
function get_the_title( $post ) {
	return is_object( $post ) ? $post->post_title : '';
}
function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['el_meta'][ $id ][ $key ] ?? '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['el_meta'][ $id ][ $key ] = $value;
	return true;
}
function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['el_meta'][ $id ][ $key ] );
	return true;
}
function get_posts( $a ) {
	return array();
}
function wp_insert_post( $arr, $e = false ) {
	static $next = 1;
	$id          = $next++;

	$post               = new stdClass();
	$post->ID           = $id;
	$post->post_title   = $arr['post_title'];
	$post->post_content = $arr['post_content'];
	$post->post_status  = $arr['post_status'];

	$GLOBALS['el_posts'][ $id ] = $post;
	return $id;
}

class WP_Error {
	private $m;
	public function __construct( $c = '', $m = '' ) {
		$this->m = $m;
	}
	public function get_error_message() {
		return $this->m;
	}
}

/**
 * A stand-in for WP_Query that honours the arguments the shortcode builds,
 * so query_args() is exercised rather than bypassed.
 */
class WP_Query {

	/** @var array */
	public $posts = array();

	public function __construct( $args ) {
		$window = $args['meta_query']['window'] ?? null;
		$from   = $args['meta_query']['from'] ?? null;
		$order  = $args['orderby']['start'] ?? 'DESC';
		$limit  = (int) ( $args['posts_per_page'] ?? -1 );

		$matched = array();

		foreach ( $GLOBALS['el_posts'] as $id => $post ) {
			if ( 'publish' !== $post->post_status ) {
				continue;
			}

			$start = $GLOBALS['el_meta'][ $id ]['eventslist_start_date'] ?? '';
			if ( '' === $start ) {
				continue; // The EXISTS clause on start_date.
			}

			if ( $from && ! ( $start >= $from['value'] ) ) {
				continue;
			}

			if ( $window ) {
				$end = $GLOBALS['el_meta'][ $id ][ $window['key'] ] ?? '';
				if ( '' === $end ) {
					continue;
				}
				if ( '>=' === $window['compare'] && ! ( $end >= $window['value'] ) ) {
					continue;
				}
				if ( '<' === $window['compare'] && ! ( $end < $window['value'] ) ) {
					continue;
				}
			}

			$matched[] = array( 'start' => $start, 'post' => $post );
		}

		usort(
			$matched,
			static function ( $a, $b ) use ( $order ) {
				$cmp = strcmp( $a['start'], $b['start'] );
				return 'ASC' === $order ? $cmp : -$cmp;
			}
		);

		$this->posts = array_column( $matched, 'post' );

		if ( $limit > 0 ) {
			$this->posts = array_slice( $this->posts, 0, $limit );
		}
	}

	public function have_posts() {
		return ! empty( $this->posts );
	}
}

// --- Load classes and import the real data ----------------------------------

require_once __DIR__ . '/../eventslist/includes/class-eventslist-event.php';
require_once __DIR__ . '/../eventslist/includes/class-eventslist-importer.php';
require_once __DIR__ . '/../eventslist/includes/class-eventslist-shortcode.php';

$file = $argv[1] ?? '';
if ( '' === $file || ! is_readable( $file ) ) {
	fwrite( STDERR, "Usage: php tests/preview.php path/to/export.xml > preview.html\n" );
	exit( 1 );
}

$importer = new Eventslist_Importer( array( 'post_status' => 'publish' ) );
$result   = $importer->import_file( $file );

if ( is_wp_error( $result ) ) {
	fwrite( STDERR, 'ERROR: ' . $result->get_error_message() . "\n" );
	exit( 1 );
}

$stats = $importer->get_stats();
fwrite( STDERR, "Imported {$stats['created']} events into the in-memory store.\n" );

$css = file_get_contents( __DIR__ . '/../eventslist/assets/eventslist.css' );

$sections = array(
	'[eventslist show="upcoming" order="asc"]' => array(
		'show'  => 'upcoming',
		'order' => 'asc',
	),
	'[eventslist show="upcoming"]'             => array( 'show' => 'upcoming' ),
	'[eventslist show="past" limit="8"]'       => array(
		'show'  => 'past',
		'limit' => 8,
	),
	'[eventslist]'                             => array(),
);

echo "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\">\n";
echo "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">\n";
echo "<title>Events List preview</title>\n<style>\n";
echo "body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:52rem;margin:2rem auto;padding:0 1.25rem;color:#111;line-height:1.5}\n";
echo "h2{margin-top:3rem;padding-bottom:.4rem;border-bottom:2px solid #111}\n";
echo "code{background:#f3f4f6;padding:.15rem .35rem;border-radius:3px}\n";
echo $css;
echo "\n</style>\n</head><body>\n";
echo '<h1>Events List &mdash; shortcode preview</h1>';
echo '<p>Rendered by the plugin&rsquo;s own template code from all ' . (int) $stats['created'] . " events in the export. Today is " . esc_html( gmdate( 'j M Y' ) ) . ".</p>\n";

foreach ( $sections as $label => $atts ) {
	echo '<h2><code>' . esc_html( $label ) . "</code></h2>\n";
	echo Eventslist_Shortcode::render( $atts );
}

echo "</body></html>\n";
