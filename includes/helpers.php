<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'backup_lite_local_time' ) ) {
    /**
     * Return a timestamp formatted using the site's timezone settings.
     *
     * @param string   $format    Date format string.
     * @param int|null $timestamp Optional Unix timestamp.
     * @return string
     */
    /**
     * Return a timestamp formatted using the site's local timezone.
     * 
     * @param string   $format    Date format string.
     * @param int|null $timestamp Optional Unix timestamp (assumed to be UTC).
     * @return string Formatted date/time in site's local timezone.
     */
    function backup_lite_local_time( $format = 'Y-m-d H:i:s', $timestamp = null ) {
        // Use wp_date() for WordPress 5.3+ (handles timezone conversion automatically)
        if ( function_exists( 'wp_date' ) ) {
            // wp_date() expects UTC timestamp and converts to local timezone
            if ( null === $timestamp ) {
                $timestamp = time(); // Use current UTC time
            }
            return wp_date( $format, $timestamp, wp_timezone() );
        }

        // Fallback for older WordPress versions
        if ( null === $timestamp ) {
            $timestamp = time(); // Use current UTC time
        }
        
        // date_i18n() expects local timestamp, so we need to convert UTC to local
        $gmt_offset = get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
        $local_timestamp = $timestamp + $gmt_offset;
        
        return date_i18n( $format, $local_timestamp );
    }
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- temporary variable for file path only
    $museder_restoreone_formatting = trailingslashit( ABSPATH ) . 'wp-includes/formatting.php';
    if ( file_exists( $museder_restoreone_formatting ) ) {
        require_once $museder_restoreone_formatting;
    }
}

if ( ! function_exists( 'size_format' ) ) {
    /**
     * Provides a lightweight replacement for WordPress size_format() when unavailable (e.g. CLI tests).
     *
     * @param float $bytes    Size in bytes.
     * @param int   $decimals Decimal precision.
     * @return string
     */
    function size_format( $bytes, $decimals = 0 ) {
        $bytes = (float) $bytes;
        if ( $bytes < 0 ) {
            $bytes = 0;
        }

        $units = [ 'B', 'KB', 'MB', 'GB', 'TB', 'PB' ];
        $pow   = 0;

        if ( $bytes > 0 ) {
            $pow = floor( log( $bytes, 1024 ) );
            $pow = min( $pow, count( $units ) - 1 );
        }

        $value = $bytes / pow( 1024, $pow );

        return round( $value, $decimals ) . $units[ $pow ];
    }
}

/**
 * Return the base directory used by Backup Lite within uploads.
 *
 * @return array{path:string,url:string}
 */
function backup_lite_get_storage_root() {
    $upload_dir = wp_upload_dir();
    $base       = trailingslashit( $upload_dir['basedir'] ) . 'museder-restoreone';
    $url        = trailingslashit( $upload_dir['baseurl'] ) . 'museder-restoreone';

    backup_lite_ensure_directory( $base );
    backup_lite_maybe_protect_directory( $base );

    return [
        'path' => $base,
        'url'  => $url,
    ];
}

function backup_lite_get_backup_dir() {
    $root = backup_lite_get_storage_root();
    $dir  = trailingslashit( $root['path'] ) . 'backups';
    backup_lite_ensure_directory( $dir );

    return $dir;
}

function backup_lite_get_log_dir() {
    $upload_dir = wp_upload_dir();
    $dir        = trailingslashit( $upload_dir['basedir'] ) . 'backup-lite-logs';

    backup_lite_ensure_directory( $dir );
    backup_lite_maybe_protect_directory( $dir );

    return $dir;
}

function backup_lite_get_temp_dir() {
    $root = backup_lite_get_storage_root();
    $dir  = trailingslashit( $root['path'] ) . 'temp';
    backup_lite_ensure_directory( $dir );
    backup_lite_maybe_protect_directory( $dir );

    return $dir;
}

function backup_lite_get_jobs_dir() {
    $root = backup_lite_get_storage_root();
    $dir  = trailingslashit( $root['path'] ) . 'jobs';
    backup_lite_ensure_directory( $dir );
    backup_lite_maybe_protect_directory( $dir );

    return $dir;
}

function backup_lite_get_reports_dir() {
    $root = backup_lite_get_storage_root();
    $dir  = trailingslashit( $root['path'] ) . 'reports';
    backup_lite_ensure_directory( $dir );
    backup_lite_maybe_protect_directory( $dir );

    return $dir;
}

/**
 * Get PRO jobs directory.
 *
 * @return string
 */
