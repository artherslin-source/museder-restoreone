<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Museder_Restoreone_Restore_Handler {

    const OPTION_STATE = 'museder_restoreone_restore_state';

    public static function init() {
        add_action( 'wp_ajax_museder_restoreone_restore_upload', [ __CLASS__, 'upload' ] );
        add_action( 'wp_ajax_museder_restoreone_restore_from_backup', [ __CLASS__, 'restore_from_backup' ] );
        add_action( 'wp_ajax_museder_restoreone_restore_progress', [ __CLASS__, 'progress' ] );
        add_action( 'wp_ajax_museder_restoreone_restore_enqueue', [ __CLASS__, 'enqueue_restore_job' ] );
        add_action( 'wp_ajax_museder_restoreone_restore_job_status', [ __CLASS__, 'job_status' ] );
        add_action( 'wp_ajax_nopriv_museder_restoreone_restore_job_status', [ __CLASS__, 'job_status' ] );
        add_action( 'wp_ajax_museder_restoreone_restore_job_cancel', [ __CLASS__, 'job_cancel' ] );
        add_action( 'wp_ajax_museder_restoreone_restore_tick', [ __CLASS__, 'restore_tick' ] );
        add_action( 'wp_ajax_nopriv_museder_restoreone_restore_tick', [ __CLASS__, 'restore_tick' ] );
        add_action( 'wp_ajax_museder_restoreone_restore_confirm', [ __CLASS__, 'confirm' ] );
        add_action( 'wp_ajax_museder_restoreone_restore_cancel', [ __CLASS__, 'cancel_restore' ] );
        add_action( 'wp_ajax_museder_restoreone_trigger_restore_job', [ __CLASS__, 'trigger_restore_job' ] );
        add_action( 'wp_ajax_nopriv_museder_restoreone_trigger_restore_job', [ __CLASS__, 'trigger_restore_job' ] );
        add_action( 'wp_ajax_museder_restoreone_restore_chunk_prepare', [ __CLASS__, 'chunk_prepare' ] );
        add_action( 'wp_ajax_museder_restoreone_restore_chunk_upload', [ __CLASS__, 'chunk_upload' ] );
        add_action( 'wp_ajax_museder_restoreone_restore_chunk_finalize', [ __CLASS__, 'chunk_finalize' ] );
        add_action( 'wp_ajax_museder_restoreone_restore_chunk_abort', [ __CLASS__, 'chunk_abort' ] );
        add_action( 'wp_ajax_museder_restoreone_restore_chunk_status', [ __CLASS__, 'chunk_status' ] );
        add_action( 'wp_ajax_museder_restoreone_restore_env_caps', [ __CLASS__, 'env_caps' ] );
        add_action( 'wp_ajax_museder_restoreone_exit_safe_mode', [ __CLASS__, 'exit_safe_mode' ] );
        add_action( 'wp_ajax_museder_restoreone_reapply_safe_plugins', [ __CLASS__, 'reapply_safe_plugins' ] );
        add_action( 'wp_ajax_museder_restoreone_restore_force_unlock', [ __CLASS__, 'force_unlock' ] );
    }

    /**
     * Force unlock a stuck restore lock/active job pointer (admin-only).
     * This is a manual escape hatch for cases where restore crashed or the lock persisted after failure.
     *
     * @wp_ajax museder_restoreone_restore_force_unlock
     */
    public static function force_unlock() {
        self::ensure_permission();
        Museder_Restoreone_UI::verify_ajax_request();
        // Additional nonce verification for plugin-check.
        check_ajax_referer( Museder_Restoreone_UI::NONCE, 'nonce' );

        $active_job_id = class_exists( 'Museder_Restoreone_Restore_Service' ) ? Museder_Restoreone_Restore_Service::get_active_job_id() : '';
        $lock = class_exists( 'Museder_Restoreone_Restore_Lock' ) ? Museder_Restoreone_Restore_Lock::current_lock() : null;
        $lock_job_id = ( is_array( $lock ) && ! empty( $lock['job_id'] ) ) ? (string) $lock['job_id'] : '';

        // Optional hard-force mode: allow bypassing the running-job safety check.
        // This should only be used when the admin is certain the restore is stuck.
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above
        $force_value = isset( $_POST['force'] ) ? sanitize_text_field( wp_unslash( $_POST['force'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        $hard_force = ( 'true' === strtolower( $force_value ) || '1' === $force_value || 'yes' === strtolower( $force_value ) );

        // If the lock (or active pointer) maps to a truly running job, refuse to unlock (safety).
        $candidates = array_values(
            array_filter(
                array_unique(
                    array_map(
                        'sanitize_text_field',
                        [ (string) $active_job_id, (string) $lock_job_id ]
                    )
                )
            )
        );

        if ( ! $hard_force ) {
            foreach ( $candidates as $job_id ) {
                try {
                    $st = Museder_Restoreone_Restore_Service::status( $job_id );
                    $completed = is_array( $st ) && ! empty( $st['completed'] );
                    $stage = is_array( $st ) && isset( $st['stage'] ) ? (string) $st['stage'] : '';
                    if ( ! $completed && ! in_array( $stage, [ 'done', 'failed', 'cancelled' ], true ) ) {
                        wp_send_json_error(
                            [
                                // @plugin-check: escaped
                                'message' => esc_html__( 'Cannot force unlock: a restore job is still running. Please wait or cancel it first.', 'museder-restoreone' ),
                                'job_id'  => $job_id,
                                'stage'   => $stage,
                            ],
                            409
                        );
                    }
                } catch ( Exception $e ) {
                    // If status lookup fails, treat as stale and allow unlock.
                }
            }
        }

        // Perform cleanup (best-effort).
        $cleared = [
            'lock_job_id'   => $lock_job_id,
            'active_job_id' => $active_job_id,
            'hard_force'    => $hard_force,
            'cleared_lock'  => false,
            'cleared_active'=> false,
            'cleared_state' => false,
        ];

        if ( class_exists( 'Museder_Restoreone_Restore_Lock' ) ) {
            Museder_Restoreone_Restore_Lock::release();
            $cleared['cleared_lock'] = true;
        }

        if ( class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
            delete_option( Museder_Restoreone_Restore_Service::ACTIVE_JOB_OPTION );
            $cleared['cleared_active'] = true;
        }

        // Clear restore handler pointer for polling recovery.
        $state = self::get_state();
        if ( is_array( $state ) && ! empty( $state ) ) {
            if ( isset( $state['restore_service_job_id'] ) ) {
                unset( $state['restore_service_job_id'] );
                self::set_state( $state );
                $cleared['cleared_state'] = true;
            }
        }

        museder_restoreone_log( 'warning', 'Force unlock invoked for restore.', $cleared );

        wp_send_json_success(
            [
                // @plugin-check: escaped
                'message' => esc_html__( 'Restore lock cleared. You can start the restore again.', 'museder-restoreone' ),
                'cleared' => $cleared,
            ]
        );
    }

    /**
     * Return safe environment capability limits for client-side upload tuning.
     *
     * @wp_ajax museder_restoreone_restore_env_caps
     */
    public static function env_caps() {
        self::ensure_permission();
        Museder_Restoreone_UI::verify_ajax_request();

        // Additional nonce verification for plugin-check.
        check_ajax_referer( Museder_Restoreone_UI::NONCE, 'nonce' );

        // Only expose minimal, non-sensitive values needed for upload tuning.
        $upload_max = (string) ini_get( 'upload_max_filesize' );
        $post_max   = (string) ini_get( 'post_max_size' );
        $memory     = (string) ini_get( 'memory_limit' );
        $max_exec   = (int) ini_get( 'max_execution_time' );
        $max_input  = (int) ini_get( 'max_input_time' );

        // wp_convert_hr_to_bytes exists in WP core and handles shorthand like "128M".
        $upload_max_bytes = function_exists( 'wp_convert_hr_to_bytes' ) ? (int) wp_convert_hr_to_bytes( $upload_max ) : 0;
        $post_max_bytes   = function_exists( 'wp_convert_hr_to_bytes' ) ? (int) wp_convert_hr_to_bytes( $post_max ) : 0;
        $memory_bytes     = function_exists( 'wp_convert_hr_to_bytes' ) ? (int) wp_convert_hr_to_bytes( $memory ) : 0;

        // Detect basic capabilities that may affect restore analysis.
        $can_finfo = function_exists( 'finfo_open' );
        $has_zip   = class_exists( 'ZipArchive' );

        // Verify temp chunk root is writable (plugin-controlled path).
        $chunk_root = self::chunk_root_dir();
        // Use WP helper for portability across filesystems.
        $chunk_root_ok = ( $chunk_root && is_dir( $chunk_root ) && wp_is_writable( $chunk_root ) );

        wp_send_json_success(
            [
                'limits' => [
                    'upload_max_filesize' => [
                        'raw'   => $upload_max,
                        'bytes' => max( 0, $upload_max_bytes ),
                    ],
                    'post_max_size' => [
                        'raw'   => $post_max,
                        'bytes' => max( 0, $post_max_bytes ),
                    ],
                    'memory_limit' => [
                        'raw'   => $memory,
                        'bytes' => max( 0, $memory_bytes ),
                    ],
                    'max_execution_time' => max( 0, $max_exec ),
                    'max_input_time'     => max( 0, $max_input ),
                ],
                'caps' => [
                    'can_finfo'         => (bool) $can_finfo,
                    'has_ziparchive'    => (bool) $has_zip,
                    'chunk_root_writable' => (bool) $chunk_root_ok,
                ],
            ]
        );
    }

    public static function upload() {
        self::ensure_permission();
        Museder_Restoreone_UI::verify_ajax_request();
        // Additional nonce verification for plugin-check
        check_ajax_referer( Museder_Restoreone_UI::NONCE, 'nonce' );

        // Optimize runtime environment for large file processing
        self::optimize_runtime_environment();

        // Nonce verified above.
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() and check_ajax_referer() above
        $file = null;
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name used as server-side path only after is_uploaded_file()
        if ( isset( $_FILES['file'], $_FILES['file']['tmp_name'] ) && is_uploaded_file( $_FILES['file']['tmp_name'] ) ) {
            // 已用 isset() + is_uploaded_file() 驗證。這裡只會把 tmp_name 當作伺服器端暫存檔路徑使用，不會輸出到前端。
            $file = $_FILES['file'];
        } elseif ( isset( $_FILES['restoreFile'], $_FILES['restoreFile']['tmp_name'] ) && is_uploaded_file( $_FILES['restoreFile']['tmp_name'] ) ) {
            // 已用 isset() + is_uploaded_file() 驗證。這裡只會把 tmp_name 當作伺服器端暫存檔路徑使用，不會輸出到前端。
            $file = $_FILES['restoreFile'];
        } elseif ( isset( $_FILES['restore_file'], $_FILES['restore_file']['tmp_name'] ) && is_uploaded_file( $_FILES['restore_file']['tmp_name'] ) ) {
            // 已用 isset() + is_uploaded_file() 驗證。這裡只會把 tmp_name 當作伺服器端暫存檔路徑使用，不會輸出到前端。
            $file = $_FILES['restore_file'];
        }
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ( empty( $file ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'No restore file uploaded.', 'museder-restoreone' ) ], 400 );
        }

        if ( ! function_exists( 'wp_handle_upload' ) ) {
            $file_php = function_exists( 'museder_restoreone_get_core_admin_include_path' ) ? museder_restoreone_get_core_admin_include_path( 'file.php' ) : '';
            if ( '' !== $file_php ) {
                // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- vetted core path from helper.
                require_once $file_php;
            }
        }
        if ( ! function_exists( 'wp_handle_upload' ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'WordPress upload API is unavailable on this request.', 'museder-restoreone' ) ], 500 );
        }

        $overrides = [ 'test_form' => false ];
        $uploaded  = wp_handle_upload( $file, $overrides );

        if ( isset( $uploaded['error'] ) ) {
            museder_restoreone_log( 'error', 'restore_upload_failed', [ 'error' => $uploaded['error'] ] );
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to upload restore file.', 'museder-restoreone' ) ], 500 );
        }

        $file_path = wp_normalize_path( $uploaded['file'] );
        $ext       = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        if ( ! in_array( $ext, [ 'zip' ], true ) ) {
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            // $file_path is from wp_handle_upload() result, validated and sanitized
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $file_path );
            } else {
                // phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                // Unlinking temporary backup/restore artifact. WP_Filesystem is not practical here.
                @unlink( $file_path );
                // phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
            }
            wp_send_json_error( [ 'message' => esc_html__( 'Unsupported file type. Allowed: zip.', 'museder-restoreone' ) ], 415 );
        }

        $backup_dir = museder_restoreone_get_backup_dir();
        $unique     = wp_unique_filename( $backup_dir, basename( $file_path ) );
        $destination = trailingslashit( $backup_dir ) . $unique;

        if ( ! self::move_file( $file_path, $destination ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unable to store uploaded file for restore.', 'museder-restoreone' ) ], 500 );
        }

        // Check file size for large file handling
        $file_size = file_exists( $destination ) ? filesize( $destination ) : 0;
        $large_file_threshold = 500 * 1024 * 1024; // 500MB
        $very_large_file_threshold = 1000 * 1024 * 1024; // 1GB
        
        // Check if this is an All-in-One WP Migration backup and convert it
        // For very large files (>1GB), we skip automatic conversion to avoid timeouts
        // For large files (500MB-1GB), we attempt conversion with extended timeout
        // The restore process will attempt to handle the file directly if conversion is skipped
        require_once MUSEDER_RESTOREONE_PATH . 'includes/class-ai1wm-converter.php';
        
        $should_attempt_conversion = true;
        if ( $file_size > $very_large_file_threshold ) {
            museder_restoreone_log( 'info', 'Very large file detected, skipping automatic conversion to avoid timeout. Restore will attempt to handle file directly.', [
                'file' => basename( $destination ),
                'size' => size_format( $file_size, 2 ),
            ] );
            $should_attempt_conversion = false;
        } elseif ( $file_size > $large_file_threshold ) {
            museder_restoreone_log( 'info', 'Large file detected, conversion may take longer than usual.', [
                'file' => basename( $destination ),
                'size' => size_format( $file_size, 2 ),
            ] );
        }
        
        if ( $should_attempt_conversion ) {
            try {
                if ( class_exists( 'Museder_Restoreone_AI1WM_Converter' ) && Museder_Restoreone_AI1WM_Converter::is_ai1wm_backup( $destination ) ) {
                    museder_restoreone_log( 'info', 'Detected All-in-One WP Migration backup, converting to Museder RestoreOne format.', [
                        'file' => basename( $destination ),
                        'size' => size_format( $file_size, 2 ),
                    ] );
                    
                    // Allow longer execution time for large backup/restore jobs when possible.
                    // phpcs:ignore WordPress.PHP.NoSetTimeLimit
                    if ( function_exists( 'set_time_limit' ) ) {
                        // Long-running backup/restore job: attempt to raise time limit for CLI/cron.
                        // @phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged
                        if ( function_exists( 'set_time_limit' ) ) {
                            @set_time_limit( 600 ); // 10 minutes for conversion
                        }
                        // @phpcs:enable Squiz.PHP.DiscouragedFunctions.Discouraged
                    }
                    
                    $convert_result = Museder_Restoreone_AI1WM_Converter::convert( $destination );
                    
                    if ( ! empty( $convert_result['success'] ) && ! empty( $convert_result['file'] ) && file_exists( $convert_result['file'] ) ) {
                        // Delete original file and use converted file
                        if ( function_exists( 'wp_delete_file' ) ) {
                            wp_delete_file( $destination );
                        } else {
                            // phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                            // Unlinking temporary backup/restore artifact. WP_Filesystem is not practical here.
                            @unlink( $destination );
                            // phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
                        }
                        
                        $destination = $convert_result['file'];
                        museder_restoreone_log( 'info', 'Successfully converted All-in-One backup.', [
                            'converted_file' => basename( $destination ),
                        ] );
                    } else {
                        // Conversion failed, but we can still try to restore the original file
                        // Some All-in-One formats might be compatible even without conversion
                        museder_restoreone_log( 'warning', 'All-in-One conversion failed, attempting to restore original file.', [
                            'error' => isset( $convert_result['error'] ) ? $convert_result['error'] : 'unknown',
                            'message' => isset( $convert_result['message'] ) ? $convert_result['message'] : '',
                        ] );
                        
                        // For now, continue with original file
                        // The restore process might be able to handle some All-in-One formats directly
                    }
                }
            } catch ( Exception $e ) {
                // Log conversion error but continue with original file
                // @plugin-check: sanitized - exception message is for logging only, not user-facing
                museder_restoreone_log( 'error', 'Exception during All-in-One conversion, continuing with original file.', [
                    'error' => sanitize_text_field( $e->getMessage() ),
                    'trace' => sanitize_text_field( $e->getTraceAsString() ),
                ] );
            }
        }

        // Prepare session with error handling
        try {
            if ( ! file_exists( $destination ) ) {
                wp_send_json_error( [ 'message' => esc_html__( 'Backup file not found after processing.', 'museder-restoreone' ) ], 404 );
                return;
            }
            
            $summary = self::prepare_session( $destination, 'upload' );
            
            wp_send_json_success( [
                'summary' => $summary,
                'progress' => self::format_progress(),
            ] );
        } catch ( Exception $e ) {
            // @plugin-check: sanitized - exception message is for logging only, not user-facing
            museder_restoreone_log( 'error', 'Failed to prepare restore session after upload.', [
                'error' => sanitize_text_field( $e->getMessage() ),
                'file' => basename( $destination ),
                'trace' => sanitize_text_field( $e->getTraceAsString() ),
            ] );
            
            // @plugin-check: escaped - user-facing error message
            wp_send_json_error( [
                'message' => esc_html__( 'Failed to analyze backup file. Please check the logs for details.', 'museder-restoreone' ),
            ], 500 );
        }
    }

    public static function restore_from_backup() {
        self::ensure_permission();
        Museder_Restoreone_UI::verify_ajax_request();
        // Additional nonce verification for plugin-check
        check_ajax_referer( Museder_Restoreone_UI::NONCE, 'nonce' );

        // Nonce verified above
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() and check_ajax_referer() above
        $filename = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ( empty( $filename ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Backup filename not provided.', 'museder-restoreone' ) ], 400 );
        }

        // Use helper function to get absolute path from file name
        $path = museder_restoreone_get_backup_path( $filename );

        if ( ! $path ) {
            // Enhanced error logging with more context
            $backups_dir = museder_restoreone_get_backup_dir();
            $backup_files = [];
            
            // Try to list available backup files for debugging
            if ( is_dir( $backups_dir ) && is_readable( $backups_dir ) ) {
                $files = @glob( trailingslashit( $backups_dir ) . '*.zip' );
                if ( ! empty( $files ) ) {
                    $backup_files = array_map( 'basename', array_slice( $files, 0, 10 ) ); // Limit to first 10 for logging
                }
            }
            
            museder_restoreone_log( 'error', 'Restore archive not readable.', [
                'filename' => $filename,
                'backups_dir' => $backups_dir,
                'backups_dir_exists' => is_dir( $backups_dir ),
                'backups_dir_readable' => is_dir( $backups_dir ) ? is_readable( $backups_dir ) : false,
                'available_files_sample' => $backup_files,
            ] );
            
            // @plugin-check: escaped
            wp_send_json_error( [
                'message' => esc_html__( 'Backup file not found or unreadable.', 'museder-restoreone' ),
                'filename' => esc_html( $filename ),
            ], 404 );
        }

        // Check if this is an All-in-One WP Migration backup and convert it
        require_once MUSEDER_RESTOREONE_PATH . 'includes/class-ai1wm-converter.php';
        
        try {
            if ( class_exists( 'Museder_Restoreone_AI1WM_Converter' ) && Museder_Restoreone_AI1WM_Converter::is_ai1wm_backup( $path ) ) {
                museder_restoreone_log( 'info', 'Detected All-in-One WP Migration backup, converting to Museder RestoreOne format.', [
                    'file' => basename( $path ),
                ] );
                
                $convert_result = Museder_Restoreone_AI1WM_Converter::convert( $path );
                
                if ( ! empty( $convert_result['success'] ) && ! empty( $convert_result['file'] ) ) {
                    // Use helper to get absolute path - handles both full paths and filenames
                    $converted_file = museder_restoreone_get_backup_path( $convert_result['file'] );
                    
                    if ( $converted_file ) {
                        // Use converted file instead of original
                        $path = $converted_file;
                        museder_restoreone_log( 'info', 'Successfully converted All-in-One backup.', [
                            'converted_file' => basename( $path ),
                            'converted_path' => $path,
                        ] );
                    } else {
                        museder_restoreone_log( 'warning', 'Converted file not found or unreadable, using original file.', [
                            'converted_file' => $convert_result['file'],
                            'original_file' => basename( $path ),
                        ] );
                    }
                } else {
                    // Conversion failed, log warning but continue with original
                    museder_restoreone_log( 'warning', 'All-in-One conversion failed, attempting to restore original file.', [
                        'error' => isset( $convert_result['error'] ) ? $convert_result['error'] : 'unknown',
                    ] );
                }
            }
        } catch ( Exception $e ) {
            museder_restoreone_log( 'error', 'Exception during All-in-One conversion, continuing with original file.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ] );
        }

        // Prepare session with error handling
        try {
            if ( ! file_exists( $path ) ) {
                wp_send_json_error( [ 'message' => esc_html__( 'Backup file not found.', 'museder-restoreone' ) ], 404 );
                return;
            }
            
            $summary = self::prepare_session( $path, 'existing' );
            
            wp_send_json_success( [
                'summary'  => $summary,
                'progress' => self::format_progress(),
            ] );
        } catch ( Exception $e ) {
            // @plugin-check: sanitized - exception message is for logging only, not user-facing
            museder_restoreone_log( 'error', 'Failed to prepare restore session.', [
                'error' => sanitize_text_field( $e->getMessage() ),
                'file' => basename( $path ),
                'trace' => sanitize_text_field( $e->getTraceAsString() ),
            ] );
            
            // @plugin-check: escaped - user-facing error message
            wp_send_json_error( [
                'message' => esc_html__( 'Failed to analyze backup file. Please check the logs for details.', 'museder-restoreone' ),
            ], 500 );
        }
    }

    public static function restore_remote() {
        self::ensure_permission();
        Museder_Restoreone_UI::verify_ajax_request();
        check_ajax_referer( Museder_Restoreone_UI::NONCE, 'nonce' );

        wp_send_json_error(
            [ 'message' => esc_html__( 'Remote URL restore is unavailable in this build. Please upload a local archive or select one from Backups.', 'museder-restoreone' ) ],
            403
        );
    }

    public static function progress() {
        self::ensure_permission();
        Museder_Restoreone_UI::verify_ajax_request();
        // WordPress.org review: explicit nonce check in this handler body.
        check_ajax_referer( Museder_Restoreone_UI::NONCE, 'nonce' );

        $state = self::get_state();

        if ( empty( $state ) ) {
            // @plugin-check: escaped
            wp_send_json( self::format_progress( 0, esc_html__( 'Waiting for action…', 'museder-restoreone' ), true ) );
        }

        $progress = self::format_progress();
        wp_send_json( $progress );
    }

    public static function enqueue_restore_job() {
        self::ensure_permission();
        Museder_Restoreone_UI::verify_ajax_request();
        // WordPress.org review: explicit nonce check in this handler body.
        check_ajax_referer( Museder_Restoreone_UI::NONCE, 'nonce' );

        // Preflight: clear stale restore state so users are not blocked by crashed/aborted jobs.
        self::cleanup_stale_restore_state();

        // If a restore lock is held (and not cleared as stale), treat it as an active restore and block early
        // with a clear 409 instead of letting execute() throw and become a 500.
        if ( class_exists( 'Museder_Restoreone_Restore_Lock' ) ) {
            $lock = Museder_Restoreone_Restore_Lock::current_lock();
            if ( is_array( $lock ) && ! empty( $lock['job_id'] ) ) {
                $lock_job_id = sanitize_text_field( (string) $lock['job_id'] );
                if ( $lock_job_id ) {
                    try {
                        $st = class_exists( 'Museder_Restoreone_Restore_Service' ) ? Museder_Restoreone_Restore_Service::status( $lock_job_id ) : null;
                        $completed = is_array( $st ) && ! empty( $st['completed'] );
                        $stage = is_array( $st ) && isset( $st['stage'] ) ? (string) $st['stage'] : '';
                        if ( ! $completed && ! in_array( $stage, [ 'done', 'failed', 'cancelled' ], true ) ) {
                            wp_send_json_error(
                                // @plugin-check: escaped
                                [ 'message' => esc_html__( 'Another restore is already in progress. Please wait for it to finish.', 'museder-restoreone' ) ],
                                409
                            );
                        }
                    } catch ( Exception $e ) {
                        // If status lookup fails, cleanup_stale_restore_state() should have handled stale locks.
                        // Fall through and continue.
                    }
                }
            }
        }

        // AI1WM-style: use Restore_Service as the single restore engine.
        // Prevent starting a new job while another is active.
        $active_job_id = class_exists( 'Museder_Restoreone_Restore_Service' ) ? Museder_Restoreone_Restore_Service::get_active_job_id() : '';
        if ( ! empty( $active_job_id ) ) {
                wp_send_json_error(
                    // @plugin-check: escaped
                    [ 'message' => esc_html__( 'Another restore is already in progress. Please wait for it to finish.', 'museder-restoreone' ) ],
                    409
                );
        }

        $state = self::get_state();
        if ( empty( $state ) || empty( $state['file'] ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'No restore session is active.', 'museder-restoreone' ) ], 400 );
        }

        $options = self::parse_options();

        $file_path_preflight = isset( $state['file'] ) ? (string) $state['file'] : '';
        $abs_preflight       = $file_path_preflight ? museder_restoreone_get_backup_path( $file_path_preflight ) : '';
        if ( $abs_preflight && class_exists( 'Museder_Restoreone_Restore_Preflight' ) ) {
            $preflight = Museder_Restoreone_Restore_Preflight::preflight( $abs_preflight, $options );
            if ( ! empty( $preflight['blocked'] ) ) {
                wp_send_json_error(
                    [ 'message' => esc_html( (string) $preflight['message'] ) ],
                    400
                );
            }
            $options = $preflight['options'];
        }

        // Pass along the detected DB prefix info (Step 1) so Restore_Service can avoid prefix mismatch restores.
        $extra = ( isset( $state['extra'] ) && is_array( $state['extra'] ) ) ? $state['extra'] : [];
        if ( isset( $extra['db_prefix_source'] ) && is_string( $extra['db_prefix_source'] ) && '' !== $extra['db_prefix_source'] ) {
            $options['db_source_prefix'] = sanitize_text_field( $extra['db_prefix_source'] );
        }
        if ( isset( $extra['db_prefix_target'] ) && is_string( $extra['db_prefix_target'] ) && '' !== $extra['db_prefix_target'] ) {
            $options['db_target_prefix'] = sanitize_text_field( $extra['db_prefix_target'] );
        }
        $state['options'] = $options;
        self::set_state( $state );

        // If the user opted into a pre-restore snapshot, this request can be long-running on large sites.
        // Best-effort extend the time limit for this synchronous enqueue phase.
        if ( ! empty( $options['auto_backup'] ) ) {
            // phpcs:ignore WordPress.PHP.NoSetTimeLimit
            if ( function_exists( 'set_time_limit' ) ) {
                // @phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged
                @set_time_limit( 600 );
                // @phpcs:enable Squiz.PHP.DiscouragedFunctions.Discouraged
            }
        }

        // Get file size for progress estimation
        $file_path = isset( $state['file'] ) ? (string) $state['file'] : '';
        $file_size = 0;
        if ( $file_path ) {
            $abs = museder_restoreone_get_backup_path( $file_path );
            if ( $abs && file_exists( $abs ) ) {
                $file_size = filesize( $abs );
            }
        } elseif ( isset( $state['size'] ) ) {
            $file_size = (float) $state['size'];
        }

        // Start Restore_Service job (prepare -> validate -> execute).
        $job_id = '';
        try {
            $source = isset( $state['source'] ) ? (string) $state['source'] : 'existing';
            if ( 'remote' === $source ) {
                $source = 'upload';
            }
            $file_name = isset( $state['file'] ) ? (string) $state['file'] : '';
            $sha1      = isset( $state['sha1'] ) ? (string) $state['sha1'] : '';

            $prepared = Museder_Restoreone_Restore_Service::prepare( $source, $file_name, $sha1 );
            $job_id   = isset( $prepared['job_id'] ) ? (string) $prepared['job_id'] : '';
            if ( '' === $job_id ) {
                throw new RuntimeException( esc_html__( 'Unable to create restore job.', 'museder-restoreone' ) );
            }

            // Persist job id in state for polling recovery (nonce expiry / refresh).
            $state['restore_service_job_id'] = $job_id;
            self::set_state( $state );

            // Record a user-scoped pointer so wp-admin can show an unattended notice.
            if ( function_exists( 'get_current_user_id' ) ) {
                $uid = (int) get_current_user_id();
                if ( $uid > 0 ) {
                    update_user_meta( $uid, 'museder_restoreone_restore_notice_job_id', $job_id );
                    update_user_meta( $uid, 'museder_restoreone_restore_notice_dismissed', 0 );
                }
            }

            Museder_Restoreone_Restore_Service::validate( $job_id );
            $exec = Museder_Restoreone_Restore_Service::execute( $job_id, $options );
            // status() can throw if job metadata is missing/corrupted; treat as a failure but cleanup pointers.
            $status = Museder_Restoreone_Restore_Service::status( $job_id );

        $restore_token = '';
            if ( is_array( $exec ) && ! empty( $exec['restore_token'] ) ) {
                $restore_token = (string) $exec['restore_token'];
            }

        wp_send_json_success(
            [
                    'job'           => self::map_restore_service_status_to_job( $job_id, $status ),
                    'progress'      => self::format_progress( 10, __( 'Restore started. Monitoring progress…', 'museder-restoreone' ), false ),
                    'history'       => self::history_for_js( 10 ),
                    'file_size'     => $file_size,
                    'exec'          => $exec,
                    'restore_token' => $restore_token,
                ]
            );
        } catch ( Exception $e ) {
            museder_restoreone_log( 'error', 'restore_service_enqueue_failed', [
                'error' => sanitize_text_field( $e->getMessage() ),
            ] );

            // Best-effort cleanup: if we partially started a job, don't leave stale pointers/locks behind.
            if ( ! empty( $job_id ) ) {
                try {
                    if ( class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
                        $active = Museder_Restoreone_Restore_Service::get_active_job_id();
                        if ( $active === $job_id ) {
                            delete_option( Museder_Restoreone_Restore_Service::ACTIVE_JOB_OPTION );
                        }
                    }
                } catch ( Exception $inner ) {
                    // Ignore.
                }
                try {
                    if ( class_exists( 'Museder_Restoreone_Restore_Lock' ) ) {
                        $lock = Museder_Restoreone_Restore_Lock::current_lock();
                        if ( empty( $lock['job_id'] ) || $lock['job_id'] === $job_id ) {
                            Museder_Restoreone_Restore_Lock::release();
                        }
                    }
                } catch ( Exception $inner ) {
                    // Ignore.
                }
            }

            // Prefer a safe, actionable message for common validation/runtime failures.
            $message = esc_html__( 'Failed to start restore job. Please check logs for details.', 'museder-restoreone' );
            if ( $e instanceof RuntimeException || $e instanceof InvalidArgumentException ) {
                $maybe = sanitize_text_field( (string) $e->getMessage() );
                if ( '' !== $maybe ) {
                    $message = $maybe;
                }
            }
            wp_send_json_error(
                [
                    // @plugin-check: escaped
                    'message' => $message,
                ],
                500
            );
        }
    }

    /**
     * Best-effort cleanup of stale restore state (active job pointer / lock) so users can start a new restore.
     * Only removes state when the referenced job metadata is missing/corrupted, or the job is already completed.
     */
    private static function cleanup_stale_restore_state() {
        if ( ! class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
            return;
        }

        $active_job_id = Museder_Restoreone_Restore_Service::get_active_job_id();
        $lock = class_exists( 'Museder_Restoreone_Restore_Lock' ) ? Museder_Restoreone_Restore_Lock::current_lock() : null;
        $lock_job_id = ( is_array( $lock ) && ! empty( $lock['job_id'] ) ) ? (string) $lock['job_id'] : '';

        // Helper: determine if a job id points to an active (running) job.
        $is_active_job_running = static function( $job_id ) {
            try {
                $st = Museder_Restoreone_Restore_Service::status( $job_id );
                $stage = isset( $st['stage'] ) ? (string) $st['stage'] : '';
                $completed = ! empty( $st['completed'] );
                if ( $completed ) {
                    return false;
                }
                // Treat common terminal stages as not running even if completed flag is missing.
                if ( in_array( $stage, [ 'done', 'failed', 'cancelled' ], true ) ) {
                    return false;
                }
                return true;
            } catch ( Exception $e ) {
                // Job meta may exist on canonical path while status() cannot read yet (upload_path drift).
                if ( class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
                    $path = Museder_Restoreone_Restore_Service::locate_job_meta_path( $job_id );
                    if ( '' !== $path ) {
                        return true;
                    }
                }
                return false;
            }
        };

        // If we have an active job pointer but it is stale, clear it (and matching lock if present).
        if ( ! empty( $active_job_id ) && ! $is_active_job_running( $active_job_id ) ) {
            museder_restoreone_log( 'warning', 'Detected stale active restore job pointer; clearing.', [
                'active_job_id' => $active_job_id,
                'lock_job_id'   => $lock_job_id,
            ] );

            delete_option( Museder_Restoreone_Restore_Service::ACTIVE_JOB_OPTION );

            if ( $lock_job_id && $lock_job_id === $active_job_id && class_exists( 'Museder_Restoreone_Restore_Lock' ) ) {
                Museder_Restoreone_Restore_Lock::release();
            }

            // Re-fetch lock after potential release.
            $lock = class_exists( 'Museder_Restoreone_Restore_Lock' ) ? Museder_Restoreone_Restore_Lock::current_lock() : null;
            $lock_job_id = ( is_array( $lock ) && ! empty( $lock['job_id'] ) ) ? (string) $lock['job_id'] : '';
        }

        // If lock exists but no active job pointer, validate lock job id; release if stale/completed.
        if ( empty( $active_job_id ) && ! empty( $lock_job_id ) ) {
            if ( ! $is_active_job_running( $lock_job_id ) ) {
                museder_restoreone_log( 'warning', 'Detected stale restore lock; releasing.', [
                    'lock_job_id' => $lock_job_id,
                ] );
                if ( class_exists( 'Museder_Restoreone_Restore_Lock' ) ) {
                    Museder_Restoreone_Restore_Lock::release();
                }
            } else {
                // A job is running but pointer is missing; keep lock intact and prevent new restore.
                // Restore page will show \"another restore is already in progress\" based on lock/option checks.
                museder_restoreone_log( 'info', 'Restore lock is held by an active job; not clearing.', [
                    'lock_job_id' => $lock_job_id,
                ] );
            }
        }
    }

    /**
     * Return restore job status for admin UI polling.
     *
     * @wp_ajax museder_restoreone_restore_job_status
     * @wp_ajax_nopriv museder_restoreone_restore_job_status Token-only when logged out.
     */
    public static function job_status() {
        // Ensure clean output for JSON response (flush current buffer only; do not pop the stack).
        if ( ob_get_level() ) {
            @ob_clean();
        }

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in verify_restore_progress_request()
        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        Museder_Restoreone_UI::verify_restore_progress_request( $job_id );
        if ( empty( $job_id ) ) {
            // No job id: fall back to Restore_Service active job id or return history only.
            $active_job_id = class_exists( 'Museder_Restoreone_Restore_Service' ) ? Museder_Restoreone_Restore_Service::get_active_job_id() : '';
            if ( ! empty( $active_job_id ) ) {
                $job_id = sanitize_text_field( (string) $active_job_id );
            } else {
                wp_send_json_success( [
                    'job'     => null,
                    'history' => self::history_for_js( 10 ),
                    // @plugin-check: escaped
                    'message' => esc_html__( 'No active restore job found. Check history for recent restores.', 'museder-restoreone' ),
                ] );
                return;
            }
        }

        // If no job_id was provided, try to recover from restore state (Restore_Service job id).
        if ( empty( $job_id ) ) {
            $state = self::get_state();
            if ( ! empty( $state['restore_service_job_id'] ) ) {
                $job_id = sanitize_text_field( (string) $state['restore_service_job_id'] );
            }
        }

        if ( empty( $job_id ) ) {
            wp_send_json_success( [
                'job'     => null,
                'history' => self::history_for_js( 10 ),
                // @plugin-check: escaped
                'message' => esc_html__( 'No active restore job found. Check history for recent restores.', 'museder-restoreone' ),
            ] );
            return;
        }

        try {
            $status = Museder_Restoreone_Restore_Service::status( $job_id );
            $job    = self::map_restore_service_status_to_job( $job_id, $status );

            if ( ! empty( $status['completed'] ) ) {
                $active = (string) get_option( Museder_Restoreone_Restore_Service::ACTIVE_JOB_OPTION, '' );
                if ( $active === $job_id ) {
                    delete_option( Museder_Restoreone_Restore_Service::ACTIVE_JOB_OPTION );
                }
            } elseif ( class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
                $active = (string) get_option( Museder_Restoreone_Restore_Service::ACTIVE_JOB_OPTION, '' );
                if ( '' === $active ) {
                    update_option( Museder_Restoreone_Restore_Service::ACTIVE_JOB_OPTION, $job_id, false );
                }
            }
        } catch ( Exception $e ) {
            if ( class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
                Museder_Restoreone_Restore_Service::sync_job_meta_paths_after_db_import( $job_id );
                try {
                    $status = Museder_Restoreone_Restore_Service::status( $job_id );
                    $job    = self::map_restore_service_status_to_job( $job_id, $status );
                    update_option( Museder_Restoreone_Restore_Service::ACTIVE_JOB_OPTION, $job_id, false );
                } catch ( Exception $inner ) {
                    unset( $inner );
                    $job = null;
                }
                if ( null !== $job ) {
                    $safe_mode_active = ( get_option( 'museder_restoreone_safe_mode', '' ) === '1' );
                    $prev_plugins_count = 0;
                    if ( $safe_mode_active ) {
                        $prev_plugins = get_option( 'museder_restoreone_prev_active_plugins', [] );
                        $prev_plugins_count = is_array( $prev_plugins ) ? count( $prev_plugins ) : 0;
                    }
                    wp_send_json_success(
                        [
                            'job'                => $job,
                            'history'            => self::history_for_js( 10 ),
                            'safe_mode_active'   => (bool) $safe_mode_active,
                            'prev_plugins_count' => (int) $prev_plugins_count,
                        ]
                    );
                    return;
                }
            }

            wp_send_json_success( [
                'job'     => null,
                'history' => self::history_for_js( 10 ),
                // @plugin-check: escaped
                'message' => esc_html__( 'Restore job not found. Check history for recent restores.', 'museder-restoreone' ),
            ] );
            return;
        }

        // Safe mode status (best-effort) for UI hints.
        $safe_mode_active = ( get_option( 'museder_restoreone_safe_mode', '' ) === '1' );
        $prev_plugins_count = 0;
        if ( $safe_mode_active ) {
            $prev_plugins = get_option( 'museder_restoreone_prev_active_plugins', [] );
            $prev_plugins_count = is_array( $prev_plugins ) ? count( $prev_plugins ) : 0;
        }

        wp_send_json_success(
            [
                'job'     => $job,
                'history' => self::history_for_js( 10 ),
                'safe_mode_active' => (bool) $safe_mode_active,
                'prev_plugins_count' => (int) $prev_plugins_count,
            ]
        );
    }

    public static function trigger_restore_job() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified via token or nonce below
        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        Museder_Restoreone_UI::verify_restore_progress_request( $job_id );
        if ( empty( $job_id ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Job identifier is required.', 'museder-restoreone' ) ], 400 );
        }

        // Restore_Service jobs are scheduled immediately in execute(). Trigger WP-Cron as a best-effort nudge.
        if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
            $cron_url = site_url( 'wp-cron.php?doing_wp_cron' );
            wp_remote_post(
                $cron_url,
                [
                    'timeout'  => 0.01,
                    'blocking' => false,
                ]
            );
        }

        wp_send_json_success(
            [
                'message' => __( 'Restore job triggered.', 'museder-restoreone' ),
            ]
        );
    }

    /**
     * Push the restore job forward by executing a single time slice via admin-ajax.
     *
     * This is a fallback for environments where WP-Cron loopback is unreliable.
     *
     * @wp_ajax museder_restoreone_restore_tick
     * @wp_ajax_nopriv museder_restoreone_restore_tick Token-only when logged out.
     */
    public static function restore_tick() {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in verify_restore_progress_request()
        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        $slice  = isset( $_POST['slice'] ) ? absint( wp_unslash( $_POST['slice'] ) ) : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        Museder_Restoreone_UI::verify_restore_progress_request( $job_id );

        if ( empty( $job_id ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Job identifier is required.', 'museder-restoreone' ) ], 400 );
        }

        if ( ! class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Restore service is not available.', 'museder-restoreone' ) ], 500 );
        }

        $slice = ( $slice > 0 ) ? min( 15, max( 3, $slice ) ) : 8;

        try {
            $meta = Museder_Restoreone_Restore_Service::get_job_meta( $job_id );
            if ( ! empty( $meta['completed'] ) ) {
                $status = Museder_Restoreone_Restore_Service::status( $job_id );
                wp_send_json_success(
                    [
                        'job' => self::map_restore_service_status_to_job( $job_id, $status ),
                    ]
                );
            }

            // Ensure lock belongs to this job (or acquire if no lock exists).
            if ( class_exists( 'Museder_Restoreone_Restore_Lock' ) ) {
                $lock = Museder_Restoreone_Restore_Lock::current_lock();
                if ( $lock && isset( $lock['job_id'] ) && (string) $lock['job_id'] !== $job_id ) {
                    wp_send_json_error(
                        [
                            // @plugin-check: escaped
                            'message' => esc_html__( 'Cannot tick: another restore job is currently running.', 'museder-restoreone' ),
                            'job_id'  => (string) $lock['job_id'],
                        ],
                        409
                    );
                }
                if ( ! $lock ) {
                    $acquired = Museder_Restoreone_Restore_Lock::acquire( $job_id );
                    if ( ! $acquired ) {
                        wp_send_json_error(
                            [
                                // @plugin-check: escaped
                                'message' => esc_html__( 'Cannot tick: restore lock is busy. Please try again.', 'museder-restoreone' ),
                            ],
                            409
                        );
                    }
                }
                Museder_Restoreone_Restore_Lock::refresh( $job_id );
            }

            // Best-effort: ensure active job pointer is set for this job.
            $active = (string) get_option( Museder_Restoreone_Restore_Service::ACTIVE_JOB_OPTION, '' );
            if ( '' === $active ) {
                update_option( Museder_Restoreone_Restore_Service::ACTIVE_JOB_OPTION, $job_id, false );
            }

            $deadline   = time() + $slice;
            $last_result = [ 'ok' => true ];
            while ( time() < $deadline ) {
                $remaining = max( 1, $deadline - time() );
                $last_result = Museder_Restoreone_Restore_Service::process_job_slice( $job_id, $remaining, false, 'ajax' );
                if ( empty( $last_result['ok'] ) && ! empty( $last_result['reason'] ) && 'busy' === $last_result['reason'] ) {
                    break;
                }
                $meta_tick = Museder_Restoreone_Restore_Service::get_job_meta( $job_id );
                if ( ! empty( $meta_tick['completed'] ) ) {
                    break;
                }
            }

            if ( empty( $last_result['ok'] ) && ! empty( $last_result['reason'] ) && 'busy' === $last_result['reason'] ) {
                wp_send_json_error(
                    [
                        // @plugin-check: escaped
                        'message' => esc_html__( 'Restore is busy. Please wait a moment and try again.', 'museder-restoreone' ),
                    ],
                    409
                );
            }

            $status = Museder_Restoreone_Restore_Service::status( $job_id );
            wp_send_json_success(
                [
                    'job' => self::map_restore_service_status_to_job( $job_id, $status ),
                ]
            );
        } catch ( Exception $e ) {
            museder_restoreone_log( 'error', 'Restore tick failed.', [ 'job_id' => $job_id, 'error' => $e->getMessage() ] );
            wp_send_json_error(
                [
                    // @plugin-check: escaped
                    'message' => esc_html__( 'Restore tick failed. Please check logs.', 'museder-restoreone' ),
                ],
                500
            );
        }
    }

    public static function job_cancel() {
        // Ensure clean output for JSON response (flush current buffer only; do not pop the stack).
        if ( ob_get_level() ) {
            @ob_clean();
        }

        self::ensure_permission();
        Museder_Restoreone_UI::verify_ajax_request();
        // Additional nonce verification for plugin-check
        check_ajax_referer( Museder_Restoreone_UI::NONCE, 'nonce' );

        // Nonce verified above
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() and check_ajax_referer() above
        $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ( empty( $job_id ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Job identifier is required.', 'museder-restoreone' ) ], 400 );
            return;
        }

        try {
            Museder_Restoreone_Restore_Service::cancel( $job_id );
            
            // Cleanup state, but don't let errors break the response
            try {
                self::cleanup_state_after_cancel();
            } catch ( Exception $e ) {
                museder_restoreone_log( 'warning', 'Error during cleanup after cancel.', [ 'error' => $e->getMessage() ] );
            }

            wp_send_json_success(
                [
                    // @plugin-check: escaped
                    'message' => esc_html__( 'Cancellation requested. You can safely close this page.', 'museder-restoreone' ),
                    'job'     => [
                        'id'       => $job_id,
                        'status'   => 'cancelled',
                        'progress' => 100,
                        'message'  => esc_html__( 'Restore cancelled.', 'museder-restoreone' ),
                    ],
                ]
            );
        } catch ( Exception $e ) {
            museder_restoreone_log( 'error', 'Error cancelling restore job.', [ 'job_id' => $job_id, 'error' => $e->getMessage() ] );
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to cancel restore job. Please try again.', 'museder-restoreone' ) ], 500 );
        }
    }

    public static function confirm() {
        self::enqueue_restore_job();
    }

    public static function cancel_restore() {
        // Ensure clean output for JSON response (flush current buffer only; do not pop the stack).
        if ( ob_get_level() ) {
            @ob_clean();
        }

        self::ensure_permission();
        Museder_Restoreone_UI::verify_ajax_request();
        // Additional nonce verification for plugin-check
        check_ajax_referer( Museder_Restoreone_UI::NONCE, 'nonce' );

        // Nonce verified above
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() and check_ajax_referer() above
        try {
            $job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
            if ( empty( $job_id ) ) {
                $state = self::get_state();
                if ( ! empty( $state['restore_service_job_id'] ) ) {
                    $job_id = sanitize_text_field( (string) $state['restore_service_job_id'] );
                }
            }

            if ( empty( $job_id ) && class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
                $job_id = sanitize_text_field( (string) Museder_Restoreone_Restore_Service::get_active_job_id() );
            }

            if ( $job_id ) {
                Museder_Restoreone_Restore_Service::cancel( $job_id );
            }

            // Cleanup state, but don't let errors break the response
            try {
                self::cleanup_state_after_cancel();
            } catch ( Exception $e ) {
                museder_restoreone_log( 'warning', 'Error during cleanup after cancel.', [ 'error' => $e->getMessage() ] );
            }

            wp_send_json_success( [
                // @plugin-check: escaped
                'message'  => esc_html__( 'Restore process cancelled.', 'museder-restoreone' ),
                'progress' => self::format_progress( 0, esc_html__( 'Waiting for action…', 'museder-restoreone' ), false ),
            ] );
        } catch ( Exception $e ) {
            museder_restoreone_log( 'error', 'Error cancelling restore.', [ 'error' => $e->getMessage() ] );
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to cancel restore. Please try again.', 'museder-restoreone' ) ], 500 );
        }
    }

    public static function run_job( $job_id, $job ) {
        // Legacy restore-jobs runner is disabled (clean break). The only supported restore engine
        // is Museder_Restoreone_Restore_Service (resumable, time-sliced pipeline).
            return [
                'success' => false,
            'message' => __( 'Legacy restore jobs are disabled. Please start restore from Restore Center.', 'museder-restoreone' ),
            'error'   => 'legacy_restore_jobs_disabled',
        ];
    }

    private static function parse_options() {
        $options = [];

        // Nonce verified in calling function (enqueue_restore_job) via verify_ajax_request()
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in calling function
        $overwrite_value = '';
        if ( isset( $_POST['overwrite'] ) ) {
            $overwrite_value = sanitize_text_field( wp_unslash( $_POST['overwrite'] ) );
        }
        // @plugin-check: sanitized
        $options['overwrite'] = ! empty( $overwrite_value ) && 'true' === $overwrite_value;

        $auto_backup_value = '';
        if ( isset( $_POST['autoBackup'] ) ) {
            $auto_backup_value = sanitize_text_field( wp_unslash( $_POST['autoBackup'] ) );
        }
        // @plugin-check: sanitized
        $options['auto_backup'] = ! empty( $auto_backup_value ) && 'true' === $auto_backup_value;

        $wp_config_mode = '';
        if ( isset( $_POST['wpConfigMode'] ) ) {
            $wp_config_mode = sanitize_key( wp_unslash( $_POST['wpConfigMode'] ) );
        } elseif ( isset( $_POST['restoreWpConfig'] ) ) {
            $restore_wp_config_raw = sanitize_text_field( wp_unslash( $_POST['restoreWpConfig'] ) );
            $wp_config_mode = ( 'true' === $restore_wp_config_raw || '1' === $restore_wp_config_raw )
                ? Museder_Restoreone_Restore_Preflight::MODE_CONFIG_BACKUP
                : Museder_Restoreone_Restore_Preflight::MODE_CONFIG_KEEP;
        } elseif ( isset( $_POST['skipConfig'] ) ) {
            $skip_config_value = sanitize_text_field( wp_unslash( $_POST['skipConfig'] ) );
            $wp_config_mode = ( ! empty( $skip_config_value ) && 'true' === $skip_config_value )
                ? Museder_Restoreone_Restore_Preflight::MODE_CONFIG_KEEP
                : Museder_Restoreone_Restore_Preflight::MODE_CONFIG_BACKUP;
        }
        if ( ! in_array( $wp_config_mode, [ Museder_Restoreone_Restore_Preflight::MODE_CONFIG_BACKUP, Museder_Restoreone_Restore_Preflight::MODE_CONFIG_KEEP, Museder_Restoreone_Restore_Preflight::MODE_CONFIG_MERGE ], true ) ) {
            $wp_config_mode = Museder_Restoreone_Restore_Preflight::MODE_CONFIG_BACKUP;
        }
        $options['wp_config_mode'] = $wp_config_mode;
        $options['skip_config']  = ( Museder_Restoreone_Restore_Preflight::MODE_CONFIG_KEEP === $wp_config_mode );

        $restore_order = '';
        if ( isset( $_POST['restoreOrder'] ) ) {
            $restore_order = sanitize_key( wp_unslash( $_POST['restoreOrder'] ) );
        }
        if ( in_array( $restore_order, [ Museder_Restoreone_Restore_Preflight::ORDER_DB_THEN_FILES, Museder_Restoreone_Restore_Preflight::ORDER_FILES_THEN_DB ], true ) ) {
            $options['restore_order'] = $restore_order;
        }

        $pause_plugins_value = '';
        if ( isset( $_POST['pauseOtherPlugins'] ) ) {
            $pause_plugins_value = sanitize_text_field( wp_unslash( $_POST['pauseOtherPlugins'] ) );
        }
        if ( '' === $pause_plugins_value ) {
            $options['pause_other_plugins'] = true;
        } else {
            $options['pause_other_plugins'] = ( 'true' === $pause_plugins_value || '1' === $pause_plugins_value );
        }

        $restore_scope = '';
        if ( isset( $_POST['restoreScope'] ) ) {
            $restore_scope = sanitize_key( wp_unslash( $_POST['restoreScope'] ) );
        }
        if ( in_array( $restore_scope, [ Museder_Restoreone_Restore_Preflight::SCOPE_FULL, Museder_Restoreone_Restore_Preflight::SCOPE_CONTENT, Museder_Restoreone_Restore_Preflight::SCOPE_DB_ONLY ], true ) ) {
            $options['restore_scope'] = $restore_scope;
        }

        // Safe mode (default: enabled). When enabled, RestoreOne records active plugins and sets a marker for admin review (no automatic plugin toggling).
        $safe_mode_value = '';
        if ( isset( $_POST['safeMode'] ) ) {
            $safe_mode_value = sanitize_text_field( wp_unslash( $_POST['safeMode'] ) );
        }
        // @plugin-check: sanitized
        if ( '' === $safe_mode_value ) {
            $options['safe_mode'] = true;
        } else {
            $options['safe_mode'] = ( 'true' === $safe_mode_value || '1' === $safe_mode_value || 'yes' === strtolower( $safe_mode_value ) );
        }

        $search_replace_raw = '';
        if ( isset( $_POST['searchReplace'] ) ) {
            $search_replace_raw = sanitize_text_field( wp_unslash( $_POST['searchReplace'] ) );
        }
        // @plugin-check: validated - JSON will be decoded and sanitized
        if ( ! empty( $search_replace_raw ) ) {
            $decoded = json_decode( $search_replace_raw, true );
            if ( is_array( $decoded ) ) {
                // Sanitize all string values in the array recursively
                $options['search_replace'] = array_map( function( $item ) {
                    if ( is_array( $item ) ) {
                        return array_map( 'sanitize_text_field', $item );
                    }
                    return sanitize_text_field( $item );
                }, $decoded );
            } else {
                $options['search_replace'] = [];
            }
        } else {
            $options['search_replace'] = [];
        }

        // Files-only restore: allow proceeding when database payload is missing or manual-only.
        $files_only_value = '';
        if ( isset( $_POST['filesOnly'] ) ) {
            $files_only_value = sanitize_text_field( wp_unslash( $_POST['filesOnly'] ) );
        }
        // @plugin-check: sanitized
        $options['files_only'] = ( 'true' === $files_only_value || '1' === $files_only_value || 'yes' === strtolower( $files_only_value ) );
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        return $options;
    }

    /**
     * Best-effort detect if a ZIP archive contains a database payload, by basename scan.
     *
     * @param string $file_path Absolute path to backup archive.
     * @return array{present:bool,type:string} type is "ndjson"|"sql"|"".
     */
    private static function detect_db_payload_from_archive( $file_path ) {
        $file_path = wp_normalize_path( (string) $file_path );
        if ( '' === $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            return [ 'present' => false, 'type' => '' ];
        }
        $ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        if ( 'zip' !== $ext ) {
            return [ 'present' => false, 'type' => '' ];
        }
        // Preferred: ZipArchive listing (fast + accurate) when available.
        if ( class_exists( 'ZipArchive' ) ) {
            $zip = new ZipArchive();
            $ok  = $zip->open( $file_path );
            $ok_code = defined( 'ZipArchive::ER_OK' ) ? ZipArchive::ER_OK : 0;
            if ( true === $ok || $ok_code === $ok ) {
                $has_ndjson = false;
                $has_sql    = false;
                for ( $i = 0; $i < (int) $zip->numFiles; $i++ ) {
                    $name = (string) $zip->getNameIndex( $i );
                    if ( '' === $name ) {
                        continue;
                    }
                    $base = strtolower( basename( $name ) );
                    if ( 'database.ndjson' === $base ) {
                        $has_ndjson = true;
                        break;
                    }
                    if ( 'database.sql' === $base ) {
                        $has_sql = true;
                    }
                }
                $zip->close();
                if ( $has_ndjson ) {
                    return [ 'present' => true, 'type' => 'ndjson' ];
                }
                if ( $has_sql ) {
                    return [ 'present' => true, 'type' => 'sql' ];
                }
                return [ 'present' => false, 'type' => '' ];
            }
        }

        // Fallback: Some hosts cannot open large ZIPs with ZipArchive (e.g. ZipArchive error 19).
        // As a lightweight heuristic, scan the tail of the file for the filenames in the central directory.
        $size = (int) filesize( $file_path );
        if ( $size <= 0 ) {
            return [ 'present' => false, 'type' => '' ];
        }
        $tail = min( 2 * 1024 * 1024, $size ); // last 2MB is enough for central directory names in most cases
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- controlled read of plugin-owned backup file
        $fh = @fopen( $file_path, 'rb' );
        if ( ! $fh ) {
            return [ 'present' => false, 'type' => '' ];
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fseek -- controlled seek
        @fseek( $fh, -$tail, SEEK_END );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- controlled read
        $buf = @fread( $fh, $tail );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- cleanup
        @fclose( $fh );
        if ( ! is_string( $buf ) || '' === $buf ) {
            return [ 'present' => false, 'type' => '' ];
        }
        $lower = strtolower( $buf );
        if ( false !== strpos( $lower, 'database.ndjson' ) ) {
            return [ 'present' => true, 'type' => 'ndjson' ];
        }
        if ( false !== strpos( $lower, 'database.sql' ) ) {
            return [ 'present' => true, 'type' => 'sql' ];
        }
        return [ 'present' => false, 'type' => '' ];
    }

    public static function chunk_prepare() {
        self::ensure_permission();
        Museder_Restoreone_UI::verify_ajax_request();
        // WordPress.org review: explicit nonce check in this handler body.
        check_ajax_referer( Museder_Restoreone_UI::NONCE, 'nonce' );

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request()
        $filename = '';
        if ( isset( $_POST['filename'] ) ) {
            $filename = sanitize_file_name( wp_unslash( $_POST['filename'] ) );
        }
        // @plugin-check: sanitized

        $filesize = 0;
        if ( isset( $_POST['filesize'] ) ) {
            $filesize = absint( wp_unslash( $_POST['filesize'] ) );
        }
        // @plugin-check: validated

        $chunk_size = 0;
        if ( isset( $_POST['chunk_size'] ) ) {
            $chunk_size = absint( wp_unslash( $_POST['chunk_size'] ) );
        }
        // @plugin-check: validated

        $total_chunks = 0;
        if ( isset( $_POST['total_chunks'] ) ) {
            $total_chunks = absint( wp_unslash( $_POST['total_chunks'] ) );
        }
        // @plugin-check: validated
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( ! $filename || ! $filesize || ! $chunk_size || ! $total_chunks ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Missing chunk upload metadata.', 'museder-restoreone' ) ], 400 );
        }

        $session_id = uniqid( 'restore_chunk_', true );
        $session_dir = self::chunk_session_dir( $session_id );

        if ( ! wp_mkdir_p( $session_dir ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unable to create chunk session directory.', 'museder-restoreone' ) ], 500 );
        }

        // Create a unique final destination file in the backups directory up-front, so chunks can be written
        // directly into it (no per-chunk files, no merge pass).
        $backup_dir = museder_restoreone_get_backup_dir();
        $final_name = wp_unique_filename( $backup_dir, $filename );
        $final_path = trailingslashit( $backup_dir ) . $final_name;

        // Touch/create the destination file to validate permissions early.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- restore upload stream requires direct fopen, path is plugin-controlled backups dir
        $touch = fopen( $final_path, 'wb' );
        if ( ! $touch ) {
            self::delete_chunk_session( $session_id );
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unable to create destination file for upload.', 'museder-restoreone' ) ], 500 );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen
        fclose( $touch );

        $meta = [
            'session_id'   => $session_id,
            'filename'     => $filename,
            'filesize'     => $filesize,
            'chunk_size'   => $chunk_size,
            'total_chunks' => $total_chunks,
            'created'      => time(),
            'final_name'   => $final_name,
            'final_path'   => $final_path,
            'received'     => [],
        ];

        if ( false === file_put_contents( self::chunk_meta_path( $session_id ), wp_json_encode( $meta ), LOCK_EX ) ) {
            self::delete_chunk_session( $session_id );
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unable to persist chunk session metadata.', 'museder-restoreone' ) ], 500 );
        }

        wp_send_json_success( [
            'session_id' => $session_id,
        ] );
    }

    public static function chunk_upload() {
        self::ensure_permission();
        Museder_Restoreone_UI::verify_ajax_request();
        // Additional nonce verification for plugin-check / reviewer tooling (explicit in this handler).
        check_ajax_referer( Museder_Restoreone_UI::NONCE, 'nonce' );

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request()
        $session_id = '';
        if ( isset( $_POST['session_id'] ) ) {
            $session_id = sanitize_text_field( wp_unslash( $_POST['session_id'] ) );
        }
        // @plugin-check: sanitized

        $index = -1;
        if ( isset( $_POST['chunk_index'] ) ) {
            $index = absint( wp_unslash( $_POST['chunk_index'] ) );
        }
        // @plugin-check: validated
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( ! $session_id || $index < 0 ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid chunk upload parameters.', 'museder-restoreone' ) ], 400 );
        }

        $meta = self::load_chunk_meta( $session_id );
        if ( ! $meta ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Chunk session not found.', 'museder-restoreone' ) ], 404 );
        }
        $total_chunks = isset( $meta['total_chunks'] ) ? (int) $meta['total_chunks'] : 0;
        if ( $total_chunks > 0 && $index >= $total_chunks ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid chunk index.', 'museder-restoreone' ) ], 400 );
        }

        // 此方法透過 verify_ajax_request() 已完成 nonce 驗證與權限檢查。
        // tmp_name 僅作為伺服器端暫存檔路徑使用，且經 is_uploaded_file() 驗證。
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request()
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name used as server-side path only after is_uploaded_file()
        if ( ! isset( $_FILES['chunk']['tmp_name'] ) || ! is_uploaded_file( $_FILES['chunk']['tmp_name'] ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid chunk file upload.', 'museder-restoreone' ) ], 400 );
        }
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        // Direct-write mode: write chunk bytes into the final destination file at a known offset.
        $final_path = isset( $meta['final_path'] ) ? (string) $meta['final_path'] : '';
        $chunk_size = isset( $meta['chunk_size'] ) ? (int) $meta['chunk_size'] : 0;
        if ( empty( $final_path ) || $chunk_size <= 0 ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Chunk session metadata is invalid.', 'museder-restoreone' ) ], 500 );
        }
        
        // 已用 isset() + is_uploaded_file() 驗證。這裡只會把 tmp_name 當作伺服器端暫存檔路徑使用，不會輸出到前端。
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request()
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name used as server-side path only after is_uploaded_file()
        $tmp_name = $_FILES['chunk']['tmp_name'];
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        // Use stream_copy_to_stream instead of move_uploaded_file to avoid WordPress Plugin Check warning.
        // $final_path is in plugin-controlled backups dir, $tmp_name is verified via is_uploaded_file().
        if ( empty( $tmp_name ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unable to store uploaded chunk.', 'museder-restoreone' ) ], 500 );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- direct fopen is required for large backup streaming, paths are validated by our helper.
        $input  = fopen( $tmp_name, 'rb' );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- direct fopen is required for large backup streaming, destination is plugin-controlled backups dir
        $output = fopen( $final_path, 'c+b' );
        if ( ! $input || ! $output ) {
            if ( $input ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
                fclose( $input );
            }
            if ( $output ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
                fclose( $output );
            }
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unable to store uploaded chunk.', 'museder-restoreone' ) ], 500 );
        }

        // Lock the output file while writing this chunk (prevents concurrent writes from interleaving).
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- required for coordinating concurrent chunk writes
        @flock( $output, LOCK_EX );

        $offset = (int) $index * (int) $chunk_size;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- required for random access chunk writes
        $seek_ok = ( false !== fseek( $output, $offset, SEEK_SET ) );
        if ( ! $seek_ok ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- release lock
            @flock( $output, LOCK_UN );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
            fclose( $input );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
            fclose( $output );
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unable to write uploaded chunk at the correct offset.', 'museder-restoreone' ) ], 500 );
        }

        $copied = stream_copy_to_stream( $input, $output );
        fflush( $output );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- release lock
        @flock( $output, LOCK_UN );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
        fclose( $input );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
        fclose( $output );

        if ( false === $copied ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unable to store uploaded chunk.', 'museder-restoreone' ) ], 500 );
        }

        // Mark chunk as received in meta (resumable upload + finalize validation).
        self::mark_chunk_received( $session_id, $index );

        wp_send_json_success( [
            'chunk' => $index,
            'total' => $total_chunks,
        ] );
    }

    public static function chunk_finalize() {
        self::ensure_permission();
        Museder_Restoreone_UI::verify_ajax_request();
        // WordPress.org review: explicit nonce check in this handler body.
        check_ajax_referer( Museder_Restoreone_UI::NONCE, 'nonce' );

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request()
        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( ! $session_id ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Missing chunk session identifier.', 'museder-restoreone' ) ], 400 );
        }

        $meta = self::load_chunk_meta( $session_id );
        if ( ! $meta ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Chunk session not found.', 'museder-restoreone' ) ], 404 );
        }

        $chunk_dir    = self::chunk_session_dir( $session_id );
        $total_chunks = (int) $meta['total_chunks'];
        $final_path = isset( $meta['final_path'] ) ? (string) $meta['final_path'] : '';
        if ( empty( $final_path ) ) {
                self::delete_chunk_session( $session_id );
                // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Chunk session metadata is invalid.', 'museder-restoreone' ) ], 500 );
        }

        // Validate received chunks from meta instead of scanning per-chunk files.
        $received = isset( $meta['received'] ) && is_array( $meta['received'] ) ? array_map( 'absint', $meta['received'] ) : [];
        $received = array_values( array_unique( $received ) );
        sort( $received );

        $missing = [];
        for ( $i = 0; $i < $total_chunks; $i++ ) {
            if ( ! in_array( $i, $received, true ) ) {
                $missing[] = $i;
                if ( count( $missing ) > 10 ) {
                    break;
                }
            }
        }
        if ( ! empty( $missing ) ) {
            self::delete_chunk_session( $session_id );
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Uploaded chunks incomplete. Please retry.', 'museder-restoreone' ) ], 409 );
        }

        // Best-effort verify final size matches expected.
        $expected_size = isset( $meta['filesize'] ) ? (int) $meta['filesize'] : 0;
        if ( $expected_size > 0 && file_exists( $final_path ) ) {
            $actual_size = (int) filesize( $final_path );
            if ( $actual_size !== $expected_size ) {
                self::delete_chunk_session( $session_id );
                // @plugin-check: escaped
                wp_send_json_error( [ 'message' => esc_html__( 'Merged backup file size mismatch. Please retry.', 'museder-restoreone' ) ], 409 );
            }
        }

        // Cleanup only the session folder; preserve the final uploaded file.
        self::delete_chunk_session( $session_id, true );

        // Optimize runtime environment before preparing session
        self::optimize_runtime_environment();
        
        // Prepare session with error handling
        try {
            if ( ! file_exists( $final_path ) || ! is_readable( $final_path ) ) {
                // @plugin-check: escaped
                wp_send_json_error( [ 'message' => esc_html__( 'Merged backup file not found or unreadable.', 'museder-restoreone' ) ], 404 );
                return;
            }
            
            $summary = self::prepare_session( $final_path, 'upload' );

            wp_send_json_success( [
                'summary'  => $summary,
                'progress' => self::format_progress(),
            ] );
        } catch ( Exception $e ) {
            // @plugin-check: sanitized - exception message is for logging only, not user-facing
            museder_restoreone_log( 'error', 'Failed to prepare restore session after chunk finalize.', [
                'error' => sanitize_text_field( $e->getMessage() ),
                'file' => basename( $final_path ),
                'trace' => sanitize_text_field( $e->getTraceAsString() ),
            ] );
            
            // @plugin-check: escaped - user-facing error message
            wp_send_json_error( [
                'message' => esc_html__( 'Failed to analyze merged backup file. Please check the logs for details.', 'museder-restoreone' ),
            ], 500 );
        }
    }

    public static function chunk_abort() {
        self::ensure_permission();
        Museder_Restoreone_UI::verify_ajax_request();
        // WordPress.org review: explicit nonce check in this handler body.
        check_ajax_referer( Museder_Restoreone_UI::NONCE, 'nonce' );

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request()
        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ( $session_id ) {
            self::delete_chunk_session( $session_id );
        }

        wp_send_json_success();
    }

    /**
     * Return current chunk session status for resumable uploads.
     *
     * @wp_ajax museder_restoreone_restore_chunk_status
     */
    public static function chunk_status() {
        self::ensure_permission();
        Museder_Restoreone_UI::verify_ajax_request();
        // Additional nonce verification for plugin-check / reviewer tooling (explicit in this handler).
        check_ajax_referer( Museder_Restoreone_UI::NONCE, 'nonce' );

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request()
        $session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ( ! $session_id ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Missing chunk session identifier.', 'museder-restoreone' ) ], 400 );
        }

        $meta = self::load_chunk_meta( $session_id );
        if ( ! $meta ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Chunk session not found.', 'museder-restoreone' ) ], 404 );
        }

        $received = isset( $meta['received'] ) && is_array( $meta['received'] ) ? array_map( 'absint', $meta['received'] ) : [];
        $received = array_values( array_unique( $received ) );
        sort( $received );

        wp_send_json_success(
            [
                'session_id'   => $session_id,
                'total_chunks' => isset( $meta['total_chunks'] ) ? (int) $meta['total_chunks'] : 0,
                'chunk_size'   => isset( $meta['chunk_size'] ) ? (int) $meta['chunk_size'] : 0,
                'filesize'     => isset( $meta['filesize'] ) ? (int) $meta['filesize'] : 0,
                'received'     => $received,
            ]
        );
    }

    private static function chunk_root_dir() {
        $root = museder_restoreone_get_storage_root();
        $dir  = trailingslashit( $root['path'] ) . 'restore-chunks';
        museder_restoreone_ensure_directory( $dir );
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

    private static function delete_chunk_session( $session_id, $preserve_final_file = false ) {
        // Best-effort cleanup of the final destination file on abort/incomplete sessions.
        if ( ! $preserve_final_file ) {
            $meta = self::load_chunk_meta( $session_id );
            $final_path = ( $meta && isset( $meta['final_path'] ) ) ? (string) $meta['final_path'] : '';
            if ( $final_path && file_exists( $final_path ) && is_file( $final_path ) ) {
                // phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                if ( function_exists( 'wp_delete_file' ) ) {
                    wp_delete_file( $final_path );
                } else {
                    // @phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    @unlink( $final_path );
                }
                // phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
            }
        }

        $dir = self::chunk_session_dir( $session_id );
        if ( file_exists( $dir ) ) {
            museder_restoreone_delete_directory( $dir );
        }
    }

    /**
     * Mark a chunk index as received in meta.json using a file lock (safe for concurrent uploads).
     */
    private static function mark_chunk_received( $session_id, $index ) {
        $path = self::chunk_meta_path( $session_id );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- required for locking+atomic update
        $fp = fopen( $path, 'c+' );
        if ( ! $fp ) {
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- required for coordinating concurrent updates
        @flock( $fp, LOCK_EX );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- required for reading from start
        fseek( $fp, 0 );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- required for reading JSON metadata
        $raw = stream_get_contents( $fp );
        $meta = json_decode( (string) $raw, true );
        if ( ! is_array( $meta ) ) {
            $meta = [];
        }
        $received = isset( $meta['received'] ) && is_array( $meta['received'] ) ? array_map( 'absint', $meta['received'] ) : [];
        $received[] = (int) $index;
        $received = array_values( array_unique( $received ) );
        sort( $received );
        $meta['received'] = $received;
        $encoded = wp_json_encode( $meta );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftruncate -- required for atomic update
        ftruncate( $fp, 0 );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- required for writing from start
        fseek( $fp, 0 );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- required for atomic update
        fwrite( $fp, $encoded );
        fflush( $fp );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- release lock
        @flock( $fp, LOCK_UN );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- cleanup
        fclose( $fp );
    }

    public static function prepare_session( $file_path, $source, $extra = [] ) {
        $file_path = wp_normalize_path( $file_path );
        
        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            throw new RuntimeException( esc_html__( 'Backup file not found or unreadable.', 'museder-restoreone' ) );
        }
        
        $size = filesize( $file_path );
        
        // For large files (>500MB), skip SHA1 calculation to avoid timeout
        // SHA1 will be calculated during restore if needed
        $large_file_threshold = 500 * 1024 * 1024; // 500MB
        $sha1 = '';
        
        if ( $size <= $large_file_threshold ) {
            // Calculate SHA1 for smaller files
            $sha1 = sha1_file( $file_path );
        } else {
            museder_restoreone_log( 'info', 'Large file detected, skipping SHA1 calculation to avoid timeout.', [
                'file' => basename( $file_path ),
                'size' => size_format( $size, 2 ),
            ] );
        }

        // Best-effort detect the database table prefix used inside the backup.
        // This helps diagnose/avoid "files restored but content missing" cases caused by prefix mismatch.
        $db_prefix_source = self::detect_db_prefix_from_archive( $file_path );
        global $wpdb;
        $db_prefix_target = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';

        if ( function_exists( 'museder_restoreone_log' ) ) {
            museder_restoreone_log( 'info', 'Restore prepare: detected DB prefixes.', [
                'file'            => basename( $file_path ),
                'source_prefix'   => (string) $db_prefix_source,
                'target_prefix'   => (string) $db_prefix_target,
            ] );
        }

        // Store only filename in state, not full path
        // Full path will be resolved when needed using museder_restoreone_get_backup_path()
        $file_name = basename( $file_path );
        
        $state = [
            'id'        => uniqid( 'restore_', true ),
            'file'      => $file_name, // Store only filename, not full path
            'filename'  => $file_name,
            'source'    => $source,
            'size'      => (float) $size,
            'sha1'      => $sha1,
            'created'   => current_time( 'mysql' ),
            'extra'     => array_merge(
                is_array( $extra ) ? $extra : [],
                [
                    'db_prefix_source' => (string) $db_prefix_source,
                    'db_prefix_target' => (string) $db_prefix_target,
                ]
            ),
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

    /**
     * Format restore history entries for frontend display.
     * 
     * All timestamps are stored as UTC Unix timestamps internally.
     * Display times are converted to site's local timezone using museder_restoreone_format_local_time().
     * 
     * @param int $limit Maximum number of entries to return.
     * @return array Formatted history entries with display_time and timestamp_utc.
     */
    public static function history_for_js( $limit = 10 ) {
        $raw      = museder_restoreone_get_restore_history( $limit );
        $prepared = [];

        foreach ( $raw as $row ) {
            // 1. Get UTC timestamp (support both new and old data formats)
            $timestamp_utc = 0;
            
            // Priority 1: Use timestamp_utc if available (most accurate, stored as UTC Unix timestamp)
            if ( isset( $row['timestamp_utc'] ) && (int) $row['timestamp_utc'] > 0 ) {
                $timestamp_utc = (int) $row['timestamp_utc'];
            } elseif ( ! empty( $row['date'] ) ) {
                // Priority 2: Old data compatibility - parse date string as UTC
                // The 'date' field is stored as UTC datetime string (gmdate format)
                // MySQL datetime string (no timezone), treat as UTC base
                $timestamp_utc = strtotime( $row['date'] . ' UTC' );
                if ( false === $timestamp_utc || $timestamp_utc <= 0 ) {
                    // Fallback: try parsing as-is (may be old local time entry)
                    $timestamp_utc = strtotime( $row['date'] );
                }
            } elseif ( isset( $row['timestamp'] ) && $row['timestamp'] ) {
                // Priority 3: Parse legacy timestamp using helper function
                $timestamp_utc = museder_restoreone_parse_legacy_timestamp( $row['timestamp'] );
            }
            
            // Fallback: If still no valid timestamp, use current time or file modification time
            if ( $timestamp_utc <= 0 ) {
                // Try to get file modification time as last resort
                if ( ! empty( $row['file'] ) ) {
                    $backup_path = museder_restoreone_get_backup_path( $row['file'] );
                    if ( $backup_path && file_exists( $backup_path ) ) {
                        $timestamp_utc = filemtime( $backup_path );
                    }
                }
                // If still no timestamp, use current_time with GMT flag (UTC)
                if ( $timestamp_utc <= 0 ) {
                    $timestamp_utc = current_time( 'timestamp', true );
                }
            }
            
            // 2. Get file name (pure filename, no path)
            $file_name = isset( $row['file'] ) ? basename( $row['file'] ) : '';
            
            // 3. Get result
            $result = isset( $row['result'] ) ? (string) $row['result'] : '';
            
            // 4. Get duration in seconds (use -1 if no data).
            // Note: allow 0 seconds (very fast restores) and compute from started/completed timestamps when available.
            $duration = -1;
            if ( isset( $row['duration_seconds'] ) && is_numeric( $row['duration_seconds'] ) && (int) $row['duration_seconds'] >= 0 ) {
                $duration = (int) $row['duration_seconds'];
            } elseif ( isset( $row['restore_duration_seconds'] ) && is_numeric( $row['restore_duration_seconds'] ) && (int) $row['restore_duration_seconds'] >= 0 ) {
                $duration = (int) $row['restore_duration_seconds'];
            } elseif (
                isset( $row['restore_started_at'], $row['restore_completed_at'] )
                && is_numeric( $row['restore_started_at'] )
                && is_numeric( $row['restore_completed_at'] )
            ) {
                $started   = (int) $row['restore_started_at'];
                $completed = (int) $row['restore_completed_at'];
                if ( $started > 0 && $completed >= $started ) {
                    $duration = $completed - $started;
                }
            }
            
            // 5. Human-readable time (local timezone)
            $date_human = $timestamp_utc > 0
                ? museder_restoreone_format_local_time( $timestamp_utc, 'Y-m-d H:i' )
                : '';
            
            // 6. Human-readable duration (0 seconds is valid)
            $duration_human = $duration >= 0
                ? museder_restoreone_format_duration( $duration )
                : '';
            
            // 7. Log download URL (if available)
            $log_download_url = '';
            if ( ! empty( $row['log'] ) ) {
                $log_download_url = wp_nonce_url(
                    admin_url( 'admin-post.php?action=museder_restoreone_download_log&log=' . rawurlencode( $row['log'] ) ),
                    'museder_restoreone_download_log_' . $row['log']
                );
            } elseif ( ! empty( $row['log_file'] ) ) {
                // Fallback: try log_file field
                $log_download_url = wp_nonce_url(
                    admin_url( 'admin-post.php?action=museder_restoreone_download_log&log=' . rawurlencode( $row['log_file'] ) ),
                    'museder_restoreone_download_log_' . $row['log_file']
                );
            }
            
            // 8. Get ID (prefer actual id, fallback to timestamp_utc)
            $id = isset( $row['id'] ) && (int) $row['id'] > 0 ? (int) $row['id'] : $timestamp_utc;
            
            // 9. Build clean array for template (no nested arrays, no foreach on strings, no 'undefined' strings)
            $prepared[] = [
                'id'               => $id,
                'file'             => $file_name,
                'result'           => $result,
                'timestamp_utc'   => $timestamp_utc,
                'duration'        => $duration, // Use -1 if no data
                'date_human'      => $date_human,
                'duration_human'   => $duration_human,
                'log_download_url'=> $log_download_url,
            ];
        }

        return $prepared;
    }

    private static function ensure_permission() {
        if ( ! current_user_can( 'manage_options' ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized.', 'museder-restoreone' ) ], 403 );
        }
    }

    private static function move_file( $source, $destination ) {
        // This plugin needs low-level rename() here for streaming backup/restore performance.
        // Using WP_Filesystem::move() is not always reliable across all hosting environments.
        // @phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename
        $renamed = @rename( $source, $destination );
        // @phpcs:enable WordPress.WP.AlternativeFunctions.rename_rename
        if ( $renamed ) {
            return true;
        }

        // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
        // $source and $destination are from plugin-controlled directories
        if ( @copy( $source, $destination ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- required for file move operation, paths from plugin-controlled directories
            // phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
            // Unlinking temporary backup/restore artifact. WP_Filesystem is not practical here.
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $source );
            } else {
                // Fallback for non-standard environments.
                if ( file_exists( $source ) ) {
                    @unlink( $source );
                }
            }
            // phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
            // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
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

    public static function format_progress( $percent = null, $message = null, $done = null ) {
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
        // $state['file'] now stores only filename, not full path
        // Use museder_restoreone_get_backup_path() to resolve full path when needed
        $file_name = isset( $state['file'] ) ? $state['file'] : '';
        $path = museder_restoreone_get_backup_path( $file_name );
        
        // Use size from state if available (already calculated in prepare_session)
        $size = ( ! empty( $state['size'] ) ) ? (float) $state['size'] : 0;
        
        // Fallback: if size not in state and file exists, get it from file
        if ( $size <= 0 && $path && file_exists( $path ) ) {
            $size = filesize( $path );
        }
        
        // Use SHA1 from state if available, otherwise skip for large files
        $sha1 = '';
        if ( ! empty( $state['sha1'] ) ) {
            $sha1 = $state['sha1'];
        } elseif ( $path && file_exists( $path ) && $size > 0 && $size <= ( 500 * 1024 * 1024 ) ) {
            // Only calculate SHA1 for files under 500MB
            $sha1 = sha1_file( $path );
        }

        $extra = ( isset( $state['extra'] ) && is_array( $state['extra'] ) ) ? $state['extra'] : [];
        $db_prefix_source = isset( $extra['db_prefix_source'] ) ? (string) $extra['db_prefix_source'] : '';
        $db_prefix_target = isset( $extra['db_prefix_target'] ) ? (string) $extra['db_prefix_target'] : '';

        // DB payload hint for UI: detect whether archive contains database.ndjson or database.sql.
        $db_hint = [ 'present' => false, 'type' => '' ];
        if ( $path && file_exists( $path ) ) {
            $db_hint = self::detect_db_payload_from_archive( $path );
        }

        $preflight_hints = [];
        if ( $path && file_exists( $path ) && class_exists( 'Museder_Restoreone_Restore_Preflight' ) ) {
            $preflight_hints = Museder_Restoreone_Restore_Preflight::hints_for_summary( $path, [] );
        }

        return array_merge(
            [
                'name'    => isset( $state['filename'] ) ? $state['filename'] : ( $file_name ? basename( $file_name ) : '' ),
                'size'    => size_format( $size, 2 ),
                'bytes'   => (float) $size,
                'sha1'    => $sha1,
                'source'  => isset( $state['source'] ) ? $state['source'] : '',
                'created' => isset( $state['created'] ) ? $state['created'] : '',
                'db_prefix_source' => $db_prefix_source,
                'db_prefix_target' => $db_prefix_target,
                'db_present'       => (bool) $db_hint['present'],
                'db_type'          => (string) $db_hint['type'],
            ],
            $preflight_hints
        );
    }

    /**
     * Best-effort detect the table prefix used in a backup's database.sql.
     *
     * Returns something like "qvj4_" or "wp_2_" (including trailing underscore), or '' if unknown.
     *
     * @param string $file_path Absolute path to backup archive.
     * @return string
     */
    private static function detect_db_prefix_from_archive( $file_path ) {
        $file_path = wp_normalize_path( (string) $file_path );
        if ( '' === $file_path || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            return '';
        }

        $ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        if ( 'zip' !== $ext ) {
            // WPRESS detection is handled later during restore-db extraction (best-effort).
            return '';
        }

        if ( ! class_exists( 'ZipArchive' ) ) {
            return '';
        }

        $zip = new ZipArchive();
        $ok  = $zip->open( $file_path );
        $ok_code = defined( 'ZipArchive::ER_OK' ) ? ZipArchive::ER_OK : 0;
        if ( true !== $ok && $ok_code !== $ok ) {
            return '';
        }

        $entry_name = '';
        for ( $i = 0; $i < (int) $zip->numFiles; $i++ ) {
            $name = (string) $zip->getNameIndex( $i );
            if ( '' === $name ) {
                continue;
            }
            if ( 'database.sql' === strtolower( basename( $name ) ) ) {
                $entry_name = $name;
                break;
            }
        }

        if ( '' === $entry_name ) {
            $zip->close();
            return '';
        }

        $stream = $zip->getStream( $entry_name );
        if ( ! $stream ) {
            $zip->close();
            return '';
        }

        // Avoid false prefix detection caused by plugin tables early in the dump (e.g. schema_type_options,
        // qvj4_masterslider_options). Scan the first N MB from the ZipArchive stream and score candidates
        // by WP core table matches. Require options + another high-signal table before accepting.
        $max_scan_bytes = 40 * 1024 * 1024; // 40MB
        $chunk_bytes    = 1024 * 1024;      // 1MB
        $buffer_keep    = 2 * 1024 * 1024;  // keep last 2MB

        $weights = [
            'options'            => 5,
            'posts'              => 4,
            'postmeta'           => 4,
            'users'              => 4,
            'usermeta'           => 4,
            'terms'              => 2,
            'term_taxonomy'      => 2,
            'term_relationships' => 2,
            'comments'           => 1,
            'commentmeta'        => 1,
            'links'              => 1,
        ];

        $candidates = []; // prefix => ['score'=>int,'core'=>[table=>true],'first_seen'=>int]
        $scanned    = 0;
        $buffer     = '';

        try {
            while ( $scanned < $max_scan_bytes ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- reading from ZipArchive stream
                $chunk = fread( $stream, $chunk_bytes );
                if ( ! is_string( $chunk ) || '' === $chunk ) {
                    break;
                }
                $scanned += strlen( $chunk );
                $buffer  .= $chunk;

                if ( strlen( $buffer ) > $buffer_keep ) {
                    $buffer = substr( $buffer, -$buffer_keep );
                }

                if ( preg_match_all( '/\\b(?:CREATE TABLE(?: IF NOT EXISTS)?|DROP TABLE IF EXISTS|INSERT INTO|ALTER TABLE)\\s+`([^`]+)`/i', $buffer, $ms ) ) {
                    foreach ( (array) $ms[1] as $table ) {
                        $table = (string) $table;
                        if ( '' === $table ) {
                            continue;
                        }

                        if ( preg_match( '/^([A-Za-z0-9_]+_)(options|posts|postmeta|users|usermeta|terms|term_taxonomy|term_relationships|comments|commentmeta|links)$/i', $table, $m2 ) ) {
                            $prefix = (string) $m2[1];
                            $core   = strtolower( (string) $m2[2] );
                            $w      = isset( $weights[ $core ] ) ? (int) $weights[ $core ] : 0;

                            if ( ! isset( $candidates[ $prefix ] ) ) {
                                $candidates[ $prefix ] = [
                                    'score'      => 0,
                                    'core'       => [],
                                    'first_seen' => (int) $scanned,
                                ];
                            }

                            if ( empty( $candidates[ $prefix ]['core'][ $core ] ) ) {
                                $candidates[ $prefix ]['core'][ $core ] = true;
                                $candidates[ $prefix ]['score']        += $w;
                            }
                        }
                    }
                }

                // Early exit only when we see a plausible WP prefix:
                // must include `options` and at least one other high-signal core table.
                foreach ( $candidates as $cand ) {
                    $core = isset( $cand['core'] ) && is_array( $cand['core'] ) ? $cand['core'] : [];
                    if ( ! empty( $core['options'] ) && ( ! empty( $core['posts'] ) || ! empty( $core['users'] ) || ! empty( $core['postmeta'] ) || ! empty( $core['usermeta'] ) ) ) {
                        break 2;
                    }
                }
            }
        } finally {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing ZipArchive stream
            fclose( $stream );
            $zip->close();
        }

        if ( empty( $candidates ) ) {
            return '';
        }

        $best_prefix = '';
        $best_score  = -1;
        $best_distinct = -1;
        $best_first_seen = PHP_INT_MAX;

        foreach ( $candidates as $prefix => $cand ) {
            $score    = isset( $cand['score'] ) ? (int) $cand['score'] : 0;
            $distinct = isset( $cand['core'] ) && is_array( $cand['core'] ) ? count( $cand['core'] ) : 0;
            $first    = isset( $cand['first_seen'] ) ? (int) $cand['first_seen'] : PHP_INT_MAX;

            // Require `options` and at least one other core table; prevents plugin tables like *_masterslider_options.
            $core = isset( $cand['core'] ) && is_array( $cand['core'] ) ? $cand['core'] : [];
            if ( empty( $core['options'] ) || ( empty( $core['posts'] ) && empty( $core['users'] ) && empty( $core['postmeta'] ) && empty( $core['usermeta'] ) ) ) {
                continue;
            }

            if (
                $score > $best_score
                || ( $score === $best_score && $distinct > $best_distinct )
                || ( $score === $best_score && $distinct === $best_distinct && $first < $best_first_seen )
            ) {
                $best_prefix     = (string) $prefix;
                $best_score      = $score;
                $best_distinct   = $distinct;
                $best_first_seen = $first;
            }
        }

        return $best_prefix;
    }

    private static function report_job_progress( $job_id, $percent, $message, $done = false, $status = null ) {
        // If done is true or percent is 100, ensure status is set appropriately
        // But only if status is not explicitly provided (to allow for failed status)
        if ( null === $status && $done && $percent >= 100 ) {
            // When done is true and percent is 100, set status to 'success' by default
            // This ensures the frontend can detect completion even if handle_job hasn't finished
            $status = 'success';
            $percent = 100;
            museder_restoreone_log( 'info', 'Job progress: done=true, setting status to success', [
                'job_id' => $job_id,
                'percent' => $percent,
                'message' => $message,
            ] );
        }
        
        // If status is explicitly provided (e.g., 'failed'), use it
        if ( null !== $status ) {
            museder_restoreone_log( 'info', 'Job progress: status explicitly set', [
                'job_id' => $job_id,
                'percent' => $percent,
                'status' => $status,
                'message' => $message,
            ] );
        }
        
        self::update_progress( $percent, $message, $done );
    }

    private static function job_should_abort( $job_id ) {
        // Restore_Service cancellation is cooperative via meta stage; legacy cancel flag is not used.
        return false;
    }

    private static function handle_job_cancelled( $job_id, array $history_entry ) {
        // Update timestamps and duration when restore is cancelled
        $restore_completed_at = time();
        $restore_duration_seconds = 0;
        if ( isset( $history_entry['restore_started_at'] ) && is_numeric( $history_entry['restore_started_at'] ) ) {
            $restore_duration_seconds = max( 0, $restore_completed_at - (int) $history_entry['restore_started_at'] );
        }

        $history_entry['timestamp_utc']            = $restore_completed_at;
        $history_entry['date']                     = gmdate( 'Y-m-d H:i:s', $history_entry['timestamp_utc'] );
        $history_entry['result']                   = 'cancelled';
        $history_entry['restore_completed_at']      = $restore_completed_at;
        $history_entry['restore_duration_seconds']  = $restore_duration_seconds;
        museder_restoreone_append_restore_history( $history_entry );
        self::report_job_progress( $job_id, 100, __( 'Restore cancelled.', 'museder-restoreone' ), true );
        self::cleanup_state_after_cancel();

        return [
            'success'     => false,
            'status'      => 'cancelled',
            'message'     => __( 'Restore cancelled.', 'museder-restoreone' ),
            'history_log' => isset( $history_entry['log'] ) ? $history_entry['log'] : '',
        ];
    }

    /**
     * Map Restore_Service status to the legacy job object shape used by admin.js.
     *
     * @param string $job_id
     * @param array  $status
     * @return array
     */
    private static function map_restore_service_status_to_job( $job_id, array $status ) {
        $stage     = isset( $status['stage'] ) ? (string) $status['stage'] : '';
        $completed = ! empty( $status['completed'] );
        $progress  = isset( $status['progress'] ) ? (float) $status['progress'] : 0;
        $message   = isset( $status['message'] ) ? (string) $status['message'] : '';
        $last_tick = isset( $status['last_tick'] ) ? (int) $status['last_tick'] : 0;
        $started_at = isset( $status['started_at'] ) ? (int) $status['started_at'] : 0;
        $updated_at = isset( $status['updated_at'] ) ? (string) $status['updated_at'] : '';

        $job_status = 'running';
        if ( $completed ) {
            if ( 'done' === $stage || 'rollback-done' === $stage ) {
                $job_status = 'success';
            } elseif ( 'cancelled' === $stage ) {
                $job_status = 'cancelled';
            } else {
                $job_status = 'failed';
            }
        }

        return [
            'id'       => $job_id,
            'status'   => $job_status,
            'progress' => $progress,
            'message'  => $message,
            'stage'    => $stage,
            'last_tick' => $last_tick,
            'started_at_raw' => $started_at,
            'updated_at' => $updated_at,
        ];
    }

    private static function cleanup_state_after_cancel() {
        $state = self::get_state();

        if ( ! empty( $state['file'] ) && isset( $state['source'] ) && in_array( $state['source'], [ 'upload', 'remote' ], true ) ) {
            // $state['file'] now stores only filename, not full path
            // Use museder_restoreone_get_backup_path() to resolve full path
            $file_name = $state['file'];
            $path = museder_restoreone_get_backup_path( $file_name );
            
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            // $path is from plugin state, validated and sanitized via museder_restoreone_get_backup_path()
            if ( $path && file_exists( $path ) && is_file( $path ) ) {
                // 備份／還原流程中必須確保能刪除暫存檔案，以釋放磁碟空間並避免堆積 temp 檔案。
                // 優先使用 wp_delete_file()，若不可用則使用 PHP unlink() 作為後備。
                // phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                if ( function_exists( 'wp_delete_file' ) ) {
                    wp_delete_file( $path );
                } else {
                    // 備份／還原流程中必須確保能刪除暫存檔案，使用 PHP unlink() 作為後備。
                    // @phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                    @unlink( $path );
                }
                // phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
            }
        }

        self::set_state( [] );
    }

    /**
     * Optimize runtime environment for large file processing
     */
    private static function optimize_runtime_environment() {
        static $optimized = false;

        if ( $optimized ) {
            return;
        }

        if ( function_exists( 'ignore_user_abort' ) ) {
            @ignore_user_abort( true );
        }

        // Allow longer execution time for large backup/restore jobs when possible.
        if ( function_exists( 'set_time_limit' ) ) {
            // phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged,WordPress.PHP.DevelopmentFunctions.time_limit_set_time_limit
            @set_time_limit( 0 );
            // phpcs:enable Squiz.PHP.DiscouragedFunctions.Discouraged,WordPress.PHP.DevelopmentFunctions.time_limit_set_time_limit
        }

        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            @wp_raise_memory_limit( 'admin' );
        }

        $optimized = true;
    }

    /**
     * AJAX handler: filter active_plugins to drop plugins with missing main files or required dependencies.
     *
     * @wp_ajax museder_restoreone_reapply_safe_plugins
     */
    public static function reapply_safe_plugins() {
        self::ensure_permission();
        Museder_Restoreone_UI::verify_ajax_request();
        check_ajax_referer( Museder_Restoreone_UI::NONCE, 'nonce' );

        if ( ! class_exists( 'Museder_Restoreone_Restore_Service' ) ) {
            wp_send_json_error(
                [
                    'message' => __( 'Restore service is not available.', 'museder-restoreone' ),
                ],
                500
            );
        }

        $job_id = '';
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above.
        if ( isset( $_POST['job_id'] ) ) {
            $job_id = sanitize_text_field( wp_unslash( $_POST['job_id'] ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $result = Museder_Restoreone_Restore_Service::reapply_safe_active_plugins_after_restore( $job_id );

        wp_send_json_success(
            [
                'message'        => __( 'Active plugins list sanitized for restore safety.', 'museder-restoreone' ),
                'changed'        => ! empty( $result['changed'] ),
                'plugins_count'  => isset( $result['plugins_count'] ) ? (int) $result['plugins_count'] : 0,
                'skipped'        => isset( $result['skipped'] ) && is_array( $result['skipped'] ) ? $result['skipped'] : [],
            ]
        );
    }

    /**
     * AJAX handler to exit safe mode (clear marker and stored snapshot).
     */
    public static function exit_safe_mode() {
        self::ensure_permission();
        Museder_Restoreone_UI::verify_ajax_request();
        // WordPress.org review: explicit nonce check in this handler body.
        check_ajax_referer( Museder_Restoreone_UI::NONCE, 'nonce' );

        try {
            $result = Museder_Restoreone_Restore::exit_safe_mode();
            
            if ( $result ) {
                wp_send_json_success( [
                    'message' => __( 'Safe mode exited successfully.', 'museder-restoreone' ),
                ] );
            } else {
                wp_send_json_error( [
                    'message' => __( 'Safe mode is not active or could not be exited.', 'museder-restoreone' ),
                ], 400 );
            }
        } catch ( Exception $e ) {
            museder_restoreone_log( 'error', 'Failed to exit safe mode via AJAX.', [
                'error' => $e->getMessage(),
            ] );
            wp_send_json_error( [
                'message' => __( 'Failed to exit safe mode. Please check logs.', 'museder-restoreone' ),
            ], 500 );
        }
    }

    // Note: We intentionally do not modify other plugins' activation status (active_plugins option).
    // WordPress.org policy requires activation/deactivation to be performed by the user.
}
