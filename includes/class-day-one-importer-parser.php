<?php
/**
 * Day One export parser.
 *
 * @package Day_One_Importer
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'DAY_ONE_IMPORTER_TESTING' ) ) {
	exit;
}

/**
 * Finds journal JSON files and normalizes entries.
 */
class Day_One_Importer_Parser {
	/**
	 * Parse all Day One journal JSON files under an extracted export root.
	 *
	 * @param string                   $root Extraction root.
	 * @param Day_One_Importer_Results $results Results object.
	 * @return array<int,array<string,mixed>> Normalized entries.
	 */
	public function parse_export( $root, Day_One_Importer_Results $results ) {
		$files = $this->find_journal_json_files( $root );
		$results->set_count( 'json_files_found', count( $files ) );

		$entries = array();
		$seen    = array();

		foreach ( $files as $file ) {
			$data = $this->decode_json_file( $file );
			if ( ! is_array( $data ) || ! isset( $data['entries'] ) || ! is_array( $data['entries'] ) ) {
				continue;
			}

			foreach ( $data['entries'] as $index => $raw_entry ) {
				$results->increment( 'entries_found' );
				$entry = $this->normalize_entry( $raw_entry, $file, $index, $results );
				if ( null === $entry ) {
					continue;
				}

				$uuid_key = strtolower( $entry['uuid'] );
				if ( isset( $seen[ $uuid_key ] ) ) {
					$results->add_warning(
						sprintf(
							/* translators: %s: Day One UUID. */
							__( 'Duplicate UUID in export skipped: %s', 'day-one-importer' ),
							$entry['uuid']
						)
					);
					continue;
				}

				$seen[ $uuid_key ] = true;
				$entries[]         = $entry;
			}
		}

		return $entries;
	}

