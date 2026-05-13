<?php
/**
 * Persisted job storage and atomic locks for Day One imports.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'DAY_ONE_IMPORTER_TESTING' ) ) {
	exit;
}

/**
 * Stores import job state in non-autoloaded options and coordinates workers.
 */
class Day_One_Importer_Job_Store {
	/** Job option prefix. */
	const JOB_PREFIX = 'day_one_importer_job_';
	/** Lock option prefix. */
	const LOCK_PREFIX = 'day_one_importer_job_lock_';
	/** Per-user active job pointer prefix. */
	const USER_ACTIVE_PREFIX = 'day_one_importer_user_active_';
	/** Cron hook used to advance jobs. */
	const CRON_HOOK = 'day_one_importer_process_job';

	/**
	 * In-memory option fallback used by pure tests.
	 *
	 * @var array<string,mixed>
	 */
	private static $test_options = array();

	/**
	 * Create a persisted import job from an uploaded ZIP in a protected run dir.
	 *
	 * @param int                            $owner_user_id Owner user ID.
	 * @param string                         $run_dir Protected run directory.
	 * @param string                         $zip_path Protected ZIP path.
	 * @param Day_One_Importer_Results|null  $results Initial results.
	 * @return array<string,mixed>|false Job state, or false.
	 */
	public function create_job( $owner_user_id, $run_dir, $zip_path, $results = null ) {
		$owner_user_id = (int) $owner_user_id;
		$run_dir       = (string) $run_dir;
		$zip_path      = (string) $zip_path;
		if ( $owner_user_id <= 0 || '' === $run_dir || '' === $zip_path ) {
			return false;
		}

		$now     = time();
		$job_id  = $this->generate_job_id();
		$results = $results instanceof Day_One_Importer_Results ? $results : new Day_One_Importer_Results();
		$job     = array(
			'id'                          => $job_id,
			'owner_user_id'               => $owner_user_id,
			'created_at'                  => $now,
			'updated_at'                  => $now,
			'expires_at'                  => $now + $this->retention_seconds(),
			'status'                      => Day_One_Importer_Job_State::STATUS_QUEUED,
			'phase'                       => 'uploaded',
			'run_dir'                     => $run_dir,
			'zip_path'                    => $zip_path,
			'extract_dir'                 => trailingslashit( $run_dir ) . 'extract',
			'manifest_path'               => trailingslashit( $run_dir ) . 'entries.jsonl',
			'entries_jsonl_path'          => trailingslashit( $run_dir ) . 'entries.jsonl',
			'zip_index'                   => 0,
			'zip_total'                   => 0,
			'zip_preflight_done'          => false,
			'zip_json_candidates'         => array(),
			'zip_photo_dirs'              => array(),
			'extract_index'               => 0,
			'extract_total'               => 0,
			'json_files'                  => array(),
			'json_files_found'            => 0,
			'photo_dirs'                  => array(),
			'json_file_index'             => 0,
			'json_entry_index'            => 0,
			'json_discovery_done'         => false,
			'seen_uuids'                  => array(),
			'entries_total'               => 0,
			'entry_index'                 => 0,
			'current_entry_uuid'          => '',
			'current_post_id'             => 0,
			'current_media_index'         => 0,
			'current_media_total'         => 0,
			'current_attachment_ids'      => array(),
			'current_entry_post_prepared' => false,
			'current_entry_media_complete'=> false,
			'current_entry_media_counted' => false,
			'current_entry_content_appended' => false,
			'files_cleaned'               => false,
			'results'                     => $results->to_array(),
		);

		if ( ! self::option_add( self::JOB_PREFIX . $job_id, $job ) ) {
			return false;
		}

		$this->set_user_active_job( $owner_user_id, $job_id );
		$this->schedule_job( $job_id );

		return $job;
	}

	/**
	 * Get a job by ID.
	 *
	 * @param string $job_id Job ID.
	 * @return array<string,mixed>|null
	 */
	public function get_job( $job_id ) {
		$job_id = self::sanitize_job_id( $job_id );
		if ( '' === $job_id ) {
			return null;
		}

		$job = self::option_get( self::JOB_PREFIX . $job_id );
		if ( ! is_array( $job ) ) {
			return null;
		}

		$job['id']     = isset( $job['id'] ) ? self::sanitize_job_id( $job['id'] ) : $job_id;
		$job['status'] = Day_One_Importer_Job_State::normalize_status( isset( $job['status'] ) ? $job['status'] : '' );
		$job['phase']  = Day_One_Importer_Job_State::normalize_phase( isset( $job['phase'] ) ? $job['phase'] : '' );

		return $job;
	}

