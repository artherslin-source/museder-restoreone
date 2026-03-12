<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'museder_is_pro_active' ) ) {
    /**
     * Unified PRO activation check wrapper.
     *
     * IMPORTANT: New modules (e.g., AI scaffolding) should only depend on this
     * function instead of calling Museder_Restoreone_Pro::is_pro_active() directly.
     *
     * @return bool
     */
    function museder_is_pro_active(): bool {
        if ( class_exists( 'Museder_Restoreone_Pro' ) && method_exists( 'Museder_Restoreone_Pro', 'is_pro_active' ) ) {
            return (bool) Museder_Restoreone_Pro::is_pro_active();
        }

        return false;
    }
}

if ( ! function_exists( 'museder_restoreone_local_time' ) ) {
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
    function museder_restoreone_local_time( $format = 'Y-m-d H:i:s', $timestamp = null ) {
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

// This plugin requires WordPress 5.8+ where wp_mkdir_p() and sanitize_file_name() are always available.
// Avoid including core files directly to reduce WP.org review risk.

// WordPress 5.8+ includes size_format() in core.
// We require WordPress 5.8+, so no polyfill is needed.
// All calls to size_format() will use WordPress core function.

/**
 * Return the base directory used by Museder RestoreOne within uploads.
 *
 * @return array{path:string,url:string}
 */
function museder_restoreone_get_storage_root() {
    $upload_dir = wp_upload_dir();
    $base       = trailingslashit( $upload_dir['basedir'] ) . 'museder-restoreone';
    $url        = trailingslashit( $upload_dir['baseurl'] ) . 'museder-restoreone';

    museder_restoreone_ensure_directory( $base );
    museder_restoreone_maybe_protect_directory( $base );

    return [
        'path' => $base,
        'url'  => $url,
    ];
}

function museder_restoreone_get_backup_dir() {
    $root = museder_restoreone_get_storage_root();
    $dir  = trailingslashit( $root['path'] ) . 'backups';
    museder_restoreone_ensure_directory( $dir );

    return $dir;
}

/**
 * Return all known backup directories (current + legacy).
 *
 * @return array<int,string> Absolute directory paths.
 */
function museder_restoreone_get_all_backup_dirs() {
    $dirs = [];

    $current = museder_restoreone_get_backup_dir();
    if ( $current ) {
        $dirs[] = wp_normalize_path( $current );
    }

    foreach ( museder_restoreone_get_legacy_storage_roots() as $legacy_root ) {
        $legacy_backups = trailingslashit( wp_normalize_path( $legacy_root ) ) . 'backups';
        if ( is_dir( $legacy_backups ) ) {
            $dirs[] = wp_normalize_path( $legacy_backups );
        }
    }

    $dirs = array_values( array_unique( array_filter( $dirs ) ) );
    return $dirs;
}

/**
 * Check if a path is an absolute path.
 *
 * @param string $path Path to check.
 * @return bool True if absolute path, false otherwise.
 */
function museder_restoreone_is_absolute_path( $path ) {
    return (bool) preg_match( '#^([a-zA-Z]:[\\\\/]|\\\\\\\\|/)#', $path );
}

/**
 * Get absolute path to a backup archive within the backup directory.
 *
 * Handles both absolute paths and file names. If the input is already a valid absolute path
 * that exists and is readable, returns it directly. Otherwise, treats the input as a file name
 * or relative path and constructs the full path within the backup directory.
 *
 * @param string $file Backup file name, relative path, or absolute path.
 * @return string|false Absolute path if file exists and is readable, false otherwise.
 */
function museder_restoreone_get_backup_path( $file ) {
    if ( empty( $file ) ) {
        museder_restoreone_log( 'warning', 'museder_restoreone_get_backup_path called with empty file parameter.' );
        return false;
    }

    $backup_dirs = museder_restoreone_get_all_backup_dirs();
    if ( empty( $backup_dirs ) ) {
        museder_restoreone_log( 'error', 'Backup directories not available.', [ 'file' => $file ] );
        return false;
    }

    // If already an absolute path, validate it stays within a known backups directory.
    if ( museder_restoreone_is_absolute_path( $file ) && file_exists( $file ) && is_readable( $file ) ) {
        $real_candidate = realpath( $file );
        if ( ! $real_candidate ) {
            return false;
        }
        foreach ( $backup_dirs as $dir ) {
            $real_dir = realpath( $dir );
            if ( $real_dir && 0 === strpos( $real_candidate, $real_dir ) ) {
                return $real_candidate;
            }
        }
        return false;
    }

    // Sanitize file name to handle any special characters
    $sanitized_file = sanitize_file_name( basename( $file ) );
    foreach ( $backup_dirs as $backups_dir ) {
        $candidate = trailingslashit( $backups_dir ) . $sanitized_file;

        $real_backups_dir = realpath( $backups_dir );
        if ( ! $real_backups_dir ) {
            continue;
        }

        $real_candidate = file_exists( $candidate ) ? realpath( $candidate ) : false;
        if ( ! $real_candidate ) {
            continue;
        }

        if ( 0 !== strpos( $real_candidate, $real_backups_dir ) ) {
            continue;
        }

        if ( ! is_readable( $real_candidate ) ) {
            continue;
        }

        return $real_candidate;
    }

    // Not found in any known directory.
    museder_restoreone_log( 'warning', 'Backup file not found in known directories.', [
        'file' => $file,
        'sanitized_file' => $sanitized_file,
        'dirs' => $backup_dirs,
    ] );
    return false;
}

/**
 * Format a timestamp or datetime string into site-local time using WordPress timezone.
 *
 * @param int|string $time   Unix timestamp (UTC) or datetime string.
 * @param string     $format Date format; default is site date + time format.
 * @return string Formatted date/time in site's local timezone.
 */
/**
 * Format duration in seconds to human-readable string (e.g., "32m 38s" or "1h 02m").
 *
 * @param int|null $seconds Duration in seconds.
 * @return string Formatted duration string, or empty string if invalid.
 */
function museder_restoreone_format_duration( $seconds ) {
    if ( ! is_numeric( $seconds ) || $seconds < 0 ) {
        return '';
    }

    $seconds = (int) $seconds;

    if ( $seconds === 0 ) {
        return '00m 00s';
    }

    $hours   = floor( $seconds / 3600 );
    $minutes = floor( ( $seconds % 3600 ) / 60 );
    $secs    = $seconds % 60;

    if ( $hours > 0 ) {
        return sprintf( '%02dh %02dm %02ds', $hours, $minutes, $secs );
    }

    return sprintf( '%02dm %02ds', $minutes, $secs );
}

function museder_restoreone_format_local_time( $time, $format = '' ) {
    if ( empty( $format ) ) {
        $format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
    }

    // Normalize to Unix timestamp.
    if ( is_numeric( $time ) ) {
        $timestamp = (int) $time;
    } else {
        $timestamp = strtotime( (string) $time );
    }

    if ( ! $timestamp ) {
        return '';
    }

    // Use wp_date() for WordPress 5.3+ (handles timezone conversion automatically)
    if ( function_exists( 'wp_date' ) ) {
        return wp_date( $format, $timestamp, wp_timezone() );
    }

    // Fallback for older WordPress versions
    $gmt_offset = get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
    $local_timestamp = $timestamp + $gmt_offset;
    
    return date_i18n( $format, $local_timestamp );
}

/**
 * Parse legacy timestamp string to UTC Unix timestamp.
 * 
 * Handles old history entries that may have stored timestamps as local time strings.
 * 
 * @param string|int $timestamp_legacy Legacy timestamp (string or numeric).
 * @return int UTC Unix timestamp, or 0 if parsing fails.
 */
function museder_restoreone_parse_legacy_timestamp( $timestamp_legacy ) {
    if ( empty( $timestamp_legacy ) ) {
        return 0;
    }
    
    // If already numeric, treat as UTC Unix timestamp
    if ( is_numeric( $timestamp_legacy ) ) {
        return (int) $timestamp_legacy;
    }
    
    // Try parsing as UTC first
    $parsed = strtotime( $timestamp_legacy . ' UTC' );
    if ( false !== $parsed && $parsed > 0 ) {
        return $parsed;
    }
    
    // Fallback: try parsing as-is (may be old local time entry)
    $parsed = strtotime( $timestamp_legacy );
    if ( false !== $parsed && $parsed > 0 ) {
        // If WordPress timezone is available, try to convert local time to UTC
        if ( function_exists( 'wp_timezone' ) ) {
            try {
                $timezone = wp_timezone();
                $date = new DateTime( $timestamp_legacy, $timezone );
                return $date->getTimestamp();
            } catch ( Exception $e ) {
                // If conversion fails, return parsed timestamp as-is
                return $parsed;
            }
        }
        return $parsed;
    }
    
    return 0;
}

function museder_restoreone_get_log_dir() {
    $root = museder_restoreone_get_storage_root();
    $dir  = trailingslashit( $root['path'] ) . 'logs';

    museder_restoreone_ensure_directory( $dir );
    museder_restoreone_maybe_protect_directory( $dir );

    return $dir;
}

/**
 * Return legacy storage roots used by older versions (best-effort).
 *
 * @return array<int,string> Array of absolute legacy roots (e.g. .../uploads/backup-lite)
 */
function museder_restoreone_get_legacy_storage_roots() {
    $candidates = [];

    $upload_dir = wp_upload_dir();
    if ( ! empty( $upload_dir['basedir'] ) ) {
        $candidates[] = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) . 'backup-lite' );
    }

    $roots = [];
    foreach ( array_unique( $candidates ) as $path ) {
        if ( $path && is_dir( $path ) ) {
            $roots[] = $path;
        }
    }

    return array_values( array_unique( array_filter( $roots ) ) );
}

