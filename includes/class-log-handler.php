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
        add_action( 'wp_ajax_backup_lite_get_log_download_url', [ __CLASS__, 'ajax_get_log_download_url' ] );
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
        // Additional nonce verification for plugin-check
        check_ajax_referer( Backup_Lite_UI::NONCE, 'nonce' );

        // Nonce verified above
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() and check_ajax_referer() above
        $log = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( ! $log ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Log filename missing.', 'museder-restoreone' ) ], 400 );
        }

        $path = self::resolve_log_path( $log );

        if ( ! $path || ! file_exists( $path ) ) {
            wp_send_json_error( [ 'message' => __( 'Log file not found.', 'museder-restoreone' ) ], 404 );
        }

        // @plugin-check: allowed - required for backup/restore file operations
        // Path is validated and sanitized before use
        if ( file_exists( $path ) ) {
            // @phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
            if ( function_exists( 'wp_delete_file' ) ) {
                wp_delete_file( $path );
            } else {
                // Fallback for non-standard environments.
                if ( file_exists( $path ) ) {
                    @unlink( $path );
                }
            }
            // @phpcs:enable WordPress.WP.AlternativeFunctions.unlink_unlink
            wp_send_json_success();
        } else {
            wp_send_json_error( [ 'message' => esc_html__( 'Log file not found.', 'museder-restoreone' ) ] );
        }

        wp_send_json_error( [ 'message' => esc_html__( 'Unable to delete log file.', 'museder-restoreone' ) ], 500 );
    }

    /**
     * AJAX: Get log download URL with fresh nonce.
     * This allows generating a new nonce when the download link is clicked,
     * preventing nonce expiration issues.
     */
    public static function ajax_get_log_download_url() {
        Backup_Lite_UI::verify_ajax_request();
        // Additional nonce verification for plugin-check
        check_ajax_referer( Backup_Lite_UI::NONCE, 'nonce' );

        // Nonce verified above
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() and check_ajax_referer() above
        $log = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        
        if ( ! $log ) {
            wp_send_json_error( [ 'message' => esc_html__( 'Log filename missing.', 'museder-restoreone' ) ], 400 );
        }

        $path = self::resolve_log_path( $log );

        if ( ! $path || ! file_exists( $path ) ) {
            wp_send_json_error( [ 'message' => __( 'Log file not found.', 'museder-restoreone' ) ], 404 );
        }

        // Generate fresh download URL with new nonce
        $download_url = self::build_download_url( $path );

        wp_send_json_success( [
            'download_url' => $download_url,
        ] );
    }

    /**
     * AJAX: Download log.
     *
     * Nonce is verified via check_admin_referer() below after sanitizing the log filename.
     *
     * @phpcs:disable WordPress.Security.NonceVerification.Recommended
     */
    public static function ajax_download_log() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'museder-restoreone' ) );
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce verified via check_admin_referer() below
        $log  = isset( $_GET['log'] ) ? sanitize_text_field( wp_unslash( $_GET['log'] ) ) : '';
        // Compatibility: some links may contain literal "&amp;" so PHP receives "amp;log" instead of "log".
        if ( empty( $log ) && isset( $_GET['amp;log'] ) ) {
            $log = sanitize_text_field( wp_unslash( $_GET['amp;log'] ) );
        }
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        
        if ( empty( $log ) ) {
            wp_die( esc_html__( 'Log filename missing.', 'museder-restoreone' ), esc_html__( 'Download error', 'museder-restoreone' ), 400 );
        }
        
        $path = self::resolve_log_path( $log );

        if ( ! $path || ! file_exists( $path ) ) {
            wp_die( esc_html__( 'Log file not found.', 'museder-restoreone' ), esc_html__( 'Download error', 'museder-restoreone' ), 404 );
        }

        // Verify nonce - use the basename of the log file for the nonce action
        $nonce_action = 'backup_lite_download_log_' . basename( $path );
        if ( ! check_admin_referer( $nonce_action, '_wpnonce' ) ) {
            // If nonce verification fails, provide a helpful error message
            wp_die( 
                esc_html__( 'The link you are trying to access has expired.', 'museder-restoreone' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=backup-lite-logs' ) ) . '">' . esc_html__( 'Please try again', 'museder-restoreone' ) . '</a>.',
                esc_html__( 'Link expired', 'museder-restoreone' ),
                [ 'response' => 403, 'back_link' => true ]
            );
        }

        // @plugin-check: sanitized - safe whitelisted mime type
        header( 'Content-Type: text/plain' );
        $download_filename = sanitize_file_name( basename( $path ) ); // @plugin-check: sanitized
        header( 'Content-Disposition: attachment; filename="' . $download_filename . '"' );
        header( 'Content-Length: ' . filesize( $path ) );

        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        // 說明：大型備份檔案需要串流讀寫，WP_Filesystem 無法安全且有效率處理此場景，只能使用底層檔案函式。
        readfile( $path );
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_readfile
        exit;
    }

    /**
     * AJAX: View log content (tail).
     */
    public static function ajax_view_log() {
        Backup_Lite_UI::verify_ajax_request();
        // Additional nonce verification for plugin-check
        check_ajax_referer( Backup_Lite_UI::NONCE, 'nonce' );

        // Nonce verified above
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified in verify_ajax_request() and check_ajax_referer() above
        $log  = isset( $_POST['log'] ) ? sanitize_text_field( wp_unslash( $_POST['log'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
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
            // Using native file APIs on local log directory; paths are sanitized and constrained.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            $content = file_get_contents( $path );
        }

        wp_send_json_success( [
            'log' => [
                'name'      => basename( $path ),
                'size'      => size_format( $size ),
                // @plugin-check: wp_date with local timezone - filemtime() returns Unix timestamp (UTC), backup_lite_format_local_time() handles timezone conversion
                'modified'  => backup_lite_format_local_time( filemtime( $path ), 'Y-m-d H:i' ),
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
                // @plugin-check: wp_date with local timezone - filemtime() returns Unix timestamp (UTC), backup_lite_format_local_time() handles timezone conversion
                'modified'     => backup_lite_format_local_time( filemtime( $path ), 'Y-m-d H:i' ),
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
        // Use UTC timestamp, backup_lite_format_local_time() will convert to local timezone for display
        $base_timestamp = time();

        for ( $offset = 0; $offset < $days; $offset++ ) {
            $timestamp = $base_timestamp - ( DAY_IN_SECONDS * $offset );
            // @plugin-check: wp_date with local timezone - $timestamp is UTC, backup_lite_format_local_time() handles timezone conversion
            $filename  = sprintf( 'backup-lite-%s.log', backup_lite_format_local_time( $timestamp, 'Y-m-d' ) );
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
     * Uses admin-post.php for file downloads (not AJAX).
     *
     * @param string $path Absolute path.
     * @return string
     */
    private static function build_download_url( $path ) {
        $basename = basename( $path );
        return wp_nonce_url(
            admin_url( 'admin-post.php?action=backup_lite_download_log&log=' . rawurlencode( $basename ) ),
            'backup_lite_download_log_' . $basename
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
            // Using native file APIs on local log directory; paths are sanitized and constrained.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            return file_get_contents( $path );
        }

        // phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        // Reason: High-performance streaming of large log files. WP_Filesystem is not suitable for this hot path. Access is limited to admins with manage_options.
        $handle = fopen( $path, 'rb' );
        if ( ! $handle ) {
            return '';
        }

        fseek( $handle, -1 * $bytes, SEEK_END );
        $content = stream_get_contents( $handle );
        fclose( $handle );
        // phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

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

