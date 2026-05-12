<?php
/**
 * Media resolution and import.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	if ( ! defined( 'DAY_ONE_IMPORTER_TESTING' ) ) {
		exit;
	}
}

/**
 * Imports and reuses Day One photos.
 */
class Day_One_Importer_Media {
	/**
	 * Private uploads subdirectory for Day One media.
	 *
	 * @var string
	 */
	const PRIVATE_UPLOAD_SUBDIR = 'day-one-importer-private';

	/**
	 * AJAX action used to serve protected Day One media.
	 *
	 * @var string
	 */
	const PRIVATE_MEDIA_ACTION = 'day_one_importer_media';

	/**
	 * Extraction root.
	 *
	 * @var string
	 */
	private $root;

	/**
	 * Results.
	 *
	 * @var Day_One_Importer_Results
	 */
	private $results;

	/**
	 * Cached photo directories for async jobs, or null to discover synchronously.
	 *
	 * @var string[]|null
	 */
	private $photo_dirs = null;

	/**
	 * Constructor.
	 *
	 * @param string                   $root Extraction root.
	 * @param Day_One_Importer_Results $results Results.
	 * @param string[]|null            $photo_dirs Cached photo directories, or null to discover.
	 */
	public function __construct( $root, Day_One_Importer_Results $results, $photo_dirs = null ) {
		$this->root       = $root;
		$this->results    = $results;
		$this->photo_dirs = is_array( $photo_dirs ) ? array_values( array_filter( array_map( 'strval', $photo_dirs ) ) ) : null;
	}

	/**
	 * Import all photos for an entry.
	 *
	 * @param array<string,mixed> $entry Entry.
	 * @param int                 $post_id Post ID.
	 * @return int[] Attachment IDs in entry order.
	 */
	public function import_entry_photos( $entry, $post_id ) {
		$photos = isset( $entry['photos'] ) && is_array( $entry['photos'] ) ? $entry['photos'] : array();
		if ( empty( $photos ) ) {
			return array();
		}

		$this->results->increment( 'media_found', count( $photos ) );
		$photos = self::sort_photos( $photos );

		$attachment_ids = array();
		foreach ( $photos as $photo ) {
			$attachment_id = $this->import_or_reuse_photo( $photo, $entry, $post_id );
			if ( $attachment_id ) {
				$attachment_ids[] = $attachment_id;
			}
		}

		return $attachment_ids;
	}

	/**
	 * Sort photos by orderInEntry.
	 *
	 * @param array<int,array<string,mixed>> $photos Photos.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sort_photos( $photos ) {
		foreach ( $photos as $index => &$photo ) {
			$photo['_original_index'] = $index;
		}
		unset( $photo );

		usort(
			$photos,
			static function ( $a, $b ) {
				$a_order = isset( $a['orderInEntry'] ) && null !== $a['orderInEntry'] ? (int) $a['orderInEntry'] : PHP_INT_MAX;
				$b_order = isset( $b['orderInEntry'] ) && null !== $b['orderInEntry'] ? (int) $b['orderInEntry'] : PHP_INT_MAX;
				if ( $a_order === $b_order ) {
					return (int) $a['_original_index'] <=> (int) $b['_original_index'];
				}

				return $a_order <=> $b_order;
			}
		);

		return $photos;
	}

	/**
	 * Import or reuse one photo.
	 *
	 * @param array<string,mixed> $photo Photo.
	 * @param array<string,mixed> $entry Entry.
	 * @param int                 $post_id Post ID.
	 * @return int Attachment ID, or 0.
	 */
	public function import_or_reuse_photo( $photo, $entry, $post_id ) {
		$uuid       = isset( $entry['uuid'] ) ? (string) $entry['uuid'] : '';
		$identifier = isset( $photo['identifier'] ) ? (string) $photo['identifier'] : '';
		$md5        = isset( $photo['md5'] ) ? (string) $photo['md5'] : '';
		$label      = $identifier ? $identifier : ( $md5 ? $md5 : ( isset( $photo['filename'] ) ? (string) $photo['filename'] : 'unknown' ) );

		$existing = $this->find_existing_attachment( $post_id, $uuid, $identifier, $md5 );
		if ( $existing ) {
			$this->results->increment( 'media_reused' );
			return $existing;
		}

		$source = self::resolve_photo_path( $this->root, $photo, $this->photo_dirs );
		if ( ! $source ) {
			$this->results->increment( 'media_missing' );
			$this->results->add_warning(
				sprintf(
					/* translators: 1: Day One entry UUID, 2: media identifier, hash, or filename. */
					__( 'Media missing for UUID %1$s: %2$s', 'day-one-importer' ),
					$uuid,
					$label
				)
			);
			return 0;
		}

		$partial = $this->find_partial_attachment_by_source( $post_id, $uuid, $photo, $source );
		if ( $partial ) {
			$this->apply_photo_marker_metadata( $partial, $uuid, $identifier, $md5, $photo );
			$this->results->increment( 'media_reused' );
			return $partial;
		}

		$valid = $this->validate_media_file( $source );
		if ( true !== $valid ) {
			$this->results->increment( 'media_unsupported' );
			$this->results->add_warning(
				sprintf(
					/* translators: 1: Day One entry UUID, 2: media identifier, hash, or filename. */
					__( 'Unsupported media skipped for UUID %1$s: %2$s', 'day-one-importer' ),
					$uuid,
					$label
				)
			);
			return 0;
		}

		$attachment_id = $this->sideload_media( $source, $photo, $entry, $post_id );
		if ( ! $attachment_id ) {
			$this->results->increment( 'media_failed' );
			$this->results->add_warning(
				sprintf(
					/* translators: 1: Day One entry UUID, 2: media identifier, hash, or filename. */
					__( 'Media import failed for UUID %1$s: %2$s', 'day-one-importer' ),
					$uuid,
					$label
				)
			);
			return 0;
		}

		$this->apply_photo_marker_metadata( $attachment_id, $uuid, $identifier, $md5, $photo );
		$this->results->increment( 'media_imported' );
		return $attachment_id;
	}

