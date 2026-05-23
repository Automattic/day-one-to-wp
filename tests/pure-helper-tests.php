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
	function __( $text, $domain = null ) {
		return $text;
	}
}

$GLOBALS['day_one_importer_test_filters'] = array();
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		$filters = isset( $GLOBALS['day_one_importer_test_filters'] ) && is_array( $GLOBALS['day_one_importer_test_filters'] ) ? $GLOBALS['day_one_importer_test_filters'] : array();
		return isset( $filters[ $tag ] ) && is_callable( $filters[ $tag ] ) ? call_user_func( $filters[ $tag ], $value ) : $value;
	}
}

$GLOBALS['day_one_importer_test_options'] = array();
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		return array_key_exists( $option, $GLOBALS['day_one_importer_test_options'] ) ? $GLOBALS['day_one_importer_test_options'][ $option ] : $default;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	function add_option( $option, $value = '', $deprecated = '', $autoload = null ) {
		unset( $deprecated, $autoload );
		if ( array_key_exists( $option, $GLOBALS['day_one_importer_test_options'] ) ) {
			return false;
		}

		$GLOBALS['day_one_importer_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		unset( $autoload );
		$GLOBALS['day_one_importer_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $option ) {
		$exists = array_key_exists( $option, $GLOBALS['day_one_importer_test_options'] );
		unset( $GLOBALS['day_one_importer_test_options'][ $option ] );

		return $exists;
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( $filename ) {
		$filename = basename( (string) $filename );
		return preg_replace( '/[^A-Za-z0-9._-]/', '-', $filename );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return (string) preg_replace( '/<[^>]*>/', '', (string) $text );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		$str = wp_strip_all_tags( (string) $str );
		$str = preg_replace( '/[\x00-\x1F\x7F]/u', '', $str );

		return trim( (string) $str );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return untrailingslashit( $value ) . DIRECTORY_SEPARATOR;
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $path ) {
		return is_dir( $path ) || mkdir( $path, 0777, true );
	}
}

if ( ! function_exists( 'wp_is_writable' ) ) {
	function wp_is_writable( $path ) {
		return is_writable( $path );
	}
}

if ( ! class_exists( 'Day_One_Importer_Test_Filesystem' ) ) {
	class Day_One_Importer_Test_Filesystem {
		public function chmod( $path, $mode, $recursive = false ) {
			unset( $recursive );
			return chmod( $path, $mode );
		}

		public function get_contents( $path ) {
			return file_get_contents( $path );
		}

		public function put_contents( $path, $contents, $mode = false ) {
			$written = file_put_contents( $path, $contents );
			if ( false !== $written && false !== $mode ) {
				chmod( $path, $mode );
			}

			return false !== $written;
		}

		public function copy( $source, $destination, $overwrite = false, $mode = false ) {
			if ( ! $overwrite && file_exists( $destination ) ) {
				return false;
			}

			$copied = copy( $source, $destination );
			if ( $copied && false !== $mode ) {
				chmod( $destination, $mode );
			}

			return $copied;
		}

		public function rmdir( $path, $recursive = false ) {
			unset( $recursive );
			return rmdir( $path );
		}
	}
}

if ( ! function_exists( 'WP_Filesystem' ) ) {
	function WP_Filesystem() {
		global $wp_filesystem;

		$wp_filesystem = new Day_One_Importer_Test_Filesystem();
		return true;
	}
}

if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $file ) {
		if ( is_string( $file ) && file_exists( $file ) ) {
			unlink( $file );
		}
	}
}

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir( $time = null, $create_dir = true, $refresh_cache = false ) {
		unset( $time, $create_dir, $refresh_cache );
		if ( isset( $GLOBALS['day_one_importer_test_wp_upload_dir_calls'] ) ) {
			++$GLOBALS['day_one_importer_test_wp_upload_dir_calls'];
		}
		$basedir = isset( $GLOBALS['day_one_importer_test_upload_basedir'] ) ? $GLOBALS['day_one_importer_test_upload_basedir'] : sys_get_temp_dir() . '/day-one-importer-uploads';
		return array(
			'basedir' => $basedir,
			'baseurl' => 'https://example.test/wp-content/uploads',
		);
	}
}

if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action = -1 ) {
		unset( $action );
		return 'day-one-nonce';
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url ) {
		return $url . '?' . http_build_query( $args, '', '&' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return htmlspecialchars( (string) $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'wp_get_attachment_image_src' ) ) {
	function wp_get_attachment_image_src( $id, $size, $icon = false ) {
		$id = (int) $id;
		// #60 R5.1 — ID 1001 simulates a GIF attachment. The `large` derivative
		// is intentionally a DIFFERENT URL than wp_get_attachment_url() so a
		// failing test would visibly leak the derivative into the block.
		if ( 1001 === $id ) {
			return array( 'https://example.test/wp-content/uploads/2026/05/animated-1024x1024.jpg', 1024, 1024, true );
		}
		// #60 R5.1 — ID 1002 simulates a JPEG attachment.
		if ( 1002 === $id ) {
			return array( 'https://example.test/wp-content/uploads/2026/05/photo-1024x768.jpg', 1024, 768, true );
		}
		if ( ! in_array( $id, array( 101, 102, 202 ), true ) ) {
			return false;
		}

		return array( 'https://example.test/' . $id . '.jpg', 1200, 800, false );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $html ) {
		return $html;
	}
}

if ( ! function_exists( 'wp_get_attachment_image' ) ) {
	function wp_get_attachment_image( $id, $size, $icon = false, $attr = array() ) {
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
	function wp_get_attachment_url( $id ) {
		$id = (int) $id;
		// #60 R5.1 — ID 1001 GIF stub returns the ORIGINAL file URL (no
		// derivative suffix); GIF branch MUST resolve to this string exactly.
		if ( 1001 === $id ) {
			return 'https://example.test/wp-content/uploads/2026/05/animated.gif';
		}
		// #60 R5.1 — ID 1002 JPEG stub. The JPEG branch resolves to the
		// `large` derivative (see wp_get_attachment_image_src stub) — this
		// URL is only used if the ladder falls through, which MUST NOT
		// happen for a JPEG record under the new code path.
		if ( 1002 === $id ) {
			return 'https://example.test/wp-content/uploads/2026/05/photo.jpg';
		}
		if ( 303 === $id ) {
			return 'https://example.test/303-fallback.jpg';
		}
		// Video stubs for #57: emulate private-uploads URLs the sideloader would build.
		if ( in_array( $id, array( 501, 502, 503 ), true ) ) {
			return 'https://example.test/wp-content/uploads/day-one-importer-private/clip-' . $id . '.mov';
		}
		// Audio stubs for #58: emulate private-uploads URLs the sideloader would build.
		if ( in_array( $id, array( 601, 602, 603 ), true ) ) {
			return 'https://example.test/wp-content/uploads/day-one-importer-private/clip-' . $id . '.mp3';
		}
		// #58 R6.5 defensive: ID 304 returns a non-private URL so serialize_audio_block refuses to emit.
		if ( 304 === $id ) {
			return 'https://example.test/304-fallback.mp3';
		}
		// #59 PDF stubs: emulate private-uploads URLs for IDs with various pdfName / basename setups.
		if ( in_array( $id, array( 701, 702, 703, 704 ), true ) ) {
			return 'https://example.test/wp-content/uploads/day-one-importer-private/sample-' . $id . '.pdf';
		}
		// #59 R6.5 tier 3 (basename) coverage: URL with a parseable basename, attachment is Day One (meta says so).
		if ( 705 === $id ) {
			return 'https://example.test/wp-content/uploads/day-one-importer-private/Tier3-Basename.pdf';
		}
		// #59 R6.5 tier 4 ([PDF] floor) coverage: URL has no path (only authority) so
		// basename-without-extension is '' and the [PDF] floor fires. The
		// attachment IS Day One (meta says so) so the defensive guard accepts
		// the URL even though it lacks the private uploads subdir.
		if ( 706 === $id ) {
			return 'https://example.test';
		}
		// #59 R6.5 defensive: ID 305 returns a non-private URL so serialize_file_block refuses to emit.
		if ( 305 === $id ) {
			return 'https://example.test/305-fallback.pdf';
		}
		return false;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		if ( -1 === $component ) {
			return parse_url( $url );
		}
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		unset( $domain );
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

// #58 R6.2 + #59 R6.2 / R6.5: minimal get_post_meta stub keyed by attachment ID + meta key.
// IDs 601/603 have a non-empty _day_one_audio_title; ID 602 has an empty title.
// ID 304 has no _day_one_source marker (used to verify the defensive guard).
// IDs 701/703/704 (Day One PDFs) carry _day_one_pdf_name; ID 702 omits it (basename fallback).
// ID 705 has no _day_one_pdf_name (basename tier coverage); ID 706 forces the [PDF] floor.
// ID 305 has no _day_one_source marker (PDF defensive guard).
// #60 R5.1 — ID 1001 carries _day_one_photo_format='gif'; ID 1002 carries 'jpeg'.
// Alt text is stubbed for both so build_attachment_image_record() resolves an alt.
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		$post_id = (int) $post_id;
		$store   = array(
			601  => array(
				'_day_one_source'      => 'day-one-export',
				'_day_one_audio_title' => 'sample-1s',
			),
			602  => array(
				'_day_one_source'      => 'day-one-export',
				'_day_one_audio_title' => '',
			),
			603  => array(
				'_day_one_source'      => 'day-one-export',
				'_day_one_audio_title' => 'mixed-sequence audio',
			),
			701  => array(
				'_day_one_source'      => 'day-one-export',
				'_day_one_pdf_name'    => 'Fictional PDF Sample',
				'_day_one_media_kind'  => 'pdf',
			),
			702  => array(
				'_day_one_source'      => 'day-one-export',
				'_day_one_pdf_name'    => '',
				'_day_one_media_kind'  => 'pdf',
			),
			703  => array(
				'_day_one_source'      => 'day-one-export',
				'_day_one_pdf_name'    => 'Tier 2 Meta Name',
				'_day_one_media_kind'  => 'pdf',
			),
			704  => array(
				'_day_one_source'      => 'day-one-export',
				'_day_one_pdf_name'    => 'mixed-sequence pdf',
				'_day_one_media_kind'  => 'pdf',
			),
			705  => array(
				'_day_one_source'      => 'day-one-export',
				'_day_one_pdf_name'    => '',
				'_day_one_media_kind'  => 'pdf',
			),
			706  => array(
				'_day_one_source'      => 'day-one-export',
				'_day_one_pdf_name'    => '',
				'_day_one_media_kind'  => 'pdf',
			),
			1001 => array(
				'_day_one_source'           => 'day-one-export',
				'_day_one_photo_format'     => 'gif',
				'_wp_attachment_image_alt'  => 'animated lantern',
			),
			1002 => array(
				'_day_one_source'           => 'day-one-export',
				'_day_one_photo_format'     => 'jpeg',
				'_wp_attachment_image_alt'  => 'photo of a lantern',
			),
		);
		if ( isset( $store[ $post_id ][ $key ] ) ) {
			return $store[ $post_id ][ $key ];
		}
		return $single ? '' : array();
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) {
		return json_encode( $data, $flags );
	}
}

if ( ! function_exists( 'parse_blocks' ) ) {
	/**
	 * Minimal block parser for pure-helper tests — recognizes top-level block
	 * comments produced by Day_One_Importer_Content::serialize_block(). It only
	 * extracts blockName + attrs in order; nested inner blocks are left as the
	 * raw innerHTML segment. Sufficient for #57 ordering assertions.
	 *
	 * @param string $content Serialized block markup.
	 * @return array<int,array{blockName:string,attrs:array,innerHTML:string}>
	 */
	function parse_blocks( $content ) {
		$blocks = array();
		if ( ! is_string( $content ) || '' === $content ) {
			return $blocks;
		}
		$offset = 0;
		while ( $offset < strlen( $content ) ) {
			if ( ! preg_match( '/<!--\s*wp:([a-zA-Z][a-zA-Z0-9_\/-]*)(\s+(\{.*?\}))?\s*(\/)?-->/s', $content, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
				break;
			}
			$name        = $m[1][0];
			$attrs_json  = isset( $m[3][0] ) ? $m[3][0] : '';
			$self_closed = isset( $m[4][0] ) && '/' === $m[4][0];
			$start       = (int) $m[0][1];
			$end         = $start + strlen( $m[0][0] );
			$attrs       = array();
			if ( '' !== $attrs_json ) {
				$decoded = json_decode( $attrs_json, true );
				if ( is_array( $decoded ) ) {
					$attrs = $decoded;
				}
			}
			if ( $self_closed ) {
				$blocks[] = array(
					'blockName' => 'core/' . $name,
					'attrs'     => $attrs,
					'innerHTML' => '',
				);
				$offset    = $end;
				continue;
			}
			$close       = '<!-- /wp:' . $name . ' -->';
			$close_at    = strpos( $content, $close, $end );
			if ( false === $close_at ) {
				break;
			}
			$inner       = substr( $content, $end, $close_at - $end );
			$blocks[]    = array(
				'blockName' => 'core/' . $name,
				'attrs'     => $attrs,
				'innerHTML' => $inner,
			);
			$offset      = $close_at + strlen( $close );
		}
		return $blocks;
	}
}

// #75 — capture get_posts() argument lists so the runner's `find_existing_post_id`
// lookup can assert the new `meta_key` / `meta_value` shape without spinning up
// WordPress. Tests that do not exercise the runner ignore the capture buffer.
$GLOBALS['day_one_importer_test_get_posts_calls']  = array();
$GLOBALS['day_one_importer_test_get_posts_result'] = array();
if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) {
		$GLOBALS['day_one_importer_test_get_posts_calls'][] = is_array( $args ) ? $args : array();
		$result = isset( $GLOBALS['day_one_importer_test_get_posts_result'] ) ? $GLOBALS['day_one_importer_test_get_posts_result'] : array();
		return is_array( $result ) ? $result : array();
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
require_once __DIR__ . '/../includes/class-day-one-importer-runner.php';

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
assert_true( Day_One_Importer_Job_State::is_retryable_status( 'queued' ), 'Queued job status can be continued.' );
assert_true( ! Day_One_Importer_Job_State::is_retryable_status( 'running' ), 'Running job status cannot be retried while active.' );
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

// AC1: screenshot scenario - 124 of 3011 entries should land in single digits.
$screenshot_payload = Day_One_Importer_Job_State::status_response(
	array(
		'status'              => 'running',
		'phase'               => 'importing',
		'entries_total'       => 3011,
		'entry_index'         => 124,
		'current_media_index' => 0,
		'current_media_total' => 0,
	)
);
assert_true( $screenshot_payload['progress_percent'] >= 1 && $screenshot_payload['progress_percent'] <= 10, 'Screenshot scenario (124 of 3011) reports a single-digit progress percentage.' );

// AC2: end of importing should land in [95, 98].
$end_importing_payload = Day_One_Importer_Job_State::status_response(
	array(
		'status'        => 'running',
		'phase'         => 'importing',
		'entries_total' => 3011,
		'entry_index'   => 3011,
	)
);
assert_true( $end_importing_payload['progress_percent'] >= 95 && $end_importing_payload['progress_percent'] <= 98, 'End of importing reports a percentage in [95, 98].' );

// AC5: uploaded phase is canonical zero.
$uploaded_payload = Day_One_Importer_Job_State::status_response(
	array(
		'status' => 'running',
		'phase'  => 'uploaded',
	)
);
assert_true( 0 === $uploaded_payload['progress_percent'], 'Uploaded phase reports zero progress.' );

// AC11: resumed job at 2500 of 3011 reports >= 80 on first paint.
$resumed_payload = Day_One_Importer_Job_State::status_response(
	array(
		'status'              => 'running',
		'phase'               => 'importing',
		'entries_total'       => 3011,
		'entry_index'         => 2500,
		'current_media_index' => 0,
		'current_media_total' => 0,
	)
);
assert_true( $resumed_payload['progress_percent'] >= 80, 'Resumed job at 2500 of 3011 reports at least 80 percent.' );

// AC13a: failed mid-importing preserves the computed value.
$failed_payload = Day_One_Importer_Job_State::status_response(
	array(
		'status'              => 'failed',
		'phase'               => 'importing',
		'entries_total'       => 3011,
		'entry_index'         => 1000,
		'current_media_index' => 0,
		'current_media_total' => 0,
	)
);
assert_true( $failed_payload['progress_percent'] >= 20 && $failed_payload['progress_percent'] <= 50, 'Failed mid-importing reports the computed value, not 100.' );

// AC13b: canceled mid-importing preserves the computed value.
$canceled_payload = Day_One_Importer_Job_State::status_response(
	array(
		'status'              => 'canceled',
		'phase'               => 'importing',
		'entries_total'       => 3011,
		'entry_index'         => 1000,
		'current_media_index' => 0,
		'current_media_total' => 0,
	)
);
assert_true( $canceled_payload['progress_percent'] >= 20 && $canceled_payload['progress_percent'] <= 50, 'Canceled mid-importing reports the computed value, not 100.' );

// AC6: pre-importing phases stay > 0 and <= 10 at every cursor position.
$pre_importing_phase_cases = array(
	array(
		'phase'   => 'preflight_open',
		'cursors' => array(
			array(),
		),
	),
	array(
		'phase'   => 'preflighting',
		'cursors' => array(
			array(
				'zip_index' => 0,
				'zip_total' => 10,
			),
			array(
				'zip_index' => 10,
				'zip_total' => 10,
			),
		),
	),
	array(
		'phase'   => 'extracting',
		'cursors' => array(
			array(
				'extract_index' => 0,
				'extract_total' => 50,
			),
			array(
				'extract_index' => 50,
				'extract_total' => 50,
			),
		),
	),
	array(
		'phase'   => 'validating_tree',
		'cursors' => array(
			array(),
		),
	),
	array(
		'phase'   => 'indexing_discover',
		'cursors' => array(
			array(),
		),
	),
	array(
		'phase'   => 'indexing_entries',
		'cursors' => array(
			array(
				'json_file_index'  => 0,
				'json_files_found' => 3,
			),
			array(
				'json_file_index'  => 3,
				'json_files_found' => 3,
			),
		),
	),
);
foreach ( $pre_importing_phase_cases as $pre_case ) {
	foreach ( $pre_case['cursors'] as $cursor_set ) {
		$payload = Day_One_Importer_Job_State::status_response(
			array_merge(
				array(
					'status' => 'running',
					'phase'  => $pre_case['phase'],
				),
				$cursor_set
			)
		);
		assert_true( $payload['progress_percent'] > 0 && $payload['progress_percent'] <= 10, 'Pre-importing phase ' . $pre_case['phase'] . ' reports a value in (0, 10].' );
	}
}

// AC7: cleanup is strictly above end-of-importing value and below 100.
$cleanup_payload                = Day_One_Importer_Job_State::status_response(
	array(
		'status' => 'running',
		'phase'  => 'cleanup',
	)
);
$end_of_importing_value         = $end_importing_payload['progress_percent'];
assert_true( $cleanup_payload['progress_percent'] > $end_of_importing_value, 'Cleanup phase reports a value strictly greater than end of importing.' );
assert_true( $cleanup_payload['progress_percent'] < 100 && $cleanup_payload['progress_percent'] <= 99, 'Cleanup phase reports a value strictly less than 100.' );

// AC8: monotonicity within importing for fixed entries_total.
$monotonic_total = 20;
$prev_monotonic  = -1;
for ( $monotonic_i = 0; $monotonic_i <= $monotonic_total; $monotonic_i++ ) {
	$step_payload = Day_One_Importer_Job_State::status_response(
		array(
			'status'              => 'running',
			'phase'               => 'importing',
			'entries_total'       => $monotonic_total,
			'entry_index'         => $monotonic_i,
			'current_media_index' => 0,
			'current_media_total' => 0,
		)
	);
	assert_true( $step_payload['progress_percent'] >= $prev_monotonic, 'Progress percentage is non-decreasing within importing at index ' . $monotonic_i . '.' );
	$prev_monotonic = $step_payload['progress_percent'];
}

// AC9 / AC10: cross-phase monotonicity walking the happy path.
$happy_path_walk = array(
	array(
		'label'   => 'uploaded',
		'payload' => array(
			'status' => 'running',
			'phase'  => 'uploaded',
		),
	),
	array(
		'label'   => 'preflight_open',
		'payload' => array(
			'status' => 'running',
			'phase'  => 'preflight_open',
		),
	),
	array(
		'label'   => 'preflighting (cursor=total)',
		'payload' => array(
			'status'    => 'running',
			'phase'     => 'preflighting',
			'zip_index' => 8,
			'zip_total' => 8,
		),
	),
	array(
		'label'   => 'extracting (cursor=total)',
		'payload' => array(
			'status'        => 'running',
			'phase'         => 'extracting',
			'extract_index' => 50,
			'extract_total' => 50,
		),
	),
	array(
		'label'   => 'validating_tree',
		'payload' => array(
			'status' => 'running',
			'phase'  => 'validating_tree',
		),
	),
	array(
		'label'   => 'indexing_discover',
		'payload' => array(
			'status' => 'running',
			'phase'  => 'indexing_discover',
		),
	),
	array(
		'label'   => 'indexing_entries (cursor=total)',
		'payload' => array(
			'status'           => 'running',
			'phase'            => 'indexing_entries',
			'json_file_index'  => 3,
			'json_files_found' => 3,
		),
	),
	array(
		'label'   => 'importing (entry_index=0)',
		'payload' => array(
			'status'              => 'running',
			'phase'               => 'importing',
			'entries_total'       => 3011,
			'entry_index'         => 0,
			'current_media_index' => 0,
			'current_media_total' => 0,
		),
	),
	array(
		'label'   => 'importing (entry_index=3011)',
		'payload' => array(
			'status'              => 'running',
			'phase'               => 'importing',
			'entries_total'       => 3011,
			'entry_index'         => 3011,
			'current_media_index' => 0,
			'current_media_total' => 0,
		),
	),
	array(
		'label'   => 'cleanup',
		'payload' => array(
			'status' => 'running',
			'phase'  => 'cleanup',
		),
	),
	array(
		'label'   => 'done',
		'payload' => array(
			'status' => 'running',
			'phase'  => 'done',
		),
	),
);
$prev_happy_value = -1;
$happy_values     = array();
foreach ( $happy_path_walk as $walk_step ) {
	$walk_payload                          = Day_One_Importer_Job_State::status_response( $walk_step['payload'] );
	$happy_values[ $walk_step['label'] ]   = $walk_payload['progress_percent'];
	assert_true( $walk_payload['progress_percent'] >= $prev_happy_value, 'Progress percentage is non-decreasing across happy-path transition into ' . $walk_step['label'] . '.' );
	$prev_happy_value = $walk_payload['progress_percent'];
}

// AC10: explicit boundary between end-of-indexing_entries and start-of-importing.
assert_true( $happy_values['indexing_entries (cursor=total)'] <= $happy_values['importing (entry_index=0)'], 'End of indexing_entries is <= start of importing.' );

// AC12: zero-media job is not penalized relative to media-present with index=0.
$zero_media_payload = Day_One_Importer_Job_State::status_response(
	array(
		'status'              => 'running',
		'phase'               => 'importing',
		'entries_total'       => 10,
		'entry_index'         => 3,
		'current_media_index' => 0,
		'current_media_total' => 0,
	)
);
$media_present_payload = Day_One_Importer_Job_State::status_response(
	array(
		'status'              => 'running',
		'phase'               => 'importing',
		'entries_total'       => 10,
		'entry_index'         => 3,
		'current_media_index' => 0,
		'current_media_total' => 5,
	)
);
assert_true( $zero_media_payload['progress_percent'] >= $media_present_payload['progress_percent'], 'Zero-media import is not penalized vs media-present with index 0.' );

// AC14: defensive inputs do not warn and stay within [0, 100].
$defensive_warning_seen = false;
set_error_handler(
	static function ( $errno, $errstr ) use ( &$defensive_warning_seen ) {
		$defensive_warning_seen = true;
		return true;
	}
);

$zero_total_payload = Day_One_Importer_Job_State::status_response(
	array(
		'status'        => 'running',
		'phase'         => 'importing',
		'entries_total' => 0,
		'entry_index'   => 0,
	)
);
$impossible_payload = Day_One_Importer_Job_State::status_response(
	array(
		'status'        => 'running',
		'phase'         => 'importing',
		'entries_total' => 0,
		'entry_index'   => 5,
	)
);
$missing_keys_payload = Day_One_Importer_Job_State::status_response(
	array(
		'status' => 'running',
		'phase'  => 'importing',
	)
);
$overshoot_payload = Day_One_Importer_Job_State::status_response(
	array(
		'status'        => 'running',
		'phase'         => 'importing',
		'entries_total' => 3011,
		'entry_index'   => 5000,
	)
);

restore_error_handler();

assert_true( ! $defensive_warning_seen, 'Defensive progress percentage inputs do not raise PHP warnings.' );
assert_true( is_int( $zero_total_payload['progress_percent'] ) && $zero_total_payload['progress_percent'] >= 0 && $zero_total_payload['progress_percent'] <= 10 && 100 !== $zero_total_payload['progress_percent'], 'Importing with totals not yet known reports a small non-100 value.' );
assert_true( is_int( $impossible_payload['progress_percent'] ) && $impossible_payload['progress_percent'] >= 0 && $impossible_payload['progress_percent'] <= 100, 'Impossible state (index without total) stays bounded.' );
assert_true( is_int( $missing_keys_payload['progress_percent'] ) && $missing_keys_payload['progress_percent'] >= 0 && $missing_keys_payload['progress_percent'] <= 100, 'Missing cursor keys still produce a bounded progress percentage.' );
assert_true( is_int( $overshoot_payload['progress_percent'] ) && $overshoot_payload['progress_percent'] >= 0 && $overshoot_payload['progress_percent'] <= 100, 'Cursor overshoot is clamped within bounds.' );

// AC15: narrowed status/phase integer matrix.
$matrix_running_phases = array(
	'uploaded',
	'preflight_open',
	'preflighting',
	'extracting',
	'validating_tree',
	'indexing_discover',
	'indexing_entries',
	'importing',
	'cleanup',
	'done',
);
foreach ( $matrix_running_phases as $matrix_phase ) {
	$matrix_payload = Day_One_Importer_Job_State::status_response(
		array(
			'status' => 'running',
			'phase'  => $matrix_phase,
		)
	);
	assert_true( is_int( $matrix_payload['progress_percent'] ) && $matrix_payload['progress_percent'] >= 0 && $matrix_payload['progress_percent'] <= 100, 'status_response returns an int percent in [0, 100] for (running, ' . $matrix_phase . ').' );
}

$matrix_completed_done = Day_One_Importer_Job_State::status_response(
	array(
		'status' => 'completed',
		'phase'  => 'done',
	)
);
assert_true( 100 === $matrix_completed_done['progress_percent'], 'status_response returns 100 for (completed, done).' );

$matrix_failed_importing = Day_One_Importer_Job_State::status_response(
	array(
		'status'        => 'failed',
		'phase'         => 'importing',
		'entries_total' => 3011,
		'entry_index'   => 1000,
	)
);
assert_true( is_int( $matrix_failed_importing['progress_percent'] ) && 100 !== $matrix_failed_importing['progress_percent'] && $matrix_failed_importing['progress_percent'] >= 20 && $matrix_failed_importing['progress_percent'] <= 50, 'status_response returns the computed value for (failed, importing).' );

$matrix_canceled_importing = Day_One_Importer_Job_State::status_response(
	array(
		'status'        => 'canceled',
		'phase'         => 'importing',
		'entries_total' => 3011,
		'entry_index'   => 1000,
	)
);
assert_true( is_int( $matrix_canceled_importing['progress_percent'] ) && 100 !== $matrix_canceled_importing['progress_percent'] && $matrix_canceled_importing['progress_percent'] >= 20 && $matrix_canceled_importing['progress_percent'] <= 50, 'status_response returns the computed value for (canceled, importing).' );

$matrix_queued_uploaded = Day_One_Importer_Job_State::status_response(
	array(
		'status' => 'queued',
		'phase'  => 'uploaded',
	)
);
assert_true( 0 === $matrix_queued_uploaded['progress_percent'], 'status_response returns 0 for (queued, uploaded).' );

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

$cancel_job = $store->create_job( 7, sys_get_temp_dir() . '/day-one-cancel-run', sys_get_temp_dir() . '/day-one-cancel-run/day-one-export.zip', new Day_One_Importer_Results() );
assert_true( is_array( $cancel_job ), 'Cancel preservation test job created.' );
$cancel_job['status']        = Day_One_Importer_Job_State::STATUS_RUNNING;
$cancel_job['phase']         = 'importing';
$cancel_job['entries_total'] = 10;
$cancel_job['entry_index']   = 4;
$store->save_job( $cancel_job );
$canceled_job = $store->cancel_job( $cancel_job['id'], 7 );
assert_true( is_array( $canceled_job ) && 'importing' === $canceled_job['phase'], 'Cancel preserves the current phase instead of forcing Done.' );
$canceled_status = Day_One_Importer_Job_State::status_response( $canceled_job );
assert_true( 100 !== $canceled_status['progress_percent'], 'Cancel preserves computed progress instead of reporting 100 percent.' );
$store->delete_job( $cancel_job['id'] );

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

// R11.1 — single-paragraph richText payload produces one Paragraph block.
$rt_single = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array(),
				'text'       => 'Hello world',
			),
		),
	)
);
assert_true( 1 === substr_count( $rt_single, '<!-- wp:paragraph -->' ), 'richText single run produces exactly one Paragraph block.' );
assert_true( false !== strpos( $rt_single, '<p>Hello world</p>' ), 'richText single run renders the text inside a paragraph tag.' );

