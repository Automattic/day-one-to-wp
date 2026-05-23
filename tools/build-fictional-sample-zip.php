<?php
/**
 * Build the committed fictional Day One sample ZIP.
 *
 * Video fixture regeneration (spec #57 R11.2):
 *   mkdir -p tests/fixtures/day-one-fictional/videos
 *   ffmpeg -y -f lavfi -i color=c=black:s=320x180:d=1:r=15 -an \
 *     -c:v libx264 -profile:v baseline -pix_fmt yuv420p \
 *     -movflags +faststart \
 *     tests/fixtures/day-one-fictional/videos/_tmp.mov
 *   md5 -q tests/fixtures/day-one-fictional/videos/_tmp.mov  # macOS; or md5sum on Linux
 *   mv tests/fixtures/day-one-fictional/videos/_tmp.mov \
 *      tests/fixtures/day-one-fictional/videos/<lowercase md5>.mov
 * Verify size <= 200 KB (#57 R11.2 / K1). If over, lower the bitrate with -b:v 100k.
 *
 * Audio fixture regeneration (spec #58 R11.2):
 *   mkdir -p tests/fixtures/day-one-fictional/audios
 *   ffmpeg -y -f lavfi -i sine=frequency=440:duration=1:sample_rate=22050 \
 *     -c:a libmp3lame -b:a 32k -ac 1 \
 *     tests/fixtures/day-one-fictional/audios/_tmp.mp3
 *   md5 -q tests/fixtures/day-one-fictional/audios/_tmp.mp3  # macOS; or md5sum on Linux
 *   mv tests/fixtures/day-one-fictional/audios/_tmp.mp3 \
 *      tests/fixtures/day-one-fictional/audios/<lowercase md5>.mp3
 * Verify size <= 50 KB (#58 R11.2 / K1). If over, lower the bitrate further
 * (-b:a 24k) or drop sample rate to 16000. Output MUST remain .mp3.
 *
 * PDF fixture regeneration (spec #59 R11.2). The canonical path is a
 * hand-crafted minimal one-page PDF (path 1; ~300 bytes; no embedded fonts;
 * blank MediaBox). The structure is:
 *   %PDF-1.4
 *   1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj
 *   2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj
 *   3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]>>endobj
 *   xref
 *   0 4
 *   <byte offsets per object, formatted as `nnnnnnnnnn 00000 n \n`>
 *   trailer<</Size 4/Root 1 0 R>>
 *   startxref
 *   <byte offset of xref>
 *   %%EOF
 * Commit the file as tests/fixtures/day-one-fictional/pdfs/<lowercase md5>.pdf
 * (rename after computing md5_file()). Verify size <= 5 KB (#59 R11.2 / K1).
 * A pandoc-based fallback path is documented in spec R11.2 but is NOT used
 * by CI -- the implementer commits the file pre-made; this build tool
 * validates the committed md5 only.
 *
 * @package Day_One_Importer
 */

$root       = dirname( __DIR__ );
$source_dir = $root . '/tests/fixtures/day-one-fictional';
$zip_path   = $root . '/tests/fixtures/day-one-fictional.zip';

function day_one_fixture_fail( $message ) {
	fwrite( STDERR, "ERROR: {$message}\n" );
	exit( 1 );
}

function day_one_fixture_relative_path( $base, $path ) {
	$base = rtrim( str_replace( '\\', '/', realpath( $base ) ), '/' ) . '/';
	$path = str_replace( '\\', '/', realpath( $path ) );
	if ( 0 !== strpos( $path, $base ) ) {
		day_one_fixture_fail( 'Fixture path escaped source directory.' );
	}

	return substr( $path, strlen( $base ) );
}

function day_one_fixture_is_hidden_or_temporary( $relative ) {
	$parts = explode( '/', str_replace( '\\', '/', $relative ) );
	foreach ( $parts as $part ) {
		if ( '' === $part ) {
			continue;
		}
		if ( 0 === strpos( $part, '.' ) || '__MACOSX' === $part ) {
			return true;
		}
	}

	return (bool) preg_match( '/(?:~|\.tmp)$/i', $relative );
}

