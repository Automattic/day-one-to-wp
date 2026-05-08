<?php
/**
 * wp-env smoke test for importing the committed fictional Day One sample export.
 *
 * Run from the plugin root after `wp-env start`. In wp-env, the mounted plugin
 * directory name follows the local checkout name, so see README.md for the
 * robust command that resolves the plugin directory dynamically.
 *
 * By default, this imports the safe fictional fixture committed at
 * tests/fixtures/day-one-fictional.zip. Developers may optionally test their own
 * private local export by setting DAY_ONE_IMPORTER_SAMPLE_ZIP or passing a path
 * as the first WP-CLI argument. Private exports should stay under ignored paths
 * such as sample/ or outside this repository.
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

function day_one_importer_wp_env_sample_zip_config() {
	global $args;

	$explicit   = false;
	$configured = getenv( 'DAY_ONE_IMPORTER_SAMPLE_ZIP' );
	if ( false !== $configured && '' !== trim( (string) $configured ) ) {
		$explicit = true;
	} elseif ( ! empty( $args[0] ) ) {
		$configured = $args[0];
		$explicit   = true;
	} else {
		$configured = 'tests/fixtures/day-one-fictional.zip';
	}

	$configured = (string) $configured;
	$path       = day_one_importer_wp_env_is_absolute_path( $configured ) ? $configured : dirname( __DIR__ ) . '/' . ltrim( $configured, '/\\' );

	return array(
		'path'     => $path,
		'explicit' => $explicit,
	);
}

$sample_config     = day_one_importer_wp_env_sample_zip_config();
$sample_zip        = $sample_config['path'];
$using_default_zip = ! $sample_config['explicit'];
if ( ! is_readable( $sample_zip ) ) {
	if ( $using_default_zip ) {
		fwrite( STDERR, "FAIL: Committed fictional fixture is missing or unreadable: tests/fixtures/day-one-fictional.zip\n" );
	} else {
		fwrite( STDERR, "FAIL: Configured Day One sample ZIP override is missing or unreadable. Check DAY_ONE_IMPORTER_SAMPLE_ZIP or the first WP-CLI argument.\n" );
	}
	exit( 1 );
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

function day_one_importer_wp_env_blocks_have_core_block( $blocks ) {
	foreach ( (array) $blocks as $block ) {
		if ( ! empty( $block['blockName'] ) && 0 === strpos( $block['blockName'], 'core/' ) ) {
			return true;
		}
		if ( ! empty( $block['innerBlocks'] ) && day_one_importer_wp_env_blocks_have_core_block( $block['innerBlocks'] ) ) {
			return true;
		}
	}

	return false;
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
		'post_type'      => array( 'post', 'attachment' ),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => '_day_one_source', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_value'     => 'day-one-export', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	)
);
foreach ( $existing as $post_id ) {
	if ( 'attachment' === get_post_type( (int) $post_id ) ) {
		wp_delete_attachment( (int) $post_id, true );
	} else {
		wp_delete_post( (int) $post_id, true );
	}
}

$first        = day_one_importer_wp_env_import_from_zip( $sample_zip );
$first_counts = $first->get_counts();

$created = isset( $first_counts['posts_created'] ) ? (int) $first_counts['posts_created'] : 0;
$media   = isset( $first_counts['media_imported'] ) ? (int) $first_counts['media_imported'] : 0;
if ( $using_default_zip ) {
	$entries_found = isset( $first_counts['entries_found'] ) ? (int) $first_counts['entries_found'] : 0;
	day_one_importer_wp_env_assert( 3 === $entries_found, 'Fictional fixture parsed three entries.' );
	day_one_importer_wp_env_assert( 3 === $created, 'Fictional fixture created exactly three private posts.' );
} else {
	day_one_importer_wp_env_assert( $created > 0, 'Created private posts from sample.' );
}
day_one_importer_wp_env_assert( $media > 0, 'Imported sample media attachments.' );
day_one_importer_wp_env_assert( '3' === Day_One_Importer_Runner::IMPORT_SCHEMA_VERSION, 'Import schema version is 3 for block content.' );

$imported_posts = get_posts(
	array(
		'post_type'      => 'post',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
		'meta_key'       => '_day_one_source', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_value'     => 'day-one-export', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	)
);

day_one_importer_wp_env_assert( count( $imported_posts ) === $created, 'Created post count matches query count.' );
$found_fixture_tag = false;
foreach ( $imported_posts as $post_id ) {
	$post_id = (int) $post_id;
	day_one_importer_wp_env_assert( 'private' === get_post_status( $post_id ), 'Imported post is private.' );
	day_one_importer_wp_env_assert( '' !== get_post_meta( $post_id, '_day_one_uuid', true ), 'Imported post has Day One UUID.' );
	day_one_importer_wp_env_assert( 'day-one-export' === get_post_meta( $post_id, '_day_one_source', true ), 'Imported post has Day One source metadata.' );
	day_one_importer_wp_env_assert( '1' === get_post_meta( $post_id, '_day_one_import_complete', true ), 'Imported post marked complete.' );
	day_one_importer_wp_env_assert( Day_One_Importer_Runner::IMPORT_SCHEMA_VERSION === get_post_meta( $post_id, '_day_one_import_version', true ), 'Imported post has current import schema metadata.' );

	$post_content = (string) get_post_field( 'post_content', $post_id );
	day_one_importer_wp_env_assert( false !== strpos( $post_content, '<!-- wp:' ), 'Imported post content contains block comments.' );
	if ( function_exists( 'parse_blocks' ) ) {
		day_one_importer_wp_env_assert( day_one_importer_wp_env_blocks_have_core_block( parse_blocks( $post_content ) ), 'Imported post content parses as core blocks.' );
	}
	day_one_importer_wp_env_assert( false === strpos( $post_content, 'day-one-importer-photos' ), 'Imported post content omits old photo wrapper.' );
	day_one_importer_wp_env_assert( false === strpos( $post_content, '<h2>Imported photos</h2>' ), 'Imported post content omits old imported photos heading.' );

	$attachments    = get_attached_media( 'image', $post_id );
	$attachment_ids = array_map( 'intval', wp_list_pluck( $attachments, 'ID' ) );
	if ( 1 === count( $attachment_ids ) ) {
		day_one_importer_wp_env_assert( false !== strpos( $post_content, '<!-- wp:image' ), 'Post with one attachment contains an Image block.' );
	} elseif ( count( $attachment_ids ) >= 2 ) {
		day_one_importer_wp_env_assert( false !== strpos( $post_content, '<!-- wp:gallery' ), 'Post with multiple attachments contains a Gallery block.' );
	}
	foreach ( $attachment_ids as $attachment_id ) {
		if ( false !== strpos( $post_content, '"id":' . $attachment_id ) ) {
			day_one_importer_wp_env_assert( false !== strpos( $post_content, 'wp-image-' . $attachment_id ), 'Image block contains matching wp-image class.' );
		}
	}

	if ( $using_default_zip && has_term( 'fictional', 'post_tag', $post_id ) ) {
		$found_fixture_tag = true;
	}
}
if ( $using_default_zip ) {
	day_one_importer_wp_env_assert( $found_fixture_tag, 'Expected fictional fixture tag exists on imported posts.' );
}

$second        = day_one_importer_wp_env_import_from_zip( $sample_zip );
$second_counts = $second->get_counts();
$skipped       = isset( $second_counts['posts_skipped'] ) ? (int) $second_counts['posts_skipped'] : 0;
day_one_importer_wp_env_assert( $skipped === $created, 'Second import skipped existing completed posts.' );

$legacy_post_id = (int) reset( $imported_posts );
update_post_meta( $legacy_post_id, '_day_one_import_version', '1' );
$third          = day_one_importer_wp_env_import_from_zip( $sample_zip );
$third_counts   = $third->get_counts();
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
		'sample'                         => $using_default_zip ? 'committed fictional fixture' : 'configured override',
		'posts_created'                  => $created,
		'posts_skipped_rerun'            => $skipped,
		'legacy_posts_reprocessed'       => $resumed_legacy,
		'posts_recreated_after_trash'    => $recreated,
		'posts_skipped_after_trash_test' => $skipped_after_trash,
		'media_imported'                 => $media,
	),
	JSON_PRETTY_PRINT
) . "\n";
