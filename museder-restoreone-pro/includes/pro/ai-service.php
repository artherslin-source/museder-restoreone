<?php
/**
 * Backup Lite PRO - AI Service
 * 
 * Core AI logic for Backup Copilot features.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AI Service class for Backup Lite PRO.
 */
class Museder_Restoreone_AI_Service {

    /**
     * Initialize the AI service.
     */
    public static function init() {
        // Register anomaly detection cron
        if ( ! wp_next_scheduled( 'museder_restoreone_ai_anomaly_detection' ) ) {
            wp_schedule_event( time(), 'museder_restoreone_6hours', 'museder_restoreone_ai_anomaly_detection' );
        }
        add_action( 'museder_restoreone_ai_anomaly_detection', [ __CLASS__, 'cron_anomaly_detection' ] );

        // Register custom cron interval
        add_filter( 'cron_schedules', [ __CLASS__, 'add_cron_intervals' ] );
    }

    /**
     * Add custom cron intervals.
     * 
     * @param array $schedules Existing schedules.
     * @return array
     */
    public static function add_cron_intervals( $schedules ) {
        $schedules['museder_restoreone_6hours'] = [
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => __( 'Every 6 Hours', 'museder-restoreone' ),
        ];
        return $schedules;
    }

    /**
     * Cron job for anomaly detection.
     */
    public static function cron_anomaly_detection() {
        if ( ! Museder_Restoreone_Pro::is_pro_active() ) {
            return;
        }

        $anomalies = self::detect_anomalies();
        if ( ! empty( $anomalies['anomalies'] ) ) {
            // Store anomalies for dashboard display
            update_option( 'museder_restoreone_ai_anomalies', $anomalies['anomalies'], false );
        }
    }

    /**
     * Analyze site and provide backup recommendations.
     * 
     * @param array $site_meta Site metadata.
     * @param array $backup_history Backup history.
     * @return array {
     *     @type array $schedule_recommendations Recommended schedule settings.
     *     @type array $retention_recommendations Recommended retention policies.
     *     @type array $exclude_paths Recommended exclusion paths.
     *     @type array $warnings Major warnings.
     * }
     */
    public static function analyze_site( $site_meta = [], $backup_history = [] ) {
        if ( ! Museder_Restoreone_Pro::is_pro_active() ) {
            return [
                'error'   => 'pro_required',
                'message' => __( 'This feature is unavailable in the current build.', 'museder-restoreone' ),
            ];
        }

        // Collect site metadata
        $wp_version = get_bloginfo( 'version' );
        $php_version = PHP_VERSION;
        $db_version = $GLOBALS['wpdb']->db_version();
        $site_url = home_url();
        $wp_content_size = self::calculate_wp_content_size();
        $backup_count = count( Museder_Restoreone_UI::get_backups_list() );
        $last_backup = self::get_last_backup_time();

        // Analyze and generate recommendations
        $schedule_recommendations = self::analyze_schedule_needs( $backup_count, $last_backup );
        $retention_recommendations = self::analyze_retention_needs( $backup_count, $wp_content_size );
        $exclude_paths = self::analyze_exclude_paths( $wp_content_size );
        $warnings = self::analyze_warnings( $wp_version, $php_version, $db_version, $last_backup );

        return [
            'schedule_recommendations' => $schedule_recommendations,
            'retention_recommendations' => $retention_recommendations,
            'exclude_paths'             => $exclude_paths,
            'warnings'                  => $warnings,
        ];
    }

