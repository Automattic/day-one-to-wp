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
		add_filter( 'wp_get_attachment_url', array( 'Day_One_Importer_Media', 'filter_attachment_url' ), 10, 2 );
		add_action( 'wp_ajax_day_one_importer_media', array( 'Day_One_Importer_Media', 'serve_private_media' ) );

		if ( is_admin() ) {
			$admin = new Day_One_Importer_Admin();
			$admin->init();
		}
	}

	/**
	 * Disallow direct construction by consumers.
	 */
	private function __construct() {}
}
