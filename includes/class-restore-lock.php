<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Backup_Lite_Restore_Lock {

    const OPTION_KEY   = 'backup_lite_restore_lock';
    const TRANSIENT_KEY = 'backup_lite_restore_lock';
    const LOCK_TIMEOUT = 30 * MINUTE_IN_SECONDS;

    public static function acquire( $job_id ) {
        $lock = self::current_lock();
        if ( $lock ) {
            return false;
        }

        $payload = [
            'job_id'     => $job_id,
            'acquired_at'=> current_time( 'timestamp' ),
        ];

        set_site_transient( self::TRANSIENT_KEY, $payload, self::LOCK_TIMEOUT );
        update_option( self::OPTION_KEY, $payload, false );

        return true;
    }

    /**
     * Refreshes the lock TTL for a long-running restore job.
     *
     * @param string $job_id
     * @return bool
     */
    public static function refresh( $job_id ) {
        $lock = self::current_lock();
        if ( empty( $lock['job_id'] ) || $lock['job_id'] !== $job_id ) {
            return false;
        }

        $payload = [
            'job_id'      => $job_id,
            'acquired_at' => isset( $lock['acquired_at'] ) ? $lock['acquired_at'] : current_time( 'timestamp' ),
        ];

        set_site_transient( self::TRANSIENT_KEY, $payload, self::LOCK_TIMEOUT );
        update_option( self::OPTION_KEY, $payload, false );

        return true;
    }

    public static function release() {
        delete_site_transient( self::TRANSIENT_KEY );
        delete_option( self::OPTION_KEY );
    }

    public static function is_locked() {
        return (bool) self::current_lock();
    }

    public static function current_lock() {
        $lock = get_site_transient( self::TRANSIENT_KEY );
        if ( ! $lock ) {
            $lock = get_option( self::OPTION_KEY );
            if ( $lock ) {
                set_site_transient( self::TRANSIENT_KEY, $lock, self::LOCK_TIMEOUT );
            }
        }

        return is_array( $lock ) ? $lock : null;
    }
}
