<?php
/**
 * Plugin Name: Day One Importer
 * Description: Import Day One journal exports as private WordPress posts.
 * Version: 0.2.23
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Automattic
 * Author URI: https://automattic.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: day-one-importer
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DAY_ONE_IMPORTER_VERSION', '0.2.23' );
define( 'DAY_ONE_IMPORTER_FILE', __FILE__ );
define( 'DAY_ONE_IMPORTER_DIR', plugin_dir_path( __FILE__ ) );
define( 'DAY_ONE_IMPORTER_URL', plugin_dir_url( __FILE__ ) );
define( 'DAY_ONE_IMPORTER_TEXT_DOMAIN', 'day-one-importer' );

require_once DAY_ONE_IMPORTER_DIR . 'includes/functions.php';
require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-results.php';
require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-post-type.php';
require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-job-state.php';
require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-cleanup.php';
require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-content.php';
require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-parser.php';
require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-media.php';
require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-uploader.php';
require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-runner.php';
require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-job-store.php';
require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-job-processor.php';

// Defer admin-only files: only load the importer UI + AJAX controller when the
// current request can actually reach them (admin screen, admin-ajax, WP-CLI).
// Front-end pageviews and WP-Cron front-end dispatches skip ~750 LOC each. The
// matching `Day_One_Importer_Plugin::init()` branch is gated on the same
// condition; the cron job-processing callback is registered unconditionally
// from the plugin class so cron still fires on front-end requests.
if (
	is_admin()
	|| ( defined( 'DOING_AJAX' ) && DOING_AJAX )
	|| ( defined( 'WP_CLI' ) && WP_CLI )
) {
	require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-jobs-controller.php';
	require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-admin.php';
}

require_once DAY_ONE_IMPORTER_DIR . 'includes/class-day-one-importer-plugin.php';

register_activation_hook( __FILE__, array( 'Day_One_Importer_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Day_One_Importer_Plugin', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		Day_One_Importer_Plugin::instance()->init();
	}
);
