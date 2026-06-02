<?php
/**
 * shineching.com profile restore QA (2.7.275) — Docker / clean WP only.
 *
 * Phase A: Full restore from production backup archive.
 * Phase B: Backup on restored site + second full restore.
 *
 * Usage:
 *   wp --allow-root eval-file /tmp/museder-restoreone-src/tools/qa/shineching-profile-e2e.php [archive_basename]
 *
 * @package MusederRestoreOne
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load WordPress first.\n" );
	exit( 1 );
}

$expected_version = '2.7.275';
$archive          = isset( $args[0] ) && is_string( $args[0] ) && '' !== $args[0]
	? basename( $args[0] )
	: 'shineching.com-20260531013823-V6yYBa-1.zip';

$failures = 0;
$sc_qa_restore_tokens = array();

function sc_qa_fail( $msg ) {
	global $failures;
	++$failures;
	$GLOBALS['failures'] = $failures;
	fwrite( STDERR, "FAIL\t{$msg}\n" );
}

function sc_qa_pass( $msg ) {
	echo "PASS\t{$msg}\n";
}

function sc_qa_site_health_expected_bytes() {
	return 131241;
}

/** Archive wp-config DB name inside shineching seed (must never become live mid-restore). */
function sc_qa_archive_db_name() {
	return 'i10269493_ipic1';
}

