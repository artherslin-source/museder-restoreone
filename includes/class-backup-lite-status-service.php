<?php
/**
 * Status service for Backup Lite - provides summary data for dashboard.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Backup Lite Status Service
 *
 * Provides summary data for dashboard status panel.
 */
class Backup_Lite_Status_Service {

    /**
     * Get last backup summary.
     *
     * @return array{
     *     exists: bool,
     *     status: string,
     *     job_type: string|null,
     *     source: string|null,
     *     destinations: array<string>,
     *     started_at: int|null,
     *     finished_at: int|null,
     *     size_bytes: int|null,
     *     log_id: string|null
     * }
     */
    public static function get_last_backup_summary() {
        // Default return structure
        $default = [
            'exists'       => false,
            'status'       => 'none',
            'job_type'     => null,
            'source'       => null,
            'destinations' => [],
            'started_at'   => null,
            'finished_at'  => null,
            'size_bytes'   => null,
            'log_id'       => null,
        ];

        // Check if backup is in progress
        $active_job = Backup_Lite_Backup_Jobs::get_active_job();
        $is_in_progress = false;
        if ( $active_job && isset( $active_job['status'] ) ) {
            if ( Backup_Lite_Backup_Jobs::STATUS_RUNNING === $active_job['status'] || Backup_Lite_Backup_Jobs::STATUS_PENDING === $active_job['status'] ) {
                $is_in_progress = true;
            }
        }

        // Use repository method to get last successful local backup
        $last_backup = Backup_Lite_UI::get_last_successful_local_backup();
        
        if ( ! $last_backup ) {
            // If there's an active job, show in_progress status even if no completed backup yet
            if ( $is_in_progress ) {
                return [
                    'exists'       => true,
                    'status'       => 'in_progress',
                    'job_type'     => null,
                    'source'       => null,
                    'destinations' => [],
                    'started_at'   => null,
                    'finished_at'  => null,
                    'size_bytes'   => null,
                    'log_id'       => null,
                ];
            }
            return $default;
        }

        // Determine status
        $status = 'success';
        if ( $is_in_progress ) {
            $status = 'in_progress';
        }

        // Get file path and size
        $file_path = $last_backup['path'] ?? '';
        $size_bytes = isset( $last_backup['size'] ) ? (int) $last_backup['size'] : null;

        // Determine destinations
        $destinations = [ 'local' ];
        if ( ! empty( $last_backup['s3_status'] ) && 'stored' === $last_backup['s3_status'] ) {
            $destinations[] = 's3';
        }

        // Determine job type from metadata
        $job_type = 'full'; // Default
        $metadata = Backup_Lite_Backup::get_backup_metadata( $last_backup['name'] );
        if ( ! empty( $metadata['options']['create_dual_version'] ) || ! empty( $metadata['options']['dual_version'] ) || ! empty( $metadata['options']['dual'] ) ) {
            $job_type = 'dual';
        }

        // Determine source (default to manual, could be enhanced with schedule tracking)
        $source = 'manual';

        // Get timestamps
        $finished_at = null;
        if ( isset( $last_backup['created_timestamp'] ) ) {
            $finished_at = (int) $last_backup['created_timestamp'];
        } elseif ( ! empty( $file_path ) && file_exists( $file_path ) ) {
            $finished_at = filemtime( $file_path );
        }

        // Get log filename for linking
        $log_id = null;
        if ( $finished_at ) {
            $log_date = date( 'Y-m-d', $finished_at );
            $log_id = sprintf( 'backup-lite-%s.log', $log_date );
        }

        return [
            'exists'       => true,
            'status'       => $status,
            'job_type'     => $job_type,
            'source'       => $source,
            'destinations' => $destinations,
            'started_at'   => $finished_at, // Use finished_at as started_at fallback
            'finished_at'  => $finished_at,
            'size_bytes'   => $size_bytes,
            'log_id'       => $log_id,
        ];
    }

    /**
     * Get last restore summary.
     *
     * @return array{
     *     exists: bool,
     *     status: string,
     *     source_type: string|null,
     *     started_at: int|null,
     *     finished_at: int|null,
     *     log_id: string|null
     * }
     */
    public static function get_last_restore_summary() {
        // Default return structure
        $default = [
            'exists'       => false,
            'status'       => 'none',
            'source_type'  => null,
            'started_at'   => null,
            'finished_at'  => null,
            'log_id'       => null,
        ];

        // Use repository method to get last successful restore
        $last_restore = backup_lite_get_last_successful_restore();
        
        if ( ! $last_restore ) {
            return $default;
        }

        // Determine status (from restore history, result should be 'success')
        $status = 'success';
        $result = isset( $last_restore['result'] ) ? strtolower( $last_restore['result'] ) : '';
        if ( 'failed' === $result ) {
            $status = 'failed';
        }

        // Determine source type
        $source_type = 'local';
        if ( isset( $last_restore['source'] ) ) {
            $source = strtolower( $last_restore['source'] );
            if ( 's3' === $source ) {
                $source_type = 's3';
            } else {
                $source_type = 'local';
            }
        }

        // Parse timestamps
        $finished_at = null;
        if ( ! empty( $last_restore['timestamp_utc'] ) && is_numeric( $last_restore['timestamp_utc'] ) ) {
            $finished_at = (int) $last_restore['timestamp_utc'];
        } elseif ( ! empty( $last_restore['timestamp'] ) ) {
            $timestamp_str = $last_restore['timestamp'];
            if ( is_numeric( $timestamp_str ) ) {
                $finished_at = (int) $timestamp_str;
            } else {
                $finished_at = strtotime( $timestamp_str );
            }
        }

        $started_at = $finished_at; // Use finished_at as started_at fallback

        // Get log filename
        $log_id = null;
        if ( $finished_at ) {
            $log_date = date( 'Y-m-d', $finished_at );
            $log_id = sprintf( 'backup-lite-%s.log', $log_date );
        }

        return [
            'exists'       => true,
            'status'       => $status,
            'source_type'  => $source_type,
            'started_at'   => $started_at,
            'finished_at'  => $finished_at,
            'log_id'       => $log_id,
        ];
    }

    /**
     * Get recent backup statistics.
     *
     * @param int $days Number of days to analyze (default: 7).
     * @return array{
     *     days: int,
     *     success_count: int,
     *     failed_count: int,
     *     total_count: int
     * }
     */
    public static function get_recent_backup_stats( $days = 7 ) {
        $days = max( 1, (int) $days );

        $totals = Backup_Lite_Log_Handler::get_activity_totals( $days );

        return [
            'days'          => $days,
            'success_count' => isset( $totals['success'] ) ? (int) $totals['success'] : 0,
            'failed_count'  => isset( $totals['failed'] ) ? (int) $totals['failed'] : 0,
            'total_count'   => ( isset( $totals['success'] ) ? (int) $totals['success'] : 0 ) + ( isset( $totals['failed'] ) ? (int) $totals['failed'] : 0 ),
        ];
    }
}

