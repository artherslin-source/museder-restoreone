<?php
/**
 * WP-CLI eval-file: poll active restore service job.
 *
 * @package MusederRestoreOne
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load WordPress first.\n" );
	exit( 1 );
}

$job_id = '';
if ( class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
	$job_id = (string) Museder_Restoreone_Restore_Service::get_active_job_id();
}
if ( '' === $job_id ) {
	$job_id = (string) get_option( 'museder_restoreone_restore_service_active_job_id', '' );
}

$meta = [];
if ( '' !== $job_id && class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
	$meta = Museder_Restoreone_Restore_Service::get_job_meta( $job_id );
}

echo wp_json_encode(
	[
		'job_id'    => $job_id,
		'stage'     => $meta['stage'] ?? '',
		'progress'  => isset( $meta['progress'] ) ? (int) $meta['progress'] : 0,
		'completed' => ! empty( $meta['completed'] ),
		'message'   => $meta['message'] ?? '',
		'isolation' => (string) get_option( 'museder_restoreone_mid_restore_isolation', '' ),
	],
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . "\n";
