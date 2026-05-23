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
	const IMPORT_SCHEMA_VERSION = '10';

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

			wp_delete_file( $zip_path );
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
				wp_delete_file( $zip_path );
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
	 * @param int                      $owner_user_id User ID to assign as post author.
	 * @return void
	 */
	public function import_entry( $entry, $extract_dir, Day_One_Importer_Results $results, $owner_user_id = 0 ) {
		$prepared = $this->prepare_imported_entry_post( $entry, $results, $owner_user_id );
		if ( 'ready' !== $prepared['status'] ) {
			return;
		}

		$job = array(
			'current_media_index'          => 0,
			'current_media_total'          => 0,
			'current_video_media_index'    => 0,
			'current_video_total'          => 0,
			'current_audio_media_index'    => 0,
			'current_audio_total'          => 0,
			'current_pdf_media_index'      => 0,
			'current_pdf_total'            => 0,
			'current_attachment_ids'       => array(),
			'current_photo_identifier_map' => array(),
			'current_video_identifier_map' => array(),
			'current_audio_identifier_map' => array(),
			'current_pdf_identifier_map'   => array(),
			'current_entry_media_counted'  => false,
			'current_entry_video_counted'  => false,
			'current_entry_audio_counted'  => false,
			'current_entry_pdf_counted'    => false,
			'current_entry_media_complete' => false,
		);

		$deadline = 1.0E+30;
		while ( ! $this->import_entry_media_batch( $entry, $extract_dir, (int) $prepared['post_id'], $job, $deadline, $results ) ) {
			day_one_importer_prepare_long_running_import();
		}

		$this->finalize_imported_entry( $entry, (int) $prepared['post_id'], $job['current_attachment_ids'], $job['current_photo_identifier_map'], $results, $job['current_video_identifier_map'], $job['current_audio_identifier_map'], $job['current_pdf_identifier_map'] );
	}

	/**
	 * Prepare/create/resume the WordPress post for an entry without importing media.
	 *
	 * @param array<string,mixed>      $entry Entry.
	 * @param Day_One_Importer_Results $results Results.
	 * @param int                      $owner_user_id User ID to assign as post author.
	 * @return array<string,mixed> status: ready, skipped, or failed.
	 */
	public function prepare_imported_entry_post( $entry, Day_One_Importer_Results $results, $owner_user_id = 0 ) {
		$uuid = isset( $entry['uuid'] ) ? (string) $entry['uuid'] : '';
		if ( '' === $uuid ) {
			$results->increment( 'entries_failed' );
			return array(
				'status'  => 'failed',
				'post_id' => 0,
			);
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
					return array(
						'status'  => 'skipped',
						'post_id' => (int) $existing_post_id,
					);
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

		// #56 R12 render-once pin — the prepare step writes a deterministic placeholder
		// body. RichText entries start with '' and are materialized in finalize_imported_entry()
		// after the identifier→attachment_id map is built; legacy entries keep their
		// existing first-render body so the byte-parity range stays byte-identical.
		if ( Day_One_Importer_Content::entry_uses_rich_text_path( $entry ) ) {
			$content = '';
		} else {
			$text    = ( is_array( $entry ) && isset( $entry['text'] ) ) ? $entry['text'] : '';
			$content = Day_One_Importer_Content::convert_text_to_content( $text );
		}
		$title = Day_One_Importer_Content::derive_title_from_entry( $entry, $creation['gmt'] );

		$owner_user_id = absint( $owner_user_id );
		if ( ! $owner_user_id && function_exists( 'get_current_user_id' ) ) {
			$owner_user_id = absint( get_current_user_id() );
		}

		$postarr = array(
			'post_type'    => 'post',
			'post_title'   => $title,
			'post_content' => $content,
		);

		if ( $owner_user_id ) {
			$postarr['post_author'] = $owner_user_id;
		}

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
				return array(
					'status'  => 'failed',
					'post_id' => 0,
				);
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
			$post_id                = wp_insert_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $post_id ) || ! $post_id ) {
				$results->increment( 'entries_failed' );
				$results->add_warning(
					sprintf(
						/* translators: %s: Day One entry UUID. */
						__( 'Could not create private post for UUID %s.', 'day-one-importer' ),
						$uuid
					)
				);
				return array(
					'status'  => 'failed',
					'post_id' => 0,
				);
			}

			$results->increment( 'posts_created' );
		}

		$this->update_entry_meta( (int) $post_id, $entry );
		$this->assign_tags( (int) $post_id, $entry, $uuid, $results );
		$this->assign_journal_category( (int) $post_id, $entry, $uuid, $results );

		/*
		 * `base_content` reflects the body inserted by wp_insert_post at the prepare step.
		 * For richText entries this is intentionally '' — the final body is materialized by
		 * finalize_imported_entry() once the photo identifier map is populated (#56 R12
		 * render-once pin). No downstream consumer reads this key; it is retained for
		 * compatibility with any external caller.
		 */
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
		$photos                     = isset( $entry['photos'] ) && is_array( $entry['photos'] ) ? $entry['photos'] : array();
		$photos                     = Day_One_Importer_Media::sort_photos( $photos );
		$total                      = count( $photos );
		$job['current_media_total'] = $total;

		$videos                     = isset( $entry['videos'] ) && is_array( $entry['videos'] ) ? $entry['videos'] : array();
		$videos                     = Day_One_Importer_Media::sort_videos( $videos );
		$video_total                = count( $videos );
		$job['current_video_total'] = $video_total;

		$audios                     = isset( $entry['audios'] ) && is_array( $entry['audios'] ) ? $entry['audios'] : array();
		$audios                     = Day_One_Importer_Media::sort_audios( $audios );
		$audio_total                = count( $audios );
		$job['current_audio_total'] = $audio_total;

		$pdfs                     = isset( $entry['pdfAttachments'] ) && is_array( $entry['pdfAttachments'] ) ? $entry['pdfAttachments'] : array();
		$pdfs                     = Day_One_Importer_Media::sort_pdfs( $pdfs );
		$pdf_total                = count( $pdfs );
		$job['current_pdf_total'] = $pdf_total;

		$photo_dirs = isset( $job['photo_dirs'] ) && is_array( $job['photo_dirs'] ) ? $job['photo_dirs'] : null;
		$video_dirs = isset( $job['video_dirs'] ) && is_array( $job['video_dirs'] ) ? $job['video_dirs'] : null;
		$audio_dirs = isset( $job['audio_dirs'] ) && is_array( $job['audio_dirs'] ) ? $job['audio_dirs'] : null;
		$pdf_dirs   = isset( $job['pdf_dirs'] ) && is_array( $job['pdf_dirs'] ) ? $job['pdf_dirs'] : null;
		$media      = new Day_One_Importer_Media( $extract_dir, $results, $photo_dirs, $video_dirs, $audio_dirs, $pdf_dirs );
		$limit      = class_exists( 'Day_One_Importer_Job_State' ) ? Day_One_Importer_Job_State::batch_media_limit() : max( 1, $total + $video_total + $audio_total + $pdf_total );

		$ids                  = isset( $job['current_attachment_ids'] ) && is_array( $job['current_attachment_ids'] ) ? array_values( array_map( 'intval', $job['current_attachment_ids'] ) ) : array();
		$identifier_map       = isset( $job['current_photo_identifier_map'] ) && is_array( $job['current_photo_identifier_map'] ) ? $job['current_photo_identifier_map'] : array();
		$video_identifier_map = isset( $job['current_video_identifier_map'] ) && is_array( $job['current_video_identifier_map'] ) ? $job['current_video_identifier_map'] : array();
		$audio_identifier_map = isset( $job['current_audio_identifier_map'] ) && is_array( $job['current_audio_identifier_map'] ) ? $job['current_audio_identifier_map'] : array();
		$pdf_identifier_map   = isset( $job['current_pdf_identifier_map'] ) && is_array( $job['current_pdf_identifier_map'] ) ? $job['current_pdf_identifier_map'] : array();

		// --- Photo loop. ---
		if ( $total > 0 ) {
			if ( empty( $job['current_entry_media_counted'] ) ) {
				$results->increment( 'media_found', $total );
				$job['current_entry_media_counted'] = true;
				if ( is_callable( $checkpoint ) ) {
					call_user_func_array( $checkpoint, array( &$job, $results ) );
				}
			}

			$index = isset( $job['current_media_index'] ) ? max( 0, (int) $job['current_media_index'] ) : 0;
			$done  = 0;

			while ( $index < $total && $done < $limit ) {
				if ( class_exists( 'Day_One_Importer_Job_State' ) && Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) ) {
					break;
				}

				day_one_importer_prepare_long_running_import();
				$attachment_id = $media->import_or_reuse_photo( $photos[ $index ], $entry, $post_id );
				if ( $attachment_id && ! in_array( (int) $attachment_id, $ids, true ) ) {
					$ids[] = (int) $attachment_id;
				}
				// #56 R12 — pair the photo's identifier with its attachment ID so the
				// richText emitter can resolve embeddedObjects[].identifier later.
				// Photos with empty identifiers are silently absent from the map.
				if ( $attachment_id ) {
					$identifier = isset( $photos[ $index ]['identifier'] ) && is_scalar( $photos[ $index ]['identifier'] ) ? (string) $photos[ $index ]['identifier'] : '';
					if ( '' !== $identifier ) {
						$identifier_map[ $identifier ] = (int) $attachment_id;
					}
				}

				++$index;
				++$done;
				$job['current_media_index']          = $index;
				$job['current_attachment_ids']       = $ids;
				$job['current_photo_identifier_map'] = $identifier_map;
				if ( is_callable( $checkpoint ) ) {
					call_user_func_array( $checkpoint, array( &$job, $results ) );
				}
			}

			$job['current_media_index']          = $index;
			$job['current_attachment_ids']       = $ids;
			$job['current_photo_identifier_map'] = $identifier_map;
			if ( $index < $total ) {
				return false;
			}
		}

		// --- Video loop (#57 R7.4). ---
		if ( $video_total > 0 ) {
			if ( empty( $job['current_entry_video_counted'] ) ) {
				$results->increment( 'media_found', $video_total );
				$job['current_entry_video_counted'] = true;
				if ( is_callable( $checkpoint ) ) {
					call_user_func_array( $checkpoint, array( &$job, $results ) );
				}
			}

			$video_index = isset( $job['current_video_media_index'] ) ? max( 0, (int) $job['current_video_media_index'] ) : 0;
			$video_done  = 0;

			while ( $video_index < $video_total && $video_done < $limit ) {
				if ( class_exists( 'Day_One_Importer_Job_State' ) && Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) ) {
					break;
				}

				day_one_importer_prepare_long_running_import();
				$attachment_id = $media->import_or_reuse_video( $videos[ $video_index ], $entry, $post_id );
				if ( $attachment_id && ! in_array( (int) $attachment_id, $ids, true ) ) {
					$ids[] = (int) $attachment_id;
				}
				if ( $attachment_id ) {
					$identifier = isset( $videos[ $video_index ]['identifier'] ) && is_scalar( $videos[ $video_index ]['identifier'] ) ? (string) $videos[ $video_index ]['identifier'] : '';
					if ( '' !== $identifier ) {
						$video_identifier_map[ $identifier ] = (int) $attachment_id;
					}
				}

				++$video_index;
				++$video_done;
				$job['current_video_media_index']    = $video_index;
				$job['current_attachment_ids']       = $ids;
				$job['current_video_identifier_map'] = $video_identifier_map;
				if ( is_callable( $checkpoint ) ) {
					call_user_func_array( $checkpoint, array( &$job, $results ) );
				}
			}

			$job['current_video_media_index']    = $video_index;
			$job['current_attachment_ids']       = $ids;
			$job['current_video_identifier_map'] = $video_identifier_map;
			if ( $video_index < $video_total ) {
				return false;
			}
		}

		// --- Audio loop (#58 R7.4). ---
		if ( $audio_total > 0 ) {
			if ( empty( $job['current_entry_audio_counted'] ) ) {
				$results->increment( 'media_found', $audio_total );
				$job['current_entry_audio_counted'] = true;
				if ( is_callable( $checkpoint ) ) {
					call_user_func_array( $checkpoint, array( &$job, $results ) );
				}
			}

			$audio_index = isset( $job['current_audio_media_index'] ) ? max( 0, (int) $job['current_audio_media_index'] ) : 0;
			$audio_done  = 0;

			while ( $audio_index < $audio_total && $audio_done < $limit ) {
				if ( class_exists( 'Day_One_Importer_Job_State' ) && Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) ) {
					break;
				}

				day_one_importer_prepare_long_running_import();
				$attachment_id = $media->import_or_reuse_audio( $audios[ $audio_index ], $entry, $post_id );
				if ( $attachment_id && ! in_array( (int) $attachment_id, $ids, true ) ) {
					$ids[] = (int) $attachment_id;
				}
				if ( $attachment_id ) {
					$identifier = isset( $audios[ $audio_index ]['identifier'] ) && is_scalar( $audios[ $audio_index ]['identifier'] ) ? (string) $audios[ $audio_index ]['identifier'] : '';
					if ( '' !== $identifier ) {
						$audio_identifier_map[ $identifier ] = (int) $attachment_id;
					}
				}

				++$audio_index;
				++$audio_done;
				$job['current_audio_media_index']    = $audio_index;
				$job['current_attachment_ids']       = $ids;
				$job['current_audio_identifier_map'] = $audio_identifier_map;
				if ( is_callable( $checkpoint ) ) {
					call_user_func_array( $checkpoint, array( &$job, $results ) );
				}
			}

			$job['current_audio_media_index']    = $audio_index;
			$job['current_attachment_ids']       = $ids;
			$job['current_audio_identifier_map'] = $audio_identifier_map;
			if ( $audio_index < $audio_total ) {
				return false;
			}
		}

		// --- PDF loop (#59 R7.4). ---
		if ( $pdf_total > 0 ) {
			if ( empty( $job['current_entry_pdf_counted'] ) ) {
				$results->increment( 'media_found', $pdf_total );
				$job['current_entry_pdf_counted'] = true;
				if ( is_callable( $checkpoint ) ) {
					call_user_func_array( $checkpoint, array( &$job, $results ) );
				}
			}

			$pdf_index = isset( $job['current_pdf_media_index'] ) ? max( 0, (int) $job['current_pdf_media_index'] ) : 0;
			$pdf_done  = 0;

			while ( $pdf_index < $pdf_total && $pdf_done < $limit ) {
				if ( class_exists( 'Day_One_Importer_Job_State' ) && Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) ) {
					break;
				}

				day_one_importer_prepare_long_running_import();
				$attachment_id = $media->import_or_reuse_pdf( $pdfs[ $pdf_index ], $entry, $post_id );
				if ( $attachment_id && ! in_array( (int) $attachment_id, $ids, true ) ) {
					$ids[] = (int) $attachment_id;
				}
				if ( $attachment_id ) {
					$identifier = isset( $pdfs[ $pdf_index ]['identifier'] ) && is_scalar( $pdfs[ $pdf_index ]['identifier'] ) ? (string) $pdfs[ $pdf_index ]['identifier'] : '';
					if ( '' !== $identifier ) {
						$pdf_identifier_map[ $identifier ] = (int) $attachment_id;
					}
				}

				++$pdf_index;
				++$pdf_done;
				$job['current_pdf_media_index']    = $pdf_index;
				$job['current_attachment_ids']     = $ids;
				$job['current_pdf_identifier_map'] = $pdf_identifier_map;
				if ( is_callable( $checkpoint ) ) {
					call_user_func_array( $checkpoint, array( &$job, $results ) );
				}
			}

			$job['current_pdf_media_index']    = $pdf_index;
			$job['current_attachment_ids']     = $ids;
			$job['current_pdf_identifier_map'] = $pdf_identifier_map;
			if ( $pdf_index < $pdf_total ) {
				return false;
			}
		}

		$job['current_entry_media_complete'] = true;
		return true;
	}

	/**
	 * Finalize post content and import completion metadata for one entry.
	 *
	 * Finalize is the sole producer of `post_content` after #56 — the prepare
	 * step writes a deterministic placeholder body and this method materializes
	 * the final body now that the photo identifier map is populated.
	 *
	 * @param array<string,mixed>      $entry          Entry.
	 * @param int                      $post_id        Post ID.
	 * @param int[]                    $attachment_ids Attachment IDs.
	 * @param array<string,int>        $photo_map      identifier → attachment_id map (#56 R12).
	 * @param Day_One_Importer_Results $results        Results.
	 * @param array<string,int>        $video_map      identifier → attachment_id map for videos (#57 R7.5).
	 * @param array<string,int>        $audio_map      identifier → attachment_id map for audios (#58 R7.5).
	 * @param array<string,int>        $pdf_map        identifier → attachment_id map for PDFs (#59 R7.5).
	 * @return bool True when finalization completed and post was marked complete.
	 */
	public function finalize_imported_entry( $entry, $post_id, $attachment_ids, array $photo_map, Day_One_Importer_Results $results, array $video_map = array(), array $audio_map = array(), array $pdf_map = array() ) {
		$uuid           = isset( $entry['uuid'] ) ? (string) $entry['uuid'] : '';
		$uses_rich_text = Day_One_Importer_Content::entry_uses_rich_text_path( $entry );
		$content        = Day_One_Importer_Content::render_entry_body( $entry, $results, $photo_map, $video_map, $audio_map, $pdf_map );

		// #56 R10/R11 — append-at-end ONLY for the legacy markdown path.
		if ( ! $uses_rich_text && ! empty( $attachment_ids ) ) {
			$content = Day_One_Importer_Content::append_image_section( $content, $attachment_ids );
		}

		// #56 R14 — richText entry with imported photos but no inline embed:
		// attach the photos to the post (already done by the media batch) and
		// emit one warning per entry. Renderer is NOT invoked twice — the body
		// rendered above already excludes a trailing gallery.
		// #57 R9.1 — the R14 warning is photo-specific by design. Gate on the
		// entry's photo records (not the combined attachment_ids list, which now
		// also includes video attachment IDs) so video-only entries do not
		// trigger this warning.
		$entry_has_photos = is_array( $entry ) && ! empty( $entry['photos'] ) && is_array( $entry['photos'] );
		if (
			$uses_rich_text
			&& $entry_has_photos
			&& ! empty( $photo_map )
			&& Day_One_Importer_Content::richtext_has_no_photo_embeds( isset( $entry['richText'] ) ? $entry['richText'] : array() )
		) {
			$results->add_warning(
				__( 'Day One entry has imported photos but no inline embed; photos remain attached to the post but are not placed in the body.', 'day-one-importer' )
			);
		}

		$updated = wp_update_post(
			array(
				'ID'           => (int) $post_id,
				'post_content' => wp_slash( $content ),
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			$results->add_warning(
				sprintf(
					/* translators: %s: Day One entry UUID. */
					__( 'Could not update post content for UUID %s.', 'day-one-importer' ),
					$uuid
				)
			);
			update_post_meta( $post_id, '_day_one_import_complete', '0' );
			return false;
		}

		update_post_meta( $post_id, '_day_one_import_version', self::IMPORT_SCHEMA_VERSION );
		update_post_meta( $post_id, '_day_one_import_complete', '1' );
		update_post_meta( $post_id, '_day_one_import_completed_at', current_time( 'mysql', true ) );

		$this->write_location_meta( (int) $post_id, $entry, $results );

		return true;
	}

	/**
	 * Write sanitized location metadata to the imported post.
	 *
	 * Builds the `_day_one_location_*` meta map from the normalized entry
	 * location (spec #61 R3.2/R3.3) and runs it through the
	 * `day_one_importer_location_meta` filter (R4.1/R4.2). The filter contract:
	 *
	 * - `array` (including `array()`): each pair is written via `update_post_meta`.
	 * - `null` or `false`: skip all writes silently.
	 * - any other value: skip writes and emit a warning naming the filter.
	 *
	 * The filter does NOT fire when the entry carries no normalized location
	 * (R4.3). All writes use `update_post_meta` so reruns are idempotent (R3.4).
	 *
	 * @param int                      $post_id Post ID.
	 * @param array<string,mixed>      $entry   Normalized entry array.
	 * @param Day_One_Importer_Results $results Results.
	 * @return void
	 */
	private function write_location_meta( $post_id, array $entry, Day_One_Importer_Results $results ) {
		if ( ! isset( $entry['location'] ) || ! is_array( $entry['location'] ) || empty( $entry['location'] ) ) {
			return;
		}

		$location = $entry['location'];
		$meta     = array();

		if ( isset( $location['latitude'] ) ) {
			$meta['_day_one_location_latitude'] = (string) (float) $location['latitude'];
		}
		if ( isset( $location['longitude'] ) ) {
			$meta['_day_one_location_longitude'] = (string) (float) $location['longitude'];
		}

		$string_map = array(
			'placeName'          => '_day_one_location_place_name',
			'localityName'       => '_day_one_location_locality',
			'administrativeArea' => '_day_one_location_administrative_area',
			'country'            => '_day_one_location_country',
			'timeZoneName'       => '_day_one_location_timezone',
		);
		foreach ( $string_map as $source => $meta_key ) {
			if ( isset( $location[ $source ] ) && is_scalar( $location[ $source ] ) ) {
				$value = day_one_importer_sanitize_text( (string) $location[ $source ] );
				if ( '' !== $value ) {
					$meta[ $meta_key ] = $value;
				}
			}
		}

		if ( isset( $location['raw'] ) && is_scalar( $location['raw'] ) ) {
			$raw = (string) $location['raw'];
			if ( '' !== $raw ) {
				$meta['_day_one_location_raw'] = $raw;
			}
		}

		/**
		 * Filter the location meta key/value map before writing to the post.
		 *
		 * Return an array (possibly empty) to write its pairs as post meta.
		 * Return `null` or `false` to skip all writes silently. Any other
		 * value is treated as a no-op and emits a warning.
		 *
		 * @since 0.2.14
		 *
		 * @param array<string,string> $meta     Meta key => value pairs ready to write.
		 * @param array<string,mixed>  $location Normalized location array.
		 * @param int                  $post_id  Post ID receiving the meta.
		 * @param array<string,mixed>  $entry    Full normalized entry.
		 */
		$filtered = apply_filters( 'day_one_importer_location_meta', $meta, $location, (int) $post_id, $entry );

		if ( null === $filtered || false === $filtered ) {
			return;
		}

		if ( ! is_array( $filtered ) ) {
			$results->add_warning(
				__( 'day_one_importer_location_meta filter returned a non-array, non-null value; location meta was not written.', 'day-one-importer' )
			);
			return;
		}

		foreach ( $filtered as $key => $value ) {
			update_post_meta( $post_id, (string) $key, $value );
		}
	}

	/**
	 * Find existing imported post by UUID.
	 *
	 * @param string                   $uuid UUID.
	 * @param Day_One_Importer_Results $results Results.
	 * @return int Post ID or 0.
	 */
	private function find_existing_post_id( $uuid, Day_One_Importer_Results $results ) {
		$statuses   = get_post_stati( array(), 'names' );
		$candidates = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array_values( $statuses ),
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);
		$ids        = array();
		foreach ( $candidates as $candidate_id ) {
			if ( (string) get_post_meta( (int) $candidate_id, '_day_one_uuid', true ) !== $uuid ) {
				continue;
			}
			$ids[] = (int) $candidate_id;
			if ( 1 < count( $ids ) ) {
				break;
			}
		}

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
		if ( ! empty( $entry['journal'] ) ) {
			update_post_meta( $post_id, '_day_one_journal', Day_One_Importer_Content::normalize_journal_name( $entry['journal'] ) );
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
	 * @param int                      $post_id Post ID.
	 * @param array<string,mixed>      $entry Entry.
	 * @param string                   $uuid UUID.
	 * @param Day_One_Importer_Results $results Results.
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

	/**
	 * Assign the source Day One journal as a WordPress category.
	 *
	 * @param int                      $post_id Post ID.
	 * @param array<string,mixed>      $entry Entry.
	 * @param string                   $uuid UUID.
	 * @param Day_One_Importer_Results $results Results.
	 * @return void
	 */
	private function assign_journal_category( $post_id, $entry, $uuid, Day_One_Importer_Results $results ) {
		$journal = Day_One_Importer_Content::normalize_journal_name( isset( $entry['journal'] ) ? $entry['journal'] : '' );
		if ( '' === $journal ) {
			return;
		}

		$term = term_exists( $journal, 'category' );
		if ( 0 === $term || null === $term ) {
			$term = wp_insert_term( $journal, 'category' );
		}

		if ( is_wp_error( $term ) ) {
			$results->add_warning(
				sprintf(
					/* translators: %s: Day One entry UUID. */
					__( 'Journal category could not be created for UUID %s.', 'day-one-importer' ),
					$uuid
				)
			);
			return;
		}

		$term_id = is_array( $term ) && isset( $term['term_id'] ) ? (int) $term['term_id'] : (int) $term;
		if ( $term_id <= 0 ) {
			return;
		}

		$set = wp_set_post_terms( $post_id, array( $term_id ), 'category', false );
		if ( is_wp_error( $set ) ) {
			$results->add_warning(
				sprintf(
					/* translators: %s: Day One entry UUID. */
					__( 'Journal category could not be assigned for UUID %s.', 'day-one-importer' ),
					$uuid
				)
			);
			return;
		}

		$results->increment( 'categories_assigned' );
	}
}
