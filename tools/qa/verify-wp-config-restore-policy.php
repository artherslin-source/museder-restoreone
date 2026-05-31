<?php
/**
 * Verify wp-config restore policy: defer ZIP extract + populated-host DB credential merge.
 *
 * Usage: php tools/qa/verify-wp-config-restore-policy.php
 *
 * @package MusederRestoreOne
 */

if ( php_sapi_name() !== 'cli' ) {
	exit( 1 );
}

$root = dirname( __DIR__, 2 );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $root . '/' );
}

require_once $root . '/includes/class-restore-preflight.php';

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) );
	}
}

$failures = 0;

function wp_config_policy_fail( $message ) {
	global $failures;
	fwrite( STDERR, "FAIL: {$message}\n" );
	++$failures;
}

$backup_opts = Museder_Restoreone_Restore_Preflight::normalize_options(
	[
		'wp_config_mode'    => Museder_Restoreone_Restore_Preflight::MODE_CONFIG_BACKUP,
		'restore_order'     => Museder_Restoreone_Restore_Preflight::ORDER_DB_THEN_FILES,
		'restore_scope'     => Museder_Restoreone_Restore_Preflight::SCOPE_FULL,
		'restore_profile'   => Museder_Restoreone_Restore_Preflight::PROFILE_POPULATED,
		'db_target_prefix'  => 'w4gt_',
	]
);

if ( ! Museder_Restoreone_Restore_Preflight::should_skip_wp_config_in_zip( $backup_opts ) ) {
	wp_config_policy_fail( 'backup mode should defer wp-config during ZIP extract' );
}

$merge_opts = Museder_Restoreone_Restore_Preflight::normalize_options(
	[
		'wp_config_mode' => Museder_Restoreone_Restore_Preflight::MODE_CONFIG_MERGE,
	]
);
if ( ! Museder_Restoreone_Restore_Preflight::should_skip_wp_config_in_zip( $merge_opts ) ) {
	wp_config_policy_fail( 'merge mode should defer wp-config during ZIP extract' );
}

if ( ! Museder_Restoreone_Restore_Preflight::should_preserve_destination_db_credentials( $backup_opts ) ) {
	wp_config_policy_fail( 'db_then_files full restore should preserve destination DB credentials' );
}

$files_then_db = Museder_Restoreone_Restore_Preflight::normalize_options(
	[
		'wp_config_mode'  => Museder_Restoreone_Restore_Preflight::MODE_CONFIG_BACKUP,
		'restore_order'   => Museder_Restoreone_Restore_Preflight::ORDER_FILES_THEN_DB,
		'restore_profile' => Museder_Restoreone_Restore_Preflight::PROFILE_FRESH,
	]
);
if ( Museder_Restoreone_Restore_Preflight::should_preserve_destination_db_credentials( $files_then_db ) ) {
	wp_config_policy_fail( 'files_then_db should not preserve destination DB credentials at file stage' );
}