function day_one_fixture_validate_json_file( $json_file, $source_dir ) {
	$contents = file_get_contents( $json_file );
	if ( false === $contents ) {
		day_one_fixture_fail( 'Could not read fixture JSON.' );
	}

	$data = json_decode( $contents, true );
	if ( JSON_ERROR_NONE !== json_last_error() ) {
		day_one_fixture_fail( 'Fixture JSON is invalid: ' . json_last_error_msg() );
	}

	if ( ! is_array( $data ) || ! isset( $data['entries'] ) || ! is_array( $data['entries'] ) ) {
		day_one_fixture_fail( 'Fixture JSON must contain an entries array.' );
	}

	foreach ( $data['entries'] as $entry_index => $entry ) {
		if ( ! is_array( $entry ) ) {
			day_one_fixture_fail( "Entry {$entry_index} is not an object." );
		}

		if ( empty( $entry['uuid'] ) || ! is_scalar( $entry['uuid'] ) ) {
			day_one_fixture_fail( "Entry {$entry_index} is missing a UUID." );
		}

		if ( ! empty( $entry['photos'] ) ) {
			if ( ! is_array( $entry['photos'] ) ) {
				day_one_fixture_fail( "Photos for entry {$entry_index} must be an array." );
			}

			foreach ( $entry['photos'] as $photo_index => $photo ) {
				if ( ! is_array( $photo ) ) {
					day_one_fixture_fail( "Photo {$photo_index} for entry {$entry_index} is not an object." );
				}

				$md5  = isset( $photo['md5'] ) && is_scalar( $photo['md5'] ) ? strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) $photo['md5'] ) ) : '';
				$type = isset( $photo['type'] ) && is_scalar( $photo['type'] ) ? strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $photo['type'] ) ) : '';
				if ( '' === $md5 || '' === $type ) {
					day_one_fixture_fail( "Photo {$photo_index} for entry {$entry_index} needs md5 and type metadata." );
				}

				$photo_path = $source_dir . '/photos/' . $md5 . '.' . $type;
				if ( ! is_file( $photo_path ) ) {
					day_one_fixture_fail( "Photo file missing for metadata {$md5}.{$type}." );
				}

				$actual_md5 = md5_file( $photo_path );
				if ( $actual_md5 !== $md5 ) {
					day_one_fixture_fail( "Photo file MD5 mismatch for {$md5}.{$type}; actual hash is {$actual_md5}." );
				}
			}
		}

		if ( ! empty( $entry['videos'] ) ) {
			if ( ! is_array( $entry['videos'] ) ) {
				day_one_fixture_fail( "Videos for entry {$entry_index} must be an array." );
			}

			foreach ( $entry['videos'] as $video_index => $video ) {
				if ( ! is_array( $video ) ) {
					day_one_fixture_fail( "Video {$video_index} for entry {$entry_index} is not an object." );
				}

				$md5  = isset( $video['md5'] ) && is_scalar( $video['md5'] ) ? strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) $video['md5'] ) ) : '';
				$type = isset( $video['type'] ) && is_scalar( $video['type'] ) ? strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $video['type'] ) ) : '';
				if ( '' === $md5 || '' === $type ) {
					day_one_fixture_fail( "Video {$video_index} for entry {$entry_index} needs md5 and type metadata." );
				}

				$video_path = $source_dir . '/videos/' . $md5 . '.' . $type;
				if ( ! is_file( $video_path ) ) {
					day_one_fixture_fail( "Video file missing for metadata {$md5}.{$type}." );
				}

				$actual_md5 = md5_file( $video_path );
				if ( $actual_md5 !== $md5 ) {
					day_one_fixture_fail( "Video file MD5 mismatch for {$md5}.{$type}; actual hash is {$actual_md5}." );
				}

				$size = filesize( $video_path );
				if ( $size > 200 * 1024 ) {
					day_one_fixture_fail( "Video fixture {$md5}.{$type} exceeds the 200 KB cap ({$size} bytes); see header for regeneration command." );
				}
			}
		}

		if ( ! empty( $entry['audios'] ) ) {
			if ( ! is_array( $entry['audios'] ) ) {
				day_one_fixture_fail( "Audios for entry {$entry_index} must be an array." );
			}

			foreach ( $entry['audios'] as $audio_index => $audio ) {
				if ( ! is_array( $audio ) ) {
					day_one_fixture_fail( "Audio {$audio_index} for entry {$entry_index} is not an object." );
				}

				// #58 R4.5 — audio records use `format`, not `type`.
				$md5    = isset( $audio['md5'] ) && is_scalar( $audio['md5'] ) ? strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) $audio['md5'] ) ) : '';
				$format = isset( $audio['format'] ) && is_scalar( $audio['format'] ) ? strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', (string) $audio['format'] ) ) : '';
				if ( '' === $md5 || '' === $format ) {
					day_one_fixture_fail( "Audio {$audio_index} for entry {$entry_index} needs md5 and format metadata." );
				}

				$audio_path = $source_dir . '/audios/' . $md5 . '.' . $format;
				if ( ! is_file( $audio_path ) ) {
					day_one_fixture_fail( "Audio file missing for metadata {$md5}.{$format}." );
				}

				$actual_md5 = md5_file( $audio_path );
				if ( $actual_md5 !== $md5 ) {
					day_one_fixture_fail( "Audio file MD5 mismatch for {$md5}.{$format}; actual hash is {$actual_md5}." );
				}

				$size = filesize( $audio_path );
				if ( $size > 50 * 1024 ) {
					day_one_fixture_fail( "Audio fixture {$md5}.{$format} exceeds the 50 KB cap ({$size} bytes); see header for regeneration command." );
				}
			}
		}

		if ( ! empty( $entry['pdfAttachments'] ) ) {
			if ( ! is_array( $entry['pdfAttachments'] ) ) {
				day_one_fixture_fail( "pdfAttachments for entry {$entry_index} must be an array." );
			}

			foreach ( $entry['pdfAttachments'] as $pdf_index => $pdf ) {
				if ( ! is_array( $pdf ) ) {
					day_one_fixture_fail( "PDF {$pdf_index} for entry {$entry_index} is not an object." );
				}

				// #59 R1.3 — PDF records have no `type`/`format` field; extension is hardcoded.
				$md5 = isset( $pdf['md5'] ) && is_scalar( $pdf['md5'] ) ? strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) $pdf['md5'] ) ) : '';
				if ( '' === $md5 ) {
					day_one_fixture_fail( "PDF {$pdf_index} for entry {$entry_index} needs md5 metadata." );
				}

				$pdf_path = $source_dir . '/pdfs/' . $md5 . '.pdf';
				if ( ! is_file( $pdf_path ) ) {
					day_one_fixture_fail( "PDF file missing for metadata {$md5}.pdf." );
				}

				$actual_md5 = md5_file( $pdf_path );
				if ( $actual_md5 !== $md5 ) {
					day_one_fixture_fail( "PDF file MD5 mismatch for {$md5}.pdf; actual hash is {$actual_md5}." );
				}

				$size = filesize( $pdf_path );
				if ( $size > 5 * 1024 ) {
					day_one_fixture_fail( "PDF fixture {$md5}.pdf exceeds the 5 KB cap ({$size} bytes); see header for hand-crafted minimal-PDF skeleton (#59 R11.2)." );
				}
			}
		}
	}

	return count( $data['entries'] );
}

