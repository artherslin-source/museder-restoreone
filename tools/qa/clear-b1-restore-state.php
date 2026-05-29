<?php
/**
 * Clear stale restore job state on QA-B1 (CLI via wp-load.php).
 *
 * @package MusederRestoreOne
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'WP_USE_THEMES', false );
	require '/var/www/html/wp-load.php';
}

delete_option( 'museder_restoreone_restore_service_active_job_id' );
delete_option( 'museder_restoreone_restore_token' );
delete_option( 'museder_restoreone_restore_post_complete_access' );
delete_option( 'museder_restoreone_mid_restore_isolation' );
delete_option( 'museder_restoreone_restore_lock' );

if ( class_exists( 'Museder_Restoreone_Restore_Lock' ) ) {
	Museder_Restoreone_Restore_Lock::release();
}
if ( class_exists( 'Museder_Restoreone_Restore_Token' ) ) {
	Museder_Restoreone_Restore_Token::revoke();
	Museder_Restoreone_Restore_Token::clear_post_complete_access();
}

$jobs_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'museder-restoreone/jobs';
if ( is_dir( $jobs_dir ) ) {
	foreach ( glob( $jobs_dir . '/*.json' ) as $job_file ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- QA cleanup of stale job meta
		@unlink( $job_file );
	}
}

$active = (string) get_option( 'museder_restoreone_restore_service_active_job_id', '' );
$lock   = class_exists( 'Museder_Restoreone_Restore_Lock' ) ? Museder_Restoreone_Restore_Lock::current_lock() : null;
echo 'active_job_id=' . ( '' === $active ? '(empty)' : $active ) . "\n";
echo 'lock=' . ( empty( $lock ) ? '(empty)' : wp_json_encode( $lock ) ) . "\n";
