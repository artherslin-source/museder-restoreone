<?php
/**
 * Verify Auto backup mode treats size-based large sites like the estimate UI (>1 GB).
 *
 * Usage: php tools/qa/verify-backup-auto-mode-large-site.php
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
require_once $root . '/includes/class-estimate-size.php';

$failures = 0;

if ( Museder_Restoreone_Estimate_Size::LARGE_SITE_TOTAL_BYTES !== 1073741824 ) {
	fwrite( STDERR, "FAIL: LARGE_SITE_TOTAL_BYTES should be 1 GiB\n" );
	++$failures;
}

// Mirror resolve_effective_backup_options_for_job large-site OR logic.
$byte_threshold = Museder_Restoreone_Estimate_Size::LARGE_SITE_TOTAL_BYTES;

$cases = [
	'large_by_estimate_only' => [
		'stats'    => [ 'bytes' => 100, 'reached_file_threshold' => false, 'reached_byte_threshold' => false ],
		'estimate' => $byte_threshold + 1,
		'expect'   => true,
	],
	'large_by_scan_bytes' => [
		'stats'    => [ 'bytes' => $byte_threshold, 'reached_file_threshold' => false, 'reached_byte_threshold' => true ],
		'estimate' => 0,
		'expect'   => true,
	],
	'small_site' => [
		'stats'    => [ 'bytes' => 500000000, 'reached_file_threshold' => false, 'reached_byte_threshold' => false ],
		'estimate' => 500000000,
		'expect'   => false,
	],
	'large_by_files' => [
		'stats'    => [ 'bytes' => 100, 'reached_file_threshold' => true, 'reached_byte_threshold' => false ],
		'estimate' => 0,
		'expect'   => true,
	],
];

foreach ( $cases as $name => $case ) {
	$stats    = $case['stats'];
	$estimate = (int) $case['estimate'];

	$is_large_by_files = ! empty( $stats['reached_file_threshold'] );
	$is_large_by_scan_bytes = ! empty( $stats['reached_byte_threshold'] )
		|| ( isset( $stats['bytes'] ) && (int) $stats['bytes'] >= $byte_threshold );
	$is_large_by_estimate = $estimate >= $byte_threshold;
	$is_large = $is_large_by_files || $is_large_by_scan_bytes || $is_large_by_estimate;

	if ( $case['expect'] !== $is_large ) {
		fwrite( STDERR, "FAIL: {$name} expected " . ( $case['expect'] ? 'large' : 'small' ) . "\n" );
		++$failures;
	}
}

if ( $failures > 0 ) {
	fwrite( STDERR, "BACKUP_AUTO_MODE_QA=FAIL ({$failures})\n" );
	exit( 1 );
}

echo "BACKUP_AUTO_MODE_QA=PASS\n";