/**
 * Return legacy log directories used by older versions (best-effort).
 *
 * @return array<int,string> Array of absolute legacy log directories (e.g. .../uploads/backup-lite-logs)
 */
function museder_restoreone_get_legacy_log_dirs() {
    $candidates = [];

    $upload_dir = wp_upload_dir();
    if ( ! empty( $upload_dir['basedir'] ) ) {
        $candidates[] = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) . 'backup-lite-logs' );
    }

    $dirs = [];
    foreach ( array_unique( $candidates ) as $path ) {
        if ( $path && is_dir( $path ) ) {
            $dirs[] = $path;
        }
    }

    return array_values( array_unique( array_filter( $dirs ) ) );
}

/**
 * Attempt to migrate legacy storage directories into the wp_upload_dir-based museder-restoreone root.
 *
 * This runs in a best-effort manner: failures are logged but will not break the plugin.
 *
 * @return array<string,mixed> Migration report.
 */
function museder_restoreone_migrate_legacy_storage() {
    $flag = (int) get_option( 'museder_restoreone_legacy_storage_migrated', 0 );
    if ( 1 === $flag ) {
        return [ 'skipped' => true ];
    }

    $report = [
        'skipped'  => false,
        'migrated' => [],
        'errors'   => [],
    ];

    $root = museder_restoreone_get_storage_root();
    $new_root = isset( $root['path'] ) ? wp_normalize_path( $root['path'] ) : '';
    if ( '' === $new_root ) {
        $report['errors'][] = 'new_root_missing';
        return $report;
    }

    // Ensure root exists (but do not pre-create subdirectories so we can rename legacy folders atomically when possible).
    museder_restoreone_ensure_directory( $new_root );

    $moves = [];

    foreach ( museder_restoreone_get_legacy_storage_roots() as $legacy_root ) {
        $legacy_root = wp_normalize_path( $legacy_root );
        $moves[] = [ trailingslashit( $legacy_root ) . 'backups', trailingslashit( $new_root ) . 'backups' ];
        $moves[] = [ trailingslashit( $legacy_root ) . 'temp', trailingslashit( $new_root ) . 'temp' ];
        $moves[] = [ trailingslashit( $legacy_root ) . 'jobs', trailingslashit( $new_root ) . 'jobs' ];
        $moves[] = [ trailingslashit( $legacy_root ) . 'reports', trailingslashit( $new_root ) . 'reports' ];
        $moves[] = [ trailingslashit( $legacy_root ) . 'pro/jobs', trailingslashit( $new_root ) . 'pro/jobs' ];
        $moves[] = [ trailingslashit( $legacy_root ) . 'pro/reports', trailingslashit( $new_root ) . 'pro/reports' ];
    }

    foreach ( museder_restoreone_get_legacy_log_dirs() as $legacy_logs ) {
        $legacy_logs = wp_normalize_path( $legacy_logs );
        $moves[] = [ $legacy_logs, trailingslashit( $new_root ) . 'logs' ];
    }

    $moves = array_values( array_unique( array_filter( $moves ) ) );

    foreach ( $moves as $pair ) {
        $from = wp_normalize_path( $pair[0] );
        $to   = wp_normalize_path( $pair[1] );

        if ( ! $from || ! is_dir( $from ) ) {
            continue;
        }

        if ( $to && ! file_exists( $to ) ) {
            museder_restoreone_ensure_directory( dirname( $to ) );

            // Prefer atomic rename when possible (same filesystem).
            // @phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename
            $ok = @rename( $from, $to );
            // @phpcs:enable WordPress.WP.AlternativeFunctions.rename_rename

            if ( $ok ) {
                $report['migrated'][] = [ 'from' => $from, 'to' => $to, 'method' => 'rename' ];
                continue;
            }
        }

        // Fallback: keep legacy directory in place; dual-read will continue to work.
        $report['errors'][] = [ 'from' => $from, 'to' => $to, 'code' => 'rename_failed' ];
    }

    update_option( 'museder_restoreone_legacy_storage_migrated', 1, false );

    if ( function_exists( 'museder_restoreone_log' ) ) {
        museder_restoreone_log( 'info', 'Legacy storage migration completed.', $report );
    }

    return $report;
}

