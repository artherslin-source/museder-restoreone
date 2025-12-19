<?php
/**
 * AI report repository (stores reports in wp_options).
 *
 * @package MusederRestoreOne
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Museder_AI_Report_Repository {
    public const OPTION_KEY = 'museder_restoreone_ai_reports';

    /**
     * Add a report and return the stored report.
     *
     * @param array<string,mixed> $report Report payload.
     * @return array<string,mixed>
     */
    public function add_report( array $report ): array {
        $reports = $this->list_reports( 0 );

        $stored = [
            'id'              => function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : (string) uniqid( 'ai_', true ),
            'created_at_gmt'   => function_exists( 'current_time' ) ? (string) current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ),
            'created_at_ts_gmt'=> function_exists( 'current_time' ) ? (int) current_time( 'timestamp', true ) : time(),
            'report'          => $report,
        ];

        array_unshift( $reports, $stored );

        $max = (int) apply_filters( 'museder_ai_reports_max_items', 20 );
        $max = max( 1, $max );
        $reports = array_slice( $reports, 0, $max );

        update_option( self::OPTION_KEY, $reports, false );

        return $stored;
    }

    /**
     * List stored reports.
     *
     * @param int $limit Max reports. 0 = all.
     * @return array<int,array<string,mixed>>
     */
    public function list_reports( int $limit = 20 ): array {
        $reports = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $reports ) ) {
            $reports = [];
        }

        $reports = array_values( array_filter( $reports, 'is_array' ) );

        if ( $limit > 0 ) {
            return array_slice( $reports, 0, $limit );
        }

        return $reports;
    }

    /**
     * Get the latest stored report.
     *
     * @return array<string,mixed>|null
     */
    public function get_latest(): ?array {
        $reports = $this->list_reports( 1 );
        if ( empty( $reports ) ) {
            return null;
        }
        return $reports[0];
    }

    /**
     * Clear stored reports.
     *
     * @return void
     */
    public function clear(): void {
        delete_option( self::OPTION_KEY );
    }
}


