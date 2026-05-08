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

		self::protect_directory( $base );
		self::protect_directory( $run );

		return $run;
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
