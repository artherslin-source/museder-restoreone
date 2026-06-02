<?php
/**
 * Static guard checks for restore DB prefix migration flow.
 *
 * Usage: php tools/qa/verify-restore-prefix-migration-guards.php
 *
 * @package MusederRestoreOne
 */

$root         = dirname( __DIR__, 2 );
$service_file = $root . '/includes/class-restore-service.php';
$restore_file = $root . '/includes/class-restore.php';

if ( ! is_readable( $service_file ) || ! is_readable( $restore_file ) ) {
	fwrite( STDERR, "FAIL\tguard_files_missing\n" );
	exit( 1 );
}

$service_source = file_get_contents( $service_file );
$restore_source = file_get_contents( $restore_file );
if ( ! is_string( $service_source ) || '' === $service_source || ! is_string( $restore_source ) || '' === $restore_source ) {
	fwrite( STDERR, "FAIL\tguard_files_empty\n" );
	exit( 1 );
}

$required_service_tokens = array(
	'function stage_import_database',
	'function stage_migrate_db_prefix',
	'function verify_restored_database_capability_keys',
	'function set_stage_after_database_import',
	"if ( \$rewrite_from && \$rewrite_to && \$rewrite_from !== \$rewrite_to )",
	"if ( empty( \$result['success'] ) )",
	"Database prefix migration verification failed",
);

foreach ( $required_service_tokens as $token ) {
	if ( false === strpos( $service_source, $token ) ) {
		fwrite( STDERR, "FAIL\tmissing_service_guard\t{$token}\n" );
		exit( 1 );
	}
}

if ( preg_match( '/stage_import_database[\s\S]*?self::write_job_meta\( \$job_id, \$meta \);\s*return;\s*if \( ! empty\( \$result\[\'success\'\] \) \)/', $service_source ) ) {
	fwrite( STDERR, "FAIL\tunreachable_success_block_detected\n" );
	exit( 1 );
}

$required_restore_tokens = array(
	"\$result['source_prefix']",
	"\$result['target_prefix']",
	"\$result['prefix_rewrite_applied']",
);

foreach ( $required_restore_tokens as $token ) {
	if ( false === strpos( $restore_source, $token ) ) {
		fwrite( STDERR, "FAIL\tmissing_restore_result_field\t{$token}\n" );
		exit( 1 );
	}
}

fwrite( STDOUT, "PASS\trestore_prefix_migration_guards\n" );
exit( 0 );
