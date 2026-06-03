<?php
/**
 * shineching.com production field restore (2.7.276) — docroot scoped CLI runner.
 *
 * Usage:
 *   wp --allow-root eval-file /tmp/shineching-field-restore-e2e.php [archive_basename]
 *
 * @package MusederRestoreOne
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load WordPress first.\n" );
	exit( 1 );
}

$archive = isset( $args[0] ) && is_string( $args[0] ) && '' !== $args[0]
	? basename( $args[0] )
	: 'shineching.com-20260531013823-V6yYBa-1.zip';

$failures = 0;

function sc_field_fail( $msg ) {
	global $failures;
	++$failures;
	fwrite( STDERR, "FAIL\t{$msg}\n" );
}

function sc_field_pass( $msg ) {
	echo "PASS\t{$msg}\n";
}

$version = defined( 'MUSEDER_RESTOREONE_VERSION' ) ? MUSEDER_RESTOREONE_VERSION : '';
if ( '2.7.276' !== $version ) {
	sc_field_fail( 'version_not_2.7.276 got=' . $version );
	exit( 1 );
}
sc_field_pass( 'version_2.7.276' );

$zip_path = museder_restoreone_get_backup_path( $archive );
if ( ! $zip_path || ! file_exists( $zip_path ) ) {
	sc_field_fail( 'backup_missing ' . $archive );
	exit( 1 );
}
sc_field_pass( 'backup_present bytes=' . filesize( $zip_path ) );

$host_db_name   = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
$host_db_prefix = isset( $GLOBALS['table_prefix'] ) ? (string) $GLOBALS['table_prefix'] : '';
if ( '' === $host_db_name || '' === $host_db_prefix ) {
	sc_field_fail( 'host_db_unreadable db=' . $host_db_name . ' prefix=' . $host_db_prefix );
	exit( 1 );
}
sc_field_pass( 'host_db ' . $host_db_name . ' prefix=' . $host_db_prefix );

if ( 'i10269493_ipic1' === $host_db_name ) {
	sc_field_fail( 'host_db_points_to_archive_db' );
	exit( 1 );
}

delete_option( Museder_Restoreone_Restore_Service::ACTIVE_JOB_OPTION );

$options = array(
	'overwrite'           => true,
	'auto_backup'         => false,
	'pause_other_plugins' => true,
	'safe_mode'           => true,
	'files_only'          => false,
	'restore_scope'       => Museder_Restoreone_Restore_Preflight::SCOPE_FULL,
	'restore_order'       => Museder_Restoreone_Restore_Preflight::ORDER_DB_THEN_FILES,
	'wp_config_mode'      => Museder_Restoreone_Restore_Preflight::MODE_CONFIG_BACKUP,
	'restore_profile'     => Museder_Restoreone_Restore_Preflight::PROFILE_POPULATED,
	'db_target_prefix'    => $host_db_prefix,
);

try {
	Museder_Restoreone_Restore_Handler::prepare_session( $zip_path, 'existing' );
	$prepared = Museder_Restoreone_Restore_Service::prepare( 'existing', $archive, '' );
	$job_id   = (string) ( $prepared['job_id'] ?? '' );
	if ( '' === $job_id ) {
		throw new RuntimeException( 'empty job_id' );
	}
	sc_field_pass( 'restore_prepared ' . $job_id );

	Museder_Restoreone_Restore_Service::validate( $job_id );
	$exec     = Museder_Restoreone_Restore_Service::execute( $job_id, $options );
	$raw_token = is_array( $exec ) && ! empty( $exec['restore_token'] ) ? (string) $exec['restore_token'] : '';
	if ( '' === $raw_token ) {
		sc_field_fail( 'restore_token_missing_from_execute' );
		exit( 1 );
	}
	sc_field_pass( 'restore_token_captured len=' . strlen( $raw_token ) );

	for ( $i = 0; $i < 8000; $i++ ) {
		Museder_Restoreone_Restore_Service::process_job_slice( $job_id, 20, false, 'cli' );
		$meta = Museder_Restoreone_Restore_Service::get_job_meta( $job_id );
		if ( ! empty( $meta['completed'] ) ) {
			sc_field_pass( 'restore_completed loop=' . ( $i + 1 ) . ' stage=' . ( $meta['stage'] ?? '' ) );
			break;
		}
		if ( 'failed' === ( $meta['stage'] ?? '' ) ) {
			sc_field_fail( 'restore_failed message=' . ( $meta['message'] ?? '' ) );
			exit( 1 );
		}
		if ( 0 === ( $i + 1 ) % 25 ) {
			echo 'INFO\tloop=' . ( $i + 1 ) . "\tstage=" . ( $meta['stage'] ?? '' ) . "\tprogress=" . (int) ( $meta['progress'] ?? 0 ) . "\n";
		}
	}

	$final = Museder_Restoreone_Restore_Service::get_job_meta( $job_id );
	if ( empty( $final['completed'] ) || 'done' !== (string) ( $final['stage'] ?? '' ) ) {
		sc_field_fail( 'restore_not_done stage=' . ( $final['stage'] ?? '' ) . ' progress=' . (int) ( $final['progress'] ?? 0 ) );
		exit( 1 );
	}
	sc_field_pass( 'restore_final_stage_ok' );

	global $wpdb;
	$roles_key = $wpdb->prefix . 'user_roles';
	$roles     = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			$roles_key
		)
	);
	if ( $roles !== $roles_key ) {
		sc_field_fail( 'prefix_roles_missing key=' . $roles_key );
	} else {
		sc_field_pass( 'prefix_roles_ok ' . $roles_key );
	}

	$cap_rows = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s",
			$wpdb->prefix . 'capabilities'
		)
	);
	if ( (int) $cap_rows < 1 ) {
		sc_field_fail( 'prefix_capabilities_missing prefix=' . $wpdb->prefix );
	} else {
		sc_field_pass( 'prefix_capabilities_ok count=' . (int) $cap_rows );
	}

	$blogname = (string) get_option( 'blogname', '' );
	if ( '' === $blogname ) {
		sc_field_fail( 'empty_blogname' );
	} else {
		sc_field_pass( 'blogname ' . $blogname );
	}

	$db_name_after = defined( 'DB_NAME' ) ? (string) DB_NAME : '';
	if ( 'i10269493_ipic1' === $db_name_after ) {
		sc_field_fail( 'wp_config_points_to_archive_db_after_restore' );
	} else {
		sc_field_pass( 'wp_config_host_db_preserved ' . $db_name_after );
	}

	$grant_test = '/tmp/shineching-field-exit-safe-mode-grant-test.php';
	if ( file_exists( $grant_test ) ) {
		$cmd = 'wp --allow-root eval-file ' . escapeshellarg( $grant_test ) . ' ' . escapeshellarg( $job_id ) . ' ' . escapeshellarg( $raw_token );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- field QA runner only.
		$out = array();
		$code = 0;
		exec( $cmd . ' 2>&1', $out, $code );
		echo implode( "\n", $out ) . "\n";
		if ( 0 !== $code || ! in_array( 'exit_safe_mode_grant_test=PASS', $out, true ) ) {
			sc_field_fail( 'exit_safe_mode_grant_test_failed code=' . $code );
			exit( 1 );
		}
		sc_field_pass( 'exit_safe_mode_grant_test_ok' );
	} else {
		echo "WARN\texit_safe_mode_grant_test_skipped missing_script\n";
	}

} catch ( Throwable $e ) {
	sc_field_fail( 'exception ' . $e->getMessage() );
	exit( 1 );
}

if ( $failures > 0 ) {
	fwrite( STDERR, "SHINECHING_FIELD_E2E=FAIL ({$failures})\n" );
	exit( 1 );
}

echo "SHINECHING_FIELD_E2E=PASS\n";
exit( 0 );
