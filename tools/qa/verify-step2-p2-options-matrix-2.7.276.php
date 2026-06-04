<?php
/**
 * 2.7.276 Step 2 (P2) Review Restore Options — POST → parse_options → normalize → preflight matrix.
 *
 * Run inside WordPress container:
 *   php /tmp/museder-restoreone-src/tools/qa/verify-step2-p2-options-matrix-2.7.276.php
 *
 * @package MusederRestoreOne
 */

define( 'WP_USE_THEMES', false );
require '/var/www/html/wp-load.php';

$failures = 0;
$checks   = 0;

function p2_check( $label, $ok, $detail = '' ) {
	global $failures, $checks;
	++$checks;
	if ( $ok ) {
		echo "PASS\t{$label}\n";
		return;
	}
	++$failures;
	$detail = $detail ? " — {$detail}" : '';
	echo "FAIL\t{$label}{$detail}\n";
}

/**
 * Mirror Museder_Restoreone_Restore_Handler::parse_options() using synthetic POST.
 *
 * @param array<string, string> $post Simulated $_POST fields from admin.js enqueue.
 * @return array<string, mixed>
 */
function p2_parse_post_options( array $post ) {
	$options = [];

	$overwrite_value = isset( $post['overwrite'] ) ? sanitize_text_field( (string) $post['overwrite'] ) : '';
	$options['overwrite'] = ! empty( $overwrite_value ) && 'true' === $overwrite_value;

	$auto_backup_value = isset( $post['autoBackup'] ) ? sanitize_text_field( (string) $post['autoBackup'] ) : '';
	$options['auto_backup'] = ! empty( $auto_backup_value ) && 'true' === $auto_backup_value;

	$wp_config_mode = isset( $post['wpConfigMode'] ) ? sanitize_key( (string) $post['wpConfigMode'] ) : '';
	if ( ! in_array( $wp_config_mode, [ Museder_Restoreone_Restore_Preflight::MODE_CONFIG_BACKUP, Museder_Restoreone_Restore_Preflight::MODE_CONFIG_KEEP, Museder_Restoreone_Restore_Preflight::MODE_CONFIG_MERGE ], true ) ) {
		$wp_config_mode = Museder_Restoreone_Restore_Preflight::MODE_CONFIG_BACKUP;
	}
	$options['wp_config_mode'] = $wp_config_mode;
	$options['skip_config']    = ( Museder_Restoreone_Restore_Preflight::MODE_CONFIG_KEEP === $wp_config_mode );

	$restore_order = isset( $post['restoreOrder'] ) ? sanitize_key( (string) $post['restoreOrder'] ) : '';
	if ( in_array( $restore_order, [ Museder_Restoreone_Restore_Preflight::ORDER_DB_THEN_FILES, Museder_Restoreone_Restore_Preflight::ORDER_FILES_THEN_DB ], true ) ) {
		$options['restore_order'] = $restore_order;
	}

	$pause_plugins_value = isset( $post['pauseOtherPlugins'] ) ? sanitize_text_field( (string) $post['pauseOtherPlugins'] ) : '';
	if ( '' === $pause_plugins_value ) {
		$options['pause_other_plugins'] = true;
	} else {
		$options['pause_other_plugins'] = ( 'true' === $pause_plugins_value || '1' === $pause_plugins_value );
	}

	$restore_scope = isset( $post['restoreScope'] ) ? sanitize_key( (string) $post['restoreScope'] ) : '';
	if ( in_array( $restore_scope, [ Museder_Restoreone_Restore_Preflight::SCOPE_FULL, Museder_Restoreone_Restore_Preflight::SCOPE_CONTENT, Museder_Restoreone_Restore_Preflight::SCOPE_DB_ONLY ], true ) ) {
		$options['restore_scope'] = $restore_scope;
	}

	$safe_mode_value = isset( $post['safeMode'] ) ? sanitize_text_field( (string) $post['safeMode'] ) : '';
	if ( '' === $safe_mode_value ) {
		$options['safe_mode'] = true;
	} else {
		$options['safe_mode'] = ( 'true' === $safe_mode_value || '1' === $safe_mode_value || 'yes' === strtolower( $safe_mode_value ) );
	}

	$files_only_value = isset( $post['filesOnly'] ) ? sanitize_text_field( (string) $post['filesOnly'] ) : '';
	$options['files_only'] = ( 'true' === $files_only_value || '1' === $files_only_value || 'yes' === strtolower( $files_only_value ) );
	$options['search_replace'] = [];

	return $options;
}

/**
 * @param array<string, string> $post
 * @param array<string, mixed>  $expect
 * @param string                $label
 */
