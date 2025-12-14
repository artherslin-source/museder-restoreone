<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class Backup_Lite_Restore {

    private static $pclzip_destination = '';

    /**
     * Stream helpers (centralize direct file operations for restore hot paths).
     *
     * Plugin Check flags direct use of fopen/fread/fwrite/fclose. We keep the
     * surface area minimal by encapsulating them here and documenting why.
     *
     * @param string $path File path.
     * @param string $mode fopen mode.
     * @return resource|false
     */
    private static function stream_open( $path, $mode ) {
        // @plugin-check: allowed - controlled backup/restore file operation (streaming I/O), paths are plugin-controlled and validated at call sites.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- required for large-file streaming; WP_Filesystem not suitable for this hot path.
        return fopen( $path, $mode );
    }

    /**
     * @param resource $handle Stream handle.
     * @param int      $length Bytes to read.
     * @return string|false
     */
    private static function stream_read( $handle, $length ) {
        // @plugin-check: allowed - controlled backup/restore file operation (streaming I/O).
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- required for large-file streaming; WP_Filesystem not suitable for this hot path.
        return fread( $handle, $length );
    }

    /**
     * @param resource $handle Stream handle.
     * @param string   $data Data to write.
     * @return int|false
     */
    private static function stream_write( $handle, $data ) {
        // @plugin-check: allowed - controlled backup/restore file operation (streaming I/O).
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- required for large-file streaming; WP_Filesystem not suitable for this hot path.
        return fwrite( $handle, $data );
    }

    /**
     * @param resource|null $handle Stream handle.
     * @return bool
     */
    private static function stream_close( $handle ) {
        if ( ! is_resource( $handle ) ) {
            return false;
        }
        // @plugin-check: allowed - controlled backup/restore file operation (streaming I/O).
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- required for large-file streaming; WP_Filesystem not suitable for this hot path.
        return fclose( $handle );
    }

    /**
     * Restore a site from a unified backup archive.
     *
     * @param string   $archive_file
     * @param array    $options      Optional restore options (search_replace)
     * @param callable $progress_cb  Optional progress callback function( $percent, $message )
     *
     * @return array{success:bool,message:string,log?:string,code?:string}
     */
    public static function restore_site( $archive_file, $options = [], $progress_cb = null ) {
        // Resolve archive path from filename or path
        $archive_path = backup_lite_get_backup_path( $archive_file );
        
        if ( ! $archive_path ) {
            backup_lite_log( 'Restore failed: archive path could not be resolved.', array( 'archive_file' => $archive_file ) );
            
            return array(
                'success' => false,
                'message' => __( 'Backup file not found or unreadable.', 'museder-restoreone' ),
                'code'    => 'archive_not_readable',
            );
        }
        
        if ( ! file_exists( $archive_path ) || ! is_readable( $archive_path ) ) {
            backup_lite_log( 'Restore failed: archive not readable.', array( 'archive_path' => $archive_path ) );
            
            return array(
                'success' => false,
                'message' => __( 'Backup file not found or unreadable.', 'museder-restoreone' ),
                'code'    => 'archive_not_readable',
            );
        }
        
        // Use resolved path for the rest of the restore process
        $archive_file = $archive_path;
        
        $result   = [
            'success' => false,
            'message' => __( 'Restore failed.', 'museder-restoreone' ),
        ];

        $log = backup_lite_log( 'info', 'Site restore started.', [
            'archive' => $archive_file,
            'method'  => 'auto',
        ] );

        if ( is_callable( $progress_cb ) ) {
            call_user_func( $progress_cb, 5, __( 'Preparing restore environment…', 'museder-restoreone' ) );
        }

        $temp_dir = backup_lite_create_temp_dir( 'restore' );

        if ( is_callable( $progress_cb ) ) {
            call_user_func( $progress_cb, 15, __( 'Extracting backup archive…', 'museder-restoreone' ) );
        }

        // Wrap extraction in try-catch to handle PclZip exceptions
        // Even if PclZip throws an exception, extraction may have partially succeeded
        try {
            $extract_result = self::extract_archive( $archive_file, $temp_dir );
        } catch ( Throwable $extract_exception ) {
            // Check if any files were extracted despite the exception
            $extracted_count = 0;
            if ( is_dir( $temp_dir ) ) {
                try {
                    $iterator = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator( $temp_dir, FilesystemIterator::SKIP_DOTS ),
                        RecursiveIteratorIterator::LEAVES_ONLY
                    );
                    $extracted_count = iterator_count( $iterator );
                } catch ( Exception $e ) {
                    // Ignore iterator errors
                }
            }
            
            backup_lite_log( 'warning', 'extract_archive_exception', [
                'archive' => $archive_file,
                'exception' => $extract_exception->getMessage(),
                'extracted_files' => $extracted_count,
            ] );
            
            // If files were extracted, continue with restore
            // PclZip may throw exceptions even when extraction succeeds
            if ( $extracted_count > 0 ) {
                backup_lite_log( 'info', 'Extraction completed despite exception, continuing with restore.', [
                    'extracted_files' => $extracted_count,
                ] );
                $extract_result = [ 'success' => true, 'had_exception' => true ];
            } else {
                // No files extracted, return failure
                backup_lite_log( 'error', 'Extraction failed with exception and no files extracted.', [
                    'archive' => $archive_file,
                    'exception' => $extract_exception->getMessage(),
                ] );
                backup_lite_delete_directory( $temp_dir );
                return [
                    'success' => false,
                    'message' => __( 'Unable to extract backup archive. Check logs for details.', 'museder-restoreone' ),
                    'log'     => $log,
                    'code'    => 'zip_extract_exception',
                    'error'   => $extract_exception->getMessage(),
                ];
            }
        }

        if ( empty( $extract_result['success'] ) ) {
            $ext = strtolower( pathinfo( $archive_file, PATHINFO_EXTENSION ) );
            $error_code = isset( $extract_result['code'] ) ? $extract_result['code'] : 'zip_open_failed';
            
            // Provide more specific error messages for .wpress files
            $error_message = __( 'Unable to extract backup archive. Check logs for details.', 'museder-restoreone' );
            if ( 'wpress' === $ext ) {
                if ( 'tar_not_available' === $error_code ) {
                    $error_message = __( 'Unable to extract .wpress file: tar command is not available on this server. Please contact your hosting provider to enable tar command, or convert the .wpress file to ZIP format first.', 'museder-restoreone' );
                } elseif ( 'wpress_extraction_failed' === $error_code ) {
                    $error_message = __( 'Unable to extract .wpress file. The file may be corrupted, use a custom format, or require All-in-One WP Migration plugin to extract. Please try: 1) Verify the backup file is not corrupted, 2) Use All-in-One WP Migration plugin to convert the backup to ZIP format, or 3) Contact support with the log file for assistance.', 'museder-restoreone' );
                } else {
                    $error_message = __( 'Unable to extract .wpress file. All-in-One WP Migration .wpress files may require special handling. Please try converting the backup to ZIP format using All-in-One WP Migration plugin, or check the logs for details.', 'museder-restoreone' );
                }
            }
            
            backup_lite_log( 'error', 'Failed to extract archive for restore.', [
                'archive'        => $archive_file,
                'zip_error_code' => isset( $extract_result['zip_error_code'] ) ? $extract_result['zip_error_code'] : null,
                'error_code'     => $error_code,
                'extension'      => $ext,
            ] );
            backup_lite_delete_directory( $temp_dir );

            return [
                'success' => false,
                'message' => $error_message,
                'log'     => $log,
                'code'    => $error_code,
                'zip_error_code' => isset( $extract_result['zip_error_code'] ) ? $extract_result['zip_error_code'] : null,
            ];
        }

        if ( is_callable( $progress_cb ) ) {
            call_user_func( $progress_cb, 30, __( 'Locating database file…', 'museder-restoreone' ) );
        }

        $sql_path = self::locate_database_dump( $temp_dir );
        if ( ! $sql_path ) {
            backup_lite_log( 'error', 'database.sql missing in archive.', [ 'archive' => $archive_file ] );
            backup_lite_delete_directory( $temp_dir );

            return [
                'success' => false,
                'message' => __( 'database.sql not found in backup archive.', 'museder-restoreone' ),
                'log'     => $log,
                'code'    => 'sql_not_found',
            ];
        }

        if ( is_callable( $progress_cb ) ) {
            call_user_func( $progress_cb, 40, __( 'Preparing database import…', 'museder-restoreone' ) );
        }

        $db_result = self::import_database( $sql_path, $progress_cb );
        if ( empty( $db_result['success'] ) ) {
            backup_lite_delete_directory( $temp_dir );
            return $db_result;
        }

        // Store active_plugins from SQL file for later restoration
        if ( ! empty( $db_result['active_plugins'] ) && is_array( $db_result['active_plugins'] ) ) {
            // Store in a temporary option that will be used after restore
            update_option( 'backup_lite_restored_active_plugins', $db_result['active_plugins'], false );
            backup_lite_log( 'info', 'Stored active_plugins from SQL file for restoration.', [
                'count' => count( $db_result['active_plugins'] ),
            ] );
        }

        if ( is_callable( $progress_cb ) ) {
            call_user_func( $progress_cb, 70, __( 'Restoring files from backup…', 'museder-restoreone' ) );
        }

        $files_result = self::restore_files_from_extract( $temp_dir );
        if ( empty( $files_result['success'] ) ) {
            backup_lite_delete_directory( $temp_dir );
            return $files_result;
        }

        if ( ! empty( $options['search_replace'] ) && is_array( $options['search_replace'] ) ) {
            if ( is_callable( $progress_cb ) ) {
                call_user_func( $progress_cb, 85, __( 'Applying URL search & replace…', 'museder-restoreone' ) );
            }
            self::run_search_replace( $options['search_replace'] );
        }

        if ( is_callable( $progress_cb ) ) {
            call_user_func( $progress_cb, 90, __( 'Cleaning up temporary files…', 'museder-restoreone' ) );
        }

        backup_lite_delete_directory( $temp_dir );

        backup_lite_log( 'info', 'Site restore completed.', [ 'archive' => $archive_file ] );

        return [
            'success' => true,
            'message' => __( 'Restore completed successfully.', 'museder-restoreone' ),
            'log'     => $log,
        ];
    }

    /**
     * Import the WordPress database from an SQL file.
     *
     * @param string $sql_file
     */
    public static function import_database( $sql_file, $progress_cb = null ) {
        $sql_file = wp_normalize_path( $sql_file );
        $result   = [
            'success' => false,
            'message' => __( 'Database restore failed.', 'museder-restoreone' ),
        ];

        if ( ! file_exists( $sql_file ) || ! is_readable( $sql_file ) ) {
            $log               = backup_lite_log( 'error', 'SQL file not readable for restore.', [ 'path' => $sql_file ] );
            $result['message'] = __( 'SQL backup file not found or unreadable.', 'museder-restoreone' );
            $result['log']     = $log;
            $result['code']    = 'sql_file_missing';
            return $result;
        }

        if ( is_callable( $progress_cb ) ) {
            call_user_func( $progress_cb, 45, __( 'Preparing database SQL file…', 'museder-restoreone' ) );
        }

        // Extract active_plugins from SQL file before import
        $active_plugins_from_sql = self::extract_active_plugins_from_sql( $sql_file );
        if ( ! empty( $active_plugins_from_sql ) ) {
            $result['active_plugins'] = $active_plugins_from_sql;
            backup_lite_log( 'info', 'Extracted active_plugins from SQL file before import.', [
                'count' => count( $active_plugins_from_sql ),
            ] );
        }

        $prepared_sql = self::prepare_sql_for_import( $sql_file );
        $sql_to_import = $prepared_sql['path'];

        $method = backup_lite_can_use_mysql_cli() ? 'mysql-cli' : 'php';
        $log    = backup_lite_log( 'info', 'Database restore started.', [ 'method' => $method, 'path' => $sql_to_import ] );

        if ( is_callable( $progress_cb ) ) {
            $method_text = backup_lite_can_use_mysql_cli() ? __( 'Importing database via MySQL CLI…', 'museder-restoreone' ) : __( 'Importing database via PHP…', 'museder-restoreone' );
            call_user_func( $progress_cb, 50, $method_text );
        }

        try {
            if ( backup_lite_can_use_mysql_cli() ) {
                $success     = self::import_database_with_cli( $sql_to_import );
                $php_details = [];
            } else {
                $php_details = self::import_database_with_php( $sql_to_import, $progress_cb );
                $success     = isset( $php_details['success'] ) ? $php_details['success'] : false;
            }

            if ( is_callable( $progress_cb ) && $success ) {
                call_user_func( $progress_cb, 65, __( 'Database import completed.', 'museder-restoreone' ) );
            }

            if ( ! $success ) {
                backup_lite_log( 'error', 'Database restore failed.', [ 'path' => $sql_to_import ] );
                $result['message'] = __( 'Database restore encountered an error. Check logs.', 'museder-restoreone' );
                $result['log']     = $log;
                if ( ! empty( $php_details['line'] ) ) {
                    $result['line'] = $php_details['line'];
                }
                $result['code'] = isset( $php_details['code'] ) ? $php_details['code'] : 'database_error';
                return $result;
            }
        } finally {
            if ( $prepared_sql['temporary'] && file_exists( $prepared_sql['path'] ) ) {
                // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
                // $prepared_sql['path'] is from plugin-controlled temp directory
                if ( function_exists( 'wp_delete_file' ) ) {
                    wp_delete_file( $prepared_sql['path'] );
                } else {
                    // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                    if ( function_exists( 'wp_delete_file' ) ) {
                        wp_delete_file( $prepared_sql['path'] );
                    } else {
                        // Fallback for non-standard environments.
                        if ( file_exists( $prepared_sql['path'] ) ) {
                            @unlink( $prepared_sql['path'] );
                        }
                    }
                    // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
                }
            }
        }

        backup_lite_log( 'info', 'Database restore completed.', [ 'path' => $sql_to_import ] );

        $result['success'] = true;
        $result['message'] = __( 'Database restore completed successfully.', 'museder-restoreone' );
        $result['log']     = $log;
        $result['code']    = 'database_restored';

        return $result;
    }

    private static function prepare_sql_for_import( $sql_file ) {
        $default = [
            'path'      => $sql_file,
            'temporary' => false,
        ];

        if ( ! file_exists( $sql_file ) || ! is_readable( $sql_file ) ) {
            return $default;
        }

        $placeholder = 'SERVMASK_PREFIX_';
        $needs_normalize = false;

        $handle = self::stream_open( $sql_file, 'rb' );
        if ( $handle ) {
            // Only reads plugin-generated backup files, path is validated and sanitized.
            $sample = self::stream_read( $handle, 1048576 ); // 1MB sample.
            if ( false !== strpos( $sample, $placeholder ) ) {
                $needs_normalize = true;
            }
            self::stream_close( $handle );
        }

        if ( ! $needs_normalize ) {
            return $default;
        }

        self::cleanup_servmask_tables();

        global $wpdb;
        $prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
        if ( '' === $prefix ) {
            backup_lite_log( 'warning', 'Detected SERVMASK_PREFIX in SQL but database prefix is unknown. Proceeding without normalization.' );
            return $default;
        }

        $normalized = $sql_file . '.normalized.sql';
        $in  = self::stream_open( $sql_file, 'rb' );
        $out = self::stream_open( $normalized, 'wb' );

        if ( ! $in || ! $out ) {
            if ( $in ) {
                self::stream_close( $in );
            }
            if ( $out ) {
                self::stream_close( $out );
            }
            backup_lite_log( 'warning', 'Unable to create normalized SQL file for SERVMASK export.', [ 'source' => $sql_file ] );
            return $default;
        }

        // Detect file size to choose optimal strategy
        $file_size = filesize( $sql_file );
        $is_large_file = $file_size >= 1073741824; // 1GB or larger
        
        // For large files (1GB+), use larger chunks and more aggressive overlap
        $chunk_size = $is_large_file ? 10485760 : 1048576; // 10MB for large files, 1MB for smaller
        $placeholder_len = strlen( $placeholder );
        // Use 3x placeholder length for large files to ensure no cross-boundary issues
        $overlap    = $is_large_file ? ( $placeholder_len * 3 ) : ( $placeholder_len * 2 );
        $buffer     = '';

        // First pass: streaming replacement with overlap buffer
        while ( ! feof( $in ) ) {
            $chunk = self::stream_read( $in, $chunk_size );
            if ( false === $chunk ) {
                break;
            }

            // Prepend previous buffer to handle cross-chunk placeholders
            $chunk = $buffer . $chunk;

            // Ensure we have enough data to safely extract a chunk
            if ( strlen( $chunk ) > $overlap ) {
                $buffer        = substr( $chunk, -$overlap );
                $chunk_to_write = substr( $chunk, 0, -$overlap );
            } else {
                // Not enough data yet, accumulate in buffer
                $buffer = $chunk;
                continue;
            }

            // Replace placeholder in the chunk we're about to write
            $chunk_to_write = str_replace( $placeholder, $prefix, $chunk_to_write );
            self::stream_write( $out, $chunk_to_write );
        }

        // Write remaining buffer
        if ( $buffer !== '' ) {
            $buffer = str_replace( $placeholder, $prefix, $buffer );
            self::stream_write( $out, $buffer );
        }

        self::stream_close( $in );
        self::stream_close( $out );

        // For large files (1GB+), always do a second pass to ensure 100% replacement
        // This is necessary because even with large overlap, edge cases can occur
        if ( $is_large_file ) {
            backup_lite_log( 'info', 'Large SQL file detected, performing second normalization pass for safety.', [ 
                'file' => basename( $normalized ),
                'size' => round( $file_size / 1073741824, 2 ) . 'GB'
            ] );
            
            $temp_file = $normalized . '.tmp';
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- required for creating temp file for second pass, paths from plugin-controlled directory
            // This plugin needs low-level rename() here for streaming backup/restore performance.
            // Using WP_Filesystem::move() is not always reliable across all hosting environments.
            // @phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename
            $renamed = rename( $normalized, $temp_file );
            // @phpcs:enable WordPress.WP.AlternativeFunctions.rename_rename
            if ( $renamed ) {
                $in2  = self::stream_open( $temp_file, 'rb' );
                $out2 = self::stream_open( $normalized, 'wb' );
                
                if ( $in2 && $out2 ) {
                    $second_buffer = '';
                    $second_chunk_size = $chunk_size; // Use same chunk size
                    
                    while ( ! feof( $in2 ) ) {
                        // Only reads plugin-generated backup files, path is validated and sanitized.
                        $chunk2 = self::stream_read( $in2, $second_chunk_size );
                        if ( false === $chunk2 ) {
                            break;
                        }
                        
                        // Prepend buffer to handle cross-boundary placeholders
                        $chunk2 = $second_buffer . $chunk2;
                        
                        if ( strlen( $chunk2 ) > $overlap ) {
                            $second_buffer = substr( $chunk2, -$overlap );
                            $chunk_to_write2 = substr( $chunk2, 0, -$overlap );
                        } else {
                            $second_buffer = $chunk2;
                            continue;
                        }
                        
                        // Replace any remaining placeholders
                        $chunk_to_write2 = str_replace( $placeholder, $prefix, $chunk_to_write2 );
                        self::stream_write( $out2, $chunk_to_write2 );
                    }
                    
                    // Write remaining buffer
                    if ( $second_buffer !== '' ) {
                        $second_buffer = str_replace( $placeholder, $prefix, $second_buffer );
                        self::stream_write( $out2, $second_buffer );
                    }
                    
                    self::stream_close( $in2 );
                    self::stream_close( $out2 );
                }
                // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
                // $temp_file is from plugin-controlled temp directory
                // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                if ( function_exists( 'wp_delete_file' ) ) {
                    wp_delete_file( $temp_file );
                } else {
                    // Fallback for non-standard environments.
                    if ( file_exists( $temp_file ) ) {
                        @unlink( $temp_file );
                    }
                }
                // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
                
                backup_lite_log( 'info', 'Second normalization pass completed for large file.', [ 'file' => basename( $normalized ) ] );
            }
        } else {
            // For smaller files, verify and do second pass only if needed
            $normalized_size = filesize( $normalized );
            if ( $normalized_size > 0 && $normalized_size < 52428800 ) { // < 50MB
                // Using native file APIs on local backup directory; paths are sanitized and constrained.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                $verify_content = file_get_contents( $normalized );
                if ( false !== $verify_content && false !== strpos( $verify_content, $placeholder ) ) {
                    backup_lite_log( 'warning', 'SERVMASK placeholder still found after first pass, running second normalization pass.', [ 'file' => basename( $normalized ) ] );
                    
                    $verify_content = str_replace( $placeholder, $prefix, $verify_content );
                    // Using native file APIs on local backup directory; paths are sanitized and constrained.
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
                    file_put_contents( $normalized, $verify_content );
                }
            }
        }

        backup_lite_log(
            'info',
            'Normalized SERVMASK SQL prefix.',
            [
                'source'     => basename( $sql_file ),
                'normalized' => basename( $normalized ),
                'prefix'     => $prefix,
            ]
        );

        return [
            'path'      => $normalized,
            'temporary' => true,
        ];
    }

    /**
     * Drop leftover SERVMASK placeholder tables created by All-in-One WP Migration imports.
     */
    private static function cleanup_servmask_tables() {
        global $wpdb;

        // Introspection query used during restore to detect SERVMASK placeholder tables.
        // Pattern is hardcoded; identifiers are validated via a live whitelist helper.
        $tables = backup_lite_get_servmask_table_whitelist();
        if ( empty( $tables ) ) {
            return;
        }

        $dropped = [];

        foreach ( $tables as $table ) {
            $safe = backup_lite_validate_servmask_table_name( $table );
            if ( ! $safe ) {
                continue;
            }

            // Runtime schema change is required for restore correctness (cleanup of placeholder tables).
            // Table identifier cannot be prepared; validated via backup_lite_validate_servmask_table_name()
            // against a live whitelist sourced from the DB engine. No user input reaches SQL here.
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $wpdb->query(
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifier cannot be passed as a prepared value; validated via backup_lite_validate_servmask_table_name() (live SERVMASK whitelist).
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier cannot be prepared; validated via backup_lite_validate_servmask_table_name() (live SERVMASK whitelist).
                "DROP TABLE IF EXISTS `{$safe}`"
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            $dropped[] = $safe;
        }

        if ( ! empty( $dropped ) ) {
            backup_lite_log(
                'info',
                'Removed SERVMASK placeholder tables before restore.',
                [
                    'tables' => $dropped,
                ]
            );
        }
    }

    private static function restore_files_from_extract( $extract_dir ) {
        $targets = [];
        $wp_content_source = self::find_directory_by_name( $extract_dir, 'wp-content' );

        if ( $wp_content_source && is_dir( $wp_content_source ) ) {
            $targets[] = [ $wp_content_source, WP_CONTENT_DIR ];
        } else {
            $fallbacks = [
                'themes'     => WP_CONTENT_DIR . '/themes',
                'plugins'    => WP_CONTENT_DIR . '/plugins',
                'uploads'    => WP_CONTENT_DIR . '/uploads',
                'mu-plugins' => WP_CONTENT_DIR . '/mu-plugins',
            ];

            foreach ( $fallbacks as $dir => $destination ) {
                $source = self::find_directory_by_name( $extract_dir, $dir );
                if ( $source && is_dir( $source ) ) {
                    $targets[] = [ $source, $destination ];
                }
            }
        }

        if ( empty( $targets ) ) {
            backup_lite_log( 'warning', 'No wp-content data found in archive.', [
                'extract_dir' => wp_normalize_path( $extract_dir ),
                'top_level'   => self::summarize_extract_contents( $extract_dir ),
            ] );
            return [
                'success' => true,
                'message' => __( 'Database restored, but no wp-content data found in archive.', 'museder-restoreone' ),
            ];
        }

        foreach ( $targets as $pair ) {
            list( $source, $destination ) = $pair;
            if ( ! self::copy_directory( $source, $destination ) ) {
                $log = backup_lite_log( 'error', 'Failed to copy files during restore.', [
                    'source' => $source,
                    'destination' => $destination,
                ] );

                return [
                    'success' => false,
                    'message' => __( 'File restore failed. Check file permissions.', 'museder-restoreone' ),
                    'log'     => $log,
                ];
            }
        }

        backup_lite_log( 'info', 'File restore completed.' );

        return [
            'success' => true,
            'message' => __( 'Files restored successfully.', 'museder-restoreone' ),
        ];
    }

    private static function extract_archive( $archive, $destination ) {
        $zip_error_code = null;

        // Check if this is a .wpress file (All-in-One WP Migration format)
        $ext = strtolower( pathinfo( $archive, PATHINFO_EXTENSION ) );
        if ( 'wpress' === $ext ) {
            // Try to extract .wpress file using tar command (it's a gzip-compressed tar archive)
            $wpress_result = self::extract_with_tar( $archive, $destination );
            if ( ! empty( $wpress_result['success'] ) ) {
                return [
                    'success'        => true,
                    'method'         => 'tar',
                    'zip_error_code' => 0,
                ];
            }

            // If tar extraction failed, log and continue to try ZIP methods as fallback
            backup_lite_log( 'warning', 'WPRESS extraction with tar failed, attempting ZIP methods as fallback', [
                'archive' => $archive,
                'error'  => isset( $wpress_result['error'] ) ? $wpress_result['error'] : 'unknown',
            ] );
        }

        if ( backup_lite_can_use_ziparchive() ) {
            $zip_result = self::extract_with_ziparchive( $archive, $destination );
            if ( ! empty( $zip_result['success'] ) ) {
                return [
                    'success'        => true,
                    'method'         => 'ZipArchive',
                    'zip_error_code' => 0,
                ];
            }

            if ( isset( $zip_result['error_code'] ) ) {
                $zip_error_code = $zip_result['error_code'];
            }
        }

        // Try PclZip only if ZipArchive failed completely
        // PclZip has compatibility issues with some WordPress versions
        $pcl_result = self::extract_with_pclzip( $archive, $destination );
        if ( ! empty( $pcl_result['success'] ) ) {
            return [
                'success'        => true,
                'method'         => 'PclZip',
                'zip_error_code' => 0,
            ];
        }

        if ( isset( $pcl_result['error_code'] ) ) {
            $zip_error_code = $zip_error_code ?? $pcl_result['error_code'];
        }

        // If all methods failed, return error
        $error_message = 'Both ZipArchive and PclZip extraction failed';
        if ( 'wpress' === $ext ) {
            $error_message = 'WPRESS extraction failed. All-in-One WP Migration .wpress files require tar command or need to be converted to ZIP format first.';
        }
        
        backup_lite_log( 'error', $error_message, [
            'archive'    => $archive,
            'zip_error_code' => $zip_error_code,
            'pclzip_error' => isset( $pcl_result['error'] ) ? $pcl_result['error'] : '',
        ] );

        return [
            'success'        => false,
            'code'           => 'wpress' === $ext ? 'wpress_extraction_failed' : 'zip_open_failed',
            'zip_error_code' => $zip_error_code,
        ];
    }

    private static function extract_with_ziparchive( $archive, $destination ) {
        $zip = new ZipArchive();
        $open_result = $zip->open( $archive );
        $ok_code     = defined( 'ZipArchive::ER_OK' ) ? ZipArchive::ER_OK : 0;

        if ( true !== $open_result && $ok_code !== $open_result ) {
            backup_lite_log( 'error', 'zip_open_failed', [
                'archive'    => $archive,
                'error'      => $zip->getStatusString(),
                'error_code' => $open_result,
            ] );
            return [ 'success' => false, 'error_code' => $open_result ];
        }

        $destination = wp_normalize_path( $destination );
        $skipped     = 0;
        $error_code  = null;

        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $entry  = $zip->getNameIndex( $i );
            $target = false;

            if ( self::is_suspicious_zip_entry( $entry ) ) {
                backup_lite_log( 'warning', 'zip_entry_skipped', [ 'entry' => $entry, 'reason' => 'suspicious_path' ] );
                $skipped++;
                continue;
            }

            $target = backup_lite_safe_path_join( $destination, $entry );

            if ( ! $target ) {
                backup_lite_log( 'warning', 'zip_entry_skipped', [ 'entry' => $entry, 'reason' => 'unsafe_join' ] );
                $skipped++;
                continue;
            }

            if ( substr( $entry, -1 ) === '/' ) {
                backup_lite_ensure_directory( $target );
                continue;
            }

            backup_lite_ensure_directory( dirname( $target ) );

            $input = $zip->getStream( $entry );
            if ( ! $input ) {
                backup_lite_log( 'error', 'Unable to read entry during extraction.', [ 'entry' => $entry ] );
                $error_code = 'entry_stream_unreadable';
                break;
            }

            $output = self::stream_open( $target, 'wb' );
            if ( ! $output ) {
                backup_lite_log( 'error', 'Unable to write extracted file.', [ 'target' => $target ] );
                self::stream_close( $input );
                $error_code = 'entry_unwritable';
                break;
            }

            while ( ! feof( $input ) ) {
                // Only reads plugin-generated backup files, path is validated and sanitized.
                $buffer = self::stream_read( $input, 1048576 );
                if ( false === $buffer ) {
                    backup_lite_log( 'error', 'Error while reading stream during extraction.', [ 'entry' => $entry ] );
                    $error_code = 'stream_read_error';
                    break;
                }
                if ( false === self::stream_write( $output, $buffer ) ) {
                    backup_lite_log( 'error', 'Unable to write buffer during extraction.', [ 'target' => $target ] );
                    $error_code = 'stream_write_error';
                    break;
                }
            }

            self::stream_close( $input );
            self::stream_close( $output );

            if ( null !== $error_code ) {
                break;
            }
        }

        $zip->close();

        if ( $skipped > 0 ) {
            backup_lite_log( 'warning', 'zip_entries_skipped_summary', [ 'count' => $skipped ] );
        }

        if ( null !== $error_code ) {
            return [ 'success' => false, 'error_code' => $error_code ];
        }

        return [ 'success' => true, 'entries' => $zip->numFiles ];
    }

    /**
     * Extract .wpress file using tar command
     * .wpress files can be gzip-compressed tar, uncompressed tar, or other formats
     *
     * @param string $archive Path to .wpress file
     * @param string $destination Destination directory
     * @return array{success:bool, error?:string, error_code?:string}
     */
    private static function extract_with_tar( $archive, $destination ) {
        // Check if tar command is available
        if ( ! backup_lite_command_exists( 'tar' ) ) {
            backup_lite_log( 'warning', 'tar command not available for WPRESS extraction', [
                'archive' => basename( $archive ),
            ] );
            return [
                'success'    => false,
                'error'      => __( 'tar command is not available on this server. .wpress files require tar command for extraction.', 'museder-restoreone' ),
                'error_code' => 'tar_not_available',
            ];
        }

        // Ensure destination directory exists
        $destination = wp_normalize_path( $destination );
        backup_lite_ensure_directory( $destination );

        // Sanitize paths for shell command
        $archive_escaped = escapeshellarg( $archive );
        $destination_escaped = escapeshellarg( $destination );

        // Detect file format by reading first few bytes
        $file_handle = self::stream_open( $archive, 'rb' );
        $file_header = '';
        if ( $file_handle ) {
            $file_header = self::stream_read( $file_handle, 512 ); // Read first 512 bytes
            self::stream_close( $file_handle );
        }

        // Try different extraction methods based on file format
        $methods = [];
        
        // Check if it's gzip compressed (starts with 0x1f 0x8b)
        if ( strlen( $file_header ) >= 2 && ord( $file_header[0] ) === 0x1f && ord( $file_header[1] ) === 0x8b ) {
            // Gzip compressed tar: tar -xzf
            $methods[] = [
                'command' => sprintf( 'tar -xzf %s -C %s 2>&1', $archive_escaped, $destination_escaped ),
                'method' => 'gzip-compressed tar',
            ];
        }
        
        // Check if it's a tar file (starts with tar magic bytes or ustar)
        if ( strlen( $file_header ) >= 263 ) {
            $ustar_pos = strpos( $file_header, 'ustar', 257 );
            if ( $ustar_pos !== false || substr( $file_header, 0, 4 ) === "\x00\x00\x00" ) {
                // Uncompressed tar: tar -xf
                $methods[] = [
                    'command' => sprintf( 'tar -xf %s -C %s 2>&1', $archive_escaped, $destination_escaped ),
                    'method' => 'uncompressed tar',
                ];
            }
        }
        
        // If we couldn't detect format, try both methods in order
        if ( empty( $methods ) ) {
            $methods[] = [
                'command' => sprintf( 'tar -xzf %s -C %s 2>&1', $archive_escaped, $destination_escaped ),
                'method' => 'gzip-compressed tar (auto-detect)',
            ];
            $methods[] = [
                'command' => sprintf( 'tar -xf %s -C %s 2>&1', $archive_escaped, $destination_escaped ),
                'method' => 'uncompressed tar (fallback)',
            ];
        }

        backup_lite_log( 'info', 'Extracting WPRESS file with tar command', [
            'archive' => basename( $archive ),
            'destination' => $destination,
            'detected_methods' => count( $methods ),
        ] );

        $last_error = '';
        $last_return_code = 0;

        foreach ( $methods as $method_info ) {
            $command = $method_info['command'];
            $method_name = $method_info['method'];
            
            backup_lite_log( 'info', 'Attempting WPRESS extraction', [
                'archive' => basename( $archive ),
                'method' => $method_name,
            ] );

            $output = [];
            $return_code = 0;

            if ( function_exists( 'exec' ) ) {
                exec( $command, $output, $return_code );
            } elseif ( function_exists( 'shell_exec' ) ) {
                $output_str = shell_exec( $command . ' 2>&1' );
                $output = ! empty( $output_str ) ? explode( "\n", trim( $output_str ) ) : [];
                // For shell_exec, check if output contains error indicators
                if ( ! empty( $output_str ) && ( stripos( $output_str, 'error' ) !== false || stripos( $output_str, 'failed' ) !== false ) ) {
                    $return_code = 1;
                }
            } else {
                return [
                    'success'    => false,
                    'error'      => __( 'No shell execution function available for tar extraction.', 'museder-restoreone' ),
                    'error_code' => 'shell_not_available',
                ];
            }

            if ( 0 === $return_code ) {
                // Success! Verify that files were actually extracted
                $extracted_files = 0;
                if ( is_dir( $destination ) ) {
                    try {
                        $iterator = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator( $destination, FilesystemIterator::SKIP_DOTS ),
                            RecursiveIteratorIterator::LEAVES_ONLY
                        );
                        $extracted_files = iterator_count( $iterator );
                    } catch ( Exception $e ) {
                        backup_lite_log( 'warning', 'Unable to count extracted files after tar extraction', [
                            'error' => $e->getMessage(),
                        ] );
                    }
                }

                if ( $extracted_files > 0 ) {
                    backup_lite_log( 'info', 'WPRESS tar extraction completed successfully', [
                        'archive' => basename( $archive ),
                        'method' => $method_name,
                        'files_extracted' => $extracted_files,
                    ] );

                    return [
                        'success' => true,
                    ];
                } else {
                    // No files extracted, continue to next method
                    backup_lite_log( 'warning', 'WPRESS tar extraction completed but no files found', [
                        'archive' => basename( $archive ),
                        'method' => $method_name,
                    ] );
                    $last_error = __( 'tar extraction completed but no files were extracted.', 'museder-restoreone' );
                    $last_return_code = 1;
                    continue;
                }
            } else {
                // This method failed, try next one
                $error_message = ! empty( $output ) ? implode( "\n", $output ) : __( 'tar extraction failed', 'museder-restoreone' );
                backup_lite_log( 'warning', 'WPRESS tar extraction method failed, trying next method', [
                    'archive' => basename( $archive ),
                    'method' => $method_name,
                    'return_code' => $return_code,
                    'error' => $error_message,
                ] );
                $last_error = $error_message;
                $last_return_code = $return_code;
                continue;
            }
        }

        // All tar methods failed - try PHP native extraction as last resort
        // .wpress files may use All-in-One WP Migration's custom format
        backup_lite_log( 'info', 'WPRESS tar extraction failed, attempting PHP native extraction', [
            'archive' => basename( $archive ),
        ] );
        
        $php_result = self::extract_wpress_with_php( $archive, $destination );
        if ( ! empty( $php_result['success'] ) ) {
            return $php_result;
        }
        
        // All methods failed
        backup_lite_log( 'error', 'WPRESS extraction failed with all methods (tar and PHP)', [
            'archive' => basename( $archive ),
            'last_error' => $last_error,
            'last_return_code' => $last_return_code,
            'php_error' => isset( $php_result['error'] ) ? $php_result['error'] : '',
        ] );
        
        // Provide more helpful error message
        $error_message = __( 'Unable to extract .wpress file. The file may be corrupted, in an unsupported format, or require All-in-One WP Migration plugin to extract. Please try using All-in-One WP Migration plugin to convert the backup to ZIP format first.', 'museder-restoreone' );
        
        return [
            'success'    => false,
            'error'      => $error_message,
            'error_code' => 'wpress_extraction_failed',
        ];
    }
    
    /**
     * Attempt to extract .wpress file using PHP native functions
     * This is a fallback when tar command fails
     *
     * @param string $archive Path to .wpress file
     * @param string $destination Destination directory
     * @return array{success:bool, error?:string, error_code?:string}
     */
    private static function extract_wpress_with_php( $archive, $destination ) {
        // Ensure destination directory exists
        $destination = wp_normalize_path( $destination );
        backup_lite_ensure_directory( $destination );
        
        backup_lite_log( 'info', 'Attempting WPRESS extraction with PHP native functions', [
            'archive' => basename( $archive ),
            'destination' => $destination,
        ] );
        
        // Check if file is readable
        if ( ! file_exists( $archive ) || ! is_readable( $archive ) ) {
            return [
                'success'    => false,
                'error'      => __( 'WPRESS file is not readable.', 'museder-restoreone' ),
                'error_code' => 'file_not_readable',
            ];
        }
        
        // Read file header to determine format
        $file_handle = self::stream_open( $archive, 'rb' );
        if ( ! $file_handle ) {
            return [
                'success'    => false,
                'error'      => __( 'Unable to open WPRESS file for reading.', 'museder-restoreone' ),
                'error_code' => 'file_open_failed',
            ];
        }
        
        $header = self::stream_read( $file_handle, 1024 );
        self::stream_close( $file_handle );
        
        // Check for gzip magic bytes (0x1f 0x8b)
        if ( strlen( $header ) >= 2 && ord( $header[0] ) === 0x1f && ord( $header[1] ) === 0x8b ) {
            // Try gzopen
            if ( function_exists( 'gzopen' ) ) {
                $gz_handle = gzopen( $archive, 'rb' );
                if ( $gz_handle ) {
                    // Read and write decompressed data
                    $output_file = trailingslashit( $destination ) . 'extracted_content';
                    $output_handle = self::stream_open( $output_file, 'wb' );
                    if ( $output_handle ) {
                        $bytes_written = 0;
                        while ( ! gzeof( $gz_handle ) ) {
                            $chunk = gzread( $gz_handle, 8192 );
                            if ( false === $chunk ) {
                                break;
                            }
                            self::stream_write( $output_handle, $chunk );
                            $bytes_written += strlen( $chunk );
                        }
                        self::stream_close( $output_handle );
                        gzclose( $gz_handle );
                        
                        if ( $bytes_written > 0 ) {
                            backup_lite_log( 'info', 'WPRESS PHP extraction completed (gzip)', [
                                'archive' => basename( $archive ),
                                'bytes_written' => $bytes_written,
                            ] );
                            // Note: This extracts to a single file, not a directory structure
                            // .wpress files may need special handling beyond simple gzip
                            return [
                                'success' => true,
                                'note' => 'Extracted as single file - may need further processing',
                            ];
                        }
                    }
                    gzclose( $gz_handle );
                }
            }
        }
        
        // If we get here, PHP native extraction also failed
        backup_lite_log( 'warning', 'WPRESS PHP native extraction failed', [
            'archive' => basename( $archive ),
            'header_length' => strlen( $header ),
            'header_start' => bin2hex( substr( $header, 0, 16 ) ),
        ] );
        
        return [
            'success'    => false,
            'error'      => __( 'PHP native extraction failed. .wpress file may use a custom format that requires All-in-One WP Migration plugin.', 'museder-restoreone' ),
            'error_code' => 'php_extraction_failed',
        ];
    }

    private static function extract_with_pclzip( $archive, $destination ) {
        if ( ! class_exists( 'PclZip' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }

        self::$pclzip_destination = wp_normalize_path( $destination );

        $zip = new PclZip( $archive );
        
        // Use individual parameters instead of array to avoid PclZip parsing issues
        // Some WordPress versions have PclZip that doesn't handle option arrays correctly
        // We'll extract without callback first, then filter suspicious files after extraction
        // Wrap in try-catch to handle exceptions that PclZip may throw
        try {
            $result = $zip->extract(
                PCLZIP_OPT_PATH, self::$pclzip_destination
            );
        } catch ( Throwable $e ) {
            // PclZip may throw exceptions for certain errors
            // Check if any files were extracted before declaring failure
            $extracted_files = 0;
            if ( is_dir( self::$pclzip_destination ) ) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator( self::$pclzip_destination, FilesystemIterator::SKIP_DOTS ),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                $extracted_files = iterator_count( $iterator );
            }
            
            backup_lite_log( 'warning', 'pclzip_extract_exception', [
                'archive'    => $archive,
                'exception'   => $e->getMessage(),
                'extracted_files' => $extracted_files,
            ] );
            
            // If some files were extracted, consider it partially successful
            // The extraction may have completed despite the exception
            if ( $extracted_files > 0 ) {
                backup_lite_log( 'info', 'PclZip extraction completed despite exception, continuing with restore.', [
                    'extracted_files' => $extracted_files,
                ] );
                // Return success with a note that we had an exception but files were extracted
                return [ 'success' => true, 'entries' => $extracted_files, 'had_exception' => true ];
            }
            
            // No files extracted, return failure
            return [
                'success'    => false,
                'error_code' => 'pclzip_exception',
                'error'      => $e->getMessage(),
            ];
        }

        if ( 0 === $result ) {
            $error_code = method_exists( $zip, 'errorCode' ) ? $zip->errorCode() : 'pclzip_error';
            $error_info = method_exists( $zip, 'errorInfo' ) ? $zip->errorInfo( true ) : '';

            backup_lite_log( 'error', 'pclzip_extract_failed', [
                'archive'    => $archive,
                'error_code' => $error_code,
                'error'      => $error_info,
            ] );

            return [
                'success'    => false,
                'error_code' => $error_code,
                'error'      => $error_info,
            ];
        }

        // After extraction, filter out suspicious files that would have been caught by callback
        // PclZip extract() may return an array of entries or just a count, handle both cases
        if ( is_array( $result ) && count( $result ) > 0 ) {
            $removed_count = 0;
            foreach ( $result as $entry ) {
                // Handle both array format and potential string format
                if ( ! is_array( $entry ) ) {
                    continue;
                }
                
                $entry_path = null;
                if ( isset( $entry['stored_filename'] ) && is_string( $entry['stored_filename'] ) ) {
                    $entry_path = $entry['stored_filename'];
                } elseif ( isset( $entry['filename'] ) && is_string( $entry['filename'] ) ) {
                    $entry_path = $entry['filename'];
                }
                
                if ( ! $entry_path ) {
                    continue;
                }
                
                if ( self::is_suspicious_zip_entry( $entry_path ) ) {
                    $target = backup_lite_safe_path_join( self::$pclzip_destination, $entry_path );
                    if ( $target && file_exists( $target ) ) {
                        // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
                        // $target is from plugin-controlled extract directory
                        // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                        if ( function_exists( 'wp_delete_file' ) ) {
                            wp_delete_file( $target );
                        } else {
                            // Fallback for non-standard environments.
                            if ( file_exists( $target ) ) {
                                @unlink( $target );
                            }
                        }
                        // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
                        $removed_count++;
                        backup_lite_log( 'warning', 'zip_entry_removed_after_extraction', [
                            'entry' => $entry_path,
                            'reason' => 'suspicious_path',
                        ] );
                    }
                } else {
                    // Also check for unsafe path joins
                    $target = backup_lite_safe_path_join( self::$pclzip_destination, $entry_path );
                    if ( ! $target && isset( $entry['filename'] ) && is_string( $entry['filename'] ) ) {
                        $full_path = trailingslashit( self::$pclzip_destination ) . $entry['filename'];
                        if ( file_exists( $full_path ) ) {
                            // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
                            // $full_path is from plugin-controlled extract directory
                            // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                            if ( function_exists( 'wp_delete_file' ) ) {
                                wp_delete_file( $full_path );
                            } else {
                                // Fallback for non-standard environments.
                                if ( file_exists( $full_path ) ) {
                                    @unlink( $full_path );
                                }
                            }
                            // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
                            $removed_count++;
                            backup_lite_log( 'warning', 'zip_entry_removed_after_extraction', [
                                'entry' => $entry_path,
                                'reason' => 'unsafe_join',
                            ] );
                        }
                    }
                }
            }
            if ( $removed_count > 0 ) {
                backup_lite_log( 'info', 'zip_entries_filtered_after_extraction', [ 'count' => $removed_count ] );
            }
        }

        return [ 'success' => true, 'entries' => $result ];
    }

    public static function pclzip_pre_extract( $event, &$header ) {
         if ( 'check' === $event ) {
            if ( self::is_suspicious_zip_entry( $header['stored_filename'] ) ) {
                backup_lite_log( 'warning', 'zip_entry_skipped', [ 'entry' => $header['stored_filename'], 'reason' => 'suspicious_path' ] );
                return 0;
            }

            $target = backup_lite_safe_path_join( self::$pclzip_destination, $header['stored_filename'] );
            if ( ! $target ) {
                backup_lite_log( 'warning', 'zip_entry_skipped', [ 'entry' => $header['stored_filename'], 'reason' => 'unsafe_join' ] );
                return 0;
            }
         }
 
         return 1;
    }

    private static function locate_database_dump( $directory ) {
        $candidate = trailingslashit( $directory ) . 'database.sql';
        if ( file_exists( $candidate ) ) {
            return $candidate;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( strtolower( $file->getFilename() ) === 'database.sql' ) {
                return $file->getPathname();
            }
        }

        return '';
    }

    private static function copy_directory( $source, $destination ) {
        $source      = rtrim( wp_normalize_path( $source ), '/' );
        $destination = rtrim( wp_normalize_path( $destination ), '/' );

        if ( ! is_dir( $source ) ) {
            backup_lite_log( 'error', 'copy_directory_source_not_dir', [
                'source' => $source,
                'destination' => $destination,
            ] );
            return false;
        }

        if ( ! is_readable( $source ) ) {
            backup_lite_log( 'error', 'copy_directory_source_not_readable', [
                'source' => $source,
                'destination' => $destination,
            ] );
            return false;
        }

        if ( ! file_exists( $destination ) ) {
            if ( ! backup_lite_ensure_directory( $destination ) ) {
                backup_lite_log( 'error', 'copy_directory_dest_create_failed', [
                    'source' => $source,
                    'destination' => $destination,
                ] );
                return false;
            }
        }

        if ( ! wp_is_writable( $destination ) ) {
            backup_lite_log( 'error', 'copy_directory_dest_not_writable', [
                'source' => $source,
                'destination' => $destination,
                'dest_perms' => file_exists( $destination ) ? substr( sprintf( '%o', fileperms( $destination ) ), -4 ) : 'N/A',
            ] );
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $copied_count = 0;
        $failed_count = 0;
        $first_failure = null;

        foreach ( $iterator as $item ) {
            $target_path = $destination . substr( wp_normalize_path( $item->getPathname() ), strlen( $source ) );

            if ( $item->isDir() ) {
                if ( ! file_exists( $target_path ) ) {
                    if ( ! backup_lite_ensure_directory( $target_path ) ) {
                        $failed_count++;
                        if ( ! $first_failure ) {
                            $first_failure = [
                                'type' => 'directory',
                                'path' => $target_path,
                                'error' => 'Failed to create directory',
                            ];
                        }
                        // Continue trying other files even if directory creation fails
                        continue;
                    }
                }
            } else {
                $dir = dirname( $target_path );
                if ( ! file_exists( $dir ) ) {
                    if ( ! backup_lite_ensure_directory( $dir ) ) {
                        $failed_count++;
                        if ( ! $first_failure ) {
                            $first_failure = [
                                'type' => 'directory',
                                'path' => $dir,
                                'error' => 'Failed to create parent directory',
                            ];
                        }
                        continue;
                    }
                }

                if ( ! @copy( $item->getPathname(), $target_path ) ) {
                    $failed_count++;
                    // Log detailed error information for debugging
                    $error = error_get_last();
                    $error_details = [
                        'source' => $item->getPathname(),
                        'destination' => $target_path,
                        'error' => $error ? $error['message'] : 'Unknown error',
                        'source_readable' => is_readable( $item->getPathname() ),
                        'source_exists' => file_exists( $item->getPathname() ),
                        'dest_dir_writable' => wp_is_writable( dirname( $target_path ) ),
                        'dest_dir_exists' => file_exists( dirname( $target_path ) ),
                        'dest_dir_perms' => file_exists( dirname( $target_path ) ) ? substr( sprintf( '%o', fileperms( dirname( $target_path ) ) ), -4 ) : 'N/A',
                    ];
                    
                    if ( ! $first_failure ) {
                        $first_failure = array_merge( [ 'type' => 'file' ], $error_details );
                    }
                    
                    backup_lite_log( 'error', 'copy_file_failed', $error_details );
                    
                    // If too many files fail, abort to avoid wasting time
                    if ( $failed_count > 10 && $copied_count === 0 ) {
                        backup_lite_log( 'error', 'copy_directory_aborted_too_many_failures', [
                            'source' => $source,
                            'destination' => $destination,
                            'failed_count' => $failed_count,
                            'copied_count' => $copied_count,
                            'first_failure' => $first_failure,
                        ] );
                        return false;
                    }
                } else {
                    $copied_count++;
                }
            }
        }

        // If we copied some files but had failures, log a warning but continue
        if ( $failed_count > 0 ) {
            backup_lite_log( 'warning', 'copy_directory_partial_success', [
                'source' => $source,
                'destination' => $destination,
                'copied_count' => $copied_count,
                'failed_count' => $failed_count,
                'first_failure' => $first_failure,
            ] );
            
            // If we copied nothing, consider it a failure
            if ( $copied_count === 0 ) {
                return false;
            }
        }

        backup_lite_log( 'info', 'copy_directory_completed', [
            'source' => $source,
            'destination' => $destination,
            'copied_count' => $copied_count,
            'failed_count' => $failed_count,
        ] );

        return true;
    }

    private static function import_database_with_cli( $sql_file ) {
        $command = sprintf(
            'mysql --init-command=%s -u%s -p%s %s < %s',
            escapeshellarg( 'SET foreign_key_checks=0; SET NAMES utf8mb4;' ),
            escapeshellarg( DB_USER ),
            escapeshellarg( DB_PASSWORD ),
            escapeshellarg( DB_NAME ),
            escapeshellarg( $sql_file )
        );

        $output  = '';
        $success = Backup_Lite_Backup::run_shell_command( $command, $output );

        if ( ! $success ) {
            backup_lite_log( 'error', 'mysql command failed.', [ 'output' => $output ] );
        }

        return $success;
    }

    private static function import_database_with_php( $sql_file, $progress_cb = null ) {
        global $wpdb;

        // High-performance streaming of large backup/restore archive files.
        // - Only runs for admins (manage_options) or via authenticated restore jobs.
        // - WP_Filesystem is not suitable for this hot path.
        $handle = self::stream_open( $sql_file, 'r' );
        if ( ! $handle ) {
            backup_lite_log( 'error', 'Unable to open SQL file for reading.', [ 'path' => $sql_file ] );
            return false;
        }

        // Allow longer execution time for large backup/restore jobs when possible.
        // phpcs:ignore WordPress.PHP.NoSetTimeLimit
        if ( function_exists( 'set_time_limit' ) ) {
            // Long-running backup/restore job: attempt to raise time limit for CLI/cron.
            // @phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged
            if ( function_exists( 'set_time_limit' ) ) {
                @set_time_limit( 0 );
            }
            // @phpcs:enable Squiz.PHP.DiscouragedFunctions.Discouraged
        }
        // Adjusting PHP settings locally for backup/restore process.
        // phpcs:ignore WordPress.PHP.IniSet
        // Adjust memory limit for large backup/restore operations.
        // @phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged
        if ( function_exists( 'ini_set' ) ) {
            @ini_set( 'memory_limit', '512M' );
        }
        // @phpcs:enable Squiz.PHP.DiscouragedFunctions.Discouraged

        $query    = '';
        $success  = true;
        $line_num = 0;
        $file_size = filesize( $sql_file );
        $last_progress_report = 0;
        $progress_report_interval = max( 1, floor( $file_size / 20 ) ); // Report progress ~20 times

        self::run_database_primers();

        while ( false !== ( $line = fgets( $handle ) ) ) {
            $line_num++;
            $trimmed = trim( $line );

            if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) || 0 === strpos( $trimmed, '/*' ) ) {
                continue;
            }

            $query .= $line;

            // Report progress periodically during import
            if ( is_callable( $progress_cb ) && $file_size > 0 ) {
                $current_pos = ftell( $handle );
                $progress_percent = min( 100, floor( ( $current_pos / $file_size ) * 100 ) );
                if ( $progress_percent >= $last_progress_report + 5 ) { // Report every 5%
                    $mapped_percent = 50 + ( $progress_percent * 0.15 ); // Map to 50-65% range
                    call_user_func( $progress_cb, $mapped_percent, __( 'Importing database…', 'museder-restoreone' ) );
                    $last_progress_report = $progress_percent;
                }
            }

            if ( ';' === substr( rtrim( $line ), -1 ) ) {
                $prepared = trim( $query );
                if ( ! empty( $prepared ) ) {
                    // This method restores a SQL dump that was previously generated by this plugin.
                    // The .sql file path is resolved and validated by backup_lite_get_backup_path(),
                    // and cannot be controlled by unprivileged users.
                    // Cannot use prepare() because this is a complete SQL script with multiple statements.
                    // Restore is admin-initiated; schema/data changes are expected and required for correctness.
                    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter
                    $wpdb->flush();
                    $result = $wpdb->query( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $prepared is a complete SQL script from backup file, cannot use prepare() for multi-statement scripts
                    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, PluginCheck.Security.DirectDB.UnescapedDBParameter

                    if ( false === $result ) {
                        $error = $wpdb->last_error ?: 'unknown error';
                        backup_lite_log( 'error', 'SQL execution failed.', [
                            'line'  => $line_num,
                            'error' => $error,
                        ] );
                        self::stream_close( $handle );
                        return [
                            'success' => false,
                            'message' => __( 'Database restore encountered an error. Check logs.', 'museder-restoreone' ),
                            'code'    => 'sql_error',
                            'line'    => $line_num,
                            'error'   => $error,
                        ];
                    }
                }
                $query = '';
            }
        }

        self::stream_close( $handle );

        self::restore_database_constraints();

        return [
            'success' => $success,
            'code'    => $success ? 'database_restored' : 'database_error',
        ];
    }

    private static function run_database_primers() {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        // Restore path: required MySQL session settings (hardcoded strings), not user input.
        // Caching/higher-level APIs are not applicable here.
        // These are MySQL session settings (hardcoded strings), not user input.
        $wpdb->query( 'SET foreign_key_checks = 0' );
        $wpdb->query( "SET NAMES 'utf8mb4'" );
        $wpdb->query( "SET sql_mode = ''" );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
    }

    private static function restore_database_constraints() {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        // Restore path: required MySQL session setting (hardcoded string), not user input.
        // Caching/higher-level APIs are not applicable here.
        // This is a MySQL session setting (hardcoded string), not user input.
        $wpdb->query( 'SET foreign_key_checks = 1' );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
    }

    /**
     * Extract active_plugins value from SQL file before import.
     * This is needed because All-in-One WP Migration may have deactivated plugins during backup.
     *
     * @param string $sql_file Path to SQL file.
     * @return array Array of active plugin file paths, or empty array if not found.
     */
    private static function extract_active_plugins_from_sql( $sql_file ) {
        if ( ! file_exists( $sql_file ) || ! is_readable( $sql_file ) ) {
            return [];
        }

        $active_plugins = [];
        $handle = self::stream_open( $sql_file, 'rb' );
        if ( ! $handle ) {
            return [];
        }

        // Search for active_plugins in the SQL file
        // Pattern: INSERT INTO `SERVMASK_PREFIX_options` VALUES (...,'active_plugins','a:XX:{...}',...)
        // We need to handle nested serialized arrays, so we'll use a more robust approach
        $buffer = '';
        $chunk_size = 1048576; // 1MB chunks
        $found = false;

        while ( ! feof( $handle ) && ! $found ) {
            // Only reads plugin-generated backup files, path is validated and sanitized.
            $chunk = self::stream_read( $handle, $chunk_size );
            if ( false === $chunk ) {
                break;
            }

            $buffer .= $chunk;

            // Look for active_plugins pattern
            // Match: 'active_plugins' followed by a quoted value
            // We need to handle the full VALUES format: VALUES (id,'active_plugins','serialized_value','yes')
            // The serialized value can contain quotes, so we need to parse carefully
            if ( preg_match( "/'active_plugins'[,\s]+'((?:[^'\\\\]|\\\\.|'')*)'/s", $buffer, $matches ) ) {
                $serialized_value = str_replace( "''", "'", $matches[1] ); // Unescape SQL quotes
                
                // Try to unserialize
                $unserialized = @unserialize( $serialized_value );
                if ( is_array( $unserialized ) ) {
                    $active_plugins = $unserialized;
                    $found = true;
                    break;
                }
            }

            // Keep last 2MB in buffer to catch cross-chunk matches
            if ( strlen( $buffer ) > 2097152 ) {
                $buffer = substr( $buffer, -1048576 );
            }
        }

        self::stream_close( $handle );

        return $active_plugins;
    }

    private static function run_search_replace( $pairs ) {
        global $wpdb;

        // Restore search/replace is intentionally limited to this site's $wpdb->prefix tables.
        // Table identifiers cannot be prepared; we validate identifiers via live whitelist.
        $tables = backup_lite_get_wp_table_whitelist();
        if ( empty( $tables ) ) {
            return;
        }

        $text_types = [ 'tinytext', 'text', 'mediumtext', 'longtext', 'varchar', 'char' ];

        foreach ( $tables as $table ) {
            $safe_table = backup_lite_validate_wp_table_name( $table );
            if ( ! $safe_table ) {
                continue;
            }
            // Introspection required to find text-like columns.
            // Table identifier cannot be prepared; validated via backup_lite_validate_wp_table_name().
            $cache_key = 'backup_lite_sr_columns_' . md5( $safe_table );
            $columns   = wp_cache_get( $cache_key, 'backup_lite' );

            if ( false === $columns ) {
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
                $columns = $wpdb->get_results(
                    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifier cannot be passed as a prepared value; validated via backup_lite_validate_wp_table_name() (live prefix whitelist).
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier cannot be prepared; validated via backup_lite_validate_wp_table_name() (live prefix whitelist).
                    "SHOW COLUMNS FROM `{$safe_table}`",
                    ARRAY_A
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

                // Cache schema introspection; safe to reuse across requests.
                wp_cache_set( $cache_key, $columns, 'backup_lite', HOUR_IN_SECONDS );
            }

            if ( empty( $columns ) || ! is_array( $columns ) ) {
                continue;
            }
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

            // Full table scan is required to update serialized/text fields reliably.
            // Table identifier cannot be prepared; validated via backup_lite_validate_wp_table_name().
            // No object caching: restore/migration data must reflect current DB state and may be large.
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $rows = $wpdb->get_results(
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifier cannot be passed as a prepared value; validated via backup_lite_validate_wp_table_name() (live prefix whitelist).
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier cannot be prepared; validated via backup_lite_validate_wp_table_name() (live prefix whitelist).
                "SELECT * FROM `{$safe_table}`",
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
                    // $safe_table is validated via backup_lite_validate_wp_table_name().
                    // This is a required restore/migration write; caching does not apply to writes.
                    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->update( $safe_table, $update, [ 'id' => isset( $row['id'] ) ? $row['id'] : $row[ array_key_first( $row ) ] ] );
                    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                }
            }
        }
    }

    private static function serialized_replace_recursive( $pairs, $value ) {
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

    private static function is_suspicious_zip_entry( $entry ) {
        $entry = str_replace( '\\', '/', (string) $entry );

        if ( preg_match( '#^(?:/|[A-Za-z]:/)#', $entry ) ) {
            return true;
        }

        if ( strpos( $entry, '..' ) !== false ) {
            return true;
        }

        return false;
    }

    /**
     * Recursively search for a directory by name within the extract directory.
     *
     * @param string $base_dir   Root directory to search within.
     * @param string $target     Directory name to locate.
     * @param int    $max_depth  Maximum depth to scan.
     * @return string Path if found, empty string otherwise.
     */
    private static function find_directory_by_name( $base_dir, $target, $max_depth = 4 ) {
        $base_dir = wp_normalize_path( $base_dir );
        if ( ! is_dir( $base_dir ) ) {
            return '';
        }

        $queue = [
            [
                'path'  => $base_dir,
                'depth' => 0,
            ],
        ];

        while ( ! empty( $queue ) ) {
            $current = array_shift( $queue );
            $current_path  = $current['path'];
            $current_depth = (int) $current['depth'];

            if ( $current_depth > $max_depth ) {
                continue;
            }

            $children = glob( trailingslashit( $current_path ) . '*', GLOB_ONLYDIR );
            if ( empty( $children ) ) {
                continue;
            }

            foreach ( $children as $child ) {
                $child_path = wp_normalize_path( $child );
                if ( basename( $child_path ) === $target ) {
                    return $child_path;
                }

                if ( $current_depth + 1 <= $max_depth ) {
                    $queue[] = [
                        'path'  => $child_path,
                        'depth' => $current_depth + 1,
                    ];
                }
            }
        }

        return '';
    }

    /**
     * List top-level directories/files inside the extract folder for logging.
     *
     * @param string $base_dir Extract directory.
     * @return array
     */
    private static function summarize_extract_contents( $base_dir ) {
        $base_dir = wp_normalize_path( $base_dir );
        if ( ! is_dir( $base_dir ) ) {
            return [];
        }

        $entries = glob( trailingslashit( $base_dir ) . '*', GLOB_NOSORT );
        if ( empty( $entries ) ) {
            return [];
        }

        $summary = [];
        foreach ( array_slice( $entries, 0, 15 ) as $entry ) {
            $type = is_dir( $entry ) ? 'dir' : 'file';
            $summary[] = $type . ':' . basename( $entry );
        }

        return $summary;
    }

    /**
     * Enter safe mode after restore import.
     * Temporarily disables all non-essential plugins to prevent white screen issues.
     *
     * @return bool True on success, false on failure.
     */
    public static function enter_safe_mode_after_import() {
        // Get current active plugins
        $active_plugins = get_option( 'active_plugins', [] );
        
        if ( ! is_array( $active_plugins ) ) {
            $active_plugins = [];
        }

        $plugin_count = count( $active_plugins );
        
        // Store the previous active plugins list
        update_option( 'backup_lite_prev_active_plugins', $active_plugins, false );
        
        // Keep only essential plugins (this plugin itself)
        // Find this plugin's basename
        $plugin_file = plugin_basename( dirname( dirname( __FILE__ ) ) . '/museder-restoreone.php' );
        $essential_plugins = [];
        
        // Always keep this plugin active
        if ( in_array( $plugin_file, $active_plugins, true ) ) {
            $essential_plugins[] = $plugin_file;
        }
        
        // Set active_plugins to only essential plugins
        update_option( 'active_plugins', $essential_plugins, false );
        
        // Set safe mode flag
        update_option( 'backup_lite_safe_mode', '1', false );
        
        // Clear plugin cache
        wp_cache_delete( 'plugins', 'plugins' );
        
        backup_lite_log( 'info', 'Safe mode entered after restore.', [
            'previous_plugins_count' => $plugin_count,
            'essential_plugins_count' => count( $essential_plugins ),
            'plugin_file' => $plugin_file,
        ] );
        
        return true;
    }

    /**
     * Exit safe mode and restore previous plugin activation status.
     *
     * @return bool True on success, false on failure.
     */
    public static function exit_safe_mode() {
        // Check if safe mode is active
        $safe_mode = get_option( 'backup_lite_safe_mode', '' );
        
        if ( '1' !== $safe_mode ) {
            backup_lite_log( 'info', 'Safe mode exit called but safe mode is not active.', [] );
            return false;
        }
        
        // Get previous active plugins
        $prev_plugins = get_option( 'backup_lite_prev_active_plugins', [] );
        
        if ( ! is_array( $prev_plugins ) ) {
            $prev_plugins = [];
        }
        
        // Validate that plugins still exist before restoring
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $all_plugins = get_plugins();
        $valid_plugins = [];
        $missing_plugins = [];
        
        foreach ( $prev_plugins as $plugin_file ) {
            $plugin_path = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . $plugin_file );
            
            if ( file_exists( $plugin_path ) && isset( $all_plugins[ $plugin_file ] ) ) {
                $valid_plugins[] = $plugin_file;
            } else {
                $missing_plugins[] = $plugin_file;
            }
        }
        
        // Restore active plugins
        update_option( 'active_plugins', $valid_plugins, false );
        
        // Clear plugin cache
        wp_cache_delete( 'plugins', 'plugins' );
        
        // Delete safe mode options
        delete_option( 'backup_lite_safe_mode' );
        delete_option( 'backup_lite_prev_active_plugins' );
        
        backup_lite_log( 'info', 'Safe mode exited and plugins restored.', [
            'restored_plugins_count' => count( $valid_plugins ),
            'missing_plugins_count' => count( $missing_plugins ),
            'missing_plugins' => $missing_plugins,
        ] );
        
        return true;
    }
}
