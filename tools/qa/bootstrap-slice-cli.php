<?php
/**
 * QA helper: run one bootstrap restore slice from CLI (inside web container).
 *
 * Usage: php bootstrap-slice-cli.php <job_id> [seconds]
 */

if ( $argc < 2 ) {
	fwrite( STDERR, "Usage: php bootstrap-slice-cli.php <job_id> [seconds]\n" );
	exit( 1 );
}

$job_id = (string) $argv[1];
$slice  = isset( $argv[2] ) ? max( 1, (int) $argv[2] ) : 30;

if ( ! defined( 'MUSEDER_RESTOREONE_BOOTSTRAP_ROOT' ) ) {
	define( 'MUSEDER_RESTOREONE_BOOTSTRAP_ROOT', '/var/www/html' );
}

require_once MUSEDER_RESTOREONE_BOOTSTRAP_ROOT . '/wp-content/plugins/museder-restoreone/includes/class-restore-bootstrap.php';

Museder_Restoreone_Restore_Bootstrap::bootstrap_prepare_runtime();
Museder_Restoreone_Restore_Bootstrap::load_plugin_stack();

if ( ! class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
	fwrite( STDERR, "Restore_Service not loaded\n" );
	exit( 2 );
}

$result = Museder_Restoreone_Restore_Service::process_job_slice( $job_id, $slice, false, 'bootstrap' );
$meta   = isset( $result['meta'] ) && is_array( $result['meta'] ) ? $result['meta'] : [];

echo wp_json_encode(
	[
		'ok'        => ! empty( $result['ok'] ),
		'progress'  => isset( $meta['progress'] ) ? (int) $meta['progress'] : 0,
		'stage'     => isset( $meta['stage'] ) ? (string) $meta['stage'] : '',
		'completed' => ! empty( $meta['completed'] ),
		'message'   => isset( $meta['message'] ) ? (string) $meta['message'] : '',
	],
	JSON_PRETTY_PRINT
) . "\n";
