<?php
/**
 * Import runner.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates a synchronous Day One import run.
 */
class Day_One_Importer_Runner {
	/**
	 * Import schema version stored on posts.
	 *
	 * @var string
	 */
	const IMPORT_SCHEMA_VERSION = '2';

	/**
	 * Run import for an uploaded ZIP file.
	 *
	 * @param array<string,mixed> $file Upload file array.
	 * @return Day_One_Importer_Results
	 */
	public function run_upload( $file ) {
		$results     = new Day_One_Importer_Results();
		$run_dir     = '';
		$zip_path    = '';
		$extract_dir = '';

		try {
			$run_dir = Day_One_Importer_Cleanup::create_run_directory();
			if ( ! $run_dir ) {
				$results->add_error( __( 'A protected temporary directory could not be created.', 'day-one-importer' ) );
				return $results;
			}

			$zip_path = $this->handle_upload( $file, $run_dir, $results );
			if ( ! $zip_path ) {
				return $results;
			}

			$preflight = Day_One_Importer_Cleanup::preflight_zip( $zip_path );
			if ( true !== $preflight ) {
				$results->add_error( $preflight );
				return $results;
			}

			$extract_dir = trailingslashit( $run_dir ) . 'extract';
			wp_mkdir_p( $extract_dir );
			Day_One_Importer_Cleanup::protect_directory( $extract_dir );

			$unzipped = $this->unzip( $zip_path, $extract_dir );
			if ( is_wp_error( $unzipped ) ) {
				$results->add_error( Day_One_Importer_Results::error_to_message( $unzipped, __( 'The ZIP archive could not be extracted.', 'day-one-importer' ) ) );
				return $results;
			}

			@unlink( $zip_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			$zip_path = '';

			$tree_valid = Day_One_Importer_Cleanup::validate_extracted_tree( $extract_dir );
			if ( true !== $tree_valid ) {
				$results->add_error( $tree_valid );
				return $results;
			}

			$parser  = new Day_One_Importer_Parser();
			$entries = $parser->parse_export( $extract_dir, $results );
			if ( 0 === $results->get_count( 'json_files_found' ) ) {
				$results->add_error( __( 'No Day One journal JSON file with an entries array was found in the archive.', 'day-one-importer' ) );
				return $results;
			}
				if ( empty( $entries ) ) {
				$results->add_error( __( 'No importable Day One entries were found in the archive.', 'day-one-importer' ) );
				return $results;
			}

			foreach ( $entries as $entry ) {
				$this->import_entry( $entry, $extract_dir, $results );
			}
		} catch ( Exception $e ) {
			$results->add_error( __( 'The import stopped because of an unexpected error.', 'day-one-importer' ) );
		} finally {
			if ( $zip_path ) {
				@unlink( $zip_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			if ( $run_dir ) {
				Day_One_Importer_Cleanup::remove( $run_dir );
			}
		}

		return $results;
	}

	/**
	 * Validate and move uploaded ZIP into the protected run directory.
	 *
	 * @param array<string,mixed>      $file Upload file array.
	 * @param string                   $run_dir Run directory.
	 * @param Day_One_Importer_Results $results Results.
	 * @return string Empty on failure.
	 */
	private function handle_upload( $file, $run_dir, Day_One_Importer_Results $results ) {
		if ( empty( $file ) || empty( $file['tmp_name'] ) || ! isset( $file['error'] ) ) {
			$results->add_error( __( 'No ZIP file was uploaded.', 'day-one-importer' ) );
			return '';
		}

		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$results->add_error(
				sprintf(
					/* translators: %d: PHP file upload error code. */
					__( 'The upload failed with PHP upload error code %d.', 'day-one-importer' ),
					(int) $file['error']
				)
			);
			return '';
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		if ( 'zip' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			$results->add_error( __( 'Only Day One ZIP exports are supported.', 'day-one-importer' ) );
			return '';
		}

		$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		if ( ! is_uploaded_file( $tmp_name ) ) {
			$results->add_error( __( 'The uploaded ZIP file could not be verified.', 'day-one-importer' ) );
			return '';
		}

		$zip_type = wp_check_filetype( $name, array( 'zip' => 'application/zip' ) );
		if ( 'zip' !== strtolower( (string) $zip_type['ext'] ) ) {
			$results->add_error( __( 'Only Day One ZIP exports are supported.', 'day-one-importer' ) );
			return '';
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload_dir_filter = static function ( $dirs ) use ( $run_dir ) {
			$run_dir = untrailingslashit( $run_dir );

			$dirs['path']    = $run_dir;
			$dirs['basedir'] = $run_dir;
			$dirs['subdir']  = '';
			$dirs['url']     = '';
			$dirs['baseurl'] = '';
			$dirs['error']   = false;

			return $dirs;
		};

		add_filter( 'upload_dir', $upload_dir_filter );
		$uploaded = wp_handle_upload(
			$file,
			array(
				'test_form'                => false,
				'mimes'                    => array( 'zip' => 'application/zip' ),
				'unique_filename_callback' => static function ( $dir, $name, $ext ) {
					return 'day-one-export.zip';
				},
			)
		);
		remove_filter( 'upload_dir', $upload_dir_filter );

		if ( empty( $uploaded['file'] ) || ! empty( $uploaded['error'] ) ) {
			$results->add_error( __( 'The uploaded ZIP file could not be moved into the protected import directory.', 'day-one-importer' ) );
			return '';
		}

		$target = (string) $uploaded['file'];
		@chmod( $target, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		return $target;
	}

	/**
	 * Extract ZIP archive.
	 *
	 * @param string $zip_path Zip path.
	 * @param string $extract_dir Extract directory.
	 * @return true|WP_Error
	 */
	private function unzip( $zip_path, $extract_dir ) {
		if ( ! function_exists( 'unzip_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}

		return unzip_file( $zip_path, $extract_dir );
	}

	/**
	 * Import or resume one normalized entry.
	 *
	 * @param array<string,mixed>      $entry Entry.
	 * @param string                   $extract_dir Extraction root.
	 * @param Day_One_Importer_Results $results Results.
	 * @return void
	 */
	private function import_entry( $entry, $extract_dir, Day_One_Importer_Results $results ) {
		$uuid = isset( $entry['uuid'] ) ? (string) $entry['uuid'] : '';
		if ( '' === $uuid ) {
			$results->increment( 'entries_failed' );
			return;
		}

		$existing_post_id = $this->find_existing_post_id( $uuid, $results );
		if ( $existing_post_id ) {
			if ( 'trash' === get_post_status( $existing_post_id ) ) {
				wp_delete_post( $existing_post_id, true );
				$existing_post_id = 0;
			} else {
				$complete = get_post_meta( $existing_post_id, '_day_one_import_complete', true );
				$version  = get_post_meta( $existing_post_id, '_day_one_import_version', true );
				if ( '1' === (string) $complete && self::IMPORT_SCHEMA_VERSION === (string) $version ) {
					$results->increment( 'posts_skipped' );
					return;
				}

				$post_id = $existing_post_id;
				$results->increment( 'posts_resumed' );
			}
		}

		if ( ! $existing_post_id ) {
			$post_id = 0;
		}

		$creation = Day_One_Importer_Content::parse_day_one_date( isset( $entry['creationDate'] ) ? $entry['creationDate'] : '' );
		$modified = Day_One_Importer_Content::parse_day_one_date( isset( $entry['modifiedDate'] ) ? $entry['modifiedDate'] : '' );
		if ( ! $creation['valid'] ) {
			$results->add_warning(
				sprintf(
					/* translators: %s: Day One entry UUID. */
					__( 'Invalid creation date for UUID %s; WordPress current time was used.', 'day-one-importer' ),
					$uuid
				)
			);
		}

		$content = Day_One_Importer_Content::convert_text_to_content( isset( $entry['text'] ) ? $entry['text'] : '' );
		$title   = Day_One_Importer_Content::derive_title( isset( $entry['text'] ) ? $entry['text'] : '', $creation['gmt'] );

		$postarr = array(
			'post_type'    => 'post',
			'post_title'   => $title,
			'post_content' => $content,
		);

		if ( $creation['valid'] ) {
			$postarr['post_date_gmt'] = $creation['gmt'];
			$postarr['post_date']     = $creation['local'];
		}
		if ( $modified['valid'] ) {
			$postarr['post_modified_gmt'] = $modified['gmt'];
			$postarr['post_modified']     = $modified['local'];
		}

		if ( $post_id ) {
			$postarr['ID'] = $post_id;
			$updated       = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $updated ) ) {
				$results->increment( 'entries_failed' );
				$results->add_warning(
					sprintf(
						/* translators: %s: Day One entry UUID. */
						__( 'Could not resume post for UUID %s.', 'day-one-importer' ),
						$uuid
					)
				);
				return;
			}
		} else {
			$postarr['post_status'] = 'private';
			$post_id                 = wp_insert_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $post_id ) || ! $post_id ) {
				$results->increment( 'entries_failed' );
				$results->add_warning(
					sprintf(
						/* translators: %s: Day One entry UUID. */
						__( 'Could not create private post for UUID %s.', 'day-one-importer' ),
						$uuid
					)
				);
				return;
			}

			update_post_meta( $post_id, '_day_one_uuid', $uuid );
			update_post_meta( $post_id, '_day_one_source', 'day-one-export' );
			update_post_meta( $post_id, '_day_one_import_version', self::IMPORT_SCHEMA_VERSION );
			update_post_meta( $post_id, '_day_one_import_complete', '0' );
			update_post_meta( $post_id, '_day_one_import_started_at', current_time( 'mysql', true ) );
			$results->increment( 'posts_created' );
		}

		$this->update_entry_meta( $post_id, $entry );
		$this->assign_tags( $post_id, $entry, $uuid, $results );

		$media          = new Day_One_Importer_Media( $extract_dir, $results );
		$attachment_ids = $media->import_entry_photos( $entry, $post_id );
		if ( ! empty( $attachment_ids ) ) {
			$content_with_media = Day_One_Importer_Content::append_image_section( $content, $attachment_ids );
			$updated            = wp_update_post(
				array(
					'ID'           => $post_id,
					'post_content' => wp_slash( $content_with_media ),
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				$results->add_warning(
					sprintf(
						/* translators: %s: Day One entry UUID. */
						__( 'Could not append imported media to post content for UUID %s.', 'day-one-importer' ),
						$uuid
					)
				);
			}
		}

		update_post_meta( $post_id, '_day_one_import_version', self::IMPORT_SCHEMA_VERSION );
		update_post_meta( $post_id, '_day_one_import_complete', '1' );
		update_post_meta( $post_id, '_day_one_import_completed_at', current_time( 'mysql', true ) );
	}

	/**
	 * Find existing imported post by UUID.
	 *
	 * @param string                   $uuid UUID.
	 * @param Day_One_Importer_Results $results Results.
	 * @return int Post ID or 0.
	 */
	private function find_existing_post_id( $uuid, Day_One_Importer_Results $results ) {
		$statuses = get_post_stati( array(), 'names' );
		$ids      = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array_values( $statuses ),
				'fields'         => 'ids',
				'posts_per_page' => 2,
				'no_found_rows'  => true,
				'meta_key'       => '_day_one_uuid', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $uuid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( count( $ids ) > 1 ) {
			$results->add_warning(
				sprintf(
					/* translators: %s: Day One entry UUID. */
					__( 'Multiple existing posts found for UUID %s; the first one was used.', 'day-one-importer' ),
					$uuid
				)
			);
		}

		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Store safe Day One metadata.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $entry Entry.
	 * @return void
	 */
	private function update_entry_meta( $post_id, $entry ) {
		update_post_meta( $post_id, '_day_one_uuid', (string) $entry['uuid'] );
		update_post_meta( $post_id, '_day_one_source', 'day-one-export' );
		update_post_meta( $post_id, '_day_one_time_zone', isset( $entry['timeZone'] ) ? day_one_importer_sanitize_text( $entry['timeZone'] ) : '' );
		update_post_meta( $post_id, '_day_one_starred', ! empty( $entry['starred'] ) ? '1' : '0' );
		update_post_meta( $post_id, '_day_one_is_pinned', ! empty( $entry['isPinned'] ) ? '1' : '0' );
		if ( ! empty( $entry['source_file'] ) ) {
			update_post_meta( $post_id, '_day_one_source_file', day_one_importer_sanitize_text( $entry['source_file'] ) );
		}
		if ( ! empty( $entry['creationDeviceType'] ) ) {
			update_post_meta( $post_id, '_day_one_creation_device_type', day_one_importer_sanitize_text( $entry['creationDeviceType'] ) );
		}
		if ( ! empty( $entry['creationDeviceModel'] ) ) {
			update_post_meta( $post_id, '_day_one_creation_device_model', day_one_importer_sanitize_text( $entry['creationDeviceModel'] ) );
		}
	}

	/**
	 * Assign tags.
	 *
	 * @param int                       $post_id Post ID.
	 * @param array<string,mixed>       $entry Entry.
	 * @param string                    $uuid UUID.
	 * @param Day_One_Importer_Results  $results Results.
	 * @return void
	 */
	private function assign_tags( $post_id, $entry, $uuid, Day_One_Importer_Results $results ) {
		$tags = Day_One_Importer_Content::normalize_tags( isset( $entry['tags'] ) ? $entry['tags'] : array() );
		if ( empty( $tags ) ) {
			return;
		}

		$set = wp_set_post_tags( $post_id, $tags, false );
		if ( is_wp_error( $set ) ) {
			$results->add_warning(
				sprintf(
					/* translators: %s: Day One entry UUID. */
					__( 'Tags could not be assigned for UUID %s.', 'day-one-importer' ),
					$uuid
				)
			);
			return;
		}

		$results->increment( 'tags_assigned' );
	}
}