function museder_restoreone_get_temp_dir() {
    $root = museder_restoreone_get_storage_root();
    $dir  = trailingslashit( $root['path'] ) . 'temp';
    museder_restoreone_ensure_directory( $dir );
    museder_restoreone_maybe_protect_directory( $dir );

    return $dir;
}

function museder_restoreone_get_jobs_dir() {
    $root = museder_restoreone_get_storage_root();
    $dir  = trailingslashit( $root['path'] ) . 'jobs';
    museder_restoreone_ensure_directory( $dir );
    museder_restoreone_maybe_protect_directory( $dir );

    return $dir;
}

function museder_restoreone_get_reports_dir() {
    $root = museder_restoreone_get_storage_root();
    $dir  = trailingslashit( $root['path'] ) . 'reports';
    museder_restoreone_ensure_directory( $dir );
    museder_restoreone_maybe_protect_directory( $dir );

    return $dir;
}

/**
 * Get PRO jobs directory.
 *
 * @return string
 */
function museder_restoreone_get_pro_jobs_dir() {
    $root = museder_restoreone_get_storage_root();
    $dir  = trailingslashit( $root['path'] ) . 'pro/jobs';
    museder_restoreone_ensure_directory( $dir );
    museder_restoreone_maybe_protect_directory( $dir );

    return $dir;
}

/**
 * Get PRO reports directory.
 *
 * @return string
 */
function museder_restoreone_get_pro_reports_dir() {
    $root = museder_restoreone_get_storage_root();
    $dir  = trailingslashit( $root['path'] ) . 'pro/reports';
    museder_restoreone_ensure_directory( $dir );
    museder_restoreone_maybe_protect_directory( $dir );

    return $dir;
}

function museder_restoreone_get_restore_history_path() {
    $root = museder_restoreone_get_storage_root();
    $path = trailingslashit( $root['path'] ) . 'restore-history.json';

    if ( ! file_exists( $path ) ) {
        @file_put_contents( $path, wp_json_encode( [] ), LOCK_EX );
        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod
        // chmod is required here to make backup archives readable by the web server user on some hosts.
        @chmod( $path, 0640 );
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_chmod
    }

    return $path;
}

