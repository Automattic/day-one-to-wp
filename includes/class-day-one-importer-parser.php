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

		$root = class_exists( 'Day_One_Importer_Cleanup' ) ? Day_One_Importer_Cleanup::normalize_path( $root ) : (string) $root;
		if ( ! $this->is_dir( $root ) ) {
			return array(
				'done'  => false,
				'error' => function_exists( '__' ) ? __( 'The extraction directory is invalid.', 'day-one-importer' ) : 'The extraction directory is invalid.',
			);
		}

		if ( array_key_exists( 'zip_json_candidates', $job ) || array_key_exists( 'zip_photo_dirs', $job ) ) {
			return $this->discover_archive_candidates_batch( $root, $job, $results, $deadline, $checkpoint );
		}

		return array(
			'done'  => false,
			'error' => function_exists( '__' ) ? __( 'The import job is missing ZIP discovery metadata.', 'day-one-importer' ) : 'The import job is missing ZIP discovery metadata.',
		);
	}

	/**
	 * Discover JSON files/photo dirs from ZIP preflight candidates in bounded chunks.
	 *
	 * @param string                   $root Extraction root.
	 * @param array<string,mixed>      $job Job state.
	 * @param Day_One_Importer_Results $results Results.
	 * @param float                    $deadline Deadline.
	 * @param callable|null            $checkpoint Checkpoint callback.
	 * @return array<string,mixed>
	 */
	private function discover_archive_candidates_batch( $root, &$job, Day_One_Importer_Results $results, $deadline, $checkpoint ) {
		if ( empty( $job['archive_discovery_initialized'] ) ) {
			$job['archive_json_candidate_index']      = 0;
			$job['archive_photo_dir_candidate_index'] = 0;
			$job['json_files']                        = array();
			$job['photo_dirs']                        = isset( $job['photo_dirs'] ) && is_array( $job['photo_dirs'] ) ? array_values( $job['photo_dirs'] ) : array();
			$job['archive_discovery_initialized']     = true;
		}

		$json_candidates  = isset( $job['zip_json_candidates'] ) && is_array( $job['zip_json_candidates'] ) ? array_values( $job['zip_json_candidates'] ) : array();
		$photo_candidates = isset( $job['zip_photo_dirs'] ) && is_array( $job['zip_photo_dirs'] ) ? array_values( $job['zip_photo_dirs'] ) : array();
		$files            = isset( $job['json_files'] ) && is_array( $job['json_files'] ) ? array_values( $job['json_files'] ) : array();
		$photo_dirs       = isset( $job['photo_dirs'] ) && is_array( $job['photo_dirs'] ) ? array_values( $job['photo_dirs'] ) : array();
		$json_i           = isset( $job['archive_json_candidate_index'] ) ? max( 0, (int) $job['archive_json_candidate_index'] ) : 0;
		$photo_i          = isset( $job['archive_photo_dir_candidate_index'] ) ? max( 0, (int) $job['archive_photo_dir_candidate_index'] ) : 0;
		$processed        = 0;
		$limit            = $this->discovery_node_limit();

		$json_candidate_count  = count( $json_candidates );
		$photo_candidate_count = count( $photo_candidates );

		while ( $processed < $limit && $json_i < $json_candidate_count ) {
			if ( class_exists( 'Day_One_Importer_Job_State' ) && Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) ) {
				break;
			}
			$path = $this->archive_relative_to_real_path( $root, (string) $json_candidates[ $json_i ], false );
			if ( $path && $this->is_file( $path ) && ! in_array( $path, $files, true ) ) {
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
			$path = $this->archive_relative_to_real_path( $root, (string) $photo_candidates[ $photo_i ], true );
			if ( $path && $this->is_dir( $path ) && ! in_array( $path, $photo_dirs, true ) ) {
				$photo_dirs[] = $path;
			}
			++$photo_i;
			++$processed;
			$job['archive_photo_dir_candidate_index'] = $photo_i;
		}

		$job['json_files']       = $files;
		$job['json_files_found'] = count( $files );
		$job['photo_dirs']       = $photo_dirs;

		if ( $json_i >= $json_candidate_count && $photo_i >= $photo_candidate_count ) {
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
	 * @param string $root Extraction root.
	 * @param string $relative Archive-relative path.
	 * @param bool   $directory Whether a directory path is expected.
	 * @return string Empty on failure.
	 */
	private function archive_relative_to_real_path( $root, $relative, $directory ) {
		if ( ! class_exists( 'Day_One_Importer_Cleanup' ) ) {
			return '';
		}

		$path = Day_One_Importer_Cleanup::archive_relative_path( $root, $relative );
		if ( '' === $path || ( $directory && ! $this->is_dir( $path ) ) || ( ! $directory && ! $this->is_file( $path ) ) ) {
			return '';
		}

		return Day_One_Importer_Cleanup::path_is_inside( $path, $root ) ? $path : '';
	}

	/**
	 * Normalize entries into a protected JSONL manifest using a resumable cursor parser.
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

		$seen      = array();
		$file_i    = isset( $job['json_file_index'] ) ? max( 0, (int) $job['json_file_index'] ) : 0;
		$processed = 0;
		$limit     = class_exists( 'Day_One_Importer_Job_State' ) ? Day_One_Importer_Job_State::batch_index_entry_limit() : 100;

		$file_count = count( $files );
		while ( $file_i < $file_count ) {
			if ( $processed >= $limit || ( class_exists( 'Day_One_Importer_Job_State' ) && Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) ) ) {
				$job['seen_uuids']      = array();
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

		$job['seen_uuids'] = array();
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
		if ( '' === $manifest || ! $this->is_readable( $manifest ) ) {
			return null;
		}

		$contents = $this->read_file( $manifest );
		if ( ! is_string( $contents ) || '' === $contents ) {
			return null;
		}

		$current = 0;
		foreach ( preg_split( '/\r\n|\r|\n/', $contents ) as $line ) {
			$line = trim( (string) $line );
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
	 * Process one JSON file until the request budget or entry limit is reached.
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
		if ( ! $this->is_readable( $file ) ) {
			return array(
				'file_done' => true,
				'processed' => 0,
				'error'     => '',
			);
		}

		$contents = $this->read_file( $file );
		if ( ! is_string( $contents ) || '' === $contents ) {
			return array(
				'file_done' => true,
				'processed' => 0,
				'error'     => '',
			);
		}

		$offset       = isset( $job['json_stream_offset'] ) ? max( 0, (int) $job['json_stream_offset'] ) : 0;
		$total_length = strlen( $contents );
		if ( $offset > $total_length ) {
			$offset = 0;
		}

		$mode            = isset( $job['json_stream_mode'] ) ? (string) $job['json_stream_mode'] : 'search_key';
		$in_string       = ! empty( $job['json_stream_in_string'] );
		$escape          = ! empty( $job['json_stream_escape'] );
		$string_buffer   = isset( $job['json_stream_string_buffer'] ) ? (string) $job['json_stream_string_buffer'] : '';
		$top_depth       = isset( $job['json_stream_top_depth'] ) ? max( 0, (int) $job['json_stream_top_depth'] ) : 0;
		$expect_key      = ! empty( $job['json_stream_expect_key'] );
		$entry_buffer    = isset( $job['json_stream_entry_buffer'] ) ? (string) $job['json_stream_entry_buffer'] : '';
		$entry_depth     = isset( $job['json_stream_entry_depth'] ) ? max( 0, (int) $job['json_stream_entry_depth'] ) : 0;
		$entry_in_string = ! empty( $job['json_stream_entry_in_string'] );
		$entry_escape    = ! empty( $job['json_stream_entry_escape'] );
		$entry_i         = isset( $job['json_entry_index'] ) ? max( 0, (int) $job['json_entry_index'] ) : 0;
		$processed       = 0;
		$file_done       = false;
		$paused          = false;

		while ( $offset < $total_length ) {
			if ( $processed >= $limit || ( class_exists( 'Day_One_Importer_Job_State' ) && Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) ) ) {
				$paused = true;
				break;
			}

			$chunk = substr( $contents, $offset, 65536 );
			if ( '' === $chunk ) {
				break;
			}

			$length = strlen( $chunk );
			for ( $i = 0; $i < $length; $i++ ) {
				$char = $chunk[ $i ];
				++$offset;

				if ( 'search_key' === $mode ) {
					$this->stream_search_key_char( $char, $mode, $in_string, $escape, $string_buffer, $top_depth, $expect_key );
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
							$this->persist_stream_state( $job, $offset, $mode, $in_string, $escape, $string_buffer, $top_depth, $expect_key, $entry_buffer, $entry_depth, $entry_in_string, $entry_escape, $entry_i );
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
						$this->persist_stream_state( $job, $offset, $mode, $in_string, $escape, $string_buffer, $top_depth, $expect_key, $entry_buffer, $entry_depth, $entry_in_string, $entry_escape, $entry_i );
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

		$reached_eof = $offset >= $total_length;

		if ( ! $paused && ! $file_done && $reached_eof ) {
			$file_done = true;
		}

		$this->persist_stream_state( $job, $offset, $mode, $in_string, $escape, $string_buffer, $top_depth, $expect_key, $entry_buffer, $entry_depth, $entry_in_string, $entry_escape, $entry_i );
		$job['seen_uuids'] = array();

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
	 * @param int    $top_depth JSON nesting depth while searching top-level keys.
	 * @param bool   $expect_key Whether a top-level object key can start here.
	 * @return void
	 */
	private function stream_search_key_char( $char, &$mode, &$in_string, &$escape, &$string_buffer, &$top_depth, &$expect_key ) {
		if ( ! $in_string ) {
			if ( '"' === $char ) {
				$in_string     = true;
				$escape        = false;
				$string_buffer = ( 1 === $top_depth && $expect_key ) ? '' : null;
				return;
			}
			if ( '{' === $char || '[' === $char ) {
				++$top_depth;
				if ( 1 === $top_depth && '{' === $char ) {
					$expect_key = true;
				}
				return;
			}
			if ( '}' === $char || ']' === $char ) {
				$top_depth  = max( 0, $top_depth - 1 );
				$expect_key = false;
				return;
			}
			if ( ',' === $char && 1 === $top_depth ) {
				$expect_key = true;
			}
			return;
		}

		if ( $escape ) {
			if ( null !== $string_buffer && strlen( $string_buffer ) < 64 ) {
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
			$in_string = false;
			if ( null !== $string_buffer ) {
				$mode       = 'entries' === $string_buffer ? 'search_colon' : 'search_key';
				$expect_key = false;
			}
			$string_buffer = '';
			return;
		}

		if ( null !== $string_buffer && strlen( $string_buffer ) < 64 ) {
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

		if ( 'exists' === $written ) {
			$results->add_warning(
				sprintf(
					/* translators: %s: Day One UUID. */
					__( 'Duplicate UUID in export skipped: %s', 'day-one-importer' ),
					$entry['uuid']
				)
			);
			return true;
		}

		$seen[ $uuid_key ]    = true;
		$job['seen_uuids']    = array();
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
	 * @param int                 $top_depth JSON nesting depth while searching top-level keys.
	 * @param bool                $expect_key Whether a top-level object key can start here.
	 * @param string              $entry_buffer Entry buffer.
	 * @param int                 $entry_depth Entry depth.
	 * @param bool                $entry_in_string Entry string state.
	 * @param bool                $entry_escape Entry escape state.
	 * @param int                 $entry_i Entry index.
	 * @return void
	 */
	private function persist_stream_state( &$job, $offset, $mode, $in_string, $escape, $string_buffer, $top_depth, $expect_key, $entry_buffer, $entry_depth, $entry_in_string, $entry_escape, $entry_i ) {
		$job['json_stream_offset']          = max( 0, (int) $offset );
		$job['json_stream_mode']            = (string) $mode;
		$job['json_stream_in_string']       = (bool) $in_string;
		$job['json_stream_escape']          = (bool) $escape;
		$job['json_stream_string_buffer']   = (string) $string_buffer;
		$job['json_stream_top_depth']       = max( 0, (int) $top_depth );
		$job['json_stream_expect_key']      = (bool) $expect_key;
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
		$job['json_stream_top_depth']       = 0;
		$job['json_stream_expect_key']      = false;
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

		if ( $this->exists( $marker ) ) {
			$marker_contents = $this->read_file( $marker );
			$state           = is_string( $marker_contents ) ? trim( $marker_contents ) : '';
			if ( 'written' === $state ) {
				return 'exists';
			}
			if ( $this->manifest_contains_uuid( $manifest, $uuid ) ) {
				$this->write_manifest_marker( $marker, 'written' );
				return 'exists';
			}
		} elseif ( ! $this->create_manifest_marker( $marker ) ) {
			if ( $this->exists( $marker ) ) {
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
		if ( $this->exists( $marker ) ) {
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
	 * @return string|false File contents, or false on failure.
	 */
	private function read_file( $path ) {
		return class_exists( 'Day_One_Importer_Cleanup' ) ? Day_One_Importer_Cleanup::read_file( $path ) : false;
	}

	/**
	 * Check path existence through WordPress' filesystem abstraction.
	 *
	 * @param string $path Path.
	 * @return bool True when the path exists.
	 */
	private function exists( $path ) {
		return class_exists( 'Day_One_Importer_Cleanup' ) && Day_One_Importer_Cleanup::exists( $path );
	}

	/**
	 * Check directory status through WordPress' filesystem abstraction.
	 *
	 * @param string $path Path.
	 * @return bool True when the path is a directory.
	 */
	private function is_dir( $path ) {
		return class_exists( 'Day_One_Importer_Cleanup' ) && Day_One_Importer_Cleanup::is_dir( $path );
	}

	/**
	 * Check file status through WordPress' filesystem abstraction.
	 *
	 * @param string $path Path.
	 * @return bool True when the path is a file.
	 */
	private function is_file( $path ) {
		return class_exists( 'Day_One_Importer_Cleanup' ) && Day_One_Importer_Cleanup::is_file( $path );
	}

	/**
	 * Check file readability through WordPress' filesystem abstraction.
	 *
	 * @param string $path Path.
	 * @return bool True when the file can be read.
	 */
	private function is_readable( $path ) {
		return class_exists( 'Day_One_Importer_Cleanup' ) && Day_One_Importer_Cleanup::is_readable( $path );
	}

	/**
	 * Append one line to the manifest through WP_Filesystem.
	 *
	 * @param string $manifest Manifest path.
	 * @param string $line Line to append.
	 * @return bool True when the line was appended.
	 */
	private function append_manifest_line( $manifest, $line ) {
		$existing = '';
		if ( $this->exists( $manifest ) ) {
			$existing = $this->read_file( $manifest );
		}
		if ( ! is_string( $existing ) ) {
			return false;
		}

		return $this->write_manifest_marker( $manifest, $existing . $line );
	}

	/**
	 * Prepare manifest file and idempotency marker directory.
	 *
	 * @param string $manifest Manifest path.
	 * @return bool
	 */
	private function prepare_manifest_storage( $manifest ) {
		$dir = dirname( $manifest );
		if ( ! $this->is_dir( $dir ) && class_exists( 'Day_One_Importer_Cleanup' ) ) {
			Day_One_Importer_Cleanup::make_directory( $dir );
		}
		if ( ! $this->is_dir( $dir ) ) {
			return false;
		}
		if ( ! $this->exists( $manifest ) && ! $this->write_manifest_marker( $manifest, '' ) ) {
			return false;
		}
		if ( class_exists( 'Day_One_Importer_Cleanup' ) ) {
			Day_One_Importer_Cleanup::set_owner_only_permissions( $manifest );
		}

		$marker_dir = $this->manifest_marker_dir( $manifest );
		if ( ! $this->is_dir( $marker_dir ) && class_exists( 'Day_One_Importer_Cleanup' ) ) {
			Day_One_Importer_Cleanup::make_directory( $marker_dir );
		}
		if ( class_exists( 'Day_One_Importer_Cleanup' ) ) {
			Day_One_Importer_Cleanup::protect_directory( $marker_dir );
		}

		return $this->is_dir( $marker_dir );
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
		if ( ! $this->is_dir( $dir ) && ! $this->prepare_manifest_storage( $manifest ) ) {
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
		if ( ! $this->is_readable( $manifest ) ) {
			return false;
		}

		$contents = $this->read_file( $manifest );
		if ( ! is_string( $contents ) || '' === $contents ) {
			return false;
		}

		foreach ( preg_split( '/\r\n|\r|\n/', $contents ) as $line ) {
			$line = trim( (string) $line );
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
		return class_exists( 'Day_One_Importer_Cleanup' ) && Day_One_Importer_Cleanup::path_is_inside( $path, $root );
	}

	/**
	 * Find journal JSON files with an entries array.
	 *
	 * @param string $root Extraction root.
	 * @return string[]
	 */
	public function find_journal_json_files( $root ) {
		$root = class_exists( 'Day_One_Importer_Cleanup' ) ? Day_One_Importer_Cleanup::normalize_path( $root ) : (string) $root;
		if ( ! $this->is_dir( $root ) ) {
			return array();
		}

		$files = array();
		foreach ( Day_One_Importer_Cleanup::list_directory_paths( $root ) as $path ) {
			if ( ! $this->is_file( $path ) ) {
				continue;
			}

			if ( false !== strpos( $path, '/__MACOSX/' ) ) {
				continue;
			}

			$filename = basename( $path );
			if ( 0 === strpos( $filename, '.' ) ) {
				continue;
			}

			if ( 'json' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
				continue;
			}

			$data = $this->decode_json_file( $path );
			if ( is_array( $data ) && isset( $data['entries'] ) && is_array( $data['entries'] ) ) {
				$files[] = $path;
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
		$contents = $this->read_file( $file );
		if ( ! is_string( $contents ) || '' === $contents ) {
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

		return array(
			'uuid'                => $uuid,
			'creationDate'        => isset( $raw_entry['creationDate'] ) && is_scalar( $raw_entry['creationDate'] ) ? (string) $raw_entry['creationDate'] : '',
			'modifiedDate'        => isset( $raw_entry['modifiedDate'] ) && is_scalar( $raw_entry['modifiedDate'] ) ? (string) $raw_entry['modifiedDate'] : '',
			'timeZone'            => isset( $raw_entry['timeZone'] ) && is_scalar( $raw_entry['timeZone'] ) ? day_one_importer_sanitize_text( $raw_entry['timeZone'] ) : '',
			'text'                => isset( $raw_entry['text'] ) && is_scalar( $raw_entry['text'] ) ? (string) $raw_entry['text'] : '',
			'tags'                => isset( $raw_entry['tags'] ) && is_array( $raw_entry['tags'] ) ? $raw_entry['tags'] : array(),
			'journal'             => Day_One_Importer_Content::derive_journal_name( $raw_entry, $source_file ),
			'photos'              => $photos,
			'starred'             => ! empty( $raw_entry['starred'] ),
			'isPinned'            => ! empty( $raw_entry['isPinned'] ),
			'creationDeviceType'  => isset( $raw_entry['creationDeviceType'] ) && is_scalar( $raw_entry['creationDeviceType'] ) ? day_one_importer_sanitize_text( $raw_entry['creationDeviceType'] ) : '',
			'creationDeviceModel' => isset( $raw_entry['creationDeviceModel'] ) && is_scalar( $raw_entry['creationDeviceModel'] ) ? day_one_importer_sanitize_text( $raw_entry['creationDeviceModel'] ) : '',
			'source_file'         => basename( $source_file ),
		);
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
}
