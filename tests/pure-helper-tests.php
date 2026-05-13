<?php
/**
 * Pure helper tests for Day One Importer.
 *
 * These tests intentionally avoid WordPress bootstrap and do not inspect private
 * sample journal text.
 */

define( 'DAY_ONE_IMPORTER_TESTING', true );
define( 'DAY_ONE_IMPORTER_TEXT_DOMAIN', 'day-one-importer' );
$day_one_importer_test_webroot = sys_get_temp_dir() . '/day-one-importer-webroot-' . uniqid() . '/public/';
mkdir( $day_one_importer_test_webroot, 0777, true );
define( 'ABSPATH', $day_one_importer_test_webroot );

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $text;
	}
}

$GLOBALS['day_one_importer_test_filters'] = array();
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$filters = isset( $GLOBALS['day_one_importer_test_filters'] ) && is_array( $GLOBALS['day_one_importer_test_filters'] ) ? $GLOBALS['day_one_importer_test_filters'] : array();
		return isset( $filters[ $tag ] ) && is_callable( $filters[ $tag ] ) ? call_user_func( $filters[ $tag ], $value ) : $value;
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $filename ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$filename = basename( (string) $filename );
		return preg_replace( '/[^A-Za-z0-9._-]/', '-', $filename );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return rtrim( (string) $value, '/\\' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return untrailingslashit( $value ) . DIRECTORY_SEPARATOR;
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $path ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return is_dir( $path ) || mkdir( $path, 0777, true );
	}
}

