<?php
/**
 * Day One export parser.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds journal JSON files and normalizes entries.
 */
class Day_One_Importer_Parser {
	/**
	 * Parse all Day One journal JSON files under an extracted export root.
	 *
	 * @param string                   $root Extraction root.
	 * @param Day_One_Importer_Results $results Results object.
	 * @return array<int,array<string,mixed>> Normalized entries.
	 */
	public function parse_export( $root, Day_One_Importer_Results $results ) {
		$files = $this->find_journal_json_files( $root );
		$results->set_count( 'json_files_found', count( $files ) );

		$entries = array();
		$seen    = array();

		foreach ( $files as $file ) {
			$data = $this->decode_json_file( $file );
			if ( ! is_array( $data ) || ! isset( $data['entries'] ) || ! is_array( $data['entries'] ) ) {
				continue;
			}

			foreach ( $data['entries'] as $index => $raw_entry ) {
				$results->increment( 'entries_found' );
				$entry = $this->normalize_entry( $raw_entry, $file, $index, $results );
				if ( null === $entry ) {
					continue;
				}

				$uuid_key = strtolower( $entry['uuid'] );
				if ( isset( $seen[ $uuid_key ] ) ) {
					$results->add_warning(
						sprintf(
							/* translators: %s: Day One UUID. */
							__( 'Duplicate UUID in export skipped: %s', 'day-one-importer' ),
							$entry['uuid']
						)
					);
					continue;
				}

				$seen[ $uuid_key ] = true;
				$entries[]         = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Discover candidate journal JSON files for a persisted job in bounded chunks.
	 *
	 * Async jobs use ZIP preflight metadata as the discovery source, so discovery
	 * does not need to enumerate extracted directories (including huge media
	 * directories) at all.
	 *
	 * @param string                   $root Extraction root.
	 * @param array<string,mixed>      $job Job state, updated by reference.
	 * @param Day_One_Importer_Results $results Results object.
	 * @param float                    $deadline Deadline timestamp.
	 * @param callable|null            $checkpoint Optional checkpoint callback.
	 * @return array<string,mixed>
	 */
	public function discover_json_files_batch( $root, &$job, Day_One_Importer_Results $results, $deadline, $checkpoint = null ) {
		if ( ! empty( $job['json_discovery_done'] ) ) {
			return array(
				'done'  => true,
				'error' => '',
			);
		}

		$root_real = realpath( $root );
		if ( false === $root_real || ! is_dir( $root_real ) ) {
			return array(
				'done'  => false,
				'error' => function_exists( '__' ) ? __( 'The extraction directory is invalid.', 'day-one-importer' ) : 'The extraction directory is invalid.',
			);
		}

		if ( array_key_exists( 'zip_json_candidates', $job ) || array_key_exists( 'zip_photo_dirs', $job ) || array_key_exists( 'zip_video_dirs', $job ) || array_key_exists( 'zip_audio_dirs', $job ) || array_key_exists( 'zip_pdf_dirs', $job ) ) {
			return $this->discover_archive_candidates_batch( $root_real, $job, $results, $deadline, $checkpoint );
		}

		return array(
			'done'  => false,
			'error' => function_exists( '__' ) ? __( 'The import job is missing ZIP discovery metadata.', 'day-one-importer' ) : 'The import job is missing ZIP discovery metadata.',
		);
	}

	/**
	 * Discover JSON files/photo dirs from ZIP preflight candidates in bounded chunks.
	 *
	 * @param string                   $root_real Real extraction root.
	 * @param array<string,mixed>      $job Job state.
	 * @param Day_One_Importer_Results $results Results.
	 * @param float                    $deadline Deadline.
	 * @param callable|null            $checkpoint Checkpoint callback.
	 * @return array<string,mixed>
	 */
	private function discover_archive_candidates_batch( $root_real, &$job, Day_One_Importer_Results $results, $deadline, $checkpoint ) {
		if ( empty( $job['archive_discovery_initialized'] ) ) {
			$job['archive_json_candidate_index']      = 0;
			$job['archive_photo_dir_candidate_index'] = 0;
			$job['archive_video_dir_candidate_index'] = 0;
			$job['archive_audio_dir_candidate_index'] = 0;
			$job['archive_pdf_dir_candidate_index']   = 0;
			$job['json_files']                        = array();
			$job['photo_dirs']                        = isset( $job['photo_dirs'] ) && is_array( $job['photo_dirs'] ) ? array_values( $job['photo_dirs'] ) : array();
			$job['video_dirs']                        = isset( $job['video_dirs'] ) && is_array( $job['video_dirs'] ) ? array_values( $job['video_dirs'] ) : array();
			$job['audio_dirs']                        = isset( $job['audio_dirs'] ) && is_array( $job['audio_dirs'] ) ? array_values( $job['audio_dirs'] ) : array();
			$job['pdf_dirs']                          = isset( $job['pdf_dirs'] ) && is_array( $job['pdf_dirs'] ) ? array_values( $job['pdf_dirs'] ) : array();
			$job['archive_discovery_initialized']     = true;
		}

		$json_candidates  = isset( $job['zip_json_candidates'] ) && is_array( $job['zip_json_candidates'] ) ? array_values( $job['zip_json_candidates'] ) : array();
		$photo_candidates = isset( $job['zip_photo_dirs'] ) && is_array( $job['zip_photo_dirs'] ) ? array_values( $job['zip_photo_dirs'] ) : array();
		$video_candidates = isset( $job['zip_video_dirs'] ) && is_array( $job['zip_video_dirs'] ) ? array_values( $job['zip_video_dirs'] ) : array();
		$audio_candidates = isset( $job['zip_audio_dirs'] ) && is_array( $job['zip_audio_dirs'] ) ? array_values( $job['zip_audio_dirs'] ) : array();
		$pdf_candidates   = isset( $job['zip_pdf_dirs'] ) && is_array( $job['zip_pdf_dirs'] ) ? array_values( $job['zip_pdf_dirs'] ) : array();
		$files            = isset( $job['json_files'] ) && is_array( $job['json_files'] ) ? array_values( $job['json_files'] ) : array();
		$photo_dirs       = isset( $job['photo_dirs'] ) && is_array( $job['photo_dirs'] ) ? array_values( $job['photo_dirs'] ) : array();
		$video_dirs       = isset( $job['video_dirs'] ) && is_array( $job['video_dirs'] ) ? array_values( $job['video_dirs'] ) : array();
		$audio_dirs       = isset( $job['audio_dirs'] ) && is_array( $job['audio_dirs'] ) ? array_values( $job['audio_dirs'] ) : array();
		$pdf_dirs         = isset( $job['pdf_dirs'] ) && is_array( $job['pdf_dirs'] ) ? array_values( $job['pdf_dirs'] ) : array();
		$json_i           = isset( $job['archive_json_candidate_index'] ) ? max( 0, (int) $job['archive_json_candidate_index'] ) : 0;
		$photo_i          = isset( $job['archive_photo_dir_candidate_index'] ) ? max( 0, (int) $job['archive_photo_dir_candidate_index'] ) : 0;
		$video_i          = isset( $job['archive_video_dir_candidate_index'] ) ? max( 0, (int) $job['archive_video_dir_candidate_index'] ) : 0;
		$audio_i          = isset( $job['archive_audio_dir_candidate_index'] ) ? max( 0, (int) $job['archive_audio_dir_candidate_index'] ) : 0;
		$pdf_i            = isset( $job['archive_pdf_dir_candidate_index'] ) ? max( 0, (int) $job['archive_pdf_dir_candidate_index'] ) : 0;
		$processed        = 0;
		$limit            = $this->discovery_node_limit();

		$json_candidate_count  = count( $json_candidates );
		$photo_candidate_count = count( $photo_candidates );
		$video_candidate_count = count( $video_candidates );
		$audio_candidate_count = count( $audio_candidates );
		$pdf_candidate_count   = count( $pdf_candidates );

		while ( $processed < $limit && $json_i < $json_candidate_count ) {
			if ( class_exists( 'Day_One_Importer_Job_State' ) && Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) ) {
				break;
			}
			$path = $this->archive_relative_to_real_path( $root_real, (string) $json_candidates[ $json_i ], false );
			if ( $path && is_file( $path ) && ! in_array( $path, $files, true ) ) {
				$files[] = $path;
			}
			++$json_i;
			++$processed;
			$job['archive_json_candidate_index'] = $json_i;
		}

		while ( $processed < $limit && $json_i >= $json_candidate_count && $photo_i < $photo_candidate_count ) {
			if ( class_exists( 'Day_One_Importer_Job_State' ) && Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) ) {
				break;
			}
			$path = $this->archive_relative_to_real_path( $root_real, (string) $photo_candidates[ $photo_i ], true );
			if ( $path && is_dir( $path ) && ! in_array( $path, $photo_dirs, true ) ) {
				$photo_dirs[] = $path;
			}
			++$photo_i;
			++$processed;
			$job['archive_photo_dir_candidate_index'] = $photo_i;
		}

		while ( $processed < $limit && $json_i >= $json_candidate_count && $photo_i >= $photo_candidate_count && $video_i < $video_candidate_count ) {
			if ( class_exists( 'Day_One_Importer_Job_State' ) && Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) ) {
				break;
			}
			$path = $this->archive_relative_to_real_path( $root_real, (string) $video_candidates[ $video_i ], true );
			if ( $path && is_dir( $path ) && ! in_array( $path, $video_dirs, true ) ) {
				$video_dirs[] = $path;
			}
			++$video_i;
			++$processed;
			$job['archive_video_dir_candidate_index'] = $video_i;
		}

		while ( $processed < $limit && $json_i >= $json_candidate_count && $photo_i >= $photo_candidate_count && $video_i >= $video_candidate_count && $audio_i < $audio_candidate_count ) {
			if ( class_exists( 'Day_One_Importer_Job_State' ) && Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) ) {
				break;
			}
			$path = $this->archive_relative_to_real_path( $root_real, (string) $audio_candidates[ $audio_i ], true );
			if ( $path && is_dir( $path ) && ! in_array( $path, $audio_dirs, true ) ) {
				$audio_dirs[] = $path;
			}
			++$audio_i;
			++$processed;
			$job['archive_audio_dir_candidate_index'] = $audio_i;
		}

