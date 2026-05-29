<?php
/**
 * Admin UI smoke (WP_DEBUG on). Run inside web container:
 *   php /tmp/museder-restoreone-src/tools/qa/admin-smoke-wpdebug.php
 *
 * @package MusederRestoreOne
 */

define( 'WP_USE_THEMES', false );
require '/var/www/html/wp-load.php';

if ( ! function_exists( 'wp_set_current_user' ) ) {
	fwrite( STDERR, "FAIL: WordPress not loaded\n" );
	exit( 1 );
}

$user = get_user_by( 'login', 'admin' );
if ( ! $user ) {
	fwrite( STDERR, "FAIL: admin user missing\n" );
	exit( 1 );
}
wp_set_current_user( $user->ID );
require_once ABSPATH . 'wp-admin/includes/admin.php';
if ( function_exists( 'set_current_screen' ) ) {
	set_current_screen( 'dashboard' );
}

$pages = array(
	'museder-restoreone-dashboard' => 'museder_restoreone_render_dashboard',
	'museder-restoreone-backups'   => 'museder_restoreone_render_backups',
	'museder-restoreone-restore'   => 'museder_restoreone_render_restore_page',
	'museder-restoreone-schedules' => 'museder_restoreone_render_schedules',
	'museder-restoreone-logs'      => 'museder_restoreone_render_logs',
	'museder-restoreone-settings'  => 'museder_restoreone_render_settings',
);

$ok = 0;
foreach ( $pages as $slug => $callback ) {
	if ( ! is_callable( $callback ) ) {
		echo "FAIL $slug callback_missing\n";
		continue;
	}
	ob_start();
	$error = null;
	set_error_handler(
		static function ( $severity, $message, $file, $line ) use ( &$error ) {
			$error = $message . ' in ' . $file . ':' . $line;
			return true;
		}
	);
	try {
		call_user_func( $callback );
	} catch ( Throwable $e ) {
		$error = $e->getMessage();
	}
	restore_error_handler();
	$html = ob_get_clean();
	if ( $error ) {
		echo "FAIL $slug $error\n";
	} elseif ( strlen( $html ) < 50 ) {
		echo "FAIL $slug empty_output len=" . strlen( $html ) . "\n";
	} else {
		echo "PASS $slug len=" . strlen( $html ) . "\n";
		++$ok;
	}
}

$log = WP_CONTENT_DIR . '/debug.log';
$fatals = 0;
if ( is_readable( $log ) ) {
	$tail = file_get_contents( $log );
	if ( false !== $tail && preg_match( '/PHP Fatal/i', $tail ) ) {
		$fatals = 1;
	}
}
echo "SUMMARY pages_pass=$ok/" . count( $pages ) . " debug_log_fatal=" . ( $fatals ? 'yes' : 'no' ) . "\n";
exit( ( $ok === count( $pages ) && ! $fatals ) ? 0 : 1 );