function museder_restoreone_get_restore_history( $limit = 0 ) {
    $path = museder_restoreone_get_restore_history_path();

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

    // Sanitize decoded history entries (defensive; file lives under plugin-controlled uploads storage).
    $sanitized = [];
    foreach ( $data as $row ) {
        if ( ! is_array( $row ) ) {
            continue;
        }
        $sanitized[] = museder_restoreone_sanitize_restore_history_entry( $row );
    }
    $data = $sanitized;

    if ( $limit > 0 ) {
        return array_slice( $data, 0, $limit );
    }

    return $data;
}

/**
 * Sanitize a restore history entry (stored in JSON under uploads).
 *
 * @param array $entry
 * @return array
 */
function museder_restoreone_sanitize_restore_history_entry( array $entry ) {
    $allowed_results = [ 'running', 'success', 'failed', 'cancelled', 'pending' ];

    $job_id  = isset( $entry['job_id'] ) ? sanitize_text_field( (string) $entry['job_id'] ) : '';
    $file    = isset( $entry['file'] ) ? sanitize_file_name( basename( (string) $entry['file'] ) ) : '';
    $result  = isset( $entry['result'] ) ? sanitize_text_field( (string) $entry['result'] ) : '';
    if ( '' !== $result && ! in_array( $result, $allowed_results, true ) ) {
        $result = '';
    }

    $out = [
        'job_id'        => $job_id,
        'timestamp_utc' => isset( $entry['timestamp_utc'] ) ? (int) $entry['timestamp_utc'] : 0,
        'date'          => isset( $entry['date'] ) ? sanitize_text_field( (string) $entry['date'] ) : '',
        'file'          => $file,
        'result'        => $result,
        'timestamp'     => isset( $entry['timestamp'] ) ? sanitize_text_field( (string) $entry['timestamp'] ) : '',
        'log'           => isset( $entry['log'] ) ? sanitize_text_field( (string) $entry['log'] ) : '',
        'message'       => isset( $entry['message'] ) ? sanitize_text_field( (string) $entry['message'] ) : '',
        'duration_seconds' => isset( $entry['duration_seconds'] ) ? (int) $entry['duration_seconds'] : ( isset( $entry['restore_duration_seconds'] ) ? (int) $entry['restore_duration_seconds'] : 0 ),
        'restore_started_at'   => isset( $entry['restore_started_at'] ) ? (int) $entry['restore_started_at'] : 0,
        'restore_completed_at' => isset( $entry['restore_completed_at'] ) ? (int) $entry['restore_completed_at'] : 0,
        'restore_duration_seconds' => isset( $entry['restore_duration_seconds'] ) ? (int) $entry['restore_duration_seconds'] : 0,
    ];

    // Preserve any known extra fields as sanitized strings.
    foreach ( [ 'extra', 'details' ] as $maybe ) {
        if ( isset( $entry[ $maybe ] ) ) {
            $out[ $maybe ] = is_array( $entry[ $maybe ] ) ? array_map( 'sanitize_text_field', $entry[ $maybe ] ) : sanitize_text_field( (string) $entry[ $maybe ] );
        }
    }

    // Remove empty keys to keep JSON smaller.
    return array_filter(
        $out,
        static function ( $v ) {
            if ( is_int( $v ) ) {
                return true;
            }
            return '' !== $v && null !== $v;
        }
    );
}

function museder_restoreone_append_restore_history( $entry ) {
    if ( empty( $entry ) || ! is_array( $entry ) ) {
        return false;
    }

    $entry = museder_restoreone_sanitize_restore_history_entry( $entry );
    $history = museder_restoreone_get_restore_history();
    array_unshift( $history, $entry );

    $history = array_slice( $history, 0, 50 );

    $path = museder_restoreone_get_restore_history_path();

    $json = wp_json_encode( $history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

    return false !== file_put_contents( $path, $json, LOCK_EX );
}

/**
 * Upsert restore history by job_id (preferred) or append if missing.
 *
 * This supports the workflow:
 * - write an entry when restore starts: result=running
 * - update same job_id when completed: result=success/failed/cancelled
 *
 * @param array $entry
 * @return bool
 */
function museder_restoreone_upsert_restore_history( $entry ) {
    if ( empty( $entry ) || ! is_array( $entry ) ) {
        return false;
    }

    $entry = museder_restoreone_sanitize_restore_history_entry( $entry );
    $job_id = isset( $entry['job_id'] ) ? sanitize_text_field( (string) $entry['job_id'] ) : '';
    if ( '' === $job_id ) {
        return museder_restoreone_append_restore_history( $entry );
    }

    $history = museder_restoreone_get_restore_history();
    $updated = false;

    foreach ( $history as $idx => $row ) {
        $row_job = isset( $row['job_id'] ) ? (string) $row['job_id'] : '';
        if ( $row_job === $job_id ) {
            // Merge: new entry wins.
            $history[ $idx ] = array_merge( (array) $row, $entry );
            $updated = true;
            break;
        }
    }

    if ( ! $updated ) {
        array_unshift( $history, $entry );
    }

    $history = array_slice( $history, 0, 50 );

    $path = museder_restoreone_get_restore_history_path();
    $json = wp_json_encode( $history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

    // Using native file APIs on plugin-controlled storage path.
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    return false !== file_put_contents( $path, $json, LOCK_EX );
}

function museder_restoreone_create_temp_dir( $prefix = 'tmp' ) {
    $temp_base = museder_restoreone_get_temp_dir();

    if ( function_exists( 'wp_generate_password' ) ) {
        $token = wp_generate_password( 6, false, false );
    } else {
        try {
            $token = substr( bin2hex( random_bytes( 6 ) ), 0, 6 );
        } catch ( Exception $e ) {
            $token = substr( uniqid( '', true ), -6 );
        }
    }

    $unique = $prefix . '-' . museder_restoreone_local_time( 'Ymd-His' ) . '-' . $token;
    $path   = trailingslashit( $temp_base ) . $unique;

    museder_restoreone_ensure_directory( $path );

    return $path;
}

function museder_restoreone_get_chunk_path( $upload_id, $file = '' ) {
    $slug = preg_replace( '/[^a-zA-Z0-9\-_.]/', '', (string) $upload_id );
    if ( empty( $slug ) ) {
        return '';
    }

    $base = trailingslashit( museder_restoreone_get_temp_dir() ) . $slug;
    museder_restoreone_ensure_directory( $base );

    if ( $file ) {
        return trailingslashit( $base ) . ltrim( $file, '/' );
    }

    return $base;
}

function museder_restoreone_delete_directory( $directory ) {
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
            // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $file_path );
            } else {
                // Fallback for non-standard environments.
                if ( file_exists( $file_path ) ) {
                    @unlink( $file_path );
                }
            }
            // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
        }
    }

    // @plugin-check: allowed - controlled backup/restore file operation, path sanitized
    @rmdir( $directory ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- required for directory deletion, path from plugin-controlled directory
}

function museder_restoreone_cleanup_temp( $max_age = DAY_IN_SECONDS ) {
    $temp_dir = museder_restoreone_get_temp_dir();
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
                museder_restoreone_delete_directory( $path );
            }
        } elseif ( $age > $max_age ) {
            // @plugin-check: allowed - required for backup/restore file operations
            // Path is validated and sanitized before use
            if ( file_exists( $path ) ) {
                // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
                if ( function_exists( 'wp_delete_file' ) ) {
                    wp_delete_file( $path );
                } else {
                    // Fallback for non-standard environments.
                    @unlink( $path );
                }
                // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
            }
        }
    }
}

function museder_restoreone_sanitize_filename( $filename ) {
    if ( function_exists( 'sanitize_file_name' ) ) {
        return sanitize_file_name( $filename );
    }

    $filename = preg_replace( '/[^a-zA-Z0-9_\.-]/', '-', (string) $filename );
    return trim( preg_replace( '/-+/', '-', $filename ), '-' );
}

function museder_restoreone_is_allowed_backup_extension( $filename ) {
    $extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
    return in_array( $extension, [ 'zip' ], true );
}

function museder_restoreone_safe_path_join( $base, $path ) {
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

function museder_restoreone_ensure_directory( $dir ) {
    if ( ! file_exists( $dir ) ) {
        $result = wp_mkdir_p( $dir );
        if ( ! $result ) {
            museder_restoreone_log( 'error', 'ensure_directory_failed', [
                'dir' => $dir,
                'parent_exists' => file_exists( dirname( $dir ) ),
                'parent_writable' => wp_is_writable( dirname( $dir ) ),
            ] );
            return false;
        }
    }
    return true;
}

function museder_restoreone_maybe_protect_directory( $dir ) {
    museder_restoreone_ensure_directory( $dir );

    $htaccess = trailingslashit( $dir ) . '.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        $rules = "Options -Indexes\nRequire all denied\n";
        @file_put_contents( $htaccess, $rules );
    }

    $nginx = trailingslashit( $dir ) . 'nginx-deny.conf';
    if ( ! file_exists( $nginx ) ) {
        // Build an nginx location using uploads baseurl when possible (avoids relying on ABSPATH).
        $location = '';
        $uploads  = wp_upload_dir();
        $basedir  = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
        $baseurl  = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
        $basepath = $baseurl ? (string) wp_parse_url( $baseurl, PHP_URL_PATH ) : '';

        $dir_norm = wp_normalize_path( (string) $dir );
        if ( '' !== $basedir && 0 === strpos( $dir_norm, trailingslashit( $basedir ) ) && '' !== $basepath ) {
            $relative = ltrim( substr( $dir_norm, strlen( $basedir ) ), '/' );
            $location = trailingslashit( untrailingslashit( $basepath ) ) . $relative;
        }

        if ( '' !== $location ) {
            $instruction = "location ~ ^" . trailingslashit( $location ) . " {\n    deny all;\n}\n";
            @file_put_contents( $nginx, $instruction );
        }
    }

    $index = trailingslashit( $dir ) . 'index.html';
    if ( ! file_exists( $index ) ) {
        @file_put_contents( $index, '' );
    }
}

function museder_restoreone_ensure_access_controls() {
    museder_restoreone_maybe_protect_directory( museder_restoreone_get_backup_dir() );
    museder_restoreone_maybe_protect_directory( museder_restoreone_get_log_dir() );
    museder_restoreone_maybe_protect_directory( museder_restoreone_get_temp_dir() );
    museder_restoreone_maybe_protect_directory( museder_restoreone_get_jobs_dir() );
    museder_restoreone_maybe_protect_directory( museder_restoreone_get_reports_dir() );
    
    // PRO directories
    if ( museder_is_pro_active() ) {
        museder_restoreone_maybe_protect_directory( museder_restoreone_get_pro_jobs_dir() );
        museder_restoreone_maybe_protect_directory( museder_restoreone_get_pro_reports_dir() );
    }
}

