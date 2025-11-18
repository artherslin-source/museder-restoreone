<?php
/**
 * Backup Lite PRO - AI Controller
 * 
 * REST API endpoints for AI features.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AI Controller class for REST API endpoints.
 */
class Backup_Lite_AI_Controller {

    const NAMESPACE = 'backup-lite/v2';
    const BASE      = 'pro/ai';

    /**
     * Initialize the controller.
     */
    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    /**
     * Register REST API routes.
     */
    public static function register_routes() {
        register_rest_route(
            self::NAMESPACE,
            '/' . self::BASE . '/setup-analyze',
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'handle_setup_analyze' ],
                'permission_callback' => [ __CLASS__, 'check_permission' ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/' . self::BASE . '/health-score',
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'handle_health_score' ],
                'permission_callback' => [ __CLASS__, 'check_permission' ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/' . self::BASE . '/restore-summary/(?P<job_id>[a-zA-Z0-9\-_]+)',
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'handle_restore_summary' ],
                'permission_callback' => [ __CLASS__, 'check_permission' ],
                'args'                => [
                    'job_id' => [
                        'required' => true,
                        'type'     => 'string',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/' . self::BASE . '/diagnose-log',
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'handle_diagnose_log' ],
                'permission_callback' => [ __CLASS__, 'check_permission' ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/' . self::BASE . '/smart-schedule',
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'handle_smart_schedule' ],
                'permission_callback' => [ __CLASS__, 'check_permission' ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/' . self::BASE . '/nl-command',
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'handle_nl_command' ],
                'permission_callback' => [ __CLASS__, 'check_permission' ],
            ]
        );
    }

    /**
     * Check user permission.
     * 
     * @return bool
     */
    public static function check_permission() {
        return current_user_can( 'manage_options' );
    }

    /**
     * Handle setup analyze request.
     * 
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_setup_analyze( $request ) {
        $site_meta      = $request->get_param( 'site_meta' ) ?: [];
        $backup_history = $request->get_param( 'backup_history' ) ?: [];

        $result = Backup_Lite_AI_Service::analyze_site( $site_meta, $backup_history );

        if ( isset( $result['error'] ) ) {
            return new WP_Error( 'pro_required', $result['message'], [ 'status' => 403 ] );
        }

        return rest_ensure_response( $result );
    }

    /**
     * Handle health score request.
     * 
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_health_score( $request ) {
        $result = Backup_Lite_AI_Service::get_health_score();

        if ( isset( $result['error'] ) ) {
            return new WP_Error( 'pro_required', $result['message'], [ 'status' => 403 ] );
        }

        return rest_ensure_response( $result );
    }

    /**
     * Handle restore summary request.
     * 
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_restore_summary( $request ) {
        $job_id = $request->get_param( 'job_id' );

        $result = Backup_Lite_AI_Service::get_restore_summary( $job_id );

        if ( isset( $result['error'] ) ) {
            return new WP_Error( 'pro_required', $result['message'], [ 'status' => 403 ] );
        }

        return rest_ensure_response( $result );
    }

    /**
     * Handle diagnose log request.
     * 
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_diagnose_log( $request ) {
        $log_content = $request->get_param( 'log_content' ) ?: '';

        $result = Backup_Lite_AI_Service::diagnose_log( $log_content );

        if ( isset( $result['error'] ) ) {
            return new WP_Error( 'pro_required', $result['message'], [ 'status' => 403 ] );
        }

        return rest_ensure_response( $result );
    }

    /**
     * Handle smart schedule request.
     * 
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_smart_schedule( $request ) {
        $result = Backup_Lite_AI_Service::get_smart_schedule();

        if ( isset( $result['error'] ) ) {
            return new WP_Error( 'pro_required', $result['message'], [ 'status' => 403 ] );
        }

        return rest_ensure_response( $result );
    }

    /**
     * Handle natural language command request.
     * 
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_nl_command( $request ) {
        $command = $request->get_param( 'command' ) ?: '';

        $result = Backup_Lite_AI_Service::process_nl_command( $command );

        if ( isset( $result['error'] ) ) {
            return new WP_Error( 'pro_required', $result['message'], [ 'status' => 403 ] );
        }

        return rest_ensure_response( $result );
    }
}