	/**
	 * Find journal JSON files with an entries array.
	 *
	 * @param string $root Extraction root.
	 * @return string[]
	 */
	public function find_journal_json_files( $root ) {
		$root_real = realpath( $root );
		if ( false === $root_real || ! is_dir( $root_real ) ) {
			return array();
		}

		$files    = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root_real, FilesystemIterator::SKIP_DOTS ) );

		foreach ( $iterator as $item ) {
			if ( ! $item->isFile() ) {
				continue;
			}

			$path = $item->getPathname();
			if ( false !== strpos( $path, DIRECTORY_SEPARATOR . '__MACOSX' . DIRECTORY_SEPARATOR ) ) {
				continue;
			}

			$filename = $item->getFilename();
			if ( 0 === strpos( $filename, '.' ) ) {
				continue;
			}

			if ( 'json' !== strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ) ) {
				continue;
			}

			$data = $this->decode_json_file( $item->getPathname() );
			if ( is_array( $data ) && isset( $data['entries'] ) && is_array( $data['entries'] ) ) {
				$files[] = $item->getPathname();
			}
		}

		sort( $files );
		return $files;
	}

	/**
	 * Decode JSON file defensively.
	 *
	 * @param string $file File path.
	 * @return mixed
	 */
	public function decode_json_file( $file ) {
		if ( function_exists( 'wp_json_file_decode' ) ) {
			$data = wp_json_file_decode( $file, array( 'associative' => true ) );
			if ( null !== $data ) {
				return $data;
			}
		}

		$contents = @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return null;
		}

		return json_decode( $contents, true );
	}

	/**
	 * Normalize a raw entry.
	 *
	 * @param mixed                    $raw_entry Raw entry.
	 * @param string                   $source_file Source JSON file.
	 * @param int                      $index Entry index.
	 * @param Day_One_Importer_Results $results Results.
	 * @return array<string,mixed>|null
	 */
	public function normalize_entry( $raw_entry, $source_file, $index, Day_One_Importer_Results $results ) {
		if ( ! is_array( $raw_entry ) ) {
			$results->increment( 'entries_failed' );
			$results->add_warning(
				sprintf(
					/* translators: %s: JSON filename. */
					__( 'Malformed entry skipped in %s.', 'day-one-importer' ),
					basename( $source_file )
				)
			);
			return null;
		}

		$uuid = isset( $raw_entry['uuid'] ) && is_scalar( $raw_entry['uuid'] ) ? day_one_importer_sanitize_text( $raw_entry['uuid'] ) : '';
		if ( '' === $uuid ) {
			$results->increment( 'entries_failed' );
			$results->add_warning(
				sprintf(
					/* translators: 1: JSON filename, 2: entry index. */
					__( 'Entry without UUID skipped in %1$s at index %2$d.', 'day-one-importer' ),
					basename( $source_file ),
					(int) $index
				)
			);
			return null;
		}

		$photos = array();
		if ( isset( $raw_entry['photos'] ) && is_array( $raw_entry['photos'] ) ) {
			foreach ( $raw_entry['photos'] as $photo ) {
				if ( is_array( $photo ) ) {
					$photos[] = $this->normalize_photo( $photo );
				}
			}
		}

		return array(
			'uuid'                 => $uuid,
			'creationDate'         => isset( $raw_entry['creationDate'] ) && is_scalar( $raw_entry['creationDate'] ) ? (string) $raw_entry['creationDate'] : '',
			'modifiedDate'         => isset( $raw_entry['modifiedDate'] ) && is_scalar( $raw_entry['modifiedDate'] ) ? (string) $raw_entry['modifiedDate'] : '',
			'timeZone'             => isset( $raw_entry['timeZone'] ) && is_scalar( $raw_entry['timeZone'] ) ? day_one_importer_sanitize_text( $raw_entry['timeZone'] ) : '',
			'text'                 => isset( $raw_entry['text'] ) && is_scalar( $raw_entry['text'] ) ? (string) $raw_entry['text'] : '',
			'tags'                 => isset( $raw_entry['tags'] ) && is_array( $raw_entry['tags'] ) ? $raw_entry['tags'] : array(),
			'photos'               => $photos,
			'starred'              => ! empty( $raw_entry['starred'] ),
			'isPinned'             => ! empty( $raw_entry['isPinned'] ),
			'creationDeviceType'   => isset( $raw_entry['creationDeviceType'] ) && is_scalar( $raw_entry['creationDeviceType'] ) ? day_one_importer_sanitize_text( $raw_entry['creationDeviceType'] ) : '',
			'creationDeviceModel'  => isset( $raw_entry['creationDeviceModel'] ) && is_scalar( $raw_entry['creationDeviceModel'] ) ? day_one_importer_sanitize_text( $raw_entry['creationDeviceModel'] ) : '',
			'source_file'          => basename( $source_file ),
		);
	}

	/**
	 * Normalize photo metadata.
	 *
	 * @param array<string,mixed> $photo Raw photo.
	 * @return array<string,mixed>
	 */
	private function normalize_photo( $photo ) {
		return array(
			'identifier'   => isset( $photo['identifier'] ) && is_scalar( $photo['identifier'] ) ? day_one_importer_sanitize_text( $photo['identifier'] ) : '',
			'md5'          => isset( $photo['md5'] ) && is_scalar( $photo['md5'] ) ? strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) $photo['md5'] ) ) : '',
			'type'         => isset( $photo['type'] ) && is_scalar( $photo['type'] ) ? strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $photo['type'] ) ) : '',
			'filename'     => isset( $photo['filename'] ) && is_scalar( $photo['filename'] ) ? basename( day_one_importer_sanitize_text( $photo['filename'] ) ) : '',
			'date'         => isset( $photo['date'] ) && is_scalar( $photo['date'] ) ? day_one_importer_sanitize_text( $photo['date'] ) : '',
			'orderInEntry' => isset( $photo['orderInEntry'] ) && is_numeric( $photo['orderInEntry'] ) ? (int) $photo['orderInEntry'] : null,
			'width'        => isset( $photo['width'] ) && is_numeric( $photo['width'] ) ? (int) $photo['width'] : 0,
			'height'       => isset( $photo['height'] ) && is_numeric( $photo['height'] ) ? (int) $photo['height'] : 0,
		);
	}
}