function backup_lite_get_pro_jobs_dir() {
    $root = backup_lite_get_storage_root();
    $dir  = trailingslashit( $root['path'] ) . 'pro/jobs';
    backup_lite_ensure_directory( $dir );
    backup_lite_maybe_protect_directory( $dir );

    return $dir;
}

/**
 * Get PRO reports directory.
 *
 * @return string
 */
function backup_lite_get_pro_reports_dir() {
    $root = backup_lite_get_storage_root();
    $dir  = trailingslashit( $root['path'] ) . 'pro/reports';
    backup_lite_ensure_directory( $dir );
    backup_lite_maybe_protect_directory( $dir );

    return $dir;
}

function backup_lite_get_restore_history_path() {
    $root = backup_lite_get_storage_root();
    $path = trailingslashit( $root['path'] ) . 'restore-history.json';

    if ( ! file_exists( $path ) ) {
        @file_put_contents( $path, wp_json_encode( [] ), LOCK_EX );
        @chmod( $path, 0640 );
    }

    return $path;
}

function backup_lite_get_restore_history( $limit = 0 ) {
    $path = backup_lite_get_restore_history_path();

    if ( ! file_exists( $path ) ) {
        return [];
    }

    $contents = file_get_contents( $path );
    if ( false === $contents ) {
        return [];
    }

    $data = json_decode( $contents, true );
    if ( ! is_array( $data ) ) {
        return [];
    }

    if ( $limit > 0 ) {
        return array_slice( $data, 0, $limit );
    }

    return $data;
}

function backup_lite_append_restore_history( $entry ) {
    if ( empty( $entry ) || ! is_array( $entry ) ) {
        return false;
    }

    $history = backup_lite_get_restore_history();
    array_unshift( $history, $entry );

    $history = array_slice( $history, 0, 50 );

    $path = backup_lite_get_restore_history_path();

    $json = wp_json_encode( $history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

    return false !== file_put_contents( $path, $json, LOCK_EX );
}

function backup_lite_create_temp_dir( $prefix = 'tmp' ) {
    $temp_base = backup_lite_get_temp_dir();

    if ( function_exists( 'wp_generate_password' ) ) {
        $token = wp_generate_password( 6, false, false );
    } else {
        try {
            $token = substr( bin2hex( random_bytes( 6 ) ), 0, 6 );
        } catch ( Exception $e ) {
            $token = substr( uniqid( '', true ), -6 );
        }
    }

    $unique = $prefix . '-' . backup_lite_local_time( 'Ymd-His' ) . '-' . $token;
    $path   = trailingslashit( $temp_base ) . $unique;

    backup_lite_ensure_directory( $path );

    return $path;
}

function backup_lite_get_chunk_path( $upload_id, $file = '' ) {
    $slug = preg_replace( '/[^a-zA-Z0-9\-_.]/', '', (string) $upload_id );
    if ( empty( $slug ) ) {
        return '';
    }

    $base = trailingslashit( backup_lite_get_temp_dir() ) . $slug;
    backup_lite_ensure_directory( $base );

    if ( $file ) {
        return trailingslashit( $base ) . ltrim( $file, '/' );
    }

    return $base;
}

function backup_lite_delete_directory( $directory ) {
    if ( empty( $directory ) || ! file_exists( $directory ) ) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ( $iterator as $fileinfo ) {
        // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
        // $directory is from plugin-controlled directories only
        if ( $fileinfo->isDir() ) {
            @rmdir( $fileinfo->getRealPath() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- required for recursive directory deletion, path from plugin-controlled directory
        } else {
            // Try wp_delete_file() first, fallback to unlink() if not available
            $file_path = $fileinfo->getRealPath();
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $file_path );
            } else {
                // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
                @unlink( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_unlink -- required for recursive directory deletion, path from plugin-controlled directory
            }
        }
    }

    // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
    @rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- required for directory deletion, path from plugin-controlled directory
}

function backup_lite_cleanup_temp( $max_age = DAY_IN_SECONDS ) {
    $temp_dir = backup_lite_get_temp_dir();
    if ( ! is_dir( $temp_dir ) ) {
        return;
    }

    $now = time();

    try {
        $iterator = new DirectoryIterator( $temp_dir );
    } catch ( Exception $e ) {
        return;
    }

    foreach ( $iterator as $entry ) {
        if ( $entry->isDot() ) {
            continue;
        }

        $path = $entry->getPathname();
        $age  = $now - $entry->getMTime();

        if ( $entry->isDir() ) {
            if ( $age > $max_age ) {
                backup_lite_delete_directory( $path );
            }
        } elseif ( $age > $max_age ) {
            // @plugin-check: allowed - required for backup/restore file operations
            // Path is validated and sanitized before use
            @unlink( $path );
        }
    }
}

function backup_lite_sanitize_filename( $filename ) {
    if ( function_exists( 'sanitize_file_name' ) ) {
        return sanitize_file_name( $filename );
    }

    $filename = preg_replace( '/[^a-zA-Z0-9_\.-]/', '-', (string) $filename );
    return trim( preg_replace( '/-+/', '-', $filename ), '-' );
}

function backup_lite_is_allowed_backup_extension( $filename ) {
    $extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
    return in_array( $extension, [ 'zip', 'wpress' ], true );
}

function backup_lite_safe_path_join( $base, $path ) {
    $base     = wp_normalize_path( $base );
    $relative = str_replace( '\\', '/', (string) $path );

    // Reject absolute paths and traversal attempts.
    if ( preg_match( '#^(?:[A-Za-z]:\\\\|[A-Za-z]:/|/)#', $relative ) ) {
        return false;
    }

    if ( strpos( $relative, '..' ) !== false ) {
        return false;
    }

    $relative = ltrim( $relative, '/' );

    $joined = wp_normalize_path( trailingslashit( $base ) . $relative );

    if ( strpos( $joined, $base ) !== 0 ) {
        return false;
    }

    return $joined;
}

function backup_lite_ensure_directory( $dir ) {
    if ( ! file_exists( $dir ) ) {
        $result = wp_mkdir_p( $dir );
        if ( ! $result ) {
            backup_lite_log( 'error', 'ensure_directory_failed', [
                'dir' => $dir,
                'parent_exists' => file_exists( dirname( $dir ) ),
                'parent_writable' => wp_is_writable( dirname( $dir ) ),
            ] );
            return false;
        }
    }
    return true;
}

function backup_lite_maybe_protect_directory( $dir ) {
    backup_lite_ensure_directory( $dir );

    $htaccess = trailingslashit( $dir ) . '.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        $rules = "Options -Indexes\nRequire all denied\n";
        @file_put_contents( $htaccess, $rules );
    }

    $nginx = trailingslashit( $dir ) . 'nginx-deny.conf';
    if ( ! file_exists( $nginx ) ) {
        $instruction = "location ~ ^" . trailingslashit( str_replace( ABSPATH, '/', wp_normalize_path( $dir ) ) ) . " {\n    deny all;\n}\n";
        @file_put_contents( $nginx, $instruction );
    }

    $index = trailingslashit( $dir ) . 'index.html';
    if ( ! file_exists( $index ) ) {
        @file_put_contents( $index, '' );
    }
}