function sc_qa_read_wp_config_db_name( $path = null ) {
	$path = $path ? (string) $path : ABSPATH . 'wp-config.php';
	if ( ! is_readable( $path ) ) {
		return '';
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$body = file_get_contents( $path );
	if ( ! is_string( $body ) ) {
		return '';
	}
	if ( preg_match( "/define\\s*\\(\\s*'DB_NAME'\\s*,\\s*'([^']+)'\\s*\\)/", $body, $m ) ) {
		return (string) $m[1];
	}
	return '';
}

function sc_qa_read_wp_config_table_prefix( $path = null ) {
	$path = $path ? (string) $path : ABSPATH . 'wp-config.php';
	if ( ! is_readable( $path ) ) {
		return '';
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$body = file_get_contents( $path );
	if ( ! is_string( $body ) ) {
		return '';
	}
	if ( preg_match( '/\$table_prefix\s*=\s*\'([^\']+)\'\s*;/', $body, $m ) ) {
		return (string) $m[1];
	}
	return '';
}

function sc_qa_assert_wp_config_not_archive_db( $context ) {
	$path = ABSPATH . 'wp-config.php';
	if ( is_readable( $path ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$body = file_get_contents( $path );
		if ( is_string( $body ) && false !== strpos( $body, sc_qa_archive_db_name() ) ) {
			sc_qa_fail( $context . ' wp_config_file_contains_archive_db' );
			return;
		}
	}
	$db_name = defined( 'DB_NAME' ) ? (string) DB_NAME : sc_qa_read_wp_config_db_name();
	if ( sc_qa_archive_db_name() === $db_name ) {
		sc_qa_fail( $context . ' wp_config_points_to_archive_db=' . $db_name );
	}
}

function sc_qa_assert_wp_config_preserves_host_db( $context, $expected_db_name, $expected_prefix ) {
	$db_name = defined( 'DB_NAME' ) ? (string) DB_NAME : sc_qa_read_wp_config_db_name();
	$prefix  = isset( $GLOBALS['table_prefix'] ) ? (string) $GLOBALS['table_prefix'] : sc_qa_read_wp_config_table_prefix();
	if ( $expected_db_name !== $db_name ) {
		sc_qa_fail( $context . ' db_name got=' . $db_name . ' want=' . $expected_db_name );
	}
	if ( $expected_prefix !== $prefix ) {
		sc_qa_fail( $context . ' table_prefix got=' . $prefix . ' want=' . $expected_prefix );
	}
	if ( sc_qa_archive_db_name() === $db_name ) {
		sc_qa_fail( $context . ' wp_config_still_archive_db' );
	}
	global $wpdb;
	if ( ! isset( $wpdb ) || ! $wpdb->check_connection( false ) ) {
		sc_qa_fail( $context . ' wpdb_check_connection_failed' );
	} else {
		sc_qa_pass( $context . ' wpdb_check_connection_ok' );
	}
}

/**
 * @param string $context
 * @param mixed  $expected_permalink
 * @param mixed  $expected_rewrite_rules
 * @return void
 */
function sc_qa_assert_permalink_policy_preserved( $context, $expected_permalink, $expected_rewrite_rules ) {
	$current_permalink = get_option( 'permalink_structure', '' );
	$current_rewrite   = get_option( 'rewrite_rules', '' );

	if ( (string) $current_permalink !== (string) $expected_permalink ) {
		sc_qa_fail(
			$context . ' permalink_structure_changed got=' . (string) $current_permalink
			. ' want=' . (string) $expected_permalink
		);
	} else {
		sc_qa_pass( $context . ' permalink_structure_preserved' );
	}

	if ( maybe_serialize( $current_rewrite ) !== maybe_serialize( $expected_rewrite_rules ) ) {
		sc_qa_fail( $context . ' rewrite_rules_changed' );
	} else {
		sc_qa_pass( $context . ' rewrite_rules_preserved' );
	}
}

function sc_qa_assert_wp_config_php_lint( $context ) {
	$path = ABSPATH . 'wp-config.php';
	if ( ! file_exists( $path ) ) {
		sc_qa_fail( $context . ' wp_config_missing' );
		return;
	}
	$output = array();
	$code   = 0;
	exec( 'php -l ' . escapeshellarg( $path ) . ' 2>&1', $output, $code );
	if ( 0 !== $code ) {
		sc_qa_fail( $context . ' wp_config_php_lint ' . implode( ' ', $output ) );
	} else {
		sc_qa_pass( $context . ' wp_config_php_lint' );
	}
}

/**
 * Validate resume-token continuity for reload/session-loss scenarios.
 *
 * @param string $job_id Restore job id.
 * @param string $raw_token Raw restore token from execute() response.
 * @param string $context QA label prefix.
 * @return void
 */
function sc_qa_assert_resume_token_continuity( $job_id, $raw_token, $context ) {
	$job_id    = (string) $job_id;
	$raw_token = (string) $raw_token;
	if ( '' === $job_id || '' === $raw_token ) {
		sc_qa_fail( $context . ' resume_token_missing_inputs' );
		return;
	}
	if ( ! class_exists( 'Museder_Restoreone_Restore_Handler' ) || ! class_exists( 'Museder_Restoreone_UI' ) ) {
		sc_qa_fail( $context . ' resume_token_required_classes_missing' );
		return;
	}

	$bootstrap_job = Museder_Restoreone_Restore_Handler::active_job_for_page();
	if ( ! is_array( $bootstrap_job ) ) {
		sc_qa_fail( $context . ' active_job_payload_missing' );
		return;
	}
	$bootstrap_token = isset( $bootstrap_job['restore_token'] ) ? (string) $bootstrap_job['restore_token'] : '';
	if ( '' === $bootstrap_token ) {
		sc_qa_fail( $context . ' active_job_missing_restore_token' );
	} else {
		sc_qa_pass( $context . ' active_job_restore_token_present' );
	}
	if ( $bootstrap_token !== $raw_token ) {
		sc_qa_fail( $context . ' active_job_restore_token_mismatch' );
	} else {
		sc_qa_pass( $context . ' active_job_restore_token_matches_execute' );
	}

	$current_user = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
	if ( function_exists( 'wp_set_current_user' ) ) {
		wp_set_current_user( 0 );
	}

	$_POST['restore_token'] = $bootstrap_token;
	$token_ok = Museder_Restoreone_UI::restore_progress_token_is_valid( $job_id );
	unset( $_POST['restore_token'] );

	if ( function_exists( 'wp_set_current_user' ) ) {
		wp_set_current_user( $current_user );
	}

	if ( ! $token_ok ) {
		sc_qa_fail( $context . ' resume_token_invalid_after_session_drop' );
	} else {
		sc_qa_pass( $context . ' resume_token_valid_after_session_drop' );
	}
}

/**
 * Validate post-complete token grant continuity used by safe-mode exit fallback.
 *
 * @param string $job_id Completed restore job id.
 * @param string $raw_token Raw restore token from execute() response.
 * @param string $context QA label prefix.
 * @return void
 */
function sc_qa_assert_post_complete_grant_continuity( $job_id, $raw_token, $context ) {
	$job_id    = (string) $job_id;
	$raw_token = (string) $raw_token;
	if ( '' === $job_id || '' === $raw_token ) {
		sc_qa_fail( $context . ' post_complete_missing_inputs' );
		return;
	}
	if ( ! class_exists( 'Museder_Restoreone_Restore_Token' ) || ! class_exists( 'Museder_Restoreone_UI' ) ) {
		sc_qa_fail( $context . ' post_complete_required_classes_missing' );
		return;
	}

	if ( ! Museder_Restoreone_Restore_Token::verify_post_complete_read( $raw_token, $job_id ) ) {
		sc_qa_fail( $context . ' post_complete_verify_failed' );
	} else {
		sc_qa_pass( $context . ' post_complete_verify_ok' );
	}

	$_POST['restore_token'] = $raw_token;
	$ui_ok = Museder_Restoreone_UI::restore_post_complete_read_is_valid( $job_id );
	unset( $_POST['restore_token'] );
	if ( ! $ui_ok ) {
		sc_qa_fail( $context . ' post_complete_ui_fallback_invalid' );
	} else {
		sc_qa_pass( $context . ' post_complete_ui_fallback_ok' );
	}
}

function sc_qa_assert_site_health_atomic( $context ) {
	$path = ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
	if ( ! file_exists( $path ) ) {
		return;
	}

	$expected = sc_qa_site_health_expected_bytes();
	$size     = (int) filesize( $path );
	$suffix   = '.museder-restoreone-partial';
	$partial  = $path . $suffix;

	if ( $size === $expected ) {
		$output = array();
		$code   = 0;
		exec( 'php -l ' . escapeshellarg( $path ) . ' 2>&1', $output, $code );
		if ( 0 !== $code ) {
			sc_qa_fail( $context . ' site_health_php_lint size_ok' );
		}
		return;
	}

	if ( file_exists( $partial ) ) {
		sc_qa_pass( $context . ' partial_sidecar_active partial=' . (int) filesize( $partial ) );
		return;
	}

	// Live file smaller than archive entry: OK while the previous core copy remains until atomic promote.
	if ( $size > 0 && $size < $expected ) {
		$output = array();
		$code   = 0;
		exec( 'php -l ' . escapeshellarg( $path ) . ' 2>&1', $output, $code );
		if ( 0 !== $code ) {
			sc_qa_fail( $context . ' truncated_corrupt_live size=' . $size . ' expected=' . $expected );
		}
		return;
	}
}

function sc_qa_run_restore_job( $archive_name, array $options, $label, $max_loops = 6000, $slice = 20 ) {
	$zip_path = museder_restoreone_get_backup_path( $archive_name );
	if ( ! $zip_path || ! file_exists( $zip_path ) ) {
		sc_qa_fail( $label . ' backup_missing ' . $archive_name );
		return '';
	}

	try {
		delete_option( Museder_Restoreone_Restore_Service::ACTIVE_JOB_OPTION );
		Museder_Restoreone_Restore_Handler::prepare_session( $zip_path, 'existing' );
		$prepared = Museder_Restoreone_Restore_Service::prepare( 'existing', $archive_name, '' );
		$job_id   = (string) ( $prepared['job_id'] ?? '' );
		if ( '' === $job_id ) {
			throw new RuntimeException( 'empty job_id' );
		}
		sc_qa_pass( $label . ' prepared ' . $job_id );

		Museder_Restoreone_Restore_Service::validate( $job_id );
		$exec      = Museder_Restoreone_Restore_Service::execute( $job_id, $options );
		$raw_token = isset( $exec['restore_token'] ) ? (string) $exec['restore_token'] : '';
		if ( '' === $raw_token ) {
			sc_qa_fail( $label . ' execute_missing_restore_token' );
		} else {
			$GLOBALS['sc_qa_restore_tokens'][ $job_id ] = $raw_token;
			sc_qa_pass( $label . ' execute_restore_token_present' );
			sc_qa_assert_resume_token_continuity( $job_id, $raw_token, $label . '_resume_token' );
		}

		for ( $i = 0; $i < $max_loops; $i++ ) {
			Museder_Restoreone_Restore_Service::process_job_slice( $job_id, (int) $slice, false, 'cli' );
			$meta = Museder_Restoreone_Restore_Service::get_job_meta( $job_id );
			if ( 'restore-files' === ( $meta['stage'] ?? '' ) ) {
				sc_qa_assert_site_health_atomic( $label . ' loop=' . ( $i + 1 ) );
				sc_qa_assert_wp_config_not_archive_db( $label . ' loop=' . ( $i + 1 ) );
			}
			if ( ! empty( $meta['completed'] ) ) {
				sc_qa_pass( $label . ' completed loop=' . ( $i + 1 ) . ' stage=' . ( $meta['stage'] ?? '' ) );
				break;
			}
			if ( 0 === ( $i + 1 ) % 25 ) {
				echo 'INFO\t' . $label . "\tloop=" . ( $i + 1 ) . "\tstage=" . ( $meta['stage'] ?? '' ) . "\tprogress=" . (int) ( $meta['progress'] ?? 0 ) . "\n";
			}
		}

		$final = Museder_Restoreone_Restore_Service::get_job_meta( $job_id );
		if ( empty( $final['completed'] ) ) {
			sc_qa_fail( $label . ' not_completed stage=' . ( $final['stage'] ?? '' ) . ' progress=' . (int) ( $final['progress'] ?? 0 ) );
		} elseif ( ! in_array( (string) ( $final['stage'] ?? '' ), array( 'done', 'rollback-done' ), true ) ) {
			sc_qa_fail( $label . ' bad_final_stage ' . ( $final['stage'] ?? '' ) );
		} else {
			sc_qa_pass( $label . ' final_stage_ok' );
		}

		sc_qa_assert_site_health_atomic( $label . ' final' );
		$health = ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		if ( ! file_exists( $health ) || (int) filesize( $health ) !== sc_qa_site_health_expected_bytes() ) {
			sc_qa_fail( $label . ' site_health_final_size got=' . ( file_exists( $health ) ? filesize( $health ) : 0 ) );
		} else {
			sc_qa_pass( $label . ' site_health_final_size' );
		}

		if ( '' !== $raw_token ) {
			sc_qa_assert_post_complete_grant_continuity( $job_id, $raw_token, $label . '_post_complete' );
		}

		return $job_id;
	} catch ( Throwable $e ) {
		sc_qa_fail( $label . ' exception ' . $e->getMessage() );
		return '';
	}
}

/**
 * @return array{total:int,missing:int}
 */
function sc_qa_count_missing_attachments() {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'",
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	$missing = 0;
	$total   = 0;
	if ( is_array( $rows ) ) {
		foreach ( $rows as $row ) {
			$rel = isset( $row['meta_value'] ) ? (string) $row['meta_value'] : '';
			if ( '' === $rel ) {
				continue;
			}
			++$total;
			$abs = WP_CONTENT_DIR . '/uploads/' . $rel;
			if ( ! file_exists( $abs ) ) {
				++$missing;
			}
		}
	}

	return array(
		'total'   => $total,
		'missing' => $missing,
	);
}

function sc_qa_assert_media_paths_reconciled( $job_id, $context ) {
	$job_id = (string) $job_id;
	if ( '' === $job_id ) {
		sc_qa_fail( $context . ' empty_job_id' );
		return;
	}

	$meta = Museder_Restoreone_Restore_Service::get_job_meta( $job_id );
	$mp   = isset( $meta['media_paths'] ) && is_array( $meta['media_paths'] ) ? $meta['media_paths'] : array();
	$fixed   = isset( $mp['fixed'] ) ? (int) $mp['fixed'] : 0;
	$scanned = isset( $mp['scanned'] ) ? (int) $mp['scanned'] : 0;

	if ( $scanned <= 0 ) {
		sc_qa_fail( $context . ' media_paths_scanned_zero (reconcile noop regression)' );
	} else {
		sc_qa_pass( $context . ' media_paths_scanned=' . $scanned );
	}
	if ( $fixed <= 0 ) {
		sc_qa_fail( $context . ' media_paths_fixed_zero' );
	} else {
		sc_qa_pass( $context . ' media_paths_fixed=' . $fixed );
	}

	$counts = sc_qa_count_missing_attachments();
	$pct    = $counts['total'] > 0 ? ( $counts['missing'] / $counts['total'] ) * 100 : 0;
	// Pre-fix field profile: ~47% missing (173/370). Post-reconcile should drop sharply.
	if ( $counts['total'] > 0 && $pct > 15 ) {
		sc_qa_fail(
			$context . ' attachment_missing_pct=' . round( $pct, 1 )
			. ' missing=' . $counts['missing'] . '/' . $counts['total']
		);
	} else {
		sc_qa_pass(
			$context . ' attachment_missing=' . $counts['missing'] . '/' . $counts['total']
			. ' pct=' . round( $pct, 1 )
		);
	}

	if ( get_post( 3600 ) ) {
		$rel = (string) get_post_meta( 3600, '_wp_attached_file', true );
		if ( '' === $rel || ! file_exists( WP_CONTENT_DIR . '/uploads/' . $rel ) ) {
			sc_qa_fail( $context . ' attachment_3600_missing rel=' . $rel );
		} else {
			sc_qa_pass( $context . ' attachment_3600_ok ' . $rel );
		}
	}

	if ( class_exists( 'Museder_Restoreone_Restore_Media_Paths' ) ) {
		$drift = Museder_Restoreone_Restore_Media_Paths::detect_upload_path_drift( 500, $job_id );
		$d     = isset( $drift['drift'] ) ? (int) $drift['drift'] : -1;
		if ( $d > 5 ) {
			sc_qa_fail( $context . ' post_restore_drift=' . $d );
		} else {
			sc_qa_pass( $context . ' post_restore_drift=' . $d );
		}
	}

	$cp = isset( $meta['checkpoints']['cleanup'] ) && is_array( $meta['checkpoints']['cleanup'] )
		? $meta['checkpoints']['cleanup']
		: array();
	$phase = isset( $cp['media_paths_phase'] ) ? (string) $cp['media_paths_phase'] : '';
	if ( 'done' !== $phase ) {
		sc_qa_fail( $context . ' media_paths_phase=' . $phase . ' (apply_pairs slice regression)' );
	} else {
		sc_qa_pass( $context . ' media_paths_phase=done' );
	}
	if ( ! empty( $cp['media_paths_apply_plan'] ) || ! empty( $cp['media_paths_apply_table_i'] ) ) {
		sc_qa_pass( $context . ' apply_pairs_sliced_checkpoint_present' );
	}

	foreach ( array( 1469, 1470, 1471, 1472 ) as $menu_id ) {
		if ( ! get_post( $menu_id ) ) {
			continue;
		}
		$rel = (string) get_post_meta( $menu_id, '_wp_attached_file', true );
		if ( '' === $rel || ! file_exists( WP_CONTENT_DIR . '/uploads/' . $rel ) ) {
			sc_qa_fail( $context . ' menu_icon_missing id=' . $menu_id . ' rel=' . $rel );
		} else {
			sc_qa_pass( $context . ' menu_icon_ok id=' . $menu_id . ' rel=' . $rel );
		}
	}
}

/**
 * R8: token-based REST final-status must confirm completed restore without admin-ajax/session.
 *
 * @param string $job_id Completed restore job id.
 * @param string $context QA label prefix.
 */
function sc_qa_assert_final_status_rest( $job_id, $context ) {
	$job_id = sanitize_text_field( (string) $job_id );
	if ( '' === $job_id ) {
		sc_qa_fail( $context . ' final_status_empty_job_id' );
		return;
	}

	if ( ! class_exists( 'Museder_Restoreone_Restore_Controller' ) || ! class_exists( 'Museder_Restoreone_Restore_Token' ) ) {
		sc_qa_fail( $context . ' final_status_classes_missing' );
		return;
	}

	$meta = Museder_Restoreone_Restore_Service::get_job_meta( $job_id );
	if ( empty( $meta['completed'] ) || 'done' !== (string) ( $meta['stage'] ?? '' ) ) {
		sc_qa_fail( $context . ' final_status_job_not_done stage=' . ( $meta['stage'] ?? '' ) );
		return;
	}

	$test_token = 'qa_r8_final_status_' . wp_generate_password( 32, false );
	update_option(
		Museder_Restoreone_Restore_Token::POST_COMPLETE_OPTION,
		array(
			'job_id'     => $job_id,
			'token_hash' => wp_hash( $test_token ),
			'expires_at' => time() + HOUR_IN_SECONDS,
		),
		false
	);

	$request = new WP_REST_Request( 'GET', '/museder-restoreone/v2/restore/final-status/' . $job_id );
	$request->set_param( 'job_id', $job_id );
	$request->set_param( '_restore_token', $test_token );

	$perm = Museder_Restoreone_Restore_Controller::check_final_status_permissions( $request );
	if ( true !== $perm ) {
		$code = is_wp_error( $perm ) ? $perm->get_error_code() : 'not_true';
		sc_qa_fail( $context . ' final_status_permission_denied code=' . $code );
		return;
	}
	sc_qa_pass( $context . ' final_status_permission_ok' );

	$response = Museder_Restoreone_Restore_Controller::final_status( $request );
	$data     = $response instanceof WP_REST_Response ? $response->get_data() : array();
	$job      = isset( $data['job'] ) && is_array( $data['job'] ) ? $data['job'] : array();

	if ( empty( $data['ok'] ) ) {
		sc_qa_fail( $context . ' final_status_ok_false' );
	} else {
		sc_qa_pass( $context . ' final_status_ok_true' );
	}

	if ( 'success' !== (string) ( $job['status'] ?? '' ) ) {
		sc_qa_fail( $context . ' final_status_job_status=' . ( $job['status'] ?? '' ) );
	} else {
		sc_qa_pass( $context . ' final_status_job_success' );
	}

	$history_match = false;
	if ( ! empty( $data['history'] ) && is_array( $data['history'] ) ) {
		foreach ( $data['history'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( (string) ( $row['job_id'] ?? '' ) === $job_id && 'success' === (string) ( $row['result'] ?? '' ) ) {
				$history_match = true;
				break;
			}
		}
	}
	if ( ! $history_match ) {
		sc_qa_fail( $context . ' final_status_history_success_missing' );
	} else {
		sc_qa_pass( $context . ' final_status_history_success' );
	}

	Museder_Restoreone_Restore_Token::clear_post_complete_access();
}

function sc_qa_run_backup_job( $label, $max_loops = 4000 ) {
	$options = array(
		'backup_mode'          => 'balanced',
		'backup_smart_exclude' => 'on',
	);

	try {
		$job = Museder_Restoreone_Backup_Jobs::create_job( $options );
	} catch ( Throwable $e ) {
		sc_qa_fail( $label . ' create ' . $e->getMessage() );
		return '';
	}

	$job_id = (string) ( $job['id'] ?? '' );
	if ( '' === $job_id ) {
		sc_qa_fail( $label . ' empty_job_id' );
		return '';
	}

	for ( $i = 0; $i < $max_loops; $i++ ) {
		$job = Museder_Restoreone_Backup_Jobs::process_job_immediately(
			$job_id,
			Museder_Restoreone_Backup_Jobs::CRON_BATCH_FILES,
			Museder_Restoreone_Backup_Jobs::CRON_BATCH_BYTES
		);
		if ( ! $job ) {
			sc_qa_fail( $label . ' process_null loop=' . ( $i + 1 ) );
			return '';
		}
		if ( 'completed' === (string) ( $job['status'] ?? '' ) ) {
			sc_qa_pass( $label . ' completed loop=' . ( $i + 1 ) );
			break;
		}
		if ( in_array( (string) ( $job['status'] ?? '' ), array( 'failed', 'cancelled' ), true ) ) {
			sc_qa_fail( $label . ' status_' . ( $job['status'] ?? '' ) );
			return '';
		}
		if ( 0 === ( $i + 1 ) % 25 ) {
			echo 'INFO\t' . $label . "\tloop=" . ( $i + 1 ) . "\tstage=" . ( $job['stage'] ?? '' ) . "\n";
		}
	}

	$files = glob( trailingslashit( museder_restoreone_get_backup_dir() ) . '*.zip' );
	if ( ! is_array( $files ) || empty( $files ) ) {
		sc_qa_fail( $label . ' no_archive' );
		return '';
	}
	usort(
		$files,
		static function ( $a, $b ) {
			return filemtime( $b ) - filemtime( $a );
		}
	);
	$name = basename( $files[0] );
	sc_qa_pass( $label . ' archive ' . $name );
	return $name;
}

$version = defined( 'MUSEDER_RESTOREONE_VERSION' ) ? MUSEDER_RESTOREONE_VERSION : '';
if ( $expected_version !== $version ) {
	sc_qa_fail( 'version_not_' . $expected_version . ' got=' . $version );
	exit( 1 );
}
sc_qa_pass( 'version_' . $expected_version );

$abs = museder_restoreone_get_backup_path( $archive );
if ( ! $abs || ! file_exists( $abs ) ) {
	sc_qa_fail( 'seed_archive_missing ' . $archive );
	exit( 1 );
}
sc_qa_pass( 'seed_archive_present bytes=' . filesize( $abs ) );

$host_db_name   = defined( 'DB_NAME' ) ? (string) DB_NAME : sc_qa_read_wp_config_db_name();
$host_db_prefix = isset( $GLOBALS['table_prefix'] ) ? (string) $GLOBALS['table_prefix'] : sc_qa_read_wp_config_table_prefix();
$host_permalink = get_option( 'permalink_structure', '' );
$host_rewrite_rules = get_option( 'rewrite_rules', '' );
if ( '' === $host_db_name || '' === $host_db_prefix ) {
	sc_qa_fail( 'pre_restore_wp_config_unreadable db=' . $host_db_name . ' prefix=' . $host_db_prefix );
	exit( 1 );
}
sc_qa_pass( 'pre_restore_host_db ' . $host_db_name . ' prefix=' . $host_db_prefix );

$shineching_options = array(
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

$phase_a_job = sc_qa_run_restore_job( $archive, $shineching_options, 'phase_a_seed_restore', 6000, 15 );

sc_qa_assert_final_status_rest( $phase_a_job, 'phase_a_r8_final_status' );
sc_qa_assert_media_paths_reconciled( $phase_a_job, 'phase_a_media_paths' );
sc_qa_assert_wp_config_preserves_host_db( 'phase_a_after_restore', $host_db_name, $host_db_prefix );
sc_qa_assert_wp_config_php_lint( 'phase_a_after_restore' );
sc_qa_assert_permalink_policy_preserved( 'phase_a_after_restore', $host_permalink, $host_rewrite_rules );

$restored_blogname = (string) get_option( 'blogname', '' );
if ( '' === $restored_blogname ) {
	sc_qa_fail( 'phase_a_empty_blogname' );
} else {
	sc_qa_pass( 'phase_a_blogname ' . $restored_blogname );
}

$round2_marker = 'SC275-R2-' . gmdate( 'YmdHis' );
update_option( 'blogname', $round2_marker );
sc_qa_pass( 'phase_b_marker_set ' . $round2_marker );

$round2_archive = sc_qa_run_backup_job( 'phase_b_backup', 5000 );
if ( '' === $round2_archive ) {
	exit( 1 );
}

sc_qa_run_restore_job( $round2_archive, $shineching_options, 'phase_b_restore', 6000, 15 );

$after_round2 = (string) get_option( 'blogname', '' );
if ( $after_round2 !== $round2_marker ) {
	sc_qa_fail( 'phase_b_blogname after=' . $after_round2 . ' want=' . $round2_marker );
} else {
	sc_qa_pass( 'phase_b_blogname_restored' );
}

sc_qa_assert_wp_config_php_lint( 'phase_b_final' );
sc_qa_assert_permalink_policy_preserved( 'phase_b_final', $host_permalink, $host_rewrite_rules );

if ( $failures > 0 ) {
	fwrite( STDERR, "SHINECHING_PROFILE_E2E=FAIL ({$failures})\n" );
	exit( 1 );
}

echo "SHINECHING_PROFILE_E2E=PASS\n";
exit( 0 );
