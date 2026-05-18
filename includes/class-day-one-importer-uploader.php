<?php
/**
 * Upload handling for Day One import ZIP files.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates and moves uploaded Day One ZIP files into protected run directories.
 */
class Day_One_Importer_Uploader {
	/**
	 * Validate and move uploaded ZIP into the protected run directory.
	 *
	 * @param array<string,mixed>      $file Upload file array.
	 * @param string                   $run_dir Run directory.
	 * @param Day_One_Importer_Results $results Results.
	 * @return string Empty on failure.
	 */
	public function handle_upload( $file, $run_dir, Day_One_Importer_Results $results ) {
		if ( empty( $file ) || empty( $file['tmp_name'] ) || ! isset( $file['error'] ) ) {
			$results->add_error( __( 'No ZIP file was uploaded.', 'day-one-importer' ) );
			return '';
		}

		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$results->add_error(
				sprintf(
					/* translators: %d: PHP file upload error code. */
					__( 'The upload failed with PHP upload error code %d.', 'day-one-importer' ),
					(int) $file['error']
				)
			);
			return '';
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		if ( 'zip' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			$results->add_error( __( 'Only Day One ZIP exports are supported.', 'day-one-importer' ) );
			return '';
		}

		$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		if ( ! is_uploaded_file( $tmp_name ) ) {
			$results->add_error( __( 'The uploaded ZIP file could not be verified.', 'day-one-importer' ) );
			return '';
		}

		$zip_type = wp_check_filetype( $name, array( 'zip' => 'application/zip' ) );
		if ( 'zip' !== strtolower( (string) $zip_type['ext'] ) ) {
			$results->add_error( __( 'Only Day One ZIP exports are supported.', 'day-one-importer' ) );
			return '';
		}

		if ( ! function_exists( 'wp_handle_upload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload_dir_filter = static function ( $dirs ) use ( $run_dir ) {
			$run_dir = untrailingslashit( $run_dir );

			$dirs['path']    = $run_dir;
			$dirs['basedir'] = $run_dir;
			$dirs['subdir']  = '';
			$dirs['url']     = '';
			$dirs['baseurl'] = '';
			$dirs['error']   = false;

			return $dirs;
		};

		add_filter( 'upload_dir', $upload_dir_filter );
		try {
			$uploaded = wp_handle_upload(
				$file,
				array(
					'test_form'                => false,
					'mimes'                    => array( 'zip' => 'application/zip' ),
					'unique_filename_callback' => static function () {
						return 'day-one-export.zip';
					},
				)
			);
		} finally {
			remove_filter( 'upload_dir', $upload_dir_filter );
		}

		if ( empty( $uploaded['file'] ) || ! empty( $uploaded['error'] ) ) {
			$results->add_error( __( 'The uploaded ZIP file could not be moved into the protected import directory.', 'day-one-importer' ) );
			return '';
		}

		$target = (string) $uploaded['file'];
		if ( ! Day_One_Importer_Cleanup::set_owner_only_permissions( $target ) ) {
			wp_delete_file( $target );
			$results->add_error( __( 'The uploaded ZIP file could not be secured in the protected import directory.', 'day-one-importer' ) );
			return '';
		}

		return $target;
	}
}
