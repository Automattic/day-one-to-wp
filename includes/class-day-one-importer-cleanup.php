<?php
/**
 * Temporary file and archive safety helpers.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'DAY_ONE_IMPORTER_TESTING' ) ) {
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
		$base = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir() . DIRECTORY_SEPARATOR;
		$base = trailingslashit( $base ) . 'day-one-importer';
		$run  = $base . DIRECTORY_SEPARATOR . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 8, false, false );

		if ( ! function_exists( 'wp_mkdir_p' ) || ! wp_mkdir_p( $run ) ) {
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
	 * Restrict a file or directory to owner-only permissions.
	 *
	 * @param string $path File or directory path.
	 * @return bool True when permissions were applied.
	 */
	public static function set_owner_only_permissions( $path ) {
		if ( empty( $path ) || ! file_exists( $path ) ) {
			return false;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem || ! is_object( $wp_filesystem ) || ! method_exists( $wp_filesystem, 'chmod' ) ) {
			if ( defined( 'ABSPATH' ) && ! defined( 'DAY_ONE_IMPORTER_TESTING' ) && ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			if ( function_exists( 'WP_Filesystem' ) ) {
				WP_Filesystem();
			}
		}

		if ( ! $wp_filesystem || ! is_object( $wp_filesystem ) || ! method_exists( $wp_filesystem, 'chmod' ) ) {
			return false;
		}

		return (bool) $wp_filesystem->chmod( $path, is_dir( $path ) ? 0700 : 0600, false );
	}

	/**
	 * Add best-effort protection files to a temp directory.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	public static function protect_directory( $dir ) {
		if ( ! is_dir( $dir ) || ( function_exists( 'wp_is_writable' ) && ! wp_is_writable( $dir ) ) ) {
			return;
		}

		$files = array(
			'index.html' => '',
			'.htaccess'  => "Deny from all\nRequire all denied\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);

		foreach ( $files as $name => $contents ) {
			$path = trailingslashit( $dir ) . $name;
			if ( ! file_exists( $path ) ) {
				@file_put_contents( $path, $contents ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
		}
	}

	/**
	 * Safely pre-scan a zip archive when ZipArchive is available.
	 *
	 * @param string $zip_path Zip path.
	 * @return true|string True on success, otherwise error message.
	 */
	public static function preflight_zip( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return true;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return function_exists( '__' ) ? __( 'The ZIP archive could not be opened.', 'day-one-importer' ) : 'The ZIP archive could not be opened.';
		}

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
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
		$root_real = realpath( $root );
		if ( false === $root_real ) {
			return function_exists( '__' ) ? __( 'The extraction directory is invalid.', 'day-one-importer' ) : 'The extraction directory is invalid.';
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root_real, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		$root_prefix = rtrim( $root_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		foreach ( $iterator as $item ) {
			$path = $item->getPathname();
			if ( is_link( $path ) ) {
				return function_exists( '__' ) ? __( 'The archive contains a symlink and was rejected.', 'day-one-importer' ) : 'The archive contains a symlink and was rejected.';
			}

			$real = realpath( $path );
			if ( false === $real || ( $real !== $root_real && 0 !== strpos( $real, $root_prefix ) ) ) {
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

		$root_real = realpath( $root );
		if ( false === $root_real ) {
			$result['error'] = function_exists( '__' ) ? __( 'The extraction directory is invalid.', 'day-one-importer' ) : 'The extraction directory is invalid.';
			return $result;
		}

		$zip_path = isset( $job['zip_path'] ) ? (string) $job['zip_path'] : '';
		if ( '' === $zip_path || ! is_readable( $zip_path ) || ! class_exists( 'ZipArchive' ) ) {
			$result['error'] = function_exists( '__' ) ? __( 'The extracted tree could not be validated from the ZIP manifest.', 'day-one-importer' ) : 'The extracted tree could not be validated from the ZIP manifest.';
			return $result;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			$result['error'] = function_exists( '__' ) ? __( 'The ZIP archive could not be opened.', 'day-one-importer' ) : 'The ZIP archive could not be opened.';
			return $result;
		}

		$total       = (int) $zip->numFiles;
		$index       = isset( $job['tree_validation_zip_index'] ) ? max( 0, (int) $job['tree_validation_zip_index'] ) : 0;
		$limit       = max( 1, (int) $limit );
		$processed   = 0;
		$root_prefix = rtrim( $root_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;

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

			$relative = trim( str_replace( '\\', '/', $name ), '/' );
			if ( '' !== $relative ) {
				$path = $root_real . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
				if ( is_link( $path ) ) {
					$result['error'] = function_exists( '__' ) ? __( 'The archive contains a symlink and was rejected.', 'day-one-importer' ) : 'The archive contains a symlink and was rejected.';
					$zip->close();
					return $result;
				}

				$real = realpath( $path );
				if ( false === $real || ( $real !== $root_real && 0 !== strpos( $real, $root_prefix ) ) ) {
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

		if ( function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $extract_dir );
		} elseif ( ! is_dir( $extract_dir ) ) {
			@mkdir( $extract_dir, 0700, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}

		if ( ! is_dir( $extract_dir ) ) {
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

		$total = (int) $zip->numFiles;
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
	 * @return array<string,mixed>
	 */
	public static function preflight_zip_batch( $zip_path, $start_index, $limit, $deadline ) {
		$result = array(
			'done'            => false,
			'next_index'      => max( 0, (int) $start_index ),
			'total'           => 0,
			'error'           => '',
			'json_candidates' => array(),
			'photo_dirs'      => array(),
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

		$total           = (int) $zip->numFiles;
		$result['total'] = $total;
		$index           = max( 0, (int) $start_index );
		$limit           = max( 1, (int) $limit );
		$processed       = 0;

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
	 * @return array<string,mixed>
	 */
	public static function extract_zip_batch( $zip_path, $extract_dir, $start_index, $limit, $deadline ) {
		$result = array(
			'done'       => false,
			'next_index' => max( 0, (int) $start_index ),
			'total'      => 0,
			'error'      => '',
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

		$total           = (int) $zip->numFiles;
		$result['total'] = $total;
		$index           = max( 0, (int) $start_index );
		$limit           = max( 1, (int) $limit );
		$processed       = 0;

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
	 * Recursively remove a file/directory.
	 *
	 * @param string $path Path.
	 * @return void
	 */
	public static function remove( $path ) {
		if ( empty( $path ) || ! file_exists( $path ) ) {
			return;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() && ! $item->isLink() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}

		@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
