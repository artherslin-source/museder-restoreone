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
            $duration_seconds = isset( $context['duration_seconds'] ) && is_numeric( $context['duration_seconds'] ) ? (int) $context['duration_seconds'] : null;
            $items[] = [
                'file'       => $context['file'] ?? '',
                'name'       => basename( $context['file'] ?? '' ),
                'size'       => isset( $context['size_bytes'] ) ? (int) $context['size_bytes'] : 0,
                'size_human' => $context['size_human'] ?? ( isset( $context['size_bytes'] ) ? size_format( (int) $context['size_bytes'], 2 ) : '' ),
                'created'    => self::format_timestamp( $event['timestamp'] ),
                'status'   => $context['status'] ?? 'pending',
                'duration_seconds' => $duration_seconds,
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

        // Sort by next_run_timestamp_utc (prefer new field, fallback to next_run)
        usort(
            $schedules,
            static function ( $a, $b ) {
                $a_next = isset( $a['next_run_timestamp_utc'] ) ? (int) $a['next_run_timestamp_utc'] : 0;
                if ( $a_next <= 0 && isset( $a['next_run'] ) ) {
                    $a_next = is_numeric( $a['next_run'] ) ? (int) $a['next_run'] : 0;
                }
                $b_next = isset( $b['next_run_timestamp_utc'] ) ? (int) $b['next_run_timestamp_utc'] : 0;
                if ( $b_next <= 0 && isset( $b['next_run'] ) ) {
                    $b_next = is_numeric( $b['next_run'] ) ? (int) $b['next_run'] : 0;
                }
                if ( $a_next <= 0 ) {
                    $a_next = PHP_INT_MAX;
                }
                if ( $b_next <= 0 ) {
                    $b_next = PHP_INT_MAX;
                }
                return $a_next <=> $b_next;
            }
        );

        $next = $schedules[0];
        
        // Next run - prefer next_run_timestamp_utc, fallback to next_run
        $next_timestamp = isset( $next['next_run_timestamp_utc'] )
            ? (int) $next['next_run_timestamp_utc']
            : 0;

        if ( $next_timestamp <= 0 && ! empty( $next['next_run'] ) ) {
            if ( is_numeric( $next['next_run'] ) ) {
                $next_timestamp = (int) $next['next_run'];
            } else {
                // MySQL datetime string (no timezone), treat as UTC base
                $next_timestamp = strtotime( $next['next_run'] . ' UTC' );
            }
        }

        // next_run_timestamp_utc is UTC timestamp, use current_time('timestamp', true) for comparison (UTC)
        $countdown = $next_timestamp > 0
            ? self::get_countdown_string( $next_timestamp - current_time( 'timestamp', true ) )
            : '';

        // Last run - prefer last_run_timestamp_utc, fallback to last_run
        $last_run_timestamp = isset( $next['last_run_timestamp_utc'] )
            ? (int) $next['last_run_timestamp_utc']
            : 0;

        if ( $last_run_timestamp <= 0 && ! empty( $next['last_run'] ) ) {
            if ( is_numeric( $next['last_run'] ) ) {
                $last_run_timestamp = (int) $next['last_run'];
            } else {
                // MySQL datetime string (no timezone), treat as UTC base
                $last_run_timestamp = strtotime( $next['last_run'] . ' UTC' );
            }
        }
        
        return [
            'title'       => $next['title'] ?? __( 'Scheduled Backup', 'museder-restoreone' ),
            'period'      => $next['period'] ?? 'daily',
            'next_run'    => $next_timestamp,
            // Use same format as Schedules list: Y-m-d H:i
            'next_run_human' => $next_timestamp > 0
                ? backup_lite_format_local_time( $next_timestamp, 'Y-m-d H:i' )
                : __( 'Not scheduled', 'museder-restoreone' ),
            'countdown'   => $countdown,
            'last_result' => $next['last_result'] ?? '',
            'last_run'    => $last_run_timestamp > 0
                ? backup_lite_format_local_time( $last_run_timestamp, 'Y-m-d H:i' )
                : __( 'Never', 'museder-restoreone' ),
            'last_run_timestamp' => $last_run_timestamp,
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
    /**
     * Formats a timestamp string from log entries.
     * 
     * @param string $timestamp Raw timestamp from log (assumed to be UTC datetime string).
     * @return string Formatted date/time in site's local timezone.
     */
    private static function format_timestamp( $timestamp ) {
        // Parse timestamp string to Unix timestamp (assume UTC)
        $time = strtotime( $timestamp . ' UTC' );
        if ( ! $time ) {
            // Fallback: try parsing without UTC suffix
            $time = strtotime( $timestamp );
            if ( ! $time ) {
                return '';
            }
        }

        // @plugin-check: wp_date with local timezone - converts UTC timestamp to site's local timezone
        return backup_lite_format_local_time( $time );
    }

    /**
     * Converts seconds into a human readable countdown string.
     *
     * @param int $seconds Seconds until next run.
     * @return string Escaped HTML string ready for output.
     * 
     * @test Checklist:
     * - Dashboard 能正常打開，不再有 fatal
     * - 在台北時區下，countdown 顯示正確的時間
     * - 分鐘和小時的單複數形式正確顯示
     */
    private static function get_countdown_string( $seconds ) {
        if ( $seconds <= 0 ) {
            return esc_html__( 'Due now', 'museder-restoreone' );
        }

        if ( $seconds < HOUR_IN_SECONDS ) {
            $minutes = max( 1, (int) floor( $seconds / MINUTE_IN_SECONDS ) );
            /* translators: %s: number of minutes */
            $text = _n(
                'Next run in %s minute',
                'Next run in %s minutes',
                $minutes,
                'museder-restoreone'
            );
            return sprintf(
                esc_html( $text ),
                number_format_i18n( $minutes )
            );
        }

        $hours = (int) floor( $seconds / HOUR_IN_SECONDS );
        /* translators: %s: number of hours */
        $text = _n(
            'Next run in %s hour',
            'Next run in %s hours',
            $hours,
            'museder-restoreone'
        );
        return sprintf(
            esc_html( $text ),
            number_format_i18n( $hours )
        );
    }
}

