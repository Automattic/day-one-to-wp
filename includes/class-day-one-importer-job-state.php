<?php
/**
 * Pure helpers for persisted Day One import jobs.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'DAY_ONE_IMPORTER_TESTING' ) ) {
	exit;
}

/**
 * Validates and shapes import job state without WordPress side effects.
 */
class Day_One_Importer_Job_State {
	/** Import has been queued but not yet advanced. */
	const STATUS_QUEUED = 'queued';
	/** Import is actively being processed by short requests. */
	const STATUS_RUNNING = 'running';
	/** Import stopped with a retryable failure. */
	const STATUS_FAILED = 'failed';
	/** Import completed successfully. */
	const STATUS_COMPLETED = 'completed';
	/** Import was canceled/abandoned. */
	const STATUS_CANCELED = 'canceled';

	/**
	 * Return allowed statuses.
	 *
	 * @return string[]
	 */
	public static function allowed_statuses() {
		return array(
			self::STATUS_QUEUED,
			self::STATUS_RUNNING,
			self::STATUS_FAILED,
			self::STATUS_COMPLETED,
			self::STATUS_CANCELED,
		);
	}

	/**
	 * Return allowed phases.
	 *
	 * @return string[]
	 */
	public static function allowed_phases() {
		return array(
			'uploaded',
			'preflight_open',
			'preflighting',
			'extracting',
			'validating_tree',
			'indexing_discover',
			'indexing_entries',
			'importing',
			'cleanup',
			'done',
		);
	}

	/**
	 * Normalize a status.
	 *
	 * @param mixed $status Status.
	 * @return string
	 */
	public static function normalize_status( $status ) {
		$status = is_scalar( $status ) ? (string) $status : '';
		return in_array( $status, self::allowed_statuses(), true ) ? $status : self::STATUS_QUEUED;
	}

	/**
	 * Normalize a phase.
	 *
	 * @param mixed $phase Phase.
	 * @return string
	 */
	public static function normalize_phase( $phase ) {
		$phase = is_scalar( $phase ) ? (string) $phase : '';
		return in_array( $phase, self::allowed_phases(), true ) ? $phase : 'uploaded';
	}

	/**
	 * Determine whether a status is terminal.
	 *
	 * @param mixed $status Status.
	 * @return bool
	 */
	public static function is_terminal_status( $status ) {
		$status = self::normalize_status( $status );
		return self::STATUS_COMPLETED === $status || self::STATUS_CANCELED === $status;
	}

	/**
	 * Determine whether a job can be retried/continued by the user.
	 *
	 * @param mixed $status Status.
	 * @return bool
	 */
	public static function is_retryable_status( $status ) {
		$status = self::normalize_status( $status );
		return in_array( $status, array( self::STATUS_QUEUED, self::STATUS_FAILED ), true );
	}

	/**
	 * Return a monotonic-ish timestamp for deadline calculations.
	 *
	 * @return float
	 */
	public static function now() {
		return microtime( true );
	}

	/**
	 * Build a deadline from a budget in seconds.
	 *
	 * @param mixed      $budget_seconds Seconds.
	 * @param float|null $start Start time.
	 * @return float
	 */
	public static function deadline_from_budget( $budget_seconds, $start = null ) {
		$budget_seconds = max( 1.0, (float) $budget_seconds );
		$start          = null === $start ? self::now() : (float) $start;

		return $start + $budget_seconds;
	}

	/**
	 * Whether another unit of work should be deferred to the next request.
	 *
	 * @param float $deadline Deadline timestamp.
	 * @param float $reserve_seconds Safety reserve.
	 * @return bool
	 */
	public static function should_pause_for_deadline( $deadline, $reserve_seconds = 0.5 ) {
		return self::now() >= ( (float) $deadline - max( 0.0, (float) $reserve_seconds ) );
	}

	/**
	 * Get the per-request time budget.
	 *
	 * @param string $source Source label.
	 * @return float
	 */
	public static function batch_time_budget( $source = 'ajax' ) {
		$default = 'cron' === $source ? 8 : 10;
		$budget  = function_exists( 'apply_filters' ) ? apply_filters( 'day_one_importer_batch_time_budget', $default, $source ) : $default;

		return max( 2.0, (float) $budget );
	}

	/**
	 * Get bounded entry limit per processor request.
	 *
	 * @return int
	 */
	public static function batch_entry_limit() {
		$value = function_exists( 'apply_filters' ) ? apply_filters( 'day_one_importer_batch_entry_limit', 1 ) : 1;
		return self::positive_int( $value, 1 );
	}

	/**
	 * Get bounded media limit per processor request.
	 *
	 * @return int
	 */
	public static function batch_media_limit() {
		$value = function_exists( 'apply_filters' ) ? apply_filters( 'day_one_importer_batch_media_limit', 1 ) : 1;
		return self::positive_int( $value, 1 );
	}