	/**
	 * Persist a job.
	 *
	 * @param array<string,mixed> $job Job state.
	 * @return bool
	 */
	public function save_job( $job ) {
		if ( ! is_array( $job ) || empty( $job['id'] ) ) {
			return false;
		}

		$job_id = self::sanitize_job_id( $job['id'] );
		if ( '' === $job_id ) {
			return false;
		}

		$job['id']         = $job_id;
		$job['status']     = Day_One_Importer_Job_State::normalize_status( isset( $job['status'] ) ? $job['status'] : '' );
		$job['phase']      = Day_One_Importer_Job_State::normalize_phase( isset( $job['phase'] ) ? $job['phase'] : '' );
		$job['updated_at'] = time();
		if ( empty( $job['expires_at'] ) ) {
			$job['expires_at'] = time() + $this->retention_seconds();
		}

		$updated = self::option_update( self::JOB_PREFIX . $job_id, $job );
		if ( $updated && ! empty( $job['owner_user_id'] ) ) {
			$this->set_user_active_job( (int) $job['owner_user_id'], $job_id );
		}

		return $updated;
	}

	/**
	 * Delete a job and its lock.
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	public function delete_job( $job_id ) {
		$job_id = self::sanitize_job_id( $job_id );
		if ( '' === $job_id ) {
			return;
		}

		self::option_delete( self::JOB_PREFIX . $job_id );
		self::option_delete( self::LOCK_PREFIX . $job_id );
	}

	/**
	 * Return active/recent job for a user, optionally constrained to a requested ID.
	 *
	 * @param int    $user_id User ID.
	 * @param string $requested_job_id Requested job ID.
	 * @return array<string,mixed>|null
	 */
	public function get_user_job( $user_id, $requested_job_id = '' ) {
		$user_id          = (int) $user_id;
		$requested_job_id = self::sanitize_job_id( $requested_job_id );
		$job_id           = $requested_job_id;
		if ( '' === $job_id && $user_id > 0 ) {
			$job_id = self::sanitize_job_id( self::option_get( self::USER_ACTIVE_PREFIX . $user_id ) );
		}

		if ( '' === $job_id ) {
			return null;
		}

		$job = $this->get_job( $job_id );
		if ( ! $job || ! $this->user_can_access_job( $job, $user_id ) ) {
			return null;
		}

		return $job;
	}

	/**
	 * Whether a user owns a job.
	 *
	 * @param array<string,mixed> $job Job.
	 * @param int                 $user_id User ID.
	 * @return bool
	 */
	public function user_can_access_job( $job, $user_id ) {
		return is_array( $job ) && (int) $user_id > 0 && isset( $job['owner_user_id'] ) && (int) $job['owner_user_id'] === (int) $user_id;
	}

	/**
	 * Acquire an atomic, expiring lock for a job.
	 *
	 * @param string $job_id Job ID.
	 * @param string $owner_token Optional owner token.
	 * @param int    $ttl Lock TTL in seconds.
	 * @return string|false Owner token on success, false when busy.
	 */
	public function acquire_lock( $job_id, $owner_token = '', $ttl = 60 ) {
		$job_id = self::sanitize_job_id( $job_id );
		if ( '' === $job_id ) {
			return false;
		}

		$owner_token = is_scalar( $owner_token ) && '' !== (string) $owner_token ? (string) $owner_token : $this->generate_lock_token();
		$option      = self::LOCK_PREFIX . $job_id;
		$lock        = array(
			'owner_token' => $owner_token,
			'expires_at'  => time() + max( 1, (int) $ttl ),
		);

		if ( self::option_add( $option, $lock ) ) {
			return $owner_token;
		}

		$existing = self::option_get( $option );
		if ( is_array( $existing ) && isset( $existing['expires_at'] ) && (int) $existing['expires_at'] <= time() ) {
			if ( self::option_delete_if_value( $option, $existing ) && self::option_add( $option, $lock ) ) {
				return $owner_token;
			}
		}

		return false;
	}

