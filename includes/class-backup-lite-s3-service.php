<?php
/**
 * S3 Service
 * 
 * Handles S3 cloud storage operations for backup files.
 *
 * @package BackupLite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * S3 cloud storage: upload backup archive
 * Backup_Lite_S3_Service class.
 */
class Backup_Lite_S3_Service {

    /**
     * Upload a backup archive to S3.
     *
     * @param string $file_path Absolute path to backup archive.
     * @return array Result array with 'status' ('success'|'error'|'pending'|'none'), 'message', 'object_key', 'error'.
     */
    /**
     * Uploads a local backup file to S3.
     *
     * @param string $file_path Absolute path to local backup file.
     * @return array|WP_Error On success, array with 'status' => 'success' and 'object_key'. On failure, WP_Error.
     */
    public static function upload_backup( $file_path ) {
        $file_path = wp_normalize_path( $file_path );

        // Log that function was called
        backup_lite_log( 'info', 'S3 upload_backup() called.', [
            'file' => $file_path,
        ] );

        if ( ! file_exists( $file_path ) ) {
            $error_msg = __( 'Backup file not found.', 'museder-restoreone' );
            backup_lite_log( 'error', 'S3 upload failed: backup file not found.', [
                'file' => $file_path,
                'reason' => 'file_not_found',
            ] );
            return new WP_Error( 's3_missing_file', $error_msg );
        }
        
        // Check if file is readable
        if ( ! is_readable( $file_path ) ) {
            backup_lite_log( 'error', 'S3 upload failed: backup file is not readable.', [
                'file' => $file_path,
                'reason' => 'file_not_readable',
            ] );
            return new WP_Error( 's3_open_failed', __( 'Unable to open backup file for reading.', 'museder-restoreone' ) );
        }
        
        // Check file size
        $file_size = filesize( $file_path );
        if ( false === $file_size || $file_size <= 0 ) {
            backup_lite_log( 'error', 'S3 upload failed: backup file size is invalid.', [
                'file' => $file_path,
                'size' => $file_size,
                'reason' => 'file_size_invalid',
            ] );
            return new WP_Error( 's3_filesize_failed', __( 'Unable to determine backup file size.', 'museder-restoreone' ) );
        }
        
        // Read entire file content as string
        // Note: This loads the entire file into memory, but wp_remote_request() requires string body, not resource
        // For very large files (>500MB), consider implementing chunked upload in the future
        backup_lite_log( 'debug', 'S3 upload: reading file content into memory.', [
            'file' => $file_path,
            'size' => $file_size,
        ] );
        
        $body = @file_get_contents( $file_path ); // @plugin-check: allowed - reading local backup file
        if ( false === $body ) {
            $error = error_get_last();
            $error_msg = $error && isset( $error['message'] ) ? $error['message'] : __( 'Unknown error reading file.', 'museder-restoreone' );
            backup_lite_log( 'error', 'S3 upload failed: could not read file.', [
                'file' => $file_path,
                'error' => $error_msg,
            ] );
            return new WP_Error( 's3_read_file_error', __( 'Could not read backup file for S3 upload.', 'museder-restoreone' ) );
        }
        
        // Verify read size matches file size
        $read_size = strlen( $body );
        if ( $read_size !== $file_size ) {
            backup_lite_log( 'error', 'S3 upload failed: file read size mismatch.', [
                'file' => $file_path,
                'expected_size' => $file_size,
                'actual_size' => $read_size,
            ] );
            return new WP_Error( 's3_read_file_error', __( 'File read size mismatch. File may be corrupted or in use.', 'museder-restoreone' ) );
        }

        $settings = backup_lite_get_s3_settings();

        // DEBUG log S3 settings (without sensitive info)
        backup_lite_log(
            'debug',
            'S3 settings loaded in upload_backup().',
            array(
                'enabled' => isset( $settings['enabled'] ) ? (bool) $settings['enabled'] : null,
                'region'  => isset( $settings['region'] ) ? sanitize_text_field( $settings['region'] ) : '',
                'bucket'  => isset( $settings['bucket'] ) ? sanitize_text_field( $settings['bucket'] ) : '',
            )
        );

        // Check if S3 is enabled
        if ( empty( $settings['enabled'] ) ) {
            backup_lite_log( 'info', 'S3 upload skipped: S3 is disabled in settings.', array() );
            return new WP_Error( 's3_disabled', __( 'S3 is disabled in settings.', 'museder-restoreone' ) );
        }

        // Check required settings
        if ( empty( $settings['access_key_id'] ) ) {
            backup_lite_log( 'error', 'S3 upload skipped: missing access key ID.', array() );
            return new WP_Error( 'missing_setting_access_key_id', __( 'Missing S3 access key ID.', 'museder-restoreone' ) );
        }
        if ( empty( $settings['secret_access_key'] ) ) {
            backup_lite_log( 'error', 'S3 upload skipped: missing secret access key.', array() );
            return new WP_Error( 'missing_setting_secret_access_key', __( 'Missing S3 secret access key.', 'museder-restoreone' ) );
        }
        if ( empty( $settings['region'] ) ) {
            backup_lite_log( 'error', 'S3 upload skipped: missing region.', array() );
            return new WP_Error( 'missing_setting_region', __( 'Missing S3 region.', 'museder-restoreone' ) );
        }
        if ( empty( $settings['bucket'] ) ) {
            backup_lite_log( 'error', 'S3 upload skipped: missing bucket.', array() );
            return new WP_Error( 'missing_setting_bucket', __( 'Missing S3 bucket.', 'museder-restoreone' ) );
        }

        // Build object key
        $bucket      = $settings['bucket'];
        $region      = $settings['region'];
        $endpoint    = $settings['endpoint'];
        $use_path    = $settings['use_path_style_endpoint'];
        $prefix      = $settings['prefix'];
        $file_name   = basename( $file_path );
        
        // Build object key with better structure: {prefix}/{site-domain}/{Y-m-d}/{filename}
        try {
            $site_domain = wp_parse_url( home_url(), PHP_URL_HOST );
            if ( empty( $site_domain ) ) {
                $site_domain = 'unknown';
            }
            $site_domain = sanitize_file_name( $site_domain );
            if ( empty( $site_domain ) ) {
                $site_domain = 'unknown';
            }
            $date_path   = gmdate( 'Y-m-d' );
            $object_key  = $prefix 
                ? trailingslashit( $prefix ) . trailingslashit( $site_domain ) . trailingslashit( $date_path ) . $file_name
                : trailingslashit( $site_domain ) . trailingslashit( $date_path ) . $file_name;
        } catch ( Throwable $key_error ) {
            backup_lite_log( 'error', 'S3 upload failed: error building object key.', [
                'reason' => 'object_key_error',
                'message' => $key_error->getMessage(),
                'file_name' => $file_name,
            ] );
            return new WP_Error( 'object_key_error', __( 'Failed to build S3 object key.', 'museder-restoreone' ) );
        }

        // Log S3 upload start
        backup_lite_log( 'info', 'S3 upload starting.', [
            'bucket' => $bucket,
            'region' => $region,
            'key'    => $object_key,
        ] );

        // Build target URL based on endpoint / region
        try {
            $target_url = self::build_s3_object_url( $bucket, $region, $endpoint, $use_path, $object_key );
        } catch ( Throwable $url_error ) {
            backup_lite_log( 'error', 'S3 upload failed: error building target URL.', [
                'reason' => 'url_build_error',
                'message' => $url_error->getMessage(),
                'bucket' => $bucket,
                'region' => $region,
                'key'    => $object_key,
            ] );
            return new WP_Error( 'url_build_error', __( 'Failed to build S3 target URL.', 'museder-restoreone' ) );
        }

        try {
            // Build object key for backup
            $object_key = $object_key; // Already built above
            
            // Prepare arguments for put_object_via_sigv4
            $put_args = array(
                'bucket'         => $bucket,
                'region'         => $region,
                'key'            => $object_key,
                'body'           => $body, // string content (entire file read into memory)
                'content_type'   => 'application/zip',
                'content_length' => (int) $file_size,
                'url'            => $target_url,
                'access_key'     => $settings['access_key_id'],
                'secret_key'     => $settings['secret_access_key'],
            );
            
            // Log before calling put_object_via_sigv4 to track where we are
            backup_lite_log( 'debug', 'S3 upload: calling put_object_via_sigv4.', [
                'target_url' => $target_url,
                'bucket' => $bucket,
                'region' => $region,
                'key' => $object_key,
                'content_length' => $file_size,
            ] );
            
            $result = self::put_object_via_sigv4( $put_args );
            
            // Clear body from memory after upload attempt
            unset( $body );

            // Check if result is WP_Error
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            
            // Check if result is true (success)
            if ( true === $result ) {
                backup_lite_log( 'info', 'S3 upload completed.', [
                    'bucket' => $bucket,
                    'key'    => $object_key,
                    'region' => $region,
                ] );
                // Return array with success status and object_key for metadata storage
                return array(
                    'status'    => 'success',
                    'object_key' => $object_key,
                );
            }
            
            // Unexpected result type
            backup_lite_log( 'error', 'S3 upload failed: unexpected result type.', [
                'result_type' => gettype( $result ),
                'bucket' => $bucket,
                'key'    => $object_key,
                'region' => $region,
            ] );
            return new WP_Error( 's3_unexpected_result', __( 'S3 upload returned unexpected result.', 'museder-restoreone' ) );
        } catch ( Throwable $e ) {
            // Clear body from memory on exception
            if ( isset( $body ) ) {
                unset( $body );
            }
            
            // Sanitize exception message to avoid exposing sensitive info
            $error_msg = $e->getMessage();
            if ( stripos( $error_msg, 'secret' ) !== false 
                 || stripos( $error_msg, 'key' ) !== false 
                 || stripos( $error_msg, 'authorization' ) !== false 
                 || stripos( $error_msg, 'access' ) !== false
            ) {
                $error_msg = __( 'Authentication failed', 'museder-restoreone' );
            }
            
            // Log to plugin log
            backup_lite_log( 'error', 'S3 upload failed: unhandled exception.', [
                'reason' => 'exception',
                'bucket' => isset( $bucket ) ? $bucket : 'unknown',
                'key'    => isset( $object_key ) ? $object_key : 'unknown',
                'message' => $error_msg,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => substr( $e->getTraceAsString(), 0, 1000 ),
            ] );
            
            // Also log to PHP error log for easier debugging
            error_log( '[Backup Lite] S3 upload fatal error: ' . $error_msg . ' in ' . $e->getFile() . ':' . $e->getLine() );
            
            return new WP_Error( 's3_exception', __( 'S3 upload failed due to an internal error.', 'museder-restoreone' ) );
        }
    }

