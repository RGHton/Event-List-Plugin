<?php
/**
 * WP-CLI command for importing events.
 *
 * @package eventslist
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Imports events from a WordPress export file.
 */
class Eventslist_CLI {

	/**
	 * Imports events from a WXR export file.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the WordPress export (.xml) file.
	 *
	 * [--dry-run]
	 * : Parse and report without writing anything.
	 *
	 * [--skip-existing]
	 * : Leave already-imported events alone instead of updating them.
	 *
	 * [--status=<status>]
	 * : Status for imported events.
	 * ---
	 * default: preserve
	 * options:
	 *   - preserve
	 *   - publish
	 *   - draft
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp eventslist import export.xml --dry-run
	 *     wp eventslist import export.xml
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function import( $args, $assoc_args ) {
		$file    = isset( $args[0] ) ? $args[0] : '';
		$dry_run = ! empty( $assoc_args['dry-run'] );

		$importer = new Eventslist_Importer(
			array(
				'dry_run'      => $dry_run,
				'on_duplicate' => empty( $assoc_args['skip-existing'] ) ? 'update' : 'skip',
				'post_status'  => isset( $assoc_args['status'] ) ? $assoc_args['status'] : 'preserve',
			)
		);

		$outcome = $importer->import_file( $file );

		if ( is_wp_error( $outcome ) ) {
			WP_CLI::error( $outcome->get_error_message() );
		}

		$stats = $importer->get_stats();

		WP_CLI::log(
			sprintf(
				'Found %d events: %d new, %d updated, %d skipped, %d failed.',
				$stats['found'],
				$stats['created'],
				$stats['updated'],
				$stats['skipped'],
				$stats['failed']
			)
		);

		if ( $dry_run ) {
			$rows = $importer->get_rows();
			if ( ! empty( $rows ) ) {
				WP_CLI\Utils\format_items(
					'table',
					$rows,
					array( 'date', 'title', 'time', 'loc', 'action', 'note' )
				);
			}
			WP_CLI::success( 'Dry run complete. Nothing was saved.' );
			return;
		}

		if ( $stats['failed'] > 0 ) {
			WP_CLI::warning( sprintf( '%d events could not be imported.', $stats['failed'] ) );
		}

		WP_CLI::success( 'Import complete.' );
	}
}

WP_CLI::add_command( 'eventslist', 'Eventslist_CLI' );
