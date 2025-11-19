<?php
/**
 * Schedule handler for Backup Lite.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Backup_Lite_Schedule_Handler {

    const OPTION_KEY      = 'backup_lite_schedules';
    const CRON_HOOK       = 'backup_lite_cron_event';
    const MONTHLY_INTERVAL = 'backup_lite_monthly';

    /**
     * Bootstraps hooks.
     */
    public static function init() {
        add_filter( 'cron_schedules', [ __CLASS__, 'register_custom_intervals' ] );

        add_action( 'init', [ __CLASS__, 'synchronise_cron_events' ] );
        add_action( self::CRON_HOOK, [ __CLASS__, 'run_scheduled_backup' ], 10, 2 );

        add_action( 'wp_ajax_backup_lite_fetch_schedules', [ __CLASS__, 'ajax_fetch_schedules' ] );
        add_action( 'wp_ajax_backup_lite_save_schedule', [ __CLASS__, 'ajax_save_schedule' ] );
        add_action( 'wp_ajax_backup_lite_delete_schedule', [ __CLASS__, 'ajax_delete_schedule' ] );
        add_action( 'wp_ajax_backup_lite_run_schedule_now', [ __CLASS__, 'ajax_run_schedule_now' ] );

        // Backwards compatibility with previous AJAX endpoints.
        add_action( 'wp_ajax_backup_lite_add_schedule', [ __CLASS__, 'ajax_add_schedule' ] );
        add_action( 'wp_ajax_backup_lite_update_schedule', [ __CLASS__, 'ajax_update_schedule' ] );
        add_action( 'wp_ajax_backup_lite_start_schedule', [ __CLASS__, 'ajax_start_schedule' ] );
        add_action( 'wp_ajax_backup_lite_toggle_schedule', [ __CLASS__, 'ajax_toggle_schedule' ] );
    }

    /**
     * Adds a custom monthly recurrence to WP-Cron.
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public static function register_custom_intervals( $schedules ) {
        if ( ! isset( $schedules[ self::MONTHLY_INTERVAL ] ) ) {
            $schedules[ self::MONTHLY_INTERVAL ] = [
                'interval' => MONTH_IN_SECONDS,
                'display'  => __( 'Once Monthly', 'museder-restoreone' ),
            ];
        }

        return $schedules;
    }

    /**
     * Ensures cron events exist for enabled schedules.
     */
    public static function synchronise_cron_events() {
        try {
            $schedules = self::get_schedules();

            if ( empty( $schedules ) || ! is_array( $schedules ) ) {
                return;
            }

            foreach ( $schedules as $schedule ) {
                if ( ! is_array( $schedule ) ) {
                    continue;
                }
                
                if ( self::is_schedule_enabled( $schedule ) ) {
                    self::ensure_event_exists( $schedule );
                } else {
                    if ( isset( $schedule['id'] ) ) {
                        self::clear_event( $schedule['id'] );
                    }
                }
            }
        } catch ( Exception $e ) {
            // Silently fail during activation to prevent blocking plugin activation
            if ( function_exists( 'backup_lite_log' ) ) {
                backup_lite_log( 'error', 'Failed to synchronise cron events: ' . $e->getMessage() );
            }
        }
    }

    /**
     * AJAX: Fetch schedules list.
     */
    public static function ajax_fetch_schedules() {
        self::verify_ajax();

        wp_send_json_success( [
            'schedules' => array_values( self::get_schedules() ),
        ] );
    }

    /**
     * AJAX: Add schedule.
     */
    public static function ajax_add_schedule() {
        self::ajax_save_schedule();
    }

    /**
     * AJAX: Update schedule.
     */
    public static function ajax_update_schedule() {
        self::ajax_save_schedule();
    }

    /**
     * AJAX: Delete schedule.
     */
    public static function ajax_delete_schedule() {
        self::verify_ajax();

        $id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

        if ( ! $id ) {
            wp_send_json_error( [ 'message' => __( 'Schedule ID missing.', 'museder-restoreone' ) ], 400 );
        }

        $deleted = self::delete_schedule( $id );

        if ( ! $deleted ) {
            wp_send_json_error( [ 'message' => __( 'Schedule not found.', 'museder-restoreone' ) ], 404 );
        }

        wp_send_json_success();
    }

    /**
     * AJAX: Manually start a schedule.
     */
    public static function ajax_start_schedule() {
        self::ajax_run_schedule_now();
    }

    /**
     * AJAX: Toggle schedule status (enable/disable).
     */
    public static function ajax_toggle_schedule() {
        self::verify_ajax();

        $id     = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
        $status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'enabled';

        if ( ! $id ) {
            wp_send_json_error( [ 'message' => __( 'Schedule ID missing.', 'museder-restoreone' ) ], 400 );
        }

        $schedule = self::toggle_schedule_status( $id, $status );

        if ( ! $schedule ) {
            wp_send_json_error( [ 'message' => __( 'Schedule not found.', 'museder-restoreone' ) ], 404 );
        }

        wp_send_json_success( [ 'schedule' => $schedule ] );
    }

    /**
     * AJAX: Save schedule (create/update).
     */
    public static function ajax_save_schedule() {
        self::verify_ajax();

        $data = self::read_schedule_data();
        $id   = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

        // Check PRO limit for Free users
        if ( ! $id ) {
            $is_pro = Backup_Lite_Pro::is_pro_active();
            $existing = self::get_schedules();
            
            if ( ! $is_pro && count( $existing ) >= 1 ) {
                wp_send_json_error( [
                    'message' => __( 'Free version supports only 1 schedule. Upgrade to Museder RestoreOne PRO for multiple schedules.', 'museder-restoreone' ),
                    'code'    => 'schedule_limit_reached',
                ], 403 );
            }
        }

        if ( $id ) {
            $schedule = self::update_schedule( $id, $data );
            if ( ! $schedule ) {
                wp_send_json_error( [ 'message' => __( 'Schedule not found.', 'museder-restoreone' ) ], 404 );
            }
        } else {
            $schedule = self::create_schedule( $data );
            self::save_schedule( $schedule );
        }

        wp_send_json_success( [ 'schedule' => $schedule ] );
    }

    /**
     * AJAX: Run schedule immediately.
     */
    public static function ajax_run_schedule_now() {
        self::verify_ajax();

        $id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

        if ( ! $id ) {
            wp_send_json_error( [ 'message' => __( 'Schedule ID missing.', 'museder-restoreone' ) ], 400 );
        }

        $result = self::run_schedule_now( $id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
        }

        wp_send_json_success( [ 'schedule' => $result ] );
    }

    /**
     * Runs backup when cron fires.
     *
     * @param string $schedule_id Schedule identifier.
     */
    public static function run_scheduled_backup( $schedule_id, $attempt = 0 ) {
        $schedules = self::get_schedules();

        if ( empty( $schedules ) || empty( $schedules[ $schedule_id ] ) ) {
            return;
        }

        $schedule = $schedules[ $schedule_id ];
        $attempt  = (int) $attempt;

        if ( ! self::is_schedule_enabled( $schedule ) ) {
            self::clear_event( $schedule_id );
            return;
        }

        $schedule['last_run'] = current_time( 'mysql' );

        $result = Backup_Lite_Backup::backup_site();

        if ( ! empty( $result['success'] ) ) {
            $schedule['last_result'] = 'success';
            $schedule['last_error']  = '';
            $schedule['retry_count'] = 0;

            Backup_Lite_Log_Handler::record_event(
                'schedule_result',
                [
                    'schedule_id' => $schedule_id,
                    'title'       => $schedule['title'],
                    'status'      => 'success',
                    'attempt'     => $attempt,
                    'trigger'     => $attempt > 0 ? 'retry' : 'cron',
                ]
            );

            self::apply_retention_rules( $schedule );
        } else {
            $schedule['last_result'] = 'failed';
            $schedule['last_error']  = $result['message'] ?? __( 'Unknown failure.', 'museder-restoreone' );
            $schedule['retry_count'] = $attempt;

            Backup_Lite_Log_Handler::record_event(
                'schedule_result',
                [
                    'schedule_id' => $schedule_id,
                    'title'       => $schedule['title'],
                    'status'      => 'failed',
                    'attempt'     => $attempt,
                    'trigger'     => $attempt > 0 ? 'retry' : 'cron',
                    'message'     => $schedule['last_error'],
                ],
                'error'
            );

            if ( $attempt < 2 ) {
                backup_lite_retry_cron( $schedule_id, $attempt + 1 );
            }
        }

        $schedule['next_run'] = self::compute_next_timestamp( $schedule, current_time( 'timestamp' ) );
        $schedules[ $schedule_id ] = $schedule;
        update_option( self::OPTION_KEY, $schedules );

        self::ensure_event_exists( $schedule );
    }

    /**
     * Creates a schedule entry with sanitized data.
     *
     * @param array $data Schedule data.
     * @return array
     */
    private static function create_schedule( array $data ) {
        $schedule_id = uniqid( 'sched_', true );

        $schedule = [
            'id'       => $schedule_id,
            'title'    => $data['title'],
            'type'     => $data['type'],
            'status'   => $data['status'],
            'period'   => $data['period'],
            'time'     => $data['time'],
            'retain'   => (int) $data['retain'],
            'max_age'  => (int) $data['max_age'],
            'notify'   => $data['notify'],
            'last_run' => '',
            'last_result' => 'pending',
            'last_error'  => '',
            'retry_count' => 0,
        ];

        // PRO features
        if ( Backup_Lite_Pro::is_pro_active() ) {
            // Custom cron pattern
            if ( ! empty( $data['cron_pattern'] ) ) {
                $schedule['cron_pattern'] = sanitize_text_field( $data['cron_pattern'] );
            }

            // Exclusion paths
            if ( ! empty( $data['exclude_paths'] ) && is_array( $data['exclude_paths'] ) ) {
                $schedule['exclude_paths'] = array_map( 'sanitize_text_field', $data['exclude_paths'] );
            }

            // Smart retention policy
            if ( ! empty( $data['retention_policy'] ) ) {
                $schedule['retention_policy'] = sanitize_text_field( $data['retention_policy'] );
            }
        }

        $schedule['next_run'] = self::compute_next_timestamp( $schedule );

        return $schedule;
    }

    /**
     * Saves a new schedule.
     *
     * @param array $schedule Schedule array.
     */
    private static function save_schedule( array $schedule ) {
        $schedule = self::normalise_schedule( $schedule );
        $schedules                    = self::get_schedules();
        $schedules[ $schedule['id'] ] = $schedule;
        update_option( self::OPTION_KEY, $schedules );

        if ( self::is_schedule_enabled( $schedule ) ) {
            self::ensure_event_exists( $schedule );
        }
    }

    /**
     * Updates a schedule.
     *
     * @param string $schedule_id Schedule ID.
     * @param array  $data Data to update.
     * @return array|false
     */
    private static function update_schedule( $schedule_id, array $data ) {
        $schedules = self::get_schedules();

        if ( empty( $schedules[ $schedule_id ] ) ) {
            return false;
        }

        $schedule = $schedules[ $schedule_id ];

        $schedule['title']   = $data['title'];
        $schedule['type']    = $data['type'];
        $schedule['status']  = $data['status'];
        $schedule['period']  = $data['period'];
        $schedule['time']    = $data['time'];
        $schedule['retain']  = (int) $data['retain'];
        $schedule['max_age'] = (int) $data['max_age'];
        $schedule['notify']  = $data['notify'];

        // PRO features
        if ( Backup_Lite_Pro::is_pro_active() ) {
            // Custom cron pattern
            if ( isset( $data['cron_pattern'] ) ) {
                $schedule['cron_pattern'] = ! empty( $data['cron_pattern'] ) ? sanitize_text_field( $data['cron_pattern'] ) : '';
            }

            // Exclusion paths
            if ( isset( $data['exclude_paths'] ) ) {
                $schedule['exclude_paths'] = ! empty( $data['exclude_paths'] ) && is_array( $data['exclude_paths'] ) 
                    ? array_map( 'sanitize_text_field', $data['exclude_paths'] ) 
                    : [];
            }

            // Smart retention policy
            if ( isset( $data['retention_policy'] ) ) {
                $schedule['retention_policy'] = ! empty( $data['retention_policy'] ) ? sanitize_text_field( $data['retention_policy'] ) : '';
            }
        }

        $schedule['next_run'] = self::compute_next_timestamp( $schedule );

        $schedules[ $schedule_id ] = $schedule;
        update_option( self::OPTION_KEY, $schedules );

        self::clear_event( $schedule_id );

        if ( self::is_schedule_enabled( $schedule ) ) {
            self::ensure_event_exists( $schedule );
        }

        return self::normalise_schedule( $schedule );
    }

    /**
     * Returns all schedules as indexed array.
     *
     * @return array
     */
    public static function list_schedules() {
        return array_values( self::get_schedules() );
    }

    /**
     * Returns next scheduled run timestamp across all enabled schedules.
     *
     * @return int|null
     */
    public static function get_next_run_timestamp() {
        $schedules = self::get_schedules();
        if ( empty( $schedules ) ) {
            return null;
        }

        $next = null;
        foreach ( $schedules as $schedule ) {
            if ( ! self::is_schedule_enabled( $schedule ) ) {
                continue;
            }

            $candidate = isset( $schedule['next_run'] ) ? (int) $schedule['next_run'] : 0;

            if ( $candidate <= 0 ) {
                $candidate = self::compute_next_timestamp( $schedule );
            }

            if ( $next === null || $candidate < $next ) {
                $next = $candidate;
            }
        }

        return $next;
    }

    /**
     * Deletes a schedule.
     *
     * @param string $schedule_id Schedule ID.
     * @return bool
     */
    private static function delete_schedule( $schedule_id ) {
        $schedules = self::get_schedules();

        if ( empty( $schedules[ $schedule_id ] ) ) {
            return false;
        }

        unset( $schedules[ $schedule_id ] );
        update_option( self::OPTION_KEY, $schedules );

        self::clear_event( $schedule_id );

        return true;
    }

    /**
     * Enables/disables a schedule.
     *
     * @param string $schedule_id Schedule ID.
     * @param string $status      New status.
     * @return array|false
     */
    private static function toggle_schedule_status( $schedule_id, $status ) {
        $schedules = self::get_schedules();

        if ( empty( $schedules[ $schedule_id ] ) ) {
            return false;
        }

        $schedule = $schedules[ $schedule_id ];
        $schedule['status'] = ( 'enabled' === $status ) ? 'enabled' : 'disabled';

        $schedule['next_run'] = self::compute_next_timestamp( $schedule );

        $schedules[ $schedule_id ] = $schedule;
        update_option( self::OPTION_KEY, $schedules );

        self::clear_event( $schedule_id );

        if ( self::is_schedule_enabled( $schedule ) ) {
            self::ensure_event_exists( $schedule );
        }

        return $schedule;
    }

    /**
     * Runs selected schedule immediately.
     *
     * @param string $schedule_id Schedule ID.
     * @return array|WP_Error
     */
    private static function run_schedule_now( $schedule_id ) {
        $schedules = self::get_schedules();

        if ( empty( $schedules[ $schedule_id ] ) ) {
            return new WP_Error( 'schedule_not_found', __( 'Schedule not found.', 'museder-restoreone' ) );
        }

        $schedule = $schedules[ $schedule_id ];

        $schedule['last_run']    = current_time( 'mysql' );
        $schedule['retry_count'] = 0;

        $result = Backup_Lite_Backup::backup_site();

        if ( empty( $result['success'] ) ) {
            $schedule['last_result'] = 'failed';
            $schedule['last_error']  = $result['message'] ?? __( 'Failed to start backup.', 'museder-restoreone' );
            $schedules[ $schedule_id ] = $schedule;
            update_option( self::OPTION_KEY, $schedules );

            Backup_Lite_Log_Handler::record_event(
                'schedule_result',
                [
                    'schedule_id' => $schedule_id,
                    'title'       => $schedule['title'],
                    'status'      => 'failed',
                    'attempt'     => 0,
                    'trigger'     => 'manual',
                    'message'     => $schedule['last_error'],
                ],
                'error'
            );

            return new WP_Error( 'backup_failed', __( 'Failed to start backup.', 'museder-restoreone' ) );
        }

        $schedule['last_result'] = 'success';
        $schedule['last_error']  = '';

        $schedule['last_run'] = current_time( 'mysql' );
        $schedule['next_run'] = self::compute_next_timestamp( $schedule );

        $schedules[ $schedule_id ] = $schedule;
        update_option( self::OPTION_KEY, $schedules );

        if ( self::is_schedule_enabled( $schedule ) ) {
            self::ensure_event_exists( $schedule );
        }

        self::apply_retention_rules( $schedule );
        Backup_Lite_Log_Handler::record_event(
            'schedule_result',
            [
                'schedule_id' => $schedule_id,
                'title'       => $schedule['title'],
                'status'      => 'success',
                'attempt'     => 0,
                'trigger'     => 'manual',
            ]
        );

        return $schedule;
    }

    /**
     * Applies retention rules to existing backups for a schedule.
     *
     * @param array $schedule Schedule.
     */
    private static function apply_retention_rules( array $schedule ) {
        $backup_dir = trailingslashit( backup_lite_get_backup_dir() );
        $files      = glob( $backup_dir . '*.{zip,wpress}', GLOB_BRACE );

        if ( empty( $files ) ) {
            return;
        }

        rsort( $files ); // newest first.

        $retain  = isset( $schedule['retain'] ) ? (int) $schedule['retain'] : 0;
        $max_age = isset( $schedule['max_age'] ) ? (int) $schedule['max_age'] : 0;
        $now     = current_time( 'timestamp' );

        if ( $retain > 0 && count( $files ) > $retain ) {
            $excess = array_slice( $files, $retain );
            foreach ( $excess as $file ) {
                @unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            }
        }

        if ( $max_age > 0 ) {
            $threshold = $now - ( $max_age * DAY_IN_SECONDS );
            foreach ( $files as $file ) {
                if ( filemtime( $file ) < $threshold ) {
                    @unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                }
            }
        }
    }

    /**
     * Returns stored schedules.
     *
     * @return array
     */
    private static function get_schedules() {
        $stored = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $stored ) ) {
            return [];
        }

        foreach ( $stored as $id => $schedule ) {
            if ( ! is_array( $schedule ) ) {
                continue;
            }
            try {
                $stored[ $id ] = self::normalise_schedule( $schedule );
            } catch ( Exception $e ) {
                // Skip invalid schedule entries
                unset( $stored[ $id ] );
            }
        }

        return $stored;
    }

    /**
     * Checks if schedule is enabled.
     *
     * @param array $schedule Schedule.
     * @return bool
     */
    private static function is_schedule_enabled( $schedule ) {
        return isset( $schedule['status'] ) && 'disabled' !== $schedule['status'];
    }

    /**
     * Ensures cron event exists for schedule.
     *
     * @param array $schedule Schedule.
     */
    private static function ensure_event_exists( array $schedule ) {
        $args = [ $schedule['id'], 0 ];

        $timestamp = wp_next_scheduled( self::CRON_HOOK, $args );
        if ( ! $timestamp ) {
            // Clean up legacy scheduled events that may still exist.
            $legacy_timestamp = wp_next_scheduled( self::CRON_HOOK, [ $schedule['id'] ] );
            if ( $legacy_timestamp ) {
                wp_unschedule_event( $legacy_timestamp, self::CRON_HOOK, [ $schedule['id'] ] );
            }
        }

        if ( ! $timestamp ) {
            $next = isset( $schedule['next_run'] ) ? (int) $schedule['next_run'] : self::compute_next_timestamp( $schedule );

            if ( $next <= time() ) {
                $next = self::compute_next_timestamp( $schedule, time() );
            }

            $recurrence = self::map_period_to_recurrence( $schedule['period'] );
            wp_schedule_event( $next, $recurrence, self::CRON_HOOK, $args );
        }
    }

    /**
     * Clears scheduled event.
     *
     * @param string $schedule_id Schedule ID.
     */
    private static function clear_event( $schedule_id ) {
        wp_clear_scheduled_hook( self::CRON_HOOK, [ $schedule_id ] );
        wp_clear_scheduled_hook( self::CRON_HOOK, [ $schedule_id, 0 ] );
        wp_clear_scheduled_hook( self::CRON_HOOK, [ $schedule_id, 1 ] );
        wp_clear_scheduled_hook( self::CRON_HOOK, [ $schedule_id, 2 ] );
    }

    /**
     * Maps schedule period to cron recurrence.
     *
     * @param string $period Schedule period.
     * @return string
     */
    private static function map_period_to_recurrence( $period ) {
        switch ( $period ) {
            case 'weekly':
                return 'weekly';
            case 'monthly':
                return self::MONTHLY_INTERVAL;
            case 'daily':
            default:
                return 'daily';
        }
    }

    /**
     * Computes next timestamp for schedule.
     *
     * @param array    $schedule Schedule.
     * @param int|null $timestamp Base timestamp.
     * @return int
     */
    private static function compute_next_timestamp( array $schedule, $timestamp = null ) {
        $timezone = wp_timezone();
        $now      = new DateTime( 'now', $timezone );

        if ( null !== $timestamp ) {
            $now->setTimestamp( $timestamp );
        }

        $time_parts = explode( ':', $schedule['time'] );
        $hour       = isset( $time_parts[0] ) ? (int) $time_parts[0] : 0;
        $minute     = isset( $time_parts[1] ) ? (int) $time_parts[1] : 0;

        $next = clone $now;
        $next->setTime( $hour, $minute, 0 );

        if ( $next <= $now ) {
            switch ( $schedule['period'] ) {
                case 'weekly':
                    $next->modify( '+1 week' );
                    break;
                case 'monthly':
                    $next->modify( '+1 month' );
                    break;
                case 'daily':
                default:
                    $next->modify( '+1 day' );
                    break;
            }
        }

        return $next->getTimestamp();
    }

    /**
     * Reads schedule data from request.
     *
     * @return array
     */
    private static function read_schedule_data() {
        $raw = isset( $_POST['schedule'] ) ? wp_unslash( $_POST['schedule'] ) : '';

        if ( empty( $raw ) ) {
            $raw = file_get_contents( 'php://input' ); // Fallback for JSON payload.
        }

        $decoded = json_decode( $raw, true );

        if ( ! is_array( $decoded ) ) {
            $decoded = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        }

        $settings = Backup_Lite_Settings::get_settings();

        $result = [
            'title'   => isset( $decoded['title'] ) ? sanitize_text_field( $decoded['title'] ) : __( 'Scheduled Backup', 'museder-restoreone' ),
            'type'    => isset( $decoded['type'] ) ? sanitize_text_field( $decoded['type'] ) : 'backup',
            'period'  => isset( $decoded['period'] ) ? sanitize_text_field( $decoded['period'] ) : 'daily',
            'time'    => isset( $decoded['time'] ) ? sanitize_text_field( $decoded['time'] ) : '00:00',
            'retain'  => isset( $decoded['retain'] ) ? absint( $decoded['retain'] ) : 5,
            'max_age' => isset( $decoded['max_age'] ) ? absint( $decoded['max_age'] ) : 30,
            'notify'  => isset( $decoded['notify'] ) ? sanitize_email( $decoded['notify'] ) : ( $settings['notification_email'] ?? get_option( 'admin_email' ) ),
            'status'  => ( isset( $decoded['status'] ) && 'disabled' === $decoded['status'] ) ? 'disabled' : 'enabled',
        ];

        // PRO features
        if ( Backup_Lite_Pro::is_pro_active() ) {
            // Custom cron pattern
            if ( isset( $decoded['cron_pattern'] ) ) {
                $result['cron_pattern'] = sanitize_text_field( $decoded['cron_pattern'] );
            }

            // Exclusion paths
            if ( isset( $decoded['exclude_paths'] ) && is_array( $decoded['exclude_paths'] ) ) {
                $result['exclude_paths'] = array_map( 'sanitize_text_field', $decoded['exclude_paths'] );
            }

            // Smart retention policy
            if ( isset( $decoded['retention_policy'] ) ) {
                $result['retention_policy'] = sanitize_text_field( $decoded['retention_policy'] );
            }
        }

        if ( empty( $result['time'] ) || ! preg_match( '/^\d{2}:\d{2}$/', $result['time'] ) ) {
            $result['time'] = '00:00';
        }

        if ( ! in_array( $result['period'], [ 'daily', 'weekly', 'monthly' ], true ) ) {
            $result['period'] = 'daily';
        }

        if ( ! in_array( $result['type'], [ 'backup', 'restore' ], true ) ) {
            $result['type'] = 'backup';
        }

        return $result;
    }

    /**
     * Runs verification for AJAX requests.
     */
    private static function verify_ajax() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'museder-restoreone' ) ], 403 );
        }

        check_ajax_referer( Backup_Lite_UI::NONCE, 'nonce' );
    }

    /**
     * Ensures schedule arrays contain expected keys.
     *
     * @param array $schedule Schedule array.
     * @return array
     */
    private static function normalise_schedule( $schedule ) {
        if ( ! is_array( $schedule ) ) {
            $schedule = [];
        }
        if ( ! isset( $schedule['last_run'] ) ) {
            $schedule['last_run'] = '';
        }

        if ( ! isset( $schedule['last_result'] ) ) {
            $schedule['last_result'] = 'pending';
        }

        if ( ! isset( $schedule['last_error'] ) ) {
            $schedule['last_error'] = '';
        }

        if ( ! isset( $schedule['retry_count'] ) ) {
            $schedule['retry_count'] = 0;
        }

        if ( ! isset( $schedule['next_run'] ) ) {
            $schedule['next_run'] = self::compute_next_timestamp( $schedule );
        }

        // PRO features defaults
        if ( class_exists( 'Backup_Lite_Pro' ) && method_exists( 'Backup_Lite_Pro', 'is_pro_active' ) && Backup_Lite_Pro::is_pro_active() ) {
            if ( ! isset( $schedule['cron_pattern'] ) ) {
                $schedule['cron_pattern'] = '';
            }
            if ( ! isset( $schedule['exclude_paths'] ) ) {
                $schedule['exclude_paths'] = [];
            }
            if ( ! isset( $schedule['retention_policy'] ) ) {
                $schedule['retention_policy'] = '';
            }
        }

        return $schedule;
    }

    /**
     * Get AI schedule recommendations.
     * 
     * @return array
     */
    public static function get_ai_recommendations() {
        if ( ! Backup_Lite_Pro::is_pro_active() ) {
            return [
                'error'   => 'pro_required',
                'message' => __( 'This feature requires Museder RestoreOne PRO.', 'museder-restoreone' ),
            ];
        }

        return Backup_Lite_AI_Service::get_smart_schedule();
    }
}

