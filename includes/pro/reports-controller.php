<?php
/**
 * Backup Lite PRO - Reports Controller
 * 
 * REST API endpoints for Reports features.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reports Controller class for REST API endpoints.
 */
class Backup_Lite_Reports_Controller {

    const NAMESPACE = 'backup-lite/v2';
    const BASE      = 'pro/reports';

    /**
     * Initialize the controller.
     */
    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
        add_action( 'admin_post_backup_lite_download_report', [ __CLASS__, 'handle_report_download' ] );
    }

    /**
     * Register REST API routes.
     */
    public static function register_routes() {
        register_rest_route(
            self::NAMESPACE,
            '/' . self::BASE . '/system-check',
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'handle_system_check' ],
                'permission_callback' => [ __CLASS__, 'check_permission' ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/' . self::BASE . '/trends',
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'handle_trends' ],
                'permission_callback' => [ __CLASS__, 'check_permission' ],
                'args'                => [
                    'days' => [
                        'default' => 30,
                        'type'    => 'integer',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/' . self::BASE . '/ai-analysis',
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'handle_ai_analysis' ],
                'permission_callback' => [ __CLASS__, 'check_permission' ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/' . self::BASE . '/generate-json',
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'handle_generate_json' ],
                'permission_callback' => [ __CLASS__, 'check_permission' ],
            ]
        );

        register_rest_route(
            self::NAMESPACE,
            '/' . self::BASE . '/generate-pdf',
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'handle_generate_pdf' ],
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
     * Handle system check request.
     * 
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_system_check( $request ) {
        $result = Backup_Lite_Reports_Service::get_system_check();

        if ( isset( $result['error'] ) ) {
            return new WP_Error( 'pro_required', $result['message'], [ 'status' => 403 ] );
        }

        return rest_ensure_response( $result );
    }

    /**
     * Handle trends request.
     * 
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_trends( $request ) {
        $days = $request->get_param( 'days' ) ?: 30;
        $result = Backup_Lite_Reports_Service::get_backup_trends( $days );

        if ( isset( $result['error'] ) ) {
            return new WP_Error( 'pro_required', $result['message'], [ 'status' => 403 ] );
        }

        return rest_ensure_response( $result );
    }

    /**
     * Handle AI analysis request.
     * 
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_ai_analysis( $request ) {
        $result = Backup_Lite_Reports_Service::get_ai_event_analysis();

        if ( isset( $result['error'] ) ) {
            return new WP_Error( 'pro_required', $result['message'], [ 'status' => 403 ] );
        }

        return rest_ensure_response( $result );
    }

    /**
     * Handle generate JSON report request.
     * 
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_generate_json( $request ) {
        $result = Backup_Lite_Reports_Service::generate_json_report();

        if ( isset( $result['error'] ) ) {
            return new WP_Error( 'generation_failed', $result['message'], [ 'status' => 500 ] );
        }

        return rest_ensure_response( $result );
    }

    /**
     * Handle generate PDF report request.
     * 
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_generate_pdf( $request ) {
        $result = Backup_Lite_Reports_Service::generate_pdf_report();

        if ( isset( $result['error'] ) ) {
            return new WP_Error( 'generation_failed', $result['message'], [ 'status' => 500 ] );
        }

        return rest_ensure_response( $result );
    }

    /**
     * Handle report file download.
     */
    public static function handle_report_download() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'museder-restoreone' ) );
        }

        $file = sanitize_text_field( wp_unslash( $_GET['file'] ?? '' ) );
        if ( empty( $file ) ) {
            wp_die( esc_html__( 'File not specified.', 'museder-restoreone' ) );
        }

        $reports_dir = backup_lite_get_pro_reports_dir();
        $path = trailingslashit( $reports_dir ) . basename( $file );
        $path = wp_normalize_path( $path );

        if ( ! file_exists( $path ) ) {
            wp_die( esc_html__( 'Report file not found.', 'museder-restoreone' ) );
        }

        // Verify path is within reports directory
        if ( strpos( $path, wp_normalize_path( $reports_dir ) ) !== 0 ) {
            wp_die( esc_html__( 'Invalid file path.', 'museder-restoreone' ) );
        }

        // Verify nonce
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'backup_lite_download_report_' . basename( $path ) ) ) {
            wp_die( esc_html__( 'Security check failed.', 'museder-restoreone' ) );
        }

        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        $mime = 'application/json';
        if ( 'pdf' === $ext ) {
            $mime = 'application/pdf';
        }

        // @plugin-check: sanitized - safe whitelisted mime type
        header( 'Content-Type: ' . $mime );
        $download_filename = sanitize_file_name( basename( $path ) ); // @plugin-check: sanitized
        header( 'Content-Disposition: attachment; filename="' . $download_filename . '"' );
        header( 'Content-Length: ' . filesize( $path ) );

        readfile( $path );
        exit;
    }
}

