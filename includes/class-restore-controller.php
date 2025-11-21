<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Backup_Lite_Restore_Controller {

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
    }

    public static function register_routes() {
        register_rest_route(
            'backup-lite/v2',
            '/restore/prepare',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'prepare' ],
                'permission_callback' => [ __CLASS__, 'check_permissions' ],
            ]
        );

        register_rest_route(
            'backup-lite/v2',
            '/restore/validate/(?P<job_id>[a-zA-Z0-9_\-]+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'validate' ],
                'permission_callback' => [ __CLASS__, 'check_permissions' ],
            ]
        );

        register_rest_route(
            'backup-lite/v2',
            '/restore/dry-run/(?P<job_id>[a-zA-Z0-9_\-]+)',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'dry_run' ],
                'permission_callback' => [ __CLASS__, 'check_permissions' ],
            ]
        );

        register_rest_route(
            'backup-lite/v2',
            '/restore/status/(?P<job_id>[a-zA-Z0-9_\-]+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'status' ],
                'permission_callback' => [ __CLASS__, 'check_permissions' ],
            ]
        );

        register_rest_route(
            'backup-lite/v2',
            '/restore/execute/(?P<job_id>[a-zA-Z0-9_\-]+)',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'execute' ],
                'permission_callback' => [ __CLASS__, 'check_permissions' ],
            ]
        );

        register_rest_route(
            'backup-lite/v2',
            '/restore/rollback/(?P<job_id>[a-zA-Z0-9_\-]+)',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'rollback' ],
                'permission_callback' => [ __CLASS__, 'check_permissions' ],
            ]
        );

        register_rest_route(
            'backup-lite/v2',
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
                        'default'           => Backup_Lite_Restore_Service::REPORT_TYPE_DRYRUN,
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

            $result = Backup_Lite_Restore_Service::prepare( $source, $file, $sha1 );

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

            $result = Backup_Lite_Restore_Service::validate( $job_id );

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

            $result = Backup_Lite_Restore_Service::dry_run( $job_id );

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
            $status = Backup_Lite_Restore_Service::status( $job_id );

            return rest_ensure_response( $status );
        } catch ( Exception $e ) {
            return self::error_response( $e );
        }
    }

    public static function execute( WP_REST_Request $request ) {
        try {
            $job_id = $request->get_param( 'job_id' );
            $options = $request->get_json_params();
            $result = Backup_Lite_Restore_Service::execute( $job_id, is_array( $options ) ? $options : [] );

            return rest_ensure_response( $result );
        } catch ( Exception $e ) {
            return self::error_response( $e );
        }
    }

    public static function rollback( WP_REST_Request $request ) {
        try {
            $job_id = $request->get_param( 'job_id' );
            $result = Backup_Lite_Restore_Service::rollback( $job_id );

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
            return new WP_Error( 'backup_lite_invalid_format', __( 'Invalid report format.', 'museder-restoreone' ), [ 'status' => 400 ] );
        }

        $path = Backup_Lite_Restore_Report::download( $job_id, $type, $format );
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
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'backup_lite_forbidden', __( 'You are not allowed to perform this action.', 'museder-restoreone' ), [ 'status' => 403 ] );
        }

        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error( 'backup_lite_invalid_nonce', __( 'Invalid security token.', 'museder-restoreone' ), [ 'status' => 401 ] );
        }

        return true;
    }

    protected static function error_response( Exception $e ) {
        return rest_ensure_response( [
            'ok'      => false,
            'message' => $e->getMessage(),
        ] );
    }
}
