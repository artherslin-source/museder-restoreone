<?php
/**
 * Backup Lite PRO - Reports Service
 * 
 * Generates system reports, analytics, and exports.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reports Service class for Backup Lite PRO.
 */
class Museder_Restoreone_Reports_Service {

    /**
     * Initialize the reports service.
     */
    public static function init() {
        // Future: Register hooks, cron jobs, etc.
    }

    /**
     * Get system check summary.
     * 
     * @return array {
     *     @type array $environment Environment compatibility.
     *     @type array $backups Backup statistics.
     *     @type array $schedules Schedule statistics.
     *     @type array $storage Storage statistics.
     * }
     */
    public static function get_system_check() {
        if ( ! Museder_Restoreone_Pro::is_pro_active() ) {
            return [
                'error'   => 'pro_required',
                'message' => __( 'This feature is unavailable in the current build.', 'museder-restoreone' ),
            ];
        }

        $status = Museder_Restoreone_UI::get_environment_status();
        $backups = Museder_Restoreone_UI::get_backups_list();
        $schedules = Museder_Restoreone_Schedule_Handler::list_schedules();

        $backup_count = count( $backups );
        $total_size = 0;
        foreach ( $backups as $backup ) {
            $total_size += $backup['size'] ?? 0;
        }

        $enabled_schedules = 0;
        foreach ( $schedules as $schedule ) {
            if ( isset( $schedule['status'] ) && 'disabled' !== $schedule['status'] ) {
                $enabled_schedules++;
            }
        }

        return [
            'environment' => [
                'shell'      => ! empty( $status['shell'] ),
                'mysqldump'  => ! empty( $status['mysqldump'] ),
                'mysql_cli'  => ! empty( $status['mysql_cli'] ),
                'ziparchive' => ! empty( $status['ziparchive'] ),
            ],
            'backups' => [
                'count'      => $backup_count,
                'total_size' => $total_size,
                'avg_size'   => $backup_count > 0 ? $total_size / $backup_count : 0,
            ],
            'schedules' => [
                'total'   => count( $schedules ),
                'enabled' => $enabled_schedules,
            ],
            'storage' => [
                'backup_dir'      => museder_restoreone_get_backup_dir(),
                'available_space' => self::get_available_disk_space(),
            ],
        ];
    }

    /**
     * Get backup trends data.
     * 
     * @param int $days Number of days (7, 30, 90).
     * @return array {
     *     @type array $data Trend data points.
     *     @type array $summary Summary statistics.
     * }
     */
    public static function get_backup_trends( $days = 30 ) {
        if ( ! Museder_Restoreone_Pro::is_pro_active() ) {
            return [
                'error'   => 'pro_required',
                'message' => __( 'This feature is unavailable in the current build.', 'museder-restoreone' ),
            ];
        }

        $backups = Museder_Restoreone_UI::get_backups_list();
        $cutoff = time() - ( $days * DAY_IN_SECONDS );
        
        $data = [];
        $summary = [
            'total'      => 0,
            'successful' => 0,
            'failed'     => 0,
            'total_size' => 0,
        ];

        foreach ( $backups as $backup ) {
            $file_path = $backup['path'] ?? '';
            if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
                continue;
            }

            $mtime = filemtime( $file_path );
            if ( $mtime < $cutoff ) {
                continue;
            }

            // @plugin-check: allowed - user-facing date display, uses WordPress timezone
            $date = wp_date( 'Y-m-d', $mtime );
            if ( ! isset( $data[ $date ] ) ) {
                $data[ $date ] = [
                    'date'  => $date,
                    'count' => 0,
                    'size'  => 0,
                ];
            }

            $data[ $date ]['count']++;
            $data[ $date ]['size'] += $backup['size'] ?? 0;
            $summary['total']++;
            $summary['total_size'] += $backup['size'] ?? 0;
        }

        // Fill missing dates with zeros
        $filled_data = [];
        for ( $i = $days - 1; $i >= 0; $i-- ) {
            // @plugin-check: allowed - user-facing date display, uses WordPress timezone
            $date = wp_date( 'Y-m-d', time() - ( $i * DAY_IN_SECONDS ) );
            $filled_data[] = $data[ $date ] ?? [
                'date'  => $date,
                'count' => 0,
                'size'  => 0,
            ];
        }

