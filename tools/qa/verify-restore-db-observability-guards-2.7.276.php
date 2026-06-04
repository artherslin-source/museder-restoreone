<?php
/**
 * Static guards for restore-db observability and stale diagnostics (2.7.276).
 *
 * Usage: php tools/qa/verify-restore-db-observability-guards-2.7.276.php
 *
 * @package MusederRestoreOne
 */

$root = dirname( __DIR__, 2 );
$service_file = $root . '/includes/class-restore-service.php';
$restore_file = $root . '/includes/class-restore.php';

if ( ! is_readable( $service_file ) || ! is_readable( $restore_file ) ) {
	fwrite( STDERR, "FAIL\trestore_db_observability_sources_missing\n" );
	exit( 1 );
}

$service_source = file_get_contents( $service_file );
$restore_source = file_get_contents( $restore_file );
if ( ! is_string( $service_source ) || '' === $service_source || ! is_string( $restore_source ) || '' === $restore_source ) {
	fwrite( STDERR, "FAIL\trestore_db_observability_sources_empty\n" );
	exit( 1 );
}

$service_needles = array(
	'log_process_slice_telemetry',
	'restore_process_slice_telemetry',
	'restore_db_stage_entry',
	'restore_db_stage_import_result',
	'restore_db_stage_recovery',
	'Restore DB stage is stale with db_offset=0.',
);

$restore_needles = array(
	'NDJSON import entry.',
	'NDJSON import stream opened.',
	'NDJSON import first schema applied.',
	'NDJSON import first row applied.',
	'NDJSON import completed.',
	'Opening database import stream…',
);

$failures = array();
foreach ( $service_needles as $needle ) {
	if ( false === strpos( $service_source, $needle ) ) {
		$failures[] = 'service:' . $needle;
	}
}
foreach ( $restore_needles as $needle ) {
	if ( false === strpos( $restore_source, $needle ) ) {
		$failures[] = 'restore:' . $needle;
	}
}

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL\tmissing_guard\t{$failure}\n" );
	}
	exit( 1 );
}

fwrite( STDOUT, "PASS\trestore_db_observability_guards_2.7.276\n" );
exit( 0 );