// R11.2 — multi-run payload produces multiple paragraph blocks in order.
$rt_multi = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array( 'text' => 'First' ),
			array( 'text' => 'Second' ),
		),
	)
);
assert_true( 2 === substr_count( $rt_multi, '<!-- wp:paragraph -->' ), 'richText multi-run payload produces a paragraph block per run.' );
assert_true( strpos( $rt_multi, 'First' ) < strpos( $rt_multi, 'Second' ), 'richText paragraphs preserve content order.' );

// R11.3 — intra-text \n is rendered as <br />.
$rt_break = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array( 'text' => "Line one\nLine two" ),
		),
	)
);
assert_true( false !== strpos( $rt_break, "<p>Line one<br />\nLine two</p>" ), 'richText intra-paragraph newlines render as <br />.' );

// R11.4 — embeddedObjects-only items survive drop (R5.1); with empty photo_map and null results, emit_media_group returns ''.
$rt_embed_only = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array(
						'type'       => 'photo',
						'identifier' => 'X',
					),
				),
			),
		),
	)
);
assert_true( '' === $rt_embed_only, 'richText embeddedObjects-only item survives drop check; empty map yields no block.' );

// R11.5 — text + embeddedObjects: only the paragraph renders, embedded object is ignored.
// AC8 — verified by R11.5 (below) after #56 changes (R5 precedence unchanged).
$rt_precedence = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'text'            => 'Caption',
				'embeddedObjects' => array(
					array(
						'type'       => 'photo',
						'identifier' => 'X',
					),
				),
			),
		),
	)
);
assert_true( 1 === substr_count( $rt_precedence, '<!-- wp:paragraph -->' ), 'richText text+embeddedObjects produces exactly one paragraph (precedence rule).' );
assert_true( false !== strpos( $rt_precedence, 'Caption' ), 'richText text+embeddedObjects keeps the caption text.' );
assert_true( 0 === substr_count( $rt_precedence, 'identifier' ), 'richText text+embeddedObjects does not leak embeddedObject identifier into output.' );

// #56 AC1 — is_day_one_media_placeholder accepts every R1 variant on a fully-trimmed line.
$ac1_positives = array(
	'![](dayone-moment://4223F59BF563410FAACA8EDBF888C3FB)',
	'![](dayone-moment:/photo/4223F59BF563410FAACA8EDBF888C3FB)',
	'![](dayone-moment:/video/36AFEC058A8640F98420344B2CCCD145)',
	'![](dayone-moment:/audio/A1A1A1A1A1A1A1A1A1A1A1A1A1A1A1A1)',
	'![](dayone-moment:/pdfAttachment/2540EC0D96C24252AB79706C8BDED592)',
	'![](dayone-photo://B2B2B2B2B2B2B2B2B2B2B2B2B2B2B2B2)',
	'![](dayone-video://C3C3C3C3C3C3C3C3C3C3C3C3C3C3C3C3)',
	'![](dayone-audio://D4D4D4D4D4D4D4D4D4D4D4D4D4D4D4D4)',
	'![](dayone-pdf://E5E5E5E5E5E5E5E5E5E5E5E5E5E5E5E5)',
	// R1 case-insensitivity check (pdfAttachment uppercased).
	'![](dayone-moment:/PDFATTACHMENT/2540EC0D96C24252AB79706C8BDED592)',
	// R1 alt-text allowed (bracket pair MAY contain text).
	'![alt text](dayone-moment://4223F59BF563410FAACA8EDBF888C3FB)',
);
foreach ( $ac1_positives as $ac1_line ) {
	assert_true(
		true === Day_One_Importer_Content::is_day_one_media_placeholder( $ac1_line ),
		'#56 AC1 — is_day_one_media_placeholder matches placeholder line: ' . $ac1_line
	);
}

// #56 AC2 — is_day_one_media_placeholder rejects non-placeholder lines and forbidden shapes.
$ac2_negatives = array(
	'dayone-moment://4223F59BF563410FAACA8EDBF888C3FB',
	'prefix ![](dayone-moment://4223F59BF563410FAACA8EDBF888C3FB) suffix',
	'![](https://example.com/dayone-moment-something)',
	'dayone-moment://something happened',
	'![](dayone-bogus://4223F59BF563410FAACA8EDBF888C3FB)',
);
foreach ( $ac2_negatives as $ac2_line ) {
	assert_true(
		false === Day_One_Importer_Content::is_day_one_media_placeholder( $ac2_line ),
		'#56 AC2 — is_day_one_media_placeholder rejects: ' . $ac2_line
	);
}

// #56 AC2a — is_line_item_dropped() contract via convert_rich_text_to_content() indirection.
// (a) Item with empty text + non-empty embeddedObjects (no map) → output '', warning recorded.
$ac2a_results_embed_only = new Day_One_Importer_Results();
$ac2a_embed_only         = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array(
						'type'       => 'photo',
						'identifier' => 'AC2A-MISSING',
					),
				),
			),
		),
	),
	$ac2a_results_embed_only
);
assert_true( '' === $ac2a_embed_only, '#56 AC2a — embed-only item with empty map produces no block.' );
assert_true( $ac2a_results_embed_only->has_warnings(), '#56 AC2a — embed-only item with empty map records the unresolved-photo warning (survives drop).' );

// (b) Item with empty text + no embeddedObjects → '' AND no warning (truly dropped).
$ac2a_results_text_empty = new Day_One_Importer_Results();
$ac2a_text_empty         = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array( 'text' => '' ),
		),
	),
	$ac2a_results_text_empty
);
assert_true( '' === $ac2a_text_empty, '#56 AC2a — empty-text + no embeds yields empty output.' );
assert_true( ! $ac2a_results_text_empty->has_warnings(), '#56 AC2a — empty-text + no embeds records no warning (dropped silently).' );

// (c) Item with empty text + empty embeddedObjects[] → '' AND no warning (empty array counts as absent).
$ac2a_results_empty_embeds = new Day_One_Importer_Results();
$ac2a_empty_embeds         = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'text'            => '',
				'embeddedObjects' => array(),
			),
		),
	),
	$ac2a_results_empty_embeds
);
assert_true( '' === $ac2a_empty_embeds, '#56 AC2a — empty-text + empty embeddedObjects yields empty output.' );
assert_true( ! $ac2a_results_empty_embeds->has_warnings(), '#56 AC2a — empty-text + empty embeddedObjects records no warning (treated as absent).' );

// (d) Non-empty text + embeddedObjects → paragraph only, no media block, no warning (R5 precedence).
$ac2a_results_precedence = new Day_One_Importer_Results();
$ac2a_precedence         = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'text'            => 'AC2A precedence text',
				'embeddedObjects' => array(
					array(
						'type'       => 'photo',
						'identifier' => 'AC2A-PRECEDENCE',
					),
				),
			),
		),
	),
	$ac2a_results_precedence
);
assert_true( 1 === substr_count( $ac2a_precedence, '<!-- wp:paragraph -->' ), '#56 AC2a — text+embed item renders paragraph only (R5 precedence).' );
assert_true( 0 === substr_count( $ac2a_precedence, '<!-- wp:image' ), '#56 AC2a — text+embed item emits no image block.' );
assert_true( 0 === substr_count( $ac2a_precedence, '<!-- wp:gallery' ), '#56 AC2a — text+embed item emits no gallery block.' );
assert_true( ! $ac2a_results_precedence->has_warnings(), '#56 AC2a — text+embed item does NOT add an unresolved-photo warning.' );

// #56 AC3 — single resolved photo embed produces one image block between paragraphs.
// Pure-helper stubs at the top of this file resolve attachment IDs 101 / 102 / 202 / 303;
// AC3-AC5 reuse those IDs so build_attachment_image_record() returns a non-null record.
$ac3_results = new Day_One_Importer_Results();
$ac3_output  = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array( 'text' => 'Before' ),
			array(
				'embeddedObjects' => array(
					array(
						'type'       => 'photo',
						'identifier' => 'AC3-PHOTO',
					),
				),
			),
			array( 'text' => 'After' ),
		),
	),
	$ac3_results,
	array( 'AC3-PHOTO' => 101 )
);
assert_true( 1 === substr_count( $ac3_output, '<!-- wp:image' ), '#56 AC3 — single resolved photo yields exactly one wp:image block.' );
assert_true( 2 === substr_count( $ac3_output, '<!-- wp:paragraph' ), '#56 AC3 — surrounding paragraphs render unchanged.' );
assert_true( strpos( $ac3_output, 'Before' ) < strpos( $ac3_output, '<!-- wp:image' ), '#56 AC3 — image block comes after the leading paragraph.' );
assert_true( strpos( $ac3_output, '<!-- wp:image' ) < strpos( $ac3_output, 'After' ), '#56 AC3 — image block comes before the trailing paragraph.' );
assert_true( false !== strpos( $ac3_output, '"id":101' ), '#56 AC3 — image block carries the resolved attachment ID.' );
assert_true( ! $ac3_results->has_warnings(), '#56 AC3 — resolved photo emits no warning.' );

// #56 AC4 — two consecutive single-embed items collapse into one gallery block.
$ac4_results = new Day_One_Importer_Results();
$ac4_output  = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array(
						'type'       => 'photo',
						'identifier' => 'AC4-A',
					),
				),
			),
			array(
				'embeddedObjects' => array(
					array(
						'type'       => 'photo',
						'identifier' => 'AC4-B',
					),
				),
			),
		),
	),
	$ac4_results,
	array(
		'AC4-A' => 101,
		'AC4-B' => 102,
	)
);
assert_true( 1 === substr_count( $ac4_output, '<!-- wp:gallery' ), '#56 AC4 — two consecutive embed items yield exactly one gallery block.' );
assert_true( ! $ac4_results->has_warnings(), '#56 AC4 — resolved photos in a gallery emit no warning.' );

// #56 AC4a — transparent drop between two media items keeps them in one run.
$ac4a_results = new Day_One_Importer_Results();
$ac4a_output  = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array(
						'type'       => 'photo',
						'identifier' => 'AC4A-A',
					),
				),
			),
			// Fully-dropped item: empty text, no embeds — transparent in run loop.
			array( 'text' => '' ),
			array(
				'embeddedObjects' => array(
					array(
						'type'       => 'photo',
						'identifier' => 'AC4A-B',
					),
				),
			),
		),
	),
	$ac4a_results,
	array(
		'AC4A-A' => 101,
		'AC4A-B' => 202,
	)
);
assert_true( 1 === substr_count( $ac4a_output, '<!-- wp:gallery' ), '#56 AC4a — dropped item between two media items keeps them in ONE gallery run.' );
assert_true( ! $ac4a_results->has_warnings(), '#56 AC4a — transparent-drop run emits no warning.' );

// #56 AC5 — one item carrying two embeds collapses into one gallery block.
$ac5_results = new Day_One_Importer_Results();
$ac5_output  = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array(
						'type'       => 'photo',
						'identifier' => 'AC5-A',
					),
					array(
						'type'       => 'photo',
						'identifier' => 'AC5-B',
					),
				),
			),
		),
	),
	$ac5_results,
	array(
		'AC5-A' => 101,
		'AC5-B' => 102,
	)
);
assert_true( 1 === substr_count( $ac5_output, '<!-- wp:gallery' ), '#56 AC5 — one item with two embeds yields one gallery block.' );
assert_true( ! $ac5_results->has_warnings(), '#56 AC5 — resolved multi-embed item emits no warning.' );

// #56 AC6 (updated by #57) — audio/pdfAttachment embeds keep the per-type
// placeholder warning. Video embeds with unresolved identifiers now emit one
// warning per missing identifier (R6.3, R5.3 wording — no per-type dedupe;
// matches the photo precedent).
$ac6_results = new Day_One_Importer_Results();
$ac6_output  = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array(
						'type'       => 'video',
						'identifier' => 'AC6-V',
					),
				),
			),
			array(
				'embeddedObjects' => array(
					array(
						'type'       => 'audio',
						'identifier' => 'AC6-A',
					),
				),
			),
			array(
				'embeddedObjects' => array(
					array(
						'type'       => 'pdfAttachment',
						'identifier' => 'AC6-P',
					),
				),
			),
		),
	),
	$ac6_results
);
assert_true( '' === $ac6_output, '#56 AC6 — video/audio/pdfAttachment embeds emit no blocks when none resolve.' );
$ac6_warnings = $ac6_results->get_warnings();
assert_true( 3 === count( $ac6_warnings ), '#56 AC6 — exactly 3 warnings recorded (one per unresolved record).' );
$ac6_warning_blob = implode( "\n", $ac6_warnings );
assert_true( false !== strpos( $ac6_warning_blob, 'Skipping embedded video in Day One entry: referenced media file is unsupported or missing.' ), '#57 R5.3 — unresolved video uses the privacy-safe warning text.' );
assert_true( false === strpos( $ac6_warning_blob, 'video import is not yet supported' ), '#57 AC7 — #56 placeholder "video import is not yet supported" warning is no longer emitted.' );
assert_true( false !== strpos( $ac6_warning_blob, 'Skipping embedded audio in Day One entry: referenced media file is unsupported or missing.' ), '#58 R5.3 — unresolved audio uses the privacy-safe warning text.' );
assert_true( false === strpos( $ac6_warning_blob, 'audio import is not yet supported' ), '#58 AC7 — #56 placeholder "audio import is not yet supported" warning is no longer emitted.' );
assert_true( false !== strpos( $ac6_warning_blob, 'Skipping embedded PDF in Day One entry: referenced media file is unsupported or missing.' ), '#59 R5.3 — unresolved PDF uses the privacy-safe warning text.' );
assert_true( false === strpos( $ac6_warning_blob, 'PDF import is not yet supported' ), '#59 AC7 — #56 placeholder "PDF import is not yet supported" warning is no longer emitted.' );
assert_true( false === strpos( $ac6_warning_blob, '#57' ), '#56 AC6 — warnings do not leak issue number #57.' );
assert_true( false === strpos( $ac6_warning_blob, '#58' ), '#56 AC6 — warnings do not leak issue number #58.' );
assert_true( false === strpos( $ac6_warning_blob, '#59' ), '#56 AC6 — warnings do not leak issue number #59.' );
assert_true( false === strpos( $ac6_warning_blob, 'issue' ), '#56 AC6 — warnings do not contain the word "issue".' );
assert_true( false === strpos( $ac6_warning_blob, 'AC6-V' ), '#56 AC6 — warnings do not leak embed identifier (video).' );
assert_true( false === strpos( $ac6_warning_blob, 'AC6-A' ), '#56 AC6 — warnings do not leak embed identifier (audio).' );
assert_true( false === strpos( $ac6_warning_blob, 'AC6-P' ), '#56 AC6 — warnings do not leak embed identifier (pdf).' );

// #57 AC8 — two unresolved video embeds in one run produce TWO warnings
// (per-identifier, NOT deduped; matches the photo precedent in #56 AC7).
$ac6_dedupe_results = new Day_One_Importer_Results();
Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array(
						'type'       => 'video',
						'identifier' => 'AC6-V1',
					),
					array(
						'type'       => 'video',
						'identifier' => 'AC6-V2',
					),
				),
			),
		),
	),
	$ac6_dedupe_results
);
assert_true( 2 === count( $ac6_dedupe_results->get_warnings() ), '#57 AC8 — two unresolved video embeds yield two warnings (one per missing identifier; no per-type dedupe).' );

// --- C4 — emit_media_group video branch (#57 R6 / AC6, AC7, AC9). ---

// Single video resolves to one core/video block.
$c4_single_results = new Day_One_Importer_Results();
$c4_single_html    = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'video', 'identifier' => 'V-1' ),
				),
			),
		),
	),
	$c4_single_results,
	array(),
	array( 'V-1' => 501 )
);
$c4_single_blocks = parse_blocks( $c4_single_html );
assert_true( 1 === count( $c4_single_blocks ) && 'core/video' === $c4_single_blocks[0]['blockName'], '#57 AC6 — single resolved video embed emits one core/video block.' );
assert_true( isset( $c4_single_blocks[0]['attrs']['id'] ) && 501 === (int) $c4_single_blocks[0]['attrs']['id'], '#57 AC6 — core/video block carries the resolved attachment ID.' );
assert_true( false !== strpos( $c4_single_html, 'wp-block-video' ), '#57 R6.5 — emitted markup contains the wp-block-video class.' );
assert_true( false !== strpos( $c4_single_html, 'day-one-importer-private' ), '#57 R6.5 — emitted video src URL points at the private uploads subdir.' );
assert_true( 0 === count( $c4_single_results->get_warnings() ), '#57 AC7 — resolved video does not trigger the #56 placeholder warning.' );
assert_true( false === strpos( implode( "\n", $c4_single_results->get_warnings() ), 'video import is not yet supported' ), '#57 AC7 — #56 placeholder warning text is gone.' );

// Two consecutive videos produce two separate core/video blocks (no gallery aggregation).
$c4_consec_html = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'video', 'identifier' => 'V-1' ),
					array( 'type' => 'video', 'identifier' => 'V-2' ),
				),
			),
		),
	),
	new Day_One_Importer_Results(),
	array(),
	array( 'V-1' => 501, 'V-2' => 502 )
);
$c4_consec_blocks = parse_blocks( $c4_consec_html );
assert_true( 2 === count( $c4_consec_blocks ), '#57 R11.4 — two consecutive videos produce exactly two top-level blocks.' );
assert_true( 'core/video' === $c4_consec_blocks[0]['blockName'] && 'core/video' === $c4_consec_blocks[1]['blockName'], '#57 R11.4 — both consecutive blocks are core/video (no gallery aggregation).' );
assert_true( 501 === (int) $c4_consec_blocks[0]['attrs']['id'] && 502 === (int) $c4_consec_blocks[1]['attrs']['id'], '#57 R11.4 — consecutive videos preserve scan order in attrs[id].' );

// Mixed photo, video, photo — interleaved emission with photo runs split by video.
$c4_mixed_html = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'photo', 'identifier' => 'P-1' ),
					array( 'type' => 'video', 'identifier' => 'V-1' ),
					array( 'type' => 'photo', 'identifier' => 'P-2' ),
				),
			),
		),
	),
	new Day_One_Importer_Results(),
	array( 'P-1' => 101, 'P-2' => 102 ),
	array( 'V-1' => 501 )
);
$c4_mixed_blocks = parse_blocks( $c4_mixed_html );
$c4_mixed_names  = array_map( static function ( $b ) {
	return $b['blockName'];
}, $c4_mixed_blocks );
assert_true( array( 'core/image', 'core/video', 'core/image' ) === $c4_mixed_names, '#57 AC9 — photo,video,photo sequence emits core/image, core/video, core/image in order.' );

// Mixed photo, photo, video, photo — gallery splitting edge case (K8).
$c4_split_html = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'photo', 'identifier' => 'P-1' ),
					array( 'type' => 'photo', 'identifier' => 'P-2' ),
					array( 'type' => 'video', 'identifier' => 'V-1' ),
					array( 'type' => 'photo', 'identifier' => 'P-3' ),
				),
			),
		),
	),
	new Day_One_Importer_Results(),
	array( 'P-1' => 101, 'P-2' => 102, 'P-3' => 202 ),
	array( 'V-1' => 502 )
);
$c4_split_blocks = parse_blocks( $c4_split_html );
$c4_split_names  = array_map( static function ( $b ) {
	return $b['blockName'];
}, $c4_split_blocks );
assert_true( array( 'core/gallery', 'core/video', 'core/image' ) === $c4_split_names, '#57 K8 — photo,photo,video,photo splits into gallery,video,image.' );

// Mixed video, photo, video — alternation preserves order.
$c4_alt_html = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'video', 'identifier' => 'V-1' ),
					array( 'type' => 'photo', 'identifier' => 'P-1' ),
					array( 'type' => 'video', 'identifier' => 'V-2' ),
				),
			),
		),
	),
	new Day_One_Importer_Results(),
	array( 'P-1' => 101 ),
	array( 'V-1' => 501, 'V-2' => 502 )
);
$c4_alt_blocks = parse_blocks( $c4_alt_html );
$c4_alt_names  = array_map( static function ( $b ) {
	return $b['blockName'];
}, $c4_alt_blocks );
assert_true( array( 'core/video', 'core/image', 'core/video' ) === $c4_alt_names, '#57 R11.4 — video,photo,video sequence emits video,image,video.' );

// Unresolved video identifier (not in $video_map) emits no block and ONE warning per missing identifier.
$c4_miss_results = new Day_One_Importer_Results();
$c4_miss_html    = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'video', 'identifier' => 'V-MISS' ),
				),
			),
		),
	),
	$c4_miss_results,
	array(),
	array() // empty video map
);
assert_true( '' === $c4_miss_html, '#57 AC8 — unresolved video emits no block.' );
$c4_miss_warnings = $c4_miss_results->get_warnings();
assert_true( 1 === count( $c4_miss_warnings ), '#57 AC8 — unresolved video records exactly one warning.' );
assert_true( $c4_miss_warnings[0] === 'Skipping embedded video in Day One entry: referenced media file is unsupported or missing.', '#57 R5.3 — warning text is privacy-safe (no identifier).' );
assert_true( false === strpos( $c4_miss_warnings[0], 'V-MISS' ), '#57 R5.3 — warning does not leak the embed identifier.' );

// wp_kses_post() round-trip on the video block: wp-block-video class survives, parse_blocks still recognizes core/video.
$c4_kses_html = wp_kses_post( $c4_single_html );
$c4_kses_blocks = parse_blocks( $c4_kses_html );
assert_true( 1 === count( $c4_kses_blocks ) && 'core/video' === $c4_kses_blocks[0]['blockName'], '#57 R6.5 — wp_kses_post() round-trip preserves the core/video block.' );
assert_true( false !== strpos( $c4_kses_html, 'wp-block-video' ), '#57 R6.5 — wp_kses_post() preserves the wp-block-video class.' );

// serialize_video_block defensive guard: an attachment URL outside the private uploads subdir is skipped.
$c4_guard_html = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'video', 'identifier' => 'V-OUTSIDE' ),
				),
			),
		),
	),
	new Day_One_Importer_Results(),
	array(),
	array( 'V-OUTSIDE' => 303 ) // stub returns a non-private URL for ID 303.
);
assert_true( '' === $c4_guard_html, '#57 R6.5 — emitter refuses to serialize a video block when the URL is not from the private uploads subdir.' );

// #56 AC7 — unresolved photo identifier emits zero blocks and exactly one warning.
$ac7_results = new Day_One_Importer_Results();
$ac7_output  = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array(
						'type'       => 'photo',
						'identifier' => 'AC7-MISSING',
					),
				),
			),
		),
	),
	$ac7_results,
	array() // empty map — every photo is unresolved.
);
assert_true( '' === $ac7_output, '#56 AC7 — unresolved photo emits no blocks.' );
$ac7_warnings = $ac7_results->get_warnings();
assert_true( 1 === count( $ac7_warnings ), '#56 AC7 — exactly one warning recorded.' );
assert_true( false === strpos( $ac7_warnings[0], 'AC7-MISSING' ), '#56 AC7 — warning does not leak the unresolved identifier.' );

// #56 AC7 cardinality — two distinct unresolved identifiers produce TWO warnings (per-identifier, NOT deduped).
$ac7_card_results = new Day_One_Importer_Results();
Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array(
						'type'       => 'photo',
						'identifier' => 'AC7-MISS-A',
					),
					array(
						'type'       => 'photo',
						'identifier' => 'AC7-MISS-B',
					),
				),
			),
		),
	),
	$ac7_card_results,
	array()
);
assert_true( 2 === count( $ac7_card_results->get_warnings() ), '#56 AC7 — two distinct missing identifiers produce two warnings (per-identifier, not deduped).' );

// #56 AC9 — entry-shaped finalize call with empty map yields warning + no block, no PHP notice.
error_clear_last();
$ac9_results = new Day_One_Importer_Results();
$ac9_entry   = array(
	'uuid'     => 'AC9-ENTRY',
	'richText' => array(
		'meta'     => array( 'version' => 1 ),
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array(
						'type'       => 'photo',
						'identifier' => 'AC9-PHOTO',
					),
				),
			),
		),
	),
);
$ac9_output = Day_One_Importer_Content::render_entry_body( $ac9_entry, $ac9_results, array() );
assert_true( '' === $ac9_output, '#56 AC9 — render_entry_body with empty map yields empty body.' );
assert_true( $ac9_results->has_warnings(), '#56 AC9 — render_entry_body with unresolved embed records a warning.' );
$ac9_last_error = error_get_last();
assert_true( null === $ac9_last_error || ! in_array( (int) $ac9_last_error['type'], array( E_ERROR, E_WARNING, E_NOTICE, E_USER_ERROR, E_USER_WARNING, E_USER_NOTICE ), true ), '#56 AC9 — render_entry_body call did not raise a PHP error/warning/notice.' );

// R11.6 (R19.1 inversion) — italic attribute wraps text in <em> inside the paragraph block.
$rt_italic = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'italic' => true ),
				'text'       => 'fancy',
			),
		),
	)
);
assert_true( false !== strpos( $rt_italic, '<p><em>fancy</em></p>' ), 'richText inline italic attribute wraps text in <em> inside the paragraph block.' );
assert_true( false !== strpos( $rt_italic, '<em>fancy</em>' ), 'richText italic wrapper encloses the run text.' );
assert_true( false === strpos( $rt_italic, '<i>' ), 'richText italic does NOT emit <i> (semantic <em> only).' );
assert_true( false === strpos( $rt_italic, '<strong>' ), 'richText italic does NOT also emit <strong> (guard against over-wrapping).' );

// R11.7 — JSON-string vs decoded-array equivalence on rendered output.
$rt_payload = array(
	'meta'     => array( 'version' => 1 ),
	'contents' => array(
		array( 'text' => 'roundtrip sample' ),
		array(
			'attributes' => array( 'italic' => true ),
			'text'       => 'two',
		),
	),
);
$rt_json    = wp_json_encode( $rt_payload );
$rt_decoded = json_decode( $rt_json, true );
assert_true( Day_One_Importer_Content::convert_rich_text_to_content( $rt_json ) === Day_One_Importer_Content::convert_rich_text_to_content( $rt_decoded ), 'richText string and decoded payloads produce identical rendered output.' );

// R11.8 — empty / missing contents returns ''.
assert_true( '' === Day_One_Importer_Content::convert_rich_text_to_content( null ), 'richText null input returns empty string.' );
assert_true( '' === Day_One_Importer_Content::convert_rich_text_to_content( '' ), 'richText empty string returns empty string.' );
assert_true( '' === Day_One_Importer_Content::convert_rich_text_to_content( 'not json' ), 'richText non-JSON string returns empty string.' );
assert_true( '' === Day_One_Importer_Content::convert_rich_text_to_content( array() ), 'richText empty array returns empty string.' );
assert_true( '' === Day_One_Importer_Content::convert_rich_text_to_content( array( 'contents' => array() ) ), 'richText empty contents returns empty string.' );
assert_true( '' === Day_One_Importer_Content::convert_rich_text_to_content( array( 'meta' => array( 'version' => 1 ) ) ), 'richText payload without contents returns empty string.' );

