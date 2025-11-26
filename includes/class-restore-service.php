<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Backup_Lite_Restore_Service {

    const JOB_META_EXTENSION = '.json';
    const REPORT_TYPE_DRYRUN = 'dryrun';

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
        if ( ! in_array( $source, [ 'upload', 'existing' ], true ) ) {
            throw new InvalidArgumentException( esc_html__( 'Invalid restore source.', 'museder-restoreone' ) );
        }

        $file_name = sanitize_file_name( wp_unslash( $file ) );
        if ( empty( $file_name ) ) {
            throw new InvalidArgumentException( esc_html__( 'Invalid restore file name.', 'museder-restoreone' ) );
        }

        $allowed_ext = [ 'zip', 'wpress' ];
        $ext         = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, $allowed_ext, true ) ) {
            throw new InvalidArgumentException( esc_html__( 'Unsupported backup extension.', 'museder-restoreone' ) );
        }

        $backup_dir = backup_lite_get_backup_dir();
        $file_path  = wp_normalize_path( trailingslashit( $backup_dir ) . $file_name );

        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
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

        backup_lite_log( 'info', 'Restore job prepared.', [ 'job_id' => $job_id, 'file' => $file_name ] );

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
        ];

        $meta['validation'] = $result;
        $meta['stage']      = 'validated';
        $meta['progress']   = 30;
        $meta['message']    = __( 'Validation results available.', 'museder-restoreone' );
        $meta['updated_at'] = current_time( 'mysql' );

        self::write_job_meta( $job_id, $meta );

        backup_lite_log( 'info', 'Restore job validated.', [ 'job_id' => $job_id ] );

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

        $txt_path  = Backup_Lite_Restore_Report::write_txt( $job_id, self::REPORT_TYPE_DRYRUN, $summary );
        $json_path = Backup_Lite_Restore_Report::write_json( $job_id, self::REPORT_TYPE_DRYRUN, $summary );

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

        backup_lite_log( 'info', 'Restore dry-run completed.', [ 'job_id' => $job_id ] );

        return [
            'summary' => $summary,
            'reports' => [
                'txt'  => rest_url( 'backup-lite/v2/restore/report/' . $job_id . '?format=txt&type=' . self::REPORT_TYPE_DRYRUN ),
                'json' => rest_url( 'backup-lite/v2/restore/report/' . $job_id . '?format=json&type=' . self::REPORT_TYPE_DRYRUN ),
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

        if ( Backup_Lite_Restore_Lock::is_locked() && ! self::is_current_lock( $job_id ) ) {
            throw new RuntimeException( esc_html__( 'Another restore operation is currently running.', 'museder-restoreone' ) );
        }

        if ( ! Backup_Lite_Restore_Lock::acquire( $job_id ) ) {
            throw new RuntimeException( esc_html__( 'Failed to acquire restore lock.', 'museder-restoreone' ) );
        }

        try {
            $pre_backup = self::create_pre_backup();
            $meta['pre_backup'] = $pre_backup;
            $meta['stage']      = 'restore-files';
            $meta['progress']   = 82;
            $meta['message']    = __( 'Extracting archive and preparing files…', 'museder-restoreone' );
            self::write_job_meta( $job_id, $meta );

            // Check if this is an All-in-One WP Migration backup and convert it if needed
            $file_to_extract = $meta['file'];
            $ext = strtolower( pathinfo( $file_to_extract, PATHINFO_EXTENSION ) );
            
            if ( 'wpress' === $ext || ( 'zip' === $ext && file_exists( $file_to_extract ) ) ) {
                require_once plugin_dir_path( __FILE__ ) . 'class-ai1wm-converter.php';
                
                try {
                    if ( class_exists( 'Backup_Lite_AI1WM_Converter' ) && Backup_Lite_AI1WM_Converter::is_ai1wm_backup( $file_to_extract ) ) {
                        backup_lite_log( 'info', 'Detected All-in-One WP Migration backup in restore service, converting to Museder RestoreOne format.', [
                            'job_id' => $job_id,
                            'file' => basename( $file_to_extract ),
                        ] );
                        
                        $convert_result = Backup_Lite_AI1WM_Converter::convert( $file_to_extract );
                        
                        if ( ! empty( $convert_result['success'] ) && ! empty( $convert_result['file'] ) && file_exists( $convert_result['file'] ) ) {
                            // Use converted file for extraction
                            $file_to_extract = $convert_result['file'];
                            $meta['file'] = $file_to_extract;
                            $meta['file_name'] = basename( $file_to_extract );
                            backup_lite_log( 'info', 'Successfully converted All-in-One backup in restore service.', [
                                'job_id' => $job_id,
                                'converted_file' => basename( $file_to_extract ),
                            ] );
                        } else {
                            // Conversion failed or not needed (e.g., .wpress files don't need conversion)
                            $log_level = ( isset( $convert_result['error'] ) && 'wpress_no_conversion_needed' === $convert_result['error'] ) ? 'info' : 'warning';
                            backup_lite_log( $log_level, 'All-in-One conversion not performed in restore service, will attempt direct extraction.', [
                                'job_id' => $job_id,
                                'error' => isset( $convert_result['error'] ) ? $convert_result['error'] : 'unknown',
                                'message' => isset( $convert_result['message'] ) ? $convert_result['message'] : '',
                            ] );
                        }
                    }
                } catch ( Exception $e ) {
                    // Log conversion error but continue with original file
                    backup_lite_log( 'warning', 'Exception during All-in-One conversion in restore service, continuing with original file.', [
                        'job_id' => $job_id,
                        'error' => $e->getMessage(),
                    ] );
                }
            }

            // Log extraction attempt
            backup_lite_log( 'info', 'Starting archive extraction.', [
                'job_id' => $job_id,
                'file' => basename( $file_to_extract ),
                'file_size' => file_exists( $file_to_extract ) ? size_format( filesize( $file_to_extract ), 2 ) : 'unknown',
            ] );

            try {
                $extracted = self::extract_for_restore( $job_id, $file_to_extract );
                backup_lite_log( 'info', 'Archive extraction completed successfully.', [
                    'job_id' => $job_id,
                    'extract_dir' => $extracted,
                ] );
            } catch ( Exception $extract_exception ) {
                // Log detailed extraction error
                backup_lite_log( 'error', 'Archive extraction failed.', [
                    'job_id' => $job_id,
                    'file' => basename( $file_to_extract ),
                    'error' => $extract_exception->getMessage(),
                    'file_exists' => file_exists( $file_to_extract ),
                    'file_readable' => file_exists( $file_to_extract ) ? is_readable( $file_to_extract ) : false,
                    'file_size' => file_exists( $file_to_extract ) ? filesize( $file_to_extract ) : 0,
                ] );
                // Re-throw with enhanced message
                throw new RuntimeException( sprintf(
                    /* translators: 1: Original error message, 2: File name */
                    esc_html__( 'Failed to extract backup archive: %1$s. File: %2$s. Please check the logs for details.', 'museder-restoreone' ),
                    $extract_exception->getMessage(),
                    basename( $file_to_extract )
                ) );
            }

            $meta['stage']    = 'restore-db';
            $meta['progress'] = 90;
            $meta['message']  = __( 'Importing database…', 'museder-restoreone' );
            self::write_job_meta( $job_id, $meta );

            // Log database restore start
            $old_siteurl = get_option( 'siteurl' );
            $old_home = get_option( 'home' );
            backup_lite_log( 'info', 'Starting database import.', [
                'job_id' => $job_id,
                'old_siteurl' => $old_siteurl,
                'old_home' => $old_home,
            ] );

            self::import_database_from_extract( $extracted, $meta );

            // Log database restore completion
            $new_siteurl = get_option( 'siteurl' );
            $new_home = get_option( 'home' );
            backup_lite_log( 'info', 'Database import completed.', [
                'job_id' => $job_id,
                'new_siteurl' => $new_siteurl,
                'new_home' => $new_home,
                'siteurl_changed' => ( $old_siteurl !== $new_siteurl ),
                'home_changed' => ( $old_home !== $new_home ),
            ] );

            $meta['stage']    = 'restore-files-final';
            $meta['message']  = __( 'Copying wp-content files…', 'museder-restoreone' );
            $meta['progress'] = 95;
            self::write_job_meta( $job_id, $meta );

            self::copy_files_from_extract( $extracted );

            $meta['stage']      = 'search-replace';
            $meta['message']    = __( 'Applying URL search & replace…', 'museder-restoreone' );
            $meta['progress']   = 97;
            self::write_job_meta( $job_id, $meta );

            self::apply_search_replace( $meta, $options );

            $meta['stage']      = 'cleanup';
            $meta['message']    = __( 'Finalising restore and cleaning up…', 'museder-restoreone' );
            $meta['progress']   = 99;
            self::write_job_meta( $job_id, $meta );

            self::cleanup_job_tmp( $job_id, $extracted );

            // Clear caches and refresh permalinks after restore
            self::post_restore_cleanup();

            // Enter safe mode after restore to prevent plugin conflicts
            $safe_mode_entered = false;
            try {
                Backup_Lite_Restore::enter_safe_mode_after_import();
                $safe_mode_entered = true;
                backup_lite_log( 'info', 'Safe mode activated after restore.', [ 'job_id' => $job_id ] );
            } catch ( Exception $e ) {
                backup_lite_log( 'warning', 'Failed to enter safe mode after restore.', [
                    'job_id' => $job_id,
                    'error' => $e->getMessage(),
                ] );
            }

            // Prepare metadata for hooks
            $restore_meta = [
                'job_id' => $job_id,
                'file' => isset( $meta['file'] ) ? $meta['file'] : '',
                'file_name' => isset( $meta['file_name'] ) ? $meta['file_name'] : '',
                'source' => isset( $meta['source'] ) ? $meta['source'] : '',
                'siteurl_old' => isset( $meta['validation']['domain']['backup'] ) ? $meta['validation']['domain']['backup'] : '',
                'siteurl_new' => isset( $meta['validation']['domain']['current'] ) ? $meta['validation']['domain']['current'] : home_url(),
                'safe_mode' => $safe_mode_entered,
                'completed_at' => current_time( 'mysql' ),
            ];

            // Fire hook after restore completion (before marking as done)
            try {
                do_action( 'backup_lite_after_restore', $restore_meta );
                if ( $safe_mode_entered ) {
                    do_action( 'backup_lite_after_restore_safe_mode', $restore_meta );
                }
            } catch ( Exception $hook_exception ) {
                // Log hook errors but don't fail the restore
                backup_lite_log( 'warning', 'Error in restore completion hook.', [
                    'job_id' => $job_id,
                    'error' => $hook_exception->getMessage(),
                ] );
            }

            $meta['stage']      = 'done';
            $meta['progress']   = 100;
            $meta['message']    = __( 'Restore completed successfully.', 'museder-restoreone' );
            $meta['completed']  = true;
            $meta['updated_at'] = current_time( 'mysql' );
            self::write_job_meta( $job_id, $meta );

            backup_lite_log( 'info', 'Restore job executed successfully.', [
                'job_id' => $job_id,
                'safe_mode' => $safe_mode_entered,
            ] );

            return [
                'ok'                  => true,
                'message'             => __( 'Restore completed successfully.', 'museder-restoreone' ),
                'rollback_available'  => ! empty( $pre_backup['file'] ),
            ];
        } catch ( Exception $e ) {
            backup_lite_log( 'error', 'Restore execution failed.', [ 'job_id' => $job_id, 'error' => $e->getMessage() ] );
            throw $e;
        } finally {
            Backup_Lite_Restore_Lock::release();
        }
    }

    /**
     * Rollback to the latest pre-backup snapshot (placeholder).
     */
    public static function rollback( $job_id ) {
        $meta = self::get_job_meta( $job_id );

        if ( empty( $meta['pre_backup']['file'] ) ) {
            throw new RuntimeException( esc_html__( 'No pre-restore snapshot available.', 'museder-restoreone' ) );
        }

        if ( Backup_Lite_Restore_Lock::is_locked() && ! self::is_current_lock( $job_id ) ) {
            throw new RuntimeException( esc_html__( 'Another restore operation is currently running.', 'museder-restoreone' ) );
        }

        if ( ! Backup_Lite_Restore_Lock::acquire( $job_id ) ) {
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

            backup_lite_log( 'info', 'Restore rollback executed.', [ 'job_id' => $job_id ] );

            return [ 'ok' => true, 'message' => __( 'Rollback completed successfully.', 'museder-restoreone' ) ];
        } catch ( Exception $e ) {
            backup_lite_log( 'error', 'Restore rollback failed.', [ 'job_id' => $job_id, 'error' => $e->getMessage() ] );
            throw $e;
        } finally {
            Backup_Lite_Restore_Lock::release();
        }
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
        return 'rjb_' . backup_lite_local_time( 'Ymd_His' ) . '_' . strtolower( $token );
    }

    /**
     * Ensure job directory exists.
     */
    protected static function ensure_job_directory( $job_id ) {
        $dir = trailingslashit( backup_lite_get_jobs_dir() ) . $job_id;
        if ( ! file_exists( $dir ) ) {
            backup_lite_ensure_directory( $dir );
        }
        return $dir;
    }

    /**
     * Ensure job temp directory exists (within global temp root).
     */
    protected static function ensure_job_tmp_directory( $job_id ) {
        $dir = trailingslashit( backup_lite_get_temp_dir() ) . $job_id;
        if ( ! file_exists( $dir ) ) {
            backup_lite_ensure_directory( $dir );
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
        return trailingslashit( backup_lite_get_jobs_dir() ) . $job_id . self::JOB_META_EXTENSION;
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
        $lock = Backup_Lite_Restore_Lock::current_lock();
        return $lock && isset( $lock['job_id'] ) && $lock['job_id'] === $job_id;
    }

    protected static function create_pre_backup() {
        $result = Backup_Lite_Backup::backup_site();
        if ( empty( $result['success'] ) || empty( $result['file'] ) ) {
            throw new RuntimeException( esc_html__( 'Failed to create pre-restore backup snapshot.', 'museder-restoreone' ) );
        }

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
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
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
            backup_lite_delete_directory( $extract_dir );
        }
        backup_lite_ensure_directory( $extract_dir );

        $result = self::unpack_archive( $file_path, $extract_dir );
        if ( ! $result['success'] ) {
            throw new RuntimeException( esc_html__( 'Failed to extract archive.', 'museder-restoreone' ) );
        }

        return $extract_dir;
    }

    protected static function unpack_archive( $archive_path, $destination ) {
        if ( ! file_exists( $archive_path ) || ! is_readable( $archive_path ) ) {
            backup_lite_log( 'error', 'Archive file not found or not readable for extraction.', [
                'file' => $archive_path,
                'exists' => file_exists( $archive_path ),
                'readable' => file_exists( $archive_path ) ? is_readable( $archive_path ) : false,
            ] );
            return [ 'success' => false, 'error' => 'file_not_readable' ];
        }

        $file_size = filesize( $archive_path );
        if ( $file_size <= 0 ) {
            backup_lite_log( 'error', 'Archive file is empty or invalid.', [
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
                    backup_lite_log( 'info', 'Archive extracted successfully using ZipArchive.', [
                        'file' => basename( $archive_path ),
                        'destination' => $destination,
                    ] );
                    return [ 'success' => true ];
                } else {
                    backup_lite_log( 'warning', 'ZipArchive extractTo() returned false, trying PclZip fallback.', [
                        'file' => basename( $archive_path ),
                    ] );
                }
            } else {
                backup_lite_log( 'warning', 'ZipArchive failed to open archive, trying PclZip fallback.', [
                    'file' => basename( $archive_path ),
                    'error_code' => $open_result,
                ] );
            }
        }

        // Fallback to PclZip
        if ( ! class_exists( 'PclZip' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
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
                backup_lite_log( 'info', 'Archive extracted successfully using PclZip.', [
                    'file' => basename( $archive_path ),
                    'destination' => $destination,
                    'extracted_count' => is_array( $result ) ? count( $result ) : ( is_numeric( $result ) ? $result : 'unknown' ),
                ] );
            } else {
                $error_code = method_exists( $pcl, 'errorCode' ) ? $pcl->errorCode() : 'unknown';
                $error_info = method_exists( $pcl, 'errorInfo' ) ? $pcl->errorInfo( true ) : 'Unknown error';
                backup_lite_log( 'error', 'PclZip extraction failed.', [
                    'file' => basename( $archive_path ),
                    'error_code' => $error_code,
                    'error_info' => $error_info,
                ] );
            }
            
            return [ 'success' => $success, 'error_code' => $success ? null : $error_code, 'error_info' => $success ? null : $error_info ];
        } catch ( Exception $pcl_exception ) {
            backup_lite_log( 'error', 'PclZip extraction threw exception.', [
                'file' => basename( $archive_path ),
                'error' => $pcl_exception->getMessage(),
            ] );
            return [ 'success' => false, 'error' => 'pclzip_exception', 'error_message' => $pcl_exception->getMessage() ];
        }
    }

    protected static function import_database_from_extract( $extract_dir, array $meta ) {
        $sql_file = self::locate_sql_file( $extract_dir );
        if ( ! $sql_file ) {
            return;
        }

        $result = Backup_Lite_Restore::import_database( $sql_file );
        if ( empty( $result['success'] ) ) {
            throw new RuntimeException( esc_html__( 'Database import failed.', 'museder-restoreone' ) );
        }
    }

    protected static function locate_sql_file( $extract_dir ) {
        $sql_path = trailingslashit( $extract_dir ) . 'database.sql';
        if ( file_exists( $sql_path ) ) {
            return $sql_path;
        }

        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $extract_dir, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $iterator as $file ) {
            if ( strtolower( $file->getFilename() ) === 'database.sql' ) {
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

        self::recursive_copy( $content_dir, WP_CONTENT_DIR );
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
            backup_lite_log( 'warning', 'No URL replacement pairs generated.', [
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
        backup_lite_log( 'info', 'Applying URL search & replace.', [
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

        // @plugin-check: allowed - schema introspection for restore, system query not user input
        $tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching -- system query for restore, caching not applicable
        if ( empty( $tables ) ) {
            return;
        }

        $text_types = [ 'tinytext', 'text', 'mediumtext', 'longtext', 'varchar', 'char' ];

        foreach ( $tables as $table ) {
            // @plugin-check: safe table name from whitelist
            // $table comes from SHOW TABLES result (system query, not user input)
            // Sanitize table name to ensure only safe characters
            $safe_table = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
            if ( empty( $safe_table ) ) {
                continue;
            }

            // @plugin-check: allowed - schema introspection for restore, table name from whitelist only
            $columns = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM `%s`", $safe_table ), ARRAY_A ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- safe: table name sanitized from SHOW TABLES result
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

            // @plugin-check: safe table name from whitelist
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `%s`", $safe_table ), ARRAY_A );
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
                    // @plugin-check: safe table name from whitelist
                    $where_key = isset( $row['id'] ) ? 'id' : array_key_first( $row );
                    $wpdb->update( $safe_table, $update, [ $where_key => $row[ $where_key ] ] );
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

        if ( is_object( $value ) ) {
            foreach ( $value as $key => $item ) {
                $value->$key = self::serialized_replace_recursive( $pairs, $item );
            }
            return $value;
        }

        if ( is_string( $value ) ) {
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

    protected static function cleanup_job_tmp( $job_id, $extract_dir ) {
        if ( $extract_dir && file_exists( $extract_dir ) ) {
            backup_lite_delete_directory( $extract_dir );
        }

        $tmp   = trailingslashit( backup_lite_get_temp_dir() ) . $job_id;
        if ( file_exists( $tmp ) ) {
            backup_lite_delete_directory( $tmp );
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
        // @plugin-check: safe table name from whitelist ($wpdb->options is WordPress core table)
        // Cannot use prepare() for LIKE patterns with wildcards, but pattern is hardcoded
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_%', '_site_transient_%' ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- safe: $wpdb->options is WordPress core table, patterns are hardcoded

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
                backup_lite_log( 'info', 'Updated siteurl option after restore.', [ 'old' => $current_site_url, 'new' => $expected_site_url ] );
            }
            if ( rtrim( $current_home_url, '/' ) !== rtrim( $expected_home_url, '/' ) ) {
                update_option( 'home', $expected_home_url );
                backup_lite_log( 'info', 'Updated home option after restore.', [ 'old' => $current_home_url, 'new' => $expected_home_url ] );
            }
        }

        // Restore plugin activation status from backup
        self::restore_plugin_status();

        backup_lite_log( 'info', 'Post-restore cleanup completed.', [] );
    }

    /**
     * Restore plugin activation status from the backup database.
     * This ensures plugins are activated/deactivated according to the original site state.
     */
    protected static function restore_plugin_status() {
        // Get the active_plugins option from the restored database
        $active_plugins = get_option( 'active_plugins', [] );
        
        if ( ! is_array( $active_plugins ) || empty( $active_plugins ) ) {
            backup_lite_log( 'info', 'No active plugins found in restored database, skipping plugin status restoration.', [] );
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
        
        // Only update if there's a difference
        if ( $valid_active_plugins !== $current_active ) {
            // Update active_plugins option
            update_option( 'active_plugins', $valid_active_plugins );
            
            // Also handle network-active plugins if multisite
            if ( is_multisite() ) {
                $network_active = get_site_option( 'active_sitewide_plugins', [] );
                // For multisite, we might need to handle network plugins differently
                // For now, we'll just log it
                if ( ! empty( $network_active ) ) {
                    backup_lite_log( 'info', 'Multisite network plugins detected, manual activation may be needed.', [
                        'network_plugins' => array_keys( $network_active ),
                    ] );
                }
            }
            
            backup_lite_log( 'info', 'Plugin activation status restored from backup.', [
                'restored_count' => count( $valid_active_plugins ),
                'missing_count' => count( $missing_plugins ),
            ] );
        } else {
            backup_lite_log( 'info', 'Plugin activation status already matches backup, no changes needed.', [] );
        }
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
