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

$day_one_importer_admin_users = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ids',
	)
);
if ( ! empty( $day_one_importer_admin_users ) ) {
	wp_set_current_user( (int) $day_one_importer_admin_users[0] );
}

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

function day_one_importer_wp_env_collect_blocks_by_name( $blocks, $name, &$found ) {
	foreach ( (array) $blocks as $block ) {
		if ( isset( $block['blockName'] ) && $name === $block['blockName'] ) {
			$found[] = $block;
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			day_one_importer_wp_env_collect_blocks_by_name( $block['innerBlocks'], $name, $found );
		}
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
		foreach ( $entries as $entry ) {
			$runner->import_entry( $entry, $extract_dir, $results );
		}

		return $results;
	} finally {
		Day_One_Importer_Cleanup::remove( $run_dir );
	}
}

function day_one_importer_wp_env_import_from_zip_async( $zip_path ) {
	day_one_importer_wp_env_assert( class_exists( 'ZipArchive' ), 'Async import requires ZipArchive for chunked ZIP processing.' );

	$run_dir = Day_One_Importer_Cleanup::create_run_directory();
	day_one_importer_wp_env_assert( $run_dir, 'Async run directory created.' );
	$target_zip = trailingslashit( $run_dir ) . 'day-one-export.zip';
	day_one_importer_wp_env_assert( copy( $zip_path, $target_zip ), 'Async ZIP copied into protected run directory.' );
	Day_One_Importer_Cleanup::set_owner_only_permissions( $target_zip );

	$store = new Day_One_Importer_Job_Store();
	$job   = $store->create_job( get_current_user_id(), $run_dir, $target_zip, new Day_One_Importer_Results() );
	day_one_importer_wp_env_assert( is_array( $job ), 'Async job created.' );

	$processor = new Day_One_Importer_Job_Processor( $store );
	$batches   = 0;
	$status    = array();
	while ( $batches < 500 ) {
		++$batches;
		$status = $processor->process_batch( $job['id'], 'manual' );
		if ( ! empty( $status['is_terminal'] ) || 'failed' === $status['status'] ) {
			break;
		}
	}

	day_one_importer_wp_env_assert( $batches > 1, 'Async import required multiple processor batches with forced low limits.' );
	day_one_importer_wp_env_assert( ! empty( $status['is_terminal'] ) && 'completed' === $status['status'], 'Async import completed.' );

	$final_job = $store->get_job( $job['id'] );
	day_one_importer_wp_env_assert( is_array( $final_job ), 'Completed async job remains displayable.' );
	day_one_importer_wp_env_assert( ! is_dir( $run_dir ), 'Completed async import cleaned temporary files.' );

	return array(
		'results' => Day_One_Importer_Results::from_array( $final_job['results'] ),
		'batches' => $batches,
		'job'     => $final_job,
	);
}

// Start from a clean sample-import state so this smoke test is repeatable.
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

$day_one_importer_wp_env_return_one = static function () {
	return 1;
};
add_filter( 'day_one_importer_batch_zip_limit', $day_one_importer_wp_env_return_one );
add_filter( 'day_one_importer_batch_index_entry_limit', $day_one_importer_wp_env_return_one );
add_filter( 'day_one_importer_batch_entry_limit', $day_one_importer_wp_env_return_one );
add_filter( 'day_one_importer_batch_media_limit', $day_one_importer_wp_env_return_one );

$first_async  = day_one_importer_wp_env_import_from_zip_async( $sample_zip );
$first        = $first_async['results'];
$first_counts = $first->get_counts();

$created = isset( $first_counts['posts_created'] ) ? (int) $first_counts['posts_created'] : 0;
$media   = isset( $first_counts['media_imported'] ) ? (int) $first_counts['media_imported'] : 0;
if ( $using_default_zip ) {
	$entries_found = isset( $first_counts['entries_found'] ) ? (int) $first_counts['entries_found'] : 0;
	day_one_importer_wp_env_assert( 23 === $entries_found, 'Fictional fixture parsed twenty-three entries.' );
	day_one_importer_wp_env_assert( 23 === $created, 'Fictional fixture created exactly twenty-three private posts.' );
} else {
	day_one_importer_wp_env_assert( $created > 0, 'Created private posts from sample.' );
}
day_one_importer_wp_env_assert( $media > 0, 'Imported sample media attachments.' );
day_one_importer_wp_env_assert( '8' === Day_One_Importer_Runner::IMPORT_SCHEMA_VERSION, 'Import schema version is 8 for audio embeds (issue #58).' );

$imported_post_candidates = get_posts(
	array(
		'post_type'      => 'post',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'ID',
		'order'          => 'ASC',
	)
);
$imported_posts          = array();
foreach ( $imported_post_candidates as $post_id ) {
	if ( 'day-one-export' === (string) get_post_meta( (int) $post_id, '_day_one_source', true ) ) {
		$imported_posts[] = (int) $post_id;
	}
}

