<?php
/**
 * Plugin Name:       Events List
 * Description:       A lightweight events manager: an "eventslist" custom post type, an importer for legacy event data from a WordPress export (WXR) file, and an [eventslist] shortcode that renders events in a two-column date / details layout.
 * Version:           1.0.2
 * Author:            Britt Andreatta
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       eventslist
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'EVENTSLIST_VERSION', '1.0.2' );
define( 'EVENTSLIST_FILE', __FILE__ );
define( 'EVENTSLIST_DIR', plugin_dir_path( __FILE__ ) );
define( 'EVENTSLIST_URL', plugin_dir_url( __FILE__ ) );

/** The custom post type key. */
define( 'EVENTSLIST_POST_TYPE', 'eventslist' );

require_once EVENTSLIST_DIR . 'includes/class-eventslist-event.php';
require_once EVENTSLIST_DIR . 'includes/class-eventslist-post-type.php';
require_once EVENTSLIST_DIR . 'includes/class-eventslist-meta-box.php';
require_once EVENTSLIST_DIR . 'includes/class-eventslist-shortcode.php';
require_once EVENTSLIST_DIR . 'includes/class-eventslist-importer.php';
require_once EVENTSLIST_DIR . 'includes/class-eventslist-admin.php';

/**
 * Boot the plugin.
 */
function eventslist_init() {
	Eventslist_Post_Type::init();
	Eventslist_Meta_Box::init();
	Eventslist_Shortcode::init();
	Eventslist_Admin::init();
}
add_action( 'plugins_loaded', 'eventslist_init' );

/*
 * The post type is registered on `init` directly (not inside plugins_loaded) so
 * that it exists early enough for rewrite rules and the REST API.
 */
add_action( 'init', array( 'Eventslist_Post_Type', 'register' ) );
add_action( 'init', array( 'Eventslist_Post_Type', 'register_meta' ) );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once EVENTSLIST_DIR . 'includes/class-eventslist-cli.php';
}

register_activation_hook( __FILE__, array( 'Eventslist_Post_Type', 'activate' ) );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );
