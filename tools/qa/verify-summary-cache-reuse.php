<?php
/**
 * Smoke test: restore summary cache reuse (BUG musederlabs Step 1 Load Info).
 * Run inside WordPress: wp eval-file tools/qa/verify-summary-cache-reuse.php
 *
 * @package MusederRestoreOne
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "SKIP: run via wp eval-file inside WordPress\n";
	exit( 0 );
}

if ( ! class_exists( 'Museder_Restoreone_Restore_Handler' ) ) {
	echo "FAIL: Restore_Handler missing\n";
	exit( 1 );
}

$ref = new ReflectionClass( 'Museder_Restoreone_Restore_Handler' );

foreach ( [ 'try_reuse_cached_prepare_session', 'build_summary_cache_for_archive' ] as $method ) {
	if ( ! $ref->hasMethod( $method ) ) {
		echo "FAIL: missing method {$method}\n";
		exit( 1 );
	}
}

echo "PASS: summary cache reuse methods present\n";
exit( 0 );
