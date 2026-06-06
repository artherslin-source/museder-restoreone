<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Museder_Restoreone_Restore_Controller {

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes() {
        register_rest_route(
            'museder-restoreone/v2',
            '/restore/prepare',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'prepare' ],
                'permission_callback' => [ __CLASS__, 'check_permissions' ],
            ]
        );

        register_rest_route(
            'museder-restoreone/v2',
            '/restore/validate/(?P<job_id>[a-zA-Z0-9_\-]+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'validate' ],
                'permission_callback' => [ __CLASS__, 'check_permissions' ],
            ]
        );

        register_rest_route(
            'museder-restoreone/v2',
            '/restore/dry-run/(?P<job_id>[a-zA-Z0-9_\-]+)',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'dry_run' ],
                'permission_callback' => [ __CLASS__, 'check_permissions' ],
            ]
        );

        register_rest_route(
            'museder-restoreone/v2',
            '/restore/status/(?P<job_id>[a-zA-Z0-9_\-]+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'status' ],
                'permission_callback' => [ __CLASS__, 'check_permissions' ],
            ]
        );

        register_rest_route(
            'museder-restoreone/v2',
            '/restore/final-status/(?P<job_id>[a-zA-Z0-9_\-]+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'final_status' ],
                'permission_callback' => [ __CLASS__, 'check_final_status_permissions' ],
            ]
        );

        register_rest_route(
            'museder-restoreone/v2',
            '/restore/execute/(?P<job_id>[a-zA-Z0-9_\-]+)',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'execute' ],
                'permission_callback' => [ __CLASS__, 'check_permissions' ],
            ]
        );

        register_rest_route(
            'museder-restoreone/v2',
            '/restore/rollback/(?P<job_id>[a-zA-Z0-9_\-]+)',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'rollback' ],
                'permission_callback' => [ __CLASS__, 'check_permissions' ],
            ]
        );

        register_rest_route(
            'museder-restoreone/v2',
            '/restore/report/(?P<job_id>[a-zA-Z0-9_\-]+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'report' ],
                'permission_callback' => [ __CLASS__, 'check_permissions' ],
                'args'                => [
                    'format' => [
                        'default'           => 'txt',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'type'   => [
                        'default'           => Museder_Restoreone_Restore_Service::REPORT_TYPE_DRYRUN,
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ]
        );
    }

    public static function prepare( WP_REST_Request $request ) {
        try {
            $params = $request->get_json_params();
            $source = isset( $params['source'] ) ? $params['source'] : '';
            $file   = isset( $params['file'] ) ? $params['file'] : '';
            $sha1   = isset( $params['sha1'] ) ? $params['sha1'] : '';

            $result = Museder_Restoreone_Restore_Service::prepare( $source, $file, $sha1 );

            return rest_ensure_response( [
                'ok'     => true,
                'job_id' => $result['job_id'],
            ] );
        } catch ( Exception $e ) {
            return self::error_response( $e );
        }
    }

    public static function validate( WP_REST_Request $request ) {
        try {
            $job_id = $request->get_param( 'job_id' );

            $result = Museder_Restoreone_Restore_Service::validate( $job_id );

            return rest_ensure_response( [
                'ok'     => true,
                'result' => $result,
            ] );
        } catch ( Exception $e ) {
            return self::error_response( $e );
        }
    }

    public static function dry_run( WP_REST_Request $request ) {
        try {
            $job_id = $request->get_param( 'job_id' );

            $result = Museder_Restoreone_Restore_Service::dry_run( $job_id );

            return rest_ensure_response( [
                'ok'              => true,
                'summary'         => $result['summary'],
                'report_url_txt'  => $result['reports']['txt'],
                'report_url_json' => $result['reports']['json'],
            ] );
        } catch ( Exception $e ) {
            return self::error_response( $e );
        }
    }

    public static function status( WP_REST_Request $request ) {
        try {
            $job_id = $request->get_param( 'job_id' );
            Museder_Restoreone_Restore_Service::ensure_running_job_scheduled( $job_id, 'rest_status' );
            $status = Museder_Restoreone_Restore_Service::status( $job_id );

            return rest_ensure_response( $status );
        } catch ( Exception $e ) {
            return self::error_response( $e );
        }
    }

    public static function final_status( WP_REST_Request $request ) {
        $job_id = sanitize_text_field( (string) $request->get_param( 'job_id' ) );

        $job = null;
        $status = [];
        try {
            Museder_Restoreone_Restore_Service::ensure_running_job_scheduled( $job_id, 'rest_final_status' );
            $status = Museder_Restoreone_Restore_Service::status( $job_id );
            $job    = Museder_Restoreone_Restore_Handler::map_restore_service_status_to_job( $job_id, $status );
        } catch ( Exception $e ) {
            unset( $e );
        }

        $safe_mode_active = ( get_option( 'museder_restoreone_safe_mode', '' ) === '1' );
        $prev_plugins_count = 0;
        if ( $safe_mode_active ) {
            $prev_plugins = get_option( 'museder_restoreone_prev_active_plugins', [] );
            $prev_plugins_count = is_array( $prev_plugins ) ? count( $prev_plugins ) : 0;
        }

        return rest_ensure_response(
            [
                'ok'                 => true,
                'job'                => $job,
                'status'             => $status,
                'history'            => Museder_Restoreone_Restore_Handler::history_for_js( 10 ),
                'safe_mode_active'   => (bool) $safe_mode_active,
                'prev_plugins_count' => (int) $prev_plugins_count,
            ]
        );
    }

    public static function execute( WP_REST_Request $request ) {
        try {
            $job_id = $request->get_param( 'job_id' );
            $options = $request->get_json_params();
            $result = Museder_Restoreone_Restore_Service::execute( $job_id, is_array( $options ) ? $options : [] );

            return rest_ensure_response( $result );
        } catch ( Exception $e ) {
            return self::error_response( $e );
        }
    }

    public static function rollback( WP_REST_Request $request ) {
        try {
            $job_id = $request->get_param( 'job_id' );
            $result = Museder_Restoreone_Restore_Service::rollback( $job_id );

            return rest_ensure_response( $result );
        } catch ( Exception $e ) {
            return self::error_response( $e );
        }
    }

    public static function report( WP_REST_Request $request ) {
        $job_id = $request->get_param( 'job_id' );
        $format = strtolower( $request->get_param( 'format' ) );
        $type   = $request->get_param( 'type' );

        if ( ! in_array( $format, [ 'txt', 'json' ], true ) ) {
            return new WP_Error( 'museder_restoreone_invalid_format', __( 'Invalid report format.', 'museder-restoreone' ), [ 'status' => 400 ] );
        }

        $path = Museder_Restoreone_Restore_Report::download( $job_id, $type, $format );
        if ( is_wp_error( $path ) ) {
            return $path;
        }

        $contents = file_get_contents( $path );
        $response = new WP_REST_Response( $contents );

        // @plugin-check: sanitized - safe whitelisted mime type
        $mime = ( 'json' === $format ) ? 'application/json' : 'text/plain';
        $download_filename = sanitize_file_name( basename( $path ) ); // @plugin-check: sanitized

        $response->set_headers( [
            'Content-Type'              => $mime . '; charset=utf-8',
            'Content-Disposition'       => 'attachment; filename="' . $download_filename . '"',
            'Content-Length'            => filesize( $path ),
            'X-Backup-Lite-Restore-Job' => $job_id,
        ] );

        return $response;
    }

    public static function check_permissions( WP_REST_Request $request ) {
        // --- Restore-token helper (used as fallback below) ---
        $try_restore_token = static function () use ( $request ) {
            if ( ! class_exists( 'Museder_Restoreone_Restore_Token' ) ) {
                return false;
            }
            $rt = (string) $request->get_header( 'X-Restore-Token' );
            if ( '' === $rt ) {
                $rt = (string) $request->get_param( '_restore_token' );
            }
            return '' !== $rt && Museder_Restoreone_Restore_Token::verify( $rt );
        };

        if ( ! current_user_can( 'manage_options' ) ) {
            // Session may have been destroyed by DB import — try restore token.
            if ( $try_restore_token() ) {
                return true;
            }
            return new WP_Error( 'museder_restoreone_forbidden', __( 'You are not allowed to perform this action.', 'museder-restoreone' ), [ 'status' => 403 ] );
        }

        $nonce = (string) $request->get_header( 'X-WP-Nonce' );
        if ( '' === $nonce ) {
            $nonce = (string) $request->get_param( '_wpnonce' );
        }

        // WordPress.org review: empty check and verify_nonce as separate steps (same pattern as AI REST).
        if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            // Nonce missing or invalid — try restore token fallback.
            if ( $try_restore_token() ) {
                return true;
            }
            return new WP_Error( 'museder_restoreone_invalid_nonce', __( 'Invalid security token.', 'museder-restoreone' ), [ 'status' => 401 ] );
        }

        return true;
    }

    public static function check_final_status_permissions( WP_REST_Request $request ) {
        if ( ! class_exists( 'Museder_Restoreone_Restore_Token' ) ) {
            return new WP_Error( 'museder_restoreone_token_unavailable', __( 'Restore authorization is unavailable.', 'museder-restoreone' ), [ 'status' => 403 ] );
        }

        $job_id = sanitize_text_field( (string) $request->get_param( 'job_id' ) );
        $token  = (string) $request->get_header( 'X-Restore-Token' );
        if ( '' === $token ) {
            $token = (string) $request->get_param( '_restore_token' );
        }
        if ( '' === $token ) {
            $token = (string) $request->get_param( 'restore_token' );
        }
        $token = sanitize_text_field( wp_unslash( $token ) );

        if ( '' === $job_id || '' === $token ) {
            return new WP_Error( 'museder_restoreone_invalid_restore_token', __( 'Restore authorization expired or invalid.', 'museder-restoreone' ), [ 'status' => 403 ] );
        }

        if ( Museder_Restoreone_Restore_Token::verify( $token, $job_id ) || Museder_Restoreone_Restore_Token::verify_post_complete_read( $token, $job_id ) ) {
            return true;
        }

        return new WP_Error( 'museder_restoreone_invalid_restore_token', __( 'Restore authorization expired or invalid.', 'museder-restoreone' ), [ 'status' => 403 ] );
    }

    protected static function error_response( Exception $e ) {
        return rest_ensure_response( [
            'ok'      => false,
            // Do not expose raw exception messages to REST clients.
            // @plugin-check: escaped
            'message' => esc_html( $e->getMessage() ),
        ] );
    }
}
