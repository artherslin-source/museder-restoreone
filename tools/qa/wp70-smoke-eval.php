<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

/**
 * WP-CLI eval-file smoke checks for WordPress 7.0.
 *
 * Usage:
 *   wp eval-file /tmp/museder-restoreone-src/tools/qa/wp70-smoke-eval.php
 */

$results = array();
$errors  = array();

$assert = static function ( $condition, $message ) use ( &$results, &$errors ) {
	$results[] = array(
		'check'  => $message,
		'pass'   => (bool) $condition,
	);
	if ( ! $condition ) {
		$errors[] = $message;
	}
};

// Ensure we can run admin callbacks.
$admin = get_user_by( 'login', 'admin' );
if ( $admin && isset( $admin->ID ) ) {
	wp_set_current_user( (int) $admin->ID );
}

// 1) WordPress version gate.
global $wp_version;
$assert( is_string( $wp_version ) && version_compare( $wp_version, '7.0', '>=' ), 'WordPress version is >= 7.0' );

// 2) Plugin + class loading.
$assert( function_exists( 'museder_restoreone_bootstrap' ), 'museder_restoreone_bootstrap exists' );
$assert( class_exists( 'Museder_Restoreone_UI' ), 'Museder_Restoreone_UI class exists' );
$assert( class_exists( 'Museder_Restoreone_Restore_Controller' ), 'Restore controller class exists' );
$assert( class_exists( 'Museder_Restoreone_Chunk_V2' ), 'Chunk v2 class exists' );

// 3) AJAX hooks: existence checks.
$critical_ajax_actions = array(
	'museder_restoreone_run_backup',
	'museder_restoreone_restore_existing',
	'museder_restoreone_save_settings',
	'museder_restoreone_fetch_logs',
	'museder_restoreone_test_email',
);
foreach ( $critical_ajax_actions as $action ) {
	$assert( has_action( 'wp_ajax_' . $action ) !== false, 'AJAX action registered: ' . $action );
}

// 4) REST route checks.
$server = rest_get_server();
$routes = $server->get_routes();
$route_count = 0;

foreach ( $routes as $route => $handlers ) {
	if ( 0 !== strpos( $route, '/museder-restoreone/' ) ) {
		continue;
	}
	$route_count++;
	foreach ( $handlers as $handler ) {
		if ( ! is_array( $handler ) ) {
			continue;
		}
		if ( isset( $handler['permission_callback'] ) ) {
			$assert( $handler['permission_callback'] !== '__return_true', 'REST permission_callback is not __return_true for ' . $route );
		}
	}
}
$assert( $route_count >= 10, 'REST route count for museder-restoreone namespace is >= 10' );

// 5) Render key admin page callbacks in-process (fatal smoke).
$page_callbacks = array(
	'museder_restoreone_render_dashboard',
	'museder_restoreone_render_backups',
	'museder_restoreone_render_restore_page',
	'museder_restoreone_render_schedules',
	'museder_restoreone_render_logs',
	'museder_restoreone_render_settings',
);

foreach ( $page_callbacks as $callback ) {
	$ok = function_exists( $callback );
	$assert( $ok, 'Page callback exists: ' . $callback );
	if ( ! $ok ) {
		continue;
	}

	try {
		ob_start();
		call_user_func( $callback );
		$output = ob_get_clean();
		$assert( is_string( $output ) && strlen( $output ) > 0, 'Page callback renders output: ' . $callback );
	} catch ( Throwable $e ) {
		if ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		$assert( false, 'Page callback throws exception: ' . $callback . ' => ' . $e->getMessage() );
	}
}

// 6) Header/readme consistency checks.
$plugin_main = WP_PLUGIN_DIR . '/museder-restoreone/museder-restoreone.php';
$readme_file = WP_PLUGIN_DIR . '/museder-restoreone/readme.txt';

$tested_header = '';
$tested_readme = '';

if ( file_exists( $plugin_main ) ) {
	$main_content = file_get_contents( $plugin_main );
	if ( is_string( $main_content ) && preg_match( '/^\s*Tested up to:\s*([0-9.]+)\s*$/mi', $main_content, $m ) ) {
		$tested_header = $m[1];
	}
}
if ( file_exists( $readme_file ) ) {
	$readme_content = file_get_contents( $readme_file );
	if ( is_string( $readme_content ) && preg_match( '/^\s*Tested up to:\s*([0-9.]+)\s*$/mi', $readme_content, $m ) ) {
		$tested_readme = $m[1];
	}
}

$assert( '' !== $tested_header, 'Plugin header has Tested up to field' );
$assert( '' !== $tested_readme, 'readme has Tested up to field' );
if ( '' !== $tested_header && '' !== $tested_readme ) {
	$assert( $tested_header === $tested_readme, 'Header/readme Tested up to values are consistent' );
}

// Print report.
echo "=== WP70 Smoke Eval Report ===\n";
foreach ( $results as $item ) {
	echo ( $item['pass'] ? '[PASS] ' : '[FAIL] ' ) . $item['check'] . "\n";
}

if ( ! empty( $errors ) ) {
	echo "\nSummary: FAIL (" . count( $errors ) . " checks failed)\n";
	exit( 1 );
}

echo "\nSummary: PASS\n";
exit( 0 );