	/**
	 * Renew a lock if the caller still owns it.
	 *
	 * @param string $job_id Job ID.
	 * @param string $owner_token Owner token.
	 * @param int    $ttl Lock TTL in seconds.
	 * @return bool
	 */
	public function renew_lock( $job_id, $owner_token, $ttl = 60 ) {
		$job_id      = self::sanitize_job_id( $job_id );
		$owner_token = (string) $owner_token;
		if ( '' === $job_id || '' === $owner_token ) {
			return false;
		}

		$option = self::LOCK_PREFIX . $job_id;
		$lock   = self::option_get( $option );
		if ( ! is_array( $lock ) || ! isset( $lock['owner_token'] ) || ! hash_equals( (string) $lock['owner_token'], $owner_token ) ) {
			return false;
		}

		$new_lock = array(
			'owner_token' => $owner_token,
			'expires_at'  => time() + max( 1, (int) $ttl ),
		);

		return self::option_update_if_value( $option, $lock, $new_lock );
	}

	/**
	 * Release a lock. Only the owner token may release it.
	 *
	 * @param string $job_id Job ID.
	 * @param string $owner_token Owner token.
	 * @return bool
	 */
	public function release_lock( $job_id, $owner_token ) {
		$job_id      = self::sanitize_job_id( $job_id );
		$owner_token = (string) $owner_token;
		if ( '' === $job_id || '' === $owner_token ) {
			return false;
		}

		$option = self::LOCK_PREFIX . $job_id;
		$lock   = self::option_get( $option );
		if ( ! is_array( $lock ) || ! isset( $lock['owner_token'] ) || ! hash_equals( (string) $lock['owner_token'], $owner_token ) ) {
			return false;
		}

		return self::option_delete_if_value( $option, $lock );
	}

	/**
	 * Retry/continue a failed or interrupted job.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $user_id User ID.
	 * @return array<string,mixed>|null Updated job.
	 */
	public function retry_job( $job_id, $user_id ) {
		$job = $this->get_user_job( $user_id, $job_id );
		if ( ! $job || Day_One_Importer_Job_State::is_terminal_status( $job['status'] ) ) {
			return null;
		}

		$lock = $this->acquire_lock( $job['id'], 'retry-' . str_replace( '.', '', uniqid( '', true ) ), 30 );
		if ( ! $lock ) {
			// A processor is still active; do not overwrite newer cursors.
			return $this->get_user_job( $user_id, $job_id );
		}

		try {
			$job = $this->get_user_job( $user_id, $job_id );
			if ( ! $job || Day_One_Importer_Job_State::is_terminal_status( $job['status'] ) ) {
				return $job;
			}

			if ( Day_One_Importer_Job_State::STATUS_FAILED === $job['status'] || Day_One_Importer_Job_State::STATUS_QUEUED === $job['status'] ) {
				$job['status'] = Day_One_Importer_Job_State::STATUS_QUEUED;
				$this->save_job( $job );
			}

			$this->schedule_job( $job['id'] );
			return $job;
		} finally {
			$this->release_lock( $job_id, $lock );
		}
	}

	/**
	 * Cancel/abandon a job and clean temporary files.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $user_id User ID.
	 * @return array<string,mixed>|null Updated job.
	 */
	public function cancel_job( $job_id, $user_id ) {
		$job = $this->get_user_job( $user_id, $job_id );
		if ( ! $job || Day_One_Importer_Job_State::is_terminal_status( $job['status'] ) ) {
			return null;
		}

		$lock = $this->acquire_lock( $job['id'], 'cancel-' . str_replace( '.', '', uniqid( '', true ) ), 30 );
		if ( ! $lock ) {
			return null;
		}

		try {
			if ( ! empty( $job['run_dir'] ) ) {
				Day_One_Importer_Cleanup::remove( (string) $job['run_dir'] );
			}
			$job['files_cleaned'] = true;
			$job['status']        = Day_One_Importer_Job_State::STATUS_CANCELED;
			$job['phase']         = 'done';
			$this->save_job( $job );
		} finally {
			$this->release_lock( $job['id'], $lock );
		}

		return $job;
	}

