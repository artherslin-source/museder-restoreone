<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Backup_Lite_Backup_Jobs {

    const STATE_OPTION      = 'backup_lite_active_job';
    const CRON_HOOK         = 'backup_lite_process_job';
    const LOCK_TTL          = 60;
    const AJAX_BATCH_FILES  = 200;
    const AJAX_BATCH_BYTES  = 40 * 1024 * 1024; // 40MB
    const CRON_BATCH_FILES  = 600;
    const CRON_BATCH_BYTES  = 120 * 1024 * 1024; // 120MB

    public static function init() {
        add_action( self::CRON_HOOK, [ __CLASS__, 'cron_process_job' ], 10, 1 );
    }

    /**
     * Create a new backup job.
     *
     * @param array $options Optional backup options.
     * @return array
     */
    public static function create_job( $options = [] ) {
        $job_id = wp_generate_uuid4();

        $context = Backup_Lite_Backup::prepare_async_job( $job_id, $options );
        if ( empty( $context['manifest_file'] ) || empty( $context['manifest_count'] ) ) {
            throw new RuntimeException( esc_html__( 'Unable to build file manifest for backup.', 'museder-restoreone' ) );
        }

        // Store started_at timestamp when job is actually queued
        $started_at = time();
        
        $job = [
            'id'              => $job_id,
            'status'          => 'pending',
            'stage'           => 'preparing',
            'message'         => __( 'Preparing backup…', 'museder-restoreone' ),
            'created_at'      => current_time( 'mysql' ),
            'updated_at'      => current_time( 'mysql' ),
            'processing'      => false,
            'pointer'         => 0,
            'processed_files' => 0,
            'processed_bytes' => 0,
            'total_files'     => (int) $context['manifest_count'],
            'total_bytes'     => max( 1, (int) $context['manifest_bytes'] ),
            'archive_path'    => $context['archive_path'],
            'download_url'    => backup_lite_get_download_url( $context['archive_path'] ),
            'temp_dir'        => $context['temp_dir'],
            'manifest_file'   => $context['manifest_file'],
            'options'         => $context['options'],
            'started_at'      => $started_at, // Store timestamp when job is queued
            'last_activity'   => time(),
        ];

        self::save_job( $job );
        self::set_active_job( $job_id );
        self::enqueue_processing( $job_id );

        return $job;
    }

    /**
     * Return lightweight summary of the active job for UI bootstrap.
     *
     * @return array|null
     */
    public static function get_active_job_summary() {
        $job = self::get_active_job();
        if ( ! $job ) {
            return null;
        }

        return self::format_job_payload( $job );
    }

    /**
     * Retrieve the currently active job (if any).
     *
     * @return array|null
     */
    public static function get_active_job() {
        $job_id = get_option( self::STATE_OPTION, '' );
        if ( empty( $job_id ) ) {
            return null;
        }

        $job = self::load_job( $job_id );
        if ( empty( $job ) || in_array( $job['status'], [ 'completed', 'failed', 'cancelled' ], true ) ) {
            delete_option( self::STATE_OPTION );
            return null;
        }

        return $job;
    }

    /**
     * Schedule background processing of a job.
     *
     * @param string $job_id Job identifier.
     * @param bool   $force  Process immediately instead of scheduling.
     */
    public static function enqueue_processing( $job_id, $force = false ) {
        if ( $force ) {
            self::process_job_immediately( $job_id, self::AJAX_BATCH_FILES, self::AJAX_BATCH_BYTES );
            return;
        }

        if ( ! wp_next_scheduled( self::CRON_HOOK, [ $job_id ] ) ) {
            wp_schedule_single_event( time(), self::CRON_HOOK, [ $job_id ] );
        }
    }

    /**
     * Cron handler.
     *
     * @param string $job_id Job identifier.
     */
    public static function cron_process_job( $job_id ) {
        self::process_job_immediately( $job_id, self::CRON_BATCH_FILES, self::CRON_BATCH_BYTES );
    }

    /**
     * Process a job immediately (used by cron + AJAX fallback).
     *
     * @param string $job_id Job identifier.
     * @param int    $max_files Max files per batch.
     * @param int    $max_bytes Max bytes per batch.
     * @return array|null Updated job state.
     */
    public static function process_job_immediately( $job_id, $max_files, $max_bytes ) {
        $job = self::load_job( $job_id );
        if ( ! $job ) {
            return null;
        }

        $locked = self::acquire_lock( $job );
        if ( ! $locked ) {
            return $job;
        }

        $job['stage'] = 'packing';
        $job['status'] = 'running';
        self::save_job( $job );

        $limits    = self::resolve_batch_limits( $job, $max_files, $max_bytes );
        $max_files = $limits['max_files'];
        $max_bytes = $limits['max_bytes'];

        try {
            $job = Backup_Lite_Backup::process_job_batch( $job, $max_files, $max_bytes );

            if ( in_array( $job['status'], [ 'completed', 'failed', 'cancelled' ], true ) ) {
                self::clear_active_job( $job['id'] );
            }
        } catch ( Throwable $exception ) {
            backup_lite_log( 'error', 'Error processing backup job batch.', [
                'job_id' => $job_id,
                'message' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ] );
            
            $job['status']  = 'failed';
            $job['stage']   = 'failed';
            $job['message'] = __( 'Backup failed due to an internal error. Please check logs for details.', 'museder-restoreone' );
        }

        if ( in_array( $job['status'], [ 'completed', 'failed', 'cancelled' ], true ) ) {
            self::clear_active_job( $job['id'] );
        }

        $job['processing']    = false;
        $job['last_activity'] = time();
        $job['updated_at']    = current_time( 'mysql' );
        self::save_job( $job );

        return $job;
    }

    /**
     * Return job status payload formatted for AJAX responses.
     *
     * @param string $job_id Job ID.
     * @return array|null
     */
    public static function get_job_payload( $job_id ) {
        try {
            $job = self::load_job( $job_id );
            if ( ! $job ) {
                return null;
            }

            return self::format_job_payload( $job );
        } catch ( Throwable $e ) {
            backup_lite_log( 'error', 'Failed to get job payload.', [
                'job_id' => $job_id,
                'message' => $e->getMessage(),
            ] );
            return null;
        }
    }

    /**
     * Cancel and clean up a job.
     *
     * @param string $job_id Job ID.
     * @return bool
     */
    public static function cancel_job( $job_id ) {
        $job = self::load_job( $job_id );
        if ( ! $job ) {
            return false;
        }

        $job['status']  = 'cancelled';
        $job['stage']   = 'cancelled';
        $job['message'] = __( 'Backup cancelled by user.', 'museder-restoreone' );
        $job['processing'] = false;
        self::save_job( $job );
        self::cleanup_job( $job );
        self::clear_active_job( $job_id );

        return true;
    }

    /**
     * Remove temp and manifest files for a finished job.
     *
     * @param array $job Job state.
     */
    public static function cleanup_job( $job ) {
        if ( ! empty( $job['temp_dir'] ) && is_dir( $job['temp_dir'] ) ) {
            backup_lite_delete_directory( $job['temp_dir'] );
        }

        if ( ! empty( $job['manifest_file'] ) && file_exists( $job['manifest_file'] ) ) {
            // @plugin-check: allowed - required for backup/restore file operations
            // Path is validated and sanitized before use
            @unlink( $job['manifest_file'] );
        }
    }

    /**
     * Persist job state to disk.
     *
     * @param array $job Job state.
     */
    public static function save_job( $job ) {
        $path = self::job_state_path( $job['id'] );
        $dir  = dirname( $path );
        if ( ! file_exists( $dir ) ) {
            backup_lite_ensure_directory( $dir );
        }

        $encoded = wp_json_encode( $job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        file_put_contents( $path, $encoded, LOCK_EX );
    }

    /**
     * Load job state from disk.
     *
     * @param string $job_id Job identifier.
     * @return array|null
     */
    public static function load_job( $job_id ) {
        try {
            $path = self::job_state_path( $job_id );
            if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
                return null;
            }

            $contents = file_get_contents( $path );
            if ( false === $contents ) {
                return null;
            }

            $decoded = json_decode( $contents, true );
            if ( json_last_error() !== JSON_ERROR_NONE ) {
                return null;
            }

            return is_array( $decoded ) ? $decoded : null;
        } catch ( Throwable $e ) {
            backup_lite_log( 'error', 'Failed to load job state.', [
                'job_id' => $job_id,
                'message' => $e->getMessage(),
            ] );
            return null;
        }
    }

    /**
     * Convert job state into a safe payload for JS.
     *
     * @param array $job Job state.
     * @return array
     */
    public static function format_job_payload( $job ) {
        // Safely extract values with defaults to prevent undefined index errors
        $total_files   = max( 1, isset( $job['total_files'] ) ? (int) $job['total_files'] : 1 );
        $processed     = min( $total_files, isset( $job['processed_files'] ) ? (int) $job['processed_files'] : 0 );
        $total_bytes   = max( 1, isset( $job['total_bytes'] ) ? (int) $job['total_bytes'] : 1 );
        $processed_b   = min( $total_bytes, max( 0, isset( $job['processed_bytes'] ) ? (int) $job['processed_bytes'] : 0 ) );
        $percentage    = max( 0, min( 100, round( ( $processed_b / $total_bytes ) * 100 ) ) );

        $payload = array(
            'id'              => isset( $job['id'] ) ? $job['id'] : '',
            'status'          => isset( $job['status'] ) ? $job['status'] : 'unknown',
            'stage'           => isset( $job['stage'] ) ? $job['stage'] : '',
            'message'         => isset( $job['message'] ) ? $job['message'] : '',
            'processed_files' => $processed,
            'total_files'     => $total_files,
            'processed_bytes' => $processed_b,
            'total_bytes'     => $total_bytes,
            'percentage'      => $percentage,
            'download_url'    => isset( $job['download_url'] ) ? $job['download_url'] : '',
            'processing'      => ! empty( $job['processing'] ),
            'updated_at'      => isset( $job['updated_at'] ) ? $job['updated_at'] : '',
        );

        // Include duration if available
        if ( isset( $job['duration'] ) ) {
            $payload['duration'] = (int) $job['duration'];
        }

        // Note: S3 upload is now handled server-side in finalize_async_job()
        // No need to pass flags to frontend anymore

        return $payload;
    }

    /**
     * Acquire a processing lock for the job.
     *
     * @param array $job Job state.
     * @return bool
     */
    private static function acquire_lock( &$job ) {
        $now = time();

        if ( ! empty( $job['processing'] ) && ! empty( $job['last_activity'] ) ) {
            $age = $now - (int) $job['last_activity'];
            if ( $age < self::LOCK_TTL ) {
                return false;
            }
        }

        $job['processing']    = true;
        $job['last_activity'] = $now;
        $job['updated_at']    = current_time( 'mysql' );

        return true;
    }

    /**
     * Dynamically scale batch limits to speed up large-site jobs.
     *
     * @param array $job Job state.
     * @param int   $max_files Requested max files.
     * @param int   $max_bytes Requested max bytes.
     * @return array{max_files:int,max_bytes:int}
     */
    private static function resolve_batch_limits( $job, $max_files, $max_bytes ) {
        $total_bytes = isset( $job['total_bytes'] ) ? (int) $job['total_bytes'] : 0;
        $total_files = isset( $job['total_files'] ) ? max( 1, (int) $job['total_files'] ) : 1;
        $average     = $total_files > 0 ? max( 1, (int) floor( $total_bytes / $total_files ) ) : 1;

        $multiplier = 1.0;
        $gigabyte   = 1024 * 1024 * 1024;

        if ( $total_bytes > 5 * $gigabyte ) {
            $multiplier = 4.0;
        } elseif ( $total_bytes > 2 * $gigabyte ) {
            $multiplier = 3.0;
        } elseif ( $total_bytes > $gigabyte ) {
            $multiplier = 2.0;
        } elseif ( $average > 2 * 1024 * 1024 ) {
            $multiplier = 1.5;
        }

        $scaled_files = (int) round( $max_files * $multiplier );
        $scaled_bytes = (int) round( $max_bytes * $multiplier );

        $limits = [
            'max_files' => (int) min( 2000, max( 100, $scaled_files ) ),
            'max_bytes' => (int) min( 512 * 1024 * 1024, max( 20 * 1024 * 1024, $scaled_bytes ) ),
        ];

        /**
         * Filters the batch size limits for asynchronous backup jobs.
         *
         * @since 2.6.32
         *
         * @param array $limits {
         *     @type int $max_files Maximum files per batch.
         *     @type int $max_bytes Maximum bytes per batch.
         * }
         * @param array $job Current job state.
         */
        $limits = apply_filters( 'backup_lite_job_batch_limits', $limits, $job );

        $limits['max_files'] = (int) max( 50, $limits['max_files'] );
        $limits['max_bytes'] = (int) max( 10 * 1024 * 1024, $limits['max_bytes'] );

        return $limits;
    }

    private static function job_state_path( $job_id ) {
        return trailingslashit( backup_lite_get_jobs_dir() ) . $job_id . '.json';
    }

    private static function set_active_job( $job_id ) {
        update_option( self::STATE_OPTION, $job_id, false );
    }

    private static function clear_active_job( $job_id ) {
        $stored = get_option( self::STATE_OPTION, '' );
        if ( $stored === $job_id ) {
            delete_option( self::STATE_OPTION );
        }
        // Clear transient used for server-side protection against settings changes
        if ( class_exists( 'Backup_Lite_UI' ) ) {
            Backup_Lite_UI::clear_job_running();
        }
    }
}