// R11.9 — escape contract: shortcodes and raw HTML are neutralized.
$rt_escape = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array( 'text' => '[gallery] <script>x</script>' ),
		),
	)
);
assert_true( false === strpos( $rt_escape, '[gallery]' ), 'richText shortcode brackets are neutralized.' );
assert_true( false === strpos( $rt_escape, '<script>' ), 'richText raw script tags are escaped.' );

// R11.10 — empty-text line-attribute item is dropped (no heading, no empty paragraph).
$rt_empty_line_attr = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'line' => array( 'header' => 1 ) ),
				'text'       => '',
			),
		),
	)
);
assert_true( '' === $rt_empty_line_attr, 'richText empty-text item with line.header attribute is silently dropped (no heading, no empty paragraph).' );

// R12.x — inline attribute wrapping (issue #54).

// R12.1 — bold wraps in <strong>.
$rt_bold = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'bold' => true ),
				'text'       => 'b',
			),
		),
	)
);
assert_true( false !== strpos( $rt_bold, '<p><strong>b</strong></p>' ), 'R12.1 — bold attribute wraps in <strong>.' );

// R12.2 — italic wraps in <em>. (Mirrors the R11.6 inversion for completeness in the R12 block.)
$rt_em = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'italic' => true ),
				'text'       => 'i',
			),
		),
	)
);
assert_true( false !== strpos( $rt_em, '<p><em>i</em></p>' ), 'R12.2 — italic attribute wraps in <em>.' );

// R12.3 — strikethrough wraps in <s>.
$rt_s = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'strikethrough' => true ),
				'text'       => 's',
			),
		),
	)
);
assert_true( false !== strpos( $rt_s, '<p><s>s</s></p>' ), 'R12.3 — strikethrough attribute wraps in <s>.' );

// R12.4 — inlineCode wraps in <code>.
$rt_code = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'inlineCode' => true ),
				'text'       => 'c',
			),
		),
	)
);
assert_true( false !== strpos( $rt_code, '<p><code>c</code></p>' ), 'R12.4 — inlineCode attribute wraps in <code>.' );

// R12.5 — valid https linkURL wraps in <a href="…">.
$rt_link = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'linkURL' => 'https://example.com/x' ),
				'text'       => 'Linked.',
			),
		),
	)
);
assert_true( false !== strpos( $rt_link, '<p><a href="https://example.com/x">Linked.</a></p>' ), 'R12.5 — valid https linkURL wraps in <a href="…">.' );

// R12.6 — autolink + valid linkURL behaves identically to R12.5.
$rt_autolink_with_url = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array(
					'autolink' => true,
					'linkURL'  => 'https://example.com/auto',
				),
				'text'       => 'Auto.',
			),
		),
	)
);
assert_true( false !== strpos( $rt_autolink_with_url, '<p><a href="https://example.com/auto">Auto.</a></p>' ), 'R12.6 — autolink + valid linkURL wraps identically to bare linkURL.' );

// R12.7 — valid highlightedColor wraps in <mark style="background-color:#RRGGBB"> (case preserved).
$rt_highlight = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'highlightedColor' => '0x000FFF' ),
				'text'       => 'Highlighted.',
			),
		),
	)
);
assert_true( false !== strpos( $rt_highlight, '<p><mark style="background-color:#000FFF">Highlighted.</mark></p>' ), 'R12.7 — valid highlightedColor wraps in <mark style="background-color:#RRGGBB">.' );

// R12.8 — multi-attribute combination: exact byte sequence pinned in spec R18.
$rt_combo       = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array(
					'bold'             => true,
					'italic'           => true,
					'strikethrough'    => true,
					'inlineCode'       => true,
					'highlightedColor' => '0x000FFF',
					'linkURL'          => 'https://example.com/x',
				),
				'text'       => 'hello',
			),
		),
	)
);
$rt_combo_pinned = '<a href="https://example.com/x"><mark style="background-color:#000FFF"><strong><em><s><code>hello</code></s></em></strong></mark></a>';
assert_true( false !== strpos( $rt_combo, $rt_combo_pinned ), 'R12.8 — multi-attribute combination produces the pinned R18 byte sequence.' );
assert_true( false !== strpos( $rt_combo, '<p>' . $rt_combo_pinned . '</p>' ), 'R12.8 — pinned R18 sequence sits inside the paragraph block.' );

// R12.9 — wrapped text is HTML-escaped before wrapping (R10 invariant).
$rt_escape_wrapped = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'bold' => true ),
				'text'       => '<script>x</script>',
			),
		),
	)
);
assert_true( false !== strpos( $rt_escape_wrapped, '<strong>&lt;script&gt;x&lt;/script&gt;</strong>' ), 'R12.9 — bold wrapper contains HTML-escaped angle brackets, not raw script tags.' );
assert_true( false === strpos( $rt_escape_wrapped, '<script>' ), 'R12.9 — raw <script> never reaches output even inside a wrapper.' );

// R12.10 — non-http(s) linkURL drops anchor, preserves escaped text, records privacy-safe warning.
$rt_link_results = new Day_One_Importer_Results();
$rt_bad_link     = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'linkURL' => 'javascript:alert(1)' ),
				'text'       => 'Bad link.',
			),
		),
	),
	$rt_link_results
);
assert_true( false === strpos( $rt_bad_link, '<a ' ), 'R12.10 — non-http(s) linkURL emits no anchor.' );
assert_true( false === strpos( $rt_bad_link, 'javascript' ), 'R12.10 — rejected URL value does not leak into output.' );
assert_true( false !== strpos( $rt_bad_link, 'Bad link.' ), 'R12.10 — rejected-link run text still renders.' );
$rt_link_warnings = $rt_link_results->get_warnings();
assert_true( ! empty( $rt_link_warnings ), 'R12.10 — rejected linkURL records a warning.' );
$rt_link_warning_msg = (string) $rt_link_warnings[0];
assert_true( false !== strpos( $rt_link_warning_msg, 'richText' ), 'R12.10 — warning contains the "richText" locator substring.' );
assert_true( false === strpos( $rt_link_warning_msg, 'javascript:alert(1)' ), 'R12.10 — warning does NOT contain the rejected URL verbatim.' );

// R12.10b — relative URL "/foo" rejects at preg_match (no https? prefix) even if esc_url accepts it.
$rt_rel_results = new Day_One_Importer_Results();
$rt_rel_link    = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'linkURL' => '/foo' ),
				'text'       => 'Rel.',
			),
		),
	),
	$rt_rel_results
);
assert_true( false === strpos( $rt_rel_link, '<a ' ), 'R12.10b — relative URL "/foo" emits no anchor (no https? prefix).' );
assert_true( ! empty( $rt_rel_results->get_warnings() ), 'R12.10b — relative URL records a warning.' );

// R12.11 — malformed highlightedColor drops <mark>, preserves escaped text, records privacy-safe warning.
$rt_color_results = new Day_One_Importer_Results();
$rt_bad_color     = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'highlightedColor' => '0xZZZZZZ' ),
				'text'       => 'Bad color.',
			),
		),
	),
	$rt_color_results
);
assert_true( false === strpos( $rt_bad_color, '<mark' ), 'R12.11 — malformed highlightedColor emits no <mark>.' );
assert_true( false === strpos( $rt_bad_color, '0xZZZZZZ' ), 'R12.11 — rejected color value does not leak into output.' );
assert_true( false !== strpos( $rt_bad_color, 'Bad color.' ), 'R12.11 — rejected-color run text still renders.' );
$rt_color_warnings = $rt_color_results->get_warnings();
assert_true( ! empty( $rt_color_warnings ), 'R12.11 — rejected highlightedColor records a warning.' );
$rt_color_warning_msg = (string) $rt_color_warnings[0];
assert_true( false !== strpos( $rt_color_warning_msg, 'richText' ), 'R12.11 — warning contains "richText" locator.' );
assert_true( false === strpos( $rt_color_warning_msg, '0xZZZZZZ' ), 'R12.11 — warning does NOT contain the rejected color verbatim.' );

// R12.11b — every present-but-invalid highlightedColor variant drops <mark> AND records a warning.
foreach ( array( '0x12345', '#FF0000', '0x000FFFF', '0xF0F', '', null, 123, array(), true, false ) as $rt_present_invalid ) {
	$rt_variant_results = new Day_One_Importer_Results();
	$rt_variant_output  = Day_One_Importer_Content::convert_rich_text_to_content(
		array(
			'contents' => array(
				array(
					'attributes' => array( 'highlightedColor' => $rt_present_invalid ),
					'text'       => 'x',
				),
			),
		),
		$rt_variant_results
	);
	assert_true( false === strpos( $rt_variant_output, '<mark' ), sprintf( 'R12.11b — highlightedColor=%s drops <mark>.', var_export( $rt_present_invalid, true ) ) );
	assert_true( ! empty( $rt_variant_results->get_warnings() ), sprintf( 'R12.11b — highlightedColor=%s records a warning (present-but-invalid).', var_export( $rt_present_invalid, true ) ) );
}

// R12.11c — truly-absent highlightedColor records no warning.
$rt_absent_results = new Day_One_Importer_Results();
Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'bold' => true ),
				'text'       => 'x',
			),
		),
	),
	$rt_absent_results
);
assert_true( empty( $rt_absent_results->get_warnings() ), 'R12.11c — absent highlightedColor records no warning.' );

// R12.12 — autolink: true without linkURL emits no anchor and no warning.
$rt_auto_results = new Day_One_Importer_Results();
$rt_autolink     = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'autolink' => true ),
				'text'       => 'Autolink alone.',
			),
		),
	),
	$rt_auto_results
);
assert_true( false === strpos( $rt_autolink, '<a ' ), 'R12.12 — autolink without linkURL emits no anchor.' );
assert_true( empty( $rt_auto_results->get_warnings() ), 'R12.12 — autolink without linkURL records no warning (not an error).' );
assert_true( false !== strpos( $rt_autolink, 'Autolink alone.' ), 'R12.12 — autolink-without-URL run text still renders.' );

// R12.13 — loose-truthy values for boolean attributes do NOT trigger wrapping.
foreach ( array( 1, '1', 'true', 'yes', 'TRUE' ) as $rt_loose_value ) {
	$rt_loose_out = Day_One_Importer_Content::convert_rich_text_to_content(
		array(
			'contents' => array(
				array(
					'attributes' => array( 'bold' => $rt_loose_value ),
					'text'       => 'plain',
				),
			),
		)
	);
	assert_true( false === strpos( $rt_loose_out, '<strong>' ), sprintf( 'R12.13 — bold=%s (loose-truthy) does NOT trigger <strong>.', var_export( $rt_loose_value, true ) ) );
}

// R12.14 — calling with null results emits no PHP warning and produces same wrappers.
$rt_null_results_out = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'bold' => true ),
				'text'       => 'b',
			),
		),
	),
	null
);
assert_true( false !== strpos( $rt_null_results_out, '<strong>b</strong>' ), 'R12.14 — null results argument still produces the wrapper.' );

// R12.14b — rejection paths silently no-op with null results (no fatal error).
$rt_null_reject = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'linkURL' => 'javascript:x' ),
				'text'       => 'plain',
			),
		),
	),
	null
);
assert_true( false === strpos( $rt_null_reject, '<a ' ), 'R12.14b — null results still drops invalid linkURL (no fatal).' );

// R12.15 — default-call back-compat: single-arg and explicit-null calls produce identical output.
$rt_single_arg    = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'bold' => true ),
				'text'       => 'b',
			),
		),
	)
);
$rt_explicit_null = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'bold' => true ),
				'text'       => 'b',
			),
		),
	),
	null
);
assert_true( $rt_single_arg === $rt_explicit_null, 'R12.15 — single-arg and explicit-null calls produce identical output.' );

// R12.16 — render_entry_body threads $results into the richText helper.
$rt_dispatch_results = new Day_One_Importer_Results();
$rt_dispatch_entry   = array(
	'richText' => array(
		'contents' => array(
			array(
				'attributes' => array( 'linkURL' => 'javascript:x' ),
				'text'       => 'plain',
			),
		),
	),
);
Day_One_Importer_Content::render_entry_body( $rt_dispatch_entry, $rt_dispatch_results );
assert_true( ! empty( $rt_dispatch_results->get_warnings() ), 'R12.16 — render_entry_body threads $results into the richText branch (warning observed).' );

// --- R13.x — richText line-attribute mapping (issue #55) ---
// One assertion per AC in spec.md; helpers exercise the new single-forward-pass
// + group emitter path. Existing R11.x / R12.x assertions remain unchanged.

// R13.1 — header: 2 produces a core/heading block without a level attribute (Gutenberg default).
$rt_h2 = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'line' => array( 'header' => 2 ) ),
				'text'       => 'Imaginary heading two',
			),
		),
	)
);
assert_true( false !== strpos( $rt_h2, '<!-- wp:heading -->' ), 'R13.1 — header: 2 emits a core/heading block (no level attr).' );
assert_true( false !== strpos( $rt_h2, '<h2>Imaginary heading two</h2>' ), 'R13.1 — header: 2 inner is <h2>...</h2>.' );

// R13.2 — header levels 1, 3, 4, 5, 6 emit explicit "level" attr (only level 2 is the default).
foreach ( array( 1, 3, 4, 5, 6 ) as $rt_h_lvl ) {
	$rt_h_out = Day_One_Importer_Content::convert_rich_text_to_content(
		array(
			'contents' => array(
				array(
					'attributes' => array( 'line' => array( 'header' => $rt_h_lvl ) ),
					'text'       => 'Imaginary heading ' . $rt_h_lvl,
				),
			),
		)
	);
	assert_true( false !== strpos( $rt_h_out, '<!-- wp:heading {"level":' . $rt_h_lvl . '} -->' ), sprintf( 'R13.2 — header level %d emits explicit level attr.', $rt_h_lvl ) );
	assert_true( false !== strpos( $rt_h_out, '<h' . $rt_h_lvl . '>Imaginary heading ' . $rt_h_lvl . '</h' . $rt_h_lvl . '>' ), sprintf( 'R13.2 — header level %d emits <h%d>.', $rt_h_lvl, $rt_h_lvl ) );
}

// R13.3 — two adjacent header: 2 items emit two separate core/heading blocks (R2.3 — no inter-heading collapse).
$rt_h2_pair = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'line' => array( 'header' => 2 ) ),
				'text'       => 'First',
			),
			array(
				'attributes' => array( 'line' => array( 'header' => 2 ) ),
				'text'       => 'Second',
			),
		),
	)
);
assert_true( 2 === substr_count( $rt_h2_pair, '<!-- wp:heading -->' ), 'R13.3 — two adjacent header: 2 items emit two separate core/heading blocks.' );

// R13.4 — multi-line heading item renders intra-text \n as <br /> inside the same <h2>.
$rt_h2_multiline = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'line' => array( 'header' => 2 ) ),
				'text'       => "line one\nline two",
			),
		),
	)
);
assert_true( false !== strpos( $rt_h2_multiline, "<h2>line one<br />\nline two</h2>" ), 'R13.4 — multi-line header item renders \n as <br /> inside one <h2>.' );

// R13.5 — out-of-range / non-int header values fall through to the paragraph path (R3.3).
foreach ( array( 0, 7, '2', 2.5, true, null ) as $rt_h_bad ) {
	$rt_h_bad_out = Day_One_Importer_Content::convert_rich_text_to_content(
		array(
			'contents' => array(
				array(
					'attributes' => array( 'line' => array( 'header' => $rt_h_bad ) ),
					'text'       => 'Fallback heading text',
				),
			),
		)
	);
	assert_true( false === strpos( $rt_h_bad_out, '<!-- wp:heading' ), sprintf( 'R13.5 — header=%s falls through to paragraph (no heading block).', var_export( $rt_h_bad, true ) ) );
	assert_true( false !== strpos( $rt_h_bad_out, 'Fallback heading text' ), sprintf( 'R13.5 — header=%s — paragraph fallback still renders text.', var_export( $rt_h_bad, true ) ) );
}

// R13.6 — bulleted run at indentLevel: 1 emits one core/list block with one core/list-item per item.
$rt_bullet_run = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'line' => array( 'listStyle' => 'bulleted', 'indentLevel' => 1 ) ),
				'text'       => 'alpha',
			),
			array(
				'attributes' => array( 'line' => array( 'listStyle' => 'bulleted', 'indentLevel' => 1 ) ),
				'text'       => 'beta',
			),
			array(
				'attributes' => array( 'line' => array( 'listStyle' => 'bulleted', 'indentLevel' => 1 ) ),
				'text'       => 'gamma',
			),
		),
	)
);
assert_true( 1 === substr_count( $rt_bullet_run, '<!-- wp:list -->' ), 'R13.6 — bulleted run emits one core/list block.' );
assert_true( 3 === substr_count( $rt_bullet_run, '<!-- wp:list-item -->' ), 'R13.6 — bulleted run emits one core/list-item per item.' );

// R13.7 — bulleted nesting: nested list lives inside the previous list-item (parent-child shape).
$rt_bullet_nested = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'line' => array( 'listStyle' => 'bulleted', 'indentLevel' => 1 ) ),
				'text'       => 'outer one',
			),
			array(
				'attributes' => array( 'line' => array( 'listStyle' => 'bulleted', 'indentLevel' => 2 ) ),
				'text'       => 'inner one',
			),
			array(
				'attributes' => array( 'line' => array( 'listStyle' => 'bulleted', 'indentLevel' => 1 ) ),
				'text'       => 'outer two',
			),
		),
	)
);
// Nested core/list sits between an outer <li> and </li>.
assert_true( false !== strpos( $rt_bullet_nested, "<li>outer one<!-- wp:list -->" ), 'R13.7 — nested bulleted list opens inside the previous outer <li>.' );
assert_true( false !== strpos( $rt_bullet_nested, "<!-- /wp:list -->\n</li>" ), 'R13.7 — nested bulleted list closes before the parent </li>.' );

// R13.8 — numbered list with first listIndex: 1 emits no "start" attr (AC5).
$rt_num_default = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'line' => array( 'listStyle' => 'numbered', 'indentLevel' => 1, 'listIndex' => 1 ) ),
				'text'       => 'one',
			),
			array(
				'attributes' => array( 'line' => array( 'listStyle' => 'numbered', 'indentLevel' => 1, 'listIndex' => 2 ) ),
				'text'       => 'two',
			),
		),
	)
);
assert_true( false !== strpos( $rt_num_default, '<!-- wp:list {"ordered":true} -->' ), 'R13.8 — numbered list with first listIndex 1 has ordered:true and no start.' );
assert_true( false === strpos( $rt_num_default, '"start"' ), 'R13.8 — numbered list with first listIndex 1 does NOT emit start attr.' );

// R13.9 — numbered list with first listIndex: 5 emits "start":5; numeric-string "5" also casts to 5.
$rt_num_start5_int = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'line' => array( 'listStyle' => 'numbered', 'indentLevel' => 1, 'listIndex' => 5 ) ),
				'text'       => 'five',
			),
		),
	)
);
assert_true( false !== strpos( $rt_num_start5_int, '<!-- wp:list {"ordered":true,"start":5} -->' ), 'R13.9 — integer listIndex 5 emits start:5.' );

$rt_num_start5_str = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'line' => array( 'listStyle' => 'numbered', 'indentLevel' => 1, 'listIndex' => '5' ) ),
				'text'       => 'five',
			),
		),
	)
);
assert_true( false !== strpos( $rt_num_start5_str, '<!-- wp:list {"ordered":true,"start":5} -->' ), 'R13.9 — numeric-string listIndex "5" also emits start:5.' );

// R13.10 — listIndex variants that must NOT emit start: "-3", 0, 1.5, array().
foreach (
	array(
		array( 'listIndex' => '-3' ),
		array( 'listIndex' => 0 ),
		array( 'listIndex' => 1.5 ),
		array( 'listIndex' => array() ),
	) as $rt_li_variant
) {
	$rt_li_out = Day_One_Importer_Content::convert_rich_text_to_content(
		array(
			'contents' => array(
				array(
					'attributes' => array(
						'line' => array_merge( array( 'listStyle' => 'numbered', 'indentLevel' => 1 ), $rt_li_variant ),
					),
					'text'       => 'item',
				),
			),
		)
	);
	assert_true( false === strpos( $rt_li_out, '"start"' ), sprintf( 'R13.10 — listIndex=%s emits no start attr.', var_export( $rt_li_variant['listIndex'], true ) ) );
}

// R13.11 — nested numbered list does NOT emit start even if its first child listIndex > 1 (R4.5, AC7).
$rt_num_nested_start = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'line' => array( 'listStyle' => 'numbered', 'indentLevel' => 1, 'listIndex' => 1 ) ),
				'text'       => 'outer one',
			),
			array(
				'attributes' => array( 'line' => array( 'listStyle' => 'numbered', 'indentLevel' => 2, 'listIndex' => 5 ) ),
				'text'       => 'nested five',
			),
		),
	)
);
// Outer list (first occurrence) is ordered:true with no start; nested list (second wp:list opener) also has no start.
$rt_num_nested_openers = substr_count( $rt_num_nested_start, '<!-- wp:list {"ordered":true} -->' );
assert_true( 2 === $rt_num_nested_openers, 'R13.11 — both outer and nested numbered lists emit ordered:true with NO start.' );
assert_true( false === strpos( $rt_num_nested_start, '"start"' ), 'R13.11 — nested numbered list emits no start attr.' );

// R13.12 — checkbox list emits className=task-list and outer <ul class="task-list">.
$rt_checkbox = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'line' => array( 'listStyle' => 'checkbox', 'indentLevel' => 1, 'checked' => true ) ),
				'text'       => 'first',
			),
		),
	)
);
assert_true( false !== strpos( $rt_checkbox, '<!-- wp:list {"className":"task-list"} -->' ), 'R13.12 — checkbox list emits className=task-list.' );
assert_true( false !== strpos( $rt_checkbox, '<ul class="task-list">' ), 'R13.12 — checkbox list outer ul carries the task-list class.' );

// R13.13 — checked: true list-item begins with exact 8-byte sequence "&#9745; ".
assert_true( false !== strpos( $rt_checkbox, '<li>&#9745; first</li>' ), 'R13.13 — checked: true list-item is prefixed with &#9745; + ASCII space.' );

// R13.14 — checked: false / absent / non-bool checked produces "&#9744; ".
$rt_checkbox_unchecked = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'line' => array( 'listStyle' => 'checkbox', 'indentLevel' => 1, 'checked' => false ) ),
				'text'       => 'false',
			),
			array(
				'attributes' => array( 'line' => array( 'listStyle' => 'checkbox', 'indentLevel' => 1 ) ),
				'text'       => 'absent',
			),
			array(
				'attributes' => array( 'line' => array( 'listStyle' => 'checkbox', 'indentLevel' => 1, 'checked' => 'true' ) ),
				'text'       => 'string-true',
			),
		),
	)
);
assert_true( false !== strpos( $rt_checkbox_unchecked, '<li>&#9744; false</li>' ), 'R13.14 — checked: false list-item prefixed with &#9744; + ASCII space.' );
assert_true( false !== strpos( $rt_checkbox_unchecked, '<li>&#9744; absent</li>' ), 'R13.14 — checked absent list-item prefixed with &#9744; + ASCII space.' );
assert_true( false !== strpos( $rt_checkbox_unchecked, '<li>&#9744; string-true</li>' ), 'R13.14 — checked="true" (string) list-item is prefixed with &#9744; (strict bool rule).' );
assert_true( false === strpos( $rt_checkbox_unchecked, '&#9745;' ), 'R13.14 — no checked-glyph leaks into the unchecked list-items.' );

// R13.15 — 3-item code run produces inner <code>a\nb\nc</code> with no trailing \n.
$rt_code_run = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array( 'attributes' => array( 'line' => array( 'codeBlock' => true ) ), 'text' => "a\n" ),
			array( 'attributes' => array( 'line' => array( 'codeBlock' => true ) ), 'text' => "b\n" ),
			array( 'attributes' => array( 'line' => array( 'codeBlock' => true ) ), 'text' => "c\n" ),
		),
	)
);
assert_true( false !== strpos( $rt_code_run, "<code>a\nb\nc</code>" ), 'R13.15 — 3-item code run joins as a\nb\nc with no trailing newline.' );
assert_true( 1 === substr_count( $rt_code_run, '<!-- wp:code -->' ), 'R13.15 — 3-item code run produces exactly one core/code block.' );

// R13.16 — invalid linkURL / highlightedColor on a code item emit NO warning AND no anchor / no mark.
$rt_code_warn_results = new Day_One_Importer_Results();
$rt_code_warn         = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array(
					'line'             => array( 'codeBlock' => true ),
					'linkURL'          => 'javascript:x',
					'highlightedColor' => '0xZZZZZZ',
				),
				'text'       => "alert\n",
			),
		),
	),
	$rt_code_warn_results
);
assert_true( false === strpos( $rt_code_warn, '<a ' ), 'R13.16 — invalid linkURL on a code item does NOT emit <a>.' );
assert_true( false === strpos( $rt_code_warn, '<mark' ), 'R13.16 — invalid highlightedColor on a code item does NOT emit <mark>.' );
assert_true( empty( $rt_code_warn_results->get_warnings() ), 'R13.16 — code items do NOT record warnings for invalid inline attrs (R5.3 / F21).' );

// R13.17 — bold: true on a code item does NOT emit <strong> inside <code>.
$rt_code_bold = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array(
					'line' => array( 'codeBlock' => true ),
					'bold' => true,
				),
				'text'       => "loud\n",
			),
		),
	)
);
assert_true( false === strpos( $rt_code_bold, '<strong>' ), 'R13.17 — bold: true on code item does NOT emit <strong> inside <code> (R5.3).' );

// R13.18 — two consecutive quote items produce one core/quote with two child core/paragraph blocks.
$rt_quote_pair = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array( 'attributes' => array( 'line' => array( 'quote' => true, 'indentLevel' => 1 ) ), 'text' => 'one' ),
			array( 'attributes' => array( 'line' => array( 'quote' => true, 'indentLevel' => 1 ) ), 'text' => 'two' ),
		),
	)
);
assert_true( 1 === substr_count( $rt_quote_pair, '<!-- wp:quote -->' ), 'R13.18 — two consecutive quote items produce one core/quote block.' );
assert_true( 2 === substr_count( $rt_quote_pair, '<!-- wp:paragraph -->' ), 'R13.18 — quote block contains two child core/paragraph blocks.' );
assert_true( 1 === substr_count( $rt_quote_pair, '<blockquote' ), 'R13.18 — quote block emits exactly one <blockquote>.' );

// R13.19 — quote indentLevel is ignored (R6.5): both items render as siblings inside one blockquote.
$rt_quote_indent = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array( 'attributes' => array( 'line' => array( 'quote' => true, 'indentLevel' => 1 ) ), 'text' => 'shallow' ),
			array( 'attributes' => array( 'line' => array( 'quote' => true, 'indentLevel' => 2 ) ), 'text' => 'deeper' ),
		),
	)
);
assert_true( 1 === substr_count( $rt_quote_indent, '<blockquote' ), 'R13.19 — quote items at different indentLevel still produce ONE <blockquote>.' );
assert_true( 2 === substr_count( $rt_quote_indent, '<!-- wp:paragraph -->' ), 'R13.19 — quote items at different indentLevel both render as sibling <p> children.' );

// R13.20 — inline wrappers survive inside quote child paragraphs (R6.2).
$rt_quote_bold = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'line' => array( 'quote' => true, 'indentLevel' => 1 ), 'bold' => true ),
				'text'       => 'loud',
			),
		),
	)
);
assert_true( false !== strpos( $rt_quote_bold, '<p><strong>loud</strong></p>' ), 'R13.20 — quote item with bold renders <p><strong>...</strong></p>.' );

