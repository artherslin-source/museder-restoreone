<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class Backup_Lite_Restore {

    private static $pclzip_destination = '';

    /**
     * Restore a site from a unified backup archive.
     *
     * @param string $archive_file
     * @param array  $options      Optional restore options (search_replace)
     *
     * @return array{success:bool,message:string,log?:string,code?:string}
     */
    public static function restore_site( $archive_file, $options = [] ) {
        $archive_file = wp_normalize_path( $archive_file );
        $result   = [
            'success' => false,
            'message' => __( 'Restore failed.', 'museder-restoreone' ),
        ];

        if ( ! file_exists( $archive_file ) || ! is_readable( $archive_file ) ) {
            $log = backup_lite_log( 'error', 'Restore archive not readable.', [ 'path' => $archive_file ] );
            return [
                'success' => false,
                'message' => __( 'Backup file not found or unreadable.', 'museder-restoreone' ),
                'log'     => $log,
                'code'    => 'archive_not_readable',
            ];
        }

        $log = backup_lite_log( 'info', 'Site restore started.', [
            'archive' => $archive_file,
            'method'  => 'auto',
        ] );

        $temp_dir = backup_lite_create_temp_dir( 'restore' );

        $extract_result = self::extract_archive( $archive_file, $temp_dir );

        if ( empty( $extract_result['success'] ) ) {
            backup_lite_log( 'error', 'Failed to extract archive for restore.', [
                'archive'        => $archive_file,
                'zip_error_code' => isset( $extract_result['zip_error_code'] ) ? $extract_result['zip_error_code'] : null,
            ] );
            backup_lite_delete_directory( $temp_dir );

            return [
                'success' => false,
                'message' => __( 'Unable to extract backup archive. Check logs for details.', 'museder-restoreone' ),
                'log'     => $log,
                'code'    => isset( $extract_result['code'] ) ? $extract_result['code'] : 'zip_open_failed',
                'zip_error_code' => isset( $extract_result['zip_error_code'] ) ? $extract_result['zip_error_code'] : null,
            ];
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

        $db_result = self::import_database( $sql_path );
        if ( empty( $db_result['success'] ) ) {
            backup_lite_delete_directory( $temp_dir );
            return $db_result;
        }

        $files_result = self::restore_files_from_extract( $temp_dir );
        if ( empty( $files_result['success'] ) ) {
            backup_lite_delete_directory( $temp_dir );
            return $files_result;
        }

        if ( ! empty( $options['search_replace'] ) && is_array( $options['search_replace'] ) ) {
            self::run_search_replace( $options['search_replace'] );
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
    public static function import_database( $sql_file ) {
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

        $method = backup_lite_can_use_mysql_cli() ? 'mysql-cli' : 'php';
        $log    = backup_lite_log( 'info', 'Database restore started.', [ 'method' => $method, 'path' => $sql_file ] );

        if ( backup_lite_can_use_mysql_cli() ) {
            $success = self::import_database_with_cli( $sql_file );
            $php_details = [];
        } else {
            $php_details = self::import_database_with_php( $sql_file );
            $success     = isset( $php_details['success'] ) ? $php_details['success'] : false;
        }

        if ( ! $success ) {
            backup_lite_log( 'error', 'Database restore failed.', [ 'path' => $sql_file ] );
            $result['message'] = __( 'Database restore encountered an error. Check logs.', 'museder-restoreone' );
            $result['log']     = $log;
            if ( ! empty( $php_details['line'] ) ) {
                $result['line'] = $php_details['line'];
            }
            $result['code'] = isset( $php_details['code'] ) ? $php_details['code'] : 'database_error';
            return $result;
        }

        backup_lite_log( 'info', 'Database restore completed.', [ 'path' => $sql_file ] );

        $result['success'] = true;
        $result['message'] = __( 'Database restore completed successfully.', 'museder-restoreone' );
        $result['log']     = $log;
        $result['code']    = 'database_restored';

        return $result;
    }

    private static function restore_files_from_extract( $extract_dir ) {
        $wp_content_source = trailingslashit( $extract_dir ) . 'wp-content';
        $targets = [];

        if ( is_dir( $wp_content_source ) ) {
            $targets[] = [ $wp_content_source, WP_CONTENT_DIR ];
        } else {
            $fallbacks = [
                'themes'  => WP_CONTENT_DIR . '/themes',
                'plugins' => WP_CONTENT_DIR . '/plugins',
                'uploads' => WP_CONTENT_DIR . '/uploads',
                'mu-plugins' => WP_CONTENT_DIR . '/mu-plugins',
            ];

            foreach ( $fallbacks as $dir => $destination ) {
                $source = trailingslashit( $extract_dir ) . $dir;
                if ( is_dir( $source ) ) {
                    $targets[] = [ $source, $destination ];
                }
            }
        }

        if ( empty( $targets ) ) {
            backup_lite_log( 'warning', 'No wp-content data found in archive.' );
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

            $output = fopen( $target, 'wb' );
            if ( ! $output ) {
                backup_lite_log( 'error', 'Unable to write extracted file.', [ 'target' => $target ] );
                fclose( $input );
                $error_code = 'entry_unwritable';
                break;
            }

            while ( ! feof( $input ) ) {
                $buffer = fread( $input, 1048576 );
                if ( false === $buffer ) {
                    backup_lite_log( 'error', 'Error while reading stream during extraction.', [ 'entry' => $entry ] );
                    $error_code = 'stream_read_error';
                    break;
                }
                if ( false === fwrite( $output, $buffer ) ) {
                    backup_lite_log( 'error', 'Unable to write buffer during extraction.', [ 'target' => $target ] );
                    $error_code = 'stream_write_error';
                    break;
                }
            }

            fclose( $input );
            fclose( $output );

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

    private static function extract_with_pclzip( $archive, $destination ) {
        if ( ! class_exists( 'PclZip' ) ) {
            require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        }

        self::$pclzip_destination = wp_normalize_path( $destination );

        $zip    = new PclZip( $archive );
        $result = $zip->extract(
            PCLZIP_OPT_PATH, self::$pclzip_destination,
            PCLZIP_CB_PRE_EXTRACT, [ __CLASS__, 'pclzip_pre_extract' ]
        );

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
            return false;
        }

        if ( ! file_exists( $destination ) ) {
            backup_lite_ensure_directory( $destination );
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            $target_path = $destination . substr( wp_normalize_path( $item->getPathname() ), strlen( $source ) );

            if ( $item->isDir() ) {
                if ( ! file_exists( $target_path ) ) {
                    backup_lite_ensure_directory( $target_path );
                }
            } else {
                $dir = dirname( $target_path );
                if ( ! file_exists( $dir ) ) {
                    backup_lite_ensure_directory( $dir );
                }

                if ( ! @copy( $item->getPathname(), $target_path ) ) {
                    return false;
                }
            }
        }

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

    private static function import_database_with_php( $sql_file ) {
        global $wpdb;

        $handle = fopen( $sql_file, 'r' );
        if ( ! $handle ) {
            backup_lite_log( 'error', 'Unable to open SQL file for reading.', [ 'path' => $sql_file ] );
            return false;
        }

        @set_time_limit( 0 );
        @ini_set( 'memory_limit', '512M' );

        $query    = '';
        $success  = true;
        $line_num = 0;

        self::run_database_primers();

        while ( false !== ( $line = fgets( $handle ) ) ) {
            $line_num++;
            $trimmed = trim( $line );

            if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) || 0 === strpos( $trimmed, '/*' ) ) {
                continue;
            }

            $query .= $line;

            if ( ';' === substr( rtrim( $line ), -1 ) ) {
                $prepared = trim( $query );
                if ( ! empty( $prepared ) ) {
                    $wpdb->flush();
                    $result = $wpdb->query( $prepared );

                    if ( false === $result ) {
                        $error = $wpdb->last_error ?: 'unknown error';
                        backup_lite_log( 'error', 'SQL execution failed.', [
                            'line'  => $line_num,
                            'error' => $error,
                        ] );
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

        fclose( $handle );

        self::restore_database_constraints();

        return [
            'success' => $success,
            'code'    => $success ? 'database_restored' : 'database_error',
        ];
    }

    private static function run_database_primers() {
        global $wpdb;
        $wpdb->query( 'SET foreign_key_checks = 0' );
        $wpdb->query( "SET NAMES 'utf8mb4'" );
        $wpdb->query( "SET sql_mode = ''" );
    }

    private static function restore_database_constraints() {
        global $wpdb;
        $wpdb->query( 'SET foreign_key_checks = 1' );
    }

    private static function run_search_replace( $pairs ) {
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
                    $wpdb->update( $table, $update, [ 'id' => isset( $row['id'] ) ? $row['id'] : $row[ array_key_first( $row ) ] ] );
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
}