/**
 * Best-effort: derive wp-content directory path without hard-coding constants.
 *
 * Uses wp_upload_dir()['basedir'] (typically .../wp-content/uploads) and falls back to WP_CONTENT_DIR.
 *
 * @return string Normalized wp-content absolute path or empty string.
 */
function museder_restoreone_get_wp_content_dir() {
    $uploads = wp_upload_dir();
    $basedir = isset( $uploads['basedir'] ) ? wp_normalize_path( (string) $uploads['basedir'] ) : '';
    if ( '' !== $basedir ) {
        $maybe = wp_normalize_path( (string) dirname( $basedir ) );
        if ( '' !== $maybe && is_dir( $maybe ) ) {
            return $maybe;
        }
    }

    // Derive wp-content from the plugin directory (wp-content/plugins/{this-plugin}).
    if ( defined( 'MUSEDER_RESTOREONE_PATH' ) ) {
        $plugin_dir  = wp_normalize_path( (string) MUSEDER_RESTOREONE_PATH );
        $plugins_dir = wp_normalize_path( rtrim( dirname( $plugin_dir ), "/\\\n\r\t " ) );
        $content_dir = wp_normalize_path( rtrim( dirname( $plugins_dir ), "/\\\n\r\t " ) );
        if ( '' !== $content_dir && is_dir( $content_dir ) ) {
            return $content_dir;
        }
    }

    return '';
}

/**
 * Best-effort: derive the WordPress install root directory.
 *
 * We prefer get_home_path() (from wp-admin/includes/file.php) when available because it
 * handles cases like WordPress installed in a subdirectory. Returns empty if derivation fails (no ABSPATH).
 *
 * @return string Normalized absolute path to WP install root or empty string.
 */
function museder_restoreone_get_wp_root_dir() {
    if ( function_exists( 'get_home_path' ) ) {
        $path = (string) get_home_path();
        $path = wp_normalize_path( rtrim( $path, "/\\\n\r\t " ) );
        if ( '' !== $path && is_dir( $path ) ) {
            return $path;
        }
    }

    // Derive from wp-content: root is typically parent of wp-content.
    $content = museder_restoreone_get_wp_content_dir();
    if ( '' !== $content ) {
        $root = wp_normalize_path( rtrim( dirname( $content ), "/\\\n\r\t " ) );
        if ( '' !== $root && is_dir( $root ) ) {
            return $root;
        }
    }

    return '';
}

/**
 * Best-effort: derive wp-content/plugins directory path.
 *
 * @return string Normalized plugins dir absolute path or empty string.
 */
function museder_restoreone_get_plugins_dir() {
    $content = museder_restoreone_get_wp_content_dir();
    if ( '' !== $content ) {
        $plugins = wp_normalize_path( trailingslashit( $content ) . 'plugins' );
        if ( is_dir( $plugins ) ) {
            return $plugins;
        }
    }

    return '';
}

/**
 * Best-effort: derive wp-content/mu-plugins directory path.
 *
 * @return string Normalized mu-plugins dir absolute path or empty string.
 */
function museder_restoreone_get_mu_plugins_dir() {
    $content = museder_restoreone_get_wp_content_dir();
    if ( '' !== $content ) {
        $mu = wp_normalize_path( trailingslashit( $content ) . 'mu-plugins' );
        if ( is_dir( $mu ) ) {
            return $mu;
        }
    }

    if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
        $fallback = wp_normalize_path( (string) WPMU_PLUGIN_DIR );
        if ( '' !== $fallback && is_dir( $fallback ) ) {
            return $fallback;
        }
    }

    return '';
}

/**
 * Attempt to invalidate OPcache entries for this plugin after upgrades.
 *
 * Some shared hosting environments keep stale opcode cache for included PHP files (e.g., includes/class-backup.php),
 * causing the site to run old logic even after updating the plugin. This routine best-effort invalidates plugin files.
 *
 * @return void
 */
function museder_restoreone_maybe_invalidate_opcache_for_plugin() {
    if ( ! function_exists( 'opcache_invalidate' ) ) {
        return;
    }

    $base = defined( 'MUSEDER_RESTOREONE_PATH' ) ? MUSEDER_RESTOREONE_PATH : '';
    if ( empty( $base ) || ! is_dir( $base ) ) {
        return;
    }

    $invalidated = 0;
    $failed      = 0;
    $targets     = [];

    // Limit scope to the plugin root and includes/ only to avoid scanning large trees.
    $roots = [
        rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR . 'museder-restoreone.php',
        rtrim( $base, '/\\' ) . DIRECTORY_SEPARATOR . 'includes',
    ];

    foreach ( $roots as $root ) {
        if ( is_file( $root ) ) {
            $targets[] = $root;
            continue;
        }

        if ( ! is_dir( $root ) ) {
            continue;
        }

        try {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $root,
                    FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
                )
            );

            foreach ( $it as $fileinfo ) {
                if ( ! $fileinfo instanceof SplFileInfo ) {
                    continue;
                }
                if ( 'php' !== strtolower( (string) $fileinfo->getExtension() ) ) {
                    continue;
                }
                $targets[] = $fileinfo->getPathname();
            }
        } catch ( Exception $e ) {
            // Ignore iterator failures; we still try invalidating known files.
        }
    }

    $targets = array_values( array_unique( $targets ) );

    foreach ( $targets as $file ) {
        // Normalize path for opcache_invalidate.
        $file = (string) $file;
        if ( '' === $file || ! is_file( $file ) ) {
            continue;
        }

        $ok = @opcache_invalidate( $file, true );
        if ( $ok ) {
            $invalidated++;
        } else {
            $failed++;
        }
    }

    if ( function_exists( 'museder_restoreone_log' ) ) {
        museder_restoreone_log( 'info', 'Attempted OPcache invalidate for plugin files.', [
            'invalidated' => $invalidated,
            'failed'      => $failed,
            'files'       => count( $targets ),
        ] );
    }
}