	/**
	 * Get bounded ZIP member limit per processor request.
	 *
	 * @return int
	 */
	public static function batch_zip_limit() {
		$value = function_exists( 'apply_filters' ) ? apply_filters( 'day_one_importer_batch_zip_limit', 100 ) : 100;
		return self::positive_int( $value, 100 );
	}

	/**
	 * Get bounded JSON entry indexing limit per processor request.
	 *
	 * @return int
	 */
	public static function batch_index_entry_limit() {
		$value = function_exists( 'apply_filters' ) ? apply_filters( 'day_one_importer_batch_index_entry_limit', 100 ) : 100;
		return self::positive_int( $value, 100 );
	}

	/**
	 * Shape a privacy-safe status response for AJAX/cron/admin display.
	 *
	 * @param array<string,mixed> $job Job state.
	 * @param bool                $busy Whether another worker owns the lock.
	 * @return array<string,mixed>
	 */
	public static function status_response( $job, $busy = false ) {
		$job     = is_array( $job ) ? $job : array();
		$status  = self::normalize_status( isset( $job['status'] ) ? $job['status'] : '' );
		$phase   = self::normalize_phase( isset( $job['phase'] ) ? $job['phase'] : '' );
		$results = Day_One_Importer_Results::from_array( isset( $job['results'] ) ? $job['results'] : array() );

		$response = array(
			'job_id'           => isset( $job['id'] ) && is_scalar( $job['id'] ) ? day_one_importer_sanitize_text( $job['id'] ) : '',
			'status'           => $status,
			'phase'            => $phase,
			'phase_label'      => self::STATUS_CANCELED === $status ? '' : self::phase_label( $phase ),
			'busy'             => (bool) $busy,
			'is_terminal'      => self::is_terminal_status( $status ),
			'can_retry'        => self::is_retryable_status( $status ),
			'created_at'       => isset( $job['created_at'] ) ? (int) $job['created_at'] : 0,
			'updated_at'       => isset( $job['updated_at'] ) ? (int) $job['updated_at'] : 0,
			'expires_at'       => isset( $job['expires_at'] ) ? (int) $job['expires_at'] : 0,
			'counts'           => array_map( 'intval', $results->get_counts() ),
			'warnings'         => array_values( array_map( array( __CLASS__, 'sanitize_status_detail' ), $results->get_warnings() ) ),
			'errors'           => array_values( array_map( array( __CLASS__, 'sanitize_status_detail' ), $results->get_errors() ) ),
			'progress'         => self::progress_response( $job ),
			'progress_percent' => self::progress_percent( $job, $status, $phase ),
			'message'          => self::status_message( $status, $phase, (bool) $busy ),
		);

		return $response;
	}

	/**
	 * Sanitize a detail message for status responses.
	 *
	 * @param mixed $message Message.
	 * @return string
	 */
	public static function sanitize_status_detail( $message ) {
		$message = day_one_importer_sanitize_text( $message );
		$message = preg_replace( '#(?:[A-Za-z]:)?[\\/][^\s<>{}]+#', '[redacted path]', $message );

		return trim( (string) $message );
	}

	/**
	 * Get a human-readable phase label.
	 *
	 * @param string $phase Phase.
	 * @return string
	 */
	public static function phase_label( $phase ) {
		$labels = array(
			'uploaded'           => function_exists( '__' ) ? __( 'Queued', 'day-one-importer' ) : 'Queued',
			'preflight_open'     => function_exists( '__' ) ? __( 'Opening ZIP', 'day-one-importer' ) : 'Opening ZIP',
			'preflighting'       => function_exists( '__' ) ? __( 'Checking ZIP contents', 'day-one-importer' ) : 'Checking ZIP contents',
			'extracting'         => function_exists( '__' ) ? __( 'Extracting ZIP', 'day-one-importer' ) : 'Extracting ZIP',
			'validating_tree'    => function_exists( '__' ) ? __( 'Validating extracted files', 'day-one-importer' ) : 'Validating extracted files',
			'indexing_discover'  => function_exists( '__' ) ? __( 'Finding journal JSON files', 'day-one-importer' ) : 'Finding journal JSON files',
			'indexing_entries'   => function_exists( '__' ) ? __( 'Indexing entries', 'day-one-importer' ) : 'Indexing entries',
			'importing'          => function_exists( '__' ) ? __( 'Importing entries and media', 'day-one-importer' ) : 'Importing entries and media',
			'cleanup'            => function_exists( '__' ) ? __( 'Cleaning up temporary files', 'day-one-importer' ) : 'Cleaning up temporary files',
			'done'               => function_exists( '__' ) ? __( 'Done', 'day-one-importer' ) : 'Done',
		);

		$phase = self::normalize_phase( $phase );
		return isset( $labels[ $phase ] ) ? $labels[ $phase ] : $phase;
	}

