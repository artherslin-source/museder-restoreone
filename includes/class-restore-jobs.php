<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Backup_Lite_Restore_Jobs {

    const OPTION_KEY          = 'backup_lite_restore_jobs';
    const CRON_HOOK           = 'backup_lite_run_restore_job';
    const MAX_HISTORY_JOBS    = 8;
    const CLEANUP_AGE_SECONDS = WEEK_IN_SECONDS;

    public static function init() {
        add_action( self::CRON_HOOK, [ __CLASS__, 'handle_job' ], 10, 1 );
        add_action( 'backup_lite_cleanup_cron', [ __CLASS__, 'cleanup_jobs' ] );
    }

    /**
     * Schedule a new asynchronous restore job.
     *
     * @param array $args {
     *     @type array  $state
     *     @type array  $options
     *     @type int    $user_id
     * }
     *
     * @return array Job data.
     */
    public static function enqueue( array $args ) {
        $state   = isset( $args['state'] ) ? $args['state'] : [];
        $options = isset( $args['options'] ) ? $args['options'] : [];
        $user_id = isset( $args['user_id'] ) ? absint( $args['user_id'] ) : get_current_user_id();

        $job_id = 'restore_job_' . wp_generate_uuid4();

        $job = [
            'id'         => $job_id,
            'status'     => 'pending',
            'progress'   => 5,
            'message'    => __( 'Restore job queued. Waiting to start…', 'museder-restoreone' ),
            'state'      => $state,
            'options'    => $options,
            'user_id'    => $user_id,
            'created_at' => current_time( 'mysql' ),
            'started_at' => '',
            'finished_at'=> '',
            'log'        => '',
            'error'      => '',
            'history'    => [
                'timestamp' => backup_lite_local_time( 'Y-m-d H:i:s' ),
                'file'      => isset( $state['filename'] ) ? $state['filename'] : ( isset( $state['file'] ) ? basename( $state['file'] ) : '' ),
                'result'    => 'pending',
                'log'       => '',
            ],
        ];

        $jobs          = self::get_jobs();
        $jobs[ $job_id ] = $job;
        self::save_jobs( $jobs );

        wp_schedule_single_event( time(), self::CRON_HOOK, [ $job_id ] );
        self::spawn_cron();

        return $job;
    }

    public static function handle_job( $job_id ) {
        $job = self::get_job( $job_id );
        if ( empty( $job ) ) {
            return;
        }

        if ( in_array( $job['status'], [ 'success', 'failed', 'cancelled' ], true ) ) {
            return;
        }

        self::update_job( $job_id, [
            'status'     => 'running',
            'started_at' => current_time( 'mysql' ),
            'progress'   => max( 10, (float) $job['progress'] ),
            'message'    => __( 'Preparing restore environment…', 'museder-restoreone' ),
        ] );

        try {
            $result = Backup_Lite_Restore_Handler::run_job( $job_id, self::get_job( $job_id ) );

            $updates = [
                'finished_at' => current_time( 'mysql' ),
                'log'         => isset( $result['log'] ) ? $result['log'] : '',
            ];

            if ( ! empty( $result['success'] ) ) {
                $updates['status']   = 'success';
                $updates['progress'] = 100;
                $updates['message']  = __( 'Restore completed successfully.', 'museder-restoreone' );
                $updates['history']['result'] = 'success';
            } elseif ( 'cancelled' === $result['status'] ) {
                $updates['status']   = 'cancelled';
                $updates['message']  = __( 'Restore job was cancelled.', 'museder-restoreone' );
                $updates['history']['result'] = 'cancelled';
            } else {
                $updates['status']   = 'failed';
                $updates['message']  = isset( $result['message'] ) ? $result['message'] : __( 'Restore failed.', 'museder-restoreone' );
                $updates['history']['result'] = 'failed';
                if ( ! empty( $result['error'] ) ) {
                    $updates['error'] = $result['error'];
                }
            }

            if ( isset( $result['history_log'] ) ) {
                $updates['history']['log'] = $result['history_log'];
            }

            self::update_job( $job_id, $updates );
        } catch ( Throwable $e ) {
            self::update_job( $job_id, [
                'status'      => 'failed',
                'finished_at' => current_time( 'mysql' ),
                'progress'    => 100,
                'message'     => __( 'Restore failed because the server interrupted the request.', 'museder-restoreone' ),
                'error'       => $e->getMessage(),
                'history'     => array_merge(
                    isset( $job['history'] ) ? $job['history'] : [],
                    [ 'result' => 'failed' ]
                ),
            ] );
        } finally {
            self::cleanup_expired_jobs();
        }
    }

    public static function update_job_progress( $job_id, $percent, $message, $status = null, $extra = [] ) {
        $job = self::get_job( $job_id );
        if ( ! $job ) {
            return;
        }

        $job['progress'] = (float) $percent;
        $job['message']  = $message;
        if ( null !== $status ) {
            $job['status'] = $status;
            
            // When status is set to success or failed, also set finished_at timestamp
            // This ensures the frontend can correctly detect completion
            if ( in_array( $status, [ 'success', 'failed', 'cancelled' ], true ) ) {
                if ( empty( $job['finished_at'] ) ) {
                    $job['finished_at'] = current_time( 'mysql' );
                }
            }
        }

        foreach ( $extra as $key => $value ) {
            $job[ $key ] = $value;
        }

        self::save_job( $job_id, $job );
    }

    public static function request_cancel( $job_id ) {
        $job = self::get_job( $job_id );
        if ( ! $job ) {
            return;
        }

        if ( in_array( $job['status'], [ 'success', 'failed', 'cancelled' ], true ) ) {
            return;
        }

        $previous_status          = $job['status'];
        $job['status']            = 'cancelling';
        $job['message']           = __( 'Cancellation requested. Stopping restore…', 'museder-restoreone' );
        $job['cancel_requested']  = true;

        self::save_job( $job_id, $job );

        if ( 'pending' === $previous_status ) {
            self::finalize_cancel( $job_id );
            self::update_job( $job_id, [
                'message' => __( 'Restore cancelled before it started.', 'museder-restoreone' ),
            ] );
        }
    }

    public static function is_cancel_requested( $job_id ) {
        $job = self::get_job( $job_id );
        return ( ! empty( $job['cancel_requested'] ) );
    }

    public static function finalize_cancel( $job_id ) {
        self::update_job( $job_id, [
            'status'      => 'cancelled',
            'finished_at' => current_time( 'mysql' ),
            'progress'    => 100,
            'message'     => __( 'Restore cancelled.', 'museder-restoreone' ),
        ] );
    }

    public static function get_job( $job_id ) {
        $jobs = self::get_jobs();
        return isset( $jobs[ $job_id ] ) ? $jobs[ $job_id ] : null;
    }

    public static function prepare_job_response( $job ) {
        if ( empty( $job ) ) {
            return null;
        }

        // Format timestamps using WordPress date/time format and timezone
        $format_timestamp = function( $timestamp_str ) {
            if ( empty( $timestamp_str ) ) {
                return '';
            }
            $parsed = strtotime( $timestamp_str );
            if ( false !== $parsed ) {
                return backup_lite_local_time( 
                    get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), 
                    $parsed 
                );
            }
            return $timestamp_str;
        };
        
        $created_raw  = ! empty( $job['created_at'] ) ? strtotime( $job['created_at'] ) : 0;
        $started_raw  = ! empty( $job['started_at'] ) ? strtotime( $job['started_at'] ) : 0;
        $finished_raw = ! empty( $job['finished_at'] ) ? strtotime( $job['finished_at'] ) : 0;

        $response = [
            'id'               => $job['id'],
            'status'           => $job['status'],
            'progress'         => isset( $job['progress'] ) ? (float) $job['progress'] : 0,
            'message'          => isset( $job['message'] ) ? $job['message'] : __( 'Waiting…', 'museder-restoreone' ),
            'created_at'       => isset( $job['created_at'] ) ? $format_timestamp( $job['created_at'] ) : '',
            'started_at'       => isset( $job['started_at'] ) ? $format_timestamp( $job['started_at'] ) : '',
            'finished_at'      => isset( $job['finished_at'] ) ? $format_timestamp( $job['finished_at'] ) : '',
            'created_at_raw'   => $created_raw ? (int) $created_raw : 0,
            'started_at_raw'   => $started_raw ? (int) $started_raw : 0,
            'finished_at_raw'  => $finished_raw ? (int) $finished_raw : 0,
            'log'              => isset( $job['log'] ) ? $job['log'] : '',
        ];

        if ( ! empty( $job['log'] ) ) {
            $response['log_url'] = wp_nonce_url(
                admin_url( 'admin-post.php?action=backup_lite_download_log&log=' . rawurlencode( basename( $job['log'] ) ) ),
                'backup_lite_download_log_' . basename( $job['log'] )
            );
        } else {
            $response['log_url'] = '';
        }

        return $response;
    }

    public static function cleanup_jobs() {
        self::cleanup_expired_jobs();
    }

    public static function get_recent_jobs() {
        $jobs = self::get_jobs();
        uasort(
            $jobs,
            function ( $a, $b ) {
                return strcmp( $b['created_at'], $a['created_at'] );
            }
        );
        return array_slice( $jobs, 0, self::MAX_HISTORY_JOBS, true );
    }

    public static function spawn_cron() {
        // Do not include WordPress core files directly. Best-effort: nudge wp-cron via loopback request.
        if ( function_exists( 'backup_lite_nudge_wp_cron' ) ) {
            backup_lite_nudge_wp_cron();
        }
    }

    public static function has_active_job() {
        foreach ( self::get_jobs() as $job ) {
            if ( in_array( $job['status'], [ 'pending', 'running', 'cancelling' ], true ) ) {
                return $job;
            }
        }
        return null;
    }

    /* --------------------------------------------------------------------- */
    /*  Internal helpers                                                     */
    /* --------------------------------------------------------------------- */

    private static function get_jobs() {
        $jobs = get_option( self::OPTION_KEY, [] );
        return is_array( $jobs ) ? $jobs : [];
    }

    private static function save_jobs( $jobs ) {
        update_option( self::OPTION_KEY, $jobs, false );
    }

    private static function save_job( $job_id, $job ) {
        $jobs = self::get_jobs();
        $jobs[ $job_id ] = $job;
        self::save_jobs( $jobs );
    }

    private static function update_job( $job_id, $changes ) {
        $job = self::get_job( $job_id );
        if ( ! $job ) {
            return;
        }
        foreach ( $changes as $key => $value ) {
            if ( is_array( $value ) && isset( $job[ $key ] ) && is_array( $job[ $key ] ) ) {
                $job[ $key ] = array_merge( $job[ $key ], $value );
            } else {
                $job[ $key ] = $value;
            }
        }
        self::save_job( $job_id, $job );
    }

    private static function cleanup_expired_jobs() {
        $jobs   = self::get_jobs();
        $changed = false;
        $now    = current_time( 'timestamp' );

        foreach ( $jobs as $id => $job ) {
            $finished = isset( $job['finished_at'] ) ? strtotime( $job['finished_at'] ) : 0;
            if ( $finished && ( $now - $finished ) > self::CLEANUP_AGE_SECONDS ) {
                unset( $jobs[ $id ] );
                $changed = true;
            }
        }

        if ( $changed ) {
            self::save_jobs( $jobs );
        }
    }
}

