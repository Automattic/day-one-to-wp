<?php
/**
 * Plugin Name: Day One Importer
 * Description: Import Day One journal exports as private WordPress posts.
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Automattic
 * Author URI: https://automattic.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: day-one-importer
 * Domain Path: /languages
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DAY_ONE_IMPORTER_VERSION', '0.1.0' );
define( 'DAY_ONE_IMPORTER_FILE', __FILE__ );
define( 'DAY_ONE_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'DAY_ONE_IMPORTER_URL', plugin_dir_url( __FILE__ ) );
define( 'DAY_ONE_IMPORTER_TEXT_DOMAIN', 'day-one-importer' );

require_once DAY_ONE_IMPORTER_DIR . 'includes/functions.php';
require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-results.php';
require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-cleanup.php';
require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-content.php';
require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-parser.php';
require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-media.php';
require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-runner.php';
require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-admin.php';
require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-plugin.php';

add_action(
	'plugins_loaded',
	static function () {
		Day_One_Importer_Plugin::instance()->init();
	}
);
