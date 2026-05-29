<?php
/**
 * Field QA runner — run via: wp --path=$SITE eval-file tools/qa/sunpower-field-qa.php
 *
 * @package MusederRestoreOne
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load WordPress first (wp eval-file).\n" );
	exit( 1 );
}

$site_path = defined( 'ABSPATH' ) ? ABSPATH : '';
$backup    = 'sunpoweroflight.com-20260115030733-V8KGHW.zip';
$backup_path = function_exists( 'museder_restoreone_get_backup_path' )
	? museder_restoreone_get_backup_path( $backup )
	: '';

$results = [];
$fail    = 0;

$check = static function ( $id, $name, $ok, $detail = '' ) use ( &$results, &$fail ) {
	$results[] = [
		'id'     => $id,
		'name'   => $name,
		'status' => $ok ? 'PASS' : 'FAIL',
		'detail' => $detail,
	];
	if ( ! $ok ) {
		++$fail;
	}
};

$build = defined( 'MUSEDER_RESTOREONE_BUILD_ID' ) ? MUSEDER_RESTOREONE_BUILD_ID : '?';
$check( 'T0', 'Plugin build', '2.7.267' === $build || false !== strpos( $build, '2.7.267' ), 'build=' . $build );

$check( 'T0b', 'Preflight class', class_exists( 'Museder_Restoreone_Restore_Preflight' ), '' );
$check( 'T0c', 'Bootstrap class', class_exists( 'Museder_Restoreone_Restore_Bootstrap' ), '' );

if ( '' !== $backup_path && file_exists( $backup_path ) ) {
	$profile = Museder_Restoreone_Restore_Preflight::detect_restore_profile();
	$check( 'S-pop', 'Profile on live sunpower', Museder_Restoreone_Restore_Preflight::PROFILE_POPULATED === $profile, 'profile=' . $profile );

	$hints = Museder_Restoreone_Restore_Preflight::hints_for_summary( $backup_path, [] );
	$check( 'S3-hint', 'fresh hint not forced on populated', empty( $hints['fresh_db_overwrite_notice'] ) || ! $hints['suggest_files_first'], wp_json_encode( [ 'suggest_files_first' => $hints['suggest_files_first'] ?? null ] ) );

	$pf_block = Museder_Restoreone_Restore_Preflight::preflight(
		$backup_path,
		[
			'restore_scope' => Museder_Restoreone_Restore_Preflight::SCOPE_FULL,
			'overwrite'     => false,
		]
	);
	$check( 'S4', 'populated blocks without overwrite', ! empty( $pf_block['blocked'] ), $pf_block['message'] ?? '' );

	$pf_ok = Museder_Restoreone_Restore_Preflight::preflight(
		$backup_path,
		[
			'restore_scope' => Museder_Restoreone_Restore_Preflight::SCOPE_FULL,
			'overwrite'     => true,
			'auto_backup'   => true,
		]
	);
	$check( 'S4b', 'populated allows with overwrite+snapshot', empty( $pf_ok['blocked'] ), $pf_ok['message'] ?? '' );

	$order_default = $pf_ok['options']['restore_order'] ?? '';
	$check( 'S5-order', 'populated default db_then_files', Museder_Restoreone_Restore_Preflight::ORDER_DB_THEN_FILES === $order_default, 'order=' . $order_default );

	$has_core = Museder_Restoreone_Restore_Service::zip_archive_has_wp_core( $backup_path );
	$check( 'S1-zip', 'archive has wp core', $has_core, '' );
} else {
	$check( 'S-zip', 'backup file present', false, 'missing ' . $backup );
}

$root = function_exists( 'museder_restoreone_get_wp_root_dir' ) ? museder_restoreone_get_wp_root_dir() : '';
$check( 'S1-core', 'wp-admin exists', '' !== $root && is_dir( $root . '/wp-admin' ), $root );
$check( 'S1-core2', 'wp-includes exists', '' !== $root && is_dir( $root . '/wp-includes' ), '' );

$htaccess = ( '' !== $root ) ? $root . '/.htaccess' : '';
$check( 'S1-htaccess', 'site root .htaccess', '' !== $htaccess && file_exists( $htaccess ), file_exists( $htaccess ) ? (string) filesize( $htaccess ) : 'missing' );

$isolation = (string) get_option( 'museder_restoreone_mid_restore_isolation', '' );
$check( 'S5-iso', 'mid isolation off after restore', '1' !== $isolation, 'val=' . $isolation );

$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
$check( 'S9-cron', 'WP_CRON not globally disabled', ! $cron_disabled, $cron_disabled ? 'DISABLE_WP_CRON true' : 'ok' );

echo wp_json_encode( [ 'site' => $site_path, 'results' => $results, 'fail_count' => $fail ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
exit( $fail > 0 ? 1 : 0 );