    /**
     * Get health score for the site.
     * 
     * @return array {
     *     @type int    $score Health score (0-100).
     *     @type array  $risks Risk summary.
     *     @type array  $recommendations Recommendations.
     * }
     */
    public static function get_health_score() {
        if ( ! Museder_Restoreone_Pro::is_pro_active() ) {
            return [
                'error'   => 'pro_required',
                'message' => __( 'This feature is unavailable in the current build.', 'museder-restoreone' ),
            ];
        }

        // Calculate health score based on multiple factors
        $factors = [];
        $risks = [];
        $recommendations = [];

        // Factor 1: Recent backups
        $last_backup = self::get_last_backup_time();
        $days_since_backup = $last_backup ? ( time() - $last_backup ) / DAY_IN_SECONDS : 999;
        if ( $days_since_backup > 7 ) {
            $factors['recent_backups'] = 20;
            $risks[] = [
                'type'    => 'no_recent_backup',
                'message' => __( 'No backup in the last 7 days', 'museder-restoreone' ),
                'severity' => 'high',
            ];
            $recommendations[] = __( 'Create a backup immediately and set up a daily schedule', 'museder-restoreone' );
        } elseif ( $days_since_backup > 3 ) {
            $factors['recent_backups'] = 60;
            $risks[] = [
                'type'    => 'stale_backup',
                'message' => __( 'Last backup is more than 3 days old', 'museder-restoreone' ),
                'severity' => 'medium',
            ];
        } else {
            $factors['recent_backups'] = 100;
        }

        // Factor 2: Schedule configuration
        $schedules = Museder_Restoreone_Schedule_Handler::list_schedules();
        if ( empty( $schedules ) ) {
            $factors['schedule'] = 30;
            $risks[] = [
                'type'    => 'no_schedule',
                'message' => __( 'No backup schedule configured', 'museder-restoreone' ),
                'severity' => 'high',
            ];
            $recommendations[] = __( 'Set up an automated backup schedule', 'museder-restoreone' );
        } else {
            $factors['schedule'] = 100;
        }

        // Factor 3: Backup count
        $backup_count = count( Museder_Restoreone_UI::get_backups_list() );
        if ( $backup_count === 0 ) {
            $factors['backup_count'] = 0;
            $risks[] = [
                'type'    => 'no_backups',
                'message' => __( 'No backups exist', 'museder-restoreone' ),
                'severity' => 'critical',
            ];
        } elseif ( $backup_count < 3 ) {
            $factors['backup_count'] = 50;
            $risks[] = [
                'type'    => 'few_backups',
                'message' => __( 'Very few backups available', 'museder-restoreone' ),
                'severity' => 'medium',
            ];
        } else {
            $factors['backup_count'] = 100;
        }

        // Factor 4: Storage space
        $wp_content_size = self::calculate_wp_content_size();
        $available_space = self::get_available_disk_space();
        if ( $available_space > 0 && $wp_content_size > $available_space * 0.8 ) {
            $factors['storage'] = 40;
            $risks[] = [
                'type'    => 'low_storage',
                'message' => __( 'Low disk space available', 'museder-restoreone' ),
                'severity' => 'medium',
            ];
            $recommendations[] = __( 'Consider cleaning up old backups or increasing storage', 'museder-restoreone' );
        } else {
            $factors['storage'] = 100;
        }

        // Calculate overall score
        $score = (int) round( array_sum( $factors ) / count( $factors ) );

        return [
            'score'          => $score,
            'factors'        => $factors,
            'risks'          => $risks,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Generate restore impact summary.
     * 
     * @param string $job_id Restore job ID.
     * @return array {
     *     @type array $impact_analysis Impact analysis.
     *     @type array $content_changes Content/order/member rollback details.
     *     @type array $plugin_theme_diff Plugin/theme differences.
     *     @type array $domain_differences Domain differences.
     * }
     */
    public static function get_restore_summary( $job_id ) {
        if ( ! Museder_Restoreone_Pro::is_pro_active() ) {
            return [
                'error'   => 'pro_required',
                'message' => __( 'This feature is unavailable in the current build.', 'museder-restoreone' ),
            ];
        }

        // Placeholder for Phase D implementation
        return [
            'impact_analysis'    => [],
            'content_changes'    => [],
            'plugin_theme_diff'  => [],
            'domain_differences' => [],
        ];
    }

    /**
     * Diagnose log entry using AI.
     * 
     * @param string $log_content Log content.
     * @return array {
     *     @type string $explanation AI explanation.
     *     @type array  $suggestions Suggested actions.
     * }
     */
    public static function diagnose_log( $log_content ) {
        if ( ! Museder_Restoreone_Pro::is_pro_active() ) {
            return [
                'error'   => 'pro_required',
                'message' => __( 'This feature is unavailable in the current build.', 'museder-restoreone' ),
            ];
        }

        // Placeholder for Phase D implementation
        return [
            'explanation' => '',
            'suggestions' => [],
        ];
    }

    /**
     * Get smart schedule recommendations.
     * 
     * @return array {
     *     @type array $recommendations Schedule recommendations.
     * }
     */
    public static function get_smart_schedule() {
        if ( ! Museder_Restoreone_Pro::is_pro_active() ) {
            return [
                'error'   => 'pro_required',
                'message' => __( 'This feature is unavailable in the current build.', 'museder-restoreone' ),
            ];
        }

        // Placeholder for Phase D implementation
        return [
            'recommendations' => [],
        ];
    }

    /**
     * Process natural language command.
     * 
     * @param string $command Natural language command.
     * @return array {
     *     @type array $suggested_settings Suggested settings.
     * }
     */
    public static function process_nl_command( $command ) {
        if ( ! Museder_Restoreone_Pro::is_pro_active() ) {
            return [
                'error'   => 'pro_required',
                'message' => __( 'This feature is unavailable in the current build.', 'museder-restoreone' ),
            ];
        }

        // Placeholder for Phase D implementation
        return [
            'suggested_settings' => [],
        ];
    }

    /**
     * Run anomaly detection.
     * 
     * @return array {
     *     @type array $anomalies Detected anomalies.
     * }
     */
    public static function detect_anomalies() {
        if ( ! Museder_Restoreone_Pro::is_pro_active() ) {
            return [];
        }

        // Detect anomalies
        $anomalies = [];

        // Check for backup size anomalies
        $backups = Museder_Restoreone_UI::get_backups_list();
        if ( count( $backups ) > 1 ) {
            $sizes = array_column( $backups, 'size' );
            $avg_size = array_sum( $sizes ) / count( $sizes );
            foreach ( $backups as $backup ) {
                if ( $backup['size'] > $avg_size * 2 ) {
                    $anomalies[] = [
                        'type'    => 'large_backup',
                        /* translators: 1: Backup file name, 2: Backup size. */
                        'message' => sprintf( esc_html__( 'Backup %1$s is unusually large (%2$s)', 'museder-restoreone' ), esc_html( basename( $backup['name'] ) ), esc_html( size_format( $backup['size'] ) ) ),
                        'severity' => 'medium',
                    ];
                }
            }
        }

        // Check for consecutive failures
        $recent_logs = Museder_Restoreone_Log_Handler::get_recent_events( 'backup_result', 5 );
        $failures = 0;
        foreach ( $recent_logs as $log ) {
            if ( isset( $log['context']['status'] ) && $log['context']['status'] === 'failed' ) {
                $failures++;
            }
        }
        if ( $failures >= 3 ) {
            $anomalies[] = [
                'type'    => 'consecutive_failures',
                'message' => __( 'Multiple consecutive backup failures detected', 'museder-restoreone' ),
                'severity' => 'high',
            ];
        }

        // Check for long backup times
        foreach ( $recent_logs as $log ) {
            if ( isset( $log['context']['duration'] ) && $log['context']['duration'] > 3600 ) {
                $anomalies[] = [
                    'type'    => 'long_backup_time',
                    'message' => __( 'Backup took longer than 1 hour', 'museder-restoreone' ),
                    'severity' => 'medium',
                ];
                break;
            }
        }

        return [
            'anomalies' => $anomalies,
        ];
    }

    /**
     * Helper: Calculate wp-content directory size.
     * 
     * @return int Size in bytes.
     */
    private static function calculate_wp_content_size() {
        $wp_content = function_exists( 'museder_restoreone_get_wp_content_dir' ) ? museder_restoreone_get_wp_content_dir() : '';
        if ( '' === $wp_content || ! is_dir( $wp_content ) ) {
            return 0;
        }

        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $wp_content, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $file ) {
            if ( $file->isFile() ) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Helper: Get last backup timestamp.
     * 
     * @return int|false Timestamp or false if no backup.
     */
    private static function get_last_backup_time() {
        $backups = Museder_Restoreone_UI::get_backups_list();
        if ( empty( $backups ) ) {
            return false;
        }

        $latest = $backups[0];
        $file_path = $latest['path'] ?? '';
        if ( empty( $file_path ) || ! file_exists( $file_path ) ) {
            return false;
        }

        return filemtime( $file_path );
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

    /**
     * Helper: Analyze schedule needs.
     * 
     * @param int $backup_count Number of backups.
     * @param int|false $last_backup Last backup timestamp.
     * @return array
     */
    private static function analyze_schedule_needs( $backup_count, $last_backup ) {
        $recommendations = [];

        if ( $backup_count === 0 ) {
            $recommendations[] = [
                'type'        => 'daily',
                'time'        => '02:00',
                'reason'      => __( 'No backups exist. Start with daily backups.', 'museder-restoreone' ),
                'priority'    => 'high',
            ];
        } elseif ( $last_backup && ( time() - $last_backup ) > 7 * DAY_IN_SECONDS ) {
            $recommendations[] = [
                'type'        => 'daily',
                'time'        => '02:00',
                'reason'      => __( 'Last backup is more than 7 days old.', 'museder-restoreone' ),
                'priority'    => 'high',
            ];
        } else {
            $recommendations[] = [
                'type'        => 'daily',
                'time'        => '02:00',
                'reason'      => __( 'Daily backups ensure minimal data loss.', 'museder-restoreone' ),
                'priority'    => 'medium',
            ];
        }

        return $recommendations;
    }

    /**
     * Helper: Analyze retention needs.
     * 
     * @param int $backup_count Number of backups.
     * @param int $wp_content_size wp-content size in bytes.
     * @return array
     */
    private static function analyze_retention_needs( $backup_count, $wp_content_size ) {
        $recommendations = [];

        if ( $backup_count > 30 ) {
            $recommendations[] = [
                'policy'   => 'keep_last_30',
                'reason'   => __( 'You have many backups. Consider keeping only the last 30.', 'museder-restoreone' ),
                'priority' => 'medium',
            ];
        } elseif ( $backup_count > 14 ) {
            $recommendations[] = [
                'policy'   => 'keep_last_14',
                'reason'   => __( 'Keep the last 14 backups for 2 weeks of history.', 'museder-restoreone' ),
                'priority' => 'low',
            ];
        } else {
            $recommendations[] = [
                'policy'   => 'keep_last_7',
                'reason'   => __( 'Keep at least 7 backups for weekly history.', 'museder-restoreone' ),
                'priority' => 'low',
            ];
        }

        return $recommendations;
    }

    /**
     * Helper: Analyze exclude paths.
     * 
     * @param int $wp_content_size wp-content size in bytes.
     * @return array
     */
    private static function analyze_exclude_paths( $wp_content_size ) {
        $paths = [];

        // Common cache directories
        $cache_dirs = [ 'cache', 'w3tc', 'wp-rocket', 'litespeed' ];
        foreach ( $cache_dirs as $dir ) {
            $content_dir = function_exists( 'museder_restoreone_get_wp_content_dir' ) ? museder_restoreone_get_wp_content_dir() : '';
            if ( '' === $content_dir ) {
                continue;
            }
            $path = trailingslashit( $content_dir ) . $dir;
            if ( is_dir( $path ) ) {
                $paths[] = [
                    'path'   => 'wp-content/' . $dir,
                    'reason' => __( 'Cache directory - can be regenerated', 'museder-restoreone' ),
                ];
            }
        }

        // Large uploads might be excluded if site is very large
        if ( $wp_content_size > 500 * 1024 * 1024 ) { // > 500MB
            $paths[] = [
                'path'   => 'wp-content/uploads/backup-lite',
                'reason' => __( 'Backup directory - avoid backing up backups', 'museder-restoreone' ),
            ];
        }

        return $paths;
    }

    /**
     * Helper: Analyze warnings.
     * 
     * @param string $wp_version WordPress version.
     * @param string $php_version PHP version.
     * @param string $db_version Database version.
     * @param int|false $last_backup Last backup timestamp.
     * @return array
     */
    private static function analyze_warnings( $wp_version, $php_version, $db_version, $last_backup ) {
        $warnings = [];

        if ( version_compare( $php_version, '7.4', '<' ) ) {
            $warnings[] = [
                'type'    => 'php_version',
                /* translators: %s: Current PHP version number. */
                'message' => sprintf( esc_html__( 'PHP version %s is outdated. Consider upgrading.', 'museder-restoreone' ), esc_html( $php_version ) ),
                'severity' => 'medium',
            ];
        }

        if ( ! $last_backup ) {
            $warnings[] = [
                'type'    => 'no_backup',
                'message' => __( 'No backup has been created yet. Create one immediately.', 'museder-restoreone' ),
                'severity' => 'critical',
            ];
        }

        return $warnings;
    }
}

