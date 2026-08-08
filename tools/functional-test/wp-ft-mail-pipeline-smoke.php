<?php
/**
 * Verifies wp_mail is constructed for HTML messages and Email_Handler::test_email() reaches PHPMailer.
 *
 * Run: wp eval-file tools/functional-test/wp-ft-mail-pipeline-smoke.php
 *
 * @package Museder_Restoreone
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

// Docker / localhost installs default to wordpress@localhost; WordPress treats that as invalid, so
// setFrom() throws and wp_mail returns before phpmailer_init. Normalize only for this smoke run.
add_filter(
	'wp_mail_from',
	static function ( $email ) {
		return ( is_string( $email ) && is_email( $email ) ) ? $email : 'noreply@example.org';
	},
	1
);

$hit = 0;
add_action(
	'phpmailer_init',
	static function () use ( &$hit ) {
		$hit++;
	},
	1,
	1
);

$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
$to = get_option( 'admin_email' );
if ( ! is_string( $to ) || ! is_email( $to ) ) {
	$to = 'restoreone-mail-smoke@example.org';
}
$r = wp_mail( $to, 'RestoreOne mail smoke', '<p>ok</p>', $headers );
if ( $hit < 1 ) {
	fwrite( STDERR, "[wp-ft-mail-pipeline-smoke] phpmailer_init not fired for wp_mail\n" );
	exit( 1 );
}
if ( ! $r ) {
	fwrite( STDERR, "[wp-ft-mail-pipeline-smoke] note: wp_mail returned false (common on Docker without MTA); PHPMailer path still ran.\n" );
}

if ( ! class_exists( 'Museder_Restoreone_Email_Handler' ) ) {
	fwrite( STDERR, "[wp-ft-mail-pipeline-smoke] Email handler missing\n" );
	exit( 1 );
}

$hit2 = 0;
add_action(
	'phpmailer_init',
	static function () use ( &$hit2 ) {
		$hit2++;
	},
	1,
	1
);

$r2 = Museder_Restoreone_Email_Handler::test_email();
if ( $hit2 < 1 ) {
	fwrite( STDERR, "[wp-ft-mail-pipeline-smoke] phpmailer_init not fired for test_email\n" );
	exit( 1 );
}
if ( ! $r2 ) {
	fwrite( STDERR, "[wp-ft-mail-pipeline-smoke] note: test_email returned false (host may not deliver); PHPMailer path still ran.\n" );
}

echo "[wp-ft-mail-pipeline-smoke] OK\n";