	/**
	 * Schedule a single cron fallback for an incomplete job.
	 *
	 * @param string $job_id Job ID.
	 * @param int    $delay Delay in seconds.
	 * @return void
	 */
	public function schedule_job( $job_id, $delay = 10 ) {
		$job_id = self::sanitize_job_id( $job_id );
		if ( '' === $job_id || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}

		$args = array( $job_id );
		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::CRON_HOOK, $args ) ) {
			return;
		}

		wp_schedule_single_event( time() + max( 1, (int) $delay ), self::CRON_HOOK, $args );
	}

	/**
	 * Opportunistically remove expired jobs and stale locks.
	 *
	 * @return int Number of job records removed.
	 */
	public function cleanup_stale_jobs() {
		$removed = 0;
		$now     = time();
		foreach ( self::list_option_names( self::JOB_PREFIX ) as $option_name ) {
			$job = self::option_get( $option_name );
			if ( ! is_array( $job ) ) {
				continue;
			}

			$expires = isset( $job['expires_at'] ) ? (int) $job['expires_at'] : 0;
			if ( $expires > $now ) {
				continue;
			}

			$job_id = ! empty( $job['id'] ) ? self::sanitize_job_id( $job['id'] ) : str_replace( self::JOB_PREFIX, '', $option_name );
			$lock   = self::option_get( self::LOCK_PREFIX . $job_id );
			if ( is_array( $lock ) && isset( $lock['expires_at'] ) && (int) $lock['expires_at'] > $now ) {
				continue;
			}

			if ( ! empty( $job['run_dir'] ) ) {
				Day_One_Importer_Cleanup::remove( (string) $job['run_dir'] );
			}
			self::option_delete( $option_name );
			if ( is_array( $lock ) ) {
				self::option_delete_if_value( self::LOCK_PREFIX . $job_id, $lock );
			}
			++$removed;
		}

		foreach ( self::list_option_names( self::LOCK_PREFIX ) as $option_name ) {
			$lock = self::option_get( $option_name );
			if ( is_array( $lock ) && isset( $lock['expires_at'] ) && (int) $lock['expires_at'] <= $now ) {
				self::option_delete_if_value( $option_name, $lock );
			}
		}

		return $removed;
	}

	/**
	 * Set a user's active job pointer.
	 *
	 * @param int    $user_id User ID.
	 * @param string $job_id Job ID.
	 * @return void
	 */
	private function set_user_active_job( $user_id, $job_id ) {
		$user_id = (int) $user_id;
		$job_id  = self::sanitize_job_id( $job_id );
		if ( $user_id <= 0 || '' === $job_id ) {
			return;
		}

		self::option_update( self::USER_ACTIVE_PREFIX . $user_id, $job_id );
	}

	/**
	 * Generate an opaque job ID.
	 *
	 * @return string
	 */
	private function generate_job_id() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return self::sanitize_job_id( wp_generate_uuid4() );
		}

		return self::sanitize_job_id( str_replace( '.', '', uniqid( 'job-', true ) ) );
	}

	/**
	 * Generate a lock token.
	 *
	 * @return string
	 */
	private function generate_lock_token() {
		if ( function_exists( 'wp_generate_password' ) ) {
			return wp_generate_password( 32, false, false );
		}

		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Exception $e ) {
			return str_replace( '.', '', uniqid( 'lock-', true ) );
		}
	}

	/**
	 * Retention window in seconds.
	 *
	 * @return int
	 */
	private function retention_seconds() {
		$default = defined( 'DAY_IN_SECONDS' ) ? 7 * DAY_IN_SECONDS : 7 * 24 * 60 * 60;
		$value   = function_exists( 'apply_filters' ) ? apply_filters( 'day_one_importer_job_retention_seconds', $default ) : $default;

		return max( 3600, (int) $value );
	}

	/**
	 * Sanitize a job ID.
	 *
	 * @param mixed $job_id Job ID.
	 * @return string
	 */
	public static function sanitize_job_id( $job_id ) {
		$job_id = is_scalar( $job_id ) ? (string) $job_id : '';
		return preg_replace( '/[^A-Za-z0-9_-]/', '', $job_id );
	}

	/**
	 * Option get wrapper with pure-test fallback.
	 *
	 * @param string $name Option name.
	 * @return mixed|null
	 */
	private static function option_get( $name ) {
		if ( function_exists( 'get_option' ) && ! defined( 'DAY_ONE_IMPORTER_TESTING' ) ) {
			$value = get_option( $name, null );
			return false === $value ? null : $value;
		}

		return array_key_exists( $name, self::$test_options ) ? self::$test_options[ $name ] : null;
	}

	/**
	 * Atomic option add wrapper with pure-test fallback.
	 *
	 * @param string $name Option name.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	private static function option_add( $name, $value ) {
		if ( function_exists( 'add_option' ) && ! defined( 'DAY_ONE_IMPORTER_TESTING' ) ) {
			return (bool) add_option( $name, $value, '', 'no' );
		}

		if ( array_key_exists( $name, self::$test_options ) ) {
			return false;
		}
		self::$test_options[ $name ] = $value;
		return true;
	}

	/**
	 * Option update wrapper with pure-test fallback.
	 *
	 * @param string $name Option name.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	private static function option_update( $name, $value ) {
		if ( function_exists( 'update_option' ) && ! defined( 'DAY_ONE_IMPORTER_TESTING' ) ) {
			return (bool) update_option( $name, $value, false );
		}

		self::$test_options[ $name ] = $value;
		return true;
	}

	/**
	 * Option delete wrapper with pure-test fallback.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	private static function option_delete( $name ) {
		if ( function_exists( 'delete_option' ) && ! defined( 'DAY_ONE_IMPORTER_TESTING' ) ) {
			return (bool) delete_option( $name );
		}

		$exists = array_key_exists( $name, self::$test_options );
		unset( self::$test_options[ $name ] );
		return $exists;
	}

	/**
	 * Delete an option only when its stored value still matches.
	 *
	 * @param string $name Option name.
	 * @param mixed  $expected Expected value.
	 * @return bool
	 */
	private static function option_delete_if_value( $name, $expected ) {
		if ( function_exists( 'get_option' ) && ! defined( 'DAY_ONE_IMPORTER_TESTING' ) ) {
			global $wpdb;
			if ( ! $wpdb ) {
				return false;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-delete for the wp_options-backed lock; caching would defeat the distributed lock guarantee.
			$deleted = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$wpdb->options} table name interpolation is intentional.
					"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
					$name,
					self::serialize_option_value( $expected )
				)
			);

			if ( 1 === (int) $deleted ) {
				self::flush_option_cache( $name );
				return true;
			}

			return false;
		}

		if ( ! array_key_exists( $name, self::$test_options ) || self::$test_options[ $name ] !== $expected ) {
			return false;
		}
		unset( self::$test_options[ $name ] );

		return true;
	}

	/**
	 * Update an option only when its stored value still matches.
	 *
	 * @param string $name Option name.
	 * @param mixed  $expected Expected value.
	 * @param mixed  $new_value New value.
	 * @return bool
	 */
	private static function option_update_if_value( $name, $expected, $new_value ) {
		if ( function_exists( 'get_option' ) && ! defined( 'DAY_ONE_IMPORTER_TESTING' ) ) {
			global $wpdb;
			if ( ! $wpdb ) {
				return false;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-update for the wp_options-backed lock; caching would defeat the distributed lock guarantee.
			$updated = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$wpdb->options} table name interpolation is intentional.
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					self::serialize_option_value( $new_value ),
					$name,
					self::serialize_option_value( $expected )
				)
			);

			if ( 1 === (int) $updated ) {
				self::flush_option_cache( $name );
				return true;
			}

			return false;
		}

		if ( ! array_key_exists( $name, self::$test_options ) || self::$test_options[ $name ] !== $expected ) {
			return false;
		}
		self::$test_options[ $name ] = $new_value;

		return true;
	}

	/**
	 * Flush WordPress option caches after direct SQL mutations.
	 *
	 * @param string $name Option name.
	 * @return void
	 */
	private static function flush_option_cache( $name ) {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $name, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}

	/**
	 * Serialize an option value the same way WordPress stores it.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function serialize_option_value( $value ) {
		if ( function_exists( 'maybe_serialize' ) ) {
			return maybe_serialize( $value );
		}

		return is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
	}

	/**
	 * List option names for a prefix.
	 *
	 * @param string $prefix Prefix.
	 * @return string[]
	 */
	private static function list_option_names( $prefix ) {
		if ( function_exists( 'get_option' ) && ! defined( 'DAY_ONE_IMPORTER_TESTING' ) ) {
			global $wpdb;
			if ( ! $wpdb ) {
				return array();
			}

			$like = $wpdb->esc_like( $prefix ) . '%';
			return $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- {$wpdb->options} table name interpolation is intentional; prefix-scan over wp_options for lock keys; no get_option() equivalent and caching would lag.
		}

		$names = array();
		foreach ( array_keys( self::$test_options ) as $name ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				$names[] = $name;
			}
		}

		return $names;
	}
}