function p2_run_case( $label, array $post, array $expect, $archive_path = '' ) {
	$parsed = p2_parse_post_options( $post );
	$norm   = Museder_Restoreone_Restore_Preflight::normalize_options( $parsed );
	$pf     = Museder_Restoreone_Restore_Preflight::preflight( $archive_path, $parsed, false );

	foreach ( $expect as $key => $want ) {
		if ( 0 === strpos( $key, '__' ) ) {
			continue;
		}
		$got = null;
		if ( array_key_exists( $key, $norm ) ) {
			$got = $norm[ $key ];
		} elseif ( array_key_exists( $key, $pf['options'] ) ) {
			$got = $pf['options'][ $key ];
		}
		$detail = 'got=' . wp_json_encode( $got ) . ' want=' . wp_json_encode( $want );
		p2_check( "{$label}.{$key}", $want === $got, $detail );
	}

	if ( isset( $expect['__blocked'] ) ) {
		p2_check(
			"{$label}.blocked",
			(bool) $expect['__blocked'] === ! empty( $pf['blocked'] ),
			'blocked=' . ( ! empty( $pf['blocked'] ) ? '1' : '0' )
		);
	}

	if ( isset( $expect['__initial_stage'] ) ) {
		$stage = Museder_Restoreone_Restore_Preflight::initial_stage( $pf['options'] );
		p2_check(
			"{$label}.initial_stage",
			$expect['__initial_stage'] === $stage,
			"got={$stage}"
		);
	}
}

$version = defined( 'MUSEDER_RESTOREONE_VERSION' ) ? MUSEDER_RESTOREONE_VERSION : '';
p2_check( 'version_2.7.276', '2.7.276' === $version, "got {$version}" );

if ( ! class_exists( 'Museder_Restoreone_Restore_Preflight' ) ) {
	fwrite( STDERR, "FAIL\tRestore_Preflight missing\n" );
	exit( 1 );
}

// Reflection cross-check: handler parse_options must match our mirror for one case.
$_POST = [
	'overwrite'          => 'true',
	'autoBackup'         => 'false',
	'wpConfigMode'       => 'backup',
	'restoreOrder'       => 'db_then_files',
	'pauseOtherPlugins'  => 'true',
	'restoreScope'       => 'full',
	'safeMode'           => 'true',
	'filesOnly'          => 'false',
];
$ref    = new ReflectionClass( 'Museder_Restoreone_Restore_Handler' );
$method = $ref->getMethod( 'parse_options' );
$method->setAccessible( true );
$handler_parsed = $method->invoke( null );
$mirror_parsed  = p2_parse_post_options( $_POST );
p2_check(
	'handler_parse_matches_mirror_full_ui',
	$handler_parsed == $mirror_parsed,
	wp_json_encode( [ 'handler' => $handler_parsed, 'mirror' => $mirror_parsed ] )
);
unset( $_POST );

$archive = '';
$backups = function_exists( 'museder_restoreone_list_backups' ) ? museder_restoreone_list_backups() : [];
if ( ! empty( $backups[0]['path'] ) ) {
	$archive = (string) $backups[0]['path'];
}

// --- P2 matrix: UI POST combinations ---
$base_post = [
	'overwrite'         => 'true',
	'autoBackup'        => 'false',
	'wpConfigMode'      => 'backup',
	'restoreOrder'      => 'db_then_files',
	'pauseOtherPlugins' => 'true',
	'restoreScope'      => 'full',
	'safeMode'          => 'true',
	'filesOnly'         => 'false',
];

p2_run_case(
	'ui_full_site_user_claims',
	$base_post,
	[
		'restore_scope'         => Museder_Restoreone_Restore_Preflight::SCOPE_FULL,
		'files_only'            => false,
		'auto_backup'           => true, // populated profile forces true.
		'pause_other_plugins'   => true,
		'__initial_stage'       => 'restore-extract-db',
	]
);

p2_run_case(
	'ui_content_only_radio',
	array_merge( $base_post, [ 'restoreScope' => 'content_only' ] ),
	[
		'restore_scope'       => Museder_Restoreone_Restore_Preflight::SCOPE_CONTENT,
		'files_only'          => false,
		'__initial_stage'     => 'restore-files',
	]
);

p2_run_case(
	'ui_db_only_radio',
	array_merge( $base_post, [ 'restoreScope' => 'db_only' ] ),
	[
		'restore_scope'   => Museder_Restoreone_Restore_Preflight::SCOPE_DB_ONLY,
		'__initial_stage' => 'restore-extract-db',
	]
);

p2_run_case(
	'ui_full_plus_filesOnly_checkbox',
	array_merge( $base_post, [ 'filesOnly' => 'true' ] ),
	[
		'restore_scope'     => Museder_Restoreone_Restore_Preflight::SCOPE_FULL,
		'files_only'        => true,
		'wp_config_mode'    => Museder_Restoreone_Restore_Preflight::MODE_CONFIG_KEEP,
		'__initial_stage'   => 'restore-files',
	]
);

p2_run_case(
	'ui_autoBackup_unchecked_forced_on_populated',
	array_merge( $base_post, [ 'autoBackup' => 'false' ] ),
	[
		'auto_backup' => true,
	]
);

