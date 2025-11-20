<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Backup_Lite_Restore_Handler {

    const OPTION_STATE = 'backup_lite_restore_state';

    public static function init() {
        add_action( 'wp_ajax_backup_lite_restore_upload', [ __CLASS__, 'upload' ] );
        add_action( 'wp_ajax_backup_lite_restore_from_backup', [ __CLASS__, 'restore_from_backup' ] );
        add_action( 'wp_ajax_backup_lite_restore_remote_url', [ __CLASS__, 'restore_remote' ] );
        add_action( 'wp_ajax_backup_lite_restore_progress', [ __CLASS__, 'progress' ] );
        add_action( 'wp_ajax_backup_lite_restore_enqueue', [ __CLASS__, 'enqueue_restore_job' ] );
        add_action( 'wp_ajax_backup_lite_restore_job_status', [ __CLASS__, 'job_status' ] );
        add_action( 'wp_ajax_backup_lite_restore_job_cancel', [ __CLASS__, 'job_cancel' ] );
        add_action( 'wp_ajax_backup_lite_restore_confirm', [ __CLASS__, 'confirm' ] );
        add_action( 'wp_ajax_backup_lite_restore_cancel', [ __CLASS__, 'cancel_restore' ] );
        add_action( 'wp_ajax_backup_lite_trigger_restore_job', [ __CLASS__, 'trigger_restore_job' ] );
        add_action( 'wp_ajax_backup_lite_restore_chunk_prepare', [ __CLASS__, 'chunk_prepare' ] );
        add_action( 'wp_ajax_backup_lite_restore_chunk_upload', [ __CLASS__, 'chunk_upload' ] );
        add_action( 'wp_ajax_backup_lite_restore_chunk_finalize', [ __CLASS__, 'chunk_finalize' ] );
        add_action( 'wp_ajax_backup_lite_restore_chunk_abort', [ __CLASS__, 'chunk_abort' ] );
    }

    public static function upload() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        $file = $_FILES['file'] ?? $_FILES['restoreFile'] ?? null;
        if ( empty( $file ) ) {
            wp_send_json_error( [ 'message' => __( 'No restore file uploaded.', 'museder-restoreone' ) ], 400 );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $overrides = [ 'test_form' => false ];
        $uploaded  = wp_handle_upload( $file, $overrides );

        if ( isset( $uploaded['error'] ) ) {
            backup_lite_log( 'error', 'restore_upload_failed', [ 'error' => $uploaded['error'] ] );
            wp_send_json_error( [ 'message' => __( 'Failed to upload restore file.', 'museder-restoreone' ) ], 500 );
        }

        $file_path = wp_normalize_path( $uploaded['file'] );
        $ext       = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        if ( ! in_array( $ext, [ 'zip', 'wpress' ], true ) ) {
            @unlink( $file_path );
            wp_send_json_error( [ 'message' => __( 'Unsupported file type. Allowed: zip, wpress.', 'museder-restoreone' ) ], 415 );
        }

        $backup_dir = backup_lite_get_backup_dir();
        $unique     = wp_unique_filename( $backup_dir, basename( $file_path ) );
        $destination = trailingslashit( $backup_dir ) . $unique;

        if ( ! self::move_file( $file_path, $destination ) ) {
            wp_send_json_error( [ 'message' => __( 'Unable to store uploaded file for restore.', 'museder-restoreone' ) ], 500 );
        }

        $summary = self::prepare_session( $destination, 'upload' );

        wp_send_json_success( [
            'summary' => $summary,
            'progress' => self::format_progress(),
        ] );
    }

    public static function restore_from_backup() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        $filename = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
        if ( empty( $filename ) ) {
            wp_send_json_error( [ 'message' => __( 'Backup filename not provided.', 'museder-restoreone' ) ], 400 );
        }

        $backup_dir = backup_lite_get_backup_dir();
        $path       = wp_normalize_path( trailingslashit( $backup_dir ) . basename( $filename ) );

        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            wp_send_json_error( [ 'message' => __( 'Backup file not found or unreadable.', 'museder-restoreone' ) ], 404 );
        }

        $summary = self::prepare_session( $path, 'existing' );

        wp_send_json_success( [
            'summary'  => $summary,
            'progress' => self::format_progress(),
        ] );
    }

    public static function restore_remote() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        $url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
        if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
            wp_send_json_error( [ 'message' => __( 'Please enter a valid URL.', 'museder-restoreone' ) ], 400 );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $temp = download_url( $url, 300 );
        if ( is_wp_error( $temp ) ) {
            backup_lite_log( 'error', 'restore_remote_download_failed', [ 'url' => $url, 'error' => $temp->get_error_message() ] );
            wp_send_json_error( [ 'message' => __( 'Unable to download remote backup.', 'museder-restoreone' ) ], 500 );
        }

        $ext = strtolower( pathinfo( $temp, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, [ 'zip', 'wpress' ], true ) ) {
            @unlink( $temp );
            wp_send_json_error( [ 'message' => __( 'Downloaded file is not a supported backup format.', 'museder-restoreone' ) ], 415 );
        }

        $backup_dir = backup_lite_get_backup_dir();
        $unique     = wp_unique_filename( $backup_dir, basename( $temp ) );
        $destination = trailingslashit( $backup_dir ) . $unique;

        if ( ! self::move_file( $temp, $destination ) ) {
            wp_send_json_error( [ 'message' => __( 'Unable to store downloaded file for restore.', 'museder-restoreone' ) ], 500 );
        }

        $summary = self::prepare_session( $destination, 'remote', [ 'source_url' => $url ] );

        wp_send_json_success( [
            'summary'  => $summary,
            'progress' => self::format_progress(),
        ] );
    }

    public static function progress() {
        self::ensure_permission();

        $state = self::get_state();

        if ( empty( $state ) ) {
            wp_send_json( self::format_progress( 0, __( 'Waiting for action…', 'museder-restoreone' ), true ) );
        }

        $progress = self::format_progress();
        wp_send_json( $progress );
    }

    public static function enqueue_restore_job() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        // Check for active jobs, but also clean up any stale jobs that should be considered finished
        $active_job = Backup_Lite_Restore_Jobs::has_active_job();
        if ( $active_job ) {
            // Double-check: if the job has been running for more than 6 hours, consider it stale
            $job_age = 0;
            if ( ! empty( $active_job['started_at'] ) ) {
                $started = strtotime( $active_job['started_at'] );
                if ( $started ) {
                    $job_age = time() - $started;
                }
            } elseif ( ! empty( $active_job['created_at'] ) ) {
                $created = strtotime( $active_job['created_at'] );
                if ( $created ) {
                    $job_age = time() - $created;
                }
            }
            
            // If job is stale (older than 6 hours), mark it as failed and allow new job
            if ( $job_age > ( 6 * HOUR_IN_SECONDS ) ) {
                backup_lite_log( 'warning', 'Stale restore job detected, marking as failed', [
                    'job_id' => $active_job['id'],
                    'age_seconds' => $job_age,
                    'status' => $active_job['status'],
                ] );
                Backup_Lite_Restore_Jobs::update_job( $active_job['id'], [
                    'status' => 'failed',
                    'finished_at' => current_time( 'mysql' ),
                    'message' => __( 'Restore job timed out and was marked as failed.', 'museder-restoreone' ),
                ] );
            } else {
                // Job is still active, return conflict
                wp_send_json_error(
                    [ 'message' => __( 'Another restore is already in progress. Please wait for it to finish.', 'museder-restoreone' ) ],
                    409
                );
            }
        }

        $state = self::get_state();
        if ( empty( $state ) || empty( $state['file'] ) ) {
            wp_send_json_error( [ 'message' => __( 'No restore session is active.', 'museder-restoreone' ) ], 400 );
        }

        $options         = self::parse_options();
        $state['options'] = $options;
        self::set_state( $state );

        self::update_progress( 5, __( 'Restore job queued. Waiting to start…', 'museder-restoreone' ), false );

        // Get file size for progress estimation
        $file_path = isset( $state['file'] ) ? $state['file'] : '';
        $file_size = 0;
        if ( $file_path && file_exists( $file_path ) ) {
            $file_size = filesize( $file_path );
        } elseif ( isset( $state['size'] ) ) {
            $file_size = (float) $state['size'];
        }

        $job = Backup_Lite_Restore_Jobs::enqueue(
            [
                'state'   => $state,
                'options' => $options,
                'user_id' => get_current_user_id(),
            ]
        );

        wp_send_json_success(
            [
                'job'      => Backup_Lite_Restore_Jobs::prepare_job_response( $job ),
                'progress' => self::format_progress(),
                'history'  => self::history_for_js( 10 ),
                'file_size' => $file_size, // Pass file size to frontend for progress estimation
            ]
        );
    }

    public static function job_status() {
        // Ensure clean output for JSON response
        if ( ob_get_level() ) {
            @ob_end_clean();
        }
        
        self::ensure_permission();
        
        // Try to verify AJAX request, but don't fail completely if nonce is invalid
        // This allows status checks to continue even if nonce expires during long restore
        $nonce_valid = false;
        $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
        if ( ! empty( $nonce ) && wp_verify_nonce( $nonce, Backup_Lite_UI::NONCE ) ) {
            $nonce_valid = true;
        } else {
            // Nonce validation failed, but we'll still try to return job status
            // This is important for long-running restores where nonce may expire
            backup_lite_log( 'warning', 'job_status_nonce_failed', [
                'nonce_provided' => ! empty( $nonce ),
                'nonce_length' => strlen( $nonce ),
            ] );
        }

        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        if ( empty( $job_id ) ) {
            // If nonce is invalid, return 403 instead of 400 to trigger nonce refresh
            if ( ! $nonce_valid ) {
                wp_send_json_error( [
                    'code'    => 'invalid_nonce',
                    'message' => __( 'Your session has expired. Refreshing security token…', 'museder-restoreone' ),
                ], 403 );
            }
            wp_send_json_error( [ 'message' => __( 'Job identifier is required.', 'museder-restoreone' ) ], 400 );
        }

        $job = Backup_Lite_Restore_Jobs::get_job( $job_id );
        if ( ! $job ) {
            // If job not found but nonce is invalid, return nonce error first
            if ( ! $nonce_valid ) {
                wp_send_json_error( [
                    'code'    => 'invalid_nonce',
                    'message' => __( 'Your session has expired. Refreshing security token…', 'museder-restoreone' ),
                ], 403 );
            }
            wp_send_json_error( [ 'message' => __( 'Restore job not found.', 'museder-restoreone' ) ], 404 );
        }

        // If nonce is invalid but we have a valid job, still return the job status
        // This allows the frontend to continue monitoring even if nonce expires
        if ( ! $nonce_valid ) {
            // Return success but include a flag to indicate nonce should be refreshed
            wp_send_json_success(
                [
                    'job'     => Backup_Lite_Restore_Jobs::prepare_job_response( $job ),
                    'history' => self::history_for_js( 10 ),
                    'nonce_expired' => true, // Flag to trigger nonce refresh on frontend
                ]
            );
            return;
        }

        wp_send_json_success(
            [
                'job'     => Backup_Lite_Restore_Jobs::prepare_job_response( $job ),
                'history' => self::history_for_js( 10 ),
            ]
        );
    }

    public static function trigger_restore_job() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        if ( empty( $job_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Job identifier is required.', 'museder-restoreone' ) ], 400 );
        }

        $job = Backup_Lite_Restore_Jobs::get_job( $job_id );
        if ( ! $job ) {
            wp_send_json_error( [ 'message' => __( 'Restore job not found.', 'museder-restoreone' ) ], 404 );
        }

        // Only trigger if job is still pending
        if ( $job['status'] !== 'pending' ) {
            wp_send_json_success( [
                'message' => __( 'Job is already running or completed.', 'museder-restoreone' ),
                'job'     => Backup_Lite_Restore_Jobs::prepare_job_response( $job ),
            ] );
        }

        // Manually trigger the job execution
        // This is a fallback if WordPress Cron doesn't run immediately
        if ( ! wp_next_scheduled( Backup_Lite_Restore_Jobs::CRON_HOOK, [ $job_id ] ) ) {
            // Re-schedule if it was missed
            wp_schedule_single_event( time(), Backup_Lite_Restore_Jobs::CRON_HOOK, [ $job_id ] );
        }

        // Try to spawn cron immediately
        Backup_Lite_Restore_Jobs::spawn_cron();

        // Also try to execute directly if possible (non-blocking)
        // This ensures the job starts even if cron is disabled
        if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
            // Trigger via HTTP request to avoid blocking
            $cron_url = site_url( 'wp-cron.php?doing_wp_cron' );
            wp_remote_post( $cron_url, [
                'timeout'  => 0.01,
                'blocking' => false,
            ] );
        }

        wp_send_json_success( [
            'message' => __( 'Restore job triggered.', 'museder-restoreone' ),
            'job'     => Backup_Lite_Restore_Jobs::prepare_job_response( $job ),
        ] );
    }

    public static function job_cancel() {
        // Ensure clean output for JSON response
        if ( ob_get_level() ) {
            @ob_end_clean();
        }
        
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        if ( empty( $job_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Job identifier is required.', 'museder-restoreone' ) ], 400 );
            return;
        }

        try {
            $job = Backup_Lite_Restore_Jobs::get_job( $job_id );
            if ( ! $job ) {
                wp_send_json_error( [ 'message' => __( 'Restore job not found.', 'museder-restoreone' ) ], 404 );
                return;
            }

            Backup_Lite_Restore_Jobs::request_cancel( $job_id );
            
            // Cleanup state, but don't let errors break the response
            try {
                self::cleanup_state_after_cancel();
            } catch ( Exception $e ) {
                backup_lite_log( 'warning', 'Error during cleanup after cancel.', [ 'error' => $e->getMessage() ] );
            }

            wp_send_json_success(
                [
                    'message' => __( 'Cancellation requested. You can safely close this page.', 'museder-restoreone' ),
                    'job'     => Backup_Lite_Restore_Jobs::prepare_job_response( Backup_Lite_Restore_Jobs::get_job( $job_id ) ),
                ]
            );
        } catch ( Exception $e ) {
            backup_lite_log( 'error', 'Error cancelling restore job.', [ 'job_id' => $job_id, 'error' => $e->getMessage() ] );
            wp_send_json_error( [ 'message' => __( 'Failed to cancel restore job. Please try again.', 'museder-restoreone' ) ], 500 );
        }
    }

    public static function confirm() {
        self::enqueue_restore_job();
    }

    public static function cancel_restore() {
        // Ensure clean output for JSON response
        if ( ob_get_level() ) {
            @ob_end_clean();
        }
        
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        try {
            $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
            if ( $job_id ) {
                Backup_Lite_Restore_Jobs::request_cancel( $job_id );
            } else {
                $active = Backup_Lite_Restore_Jobs::has_active_job();
                if ( $active ) {
                    Backup_Lite_Restore_Jobs::request_cancel( $active['id'] );
                }
            }

            // Cleanup state, but don't let errors break the response
            try {
                self::cleanup_state_after_cancel();
            } catch ( Exception $e ) {
                backup_lite_log( 'warning', 'Error during cleanup after cancel.', [ 'error' => $e->getMessage() ] );
            }

            wp_send_json_success( [
                'message'  => __( 'Restore process cancelled.', 'museder-restoreone' ),
                'progress' => self::format_progress( 0, __( 'Waiting for action…', 'museder-restoreone' ), false ),
            ] );
        } catch ( Exception $e ) {
            backup_lite_log( 'error', 'Error cancelling restore.', [ 'error' => $e->getMessage() ] );
            wp_send_json_error( [ 'message' => __( 'Failed to cancel restore. Please try again.', 'museder-restoreone' ) ], 500 );
        }
    }

    public static function run_job( $job_id, $job ) {
        $state   = isset( $job['state'] ) ? $job['state'] : [];
        $options = isset( $job['options'] ) ? $job['options'] : [];

        if ( empty( $state ) || empty( $state['file'] ) ) {
            return [
                'success' => false,
                'message' => __( 'Restore file is missing. Please reselect the backup and try again.', 'museder-restoreone' ),
                'error'   => 'missing_state',
            ];
        }

        self::set_state( $state );

        $history_entry = isset( $job['history'] ) && is_array( $job['history'] ) ? $job['history'] : [
            'timestamp_utc' => time(), // Store Unix timestamp (UTC) for accurate timezone conversion
            'timestamp' => gmdate( 'Y-m-d H:i:s', time() ), // Store UTC datetime string for backward compatibility
            'file'      => isset( $state['filename'] ) ? $state['filename'] : basename( $state['file'] ),
            'result'    => 'pending',
            'log'       => '',
        ];

        $suspend_cache_state = null;

        try {
            ignore_user_abort( true );
            @set_time_limit( 0 );

            if ( function_exists( 'wp_raise_memory_limit' ) ) {
                wp_raise_memory_limit( 'admin' );
            }

            if ( function_exists( 'wp_suspend_cache_invalidation' ) ) {
                $suspend_cache_state = wp_suspend_cache_invalidation( true );
            }

            self::report_job_progress( $job_id, 10, __( 'Preparing restore environment…', 'museder-restoreone' ) );

            if ( self::job_should_abort( $job_id ) ) {
                return self::handle_job_cancelled( $job_id, $history_entry );
            }

            if ( ! empty( $options['auto_backup'] ) ) {
                self::report_job_progress( $job_id, 20, __( 'Creating safety backup…', 'museder-restoreone' ) );
                $backup = Backup_Lite_Backup::backup_site();
                if ( empty( $backup['success'] ) ) {
                    $history_entry['result'] = 'failed';
                    $history_entry['log']    = isset( $backup['log'] ) ? basename( $backup['log'] ) : '';
                    self::report_job_progress( $job_id, 0, __( 'Pre-restore backup failed. Restore aborted.', 'museder-restoreone' ), true, 'failed' );
                    throw new RuntimeException( __( 'Pre-restore backup failed. Restore aborted.', 'museder-restoreone' ) );
                }
            }

            self::report_job_progress( $job_id, 45, __( 'Extracting backup archive…', 'museder-restoreone' ) );

            if ( self::job_should_abort( $job_id ) ) {
                return self::handle_job_cancelled( $job_id, $history_entry );
            }

            // Pass progress callback to restore_site for detailed progress updates
            // Wrap in try-catch to handle any exceptions during restore
            try {
                $restore = Backup_Lite_Restore::restore_site( $state['file'], $options, function( $percent, $message ) use ( $job_id ) {
                    // Map restore progress (0-100) to job progress (45-95)
                    // Reserve 45-95 for restore operations, 95-100 for final cleanup
                    $mapped_percent = 45 + ( $percent * 0.5 ); // 45% to 95%
                    self::report_job_progress( $job_id, $mapped_percent, $message );
                } );
            } catch ( Throwable $restore_exception ) {
                // If restore_site throws an exception, log it but check if restore actually completed
                backup_lite_log( 'error', 'restore_site_exception', [
                    'message' => $restore_exception->getMessage(),
                    'trace'   => $restore_exception->getTraceAsString(),
                ] );
                
                // Check if database and files were actually restored despite the exception
                // This can happen if PclZip throws an exception after successful extraction
                $restore = [
                    'success' => false,
                    'message' => $restore_exception->getMessage(),
                    'error'   => $restore_exception->getMessage(),
                ];
            }

            if ( self::job_should_abort( $job_id ) ) {
                return self::handle_job_cancelled( $job_id, $history_entry );
            }

            // Check restore result - ensure we have a valid result array
            if ( ! is_array( $restore ) ) {
                backup_lite_log( 'error', 'Restore returned invalid result', [ 'job_id' => $job_id, 'restore_type' => gettype( $restore ) ] );
                $restore = [
                    'success' => false,
                    'message' => __( 'Restore failed with invalid result.', 'museder-restoreone' ),
                ];
            }

            if ( ! empty( $restore['success'] ) ) {
                self::report_job_progress( $job_id, 95, __( 'Finalizing restore…', 'museder-restoreone' ) );
                
                // Restore plugin activation status from backup
                self::restore_plugin_status();
                
                // Explicitly set status to success when reporting 100% completion
                // This ensures the frontend can detect completion immediately
                backup_lite_log( 'info', 'Restore completed successfully, setting job status to success', [ 'job_id' => $job_id ] );
                self::report_job_progress( $job_id, 100, __( 'Restore completed successfully.', 'museder-restoreone' ), true, 'success' );
                $history_entry['result'] = 'success';
            } else {
                $message = isset( $restore['message'] ) ? $restore['message'] : __( 'Restore failed.', 'museder-restoreone' );
                $error_code = isset( $restore['code'] ) ? $restore['code'] : 'unknown_error';
                backup_lite_log( 'error', 'Restore failed, setting job status to failed', [ 
                    'job_id' => $job_id, 
                    'message' => $message,
                    'error_code' => $error_code,
                    'restore_result' => $restore,
                ] );
                
                // Explicitly set job status to failed when reporting progress
                // This ensures the frontend can detect failure immediately
                self::report_job_progress( $job_id, 100, $message, true, 'failed' );
                $history_entry['result'] = 'failed';
            }

            if ( isset( $restore['log'] ) ) {
                $history_entry['log'] = basename( $restore['log'] );
            }

            backup_lite_append_restore_history( $history_entry );

            $final_state = self::get_state();
            if ( ! empty( $final_state ) ) {
                $final_state['completed'] = true;
                self::set_state( $final_state );
            }

            return [
                'success'     => ! empty( $restore['success'] ),
                'message'     => isset( $restore['message'] ) ? $restore['message'] : '',
                'log'         => isset( $restore['log'] ) ? basename( $restore['log'] ) : '',
                'history_log' => isset( $history_entry['log'] ) ? $history_entry['log'] : '',
            ];
        } catch ( Throwable $e ) {
            $message = __( 'Restore failed because the server interrupted the request. Please review the error log and try again.', 'museder-restoreone' );

            if ( false !== stripos( $e->getMessage(), 'mailpoet' ) ) {
                $message = __( 'Restore was interrupted by MailPoet. Please resolve the MailPoet database error or temporarily disable it before retrying.', 'museder-restoreone' );
            }

            backup_lite_log(
                'error',
                'restore_unhandled_exception',
                [
                    'message' => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ]
            );

            $history_entry['result'] = 'failed';
            backup_lite_append_restore_history( $history_entry );

            // Explicitly set job status to failed when reporting progress
            // This ensures the frontend can detect failure immediately
            self::report_job_progress( $job_id, 100, $message, true, 'failed' );

            return [
                'success'     => false,
                'message'     => $message,
                'error'       => $e->getMessage(),
                'history_log' => isset( $history_entry['log'] ) ? $history_entry['log'] : '',
            ];
        } finally {
            if ( function_exists( 'wp_suspend_cache_invalidation' ) && null !== $suspend_cache_state ) {
                wp_suspend_cache_invalidation( (bool) $suspend_cache_state );
            }
        }
    }

    private static function parse_options() {
        $options = [];

        $options['overwrite']   = ! empty( $_POST['overwrite'] ) && 'true' === $_POST['overwrite'];
        $options['auto_backup'] = ! empty( $_POST['autoBackup'] ) && 'true' === $_POST['autoBackup'];
        $options['skip_config'] = ! empty( $_POST['skipConfig'] ) && 'true' === $_POST['skipConfig'];

        if ( ! empty( $_POST['searchReplace'] ) ) {
            $decoded = json_decode( wp_unslash( $_POST['searchReplace'] ), true );
            if ( is_array( $decoded ) ) {
                $options['search_replace'] = $decoded;
            }
        }

        return $options;
    }

    public static function chunk_prepare() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        $filename     = isset( $_POST['filename'] ) ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
        $filesize     = isset( $_POST['filesize'] ) ? absint( $_POST['filesize'] ) : 0;
        $chunk_size   = isset( $_POST['chunk_size'] ) ? absint( $_POST['chunk_size'] ) : 0;
        $total_chunks = isset( $_POST['total_chunks'] ) ? absint( $_POST['total_chunks'] ) : 0;

        if ( ! $filename || ! $filesize || ! $chunk_size || ! $total_chunks ) {
            wp_send_json_error( [ 'message' => __( 'Missing chunk upload metadata.', 'museder-restoreone' ) ], 400 );
        }

        $session_id = uniqid( 'restore_chunk_', true );
        $session_dir = self::chunk_session_dir( $session_id );

        if ( ! wp_mkdir_p( $session_dir ) ) {
            wp_send_json_error( [ 'message' => __( 'Unable to create chunk session directory.', 'museder-restoreone' ) ], 500 );
        }

        $meta = [
            'session_id'   => $session_id,
            'filename'     => $filename,
            'filesize'     => $filesize,
            'chunk_size'   => $chunk_size,
            'total_chunks' => $total_chunks,
            'created'      => time(),
        ];

        if ( false === file_put_contents( self::chunk_meta_path( $session_id ), wp_json_encode( $meta ), LOCK_EX ) ) {
            self::delete_chunk_session( $session_id );
            wp_send_json_error( [ 'message' => __( 'Unable to persist chunk session metadata.', 'museder-restoreone' ) ], 500 );
        }

        wp_send_json_success( [
            'session_id' => $session_id,
        ] );
    }

    public static function chunk_upload() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        $index      = isset( $_POST['chunk_index'] ) ? absint( $_POST['chunk_index'] ) : -1;

        if ( ! $session_id || $index < 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid chunk upload parameters.', 'museder-restoreone' ) ], 400 );
        }

        $meta = self::load_chunk_meta( $session_id );
        if ( ! $meta ) {
            wp_send_json_error( [ 'message' => __( 'Chunk session not found.', 'museder-restoreone' ) ], 404 );
        }

        if ( empty( $_FILES['chunk'] ) || empty( $_FILES['chunk']['tmp_name'] ) || ! file_exists( $_FILES['chunk']['tmp_name'] ) ) {
            wp_send_json_error( [ 'message' => __( 'No chunk file uploaded.', 'museder-restoreone' ) ], 400 );
        }

        $chunk_dir = self::chunk_session_dir( $session_id );
        if ( ! file_exists( $chunk_dir ) && ! wp_mkdir_p( $chunk_dir ) ) {
            wp_send_json_error( [ 'message' => __( 'Unable to access chunk directory.', 'museder-restoreone' ) ], 500 );
        }

        $chunk_path = trailingslashit( $chunk_dir ) . sprintf( 'chunk-%06d.part', $index );
        $tmp_name   = $_FILES['chunk']['tmp_name'];

        if ( ! @move_uploaded_file( $tmp_name, $chunk_path ) ) {
            $input  = fopen( $tmp_name, 'rb' );
            $output = fopen( $chunk_path, 'wb' );
            if ( ! $input || ! $output ) {
                if ( $input ) {
                    fclose( $input );
                }
                if ( $output ) {
                    fclose( $output );
                }
                wp_send_json_error( [ 'message' => __( 'Unable to store uploaded chunk.', 'museder-restoreone' ) ], 500 );
            }
            stream_copy_to_stream( $input, $output );
            fclose( $input );
            fclose( $output );
        }

        wp_send_json_success( [
            'chunk' => $index,
            'total' => (int) $meta['total_chunks'],
        ] );
    }

    public static function chunk_finalize() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';

        if ( ! $session_id ) {
            wp_send_json_error( [ 'message' => __( 'Missing chunk session identifier.', 'museder-restoreone' ) ], 400 );
        }

        $meta = self::load_chunk_meta( $session_id );
        if ( ! $meta ) {
            wp_send_json_error( [ 'message' => __( 'Chunk session not found.', 'museder-restoreone' ) ], 404 );
        }

        $chunk_dir    = self::chunk_session_dir( $session_id );
        $total_chunks = (int) $meta['total_chunks'];

        $chunks = [];
        for ( $i = 0; $i < $total_chunks; $i++ ) {
            $chunk_path = trailingslashit( $chunk_dir ) . sprintf( 'chunk-%06d.part', $i );
            if ( ! file_exists( $chunk_path ) ) {
                self::delete_chunk_session( $session_id );
                wp_send_json_error( [ 'message' => __( 'Uploaded chunks incomplete. Please retry.', 'museder-restoreone' ) ], 409 );
            }
            $chunks[] = $chunk_path;
        }

        $backup_dir = backup_lite_get_backup_dir();
        $final_name = wp_unique_filename( $backup_dir, $meta['filename'] );
        $final_path = trailingslashit( $backup_dir ) . $final_name;

        $output = fopen( $final_path, 'wb' );
        if ( ! $output ) {
            self::delete_chunk_session( $session_id );
            wp_send_json_error( [ 'message' => __( 'Unable to create merged archive.', 'museder-restoreone' ) ], 500 );
        }

        foreach ( $chunks as $chunk_path ) {
            $input = fopen( $chunk_path, 'rb' );
            if ( ! $input ) {
                fclose( $output );
                self::delete_chunk_session( $session_id );
                wp_send_json_error( [ 'message' => __( 'Unable to read uploaded chunk.', 'museder-restoreone' ) ], 500 );
            }
            stream_copy_to_stream( $input, $output );
            fclose( $input );
        }

        fflush( $output );
        fclose( $output );

        self::delete_chunk_session( $session_id );

        $summary = self::prepare_session( $final_path, 'upload' );

        wp_send_json_success( [
            'summary'  => $summary,
            'progress' => self::format_progress(),
        ] );
    }

    public static function chunk_abort() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        if ( $session_id ) {
            self::delete_chunk_session( $session_id );
        }

        wp_send_json_success();
    }

    private static function chunk_root_dir() {
        $root = backup_lite_get_storage_root();
        $dir  = trailingslashit( $root['path'] ) . 'restore-chunks';
        backup_lite_ensure_directory( $dir );
        return $dir;
    }

    private static function chunk_session_dir( $session_id ) {
        return trailingslashit( self::chunk_root_dir() ) . sanitize_file_name( $session_id );
    }

    private static function chunk_meta_path( $session_id ) {
        return trailingslashit( self::chunk_session_dir( $session_id ) ) . 'meta.json';
    }

    private static function load_chunk_meta( $session_id ) {
        $path = self::chunk_meta_path( $session_id );
        if ( ! file_exists( $path ) ) {
            return null;
        }
        $contents = file_get_contents( $path );
        if ( false === $contents ) {
            return null;
        }
        $meta = json_decode( $contents, true );
        return is_array( $meta ) ? $meta : null;
    }

    private static function delete_chunk_session( $session_id ) {
        $dir = self::chunk_session_dir( $session_id );
        if ( file_exists( $dir ) ) {
            backup_lite_delete_directory( $dir );
        }
    }

    private static function prepare_session( $file_path, $source, $extra = [] ) {
        $file_path = wp_normalize_path( $file_path );
        $size      = file_exists( $file_path ) ? filesize( $file_path ) : 0;
        $sha1      = file_exists( $file_path ) ? sha1_file( $file_path ) : '';

        $state = [
            'id'        => uniqid( 'restore_', true ),
            'file'      => $file_path,
            'filename'  => basename( $file_path ),
            'source'    => $source,
            'size'      => (float) $size,
            'sha1'      => $sha1,
            'created'   => current_time( 'mysql' ),
            'extra'     => $extra,
            'progress'  => self::format_progress( 10, __( 'File ready. Review summary before restoring.', 'museder-restoreone' ), true ),
            'completed' => false,
        ];

        self::set_state( $state );

        return self::compose_summary( $state );
    }

    public static function current_summary() {
        $state = self::get_state();
        if ( empty( $state['file'] ) ) {
            return null;
        }

        return self::compose_summary( $state );
    }

    public static function current_progress() {
        return self::format_progress();
    }

    public static function history_for_js( $limit = 10 ) {
        $raw      = backup_lite_get_restore_history( $limit );
        $prepared = [];

        foreach ( $raw as $entry ) {
            $row = $entry;
            
            // Preserve raw timestamp for programmatic comparisons on the frontend
            $parsed = 0;
            if ( ! empty( $entry['timestamp'] ) ) {
                $parsed = strtotime( $entry['timestamp'] );
                $row['timestamp_raw'] = $parsed ? (int) $parsed : 0;
            } else {
                $row['timestamp_raw'] = 0;
            }
            
            // Format timestamp to use WordPress date/time format and timezone
            // This matches the approach used in Log Files page for consistency
            $parsed = null;
            
            // Priority 1: Use timestamp_utc if available (most accurate, stored as UTC Unix timestamp)
            if ( ! empty( $entry['timestamp_utc'] ) && is_numeric( $entry['timestamp_utc'] ) ) {
                $parsed = (int) $entry['timestamp_utc'];
            } elseif ( ! empty( $entry['timestamp'] ) ) {
                $timestamp_str = $entry['timestamp'];
                
                // Priority 2: If it's already a Unix timestamp (numeric string), use it directly
                if ( is_numeric( $timestamp_str ) ) {
                    $parsed = (int) $timestamp_str;
                } else {
                    // Priority 3: Parse as UTC datetime string (new format: gmdate('Y-m-d H:i:s', time()))
                    // New entries store UTC datetime strings
                    $parsed = strtotime( $timestamp_str . ' UTC' );
                    
                    // Priority 4: If that fails, it's likely an old entry stored as local time
                    // Old entries used backup_lite_local_time() or current_time('mysql')
                    // which return local time strings. We need to convert them to UTC.
                    if ( false === $parsed || $parsed <= 0 ) {
                        // Parse as local time (assumes stored string is in WordPress local timezone)
                        $local_parsed = strtotime( $timestamp_str );
                        
                        if ( false !== $local_parsed && $local_parsed > 0 ) {
                            // Convert local time to UTC by getting the timezone offset
                            // This is the same approach WordPress uses internally
                            $timezone_string = get_option( 'timezone_string' );
                            if ( $timezone_string ) {
                                // Use timezone string (e.g., "Asia/Taipei")
                                try {
                                    $timezone = new DateTimeZone( $timezone_string );
                                    $datetime = new DateTime( '@' . $local_parsed, new DateTimeZone( 'UTC' ) );
                                    $datetime->setTimezone( $timezone );
                                    $offset = $timezone->getOffset( $datetime );
                                    // Convert local time to UTC: subtract offset
                                    $parsed = $local_parsed - $offset;
                                } catch ( Exception $e ) {
                                    // Fallback to gmt_offset if timezone string is invalid
                                    $gmt_offset = get_option( 'gmt_offset' );
                                    if ( $gmt_offset ) {
                                        $offset_seconds = (int) ( $gmt_offset * HOUR_IN_SECONDS );
                                        $parsed = $local_parsed - $offset_seconds;
                                    } else {
                                        $parsed = $local_parsed; // No offset, assume already UTC
                                    }
                                }
                            } else {
                                // Use gmt_offset option (e.g., 8 for UTC+8)
                                $gmt_offset = get_option( 'gmt_offset' );
                                if ( $gmt_offset ) {
                                    $offset_seconds = (int) ( $gmt_offset * HOUR_IN_SECONDS );
                                    $parsed = $local_parsed - $offset_seconds;
                                } else {
                                    // No timezone set, assume stored time is already UTC
                                    $parsed = $local_parsed;
                                }
                            }
                        }
                    }
                }
            }
            
            if ( false !== $parsed && $parsed > 0 ) {
                // Use backup_lite_local_time to format with WordPress timezone and format settings
                // This is the same approach used in Log Files page (backup_lite_local_time('Y-m-d H:i', filemtime($path)))
                // It correctly converts UTC timestamp to local timezone for display
                $date_format = get_option( 'date_format' );
                $time_format = get_option( 'time_format' );
                
                // If formats are not set, use defaults
                if ( empty( $date_format ) ) {
                    $date_format = 'Y-m-d';
                }
                if ( empty( $time_format ) ) {
                    $time_format = 'H:i:s';
                }
                
                $row['timestamp'] = backup_lite_local_time( 
                    $date_format . ' ' . $time_format, 
                    $parsed 
                );
            } elseif ( ! empty( $entry['timestamp'] ) ) {
                // If parsing fails, use the original string as fallback
                $row['timestamp'] = $entry['timestamp'];
            }
            
            if ( ! empty( $entry['log'] ) ) {
                $row['log_url'] = wp_nonce_url(
                    admin_url( 'admin-post.php?action=backup_lite_download_log&log=' . rawurlencode( $entry['log'] ) ),
                    'backup_lite_download_log_' . $entry['log']
                );
            } else {
                $row['log_url'] = '';
            }
            $prepared[] = $row;
        }

        return $prepared;
    }

    private static function ensure_permission() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'museder-restoreone' ) ], 403 );
        }
    }

    private static function move_file( $source, $destination ) {
        if ( @rename( $source, $destination ) ) {
            return true;
        }

        if ( @copy( $source, $destination ) ) {
            @unlink( $source );
            return true;
        }

        return false;
    }

    private static function get_state() {
        $state = get_option( self::OPTION_STATE, [] );
        return is_array( $state ) ? $state : [];
    }

    private static function set_state( $state ) {
        update_option( self::OPTION_STATE, $state, false );
    }

    private static function update_progress( $percent, $message, $done = false ) {
        $state = self::get_state();
        if ( empty( $state ) ) {
            return;
        }

        $state['progress'] = self::format_progress( $percent, $message, $done );
        self::set_state( $state );
    }

    private static function format_progress( $percent = null, $message = null, $done = null ) {
        $state = self::get_state();

        $progress = [
            'percent' => $percent,
            'message' => $message,
            'done'    => $done,
        ];

        if ( null === $percent && isset( $state['progress']['percent'] ) ) {
            $progress['percent'] = $state['progress']['percent'];
        } elseif ( null === $percent ) {
            $progress['percent'] = 0;
        }

        if ( null === $message && isset( $state['progress']['message'] ) ) {
            $progress['message'] = $state['progress']['message'];
        } elseif ( null === $message ) {
            $progress['message'] = __( 'Waiting for action…', 'museder-restoreone' );
        }

        if ( null === $done && isset( $state['progress']['done'] ) ) {
            $progress['done'] = (bool) $state['progress']['done'];
        } elseif ( null === $done ) {
            $progress['done'] = false;
        }

        if ( isset( $state['filename'] ) ) {
            $progress['filename'] = $state['filename'];
        }
        if ( isset( $state['source'] ) ) {
            $progress['source'] = $state['source'];
        }

        return $progress;
    }

    private static function compose_summary( $state ) {
        $path = isset( $state['file'] ) ? $state['file'] : '';
        $size = ( ! empty( $state['size'] ) ) ? (float) $state['size'] : ( ( file_exists( $path ) ) ? filesize( $path ) : 0 );
        $sha1 = ! empty( $state['sha1'] ) ? $state['sha1'] : ( ( file_exists( $path ) ) ? sha1_file( $path ) : '' );

        return [
            'name'    => isset( $state['filename'] ) ? $state['filename'] : basename( $path ),
            'size'    => size_format( $size, 2 ),
            'bytes'   => (float) $size,
            'sha1'    => $sha1,
            'source'  => isset( $state['source'] ) ? $state['source'] : '',
            'created' => isset( $state['created'] ) ? $state['created'] : '',
        ];
    }

    private static function report_job_progress( $job_id, $percent, $message, $done = false, $status = null ) {
        // If done is true or percent is 100, ensure status is set appropriately
        // But only if status is not explicitly provided (to allow for failed status)
        if ( null === $status && $done && $percent >= 100 ) {
            // When done is true and percent is 100, set status to 'success' by default
            // This ensures the frontend can detect completion even if handle_job hasn't finished
            $status = 'success';
            $percent = 100;
            backup_lite_log( 'info', 'Job progress: done=true, setting status to success', [
                'job_id' => $job_id,
                'percent' => $percent,
                'message' => $message,
            ] );
        }
        
        // If status is explicitly provided (e.g., 'failed'), use it
        if ( null !== $status ) {
            backup_lite_log( 'info', 'Job progress: status explicitly set', [
                'job_id' => $job_id,
                'percent' => $percent,
                'status' => $status,
                'message' => $message,
            ] );
        }
        
        Backup_Lite_Restore_Jobs::update_job_progress( $job_id, $percent, $message, $status );
        self::update_progress( $percent, $message, $done );
    }

    private static function job_should_abort( $job_id ) {
        return Backup_Lite_Restore_Jobs::is_cancel_requested( $job_id );
    }

    private static function handle_job_cancelled( $job_id, array $history_entry ) {
        $history_entry['result'] = 'cancelled';
        backup_lite_append_restore_history( $history_entry );
        self::report_job_progress( $job_id, 100, __( 'Restore cancelled.', 'museder-restoreone' ), true );
        Backup_Lite_Restore_Jobs::finalize_cancel( $job_id );
        self::cleanup_state_after_cancel();

        return [
            'success'     => false,
            'status'      => 'cancelled',
            'message'     => __( 'Restore cancelled.', 'museder-restoreone' ),
            'history_log' => isset( $history_entry['log'] ) ? $history_entry['log'] : '',
        ];
    }

    private static function cleanup_state_after_cancel() {
        $state = self::get_state();

        if ( ! empty( $state['file'] ) && isset( $state['source'] ) && in_array( $state['source'], [ 'upload', 'remote' ], true ) ) {
            $path = wp_normalize_path( $state['file'] );
            if ( $path && file_exists( $path ) && is_file( $path ) ) {
                @unlink( $path );
            }
        }

        self::set_state( [] );
    }

    /**
     * Restore plugin activation status from the backup database.
     * This ensures plugins are activated/deactivated according to the original site state.
     */
    private static function restore_plugin_status() {
        // First, try to get active_plugins from the temporary option (extracted from SQL file before import)
        $active_plugins = get_option( 'backup_lite_restored_active_plugins', [] );
        
        // If not found, try to get from the restored database
        if ( empty( $active_plugins ) || ! is_array( $active_plugins ) ) {
            $active_plugins = get_option( 'active_plugins', [] );
        }
        
        // Clean up temporary option
        delete_option( 'backup_lite_restored_active_plugins' );
        
        if ( ! is_array( $active_plugins ) || empty( $active_plugins ) ) {
            backup_lite_log( 'info', 'No active plugins found in restored database or SQL file, skipping plugin status restoration.', [] );
            return;
        }

        // Get all installed plugins
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $all_plugins = get_plugins();
        $plugins_dir = WP_PLUGIN_DIR;
        
        // Filter active plugins to only include those that actually exist
        $valid_active_plugins = [];
        $missing_plugins = [];
        
        foreach ( $active_plugins as $plugin_file ) {
            $plugin_path = wp_normalize_path( trailingslashit( $plugins_dir ) . $plugin_file );
            
            // Check if plugin file exists
            if ( file_exists( $plugin_path ) && isset( $all_plugins[ $plugin_file ] ) ) {
                $valid_active_plugins[] = $plugin_file;
            } else {
                $missing_plugins[] = $plugin_file;
            }
        }
        
        // Log missing plugins
        if ( ! empty( $missing_plugins ) ) {
            backup_lite_log( 'warning', 'Some plugins from backup are missing and will not be activated.', [
                'missing' => $missing_plugins,
            ] );
        }
        
        // Get currently active plugins
        $current_active = get_option( 'active_plugins', [] );
        
        // Sort arrays for comparison (WordPress may store them in different order)
        sort( $valid_active_plugins );
        sort( $current_active );
        
        // Only update if there's a difference
        if ( $valid_active_plugins !== $current_active ) {
            // Update active_plugins option
            // Restore original order from backup
            $restored_order = [];
            foreach ( $active_plugins as $plugin_file ) {
                if ( in_array( $plugin_file, $valid_active_plugins, true ) ) {
                    $restored_order[] = $plugin_file;
                }
            }
            update_option( 'active_plugins', $restored_order );
            
            // Clear plugin cache to ensure WordPress recognizes the changes
            wp_cache_delete( 'plugins', 'plugins' );
            
            // Also handle network-active plugins if multisite
            if ( is_multisite() ) {
                $network_active = get_site_option( 'active_sitewide_plugins', [] );
                if ( ! empty( $network_active ) ) {
                    backup_lite_log( 'info', 'Multisite network plugins detected, manual activation may be needed.', [
                        'network_plugins' => array_keys( $network_active ),
                    ] );
                }
            }
            
            backup_lite_log( 'info', 'Plugin activation status restored from backup.', [
                'restored_count' => count( $restored_order ),
                'missing_count' => count( $missing_plugins ),
                'previous_count' => count( $current_active ),
            ] );
        } else {
            backup_lite_log( 'info', 'Plugin activation status already matches backup, no changes needed.', [] );
        }
    }
}