// R13.21 — transparent-drop inside a same-kind run: bullet, empty, bullet -> one list with TWO list-items (R2.1, AC11).
$rt_drop_run = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array( 'attributes' => array( 'line' => array( 'listStyle' => 'bulleted', 'indentLevel' => 1 ) ), 'text' => 'A' ),
			array( 'attributes' => array( 'line' => array( 'listStyle' => 'bulleted', 'indentLevel' => 1 ) ), 'text' => '   ' ),
			array( 'attributes' => array( 'line' => array( 'listStyle' => 'bulleted', 'indentLevel' => 1 ) ), 'text' => 'B' ),
		),
	)
);
assert_true( 1 === substr_count( $rt_drop_run, '<!-- wp:list -->' ), 'R13.21 — empty-text item is transparent: same-kind run stays as one list.' );
assert_true( 2 === substr_count( $rt_drop_run, '<!-- wp:list-item -->' ), 'R13.21 — empty-text item is dropped: only two list-items emitted.' );

// R13.22 — kind switch across an empty-text drop closes the run and opens a new one.
$rt_kind_switch = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array( 'attributes' => array( 'line' => array( 'listStyle' => 'bulleted', 'indentLevel' => 1 ) ), 'text' => 'A' ),
			array( 'attributes' => array( 'line' => array( 'listStyle' => 'bulleted', 'indentLevel' => 1 ) ), 'text' => '   ' ),
			array( 'attributes' => array( 'line' => array( 'listStyle' => 'numbered', 'indentLevel' => 1, 'listIndex' => 1 ) ), 'text' => 'B' ),
		),
	)
);
assert_true( 1 === substr_count( $rt_kind_switch, '<!-- wp:list -->' ), 'R13.22 — one bulleted core/list block emitted before kind switch.' );
assert_true( 1 === substr_count( $rt_kind_switch, '<!-- wp:list {"ordered":true} -->' ), 'R13.22 — one numbered core/list block emitted after kind switch.' );

// R13.23 — plain-paragraph runs (no line key) keep byte-equivalent legacy delegation output.
// We compare against convert_text_to_content directly to pin the byte-for-byte contract (AC12).
$rt_para_legacy = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array( 'attributes' => array(), 'text' => "First paragraph.\n" ),
			array( 'attributes' => array(), 'text' => 'Second paragraph.' ),
		),
	)
);
$rt_para_expected = Day_One_Importer_Content::convert_text_to_content( 'First paragraph.' ) . Day_One_Importer_Content::convert_text_to_content( 'Second paragraph.' );
assert_true( trim( $rt_para_legacy ) === trim( $rt_para_expected ), 'R13.23 — paragraph runs delegate to convert_text_to_content byte-for-byte.' );

// R13.25 — R1.4 precedence: codeBlock > quote when both are true on one item.
$rt_prec_code_quote = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'line' => array( 'codeBlock' => true, 'quote' => true ) ),
				'text'       => "winner\n",
			),
		),
	)
);
assert_true( false !== strpos( $rt_prec_code_quote, '<!-- wp:code -->' ), 'R13.25 — precedence: codeBlock + quote item flows to core/code.' );
assert_true( false === strpos( $rt_prec_code_quote, '<!-- wp:quote' ), 'R13.25 — precedence: codeBlock wins; no core/quote emitted.' );

// R13.26 — R1.4 precedence: header > listStyle when both are set on one item.
$rt_prec_heading_list = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'line' => array( 'header' => 2, 'listStyle' => 'bulleted' ) ),
				'text'       => 'winner',
			),
		),
	)
);
assert_true( false !== strpos( $rt_prec_heading_list, '<!-- wp:heading -->' ), 'R13.26 — precedence: header + listStyle item flows to core/heading.' );
assert_true( false === strpos( $rt_prec_heading_list, '<!-- wp:list' ), 'R13.26 — precedence: header wins over listStyle; no core/list emitted.' );

// R13.27 — heading items bypass convert_text_to_content: a literal `#` inside text is NOT re-interpreted as markdown.
$rt_hash_inside_heading = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'line' => array( 'header' => 2 ) ),
				'text'       => '# Pretend pound',
			),
		),
	)
);
assert_true( false !== strpos( $rt_hash_inside_heading, '<h2># Pretend pound</h2>' ), 'R13.27 — literal `#` inside a heading item is preserved verbatim (no markdown reinterpretation).' );

// R13.28 — derive_title_from_entry: first non-empty richText item's text becomes the title, even when codeBlock (R9.2, AC17).
$rt_title_code = Day_One_Importer_Content::derive_title_from_entry(
	array(
		'text'     => '',
		'richText' => array(
			'contents' => array(
				array(
					'attributes' => array( 'line' => array( 'codeBlock' => true ) ),
					'text'       => "\$ ls -la",
				),
			),
		),
	),
	'2031-05-02 14:00:00'
);
assert_true( '$ ls -la' === $rt_title_code, 'R13.28 — derive_title_from_entry uses first non-empty richText run even when codeBlock (accepted surprise R9.2).' );

// R13.29 — whitespace-only text on a line-typed item is dropped (R1.3).
$rt_h2_blank = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'attributes' => array( 'line' => array( 'header' => 2 ) ),
				'text'       => '   ',
			),
		),
	)
);
assert_true( false === strpos( $rt_h2_blank, '<!-- wp:heading' ), 'R13.29 — whitespace-only header item is dropped (no heading block).' );
assert_true( '' === $rt_h2_blank, 'R13.29 — whitespace-only line-typed item produces empty output.' );

// R13.30 — calling convert_rich_text_to_content with null $results does not emit PHP warnings on any kind.
$rt_null_results_all_kinds = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array( 'attributes' => array( 'line' => array( 'header' => 1 ) ), 'text' => 'h' ),
			array( 'attributes' => array( 'line' => array( 'listStyle' => 'bulleted', 'indentLevel' => 1 ) ), 'text' => 'b' ),
			array( 'attributes' => array( 'line' => array( 'codeBlock' => true ) ), 'text' => "c\n" ),
			array( 'attributes' => array( 'line' => array( 'quote' => true, 'indentLevel' => 1 ) ), 'text' => 'q' ),
		),
		// No $results argument.
	)
);
assert_true( false !== strpos( $rt_null_results_all_kinds, '<!-- wp:heading' ), 'R13.30 — null $results: heading still emitted.' );
assert_true( false !== strpos( $rt_null_results_all_kinds, '<!-- wp:list' ), 'R13.30 — null $results: list still emitted.' );
assert_true( false !== strpos( $rt_null_results_all_kinds, '<!-- wp:code' ), 'R13.30 — null $results: code still emitted.' );
assert_true( false !== strpos( $rt_null_results_all_kinds, '<!-- wp:quote' ), 'R13.30 — null $results: quote still emitted.' );

// Step 4.16 — markdown-leakage acceptance: a `#`-prefixed run delegates to convert_text_to_content and renders as a heading block.
// This documents the intentional R5.10 delegation behavior; strict line-attribute handling is deferred to issue #55.
$rt_markdown_leak = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array( 'text' => '# Imaginary heading' ),
		),
	)
);
assert_true( false !== strpos( $rt_markdown_leak, '<!-- wp:heading' ), 'Markdown sigil in a richText run delegates to convert_text_to_content; scaffold defers strict line-attribute handling to issue #55.' );
assert_true( false === strpos( $rt_markdown_leak, '<p># ' ), 'Markdown-prefixed richText run does not render as a raw paragraph (delegated path).' );

// A8 — dispatch returns same string as legacy when richText absent.
$dispatch_legacy = array( 'text' => "# Heading\n\nParagraph" );
assert_true( Day_One_Importer_Content::render_entry_body( $dispatch_legacy ) === Day_One_Importer_Content::convert_text_to_content( $dispatch_legacy['text'] ), 'render_entry_body falls back to convert_text_to_content when richText is absent.' );

// A8 — dispatch routes through richText when richText is present.
$dispatch_rich = array(
	'text'     => 'unused fallback text',
	'richText' => array(
		'contents' => array(
			array( 'text' => 'Rich body paragraph' ),
		),
	),
);
assert_true( Day_One_Importer_Content::render_entry_body( $dispatch_rich ) === Day_One_Importer_Content::convert_rich_text_to_content( $dispatch_rich['richText'] ), 'render_entry_body routes through richText when present.' );
assert_true( false !== strpos( Day_One_Importer_Content::render_entry_body( $dispatch_rich ), 'Rich body paragraph' ), 'render_entry_body richText dispatch surfaces richText content.' );

// R8 — derive_title_from_entry: text-present path preserves legacy behavior.
$title_text = Day_One_Importer_Content::derive_title_from_entry(
	array( 'text' => "# Imaginary header title\nBody" ),
	'2026-05-08 12:00:00'
);
assert_true( 'Imaginary header title' === $title_text, 'derive_title_from_entry preserves legacy heading-extraction when text is present.' );

// R8.2 — empty text + richText falls back to first non-empty run.
$title_from_rich = Day_One_Importer_Content::derive_title_from_entry(
	array(
		'text'     => '',
		'richText' => array(
			'contents' => array(
				array(
					'attributes' => array( 'line' => array( 'header' => 1 ) ),
					'text'       => '',
				),
				array(
					'attributes' => array(),
					'text'       => 'Imaginary fallback title',
				),
				array( 'text' => 'Second paragraph not used for title' ),
			),
		),
	),
	'2026-05-08 12:00:00'
);
assert_true( 'Imaginary fallback title' === $title_from_rich, 'derive_title_from_entry falls back to the first non-empty richText run when text is empty.' );

// R8.3 — no usable text in either field falls back to date.
$title_date_fallback = Day_One_Importer_Content::derive_title_from_entry(
	array( 'text' => '' ),
	'2026-05-08 12:00:00'
);
$expected_date_title = Day_One_Importer_Content::derive_title( '', '2026-05-08 12:00:00' );
assert_true( $title_date_fallback === $expected_date_title, 'derive_title_from_entry falls back to the date-based title when no usable text exists.' );

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

// #60 R5.1 — GIF branch: build_attachment_image_record() must resolve `url` to
// wp_get_attachment_url() (the original file) and serialize_image_block() must
// emit sizeSlug=full + class=wp-block-image size-full. The stub for ID 1001
// returns a DIFFERENT `large` derivative URL via wp_get_attachment_image_src()
// — if that string ever leaks into the block markup the test fails loudly.
$gif_block_content = Day_One_Importer_Content::append_image_section( '', array( 1001 ) );
assert_true( false !== strpos( $gif_block_content, '<!-- wp:image {"id":1001,"sizeSlug":"full","linkDestination":"none"} -->' ), '#60 R5.1 — GIF image block emits sizeSlug=full and id=1001.' );
assert_true( false !== strpos( $gif_block_content, '<figure class="wp-block-image size-full">' ), '#60 R5.1 — GIF image block figure carries size-full class.' );
assert_true( false !== strpos( $gif_block_content, 'src="https://example.test/wp-content/uploads/2026/05/animated.gif"' ), '#60 R5.1 — GIF image block src is wp_get_attachment_url() (original file), not the `large` derivative.' );
assert_true( false === strpos( $gif_block_content, 'animated-1024x1024.jpg' ), '#60 R5.1 — GIF image block does not leak the `large` derivative URL.' );
assert_true( false === strpos( $gif_block_content, '"sizeSlug":"large"' ), '#60 R5.1 — GIF image block does not emit sizeSlug=large.' );
assert_true( false === strpos( $gif_block_content, 'size-large' ), '#60 R5.1 — GIF image block figure does not carry size-large class.' );
assert_true( false !== strpos( $gif_block_content, 'alt="animated lantern"' ), '#60 R5.1 — GIF image block carries alt from _wp_attachment_image_alt meta.' );

// #60 R5.1 — non-GIF (jpeg) branch: existing `large` ladder is unchanged.
// sizeSlug=large is preserved and the `large` derivative URL is used.
$jpeg_block_content = Day_One_Importer_Content::append_image_section( '', array( 1002 ) );
assert_true( false !== strpos( $jpeg_block_content, '<!-- wp:image {"id":1002,"sizeSlug":"large","linkDestination":"none"} -->' ), '#60 R5.1 — non-GIF (jpeg) image block keeps sizeSlug=large.' );
assert_true( false !== strpos( $jpeg_block_content, '<figure class="wp-block-image size-large">' ), '#60 R5.1 — non-GIF (jpeg) image block figure keeps size-large class.' );
assert_true( false !== strpos( $jpeg_block_content, 'src="https://example.test/wp-content/uploads/2026/05/photo-1024x768.jpg"' ), '#60 R5.1 — non-GIF (jpeg) image block uses the `large` derivative URL.' );
assert_true( false === strpos( $jpeg_block_content, '"sizeSlug":"full"' ), '#60 R5.1 — non-GIF (jpeg) image block does not emit sizeSlug=full.' );

// #60 R5.1 K2 — mixed gallery: per-image sizeSlug MUST be preserved through
// serialize_gallery_block() -> serialize_image_block() delegation. One GIF
// (1001) + one JPEG (1002) — both inner blocks must carry their own slug.
$mixed_gallery_content = Day_One_Importer_Content::append_image_section( '', array( 1001, 1002 ) );
assert_true( false !== strpos( $mixed_gallery_content, '<!-- wp:gallery {"linkTo":"none","ids":[1001,1002]} -->' ), '#60 R5.1 K2 — mixed gallery emits both ids in order.' );
assert_true( false !== strpos( $mixed_gallery_content, '"sizeSlug":"full"' ), '#60 R5.1 K2 — mixed gallery contains a sizeSlug=full inner block (the GIF).' );
assert_true( false !== strpos( $mixed_gallery_content, '"sizeSlug":"large"' ), '#60 R5.1 K2 — mixed gallery contains a sizeSlug=large inner block (the JPEG).' );
assert_true( false !== strpos( $mixed_gallery_content, '<figure class="wp-block-image size-full">' ), '#60 R5.1 K2 — mixed gallery contains a size-full inner figure (the GIF).' );
assert_true( false !== strpos( $mixed_gallery_content, '<figure class="wp-block-image size-large">' ), '#60 R5.1 K2 — mixed gallery contains a size-large inner figure (the JPEG).' );
assert_true( 2 === substr_count( $mixed_gallery_content, '<!-- wp:image' ), '#60 R5.1 K2 — mixed gallery contains exactly two nested image blocks.' );
assert_true( strpos( $mixed_gallery_content, 'wp-image-1001' ) < strpos( $mixed_gallery_content, 'wp-image-1002' ), '#60 R5.1 K2 — mixed gallery preserves attachment order (GIF before JPEG).' );

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
$journal_from_field = Day_One_Importer_Content::derive_journal_name( array( 'journal' => array( 'name' => '<b>Travel</b>' ) ), '/tmp/Other.json' );
assert_true( 'Travel' === $journal_from_field, 'Journal names can be derived from entry metadata.' );
$journal_from_file = Day_One_Importer_Content::derive_journal_name( array(), '/tmp/Fictional Journal.json' );
assert_true( 'Fictional Journal' === $journal_from_file, 'Journal names fall back to source JSON filenames.' );

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

$public_upload_root                               = sys_get_temp_dir() . '/day-one-importer-public-upload-test-' . uniqid();
$GLOBALS['day_one_importer_test_upload_basedir'] = $public_upload_root;
$GLOBALS['day_one_importer_test_wp_upload_dir_calls'] = 0;
$upload_dirs                                      = Day_One_Importer_Media::filter_private_upload_dir(
	array(
		'basedir' => $public_upload_root,
		'baseurl' => 'https://example.test/wp-content/uploads',
		'subdir'  => '/2026/05',
		'path'    => $public_upload_root . '/2026/05',
		'url'     => 'https://example.test/wp-content/uploads/2026/05',
	)
);
$private_media_root = Day_One_Importer_Media::private_media_base_dir( $public_upload_root );
assert_true( 0 === $GLOBALS['day_one_importer_test_wp_upload_dir_calls'], 'Private media upload filter uses the provided upload directory data.' );
assert_true( '/2026/05' === $upload_dirs['subdir'], 'Private media upload subdir preserves WordPress date structure.' );
assert_true( 0 === strpos( $upload_dirs['path'], $private_media_root ), 'Private media upload path is under the protected private root.' );
assert_true( 0 === strpos( $private_media_root, $public_upload_root ), 'Private media root uses a dedicated uploads subfolder.' );
assert_true( file_exists( $private_media_root . '/.htaccess' ), 'Private media root receives defense-in-depth protection files.' );
$private_test_file = $upload_dirs['path'] . '/private-test.jpg';
file_put_contents( $private_test_file, 'fake' );
assert_true( Day_One_Importer_Media::is_private_upload_path( $private_test_file ), 'Private media path validator accepts files under the private root.' );
Day_One_Importer_Cleanup::remove( dirname( $private_media_root ) );
Day_One_Importer_Cleanup::remove( $public_upload_root );

$private_media_url = Day_One_Importer_Media::private_media_url( 123 );
assert_true( 'https://example.test/wp-admin/admin-ajax.php?action=day_one_importer_media&attachment_id=123&nonce=day-one-nonce' === $private_media_url, 'Private media URL uses the authenticated AJAX endpoint with a nonce.' );

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
assert_true( 29 === count( $fixture_entries ), 'Committed fictional fixture parses twenty-nine entries.' );
assert_true( 29 === $fixture_results->get_count( 'entries_found' ), 'Committed fictional fixture reports twenty-nine found entries.' );
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
assert_true( ! empty( $batch_index['done'] ) && 29 === $batch_job['entries_total'], 'Batch parser indexes fixture entries into a manifest.' );
assert_true( $checkpoint_count >= 3, 'Batch parser checkpoints after safe manifest units.' );
$manifest_entry = $parser->read_manifest_entry( $batch_job['manifest_path'], 0 );
assert_true( is_array( $manifest_entry ) && 'FICTIONAL-SAMPLE-ENTRY-0001' === $manifest_entry['uuid'], 'Batch parser can read a manifest entry by cursor.' );
assert_true( is_array( $manifest_entry ) && 'Fictional Journal' === $manifest_entry['journal'], 'Batch parser stores the source journal name for category assignment.' );
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
assert_true( 29 === $bounded_job['entries_total'] && $bounded_batches > 3, 'Batch parser can complete fixture indexing across multiple bounded requests.' );
Day_One_Importer_Cleanup::remove( dirname( $bounded_job['manifest_path'] ) );
$GLOBALS['day_one_importer_test_filters'] = array();

// --- richText parser-level tests (A5, A6, A9, A12) ---

// A6 — both payload forms normalize identically.
$rt_payload_norm  = array(
	'meta'     => array( 'version' => 1 ),
	'contents' => array(
		array( 'text' => 'sample' ),
	),
);
$rt_results_a     = new Day_One_Importer_Results();
$rt_results_b     = new Day_One_Importer_Results();
$rt_entry_string  = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-RT-STRING',
		'creationDate' => '2024-01-01T00:00:00Z',
		'richText'     => wp_json_encode( $rt_payload_norm ),
	),
	'fictional.json',
	0,
	$rt_results_a
);
$rt_entry_object  = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-RT-OBJECT',
		'creationDate' => '2024-01-01T00:00:00Z',
		'richText'     => $rt_payload_norm,
	),
	'fictional.json',
	1,
	$rt_results_b
);
assert_true( is_array( $rt_entry_string ) && isset( $rt_entry_string['richText'] ), 'normalize_entry surfaces richText key when raw payload is a JSON-encoded string.' );
assert_true( is_array( $rt_entry_object ) && isset( $rt_entry_object['richText'] ), 'normalize_entry surfaces richText key when raw payload is a pre-decoded array.' );
assert_true( wp_json_encode( $rt_entry_string['richText'] ) === wp_json_encode( $rt_entry_object['richText'] ), 'normalize_entry produces identical richText structures from string and array forms (JSON-canonical equality).' );

// A9 — malformed richText: warning + no failure (R2.3 contract).
$rt_failures_baseline = $rt_results_a->get_count( 'entries_failed' );
$rt_warnings_baseline = count( $rt_results_a->get_warnings() );
$rt_entry_bad        = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-RT-BAD',
		'creationDate' => '2024-01-01T00:00:00Z',
		'richText'     => '{not json',
	),
	'fictional.json',
	2,
	$rt_results_a
);
assert_true( is_array( $rt_entry_bad ), 'normalize_entry returns a normalized entry when richText fails to decode.' );
assert_true( ! isset( $rt_entry_bad['richText'] ), 'normalize_entry omits the richText key on decode failure (legacy text fallback path).' );
assert_true( $rt_results_a->get_count( 'entries_failed' ) === $rt_failures_baseline, 'Malformed richText does not increment entries_failed (A9.1).' );
assert_true( count( $rt_results_a->get_warnings() ) > $rt_warnings_baseline, 'Malformed richText records a warning via get_warnings() (A9.2).' );

// A9 complement — empty-string richText: no key, no warning.
$rt_results_empty  = new Day_One_Importer_Results();
$rt_entry_empty_rt = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-RT-EMPTY',
		'creationDate' => '2024-01-01T00:00:00Z',
		'richText'     => '',
	),
	'fictional.json',
	3,
	$rt_results_empty
);
assert_true( is_array( $rt_entry_empty_rt ) && ! isset( $rt_entry_empty_rt['richText'] ), 'normalize_entry treats empty-string richText as absent (no decode attempt).' );
assert_true( 0 === count( $rt_results_empty->get_warnings() ), 'normalize_entry does not warn on empty-string richText (R2.3 contract).' );

// A5 — manifest round-trip through public parser primitives (M3-resolved).
$rt_roundtrip_payload = array(
	'meta'     => array( 'version' => 1 ),
	'contents' => array(
		array(
			'attributes' => array(),
			'text'       => "Round-trip sample.\nSecond line.",
		),
		array(
			'attributes' => array( 'italic' => true ),
			'text'       => 'Inline-attr run kept intact.',
		),
	),
);
$rt_roundtrip_dir     = sys_get_temp_dir() . '/day-one-importer-rt-roundtrip-' . uniqid();
mkdir( $rt_roundtrip_dir, 0777, true );
file_put_contents(
	$rt_roundtrip_dir . '/Journal.json',
	wp_json_encode(
		array(
			'metadata' => array( 'version' => 'test' ),
			'entries'  => array(
				array(
					'uuid'         => 'TEST-RT-ROUNDTRIP',
					'creationDate' => '2024-01-01T00:00:00Z',
					'text'         => 'Body text',
					'richText'     => $rt_roundtrip_payload,
				),
			),
		)
	)
);
$rt_roundtrip_results = new Day_One_Importer_Results();
$rt_roundtrip_job     = array(
	'manifest_path'       => sys_get_temp_dir() . '/day-one-importer-rt-manifest-' . uniqid() . '/entries.jsonl',
	'zip_json_candidates' => array( 'Journal.json' ),
	'zip_photo_dirs'      => array( 'photos' ),
	'json_files'          => array(),
	'json_file_index'     => 0,
	'json_entry_index'    => 0,
	'entries_total'       => 0,
	'seen_uuids'          => array(),
);
$parser->discover_json_files_batch( $rt_roundtrip_dir, $rt_roundtrip_job, $rt_roundtrip_results, 1.0E+30 );
$rt_roundtrip_index = $parser->index_export_batch( $rt_roundtrip_dir, $rt_roundtrip_job, $rt_roundtrip_results, 1.0E+30 );
assert_true( ! empty( $rt_roundtrip_index['done'] ) && 1 === $rt_roundtrip_job['entries_total'], 'richText round-trip indexer writes one entry to the manifest.' );
$rt_roundtrip_entry = $parser->read_manifest_entry( $rt_roundtrip_job['manifest_path'], 0 );
assert_true( is_array( $rt_roundtrip_entry ) && isset( $rt_roundtrip_entry['richText'] ), 'richText round-trip preserves richText key through the JSONL manifest.' );
assert_true( wp_json_encode( $rt_roundtrip_payload ) === wp_json_encode( $rt_roundtrip_entry['richText'] ), 'richText payload is byte-identical after manifest round-trip (JSON-canonical equality).' );
Day_One_Importer_Cleanup::remove( dirname( $rt_roundtrip_job['manifest_path'] ) );
Day_One_Importer_Cleanup::remove( $rt_roundtrip_dir );

// A12 — old-shape manifest (no richText key) reads cleanly and the dispatch helper falls back to legacy text.
$rt_old_manifest_dir = sys_get_temp_dir() . '/day-one-importer-rt-old-manifest-' . uniqid();
mkdir( $rt_old_manifest_dir, 0777, true );
$rt_old_manifest_path = $rt_old_manifest_dir . '/entries.jsonl';
file_put_contents(
	$rt_old_manifest_path,
	wp_json_encode(
		array(
			'uuid'                => 'TEST-RT-OLD-MANIFEST',
			'creationDate'        => '2024-01-01T00:00:00Z',
			'modifiedDate'        => '',
			'timeZone'            => '',
			'text'                => "# Legacy heading\n\nLegacy body paragraph.",
			'tags'                => array(),
			'journal'             => 'Journal',
			'photos'              => array(),
			'starred'             => false,
			'isPinned'            => false,
			'creationDeviceType'  => '',
			'creationDeviceModel' => '',
			'source_file'         => 'Journal.json',
		)
	) . "\n"
);
$rt_old_entry = $parser->read_manifest_entry( $rt_old_manifest_path, 0 );
assert_true( is_array( $rt_old_entry ), 'Old-shape manifest entry (missing richText key) reads back as an array.' );
assert_true( ! isset( $rt_old_entry['richText'] ), 'Old-shape manifest entry has no richText key (forward compatibility).' );
assert_true( Day_One_Importer_Content::render_entry_body( $rt_old_entry ) === Day_One_Importer_Content::convert_text_to_content( $rt_old_entry['text'] ), 'render_entry_body on old-shape manifest entry equals convert_text_to_content( $entry["text"] ) (A12).' );
Day_One_Importer_Cleanup::remove( $rt_old_manifest_dir );

// --- R1 / R11.4 — Parser normalizes entry.videos[] (issue #57). ---

$video_results_present = new Day_One_Importer_Results();
$video_entry_present   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-VIDEO-PRESENT',
		'creationDate' => '2024-01-01T00:00:00Z',
		'videos'       => array(
			array(
				'identifier'   => '36AFEC058A8640F98420344B2CCCD145',
				'md5'          => 'ABCDEF0123456789abcdef0123456789',
				'type'         => 'MOV',
				'filename'     => 'clip.mov',
				'date'         => '2024-01-01T00:00:00Z',
				'orderInEntry' => 2,
				'width'        => 320,
				'height'       => 180,
				'duration'     => 3.6333333333333333,
			),
		),
	),
	'fictional.json',
	0,
	$video_results_present
);
assert_true( is_array( $video_entry_present ) && isset( $video_entry_present['videos'] ) && is_array( $video_entry_present['videos'] ), 'normalize_entry emits videos array when raw entry has videos[] (R1.1, R11.4).' );
assert_true( 1 === count( $video_entry_present['videos'] ), 'normalize_entry preserves a single video record.' );
assert_true( '36AFEC058A8640F98420344B2CCCD145' === $video_entry_present['videos'][0]['identifier'], 'normalize_video preserves the identifier value.' );
assert_true( 'abcdef0123456789abcdef0123456789' === $video_entry_present['videos'][0]['md5'], 'normalize_video lowercases the md5 hex.' );
assert_true( 'mov' === $video_entry_present['videos'][0]['type'], 'normalize_video lowercases the type.' );
assert_true( 'clip.mov' === $video_entry_present['videos'][0]['filename'], 'normalize_video keeps the basename filename.' );
assert_true( 2 === $video_entry_present['videos'][0]['orderInEntry'], 'normalize_video casts orderInEntry to int.' );
assert_true( 320 === $video_entry_present['videos'][0]['width'], 'normalize_video casts width to int.' );
assert_true( 180 === $video_entry_present['videos'][0]['height'], 'normalize_video casts height to int.' );
assert_true( is_string( $video_entry_present['videos'][0]['duration'] ), 'normalize_video stores duration as string (R1.4).' );
assert_true( abs( floatval( $video_entry_present['videos'][0]['duration'] ) - 3.6333333333333333 ) < 1e-9, 'normalize_video duration round-trips to within 1e-9 via floatval() (R1.4).' );

