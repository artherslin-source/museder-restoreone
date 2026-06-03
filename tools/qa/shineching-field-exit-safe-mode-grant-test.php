<?php
/**
 * Post-restore Exit Safe Mode grant/token verification (CLI, shineching docroot).
 *
 * @package MusederRestoreOne
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load WordPress first.\n" );
	exit( 1 );
}

/**
 * @param string $msg Message.
 */
function sc_exit_grant_pass( $msg ) {
	echo 'PASS\t' . $msg . "\n";
}

/**
 * @param string $msg Message.
 */
function sc_exit_grant_fail( $msg ) {
	fwrite( STDERR, 'FAIL\t' . $msg . "\n" );
}

/**
 * @param string $job_id Job id.
 * @param string $raw_token Raw restore token.
 * @return bool
 */
function sc_exit_grant_simulate_ajax( $job_id, $raw_token ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$_POST['job_id']        = $job_id;
	$_POST['restore_token'] = $raw_token;
	$_POST['nonce']         = 'invalid-on-purpose';
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	if ( ! class_exists( 'Museder_Restoreone_UI' ) ) {
		return false;
	}

	return Museder_Restoreone_UI::restore_post_complete_read_is_valid( $job_id );
}

$job_id    = isset( $args[0] ) ? sanitize_text_field( (string) $args[0] ) : '';
$raw_token = isset( $args[1] ) ? sanitize_text_field( (string) $args[1] ) : '';

if ( '' === $job_id || '' === $raw_token ) {
	sc_exit_grant_fail( 'missing_job_or_token' );
	echo "exit_safe_mode_grant_test=FAIL\n";
	exit( 1 );
}

if ( ! has_action( 'wp_ajax_nopriv_museder_restoreone_exit_safe_mode' ) ) {
	sc_exit_grant_fail( 'nopriv_exit_safe_mode_hook_missing' );
	echo "exit_safe_mode_grant_test=FAIL\n";
	exit( 1 );
}
sc_exit_grant_pass( 'nopriv_exit_safe_mode_hook_present' );

$grant = get_option( 'museder_restoreone_restore_post_complete_access', [] );
if ( ! is_array( $grant ) || empty( $grant['job_id'] ) || empty( $grant['token_hash'] ) ) {
	sc_exit_grant_fail( 'post_complete_grant_missing' );
	echo "exit_safe_mode_grant_test=FAIL\n";
	exit( 1 );
}
sc_exit_grant_pass( 'post_complete_grant_present job=' . (string) $grant['job_id'] );

if ( ! hash_equals( (string) $grant['job_id'], $job_id ) ) {
	sc_exit_grant_fail( 'grant_job_mismatch grant=' . (string) $grant['job_id'] . ' job=' . $job_id );
	echo "exit_safe_mode_grant_test=FAIL\n";
	exit( 1 );
}

if ( ! class_exists( 'Museder_Restoreone_Restore_Token' ) ) {
	sc_exit_grant_fail( 'token_class_missing' );
	echo "exit_safe_mode_grant_test=FAIL\n";
	exit( 1 );
}

if ( ! Museder_Restoreone_Restore_Token::verify_post_complete_read( $raw_token, $job_id ) ) {
	sc_exit_grant_fail( 'verify_post_complete_read_failed' );
	echo "exit_safe_mode_grant_test=FAIL\n";
	exit( 1 );
}
sc_exit_grant_pass( 'verify_post_complete_read_ok' );

if ( ! sc_exit_grant_simulate_ajax( $job_id, $raw_token ) ) {
	sc_exit_grant_fail( 'restore_post_complete_read_is_valid_failed' );
	echo "exit_safe_mode_grant_test=FAIL\n";
	exit( 1 );
}
sc_exit_grant_pass( 'restore_post_complete_read_is_valid_ok' );

$safe_before = (string) get_option( 'museder_restoreone_safe_mode', '' );
if ( '1' !== $safe_before ) {
	sc_exit_grant_fail( 'safe_mode_not_active_before=' . $safe_before );
	echo "exit_safe_mode_grant_test=FAIL\n";
	exit( 1 );
}

if ( ! class_exists( 'Museder_Restoreone_Restore' ) ) {
	sc_exit_grant_fail( 'restore_class_missing' );
	echo "exit_safe_mode_grant_test=FAIL\n";
	exit( 1 );
}

$result = Museder_Restoreone_Restore::exit_safe_mode();
$safe_after = (string) get_option( 'museder_restoreone_safe_mode', '' );
if ( ! $result || '1' === $safe_after ) {
	sc_exit_grant_fail( 'cli_exit_safe_mode_failed result=' . wp_json_encode( $result ) . ' after=' . $safe_after );
	echo "exit_safe_mode_grant_test=FAIL\n";
	exit( 1 );
}
sc_exit_grant_pass( 'cli_exit_safe_mode_ok' );

echo "exit_safe_mode_grant_test=PASS\n";
exit( 0 );
