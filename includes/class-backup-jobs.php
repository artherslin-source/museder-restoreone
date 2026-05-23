<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Museder_Restoreone_Backup_Jobs {

    const STATE_OPTION      = 'museder_restoreone_active_job';
    const OPTION_LOCK_PREFIX = 'museder_restoreone_job_lock_';
    const CRON_HOOK         = 'museder_restoreone_process_job';
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

        $context = Museder_Restoreone_Backup::create_async_job_stub_context( $job_id, $options );

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
            'pack_method'     => museder_restoreone_can_use_ziparchive() ? 'ziparchive' : 'pclzip',
            'selfcheck'       => [],
            'prep_step'       => 'db',
            'archive_path'    => $context['archive_path'],
            'download_url'    => museder_restoreone_get_download_url( $context['archive_path'] ),
            'temp_dir'        => $context['temp_dir'],
            'manifest_file'   => $context['manifest_file'],
            'manifest_ndjson_file' => $context['manifest_ndjson_file'] ?? '',
            // Byte offset into manifest.ndjson for resumable packing.
            'manifest_offset' => 0,
            // One-time migration marker for legacy manifest.json -> manifest.ndjson.
            'manifest_migrated' => false,
            'sql_path'        => $context['sql_path'],
            'meta_path'       => $context['meta_path'],
            'options'         => $context['options'],
            'last_activity'   => time(),
            // Last cron/AJAX tick timestamp (UTC). Used for debugging and stale detection.
            'last_tick'       => 0,
            'started_at'      => time(), // Record backup start time (UTC timestamp)
            // Cancellation is cooperative across cron/AJAX ticks. UI sets cancel_requested; workers must honor it.
            'cancel_requested' => false,
            'cancel_requested_at' => 0,
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
        if ( self::is_terminal_or_cancel_requested( $job ) ) {
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
            $job['last_tick'] = time();

            // If the job is still in preparing stage, do not force packing yet.
            // Preparing may take time (DB dump/manifest/self-check) and should run in background.
            if ( empty( $job['stage'] ) ) {
                $job['stage'] = 'preparing';
            }
        // IMPORTANT: Do not force status back to running here.
        // Cancelled jobs must remain cancelled; in-flight requests should not resurrect them.
        // Status/stage are managed by the pipeline steps themselves.
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
        
        // Dynamic time budget calculation.
        // Use 75% of max_execution_time, but ensure minimum of 25 seconds; cap at 90 seconds.
        // Note: We apply a hard cap below to avoid very long single requests.
        $time_budget = max( 25, min( (int) ( $max_execution_time * 0.75 ), 90 ) );

        // Hard cap to keep requests short on strict shared hosting (prevents 150s+ requests when a single batch is slow).
        // For AJAX, keep the budget smaller to avoid long pending requests (which makes the progress UI appear "stuck then jump").
        $hard_budget = 25;
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
            $hard_budget = 12;
        }
        $time_budget = min( $time_budget, $hard_budget );
        $start_microtime = microtime( true ); // Use microtime for precise timing
        $processed_bytes_start = isset( $job['processed_bytes'] ) ? (int) $job['processed_bytes'] : 0;
        $batch_count = 0;
        $max_batches = 100; // Safety limit to prevent infinite loops
        
        // State save optimization: track when to save (every 5 batches or every 3 seconds)
        $last_save_time = $start_microtime;
        $save_interval_batches = 5;
        $save_interval_seconds = 3.0;

        // Open ZipArchive once for the entire time budget loop to reduce I/O overhead.
        // Never keep ZipArchive open while pack_method is pclzip: PclZip mutates the same file and
        // ZipArchive::close() can take minutes on huge archives or clobber PclZip's central directory.
        $zip = null;
        $pack_method_for_handle = isset( $job['pack_method'] ) ? (string) $job['pack_method'] : '';
        $reuse_zip_handle        = museder_restoreone_can_use_ziparchive()
            && 'pclzip' !== $pack_method_for_handle
            && ! empty( $job['archive_path'] )
            && file_exists( (string) $job['archive_path'] );
        // Only keep ZipArchive open while we are actively packing.
        // Finalizing should run after close (and may be sliced across multiple cron ticks).
        if ( isset( $job['stage'] ) && 'packing' === $job['stage'] && $reuse_zip_handle ) {
            $zip = new ZipArchive();
            if ( true === $zip->open( $job['archive_path'], ZipArchive::CREATE ) ) {
                museder_restoreone_log( 'info', 'Opened ZipArchive for time budget loop.', [
                    'job_id' => $job_id,
                ] );
            } else {
                // If opening fails, set to null so we fall back to per-batch opening
                $zip = null;
                museder_restoreone_log( 'warning', 'Failed to open ZipArchive for time budget loop, will open per batch.', [
                    'job_id' => $job_id,
                ] );
            }
        }

        $job_completed_in_loop = false;
        $job_needs_finalize    = false;
        $last_cancel_check     = 0.0;

        // Adaptive packing limits: if a host is slow (large files / slow disk / ZipArchive close overhead),
        // shrink batch sizes automatically to keep each request within time budget and avoid "stuck at 95%".
        $adaptive = isset( $job['adaptive_pack'] ) && is_array( $job['adaptive_pack'] ) ? $job['adaptive_pack'] : [];
        $adaptive_max_files = isset( $adaptive['max_files'] ) ? (int) $adaptive['max_files'] : 0;
        $adaptive_max_bytes = isset( $adaptive['max_bytes'] ) ? (int) $adaptive['max_bytes'] : 0;
        if ( $adaptive_max_files > 0 ) {
            $max_files = min( $max_files, $adaptive_max_files );
        }
        if ( $adaptive_max_bytes > 0 ) {
            $max_bytes = min( $max_bytes, $adaptive_max_bytes );
        }

            try {
            // Time budget loop: process multiple batches until time limit or job completion
            // Use microtime for more precise timing
            while ( $batch_count < $max_batches ) {
                // Cooperative cancellation: reload state periodically to detect cancel_requested even if a request is in-flight.
                // This prevents "cancel" from being overwritten by a long-running request.
                $now_micro = microtime( true );
                if ( ( $now_micro - $last_cancel_check ) >= 1.0 ) {
                    $last_cancel_check = $now_micro;
                    $fresh = self::load_job( $job_id );
                    if ( is_array( $fresh ) ) {
                        $cancel_requested = ! empty( $fresh['cancel_requested'] ) || ( isset( $fresh['status'] ) && 'cancelled' === $fresh['status'] ) || ( isset( $fresh['stage'] ) && 'cancelled' === $fresh['stage'] );
                        if ( $cancel_requested ) {
                            // Mark cancelled and stop further work ASAP.
                            $job['cancel_requested']    = true;
                            $job['cancel_requested_at'] = isset( $fresh['cancel_requested_at'] ) ? (int) $fresh['cancel_requested_at'] : time();
                            $job['status']              = 'cancelled';
                            $job['stage']               = 'cancelled';
                            $job['message']             = __( 'Backup cancelled by user.', 'museder-restoreone' );
                            $job_needs_finalize          = false;
                            $job_completed_in_loop       = true;
                            break;
                        }
                    }
                }

                // Check if we've exceeded time budget (using microtime for precision)
                $elapsed = microtime( true ) - $start_microtime;
                if ( $elapsed >= $time_budget ) {
                    museder_restoreone_log( 'info', 'Time budget reached, scheduling next batch.', [
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
                    $job = Museder_Restoreone_Backup::run_preparing_stage( $job );
                    $batch_count++;

                    // Move to packing when preparing is done.
                    if ( isset( $job['prep_step'] ) && 'done' === $job['prep_step'] && 'failed' !== $job['status'] ) {
                        $job['stage']  = 'packing';
                        $job['status'] = 'running';
                    }

                    // If archive is now available and we can reuse ZipArchive, open it (never alongside PclZip packing).
                    $pm = isset( $job['pack_method'] ) ? (string) $job['pack_method'] : '';
                    if ( null === $zip && 'packing' === $job['stage'] && museder_restoreone_can_use_ziparchive() && 'pclzip' !== $pm && ! empty( $job['archive_path'] ) && file_exists( $job['archive_path'] ) ) {
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

                // Finalizing stage: skip packing and run finalize steps (sliced) after ZipArchive close.
                // Without this, we keep re-entering packing, re-opening ZipArchive, and never make forward progress
                // when finalize work is heavy (metadata embed/verify).
                if ( isset( $job['stage'] ) && 'finalizing' === $job['stage'] ) {
                    $job_needs_finalize = true;
                    break;
                }

                // Process one packing batch (reuse ZipArchive if available)
                $batch_started_at = microtime( true );
                $pointer_before   = isset( $job['pointer'] ) ? (int) $job['pointer'] : 0;
                $processed_before = isset( $job['processed_bytes'] ) ? (int) $job['processed_bytes'] : 0;
                $job = Museder_Restoreone_Backup::process_job_batch( $job, $max_files, $max_bytes, $zip );
                $batch_elapsed = microtime( true ) - $batch_started_at;
                $pointer_after   = isset( $job['pointer'] ) ? (int) $job['pointer'] : $pointer_before;
                $processed_after = isset( $job['processed_bytes'] ) ? (int) $job['processed_bytes'] : $processed_before;
                $delta_entries   = max( 0, $pointer_after - $pointer_before );
                $delta_bytes     = max( 0, $processed_after - $processed_before );

                // Gate A: after each long batch returns, reload latest persisted state before any scheduling/save.
                // If user cancelled during the batch, treat it as terminal immediately.
                $latest_after_batch = self::load_latest_job_state( $job_id );
                if ( self::is_terminal_or_cancel_requested( $latest_after_batch ) ) {
                    $job = self::merge_with_latest_terminal_state( $job, $latest_after_batch );
                    $job_needs_finalize = false;
                    $job_completed_in_loop = true;
                    break;
                }

                // If a single batch is slower than the time budget, shrink batch limits for the next tick.
                // This cannot preempt the current long operation, but prevents repeated 30-80s requests that make UI look stuck.
                if ( $batch_elapsed > (float) $time_budget ) {
                    $new_max_files = max( 10, (int) floor( $max_files / 2 ) );
                    $new_max_bytes = max( 4 * 1024 * 1024, (int) floor( $max_bytes / 2 ) );
                    $job['adaptive_pack'] = [
                        'max_files' => $new_max_files,
                        'max_bytes' => $new_max_bytes,
                        'last_batch_seconds' => round( $batch_elapsed, 2 ),
                    ];
                    museder_restoreone_log( 'warning', 'Packing batch exceeded time budget; shrinking batch limits for next tick.', [
                        'job_id' => $job_id,
                        'time_budget' => $time_budget,
                        'batch_seconds' => round( $batch_elapsed, 2 ),
                        'entries' => $delta_entries,
                        'bytes' => $delta_bytes,
                        'next_max_files' => $new_max_files,
                        'next_max_bytes' => $new_max_bytes,
                    ] );

                    // Improve UI message so users understand it is still working.
                    $job['message'] = sprintf(
                        /* translators: 1: seconds */
                        __( 'Packing large files… (last step took %1$s seconds). Please keep this tab open.', 'museder-restoreone' ),
                        number_format_i18n( round( $batch_elapsed, 1 ), 1 )
                    );
                } elseif ( $batch_elapsed > 0 && $delta_bytes > 0 && isset( $job['adaptive_pack'] ) && is_array( $job['adaptive_pack'] ) ) {
                    // Slowly relax limits again when host is stable (avoid being stuck at very tiny batches forever).
                    $cur_files = isset( $job['adaptive_pack']['max_files'] ) ? (int) $job['adaptive_pack']['max_files'] : 0;
                    $cur_bytes = isset( $job['adaptive_pack']['max_bytes'] ) ? (int) $job['adaptive_pack']['max_bytes'] : 0;
                    if ( $cur_files > 0 && $cur_bytes > 0 && $batch_elapsed < ( (float) $time_budget * 0.5 ) ) {
                        $job['adaptive_pack']['max_files'] = min( $cur_files + 25, 500 );
                        $job['adaptive_pack']['max_bytes'] = min( $cur_bytes + ( 2 * 1024 * 1024 ), 64 * 1024 * 1024 );
                        $job['adaptive_pack']['last_batch_seconds'] = round( $batch_elapsed, 2 );
                    }
                }
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
                    // If packing finished and we still have the ZipArchive open, embed metadata BEFORE close.
                    // This avoids re-opening a huge ZIP during finalize (slow on many shared hosts).
                    if ( $job_needs_finalize && isset( $job['status'] ) && 'running' === $job['status'] ) {
                        try {
                            if ( class_exists( 'Museder_Restoreone_Backup' ) ) {
                                Museder_Restoreone_Backup::embed_metadata_into_archive_before_close( $job, $zip );
                                $job['finalize_step'] = 'verify';
                            }
                        } catch ( Throwable $embed_throwable ) {
                            museder_restoreone_log( 'error', 'Failed to embed backup metadata before closing ZipArchive.', [
                                'job_id' => $job_id,
                                'error'  => $embed_throwable->getMessage(),
                            ] );
                            $job['status']  = 'failed';
                            $job['stage']   = 'failed';
                            $job['message'] = __( 'Unable to finalize backup archive. Please check logs and try again.', 'museder-restoreone' );
                            $job_needs_finalize = false;
                        }
                    }

                    $closed = $zip->close();
                    if ( false === $closed ) {
                        throw new RuntimeException( 'ZipArchive::close() returned false.' );
                    }
                    museder_restoreone_log( 'info', 'Closed ZipArchive after time budget loop.', [
                        'job_id' => $job_id,
                        'batches_processed' => $batch_count,
                        'completed' => $job_completed_in_loop,
                    ] );
                } catch ( Throwable $throwable ) {
                    // Prevent fatal "Invalid or uninitialized Zip object" from breaking AJAX polling (500).
                    museder_restoreone_log( 'warning', 'Failed to close ZipArchive after time budget loop.', [
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

            // If job was cancelled during the loop, perform cleanup and stop here.
            if ( isset( $job['status'] ) && 'cancelled' === $job['status'] ) {
                return self::finalize_cancelled_job_state( $job_id, $job );
            }

            // If packing finished, finalize AFTER close so filesize/metadata are accurate.
            if ( $job_needs_finalize && ! in_array( $job['status'], [ 'failed', 'cancelled' ], true ) ) {
                $total_files     = isset( $job['total_files'] ) ? (int) $job['total_files'] : 0;
                $pointer         = isset( $job['pointer'] ) ? (int) $job['pointer'] : 0;
                $processed_files = isset( $job['processed_files'] ) ? (int) $job['processed_files'] : 0;

                if ( $total_files > 0 && max( $pointer, $processed_files ) < $total_files ) {
                    // Safety: do not finalize early (would produce an incomplete archive).
                    museder_restoreone_log( 'warning', 'Deferring finalize because packing is not complete.', [
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
                    $job = Museder_Restoreone_Backup::finalize_async_job_after_close( $job );
                    // Finalize may be sliced (still "running"); only treat as completed if the job is terminal.
                    $job_completed_in_loop = isset( $job['status'] ) && in_array( $job['status'], [ 'completed', 'failed', 'cancelled' ], true );
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
                museder_restoreone_log( 'info', 'Processed multiple batches in single request.', [
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
                museder_restoreone_log( 'warning', 'Closed ZipArchive after exception.', [
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
                // Gate B: before scheduling, reload latest persisted state defensively.
                $latest_before_schedule = self::load_latest_job_state( $job_id );
                if ( self::is_terminal_or_cancel_requested( $latest_before_schedule ) ) {
                    $job = self::merge_with_latest_terminal_state( $job, $latest_before_schedule );
                    if ( isset( $job['status'] ) && 'cancelled' === $job['status'] ) {
                        return self::finalize_cancelled_job_state( $job_id, $job );
                    }
                }

                // Job is still running - schedule next batch with short interval (10-20 seconds)
                // This implements "short interval single event" scheduling for cron mode
                self::schedule_next_batch( $job_id, $job );
            }

            // Request is ending: mark processing false so UI may nudge/cron may continue.
            $job['processing']    = false;
            $job['last_activity'] = time();
            $job['updated_at']    = current_time( 'mysql' );
            // Gate C: final save merges persisted terminal state to avoid stale running overwrite.
            $job = self::merge_with_latest_terminal_state( $job, self::load_latest_job_state( $job_id ) );
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
        $latest = self::load_latest_job_state( $job_id );
        if ( self::is_terminal_or_cancel_requested( $latest ) ) {
            museder_restoreone_log( 'info', 'Skip scheduling next batch because latest state is terminal/cancelled.', [
                'job_id'  => $job_id,
                'status'  => is_array( $latest ) && isset( $latest['status'] ) ? (string) $latest['status'] : '',
                'stage'   => is_array( $latest ) && isset( $latest['stage'] ) ? (string) $latest['stage'] : '',
                'reason'  => 'latest_terminal_or_cancelled',
            ] );
            return;
        }

        // Only schedule if job is in preparing/packing/finalizing stage
        // Finalizing may be sliced across multiple ticks (metadata embed/verify).
        if ( ! in_array( $job['stage'], [ 'preparing', 'packing', 'finalizing' ], true ) ) {
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

        museder_restoreone_log( 'info', 'Scheduled next batch with short interval.', [
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

        museder_restoreone_log( 'info', 'Backup job cancelled by user.', [
            'job_id' => $job_id,
            'stage'  => $job['stage'] ?? '',
            'status' => $job['status'] ?? '',
        ] );

        // Mark cancellation intent (for in-flight request detection).
        $job['cancel_requested']    = true;
        $job['cancel_requested_at'] = time();

        $job['status']  = 'cancelled';
        $job['stage']   = 'cancelled';
        $job['message'] = __( 'Backup cancelled by user.', 'museder-restoreone' );
        $job['processing'] = false;
        self::save_job( $job );
        self::clear_scheduled_job( $job_id );

        // Best-effort immediate cleanup: only do destructive operations if we can acquire the per-job option lock.
        // This avoids racing with an in-flight request that is still writing the archive.
        $token = self::acquire_option_lock( $job_id );
        if ( ! empty( $token ) ) {
            try {
                self::finalize_cancelled_job_state( $job_id, $job );
            } finally {
                self::release_option_lock( $job_id, $token );
            }
        }

        return true;
    }

    /**
     * Remove temp and manifest files for a finished job.
     *
     * @param array $job Job state.
     */
    public static function cleanup_job( $job ) {
        if ( ! empty( $job['temp_dir'] ) && is_dir( $job['temp_dir'] ) ) {
            museder_restoreone_delete_directory( $job['temp_dir'] );
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

        if ( ! empty( $job['manifest_ndjson_file'] ) && file_exists( $job['manifest_ndjson_file'] ) ) {
            // @plugin-check: allowed - required for backup/restore file operations
            // Path is validated and sanitized before use
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $job['manifest_ndjson_file'] );
            } else {
                // phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                // Unlinking temporary backup/restore artifact. WP_Filesystem is not practical here.
                @unlink( $job['manifest_ndjson_file'] );
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

        $backup_dir = museder_restoreone_get_backup_dir();
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
            museder_restoreone_ensure_directory( $dir );
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
     * Reload latest persisted job state from disk.
     *
     * @param string $job_id Job identifier.
     * @return array|null
     */
    private static function load_latest_job_state( $job_id ) {
        return self::load_job( $job_id );
    }

    /**
     * Whether the job should be treated as terminal for scheduling/saving.
     *
     * @param array|null $job Job state.
     * @return bool
     */
    private static function is_terminal_or_cancel_requested( $job ) {
        if ( ! is_array( $job ) ) {
            return false;
        }

        if ( ! empty( $job['cancel_requested'] ) ) {
            return true;
        }

        $status = isset( $job['status'] ) ? (string) $job['status'] : '';
        $stage  = isset( $job['stage'] ) ? (string) $job['stage'] : '';

        return in_array( $status, [ 'cancelled', 'failed', 'completed' ], true ) || 'cancelled' === $stage;
    }

    /**
     * Merge in-memory state with latest persisted terminal state to prevent stale overwrite.
     *
     * @param array      $in_memory In-memory job state.
     * @param array|null $latest    Latest persisted state.
     * @return array
     */
    private static function merge_with_latest_terminal_state( $in_memory, $latest ) {
        if ( ! is_array( $in_memory ) ) {
            $in_memory = [];
        }
        if ( ! self::is_terminal_or_cancel_requested( $latest ) ) {
            return $in_memory;
        }

        // Persisted state wins when terminal/cancelled to avoid stale running resurrection.
        return array_merge( $in_memory, $latest );
    }

    /**
     * Finalize a cancelled state with consistent cleanup and persistence.
     *
     * @param string $job_id Job identifier.
     * @param array  $job    Current job state.
     * @return array
     */
    private static function finalize_cancelled_job_state( $job_id, $job ) {
        if ( ! is_array( $job ) ) {
            $job = [];
        }

        $job['id'] = isset( $job['id'] ) ? (string) $job['id'] : (string) $job_id;
        $job['cancel_requested']    = true;
        $job['cancel_requested_at'] = isset( $job['cancel_requested_at'] ) && (int) $job['cancel_requested_at'] > 0 ? (int) $job['cancel_requested_at'] : time();
        $job['status']              = 'cancelled';
        $job['stage']               = 'cancelled';
        $job['message']             = isset( $job['message'] ) && '' !== (string) $job['message'] ? (string) $job['message'] : __( 'Backup cancelled by user.', 'museder-restoreone' );
        $job['processing']          = false;
        $job['last_activity']       = time();
        $job['updated_at']          = current_time( 'mysql' );

        museder_restoreone_log( 'info', 'Cancellation detected after batch; stopping job.', [
            'job_id' => $job_id,
            'stage'  => $job['stage'],
            'status' => $job['status'],
        ] );

        self::delete_archive_for_job( $job );
        self::cleanup_job( $job );
        self::clear_active_job( $job_id );
        self::clear_scheduled_job( $job_id );
        self::save_job( $job );

        return $job;
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

        $options = isset( $job['options'] ) && is_array( $job['options'] ) ? $job['options'] : [];
        $mode    = isset( $options['backup_mode_effective'] ) ? (string) $options['backup_mode_effective'] : ( isset( $options['backup_mode'] ) ? (string) $options['backup_mode'] : '' );
        $smart   = isset( $options['backup_smart_exclude_effective'] ) ? (string) $options['backup_smart_exclude_effective'] : ( isset( $options['backup_smart_exclude'] ) ? (string) $options['backup_smart_exclude'] : '' );
        $artifact_labels = [];
        if ( isset( $options['backup_auto_excluded_artifacts'] ) && is_array( $options['backup_auto_excluded_artifacts'] ) ) {
            foreach ( $options['backup_auto_excluded_artifacts'] as $item ) {
                if ( ! is_array( $item ) || empty( $item['label'] ) ) {
                    continue;
                }
                $artifact_labels[] = (string) $item['label'];
            }
        }
        $artifact_labels = array_values( array_unique( $artifact_labels ) );

        if ( ! in_array( $mode, [ 'balanced', 'fast' ], true ) ) {
            $mode = '';
        }
        if ( ! in_array( $smart, [ 'on', 'off' ], true ) ) {
            $smart = '';
        }

        $progress = self::build_progress_payload( $job, $processed, $total_files, $processed_b, $total_bytes );

        return [
            'id'              => $job['id'],
            'status'          => $job['status'],
            'stage'           => $job['stage'],
            'message'         => $job['message'],
            'prep_step'       => isset( $job['prep_step'] ) ? (string) $job['prep_step'] : '',
            'pack_method'     => isset( $job['pack_method'] ) ? (string) $job['pack_method'] : '',
            'repack_attempted'=> ! empty( $job['repack_attempted'] ),
            'finalize_step'   => isset( $job['finalize_step'] ) ? (string) $job['finalize_step'] : '',
            'progress_mode'   => $progress['progress_mode'],
            'progress_basis'  => $progress['progress_basis'],
            'stage_done'      => $progress['stage_done'],
            'stage_total'     => $progress['stage_total'],
            'stage_progress'  => $progress['stage_progress'],
            'overall_progress'=> $progress['overall_progress'],
            'processed_files' => $processed,
            'total_files'     => $total_files,
            'processed_bytes' => $processed_b,
            'total_bytes'     => $total_bytes,
            // Keep legacy field for backward compatibility with older UI builds.
            'percentage'      => (int) round( $progress['overall_progress'] ),
            'attempted_files' => isset( $job['attempted_files'] ) ? (int) $job['attempted_files'] : 0,
            'added_files'     => isset( $job['added_files'] ) ? (int) $job['added_files'] : 0,
            'skipped_files'   => isset( $job['skipped_files'] ) ? (int) $job['skipped_files'] : 0,
            'skip_reasons'    => isset( $job['skip_reasons'] ) && is_array( $job['skip_reasons'] ) ? $job['skip_reasons'] : [],
            // Include small number of diagnostic samples so UI can explain what was skipped (e.g. too_large >2GB).
            // This is safe: paths are within the site filesystem and are shown only to admins.
            'diagnostic_samples' => isset( $job['diagnostic_samples'] ) && is_array( $job['diagnostic_samples'] ) ? array_slice( $job['diagnostic_samples'], 0, 20 ) : [],
            'download_url'    => isset( $job['download_url'] ) ? $job['download_url'] : '',
            'processing'      => ! empty( $job['processing'] ),
            'updated_at'      => isset( $job['updated_at'] ) ? $job['updated_at'] : '',
            'last_activity'   => isset( $job['last_activity'] ) ? (int) $job['last_activity'] : 0,
            'started_at'      => isset( $job['started_at'] ) ? (int) $job['started_at'] : 0,
            'cancel_requested'=> ! empty( $job['cancel_requested'] ),
            'backup_mode'     => $mode,
            'smart_exclude'   => $smart,
            'large_site_detected' => ! empty( $options['backup_large_site_detected'] ),
            'auto_threshold_files' => isset( $options['backup_auto_threshold_files'] ) ? (int) $options['backup_auto_threshold_files'] : 0,
            'auto_applied'    => ! empty( $options['backup_auto_applied'] ),
            'auto_excluded_artifact_count' => isset( $options['backup_auto_excluded_artifact_count'] ) ? (int) $options['backup_auto_excluded_artifact_count'] : count( $artifact_labels ),
            'auto_excluded_artifact_labels' => array_slice( $artifact_labels, 0, 5 ),
            'large_artifact_warnings' => isset( $job['large_artifact_warnings'] ) && is_array( $job['large_artifact_warnings'] ) ? array_slice( $job['large_artifact_warnings'], 0, 5 ) : [],
        ];
    }

    /**
     * Build normalized progress payload (stage + overall).
     *
     * @param array $job Job state.
     * @param int   $processed_files Processed files.
     * @param int   $total_files Total files.
     * @param int   $processed_bytes Processed bytes.
     * @param int   $total_bytes Total bytes.
     * @return array<string,mixed>
     */
    private static function build_progress_payload( $job, $processed_files, $total_files, $processed_bytes, $total_bytes ) {
        $stage  = isset( $job['stage'] ) ? (string) $job['stage'] : '';
        $status = isset( $job['status'] ) ? (string) $job['status'] : '';

        $payload = [
            'progress_mode'   => 'determinate',
            'progress_basis'  => 'files',
            'stage_done'      => 0,
            'stage_total'     => 0,
            'stage_progress'  => 0.0,
            'overall_progress'=> 0.0,
        ];

        // Terminal states.
        if ( 'completed' === $status || 'completed' === $stage ) {
            $payload['progress_basis']   = 'steps';
            $payload['stage_done']       = 1;
            $payload['stage_total']      = 1;
            $payload['stage_progress']   = 100.0;
            $payload['overall_progress'] = 100.0;
            return $payload;
        }

        // Preparing: deterministic by prep step count (0-10% of overall).
        if ( 'preparing' === $stage || 'pending' === $stage ) {
            $map = [
                'db'       => 1,
                'meta'     => 2,
                'archive'  => 3,
                'manifest' => 4,
                'selfcheck'=> 5,
                'done'     => 5,
            ];
            $prep_step = isset( $job['prep_step'] ) ? (string) $job['prep_step'] : '';
            $done      = isset( $map[ $prep_step ] ) ? (int) $map[ $prep_step ] : 0;
            $total     = 5;
            $ratio     = $total > 0 ? ( $done / $total ) : 0.0;

            $payload['progress_basis']   = 'steps';
            $payload['stage_done']       = $done;
            $payload['stage_total']      = $total;
            $payload['stage_progress']   = round( 100.0 * $ratio, 2 );
            $payload['overall_progress'] = round( 10.0 * $ratio, 2 );
            return $payload;
        }

        // Packing: use real file progress (10-95% of overall).
        if ( 'packing' === $stage || ( '' === $stage && 'running' === $status ) ) {
            $done  = max( 0, min( $processed_files, $total_files ) );
            $total = max( 1, $total_files );
            $ratio = $done / $total;

            $payload['progress_basis']   = 'files';
            $payload['stage_done']       = (int) $done;
            $payload['stage_total']      = (int) $total;
            $payload['stage_progress']   = round( 100.0 * $ratio, 2 );
            $payload['overall_progress'] = round( 10.0 + ( 85.0 * $ratio ), 2 );
            return $payload;
        }

        // Finalizing: deterministic by finalize step (95-99% of overall).
        if ( 'finalizing' === $stage ) {
            $finalize_map = [
                'embed_meta' => 1,
                'verify'     => 2,
                'close'      => 3,
                'complete'   => 4,
                'done'       => 4,
            ];
            $finalize_step = isset( $job['finalize_step'] ) ? (string) $job['finalize_step'] : '';
            $done          = isset( $finalize_map[ $finalize_step ] ) ? (int) $finalize_map[ $finalize_step ] : 0;
            $total         = 4;
            if ( $done <= 0 ) {
                // Unknown sub-step: do not fake detailed progress.
                $payload['progress_mode']   = 'indeterminate';
                $payload['progress_basis']  = 'steps';
                $payload['stage_done']      = 0;
                $payload['stage_total']     = 0;
                $payload['stage_progress']  = 0.0;
                $payload['overall_progress']= 95.0;
                return $payload;
            }

            $ratio = $done / $total;
            $payload['progress_basis']   = 'steps';
            $payload['stage_done']       = $done;
            $payload['stage_total']      = $total;
            $payload['stage_progress']   = round( 100.0 * $ratio, 2 );
            $payload['overall_progress'] = round( 95.0 + ( 4.0 * $ratio ), 2 );
            return $payload;
        }

        // Cancelled/failed/unknown fallback: keep best effort but never fake 100.
        $done_files  = max( 0, min( $processed_files, $total_files ) );
        $files_ratio = $total_files > 0 ? ( $done_files / $total_files ) : 0.0;
        $bytes_ratio = $total_bytes > 0 ? ( max( 0, min( $processed_bytes, $total_bytes ) ) / $total_bytes ) : 0.0;
        $hybrid      = max( 0.0, min( 1.0, ( 0.90 * $files_ratio ) + ( 0.10 * $bytes_ratio ) ) );
        $overall     = round( 10.0 + ( 85.0 * $hybrid ), 2 );
        if ( in_array( $status, [ 'failed', 'cancelled' ], true ) ) {
            $overall = min( 99.0, $overall );
        }

        $payload['progress_basis']   = 'files';
        $payload['stage_done']       = (int) $done_files;
        $payload['stage_total']      = (int) max( 1, $total_files );
        $payload['stage_progress']   = round( 100.0 * $files_ratio, 2 );
        $payload['overall_progress'] = $overall;
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
        // WordPress.org review: option name prefix must be literal at add_option() call sites (not only via indirect $key).
        $option_key = self::OPTION_LOCK_PREFIX . sanitize_key( (string) $job_id );
        $now        = time();
        $token      = wp_generate_uuid4();
        $value      = [
            'ts'    => $now,
            'token' => $token,
        ];

        // Atomic attempt (prefix visible above for static analysis).
        if ( add_option( $option_key, $value, '', 'no' ) ) {
            return $token;
        }

        // Check staleness and try to recover.
        $existing = get_option( $option_key );
        $ts       = 0;
        if ( is_array( $existing ) && isset( $existing['ts'] ) ) {
            $ts = (int) $existing['ts'];
        } elseif ( is_numeric( $existing ) ) {
            $ts = (int) $existing;
        } elseif ( is_string( $existing ) && preg_match( '/^(\d+)/', $existing, $matches ) ) {
            $ts = (int) $matches[1];
        }

        if ( $ts > 0 && ( $now - $ts ) > self::OPTION_LOCK_TTL ) {
            delete_option( $option_key );
            if ( add_option( $option_key, $value, '', 'no' ) ) {
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
        // Keep the plugin-specific option prefix statically visible for review tooling and human audits.
        // Job IDs are generated by our plugin, but we still sanitize defensively for storage.
        return self::OPTION_LOCK_PREFIX . sanitize_key( (string) $job_id );
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
        $limits = apply_filters( 'museder_restoreone_job_batch_limits', $limits, $job );

        $limits['max_files'] = (int) max( 50, $limits['max_files'] );
        $limits['max_bytes'] = (int) max( 10 * 1024 * 1024, $limits['max_bytes'] );

        return $limits;
    }

    private static function job_state_path( $job_id ) {
        return trailingslashit( museder_restoreone_get_jobs_dir() ) . $job_id . '.json';
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

