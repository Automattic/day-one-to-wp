<?php
/**
 * Media resolution and import.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'DAY_ONE_IMPORTER_TESTING' ) ) {
	exit;
}

/**
 * Imports and reuses Day One photos.
 */
class Day_One_Importer_Media {
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
	 * Constructor.
	 *
	 * @param string                   $root Extraction root.
	 * @param Day_One_Importer_Results $results Results.
	 */
	public function __construct( $root, Day_One_Importer_Results $results ) {
		$this->root    = $root;
		$this->results = $results;
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
		$photos = $this->sort_photos( $photos );

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
	private function sort_photos( $photos ) {
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
	private function import_or_reuse_photo( $photo, $entry, $post_id ) {
		$uuid       = isset( $entry['uuid'] ) ? (string) $entry['uuid'] : '';
		$identifier = isset( $photo['identifier'] ) ? (string) $photo['identifier'] : '';
		$md5        = isset( $photo['md5'] ) ? (string) $photo['md5'] : '';
		$label      = $identifier ? $identifier : ( $md5 ? $md5 : ( isset( $photo['filename'] ) ? (string) $photo['filename'] : 'unknown' ) );

		$existing = $this->find_existing_attachment( $post_id, $uuid, $identifier, $md5 );
		if ( $existing ) {
			$this->results->increment( 'media_reused' );
			return $existing;
		}

		$source = self::resolve_photo_path( $this->root, $photo );
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

		$attachment_id = $this->sideload_media( $source, $photo, $post_id );
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

		$this->results->increment( 'media_imported' );
		return $attachment_id;
	}

	/**
	 * Resolve a Day One photo to an exported file path.
	 *
	 * @param string              $root Extraction root.
	 * @param array<string,mixed> $photo Photo metadata.
	 * @return string Empty if unresolved.
	 */
	public static function resolve_photo_path( $root, $photo ) {
		$root_real = realpath( $root );
		if ( false === $root_real ) {
			return '';
		}

		$photo_dirs = self::find_photo_dirs( $root_real );
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
	private function find_existing_attachment( $post_id, $uuid, $identifier, $md5 ) {
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
	 * Sideload a media file into the Media Library.
	 *
	 * @param string              $source Source path.
	 * @param array<string,mixed> $photo Photo metadata.
	 * @param int                 $post_id Post ID.
	 * @return int Attachment ID or 0.
	 */
	private function sideload_media( $source, $photo, $post_id ) {
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

		$attachment_id = media_handle_sideload( $file_array, $post_id );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return 0;
		}

		return (int) $attachment_id;
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
