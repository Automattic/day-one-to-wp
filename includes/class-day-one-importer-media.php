<?php
/**
 * Media resolution and import.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
	 * Cached video directories for async jobs, or null to discover synchronously.
	 *
	 * @var string[]|null
	 */
	private $video_dirs = null;

	/**
	 * Cached audio directories for async jobs, or null to discover synchronously.
	 *
	 * @var string[]|null
	 */
	private $audio_dirs = null;

	/**
	 * Cached pdf directories for async jobs, or null to discover synchronously.
	 *
	 * @var string[]|null
	 */
	private $pdf_dirs = null;

	/**
	 * Constructor.
	 *
	 * @param string                   $root Extraction root.
	 * @param Day_One_Importer_Results $results Results.
	 * @param string[]|null            $photo_dirs Cached photo directories, or null to discover.
	 * @param string[]|null            $video_dirs Cached video directories, or null to discover.
	 * @param string[]|null            $audio_dirs Cached audio directories, or null to discover.
	 * @param string[]|null            $pdf_dirs   Cached pdf directories, or null to discover.
	 */
	public function __construct( $root, Day_One_Importer_Results $results, $photo_dirs = null, $video_dirs = null, $audio_dirs = null, $pdf_dirs = null ) {
		$this->root       = $root;
		$this->results    = $results;
		$this->photo_dirs = is_array( $photo_dirs ) ? array_values( array_filter( array_map( 'strval', $photo_dirs ) ) ) : null;
		$this->video_dirs = is_array( $video_dirs ) ? array_values( array_filter( array_map( 'strval', $video_dirs ) ) ) : null;
		$this->audio_dirs = is_array( $audio_dirs ) ? array_values( array_filter( array_map( 'strval', $audio_dirs ) ) ) : null;
		$this->pdf_dirs   = is_array( $pdf_dirs ) ? array_values( array_filter( array_map( 'strval', $pdf_dirs ) ) ) : null;
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
	 * Import all videos for an entry.
	 *
	 * Mirrors import_entry_photos(). Used by tests and as a convenience wrapper;
	 * the runner's per-entry batch loop calls import_or_reuse_video() directly so
	 * it can checkpoint between videos.
	 *
	 * @param array<string,mixed> $entry Entry.
	 * @param int                 $post_id Post ID.
	 * @return array<int,array{identifier:string,attachment_id:int}> Records in scan order.
	 */
	public function import_entry_videos( $entry, $post_id ) {
		$videos = isset( $entry['videos'] ) && is_array( $entry['videos'] ) ? $entry['videos'] : array();
		if ( empty( $videos ) ) {
			return array();
		}

		$this->results->increment( 'media_found', count( $videos ) );
		$videos = self::sort_videos( $videos );

		$records = array();
		foreach ( $videos as $video ) {
			$attachment_id = $this->import_or_reuse_video( $video, $entry, $post_id );
			if ( $attachment_id ) {
				$records[] = array(
					'identifier'    => isset( $video['identifier'] ) ? (string) $video['identifier'] : '',
					'attachment_id' => (int) $attachment_id,
				);
			}
		}

		return $records;
	}

	/**
	 * Import all audios for an entry.
	 *
	 * Mirrors import_entry_videos(). Used by tests and as a convenience wrapper;
	 * the runner's per-entry batch loop calls import_or_reuse_audio() directly so
	 * it can checkpoint between audios.
	 *
	 * @param array<string,mixed> $entry Entry.
	 * @param int                 $post_id Post ID.
	 * @return array<int,array{identifier:string,attachment_id:int}> Records in scan order.
	 */
	public function import_entry_audios( $entry, $post_id ) {
		$audios = isset( $entry['audios'] ) && is_array( $entry['audios'] ) ? $entry['audios'] : array();
		if ( empty( $audios ) ) {
			return array();
		}

		$this->results->increment( 'media_found', count( $audios ) );
		$audios = self::sort_audios( $audios );

		$records = array();
		foreach ( $audios as $audio ) {
			$attachment_id = $this->import_or_reuse_audio( $audio, $entry, $post_id );
			if ( $attachment_id ) {
				$records[] = array(
					'identifier'    => isset( $audio['identifier'] ) ? (string) $audio['identifier'] : '',
					'attachment_id' => (int) $attachment_id,
				);
			}
		}

		return $records;
	}

	/**
	 * Import all PDFs for an entry.
	 *
	 * Mirrors import_entry_audios(). Used by tests and as a convenience wrapper;
	 * the runner's per-entry batch loop calls import_or_reuse_pdf() directly so
	 * it can checkpoint between PDFs.
	 *
	 * @param array<string,mixed> $entry Entry.
	 * @param int                 $post_id Post ID.
	 * @return array<int,array{identifier:string,attachment_id:int}> Records in scan order.
	 */
	public function import_entry_pdfs( $entry, $post_id ) {
		$pdfs = isset( $entry['pdfAttachments'] ) && is_array( $entry['pdfAttachments'] ) ? $entry['pdfAttachments'] : array();
		if ( empty( $pdfs ) ) {
			return array();
		}

		$this->results->increment( 'media_found', count( $pdfs ) );
		$pdfs = self::sort_pdfs( $pdfs );

		$records = array();
		foreach ( $pdfs as $pdf ) {
			$attachment_id = $this->import_or_reuse_pdf( $pdf, $entry, $post_id );
			if ( $attachment_id ) {
				$records[] = array(
					'identifier'    => isset( $pdf['identifier'] ) ? (string) $pdf['identifier'] : '',
					'attachment_id' => (int) $attachment_id,
				);
			}
		}

		return $records;
	}

	/**
	 * Sort videos by orderInEntry then original index. Mirrors sort_photos().
	 *
	 * @param array<int,array<string,mixed>> $videos Videos.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sort_videos( $videos ) {
		foreach ( $videos as $index => &$video ) {
			$video['_original_index'] = $index;
		}
		unset( $video );

		usort(
			$videos,
			static function ( $a, $b ) {
				$a_order = isset( $a['orderInEntry'] ) && null !== $a['orderInEntry'] ? (int) $a['orderInEntry'] : PHP_INT_MAX;
				$b_order = isset( $b['orderInEntry'] ) && null !== $b['orderInEntry'] ? (int) $b['orderInEntry'] : PHP_INT_MAX;
				if ( $a_order === $b_order ) {
					return (int) $a['_original_index'] <=> (int) $b['_original_index'];
				}

				return $a_order <=> $b_order;
			}
		);

		return $videos;
	}

	/**
	 * Sort audios by orderInEntry then original index. Mirrors sort_videos().
	 *
	 * @param array<int,array<string,mixed>> $audios Audios.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sort_audios( $audios ) {
		foreach ( $audios as $index => &$audio ) {
			$audio['_original_index'] = $index;
		}
		unset( $audio );

		usort(
			$audios,
			static function ( $a, $b ) {
				$a_order = isset( $a['orderInEntry'] ) && null !== $a['orderInEntry'] ? (int) $a['orderInEntry'] : PHP_INT_MAX;
				$b_order = isset( $b['orderInEntry'] ) && null !== $b['orderInEntry'] ? (int) $b['orderInEntry'] : PHP_INT_MAX;
				if ( $a_order === $b_order ) {
					return (int) $a['_original_index'] <=> (int) $b['_original_index'];
				}

				return $a_order <=> $b_order;
			}
		);

		return $audios;
	}

	/**
	 * Sort PDF attachments by orderInEntry then original index. Mirrors sort_audios().
	 *
	 * @param array<int,array<string,mixed>> $pdfs PDF attachments.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sort_pdfs( $pdfs ) {
		foreach ( $pdfs as $index => &$pdf ) {
			$pdf['_original_index'] = $index;
		}
		unset( $pdf );

		usort(
			$pdfs,
			static function ( $a, $b ) {
				$a_order = isset( $a['orderInEntry'] ) && null !== $a['orderInEntry'] ? (int) $a['orderInEntry'] : PHP_INT_MAX;
				$b_order = isset( $b['orderInEntry'] ) && null !== $b['orderInEntry'] ? (int) $b['orderInEntry'] : PHP_INT_MAX;
				if ( $a_order === $b_order ) {
					return (int) $a['_original_index'] <=> (int) $b['_original_index'];
				}

				return $a_order <=> $b_order;
			}
		);

		return $pdfs;
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
	 * Import or reuse one video.
	 *
	 * Mirrors import_or_reuse_photo(). On MIME rejection (validate_media_file()
	 * sentinel), the embed is dropped with a privacy-safe warning per spec R5.2.
	 *
	 * @param array<string,mixed> $video Video metadata.
	 * @param array<string,mixed> $entry Entry.
	 * @param int                 $post_id Post ID.
	 * @return int Attachment ID, or 0.
	 */
	public function import_or_reuse_video( $video, $entry, $post_id ) {
		$uuid       = isset( $entry['uuid'] ) ? (string) $entry['uuid'] : '';
		$identifier = isset( $video['identifier'] ) ? (string) $video['identifier'] : '';
		$md5        = isset( $video['md5'] ) ? (string) $video['md5'] : '';

		$existing = $this->find_existing_attachment( $post_id, $uuid, $identifier, $md5 );
		if ( $existing ) {
			$this->results->increment( 'media_reused' );
			return $existing;
		}

		$source = self::resolve_video_path( $this->root, $video, $this->video_dirs );
		if ( ! $source ) {
			$this->results->increment( 'media_missing' );
			$this->results->add_warning(
				__( 'Skipping embedded video in Day One entry: referenced media file is unsupported or missing.', 'day-one-importer' )
			);
			return 0;
		}

		$partial = $this->find_partial_attachment_by_source( $post_id, $uuid, $video, $source );
		if ( $partial ) {
			$this->apply_video_marker_metadata( $partial, $uuid, $identifier, $md5, $video );
			$this->results->increment( 'media_reused' );
			return $partial;
		}

		$valid = $this->validate_media_file( $source, 'video' );
		if ( true !== $valid ) {
			if ( 'unsupported' === $valid ) {
				$this->results->increment( 'media_unsupported' );
			} else {
				$this->results->increment( 'media_failed' );
			}
			$this->results->add_warning(
				__( 'Skipping embedded video in Day One entry: referenced media file is unsupported or missing.', 'day-one-importer' )
			);
			return 0;
		}

		$attachment_id = $this->sideload_media( $source, $video, $entry, $post_id );
		if ( ! $attachment_id ) {
			$this->results->increment( 'media_failed' );
			$this->results->add_warning(
				__( 'Skipping embedded video in Day One entry: referenced media file is unsupported or missing.', 'day-one-importer' )
			);
			return 0;
		}

		$this->apply_video_marker_metadata( $attachment_id, $uuid, $identifier, $md5, $video );
		$this->results->increment( 'media_imported' );
		return $attachment_id;
	}

	/**
	 * Import or reuse one audio.
	 *
	 * Mirrors import_or_reuse_video(). On MIME rejection (validate_media_file()
	 * sentinel), the embed is dropped with a privacy-safe warning per spec R5.2.
	 *
	 * @param array<string,mixed> $audio Audio metadata.
	 * @param array<string,mixed> $entry Entry.
	 * @param int                 $post_id Post ID.
	 * @return int Attachment ID, or 0.
	 */
	public function import_or_reuse_audio( $audio, $entry, $post_id ) {
		$uuid       = isset( $entry['uuid'] ) ? (string) $entry['uuid'] : '';
		$identifier = isset( $audio['identifier'] ) ? (string) $audio['identifier'] : '';
		$md5        = isset( $audio['md5'] ) ? (string) $audio['md5'] : '';

		$existing = $this->find_existing_attachment( $post_id, $uuid, $identifier, $md5 );
		if ( $existing ) {
			$this->results->increment( 'media_reused' );
			return $existing;
		}

		$source = self::resolve_audio_path( $this->root, $audio, $this->audio_dirs );
		if ( ! $source ) {
			$this->results->increment( 'media_missing' );
			$this->results->add_warning(
				__( 'Skipping embedded audio in Day One entry: referenced media file is unsupported or missing.', 'day-one-importer' )
			);
			return 0;
		}

		$partial = $this->find_partial_attachment_by_source( $post_id, $uuid, $audio, $source );
		if ( $partial ) {
			$this->apply_audio_marker_metadata( $partial, $uuid, $identifier, $md5, $audio );
			$this->results->increment( 'media_reused' );
			return $partial;
		}

		$valid = $this->validate_media_file( $source, 'audio' );
		if ( true !== $valid ) {
			if ( 'unsupported' === $valid ) {
				$this->results->increment( 'media_unsupported' );
			} else {
				$this->results->increment( 'media_failed' );
			}
			$this->results->add_warning(
				__( 'Skipping embedded audio in Day One entry: referenced media file is unsupported or missing.', 'day-one-importer' )
			);
			return 0;
		}

		$attachment_id = $this->sideload_media( $source, $audio, $entry, $post_id );
		if ( ! $attachment_id ) {
			$this->results->increment( 'media_failed' );
			$this->results->add_warning(
				__( 'Skipping embedded audio in Day One entry: referenced media file is unsupported or missing.', 'day-one-importer' )
			);
			return 0;
		}

		$this->apply_audio_marker_metadata( $attachment_id, $uuid, $identifier, $md5, $audio );
		$this->results->increment( 'media_imported' );
		return $attachment_id;
	}

	/**
	 * Import or reuse one PDF attachment.
	 *
	 * Mirrors import_or_reuse_audio(). On MIME rejection (validate_media_file()
	 * sentinel), the embed is dropped with a privacy-safe warning per spec R5.2.
	 *
	 * @param array<string,mixed> $pdf PDF metadata.
	 * @param array<string,mixed> $entry Entry.
	 * @param int                 $post_id Post ID.
	 * @return int Attachment ID, or 0.
	 */
	public function import_or_reuse_pdf( $pdf, $entry, $post_id ) {
		$uuid       = isset( $entry['uuid'] ) ? (string) $entry['uuid'] : '';
		$identifier = isset( $pdf['identifier'] ) ? (string) $pdf['identifier'] : '';
		$md5        = isset( $pdf['md5'] ) ? (string) $pdf['md5'] : '';

		$existing = $this->find_existing_attachment( $post_id, $uuid, $identifier, $md5 );
		if ( $existing ) {
			$this->results->increment( 'media_reused' );
			return $existing;
		}

		$source = self::resolve_pdf_path( $this->root, $pdf, $this->pdf_dirs );
		if ( ! $source ) {
			$this->results->increment( 'media_missing' );
			$this->results->add_warning(
				__( 'Skipping embedded PDF in Day One entry: referenced media file is unsupported or missing.', 'day-one-importer' )
			);
			return 0;
		}

		$partial = $this->find_partial_attachment_by_source( $post_id, $uuid, $pdf, $source );
		if ( $partial ) {
			$this->apply_pdf_marker_metadata( $partial, $uuid, $identifier, $md5, $pdf );
			$this->results->increment( 'media_reused' );
			return $partial;
		}

		$valid = $this->validate_media_file( $source, 'pdf' );
		if ( true !== $valid ) {
			if ( 'unsupported' === $valid ) {
				$this->results->increment( 'media_unsupported' );
			} else {
				$this->results->increment( 'media_failed' );
			}
			$this->results->add_warning(
				__( 'Skipping embedded PDF in Day One entry: referenced media file is unsupported or missing.', 'day-one-importer' )
			);
			return 0;
		}

		$attachment_id = $this->sideload_media( $source, $pdf, $entry, $post_id );
		if ( ! $attachment_id ) {
			$this->results->increment( 'media_failed' );
			$this->results->add_warning(
				__( 'Skipping embedded PDF in Day One entry: referenced media file is unsupported or missing.', 'day-one-importer' )
			);
			return 0;
		}

		$this->apply_pdf_marker_metadata( $attachment_id, $uuid, $identifier, $md5, $pdf );
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
		$root = Day_One_Importer_Cleanup::normalize_path( $root );
		if ( ! Day_One_Importer_Cleanup::is_dir( $root ) ) {
			return '';
		}

		if ( null !== $photo_dirs ) {
			$photo_dirs = self::sanitize_photo_dirs( $root, $photo_dirs );
		} else {
			$photo_dirs = self::find_photo_dirs( $root );
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

		foreach ( $photo_dirs as $dir ) {
			foreach ( array_unique( $candidates ) as $candidate ) {
				$path = Day_One_Importer_Cleanup::normalize_path( $dir . '/' . $candidate );
				if ( Day_One_Importer_Cleanup::is_file( $path ) && Day_One_Importer_Cleanup::path_is_inside( $path, $root ) ) {
					return $path;
				}
			}
		}

		return '';
	}

	/**
	 * Sanitize cached photo directories against the extraction root.
	 *
	 * @param string   $root Extraction root.
	 * @param string[] $photo_dirs Cached directories.
	 * @return string[]
	 */
	private static function sanitize_photo_dirs( $root, $photo_dirs ) {
		$dirs = array();
		$root = Day_One_Importer_Cleanup::normalize_path( $root );
		foreach ( (array) $photo_dirs as $dir ) {
			$path = Day_One_Importer_Cleanup::normalize_path( (string) $dir );
			if ( ! Day_One_Importer_Cleanup::is_dir( $path ) ) {
				continue;
			}
			if ( Day_One_Importer_Cleanup::path_is_inside( $path, $root ) ) {
				$dirs[] = $path;
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
		$root = Day_One_Importer_Cleanup::normalize_path( $root );
		if ( ! Day_One_Importer_Cleanup::is_dir( $root ) ) {
			return $dirs;
		}

		foreach ( Day_One_Importer_Cleanup::list_directory_paths( $root ) as $path ) {
			if ( Day_One_Importer_Cleanup::is_dir( $path ) && 'photos' === strtolower( basename( $path ) ) ) {
				$dirs[] = $path;
			}
		}

		sort( $dirs );
		return $dirs;
	}

	/**
	 * Resolve a Day One video to an exported file path. Mirrors resolve_photo_path().
	 *
	 * @param string              $root Extraction root.
	 * @param array<string,mixed> $video Video metadata.
	 * @param string[]|null       $video_dirs Cached video directories, or null to discover.
	 * @return string Empty if unresolved.
	 */
	public static function resolve_video_path( $root, $video, $video_dirs = null ) {
		$root_real = realpath( $root );
		if ( false === $root_real ) {
			return '';
		}

		if ( null !== $video_dirs ) {
			$video_dirs = self::sanitize_video_dirs( $root_real, $video_dirs );
		} else {
			$video_dirs = self::find_video_dirs( $root_real );
		}
		if ( empty( $video_dirs ) ) {
			return '';
		}

		$candidates = array();
		$md5        = isset( $video['md5'] ) ? strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) $video['md5'] ) ) : '';
		$type       = isset( $video['type'] ) ? strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $video['type'] ) ) : '';
		$filename   = isset( $video['filename'] ) ? basename( (string) $video['filename'] ) : '';

		if ( $md5 ) {
			$extensions = self::candidate_extensions_video( $type );
			foreach ( $extensions as $extension ) {
				$candidates[] = $md5 . '.' . $extension;
			}
		}

		if ( $filename ) {
			$candidates[] = $filename;
		}

		$root_prefix = rtrim( $root_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		foreach ( $video_dirs as $dir ) {
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
	 * Sanitize cached video directories against the extraction root.
	 *
	 * @param string   $root_real Real extraction root.
	 * @param string[] $video_dirs Cached directories.
	 * @return string[]
	 */
	private static function sanitize_video_dirs( $root_real, $video_dirs ) {
		$dirs        = array();
		$root_prefix = rtrim( $root_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		foreach ( (array) $video_dirs as $dir ) {
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
	 * Find videos directories in an export. Mirrors find_photo_dirs().
	 *
	 * @param string $root Root.
	 * @return string[]
	 */
	public static function find_video_dirs( $root ) {
		$dirs = array();
		if ( ! is_dir( $root ) ) {
			return $dirs;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() && 'videos' === strtolower( $item->getFilename() ) ) {
				$dirs[] = $item->getPathname();
			}
		}

		sort( $dirs );
		return $dirs;
	}

	/**
	 * Candidate extensions for a Day One video type.
	 *
	 * @param string $type Type.
	 * @return string[]
	 */
	private static function candidate_extensions_video( $type ) {
		$extensions = array();
		if ( $type ) {
			$extensions[] = $type;
		}

		switch ( $type ) {
			case 'mov':
				$extensions[] = 'mp4';
				$extensions[] = 'm4v';
				break;
			case 'mp4':
				$extensions[] = 'mov';
				$extensions[] = 'm4v';
				break;
		}

		$extensions = array_merge( $extensions, array( 'mov', 'mp4', 'm4v' ) );
		return array_values( array_unique( array_filter( $extensions ) ) );
	}

	/**
	 * Resolve a Day One audio to an exported file path. Mirrors resolve_video_path().
	 *
	 * Reads `format` (not `type`) from the audio record (spec R4.5).
	 *
	 * @param string              $root Extraction root.
	 * @param array<string,mixed> $audio Audio metadata.
	 * @param string[]|null       $audio_dirs Cached audio directories, or null to discover.
	 * @return string Empty if unresolved.
	 */
	public static function resolve_audio_path( $root, $audio, $audio_dirs = null ) {
		$root_real = realpath( $root );
		if ( false === $root_real ) {
			return '';
		}

		if ( null !== $audio_dirs ) {
			$audio_dirs = self::sanitize_audio_dirs( $root_real, $audio_dirs );
		} else {
			$audio_dirs = self::find_audio_dirs( $root_real );
		}
		if ( empty( $audio_dirs ) ) {
			return '';
		}

		$candidates = array();
		$md5        = isset( $audio['md5'] ) ? strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) $audio['md5'] ) ) : '';
		$format     = isset( $audio['format'] ) ? strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $audio['format'] ) ) : '';
		$filename   = isset( $audio['filename'] ) ? basename( (string) $audio['filename'] ) : '';

		if ( $md5 ) {
			$extensions = self::candidate_extensions_audio( $format );
			foreach ( $extensions as $extension ) {
				$candidates[] = $md5 . '.' . $extension;
			}
		}

		if ( $filename ) {
			$candidates[] = $filename;
		}

		$root_prefix = rtrim( $root_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		foreach ( $audio_dirs as $dir ) {
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
	 * Sanitize cached audio directories against the extraction root.
	 *
	 * @param string   $root_real Real extraction root.
	 * @param string[] $audio_dirs Cached directories.
	 * @return string[]
	 */
	private static function sanitize_audio_dirs( $root_real, $audio_dirs ) {
		$dirs        = array();
		$root_prefix = rtrim( $root_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		foreach ( (array) $audio_dirs as $dir ) {
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
	 * Find audios directories in an export. Mirrors find_video_dirs().
	 *
	 * @param string $root Root.
	 * @return string[]
	 */
	public static function find_audio_dirs( $root ) {
		$dirs = array();
		if ( ! is_dir( $root ) ) {
			return $dirs;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() && 'audios' === strtolower( $item->getFilename() ) ) {
				$dirs[] = $item->getPathname();
			}
		}

		sort( $dirs );
		return $dirs;
	}

	/**
	 * Resolve a Day One PDF to an exported file path. Mirrors resolve_audio_path().
	 *
	 * The extension is hardcoded to `.pdf` per spec R1.3 (Day One only ships
	 * `.pdf` files in pdfs/ and the normalized record carries no `type`/`format`
	 * field).
	 *
	 * @param string              $root Extraction root.
	 * @param array<string,mixed> $pdf  PDF metadata.
	 * @param string[]|null       $pdf_dirs Cached PDF directories, or null to discover.
	 * @return string Empty if unresolved.
	 */
	public static function resolve_pdf_path( $root, $pdf, $pdf_dirs = null ) {
		$root_real = realpath( $root );
		if ( false === $root_real ) {
			return '';
		}

		if ( null !== $pdf_dirs ) {
			$pdf_dirs = self::sanitize_pdf_dirs( $root_real, $pdf_dirs );
		} else {
			$pdf_dirs = self::find_pdf_dirs( $root_real );
		}
		if ( empty( $pdf_dirs ) ) {
			return '';
		}

		$candidates = array();
		$md5        = isset( $pdf['md5'] ) ? strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) $pdf['md5'] ) ) : '';
		$filename   = isset( $pdf['filename'] ) ? basename( (string) $pdf['filename'] ) : '';

		if ( $md5 ) {
			// #59 R1.3 — the only extension probed for PDFs is `.pdf`.
			$candidates[] = $md5 . '.pdf';
		}

		if ( $filename ) {
			$candidates[] = $filename;
		}

		$root_prefix = rtrim( $root_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		foreach ( $pdf_dirs as $dir ) {
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
	 * Sanitize cached PDF directories against the extraction root.
	 *
	 * @param string   $root_real Real extraction root.
	 * @param string[] $pdf_dirs Cached directories.
	 * @return string[]
	 */
	private static function sanitize_pdf_dirs( $root_real, $pdf_dirs ) {
		$dirs        = array();
		$root_prefix = rtrim( $root_real, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		foreach ( (array) $pdf_dirs as $dir ) {
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
	 * Find pdfs directories in an export. Mirrors find_audio_dirs().
	 *
	 * @param string $root Root.
	 * @return string[]
	 */
	public static function find_pdf_dirs( $root ) {
		$dirs = array();
		if ( ! is_dir( $root ) ) {
			return $dirs;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() && 'pdfs' === strtolower( $item->getFilename() ) ) {
				$dirs[] = $item->getPathname();
			}
		}

		sort( $dirs );
		return $dirs;
	}

	/**
	 * Candidate extensions for a Day One audio format. Mirrors candidate_extensions_video().
	 *
	 * @param string $format Format (normalized: no leading dot, lowercased).
	 * @return string[]
	 */
	private static function candidate_extensions_audio( $format ) {
		$extensions = array();
		if ( $format ) {
			$extensions[] = $format;
		}

		switch ( $format ) {
			case 'mp3':
				$extensions[] = 'm4a';
				$extensions[] = 'aac';
				break;
			case 'aac':
				$extensions[] = 'm4a';
				$extensions[] = 'mp3';
				break;
			case 'lpcm':
				$extensions[] = 'wav';
				$extensions[] = 'm4a';
				break;
			case 'm4a':
				$extensions[] = 'mp3';
				$extensions[] = 'aac';
				break;
			case 'wav':
				$extensions[] = 'lpcm';
				$extensions[] = 'm4a';
				break;
		}

		$extensions = array_merge( $extensions, array( 'mp3', 'm4a', 'aac', 'wav' ) );
		return array_values( array_unique( array_filter( $extensions ) ) );
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
	 * Validate media is a WordPress-accepted type for the requested kind.
	 *
	 * For kind 'photo' (default) the MIME must start with `image/`; for kind
	 * 'video' it must start with `video/`; for kind 'audio' it must start with
	 * `audio/`; for kind 'pdf' the MIME must equal `application/pdf` exactly
	 * (the only MIME under the application/ tree this importer accepts; see
	 * spec R5.1). In all cases the MIME must also be present in
	 * get_allowed_mime_types(), otherwise sideload would refuse it.
	 *
	 * @param string $path Path.
	 * @param string $kind 'photo' (default), 'video', 'audio', or 'pdf'.
	 * @return true|string True or reason ('unreadable'|'unsupported'|'mime-not-allowed').
	 */
	private function validate_media_file( $path, $kind = 'photo' ) {
		if ( ! Day_One_Importer_Cleanup::is_readable( $path ) || Day_One_Importer_Cleanup::size( $path ) <= 0 ) {
			return 'unreadable';
		}

		$type = wp_check_filetype_and_ext( $path, basename( $path ) );
		$mime = isset( $type['type'] ) ? (string) $type['type'] : '';

		if ( 'video' === $kind ) {
			if ( ! $mime || 0 !== strpos( $mime, 'video/' ) ) {
				return 'unsupported';
			}
		} elseif ( 'audio' === $kind ) {
			if ( ! $mime || 0 !== strpos( $mime, 'audio/' ) ) {
				return 'unsupported';
			}
		} elseif ( 'pdf' === $kind ) {
			// #59 R5.1 — exact equality, not a prefix; application/pdf is the
			// only MIME under application/ this importer accepts.
			if ( 'application/pdf' !== $mime ) {
				return 'unsupported';
			}
		} elseif ( ! $mime || 0 !== strpos( $mime, 'image/' ) ) {
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
		if ( ! $identifier && ! $md5 ) {
			return 0;
		}

		// #76 perf: look up dedupe candidates via a single indexed `meta_query`
		// joining `_day_one_uuid` AND (`_day_one_media_identifier` OR
		// `_day_one_media_md5`) instead of loading every attachment on the post
		// and reading three meta rows per attachment in PHP. The identifier /
		// md5 sub-clause collapses to a single equality when only one of the
		// two markers is present on the photo record (Day One sometimes ships
		// one without the other).
		$marker_clauses = array();
		if ( $identifier ) {
			$marker_clauses[] = array(
				'key'     => '_day_one_media_identifier',
				'value'   => (string) $identifier,
				'compare' => '=',
			);
		}
		if ( $md5 ) {
			$marker_clauses[] = array(
				'key'     => '_day_one_media_md5',
				'value'   => (string) $md5,
				'compare' => '=',
			);
		}
		if ( count( $marker_clauses ) > 1 ) {
			$marker_clauses = array_merge( array( 'relation' => 'OR' ), $marker_clauses );
		} else {
			$marker_clauses = $marker_clauses[0];
		}

		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'     => '_day_one_uuid',
				'value'   => (string) $uuid,
				'compare' => '=',
			),
			$marker_clauses,
		);

		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'post_parent'    => (int) $post_id,
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return 0;
		}

		return (int) $ids[0];
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
		// #76 perf: previously this loaded every attachment on the post and
		// called `get_post_meta` per row to filter out Day One imports. Move
		// the `_day_one_source != 'day-one-export'` predicate into a single
		// indexed `meta_query` and cap `posts_per_page` at 10 so a single
		// query bounds the work irrespective of how many media a post carries.
		// Partial attachments (the ones this helper repairs) by definition
		// lack the Day One source marker, so this still captures every
		// candidate the previous PHP filter would have returned. `$uuid` is
		// accepted for signature parity with `find_existing_attachment()` and
		// remains intentionally unused here (the partial repair predicate is
		// filename-shape based, not Day One UUID based).
		unset( $uuid );
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'post_parent'    => (int) $post_id,
				'fields'         => 'ids',
				'posts_per_page' => 10,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => '_day_one_source',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_day_one_source',
						'value'   => 'day-one-export',
						'compare' => '!=',
					),
				),
			)
		);
		if ( ! is_array( $ids ) ) {
			return 0;
		}

		foreach ( $ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			if ( ! $attachment_id ) {
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
	 * Writes the following attachment meta when the corresponding Day One photo
	 * record carries the underlying value:
	 *  - `_day_one_media_identifier`, `_day_one_media_md5`, `_day_one_uuid`,
	 *    `_day_one_source` (always written)
	 *  - `_day_one_media_date`, `_day_one_original_filename`,
	 *    `_day_one_width`, `_day_one_height` (only when present in `$photo`)
	 *  - `_day_one_photo_format` (issue #60 R1): lowercased `$photo['type']`.
	 *    When `type` is missing or empty the key is left untouched, preserving
	 *    any pre-existing value across a resume/refetch (R1.1 second clause).
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

		// #60 R1.1 — Uniform photo-format marker. Skip the write entirely when
		// `type` is missing/empty so a refetch/resume call cannot wipe a prior
		// value (no update_post_meta with '', no delete_post_meta).
		$photo_format = isset( $photo['type'] ) ? strtolower( (string) $photo['type'] ) : '';
		if ( '' !== $photo_format ) {
			update_post_meta( $attachment_id, '_day_one_photo_format', $photo_format );
		}
	}

	/**
	 * Apply Day One marker metadata for a video attachment.
	 *
	 * Mirrors apply_photo_marker_metadata() for the shared keys. Adds:
	 *  - `_day_one_media_kind = 'video'` (always)
	 *  - `_day_one_video_duration` (string) only when the canonical numeric value
	 *    is greater than zero. See spec R1.4 + R4.1.
	 *
	 * @param int                 $attachment_id Attachment ID.
	 * @param string              $uuid Entry UUID.
	 * @param string              $identifier Media identifier.
	 * @param string              $md5 Media MD5.
	 * @param array<string,mixed> $video Video metadata.
	 * @return void
	 */
	private function apply_video_marker_metadata( $attachment_id, $uuid, $identifier, $md5, $video ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id ) {
			return;
		}

		update_post_meta( $attachment_id, '_day_one_media_identifier', $identifier );
		update_post_meta( $attachment_id, '_day_one_media_md5', $md5 );
		update_post_meta( $attachment_id, '_day_one_uuid', $uuid );
		update_post_meta( $attachment_id, '_day_one_source', 'day-one-export' );
		update_post_meta( $attachment_id, '_day_one_media_kind', 'video' );
		if ( ! empty( $video['date'] ) ) {
			update_post_meta( $attachment_id, '_day_one_media_date', day_one_importer_sanitize_text( $video['date'] ) );
		}
		if ( ! empty( $video['filename'] ) ) {
			update_post_meta( $attachment_id, '_day_one_original_filename', day_one_importer_sanitize_text( $video['filename'] ) );
		}
		if ( ! empty( $video['width'] ) ) {
			update_post_meta( $attachment_id, '_day_one_width', absint( $video['width'] ) );
		}
		if ( ! empty( $video['height'] ) ) {
			update_post_meta( $attachment_id, '_day_one_height', absint( $video['height'] ) );
		}
		if ( isset( $video['duration'] ) && floatval( $video['duration'] ) > 0 ) {
			update_post_meta( $attachment_id, '_day_one_video_duration', (string) $video['duration'] );
		}
	}

	/**
	 * Apply Day One marker metadata for an audio attachment.
	 *
	 * Mirrors apply_video_marker_metadata() for the shared keys. Adds:
	 *  - `_day_one_media_kind = 'audio'` (always)
	 *  - `_day_one_audio_duration` (string) only when the canonical numeric value
	 *    is greater than zero.
	 *  - `_day_one_audio_title` (string) only when non-empty after sanitization.
	 *
	 * Width / height are intentionally NOT persisted for audio (spec R4.1) --
	 * Day One ships zeros and they carry no signal.
	 *
	 * @param int                 $attachment_id Attachment ID.
	 * @param string              $uuid Entry UUID.
	 * @param string              $identifier Media identifier.
	 * @param string              $md5 Media MD5.
	 * @param array<string,mixed> $audio Audio metadata.
	 * @return void
	 */
	private function apply_audio_marker_metadata( $attachment_id, $uuid, $identifier, $md5, $audio ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id ) {
			return;
		}

		update_post_meta( $attachment_id, '_day_one_media_identifier', $identifier );
		update_post_meta( $attachment_id, '_day_one_media_md5', $md5 );
		update_post_meta( $attachment_id, '_day_one_uuid', $uuid );
		update_post_meta( $attachment_id, '_day_one_source', 'day-one-export' );
		update_post_meta( $attachment_id, '_day_one_media_kind', 'audio' );
		if ( ! empty( $audio['date'] ) ) {
			update_post_meta( $attachment_id, '_day_one_media_date', day_one_importer_sanitize_text( $audio['date'] ) );
		}
		if ( ! empty( $audio['filename'] ) ) {
			update_post_meta( $attachment_id, '_day_one_original_filename', day_one_importer_sanitize_text( $audio['filename'] ) );
		}
		if ( isset( $audio['duration'] ) && floatval( $audio['duration'] ) > 0 ) {
			update_post_meta( $attachment_id, '_day_one_audio_duration', (string) $audio['duration'] );
		}
		if ( isset( $audio['title'] ) && '' !== (string) $audio['title'] ) {
			update_post_meta( $attachment_id, '_day_one_audio_title', (string) $audio['title'] );
		}
	}

	/**
	 * Apply Day One marker metadata for a PDF attachment.
	 *
	 * Mirrors apply_audio_marker_metadata() for the shared keys. Adds:
	 *  - `_day_one_media_kind = 'pdf'` (always)
	 *  - `_day_one_pdf_name` (string) only when non-empty after sanitization.
	 *    Used by serialize_file_block() link-text precedence (spec R6.2 + R6.5).
	 *
	 * Width / height / duration are intentionally NOT persisted for PDFs (spec
	 * R4.1) -- Day One ships zeros and they carry no signal. The PDF record
	 * carries no `date` or `filename` either, so those keys are skipped.
	 *
	 * @param int                 $attachment_id Attachment ID.
	 * @param string              $uuid Entry UUID.
	 * @param string              $identifier Media identifier.
	 * @param string              $md5 Media MD5.
	 * @param array<string,mixed> $pdf PDF metadata.
	 * @return void
	 */
	private function apply_pdf_marker_metadata( $attachment_id, $uuid, $identifier, $md5, $pdf ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id ) {
			return;
		}

		update_post_meta( $attachment_id, '_day_one_media_identifier', $identifier );
		update_post_meta( $attachment_id, '_day_one_media_md5', $md5 );
		update_post_meta( $attachment_id, '_day_one_uuid', $uuid );
		update_post_meta( $attachment_id, '_day_one_source', 'day-one-export' );
		update_post_meta( $attachment_id, '_day_one_media_kind', 'pdf' );
		if ( isset( $pdf['pdfName'] ) && '' !== (string) $pdf['pdfName'] ) {
			update_post_meta( $attachment_id, '_day_one_pdf_name', (string) $pdf['pdfName'] );
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
		if ( ! $tmp || ! Day_One_Importer_Cleanup::copy_file( $source, $tmp ) ) {
			if ( $tmp ) {
				Day_One_Importer_Cleanup::delete_path( $tmp );
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
			Day_One_Importer_Cleanup::delete_path( $tmp );
			return 0;
		}

		if ( $private_file && Day_One_Importer_Cleanup::is_readable( $private_file ) ) {
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
	public static function filter_import_image_sizes( $sizes ) {
		unset( $sizes );
		return array();
	}

	/**
	 * Route Day One media sideloads into a dedicated private uploads directory.
	 *
	 * @param array<string,mixed> $dirs Upload directory data.
	 * @return array<string,mixed> Filtered upload directory data.
	 */
	public static function filter_private_upload_dir( $dirs ) {
		$basedir = isset( $dirs['basedir'] ) ? (string) $dirs['basedir'] : '';
		$baseurl = isset( $dirs['baseurl'] ) ? untrailingslashit( (string) $dirs['baseurl'] ) : '';
		$subdir  = isset( $dirs['subdir'] ) ? (string) $dirs['subdir'] : '';
		$private = self::prepare_private_media_base_dir( $basedir );

		if ( '' === $baseurl || '' === $private ) {
			$dirs['error'] = __( 'The private media directory could not be prepared.', 'day-one-importer' );
			return $dirs;
		}

		$private_subdir = $subdir;
		$private_path   = untrailingslashit( $private ) . $private_subdir;

		Day_One_Importer_Cleanup::make_directory( $private_path );

		self::protect_private_upload_directory( $private );
		self::protect_private_upload_directory( $private_path );

		if ( ! Day_One_Importer_Cleanup::is_dir( $private_path ) || ! Day_One_Importer_Cleanup::is_writable( $private_path ) ) {
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
	 * @param string $upload_basedir Optional uploads base directory.
	 * @return string Absolute directory path, or empty string on failure.
	 */
	public static function prepare_private_media_base_dir( $upload_basedir = '' ) {
		$dir = self::private_media_base_dir( $upload_basedir );
		if ( '' === $dir ) {
			return '';
		}

		if ( ! Day_One_Importer_Cleanup::make_directory( $dir ) ) {
			return '';
		}

		if ( ! Day_One_Importer_Cleanup::is_dir( $dir ) || ! Day_One_Importer_Cleanup::is_writable( $dir ) ) {
			return '';
		}

		self::protect_private_upload_directory( $dir );
		return $dir;
	}

	/**
	 * Return the protected private media base directory in the uploads tree.
	 *
	 * @param string $upload_basedir Optional uploads base directory.
	 * @return string Directory path.
	 */
	public static function private_media_base_dir( $upload_basedir = '' ) {
		if ( '' === $upload_basedir ) {
			$upload_dir     = wp_upload_dir( null, false );
			$upload_basedir = empty( $upload_dir['basedir'] ) ? '' : (string) $upload_dir['basedir'];
		}

		$basedir = untrailingslashit( $upload_basedir );
		$default = $basedir ? $basedir . DIRECTORY_SEPARATOR . self::PRIVATE_UPLOAD_SUBDIR : '';
		$default = (string) apply_filters( 'day_one_importer_private_media_dir', $default );

		return $default ? untrailingslashit( $default ) : '';
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
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $include_nonce Whether to include a fresh media nonce.
	 * @return string URL.
	 */
	public static function private_media_url( $attachment_id, $include_nonce = true ) {
		if ( ! function_exists( 'admin_url' ) || ! function_exists( 'add_query_arg' ) ) {
			return '';
		}

		$args = array(
			'action'        => self::PRIVATE_MEDIA_ACTION,
			'attachment_id' => absint( $attachment_id ),
		);
		if ( $include_nonce ) {
			$args['nonce'] = wp_create_nonce( self::PRIVATE_MEDIA_ACTION );
		}

		return add_query_arg( $args, admin_url( 'admin-ajax.php' ) );
	}

	/**
	 * Inject fresh private media nonces into rendered post content.
	 *
	 * Stored block markup intentionally keeps stable nonce-less endpoint URLs so
	 * imported entries of either post type do not expire. Rendered content
	 * receives user-specific nonces immediately before output.
	 *
	 * @param string $content Post content.
	 * @return string Filtered content.
	 */
	public static function filter_private_media_content_urls( $content ) {
		$content = (string) $content;
		if ( false === strpos( $content, 'wp-image-' ) && false === strpos( $content, 'action=' . self::PRIVATE_MEDIA_ACTION ) ) {
			return $content;
		}

		// Pass 1 — re-nonce every quoted private-media endpoint URL (img, video,
		// audio, and file blocks all reference the same authenticated endpoint).
		// Matches both nonce-less stored URLs and legacy stored URLs whose baked
		// nonce has expired; either way the whole attribute value is rebuilt.
		$filtered = preg_replace_callback(
			'/(?<=\bsrc=|href=)(["\'])[^"\']*action=' . preg_quote( self::PRIVATE_MEDIA_ACTION, '/' ) . '(?:&|&#0?38;|&amp;)attachment_id=([0-9]+)[^"\']*\1/i',
			static function ( $matches ) {
				return self::replace_private_media_endpoint_url( $matches );
			},
			$content
		);
		$content  = is_string( $filtered ) ? $filtered : $content;

		// Pass 2 — legacy imported image markup that still points at the raw
		// uploads path gets its src rewritten to a fresh authenticated URL.
		$filtered = preg_replace_callback(
			'/<img\b[^>]*\bclass=(["\'])(?:(?!\1).)*\bwp-image-([0-9]+)\b(?:(?!\1).)*\1[^>]*>/i',
			static function ( $matches ) {
				return self::replace_private_media_img_src( $matches );
			},
			$content
		);

		return is_string( $filtered ) ? $filtered : $content;
	}

	/**
	 * Replace one quoted private-media endpoint URL with a freshly nonce'd one.
	 *
	 * @param array<int,string> $matches Regex matches (1: quote, 2: attachment ID).
	 * @return string Replacement quoted URL.
	 */
	private static function replace_private_media_endpoint_url( $matches ) {
		$original      = isset( $matches[0] ) ? (string) $matches[0] : '';
		$quote         = isset( $matches[1] ) ? (string) $matches[1] : '"';
		$attachment_id = isset( $matches[2] ) ? absint( $matches[2] ) : 0;
		if ( ! $attachment_id || 'day-one-export' !== (string) get_post_meta( $attachment_id, '_day_one_source', true ) ) {
			return $original;
		}

		$url = self::private_media_url( $attachment_id, true );
		if ( '' === $url ) {
			return $original;
		}

		return $quote . esc_url( $url ) . $quote;
	}

	/**
	 * Replace one imported Day One image src with a fresh authenticated URL.
	 *
	 * @param array<int,string> $matches Regex matches.
	 * @return string Replacement image tag.
	 */
	private static function replace_private_media_img_src( $matches ) {
		$img           = isset( $matches[0] ) ? (string) $matches[0] : '';
		$attachment_id = isset( $matches[2] ) ? absint( $matches[2] ) : 0;
		if ( ! $attachment_id || 'day-one-export' !== (string) get_post_meta( $attachment_id, '_day_one_source', true ) ) {
			return $img;
		}

		$url = self::private_media_url( $attachment_id, true );
		if ( '' === $url ) {
			return $img;
		}

		$escaped_url = esc_url( $url );
		if ( preg_match( '/\ssrc=(["\']).*?\1/i', $img ) ) {
			$replaced = preg_replace( '/\ssrc=(["\']).*?\1/i', ' src="' . $escaped_url . '"', $img, 1 );
			return is_string( $replaced ) ? $replaced : $img;
		}

		$replaced = preg_replace( '/\s*\/?>$/', ' src="' . $escaped_url . '" />', $img, 1 );
		return is_string( $replaced ) ? $replaced : $img;
	}

	/**
	 * Serve protected Day One media to users who can read the associated post.
	 *
	 * @return void
	 */
	public static function serve_private_media() {
		if ( ! check_ajax_referer( self::PRIVATE_MEDIA_ACTION, 'nonce', false ) ) {
			self::private_media_status( 403 );
		}

		$attachment_id = isset( $_GET['attachment_id'] ) && is_scalar( $_GET['attachment_id'] ) ? absint( wp_unslash( $_GET['attachment_id'] ) ) : 0;
		if ( ! $attachment_id || 'day-one-export' !== (string) get_post_meta( $attachment_id, '_day_one_source', true ) ) {
			self::private_media_status( 404 );
		}

		// Orphaned attachments (parent post deleted) resolve to publish status,
		// which would degrade read_post to the plain read capability, so they
		// fail closed: only users who can edit the attachment (or its owner)
		// may fetch the bytes once the parent post is gone.
		$parent_id = wp_get_post_parent_id( $attachment_id );
		if ( $parent_id ) {
			$can_read = current_user_can( 'read_post', $parent_id );
		} else {
			$attachment_post = get_post( $attachment_id );
			$is_owner        = $attachment_post && get_current_user_id() && get_current_user_id() === (int) $attachment_post->post_author;
			$can_read        = $is_owner || current_user_can( 'edit_post', $attachment_id );
		}
		if ( ! $can_read ) {
			self::private_media_status( is_user_logged_in() ? 403 : 401 );
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! Day_One_Importer_Cleanup::is_readable( $file ) || ! self::is_private_upload_path( $file ) ) {
			self::private_media_status( 404 );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Private media responses need bounded streaming; WP_Filesystem loads the full file.
		$handle = fopen( $file, 'rb' );
		if ( ! is_resource( $handle ) ) {
			self::private_media_status( 404 );
		}

		$mime = day_one_importer_sanitize_mime_type( get_post_mime_type( $attachment_id ) );
		if ( ! $mime || 0 !== strpos( (string) $mime, 'image/' ) ) {
			$type = wp_check_filetype( $file );
			$mime = ! empty( $type['type'] ) ? day_one_importer_sanitize_mime_type( $type['type'] ) : 'application/octet-stream';
		}

		nocache_headers();
		header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0' );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . absint( Day_One_Importer_Cleanup::size( $file ) ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: same-origin' );

		$disposition_filename = sanitize_file_name( basename( $file ) );
		if ( '' !== $disposition_filename ) {
			header( 'Content-Disposition: inline; filename="' . $disposition_filename . '"' );
		}

		while ( ! feof( $handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Private media responses need bounded streaming; WP_Filesystem loads the full file.
			$chunk = fread( $handle, 1048576 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Streaming trusted private media bytes after nonce, capability, MIME, and path checks.
			echo $chunk;
			flush();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the native stream opened above.
		fclose( $handle );
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
		$private_root = Day_One_Importer_Cleanup::normalize_path( self::private_media_base_dir() );
		$file         = Day_One_Importer_Cleanup::normalize_path( $file );
		if ( '' === $private_root || '' === $file || ! Day_One_Importer_Cleanup::is_file( $file ) ) {
			return false;
		}

		// Resolve symlinks and dot segments on both sides before the prefix
		// comparison so a crafted attached-file path cannot escape the root.
		$real_file = realpath( $file );
		$real_root = realpath( $private_root );
		if ( false !== $real_file && false !== $real_root ) {
			return Day_One_Importer_Cleanup::path_is_inside(
				Day_One_Importer_Cleanup::normalize_path( $real_file ),
				Day_One_Importer_Cleanup::normalize_path( $real_root )
			);
		}

		return Day_One_Importer_Cleanup::path_is_inside( $file, $private_root );
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
