<?php
/**
 * Static guard checks for Restore Step 3 UI one-way success convergence (2.7.275+).
 *
 * Usage: php tools/qa/verify-restore-ui-convergence-guards.php
 *
 * @package MusederRestoreOne
 */

$root = dirname( __DIR__, 2 );
$admin_js = $root . '/assets/js/admin.js';

if ( ! is_readable( $admin_js ) ) {
	fwrite( STDERR, "FAIL\tadmin_js_missing\n" );
	exit( 1 );
}

$source = file_get_contents( $admin_js );
if ( ! is_string( $source ) || '' === $source ) {
	fwrite( STDERR, "FAIL\tadmin_js_empty\n" );
	exit( 1 );
}

$required = array(
	'isRestoreUiSuccessLocked',
	'startRestoreRestOnlyCompletionLoop',
	'stopRestoreRestOnlyCompletionLoop',
	'restoreTransportDegraded',
	'restoreRestOnlyCompletionInterval',
	'dismissWordPressAuthCheckModal',
	'REST-only completion loop',
);

$failures = array();
foreach ( $required as $needle ) {
	if ( false === strpos( $source, $needle ) ) {
		$failures[] = $needle;
	}
}

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL\tmissing_guard\t{$failure}\n" );
	}
	exit( 1 );
}

// Ensure success lock is checked before monitor restart resets state.
if ( ! preg_match( '/function startRestoreJobMonitor[\s\S]*?isRestoreUiSuccessLocked\(\)/', $source ) ) {
	fwrite( STDERR, "FAIL\tstartRestoreJobMonitor_missing_success_lock\n" );
	exit( 1 );
}

if ( ! preg_match( '/function applyRestoreFinalStatusPayload[\s\S]*?isRestoreUiSuccessLocked\(\)/', $source ) ) {
	fwrite( STDERR, "FAIL\tapplyRestoreFinalStatusPayload_missing_success_lock\n" );
	exit( 1 );
}

fwrite( STDOUT, "PASS\trestore_ui_convergence_guards\n" );
exit( 0 );
