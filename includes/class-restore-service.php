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
            throw new InvalidArgumentException( __( 'Invalid restore source.', 'museder-restoreone' ) );
        }

        $file_name = sanitize_file_name( wp_unslash( $file ) );
        if ( empty( $file_name ) ) {
            throw new InvalidArgumentException( __( 'Invalid restore file name.', 'museder-restoreone' ) );
        }

        $allowed_ext = [ 'zip', 'wpress' ];
        $ext         = strtolower( pathinfo( $file_name, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, $allowed_ext, true ) ) {
            throw new InvalidArgumentException( __( 'Unsupported backup extension.', 'museder-restoreone' ) );
        }

        $backup_dir = backup_lite_get_backup_dir();
        $file_path  = wp_normalize_path( trailingslashit( $backup_dir ) . $file_name );

        if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
            throw new RuntimeException( __( 'Backup file not found or unreadable.', 'museder-restoreone' ) );
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
            throw new RuntimeException( __( 'Restore source file missing.', 'museder-restoreone' ) );
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
            throw new RuntimeException( __( 'Please complete validation before running a dry-run.', 'museder-restoreone' ) );
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
            throw new RuntimeException( __( 'Please validate the archive before executing the restore.', 'museder-restoreone' ) );
        }

        if ( Backup_Lite_Restore_Lock::is_locked() && ! self::is_current_lock( $job_id ) ) {
            throw new RuntimeException( __( 'Another restore operation is currently running.', 'museder-restoreone' ) );
        }

        if ( ! Backup_Lite_Restore_Lock::acquire( $job_id ) ) {
            throw new RuntimeException( __( 'Failed to acquire restore lock.', 'museder-restoreone' ) );
        }

        try {
            $pre_backup = self::create_pre_backup();
            $meta['pre_backup'] = $pre_backup;
            $meta['stage']      = 'restore-files';
            $meta['progress']   = 82;
            $meta['message']    = __( 'Extracting archive and preparing files…', 'museder-restoreone' );
            self::write_job_meta( $job_id, $meta );

            $extracted = self::extract_for_restore( $job_id, $meta['file'] );

            $meta['stage']    = 'restore-db';
            $meta['progress'] = 90;
            $meta['message']  = __( 'Importing database…', 'museder-restoreone' );
            self::write_job_meta( $job_id, $meta );

            self::import_database_from_extract( $extracted, $meta );

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

            $meta['stage']      = 'done';
            $meta['progress']   = 100;
            $meta['message']    = __( 'Restore completed successfully.', 'museder-restoreone' );
            $meta['completed']  = true;
            $meta['updated_at'] = current_time( 'mysql' );
            self::write_job_meta( $job_id, $meta );

            backup_lite_log( 'info', 'Restore job executed.', [ 'job_id' => $job_id ] );

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
            throw new RuntimeException( __( 'No pre-restore snapshot available.', 'museder-restoreone' ) );
        }

        if ( Backup_Lite_Restore_Lock::is_locked() && ! self::is_current_lock( $job_id ) ) {
            throw new RuntimeException( __( 'Another restore operation is currently running.', 'museder-restoreone' ) );
        }

        if ( ! Backup_Lite_Restore_Lock::acquire( $job_id ) ) {
            throw new RuntimeException( __( 'Failed to acquire restore lock.', 'museder-restoreone' ) );
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
            throw new RuntimeException( __( 'Restore job not found.', 'museder-restoreone' ) );
        }

        $contents = file_get_contents( $path );
        $data     = json_decode( $contents, true );

        if ( ! is_array( $data ) ) {
            throw new RuntimeException( __( 'Corrupted restore job metadata.', 'museder-restoreone' ) );
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
            throw new RuntimeException( __( 'Unable to write restore job metadata.', 'museder-restoreone' ) );
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
            throw new RuntimeException( __( 'Failed to create pre-restore backup snapshot.', 'museder-restoreone' ) );
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
            throw new RuntimeException( __( 'Failed to extract archive.', 'museder-restoreone' ) );
        }

        return $extract_dir;
    }

    protected static function unpack_archive( $archive_path, $destination ) {
        if ( class_exists( 'ZipArchive' ) ) {
            $zip = new ZipArchive();
            if ( true === $zip->open( $archive_path ) ) {
                $zip->extractTo( $destination );
                $zip->close();
                return [ 'success' => true ];
            }
        }

        if ( ! class_exists( 'PclZip' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }

        $pcl = new PclZip( $archive_path );
        $result = $pcl->extract( PCLZIP_OPT_PATH, $destination, PCLZIP_OPT_REPLACE_NEWER );
        return [ 'success' => ( false !== $result ) ];
    }

    protected static function import_database_from_extract( $extract_dir, array $meta ) {
        $sql_file = self::locate_sql_file( $extract_dir );
        if ( ! $sql_file ) {
            return;
        }

        $result = Backup_Lite_Restore::import_database( $sql_file );
        if ( empty( $result['success'] ) ) {
            throw new RuntimeException( __( 'Database import failed.', 'museder-restoreone' ) );
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

        // Use the complete search-replace implementation from Backup_Lite_Restore
        $pairs = [
            [
                'search'  => $from,
                'replace' => $to,
            ],
        ];

        self::run_search_replace( $pairs );
    }

    /**
     * Complete search-replace implementation that handles all text fields and serialized data.
     *
     * @param array $pairs Array of search/replace pairs.
     */
    protected static function run_search_replace( $pairs ) {
        global $wpdb;

        $tables = $wpdb->get_col( 'SHOW TABLES' );
        if ( empty( $tables ) ) {
            return;
        }

        $text_types = [ 'tinytext', 'text', 'mediumtext', 'longtext', 'varchar', 'char' ];

        foreach ( $tables as $table ) {
            $columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
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

            $rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );
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
                    $where_key = isset( $row['id'] ) ? 'id' : array_key_first( $row );
                    $wpdb->update( $table, $update, [ $where_key => $row[ $where_key ] ] );
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
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'" );

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

        backup_lite_log( 'info', 'Post-restore cleanup completed.', [] );
    }

    protected static function restore_from_snapshot( array $snapshot ) {
        if ( empty( $snapshot['file'] ) || ! file_exists( $snapshot['file'] ) ) {
            throw new RuntimeException( __( 'Snapshot file missing.', 'museder-restoreone' ) );
        }

        $job_id = self::generate_job_id();
        $meta   = [ 'file' => $snapshot['file'], 'stage' => 'rollback' ];
        $extract = self::extract_for_restore( $job_id, $snapshot['file'] );
        self::import_database_from_extract( $extract, $meta );
        self::copy_files_from_extract( $extract );
        self::cleanup_job_tmp( $job_id, $extract );
    }
}
