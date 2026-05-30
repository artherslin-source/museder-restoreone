<?php
/**
 * Verify sliced restore extraction uses sidecar partial files (atomic promote).
 *
 * Usage: php tools/qa/verify-restore-slice-atomic-write.php
 *
 * @package MusederRestoreOne
 */

if ( php_sapi_name() !== 'cli' ) {
	exit( 1 );
}

$root = dirname( __DIR__, 2 );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}
require_once $root . '/includes/helpers.php';
require_once $root . '/includes/class-restore-service.php';

$failures = 0;
$tmpdir   = sys_get_temp_dir() . '/mro-restore-slice-' . bin2hex( random_bytes( 4 ) );
$site     = $tmpdir . '/site/wp-admin/includes';

if ( ! is_dir( $site ) && ! mkdir( $site, 0700, true ) && ! is_dir( $site ) ) {
	fwrite( STDERR, "FAIL: mkdir\n" );
	exit( 1 );
}

$live_file = $site . '/class-wp-site-health.php';
$original  = str_repeat( 'L', 128 );
file_put_contents( $live_file, $original );

$ref      = new ReflectionClass( 'Museder_Restoreone_Restore_Service' );
$partial  = $ref->getMethod( 'restore_partial_path' );
$open     = $ref->getMethod( 'open_restore_slice_output' );
$finalize = $ref->getMethod( 'finalize_restore_slice_output' );
$suffix   = (string) $ref->getConstant( 'RESTORE_PARTIAL_SUFFIX' );

$partial->setAccessible( true );
$open->setAccessible( true );
$finalize->setAccessible( true );

$partial_file = $partial->invoke( null, $live_file );
if ( $partial_file !== $live_file . $suffix ) {
	fwrite( STDERR, "FAIL: unexpected partial path\n" );
	++$failures;
}

$offset = 0;
$handle = $open->invokeArgs( null, [ $live_file, &$offset ] );
if ( ! is_resource( $handle ) ) {
	fwrite( STDERR, "FAIL: could not open partial output\n" );
	++$failures;
} else {
	fwrite( $handle, str_repeat( 'P', 90112 ) );
	fclose( $handle );
}

if ( file_get_contents( $live_file ) !== $original ) {
	fwrite( STDERR, "FAIL: live destination changed before finalize\n" );
	++$failures;
}

if ( ! file_exists( $partial_file ) || filesize( $partial_file ) !== 90112 ) {
	fwrite( STDERR, "FAIL: partial sidecar missing or wrong size\n" );
	++$failures;
}

if ( ! $finalize->invoke( null, $live_file ) ) {
	fwrite( STDERR, "FAIL: finalize rename failed\n" );
	++$failures;
}

if ( file_exists( $partial_file ) ) {
	fwrite( STDERR, "FAIL: partial sidecar not removed after finalize\n" );
	++$failures;
}

if ( file_get_contents( $live_file ) !== str_repeat( 'P', 90112 ) ) {
	fwrite( STDERR, "FAIL: live file not updated after finalize\n" );
	++$failures;
}

// Resume path: partial exists with offset; live file must stay untouched until finalize.
$live2 = $site . '/resume-target.php';
file_put_contents( $live2, 'ORIGINAL' );
$offset = 0;
$handle = $open->invokeArgs( null, [ $live2, &$offset ] );
if ( is_resource( $handle ) ) {
	fwrite( $handle, 'PARTIAL-BYTES' );
	fclose( $handle );
}
$offset = 13;
$handle = $open->invokeArgs( null, [ $live2, &$offset ] );
if ( is_resource( $handle ) ) {
	fwrite( $handle, '-MORE' );
	fclose( $handle );
}

if ( file_get_contents( $live2 ) !== 'ORIGINAL' ) {
	fwrite( STDERR, "FAIL: live file changed during resumed partial write\n" );
	++$failures;
}

if ( ! $finalize->invoke( null, $live2 ) || file_get_contents( $live2 ) !== 'PARTIAL-BYTES-MORE' ) {
	fwrite( STDERR, "FAIL: resumed partial finalize content mismatch\n" );
	++$failures;
}

$cleanup = static function ( $path ) use ( &$cleanup ) {
	if ( is_file( $path ) ) {
		unlink( $path );
		return;
	}
	if ( ! is_dir( $path ) ) {
		return;
	}
	foreach ( scandir( $path ) ?: [] as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$cleanup( $path . '/' . $item );
	}
	rmdir( $path );
};
$cleanup( $tmpdir );

if ( $failures > 0 ) {
	fwrite( STDERR, "RESTORE_SLICE_ATOMIC_QA=FAIL ({$failures})\n" );
	exit( 1 );
}

echo "RESTORE_SLICE_ATOMIC_QA=PASS\n";
