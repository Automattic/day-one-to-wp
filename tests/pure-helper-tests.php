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

if ( ! function_exists( 'wp_get_attachment_image_src' ) ) {
	function wp_get_attachment_image_src( $id, $size, $icon = false ) {
		$id = (int) $id;
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
		return 303 === (int) $id ? 'https://example.test/303-fallback.jpg' : false;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $flags = 0 ) {
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

// #56 AC6 — video/audio/pdfAttachment embeds emit zero blocks and one per-type warning each.
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
assert_true( '' === $ac6_output, '#56 AC6 — video/audio/pdfAttachment embeds emit no blocks.' );
$ac6_warnings = $ac6_results->get_warnings();
assert_true( 3 === count( $ac6_warnings ), '#56 AC6 — exactly 3 warnings recorded (one per unsupported type).' );
$ac6_warning_blob = implode( "\n", $ac6_warnings );
assert_true( false !== strpos( $ac6_warning_blob, 'video import is not yet supported' ), '#56 AC6 — video warning text present.' );
assert_true( false !== strpos( $ac6_warning_blob, 'audio import is not yet supported' ), '#56 AC6 — audio warning text present.' );
assert_true( false !== strpos( $ac6_warning_blob, 'PDF import is not yet supported' ), '#56 AC6 — PDF warning text present.' );
assert_true( false === strpos( $ac6_warning_blob, '#57' ), '#56 AC6 — warnings do not leak issue number #57.' );
assert_true( false === strpos( $ac6_warning_blob, '#58' ), '#56 AC6 — warnings do not leak issue number #58.' );
assert_true( false === strpos( $ac6_warning_blob, '#59' ), '#56 AC6 — warnings do not leak issue number #59.' );
assert_true( false === strpos( $ac6_warning_blob, 'issue' ), '#56 AC6 — warnings do not contain the word "issue".' );
assert_true( false === strpos( $ac6_warning_blob, 'AC6-V' ), '#56 AC6 — warnings do not leak embed identifier (video).' );
assert_true( false === strpos( $ac6_warning_blob, 'AC6-A' ), '#56 AC6 — warnings do not leak embed identifier (audio).' );
assert_true( false === strpos( $ac6_warning_blob, 'AC6-P' ), '#56 AC6 — warnings do not leak embed identifier (pdf).' );

// #56 AC6 dedupe — two video embeds in one run produce exactly one video warning.
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
assert_true( 1 === count( $ac6_dedupe_results->get_warnings() ), '#56 AC6 — two video embeds in one entry yield exactly one video warning (per-type dedupe).' );

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
assert_true( 19 === count( $fixture_entries ), 'Committed fictional fixture parses nineteen entries.' );
assert_true( 19 === $fixture_results->get_count( 'entries_found' ), 'Committed fictional fixture reports nineteen found entries.' );
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
assert_true( ! empty( $batch_index['done'] ) && 19 === $batch_job['entries_total'], 'Batch parser indexes fixture entries into a manifest.' );
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
assert_true( 19 === $bounded_job['entries_total'] && $bounded_batches > 3, 'Batch parser can complete fixture indexing across multiple bounded requests.' );
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

echo "All pure helper tests passed.\n";
