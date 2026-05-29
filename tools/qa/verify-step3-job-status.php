<?php
/**
 * Verify Step 3 page job payload includes derived status for admin.js.
 *
 * Usage: php tools/qa/verify-step3-job-status.php
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

require_once $root . '/includes/class-restore-handler.php';

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

$failures = 0;

$running = Museder_Restoreone_Restore_Handler::map_restore_service_status_to_job(
	'rjb_test_running',
	[
		'stage'     => 'restore-files',
		'progress'  => 42,
		'completed' => false,
		'message'   => 'Restoring files…',
		'last_tick' => time(),
		'started_at' => time() - 60,
	]
);
if ( empty( $running['status'] ) || 'running' !== $running['status'] ) {
	fwrite( STDERR, "FAIL: running job should map to status running\n" );
	++$failures;
}

$done = Museder_Restoreone_Restore_Handler::map_restore_service_status_to_job(
	'rjb_test_done',
	[
		'stage'     => 'done',
		'progress'  => 100,
		'completed' => true,
	]
);
if ( 'success' !== ( $done['status'] ?? '' ) ) {
	fwrite( STDERR, "FAIL: completed done job should map to success\n" );
	++$failures;
}

require_once $root . '/includes/class-restore-preflight.php';
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
	}
}

$files_only = Museder_Restoreone_Restore_Preflight::normalize_options(
	[
		'files_only'      => true,
		'restore_scope'   => Museder_Restoreone_Restore_Preflight::SCOPE_FULL,
		'wp_config_mode'  => Museder_Restoreone_Restore_Preflight::MODE_CONFIG_BACKUP,
	]
);
if ( Museder_Restoreone_Restore_Preflight::MODE_CONFIG_KEEP !== ( $files_only['wp_config_mode'] ?? '' ) ) {
	fwrite( STDERR, "FAIL: files_only should force wp_config_mode keep\n" );
	++$failures;
}
if ( empty( $files_only['skip_config'] ) ) {
	fwrite( STDERR, "FAIL: files_only should set skip_config\n" );
	++$failures;
}
if ( ! Museder_Restoreone_Restore_Preflight::should_skip_wp_config_in_zip( $files_only ) ) {
	fwrite( STDERR, "FAIL: files_only should skip wp-config in zip extract\n" );
	++$failures;
}

if ( $failures > 0 ) {
	fwrite( STDERR, "STEP3_JOB_STATUS_QA=FAIL ({$failures} checks)\n" );
	exit( 1 );
}

echo "STEP3_JOB_STATUS_QA=PASS\n";
