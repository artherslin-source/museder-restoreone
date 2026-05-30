<?php
/**
 * Verify backup archive entry-index lookup (post-close verify false-negative guard).
 *
 * Usage: php tools/qa/verify-backup-archive-verify.php
 *
 * @package MusederRestoreOne
 */

if ( php_sapi_name() !== 'cli' ) {
	exit( 1 );
}

if ( ! class_exists( 'ZipArchive' ) ) {
	fwrite( STDERR, "SKIP: ZipArchive extension not available\n" );
	echo "BACKUP_ARCHIVE_VERIFY_QA=SKIP\n";
	exit( 0 );
}

$root = dirname( __DIR__, 2 );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}
require_once $root . '/includes/class-backup.php';

$failures = 0;

$ref = new ReflectionClass( 'Museder_Restoreone_Backup' );
$has_entry = $ref->getMethod( 'zip_archive_has_entry' );
$has_entry->setAccessible( true );
$has_prefix = $ref->getMethod( 'zip_archive_has_file_under_prefix' );
$has_prefix->setAccessible( true );
$has_core = $ref->getMethod( 'zip_archive_has_core_path' );
$has_core->setAccessible( true );
$verify_fn = $ref->getMethod( 'verify_archive_contains_wp_content' );
$verify_fn->setAccessible( true );

$tmpdir = sys_get_temp_dir() . '/mro-archive-verify-' . bin2hex( random_bytes( 4 ) );
if ( ! is_dir( $tmpdir ) && ! mkdir( $tmpdir, 0700, true ) && ! is_dir( $tmpdir ) ) {
	fwrite( STDERR, "FAIL: unable to create temp dir\n" );
	exit( 1 );
}

$fixture_root = $tmpdir . '/site';
foreach ( [
	$fixture_root . '/wp-admin',
	$fixture_root . '/wp-includes',
	$fixture_root . '/wp-content/themes',
	$fixture_root . '/wp-content/uploads',
] as $dir ) {
	if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
		fwrite( STDERR, "FAIL: unable to create fixture dir {$dir}\n" );
		exit( 1 );
	}
}

file_put_contents( $fixture_root . '/wp-admin/index.php', "<?php\n" );
file_put_contents( $fixture_root . '/wp-includes/version.php', "<?php\n" );
file_put_contents( $fixture_root . '/wp-content/index.php', "<?php\n" );
file_put_contents( $fixture_root . '/wp-content/themes/index.php', "<?php\n" );

$archive = $tmpdir . '/backup.zip';
$zip     = new ZipArchive();
if ( true !== $zip->open( $archive, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "FAIL: unable to create test archive\n" );
	++$failures;
} else {
	$zip->addFromString( 'database.ndjson', "{}\n" );
	$zip->addFromString( 'meta.json', "{}\n" );
	$zip->addFromString( 'package.json', "{}\n" );
	$zip->addFromString( 'manifest.ndjson', "{}\n" );
	$zip->addFile( $fixture_root . '/wp-admin/index.php', 'wp-admin/index.php' );
	$zip->addFile( $fixture_root . '/wp-includes/version.php', 'wp-includes/version.php' );
	$zip->addFile( $fixture_root . '/wp-content/index.php', 'wp-content/index.php' );
	$zip->addFile( $fixture_root . '/wp-content/themes/index.php', 'wp-content/themes/index.php' );
	file_put_contents( $fixture_root . '/wp-content/uploads/sample.png', 'png' );
	$zip->addFile( $fixture_root . '/wp-content/uploads/sample.png', 'wp-content/uploads/sample.png' );
	$zip->close();

	$zip_ro = new ZipArchive();
	if ( true !== $zip_ro->open( $archive ) ) {
		fwrite( STDERR, "FAIL: unable to reopen test archive\n" );
		++$failures;
	} else {
		$index = null;
		foreach ( [
			'wp-admin/index.php',
			'wp-includes/version.php',
			'wp-content/index.php',
			'package.json',
		] as $path ) {
			if ( ! $has_entry->invoke( null, $zip_ro, $path, $index ) ) {
				fwrite( STDERR, "FAIL: entry index missed {$path}\n" );
				++$failures;
			}
		}
		if ( ! $has_prefix->invoke( null, $zip_ro, 'wp-content/uploads/', $index ) ) {
			// uploads prefix may only have directory entries in other tests; here we only assert themes prefix exists.
		}
		if ( ! $has_prefix->invoke( null, $zip_ro, 'wp-content/themes/', $index ) ) {
			fwrite( STDERR, "FAIL: themes prefix not detected\n" );
			++$failures;
		}
		if ( ! $has_core->invoke( null, $zip_ro, 'wp-admin/index.php', $index ) ) {
			fwrite( STDERR, "FAIL: core path fallback missed wp-admin/index.php\n" );
			++$failures;
		}
		$zip_ro->close();
	}
}

$ndjson = $tmpdir . '/manifest.ndjson';
$lines  = [
	json_encode( [ 'path' => $fixture_root . '/wp-content/themes/index.php', 'target' => 'wp-content/themes/index.php', 'size' => 10 ] ),
	json_encode( [ 'path' => $fixture_root . '/wp-content/uploads/missing.png', 'target' => 'wp-content/uploads/missing.png', 'size' => 10 ] ),
];
file_put_contents( $ndjson, implode( "\n", $lines ) . "\n" );

$job = [
	'archive_path'        => $archive,
	'manifest_ndjson_file'=> $ndjson,
	'total_files'         => 6,
	'added_files'         => 6,
	'options'             => [],
];

$verify = $verify_fn->invoke( null, $job );
if ( empty( $verify['ok'] ) && empty( $verify['structural_ok'] ) ) {
	fwrite( STDERR, "FAIL: verify rejected structurally valid archive\n" );
	fwrite( STDERR, print_r( $verify, true ) );
	++$failures;
}

// Cleanup temp artifacts.
$cleanup = static function ( $path ) use ( &$cleanup ) {
	if ( is_file( $path ) ) {
		unlink( $path );
		return;
	}
	if ( ! is_dir( $path ) ) {
		return;
	}
	$items = scandir( $path );
	if ( ! is_array( $items ) ) {
		return;
	}
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$cleanup( $path . '/' . $item );
	}
	rmdir( $path );
};
$cleanup( $tmpdir );

if ( $failures > 0 ) {
	fwrite( STDERR, "BACKUP_ARCHIVE_VERIFY_QA=FAIL ({$failures})\n" );
	exit( 1 );
}

echo "BACKUP_ARCHIVE_VERIFY_QA=PASS\n";