	/**
	 * Make a progress object from safe cursor fields.
	 *
	 * @param array<string,mixed> $job Job state.
	 * @return array<string,int>
	 */
	private static function progress_response( $job ) {
		$keys     = array(
			'zip_index',
			'zip_total',
			'extract_index',
			'extract_total',
			'json_file_index',
			'json_files_found',
			'json_entry_index',
			'entries_total',
			'entry_index',
			'current_media_index',
			'current_media_total',
		);
		$progress = array();
		foreach ( $keys as $key ) {
			$progress[ $key ] = isset( $job[ $key ] ) ? max( 0, (int) $job[ $key ] ) : 0;
		}

		return $progress;
	}

	/**
	 * Estimate overall import completion for admin progress display.
	 *
	 * @param array<string,mixed> $job Job state.
	 * @param string              $status Normalized status.
	 * @param string              $phase Normalized phase.
	 * @return int Percentage from 0 to 100.
	 */
	private static function progress_percent( $job, $status, $phase ) {
		if ( self::STATUS_COMPLETED === $status ) {
			return 100;
		}

		if ( 'done' === $phase ) {
			return 100;
		}

		$progress = self::progress_response( $job );

		// Calibration constants: pre-importing ceiling (C), importing-end (E), cleanup (K).
		$pre_importing_ceiling = 6;
		$importing_end         = 96;
		$cleanup_value         = 99;
		$importing_span        = $importing_end - $pre_importing_ceiling;

		$percent = 0;

		switch ( $phase ) {
			case 'uploaded':
				$percent = 0;
				break;
			case 'preflight_open':
				$percent = 1;
				break;
			case 'preflighting':
				$percent = 1 + self::fraction_percent( $progress['zip_index'], $progress['zip_total'], 1 );
				break;
			case 'extracting':
				$percent = 2 + self::fraction_percent( $progress['extract_index'], $progress['extract_total'], 1 );
				break;
			case 'validating_tree':
				$percent = 3;
				break;
			case 'indexing_discover':
				$percent = 4;
				break;
			case 'indexing_entries':
				$percent = 4 + self::fraction_percent( $progress['json_file_index'], $progress['json_files_found'], 2 );
				break;
			case 'importing':
				$entries_total = (int) $progress['entries_total'];
				if ( $entries_total <= 0 ) {
					$percent = $pre_importing_ceiling;
				} else {
					$percent = $pre_importing_ceiling + self::fraction_percent( $progress['entry_index'], $entries_total, $importing_span );

					// Optional media smoothing within the current entry's slot.
					$current_media_total = (int) $progress['current_media_total'];
					if ( $current_media_total > 0 ) {
						$slot_width = (int) floor( $importing_span / $entries_total );
						if ( $slot_width > 0 ) {
							$percent += self::fraction_percent( $progress['current_media_index'], $current_media_total, $slot_width );
						}
					}
				}
				break;
			case 'cleanup':
				$percent = $cleanup_value;
				break;
		}

		return max( 0, min( 100, (int) $percent ) );
	}

	/**
	 * Convert a cursor pair to a bounded percentage span.
	 *
	 * @param int $current Current cursor.
	 * @param int $total Total units.
	 * @param int $span Percentage points allocated to the phase.
	 * @return int Percentage points within the span.
	 */
	private static function fraction_percent( $current, $total, $span ) {
		$total = max( 0, (int) $total );
		if ( 0 === $total ) {
			return 0;
		}

		$current = max( 0, min( (int) $current, $total ) );
		return (int) floor( ( $current / $total ) * max( 0, (int) $span ) );
	}

	/**
	 * Build a short status message.
	 *
	 * @param string $status Status.
	 * @param string $phase Phase.
	 * @param bool   $busy Busy flag.
	 * @return string
	 */
	private static function status_message( $status, $phase, $busy ) {
		if ( $busy ) {
			return function_exists( '__' ) ? __( 'Another import request is currently processing this job.', 'day-one-importer' ) : 'Another import request is currently processing this job.';
		}

		if ( self::STATUS_COMPLETED === $status ) {
			return function_exists( '__' ) ? __( 'Import complete.', 'day-one-importer' ) : 'Import complete.';
		}

		if ( self::STATUS_FAILED === $status ) {
			return function_exists( '__' ) ? __( 'Import paused after an error. Retry is safe.', 'day-one-importer' ) : 'Import paused after an error. Retry is safe.';
		}

		if ( self::STATUS_CANCELED === $status ) {
			return function_exists( '__' ) ? __( 'Import canceled.', 'day-one-importer' ) : 'Import canceled.';
		}

		return sprintf(
			/* translators: %s: current import phase. */
			function_exists( '__' ) ? __( 'Import running: %s.', 'day-one-importer' ) : 'Import running: %s.',
			self::phase_label( $phase )
		);
	}

	/**
	 * Normalize a positive integer.
	 *
	 * @param mixed $value Value.
	 * @param int   $default Default.
	 * @return int
	 */
	private static function positive_int( $value, $default ) {
		$value = (int) $value;
		return $value > 0 ? $value : (int) $default;
	}
}
