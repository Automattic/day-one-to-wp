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
	const IMPORT_SCHEMA_VERSION = '4';

	/**
	 * Run import for an uploaded ZIP file.
	 *
	 * @param array<string,mixed> $file Upload file array.
	 * @return Day_One_Importer_Results
	 */
	public function run_upload( $file ) {
		day_one_importer_prepare_long_running_import();

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

			$uploader = new Day_One_Importer_Uploader();
			$zip_path = $uploader->handle_upload( $file, $run_dir, $results );
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
				day_one_importer_prepare_long_running_import();
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
	 * Import or resume one normalized entry synchronously.
	 *
	 * @param array<string,mixed>      $entry Entry.
	 * @param string                   $extract_dir Extraction root.
	 * @param Day_One_Importer_Results $results Results.
	 * @return void
	 */
	public function import_entry( $entry, $extract_dir, Day_One_Importer_Results $results ) {
		$prepared = $this->prepare_imported_entry_post( $entry, $results );
		if ( 'ready' !== $prepared['status'] ) {
			return;
		}

		$job = array(
			'current_media_index'          => 0,
			'current_media_total'          => 0,
			'current_attachment_ids'       => array(),
			'current_entry_media_counted'  => false,
			'current_entry_media_complete' => false,
		);

		$deadline = 1.0E+30;
		while ( ! $this->import_entry_media_batch( $entry, $extract_dir, (int) $prepared['post_id'], $job, $deadline, $results ) ) {
			day_one_importer_prepare_long_running_import();
		}

		$this->finalize_imported_entry( $entry, (int) $prepared['post_id'], $job['current_attachment_ids'], $results );
	}

	/**
	 * Prepare/create/resume the WordPress post for an entry without importing media.
	 *
	 * @param array<string,mixed>      $entry Entry.
	 * @param Day_One_Importer_Results $results Results.
	 * @return array<string,mixed> status: ready, skipped, or failed.
	 */
	public function prepare_imported_entry_post( $entry, Day_One_Importer_Results $results ) {
		$uuid = isset( $entry['uuid'] ) ? (string) $entry['uuid'] : '';
		if ( '' === $uuid ) {
			$results->increment( 'entries_failed' );
			return array( 'status' => 'failed', 'post_id' => 0 );
		}

		$post_id          = 0;
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
					return array( 'status' => 'skipped', 'post_id' => (int) $existing_post_id );
				}

				$post_id = (int) $existing_post_id;
				$results->increment( 'posts_resumed' );
			}
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
				return array( 'status' => 'failed', 'post_id' => 0 );
			}
			update_post_meta( $post_id, '_day_one_import_complete', '0' );
		} else {
			$postarr['post_status'] = 'private';
			$postarr['meta_input']  = array(
				'_day_one_uuid'              => $uuid,
				'_day_one_source'            => 'day-one-export',
				'_day_one_import_version'    => self::IMPORT_SCHEMA_VERSION,
				'_day_one_import_complete'   => '0',
				'_day_one_import_started_at' => current_time( 'mysql', true ),
			);
			$post_id = wp_insert_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $post_id ) || ! $post_id ) {
				$results->increment( 'entries_failed' );
				$results->add_warning(
					sprintf(
						/* translators: %s: Day One entry UUID. */
						__( 'Could not create private post for UUID %s.', 'day-one-importer' ),
						$uuid
					)
				);
				return array( 'status' => 'failed', 'post_id' => 0 );
			}

			$results->increment( 'posts_created' );
		}

		$this->update_entry_meta( (int) $post_id, $entry );
		$this->assign_tags( (int) $post_id, $entry, $uuid, $results );

		return array(
			'status'       => 'ready',
			'post_id'      => (int) $post_id,
			'base_content' => $content,
		);
	}

	/**
	 * Import media for one entry in bounded, resumable batches.
	 *
	 * @param array<string,mixed>      $entry Entry.
	 * @param string                   $extract_dir Extraction root.
	 * @param int                      $post_id Post ID.
	 * @param array<string,mixed>      $job Job state, updated by reference.
	 * @param float                    $deadline Deadline timestamp.
	 * @param Day_One_Importer_Results $results Results.
	 * @param callable|null            $checkpoint Optional checkpoint callback.
	 * @return bool True when all media for the entry is complete.
	 */
	public function import_entry_media_batch( $entry, $extract_dir, $post_id, &$job, $deadline, Day_One_Importer_Results $results, $checkpoint = null ) {
		$photos = isset( $entry['photos'] ) && is_array( $entry['photos'] ) ? $entry['photos'] : array();
		$photos = Day_One_Importer_Media::sort_photos( $photos );
		$total  = count( $photos );
		$job['current_media_total'] = $total;

		if ( 0 === $total ) {
			$job['current_entry_media_complete'] = true;
			return true;
		}

		if ( empty( $job['current_entry_media_counted'] ) ) {
			$results->increment( 'media_found', $total );
			$job['current_entry_media_counted'] = true;
			if ( is_callable( $checkpoint ) ) {
				call_user_func_array( $checkpoint, array( &$job, $results ) );
			}
		}

		$index = isset( $job['current_media_index'] ) ? max( 0, (int) $job['current_media_index'] ) : 0;
		$ids   = isset( $job['current_attachment_ids'] ) && is_array( $job['current_attachment_ids'] ) ? array_values( array_map( 'intval', $job['current_attachment_ids'] ) ) : array();
		$limit = class_exists( 'Day_One_Importer_Job_State' ) ? Day_One_Importer_Job_State::batch_media_limit() : $total;
		$done  = 0;
		$photo_dirs = isset( $job['photo_dirs'] ) && is_array( $job['photo_dirs'] ) ? $job['photo_dirs'] : null;
		$media      = new Day_One_Importer_Media( $extract_dir, $results, $photo_dirs );

		while ( $index < $total && $done < $limit ) {
			if ( class_exists( 'Day_One_Importer_Job_State' ) && Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) ) {
				break;
			}

			day_one_importer_prepare_long_running_import();
			$attachment_id = $media->import_or_reuse_photo( $photos[ $index ], $entry, $post_id );
			if ( $attachment_id && ! in_array( (int) $attachment_id, $ids, true ) ) {
				$ids[] = (int) $attachment_id;
			}

			++$index;
			++$done;
			$job['current_media_index']    = $index;
			$job['current_attachment_ids'] = $ids;
			if ( is_callable( $checkpoint ) ) {
				call_user_func_array( $checkpoint, array( &$job, $results ) );
			}
		}

		$job['current_media_index']    = $index;
		$job['current_attachment_ids'] = $ids;
		if ( $index >= $total ) {
			$job['current_entry_media_complete'] = true;
			return true;
		}

		return false;
	}

	/**
	 * Finalize post content and import completion metadata for one entry.
	 *
	 * @param array<string,mixed>      $entry Entry.
	 * @param int                      $post_id Post ID.
	 * @param int[]                    $attachment_ids Attachment IDs.
	 * @param Day_One_Importer_Results $results Results.
	 * @return bool True when finalization completed and post was marked complete.
	 */
	public function finalize_imported_entry( $entry, $post_id, $attachment_ids, Day_One_Importer_Results $results ) {
		$uuid = isset( $entry['uuid'] ) ? (string) $entry['uuid'] : '';
		if ( ! empty( $attachment_ids ) ) {
			$content            = Day_One_Importer_Content::convert_text_to_content( isset( $entry['text'] ) ? $entry['text'] : '' );
			$content_with_media = Day_One_Importer_Content::append_image_section( $content, $attachment_ids );
			$updated            = wp_update_post(
				array(
					'ID'           => (int) $post_id,
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
				update_post_meta( $post_id, '_day_one_import_complete', '0' );
				return false;
			}
		}

		update_post_meta( $post_id, '_day_one_import_version', self::IMPORT_SCHEMA_VERSION );
		update_post_meta( $post_id, '_day_one_import_complete', '1' );
		update_post_meta( $post_id, '_day_one_import_completed_at', current_time( 'mysql', true ) );

		return true;
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
