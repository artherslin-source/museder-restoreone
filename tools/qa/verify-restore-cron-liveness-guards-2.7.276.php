<?php
/**
 * Static guards for restore cron liveness self-heal (2.7.276).
 *
 * Usage: php tools/qa/verify-restore-cron-liveness-guards-2.7.276.php
 *
 * @package MusederRestoreOne
 */

$root = dirname( __DIR__, 2 );
$service_file    = $root . '/includes/class-restore-service.php';
$handler_file    = $root . '/includes/class-restore-handler.php';
$controller_file = $root . '/includes/class-restore-controller.php';

foreach ( [ $service_file, $handler_file, $controller_file ] as $file ) {
	if ( ! is_readable( $file ) ) {
		fwrite( STDERR, "FAIL\tmissing_file\t{$file}\n" );
		exit( 1 );
	}
}

$service_source    = file_get_contents( $service_file );
$handler_source    = file_get_contents( $handler_file );
$controller_source = file_get_contents( $controller_file );

if ( ! is_string( $service_source ) || ! is_string( $handler_source ) || ! is_string( $controller_source ) ) {
	fwrite( STDERR, "FAIL\tsource_read_failed\n" );
	exit( 1 );
}

$service_needles = [
	'function ensure_running_job_scheduled',
	'restore_liveness_schedule_check',
	"self::ensure_running_job_scheduled( \$job_id, 'status' )",
	"self::ensure_running_job_scheduled( \$active, 'active_job_lookup', \$meta )",
];

$handler_needles = [
	"ensure_running_job_scheduled( \$job_id, 'ajax_job_status' )",
	"ensure_running_job_scheduled( \$job_id, 'ajax_final_status' )",
];

$controller_needles = [
	"ensure_running_job_scheduled( \$job_id, 'rest_status' )",
	"ensure_running_job_scheduled( \$job_id, 'rest_final_status' )",
];

$failures = [];
foreach ( $service_needles as $needle ) {
	if ( false === strpos( $service_source, $needle ) ) {
		$failures[] = 'service:' . $needle;
	}
}
foreach ( $handler_needles as $needle ) {
	if ( false === strpos( $handler_source, $needle ) ) {
		$failures[] = 'handler:' . $needle;
	}
}
foreach ( $controller_needles as $needle ) {
	if ( false === strpos( $controller_source, $needle ) ) {
		$failures[] = 'controller:' . $needle;
	}
}

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL\tmissing_guard\t{$failure}\n" );
	}
	exit( 1 );
}

fwrite( STDOUT, "PASS\trestore_cron_liveness_guards_2.7.276\n" );
exit( 0 );
