<?php
/**
 * Dashboard data helpers for Backup Lite.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Backup_Lite_Dashboard {

    /**
     * Placeholder bootstrap (reserved for future hooks).
     */
    public static function init() {
        // Reserved for future use (REST endpoints, caching, etc.).
    }

    /**
     * Returns formatted recent backup entries derived from logs.
     *
     * @param int $limit Number of entries to return.
     * @return array<int,array>
     */
    public static function get_recent_backups( $limit = 3 ) {
        $events = Backup_Lite_Log_Handler::get_recent_events( 'backup_result', $limit );
        $items  = [];

        foreach ( $events as $event ) {
            $context = $event['context'];
            $items[] = [
                'file'       => $context['file'] ?? '',
                'name'       => basename( $context['file'] ?? '' ),
                'size'       => isset( $context['size_bytes'] ) ? (int) $context['size_bytes'] : 0,
                'size_human' => $context['size_human'] ?? ( isset( $context['size_bytes'] ) ? size_format( (int) $context['size_bytes'], 2 ) : '' ),
                'created'    => self::format_timestamp( $event['timestamp'] ),
                'status'   => $context['status'] ?? 'pending',
            ];
        }

        return $items;
    }

    /**
     * Returns the next scheduled task overview.
     *
     * @return array<string,mixed>
     */
    public static function get_schedule_overview() {
        $schedules = Backup_Lite_Schedule_Handler::list_schedules();

        if ( empty( $schedules ) ) {
            return [
                'title'       => __( 'No schedules configured', 'museder-restoreone' ),
                'period'      => '',
                'next_run'    => null,
                'countdown'   => '',
                'last_result' => '',
            ];
        }

        usort(
            $schedules,
            static function ( $a, $b ) {
                $a_next = isset( $a['next_run'] ) ? (int) $a['next_run'] : PHP_INT_MAX;
                $b_next = isset( $b['next_run'] ) ? (int) $b['next_run'] : PHP_INT_MAX;
                return $a_next <=> $b_next;
            }
        );

        $next = $schedules[0];
        $next_timestamp = isset( $next['next_run'] ) ? (int) $next['next_run'] : 0;
        $countdown      = $next_timestamp > 0 ? self::get_countdown_string( $next_timestamp - current_time( 'timestamp' ) ) : '';

        return [
            'title'       => $next['title'] ?? __( 'Scheduled Backup', 'museder-restoreone' ),
            'period'      => $next['period'] ?? 'daily',
            'next_run'    => $next_timestamp,
            'next_run_human' => $next_timestamp ? backup_lite_local_time( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_timestamp ) : '',
            'countdown'   => $countdown,
            'last_result' => $next['last_result'] ?? '',
            'last_run'    => $next['last_run'] ?? '',
        ];
    }

    /**
     * Returns chart-ready activity data.
     *
     * @param int $days Number of days to analyse.
     * @return array{success:int,failed:int,pending:int}
     */
    public static function get_activity_stats( $days = 7 ) {
        return Backup_Lite_Log_Handler::get_activity_totals( $days );
    }

    /**
     * Formats a timestamp string from log entries.
     *
     * @param string $timestamp Raw timestamp from log.
     * @return string
     */
    private static function format_timestamp( $timestamp ) {
        $time = strtotime( $timestamp );
        if ( ! $time ) {
            return '';
        }

        return backup_lite_local_time( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
    }

    /**
     * Converts seconds into a human readable countdown string.
     *
     * @param int $seconds Seconds until next run.
     * @return string
     */
    private static function get_countdown_string( $seconds ) {
        if ( $seconds <= 0 ) {
            return __( 'Due now', 'museder-restoreone' );
        }

        if ( $seconds < HOUR_IN_SECONDS ) {
            $minutes = max( 1, (int) floor( $seconds / MINUTE_IN_SECONDS ) );
            /* translators: %d: number of minutes */
            return sprintf( esc_html_n( 'Next run in %d minute', 'Next run in %d minutes', $minutes, 'museder-restoreone' ), $minutes );
        }

        $hours = (int) floor( $seconds / HOUR_IN_SECONDS );
        /* translators: %d: number of hours */
        return sprintf( esc_html_n( 'Next run in %d hour', 'Next run in %d hours', $hours, 'museder-restoreone' ), $hours );
    }
}

