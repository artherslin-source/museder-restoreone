<?php
/**
 * AI REST controller (Free scaffolding).
 *
 * @package MusederRestoreOne
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Museder_AI_REST_Controller {
    public const REST_NAMESPACE = 'museder/v1';

    /**
     * Init hooks.
     *
     * @return void
     */
    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    /**
     * Register routes.
     *
     * @return void
     */
    public static function register_routes(): void {
        register_rest_route(
            self::REST_NAMESPACE,
            '/ai/scan',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'handle_scan' ],
                'permission_callback' => [ __CLASS__, 'permission_check' ],
            ]
        );

        register_rest_route(
            self::REST_NAMESPACE,
            '/ai/reports',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'handle_reports' ],
                'permission_callback' => [ __CLASS__, 'permission_check' ],
                'args'                => [
                    'limit' => [
                        'type'              => 'integer',
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                        'default'           => 5,
                    ],
                ],
            ]
        );
    }

    /**
     * Permission check for AI routes.
     *
     * @param WP_REST_Request $request Request.
     * @return true|WP_Error
     */
    public static function permission_check( WP_REST_Request $request ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error(
                'museder_ai_forbidden',
                __( 'You do not have permission to access this resource.', 'museder-restoreone' ),
                [ 'status' => 403 ]
            );
        }

        // Require REST nonce for cookie-authenticated requests.
        $nonce = (string) $request->get_header( 'X-WP-Nonce' );
        if ( '' === $nonce ) {
            $nonce = (string) $request->get_param( '_wpnonce' );
        }

        if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error(
                'museder_ai_invalid_nonce',
                __( 'Invalid security token.', 'museder-restoreone' ),
                [ 'status' => 403 ]
            );
        }

        return true;
    }

    /**
     * POST /ai/scan
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public static function handle_scan( WP_REST_Request $request ) {
        $user_id = get_current_user_id();

        $rate = new Museder_AI_Rate_Limiter();
        $ok   = $rate->assert_allowed_and_increment( (int) $user_id );
        if ( is_wp_error( $ok ) ) {
            return $ok;
        }

        $payload_raw = $request->get_json_params();
        $payload     = Museder_AI_Sanitizer::sanitize_payload( $payload_raw );

        $provider = Museder_AI_Factory::provider();
        $result   = $provider->scan(
            $payload,
            [
                'user_id' => (int) $user_id,
            ]
        );

        $repo   = new Museder_AI_Report_Repository();
        $stored = $repo->add_report( $result );

        return rest_ensure_response(
            [
                'ok'        => true,
                'remaining' => $rate->get_remaining( (int) $user_id ),
                'report'    => $stored,
            ]
        );
    }

    /**
     * GET /ai/reports
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public static function handle_reports( WP_REST_Request $request ): WP_REST_Response {
        $limit = absint( $request->get_param( 'limit' ) );
        if ( $limit <= 0 ) {
            $limit = 5;
        }

        $repo = new Museder_AI_Report_Repository();
        return rest_ensure_response(
            [
                'ok'      => true,
                'reports' => $repo->list_reports( $limit ),
            ]
        );
    }
}