    /**
     * Build S3 object URL.
     *
     * This method is public to allow Backup_Lite_S3_Uploader to use it.
     *
     * @param string $bucket   Bucket name.
     * @param string $region   AWS region.
     * @param string $endpoint Custom endpoint (optional).
     * @param bool   $use_path Use path-style endpoint.
     * @param string $object_key Object key.
     * @return string
     */
    public static function build_s3_object_url( $bucket, $region, $endpoint, $use_path, $object_key ) {
        // If custom endpoint is provided, use it
        if ( $endpoint ) {
            $endpoint = rtrim( $endpoint, '/' );
            if ( $use_path ) {
                return $endpoint . '/' . rawurlencode( $bucket ) . '/' . str_replace( '%2F', '/', rawurlencode( $object_key ) );
            }
            return $endpoint . '/' . str_replace( '%2F', '/', rawurlencode( $object_key ) );
        }

        // Default Amazon S3 endpoint
        $host = $bucket . '.s3.' . $region . '.amazonaws.com';
        if ( $use_path ) {
            $host = 's3.' . $region . '.amazonaws.com';
            return 'https://' . $host . '/' . rawurlencode( $bucket ) . '/' . str_replace( '%2F', '/', rawurlencode( $object_key ) );
        }

        return 'https://' . $host . '/' . str_replace( '%2F', '/', rawurlencode( $object_key ) );
    }