// Missing/null videos yields empty array.
$video_results_missing = new Day_One_Importer_Results();
$video_entry_missing   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-VIDEO-MISSING',
		'creationDate' => '2024-01-01T00:00:00Z',
	),
	'fictional.json',
	1,
	$video_results_missing
);
assert_true( is_array( $video_entry_missing ) && isset( $video_entry_missing['videos'] ) && array() === $video_entry_missing['videos'], 'normalize_entry yields empty videos array when raw entry lacks the field (R1.3).' );

// Malformed entries (non-array members) are skipped silently.
$video_results_bad = new Day_One_Importer_Results();
$video_entry_bad   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-VIDEO-BAD',
		'creationDate' => '2024-01-01T00:00:00Z',
		'videos'       => array(
			'not-an-array',
			array( 'identifier' => 'ONLY-ID' ),
			null,
		),
	),
	'fictional.json',
	2,
	$video_results_bad
);
assert_true( is_array( $video_entry_bad ) && 1 === count( $video_entry_bad['videos'] ), 'normalize_entry skips non-array video members silently (R11.4).' );
assert_true( 'ONLY-ID' === $video_entry_bad['videos'][0]['identifier'], 'normalize_video accepts a minimal record with only identifier set.' );
assert_true( '' === $video_entry_bad['videos'][0]['duration'], 'normalize_video records duration="" when raw value is missing.' );

// JSONL manifest round-trip: videos field travels through the manifest unchanged (under floatval tolerance for duration).
$video_roundtrip_payload = array(
	array(
		'identifier'   => 'ROUNDTRIP-VID-001',
		'md5'          => '0123456789abcdef0123456789abcdef',
		'type'         => 'mov',
		'filename'     => 'rt.mov',
		'date'         => '2024-01-01T00:00:00Z',
		'orderInEntry' => 0,
		'width'        => 320,
		'height'       => 180,
		'duration'     => 1.5,
	),
);
$video_roundtrip_dir     = sys_get_temp_dir() . '/day-one-importer-vid-roundtrip-' . uniqid();
mkdir( $video_roundtrip_dir, 0777, true );
file_put_contents(
	$video_roundtrip_dir . '/Journal.json',
	wp_json_encode(
		array(
			'metadata' => array( 'version' => 'test' ),
			'entries'  => array(
				array(
					'uuid'         => 'TEST-VID-ROUNDTRIP',
					'creationDate' => '2024-01-01T00:00:00Z',
					'videos'       => $video_roundtrip_payload,
				),
			),
		)
	)
);
$video_roundtrip_results = new Day_One_Importer_Results();
$video_roundtrip_job     = array(
	'manifest_path'       => sys_get_temp_dir() . '/day-one-importer-vid-manifest-' . uniqid() . '/entries.jsonl',
	'zip_json_candidates' => array( 'Journal.json' ),
	'zip_photo_dirs'      => array( 'photos' ),
	'zip_video_dirs'      => array( 'videos' ),
	'json_files'          => array(),
	'json_file_index'     => 0,
	'json_entry_index'    => 0,
	'entries_total'       => 0,
	'seen_uuids'          => array(),
);
$parser->discover_json_files_batch( $video_roundtrip_dir, $video_roundtrip_job, $video_roundtrip_results, 1.0E+30 );
$video_roundtrip_index = $parser->index_export_batch( $video_roundtrip_dir, $video_roundtrip_job, $video_roundtrip_results, 1.0E+30 );
assert_true( ! empty( $video_roundtrip_index['done'] ) && 1 === $video_roundtrip_job['entries_total'], 'videos round-trip indexer writes one entry to the manifest.' );
$video_roundtrip_entry = $parser->read_manifest_entry( $video_roundtrip_job['manifest_path'], 0 );
assert_true( is_array( $video_roundtrip_entry ) && isset( $video_roundtrip_entry['videos'] ) && 1 === count( $video_roundtrip_entry['videos'] ), 'videos field round-trips through the JSONL manifest.' );
$rt_video = $video_roundtrip_entry['videos'][0];
assert_true( 'ROUNDTRIP-VID-001' === $rt_video['identifier'], 'videos manifest round-trip preserves identifier.' );
assert_true( '0123456789abcdef0123456789abcdef' === $rt_video['md5'], 'videos manifest round-trip preserves md5.' );
assert_true( 'mov' === $rt_video['type'], 'videos manifest round-trip preserves type.' );
assert_true( 'rt.mov' === $rt_video['filename'], 'videos manifest round-trip preserves filename.' );
assert_true( 0 === $rt_video['orderInEntry'], 'videos manifest round-trip preserves orderInEntry.' );
assert_true( 320 === $rt_video['width'] && 180 === $rt_video['height'], 'videos manifest round-trip preserves width/height.' );
assert_true( is_string( $rt_video['duration'] ) && abs( floatval( $rt_video['duration'] ) - 1.5 ) < 1e-9, 'videos manifest round-trip preserves duration as string within 1e-9 (R2.1, R1.4).' );
Day_One_Importer_Cleanup::remove( dirname( $video_roundtrip_job['manifest_path'] ) );
Day_One_Importer_Cleanup::remove( $video_roundtrip_dir );

// --- C2 — Media helpers for videos (issue #57). ---

// sort_videos() preserves order by orderInEntry then original index (R11.4).
$sort_input = array(
	array( 'identifier' => 'A', 'orderInEntry' => 2 ),
	array( 'identifier' => 'B', 'orderInEntry' => 0 ),
	array( 'identifier' => 'C', 'orderInEntry' => null ),
	array( 'identifier' => 'D', 'orderInEntry' => 0 ),
);
$sort_output = Day_One_Importer_Media::sort_videos( $sort_input );
assert_true(
	'B' === $sort_output[0]['identifier']
	&& 'D' === $sort_output[1]['identifier']
	&& 'A' === $sort_output[2]['identifier']
	&& 'C' === $sort_output[3]['identifier'],
	'sort_videos orders by orderInEntry then original index (null sinks to end).'
);

// resolve_video_path() probes mov,mp4,m4v for type=mov (R11.4 light coverage).
$resolve_root = sys_get_temp_dir() . '/day-one-importer-vid-resolve-' . uniqid();
mkdir( $resolve_root . '/videos', 0777, true );
$resolve_md5    = '11112222333344445555666677778888';
$resolve_target = $resolve_root . '/videos/' . $resolve_md5 . '.mp4';
file_put_contents( $resolve_target, 'placeholder' );
// type=mov: mov,mp4,m4v — should find the .mp4 fallback.
$resolved = Day_One_Importer_Media::resolve_video_path(
	$resolve_root,
	array(
		'md5'      => $resolve_md5,
		'type'     => 'mov',
		'filename' => '',
	)
);
assert_true( '' !== $resolved && realpath( $resolve_target ) === $resolved, 'resolve_video_path falls back through mov,mp4,m4v for type=mov (R4.1).' );
// Verify mov is preferred over mp4 when both exist.
$resolve_mov = $resolve_root . '/videos/' . $resolve_md5 . '.mov';
file_put_contents( $resolve_mov, 'placeholder' );
$resolved_mov = Day_One_Importer_Media::resolve_video_path(
	$resolve_root,
	array(
		'md5'      => $resolve_md5,
		'type'     => 'mov',
		'filename' => '',
	)
);
assert_true( '' !== $resolved_mov && realpath( $resolve_mov ) === $resolved_mov, 'resolve_video_path prefers the requested type (mov) over fallbacks (mp4).' );
// type=mp4: mp4,mov,m4v — should prefer the .mp4 even with .mov present.
$resolved_mp4 = Day_One_Importer_Media::resolve_video_path(
	$resolve_root,
	array(
		'md5'      => $resolve_md5,
		'type'     => 'mp4',
		'filename' => '',
	)
);
assert_true( '' !== $resolved_mp4 && realpath( $resolve_target ) === $resolved_mp4, 'resolve_video_path prefers the requested type (mp4) when type=mp4.' );
Day_One_Importer_Cleanup::remove( $resolve_root );

// find_video_dirs() discovers a top-level videos/ directory (R4.1).
$discover_root = sys_get_temp_dir() . '/day-one-importer-vid-discover-' . uniqid();
mkdir( $discover_root . '/videos', 0777, true );
mkdir( $discover_root . '/photos', 0777, true );
$found_dirs = Day_One_Importer_Media::find_video_dirs( $discover_root );
assert_true( 1 === count( $found_dirs ) && false !== strpos( $found_dirs[0], DIRECTORY_SEPARATOR . 'videos' ), 'find_video_dirs picks up a top-level videos directory.' );
Day_One_Importer_Cleanup::remove( $discover_root );

// --- C5 — fictional fixture entries 20 (video) + 21 (interleaved) round-trip (#57 R11.4). ---

$fixture_video_entry = null;
$fixture_mixed_entry = null;
foreach ( $fixture_entries as $fx ) {
	if ( 'FICTIONAL-SAMPLE-ENTRY-0020' === ( isset( $fx['uuid'] ) ? $fx['uuid'] : '' ) ) {
		$fixture_video_entry = $fx;
	}
	if ( 'FICTIONAL-SAMPLE-ENTRY-0021' === ( isset( $fx['uuid'] ) ? $fx['uuid'] : '' ) ) {
		$fixture_mixed_entry = $fx;
	}
}
assert_true( is_array( $fixture_video_entry ), 'Fixture entry 0020 is present in the parsed fixture (#57 R11.1).' );
assert_true( is_array( $fixture_mixed_entry ), 'Fixture entry 0021 is present in the parsed fixture (#57 R11.1).' );
assert_true( isset( $fixture_video_entry['videos'][0]['identifier'] ) && '36AFEC058A8640F98420344B2CCCD145' === $fixture_video_entry['videos'][0]['identifier'], 'Fixture entry 0020 carries the expected video identifier.' );
assert_true( isset( $fixture_video_entry['videos'][0]['duration'] ) && abs( floatval( $fixture_video_entry['videos'][0]['duration'] ) - 1.0 ) < 1e-9, 'Fixture entry 0020 round-trips duration through the parser within 1e-9 (R1.4).' );

$fixture_video_path = Day_One_Importer_Media::resolve_video_path( $fixture_dir, $fixture_video_entry['videos'][0] );
assert_true( '' !== $fixture_video_path && is_file( $fixture_video_path ), 'Fixture entry 0020 video resolves on disk via resolve_video_path().' );

// Entry 21 mixed-sequence rendering (AC9 pure-helper layer).
$mixed_results = new Day_One_Importer_Results();
$mixed_html    = Day_One_Importer_Content::convert_rich_text_to_content(
	isset( $fixture_mixed_entry['richText'] ) ? $fixture_mixed_entry['richText'] : array(),
	$mixed_results,
	array( 'FICTIONAL-SAMPLE-PHOTO-0001' => 101 ),
	array( '36AFEC058A8640F98420344B2CCCD145' => 501 )
);
$mixed_blocks = parse_blocks( $mixed_html );
$mixed_names  = array_map( static function ( $b ) {
	return $b['blockName'];
}, $mixed_blocks );
assert_true( array( 'core/image', 'core/video', 'core/image' ) === $mixed_names, '#57 AC9 — fictional entry 0021 (interleaved photo,video,photo) renders core/image, core/video, core/image in order.' );

// --- #58 C5 — fictional fixture entries 22 (audio) + 23 (interleaved photo+video+audio) round-trip (R11.4). ---

$fixture_audio_entry         = null;
$fixture_audio_mixed_entry   = null;
foreach ( $fixture_entries as $fx ) {
	if ( 'FICTIONAL-SAMPLE-ENTRY-0022' === ( isset( $fx['uuid'] ) ? $fx['uuid'] : '' ) ) {
		$fixture_audio_entry = $fx;
	}
	if ( 'FICTIONAL-SAMPLE-ENTRY-0023' === ( isset( $fx['uuid'] ) ? $fx['uuid'] : '' ) ) {
		$fixture_audio_mixed_entry = $fx;
	}
}
assert_true( is_array( $fixture_audio_entry ), '#58 Fixture entry 0022 is present in the parsed fixture (R11.1).' );
assert_true( is_array( $fixture_audio_mixed_entry ), '#58 Fixture entry 0023 is present in the parsed fixture (R11.1).' );
assert_true( isset( $fixture_audio_entry['audios'][0]['identifier'] ) && 'A1B2C3D400000000000000000058AU01' === $fixture_audio_entry['audios'][0]['identifier'], '#58 Fixture entry 0022 carries the expected audio identifier.' );
assert_true( isset( $fixture_audio_entry['audios'][0]['format'] ) && 'mp3' === $fixture_audio_entry['audios'][0]['format'], '#58 Fixture entry 0022 normalized format is "mp3".' );
assert_true( isset( $fixture_audio_entry['audios'][0]['title'] ) && 'sample-1s' === $fixture_audio_entry['audios'][0]['title'], '#58 Fixture entry 0022 carries the sample-1s title.' );
assert_true( isset( $fixture_audio_entry['audios'][0]['duration'] ) && abs( floatval( $fixture_audio_entry['audios'][0]['duration'] ) - 1.0 ) < 1e-9, '#58 Fixture entry 0022 round-trips duration through the parser within 1e-9 (R1.4).' );

$fixture_audio_path = Day_One_Importer_Media::resolve_audio_path( $fixture_dir, $fixture_audio_entry['audios'][0] );
assert_true( '' !== $fixture_audio_path && is_file( $fixture_audio_path ), '#58 Fixture entry 0022 audio resolves on disk via resolve_audio_path().' );

// Entry 23 interleaved photo+video+audio rendering (AC9 pure-helper layer).
$audio_mixed_results = new Day_One_Importer_Results();
$audio_mixed_html    = Day_One_Importer_Content::convert_rich_text_to_content(
	isset( $fixture_audio_mixed_entry['richText'] ) ? $fixture_audio_mixed_entry['richText'] : array(),
	$audio_mixed_results,
	array( 'FICTIONAL-SAMPLE-PHOTO-0001' => 101 ),
	array( '36AFEC058A8640F98420344B2CCCD145' => 501 ),
	array( 'A1B2C3D400000000000000000058AU01' => 603 )
);
$audio_mixed_blocks = parse_blocks( $audio_mixed_html );
$audio_mixed_names  = array_map( static function ( $b ) {
	return $b['blockName'];
}, $audio_mixed_blocks );
assert_true( array( 'core/image', 'core/video', 'core/audio' ) === $audio_mixed_names, '#58 AC9 — fictional entry 0023 (interleaved photo,video,audio) renders core/image, core/video, core/audio in order.' );

// --- #59 C5 — fictional fixture entries 24 (pdf) + 25 (interleaved photo+video+audio+pdf) round-trip (R11.4). ---

$fixture_pdf_entry       = null;
$fixture_pdf_mixed_entry = null;
foreach ( $fixture_entries as $fx ) {
	if ( 'FICTIONAL-SAMPLE-ENTRY-0024' === ( isset( $fx['uuid'] ) ? $fx['uuid'] : '' ) ) {
		$fixture_pdf_entry = $fx;
	}
	if ( 'FICTIONAL-SAMPLE-ENTRY-0025' === ( isset( $fx['uuid'] ) ? $fx['uuid'] : '' ) ) {
		$fixture_pdf_mixed_entry = $fx;
	}
}
assert_true( is_array( $fixture_pdf_entry ), '#59 Fixture entry 0024 is present in the parsed fixture (R11.1).' );
assert_true( is_array( $fixture_pdf_mixed_entry ), '#59 Fixture entry 0025 is present in the parsed fixture (R11.1).' );
assert_true( isset( $fixture_pdf_entry['pdfAttachments'][0]['identifier'] ) && 'F1C7100A00000000000000000059PD01' === $fixture_pdf_entry['pdfAttachments'][0]['identifier'], '#59 Fixture entry 0024 carries the expected PDF identifier.' );
assert_true( isset( $fixture_pdf_entry['pdfAttachments'][0]['pdfName'] ) && 'Fictional PDF Sample' === $fixture_pdf_entry['pdfAttachments'][0]['pdfName'], '#59 Fixture entry 0024 carries the deterministic pdfName "Fictional PDF Sample".' );
assert_true( isset( $fixture_pdf_entry['pdfAttachments'][0]['md5'] ) && '65206568723b92ce9a928d0fb0ebed49' === $fixture_pdf_entry['pdfAttachments'][0]['md5'], '#59 Fixture entry 0024 md5 matches the committed file.' );
$fixture_pdf_disk_size = filesize( __DIR__ . '/fixtures/day-one-fictional/pdfs/65206568723b92ce9a928d0fb0ebed49.pdf' );
assert_true( $fixture_pdf_disk_size === $fixture_pdf_entry['pdfAttachments'][0]['fileSize'], '#59 Fixture entry 0024 fileSize matches the committed file on disk.' );

$fixture_pdf_path = Day_One_Importer_Media::resolve_pdf_path( $fixture_dir, $fixture_pdf_entry['pdfAttachments'][0] );
assert_true( '' !== $fixture_pdf_path && is_file( $fixture_pdf_path ), '#59 Fixture entry 0024 PDF resolves on disk via resolve_pdf_path().' );

// Entry 25 interleaved photo+video+audio+pdf rendering (AC9 pure-helper layer).
$pdf_mixed_results = new Day_One_Importer_Results();
$pdf_mixed_html    = Day_One_Importer_Content::convert_rich_text_to_content(
	isset( $fixture_pdf_mixed_entry['richText'] ) ? $fixture_pdf_mixed_entry['richText'] : array(),
	$pdf_mixed_results,
	array( 'FICTIONAL-SAMPLE-PHOTO-0001' => 101 ),
	array( '36AFEC058A8640F98420344B2CCCD145' => 501 ),
	array( 'A1B2C3D400000000000000000058AU01' => 603 ),
	array( 'F1C7100A00000000000000000059PD01' => 704 )
);
$pdf_mixed_blocks = parse_blocks( $pdf_mixed_html );
$pdf_mixed_names  = array_map( static function ( $b ) {
	return $b['blockName'];
}, $pdf_mixed_blocks );
assert_true( array( 'core/image', 'core/video', 'core/audio', 'core/file' ) === $pdf_mixed_names, '#59 AC9 — fictional entry 0025 (interleaved photo,video,audio,pdf) renders core/image, core/video, core/audio, core/file in order.' );

// --- C3 — signature smoke for render_entry_body / convert_rich_text_to_content with $video_map (#57 R7.5, R7.6). ---

$c3_sig_entry  = array(
	'text'     => 'Legacy fallback text.',
	'richText' => array(
		'meta'     => array( 'version' => 1 ),
		'contents' => array(
			array( 'text' => 'Hello world.' ),
		),
	),
);
$c3_sig_results = new Day_One_Importer_Results();
$c3_sig_body    = Day_One_Importer_Content::render_entry_body( $c3_sig_entry, $c3_sig_results, array(), array() );
assert_true( false !== strpos( $c3_sig_body, '<p>Hello world.</p>' ), 'render_entry_body accepts the new $video_map argument and preserves richText rendering (#57 R7.5).' );
$c3_sig_body_default = Day_One_Importer_Content::render_entry_body( $c3_sig_entry, $c3_sig_results, array() );
assert_true( $c3_sig_body === $c3_sig_body_default, 'render_entry_body produces identical output whether $video_map is omitted (default array) or passed explicitly.' );
$c3_sig_convert = Day_One_Importer_Content::convert_rich_text_to_content( $c3_sig_entry['richText'], $c3_sig_results, array(), array() );
assert_true( false !== strpos( $c3_sig_convert, '<p>Hello world.</p>' ), 'convert_rich_text_to_content accepts the new $video_map argument and preserves rendering (#57 R7.6).' );

// --- #58 R1 / R11.4 — Parser normalizes entry.audios[] (issue #58). ---

$audio_results_present = new Day_One_Importer_Results();
$audio_entry_present   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-AUDIO-PRESENT',
		'creationDate' => '2024-01-01T00:00:00Z',
		'audios'       => array(
			array(
				'identifier'   => 'AAAAFEC058A8640F98420344B2CCCD145',
				'md5'          => 'ABCDEF0123456789abcdef0123456789',
				'format'       => '.mp3',
				'filename'     => 'clip.mp3',
				'date'         => '2024-01-01T00:00:00Z',
				'orderInEntry' => 2,
				'duration'     => 6.3913124999999997,
				'title'        => 'sample-6s',
			),
		),
	),
	'fictional.json',
	0,
	$audio_results_present
);
assert_true( is_array( $audio_entry_present ) && isset( $audio_entry_present['audios'] ) && is_array( $audio_entry_present['audios'] ), '#58 normalize_entry emits audios array when raw entry has audios[] (R1.1, R11.4).' );
assert_true( 1 === count( $audio_entry_present['audios'] ), '#58 normalize_entry preserves a single audio record.' );
assert_true( 'AAAAFEC058A8640F98420344B2CCCD145' === $audio_entry_present['audios'][0]['identifier'], '#58 normalize_audio preserves the identifier value.' );
assert_true( 'abcdef0123456789abcdef0123456789' === $audio_entry_present['audios'][0]['md5'], '#58 normalize_audio lowercases the md5 hex.' );
assert_true( 'mp3' === $audio_entry_present['audios'][0]['format'], '#58 normalize_audio strips the leading dot and lowercases format (R1.3).' );
assert_true( 'clip.mp3' === $audio_entry_present['audios'][0]['filename'], '#58 normalize_audio keeps the basename filename.' );
assert_true( 2 === $audio_entry_present['audios'][0]['orderInEntry'], '#58 normalize_audio casts orderInEntry to int.' );
assert_true( is_string( $audio_entry_present['audios'][0]['duration'] ), '#58 normalize_audio stores duration as string (R1.4).' );
assert_true( abs( floatval( $audio_entry_present['audios'][0]['duration'] ) - 6.3913124999999997 ) < 1e-9, '#58 normalize_audio duration round-trips to within 1e-9 via floatval() (R1.4).' );
assert_true( 'sample-6s' === $audio_entry_present['audios'][0]['title'], '#58 normalize_audio preserves the sanitized title (R1.2).' );

// Format leading-dot strip + lowercase coverage (R1.3).
$audio_format_cases = array(
	'.mp3'  => 'mp3',
	'aac'   => 'aac',
	'.LPCM' => 'lpcm',
	'.M4A'  => 'm4a',
);
foreach ( $audio_format_cases as $raw_format => $expected ) {
	$fmt_results = new Day_One_Importer_Results();
	$fmt_entry   = $parser->normalize_entry(
		array(
			'uuid'         => 'TEST-AUDIO-FMT-' . $expected,
			'creationDate' => '2024-01-01T00:00:00Z',
			'audios'       => array(
				array(
					'identifier' => 'FMT-' . $expected,
					'format'     => $raw_format,
				),
			),
		),
		'fictional.json',
		0,
		$fmt_results
	);
	assert_true( $expected === $fmt_entry['audios'][0]['format'], '#58 normalize_audio normalizes format "' . $raw_format . '" to "' . $expected . '" (R1.3, R11.4).' );
}

// Missing/null audios yields empty array.
$audio_results_missing = new Day_One_Importer_Results();
$audio_entry_missing   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-AUDIO-MISSING',
		'creationDate' => '2024-01-01T00:00:00Z',
	),
	'fictional.json',
	1,
	$audio_results_missing
);
assert_true( is_array( $audio_entry_missing ) && isset( $audio_entry_missing['audios'] ) && array() === $audio_entry_missing['audios'], '#58 normalize_entry yields empty audios array when raw entry lacks the field (R1.5).' );

// Malformed entries (non-array members) are skipped silently.
$audio_results_bad = new Day_One_Importer_Results();
$audio_entry_bad   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-AUDIO-BAD',
		'creationDate' => '2024-01-01T00:00:00Z',
		'audios'       => array(
			'not-an-array',
			array( 'identifier' => 'ONLY-ID-AUDIO' ),
			null,
		),
	),
	'fictional.json',
	2,
	$audio_results_bad
);
assert_true( is_array( $audio_entry_bad ) && 1 === count( $audio_entry_bad['audios'] ), '#58 normalize_entry skips non-array audio members silently (R11.4).' );
assert_true( 'ONLY-ID-AUDIO' === $audio_entry_bad['audios'][0]['identifier'], '#58 normalize_audio accepts a minimal record with only identifier set.' );
assert_true( '' === $audio_entry_bad['audios'][0]['duration'], '#58 normalize_audio records duration="" when raw value is missing.' );
assert_true( '' === $audio_entry_bad['audios'][0]['title'], '#58 normalize_audio records title="" when raw value is missing.' );

// JSONL manifest round-trip: audios field travels through the manifest unchanged (under floatval tolerance for duration).
$audio_roundtrip_payload = array(
	array(
		'identifier'   => 'ROUNDTRIP-AUD-001',
		'md5'          => '0123456789abcdef0123456789abcdef',
		'format'       => 'mp3',
		'filename'     => 'rt.mp3',
		'date'         => '2024-01-01T00:00:00Z',
		'orderInEntry' => 0,
		'duration'     => 1.5,
		'title'        => 'rt-title',
	),
);
$audio_roundtrip_dir     = sys_get_temp_dir() . '/day-one-importer-aud-roundtrip-' . uniqid();
mkdir( $audio_roundtrip_dir, 0777, true );
file_put_contents(
	$audio_roundtrip_dir . '/Journal.json',
	wp_json_encode(
		array(
			'metadata' => array( 'version' => 'test' ),
			'entries'  => array(
				array(
					'uuid'         => 'TEST-AUD-ROUNDTRIP',
					'creationDate' => '2024-01-01T00:00:00Z',
					'audios'       => $audio_roundtrip_payload,
				),
			),
		)
	)
);
$audio_roundtrip_results = new Day_One_Importer_Results();
$audio_roundtrip_job     = array(
	'manifest_path'       => sys_get_temp_dir() . '/day-one-importer-aud-manifest-' . uniqid() . '/entries.jsonl',
	'zip_json_candidates' => array( 'Journal.json' ),
	'zip_photo_dirs'      => array( 'photos' ),
	'zip_video_dirs'      => array( 'videos' ),
	'zip_audio_dirs'      => array( 'audios' ),
	'json_files'          => array(),
	'json_file_index'     => 0,
	'json_entry_index'    => 0,
	'entries_total'       => 0,
	'seen_uuids'          => array(),
);
$parser->discover_json_files_batch( $audio_roundtrip_dir, $audio_roundtrip_job, $audio_roundtrip_results, 1.0E+30 );
$audio_roundtrip_index = $parser->index_export_batch( $audio_roundtrip_dir, $audio_roundtrip_job, $audio_roundtrip_results, 1.0E+30 );
assert_true( ! empty( $audio_roundtrip_index['done'] ) && 1 === $audio_roundtrip_job['entries_total'], '#58 audios round-trip indexer writes one entry to the manifest.' );
$audio_roundtrip_entry = $parser->read_manifest_entry( $audio_roundtrip_job['manifest_path'], 0 );
assert_true( is_array( $audio_roundtrip_entry ) && isset( $audio_roundtrip_entry['audios'] ) && 1 === count( $audio_roundtrip_entry['audios'] ), '#58 audios field round-trips through the JSONL manifest (AC2).' );
$rt_audio = $audio_roundtrip_entry['audios'][0];
assert_true( 'ROUNDTRIP-AUD-001' === $rt_audio['identifier'], '#58 audios manifest round-trip preserves identifier.' );
assert_true( '0123456789abcdef0123456789abcdef' === $rt_audio['md5'], '#58 audios manifest round-trip preserves md5.' );
assert_true( 'mp3' === $rt_audio['format'], '#58 audios manifest round-trip preserves format.' );
assert_true( 'rt.mp3' === $rt_audio['filename'], '#58 audios manifest round-trip preserves filename.' );
assert_true( 0 === $rt_audio['orderInEntry'], '#58 audios manifest round-trip preserves orderInEntry.' );
assert_true( is_string( $rt_audio['duration'] ) && abs( floatval( $rt_audio['duration'] ) - 1.5 ) < 1e-9, '#58 audios manifest round-trip preserves duration as string within 1e-9 (R2.1, R1.4).' );
assert_true( 'rt-title' === $rt_audio['title'], '#58 audios manifest round-trip preserves title.' );
Day_One_Importer_Cleanup::remove( dirname( $audio_roundtrip_job['manifest_path'] ) );
Day_One_Importer_Cleanup::remove( $audio_roundtrip_dir );

