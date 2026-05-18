<?php
/**
 * Import result object.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks counters and bounded warning/error details for one import run.
 */
class Day_One_Importer_Results {
	/**
	 * Maximum detail messages retained for admin output.
	 *
	 * @var int
	 */
	const MAX_DETAILS = 50;

	/**
	 * Counters.
	 *
	 * @var array<string,int>
	 */
	private $counts = array(
		'json_files_found'    => 0,
		'entries_found'       => 0,
		'posts_created'       => 0,
		'posts_skipped'       => 0,
		'posts_resumed'       => 0,
		'entries_failed'      => 0,
		'tags_assigned'       => 0,
		'categories_assigned' => 0,
		'media_found'         => 0,
		'media_imported'      => 0,
		'media_reused'        => 0,
		'media_missing'       => 0,
		'media_failed'        => 0,
		'media_unsupported'   => 0,
	);

	/**
	 * Warning details.
	 *
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * Error details.
	 *
	 * @var string[]
	 */
	private $errors = array();

	/**
	 * Number of suppressed warnings.
	 *
	 * @var int
	 */
	private $warnings_suppressed = 0;

	/**
	 * Number of suppressed errors.
	 *
	 * @var int
	 */
	private $errors_suppressed = 0;

	/**
	 * Increment a counter.
	 *
	 * @param string $key Counter key.
	 * @param int    $amount Amount.
	 * @return void
	 */
	public function increment( $key, $amount = 1 ) {
		if ( ! isset( $this->counts[ $key ] ) ) {
			$this->counts[ $key ] = 0;
		}

		$this->counts[ $key ] += (int) $amount;
	}

	/**
	 * Set a counter.
	 *
	 * @param string $key Counter key.
	 * @param int    $value Value.
	 * @return void
	 */
	public function set_count( $key, $value ) {
		$this->counts[ $key ] = max( 0, (int) $value );
	}

	/**
	 * Get all counters.
	 *
	 * @return array<string,int>
	 */
	public function get_counts() {
		return $this->counts;
	}

	/**
	 * Get a counter.
	 *
	 * @param string $key Counter key.
	 * @return int
	 */
	public function get_count( $key ) {
		return isset( $this->counts[ $key ] ) ? (int) $this->counts[ $key ] : 0;
	}

	/**
	 * Add a privacy-safe warning.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function add_warning( $message ) {
		if ( count( $this->warnings ) >= self::MAX_DETAILS ) {
			++$this->warnings_suppressed;
			return;
		}

		$this->warnings[] = $this->sanitize_detail( $message );
	}

	/**
	 * Add a privacy-safe error.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public function add_error( $message ) {
		if ( count( $this->errors ) >= self::MAX_DETAILS ) {
			++$this->errors_suppressed;
			return;
		}

		$this->errors[] = $this->sanitize_detail( $message );
	}

	/**
	 * Whether the run has fatal/import-level errors.
	 *
	 * @return bool
	 */
	public function has_errors() {
		return ! empty( $this->errors );
	}

	/**
	 * Whether the run has warning details or suppressed warnings.
	 *
	 * @return bool
	 */
	public function has_warnings() {
		return ! empty( $this->warnings ) || $this->warnings_suppressed > 0;
	}

	/**
	 * Get warnings.
	 *
	 * @return string[]
	 */
	public function get_warnings() {
		$warnings = $this->warnings;
		if ( $this->warnings_suppressed > 0 ) {
			$warnings[] = sprintf(
				/* translators: %d: number of warnings. */
				function_exists( '__' ) ? __( '%d additional warnings were suppressed.', 'day-one-importer' ) : '%d additional warnings were suppressed.',
				$this->warnings_suppressed
			);
		}

		return $warnings;
	}

	/**
	 * Get errors.
	 *
	 * @return string[]
	 */
	public function get_errors() {
		$errors = $this->errors;
		if ( $this->errors_suppressed > 0 ) {
			$errors[] = sprintf(
				/* translators: %d: number of errors. */
				function_exists( '__' ) ? __( '%d additional errors were suppressed.', 'day-one-importer' ) : '%d additional errors were suppressed.',
				$this->errors_suppressed
			);
		}

		return $errors;
	}

	/**
	 * Serialize results for persisted import jobs.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array() {
		return array(
			'counts'              => $this->counts,
			'warnings'            => $this->warnings,
			'errors'              => $this->errors,
			'warnings_suppressed' => $this->warnings_suppressed,
			'errors_suppressed'   => $this->errors_suppressed,
		);
	}

	/**
	 * Rehydrate results from persisted job state.
	 *
	 * @param mixed $data Serialized results.
	 * @return Day_One_Importer_Results
	 */
	public static function from_array( $data ) {
		$results = new self();
		if ( ! is_array( $data ) ) {
			return $results;
		}

		if ( isset( $data['counts'] ) && is_array( $data['counts'] ) ) {
			foreach ( $data['counts'] as $key => $value ) {
				if ( is_scalar( $key ) ) {
					$results->counts[ (string) $key ] = max( 0, (int) $value );
				}
			}
		}

		foreach ( array( 'warnings', 'errors' ) as $detail_key ) {
			if ( ! isset( $data[ $detail_key ] ) || ! is_array( $data[ $detail_key ] ) ) {
				continue;
			}

			$details = array();
			foreach ( $data[ $detail_key ] as $message ) {
				if ( count( $details ) >= self::MAX_DETAILS ) {
					break;
				}
				$details[] = day_one_importer_sanitize_text( $message );
			}
			$results->{$detail_key} = $details;
		}

		$results->warnings_suppressed = isset( $data['warnings_suppressed'] ) ? max( 0, (int) $data['warnings_suppressed'] ) : 0;
		$results->errors_suppressed   = isset( $data['errors_suppressed'] ) ? max( 0, (int) $data['errors_suppressed'] ) : 0;

		return $results;
	}

	/**
	 * Convert WP_Error or mixed values to safe messages.
	 *
	 * @param mixed  $error Error value.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	public static function error_to_message( $error, $fallback ) {
		if ( is_object( $error ) && method_exists( $error, 'get_error_message' ) ) {
			$message = $error->get_error_message();
			return $message ? $message : $fallback;
		}

		if ( is_scalar( $error ) && '' !== (string) $error ) {
			return (string) $error;
		}

		return $fallback;
	}

	/**
	 * Sanitize detail text.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private function sanitize_detail( $message ) {
		return day_one_importer_sanitize_text( $message );
	}
}
