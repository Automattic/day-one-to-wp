<?php
/**
 * Bounded import job processor.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Advances persisted import jobs through short resumable batches.
 */
class Day_One_Importer_Job_Processor {
	/**
	 * Job store.
	 *
	 * @var Day_One_Importer_Job_Store
	 */
	private $store;

	/**
	 * Current lock token for renewal during processing.
	 *
	 * @var string
	 */
	private $current_lock_token = '';

	/**
	 * Current lock TTL for renewal during processing.
	 *
	 * @var int
	 */
	private $current_lock_ttl = 120;

	/**
	 * Constructor.
	 *
	 * @param Day_One_Importer_Job_Store|null $store Store.
	 */
	public function __construct( $store = null ) {
		$this->store = $store instanceof Day_One_Importer_Job_Store ? $store : new Day_One_Importer_Job_Store();
	}

	/**
	 * Process one bounded batch for a job.
	 *
	 * @param string $job_id Job ID.
	 * @param string $source ajax|cron|manual.
	 * @return array<string,mixed> Sanitized status payload.
	 */
	public function process_batch( $job_id, $source = 'ajax' ) {
		$job = $this->store->get_job( $job_id );
		if ( ! $job ) {
			$results = new Day_One_Importer_Results();
			$results->add_error( __( 'The requested import job could not be found.', 'day-one-importer' ) );
			return Day_One_Importer_Job_State::status_response(
				array(
					'id'      => Day_One_Importer_Job_Store::sanitize_job_id( $job_id ),
					'status'  => Day_One_Importer_Job_State::STATUS_FAILED,
					'phase'   => 'uploaded',
					'results' => $results->to_array(),
				)
			);
		}

		if ( Day_One_Importer_Job_State::is_terminal_status( $job['status'] ) || Day_One_Importer_Job_State::STATUS_FAILED === $job['status'] ) {
			return Day_One_Importer_Job_State::status_response( $job );
		}

		$budget                 = Day_One_Importer_Job_State::batch_time_budget( $source );
		$this->current_lock_ttl = $this->lock_ttl( $budget );
		$lock                   = $this->store->acquire_lock( $job['id'], '', $this->current_lock_ttl );
		if ( ! $lock ) {
			return Day_One_Importer_Job_State::status_response( $job, true );
		}

		$this->current_lock_token = (string) $lock;
		try {
			day_one_importer_prepare_long_running_import();
			$deadline      = Day_One_Importer_Job_State::deadline_from_budget( $budget );
			$results       = Day_One_Importer_Results::from_array( isset( $job['results'] ) ? $job['results'] : array() );
			$job['status'] = Day_One_Importer_Job_State::STATUS_RUNNING;
			$this->save_job( $job, $results );

			$continue = true;
			while ( $continue && ! Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) && ! Day_One_Importer_Job_State::is_terminal_status( $job['status'] ) && Day_One_Importer_Job_State::STATUS_FAILED !== $job['status'] ) {
				$continue = $this->advance_current_phase( $job, $results, $deadline );
			}
		} catch ( Throwable $e ) {
			if ( ! isset( $results ) || ! $results instanceof Day_One_Importer_Results ) {
				$results = Day_One_Importer_Results::from_array( isset( $job['results'] ) ? $job['results'] : array() );
			}
			$results->add_error( __( 'The import stopped because of an unexpected batch error. Retry is safe.', 'day-one-importer' ) );
			$job['status'] = Day_One_Importer_Job_State::STATUS_FAILED;
			$this->save_job( $job, $results );
		} finally {
			$this->store->release_lock( $job['id'], $lock );
			$this->current_lock_token = '';
		}

		$latest = $this->store->get_job( $job['id'] );
		if ( $latest && ! Day_One_Importer_Job_State::is_terminal_status( $latest['status'] ) && Day_One_Importer_Job_State::STATUS_FAILED !== $latest['status'] ) {
			$this->store->schedule_job( $latest['id'] );
		}