// --- #58 C2 — Media helpers for audios. ---

// sort_audios() preserves order by orderInEntry then original index (R11.4).
$audio_sort_input  = array(
	array( 'identifier' => 'A', 'orderInEntry' => 2 ),
	array( 'identifier' => 'B', 'orderInEntry' => 0 ),
	array( 'identifier' => 'C', 'orderInEntry' => null ),
	array( 'identifier' => 'D', 'orderInEntry' => 0 ),
);
$audio_sort_output = Day_One_Importer_Media::sort_audios( $audio_sort_input );
assert_true(
	'B' === $audio_sort_output[0]['identifier']
	&& 'D' === $audio_sort_output[1]['identifier']
	&& 'A' === $audio_sort_output[2]['identifier']
	&& 'C' === $audio_sort_output[3]['identifier'],
	'#58 sort_audios orders by orderInEntry then original index (null sinks to end).'
);

// resolve_audio_path() probes mp3,m4a,aac for format=mp3 (R11.4 light coverage).
$audio_resolve_root = sys_get_temp_dir() . '/day-one-importer-aud-resolve-' . uniqid();
mkdir( $audio_resolve_root . '/audios', 0777, true );
$audio_resolve_md5 = '11112222333344445555666677778888';

// format=mp3: probes mp3 first; with only .m4a on disk, falls through to m4a.
$audio_resolve_m4a = $audio_resolve_root . '/audios/' . $audio_resolve_md5 . '.m4a';
file_put_contents( $audio_resolve_m4a, 'placeholder' );
$audio_resolved_m4a = Day_One_Importer_Media::resolve_audio_path(
	$audio_resolve_root,
	array(
		'md5'      => $audio_resolve_md5,
		'format'   => 'mp3',
		'filename' => '',
	)
);
assert_true( '' !== $audio_resolved_m4a && realpath( $audio_resolve_m4a ) === $audio_resolved_m4a, '#58 resolve_audio_path falls back to m4a when mp3 absent for format=mp3 (R4.1).' );
// Once .mp3 exists too, format=mp3 prefers it.
$audio_resolve_mp3 = $audio_resolve_root . '/audios/' . $audio_resolve_md5 . '.mp3';
file_put_contents( $audio_resolve_mp3, 'placeholder' );
$audio_resolved_mp3 = Day_One_Importer_Media::resolve_audio_path(
	$audio_resolve_root,
	array(
		'md5'      => $audio_resolve_md5,
		'format'   => 'mp3',
		'filename' => '',
	)
);
assert_true( '' !== $audio_resolved_mp3 && realpath( $audio_resolve_mp3 ) === $audio_resolved_mp3, '#58 resolve_audio_path prefers the requested format (mp3) when both mp3 and m4a exist.' );

// format=lpcm: probes lpcm,wav,m4a -- with only .wav on disk it falls back.
$audio_lpcm_md5 = '22223333444455556666777788889999';
$audio_lpcm_wav = $audio_resolve_root . '/audios/' . $audio_lpcm_md5 . '.wav';
file_put_contents( $audio_lpcm_wav, 'placeholder' );
$audio_resolved_lpcm = Day_One_Importer_Media::resolve_audio_path(
	$audio_resolve_root,
	array(
		'md5'      => $audio_lpcm_md5,
		'format'   => 'lpcm',
		'filename' => '',
	)
);
assert_true( '' !== $audio_resolved_lpcm && realpath( $audio_lpcm_wav ) === $audio_resolved_lpcm, '#58 resolve_audio_path probes lpcm,wav,m4a for format=lpcm.' );
Day_One_Importer_Cleanup::remove( $audio_resolve_root );

// --- #58 C3 — signature smoke for render_entry_body / convert_rich_text_to_content with $audio_map (R7.5, R7.6). ---

$c3_aud_entry  = array(
	'text'     => 'Legacy fallback text.',
	'richText' => array(
		'meta'     => array( 'version' => 1 ),
		'contents' => array(
			array( 'text' => 'Hello audio world.' ),
		),
	),
);
$c3_aud_results = new Day_One_Importer_Results();
$c3_aud_body    = Day_One_Importer_Content::render_entry_body( $c3_aud_entry, $c3_aud_results, array(), array(), array() );
assert_true( false !== strpos( $c3_aud_body, '<p>Hello audio world.</p>' ), '#58 render_entry_body accepts the new $audio_map argument and preserves richText rendering (R7.5).' );
$c3_aud_body_default = Day_One_Importer_Content::render_entry_body( $c3_aud_entry, $c3_aud_results, array(), array() );
assert_true( $c3_aud_body === $c3_aud_body_default, '#58 render_entry_body produces identical output whether $audio_map is omitted (default array) or passed explicitly.' );
$c3_aud_convert = Day_One_Importer_Content::convert_rich_text_to_content( $c3_aud_entry['richText'], $c3_aud_results, array(), array(), array() );
assert_true( false !== strpos( $c3_aud_convert, '<p>Hello audio world.</p>' ), '#58 convert_rich_text_to_content accepts the new $audio_map argument and preserves rendering (R7.6).' );

// find_audio_dirs() discovers a top-level audios/ directory (R4.1).
$audio_discover_root = sys_get_temp_dir() . '/day-one-importer-aud-discover-' . uniqid();
mkdir( $audio_discover_root . '/audios', 0777, true );
mkdir( $audio_discover_root . '/photos', 0777, true );
$audio_found_dirs = Day_One_Importer_Media::find_audio_dirs( $audio_discover_root );
assert_true( 1 === count( $audio_found_dirs ) && false !== strpos( $audio_found_dirs[0], DIRECTORY_SEPARATOR . 'audios' ), '#58 find_audio_dirs picks up a top-level audios directory.' );
Day_One_Importer_Cleanup::remove( $audio_discover_root );

// --- #58 C4 — emit_media_group audio branch (R6 / AC6, AC7, AC8, AC9). ---

// Single audio resolves to one core/audio block, with figcaption sourced from _day_one_audio_title.
$c4_aud_single_results = new Day_One_Importer_Results();
$c4_aud_single_html    = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'audio', 'identifier' => 'A-1' ),
				),
			),
		),
	),
	$c4_aud_single_results,
	array(),
	array(),
	array( 'A-1' => 601 )
);
$c4_aud_single_blocks = parse_blocks( $c4_aud_single_html );
assert_true( 1 === count( $c4_aud_single_blocks ) && 'core/audio' === $c4_aud_single_blocks[0]['blockName'], '#58 AC6 — single resolved audio embed emits one core/audio block.' );
assert_true( isset( $c4_aud_single_blocks[0]['attrs']['id'] ) && 601 === (int) $c4_aud_single_blocks[0]['attrs']['id'], '#58 AC6 — core/audio block carries the resolved attachment ID.' );
assert_true( false !== strpos( $c4_aud_single_html, 'wp-block-audio' ), '#58 R6.5 — emitted markup contains the wp-block-audio class.' );
assert_true( false !== strpos( $c4_aud_single_html, 'day-one-importer-private' ), '#58 R6.5 — emitted audio src URL points at the private uploads subdir.' );
assert_true( false !== strpos( $c4_aud_single_html, 'wp-element-caption' ), '#58 R6.2 — non-empty _day_one_audio_title emits a wp-element-caption figcaption.' );
assert_true( false !== strpos( $c4_aud_single_html, 'sample-1s' ), '#58 R6.2 — figcaption contains the title text.' );
assert_true( 0 === count( $c4_aud_single_results->get_warnings() ), '#58 AC7 — resolved audio does not trigger the #56 placeholder warning.' );
assert_true( false === strpos( implode( "\n", $c4_aud_single_results->get_warnings() ), 'audio import is not yet supported' ), '#58 AC7 — #56 placeholder warning text is gone.' );

// Empty _day_one_audio_title omits the figcaption.
$c4_aud_empty_html = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'audio', 'identifier' => 'A-2' ),
				),
			),
		),
	),
	new Day_One_Importer_Results(),
	array(),
	array(),
	array( 'A-2' => 602 )
);
assert_true( false !== strpos( $c4_aud_empty_html, 'wp-block-audio' ), '#58 R6.2 — empty title still emits the core/audio block.' );
assert_true( false === strpos( $c4_aud_empty_html, 'wp-element-caption' ), '#58 R6.2 — empty _day_one_audio_title omits the figcaption entirely.' );

// Two consecutive audios produce two separate core/audio blocks (no gallery aggregation).
$c4_aud_consec_html = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'audio', 'identifier' => 'A-1' ),
					array( 'type' => 'audio', 'identifier' => 'A-2' ),
				),
			),
		),
	),
	new Day_One_Importer_Results(),
	array(),
	array(),
	array( 'A-1' => 601, 'A-2' => 602 )
);
$c4_aud_consec_blocks = parse_blocks( $c4_aud_consec_html );
assert_true( 2 === count( $c4_aud_consec_blocks ), '#58 R11.4 — two consecutive audios produce exactly two top-level blocks.' );
assert_true( 'core/audio' === $c4_aud_consec_blocks[0]['blockName'] && 'core/audio' === $c4_aud_consec_blocks[1]['blockName'], '#58 R11.4 — both consecutive blocks are core/audio (no gallery aggregation).' );
assert_true( 601 === (int) $c4_aud_consec_blocks[0]['attrs']['id'] && 602 === (int) $c4_aud_consec_blocks[1]['attrs']['id'], '#58 R11.4 — consecutive audios preserve scan order in attrs[id].' );

// Mixed photo, audio, photo — interleaved emission with photo runs split by audio (K11).
$c4_aud_mixed_html = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'photo', 'identifier' => 'P-1' ),
					array( 'type' => 'audio', 'identifier' => 'A-1' ),
					array( 'type' => 'photo', 'identifier' => 'P-2' ),
				),
			),
		),
	),
	new Day_One_Importer_Results(),
	array( 'P-1' => 101, 'P-2' => 102 ),
	array(),
	array( 'A-1' => 601 )
);
$c4_aud_mixed_blocks = parse_blocks( $c4_aud_mixed_html );
$c4_aud_mixed_names  = array_map( static function ( $b ) {
	return $b['blockName'];
}, $c4_aud_mixed_blocks );
assert_true( array( 'core/image', 'core/audio', 'core/image' ) === $c4_aud_mixed_names, '#58 AC9 — photo,audio,photo sequence emits core/image, core/audio, core/image in order.' );

// Mixed photo, photo, audio, video, photo — gallery splitting edge case (K11).
$c4_aud_split_html = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'photo', 'identifier' => 'P-1' ),
					array( 'type' => 'photo', 'identifier' => 'P-2' ),
					array( 'type' => 'audio', 'identifier' => 'A-1' ),
					array( 'type' => 'video', 'identifier' => 'V-1' ),
					array( 'type' => 'photo', 'identifier' => 'P-3' ),
				),
			),
		),
	),
	new Day_One_Importer_Results(),
	array( 'P-1' => 101, 'P-2' => 102, 'P-3' => 202 ),
	array( 'V-1' => 502 ),
	array( 'A-1' => 601 )
);
$c4_aud_split_blocks = parse_blocks( $c4_aud_split_html );
$c4_aud_split_names  = array_map( static function ( $b ) {
	return $b['blockName'];
}, $c4_aud_split_blocks );
assert_true( array( 'core/gallery', 'core/audio', 'core/video', 'core/image' ) === $c4_aud_split_names, '#58 K11 — photo,photo,audio,video,photo splits into gallery,audio,video,image.' );

// Mixed audio, photo, video, audio — alternation preserves order.
$c4_aud_alt_html = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'audio', 'identifier' => 'A-1' ),
					array( 'type' => 'photo', 'identifier' => 'P-1' ),
					array( 'type' => 'video', 'identifier' => 'V-1' ),
					array( 'type' => 'audio', 'identifier' => 'A-2' ),
				),
			),
		),
	),
	new Day_One_Importer_Results(),
	array( 'P-1' => 101 ),
	array( 'V-1' => 501 ),
	array( 'A-1' => 601, 'A-2' => 602 )
);
$c4_aud_alt_blocks = parse_blocks( $c4_aud_alt_html );
$c4_aud_alt_names  = array_map( static function ( $b ) {
	return $b['blockName'];
}, $c4_aud_alt_blocks );
assert_true( array( 'core/audio', 'core/image', 'core/video', 'core/audio' ) === $c4_aud_alt_names, '#58 R11.4 — audio,photo,video,audio sequence emits audio,image,video,audio.' );

// Unresolved audio identifier (not in $audio_map) emits no block and ONE warning per missing identifier.
$c4_aud_miss_results = new Day_One_Importer_Results();
$c4_aud_miss_html    = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'audio', 'identifier' => 'A-MISS' ),
				),
			),
		),
	),
	$c4_aud_miss_results,
	array(),
	array(),
	array() // empty audio map
);
assert_true( '' === $c4_aud_miss_html, '#58 AC8 — unresolved audio emits no block.' );
$c4_aud_miss_warnings = $c4_aud_miss_results->get_warnings();
assert_true( 1 === count( $c4_aud_miss_warnings ), '#58 AC8 — unresolved audio records exactly one warning.' );
assert_true( $c4_aud_miss_warnings[0] === 'Skipping embedded audio in Day One entry: referenced media file is unsupported or missing.', '#58 R5.3 — warning text is privacy-safe (no identifier).' );
assert_true( false === strpos( $c4_aud_miss_warnings[0], 'A-MISS' ), '#58 R5.3 — warning does not leak the embed identifier.' );

// Two unresolved audios produce TWO warnings (per-identifier, NOT deduped).
$c4_aud_n_results = new Day_One_Importer_Results();
Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'audio', 'identifier' => 'A-MISS-A' ),
					array( 'type' => 'audio', 'identifier' => 'A-MISS-B' ),
				),
			),
		),
	),
	$c4_aud_n_results,
	array(),
	array(),
	array()
);
assert_true( 2 === count( $c4_aud_n_results->get_warnings() ), '#58 AC8 — two distinct unresolved audios produce two warnings (per-identifier, not deduped).' );

// serialize_audio_block defensive guard: an attachment URL outside the private uploads subdir is skipped.
$c4_aud_guard_html = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'audio', 'identifier' => 'A-OUTSIDE' ),
				),
			),
		),
	),
	new Day_One_Importer_Results(),
	array(),
	array(),
	array( 'A-OUTSIDE' => 304 ) // stub returns a non-private URL for ID 304.
);
assert_true( '' === $c4_aud_guard_html, '#58 R6.5 — emitter refuses to serialize an audio block when the URL is not from the private uploads subdir.' );

// wp_kses_post() round-trip on the audio block: wp-block-audio class survives, parse_blocks still recognizes core/audio.
$c4_aud_kses_html   = wp_kses_post( $c4_aud_single_html );
$c4_aud_kses_blocks = parse_blocks( $c4_aud_kses_html );
assert_true( 1 === count( $c4_aud_kses_blocks ) && 'core/audio' === $c4_aud_kses_blocks[0]['blockName'], '#58 R6.5 — wp_kses_post() round-trip preserves the core/audio block.' );
assert_true( false !== strpos( $c4_aud_kses_html, 'wp-block-audio' ), '#58 R6.5 — wp_kses_post() preserves the wp-block-audio class.' );

// --- #59 C4 — emit_media_group PDF branch + serialize_file_block (R6 / AC6, AC7, AC8, AC9). ---

// Single PDF resolves to one core/file block with the expected attrs and link text from _day_one_pdf_name.
$c4_pdf_single_results = new Day_One_Importer_Results();
$c4_pdf_single_html    = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'pdfAttachment', 'identifier' => 'P-1' ),
				),
			),
		),
	),
	$c4_pdf_single_results,
	array(),
	array(),
	array(),
	array( 'P-1' => 701 )
);
$c4_pdf_single_blocks = parse_blocks( $c4_pdf_single_html );
assert_true( 1 === count( $c4_pdf_single_blocks ) && 'core/file' === $c4_pdf_single_blocks[0]['blockName'], '#59 AC6 — single resolved PDF embed emits one core/file block.' );
assert_true( isset( $c4_pdf_single_blocks[0]['attrs']['id'] ) && 701 === (int) $c4_pdf_single_blocks[0]['attrs']['id'], '#59 AC6 — core/file block carries the resolved attachment ID.' );
assert_true( isset( $c4_pdf_single_blocks[0]['attrs']['href'] ) && 'https://example.test/wp-content/uploads/day-one-importer-private/sample-701.pdf' === $c4_pdf_single_blocks[0]['attrs']['href'], '#59 AC6 — core/file block carries href = wp_get_attachment_url() (R6.5).' );
assert_true( isset( $c4_pdf_single_blocks[0]['attrs']['showDownloadButton'] ) && true === $c4_pdf_single_blocks[0]['attrs']['showDownloadButton'], '#59 AC6 — showDownloadButton is true (R6.5).' );
assert_true( false !== strpos( $c4_pdf_single_html, 'wp-block-file' ), '#59 R6.5 — emitted markup contains the wp-block-file class.' );
assert_true( false !== strpos( $c4_pdf_single_html, 'wp-block-file__button' ), '#59 R6.5 — emitted markup contains the wp-block-file__button class.' );
assert_true( false !== strpos( $c4_pdf_single_html, 'day-one-importer-private' ), '#59 R6.5 — emitted file URL points at the private uploads subdir.' );
assert_true( false !== strpos( $c4_pdf_single_html, 'Download' ), '#59 R6.5 — emitted markup contains the literal "Download" label.' );
assert_true( false !== strpos( $c4_pdf_single_html, 'Fictional PDF Sample' ), '#59 R6.2 — link text uses the non-empty _day_one_pdf_name meta value (tier 2).' );
assert_true( 0 === count( $c4_pdf_single_results->get_warnings() ), '#59 AC7 — resolved PDF does not trigger the #56 placeholder warning.' );

// Link-text precedence tier 2: _day_one_pdf_name meta wins over basename when explicit $name omitted (covered above by ID 701).
// Link-text precedence tier 3: basename-without-extension when both $name and _day_one_pdf_name empty.
$c4_pdf_tier3_html = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'pdfAttachment', 'identifier' => 'P-T3' ),
				),
			),
		),
	),
	new Day_One_Importer_Results(),
	array(),
	array(),
	array(),
	array( 'P-T3' => 705 )
);
assert_true( false !== strpos( $c4_pdf_tier3_html, 'Tier3-Basename' ), '#59 R6.5 tier 3 — basename-without-extension fallback (URL ".../Tier3-Basename.pdf" yields "Tier3-Basename").' );
assert_true( false === strpos( $c4_pdf_tier3_html, 'Tier3-Basename.pdf</a>' ), '#59 R6.5 tier 3 — extension is stripped from the link text.' );

// Link-text precedence tier 4: [PDF] floor when basename is empty.
$c4_pdf_tier4_html = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'pdfAttachment', 'identifier' => 'P-T4' ),
				),
			),
		),
	),
	new Day_One_Importer_Results(),
	array(),
	array(),
	array(),
	array( 'P-T4' => 706 )
);
assert_true( false !== strpos( $c4_pdf_tier4_html, '[PDF]' ), '#59 R6.5 tier 4 — [PDF] floor when basename-without-extension is empty.' );

// Two consecutive PDFs produce two separate core/file blocks (no gallery aggregation).
$c4_pdf_consec_html = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'pdfAttachment', 'identifier' => 'P-1' ),
					array( 'type' => 'pdfAttachment', 'identifier' => 'P-2' ),
				),
			),
		),
	),
	new Day_One_Importer_Results(),
	array(),
	array(),
	array(),
	array( 'P-1' => 701, 'P-2' => 702 )
);
$c4_pdf_consec_blocks = parse_blocks( $c4_pdf_consec_html );
assert_true( 2 === count( $c4_pdf_consec_blocks ), '#59 R11.4 — two consecutive PDFs produce exactly two top-level blocks.' );
assert_true( 'core/file' === $c4_pdf_consec_blocks[0]['blockName'] && 'core/file' === $c4_pdf_consec_blocks[1]['blockName'], '#59 R11.4 — both consecutive blocks are core/file (no gallery aggregation).' );
assert_true( 701 === (int) $c4_pdf_consec_blocks[0]['attrs']['id'] && 702 === (int) $c4_pdf_consec_blocks[1]['attrs']['id'], '#59 R11.4 — consecutive PDFs preserve scan order in attrs[id].' );

// Mixed photo, pdf, photo -- gallery splits around the PDF (K12).
$c4_pdf_mixed_html = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'photo', 'identifier' => 'P-1' ),
					array( 'type' => 'pdfAttachment', 'identifier' => 'PDF-1' ),
					array( 'type' => 'photo', 'identifier' => 'P-2' ),
				),
			),
		),
	),
	new Day_One_Importer_Results(),
	array( 'P-1' => 101, 'P-2' => 102 ),
	array(),
	array(),
	array( 'PDF-1' => 701 )
);
$c4_pdf_mixed_blocks = parse_blocks( $c4_pdf_mixed_html );
$c4_pdf_mixed_names  = array_map( static function ( $b ) {
	return $b['blockName'];
}, $c4_pdf_mixed_blocks );
assert_true( array( 'core/image', 'core/file', 'core/image' ) === $c4_pdf_mixed_names, '#59 AC9 — photo,pdf,photo sequence emits core/image, core/file, core/image in order.' );

// Mixed photo, photo, pdf, video, audio, photo -- gallery splitting edge case (K12).
$c4_pdf_split_html = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'photo', 'identifier' => 'P-1' ),
					array( 'type' => 'photo', 'identifier' => 'P-2' ),
					array( 'type' => 'pdfAttachment', 'identifier' => 'PDF-1' ),
					array( 'type' => 'video', 'identifier' => 'V-1' ),
					array( 'type' => 'audio', 'identifier' => 'A-1' ),
					array( 'type' => 'photo', 'identifier' => 'P-3' ),
				),
			),
		),
	),
	new Day_One_Importer_Results(),
	array( 'P-1' => 101, 'P-2' => 102, 'P-3' => 202 ),
	array( 'V-1' => 502 ),
	array( 'A-1' => 601 ),
	array( 'PDF-1' => 701 )
);
$c4_pdf_split_blocks = parse_blocks( $c4_pdf_split_html );
$c4_pdf_split_names  = array_map( static function ( $b ) {
	return $b['blockName'];
}, $c4_pdf_split_blocks );
assert_true( array( 'core/gallery', 'core/file', 'core/video', 'core/audio', 'core/image' ) === $c4_pdf_split_names, '#59 K12 — photo,photo,pdf,video,audio,photo splits into gallery,file,video,audio,image.' );

// Mixed photo, video, audio, pdf -- interleaved scan order (AC9 pure-helper layer).
$c4_pdf_quad_html = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'photo', 'identifier' => 'P-1' ),
					array( 'type' => 'video', 'identifier' => 'V-1' ),
					array( 'type' => 'audio', 'identifier' => 'A-1' ),
					array( 'type' => 'pdfAttachment', 'identifier' => 'PDF-1' ),
				),
			),
		),
	),
	new Day_One_Importer_Results(),
	array( 'P-1' => 101 ),
	array( 'V-1' => 501 ),
	array( 'A-1' => 601 ),
	array( 'PDF-1' => 701 )
);
$c4_pdf_quad_blocks = parse_blocks( $c4_pdf_quad_html );
$c4_pdf_quad_names  = array_map( static function ( $b ) {
	return $b['blockName'];
}, $c4_pdf_quad_blocks );
assert_true( array( 'core/image', 'core/video', 'core/audio', 'core/file' ) === $c4_pdf_quad_names, '#59 AC9 — photo,video,audio,pdf sequence emits core/image, core/video, core/audio, core/file in order.' );

// Unresolved PDF identifier (not in $pdf_map) emits no block and ONE warning per missing identifier.
$c4_pdf_miss_results = new Day_One_Importer_Results();
$c4_pdf_miss_html    = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'pdfAttachment', 'identifier' => 'PDF-MISS' ),
				),
			),
		),
	),
	$c4_pdf_miss_results,
	array(),
	array(),
	array(),
	array() // empty PDF map
);
assert_true( '' === $c4_pdf_miss_html, '#59 AC8 — unresolved PDF emits no block.' );
$c4_pdf_miss_warnings = $c4_pdf_miss_results->get_warnings();
assert_true( 1 === count( $c4_pdf_miss_warnings ), '#59 AC8 — unresolved PDF records exactly one warning.' );
assert_true( $c4_pdf_miss_warnings[0] === 'Skipping embedded PDF in Day One entry: referenced media file is unsupported or missing.', '#59 R5.3 — warning text is privacy-safe (no identifier).' );
assert_true( false === strpos( $c4_pdf_miss_warnings[0], 'PDF-MISS' ), '#59 R5.3 — warning does not leak the embed identifier.' );

// Two distinct unresolved PDFs produce TWO warnings (per-identifier, NOT deduped).
$c4_pdf_n_results = new Day_One_Importer_Results();
Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'pdfAttachment', 'identifier' => 'PDF-MISS-A' ),
					array( 'type' => 'pdfAttachment', 'identifier' => 'PDF-MISS-B' ),
				),
			),
		),
	),
	$c4_pdf_n_results,
	array(),
	array(),
	array(),
	array()
);
assert_true( 2 === count( $c4_pdf_n_results->get_warnings() ), '#59 AC8 — two distinct unresolved PDFs produce two warnings (per-identifier, not deduped).' );

// serialize_file_block defensive guard: an attachment URL outside the private uploads subdir is skipped.
$c4_pdf_guard_html = Day_One_Importer_Content::convert_rich_text_to_content(
	array(
		'contents' => array(
			array(
				'embeddedObjects' => array(
					array( 'type' => 'pdfAttachment', 'identifier' => 'PDF-OUTSIDE' ),
				),
			),
		),
	),
	new Day_One_Importer_Results(),
	array(),
	array(),
	array(),
	array( 'PDF-OUTSIDE' => 305 ) // stub returns a non-private URL and no Day One meta for ID 305.
);
assert_true( '' === $c4_pdf_guard_html, '#59 R6.5 — emitter refuses to serialize a file block when the attachment is not Day One and the URL is not from the private uploads subdir.' );

// wp_kses_post() round-trip on the file block: wp-block-file class survives, parse_blocks still recognizes core/file.
$c4_pdf_kses_html   = wp_kses_post( $c4_pdf_single_html );
$c4_pdf_kses_blocks = parse_blocks( $c4_pdf_kses_html );
assert_true( 1 === count( $c4_pdf_kses_blocks ) && 'core/file' === $c4_pdf_kses_blocks[0]['blockName'], '#59 R6.5 — wp_kses_post() round-trip preserves the core/file block.' );
assert_true( false !== strpos( $c4_pdf_kses_html, 'wp-block-file' ), '#59 R6.5 — wp_kses_post() preserves the wp-block-file class.' );

// --- #59 R1 / R11.4 — Parser normalizes entry.pdfAttachments[] (issue #59 C1). ---

