<?php
/**
 * Build the committed fictional Day One sample ZIP.
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

		if ( empty( $entry['photos'] ) ) {
			continue;
		}

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
