<?php
/**
 * Verify Step 1 analysis preflight does not block on overwrite-only populated sites.
 *
 * Usage: php tools/qa/verify-preflight-step1-analysis.php
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
require_once $root . '/includes/class-restore-preflight.php';
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) {
		return '6.8';
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

$failures = 0;

// Populated + no overwrite: analysis must not block; execute must block.
$analysis = Museder_Restoreone_Restore_Preflight::preflight(
	'',
	[
		'restore_profile' => Museder_Restoreone_Restore_Preflight::PROFILE_POPULATED,
		'restore_scope'   => Museder_Restoreone_Restore_Preflight::SCOPE_FULL,
		'overwrite'       => false,
	],
	true
);
if ( ! empty( $analysis['blocked'] ) ) {
	fwrite( STDERR, "FAIL: analysis preflight blocked on populated site without overwrite\n" );
	++$failures;
}
$overwrite_msg = 'Full-site restore on a site with existing content requires enabling “Overwrite existing data”.';
$found_warning = false;
foreach ( $analysis['warnings'] as $w ) {
	if ( false !== strpos( (string) $w, 'Overwrite existing data' ) ) {
		$found_warning = true;
		break;
	}
}
if ( ! $found_warning ) {
	fwrite( STDERR, "FAIL: analysis preflight missing overwrite warning\n" );
	++$failures;
}

$execute = Museder_Restoreone_Restore_Preflight::preflight(
	'',
	[
		'restore_profile' => Museder_Restoreone_Restore_Preflight::PROFILE_POPULATED,
		'restore_scope'   => Museder_Restoreone_Restore_Preflight::SCOPE_FULL,
		'overwrite'       => false,
	],
	false
);
if ( empty( $execute['blocked'] ) ) {
	fwrite( STDERR, "FAIL: execute preflight should block without overwrite\n" );
	++$failures;
}

$execute_ok = Museder_Restoreone_Restore_Preflight::preflight(
	'',
	[
		'restore_profile' => Museder_Restoreone_Restore_Preflight::PROFILE_POPULATED,
		'restore_scope'   => Museder_Restoreone_Restore_Preflight::SCOPE_FULL,
		'overwrite'       => true,
	],
	false
);
if ( ! empty( $execute_ok['blocked'] ) ) {
	fwrite( STDERR, "FAIL: execute preflight should not block when overwrite enabled\n" );
	++$failures;
}

// Fatal block (empty shell + full scope + no core in archive) still blocks at analysis.
$fatal = Museder_Restoreone_Restore_Preflight::preflight(
	'',
	[
		'restore_profile' => Museder_Restoreone_Restore_Preflight::PROFILE_EMPTY,
		'restore_scope'   => Museder_Restoreone_Restore_Preflight::SCOPE_FULL,
	],
	true
);
if ( empty( $fatal['blocked'] ) ) {
	fwrite( STDERR, "FAIL: analysis preflight should still block fatal empty-shell mismatch\n" );
	++$failures;
}

if ( $failures > 0 ) {
	fwrite( STDERR, "PREFLIGHT_STEP1_QA=FAIL ({$failures} checks)\n" );
	exit( 1 );
}

echo "PREFLIGHT_STEP1_QA=PASS\n";
