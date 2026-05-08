<?php
/**
 * Pure helper tests for Day One Importer.
 *
 * These tests intentionally avoid WordPress bootstrap and do not inspect private
 * sample journal text.
 */

define( 'DAY_ONE_IMPORTER_TESTING', true );
define( 'DAY_ONE_IMPORTER_TEXT_DOMAIN', 'day-one-importer' );

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $text;
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $filename ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$filename = basename( (string) $filename );
		return preg_replace( '/[^A-Za-z0-9._-]/', '-', $filename );
	}
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/class-day-one-importer-results.php';
require_once __DIR__ . '/../includes/class-day-one-importer-cleanup.php';
require_once __DIR__ . '/../includes/class-day-one-importer-content.php';
require_once __DIR__ . '/../includes/class-day-one-importer-parser.php';
require_once __DIR__ . '/../includes/class-day-one-importer-media.php';

function assert_true( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$content = Day_One_Importer_Content::convert_text_to_content( "# Heading\n\nParagraph with [gallery] and <script>alert(1)</script>.\n- item" );
assert_true( false !== strpos( $content, '<h1>Heading</h1>' ), 'Markdown heading is converted.' );
assert_true( false === strpos( $content, '<script>' ), 'Raw script tags are escaped.' );
assert_true( false === strpos( $content, '[gallery]' ), 'Shortcode brackets are neutralized.' );
assert_true( false !== strpos( $content, '&#91;gallery&#93;' ), 'Shortcode-like text remains visible as entities.' );

$placeholder_content = Day_One_Importer_Content::convert_text_to_content( "![](dayone-moment://C3E4A1AA78264398801ED7B7D984F859)\n\nCaption text" );
assert_true( false === strpos( $placeholder_content, 'dayone-moment://' ), 'Day One media placeholders are omitted from content.' );
assert_true( false !== strpos( $placeholder_content, 'Caption text' ), 'Text after media placeholder is preserved.' );

$escaped_content = Day_One_Importer_Content::convert_text_to_content( 'Un año sin usar Day One\\. Como pasa el tiempo\\!' );
assert_true( false !== strpos( $escaped_content, 'Day One.' ), 'Markdown-escaped periods are normalized in content.' );
assert_true( false !== strpos( $escaped_content, 'tiempo!' ), 'Markdown-escaped exclamation marks are normalized in content.' );

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

echo "All pure helper tests passed.\n";
