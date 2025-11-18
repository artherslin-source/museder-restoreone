<?php
/**
 * Log handler utilities for Backup Lite.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Backup_Lite_Log_Handler {

    const LOG_DIR = 'backup-lite-logs';

    /**
     * Initialise hooks.
     */
    public static function init() {
        add_action( 'admin_init', [ __CLASS__, 'maybe_create_log_dir' ] );

        add_action( 'wp_ajax_backup_lite_fetch_logs', [ __CLASS__, 'ajax_fetch_logs' ] );
        add_action( 'wp_ajax_backup_lite_delete_log', [ __CLASS__, 'ajax_delete_log' ] );
        add_action( 'wp_ajax_backup_lite_download_log', [ __CLASS__, 'ajax_download_log' ] );
        add_action( 'wp_ajax_backup_lite_view_log', [ __CLASS__, 'ajax_view_log' ] );
    }

    /**
     * Records a structured event into the log.
     *
     * @param string $event   Event identifier.
     * @param array  $context Additional context to persist.
     * @param string $level   Log level.
     */
    public static function record_event( $event, array $context = [], $level = 'info' ) {
        $context['event'] = $event;
        backup_lite_log( $level, 'event=' . $event, $context );
    }

    /**
     * Returns recent events filtered by type.
     *
     * @param string $event Event slug.
     * @param int    $limit Maximum number of entries.
     * @return array<int,array>
     */
    public static function get_recent_events( $event, $limit = 5 ) {
        $entries = [];
        foreach ( self::iterate_recent_logs( 7 ) as $line ) {
            if ( empty( $line['context']['event'] ) || $line['context']['event'] !== $event ) {
                continue;
            }
            $entries[] = $line + [ 'status' => $line['context']['status'] ?? '' ];
            if ( count( $entries ) >= $limit ) {
                break;
            }
        }

        return $entries;
    }

    /**
     * Builds activity totals for charting.
     *
     * @param int $days Number of days to inspect.
     * @return array{success:int,failed:int,pending:int}
     */
    public static function get_activity_totals( $days = 7 ) {
        $totals = [
            'success' => 0,
            'failed'  => 0,
            'pending' => 0,
        ];

        foreach ( self::iterate_recent_logs( $days ) as $line ) {
            if ( empty( $line['context']['event'] ) ) {
                continue;
            }

            $status = $line['context']['status'] ?? '';
            if ( empty( $status ) ) {
                continue;
            }

            if ( ! isset( $totals[ $status ] ) ) {
                continue;
            }

            $totals[ $status ]++;
        }

        return $totals;
    }

    /**
     * Ensures log directory exists.
     */
    public static function maybe_create_log_dir() {
        $path = self::get_log_dir();
        if ( ! is_dir( $path ) ) {
            wp_mkdir_p( $path );
        }
    }

    /**
     * Returns logs directory path.
     *
     * @return string
     */
    public static function get_log_dir() {
        $uploads = wp_upload_dir();
        return trailingslashit( $uploads['basedir'] ) . self::LOG_DIR;
    }

    /**
     * AJAX: Fetch logs list.
     */
    public static function ajax_fetch_logs() {
        Backup_Lite_UI::verify_ajax_request();

        wp_send_json_success( [ 'logs' => self::get_logs() ] );
    }

    /**
     * AJAX: Delete log file.
     */
    public static function ajax_delete_log() {
        Backup_Lite_UI::verify_ajax_request();

        $log = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';

        if ( ! $log ) {
            wp_send_json_error( [ 'message' => __( 'Log filename missing.', 'museder-restoreone' ) ], 400 );
        }

        $path = self::resolve_log_path( $log );

        if ( ! $path || ! file_exists( $path ) ) {
            wp_send_json_error( [ 'message' => __( 'Log file not found.', 'museder-restoreone' ) ], 404 );
        }

        if ( @unlink( $path ) ) {
            wp_send_json_success();
        }

        wp_send_json_error( [ 'message' => __( 'Unable to delete log file.', 'museder-restoreone' ) ], 500 );
    }

    /**
     * AJAX: Download log.
     */
    public static function ajax_download_log() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized.', 'museder-restoreone' ) );
        }

        $log  = isset( $_GET['log'] ) ? sanitize_text_field( wp_unslash( $_GET['log'] ) ) : '';
        $path = self::resolve_log_path( $log );

        if ( ! $path || ! file_exists( $path ) ) {
            wp_die( __( 'Log file not found.', 'museder-restoreone' ) );
        }

        check_admin_referer( 'backup_lite_download_log_' . basename( $path ) );

        header( 'Content-Type: text/plain' );
        header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
        header( 'Content-Length: ' . filesize( $path ) );

        readfile( $path );
        exit;
    }

    /**
     * AJAX: View log content (tail).
     */
    public static function ajax_view_log() {
        Backup_Lite_UI::verify_ajax_request();

        $log  = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
        $path = self::resolve_log_path( $log );

        if ( ! $path || ! file_exists( $path ) ) {
            wp_send_json_error( [ 'message' => __( 'Log file not found.', 'museder-restoreone' ) ], 404 );
        }

        $size      = filesize( $path );
        $limit     = 200 * 1024; // 200 KB.
        $truncated = $size > $limit;

        if ( $truncated ) {
            $content = self::tail_file( $path, $limit );
        } else {
            $content = file_get_contents( $path );
        }

        wp_send_json_success( [
            'log' => [
                'name'      => basename( $path ),
                'size'      => size_format( $size ),
                'modified'  => backup_lite_local_time( 'Y-m-d H:i', filemtime( $path ) ),
                'content'   => $content,
                'truncated' => $truncated,
            ],
        ] );
    }

    /**
     * Returns logs metadata for display.
     *
     * @param int $limit Optional limit.
     * @return array
     */
    public static function get_logs( $limit = 0 ) {
        $files = self::scan_logs();
        $items = [];

        foreach ( $files as $index => $path ) {
            if ( $limit > 0 && $index >= $limit ) {
                break;
            }

            $items[] = [
                'name'         => basename( $path ),
                'size'         => size_format( filesize( $path ) ),
                'modified'     => backup_lite_local_time( 'Y-m-d H:i', filemtime( $path ) ),
                'download_url' => self::build_download_url( $path ),
            ];
        }

        return $items;
    }

    /**
     * Iterates recent log entries as associative arrays.
     *
     * @param int $days Number of days to include.
     * @return Generator
     */
    private static function iterate_recent_logs( $days = 7 ) {
        $days = max( 1, (int) $days );
        $base_timestamp = current_time( 'timestamp' );

        for ( $offset = 0; $offset < $days; $offset++ ) {
            $timestamp = $base_timestamp - ( DAY_IN_SECONDS * $offset );
            $filename  = sprintf( 'backup-lite-%s.log', backup_lite_local_time( 'Y-m-d', $timestamp ) );
            $path     = trailingslashit( self::get_log_dir() ) . $filename;

            if ( ! file_exists( $path ) ) {
                continue;
            }

            $lines = @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
            if ( empty( $lines ) ) {
                continue;
            }

            $lines = array_reverse( $lines );

            foreach ( $lines as $line ) {
                $parsed = self::parse_log_line( $line );
                if ( $parsed ) {
                    yield $parsed;
                }
            }
        }
    }

    /**
     * Returns list of log files.
     *
     * @return array
     */
    private static function scan_logs() {
        $path = self::get_log_dir();
        if ( ! is_dir( $path ) ) {
            return [];
        }

        $glob = glob( trailingslashit( $path ) . '*.log' );

        if ( empty( $glob ) ) {
            return [];
        }

        rsort( $glob );
        return $glob;
    }

    /**
     * Resolves a log filename to absolute path.
     *
     * @param string $log Log filename.
     * @return string|null
     */
    private static function resolve_log_path( $log ) {
        if ( empty( $log ) ) {
            return null;
        }

        $path = self::get_log_dir() . '/' . basename( $log );
        $path = wp_normalize_path( $path );

        $root = wp_normalize_path( self::get_log_dir() );
        if ( strpos( $path, $root ) !== 0 ) {
            return null;
        }

        return $path;
    }

    /**
     * Builds a secure download URL for a log file.
     *
     * @param string $path Absolute path.
     * @return string
     */
    private static function build_download_url( $path ) {
        $basename = basename( $path );
        return add_query_arg(
            [
                'action'   => 'backup_lite_download_log',
                'log'      => rawurlencode( $basename ),
                '_wpnonce' => wp_create_nonce( 'backup_lite_download_log_' . $basename ),
            ],
            admin_url( 'admin-ajax.php' )
        );
    }

    /**
     * Returns tail of file limited by bytes.
     *
     * @param string $path  File path.
     * @param int    $bytes Bytes to read from end.
     * @return string
     */
    private static function tail_file( $path, $bytes = 204800 ) {
        $bytes = max( 1024, (int) $bytes );
        $size  = filesize( $path );

        if ( $size <= $bytes ) {
            return file_get_contents( $path );
        }

        $handle = fopen( $path, 'rb' );
        if ( ! $handle ) {
            return '';
        }

        fseek( $handle, -1 * $bytes, SEEK_END );
        $content = stream_get_contents( $handle );
        fclose( $handle );

        return $content;
    }

    /**
     * Parses a log line into structured data.
     *
     * @param string $line Log line.
     * @return array|null
     */
    private static function parse_log_line( $line ) {
        $line = trim( $line );
        if ( '' === $line ) {
            return null;
        }

        if ( ! preg_match( '/^\[(.+?)\]\s\[(.+?)\]\s(.*)$/', $line, $matches ) ) {
            return null;
        }

        $timestamp = $matches[1];
        $level     = strtolower( $matches[2] );
        $body      = $matches[3];

        $message = $body;
        $context = [];

        $json_pos = strpos( $body, '{' );
        if ( false !== $json_pos ) {
            $message = trim( substr( $body, 0, $json_pos ) );
            $json    = substr( $body, $json_pos );
            $decoded = json_decode( $json, true );
            if ( is_array( $decoded ) ) {
                $context = $decoded;
            }
        }

        return [
            'timestamp' => $timestamp,
            'level'     => $level,
            'message'   => $message,
            'context'   => $context,
        ];
    }
}

