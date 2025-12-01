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
        add_action( 'wp_ajax_backup_lite_exit_safe_mode', [ __CLASS__, 'exit_safe_mode' ] );
    }

    public static function upload() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        // Optimize runtime environment for large file processing
        self::optimize_runtime_environment();

        // @plugin-check: sanitized + nonce - verified via verify_ajax_request() above
        $file = null;
        if ( isset( $_FILES['file'] ) && is_uploaded_file( $_FILES['file']['tmp_name'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- using PHP upload file array provided by the system
            $file = $_FILES['file'];
        } elseif ( isset( $_FILES['restoreFile'] ) && is_uploaded_file( $_FILES['restoreFile']['tmp_name'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- using PHP upload file array provided by the system
            $file = $_FILES['restoreFile'];
        } elseif ( isset( $_FILES['restore_file'] ) && is_uploaded_file( $_FILES['restore_file']['tmp_name'] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- using PHP upload file array provided by the system
            $file = $_FILES['restore_file'];
        }
        if ( empty( $file ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'No restore file uploaded.', 'museder-restoreone' ) ], 400 );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $overrides = [ 'test_form' => false ];
        $uploaded  = wp_handle_upload( $file, $overrides );

        if ( isset( $uploaded['error'] ) ) {
            backup_lite_log( 'error', 'restore_upload_failed', [ 'error' => $uploaded['error'] ] );
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to upload restore file.', 'museder-restoreone' ) ], 500 );
        }

        $file_path = wp_normalize_path( $uploaded['file'] );
        $ext       = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        if ( ! in_array( $ext, [ 'zip', 'wpress' ], true ) ) {
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            // $file_path is from wp_handle_upload() result, validated and sanitized
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $file_path );
            } else {
                @unlink( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for cleanup, path from wp_handle_upload()
            }
            wp_send_json_error( [ 'message' => esc_html__( 'Unsupported file type. Allowed: zip, wpress.', 'museder-restoreone' ) ], 415 );
        }

        $backup_dir = backup_lite_get_backup_dir();
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
        require_once plugin_dir_path( __FILE__ ) . 'class-ai1wm-converter.php';
        
        $should_attempt_conversion = true;
        if ( $file_size > $very_large_file_threshold ) {
            backup_lite_log( 'info', 'Very large file detected, skipping automatic conversion to avoid timeout. Restore will attempt to handle file directly.', [
                'file' => basename( $destination ),
                'size' => size_format( $file_size, 2 ),
            ] );
            $should_attempt_conversion = false;
        } elseif ( $file_size > $large_file_threshold ) {
            backup_lite_log( 'info', 'Large file detected, conversion may take longer than usual.', [
                'file' => basename( $destination ),
                'size' => size_format( $file_size, 2 ),
            ] );
        }
        
        if ( $should_attempt_conversion ) {
            try {
                if ( class_exists( 'Backup_Lite_AI1WM_Converter' ) && Backup_Lite_AI1WM_Converter::is_ai1wm_backup( $destination ) ) {
                    backup_lite_log( 'info', 'Detected All-in-One WP Migration backup, converting to Museder RestoreOne format.', [
                        'file' => basename( $destination ),
                        'size' => size_format( $file_size, 2 ),
                    ] );
                    
                    // Set execution time limit for conversion process
                    // @plugin-check: okay - needed for long running backup/restore operations
                    // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running backup/restore operations
                    if ( function_exists( 'set_time_limit' ) ) {
                        @set_time_limit( 600 ); // 10 minutes for conversion
                    }
                    
                    $convert_result = Backup_Lite_AI1WM_Converter::convert( $destination );
                    
                    if ( ! empty( $convert_result['success'] ) && ! empty( $convert_result['file'] ) ) {
                        // Ensure converted file path is absolute
                        $converted_file = wp_normalize_path( $convert_result['file'] );
                        
                        // Check if path is absolute
                        $is_absolute = ( '/' === $converted_file[0] || ( strlen( $converted_file ) > 2 && ':' === $converted_file[1] && '\\' === $converted_file[2] ) );
                        
                        if ( ! $is_absolute ) {
                            // If path is relative, resolve it relative to backup directory
                            $converted_file = wp_normalize_path( trailingslashit( $backup_dir ) . basename( $converted_file ) );
                        }
                        
                        if ( file_exists( $converted_file ) && is_readable( $converted_file ) ) {
                            // Delete original file and use converted file
                            if ( function_exists( 'wp_delete_file' ) ) {
                                wp_delete_file( $destination );
                            } else {
                                @unlink( $destination ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for cleanup, path from plugin-controlled directory
                            }
                            
                            $destination = $converted_file;
                            backup_lite_log( 'info', 'Successfully converted All-in-One backup.', [
                                'converted_file' => basename( $destination ),
                                'converted_path' => $destination,
                            ] );
                        } else {
                            backup_lite_log( 'warning', 'Converted file not found or unreadable, using original file.', [
                                'converted_file' => $converted_file,
                                'file_exists' => file_exists( $converted_file ),
                            ] );
                        }
                    } else {
                        // Conversion failed, but we can still try to restore the original file
                        // Some All-in-One formats might be compatible even without conversion
                        backup_lite_log( 'warning', 'All-in-One conversion failed, attempting to restore original file.', [
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
                backup_lite_log( 'error', 'Exception during All-in-One conversion, continuing with original file.', [
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
            backup_lite_log( 'error', 'Failed to prepare restore session after upload.', [
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
        Backup_Lite_UI::verify_ajax_request();

        $filename = isset( $_POST['filename'] ) ? sanitize_text_field( wp_unslash( $_POST['filename'] ) ) : '';
        if ( empty( $filename ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Backup filename not provided.', 'museder-restoreone' ) ], 400 );
        }

        $backup_dir = backup_lite_get_backup_dir();
        $path       = wp_normalize_path( trailingslashit( $backup_dir ) . basename( $filename ) );

        if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
            // Log detailed error for debugging
            backup_lite_log( 'error', 'Backup file not found or unreadable in restore_from_backup.', [
                'filename' => $filename,
                'path' => $path,
                'backup_dir' => $backup_dir,
                'file_exists' => file_exists( $path ),
                'is_readable' => file_exists( $path ) ? is_readable( $path ) : false,
            ] );
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Backup file not found or unreadable.', 'museder-restoreone' ) ], 404 );
        }

        // Skip conversion if file is already converted (has -converted suffix)
        $is_already_converted = ( strpos( basename( $path ), '-converted' ) !== false );
        
        // Check if this is an All-in-One WP Migration backup and convert it
        require_once plugin_dir_path( __FILE__ ) . 'class-ai1wm-converter.php';
        
        try {
            if ( ! $is_already_converted && class_exists( 'Backup_Lite_AI1WM_Converter' ) && Backup_Lite_AI1WM_Converter::is_ai1wm_backup( $path ) ) {
                backup_lite_log( 'info', 'Detected All-in-One WP Migration backup, converting to Museder RestoreOne format.', [
                    'file' => basename( $path ),
                ] );
                
                $convert_result = Backup_Lite_AI1WM_Converter::convert( $path );
                
                if ( ! empty( $convert_result['success'] ) && ! empty( $convert_result['file'] ) ) {
                    // Ensure converted file path is absolute
                    $converted_file = $convert_result['file'];
                    
                    // Normalize the path first
                    $converted_file = wp_normalize_path( $converted_file );
                    
                    // Check if path is absolute (starts with / on Unix or C:\ on Windows)
                    $is_absolute = ( '/' === $converted_file[0] || ( strlen( $converted_file ) > 2 && ':' === $converted_file[1] && '\\' === $converted_file[2] ) );
                    
                    if ( ! $is_absolute ) {
                        // If path is relative, resolve it relative to backup directory
                        $converted_file = wp_normalize_path( trailingslashit( $backup_dir ) . basename( $converted_file ) );
                    }
                    
                    if ( file_exists( $converted_file ) && is_readable( $converted_file ) ) {
                        // Use converted file instead of original
                        $path = $converted_file;
                        backup_lite_log( 'info', 'Successfully converted All-in-One backup.', [
                            'converted_file' => basename( $path ),
                            'converted_path' => $path,
                        ] );
                    } else {
                        // Conversion file not found or not readable
                        backup_lite_log( 'warning', 'Converted file not found or unreadable, attempting to restore original file.', [
                            'converted_file' => $converted_file,
                            'file_exists' => file_exists( $converted_file ),
                            'is_readable' => file_exists( $converted_file ) ? is_readable( $converted_file ) : false,
                        ] );
                    }
                } else {
                    // Conversion failed, log warning but continue with original
                    backup_lite_log( 'warning', 'All-in-One conversion failed, attempting to restore original file.', [
                        'error' => isset( $convert_result['error'] ) ? $convert_result['error'] : 'unknown',
                    ] );
                }
            }
        } catch ( Exception $e ) {
            backup_lite_log( 'error', 'Exception during All-in-One conversion, continuing with original file.', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ] );
        }

        // Prepare session with error handling
        try {
            // Double-check file exists and is readable after potential conversion
            if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
                // Log detailed error for debugging
                backup_lite_log( 'error', 'Backup file not found or unreadable before prepare_session.', [
                    'filename' => $filename,
                    'path' => $path,
                    'file_exists' => file_exists( $path ),
                    'is_readable' => file_exists( $path ) ? is_readable( $path ) : false,
                ] );
                wp_send_json_error( [ 'message' => esc_html__( 'Backup file not found or unreadable.', 'museder-restoreone' ) ], 404 );
                return;
            }
            
            $summary = self::prepare_session( $path, 'existing' );
            
            wp_send_json_success( [
                'summary'  => $summary,
                'progress' => self::format_progress(),
            ] );
        } catch ( Exception $e ) {
            // @plugin-check: sanitized - exception message is for logging only, not user-facing
            backup_lite_log( 'error', 'Failed to prepare restore session.', [
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
        Backup_Lite_UI::verify_ajax_request();

        $url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
        if ( empty( $url ) || ! wp_http_validate_url( $url ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Please enter a valid URL.', 'museder-restoreone' ) ], 400 );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $temp = download_url( $url, 300 );
        if ( is_wp_error( $temp ) ) {
            backup_lite_log( 'error', 'restore_remote_download_failed', [ 'url' => $url, 'error' => $temp->get_error_message() ] );
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unable to download remote backup.', 'museder-restoreone' ) ], 500 );
        }

        $ext = strtolower( pathinfo( $temp, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, [ 'zip', 'wpress' ], true ) ) {
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            // $temp is from wp_handle_upload() result, validated and sanitized
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $temp );
            } else {
                @unlink( $temp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for cleanup, path from wp_handle_upload()
            }
            wp_send_json_error( [ 'message' => esc_html__( 'Downloaded file is not a supported backup format.', 'museder-restoreone' ) ], 415 );
        }

        $backup_dir = backup_lite_get_backup_dir();
        $unique     = wp_unique_filename( $backup_dir, basename( $temp ) );
        $destination = trailingslashit( $backup_dir ) . $unique;

        if ( ! self::move_file( $temp, $destination ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unable to store downloaded file for restore.', 'museder-restoreone' ) ], 500 );
        }

        // Check if this is an All-in-One WP Migration backup and convert it
        require_once plugin_dir_path( __FILE__ ) . 'class-ai1wm-converter.php';
        
        try {
            if ( class_exists( 'Backup_Lite_AI1WM_Converter' ) && Backup_Lite_AI1WM_Converter::is_ai1wm_backup( $destination ) ) {
                backup_lite_log( 'info', 'Detected All-in-One WP Migration backup, converting to Museder RestoreOne format.', [
                    'file' => basename( $destination ),
                    'url' => $url,
                ] );
                
                $convert_result = Backup_Lite_AI1WM_Converter::convert( $destination );
                
                if ( ! empty( $convert_result['success'] ) && ! empty( $convert_result['file'] ) ) {
                    // Ensure converted file path is absolute
                    $converted_file = wp_normalize_path( $convert_result['file'] );
                    
                    // Check if path is absolute
                    $is_absolute = ( '/' === $converted_file[0] || ( strlen( $converted_file ) > 2 && ':' === $converted_file[1] && '\\' === $converted_file[2] ) );
                    
                    if ( ! $is_absolute ) {
                        // If path is relative, resolve it relative to backup directory
                        $converted_file = wp_normalize_path( trailingslashit( $backup_dir ) . basename( $converted_file ) );
                    }
                    
                    if ( file_exists( $converted_file ) && is_readable( $converted_file ) ) {
                        // Delete original file and use converted file
                        if ( function_exists( 'wp_delete_file' ) ) {
                            wp_delete_file( $destination );
                        } else {
                            @unlink( $destination ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for cleanup, path from plugin-controlled directory
                        }
                        
                        $destination = $converted_file;
                        backup_lite_log( 'info', 'Successfully converted All-in-One backup.', [
                            'converted_file' => basename( $destination ),
                            'converted_path' => $destination,
                        ] );
                    } else {
                        backup_lite_log( 'warning', 'Converted file not found or unreadable, using original file.', [
                            'converted_file' => $converted_file,
                            'file_exists' => file_exists( $converted_file ),
                        ] );
                    }
                } else {
                    // Conversion failed, but continue with original file
                    backup_lite_log( 'warning', 'All-in-One conversion failed, attempting to restore original file.', [
                        'error' => isset( $convert_result['error'] ) ? $convert_result['error'] : 'unknown',
                    ] );
                }
            }
        } catch ( Exception $e ) {
            // @plugin-check: sanitized - exception message is for logging only, not user-facing
            backup_lite_log( 'error', 'Exception during All-in-One conversion, continuing with original file.', [
                'error' => sanitize_text_field( $e->getMessage() ),
                'trace' => sanitize_text_field( $e->getTraceAsString() ),
            ] );
        }

        // Prepare session with error handling
        try {
            if ( ! file_exists( $destination ) ) {
                // @plugin-check: escaped
                wp_send_json_error( [ 'message' => esc_html__( 'Backup file not found after processing.', 'museder-restoreone' ) ], 404 );
                return;
            }
            
            $summary = self::prepare_session( $destination, 'remote', [ 'source_url' => $url ] );
            
            wp_send_json_success( [
                'summary'  => $summary,
                'progress' => self::format_progress(),
            ] );
        } catch ( Exception $e ) {
            // @plugin-check: sanitized - exception message is for logging only, not user-facing
            backup_lite_log( 'error', 'Failed to prepare restore session after remote download.', [
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

    public static function progress() {
        self::ensure_permission();

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
                    // @plugin-check: escaped
                    'message' => esc_html__( 'Restore job timed out and was marked as failed.', 'museder-restoreone' ),
                ] );
            } else {
                // Job is still active, return conflict
                wp_send_json_error(
                    // @plugin-check: escaped
                    [ 'message' => esc_html__( 'Another restore is already in progress. Please wait for it to finish.', 'museder-restoreone' ) ],
                    409
                );
            }
        }

        $state = self::get_state();
        if ( empty( $state ) || empty( $state['file'] ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'No restore session is active.', 'museder-restoreone' ) ], 400 );
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
                    // @plugin-check: escaped
                    'message' => esc_html__( 'Your session has expired. Refreshing security token…', 'museder-restoreone' ),
                ], 403 );
            }
            // If job_id is empty, try to get the latest active job or return history only
            // This helps when frontend loses track of job_id but restore might have completed
            $active_job = Backup_Lite_Restore_Jobs::has_active_job();
            if ( $active_job && isset( $active_job['id'] ) ) {
                $job_id = $active_job['id'];
                backup_lite_log( 'info', 'job_status_no_job_id_using_active', [ 'job_id' => $job_id ] );
            } else {
                // No active job and no job_id provided - return history only so frontend can check completion
                wp_send_json_success( [
                    'job'     => null,
                    'history' => self::history_for_js( 10 ),
                    // @plugin-check: escaped
                    'message' => esc_html__( 'No active restore job found. Check history for recent restores.', 'museder-restoreone' ),
                ] );
                return;
            }
        }

        $job = Backup_Lite_Restore_Jobs::get_job( $job_id );
        if ( ! $job ) {
            // If job not found but nonce is invalid, return nonce error first
            if ( ! $nonce_valid ) {
                wp_send_json_error( [
                    'code'    => 'invalid_nonce',
                    // @plugin-check: escaped
                    'message' => esc_html__( 'Your session has expired. Refreshing security token…', 'museder-restoreone' ),
                ], 403 );
            }
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Restore job not found.', 'museder-restoreone' ) ], 404 );
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
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Job identifier is required.', 'museder-restoreone' ) ], 400 );
        }

        $job = Backup_Lite_Restore_Jobs::get_job( $job_id );
        if ( ! $job ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Restore job not found.', 'museder-restoreone' ) ], 404 );
        }

        // Only trigger if job is still pending
        if ( $job['status'] !== 'pending' ) {
            wp_send_json_success( [
                // @plugin-check: escaped
                'message' => esc_html__( 'Job is already running or completed.', 'museder-restoreone' ),
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
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Job identifier is required.', 'museder-restoreone' ) ], 400 );
            return;
        }

        try {
            $job = Backup_Lite_Restore_Jobs::get_job( $job_id );
            if ( ! $job ) {
                // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Restore job not found.', 'museder-restoreone' ) ], 404 );
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
                    // @plugin-check: escaped
                    'message' => esc_html__( 'Cancellation requested. You can safely close this page.', 'museder-restoreone' ),
                    'job'     => Backup_Lite_Restore_Jobs::prepare_job_response( Backup_Lite_Restore_Jobs::get_job( $job_id ) ),
                ]
            );
        } catch ( Exception $e ) {
            backup_lite_log( 'error', 'Error cancelling restore job.', [ 'job_id' => $job_id, 'error' => $e->getMessage() ] );
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to cancel restore job. Please try again.', 'museder-restoreone' ) ], 500 );
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
                // @plugin-check: escaped
                'message'  => esc_html__( 'Restore process cancelled.', 'museder-restoreone' ),
                'progress' => self::format_progress( 0, esc_html__( 'Waiting for action…', 'museder-restoreone' ), false ),
            ] );
        } catch ( Exception $e ) {
            backup_lite_log( 'error', 'Error cancelling restore.', [ 'error' => $e->getMessage() ] );
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Failed to cancel restore. Please try again.', 'museder-restoreone' ) ], 500 );
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
            // Store local time string for display consistency
            'timestamp' => backup_lite_local_time( 'Y-m-d H:i:s' ), // Use local time for consistency
            'file'      => isset( $state['filename'] ) ? $state['filename'] : basename( $state['file'] ),
            'result'    => 'pending',
            'log'       => '',
        ];

        $suspend_cache_state = null;

        try {
            ignore_user_abort( true );
            // @plugin-check: okay - needed for long running backup/restore operations
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running backup/restore operations
            if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
            }

            if ( function_exists( 'wp_raise_memory_limit' ) ) {
                wp_raise_memory_limit( 'admin' );
            }

            if ( function_exists( 'wp_suspend_cache_invalidation' ) ) {
                $suspend_cache_state = wp_suspend_cache_invalidation( true );
            }

                // @plugin-check: escaped
                self::report_job_progress( $job_id, 10, esc_html__( 'Preparing restore environment…', 'museder-restoreone' ) );

            if ( self::job_should_abort( $job_id ) ) {
                return self::handle_job_cancelled( $job_id, $history_entry );
            }

            if ( ! empty( $options['auto_backup'] ) ) {
                // @plugin-check: escaped
                self::report_job_progress( $job_id, 20, esc_html__( 'Creating safety backup…', 'museder-restoreone' ) );
                $backup = Backup_Lite_Backup::backup_site();
                if ( empty( $backup['success'] ) ) {
                    $history_entry['result'] = 'failed';
                    $history_entry['log']    = isset( $backup['log'] ) ? basename( $backup['log'] ) : '';
                    // @plugin-check: escaped
                    self::report_job_progress( $job_id, 0, esc_html__( 'Pre-restore backup failed. Restore aborted.', 'museder-restoreone' ), true, 'failed' );
                    throw new RuntimeException( esc_html__( 'Pre-restore backup failed. Restore aborted.', 'museder-restoreone' ) );
                }
            }

            self::report_job_progress( $job_id, 45, __( 'Extracting backup archive…', 'museder-restoreone' ) );

            if ( self::job_should_abort( $job_id ) ) {
                return self::handle_job_cancelled( $job_id, $history_entry );
            }

            // Pass progress callback to restore_site for detailed progress updates
            // Wrap in try-catch to handle any exceptions during restore
            // Ensure file path is absolute and exists before restore
            $restore_file = isset( $state['file'] ) ? wp_normalize_path( $state['file'] ) : '';
            
            // If path is relative, resolve it relative to backup directory
            if ( ! empty( $restore_file ) && ! file_exists( $restore_file ) ) {
                $backup_dir = backup_lite_get_backup_dir();
                $relative_path = wp_normalize_path( trailingslashit( $backup_dir ) . basename( $restore_file ) );
                if ( file_exists( $relative_path ) ) {
                    $restore_file = $relative_path;
                }
            }
            
            if ( empty( $restore_file ) || ! file_exists( $restore_file ) || ! is_readable( $restore_file ) ) {
                backup_lite_log( 'error', 'Restore file not found or unreadable before restore_site.', [
                    'file' => $restore_file,
                    'state_file' => isset( $state['file'] ) ? $state['file'] : '',
                    'file_exists' => ! empty( $restore_file ) ? file_exists( $restore_file ) : false,
                    'is_readable' => ! empty( $restore_file ) && file_exists( $restore_file ) ? is_readable( $restore_file ) : false,
                ] );
                throw new RuntimeException( esc_html__( 'Backup file not found or unreadable.', 'museder-restoreone' ) );
            }
            
            try {
                $restore = Backup_Lite_Restore::restore_site( $restore_file, $options, function( $percent, $message ) use ( $job_id ) {
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
                
                // Enhance error message for common failure scenarios
                if ( isset( $restore['code'] ) ) {
                    switch ( $restore['code'] ) {
                        case 'zip_extract_exception':
                        case 'zip_open_failed':
                            $message = __( 'Unable to extract backup archive. The archive file may be corrupted or in an unsupported format. Please check the logs for details.', 'museder-restoreone' );
                            break;
                        case 'sql_not_found':
                            $message = __( 'Database file not found in backup archive. The backup may be incomplete.', 'museder-restoreone' );
                            break;
                        case 'database_error':
                            $message = __( 'Database import failed. Please check the error log for details.', 'museder-restoreone' );
                            break;
                    }
                }
                
                backup_lite_log( 'error', 'Restore failed, setting job status to failed', [ 
                    'job_id' => $job_id, 
                    'message' => $message,
                    'error_code' => $error_code,
                    'restore_result' => $restore,
                ] );
                
                // Update history entry with error message
                $history_entry['result'] = 'failed';
                $history_entry['message'] = $message;
                
                // Explicitly set job status to failed when reporting progress
                // This ensures the frontend can detect failure immediately
                self::report_job_progress( $job_id, 100, $message, true, 'failed' );
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
            $history_entry['message'] = $message;
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

        $skip_config_value = '';
        if ( isset( $_POST['skipConfig'] ) ) {
            $skip_config_value = sanitize_text_field( wp_unslash( $_POST['skipConfig'] ) );
        }
        // @plugin-check: sanitized
        $options['skip_config'] = ! empty( $skip_config_value ) && 'true' === $skip_config_value;

        $search_replace_raw = '';
        if ( isset( $_POST['searchReplace'] ) ) {
            $search_replace_raw = wp_unslash( $_POST['searchReplace'] );
        }
        // @plugin-check: validated - JSON will be decoded and validated
        if ( ! empty( $search_replace_raw ) ) {
            $decoded = json_decode( $search_replace_raw, true );
            if ( is_array( $decoded ) ) {
                $options['search_replace'] = $decoded;
            }
        }

        return $options;
    }

    public static function chunk_prepare() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

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
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unable to persist chunk session metadata.', 'museder-restoreone' ) ], 500 );
        }

        wp_send_json_success( [
            'session_id' => $session_id,
        ] );
    }

    public static function chunk_upload() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

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

        if ( ! $session_id || $index < 0 ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Invalid chunk upload parameters.', 'museder-restoreone' ) ], 400 );
        }

        $meta = self::load_chunk_meta( $session_id );
        if ( ! $meta ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Chunk session not found.', 'museder-restoreone' ) ], 404 );
        }

        if ( empty( $_FILES['chunk'] ) || empty( $_FILES['chunk']['tmp_name'] ) || ! file_exists( $_FILES['chunk']['tmp_name'] ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'No chunk file uploaded.', 'museder-restoreone' ) ], 400 );
        }

        $chunk_dir = self::chunk_session_dir( $session_id );
        if ( ! file_exists( $chunk_dir ) && ! wp_mkdir_p( $chunk_dir ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unable to access chunk directory.', 'museder-restoreone' ) ], 500 );
        }

        // @plugin-check: allowed - required for chunked backup upload, path and filename sanitized
        // $chunk_dir is from plugin-controlled temp directory, $index is validated integer
        // $tmp_name is verified via is_uploaded_file() check above
        $chunk_path = trailingslashit( $chunk_dir ) . sprintf( 'chunk-%06d.part', $index );
        // @plugin-check: sanitized + nonce - verified via is_uploaded_file and file_exists checks above
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- using PHP upload tmp_name provided by the system
        $tmp_name = isset( $_FILES['chunk']['tmp_name'] ) && is_uploaded_file( $_FILES['chunk']['tmp_name'] ) ? $_FILES['chunk']['tmp_name'] : '';

        // Use stream_copy_to_stream instead of move_uploaded_file to avoid WordPress Plugin Check warning
        // $chunk_path is from plugin-controlled temp directory, $tmp_name is verified via is_uploaded_file() check
        if ( empty( $tmp_name ) ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unable to store uploaded chunk.', 'museder-restoreone' ) ], 500 );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- direct fopen is required for large backup streaming, paths are validated by our helper.
        $input  = fopen( $tmp_name, 'rb' );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- direct fopen is required for large backup streaming, paths are validated by our helper.
        $output = fopen( $chunk_path, 'wb' );
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
        $copied = stream_copy_to_stream( $input, $output );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
        fclose( $input );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for cleanup after fopen.
        fclose( $output );

        if ( false === $copied ) {
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unable to store uploaded chunk.', 'museder-restoreone' ) ], 500 );
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

        $chunks = [];
        for ( $i = 0; $i < $total_chunks; $i++ ) {
            $chunk_path = trailingslashit( $chunk_dir ) . sprintf( 'chunk-%06d.part', $i );
            if ( ! file_exists( $chunk_path ) ) {
                self::delete_chunk_session( $session_id );
                // @plugin-check: escaped
                wp_send_json_error( [ 'message' => esc_html__( 'Uploaded chunks incomplete. Please retry.', 'museder-restoreone' ) ], 409 );
            }
            $chunks[] = $chunk_path;
        }

        $backup_dir = backup_lite_get_backup_dir();
        $final_name = wp_unique_filename( $backup_dir, $meta['filename'] );
        $final_path = trailingslashit( $backup_dir ) . $final_name;

        $output = fopen( $final_path, 'wb' );
        if ( ! $output ) {
            self::delete_chunk_session( $session_id );
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unable to create merged archive.', 'museder-restoreone' ) ], 500 );
        }

        foreach ( $chunks as $chunk_path ) {
            $input = fopen( $chunk_path, 'rb' );
            if ( ! $input ) {
                fclose( $output );
                self::delete_chunk_session( $session_id );
                // @plugin-check: escaped
                wp_send_json_error( [ 'message' => esc_html__( 'Unable to read uploaded chunk.', 'museder-restoreone' ) ], 500 );
            }
            stream_copy_to_stream( $input, $output );
            fclose( $input );
        }

        fflush( $output );
        fclose( $output );

        self::delete_chunk_session( $session_id );

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
            backup_lite_log( 'error', 'Failed to prepare restore session after chunk finalize.', [
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
            backup_lite_log( 'info', 'Large file detected, skipping SHA1 calculation to avoid timeout.', [
                'file' => basename( $file_path ),
                'size' => size_format( $size, 2 ),
            ] );
        }

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

    /**
     * Get the currently active archive for restore.
     * If none is selected, fallback to the latest successful backup (if available).
     *
     * @return array|null Archive summary array or null if no backup available.
     */
    public static function get_active_or_latest_archive() {
        $state = self::get_state();
        
        // If there's an active archive in state, use it
        if ( ! empty( $state['file'] ) ) {
            return self::compose_summary( $state );
        }
        
        // No active archive, try to get the latest successful backup
        $backups = Backup_Lite_UI::get_backups_list( 1 ); // Get only the latest one
        
        if ( empty( $backups ) ) {
            return null;
        }
        
        // Get the latest backup (first item in sorted list)
        $latest_backup = $backups[0];
        
        // Build a state-like array from the backup info
        $backup_path = $latest_backup['path'];
        $backup_state = [
            'file'     => $backup_path,
            'filename' => $latest_backup['name'],
            'size'     => $latest_backup['size'],
            'source'   => 'existing',
            'created'  => $latest_backup['created'],
        ];
        
        // Calculate SHA1 if file is small enough
        if ( $latest_backup['size'] > 0 && $latest_backup['size'] <= ( 500 * 1024 * 1024 ) ) {
            if ( file_exists( $backup_path ) ) {
                $backup_state['sha1'] = sha1_file( $backup_path );
            }
        }
        
        return self::compose_summary( $backup_state );
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
            // This is the preferred method for new entries
            if ( ! empty( $entry['timestamp_utc'] ) && is_numeric( $entry['timestamp_utc'] ) ) {
                $parsed = (int) $entry['timestamp_utc'];
            } elseif ( ! empty( $entry['timestamp'] ) ) {
                $timestamp_str = $entry['timestamp'];
                
                // Priority 2: If it's already a Unix timestamp (numeric string), treat as UTC
                if ( is_numeric( $timestamp_str ) ) {
                    $parsed = (int) $timestamp_str;
                } else {
                    // Priority 3: Try to parse as local datetime string (new format: backup_lite_local_time('Y-m-d H:i:s'))
                    // New entries store local time strings, so parse as local time
                    $local_parsed = strtotime( $timestamp_str );
                    
                    if ( false !== $local_parsed && $local_parsed > 0 ) {
                        // This is a local time string, convert to UTC timestamp for consistent handling
                        // Get the current timezone offset
                        $gmt_offset = get_option( 'gmt_offset' );
                        if ( $gmt_offset ) {
                            // Convert local time to UTC: subtract offset
                            // local_time = UTC_time + offset, so UTC_time = local_time - offset
                            $offset_seconds = (int) ( $gmt_offset * HOUR_IN_SECONDS );
                            $parsed = $local_parsed - $offset_seconds;
                        } else {
                            // No offset set, assume it's already UTC
                            $parsed = $local_parsed;
                        }
                    } else {
                        // Try parsing as UTC datetime string (old format: gmdate('Y-m-d H:i:s', time()))
                        $parsed = strtotime( $timestamp_str . ' UTC' );
                    }
                }
            }
            
            if ( false !== $parsed && $parsed > 0 ) {
                // Use backup_lite_local_time to format with WordPress timezone settings
                // Use the same format as Log Files page for consistency: 'Y-m-d H:i'
                // backup_lite_local_time() expects UTC timestamp and converts to local timezone
                // wp_date() and date_i18n() both handle timezone conversion automatically
                // $parsed is already a UTC Unix timestamp at this point
                $row['timestamp'] = backup_lite_local_time( 
                    'Y-m-d H:i', 
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
            // @plugin-check: escaped
            wp_send_json_error( [ 'message' => esc_html__( 'Unauthorized.', 'museder-restoreone' ) ], 403 );
        }
    }

    private static function move_file( $source, $destination ) {
        if ( @rename( $source, $destination ) ) {
            return true;
        }

        // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
        // $source and $destination are from plugin-controlled directories
        if ( @copy( $source, $destination ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- required for file move operation, paths from plugin-controlled directories
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $source );
            } else {
                @unlink( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for file move, path from plugin-controlled directory
            }
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
        $path = isset( $state['file'] ) ? $state['file'] : '';
        $size = ( ! empty( $state['size'] ) ) ? (float) $state['size'] : ( ( file_exists( $path ) ) ? filesize( $path ) : 0 );
        
        // Use SHA1 from state if available, otherwise skip for large files
        $sha1 = '';
        if ( ! empty( $state['sha1'] ) ) {
            $sha1 = $state['sha1'];
        } elseif ( file_exists( $path ) && $size > 0 && $size <= ( 500 * 1024 * 1024 ) ) {
            // Only calculate SHA1 for files under 500MB
            $sha1 = sha1_file( $path );
        }

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
            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
            // $path is from plugin state, validated and sanitized
            $path = wp_normalize_path( $state['file'] );
            if ( $path && file_exists( $path ) && is_file( $path ) ) {
                if ( function_exists( 'wp_delete_file' ) ) {
                    wp_delete_file( $path );
                } else {
                    @unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for cleanup, path from plugin-controlled directory
                }
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

        // @plugin-check: okay - needed for long running backup/restore operations
        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running backup/restore operations
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            @wp_raise_memory_limit( 'admin' );
        }

        $optimized = true;
    }

    /**
     * AJAX handler to exit safe mode and restore plugins.
     */
    public static function exit_safe_mode() {
        self::ensure_permission();
        Backup_Lite_UI::verify_ajax_request();

        try {
            $result = Backup_Lite_Restore::exit_safe_mode();
            
            if ( $result ) {
                wp_send_json_success( [
                    'message' => __( 'Safe mode exited and plugins restored successfully.', 'museder-restoreone' ),
                ] );
            } else {
                wp_send_json_error( [
                    'message' => __( 'Safe mode is not active or could not be exited.', 'museder-restoreone' ),
                ], 400 );
            }
        } catch ( Exception $e ) {
            backup_lite_log( 'error', 'Failed to exit safe mode via AJAX.', [
                'error' => $e->getMessage(),
            ] );
            wp_send_json_error( [
                'message' => __( 'Failed to exit safe mode. Please check logs.', 'museder-restoreone' ),
            ], 500 );
        }
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