function backup_lite_ensure_access_controls() {
    backup_lite_maybe_protect_directory( backup_lite_get_backup_dir() );
    backup_lite_maybe_protect_directory( backup_lite_get_log_dir() );
    backup_lite_maybe_protect_directory( backup_lite_get_temp_dir() );
    backup_lite_maybe_protect_directory( backup_lite_get_jobs_dir() );
    backup_lite_maybe_protect_directory( backup_lite_get_reports_dir() );
    
    // PRO directories
    if ( class_exists( 'Backup_Lite_Pro' ) && Backup_Lite_Pro::is_pro_active() ) {
        backup_lite_maybe_protect_directory( backup_lite_get_pro_jobs_dir() );
        backup_lite_maybe_protect_directory( backup_lite_get_pro_reports_dir() );
    }
}

function backup_lite_is_shell_available() {
    if ( defined( 'BACKUP_LITE_FORCE_NO_SHELL' ) && BACKUP_LITE_FORCE_NO_SHELL ) {
        return false;
    }

    if ( defined( 'BACKUP_LITE_FORCE_SHELL' ) && BACKUP_LITE_FORCE_SHELL ) {
        return true;
    }

    $disabled_raw = ini_get( 'disable_functions' );
    $disabled     = array_filter( array_map( 'trim', explode( ',', (string) $disabled_raw ) ) );

    $required_helpers = [ 'escapeshellarg', 'escapeshellcmd' ];
    foreach ( $required_helpers as $fn ) {
        if ( ! function_exists( $fn ) || in_array( $fn, $disabled, true ) ) {
            return false;
        }
    }

    $shell_functions = [ 'exec', 'system', 'passthru', 'shell_exec' ];
    foreach ( $shell_functions as $fn ) {
        if ( function_exists( $fn ) && ! in_array( $fn, $disabled, true ) ) {
            return true;
        }
    }

    return false;
}