function museder_restoreone_is_shell_available() {
    // WP.org submission hardening:
    // Do not rely on shell execution functions in the directory build.
    return false;
}

function museder_restoreone_command_exists( $command ) {
    // WP.org submission hardening: no shell probing.
    return false;
}

function museder_restoreone_can_use_mysqldump() {
    // WP.org submission hardening: do not use mysqldump in directory build.
    return false;
}

function museder_restoreone_can_use_mysql_cli() {
    // WP.org submission hardening: do not use mysql CLI in directory build.
    return false;
}

function museder_restoreone_can_use_ziparchive() {
    if ( defined( 'MUSEDER_RESTOREONE_FORCE_NO_ZIPARCHIVE' ) && MUSEDER_RESTOREONE_FORCE_NO_ZIPARCHIVE ) {
        return false;
    }

    return class_exists( 'ZipArchive' );
}

function museder_restoreone_generate_filename( $type, $extension ) {
    $timestamp = museder_restoreone_local_time( 'Ymd-His' );
    return sprintf( '%s-%s.%s', $type, $timestamp, ltrim( $extension, '.' ) );
}

function museder_restoreone_get_download_url( $path ) {
    $path         = wp_normalize_path( $path );
    $allowed_dirs = array_map( 'wp_normalize_path', museder_restoreone_get_all_backup_dirs() );

    $allowed = false;
    foreach ( $allowed_dirs as $dir ) {
        if ( '' !== $dir && 0 === strpos( $path, trailingslashit( $dir ) ) ) {
            $allowed = true;
            break;
        }
    }

    if ( ! $allowed ) {
        return '';
    }

    $filename = basename( $path );
    // Standard admin-post download URL protected by a WordPress nonce.
    // Note: We intentionally avoid generating plugin-specific “secret header” tokens to keep flows aligned with WP auth/nonce.
    return wp_nonce_url(
        admin_url( 'admin-post.php?action=museder_restoreone_download_backup&file=' . rawurlencode( $filename ) ),
        'museder_restoreone_download_backup',
        '_museder_restoreone_download_nonce'
    );
}

/**
 * Verify a time-limited download token (HMAC).
 *
 * Used for legacy-style download URLs (file/expires/token). This function lives in shared helpers so
 * both admin-post handlers and backward-compat stubs can rely on the same verification logic.
 *
 * @param string $file    Filename (basename only).
 * @param int    $expires Expiration timestamp.
 * @param string $token   Provided token.
 * @return bool True if token is valid and not expired.
 */
function museder_restoreone_verify_download_token( $file, $expires, $token ) {
    $file    = (string) $file;
    $expires = (int) $expires;
    $token   = (string) $token;

    if ( '' === $file || $expires <= 0 || '' === $token ) {
        return false;
    }

    if ( $expires < time() ) {
        return false;
    }

    // Backward-compat: verify legacy time-limited tokens (file/expires/token).
    // Use WordPress salts instead of any plugin-generated secret files.
    $key = function_exists( 'wp_salt' ) ? (string) wp_salt( 'museder_restoreone_download' ) : '';
    if ( '' === $key ) {
        return false;
    }

    $expected = hash_hmac( 'sha256', $file . '|' . $expires, $key );
    return hash_equals( $expected, $token );
}

/**
 * Best-effort: nudge WordPress cron runner without loading any core files.
 *
 * Why this exists:
 * - WP Plugin Review disallows directly including WordPress core files to force cron execution.
 * - Low-traffic sites (or test environments) may not trigger wp-cron naturally.
 *
 * This function schedules nothing by itself; callers should schedule events first,
 * then call this to *encourage* wp-cron to run soon.
 *
 * @return void
 */
function museder_restoreone_nudge_wp_cron() {
    if ( ! function_exists( 'wp_remote_post' ) || ! function_exists( 'site_url' ) || ! function_exists( 'set_transient' ) || ! function_exists( 'get_transient' ) ) {
        return;
    }

    // Rate-limit nudges to avoid spamming loopback requests.
    $last = (int) get_transient( 'museder_restoreone_wp_cron_nudge_ts' );
    if ( $last > 0 && ( time() - $last ) < 10 ) {
        return;
    }
    set_transient( 'museder_restoreone_wp_cron_nudge_ts', time(), 30 );

    $doing = sprintf( '%.22F', microtime( true ) );
    $url   = add_query_arg( 'doing_wp_cron', rawurlencode( $doing ), site_url( 'wp-cron.php' ) );

    // Non-blocking loopback request; ignore failures (some hosts disable loopback).
    wp_remote_post(
        $url,
        [
            'timeout'    => 0.01,
            'blocking'   => false,
            'user-agent' => 'Museder RestoreOne',
        ]
    );
}

