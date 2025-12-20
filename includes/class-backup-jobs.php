<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Backup_Lite_Backup_Jobs {

    const STATE_OPTION      = 'backup_lite_active_job';
    const CRON_HOOK         = 'backup_lite_process_job';
    const LOCK_TTL          = 60;
    const OPTION_LOCK_TTL   = 180;
    const AJAX_BATCH_FILES  = 400; // Increased from 200 to improve backup speed
    const AJAX_BATCH_BYTES  = 80 * 1024 * 1024; // 80MB - Increased from 40MB to improve backup speed
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
        // If there's already an active job, return it instead of creating a new one.
        // This prevents accidental duplicate jobs and "restart loops" when users click multiple times or recover from timeouts.
        $existing = self::get_active_job();
        if ( $existing ) {
            return $existing;
        }

        $job_id = wp_generate_uuid4();

        $context = Backup_Lite_Backup::create_async_job_stub_context( $job_id, $options );

        $job = [
            'id'              => $job_id,
            'status'          => 'running',
            'stage'           => 'preparing',
            'message'         => __( 'Preparing backup…', 'museder-restoreone' ),
            'created_at'      => current_time( 'mysql' ),
            'updated_at'      => current_time( 'mysql' ),
            'processing'      => false,
            'pointer'         => 0,
            'processed_files' => 0,
            'processed_bytes' => 0,
            // Totals are determined during preparing stage (manifest build).
            'total_files'     => 1,
            'total_bytes'     => 1,
            // Diagnostics + completion guards (prevents fake-success archives).
            'attempted_files' => 0,
            'added_files'     => 0,
            'skipped_files'   => 0,
            'added_bytes'     => 0,
            'skipped_bytes'   => 0,
            'skip_reasons'    => [],
            'diagnostic_samples' => [],
            'pack_method'     => backup_lite_can_use_ziparchive() ? 'ziparchive' : 'pclzip',
            'selfcheck'       => [],
            'prep_step'       => 'db',
            'archive_path'    => $context['archive_path'],
            'download_url'    => backup_lite_get_download_url( $context['archive_path'] ),
            'temp_dir'        => $context['temp_dir'],
            'manifest_file'   => $context['manifest_file'],
            'sql_path'        => $context['sql_path'],
            'meta_path'       => $context['meta_path'],
            'options'         => $context['options'],
            'last_activity'   => time(),
            'started_at'      => time(), // Record backup start time (UTC timestamp)
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

        // Clear any existing scheduled event(s) to avoid duplicates
        self::clear_scheduled_job( $job_id );

        // Schedule first batch immediately
        wp_schedule_single_event( time(), self::CRON_HOOK, [ $job_id ] );
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
     * Implements time budget loop: processes multiple batches within a single request
     * until time limit (dynamically calculated based on max_execution_time) is reached or job completes.
     * Time budget formula: max(25, min(max_execution_time * 0.75, 90)) seconds.
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

        // Finalize-guard: if a terminal job somehow gets re-invoked (e.g., lingering cron),
        // do not process again. Also clear active pointer + scheduled events defensively.
        if ( isset( $job['status'] ) && in_array( $job['status'], [ 'completed', 'failed', 'cancelled' ], true ) ) {
            self::clear_active_job( $job_id );
            self::clear_scheduled_job( $job_id );
            return $job;
        }

        // If a previous request died unexpectedly, the UI may stop nudging when processing=true.
        // Treat stale processing flag as recoverable.
        $now_ts = time();
        if ( ! empty( $job['processing'] ) && ! empty( $job['last_activity'] ) ) {
            $last_activity = (int) $job['last_activity'];
            if ( $last_activity > 0 && ( $now_ts - $last_activity ) > 120 ) {
                $job['processing'] = false;
                $job['last_activity'] = $now_ts;
                $job['updated_at'] = current_time( 'mysql' );
                self::save_job( $job );
            }
        }

        // Cross-request atomic lock (prevents concurrent cron/AJAX from processing the same job).
        $lock_token = self::acquire_option_lock( $job_id );
        if ( empty( $lock_token ) ) {
            // Another request owns the lock; return current status only.
            return $job;
        }

        try {
            // Mark as processing for frontend/UI (kept true for the whole request; reset to false at the end).
            self::acquire_lock( $job );

            // If the job is still in preparing stage, do not force packing yet.
            // Preparing may take time (DB dump/manifest/self-check) and should run in background.
            if ( empty( $job['stage'] ) ) {
                $job['stage'] = 'preparing';
            }
            $job['status'] = 'running';
            self::save_job( $job );

            $limits    = self::resolve_batch_limits( $job, $max_files, $max_bytes );
            $max_files = $limits['max_files'];
            $max_bytes = $limits['max_bytes'];

        // Calculate time budget dynamically based on max_execution_time
        // Formula: max(25, min(max_execution_time * 0.75, 90))
        // This means:
        // - If max_execution_time = 30 seconds, time_budget = 25 seconds (keep current behavior)
        // - If max_execution_time = 60 seconds, time_budget = 45 seconds
        // - If max_execution_time = 120 seconds, time_budget = 90 seconds
        $max_execution_time = (int) ini_get( 'max_execution_time' );
        if ( $max_execution_time <= 0 ) {
            // Default to 30 seconds if max_execution_time is unlimited or not set
            $max_execution_time = 30;
        }
        
        // Dynamic time budget calculation
        // Use 75% of max_execution_time, but ensure minimum of 25 seconds
        // Cap at 90 seconds to prevent extremely long single requests that might timeout
        // This leaves buffer for frontend timeout (30s) and other operations
        $time_budget = max( 25, min( (int) ( $max_execution_time * 0.75 ), 90 ) );
        // Hard cap to keep requests short on strict shared hosting (prevents 150s+ requests when a single batch is slow).
        $hard_budget = 25;
        $time_budget = min( $time_budget, $hard_budget );
        $start_microtime = microtime( true ); // Use microtime for precise timing
        $processed_bytes_start = isset( $job['processed_bytes'] ) ? (int) $job['processed_bytes'] : 0;
        $batch_count = 0;
        $max_batches = 100; // Safety limit to prevent infinite loops
        
        // State save optimization: track when to save (every 5 batches or every 3 seconds)
        $last_save_time = $start_microtime;
        $save_interval_batches = 5;
        $save_interval_seconds = 3.0;

        // Open ZipArchive once for the entire time budget loop to reduce I/O overhead
        $zip = null;
        if ( backup_lite_can_use_ziparchive() && ! empty( $job['archive_path'] ) && file_exists( $job['archive_path'] ) ) {
            $zip = new ZipArchive();
            if ( true === $zip->open( $job['archive_path'], ZipArchive::CREATE ) ) {
                backup_lite_log( 'info', 'Opened ZipArchive for time budget loop.', [
                    'job_id' => $job_id,
                ] );
            } else {
                // If opening fails, set to null so we fall back to per-batch opening
                $zip = null;
                backup_lite_log( 'warning', 'Failed to open ZipArchive for time budget loop, will open per batch.', [
                    'job_id' => $job_id,
                ] );
            }
        }

        $job_completed_in_loop = false;
        $job_needs_finalize    = false;

            try {
            // Time budget loop: process multiple batches until time limit or job completion
            // Use microtime for more precise timing
            while ( $batch_count < $max_batches ) {
                // Check if we've exceeded time budget (using microtime for precision)
                $elapsed = microtime( true ) - $start_microtime;
                if ( $elapsed >= $time_budget ) {
                    backup_lite_log( 'info', 'Time budget reached, scheduling next batch.', [
                        'job_id' => $job_id,
                        'elapsed' => round( $elapsed, 2 ),
                        'time_budget' => $time_budget,
                        'batches_processed' => $batch_count,
                    ] );
                    break;
                }

                // If we are close to the time budget, shrink batch limits for the next operation.
                $remaining = $time_budget - $elapsed;
                if ( $remaining < 8 ) {
                    $max_files = min( $max_files, 60 );
                    $max_bytes = min( $max_bytes, 12 * 1024 * 1024 ); // 12MB
                } elseif ( $remaining < 15 ) {
                    $max_files = min( $max_files, 120 );
                    $max_bytes = min( $max_bytes, 24 * 1024 * 1024 ); // 24MB
                }

                // Preparing stage runs before packing to avoid long initial AJAX requests.
                if ( isset( $job['stage'] ) && 'preparing' === $job['stage'] ) {
                    $job = Backup_Lite_Backup::run_preparing_stage( $job );
                    $batch_count++;

                    // Move to packing when preparing is done.
                    if ( isset( $job['prep_step'] ) && 'done' === $job['prep_step'] && 'failed' !== $job['status'] ) {
                        $job['stage']  = 'packing';
                        $job['status'] = 'running';
                    }

                    // If archive is now available and we can reuse ZipArchive, open it.
                    if ( null === $zip && 'packing' === $job['stage'] && backup_lite_can_use_ziparchive() && ! empty( $job['archive_path'] ) && file_exists( $job['archive_path'] ) ) {
                        $zip = new ZipArchive();
                        if ( true !== $zip->open( $job['archive_path'], ZipArchive::CREATE ) ) {
                            $zip = null;
                        }
                    }

                    // Always save after each preparing step to keep UI responsive.
                    $job['processing']    = true;
                    $job['last_activity'] = time();
                    $job['updated_at']    = current_time( 'mysql' );
                    self::save_job( $job );
                    $last_save_time = microtime( true );

                    // If still preparing, continue the loop until time budget is reached.
                    if ( 'preparing' === $job['stage'] ) {
                        continue;
                    }
                }

                // Process one packing batch (reuse ZipArchive if available)
                $job = Backup_Lite_Backup::process_job_batch( $job, $max_files, $max_bytes, $zip );
                $batch_count++;

                // If packing is done, defer finalize until after ZipArchive::close().
                if ( ! empty( $job['needs_finalize'] ) ) {
                    $job_needs_finalize = true;
                    break;
                }

                // Check if job is complete
                if ( in_array( $job['status'], [ 'completed', 'failed', 'cancelled' ], true ) ) {
                    self::clear_active_job( $job['id'] );
                    $job_completed_in_loop = true;
                    break;
                }

                // Always save after each packing batch to avoid stuck UI when hosts kill long requests.
                $job['processing']    = true;
                $job['last_activity'] = time();
                $job['updated_at']    = current_time( 'mysql' );
                self::save_job( $job );
                $last_save_time = microtime( true );
            }

            // Close ZipArchive if we opened it (single close point).
            if ( null !== $zip ) {
                try {
                    $closed = $zip->close();
                    if ( false === $closed ) {
                        throw new RuntimeException( 'ZipArchive::close() returned false.' );
                    }
                    backup_lite_log( 'info', 'Closed ZipArchive after time budget loop.', [
                        'job_id' => $job_id,
                        'batches_processed' => $batch_count,
                        'completed' => $job_completed_in_loop,
                    ] );
                } catch ( Throwable $throwable ) {
                    // Prevent fatal "Invalid or uninitialized Zip object" from breaking AJAX polling (500).
                    backup_lite_log( 'warning', 'Failed to close ZipArchive after time budget loop.', [
                        'job_id' => $job_id,
                        'error' => $throwable->getMessage(),
                    ] );
                    // If packing finished but we can't close, fail the job to avoid serving partial archives.
                    if ( $job_needs_finalize ) {
                        $job['status']  = 'failed';
                        $job['stage']   = 'failed';
                        $job['message'] = __( 'Unable to finalize backup archive. Please check logs and try again.', 'museder-restoreone' );
                        $job_needs_finalize = false;
                    }
                }
                $zip = null;
            }

            // If packing finished, finalize AFTER close so filesize/metadata are accurate.
            if ( $job_needs_finalize && ! in_array( $job['status'], [ 'failed', 'cancelled' ], true ) ) {
                $total_files     = isset( $job['total_files'] ) ? (int) $job['total_files'] : 0;
                $pointer         = isset( $job['pointer'] ) ? (int) $job['pointer'] : 0;
                $processed_files = isset( $job['processed_files'] ) ? (int) $job['processed_files'] : 0;

                if ( $total_files > 0 && max( $pointer, $processed_files ) < $total_files ) {
                    // Safety: do not finalize early (would produce an incomplete archive).
                    backup_lite_log( 'warning', 'Deferring finalize because packing is not complete.', [
                        'job_id'          => $job_id,
                        'total_files'     => $total_files,
                        'pointer'         => $pointer,
                        'processed_files' => $processed_files,
                    ] );
                    $job['status']  = 'running';
                    $job['stage']   = 'packing';
                    $job['message'] = __( 'Backup running…', 'museder-restoreone' );
                    $job_needs_finalize = false;
                } else {
                    $job = Backup_Lite_Backup::finalize_async_job_after_close( $job );
                    $job_completed_in_loop = true;
                    $job_needs_finalize = false;
                }
            }

            // If job completed inside the loop, persist final state AFTER close to avoid exposing completed status early.
            if ( $job_completed_in_loop ) {
                $job['processing']    = false;
                $job['last_activity'] = time();
                $job['updated_at']    = current_time( 'mysql' );
                self::save_job( $job );
            }

            // Ensure we save at least once before time budget ends (if we processed any batches)
            if ( $batch_count > 0 ) {
                $current_time = microtime( true );
                $time_since_last_save = $current_time - $last_save_time;
                // Save if we haven't saved recently (more than 1 second ago)
                if ( $time_since_last_save >= 1.0 ) {
                    $job['processing']    = true;
                    $job['last_activity'] = time();
                    $job['updated_at']    = current_time( 'mysql' );
                    self::save_job( $job );
                }
            }

            // Log batch processing summary
            if ( $batch_count > 1 ) {
                $total_elapsed = microtime( true ) - $start_microtime;
                $processed_bytes_end = isset( $job['processed_bytes'] ) ? (int) $job['processed_bytes'] : 0;
                $delta_bytes = max( 0, $processed_bytes_end - $processed_bytes_start );
                $throughput_mbps = $total_elapsed > 0 ? round( ( $delta_bytes / 1048576 ) / $total_elapsed, 2 ) : 0;
                $mode = '';
                if ( isset( $job['options'] ) && is_array( $job['options'] ) ) {
                    $mode = isset( $job['options']['backup_mode'] ) ? (string) $job['options']['backup_mode'] : '';
                }
                backup_lite_log( 'info', 'Processed multiple batches in single request.', [
                    'job_id' => $job_id,
                    'batches' => $batch_count,
                    'elapsed' => round( $total_elapsed, 2 ),
                    'bytes' => $delta_bytes,
                    'mb_per_second' => $throughput_mbps,
                    'backup_mode' => $mode,
                ] );
            }

            } catch ( Throwable $exception ) {
            // Close ZipArchive if we opened it (even on error)
            if ( null !== $zip ) {
                try {
                    $zip->close();
                } catch ( Throwable $ignored ) {
                    // ignore
                }
                backup_lite_log( 'warning', 'Closed ZipArchive after exception.', [
                    'job_id' => $job_id,
                    'error' => $exception->getMessage(),
                ] );
            }
            
            $job['status']  = 'failed';
            $job['stage']   = 'failed';
            $job['message'] = $exception->getMessage();
            }

            if ( in_array( $job['status'], [ 'completed', 'failed', 'cancelled' ], true ) ) {
                self::clear_active_job( $job['id'] );
                self::clear_scheduled_job( $job_id );
                // Don't schedule next event if job is complete
            } else {
                // Job is still running - schedule next batch with short interval (10-20 seconds)
                // This implements "short interval single event" scheduling for cron mode
                self::schedule_next_batch( $job_id, $job );
            }

            // Request is ending: mark processing false so UI may nudge/cron may continue.
            $job['processing']    = false;
            $job['last_activity'] = time();
            $job['updated_at']    = current_time( 'mysql' );
            self::save_job( $job );

            return $job;
        } finally {
            self::release_option_lock( $job_id, $lock_token );
        }
    }

    /**
     * Schedule next batch processing with short interval (10-20 seconds).
     * Only schedules if job status is preparing or packing.
     *
     * @param string $job_id Job identifier.
     * @param array  $job    Current job state.
     */
    private static function schedule_next_batch( $job_id, $job ) {
        // Only schedule if job is in preparing or packing stage
        if ( ! in_array( $job['stage'], [ 'preparing', 'packing' ], true ) ) {
            return;
        }

        // Only schedule if job is still running
        if ( $job['status'] !== 'running' ) {
            return;
        }

        // Clear any existing scheduled event(s) for this job
        self::clear_scheduled_job( $job_id );

        // Schedule next batch with short interval (10-20 seconds, randomly distributed)
        // This prevents all jobs from running at exactly the same time
        $interval = 10 + wp_rand( 0, 10 ); // 10-20 seconds
        $next_run = time() + $interval;

        wp_schedule_single_event( $next_run, self::CRON_HOOK, [ $job_id ] );

        backup_lite_log( 'info', 'Scheduled next batch with short interval.', [
            'job_id' => $job_id,
            'interval' => $interval,
            'next_run' => $next_run,
            'stage' => $job['stage'],
        ] );
    }

    /**
     * Return job status payload formatted for AJAX responses.
     *
     * @param string $job_id Job ID.
     * @return array|null
     */
    public static function get_job_payload( $job_id ) {
        $job = self::load_job( $job_id );
        if ( ! $job ) {
            return null;
        }

        return self::format_job_payload( $job );
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
        self::delete_archive_for_job( $job );
        self::cleanup_job( $job );
        self::clear_active_job( $job_id );
        self::clear_scheduled_job( $job_id );

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
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $job['manifest_file'] );
            } else {
                // phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                // Unlinking temporary backup/restore artifact. WP_Filesystem is not practical here.
                @unlink( $job['manifest_file'] );
                // phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
            }
        }
    }

    /**
     * Delete the in-progress archive file for a cancelled/failed job (safely, within backup directory).
     *
     * @param array $job Job state.
     * @return void
     */
    private static function delete_archive_for_job( $job ) {
        if ( empty( $job['archive_path'] ) || ! is_string( $job['archive_path'] ) ) {
            return;
        }

        $archive_path = (string) $job['archive_path'];
        if ( ! file_exists( $archive_path ) ) {
            return;
        }

        $backup_dir = backup_lite_get_backup_dir();
        if ( empty( $backup_dir ) ) {
            return;
        }

        $real_backup_dir = realpath( $backup_dir );
        $real_archive    = realpath( $archive_path );
        if ( ! $real_backup_dir || ! $real_archive ) {
            return;
        }

        $real_backup_dir = trailingslashit( wp_normalize_path( $real_backup_dir ) );
        $real_archive    = wp_normalize_path( $real_archive );

        if ( 0 !== strpos( $real_archive, $real_backup_dir ) ) {
            return;
        }

        if ( function_exists( 'wp_delete_file' ) ) {
            wp_delete_file( $real_archive );
        } else {
            // phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
            @unlink( $real_archive );
            // phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
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
        $path = self::job_state_path( $job_id );
        if ( ! file_exists( $path ) ) {
            return null;
        }

        $contents = file_get_contents( $path );
        $decoded  = json_decode( $contents, true );

        return is_array( $decoded ) ? $decoded : null;
    }

    /**
     * Convert job state into a safe payload for JS.
     *
     * @param array $job Job state.
     * @return array
     */
    public static function format_job_payload( $job ) {
        $total_files   = max( 1, (int) $job['total_files'] );
        $processed     = min( $total_files, (int) $job['processed_files'] );
        $total_bytes   = max( 1, (int) $job['total_bytes'] );
        $processed_b   = min( $total_bytes, max( 0, (int) $job['processed_bytes'] ) );
        $stage         = isset( $job['stage'] ) ? (string) $job['stage'] : '';

        // Progress model:
        // - preparing: 0–10
        // - packing: 10–95 (hybrid: file-count backbone + bytes adjustment)
        // - finalizing: 95–99
        // - completed: 100
        $files_ratio = $processed / $total_files;
        $bytes_ratio = $processed_b / $total_bytes;

        // Prefer file count for smoothness on shared hosting with many small files.
        // Weight bytes lower to avoid early jumps when a few large files are added first.
        $hybrid_ratio = ( 0.90 * $files_ratio ) + ( 0.10 * $bytes_ratio );
        $hybrid_ratio = max( 0, min( 1, $hybrid_ratio ) );

        $percentage = 0;
        if ( 'completed' === $stage || 'completed' === ( $job['status'] ?? '' ) ) {
            $percentage = 100;
        } elseif ( 'finalizing' === $stage ) {
            // Finalizing work (ZipArchive::close flush / metadata). Keep near-done but not 100.
            $percentage = 95 + (int) round( 4 * max( 0, min( 1, $bytes_ratio ) ) );
        } elseif ( 'packing' === $stage || 'running' === ( $job['status'] ?? '' ) ) {
            // Main work: 10–95.
            $percentage = 10 + (int) round( 85 * $hybrid_ratio );
        } elseif ( 'preparing' === $stage || 'pending' === ( $job['status'] ?? '' ) ) {
            // Preparing runs in background; provide stable 0–10 progression by prep_step.
            $prep_step = isset( $job['prep_step'] ) ? (string) $job['prep_step'] : '';
            $map = [
                'db'       => 1,
                'meta'     => 3,
                'archive'  => 5,
                'manifest' => 8,
                'selfcheck'=> 9,
                'done'     => 10,
            ];
            $percentage = isset( $map[ $prep_step ] ) ? (int) $map[ $prep_step ] : 0;
        } else {
            // Fallback (failed/cancelled/unknown): show best-effort progress, never 100.
            $percentage = (int) round( 10 + ( 85 * $hybrid_ratio ) );
        }

        $percentage = max( 0, min( 100, $percentage ) );
        if ( in_array( ( $job['status'] ?? '' ), [ 'failed', 'cancelled' ], true ) ) {
            $percentage = min( 99, $percentage );
        }

        $options = isset( $job['options'] ) && is_array( $job['options'] ) ? $job['options'] : [];
        $mode    = isset( $options['backup_mode_effective'] ) ? (string) $options['backup_mode_effective'] : ( isset( $options['backup_mode'] ) ? (string) $options['backup_mode'] : '' );
        $smart   = isset( $options['backup_smart_exclude_effective'] ) ? (string) $options['backup_smart_exclude_effective'] : ( isset( $options['backup_smart_exclude'] ) ? (string) $options['backup_smart_exclude'] : '' );

        if ( ! in_array( $mode, [ 'balanced', 'fast' ], true ) ) {
            $mode = '';
        }
        if ( ! in_array( $smart, [ 'on', 'off' ], true ) ) {
            $smart = '';
        }

        return [
            'id'              => $job['id'],
            'status'          => $job['status'],
            'stage'           => $job['stage'],
            'message'         => $job['message'],
            'prep_step'       => isset( $job['prep_step'] ) ? (string) $job['prep_step'] : '',
            'pack_method'     => isset( $job['pack_method'] ) ? (string) $job['pack_method'] : '',
            'processed_files' => $processed,
            'total_files'     => $total_files,
            'processed_bytes' => $processed_b,
            'total_bytes'     => $total_bytes,
            'percentage'      => $percentage,
            'attempted_files' => isset( $job['attempted_files'] ) ? (int) $job['attempted_files'] : 0,
            'added_files'     => isset( $job['added_files'] ) ? (int) $job['added_files'] : 0,
            'skipped_files'   => isset( $job['skipped_files'] ) ? (int) $job['skipped_files'] : 0,
            'skip_reasons'    => isset( $job['skip_reasons'] ) && is_array( $job['skip_reasons'] ) ? $job['skip_reasons'] : [],
            'download_url'    => isset( $job['download_url'] ) ? $job['download_url'] : '',
            'processing'      => ! empty( $job['processing'] ),
            'updated_at'      => isset( $job['updated_at'] ) ? $job['updated_at'] : '',
            'last_activity'   => isset( $job['last_activity'] ) ? (int) $job['last_activity'] : 0,
            'started_at'      => isset( $job['started_at'] ) ? (int) $job['started_at'] : 0,
            'backup_mode'     => $mode,
            'smart_exclude'   => $smart,
            'large_site_detected' => ! empty( $options['backup_large_site_detected'] ),
            'auto_threshold_files' => isset( $options['backup_auto_threshold_files'] ) ? (int) $options['backup_auto_threshold_files'] : 0,
            'auto_applied'    => ! empty( $options['backup_auto_applied'] ),
        ];
    }

    /**
     * Acquire a processing lock for the job.
     *
     * @param array $job Job state.
     * @return bool
     */
    private static function acquire_lock( &$job ) {
        $now = time();

        $job['processing']    = true;
        $job['last_activity'] = $now;
        $job['updated_at']    = current_time( 'mysql' );

        return true;
    }

    /**
     * Acquire an atomic cross-request lock using options table.
     *
     * Uses add_option() for atomicity. Value includes timestamp + token so we only release our own lock.
     *
     * @param string $job_id Job identifier.
     * @return string Lock token when acquired; empty string otherwise.
     */
    private static function acquire_option_lock( $job_id ) {
        $key   = self::get_option_lock_key( $job_id );
        $now   = time();
        $token = wp_generate_uuid4();
        $value = [
            'ts'    => $now,
            'token' => $token,
        ];

        // Atomic attempt.
        if ( add_option( $key, $value, '', 'no' ) ) {
            return $token;
        }

        // Check staleness and try to recover.
        $existing = get_option( $key );
        $ts       = 0;
        if ( is_array( $existing ) && isset( $existing['ts'] ) ) {
            $ts = (int) $existing['ts'];
        } elseif ( is_numeric( $existing ) ) {
            $ts = (int) $existing;
        } elseif ( is_string( $existing ) && preg_match( '/^(\d+)/', $existing, $matches ) ) {
            $ts = (int) $matches[1];
        }

        if ( $ts > 0 && ( $now - $ts ) > self::OPTION_LOCK_TTL ) {
            delete_option( $key );
            if ( add_option( $key, $value, '', 'no' ) ) {
                return $token;
            }
        }

        return '';
    }

    /**
     * Release the atomic job lock if owned by this request.
     *
     * @param string $job_id Job identifier.
     * @param string $token  Lock token returned by acquire_option_lock().
     * @return void
     */
    private static function release_option_lock( $job_id, $token ) {
        if ( empty( $token ) ) {
            return;
        }

        $key      = self::get_option_lock_key( $job_id );
        $existing = get_option( $key );

        if ( is_array( $existing ) && isset( $existing['token'] ) && (string) $existing['token'] === (string) $token ) {
            delete_option( $key );
        }
    }

    /**
     * Build a unique option key for the per-job lock.
     *
     * @param string $job_id Job identifier.
     * @return string
     */
    private static function get_option_lock_key( $job_id ) {
        return 'backup_lite_job_lock_' . $job_id;
    }

    /**
     * Dynamically scale batch limits to speed up large-site jobs.
     * Considers both backup size and available memory.
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

        // Scale based on backup size
        if ( $total_bytes > 5 * $gigabyte ) {
            $multiplier = 4.0;
        } elseif ( $total_bytes > 2 * $gigabyte ) {
            $multiplier = 3.0;
        } elseif ( $total_bytes > $gigabyte ) {
            $multiplier = 2.0;
        } elseif ( $average > 2 * 1024 * 1024 ) {
            $multiplier = 1.5;
        }

        // Additional scaling based on available memory
        // Check available memory and increase batch size if sufficient
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'admin' );
        }
        
        $memory_limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
        $memory_usage = memory_get_usage( true );
        $available_memory = $memory_limit > 0 ? ( $memory_limit - $memory_usage ) : 0;
        
        // Increase batch size for high-memory environments
        // Use specific batch sizes rather than multipliers for better control
        // Default: 400 files/80MB (keep current behavior)
        // High memory (> 512MB available): 600 files/120MB
        // Very high memory (> 1024MB available): 800 files/160MB
        if ( $available_memory > 1024 * 1024 * 1024 ) {
            // > 1GB available: use 800 files/160MB
            $memory_batch_files = 800;
            $memory_batch_bytes = 160 * 1024 * 1024;
        } elseif ( $available_memory > 512 * 1024 * 1024 ) {
            // > 512MB available: use 600 files/120MB
            $memory_batch_files = 600;
            $memory_batch_bytes = 120 * 1024 * 1024;
        } else {
            // Default: keep original values
            $memory_batch_files = $max_files;
            $memory_batch_bytes = $max_bytes;
        }

        // Apply backup size scaling to memory-based batch sizes
        $scaled_files = (int) round( $memory_batch_files * $multiplier );
        $scaled_bytes = (int) round( $memory_batch_bytes * $multiplier );

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
    }

    /**
     * Clear any scheduled processing events for a given job.
     *
     * @param string $job_id Job identifier.
     */
    private static function clear_scheduled_job( $job_id ) {
        // Remove all scheduled events for this job to prevent duplicate processing.
        if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
            wp_clear_scheduled_hook( self::CRON_HOOK, [ $job_id ] );
        } else {
            // Fallback: attempt to unschedule the next scheduled event (best-effort).
            $timestamp = wp_next_scheduled( self::CRON_HOOK, [ $job_id ] );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, self::CRON_HOOK, [ $job_id ] );
            }
        }
    }
}

