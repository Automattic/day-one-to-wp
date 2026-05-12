<?php
/**
 * AJAX and cron controller for Day One import jobs.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers authenticated endpoints for import jobs.
 */
class Day_One_Importer_Jobs_Controller {
	/** Nonce action for job AJAX. */
	const NONCE_ACTION = 'day_one_importer_job';

	/**
	 * Store.
	 *
	 * @var Day_One_Importer_Job_Store
	 */
	private $store;

	/**
	 * Constructor.
	 *
	 * @param Day_One_Importer_Job_Store|null $store Store.
	 */
	public function __construct( $store = null ) {
		$this->store = $store instanceof Day_One_Importer_Job_Store ? $store : new Day_One_Importer_Job_Store();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'wp_ajax_day_one_importer_job_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_day_one_importer_job_process', array( $this, 'ajax_process' ) );
		add_action( 'wp_ajax_day_one_importer_job_retry', array( $this, 'ajax_retry' ) );
		add_action( 'wp_ajax_day_one_importer_job_cancel', array( $this, 'ajax_cancel' ) );
		add_action( Day_One_Importer_Job_Store::CRON_HOOK, array( $this, 'cron_process' ) );
	}

	/**
	 * Return current status.
	 *
	 * @return void
	 */
	public function ajax_status() {
		$job = $this->authorize_ajax_job();
		wp_send_json_success( Day_One_Importer_Job_State::status_response( $job ) );
	}

	/**
	 * Process a batch and return status.
	 *
	 * @return void
	 */
	public function ajax_process() {
		$job       = $this->authorize_ajax_job();
		$processor = new Day_One_Importer_Job_Processor( $this->store );
		wp_send_json_success( $processor->process_batch( $job['id'], 'ajax' ) );
	}

	/**
	 * Retry/continue a job.
	 *
	 * @return void
	 */
	public function ajax_retry() {
		$job = $this->authorize_ajax_job();
		$job = $this->store->retry_job( $job['id'], get_current_user_id() );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'This import job cannot be retried.', 'day-one-importer' ) ), 400 );
		}

		wp_send_json_success( Day_One_Importer_Job_State::status_response( $job ) );
	}

	/**
	 * Cancel/abandon a job.
	 *
	 * @return void
	 */
	public function ajax_cancel() {
		$job = $this->authorize_ajax_job();
		$job = $this->store->cancel_job( $job['id'], get_current_user_id() );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'This import job cannot be canceled.', 'day-one-importer' ) ), 400 );
		}

		wp_send_json_success( Day_One_Importer_Job_State::status_response( $job ) );
	}

	/**
	 * Cron fallback processor.
	 *
	 * @param string $job_id Job ID.
	 * @return void
	 */
	public function cron_process( $job_id ) {
		$job = $this->store->get_job( $job_id );
		if ( ! $job || empty( $job['owner_user_id'] ) ) {
			return;
		}

		if ( function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( (int) $job['owner_user_id'] );
		}

		$processor = new Day_One_Importer_Job_Processor( $this->store );
		$processor->process_batch( $job['id'], 'cron' );
	}

	/**
	 * Validate nonce/capability/ownership and return the requested job.
	 *
	 * @return array<string,mixed>
	 */
	private function authorize_ajax_job() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed for the import request.', 'day-one-importer' ) ), 403 );
		}

		if ( ! day_one_importer_current_user_can_import() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to import Day One exports.', 'day-one-importer' ) ), 403 );
		}

		$job_id = $this->requested_job_id();
		$job    = $this->store->get_user_job( get_current_user_id(), $job_id );
		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'The requested import job could not be found.', 'day-one-importer' ) ), 404 );
		}

		return $job;
	}

	/**
	 * Read requested job ID.
	 *
	 * @return string
	 */
	private function requested_job_id() {
		$job_id = '';
		if ( isset( $_REQUEST['job_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked by caller.
			$job_id = wp_unslash( $_REQUEST['job_id'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
		}

		return Day_One_Importer_Job_Store::sanitize_job_id( $job_id );
	}
}
