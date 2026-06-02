<?php
/**
 * Static guards for restore token stability across wp-config salt changes.
 *
 * Usage: php tools/qa/verify-restore-token-stability-guards.php
 *
 * @package MusederRestoreOne
 */

$root = dirname( __DIR__, 2 );
$token_file   = $root . '/includes/class-restore-token.php';
$handler_file = $root . '/includes/class-restore-handler.php';

if ( ! is_readable( $token_file ) ) {
	fwrite( STDERR, "FAIL\trestore_token_file_missing\n" );
	exit( 1 );
}
if ( ! is_readable( $handler_file ) ) {
	fwrite( STDERR, "FAIL\trestore_handler_file_missing\n" );
	exit( 1 );
}

$token_source = file_get_contents( $token_file );
$handler_source = file_get_contents( $handler_file );
if ( ! is_string( $token_source ) || '' === $token_source ) {
	fwrite( STDERR, "FAIL\trestore_token_file_empty\n" );
	exit( 1 );
}
if ( ! is_string( $handler_source ) || '' === $handler_source ) {
	fwrite( STDERR, "FAIL\trestore_handler_file_empty\n" );
	exit( 1 );
}

$required_token_needles = array(
	'stable_token_hash',
	'hash_hmac',
	'token_secret',
	'SECRET_FILENAME',
	'hmac_sha256_restore_secret_v1',
);
$required_handler_needles = array(
	'museder_restoreone_restore_final_status',
	'public static function final_status()',
	'verify_restore_progress_request',
	'history_for_js',
);

$failed = array();
foreach ( $required_token_needles as $needle ) {
	if ( false === strpos( $token_source, $needle ) ) {
		$failed[] = "token_missing\t{$needle}";
	}
}
foreach ( $required_handler_needles as $needle ) {
	if ( false === strpos( $handler_source, $needle ) ) {
		$failed[] = "handler_missing\t{$needle}";
	}
}

if ( ! preg_match( '/verify_post_complete_read[\s\S]*stable_token_hash/', $token_source ) ) {
	$failed[] = 'post_complete_missing_stable_hash';
}

if ( ! empty( $failed ) ) {
	foreach ( $failed as $line ) {
		fwrite( STDERR, "FAIL\t{$line}\n" );
	}
	exit( 1 );
}

fwrite( STDOUT, "PASS\trestore_token_stability_guards\n" );
exit( 0 );
