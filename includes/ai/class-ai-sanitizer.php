<?php
/**
 * AI payload sanitizer (Phase 0 stub).
 *
 * @package MusederRestoreOne
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Museder_AI_Sanitizer {
    /**
     * Sanitize scan payload.
     *
     * Phase 1: minimal, deterministic, and safe. No external network calls.
     * - Collects a site summary on the server side (ignores client-provided details).
     * - Avoids PII and content collection; returns only coarse signals for rules.
     *
     * @param mixed $payload Raw payload.
     * @return array<string,mixed>
     */
    public static function sanitize_payload( $payload ): array {
        $raw = is_array( $payload ) ? $payload : [];

        // Optional "mode" switch (future expansion). Kept but never trusted for data collection.
        $mode = isset( $raw['mode'] ) ? sanitize_key( (string) $raw['mode'] ) : '';

        $summary = [
            'payload_version' => 1,
            'collected_at_gmt' => function_exists( 'current_time' ) ? (string) current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ),
            'site' => [
                'host'     => self::get_site_host(),
                'timezone' => self::get_timezone_string(),
            ],
            'runtime' => [
                'wp_version'  => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '',
                'php_version' => function_exists( 'phpversion' ) ? (string) phpversion() : '',
            ],
            'environment' => self::get_environment_signals(),
            'plugins'     => self::get_enabled_plugins(),
            'backup'      => self::get_backup_signals(),
            'storage'     => self::get_storage_signals(),
            'logs'        => self::get_log_signals(),
        ];

        if ( $mode ) {
            $summary['mode'] = $mode;
        }

        return $summary;
    }

    /**
     * Get site host only (no path/query).
     *
     * @return string
     */
    private static function get_site_host(): string {
        $url = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '';
        $host = '';
        if ( $url ) {
            $parts = wp_parse_url( $url );
            if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
                $host = (string) $parts['host'];
            }
        }
        return sanitize_text_field( $host );
    }

    /**
     * Get timezone string (prefers IANA name; falls back to UTC offset label).
     *
     * @return string
     */
    private static function get_timezone_string(): string {
        if ( function_exists( 'wp_timezone_string' ) ) {
            $tz = (string) wp_timezone_string();
            if ( $tz ) {
                return sanitize_text_field( $tz );
            }
        }

        $tz = (string) get_option( 'timezone_string', '' );
        if ( $tz ) {
            return sanitize_text_field( $tz );
        }

        $offset = (float) get_option( 'gmt_offset', 0 );
        if ( 0.0 === $offset ) {
            return 'UTC';
        }

        $sign = $offset >= 0 ? '+' : '-';
        $abs  = abs( $offset );
        $hours = (int) floor( $abs );
        $mins  = (int) round( ( $abs - $hours ) * 60 );
        return sprintf( 'UTC%s%02d:%02d', $sign, $hours, $mins );
    }

    /**
     * Environment signals used by deterministic rules.
     *
     * @return array<string,bool>
     */
    private static function get_environment_signals(): array {
        $signals = [
            'shell'      => false,
            'mysqldump'  => false,
            'mysql_cli'  => false,
            'ziparchive' => false,
        ];

        if ( class_exists( 'Backup_Lite_UI' ) && method_exists( 'Backup_Lite_UI', 'get_environment_status' ) ) {
            $status = Backup_Lite_UI::get_environment_status();
            if ( is_array( $status ) ) {
                foreach ( $signals as $k => $_ ) {
                    $signals[ $k ] = ! empty( $status[ $k ] );
                }
            }
        }

        return $signals;
    }

    /**
     * Enabled plugin list (slug + name only; excludes versions/authors).
     *
     * @return array{count:int,items:array<int,array{slug:string,name:string}>}
     */
    private static function get_enabled_plugins(): array {
        $active = get_option( 'active_plugins', [] );
        if ( ! is_array( $active ) ) {
            $active = [];
        }

        // Ensure get_plugins() is available for names.
        if ( ! function_exists( 'get_plugins' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all = function_exists( 'get_plugins' ) ? get_plugins() : [];
        if ( ! is_array( $all ) ) {
            $all = [];
        }

        $items = [];
        foreach ( $active as $plugin_file ) {
            $plugin_file = (string) $plugin_file;
            if ( '' === $plugin_file ) {
                continue;
            }

            $slug = $plugin_file;
            if ( strpos( $slug, '/' ) !== false ) {
                $slug = substr( $slug, 0, (int) strpos( $slug, '/' ) );
            } else {
                $slug = basename( $slug, '.php' );
            }
            $slug = sanitize_key( $slug );

            $name = '';
            if ( isset( $all[ $plugin_file ]['Name'] ) ) {
                $name = (string) $all[ $plugin_file ]['Name'];
            }
            $name = sanitize_text_field( wp_strip_all_tags( $name ) );

            $items[] = [
                'slug' => $slug,
                'name' => $name,
            ];
        }

        return [
            'count' => count( $items ),
            'items' => $items,
        ];
    }

    /**
     * Backup-related signals used by deterministic rules (no content).
     *
     * @return array<string,mixed>
     */
    private static function get_backup_signals(): array {
        $has_schedule = false;
        $next_run_ts  = 0;
        $last_run_ts  = 0;
        $activity     = [ 'success' => 0, 'failed' => 0, 'pending' => 0 ];

        if ( class_exists( 'Backup_Lite_Schedule_Handler' ) && method_exists( 'Backup_Lite_Schedule_Handler', 'list_schedules' ) ) {
            $schedules = Backup_Lite_Schedule_Handler::list_schedules();
            $has_schedule = is_array( $schedules ) && ! empty( $schedules );
        }

        if ( class_exists( 'Backup_Lite_Dashboard' ) ) {
            if ( method_exists( 'Backup_Lite_Dashboard', 'get_schedule_overview' ) ) {
                $overview = Backup_Lite_Dashboard::get_schedule_overview();
                if ( is_array( $overview ) ) {
                    $next_run_ts = isset( $overview['next_run'] ) && is_numeric( $overview['next_run'] ) ? (int) $overview['next_run'] : 0;
                    $last_run_ts = isset( $overview['last_run_timestamp'] ) && is_numeric( $overview['last_run_timestamp'] ) ? (int) $overview['last_run_timestamp'] : 0;
                }
            }

            if ( method_exists( 'Backup_Lite_Dashboard', 'get_activity_stats' ) ) {
                $stats = Backup_Lite_Dashboard::get_activity_stats( 7 );
                if ( is_array( $stats ) ) {
                    $activity['success'] = isset( $stats['success'] ) ? (int) $stats['success'] : 0;
                    $activity['failed']  = isset( $stats['failed'] ) ? (int) $stats['failed'] : 0;
                    $activity['pending'] = isset( $stats['pending'] ) ? (int) $stats['pending'] : 0;
                }
            }
        }

        return [
            'has_schedule' => (bool) $has_schedule,
            'next_run_ts_utc' => $next_run_ts > 0 ? $next_run_ts : null,
            'last_run_ts_utc' => $last_run_ts > 0 ? $last_run_ts : null,
            'activity_last_7d' => $activity,
            'has_success_last_7d' => $activity['success'] > 0,
        ];
    }

    /**
     * Storage signals (no absolute paths, no exact sizes).
     *
     * @return array<string,mixed>
     */
    private static function get_storage_signals(): array {
        $upload_dir = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : [ 'basedir' => '' ];
        $basedir    = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
        $basedir    = $basedir ? wp_normalize_path( $basedir ) : '';

        $storage_root = $basedir ? trailingslashit( $basedir ) . 'museder-restoreone' : '';
        $backup_dir   = $storage_root ? trailingslashit( $storage_root ) . 'backups' : '';
        $temp_dir     = $storage_root ? trailingslashit( $storage_root ) . 'temp' : '';
        $reports_dir  = $storage_root ? trailingslashit( $storage_root ) . 'reports' : '';
        $log_dir      = $storage_root ? trailingslashit( $storage_root ) . 'logs' : '';

        $dirs = [
            'storage_root' => $storage_root,
            'backup_dir'   => $backup_dir,
            'temp_dir'     => $temp_dir,
            'reports_dir'  => $reports_dir,
            'log_dir'      => $log_dir,
        ];

        $out = [
            'dirs' => [
                'storage_root' => self::dir_signal( $dirs['storage_root'] ),
                'backup_dir'   => self::dir_signal( $dirs['backup_dir'] ),
                'temp_dir'     => self::dir_signal( $dirs['temp_dir'] ),
                'reports_dir'  => self::dir_signal( $dirs['reports_dir'] ),
                'log_dir'      => self::dir_signal( $dirs['log_dir'] ),
            ],
            'free_space' => [
                'range' => self::free_space_range( $storage_root ),
            ],
        ];

        return $out;
    }

    /**
     * Log signals (counts only; does not include content).
     *
     * @return array<string,mixed>
     */
    private static function get_log_signals(): array {
        $count = 0;
        if ( class_exists( 'Backup_Lite_Log_Handler' ) && method_exists( 'Backup_Lite_Log_Handler', 'get_logs' ) ) {
            $logs = Backup_Lite_Log_Handler::get_logs( 0 );
            if ( is_array( $logs ) ) {
                $count = count( $logs );
            }
        }

        return [
            'log_files_count' => (int) $count,
            'has_logs'        => $count > 0,
        ];
    }

    /**
     * Directory signals: existence, writable, and presence of protection files.
     *
     * @param string $dir Directory path.
     * @return array<string,bool>
     */
    private static function dir_signal( string $dir ): array {
        if ( '' === $dir ) {
            return [
                'exists' => false,
                'writable' => false,
                'protected' => false,
            ];
        }

        $exists   = is_dir( $dir );
        $writable = $exists ? wp_is_writable( $dir ) : false;

        $protected = false;
        if ( $exists ) {
            $protected = file_exists( trailingslashit( $dir ) . '.htaccess' )
                && file_exists( trailingslashit( $dir ) . 'nginx-deny.conf' )
                && file_exists( trailingslashit( $dir ) . 'index.html' );
        }

        return [
            'exists'    => (bool) $exists,
            'writable'  => (bool) $writable,
            'protected' => (bool) $protected,
        ];
    }

    /**
     * Convert disk free space into a coarse range label.
     *
     * @param string $path Path to check.
     * @return string One of: unknown|low|ok
     */
    private static function free_space_range( string $path ): string {
        if ( '' === $path || ! is_dir( $path ) || ! function_exists( 'disk_free_space' ) ) {
            return 'unknown';
        }

        $bytes = @disk_free_space( $path );
        if ( false === $bytes || ! is_numeric( $bytes ) ) {
            return 'unknown';
        }

        $bytes = (float) $bytes;
        if ( $bytes < ( 100 * 1024 * 1024 ) ) { // < 100MB
            return 'low';
        }

        return 'ok';
    }
}


