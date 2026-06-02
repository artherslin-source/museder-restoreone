<?php
/**
 * Static guards for P3 rerun continuity fixes (2.7.275).
 *
 * Usage: php tools/qa/verify-restore-rerun-continuity-guards.php
 *
 * @package MusederRestoreOne
 */

$root = dirname( __DIR__, 2 );
$files = array(
	'handler' => $root . '/includes/class-restore-handler.php',
	'token'   => $root . '/includes/class-restore-token.php',
	'restore' => $root . '/includes/class-restore.php',
	'main'    => $root . '/museder-restoreone.php',
	'admin'   => $root . '/assets/js/admin.js',
	'e2e'     => $root . '/tools/qa/shineching-profile-e2e.php',
);

$source = array();
foreach ( $files as $key => $path ) {
	if ( ! is_readable( $path ) ) {
		fwrite( STDERR, "FAIL\tmissing_file\t{$key}\n" );
		exit( 1 );
	}
	$content = file_get_contents( $path );
	if ( ! is_string( $content ) || '' === $content ) {
		fwrite( STDERR, "FAIL\tempty_file\t{$key}\n" );
		exit( 1 );
	}
	$source[ $key ] = $content;
}

$checks = array(
	array( 'key' => 'token', 'needle' => 'get_raw_token_for_job' ),
	array( 'key' => 'token', 'needle' => "RAW_TOKEN_KEY" ),
	array( 'key' => 'handler', 'needle' => "restore_token'] = \$resume_token" ),
	array( 'key' => 'handler', 'needle' => 'restore_post_complete_read_is_valid' ),
	array( 'key' => 'admin', 'needle' => 'restoreData.job.restore_token' ),
	array( 'key' => 'admin', 'needle' => "restore_token: activeRestoreToken || ''" ),
	array( 'key' => 'admin', 'needle' => 'job_id: safeModeJobId' ),
	array( 'key' => 'admin', 'needle' => 'getRestoreStartBlockReason' ),
	array( 'key' => 'admin', 'needle' => 'redirectToRestoreReauth' ),
	array( 'key' => 'admin', 'needle' => 'restoreLoginUrl' ),
	array( 'key' => 'restore', 'needle' => "'permalink_structure' => get_option( 'permalink_structure', '' )" ),
	array( 'key' => 'restore', 'needle' => "update_option( 'permalink_structure'" ),
	array( 'key' => 'restore', 'needle' => "update_option( 'rewrite_rules'" ),
	array( 'key' => 'main', 'needle' => "'restoreLoginUrl'" ),
	array( 'key' => 'main', 'needle' => "'restorePageUrl'" ),
	array( 'key' => 'e2e', 'needle' => 'sc_qa_assert_resume_token_continuity' ),
	array( 'key' => 'e2e', 'needle' => 'sc_qa_assert_post_complete_grant_continuity' ),
	array( 'key' => 'e2e', 'needle' => 'sc_qa_assert_permalink_policy_preserved' ),
);

$failed = false;
foreach ( $checks as $check ) {
	$key = $check['key'];
	$needle = $check['needle'];
	if ( false === strpos( $source[ $key ], $needle ) ) {
		fwrite( STDERR, "FAIL\tmissing_guard\t{$key}\t{$needle}\n" );
		$failed = true;
	}
}

if ( $failed ) {
	exit( 1 );
}

fwrite( STDOUT, "PASS\trestore_rerun_continuity_guards\n" );
exit( 0 );