    /**
     * Puts an object to S3 using Signature Version 4.
     *
     * @param array $args {
     *     @type string            $bucket         Bucket name.
     *     @type string            $region         AWS region.
     *     @type string            $key            Object key.
     *     @type string            $body           Request body (must be string, wp_remote_request() does not support resource).
     *     @type string            $content_type   Content type (e.g., 'application/zip').
     *     @type int               $content_length Content length in bytes.
     *     @type string            $url            Target S3 URL.
     *     @type string            $access_key     Access key ID.
     *     @type string            $secret_key     Secret access key.
     * }
     * @return true|WP_Error On success returns true, on failure returns WP_Error.
     */
    /**
     * Puts an object to S3 using Signature Version 4.
     * 
     * This method is public to allow Backup_Lite_S3_Uploader to use it.
     * 
     * @param array $args {
     *     @type string            $bucket         Bucket name.
     *     @type string            $region         AWS region.
     *     @type string            $key            Object key.
     *     @type string            $body           File content as string (NOT resource).
     *     @type string            $content_type   Content type.
     *     @type int               $content_length Content length in bytes.
     *     @type string            $url            Target S3 URL.
     *     @type string            $access_key     Access key ID.
     *     @type string            $secret_key     Secret access key.
     * }
     * @return true|WP_Error On success returns true, on failure returns WP_Error.
     */
    public static function put_object_via_sigv4( array $args ) {
        // Validate required parameters
        $required_keys = array( 'bucket', 'region', 'key', 'body', 'content_type', 'content_length', 'url', 'access_key', 'secret_key' );
        foreach ( $required_keys as $required_key ) {
            if ( ! isset( $args[ $required_key ] ) ) {
                return new WP_Error(
                    's3_missing_arg',
                    sprintf(
                        /* translators: %s: missing argument name */
                        __( 'Missing required S3 argument: %s.', 'museder-restoreone' ),
                        esc_html( $required_key )
                    )
                );
            }
        }
        
        $bucket         = $args['bucket'];
        $region         = $args['region'];
        $key            = $args['key'];
        $body           = $args['body'];
        $content_type   = $args['content_type'];
        $content_length = (int) $args['content_length'];
        $url            = $args['url'];
        $access_key     = $args['access_key'];
        $secret_key     = $args['secret_key'];
        
        // Validate content_length
        if ( $content_length <= 0 ) {
            return new WP_Error( 's3_invalid_content_length', __( 'Content length must be greater than 0.', 'museder-restoreone' ) );
        }
        
        // Validate body is string (wp_remote_request() requires string, not resource)
        if ( ! is_string( $body ) ) {
            backup_lite_log( 'error', 'S3 upload failed: body must be a string.', [
                'body_type' => gettype( $body ),
            ] );
            return new WP_Error( 's3_invalid_body_type', __( 'Body must be a string. Resource handles are not supported by wp_remote_request().', 'museder-restoreone' ) );
        }
        
        // Calculate payload hash for signature
        // For large files (>100MB), use UNSIGNED-PAYLOAD to avoid hash calculation overhead
        // For smaller files, calculate SHA256 hash for better security
        $unsigned_payload = false;
        if ( $content_length > 100 * 1024 * 1024 ) { // 100MB threshold
            // For very large files, use UNSIGNED-PAYLOAD to avoid hash calculation overhead
            $payload_hash = 'UNSIGNED-PAYLOAD';
            $unsigned_payload = true;
            backup_lite_log( 'debug', 'S3 upload: using UNSIGNED-PAYLOAD for large file.', [
                'content_length' => $content_length,
            ] );
        } else {
            // For smaller files, calculate SHA256 hash
            $payload_hash = hash( 'sha256', $body );
            if ( false === $payload_hash ) {
                return new WP_Error( 's3_hash_error', __( 'Failed to calculate payload hash.', 'museder-restoreone' ) );
            }
        }
        
        // Parse URL to get host
        $url_parts = parse_url( $url );
        if ( ! $url_parts || ! isset( $url_parts['host'] ) ) {
            backup_lite_log( 'error', 'S3 upload failed: invalid URL format.', [
                'url' => $url,
            ] );
            return new WP_Error( 's3_invalid_url', __( 'Invalid S3 URL.', 'museder-restoreone' ) );
        }

        $host = isset( $url_parts['host'] ) ? $url_parts['host'] : '';
        $path = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';
        $query = isset( $url_parts['query'] ) ? $url_parts['query'] : '';
        
        // Validate host is not empty
        if ( empty( $host ) ) {
            backup_lite_log( 'error', 'S3 upload failed: empty host in URL.', [
                'url' => $url,
            ] );
            return new WP_Error( 's3_invalid_url', __( 'Invalid S3 URL: missing host.', 'museder-restoreone' ) );
        }
        
        try {

            // AWS Signature Version 4
            // Validate required credentials
            if ( empty( $access_key ) || empty( $secret_key ) || empty( $region ) ) {
                backup_lite_log( 'error', 'S3 upload failed: missing required credentials for signature.', [
                    'has_access_key' => ! empty( $access_key ),
                    'has_secret_key' => ! empty( $secret_key ),
                    'has_region' => ! empty( $region ),
                ] );
                return new WP_Error( 's3_missing_credentials', __( 'Missing required S3 credentials for signature calculation.', 'museder-restoreone' ) );
            }
            
            $amz_date = gmdate( 'Ymd\THis\Z' );
            $date_stamp = gmdate( 'Ymd' );
            $content_sha256 = $payload_hash; // Use calculated hash or 'UNSIGNED-PAYLOAD'

        // Build canonical request
        $canonical_uri = $path;
        $canonical_querystring = $query;
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
        $credential_scope = sprintf( '%s/%s/s3/aws4_request', $date_stamp, $region );
        $canonical_request_hash = hash( 'sha256', $canonical_request );
        if ( false === $canonical_request_hash ) {
            backup_lite_log( 'error', 'S3 upload failed: hash calculation failed.', [
                'url' => $url,
            ] );
            return new WP_Error( 's3_hash_error', __( 'Failed to calculate request hash.', 'museder-restoreone' ) );
        }
        
        $string_to_sign = sprintf(
            "%s\n%s\n%s\n%s",
            $algorithm,
            $amz_date,
            $credential_scope,
            $canonical_request_hash
        );

        // Calculate signature - wrap each hash_hmac call in error checking
        $k_date = @hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
        if ( false === $k_date ) {
            backup_lite_log( 'error', 'S3 upload failed: k_date hash calculation failed.' );
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_date).', 'museder-restoreone' ) );
        }
        
        $k_region = @hash_hmac( 'sha256', $region, $k_date, true );
        if ( false === $k_region ) {
            backup_lite_log( 'error', 'S3 upload failed: k_region hash calculation failed.' );
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_region).', 'museder-restoreone' ) );
        }
        
