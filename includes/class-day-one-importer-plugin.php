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
