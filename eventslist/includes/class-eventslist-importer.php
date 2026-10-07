<?php
/**
 * Imports events from a WordPress export (WXR) file.
 *
 * @package eventslist
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parses a WXR file and creates or updates eventslist posts from it.
 */
class Eventslist_Importer {

	/**
	 * Post types in the export that are treated as events.
	 *
	 * @var string[]
	 */
	const SOURCE_POST_TYPES = array( 'el_events', 'eventslist', 'event', 'events', 'tribe_events' );

	/**
	 * Legacy meta key => logical event field.
	 *
	 * @var array<string,string>
	 */
	const META_MAP = array(
		'startdate'  => 'start_date',
		'start_date' => 'start_date',
		'enddate'    => 'end_date',
		'end_date'   => 'end_date',
		'starttime'  => 'start_time',
		'start_time' => 'start_time',
		'location'   => 'location',
		'event_url'  => 'url',
		'url'        => 'url',
	);

	/**
	 * Running totals for the current run.
	 *
	 * @var array<string,int>
	 */
	protected $stats = array(
		'found'    => 0,
		'created'  => 0,
		'updated'  => 0,
		'skipped'  => 0,
		'failed'   => 0,
	);

	/**
	 * Per-event notes, for the preview table and the result report.
	 *
	 * @var array<int,array>
	 */
	protected $rows = array();

	/**
	 * Whether to parse and report without writing anything.
	 *
	 * @var bool
	 */
	protected $dry_run = false;

	/**
	 * What to do when an event already exists: update or skip.
	 *
	 * @var string
	 */
	protected $on_duplicate = 'update';

	/**
	 * Post status to give imported events.
	 *
	 * @var string
	 */
	protected $post_status = 'preserve';

	/**
	 * Sets options for the run.
	 *
	 * @param array $options dry_run, on_duplicate, post_status.
	 */
	public function __construct( array $options = array() ) {
		if ( isset( $options['dry_run'] ) ) {
			$this->dry_run = (bool) $options['dry_run'];
		}
		if ( isset( $options['on_duplicate'] ) && in_array( $options['on_duplicate'], array( 'update', 'skip' ), true ) ) {
			$this->on_duplicate = $options['on_duplicate'];
		}
		if ( isset( $options['post_status'] ) && in_array( $options['post_status'], array( 'preserve', 'publish', 'draft' ), true ) ) {
			$this->post_status = $options['post_status'];
		}
	}

	/**
	 * Runs the import against a file on disk.
	 *
	 * @param string $path Absolute path to a WXR file.
	 * @return WP_Error|array Stats array on success.
	 */
	public function import_file( $path ) {
		if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
			return new WP_Error(
				'eventslist_unreadable_file',
				__( 'That file could not be read. Check the path and the file permissions.', 'eventslist' )
			);
		}

		if ( ! class_exists( 'XMLReader' ) ) {
			return new WP_Error(
				'eventslist_no_xmlreader',
				__( 'The XMLReader PHP extension is required to import. Please ask your host to enable php-xml.', 'eventslist' )
			);
		}

		$reader = new XMLReader();

		// LIBXML_NONET blocks network access while parsing.
		$opened = $reader->open( $path, null, LIBXML_NONET );
		if ( ! $opened ) {
			return new WP_Error(
				'eventslist_parse_failed',
				__( 'That file could not be opened as XML.', 'eventslist' )
			);
		}

		$previous_errors = libxml_use_internal_errors( true );

		// Stream <item> elements one at a time so memory use stays flat
		// regardless of how large the export is.
		while ( $reader->read() ) {
			if ( XMLReader::ELEMENT !== $reader->nodeType || 'item' !== $reader->name ) {
				continue;
			}

			$xml = $reader->readOuterXml();
			if ( '' === $xml ) {
				continue;
			}

			$item = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET );
			if ( false === $item ) {
				libxml_clear_errors();
				continue;
			}