		return Day_One_Importer_Job_State::status_response( $latest ? $latest : $job );
	}

	/**
	 * Advance the job's current phase.
	 *
	 * @param array<string,mixed>      $job Job state, updated by reference.
	 * @param Day_One_Importer_Results $results Results.
	 * @param float                    $deadline Deadline timestamp.
	 * @return bool True when processor may continue to the next phase in this request.
	 */
	private function advance_current_phase( &$job, Day_One_Importer_Results $results, $deadline ) {
		$phase = Day_One_Importer_Job_State::normalize_phase( isset( $job['phase'] ) ? $job['phase'] : '' );

		switch ( $phase ) {
			case 'uploaded':
			case 'preflight_open':
				return $this->open_zip_phase( $job, $results );

			case 'preflighting':
				return $this->preflight_phase( $job, $results, $deadline );

			case 'extracting':
				return $this->extract_phase( $job, $results, $deadline );

			case 'validating_tree':
				return $this->validate_tree_phase( $job, $results, $deadline );

			case 'indexing_discover':
				return $this->discover_phase( $job, $results, $deadline );

			case 'indexing_entries':
				return $this->index_phase( $job, $results, $deadline );

			case 'importing':
				return $this->import_phase( $job, $results, $deadline );

			case 'cleanup':
				return $this->cleanup_phase( $job, $results );

			case 'done':
				$job['status'] = Day_One_Importer_Job_State::STATUS_COMPLETED;
				$this->save_job( $job, $results );
				return false;
		}

		$this->fail_job( $job, $results, __( 'The import job is in an unknown phase.', 'day-one-importer' ) );
		return false;
	}

	/**
	 * Initialize ZIP counters.
	 *
	 * @param array<string,mixed>      $job Job.
	 * @param Day_One_Importer_Results $results Results.
	 * @return bool
	 */
	private function open_zip_phase( &$job, Day_One_Importer_Results $results ) {
		$total = Day_One_Importer_Cleanup::zip_total( isset( $job['zip_path'] ) ? (string) $job['zip_path'] : '' );
		if ( ! is_int( $total ) ) {
			$this->fail_job( $job, $results, (string) $total );
			return false;
		}

		if ( ! Day_One_Importer_Cleanup::initialize_extract_directory( isset( $job['extract_dir'] ) ? (string) $job['extract_dir'] : '' ) ) {
			$this->fail_job( $job, $results, __( 'The extraction directory could not be prepared.', 'day-one-importer' ) );
			return false;
		}

		$job['zip_total']     = $total;
		$job['extract_total'] = $total;
		$job['zip_index']     = isset( $job['zip_index'] ) ? (int) $job['zip_index'] : 0;
		$job['extract_index'] = isset( $job['extract_index'] ) ? (int) $job['extract_index'] : 0;
		$job['phase']         = 'preflighting';
		$this->save_job( $job, $results );

		return true;
	}

	/**
	 * Preflight ZIP members.
	 *
	 * @param array<string,mixed>      $job Job.
	 * @param Day_One_Importer_Results $results Results.
	 * @param float                    $deadline Deadline.
	 * @return bool
	 */
	private function preflight_phase( &$job, Day_One_Importer_Results $results, $deadline ) {
		$batch = Day_One_Importer_Cleanup::preflight_zip_batch(
			(string) $job['zip_path'],
			isset( $job['zip_index'] ) ? (int) $job['zip_index'] : 0,
			Day_One_Importer_Job_State::batch_zip_limit(),
			$deadline
		);

		$job['zip_index'] = (int) $batch['next_index'];
		$job['zip_total'] = (int) $batch['total'];
		if ( ! empty( $batch['json_candidates'] ) && is_array( $batch['json_candidates'] ) ) {
			$existing                   = isset( $job['zip_json_candidates'] ) && is_array( $job['zip_json_candidates'] ) ? $job['zip_json_candidates'] : array();
			$job['zip_json_candidates'] = array_values( array_unique( array_merge( $existing, $batch['json_candidates'] ) ) );
		}
		if ( ! empty( $batch['photo_dirs'] ) && is_array( $batch['photo_dirs'] ) ) {
			$existing              = isset( $job['zip_photo_dirs'] ) && is_array( $job['zip_photo_dirs'] ) ? $job['zip_photo_dirs'] : array();
			$job['zip_photo_dirs'] = array_values( array_unique( array_merge( $existing, $batch['photo_dirs'] ) ) );
		}
		if ( ! empty( $batch['error'] ) ) {
			$this->fail_job( $job, $results, (string) $batch['error'] );
			return false;
		}

		if ( ! empty( $batch['done'] ) ) {
			$job['zip_preflight_done'] = true;
			$job['phase']              = 'extracting';
			$this->save_job( $job, $results );
			return true;
		}

		$this->save_job( $job, $results );
		return false;
	}

	/**
	 * Extract ZIP members.
	 *
	 * @param array<string,mixed>      $job Job.
	 * @param Day_One_Importer_Results $results Results.
	 * @param float                    $deadline Deadline.
	 * @return bool
	 */
	private function extract_phase( &$job, Day_One_Importer_Results $results, $deadline ) {
		$batch = Day_One_Importer_Cleanup::extract_zip_batch(
			(string) $job['zip_path'],
			(string) $job['extract_dir'],
			isset( $job['extract_index'] ) ? (int) $job['extract_index'] : 0,
			Day_One_Importer_Job_State::batch_zip_limit(),
			$deadline
		);

		$job['extract_index'] = (int) $batch['next_index'];
		$job['extract_total'] = (int) $batch['total'];
		if ( ! empty( $batch['error'] ) ) {
			$this->fail_job( $job, $results, (string) $batch['error'] );
			return false;
		}

		if ( ! empty( $batch['done'] ) ) {
			$job['phase'] = 'validating_tree';
			$this->save_job( $job, $results );
			return true;
		}

		$this->save_job( $job, $results );
		return false;
	}

	/**
	 * Validate extracted tree.
	 *
	 * @param array<string,mixed>      $job Job.
	 * @param Day_One_Importer_Results $results Results.
	 * @param float|null               $deadline Deadline timestamp.
	 * @return bool
	 */
	private function validate_tree_phase( &$job, Day_One_Importer_Results $results, $deadline = null ) {
		$deadline = null === $deadline ? 1.0E+30 : (float) $deadline;
		$batch    = Day_One_Importer_Cleanup::validate_extracted_tree_batch( (string) $job['extract_dir'], $job, Day_One_Importer_Job_State::batch_zip_limit(), $deadline );
		if ( ! empty( $batch['error'] ) ) {
			$this->fail_job( $job, $results, (string) $batch['error'] );
			return false;
		}

		if ( empty( $batch['done'] ) ) {
			$this->save_job( $job, $results );
			return false;
		}

		$job['phase'] = 'indexing_discover';
		$this->save_job( $job, $results );
		return true;
	}

	/**
	 * Discover JSON files.
	 *
	 * @param array<string,mixed>      $job Job.
	 * @param Day_One_Importer_Results $results Results.
	 * @param float                    $deadline Deadline.
	 * @return bool
	 */
	private function discover_phase( &$job, Day_One_Importer_Results $results, $deadline ) {
		$parser = new Day_One_Importer_Parser();
		$batch  = $parser->discover_json_files_batch( (string) $job['extract_dir'], $job, $results, $deadline, $this->checkpoint_callback() );
		if ( ! empty( $batch['error'] ) ) {
			$this->fail_job( $job, $results, (string) $batch['error'] );
			return false;
		}

		if ( empty( $batch['done'] ) ) {
			$this->save_job( $job, $results );
			return false;
		}

		if ( 0 === (int) $job['json_files_found'] ) {
			$this->fail_job( $job, $results, __( 'No Day One journal JSON file with an entries array was found in the archive.', 'day-one-importer' ) );
			return false;
		}

		$job['phase'] = 'indexing_entries';
		$this->save_job( $job, $results );
		return true;
	}

	/**
	 * Index entries into the manifest.
	 *
	 * @param array<string,mixed>      $job Job.
	 * @param Day_One_Importer_Results $results Results.
	 * @param float                    $deadline Deadline.
	 * @return bool
	 */
	private function index_phase( &$job, Day_One_Importer_Results $results, $deadline ) {
		$parser = new Day_One_Importer_Parser();
		$batch  = $parser->index_export_batch( (string) $job['extract_dir'], $job, $results, $deadline, $this->checkpoint_callback() );
		if ( ! empty( $batch['error'] ) ) {
			$this->fail_job( $job, $results, (string) $batch['error'] );
			return false;
		}

		if ( ! empty( $batch['done'] ) ) {
			if ( 0 === (int) $job['entries_total'] ) {
				if ( 0 === $results->get_count( 'json_files_found' ) ) {
					$this->fail_job( $job, $results, __( 'No Day One journal JSON file with an entries array was found in the archive.', 'day-one-importer' ) );
				} else {
					$this->fail_job( $job, $results, __( 'No importable Day One entries were found in the archive.', 'day-one-importer' ) );
				}
				return false;
			}
			$job['phase']       = 'importing';
			$job['entry_index'] = isset( $job['entry_index'] ) ? (int) $job['entry_index'] : 0;
			$this->save_job( $job, $results );
			return true;
		}

		$this->save_job( $job, $results );
		return false;
	}

	/**
	 * Import entries and media from the manifest.
	 *
	 * @param array<string,mixed>      $job Job.
	 * @param Day_One_Importer_Results $results Results.
	 * @param float                    $deadline Deadline.
	 * @return bool
	 */
	private function import_phase( &$job, Day_One_Importer_Results $results, $deadline ) {
		$parser       = new Day_One_Importer_Parser();
		$runner       = new Day_One_Importer_Runner();
		$entry_limit  = Day_One_Importer_Job_State::batch_entry_limit();
		$entries_done = 0;

		while ( (int) $job['entry_index'] < (int) $job['entries_total'] ) {
			if ( $entries_done >= $entry_limit || Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) ) {
				$this->save_job( $job, $results );
				return false;
			}

			$entry = $parser->read_manifest_entry( (string) $job['manifest_path'], (int) $job['entry_index'] );
			if ( ! is_array( $entry ) ) {
				$results->increment( 'entries_failed' );
				$this->clear_current_entry_state( $job );
				$job['entry_index'] = (int) $job['entry_index'] + 1;
				++$entries_done;
				$this->save_job( $job, $results );
				continue;
			}

			$uuid = isset( $entry['uuid'] ) ? (string) $entry['uuid'] : '';
			if ( ( isset( $job['current_entry_uuid'] ) ? (string) $job['current_entry_uuid'] : '' ) !== $uuid ) {
				$this->clear_current_entry_state( $job );
				$job['current_entry_uuid'] = $uuid;
			}

			if ( empty( $job['current_entry_post_prepared'] ) ) {
				$owner_user_id = isset( $job['owner_user_id'] ) ? absint( $job['owner_user_id'] ) : 0;
				$prepared      = $runner->prepare_imported_entry_post( $entry, $results, $owner_user_id );
				if ( 'ready' === $prepared['status'] ) {
					$job['current_post_id']             = (int) $prepared['post_id'];
					$job['current_entry_post_prepared'] = true;
					$this->save_job( $job, $results );
				} else {
					$this->clear_current_entry_state( $job );
					$job['entry_index'] = (int) $job['entry_index'] + 1;
					++$entries_done;
					$this->save_job( $job, $results );
					continue;
				}
			}

			if ( empty( $job['current_entry_media_complete'] ) ) {
				$this->renew_current_lock( $job );
				$complete = $runner->import_entry_media_batch( $entry, (string) $job['extract_dir'], (int) $job['current_post_id'], $job, $deadline, $results, $this->checkpoint_callback() );
				$this->save_job( $job, $results );
				if ( ! $complete ) {
					return false;
				}
			}

			if ( empty( $job['current_entry_content_appended'] ) ) {
				$finalized = $runner->finalize_imported_entry( $entry, (int) $job['current_post_id'], isset( $job['current_attachment_ids'] ) ? $job['current_attachment_ids'] : array(), $results );
				if ( ! $finalized ) {
					$this->fail_job( $job, $results, __( 'The import could not finalize an entry after importing media. Retry is safe.', 'day-one-importer' ) );
					return false;
				}
				$job['current_entry_content_appended'] = true;
				$this->save_job( $job, $results );
			}

			$this->clear_current_entry_state( $job );
			$job['entry_index'] = (int) $job['entry_index'] + 1;
			++$entries_done;
			$this->save_job( $job, $results );
		}

		$job['phase'] = 'cleanup';
		$this->save_job( $job, $results );
		return true;
	}

	/**
	 * Cleanup temporary files and mark complete.
	 *
	 * @param array<string,mixed>      $job Job.
	 * @param Day_One_Importer_Results $results Results.
	 * @return bool
	 */
	private function cleanup_phase( &$job, Day_One_Importer_Results $results ) {
		if ( empty( $job['files_cleaned'] ) && ! empty( $job['run_dir'] ) ) {
			Day_One_Importer_Cleanup::remove( (string) $job['run_dir'] );
		}

		$job['files_cleaned'] = true;
		$job['phase']         = 'done';
		$job['status']        = Day_One_Importer_Job_State::STATUS_COMPLETED;
		$this->save_job( $job, $results );
		return false;
	}

	/**
	 * Mark a job failed with a safe error.
	 *
	 * @param array<string,mixed>      $job Job.
	 * @param Day_One_Importer_Results $results Results.
	 * @param string                   $message Error message.
	 * @return void
	 */
	private function fail_job( &$job, Day_One_Importer_Results $results, $message ) {
		$results->add_error( $message );
		$job['status'] = Day_One_Importer_Job_State::STATUS_FAILED;
		$this->save_job( $job, $results );
	}

	/**
	 * Save job and results together.
	 *
	 * @param array<string,mixed>      $job Job.
	 * @param Day_One_Importer_Results $results Results.
	 * @return void
	 */
	private function save_job( &$job, Day_One_Importer_Results $results ) {
		$job['results'] = $results->to_array();
		$this->store->save_job( $job );
		$this->renew_current_lock( $job );
	}

	/**
	 * Build a checkpoint callback for parser/runner safe units.
	 *
	 * @return callable
	 */
	private function checkpoint_callback() {
		return function ( &$job, Day_One_Importer_Results $results ) {
			$this->save_job( $job, $results );
		};
	}

	/**
	 * Renew the current lock if this processor owns one.
	 *
	 * @param array<string,mixed> $job Job state.
	 * @return void
	 */
	private function renew_current_lock( $job ) {
		if ( '' === $this->current_lock_token || empty( $job['id'] ) ) {
			return;
		}

		$this->store->renew_lock( $job['id'], $this->current_lock_token, $this->current_lock_ttl );
	}

	/**
	 * Compute lock TTL with enough slack for one non-interruptible WordPress call.
	 *
	 * @param float $budget Batch budget.
	 * @return int
	 */
	private function lock_ttl( $budget ) {
		$default = max( 120, (int) ceil( (float) $budget ) + 60 );
		$value   = function_exists( 'apply_filters' ) ? apply_filters( 'day_one_importer_job_lock_ttl', $default, $budget ) : $default;

		return max( 30, (int) $value );
	}

	/**
	 * Clear per-entry cursors.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return void
	 */
	private function clear_current_entry_state( &$job ) {
		$job['current_entry_uuid']             = '';
		$job['current_post_id']                = 0;
		$job['current_media_index']            = 0;
		$job['current_media_total']            = 0;
		$job['current_attachment_ids']         = array();
		$job['current_entry_post_prepared']    = false;
		$job['current_entry_media_complete']   = false;
		$job['current_entry_media_counted']    = false;
		$job['current_entry_content_appended'] = false;
	}
}