$tmpdir = sys_get_temp_dir() . '/mro-wp-config-qa-' . getmypid();
if ( ! is_dir( $tmpdir ) && ! mkdir( $tmpdir, 0700, true ) ) {
	wp_config_policy_fail( 'could not create temp directory' );
} else {
	$dest = $tmpdir . '/dest-wp-config.php';
	$arch = $tmpdir . '/archive-wp-config.php';

	$dest_body = <<<'PHP'
<?php
define( 'DB_NAME', 'i10269493_xsip1' );
define( 'DB_USER', 'xsip1_user' );
define( 'DB_PASSWORD', 'live_secret' );
define( 'DB_HOST', 'localhost' );
$table_prefix = 'w4gt_';
PHP;

	$arch_body = <<<'PHP'
<?php
define( 'DB_NAME', 'i10269493_ipic1' );
define( 'DB_USER', 'ipic1_user' );
define( 'DB_PASSWORD', 'archive_secret' );
define( 'DB_HOST', 'localhost' );
$table_prefix = 'pa7a_';
define( 'AUTH_KEY', 'archive-auth-key' );
PHP;

	file_put_contents( $dest, $dest_body );
	file_put_contents( $arch, $arch_body );

	$merged = Museder_Restoreone_Restore_Preflight::merge_wp_config_files(
		$dest,
		$arch,
		[
			'db_target_prefix' => 'w4gt_',
		]
	);

	if ( ! is_string( $merged ) ) {
		wp_config_policy_fail( 'merge_wp_config_files should return string' );
	} else {
		if ( false === strpos( $merged, "define( 'DB_NAME', 'i10269493_xsip1' )" ) ) {
			wp_config_policy_fail( 'merged config should keep destination DB_NAME' );
		}
		if ( false !== strpos( $merged, 'i10269493_ipic1' ) ) {
			wp_config_policy_fail( 'merged config should not keep archive DB_NAME' );
		}
		if ( false === strpos( $merged, "\$table_prefix = 'w4gt_';" ) ) {
			wp_config_policy_fail( 'merged config should keep db_target_prefix table_prefix' );
		}
		if ( false === strpos( $merged, 'archive-auth-key' ) ) {
			wp_config_policy_fail( 'merged config should keep non-DB settings from archive' );
		}
	}

	$dest_docker = $tmpdir . '/dest-docker-wp-config.php';
	$arch_docker = $tmpdir . '/arch-docker-wp-config.php';

	$dest_docker_body = <<<'PHP'
<?php
define( 'DB_NAME', getenv_docker('WORDPRESS_DB_NAME', 'wordpress') );
define( 'DB_USER', getenv_docker('WORDPRESS_DB_USER', 'wordpress') );
define( 'DB_PASSWORD', getenv_docker('WORDPRESS_DB_PASSWORD', 'wordpress') );
define( 'DB_HOST', getenv_docker('WORDPRESS_DB_HOST', 'db') );
$table_prefix = 'wp_';
PHP;

	$arch_docker_body = <<<'PHP'
<?php
define( 'DB_NAME', 'i10269493_ipic1' );
define( 'DB_USER', 'ipic1_user' );
define( 'DB_PASSWORD', 'archive_secret' );
define( 'DB_HOST', 'localhost' );
$table_prefix = 'pa7a_';
define( 'AUTH_KEY', 'archive-auth-key' );
PHP;

	file_put_contents( $dest_docker, $dest_docker_body );
	file_put_contents( $arch_docker, $arch_docker_body );

	$merged_docker = Museder_Restoreone_Restore_Preflight::merge_wp_config_files( $dest_docker, $arch_docker );

	if ( ! is_string( $merged_docker ) ) {
		wp_config_policy_fail( 'docker-style merge should return string' );
	} elseif ( false === strpos( $merged_docker, "getenv_docker('WORDPRESS_DB_NAME', 'wordpress')" ) ) {
		wp_config_policy_fail( 'docker-style merge should preserve nested getenv_docker DB_NAME' );
	} elseif ( false !== strpos( $merged_docker, 'i10269493_ipic1' ) ) {
		wp_config_policy_fail( 'docker-style merge should not keep archive DB_NAME literal' );
	} else {
		$merged_docker_path = $tmpdir . '/merged-docker-wp-config.php';
		file_put_contents( $merged_docker_path, $merged_docker );
		$lint = shell_exec( 'php -l ' . escapeshellarg( $merged_docker_path ) . ' 2>&1' );
		if ( ! is_string( $lint ) || false === strpos( $lint, 'No syntax errors' ) ) {
			wp_config_policy_fail( 'docker-style merged wp-config should pass php -l' );
		}
		unlink( $merged_docker_path );
	}

	// Cleanup.
	foreach ( [ $dest, $arch, $dest_docker, $arch_docker ] as $path ) {
		if ( is_file( $path ) ) {
			unlink( $path );
		}
	}
	if ( is_dir( $tmpdir ) ) {
		rmdir( $tmpdir );
	}
}

if ( $failures > 0 ) {
	fwrite( STDERR, "WP_CONFIG_RESTORE_POLICY_QA=FAIL ({$failures} checks)\n" );
	exit( 1 );
}

echo "WP_CONFIG_RESTORE_POLICY_QA=PASS\n";
