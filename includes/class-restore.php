<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class Museder_Restoreone_Restore {

    private static $pclzip_destination = '';
    private static $last_mysql_cli_output = '';

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
        $archive_path = museder_restoreone_get_backup_path( $archive_file );
        
        if ( ! $archive_path ) {
            museder_restoreone_log( 'Restore failed: archive path could not be resolved.', array( 'archive_file' => $archive_file ) );
            
            return array(
                'success' => false,
                'message' => __( 'Backup file not found or unreadable.', 'museder-restoreone' ),
                'code'    => 'archive_not_readable',
            );
        }
        
        if ( ! file_exists( $archive_path ) || ! is_readable( $archive_path ) ) {
            museder_restoreone_log( 'Restore failed: archive not readable.', array( 'archive_path' => $archive_path ) );
            
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

        $log = museder_restoreone_log( 'info', 'Site restore started.', [
            'archive' => $archive_file,
            'method'  => 'auto',
        ] );

        if ( is_callable( $progress_cb ) ) {
            call_user_func( $progress_cb, 5, __( 'Preparing restore environment…', 'museder-restoreone' ) );
        }

        $temp_dir = museder_restoreone_create_temp_dir( 'restore' );

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
            
            museder_restoreone_log( 'warning', 'extract_archive_exception', [
                'archive' => $archive_file,
                'exception' => $extract_exception->getMessage(),
                'extracted_files' => $extracted_count,
            ] );
            
            // If files were extracted, continue with restore
            // PclZip may throw exceptions even when extraction succeeds
            if ( $extracted_count > 0 ) {
                museder_restoreone_log( 'info', 'Extraction completed despite exception, continuing with restore.', [
                    'extracted_files' => $extracted_count,
                ] );
                $extract_result = [ 'success' => true, 'had_exception' => true ];
            } else {
                // No files extracted, return failure
                museder_restoreone_log( 'error', 'Extraction failed with exception and no files extracted.', [
                    'archive' => $archive_file,
                    'exception' => $extract_exception->getMessage(),
                ] );
                museder_restoreone_delete_directory( $temp_dir );
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
            
            // Provide more specific error messages for unsupported formats
            $error_message = __( 'Unable to extract backup archive. Check logs for details.', 'museder-restoreone' );
            if ( 'wpress' === $ext ) {
                $error_message = __( 'This build does not support .wpress backups. Please convert the backup to ZIP format first.', 'museder-restoreone' );
            }
            
            museder_restoreone_log( 'error', 'Failed to extract archive for restore.', [
                'archive'        => $archive_file,
                'zip_error_code' => isset( $extract_result['zip_error_code'] ) ? $extract_result['zip_error_code'] : null,
                'error_code'     => $error_code,
                'extension'      => $ext,
            ] );
            museder_restoreone_delete_directory( $temp_dir );

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
            museder_restoreone_log( 'error', 'database file missing in archive.', [ 'archive' => $archive_file ] );
            museder_restoreone_delete_directory( $temp_dir );

            return [
                'success' => false,
                'message' => __( 'Database file not found in backup archive.', 'museder-restoreone' ),
                'log'     => $log,
                'code'    => 'db_file_not_found',
            ];
        }

        if ( is_callable( $progress_cb ) ) {
            call_user_func( $progress_cb, 40, __( 'Preparing database import…', 'museder-restoreone' ) );
        }

        $db_result = self::import_database( $sql_path, $progress_cb );
        if ( empty( $db_result['success'] ) ) {
            museder_restoreone_delete_directory( $temp_dir );
            return $db_result;
        }

        // Store active_plugins from database file for later restoration
        if ( ! empty( $db_result['active_plugins'] ) && is_array( $db_result['active_plugins'] ) ) {
            // Store in a temporary option that will be used after restore
            update_option( 'museder_restoreone_restored_active_plugins', $db_result['active_plugins'], false );
            museder_restoreone_log( 'info', 'Stored active_plugins from backup for restoration.', [
                'count' => count( $db_result['active_plugins'] ),
            ] );
        }

        if ( is_callable( $progress_cb ) ) {
            call_user_func( $progress_cb, 70, __( 'Restoring files from backup…', 'museder-restoreone' ) );
        }

        $files_result = self::restore_files_from_extract( $temp_dir );
        if ( empty( $files_result['success'] ) ) {
            museder_restoreone_delete_directory( $temp_dir );
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

        museder_restoreone_delete_directory( $temp_dir );

        museder_restoreone_log( 'info', 'Site restore completed.', [ 'archive' => $archive_file ] );

        return [
            'success' => true,
            'message' => __( 'Restore completed successfully.', 'museder-restoreone' ),
            'log'     => $log,
        ];
    }

    /**
     * Import the WordPress database from a backup database file.
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
            $log               = museder_restoreone_log( 'error', 'Database file not readable for restore.', [ 'path' => $sql_file ] );
            $result['message'] = __( 'Database backup file not found or unreadable.', 'museder-restoreone' );
            $result['log']     = $log;
            $result['code']    = 'db_file_missing';
            return $result;
        }

        $ext = strtolower( pathinfo( $sql_file, PATHINFO_EXTENSION ) );

        if ( is_callable( $progress_cb ) ) {
            call_user_func( $progress_cb, 45, __( 'Preparing database import…', 'museder-restoreone' ) );
        }

        if ( 'ndjson' === $ext ) {
            return self::import_database_from_ndjson( $sql_file, $progress_cb );
        }

        // Legacy SQL backups are manual-only in this build.
        if ( 'sql' === $ext ) {
            $log = museder_restoreone_log( 'info', 'Database restore requires manual import (SQL).', [ 'path' => $sql_file ] );
        if ( is_callable( $progress_cb ) ) {
                call_user_func( $progress_cb, 50, __( 'Manual database import is required for SQL backups.', 'museder-restoreone' ) );
            }
            $result['message'] = __( 'This backup contains a database.sql file. Automatic database import is not available in this build. Please import the database manually (for example via phpMyAdmin) using the database.sql file from the backup archive, then continue with the file restore.', 'museder-restoreone' );
                $result['log']     = $log;
            $result['code']    = 'manual_db_required';
                return $result;
        }

        $log               = museder_restoreone_log( 'error', 'Unsupported database backup format.', [ 'path' => $sql_file ] );
        $result['message'] = __( 'Unsupported database backup format.', 'museder-restoreone' );
        $result['log']     = $log;
        $result['code']    = 'db_format_unsupported';
        return $result;
    }

    /**
     * Import database from NDJSON backup file generated by this plugin.
     *
     * Each line is a JSON object:
     * - {type:"meta", ...}
     * - {type:"schema", table:"wp_posts", create:"CREATE TABLE ..."}
     * - {type:"row", table:"wp_posts", row:{...}}
     *
     * @param string        $path
     * @param callable|null $progress_cb
     * @return array{success:bool,message:string,code?:string,log?:string,active_plugins?:array}
     */
    private static function import_database_from_ndjson( $path, $progress_cb = null ) {
        global $wpdb;

        $path = wp_normalize_path( (string) $path );
        $result = [
            'success' => false,
            'message' => __( 'Database restore failed.', 'museder-restoreone' ),
            'code'    => 'db_import_failed',
        ];

        if ( '' === $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
            $result['code']    = 'db_file_missing';
            $result['message'] = __( 'Database backup file not found or unreadable.', 'museder-restoreone' );
            $result['log']     = museder_restoreone_log( 'error', 'NDJSON DB file not readable.', [ 'path' => $path ] );
            return $result;
        }

        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fgets, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        $handle = fopen( $path, 'rb' );
        if ( ! $handle ) {
            $result['code']    = 'db_file_open_failed';
            $result['message'] = __( 'Unable to open database backup file.', 'museder-restoreone' );
            $result['log']     = museder_restoreone_log( 'error', 'NDJSON DB file open failed.', [ 'path' => $path ] );
            return $result;
        }

        // Preserve current admin session state before DB tables are replaced.
        // After import, wp_usermeta is rebuilt from backup data, destroying the
        // active admin's session tokens and causing forced logout.
        $preserved_session = self::preserve_session_before_import();

        // Ensure dbDelta exists for schema creation (core upgrade API), via centralized path resolution.
        if ( ! function_exists( 'dbDelta' ) ) {
            $upgrade = function_exists( 'museder_restoreone_get_core_admin_include_path' ) ? museder_restoreone_get_core_admin_include_path( 'upgrade.php' ) : '';
            if ( '' !== $upgrade ) {
                // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- vetted core path from helper.
                require_once $upgrade;
            }
        }

        $file_size = (int) filesize( $path );
        $last_progress = -1;
        $line_num = 0;
        $active_plugins = [];
        $options_table_safe = '';
        $source_prefix = '';
        $target_prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
        $schemas_imported = 0;
        $rows_imported    = 0;
        $decoded_lines    = 0;
        $saw_first_payload_line = false;

        // Best-effort compute options table name once (target site).
        if ( isset( $wpdb->options ) ) {
            $options_table_safe = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $wpdb->options );
        }

        $rewrite_table = static function ( $table ) use ( &$source_prefix, $target_prefix ) {
            $table = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $table );
            if ( '' === $table ) {
                return '';
            }
            if ( '' !== $source_prefix && '' !== $target_prefix && 0 === strpos( $table, $source_prefix ) ) {
                return $target_prefix . substr( $table, strlen( $source_prefix ) );
            }
            return $table;
        };

        self::run_database_primers();

        try {
            while ( false !== ( $line = fgets( $handle ) ) ) {
                $line_num++;
                $line = trim( (string) $line );
                if ( '' === $line ) {
                    continue;
                }

                if ( ! $saw_first_payload_line ) {
                    $saw_first_payload_line = true;
                    if ( '{' !== substr( $line, 0, 1 ) ) {
                        throw new RuntimeException( 'db_format_invalid' );
                    }
                }

                $obj = json_decode( $line, true );
                if ( ! is_array( $obj ) ) {
                    continue;
                }
                $decoded_lines++;

                $type = isset( $obj['type'] ) ? (string) $obj['type'] : '';
                if ( 'meta' === $type ) {
                    if ( isset( $obj['table_prefix'] ) ) {
                        $maybe = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $obj['table_prefix'] );
                        if ( '' !== $maybe ) {
                            $source_prefix = $maybe;
                        }
                    }
                    continue;
                }

                if ( 'schema' === $type ) {
                    $raw_table = isset( $obj['table'] ) ? preg_replace( '/[^A-Za-z0-9_]/', '', (string) $obj['table'] ) : '';
                    $table = $rewrite_table( $raw_table );
                    $create = isset( $obj['create'] ) ? (string) $obj['create'] : '';
                    if ( '' === $table || '' === $create ) {
                        continue;
                    }

                    // Align with SQL-parse path: only touch tables allowed for this site (prefix whitelist).
                    if ( ! self::is_restore_sql_table_allowed( $table ) ) {
                        museder_restoreone_log( 'warning', 'NDJSON schema import skipped: table not allowed by prefix policy.', [
                            'table' => $table,
                            'raw'   => $raw_table,
                        ] );
                        continue;
                    }

                    // Rewrite CREATE TABLE statement to match target prefix (best-effort).
                    if ( '' !== $raw_table && '' !== $table && $raw_table !== $table ) {
                        $create = str_replace( '`' . $raw_table . '`', '`' . $table . '`', $create );
                    }

                    // Reset table before data import.
                    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
                    $wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . '`' ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifier is strict-sanitized + esc_sql()
                    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

                    if ( ! function_exists( 'dbDelta' ) ) {
                        throw new RuntimeException( esc_html__( 'Database schema import is unavailable on this host.', 'museder-restoreone' ) );
                    }

                    dbDelta( $create . ';' );
                    $schemas_imported++;

                    continue;
                }

                if ( 'row' === $type ) {
                    $raw_table = isset( $obj['table'] ) ? preg_replace( '/[^A-Za-z0-9_]/', '', (string) $obj['table'] ) : '';
                    $table = $rewrite_table( $raw_table );
                    $row   = isset( $obj['row'] ) && is_array( $obj['row'] ) ? $obj['row'] : null;
                    if ( '' === $table || ! is_array( $row ) ) {
                        continue;
                    }

                    if ( ! self::is_restore_sql_table_allowed( $table ) ) {
                        museder_restoreone_log( 'warning', 'NDJSON row import skipped: table not allowed by prefix policy.', [
                            'table' => $table,
                            'raw'   => $raw_table,
                        ] );
                        continue;
                    }

                    // Restore: write rows into the target table (direct DB required for bulk import; no caching applicable).
                    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->replace( $table, $row );
                    // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $rows_imported++;

                    // Capture active_plugins for later restoration (best-effort).
                    if ( '' !== $options_table_safe && $table === $options_table_safe ) {
                        $opt_name = isset( $row['option_name'] ) ? (string) $row['option_name'] : '';
                        if ( 'active_plugins' === $opt_name && isset( $row['option_value'] ) ) {
                            $maybe = maybe_unserialize( (string) $row['option_value'] );
                            if ( is_array( $maybe ) ) {
                                $active_plugins = array_values( array_filter( array_map( 'strval', $maybe ) ) );
                            }
                        }
                    }
                }

                if ( is_callable( $progress_cb ) && $file_size > 0 ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_ftell -- progress tracking on local file handle
                    $pos = ftell( $handle );
                    if ( is_int( $pos ) && $pos > 0 ) {
                        $pct = (int) floor( min( 99, ( $pos / $file_size ) * 100 ) );
                        if ( $pct !== $last_progress && ( $pct % 5 === 0 ) ) {
                            $last_progress = $pct;
                            call_user_func( $progress_cb, 50 + ( $pct * 0.15 ), __( 'Importing database…', 'museder-restoreone' ) );
                        }
                    }
                }
            }
        } catch ( Throwable $e ) {
            if ( 'db_format_invalid' === $e->getMessage() ) {
                $result['code']    = 'db_format_invalid';
                $result['message'] = __( 'Database backup file is not valid NDJSON. Please re-create the backup with the updated plugin.', 'museder-restoreone' );
                $result['log']     = museder_restoreone_log( 'error', 'NDJSON DB format invalid (first line not JSON).', [ 'line' => $line_num, 'path' => $path ] );
                return $result;
            }
            $result['message'] = __( 'Database restore encountered an error. Check logs.', 'museder-restoreone' );
            $result['log']     = museder_restoreone_log( 'error', 'NDJSON DB import failed.', [ 'line' => $line_num, 'error' => $e->getMessage() ] );
            // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fgets, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            return $result;
        }

        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fgets, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        self::restore_database_constraints();

        // Re-inject the preserved session so the admin stays logged in.
        self::restore_session_after_import( $preserved_session );

        if ( $decoded_lines <= 0 || $schemas_imported <= 0 ) {
            $result['code']    = 'db_format_invalid';
            $result['message'] = __( 'Database backup file is invalid or incomplete. Please re-create the backup with the updated plugin.', 'museder-restoreone' );
            $result['log']     = museder_restoreone_log( 'error', 'NDJSON DB import produced no schema/data.', [
                'decoded_lines' => $decoded_lines,
                'schemas'       => $schemas_imported,
                'rows'          => $rows_imported,
                'path'          => $path,
            ] );
            return $result;
        }

        $result['success'] = true;
        $result['message'] = __( 'Database restore completed successfully.', 'museder-restoreone' );
        $result['code']    = 'database_restored';
        if ( ! empty( $active_plugins ) ) {
            $result['active_plugins'] = $active_plugins;
        }
        return $result;
    }

    /**
     * Time-sliced database import with resume offset + buffered partial query.
     *
     * @param string $sql_file
     * @param int    $offset          Byte offset in file (updated by reference)
     * @param string $query_buffer    Partial query buffer (updated by reference)
     * @param int    $timeout_seconds
     *
     * @return array{success:bool,completed:bool}
     */
    public static function import_database_sliced( $sql_file, &$offset, &$query_buffer, $timeout_seconds = 10, $rewrite_from_prefix = '', $rewrite_to_prefix = '' ) {
        throw new RuntimeException( esc_html__( 'Automatic database import is unavailable for SQL backups in this build.', 'museder-restoreone' ) );
    }

    /**
     * Rewrite multisite blog table prefix in SQL statements, best-effort.
     *
     * @param string $prepared
     * @param string $rewrite_from_prefix
     * @param string $rewrite_to_prefix
     * @return string
     */
    private static function maybe_rewrite_sql_prefix( $prepared, $rewrite_from_prefix, $rewrite_to_prefix ) {
        return (string) $prepared;
    }

    /**
     * Execute a single SQL statement during import.
     *
     * For very large multi-values INSERT statements, this will split into smaller batches to avoid
     * long-running single queries that can be killed by timeouts on shared hosting.
     *
     * @param string $prepared
     * @param array  $split_info Updated by reference.
     * @param int    $timeout_seconds
     * @param float  $start
     * @return void
     */
    private static function exec_import_sql_statement( $prepared, array &$split_info, $timeout_seconds, $start ) {
        // WP.org compliance: removed (no PHP execution of SQL dump statements).
        throw new RuntimeException( esc_html__( 'Automatic database import requires MySQL CLI on this host.', 'museder-restoreone' ) );
    }

    /**
     * Classify an SQL statement for restore import.
     *
     * @param string $sql Full SQL statement (single statement ending with ';').
     * @return 'allow'|'skip'|'block'
     */
    private static function classify_restore_sql_statement_risk( $sql ) {
        $sql  = (string) $sql;
        $head = ltrim( $sql );

        // Normalize leading optimizer/compat comments (common in dumps).
        // Example: /*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
        if ( 0 === strpos( $head, '/*!' ) ) {
            $head = preg_replace( '/^\\/\\*!\\d+\\s*/', '', $head );
            $head = preg_replace( '/\\*\\/\\s*;?\\s*$/', '', (string) $head );
            $head = ltrim( (string) $head );
        }

        // High-risk patterns: should never appear in a WordPress backup restore.
        if ( preg_match( '/\b(INTO\s+(OUTFILE|DUMPFILE)|LOAD\s+DATA|LOAD_FILE\s*\(|INFILE)\b/i', $head ) ) {
            return 'block';
        }
        if ( preg_match( '/^\s*(GRANT|REVOKE|CREATE\s+USER|DROP\s+USER|ALTER\s+USER)\b/i', $head ) ) {
            return 'block';
        }
        if ( preg_match( '/^\s*SET\s+GLOBAL\b/i', $head ) ) {
            return 'block';
        }
        if ( preg_match( '/^\s*SET\s+PASSWORD\b/i', $head ) ) {
            return 'block';
        }

        // Unsupported / out-of-scope for WP restore: skip (best-effort compatibility).
        if ( preg_match( '/^\s*USE\b/i', $head ) ) {
            return 'skip';
        }
        if ( preg_match( '/^\s*(CREATE|DROP)\s+DATABASE\b/i', $head ) ) {
            return 'skip';
        }
        if ( preg_match( '/^\s*(CREATE|DROP)\s+(TRIGGER|EVENT|PROCEDURE|FUNCTION)\b/i', $head ) ) {
            return 'skip';
        }

        // Allow common dump statements used by WordPress backups, but only for expected table identifiers.
        if ( preg_match( '/^\s*(INSERT|REPLACE)\s+INTO\b/i', $head ) ) {
            $table = self::extract_sql_table_identifier( $head, 'into' );
            return self::is_restore_sql_table_allowed( $table ) ? 'allow' : 'block';
        }
        if ( preg_match( '/^\s*UPDATE\b/i', $head ) ) {
            $table = self::extract_sql_table_identifier( $head, 'update' );
            return self::is_restore_sql_table_allowed( $table ) ? 'allow' : 'block';
        }
        if ( preg_match( '/^\s*DELETE\s+FROM\b/i', $head ) ) {
            $table = self::extract_sql_table_identifier( $head, 'delete' );
            return self::is_restore_sql_table_allowed( $table ) ? 'allow' : 'block';
        }
        if ( preg_match( '/^\s*TRUNCATE\s+TABLE\b/i', $head ) ) {
            $table = self::extract_sql_table_identifier( $head, 'truncate' );
            return self::is_restore_sql_table_allowed( $table ) ? 'allow' : 'block';
        }
        if ( preg_match( '/^\s*CREATE\s+TABLE\b/i', $head ) ) {
            $table = self::extract_sql_table_identifier( $head, 'create_table' );
            return self::is_restore_sql_table_allowed( $table ) ? 'allow' : 'block';
        }
        if ( preg_match( '/^\s*ALTER\s+TABLE\b/i', $head ) ) {
            $table = self::extract_sql_table_identifier( $head, 'alter_table' );
            return self::is_restore_sql_table_allowed( $table ) ? 'allow' : 'block';
        }
        if ( preg_match( '/^\s*DROP\s+TABLE\b/i', $head ) ) {
            // DROP TABLE may include multiple tables; verify all listed tables are allowed.
            $tables = self::extract_sql_table_list_for_drop( $head );
            if ( empty( $tables ) ) {
                return 'block';
            }
            foreach ( $tables as $t ) {
                if ( ! self::is_restore_sql_table_allowed( $t ) ) {
                    return 'block';
                }
            }
            return 'allow';
        }
        if ( preg_match( '/^\s*SET\b/i', $head ) ) {
            // Session-scoped SET statements are allowed (GLOBAL is blocked above).
            return 'allow';
        }

        // Default: skip unknown statements for safety.
        return 'skip';
    }

    /**
     * Extract the primary table identifier from a SQL statement head.
     *
     * @param string $sql_head SQL head (trimmed, may include comments already stripped).
     * @param string $mode into|update|delete|truncate|create_table|alter_table
     * @return string Table name (without db qualifier/backticks) or empty string.
     */
    private static function extract_sql_table_identifier( $sql_head, $mode ) {
        $sql_head = (string) $sql_head;
        $mode     = (string) $mode;

        $pattern = '';
        switch ( $mode ) {
            case 'into':
                $pattern = '/^\s*(?:INSERT|REPLACE)\s+INTO\s+(?:`?[A-Za-z0-9_]+`?\.)?`?([A-Za-z0-9_]+)`?/i';
                break;
            case 'update':
                $pattern = '/^\s*UPDATE\s+(?:`?[A-Za-z0-9_]+`?\.)?`?([A-Za-z0-9_]+)`?/i';
                break;
            case 'delete':
                $pattern = '/^\s*DELETE\s+FROM\s+(?:`?[A-Za-z0-9_]+`?\.)?`?([A-Za-z0-9_]+)`?/i';
                break;
            case 'truncate':
                $pattern = '/^\s*TRUNCATE\s+TABLE\s+(?:`?[A-Za-z0-9_]+`?\.)?`?([A-Za-z0-9_]+)`?/i';
                break;
            case 'create_table':
                $pattern = '/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`?[A-Za-z0-9_]+`?\.)?`?([A-Za-z0-9_]+)`?/i';
                break;
            case 'alter_table':
                $pattern = '/^\s*ALTER\s+TABLE\s+(?:`?[A-Za-z0-9_]+`?\.)?`?([A-Za-z0-9_]+)`?/i';
                break;
        }

        if ( '' === $pattern ) {
            return '';
        }

        if ( preg_match( $pattern, $sql_head, $m ) ) {
            return isset( $m[1] ) ? (string) $m[1] : '';
        }
        return '';
    }

    /**
     * Extract table list from a DROP TABLE statement.
     *
     * @param string $sql_head
     * @return array<int,string> Table names (without db qualifier/backticks).
     */
    private static function extract_sql_table_list_for_drop( $sql_head ) {
        $sql_head = (string) $sql_head;

        $after = preg_replace( '/^\s*DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?/i', '', $sql_head );
        $after = preg_replace( '/;.*$/', '', (string) $after );
        $after = trim( (string) $after );
        if ( '' === $after ) {
            return [];
        }

        $parts  = array_map( 'trim', explode( ',', $after ) );
        $tables = [];
        foreach ( $parts as $p ) {
            if ( '' === $p ) {
                continue;
            }
            // Remove potential db qualifier, then strip backticks.
            $p = preg_replace( '/^`?[A-Za-z0-9_]+`?\./', '', $p );
            $p = trim( (string) $p, " \t\n\r\0\x0B`" );
            if ( '' !== $p ) {
                $tables[] = $p;
            }
        }
        return $tables;
    }

    /**
     * Allow only table identifiers that look like WordPress tables (current prefix/base_prefix),
     * plus the SERVMASK placeholder used by certain exports before normalization.
     *
     * @param string $table
     * @return bool
     */
    private static function is_restore_sql_table_allowed( $table ) {
        global $wpdb;

        $table = (string) $table;
        if ( '' === $table ) {
            return false;
        }

        // Strict identifier token.
        if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
            return false;
        }

        $allowed_prefixes = [];
        if ( isset( $wpdb->prefix ) && '' !== (string) $wpdb->prefix ) {
            $allowed_prefixes[] = (string) $wpdb->prefix;
        }
        if ( isset( $wpdb->base_prefix ) && '' !== (string) $wpdb->base_prefix ) {
            $allowed_prefixes[] = (string) $wpdb->base_prefix;
        }
        $allowed_prefixes[] = 'SERVMASK_PREFIX_';

        foreach ( array_unique( $allowed_prefixes ) as $prefix ) {
            if ( '' !== $prefix && 0 === strpos( $table, $prefix ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse a multi-values INSERT statement into prefix + tuple list.
     *
     * Returns false if the statement doesn't match expected format.
     *
     * @param string $sql
     * @return array{prefix:string,tuples:array<int,string>}|false
     */
    private static function parse_multi_values_insert( $sql ) {
        $sql = (string) $sql;
        $pos_values = stripos( $sql, 'VALUES' );
        if ( false === $pos_values ) {
            return false;
        }
        $pos_paren = strpos( $sql, '(', $pos_values );
        if ( false === $pos_paren ) {
            return false;
        }

        $prefix = substr( $sql, 0, $pos_paren );
        $len = strlen( $sql );
        $tuples = [];
        $in_string = false;
        $escape = false;
        $depth = 0;
        $start = -1;

        for ( $i = $pos_paren; $i < $len; $i++ ) {
            $ch = $sql[ $i ];

            if ( $in_string ) {
                if ( $escape ) {
                    $escape = false;
                } elseif ( '\\' === $ch ) {
                    $escape = true;
                } elseif ( '\'' === $ch ) {
                    // Handle doubled single-quote escape ('') used by MySQL.
                    if ( ( $i + 1 ) < $len && '\'' === $sql[ $i + 1 ] ) {
                        $i++;
                    } else {
                        $in_string = false;
                    }
                }
                continue;
            }

            if ( '\'' === $ch ) {
                $in_string = true;
                continue;
            }

            if ( '(' === $ch ) {
                if ( 0 === $depth ) {
                    $start = $i;
                }
                $depth++;
                continue;
            }

            if ( ')' === $ch ) {
                $depth--;
                if ( 0 === $depth && $start >= 0 ) {
                    $tuples[] = substr( $sql, $start, ( $i - $start + 1 ) );
                    $start = -1;
                }
                continue;
            }
        }

        if ( empty( $tuples ) ) {
            return false;
        }

        return [
            'prefix' => $prefix,
            'tuples' => $tuples,
        ];
    }

    /**
     * Apply best-effort MySQL session tuning to speed up large SQL imports.
     *
     * Note: This modifies ONLY the current DB connection session and is restored after the slice completes.
     * This keeps behaviour compliant with WordPress expectations and avoids leaking settings into other queries.
     *
     * @return array{applied:bool,orig_fk:int|null,orig_uq:int|null,orig_charset:string|null,orig_collation:string|null}
     */
    private static function apply_import_session_tuning() {
        global $wpdb;

        $state = [
            'applied'        => false,
            'orig_fk'        => null,
            'orig_uq'        => null,
            'orig_charset'   => null,
            'orig_collation' => null,
        ];

        // Read original session values (best-effort). These are server-provided values, not user input.
        try {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $row = $wpdb->get_row(
                'SELECT @@FOREIGN_KEY_CHECKS AS fk, @@UNIQUE_CHECKS AS uq, @@character_set_client AS csc, @@collation_connection AS coll',
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

            if ( is_array( $row ) ) {
                $state['orig_fk'] = isset( $row['fk'] ) ? (int) $row['fk'] : null;
                $state['orig_uq'] = isset( $row['uq'] ) ? (int) $row['uq'] : null;
                $state['orig_charset'] = isset( $row['csc'] ) ? (string) $row['csc'] : null;
                $state['orig_collation'] = isset( $row['coll'] ) ? (string) $row['coll'] : null;
            }
        } catch ( Exception $e ) {
            // Ignore: best-effort only.
        }

        // Apply tuning (A option): disable FK/unique checks and ensure UTF8MB4 connection charset.
        try {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
            $wpdb->query( 'SET UNIQUE_CHECKS = 0' );
            $wpdb->query( "SET NAMES utf8mb4" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- constant statement, no user input
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

            $state['applied'] = true;
            if ( function_exists( 'museder_restoreone_log' ) ) {
                museder_restoreone_log( 'info', 'DB import session tuning applied.', [ 'fk' => 0, 'uq' => 0, 'names' => 'utf8mb4' ] );
            }
        } catch ( Exception $e ) {
            if ( function_exists( 'museder_restoreone_log' ) ) {
                // @plugin-check: sanitized - log only
                museder_restoreone_log( 'warning', 'DB import session tuning apply failed; continuing without tuning.', [
                    'error' => sanitize_text_field( $e->getMessage() ),
                ] );
            }
        }

        return $state;
    }

    /**
     * Restore MySQL session tuning applied by apply_import_session_tuning().
     *
     * @param array $state
     * @return void
     */
    private static function restore_import_session_tuning( $state ) {
        global $wpdb;

        if ( ! is_array( $state ) || empty( $state['applied'] ) ) {
            return;
        }

        // Validate charset/collation tokens (defensive; these come from server vars).
        $charset = isset( $state['orig_charset'] ) ? (string) $state['orig_charset'] : '';
        $collation = isset( $state['orig_collation'] ) ? (string) $state['orig_collation'] : '';
        if ( $charset && ! preg_match( '/^[A-Za-z0-9_]+$/', $charset ) ) {
            $charset = '';
        }
        if ( $collation && ! preg_match( '/^[A-Za-z0-9_]+$/', $collation ) ) {
            $collation = '';
        }

        try {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            if ( null !== $state['orig_fk'] ) {
                $wpdb->query( 'SET FOREIGN_KEY_CHECKS = ' . ( (int) $state['orig_fk'] ? '1' : '0' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- int only, not user input
            }
            if ( null !== $state['orig_uq'] ) {
                $wpdb->query( 'SET UNIQUE_CHECKS = ' . ( (int) $state['orig_uq'] ? '1' : '0' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- int only, not user input
            }
            if ( $charset ) {
                if ( $collation ) {
                    $wpdb->query( $wpdb->prepare( 'SET NAMES %s COLLATE %s', $charset, $collation ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- MySQL accepts quoted charset/collation tokens; values are prepared
                } else {
                    $wpdb->query( $wpdb->prepare( 'SET NAMES %s', $charset ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- MySQL accepts quoted charset token; value is prepared
                }
            }
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

            if ( function_exists( 'museder_restoreone_log' ) ) {
                museder_restoreone_log( 'info', 'DB import session tuning restored.', [
                    'fk' => $state['orig_fk'],
                    'uq' => $state['orig_uq'],
                    'names' => $charset ? $charset : null,
                ] );
            }
        } catch ( Exception $e ) {
            if ( function_exists( 'museder_restoreone_log' ) ) {
                // @plugin-check: sanitized - log only
                museder_restoreone_log( 'warning', 'DB import session tuning restore failed (ignored).', [
                    'error' => sanitize_text_field( $e->getMessage() ),
                ] );
            }
        }
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

        // 在備份檔案串流過程中，必須使用底層 fopen/fread/fclose 以確保大檔案（>1GB）在各種主機環境下具有最佳效能與穩定性。
        // WP_Filesystem 在部分共用主機環境中會受到限制，因此此處保留原生檔案操作。
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        $handle = fopen( $sql_file, 'rb' );
        if ( $handle ) {
            // Only reads plugin-generated backup files, path is validated and sanitized.
            $sample = fread( $handle, 1048576 ); // 1MB sample.
            if ( false !== strpos( $sample, $placeholder ) ) {
                $needs_normalize = true;
            }
            fclose( $handle );
        }
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        if ( ! $needs_normalize ) {
            return $default;
        }

        self::cleanup_servmask_tables();

        global $wpdb;
        $prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
        if ( '' === $prefix ) {
            museder_restoreone_log( 'warning', 'Detected SERVMASK_PREFIX in SQL but database prefix is unknown. Proceeding without normalization.' );
            return $default;
        }

        $normalized = $sql_file . '.normalized.sql';
        // 在備份檔案串流過程中，必須使用底層 fopen/fread/fwrite/fclose 以確保大檔案（>1GB）在各種主機環境下具有最佳效能與穩定性。
        // WP_Filesystem 在部分共用主機環境中會受到限制，因此此處保留原生檔案操作。
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        $in         = fopen( $sql_file, 'rb' );
        $out        = fopen( $normalized, 'wb' );

        if ( ! $in || ! $out ) {
            if ( $in ) {
                fclose( $in );
            }
            if ( $out ) {
                fclose( $out );
            }
            museder_restoreone_log( 'warning', 'Unable to create normalized SQL file for SERVMASK export.', [ 'source' => $sql_file ] );
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
            $chunk = fread( $in, $chunk_size );
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
            fwrite( $out, $chunk_to_write );
        }

        // Write remaining buffer
        if ( $buffer !== '' ) {
            $buffer = str_replace( $placeholder, $prefix, $buffer );
            fwrite( $out, $buffer );
        }

        fclose( $in );
        fclose( $out );
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

        // For large files (1GB+), always do a second pass to ensure 100% replacement
        // This is necessary because even with large overlap, edge cases can occur
        if ( $is_large_file ) {
            museder_restoreone_log( 'info', 'Large SQL file detected, performing second normalization pass for safety.', [ 
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
                // 在備份檔案串流過程中，必須使用底層 fopen/fread/fwrite/fclose 以確保大檔案（>1GB）在各種主機環境下具有最佳效能與穩定性。
                // WP_Filesystem 在部分共用主機環境中會受到限制，因此此處保留原生檔案操作。
                // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
                $in2 = fopen( $temp_file, 'rb' );
                $out2 = fopen( $normalized, 'wb' );
                
                if ( $in2 && $out2 ) {
                    $second_buffer = '';
                    $second_chunk_size = $chunk_size; // Use same chunk size
                    
                    while ( ! feof( $in2 ) ) {
                        // Only reads plugin-generated backup files, path is validated and sanitized.
                        $chunk2 = fread( $in2, $second_chunk_size );
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
                        fwrite( $out2, $chunk_to_write2 );
                    }
                    
                    // Write remaining buffer
                    if ( $second_buffer !== '' ) {
                        $second_buffer = str_replace( $placeholder, $prefix, $second_buffer );
                        fwrite( $out2, $second_buffer );
                    }
                    
                    fclose( $in2 );
                    fclose( $out2 );
                }
                // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
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
                
                museder_restoreone_log( 'info', 'Second normalization pass completed for large file.', [ 'file' => basename( $normalized ) ] );
            }
        } else {
            // For smaller files, verify and do second pass only if needed
            $normalized_size = filesize( $normalized );
            if ( $normalized_size > 0 && $normalized_size < 52428800 ) { // < 50MB
                // Using native file APIs on local backup directory; paths are sanitized and constrained.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
                $verify_content = file_get_contents( $normalized );
                if ( false !== $verify_content && false !== strpos( $verify_content, $placeholder ) ) {
                    museder_restoreone_log( 'warning', 'SERVMASK placeholder still found after first pass, running second normalization pass.', [ 'file' => basename( $normalized ) ] );
                    
                    $verify_content = str_replace( $placeholder, $prefix, $verify_content );
                    // Using native file APIs on local backup directory; paths are sanitized and constrained.
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
                    file_put_contents( $normalized, $verify_content );
                }
            }
        }

        museder_restoreone_log(
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

        // 這段查詢用於備份／還原流程中的資料庫狀態檢查或結構調整，
        // 輸入值來自系統內部狀態，不包含直接的使用者輸入。
        // 為了確保相容性與效能，此處使用直接查詢而非 WP_Query。
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $tables = $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", 'SERVMASK\_PREFIX\_%' ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- safe: hardcoded pattern for cleanup, not user input
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( empty( $tables ) ) {
            return;
        }

        $dropped = [];

        foreach ( $tables as $table ) {
            // @plugin-check: backup-restore
            // $table is from SHOW TABLES result, sanitized with preg_replace before use
            $safe = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
            if ( empty( $safe ) ) {
                continue;
            }

            // @plugin-check: backup-restore
            // $safe has been whitelist-filtered (alphanumeric + underscore only), safe for identifier usage.
            // Prefer wpdb identifier placeholders when available (WP 6.2+), otherwise fall back to a strict whitelist + esc_sql().
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
            // Note: Avoid %i identifier placeholders for compatibility with older WordPress versions.
            // Identifier is strict-whitelisted above and escaped with esc_sql().
                $wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $safe ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier is strict-whitelisted above and escaped with esc_sql()
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange
            $dropped[] = $safe;
        }

        if ( ! empty( $dropped ) ) {
            museder_restoreone_log(
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
            $content_dir = function_exists( 'museder_restoreone_get_wp_content_dir' ) ? museder_restoreone_get_wp_content_dir() : '';
            if ( '' !== $content_dir ) {
                $targets[] = [ $wp_content_source, $content_dir ];
            }
        } else {
            $upload_dir = wp_upload_dir();
            $uploads_basedir = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
            $uploads_basedir = $uploads_basedir ? wp_normalize_path( $uploads_basedir ) : '';
            $plugins_dir  = function_exists( 'museder_restoreone_get_plugins_dir' ) ? museder_restoreone_get_plugins_dir() : '';
            $themes_dir   = function_exists( 'get_theme_root' ) ? wp_normalize_path( (string) get_theme_root() ) : '';
            $mu_plugins_dir = function_exists( 'museder_restoreone_get_mu_plugins_dir' ) ? museder_restoreone_get_mu_plugins_dir() : '';
            $fallbacks = [
                'themes'     => $themes_dir,
                'plugins'    => $plugins_dir,
                'uploads'    => $uploads_basedir,
                'mu-plugins' => $mu_plugins_dir,
            ];

            foreach ( $fallbacks as $dir => $destination ) {
                if ( empty( $destination ) ) {
                    continue;
                }
                $source = self::find_directory_by_name( $extract_dir, $dir );
                if ( $source && is_dir( $source ) ) {
                    $targets[] = [ $source, $destination ];
                }
            }
        }

        if ( empty( $targets ) ) {
            museder_restoreone_log( 'warning', 'No wp-content data found in archive.', [
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
                $log = museder_restoreone_log( 'error', 'Failed to copy files during restore.', [
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

        museder_restoreone_log( 'info', 'File restore completed.' );

        return [
            'success' => true,
            'message' => __( 'Files restored successfully.', 'museder-restoreone' ),
        ];
    }

    private static function extract_archive( $archive, $destination ) {
        $zip_error_code = null;

        // WP.org submission build: .wpress is not supported.
        $ext = strtolower( pathinfo( $archive, PATHINFO_EXTENSION ) );
        if ( 'wpress' === $ext ) {
            museder_restoreone_log( 'warning', 'wpress_not_supported', [ 'archive' => basename( $archive ) ] );
                return [
                'success' => false,
                'code'    => 'wpress_not_supported',
            ];
        }

        if ( museder_restoreone_can_use_ziparchive() ) {
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
        
        museder_restoreone_log( 'error', $error_message, [
            'archive'    => $archive,
            'zip_error_code' => $zip_error_code,
            'pclzip_error' => isset( $pcl_result['error'] ) ? $pcl_result['error'] : '',
        ] );

        return [
            'success'        => false,
            'code'           => 'zip_open_failed',
            'zip_error_code' => $zip_error_code,
        ];
    }

    private static function extract_with_ziparchive( $archive, $destination ) {
        $zip = new ZipArchive();
        $open_result = $zip->open( $archive );
        $ok_code     = defined( 'ZipArchive::ER_OK' ) ? ZipArchive::ER_OK : 0;

        if ( true !== $open_result && $ok_code !== $open_result ) {
            museder_restoreone_log( 'error', 'zip_open_failed', [
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
                museder_restoreone_log( 'warning', 'zip_entry_skipped', [ 'entry' => $entry, 'reason' => 'suspicious_path' ] );
                $skipped++;
                continue;
            }

            $target = museder_restoreone_safe_path_join( $destination, $entry );

            if ( ! $target ) {
                museder_restoreone_log( 'warning', 'zip_entry_skipped', [ 'entry' => $entry, 'reason' => 'unsafe_join' ] );
                $skipped++;
                continue;
            }

            if ( substr( $entry, -1 ) === '/' ) {
                museder_restoreone_ensure_directory( $target );
                continue;
            }

            museder_restoreone_ensure_directory( dirname( $target ) );

            $input = $zip->getStream( $entry );
            if ( ! $input ) {
                museder_restoreone_log( 'error', 'Unable to read entry during extraction.', [ 'entry' => $entry ] );
                $error_code = 'entry_stream_unreadable';
                break;
            }

            // 在備份檔案串流過程中，必須使用底層 fopen/fread/fwrite/fclose 以確保大檔案（>1GB）在各種主機環境下具有最佳效能與穩定性。
            // WP_Filesystem 在部分共用主機環境中會受到限制，因此此處保留原生檔案操作。
            // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            $output = fopen( $target, 'wb' );
            if ( ! $output ) {
                museder_restoreone_log( 'error', 'Unable to write extracted file.', [ 'target' => $target ] );
                fclose( $input );
                $error_code = 'entry_unwritable';
                break;
            }

            while ( ! feof( $input ) ) {
                // Only reads plugin-generated backup files, path is validated and sanitized.
                $buffer = fread( $input, 1048576 );
                if ( false === $buffer ) {
                    museder_restoreone_log( 'error', 'Error while reading stream during extraction.', [ 'entry' => $entry ] );
                    $error_code = 'stream_read_error';
                    break;
                }
                if ( false === fwrite( $output, $buffer ) ) {
                    museder_restoreone_log( 'error', 'Unable to write buffer during extraction.', [ 'target' => $target ] );
                    $error_code = 'stream_write_error';
                    break;
                }
            }

            fclose( $input );
            fclose( $output );
            // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

            if ( null !== $error_code ) {
                break;
            }
        }

        $zip->close();

        if ( $skipped > 0 ) {
            museder_restoreone_log( 'warning', 'zip_entries_skipped_summary', [ 'count' => $skipped ] );
        }

        if ( null !== $error_code ) {
            return [ 'success' => false, 'error_code' => $error_code ];
        }

        return [ 'success' => true, 'entries' => $zip->numFiles ];
    }

    /**
     * WP.org submission build: .wpress is not supported.
     *
     * @param string $archive
     * @param string $destination
     * @return array{success:bool, error?:string, error_code?:string}
     */
    private static function extract_with_tar( $archive, $destination ) {
        return [
            'success'    => false,
            'error'      => __( '.wpress files are not supported in this build. Please convert the backup to ZIP format first.', 'museder-restoreone' ),
            'error_code' => 'wpress_not_supported',
        ];
    }
    
    /**
     * WP.org submission build: .wpress is not supported.
     *
     * @param string $archive
     * @param string $destination
     * @return array{success:bool, error?:string, error_code?:string}
     */
    private static function extract_wpress_with_php( $archive, $destination ) {
        return [
            'success'    => false,
            'error'      => __( '.wpress files are not supported in this build. Please convert the backup to ZIP format first.', 'museder-restoreone' ),
            'error_code' => 'wpress_not_supported',
        ];
    }

    private static function extract_with_pclzip( $archive, $destination ) {
        if ( function_exists( 'museder_restoreone_require_pclzip' ) ) {
            museder_restoreone_require_pclzip();
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
            
            museder_restoreone_log( 'warning', 'pclzip_extract_exception', [
                'archive'    => $archive,
                'exception'   => $e->getMessage(),
                'extracted_files' => $extracted_files,
            ] );
            
            // If some files were extracted, consider it partially successful
            // The extraction may have completed despite the exception
            if ( $extracted_files > 0 ) {
                museder_restoreone_log( 'info', 'PclZip extraction completed despite exception, continuing with restore.', [
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

            museder_restoreone_log( 'error', 'pclzip_extract_failed', [
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
                    $target = museder_restoreone_safe_path_join( self::$pclzip_destination, $entry_path );
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
                        museder_restoreone_log( 'warning', 'zip_entry_removed_after_extraction', [
                            'entry' => $entry_path,
                            'reason' => 'suspicious_path',
                        ] );
                    }
                } else {
                    // Also check for unsafe path joins
                    $target = museder_restoreone_safe_path_join( self::$pclzip_destination, $entry_path );
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
                            museder_restoreone_log( 'warning', 'zip_entry_removed_after_extraction', [
                                'entry' => $entry_path,
                                'reason' => 'unsafe_join',
                            ] );
                        }
                    }
                }
            }
            if ( $removed_count > 0 ) {
                museder_restoreone_log( 'info', 'zip_entries_filtered_after_extraction', [ 'count' => $removed_count ] );
            }
        }

        return [ 'success' => true, 'entries' => $result ];
    }

    public static function pclzip_pre_extract( $event, &$header ) {
         if ( 'check' === $event ) {
            if ( self::is_suspicious_zip_entry( $header['stored_filename'] ) ) {
                museder_restoreone_log( 'warning', 'zip_entry_skipped', [ 'entry' => $header['stored_filename'], 'reason' => 'suspicious_path' ] );
                return 0;
            }

            $target = museder_restoreone_safe_path_join( self::$pclzip_destination, $header['stored_filename'] );
            if ( ! $target ) {
                museder_restoreone_log( 'warning', 'zip_entry_skipped', [ 'entry' => $header['stored_filename'], 'reason' => 'unsafe_join' ] );
                return 0;
            }
         }
 
         return 1;
    }

    private static function locate_database_dump( $directory ) {
        $candidate = trailingslashit( $directory ) . 'database.ndjson';
        if ( file_exists( $candidate ) ) {
            return $candidate;
        }

        $candidate = trailingslashit( $directory ) . 'database.sql';
        if ( file_exists( $candidate ) ) {
            return $candidate;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            $name = strtolower( $file->getFilename() );
            if ( 'database.ndjson' === $name || 'database.sql' === $name ) {
                return $file->getPathname();
            }
        }

        return '';
    }

    private static function copy_directory( $source, $destination ) {
        $source      = rtrim( wp_normalize_path( $source ), '/' );
        $destination = rtrim( wp_normalize_path( $destination ), '/' );

        if ( ! is_dir( $source ) ) {
            museder_restoreone_log( 'error', 'copy_directory_source_not_dir', [
                'source' => $source,
                'destination' => $destination,
            ] );
            return false;
        }

        if ( ! is_readable( $source ) ) {
            museder_restoreone_log( 'error', 'copy_directory_source_not_readable', [
                'source' => $source,
                'destination' => $destination,
            ] );
            return false;
        }

        if ( ! file_exists( $destination ) ) {
            if ( ! museder_restoreone_ensure_directory( $destination ) ) {
                museder_restoreone_log( 'error', 'copy_directory_dest_create_failed', [
                    'source' => $source,
                    'destination' => $destination,
                ] );
                return false;
            }
        }

        if ( ! wp_is_writable( $destination ) ) {
            museder_restoreone_log( 'error', 'copy_directory_dest_not_writable', [
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
                    if ( ! museder_restoreone_ensure_directory( $target_path ) ) {
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
                    if ( ! museder_restoreone_ensure_directory( $dir ) ) {
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
                    
                    museder_restoreone_log( 'error', 'copy_file_failed', $error_details );
                    
                    // If too many files fail, abort to avoid wasting time
                    if ( $failed_count > 10 && $copied_count === 0 ) {
                        museder_restoreone_log( 'error', 'copy_directory_aborted_too_many_failures', [
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
            museder_restoreone_log( 'warning', 'copy_directory_partial_success', [
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

        museder_restoreone_log( 'info', 'copy_directory_completed', [
            'source' => $source,
            'destination' => $destination,
            'copied_count' => $copied_count,
            'failed_count' => $failed_count,
        ] );

        return true;
    }
    
    // NOTE: Automatic SQL dump import (database.sql) has been removed for WP.org submission compliance.

    /**
     * Snapshot critical runtime state before DB import replaces all tables.
     *
     * Captures the current admin's WP session tokens and plugin-specific
     * options (restore lock, active job, cron schedule) so they can be
     * re-injected after import.  This follows the same pattern used by
     * All-in-One WP Migration (secret_key save/restore around import).
     *
     * @return array Opaque state blob for restore_session_after_import().
     */
    private static function preserve_session_before_import() {
        $state = [
            'user_id'        => function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0,
            'session_tokens' => [],
            'cron'           => get_option( 'cron' ),
            'restore_lock'   => get_option( 'museder_restoreone_restore_lock' ),
            'active_job'     => get_option( 'museder_restoreone_restore_service_active_job_id' ),
        ];

        if ( $state['user_id'] > 0 && class_exists( 'WP_Session_Tokens' ) ) {
            $manager = WP_Session_Tokens::get_instance( $state['user_id'] );
            $state['session_tokens'] = $manager->get_all();
        }

        return $state;
    }

    /**
     * Re-inject preserved runtime state after DB import.
     *
     * Because import_database_from_ndjson() DROP+rebuilds every table
     * (including wp_usermeta and wp_options), the current admin's session
     * tokens and the plugin's cron/lock state are destroyed.
     * This method writes them back so:
     *  - The admin is not forced to re-login.
     *  - WP-Cron can still fire the next restore slice.
     *  - The restore lock remains valid.
     *
     * @param array $state Return value from preserve_session_before_import().
     */
    private static function restore_session_after_import( array $state ) {
        global $wpdb;

        // 1. Flush object cache so subsequent reads hit the new DB.
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }

        // 2. Re-inject session tokens for the admin who triggered the restore.
        $user_id = isset( $state['user_id'] ) ? (int) $state['user_id'] : 0;
        if ( $user_id > 0 && ! empty( $state['session_tokens'] ) ) {
            $serialized = maybe_serialize( $state['session_tokens'] );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $updated = $wpdb->update(
                $wpdb->usermeta,
                [ 'meta_value' => $serialized ],
                [
                    'user_id'  => $user_id,
                    'meta_key' => 'session_tokens', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                ]
            );
            if ( 0 === (int) $wpdb->rows_affected ) {
                // Row may not exist yet (backup had different user IDs).
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->insert(
                    $wpdb->usermeta,
                    [
                        'user_id'    => $user_id,
                        'meta_key'   => 'session_tokens', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                        'meta_value' => $serialized, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                    ]
                );
            }

            if ( function_exists( 'museder_restoreone_log' ) ) {
                museder_restoreone_log( 'info', 'Session tokens re-injected after DB import.', [ 'user_id' => $user_id ] );
            }
        }

        // 3. Restore cron schedule.
        if ( ! empty( $state['cron'] ) ) {
            update_option( 'cron', $state['cron'] );
        }

        // 4. Restore the plugin's restore lock.
        if ( ! empty( $state['restore_lock'] ) ) {
            update_option( 'museder_restoreone_restore_lock', $state['restore_lock'], false );
            if ( function_exists( 'set_site_transient' ) ) {
                set_site_transient( 'museder_restoreone_restore_lock', $state['restore_lock'], 30 * MINUTE_IN_SECONDS );
            }
        }

        // 5. Restore active job pointer.
        if ( ! empty( $state['active_job'] ) ) {
            update_option( 'museder_restoreone_restore_service_active_job_id', $state['active_job'], false );
        }

        // 6. Restore the file-based restore token (Fix 2 integration point).
        $token_file = WP_CONTENT_DIR . '/uploads/museder-restoreone/temp/.restore-auth-token';
        if ( file_exists( $token_file ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
            $token_data = json_decode( file_get_contents( $token_file ), true );
            if ( is_array( $token_data ) ) {
                update_option( 'museder_restoreone_restore_token', $token_data, false );
            }
        }
    }

    private static function run_database_primers() {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        // 說明：以下查詢用於備份/還原流程，必須直接操作資料表結構，無法使用高階 API 或快取。
        // 所有 table 名稱皆由 $wpdb 提供或白名單，不接受使用者輸入。
        // These are MySQL session settings (hardcoded strings), not user input.
        $wpdb->query( 'SET foreign_key_checks = 0' );
        $wpdb->query( "SET NAMES 'utf8mb4'" );
        $wpdb->query( "SET sql_mode = ''" );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
    }

    private static function restore_database_constraints() {
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
        // 說明：以下查詢用於備份/還原流程，必須直接操作資料表結構，無法使用高階 API 或快取。
        // 所有 table 名稱皆由 $wpdb 提供或白名單，不接受使用者輸入。
        // This is a MySQL session setting (hardcoded string), not user input.
        $wpdb->query( 'SET foreign_key_checks = 1' );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
    }

    private static function run_search_replace( $pairs ) {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
        // 說明：以下查詢用於備份/還原過程，必須直接操作資料表結構，table 名稱皆來自 $wpdb 或白名單，不接受使用者輸入。
        $tables = $wpdb->get_col( 'SHOW TABLES' );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( empty( $tables ) ) {
            return;
        }

        $text_types = [ 'tinytext', 'text', 'mediumtext', 'longtext', 'varchar', 'char' ];

        foreach ( $tables as $table ) {
            // @plugin-check: backup-restore
            // $table comes from SHOW TABLES result, sanitized with preg_replace before use in query
            $safe_table = preg_replace( '/[^A-Za-z0-9_]/', '', $table );
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
            // 說明：以下查詢用於備份/還原過程，必須直接操作資料表結構，table 名稱皆來自 $wpdb 或白名單，不接受使用者輸入。
            // Identifier: safe_table is strict-whitelisted; do not use prepare() for identifiers.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier; strict whitelist applied above
            $columns = $wpdb->get_results( 'SHOW COLUMNS FROM `' . esc_sql( $safe_table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier is strict-whitelisted above and escaped with esc_sql()
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

            // @plugin-check: backup-restore
            // $safe_table has been whitelist-filtered (alphanumeric + underscore only), safe for SELECT
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
            // 說明：以下查詢用於備份/還原過程，必須直接操作資料表結構，table 名稱皆來自 $wpdb 或白名單，不接受使用者輸入。
            // Identifier: safe_table is strict-whitelisted; do not use prepare() for identifiers.
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier; strict whitelist applied above
            $rows = $wpdb->get_results( 'SELECT * FROM `' . esc_sql( $safe_table ) . '`', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifier is strict-whitelisted above and escaped with esc_sql()
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
     *
     * Records the current `active_plugins` list and sets a safe-mode marker. This build does not
     * automatically activate or deactivate other plugins; the site owner reviews the snapshot and clears the marker.
     *
     * @return bool True on success, false on failure.
     */
    public static function enter_safe_mode_after_import() {
        // Snapshot active plugins for admin review (no automatic activation changes).
        $active_plugins = get_option( 'active_plugins', [] );
        
        if ( ! is_array( $active_plugins ) ) {
            $active_plugins = [];
        }

        $plugin_count = count( $active_plugins );
        
        // Store the previous active plugins list so the admin can review it.
        update_option( 'museder_restoreone_prev_active_plugins', $active_plugins, false );
        
        // Set safe mode flag (note: we do NOT change other plugins' activation status automatically).
        update_option( 'museder_restoreone_safe_mode', '1', false );

        museder_restoreone_log( 'info', 'Safe mode marker enabled after restore (no automatic plugin activation changes).', [
            'previous_plugins_count' => $plugin_count,
        ] );
        
        return true;
    }

    /**
     * Exit safe mode: clear the marker and stored plugin snapshot.
     *
     * Does not change which plugins are active; administrators manage plugins in WordPress as usual.
     *
     * @return bool True on success, false on failure.
     */
    public static function exit_safe_mode() {
        // Check if safe mode is active
        $safe_mode = get_option( 'museder_restoreone_safe_mode', '' );
        
        if ( '1' !== $safe_mode ) {
            museder_restoreone_log( 'info', 'Safe mode exit called but safe mode is not active.', [] );
            return false;
        }

        // Delete safe mode options (no plugin activation changes are performed here).
        delete_option( 'museder_restoreone_safe_mode' );
        delete_option( 'museder_restoreone_prev_active_plugins' );
        
        museder_restoreone_log( 'info', 'Safe mode marker cleared by admin.', [] );
        
        return true;
    }
}