day_one_importer_wp_env_assert( count( $imported_posts ) === $created, 'Created post count matches query count.' );
$found_fixture_tag      = false;
$found_fixture_category = false;
foreach ( $imported_posts as $post_id ) {
	$post_id = (int) $post_id;
	day_one_importer_wp_env_assert( 'private' === get_post_status( $post_id ), 'Imported post is private.' );
	day_one_importer_wp_env_assert( '' !== get_post_meta( $post_id, '_day_one_uuid', true ), 'Imported post has Day One UUID.' );
	day_one_importer_wp_env_assert( 'day-one-export' === get_post_meta( $post_id, '_day_one_source', true ), 'Imported post has Day One source metadata.' );
	day_one_importer_wp_env_assert( '1' === get_post_meta( $post_id, '_day_one_import_complete', true ), 'Imported post marked complete.' );
	day_one_importer_wp_env_assert( Day_One_Importer_Runner::IMPORT_SCHEMA_VERSION === get_post_meta( $post_id, '_day_one_import_version', true ), 'Imported post has current import schema metadata.' );

	$post_content = (string) get_post_field( 'post_content', $post_id );
	day_one_importer_wp_env_assert( false !== strpos( $post_content, '<!-- wp:' ), 'Imported post content contains block comments.' );
	foreach ( array( ' width=', ' height=', ' loading=', ' decoding=', ' srcset=', ' sizes=' ) as $attr ) {
		day_one_importer_wp_env_assert( false === strpos( $post_content, $attr ), 'Imported block image markup omits runtime attribute ' . trim( $attr, ' =' ) . '.' );
	}

	$blocks         = array();
	$image_blocks   = array();
	$gallery_blocks = array();
	if ( function_exists( 'parse_blocks' ) ) {
		$blocks = parse_blocks( $post_content );
		day_one_importer_wp_env_assert( day_one_importer_wp_env_blocks_have_core_block( $blocks ), 'Imported post content parses as core blocks.' );
		day_one_importer_wp_env_collect_blocks_by_name( $blocks, 'core/image', $image_blocks );
		day_one_importer_wp_env_collect_blocks_by_name( $blocks, 'core/gallery', $gallery_blocks );

		if ( function_exists( 'serialize_blocks' ) ) {
			$reserialized = serialize_blocks( $blocks );
			day_one_importer_wp_env_assert( false !== strpos( $reserialized, '<!-- wp:' ), 'Parsed imported blocks can be serialized by WordPress.' );
		}
	}
	day_one_importer_wp_env_assert( false === strpos( $post_content, 'day-one-importer-photos' ), 'Imported post content omits old photo wrapper.' );
	day_one_importer_wp_env_assert( false === strpos( $post_content, '<h2>Imported photos</h2>' ), 'Imported post content omits old imported photos heading.' );

	$attachments    = get_attached_media( 'image', $post_id );
	$attachment_ids = array_map( 'intval', wp_list_pluck( $attachments, 'ID' ) );
	// #56 R14 — richText entries with photos but no inline embeds intentionally
	// produce zero image/gallery blocks (photos stay attached only). Detect this
	// case and skip the body-shape assertion below.
	$is_r14_case = ( empty( $image_blocks ) && empty( $gallery_blocks ) );
	// #57 — entry 0021 reuses the same photo identifier twice (once on each
	// side of an interleaved video embed), so it has 1 attached image but emits
	// 2 core/image blocks. The bespoke ordering check below this loop covers
	// entry 0021's body shape; skip the generic 1:1 assertion here.
	$post_uuid_meta = (string) get_post_meta( $post_id, '_day_one_uuid', true );
	$is_interleaved_video_case = ( 'FICTIONAL-SAMPLE-ENTRY-0021' === $post_uuid_meta );
	if ( 1 === count( $attachment_ids ) && ! $is_r14_case && ! $is_interleaved_video_case ) {
		day_one_importer_wp_env_assert( 1 === count( $image_blocks ), 'Post with one attachment contains exactly one Image block.' );
		$image_id = isset( $image_blocks[0]['attrs']['id'] ) ? (int) $image_blocks[0]['attrs']['id'] : 0;
		day_one_importer_wp_env_assert( in_array( $image_id, $attachment_ids, true ), 'Single Image block ID matches the attached media ID.' );
		day_one_importer_wp_env_assert( false !== strpos( (string) $image_blocks[0]['innerHTML'], 'wp-image-' . $image_id ), 'Single Image block inner HTML contains matching wp-image class.' );
	} elseif ( count( $attachment_ids ) >= 2 && ! $is_r14_case && ! $is_interleaved_video_case ) {
		day_one_importer_wp_env_assert( ! empty( $gallery_blocks ), 'Post with multiple attachments contains a Gallery block.' );

		$content_attachment_positions = array();
		foreach ( $attachment_ids as $attachment_id ) {
			$position = strpos( $post_content, 'wp-image-' . $attachment_id );
			if ( false !== $position ) {
				$content_attachment_positions[ $attachment_id ] = $position;
			}
		}
		asort( $content_attachment_positions );
		$ordered_content_attachment_ids = array_map( 'intval', array_keys( $content_attachment_positions ) );

		$gallery_ids = isset( $gallery_blocks[0]['attrs']['ids'] ) && is_array( $gallery_blocks[0]['attrs']['ids'] ) ? array_map( 'intval', $gallery_blocks[0]['attrs']['ids'] ) : array();
		day_one_importer_wp_env_assert( $ordered_content_attachment_ids === $gallery_ids, 'Gallery block IDs match the attached media IDs in content order.' );

		$nested_image_blocks = array();
		day_one_importer_wp_env_collect_blocks_by_name( $gallery_blocks[0]['innerBlocks'], 'core/image', $nested_image_blocks );
		day_one_importer_wp_env_assert( count( $nested_image_blocks ) === count( $gallery_ids ), 'Gallery contains one nested Image block for each Gallery ID.' );
		foreach ( $gallery_ids as $index => $attachment_id ) {
			$nested_id = isset( $nested_image_blocks[ $index ]['attrs']['id'] ) ? (int) $nested_image_blocks[ $index ]['attrs']['id'] : 0;
			day_one_importer_wp_env_assert( $attachment_id === $nested_id, 'Nested Image block ID matches its Gallery ID.' );
			day_one_importer_wp_env_assert( false !== strpos( (string) $nested_image_blocks[ $index ]['innerHTML'], 'wp-image-' . $attachment_id ), 'Nested Image block inner HTML contains matching wp-image class.' );
		}
	}

	if ( $using_default_zip && has_term( 'fictional', 'post_tag', $post_id ) ) {
		$found_fixture_tag = true;
	}
	if ( $using_default_zip && has_term( 'Fictional Journal', 'category', $post_id ) ) {
		$found_fixture_category = true;
	}
}
if ( $using_default_zip ) {
	day_one_importer_wp_env_assert( $found_fixture_tag, 'Expected fictional fixture tag exists on imported posts.' );
	day_one_importer_wp_env_assert( $found_fixture_category, 'Expected fictional journal category exists on imported posts.' );

	// --- richText scaffold assertions (issue #53) ---
	// Locate each scaffold fixture entry by its known UUID via post meta.
	$day_one_importer_uuid_to_post_id = array();
	foreach ( $imported_posts as $post_id ) {
		$uuid = (string) get_post_meta( (int) $post_id, '_day_one_uuid', true );
		if ( '' !== $uuid ) {
			$day_one_importer_uuid_to_post_id[ $uuid ] = (int) $post_id;
		}
	}

	// R10.1 — richText (JSON-string form) produces a paragraph block with the expected text.
	$entry_0004_post_id = isset( $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0004'] ) ? $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0004'] : 0;
	day_one_importer_wp_env_assert( $entry_0004_post_id > 0, 'Fixture entry 0004 (richText as JSON-encoded string) was imported.' );
	if ( $entry_0004_post_id > 0 && function_exists( 'parse_blocks' ) ) {
		$entry_0004_content    = (string) get_post_field( 'post_content', $entry_0004_post_id );
		$entry_0004_blocks     = parse_blocks( $entry_0004_content );
		$entry_0004_paragraphs = array();
		day_one_importer_wp_env_collect_blocks_by_name( $entry_0004_blocks, 'core/paragraph', $entry_0004_paragraphs );
		$found_marigold_paragraph = false;
		foreach ( $entry_0004_paragraphs as $paragraph ) {
			if ( false !== strpos( (string) $paragraph['innerHTML'], 'Imaginary trip to the paper marigolds' ) ) {
				$found_marigold_paragraph = true;
				break;
			}
		}
		day_one_importer_wp_env_assert( $found_marigold_paragraph, 'Entry 0004 produces a core/paragraph block from the JSON-string richText payload.' );
	}

	// R10.2 / A13 — byte-for-byte legacy regression assertion against entry 0003 (text-only, no media).
	$entry_0003_post_id = isset( $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0003'] ) ? $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0003'] : 0;
	day_one_importer_wp_env_assert( $entry_0003_post_id > 0, 'Legacy fixture entry 0003 (text-only) was imported.' );
	if ( $entry_0003_post_id > 0 ) {
		$entry_0003_legacy_text = "Quiet text-only entry for the imaginary mooncake tasting club.\n\nThis entry has no media and exists only to exercise text-only imports with paragraph breaks.";
		$entry_0003_expected    = Day_One_Importer_Content::convert_text_to_content( $entry_0003_legacy_text );
		$entry_0003_actual      = (string) get_post_field( 'post_content', $entry_0003_post_id );
		day_one_importer_wp_env_assert( $entry_0003_expected === $entry_0003_actual, 'Entry 0003 post_content is byte-for-byte equal to convert_text_to_content( legacy text ) — no regression on the legacy path.' );
	}

	// R10.3 / A7 — photo-append still works for a richText entry. Entry 0007 carries one photo (md5-deduped with entry 0001).
	$entry_0007_post_id = isset( $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0007'] ) ? $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0007'] : 0;
	day_one_importer_wp_env_assert( $entry_0007_post_id > 0, 'Fixture entry 0007 (richText + 1 photo) was imported.' );
	if ( $entry_0007_post_id > 0 ) {
		$entry_0007_attachments    = get_attached_media( 'image', $entry_0007_post_id );
		$entry_0007_attachment_ids = array_map( 'intval', wp_list_pluck( $entry_0007_attachments, 'ID' ) );
		day_one_importer_wp_env_assert( 1 === count( $entry_0007_attachment_ids ), 'Entry 0007 (richText + photo) has exactly one attached image (photo-append behaviour preserved).' );
	}

	// R20 / AC9a (hard) — entry 0008 richly-formatted content survives wp_kses_post for the basic wrappers.
	// AC9b (soft) — <mark style="…"> survival logged on failure (not fatal).
	$entry_0008_post_id = isset( $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0008'] ) ? $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0008'] : 0;
	day_one_importer_wp_env_assert( $entry_0008_post_id > 0, 'Fixture entry 0008 (richText inline formatting) was imported.' );
	if ( $entry_0008_post_id > 0 && function_exists( 'parse_blocks' ) ) {
		$entry_0008_content    = (string) get_post_field( 'post_content', $entry_0008_post_id );
		$entry_0008_blocks     = parse_blocks( $entry_0008_content );
		$entry_0008_paragraphs = array();
		day_one_importer_wp_env_collect_blocks_by_name( $entry_0008_blocks, 'core/paragraph', $entry_0008_paragraphs );
		$entry_0008_inner_concat = '';
		foreach ( $entry_0008_paragraphs as $paragraph ) {
			$entry_0008_inner_concat .= (string) $paragraph['innerHTML'];
		}

		// AC9a — basic wrappers MUST survive wp_kses_post (hard assert).
		day_one_importer_wp_env_assert(
			false !== strpos( $entry_0008_inner_concat, '<strong>Bold sample run.</strong>' ),
			'Entry 0008 — <strong> wrapper survives wp_kses_post.'
		);
		day_one_importer_wp_env_assert(
			false !== strpos( $entry_0008_inner_concat, '<em>Italic sample run.</em>' ),
			'Entry 0008 — <em> wrapper survives wp_kses_post.'
		);
		day_one_importer_wp_env_assert(
			false !== strpos( $entry_0008_inner_concat, '<s>Strikethrough sample run.</s>' ),
			'Entry 0008 — <s> wrapper survives wp_kses_post.'
		);
		day_one_importer_wp_env_assert(
			false !== strpos( $entry_0008_inner_concat, '<code>Inline code sample.</code>' ),
			'Entry 0008 — <code> wrapper survives wp_kses_post.'
		);
		day_one_importer_wp_env_assert(
			false !== strpos( $entry_0008_inner_concat, '<a href="https://example.com/fictional-link">Linked sample run.</a>' ),
			'Entry 0008 — <a href> wrapper survives wp_kses_post.'
		);

		// R18 canonical multi-attribute combination — hard assert via AC9a basic wrappers.
		// The outer <mark> is checked separately as a soft assert (AC9b) so a future kses tightening
		// on <mark> does not fail the whole test.
		day_one_importer_wp_env_assert(
			false !== strpos( $entry_0008_inner_concat, '<a href="https://example.com/x">' ),
			'Entry 0008 — R18 combination: outer <a> wrapper present.'
		);
		day_one_importer_wp_env_assert(
			false !== strpos( $entry_0008_inner_concat, '<strong><em><s><code>hello</code></s></em></strong>' ),
			'Entry 0008 — R18 combination: inner wrapper stack pinned byte-for-byte.'
		);

		// AC9b (soft) — <mark style="background-color:#RRGGBB"> survival is logged on failure, not fatal.
		if ( false === strpos( $entry_0008_inner_concat, '<mark style="background-color:#FFEE99">Highlighted sample run.</mark>' ) ) {
			fwrite( STDERR, "SOFT-WARN: Entry 0008 — <mark style=\"background-color:#FFEE99\"> did not survive wp_kses_post; logged per AC9b.\n" );
		}
		if ( false === strpos( $entry_0008_inner_concat, '<mark style="background-color:#000FFF">' ) ) {
			fwrite( STDERR, "SOFT-WARN: Entry 0008 — R18 <mark style=\"background-color:#000FFF\"> did not survive wp_kses_post; logged per AC9b.\n" );
		}

		// R7 negative path — rejected linkURL drops anchor; run text still renders.
		day_one_importer_wp_env_assert(
			false === strpos( $entry_0008_inner_concat, 'javascript:alert(1)' ),
			'Entry 0008 — rejected javascript: URL does not leak into post content.'
		);
		day_one_importer_wp_env_assert(
			false !== strpos( $entry_0008_inner_concat, 'Bad link sample run.' ),
			'Entry 0008 — rejected-link run text still renders.'
		);

		// R9 negative path — malformed highlightedColor drops <mark>; run text still renders.
		day_one_importer_wp_env_assert(
			false === strpos( $entry_0008_inner_concat, '0xZZZZZZ' ),
			'Entry 0008 — rejected highlight color does not leak into post content.'
		);
		day_one_importer_wp_env_assert(
			false !== strpos( $entry_0008_inner_concat, 'Bad color sample run.' ),
			'Entry 0008 — rejected-color run text still renders.'
		);
	}

	// --- richText line-attribute assertions (issue #55) ---
	// Entries 0009-0017 exercise heading / list / code / quote emitters and
	// the transparent-drop + run-collapse contracts pinned in spec R2-R6.

	// AC1 / AC2 / AC16 / R3.3 — entry 0009 covers headings 1..6, adjacent
	// header:2 pair (no inter-collapse), multi-line heading, and the
	// header:0 paragraph fall-through.
	$entry_0009_post_id = isset( $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0009'] ) ? $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0009'] : 0;
	day_one_importer_wp_env_assert( $entry_0009_post_id > 0, 'Fixture entry 0009 (heading levels) was imported.' );
	if ( $entry_0009_post_id > 0 && function_exists( 'parse_blocks' ) ) {
		$entry_0009_content  = (string) get_post_field( 'post_content', $entry_0009_post_id );
		$entry_0009_blocks   = parse_blocks( $entry_0009_content );
		$entry_0009_headings = array();
		day_one_importer_wp_env_collect_blocks_by_name( $entry_0009_blocks, 'core/heading', $entry_0009_headings );

		// 8 heading items in the fixture: levels 1, 2, 2, 3, 4, 5, 6, and a multi-line level 2 = 8 core/heading blocks.
		day_one_importer_wp_env_assert( 8 === count( $entry_0009_headings ), 'Entry 0009 — exactly 8 core/heading blocks emitted.' );

		// Count adjacent header:2 — fixture has three level-2 headings (two adjacent + one multi-line later).
		$entry_0009_level_2 = 0;
		foreach ( $entry_0009_headings as $h ) {
			$level = isset( $h['attrs']['level'] ) ? (int) $h['attrs']['level'] : 2;
			if ( 2 === $level ) {
				++$entry_0009_level_2;
			}
		}
		day_one_importer_wp_env_assert( $entry_0009_level_2 >= 2, 'Entry 0009 — at least two distinct core/heading blocks at level 2 (R2.3 no inter-collapse).' );

		// Multi-line heading survives via <br />.
		day_one_importer_wp_env_assert( false !== strpos( $entry_0009_content, "<h2>line one<br />\nline two</h2>" ), 'Entry 0009 — multi-line header:2 renders as <h2>line one<br />\\nline two</h2>.' );

		// header:0 falls through to a paragraph block.
		$entry_0009_paragraphs = array();
		day_one_importer_wp_env_collect_blocks_by_name( $entry_0009_blocks, 'core/paragraph', $entry_0009_paragraphs );
		$found_fallback = false;
		foreach ( $entry_0009_paragraphs as $p ) {
			if ( false !== strpos( (string) $p['innerHTML'], 'Header zero falls through to paragraph.' ) ) {
				$found_fallback = true;
				break;
			}
		}
		day_one_importer_wp_env_assert( $found_fallback, 'Entry 0009 — header:0 item falls through to a core/paragraph block.' );
	}

	// AC3 / AC4 — entry 0010: bulleted list with nested level (structural assert via innerBlocks, NOT substring).
	$entry_0010_post_id = isset( $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0010'] ) ? $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0010'] : 0;
	day_one_importer_wp_env_assert( $entry_0010_post_id > 0, 'Fixture entry 0010 (bulleted nesting) was imported.' );
	if ( $entry_0010_post_id > 0 && function_exists( 'parse_blocks' ) ) {
		$entry_0010_content = (string) get_post_field( 'post_content', $entry_0010_post_id );
		$entry_0010_blocks  = parse_blocks( $entry_0010_content );
		$entry_0010_outer   = array();
		foreach ( $entry_0010_blocks as $blk ) {
			if ( isset( $blk['blockName'] ) && 'core/list' === $blk['blockName'] ) {
				$entry_0010_outer[] = $blk;
			}
		}
		day_one_importer_wp_env_assert( 1 === count( $entry_0010_outer ), 'Entry 0010 — exactly one outer core/list block.' );

		// Outer block has one list-item that itself contains a nested core/list.
		$found_nested = false;
		if ( ! empty( $entry_0010_outer ) && isset( $entry_0010_outer[0]['innerBlocks'] ) ) {
			foreach ( $entry_0010_outer[0]['innerBlocks'] as $li ) {
				if ( ! empty( $li['innerBlocks'] ) ) {
					foreach ( $li['innerBlocks'] as $child ) {
						if ( isset( $child['blockName'] ) && 'core/list' === $child['blockName'] ) {
							$found_nested = true;
							break 2;
						}
					}
				}
			}
		}
		day_one_importer_wp_env_assert( $found_nested, 'Entry 0010 — nested core/list lives inside a parent core/list-item (parent-child shape, R4.4).' );
	}

	// AC6 / AC7 — entry 0011: outer numbered list with start:5; nested numbered list with no start.
	$entry_0011_post_id = isset( $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0011'] ) ? $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0011'] : 0;
	day_one_importer_wp_env_assert( $entry_0011_post_id > 0, 'Fixture entry 0011 (numbered start:5 + nested) was imported.' );
	if ( $entry_0011_post_id > 0 && function_exists( 'parse_blocks' ) ) {
		$entry_0011_content = (string) get_post_field( 'post_content', $entry_0011_post_id );
		$entry_0011_blocks  = parse_blocks( $entry_0011_content );
		$entry_0011_outer   = isset( $entry_0011_blocks[0] ) && isset( $entry_0011_blocks[0]['blockName'] ) && 'core/list' === $entry_0011_blocks[0]['blockName'] ? $entry_0011_blocks[0] : null;
		day_one_importer_wp_env_assert( null !== $entry_0011_outer, 'Entry 0011 — outermost block is core/list.' );
		if ( null !== $entry_0011_outer ) {
			day_one_importer_wp_env_assert( isset( $entry_0011_outer['attrs']['ordered'] ) && true === $entry_0011_outer['attrs']['ordered'], 'Entry 0011 — outer list has ordered:true.' );
			day_one_importer_wp_env_assert( isset( $entry_0011_outer['attrs']['start'] ) && 5 === (int) $entry_0011_outer['attrs']['start'], 'Entry 0011 — outer list has start:5.' );

			$entry_0011_nested = null;
			foreach ( $entry_0011_outer['innerBlocks'] as $li ) {
				if ( ! empty( $li['innerBlocks'] ) ) {
					foreach ( $li['innerBlocks'] as $child ) {
						if ( isset( $child['blockName'] ) && 'core/list' === $child['blockName'] ) {
							$entry_0011_nested = $child;
							break 2;
						}
					}
				}
			}
			day_one_importer_wp_env_assert( null !== $entry_0011_nested, 'Entry 0011 — nested core/list is present inside the parent list-item.' );
			if ( null !== $entry_0011_nested ) {
				day_one_importer_wp_env_assert( isset( $entry_0011_nested['attrs']['ordered'] ) && true === $entry_0011_nested['attrs']['ordered'], 'Entry 0011 — nested list has ordered:true.' );
				day_one_importer_wp_env_assert( ! isset( $entry_0011_nested['attrs']['start'] ), 'Entry 0011 — nested numbered list has NO start attr (R4.5).' );
			}
		}
	}

	// AC5 — entry 0012: numbered list with first listIndex:1 emits NO start attr.
	$entry_0012_post_id = isset( $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0012'] ) ? $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0012'] : 0;
	day_one_importer_wp_env_assert( $entry_0012_post_id > 0, 'Fixture entry 0012 (numbered default start) was imported.' );
	if ( $entry_0012_post_id > 0 && function_exists( 'parse_blocks' ) ) {
		$entry_0012_content = (string) get_post_field( 'post_content', $entry_0012_post_id );
		$entry_0012_blocks  = parse_blocks( $entry_0012_content );
		$entry_0012_outer   = isset( $entry_0012_blocks[0] ) && isset( $entry_0012_blocks[0]['blockName'] ) && 'core/list' === $entry_0012_blocks[0]['blockName'] ? $entry_0012_blocks[0] : null;
		day_one_importer_wp_env_assert( null !== $entry_0012_outer, 'Entry 0012 — outermost block is core/list.' );
		if ( null !== $entry_0012_outer ) {
			day_one_importer_wp_env_assert( isset( $entry_0012_outer['attrs']['ordered'] ) && true === $entry_0012_outer['attrs']['ordered'], 'Entry 0012 — outer list has ordered:true.' );
			day_one_importer_wp_env_assert( ! isset( $entry_0012_outer['attrs']['start'] ), 'Entry 0012 — numbered list with first listIndex:1 has NO start attr (AC5).' );
		}
	}

	// AC8 — entry 0013: checkbox list with task-list className; Unicode glyphs survive wp_kses_post.
	$entry_0013_post_id = isset( $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0013'] ) ? $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0013'] : 0;
	day_one_importer_wp_env_assert( $entry_0013_post_id > 0, 'Fixture entry 0013 (checkbox list) was imported.' );
	if ( $entry_0013_post_id > 0 && function_exists( 'parse_blocks' ) ) {
		$entry_0013_content = (string) get_post_field( 'post_content', $entry_0013_post_id );
		$entry_0013_blocks  = parse_blocks( $entry_0013_content );
		$entry_0013_outer   = isset( $entry_0013_blocks[0] ) && isset( $entry_0013_blocks[0]['blockName'] ) && 'core/list' === $entry_0013_blocks[0]['blockName'] ? $entry_0013_blocks[0] : null;
		day_one_importer_wp_env_assert( null !== $entry_0013_outer, 'Entry 0013 — outermost block is core/list.' );
		if ( null !== $entry_0013_outer ) {
			day_one_importer_wp_env_assert( isset( $entry_0013_outer['attrs']['className'] ) && 'task-list' === $entry_0013_outer['attrs']['className'], 'Entry 0013 — checkbox list has className=task-list.' );
		}
		day_one_importer_wp_env_assert( false !== strpos( $entry_0013_content, '<ul class="task-list">' ), 'Entry 0013 — outer <ul class="task-list"> survives wp_kses_post.' );
		day_one_importer_wp_env_assert( false !== strpos( $entry_0013_content, '&#9745;' ), 'Entry 0013 — &#9745; checked glyph survives wp_kses_post byte-identically.' );
		day_one_importer_wp_env_assert( false !== strpos( $entry_0013_content, '&#9744;' ), 'Entry 0013 — &#9744; unchecked glyph survives wp_kses_post byte-identically.' );
		day_one_importer_wp_env_assert( false !== strpos( $entry_0013_content, '<li>&#9745; Checked box alpha</li>' ), 'Entry 0013 — checked:true list-item carries &#9745; + ASCII space before its text.' );
		day_one_importer_wp_env_assert( false !== strpos( $entry_0013_content, '<li>&#9744; Unchecked box beta</li>' ), 'Entry 0013 — checked:false list-item carries &#9744; + ASCII space before its text.' );
	}

	// AC9 — entry 0014: 3-line code block. Byte-exact a\nb\nc inside <code>; no <a> / <strong> / <mark>; no warnings recorded.
	$entry_0014_post_id = isset( $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0014'] ) ? $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0014'] : 0;
	day_one_importer_wp_env_assert( $entry_0014_post_id > 0, 'Fixture entry 0014 (code block) was imported.' );
	if ( $entry_0014_post_id > 0 && function_exists( 'parse_blocks' ) ) {
		$entry_0014_content = (string) get_post_field( 'post_content', $entry_0014_post_id );
		$entry_0014_blocks  = parse_blocks( $entry_0014_content );
		$entry_0014_codes   = array();
		day_one_importer_wp_env_collect_blocks_by_name( $entry_0014_blocks, 'core/code', $entry_0014_codes );
		day_one_importer_wp_env_assert( 1 === count( $entry_0014_codes ), 'Entry 0014 — exactly one core/code block.' );
		day_one_importer_wp_env_assert( false !== strpos( $entry_0014_content, "<code>a\nb\nc</code>" ), 'Entry 0014 — <code> inner is a\\nb\\nc byte-exact.' );
		day_one_importer_wp_env_assert( false === strpos( $entry_0014_content, '<strong>' ), 'Entry 0014 — code block has NO <strong> inside (R5.3).' );
		day_one_importer_wp_env_assert( false === strpos( $entry_0014_content, '<a ' ), 'Entry 0014 — code block has NO <a> inside (invalid linkURL was on a code item).' );
		day_one_importer_wp_env_assert( false === strpos( $entry_0014_content, '<mark' ), 'Entry 0014 — code block has NO <mark> inside (invalid highlightedColor was on a code item).' );
	}

	// AC10 — entry 0015: multi-paragraph quote (indent ignored).
	$entry_0015_post_id = isset( $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0015'] ) ? $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0015'] : 0;
	day_one_importer_wp_env_assert( $entry_0015_post_id > 0, 'Fixture entry 0015 (multi-paragraph quote) was imported.' );
	if ( $entry_0015_post_id > 0 && function_exists( 'parse_blocks' ) ) {
		$entry_0015_content = (string) get_post_field( 'post_content', $entry_0015_post_id );
		$entry_0015_blocks  = parse_blocks( $entry_0015_content );
		$entry_0015_quotes  = array();
		day_one_importer_wp_env_collect_blocks_by_name( $entry_0015_blocks, 'core/quote', $entry_0015_quotes );
		day_one_importer_wp_env_assert( 1 === count( $entry_0015_quotes ), 'Entry 0015 — exactly one core/quote block.' );
		$entry_0015_inner_paragraphs = array();
		if ( ! empty( $entry_0015_quotes ) ) {
			day_one_importer_wp_env_collect_blocks_by_name( $entry_0015_quotes[0]['innerBlocks'], 'core/paragraph', $entry_0015_inner_paragraphs );
		}
		day_one_importer_wp_env_assert( 2 === count( $entry_0015_inner_paragraphs ), 'Entry 0015 — core/quote contains two child core/paragraph blocks.' );
		day_one_importer_wp_env_assert( 1 === substr_count( $entry_0015_content, '<blockquote' ), 'Entry 0015 — exactly one <blockquote> in post_content (indentLevel ignored, R6.5).' );
		day_one_importer_wp_env_assert( false !== strpos( $entry_0015_content, '<strong>Imaginary quote second paragraph.</strong>' ), 'Entry 0015 — inline <strong> wrapper survives inside the quote child paragraph (R6.2).' );
	}

	// AC15 — entry 0016: mixed-line-types entry. Pin block counts and kses survival of basic wrappers.
	$entry_0016_post_id = isset( $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0016'] ) ? $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0016'] : 0;
	day_one_importer_wp_env_assert( $entry_0016_post_id > 0, 'Fixture entry 0016 (mixed line types) was imported.' );
	if ( $entry_0016_post_id > 0 && function_exists( 'parse_blocks' ) ) {
		$entry_0016_content    = (string) get_post_field( 'post_content', $entry_0016_post_id );
		$entry_0016_blocks     = parse_blocks( $entry_0016_content );
		$entry_0016_top_heads  = 0;
		$entry_0016_top_lists  = 0;
		$entry_0016_top_codes  = 0;
		$entry_0016_top_quotes = 0;
		$entry_0016_top_paras  = 0;
		foreach ( $entry_0016_blocks as $blk ) {
			if ( ! isset( $blk['blockName'] ) ) {
				continue;
			}
			switch ( $blk['blockName'] ) {
				case 'core/heading':
					++$entry_0016_top_heads;
					break;
				case 'core/list':
					++$entry_0016_top_lists;
					break;
				case 'core/code':
					++$entry_0016_top_codes;
					break;
				case 'core/quote':
					++$entry_0016_top_quotes;
					break;
				case 'core/paragraph':
					++$entry_0016_top_paras;
					break;
			}
		}
		day_one_importer_wp_env_assert( 1 === $entry_0016_top_heads, 'Entry 0016 — exactly 1 top-level core/heading block.' );
		day_one_importer_wp_env_assert( 2 === $entry_0016_top_lists, 'Entry 0016 — exactly 2 top-level core/list blocks (bulleted + numbered).' );
		day_one_importer_wp_env_assert( 1 === $entry_0016_top_codes, 'Entry 0016 — exactly 1 top-level core/code block.' );
		day_one_importer_wp_env_assert( 1 === $entry_0016_top_quotes, 'Entry 0016 — exactly 1 top-level core/quote block.' );
		day_one_importer_wp_env_assert( 2 === $entry_0016_top_paras, 'Entry 0016 — exactly 2 top-level core/paragraph blocks (lead + trailing).' );

		// List-item count across both lists.
		$entry_0016_list_items = array();
		day_one_importer_wp_env_collect_blocks_by_name( $entry_0016_blocks, 'core/list-item', $entry_0016_list_items );
		day_one_importer_wp_env_assert( 4 === count( $entry_0016_list_items ), 'Entry 0016 — exactly 4 core/list-item blocks across both lists.' );

		// kses survival of basic wrappers (hard asserts per AC15).
		day_one_importer_wp_env_assert( false !== strpos( $entry_0016_content, '<h1>' ), 'Entry 0016 — <h1> survives wp_kses_post.' );
		day_one_importer_wp_env_assert( false !== strpos( $entry_0016_content, '<ul>' ), 'Entry 0016 — <ul> survives wp_kses_post.' );
		day_one_importer_wp_env_assert( false !== strpos( $entry_0016_content, '<ol>' ), 'Entry 0016 — <ol> survives wp_kses_post.' );
		day_one_importer_wp_env_assert( false !== strpos( $entry_0016_content, '<li>' ), 'Entry 0016 — <li> survives wp_kses_post.' );
		day_one_importer_wp_env_assert( false !== strpos( $entry_0016_content, '<pre' ), 'Entry 0016 — <pre> survives wp_kses_post.' );
		day_one_importer_wp_env_assert( false !== strpos( $entry_0016_content, '<code>' ), 'Entry 0016 — <code> survives wp_kses_post.' );
		day_one_importer_wp_env_assert( false !== strpos( $entry_0016_content, '<blockquote' ), 'Entry 0016 — <blockquote> survives wp_kses_post.' );
	}

	// AC11 — entry 0017: transparent drop inside a bulleted run -> ONE list with TWO list-items.
	$entry_0017_post_id = isset( $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0017'] ) ? $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0017'] : 0;
	day_one_importer_wp_env_assert( $entry_0017_post_id > 0, 'Fixture entry 0017 (transparent drop) was imported.' );
	if ( $entry_0017_post_id > 0 && function_exists( 'parse_blocks' ) ) {
		$entry_0017_content    = (string) get_post_field( 'post_content', $entry_0017_post_id );
		$entry_0017_blocks     = parse_blocks( $entry_0017_content );
		$entry_0017_lists      = array();
		$entry_0017_list_items = array();
		day_one_importer_wp_env_collect_blocks_by_name( $entry_0017_blocks, 'core/list', $entry_0017_lists );
		day_one_importer_wp_env_collect_blocks_by_name( $entry_0017_blocks, 'core/list-item', $entry_0017_list_items );
		day_one_importer_wp_env_assert( 1 === count( $entry_0017_lists ), 'Entry 0017 — empty-text item is transparent: same-kind run stays as ONE core/list.' );
		day_one_importer_wp_env_assert( 2 === count( $entry_0017_list_items ), 'Entry 0017 — empty-text item is dropped: only two core/list-item blocks.' );
	}

	// --- #56 inline positioning + placeholder regex assertions ---

	// #56 AC11 — byte-parity range. Entries 0001-0006 + 0008-0017 must match
	// pre-#56 baselines captured by tools/capture-baseline.php. The baseline and
	// the live import allocate fresh post/attachment IDs and per-session nonces,
	// so we normalize the volatile substrings (attachment IDs in three render
	// shapes + the private-media nonce) before comparing. The assertion focuses
	// on the rendered block structure, not on ID/nonce churn.
	$day_one_56_normalize = static function ( $html ) {
		$html = preg_replace( '/(&(?:#038;)?nonce=)[A-Za-z0-9]+/', '$1NONCE', (string) $html );
		$html = preg_replace( '/("id":)\d+/', '$1ID', $html );
		$html = preg_replace( '/(attachment_id=)\d+/', '$1ID', $html );
		$html = preg_replace( '/(wp-image-)\d+/', '$1ID', $html );
		return $html;
	};
	$day_one_56_expected_dir = dirname( __DIR__ ) . '/tests/fixtures/expected';
	$day_one_56_parity_uuids = array(
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
	foreach ( $day_one_56_parity_uuids as $uuid ) {
		$expected_path = $day_one_56_expected_dir . '/post-' . $uuid . '.html';
		if ( ! is_readable( $expected_path ) ) {
			fwrite( STDERR, "FAIL: Missing #56 baseline: {$expected_path}\n" );
			exit( 1 );
		}
		$expected      = (string) file_get_contents( $expected_path );
		$parity_pid    = isset( $day_one_importer_uuid_to_post_id[ $uuid ] ) ? (int) $day_one_importer_uuid_to_post_id[ $uuid ] : 0;
		day_one_importer_wp_env_assert( $parity_pid > 0, "#56 AC11 — byte-parity entry imported: {$uuid}" );
		$actual = (string) get_post_field( 'post_content', $parity_pid );
		day_one_importer_wp_env_assert(
			$day_one_56_normalize( $expected ) === $day_one_56_normalize( $actual ),
			"#56 AC11 — post_content byte-identical to baseline (ID/nonce-normalized) for {$uuid}"
		);
	}

	// #56 AC11 entry 0018 — inline image between two paragraphs; no trailing gallery.
	$entry_0018_post_id = isset( $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0018'] ) ? $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0018'] : 0;
	day_one_importer_wp_env_assert( $entry_0018_post_id > 0, '#56 AC11 — Fixture entry 0018 (inline embedded photo) was imported.' );
	if ( $entry_0018_post_id > 0 && function_exists( 'parse_blocks' ) ) {
		$entry_0018_content    = (string) get_post_field( 'post_content', $entry_0018_post_id );
		$entry_0018_blocks     = parse_blocks( $entry_0018_content );
		$entry_0018_paragraphs = array();
		$entry_0018_images     = array();
		$entry_0018_galleries  = array();
		day_one_importer_wp_env_collect_blocks_by_name( $entry_0018_blocks, 'core/paragraph', $entry_0018_paragraphs );
		day_one_importer_wp_env_collect_blocks_by_name( $entry_0018_blocks, 'core/image', $entry_0018_images );
		day_one_importer_wp_env_collect_blocks_by_name( $entry_0018_blocks, 'core/gallery', $entry_0018_galleries );
		day_one_importer_wp_env_assert( 2 === count( $entry_0018_paragraphs ), '#56 AC11 — entry 0018 has exactly two core/paragraph blocks.' );
		day_one_importer_wp_env_assert( 1 === count( $entry_0018_images ) && 0 === count( $entry_0018_galleries ), '#56 AC11 — entry 0018 has exactly one core/image block and zero galleries (single resolved embed).' );

		// Assert block order: paragraph, image, paragraph at the top level.
		$entry_0018_top_names = array();
		foreach ( $entry_0018_blocks as $blk ) {
			if ( ! empty( $blk['blockName'] ) ) {
				$entry_0018_top_names[] = $blk['blockName'];
			}
		}
		day_one_importer_wp_env_assert(
			$entry_0018_top_names === array( 'core/paragraph', 'core/image', 'core/paragraph' ),
			'#56 AC11 — entry 0018 top-level block order is paragraph, image, paragraph (inline position).'
		);

		// Assert the LAST top-level block is the trailing paragraph (no append-at-end).
		$last_block_name = '';
		for ( $i = count( $entry_0018_blocks ) - 1; $i >= 0; --$i ) {
			if ( ! empty( $entry_0018_blocks[ $i ]['blockName'] ) ) {
				$last_block_name = (string) $entry_0018_blocks[ $i ]['blockName'];
				break;
			}
		}
		day_one_importer_wp_env_assert( 'core/paragraph' === $last_block_name, '#56 AC11 — entry 0018 last block is a paragraph (no trailing append).' );

		// The image's attachment ID must reference an actual attachment parented to entry 0018.
		// (Per-post md5 dedupe in import_or_reuse_photo() creates one attachment per post for
		// shared on-disk files; the cross-post identity asserted here is "attachment exists
		// and is parented to this post", not "same attachment ID as entry 0007".)
		$entry_0018_image_id = isset( $entry_0018_images[0]['attrs']['id'] ) ? (int) $entry_0018_images[0]['attrs']['id'] : 0;
		day_one_importer_wp_env_assert( $entry_0018_image_id > 0, '#56 AC11 — entry 0018 image block references a positive attachment ID.' );
		$entry_0018_attachments    = get_attached_media( 'image', $entry_0018_post_id );
		$entry_0018_attachment_ids = array_map( 'intval', wp_list_pluck( $entry_0018_attachments, 'ID' ) );
		day_one_importer_wp_env_assert( in_array( $entry_0018_image_id, $entry_0018_attachment_ids, true ), '#56 AC11 — entry 0018 image attachment is parented to the entry 0018 post.' );
	}

	// #56 AC11a — entry 0007 R14 case: paragraph only, no image/gallery, one R14 warning.
	if ( $entry_0007_post_id > 0 && function_exists( 'parse_blocks' ) ) {
		$entry_0007_content    = (string) get_post_field( 'post_content', $entry_0007_post_id );
		$entry_0007_blocks     = parse_blocks( $entry_0007_content );
		$entry_0007_paragraphs = array();
		$entry_0007_images     = array();
		$entry_0007_galleries  = array();
		day_one_importer_wp_env_collect_blocks_by_name( $entry_0007_blocks, 'core/paragraph', $entry_0007_paragraphs );
		day_one_importer_wp_env_collect_blocks_by_name( $entry_0007_blocks, 'core/image', $entry_0007_images );
		day_one_importer_wp_env_collect_blocks_by_name( $entry_0007_blocks, 'core/gallery', $entry_0007_galleries );
		day_one_importer_wp_env_assert( 1 === count( $entry_0007_paragraphs ), '#56 AC11a — entry 0007 has exactly one core/paragraph block.' );
		day_one_importer_wp_env_assert( 0 === count( $entry_0007_images ), '#56 AC11a — entry 0007 has zero core/image blocks (R14 case).' );
		day_one_importer_wp_env_assert( 0 === count( $entry_0007_galleries ), '#56 AC11a — entry 0007 has zero core/gallery blocks (R14 case).' );

		$first_warnings = $first->get_warnings();
		$r14_substr     = 'imported photos but no inline embed';
		$r14_count      = 0;
		foreach ( $first_warnings as $warning ) {
			if ( false !== strpos( (string) $warning, $r14_substr ) ) {
				++$r14_count;
			}
		}
		day_one_importer_wp_env_assert( 1 === $r14_count, '#56 AC11a — exactly one R14 warning recorded during import for entry 0007.' );
	}

	// #56 AC12 — entry 0019: legacy placeholders strip from both post_content and post_title.
	$entry_0019_post_id = isset( $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0019'] ) ? $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0019'] : 0;
	day_one_importer_wp_env_assert( $entry_0019_post_id > 0, '#56 AC12 — Fixture entry 0019 (legacy placeholders) was imported.' );
	if ( $entry_0019_post_id > 0 ) {
		$entry_0019_content = (string) get_post_field( 'post_content', $entry_0019_post_id );
		$entry_0019_title   = (string) get_post_field( 'post_title', $entry_0019_post_id );
		day_one_importer_wp_env_assert( false !== strpos( $entry_0019_content, 'Before placeholders.' ), '#56 AC12 — entry 0019 retains the leading sentinel paragraph.' );
		day_one_importer_wp_env_assert( false !== strpos( $entry_0019_content, 'After placeholders.' ), '#56 AC12 — entry 0019 retains the trailing sentinel paragraph.' );
		$ac12_forbidden = array(
			'dayone-moment://',
			'dayone-moment:/photo/',
			'dayone-moment:/video/',
			'dayone-moment:/audio/',
			'dayone-moment:/pdfAttachment/',
			'dayone-photo://',
			'dayone-video://',
			'dayone-audio://',
			'dayone-pdf://',
		);
		foreach ( $ac12_forbidden as $needle ) {
			day_one_importer_wp_env_assert( false === strpos( $entry_0019_content, $needle ), "#56 AC12 — entry 0019 post_content strips placeholder substring: {$needle}" );
			day_one_importer_wp_env_assert( false === strpos( $entry_0019_title, $needle ), "#56 AC12 — entry 0019 post_title strips placeholder substring: {$needle}" );
		}
	}

	// --- #57 — video embed assertions (entries 0020 + 0021). ---

	$day_one_importer_57_video_attachment_id = 0;
	$entry_0020_post_id                      = isset( $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0020'] ) ? $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0020'] : 0;
	day_one_importer_wp_env_assert( $entry_0020_post_id > 0, '#57 AC6 — fictional entry 0020 (video embed) was imported.' );
	if ( $entry_0020_post_id > 0 && function_exists( 'parse_blocks' ) ) {
		$entry_0020_content = (string) get_post_field( 'post_content', $entry_0020_post_id );
		$entry_0020_blocks  = parse_blocks( $entry_0020_content );
		$entry_0020_videos  = array();
		day_one_importer_wp_env_collect_blocks_by_name( $entry_0020_blocks, 'core/video', $entry_0020_videos );
		day_one_importer_wp_env_assert( 1 === count( $entry_0020_videos ), '#57 AC6 / AC16 — entry 0020 contains exactly one core/video block.' );

		$entry_0020_video_id = isset( $entry_0020_videos[0]['attrs']['id'] ) ? (int) $entry_0020_videos[0]['attrs']['id'] : 0;
		day_one_importer_wp_env_assert( $entry_0020_video_id > 0, '#57 AC6 — entry 0020 video block carries a positive attachment ID.' );
		$day_one_importer_57_video_attachment_id = $entry_0020_video_id;

		// #57 AC16 — attachment parent linkage + private uploads URL + media_kind marker.
		day_one_importer_wp_env_assert( (int) get_post( $entry_0020_video_id )->post_parent === $entry_0020_post_id, '#57 AC16 — entry 0020 video attachment is parented to its post.' );
		day_one_importer_wp_env_assert( 'video' === (string) get_post_meta( $entry_0020_video_id, '_day_one_media_kind', true ), '#57 AC4 — entry 0020 video attachment carries _day_one_media_kind = "video".' );
		// The runtime wp_get_attachment_url filter rewrites Day One media to an
		// admin-ajax endpoint, so we verify the on-disk path lives under the
		// private uploads subdir directly (AC16) and that the filtered URL goes
		// through the authenticated endpoint.
		$entry_0020_video_url  = (string) wp_get_attachment_url( $entry_0020_video_id );
		$entry_0020_video_file = (string) get_attached_file( $entry_0020_video_id );
		day_one_importer_wp_env_assert( '' !== $entry_0020_video_file && false !== strpos( $entry_0020_video_file, Day_One_Importer_Media::PRIVATE_UPLOAD_SUBDIR ), '#57 AC16 — entry 0020 video file lives under the private uploads subdir on disk.' );
		day_one_importer_wp_env_assert( false !== strpos( $entry_0020_video_url, 'action=' . Day_One_Importer_Media::PRIVATE_MEDIA_ACTION ), '#57 AC16 — entry 0020 video URL routes through the authenticated private-media endpoint.' );
		// #57 R1.4 — duration metadata round-trips through floatval() to within 1e-9 of 1.0.
		$entry_0020_duration_meta = (string) get_post_meta( $entry_0020_video_id, '_day_one_video_duration', true );
		day_one_importer_wp_env_assert( '' !== $entry_0020_duration_meta && abs( floatval( $entry_0020_duration_meta ) - 1.0 ) < 1e-9, '#57 R1.4 / AC4 — entry 0020 _day_one_video_duration round-trips within 1e-9 (string storage).' );
	}

	// #57 AC16 — media_imported on the first pass includes the video attachment.
	day_one_importer_wp_env_assert( $media >= 2, '#57 AC16 — first-pass media_imported includes at least the photo and video attachments from the fixture.' );

	// #57 AC7 — the obsolete #56 placeholder warning is gone.
	$first_warnings = is_object( $first ) && method_exists( $first, 'get_warnings' ) ? (array) $first->get_warnings() : array();
	foreach ( $first_warnings as $warning ) {
		day_one_importer_wp_env_assert( false === strpos( (string) $warning, 'video import is not yet supported' ), '#57 AC7 — the #56 placeholder "video import is not yet supported" warning is no longer emitted.' );
	}

	// #57 AC9 / AC16 — entry 0021 emits core/image, core/video, core/image in inline order.
	$entry_0021_post_id = isset( $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0021'] ) ? $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0021'] : 0;
	day_one_importer_wp_env_assert( $entry_0021_post_id > 0, '#57 AC9 — fictional entry 0021 (interleaved photo,video,photo) was imported.' );
	if ( $entry_0021_post_id > 0 && function_exists( 'parse_blocks' ) ) {
		$entry_0021_content   = (string) get_post_field( 'post_content', $entry_0021_post_id );
		$entry_0021_blocks    = parse_blocks( $entry_0021_content );
		$entry_0021_top_names = array();
		foreach ( $entry_0021_blocks as $entry_0021_block ) {
			$entry_0021_block_name = isset( $entry_0021_block['blockName'] ) ? (string) $entry_0021_block['blockName'] : '';
			if ( '' !== $entry_0021_block_name ) {
				$entry_0021_top_names[] = $entry_0021_block_name;
			}
		}
		day_one_importer_wp_env_assert( array( 'core/image', 'core/video', 'core/image' ) === $entry_0021_top_names, '#57 AC9 / AC16 — entry 0021 emits core/image, core/video, core/image in inline order.' );
	}

	// --- #58 — audio embed assertions (entries 0022 + 0023). ---

	$day_one_importer_58_audio_attachment_id = 0;
	$entry_0022_post_id                      = isset( $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0022'] ) ? $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0022'] : 0;
	day_one_importer_wp_env_assert( $entry_0022_post_id > 0, '#58 AC6 — fictional entry 0022 (audio embed) was imported.' );
	if ( $entry_0022_post_id > 0 && function_exists( 'parse_blocks' ) ) {
		$entry_0022_content = (string) get_post_field( 'post_content', $entry_0022_post_id );
		$entry_0022_blocks  = parse_blocks( $entry_0022_content );
		$entry_0022_audios  = array();
		day_one_importer_wp_env_collect_blocks_by_name( $entry_0022_blocks, 'core/audio', $entry_0022_audios );
		day_one_importer_wp_env_assert( 1 === count( $entry_0022_audios ), '#58 AC6 / AC16 — entry 0022 contains exactly one core/audio block.' );

		$entry_0022_audio_id = isset( $entry_0022_audios[0]['attrs']['id'] ) ? (int) $entry_0022_audios[0]['attrs']['id'] : 0;
		day_one_importer_wp_env_assert( $entry_0022_audio_id > 0, '#58 AC6 — entry 0022 audio block carries a positive attachment ID.' );
		$day_one_importer_58_audio_attachment_id = $entry_0022_audio_id;

		// #58 AC16 — attachment parent linkage + private uploads URL + media_kind marker.
		day_one_importer_wp_env_assert( (int) get_post( $entry_0022_audio_id )->post_parent === $entry_0022_post_id, '#58 AC16 — entry 0022 audio attachment is parented to its post.' );
		day_one_importer_wp_env_assert( 'audio' === (string) get_post_meta( $entry_0022_audio_id, '_day_one_media_kind', true ), '#58 AC4 — entry 0022 audio attachment carries _day_one_media_kind = "audio".' );
		$entry_0022_audio_url  = (string) wp_get_attachment_url( $entry_0022_audio_id );
		$entry_0022_audio_file = (string) get_attached_file( $entry_0022_audio_id );
		day_one_importer_wp_env_assert( '' !== $entry_0022_audio_file && false !== strpos( $entry_0022_audio_file, Day_One_Importer_Media::PRIVATE_UPLOAD_SUBDIR ), '#58 AC16 — entry 0022 audio file lives under the private uploads subdir on disk.' );
		day_one_importer_wp_env_assert( false !== strpos( $entry_0022_audio_url, 'action=' . Day_One_Importer_Media::PRIVATE_MEDIA_ACTION ), '#58 AC16 — entry 0022 audio URL routes through the authenticated private-media endpoint.' );

		// #58 R1.4 / AC4 — duration metadata round-trips through floatval() to within 1e-9 of 1.0.
		$entry_0022_duration_meta = (string) get_post_meta( $entry_0022_audio_id, '_day_one_audio_duration', true );
		day_one_importer_wp_env_assert( '' !== $entry_0022_duration_meta && abs( floatval( $entry_0022_duration_meta ) - 1.0 ) < 1e-9, '#58 R1.4 / AC4 — entry 0022 _day_one_audio_duration round-trips within 1e-9 (string storage).' );

		// #58 AC6 / AC16 — figcaption caption matches the fixture title.
		$entry_0022_title_meta = (string) get_post_meta( $entry_0022_audio_id, '_day_one_audio_title', true );
		day_one_importer_wp_env_assert( 'sample-1s' === $entry_0022_title_meta, '#58 R11.5 / AC4 — entry 0022 _day_one_audio_title matches the fixture title.' );
		day_one_importer_wp_env_assert( false !== strpos( $entry_0022_content, '<figcaption class="wp-element-caption">' ), '#58 AC6 / AC16 — entry 0022 post body contains a wp-element-caption figcaption.' );
		day_one_importer_wp_env_assert( false !== strpos( $entry_0022_content, 'sample-1s' ), '#58 AC6 / AC16 — entry 0022 figcaption text contains the fixture title "sample-1s".' );
	}

	// #58 AC16 — media_imported on the first pass includes the audio attachment.
	day_one_importer_wp_env_assert( $media >= 3, '#58 AC16 — first-pass media_imported includes the photo, video, and audio attachments from the fixture.' );

	// #58 AC7 — the obsolete #56 placeholder warning is gone.
	$first_warnings_audio = is_object( $first ) && method_exists( $first, 'get_warnings' ) ? (array) $first->get_warnings() : array();
	foreach ( $first_warnings_audio as $warning ) {
		day_one_importer_wp_env_assert( false === strpos( (string) $warning, 'audio import is not yet supported' ), '#58 AC7 — the #56 placeholder "audio import is not yet supported" warning is no longer emitted.' );
	}

	// #58 AC9 / AC16 — entry 0023 emits core/image, core/video, core/audio in inline order.
	$entry_0023_post_id = isset( $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0023'] ) ? $day_one_importer_uuid_to_post_id['FICTIONAL-SAMPLE-ENTRY-0023'] : 0;
	day_one_importer_wp_env_assert( $entry_0023_post_id > 0, '#58 AC9 — fictional entry 0023 (interleaved photo,video,audio) was imported.' );
	if ( $entry_0023_post_id > 0 && function_exists( 'parse_blocks' ) ) {
		$entry_0023_content   = (string) get_post_field( 'post_content', $entry_0023_post_id );
		$entry_0023_blocks    = parse_blocks( $entry_0023_content );
		$entry_0023_top_names = array();
		foreach ( $entry_0023_blocks as $entry_0023_block ) {
			$entry_0023_block_name = isset( $entry_0023_block['blockName'] ) ? (string) $entry_0023_block['blockName'] : '';
			if ( '' !== $entry_0023_block_name ) {
				$entry_0023_top_names[] = $entry_0023_block_name;
			}
		}
		day_one_importer_wp_env_assert( array( 'core/image', 'core/video', 'core/audio' ) === $entry_0023_top_names, '#58 AC9 / AC16 — entry 0023 emits core/image, core/video, core/audio in inline order.' );
	}

	// Stash for rerun-identity check below.
	$GLOBALS['day_one_importer_57_video_attachment_id'] = $day_one_importer_57_video_attachment_id;
	$GLOBALS['day_one_importer_58_audio_attachment_id'] = $day_one_importer_58_audio_attachment_id;
}

$second_async  = day_one_importer_wp_env_import_from_zip_async( $sample_zip );
$second        = $second_async['results'];
$second_counts = $second->get_counts();
$skipped       = isset( $second_counts['posts_skipped'] ) ? (int) $second_counts['posts_skipped'] : 0;
day_one_importer_wp_env_assert( $skipped === $created, 'Second import skipped existing completed posts.' );

// #57 AC10 / AC16 — rerun identity: the entry-20 video attachment ID is stable
// across the first import and this rerun (no duplicate attachment created).
if ( $using_default_zip && ! empty( $GLOBALS['day_one_importer_57_video_attachment_id'] ) ) {
	$expected_video_id = (int) $GLOBALS['day_one_importer_57_video_attachment_id'];
	$post_id_lookup    = 0;
	foreach ( $imported_posts as $maybe_pid ) {
		if ( 'FICTIONAL-SAMPLE-ENTRY-0020' === (string) get_post_meta( (int) $maybe_pid, '_day_one_uuid', true ) ) {
			$post_id_lookup = (int) $maybe_pid;
			break;
		}
	}
	day_one_importer_wp_env_assert( $post_id_lookup > 0, '#57 AC10 — entry 0020 post is still discoverable after the rerun.' );
	if ( $post_id_lookup > 0 && function_exists( 'parse_blocks' ) ) {
		$rerun_blocks       = parse_blocks( (string) get_post_field( 'post_content', $post_id_lookup ) );
		$rerun_video_blocks = array();
		day_one_importer_wp_env_collect_blocks_by_name( $rerun_blocks, 'core/video', $rerun_video_blocks );
		$rerun_video_id = ! empty( $rerun_video_blocks ) && isset( $rerun_video_blocks[0]['attrs']['id'] ) ? (int) $rerun_video_blocks[0]['attrs']['id'] : 0;
		day_one_importer_wp_env_assert( $rerun_video_id === $expected_video_id, '#57 AC10 / AC16 — entry 0020 video attachment ID is identical across the first import and the rerun (no duplicate attachment).' );
	}
}

// #58 AC10 / AC16 — rerun identity: the entry-22 audio attachment ID is stable
// across the first import and this rerun (no duplicate attachment created).
if ( $using_default_zip && ! empty( $GLOBALS['day_one_importer_58_audio_attachment_id'] ) ) {
	$expected_audio_id     = (int) $GLOBALS['day_one_importer_58_audio_attachment_id'];
	$audio_post_id_lookup  = 0;
	foreach ( $imported_posts as $maybe_pid ) {
		if ( 'FICTIONAL-SAMPLE-ENTRY-0022' === (string) get_post_meta( (int) $maybe_pid, '_day_one_uuid', true ) ) {
			$audio_post_id_lookup = (int) $maybe_pid;
			break;
		}
	}
	day_one_importer_wp_env_assert( $audio_post_id_lookup > 0, '#58 AC10 — entry 0022 post is still discoverable after the rerun.' );
	if ( $audio_post_id_lookup > 0 && function_exists( 'parse_blocks' ) ) {
		$rerun_audio_content = (string) get_post_field( 'post_content', $audio_post_id_lookup );
		$rerun_audio_blocks  = parse_blocks( $rerun_audio_content );
		$rerun_audio_records = array();
		day_one_importer_wp_env_collect_blocks_by_name( $rerun_audio_blocks, 'core/audio', $rerun_audio_records );
		$rerun_audio_id = ! empty( $rerun_audio_records ) && isset( $rerun_audio_records[0]['attrs']['id'] ) ? (int) $rerun_audio_records[0]['attrs']['id'] : 0;
		day_one_importer_wp_env_assert( $rerun_audio_id === $expected_audio_id, '#58 AC10 / AC16 — entry 0022 audio attachment ID is identical across the first import and the rerun (no duplicate attachment).' );
	}
}

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
		'async_batches_first_import'     => $first_async['batches'],
		'async_batches_rerun'            => $second_async['batches'],
		'posts_skipped_rerun'            => $skipped,
		'legacy_posts_reprocessed'       => $resumed_legacy,
		'posts_recreated_after_trash'    => $recreated,
		'posts_skipped_after_trash_test' => $skipped_after_trash,
		'media_imported'                 => $media,
	),
	JSON_PRETTY_PRINT
) . "\n";