		// #59 R3.4 — PDF directory candidates resolve after json/photo/video/audio.
		while ( $processed < $limit && $json_i >= $json_candidate_count && $photo_i >= $photo_candidate_count && $video_i >= $video_candidate_count && $audio_i >= $audio_candidate_count && $pdf_i < $pdf_candidate_count ) {
			if ( class_exists( 'Day_One_Importer_Job_State' ) && Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) ) {
				break;
			}
			$path = $this->archive_relative_to_real_path( $root_real, (string) $pdf_candidates[ $pdf_i ], true );
			if ( $path && is_dir( $path ) && ! in_array( $path, $pdf_dirs, true ) ) {
				$pdf_dirs[] = $path;
			}
			++$pdf_i;
			++$processed;
			$job['archive_pdf_dir_candidate_index'] = $pdf_i;
		}

		$job['json_files']       = $files;
		$job['json_files_found'] = count( $files );
		$job['photo_dirs']       = $photo_dirs;
		$job['video_dirs']       = $video_dirs;
		$job['audio_dirs']       = $audio_dirs;
		$job['pdf_dirs']         = $pdf_dirs;

		if ( $json_i >= $json_candidate_count && $photo_i >= $photo_candidate_count && $video_i >= $video_candidate_count && $audio_i >= $audio_candidate_count && $pdf_i >= $pdf_candidate_count ) {
			$job['json_discovery_done'] = true;
			$job['json_file_index']     = 0;
			$job['json_entry_index']    = 0;
			$this->reset_json_stream_state( $job, 0 );
			$this->checkpoint_job( $checkpoint, $job, $results );
			return array(
				'done'  => true,
				'error' => '',
			);
		}

		$this->checkpoint_job( $checkpoint, $job, $results );
		return array(
			'done'  => false,
			'error' => '',
		);
	}

	/**
	 * Convert a safe archive-relative path to an extracted real path.
	 *
	 * @param string $root_real Real extraction root.
	 * @param string $relative Archive-relative path.
	 * @param bool   $directory Whether a directory path is expected.
	 * @return string Empty on failure.
	 */
	private function archive_relative_to_real_path( $root_real, $relative, $directory ) {
		if ( class_exists( 'Day_One_Importer_Cleanup' ) && ! Day_One_Importer_Cleanup::is_safe_relative_archive_name( $relative ) ) {
			return '';
		}

		$path = rtrim( $root_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, trim( str_replace( '\\', '/', (string) $relative ), '/' ) );
		$real = realpath( $path );
		if ( false === $real || ( $directory && ! is_dir( $real ) ) || ( ! $directory && ! is_file( $real ) ) ) {
			return '';
		}

		$root_prefix = rtrim( $root_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		return $real === $root_real || 0 === strpos( $real, $root_prefix ) ? $real : '';
	}

	/**
	 * Normalize entries into a protected JSONL manifest using a streaming parser.
	 *
	 * @param string                   $root Extraction root.
	 * @param array<string,mixed>      $job Job state, updated by reference.
	 * @param Day_One_Importer_Results $results Results object.
	 * @param float                    $deadline Deadline timestamp.
	 * @param callable|null            $checkpoint Optional checkpoint callback.
	 * @return array<string,mixed>
	 */
	public function index_export_batch( $root, &$job, Day_One_Importer_Results $results, $deadline, $checkpoint = null ) {
		unset( $root );
		$files = isset( $job['json_files'] ) && is_array( $job['json_files'] ) ? array_values( $job['json_files'] ) : array();
		if ( empty( $files ) ) {
			return array(
				'done'  => true,
				'error' => '',
			);
		}

		$manifest = isset( $job['manifest_path'] ) ? (string) $job['manifest_path'] : '';
		if ( '' === $manifest || ! $this->prepare_manifest_storage( $manifest ) ) {
			return array(
				'done'  => false,
				'error' => function_exists( '__' ) ? __( 'The import manifest could not be prepared.', 'day-one-importer' ) : 'The import manifest could not be prepared.',
			);
		}

		$seen      = isset( $job['seen_uuids'] ) && is_array( $job['seen_uuids'] ) ? $job['seen_uuids'] : array();
		$file_i    = isset( $job['json_file_index'] ) ? max( 0, (int) $job['json_file_index'] ) : 0;
		$processed = 0;
		$limit     = class_exists( 'Day_One_Importer_Job_State' ) ? Day_One_Importer_Job_State::batch_index_entry_limit() : 100;

		$file_count = count( $files );
		while ( $file_i < $file_count ) {
			if ( $processed >= $limit || ( class_exists( 'Day_One_Importer_Job_State' ) && Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) ) ) {
				$job['seen_uuids']      = $seen;
				$job['json_file_index'] = $file_i;
				$this->checkpoint_job( $checkpoint, $job, $results );
				return array(
					'done'  => false,
					'error' => '',
				);
			}

			if ( ! isset( $job['json_stream_file_index'] ) || (int) $job['json_stream_file_index'] !== $file_i ) {
				$this->reset_json_stream_state( $job, $file_i );
			}

			$batch = $this->stream_json_file_batch( (string) $files[ $file_i ], $job, $results, $deadline, $limit - $processed, $manifest, $seen, $checkpoint );
			if ( ! empty( $batch['error'] ) ) {
				return $batch;
			}

			$processed += isset( $batch['processed'] ) ? (int) $batch['processed'] : 0;
			$seen       = isset( $job['seen_uuids'] ) && is_array( $job['seen_uuids'] ) ? $job['seen_uuids'] : $seen;

			if ( empty( $batch['file_done'] ) ) {
				$this->checkpoint_job( $checkpoint, $job, $results );
				return array(
					'done'  => false,
					'error' => '',
				);
			}

			++$file_i;
			$job['json_file_index']  = $file_i;
			$job['json_entry_index'] = 0;
			$this->reset_json_stream_state( $job, $file_i );
			$this->checkpoint_job( $checkpoint, $job, $results );
		}

		$job['seen_uuids'] = $seen;
		$this->checkpoint_job( $checkpoint, $job, $results );

		return array(
			'done'  => true,
			'error' => '',
		);
	}

	/**
	 * Read one normalized entry from a JSONL manifest by zero-based index.
	 *
	 * @param string $manifest Manifest path.
	 * @param int    $index Entry index.
	 * @return array<string,mixed>|null
	 */
	public function read_manifest_entry( $manifest, $index ) {
		$manifest = (string) $manifest;
		$index    = max( 0, (int) $index );
		if ( '' === $manifest || ! is_readable( $manifest ) ) {
			return null;
		}

		$file    = new SplFileObject( $manifest, 'r' );
		$current = 0;
		while ( ! $file->eof() ) {
			$line = trim( (string) $file->fgets() );
			if ( '' === $line ) {
				continue;
			}
			if ( $current === $index ) {
				$data = json_decode( $line, true );
				return is_array( $data ) ? $data : null;
			}
			++$current;
		}

		return null;
	}

	/**
	 * Stream one JSON file until the request budget or entry limit is reached.
	 *
	 * @param string                   $file JSON file path.
	 * @param array<string,mixed>      $job Job state.
	 * @param Day_One_Importer_Results $results Results.
	 * @param float                    $deadline Deadline.
	 * @param int                      $limit Entry limit.
	 * @param string                   $manifest Manifest path.
	 * @param array<string,bool>       $seen Seen UUID map.
	 * @param callable|null            $checkpoint Checkpoint callback.
	 * @return array<string,mixed>
	 */
	private function stream_json_file_batch( $file, &$job, Day_One_Importer_Results $results, $deadline, $limit, $manifest, &$seen, $checkpoint ) {
		$handle = @fopen( $file, 'rb' );
		if ( ! $handle ) {
			return array(
				'file_done' => true,
				'processed' => 0,
				'error'     => '',
			);
		}

		$offset = isset( $job['json_stream_offset'] ) ? max( 0, (int) $job['json_stream_offset'] ) : 0;
		if ( $offset > 0 ) {
			@fseek( $handle, $offset );
		}

		$mode            = isset( $job['json_stream_mode'] ) ? (string) $job['json_stream_mode'] : 'search_key';
		$in_string       = ! empty( $job['json_stream_in_string'] );
		$escape          = ! empty( $job['json_stream_escape'] );
		$string_buffer   = isset( $job['json_stream_string_buffer'] ) ? (string) $job['json_stream_string_buffer'] : '';
		$entry_buffer    = isset( $job['json_stream_entry_buffer'] ) ? (string) $job['json_stream_entry_buffer'] : '';
		$entry_depth     = isset( $job['json_stream_entry_depth'] ) ? max( 0, (int) $job['json_stream_entry_depth'] ) : 0;
		$entry_in_string = ! empty( $job['json_stream_entry_in_string'] );
		$entry_escape    = ! empty( $job['json_stream_entry_escape'] );
		$entry_i         = isset( $job['json_entry_index'] ) ? max( 0, (int) $job['json_entry_index'] ) : 0;
		$processed       = 0;
		$file_done       = false;
		$paused          = false;

		while ( ! feof( $handle ) ) {
			if ( $processed >= $limit || ( class_exists( 'Day_One_Importer_Job_State' ) && Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) ) ) {
				$paused = true;
				break;
			}

			$chunk = fread( $handle, 65536 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}

			$length = strlen( $chunk );
			for ( $i = 0; $i < $length; $i++ ) {
				$char = $chunk[ $i ];
				++$offset;

				if ( 'search_key' === $mode ) {
					$this->stream_search_key_char( $char, $mode, $in_string, $escape, $string_buffer );
					continue;
				}

				if ( 'search_colon' === $mode ) {
					if ( ctype_space( $char ) ) {
						continue;
					}
					$mode = ':' === $char ? 'search_array' : 'search_key';
					continue;
				}

				if ( 'search_array' === $mode ) {
					if ( ctype_space( $char ) ) {
						continue;
					}
					if ( '[' === $char ) {
						$mode = 'in_array';
						if ( empty( $job['json_current_file_counted'] ) ) {
							$results->increment( 'json_files_found' );
							$job['json_current_file_counted'] = true;
							$this->persist_stream_state( $job, $offset, $mode, $in_string, $escape, $string_buffer, $entry_buffer, $entry_depth, $entry_in_string, $entry_escape, $entry_i );
							$this->checkpoint_job( $checkpoint, $job, $results );
						}
					} else {
						$mode = 'search_key';
					}
					continue;
				}

				if ( 'in_array' !== $mode ) {
					continue;
				}

				if ( $entry_depth > 0 ) {
					$entry_buffer .= $char;
					$this->stream_entry_char( $char, $entry_depth, $entry_in_string, $entry_escape );
					if ( 0 === $entry_depth ) {
						++$processed;
						if ( ! $this->process_streamed_entry( $entry_buffer, $file, $entry_i, $manifest, $job, $results, $seen ) ) {
							fclose( $handle );
							return array(
								'file_done' => false,
								'processed' => $processed,
								'error'     => function_exists( '__' ) ? __( 'The import manifest could not be written.', 'day-one-importer' ) : 'The import manifest could not be written.',
							);
						}
						++$entry_i;
						$entry_buffer            = '';
						$entry_in_string         = false;
						$entry_escape            = false;
						$job['json_entry_index'] = $entry_i;
						$this->persist_stream_state( $job, $offset, $mode, $in_string, $escape, $string_buffer, $entry_buffer, $entry_depth, $entry_in_string, $entry_escape, $entry_i );
						$this->checkpoint_job( $checkpoint, $job, $results );
						if ( $processed >= $limit || ( class_exists( 'Day_One_Importer_Job_State' ) && Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) ) ) {
							$paused = true;
							break 2;
						}
					}
					continue;
				}

				if ( ctype_space( $char ) || ',' === $char ) {
					continue;
				}
				if ( ']' === $char ) {
					$file_done = true;
					break 2;
				}
				if ( '{' === $char ) {
					$entry_buffer    = '{';
					$entry_depth     = 1;
					$entry_in_string = false;
					$entry_escape    = false;
				}
			}
		}

		$reached_eof = feof( $handle );
		fclose( $handle );

		if ( ! $paused && ! $file_done && $reached_eof ) {
			$file_done = true;
		}

		$this->persist_stream_state( $job, $offset, $mode, $in_string, $escape, $string_buffer, $entry_buffer, $entry_depth, $entry_in_string, $entry_escape, $entry_i );
		$job['seen_uuids'] = $seen;

		return array(
			'file_done' => $file_done,
			'processed' => $processed,
			'error'     => '',
		);
	}

	/**
	 * Process a character while searching for an entries key.
	 *
	 * @param string $char Character.
	 * @param string $mode Mode.
	 * @param bool   $in_string In string.
	 * @param bool   $escape Escape state.
	 * @param string $string_buffer String buffer.
	 * @return void
	 */
	private function stream_search_key_char( $char, &$mode, &$in_string, &$escape, &$string_buffer ) {
		if ( ! $in_string ) {
			if ( '"' === $char ) {
				$in_string     = true;
				$escape        = false;
				$string_buffer = '';
			}
			return;
		}

		if ( $escape ) {
			if ( strlen( $string_buffer ) < 64 ) {
				$string_buffer .= $char;
			}
			$escape = false;
			return;
		}

		if ( '\\' === $char ) {
			$escape = true;
			return;
		}

		if ( '"' === $char ) {
			$in_string     = false;
			$mode          = 'entries' === $string_buffer ? 'search_colon' : 'search_key';
			$string_buffer = '';
			return;
		}

		if ( strlen( $string_buffer ) < 64 ) {
			$string_buffer .= $char;
		}
	}

	/**
	 * Update entry object parse state for one character.
	 *
	 * @param string $char Character.
	 * @param int    $depth Object depth.
	 * @param bool   $in_string In string.
	 * @param bool   $escape Escape state.
	 * @return void
	 */
	private function stream_entry_char( $char, &$depth, &$in_string, &$escape ) {
		if ( $in_string ) {
			if ( $escape ) {
				$escape = false;
				return;
			}
			if ( '\\' === $char ) {
				$escape = true;
				return;
			}
			if ( '"' === $char ) {
				$in_string = false;
			}
			return;
		}

		if ( '"' === $char ) {
			$in_string = true;
			return;
		}
		if ( '{' === $char ) {
			++$depth;
			return;
		}
		if ( '}' === $char ) {
			--$depth;
		}
	}

	/**
	 * Process one streamed raw entry object.
	 *
	 * @param string                   $entry_json Entry JSON.
	 * @param string                   $file Source file.
	 * @param int                      $entry_i Entry index.
	 * @param string                   $manifest Manifest path.
	 * @param array<string,mixed>      $job Job state.
	 * @param Day_One_Importer_Results $results Results.
	 * @param array<string,bool>       $seen Seen UUID map.
	 * @return bool True on success.
	 */
	private function process_streamed_entry( $entry_json, $file, $entry_i, $manifest, &$job, Day_One_Importer_Results $results, &$seen ) {
		$results->increment( 'entries_found' );
		$raw_entry = json_decode( $entry_json, true );
		$entry     = $this->normalize_entry( $raw_entry, $file, $entry_i, $results );
		if ( null === $entry ) {
			return true;
		}

		$uuid_key = strtolower( $entry['uuid'] );
		if ( isset( $seen[ $uuid_key ] ) ) {
			$results->add_warning(
				sprintf(
					/* translators: %s: Day One UUID. */
					__( 'Duplicate UUID in export skipped: %s', 'day-one-importer' ),
					$entry['uuid']
				)
			);
			return true;
		}

		$written = $this->append_manifest_entry( $manifest, $entry );
		if ( false === $written ) {
			return false;
		}

		$seen[ $uuid_key ]    = true;
		$job['seen_uuids']    = $seen;
		$job['entries_total'] = isset( $job['entries_total'] ) ? (int) $job['entries_total'] + 1 : 1;

		return true;
	}

	/**
	 * Persist stream cursor fields into the job array.
	 *
	 * @param array<string,mixed> $job Job state.
	 * @param int                 $offset Offset.
	 * @param string              $mode Mode.
	 * @param bool                $in_string Search string state.
	 * @param bool                $escape Search escape state.
	 * @param string              $string_buffer Search buffer.
	 * @param string              $entry_buffer Entry buffer.
	 * @param int                 $entry_depth Entry depth.
	 * @param bool                $entry_in_string Entry string state.
	 * @param bool                $entry_escape Entry escape state.
	 * @param int                 $entry_i Entry index.
	 * @return void
	 */
	private function persist_stream_state( &$job, $offset, $mode, $in_string, $escape, $string_buffer, $entry_buffer, $entry_depth, $entry_in_string, $entry_escape, $entry_i ) {
		$job['json_stream_offset']          = max( 0, (int) $offset );
		$job['json_stream_mode']            = (string) $mode;
		$job['json_stream_in_string']       = (bool) $in_string;
		$job['json_stream_escape']          = (bool) $escape;
		$job['json_stream_string_buffer']   = (string) $string_buffer;
		$job['json_stream_entry_buffer']    = (string) $entry_buffer;
		$job['json_stream_entry_depth']     = max( 0, (int) $entry_depth );
		$job['json_stream_entry_in_string'] = (bool) $entry_in_string;
		$job['json_stream_entry_escape']    = (bool) $entry_escape;
		$job['json_entry_index']            = max( 0, (int) $entry_i );
	}

	/**
	 * Reset streaming cursor fields for a file index.
	 *
	 * @param array<string,mixed> $job Job state.
	 * @param int                 $file_i File index.
	 * @return void
	 */
	private function reset_json_stream_state( &$job, $file_i ) {
		$job['json_stream_file_index']      = max( 0, (int) $file_i );
		$job['json_stream_offset']          = 0;
		$job['json_stream_mode']            = 'search_key';
		$job['json_stream_in_string']       = false;
		$job['json_stream_escape']          = false;
		$job['json_stream_string_buffer']   = '';
		$job['json_stream_entry_buffer']    = '';
		$job['json_stream_entry_depth']     = 0;
		$job['json_stream_entry_in_string'] = false;
		$job['json_stream_entry_escape']    = false;
		$job['json_current_file_counted']   = false;
	}

	/**
	 * Append one normalized entry to a JSONL manifest idempotently.
	 *
	 * @param string              $manifest Manifest path.
	 * @param array<string,mixed> $entry Entry.
	 * @return string|false appended, exists, or false.
	 */
	private function append_manifest_entry( $manifest, $entry ) {
		$uuid = isset( $entry['uuid'] ) ? strtolower( (string) $entry['uuid'] ) : '';
		if ( '' === $uuid ) {
			return false;
		}

		$marker = $this->manifest_marker_path( $manifest, $uuid );
		if ( '' === $marker ) {
			return false;
		}

		if ( file_exists( $marker ) ) {
			$state = trim( (string) $this->read_file( $marker ) );
			if ( 'written' === $state ) {
				return 'exists';
			}
			if ( $this->manifest_contains_uuid( $manifest, $uuid ) ) {
				$this->write_manifest_marker( $marker, 'written' );
				return 'exists';
			}
		} elseif ( ! $this->create_manifest_marker( $marker ) ) {
			if ( file_exists( $marker ) ) {
				return $this->append_manifest_entry( $manifest, $entry );
			}

			return false;
		}

		$flags = 0;
		if ( defined( 'JSON_UNESCAPED_SLASHES' ) ) {
			$flags |= JSON_UNESCAPED_SLASHES;
		}
		if ( defined( 'JSON_UNESCAPED_UNICODE' ) ) {
			$flags |= JSON_UNESCAPED_UNICODE;
		}

		$encoded = wp_json_encode( $entry, $flags );
		if ( ! is_string( $encoded ) ) {
			return false;
		}

		if ( ! $this->append_manifest_line( $manifest, $encoded . "\n" ) ) {
			return false;
		}

		$this->write_manifest_marker( $marker, 'written' );
		return 'appended';
	}

	/**
	 * Create a pending manifest marker.
	 *
	 * @param string $marker Marker path.
	 * @return bool True when the marker was created.
	 */
	private function create_manifest_marker( $marker ) {
		if ( file_exists( $marker ) ) {
			return false;
		}

		return $this->write_manifest_marker( $marker, 'pending' );
	}

	/**
	 * Write marker-like state files.
	 *
	 * @param string $path File path.
	 * @param string $contents File contents.
	 * @return bool True when the file was written.
	 */
	private function write_manifest_marker( $path, $contents ) {
		return class_exists( 'Day_One_Importer_Cleanup' ) && Day_One_Importer_Cleanup::write_file( $path, $contents );
	}

	/**
	 * Read a complete file through WordPress' filesystem abstraction.
	 *
	 * @param string $path File path.
	 * @return string File contents, or an empty string on failure.
	 */
	private function read_file( $path ) {
		$contents = class_exists( 'Day_One_Importer_Cleanup' ) ? Day_One_Importer_Cleanup::read_file( $path ) : false;

		return is_string( $contents ) ? $contents : '';
	}

	/**
	 * Append one line to the manifest under an exclusive lock.
	 *
	 * @param string $manifest Manifest path.
	 * @param string $line Line to append.
	 * @return bool True when the line was appended.
	 */
	private function append_manifest_line( $manifest, $line ) {
		$current = $this->read_file( $manifest );

		return $this->write_manifest_marker( $manifest, $current . $line );
	}

	/**
	 * Prepare manifest file and idempotency marker directory.
	 *
	 * @param string $manifest Manifest path.
	 * @return bool
	 */
	private function prepare_manifest_storage( $manifest ) {
		$dir = dirname( $manifest );
		if ( ! is_dir( $dir ) && class_exists( 'Day_One_Importer_Cleanup' ) ) {
			Day_One_Importer_Cleanup::make_directory( $dir );
		}
		if ( ! is_dir( $dir ) ) {
			return false;
		}
		if ( ! file_exists( $manifest ) && ! $this->write_manifest_marker( $manifest, '' ) ) {
			return false;
		}
		if ( class_exists( 'Day_One_Importer_Cleanup' ) ) {
			Day_One_Importer_Cleanup::set_owner_only_permissions( $manifest );
		}

		$marker_dir = $this->manifest_marker_dir( $manifest );
		if ( ! is_dir( $marker_dir ) && class_exists( 'Day_One_Importer_Cleanup' ) ) {
			Day_One_Importer_Cleanup::make_directory( $marker_dir );
		}
		if ( class_exists( 'Day_One_Importer_Cleanup' ) ) {
			Day_One_Importer_Cleanup::protect_directory( $marker_dir );
		}

		return is_dir( $marker_dir );
	}

	/**
	 * Marker directory for manifest UUID idempotency.
	 *
	 * @param string $manifest Manifest path.
	 * @return string
	 */
	private function manifest_marker_dir( $manifest ) {
		return $manifest . '.uuid-index';
	}

	/**
	 * Marker file path for a UUID.
	 *
	 * @param string $manifest Manifest path.
	 * @param string $uuid UUID.
	 * @return string
	 */
	private function manifest_marker_path( $manifest, $uuid ) {
		$dir = $this->manifest_marker_dir( $manifest );
		if ( ! is_dir( $dir ) && ! $this->prepare_manifest_storage( $manifest ) ) {
			return '';
		}

		return $dir . DIRECTORY_SEPARATOR . sha1( strtolower( $uuid ) ) . '.txt';
	}

	/**
	 * Check whether a manifest already contains a UUID after a pending marker.
	 *
	 * @param string $manifest Manifest path.
	 * @param string $uuid UUID.
	 * @return bool
	 */
	private function manifest_contains_uuid( $manifest, $uuid ) {
		if ( ! is_readable( $manifest ) ) {
			return false;
		}

		$file = new SplFileObject( $manifest, 'r' );
		while ( ! $file->eof() ) {
			$line = trim( (string) $file->fgets() );
			if ( '' === $line ) {
				continue;
			}
			$data = json_decode( $line, true );
			if ( is_array( $data ) && isset( $data['uuid'] ) && strtolower( (string) $data['uuid'] ) === strtolower( $uuid ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Run a job checkpoint callback.
	 *
	 * @param callable|null            $checkpoint Callback.
	 * @param array<string,mixed>      $job Job state.
	 * @param Day_One_Importer_Results $results Results.
	 * @return void
	 */
	private function checkpoint_job( $checkpoint, &$job, Day_One_Importer_Results $results ) {
		if ( is_callable( $checkpoint ) ) {
			call_user_func_array( $checkpoint, array( &$job, $results ) );
		}
	}

	/**
	 * Discovery node limit.
	 *
	 * @return int
	 */
	private function discovery_node_limit() {
		$value = function_exists( 'apply_filters' ) ? apply_filters( 'day_one_importer_batch_discovery_limit', 200 ) : 200;
		$value = (int) $value;

		return $value > 0 ? $value : 200;
	}

	/**
	 * Whether a path is inside root.
	 *
	 * @param string $path Path.
	 * @param string $root Root.
	 * @return bool
	 */
	private function path_is_inside_root( $path, $root ) {
		$root_prefix = rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		return $path === $root || 0 === strpos( $path, $root_prefix );
	}

	/**
	 * Find journal JSON files with an entries array.
	 *
	 * @param string $root Extraction root.
	 * @return string[]
	 */
	public function find_journal_json_files( $root ) {
		$root_real = realpath( $root );
		if ( false === $root_real || ! is_dir( $root_real ) ) {
			return array();
		}

		$files    = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root_real, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() ) {
				continue;
			}

			$path = $item->getPathname();
			if ( false !== strpos( $path, DIRECTORY_SEPARATOR . '__MACOSX' . DIRECTORY_SEPARATOR ) ) {
				continue;
			}

			$filename = $item->getFilename();
			if ( 0 === strpos( $filename, '.' ) ) {
				continue;
			}

			if ( 'json' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
				continue;
			}

			$data = $this->decode_json_file( $item->getPathname() );
			if ( is_array( $data ) && isset( $data['entries'] ) && is_array( $data['entries'] ) ) {
				$files[] = $item->getPathname();
			}
		}

		sort( $files );
		return $files;
	}

	/**
	 * Decode JSON file defensively.
	 *
	 * @param string $file File path.
	 * @return mixed
	 */
	public function decode_json_file( $file ) {
		if ( function_exists( 'wp_json_file_decode' ) ) {
			$data = wp_json_file_decode( $file, array( 'associative' => true ) );
			if ( null !== $data ) {
				return $data;
			}
		}

		$contents = $this->read_file( $file );
		if ( '' === $contents ) {
			return null;
		}

		return json_decode( $contents, true );
	}

	/**
	 * Normalize a raw entry.
	 *
	 * @param mixed                    $raw_entry Raw entry.
	 * @param string                   $source_file Source JSON file.
	 * @param int                      $index Entry index.
	 * @param Day_One_Importer_Results $results Results.
	 * @return array<string,mixed>|null
	 */
	public function normalize_entry( $raw_entry, $source_file, $index, Day_One_Importer_Results $results ) {
		if ( ! is_array( $raw_entry ) ) {
			$results->increment( 'entries_failed' );
			$results->add_warning(
				sprintf(
					/* translators: %s: JSON filename. */
					__( 'Malformed entry skipped in %s.', 'day-one-importer' ),
					basename( $source_file )
				)
			);
			return null;
		}

		$uuid = isset( $raw_entry['uuid'] ) && is_scalar( $raw_entry['uuid'] ) ? day_one_importer_sanitize_text( $raw_entry['uuid'] ) : '';
		if ( '' === $uuid ) {
			$results->increment( 'entries_failed' );
			$results->add_warning(
				sprintf(
					/* translators: 1: JSON filename, 2: entry index. */
					__( 'Entry without UUID skipped in %1$s at index %2$d.', 'day-one-importer' ),
					basename( $source_file ),
					(int) $index
				)
			);
			return null;
		}

		$photos = array();
		if ( isset( $raw_entry['photos'] ) && is_array( $raw_entry['photos'] ) ) {
			foreach ( $raw_entry['photos'] as $photo ) {
				if ( is_array( $photo ) ) {
					$photos[] = $this->normalize_photo( $photo );
				}
			}
		}

		$videos = array();
		if ( isset( $raw_entry['videos'] ) && is_array( $raw_entry['videos'] ) ) {
			foreach ( $raw_entry['videos'] as $video ) {
				if ( is_array( $video ) ) {
					$videos[] = $this->normalize_video( $video );
				}
			}
		}

		$audios = array();
		if ( isset( $raw_entry['audios'] ) && is_array( $raw_entry['audios'] ) ) {
			foreach ( $raw_entry['audios'] as $audio ) {
				if ( is_array( $audio ) ) {
					$audios[] = $this->normalize_audio( $audio );
				}
			}
		}

		// #59 R1.1 — normalize entry.pdfAttachments[].
		$pdf_attachments = array();
		if ( isset( $raw_entry['pdfAttachments'] ) && is_array( $raw_entry['pdfAttachments'] ) ) {
			foreach ( $raw_entry['pdfAttachments'] as $pdf ) {
				if ( is_array( $pdf ) ) {
					$pdf_attachments[] = $this->normalize_pdf_attachment( $pdf );
				}
			}
		}

		$rich_text = null;
		if ( isset( $raw_entry['richText'] ) ) {
			$raw_rich = $raw_entry['richText'];
			if ( is_array( $raw_rich ) ) {
				$rich_text = $raw_rich;
			} elseif ( is_string( $raw_rich ) ) {
				if ( '' !== trim( $raw_rich ) ) {
					$decoded = json_decode( $raw_rich, true );
					if ( is_array( $decoded ) ) {
						$rich_text = $decoded;
					} else {
						$results->add_warning(
							sprintf(
								/* translators: %s: Day One entry UUID. */
								__( 'Could not decode richText for UUID %s; falling back to legacy text.', 'day-one-importer' ),
								$uuid
							)
						);
					}
				}
			}
		}

		// #61 R1 — extract and sanitize location alongside other normalizers.
		$location = null;
		if ( isset( $raw_entry['location'] ) ) {
			$raw_location = $raw_entry['location'];
			if ( is_array( $raw_location ) && ! empty( $raw_location ) ) {
				$normalized_location = $this->normalize_location( $raw_location );
				if ( ! empty( $normalized_location ) ) {
					$location = $normalized_location;
				}
			} elseif ( ! is_array( $raw_location ) ) {
				$results->add_warning(
					sprintf(
						/* translators: %s: Day One entry UUID. */
						__( 'Malformed location for UUID %s; entry imported without location.', 'day-one-importer' ),
						$uuid
					)
				);
			}
		}

		$normalized = array(
			'uuid'                => $uuid,
			'creationDate'        => isset( $raw_entry['creationDate'] ) && is_scalar( $raw_entry['creationDate'] ) ? (string) $raw_entry['creationDate'] : '',
			'modifiedDate'        => isset( $raw_entry['modifiedDate'] ) && is_scalar( $raw_entry['modifiedDate'] ) ? (string) $raw_entry['modifiedDate'] : '',
			'timeZone'            => isset( $raw_entry['timeZone'] ) && is_scalar( $raw_entry['timeZone'] ) ? day_one_importer_sanitize_text( $raw_entry['timeZone'] ) : '',
			'text'                => isset( $raw_entry['text'] ) && is_scalar( $raw_entry['text'] ) ? (string) $raw_entry['text'] : '',
			'tags'                => isset( $raw_entry['tags'] ) && is_array( $raw_entry['tags'] ) ? $raw_entry['tags'] : array(),
			'journal'             => Day_One_Importer_Content::derive_journal_name( $raw_entry, $source_file ),
			'photos'              => $photos,
			'videos'              => $videos,
			'audios'              => $audios,
			'pdfAttachments'      => $pdf_attachments,
			'starred'             => ! empty( $raw_entry['starred'] ),
			'isPinned'            => ! empty( $raw_entry['isPinned'] ),
			'creationDeviceType'  => isset( $raw_entry['creationDeviceType'] ) && is_scalar( $raw_entry['creationDeviceType'] ) ? day_one_importer_sanitize_text( $raw_entry['creationDeviceType'] ) : '',
			'creationDeviceModel' => isset( $raw_entry['creationDeviceModel'] ) && is_scalar( $raw_entry['creationDeviceModel'] ) ? day_one_importer_sanitize_text( $raw_entry['creationDeviceModel'] ) : '',
			'source_file'         => basename( $source_file ),
		);

		if ( is_array( $rich_text ) ) {
			$normalized['richText'] = $rich_text;
		}

		if ( is_array( $location ) && ! empty( $location ) ) {
			$normalized['location'] = $location;
		}

		return $normalized;
	}

	/**
	 * Normalize photo metadata.
	 *
	 * @param array<string,mixed> $photo Raw photo.
	 * @return array<string,mixed>
	 */
	private function normalize_photo( $photo ) {
		return array(
			'identifier'   => isset( $photo['identifier'] ) && is_scalar( $photo['identifier'] ) ? day_one_importer_sanitize_text( $photo['identifier'] ) : '',
			'md5'          => isset( $photo['md5'] ) && is_scalar( $photo['md5'] ) ? strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) $photo['md5'] ) ) : '',
			'type'         => isset( $photo['type'] ) && is_scalar( $photo['type'] ) ? strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $photo['type'] ) ) : '',
			'filename'     => isset( $photo['filename'] ) && is_scalar( $photo['filename'] ) ? basename( day_one_importer_sanitize_text( $photo['filename'] ) ) : '',
			'date'         => isset( $photo['date'] ) && is_scalar( $photo['date'] ) ? day_one_importer_sanitize_text( $photo['date'] ) : '',
			'orderInEntry' => isset( $photo['orderInEntry'] ) && is_numeric( $photo['orderInEntry'] ) ? (int) $photo['orderInEntry'] : null,
			'width'        => isset( $photo['width'] ) && is_numeric( $photo['width'] ) ? (int) $photo['width'] : 0,
			'height'       => isset( $photo['height'] ) && is_numeric( $photo['height'] ) ? (int) $photo['height'] : 0,
		);
	}

	/**
	 * Normalize video metadata.
	 *
	 * Mirrors normalize_photo() for the shared keys. `duration` is stored as the
	 * canonical PHP string form of the originally decoded JSON number (see spec
	 * R1.4 — string storage avoids float drift on JSONL round-trips).
	 *
	 * @param array<string,mixed> $video Raw video.
	 * @return array<string,mixed>
	 */
	private function normalize_video( $video ) {
		$duration = '';
		if ( isset( $video['duration'] ) && is_numeric( $video['duration'] ) ) {
			$duration = (string) ( floatval( $video['duration'] ) + 0 );
		}

		return array(
			'identifier'   => isset( $video['identifier'] ) && is_scalar( $video['identifier'] ) ? day_one_importer_sanitize_text( $video['identifier'] ) : '',
			'md5'          => isset( $video['md5'] ) && is_scalar( $video['md5'] ) ? strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) $video['md5'] ) ) : '',
			'type'         => isset( $video['type'] ) && is_scalar( $video['type'] ) ? strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $video['type'] ) ) : '',
			'filename'     => isset( $video['filename'] ) && is_scalar( $video['filename'] ) ? basename( day_one_importer_sanitize_text( $video['filename'] ) ) : '',
			'date'         => isset( $video['date'] ) && is_scalar( $video['date'] ) ? day_one_importer_sanitize_text( $video['date'] ) : '',
			'orderInEntry' => isset( $video['orderInEntry'] ) && is_numeric( $video['orderInEntry'] ) ? (int) $video['orderInEntry'] : null,
			'width'        => isset( $video['width'] ) && is_numeric( $video['width'] ) ? (int) $video['width'] : 0,
			'height'       => isset( $video['height'] ) && is_numeric( $video['height'] ) ? (int) $video['height'] : 0,
			'duration'     => $duration,
		);
	}

	/**
	 * Normalize audio metadata.
	 *
	 * Mirrors normalize_video() for the shared keys. `format` deviates from
	 * the photo/video `type` field by one step: a single leading dot is
	 * stripped before the regex pass (Day One ships either `.mp3` or `aac`).
	 * `duration` is stored as a canonical PHP string (spec R1.3, R1.4).
	 *
	 * @param array<string,mixed> $audio Raw audio.
	 * @return array<string,mixed>
	 */
	private function normalize_audio( $audio ) {
		$duration = '';
		if ( isset( $audio['duration'] ) && is_numeric( $audio['duration'] ) ) {
			$duration = (string) ( floatval( $audio['duration'] ) + 0 );
		}

		$format = '';
		if ( isset( $audio['format'] ) && is_scalar( $audio['format'] ) ) {
			$raw_format = (string) $audio['format'];
			if ( '' !== $raw_format && '.' === $raw_format[0] ) {
				$raw_format = substr( $raw_format, 1 );
			}
			$format = strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', $raw_format ) );
		}

		return array(
			'identifier'   => isset( $audio['identifier'] ) && is_scalar( $audio['identifier'] ) ? day_one_importer_sanitize_text( $audio['identifier'] ) : '',
			'md5'          => isset( $audio['md5'] ) && is_scalar( $audio['md5'] ) ? strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) $audio['md5'] ) ) : '',
			'format'       => $format,
			'filename'     => isset( $audio['filename'] ) && is_scalar( $audio['filename'] ) ? basename( day_one_importer_sanitize_text( $audio['filename'] ) ) : '',
			'date'         => isset( $audio['date'] ) && is_scalar( $audio['date'] ) ? day_one_importer_sanitize_text( $audio['date'] ) : '',
			'orderInEntry' => isset( $audio['orderInEntry'] ) && is_numeric( $audio['orderInEntry'] ) ? (int) $audio['orderInEntry'] : null,
			'duration'     => $duration,
			'title'        => isset( $audio['title'] ) && is_scalar( $audio['title'] ) ? day_one_importer_sanitize_text( $audio['title'] ) : '',
		);
	}

	/**
	 * Normalize pdf attachment metadata.
	 *
	 * Mirrors normalize_audio() for the shared keys. PDFs deviate by using a
	 * `pdfName` field for the human-readable label (no `type`/`format` field,
	 * no `duration`, no `width`/`height` -- all invariant or zeroed in Day One
	 * exports). See spec R1.2 + R1.3 + R1.4.
	 *
	 * @param array<string,mixed> $pdf Raw PDF attachment.
	 * @return array<string,mixed>
	 */
	private function normalize_pdf_attachment( $pdf ) {
		return array(
			'identifier'   => isset( $pdf['identifier'] ) && is_scalar( $pdf['identifier'] ) ? day_one_importer_sanitize_text( $pdf['identifier'] ) : '',
			'md5'          => isset( $pdf['md5'] ) && is_scalar( $pdf['md5'] ) ? strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) $pdf['md5'] ) ) : '',
			'pdfName'      => isset( $pdf['pdfName'] ) && is_scalar( $pdf['pdfName'] ) ? day_one_importer_sanitize_text( $pdf['pdfName'] ) : '',
			'orderInEntry' => isset( $pdf['orderInEntry'] ) && is_numeric( $pdf['orderInEntry'] ) ? (int) $pdf['orderInEntry'] : null,
			'fileSize'     => isset( $pdf['fileSize'] ) && is_numeric( $pdf['fileSize'] ) ? (int) $pdf['fileSize'] : 0,
		);
	}

	/**
	 * Normalize entry location metadata.
	 *
	 * Extracts the typed scalar fields (latitude/longitude/placeName/localityName/
	 * administrativeArea/country/timeZoneName) when each is present and valid, plus
	 * a `raw` JSON snapshot of the full original location payload (preserves the
	 * `region` subtree without exposing it as separate normalized fields). See spec
	 * #61 R1.3 — each key is independently optional; the caller drops the entire
	 * `location` key when this method returns an empty array.
	 *
	 * @param array<string,mixed> $raw Raw location subtree.
	 * @return array<string,mixed>
	 */
	private function normalize_location( $raw ) {
		$location = array();

		if ( isset( $raw['latitude'] ) && is_numeric( $raw['latitude'] ) ) {
			$location['latitude'] = (float) $raw['latitude'];
		}

		if ( isset( $raw['longitude'] ) && is_numeric( $raw['longitude'] ) ) {
			$location['longitude'] = (float) $raw['longitude'];
		}

		$string_fields = array( 'placeName', 'localityName', 'administrativeArea', 'country', 'timeZoneName' );
		foreach ( $string_fields as $field ) {
			if ( isset( $raw[ $field ] ) && is_scalar( $raw[ $field ] ) ) {
				$sanitized = day_one_importer_sanitize_text( (string) $raw[ $field ] );
				if ( '' !== $sanitized ) {
					$location[ $field ] = $sanitized;
				}
			}
		}

		$encoded         = wp_json_encode( $raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$location['raw'] = false === $encoded ? '' : $encoded;

		return $location;
	}
}