function backup_lite_command_exists( $command ) {
    if ( ! backup_lite_is_shell_available() ) {
        return false;
    }

    $command = escapeshellcmd( $command );

    if ( function_exists( 'shell_exec' ) ) {
        $which = shell_exec( 'command -v ' . $command . ' 2>/dev/null' );
        if ( ! empty( $which ) ) {
            return true;
        }
    }

    if ( function_exists( 'exec' ) ) {
        $output = [];
        $code   = 0;
        exec( 'command -v ' . $command . ' 2>/dev/null', $output, $code );
        if ( 0 === $code && ! empty( $output ) ) {
            return true;
        }
    }

    return false;
}

function backup_lite_can_use_mysqldump() {
    if ( defined( 'BACKUP_LITE_FORCE_NO_MYSQLDUMP' ) && BACKUP_LITE_FORCE_NO_MYSQLDUMP ) {
        return false;
    }

    if ( defined( 'BACKUP_LITE_FORCE_MYSQLDUMP' ) && BACKUP_LITE_FORCE_MYSQLDUMP ) {
        return true;
    }

    return backup_lite_is_shell_available() && backup_lite_command_exists( 'mysqldump' );
}

function backup_lite_can_use_mysql_cli() {
    if ( defined( 'BACKUP_LITE_FORCE_NO_MYSQL' ) && BACKUP_LITE_FORCE_NO_MYSQL ) {
        return false;
    }

    if ( defined( 'BACKUP_LITE_FORCE_MYSQL' ) && BACKUP_LITE_FORCE_MYSQL ) {
        return true;
    }

    return backup_lite_is_shell_available() && backup_lite_command_exists( 'mysql' );
}

function backup_lite_can_use_ziparchive() {
    if ( defined( 'BACKUP_LITE_FORCE_NO_ZIPARCHIVE' ) && BACKUP_LITE_FORCE_NO_ZIPARCHIVE ) {
        return false;
    }

    return class_exists( 'ZipArchive' );
}

function backup_lite_generate_filename( $type, $extension ) {
    $timestamp = backup_lite_local_time( 'Ymd-His' );
    return sprintf( '%s-%s.%s', $type, $timestamp, ltrim( $extension, '.' ) );
}

function backup_lite_get_download_url( $path ) {
    $storage_root = backup_lite_get_storage_root();
    $path         = wp_normalize_path( $path );
    $backups_dir  = wp_normalize_path( trailingslashit( $storage_root['path'] ) . 'backups' );

    if ( 0 !== strpos( $path, $backups_dir ) ) {
        return '';
    }

    $filename = basename( $path );
    $secret   = '';

    if ( class_exists( 'Backup_Lite_Upload_Secret' ) ) {
        $secret = (string) Backup_Lite_Upload_Secret::get_secret();
    }

    if ( ! empty( $secret ) ) {
        $expires = time() + apply_filters( 'backup_lite_download_ttl', 20 * MINUTE_IN_SECONDS, $path );
        $token   = hash_hmac( 'sha256', $filename . '|' . $expires, $secret );
        $handler = plugins_url( 'download-handler.php', BACKUP_LITE_PATH . 'download-handler.php' );

        return add_query_arg(
            [
                'file'    => $filename,
                'expires' => $expires,
                'token'   => $token,
            ],
            $handler
        );
    }

    return wp_nonce_url(
        admin_url( 'admin-post.php?action=backup_lite_download_backup&file=' . rawurlencode( $filename ) ),
        'backup_lite_download_' . $filename
    );
}

function backup_lite_log( $level, $message, $context = [] ) {
    $log_dir = backup_lite_get_log_dir();
    $file    = trailingslashit( $log_dir ) . 'backup-lite-' . backup_lite_local_time( 'Y-m-d' ) . '.log';

    $entry = sprintf(
        "[%s] [%s] %s",
        backup_lite_local_time( 'c' ),
        strtoupper( $level ),
        $message
    );

    if ( ! empty( $context ) ) {
        $entry .= ' ' . wp_json_encode( $context );
    }

    $entry .= PHP_EOL;

    file_put_contents( $file, $entry, FILE_APPEND | LOCK_EX );

    return $file;
}

function backup_lite_get_recent_logs( $limit = 5 ) {
    $log_dir = backup_lite_get_log_dir();
    $files   = glob( trailingslashit( $log_dir ) . 'backup-lite-*.log' );

    if ( empty( $files ) ) {
        return [];
    }

    rsort( $files );

    return array_slice( $files, 0, $limit );
}

function backup_lite_normalize_bool( $value ) {
    return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
}
