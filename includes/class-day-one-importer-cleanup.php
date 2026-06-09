<?php
/**
 * Temporary file and archive safety helpers.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, protects, validates, and removes importer temp directories.
 */
class Day_One_Importer_Cleanup {
	/**
	 * Create a per-run temporary directory.
	 *
	 * @return string|false Directory path, or false.
	 */
	public static function create_run_directory() {
		$base = self::run_base_dir();
		if ( '' === $base ) {
			return false;
		}

		if ( ! self::make_directory( $base ) ) {
			return false;
		}

		$run = $base . DIRECTORY_SEPARATOR . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 8, false, false );

		if ( ! self::make_directory( $run ) ) {
			return false;
		}

		if ( ! self::set_owner_only_permissions( $base ) || ! self::set_owner_only_permissions( $run ) ) {
			self::remove( $run );
			return false;
		}

		self::protect_directory( $base );
		self::protect_directory( $run );

		return $run;
	}

	/**
	 * Resolve the base directory that holds per-run import folders.
	 *
	 * Prefers wp-content/uploads/day-one-importer so per-run state lives with
	 * the rest of the site's user content and survives system temp wipes.
	 * Falls back to get_temp_dir() when the uploads dir is unavailable.
	 *
	 * @return string Absolute path without trailing separator, or empty string on failure.
	 */
	public static function run_base_dir() {
		$basedir = '';
		if ( function_exists( 'wp_upload_dir' ) ) {
			$upload_dir = wp_upload_dir( null, false );
			if ( is_array( $upload_dir ) && empty( $upload_dir['error'] ) && ! empty( $upload_dir['basedir'] ) ) {
				$basedir = (string) $upload_dir['basedir'];
			}
		}

		if ( '' === $basedir ) {
			$basedir = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir() . DIRECTORY_SEPARATOR;
		}

		$base = untrailingslashit( $basedir ) . DIRECTORY_SEPARATOR . 'day-one-importer';

		if ( function_exists( 'apply_filters' ) ) {
			$base = (string) apply_filters( 'day_one_importer_run_base_dir', $base );
		}

		return '' === $base ? '' : untrailingslashit( $base );
	}

	/**
	 * Restrict a file or directory to owner-only permissions.
	 *
	 * @param string $path File or directory path.
	 * @return bool True when permissions were applied.
	 */
	public static function set_owner_only_permissions( $path ) {
		if ( empty( $path ) || ! self::exists( $path ) ) {
			return false;
		}

		$filesystem = self::filesystem();
		if ( ! $filesystem || ! method_exists( $filesystem, 'chmod' ) ) {
			return false;
		}

		return (bool) $filesystem->chmod( $path, self::is_dir( $path ) ? 0700 : 0600, false );
	}

	/**
	 * Add best-effort protection files to a temp directory.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	public static function protect_directory( $dir ) {
		if ( ! self::is_dir( $dir ) || ! self::is_writable( $dir ) ) {
			return;
		}

		$files = array(
			'index.html' => '',
			'.htaccess'  => "Deny from all\nRequire all denied\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);

		foreach ( $files as $name => $contents ) {
			$path = trailingslashit( $dir ) . $name;
			if ( ! self::exists( $path ) ) {
				self::write_file( $path, $contents );
			}
		}
	}

	/**
	 * Return the WordPress filesystem object when available.
	 *
	 * @return WP_Filesystem_Base|null Filesystem object, or null on failure.
	 */
	public static function filesystem() {
		global $wp_filesystem;

		if ( ! $wp_filesystem || ! is_object( $wp_filesystem ) ) {
			if ( defined( 'ABSPATH' ) && ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			if ( function_exists( 'WP_Filesystem' ) ) {
				WP_Filesystem();
			}
		}

		return $wp_filesystem && is_object( $wp_filesystem ) ? $wp_filesystem : null;
	}

	/**
	 * Check whether a path exists through the WordPress filesystem API.
	 *
	 * @param string $path File or directory path.
	 * @return bool True when the path exists.
	 */
	public static function exists( $path ) {
		$filesystem = self::filesystem();

		return $filesystem && method_exists( $filesystem, 'exists' ) ? (bool) $filesystem->exists( $path ) : false;
	}

	/**
	 * Check whether a path is a directory through the WordPress filesystem API.
	 *
	 * @param string $path Directory path.
	 * @return bool True when the path is a directory.
	 */
	public static function is_dir( $path ) {
		$filesystem = self::filesystem();

		return $filesystem && method_exists( $filesystem, 'is_dir' ) ? (bool) $filesystem->is_dir( $path ) : false;
	}

	/**
	 * Check whether a path is a file through the WordPress filesystem API.
	 *
	 * @param string $path File path.
	 * @return bool True when the path is a file.
	 */
	public static function is_file( $path ) {
		$filesystem = self::filesystem();

		return $filesystem && method_exists( $filesystem, 'is_file' ) ? (bool) $filesystem->is_file( $path ) : false;
	}

	/**
	 * Check whether a file can be read through the WordPress filesystem API.
	 *
	 * @param string $path File path.
	 * @return bool True when readable.
	 */
	public static function is_readable( $path ) {
		$filesystem = self::filesystem();

		return $filesystem && method_exists( $filesystem, 'is_readable' ) ? (bool) $filesystem->is_readable( $path ) : self::is_file( $path );
	}

	/**
	 * Check whether a path is writable through the WordPress filesystem API.
	 *
	 * @param string $path File or directory path.
	 * @return bool True when writable.
	 */
	public static function is_writable( $path ) {
		$filesystem = self::filesystem();

		return $filesystem && method_exists( $filesystem, 'is_writable' ) ? (bool) $filesystem->is_writable( $path ) : false;
	}

	/**
	 * Return a file size through the WordPress filesystem API.
	 *
	 * @param string $path File path.
	 * @return int File size in bytes.
	 */
	public static function size( $path ) {
		$filesystem = self::filesystem();
		$size       = $filesystem && method_exists( $filesystem, 'size' ) ? $filesystem->size( $path ) : 0;

		return is_numeric( $size ) ? (int) $size : 0;
	}

	/**
	 * Read a complete file through the WordPress filesystem API.
	 *
	 * @param string $path File path.
	 * @return string|false File contents, or false on failure.
	 */
	public static function read_file( $path ) {
		$filesystem = self::filesystem();

		return $filesystem && method_exists( $filesystem, 'get_contents' ) ? $filesystem->get_contents( $path ) : false;
	}

	/**
	 * Normalize a path string without touching the native filesystem.
	 *
	 * @param string $path Path.
	 * @return string Normalized path.
	 */
	public static function normalize_path( $path ) {
		if ( function_exists( 'wp_normalize_path' ) ) {
			return wp_normalize_path( (string) $path );
		}

		return preg_replace( '#/+#', '/', str_replace( '\\', '/', (string) $path ) );
	}

	/**
	 * Return whether a normalized path is inside a normalized root.
	 *
	 * @param string $path Path.
	 * @param string $root Root path.
	 * @return bool True when path is root or a child of root.
	 */
	public static function path_is_inside( $path, $root ) {
		$path = rtrim( self::normalize_path( $path ), '/' );
		$root = rtrim( self::normalize_path( $root ), '/' );

		return $path === $root || 0 === strpos( $path, $root . '/' );
	}

	/**
	 * Convert a safe archive-relative path into an absolute path under root.
	 *
	 * @param string $root Root path.
	 * @param string $relative Archive-relative path.
	 * @return string Absolute path, or empty string on failure.
	 */
	public static function archive_relative_path( $root, $relative ) {
		if ( ! self::is_safe_relative_archive_name( $relative ) ) {
			return '';
		}

		$root     = rtrim( self::normalize_path( $root ), '/' );
		$relative = trim( self::normalize_path( $relative ), '/' );
		$path     = '' === $relative ? $root : $root . '/' . $relative;

		return self::path_is_inside( $path, $root ) ? $path : '';
	}

	/**
	 * Return a recursive directory path list through WP_Filesystem.
	 *
	 * @param string $root Root directory.
	 * @return string[] Directory and file paths.
	 */
	public static function list_directory_paths( $root ) {
		$filesystem = self::filesystem();
		if ( ! $filesystem || ! method_exists( $filesystem, 'dirlist' ) ) {
			return array();
		}

		$root = rtrim( self::normalize_path( $root ), '/' );
		$list = $filesystem->dirlist( $root, true, true );
		if ( ! is_array( $list ) ) {
			return array();
		}

		$paths = array();
		self::flatten_dirlist( $root, $list, $paths );
		sort( $paths );
		return $paths;
	}

	/**
	 * Flatten WP_Filesystem dirlist output.
	 *
	 * @param string              $base Base path.
	 * @param array<string,mixed> $items Directory list.
	 * @param string[]            $paths Paths accumulator.
	 * @return void
	 */
	private static function flatten_dirlist( $base, $items, &$paths ) {
		foreach ( $items as $name => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$item_name = isset( $item['name'] ) ? (string) $item['name'] : (string) $name;
			$path      = rtrim( $base, '/' ) . '/' . ltrim( self::normalize_path( $item_name ), '/' );
			$paths[]   = $path;

			if ( ! empty( $item['files'] ) && is_array( $item['files'] ) ) {
				self::flatten_dirlist( $path, $item['files'], $paths );
			}
		}
	}

	/**
	 * Write a complete file through the WordPress filesystem API.
	 *
	 * @param string $path File path.
	 * @param string $contents File contents.
	 * @return bool True when the file was written.
	 */
	public static function write_file( $path, $contents ) {
		$filesystem = self::filesystem();

		return $filesystem && method_exists( $filesystem, 'put_contents' ) ? (bool) $filesystem->put_contents( $path, $contents, 0600 ) : false;
	}

	/**
	 * Copy a file through the WordPress filesystem API.
	 *
	 * @param string $source Source path.
	 * @param string $destination Destination path.
	 * @return bool True when copied.
	 */
	public static function copy_file( $source, $destination ) {
		$filesystem = self::filesystem();

		return $filesystem && method_exists( $filesystem, 'copy' ) ? (bool) $filesystem->copy( $source, $destination, true, 0600 ) : false;
	}

	/**
	 * Create a directory through WordPress helpers.
	 *
	 * @param string $path Directory path.
	 * @return bool True when the directory exists or was created.
	 */
	public static function make_directory( $path ) {
		$path = rtrim( self::normalize_path( (string) $path ), '/' );
		if ( '' === $path ) {
			return false;
		}
		if ( self::is_dir( $path ) ) {
			return true;
		}

		$parent = dirname( $path );
		if ( $parent && $parent !== $path && ! self::is_dir( $parent ) && ! self::make_directory( $parent ) ) {
			return false;
		}

		$filesystem = self::filesystem();
		if ( $filesystem && method_exists( $filesystem, 'mkdir' ) && ! self::is_dir( $path ) ) {
			$filesystem->mkdir( $path, 0700 );
		}

		return self::is_dir( $path );
	}

	/**
	 * Remove an empty directory through the WordPress filesystem API.
	 *
	 * @param string $path Directory path.
	 * @return bool True when removed.
	 */
	public static function remove_directory( $path ) {
		$filesystem = self::filesystem();

		return $filesystem && method_exists( $filesystem, 'rmdir' ) ? (bool) $filesystem->rmdir( $path, false ) : false;
	}

	/**
	 * Delete a file or directory through WordPress APIs.
	 *
	 * @param string $path File or directory path.
	 * @return bool True when removed.
	 */
	public static function delete_path( $path ) {
		$filesystem = self::filesystem();
		return $filesystem && method_exists( $filesystem, 'delete' ) ? (bool) $filesystem->delete( $path, true ) : false;
	}

	/**
	 * Safely pre-scan a zip archive when ZipArchive is available.
	 *
	 * @param string $zip_path Zip path.
	 * @return true|string True on success, otherwise error message.
	 */
	public static function preflight_zip( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return function_exists( '__' ) ? __( 'The PHP ZipArchive extension is required before a Day One ZIP can be imported safely.', 'day-one-importer' ) : 'The PHP ZipArchive extension is required before a Day One ZIP can be imported safely.';
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return function_exists( '__' ) ? __( 'The ZIP archive could not be opened.', 'day-one-importer' ) : 'The ZIP archive could not be opened.';
		}

		$total        = (int) $zip->count();
		$budget_state = array();
		for ( $i = 0; $i < $total; $i++ ) {
			$stat = $zip->statIndex( $i );
			if ( ! is_array( $stat ) ) {
				$zip->close();
				return function_exists( '__' ) ? __( 'The ZIP archive could not be inspected safely.', 'day-one-importer' ) : 'The ZIP archive could not be inspected safely.';
			}
			$name = isset( $stat['name'] ) ? (string) $stat['name'] : '';
			if ( ! self::is_safe_relative_archive_name( $name ) ) {
				$zip->close();
				return sprintf(
					/* translators: %s: archive entry. */
					function_exists( '__' ) ? __( 'Unsafe archive path rejected: %s', 'day-one-importer' ) : 'Unsafe archive path rejected: %s',
					$name
				);
			}

			$opsys = 0;
			$attr  = 0;
			if ( method_exists( $zip, 'getExternalAttributesIndex' ) && $zip->getExternalAttributesIndex( $i, $opsys, $attr ) ) {
				$file_type = ( $attr >> 16 ) & 0170000;
				if ( 0120000 === $file_type ) {
					$zip->close();
					return sprintf(
						/* translators: %s: archive entry. */
						function_exists( '__' ) ? __( 'Symlink archive entry rejected: %s', 'day-one-importer' ) : 'Symlink archive entry rejected: %s',
						$name
					);
				}
			}

			$budget_state = self::accumulate_zip_budget_state( $budget_state, $stat );
			$error        = self::zip_budget_error( $name, $total, $budget_state );
			if ( '' !== $error ) {
				$zip->close();
				return $error;
			}
		}

		$zip->close();
		return true;
	}

	/**
	 * Determine whether a zip entry name is safe and relative.
	 *
	 * @param string $name Archive name.
	 * @return bool
	 */
	public static function is_safe_relative_archive_name( $name ) {
		if ( '' === $name || false !== strpos( $name, "\0" ) || preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
			return false;
		}

		$name = str_replace( '\\', '/', $name );

		if ( '/' === substr( $name, 0, 1 ) || preg_match( '/^[A-Za-z]:/', $name ) ) {
			return false;
		}

		$parts = explode( '/', $name );
		foreach ( $parts as $part ) {
			if ( '..' === $part ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Validate all extracted paths stay inside the extraction root and are not symlinks.
	 *
	 * @param string $root Root directory.
	 * @return true|string True on success, otherwise error message.
	 */
	public static function validate_extracted_tree( $root ) {
		$root = self::normalize_path( $root );
		if ( ! self::is_dir( $root ) ) {
			return function_exists( '__' ) ? __( 'The extraction directory is invalid.', 'day-one-importer' ) : 'The extraction directory is invalid.';
		}

		foreach ( self::list_directory_paths( $root ) as $path ) {
			if ( ! self::path_is_inside( $path, $root ) ) {
				return function_exists( '__' ) ? __( 'The archive extracted a file outside the temporary directory.', 'day-one-importer' ) : 'The archive extracted a file outside the temporary directory.';
			}
		}

		return true;
	}

	/**
	 * Validate extracted paths in bounded batches for async jobs.
	 *
	 * Uses the already-bounded ZIP member cursor instead of walking extracted
	 * directories, avoiding large directory scans after extraction.
	 *
	 * @param string              $root Root directory.
	 * @param array<string,mixed> $job Job state, updated by reference.
	 * @param int                 $limit Maximum ZIP members to validate.
	 * @param float               $deadline Deadline timestamp.
	 * @return array<string,mixed>
	 */
	public static function validate_extracted_tree_batch( $root, &$job, $limit, $deadline ) {
		$result = array(
			'done'  => false,
			'error' => '',
		);

		$root = self::normalize_path( $root );
		if ( ! self::is_dir( $root ) ) {
			$result['error'] = function_exists( '__' ) ? __( 'The extraction directory is invalid.', 'day-one-importer' ) : 'The extraction directory is invalid.';
			return $result;
		}

		$zip_path = isset( $job['zip_path'] ) ? (string) $job['zip_path'] : '';
		if ( '' === $zip_path || ! self::is_readable( $zip_path ) || ! class_exists( 'ZipArchive' ) ) {
			$result['error'] = function_exists( '__' ) ? __( 'The extracted tree could not be validated from the ZIP manifest.', 'day-one-importer' ) : 'The extracted tree could not be validated from the ZIP manifest.';
			return $result;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			$result['error'] = function_exists( '__' ) ? __( 'The ZIP archive could not be opened.', 'day-one-importer' ) : 'The ZIP archive could not be opened.';
			return $result;
		}

		$total     = (int) $zip->count();
		$index     = isset( $job['tree_validation_zip_index'] ) ? max( 0, (int) $job['tree_validation_zip_index'] ) : 0;
		$limit     = max( 1, (int) $limit );
		$processed = 0;
		while ( $index < $total && $processed < $limit ) {
			if ( class_exists( 'Day_One_Importer_Job_State' ) && Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) ) {
				break;
			}

			$stat = $zip->statIndex( $index );
			if ( ! is_array( $stat ) ) {
				$result['error'] = function_exists( '__' ) ? __( 'The ZIP archive could not be inspected safely.', 'day-one-importer' ) : 'The ZIP archive could not be inspected safely.';
				$zip->close();
				return $result;
			}
			$name = isset( $stat['name'] ) ? (string) $stat['name'] : '';
			if ( ! self::is_safe_relative_archive_name( $name ) ) {
				$result['error'] = sprintf(
					/* translators: %s: archive entry. */
					function_exists( '__' ) ? __( 'Unsafe archive path rejected: %s', 'day-one-importer' ) : 'Unsafe archive path rejected: %s',
					$name
				);
				$zip->close();
				return $result;
			}

			$relative = trim( str_replace( '\\', '/', $name ), '/' );
			if ( '' !== $relative ) {
				$path = self::archive_relative_path( $root, $relative );
				if ( '' === $path || ! self::exists( $path ) || ! self::path_is_inside( $path, $root ) ) {
					$result['error'] = function_exists( '__' ) ? __( 'The archive extracted a file outside the temporary directory.', 'day-one-importer' ) : 'The archive extracted a file outside the temporary directory.';
					$zip->close();
					return $result;
				}
			}

			++$index;
			++$processed;
			$job['tree_validation_zip_index'] = $index;
		}

		$zip->close();
		$result['done'] = $index >= $total;

		return $result;
	}

	/**
	 * Prepare an extraction directory for a resumable job.
	 *
	 * @param string $extract_dir Extraction directory.
	 * @return bool
	 */
	public static function initialize_extract_directory( $extract_dir ) {
		if ( '' === (string) $extract_dir ) {
			return false;
		}

		self::make_directory( $extract_dir );

		if ( ! self::is_dir( $extract_dir ) ) {
			return false;
		}

		self::set_owner_only_permissions( $extract_dir );
		self::protect_directory( $extract_dir );

		return true;
	}

	/**
	 * Return ZIP member count for chunked processing.
	 *
	 * @param string $zip_path ZIP path.
	 * @return int|string Count, or safe error message.
	 */
	public static function zip_total( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return function_exists( '__' ) ? __( 'Chunked Day One imports require the PHP ZipArchive extension.', 'day-one-importer' ) : 'Chunked Day One imports require the PHP ZipArchive extension.';
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return function_exists( '__' ) ? __( 'The ZIP archive could not be opened.', 'day-one-importer' ) : 'The ZIP archive could not be opened.';
		}

		$total = (int) $zip->count();
		$zip->close();

		return $total;
	}

	/**
	 * Validate a bounded number of ZIP members.
	 *
	 * @param string $zip_path ZIP path.
	 * @param int    $start_index Current cursor.
	 * @param int    $limit Maximum members this call.
	 * @param float  $deadline Deadline timestamp.
	 * @param array  $budget_state Existing ZIP budget state.
	 * @return array<string,mixed>
	 */
	public static function preflight_zip_batch( $zip_path, $start_index, $limit, $deadline, $budget_state = array() ) {
		$result = array(
			'done'                 => false,
			'next_index'           => max( 0, (int) $start_index ),
			'total'                => 0,
			'error'                => '',
			'json_candidates'      => array(),
			'photo_dirs'           => array(),
			'uncompressed_total'   => isset( $budget_state['zip_uncompressed_total'] ) ? max( 0, (int) $budget_state['zip_uncompressed_total'] ) : 0,
			'compressed_total'     => isset( $budget_state['zip_compressed_total'] ) ? max( 0, (int) $budget_state['zip_compressed_total'] ) : 0,
			'largest_member_bytes' => isset( $budget_state['zip_largest_member_bytes'] ) ? max( 0, (int) $budget_state['zip_largest_member_bytes'] ) : 0,
		);

		if ( ! class_exists( 'ZipArchive' ) ) {
			$result['error'] = function_exists( '__' ) ? __( 'Chunked Day One imports require the PHP ZipArchive extension.', 'day-one-importer' ) : 'Chunked Day One imports require the PHP ZipArchive extension.';
			return $result;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			$result['error'] = function_exists( '__' ) ? __( 'The ZIP archive could not be opened.', 'day-one-importer' ) : 'The ZIP archive could not be opened.';
			return $result;
		}

		$total           = (int) $zip->count();
		$result['total'] = $total;
		$count_error     = self::zip_member_count_error( $total );
		if ( '' !== $count_error ) {
			$result['error'] = $count_error;
			$zip->close();
			return $result;
		}

		$index     = max( 0, (int) $start_index );
		$limit     = max( 1, (int) $limit );
		$processed = 0;

		while ( $index < $total && $processed < $limit ) {
			if ( class_exists( 'Day_One_Importer_Job_State' ) && Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) ) {
				break;
			}

			$stat = $zip->statIndex( $index );
			if ( ! is_array( $stat ) ) {
				$result['error'] = function_exists( '__' ) ? __( 'The ZIP archive could not be inspected safely.', 'day-one-importer' ) : 'The ZIP archive could not be inspected safely.';
				$zip->close();
				return $result;
			}
			$name = isset( $stat['name'] ) ? (string) $stat['name'] : '';
			if ( ! self::is_safe_relative_archive_name( $name ) ) {
				$result['error'] = sprintf(
					/* translators: %s: archive entry. */
					function_exists( '__' ) ? __( 'Unsafe archive path rejected: %s', 'day-one-importer' ) : 'Unsafe archive path rejected: %s',
					$name
				);
				$zip->close();
				return $result;
			}

			$opsys = 0;
			$attr  = 0;
			if ( method_exists( $zip, 'getExternalAttributesIndex' ) && $zip->getExternalAttributesIndex( $index, $opsys, $attr ) ) {
				$file_type = ( $attr >> 16 ) & 0170000;
				if ( 0120000 === $file_type ) {
					$result['error'] = sprintf(
						/* translators: %s: archive entry. */
						function_exists( '__' ) ? __( 'Symlink archive entry rejected: %s', 'day-one-importer' ) : 'Symlink archive entry rejected: %s',
						$name
					);
					$zip->close();
					return $result;
				}
			}

			$metadata = self::archive_member_import_metadata( $name );
			if ( ! empty( $metadata['json'] ) ) {
				$result['json_candidates'][] = $metadata['json'];
			}
			if ( ! empty( $metadata['photo_dir'] ) ) {
				$result['photo_dirs'][] = $metadata['photo_dir'];
			}

			$result = array_merge( $result, self::accumulate_zip_budget_state( $result, $stat ) );
			$error  = self::zip_budget_error( $name, $total, $result );
			if ( '' !== $error ) {
				$result['error'] = $error;
				$zip->close();
				return $result;
			}

			++$index;
			++$processed;
			$result['next_index'] = $index;
		}

		$result['done']            = $index >= $total;
		$result['json_candidates'] = array_values( array_unique( $result['json_candidates'] ) );
		$result['photo_dirs']      = array_values( array_unique( $result['photo_dirs'] ) );
		$zip->close();

		return $result;
	}

	/**
	 * Extract import-relevant metadata from a safe archive member path.
	 *
	 * @param string $name Archive member name.
	 * @return array{json:string,photo_dir:string}
	 */
	private static function archive_member_import_metadata( $name ) {
		$normalized = trim( str_replace( '\\', '/', (string) $name ), '/' );
		$metadata   = array(
			'json'      => '',
			'photo_dir' => '',
		);
		if ( '' === $normalized ) {
			return $metadata;
		}

		if ( '/' !== substr( $name, -1 ) && 'json' === strtolower( pathinfo( $normalized, PATHINFO_EXTENSION ) ) ) {
			$metadata['json'] = $normalized;
		}

		$parts = explode( '/', $normalized );
		foreach ( $parts as $index => $part ) {
			if ( 'photos' === strtolower( $part ) ) {
				$metadata['photo_dir'] = implode( '/', array_slice( $parts, 0, $index + 1 ) );
				break;
			}
		}

		return $metadata;
	}

	/**
	 * Extract a bounded number of ZIP members.
	 *
	 * @param string $zip_path ZIP path.
	 * @param string $extract_dir Extraction directory.
	 * @param int    $start_index Current cursor.
	 * @param int    $limit Maximum members this call.
	 * @param float  $deadline Deadline timestamp.
	 * @param array  $budget_state Existing extraction budget state.
	 * @return array<string,mixed>
	 */
	public static function extract_zip_batch( $zip_path, $extract_dir, $start_index, $limit, $deadline, $budget_state = array() ) {
		$result = array(
			'done'                         => false,
			'next_index'                   => max( 0, (int) $start_index ),
			'total'                        => 0,
			'error'                        => '',
			'extracted_uncompressed_total' => isset( $budget_state['extract_uncompressed_total'] ) ? max( 0, (int) $budget_state['extract_uncompressed_total'] ) : 0,
		);

		if ( ! self::initialize_extract_directory( $extract_dir ) ) {
			$result['error'] = function_exists( '__' ) ? __( 'The extraction directory could not be prepared.', 'day-one-importer' ) : 'The extraction directory could not be prepared.';
			return $result;
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			$result['error'] = function_exists( '__' ) ? __( 'Chunked Day One imports require the PHP ZipArchive extension.', 'day-one-importer' ) : 'Chunked Day One imports require the PHP ZipArchive extension.';
			return $result;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			$result['error'] = function_exists( '__' ) ? __( 'The ZIP archive could not be opened.', 'day-one-importer' ) : 'The ZIP archive could not be opened.';
			return $result;
		}

		$total           = (int) $zip->count();
		$result['total'] = $total;
		$count_error     = self::zip_member_count_error( $total );
		if ( '' !== $count_error ) {
			$result['error'] = $count_error;
			$zip->close();
			return $result;
		}

		$index     = max( 0, (int) $start_index );
		$limit     = max( 1, (int) $limit );
		$processed = 0;

		while ( $index < $total && $processed < $limit ) {
			if ( class_exists( 'Day_One_Importer_Job_State' ) && Day_One_Importer_Job_State::should_pause_for_deadline( $deadline ) ) {
				break;
			}

			$stat = $zip->statIndex( $index );
			$name = isset( $stat['name'] ) ? (string) $stat['name'] : '';
			if ( ! self::is_safe_relative_archive_name( $name ) ) {
				$result['error'] = sprintf(
					/* translators: %s: archive entry. */
					function_exists( '__' ) ? __( 'Unsafe archive path rejected: %s', 'day-one-importer' ) : 'Unsafe archive path rejected: %s',
					$name
				);
				$zip->close();
				return $result;
			}

			$member_size                             = isset( $stat['size'] ) ? max( 0, (int) $stat['size'] ) : 0;
			$result['extracted_uncompressed_total'] += $member_size;
			$error                                   = self::extraction_budget_error( $name, $member_size, $result['extracted_uncompressed_total'] );
			if ( '' !== $error ) {
				$result['error'] = $error;
				$zip->close();
				return $result;
			}

			if ( '' !== $name && ! $zip->extractTo( $extract_dir, array( $name ) ) ) {
				$result['error'] = function_exists( '__' ) ? __( 'The ZIP archive could not be extracted.', 'day-one-importer' ) : 'The ZIP archive could not be extracted.';
				$zip->close();
				return $result;
			}

			++$index;
			++$processed;
			$result['next_index'] = $index;
		}

		$result['done'] = $index >= $total;
		$zip->close();
		self::protect_directory( $extract_dir );

		return $result;
	}

	/**
	 * Return ZIP safety budgets.
	 *
	 * @return array<string,int|float>
	 */
	private static function zip_budget_limits() {
		$gb = 1024 * 1024 * 1024;

		return array(
			'max_member_count'       => self::filtered_positive_int( 'day_one_importer_zip_max_member_count', 20000 ),
			'max_total_uncompressed' => self::filtered_positive_int( 'day_one_importer_zip_max_uncompressed_bytes', 2 * $gb ),
			'max_member_bytes'       => self::filtered_positive_int( 'day_one_importer_zip_max_member_bytes', 512 * 1024 * 1024 ),
			'max_compression_ratio'  => self::filtered_positive_float( 'day_one_importer_zip_max_compression_ratio', 100.0 ),
		);
	}

	/**
	 * Apply a positive integer filter.
	 *
	 * @param string $filter Filter name.
	 * @param int    $default_value Default value.
	 * @return int
	 */
	private static function filtered_positive_int( $filter, $default_value ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- All call sites pass day_one_importer_-prefixed hook names.
		$value = function_exists( 'apply_filters' ) ? apply_filters( $filter, $default_value ) : $default_value;

		return max( 1, (int) $value );
	}

	/**
	 * Apply a positive float filter.
	 *
	 * @param string $filter Filter name.
	 * @param float  $default_value Default value.
	 * @return float
	 */
	private static function filtered_positive_float( $filter, $default_value ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- All call sites pass day_one_importer_-prefixed hook names.
		$value = function_exists( 'apply_filters' ) ? apply_filters( $filter, $default_value ) : $default_value;

		return max( 1.0, (float) $value );
	}

	/**
	 * Accumulate ZIP size state from a member stat.
	 *
	 * @param array<string,mixed> $state Budget state.
	 * @param array<string,mixed> $stat ZIP member stat.
	 * @return array<string,int>
	 */
	private static function accumulate_zip_budget_state( $state, $stat ) {
		$size = isset( $stat['size'] ) ? max( 0, (int) $stat['size'] ) : 0;
		$comp = isset( $stat['comp_size'] ) ? max( 0, (int) $stat['comp_size'] ) : 0;

		return array(
			'uncompressed_total'   => ( isset( $state['uncompressed_total'] ) ? max( 0, (int) $state['uncompressed_total'] ) : 0 ) + $size,
			'compressed_total'     => ( isset( $state['compressed_total'] ) ? max( 0, (int) $state['compressed_total'] ) : 0 ) + $comp,
			'largest_member_bytes' => max( isset( $state['largest_member_bytes'] ) ? max( 0, (int) $state['largest_member_bytes'] ) : 0, $size ),
		);
	}

	/**
	 * Return a ZIP member count error if the archive exceeds budget.
	 *
	 * @param int $member_count ZIP member count.
	 * @return string Error message, or empty string.
	 */
	private static function zip_member_count_error( $member_count ) {
		$limits = self::zip_budget_limits();
		if ( (int) $member_count > (int) $limits['max_member_count'] ) {
			return function_exists( '__' ) ? __( 'The ZIP archive contains too many files for a safe import.', 'day-one-importer' ) : 'The ZIP archive contains too many files for a safe import.';
		}

		return '';
	}

	/**
	 * Return a ZIP budget error if accumulated preflight state exceeds budget.
	 *
	 * @param string              $name Archive member name.
	 * @param int                 $member_count ZIP member count.
	 * @param array<string,mixed> $state Budget state.
	 * @return string Error message, or empty string.
	 */
	private static function zip_budget_error( $name, $member_count, $state ) {
		$count_error = self::zip_member_count_error( $member_count );
		if ( '' !== $count_error ) {
			return $count_error;
		}

		$limits       = self::zip_budget_limits();
		$uncompressed = isset( $state['uncompressed_total'] ) ? max( 0, (int) $state['uncompressed_total'] ) : 0;
		$compressed   = isset( $state['compressed_total'] ) ? max( 0, (int) $state['compressed_total'] ) : 0;
		$largest      = isset( $state['largest_member_bytes'] ) ? max( 0, (int) $state['largest_member_bytes'] ) : 0;

		if ( $largest > (int) $limits['max_member_bytes'] ) {
			return sprintf(
				/* translators: %s: archive entry. */
				function_exists( '__' ) ? __( 'The ZIP archive contains an oversized file and was rejected: %s', 'day-one-importer' ) : 'The ZIP archive contains an oversized file and was rejected: %s',
				$name
			);
		}

		if ( $uncompressed > (int) $limits['max_total_uncompressed'] ) {
			return function_exists( '__' ) ? __( 'The ZIP archive expands to more data than this importer will process safely.', 'day-one-importer' ) : 'The ZIP archive expands to more data than this importer will process safely.';
		}

		if ( $compressed > 0 && ( $uncompressed / $compressed ) > (float) $limits['max_compression_ratio'] ) {
			return function_exists( '__' ) ? __( 'The ZIP archive compression ratio is too high for a safe import.', 'day-one-importer' ) : 'The ZIP archive compression ratio is too high for a safe import.';
		}

		return '';
	}

	/**
	 * Return an extraction budget error if extracted state exceeds budget.
	 *
	 * @param string $name Archive member name.
	 * @param int    $member_size Current member uncompressed size.
	 * @param int    $extracted_total Total extracted bytes.
	 * @return string Error message, or empty string.
	 */
	private static function extraction_budget_error( $name, $member_size, $extracted_total ) {
		$limits = self::zip_budget_limits();
		if ( (int) $member_size > (int) $limits['max_member_bytes'] ) {
			return sprintf(
				/* translators: %s: archive entry. */
				function_exists( '__' ) ? __( 'The ZIP archive contains an oversized file and was rejected: %s', 'day-one-importer' ) : 'The ZIP archive contains an oversized file and was rejected: %s',
				$name
			);
		}
		if ( (int) $extracted_total > (int) $limits['max_total_uncompressed'] ) {
			return function_exists( '__' ) ? __( 'The ZIP archive expands to more data than this importer will process safely.', 'day-one-importer' ) : 'The ZIP archive expands to more data than this importer will process safely.';
		}

		return '';
	}

	/**
	 * Recursively remove a file/directory.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	public static function remove( $path ) {
		if ( empty( $path ) || ! self::exists( $path ) ) {
			return;
		}

		self::delete_path( $path );
	}
}