function museder_restoreone_log( $level, $message, $context = [] ) {
    $log_dir = museder_restoreone_get_log_dir();
    $file    = trailingslashit( $log_dir ) . 'backup-lite-' . museder_restoreone_local_time( 'Y-m-d' ) . '.log';

    // Ensure message is always a string (avoid "Array to string conversion" notices).
    if ( ! is_string( $message ) ) {
        if ( is_scalar( $message ) ) {
            $message = (string) $message;
        } else {
            $message = wp_json_encode( $message );
        }
    }

    $entry = sprintf(
        "[%s] [%s] %s",
        museder_restoreone_local_time( 'c' ),
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

/**
 * Load bundled PclZip fallback library if needed.
 *
 * WP.org compliance: we avoid including WordPress core files directly via ABSPATH.
 *
 * @return void
 */
function museder_restoreone_require_pclzip() {
    if ( class_exists( 'PclZip' ) ) {
        return;
    }

    $path = defined( 'MUSEDER_RESTOREONE_PATH' ) ? (string) MUSEDER_RESTOREONE_PATH : '';
    if ( '' === $path ) {
        return;
    }

    $file = trailingslashit( $path ) . 'includes/vendor/pclzip/class-pclzip.php';
    if ( file_exists( $file ) ) {
        require_once $file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- fixed plugin path
    }
}

function museder_restoreone_get_recent_logs( $limit = 5 ) {
    $log_dir = museder_restoreone_get_log_dir();
    $files   = glob( trailingslashit( $log_dir ) . 'backup-lite-*.log' );

    if ( empty( $files ) ) {
        return [];
    }

    rsort( $files );

    return array_slice( $files, 0, $limit );
}

function museder_restoreone_normalize_bool( $value ) {
    return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
}

/**
 * Get list of paths that should be excluded from backup and size estimation.
 * 
 * This function provides a unified list of excluded paths used by both
 * the actual backup process and the size estimation scan to ensure consistency.
 *
 * @return array<string> Array of normalized directory paths (with trailing slashes) to exclude.
 */
if ( ! function_exists( 'museder_restoreone_get_excluded_paths' ) ) {
    function museder_restoreone_get_excluded_paths() {
        $paths = [];
        
        $normalize = static function( $path, $must_exist = false ) {
            if ( empty( $path ) ) {
                return '';
            }
            
            $normalized = wp_normalize_path( rtrim( $path, '/\\' ) );
            if ( '' === $normalized ) {
                return '';
            }
            
            if ( $must_exist && ! file_exists( $normalized ) ) {
                return '';
            }
            
            return trailingslashit( $normalized );
        };
        
        $storage = museder_restoreone_get_storage_root();
        
        if ( ! empty( $storage['path'] ) ) {
            $root = trailingslashit( $storage['path'] );
            $paths[] = $normalize( $root );
            $paths[] = $normalize( $root . 'backups' );
            $paths[] = $normalize( $root . 'logs' );
            $paths[] = $normalize( $root . 'jobs' );
            $paths[] = $normalize( $root . 'temp' );
            $paths[] = $normalize( $root . 'reports' );
            $paths[] = $normalize( $root . 'pro' );
            $paths[] = $normalize( $root . 'pro/jobs' );
            $paths[] = $normalize( $root . 'pro/reports' );
        }
        
        // Always exclude the active backup directory (even if customized) and its parent root.
        $active_backup_dir = museder_restoreone_get_backup_dir();
        $paths[] = $normalize( $active_backup_dir );
        $paths[] = $normalize( trailingslashit( dirname( $active_backup_dir ) ) );
        $paths[] = $normalize( museder_restoreone_get_temp_dir() );
        $paths[] = $normalize( museder_restoreone_get_jobs_dir() );
        $paths[] = $normalize( museder_restoreone_get_reports_dir() );
        
        // Legacy directories (only exclude when they exist to avoid false positives).
        $upload_dir = wp_upload_dir();
        $uploads_base = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';

        $legacy = [];
        if ( '' !== $uploads_base ) {
            $legacy[] = trailingslashit( $uploads_base ) . 'backup-lite';
            $legacy[] = trailingslashit( $uploads_base ) . 'backup-lite/backups';
            $legacy[] = trailingslashit( $uploads_base ) . 'backup-lite/temp';
            $legacy[] = trailingslashit( $uploads_base ) . 'backup-lite/jobs';
            $legacy[] = trailingslashit( $uploads_base ) . 'backup-lite/pro';
            $legacy[] = trailingslashit( $uploads_base ) . 'backup-lite/pro/jobs';
            $legacy[] = trailingslashit( $uploads_base ) . 'backup-lite/pro/reports';
            $legacy[] = trailingslashit( $uploads_base ) . 'backup-lite-logs';
        }
        
        foreach ( $legacy as $legacy_path ) {
            $normalized = $normalize( $legacy_path, true );
            if ( $normalized ) {
                $paths[] = $normalized;
            }
        }

        // Exclude other backup plugins' archives to prevent "backup of backups" explosions.
        // Only exclude when the directory exists to avoid false positives.
        $other_backup_dirs = [];
        if ( '' !== $uploads_base ) {
            $content_base = wp_normalize_path( dirname( $uploads_base ) );
            if ( $content_base ) {
                $other_backup_dirs[] = trailingslashit( $content_base ) . 'updraft';
            }
        }
        foreach ( $other_backup_dirs as $p ) {
            $normalized = $normalize( $p, true );
            if ( $normalized ) {
                $paths[] = $normalized;
            }
        }
        
        // Filter out empty paths
        $paths = array_filter( $paths );
        
        return array_values( array_unique( $paths ) );
    }
}
