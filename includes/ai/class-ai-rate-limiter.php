<?php
/**
 * AI rate limiter (Free).
 *
 * @package MusederRestoreOne
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Museder_AI_Rate_Limiter {
    /**
     * Assert the user is allowed to perform a scan and increment usage.
     *
     * @param int $user_id User ID.
     * @return true|WP_Error
     */
    public function assert_allowed_and_increment( int $user_id ) {
        $limit = (int) apply_filters( 'museder_ai_free_daily_limit', 3 );
        $limit = max( 0, $limit );

        if ( $limit <= 0 ) {
            return new WP_Error(
                'museder_ai_rate_limited',
                __( 'AI scan is currently unavailable.', 'museder-restoreone' ),
                [ 'status' => 429 ]
            );
        }

        $key   = $this->transient_key( $user_id );
        $count = (int) get_transient( $key );

        if ( $count >= $limit ) {
            return new WP_Error(
                'museder_ai_rate_limited',
                __( 'Daily AI scan limit reached. Please try again tomorrow.', 'museder-restoreone' ),
                [ 'status' => 429 ]
            );
        }

        $count++;
        set_transient( $key, $count, DAY_IN_SECONDS );

        return true;
    }

    /**
     * Get remaining scans for the day.
     *
     * @param int $user_id User ID.
     * @return int
     */
    public function get_remaining( int $user_id ): int {
        $limit = (int) apply_filters( 'museder_ai_free_daily_limit', 3 );
        $limit = max( 0, $limit );
        $count = (int) get_transient( $this->transient_key( $user_id ) );
        return max( 0, $limit - $count );
    }

    /**
     * Build transient key.
     *
     * @param int $user_id User ID.
     * @return string
     */
    private function transient_key( int $user_id ): string {
        $date = function_exists( 'current_time' ) ? (string) current_time( 'Ymd' ) : gmdate( 'Ymd' );
        return 'museder_ai_scan_' . $user_id . '_' . $date;
    }
}


