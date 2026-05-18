<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Museder_Restoreone_Restore_Service {

    const JOB_META_EXTENSION = '.json';
    const REPORT_TYPE_DRYRUN = 'dryrun';
    const CRON_HOOK_PROCESS  = 'museder_restoreone_restore_service_process_job';
    const CRON_HOOK_BG_CLEANUP = 'museder_restoreone_restore_service_background_cleanup';
    const DEFAULT_SLICE_SECONDS = 10;
    const ACTIVE_JOB_OPTION = 'museder_restoreone_restore_service_active_job_id';
    const ZIP_WP_CONTENT_PREFIX = 'wp-content/';
    const WPRESS_DB_FILES = [ 'database.ndjson' ];
    const WPRESS_FILES_EXCLUDE = [ 'database.ndjson', 'package.json', 'multisite.json', 'blogs.json' ];

    /**
     * Find a ZIP entry name by matching basename (case-insensitive).
     * This allows archives that store db files under subdirectories, e.g. "site/database.ndjson".
     *
     * @param string $zip_path
     * @param string $wanted_basename e.g. "database.ndjson"
     * @return string Entry name inside the zip, or empty string if not found.
     */
    protected static function find_zip_entry_by_basename( $zip_path, $wanted_basename ) {
        $zip_path        = wp_normalize_path( (string) $zip_path );
        $wanted_basename = strtolower( (string) $wanted_basename );
        if ( '' === $zip_path || '' === $wanted_basename || ! file_exists( $zip_path ) ) {
            return '';
        }
        if ( ! class_exists( 'ZipArchive' ) ) {
            return '';
        }

        $zip = new ZipArchive();
        $ok  = $zip->open( $zip_path );
        $ok_code = defined( 'ZipArchive::ER_OK' ) ? ZipArchive::ER_OK : 0;
        if ( true !== $ok && $ok_code !== $ok ) {
            return '';
        }

        $found = '';
        for ( $i = 0; $i < (int) $zip->numFiles; $i++ ) {
            $name = (string) $zip->getNameIndex( $i );
            if ( '' === $name ) {
                continue;
            }
            if ( $wanted_basename === strtolower( basename( $name ) ) ) {
                $found = $name;
                break;
            }
        }
        $zip->close();
        return (string) $found;
    }

    public static function init() {
        add_action( self::CRON_HOOK_PROCESS, [ __CLASS__, 'cron_process_job' ], 10, 1 );
        add_action( self::CRON_HOOK_BG_CLEANUP, [ __CLASS__, 'cron_background_cleanup' ], 10, 1 );
    }

    /**
     * Prepare a restore job by validating input and recording metadata.
     *
     * @param string $source upload|existing
     * @param string $file   filename or path
     * @param string $sha1   optional checksum
     *
     * @return array
     */
    public static function prepare( $source, $file, $sha1 = '' ) {
        $source = strtolower( (string) $source );
        // Note: legacy flows may pass 'remote' (remote download stored into backups dir).
        if ( ! in_array( $source, [ 'upload', 'existing', 'remote' ], true ) ) {
            throw new InvalidArgumentException( esc_html__( 'Invalid restore source.', 'museder-restoreone' ) );
        }

        $file_name = sanitize_file_name( wp_unslash( $file ) );
        if ( empty( $file_name ) ) {
            throw new InvalidArgumentException( esc_html__( 'Invalid restore file name.', 'museder-restoreone' ) );
        }

        // WP.org submission build: only support ZIP archives.
        $allowed_ext = [ 'zip' ];
        $ext         = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, $allowed_ext, true ) ) {
            throw new InvalidArgumentException( esc_html__( 'Unsupported backup extension.', 'museder-restoreone' ) );
        }

        // Use helper function to get absolute path from file name
        $file_path = museder_restoreone_get_backup_path( $file_name );

        if ( ! $file_path ) {
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
                'filename' => $file_name,
                'source' => $source,
                'backups_dir' => $backups_dir,
                'backups_dir_exists' => is_dir( $backups_dir ),
                'backups_dir_readable' => is_dir( $backups_dir ) ? is_readable( $backups_dir ) : false,
                'available_files_sample' => $backup_files,
            ] );
            
            throw new RuntimeException( esc_html__( 'Backup file not found or unreadable.', 'museder-restoreone' ) );
        }

        $job_id  = self::generate_job_id();
        $job_dir = self::ensure_job_directory( $job_id );

        $meta = [
            'id'          => $job_id,
            'source'      => $source,
            'file'        => $file_path,
            'file_name'   => $file_name,
            'sha1'        => $sha1,
            'created_at'  => current_time( 'mysql' ),
            'updated_at'  => current_time( 'mysql' ),
            'stage'       => 'prepared',
            'progress'    => 10,
            'message'     => __( 'Restore job prepared.', 'museder-restoreone' ),
            'validation'  => null,
            'tmp_dir'     => self::ensure_job_tmp_directory( $job_id ),
            'logs'        => [],
            'reports'     => [],
        ];

        self::write_job_meta( $job_id, $meta );

        museder_restoreone_log( 'info', 'Restore job prepared.', [ 'job_id' => $job_id, 'file' => $file_name ] );

        return [ 'job_id' => $job_id ];
    }

    /**
     * Validate the prepared job and record compatibility results.
     *
     * @param string $job_id Job identifier.
     *
     * @return array
     */
    public static function validate( $job_id ) {
        $meta = self::get_job_meta( $job_id );

        if ( empty( $meta['file'] ) || ! file_exists( $meta['file'] ) ) {
            throw new RuntimeException( esc_html__( 'Restore source file missing.', 'museder-restoreone' ) );
        }

        $metadata = self::extract_archive_metadata( $job_id, $meta['file'] );

        // AI1WM encryption detection (package.json fields).
        $encrypted = ! empty( $metadata['Encrypted'] ) && ! empty( $metadata['EncryptedSignature'] );
        $encryption_error = null;
        if ( $encrypted ) {
            if ( ! Museder_Restoreone_Wpress_Crypto::can_decrypt() ) {
                $encryption_error = __( 'This server cannot decrypt encrypted backups (OpenSSL missing).', 'museder-restoreone' );
            }
        }

        $checksum_value = sha1_file( $meta['file'] );
        if ( empty( $meta['sha1'] ) ) {
            $meta['sha1'] = $checksum_value;
        }

        $current_wp  = get_bloginfo( 'version' );
        $current_php = PHP_VERSION;
        global $wpdb;
        $current_db = method_exists( $wpdb, 'db_version' ) ? $wpdb->db_version() : '';

        $domain = home_url();
        $backup_domain = isset( $metadata['siteurl'] ) ? $metadata['siteurl'] : $domain;

        $result = [
            'checksum' => [
                'ok'     => true,
                'sha1'   => $checksum_value,
            ],
            'compat'   => [
                'wp'  => [ 'backup' => isset( $metadata['wp_version'] ) ? $metadata['wp_version'] : '', 'current' => $current_wp, 'ok' => true ],
                'php' => [ 'backup' => isset( $metadata['php_version'] ) ? $metadata['php_version'] : '', 'current' => $current_php, 'ok' => true ],
                'db'  => [ 'backup' => isset( $metadata['db_version'] ) ? $metadata['db_version'] : '', 'current' => $current_db, 'ok' => true ],
            ],
            'domain'   => [
                'backup'      => $backup_domain,
                'current'     => $domain,
                'migrateMode' => ( $backup_domain !== $domain ),
            ],
            'dbScan'   => self::summarise_database_structure( $metadata ),
            'encryption' => [
                'encrypted' => (bool) $encrypted,
                'supported' => $encrypted ? Museder_Restoreone_Wpress_Crypto::can_decrypt() : true,
                'error'     => $encryption_error,
            ],
        ];

        $meta['validation'] = $result;
        $meta['stage']      = 'validated';
        $meta['progress']   = 30;
        $meta['message']    = __( 'Validation results available.', 'museder-restoreone' );
        $meta['updated_at'] = current_time( 'mysql' );

        self::write_job_meta( $job_id, $meta );

        museder_restoreone_log( 'info', 'Restore job validated.', [ 'job_id' => $job_id ] );

        return $result;
    }

    /**
     * Execute a dry-run analysis and return summary data.
     *
     * @param string $job_id Job identifier.
     *
     * @return array
     */
    public static function dry_run( $job_id ) {
        $meta = self::get_job_meta( $job_id );

        if ( 'validated' !== $meta['stage'] && 'dry-run' !== $meta['stage'] ) {
            throw new RuntimeException( esc_html__( 'Please complete validation before running a dry-run.', 'museder-restoreone' ) );
        }

        $summary = self::calculate_dry_run_summary( $meta );

        $txt_path  = Museder_Restoreone_Restore_Report::write_txt( $job_id, self::REPORT_TYPE_DRYRUN, $summary );
        $json_path = Museder_Restoreone_Restore_Report::write_json( $job_id, self::REPORT_TYPE_DRYRUN, $summary );

        $meta['reports'][ self::REPORT_TYPE_DRYRUN ] = [
            'txt'  => $txt_path,
            'json' => $json_path,
        ];
        $meta['dry_run']    = [
            'summary'      => $summary,
            'generated_at' => current_time( 'mysql' ),
        ];
        $meta['stage']      = 'dry-run';
        $meta['progress']   = 60;
        $meta['message']    = __( 'Dry-run summary available.', 'museder-restoreone' );
        $meta['updated_at'] = current_time( 'mysql' );

        self::write_job_meta( $job_id, $meta );

        museder_restoreone_log( 'info', 'Restore dry-run completed.', [ 'job_id' => $job_id ] );

        return [
            'summary' => $summary,
            'reports' => [
                'txt'  => rest_url( 'museder-restoreone/v2/restore/report/' . $job_id . '?format=txt&type=' . self::REPORT_TYPE_DRYRUN ),
                'json' => rest_url( 'museder-restoreone/v2/restore/report/' . $job_id . '?format=json&type=' . self::REPORT_TYPE_DRYRUN ),
            ],
        ];
    }

    /**
     * Execute the restore job (Phase C placeholder logic).
     */
    public static function execute( $job_id, array $options = [] ) {
        $meta = self::get_job_meta( $job_id );

        if ( ! in_array( $meta['stage'], [ 'validated', 'dry-run', 'ready' ], true ) ) {
            throw new RuntimeException( esc_html__( 'Please validate the archive before executing the restore.', 'museder-restoreone' ) );
        }

        if ( Museder_Restoreone_Restore_Lock::is_locked() && ! self::is_current_lock( $job_id ) ) {
            throw new RuntimeException( esc_html__( 'Another restore operation is currently running.', 'museder-restoreone' ) );
        }

        if ( ! Museder_Restoreone_Restore_Lock::acquire( $job_id ) ) {
            throw new RuntimeException( esc_html__( 'Failed to acquire restore lock.', 'museder-restoreone' ) );
        }

        try {
            $pre_backup = [];
            $do_pre_backup = ! empty( $options['auto_backup'] );
            if ( $do_pre_backup ) {
                // Allow longer execution time for the synchronous pre-backup snapshot on large sites.
                // phpcs:ignore WordPress.PHP.NoSetTimeLimit
                if ( function_exists( 'set_time_limit' ) ) {
                    // @phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged
                    @set_time_limit( 600 );
                    // @phpcs:enable Squiz.PHP.DiscouragedFunctions.Discouraged
                }

                $t0 = microtime( true );
                museder_restoreone_log( 'info', 'Restore pre-backup snapshot started.', [ 'job_id' => $job_id ] );
        try {
            $pre_backup = self::create_pre_backup();
                } catch ( Exception $e ) {
                    $elapsed = microtime( true ) - $t0;
                    museder_restoreone_log(
                        'error',
                        'Restore pre-backup snapshot failed.',
                        [
                            'job_id'   => $job_id,
                            'elapsed'  => round( $elapsed, 3 ),
                            'error'    => sanitize_text_field( $e->getMessage() ),
                        ]
                    );
                    // Surface a clear, actionable message to the user. The restore can be retried without a snapshot.
                    throw new RuntimeException(
                        esc_html__(
                            'Pre-restore backup failed. Uncheck "Backup current site before restore" and try again, or check logs for details.',
                            'museder-restoreone'
                        )
                    );
                }

                $elapsed = microtime( true ) - $t0;
                museder_restoreone_log( 'info', 'Restore pre-backup snapshot finished.', [ 'job_id' => $job_id, 'elapsed' => round( $elapsed, 3 ) ] );
            }

            $file_path = isset( $meta['file'] ) ? $meta['file'] : '';
            $ext       = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

            $meta['options']     = $options;
            $meta['pre_backup']  = $pre_backup;
            $meta['engine']      = in_array( $ext, [ 'wpress', 'zip' ], true ) ? $ext : 'zip';
            if ( empty( $meta['started_at'] ) ) {
                $meta['started_at'] = time();
            }
            $meta['stage']       = 'restore-extract-db';
            $meta['progress']    = 70;
            $meta['message']     = __( 'Restore queued. Preparing to extract database…', 'museder-restoreone' );
            $meta['updated_at']  = current_time( 'mysql' );
            $meta['completed']   = false;
            $meta['cancel_requested'] = false;
            $meta['last_tick']   = 0;

            // Initialize checkpoints (offsets) for slicing/resume.
            $meta['checkpoints'] = isset( $meta['checkpoints'] ) && is_array( $meta['checkpoints'] ) ? $meta['checkpoints'] : [];
            $meta['checkpoints'] = wp_parse_args(
                $meta['checkpoints'],
                [
                    'wpress_archive_offset' => 0,
                    'wpress_file_offset'    => 0,
                    'wpress_processed_bytes'=> 0,
                    'files_phase'           => 0,
                    'db_offset'             => 0,
                    'db_query_buffer'       => '',
                    // Search-replace (AI1WM-like, resumable).
                    'sr_tables'             => [],
                    'sr_table_index'        => 0,
                    'sr_pk_last'            => null,
                    'sr_row_offset'         => 0,
                    'sr_scanned_rows'       => 0,
                    'sr_updated_rows'       => 0,
                    'sr_table_cache'        => [],
                ]
            );

            self::write_job_meta( $job_id, $meta );

            // Restore History: record running entry (upsert by job_id).
            if ( function_exists( 'museder_restoreone_upsert_restore_history' ) ) {
                $started = isset( $meta['started_at'] ) ? (int) $meta['started_at'] : time();
                $file_for_history = isset( $meta['file'] ) ? basename( (string) $meta['file'] ) : '';
                museder_restoreone_upsert_restore_history(
                    [
                        'job_id'                  => $job_id,
                        'timestamp_utc'           => $started,
                        'date'                    => gmdate( 'Y-m-d H:i:s', $started ),
                        'file'                    => $file_for_history,
                        'result'                  => 'running',
                        'restore_started_at'      => $started,
                        'restore_completed_at'    => 0,
                        'restore_duration_seconds'=> 0,
                        'log'                     => '',
                    ]
                );
            }

            // Track as active restore job (for UI bootstrap / polling recovery).
            update_option( self::ACTIVE_JOB_OPTION, $job_id, false );

            // Schedule background processing (time-sliced).
            // De-duplicate any existing scheduled ticks for this job id.
            if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
                wp_clear_scheduled_hook( self::CRON_HOOK_PROCESS, [ $job_id ] );
            }
            wp_schedule_single_event( time(), self::CRON_HOOK_PROCESS, [ $job_id ] );
            self::spawn_cron();

            return [
                'ok'                 => true,
                'message'            => __( 'Restore started. You can monitor progress on this page.', 'museder-restoreone' ),
                'rollback_available' => ! empty( $pre_backup ) && ! empty( $pre_backup['file'] ),
            ];
        } catch ( Exception $e ) {
            museder_restoreone_log( 'error', 'Restore execution start failed.', [ 'job_id' => $job_id, 'error' => $e->getMessage() ] );
            // Best-effort clear active job pointer if we fail to start.
            $active = (string) get_option( self::ACTIVE_JOB_OPTION, '' );
            if ( $active === $job_id ) {
                delete_option( self::ACTIVE_JOB_OPTION );
            }
            Museder_Restoreone_Restore_Lock::release();
            throw $e;
        }
    }

    public static function cron_process_job( $job_id ) {
        self::process_job_slice( $job_id, self::DEFAULT_SLICE_SECONDS, true, 'cron' );
    }

    /**
     * Process a single time slice of a restore job.
     *
     * This is used by WP-Cron and can also be used by admin-ajax "tick" to keep progress moving
     * in environments where cron/loopback is unreliable.
     *
     * @param string $job_id
     * @param int    $slice_seconds
     * @param bool   $reschedule
     * @param string $source cron|ajax
     *
     * @return array{ok:bool,meta:array,reason?:string}
     */
    public static function process_job_slice( $job_id, $slice_seconds = 10, $reschedule = false, $source = 'cron' ) {
        $job_id = (string) $job_id;
        $slice  = max( 1, (int) $slice_seconds );

        $lock_fp = null;
        try {
            $lock_fp = self::acquire_job_run_lock( $job_id );
            if ( ! $lock_fp ) {
                return [ 'ok' => false, 'meta' => [], 'reason' => 'busy' ];
            }

            $meta = self::get_job_meta( $job_id );

            if ( ! empty( $meta['completed'] ) ) {
                // Clear active job pointer when job is finished.
                $active = (string) get_option( self::ACTIVE_JOB_OPTION, '' );
                if ( $active === $job_id ) {
                    delete_option( self::ACTIVE_JOB_OPTION );
                }
                return [ 'ok' => true, 'meta' => $meta ];
            }

            // Cooperative cancel: stop the pipeline as soon as cancel is requested.
            if ( ! empty( $meta['cancel_requested'] ) || ( isset( $meta['stage'] ) && 'cancelled' === $meta['stage'] ) ) {
                $meta['stage']      = 'cancelled';
                $meta['progress']   = 100;
                $meta['message']    = __( 'Restore cancelled.', 'museder-restoreone' );
                $meta['completed']  = true;
                $meta['updated_at'] = current_time( 'mysql' );
                self::write_job_meta( $job_id, $meta );
                Museder_Restoreone_Restore_Lock::release();

                // Restore History: mark cancelled.
                if ( function_exists( 'museder_restoreone_upsert_restore_history' ) ) {
                    $completed_at = time();
                    $started_at = isset( $meta['started_at'] ) ? (int) $meta['started_at'] : 0;
                    $duration = ( $started_at > 0 ) ? max( 0, $completed_at - $started_at ) : 0;
                    museder_restoreone_upsert_restore_history(
                        [
                            'job_id'                  => $job_id,
                            'timestamp_utc'           => $completed_at,
                            'date'                    => gmdate( 'Y-m-d H:i:s', $completed_at ),
                            'file'                    => isset( $meta['file'] ) ? basename( (string) $meta['file'] ) : '',
                            'result'                  => 'cancelled',
                            'restore_started_at'      => $started_at,
                            'restore_completed_at'    => $completed_at,
                            'restore_duration_seconds'=> $duration,
                        ]
                    );
                }

                $active = (string) get_option( self::ACTIVE_JOB_OPTION, '' );
                if ( $active === $job_id ) {
                    delete_option( self::ACTIVE_JOB_OPTION );
                }
                return [ 'ok' => true, 'meta' => $meta ];
            }

            // Ensure lock belongs to this job. If not, do not process.
            if ( Museder_Restoreone_Restore_Lock::is_locked() && ! self::is_current_lock( $job_id ) ) {
                return [ 'ok' => false, 'meta' => $meta, 'reason' => 'locked_by_other' ];
            }

            // Keep lock alive while processing.
            Museder_Restoreone_Restore_Lock::refresh( $job_id );

            $meta['last_tick']  = time();
            $meta['updated_at'] = current_time( 'mysql' );
            $meta['tick_source'] = $source; // safe string for debugging only
            self::write_job_meta( $job_id, $meta );

            switch ( $meta['stage'] ) {
                case 'restore-extract-db':
                    self::stage_extract_database( $job_id, $meta, $slice );
                    break;
                case 'restore-db':
                    self::stage_import_database( $job_id, $meta, $slice );
                    break;
                case 'prefix-migrate':
                    self::stage_migrate_db_prefix( $job_id, $meta, $slice );
                    break;
                case 'restore-files':
                    self::stage_restore_files( $job_id, $meta, $slice );
                    break;
                case 'search-replace':
                    self::stage_search_replace_sliced( $job_id, $meta, $slice );
                    break;
                case 'cleanup':
                    self::stage_cleanup_and_finish( $job_id, $meta, $slice );
                    break;
                case 'prepared':
                case 'validated':
                case 'dry-run':
                case 'ready':
                    // Job not started yet; UI might still poll process endpoint. Do not fail the job.
                    return [ 'ok' => true, 'meta' => $meta, 'reason' => 'not_started' ];
                case 'done':
                case 'rollback':
                case 'rollback-done':
                    // Defensive: if stage is already terminal-ish, avoid failing due to an unknown stage.
                    return [ 'ok' => true, 'meta' => $meta, 'reason' => 'terminal' ];
                default:
                    // Unknown stage -> fail safely.
                    throw new RuntimeException( esc_html__( 'Restore job is in an unknown stage.', 'museder-restoreone' ) );
            }

            // Re-schedule if not done (cron only).
            if ( $reschedule ) {
                $meta_after = self::get_job_meta( $job_id );
                if ( empty( $meta_after['completed'] ) && ! wp_next_scheduled( self::CRON_HOOK_PROCESS, [ $job_id ] ) ) {
                    wp_schedule_single_event( time() + 1, self::CRON_HOOK_PROCESS, [ $job_id ] );
                    self::spawn_cron();
                }
                return [ 'ok' => true, 'meta' => $meta_after ];
            }

            return [ 'ok' => true, 'meta' => self::get_job_meta( $job_id ) ];
                } catch ( Exception $e ) {
            museder_restoreone_log( 'error', 'Restore job slice failed.', [ 'job_id' => $job_id, 'source' => $source, 'error' => $e->getMessage() ] );
            try {
                $meta = self::get_job_meta( $job_id );
                $meta['stage']      = 'failed';
                $meta['progress']   = 100;
                $meta['message']    = $e->getMessage();
                $meta['completed']  = true;
                $meta['updated_at'] = current_time( 'mysql' );
                self::write_job_meta( $job_id, $meta );
            } catch ( Exception $inner ) {
                // Ignore.
            }

            // Restore History: mark failed.
            if ( function_exists( 'museder_restoreone_upsert_restore_history' ) ) {
                try {
                    $meta2 = self::get_job_meta( $job_id );
                    $completed_at = time();
                    $started_at = isset( $meta2['started_at'] ) ? (int) $meta2['started_at'] : 0;
                    $duration = ( $started_at > 0 ) ? max( 0, $completed_at - $started_at ) : 0;
                    museder_restoreone_upsert_restore_history(
                        [
                            'job_id'                  => $job_id,
                            'timestamp_utc'           => $completed_at,
                            'date'                    => gmdate( 'Y-m-d H:i:s', $completed_at ),
                            'file'                    => isset( $meta2['file'] ) ? basename( (string) $meta2['file'] ) : '',
                            'result'                  => 'failed',
                            'restore_started_at'      => $started_at,
                            'restore_completed_at'    => $completed_at,
                            'restore_duration_seconds'=> $duration,
                            'message'                 => sanitize_text_field( $e->getMessage() ),
                        ]
                    );
                } catch ( Exception $inner2 ) {
                    // Ignore.
                }
            }
            // Clear active job pointer on failure.
            $active = (string) get_option( self::ACTIVE_JOB_OPTION, '' );
            if ( $active === $job_id ) {
                delete_option( self::ACTIVE_JOB_OPTION );
            }
            Museder_Restoreone_Restore_Lock::release();
            return [ 'ok' => false, 'meta' => [], 'reason' => 'failed' ];
        } finally {
            self::release_job_run_lock( $lock_fp );
        }
    }

    /**
     * Acquire a per-job execution lock to prevent concurrent cron/tick processors.
     *
     * @param string $job_id
     * @return resource|false
     */
    protected static function acquire_job_run_lock( $job_id ) {
        $tmp = self::ensure_job_tmp_directory( $job_id );
        $path = trailingslashit( $tmp ) . 'run.lock';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- lock file for concurrency control
        $fp = fopen( $path, 'c+' );
        if ( ! $fp ) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- required for concurrency control
        $ok = flock( $fp, LOCK_EX | LOCK_NB );
        if ( ! $ok ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose( $fp );
            return false;
        }
        return $fp;
    }

    /**
     * Release job run lock.
     *
     * @param resource|false $fp
     * @return void
     */
    protected static function release_job_run_lock( $fp ) {
        if ( ! $fp ) {
            return;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock -- release lock
        @flock( $fp, LOCK_UN );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $fp );
    }

    /**
     * Cancel an in-progress restore job (best-effort).
     *
     * @param string $job_id
     * @return array
     */
    public static function cancel( $job_id ) {
        $meta = self::get_job_meta( $job_id );

        if ( ! empty( $meta['completed'] ) ) {
            return [ 'ok' => true, 'message' => __( 'Restore job already completed.', 'museder-restoreone' ) ];
        }

        // Cooperative cancel: mark request; the cron loop will finalize quickly and safely.
        $meta['cancel_requested'] = true;
        $meta['stage']            = 'cancelled';
        $meta['progress']         = 100;
        $meta['message']          = __( 'Restore cancelled.', 'museder-restoreone' );
        $meta['completed']        = true;
        $meta['updated_at'] = current_time( 'mysql' );
        self::write_job_meta( $job_id, $meta );

        // Clear active job pointer.
        $active = (string) get_option( self::ACTIVE_JOB_OPTION, '' );
        if ( $active === $job_id ) {
            delete_option( self::ACTIVE_JOB_OPTION );
        }

        // Best-effort cleanup.
        try {
            $tmp = trailingslashit( museder_restoreone_get_temp_dir() ) . $job_id;
            if ( file_exists( $tmp ) ) {
                museder_restoreone_delete_directory( $tmp );
            }
        } catch ( Exception $e ) {
            // Ignore.
        }

        Museder_Restoreone_Restore_Lock::release();

        return [ 'ok' => true, 'message' => __( 'Restore cancelled.', 'museder-restoreone' ) ];
    }

    /**
     * Return active restore job id (if any).
     *
     * @return string
     */
    public static function get_active_job_id() {
        return (string) get_option( self::ACTIVE_JOB_OPTION, '' );
    }

    protected static function spawn_cron() {
        // Do not include WordPress core files directly. Best-effort: nudge wp-cron via loopback request.
        if ( function_exists( 'museder_restoreone_nudge_wp_cron' ) ) {
            museder_restoreone_nudge_wp_cron();
        }
    }

    protected static function stage_extract_database( $job_id, array $meta, $slice_seconds ) {
        $file_path = isset( $meta['file'] ) ? $meta['file'] : '';
        if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
            throw new RuntimeException( esc_html__( 'Restore source file missing.', 'museder-restoreone' ) );
        }

        $tmp_dir = self::ensure_job_tmp_directory( $job_id );
        $db_dir  = trailingslashit( $tmp_dir ) . 'db';
        museder_restoreone_ensure_directory( $db_dir );

        $engine = isset( $meta['engine'] ) ? $meta['engine'] : 'zip';
        if ( 'wpress' === $engine ) {
            $cp = isset( $meta['checkpoints'] ) && is_array( $meta['checkpoints'] ) ? $meta['checkpoints'] : [];
            $archive_offset = isset( $cp['wpress_archive_offset'] ) ? (int) $cp['wpress_archive_offset'] : 0;
            $file_offset    = isset( $cp['wpress_file_offset'] ) ? (int) $cp['wpress_file_offset'] : 0;
            $processed       = isset( $cp['wpress_processed_bytes'] ) ? (int) $cp['wpress_processed_bytes'] : 0;

            $extractor = new Museder_Restoreone_Wpress_Extractor( $file_path );
            if ( ! empty( $meta['options']['decryption_password'] ) ) {
                $extractor->set_decryption_password( (string) $meta['options']['decryption_password'] );
            }

            $ok = $extractor->extract_filtered_sliced(
                $db_dir,
                self::WPRESS_DB_FILES,
                [],
                [],
                $archive_offset,
                $file_offset,
                $processed,
                (int) $slice_seconds
            );
            $extractor->close();

            $meta['checkpoints']['wpress_archive_offset']  = $archive_offset;
            $meta['checkpoints']['wpress_file_offset']     = $file_offset;
            $meta['checkpoints']['wpress_processed_bytes'] = $processed;
            $meta['db_file'] = trailingslashit( $db_dir ) . 'database.ndjson';
            $meta['progress'] = 75;
            $meta['message']  = __( 'Extracting database…', 'museder-restoreone' );
            $meta['updated_at'] = current_time( 'mysql' );
            self::write_job_meta( $job_id, $meta );

            if ( $ok && file_exists( $meta['db_file'] ) ) {
            $meta['stage']    = 'restore-db';
                $meta['progress'] = 80;
                $meta['message']  = __( 'Preparing database import…', 'museder-restoreone' );
                $meta['updated_at'] = current_time( 'mysql' );
            self::write_job_meta( $job_id, $meta );
            }
        } else {
            // zip: do a minimal extraction of database.ndjson into job tmp folder
            $db_path = trailingslashit( $db_dir ) . 'database.ndjson';
            if ( ! file_exists( $db_path ) ) {
                // On some hosts, ZipArchive cannot open large archives (ZipArchive error 19).
                // In that case, avoid directory listing and try extracting the expected root name directly.
                self::extract_zip_entry_to_path( $file_path, 'database.ndjson', $db_path );
            }
            if ( ! file_exists( $db_path ) ) {
                // No NDJSON DB. Check if legacy SQL exists in the archive (manual import only).
                $sql_path = trailingslashit( $db_dir ) . 'database.sql';
                if ( ! file_exists( $sql_path ) ) {
                    self::extract_zip_entry_to_path( $file_path, 'database.sql', $sql_path );
                }
                if ( file_exists( $sql_path ) ) {
                    $meta['db_file']    = $sql_path;
                    $meta['stage']      = 'restore-db';
                    $meta['progress']   = 80;
                    $meta['message']    = __( 'Preparing database import…', 'museder-restoreone' );
                    $meta['updated_at'] = current_time( 'mysql' );
                    self::write_job_meta( $job_id, $meta );
                    return;
                }

                // DB is missing. If files-only was selected, skip DB stages and proceed.
                $files_only = ! empty( $meta['options'] ) && is_array( $meta['options'] ) && ! empty( $meta['options']['files_only'] );
                if ( $files_only ) {
                    $meta['warnings'] = isset( $meta['warnings'] ) && is_array( $meta['warnings'] ) ? $meta['warnings'] : [];
                    $meta['warnings'][] = 'db_missing_files_only';
                    $meta['stage']      = 'restore-files';
                    $meta['progress']   = 85;
                    $meta['message']    = __( 'Database backup not found in archive. Proceeding with files-only restore…', 'museder-restoreone' );
                    $meta['updated_at'] = current_time( 'mysql' );
                    self::write_job_meta( $job_id, $meta );
                    return;
                }

                throw new RuntimeException(
                    esc_html__(
                        'Database backup not found in archive. Enable “files-only restore” to restore files without database, or recreate the backup with the updated plugin.',
                        'museder-restoreone'
                    )
                );
            }

            $meta['db_file']    = $db_path;
            $meta['stage']      = 'restore-db';
            $meta['progress']   = 80;
            $meta['message']    = __( 'Preparing database import…', 'museder-restoreone' );
            $meta['updated_at'] = current_time( 'mysql' );
            self::write_job_meta( $job_id, $meta );
        }
    }

    protected static function stage_import_database( $job_id, array $meta, $slice_seconds ) {
        $db_file = isset( $meta['db_file'] ) ? (string) $meta['db_file'] : ( isset( $meta['sql_file'] ) ? (string) $meta['sql_file'] : '' );
        if ( '' === $db_file || ! file_exists( $db_file ) ) {
            throw new RuntimeException( esc_html__( 'Database file missing for import.', 'museder-restoreone' ) );
        }

        // New database format (database.ndjson) is imported directly by Museder_Restoreone_Restore.
        // Legacy SQL backups are manual-only (Museder_Restoreone_Restore::import_database returns manual_db_required).
        $result = Museder_Restoreone_Restore::import_database( $db_file );

        // Manual DB fallback: allow file restore to proceed when backup format is SQL.
        if ( empty( $result['success'] ) && isset( $result['code'] ) && 'manual_db_required' === (string) $result['code'] ) {
            $job_dir = self::ensure_job_directory( $job_id );
            $dest_name = 'database.sql';
            $dest    = wp_normalize_path( trailingslashit( $job_dir ) . $dest_name );

            // Best-effort persist SQL for the admin to download (direct access is blocked via directory protection).
            if ( file_exists( $db_file ) && is_readable( $db_file ) && ! file_exists( $dest ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- restore file operation, plugin-controlled path
                @copy( $db_file, $dest );
            }

            $meta['manual_db'] = [
                'required' => true,
                'file'     => $dest_name,
            ];

            $meta['stage']      = 'restore-files';
            $meta['progress']   = 90;
            $meta['message']    = __( 'Manual database import required. Restoring files now…', 'museder-restoreone' );
            $meta['updated_at'] = current_time( 'mysql' );
            self::write_job_meta( $job_id, $meta );
            return;
        }

        if ( ! empty( $result['success'] ) ) {
            $meta['progress'] = 90;
            $meta['message']  = __( 'Database import completed.', 'museder-restoreone' );
            $meta['stage']    = 'restore-files';
        } else {
            $meta['progress'] = 80;
            $meta['message']  = isset( $result['message'] ) ? (string) $result['message'] : __( 'Database import failed.', 'museder-restoreone' );
            $meta['stage']    = 'failed';
        }
        $meta['updated_at'] = current_time( 'mysql' );
            self::write_job_meta( $job_id, $meta );

        return;

        if ( ! empty( $result['success'] ) ) {
            // Guard against "restore success but empty content" caused by prefix mis-detection.
            // If we imported SQL without rewriting to the active prefix, the site will appear blank even though the SQL import ran.
            $verify_prefix = '';
            if ( is_string( $rewrite_to ) && '' !== $rewrite_to ) {
                $verify_prefix = $rewrite_to;
            } else {
                global $wpdb;
                $verify_prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
            }

            $verify = self::verify_restored_database_core_tables( $verify_prefix );
            $meta['checkpoints']['db_verify'] = $verify;
            self::write_job_meta( $job_id, $meta );

            if ( empty( $verify['ok'] ) ) {
                $src = ( is_string( $rewrite_from ) && '' !== $rewrite_from ) ? $rewrite_from : ( ! empty( $cp['db_source_prefix'] ) ? (string) $cp['db_source_prefix'] : '' );
                $dst = $verify_prefix;
                $reason = isset( $verify['reason'] ) ? (string) $verify['reason'] : 'unknown';

                if ( function_exists( 'museder_restoreone_log' ) ) {
                    museder_restoreone_log( 'error', 'DB verify failed after import; refusing to mark restore success.', [
                        'job_id'        => $job_id,
                        'source_prefix' => $src,
                        'target_prefix' => $dst,
                        'reason'        => $reason,
                        'missing'       => isset( $verify['missing'] ) ? $verify['missing'] : [],
                    ] );
                }

                throw new RuntimeException(
                    sprintf(
                        /* translators: 1: source prefix, 2: target prefix */
                        esc_html__( 'Database import verification failed (source prefix: %1$s, target prefix: %2$s).', 'museder-restoreone' ),
                        esc_html( $src ? $src : 'unknown' ),
                        esc_html( $dst ? $dst : 'unknown' )
                    )
                );
            } elseif ( function_exists( 'museder_restoreone_log' ) ) {
                museder_restoreone_log( 'info', 'DB verify ok after import.', [
                    'job_id'        => $job_id,
                    'target_prefix' => $verify_prefix,
                    'tables'        => isset( $verify['tables'] ) ? $verify['tables'] : [],
                ] );
            }

            // If we rewrote table prefixes during import, we must also migrate prefix-dependent keys/values
            // inside options/usermeta (e.g., qvj4_user_roles, qvj4_capabilities).
            if ( $rewrite_from && $rewrite_to && $rewrite_from !== $rewrite_to ) {
                $meta['checkpoints']['prefix_migrate'] = [
                    'from'  => (string) $rewrite_from,
                    'to'    => (string) $rewrite_to,
                    'phase' => 'options_keys',
                    'stats' => [
                        'options_keys'   => 0,
                        'usermeta_keys'  => 0,
                        'options_values' => 0,
                        'usermeta_values'=> 0,
                    ],
                ];
                $meta['stage']      = 'prefix-migrate';
                $meta['progress']   = 90;
                $meta['message']    = __( 'Fixing database prefix references…', 'museder-restoreone' );
                $meta['updated_at'] = current_time( 'mysql' );
                self::write_job_meta( $job_id, $meta );
            } else {
                $meta['stage']      = 'restore-files';
                $meta['progress']   = 90;
                $meta['message']    = __( 'Restoring files…', 'museder-restoreone' );
                $meta['updated_at'] = current_time( 'mysql' );
                self::write_job_meta( $job_id, $meta );
            }
        }
    }

    /**
     * Verify that core WP tables exist under the expected prefix after importing a SQL dump.
     *
     * This prevents false-success restores where SQL was imported under a different prefix, making the site appear empty.
     *
     * @param string $target_prefix e.g. "wp_" or "wp_2_" (including trailing underscore)
     * @return array{ok:bool,reason:string,missing:array,tables:array}
     */
    protected static function verify_restored_database_core_tables( $target_prefix ) {
        global $wpdb;

        $target_prefix = (string) $target_prefix;
        if ( '' === $target_prefix || ! preg_match( '/^[A-Za-z0-9_]+_$/', $target_prefix ) ) {
            return [
                'ok'      => false,
                'reason'  => 'invalid_prefix',
                'missing' => [],
                'tables'  => [],
            ];
        }

        $need = [ 'options', 'posts' ];
        $missing = [];
        $present = [];

        foreach ( $need as $suffix ) {
            $table = $target_prefix . $suffix;
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( $found ) {
                $present[] = $table;
            } else {
                $missing[] = $table;
            }
        }

        if ( ! empty( $missing ) ) {
            return [
                'ok'      => false,
                'reason'  => 'missing_core_tables',
                'missing' => $missing,
                'tables'  => $present,
            ];
        }

        // Validate expected schema columns to prevent "wrong table rewritten into {prefix}_options".
        // Identifiers: prefix has already been validated (^[A-Za-z0-9_]+_$), sanitize again defensively.
        $options_table = sanitize_key( (string) ( $target_prefix . 'options' ) );
        $posts_table   = sanitize_key( (string) ( $target_prefix . 'posts' ) );
        $missing_cols  = [];

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers are sanitized and escaped with esc_sql(); LIKE value uses prepare()
        $opt_autoload = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM `' . esc_sql( $options_table ) . '` LIKE %s', 'autoload' ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifier sanitized via sanitize_key() + esc_sql()
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers are sanitized and escaped with esc_sql(); LIKE value uses prepare()
        $opt_name     = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM `' . esc_sql( $options_table ) . '` LIKE %s', 'option_name' ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifier sanitized via sanitize_key() + esc_sql()
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers are sanitized and escaped with esc_sql(); LIKE value uses prepare()
        $opt_value    = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM `' . esc_sql( $options_table ) . '` LIKE %s', 'option_value' ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifier sanitized via sanitize_key() + esc_sql()
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers are sanitized and escaped with esc_sql(); LIKE value uses prepare()
        $post_id      = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM `' . esc_sql( $posts_table ) . '` LIKE %s', 'ID' ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifier sanitized via sanitize_key() + esc_sql()
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        if ( ! $opt_autoload ) {
            $missing_cols[] = $options_table . '.autoload';
        }
        if ( ! $opt_name ) {
            $missing_cols[] = $options_table . '.option_name';
        }
        if ( ! $opt_value ) {
            $missing_cols[] = $options_table . '.option_value';
        }
        if ( ! $post_id ) {
            $missing_cols[] = $posts_table . '.ID';
        }

        if ( ! empty( $missing_cols ) ) {
            return [
                'ok'      => false,
                'reason'  => 'core_schema_mismatch',
                'missing' => $missing_cols,
                'tables'  => $present,
            ];
        }

        // Basic sanity: siteurl/home should exist in the target options table.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier is sanitized + esc_sql(); option_name uses prepare()
        $siteurl = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . esc_sql( $options_table ) . '` WHERE option_name = %s LIMIT 1', 'siteurl' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier is sanitized + esc_sql(); option_name uses prepare()
        $home    = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . esc_sql( $options_table ) . '` WHERE option_name = %s LIMIT 1', 'home' ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        if ( empty( $siteurl ) && empty( $home ) ) {
            return [
                'ok'      => false,
                'reason'  => 'missing_siteurl_home',
                'missing' => [],
                'tables'  => $present,
            ];
        }

        return [
            'ok'      => true,
            'reason'  => 'ok',
            'missing' => [],
            'tables'  => $present,
        ];
    }

    /**
     * After importing SQL with table-prefix rewrite, migrate prefix-dependent keys/values inside the restored DB.
     *
     * This fixes common WP fields like:
     * - wp_options.option_name: {prefix}_user_roles
     * - wp_usermeta.meta_key:  {prefix}_capabilities, {prefix}_user_level
     *
     * @param string $job_id
     * @param array  $meta
     * @param int    $slice_seconds
     * @return void
     */
    protected static function stage_migrate_db_prefix( $job_id, array $meta, $slice_seconds ) {
        global $wpdb;

        $cp = isset( $meta['checkpoints'] ) && is_array( $meta['checkpoints'] ) ? $meta['checkpoints'] : [];
        $pm = isset( $cp['prefix_migrate'] ) && is_array( $cp['prefix_migrate'] ) ? $cp['prefix_migrate'] : [];
        $from = isset( $pm['from'] ) ? (string) $pm['from'] : '';
        $to   = isset( $pm['to'] ) ? (string) $pm['to'] : '';
        $phase = isset( $pm['phase'] ) ? (string) $pm['phase'] : 'options_keys';
        $stats = isset( $pm['stats'] ) && is_array( $pm['stats'] ) ? $pm['stats'] : [];

        if ( '' === $from || '' === $to || $from === $to ) {
            // Nothing to migrate.
            $meta['stage']      = 'restore-files';
            $meta['progress']   = 90;
            $meta['message']    = __( 'Restoring files…', 'museder-restoreone' );
            $meta['updated_at'] = current_time( 'mysql' );
            self::write_job_meta( $job_id, $meta );
            return;
        }

        $start = microtime( true );
        $slice_seconds = max( 1, (int) $slice_seconds );

        // Use core table names. These are not user input.
        $options_table  = isset( $wpdb->options ) ? (string) $wpdb->options : ( $wpdb->prefix . 'options' );
        $usermeta_table = isset( $wpdb->usermeta ) ? (string) $wpdb->usermeta : ( $wpdb->prefix . 'usermeta' );

        $from_len = strlen( $from );
        $safe_value_replace = ( strlen( $from ) === strlen( $to ) );

        $limit_keys   = 500;
        $limit_values = 200;

        while ( ( microtime( true ) - $start ) < $slice_seconds ) {
            if ( 'options_keys' === $phase ) {
                $like = $wpdb->esc_like( $from ) . '%';
                $start_pos = (int) $from_len + 1; // MySQL SUBSTRING is 1-based.
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- core table name; values are prepared
                $affected = (int) $wpdb->query(
                    $wpdb->prepare(
                        'UPDATE ' . $options_table . ' SET option_name = CONCAT(%s, SUBSTRING(option_name, %d)) WHERE option_name LIKE %s LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table identifier is core ($wpdb->options), cannot be a placeholder
                        $to,
                        $start_pos,
                        $like,
                        $limit_keys
                    )
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

                $stats['options_keys'] = isset( $stats['options_keys'] ) ? (int) $stats['options_keys'] + max( 0, $affected ) : max( 0, $affected );
                if ( $affected <= 0 ) {
                    $phase = 'usermeta_keys';
                }
            } elseif ( 'usermeta_keys' === $phase ) {
                $like = $wpdb->esc_like( $from ) . '%';
                $start_pos = (int) $from_len + 1;
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- core table name; values are prepared
                $affected = (int) $wpdb->query(
                    $wpdb->prepare(
                        'UPDATE ' . $usermeta_table . ' SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table identifier is core ($wpdb->usermeta), cannot be a placeholder
                        $to,
                        $start_pos,
                        $like,
                        $limit_keys
                    )
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

                $stats['usermeta_keys'] = isset( $stats['usermeta_keys'] ) ? (int) $stats['usermeta_keys'] + max( 0, $affected ) : max( 0, $affected );
                if ( $affected <= 0 ) {
                    $phase = $safe_value_replace ? 'options_values' : 'finish';
                }
            } elseif ( 'options_values' === $phase ) {
                $like = '%' . $wpdb->esc_like( $from ) . '%';
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- core table name; values are prepared
                $affected = (int) $wpdb->query(
                    $wpdb->prepare(
                        'UPDATE ' . $options_table . ' SET option_value = REPLACE(option_value, %s, %s) WHERE option_value LIKE %s LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table identifier is core ($wpdb->options), cannot be a placeholder
                        $from,
                        $to,
                        $like,
                        $limit_values
                    )
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

                $stats['options_values'] = isset( $stats['options_values'] ) ? (int) $stats['options_values'] + max( 0, $affected ) : max( 0, $affected );
                if ( $affected <= 0 ) {
                    $phase = 'usermeta_values';
                }
            } elseif ( 'usermeta_values' === $phase ) {
                $like = '%' . $wpdb->esc_like( $from ) . '%';
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- core table name; values are prepared
                $affected = (int) $wpdb->query(
                    $wpdb->prepare(
                        'UPDATE ' . $usermeta_table . ' SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_value LIKE %s LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table identifier is core ($wpdb->usermeta), cannot be a placeholder
                        $from,
                        $to,
                        $like,
                        $limit_values
                    )
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

                $stats['usermeta_values'] = isset( $stats['usermeta_values'] ) ? (int) $stats['usermeta_values'] + max( 0, $affected ) : max( 0, $affected );
                if ( $affected <= 0 ) {
                    $phase = 'finish';
                }
            } else {
                // finish
                break;
            }

            // Update job meta for UI visibility.
            $meta['progress']   = 90;
            $meta['message']    = __( 'Fixing database prefix references…', 'museder-restoreone' );
            $meta['updated_at'] = current_time( 'mysql' );
            $cp['prefix_migrate'] = [
                'from'  => $from,
                'to'    => $to,
                'phase' => $phase,
                'stats' => $stats,
            ];
            $meta['checkpoints'] = $cp;
            self::write_job_meta( $job_id, $meta );

            if ( 'finish' === $phase ) {
                break;
            }
        }

        if ( 'finish' === $phase ) {
            museder_restoreone_log( 'info', 'DB prefix migration completed.', [
                'job_id' => $job_id,
                'from'   => $from,
                'to'     => $to,
                'stats'  => $stats,
                'value_replace' => (bool) $safe_value_replace,
            ] );

            $meta['stage']      = 'restore-files';
            $meta['progress']   = 90;
            $meta['message']    = __( 'Restoring files…', 'museder-restoreone' );
            $meta['updated_at'] = current_time( 'mysql' );
            $meta['checkpoints'] = $cp;
            self::write_job_meta( $job_id, $meta );
        }
    }

    protected static function stage_restore_files( $job_id, array $meta, $slice_seconds ) {
        $file_path = isset( $meta['file'] ) ? $meta['file'] : '';
        if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
            throw new RuntimeException( esc_html__( 'Restore source file missing.', 'museder-restoreone' ) );
        }

        $engine = isset( $meta['engine'] ) ? $meta['engine'] : 'zip';

        if ( 'wpress' === $engine ) {
            $protect_self = (bool) apply_filters( 'museder_restoreone_restore_protect_self', true );
            $self_exclude = $protect_self ? [ 'plugins/museder-restoreone', 'wp-content/plugins/museder-restoreone' ] : [];
            $content_dir  = function_exists( 'museder_restoreone_get_wp_content_dir' ) ? museder_restoreone_get_wp_content_dir() : '';
            $site_root    = '' !== $content_dir ? wp_normalize_path( (string) dirname( $content_dir ) ) : '';
            $phases = [
                [ 'base' => $content_dir, 'include' => [ 'uploads', 'plugins', 'themes', 'mu-plugins' ] ],
                [ 'base' => $site_root, 'include' => [ 'wp-content' ] ],
            ];

            $cp = isset( $meta['checkpoints'] ) && is_array( $meta['checkpoints'] ) ? $meta['checkpoints'] : [];
            $phase_idx     = isset( $cp['files_phase'] ) ? (int) $cp['files_phase'] : 0;
            $archive_offset = isset( $cp['wpress_archive_offset'] ) ? (int) $cp['wpress_archive_offset'] : 0;
            $file_offset    = isset( $cp['wpress_file_offset'] ) ? (int) $cp['wpress_file_offset'] : 0;
            $processed       = isset( $cp['wpress_processed_bytes'] ) ? (int) $cp['wpress_processed_bytes'] : 0;

            if ( $phase_idx >= count( $phases ) ) {
            $meta['stage']      = 'search-replace';
                $meta['progress']   = 96;
                $meta['message']    = __( 'Preparing URL replacement…', 'museder-restoreone' );
                $meta['updated_at'] = current_time( 'mysql' );
            self::write_job_meta( $job_id, $meta );
                return;
            }

            $extractor = new Museder_Restoreone_Wpress_Extractor( $file_path );
            if ( ! empty( $meta['options']['decryption_password'] ) ) {
                $extractor->set_decryption_password( (string) $meta['options']['decryption_password'] );
            }

            $phase = $phases[ $phase_idx ];
            $ok    = $extractor->extract_filtered_sliced(
                $phase['base'],
                $phase['include'],
                array_merge( self::WPRESS_FILES_EXCLUDE, $self_exclude ),
                [],
                $archive_offset,
                $file_offset,
                $processed,
                (int) $slice_seconds
            );
            $extractor->close();

            $meta['checkpoints']['wpress_archive_offset']  = $archive_offset;
            $meta['checkpoints']['wpress_file_offset']     = $file_offset;
            $meta['checkpoints']['wpress_processed_bytes'] = $processed;

            if ( $ok ) {
                $meta['checkpoints']['files_phase'] = $phase_idx + 1;
                // Reset offsets for next phase.
                $meta['checkpoints']['wpress_archive_offset'] = 0;
                $meta['checkpoints']['wpress_file_offset']    = 0;
            }

            // Progress mapping: 90–96 for files stage.
            $phase_count = max( 1, count( $phases ) );
            $base = 90 + (int) floor( ( $phase_idx / $phase_count ) * 6 );
            $meta['progress']   = min( 96, $base + ( $ok ? 3 : 1 ) );
            $meta['message']    = __( 'Restoring files…', 'museder-restoreone' );
            $meta['updated_at'] = current_time( 'mysql' );
            self::write_job_meta( $job_id, $meta );

            if ( $ok && ( $phase_idx + 1 ) >= count( $phases ) ) {
                $meta['stage']      = 'search-replace';
                $meta['progress']   = 96;
                $meta['message']    = __( 'Preparing URL replacement…', 'museder-restoreone' );
                $meta['updated_at'] = current_time( 'mysql' );
                self::write_job_meta( $job_id, $meta );
            }
        } else {
            // ZIP restore: extract wp-content/ into site wp-content (streamed per entry, sliced).
            $cp = isset( $meta['checkpoints'] ) && is_array( $meta['checkpoints'] ) ? $meta['checkpoints'] : [];
            $zip_index  = isset( $cp['zip_index'] ) ? (int) $cp['zip_index'] : 0;
            $zip_offset = isset( $cp['zip_entry_offset'] ) ? (int) $cp['zip_entry_offset'] : 0;
            $zip_total  = isset( $cp['zip_total_entries'] ) ? (int) $cp['zip_total_entries'] : 0;
            $skipped_self_total = isset( $cp['zip_skipped_self'] ) ? (int) $cp['zip_skipped_self'] : 0;

            if ( $zip_total <= 0 && class_exists( 'ZipArchive' ) ) {
                $zip = new ZipArchive();
                if ( true === $zip->open( $file_path ) ) {
                    $zip_total = (int) $zip->numFiles;
                    $zip->close();
                    $meta['checkpoints']['zip_total_entries'] = $zip_total;
                }
            }

            $content_dir = function_exists( 'museder_restoreone_get_wp_content_dir' ) ? museder_restoreone_get_wp_content_dir() : '';
            $site_root   = '' !== $content_dir ? wp_normalize_path( (string) dirname( $content_dir ) ) : '';
            $result = self::extract_zip_prefix_sliced( $file_path, self::ZIP_WP_CONTENT_PREFIX, $site_root, $zip_index, $zip_offset, (int) $slice_seconds );
            $meta['checkpoints']['zip_index']        = $zip_index;
            $meta['checkpoints']['zip_entry_offset'] = $zip_offset;
            if ( is_array( $result ) && isset( $result['skipped_self'] ) ) {
                $skipped_self_total += (int) $result['skipped_self'];
                $meta['checkpoints']['zip_skipped_self'] = $skipped_self_total;
                if ( (int) $result['skipped_self'] > 0 && function_exists( 'museder_restoreone_log' ) ) {
                    museder_restoreone_log( 'info', 'Restore files: self-protect skipped plugin files.', [
                        'skipped' => (int) $result['skipped_self'],
                        'total'   => (int) $skipped_self_total,
                        'prefix'  => 'wp-content/plugins/museder-restoreone/',
                    ] );
                }
            }
            $pct = ( $zip_total > 0 ) ? min( 1.0, max( 0.0, $zip_index / $zip_total ) ) : 0.0;
            $meta['progress']   = min( 96, 90 + (int) floor( $pct * 6 ) );
            $meta['message']    = __( 'Restoring files…', 'museder-restoreone' );
            $meta['updated_at'] = current_time( 'mysql' );
            self::write_job_meta( $job_id, $meta );

            if ( ! empty( $result['completed'] ) ) {
                // Multisite target mapping: move extracted sites/{source} -> sites/{target} if requested.
                if ( function_exists( 'is_multisite' ) && is_multisite() && ! empty( $meta['options']['target_blog_id'] ) ) {
                    self::remap_multisite_uploads_to_target_blog_if_present( $meta['options']['target_blog_id'] );
                }

                // If this looks like a multisite subsite export (wp-content/uploads/sites/{id}),
                // flatten it into uploads/ for single-site restore.
                if ( function_exists( 'is_multisite' ) && ! is_multisite() ) {
                    self::flatten_multisite_uploads_if_present();
                }

                $meta['stage']      = 'search-replace';
                $meta['progress']   = 96;
                $meta['message']    = __( 'Preparing URL replacement…', 'museder-restoreone' );
                $meta['updated_at'] = current_time( 'mysql' );
                self::write_job_meta( $job_id, $meta );
            }
        }
    }

    /**
     * Best-effort detect multisite blog prefix from SQL file.
     *
     * Returns something like "wp_2_" (including trailing underscore), or '' if not detected.
     */
    protected static function detect_multisite_blog_prefix_from_sql( $sql_file ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading local SQL file in job temp dir
        $fh = fopen( $sql_file, 'rb' );
        if ( ! $fh ) {
            return '';
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        $head = fread( $fh, 1024 * 1024 ); // 1MB
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $fh );

        if ( ! is_string( $head ) || $head === '' ) {
            return '';
        }

        if ( preg_match( '/CREATE TABLE IF NOT EXISTS\\s+`([^`]+)`/i', $head, $m ) ) {
            $table = $m[1];
            if ( preg_match( '/^([A-Za-z0-9_]+_\\d+_)options$/', $table, $m2 ) ) {
                return $m2[1];
            }
        }

        return '';
    }

    /**
     * Best-effort detect the table prefix used by a SQL dump.
     *
     * Returns something like "qvj4_" or "wp_2_" (including trailing underscore), or '' if not detected.
     *
     * @param string $sql_file
     * @return string
     */
    protected static function detect_table_prefix_from_sql( $sql_file ) {
        $sql_file = (string) $sql_file;
        if ( '' === $sql_file || ! file_exists( $sql_file ) || ! is_readable( $sql_file ) ) {
            return '';
        }

        // Some large sites include plugin tables early in the dump which can contain misleading *_options tables
        // (e.g. schema_type_options). To avoid prefix mis-detection, scan the first N MB and score candidates
        // by how many WordPress core tables they match.
        $max_scan_bytes = 40 * 1024 * 1024; // 40MB
        $chunk_bytes    = 1024 * 1024;      // 1MB
        $buffer_keep    = 2 * 1024 * 1024;  // keep last 2MB for regex boundary safety

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

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reading local SQL file in job temp dir
        $fh = fopen( $sql_file, 'rb' );
        if ( ! $fh ) {
            return '';
        }

        try {
            while ( $scanned < $max_scan_bytes ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- reading SQL file
                $chunk = fread( $fh, $chunk_bytes );
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
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- reading local SQL file in job temp dir
            fclose( $fh );
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

            // Prefer higher score, then more distinct core tables, then earlier appearance.
            if (
                $score > $best_score
                || ( $score === $best_score && $distinct > $best_distinct )
                || ( $score === $best_score && $distinct === $best_distinct && $first < $best_first_seen )
            ) {
                $best_prefix    = (string) $prefix;
                $best_score     = $score;
                $best_distinct  = $distinct;
                $best_first_seen = $first;
            }
        }

        return $best_prefix;
    }

    /**
     * Create a rewritten copy of an SQL dump for MySQL CLI import when table prefixes differ.
     *
     * We only rewrite backticked identifiers (e.g. `wp_options`) to avoid touching data values.
     * This preserves the existing prefix-rewrite behavior that previously happened during PHP-sliced import.
     *
     * @param string $job_id
     * @param string $sql_file Absolute SQL file path.
     * @param string $rewrite_from_prefix Source prefix (e.g. "qvj4_").
     * @param string $rewrite_to_prefix   Target prefix (e.g. "wp_").
     *
     * @return array{path:string,temporary:bool}
     */
    protected static function rewrite_sql_prefix_file_for_cli( $job_id, $sql_file, $rewrite_from_prefix, $rewrite_to_prefix ) {
        $sql_file = (string) $sql_file;
        $rewrite_from_prefix = (string) $rewrite_from_prefix;
        $rewrite_to_prefix   = (string) $rewrite_to_prefix;

        if ( '' === $sql_file || ! file_exists( $sql_file ) || ! is_readable( $sql_file ) ) {
            return [ 'path' => $sql_file, 'temporary' => false ];
        }
        if ( '' === $rewrite_from_prefix || '' === $rewrite_to_prefix || $rewrite_from_prefix === $rewrite_to_prefix ) {
            return [ 'path' => $sql_file, 'temporary' => false ];
        }

        $out = $sql_file . '.rewrite.sql';

        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $in_fh  = fopen( $sql_file, 'rb' );
        $out_fh = fopen( $out, 'wb' );
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen

        if ( ! $in_fh || ! $out_fh ) {
            // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            if ( $in_fh ) {
                fclose( $in_fh );
            }
            if ( $out_fh ) {
                fclose( $out_fh );
            }
            // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            return [ 'path' => $sql_file, 'temporary' => false ];
        }

        $needle = '`' . $rewrite_from_prefix;
        $repl   = '`' . $rewrite_to_prefix;
        $rewritten_lines = 0;

        try {
            // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_read_fgets
            while ( false !== ( $line = fgets( $in_fh ) ) ) {
                if ( false !== strpos( $line, $needle ) ) {
                    $line = str_replace( $needle, $repl, $line );
                    $rewritten_lines++;
                }
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
                fwrite( $out_fh, $line );
            }
            // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_read_fgets
        } finally {
            // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose( $in_fh );
            fclose( $out_fh );
            // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        }

        if ( function_exists( 'museder_restoreone_log' ) ) {
            museder_restoreone_log( 'info', 'Prepared SQL file for MySQL CLI import with prefix rewrite.', [
                'job_id'    => (string) $job_id,
                'from'      => $rewrite_from_prefix,
                'to'        => $rewrite_to_prefix,
                'rewritten' => (int) $rewritten_lines,
                'file'      => basename( $out ),
            ] );
        }

        return [ 'path' => $out, 'temporary' => true ];
    }

    /**
     * If a ZIP restore produced wp-content/uploads/sites/{id}/..., move it to wp-content/uploads/.
     * This is a helper for multisite subsite->single restores.
     */
    protected static function flatten_multisite_uploads_if_present() {
        $uploads  = wp_upload_dir();
        $basedir  = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
        $sites_dir = '' !== $basedir ? wp_normalize_path( trailingslashit( $basedir ) . 'sites' ) : '';
        if ( ! is_dir( $sites_dir ) ) {
            return;
        }

        $entries = glob( trailingslashit( $sites_dir ) . '*', GLOB_ONLYDIR );
        if ( empty( $entries ) ) {
            return;
        }

        // Pick the first sites/{id} directory found.
        $source = wp_normalize_path( $entries[0] );
        $dest   = '' !== $basedir ? wp_normalize_path( $basedir ) : '';
        if ( '' === $dest ) {
            return;
        }
        if ( ! is_dir( $dest ) ) {
            wp_mkdir_p( $dest );
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ( $it as $item ) {
            $rel = $it->getSubPathName();
            $target = wp_normalize_path( trailingslashit( $dest ) . $rel );
            if ( $item->isDir() ) {
                wp_mkdir_p( $target );
            } else {
                wp_mkdir_p( dirname( $target ) );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- restore file operation, path is within wp-content/uploads
                @copy( $item->getPathname(), $target );
            }
        }
    }

    protected static function remap_multisite_uploads_to_target_blog_if_present( $target_blog_id ) {
        $target_blog_id = absint( $target_blog_id );
        if ( $target_blog_id <= 0 ) {
            return;
        }

        $uploads  = wp_upload_dir();
        $basedir  = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
        $sites_dir = '' !== $basedir ? wp_normalize_path( trailingslashit( $basedir ) . 'sites' ) : '';
        if ( ! is_dir( $sites_dir ) ) {
            return;
        }

        $entries = glob( trailingslashit( $sites_dir ) . '*', GLOB_ONLYDIR );
        if ( empty( $entries ) ) {
            return;
        }

        // Best-effort: pick the first extracted blog id dir and rename/merge into target.
        $source = wp_normalize_path( $entries[0] );
        $dest   = wp_normalize_path( trailingslashit( $sites_dir ) . $target_blog_id );
        if ( $source === $dest ) {
            return;
        }

        wp_mkdir_p( $dest );

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ( $it as $item ) {
            $rel = $it->getSubPathName();
            $target = wp_normalize_path( trailingslashit( $dest ) . $rel );
            if ( $item->isDir() ) {
                wp_mkdir_p( $target );
            } else {
                wp_mkdir_p( dirname( $target ) );
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- restore file operation, path is within wp-content/uploads
                @copy( $item->getPathname(), $target );
            }
        }
    }

    protected static function stage_cleanup_and_finish( $job_id, array $meta, $slice_seconds ) {
        $start = microtime( true );
        $slice_seconds = (int) $slice_seconds;

        // Checkpoints container.
        $cp = isset( $meta['checkpoints'] ) && is_array( $meta['checkpoints'] ) ? $meta['checkpoints'] : [];
        $cleanup = isset( $cp['cleanup'] ) && is_array( $cp['cleanup'] ) ? $cp['cleanup'] : [];
        if ( empty( $cleanup['step'] ) ) {
            $cleanup['step'] = 'flush_cache';
        }

        // Optional: background non-critical cleanup to avoid UI sitting at 99% on huge sites.
        // Default is false; enable via filter in custom integration.
        $background_cleanup = (bool) apply_filters( 'museder_restoreone_restore_background_cleanup', false, $job_id, $meta );
        if ( $background_cleanup && empty( $cleanup['bg_scheduled'] ) ) {
            $cleanup['bg_scheduled'] = 1;
            $cleanup['bg_scheduled_at'] = time();
            // De-duplicate before scheduling.
            if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
                wp_clear_scheduled_hook( self::CRON_HOOK_BG_CLEANUP, [ $job_id ] );
            }
            wp_schedule_single_event( time() + 5, self::CRON_HOOK_BG_CLEANUP, [ $job_id ] );
        }

        $safe_mode_entered = ! empty( $cleanup['safe_mode_entered'] );

        while ( true ) {
            $elapsed = microtime( true ) - $start;
            if ( $slice_seconds > 0 && $elapsed > $slice_seconds ) {
                break;
            }

            switch ( (string) $cleanup['step'] ) {
                case 'flush_cache':
            $meta['progress']   = 99;
                    $meta['message']    = __( 'Finalising restore… (clearing cache)', 'museder-restoreone' );
                    $meta['updated_at'] = current_time( 'mysql' );
            self::write_job_meta( $job_id, $meta );

                    if ( function_exists( 'wp_cache_flush' ) ) {
                        wp_cache_flush();
                    }
                    $cleanup['step'] = $background_cleanup ? 'fix_urls' : 'delete_transients';
                    $cleanup['transients_last_id'] = 0;
                    $cleanup['transients_batches'] = 0;
                    $cleanup['transients_deleted_total'] = 0;
                    break;

                case 'delete_transients':
                    // Delete transients in small batches to avoid long locks/timeouts near 99%.
                    $result = self::delete_transients_sliced( $cleanup, 2500, $slice_seconds, $start );
                    $cleanup['transients_batches'] = isset( $cleanup['transients_batches'] ) ? (int) $cleanup['transients_batches'] : 0;
                    $cleanup['transients_deleted_total'] = isset( $cleanup['transients_deleted_total'] ) ? (int) $cleanup['transients_deleted_total'] : 0;

                    if ( ! empty( $result['done'] ) ) {
                        $cleanup['step'] = 'flush_rewrite';
                    }

                    $meta['progress']   = 99;
                    $meta['message']    = sprintf(
                        /* translators: 1: batch number, 2: deleted total */
                        __( 'Finalising restore… (clearing transients, batch %1$d, deleted %2$d)', 'museder-restoreone' ),
                        max( 1, (int) $cleanup['transients_batches'] ),
                        (int) $cleanup['transients_deleted_total']
                    );
                    $meta['updated_at'] = current_time( 'mysql' );
                    $cp['cleanup'] = $cleanup;
                    $meta['checkpoints'] = $cp;
                    self::write_job_meta( $job_id, $meta );
                    break;

                case 'flush_rewrite':
                    $meta['progress']   = 99;
                    $meta['message']    = __( 'Finalising restore… (flushing permalinks)', 'museder-restoreone' );
                    $meta['updated_at'] = current_time( 'mysql' );
                    self::write_job_meta( $job_id, $meta );

                    if ( function_exists( 'flush_rewrite_rules' ) ) {
                        flush_rewrite_rules( false );
                    }
                    $cleanup['step'] = 'clear_alloptions_cache';
                    break;

                case 'clear_alloptions_cache':
                    $meta['progress']   = 99;
                    $meta['message']    = __( 'Finalising restore… (clearing options cache)', 'museder-restoreone' );
                    $meta['updated_at'] = current_time( 'mysql' );
                    self::write_job_meta( $job_id, $meta );

                    if ( function_exists( 'wp_cache_delete' ) ) {
                        wp_cache_delete( 'alloptions', 'options' );
                    }
                    $cleanup['step'] = 'fix_urls';
                    break;

                case 'fix_urls':
                    $meta['progress']   = 99;
                    $meta['message']    = __( 'Finalising restore… (validating site URLs)', 'museder-restoreone' );
                    $meta['updated_at'] = current_time( 'mysql' );
                    self::write_job_meta( $job_id, $meta );

                    self::ensure_site_urls_match_current_host();
                    $cleanup['step'] = 'restore_plugin_status';
                    break;

                case 'restore_plugin_status':
                    $meta['progress']   = 99;
                    $meta['message']    = __( 'Finalising restore… (restoring plugin status)', 'museder-restoreone' );
                    $meta['updated_at'] = current_time( 'mysql' );
                    self::write_job_meta( $job_id, $meta );

                    self::restore_plugin_status();
                    $cleanup['step'] = 'enter_safe_mode';
                    break;

                case 'enter_safe_mode':
                    $meta['progress']   = 99;
                    $meta['message']    = __( 'Finalising restore… (entering safe mode)', 'museder-restoreone' );
                    $meta['updated_at'] = current_time( 'mysql' );
                    self::write_job_meta( $job_id, $meta );

                    $safe_mode_entered = false;
                    $opt = ( isset( $meta['options'] ) && is_array( $meta['options'] ) ) ? $meta['options'] : [];
                    $want_safe_mode = true;
                    if ( array_key_exists( 'safe_mode', $opt ) ) {
                        $want_safe_mode = (bool) $opt['safe_mode'];
                    }

                    if ( $want_safe_mode ) {
                        try {
                            Museder_Restoreone_Restore::enter_safe_mode_after_import();
                            $safe_mode_entered = true;
                        } catch ( Exception $e ) {
                            museder_restoreone_log( 'warning', 'Failed to enter safe mode after restore.', [
                                'job_id' => $job_id,
                                'error' => $e->getMessage(),
                            ] );
                        }
                    } else {
                        // If safe mode is disabled for this restore, ensure no stale safe-mode flags linger.
                        if ( function_exists( 'delete_option' ) ) {
                            delete_option( 'museder_restoreone_safe_mode' );
                            delete_option( 'museder_restoreone_prev_active_plugins' );
                        }
                        if ( function_exists( 'museder_restoreone_log' ) ) {
                            museder_restoreone_log( 'info', 'Safe mode disabled by user option; cleared safe mode markers.', [
                                'job_id' => $job_id,
                            ] );
                        }
                    }
                    $cleanup['safe_mode_entered'] = $safe_mode_entered ? 1 : 0;
                    $cleanup['step'] = 'cleanup_tmp';
                    break;

                case 'cleanup_tmp':
                    $meta['progress']   = 99;
                    $meta['message']    = __( 'Finalising restore… (cleaning temp files)', 'museder-restoreone' );
                    $meta['updated_at'] = current_time( 'mysql' );
                    self::write_job_meta( $job_id, $meta );

                    try {
                        $tmp = trailingslashit( museder_restoreone_get_temp_dir() ) . $job_id;
                        if ( file_exists( $tmp ) ) {
                            museder_restoreone_delete_directory( $tmp );
                        }
                    } catch ( Exception $e ) {
                        // Ignore.
                    }
                    $cleanup['step'] = 'do_actions';
                    break;

                case 'do_actions':
                    $meta['progress']   = 99;
                    $meta['message']    = __( 'Finalising restore… (notifying hooks)', 'museder-restoreone' );
                    $meta['updated_at'] = current_time( 'mysql' );
                    self::write_job_meta( $job_id, $meta );

            $restore_meta = [
                'job_id' => $job_id,
                'file' => isset( $meta['file'] ) ? $meta['file'] : '',
                'file_name' => isset( $meta['file_name'] ) ? $meta['file_name'] : '',
                'source' => isset( $meta['source'] ) ? $meta['source'] : '',
                'siteurl_old' => isset( $meta['validation']['domain']['backup'] ) ? $meta['validation']['domain']['backup'] : '',
                'siteurl_new' => isset( $meta['validation']['domain']['current'] ) ? $meta['validation']['domain']['current'] : home_url(),
                        'safe_mode' => (bool) $safe_mode_entered,
                'completed_at' => current_time( 'mysql' ),
            ];
                do_action( 'museder_restoreone_after_restore', $restore_meta );
                if ( $safe_mode_entered ) {
                    do_action( 'museder_restoreone_after_restore_safe_mode', $restore_meta );
                }
                    $cleanup['step'] = 'finish';
                    break;

                case 'finish':
                    $cp['cleanup'] = $cleanup;
                    $meta['checkpoints'] = $cp;
            $meta['stage']      = 'done';
            $meta['progress']   = 100;
                    $meta['message']    = $background_cleanup
                        ? __( 'Restore completed successfully. Background cleanup queued.', 'museder-restoreone' )
                        : __( 'Restore completed successfully.', 'museder-restoreone' );
            $meta['completed']  = true;
            $meta['updated_at'] = current_time( 'mysql' );
            self::write_job_meta( $job_id, $meta );

                    // Restore History: mark success.
                    if ( function_exists( 'museder_restoreone_upsert_restore_history' ) ) {
                        $completed_at = time();
                        $started_at = isset( $meta['started_at'] ) ? (int) $meta['started_at'] : 0;
                        $duration = ( $started_at > 0 ) ? max( 0, $completed_at - $started_at ) : 0;
                        museder_restoreone_upsert_restore_history(
                            [
                                'job_id'                  => $job_id,
                                'timestamp_utc'           => $completed_at,
                                'date'                    => gmdate( 'Y-m-d H:i:s', $completed_at ),
                                'file'                    => isset( $meta['file'] ) ? basename( (string) $meta['file'] ) : '',
                                'result'                  => 'success',
                                'restore_started_at'      => $started_at,
                                'restore_completed_at'    => $completed_at,
                                'restore_duration_seconds'=> $duration,
                            ]
                        );
                    }

            Museder_Restoreone_Restore_Lock::release();

                    $active = (string) get_option( self::ACTIVE_JOB_OPTION, '' );
                    if ( $active === $job_id ) {
                        delete_option( self::ACTIVE_JOB_OPTION );
                    }
                    return;

                default:
                    $cleanup['step'] = 'flush_cache';
                    break;
            }

            // Persist checkpoints after each step.
            $cp['cleanup'] = $cleanup;
            $meta['checkpoints'] = $cp;
            $meta['progress'] = 99;
            $meta['updated_at'] = current_time( 'mysql' );
            self::write_job_meta( $job_id, $meta );
        }

        // Not finished within slice: keep stage as cleanup and persist current step.
        $cp['cleanup'] = $cleanup;
        $meta['checkpoints'] = $cp;
        $meta['progress'] = 99;
        $meta['updated_at'] = current_time( 'mysql' );
        if ( empty( $meta['message'] ) ) {
            $meta['message'] = __( 'Finalising restore…', 'museder-restoreone' );
        }
        self::write_job_meta( $job_id, $meta );
    }

    /**
     * Background cleanup job (non-critical) to avoid blocking the UI at 99% on huge sites.
     * Default is not enabled; scheduled only when filter museder_restoreone_restore_background_cleanup returns true.
     */
    public static function cron_background_cleanup( $job_id ) {
        try {
            $meta = self::get_job_meta( $job_id );
            $cp = isset( $meta['checkpoints'] ) && is_array( $meta['checkpoints'] ) ? $meta['checkpoints'] : [];
            $cleanup = isset( $cp['cleanup'] ) && is_array( $cp['cleanup'] ) ? $cp['cleanup'] : [];
            if ( empty( $cleanup['bg_scheduled'] ) ) {
                return;
            }
            $start = microtime( true );
            // Best-effort: clear transients in background (batch loop within ~20s).
            while ( ( microtime( true ) - $start ) < 20 ) {
                $res = self::delete_transients_sliced( $cleanup, 5000, 20, $start );
                if ( ! empty( $res['done'] ) ) {
                    break;
                }
            }
            // Flush rewrite rules after background transient cleanup.
            if ( function_exists( 'flush_rewrite_rules' ) ) {
                flush_rewrite_rules( false );
            }
            $cp['cleanup'] = $cleanup;
            $meta['checkpoints'] = $cp;
            self::write_job_meta( $job_id, $meta );
            museder_restoreone_log( 'info', 'Restore background cleanup completed.', [ 'job_id' => $job_id ] );
        } catch ( Exception $e ) {
            museder_restoreone_log( 'warning', 'Restore background cleanup failed.', [ 'job_id' => $job_id, 'error' => $e->getMessage() ] );
        }
    }

    /**
     * Ensure siteurl/home match current host (best-effort safety net).
     */
    protected static function ensure_site_urls_match_current_host() {
        $protocol = is_ssl() ? 'https' : 'http';
        $host     = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
        if ( empty( $host ) ) {
            return;
        }
        $expected_site_url = $protocol . '://' . $host;
        $expected_home_url = $expected_site_url;
        $current_site_url = get_option( 'siteurl' );
        $current_home_url = get_option( 'home' );
        if ( rtrim( (string) $current_site_url, '/' ) !== rtrim( (string) $expected_site_url, '/' ) ) {
            update_option( 'siteurl', $expected_site_url );
            museder_restoreone_log( 'info', 'Updated siteurl option after restore.', [ 'old' => $current_site_url, 'new' => $expected_site_url ] );
        }
        if ( rtrim( (string) $current_home_url, '/' ) !== rtrim( (string) $expected_home_url, '/' ) ) {
            update_option( 'home', $expected_home_url );
            museder_restoreone_log( 'info', 'Updated home option after restore.', [ 'old' => $current_home_url, 'new' => $expected_home_url ] );
        }
    }

    /**
     * Delete transients in small batches, tracking progress in $cleanup checkpoint state.
     *
     * @param array $cleanup Cleanup checkpoint state (by reference).
     * @param int   $limit   Max rows per batch.
     * @param int   $slice_seconds Slice budget.
     * @param float $slice_start Start time (microtime true) for the current slice.
     * @return array{done:bool,deleted:int}
     */
    protected static function delete_transients_sliced( array &$cleanup, $limit, $slice_seconds, $slice_start ) {
        global $wpdb;
        $limit = max( 100, (int) $limit );
        $last_id = isset( $cleanup['transients_last_id'] ) ? (int) $cleanup['transients_last_id'] : 0;
        $cleanup['transients_batches'] = isset( $cleanup['transients_batches'] ) ? (int) $cleanup['transients_batches'] : 0;
        $cleanup['transients_deleted_total'] = isset( $cleanup['transients_deleted_total'] ) ? (int) $cleanup['transients_deleted_total'] : 0;

        // Use escaped underscore so LIKE matches literal "_transient_" prefixes.
        $like1 = '\\_transient_%';
        $like2 = '\\_site_transient_%';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_id FROM {$wpdb->options} WHERE option_id > %d AND (option_name LIKE %s OR option_name LIKE %s) ORDER BY option_id ASC LIMIT %d",
                $last_id,
                $like1,
                $like2,
                $limit
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

        if ( empty( $ids ) || ! is_array( $ids ) ) {
            return [ 'done' => true, 'deleted' => 0 ];
        }

        $ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
        if ( empty( $ids ) ) {
            return [ 'done' => true, 'deleted' => 0 ];
        }
        $cleanup['transients_last_id'] = max( $ids );
        $cleanup['transients_batches']++;

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql          = "DELETE FROM {$wpdb->options} WHERE option_id IN ($placeholders)"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- core table name from $wpdb; dynamic placeholders will be prepared below
        $args         = array_merge( [ $sql ], $ids );
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built from absint() IDs, table is core ($wpdb->options)
        $deleted = $wpdb->query( call_user_func_array( [ $wpdb, 'prepare' ], $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared from absint IDs; core table name from $wpdb
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $deleted = is_numeric( $deleted ) ? (int) $deleted : 0;
        $cleanup['transients_deleted_total'] += max( 0, $deleted );

        // Respect slice budget.
        if ( $slice_seconds > 0 && ( microtime( true ) - (float) $slice_start ) > $slice_seconds ) {
            return [ 'done' => false, 'deleted' => $deleted ];
        }

        return [ 'done' => false, 'deleted' => $deleted ];
    }

    protected static function extract_zip_entry_to_path( $zip_path, $entry_name, $dest_path ) {
        // Prefer ZipArchive streaming extraction when available; fall back to bundled PclZip on hosts
        // where ZipArchive cannot open large ZIPs (observed as ZipArchive::open error 19).
        if ( class_exists( 'ZipArchive' ) ) {
        $zip = new ZipArchive();
            $open_result = $zip->open( $zip_path );
            if ( true === $open_result ) {
        $stream = $zip->getStream( $entry_name );
        if ( ! $stream ) {
            $zip->close();
            return;
        }

        museder_restoreone_ensure_directory( dirname( $dest_path ) );

        // Large ZIP streaming requires direct file operations for performance and compatibility.
        $out = fopen( $dest_path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Stream extraction to disk.
        if ( $out ) {
            while ( ! feof( $stream ) ) {
                $buf = fread( $stream, 1024 * 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Stream extraction to disk.
                if ( $buf === false ) {
                    break;
                }
                fwrite( $out, $buf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Stream extraction to disk.
            }
            fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Stream extraction to disk.
        }
        fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Stream from ZipArchive.
        $zip->close();
                return;
            }
        }

        // Fallback: PclZip extraction by name, stripping directory prefixes.
        if ( function_exists( 'museder_restoreone_require_pclzip' ) ) {
            museder_restoreone_require_pclzip();
        }
        if ( ! class_exists( 'PclZip' ) ) {
            throw new RuntimeException( esc_html__( 'ZIP extraction is not available on this server.', 'museder-restoreone' ) );
        }

        museder_restoreone_ensure_directory( dirname( $dest_path ) );
        $pcl = new PclZip( $zip_path );
        $remove_path = dirname( (string) $entry_name );
        if ( '.' === $remove_path ) {
            $remove_path = '';
        }

        // PclZip extracts to a directory; use REMOVE_PATH so subdir entries land as basename.
        $result = $pcl->extract(
            PCLZIP_OPT_BY_NAME,
            (string) $entry_name,
            PCLZIP_OPT_PATH,
            dirname( $dest_path ),
            ( '' !== $remove_path ? PCLZIP_OPT_REMOVE_PATH : PCLZIP_OPT_REMOVE_ALL_PATH ),
            ( '' !== $remove_path ? $remove_path : true )
        );

        if ( ! is_array( $result ) || empty( $result ) ) {
            return;
        }

        // Ensure extracted file ends up at dest_path; if PclZip wrote to a different name, normalize it.
        $expected = basename( (string) $dest_path );
        $candidate = trailingslashit( dirname( $dest_path ) ) . $expected;
        if ( $candidate !== $dest_path && file_exists( $candidate ) && ! file_exists( $dest_path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- plugin-controlled temp paths
            @rename( $candidate, $dest_path );
        }
    }

    protected static function extract_zip_prefix_sliced( $zip_path, $prefix, $dest_base, &$entry_index, &$entry_offset, $slice_seconds ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            throw new RuntimeException( esc_html__( 'ZipArchive is not available on this server.', 'museder-restoreone' ) );
        }

        $start = microtime( true );
        $zip   = new ZipArchive();
        if ( true !== $zip->open( $zip_path ) ) {
            throw new RuntimeException( esc_html__( 'Unable to open ZIP archive.', 'museder-restoreone' ) );
        }

        $count = $zip->numFiles;
        $prefix = (string) $prefix;

        // Allow sites to disable self-protection if they truly need to restore the plugin itself.
        // Default: true (protect this plugin from being overwritten mid-restore).
        $protect_self = (bool) apply_filters( 'museder_restoreone_restore_protect_self', true );
        $self_prefix  = 'wp-content/plugins/museder-restoreone/';
        $skipped_self = 0;

        for ( $i = $entry_index; $i < $count; $i++ ) {
            $name = $zip->getNameIndex( $i );
            if ( $name === false ) {
                continue;
            }
            if ( strpos( $name, $prefix ) !== 0 ) {
                continue;
            }
            // Protect the currently-running plugin from being overwritten mid-restore.
            // This prevents admin-ajax actions from disappearing (returning "0") and stalling the UI/processor.
            if ( $protect_self && strpos( $name, $self_prefix ) === 0 ) {
                $skipped_self++;
                continue;
            }
            if ( substr( $name, -1 ) === '/' ) {
                continue;
            }
            if ( museder_restoreone_safe_path_join( $dest_base, $name ) === false ) {
                continue;
            }

            $target = museder_restoreone_safe_path_join( $dest_base, $name );
            if ( ! $target ) {
                continue;
            }

            $in = $zip->getStream( $name );
            if ( ! $in ) {
                continue;
            }

            museder_restoreone_ensure_directory( dirname( $target ) );

            // Large ZIP streaming requires direct file operations for performance and compatibility.
            $out = fopen( $target, ( $entry_offset > 0 ? 'ab' : 'wb' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Stream extraction to disk.
            if ( $out ) {
                // Skip bytes if resuming the same entry.
                $to_skip = (int) $entry_offset;
                while ( $to_skip > 0 && ! feof( $in ) ) {
                    $skip_chunk = $to_skip > 65536 ? 65536 : $to_skip;
                    $buf = fread( $in, $skip_chunk ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Stream extraction to disk.
                    if ( $buf === false || $buf === '' ) {
                        break;
                    }
                    $to_skip -= strlen( $buf );
                }

                $written_this_entry = 0;
                while ( ! feof( $in ) ) {
                    $buf = fread( $in, 512000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Stream extraction to disk.
                    if ( $buf === false ) {
                        break;
                    }
                    if ( $buf === '' ) {
                        break;
                    }
                    $w = fwrite( $out, $buf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Stream extraction to disk.
                    if ( $w === false ) {
                        break;
                    }
                    $written_this_entry += $w;
                    $entry_offset += $w;

                    if ( $slice_seconds > 0 && ( microtime( true ) - $start ) > $slice_seconds ) {
                        fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Stream extraction to disk.
                        fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Stream from ZipArchive.
                        $zip->close();
                        $entry_index = $i;
                        return [ 'completed' => false, 'skipped_self' => $skipped_self ];
                    }
                }
                fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Stream extraction to disk.
            }
            fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Stream from ZipArchive.

            // Move to next entry.
            $entry_offset = 0;
            $entry_index  = $i + 1;

            if ( $slice_seconds > 0 && ( microtime( true ) - $start ) > $slice_seconds ) {
                $zip->close();
                return [ 'completed' => false, 'skipped_self' => $skipped_self ];
            }
        }

        $zip->close();
        return [ 'completed' => true, 'skipped_self' => $skipped_self ];
    }

    /**
     * Rollback to the latest pre-backup snapshot (placeholder).
     */
    public static function rollback( $job_id ) {
        $meta = self::get_job_meta( $job_id );

        if ( empty( $meta['pre_backup']['file'] ) ) {
            throw new RuntimeException( esc_html__( 'No pre-restore snapshot available.', 'museder-restoreone' ) );
        }

        if ( Museder_Restoreone_Restore_Lock::is_locked() && ! self::is_current_lock( $job_id ) ) {
            throw new RuntimeException( esc_html__( 'Another restore operation is currently running.', 'museder-restoreone' ) );
        }

        if ( ! Museder_Restoreone_Restore_Lock::acquire( $job_id ) ) {
            throw new RuntimeException( esc_html__( 'Failed to acquire restore lock.', 'museder-restoreone' ) );
        }

        try {
            $meta['stage']      = 'rollback';
            $meta['progress']   = 40;
            $meta['message']    = __( 'Restoring from pre-restore snapshot…', 'museder-restoreone' );
            $meta['updated_at'] = current_time( 'mysql' );
            self::write_job_meta( $job_id, $meta );

            self::restore_from_snapshot( $meta['pre_backup'] );

            $meta['stage']      = 'rollback-done';
            $meta['progress']   = 100;
            $meta['message']    = __( 'Rollback completed successfully.', 'museder-restoreone' );
            $meta['updated_at'] = current_time( 'mysql' );
            self::write_job_meta( $job_id, $meta );

            museder_restoreone_log( 'info', 'Restore rollback executed.', [ 'job_id' => $job_id ] );

            return [ 'ok' => true, 'message' => __( 'Rollback completed successfully.', 'museder-restoreone' ) ];
        } catch ( Exception $e ) {
            museder_restoreone_log( 'error', 'Restore rollback failed.', [ 'job_id' => $job_id, 'error' => $e->getMessage() ] );
            throw $e;
        } finally {
            Museder_Restoreone_Restore_Lock::release();
            // Clear active job pointer after rollback completes.
            $active = (string) get_option( self::ACTIVE_JOB_OPTION, '' );
            if ( $active === $job_id ) {
                delete_option( self::ACTIVE_JOB_OPTION );
            }
        }
    }

    /**
     * Time-sliced, resumable search-replace stage (AI1WM-like).
     *
     * @param string $job_id
     * @param array  $meta
     * @param int    $slice_seconds
     */
    protected static function stage_search_replace_sliced( $job_id, array $meta, $slice_seconds ) {
        $meta['progress']   = 96;
        $meta['message']    = __( 'Applying URL search & replace…', 'museder-restoreone' );
        $meta['updated_at'] = current_time( 'mysql' );
        self::write_job_meta( $job_id, $meta );

        // Only run when migrating domains.
        if ( empty( $meta['validation']['domain']['migrateMode'] ) ) {
            $meta['stage']      = 'cleanup';
            $meta['progress']   = 99;
            $meta['message']    = __( 'Finalising restore and cleaning up…', 'museder-restoreone' );
            $meta['updated_at'] = current_time( 'mysql' );
            self::write_job_meta( $job_id, $meta );
            return;
        }

        // Build URL replacement pairs.
        $pairs = self::build_url_replacement_pairs( $meta );
        if ( empty( $pairs ) ) {
            $meta['stage']      = 'cleanup';
            $meta['progress']   = 99;
            $meta['message']    = __( 'Finalising restore and cleaning up…', 'museder-restoreone' );
            $meta['updated_at'] = current_time( 'mysql' );
            self::write_job_meta( $job_id, $meta );
            return;
        }

        $cp = isset( $meta['checkpoints'] ) && is_array( $meta['checkpoints'] ) ? $meta['checkpoints'] : [];
        $result = self::run_search_replace_sliced( $pairs, $cp, (int) $slice_seconds );
        $meta['checkpoints'] = $cp;

        // Progress mapping: 96–99 for search-replace, based on table index.
        $total = ! empty( $cp['sr_tables'] ) && is_array( $cp['sr_tables'] ) ? count( $cp['sr_tables'] ) : 0;
        $idx   = isset( $cp['sr_table_index'] ) ? (int) $cp['sr_table_index'] : 0;
        $pct   = ( $total > 0 ) ? min( 1.0, max( 0.0, $idx / $total ) ) : 0.0;
        $meta['progress']   = 96 + (int) floor( $pct * 3 ); // 96..99 (exclusive of 99 for completion)
        $scanned = isset( $cp['sr_scanned_rows'] ) ? (int) $cp['sr_scanned_rows'] : 0;
        $updated = isset( $cp['sr_updated_rows'] ) ? (int) $cp['sr_updated_rows'] : 0;
        $meta['message']    = sprintf(
            /* translators: 1: current table index, 2: total tables, 3: scanned rows, 4: updated rows */
            __( 'Applying URL search & replace… (%1$d/%2$d tables, scanned %3$d, updated %4$d)', 'museder-restoreone' ),
            min( $idx + 1, max( 1, $total ) ),
            max( 1, $total ),
            $scanned,
            $updated
        );
        $meta['updated_at'] = current_time( 'mysql' );
        self::write_job_meta( $job_id, $meta );

        if ( ! empty( $result['completed'] ) ) {
            $meta['stage']      = 'cleanup';
            $meta['progress']   = 99;
            $meta['message']    = __( 'Finalising restore and cleaning up…', 'museder-restoreone' );
            $meta['updated_at'] = current_time( 'mysql' );
            self::write_job_meta( $job_id, $meta );
        }
    }

    /**
     * Sliced implementation of run_search_replace(). Updates checkpoints in $cp.
     *
     * @param array $pairs
     * @param array $cp
     * @param int   $timeout_seconds
     * @return array{completed:bool,scanned:int,updated:int}
     */
    protected static function run_search_replace_sliced( array $pairs, array &$cp, $timeout_seconds = 10 ) {
        global $wpdb;

        $start = microtime( true );

        if ( ! isset( $cp['sr_tables'] ) || ! is_array( $cp['sr_tables'] ) || empty( $cp['sr_tables'] ) ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $tables = $wpdb->get_col( 'SHOW TABLES' );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $cp['sr_tables'] = is_array( $tables ) ? array_values( $tables ) : [];
            $cp['sr_table_index']  = 0;
            $cp['sr_pk_last']      = null;
            $cp['sr_row_offset']   = 0;
            $cp['sr_scanned_rows'] = 0;
            $cp['sr_updated_rows'] = 0;
            $cp['sr_table_cache']  = [];
        }

        $tables = isset( $cp['sr_tables'] ) && is_array( $cp['sr_tables'] ) ? $cp['sr_tables'] : [];
        $idx    = isset( $cp['sr_table_index'] ) ? (int) $cp['sr_table_index'] : 0;

        $scanned = 0;
        $updated = 0;

        if ( $idx >= count( $tables ) ) {
            return [ 'completed' => true, 'scanned' => 0, 'updated' => 0 ];
        }

        $batch_size = 50;

        while ( $timeout_seconds <= 0 || ( microtime( true ) - $start ) < $timeout_seconds ) {
            if ( $idx >= count( $tables ) ) {
                $cp['sr_table_index'] = $idx;
                return [ 'completed' => true, 'scanned' => $scanned, 'updated' => $updated ];
            }

            $table      = (string) $tables[ $idx ];
            $safe_table = sanitize_key( $table );
            if ( '' === $safe_table ) {
                $idx++;
                $cp['sr_table_index'] = $idx;
                $cp['sr_pk_last'] = null;
                $cp['sr_row_offset'] = 0;
                continue;
            }

            // Cache per-table metadata: pk + text columns.
            if ( empty( $cp['sr_table_cache'][ $safe_table ] ) || ! is_array( $cp['sr_table_cache'][ $safe_table ] ) ) {
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $columns = $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $safe_table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifiers sanitized + escaped
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

                if ( empty( $columns ) ) {
                    $idx++;
                    $cp['sr_table_index'] = $idx;
                    $cp['sr_pk_last'] = null;
                    $cp['sr_row_offset'] = 0;
                    continue;
                }

                $targets = [];
                foreach ( $columns as $col ) {
                    $type = isset( $col['Type'] ) ? strtolower( (string) $col['Type'] ) : '';
                    $field = isset( $col['Field'] ) ? (string) $col['Field'] : '';
                    if ( '' === $field ) {
                        continue;
                    }
                    // Include common text-like types.
                    if (
                        false !== strpos( $type, 'text' )
                        || 0 === strpos( $type, 'varchar' )
                        || 0 === strpos( $type, 'char' )
                    ) {
                        $targets[] = $field;
                    }
                }

                // Detect primary key column (first column with Key=PRI from SHOW COLUMNS).
                $pk = '';
                $pk_type = '';
                foreach ( $columns as $col ) {
                    $key = isset( $col['Key'] ) ? (string) $col['Key'] : '';
                    if ( 'PRI' === $key && ! empty( $col['Field'] ) ) {
                        $pk = (string) $col['Field'];
                        $pk_type = isset( $col['Type'] ) ? strtolower( (string) $col['Type'] ) : '';
                        break;
                    }
                }

                $cp['sr_table_cache'][ $safe_table ] = [
                    'pk'      => $pk,
                    'pk_type' => $pk_type,
                    'targets' => $targets,
                ];
            }

            $cache = $cp['sr_table_cache'][ $safe_table ];
            $pk    = isset( $cache['pk'] ) ? (string) $cache['pk'] : '';
            $pk_type = isset( $cache['pk_type'] ) ? strtolower( (string) $cache['pk_type'] ) : '';
            $targets = isset( $cache['targets'] ) && is_array( $cache['targets'] ) ? $cache['targets'] : [];

            if ( empty( $targets ) ) {
                // No text fields to update.
                $idx++;
                $cp['sr_table_index'] = $idx;
                $cp['sr_pk_last'] = null;
                $cp['sr_row_offset'] = 0;
                continue;
            }

            // Build SELECT query.
            $select_cols = $targets;
            if ( $pk ) {
                array_unshift( $select_cols, $pk );
                $select_cols = array_values( array_unique( $select_cols ) );
            }
            $select_sql_cols = [];
            foreach ( $select_cols as $c ) {
                $safe_c = sanitize_key( (string) $c );
                if ( '' !== $safe_c ) {
                    $select_sql_cols[] = '`' . esc_sql( $safe_c ) . '`';
                }
            }
            if ( empty( $select_sql_cols ) ) {
                $idx++;
                $cp['sr_table_index'] = $idx;
                $cp['sr_pk_last'] = null;
                $cp['sr_row_offset'] = 0;
                continue;
            }

            $rows = [];
            if ( $pk ) {
                $last = $cp['sr_pk_last'];
                $pk_sql = esc_sql( sanitize_key( $pk ) );
                $pk_is_numeric = ( $pk_type !== '' ) && preg_match( '/^(tinyint|smallint|mediumint|int|bigint)/i', $pk_type );

                if ( null !== $last && '' !== $last ) {
                    // Avoid lexicographical issues on numeric PKs by using integer comparison.
                    if ( $pk_is_numeric ) {
                        $last_int = (int) $last;
                        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $rows = $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $select_sql_cols built from sanitized + esc_sql() identifiers
                            $wpdb->prepare(
								'SELECT ' . implode( ',', $select_sql_cols ) . ' FROM `' . esc_sql( $safe_table ) . '` WHERE `' . $pk_sql . '` > %d ORDER BY `' . $pk_sql . '` ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifiers sanitized + escaped; values prepared
                                $last_int,
                                (int) $batch_size
                            ),
                            ARRAY_A
                        );
                        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    } else {
                        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $rows = $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $select_sql_cols built from sanitized + esc_sql() identifiers
                            $wpdb->prepare(
								'SELECT ' . implode( ',', $select_sql_cols ) . ' FROM `' . esc_sql( $safe_table ) . '` WHERE `' . $pk_sql . '` > %s ORDER BY `' . $pk_sql . '` ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifiers sanitized + escaped; values prepared
                                (string) $last,
                                (int) $batch_size
                            ),
                            ARRAY_A
                        );
                        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    }
                } else {
                    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $rows = $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $select_sql_cols built from sanitized + esc_sql() identifiers
                        $wpdb->prepare(
							'SELECT ' . implode( ',', $select_sql_cols ) . ' FROM `' . esc_sql( $safe_table ) . '` ORDER BY `' . $pk_sql . '` ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifiers sanitized + escaped; values prepared
                            (int) $batch_size
                        ),
                        ARRAY_A
                    );
                    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                }
            } else {
                $offset = isset( $cp['sr_row_offset'] ) ? (int) $cp['sr_row_offset'] : 0;
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $rows = $wpdb->get_results( // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $select_sql_cols built from sanitized + esc_sql() identifiers
                    $wpdb->prepare(
						'SELECT ' . implode( ',', $select_sql_cols ) . ' FROM `' . esc_sql( $safe_table ) . '` LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifiers sanitized + escaped; values prepared
                        (int) $batch_size,
                        (int) $offset
                    ),
                    ARRAY_A
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            }

            if ( empty( $rows ) ) {
                // Finished this table.
                $idx++;
                $cp['sr_table_index'] = $idx;
                $cp['sr_pk_last'] = null;
                $cp['sr_row_offset'] = 0;
                continue;
            }

            foreach ( $rows as $row ) {
                $scanned++;
                $cp['sr_scanned_rows'] = isset( $cp['sr_scanned_rows'] ) ? (int) $cp['sr_scanned_rows'] + 1 : 1;

                $update_data = [];
                foreach ( $targets as $field ) {
                    if ( ! array_key_exists( $field, $row ) ) {
                        continue;
                    }
                    $original = $row[ $field ];
                    $maybe = self::serialized_replace_recursive( $pairs, $original );
                    if ( $maybe !== $original ) {
                        $update_data[ $field ] = $maybe;
                    }
                }

                if ( ! empty( $update_data ) ) {
                    // Use PK when available; otherwise fall back to first column (best-effort, matches previous behavior).
                    $where_key = $pk ? $pk : array_key_first( $row );
                    if ( $where_key && array_key_exists( $where_key, $row ) ) {
                        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $wpdb->update( $safe_table, $update_data, [ $where_key => $row[ $where_key ] ] );
                        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $updated++;
                        $cp['sr_updated_rows'] = isset( $cp['sr_updated_rows'] ) ? (int) $cp['sr_updated_rows'] + 1 : 1;
                    }
                }

                if ( $pk && array_key_exists( $pk, $row ) ) {
                    $cp['sr_pk_last'] = $row[ $pk ];
                } else {
                    $cp['sr_row_offset'] = isset( $cp['sr_row_offset'] ) ? (int) $cp['sr_row_offset'] + 1 : 1;
                }

                if ( $timeout_seconds > 0 && ( microtime( true ) - $start ) > $timeout_seconds ) {
                    break;
                }
            }

            // Stop if out of time.
            if ( $timeout_seconds > 0 && ( microtime( true ) - $start ) > $timeout_seconds ) {
                break;
            }
        }

        $cp['sr_table_index'] = $idx;

        return [
            'completed' => ( $idx >= count( $tables ) ),
            'scanned'   => $scanned,
            'updated'   => $updated,
        ];
    }

    /**
     * Retrieve a job status snapshot.
     *
     * @param string $job_id Job identifier.
     *
     * @return array
     */
    public static function status( $job_id ) {
        $meta = self::get_job_meta( $job_id );

        return [
            'ok'       => true,
            'stage'    => isset( $meta['stage'] ) ? $meta['stage'] : 'pending',
            'progress' => isset( $meta['progress'] ) ? (int) $meta['progress'] : 0,
            'message'  => isset( $meta['message'] ) ? $meta['message'] : '',
            'completed'=> ! empty( $meta['completed'] ),
            'job_id'   => $job_id,
            'rollback_available' => ! empty( $meta['pre_backup']['file'] ),
            // Safe timing signals for frontend polling/timers (no sensitive paths).
            'last_tick' => isset( $meta['last_tick'] ) ? (int) $meta['last_tick'] : 0,
            'started_at' => isset( $meta['started_at'] ) ? (int) $meta['started_at'] : 0,
            'created_at' => isset( $meta['created_at'] ) ? (string) $meta['created_at'] : '',
            'updated_at' => isset( $meta['updated_at'] ) ? (string) $meta['updated_at'] : '',
        ];
    }

    /**
     * Read job metadata.
     */
    public static function get_job_meta( $job_id ) {
        $path = self::job_meta_path( $job_id );
        if ( ! file_exists( $path ) ) {
            throw new RuntimeException( esc_html__( 'Restore job not found.', 'museder-restoreone' ) );
        }

        $contents = file_get_contents( $path );
        $data     = json_decode( $contents, true );

        if ( ! is_array( $data ) ) {
            throw new RuntimeException( esc_html__( 'Corrupted restore job metadata.', 'museder-restoreone' ) );
        }

        return $data;
    }

    /**
     * Generate a unique job identifier.
     */
    protected static function generate_job_id() {
        $token = function_exists( 'wp_generate_password' ) ? wp_generate_password( 6, false, false ) : wp_rand( 100000, 999999 );
        return 'rjb_' . museder_restoreone_local_time( 'Ymd_His' ) . '_' . strtolower( $token );
    }

    /**
     * Ensure job directory exists.
     */
    protected static function ensure_job_directory( $job_id ) {
        $dir = trailingslashit( museder_restoreone_get_jobs_dir() ) . $job_id;
        if ( ! file_exists( $dir ) ) {
            museder_restoreone_ensure_directory( $dir );
        }
        return $dir;
    }

    /**
     * Ensure job temp directory exists (within global temp root).
     */
    protected static function ensure_job_tmp_directory( $job_id ) {
        $dir = trailingslashit( museder_restoreone_get_temp_dir() ) . $job_id;
        if ( ! file_exists( $dir ) ) {
            museder_restoreone_ensure_directory( $dir );
        }
        return $dir;
    }

    /**
     * Persist job metadata to disk.
     */
    protected static function write_job_meta( $job_id, array $meta ) {
        $path = self::job_meta_path( $job_id );
        $meta = wp_parse_args( $meta, [ 'logs' => [] ] );
        $json = wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

        if ( false === file_put_contents( $path, $json, LOCK_EX ) ) {
            throw new RuntimeException( esc_html__( 'Unable to write restore job metadata.', 'museder-restoreone' ) );
        }
    }

    /**
     * Resolve job metadata path.
     */
    protected static function job_meta_path( $job_id ) {
        return trailingslashit( museder_restoreone_get_jobs_dir() ) . $job_id . self::JOB_META_EXTENSION;
    }

    /**
     * Calculate a lightweight dry-run summary (placeholder implementation).
     */
    protected static function calculate_dry_run_summary( array $meta ) {
        return [
            'tables' => isset( $meta['validation']['dbScan'] ) ? $meta['validation']['dbScan'] : [ 'add' => 0, 'update' => 0, 'drop' => 0 ],
            'files'  => self::summarise_file_changes( $meta ),
            'warnings' => self::detect_restore_warnings( $meta ),
        ];
    }

    /**
     * Check whether current lock belongs to job.
     */
    protected static function is_current_lock( $job_id ) {
        $lock = Museder_Restoreone_Restore_Lock::current_lock();
        return $lock && isset( $lock['job_id'] ) && $lock['job_id'] === $job_id;
    }

    protected static function create_pre_backup() {
        // Creating a full site snapshot can take a long time on large sites.
        // phpcs:ignore WordPress.PHP.NoSetTimeLimit
        if ( function_exists( 'set_time_limit' ) ) {
            // @phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged
            @set_time_limit( 600 );
            // @phpcs:enable Squiz.PHP.DiscouragedFunctions.Discouraged
        }

        $t0 = microtime( true );
        museder_restoreone_log( 'info', 'Pre-restore backup snapshot: backup_site() started.' );
        $result = Museder_Restoreone_Backup::backup_site();
        $elapsed = microtime( true ) - $t0;

        if ( empty( $result['success'] ) || empty( $result['file'] ) ) {
            museder_restoreone_log(
                'error',
                'Pre-restore backup snapshot: backup_site() failed.',
                [
                    'elapsed' => round( $elapsed, 3 ),
                    'result'  => is_array( $result ) ? wp_json_encode( $result ) : sanitize_text_field( (string) $result ),
                ]
            );
            throw new RuntimeException( esc_html__( 'Failed to create pre-restore backup snapshot.', 'museder-restoreone' ) );
        }

        museder_restoreone_log(
            'info',
            'Pre-restore backup snapshot: backup_site() finished.',
            [
                'elapsed' => round( $elapsed, 3 ),
                'file'    => isset( $result['file'] ) ? sanitize_text_field( (string) $result['file'] ) : '',
            ]
        );

        return [
            'file'       => $result['file'],
            'created_at' => current_time( 'mysql' ),
        ];
    }

    protected static function extract_archive_metadata( $job_id, $file_path ) {
        $extract_dir = self::ensure_job_tmp_directory( $job_id );

        $meta_file = trailingslashit( $extract_dir ) . 'backup-lite-meta.json';

        if ( file_exists( $meta_file ) ) {
            $decoded = json_decode( file_get_contents( $meta_file ), true );
            return is_array( $decoded ) ? $decoded : [];
        }

        $ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

        // WPRESS / AI1WM: read package.json for encryption + basic metadata (best-effort).
        if ( 'wpress' === $ext ) {
            $pkg_path = trailingslashit( $extract_dir ) . 'package.json';
            if ( ! file_exists( $pkg_path ) ) {
                $archive_offset = 0;
                $file_offset    = 0;
                $processed       = 0;
                $extractor = new Museder_Restoreone_Wpress_Extractor( $file_path );
                // Extract package.json only (never encrypted in AI1WM).
                $extractor->extract_filtered_sliced( $extract_dir, [ 'package.json' ], [], [], $archive_offset, $file_offset, $processed, 30 );
                $extractor->close();
            }

            if ( file_exists( $pkg_path ) ) {
                $decoded = json_decode( file_get_contents( $pkg_path ), true );
                return is_array( $decoded ) ? $decoded : [];
            }

            return [];
        }

        // ZIP (RestoreOne backups): read backup-lite-meta.json inside archive.
        $archive = new ZipArchive();
        if ( true === $archive->open( $file_path ) ) {
            $index = $archive->locateName( 'backup-lite-meta.json', ZipArchive::FL_NOCASE | ZipArchive::FL_NODIR );
            if ( false !== $index ) {
                $content = $archive->getFromIndex( $index );
                file_put_contents( $meta_file, $content );
                $archive->close();
                $decoded = json_decode( $content, true );
                return is_array( $decoded ) ? $decoded : [];
            }
            $archive->close();
        }

        return [];
    }

    protected static function summarise_database_structure( array $metadata ) {
        if ( ! isset( $metadata['tables'] ) || ! is_array( $metadata['tables'] ) ) {
            return [ 'add' => 0, 'update' => 0, 'conflict' => 0 ];
        }

        $tables = $metadata['tables'];
        $counts = [ 'add' => 0, 'update' => 0, 'conflict' => 0 ];
        global $wpdb;

        foreach ( $tables as $table ) {
            $name = isset( $table['name'] ) ? $table['name'] : '';
            if ( empty( $name ) ) {
                continue;
            }
            // 這段查詢用於備份／還原流程中的資料庫狀態檢查或結構調整，
            // 輸入值來自系統內部狀態，不包含直接的使用者輸入。
            // 為了確保相容性與效能，此處使用直接查詢而非 WP_Query。
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( empty( $exists ) ) {
                $counts['add']++;
            } else {
                $counts['update']++;
            }
        }

        return $counts;
    }

    protected static function summarise_file_changes( array $meta ) {
        return [
            'create' => isset( $meta['validation']['dbScan']['add'] ) ? (int) $meta['validation']['dbScan']['add'] : 0,
            'update' => isset( $meta['validation']['dbScan']['update'] ) ? (int) $meta['validation']['dbScan']['update'] : 0,
            'delete' => isset( $meta['validation']['dbScan']['conflict'] ) ? (int) $meta['validation']['dbScan']['conflict'] : 0,
            'size'   => size_format( file_exists( $meta['file'] ) ? filesize( $meta['file'] ) : 0, 2 ),
        ];
    }

    protected static function detect_restore_warnings( array $meta ) {
        $warnings = [];
        if ( isset( $meta['validation']['domain']['migrateMode'] ) && $meta['validation']['domain']['migrateMode'] ) {
            $warnings[] = __( 'Domain differs from original site. URL replacement will be applied.', 'museder-restoreone' );
        }
        return $warnings;
    }

    protected static function extract_for_restore( $job_id, $file_path ) {
        $target = self::ensure_job_tmp_directory( $job_id );
        $extract_dir = trailingslashit( $target ) . 'extract';
        if ( file_exists( $extract_dir ) ) {
            museder_restoreone_delete_directory( $extract_dir );
        }
        museder_restoreone_ensure_directory( $extract_dir );

        $result = self::unpack_archive( $file_path, $extract_dir );
        if ( ! $result['success'] ) {
            throw new RuntimeException( esc_html__( 'Failed to extract archive.', 'museder-restoreone' ) );
        }

        return $extract_dir;
    }

    protected static function unpack_archive( $archive_path, $destination ) {
        if ( ! file_exists( $archive_path ) || ! is_readable( $archive_path ) ) {
            museder_restoreone_log( 'error', 'Archive file not found or not readable for extraction.', [
                'file' => $archive_path,
                'exists' => file_exists( $archive_path ),
                'readable' => file_exists( $archive_path ) ? is_readable( $archive_path ) : false,
            ] );
            return [ 'success' => false, 'error' => 'file_not_readable' ];
        }

        $file_size = filesize( $archive_path );
        if ( $file_size <= 0 ) {
            museder_restoreone_log( 'error', 'Archive file is empty or invalid.', [
                'file' => basename( $archive_path ),
                'size' => $file_size,
            ] );
            return [ 'success' => false, 'error' => 'file_empty' ];
        }

        // Try ZipArchive first
        if ( class_exists( 'ZipArchive' ) ) {
            $zip = new ZipArchive();
            $open_result = $zip->open( $archive_path );
            
            if ( true === $open_result ) {
                $extract_result = $zip->extractTo( $destination );
                $zip->close();
                
                if ( $extract_result ) {
                    museder_restoreone_log( 'info', 'Archive extracted successfully using ZipArchive.', [
                        'file' => basename( $archive_path ),
                        'destination' => $destination,
                    ] );
                    return [ 'success' => true ];
                } else {
                    museder_restoreone_log( 'warning', 'ZipArchive extractTo() returned false, trying PclZip fallback.', [
                        'file' => basename( $archive_path ),
                    ] );
                }
            } else {
                museder_restoreone_log( 'warning', 'ZipArchive failed to open archive, trying PclZip fallback.', [
                    'file' => basename( $archive_path ),
                    'error_code' => $open_result,
                ] );
            }
        }

        // Fallback to PclZip
        if ( function_exists( 'museder_restoreone_require_pclzip' ) ) {
            museder_restoreone_require_pclzip();
        }

        $pcl = new PclZip( $archive_path );
        // Use array format for options to avoid PclZip parsing issues
        $options = [
            PCLZIP_OPT_PATH => $destination,
            PCLZIP_OPT_REPLACE_NEWER => true,
        ];
        
        try {
            $result = $pcl->extract( $options );
            $success = ( false !== $result && 0 !== $result );
            
            if ( $success ) {
                museder_restoreone_log( 'info', 'Archive extracted successfully using PclZip.', [
                    'file' => basename( $archive_path ),
                    'destination' => $destination,
                    'extracted_count' => is_array( $result ) ? count( $result ) : ( is_numeric( $result ) ? $result : 'unknown' ),
                ] );
            } else {
                $error_code = method_exists( $pcl, 'errorCode' ) ? $pcl->errorCode() : 'unknown';
                $error_info = method_exists( $pcl, 'errorInfo' ) ? $pcl->errorInfo( true ) : 'Unknown error';
                museder_restoreone_log( 'error', 'PclZip extraction failed.', [
                    'file' => basename( $archive_path ),
                    'error_code' => $error_code,
                    'error_info' => $error_info,
                ] );
            }
            
            return [ 'success' => $success, 'error_code' => $success ? null : $error_code, 'error_info' => $success ? null : $error_info ];
        } catch ( Exception $pcl_exception ) {
            museder_restoreone_log( 'error', 'PclZip extraction threw exception.', [
                'file' => basename( $archive_path ),
                'error' => $pcl_exception->getMessage(),
            ] );
            return [ 'success' => false, 'error' => 'pclzip_exception', 'error_message' => $pcl_exception->getMessage() ];
        }
    }

    protected static function import_database_from_extract( $extract_dir, array $meta ) {
        $db_file = self::locate_sql_file( $extract_dir );
        if ( ! $db_file ) {
            return;
        }

        $result = Museder_Restoreone_Restore::import_database( $db_file );
        if ( empty( $result['success'] ) ) {
            throw new RuntimeException( esc_html__( 'Database import failed.', 'museder-restoreone' ) );
        }
    }

    protected static function locate_sql_file( $extract_dir ) {
        $db_path = trailingslashit( $extract_dir ) . 'database.ndjson';
        if ( file_exists( $db_path ) ) {
            return $db_path;
        }

        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $extract_dir, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $iterator as $file ) {
            if ( strtolower( $file->getFilename() ) === 'database.ndjson' ) {
                return $file->getPathname();
            }
        }

        return null;
    }

    protected static function copy_files_from_extract( $extract_dir ) {
        $content_dir = trailingslashit( $extract_dir ) . 'wp-content';
        if ( ! is_dir( $content_dir ) ) {
            return;
        }

        $dest_content_dir = function_exists( 'museder_restoreone_get_wp_content_dir' ) ? museder_restoreone_get_wp_content_dir() : '';
        if ( '' === $dest_content_dir ) {
            return;
        }
        self::recursive_copy( $content_dir, $dest_content_dir );
    }

    protected static function recursive_copy( $source, $destination ) {
        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
        foreach ( $iterator as $item ) {
            $target = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ( $item->isDir() ) {
                if ( ! file_exists( $target ) ) {
                    wp_mkdir_p( $target );
                }
            } else {
                wp_mkdir_p( dirname( $target ) );
                copy( $item->getPathname(), $target );
            }
        }
    }

    protected static function apply_search_replace( array $meta, array $options ) {
        if ( empty( $meta['validation']['domain']['migrateMode'] ) ) {
            return;
        }

        $from = $meta['validation']['domain']['backup'];
        $to   = $meta['validation']['domain']['current'];

        if ( empty( $from ) || empty( $to ) || $from === $to ) {
            return;
        }

        // Build comprehensive URL replacement pairs
        $pairs = self::build_url_replacement_pairs( $meta );

        if ( empty( $pairs ) ) {
            museder_restoreone_log( 'warning', 'No URL replacement pairs generated.', [
                'from' => $from,
                'to' => $to,
            ] );
            return;
        }

        // Log the pairs being used
        $pair_summary = [];
        foreach ( $pairs as $pair ) {
            $pair_summary[] = $pair['search'] . ' → ' . $pair['replace'];
        }
        museder_restoreone_log( 'info', 'Applying URL search & replace.', [
            'pairs_count' => count( $pairs ),
            'pairs' => $pair_summary,
        ] );

        self::run_search_replace( $pairs );
    }

    /**
     * Build comprehensive URL replacement pairs for search-replace operation.
     * Handles http/https, www/non-www, and subdirectory path variations.
     *
     * @param array $meta Restore job metadata containing domain information.
     * @return array Array of search/replace pairs.
     */
    protected static function build_url_replacement_pairs( array $meta ) {
        $old_base = isset( $meta['validation']['domain']['backup'] ) ? $meta['validation']['domain']['backup'] : '';
        $new_base = isset( $meta['validation']['domain']['current'] ) ? $meta['validation']['domain']['current'] : '';

        // Fallback to current site URLs if not in meta
        if ( empty( $old_base ) ) {
            // Try to get from metadata if available
            $metadata = self::extract_archive_metadata( isset( $meta['id'] ) ? $meta['id'] : '', isset( $meta['file'] ) ? $meta['file'] : '' );
            if ( ! empty( $metadata['siteurl'] ) ) {
                $old_base = $metadata['siteurl'];
            } else {
                // Last resort: use current site URL as old base (not ideal but better than nothing)
                $old_base = home_url();
            }
        }

        if ( empty( $new_base ) ) {
            $new_base = home_url();
        }

        // Normalize URLs: remove trailing slashes
        $old_base = rtrim( $old_base, '/' );
        $new_base = rtrim( $new_base, '/' );

        if ( empty( $old_base ) || empty( $new_base ) || $old_base === $new_base ) {
            return [];
        }

        // Parse URLs to extract components
        $old_parsed = wp_parse_url( $old_base );
        $new_parsed = wp_parse_url( $new_base );

        if ( ! $old_parsed || ! $new_parsed ) {
            // If parsing fails, use simple replacement
            return [
                [
                    'search'  => $old_base,
                    'replace' => $new_base,
                ],
            ];
        }

        $old_scheme = isset( $old_parsed['scheme'] ) ? $old_parsed['scheme'] : 'http';
        $old_host   = isset( $old_parsed['host'] ) ? $old_parsed['host'] : '';
        $old_path   = isset( $old_parsed['path'] ) ? $old_parsed['path'] : '';

        $new_scheme = isset( $new_parsed['scheme'] ) ? $new_parsed['scheme'] : 'http';
        $new_host   = isset( $new_parsed['host'] ) ? $new_parsed['host'] : '';
        $new_path   = isset( $new_parsed['path'] ) ? $new_parsed['path'] : '';

        if ( empty( $old_host ) || empty( $new_host ) ) {
            return [];
        }

        // Build base URLs with and without www
        $old_host_with_www    = 'www.' . ltrim( $old_host, 'www.' );
        $old_host_without_www = preg_replace( '/^www\./', '', $old_host );
        $new_host_with_www    = 'www.' . ltrim( $new_host, 'www.' );
        $new_host_without_www = preg_replace( '/^www\./', '', $new_host );

        // Determine if we should preserve www or remove it based on new_base
        $new_has_www = ( 0 === strpos( $new_host, 'www.' ) );
        $target_new_host = $new_has_www ? $new_host_with_www : $new_host_without_www;

        $pairs = [];

        // Generate pairs for all common variations
        $variations = [
            // http variations
            [ 'http', $old_host, $old_path, $new_scheme, $target_new_host, $new_path ],
            [ 'http', $old_host_with_www, $old_path, $new_scheme, $target_new_host, $new_path ],
            [ 'http', $old_host_without_www, $old_path, $new_scheme, $target_new_host, $new_path ],
            // https variations
            [ 'https', $old_host, $old_path, $new_scheme, $target_new_host, $new_path ],
            [ 'https', $old_host_with_www, $old_path, $new_scheme, $target_new_host, $new_path ],
            [ 'https', $old_host_without_www, $old_path, $new_scheme, $target_new_host, $new_path ],
        ];

        foreach ( $variations as $variation ) {
            list( $old_s, $old_h, $old_p, $new_s, $new_h, $new_p ) = $variation;

            $old_url = $old_s . '://' . $old_h . $old_p;
            $new_url = $new_s . '://' . $new_h . $new_p;

            // Only add if different
            if ( $old_url !== $new_url ) {
                $pairs[] = [
                    'search'  => $old_url,
                    'replace' => $new_url,
                ];
            }
        }

        // Also handle path-only replacements if paths differ
        if ( $old_path !== $new_path && ! empty( $old_path ) ) {
            // Replace old path with new path in URLs
            $pairs[] = [
                'search'  => $old_path,
                'replace' => $new_path,
            ];
        }

        // Remove duplicates
        $unique_pairs = [];
        $seen = [];
        foreach ( $pairs as $pair ) {
            $key = $pair['search'] . '|' . $pair['replace'];
            if ( ! isset( $seen[ $key ] ) ) {
                $seen[ $key ] = true;
                $unique_pairs[] = $pair;
            }
        }

        return $unique_pairs;
    }

    /**
     * Complete search-replace implementation that handles all text fields and serialized data.
     *
     * @param array $pairs Array of search/replace pairs.
     */
    protected static function run_search_replace( $pairs ) {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // 說明：以下查詢用於備份/還原流程，必須直接操作資料表結構，無法使用高階 API 或快取。
        // 所有 table 名稱皆由 $wpdb 提供或白名單，不接受使用者輸入。
        $tables = $wpdb->get_col( 'SHOW TABLES' );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( empty( $tables ) ) {
            return;
        }

        $text_types = [ 'tinytext', 'text', 'mediumtext', 'longtext', 'varchar', 'char' ];

        foreach ( $tables as $table ) {
            // @plugin-check: safe table name from whitelist
            // $table comes from SHOW TABLES result (system query, not user input)
            // Sanitize table name to ensure only safe characters
            $safe_table = sanitize_key( (string) $table );
            if ( empty( $safe_table ) ) {
                continue;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
            // 說明：以下查詢用於備份/還原過程，必須直接操作資料表結構，table 名稱皆來自 $wpdb 或白名單，不接受使用者輸入。
            // Identifier: safe_table is strict-whitelisted; do not use prepare() for identifiers.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier; strict whitelist applied above
            $columns = $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $safe_table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifiers sanitized + escaped
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( empty( $columns ) ) {
                continue;
            }

            $targets = [];
            foreach ( $columns as $column ) {
                if ( in_array( strtolower( $column['Type'] ), $text_types, true ) ) {
                    $targets[] = $column['Field'];
                }
            }

            if ( empty( $targets ) ) {
                continue;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
            // 說明：以下查詢用於備份/還原過程，必須直接操作資料表結構，table 名稱皆來自 $wpdb 或白名單，不接受使用者輸入。
            // Identifier: safe_table is strict-whitelisted; do not use prepare() for identifiers.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier; strict whitelist applied above
            $rows = $wpdb->get_results( 'SELECT * FROM `' . esc_sql( $safe_table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifiers sanitized + escaped
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( empty( $rows ) ) {
                continue;
            }

            foreach ( $rows as $row ) {
                $update = [];
                foreach ( $targets as $field ) {
                    $original = $row[ $field ];
                    $replaced = self::serialized_replace_recursive( $pairs, maybe_unserialize( $original ) );
                    $maybe    = is_array( $replaced ) || is_object( $replaced ) ? serialize( $replaced ) : $replaced;
                    if ( $maybe !== $original ) {
                        $update[ $field ] = $maybe;
                    }
                }

                if ( ! empty( $update ) ) {
                    // 這段查詢用於備份／還原流程中的資料庫狀態檢查或結構調整，
                    // 輸入值來自系統內部狀態，不包含直接的使用者輸入。
                    // 為了確保相容性與效能，此處使用直接查詢而非 WP_Query。
                    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $where_key = isset( $row['id'] ) ? 'id' : array_key_first( $row );
                    $wpdb->update( $safe_table, $update, [ $where_key => $row[ $where_key ] ] );
                    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                }
            }
        }
    }

    /**
     * Recursively replace search strings in arrays, objects, and strings.
     *
     * @param array $pairs Search/replace pairs.
     * @param mixed $value Value to process.
     * @return mixed Processed value.
     */
    protected static function serialized_replace_recursive( $pairs, $value ) {
        if ( is_array( $value ) ) {
            foreach ( $value as $key => $item ) {
                $value[ $key ] = self::serialized_replace_recursive( $pairs, $item );
            }
            return $value;
        }

        // For safety, do not attempt to traverse/modify objects in serialized structures.
        // Objects may represent class instances; altering them can be unsafe.
        if ( is_object( $value ) ) {
            return $value;
        }

        if ( is_string( $value ) ) {
            // AI1WM-style: if the string itself is serialized, safely unserialize, replace recursively,
            // then re-serialize so string lengths remain valid.
            if ( self::looks_like_serialized( $value ) ) {
                $un = self::safe_unserialize_no_objects( $value );
                if ( $un['ok'] ) {
                    if ( is_object( $un['value'] ) ) {
                        // Do not modify serialized objects.
                        return $value;
                    }
                    $new = self::serialized_replace_recursive( $pairs, $un['value'] );
                    // Re-serialize to preserve length fields.
                    return serialize( $new );
                }
                // If it looks serialized but fails to unserialize, treat as plain text (best-effort).
            }

            foreach ( $pairs as $pair ) {
                if ( empty( $pair['search'] ) ) {
                    continue;
                }
                $replace = isset( $pair['replace'] ) ? $pair['replace'] : '';
                $value   = str_replace( $pair['search'], $replace, $value );
            }
        }

        return $value;
    }

    /**
     * Lightweight heuristic to detect serialized PHP values.
     *
     * @param string $value
     * @return bool
     */
    protected static function looks_like_serialized( $value ) {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return false;
        }
        // Common serialized prefixes.
        $first = $value[0];
        if ( ! in_array( $first, [ 'a', 's', 'i', 'b', 'd', 'O', 'C', 'N' ], true ) ) {
            return false;
        }
        // Must contain a ':' early, and end with ';' or '}'.
        if ( false === strpos( $value, ':' ) ) {
            return false;
        }
        $last = substr( $value, -1 );
        return ( ';' === $last || '}' === $last );
    }

    /**
     * Safely unserialize without allowing objects/classes, without using @ suppression.
     *
     * @param string $value
     * @return array{ok:bool,value:mixed}
     */
    protected static function safe_unserialize_no_objects( $value ) {
        $value = (string) $value;

        if ( '' === $value ) {
            return [ 'ok' => false, 'value' => null ];
        }

        $prev = set_error_handler( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- convert unserialize warnings into exceptions; not debug logging
            static function () {
                throw new RuntimeException( 'unserialize_warning' );
            }
        );

        try {
            $result = unserialize( $value, [ 'allowed_classes' => false ] );
            restore_error_handler();
            return [ 'ok' => true, 'value' => $result ];
        } catch ( Throwable $e ) {
            // Ensure handler is restored even on failure.
            if ( is_callable( $prev ) ) {
                restore_error_handler();
            } else {
                restore_error_handler();
            }
            return [ 'ok' => false, 'value' => null ];
        }
    }

    protected static function cleanup_job_tmp( $job_id, $extract_dir ) {
        if ( $extract_dir && file_exists( $extract_dir ) ) {
            museder_restoreone_delete_directory( $extract_dir );
        }

        $tmp   = trailingslashit( museder_restoreone_get_temp_dir() ) . $job_id;
        if ( file_exists( $tmp ) ) {
            museder_restoreone_delete_directory( $tmp );
        }
    }

    /**
     * Perform post-restore cleanup operations: clear caches and refresh permalinks.
     */
    protected static function post_restore_cleanup() {
        global $wpdb;

        // Clear WordPress object cache
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }

        // Clear transients
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
        // 說明：以下查詢用於備份/還原過程，必須直接操作資料表結構，table 名稱皆來自 $wpdb 或白名單，不接受使用者輸入。
        // @plugin-check: safe table name from whitelist ($wpdb->options is WordPress core table)
        // Cannot use prepare() for LIKE patterns with wildcards, but pattern is hardcoded
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_%', '_site_transient_%' ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- safe: $wpdb->options is WordPress core table, patterns are hardcoded
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching

        // Refresh permalink structure
        if ( function_exists( 'flush_rewrite_rules' ) ) {
            flush_rewrite_rules( false );
        }

        // Clear any plugin-specific caches
        if ( function_exists( 'wp_cache_delete' ) ) {
            // Clear common cache groups
            wp_cache_delete( 'alloptions', 'options' );
        }

        // Ensure site URL and home URL are correctly set based on current server
        // This is a safety check in case URL replacement didn't catch everything
        $protocol = is_ssl() ? 'https' : 'http';
        $host     = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
        
        if ( ! empty( $host ) ) {
            $expected_site_url = $protocol . '://' . $host;
            $expected_home_url  = $expected_site_url;
            
            $current_site_url = get_option( 'siteurl' );
            $current_home_url = get_option( 'home' );
            
            // Only update if URLs don't match (excluding trailing slashes)
            if ( rtrim( $current_site_url, '/' ) !== rtrim( $expected_site_url, '/' ) ) {
                update_option( 'siteurl', $expected_site_url );
                museder_restoreone_log( 'info', 'Updated siteurl option after restore.', [ 'old' => $current_site_url, 'new' => $expected_site_url ] );
            }
            if ( rtrim( $current_home_url, '/' ) !== rtrim( $expected_home_url, '/' ) ) {
                update_option( 'home', $expected_home_url );
                museder_restoreone_log( 'info', 'Updated home option after restore.', [ 'old' => $current_home_url, 'new' => $expected_home_url ] );
            }
        }

        // Do not change other plugins' activation status automatically.
        // Store info for the admin to review manually if needed.
        self::record_restored_plugin_list();

        museder_restoreone_log( 'info', 'Post-restore cleanup completed.', [] );
    }

    /**
     * Record plugin list found in the restored database for admin visibility.
     * Note: Per WordPress.org policy, we do not change activation status of other plugins.
     */
    protected static function record_restored_plugin_list() {
        $active_plugins = get_option( 'active_plugins', [] );
        if ( ! is_array( $active_plugins ) || empty( $active_plugins ) ) {
            museder_restoreone_log( 'info', 'No active plugins found in restored database.', [] );
            return;
        }

        update_option( 'museder_restoreone_restored_active_plugins_last', $active_plugins, false );

        museder_restoreone_log( 'info', 'Restore completed. Plugin activation status was not modified automatically.', [
            'active_plugins_count' => count( $active_plugins ),
        ] );
    }

    /**
     * Legacy hook: restore plugin activation status.
     *
     * IMPORTANT: Per WordPress.org policy, we do not change activation state of other plugins automatically.
     * We keep this method as a no-op/safe recorder so the cleanup pipeline doesn't fatal.
     *
     * @return void
     */
    protected static function restore_plugin_status() {
        // Record the restored plugin list for admin visibility (no activation changes).
        self::record_restored_plugin_list();
    }

    protected static function restore_from_snapshot( array $snapshot ) {
        if ( empty( $snapshot['file'] ) || ! file_exists( $snapshot['file'] ) ) {
            throw new RuntimeException( esc_html__( 'Snapshot file missing.', 'museder-restoreone' ) );
        }

        $job_id = self::generate_job_id();
        $meta   = [ 'file' => $snapshot['file'], 'stage' => 'rollback' ];
        $extract = self::extract_for_restore( $job_id, $snapshot['file'] );
        self::import_database_from_extract( $extract, $meta );
        self::copy_files_from_extract( $extract );
        self::cleanup_job_tmp( $job_id, $extract );
    }
}