	/**
	 * Resolve a Day One photo to an exported file path.
	 *
	 * @param string              $root Extraction root.
	 * @param array<string,mixed> $photo Photo metadata.
	 * @param string[]|null       $photo_dirs Cached photo directories, or null to discover.
	 * @return string Empty if unresolved.
	 */
	public static function resolve_photo_path( $root, $photo, $photo_dirs = null ) {
		$root_real = realpath( $root );
		if ( false === $root_real ) {
			return '';
		}

		if ( null !== $photo_dirs ) {
			$photo_dirs = self::sanitize_photo_dirs( $root_real, $photo_dirs );
		} else {
			$photo_dirs = self::find_photo_dirs( $root_real );
		}
		if ( empty( $photo_dirs ) ) {
			return '';
		}

		$candidates = array();
		$md5        = isset( $photo['md5'] ) ? strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) $photo['md5'] ) ) : '';
		$type       = isset( $photo['type'] ) ? strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $photo['type'] ) ) : '';
		$filename   = isset( $photo['filename'] ) ? basename( (string) $photo['filename'] ) : '';

		if ( $md5 ) {
			$extensions = self::candidate_extensions( $type );
			foreach ( $extensions as $extension ) {
				$candidates[] = $md5 . '.' . $extension;
			}
		}

		if ( $filename ) {
			$candidates[] = $filename;
		}

		$root_prefix = rtrim( $root_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		foreach ( $photo_dirs as $dir ) {
			foreach ( array_unique( $candidates ) as $candidate ) {
				$path = $dir . DIRECTORY_SEPARATOR . $candidate;
				if ( is_file( $path ) ) {
					$real = realpath( $path );
					if ( $real && ( $real === $root_real || 0 === strpos( $real, $root_prefix ) ) ) {
						return $real;
					}
				}
			}
		}

		return '';
	}

	/**
	 * Sanitize cached photo directories against the extraction root.
	 *
	 * @param string   $root_real Real extraction root.
	 * @param string[] $photo_dirs Cached directories.
	 * @return string[]
	 */
	private static function sanitize_photo_dirs( $root_real, $photo_dirs ) {
		$dirs        = array();
		$root_prefix = rtrim( $root_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		foreach ( (array) $photo_dirs as $dir ) {
			$real = realpath( (string) $dir );
			if ( false === $real || ! is_dir( $real ) ) {
				continue;
			}
			if ( $real === $root_real || 0 === strpos( $real, $root_prefix ) ) {
				$dirs[] = $real;
			}
		}

		return array_values( array_unique( $dirs ) );
	}

	/**
	 * Find photos directories in an export.
	 *
	 * @param string $root Root.
	 * @return string[]
	 */
	public static function find_photo_dirs( $root ) {
		$dirs = array();
		if ( ! is_dir( $root ) ) {
			return $dirs;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() && 'photos' === strtolower( $item->getFilename() ) ) {
				$dirs[] = $item->getPathname();
			}
		}

		sort( $dirs );
		return $dirs;
	}

	/**
	 * Candidate extensions for a Day One media type.
	 *
	 * @param string $type Type.
	 * @return string[]
	 */
	private static function candidate_extensions( $type ) {
		$extensions = array();
		if ( $type ) {
			$extensions[] = $type;
		}

		switch ( $type ) {
			case 'jpeg':
				$extensions[] = 'jpg';
				break;
			case 'jpg':
				$extensions[] = 'jpeg';
				break;
			case 'heic':
				$extensions[] = 'jpg';
				$extensions[] = 'jpeg';
				$extensions[] = 'png';
				break;
		}

		$extensions = array_merge( $extensions, array( 'jpeg', 'jpg', 'png', 'gif', 'webp' ) );
		return array_values( array_unique( array_filter( $extensions ) ) );
	}

	/**
	 * Validate media is a WordPress-accepted image type.
	 *
	 * @param string $path Path.
	 * @return true|string True or reason.
	 */
	private function validate_media_file( $path ) {
		if ( ! is_readable( $path ) || filesize( $path ) <= 0 ) {
			return 'unreadable';
		}

		$type = wp_check_filetype_and_ext( $path, basename( $path ) );
		$mime = isset( $type['type'] ) ? (string) $type['type'] : '';
		if ( ! $mime || 0 !== strpos( $mime, 'image/' ) ) {
			return 'unsupported';
		}

		$allowed = get_allowed_mime_types();
		if ( ! in_array( $mime, $allowed, true ) ) {
			return 'mime-not-allowed';
		}

		return true;
	}

	/**
	 * Find an existing attachment tied to this post and Day One entry/media.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $uuid Entry UUID.
	 * @param string $identifier Media identifier.
	 * @param string $md5 Media MD5.
	 * @return int Attachment ID or 0.
	 */
	public function find_existing_attachment( $post_id, $uuid, $identifier, $md5 ) {
		$or = array( 'relation' => 'OR' );
		if ( $identifier ) {
			$or[] = array(
				'key'   => '_day_one_media_identifier',
				'value' => $identifier,
			);
		}
		if ( $md5 ) {
			$or[] = array(
				'key'   => '_day_one_media_md5',
				'value' => $md5,
			);
		}

		if ( count( $or ) <= 1 ) {
			return 0;
		}

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'post_parent'    => $post_id,
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => '_day_one_uuid',
						'value' => $uuid,
					),
					$or,
				),
			)
		);

		return ! empty( $ids ) ? (int) $ids[0] : 0;
	}

	/**
	 * Find and repair a partial attachment created before Day One marker metadata was written.
	 *
	 * @param int                 $post_id Post ID.
	 * @param string              $uuid Entry UUID.
	 * @param array<string,mixed> $photo Photo metadata.
	 * @param string              $source Resolved source file.
	 * @return int Attachment ID or 0.
	 */
	private function find_partial_attachment_by_source( $post_id, $uuid, $photo, $source ) {
		if ( ! $post_id || '' === (string) $source ) {
			return 0;
		}

		$expected = self::build_upload_filename( $source, $photo );
		$expected = sanitize_file_name( basename( $expected ) );
		if ( '' === $expected ) {
			return 0;
		}

		$expected_base = pathinfo( $expected, PATHINFO_FILENAME );
		$expected_ext  = strtolower( pathinfo( $expected, PATHINFO_EXTENSION ) );
		$attachments   = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'post_parent'    => (int) $post_id,
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		foreach ( $attachments as $attachment ) {
			$attachment_id = isset( $attachment->ID ) ? (int) $attachment->ID : 0;
			if ( ! $attachment_id || 'day-one-export' === (string) get_post_meta( $attachment_id, '_day_one_source', true ) ) {
				continue;
			}

			$file = get_attached_file( $attachment_id );
			if ( ! $file ) {
				continue;
			}

			$basename = sanitize_file_name( basename( $file ) );
			$base     = pathinfo( $basename, PATHINFO_FILENAME );
			$ext      = strtolower( pathinfo( $basename, PATHINFO_EXTENSION ) );
			if ( $expected_ext !== $ext ) {
				continue;
			}

			if ( $base === $expected_base || 0 === strpos( $base, $expected_base . '-' ) ) {
				return $attachment_id;
			}
		}

		return 0;
	}

	/**
	 * Apply Day One marker metadata as early as possible after attachment creation.
	 *
	 * @param int                 $attachment_id Attachment ID.
	 * @param string              $uuid Entry UUID.
	 * @param string              $identifier Media identifier.
	 * @param string              $md5 Media MD5.
	 * @param array<string,mixed> $photo Photo metadata.
	 * @return void
	 */
	private function apply_photo_marker_metadata( $attachment_id, $uuid, $identifier, $md5, $photo ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id ) {
			return;
		}

		update_post_meta( $attachment_id, '_day_one_media_identifier', $identifier );
		update_post_meta( $attachment_id, '_day_one_media_md5', $md5 );
		update_post_meta( $attachment_id, '_day_one_uuid', $uuid );
		update_post_meta( $attachment_id, '_day_one_source', 'day-one-export' );
		if ( ! empty( $photo['date'] ) ) {
			update_post_meta( $attachment_id, '_day_one_media_date', day_one_importer_sanitize_text( $photo['date'] ) );
		}
		if ( ! empty( $photo['filename'] ) ) {
			update_post_meta( $attachment_id, '_day_one_original_filename', day_one_importer_sanitize_text( $photo['filename'] ) );
		}
		if ( ! empty( $photo['width'] ) ) {
			update_post_meta( $attachment_id, '_day_one_width', absint( $photo['width'] ) );
		}
		if ( ! empty( $photo['height'] ) ) {
			update_post_meta( $attachment_id, '_day_one_height', absint( $photo['height'] ) );
		}
	}

	/**
	 * Sideload a media file into the Media Library.
	 *
	 * @param string              $source Source path.
	 * @param array<string,mixed> $photo Photo metadata.
	 * @param array<string,mixed> $entry Entry metadata.
	 * @param int                 $post_id Post ID.
	 * @return int Attachment ID or 0.
	 */
	private function sideload_media( $source, $photo, $entry, $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$filename = self::build_upload_filename( $source, $photo );
		$tmp      = wp_tempnam( $filename );
		if ( ! $tmp || ! copy( $source, $tmp ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			if ( $tmp ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
			return 0;
		}

		$file_array = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);

		add_filter( 'upload_dir', array( __CLASS__, 'filter_private_upload_dir' ) );
		add_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'filter_import_image_sizes' ), 10, 3 );
		add_filter( 'big_image_size_threshold', '__return_false' );
		$private_file = '';
		try {
			$attachment_id = media_handle_sideload( $file_array, $post_id );
			if ( ! is_wp_error( $attachment_id ) ) {
				$uuid       = isset( $entry['uuid'] ) ? (string) $entry['uuid'] : '';
				$identifier = isset( $photo['identifier'] ) ? (string) $photo['identifier'] : '';
				$md5        = isset( $photo['md5'] ) ? (string) $photo['md5'] : '';
				$this->apply_photo_marker_metadata( (int) $attachment_id, $uuid, $identifier, $md5, $photo );
				$private_file = get_attached_file( $attachment_id );
			}
		} finally {
			remove_filter( 'upload_dir', array( __CLASS__, 'filter_private_upload_dir' ) );
			remove_filter( 'intermediate_image_sizes_advanced', array( __CLASS__, 'filter_import_image_sizes' ), 10 );
			remove_filter( 'big_image_size_threshold', '__return_false' );
		}

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return 0;
		}

		if ( $private_file && is_readable( $private_file ) ) {
			update_attached_file( $attachment_id, $private_file );
		}

		return (int) $attachment_id;
	}

	/**
	 * Disable generated image sizes during Day One import sideloads.
	 *
	 * WordPress creates thumbnails and other sub-sizes synchronously during
	 * media sideloads. Large journal exports can spend enough time in Imagick
	 * resizing to hit PHP request limits, so importer media keeps only the
	 * original uploaded file and lets WordPress generate sizes later if an
	 * operator explicitly requests that outside the import.
	 *
	 * @param array<string,array<string,int>> $sizes Existing size definitions.
	 * @return array<string,array<string,int>> Empty size list.
	 */
	public static function filter_import_image_sizes( $sizes ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WordPress filter signature.
		return array();
	}

	/**
	 * Route Day One media sideloads into a dedicated private uploads directory.
	 *
	 * @param array<string,mixed> $dirs Upload directory data.
	 * @return array<string,mixed> Filtered upload directory data.
	 */
	public static function filter_private_upload_dir( $dirs ) {
		$baseurl = isset( $dirs['baseurl'] ) ? untrailingslashit( (string) $dirs['baseurl'] ) : '';
		$subdir  = isset( $dirs['subdir'] ) ? (string) $dirs['subdir'] : '';
		$private = self::prepare_private_media_base_dir();

		if ( '' === $baseurl || '' === $private ) {
			$dirs['error'] = __( 'The private media directory could not be prepared.', 'day-one-importer' );
			return $dirs;
		}

		$private_subdir = $subdir;
		$private_path   = untrailingslashit( $private ) . $private_subdir;

		if ( function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $private_path );
		}

		self::protect_private_upload_directory( $private );
		self::protect_private_upload_directory( $private_path );

		if ( ! is_dir( $private_path ) || ! wp_is_writable( $private_path ) ) {
			$dirs['error'] = __( 'The private media directory is not writable.', 'day-one-importer' );
			return $dirs;
		}

		$dirs['basedir'] = untrailingslashit( $private );
		$dirs['baseurl'] = $baseurl . '/' . self::PRIVATE_UPLOAD_SUBDIR;
		$dirs['subdir']  = $private_subdir;
		$dirs['path']    = $private_path;
		$dirs['url']     = $dirs['baseurl'] . $private_subdir;

		return $dirs;
	}

	/**
	 * Prepare the private media base directory.
	 *
	 * @return string Absolute directory path, or empty string on failure.
	 */
	public static function prepare_private_media_base_dir() {
		$dir = self::private_media_base_dir();
		if ( '' === $dir ) {
			return '';
		}

		if ( function_exists( 'wp_mkdir_p' ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}

		if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
			return '';
		}

		self::protect_private_upload_directory( $dir );
		return $dir;
	}

	/**
	 * Return the private media base directory outside the web root where possible.
	 *
	 * @return string Directory path.
	 */
	public static function private_media_base_dir() {
		$candidates = array();
		if ( defined( 'ABSPATH' ) ) {
			$candidates[] = dirname( untrailingslashit( ABSPATH ) ) . DIRECTORY_SEPARATOR . self::PRIVATE_UPLOAD_SUBDIR;
		}
		if ( function_exists( 'get_temp_dir' ) ) {
			$candidates[] = trailingslashit( get_temp_dir() ) . self::PRIVATE_UPLOAD_SUBDIR;
		} else {
			$candidates[] = trailingslashit( sys_get_temp_dir() ) . self::PRIVATE_UPLOAD_SUBDIR;
		}
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$candidates[] = untrailingslashit( WP_CONTENT_DIR ) . DIRECTORY_SEPARATOR . self::PRIVATE_UPLOAD_SUBDIR;
		}

		$default = self::first_writable_private_media_dir( $candidates );
		if ( function_exists( 'apply_filters' ) ) {
			$default = (string) apply_filters( 'day_one_importer_private_media_dir', $default );
		}

		return $default ? untrailingslashit( $default ) : '';
	}

	/**
	 * Pick the first candidate whose parent directory is writable.
	 *
	 * @param string[] $candidates Directory candidates.
	 * @return string Directory path, or the first candidate if none can be proven writable.
	 */
	private static function first_writable_private_media_dir( $candidates ) {
		$first = '';
		foreach ( $candidates as $candidate ) {
			$candidate = untrailingslashit( (string) $candidate );
			if ( '' === $candidate ) {
				continue;
			}
			if ( '' === $first ) {
				$first = $candidate;
			}

			$parent = dirname( $candidate );
			if ( is_dir( $parent ) && wp_is_writable( $parent ) ) {
				return $candidate;
			}
		}

		return $first;
	}

	/**
	 * Add best-effort access-control files to a private upload directory.
	 *
	 * @param string $dir Directory path.
	 * @return void
	 */
	private static function protect_private_upload_directory( $dir ) {
		if ( class_exists( 'Day_One_Importer_Cleanup' ) ) {
			Day_One_Importer_Cleanup::protect_directory( $dir );
		}
	}

	/**
	 * Replace raw upload URLs for Day One media with an authenticated endpoint.
	 *
	 * @param string $url Attachment URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string Filtered URL.
	 */
	public static function filter_attachment_url( $url, $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( ! $attachment_id || ! function_exists( 'get_post_meta' ) || 'day-one-export' !== (string) get_post_meta( $attachment_id, '_day_one_source', true ) ) {
			return $url;
		}

		return self::private_media_url( $attachment_id );
	}

	/**
	 * Build the stable authenticated endpoint URL for a Day One attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string URL.
	 */
	public static function private_media_url( $attachment_id ) {
		if ( ! function_exists( 'admin_url' ) || ! function_exists( 'add_query_arg' ) ) {
			return '';
		}

		return add_query_arg(
			array(
				'action'        => self::PRIVATE_MEDIA_ACTION,
				'attachment_id' => absint( $attachment_id ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Serve protected Day One media to users who can read the associated post.
	 *
	 * @return void
	 */
	public static function serve_private_media() {
		$attachment_id = isset( $_GET['attachment_id'] ) ? absint( wp_unslash( $_GET['attachment_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only media endpoint with capability checks.
		if ( ! $attachment_id || 'day-one-export' !== (string) get_post_meta( $attachment_id, '_day_one_source', true ) ) {
			self::private_media_status( 404 );
		}

		$parent_id = wp_get_post_parent_id( $attachment_id );
		$can_read  = $parent_id ? current_user_can( 'read_post', $parent_id ) : current_user_can( 'read_post', $attachment_id );
		if ( ! $can_read ) {
			self::private_media_status( is_user_logged_in() ? 403 : 401 );
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_readable( $file ) || ! self::is_private_upload_path( $file ) ) {
			self::private_media_status( 404 );
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! $mime || 0 !== strpos( (string) $mime, 'image/' ) ) {
			$type = wp_check_filetype( $file );
			$mime = ! empty( $type['type'] ) ? (string) $type['type'] : 'application/octet-stream';
		}

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Stop a private media request with an HTTP status.
	 *
	 * @param int $status HTTP status.
	 * @return void
	 */
	private static function private_media_status( $status ) {
		status_header( $status );
		nocache_headers();
		exit;
	}

	/**
	 * Confirm a file lives under the Day One private uploads directory.
	 *
	 * @param string $file File path.
	 * @return bool True when private.
	 */
	public static function is_private_upload_path( $file ) {
		$private_root = realpath( self::private_media_base_dir() );
		$file_real    = realpath( $file );
		if ( false === $private_root || false === $file_real ) {
			return false;
		}

		$prefix = rtrim( $private_root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		return $file_real === $private_root || 0 === strpos( $file_real, $prefix );
	}

	/**
	 * Build a safe sideload filename whose extension matches the resolved file.
	 *
	 * Day One exports can reference an original HEIC filename while including a
	 * WordPress-compatible JPEG derivative on disk. The sideload name must match
	 * the actual source extension so WordPress validates and stores the file
	 * consistently; the original Day One filename is preserved separately in meta.
	 *
	 * @param string              $source Source path.
	 * @param array<string,mixed> $photo Photo metadata.
	 * @return string Safe upload filename.
	 */
	public static function build_upload_filename( $source, $photo ) {
		$source_basename = sanitize_file_name( basename( $source ) );
		$source_ext      = strtolower( pathinfo( $source_basename, PATHINFO_EXTENSION ) );
		$original        = ! empty( $photo['filename'] ) ? sanitize_file_name( basename( (string) $photo['filename'] ) ) : '';
		$base            = $original ? pathinfo( $original, PATHINFO_FILENAME ) : pathinfo( $source_basename, PATHINFO_FILENAME );
		$base            = sanitize_file_name( $base );

		if ( '' === $base ) {
			$base = 'day-one-media';
		}

		if ( '' === $source_ext ) {
			return $source_basename ? $source_basename : $base;
		}

		return $base . '.' . $source_ext;
	}
}