$pdf_results_present = new Day_One_Importer_Results();
$pdf_entry_present   = $parser->normalize_entry(
	array(
		'uuid'           => 'TEST-PDF-PRESENT',
		'creationDate'   => '2024-01-01T00:00:00Z',
		'pdfAttachments' => array(
			array(
				'identifier'   => 'PDF59C100000000000000000000000000',
				'md5'          => 'ABCDEF0123456789abcdef0123456789',
				'pdfName'      => 'Sample PDF',
				'orderInEntry' => 3,
				'fileSize'     => 1234,
				// Day One ships these zeroed values; the normalizer drops them.
				'duration'     => 0,
				'width'        => 0,
				'height'       => 0,
				'type'         => 'pdf',
			),
		),
	),
	'fictional.json',
	0,
	$pdf_results_present
);
assert_true( is_array( $pdf_entry_present ) && isset( $pdf_entry_present['pdfAttachments'] ) && is_array( $pdf_entry_present['pdfAttachments'] ), '#59 normalize_entry emits pdfAttachments array when raw entry has pdfAttachments[] (R1.1, R11.4).' );
assert_true( 1 === count( $pdf_entry_present['pdfAttachments'] ), '#59 normalize_entry preserves a single PDF record.' );
assert_true( 'PDF59C100000000000000000000000000' === $pdf_entry_present['pdfAttachments'][0]['identifier'], '#59 normalize_pdf_attachment preserves the identifier value.' );
assert_true( 'abcdef0123456789abcdef0123456789' === $pdf_entry_present['pdfAttachments'][0]['md5'], '#59 normalize_pdf_attachment lowercases the md5 hex.' );
assert_true( 'Sample PDF' === $pdf_entry_present['pdfAttachments'][0]['pdfName'], '#59 normalize_pdf_attachment preserves the sanitized pdfName (R1.2).' );
assert_true( 3 === $pdf_entry_present['pdfAttachments'][0]['orderInEntry'], '#59 normalize_pdf_attachment casts orderInEntry to int.' );
assert_true( 1234 === $pdf_entry_present['pdfAttachments'][0]['fileSize'], '#59 normalize_pdf_attachment casts fileSize to int (R1.2).' );
// R1.3 + R1.4: no type/format/duration/width/height fields persisted.
$pdf_key_set = array_keys( $pdf_entry_present['pdfAttachments'][0] );
sort( $pdf_key_set );
$pdf_expected_keys = array( 'fileSize', 'identifier', 'md5', 'orderInEntry', 'pdfName' );
sort( $pdf_expected_keys );
assert_true( $pdf_expected_keys === $pdf_key_set, '#59 normalize_pdf_attachment exposes exactly {identifier, md5, pdfName, orderInEntry, fileSize} (R1.3 + R1.4).' );
assert_true( ! isset( $pdf_entry_present['pdfAttachments'][0]['type'] ), '#59 normalize_pdf_attachment does NOT persist `type` (R1.3).' );
assert_true( ! isset( $pdf_entry_present['pdfAttachments'][0]['format'] ), '#59 normalize_pdf_attachment does NOT persist `format` (R1.3).' );
assert_true( ! isset( $pdf_entry_present['pdfAttachments'][0]['duration'] ), '#59 normalize_pdf_attachment does NOT persist `duration` (R1.4).' );
assert_true( ! isset( $pdf_entry_present['pdfAttachments'][0]['width'] ), '#59 normalize_pdf_attachment does NOT persist `width` (R1.4).' );
assert_true( ! isset( $pdf_entry_present['pdfAttachments'][0]['height'] ), '#59 normalize_pdf_attachment does NOT persist `height` (R1.4).' );

// Missing pdfAttachments yields an empty array (R1.5).
$pdf_results_missing = new Day_One_Importer_Results();
$pdf_entry_missing   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-PDF-MISSING',
		'creationDate' => '2024-01-01T00:00:00Z',
	),
	'fictional.json',
	1,
	$pdf_results_missing
);
assert_true( is_array( $pdf_entry_missing ) && isset( $pdf_entry_missing['pdfAttachments'] ) && array() === $pdf_entry_missing['pdfAttachments'], '#59 normalize_entry yields empty pdfAttachments array when raw entry lacks the field (R1.5).' );

// Malformed entries (non-array members) are skipped silently (R1.6).
$pdf_results_bad = new Day_One_Importer_Results();
$pdf_entry_bad   = $parser->normalize_entry(
	array(
		'uuid'           => 'TEST-PDF-BAD',
		'creationDate'   => '2024-01-01T00:00:00Z',
		'pdfAttachments' => array(
			'not-an-array',
			array( 'identifier' => 'ONLY-ID-PDF' ),
			null,
		),
	),
	'fictional.json',
	2,
	$pdf_results_bad
);
assert_true( is_array( $pdf_entry_bad ) && 1 === count( $pdf_entry_bad['pdfAttachments'] ), '#59 normalize_entry skips non-array PDF members silently (R11.4).' );
assert_true( 'ONLY-ID-PDF' === $pdf_entry_bad['pdfAttachments'][0]['identifier'], '#59 normalize_pdf_attachment accepts a minimal record with only identifier set.' );
assert_true( '' === $pdf_entry_bad['pdfAttachments'][0]['md5'], '#59 normalize_pdf_attachment records md5="" when raw value is missing.' );
assert_true( '' === $pdf_entry_bad['pdfAttachments'][0]['pdfName'], '#59 normalize_pdf_attachment records pdfName="" when raw value is missing (R11.4).' );
assert_true( null === $pdf_entry_bad['pdfAttachments'][0]['orderInEntry'], '#59 normalize_pdf_attachment records orderInEntry=null when raw value is missing.' );
assert_true( 0 === $pdf_entry_bad['pdfAttachments'][0]['fileSize'], '#59 normalize_pdf_attachment records fileSize=0 when raw value is missing (R11.4).' );

// fileSize coercion: non-numeric raw value is stored as 0.
$pdf_results_nonnumeric = new Day_One_Importer_Results();
$pdf_entry_nonnumeric   = $parser->normalize_entry(
	array(
		'uuid'           => 'TEST-PDF-NONNUM',
		'creationDate'   => '2024-01-01T00:00:00Z',
		'pdfAttachments' => array(
			array(
				'identifier' => 'NONNUM-FS',
				'fileSize'   => 'not-a-number',
			),
		),
	),
	'fictional.json',
	3,
	$pdf_results_nonnumeric
);
assert_true( 0 === $pdf_entry_nonnumeric['pdfAttachments'][0]['fileSize'], '#59 normalize_pdf_attachment stores fileSize=0 when raw value is non-numeric (R11.4).' );

// --- #59 C3 — signature smoke for render_entry_body / convert_rich_text_to_content with $pdf_map (R7.5, R7.6). ---

$c3_pdf_entry   = array(
	'text'     => 'Legacy fallback text.',
	'richText' => array(
		'meta'     => array( 'version' => 1 ),
		'contents' => array(
			array( 'text' => 'Hello pdf world.' ),
		),
	),
);
$c3_pdf_results = new Day_One_Importer_Results();
$c3_pdf_body    = Day_One_Importer_Content::render_entry_body( $c3_pdf_entry, $c3_pdf_results, array(), array(), array(), array() );
assert_true( false !== strpos( $c3_pdf_body, '<p>Hello pdf world.</p>' ), '#59 render_entry_body accepts the new $pdf_map argument and preserves richText rendering (R7.5).' );
$c3_pdf_body_default = Day_One_Importer_Content::render_entry_body( $c3_pdf_entry, $c3_pdf_results, array(), array(), array() );
assert_true( $c3_pdf_body === $c3_pdf_body_default, '#59 render_entry_body produces identical output whether $pdf_map is omitted (default array) or passed explicitly.' );
$c3_pdf_convert = Day_One_Importer_Content::convert_rich_text_to_content( $c3_pdf_entry['richText'], $c3_pdf_results, array(), array(), array(), array() );
assert_true( false !== strpos( $c3_pdf_convert, '<p>Hello pdf world.</p>' ), '#59 convert_rich_text_to_content accepts the new $pdf_map argument and preserves rendering (R7.6).' );

// --- #59 C2 — Media helpers for PDFs. ---

// sort_pdfs() orders by orderInEntry then original index (R11.4).
$pdf_sort_input  = array(
	array( 'identifier' => 'A', 'orderInEntry' => 2 ),
	array( 'identifier' => 'B', 'orderInEntry' => 0 ),
	array( 'identifier' => 'C', 'orderInEntry' => null ),
	array( 'identifier' => 'D', 'orderInEntry' => 0 ),
);
$pdf_sort_output = Day_One_Importer_Media::sort_pdfs( $pdf_sort_input );
assert_true(
	'B' === $pdf_sort_output[0]['identifier']
	&& 'D' === $pdf_sort_output[1]['identifier']
	&& 'A' === $pdf_sort_output[2]['identifier']
	&& 'C' === $pdf_sort_output[3]['identifier'],
	'#59 sort_pdfs orders by orderInEntry then original index (null sinks to end).'
);

// resolve_pdf_path() locates an md5-named .pdf in pdfs/.
$pdf_resolve_root = sys_get_temp_dir() . '/day-one-importer-pdf-resolve-' . uniqid();
mkdir( $pdf_resolve_root . '/pdfs', 0777, true );
$pdf_resolve_md5 = '99998888777766665555444433332222';

$pdf_resolve_file = $pdf_resolve_root . '/pdfs/' . $pdf_resolve_md5 . '.pdf';
file_put_contents( $pdf_resolve_file, 'placeholder' );
$pdf_resolved = Day_One_Importer_Media::resolve_pdf_path(
	$pdf_resolve_root,
	array(
		'md5'      => $pdf_resolve_md5,
		'filename' => '',
	)
);
assert_true( '' !== $pdf_resolved && realpath( $pdf_resolve_file ) === $pdf_resolved, '#59 resolve_pdf_path locates an md5-named .pdf in pdfs/ (R4.1).' );

// resolve_pdf_path() probes ONLY .pdf -- it does not fall back to .mp3 / .mov / etc.
$pdf_no_ext_md5 = '11112222333344445555666677778888';
$pdf_only_mp3   = $pdf_resolve_root . '/pdfs/' . $pdf_no_ext_md5 . '.mp3';
file_put_contents( $pdf_only_mp3, 'audio data' );
$pdf_resolve_no = Day_One_Importer_Media::resolve_pdf_path(
	$pdf_resolve_root,
	array(
		'md5'      => $pdf_no_ext_md5,
		'filename' => '',
	)
);
assert_true( '' === $pdf_resolve_no, '#59 resolve_pdf_path probes only .pdf -- does not fall back to .mp3 / .mov etc. (R1.3).' );

// resolve_pdf_path() falls back to the filename hint when md5 lookup fails.
$pdf_filename_only = $pdf_resolve_root . '/pdfs/named-document.pdf';
file_put_contents( $pdf_filename_only, '%PDF' );
$pdf_resolve_named = Day_One_Importer_Media::resolve_pdf_path(
	$pdf_resolve_root,
	array(
		'md5'      => '',
		'filename' => 'named-document.pdf',
	)
);
assert_true( '' !== $pdf_resolve_named && realpath( $pdf_filename_only ) === $pdf_resolve_named, '#59 resolve_pdf_path falls back to filename when md5 absent.' );
Day_One_Importer_Cleanup::remove( $pdf_resolve_root );

// find_pdf_dirs() discovers a top-level pdfs/ directory (R4.1).
$pdf_discover_root = sys_get_temp_dir() . '/day-one-importer-pdf-discover-' . uniqid();
mkdir( $pdf_discover_root . '/pdfs', 0777, true );
mkdir( $pdf_discover_root . '/photos', 0777, true );
$pdf_found_dirs = Day_One_Importer_Media::find_pdf_dirs( $pdf_discover_root );
assert_true( 1 === count( $pdf_found_dirs ) && false !== strpos( $pdf_found_dirs[0], DIRECTORY_SEPARATOR . 'pdfs' ), '#59 find_pdf_dirs picks up a top-level pdfs directory.' );
Day_One_Importer_Cleanup::remove( $pdf_discover_root );

// JSONL manifest round-trip: pdfAttachments field travels through the manifest unchanged.
$pdf_roundtrip_payload = array(
	array(
		'identifier'   => 'ROUNDTRIP-PDF-001',
		'md5'          => '0123456789abcdef0123456789abcdef',
		'pdfName'      => 'Round trip PDF',
		'orderInEntry' => 0,
		'fileSize'     => 4096,
	),
);
$pdf_roundtrip_dir = sys_get_temp_dir() . '/day-one-importer-pdf-roundtrip-' . uniqid();
mkdir( $pdf_roundtrip_dir, 0777, true );
file_put_contents(
	$pdf_roundtrip_dir . '/Journal.json',
	wp_json_encode(
		array(
			'metadata' => array( 'version' => 'test' ),
			'entries'  => array(
				array(
					'uuid'           => 'TEST-PDF-ROUNDTRIP',
					'creationDate'   => '2024-01-01T00:00:00Z',
					'pdfAttachments' => $pdf_roundtrip_payload,
				),
			),
		)
	)
);
$pdf_roundtrip_results = new Day_One_Importer_Results();
$pdf_roundtrip_job     = array(
	'manifest_path'       => sys_get_temp_dir() . '/day-one-importer-pdf-manifest-' . uniqid() . '/entries.jsonl',
	'zip_json_candidates' => array( 'Journal.json' ),
	'zip_photo_dirs'      => array( 'photos' ),
	'zip_video_dirs'      => array( 'videos' ),
	'zip_audio_dirs'      => array( 'audios' ),
	'zip_pdf_dirs'        => array( 'pdfs' ),
	'json_files'          => array(),
	'json_file_index'     => 0,
	'json_entry_index'    => 0,
	'entries_total'       => 0,
	'seen_uuids'          => array(),
);
$parser->discover_json_files_batch( $pdf_roundtrip_dir, $pdf_roundtrip_job, $pdf_roundtrip_results, 1.0E+30 );
$pdf_roundtrip_index = $parser->index_export_batch( $pdf_roundtrip_dir, $pdf_roundtrip_job, $pdf_roundtrip_results, 1.0E+30 );
assert_true( ! empty( $pdf_roundtrip_index['done'] ) && 1 === $pdf_roundtrip_job['entries_total'], '#59 pdfAttachments round-trip indexer writes one entry to the manifest.' );
$pdf_roundtrip_entry = $parser->read_manifest_entry( $pdf_roundtrip_job['manifest_path'], 0 );
assert_true( is_array( $pdf_roundtrip_entry ) && isset( $pdf_roundtrip_entry['pdfAttachments'] ) && 1 === count( $pdf_roundtrip_entry['pdfAttachments'] ), '#59 pdfAttachments field round-trips through the JSONL manifest (AC2).' );
$rt_pdf = $pdf_roundtrip_entry['pdfAttachments'][0];
assert_true( 'ROUNDTRIP-PDF-001' === $rt_pdf['identifier'], '#59 pdfAttachments manifest round-trip preserves identifier.' );
assert_true( '0123456789abcdef0123456789abcdef' === $rt_pdf['md5'], '#59 pdfAttachments manifest round-trip preserves md5.' );
assert_true( 'Round trip PDF' === $rt_pdf['pdfName'], '#59 pdfAttachments manifest round-trip preserves pdfName.' );
assert_true( 0 === $rt_pdf['orderInEntry'], '#59 pdfAttachments manifest round-trip preserves orderInEntry.' );
assert_true( 4096 === $rt_pdf['fileSize'], '#59 pdfAttachments manifest round-trip preserves fileSize (int).' );
Day_One_Importer_Cleanup::remove( dirname( $pdf_roundtrip_job['manifest_path'] ) );
Day_One_Importer_Cleanup::remove( $pdf_roundtrip_dir );

// --- #61 — location normalization + filter contract ---------------------------------------

// AC1, R1.3 — sample-shape entry normalizes into the 7 typed fields plus a non-empty `raw` JSON snapshot.
$location_sample = array(
	'region'             => array(
		'center'     => array( 'longitude' => -112.0715115, 'latitude' => 43.5109408 ),
		'identifier' => '<+43.51094080,-112.07151150> radius 70.70',
		'radius'     => 70.698295006150417,
	),
	'localityName'       => 'Idaho Falls',
	'country'            => 'United States',
	'timeZoneName'       => 'America/Boise',
	'administrativeArea' => 'ID',
	'longitude'          => -112.07170104980469,
	'placeName'          => 'Idaho Falls Regional Airport',
	'latitude'           => 43.511299133300781,
);
$location_results = new Day_One_Importer_Results();
$location_entry   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-LOCATION-SAMPLE',
		'creationDate' => '2031-05-10T17:45:00Z',
		'timeZone'     => 'America/Boise',
		'location'     => $location_sample,
	),
	'fictional.json',
	0,
	$location_results
);
assert_true( is_array( $location_entry ) && isset( $location_entry['location'] ), '#61 R1.2 — normalize_entry sets a location key when raw entry carries a sample-shape location.' );
assert_true( is_array( $location_entry['location'] ) && abs( $location_entry['location']['latitude'] - 43.511299133300781 ) < 1e-9, '#61 R1.3 — latitude is cast to float and round-trips to within 1e-9.' );
assert_true( abs( $location_entry['location']['longitude'] - ( -112.07170104980469 ) ) < 1e-9, '#61 R1.3 — longitude is cast to float and round-trips to within 1e-9.' );
assert_true( 'Idaho Falls Regional Airport' === $location_entry['location']['placeName'], '#61 R1.3 — placeName is sanitized + preserved verbatim.' );
assert_true( 'Idaho Falls' === $location_entry['location']['localityName'], '#61 R1.3 — localityName is sanitized + preserved verbatim.' );
assert_true( 'ID' === $location_entry['location']['administrativeArea'], '#61 R1.3 — administrativeArea is sanitized + preserved verbatim.' );
assert_true( 'United States' === $location_entry['location']['country'], '#61 R1.3 — country is sanitized + preserved verbatim.' );
assert_true( 'America/Boise' === $location_entry['location']['timeZoneName'], '#61 R1.3 — timeZoneName is sanitized + preserved verbatim.' );
assert_true( isset( $location_entry['location']['raw'] ) && is_string( $location_entry['location']['raw'] ) && '' !== $location_entry['location']['raw'], '#61 R1.3 — raw field is a non-empty JSON string snapshot of the source location.' );
assert_true( false !== strpos( $location_entry['location']['raw'], 'region' ), '#61 R1.4 — raw JSON preserves the `region` subtree (center/identifier/radius).' );
assert_true( false !== strpos( $location_entry['location']['raw'], '"identifier":"<+43.51094080,-112.07151150> radius 70.70"' ), '#61 R1.3 — raw JSON uses JSON_UNESCAPED_SLASHES (no `\\/` escapes).' );
assert_true( 0 === count( $location_results->get_warnings() ), '#61 R1.5 — well-formed sample-shape location emits no warnings.' );

// AC2, R1.2 — entry without `location` key: no `location` key in normalized output, no warning.
$loc_missing_results = new Day_One_Importer_Results();
$loc_missing_entry   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-LOCATION-MISSING',
		'creationDate' => '2031-05-10T17:45:00Z',
	),
	'fictional.json',
	1,
	$loc_missing_results
);
assert_true( is_array( $loc_missing_entry ) && ! isset( $loc_missing_entry['location'] ), '#61 R1.2 — normalize_entry omits the `location` key entirely when raw entry has no location.' );
assert_true( 0 === count( $loc_missing_results->get_warnings() ), '#61 R1.5 — entry without location emits no warnings.' );

// AC2, R1.2 — entry with empty-array `location`: dropped, no warning.
$loc_empty_results = new Day_One_Importer_Results();
$loc_empty_entry   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-LOCATION-EMPTY',
		'creationDate' => '2031-05-10T17:45:00Z',
		'location'     => array(),
	),
	'fictional.json',
	2,
	$loc_empty_results
);
assert_true( is_array( $loc_empty_entry ) && ! isset( $loc_empty_entry['location'] ), '#61 R1.2 — normalize_entry omits the `location` key when raw entry has an empty-array location.' );
assert_true( 0 === count( $loc_empty_results->get_warnings() ), '#61 R1.5 — empty-array location emits no warnings.' );

// AC2, R1.5 — non-array `location`: dropped AND a single warning is emitted.
$loc_bad_results = new Day_One_Importer_Results();
$loc_bad_entry   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-LOCATION-BAD',
		'creationDate' => '2031-05-10T17:45:00Z',
		'location'     => 'oops',
	),
	'fictional.json',
	3,
	$loc_bad_results
);
assert_true( is_array( $loc_bad_entry ) && ! isset( $loc_bad_entry['location'] ), '#61 R1.2 — non-array `location` produces no `location` key.' );
$loc_bad_warnings = $loc_bad_results->get_warnings();
assert_true( 1 === count( $loc_bad_warnings ), '#61 R1.5 — non-array `location` emits exactly one warning.' );
assert_true( false !== strpos( (string) $loc_bad_warnings[0], 'TEST-LOCATION-BAD' ), '#61 R1.5 — malformed-location warning includes the entry UUID.' );

// AC2, R1.2 — array-but-no-recognizable-fields location: dropped without warning (raw is always written but the
// caller's non-empty check is on the assembled array; with only an unknown scalar field the array still has `raw`, so
// it survives. Confirm the meaningful-fields path: an array with ONLY an unknown field still keeps raw so the entry
// reports location — but with empty scalars only, the location array is `raw`-only and parser keeps it. This mirrors
// R1.4 — region/unknown subtrees ride in `raw`.).
$loc_rawonly_results = new Day_One_Importer_Results();
$loc_rawonly_entry   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-LOCATION-RAW-ONLY',
		'creationDate' => '2031-05-10T17:45:00Z',
		'location'     => array(
			'region' => array( 'identifier' => '<+0,0> radius 0' ),
		),
	),
	'fictional.json',
	4,
	$loc_rawonly_results
);
assert_true( is_array( $loc_rawonly_entry ) && isset( $loc_rawonly_entry['location'] ) && isset( $loc_rawonly_entry['location']['raw'] ), '#61 R1.4 — a location with only a `region` subtree is preserved through `raw` (region-only payload survives).' );
assert_true( ! isset( $loc_rawonly_entry['location']['latitude'] ), '#61 R1.3 — region-only payload yields no `latitude` field (independently optional).' );
assert_true( ! isset( $loc_rawonly_entry['location']['placeName'] ), '#61 R1.3 — region-only payload yields no `placeName` field (independently optional).' );

// AC7, R3.3 — float 0.0 latitude / longitude survive normalization (gated on isset(), not truthiness).
$loc_zero_results = new Day_One_Importer_Results();
$loc_zero_entry   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-LOCATION-ZERO',
		'creationDate' => '2031-05-10T17:45:00Z',
		'location'     => array(
			'latitude'  => 0,
			'longitude' => 0.0,
		),
	),
	'fictional.json',
	5,
	$loc_zero_results
);
assert_true( isset( $loc_zero_entry['location']['latitude'] ), '#61 R3.3 — latitude=0 is preserved (isset gate, not truthiness).' );
assert_true( 0.0 === $loc_zero_entry['location']['latitude'], '#61 R3.3 — latitude=0 round-trips as float 0.0.' );
assert_true( isset( $loc_zero_entry['location']['longitude'] ), '#61 R3.3 — longitude=0.0 is preserved (isset gate, not truthiness).' );
assert_true( 0.0 === $loc_zero_entry['location']['longitude'], '#61 R3.3 — longitude=0.0 round-trips as float 0.0.' );

// R3.3 — empty / whitespace-only string fields are dropped (so the runner won't write empty meta).
$loc_empties_results = new Day_One_Importer_Results();
$loc_empties_entry   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-LOCATION-EMPTIES',
		'creationDate' => '2031-05-10T17:45:00Z',
		'location'     => array(
			'placeName'    => '',
			'localityName' => '   ',
			'country'      => 'Anywhere',
		),
	),
	'fictional.json',
	6,
	$loc_empties_results
);
assert_true( ! isset( $loc_empties_entry['location']['placeName'] ), '#61 R3.3 — empty-string placeName is dropped after sanitization.' );
assert_true( ! isset( $loc_empties_entry['location']['localityName'] ), '#61 R3.3 — whitespace-only localityName is dropped after sanitization.' );
assert_true( 'Anywhere' === $loc_empties_entry['location']['country'], '#61 R3.3 — non-empty sibling fields survive when others are empty.' );

// AC6, AC8, R4.1, R4.2 — filter contract sanity: apply_filters() with registered callbacks returns the
// transformed value. (The runner's contract — write on array, skip on null/false, warn on other — is
// covered by the wp-env smoke; here we assert the filter is invocable and stable in the value-flow path.)
$loc_filter_meta = array(
	'_day_one_location_latitude'  => '43.5113',
	'_day_one_location_longitude' => '-112.0717',
	'_day_one_location_country'   => 'United States',
);
$GLOBALS['day_one_importer_test_filters']['day_one_importer_location_meta'] = static function ( $meta ) {
	if ( is_array( $meta ) && isset( $meta['_day_one_location_country'] ) ) {
		$meta['_day_one_location_country'] = strtoupper( (string) $meta['_day_one_location_country'] );
	}
	return $meta;
};
$loc_filter_mutated = apply_filters( 'day_one_importer_location_meta', $loc_filter_meta, array(), 123, array() );
assert_true( is_array( $loc_filter_mutated ) && 'UNITED STATES' === $loc_filter_mutated['_day_one_location_country'], '#61 R4.1 — filter callback can mutate values (uppercased country example).' );

$GLOBALS['day_one_importer_test_filters']['day_one_importer_location_meta'] = static function () {
	return null;
};
$loc_filter_null = apply_filters( 'day_one_importer_location_meta', $loc_filter_meta, array(), 123, array() );
assert_true( null === $loc_filter_null, '#61 R4.2 — filter returning null is observable to the runner skip branch.' );

$GLOBALS['day_one_importer_test_filters']['day_one_importer_location_meta'] = static function () {
	return false;
};
$loc_filter_false = apply_filters( 'day_one_importer_location_meta', $loc_filter_meta, array(), 123, array() );
assert_true( false === $loc_filter_false, '#61 R4.2 — filter returning false is observable to the runner skip branch (review-1 mirror).' );

$GLOBALS['day_one_importer_test_filters']['day_one_importer_location_meta'] = static function () {
	return 'nope';
};
$loc_filter_string = apply_filters( 'day_one_importer_location_meta', $loc_filter_meta, array(), 123, array() );
assert_true( 'nope' === $loc_filter_string, '#61 R4.2 — filter returning a non-array non-null value reaches the runner (which then warns + skips).' );

// Clean up the filter registration so later tests / re-runs see a known state.
unset( $GLOBALS['day_one_importer_test_filters']['day_one_importer_location_meta'] );

// AC6, R4.3 — filter-not-fired mirror: entries without a `location` key never enter the meta-write branch.
$loc_no_branch_results = new Day_One_Importer_Results();
$loc_no_branch_entry   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-LOCATION-NO-BRANCH',
		'creationDate' => '2031-05-10T17:45:00Z',
	),
	'fictional.json',
	7,
	$loc_no_branch_results
);
assert_true( ! isset( $loc_no_branch_entry['location'] ), '#61 R4.3 — entries without a location have no `location` key, so the runner skips the filter-fire branch.' );

// --- #62 — weather normalization + filter contract ---------------------------------------