if ( ! extension_loaded( 'zip' ) || ! class_exists( 'ZipArchive' ) ) {
	day_one_fixture_fail( 'PHP ZipArchive extension is required.' );
}

if ( ! is_dir( $source_dir ) ) {
	day_one_fixture_fail( 'Fixture source directory is missing.' );
}

$json_files = glob( $source_dir . '/*.json' );
if ( empty( $json_files ) ) {
	day_one_fixture_fail( 'No top-level fixture JSON file found.' );
}

$entry_count = 0;
foreach ( $json_files as $json_file ) {
	$entry_count += day_one_fixture_validate_json_file( $json_file, $source_dir );
}
if ( 0 === $entry_count ) {
	day_one_fixture_fail( 'Fixture JSON does not contain any entries.' );
}

if ( file_exists( $zip_path ) && ! unlink( $zip_path ) ) {
	day_one_fixture_fail( 'Could not remove existing ZIP.' );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	day_one_fixture_fail( 'Could not create ZIP.' );
}

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $source_dir, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::SELF_FIRST
);

$added = array();
foreach ( $iterator as $item ) {
	$relative = day_one_fixture_relative_path( $source_dir, $item->getPathname() );
	$relative = str_replace( '\\', '/', $relative );
	if ( day_one_fixture_is_hidden_or_temporary( $relative ) ) {
		continue;
	}

	if ( $item->isDir() ) {
		$zip->addEmptyDir( rtrim( $relative, '/' ) . '/' );
		continue;
	}

	if ( ! $item->isFile() ) {
		continue;
	}

	if ( ! $zip->addFile( $item->getPathname(), $relative ) ) {
		$zip->close();
		day_one_fixture_fail( "Could not add {$relative} to ZIP." );
	}
	$added[] = $relative;
}

$zip->close();
sort( $added );

echo "Built tests/fixtures/day-one-fictional.zip with " . count( $added ) . " files and {$entry_count} entries.\n";
foreach ( $added as $relative ) {
	echo " - {$relative}\n";
}