if ( ! function_exists( 'wp_is_writable' ) ) {
	function wp_is_writable( $path ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return is_writable( $path );
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $url . '?' . http_build_query( $args, '', '&' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return htmlspecialchars( (string) $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_get_attachment_image_src' ) ) {
	function wp_get_attachment_image_src( $id, $size, $icon = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$id = (int) $id;
		if ( ! in_array( $id, array( 101, 102, 202 ), true ) ) {
			return false;
		}

		return array( 'https://example.test/' . $id . '.jpg', 1200, 800, false );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $html ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $html;
	}
}

if ( ! function_exists( 'wp_get_attachment_image' ) ) {
	function wp_get_attachment_image( $id, $size, $icon = false, $attr = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$id = (int) $id;
		if ( ! in_array( $id, array( 101, 102, 202 ), true ) ) {
			return '';
		}

		$class = 'attachment-' . $size;
		if ( 202 !== $id && ! empty( $attr['class'] ) ) {
			$class .= ' ' . $attr['class'];
		}

		return '<img width="1200" height="800" loading="lazy" decoding="async" srcset="https://example.test/' . $id . '-2x.jpg 2x" sizes="100vw" class="' . htmlspecialchars( $class, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '" src="https://example.test/' . $id . '.jpg" alt="" />';
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $id ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return 303 === (int) $id ? 'https://example.test/303-fallback.jpg' : false;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return json_encode( $data, $flags );
	}
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/class-day-one-importer-results.php';
require_once __DIR__ . '/../includes/class-day-one-importer-job-state.php';
require_once __DIR__ . '/../includes/class-day-one-importer-cleanup.php';
require_once __DIR__ . '/../includes/class-day-one-importer-job-store.php';
require_once __DIR__ . '/../includes/class-day-one-importer-content.php';
require_once __DIR__ . '/../includes/class-day-one-importer-parser.php';
require_once __DIR__ . '/../includes/class-day-one-importer-media.php';

function assert_true( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function assert_no_runtime_img_attrs( $html, $message ) {
	foreach ( array( 'width=', 'height=', 'loading=', 'decoding=', 'srcset=', 'sizes=' ) as $attr ) {
		assert_true( false === strpos( $html, $attr ), $message . ' omits ' . $attr );
	}
}

$empty_results = new Day_One_Importer_Results();
assert_true( ! $empty_results->has_warnings(), 'A new result has no warnings.' );
assert_true( ! $empty_results->has_errors(), 'A new result has no errors.' );

$warning_results = new Day_One_Importer_Results();
$warning_results->add_warning( 'Privacy-safe warning.' );
assert_true( $warning_results->has_warnings(), 'A result with one warning reports warnings.' );

$suppressed_warning_results = new Day_One_Importer_Results();
for ( $i = 0; $i <= Day_One_Importer_Results::MAX_DETAILS; $i++ ) {
	$suppressed_warning_results->add_warning( 'Privacy-safe warning.' );
}
assert_true( $suppressed_warning_results->has_warnings(), 'Suppressed warnings still report warnings.' );

$error_results = new Day_One_Importer_Results();
$error_results->add_error( 'Privacy-safe error.' );
assert_true( $error_results->has_errors(), 'Existing error behavior is unchanged.' );

assert_true( Day_One_Importer_Job_State::is_terminal_status( 'completed' ), 'Completed job status is terminal.' );
assert_true( Day_One_Importer_Job_State::is_terminal_status( 'canceled' ), 'Canceled job status is terminal.' );
assert_true( ! Day_One_Importer_Job_State::is_terminal_status( 'running' ), 'Running job status is not terminal.' );
assert_true( Day_One_Importer_Job_State::is_retryable_status( 'failed' ), 'Failed job status can be retried.' );
$deadline = Day_One_Importer_Job_State::deadline_from_budget( 5, 100.0 );
assert_true( 105.0 === $deadline, 'Deadline helper adds the requested budget.' );

$status_results = new Day_One_Importer_Results();
$status_results->add_error( 'Failure in /tmp/private/export/Journal.json' );
$status_payload = Day_One_Importer_Job_State::status_response(
	array(
		'id'              => 'job-123',
		'status'          => 'running',
		'phase'           => 'importing',
		'run_dir'         => '/tmp/private/export',
		'extract_dir'     => '/tmp/private/export/extract',
		'entries_total'   => 10,
		'entry_index'     => 3,
		'current_media_index' => 1,
		'current_media_total' => 2,
		'results'         => $status_results->to_array(),
	)
);
assert_true( 'job-123' === $status_payload['job_id'], 'Status payload includes the opaque job ID.' );
assert_true( 3 === $status_payload['progress']['entry_index'], 'Status payload includes safe progress cursors.' );
assert_true( $status_payload['progress_percent'] >= 20 && $status_payload['progress_percent'] <= 55, 'Status payload reports a calibrated overall progress percentage.' );
assert_true( false === strpos( json_encode( $status_payload ), '/tmp/private' ), 'Status payload omits filesystem paths.' );

$completed_status_payload = Day_One_Importer_Job_State::status_response(
	array(
		'status' => 'completed',
		'phase'  => 'done',
	)
);
assert_true( 100 === $completed_status_payload['progress_percent'], 'Completed status reports 100 percent progress.' );

$store = new Day_One_Importer_Job_Store();
$token = $store->acquire_lock( 'lock-test', 'owner-a', 30 );
assert_true( 'owner-a' === $token, 'Job lock can be acquired with an owner token.' );
assert_true( false === $store->acquire_lock( 'lock-test', 'owner-b', 30 ), 'Concurrent job lock acquisition fails.' );
assert_true( ! $store->release_lock( 'lock-test', 'owner-b' ), 'Only the lock owner can release the lock.' );
assert_true( $store->release_lock( 'lock-test', 'owner-a' ), 'The lock owner can release the lock.' );
$stale_token = $store->acquire_lock( 'stale-lock-test', 'stale-owner', 1 );
assert_true( 'stale-owner' === $stale_token, 'Stale lock test lock acquired.' );
sleep( 2 );
assert_true( 'new-owner' === $store->acquire_lock( 'stale-lock-test', 'new-owner', 30 ), 'Expired locks can be recovered.' );
assert_true( $store->release_lock( 'stale-lock-test', 'new-owner' ), 'Recovered lock can be released.' );
$renew_token = $store->acquire_lock( 'renew-lock-test', 'renew-owner', 1 );
assert_true( 'renew-owner' === $renew_token, 'Renew test lock acquired.' );
assert_true( $store->renew_lock( 'renew-lock-test', 'renew-owner', 5 ), 'Lock owner can renew an active lock.' );
sleep( 2 );
assert_true( false === $store->acquire_lock( 'renew-lock-test', 'other-owner', 1 ), 'Renewed lock is not treated as stale after original TTL.' );
assert_true( $store->release_lock( 'renew-lock-test', 'renew-owner' ), 'Renewed lock can be released by owner.' );
$retry_job = $store->create_job( 7, sys_get_temp_dir() . '/day-one-retry-run', sys_get_temp_dir() . '/day-one-retry-run/day-one-export.zip', new Day_One_Importer_Results() );
assert_true( is_array( $retry_job ), 'Retry race test job created.' );
$retry_job['status'] = Day_One_Importer_Job_State::STATUS_RUNNING;
$store->save_job( $retry_job );
assert_true( 'active-worker' === $store->acquire_lock( $retry_job['id'], 'active-worker', 30 ), 'Active worker lock acquired for retry race test.' );
$retried_while_locked = $store->retry_job( $retry_job['id'], 7 );
assert_true( is_array( $retried_while_locked ) && Day_One_Importer_Job_State::STATUS_RUNNING === $retried_while_locked['status'], 'Retry while active lock is held is a no-op and preserves running state.' );
assert_true( $store->release_lock( $retry_job['id'], 'active-worker' ), 'Active worker lock released for retry race test.' );
$store->delete_job( $retry_job['id'] );

$content = Day_One_Importer_Content::convert_text_to_content( "# Heading\n\nParagraph with [gallery] and <script>alert(1)</script>.\n- item" );
assert_true( false !== strpos( $content, '<!-- wp:heading {"level":1} -->' ), 'Markdown heading is serialized as a Heading block.' );
assert_true( false !== strpos( $content, '<h1>Heading</h1>' ), 'Markdown heading markup is converted.' );
assert_true( false !== strpos( $content, '<!-- wp:paragraph -->' ), 'Paragraph content is serialized as a Paragraph block.' );
assert_true( false === strpos( $content, '<script>' ), 'Raw script tags are escaped.' );
assert_true( false !== strpos( $content, '&lt;script&gt;alert(1)&lt;/script&gt;' ), 'Raw script tags remain visible as escaped text.' );
assert_true( false === strpos( $content, '[gallery]' ), 'Shortcode brackets are neutralized.' );
assert_true( false !== strpos( $content, '&#91;gallery&#93;' ), 'Shortcode-like text remains visible as entities.' );

$multiline_content = Day_One_Importer_Content::convert_text_to_content( "Line one\nLine two" );
assert_true( false !== strpos( $multiline_content, "<p>Line one<br />\nLine two</p>" ), 'Multiline prose preserves line breaks inside a Paragraph block.' );

$list_content = Day_One_Importer_Content::convert_text_to_content( "- one\n- two" );
assert_true( 1 === substr_count( $list_content, '<!-- wp:list -->' ), 'Consecutive list items create one List block.' );
assert_true( false !== strpos( $list_content, '<li>one</li>' ) && false !== strpos( $list_content, '<li>two</li>' ), 'List block contains both list items.' );

$placeholder_content = Day_One_Importer_Content::convert_text_to_content( "![](dayone-moment://C3E4A1AA78264398801ED7B7D984F859)\n\nCaption text" );
assert_true( false === strpos( $placeholder_content, 'dayone-moment://' ), 'Day One media placeholders are omitted from content.' );
assert_true( false !== strpos( $placeholder_content, '<!-- wp:paragraph -->' ), 'Text after media placeholder remains in a Paragraph block.' );
assert_true( false !== strpos( $placeholder_content, 'Caption text' ), 'Text after media placeholder is preserved.' );

$escaped_content = Day_One_Importer_Content::convert_text_to_content( 'Un año sin usar Day One\\. Como pasa el tiempo\\!' );
assert_true( false !== strpos( $escaped_content, 'Day One.' ), 'Markdown-escaped periods are normalized in content.' );
assert_true( false !== strpos( $escaped_content, 'tiempo!' ), 'Markdown-escaped exclamation marks are normalized in content.' );

$base_content = '<!-- wp:paragraph -->' . "\n" . '<p>Existing</p>' . "\n" . '<!-- /wp:paragraph -->';
assert_true( $base_content === Day_One_Importer_Content::append_image_section( $base_content, array() ), 'No attachments leave content unchanged.' );
assert_true( $base_content === Day_One_Importer_Content::append_image_section( $base_content, array( 0, 'bad', 404 ) ), 'No renderable attachments leave content unchanged.' );

$single_image_content = Day_One_Importer_Content::append_image_section( '', array( 101 ) );
assert_true( false !== strpos( $single_image_content, '<!-- wp:image {"id":101,"sizeSlug":"large","linkDestination":"none"} -->' ), 'One attachment appends an Image block with deterministic attributes.' );
assert_true( false === strpos( $single_image_content, '<!-- wp:gallery' ), 'One attachment does not append a Gallery block.' );
assert_true( false !== strpos( $single_image_content, '<figure class="wp-block-image size-large"><img src="https://example.test/101.jpg" alt="" class="wp-image-101" /></figure>' ), 'Image block img markup is deterministic.' );
assert_no_runtime_img_attrs( $single_image_content, 'Single image content' );

$normalized_image_content = Day_One_Importer_Content::append_image_section( '', array( 202 ) );
assert_true( false !== strpos( $normalized_image_content, 'class="wp-image-202"' ), 'Image markup uses only the expected wp-image class.' );
assert_true( false === strpos( $normalized_image_content, 'attachment-large' ), 'Image markup omits helper-added attachment size class.' );

$fallback_image_content = Day_One_Importer_Content::append_image_section( '', array( 303 ) );
assert_true( false !== strpos( $fallback_image_content, '<!-- wp:image {"id":303' ), 'Attachment URL fallback appends an Image block.' );
assert_true( false !== strpos( $fallback_image_content, '<img src="https://example.test/303-fallback.jpg" alt="" class="wp-image-303" />' ), 'Fallback image tag is deterministic.' );

$gallery_content = Day_One_Importer_Content::append_image_section( '', array( 101, 0, '101', 404, 102, 101 ) );
assert_true( false !== strpos( $gallery_content, '<!-- wp:gallery {"linkTo":"none","ids":[101,102]} -->' ), 'Duplicate and zero IDs are normalized while preserving order for Gallery block IDs.' );
assert_true( 2 === substr_count( $gallery_content, '<!-- wp:image' ), 'Gallery block contains nested Image blocks only for renderable unique IDs.' );
assert_true( false !== strpos( $gallery_content, 'wp-image-101' ) && false !== strpos( $gallery_content, 'wp-image-102' ), 'Each nested gallery image has its matching wp-image class.' );
assert_true( strpos( $gallery_content, 'wp-image-101' ) < strpos( $gallery_content, 'wp-image-102' ), 'Gallery images preserve attachment order.' );
assert_no_runtime_img_attrs( $gallery_content, 'Gallery content' );
assert_true( false === strpos( $gallery_content, '404' ) && false === strpos( $gallery_content, '/tmp/' ), 'Skipped invalid images do not leak IDs or filesystem paths.' );
assert_true( false === strpos( $gallery_content, 'day-one-importer-photos' ) && false === strpos( $gallery_content, 'Imported photos' ), 'Old imported photo wrapper is not emitted.' );

$title = Day_One_Importer_Content::derive_title( "# A safe title\nBody", '2026-05-08 12:00:00' );
assert_true( 'A safe title' === $title, 'Title is derived from first heading.' );

$placeholder_title = Day_One_Importer_Content::derive_title( "![](dayone-moment://C3E4A1AA78264398801ED7B7D984F859)\nA real title", '2026-05-08 12:00:00' );
assert_true( 'A real title' === $placeholder_title, 'Title skips Day One media placeholders.' );

$escaped_title = Day_One_Importer_Content::derive_title( 'Un año sin usar Day One\\.', '2026-05-08 12:00:00' );
assert_true( 'Un año sin usar Day One.' === $escaped_title, 'Markdown-escaped punctuation is normalized in titles.' );

$long_title = Day_One_Importer_Content::derive_title( 'Mirando Teslas, sacando releases, jugando a la procrastinación y cerrando el día', '2026-05-08 12:00:00' );
assert_true( strlen( $long_title ) <= 63, 'Long titles are shortened for admin readability.' );

$tags = Day_One_Importer_Content::normalize_tags( array( 'Travel', 'travel', '<b>Food</b>', '' ) );
assert_true( count( $tags ) === 2 && in_array( 'travel', array_map( 'strtolower', $tags ), true ), 'Tags are sanitized and deduplicated.' );

$date = Day_One_Importer_Content::parse_day_one_date( '2024-10-29T12:34:30Z' );
assert_true( $date['valid'] && '2024-10-29 12:34:30' === $date['gmt'], 'ISO UTC dates parse to GMT.' );

assert_true( Day_One_Importer_Cleanup::is_safe_relative_archive_name( 'Export/Diario.json' ), 'Safe archive path accepted.' );
assert_true( ! Day_One_Importer_Cleanup::is_safe_relative_archive_name( '../Diario.json' ), 'Traversal archive path rejected.' );
assert_true( ! Day_One_Importer_Cleanup::is_safe_relative_archive_name( '/tmp/Diario.json' ), 'Absolute archive path rejected.' );
assert_true( ! Day_One_Importer_Cleanup::is_safe_relative_archive_name( 'C:Diario.json' ), 'Windows drive-relative archive path rejected.' );
assert_true( ! Day_One_Importer_Cleanup::is_safe_relative_archive_name( 'D:/Diario.json' ), 'Windows drive-absolute archive path rejected.' );

$upload_filename = Day_One_Importer_Media::build_upload_filename(
	'/tmp/abcdef0123456789abcdef0123456789.jpeg',
	array(
		'filename' => 'IMG_1234.HEIC',
		'type'     => 'jpeg',
	)
);
assert_true( 'IMG_1234.jpeg' === $upload_filename, 'Sideload filename keeps original base but matches resolved JPEG extension.' );

$filtered_sizes = Day_One_Importer_Media::filter_import_image_sizes(
	array(
		'thumbnail' => array(
			'width'  => 150,
			'height' => 150,
		),
	)
);
assert_true( array() === $filtered_sizes, 'Importer sideloads disable generated image sub-sizes.' );

$public_upload_root = sys_get_temp_dir() . '/day-one-importer-public-upload-test-' . uniqid();
$upload_dirs        = Day_One_Importer_Media::filter_private_upload_dir(
	array(
		'basedir' => $public_upload_root,
		'baseurl' => 'https://example.test/wp-content/uploads',
		'subdir'  => '/2026/05',
		'path'    => $public_upload_root . '/2026/05',
		'url'     => 'https://example.test/wp-content/uploads/2026/05',
	)
);
$private_media_root = Day_One_Importer_Media::private_media_base_dir();
assert_true( '/2026/05' === $upload_dirs['subdir'], 'Private media upload subdir preserves WordPress date structure.' );
assert_true( 0 === strpos( $upload_dirs['path'], $private_media_root ), 'Private media upload path is outside the public uploads tree.' );
assert_true( false === strpos( $upload_dirs['path'], $public_upload_root ), 'Private media upload path does not use the public uploads root.' );
assert_true( file_exists( $private_media_root . '/.htaccess' ), 'Private media root receives defense-in-depth protection files.' );
$private_test_file = $upload_dirs['path'] . '/private-test.jpg';
file_put_contents( $private_test_file, 'fake' );
assert_true( Day_One_Importer_Media::is_private_upload_path( $private_test_file ), 'Private media path validator accepts files under the private root.' );
Day_One_Importer_Cleanup::remove( dirname( $private_media_root ) );
Day_One_Importer_Cleanup::remove( $public_upload_root );

$private_media_url = Day_One_Importer_Media::private_media_url( 123 );
assert_true( 'https://example.test/wp-admin/admin-ajax.php?action=day_one_importer_media&attachment_id=123' === $private_media_url, 'Private media URL uses the authenticated AJAX endpoint.' );

$tmp = sys_get_temp_dir() . '/day-one-importer-test-' . uniqid();
mkdir( $tmp . '/Export/photos', 0777, true );
file_put_contents( $tmp . '/Export/photos/abcdef0123456789abcdef0123456789.jpeg', 'fake' );
$resolved = Day_One_Importer_Media::resolve_photo_path(
	$tmp,
	array(
		'md5'  => 'abcdef0123456789abcdef0123456789',
		'type' => 'jpg',
	)
);
assert_true( '' !== $resolved && false !== strpos( $resolved, '.jpeg' ), 'Photo resolver handles jpg/jpeg aliases.' );
Day_One_Importer_Cleanup::remove( $tmp );

$parser_dir = sys_get_temp_dir() . '/day-one-importer-parser-test-' . uniqid();
mkdir( $parser_dir, 0777, true );
file_put_contents(
	$parser_dir . '/Journal.json',
	json_encode(
		array(
			'metadata' => array( 'version' => 'test' ),
			'entries'  => array(
				array(
					'uuid'         => 'UUID-1',
					'creationDate' => '2024-10-29T12:34:30Z',
					'text'         => 'Private text placeholder',
				),
			),
		)
	)
);
$parser  = new Day_One_Importer_Parser();
$results = new Day_One_Importer_Results();
$entries = $parser->parse_export( $parser_dir, $results );
assert_true( 1 === count( $entries ) && 'UUID-1' === $entries[0]['uuid'], 'Parser finds and normalizes entries.' );
Day_One_Importer_Cleanup::remove( $parser_dir );

$fixture_dir     = __DIR__ . '/fixtures/day-one-fictional';
$fixture_results = new Day_One_Importer_Results();
$fixture_entries = $parser->parse_export( $fixture_dir, $fixture_results );
assert_true( 3 === count( $fixture_entries ), 'Committed fictional fixture parses three entries.' );
assert_true( 3 === $fixture_results->get_count( 'entries_found' ), 'Committed fictional fixture reports three found entries.' );
assert_true( empty( $fixture_results->get_warnings() ), 'Committed fictional fixture parses without warnings.' );
assert_true( ! empty( $fixture_entries[0]['photos'] ), 'Committed fictional fixture includes photo metadata.' );
$fixture_photo_path = Day_One_Importer_Media::resolve_photo_path( $fixture_dir, $fixture_entries[0]['photos'][0] );
assert_true( '' !== $fixture_photo_path && is_file( $fixture_photo_path ), 'Committed fictional fixture photo resolves on disk.' );

$batch_results = new Day_One_Importer_Results();
$batch_job     = array(
	'manifest_path'        => sys_get_temp_dir() . '/day-one-importer-manifest-' . uniqid() . '/entries.jsonl',
	'zip_json_candidates'  => array( 'Fictional Journal.json' ),
	'zip_photo_dirs'       => array( 'photos' ),
	'json_files'           => array(),
	'json_file_index'      => 0,
	'json_entry_index'     => 0,
	'entries_total'        => 0,
	'seen_uuids'           => array(),
);
$parser->discover_json_files_batch( $fixture_dir, $batch_job, $batch_results, 1.0E+30 );
$checkpoint_count = 0;
$checkpoint       = static function () use ( &$checkpoint_count ) {
	++$checkpoint_count;
};
$batch_index = $parser->index_export_batch( $fixture_dir, $batch_job, $batch_results, 1.0E+30, $checkpoint );
assert_true( ! empty( $batch_index['done'] ) && 3 === $batch_job['entries_total'], 'Batch parser indexes fixture entries into a manifest.' );
assert_true( $checkpoint_count >= 3, 'Batch parser checkpoints after safe manifest units.' );
$manifest_entry = $parser->read_manifest_entry( $batch_job['manifest_path'], 0 );
assert_true( is_array( $manifest_entry ) && 'FICTIONAL-SAMPLE-ENTRY-0001' === $manifest_entry['uuid'], 'Batch parser can read a manifest entry by cursor.' );
Day_One_Importer_Cleanup::remove( dirname( $batch_job['manifest_path'] ) );

$GLOBALS['day_one_importer_test_filters']['day_one_importer_batch_discovery_limit']    = static function () {
	return 1;
};
$GLOBALS['day_one_importer_test_filters']['day_one_importer_batch_index_entry_limit'] = static function () {
	return 1;
};
$bounded_results = new Day_One_Importer_Results();
$bounded_job     = array(
	'manifest_path'       => sys_get_temp_dir() . '/day-one-importer-bounded-manifest-' . uniqid() . '/entries.jsonl',
	'zip_json_candidates' => array( 'Fictional Journal.json' ),
	'zip_photo_dirs'      => array( 'photos' ),
	'json_files'          => array(),
	'entries_total'       => 0,
	'seen_uuids'          => array(),
);
$bounded_batches = 0;
do {
	$bounded_discovery = $parser->discover_json_files_batch( $fixture_dir, $bounded_job, $bounded_results, 1.0E+30 );
	++$bounded_batches;
} while ( empty( $bounded_discovery['done'] ) && $bounded_batches < 100 );
do {
	$bounded_index = $parser->index_export_batch( $fixture_dir, $bounded_job, $bounded_results, 1.0E+30 );
	++$bounded_batches;
} while ( empty( $bounded_index['done'] ) && $bounded_batches < 100 );
assert_true( 3 === $bounded_job['entries_total'] && $bounded_batches > 3, 'Batch parser can complete fixture indexing across multiple bounded requests.' );
Day_One_Importer_Cleanup::remove( dirname( $bounded_job['manifest_path'] ) );
$GLOBALS['day_one_importer_test_filters'] = array();

echo "All pure helper tests passed.\n";
