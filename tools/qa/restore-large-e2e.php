<?php
/**
 * Headless large-backup restore E2E (run via WP-CLI inside Docker).
 *
 * Usage (from repo root, after setup-fresh-restore-test.sh):
 *   docker compose run --rm wpcli eval-file /tmp/museder-restoreone-src/tools/qa/restore-large-e2e.php
 *
 * Environment:
 *   MUSEDER_RESTORE_E2E_ZIP — backup filename under uploads/museder-restoreone/backups/
 *   MUSEDER_RESTORE_E2E_MAX_SLICES — max process_job_slice iterations (default 8000)
 *   MUSEDER_RESTORE_E2E_SLICE_SECONDS — seconds per slice (default 30)
 *
 * @package Museder_Restoreone
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This script must run inside WordPress (wp eval-file).\n" );
	exit( 1 );
}

if ( ! class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
	fwrite( STDERR, "Museder RestoreOne is not loaded.\n" );
	exit( 1 );
}

$zip_name      = getenv( 'MUSEDER_RESTORE_E2E_ZIP' ) ? (string) getenv( 'MUSEDER_RESTORE_E2E_ZIP' ) : 'sunpoweroflight.com-20260311014315-ptq9eY.zip';
$max_slices    = (int) ( getenv( 'MUSEDER_RESTORE_E2E_MAX_SLICES' ) ? getenv( 'MUSEDER_RESTORE_E2E_MAX_SLICES' ) : 8000 );
$slice_seconds = (int) ( getenv( 'MUSEDER_RESTORE_E2E_SLICE_SECONDS' ) ? getenv( 'MUSEDER_RESTORE_E2E_SLICE_SECONDS' ) : 30 );

if ( $max_slices < 1 ) {
	$max_slices = 8000;
}
if ( $slice_seconds < 5 ) {
	$slice_seconds = 30;
}

$backup_path = function_exists( 'museder_restoreone_get_backup_path' ) ? museder_restoreone_get_backup_path( $zip_name ) : '';
if ( ! $backup_path || ! file_exists( $backup_path ) ) {
	fwrite( STDERR, "Backup not found: {$zip_name}\n" );
	exit( 2 );
}

fwrite( STDERR, "[e2e] backup={$zip_name} size=" . (string) filesize( $backup_path ) . " bytes\n" );
fwrite( STDERR, "[e2e] plugin_version=" . ( defined( 'MUSEDER_RESTOREONE_VERSION' ) ? MUSEDER_RESTOREONE_VERSION : 'unknown' ) . "\n" );

// phpcs:ignore WordPress.PHP.NoSetTimeLimit
if ( function_exists( 'set_time_limit' ) ) {
	@set_time_limit( 0 );
}

$prepared = Museder_Restoreone_Restore_Service::prepare( 'existing', $zip_name );
$job_id   = isset( $prepared['job_id'] ) ? (string) $prepared['job_id'] : '';
if ( '' === $job_id ) {
	fwrite( STDERR, "[e2e] prepare failed: no job_id\n" );
	exit( 3 );
}

fwrite( STDERR, "[e2e] job_id={$job_id}\n" );

Museder_Restoreone_Restore_Service::validate( $job_id );
Museder_Restoreone_Restore_Service::execute(
	$job_id,
	[
		'auto_backup' => false,
		'safe_mode'   => true,
	]
);

$isolation_seen = false;
$started_at     = time();

for ( $i = 0; $i < $max_slices; $i++ ) {
	$mid_iso = (string) get_option( 'museder_restoreone_mid_restore_isolation', '' );
	if ( '1' === $mid_iso ) {
		$isolation_seen = true;
	}

	Museder_Restoreone_Restore_Service::process_job_slice( $job_id, $slice_seconds, false, 'cli' );

	$meta  = Museder_Restoreone_Restore_Service::get_job_meta( $job_id );
	$stage = isset( $meta['stage'] ) ? (string) $meta['stage'] : '';
	$prog  = isset( $meta['progress'] ) ? (int) $meta['progress'] : 0;
	$msg   = isset( $meta['message'] ) ? (string) $meta['message'] : '';

	if ( 0 === $i % 5 || in_array( $stage, [ 'restore-db', 'restore-files', 'search-replace', 'cleanup', 'done', 'failed', 'cancelled' ], true ) ) {
		fwrite( STDERR, sprintf( "[e2e] slice=%d stage=%s progress=%d mid_iso=%s %s\n", $i, $stage, $prog, $mid_iso, $msg ) );
	}

	if ( ! empty( $meta['completed'] ) || in_array( $stage, [ 'done', 'failed', 'cancelled' ], true ) ) {
		break;
	}
}

$meta_final  = Museder_Restoreone_Restore_Service::get_job_meta( $job_id );
$stage_final = isset( $meta_final['stage'] ) ? (string) $meta_final['stage'] : '';
$elapsed     = time() - $started_at;

$active_plugins = get_option( 'active_plugins', [] );
$active_count   = is_array( $active_plugins ) ? count( $active_plugins ) : 0;
$mid_iso_final  = (string) get_option( 'museder_restoreone_mid_restore_isolation', '' );
$snapshot       = get_option( 'museder_restoreone_restored_active_plugins', [] );
$snapshot_count = is_array( $snapshot ) ? count( $snapshot ) : 0;

$history_result = '';
if ( function_exists( 'museder_restoreone_get_restore_history' ) ) {
	$history = museder_restoreone_get_restore_history();
	if ( is_array( $history ) ) {
		foreach ( $history as $row ) {
			if ( is_array( $row ) && isset( $row['job_id'] ) && (string) $row['job_id'] === $job_id ) {
				$history_result = isset( $row['result'] ) ? (string) $row['result'] : '';
				break;
			}
		}
	}
}

$ok = ( 'done' === $stage_final )
	&& $isolation_seen
	&& '1' !== $mid_iso_final
	&& $active_count >= 10
	&& ( '' === $history_result || 'success' === $history_result );

fwrite( STDERR, "[e2e] DONE stage={$stage_final} elapsed={$elapsed}s isolation_seen=" . ( $isolation_seen ? 'yes' : 'no' ) . " active_plugins={$active_count} snapshot={$snapshot_count} history={$history_result}\n" );

if ( ! $ok ) {
	fwrite( STDERR, "[e2e] FAIL\n" );
	exit( 10 );
}

fwrite( STDERR, "[e2e] PASS\n" );
exit( 0 );
