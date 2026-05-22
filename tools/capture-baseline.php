<?php
/**
 * Capture pre-#56 post_content baselines for the byte-parity range of the
 * committed fictional Day One sample export.
 *
 * Required by `pipelines/56-inline-positioning/spec.md` AC11 / §7 assumptions:
 * baseline snapshots are captured at the START of the implementation phase
 * (before any renderer change) so the wp-env smoke can compare against them.
 *
 * Usage (from the host machine, with wp-env running):
 *
 *     PLUGIN_DIR=$(wp-env run cli wp eval 'echo dirname( DAY_ONE_IMPORTER_FILE );' 2>/dev/null | tail -n 1)
 *     wp-env run cli wp eval-file "${PLUGIN_DIR}/tools/capture-baseline.php"
 *
 * The script imports `tests/fixtures/day-one-fictional.zip` once (using the
 * synchronous runner — identical to the existing wp-env smoke), iterates the
 * resulting UUID→post_id map, and writes one HTML snapshot per entry in
 * the byte-parity range to `tests/fixtures/expected/post-<uuid>.html`.
 *
 * Entries captured: 0001-0006 and 0008-0017 (entry 0007 is the R14 case and
 * is intentionally excluded — its expected shape is asserted by AC11a, not by
 * byte parity).
 *
 * Re-running after #56 ships overwrites the snapshots intentionally — that
 * makes the baseline reproducible for #56 follow-ups.
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

require_once ABSPATH . 'wp-admin/includes/file.php';

if ( ! function_exists( 'WP_Filesystem' ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
}
WP_Filesystem();

$day_one_capture_admin_users = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ids',
	)
);
if ( ! empty( $day_one_capture_admin_users ) ) {
	wp_set_current_user( (int) $day_one_capture_admin_users[0] );
}

$plugin_dir = dirname( DAY_ONE_IMPORTER_FILE );
$zip_path   = $plugin_dir . '/tests/fixtures/day-one-fictional.zip';
if ( ! is_readable( $zip_path ) ) {
	fwrite( STDERR, "FAIL: Committed fictional fixture is missing or unreadable: tests/fixtures/day-one-fictional.zip\n" );
	exit( 1 );
}

// Clean up prior sample-import state so this script is repeatable.
$existing_candidates = get_posts(
	array(
		'post_type'      => array( 'post', 'attachment' ),
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $existing_candidates as $post_id ) {
	if ( 'day-one-export' !== (string) get_post_meta( (int) $post_id, '_day_one_source', true ) ) {
		continue;
	}
	if ( 'attachment' === get_post_type( (int) $post_id ) ) {
		wp_delete_attachment( (int) $post_id, true );
	} else {
		wp_delete_post( (int) $post_id, true );
	}
}

$run_dir = Day_One_Importer_Cleanup::create_run_directory();
if ( ! $run_dir ) {
	fwrite( STDERR, "FAIL: Could not create protected run directory.\n" );
	exit( 1 );
}

try {
	$extract_dir = trailingslashit( $run_dir ) . 'extract';
	wp_mkdir_p( $extract_dir );
	Day_One_Importer_Cleanup::protect_directory( $extract_dir );

	$preflight = Day_One_Importer_Cleanup::preflight_zip( $zip_path );
	if ( true !== $preflight ) {
		fwrite( STDERR, "FAIL: ZIP preflight failed.\n" );
		exit( 1 );
	}

	$unzipped = unzip_file( $zip_path, $extract_dir );
	if ( is_wp_error( $unzipped ) ) {
		fwrite( STDERR, 'FAIL: ZIP extraction failed: ' . $unzipped->get_error_message() . "\n" );
		exit( 1 );
	}

	$tree_valid = Day_One_Importer_Cleanup::validate_extracted_tree( $extract_dir );
	if ( true !== $tree_valid ) {
		fwrite( STDERR, "FAIL: Extracted tree validation failed.\n" );
		exit( 1 );
	}

	$results = new Day_One_Importer_Results();
	$parser  = new Day_One_Importer_Parser();
	$entries = $parser->parse_export( $extract_dir, $results );
	if ( empty( $entries ) ) {
		fwrite( STDERR, "FAIL: No importable entries parsed.\n" );
		exit( 1 );
	}

	$runner = new Day_One_Importer_Runner();
	foreach ( $entries as $entry ) {
		$runner->import_entry( $entry, $extract_dir, $results );
	}
} finally {
	Day_One_Importer_Cleanup::remove( $run_dir );
}

// Build UUID → post_id map (mirrors the existing wp-env smoke style).
$candidate_ids                       = get_posts(
	array(
		'post_type'      => 'post',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
	)
);
$day_one_importer_uuid_to_post_id = array();
foreach ( $candidate_ids as $post_id ) {
	if ( 'day-one-export' !== (string) get_post_meta( (int) $post_id, '_day_one_source', true ) ) {
		continue;
	}
	$uuid = (string) get_post_meta( (int) $post_id, '_day_one_uuid', true );
	if ( '' !== $uuid ) {
		$day_one_importer_uuid_to_post_id[ $uuid ] = (int) $post_id;
	}
}

$expected_dir = $plugin_dir . '/tests/fixtures/expected';
if ( ! wp_mkdir_p( $expected_dir ) ) {
	fwrite( STDERR, "FAIL: Could not create expected baseline directory: {$expected_dir}\n" );
	exit( 1 );
}

// Byte-parity range pinned by spec AC11: 0001-0006 + 0008-0017 (entry 0007 excluded — R14 case).
$capture_uuids = array(
	'FICTIONAL-SAMPLE-ENTRY-0001',
	'FICTIONAL-SAMPLE-ENTRY-0002',
	'FICTIONAL-SAMPLE-ENTRY-0003',
	'FICTIONAL-SAMPLE-ENTRY-0004',
	'FICTIONAL-SAMPLE-ENTRY-0005',
	'FICTIONAL-SAMPLE-ENTRY-0006',
	'FICTIONAL-SAMPLE-ENTRY-0008',
	'FICTIONAL-SAMPLE-ENTRY-0009',
	'FICTIONAL-SAMPLE-ENTRY-0010',
	'FICTIONAL-SAMPLE-ENTRY-0011',
	'FICTIONAL-SAMPLE-ENTRY-0012',
	'FICTIONAL-SAMPLE-ENTRY-0013',
	'FICTIONAL-SAMPLE-ENTRY-0014',
	'FICTIONAL-SAMPLE-ENTRY-0015',
	'FICTIONAL-SAMPLE-ENTRY-0016',
	'FICTIONAL-SAMPLE-ENTRY-0017',
);

$written = 0;
foreach ( $capture_uuids as $uuid ) {
	if ( ! isset( $day_one_importer_uuid_to_post_id[ $uuid ] ) ) {
		fwrite( STDERR, "FAIL: Could not locate post for UUID {$uuid}.\n" );
		exit( 1 );
	}
	$post_id      = (int) $day_one_importer_uuid_to_post_id[ $uuid ];
	$content      = (string) get_post_field( 'post_content', $post_id );
	$target_path  = $expected_dir . '/post-' . $uuid . '.html';
	$bytes        = file_put_contents( $target_path, $content );
	if ( false === $bytes ) {
		fwrite( STDERR, "FAIL: Could not write baseline {$target_path}.\n" );
		exit( 1 );
	}
	++$written;
}

echo wp_json_encode(
	array(
		'status'                  => 'passed',
		'baselines_written'       => $written,
		'expected_dir'            => $expected_dir,
		'captured_uuids'          => $capture_uuids,
	),
	JSON_PRETTY_PRINT
) . "\n";