        return [
            'data'    => $filled_data,
            'summary' => $summary,
        ];
    }

    /**
     * Get AI event analysis.
     * 
     * @return array {
     *     @type array $events Analyzed events.
     *     @type array $insights AI insights.
     * }
     */
    public static function get_ai_event_analysis() {
        if ( ! Museder_Restoreone_Pro::is_pro_active() ) {
            return [
                'error'   => 'pro_required',
                'message' => __( 'This feature is unavailable in the current build.', 'museder-restoreone' ),
            ];
        }

        $recent_logs = Museder_Restoreone_Log_Handler::get_recent_events( 'backup_result', 50 );
        $events = [];
        $insights = [];

        foreach ( $recent_logs as $log ) {
            $events[] = [
                'timestamp' => $log['timestamp'] ?? '',
                'status'    => $log['context']['status'] ?? 'unknown',
                'message'   => $log['context']['message'] ?? '',
            ];
        }

        // Analyze patterns
        $success_count = 0;
        $fail_count = 0;
        foreach ( $events as $event ) {
            if ( 'success' === $event['status'] ) {
                $success_count++;
            } elseif ( 'failed' === $event['status'] ) {
                $fail_count++;
            }
        }

        if ( $fail_count > $success_count * 0.3 ) {
            $insights[] = [
                'type'    => 'warning',
                'message' => __( 'High failure rate detected. Review backup logs.', 'museder-restoreone' ),
            ];
        }

        return [
            'events'   => $events,
            'insights' => $insights,
        ];
    }

    /**
     * Generate PDF report.
     * 
     * @return array {
     *     @type string $file_path Report file path.
     *     @type string $url Download URL.
     * }
     */
    public static function generate_pdf_report() {
        if ( ! Museder_Restoreone_Pro::is_pro_active() ) {
            return [
                'error'   => 'pro_required',
                'message' => __( 'This feature is unavailable in the current build.', 'museder-restoreone' ),
            ];
        }

        // Placeholder for PDF generation
        return [
            'error'   => 'not_implemented',
            'message' => __( 'PDF report generation will be available in a future update.', 'museder-restoreone' ),
        ];
    }

    /**
     * Generate JSON report.
     * 
     * @return array {
     *     @type string $file_path Report file path.
     *     @type string $url Download URL.
     * }
     */
    public static function generate_json_report() {
        if ( ! Museder_Restoreone_Pro::is_pro_active() ) {
            return [
                'error'   => 'pro_required',
                'message' => __( 'This feature is unavailable in the current build.', 'museder-restoreone' ),
            ];
        }

        $system_check = self::get_system_check();
        $trends_7 = self::get_backup_trends( 7 );
        $trends_30 = self::get_backup_trends( 30 );
        $trends_90 = self::get_backup_trends( 90 );
        $ai_analysis = self::get_ai_event_analysis();

        $report = [
            'generated_at' => current_time( 'c' ),
            'site_url'     => home_url(),
            'system_check' => $system_check,
            'trends'       => [
                '7_days'  => $trends_7,
                '30_days' => $trends_30,
                '90_days' => $trends_90,
            ],
            'ai_analysis' => $ai_analysis,
        ];

        $reports_dir = museder_restoreone_get_pro_reports_dir();
        $filename = 'backup-report-' . museder_restoreone_local_time( 'Y-m-d-His' ) . '.json';
        $file_path = trailingslashit( $reports_dir ) . $filename;

        $json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        if ( false === file_put_contents( $file_path, $json, LOCK_EX ) ) {
            return [
                'error'   => 'write_failed',
                'message' => __( 'Failed to write report file.', 'museder-restoreone' ),
            ];
        }

        $nonce = wp_create_nonce( 'museder_restoreone_download_report_' . $filename );
        return [
            'file_path' => $file_path,
            'filename'  => $filename,
            'url'       => wp_nonce_url(
                admin_url( 'admin-post.php?action=museder_restoreone_download_report&file=' . rawurlencode( $filename ) ),
                'museder_restoreone_download_report_' . $filename
            ),
        ];
    }

    /**
     * Helper: Get available disk space.
     * 
     * @return int Available space in bytes, or -1 if unknown.
     */
    private static function get_available_disk_space() {
        $upload_dir = wp_upload_dir();
        $path = $upload_dir['basedir'];
        $space = @disk_free_space( $path );
        return $space !== false ? (int) $space : -1;
    }
}

