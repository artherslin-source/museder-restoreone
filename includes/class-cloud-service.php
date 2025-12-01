<?php
/**
 * Cloud Storage Service
 * 
 * Handles cloud storage operations for backup files.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cloud Storage Service class.
 */
class Museder_Cloud_Service {

    const OPTION_KEY = 'museder_cloud_settings';

    /**
     * Get cloud storage settings.
     * 
     * @return array Settings array with defaults.
     */
    public static function get_settings(): array {
        $defaults = [
            'enabled'       => false,
            'provider'      => 'none', // 'none' | 's3'
            's3_access_key' => '',
            's3_secret_key' => '',
            's3_region'     => '',
            's3_bucket'     => '',
            's3_endpoint'   => '', // optional
        ];

        $stored = get_option( self::OPTION_KEY, [] );
        
        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        return wp_parse_args( $stored, $defaults );
    }

    /**
     * Update cloud storage settings.
     * 
     * @param array $data Settings data to save.
     * @return void
     */
    public static function update_settings( array $data ): void {
        $settings = self::get_settings();

        // Sanitize enabled
        $settings['enabled'] = ! empty( $data['enabled'] );

        // Sanitize provider (only allow 'none' or 's3')
        if ( isset( $data['provider'] ) ) {
            $provider = sanitize_text_field( $data['provider'] );
            $settings['provider'] = in_array( $provider, [ 'none', 's3' ], true ) ? $provider : 'none';
        }

        // Sanitize S3 settings
        if ( isset( $data['s3_access_key'] ) ) {
            $settings['s3_access_key'] = sanitize_text_field( $data['s3_access_key'] );
        }

        // Secret key: only update if provided (not empty)
        if ( isset( $data['s3_secret_key'] ) && ! empty( $data['s3_secret_key'] ) ) {
            $settings['s3_secret_key'] = sanitize_text_field( $data['s3_secret_key'] );
        }

        if ( isset( $data['s3_region'] ) ) {
            $settings['s3_region'] = sanitize_text_field( $data['s3_region'] );
        }

        if ( isset( $data['s3_bucket'] ) ) {
            $settings['s3_bucket'] = sanitize_text_field( $data['s3_bucket'] );
        }

        if ( isset( $data['s3_endpoint'] ) ) {
            $settings['s3_endpoint'] = sanitize_text_field( $data['s3_endpoint'] );
        }

        update_option( self::OPTION_KEY, $settings, false );
    }

    /**
     * Check if cloud storage is enabled and configured.
     * 
     * @return bool
     */
    public static function is_enabled(): bool {
        $settings = self::get_settings();
        
        if ( ! $settings['enabled'] || $settings['provider'] !== 's3' ) {
            return false;
        }

        // Check required S3 fields
        if ( empty( $settings['s3_access_key'] ) || 
             empty( $settings['s3_secret_key'] ) || 
             empty( $settings['s3_region'] ) || 
             empty( $settings['s3_bucket'] ) ) {
            return false;
        }

        return true;
    }

    /**
     * Get current provider.
     * 
     * @return string 's3' or 'none'
     */
    public static function get_provider(): string {
        $settings = self::get_settings();
        return $settings['provider'] === 's3' ? 's3' : 'none';
    }

    /**
     * Decide whether to upload backup to cloud storage.
     * 
     * @param string $backup_file_path Backup zip full path.
     * @param array  $context          Backup context information.
     * @param bool   $should_upload   Whether user checked "upload to cloud".
     * @return array {
     *     @type string $status      'success'|'error'|'skipped'
     *     @type string $message     Result message
     *     @type string $remote_path Remote file path (if successful)
     * }
     */
    public static function maybe_upload_backup( string $backup_file_path, array $context = [], bool $should_upload = true ): array {
        // 1. Check if upload is requested
        if ( ! $should_upload ) {
            return [
                'status'  => 'skipped',
                'message' => __( 'Cloud upload not requested.', 'museder-restoreone' ),
            ];
        }

        // 2. Check if file exists and is readable
        if ( ! file_exists( $backup_file_path ) || ! is_readable( $backup_file_path ) ) {
            return [
                'status'  => 'error',
                'message' => __( 'Backup file not found or unreadable.', 'museder-restoreone' ),
            ];
        }

        // 3. Check if cloud storage is enabled and configured
        if ( ! function_exists( 'museder_restoreone_is_cloud_configured' ) || ! museder_restoreone_is_cloud_configured() ) {
            return [
                'status'  => 'skipped',
                'message' => __( 'Cloud storage is not enabled or not fully configured.', 'museder-restoreone' ),
            ];
        }

        // 5. Delegate to provider-specific upload
        $settings = self::get_settings();
        if ( $settings['provider'] === 's3' ) {
            return self::upload_to_s3( $backup_file_path, $settings, $context );
        }

        return [
            'status'  => 'skipped',
            'message' => __( 'Unknown cloud storage provider.', 'museder-restoreone' ),
        ];
    }

