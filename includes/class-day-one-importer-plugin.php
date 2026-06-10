<?php
/**
 * Plugin bootstrap.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 */
class Day_One_Importer_Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var Day_One_Importer_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton.
	 *
	 * @return Day_One_Importer_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize plugin.
	 *
	 * @return void
	 */
	public function init() {
		// Always-on hooks: front-end private media serving + cron job processing.
		add_filter( 'wp_get_attachment_url', array( 'Day_One_Importer_Media', 'filter_attachment_url' ), 10, 2 );
		add_filter( 'the_content', array( 'Day_One_Importer_Media', 'filter_private_media_content_urls' ), 20 );
		add_action( 'wp_ajax_day_one_importer_media', array( 'Day_One_Importer_Media', 'serve_private_media' ) );

		// The journal entry CPT must exist on every request type — front-end
		// views of imported entries, cron batches, CLI, and admin alike — so it
		// registers outside the admin-gated branch below. The flush guard runs
		// at priority 20, after registration, so any one-time flush it performs
		// includes the CPT's rules.
		add_action( 'init', array( 'Day_One_Importer_Post_Type', 'register' ) );
		add_action( 'init', array( 'Day_One_Importer_Post_Type', 'maybe_flush_rewrite_rules' ), 20 );

		// Cron callbacks must register on every request (WP-Cron can fire on a
		// front-end pageview). Both callbacks lazy-load admin-only collaborators
		// only when the cron actually fires, keeping the front-end fast path
		// free of `Day_One_Importer_Jobs_Controller` / `Day_One_Importer_Admin`.
		add_action( Day_One_Importer_Job_Store::CRON_HOOK, array( $this, 'cron_process_job' ), 10, 1 );
		add_action( 'day_one_importer_daily_cleanup', array( $this, 'cleanup_stale_jobs' ) );

		// Admin + AJAX-only wiring. The require_once for these classes is gated
		// by the same condition in `day-one-importer.php`, so this branch is
		// the only place that references them.
		if (
			is_admin()
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
		) {
			$jobs_controller = new Day_One_Importer_Jobs_Controller();
			$jobs_controller->init();

			if ( is_admin() ) {
				$admin = new Day_One_Importer_Admin();
				$admin->init();
			}
		}
	}

	/**
	 * Activation: schedule the daily stale-job cleanup event and flush rewrite
	 * rules with the journal entry CPT registered.
	 *
	 * The activation request's `init` fired before this plugin loaded, so the
	 * CPT is registered in-place here before flushing — otherwise the freshly
	 * generated rules would omit it. Recording the rewrite version afterwards
	 * lets `Day_One_Importer_Post_Type::maybe_flush_rewrite_rules()`
	 * short-circuit on the next request instead of flushing a second time.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( ! wp_next_scheduled( 'day_one_importer_daily_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'day_one_importer_daily_cleanup' );
		}

		Day_One_Importer_Post_Type::register();
		flush_rewrite_rules();
		update_option( Day_One_Importer_Post_Type::REWRITE_VERSION_OPTION, Day_One_Importer_Post_Type::REWRITE_VERSION );
	}

	/**
	 * Deactivation: clear scheduled plugin events and retire the CPT's rewrite
	 * rules.
	 *
	 * Unregistering the CPT before the flush regenerates the rules without it,
	 * so its permalinks stop resolving while the plugin is inactive. Deleting
	 * the rewrite version option makes reactivation (or the next request's
	 * guard) flush again and restore the rules.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'day_one_importer_daily_cleanup' );
		wp_unschedule_hook( Day_One_Importer_Job_Store::CRON_HOOK );

		if ( post_type_exists( Day_One_Importer_Post_Type::POST_TYPE ) ) {
			unregister_post_type( Day_One_Importer_Post_Type::POST_TYPE );
		}
		flush_rewrite_rules();
		delete_option( Day_One_Importer_Post_Type::REWRITE_VERSION_OPTION );
	}

	/**
	 * Cron callback: process a queued import job batch.
	 *
	 * Registered on every request so WP-Cron can dispatch on a front-end
	 * pageview without loading the admin-only `Day_One_Importer_Jobs_Controller`
	 * file. Mirrors the authorization gate used by the controller's cron path.
	 *
	 * @param string $job_id Job ID supplied by the scheduled event.
	 * @return void
	 */
	public function cron_process_job( $job_id ) {
		$store = new Day_One_Importer_Job_Store();
		$job   = $store->get_job( $job_id );
		if ( ! $job || empty( $job['owner_user_id'] ) || ! day_one_importer_user_can_import( (int) $job['owner_user_id'] ) ) {
			return;
		}

		$processor = new Day_One_Importer_Job_Processor( $store );
		$processor->process_batch( $job['id'], 'cron' );
	}

	/**
	 * Cleanup expired job records.
	 *
	 * @return void
	 */
	public function cleanup_stale_jobs() {
		$store = new Day_One_Importer_Job_Store();
		$store->cleanup_stale_jobs();
	}

	/**
	 * Disallow direct construction by consumers.
	 */
	private function __construct() {}
}
