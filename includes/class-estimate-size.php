<?php
/**
 * Backup Lite - Estimate Backup Size
 * 
 * Provides database and file size estimation for backup planning.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Backup_Lite_Estimate_Size {

    const JOB_OPTION_KEY = 'backup_lite_scan_filesize_job';
    const CACHE_SIZE_KEY = 'backup_lite_last_file_scan_size';
    const CACHE_TIME_KEY = 'backup_lite_last_file_scan_time';
    const CACHE_TTL = 48 * HOUR_IN_SECONDS; // 48 hours
    const FILES_PER_BATCH = 3000; // Files to scan per batch
    const MAX_EXECUTION_TIME = 1.5; // Maximum seconds per batch

    /**
     * Initialize hooks and AJAX endpoints.
     */
    public static function init() {
        add_action( 'wp_ajax_backup_lite_estimate_start', [ __CLASS__, 'ajax_start_scan' ] );
        add_action( 'wp_ajax_backup_lite_estimate_progress', [ __CLASS__, 'ajax_get_progress' ] );
        add_action( 'wp_ajax_backup_lite_estimate_result', [ __CLASS__, 'ajax_get_result' ] );
        
        // Schedule cron for background processing
        add_action( 'backup_lite_estimate_scan_cron', [ __CLASS__, 'cron_process_scan' ] );
    }

    /**
     * Verify AJAX request permissions and nonce.
     */
    private static function verify_ajax() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [
                'message' => __( 'Insufficient permissions.', 'museder-restoreone' ),
            ], 403 );
        }

        check_ajax_referer( Backup_Lite_UI::NONCE, 'nonce' );
    }

    /**
     * Get database size estimate.
     * 
     * @return array{bytes: int, formatted: string}
     */
    public static function get_database_size() {
        global $wpdb;

        $table_prefix = $wpdb->prefix;
        $total_bytes = 0;

        // Query information_schema for table sizes
        // Using prepared statement with LIKE to match prefix
        $query = $wpdb->prepare(
            "SELECT 
                SUM(data_length + index_length) AS total_size
            FROM information_schema.tables 
            WHERE table_schema = %s 
            AND table_name LIKE %s",
            DB_NAME,
            $table_prefix . '%'
        );

        $result = $wpdb->get_var( $query );

        if ( $result !== null ) {
            $total_bytes = (int) $result;
        }

        return [
            'bytes'     => $total_bytes,
            'formatted' => size_format( $total_bytes, 2 ),
        ];
    }

    /**
     * AJAX: Start file size scan job.
     */
    public static function ajax_start_scan() {
        self::verify_ajax();

        // Check if scan is already in progress
        $job = self::get_scan_job();
        if ( $job && $job['status'] === 'scanning' ) {
            wp_send_json_success( [
                'message' => __( 'Scan already in progress.', 'museder-restoreone' ),
                'job_id'  => $job['job_id'] ?? 'current',
            ] );
        }

        // Check cache validity
        $cached_time = get_option( self::CACHE_TIME_KEY, 0 );
        $force = isset( $_POST['force'] ) && $_POST['force'] === 'true';

        if ( ! $force && ( time() - $cached_time ) < self::CACHE_TTL ) {
            wp_send_json_success( [
                'message' => __( 'Using cached results.', 'museder-restoreone' ),
                'cached'  => true,
            ] );
        }

        // Initialize scan job
        $job_id = wp_generate_uuid4();
        $job = [
            'job_id'            => $job_id,
            'status'            => 'scanning',
            'scanned_count'     => 0,
            'total_bytes_so_far' => 0,
            'directories_scanned' => [],
            'last_update'       => time(),
            'started_at'         => time(),
            'current_path'       => '',
            'pending_directories' => [],
        ];

        // Initialize with wp-content directory only
        $wp_content_path = WP_CONTENT_DIR;
        if ( is_dir( $wp_content_path ) && is_readable( $wp_content_path ) ) {
            $job['pending_directories'][] = $wp_content_path;
        } else {
            wp_send_json_error( [
                'message' => __( 'wp-content directory is not accessible.', 'museder-restoreone' ),
            ] );
        }

        self::save_scan_job( $job );
        self::enqueue_scan_processing( $job_id );

        wp_send_json_success( [
            'message' => __( 'Scan started.', 'museder-restoreone' ),
            'job_id'  => $job_id,
        ] );
    }

    /**
     * AJAX: Get scan progress.
     */
    public static function ajax_get_progress() {
        self::verify_ajax();

        $job = self::get_scan_job();
        if ( ! $job ) {
            wp_send_json_success( [
                'status'  => 'idle',
                'message' => __( 'No scan in progress.', 'museder-restoreone' ),
            ] );
        }

        $response = [
            'status'            => $job['status'],
            'scanned_count'     => (int) $job['scanned_count'],
            'total_bytes'       => (int) $job['total_bytes_so_far'],
            'total_bytes_formatted' => size_format( $job['total_bytes_so_far'], 2 ),
            'last_update'       => $job['last_update'],
            'current_path'       => $job['current_path'] ?? '',
        ];

        // Calculate progress percentage if we have estimates
        if ( isset( $job['estimated_total_files'] ) && $job['estimated_total_files'] > 0 ) {
            $response['progress_percent'] = min( 100, round( ( $job['scanned_count'] / $job['estimated_total_files'] ) * 100, 1 ) );
        }

        wp_send_json_success( $response );
    }

    /**
     * AJAX: Get final cached result.
     */
    public static function ajax_get_result() {
        self::verify_ajax();

        $db_size = self::get_database_size();
        $file_size_bytes = get_option( self::CACHE_SIZE_KEY, 0 );
        $file_scan_time = get_option( self::CACHE_TIME_KEY, 0 );

        $total_bytes = $db_size['bytes'] + $file_size_bytes;

        $response = [
            'database' => [
                'bytes'     => $db_size['bytes'],
                'formatted' => $db_size['formatted'],
            ],
            'files' => [
                'bytes'     => $file_size_bytes,
                'formatted' => size_format( $file_size_bytes, 2 ),
            ],
            'total' => [
                'bytes'     => $total_bytes,
                'formatted' => size_format( $total_bytes, 2 ),
            ],
            'last_scanned' => $file_scan_time ? date_i18n( 'Y-m-d H:i', $file_scan_time ) : null,
            'cache_valid'  => $file_scan_time && ( time() - $file_scan_time ) < self::CACHE_TTL,
        ];

        wp_send_json_success( $response );
    }

    /**
     * Process one batch of file scanning.
     * 
     * @param string $job_id Job identifier.
     */
    public static function process_scan_batch( $job_id ) {
        $job = self::get_scan_job();
        if ( ! $job || $job['job_id'] !== $job_id || $job['status'] !== 'scanning' ) {
            return;
        }

        ignore_user_abort( true );
        $start_time = microtime( true );
        $files_scanned = 0;
        $bytes_scanned = 0;

        // Process pending directories
        while ( ! empty( $job['pending_directories'] ) && ( microtime( true ) - $start_time ) < self::MAX_EXECUTION_TIME ) {
            $current_dir = array_shift( $job['pending_directories'] );
            
            if ( ! is_dir( $current_dir ) || ! is_readable( $current_dir ) ) {
                continue;
            }

            // Check if directory should be excluded
            if ( self::should_exclude_directory( $current_dir ) ) {
                continue;
            }

            $job['current_path'] = $current_dir;
            $job['directories_scanned'][] = $current_dir;

            try {
                // Use opendir for better memory efficiency on large directories
                $handle = @opendir( $current_dir );
                if ( ! $handle ) {
                    continue;
                }

                while ( false !== ( $entry = readdir( $handle ) ) ) {
                    // Check execution time limit
                    if ( ( microtime( true ) - $start_time ) >= self::MAX_EXECUTION_TIME ) {
                        closedir( $handle );
                        break 2; // Break both loops
                    }

                    // Check file count limit
                    if ( $files_scanned >= self::FILES_PER_BATCH ) {
                        closedir( $handle );
                        break 2;
                    }

                    // Skip . and ..
                    if ( $entry === '.' || $entry === '..' ) {
                        continue;
                    }

                    $full_path = $current_dir . DIRECTORY_SEPARATOR . $entry;
                    
                    // Skip if path is excluded
                    if ( self::should_exclude_directory( $full_path ) || self::should_exclude_file( $full_path ) ) {
                        continue;
                    }

                    if ( is_file( $full_path ) && is_readable( $full_path ) ) {
                        $file_size = @filesize( $full_path );
                        if ( $file_size !== false ) {
                            $bytes_scanned += $file_size;
                            $files_scanned++;
                        }
                    } elseif ( is_dir( $full_path ) && is_readable( $full_path ) ) {
                        // Add subdirectory to pending list if not already scanned
                        if ( ! in_array( $full_path, $job['directories_scanned'], true ) ) {
                            $job['pending_directories'][] = $full_path;
                        }
                    }
                }

                closedir( $handle );
            } catch ( Exception $e ) {
                // Skip directories that cause errors (permissions, etc.)
                continue;
            }
        }

        // Update job progress
        $job['scanned_count'] += $files_scanned;
        $job['total_bytes_so_far'] += $bytes_scanned;
        $job['last_update'] = time();

        // Check if scan is complete
        if ( empty( $job['pending_directories'] ) ) {
            $job['status'] = 'completed';
            
            // Save final results to cache
            update_option( self::CACHE_SIZE_KEY, $job['total_bytes_so_far'] );
            update_option( self::CACHE_TIME_KEY, time() );
            
            // Clear job
            delete_option( self::JOB_OPTION_KEY );
        } else {
            // Save progress and schedule next batch
            self::save_scan_job( $job );
            self::enqueue_scan_processing( $job_id );
        }
    }

    /**
     * Check if directory should be excluded from scan.
     * 
     * @param string $dir_path Directory path.
     * @return bool
     */
    private static function should_exclude_directory( $dir_path ) {
        $normalized_path = wp_normalize_path( $dir_path );
        $normalized_lower = strtolower( $normalized_path );

        // Exclude backup and log directories
        $backup_dir = backup_lite_get_backup_dir();
        $log_dir = backup_lite_get_log_dir();
        $storage_root = backup_lite_get_storage_root();
        
        if ( strpos( $normalized_path, $backup_dir ) === 0 ) {
            return true;
        }
        if ( strpos( $normalized_path, $log_dir ) === 0 ) {
            return true;
        }
        if ( strpos( $normalized_path, $storage_root['path'] ) === 0 ) {
            return true;
        }

        // Exclude common patterns
        $exclude_patterns = [
            'backup-lite-logs',
            'backup-lite-backups',
            'museder-restoreone', // Our plugin storage
            'cache',
            'mu-plugins',
            '.git',
            '.svn',
            'node_modules',
        ];

        foreach ( $exclude_patterns as $pattern ) {
            if ( strpos( $normalized_lower, $pattern ) !== false ) {
                return true;
            }
        }

        // Exclude /wp-content/cache/ and /uploads/cache/
        if ( preg_match( '/\/cache\/?$/i', $normalized_path ) || preg_match( '/\/uploads\/cache\//i', $normalized_path ) ) {
            return true;
        }

        return false;
    }

    /**
     * Check if file should be excluded from scan.
     * 
     * @param string $file_path File path.
     * @return bool
     */
    private static function should_exclude_file( $file_path ) {
        $exclude_files = [
            '.DS_Store',
            '.gitignore',
            '.svn',
        ];

        $basename = basename( $file_path );
        if ( in_array( $basename, $exclude_files, true ) ) {
            return true;
        }

        return false;
    }

    /**
     * Get current scan job.
     * 
     * @return array|null
     */
    private static function get_scan_job() {
        return get_option( self::JOB_OPTION_KEY, null );
    }

    /**
     * Save scan job state.
     * 
     * @param array $job Job data.
     */
    private static function save_scan_job( $job ) {
        update_option( self::JOB_OPTION_KEY, $job );
    }

    /**
     * Enqueue scan processing (via AJAX or cron).
     * 
     * @param string $job_id Job identifier.
     */
    private static function enqueue_scan_processing( $job_id ) {
        // Try to schedule immediate cron event
        if ( ! wp_next_scheduled( 'backup_lite_estimate_scan_cron', [ $job_id ] ) ) {
            wp_schedule_single_event( time() + 2, 'backup_lite_estimate_scan_cron', [ $job_id ] );
        }
    }

    /**
     * Cron handler for processing scan batches.
     * 
     * @param string $job_id Job identifier.
     */
    public static function cron_process_scan( $job_id ) {
        self::process_scan_batch( $job_id );
    }
}