			$this->process_item( $item );
		}

		$reader->close();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_errors );

		return $this->stats;
	}

	/**
	 * Handles a single <item> from the export.
	 *
	 * @param SimpleXMLElement $item Export item.
	 */
	protected function process_item( $item ) {
		$wp = $item->children( 'http://wordpress.org/export/1.2/' );

		$post_type = isset( $wp->post_type ) ? (string) $wp->post_type : '';
		if ( ! in_array( $post_type, self::SOURCE_POST_TYPES, true ) ) {
			return;
		}

		$status = isset( $wp->status ) ? (string) $wp->status : 'publish';
		if ( in_array( $status, array( 'auto-draft', 'trash' ), true ) ) {
			return;
		}

		++$this->stats['found'];

		$content_ns = $item->children( 'http://purl.org/rss/1.0/modules/content/' );

		$title    = trim( (string) $item->title );
		$content  = isset( $content_ns->encoded ) ? (string) $content_ns->encoded : '';
		$guid     = trim( (string) $item->guid );
		$slug     = isset( $wp->post_name ) ? (string) $wp->post_name : '';
		$date     = isset( $wp->post_date ) ? (string) $wp->post_date : '';
		$date_gmt = isset( $wp->post_date_gmt ) ? (string) $wp->post_date_gmt : '';
		$source_id = isset( $wp->post_id ) ? (string) $wp->post_id : '';

		$meta = $this->collect_meta( $wp );

		// Pull the trailing call-to-action link out of the content so it can be
		// edited as a proper field and rendered consistently.
		$extracted = self::extract_link( $content );

		$fields = array(
			'start_date' => Eventslist_Event::sanitize_date( isset( $meta['start_date'] ) ? $meta['start_date'] : '' ),
			'end_date'   => Eventslist_Event::sanitize_date( isset( $meta['end_date'] ) ? $meta['end_date'] : '' ),
			'start_time' => isset( $meta['start_time'] ) ? self::clean_text( $meta['start_time'] ) : '',
			'location'   => isset( $meta['location'] ) ? self::clean_text( $meta['location'] ) : '',
			'url'        => isset( $meta['url'] ) && '' !== $meta['url'] ? $meta['url'] : $extracted['url'],
			'link_text'  => $extracted['text'],
		);

		// Fall back to the post date when the export has no start date, so the
		// event still sorts into the list instead of disappearing.
		if ( '' === $fields['start_date'] && '' !== $date ) {
			$fields['start_date'] = Eventslist_Event::sanitize_date( substr( $date, 0, 10 ) );
		}

		// A single-day event: keep the end date equal to the start date so the
		// upcoming / past filter has something to compare.
		if ( '' === $fields['end_date'] || $fields['end_date'] < $fields['start_date'] ) {
			$fields['end_date'] = $fields['start_date'];
		}

		if ( '' === $title && '' === $fields['start_date'] ) {
			++$this->stats['skipped'];
			$this->add_row( $title, $fields, 'skipped', __( 'No title and no date.', 'eventslist' ) );
			return;
		}

		$existing = $this->find_existing( $guid, $title, $fields['start_date'] );

		if ( $existing && 'skip' === $this->on_duplicate ) {
			++$this->stats['skipped'];
			$this->add_row( $title, $fields, 'skipped', __( 'Already imported.', 'eventslist' ) );
			return;
		}

		$action = $existing ? 'updated' : 'created';

		if ( $this->dry_run ) {
			++$this->stats[ $action ];
			$this->add_row( $title, $fields, $action, '' );
			return;
		}

		$postarr = array(
			'post_type'    => EVENTSLIST_POST_TYPE,
			'post_title'   => $title,
			'post_content' => $extracted['content'],
			'post_status'  => 'preserve' === $this->post_status ? $this->map_status( $status ) : $this->post_status,
			'post_name'    => $slug,
		);

		if ( '' !== $date ) {
			$postarr['post_date'] = $date;
		}
		if ( '' !== $date_gmt && '0000-00-00 00:00:00' !== $date_gmt ) {
			$postarr['post_date_gmt'] = $date_gmt;
		}

		if ( $existing ) {
			$postarr['ID'] = $existing;
			// Do not fight an editor who has already renamed the slug.
			unset( $postarr['post_name'] );
		}

		$post_id = wp_insert_post( wp_slash( $postarr ), true );

		if ( is_wp_error( $post_id ) ) {
			++$this->stats['failed'];
			$this->add_row( $title, $fields, 'failed', $post_id->get_error_message() );
			return;
		}

		// The meta API unslashes what it is given, so values go in slashed.
		foreach ( $fields as $field => $value ) {
			$key = Eventslist_Event::meta_key( $field );
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, wp_slash( $value ) );
			}
		}

		if ( '' !== $guid ) {
			update_post_meta( $post_id, 'eventslist_source_guid', wp_slash( $guid ) );
		}
		if ( '' !== $source_id ) {
			update_post_meta( $post_id, 'eventslist_source_id', wp_slash( $source_id ) );
		}

		// Keep the untouched original content so nothing from the export is
		// lost if the link extraction ever needs revisiting.
		if ( '' !== $content && $content !== $extracted['content'] ) {
			update_post_meta( $post_id, 'eventslist_source_content', wp_slash( $content ) );
		}

		++$this->stats[ $action ];
		$this->add_row( $title, $fields, $action, '' );
	}

	/**
	 * Reads the postmeta from an export item and maps it onto event fields.
	 *
	 * @param SimpleXMLElement $wp The wp: namespaced children of an item.
	 * @return array<string,string>
	 */
	protected function collect_meta( $wp ) {
		$meta = array();

		if ( ! isset( $wp->postmeta ) ) {
			return $meta;
		}

		foreach ( $wp->postmeta as $postmeta ) {
			$key = isset( $postmeta->meta_key ) ? (string) $postmeta->meta_key : '';
			if ( '' === $key || ! isset( self::META_MAP[ $key ] ) ) {
				continue;
			}

			$value = isset( $postmeta->meta_value ) ? (string) $postmeta->meta_value : '';
			$field = self::META_MAP[ $key ];

			// Earlier keys win, so "startdate" is not clobbered by a later
			// "start_date" that happens to be empty.
			if ( ! isset( $meta[ $field ] ) || '' === $meta[ $field ] ) {
				$meta[ $field ] = $value;
			}
		}

		return $meta;
	}

	/**
	 * Finds an already-imported event.
	 *
	 * Matches first on the source GUID, which is exact and survives retitling,
	 * then falls back to an identical title on the same start date so running
	 * the importer twice does not produce duplicates.
	 *
	 * @param string $guid       Source GUID.
	 * @param string $title      Event title.
	 * @param string $start_date Start date (Y-m-d).
	 * @return int Post ID, or 0 when there is no match.
	 */
	protected function find_existing( $guid, $title, $start_date ) {
		if ( '' !== $guid ) {
			$by_guid = get_posts(
				array(
					'post_type'              => EVENTSLIST_POST_TYPE,
					'post_status'            => 'any',
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_key'               => 'eventslist_source_guid',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'             => $guid,
				)
			);

			if ( ! empty( $by_guid ) ) {
				return (int) $by_guid[0];
			}
		}

		if ( '' === $title || '' === $start_date ) {
			return 0;
		}

		$by_title = get_posts(
			array(
				'post_type'              => EVENTSLIST_POST_TYPE,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'title'                  => $title,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'             => array(
					array(
						'key'     => Eventslist_Event::meta_key( 'start_date' ),
						'value'   => $start_date,
						'compare' => '=',
					),
				),
			)
		);

		return empty( $by_title ) ? 0 : (int) $by_title[0];
	}

	/**
	 * Maps an exported post status onto one we are willing to create.
	 *
	 * @param string $status Exported status.
	 * @return string
	 */
	protected function map_status( $status ) {
		$allowed = array( 'publish', 'draft', 'pending', 'private', 'future' );
		return in_array( $status, $allowed, true ) ? $status : 'draft';
	}

	/**
	 * Separates the call-to-action link from an event description.
	 *
	 * The old plugin had no link field, so editors put the registration link
	 * inside the content, almost always as the last anchor: "Register Now >",
	 * "Learn More >>", "Watch On Demand >". The *last* anchor is taken so that
	 * when a description also links a session title inline, that inline link
	 * stays in the text and only the call to action is lifted out.
	 *
	 * @param string $content Raw post content.
	 * @return array{url:string,text:string,content:string}
	 */
	public static function extract_link( $content ) {
		$result = array(
			'url'     => '',
			'text'    => '',
			'content' => (string) $content,
		);

		if ( '' === trim( (string) $content ) ) {
			$result['content'] = '';
			return $result;
		}

		$pattern = '#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is';

		if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			$result['content'] = self::tidy_content( $content );
			return $result;
		}

		$last = $matches[ count( $matches ) - 1 ];

		$url  = html_entity_decode( $last[2][0], ENT_QUOTES, 'UTF-8' );
		$text = self::clean_link_text( $last[3][0] );

		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			$result['content'] = self::tidy_content( $content );
			return $result;
		}

		$result['url']  = $url;
		$result['text'] = $text;

		// Remove just that one anchor, by offset, so identical markup earlier
		// in the content is left alone.
		$offset  = $last[0][1];
		$length  = strlen( $last[0][0] );
		$content = substr( $content, 0, $offset ) . substr( $content, $offset + $length );

		$result['content'] = self::tidy_content( $content );

		return $result;
	}

	/**
	 * Turns anchor inner HTML into plain link text, dropping trailing arrows.
	 *
	 * @param string $html Anchor inner HTML.
	 * @return string
	 */
	protected static function clean_link_text( $html ) {
		$text = wp_strip_all_tags( (string) $html );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = str_replace( "\xc2\xa0", ' ', $text );

		// Strip the trailing ">", ">>" or arrow characters the old entries used;
		// the template supplies its own arrow.
		$text = preg_replace( '/[\s>\x{00BB}\x{2192}\x{25B8}\x{203A}]+$/u', '', $text );

		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	/**
	 * Cleans up content left behind after an anchor was removed.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	protected static function tidy_content( $content ) {
		$content = str_replace( array( '&nbsp;', "\xc2\xa0" ), ' ', (string) $content );

		// Empty wrappers and dangling breaks.
		$content = preg_replace( '#<(p|strong|em|span)[^>]*>(?:\s|<br\s*/?>)*</\1>#i', '', $content );
		$content = preg_replace( '#(?:<br\s*/?>\s*)+$#i', '', $content );

		// Collapse the run of blank lines that removing a link tends to leave.
		$content = preg_replace( "/(\r?\n){3,}/", "\n\n", (string) $content );
		$content = trim( (string) $content );

		/*
		 * What is left is sometimes only the label that introduced the link --
		 * "Session:", "Listen here:", "<strong>Webinar:</strong>". Text ending
		 * in a colon is always a dangling label rather than a description, and
		 * the link now has its own field, so drop it.
		 */
		$plain = trim( html_entity_decode( wp_strip_all_tags( $content ), ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $plain || ':' === substr( $plain, -1 ) ) {
			return '';
		}

		return $content;
	}

	/**
	 * Normalises a short free-text value from the export.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	protected static function clean_text( $value ) {
		$value = str_replace( array( '&nbsp;', "\xc2\xa0" ), ' ', (string) $value );
		$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		$value = preg_replace( '/\s+/u', ' ', $value );
		return sanitize_text_field( trim( (string) $value ) );
	}

	/**
	 * Records a row for the report.
	 *
	 * @param string $title  Event title.
	 * @param array  $fields Mapped fields.
	 * @param string $action created | updated | skipped | failed.
	 * @param string $note   Optional explanation.
	 */
	protected function add_row( $title, $fields, $action, $note = '' ) {
		$this->rows[] = array(
			'title'  => $title,
			'date'   => isset( $fields['start_date'] ) ? $fields['start_date'] : '',
			'end'    => isset( $fields['end_date'] ) ? $fields['end_date'] : '',
			'time'   => isset( $fields['start_time'] ) ? $fields['start_time'] : '',
			'loc'    => isset( $fields['location'] ) ? $fields['location'] : '',
			'url'    => isset( $fields['url'] ) ? $fields['url'] : '',
			'action' => $action,
			'note'   => $note,
		);
	}

	/**
	 * The per-event report rows, most recent event first.
	 *
	 * @return array<int,array>
	 */
	public function get_rows() {
		$rows = $this->rows;

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcmp( $b['date'], $a['date'] );
			}
		);

		return $rows;
	}

	/**
	 * The totals for the run.
	 *
	 * @return array<string,int>
	 */
	public function get_stats() {
		return $this->stats;
	}
}
