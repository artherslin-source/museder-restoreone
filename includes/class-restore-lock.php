<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Museder_Restoreone_Restore_Lock {

    const OPTION_KEY   = 'museder_restoreone_restore_lock';
    const TRANSIENT_KEY = 'museder_restoreone_restore_lock';
    const LOCK_TIMEOUT = 30 * MINUTE_IN_SECONDS;
    const FILE_LOCK_NAME = 'bootstrap-restore.lock';

    /**
     * @return bool
     */
    protected static function use_file_lock() {
        return ( defined( 'MUSEDER_RESTOREONE_BOOTSTRAP_MODE' ) && MUSEDER_RESTOREONE_BOOTSTRAP_MODE )
            && ! function_exists( 'update_option' );
    }

    /**
     * @return string
     */
    protected static function file_lock_path() {
        $root = function_exists( 'museder_restoreone_get_storage_root' ) ? museder_restoreone_get_storage_root() : [ 'path' => '' ];
        $base = isset( $root['path'] ) ? (string) $root['path'] : '';
        return trailingslashit( $base ) . self::FILE_LOCK_NAME;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function read_file_lock() {
        $path = self::file_lock_path();
        if ( ! is_readable( $path ) ) {
            return null;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $raw  = file_get_contents( $path );
        $data = json_decode( (string) $raw, true );
        return is_array( $data ) ? $data : null;
    }

    /**
     * @param array<string, mixed> $payload Payload.
     * @return bool
     */
    protected static function write_file_lock( array $payload ) {
        $path = self::file_lock_path();
        $dir  = dirname( $path );
        if ( function_exists( 'museder_restoreone_ensure_directory' ) ) {
            museder_restoreone_ensure_directory( $dir );
        } elseif ( ! is_dir( $dir ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
            @mkdir( $dir, 0755, true );
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        return false !== file_put_contents( $path, wp_json_encode( $payload ), LOCK_EX );
    }

    public static function acquire( $job_id ) {
        if ( self::use_file_lock() ) {
            $lock = self::read_file_lock();
            if ( $lock && ( ! isset( $lock['job_id'] ) || $lock['job_id'] !== $job_id ) ) {
                return false;
            }
            return self::write_file_lock(
                [
                    'job_id'      => $job_id,
                    'acquired_at' => time(),
                ]
            );
        }

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
        if ( self::use_file_lock() ) {
            $lock = self::read_file_lock();
            if ( empty( $lock['job_id'] ) || $lock['job_id'] !== $job_id ) {
                return false;
            }
            return self::write_file_lock(
                [
                    'job_id'      => $job_id,
                    'acquired_at' => isset( $lock['acquired_at'] ) ? $lock['acquired_at'] : time(),
                ]
            );
        }

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
        if ( self::use_file_lock() ) {
            $path = self::file_lock_path();
            if ( file_exists( $path ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
                @unlink( $path );
            }
            return;
        }
        delete_site_transient( self::TRANSIENT_KEY );
        delete_option( self::OPTION_KEY );
    }

    public static function is_locked() {
        return (bool) self::current_lock();
    }

    public static function current_lock() {
        if ( self::use_file_lock() ) {
            return self::read_file_lock();
        }

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
