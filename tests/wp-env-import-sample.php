<?php
/**
 * wp-env smoke test for importing a local Day One sample export.
 *
 * Run from the plugin root after `wp-env start`. In wp-env, the mounted plugin
 * directory name follows the local checkout name, so see README.md for the
 * robust command that resolves the plugin directory dynamically.
 *
 * The sample export is intentionally not committed because it can contain
 * personal journal data. Place a local ZIP at sample/local-day-one-export.zip,
 * set DAY_ONE_IMPORTER_SAMPLE_ZIP, or pass a path as the first WP-CLI argument.
 *
 * The script intentionally prints counts and statuses only, not journal content.
 * It bypasses browser upload handling so it can run under WP-CLI, but exercises
 * parsing, post creation, private status, tags, media import, and idempotency.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

if ( ! class_exists( 'Day_One_Importer_Runner' ) ) {
	fwrite( STDERR, "Day One Importer plugin is not loaded.\n" );
	exit( 1 );
}

function day_one_importer_wp_env_is_absolute_path( $path ) {
	return 1 === preg_match( '#^(?:/|[A-Za-z]:[\\\\/])#', (string) $path );
}

function day_one_importer_wp_env_sample_zip_path() {
	global $args;

	$configured = getenv( 'DAY_ONE_IMPORTER_SAMPLE_ZIP' );
	if ( ( false === $configured || '' === trim( (string) $configured ) ) && ! empty( $args[0] ) ) {
		$configured = $args[0];
	}

	if ( false === $configured || '' === trim( (string) $configured ) ) {
		$configured = 'sample/local-day-one-export.zip';
	}

	$configured = (string) $configured;
	if ( day_one_importer_wp_env_is_absolute_path( $configured ) ) {
		return $configured;
	}

	return dirname( __DIR__ ) . '/' . ltrim( $configured, '/\\' );
}

$sample_zip = day_one_importer_wp_env_sample_zip_path();
if ( ! is_readable( $sample_zip ) ) {
	echo wp_json_encode(
		array(
			'status'            => 'skipped',
			'reason'            => 'Local sample export ZIP not found.',
			'sample_configured' => (bool) getenv( 'DAY_ONE_IMPORTER_SAMPLE_ZIP' ) || ! empty( $args[0] ),
			'path_hint'         => 'Use sample/local-day-one-export.zip, DAY_ONE_IMPORTER_SAMPLE_ZIP, or a first WP-CLI argument.',
			'privacy'           => 'Sample Day One exports are personal and are intentionally not committed.',
		),
		JSON_PRETTY_PRINT
	) . "\n";
	exit( 0 );
}

require_once ABSPATH . 'wp-admin/includes/file.php';

if ( ! function_exists( 'WP_Filesystem' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}
WP_Filesystem();

function day_one_importer_wp_env_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function day_one_importer_wp_env_import_from_zip( $zip_path ) {
	$run_dir = Day_One_Importer_Cleanup::create_run_directory();
	day_one_importer_wp_env_assert( $run_dir, 'Run directory created.' );

	try {
		$extract_dir = trailingslashit( $run_dir ) . 'extract';
		wp_mkdir_p( $extract_dir );
		Day_One_Importer_Cleanup::protect_directory( $extract_dir );

		$preflight = Day_One_Importer_Cleanup::preflight_zip( $zip_path );
		day_one_importer_wp_env_assert( true === $preflight, 'ZIP preflight passed.' );

		$unzipped = unzip_file( $zip_path, $extract_dir );
		day_one_importer_wp_env_assert(
			! is_wp_error( $unzipped ),
			'ZIP extracted' . ( is_wp_error( $unzipped ) ? ': ' . $unzipped->get_error_message() : '.' )
		);

		$tree_valid = Day_One_Importer_Cleanup::validate_extracted_tree( $extract_dir );
		day_one_importer_wp_env_assert( true === $tree_valid, 'Extracted tree validated.' );

		$results = new Day_One_Importer_Results();
		$parser  = new Day_One_Importer_Parser();
		$entries = $parser->parse_export( $extract_dir, $results );
		day_one_importer_wp_env_assert( ! empty( $entries ), 'Parsed importable entries.' );

		$runner = new Day_One_Importer_Runner();
		$method = new ReflectionMethod( $runner, 'import_entry' );
		$method->setAccessible( true );

		foreach ( $entries as $entry ) {
			$method->invoke( $runner, $entry, $extract_dir, $results );
		}

		return $results;
	} finally {
		Day_One_Importer_Cleanup::remove( $run_dir );
	}
}

// Start from a clean sample-import state so this smoke test is repeatable.
$existing = get_posts(
	array(
		'post_type'      => 'post',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => '_day_one_source', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_value'     => 'day-one-export', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	)
);
foreach ( $existing as $post_id ) {
	wp_delete_post( (int) $post_id, true );
}

$first        = day_one_importer_wp_env_import_from_zip( $sample_zip );
$first_counts = $first->get_counts();

$created = isset( $first_counts['posts_created'] ) ? (int) $first_counts['posts_created'] : 0;
$media   = isset( $first_counts['media_imported'] ) ? (int) $first_counts['media_imported'] : 0;
day_one_importer_wp_env_assert( $created > 0, 'Created private posts from sample.' );
day_one_importer_wp_env_assert( $media > 0, 'Imported sample media attachments.' );

$imported_posts = get_posts(
	array(
		'post_type'      => 'post',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => '_day_one_source', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_value'     => 'day-one-export', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	)
);

day_one_importer_wp_env_assert( count( $imported_posts ) === $created, 'Created post count matches query count.' );
foreach ( $imported_posts as $post_id ) {
	day_one_importer_wp_env_assert( 'private' === get_post_status( (int) $post_id ), 'Imported post is private.' );
	day_one_importer_wp_env_assert( '' !== get_post_meta( (int) $post_id, '_day_one_uuid', true ), 'Imported post has Day One UUID.' );
	day_one_importer_wp_env_assert( '1' === get_post_meta( (int) $post_id, '_day_one_import_complete', true ), 'Imported post marked complete.' );
}

$second        = day_one_importer_wp_env_import_from_zip( $sample_zip );
$second_counts = $second->get_counts();
$skipped       = isset( $second_counts['posts_skipped'] ) ? (int) $second_counts['posts_skipped'] : 0;
day_one_importer_wp_env_assert( $skipped === $created, 'Second import skipped existing completed posts.' );

$legacy_post_id = (int) reset( $imported_posts );
update_post_meta( $legacy_post_id, '_day_one_import_version', '1' );
$third         = day_one_importer_wp_env_import_from_zip( $sample_zip );
$third_counts  = $third->get_counts();
$resumed_legacy = isset( $third_counts['posts_resumed'] ) ? (int) $third_counts['posts_resumed'] : 0;
$skipped_legacy = isset( $third_counts['posts_skipped'] ) ? (int) $third_counts['posts_skipped'] : 0;
day_one_importer_wp_env_assert( 1 === $resumed_legacy, 'Legacy-version imported post was reprocessed on rerun.' );
day_one_importer_wp_env_assert( ( $created - 1 ) === $skipped_legacy, 'Current-version completed posts were still skipped.' );
day_one_importer_wp_env_assert( Day_One_Importer_Runner::IMPORT_SCHEMA_VERSION === get_post_meta( $legacy_post_id, '_day_one_import_version', true ), 'Legacy-version imported post was upgraded.' );

wp_trash_post( $legacy_post_id );
day_one_importer_wp_env_assert( 'trash' === get_post_status( $legacy_post_id ), 'Imported post moved to trash for retry test.' );

$fourth              = day_one_importer_wp_env_import_from_zip( $sample_zip );
$fourth_counts       = $fourth->get_counts();
$recreated           = isset( $fourth_counts['posts_created'] ) ? (int) $fourth_counts['posts_created'] : 0;
$skipped_after_trash = isset( $fourth_counts['posts_skipped'] ) ? (int) $fourth_counts['posts_skipped'] : 0;
day_one_importer_wp_env_assert( 1 === $recreated, 'Trashed imported post was recreated on rerun.' );
day_one_importer_wp_env_assert( ( $created - 1 ) === $skipped_after_trash, 'Non-trashed completed posts were still skipped.' );

echo wp_json_encode(
	array(
		'status'                         => 'passed',
		'posts_created'                  => $created,
		'posts_skipped_rerun'            => $skipped,
		'legacy_posts_reprocessed'       => $resumed_legacy,
		'posts_recreated_after_trash'    => $recreated,
		'posts_skipped_after_trash_test' => $skipped_after_trash,
		'media_imported'                 => $media,
	),
	JSON_PRETTY_PRINT
) . "\n";