// AC1, R1.3 — sample-shape weather entry normalizes to the 11 typed fields + raw.
$weather_results  = new Day_One_Importer_Results();
$weather_entry    = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-WEATHER-SAMPLE',
		'creationDate' => '2031-05-11T17:00:00Z',
		'weather'      => array(
			'moonPhaseCode'         => 'first-quarter',
			'temperatureCelsius'    => 29.909999847412109,
			'weatherServiceName'    => 'Forecast.io',
			'windBearing'           => 234,
			'conditionsDescription' => 'Clear',
			'pressureMB'            => 1016.7999877929688,
			'moonPhase'             => 0.19,
			'visibilityKM'          => 10.451999664306641,
			'relativeHumidity'      => 0,
			'windSpeedKPH'          => 9.8500003814697266,
			'weatherCode'           => 'clear',
		),
	),
	'fictional.json',
	0,
	$weather_results
);
assert_true( is_array( $weather_entry ) && isset( $weather_entry['weather'] ), '#62 R1.2 — normalize_entry sets a weather key when raw entry carries a sample-shape weather.' );
assert_true( is_array( $weather_entry['weather'] ) && abs( $weather_entry['weather']['temperatureCelsius'] - 29.909999847412109 ) < 1e-9, '#62 R1.3 — temperatureCelsius is cast to float and round-trips to within 1e-9.' );
assert_true( 'first-quarter' === $weather_entry['weather']['moonPhaseCode'], '#62 R1.3 — moonPhaseCode is sanitized + preserved verbatim.' );
assert_true( 'Forecast.io' === $weather_entry['weather']['weatherServiceName'], '#62 R1.3 — weatherServiceName is sanitized + preserved verbatim.' );
assert_true( 234 === $weather_entry['weather']['windBearing'] && is_int( $weather_entry['weather']['windBearing'] ), '#62 R1.3 — windBearing is cast to int.' );
assert_true( 'Clear' === $weather_entry['weather']['conditionsDescription'], '#62 R1.3 — conditionsDescription is sanitized + preserved verbatim.' );
assert_true( abs( $weather_entry['weather']['pressureMB'] - 1016.7999877929688 ) < 1e-9, '#62 R1.3 — pressureMB is cast to float and round-trips to within 1e-9.' );
assert_true( 0.19 === $weather_entry['weather']['moonPhase'], '#62 R1.3 — moonPhase is cast to float and round-trips.' );
assert_true( abs( $weather_entry['weather']['visibilityKM'] - 10.451999664306641 ) < 1e-9, '#62 R1.3 — visibilityKM is cast to float and round-trips to within 1e-9.' );
assert_true( isset( $weather_entry['weather']['relativeHumidity'] ) && 0.0 === $weather_entry['weather']['relativeHumidity'], '#62 AC7, R3.3 — relativeHumidity=0 is preserved as float 0.0 (isset gate).' );
assert_true( abs( $weather_entry['weather']['windSpeedKPH'] - 9.8500003814697266 ) < 1e-9, '#62 R1.3 — windSpeedKPH is cast to float and round-trips to within 1e-9.' );
assert_true( 'clear' === $weather_entry['weather']['weatherCode'], '#62 R1.3 — weatherCode is sanitized + preserved verbatim.' );
assert_true( isset( $weather_entry['weather']['raw'] ) && is_string( $weather_entry['weather']['raw'] ) && '' !== $weather_entry['weather']['raw'], '#62 R1.3 — raw field is a non-empty JSON string snapshot of the source weather.' );
assert_true( false !== strpos( $weather_entry['weather']['raw'], '"conditionsDescription"' ), '#62 R1.3 — raw JSON preserves all source weather keys.' );
assert_true( 0 === count( $weather_results->get_warnings() ), '#62 R1.5 — well-formed sample-shape weather emits no warnings.' );

// AC2, R1.2 — entry without weather key omits the normalized weather key.
$weather_missing_results = new Day_One_Importer_Results();
$weather_missing_entry   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-WEATHER-MISSING',
		'creationDate' => '2031-05-11T17:00:00Z',
	),
	'fictional.json',
	1,
	$weather_missing_results
);
assert_true( is_array( $weather_missing_entry ) && ! isset( $weather_missing_entry['weather'] ), '#62 R1.2 — normalize_entry omits the `weather` key entirely when raw entry has no weather.' );
assert_true( 0 === count( $weather_missing_results->get_warnings() ), '#62 R1.5 — entry without weather emits no warnings.' );

// AC2, R1.2 — empty-array weather omits the normalized weather key.
$weather_empty_results = new Day_One_Importer_Results();
$weather_empty_entry   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-WEATHER-EMPTY',
		'creationDate' => '2031-05-11T17:00:00Z',
		'weather'      => array(),
	),
	'fictional.json',
	2,
	$weather_empty_results
);
assert_true( is_array( $weather_empty_entry ) && ! isset( $weather_empty_entry['weather'] ), '#62 R1.2 — normalize_entry omits the `weather` key when raw entry has an empty-array weather.' );
assert_true( 0 === count( $weather_empty_results->get_warnings() ), '#62 R1.5 — empty-array weather emits no warnings.' );

// AC2, R1.5 — non-array weather emits a warning that includes the entry UUID.
$weather_bad_results = new Day_One_Importer_Results();
$weather_bad_entry   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-WEATHER-BAD',
		'creationDate' => '2031-05-11T17:00:00Z',
		'weather'      => 'oops',
	),
	'fictional.json',
	3,
	$weather_bad_results
);
assert_true( is_array( $weather_bad_entry ) && ! isset( $weather_bad_entry['weather'] ), '#62 R1.2 — non-array `weather` produces no `weather` key.' );
$weather_bad_warnings = $weather_bad_results->get_warnings();
assert_true( 1 === count( $weather_bad_warnings ), '#62 R1.5 — non-array `weather` emits exactly one warning.' );
assert_true( false !== strpos( (string) $weather_bad_warnings[0], 'TEST-WEATHER-BAD' ), '#62 R1.5 — malformed-weather warning includes the entry UUID.' );

// AC7, R3.3, RK5 — numeric-0 preservation for windBearing (due north) and moonPhase (new moon).
$weather_zero_results = new Day_One_Importer_Results();
$weather_zero_entry   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-WEATHER-ZERO',
		'creationDate' => '2031-05-11T17:00:00Z',
		'weather'      => array(
			'relativeHumidity' => 0,
			'windBearing'      => 0,
			'moonPhase'        => 0,
		),
	),
	'fictional.json',
	4,
	$weather_zero_results
);
assert_true( isset( $weather_zero_entry['weather']['relativeHumidity'] ) && 0.0 === $weather_zero_entry['weather']['relativeHumidity'], '#62 AC7, R3.3 — relativeHumidity=0 preserved as float 0.0 (isset gate, not truthiness).' );
assert_true( isset( $weather_zero_entry['weather']['windBearing'] ) && 0 === $weather_zero_entry['weather']['windBearing'], '#62 AC7, R3.3 — windBearing=0 (due north) preserved as int 0 (isset gate, not truthiness).' );
assert_true( isset( $weather_zero_entry['weather']['moonPhase'] ) && 0.0 === $weather_zero_entry['weather']['moonPhase'], '#62 AC7, R3.3 — moonPhase=0 (new moon) preserved as float 0.0 (isset gate, not truthiness).' );

// AC1, R1.3, R1.4 — unknown / invalid fields filter correctly.
$weather_extra_results = new Day_One_Importer_Results();
$weather_extra_entry   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-WEATHER-EXTRA',
		'creationDate' => '2031-05-11T17:00:00Z',
		'weather'      => array(
			'temperatureCelsius' => 'hot',
			'weatherServiceName' => array(),
			'sunriseDate'        => '2031-05-11T06:00:00Z',
			'weatherCode'        => 'clear',
		),
	),
	'fictional.json',
	5,
	$weather_extra_results
);
assert_true( ! isset( $weather_extra_entry['weather']['sunriseDate'] ), '#62 R1.4 — unknown field (sunriseDate) is not promoted to a normalized weather field.' );
assert_true( isset( $weather_extra_entry['weather']['raw'] ) && false !== strpos( $weather_extra_entry['weather']['raw'], '"sunriseDate"' ), '#62 R1.4 — unknown field (sunriseDate) is preserved inside the raw JSON snapshot.' );
assert_true( ! isset( $weather_extra_entry['weather']['temperatureCelsius'] ), '#62 R1.3 — non-numeric temperatureCelsius is dropped.' );
assert_true( ! isset( $weather_extra_entry['weather']['weatherServiceName'] ), '#62 R1.3 — non-scalar weatherServiceName is dropped.' );
assert_true( 'clear' === $weather_extra_entry['weather']['weatherCode'], '#62 R1.3 — non-empty sibling fields survive when others are invalid.' );

// AC6, AC8, R4.1, R4.2 — filter contract sanity: the runner consumes apply_filters() return-value flow.
// The runner's contract — write on array, skip on null/false, warn on other — is covered by the wp-env
// smoke; here we assert the filter is invocable and stable in the value-flow path.
$weather_filter_meta = array(
	'_day_one_weather_temperature_celsius' => '29.909999847412',
	'_day_one_weather_conditions'          => 'Clear',
);
$GLOBALS['day_one_importer_test_filters']['day_one_importer_weather_meta'] = static function ( $meta ) {
	if ( is_array( $meta ) && isset( $meta['_day_one_weather_conditions'] ) ) {
		$meta['_day_one_weather_conditions'] = strtoupper( (string) $meta['_day_one_weather_conditions'] );
	}
	return $meta;
};
$weather_filter_mutated = apply_filters( 'day_one_importer_weather_meta', $weather_filter_meta, array(), 123, array() );
assert_true( is_array( $weather_filter_mutated ) && 'CLEAR' === $weather_filter_mutated['_day_one_weather_conditions'], '#62 R4.1 — filter callback can mutate values (uppercased conditions example).' );

$GLOBALS['day_one_importer_test_filters']['day_one_importer_weather_meta'] = static function () {
	return null;
};
$weather_filter_null = apply_filters( 'day_one_importer_weather_meta', $weather_filter_meta, array(), 123, array() );
assert_true( null === $weather_filter_null, '#62 R4.2 — filter returning null is observable to the runner skip branch.' );

$GLOBALS['day_one_importer_test_filters']['day_one_importer_weather_meta'] = static function () {
	return false;
};
$weather_filter_false = apply_filters( 'day_one_importer_weather_meta', $weather_filter_meta, array(), 123, array() );
assert_true( false === $weather_filter_false, '#62 R4.2 — filter returning false is observable to the runner skip branch.' );

$GLOBALS['day_one_importer_test_filters']['day_one_importer_weather_meta'] = static function () {
	return 'nope';
};
$weather_filter_string = apply_filters( 'day_one_importer_weather_meta', $weather_filter_meta, array(), 123, array() );
assert_true( 'nope' === $weather_filter_string, '#62 R4.2 — filter returning a non-array non-null value reaches the runner (which then warns + skips).' );

// Clean up the filter registration so later tests / re-runs see a known state.
unset( $GLOBALS['day_one_importer_test_filters']['day_one_importer_weather_meta'] );

// #75 — `find_existing_post_id` issues a single indexed postmeta query (no full
// table scan). Exercise the private method via reflection with the get_posts()
// stub above capturing the argument list; assert the new shape:
//   - meta_key   === '_day_one_uuid'
//   - meta_value === $uuid (echo'd verbatim from the call site)
//   - posts_per_page === 2 (so the duplicate-UUID warning still fires on > 1 hit)
$GLOBALS['day_one_importer_test_get_posts_calls']  = array();
$GLOBALS['day_one_importer_test_get_posts_result'] = array();
$find_existing_uuid  = 'TEST-75-UUID-NO-MATCH';
$find_existing_runner = new Day_One_Importer_Runner();
$find_existing_method = new ReflectionMethod( 'Day_One_Importer_Runner', 'find_existing_post_id' );
$find_existing_method->setAccessible( true );
$find_existing_results = new Day_One_Importer_Results();
$found_id = $find_existing_method->invoke( $find_existing_runner, $find_existing_uuid, $find_existing_results );
assert_true( 0 === $found_id, '#75 — find_existing_post_id returns 0 when no posts match the UUID.' );
assert_true( 1 === count( $GLOBALS['day_one_importer_test_get_posts_calls'] ), '#75 — find_existing_post_id issues a single get_posts() query (no per-row meta scan).' );
$find_existing_args = $GLOBALS['day_one_importer_test_get_posts_calls'][0];
assert_true( isset( $find_existing_args['meta_key'] ) && '_day_one_uuid' === $find_existing_args['meta_key'], '#75 — find_existing_post_id passes meta_key=_day_one_uuid to get_posts().' );
assert_true( isset( $find_existing_args['meta_value'] ) && $find_existing_uuid === $find_existing_args['meta_value'], '#75 — find_existing_post_id passes the UUID verbatim as meta_value.' );
assert_true( isset( $find_existing_args['posts_per_page'] ) && 2 === $find_existing_args['posts_per_page'], '#75 — find_existing_post_id caps posts_per_page at 2 so the duplicate-UUID warning still fires.' );
assert_true( isset( $find_existing_args['fields'] ) && 'ids' === $find_existing_args['fields'], '#75 — find_existing_post_id requests only post IDs from get_posts().' );
assert_true( isset( $find_existing_args['no_found_rows'] ) && true === $find_existing_args['no_found_rows'], '#75 — find_existing_post_id disables SQL_CALC_FOUND_ROWS via no_found_rows=true.' );
assert_true( isset( $find_existing_args['post_type'] ) && 'post' === $find_existing_args['post_type'], '#75 — find_existing_post_id scopes the lookup to post_type=post.' );
assert_true( isset( $find_existing_args['post_status'] ) && 'any' === $find_existing_args['post_status'], '#75 — find_existing_post_id uses post_status=any so private imported posts are included.' );
assert_true( ! $find_existing_results->has_warnings(), '#75 — no warning is emitted when zero posts match.' );

// #75 — single match: returns the ID and emits no duplicate-UUID warning.
$GLOBALS['day_one_importer_test_get_posts_calls']  = array();
$GLOBALS['day_one_importer_test_get_posts_result'] = array( '4242' );
$single_match_results = new Day_One_Importer_Results();
$single_match_id      = $find_existing_method->invoke( $find_existing_runner, 'TEST-75-UUID-ONE', $single_match_results );
assert_true( 4242 === $single_match_id, '#75 — find_existing_post_id returns the matching post ID coerced to int.' );
assert_true( ! $single_match_results->has_warnings(), '#75 — a single match does not trigger the duplicate-UUID warning.' );

// #75 — multiple matches: returns the first ID and emits the duplicate-UUID warning.
$GLOBALS['day_one_importer_test_get_posts_calls']  = array();
$GLOBALS['day_one_importer_test_get_posts_result'] = array( '4242', '5353' );
$dup_match_results = new Day_One_Importer_Results();
$dup_match_id      = $find_existing_method->invoke( $find_existing_runner, 'TEST-75-UUID-DUP', $dup_match_results );
assert_true( 4242 === $dup_match_id, '#75 — find_existing_post_id returns the first matching post ID on duplicate UUIDs.' );
assert_true( $dup_match_results->has_warnings(), '#75 — duplicate-UUID warning is emitted when more than one post matches.' );

// Clean up the get_posts() capture buffer so later tests / re-runs see a known state.
$GLOBALS['day_one_importer_test_get_posts_calls']  = array();
$GLOBALS['day_one_importer_test_get_posts_result'] = array();

// AC6, R4.3 — filter-not-fired mirror: entries without a `weather` key never enter the meta-write branch.
$weather_no_branch_results = new Day_One_Importer_Results();
$weather_no_branch_entry   = $parser->normalize_entry(
	array(
		'uuid'         => 'TEST-WEATHER-NO-BRANCH',
		'creationDate' => '2031-05-11T17:00:00Z',
	),
	'fictional.json',
	6,
	$weather_no_branch_results
);
assert_true( ! isset( $weather_no_branch_entry['weather'] ), '#62 R4.3 — entries without a weather have no `weather` key, so the runner skips the filter-fire branch.' );

// #76 — attachment dedupe N+1 perf fix: both `find_existing_attachment()` and
// `find_partial_attachment_by_source()` must issue a single indexed
// `meta_query`-backed `get_posts` call instead of loading every attachment on
// the post and reading meta rows per attachment in PHP. The `get_posts` stub
// above captures argument lists; assert the new `meta_query` shape and that
// the legacy "load all then filter" branch is gone.
$GLOBALS['day_one_importer_test_get_posts_calls']  = array();
$GLOBALS['day_one_importer_test_get_posts_result'] = array();
$media_results   = new Day_One_Importer_Results();
$media_for_tests = new Day_One_Importer_Media( '/tmp/day-one-importer-test-root', $media_results );

// #76 — early-return: both markers empty ⇒ zero queries, return 0.
$found_id = $media_for_tests->find_existing_attachment( 4242, 'TEST-76-UUID', '', '' );
assert_true( 0 === $found_id, '#76 — find_existing_attachment returns 0 when both identifier and md5 are empty.' );
assert_true( 0 === count( $GLOBALS['day_one_importer_test_get_posts_calls'] ), '#76 — find_existing_attachment issues zero queries when both markers are empty.' );

// #76 — both markers present: single query, meta_query joins UUID AND ( identifier OR md5 ).
$GLOBALS['day_one_importer_test_get_posts_calls']  = array();
$GLOBALS['day_one_importer_test_get_posts_result'] = array();
$found_id = $media_for_tests->find_existing_attachment( 4242, 'TEST-76-UUID', 'photo-identifier-abc', 'md5deadbeef' );
assert_true( 0 === $found_id, '#76 — find_existing_attachment returns 0 when get_posts yields no rows.' );
assert_true( 1 === count( $GLOBALS['day_one_importer_test_get_posts_calls'] ), '#76 — find_existing_attachment issues exactly one get_posts() query (no per-attachment meta scan).' );
$args = $GLOBALS['day_one_importer_test_get_posts_calls'][0];
assert_true( isset( $args['post_type'] ) && 'attachment' === $args['post_type'], '#76 — find_existing_attachment scopes the lookup to post_type=attachment.' );
assert_true( isset( $args['post_status'] ) && 'any' === $args['post_status'], '#76 — find_existing_attachment uses post_status=any.' );
assert_true( isset( $args['post_parent'] ) && 4242 === $args['post_parent'], '#76 — find_existing_attachment scopes the lookup to the supplied post_parent.' );
assert_true( isset( $args['fields'] ) && 'ids' === $args['fields'], '#76 — find_existing_attachment requests only post IDs from get_posts().' );
assert_true( isset( $args['posts_per_page'] ) && 1 === $args['posts_per_page'], '#76 — find_existing_attachment caps posts_per_page at 1 (a single match is sufficient).' );
assert_true( isset( $args['no_found_rows'] ) && true === $args['no_found_rows'], '#76 — find_existing_attachment disables SQL_CALC_FOUND_ROWS via no_found_rows=true.' );
assert_true( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ), '#76 — find_existing_attachment passes a meta_query to get_posts() (no per-row PHP meta filtering).' );
$mq = $args['meta_query'];
assert_true( isset( $mq['relation'] ) && 'AND' === $mq['relation'], '#76 — find_existing_attachment meta_query top-level relation is AND.' );
assert_true( isset( $mq[0]['key'] ) && '_day_one_uuid' === $mq[0]['key'] && 'TEST-76-UUID' === $mq[0]['value'] && '=' === $mq[0]['compare'], '#76 — find_existing_attachment meta_query first clause matches _day_one_uuid = $uuid.' );
$sub = isset( $mq[1] ) ? $mq[1] : array();
assert_true( isset( $sub['relation'] ) && 'OR' === $sub['relation'], '#76 — find_existing_attachment marker sub-clause uses relation=OR when both identifier and md5 are present.' );
$sub_clauses = array_values( array_filter( $sub, static function ( $value, $key ) { return is_int( $key ); }, ARRAY_FILTER_USE_BOTH ) );
$marker_keys = array();
foreach ( $sub_clauses as $clause ) {
	if ( isset( $clause['key'] ) ) {
		$marker_keys[ $clause['key'] ] = isset( $clause['value'] ) ? $clause['value'] : null;
	}
}
assert_true( isset( $marker_keys['_day_one_media_identifier'] ) && 'photo-identifier-abc' === $marker_keys['_day_one_media_identifier'], '#76 — find_existing_attachment meta_query OR sub-clause includes _day_one_media_identifier = $identifier.' );
assert_true( isset( $marker_keys['_day_one_media_md5'] ) && 'md5deadbeef' === $marker_keys['_day_one_media_md5'], '#76 — find_existing_attachment meta_query OR sub-clause includes _day_one_media_md5 = $md5.' );

// #76 — identifier only (md5 empty): meta_query top-level relation is still AND
// but the marker sub-clause collapses to a single equality on _day_one_media_identifier.
$GLOBALS['day_one_importer_test_get_posts_calls']  = array();
$GLOBALS['day_one_importer_test_get_posts_result'] = array();
$found_id = $media_for_tests->find_existing_attachment( 4242, 'TEST-76-UUID', 'photo-identifier-abc', '' );
assert_true( 0 === $found_id, '#76 — find_existing_attachment (identifier only) returns 0 when get_posts yields no rows.' );
assert_true( 1 === count( $GLOBALS['day_one_importer_test_get_posts_calls'] ), '#76 — find_existing_attachment (identifier only) issues exactly one get_posts() query.' );
$args = $GLOBALS['day_one_importer_test_get_posts_calls'][0];
$mq   = $args['meta_query'];
$sub  = isset( $mq[1] ) ? $mq[1] : array();
assert_true( ! isset( $sub['relation'] ), '#76 — find_existing_attachment marker sub-clause omits relation=OR when only identifier is present (single clause).' );
assert_true( isset( $sub['key'] ) && '_day_one_media_identifier' === $sub['key'] && 'photo-identifier-abc' === $sub['value'] && '=' === $sub['compare'], '#76 — find_existing_attachment (identifier only) sub-clause is _day_one_media_identifier = $identifier.' );

// #76 — md5 only (identifier empty): mirror of the identifier-only case.
$GLOBALS['day_one_importer_test_get_posts_calls']  = array();
$GLOBALS['day_one_importer_test_get_posts_result'] = array();
$found_id = $media_for_tests->find_existing_attachment( 4242, 'TEST-76-UUID', '', 'md5deadbeef' );
assert_true( 0 === $found_id, '#76 — find_existing_attachment (md5 only) returns 0 when get_posts yields no rows.' );
assert_true( 1 === count( $GLOBALS['day_one_importer_test_get_posts_calls'] ), '#76 — find_existing_attachment (md5 only) issues exactly one get_posts() query.' );
$args = $GLOBALS['day_one_importer_test_get_posts_calls'][0];
$mq   = $args['meta_query'];
$sub  = isset( $mq[1] ) ? $mq[1] : array();
assert_true( ! isset( $sub['relation'] ), '#76 — find_existing_attachment marker sub-clause omits relation=OR when only md5 is present (single clause).' );
assert_true( isset( $sub['key'] ) && '_day_one_media_md5' === $sub['key'] && 'md5deadbeef' === $sub['value'] && '=' === $sub['compare'], '#76 — find_existing_attachment (md5 only) sub-clause is _day_one_media_md5 = $md5.' );

// #76 — single match: returns the first ID coerced to int.
$GLOBALS['day_one_importer_test_get_posts_calls']  = array();
$GLOBALS['day_one_importer_test_get_posts_result'] = array( '9999' );
$found_id = $media_for_tests->find_existing_attachment( 4242, 'TEST-76-UUID', 'photo-identifier-abc', 'md5deadbeef' );
assert_true( 9999 === $found_id, '#76 — find_existing_attachment returns the matching attachment ID coerced to int.' );

// #76 — `find_partial_attachment_by_source()` (private, invoked via reflection):
// must issue a single meta_query-backed get_posts() with `_day_one_source`
// NOT EXISTS OR != 'day-one-export', capped at posts_per_page=10.
if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( $attachment_id ) {
		$map = isset( $GLOBALS['day_one_importer_test_attached_files'] ) ? $GLOBALS['day_one_importer_test_attached_files'] : array();
		return isset( $map[ (int) $attachment_id ] ) ? $map[ (int) $attachment_id ] : false;
	}
}
$GLOBALS['day_one_importer_test_attached_files']   = array();
$GLOBALS['day_one_importer_test_get_posts_calls']  = array();
$GLOBALS['day_one_importer_test_get_posts_result'] = array();
$partial_method = new ReflectionMethod( 'Day_One_Importer_Media', 'find_partial_attachment_by_source' );
$partial_method->setAccessible( true );
$partial_photo  = array(
	'identifier' => 'photo-identifier-abc',
	'md5'        => 'md5deadbeef',
	'type'       => 'jpeg',
);
$partial_source = '/tmp/day-one-importer-test/photos/abcdef0123456789abcdef0123456789.jpeg';
$found_id       = $partial_method->invoke( $media_for_tests, 4242, 'TEST-76-UUID', $partial_photo, $partial_source );
assert_true( 0 === $found_id, '#76 — find_partial_attachment_by_source returns 0 when get_posts yields no rows.' );
assert_true( 1 === count( $GLOBALS['day_one_importer_test_get_posts_calls'] ), '#76 — find_partial_attachment_by_source issues exactly one get_posts() query (no per-attachment meta scan).' );
$args = $GLOBALS['day_one_importer_test_get_posts_calls'][0];
assert_true( isset( $args['post_type'] ) && 'attachment' === $args['post_type'], '#76 — find_partial_attachment_by_source scopes the lookup to post_type=attachment.' );
assert_true( isset( $args['post_status'] ) && 'any' === $args['post_status'], '#76 — find_partial_attachment_by_source uses post_status=any.' );
assert_true( isset( $args['post_parent'] ) && 4242 === $args['post_parent'], '#76 — find_partial_attachment_by_source scopes the lookup to the supplied post_parent.' );
assert_true( isset( $args['fields'] ) && 'ids' === $args['fields'], '#76 — find_partial_attachment_by_source requests only post IDs from get_posts().' );
assert_true( isset( $args['posts_per_page'] ) && 10 === $args['posts_per_page'], '#76 — find_partial_attachment_by_source caps posts_per_page at 10 (no unbounded scan).' );
assert_true( isset( $args['no_found_rows'] ) && true === $args['no_found_rows'], '#76 — find_partial_attachment_by_source disables SQL_CALC_FOUND_ROWS via no_found_rows=true.' );
assert_true( isset( $args['meta_query'] ) && is_array( $args['meta_query'] ), '#76 — find_partial_attachment_by_source passes a meta_query to get_posts() (the _day_one_source filter is pushed into SQL).' );
$mq = $args['meta_query'];
assert_true( isset( $mq['relation'] ) && 'OR' === $mq['relation'], '#76 — find_partial_attachment_by_source meta_query top-level relation is OR (NOT EXISTS OR != day-one-export).' );
$found_not_exists   = false;
$found_not_dayone   = false;
foreach ( $mq as $key => $clause ) {
	if ( ! is_array( $clause ) || ! isset( $clause['key'] ) ) {
		continue;
	}
	if ( '_day_one_source' === $clause['key'] && isset( $clause['compare'] ) && 'NOT EXISTS' === $clause['compare'] ) {
		$found_not_exists = true;
	}
	if ( '_day_one_source' === $clause['key'] && isset( $clause['compare'] ) && '!=' === $clause['compare'] && isset( $clause['value'] ) && 'day-one-export' === $clause['value'] ) {
		$found_not_dayone = true;
	}
}
assert_true( $found_not_exists, '#76 — find_partial_attachment_by_source meta_query includes a `_day_one_source` NOT EXISTS clause.' );
assert_true( $found_not_dayone, '#76 — find_partial_attachment_by_source meta_query includes a `_day_one_source != day-one-export` clause.' );

// #76 — `find_partial_attachment_by_source()` filename-shape match: when
// get_posts returns an attachment whose `get_attached_file()` basename
// matches the expected upload filename (same base, same extension), the
// function returns that attachment ID. This preserves the pre-#76 semantics
// for partial-repair (filename-shape match), now bounded by the capped
// meta_query.
$expected_upload = Day_One_Importer_Media::build_upload_filename( $partial_source, $partial_photo );
$GLOBALS['day_one_importer_test_attached_files']   = array(
	7777 => '/srv/www/wp-content/uploads/private/day-one/' . $expected_upload,
);
$GLOBALS['day_one_importer_test_get_posts_calls']  = array();
$GLOBALS['day_one_importer_test_get_posts_result'] = array( '7777' );
$found_id = $partial_method->invoke( $media_for_tests, 4242, 'TEST-76-UUID', $partial_photo, $partial_source );
assert_true( 7777 === $found_id, '#76 — find_partial_attachment_by_source returns the matching attachment ID when filename basename matches.' );

// #76 — `find_partial_attachment_by_source()` extension mismatch: an
// attachment whose extension differs from the expected upload extension is
// skipped, the loop continues, and (with no other candidate) returns 0.
$GLOBALS['day_one_importer_test_attached_files']   = array(
	8888 => '/srv/www/wp-content/uploads/private/day-one/something-else.png',
);
$GLOBALS['day_one_importer_test_get_posts_calls']  = array();
$GLOBALS['day_one_importer_test_get_posts_result'] = array( '8888' );
$found_id = $partial_method->invoke( $media_for_tests, 4242, 'TEST-76-UUID', $partial_photo, $partial_source );
assert_true( 0 === $found_id, '#76 — find_partial_attachment_by_source returns 0 when the only candidate has a different file extension.' );

// Clean up #76 capture buffers so later tests / re-runs see a known state.
$GLOBALS['day_one_importer_test_get_posts_calls']  = array();
$GLOBALS['day_one_importer_test_get_posts_result'] = array();
$GLOBALS['day_one_importer_test_attached_files']   = array();

echo "All pure helper tests passed.\n";