        $k_service = @hash_hmac( 'sha256', 's3', $k_region, true );
        if ( false === $k_service ) {
            backup_lite_log( 'error', 'S3 upload failed: k_service hash calculation failed.' );
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_service).', 'museder-restoreone' ) );
        }
        
        $k_signing = @hash_hmac( 'sha256', 'aws4_request', $k_service, true );
        if ( false === $k_signing ) {
            backup_lite_log( 'error', 'S3 upload failed: k_signing hash calculation failed.' );
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_signing).', 'museder-restoreone' ) );
        }
        
        $signature = @hash_hmac( 'sha256', $string_to_sign, $k_signing );
        if ( false === $signature ) {
            backup_lite_log( 'error', 'S3 upload failed: signature hash calculation failed.' );
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate final signature.', 'museder-restoreone' ) );
        }

        // Build authorization header
        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $algorithm,
            $access_key,
            $credential_scope,
            $signed_headers,
            $signature
        );

            // Prepare request headers
            $headers = array(
                'Host'               => $host,
                'Content-Type'       => $content_type,
                'Content-Length'     => (string) $content_length,
                'x-amz-date'         => $amz_date,
                'x-amz-content-sha256' => $content_sha256,
                'Authorization'      => $authorization,
            );

            // Log before making request
            backup_lite_log( 'debug', 'S3 upload: sending PUT request.', [
                'bucket'         => $bucket,
                'region'         => $region,
                'key'            => $key,
                'content_length' => $content_length,
                'unsigned'       => $unsigned_payload,
            ] );
            
            $request_args = array(
                'method'      => 'PUT',
                'timeout'     => 600, // 10 minutes for large files
                'headers'     => $headers,
                'body'        => $body, // Must be string (wp_remote_request() does not support resource)
                'data_format' => 'body',
            );
            
            // Use try-catch around wp_remote_request to catch any exceptions
            try {
                $response = @wp_remote_request( $url, $request_args );
            } catch ( Throwable $request_error ) {
                backup_lite_log( 'error', 'S3 upload failed: exception during wp_remote_request.', [
                    'url' => $url,
                    'message' => $request_error->getMessage(),
                    'file' => $request_error->getFile(),
                    'line' => $request_error->getLine(),
                ] );
                error_log( '[Backup Lite] S3 wp_remote_request exception: ' . $request_error->getMessage() . ' in ' . $request_error->getFile() . ':' . $request_error->getLine() );
                return new WP_Error( 's3_request_exception', __( 'S3 upload request failed with exception.', 'museder-restoreone' ) );
            }
            
            // Check if wp_remote_request returned false or invalid result
            if ( false === $response ) {
                $error = error_get_last();
                $error_msg = $error && isset( $error['message'] ) ? $error['message'] : __( 'Unknown error.', 'museder-restoreone' );
                backup_lite_log( 'error', 'S3 upload failed: wp_remote_request returned false.', [
                    'url' => $url,
                    'error' => $error_msg,
                ] );
                return new WP_Error( 's3_request_failed', __( 'S3 upload request failed: wp_remote_request returned false.', 'museder-restoreone' ) );
            }
            
            // Check if response is WP_Error
            if ( is_wp_error( $response ) ) {
                backup_lite_log( 'error', 'S3 upload failed (wp_remote_request error).', [
                    'error' => $response->get_error_message(),
                ] );
                return new WP_Error( 's3_http_error', $response->get_error_message() );
            }
            
            // Log after request completes
            backup_lite_log( 'debug', 'S3 upload: wp_remote_request completed.', [
                'response_type' => gettype( $response ),
            ] );
            
            $status_code = wp_remote_retrieve_response_code( $response );
            
            if ( $status_code < 200 || $status_code >= 300 ) {
                $body_text = wp_remote_retrieve_body( $response );
                
                backup_lite_log( 'error', 'S3 upload failed (non-2xx response).', [
                    'status_code'  => $status_code,
                    'body_snippet' => substr( (string) $body_text, 0, 200 ),
                ] );
                
                return new WP_Error(
                    's3_non_2xx',
                    sprintf(
                        /* translators: %d: HTTP status code */
                        __( 'S3 returned unexpected status code %d.', 'museder-restoreone' ),
                        (int) $status_code
                    )
                );
            }
            
            backup_lite_log( 'info', 'S3 upload completed.', [
                'bucket' => $bucket,
                'key'    => $key,
            ] );
            
            return true;
        } catch ( Throwable $e ) {
            // Catch any unhandled exceptions during S3 upload preparation
            $error_message = $e->getMessage();
            $error_file = $e->getFile();
            $error_line = $e->getLine();
            
            // Sanitize error message to avoid exposing sensitive info
            if ( stripos( $error_message, 'secret' ) !== false 
                 || stripos( $error_message, 'key' ) !== false 
                 || stripos( $error_message, 'authorization' ) !== false 
                 || stripos( $error_message, 'access' ) !== false
            ) {
                $error_message = __( 'Authentication error', 'museder-restoreone' );
            }
            
            backup_lite_log( 'error', 'S3 upload preparation failed: unhandled exception.', [
                'message' => $error_message,
                'file' => $error_file,
                'line' => $error_line,
                'class' => get_class( $e ),
                'trace' => substr( $e->getTraceAsString(), 0, 1000 ), // Limit trace to first 1000 chars
            ] );
            
            // Also log to PHP error log for easier debugging
            error_log( '[Backup Lite] S3 upload preparation fatal error: ' . $error_message . ' in ' . $error_file . ':' . $error_line );
            
            return new WP_Error( 's3_exception', __( 'S3 upload failed due to an internal error.', 'museder-restoreone' ) );
        }
    }

    /**
     * Delete an S3 object using Signature V4.
     *
     * @param string $url         Full S3 object URL.
     * @param string $bucket      Bucket name.
     * @param string $region      AWS region.
     * @param string $access_key  AWS access key.
     * @param string $secret_key  AWS secret key.
     * @return WP_Error|array WP_Error on failure, response array on success.
     */
    protected static function delete_object_via_sigv4( $url, $bucket, $region, $access_key, $secret_key ) {
        if ( ! function_exists( 'wp_parse_url' ) ) {
            return new WP_Error( 'wp_parse_url_missing', 'wp_parse_url() function is not available.' );
        }

        $host = wp_parse_url( $url, PHP_URL_HOST );
        $amz_date = gmdate( 'Ymd\THis\Z' );
        $date_stamp = gmdate( 'Ymd' );
        $content_sha256 = 'UNSIGNED-PAYLOAD';

        // Canonical Request
        $canonical_uri = wp_parse_url( $url, PHP_URL_PATH );
        $canonical_querystring = wp_parse_url( $url, PHP_URL_QUERY ) ?: '';
        $canonical_headers = "host:{$host}\nx-amz-content-sha256:{$content_sha256}\nx-amz-date:{$amz_date}\n";
        $signed_headers = 'host;x-amz-content-sha256;x-amz-date';
        $canonical_request = "DELETE\n{$canonical_uri}\n{$canonical_querystring}\n{$canonical_headers}\n{$signed_headers}\n{$content_sha256}";

        // String to Sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = "{$date_stamp}/{$region}/s3/aws4_request";
        $string_to_sign = "{$algorithm}\n{$amz_date}\n{$credential_scope}\n" . hash( 'sha256', $canonical_request );

        // Signing Key
        $k_date = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
        $k_region = hash_hmac( 'sha256', $region, $k_date, true );
        $k_service = hash_hmac( 'sha256', 's3', $k_region, true );
        $k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );

        // Signature
        $signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

        // Authorization Header
        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $algorithm,
            $access_key,
            $credential_scope,
            $signed_headers,
            $signature
        );

        // Prepare request headers
        $headers = array(
            'Host'               => $host,
            'x-amz-date'         => $amz_date,
            'x-amz-content-sha256' => $content_sha256,
            'Authorization'      => $authorization,
        );

        // Send DELETE request
        $args = array(
            'method'  => 'DELETE',
            'headers' => $headers,
            'timeout' => 30,
        );

        $response = wp_remote_request( $url, $args );
        
        // Check if wp_remote_request returned false or invalid result
        if ( false === $response ) {
            backup_lite_log( 'error', 'S3 upload failed: wp_remote_request returned false.', [
                'url' => $url,
            ] );
            return new WP_Error( 's3_request_failed', __( 'S3 upload request failed: wp_remote_request returned false.', 'museder-restoreone' ) );
        }
        
        return $response;
    }

    /**
     * Test S3 connection by uploading a small test object.
     *
     * @param array $settings S3 settings.
     * @return array {
     *     @type bool   $success Whether test succeeded.
     *     @type string $message Result message.
     * }
     */
    public static function test_connection( $settings = null ) {
        if ( null === $settings ) {
            $settings = backup_lite_get_s3_settings();
        }

        if ( empty( $settings['access_key_id'] )
             || empty( $settings['secret_access_key'] )
             || empty( $settings['region'] )
             || empty( $settings['bucket'] )
        ) {
            return array(
                'success' => false,
                'message' => __( 'S3 settings are incomplete.', 'museder-restoreone' ),
            );
        }

        // Create a small test object
        $test_content = 'Backup Lite S3 connectivity test.';
        $test_key = ( $settings['prefix'] ? trailingslashit( $settings['prefix'] ) : '' ) . '.backup-lite-test-' . time() . '.txt';

        $bucket      = $settings['bucket'];
        $region      = $settings['region'];
        $endpoint    = $settings['endpoint'];
        $use_path    = $settings['use_path_style_endpoint'];

        $target_url = self::build_s3_object_url( $bucket, $region, $endpoint, $use_path, $test_key );

        // Prepare arguments for put_object_via_sigv4
        $put_args = array(
            'bucket'         => $bucket,
            'region'         => $region,
            'key'            => $test_key,
            'body'           => $test_content, // String for small test content
            'content_type'   => 'text/plain',
            'content_length' => strlen( $test_content ),
            'url'            => $target_url,
            'access_key'     => $settings['access_key_id'],
            'secret_key'     => $settings['secret_access_key'],
        );

        $result = self::put_object_via_sigv4( $put_args );

        if ( is_wp_error( $result ) ) {
            $error_code = $result->get_error_code();
            $error_msg = $result->get_error_message();
            
            // Sanitize error message to avoid exposing sensitive info for user display
            $user_error_msg = $error_msg;
            if ( stripos( $user_error_msg, 'secret' ) !== false || stripos( $user_error_msg, 'key' ) !== false || stripos( $user_error_msg, 'authorization' ) !== false ) {
                $user_error_msg = __( 'Authentication failed', 'museder-restoreone' );
            }
            
            // Log detailed error info (sanitize message to avoid exposing sensitive info in log)
            $log_message = $error_msg;
            // Remove potential sensitive info from log message
            $log_message = preg_replace( '/secret[=\s:]+[^\s]+/i', 'secret=***', $log_message );
            $log_message = preg_replace( '/key[=\s:]+[^\s]+/i', 'key=***', $log_message );
            $log_message = preg_replace( '/authorization[=\s:]+[^\s]+/i', 'authorization=***', $log_message );
            
            if ( function_exists( 'backup_lite_log' ) ) {
                backup_lite_log(
                    'error',
                    sprintf(
                        'S3: test connection failed - authentication or connection error. code=%s message=%s',
                        $error_code,
                        $log_message
                    )
                );
            }
            
            return array(
                'success' => false,
                'message' => sprintf( __( 'Could not upload test file to S3: %s', 'museder-restoreone' ), $user_error_msg ),
            );
        }

        // Result should be true on success
        if ( true === $result ) {
            // Test successful - delete the test object immediately
            $delete_result = self::delete_object_via_sigv4(
                $target_url,
                $bucket,
                $region,
                $settings['access_key_id'],
                $settings['secret_access_key']
            );
            
            // Log deletion result (but don't fail the test if deletion fails)
            if ( is_wp_error( $delete_result ) ) {
                // Don't log error message (may contain sensitive info)
                backup_lite_log( 'warning', 'S3: test file uploaded but deletion failed' );
            } else {
                // delete_object_via_sigv4 returns response array, check status code
                $delete_code = wp_remote_retrieve_response_code( $delete_result );
                if ( $delete_code < 200 || $delete_code >= 300 ) {
                    backup_lite_log( 'warning', 'S3: test file uploaded but deletion failed with HTTP ' . $delete_code );
                }
            }
            
            return array(
                'success' => true,
                'message' => __( 'Successfully connected to S3 bucket.', 'museder-restoreone' ),
            );
        }
        
        // Unexpected result type (should not happen)
        backup_lite_log( 'error', 'S3: test connection returned unexpected result type: ' . gettype( $result ) );
        return array(
            'success' => false,
            'message' => __( 'Unexpected error during S3 connection test.', 'museder-restoreone' ),
        );
    }

    /**
     * Create a multipart upload on S3.
     *
     * @param string $bucket      Bucket name.
     * @param string $region      AWS region.
     * @param string $key         Object key.
     * @param string $endpoint    Custom endpoint (optional).
     * @param bool   $use_path    Use path-style endpoint.
     * @param string $access_key  Access key ID.
     * @param string $secret_key  Secret access key.
     * @param string $content_type Content type (e.g., 'application/zip').
     * @return string|WP_Error UploadId on success, WP_Error on failure.
     */
    public static function create_multipart_upload( $bucket, $region, $key, $endpoint, $use_path, $access_key, $secret_key, $content_type = 'application/zip' ) {
        // Build URL with ?uploads query parameter
        $base_url = self::build_s3_object_url( $bucket, $region, $endpoint, $use_path, $key );
        $url = add_query_arg( 'uploads', '', $base_url );

        // Parse URL to get host and path
        $url_parts = parse_url( $url );
        if ( ! $url_parts || ! isset( $url_parts['host'] ) ) {
            return new WP_Error( 's3_invalid_url', __( 'Invalid S3 URL for multipart upload.', 'museder-restoreone' ) );
        }

        $host = $url_parts['host'];
        $path = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';
        $query = isset( $url_parts['query'] ) ? $url_parts['query'] : '';

        // AWS Signature Version 4 for POST request
        $amz_date = gmdate( 'Ymd\THis\Z' );
        $date_stamp = gmdate( 'Ymd' );
        $content_sha256 = 'UNSIGNED-PAYLOAD';

        // Build canonical request
        $canonical_uri = $path;
        $canonical_querystring = $query;
        $canonical_headers = sprintf( "host:%s\nx-amz-content-sha256:%s\nx-amz-date:%s\n", $host, $content_sha256, $amz_date );
        $signed_headers = 'host;x-amz-content-sha256;x-amz-date';
        $payload_hash = $content_sha256;

        $canonical_request = sprintf(
            "POST\n%s\n%s\n%s\n%s\n%s",
            $canonical_uri,
            $canonical_querystring,
            $canonical_headers,
            $signed_headers,
            $payload_hash
        );

        // Build string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = sprintf( '%s/%s/s3/aws4_request', $date_stamp, $region );
        $canonical_request_hash = hash( 'sha256', $canonical_request );
        if ( false === $canonical_request_hash ) {
            return new WP_Error( 's3_hash_error', __( 'Failed to calculate request hash.', 'museder-restoreone' ) );
        }

        $string_to_sign = sprintf(
            "%s\n%s\n%s\n%s",
            $algorithm,
            $amz_date,
            $credential_scope,
            $canonical_request_hash
        );

        // Calculate signature
        $k_date = @hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
        if ( false === $k_date ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_date).', 'museder-restoreone' ) );
        }

        $k_region = @hash_hmac( 'sha256', $region, $k_date, true );
        if ( false === $k_region ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_region).', 'museder-restoreone' ) );
        }

        $k_service = @hash_hmac( 'sha256', 's3', $k_region, true );
        if ( false === $k_service ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_service).', 'museder-restoreone' ) );
        }

        $k_signing = @hash_hmac( 'sha256', 'aws4_request', $k_service, true );
        if ( false === $k_signing ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_signing).', 'museder-restoreone' ) );
        }

        $signature = @hash_hmac( 'sha256', $string_to_sign, $k_signing );
        if ( false === $signature ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate final signature.', 'museder-restoreone' ) );
        }

        // Build authorization header
        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $algorithm,
            $access_key,
            $credential_scope,
            $signed_headers,
            $signature
        );

        // Prepare request headers
        $headers = array(
            'Host'               => $host,
            'Content-Type'       => $content_type,
            'x-amz-date'         => $amz_date,
            'x-amz-content-sha256' => $content_sha256,
            'Authorization'      => $authorization,
        );

        // Send POST request
        $request_args = array(
            'method'      => 'POST',
            'timeout'     => 60,
            'headers'     => $headers,
            'body'        => '', // Empty body for CreateMultipartUpload
            'data_format' => 'body',
        );

        try {
            $response = @wp_remote_request( $url, $request_args );
        } catch ( Throwable $request_error ) {
            backup_lite_log( 'error', 'S3 create_multipart_upload failed: exception during wp_remote_request.', [
                'url' => $url,
                'message' => $request_error->getMessage(),
            ] );
            return new WP_Error( 's3_request_exception', __( 'S3 create multipart upload request failed with exception.', 'museder-restoreone' ) );
        }

        if ( false === $response || is_wp_error( $response ) ) {
            $error_msg = is_wp_error( $response ) ? $response->get_error_message() : __( 'Unknown error.', 'museder-restoreone' );
            backup_lite_log( 'error', 'S3 create_multipart_upload failed.', [
                'error' => $error_msg,
            ] );
            return new WP_Error( 's3_request_failed', sprintf( __( 'S3 create multipart upload failed: %s', 'museder-restoreone' ), $error_msg ) );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code < 200 || $status_code >= 300 ) {
            $body_text = wp_remote_retrieve_body( $response );
            backup_lite_log( 'error', 'S3 create_multipart_upload failed (non-2xx response).', [
                'status_code'  => $status_code,
                'body_snippet' => substr( (string) $body_text, 0, 200 ),
            ] );
            return new WP_Error(
                's3_non_2xx',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'S3 create multipart upload returned status code %d.', 'museder-restoreone' ),
                    (int) $status_code
                )
            );
        }

        // Parse XML response to extract UploadId
        $body_text = wp_remote_retrieve_body( $response );
        $xml = @simplexml_load_string( $body_text );
        if ( false === $xml || ! isset( $xml->UploadId ) ) {
            backup_lite_log( 'error', 'S3 create_multipart_upload failed: could not parse UploadId from response.', [
                'body_snippet' => substr( (string) $body_text, 0, 200 ),
            ] );
            return new WP_Error( 's3_parse_error', __( 'Failed to parse UploadId from S3 response.', 'museder-restoreone' ) );
        }

        $upload_id = (string) $xml->UploadId;
        backup_lite_log( 'info', 'S3 multipart upload created.', [
            'bucket' => $bucket,
            'key'    => $key,
            'upload_id' => $upload_id,
        ] );

        return $upload_id;
    }

    /**
     * Upload a part in a multipart upload.
     *
     * @param string $bucket      Bucket name.
     * @param string $region      AWS region.
     * @param string $key         Object key.
     * @param string $endpoint    Custom endpoint (optional).
     * @param bool   $use_path    Use path-style endpoint.
     * @param string $upload_id   Upload ID from create_multipart_upload.
     * @param int    $part_number Part number (1-based).
     * @param string $part_data   Part data as string (NOT resource).
     * @param string $access_key  Access key ID.
     * @param string $secret_key  Secret access key.
     * @return string|WP_Error ETag on success, WP_Error on failure.
     */
    public static function upload_part( $bucket, $region, $key, $endpoint, $use_path, $upload_id, $part_number, $part_data, $access_key, $secret_key ) {
        // Validate part_data is string
        if ( ! is_string( $part_data ) ) {
            return new WP_Error( 's3_invalid_part_data', __( 'Part data must be a string.', 'museder-restoreone' ) );
        }

        $part_size = strlen( $part_data );
        if ( $part_size <= 0 ) {
            return new WP_Error( 's3_invalid_part_size', __( 'Part data size must be greater than 0.', 'museder-restoreone' ) );
        }

        // Build URL with partNumber and uploadId query parameters
        $base_url = self::build_s3_object_url( $bucket, $region, $endpoint, $use_path, $key );
        $url = add_query_arg(
            array(
                'partNumber' => $part_number,
                'uploadId'   => $upload_id,
            ),
            $base_url
        );

        // Parse URL to get host and path
        $url_parts = parse_url( $url );
        if ( ! $url_parts || ! isset( $url_parts['host'] ) ) {
            return new WP_Error( 's3_invalid_url', __( 'Invalid S3 URL for part upload.', 'museder-restoreone' ) );
        }

        $host = $url_parts['host'];
        $path = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';
        $query = isset( $url_parts['query'] ) ? $url_parts['query'] : '';

        // AWS Signature Version 4 for PUT request
        $amz_date = gmdate( 'Ymd\THis\Z' );
        $date_stamp = gmdate( 'Ymd' );
        
        // For multipart upload parts, use UNSIGNED-PAYLOAD to avoid hash calculation overhead
        $content_sha256 = 'UNSIGNED-PAYLOAD';

        // Build canonical request
        $canonical_uri = $path;
        $canonical_querystring = $query;
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
        $credential_scope = sprintf( '%s/%s/s3/aws4_request', $date_stamp, $region );
        $canonical_request_hash = hash( 'sha256', $canonical_request );
        if ( false === $canonical_request_hash ) {
            return new WP_Error( 's3_hash_error', __( 'Failed to calculate request hash.', 'museder-restoreone' ) );
        }

        $string_to_sign = sprintf(
            "%s\n%s\n%s\n%s",
            $algorithm,
            $amz_date,
            $credential_scope,
            $canonical_request_hash
        );

        // Calculate signature
        $k_date = @hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
        if ( false === $k_date ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_date).', 'museder-restoreone' ) );
        }

        $k_region = @hash_hmac( 'sha256', $region, $k_date, true );
        if ( false === $k_region ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_region).', 'museder-restoreone' ) );
        }

        $k_service = @hash_hmac( 'sha256', 's3', $k_region, true );
        if ( false === $k_service ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_service).', 'museder-restoreone' ) );
        }

        $k_signing = @hash_hmac( 'sha256', 'aws4_request', $k_service, true );
        if ( false === $k_signing ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_signing).', 'museder-restoreone' ) );
        }

        $signature = @hash_hmac( 'sha256', $string_to_sign, $k_signing );
        if ( false === $signature ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate final signature.', 'museder-restoreone' ) );
        }

        // Build authorization header
        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $algorithm,
            $access_key,
            $credential_scope,
            $signed_headers,
            $signature
        );

        // Prepare request headers
        $headers = array(
            'Host'               => $host,
            'Content-Length'     => (string) $part_size,
            'Content-Type'       => 'application/zip',
            'x-amz-date'         => $amz_date,
            'x-amz-content-sha256' => $content_sha256,
            'Authorization'      => $authorization,
        );

        // Send PUT request
        $request_args = array(
            'method'      => 'PUT',
            'timeout'     => 300, // 5 minutes per part
            'headers'     => $headers,
            'body'        => $part_data, // String body (NOT resource)
            'data_format' => 'body',
        );

        try {
            $response = @wp_remote_request( $url, $request_args );
        } catch ( Throwable $request_error ) {
            backup_lite_log( 'error', 'S3 upload_part failed: exception during wp_remote_request.', [
                'url' => $url,
                'part_number' => $part_number,
                'message' => $request_error->getMessage(),
            ] );
            return new WP_Error( 's3_request_exception', __( 'S3 part upload request failed with exception.', 'museder-restoreone' ) );
        }

        if ( false === $response || is_wp_error( $response ) ) {
            $error_msg = is_wp_error( $response ) ? $response->get_error_message() : __( 'Unknown error.', 'museder-restoreone' );
            backup_lite_log( 'error', 'S3 upload_part failed.', [
                'part_number' => $part_number,
                'error' => $error_msg,
            ] );
            return new WP_Error( 's3_request_failed', sprintf( __( 'S3 part upload failed: %s', 'museder-restoreone' ), $error_msg ) );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            $body_text = wp_remote_retrieve_body( $response );
            backup_lite_log( 'error', 'S3 upload_part failed (non-200 response).', [
                'status_code'  => $status_code,
                'part_number' => $part_number,
                'body_snippet' => substr( (string) $body_text, 0, 200 ),
            ] );
            return new WP_Error(
                's3_non_200',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'S3 part upload returned status code %d.', 'museder-restoreone' ),
                    (int) $status_code
                )
            );
        }

        // Extract ETag from response headers
        $etag = wp_remote_retrieve_header( $response, 'etag' );
        if ( empty( $etag ) ) {
            backup_lite_log( 'error', 'S3 upload_part failed: missing ETag in response.', [
                'part_number' => $part_number,
            ] );
            return new WP_Error( 's3_missing_etag', __( 'S3 part upload response missing ETag.', 'museder-restoreone' ) );
        }

        // Remove quotes from ETag if present
        $etag = trim( $etag, '"' );

        backup_lite_log( 'debug', 'S3 part uploaded successfully.', [
            'part_number' => $part_number,
            'part_size'   => $part_size,
            'etag'        => $etag,
        ] );

        return $etag;
    }

    /**
     * Complete a multipart upload on S3.
     *
     * @param string $bucket      Bucket name.
     * @param string $region      AWS region.
     * @param string $key         Object key.
     * @param string $endpoint    Custom endpoint (optional).
     * @param bool   $use_path    Use path-style endpoint.
     * @param string $upload_id   Upload ID from create_multipart_upload.
     * @param array  $parts       Array of parts, each with 'PartNumber' and 'ETag'.
     * @param string $access_key  Access key ID.
     * @param string $secret_key  Secret access key.
     * @return true|WP_Error True on success, WP_Error on failure.
     */
    public static function complete_multipart_upload( $bucket, $region, $key, $endpoint, $use_path, $upload_id, $parts, $access_key, $secret_key ) {
        // Build XML body for CompleteMultipartUpload
        $xml_parts = '';
        foreach ( $parts as $part ) {
            if ( ! isset( $part['PartNumber'] ) || ! isset( $part['ETag'] ) ) {
                return new WP_Error( 's3_invalid_part', __( 'Invalid part data: missing PartNumber or ETag.', 'museder-restoreone' ) );
            }
            $xml_parts .= sprintf(
                '<Part><PartNumber>%d</PartNumber><ETag>%s</ETag></Part>',
                (int) $part['PartNumber'],
                esc_xml( $part['ETag'] )
            );
        }

        $xml_body = '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUpload>' . $xml_parts . '</CompleteMultipartUpload>';

        // Build URL with uploadId query parameter
        $base_url = self::build_s3_object_url( $bucket, $region, $endpoint, $use_path, $key );
        $url = add_query_arg( 'uploadId', $upload_id, $base_url );

        // Parse URL to get host and path
        $url_parts = parse_url( $url );
        if ( ! $url_parts || ! isset( $url_parts['host'] ) ) {
            return new WP_Error( 's3_invalid_url', __( 'Invalid S3 URL for complete multipart upload.', 'museder-restoreone' ) );
        }

        $host = $url_parts['host'];
        $path = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';
        $query = isset( $url_parts['query'] ) ? $url_parts['query'] : '';

        // AWS Signature Version 4 for POST request
        $amz_date = gmdate( 'Ymd\THis\Z' );
        $date_stamp = gmdate( 'Ymd' );
        
        // Calculate SHA256 hash of XML body
        $content_sha256 = hash( 'sha256', $xml_body );
        if ( false === $content_sha256 ) {
            return new WP_Error( 's3_hash_error', __( 'Failed to calculate body hash.', 'museder-restoreone' ) );
        }

        // Build canonical request
        $canonical_uri = $path;
        $canonical_querystring = $query;
        $canonical_headers = sprintf( "host:%s\nx-amz-content-sha256:%s\nx-amz-date:%s\n", $host, $content_sha256, $amz_date );
        $signed_headers = 'host;x-amz-content-sha256;x-amz-date';
        $payload_hash = $content_sha256;

        $canonical_request = sprintf(
            "POST\n%s\n%s\n%s\n%s\n%s",
            $canonical_uri,
            $canonical_querystring,
            $canonical_headers,
            $signed_headers,
            $payload_hash
        );

        // Build string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = sprintf( '%s/%s/s3/aws4_request', $date_stamp, $region );
        $canonical_request_hash = hash( 'sha256', $canonical_request );
        if ( false === $canonical_request_hash ) {
            return new WP_Error( 's3_hash_error', __( 'Failed to calculate request hash.', 'museder-restoreone' ) );
        }

        $string_to_sign = sprintf(
            "%s\n%s\n%s\n%s",
            $algorithm,
            $amz_date,
            $credential_scope,
            $canonical_request_hash
        );

        // Calculate signature
        $k_date = @hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
        if ( false === $k_date ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_date).', 'museder-restoreone' ) );
        }

        $k_region = @hash_hmac( 'sha256', $region, $k_date, true );
        if ( false === $k_region ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_region).', 'museder-restoreone' ) );
        }

        $k_service = @hash_hmac( 'sha256', 's3', $k_region, true );
        if ( false === $k_service ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_service).', 'museder-restoreone' ) );
        }

        $k_signing = @hash_hmac( 'sha256', 'aws4_request', $k_service, true );
        if ( false === $k_signing ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_signing).', 'museder-restoreone' ) );
        }

        $signature = @hash_hmac( 'sha256', $string_to_sign, $k_signing );
        if ( false === $signature ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate final signature.', 'museder-restoreone' ) );
        }

        // Build authorization header
        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $algorithm,
            $access_key,
            $credential_scope,
            $signed_headers,
            $signature
        );

        // Prepare request headers
        $headers = array(
            'Host'               => $host,
            'Content-Type'       => 'application/xml',
            'Content-Length'     => (string) strlen( $xml_body ),
            'x-amz-date'         => $amz_date,
            'x-amz-content-sha256' => $content_sha256,
            'Authorization'      => $authorization,
        );

        // Send POST request
        $request_args = array(
            'method'      => 'POST',
            'timeout'     => 60,
            'headers'     => $headers,
            'body'        => $xml_body, // XML body as string
            'data_format' => 'body',
        );

        try {
            $response = @wp_remote_request( $url, $request_args );
        } catch ( Throwable $request_error ) {
            backup_lite_log( 'error', 'S3 complete_multipart_upload failed: exception during wp_remote_request.', [
                'url' => $url,
                'message' => $request_error->getMessage(),
            ] );
            return new WP_Error( 's3_request_exception', __( 'S3 complete multipart upload request failed with exception.', 'museder-restoreone' ) );
        }

        if ( false === $response || is_wp_error( $response ) ) {
            $error_msg = is_wp_error( $response ) ? $response->get_error_message() : __( 'Unknown error.', 'museder-restoreone' );
            backup_lite_log( 'error', 'S3 complete_multipart_upload failed.', [
                'error' => $error_msg,
            ] );
            return new WP_Error( 's3_request_failed', sprintf( __( 'S3 complete multipart upload failed: %s', 'museder-restoreone' ), $error_msg ) );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code < 200 || $status_code >= 300 ) {
            $body_text = wp_remote_retrieve_body( $response );
            backup_lite_log( 'error', 'S3 complete_multipart_upload failed (non-2xx response).', [
                'status_code'  => $status_code,
                'body_snippet' => substr( (string) $body_text, 0, 200 ),
            ] );
            return new WP_Error(
                's3_non_2xx',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'S3 complete multipart upload returned status code %d.', 'museder-restoreone' ),
                    (int) $status_code
                )
            );
        }

        backup_lite_log( 'info', 'S3 multipart upload completed.', [
            'bucket' => $bucket,
            'key'    => $key,
            'upload_id' => $upload_id,
            'parts_count' => count( $parts ),
        ] );

        return true;
    }

    /**
     * Abort a multipart upload on S3.
     *
     * @param string $bucket      Bucket name.
     * @param string $region      AWS region.
     * @param string $key         Object key.
     * @param string $endpoint    Custom endpoint (optional).
     * @param bool   $use_path    Use path-style endpoint.
     * @param string $upload_id   Upload ID from create_multipart_upload.
     * @param string $access_key  Access key ID.
     * @param string $secret_key  Secret access key.
     * @return true|WP_Error True on success, WP_Error on failure.
     */
    public static function abort_multipart_upload( $bucket, $region, $key, $endpoint, $use_path, $upload_id, $access_key, $secret_key ) {
        // Build URL with uploadId query parameter
        $base_url = self::build_s3_object_url( $bucket, $region, $endpoint, $use_path, $key );
        $url = add_query_arg( 'uploadId', $upload_id, $base_url );

        // Parse URL to get host and path
        $url_parts = parse_url( $url );
        if ( ! $url_parts || ! isset( $url_parts['host'] ) ) {
            return new WP_Error( 's3_invalid_url', __( 'Invalid S3 URL for abort multipart upload.', 'museder-restoreone' ) );
        }

        $host = $url_parts['host'];
        $path = isset( $url_parts['path'] ) ? $url_parts['path'] : '/';
        $query = isset( $url_parts['query'] ) ? $url_parts['query'] : '';

        // AWS Signature Version 4 for DELETE request
        $amz_date = gmdate( 'Ymd\THis\Z' );
        $date_stamp = gmdate( 'Ymd' );
        $content_sha256 = 'UNSIGNED-PAYLOAD';

        // Build canonical request
        $canonical_uri = $path;
        $canonical_querystring = $query;
        $canonical_headers = sprintf( "host:%s\nx-amz-content-sha256:%s\nx-amz-date:%s\n", $host, $content_sha256, $amz_date );
        $signed_headers = 'host;x-amz-content-sha256;x-amz-date';
        $payload_hash = $content_sha256;

        $canonical_request = sprintf(
            "DELETE\n%s\n%s\n%s\n%s\n%s",
            $canonical_uri,
            $canonical_querystring,
            $canonical_headers,
            $signed_headers,
            $payload_hash
        );

        // Build string to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = sprintf( '%s/%s/s3/aws4_request', $date_stamp, $region );
        $canonical_request_hash = hash( 'sha256', $canonical_request );
        if ( false === $canonical_request_hash ) {
            return new WP_Error( 's3_hash_error', __( 'Failed to calculate request hash.', 'museder-restoreone' ) );
        }

        $string_to_sign = sprintf(
            "%s\n%s\n%s\n%s",
            $algorithm,
            $amz_date,
            $credential_scope,
            $canonical_request_hash
        );

        // Calculate signature
        $k_date = @hash_hmac( 'sha256', $date_stamp, 'AWS4' . $secret_key, true );
        if ( false === $k_date ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_date).', 'museder-restoreone' ) );
        }

        $k_region = @hash_hmac( 'sha256', $region, $k_date, true );
        if ( false === $k_region ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_region).', 'museder-restoreone' ) );
        }

        $k_service = @hash_hmac( 'sha256', 's3', $k_region, true );
        if ( false === $k_service ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_service).', 'museder-restoreone' ) );
        }

        $k_signing = @hash_hmac( 'sha256', 'aws4_request', $k_service, true );
        if ( false === $k_signing ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate signature key (k_signing).', 'museder-restoreone' ) );
        }

        $signature = @hash_hmac( 'sha256', $string_to_sign, $k_signing );
        if ( false === $signature ) {
            return new WP_Error( 's3_signature_error', __( 'Failed to calculate final signature.', 'museder-restoreone' ) );
        }

        // Build authorization header
        $authorization = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $algorithm,
            $access_key,
            $credential_scope,
            $signed_headers,
            $signature
        );

        // Prepare request headers
        $headers = array(
            'Host'               => $host,
            'x-amz-date'         => $amz_date,
            'x-amz-content-sha256' => $content_sha256,
            'Authorization'      => $authorization,
        );

        // Send DELETE request
        $request_args = array(
            'method'      => 'DELETE',
            'timeout'     => 30,
            'headers'     => $headers,
            'body'        => '', // Empty body for DELETE
            'data_format' => 'body',
        );

        try {
            $response = @wp_remote_request( $url, $request_args );
        } catch ( Throwable $request_error ) {
            backup_lite_log( 'error', 'S3 abort_multipart_upload failed: exception during wp_remote_request.', [
                'url' => $url,
                'message' => $request_error->getMessage(),
            ] );
            return new WP_Error( 's3_request_exception', __( 'S3 abort multipart upload request failed with exception.', 'museder-restoreone' ) );
        }

        if ( false === $response || is_wp_error( $response ) ) {
            $error_msg = is_wp_error( $response ) ? $response->get_error_message() : __( 'Unknown error.', 'museder-restoreone' );
            backup_lite_log( 'error', 'S3 abort_multipart_upload failed.', [
                'error' => $error_msg,
            ] );
            return new WP_Error( 's3_request_failed', sprintf( __( 'S3 abort multipart upload failed: %s', 'museder-restoreone' ), $error_msg ) );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        // 204 No Content is also acceptable for DELETE
        if ( $status_code !== 204 && ( $status_code < 200 || $status_code >= 300 ) ) {
            $body_text = wp_remote_retrieve_body( $response );
            backup_lite_log( 'error', 'S3 abort_multipart_upload failed (non-2xx response).', [
                'status_code'  => $status_code,
                'body_snippet' => substr( (string) $body_text, 0, 200 ),
            ] );
            return new WP_Error(
                's3_non_2xx',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'S3 abort multipart upload returned status code %d.', 'museder-restoreone' ),
                    (int) $status_code
                )
            );
        }

        backup_lite_log( 'info', 'S3 multipart upload aborted.', [
            'bucket' => $bucket,
            'key'    => $key,
            'upload_id' => $upload_id,
        ] );

        return true;
    }
}

