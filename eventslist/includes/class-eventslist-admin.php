<?php
/**
 * Admin screens: the importer page, list columns and the shortcode helper.
 *
 * @package eventslist
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin integration.
 */
class Eventslist_Admin {

	const PAGE_SLUG    = 'eventslist-import';
	const NONCE_ACTION = 'eventslist_import';
	const NONCE_NAME   = 'eventslist_import_nonce';

	/**
	 * Results of an import performed on this request.
	 *
	 * @var array|null
	 */
	protected static $result = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_import_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_import' ) );

		add_filter( 'manage_' . EVENTSLIST_POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . EVENTSLIST_POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_filter( 'manage_edit-' . EVENTSLIST_POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'default_admin_order' ) );
	}

	/**
	 * Adds the import screen under the Events menu.
	 */
	public static function add_import_page() {
		add_submenu_page(
			'edit.php?post_type=' . EVENTSLIST_POST_TYPE,
			__( 'Import Events', 'eventslist' ),
			__( 'Import', 'eventslist' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_import_page' )
		);
	}

	/**
	 * Processes a submitted import form.
	 */
	public static function handle_import() {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import events.', 'eventslist' ) );
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'That import link has expired. Please try again.', 'eventslist' ) );
		}

		$dry_run      = isset( $_POST['eventslist_dry_run'] ) && '1' === $_POST['eventslist_dry_run'];
		$on_duplicate = isset( $_POST['eventslist_on_duplicate'] ) && 'skip' === $_POST['eventslist_on_duplicate'] ? 'skip' : 'update';
		$post_status  = isset( $_POST['eventslist_post_status'] ) ? sanitize_key( wp_unslash( $_POST['eventslist_post_status'] ) ) : 'preserve';

		$path = self::resolve_source_file();

		if ( is_wp_error( $path ) ) {
			self::$result = array( 'error' => $path );
			return;
		}

		$importer = new Eventslist_Importer(
			array(
				'dry_run'      => $dry_run,
				'on_duplicate' => $on_duplicate,
				'post_status'  => $post_status,
			)
		);

		$outcome = $importer->import_file( $path );

		if ( is_wp_error( $outcome ) ) {
			self::$result = array( 'error' => $outcome );
			return;
		}

		self::$result = array(
			'stats'   => $importer->get_stats(),
			'rows'    => $importer->get_rows(),
			'dry_run' => $dry_run,
		);
	}

	/**
	 * Works out which file to read: an upload, or a path on the server.
	 *
	 * @return string|WP_Error Absolute path, or an error.
	 */
	protected static function resolve_source_file() {
		// An uploaded file takes precedence.
		if ( ! empty( $_FILES['eventslist_file']['name'] ) ) {
			$file = $_FILES['eventslist_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

			if ( ! empty( $file['error'] ) ) {
				return new WP_Error(
					'eventslist_upload_error',
					sprintf(
						/* translators: %d: PHP upload error code. */
						__( 'The upload failed (error code %d). The file may be larger than this server allows; try the server path option instead.', 'eventslist' ),
						(int) $file['error']
					)
				);
			}

			$tmp = isset( $file['tmp_name'] ) ? $file['tmp_name'] : '';
			if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
				return new WP_Error( 'eventslist_upload_invalid', __( 'That upload could not be verified.', 'eventslist' ) );
			}

			$name = sanitize_file_name( $file['name'] );
			$ext  = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( ! in_array( $ext, array( 'xml', 'wxr' ), true ) ) {
				return new WP_Error( 'eventslist_upload_type', __( 'Please upload the .xml export file.', 'eventslist' ) );
			}

			return $tmp;
		}

		$raw_path = isset( $_POST['eventslist_path'] ) ? trim( (string) wp_unslash( $_POST['eventslist_path'] ) ) : '';
		if ( '' === $raw_path ) {
			return new WP_Error( 'eventslist_no_file', __( 'Choose a file to upload, or enter a path to one on the server.', 'eventslist' ) );
		}

		// Reject traversal, then confirm the file really is where it claims.
		if ( false !== strpos( $raw_path, '..' ) ) {
			return new WP_Error( 'eventslist_bad_path', __( 'That path is not allowed.', 'eventslist' ) );
		}

		$path = realpath( $raw_path );
		if ( false === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'eventslist_bad_path', __( 'No readable file was found at that path.', 'eventslist' ) );
		}

		$ext = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'xml', 'wxr' ), true ) ) {
			return new WP_Error( 'eventslist_bad_path', __( 'That path must point at an .xml export file.', 'eventslist' ) );
		}

		return $path;
	}

	/**
	 * Renders the import screen.
	 */
	public static function render_import_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$upload_dir = wp_get_upload_dir();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Events', 'eventslist' ); ?></h1>

			<p class="description" style="max-width:48em;">
				<?php esc_html_e( 'Reads a WordPress export (WXR) file and turns its events into Events List entries. Events are matched on their original ID, so you can run this more than once without creating duplicates. Start with a dry run to see exactly what would happen.', 'eventslist' ); ?>
			</p>

			<?php self::render_result(); ?>

			<form method="post" enctype="multipart/form-data" action="">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="eventslist_file"><?php esc_html_e( 'Export file', 'eventslist' ); ?></label>
							</th>
							<td>
								<input type="file" id="eventslist_file" name="eventslist_file" accept=".xml,.wxr" />
								<p class="description">
									<?php esc_html_e( 'The .xml file produced by Tools > Export.', 'eventslist' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="eventslist_path"><?php esc_html_e( 'Or a path on the server', 'eventslist' ); ?></label>
							</th>
							<td>
								<input type="text" class="large-text code" id="eventslist_path" name="eventslist_path"
									placeholder="<?php echo esc_attr( trailingslashit( $upload_dir['basedir'] ) . 'export.xml' ); ?>" />
								<p class="description">
									<?php esc_html_e( 'Useful when the file is too large for a browser upload. Upload it to the server first, then paste the full path here.', 'eventslist' ); ?>
								</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><?php esc_html_e( 'Existing events', 'eventslist' ); ?></th>
							<td>
								<fieldset>
									<legend class="screen-reader-text"><?php esc_html_e( 'Existing events', 'eventslist' ); ?></legend>
									<label>
										<input type="radio" name="eventslist_on_duplicate" value="update" checked="checked" />
										<?php esc_html_e( 'Update them with the values from the file', 'eventslist' ); ?>
									</label><br />
									<label>
										<input type="radio" name="eventslist_on_duplicate" value="skip" />
										<?php esc_html_e( 'Leave them alone and skip', 'eventslist' ); ?>
									</label>
								</fieldset>
							</td>
						</tr>

						<tr>
							<th scope="row">
								<label for="eventslist_post_status"><?php esc_html_e( 'Status', 'eventslist' ); ?></label>
							</th>
							<td>
								<select id="eventslist_post_status" name="eventslist_post_status">
									<option value="preserve"><?php esc_html_e( 'Keep the status from the file', 'eventslist' ); ?></option>
									<option value="publish"><?php esc_html_e( 'Publish everything', 'eventslist' ); ?></option>
									<option value="draft"><?php esc_html_e( 'Import everything as a draft', 'eventslist' ); ?></option>
								</select>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit">
					<button type="submit" name="eventslist_dry_run" value="1" class="button button-secondary">
						<?php esc_html_e( 'Dry run (preview only)', 'eventslist' ); ?>
					</button>
					<button type="submit" name="eventslist_dry_run" value="0" class="button button-primary">
						<?php esc_html_e( 'Run import', 'eventslist' ); ?>
					</button>
				</p>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Displaying events', 'eventslist' ); ?></h2>
			<p><?php esc_html_e( 'Put one of these shortcodes into any page or post:', 'eventslist' ); ?></p>
			<table class="widefat striped" style="max-width:52em;">
				<tbody>
					<tr>
						<td><code>[eventslist]</code></td>
						<td><?php esc_html_e( 'Every event, newest first.', 'eventslist' ); ?></td>
					</tr>
					<tr>
						<td><code>[eventslist show="upcoming"]</code></td>
						<td><?php esc_html_e( 'Only events that have not finished yet.', 'eventslist' ); ?></td>
					</tr>
					<tr>
						<td><code>[eventslist show="past"]</code></td>
						<td><?php esc_html_e( 'Only events that have already happened.', 'eventslist' ); ?></td>
					</tr>
					<tr>
						<td><code>[eventslist show="upcoming" order="asc"]</code></td>
						<td><?php esc_html_e( 'Upcoming events with the soonest one first.', 'eventslist' ); ?></td>
					</tr>
					<tr>
						<td><code>[eventslist show="past" limit="10"]</code></td>
						<td><?php esc_html_e( 'The ten most recent past events.', 'eventslist' ); ?></td>
					</tr>
					<tr>
						<td><code>[eventslist description="no"]</code></td>
						<td><?php esc_html_e( 'Hide the description, leaving the title, time, location and link.', 'eventslist' ); ?></td>
					</tr>
					<tr>
						<td><code>[eventslist year="always"]</code></td>
						<td><?php esc_html_e( 'Always show the year. Default is to show it only for events outside the current year; use year="never" to hide it.', 'eventslist' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders the outcome of an import performed on this request.
	 */
	protected static function render_result() {
		if ( null === self::$result ) {
			return;
		}

		if ( isset( self::$result['error'] ) && is_wp_error( self::$result['error'] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( self::$result['error']->get_error_message() )
			);
			return;
		}

		$stats   = self::$result['stats'];
		$rows    = self::$result['rows'];
		$dry_run = ! empty( self::$result['dry_run'] );

		$summary = sprintf(
			/* translators: 1: events found, 2: created, 3: updated, 4: skipped, 5: failed. */
			__( '%1$d events found in the file: %2$d new, %3$d updated, %4$d skipped, %5$d failed.', 'eventslist' ),
			(int) $stats['found'],
			(int) $stats['created'],
			(int) $stats['updated'],
			(int) $stats['skipped'],
			(int) $stats['failed']
		);

		printf(
			'<div class="notice %1$s"><p><strong>%2$s</strong> %3$s</p></div>',
			$dry_run ? 'notice-info' : 'notice-success',
			$dry_run ? esc_html__( 'Dry run: nothing was saved.', 'eventslist' ) : esc_html__( 'Import complete.', 'eventslist' ),
			esc_html( $summary )
		);

		if ( empty( $rows ) ) {
			return;
		}
		?>
		<h2><?php echo $dry_run ? esc_html__( 'Preview', 'eventslist' ) : esc_html__( 'What was imported', 'eventslist' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Date', 'eventslist' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Event', 'eventslist' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Time', 'eventslist' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Location', 'eventslist' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Link', 'eventslist' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'eventslist' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td>
							<?php
							$parts = Eventslist_Event::format_date_parts( $row['date'], $row['end'] );
							echo esc_html( trim( $parts['day'] . ' ' . $parts['year'] ) );
							?>
						</td>
						<td><strong><?php echo esc_html( $row['title'] ); ?></strong></td>
						<td><?php echo esc_html( Eventslist_Event::format_time( $row['time'] ) ); ?></td>
						<td><?php echo esc_html( $row['loc'] ); ?></td>
						<td>
							<?php if ( '' !== $row['url'] ) : ?>
								<span aria-hidden="true">&#10003;</span>
								<span class="screen-reader-text"><?php esc_html_e( 'Has a link', 'eventslist' ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( $row['action'] ); ?>
							<?php if ( '' !== $row['note'] ) : ?>
								<br /><span class="description"><?php echo esc_html( $row['note'] ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Replaces the default Date column with the event's own details.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		$new = array();

		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				continue;
			}
			$new[ $key ] = $label;

			if ( 'title' === $key ) {
				$new['eventslist_date']     = __( 'Event date', 'eventslist' );
				$new['eventslist_time']     = __( 'Time', 'eventslist' );
				$new['eventslist_location'] = __( 'Location', 'eventslist' );
				$new['eventslist_link']     = __( 'Link', 'eventslist' );
			}
		}

		$new['date'] = isset( $columns['date'] ) ? $columns['date'] : __( 'Published', 'eventslist' );

		return $new;
	}

	/**
	 * Prints a custom column's value.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'eventslist_date':
				$parts = Eventslist_Event::format_date_parts(
					Eventslist_Event::get( $post_id, 'start_date' ),
					Eventslist_Event::get( $post_id, 'end_date' )
				);

				if ( '' === $parts['day'] ) {
					echo '<span class="description">' . esc_html__( 'No date set', 'eventslist' ) . '</span>';
					break;
				}

				echo esc_html( trim( $parts['day'] . ' ' . $parts['year'] ) );

				$end = Eventslist_Event::effective_end_date( $post_id );
				if ( '' !== $end && $end < Eventslist_Event::today() ) {
					echo '<br /><span class="description">' . esc_html__( 'Past', 'eventslist' ) . '</span>';
				} else {
					echo '<br /><span class="description">' . esc_html__( 'Upcoming', 'eventslist' ) . '</span>';
				}
				break;

			case 'eventslist_time':
				echo esc_html( Eventslist_Event::format_time( Eventslist_Event::get( $post_id, 'start_time' ) ) );
				break;

			case 'eventslist_location':
				echo esc_html( Eventslist_Event::get( $post_id, 'location' ) );
				break;

			case 'eventslist_link':
				$url = Eventslist_Event::get( $post_id, 'url' );
				if ( '' !== $url ) {
					printf(
						'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
						esc_url( $url ),
						esc_html__( 'View', 'eventslist' )
					);
				}
				break;
		}
	}

	/**
	 * Makes the event date column sortable.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public static function sortable_columns( $columns ) {
		$columns['eventslist_date'] = 'eventslist_date';
		return $columns;
	}

	/**
	 * Sorts the events list by event date, newest first, unless the user has
	 * chosen a different sort.
	 *
	 * @param WP_Query $query Current query.
	 */
	public static function default_admin_order( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( EVENTSLIST_POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}

		$orderby = $query->get( 'orderby' );

		if ( '' !== $orderby && 'eventslist_date' !== $orderby ) {
			return;
		}

		$key   = Eventslist_Event::meta_key( 'start_date' );
		$order = $query->get( 'order' );

		if ( '' === $orderby || '' === $order ) {
			$order = 'DESC';
		}

		/*
		 * The OR / NOT EXISTS pair forces a LEFT JOIN, so an event that somehow
		 * has no start date yet still shows up in this list rather than
		 * vanishing from the admin.
		 */
		$query->set(
			'meta_query',
			array(
				'relation' => 'OR',
				'has_date' => array(
					'key'     => $key,
					'compare' => 'EXISTS',
				),
				'no_date'  => array(
					'key'     => $key,
					'compare' => 'NOT EXISTS',
				),
			)
		);

		$query->set( 'orderby', array( 'has_date' => $order ) );
	}
}