p2_run_case(
	'ui_pause_other_plugins_off',
	array_merge( $base_post, [ 'pauseOtherPlugins' => 'false' ] ),
	[
		'pause_other_plugins' => false,
	]
);

p2_run_case(
	'ui_wp_config_keep',
	array_merge( $base_post, [ 'wpConfigMode' => 'keep' ] ),
	[
		'wp_config_mode' => Museder_Restoreone_Restore_Preflight::MODE_CONFIG_KEEP,
		'skip_config'    => true,
	]
);

p2_run_case(
	'ui_restore_order_files_first',
	array_merge( $base_post, [ 'restoreOrder' => 'files_then_db' ] ),
	[
		'restore_order'     => Museder_Restoreone_Restore_Preflight::ORDER_FILES_THEN_DB,
		'__initial_stage'   => 'restore-files',
	]
);

p2_run_case(
	'ui_safe_mode_off',
	array_merge( $base_post, [ 'safeMode' => 'false' ] ),
	[
		'safe_mode' => false,
	]
);

p2_run_case(
	'ui_missing_restoreScope_defaults_full',
	array_diff_key( $base_post, [ 'restoreScope' => '' ] ),
	[
		'restore_scope'     => Museder_Restoreone_Restore_Preflight::SCOPE_FULL,
		'__initial_stage'   => 'restore-extract-db',
	]
);

p2_run_case(
	'ui_overwrite_off_blocks_full_execute',
	array_merge( $base_post, [ 'overwrite' => 'false' ] ),
	[
		'restore_scope' => Museder_Restoreone_Restore_Preflight::SCOPE_FULL,
		'__blocked'     => true,
	],
	$archive
);

// taiyuan round 1 job meta snapshot — only reachable if POST sent content_only radio.
p2_run_case(
	'taiyuan_r1_job_meta_replay',
	array_merge( $base_post, [
		'restoreScope'      => 'content_only',
		'pauseOtherPlugins' => 'true',
		'autoBackup'        => 'false',
	] ),
	[
		'restore_scope'       => Museder_Restoreone_Restore_Preflight::SCOPE_CONTENT,
		'files_only'          => false,
		'auto_backup'         => true,
		'pause_other_plugins' => true,
		'__initial_stage'     => 'restore-files',
	],
	$archive
);

// If user truly selected Full, POST must NOT look like taiyuan replay.
$full_norm = Museder_Restoreone_Restore_Preflight::normalize_options(
	p2_parse_post_options( $base_post )
);
p2_check(
	'full_ui_cannot_normalize_to_content_only',
	Museder_Restoreone_Restore_Preflight::SCOPE_FULL === ( $full_norm['restore_scope'] ?? '' ),
	'scope=' . ( $full_norm['restore_scope'] ?? '' )
);

// force_auto_backup hint for Step 2 UI.
$hints = Museder_Restoreone_Restore_Preflight::hints_for_summary( $archive, [] );
p2_check(
	'step2_force_auto_backup_hint_populated',
	! empty( $hints['force_auto_backup'] ),
	'force=' . ( ! empty( $hints['force_auto_backup'] ) ? '1' : '0' )
);

$plugin_dir  = defined( 'MUSEDER_RESTOREONE_PLUGIN_DIR' ) ? MUSEDER_RESTOREONE_PLUGIN_DIR : dirname( __DIR__, 2 ) . '/';
$admin_js    = file_get_contents( $plugin_dir . 'assets/js/admin.js' );
$handler_php = file_get_contents( $plugin_dir . 'includes/class-restore-handler.php' );
$service_php = file_get_contents( $plugin_dir . 'includes/class-restore-service.php' );

p2_check( 'frontend_execution_snapshot_guard', false !== strpos( $admin_js, 'getRestoreExecutionSnapshot' ) );
p2_check( 'frontend_execution_summary_guard', false !== strpos( $admin_js, 'restore-execution-summary' ) );
p2_check( 'frontend_payload_console_guard', false !== strpos( $admin_js, 'restore_enqueue_payload' ) );
p2_check( 'backend_options_log_guard', false !== strpos( $handler_php, 'restore_enqueue_options' ) && false !== strpos( $handler_php, 'restore_enqueue_job_created' ) );
p2_check( 'backend_terminal_status_payload_guard', false !== strpos( $handler_php, "'terminal_state'" ) && false !== strpos( $handler_php, "'cancelled'" ) );
p2_check( 'service_terminal_history_guard', false !== strpos( $service_php, 'ensure_terminal_history' ) && false !== strpos( $service_php, "'result'                   => \$result" ) );

echo "\nP2_OPTIONS_MATRIX checks={$checks} failures={$failures}\n";
if ( $failures > 0 ) {
	echo "P2_OPTIONS_MATRIX=FAIL\n";
	exit( 1 );
}
echo "P2_OPTIONS_MATRIX=PASS\n";
exit( 0 );