    /**
     * Upload file to S3 or S3-compatible storage.
     * 
     * Uses WordPress HTTP API only (wp_remote_request).
     * Implements AWS Signature V4 with UNSIGNED-PAYLOAD.
     * 
     * @param string $file_path Local file path.
     * @param array  $settings  Cloud settings from get_settings().
     * @param array  $context   Backup context.
     * @return array {
     *     @type string $status      'success'|'error'
     *     @type string $message     Result message
     *     @type string $remote_path Remote file path (if successful)
     * }
     */
    protected static function upload_to_s3( string $file_path, array $settings, array $context = [] ): array {
        // Validate required settings
        if ( empty( $settings['s3_access_key'] ) || 
             empty( $settings['s3_secret_key'] ) || 
             empty( $settings['s3_region'] ) || 
             empty( $settings['s3_bucket'] ) ) {
            return [
                'status'  => 'error',
                'message' => __( 'Cloud storage is not fully configured.', 'museder-restoreone' ),
            ];
        }

        // Determine object key
        $site_hash = md5( home_url() );
        $filename = basename( $file_path );
        $year = gmdate( 'Y' );
        $month = gmdate( 'm' );
        $object_key = sprintf( 'museder/%s/backups/%s/%s/%s', $site_hash, $year, $month, $filename );

        // Determine endpoint
        $endpoint = ! empty( $settings['s3_endpoint'] ) 
            ? $settings['s3_endpoint'] 
            : sprintf( 'https://s3.%s.amazonaws.com', $settings['s3_region'] );

        // Parse endpoint URL
        $endpoint_url = parse_url( $endpoint );
        if ( ! $endpoint_url || ! isset( $endpoint_url['host'] ) ) {
            return [
                'status'  => 'error',
                'message' => __( 'Invalid S3 endpoint URL.', 'museder-restoreone' ),
            ];
        }

        $host = $endpoint_url['host'];
        $scheme = isset( $endpoint_url['scheme'] ) ? $endpoint_url['scheme'] : 'https';
        $port = isset( $endpoint_url['port'] ) ? ':' . $endpoint_url['port'] : '';

        // Build request URL (path-style)
        $request_url = sprintf( '%s://%s%s/%s/%s', $scheme, $host, $port, $settings['s3_bucket'], $object_key );

        // Read file content
        $file_content = file_get_contents( $file_path ); // @plugin-check: allowed - reading local backup file
        if ( $file_content === false ) {
            return [
                'status'  => 'error',
                'message' => __( 'Failed to read backup file.', 'museder-restoreone' ),
            ];
        }

        $file_size = strlen( $file_content );

        // Prepare headers for Signature V4
        $amz_date = gmdate( 'Ymd\THis\Z' );
        $date_stamp = gmdate( 'Ymd' );
        $content_type = 'application/zip';
        $content_sha256 = 'UNSIGNED-PAYLOAD';

        // Build canonical request
        $canonical_uri = '/' . $settings['s3_bucket'] . '/' . $object_key;
        $canonical_querystring = '';
        $canonical_headers = sprintf( "host:%s\nx-amz-content-sha256:%s\nx-amz-date:%s\n", $host, $content_sha256, $amz_date );
        $signed_headers = 'host;x-amz-content-sha256;x-amz-date';
        $payload_hash = $content_sha256;
        $canonical_request = sprintf(
            "PUT\n%s\n%s\n%s\n%s\n%s",
            $canonical_uri,
            $canonical_querystring,
            $canonical_headers,
            $signed_headers,
            $payload_hash
        );

        // Build string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = sprintf( '%s/%s/s3/aws4_request', $date_stamp, $settings['s3_region'] );
        $string_to_sign = sprintf(
            "%s\n%s\n%s\n%s",
            $algorithm,
            $amz_date,
            $credential_scope,
            hash( 'sha256', $canonical_request )
        );

        // Calculate signature
        $k_date = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $settings['s3_secret_key'], true );
        $k_region = hash_hmac( 'sha256', $settings['s3_region'], $k_date, true );
        $k_service = hash_hmac( 'sha256', 's3', $k_region, true );
        $k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
        $signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

        // Build authorization header
        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $algorithm,
            $settings['s3_access_key'],
            $credential_scope,
            $signed_headers,
            $signature
        );

        // Prepare request headers
        $headers = [
            'Host'               => $host,
            'Content-Type'       => $content_type,
            'Content-Length'     => $file_size,
            'x-amz-date'         => $amz_date,
            'x-amz-content-sha256' => $content_sha256,
            'Authorization'      => $authorization,
        ];

        // Make request using WordPress HTTP API
        $response = wp_remote_request( $request_url, [
            'method'  => 'PUT',
            'headers' => $headers,
            'body'    => $file_content,
            'timeout' => 300, // 5 minutes for large files
        ] );

        // Handle errors
        if ( is_wp_error( $response ) ) {
            return [
                'status'  => 'error',
                'message' => sprintf( __( 'WP_Error: %s', 'museder-restoreone' ), $response->get_error_message() ),
            ];
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code >= 400 ) {
            $response_body = wp_remote_retrieve_body( $response );
            $error_excerpt = mb_substr( $response_body, 0, 200 );
            return [
                'status'  => 'error',
                'message' => sprintf( __( 'HTTP %d: %s', 'museder-restoreone' ), $status_code, $error_excerpt ),
            ];
        }

        // Success
        $remote_path = sprintf( 's3://%s/%s', $settings['s3_bucket'], $object_key );
        return [
            'status'      => 'success',
            'message'     => __( 'Uploaded successfully', 'museder-restoreone' ),
            'remote_path' => $remote_path,
        ];
    }
}

