<?php
/**
 * Uninstall cleanup for Day One Importer.
 *
 * Removes plugin-owned job options and scheduled events. Imported entries —
 * regular posts and Journal Entries custom post type entries alike — and
 * their media are intentionally preserved: they are the user's journal
 * content. Journal Entries stop appearing in wp-admin once uninstall removes
 * the plugin (and with it the post type registration), but the entries
 * themselves stay in the database.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'day_one_importer_daily_cleanup' );
wp_unschedule_hook( 'day_one_importer_process_job' );

global $wpdb;

$day_one_importer_option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time uninstall lookup of plugin-prefixed options; no API lists options by prefix.
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'day_one_importer_' ) . '%'
	)
);

foreach ( (array) $day_one_importer_option_names as $day_one_importer_option_name ) {
	delete_option( (string) $day_one_importer_option_name );
}
